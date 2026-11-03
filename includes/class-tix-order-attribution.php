<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Order_Attribution – Herkunft einer Bestellung in eigener Tabelle (Statistik Stufe 1, docs/plan-statistik-marketing.md §4.2).
 *
 * Bis 1.38.615 lagen Kanal, Kampagne (UTM), Referrer, Promoter und Meta-Einwilligung als Postmeta an der Bestell-ID,
 * obwohl Bestellungen in {prefix}tix_orders liegen und keine Posts sind. Fiel eine Bestell-ID mit einer Post-ID zusammen,
 * mischten sich die Daten. Jetzt: {prefix}tix_order_attribution (eine Zeile je Bestellung).
 *
 *  - meta($order_id, $key) / set_meta($order_id, $key, $value) sprechen die alten Postmeta-Schlüssel (META_MAP) – so bleiben
 *    die Aufrufer lesbar. Ohne Zeile wird noch die Postmeta gelesen (Altbestand vor dem Umzug, WooCommerce-Bestellungen).
 *  - Umzug: maybe_migrate() verschiebt den Bestand in Blöcken (nur IDs aus tix_orders, nie Postmeta echter WooCommerce-
 *    Bestellungen) und löscht danach die Postmeta. Fertig = Option tix_attr_migrated = DB_VERSION.
 *  - link_code: Kampagnen-Code aus der Adresse (`c=`, ohne Gerätespeicher), click_ids nur mit Einwilligung.
 *  - Datenspiegel: jede Änderung stößt die Bestellung im Spiegel an (orders.data.attribution, ohne IP/Kennungen).
 */
class TIX_Order_Attribution {

    const DB_VERSION = '3'; // 2 = utm_id (Stufe 2), 3 = consent_analytics + analytics_ids (Stufe 3a: GA4, Matomo)
    const BATCH = 500;

    /** alter Postmeta-Schlüssel → Spalte */
    const META_MAP = [
        '_tix_campaign_source'   => 'source',
        '_tix_campaign_medium'   => 'medium',
        '_tix_campaign_name'     => 'campaign',
        '_tix_campaign_content'  => 'content',
        '_tix_campaign_referrer' => 'referrer',
        '_tix_order_channel'     => 'channel',
        '_tix_meta_fbp'          => 'fbp',
        '_tix_meta_fbc'          => 'fbc',
        '_tix_meta_client_ip'    => 'client_ip',
        '_tix_meta_client_ua'    => 'client_ua',
        '_tix_promoter_id'       => 'promoter_id',
        '_tix_promoter_attribution' => 'promoter_attribution',
        '_tix_promoter_commissions_calculated' => 'promoter_done',
        // Einwilligung: eine Spalte consent_marketing (1 = ja, 0 = nein, NULL = unbekannt)
        '_tix_meta_consent'      => 'consent_marketing',
        '_tix_meta_no_consent'   => 'consent_marketing',
    ];

    /** Spalten mit Länge (Text) */
    const TEXT = ['channel' => 20, 'source' => 64, 'medium' => 100, 'campaign' => 100, 'content' => 100, 'referrer' => 190,
        'link_code' => 32, 'utm_id' => 64, 'fbp' => 200, 'fbc' => 500, 'client_ip' => 64, 'client_ua' => 400, 'promoter_attribution' => 20];

