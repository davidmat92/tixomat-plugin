<?php
/**
 * TIX_Site_Analytics — Site-weites Pageview-Tracking + Analytics-Tab
 *
 * Erweitert das bestehende Campaign-Tracking um:
 * - Pageviews auf JEDER Frontend-Seite (nicht nur Events)
 * - Unique Visitors via localStorage-ID
 * - Sessions via Cookie (30min Idle-Timeout)
 * - Top-Pages Statistik
 * - Geräte-Breakdown (Mobile/Tablet/Desktop)
 * - Time-Series der Pageviews
 * - Conversion-Rate site-wide (Visits → Orders)
 *
 * Eigene Tabelle wp_tix_site_views (anders als wp_tix_campaign_views,
 * die für aggregierte Event-Pageviews bleibt).
 *
 * Settings unter Tixomat → Einstellungen → Tracking.
 *
 * @since 1.38.143
 */
if (!defined('ABSPATH')) exit;

class TIX_Site_Analytics {

    const TABLE_SUFFIX  = 'tix_site_views';
    const SESSION_COOKIE = 'tix_session';
    const RATE_LIMIT_MAX = 200; // pro IP pro Minute

    public static function init() {
        // DB-Tabelle anlegen / migrieren
        add_action('init', [__CLASS__, 'maybe_create_table'], 1);

        // AJAX (logged-in + nopriv)
        add_action('wp_ajax_tix_track_pageview',        [__CLASS__, 'ajax_track_pageview']);
        add_action('wp_ajax_nopriv_tix_track_pageview', [__CLASS__, 'ajax_track_pageview']);

        // Cleanup-Cron
        add_action('tix_site_views_cleanup', [__CLASS__, 'cleanup']);
        if (!wp_next_scheduled('tix_site_views_cleanup')) {
            wp_schedule_event(time() + 3600, 'daily', 'tix_site_views_cleanup');
        }
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function maybe_create_table() {
        if (get_option('tix_site_views_schema_v1')) return;

        global $wpdb;
        $t = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id VARCHAR(40) NOT NULL DEFAULT '',
            session_id VARCHAR(40) NOT NULL DEFAULT '',
            page_path VARCHAR(500) NOT NULL DEFAULT '',
            page_title VARCHAR(255) NOT NULL DEFAULT '',
            referrer_host VARCHAR(255) NOT NULL DEFAULT '',
            source VARCHAR(50) NOT NULL DEFAULT 'direct',
            campaign VARCHAR(100) NOT NULL DEFAULT '',
            content VARCHAR(100) NOT NULL DEFAULT '',
            device_type VARCHAR(20) NOT NULL DEFAULT '',
            is_first_visit TINYINT(1) NOT NULL DEFAULT 0,
            is_session_start TINYINT(1) NOT NULL DEFAULT 0,
            view_date DATE NOT NULL,
            view_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY view_date (view_date),
            KEY source (source),
            KEY visitor_id (visitor_id),
            KEY session_id (session_id),
            KEY page_path (page_path(191)),
            KEY device_type (device_type)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('tix_site_views_schema_v1', current_time('c'), false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AJAX: Pageview empfangen
    // ══════════════════════════════════════════════════════════════════════

    public static function ajax_track_pageview() {
        // Nonce optional — Pixel ist nopriv und Anti-Spam läuft via Rate-Limit
        check_ajax_referer('tix_campaign', 'nonce');

        // Rate-Limit (pro IP)
        if (!self::check_rate_limit()) {
            wp_send_json_error('rate_limited');
        }

        $visitor_id = sanitize_text_field(substr($_POST['visitor_id'] ?? '', 0, 40));
        $session_id = sanitize_text_field(substr($_POST['session_id'] ?? '', 0, 40));
        $page_path  = self::sanitize_path($_POST['page_path'] ?? '');
        $page_title = sanitize_text_field(substr(wp_unslash($_POST['page_title'] ?? ''), 0, 255));
        $source     = sanitize_key(substr($_POST['source'] ?? 'direct', 0, 50));
        $campaign   = sanitize_text_field(substr($_POST['campaign'] ?? '', 0, 100));
        $content    = sanitize_text_field(substr($_POST['content'] ?? '', 0, 100));
        $referrer   = self::sanitize_host($_POST['referrer_host'] ?? '');
        $device     = self::detect_device($_SERVER['HTTP_USER_AGENT'] ?? '');
        $first_visit = !empty($_POST['is_first_visit']) ? 1 : 0;
        $session_start = !empty($_POST['is_session_start']) ? 1 : 0;

        if (!$visitor_id || !$session_id || !$page_path) {
            wp_send_json_error('invalid');
        }

        global $wpdb;
        $wpdb->insert(self::table_name(), [
            'visitor_id'        => $visitor_id,
            'session_id'        => $session_id,
            'page_path'         => $page_path,
            'page_title'        => $page_title,
            'referrer_host'     => $referrer,
            'source'            => $source,
            'campaign'          => $campaign,
            'content'           => $content,
            'device_type'       => $device,
            'is_first_visit'    => $first_visit,
            'is_session_start'  => $session_start,
            'view_date'         => current_time('Y-m-d'),
            'view_time'         => current_time('mysql'),
        ]);

        wp_send_json_success();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    private static function sanitize_path($path) {
        $p = sanitize_text_field(wp_unslash($path));
        $p = substr($p, 0, 500);
        // Query-String abschneiden außer wir wollen UTM-Tags? Nein —
        // UTM-Tags fließen separat als source/campaign/content. Path bleibt sauber.
        $q = strpos($p, '?');
        if ($q !== false) $p = substr($p, 0, $q);
        // Hash-Fragmente raus
        $h = strpos($p, '#');
        if ($h !== false) $p = substr($p, 0, $h);
        return $p;
    }

    private static function sanitize_host($url) {
        $url = sanitize_text_field(wp_unslash($url));
        if (!$url) return '';
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) $host = $url; // Falls schon nur Host übergeben wurde
        $host = strtolower(preg_replace('/^www\./', '', (string) $host));
        return substr($host, 0, 255);
    }

    private static function detect_device($ua) {
        if (!$ua) return 'unknown';
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua)) return 'tablet';
        if (preg_match('/Mobile|iPhone|Android|webOS|Opera Mini|IEMobile/i', $ua)) return 'mobile';
        return 'desktop';
    }

    private static function check_rate_limit() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = 'tix_rl_pv_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_MAX) return false;
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    public static function cleanup() {
        global $wpdb;
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $days = max(30, intval($s['site_views_retention_days'] ?? 90));
        $t = self::table_name();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE view_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
            $days
        ));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Queries für Analytics-Tab
    // ══════════════════════════════════════════════════════════════════════

