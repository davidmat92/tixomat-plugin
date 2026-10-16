<?php
/**
 * Tixomat – Datenspiegel (Supabase)
 *
 * WordPress bleibt führend. Jede Änderung an Events, Kategorien, Kunden, Bestellungen,
 * Tickets, Check-ins und Stornos landet als Eintrag (Typ + ID) im Postausgang
 * {prefix}tix_mirror_outbox. Ein Hintergrund-Job schickt den jeweils AKTUELLEN Stand
 * gebündelt als Upsert über (site, id) an Supabase. Gibt es ein Objekt nicht mehr,
 * wird es im Spiegel gelöscht.
 *
 * Harte Regel: Nichts hier darf eine Bestellung, Zahlung oder einen Check-in aufhalten.
 * - Erfassen kostet nur einen Eintrag im Speicher; geschrieben wird einmal pro Request
 *   am Ende (shutdown) mit einem einzigen INSERT … ON DUPLICATE KEY UPDATE.
 * - Jeder Fehler wird geschluckt (kein Echo, kein Abbruch), Netzwerk nur im Cron.
 * - Kein Schreiben aus Supabase zurück nach WordPress.
 *
 * Zugang: TIX_MIRROR_URL / TIX_MIRROR_KEY in wp-config (bevorzugt) oder Option tix_mirror.
 * Schema: supabase/migrations/*.sql im Plugin-Repo.
 *
 * @since Datenspiegel Paket 1 (Version vergibt die Zentrale)
 */

if (!defined('ABSPATH')) exit;

class TIX_Mirror {

    const OPTION       = 'tix_mirror';          // Einstellungen: enabled, url, key, site, alert_email
    const STATE        = 'tix_mirror_state';    // Laufzeit: letzter Lauf, Fehler, Zähler
    const DB_OPTION    = 'tix_mirror_db';
    const DB_VERSION   = '1';
    const LOCK         = 'tix_mirror_lock';
    const CRON         = 'tix_mirror_flush';
    const SCHEDULE     = 'tix_every_minute';
    const BATCH        = 200;                   // Einträge je Runde
    const RUN_SECONDS  = 25;                    // Zeitbudget je Cron-Lauf
    const ALERT_TRIES  = 8;                     // Fehlversuche je Eintrag bis zur Alarm-Mail
    const ALERT_EVERY  = 6 * HOUR_IN_SECONDS;   // höchstens eine Alarm-Mail je 6 Stunden
    const BUFFER_MAX   = 500;                   // Puffer vorzeitig schreiben (Massenänderungen)

