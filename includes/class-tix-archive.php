<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Archive – Auto-Archivierung abgelaufener Events.
 *
 * Setzt ein Meta-Flag `_tix_archived = 1` auf Events, deren
 * Enddatum plus Gnadenfrist überschritten ist. Archivierte Events
 * werden im Frontend (Event-Listen, Katalog) ausgeblendet, bleiben
 * aber im Admin sichtbar unter der Ansicht "Archiv".
 *
 * Meta-Felder:
 *   _tix_archived     : 1 wenn archiviert
 *   _tix_archived_at  : MySQL-Datum der Archivierung
 *
 * @since 1.34.237
 */
class TIX_Archive {

    const META_FLAG    = '_tix_archived';
    const META_DATE    = '_tix_archived_at';
    const CRON_HOOK    = 'tix_archive_expired_events';

    public static function init() {
        // Cron
        add_action(self::CRON_HOOK, [__CLASS__, 'run_archive_sweep']);
        add_action('init', [__CLASS__, 'schedule_cron']);

        // Admin: Ansichten + Filter + Row-Actions
        add_filter('views_edit-event',           [__CLASS__, 'event_views']);
        add_action('pre_get_posts',              [__CLASS__, 'filter_admin_events']);
        add_filter('post_row_actions',           [__CLASS__, 'row_actions'], 20, 2);
        add_action('admin_action_tix_archive',   [__CLASS__, 'handle_admin_action']);
        add_action('admin_action_tix_unarchive', [__CLASS__, 'handle_admin_action']);
        add_action('admin_notices',              [__CLASS__, 'admin_notices']);

        // Frontend: archivierte Events per posts_where (sicherer als pre_get_posts für Breakdance)
        add_filter('posts_where',                [__CLASS__, 'filter_frontend_where'], 10, 2);
    }

    /* ══════════════════════════════════════════
     * Cron
     * ══════════════════════════════════════════ */