    /**
     * KPIs für den gewählten Zeitraum.
     */
    public static function get_kpis($date_from = '', $date_to = '') {
        global $wpdb;
        $t = self::table_name();
        $where = ['1=1'];
        $args = [];
        if ($date_from) { $where[] = 'view_date >= %s'; $args[] = $date_from; }
        if ($date_to)   { $where[] = 'view_date <= %s'; $args[] = $date_to; }
        $where_sql = implode(' AND ', $where);

        $base = "FROM $t WHERE $where_sql";
        $prep = function($sql) use ($args, $wpdb) {
            return $args ? $wpdb->prepare($sql, ...$args) : $sql;
        };

        $views    = (int) $wpdb->get_var($prep("SELECT COUNT(*) $base"));
        $uniques  = (int) $wpdb->get_var($prep("SELECT COUNT(DISTINCT visitor_id) $base"));
        $sessions = (int) $wpdb->get_var($prep("SELECT COUNT(DISTINCT session_id) $base"));
        $first    = (int) $wpdb->get_var($prep("SELECT COUNT(*) $base AND is_first_visit = 1"));

        $views_per_session = $sessions > 0 ? round($views / $sessions, 2) : 0;

        return [
            'views'             => $views,
            'unique_visitors'   => $uniques,
            'sessions'          => $sessions,
            'first_visits'      => $first,
            'views_per_session' => $views_per_session,
        ];
    }