    /** Puffer dieses Requests: "entity|id" => [entity, id, op, payload]. */
    private static $buffer = [];
    private static $shutdown_hooked = false;
    private static $shutdown_flushed = false;
    /** Während der Spiegel selbst liest/schreibt, keine neuen Einträge erzeugen. */
    private static $muted = 0;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 5);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::CRON, [__CLASS__, 'cron_run']);
        add_action('init', [__CLASS__, 'maybe_schedule'], 20);
        if (class_exists('TIX_Mirror_Hooks')) TIX_Mirror_Hooks::init();
        if (class_exists('TIX_Mirror_Backfill')) TIX_Mirror_Backfill::init();
        if (is_admin() && class_exists('TIX_Mirror_Admin')) TIX_Mirror_Admin::init();
    }

    // ═══════════════ Einstellungen ═══════════════

    public static function defaults() {
        // Alle Seiten standardmäßig an (Entscheidung des Users 2026-10-16); aktiv erst mit URL + Schlüssel.
        // log_days: Aufbewahrung des Änderungsprotokolls im Spiegel (Entscheidung des Users: 10 Jahre).
        return ['enabled' => 1, 'url' => '', 'key' => '', 'site' => '', 'alert_email' => '', 'log_days' => 3653];
    }

    /** Aufbewahrung des Verlaufs in Tagen (mindestens 30). */
    public static function log_days() {
        return max(30, (int) self::settings()['log_days']);
    }

    public static function settings() {
        $s = get_option(self::OPTION, []);
        return wp_parse_args(is_array($s) ? $s : [], self::defaults());
    }

    public static function save_settings(array $new) {
        $s = array_merge(self::settings(), $new);
        update_option(self::OPTION, $s, false);
    }

    /** Spiegel schaltbar an und Zugang vorhanden. */
    public static function active() {
        if (defined('TIX_MIRROR_DISABLED') && TIX_MIRROR_DISABLED) return false;
        return !empty(self::settings()['enabled']) && TIX_Mirror_Client::configured();
    }

    /**
     * Erfassen nur mit Schalter an und Zugang. Seiten ohne Spiegel schreiben nichts in den
     * Postausgang; was vor der Einrichtung geschah, holt die Erstbefüllung.
     */
    public static function capturing() {
        if (self::$muted > 0) return false;
        return self::active();
    }

    /** Kennung der Seite im Spiegel, z. B. "kitchenklub.de". */
    public static function site() {
        $s = (string) (self::settings()['site'] ?? '');
        if ($s !== '') return $s;
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $host = strtolower(preg_replace('/^www\./i', '', $host));
        $path = trim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        return $path !== '' ? $host . '/' . $path : $host;
    }

    public static function mute()   { self::$muted++; }
    public static function unmute() { self::$muted = max(0, self::$muted - 1); }

    /** Erfassen bewusst zulassen (Nachlauf, Erstbefüllung) und den vorherigen Zustand zurückgeben. */
    public static function unmuted_begin() { $prev = self::$muted; self::$muted = 0; return $prev; }
    public static function unmuted_end($prev) { self::$muted = (int) $prev; }

    // ═══════════════ Laufzeit-Status ═══════════════

    public static function state() {
        $s = get_option(self::STATE, []);
        return wp_parse_args(is_array($s) ? $s : [], [
            'last_run' => 0, 'last_ok' => 0, 'last_error' => '', 'last_error_at' => 0,
            'fail_runs' => 0, 'sent' => 0, 'alert_at' => 0,
            'sweep_orders' => '', 'sweeps' => [], 'site_at' => 0,
        ]);
    }

    public static function set_state(array $patch) {
        update_option(self::STATE, array_merge(self::state(), $patch), false);
    }

    // ═══════════════ Tabelle ═══════════════

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_mirror_outbox';
    }

    public static function maybe_install() {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) return;
        self::install();
    }

    public static function install() {
        global $wpdb;
        $t = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity VARCHAR(32) NOT NULL,
            object_id VARCHAR(80) NOT NULL,
            op VARCHAR(10) NOT NULL DEFAULT 'upsert',
            payload LONGTEXT NULL,
            rev INT UNSIGNED NOT NULL DEFAULT 1,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            next_try DATETIME NOT NULL,
            last_error VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entity_object (entity,object_id),
            KEY next_try (next_try)
        ) {$charset};");
        update_option(self::DB_OPTION, self::DB_VERSION, true);
        self::migrate_legacy();
    }

    /**
     * Alte Ticket-Anbindung (TIX_Sync_Supabase) ablösen: Sie zeigte auf das ALTE Projekt,
     * das unberührt bleibt. Deshalb werden URL/Schlüssel NICHT übernommen, nur vermerkt.
     */
    private static function migrate_legacy() {
        if (!class_exists('TIX_Settings')) return;
        $old = get_option(TIX_Settings::OPTION_KEY, []);
        if (!is_array($old) || empty($old['supabase_url'])) return;
        $s = self::settings();
        if (empty($s['legacy_url'])) {
            $s['legacy_url'] = (string) $old['supabase_url'];
            $s['legacy_enabled'] = !empty($old['supabase_enabled']) ? 1 : 0;
            update_option(self::OPTION, $s, false);
        }
    }

    // ═══════════════ Erfassen ═══════════════

    /**
     * Änderung vormerken. Nie ein Fehler nach außen.
     *
     * @param string      $entity  event|category|customer|order|ticket|checkin|refund|site
     * @param int|string  $id      ID in WordPress (bei checkin/refund eindeutiger Schlüssel)
     * @param string      $op      upsert | delete | forget
     * @param array|null  $payload nur für Ereignisse ohne eigene Zeile in WP (Check-in-Verlauf, Löschweg)
     */
    public static function touch($entity, $id, $op = 'upsert', $payload = null) {
        try {
            if (!self::capturing()) return;
            $id = (string) $id;
            if ($id === '' || $id === '0') return;
            $k = $entity . '|' . $id;
            // Der Löschweg (forget) bleibt stehen, auch wenn danach noch Meta-Änderungen am selben Konto kommen.
            if (isset(self::$buffer[$k]) && self::$buffer[$k][2] === 'forget') return;
            self::$buffer[$k] = [$entity, $id, $op, $payload];
            if (!self::$shutdown_hooked) {
                self::$shutdown_hooked = true;
                add_action('shutdown', [__CLASS__, 'flush_buffer'], 1);
            }
            // Nach dem Schreiben am Request-Ende (spätere shutdown-Callbacks) sofort schreiben.
            if (self::$shutdown_flushed || count(self::$buffer) >= self::BUFFER_MAX) self::flush_buffer();
        } catch (\Throwable $e) {
            // Spiegeln darf nie stören.
        }
    }

    /** Puffer in die Tabelle schreiben (ein Statement). */
    public static function flush_buffer() {
        if (doing_action('shutdown')) self::$shutdown_flushed = true;
        if (!self::$buffer) return;
        $items = self::$buffer;
        self::$buffer = [];
        try {
            global $wpdb;
            if (get_option(self::DB_OPTION) !== self::DB_VERSION) self::install();
            $now = gmdate('Y-m-d H:i:s');
            $values = [];
            foreach ($items as $it) {
                $payload = $it[3] === null ? null : wp_json_encode($it[3]);
                $values[] = $wpdb->prepare('(%s,%s,%s,%s,1,0,%s,%s,%s,%s)', $it[0], $it[1], $it[2], (string) $payload, $now, '', $now, $now);
            }
            $sql = 'INSERT INTO ' . self::table() . ' (entity,object_id,op,payload,rev,attempts,next_try,last_error,created_at,updated_at) VALUES '
                . implode(',', $values)
                . ' ON DUPLICATE KEY UPDATE payload=IF(op=\'forget\' OR VALUES(payload)=\'\', payload, VALUES(payload)), op=IF(op=\'forget\', op, VALUES(op)), rev=rev+1, attempts=0, next_try=VALUES(next_try), last_error=\'\', updated_at=VALUES(updated_at)';
            $prev = $wpdb->suppress_errors(true);
            $wpdb->query($sql);
            $wpdb->suppress_errors($prev);
        } catch (\Throwable $e) {
            // schlucken
        }
    }

    // ═══════════════ Cron ═══════════════

    public static function cron_schedules($s) {
        if (!isset($s[self::SCHEDULE])) $s[self::SCHEDULE] = ['interval' => MINUTE_IN_SECONDS, 'display' => 'Jede Minute (Tixomat)'];
        return $s;
    }

    public static function maybe_schedule() {
        $want = !empty(self::settings()['enabled']) && TIX_Mirror_Client::configured();
        $next = wp_next_scheduled(self::CRON);
        if ($want && !$next) wp_schedule_event(time() + 30, self::SCHEDULE, self::CRON);
        if (!$want && $next) wp_clear_scheduled_hook(self::CRON);
    }

    public static function cron_run() {
        if (!self::active()) return;
        if (!self::lock()) return;
        self::mute();
        try {
            @set_time_limit(120);
            $started = microtime(true);
            self::sweep();
            if (time() - (int) self::state()['site_at'] > 10 * MINUTE_IN_SECONDS) {
                // Seite regelmäßig melden (Version, zuletzt gesehen); last_seen erzeugt keinen Verlaufseintrag.
                $m = self::unmuted_begin(); self::touch('site', 'site'); self::flush_buffer(); self::unmuted_end($m);
                self::set_state(['site_at' => time()]);
            }
            if (class_exists('TIX_Mirror_Backfill')) TIX_Mirror_Backfill::step(max(5, self::RUN_SECONDS / 2));
            if (class_exists('TIX_Mirror_Reconcile')) TIX_Mirror_Reconcile::step(10);
            do {
                $r = self::process_batch();
            } while ($r['rows'] >= self::BATCH && $r['failed'] === 0 && (microtime(true) - $started) < self::RUN_SECONDS);
            self::maybe_alert();
        } catch (\Throwable $e) {
            self::set_state(['last_error' => 'Ausnahme: ' . substr($e->getMessage(), 0, 300), 'last_error_at' => time()]);
        }
        self::unmute();
        self::unlock();
    }

    /** Sperre gegen parallele Läufe (atomar über die Options-Tabelle, verfällt nach 5 Minuten). */
    private static function lock() {
        global $wpdb;
        $until = time() + 5 * MINUTE_IN_SECONDS;
        if (add_option(self::LOCK, $until, '', 'no')) return true;
        $n = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
            (string) $until, self::LOCK, time()
        ));
        wp_cache_delete(self::LOCK, 'options');
        return $n === 1;
    }

    private static function unlock() {
        delete_option(self::LOCK);
    }

    /**
     * Sicherheitsnetz für Tabellen, die per $wpdb ohne Hook geschrieben werden:
     *  - tix_orders (Status, E-Mail, Zahlart, Anonymisierung): date_modified zieht MySQL selbst nach
     *  - Guthaben und Abrechnungen (Paket 2, TIX_Mirror_Extra::sweeps()): updated bzw. neue IDs
     * Beim ersten Lauf wird nur die Marke gesetzt; den Bestand holt die Erstbefüllung.
     */
    public static function sweep() {
        global $wpdb;
        $list = [['order', $wpdb->prefix . 'tix_orders', 'date_modified', 'time_db']];
        if (class_exists('TIX_Mirror_Extra')) $list = array_merge($list, TIX_Mirror_Extra::sweeps());
        $marks = self::state()['sweeps'];
        $marks = is_array($marks) ? $marks : [];
        // Alte Marke aus Paket 1 übernehmen
        if (!isset($marks['order']) && self::state()['sweep_orders'] !== '') $marks['order'] = self::state()['sweep_orders'];
        $limit = 5000;
        $m = self::unmuted_begin(); // Treffer sollen in den Postausgang
        $prev = $wpdb->suppress_errors(true);
        try {
            foreach ($list as $sw) {
                [$entity, $table, $col, $mode] = $sw;
                // Paket-2-Tabellen ab Anfang lesen: Sie können nach der Erstbefüllung erst entstehen
                // (z. B. Abrechnungen beim Einschalten des Mehr-Veranstalter-Modus).
                $from_start = !empty($sw[4]);
                if ($mode === 'id') {
                    $max = $wpdb->get_var("SELECT MAX(id) FROM {$table}");
                    if ($max === null && $wpdb->last_error !== '') continue; // Tabelle gibt es auf dieser Seite nicht
                    if (!isset($marks[$entity])) { $marks[$entity] = $from_start ? 0 : (int) $max; if (!$from_start) continue; }
                    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE id > %d ORDER BY id LIMIT %d", (int) $marks[$entity], $limit));
                    foreach ((array) $ids as $id) self::touch($entity, (int) $id);
                    if ($ids) $marks[$entity] = (int) end($ids);
                    continue;
                }
                $now = $mode === 'time_utc' ? gmdate('Y-m-d H:i:s') : ($mode === 'time_local' ? current_time('mysql') : $wpdb->get_var('SELECT NOW()'));
                if (!$now) continue;
                if (!isset($marks[$entity])) {
                    $wpdb->get_var("SELECT 1 FROM {$table} LIMIT 1");
                    if ($wpdb->last_error !== '') continue;
                    $marks[$entity] = $from_start ? '1970-01-01 00:00:00' : $now;
                    if (!$from_start) continue;
                }
                $since = (string) $marks[$entity];
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, {$col} AS t FROM {$table} WHERE {$col} >= %s - INTERVAL 5 SECOND ORDER BY {$col}, id LIMIT %d", $since, $limit
                ), ARRAY_A);
                if ($rows === null || $wpdb->last_error !== '') continue;
                foreach ((array) $rows as $r) self::touch($entity, (int) $r['id']);
                // Volles Paket: nur bis zur letzten gesehenen Zeit vorrücken, der Rest kommt im nächsten Lauf.
                $mark = count($rows) >= $limit ? (string) end($rows)['t'] : $now;
                if ($mark === $since && count($rows) >= $limit) $mark = $now; // > 5000 Änderungen in derselben Sekunde
                $marks[$entity] = $mark;
            }
            self::flush_buffer();
        } finally {
            $wpdb->suppress_errors($prev);
            self::unmuted_end($m);
        }
        self::set_state(['sweeps' => $marks]);
    }

    /**
     * Eine Runde: fällige Einträge laden, aktuellen Stand bauen, gebündelt senden.
     * @return array{rows:int,sent:int,failed:int}
     */
    public static function process_batch($limit = self::BATCH) {
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, entity, object_id, op, payload, rev, attempts FROM {$t} WHERE next_try <= %s ORDER BY id ASC LIMIT %d",
            gmdate('Y-m-d H:i:s'), $limit
        ), ARRAY_A);
        if (!$rows) {
            self::set_state(['last_run' => time()]);
            return ['rows' => 0, 'sent' => 0, 'failed' => 0];
        }

        // Für jeden Eintrag die Operationen bauen; Fehler beim Bauen betreffen nur diesen Eintrag.
        $plans = [];
        foreach ($rows as $row) {
            try {
                $plans[$row['id']] = TIX_Mirror_Map::plan($row['entity'], $row['object_id'], $row['op'], $row['payload'] ? json_decode($row['payload'], true) : null);
            } catch (\Throwable $e) {
                $plans[$row['id']] = new WP_Error('map', 'Aufbau: ' . $e->getMessage());
            }
        }

        $failed_rows = self::send_plans($rows, $plans);

        $sent = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($failed_rows[$id])) {
                $tries = (int) $row['attempts'] + 1;
                $wait  = min(HOUR_IN_SECONDS, MINUTE_IN_SECONDS * (2 ** min(6, $tries - 1)));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$t} SET attempts = %d, next_try = %s, last_error = %s WHERE id = %d AND rev = %d",
                    $tries, gmdate('Y-m-d H:i:s', time() + $wait), substr($failed_rows[$id], 0, 490), $id, (int) $row['rev']
                ));
            } else {
                // Nur löschen, wenn seitdem keine neue Änderung kam (rev unverändert).
                $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE id = %d AND rev = %d", $id, (int) $row['rev']));
                $sent++;
                // Gesendeten Stand merken (z. B. Prüfsummen der Gutscheine).
                if (is_array($plans[$id] ?? null)) {
                    foreach ($plans[$id] as $op) if ($op[0] === 'remember') update_option($op[1], $op[2], false);
                }
            }
        }

        $st = self::state();
        $patch = ['last_run' => time(), 'sent' => (int) $st['sent'] + $sent];
        if ($failed_rows) {
            $patch['fail_runs'] = (int) $st['fail_runs'] + 1;
            $patch['last_error'] = reset($failed_rows);
            $patch['last_error_at'] = time();
        } else {
            $patch['fail_runs'] = 0;
            $patch['last_ok'] = time();
        }
        self::set_state($patch);
        return ['rows' => count($rows), 'sent' => $sent, 'failed' => count($failed_rows)];
    }

    /**
     * Pläne zusammenführen und senden. Upserts werden je Tabelle gebündelt; schlägt ein
     * Bündel mit einem Datenfehler fehl, wird es einzeln wiederholt, damit ein kaputter
     * Datensatz die anderen nicht blockiert.
     *
     * @return array<int,string> fehlgeschlagene Outbox-IDs => Fehlertext
     */
    private static function send_plans(array $rows, array $plans) {
        $failed = [];
        $upserts = []; // table => [ [row_id, row, conflict], … ]
        $others  = []; // [row_id, op]
        $prunes  = []; // table => [ [row_id, parent, keep], … ]
        foreach ($plans as $rid => $plan) {
            if (is_wp_error($plan)) { $failed[$rid] = $plan->get_error_message(); continue; }
            foreach ($plan as $op) {
                if ($op[0] === 'upsert') {
                    foreach ($op[2] as $r) $upserts[$op[1]][] = [$rid, $r, $op[3] ?? 'site,id'];
                } elseif ($op[0] === 'prune') {
                    $prunes[$op[1]][] = [$rid, (int) $op[2], array_values($op[3])];
                } elseif ($op[0] === 'remember') {
                    continue; // erst nach Erfolg, siehe process_batch()
                } else {
                    $others[] = [$rid, $op];
                }
            }
        }

        // Feste Reihenfolge, damit Kinder nach ihren Eltern ankommen (es gibt keine Fremdschlüssel, nur Lesbarkeit).
        $order = ['sites', 'organizers', 'events', 'ticket_categories', 'guests', 'customers', 'loyalty', 'orders', 'order_items', 'payments',
                  'tickets', 'checkins', 'refunds', 'coupons', 'wallets', 'wallet_transactions', 'settlements', 'settlement_items'];
        uksort($upserts, function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true); $ib = array_search($b, $order, true);
            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        });

        foreach ($upserts as $table => $list) {
            $by_conflict = [];
            foreach ($list as $e) $by_conflict[$e[2]][] = $e;
            foreach ($by_conflict as $conflict => $entries) {
                // Gleiche Zeile mehrfach im Bündel → nur die letzte (Postgres lehnt Doppelte in einem Upsert ab).
                $uniq = [];
                foreach ($entries as $e) {
                    $k = '';
                    foreach (explode(',', $conflict) as $c) $k .= '|' . ($e[1][$c] ?? '');
                    $uniq[$k] = $e;
                }
                $entries = array_values($uniq);
                foreach (array_chunk($entries, 500) as $chunk) {
                    $res = TIX_Mirror_Client::upsert($table, array_column($chunk, 1), $conflict);
                    if ($res['ok']) continue;
                    $retry_single = $res['code'] >= 400 && $res['code'] < 500 && !in_array($res['code'], [401, 403, 404], true) && count($chunk) > 1;
                    if (!$retry_single) {
                        foreach ($chunk as $e) $failed[$e[0]] = $table . ': ' . $res['error'];
                        continue;
                    }
                    foreach ($chunk as $e) {
                        $one = TIX_Mirror_Client::upsert($table, [$e[1]], $conflict);
                        if (!$one['ok']) $failed[$e[0]] = $table . ': ' . $one['error'];
                    }
                }
            }
        }

        // Aufräumen je Tabelle in einem Aufruf (nach den Upserts, damit nichts Neues gelöscht wird).
        foreach ($prunes as $table => $list) {
            $list = array_values(array_filter($list, function ($e) use ($failed) { return !isset($failed[$e[0]]); }));
            if (!$list) continue;
            $sets = array_map(function ($e) { return ['parent' => $e[1], 'keep' => $e[2]]; }, $list);
            $res = TIX_Mirror_Client::rpc('mirror_prune', ['p_site' => self::site(), 'p_table' => $table, 'p_sets' => $sets]);
            if (!$res['ok']) foreach ($list as $e) $failed[$e[0]] = $table . ' (aufräumen): ' . $res['error'];
        }

        foreach ($others as [$rid, $op]) {
            if (isset($failed[$rid])) continue;
            if ($op[0] === 'delete') {
                $res = TIX_Mirror_Client::delete($op[1], $op[2]);
            } elseif ($op[0] === 'rpc') {
                $res = TIX_Mirror_Client::rpc($op[1], $op[2]);
            } else {
                continue;
            }
            if (!$res['ok']) $failed[$rid] = $op[1] . ': ' . $res['error'];
        }
        return $failed;
    }

    // ═══════════════ Alarm ═══════════════

    private static function maybe_alert() {
        global $wpdb;
        $st = self::state();
        if (time() - (int) $st['alert_at'] < self::ALERT_EVERY) return;
        $stuck = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE attempts >= %d", self::ALERT_TRIES));
        if ($stuck === 0) return;
        $to = trim((string) self::settings()['alert_email']) ?: get_option('admin_email');
        if (!is_email($to)) return;
        $pending = self::pending_count();
        $body = "Der Datenspiegel (Supabase) von " . self::site() . " kommt nicht nach.\n\n"
            . "Einträge mit mindestens " . self::ALERT_TRIES . " Fehlversuchen: {$stuck}\n"
            . "Rückstand gesamt: {$pending}\n"
            . "Letzter Fehler: " . ($st['last_error'] ?: '–') . "\n"
            . "Letzter erfolgreicher Abgleich: " . ($st['last_ok'] ? wp_date('d.m.Y H:i', (int) $st['last_ok']) : 'noch nie') . "\n\n"
            . "Bestellungen und Check-ins laufen normal weiter; der Rückstand wird nachgeschickt, sobald Supabase wieder antwortet.\n"
            . "Status: " . admin_url('admin.php?page=tix-mirror') . "\n";
        self::set_state(['alert_at' => time()]);
        wp_mail($to, '[Tixomat] Datenspiegel: Rückstand auf ' . self::site(), $body);
    }

    // ═══════════════ Kennzahlen ═══════════════

    public static function pending_count() {
        global $wpdb;
        $prev = $wpdb->suppress_errors(true);
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
        $wpdb->suppress_errors($prev);
        return $n;
    }

    public static function stats() {
        global $wpdb;
        $t = self::table();
        $prev = $wpdb->suppress_errors(true);
        $by = $wpdb->get_results("SELECT entity, COUNT(*) n, SUM(attempts > 0) failing, MIN(created_at) oldest FROM {$t} GROUP BY entity", ARRAY_A);
        $errors = $wpdb->get_results("SELECT entity, object_id, attempts, last_error, updated_at FROM {$t} WHERE attempts > 0 ORDER BY attempts DESC, id ASC LIMIT 20", ARRAY_A);
        $wpdb->suppress_errors($prev);
        return ['by_entity' => (array) $by, 'errors' => (array) $errors];
    }
}

foreach (['client', 'map', 'hooks', 'backfill', 'reconcile', 'extra'] as $tix_mirror_part) {
    require_once __DIR__ . '/class-tix-mirror-' . $tix_mirror_part . '.php';
}
if (is_admin()) require_once __DIR__ . '/class-tix-mirror-admin.php';
unset($tix_mirror_part);
