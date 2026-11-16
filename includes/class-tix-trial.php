<?php
if (!defined('ABSPATH')) exit;
/**
 * TIX_Trial – Demo-Zugang automatisch (W1) und 14 Tage eigener Shop testen (W2), Vertrag uebergaben/vertrag-testphase.md.
 * Nur Plattform (Mehr-Veranstalter-Modus). Alles additiv; Geld/Server erst mit Schaltern:
 *  - Mollie-Schlüssel `tix_trial_mollie_key` (sonst 503 tix_trial_payment_off), Abhol-Geheimnis `tix_trial_queue_secret`
 *    bzw. EVENDIS_TRIAL_QUEUE_SECRET (sonst 503 tix_trial_queue_off), erste Abbuchung nur mit `tix_trial_auto_charge` = 1.
 * Kern als reine Funktionen (kurz_check, sig, res_sig, reminders_due, ends_at, is_disposable, company_key) – Prüfwerte im Test.
 */
class TIX_Trial {
    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const DAYS = 14;
    const DELETE_AFTER_DAYS = 3;
    const TOKEN_TTL = DAY_IN_SECONDS;
    const JOB_LOCK = 30 * MINUTE_IN_SECONDS;
    const PROGRESS_TTL = 2 * DAY_IN_SECONDS;
    /** Fortschrittsseite (1.38.857): interne Schritte des Kunden-Dienstes → sichtbare Stufe 2–6 */
    const PROGRESS_STEPS = ['pruefen' => 2, 'env' => 2, 'dns' => 2, 'site' => 2, 'plugin' => 3, 'lizenz' => 3, 'studio' => 4, 'marke' => 4, 'betreiber' => 4, 'testphase' => 4,
        'beispiel' => 4,
        'zugang' => 5, 'rechtstexte' => 5, 'verbindung' => 6, 'spiegel' => 6, 'probe' => 6, 'melden' => 6]; // 17 Schritte (1.38.859); unbekannte behalten die bisherige Stufe
    const PROGRESS_STAGES = [1 => 'Zahlungsmittel bestätigt', 2 => 'Adresse wird eingerichtet', 3 => 'Shop-System wird installiert', 4 => 'Studio und Design', 5 => 'Zugang und Rechtstexte', 6 => 'Letzte Prüfung', 7 => 'Fertig'];
    const SKEW = 300;
    const REMINDER_DAYS = [7, 12, 13];
    const MOLLIE = 'https://api.mollie.com/v2';
    const ACTIVE_STATES = ['requested', 'confirmed', 'queued', 'provisioning', 'active', 'failed', 'paused'];
    const PAUSE_DAYS = 14; // nopay: nach dem Pausieren bis zum Löschen (1.38.862)
    const FREE_STATES = ['expired', 'rejected', 'payment_failed', 'deleted'];
    /** x = genau, x* = beginnt mit, *x* = enthält (angeglichen an ops/kunden-hetzner/lib/sperrliste.py + testphase.py) */
    const BLOCKED_DEFAULT = ['www', 'mail', 'smtp', 'imap', 'ftp', 'api', 'app', 'apps', 'studio', 'admin', 'root', 'demo*', 'test*', 'staging*', 'dev*', 'veranstalter',
        'evendis*', 'tixomat*', 'support', 'hilfe', 'status', 'shop', 'kasse', 'ticket*', 'cdn', 'static', 'assets', 'blog', 'news', 'login', 'auth', 'connect', 'billing', 'rechnung*', 'intern*',
        // Server RESERVIERT
        'vorschau', 'pop', 'pop3', 'mx', 'sftp', 'media', 'docs', 'tickets', 'konto', 'sso', 'beta', 'kunden', 'partner', 'kitchenklub', 'mallorca', 'zentrale', 'dienst', 'webmail',
        'autodiscover', 'autoconfig', 'ns', 'ns1', 'ns2', 'wpad', 'localhost', 'vpn', 'git', 'cloud', 'portal', 'dashboard', 'plattform', 'platform', 'rechtliches', 'impressum',
        'datenschutz', 'agb', 'info', 'kontakt', 'presse', 'jobs',
        // Präfixe und Teilwörter (Phishing/Marken)
        'studio-*', 'demo-*', 'vorschau-*', 'xn--*', '*evendis*', '*tixomat*', '*kitchenklub*', '*mallorca*', '*login*', '*paypal*', '*mollie*', '*stripe*', '*eventim*',
        '*ticketmaster*', '*reservix*', '*google*', '*apple*', '*amazon*', '*facebook*', '*instagram*', '*tiktok*', '*passwort*', '*password*'];
    const DISPOSABLE_DEFAULT = ['mailinator.com', 'trashmail.com', 'trashmail.de', 'wegwerfmail.de', 'wegwerfemail.de', '10minutemail.com', 'guerrillamail.com', 'sharklasers.com',
        'yopmail.com', 'temp-mail.org', 'tempmail.com', 'dispostable.com', 'getnada.com', 'maildrop.cc', 'throwawaymail.com', 'mailnesia.com', 'emailondeck.com', 'spam4.me', 'fakeinbox.com', 'mohmal.com'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_trial_tick', [__CLASS__, 'tick']);
        add_action('tix_license_checked', [__CLASS__, 'on_license_checked'], 10, 2);
        add_action('init', function () {
            if (self::multi() && !wp_next_scheduled('tix_trial_tick')) wp_schedule_event(time() + 600, 'hourly', 'tix_trial_tick');
        }, 31);
    }

    public static function multi() {
        return class_exists('TIX_Sales') && TIX_Sales::multi();
    }

    // ═══════════════ Reiner Kern (ohne WP) ═══════════════

    /** Subdomain prüfen → ['ok' => bool, 'reason' => pattern|blocked|taken|null] */
    public static function kurz_check($kurz, array $blocked, array $taken) {
        $k = (string) $kurz;
        if (!preg_match('/^[a-z][a-z0-9-]{2,29}$/', $k) || substr($k, -1) === '-' || strpos($k, '--') !== false) return ['ok' => false, 'reason' => 'pattern'];
        foreach ($blocked as $b) {
            $b = strtolower(trim((string) $b));
            if ($b === '') continue;
            if (strlen($b) > 2 && $b[0] === '*' && substr($b, -1) === '*') { if (strpos($k, substr($b, 1, -1)) !== false) return ['ok' => false, 'reason' => 'blocked']; continue; } // enthält
            if (substr($b, -1) === '*' ? strpos($k, substr($b, 0, -1)) === 0 : $k === $b) return ['ok' => false, 'reason' => 'blocked'];
        }
        if (in_array($k, array_map('strtolower', $taken), true)) return ['ok' => false, 'reason' => 'taken'];
        return ['ok' => true, 'reason' => null];
    }

    /** Anfrage-Signatur (Server → Plugin) */
    public static function sig($secret, $ts, $nonce, $method, $path, $body) {
        return hash_hmac('sha256', 'evendis-trial-v1|' . $ts . '|' . $nonce . '|' . strtoupper($method) . '|' . $path . '|' . hash('sha256', (string) $body), (string) $secret);
    }

    /** Antwort-Signatur (Plugin → Server), an die Nonce der Anfrage gebunden (Server-Lücke 6) */
    public static function res_sig($secret, $ts, $nonce, $body) {
        return hash_hmac('sha256', 'evendis-trial-res-v1|' . $ts . '|' . $nonce . '|' . hash('sha256', (string) $body), (string) $secret);
    }

    private static $req_nonce = '';

    /** Fällige Erinnerungen (Tage) */
    public static function reminders_due($active_ts, array $sent, $now) {
        $due = [];
        if (!$active_ts) return $due;
        foreach (self::REMINDER_DAYS as $d) if ($now >= $active_ts + $d * 86400 && !in_array($d, $sent, true)) $due[] = $d;
        return $due;
    }

    /** Ende der Testphase: Tag 14 (+ Verlängerung) 23:59:59 in der Zeitzone */
    public static function ends_at($active_ts, $extra_days, DateTimeZone $tz) {
        $d = (new DateTimeImmutable('@' . intval($active_ts)))->setTimezone($tz)->modify('+' . (self::DAYS + intval($extra_days)) . ' days')->setTime(23, 59, 59);
        return $d->getTimestamp();
    }

    public static function is_disposable($email, array $list) {
        $dom = strtolower((string) substr(strrchr((string) $email, '@'), 1));
        if ($dom === '') return false;
        foreach ($list as $l) { $l = strtolower(trim((string) $l)); if ($l !== '' && ($dom === $l || substr($dom, -strlen('.' . $l)) === '.' . $l)) return true; }
        return false;
    }

    /**
     * Anteiliges Hochstufen (W2c, User 16.11.: sofort, Differenz anteilig für die Restlaufzeit) → array
     * {to_tier|null, on_request, diff_year, remaining_days, total_days, amount_net} ; null, wenn die Stufe reicht.
     */
    public static function upgrade_quote($tickets, $tier_max, array $tiers, $from_ts, $until_ts, $now) {
        usort($tiers, function ($a, $b) { return $a['max'] <=> $b['max']; });
        if ($tickets <= $tier_max) return null;
        $old = null; $new = null;
        foreach ($tiers as $x) { if (intval($x['max']) === intval($tier_max)) $old = floatval($x['price']); if ($new === null && $tickets <= intval($x['max'])) $new = $x; }
        if ($new === null) return ['to_tier' => null, 'on_request' => true, 'diff_year' => null, 'remaining_days' => null, 'total_days' => null, 'amount_net' => null];
        $total = max(1, (int) round(($until_ts - $from_ts) / 86400));
        $rest = max(0, min($total, (int) ceil(($until_ts - $now) / 86400)));
        $diff = round(floatval($new['price']) - floatval($old ?? 0), 2);
        return ['to_tier' => intval($new['max']), 'on_request' => false, 'diff_year' => $diff, 'remaining_days' => $rest, 'total_days' => $total, 'amount_net' => round(max(0, $diff) * $rest / $total, 2)];
    }

    /** Firma normalisiert (Grenze je Firma) */
    public static function company_key($c) {
        $c = mb_strtolower(trim((string) $c));
        $c = preg_replace('/\b(gmbh|ug|ag|kg|ohg|gbr|e\.?\s?k\.?|e\.?\s?v\.?|mbh|co\.?|haftungsbeschränkt|&)\b/u', ' ', $c);
        return trim(preg_replace('/[^a-z0-9äöüß]+/u', '', $c));
    }

    // ═══════════════ Speicher ═══════════════

    public static function t($n = 'tix_trials') { global $wpdb; return $wpdb->prefix . $n; }

    public static function maybe_install() {
        if (get_option('tix_trial_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kurz varchar(32) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'requested',
            email varchar(190) NOT NULL DEFAULT '',
            email_hash char(64) NOT NULL DEFAULT '',
            company varchar(190) NOT NULL DEFAULT '',
            company_key varchar(190) NOT NULL DEFAULT '',
            data longtext NOT NULL,
            mollie_customer_id varchar(40) NOT NULL DEFAULT '',
            mollie_mandate_id varchar(40) NOT NULL DEFAULT '',
            mandate_method varchar(20) NOT NULL DEFAULT '',
            mandate_status varchar(20) NOT NULL DEFAULT '',
            mandate_key char(64) NOT NULL DEFAULT '',
            token_hash char(64) NOT NULL DEFAULT '',
            cancel_token_hash char(64) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            active_at datetime NULL DEFAULT NULL,
            ends_at datetime NULL DEFAULT NULL,
            cancelled_at datetime NULL DEFAULT NULL,
            delete_at datetime NULL DEFAULT NULL,
            converted_at datetime NULL DEFAULT NULL,
            license_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY email_hash (email_hash),
            KEY kurz (kurz)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('tix_trial_requests') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(16) NOT NULL DEFAULT 'demo',
            email_hash char(64) NOT NULL DEFAULT '',
            data longtext NOT NULL,
            token_hash char(64) NOT NULL DEFAULT '',
            expires_at datetime NOT NULL,
            used_at datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY token_hash (token_hash),
            KEY email_hash (email_hash)
        ) $c;");
        update_option('tix_trial_db', self::DB_VERSION, false);
    }

    private static function now() { return current_time('mysql'); }
    private static function hash($v) { return hash('sha256', strtolower(trim((string) $v))); }
    private static function ip_hash() { return hash('sha256', (class_exists('TIX_App_Account') ? TIX_App_Account::client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '')) . '|' . wp_date('Y-m-d')); }