    private static $cache = [];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_create'], 5);
        add_action('init', [__CLASS__, 'maybe_migrate'], 40);
        add_action('tix_attr_migrate', [__CLASS__, 'maybe_migrate']);
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'tix_order_attribution'; }

    public static function maybe_create() {
        if (get_option('tix_order_attribution_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta("CREATE TABLE $t (
            order_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT '',
            source VARCHAR(64) NOT NULL DEFAULT '',
            medium VARCHAR(100) NOT NULL DEFAULT '',
            campaign VARCHAR(100) NOT NULL DEFAULT '',
            content VARCHAR(100) NOT NULL DEFAULT '',
            referrer VARCHAR(190) NOT NULL DEFAULT '',
            link_code VARCHAR(32) NOT NULL DEFAULT '',
            utm_id VARCHAR(64) NOT NULL DEFAULT '',
            promoter_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            promoter_attribution VARCHAR(20) NOT NULL DEFAULT '',
            promoter_done TINYINT(1) NOT NULL DEFAULT 0,
            consent_marketing TINYINT(1) NULL DEFAULT NULL,
            consent_analytics TINYINT(1) NULL DEFAULT NULL,
            analytics_ids TEXT NULL,
            fbp VARCHAR(200) NOT NULL DEFAULT '',
            fbc VARCHAR(500) NOT NULL DEFAULT '',
            client_ip VARCHAR(64) NOT NULL DEFAULT '',
            client_ua VARCHAR(400) NOT NULL DEFAULT '',
            click_ids TEXT NULL,
            updated DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (order_id),
            KEY source (source),
            KEY link_code (link_code),
            KEY utm_id (utm_id)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_order_attribution_db', self::DB_VERSION, true);
    }

    /** Ganze Zeile als Array (leere Werte, wenn keine Zeile) */
    public static function get($order_id) {
        $id = intval($order_id);
        if (!$id) return null;
        if (array_key_exists($id, self::$cache)) return self::$cache[$id];
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE order_id = %d", $id), ARRAY_A);
        return self::$cache[$id] = $r ?: null;
    }

    /** Wert wie get_post_meta($order_id, $key, true) – aus der Tabelle, ohne Zeile noch aus der Postmeta (Altbestand/WooCommerce) */
    public static function meta($order_id, $key) {
        $col = self::META_MAP[$key] ?? null;
        if (!$col) return get_post_meta($order_id, $key, true);
        $r = self::get($order_id);
        if (!$r) return get_post_meta($order_id, $key, true);
        if ($col === 'consent_marketing') {
            if ($r['consent_marketing'] === null) return '';
            if ($key === '_tix_meta_consent') return $r['consent_marketing'] === '1' || $r['consent_marketing'] === 1 ? '1' : '';
            return $r['consent_marketing'] === '0' || $r['consent_marketing'] === 0 ? '1' : '';
        }
        if (in_array($col, ['promoter_id'], true)) return intval($r[$col]) ?: '';
        if ($col === 'promoter_done') return intval($r[$col]) ? '1' : '';
        return (string) $r[$col];
    }

    /** Wie update_post_meta bzw. (null) delete_post_meta – schreibt in die Tabelle */
    public static function set_meta($order_id, $key, $value) {
        $col = self::META_MAP[$key] ?? null;
        if (!$col) return false;
        if ($col === 'consent_marketing') {
            if ($value === null || $value === '' || $value === false) {
                // löschen: nur löschen, wenn der Schlüssel zum gespeicherten Stand passt
                $cur = self::get($order_id);
                if (!$cur || $cur['consent_marketing'] === null) return true;
                $is_yes = (string) $cur['consent_marketing'] === '1';
                if (($key === '_tix_meta_consent') !== $is_yes) return true;
                return self::set($order_id, ['consent_marketing' => null]);
            }
            return self::set($order_id, ['consent_marketing' => $key === '_tix_meta_consent' ? 1 : 0]);
        }
        if ($value === null || $value === false) $value = in_array($col, ['promoter_id', 'promoter_done'], true) ? 0 : '';
        return self::set($order_id, [$col => $value]);
    }

    /** Felder setzen (Upsert). $fields: Spalte => Wert; click_ids als Array. */
    public static function set($order_id, array $fields) {
        global $wpdb;
        $id = intval($order_id);
        if (!$id) return false;
        $row = [];
        $fmt = [];
        foreach ($fields as $k => $v) {
            if (isset(self::TEXT[$k])) { $row[$k] = mb_substr((string) $v, 0, self::TEXT[$k]); $fmt[] = '%s'; }
            elseif ($k === 'promoter_id') { $row[$k] = max(0, intval($v)); $fmt[] = '%d'; }
            elseif ($k === 'promoter_done') { $row[$k] = $v ? 1 : 0; $fmt[] = '%d'; }
            elseif ($k === 'consent_marketing') { $row[$k] = $v === null ? null : ($v ? 1 : 0); $fmt[] = $v === null ? null : '%d'; }
            elseif ($k === 'click_ids' || $k === 'analytics_ids') { $row[$k] = is_array($v) && $v ? wp_json_encode($v) : null; $fmt[] = '%s'; }
            elseif ($k === 'consent_analytics') { $row[$k] = $v === null ? null : ($v ? 1 : 0); $fmt[] = $v === null ? null : '%d'; }
        }
        if (!$row) return false;
        $row['updated'] = current_time('mysql', true); $fmt[] = '%s';
        $t = self::table();
        $cols = array_keys($row);
        $vals = []; $sets = []; $args = [$id];
        foreach ($cols as $i => $c) {
            if ($row[$c] === null) { $vals[] = 'NULL'; $sets[] = "$c = NULL"; continue; }
            $vals[] = $fmt[$i];
            $sets[] = "$c = VALUES($c)";
            $args[] = $row[$c];
        }
        $sql = "INSERT INTO $t (order_id, " . implode(', ', $cols) . ") VALUES (%d, " . implode(', ', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $sets);
        $ok = $wpdb->query($wpdb->prepare($sql, $args)) !== false;
        unset(self::$cache[$id]);
        if ($ok && class_exists('TIX_Mirror')) TIX_Mirror::touch('order', $id);
        return $ok;
    }

    /** Personenbezogene Kennungen löschen (Konto löschen): Einwilligung, Pixel-Kennungen, IP, Browser, Klick-IDs */
    public static function forget($order_id) {
        global $wpdb;
        $id = intval($order_id);
        $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET consent_marketing = NULL, consent_analytics = NULL, fbp = '', fbc = '', client_ip = '', client_ua = '', click_ids = NULL, analytics_ids = NULL WHERE order_id = %d", $id));
        unset(self::$cache[$id]);
        foreach (['_tix_meta_consent', '_tix_meta_no_consent', '_tix_meta_fbp', '_tix_meta_fbc', '_tix_meta_client_ip', '_tix_meta_client_ua'] as $k) delete_post_meta($id, $k);
        if (class_exists('TIX_Mirror')) TIX_Mirror::touch('order', $id);
    }

    /** Kennungen nach der letzten Kauf-Meldung bzw. nach 90 Tagen löschen (Einwilligung und Herkunft bleiben) */
    public static function forget_ids($order_id) {
        global $wpdb;
        $id = intval($order_id);
        $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET fbp = '', fbc = '', client_ip = '', client_ua = '', click_ids = NULL, analytics_ids = NULL WHERE order_id = %d", $id));
        unset(self::$cache[$id]);
    }

    /** Täglich: alle Kennungen älter als $days Tage löschen → Anzahl */
    public static function forget_old_ids($days) {
        global $wpdb;
        self::$cache = [];
        return intval($wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET fbp = '', fbc = '', client_ip = '', client_ua = '', click_ids = NULL, analytics_ids = NULL
            WHERE updated < %s AND (fbp <> '' OR fbc <> '' OR client_ip <> '' OR client_ua <> '' OR click_ids IS NOT NULL OR analytics_ids IS NOT NULL)", gmdate('Y-m-d H:i:s', time() - intval($days) * DAY_IN_SECONDS))));
    }

    /** Für viele Bestellungen auf einmal: [order_id => Zeile] (ohne Zeile fehlt der Schlüssel) */
    public static function many(array $order_ids, array $cols = ['channel', 'source', 'medium', 'campaign', 'link_code']) {
        global $wpdb;
        $out = [];
        $cols = array_values(array_intersect($cols, array_merge(array_keys(self::TEXT), ['promoter_id', 'consent_marketing'])));
        $sel = $cols ? ', ' . implode(', ', $cols) : '';
        foreach (array_chunk(array_values(array_unique(array_map('intval', $order_ids))), 500) as $chunk) {
            if (!$chunk) continue;
            foreach ((array) $wpdb->get_results("SELECT order_id$sel FROM " . self::table() . " WHERE order_id IN (" . implode(',', $chunk) . ")", ARRAY_A) as $r) $out[intval($r['order_id'])] = $r;
        }
        return $out;
    }

    /** Für den Datenspiegel: nur Herkunft, keine Kennungen */
    public static function mirror_data($order_id) {
        $r = self::get($order_id);
        if (!$r) return null;
        $host = (string) $r['referrer'];
        if ($host !== '' && strpos($host, '/') !== false) $host = (string) wp_parse_url($host, PHP_URL_HOST);
        return array_filter([
            'channel' => (string) $r['channel'], 'source' => (string) $r['source'], 'medium' => (string) $r['medium'],
            'campaign' => (string) $r['campaign'], 'content' => (string) $r['content'], 'referrer_host' => strtolower($host),
            'link_code' => (string) $r['link_code'], 'utm_id' => (string) ($r['utm_id'] ?? ''), 'promoter_id' => intval($r['promoter_id']) ?: null,
            'consent_marketing' => $r['consent_marketing'] === null ? null : (bool) intval($r['consent_marketing']),
        ], function ($v) { return $v !== '' && $v !== null; });
    }

    // ═══════════════ Umzug Postmeta → Tabelle ═══════════════

    const MIGRATE_VERSION = '1';
    /**
     * Stand des Umzugs für /info: {attribution: {done, pending (bool)}}; Admins zusätzlich rows (Zeilen in der Tabelle) und
     * left (Bestellungen mit Postmeta, die noch umziehen; höchstens 1000 gezählt).
     */
    public static function status($detail = false) {
        global $wpdb;
        $left = 0;
        if (class_exists('TIX_Order') && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', TIX_Order::table_name())) === TIX_Order::table_name()) {
            $keys = "'" . implode("','", array_keys(self::META_MAP)) . "'";
            $left = intval($wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN " . TIX_Order::table_name() . " o ON o.id = pm.post_id
                LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ($keys) AND (p.ID IS NULL OR p.post_type NOT IN ('shop_order','shop_order_refund','shop_order_placehold')) LIMIT 1000) x"));
        }
        $out = ['done' => self::migrated(), 'pending' => $left > 0, 'db' => (string) get_option('tix_order_attribution_db', '')];
        if ($detail) { $out['rows'] = intval($wpdb->get_var("SELECT COUNT(*) FROM " . self::table())); $out['left'] = $left; }
        return ['attribution' => $out];
    }

    public static function migrated() { return get_option('tix_attr_migrated') === self::MIGRATE_VERSION; }

    /** Ein Block je Aufruf (init bzw. Cron); läuft weiter, bis nichts mehr übrig ist */
    public static function maybe_migrate() {
        if (self::migrated() || !class_exists('TIX_Order')) return;
        if (get_transient('tix_attr_migrating')) return;
        set_transient('tix_attr_migrating', 1, 60);
        $n = self::migrate_batch(self::BATCH);
        delete_transient('tix_attr_migrating');
        if ($n < 0) return; // Datenbankfehler: nicht als fertig markieren, beim nächsten Aufruf erneut
        if ($n === 0) { update_option('tix_attr_migrated', self::MIGRATE_VERSION, true); return; }
        if (!wp_next_scheduled('tix_attr_migrate')) wp_schedule_single_event(time() + 30, 'tix_attr_migrate');
    }

    /** Verschiebt bis zu $limit Bestellungen; liefert die Anzahl verschobener Bestellungen, -1 bei Datenbankfehler */
    public static function migrate_batch($limit = self::BATCH) {
        global $wpdb;
        self::maybe_create();
        $keys = "'" . implode("','", array_keys(self::META_MAP)) . "'";
        $ot = TIX_Order::table_name();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ot)) !== $ot) return 0; // keine Bestellungen = nichts umzuziehen
        // nur Bestell-IDs aus tix_orders; Posts echter WooCommerce-Bestellungen (shop_order) behalten ihre Postmeta
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN $ot o ON o.id = pm.post_id
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN ($keys) AND (p.ID IS NULL OR p.post_type NOT IN ('shop_order', 'shop_order_refund', 'shop_order_placehold'))
             LIMIT %d", $limit));
        if ($ids === null || $wpdb->last_error !== '') return -1;
        if (!$ids) return 0;
        foreach ($ids as $oid) {
            $oid = intval($oid);
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($keys)", $oid));
            $f = [];
            foreach ((array) $rows as $r) {
                $col = self::META_MAP[$r->meta_key];
                $v = maybe_unserialize($r->meta_value);
                if ($col === 'consent_marketing') {
                    if ($r->meta_key === '_tix_meta_consent' && (string) $v === '1') $f['consent_marketing'] = 1;
                    elseif ($r->meta_key === '_tix_meta_no_consent' && (string) $v === '1' && !isset($f['consent_marketing'])) $f['consent_marketing'] = 0;
                    continue;
                }
                $f[$col] = is_scalar($v) ? $v : '';
            }
            // vorhandene Zeile (schon neu geschrieben) hat Vorrang: nur leere Spalten auffüllen
            $cur = self::get($oid);
            if ($cur) {
                foreach ($f as $k => $v) {
                    $c = $cur[$k] ?? null;
                    if ($c !== null && $c !== '' && $c !== '0' && $c !== 0) unset($f[$k]);
                }
            }
            if ($f) self::set($oid, $f);
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($keys)", $oid));
            wp_cache_delete($oid, 'post_meta');
        }
        return count($ids);
    }
}

TIX_Order_Attribution::init();