    public static function get_channels($date_from = '', $date_to = '') {
        global $wpdb;
        $t = self::table_name();
        $where = ['1=1'];
        $args = [];
        if ($date_from) { $where[] = 'view_date >= %s'; $args[] = $date_from; }
        if ($date_to)   { $where[] = 'view_date <= %s'; $args[] = $date_to; }
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT source, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS uniques, COUNT(DISTINCT session_id) AS sessions
                FROM $t WHERE $where_sql
                GROUP BY source ORDER BY views DESC LIMIT 30";
        return $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A) ?: [];
    }

    public static function get_top_pages($date_from = '', $date_to = '', $limit = 30) {
        global $wpdb;
        $t = self::table_name();
        $where = ['1=1'];
        $args = [];
        if ($date_from) { $where[] = 'view_date >= %s'; $args[] = $date_from; }
        if ($date_to)   { $where[] = 'view_date <= %s'; $args[] = $date_to; }
        $where_sql = implode(' AND ', $where);

        // MAX(page_title) als ANY_VALUE-Ersatz — funktioniert in allen MySQL/MariaDB
        // Versionen, gibt deterministisch denselben page_title pro page_path zurück.
        $sql = "SELECT page_path, MAX(page_title) AS page_title,
                       COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS uniques
                FROM $t WHERE $where_sql
                GROUP BY page_path ORDER BY views DESC LIMIT %d";
        $args[] = intval($limit);
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
    }

    public static function get_devices($date_from = '', $date_to = '') {
        global $wpdb;
        $t = self::table_name();
        $where = ['1=1'];
        $args = [];
        if ($date_from) { $where[] = 'view_date >= %s'; $args[] = $date_from; }
        if ($date_to)   { $where[] = 'view_date <= %s'; $args[] = $date_to; }
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT device_type, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS uniques
                FROM $t WHERE $where_sql
                GROUP BY device_type ORDER BY views DESC";
        return $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A) ?: [];
    }

    public static function get_timeseries($date_from = '', $date_to = '') {
        global $wpdb;
        $t = self::table_name();
        $where = ['1=1'];
        $args = [];
        if ($date_from) { $where[] = 'view_date >= %s'; $args[] = $date_from; }
        if ($date_to)   { $where[] = 'view_date <= %s'; $args[] = $date_to; }
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT view_date, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS uniques, COUNT(DISTINCT session_id) AS sessions
                FROM $t WHERE $where_sql
                GROUP BY view_date ORDER BY view_date ASC";
        return $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A) ?: [];
    }

    public static function get_referrers($date_from = '', $date_to = '', $limit = 20) {
        global $wpdb;
        $t = self::table_name();
        $where = ["referrer_host != ''"];
        $args = [];
        if ($date_from) { $where[] = 'view_date >= %s'; $args[] = $date_from; }
        if ($date_to)   { $where[] = 'view_date <= %s'; $args[] = $date_to; }
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT referrer_host, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS uniques
                FROM $t WHERE $where_sql
                GROUP BY referrer_host ORDER BY views DESC LIMIT %d";
        $args[] = intval($limit);
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
    }

    /**
     * Conversion-Rate: Site-Visitors → Orders
     * Vergleicht eindeutige Visitor-IDs mit Bestellungen im selben Zeitraum.
     */
    public static function get_conversion($date_from = '', $date_to = '') {
        global $wpdb;
        $t = self::table_name();
        $where = ['1=1'];
        $args = [];
        if ($date_from) { $where[] = 'view_date >= %s'; $args[] = $date_from; }
        if ($date_to)   { $where[] = 'view_date <= %s'; $args[] = $date_to; }
        $where_sql = implode(' AND ', $where);

        $uniques = (int) $wpdb->get_var(
            $args ? $wpdb->prepare("SELECT COUNT(DISTINCT visitor_id) FROM $t WHERE $where_sql", ...$args)
                  : "SELECT COUNT(DISTINCT visitor_id) FROM $t WHERE $where_sql"
        );

        // Native + WC Orders im Zeitraum (mit Erfolg)
        $orders_table = $wpdb->prefix . 'tix_orders';
        $order_count = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$orders_table'") === $orders_table) {
            $owhere = ["status IN ('completed','processing')"];
            $oargs = [];
            if ($date_from) { $owhere[] = 'date_created >= %s'; $oargs[] = $date_from . ' 00:00:00'; }
            if ($date_to)   { $owhere[] = 'date_created <= %s'; $oargs[] = $date_to   . ' 23:59:59'; }
            $sql = "SELECT COUNT(*) FROM $orders_table WHERE " . implode(' AND ', $owhere);
            $order_count += (int) ($oargs ? $wpdb->get_var($wpdb->prepare($sql, ...$oargs)) : $wpdb->get_var($sql));
        }

        $rate = $uniques > 0 ? round(($order_count / $uniques) * 100, 2) : 0;
        return [
            'unique_visitors' => $uniques,
            'orders'          => $order_count,
            'rate'            => $rate,
        ];
    }
}