    public static function get($id) {
        global $wpdb;
        self::maybe_install();
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t() . ' WHERE id = %d', $id));
        if ($r) $r->d = json_decode((string) $r->data, true) ?: [];
        return $r;
    }

    /** Status ändern (mit Verlauf); $cols weitere Spalten, $d neues data */
    private static function set($row, $status, array $cols = [], $d = null, $by = 'system') {
        global $wpdb;
        $d = $d ?? $row->d;
        if ($status !== $row->status) $d['history'][] = ['at' => wp_date('c'), 'from' => $row->status, 'to' => $status, 'by' => $by];
        $d['history'] = array_slice((array) ($d['history'] ?? []), -60);
        $wpdb->update(self::t(), array_merge($cols, ['status' => $status, 'data' => wp_json_encode($d), 'updated_at' => self::now()]), ['id' => $row->id]);
        return self::get($row->id);
    }

    // ═══════════════ Einstellungen ═══════════════

    public static function blocked() {
        $b = get_option('tix_trial_blocked', null);
        $list = is_array($b) && $b ? $b : self::BLOCKED_DEFAULT;
        // Marken bestehender Kunden: Hosting-Kurzname und erste Domain-Teile
        if (class_exists('TIX_Platform_Customers')) {
            foreach (TIX_Platform_Customers::index()['customers'] as $c) {
                if (!empty($c['hosting_kurz'])) $list[] = strtolower($c['hosting_kurz']);
                foreach ((array) ($c['_lic']['hosts'] ?? []) as $h) $list[] = strtolower(explode('.', preg_replace('/^www\./', '', (string) $h))[0]);
            }
        }
        return array_values(array_unique(array_filter($list)));
    }

    public static function taken() {
        global $wpdb;
        self::maybe_install();
        return array_map('strval', (array) $wpdb->get_col("SELECT kurz FROM " . self::t() . " WHERE status NOT IN ('" . implode("','", self::FREE_STATES) . "')"));
    }

    public static function disposable() {
        $l = get_option('tix_disposable_domains', null);
        return is_array($l) && $l ? $l : self::DISPOSABLE_DEFAULT;
    }

    public static function secret() {
        $s = defined('EVENDIS_TRIAL_QUEUE_SECRET') ? (string) EVENDIS_TRIAL_QUEUE_SECRET : (string) get_option('tix_trial_queue_secret', '');
        return strlen($s) >= 32 ? $s : '';
    }

    /**
     * Mollie-Schlüssel der Testphase (1.38.853): eigener Schlüssel (Studio „Plattform-Einstellungen“, nur schreiben) vor dem
     * Schlüssel des Ticketverkaufs – Rückfall nur, wenn der ein live_-Schlüssel ist (ein test_-Schlüssel würde echte Kunden in eine
     * Test-Kasse schicken, ohne echtes Mandat).
     */
    private static function mollie_key() { return self::mollie_source()['key']; }

    /** → ['key' => string, 'source' => own|ticket|none] */
    public static function mollie_source() {
        $own = trim((string) get_option('tix_trial_mollie_key', ''));
        if ($own !== '') return ['key' => $own, 'source' => 'own'];
        $s = get_option('tix_settings', []);
        $tk = is_array($s) ? trim((string) ($s['mollie_api_key'] ?? '')) : '';
        if (preg_match('/^live_[A-Za-z0-9]{20,}$/', $tk)) return ['key' => $tk, 'source' => 'ticket'];
        return ['key' => '', 'source' => 'none'];
    }

    /** Anzeige ohne Schlüssel: gesetzt, Quelle, live/test, letzte 4 Zeichen */
    public static function mollie_status() {
        $m = self::mollie_source();
        $k = $m['key'];
        $s = get_option('tix_settings', []);
        $tk = is_array($s) ? trim((string) ($s['mollie_api_key'] ?? '')) : '';
        return ['set' => $k !== '', 'source' => $m['source'], 'mode' => $k === '' ? null : (strpos($k, 'live_') === 0 ? 'live' : 'test'), 'ends_with' => $k === '' ? null : substr($k, -4),
            'ticket_key' => $tk === '' ? 'none' : (strpos($tk, 'live_') === 0 ? 'live' : 'test'), 'checked_at' => get_option('tix_trial_mollie_checked', null) ?: null];
    }

    /** GET /platform/trial-settings → {ok, mollie: status} (Admin) */
    public static function rest_settings_get(WP_REST_Request $req) {
        return self::out(['ok' => true, 'mollie' => self::mollie_status()]);
    }

    /** POST /platform/trial-settings {mollie_key: "live_…|test_…" | "" (entfernen)} – nur schreiben; Schlüssel wird bei Mollie geprüft, nie ausgegeben */
    public static function rest_settings_save(WP_REST_Request $req) {
        $b = self::body($req);
        if (!array_key_exists('mollie_key', $b)) return self::err('tix_invalid_field', 'Bitte einen Schlüssel angeben.', 400, 'mollie_key');
        $k = trim((string) $b['mollie_key']);
        if ($k === '') {
            delete_option('tix_trial_mollie_key'); delete_option('tix_trial_mollie_checked');
            if (class_exists('TIX_Customers')) TIX_Customers::audit('trial_mollie_key', 0, ['scope' => 'platform', 'oid' => 0], ['removed' => true]);
            return self::out(['ok' => true, 'mollie' => self::mollie_status()]);
        }
        if (!preg_match('/^(live|test)_[A-Za-z0-9]{20,}$/', $k)) return self::err('tix_invalid_field', 'Das sieht nicht wie ein Mollie-API-Schlüssel aus (live_… bzw. test_…).', 400, 'mollie_key');
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('trial_mollie_save', 10, HOUR_IN_SECONDS, (string) get_current_user_id())) return self::err('tix_rate_limited', 'Zu viele Versuche. Bitte später noch einmal.', 429);
        // Prüfen bei Mollie (Konto erreichbar, Schlüssel gültig), bevor er gespeichert wird
        $r = wp_remote_get(self::MOLLIE . '/methods', ['timeout' => 10, 'headers' => ['Authorization' => 'Bearer ' . $k]]);
        $code = is_wp_error($r) ? 0 : intval(wp_remote_retrieve_response_code($r));
        if ($code === 401) return self::err('tix_trial_mollie_invalid', 'Mollie kennt diesen Schlüssel nicht.', 400, 'mollie_key');
        if ($code !== 200) return self::err('tix_mollie_unavailable', 'Mollie antwortet gerade nicht. Bitte gleich noch einmal.', 502);
        update_option('tix_trial_mollie_key', $k, false);
        update_option('tix_trial_mollie_checked', wp_date('c'), false);
        if (class_exists('TIX_Customers')) TIX_Customers::audit('trial_mollie_key', 0, ['scope' => 'platform', 'oid' => 0], ['mode' => strpos($k, 'live_') === 0 ? 'live' : 'test', 'ends_with' => substr($k, -4)]); // nie der Schlüssel
        return self::out(['ok' => true, 'mollie' => self::mollie_status()]);
    }

    private static function origins() {
        $o = get_option('tix_trial_origins', null);
        return is_array($o) && $o ? $o : ['demo.evendis.de', 'evendis.de', 'veranstalter.evendis.de'];
    }

    private static function tiers() {
        $c = class_exists('TIX_Platform_Offers') ? TIX_Platform_Offers::catalog() : [];
        $t = (array) ($c['shop_tiers'] ?? []);
        usort($t, function ($a, $b) { return $a['max'] <=> $b['max']; });
        return $t;
    }

    // ═══════════════ Schutz für öffentliche Routen ═══════════════

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    private static function err($code, $msg, $status, $field = null, array $extra = []) {
        return new WP_Error($code, $msg, array_merge(['status' => $status], $field ? ['field' => $field] : [], $extra));
    }

    /** Herkunft, Honigtopf (true = still verwerfen), Ratenlimit */
    private static function guard(WP_REST_Request $req, $bucket, $max = 5, $window = HOUR_IN_SECONDS) {
        $host = '';
        foreach ([(string) $req->get_header('x_evendis_origin'), (string) parse_url((string) $req->get_header('origin'), PHP_URL_HOST), (string) parse_url((string) $req->get_header('referer'), PHP_URL_HOST)] as $h) if ($h !== '') { $host = strtolower($h); break; }
        if ($host === '' || !in_array($host, self::origins(), true)) return self::err('tix_origin', 'Diese Anfrage ist von hier aus nicht erlaubt.', 403);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('trial_' . $bucket, $max, $window)) return self::err('tix_rate_limited', 'Zu viele Anfragen. Bitte später noch einmal.', 429, null, ['retry_after' => $window]);
        $b = (array) $req->get_json_params() ?: (array) $req->get_body_params();
        if (trim((string) ($b['website'] ?? '')) !== '') return true;
        $t = intval($b['t'] ?? 0);
        if ($t > 0 && time() - $t < 3) return true;
        return null;
    }

    /** Ablauf (1.38.862): nopay = ohne Zahlungsdaten, „Aktiv halten“ mit Zahlung; mandate = bisher (0-€-Mandat, Übergang an Tag 14). */
    public static function flow_default() { return get_option('tix_trial_flow', 'mandate') === 'nopay' ? 'nopay' : 'mandate'; }
    public static function flow_of($t) { return (string) ($t->d['flow'] ?? 'mandate') === 'nopay' ? 'nopay' : 'mandate'; }

    /** Einwilligungen nopay (Rechtstexte 84eb1da4, Entwurf, Anwaltsprüfung R61 offen) – Wortlaut ohne Links; Platzhalter in eckigen Klammern */
    const CONSENT_VERSION = '2026-11-16 (Rechtstexte 84eb1da4)';
    const CONSENT_A = [
        'b2b' => 'Ich bestelle für [Firma] als Unternehmer (nicht als Verbraucher) und bin berechtigt, für die Firma zu handeln.',
        'trial_terms' => 'Ich akzeptiere die Bedingungen für die Bestellung einschließlich § 11 (Testshop). Mir ist klar: Der Testshop ist 14 Tage kostenlos und endet von selbst. Es verlängert sich nichts automatisch, und es wird nichts abgebucht. Halte ich den Shop nicht aktiv, wird er pausiert und 14 Tage später gelöscht.',
    ];
    const CONSENT_B = [
        'b2b' => 'Ich schließe den Vertrag für [Firma] als Unternehmer (nicht als Verbraucher) und bin berechtigt, für die Firma zu handeln.',
        'contract' => 'Ich schließe den Lizenzvertrag für den Eigenen Shop einschließlich der Vereinbarung zur Auftragsverarbeitung zu den Bedingungen für die Bestellung ab: [Paket] für [Preis] zzgl. USt im Jahr, sofort fällig und jetzt über Mollie zu bezahlen. Der Vertrag läuft ein Jahr ab heute und verlängert sich um je ein Jahr, wenn ich nicht bis einen Monat vor Ablauf kündige. Mein Zahlungsmittel wird für die folgenden Jahresbeträge und eine anteilige Hochstufung bei mehr Tickets gespeichert.',
    ];

    /** Nachweis einer Einwilligung: Wortlaut mit eingesetzten Werten, Fassung, Zeit, IP-Hash (rein bis auf Zeit/IP) */
    public static function consent_record(array $texts, array $vars) {
        return ['version' => (string) get_option('tix_trial_consent_version', self::CONSENT_VERSION), 'at' => wp_date('c'), 'ip_hash' => self::ip_hash(),
            'texts' => array_map(function ($tx) use ($vars) { return strtr($tx, $vars); }, $texts)];
    }

    public static function money($v) {
        $v = floatval($v);
        return number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2, ',', '.') . ' €';
    }

    public static function tier_label($tier) {
        return 'Paket bis ' . number_format(intval($tier), 0, ',', '.') . ' Tickets';
    }

    /** Preis je Jahr (netto/brutto) der gebuchten Stufe → ['net','gross'] | null */
    public static function price_of($tier) {
        $net = self::tier_price($tier);
        return $net ? ['net' => round($net, 2), 'gross' => round($net * 1.19, 2), 'currency' => 'EUR', 'interval' => 'year'] : null;
    }

    /** Inhaber-Adresse auf evendis.de bzw. *.evendis.de – der Kunden-Server lehnt sie ab (ops/kunden-hetzner/lib/testphase.py), also schon hier (1.38.855) */
    public static function is_own_domain($email) {
        $e = strtolower(trim((string) $email));
        return substr($e, -11) === '@evendis.de' || substr($e, -11) === '.evendis.de';
    }

    private static function body(WP_REST_Request $req) {
        $b = $req->get_json_params();
        return is_array($b) ? $b : (array) $req->get_body_params();
    }

    private static function email_ok($email) {
        if (!is_email($email)) return self::err('tix_invalid_field', 'Bitte eine gültige E-Mail angeben.', 400, 'email');
        if (self::is_disposable($email, self::disposable())) return self::err('tix_invalid_field', 'Bitte eine geschäftliche oder dauerhafte E-Mail-Adresse nehmen.', 400, 'email');
        return null;
    }

    /** Grenze je E-Mail (3 je Tag) – erst nach erfolgreicher Prüfung zählen, Tippfehler sperren niemanden aus */
    private static function email_limit($email) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('trial_mail', 3, DAY_IN_SECONDS, self::hash($email))) return self::err('tix_rate_limited', 'Zu viele Anfragen für diese Adresse. Bitte morgen noch einmal.', 429, null, ['retry_after' => DAY_IN_SECONDS]);
        return null;
    }

    // ═══════════════ Mails ═══════════════

    /**
     * Mail der Testphase (ab 1.38.858 nach User-Fund): nur der erste Knopf ist Hauptaktion (rot, klein), weitere als Umriss-Knopf;
     * Kündigen nie als Knopf, sondern als kleiner Textlink unter dem Text ($cancel_url).
     */
    private static function mail($to, $subject, $title, array $paras, array $buttons = [], $kind = 'account', $cancel_url = '', $cancel_label = 'Testphase kündigen') {
        $h = '';
        foreach ($paras as $p) $h .= '<p>' . esc_html($p) . '</p>';
        foreach (array_values($buttons) as $i => [$label, $url]) $h .= class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::button($label, $url, $i === 0 ? 'compact' : 'outline') : '<p><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
        if ($cancel_url !== '') $h .= '<p class="tm-muted" style="margin:16px 0 0;font-size:13px;line-height:18px;color:#6B7280;text-align:center;">Du willst doch nicht? <a href="' . esc_url($cancel_url) . '" style="color:#6B7280;text-decoration:underline;">' . esc_html($cancel_label) . '</a></p>';
        if (class_exists('TIX_Mail_Layout')) return TIX_Mail_Layout::send($to, $subject, ['title' => $title, 'blocks' => [['type' => 'html', 'html' => $h]], 'kind' => $kind], ['customer' => false]);
        return wp_mail($to, $subject, $h, ['Content-Type: text/html; charset=UTF-8']);
    }

    private static function notify_sales($subject, array $paras) {
        $to = get_option('tix_demo_notify', null);
        if (!is_array($to) || !$to) {
            $to = [];
            foreach (get_users(['role__in' => ['administrator', 'tix_sales'], 'fields' => ['user_email']]) as $u) $to[] = $u->user_email;
        }
        foreach (array_unique(array_filter(array_map('sanitize_email', $to))) as $a) self::mail($a, $subject, $subject, $paras, [], 'admin');
    }

    /** Mail-Links (mit den Homepages abgestimmt): Basis tix_trial_site_url, feste Pfade; Rückweg von Mollie: tix_trial_return_url */
    const PATHS = ['demo' => '/zugang/bestaetigen/', 'trial' => '/testen/bestaetigen/', 'kuendigen' => '/testen/kuendigen/', 'starten' => '/testen/starten/', 'neu' => '/testen/', 'aktiv' => '/testen/aktiv-halten/'];
    private static function page($kind, $token = '') {
        if ($kind === 'activated') return add_query_arg('trial', rawurlencode($token), (string) get_option('tix_trial_activate_return_url', '') ?: 'https://demo.evendis.de/testen/aktiviert/');
        if ($kind === 'mandate') return add_query_arg('trial', rawurlencode($token), (string) get_option('tix_trial_return_url', '') ?: 'https://demo.evendis.de/testen/fertig/');
        $base = untrailingslashit((string) get_option('tix_trial_site_url', '') ?: 'https://demo.evendis.de');
        $url = $base . (self::PATHS[$kind] ?? '/');
        return $token !== '' ? add_query_arg('token', rawurlencode($token), $url) : $url;
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $pub = function () { return self::multi() ? true : new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]); };
        $staff = function () {
            if (!self::multi()) return new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]);
            if (current_user_can('manage_options') || (class_exists('TIX_Sales') && TIX_Sales::is_sales_user())) return true;
            return new WP_Error('rest_forbidden', 'Nur für den Vertrieb.', ['status' => 403]);
        };
        $admin = function () { return self::multi() ? (current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur für Admins.', ['status' => 403])) : new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]); };
        $signed = [__CLASS__, 'perm_signed'];
        $id = '(?P<id>\d+)';
        // W1
        register_rest_route(self::NS, '/public/demo-access/request', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_demo_request'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/public/demo-access/request/confirm', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_demo_confirm'], 'permission_callback' => $pub]);
        // W2 öffentlich
        register_rest_route(self::NS, '/public/trial/tiers', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_tiers'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/public/trial/check-subdomain', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_check'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/public/trial/order', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_order'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/public/trial/confirm', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_confirm'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/public/trial/mollie-webhook', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_webhook'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/public/trial/cancel', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_cancel_token'], 'permission_callback' => $pub]);
        // W2b: Stand für das Band im Test-Studio und „Jetzt starten“ (Verwaltungs-Token aus tix_trial_info der Kunden-Seite)
        register_rest_route(self::NS, '/public/trial/status', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_status'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/public/trial/start', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_start'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/public/trial/activate', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_activate'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/trial/(?P<id>\d+)/invoices/(?P<n>\d+)/pdf', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_invoice_pdf'], 'permission_callback' => $staff]);
        // Server (signiert)
        register_rest_route(self::NS, '/trial/queue', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_queue'], 'permission_callback' => $signed]);
        register_rest_route(self::NS, "/trial/$id/done", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_done'], 'permission_callback' => $signed]);
        register_rest_route(self::NS, "/trial/$id/progress", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_progress'], 'permission_callback' => $signed]);
        register_rest_route(self::NS, '/public/trial/progress', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_progress_public'], 'permission_callback' => $pub]);
        // Studio
        register_rest_route(self::NS, '/trial', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $staff]);
        foreach (['extend', 'convert', 'retry', 'cancel'] as $a) register_rest_route(self::NS, "/trial/$id/$a", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_' . $a], 'permission_callback' => $staff]);
        register_rest_route(self::NS, "/trial/$id/delete", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $admin]);
        register_rest_route(self::NS, '/platform/trial-settings', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_settings_get'], 'permission_callback' => $admin],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_settings_save'], 'permission_callback' => $admin],
        ]);
        register_rest_route(self::NS, "/trial/$id/contract-cancel", ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_contract_cancel'], 'permission_callback' => $staff]);
    }

    // ── W1 Demo-Zugang ──

    public static function rest_demo_request(WP_REST_Request $req) {
        $g = self::guard($req, 'demo_req');
        if (is_wp_error($g)) return $g;
        if ($g === true) return self::out(['ok' => true, 'status' => 'confirm_sent'], 202);
        $b = self::body($req);
        $name = mb_substr(trim(sanitize_text_field((string) ($b['name'] ?? ''))), 0, 80);
        $email = sanitize_email((string) ($b['email'] ?? ''));
        $company = mb_substr(trim(sanitize_text_field((string) ($b['company'] ?? ''))), 0, 80);
        $profile = in_array($b['profile'] ?? '', ['club', 'festival'], true) ? $b['profile'] : '';
        if ($name === '') return self::err('tix_invalid_field', 'Bitte einen Namen angeben.', 400, 'name');
        if ($e = self::email_ok($email)) return $e;
        if ($profile === '') return self::err('tix_invalid_field', 'Bitte ein Beispiel wählen: Club oder Festival.', 400, 'profile');
        if (empty($b['consent'])) return self::err('tix_invalid_field', 'Bitte den Datenschutz-Hinweis bestätigen.', 400, 'consent');
        $cases = isset($b['cases']) && class_exists('TIX_Demo_Remote') ? TIX_Demo_Remote::clean_cases($b['cases']) : null;
        if ($cases === null) $cases = [$profile === 'festival' ? 'shop' : 'club'];
        if (!$cases) return self::err('tix_invalid_field', 'Bitte mindestens einen Fall wählen.', 400, 'cases');
        if ($e = self::email_limit($email)) return $e;
        global $wpdb;
        self::maybe_install();
        $token = wp_generate_password(40, false, false);
        $wpdb->insert(self::t('tix_trial_requests'), ['kind' => 'demo', 'email_hash' => self::hash($email), 'token_hash' => hash('sha256', $token),
            'data' => wp_json_encode(['name' => $name, 'email' => $email, 'company' => $company, 'profile' => $profile, 'cases' => $cases, 'model' => in_array($b['model'] ?? '', ['self', 'platform'], true) ? $b['model'] : 'self',
                'consent_at' => wp_date('c'), 'ip_hash' => self::ip_hash()]),
            'expires_at' => wp_date('Y-m-d H:i:s', time() + self::TOKEN_TTL), 'created_at' => self::now()]);
        self::mail($email, 'Bitte bestätige deinen Demo-Zugang', 'Fast geschafft',
            ['Hallo ' . strtok($name, ' ') . ', bitte bestätige deine E-Mail-Adresse. Danach bekommst du sofort deinen Zugang zum evendis-Demo-Studio mit Beispieldaten.', 'Der Link gilt 24 Stunden.'],
            [['E-Mail bestätigen', self::page('demo', $token)]]);
        return self::out(['ok' => true, 'status' => 'confirm_sent'], 202);
    }

    public static function rest_demo_confirm(WP_REST_Request $req) {
        $b = self::body($req);
        $token = (string) ($b['token'] ?? '');
        global $wpdb;
        self::maybe_install();
        $r = $token !== '' ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('tix_trial_requests') . " WHERE kind = 'demo' AND token_hash = %s", hash('sha256', $token))) : null;
        if (!$r) return self::err('tix_token_unknown', 'Dieser Link ist nicht bekannt.', 404);
        if ($r->used_at || $r->expires_at < self::now()) return self::err('tix_token_expired', 'Dieser Link ist abgelaufen oder wurde schon benutzt. Bitte fordere den Zugang neu an.', 410);
        $d = json_decode((string) $r->data, true) ?: [];
        $wpdb->update(self::t('tix_trial_requests'), ['used_at' => self::now()], ['id' => $r->id]);
        $u = get_user_by('email', $d['email']);
        if ($u && in_array('tix_demo', (array) $u->roles, true) && class_exists('TIX_Demo_Access')) {
            TIX_Demo_Access::send_link($u, false);
            return self::out(['ok' => true, 'status' => 'existing', 'login_link_sent' => true]);
        }
        if ($u) return self::out(['ok' => true, 'status' => 'existing', 'login_link_sent' => false]); // anderes Konto: keine Auskunft, kein Zugang
        // Gleiche Anlage wie im Vertrieb – als System-Konto (Inhaber der selbst angeforderten Zugänge)
        $owner = intval(get_option('tix_demo_self_owner', 0)) ?: intval(get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID'])[0] ?? 0);
        $prev = get_current_user_id();
        wp_set_current_user($owner);
        $x = new WP_REST_Request('POST', '/' . self::NS . '/demo-access');
        $x->set_param('kind', 'prospect');
        foreach (['name', 'email', 'company', 'profile', 'model', 'cases'] as $k) $x->set_param($k, $d[$k] ?? null);
        $res = TIX_Demo_Access::rest_create($x);
        wp_set_current_user($prev);
        if (is_wp_error($res)) return $res;
        $acc = $res->get_data()['access'] ?? [];
        $uid = intval($acc['id'] ?? 0);
        if ($uid) { update_user_meta($uid, '_tix_demo_source', 'self'); update_user_meta($uid, '_tix_demo_consent_at', $d['consent_at'] ?? wp_date('c')); }
        self::notify_sales('Neuer Demo-Zugang (selbst angefordert)', ['Name: ' . $d['name'], 'Firma: ' . ($d['company'] ?: '–'), 'E-Mail: ' . $d['email'], 'Beispiel: ' . $d['profile'] . ' · Fälle: ' . implode(', ', (array) $d['cases'])]);
        return self::out(['ok' => true, 'status' => 'created', 'login_link_sent' => !empty($res->get_data()['invite_sent'])]);
    }

    // ── W2 Bestellung ──

    /** Preise je Stufe aus dem Katalog (jährlich, netto) */
    public static function public_tiers() {
        return array_map(function ($x) { return ['max' => intval($x['max']), 'price' => round(floatval($x['price']), 2), 'currency' => 'EUR', 'interval' => 'year', 'tax' => 'net']; }, self::tiers());
    }

    public static function rest_tiers(WP_REST_Request $req) {
        $r = new WP_REST_Response(['ok' => true, 'tiers' => self::public_tiers(), 'above' => 'auf Anfrage'], 200);
        $r->header('Cache-Control', 'public, max-age=300');
        return $r;
    }

    public static function rest_check(WP_REST_Request $req) {
        $g = self::guard($req, 'check', 30, MINUTE_IN_SECONDS);
        if (is_wp_error($g)) return $g;
        $k = strtolower(trim((string) (self::body($req)['kurz'] ?? '')));
        $c = self::kurz_check($k, self::blocked(), self::taken());
        $out = ['ok' => true, 'kurz' => $k, 'available' => $c['ok'], 'tiers' => self::public_tiers()];
        if (!$c['ok']) {
            $out['reason'] = $c['reason'];
            if ($c['reason'] === 'taken') for ($i = 2; $i < 50; $i++) { $s = substr($k, 0, 26) . '-' . $i; if (self::kurz_check($s, self::blocked(), self::taken())['ok']) { $out['suggestion'] = $s; break; } }
        }
        return self::out($out);
    }

    public static function rest_order(WP_REST_Request $req) {
        $g = self::guard($req, 'order');
        if (is_wp_error($g)) return $g;
        if ($g === true) return self::out(['ok' => true, 'status' => 'confirm_sent', 'trial_id' => 0], 202);
        $b = self::body($req);
        $txt = function ($v, $n) { return mb_substr(trim(sanitize_text_field((string) $v)), 0, $n); };
        $kurz = strtolower(trim((string) ($b['kurz'] ?? '')));
        $kc = self::kurz_check($kurz, self::blocked(), self::taken());
        if (!$kc['ok']) return self::err('tix_invalid_field', $kc['reason'] === 'taken' ? 'Diese Adresse ist schon vergeben.' : 'Diese Adresse ist nicht möglich (3–30 Zeichen, a–z, 0–9, Bindestrich).', 400, 'kurz', ['reason' => $kc['reason']]);
        $ct = (array) ($b['contact'] ?? []); $ad = (array) ($b['address'] ?? []); $cs = (array) ($b['consents'] ?? []);
        $d = ['shop_name' => $txt($b['shop_name'] ?? '', 80), 'company' => $txt($b['company'] ?? '', 120),
            'contact' => ['name' => $txt($ct['name'] ?? '', 80), 'email' => sanitize_email((string) ($ct['email'] ?? '')), 'phone' => $txt($ct['phone'] ?? '', 40)],
            'address' => ['street' => $txt($ad['street'] ?? '', 120), 'zip' => $txt($ad['zip'] ?? '', 12), 'city' => $txt($ad['city'] ?? '', 80), 'country' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($ad['country'] ?? 'DE')), 0, 2)) ?: 'DE'],
            'vat_id' => strtoupper(preg_replace('/\s+/', '', (string) ($b['vat_id'] ?? ''))), 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($b['color'] ?? '')) ? strtoupper($b['color']) : '#FC2F55',
            'logo_url' => preg_match('#^https://#', (string) ($b['logo_url'] ?? '')) ? esc_url_raw($b['logo_url']) : '', 'tier' => intval($b['tier'] ?? 0),
            'profile' => in_array($b['profile'] ?? '', ['club', 'festival'], true) ? $b['profile'] : 'festival'];
        foreach (['shop_name' => $d['shop_name'], 'company' => $d['company'], 'contact.name' => $d['contact']['name'], 'contact.phone' => $d['contact']['phone'],
            'address.street' => $d['address']['street'], 'address.zip' => $d['address']['zip'], 'address.city' => $d['address']['city']] as $f => $v) if ($v === '') return self::err('tix_invalid_field', 'Bitte dieses Feld ausfüllen.', 400, $f);
        if ($e = self::email_ok($d['contact']['email'])) { $e->add_data(array_merge($e->get_error_data(), ['field' => 'contact.email'])); return $e; }
        if (self::is_own_domain($d['contact']['email'])) return self::err('tix_invalid_field', 'Bitte eine Adresse deiner Firma angeben – evendis-Adressen sind nicht möglich.', 400, 'contact.email');
        if ($d['vat_id'] !== '' && !preg_match('/^(DE\d{9}|(AT|BE|BG|CY|CZ|DK|EE|EL|ES|FI|FR|HR|HU|IE|IT|LT|LU|LV|MT|NL|PL|PT|RO|SE|SI|SK)[0-9A-Z]{2,12})$/', $d['vat_id'])) return self::err('tix_invalid_field', 'Bitte eine gültige USt-IdNr. angeben.', 400, 'vat_id');
        if (!in_array($d['tier'], array_map('intval', array_column(self::tiers(), 'max')), true)) return self::err('tix_invalid_field', 'Bitte eine Paket-Stufe wählen.', 400, 'tier');
        if (self::flow_default() === 'nopay') { // Einwilligung A (Rechtstexte 84eb1da4): wörtlich mit Werten, Fassung, Zeit, IP-Hash speichern
            foreach (array_keys(self::CONSENT_A) as $k) if (empty($cs[$k])) return self::err('tix_invalid_field', 'Bitte bestätigen.', 400, 'consents.' . $k);
            $d['consents'] = self::consent_record(self::CONSENT_A, ['[Firma]' => $d['company']]);
        } else {
            foreach (['terms', 'trial_contract', 'privacy', 'b2b'] as $k) if (empty($cs[$k])) return self::err('tix_invalid_field', 'Bitte bestätigen.', 400, 'consents.' . $k);
            $d['consents'] = ['terms' => true, 'trial_contract' => true, 'privacy' => true, 'b2b' => true, 'at' => wp_date('c')];
        }
        global $wpdb;
        self::maybe_install();
        $act = "status IN ('" . implode("','", self::ACTIVE_STATES) . "')";
        $eh = self::hash($d['contact']['email']); $ih = self::ip_hash(); $ck = self::company_key($d['company']);
        if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t() . " WHERE $act AND (email_hash = %s OR ip_hash = %s" . ($ck !== '' ? ' OR company_key = %s' : '') . ')', ...array_values(array_filter([$eh, $ih, $ck], 'strlen'))))) {
            return self::err('tix_trial_exists', 'Es gibt schon eine laufende Testphase für diese Angaben. Bitte melde dich bei uns, wenn du Hilfe brauchst.', 409);
        }
        $d['flow'] = self::flow_default();
        if ($d['flow'] === 'nopay') { // Missbrauchsschutz ohne Karte: Tagesgrenze plattformweit (1.38.862)
            $lim = max(1, intval(get_option('tix_trial_daily_limit', 20)));
            if (intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t() . ' WHERE created_at >= %s', wp_date('Y-m-d') . ' 00:00:00'))) >= $lim)
                return self::err('tix_trial_daily_limit', 'Heute sind schon sehr viele Testphasen gestartet. Bitte versuche es morgen noch einmal oder schreib uns.', 429, null, ['retry_after' => DAY_IN_SECONDS]);
        }
        if ($e = self::email_limit($d['contact']['email'])) return $e;
        $token = wp_generate_password(40, false, false);
        $d['history'] = [['at' => wp_date('c'), 'from' => null, 'to' => 'requested', 'by' => 'customer']];
        $wpdb->insert(self::t(), ['kurz' => $kurz, 'status' => 'requested', 'email' => $d['contact']['email'], 'email_hash' => $eh, 'company' => $d['company'], 'company_key' => $ck,
            'data' => wp_json_encode($d), 'token_hash' => hash('sha256', $token), 'ip_hash' => $ih, 'created_at' => self::now(), 'updated_at' => self::now()]);
        $id = intval($wpdb->insert_id);
        if (!$id) return self::err('tix_trial_failed', 'Die Bestellung konnte nicht gespeichert werden.', 500);
        self::mail($d['contact']['email'], 'Bitte bestätige deine Testphase', 'Fast geschafft',
            ['Hallo ' . strtok($d['contact']['name'], ' ') . ', bitte bestätige deine E-Mail-Adresse. ' . ($d['flow'] === 'nopay' ? 'Danach richten wir deinen Shop unter ' . $kurz . '.evendis.de ein – ohne Zahlungsdaten.' : 'Danach hinterlegst du ein Zahlungsmittel (es wird nichts abgebucht) und wir richten deinen Shop unter ' . $kurz . '.evendis.de ein.'), 'Der Link gilt 24 Stunden.'],
            [['E-Mail bestätigen', self::page('trial', $token)]]);
        return self::out(['ok' => true, 'status' => 'confirm_sent', 'trial_id' => $id], 202);
    }

    // ── Mollie ──

    /** → [status, body array] ; Status 0 = Netzfehler */
    public static function mollie($method, $path, $body = null) {
        $key = self::mollie_key();
        $args = ['method' => $method, 'timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json']];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $r = wp_remote_request(self::MOLLIE . $path, $args);
        if (is_wp_error($r)) return [0, []];
        return [intval(wp_remote_retrieve_response_code($r)), json_decode((string) wp_remote_retrieve_body($r), true) ?: []];
    }

    public static function rest_confirm(WP_REST_Request $req) {
        $token = (string) (self::body($req)['token'] ?? '');
        global $wpdb;
        self::maybe_install();
        $row = $token !== '' ? $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . self::t() . ' WHERE token_hash = %s', hash('sha256', $token))) : null;
        if (!$row) return self::err('tix_token_unknown', 'Dieser Link ist nicht bekannt.', 404);
        $t = self::get($row->id);
        if (!in_array($t->status, ['requested', 'confirmed'], true) || strtotime($t->created_at) + self::TOKEN_TTL < current_time('timestamp')) return self::err('tix_token_expired', 'Dieser Link ist abgelaufen oder wurde schon benutzt.', 410);
        if (self::flow_of($t) === 'nopay') { // ohne Zahlungsdaten: E-Mail bestätigt → gleich einrichten (1.38.862)
            $ptok = self::to_queued($t, [], 'customer');
            return self::out(['ok' => true, 'status' => 'queued', 'trial_id' => intval($t->id), 'progress_url' => self::progress_url($t->id, $ptok)]);
        }
        if (self::mollie_key() === '') return self::err('tix_trial_payment_off', 'Die Zahlung ist gerade nicht eingerichtet. Bitte versuche es später noch einmal.', 503);
        if ($t->status === 'requested') $t = self::set($t, 'confirmed', [], null, 'customer');
        if ($t->mollie_customer_id === '') {
            [$st, $c] = self::mollie('POST', '/customers', ['name' => $t->d['contact']['name'] ?? '', 'email' => $t->email, 'metadata' => ['trial_id' => intval($t->id), 'kurz' => $t->kurz]]);
            if ($st !== 201 || empty($c['id'])) return self::err('tix_mollie_unavailable', 'Der Zahlungsanbieter antwortet gerade nicht. Bitte gleich noch einmal.', 502);
            $wpdb->update(self::t(), ['mollie_customer_id' => (string) $c['id']], ['id' => $t->id]);
            $t = self::get($t->id);
        }
        $d = $t->d; $ptok = self::issue_progress($d); // Fortschrittsseite nach der Rückkehr von Mollie
        [$st, $p] = self::mollie('POST', '/payments', ['amount' => ['currency' => 'EUR', 'value' => '0.00'], 'customerId' => $t->mollie_customer_id, 'sequenceType' => 'first',
            'method' => ['creditcard', 'paypal'], 'description' => 'evendis Testphase ' . $t->kurz . ' – Zahlungsmittel hinterlegen (0,00 €)',
            'redirectUrl' => self::progress_url($t->id, $ptok), 'webhookUrl' => rest_url(self::NS . '/public/trial/mollie-webhook'), 'metadata' => ['trial_id' => intval($t->id), 'kurz' => $t->kurz]]);
        $url = (string) ($p['_links']['checkout']['href'] ?? '');
        if ($st !== 201 || $url === '') return self::err('tix_mollie_unavailable', 'Der Zahlungsanbieter antwortet gerade nicht. Bitte gleich noch einmal.', 502);
        $d['payment_id'] = (string) ($p['id'] ?? '');
        $wpdb->update(self::t(), ['data' => wp_json_encode($d), 'updated_at' => self::now()], ['id' => $t->id]);
        return self::out(['ok' => true, 'status' => 'confirmed', 'checkout_url' => $url]);
    }

    public static function rest_webhook(WP_REST_Request $req) {
        $pid = sanitize_text_field((string) ($req->get_param('id') ?? ''));
        if (!preg_match('/^tr_[A-Za-z0-9]+$/', $pid) || self::mollie_key() === '') return self::out(['ok' => true]);
        [$st, $p] = self::mollie('GET', '/payments/' . $pid); // nie dem Inhalt der Anfrage vertrauen
        $tid = intval($p['metadata']['trial_id'] ?? 0);
        $t = $st === 200 && $tid ? self::get($tid) : null;
        if ($t && ($p['metadata']['kind'] ?? '') === 'contract' && ($p['customerId'] ?? '') === $t->mollie_customer_id) { // Jahresbetrag (W2b): Stand der Abbuchung, Rechnung bezahlt
            global $wpdb;
            $d = $t->d; $stt = sanitize_key((string) ($p['status'] ?? ''));
            foreach ((array) ($d['charges'] ?? []) as $i => $c) if (($c['payment_id'] ?? '') === $pid) $d['charges'][$i]['status'] = $stt;
            if (($d['charge']['payment_id'] ?? '') === $pid) $d['charge']['status'] = $stt;
            if ($stt === 'paid' && !empty($d['invoices'])) { $last = count($d['invoices']) - 1; if (empty($d['invoices'][$last]['paid_at'])) $d['invoices'][$last]['paid_at'] = wp_date('c'); }
            if (in_array($stt, ['failed', 'canceled', 'expired'], true)) self::notify_sales('Abbuchung fehlgeschlagen: ' . $t->kurz, ['Firma: ' . $t->company, 'Zahlung ' . $pid . ': ' . $stt]);
            $wpdb->update(self::t(), ['data' => wp_json_encode($d), 'updated_at' => self::now()], ['id' => $t->id]);
            return self::out(['ok' => true]);
        }
        if ($t && ($p['metadata']['kind'] ?? '') === 'activate' && ($p['customerId'] ?? '') === $t->mollie_customer_id) { // „Aktiv halten“ (1.38.862)
            $stt = sanitize_key((string) ($p['status'] ?? ''));
            if (in_array($stt, ['failed', 'canceled', 'expired'], true) && in_array($t->status, ['active', 'paused', 'cancelled'], true)) {
                global $wpdb;
                $d = $t->d; $d['activation'] = array_merge((array) ($d['activation'] ?? []), ['status' => $stt]);
                $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]);
                self::mail($t->email, 'Zahlung hat nicht geklappt', 'Das hat nicht geklappt', ['Die Zahlung für deinen Shop ' . $t->kurz . '.evendis.de wurde nicht abgeschlossen. Es wurde nichts abgebucht. Du kannst es einfach noch einmal versuchen.'],
                    !empty($d['cancel_token']) ? [['Nochmal versuchen', self::page('aktiv', (string) $d['cancel_token'])]] : []);
                return self::out(['ok' => true]);
            }
            $mid = (string) ($p['mandateId'] ?? '');
            if ($stt !== 'paid') return self::out(['ok' => true]);
            $cols = [];
            if ($mid !== '') {
                [$ms, $m] = self::mollie('GET', '/customers/' . $t->mollie_customer_id . '/mandates/' . $mid);
                if ($ms === 200) $cols = ['mollie_mandate_id' => $mid, 'mandate_method' => sanitize_key((string) ($m['method'] ?? '')), 'mandate_status' => sanitize_key((string) ($m['status'] ?? ''))];
            }
            self::activate_paid($t, $p, $cols);
            return self::out(['ok' => true]);
        }
        if (!$t || ($p['customerId'] ?? '') !== $t->mollie_customer_id || !in_array($t->status, ['confirmed', 'payment_failed'], true)) return self::out(['ok' => true]);
        $status = (string) ($p['status'] ?? '');
        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            self::set($t, 'payment_failed', ['kurz' => $t->kurz]);
            self::mail($t->email, 'Zahlungsmittel nicht hinterlegt', 'Das hat nicht geklappt', ['Das Zahlungsmittel für deine Testphase wurde nicht bestätigt. Du kannst es neu versuchen – bestelle die Testphase dafür einfach noch einmal.'], [['Neu versuchen', self::page('neu')]]);
            return self::out(['ok' => true]);
        }
        $mid = (string) ($p['mandateId'] ?? '');
        if ($status !== 'paid' || $mid === '') return self::out(['ok' => true]);
        [$ms, $m] = self::mollie('GET', '/customers/' . $t->mollie_customer_id . '/mandates/' . $mid);
        if ($ms !== 200 || !in_array($m['status'] ?? '', ['valid', 'pending'], true)) return self::out(['ok' => true]);
        $mkey = hash('sha256', (string) ($m['details']['cardFingerprint'] ?? '') . '|' . strtolower((string) ($m['details']['consumerAccount'] ?? '')) . '|' . ($m['method'] ?? ''));
        global $wpdb;
        $dup = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t() . " WHERE id <> %d AND mandate_key = %s AND status IN ('" . implode("','", array_merge(self::ACTIVE_STATES, ['cancelled', 'converted'])) . "')", $t->id, $mkey));
        $cols = ['mollie_mandate_id' => $mid, 'mandate_method' => sanitize_key((string) ($m['method'] ?? '')), 'mandate_status' => sanitize_key((string) ($m['status'] ?? '')), 'mandate_key' => $mkey];
        if ($dup) {
            self::mollie('DELETE', '/customers/' . $t->mollie_customer_id . '/mandates/' . $mid);
            self::set($t, 'rejected', $cols);
            self::mail($t->email, 'Testphase nicht möglich', 'Das geht leider nicht', ['Mit diesem Zahlungsmittel läuft schon eine Testphase. Melde dich gern bei uns, wenn du Fragen hast.']);
            return self::out(['ok' => true]);
        }
        self::to_queued($t, $cols, 'system');
        return self::out(['ok' => true]);
    }

    /** → queued mit Fortschritts-Token und Mail „Dein Testshop wird eingerichtet“; gibt den Token zurück */
    private static function to_queued($t, array $cols, $by) {
        $d = $t->d; $ptok = self::issue_progress($d);
        $t = self::set($t, 'queued', $cols, $d, $by);
        $nopay = self::flow_of($t) === 'nopay';
        self::mail($t->email, 'Dein Testshop wird eingerichtet', $nopay ? 'E-Mail bestätigt' : 'Zahlungsmittel bestätigt',
            [($nopay ? 'Danke! Deine E-Mail-Adresse ist bestätigt.' : 'Danke! Dein Zahlungsmittel ist hinterlegt, abgebucht wurde nichts.') . ' Wir richten jetzt deinen Testshop ' . $t->kurz . '.evendis.de ein – das dauert etwa 10 Minuten.', 'Sobald er fertig ist, bekommst du eine zweite Mail mit deinem Zugang.'],
            [['Fortschritt ansehen', self::progress_url($t->id, $ptok)]]);
        return $ptok;
    }

    // ── nopay: „Aktiv halten“ (1.38.862) ──

    /** POST /public/trial/activate {token} → Mollie-Checkout über den Jahresbetrag (sequenceType first, speichert das Mandat für die Verlängerung) */
    public static function rest_activate(WP_REST_Request $req) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('trial_activate', 20, HOUR_IN_SECONDS)) return self::err('tix_rate_limited', 'Zu viele Anfragen.', 429);
        $t = self::by_token((string) (self::body($req)['token'] ?? ''));
        if (!$t) return self::err('tix_token_unknown', 'Dieser Link ist nicht bekannt.', 404);
        if ($t->status === 'converted') return self::out(['ok' => true, 'status' => 'converted', 'contract_until' => $t->d['contract_until'] ?? null]);
        if (self::flow_of($t) !== 'nopay') return self::err('tix_trial_flow', 'Diese Testphase geht von selbst in den Vertrag über.', 409);
        if (!in_array($t->status, ['active', 'paused', 'cancelled'], true)) return self::err('tix_trial_status', 'In diesem Stand ist „Aktiv halten“ nicht möglich.', 409);
        $price = self::price_of($t->d['tier'] ?? 0);
        if (!$price) return self::err('tix_trial_tier', 'Für dieses Paket gibt es keinen Preis. Bitte melde dich bei uns.', 409);
        $cs = (array) (self::body($req)['consents'] ?? []);
        foreach (array_keys(self::CONSENT_B) as $k) if (empty($cs[$k])) return self::err('tix_invalid_field', 'Bitte bestätigen.', 400, 'consents.' . $k);
        $consent = self::consent_record(self::CONSENT_B, ['[Firma]' => $t->company, '[Paket]' => self::tier_label($t->d['tier'] ?? 0), '[Preis]' => self::money($price['net'])]);
        if (self::mollie_key() === '') return self::err('tix_trial_payment_off', 'Die Zahlung ist gerade nicht eingerichtet. Bitte versuche es später noch einmal.', 503);
        global $wpdb;
        if ($t->mollie_customer_id === '') {
            [$st, $c] = self::mollie('POST', '/customers', ['name' => $t->d['contact']['name'] ?? '', 'email' => $t->email, 'metadata' => ['trial_id' => intval($t->id), 'kurz' => $t->kurz]]);
            if ($st !== 201 || empty($c['id'])) return self::err('tix_mollie_unavailable', 'Der Zahlungsanbieter antwortet gerade nicht. Bitte gleich noch einmal.', 502);
            $wpdb->update(self::t(), ['mollie_customer_id' => (string) $c['id']], ['id' => $t->id]);
            $t = self::get($t->id);
        }
        $d = $t->d; $ptok = self::issue_progress($d);
        [$st, $p] = self::mollie('POST', '/payments', ['amount' => ['currency' => 'EUR', 'value' => number_format($price['gross'], 2, '.', '')], 'customerId' => $t->mollie_customer_id, 'sequenceType' => 'first',
            'method' => ['creditcard', 'paypal'], 'description' => 'evendis Eigener Shop ' . $t->kurz . ' – 1. Vertragsjahr',
            'redirectUrl' => add_query_arg('p', rawurlencode($ptok), self::page('activated', (string) $t->id)), 'webhookUrl' => rest_url(self::NS . '/public/trial/mollie-webhook'),
            'metadata' => ['trial_id' => intval($t->id), 'kind' => 'activate']]);
        $url = (string) ($p['_links']['checkout']['href'] ?? '');
        if ($st !== 201 || $url === '') return self::err('tix_mollie_unavailable', 'Der Zahlungsanbieter antwortet gerade nicht. Bitte gleich noch einmal.', 502);
        $d['activation'] = ['payment_id' => (string) ($p['id'] ?? ''), 'amount' => $price['gross'], 'status' => 'open', 'at' => wp_date('c'), 'consents' => $consent];
        $d['activation_consents'][] = $consent + ['payment_id' => (string) ($p['id'] ?? '')]; // Nachweis jedes Versuchs
        $wpdb->update(self::t(), ['data' => wp_json_encode($d), 'updated_at' => self::now()], ['id' => $t->id]);
        return self::out(['ok' => true, 'checkout_url' => $url, 'amount' => $price, 'tier' => intval($t->d['tier'] ?? 0)]);
    }

    /** Zahlung „Aktiv halten“ eingegangen → Vertrag (converted), aus paused mit Auftrag resume */
    private static function activate_paid($t, array $p, array $cols) {
        if (!in_array($t->status, ['active', 'paused', 'cancelled'], true)) return $t; // schon erledigt (Webhook doppelt) oder gelöscht
        global $wpdb;
        $from = $t->status;
        $d = $t->d;
        $d['activation'] = array_merge((array) ($d['activation'] ?? []), ['status' => 'paid', 'paid_at' => wp_date('c')]);
        foreach (array_reverse((array) ($d['activation_consents'] ?? [])) as $c) if (($c['payment_id'] ?? '') === (string) ($p['id'] ?? '')) { $d['contract_consent'] = $c + ['contract_at' => wp_date('c')]; break; } // Vertrag kommt mit der Zahlung zustande
        $until = (new DateTimeImmutable('now', wp_timezone()))->modify('+1 year');
        $d['contract_until'] = $until->format('c');
        $d['activated_from'] = $from;
        unset($d['paused_at']);
        $pend = array_values(array_diff((array) ($d['server']['pending'] ?? []), ['pause']));
        $d['server']['pending'] = array_values(array_unique(array_merge($pend, $from === 'paused' ? ['resume', 'convert'] : ['convert'])));
        $t = self::set($t, 'converted', $cols + ['converted_at' => self::now(), 'delete_at' => null, 'cancelled_at' => null], $d, 'customer');
        if ($t->license_id) $wpdb->update($wpdb->prefix . 'tix_licenses', ['valid_until' => $until->format('Y-m-d')], ['id' => $t->license_id]);
        $price = self::tier_price($d['tier'] ?? 0);
        $inv = self::invoice_year(self::get($t->id), $price, new DateTimeImmutable('now', wp_timezone()), $until);
        if ($inv) { $t = self::get($t->id); $d = $t->d; $last = count((array) ($d['invoices'] ?? [])) - 1; if ($last >= 0) { $d['invoices'][$last]['paid_at'] = wp_date('c'); $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]); } }
        self::mail($t->email, 'Dein Vertrag hat begonnen', 'Schön, dass du dabei bist',
            ['Danke für deine Zahlung! Dein Shop ' . $t->kurz . '.evendis.de bleibt aktiv' . ($from === 'paused' ? ' und ist in wenigen Minuten wieder online' : '') . '. Dein Vertrag läuft bis ' . $until->format('d.m.Y') . ' und verlängert sich um je ein Jahr, wenn du nicht bis einen Monat vorher kündigst.',
             'Damit deine Kasse echt verkauft, trage im Studio deine Auszahlungsdaten und deine Rechtstexte ein. Die Rechnung bekommst du per Mail.']);
        self::notify_sales('Testshop aktiv gehalten: ' . $t->kurz, ['Firma: ' . $t->company, 'Bezahlt: ' . number_format(floatval($p['amount']['value'] ?? 0), 2, ',', '.') . ' €', 'Vorher: ' . $from, $inv ? 'Rechnung ' . $inv['number'] : 'Rechnung: nicht automatisch – bitte ausstellen.']);
        return self::get($t->id);
    }

    // ── Server: signierte Abholung ──

    public static function perm_signed(WP_REST_Request $req) {
        if (!self::multi()) return new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]);
        $s = self::secret();
        if ($s === '') return self::err('tix_trial_queue_off', 'Die Abholung ist nicht eingerichtet.', 503);
        $ts = intval($req->get_header('x_evendis_ts'));
        $nonce = (string) $req->get_header('x_evendis_nonce');
        $sig = (string) $req->get_header('x_evendis_sig');
        // Pfad wie signiert: /wp-json + Route, Query nach Schlüssel sortiert (RFC 3986)
        if (!$ts || abs(time() - $ts) > self::SKEW || !preg_match('/^[a-f0-9]{16,64}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) return self::err('tix_bad_signature', 'Signatur fehlt oder ist zu alt.', 401);
        $path = '/wp-json' . $req->get_route();
        $q = $req->get_query_params();
        if ($q) { ksort($q); $path .= '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986); }
        if (!hash_equals(self::sig($s, $ts, $nonce, $req->get_method(), $path, $req->get_body()), $sig)) return self::err('tix_bad_signature', 'Signatur falsch.', 401);
        $nk = 'tix_trial_n_' . $nonce;
        if (get_transient($nk)) return self::err('tix_bad_signature', 'Diese Anfrage wurde schon benutzt.', 401);
        set_transient($nk, 1, 10 * MINUTE_IN_SECONDS);
        self::$req_nonce = $nonce;
        return true;
    }

    private static function signed_out(array $data, $status = 200) {
        $body = wp_json_encode($data);
        $ts = time();
        $r = self::out($data, $status);
        $r->header('X-Evendis-Ts', (string) $ts);
        $r->header('X-Evendis-Nonce', self::$req_nonce);
        $r->header('X-Evendis-Sig', self::res_sig(self::secret(), $ts, self::$req_nonce, $body));
        return $r;
    }

    public static function rest_queue(WP_REST_Request $req) {
        global $wpdb;
        self::maybe_install();
        $limit = max(1, min(10, intval($req->get_param('limit') ?: 5)));
        $now = self::now();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id FROM ' . self::t() . " WHERE status IN ('queued','provisioning','deleting') OR (status IN ('cancelled','paused') AND delete_at <= %s) OR (status IN ('active','converted','paused','cancelled') AND data LIKE %s) ORDER BY id ASC LIMIT 50", $now, '%"pending":["%'));
        $jobs = [];
        foreach ($rows as $r) {
            if (count($jobs) >= $limit) break;
            $t = self::get($r->id);
            $job = (array) ($t->d['job'] ?? []);
            $pending = array_values((array) ($t->d['server']['pending'] ?? []));
            if (in_array($t->status, ['queued', 'provisioning'], true)) $action = 'create';
            elseif ($t->status === 'deleting' || (in_array($t->status, ['cancelled', 'paused'], true) && $t->delete_at && $t->delete_at <= $now)) $action = 'delete';
            elseif ($pending) $action = $pending[0]; // extend | convert | pause | resume – Status bleibt
            else continue;
            if (($job['action'] ?? '') === $action && intval($job['until'] ?? 0) > time()) continue; // gesperrt (30 min)
            $d = $t->d;
            if (!empty($job['id']) && ($job['action'] ?? '') === $action) { $jid = $job['id']; $n = max(1, intval($job['n'] ?? 1)); } // Sperre abgelaufen ohne done: derselbe Auftrag noch einmal
            else { // neuer Auftrag (auch nach done/„Neu versuchen“) → neue job_id; der Kunden-Dienst zählt Versuche je job_id (1.38.856)
                $n = self::next_job_seq($d, $job);
                $d['job_seq'] = $n;
                $jid = 'trl-' . $t->id . '-' . $action . '-' . $n;
            }
            $d['job'] = ['id' => $jid, 'action' => $action, 'n' => $n, 'until' => time() + self::JOB_LOCK];
            $lic = null;
            if ($action === 'create') {
                if (!$t->license_id && class_exists('TIX_License_Server')) {
                    $l = TIX_License_Server::create(['customer' => $t->company . ' (Testphase ' . $t->kurz . ')', 'email' => $t->email, 'max_sites' => 1, 'enforce' => true,
                        'valid_until' => wp_date('Y-m-d', time() + (self::DAYS + 1) * DAY_IN_SECONDS), 'note' => 'Testphase ' . $t->kurz . ' (TIX_Trial #' . $t->id . ')']);
                    if ($l) { $wpdb->update(self::t(), ['license_id' => intval($l->id)], ['id' => $t->id]); $t->license_id = intval($l->id); }
                }
                $L = $t->license_id && class_exists('TIX_License_Server') ? TIX_License_Server::get($t->license_id) : null;
                $lic = $L ? ['key' => (string) $L->license_key, 'valid_until' => (string) $L->valid_until] : null;
                if (empty($d['cancel_token'])) { // ein Verwaltungs-Token je Testphase: Kündigen, Starten, Stand (W2b)
                    $d['cancel_token'] = wp_generate_password(40, false, false);
                    $wpdb->update(self::t(), ['cancel_token_hash' => hash('sha256', $d['cancel_token'])], ['id' => $t->id]);
                }
            }
            $t = self::set($t, $action === 'create' ? 'provisioning' : ($action === 'delete' ? 'deleting' : $t->status), [], $d, 'server');
            if (in_array($action, ['extend', 'convert', 'pause', 'resume'], true)) {
                $L = $t->license_id && class_exists('TIX_License_Server') ? TIX_License_Server::get($t->license_id) : null;
                $lic = $L ? ['valid_until' => (string) $L->valid_until] : null; // Schlüssel hat der Server schon
            }
            $jobs[] = array_filter([
                'job_id' => $jid, 'trial_id' => intval($t->id), 'action' => $action, 'kurz' => $t->kurz, 'profile' => 'test', 'license' => $lic,
                'site' => $action === 'create' ? ['name' => $t->d['shop_name'], 'color' => $t->d['color'], 'logo_url' => $t->d['logo_url'], 'noindex' => true, 'layout' => (string) ($t->d['profile'] ?? 'festival')]
                    : ($action === 'convert' ? ['noindex' => false] : null),
                'trial' => in_array($action, ['create', 'extend', 'pause', 'resume'], true) ? array_filter(['ends_at' => $t->ends_at ? (new DateTimeImmutable($t->ends_at, wp_timezone()))->format('c') : wp_date('c', time() + self::DAYS * DAY_IN_SECONDS),
                    'delete_at' => $action === 'pause' && $t->delete_at ? (new DateTimeImmutable($t->delete_at, wp_timezone()))->format('c') : null,
                    'paused' => $action === 'pause' ? true : ($action === 'resume' ? false : null),
                    'price' => self::flow_of($t) === 'nopay' && in_array($action, ['create', 'pause'], true) ? self::price_of($t->d['tier'] ?? 0) : null,
                    'activate_url' => self::flow_of($t) === 'nopay' && !empty($t->d['cancel_token']) && in_array($action, ['create', 'pause'], true) ? self::page('aktiv', (string) $t->d['cancel_token']) : null,
                    // W2b: für das Band im Test-Studio (Kunden-Seite legt es als Option tix_trial_info ab; /me.trial)
                    'cancel_url' => $action === 'create' ? self::page('kuendigen', (string) $t->d['cancel_token']) : null,
                    'start_url' => $action === 'create' && self::flow_of($t) !== 'nopay' ? self::page('starten', (string) $t->d['cancel_token']) : null,
                    'status_url' => $action === 'create' ? add_query_arg('token', rawurlencode((string) $t->d['cancel_token']), rest_url(self::NS . '/public/trial/status')) : null], function ($v) { return $v !== null; }) : null, // false bleibt (paused: false bei resume)
                'owner' => $action === 'create' ? ['name' => $t->d['contact']['name'], 'email' => $t->email, 'company' => $t->company, 'address' => $t->d['address'], 'vat_id' => $t->d['vat_id']] : null,
                'checkout' => $action === 'create' ? ['mode' => 'test'] : ($action === 'convert' ? ['mode' => 'live_allowed'] : null),
                'issued_at' => wp_date('c'), 'expires_at' => wp_date('c', time() + self::JOB_LOCK),
            ], function ($v) { return $v !== null; });
        }
        return self::signed_out(['ok' => true, 'jobs' => $jobs]);
    }

    public static function rest_done(WP_REST_Request $req) {
        $t = self::get(intval($req['id']));
        $b = (array) $req->get_json_params();
        $job = $t ? (array) ($t->d['job'] ?? []) : [];
        $okstate = ['create' => ['provisioning'], 'delete' => ['deleting'], 'extend' => ['active', 'converted', 'cancelled', 'paused'], 'convert' => ['converted', 'active'], 'pause' => ['paused', 'cancelled', 'active'], 'resume' => ['converted', 'active', 'paused', 'cancelled']];
        if (!$t || empty($job['id']) || ($b['job_id'] ?? '') !== $job['id'] || !in_array($t->status, $okstate[$job['action']] ?? [], true)) return self::err('tix_trial_job_mismatch', 'Auftrag passt nicht zum Stand.', 409);
        $d = $t->d; unset($d['job']);
        $ok = !empty($b['ok']);
        if (in_array($job['action'], ['extend', 'convert', 'pause', 'resume'], true)) { // Status bleibt; nur die Server-Aufgabe ist erledigt
            if ($ok) { $d['server']['pending'] = array_values(array_diff((array) ($d['server']['pending'] ?? []), [$job['action']])); unset($d['server']['error']); }
            else { $d['server']['error'] = mb_substr(sanitize_text_field((string) ($b['error'] ?? 'unbekannt')), 0, 500); self::notify_sales('Server-Auftrag ' . $job['action'] . ' fehlgeschlagen: ' . $t->kurz, ['Fehler: ' . $d['server']['error'], 'Der Auftrag wird nach 30 Minuten erneut ausgegeben.']); }
            if (!$ok) $d['job'] = $job; // gesperrt lassen, danach neu ausgeben
            self::set($t, $t->status, [], $d, 'server');
            return self::signed_out(['ok' => true, 'status' => $t->status]);
        }
        if ($job['action'] === 'create') {
            if (!$ok) {
                $d['error'] = mb_substr(sanitize_text_field((string) ($b['error'] ?? 'unbekannt')), 0, 500);
                self::set($t, 'failed', [], $d, 'server');
                self::notify_sales('Testphase fehlgeschlagen: ' . $t->kurz, ['Firma: ' . $t->company, 'Fehler: ' . $d['error'], 'Im Studio unter Vertrieb → Testphasen neu versuchen.']);
                return self::signed_out(['ok' => true, 'status' => 'failed']);
            }
            $now = current_time('timestamp', true);
            $ends = self::ends_at($now, intval($d['extended_days'] ?? 0), wp_timezone());
            $d['url'] = esc_url_raw((string) ($b['url'] ?? 'https://' . $t->kurz . '.evendis.de'));
            $d['studio_url'] = esc_url_raw((string) ($b['studio_url'] ?? ''));
            $invite = preg_match('#^https://#', (string) ($b['invite_url'] ?? '')) ? esc_url_raw((string) $b['invite_url']) : ''; // einmalig, 24 h – nur in die Mail, nie speichern
            $cancel = (string) ($d['cancel_token'] ?? '') ?: wp_generate_password(40, false, false); // Verwaltungs-Token aus der Abholung
            $t = self::set($t, 'active', ['active_at' => wp_date('Y-m-d H:i:s', $now), 'ends_at' => wp_date('Y-m-d H:i:s', $ends), 'cancel_token_hash' => hash('sha256', $cancel)], $d, 'server');
            if ($t->license_id && class_exists('TIX_License_Server')) { global $wpdb; $wpdb->update($wpdb->prefix . 'tix_licenses', ['valid_until' => wp_date('Y-m-d', $ends)], ['id' => $t->license_id]); }
            $d = $t->d; $d['cancel_token'] = $cancel; // Klartext nur für Mails (Ein-Klick-Link), nie in einer API-Antwort
            global $wpdb; $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]);
            self::mail($t->email, 'Dein Shop ist bereit', 'Willkommen bei evendis',
                ['Dein Shop läuft jetzt 14 Tage kostenlos unter ' . $d['url'] . '. Die Kasse ist im Testmodus, es wird nichts echt verkauft.', self::flow_of($t) === 'nopay'
                    ? 'Die Testphase endet am ' . wp_date('d.m.Y', $ends) . '. Nach 14 Tagen wird nichts abgebucht. Es verlängert sich nichts automatisch. Hältst du den Shop nicht aktiv, pausieren wir ihn am ' . wp_date('d.m.Y', $ends + 1) . ' und löschen ihn am ' . wp_date('d.m.Y', $ends + 1 + self::PAUSE_DAYS * DAY_IN_SECONDS) . '. Willst du weitermachen, klickst du auf „Aktiv halten“.'
                    : 'Die Testphase endet am ' . wp_date('d.m.Y', $ends) . '. Danach beginnt dein Vertrag automatisch – außer du kündigst vorher mit einem Klick.'],
                array_values(array_filter([$invite !== '' ? ['Passwort festlegen', $invite] : null, $d['studio_url'] ? ['Zum Studio', $d['studio_url']] : null])), 'account', self::page('kuendigen', $cancel), self::flow_of($t) === 'nopay' ? 'Testshop löschen' : 'Testphase kündigen');
            self::notify_sales('Testphase aktiv: ' . $t->kurz, ['Firma: ' . $t->company, 'Ende: ' . wp_date('d.m.Y', $ends), 'Shop: ' . $d['url']]);
            return self::signed_out(['ok' => true, 'status' => 'active']);
        }
        if (!$ok) { $d['error'] = mb_substr(sanitize_text_field((string) ($b['error'] ?? 'unbekannt')), 0, 500); self::set($t, 'failed', [], $d, 'server'); return self::signed_out(['ok' => true, 'status' => 'failed']); }
        if ($t->mollie_mandate_id !== '' && self::mollie_key() !== '') { self::mollie('DELETE', '/customers/' . $t->mollie_customer_id . '/mandates/' . $t->mollie_mandate_id); self::mollie('DELETE', '/customers/' . $t->mollie_customer_id); }
        unset($d['cancel_token']);
        self::set($t, 'deleted', ['mandate_status' => 'revoked'], $d, 'server');
        self::mail($t->email, 'Dein Testshop wurde gelöscht', 'Alles erledigt', ['Wie angekündigt haben wir deinen Testshop ' . $t->kurz . '.evendis.de mit allen Daten gelöscht' . ($t->mollie_mandate_id !== '' ? ' und das hinterlegte Zahlungsmittel freigegeben' : '') . '. Danke fürs Ausprobieren!']);
        return self::signed_out(['ok' => true, 'status' => 'deleted']);
    }

    // ── Fristen ──

    public static function tick($now = null) {
        if (!self::multi()) return;
        global $wpdb;
        self::maybe_install();
        $now = $now ?? current_time('timestamp', true);
        $ts = function ($mysql) { return $mysql ? (new DateTimeImmutable($mysql, wp_timezone()))->getTimestamp() : 0; };
        // unbestätigte Anfragen (24 h) verfallen, Subdomain wird frei
        foreach ($wpdb->get_col("SELECT id FROM " . self::t() . " WHERE status IN ('requested','confirmed')") as $id) {
            $t = self::get($id);
            if ($ts($t->created_at) + self::TOKEN_TTL + ($t->status === 'confirmed' ? DAY_IN_SECONDS : 0) < $now) self::set($t, 'expired');
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('tix_trial_requests') . ' WHERE expires_at < %s', wp_date('Y-m-d H:i:s', $now - DAY_IN_SECONDS)));
        foreach ($wpdb->get_col("SELECT id FROM " . self::t() . " WHERE status = 'active'") as $id) {
            $t = self::get($id);
            $d = $t->d;
            $ends = $ts($t->ends_at);
            $nopay = self::flow_of($t) === 'nopay';
            foreach (self::reminders_due($ts($t->active_at), array_map('intval', (array) ($d['reminders_sent'] ?? [])), $now) as $day) {
                $left = max(0, (int) ceil(($ends - $now) / DAY_IN_SECONDS));
                if ($nopay) {
                    self::mail($t->email, 'Deine Testphase endet am ' . wp_date('d.m.Y', $ends), 'Willst du weitermachen?',
                        ['Deine Testphase für ' . $t->kurz . '.evendis.de endet am ' . wp_date('d.m.Y', $ends) . ' (noch ' . $left . ' ' . ($left === 1 ? 'Tag' : 'Tage') . ').', 'Es verlängert sich nichts automatisch. Willst du deinen Shop behalten, klickst du auf „Aktiv halten“ und bezahlst das erste Jahr. Sonst pausieren wir den Shop nach Ablauf und löschen ihn 14 Tage später.'],
                        !empty($d['cancel_token']) ? [['Aktiv halten', self::page('aktiv', (string) $d['cancel_token'])]] : [], 'account', !empty($d['cancel_token']) ? self::page('kuendigen', (string) $d['cancel_token']) : '', 'Testshop löschen');
                    $d['reminders_sent'][] = $day;
                    continue;
                }
                self::mail($t->email, 'Deine Testphase endet am ' . wp_date('d.m.Y', $ends), 'Noch ' . $left . ' ' . ($left === 1 ? 'Tag' : 'Tage') . ' Testphase',
                    ['Deine Testphase für ' . $t->kurz . '.evendis.de endet am ' . wp_date('d.m.Y', $ends) . '. Danach beginnt dein Vertrag automatisch.', 'Möchtest du nicht weitermachen, kündige einfach mit einem Klick – dein Shop wird dann nach 3 Tagen gelöscht.'],
                    array_values(array_filter([!empty($d['studio_url']) ? ['Jetzt starten', $d['studio_url']] : null])), 'account', !empty($d['cancel_token']) ? self::page('kuendigen', (string) $d['cancel_token']) : '');
                $d['reminders_sent'][] = $day;
            }
            if (($d['reminders_sent'] ?? []) !== ($t->d['reminders_sent'] ?? [])) { $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]); $t = self::get($t->id); }
            if ($ends && $now > $ends) { if ($nopay) self::pause($t, $now); else self::convert($t, 'system'); }
        }
        // nopay: pausierte Shops – letzte Erinnerung 3 Tage vor dem Löschen (das Löschen selbst holt der Server über die Abholung)
        foreach ($wpdb->get_col("SELECT id FROM " . self::t() . " WHERE status = 'paused'") as $id) {
            $t = self::get($id);
            $del = $ts($t->delete_at);
            if (!$del || !empty($t->d['final_reminder']) || $now < $del - 3 * DAY_IN_SECONDS || $now >= $del) continue;
            $d = $t->d; $d['final_reminder'] = wp_date('c', $now);
            $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]);
            self::mail($t->email, 'Letzte Erinnerung: dein Shop wird am ' . wp_date('d.m.Y', $del) . ' gelöscht', 'Letzte Erinnerung',
                ['Dein pausierter Shop ' . $t->kurz . '.evendis.de wird am ' . wp_date('d.m.Y', $del) . ' mit allen Daten gelöscht.', 'Willst du ihn behalten, klickst du auf „Aktiv halten“ – dann ist er sofort wieder online.'],
                !empty($d['cancel_token']) ? [['Aktiv halten', self::page('aktiv', (string) $d['cancel_token'])]] : []);
        }
        foreach ($wpdb->get_col("SELECT id FROM " . self::t() . " WHERE status = 'converted'") as $id) self::contract_tick(self::get($id), $now);
    }

    /** nopay: Testphase vorbei, nicht aktiv gehalten → pausiert, Löschen nach PAUSE_DAYS, Auftrag pause (1.38.862) */
    public static function pause($t, $now = null) {
        if ($t->status !== 'active' || self::flow_of($t) !== 'nopay') return $t;
        global $wpdb;
        $now = $now ?? time();
        $ends = $t->ends_at ? (new DateTimeImmutable($t->ends_at, wp_timezone()))->getTimestamp() : $now;
        $del = max($ends, $now) + self::PAUSE_DAYS * DAY_IN_SECONDS;
        $d = $t->d;
        $d['paused_at'] = wp_date('c', $now);
        $d['server']['pending'] = array_values(array_unique(array_merge(array_diff((array) ($d['server']['pending'] ?? []), ['resume']), ['pause'])));
        $t = self::set($t, 'paused', ['delete_at' => wp_date('Y-m-d H:i:s', $del)], $d, 'system');
        if ($t->license_id) $wpdb->update($wpdb->prefix . 'tix_licenses', ['valid_until' => wp_date('Y-m-d', $del)], ['id' => $t->license_id]);
        self::mail($t->email, 'Testphase beendet – dein Shop ist pausiert', 'Dein Shop ist pausiert',
            ['Deine 14 Tage sind um. Wir haben deinen Shop ' . $t->kurz . '.evendis.de pausiert: Er ist offline, die Kasse ist aus, dein Studio kannst du weiter ansehen. Abgebucht wurde nichts.', 'Willst du weitermachen, klickst du auf „Aktiv halten“ – dann ist dein Shop sofort wieder da. Sonst löschen wir ihn am ' . wp_date('d.m.Y', $del) . ' mit allen Daten.'],
            !empty($d['cancel_token']) ? [['Aktiv halten', self::page('aktiv', (string) $d['cancel_token'])]] : []);
        self::notify_sales('Testshop pausiert: ' . $t->kurz, ['Firma: ' . $t->company, 'Löschung am ' . wp_date('d.m.Y', $del)]);
        return $t;
    }

    /** Übergang in den Vertrag (Tag 14, nicht gekündigt) */
    public static function convert($t, $by = 'system') {
        if ($t->status !== 'active') return $t;
        global $wpdb;
        $d = $t->d; // Verwaltungs-Token bleibt: Vertragskündigung durch den Kunden (W2b)
        // Vertrag (User 16.11.): 1 Jahr ab Übergang, verlängert sich um je 1 Jahr, Kündigung bis 1 Monat vor Ablauf
        $until = (new DateTimeImmutable('now', wp_timezone()))->modify('+1 year');
        $d['contract_until'] = $until->format('c');
        $d['server']['pending'] = array_values(array_unique(array_merge((array) ($d['server']['pending'] ?? []), ['convert'])));
        $t = self::set($t, 'converted', ['converted_at' => self::now()], $d, $by);
        if ($t->license_id) $wpdb->update($wpdb->prefix . 'tix_licenses', ['valid_until' => $until->format('Y-m-d')], ['id' => $t->license_id]);
        $price = null;
        foreach (self::tiers() as $x) if (intval($x['max']) === intval($d['tier'] ?? 0)) $price = floatval($x['price']);
        $charge = self::charge_year($t, $price, '1. Vertragsjahr');
        $inv = self::invoice_year(self::get($t->id), $price, new DateTimeImmutable('now', wp_timezone()), $until);
        self::mail($t->email, 'Dein Vertrag hat begonnen', 'Schön, dass du dabei bist',
            ['Deine Testphase ist vorbei, dein Vertrag für ' . $t->kurz . '.evendis.de hat begonnen' . ($price ? ' (' . number_format($price, 0, ',', '.') . ' € im Jahr zzgl. MwSt.)' : '') . '.',
             'Damit deine Kasse echt verkauft, trage im Studio deine Auszahlungsdaten und deine Rechtstexte ein. Die Rechnung bekommst du per Mail.']);
        self::notify_sales('Testphase in Vertrag übergegangen: ' . $t->kurz, ['Firma: ' . $t->company, 'Stufe: bis ' . number_format(intval($d['tier'] ?? 0), 0, ',', '.') . ' Tickets' . ($price ? ', ' . number_format($price, 0, ',', '.') . ' €/Jahr' : ''),
            $charge ? 'Abbuchung: ' . $charge['status'] . ' (' . number_format($charge['amount'], 2, ',', '.') . ' €)' : 'Abbuchung: nicht automatisch (Schalter aus) – bitte auslösen.',
            $inv ? 'Rechnung ' . $inv['number'] . ' verschickt.' : 'Rechnung: nicht automatisch (Schalter tix_trial_invoice aus bzw. Rechnungsangaben unvollständig) – bitte ausstellen.']);
        return self::get($t->id);
    }

    /** Jahresbetrag im Voraus über das Mandat (nur mit Schalter tix_trial_auto_charge = 1, Kasse/Geld per Button) */
    private static function charge_year($t, $price, $label) {
        if (get_option('tix_trial_auto_charge', '0') !== '1' || !$price || $t->mandate_status !== 'valid' || self::mollie_key() === '') return null;
        global $wpdb;
        $gross = round($price * 1.19, 2);
        [$st, $p] = self::mollie('POST', '/payments', ['amount' => ['currency' => 'EUR', 'value' => number_format($gross, 2, '.', '')], 'customerId' => $t->mollie_customer_id, 'mandateId' => $t->mollie_mandate_id,
            'sequenceType' => 'recurring', 'description' => 'evendis Eigener Shop ' . $t->kurz . ' – ' . $label, 'metadata' => ['trial_id' => intval($t->id), 'kind' => 'contract']]);
        $charge = ['status' => $st === 201 ? (string) ($p['status'] ?? 'open') : 'error', 'payment_id' => (string) ($p['id'] ?? ''), 'amount' => $gross, 'label' => $label, 'at' => wp_date('c')];
        $t = self::get($t->id); $d = $t->d; $d['charge'] = $charge; $d['charges'][] = $charge;
        $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]);
        return $charge;
    }

    private static function tier_price($tier) {
        foreach (self::tiers() as $x) if (intval($x['max']) === intval($tier)) return floatval($x['price']);
        return null;
    }

    /** Vertrag läuft ab: gekündigt → Löschauftrag (Schluss-Sicherung macht der Server), sonst +1 Jahr */
    public static function contract_tick($t, $now) {
        if ($t->status !== 'converted' || empty($t->d['contract_until'])) return;
        $until = new DateTimeImmutable($t->d['contract_until']);
        if ($now < $until->getTimestamp()) return;
        global $wpdb;
        $d = $t->d;
        if (!empty($d['contract_end'])) {
            self::set($t, 'cancelled', ['cancelled_at' => self::now(), 'delete_at' => self::now()], $d, 'system');
            self::mail($t->email, 'Dein Vertrag ist beendet', 'Vertrag beendet', ['Dein Vertrag für ' . $t->kurz . '.evendis.de ist wie gekündigt beendet. Wir löschen den Shop jetzt; eine Sicherung bewahren wir noch 30 Tage auf.']);
            return;
        }
        $next = $until->setTimezone(wp_timezone())->modify('+1 year');
        $d['contract_until'] = $next->format('c');
        self::set($t, 'converted', [], $d, 'system');
        if ($t->license_id) $wpdb->update($wpdb->prefix . 'tix_licenses', ['valid_until' => $next->format('Y-m-d')], ['id' => $t->license_id]);
        $price = self::tier_price($d['tier'] ?? 0);
        $charge = self::charge_year(self::get($t->id), $price, 'Vertragsjahr bis ' . $next->format('d.m.Y'));
        $inv = self::invoice_year(self::get($t->id), $price, $until->setTimezone(wp_timezone()), $next);
        self::mail($t->email, 'Dein Vertrag wurde verlängert', 'Ein weiteres Jahr', ['Dein Vertrag für ' . $t->kurz . '.evendis.de läuft jetzt bis ' . $next->format('d.m.Y') . '.' . ($price ? ' Jahresbetrag: ' . number_format($price, 0, ',', '.') . ' € zzgl. MwSt.' : '')]);
        if (!$charge || !$inv) self::notify_sales('Vertrag verlängert: ' . $t->kurz, ['Bis ' . $next->format('d.m.Y'), $charge ? 'Abbuchung: ' . $charge['status'] : 'Abbuchung: nicht automatisch – bitte auslösen.', $inv ? 'Rechnung ' . $inv['number'] : 'Rechnung: nicht automatisch – bitte ausstellen.']);
    }

    public static function rest_contract_cancel(WP_REST_Request $req) {
        $t = self::load($req); if (is_wp_error($t)) return $t;
        return self::contract_cancel($t, 'user:' . get_current_user_id());
    }

    /** Vertrag kündigen (bis 1 Monat vor Ablauf) – Vertrieb oder Kunde (Link mit Verwaltungs-Token) */
    private static function contract_cancel($t, $by) {
        if ($t->status !== 'converted' || empty($t->d['contract_until'])) return self::err('tix_trial_status', 'Nur laufende Verträge lassen sich kündigen.', 409);
        $until = new DateTimeImmutable($t->d['contract_until']);
        if (!empty($t->d['contract_end'])) return self::out(['ok' => true, 'status' => 'converted', 'contract_end' => $t->d['contract_end'], 'trial' => self::view($t)]); // schon gekündigt
        if (time() > $until->modify('-1 month')->getTimestamp()) return self::err('tix_trial_notice', 'Die Kündigungsfrist (1 Monat vor Ablauf) ist vorbei. Der Vertrag verlängert sich um ein Jahr.', 409, null, ['contract_until' => $t->d['contract_until']]);
        $d = $t->d; $d['contract_end'] = $t->d['contract_until'];
        $t = self::set($t, 'converted', [], $d, $by);
        self::mail($t->email, 'Deine Kündigung', 'Kündigung bestätigt', ['Dein Vertrag für ' . $t->kurz . '.evendis.de endet am ' . $until->setTimezone(wp_timezone())->format('d.m.Y') . '. Bis dahin läuft dein Shop normal weiter.']);
        self::notify_sales('Vertrag gekündigt: ' . $t->kurz, ['Firma: ' . $t->company, 'Ende: ' . $until->setTimezone(wp_timezone())->format('d.m.Y'), 'Durch: ' . $by]);
        return self::out(['ok' => true, 'status' => 'converted', 'contract_end' => $d['contract_end'], 'trial' => self::view($t)]);
    }

    // ── W2c: Ticketzahl je Vertrag und anteiliges Hochstufen ──

    /** Lizenzprüfung einer Kunden-Seite: gemeldete Tickets im Vertragsjahr speichern und ggf. hochstufen */
    public static function on_license_checked($lic, $req) {
        if (!self::multi() || !$lic) return;
        $count = $req->get_param('tickets_count');
        $since = (string) $req->get_param('tickets_since');
        if ($count === null || $count === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) return;
        global $wpdb;
        self::maybe_install();
        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t() . " WHERE license_id = %d AND status = 'converted' ORDER BY id DESC LIMIT 1", intval($lic->id)));
        if (!$id) return;
        self::record_usage(self::get($id), max(0, intval($count)), $since, time());
    }

    /** Zählung speichern; Paket überschritten → sofort hochstufen (anteilig), über der Staffel → Anfrage */
    public static function record_usage($t, $count, $since, $now) {
        if (!$t || $t->status !== 'converted' || empty($t->d['contract_until'])) return $t;
        global $wpdb;
        $d = $t->d;
        $d['usage'] = ['tickets' => $count, 'since' => $since, 'at' => wp_date('c', $now)];
        $tier = intval($d['tier'] ?? 0);
        $until = (new DateTimeImmutable($d['contract_until']))->getTimestamp();
        $from = (new DateTimeImmutable($d['contract_until']))->modify('-1 year')->getTimestamp();
        $q = self::upgrade_quote($count, $tier, self::tiers(), $from, $until, $now);
        if (!$q) { $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]); return self::get($t->id); }
        if ($q['on_request']) {
            if (empty($d['usage_on_request'])) {
                $d['usage_on_request'] = wp_date('c', $now);
                self::mail($t->email, 'Mehr Tickets als in deinem Paket', 'Glückwunsch zu so vielen Tickets', ['Du hast in diesem Vertragsjahr schon ' . number_format($count, 0, ',', '.') . ' Tickets verkauft – mehr als unsere größte Paket-Stufe. Wir melden uns mit einem individuellen Angebot.']);
                self::notify_sales('Paket überschritten (auf Anfrage): ' . $t->kurz, ['Firma: ' . $t->company, 'Tickets im Vertragsjahr: ' . $count, 'Größte Stufe überschritten – individuelles Angebot nötig.']);
            }
            $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]);
            return self::get($t->id);
        }
        $old = $tier;
        $d['tier'] = $q['to_tier'];
        $up = ['at' => wp_date('c', $now), 'from_tier' => $old, 'to_tier' => $q['to_tier'], 'tickets' => $count, 'diff_year' => $q['diff_year'], 'remaining_days' => $q['remaining_days'],
            'total_days' => $q['total_days'], 'amount_net' => $q['amount_net'], 'charge' => null, 'invoice' => null];
        $d['upgrades'][] = $up;
        $t = self::set($t, 'converted', [], $d, 'system');
        $idx = count($d['upgrades']) - 1;
        // Rechnung (Schalter tix_trial_invoice) und Abbuchung (eigener Schalter tix_trial_upgrade_charge, Kasse/Geld per Button)
        $inv = $q['amount_net'] > 0 ? self::issue_invoice($t, $q['amount_net'], 'Hochstufung Eigener Shop ' . $t->kurz . '.evendis.de von ' . number_format($old, 0, ',', '.') . ' auf ' . number_format($q['to_tier'], 0, ',', '.')
            . ' Tickets im Jahr, anteilig ' . $q['remaining_days'] . ' von ' . $q['total_days'] . ' Tagen', new DateTimeImmutable('@' . $now), new DateTimeImmutable('@' . $until)) : null;
        $charge = null;
        if ($q['amount_net'] > 0 && get_option('tix_trial_upgrade_charge', '0') === '1' && $t->mandate_status === 'valid' && self::mollie_key() !== '') {
            $gross = round($q['amount_net'] * 1.19, 2);
            [$st, $p] = self::mollie('POST', '/payments', ['amount' => ['currency' => 'EUR', 'value' => number_format($gross, 2, '.', '')], 'customerId' => $t->mollie_customer_id, 'mandateId' => $t->mollie_mandate_id,
                'sequenceType' => 'recurring', 'description' => 'evendis Hochstufung ' . $t->kurz . ' auf ' . $q['to_tier'] . ' Tickets (anteilig)', 'metadata' => ['trial_id' => intval($t->id), 'kind' => 'contract']]);
            $charge = ['status' => $st === 201 ? (string) ($p['status'] ?? 'open') : 'error', 'payment_id' => (string) ($p['id'] ?? ''), 'amount' => $gross, 'label' => 'Hochstufung', 'at' => wp_date('c', $now)];
        }
        $t = self::get($t->id); $d = $t->d;
        $d['upgrades'][$idx]['invoice'] = $inv ? $inv['number'] : null;
        $d['upgrades'][$idx]['charge'] = $charge;
        if ($charge) $d['charges'][] = $charge;
        $wpdb->update(self::t(), ['data' => wp_json_encode($d)], ['id' => $t->id]);
        $newp = self::tier_price($q['to_tier']);
        self::mail($t->email, 'Dein Paket wurde hochgestuft', 'Mehr Tickets – größeres Paket', [
            'Du hast in diesem Vertragsjahr schon ' . number_format($count, 0, ',', '.') . ' Tickets verkauft – mehr als in deinem Paket (bis ' . number_format($old, 0, ',', '.') . ' Tickets). Wir haben dein Paket deshalb auf bis ' . number_format($q['to_tier'], 0, ',', '.') . ' Tickets hochgestuft' . ($newp ? ' (' . number_format($newp, 0, ',', '.') . ' € im Jahr zzgl. MwSt.)' : '') . '.',
            'Für die restlichen ' . $q['remaining_days'] . ' Tage dieses Vertragsjahrs berechnen wir nur die Differenz anteilig: ' . number_format($q['amount_net'], 2, ',', '.') . ' € zzgl. MwSt.' . ($charge ? ' Der Betrag wird über dein hinterlegtes Zahlungsmittel abgebucht.' : '')]);
        self::notify_sales('Paket hochgestuft: ' . $t->kurz, ['Firma: ' . $t->company, 'Tickets im Vertragsjahr: ' . $count, 'Stufe ' . $old . ' → ' . $q['to_tier'] . ', anteilig ' . number_format($q['amount_net'], 2, ',', '.') . ' € netto (' . $q['remaining_days'] . '/' . $q['total_days'] . ' Tage)',
            $inv ? 'Rechnung ' . $inv['number'] : 'Rechnung: nicht automatisch – bitte ausstellen.', $charge ? 'Abbuchung: ' . $charge['status'] : 'Abbuchung: nicht automatisch (Schalter tix_trial_upgrade_charge aus) – bitte auslösen.']);
        return self::get($t->id);
    }

    // ── Rechnung je Vertragsjahr (W2b, nur mit Schalter tix_trial_invoice = 1) ──

    /** Rechnung festschreiben (Nummernkreis VT im Zähler der Rechnungen, Aussteller „site“), per Mail mit PDF → Beleg | null */
    public static function invoice_year($t, $price, DateTimeImmutable $from, DateTimeImmutable $to) {
        if (!$price) return null;
        $d = $t->d;
        return self::issue_invoice($t, round(floatval($price), 2), 'Eigener Shop ' . $t->kurz . '.evendis.de – Paket bis ' . number_format(intval($d['tier'] ?? 0), 0, ',', '.') . ' Tickets im Jahr, Vertragsjahr ' . $from->setTimezone(wp_timezone())->format('d.m.Y') . ' – ' . $to->setTimezone(wp_timezone())->modify('-1 day')->format('d.m.Y'), $from, $to);
    }

    /** Beleg festschreiben (Serie VT, Aussteller site) und per Mail mit PDF schicken → Beleg | null */
    public static function issue_invoice($t, $net, $text, DateTimeImmutable $from, DateTimeImmutable $to) {
        if (get_option('tix_trial_invoice', '0') !== '1' || !$net || !class_exists('TIX_Invoices')) return null;
        $from = $from->setTimezone(wp_timezone()); $to = $to->setTimezone(wp_timezone());
        $price = $net;
        $iss = TIX_Invoices::issuer('site');
        if (empty($iss['complete'])) return null; // Angaben unvollständig → Vertrieb stellt aus
        global $wpdb;
        $d = $t->d;
        $country = strtoupper((string) ($d['address']['country'] ?? 'DE'));
        $rc = $country !== 'DE' && (string) ($d['vat_id'] ?? '') !== ''; // Leistungsempfänger im EU-Ausland mit USt-IdNr.
        $rate = $rc ? 0.0 : floatval($iss['tax_rate'] ?? 19);
        $net = round(floatval($price), 2);
        $vat = round($net * $rate / 100, 2);
        $doc = ['number' => '', 'date' => current_time('Y-m-d'), 'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->modify('-1 day')->format('Y-m-d')],
            'issuer' => ['name' => (string) $iss['name'], 'lines' => array_values((array) $iss['address_lines']), 'vat_id' => (string) $iss['vat_id'], 'tax_id' => (string) $iss['tax_id']],
            'customer' => ['company' => $t->company, 'name' => (string) ($d['contact']['name'] ?? ''), 'address' => $d['address'] ?? [], 'vat_id' => (string) ($d['vat_id'] ?? ''), 'email' => $t->email],
            'lines' => [['text' => $text, 'net' => $net]],
            'net' => $net, 'vat_rate' => $rate, 'vat' => $vat, 'gross' => round($net + $vat, 2), 'reverse_charge' => $rc, 'paid_at' => null];
        $c = TIX_Invoices::counters_table();
        $y = intval(current_time('Y'));
        $code = (string) ($iss['code'] ?? '');
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $c (issuer_key, series, year, last) VALUES ('site', 'VT', %d, 0)", $y));
        $last = $wpdb->get_var($wpdb->prepare("SELECT last FROM $c WHERE issuer_key = 'site' AND series = 'VT' AND year = %d FOR UPDATE", $y));
        if ($last === null) { $wpdb->query('ROLLBACK'); return null; }
        $seq = intval($last) + 1;
        $doc['number'] = 'RV-' . ($code !== '' ? $code . '-' : '') . $y . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        $t2 = self::get($t->id); $d2 = $t2->d; $d2['invoices'][] = $doc;
        $ok1 = $wpdb->query($wpdb->prepare("UPDATE $c SET last = %d WHERE issuer_key = 'site' AND series = 'VT' AND year = %d", $seq, $y));
        $ok2 = $wpdb->update(self::t(), ['data' => wp_json_encode($d2), 'updated_at' => self::now()], ['id' => $t->id]);
        if ($ok1 === false || $ok2 === false) { $wpdb->query('ROLLBACK'); return null; }
        $wpdb->query('COMMIT');
        // Mail mit PDF
        $file = trailingslashit(get_temp_dir()) . 'Rechnung-' . $doc['number'] . '.pdf';
        $bytes = self::invoice_pdf($doc);
        if ($bytes) file_put_contents($file, $bytes);
        $h = '<p>' . esc_html('anbei deine Rechnung ' . $doc['number'] . ' über ' . number_format($doc['gross'], 2, ',', '.') . ' € für den Zeitraum ' . $from->format('d.m.Y') . ' – ' . $to->modify('-1 day')->format('d.m.Y') . '.') . '</p>'
            . '<p>' . esc_html($t->mandate_status === 'valid' ? 'Der Betrag wird über dein hinterlegtes Zahlungsmittel abgebucht.' : 'Bitte überweise den Betrag innerhalb von 14 Tagen.') . '</p>';
        if (class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($t->email, 'Rechnung ' . $doc['number'], ['title' => 'Deine Rechnung', 'blocks' => [['type' => 'html', 'html' => $h]], 'kind' => 'account'], ['customer' => false, 'attachments' => $bytes ? [$file] : []]);
        else wp_mail($t->email, 'Rechnung ' . $doc['number'], $h, ['Content-Type: text/html; charset=UTF-8'], $bytes ? [$file] : []);
        if ($bytes) @unlink($file);
        return $doc;
    }

    /** PDF aus dem festgeschriebenen Beleg (schlicht, wie die Gutschrift) */
    public static function invoice_pdf(array $d) {
        if (!class_exists('TIX_Simple_PDF') && defined('TIXOMAT_PATH')) require_once TIXOMAT_PATH . 'includes/class-tix-event-report.php';
        if (!class_exists('TIX_PDFA') && !class_exists('TIX_Simple_PDF')) return null;
        $W = 595; $H = 842; $M = 50;
        $pdf = class_exists('TIX_PDFA') && TIX_PDFA::available() ? new TIX_PDFA($W, $H, $M, 40) : new TIX_Simple_PDF($W, $H, $M, 40);
        if (method_exists($pdf, 'set_meta')) $pdf->set_meta(['title' => 'Rechnung ' . $d['number'], 'author' => (string) $d['issuer']['name']]);
        $eur = function ($v) { return number_format(floatval($v), 2, ',', '.') . ' EUR'; };
        $rt = function ($x, $y, $s, $size) use ($pdf) { $w = method_exists($pdf, 'width') ? $pdf->width($s, $size) : strlen($s) * $size * 0.5; $pdf->text(round($x - $w, 2), $y, $s); };
        $pdf->add_page();
        $y = $H - 60;
        $pdf->set_color(0, 0, 0);
        $pdf->set_font('Helvetica', 9); $pdf->text($M, $y, trim($d['issuer']['name'] . ' · ' . implode(' · ', $d['issuer']['lines']), ' ·')); $y -= 30;
        $pdf->set_font('Helvetica', 10);
        $a = (array) $d['customer']['address'];
        foreach (array_filter([$d['customer']['company'], $d['customer']['name'], $a['street'] ?? '', trim(($a['zip'] ?? '') . ' ' . ($a['city'] ?? '')), ($a['country'] ?? 'DE') !== 'DE' ? ($a['country'] ?? '') : '']) as $l) { $pdf->text($M, $y, $l); $y -= 13; }
        if ($d['customer']['vat_id'] !== '') { $pdf->text($M, $y, 'USt-IdNr.: ' . $d['customer']['vat_id']); $y -= 13; }
        $y -= 20;
        $pdf->set_font('Helvetica-Bold', 16); $pdf->text($M, $y, 'Rechnung ' . $d['number']); $y -= 20;
        $pdf->set_font('Helvetica', 10);
        $pdf->text($M, $y, 'Rechnungsdatum: ' . wp_date('d.m.Y', strtotime($d['date'] . ' 12:00:00')) . '   ·   Leistungszeitraum: ' . wp_date('d.m.Y', strtotime($d['period']['from'] . ' 12:00:00')) . ' – ' . wp_date('d.m.Y', strtotime($d['period']['to'] . ' 12:00:00'))); $y -= 26;
        $pdf->set_font('Helvetica-Bold', 9); $pdf->text($M, $y, 'Leistung'); $rt($W - $M, $y, 'Betrag netto', 9); $y -= 14;
        $pdf->set_font('Helvetica', 9);
        foreach ($d['lines'] as $l) { foreach (explode("
", wordwrap($l['text'], 80, "
", true)) as $k => $tl) { $pdf->text($M, $y, $tl); if ($k === 0) $rt($W - $M, $y, $eur($l['net']), 9); $y -= 12; } }
        $y -= 8;
        $pdf->set_font('Helvetica', 10);
        $pdf->text(330, $y, 'Summe netto'); $rt($W - $M, $y, $eur($d['net']), 10); $y -= 14;
        if (empty($d['reverse_charge'])) { $pdf->text(330, $y, 'zzgl. USt ' . rtrim(rtrim(number_format($d['vat_rate'], 1, ',', ''), '0'), ',') . ' %'); $rt($W - $M, $y, $eur($d['vat']), 10); $y -= 14; }
        $pdf->set_font('Helvetica-Bold', 11); $pdf->text(330, $y, 'Rechnungsbetrag'); $rt($W - $M, $y, $eur($d['gross']), 11); $y -= 30;
        $pdf->set_font('Helvetica', 9);
        $notes = [];
        if (!empty($d['reverse_charge'])) $notes[] = 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge). USt-IdNr. des Leistungsempfängers: ' . $d['customer']['vat_id'] . '.';
        $notes[] = 'Jahresbetrag im Voraus. Aussteller: ' . $d['issuer']['name'] . ($d['issuer']['vat_id'] !== '' ? ' · USt-IdNr. ' . $d['issuer']['vat_id'] : ($d['issuer']['tax_id'] !== '' ? ' · St.-Nr. ' . $d['issuer']['tax_id'] : ''));
        foreach ($notes as $n) foreach (explode("
", wordwrap($n, 105, "
", true)) as $l) { $pdf->text($M, $y, $l); $y -= 12; }
        return $pdf->output();
    }

    public static function rest_invoice_pdf(WP_REST_Request $req) {
        $t = self::load($req); if (is_wp_error($t)) return $t;
        $doc = ((array) ($t->d['invoices'] ?? []))[intval($req['n'])] ?? null;
        if (!$doc) return self::err('tix_trial_invoice_unknown', 'Rechnung nicht gefunden.', 404);
        $b = self::invoice_pdf($doc);
        if (!$b) return self::err('tix_pdf_unavailable', 'PDF gerade nicht möglich.', 500);
        return self::out(['ok' => true, 'filename' => 'Rechnung-' . $doc['number'] . '.pdf', 'mime' => 'application/pdf', 'number' => $doc['number'], 'paid_at' => $doc['paid_at'], 'data' => base64_encode($b)]);
    }

    // ── Kündigen ──

    private static function do_cancel($t, $by) {
        if ($t->status === 'cancelled') return self::out(['ok' => true, 'status' => 'cancelled', 'delete_at' => wp_date('c', strtotime($t->delete_at))]);
        if ($t->status === 'converted') return self::err('tix_trial_converted', 'Die Testphase ist schon in den Vertrag übergegangen. Die Kündigung richtet sich nach dem Vertrag.', 409);
        if (!in_array($t->status, ['active', 'queued', 'provisioning', 'failed', 'paused'], true)) return self::err('tix_trial_status', 'In diesem Stand ist keine Kündigung möglich.', 409);
        $del = time() + self::DELETE_AFTER_DAYS * DAY_IN_SECONDS;
        if ($t->status === 'paused' && $t->delete_at) $del = min($del, (new DateTimeImmutable($t->delete_at, wp_timezone()))->getTimestamp());
        $d = $t->d; unset($d['job']);
        if (self::flow_of($t) !== 'nopay') unset($d['cancel_token']); // nopay: „Aktiv halten“ geht bis zum Löschen
        $t = self::set($t, 'cancelled', ['cancelled_at' => self::now(), 'delete_at' => wp_date('Y-m-d H:i:s', $del, wp_timezone())], $d, $by);
        if (self::flow_of($t) === 'nopay') self::mail($t->email, 'Dein Testshop wird gelöscht', 'Löschung bestätigt', ['Wie gewünscht löschen wir deinen Testshop ' . $t->kurz . '.evendis.de am ' . wp_date('d.m.Y', $del) . ' mit allen Daten. Es wird nichts abgebucht.']);
        else self::mail($t->email, 'Deine Testphase ist gekündigt', 'Gekündigt', ['Deine Testphase für ' . $t->kurz . '.evendis.de ist gekündigt. Am ' . wp_date('d.m.Y', $del) . ' löschen wir den Shop mit allen Daten und geben das Zahlungsmittel frei. Es wird nichts abgebucht.']);
        self::notify_sales('Testphase gekündigt: ' . $t->kurz, ['Firma: ' . $t->company, 'Löschung am ' . wp_date('d.m.Y', $del)]);
        return self::out(['ok' => true, 'status' => 'cancelled', 'delete_at' => wp_date('c', $del)]);
    }

    public static function rest_cancel_token(WP_REST_Request $req) {
        $token = (string) (self::body($req)['token'] ?? '');
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('trial_cancel', 20, HOUR_IN_SECONDS)) return self::err('tix_rate_limited', 'Zu viele Anfragen.', 429);
        global $wpdb;
        self::maybe_install();
        $id = $token !== '' ? $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t() . ' WHERE cancel_token_hash = %s', hash('sha256', $token))) : null;
        if (!$id) return self::err('tix_token_unknown', 'Dieser Link ist nicht bekannt.', 404);
        $t = self::get($id);
        if ($t->status === 'converted') return self::contract_cancel($t, 'customer'); // W2b: derselbe Link kündigt den Vertrag (Frist 1 Monat)
        return self::do_cancel($t, 'customer');
    }

    private static function by_token($token) {
        global $wpdb;
        self::maybe_install();
        $id = $token !== '' ? $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t() . ' WHERE cancel_token_hash = %s', hash('sha256', (string) $token))) : null;
        return $id ? self::get($id) : null;
    }

    /**
     * POST /trial/{id}/progress {job_id, step, index, total} (signiert wie /done): Zwischenstand der Einrichtung.
     * Nur für den laufenden create-Auftrag (sonst 409). Schreibt per Vergleich-und-Tausch, damit ein gleichzeitiges /done nie überschrieben wird.
     */
    public static function rest_progress(WP_REST_Request $req) {
        global $wpdb;
        $t = self::get(intval($req['id']));
        $b = (array) $req->get_json_params();
        $job = $t ? (array) ($t->d['job'] ?? []) : [];
        if (!$t || $t->status !== 'provisioning' || ($job['action'] ?? '') !== 'create' || empty($job['id']) || ($b['job_id'] ?? '') !== $job['id']) return self::err('tix_trial_job_mismatch', 'Auftrag passt nicht zum Stand.', 409);
        $step = substr(sanitize_key((string) ($b['step'] ?? '')), 0, 40);
        $total = intval($b['total'] ?? 0); $index = intval($b['index'] ?? -1);
        if ($step === '' || $total < 1 || $total > 100 || $index < 0 || $index > $total) return self::err('tix_invalid_field', 'step, index (0 … total) und total (1 … 100) angeben.', 400);
        $d = $t->d;
        $d['progress'] = ['job_id' => $job['id'], 'step' => $step, 'index' => $index, 'total' => $total, 'at' => wp_date('c')];
        $d['progress_stage'] = max(intval($d['progress_stage'] ?? 0), intval(self::PROGRESS_STEPS[$step] ?? 0)); // Stufe fällt nie zurück, unbekannter Schritt behält sie
        $d['progress_pct'] = max(intval($d['progress_pct'] ?? 0), min(99, (int) floor($index * 100 / $total))); // 100 erst bei active
        $n = $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET data = %s WHERE id = %d AND status = 'provisioning' AND data = %s", wp_json_encode($d), $t->id, $t->data));
        return self::signed_out(['ok' => true, 'stored' => $n === 1]);
    }

    /** GET /public/trial/progress?p= → Fortschrittsseite (keine Personendaten, no-store) */
    public static function rest_progress_public(WP_REST_Request $req) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('trial_progress', 900, HOUR_IN_SECONDS)) return self::err('tix_rate_limited', 'Zu viele Anfragen.', 429, null, ['retry_after' => 60]);
        $tok = (string) $req->get_param('p');
        global $wpdb;
        self::maybe_install();
        $h = preg_match('/^[A-Za-z0-9]{20,64}$/', $tok) ? hash('sha256', $tok) : '';
        $t = null;
        if ($h !== '') foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::t() . ' WHERE data LIKE %s ORDER BY id DESC LIMIT 3', '%' . $wpdb->esc_like('"h":"' . $h . '"') . '%')) as $id) {
            $x = self::get($id);
            foreach ((array) ($x->d['progress_tokens'] ?? []) as $pt) if (hash_equals((string) ($pt['h'] ?? ''), $h) && intval($pt['exp'] ?? 0) > time()) { $t = $x; break 2; }
        }
        if (!$t) return self::err('tix_token_unknown', 'Dieser Link ist nicht bekannt oder abgelaufen.', 404);
        return self::out(self::progress_view($t->status, $t->d));
    }

    /** GET /public/trial/status?token= → Stand fürs Band (keine Personendaten) */
    public static function rest_status(WP_REST_Request $req) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('trial_status', 120, HOUR_IN_SECONDS)) return self::err('tix_rate_limited', 'Zu viele Anfragen.', 429);
        $t = self::by_token((string) $req->get_param('token'));
        if (!$t) return self::err('tix_token_unknown', 'Nicht bekannt.', 404);
        $tz = wp_timezone();
        $iso = function ($v) use ($tz) { return $v ? (new DateTimeImmutable($v, $tz))->format('c') : null; };
        $ends = $t->ends_at ? new DateTimeImmutable($t->ends_at, $tz) : null;
        $cu = $t->d['contract_until'] ?? null;
        $out = ['ok' => true, 'status' => $t->status, 'phase' => in_array($t->status, ['converted'], true) ? 'contract' : 'trial',
            'ends_at' => $ends ? $ends->format('c') : null, 'days_left' => $ends && $t->status === 'active' ? max(0, (int) floor(($ends->getTimestamp() - time()) / DAY_IN_SECONDS)) : null,
            'delete_at' => $iso($t->delete_at), 'contract_until' => $cu, 'contract_end' => $t->d['contract_end'] ?? null,
            'cancel_deadline' => $cu ? (new DateTimeImmutable($cu))->modify('-1 month')->format('c') : null,
            'can_cancel' => $t->status === 'active' || ($t->status === 'converted' && empty($t->d['contract_end']) && $cu && time() <= (new DateTimeImmutable($cu))->modify('-1 month')->getTimestamp()),
            'can_start' => $t->status === 'active'];
        // 1.38.862: Ablauf ohne Zahlungsdaten
        $nopay = self::flow_of($t) === 'nopay';
        $out['flow'] = $nopay ? 'nopay' : 'mandate';
        $out['auto_renew_trial'] = !$nopay;
        if ($t->status === 'paused') $out['phase'] = 'paused';
        elseif (in_array($t->status, ['cancelled', 'deleting', 'deleted'], true)) $out['phase'] = 'deleting';
        if ($nopay) {
            $out['can_start'] = false;
            $out['can_cancel'] = in_array($t->status, ['active', 'paused'], true);
            $out['can_activate'] = in_array($t->status, ['active', 'paused', 'cancelled'], true);
            $out['activate_url'] = $out['can_activate'] ? self::page('aktiv', (string) $req->get_param('token')) : null;
            $out['price'] = self::price_of($t->d['tier'] ?? 0);
            $out['paused_at'] = $t->d['paused_at'] ?? null;
        } else { $out['can_activate'] = false; $out['activate_url'] = null; }
        $r = self::out($out);
        $r->header('Cache-Control', 'private, max-age=60');
        return $r;
    }

    /** POST /public/trial/start {token} → „Jetzt starten“: Vertrag beginnt sofort */
    public static function rest_start(WP_REST_Request $req) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('trial_start', 20, HOUR_IN_SECONDS)) return self::err('tix_rate_limited', 'Zu viele Anfragen.', 429);
        $t = self::by_token((string) (self::body($req)['token'] ?? ''));
        if (!$t) return self::err('tix_token_unknown', 'Dieser Link ist nicht bekannt.', 404);
        if ($t->status === 'converted') return self::out(['ok' => true, 'status' => 'converted', 'contract_until' => $t->d['contract_until'] ?? null]);
        if (self::flow_of($t) === 'nopay') return self::err('tix_trial_flow', 'Zum Weitermachen bitte „Aktiv halten“ nutzen.', 409);
        if ($t->status !== 'active') return self::err('tix_trial_status', 'In diesem Stand ist kein Start möglich.', 409);
        $t = self::convert($t, 'customer');
        return self::out(['ok' => true, 'status' => $t->status, 'contract_until' => $t->d['contract_until'] ?? null]);
    }

    // ── Studio ──

    private static function view($t) {
        $tz = wp_timezone();
        $ends = $t->ends_at ? (new DateTimeImmutable($t->ends_at, $tz)) : null;
        return ['id' => intval($t->id), 'kurz' => $t->kurz, 'status' => $t->status, 'company' => $t->company, 'contact_name' => (string) ($t->d['contact']['name'] ?? ''), 'email' => $t->email,
            'tier' => intval($t->d['tier'] ?? 0), 'active_at' => $t->active_at ? (new DateTimeImmutable($t->active_at, $tz))->format('c') : null, 'ends_at' => $ends ? $ends->format('c') : null,
            'days_left' => $ends && $t->status === 'active' ? max(0, (int) floor(($ends->getTimestamp() - time()) / DAY_IN_SECONDS)) : null, // volle Tage; 0 = letzter Tag
            'cancelled_at' => $t->cancelled_at ? (new DateTimeImmutable($t->cancelled_at, $tz))->format('c') : null, 'delete_at' => $t->delete_at ? (new DateTimeImmutable($t->delete_at, $tz))->format('c') : null,
            'flow' => self::flow_of($t), 'paused_at' => $t->d['paused_at'] ?? null, 'activation' => $t->d['activation'] ?? null,
            'url' => (string) ($t->d['url'] ?? ''), 'extended_days' => intval($t->d['extended_days'] ?? 0), 'error' => (string) ($t->d['error'] ?? ''),
            'mandate' => ['method' => $t->mandate_method ?: null, 'status' => $t->mandate_status ?: null], 'charge' => $t->d['charge'] ?? null,
            'contract_until' => $t->d['contract_until'] ?? null, 'contract_end' => $t->d['contract_end'] ?? null,
            'invoices' => array_map(function ($i) { return ['number' => $i['number'], 'date' => $i['date'], 'gross' => $i['gross'], 'paid_at' => $i['paid_at'] ?? null]; }, array_values((array) ($t->d['invoices'] ?? []))),
            'usage' => $t->d['usage'] ?? null, 'upgrades' => array_values((array) ($t->d['upgrades'] ?? [])), 'usage_on_request' => $t->d['usage_on_request'] ?? null, 'server_pending' => array_values((array) ($t->d['server']['pending'] ?? [])), 'server_error' => $t->d['server']['error'] ?? null, 'created_at' => (new DateTimeImmutable($t->created_at, $tz))->format('c')];
    }

    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        self::maybe_install();
        $per = max(1, min(100, intval($req->get_param('per_page') ?: 20)));
        $page = max(1, intval($req->get_param('page') ?: 1));
        $st = sanitize_key((string) $req->get_param('status'));
        $where = $st !== '' ? $wpdb->prepare('WHERE status = %s', $st) : '';
        $total = intval($wpdb->get_var('SELECT COUNT(*) FROM ' . self::t() . " $where"));
        $ids = $wpdb->get_col('SELECT id FROM ' . self::t() . " $where ORDER BY created_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
        return self::out(['ok' => true, 'trials' => array_map(function ($id) { return self::view(self::get($id)); }, $ids), 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    private static function load(WP_REST_Request $req) {
        $t = self::get(intval($req['id']));
        return $t ?: self::err('tix_trial_not_found', 'Testphase nicht gefunden.', 404);
    }

    public static function rest_extend(WP_REST_Request $req) {
        $t = self::load($req); if (is_wp_error($t)) return $t;
        $days = intval((self::body($req)['days'] ?? 0));
        if ($days < 1 || $days > 14) return self::err('tix_invalid_field', '1 bis 14 Tage.', 400, 'days');
        if (!empty($t->d['extended_days'])) return self::err('tix_trial_extended', 'Die Testphase wurde schon verlängert.', 409);
        if (!in_array($t->status, ['active', 'paused'], true)) return self::err('tix_trial_status', 'Nur laufende oder pausierte Testphasen lassen sich verlängern.', 409);
        $d = $t->d; $d['extended_days'] = $days;
        $base = new DateTimeImmutable($t->ends_at, wp_timezone());
        if ($t->status === 'paused') { $now = new DateTimeImmutable('now', wp_timezone()); if ($base < $now) $base = $now->setTime(23, 59, 59); } // aus der Pause: ab heute
        $ends = $base->modify("+$days days");
        $add = $t->status === 'paused' ? ['resume', 'extend'] : ['extend'];
        $d['server']['pending'] = array_values(array_unique(array_merge(array_diff((array) ($d['server']['pending'] ?? []), $t->status === 'paused' ? ['pause'] : []), $add)));
        unset($d['paused_at'], $d['final_reminder']);
        $t = self::set($t, 'active', ['ends_at' => $ends->format('Y-m-d H:i:s'), 'delete_at' => null], $d, 'user:' . get_current_user_id());
        if ($t->license_id) { global $wpdb; $wpdb->update($wpdb->prefix . 'tix_licenses', ['valid_until' => $ends->format('Y-m-d')], ['id' => $t->license_id]); }
        return self::out(['ok' => true, 'trial' => self::view($t)]);
    }

    public static function rest_convert(WP_REST_Request $req) {
        $t = self::load($req); if (is_wp_error($t)) return $t;
        if ($t->status !== 'active') return self::err('tix_trial_status', 'Nur laufende Testphasen lassen sich überführen.', 409);
        return self::out(['ok' => true, 'trial' => self::view(self::convert($t, 'user:' . get_current_user_id()))]);
    }

    /**
     * Öffentliche Sicht der Fortschrittsseite (rein, ohne Personendaten). phase: wartet | einrichtung | fertig | fehler | beendet.
     * percent fällt nie zurück (progress_pct); bei „Neu versuchen“ bleibt er stehen und retrying ist true.
     */
    public static function progress_view($status, array $d) {
        $phase = in_array($status, ['requested', 'confirmed'], true) ? 'wartet' : (in_array($status, ['queued', 'provisioning'], true) ? 'einrichtung'
            : (in_array($status, ['active', 'converted'], true) ? 'fertig' : (in_array($status, ['failed', 'payment_failed', 'rejected'], true) ? 'fehler' : 'beendet')));
        $pr = (array) ($d['progress'] ?? []);
        $stage = null; $label = '';
        $nopay = (string) ($d['flow'] ?? '') === 'nopay';
        if ($status === 'paused') $phase = 'pausiert';
        if ($phase === 'wartet') $label = $nopay ? 'E-Mail wird bestätigt' : 'Zahlungsmittel wird bestätigt';
        elseif ($phase === 'pausiert') $label = 'Dein Shop ist pausiert – aktiv halten, damit er weiterläuft.';
        elseif ($phase === 'einrichtung') { // nie zurück: max(gespeicherte Stufe, Stufe des letzten Schritts, Grundstufe)
            $stage = min(6, max($status === 'provisioning' ? 2 : 1, intval($d['progress_stage'] ?? 0), $status === 'provisioning' ? intval(self::PROGRESS_STEPS[$pr['step'] ?? ''] ?? 0) : 0));
            $label = $stage === 1 && $nopay ? 'E-Mail bestätigt' : self::PROGRESS_STAGES[$stage];
        }
        elseif ($phase === 'fertig') { $stage = 7; $label = 'Dein Shop ist fertig'; }
        elseif ($phase === 'fehler') $label = $status === 'failed' ? 'Bei der Einrichtung hakt es – wir kümmern uns und melden uns per Mail.' : 'Das Zahlungsmittel wurde nicht bestätigt.';
        else $label = 'Diese Testphase ist beendet.';
        $out = ['ok' => true, 'status' => $status, 'phase' => $phase, 'stage' => $stage, 'step_label' => $label,
            'index' => $status === 'provisioning' && isset($pr['index']) ? intval($pr['index']) : null, 'total' => $status === 'provisioning' && isset($pr['total']) ? intval($pr['total']) : null,
            'percent' => $phase === 'fertig' ? 100 : ($phase === 'einrichtung' || $phase === 'fehler' ? intval($d['progress_pct'] ?? 0) : 0),
            'retrying' => $phase === 'einrichtung' && !empty($d['retried']),
            'stages' => array_values(array_slice(array_replace(self::PROGRESS_STAGES, $nopay ? [1 => 'E-Mail bestätigt'] : []), 0, 6))];
        if ($phase === 'pausiert') $out['activate'] = true;
        if (!empty($d['activation']['status'])) $out['activation'] = (string) $d['activation']['status']; // Seite /testen/aktiviert/: open | paid | failed | canceled | expired
        if ($phase === 'fertig') { $out['shop_url'] = (string) ($d['url'] ?? ''); $out['studio_url'] = (string) ($d['studio_url'] ?? ''); }
        return $out;
    }

    /** neuer Fortschritts-Token (zufällig, nur Hash gespeichert, 48 h); höchstens 5 gültige je Testphase */
    private static function issue_progress(array &$d) {
        $tok = wp_generate_password(40, false, false);
        $list = array_values(array_filter((array) ($d['progress_tokens'] ?? []), function ($x) { return intval($x['exp'] ?? 0) > time(); }));
        $list[] = ['h' => hash('sha256', $tok), 'exp' => time() + self::PROGRESS_TTL];
        $d['progress_tokens'] = array_slice($list, -5);
        return $tok;
    }

    /** Mail-Link nach außen (Tests, Homepages-Abgleich) */
    public static function page_url($kind, $token = '') { return self::page($kind, $token); }

    private static function progress_url($id, $tok) {
        return add_query_arg('p', rawurlencode($tok), self::page('mandate', (string) $id));
    }

    /** Laufende Nummer der Server-Aufträge je Testphase; done/retry/cancel löschen sie nie. Ältere Einträge ohne job_seq: über schon ausgegebene Aufträge (Verlauf) hinaus zählen. */
    public static function next_job_seq(array $d, array $job = []) {
        $base = intval($d['job_seq'] ?? 0);
        if (!isset($d['job_seq'])) foreach ((array) ($d['history'] ?? []) as $h) if (in_array($h['to'] ?? '', ['provisioning', 'deleting'], true)) $base++;
        return max($base, intval($job['n'] ?? 0)) + 1;
    }

    public static function rest_retry(WP_REST_Request $req) {
        $t = self::load($req); if (is_wp_error($t)) return $t;
        if ($t->status !== 'failed') return self::err('tix_trial_status', 'Nur fehlgeschlagene Testphasen lassen sich neu versuchen.', 409);
        $d = $t->d; unset($d['error'], $d['job']);
        if (!$t->active_at) $d['retried'] = true; // Fortschrittsseite: „Wir versuchen es noch einmal“, Balken bleibt stehen
        return self::out(['ok' => true, 'trial' => self::view(self::set($t, $t->active_at ? 'cancelled' : 'queued', [], $d, 'user:' . get_current_user_id()))]);
    }

    public static function rest_cancel(WP_REST_Request $req) {
        $t = self::load($req); if (is_wp_error($t)) return $t;
        return self::do_cancel($t, 'user:' . get_current_user_id());
    }

    public static function rest_delete(WP_REST_Request $req) {
        $t = self::load($req); if (is_wp_error($t)) return $t;
        if (in_array($t->status, ['deleted', 'deleting', 'converted'], true)) return self::err('tix_trial_status', 'In diesem Stand nicht möglich.', 409);
        if (in_array($t->status, ['requested', 'confirmed', 'payment_failed', 'expired', 'rejected'], true) || !$t->active_at && $t->status !== 'provisioning') return self::out(['ok' => true, 'trial' => self::view(self::set($t, 'deleted', [], null, 'user:' . get_current_user_id()))]);
        $d = $t->d; unset($d['cancel_token'], $d['job']);
        return self::out(['ok' => true, 'trial' => self::view(self::set($t, 'cancelled', ['cancelled_at' => self::now(), 'delete_at' => self::now()], $d, 'user:' . get_current_user_id()))]);
    }
}

TIX_Trial::init();

/**
 * TIX_Trial_Site – Test-Seite eines Kunden während der Testphase (Server-Lücke 4, wie TIX_Demo_Shop):
 * Mit Option tix_trial_instance = 1 (setzt der Server beim Einrichten, mit tix_trial_until) kein echtes Geld:
 * Live-Schlüssel werden beim Lesen und Speichern entfernt, Mollie/Stripe im Testmodus, PayPal Sandbox, Überweisung aus;
 * POST /studio/payments mit Live-Schlüssel → 400 tix_trial_live_key. Nach dem Auftrag „convert“ setzt der Server die Option auf 0.
 */
class TIX_Trial_Site {
    const LIVE_KEYS = ['stripe_secret_key_live', 'stripe_publishable_key_live', 'stripe_webhook_secret_live'];

    public static function init() {
        add_filter('option_tix_settings', [__CLASS__, 'filter_settings'], 998);
        add_filter('pre_update_option_tix_settings', [__CLASS__, 'filter_settings'], 998);
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard'], 4, 3); // vor dem Demo-Shop (5): auf Test-Seiten gilt die Testphasen-Meldung
    }

    public static function on() {
        return (string) get_option('tix_trial_instance', '0') === '1' && !TIX_Trial::multi();
    }

    public static function filter_settings($s) {
        if (!is_array($s) || !self::on()) return $s;
        if (preg_match('/^live_/i', trim((string) ($s['mollie_api_key'] ?? '')))) $s['mollie_api_key'] = '';
        foreach (self::LIVE_KEYS as $k) if (isset($s[$k]) && $s[$k] !== '') $s[$k] = '';
        foreach (['stripe_secret_key_test', 'stripe_publishable_key_test'] as $k) if (isset($s[$k]) && preg_match('/_live_/i', (string) $s[$k])) $s[$k] = '';
        $s['stripe_test_mode'] = 1;
        $s['mollie_test_mode'] = 1;
        $s['paypal_sandbox'] = 1;
        $s['bank_transfer_enabled'] = 0;
        return $s;
    }

    /**
     * /me.trial für das Band im Test-Studio (Format des Studios: {ends_at, days_left, cancel_url, start_url, status, delete_at}).
     * Quelle: Option tix_trial_info (vom Server aus dem Auftrag create: ends_at, cancel_url, start_url, status_url), Stand live von der
     * Plattform (status_url, 5 min zwischengespeichert). Nur Inhaber/Admin; sonst null.
     */
    public static function me_payload($user) {
        $info = get_option('tix_trial_info', null);
        if (is_string($info)) $info = json_decode($info, true);
        if (!is_array($info) || empty($info['ends_at']) || TIX_Trial::multi()) return null;
        if (!$user || !($user->has_cap('manage_options') || in_array('tix_organizer', (array) $user->roles, true))) return null;
        $https = function ($u) { $u = (string) $u; return preg_match('#^https://#', $u) ? esc_url_raw($u) : ''; };
        $out = ['ends_at' => (string) $info['ends_at'], 'days_left' => null, 'cancel_url' => $https($info['cancel_url'] ?? ''), 'start_url' => $https($info['start_url'] ?? ''), 'status' => !empty($info['paused']) ? 'paused' : 'active', 'delete_at' => $info['delete_at'] ?? null,
            'contract_until' => null, 'contract_end' => null, 'cancel_deadline' => null, 'can_cancel' => true, 'can_start' => ($info['start_url'] ?? '') !== '',
            // 1.38.862 (ohne Zahlungsdaten): „Aktiv halten“ statt „Jetzt starten“
            'activate_url' => $https($info['activate_url'] ?? ''), 'can_activate' => ($info['activate_url'] ?? '') !== '', 'flow' => ($info['activate_url'] ?? '') !== '' ? 'nopay' : 'mandate', 'phase' => !empty($info['paused']) ? 'paused' : 'trial', 'price' => is_array($info['price'] ?? null) ? $info['price'] : null, 'auto_renew_trial' => ($info['activate_url'] ?? '') === ''];
        $url = $https($info['status_url'] ?? '');
        if ($url !== '') {
            $key = 'tix_trial_status_' . md5($url);
            $st = get_transient($key);
            if (!is_array($st)) {
                $r = wp_remote_get($url, ['timeout' => 5]);
                $st = !is_wp_error($r) && intval(wp_remote_retrieve_response_code($r)) === 200 ? (json_decode((string) wp_remote_retrieve_body($r), true) ?: []) : [];
                set_transient($key, $st, 5 * MINUTE_IN_SECONDS);
            }
            foreach (['status', 'phase', 'ends_at', 'days_left', 'delete_at', 'contract_until', 'contract_end', 'cancel_deadline', 'can_cancel', 'can_start', 'can_activate', 'activate_url', 'flow', 'price', 'auto_renew_trial'] as $k) if (array_key_exists($k, $st) && $st[$k] !== null) $out[$k] = $st[$k];
        }
        if ($out['days_left'] === null) { $e = strtotime($out['ends_at']); $out['days_left'] = $e ? max(0, (int) floor(($e - time()) / DAY_IN_SECONDS)) : null; }
        return $out;
    }

    /**
     * W2c: verkaufte Tickets im laufenden Vertragsjahr für die Lizenzprüfung → ['tickets_count', 'tickets_since'] | []
     * Vertragsjahr = Laufzeitende der Lizenz − 1 Jahr. Gezählt: bezahlte Tickets valid/used (ohne storniert/weiterverkauft).
     * Freitickets zählen nie (User-Entscheid 16.11.). Nicht während der Testphase.
     */
    public static function usage_params() {
        if (TIX_Trial::multi() || self::on() || !class_exists('TIX_License')) return [];
        $exp = (string) (TIX_License::status()['expires_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $exp)) return [];
        $since = (new DateTimeImmutable(substr($exp, 0, 10), wp_timezone()))->modify('-1 year')->format('Y-m-d');
        return ['tickets_count' => self::tickets_since($since), 'tickets_since' => $since];
    }

    public static function tickets_since($since) {
        global $wpdb;
        if (!class_exists('TIX_Ticket_DB') || !TIX_Ticket_DB::table_exists()) return 0;
        $t = $wpdb->prefix . TIX_Ticket_DB::TABLE_SUFFIX; // {prefix}tixomat_tickets
        $o = $wpdb->prefix . 'tix_orders';
        return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t t LEFT JOIN $o o ON o.id = t.order_id WHERE t.created_at >= %s AND t.ticket_status IN ('valid','used') AND t.ticket_price > 0 AND (o.payment_method IS NULL OR o.payment_method <> 'demo')", $since . ' 00:00:00'))); // nur bezahlte Tickets, nie Demodaten (1.38.860)
    }

    public static function guard($result, $server, $request) {
        if ($result !== null || !self::on() || $request->get_method() !== 'POST' || $request->get_route() !== '/tixomat/v1/studio/payments') return $result;
        $b = (array) $request->get_json_params();
        $until = (string) get_option('tix_trial_until', '');
        $msg = 'In der Testphase' . ($until !== '' ? ' (bis ' . wp_date('d.m.Y', strtotime($until)) . ')' : '') . ' nur Test-Schlüssel – echtes Geld ist erst mit Vertragsbeginn möglich.';
        $err = function ($field) use ($msg) { return new WP_Error('tix_trial_live_key', $msg, ['status' => 400, 'field' => $field]); };
        if (is_array($b['mollie'] ?? null)) {
            if (preg_match('/^live_/i', trim((string) ($b['mollie']['api_key'] ?? '')))) return $err('mollie.api_key');
            if (array_key_exists('test_mode', $b['mollie']) && empty($b['mollie']['test_mode'])) return $err('mollie.test_mode');
        }
        if (is_array($b['stripe'] ?? null)) {
            foreach (['secret_live', 'publishable_live', 'webhook_secret_live'] as $f) if (array_key_exists($f, $b['stripe']) && trim((string) $b['stripe'][$f]) !== '') return $err('stripe.' . $f);
            foreach (['secret_test', 'publishable_test'] as $f) if (preg_match('/_live_/i', (string) ($b['stripe'][$f] ?? ''))) return $err('stripe.' . $f);
            if (array_key_exists('test_mode', $b['stripe']) && empty($b['stripe']['test_mode'])) return $err('stripe.test_mode');
        }
        if (is_array($b['paypal'] ?? null) && array_key_exists('sandbox', $b['paypal']) && empty($b['paypal']['sandbox'])) return $err('paypal.sandbox');
        if (is_array($b['bank'] ?? null) && !empty($b['bank']['enabled'])) return $err('bank.enabled');
        return $result;
    }
}

TIX_Trial_Site::init();
