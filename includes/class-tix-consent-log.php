<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Consent_Log – Nachweis der Einwilligung im Cookie-Banner (Statistik Stufe 3a, Plan §5; Art. 7 Abs. 1 DSGVO).
 *
 *   POST /public/consent {id: UUID der Einwilligung (CookieConsent consentId), action: accept|reject|change,
 *                         categories: [necessary, analytics, marketing], revision, host} → 204
 * Gespeichert: Einwilligungs-ID, Kategorien, Revision der Anbieterliste, Aktion, Host, Zeit – keine IP, kein Browser.
 * Mit der ID (steht im Cookie des Besuchers) lässt sich eine Einwilligung belegen bzw. bei Widerruf nachvollziehen.
 * Aufbewahrung 3 Jahre (Verjährung), danach automatisch gelöscht. Lesen: nur Admin (WP-CLI/Abfrage), keine Studio-Liste.
 */
class TIX_Consent_Log {

    const DB_VERSION = '1';
    const KEEP_DAYS = 1100;
    const CATS = ['necessary', 'analytics', 'marketing'];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_create'], 7);
        add_action('rest_api_init', function () {
            register_rest_route('tixomat/v1', '/public/consent', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest'], 'permission_callback' => '__return_true']);
        });
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'tix_consent_log'; }

    public static function maybe_create() {
        if (get_option('tix_consent_log_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            consent_id CHAR(36) NOT NULL,
            action VARCHAR(10) NOT NULL,
            categories VARCHAR(100) NOT NULL DEFAULT '',
            revision INT NOT NULL DEFAULT 0,
            host VARCHAR(120) NOT NULL DEFAULT '',
            created DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (id),
            KEY consent_id (consent_id),
            KEY created (created)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_consent_log_db', self::DB_VERSION, true);
    }

    public static function rest(WP_REST_Request $req) {
        global $wpdb;
        $done = new WP_REST_Response(null, 204);
        $id = strtolower((string) $req->get_param('id'));
        $action = (string) $req->get_param('action');
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $id) || !in_array($action, ['accept', 'reject', 'change'], true)) return $done;
        // Fluten bremsen: höchstens 600 Einträge je Minute für die ganze Seite
        $k = 'tix_consent_rate_' . gmdate('YmdHi');
        $n = (int) get_transient($k);
        if ($n >= 600) return $done;
        set_transient($k, $n + 1, 120);
        $cats = array_values(array_intersect(self::CATS, array_map('strval', (array) $req->get_param('categories'))));
        if (!in_array('necessary', $cats, true)) array_unshift($cats, 'necessary');
        $host = strtolower(preg_replace('/[^A-Za-z0-9.-]/', '', (string) $req->get_param('host')));
        self::maybe_create();
        $wpdb->insert(self::table(), ['consent_id' => $id, 'action' => $action, 'categories' => implode(',', $cats), 'revision' => max(0, intval($req->get_param('revision'))),
            'host' => mb_substr($host, 0, 120), 'created' => current_time('mysql', true)]);
        if (mt_rand(1, 200) === 1) $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " WHERE created < %s", gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS)));
        return $done;
    }

    /** Nachweis zu einer Einwilligungs-ID (Admin, z. B. per WP-CLI) */
    public static function history($id) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare("SELECT action, categories, revision, host, created FROM " . self::table() . " WHERE consent_id = %s ORDER BY id", strtolower((string) $id)), ARRAY_A);
    }
}

TIX_Consent_Log::init();