    public static function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule_cron() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }

    /**
     * Sweep: Alle Events prüfen und abgelaufene archivieren.
     */
    public static function run_archive_sweep() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (empty($s['auto_archive_enabled'])) return 0;

        $grace_days = max(0, intval($s['auto_archive_days'] ?? 0));
        $cutoff_ts  = current_time('timestamp') - ($grace_days * DAY_IN_SECONDS);
        $cutoff_date = date('Y-m-d', $cutoff_ts);

        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => self::META_FLAG,
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'no_found_rows'  => true,
        ]);

        $archived = 0;
        foreach ($events as $event_id) {
            $end = get_post_meta($event_id, '_tix_date_end', true);
            if (!$end) $end = get_post_meta($event_id, '_tix_date_start', true);
            if (!$end) continue;

            if ($end < $cutoff_date) {
                self::archive_event($event_id);
                $archived++;
            }
        }

        return $archived;
    }

    /* ══════════════════════════════════════════
     * Archiv-Aktionen
     * ══════════════════════════════════════════ */

    public static function archive_event($event_id) {
        update_post_meta($event_id, self::META_FLAG, 1);
        update_post_meta($event_id, self::META_DATE, current_time('mysql'));
        do_action('tix_event_archived', $event_id);
    }

    public static function unarchive_event($event_id) {
        delete_post_meta($event_id, self::META_FLAG);
        delete_post_meta($event_id, self::META_DATE);
        do_action('tix_event_unarchived', $event_id);
    }

    public static function is_archived($event_id) {
        return (bool) get_post_meta($event_id, self::META_FLAG, true);
    }

    /* ══════════════════════════════════════════
     * Admin: Ansichten-Tabs
     * ══════════════════════════════════════════ */

    public static function event_views($views) {
        $all_count      = self::count_events(false);
        $archived_count = self::count_events(true);

        $base = admin_url('edit.php?post_type=event');

        $current = $_GET['tix_view'] ?? 'active';

        // "Alle" zu "Aktiv" umbenennen (aktive Events ohne Archiv)
        if (isset($views['all'])) {
            $views['all'] = preg_replace(
                '/<a([^>]*)>.*?<\/a>/',
                '<a$1>Aktiv <span class="count">(' . ($all_count - $archived_count) . ')</span></a>',
                $views['all']
            );
            // Wenn wir in Archiv sind, current von all entfernen
            if ($current === 'archived') {
                $views['all'] = str_replace('class="current"', '', $views['all']);
                $views['all'] = str_replace("aria-current=\"page\"", '', $views['all']);
            }
        }

        $archived_url = add_query_arg(['tix_view' => 'archived'], $base);
        $class = ($current === 'archived') ? ' class="current"' : '';
        $views['tix_archived'] = '<a href="' . esc_url($archived_url) . '"' . $class . '>Archiv <span class="count">(' . $archived_count . ')</span></a>';

        return $views;
    }

    private static function count_events($archived_only) {
        $args = [
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'private', 'future', 'pending'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];
        if ($archived_only) {
            $args['meta_query'] = [[
                'key'     => self::META_FLAG,
                'value'   => '1',
                'compare' => '=',
            ]];
        }
        $q = new WP_Query($args);
        return count($q->posts);
    }

    /* ══════════════════════════════════════════
     * Admin: Query-Filter
     * ══════════════════════════════════════════ */

    public static function filter_admin_events($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if (($query->get('post_type') ?: '') !== 'event') return;

        // Breakdance Template-Simulator läuft innerhalb is_admin(),
        // rendert aber Frontend-Seiten — dort NICHT filtern.
        global $pagenow;
        if ($pagenow !== 'edit.php') return;

        $view = $_GET['tix_view'] ?? 'active';

        if ($view === 'archived') {
            $query->set('meta_query', array_merge(
                $query->get('meta_query') ?: [],
                [['key' => self::META_FLAG, 'value' => '1', 'compare' => '=']]
            ));
        } else {
            $query->set('meta_query', array_merge(
                $query->get('meta_query') ?: [],
                [['key' => self::META_FLAG, 'compare' => 'NOT EXISTS']]
            ));
        }
    }

    /* ══════════════════════════════════════════
     * Admin: Row-Actions
     * ══════════════════════════════════════════ */

    public static function row_actions($actions, $post) {
        if ($post->post_type !== 'event') return $actions;
        if (!current_user_can('edit_post', $post->ID)) return $actions;

        $is_archived = self::is_archived($post->ID);
        $action      = $is_archived ? 'tix_unarchive' : 'tix_archive';
        $label       = $is_archived ? 'Wiederherstellen' : 'Archivieren';

        $url = wp_nonce_url(
            admin_url('admin.php?action=' . $action . '&post=' . $post->ID),
            'tix_archive_' . $post->ID
        );

        $actions['tix_archive'] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        return $actions;
    }

    public static function handle_admin_action() {
        $post_id = intval($_GET['post'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('tix_archive_' . $post_id);

        $action = $_GET['action'] ?? '';
        if ($action === 'tix_archive') {
            self::archive_event($post_id);
            $msg = 'archived';
        } elseif ($action === 'tix_unarchive') {
            self::unarchive_event($post_id);
            $msg = 'unarchived';
        } else {
            wp_die('Ungültige Aktion.');
        }

        $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=event');
        $redirect = add_query_arg('tix_archive_msg', $msg, $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public static function admin_notices() {
        if (empty($_GET['tix_archive_msg'])) return;
        $msg = $_GET['tix_archive_msg'];
        $text = $msg === 'archived' ? 'Event archiviert.' : ($msg === 'unarchived' ? 'Event wiederhergestellt.' : '');
        if ($text) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($text) . '</p></div>';
        }
    }

    /* ══════════════════════════════════════════
     * Frontend: archivierte Events ausschließen
     * ══════════════════════════════════════════ */

    /**
     * Frontend-Filter via posts_where: Archivierte Events aus Ergebnis-Queries
     * ausschließen, ohne meta_query zu manipulieren (vermeidet Breakdance-Crash).
     *
     * Filtert nur Multi-Post Event-Queries, nicht Singular-Requests.
     */
    public static function filter_frontend_where($where, $query) {
        if (is_admin()) return $where;

        // Nur Event-Archive / Taxonomie-Listen, keine Single-Posts
        $pt = $query->get('post_type');
        $is_event_list = false;

        if ($pt === 'event' && !$query->is_singular()) {
            $is_event_list = true;
        }

        if (!$is_event_list) return $where;

        global $wpdb;
        $where .= " AND {$wpdb->posts}.ID NOT IN (
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '" . esc_sql(self::META_FLAG) . "' AND meta_value = '1'
        )";

        return $where;
    }
}
