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
    // 1.38.492: Archiv kurz nach dem echten Ende (stündlich), Gnadenfrist in Stunden, Entwürfe optional ausnehmen
    const OPT_GRACE    = 'tix_archive_grace_hours';
    const OPT_SKIP     = 'tix_archive_skip_drafts';

    public static function init() {
        // Cron
        add_action(self::CRON_HOOK, [__CLASS__, 'run_archive_sweep']);
        add_action('tix_archive_run_soon', [__CLASS__, 'run_archive_sweep']);
        add_action('init', [__CLASS__, 'schedule_cron']);
        add_action('init', [__CLASS__, 'maybe_heal_once'], 30);

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
        // stündlich (bis 1.38.491 täglich – alten Tageslauf umstellen)
        if (wp_next_scheduled(self::CRON_HOOK) && wp_get_schedule(self::CRON_HOOK) !== 'hourly') self::unschedule_cron();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
        }
    }

    /** Einstellungen: {enabled, grace_hours, skip_drafts}. Gnadenfrist Vorgabe 1 h; alte Tage-Einstellung (> 0) gilt, bis Stunden gesetzt sind. */
    public static function settings() {
        // Vorgaben aus tix_get_settings() (auto_archive_enabled = 1), frisch gespeicherte Werte darüber (statischer Zwischenspeicher)
        $opt = get_option('tix_settings', []);
        $s = array_merge(function_exists('tix_get_settings') ? (array) tix_get_settings() : [], is_array($opt) ? $opt : []);
        $g = get_option(self::OPT_GRACE, null);
        if ($g === null || $g === false || $g === '') {
            $days = intval($s['auto_archive_days'] ?? 0);
            $g = $days > 0 ? $days * 24 : 1;
        }
        return ['enabled' => !empty($s['auto_archive_enabled']), 'grace_hours' => max(0, min(720, intval($g))), 'skip_drafts' => get_option(self::OPT_SKIP, '0') === '1'];
    }

    /** Für Studio/App (GET /studio/config → archive). */
    public static function payload() {
        $c = self::settings();
        $next = wp_next_scheduled(self::CRON_HOOK);
        return $c + ['interval' => 'hourly', 'next_run' => $next ? wp_date('Y-m-d H:i', $next) : null];
    }

    /** POST /studio/config {archive: {enabled?, grace_hours?, skip_drafts?}} → true | WP_Error */
    public static function save($in) {
        if (!is_array($in)) return new WP_Error('tix_invalid_field', 'archive: Objekt erwartet.', ['status' => 400, 'field' => 'archive']);
        if (array_key_exists('grace_hours', $in)) {
            $g = $in['grace_hours'];
            if (!is_numeric($g) || intval($g) != $g || intval($g) < 0 || intval($g) > 720) return new WP_Error('tix_invalid_field', 'Gnadenfrist: ganze Stunden von 0 bis 720.', ['status' => 400, 'field' => 'archive.grace_hours']);
        }
        if (array_key_exists('enabled', $in)) {
            $s = get_option('tix_settings', []);
            $s = is_array($s) ? $s : [];
            $s['auto_archive_enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            update_option('tix_settings', $s);
        }
        if (array_key_exists('grace_hours', $in)) update_option(self::OPT_GRACE, (string) intval($in['grace_hours']), false);
        if (array_key_exists('skip_drafts', $in)) update_option(self::OPT_SKIP, filter_var($in['skip_drafts'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', false);
        // nach dem Einschalten bald prüfen (nicht im Request)
        if (self::settings()['enabled'] && !wp_next_scheduled('tix_archive_run_soon')) wp_schedule_single_event(time() + 60, 'tix_archive_run_soon');
        return true;
    }

    /**
     * Echtes Event-Ende als Unix-Zeit: Enddatum + Endzeit (Endzeit vor Beginn ohne eigenes Enddatum = nächster Tag);
     * ohne Endzeit Beginn (des letzten Tags) + 7 h; ohne Uhrzeit Ende des (letzten) Tages. 0 = kein Datum.
     */
    public static function end_ts($event_id) {
        $m = function ($k) use ($event_id) { return trim((string) get_post_meta(intval($event_id), $k, true)); };
        $ds = $m('_tix_date_start'); $de = $m('_tix_date_end');
        $ts = $m('_tix_time_start') ?: $m('_tix_time_doors'); $te = $m('_tix_time_end');
        if ($ds === '' && $de === '') return 0;
        $tz = wp_timezone();
        $at = function ($d, $t) use ($tz) { try { return (new DateTimeImmutable($d . ' ' . ($t !== '' ? $t : '00:00'), $tz))->getTimestamp(); } catch (\Exception $e) { return 0; } };
        $day = $de !== '' ? $de : $ds;
        if ($te !== '') {
            $end = $at($day, $te);
            $start_same_day = $ts !== '' ? $at($day, $ts) : 0;
            if ($end && $start_same_day && $end <= $start_same_day && ($de === '' || $de === $ds)) $end += DAY_IN_SECONDS;
            return $end;
        }
        if ($ts !== '') return $at($day, $ts) + 7 * HOUR_IN_SECONDS;
        return $at($day, '23:59:59');
    }

    public static function unschedule_cron() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }

    /**
     * Sweep: Alle Events prüfen und abgelaufene archivieren.
     */
    /**
     * Selbstheilung (1.38.457): Kopien trugen das Archiv-Flag des Originals – `_tix_archived_at` liegt dann vor dem
     * Anlegen des Events (post_date). Solche Events zurückholen; absichtlich archivierte bleiben unangetastet.
     */
    public static function heal_copies() {
        global $wpdb;
        $ids = $wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '" . self::META_FLAG . "'
            INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '" . self::META_DATE . "'
            WHERE p.post_type = 'event' AND d.meta_value <> '' AND d.meta_value < p.post_date");
        foreach ($ids as $id) {
            self::unarchive_event(intval($id));
            // weitere geerbte Laufzeit-Markierungen der Kopie
            delete_post_meta(intval($id), '_tix_is_past');
        }
        return count($ids);
    }

    public static function maybe_heal_once() {
        if (get_option('tix_archive_heal_v1')) return;
        update_option('tix_archive_heal_v1', current_time('mysql'), false);
        self::heal_copies();
    }

    public static function run_archive_sweep() {
        self::heal_copies();
        $c = self::settings();
        if (!$c['enabled']) return 0;

        // Ende + Gnadenfrist (Stunden) muss vorbei sein; Entwürfe auf Wunsch ausnehmen (1.38.492)
        $cutoff = time() - $c['grace_hours'] * HOUR_IN_SECONDS;

        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => $c['skip_drafts'] ? ['publish', 'private'] : ['publish', 'draft', 'private'],
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
            $end = self::end_ts($event_id);
            if (!$end) continue;
            if ($end <= $cutoff) {
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
