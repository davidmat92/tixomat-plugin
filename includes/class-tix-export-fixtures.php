<?php
/**
 * TIX_Export_Fixtures – Vergleichsdaten für den Dienst evendis-api (Paket 2.3 Events/Preise; ab 1.38.897). Nur WP-CLI.
 *
 *   wp tixomat export-fixtures seed                      Testfälle anlegen bzw. neu anlegen (NUR lokale Testseite, siehe guard)
 *   wp tixomat export-fixtures dump --events=fixtures|<ids> --out=<dir>
 *        je Event eine JSON-Datei: {case, plugin_version, now, now_gmt, timezone, export{…V5-Zeilen…}, public_list, public_detail}
 *
 * dump liest nur (wie ein Export-Abruf, ohne HTTP). seed schreibt Test-Events/Veranstalter/Ort/Bild mit Meta `_tix_fixture_case`
 * und verweigert jede Seite, deren Host nicht lokal ist (ohne Punkt, localhost, *.test, *.localhost).
 */
if (!defined('ABSPATH')) exit;

class TIX_Export_Fixtures {

    const META = '_tix_fixture_case';

    public static function init() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat export-fixtures', [__CLASS__, 'cli']);
    }

    /**
     * ## OPTIONS
     * <action>
     * : seed | dump
     * [--events=<ids>]
     * : fixtures (Vorgabe) oder Komma-Liste von Event-IDs
     * [--out=<dir>]
     * : Zielordner für dump
     */
    public static function cli($args, $assoc) {
        $a = $args[0] ?? '';
        if ($a === 'seed') {
            if (!self::local_site()) WP_CLI::error('seed nur auf einer lokalen Testseite (Host ohne Punkt, localhost, *.test, *.localhost). Diese Seite: ' . home_url());
            $ids = self::seed();
            WP_CLI::line(wp_json_encode($ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            WP_CLI::success(count($ids['events']) . ' Testfälle angelegt.');
            return;
        }
        if ($a === 'dump') {
            $out = rtrim((string) ($assoc['out'] ?? ''), '/');
            if ($out === '') WP_CLI::error('--out=<dir> fehlt');
            if (!is_dir($out) && !wp_mkdir_p($out)) WP_CLI::error('Ordner nicht anlegbar: ' . $out);
            $sel = (string) ($assoc['events'] ?? 'fixtures');
            $ids = $sel === 'fixtures' ? self::fixture_events() : array_values(array_filter(array_map('intval', explode(',', $sel))));
            if (!$ids) WP_CLI::error('Keine Events (erst seed ausführen oder --events=<ids>).');
            $index = [];
            foreach ($ids as $id) {
                $d = self::dump_event($id);
                $name = 'event-' . ($d['case'] !== '' ? $d['case'] : $id) . '.json';
                file_put_contents($out . '/' . $name, wp_json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $index[] = ['file' => $name, 'event_id' => $id, 'case' => $d['case'], 'listed' => $d['public_list'] !== null];
            }
            file_put_contents($out . '/index.json', wp_json_encode(['site' => home_url(), 'multi' => class_exists('TIX_App_Scope') && TIX_App_Scope::multi(),
                'plugin_version' => TIXOMAT_VERSION, 'recorded_at' => gmdate('c'), 'files' => $index], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            WP_CLI::success(count($ids) . ' Dateien in ' . $out);
            return;
        }
        WP_CLI::error('seed | dump');
    }

    public static function local_site() {
        $h = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ($h === '' ) return false;
        return strpos($h, '.') === false || $h === 'localhost' || preg_match('/\.(test|localhost)$/', $h) === 1;
    }

    public static function fixture_events() {
        global $wpdb;
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'event' ORDER BY p.ID ASC", self::META)));
    }

    // ═══════════════ Aufnahme ═══════════════

    private static function items($entity, array $ids) {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), 'strlen')));
        if (!$ids) return [];
        $req = new WP_REST_Request('GET', '/tixomat/v1/_export/' . $entity);
        $req->set_param('entity', $entity);
        $req->set_param('ids', implode(',', $ids));
        $res = TIX_Service_Export::rest_entity($req);
        $d = $res instanceof WP_REST_Response ? $res->get_data() : (is_wp_error($res) ? ['error' => $res->get_error_code()] : $res);
        return ['items' => $d['items'] ?? [], 'missing' => $d['missing'] ?? []];
    }

    public static function dump_event($id) {
        $id = (int) $id;
        $oid = (int) get_post_meta($id, '_tix_organizer_id', true);
        $lid = (int) get_post_meta($id, '_tix_location_id', true);
        $media = array_filter([(int) get_post_thumbnail_id($id), (int) get_post_meta($id, '_tix_tip_author_image', true)]);
        $cfg = new WP_REST_Request('GET', '/tixomat/v1/_export/config');
        $cfg->set_param('entity', 'config');
        $cfg_d = TIX_Service_Export::rest_entity($cfg)->get_data();
        $der = TIX_Service_Export::rest_derived(new WP_REST_Request('GET', '/tixomat/v1/_export/derived'))->get_data();
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return [
            'case'           => (string) get_post_meta($id, self::META, true),
            'event_id'       => $id,
            'plugin_version' => TIXOMAT_VERSION,
            'now'            => current_time('timestamp'),   // Konvention times(): lokale Zeit als Zeitstempel
            'now_gmt'        => time(),
            'now_local'      => current_time('Y-m-d H:i:s'),
            'timezone'       => wp_timezone_string(),
            'export'         => [
                'events'            => self::items('events', [$id]),
                'ticket_categories' => self::items('ticket_categories', [$id]),
                'organizers'        => self::items('organizers', $oid ? [$oid] : []),
                'locations'         => self::items('locations', $lid ? [$lid] : []),
                'media'             => self::items('media', $media),
                'sponsors'          => self::items('sponsors', array_filter(['event:' . $id, $oid ? 'organizer:' . $oid : '', 'site:0'])),
                'config'            => $cfg_d['items'] ?? [],
                'derived'           => $der,
            ],
            'public_list'    => class_exists('TIX_Public_Events') ? TIX_Public_Events::payload($id, false) : null,
            'public_detail'  => class_exists('TIX_Public_Events') ? TIX_Public_Events::payload($id, true) : null,
        ];
    }

    // ═══════════════ Testfälle ═══════════════

    private static function clear() {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META)));
        foreach ($ids as $p) {
            if (get_post_type($p) === 'attachment') wp_delete_attachment($p, true); else wp_delete_post($p, true);
        }
    }

    private static function image() {
        if (!function_exists('imagecreatetruecolor')) return 0;
        $up = wp_upload_dir();
        $file = trailingslashit($up['path']) . 'tix-fixture-' . wp_generate_password(6, false) . '.png';
        $im = imagecreatetruecolor(1600, 900);
        imagefill($im, 0, 0, imagecolorallocate($im, 232, 68, 90));
        imagepng($im, $file);
        imagedestroy($im);
        $aid = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Fixture', 'post_status' => 'inherit'], $file);
        if (!$aid || is_wp_error($aid)) return 0;
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $file));
        update_post_meta($aid, '_wp_attachment_image_alt', 'Fixture-Bild');
        update_post_meta($aid, self::META, 'image');
        return (int) $aid;
    }

    private static function org($case, array $meta) {
        $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Fixture ' . $case]);
        update_post_meta($id, self::META, 'org_' . $case);
        update_post_meta($id, '_tix_org_approval', 'approved');
        foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
        return (int) $id;
    }

    private static function event($case, array $meta, $status = 'publish') {
        $id = wp_insert_post(['post_type' => 'event', 'post_status' => $status, 'post_title' => 'Fixture ' . str_replace('_', ' ', $case), 'post_content' => '<p>Testfall ' . esc_html($case) . '</p>']);
        update_post_meta($id, self::META, $case);
        foreach ($meta as $k => $v) {
            if ($k === '_thumbnail_id') { if ($v) set_post_thumbnail($id, (int) $v); continue; }
            update_post_meta($id, $k, $v);
        }
        return (int) $id;
    }

    public static function seed() {
        self::clear();
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        $img = self::image();
        $loc = wp_insert_post(['post_type' => 'tix_location', 'post_status' => 'publish', 'post_title' => 'Fixture Halle']);
        update_post_meta($loc, self::META, 'location');
        foreach (['_tix_loc_address' => 'Musterstr. 1', '_tix_loc_city' => 'Hamburg', '_tix_loc_lat' => '53.55', '_tix_loc_lng' => '9.99'] as $k => $v) update_post_meta($loc, $k, $v);
        // Veranstalter: Normal, Gebühr zu Lasten des Käufers (mit Höchstbeträgen), Gebühr geteilt
        $o_plain = self::org('plain', []);
        $fee_cust = $multi
            ? ['_tix_fee_custom' => '1', '_tix_fee_fixed' => '1.50', '_tix_fee_percent' => '5', '_tix_fee_override' => '1', '_tix_fee_mode' => 'customer']
            : ['_tix_fee_override' => '1', '_tix_fee_mode' => 'customer', '_tix_fee_fixed' => '1.50', '_tix_fee_percent' => '5', '_tix_fee_label' => 'Vorverkaufsgebühr', '_tix_fee_max_per_ticket' => '2.00', '_tix_fee_max_per_order' => '4.00'];
        $o_cust = self::org('fee_customer', $fee_cust);
        $o_split = self::org('fee_split', $multi
            ? ['_tix_fee_custom' => '1', '_tix_fee_fixed' => '1.00', '_tix_fee_percent' => '3', '_tix_fee_override' => '1', '_tix_fee_mode' => 'split']
            : ['_tix_fee_override' => '1', '_tix_fee_mode' => 'split', '_tix_fee_fixed' => '1.00', '_tix_fee_percent' => '3']);
        $o_demo = self::org('demo', ['_tix_org_demo' => '1']);

        $day = function ($n) { return gmdate('Y-m-d', current_time('timestamp') + $n * DAY_IN_SECONDS); };
        $base = function ($n, array $extra = []) use ($day, $img, $loc, $o_plain) {
            return array_merge(['_tix_date_start' => $day($n), '_tix_time_start' => '20:00', '_tix_date_end' => $day($n), '_tix_time_end' => '23:30',
                '_tix_location_id' => $loc, '_tix_location' => 'Fixture Halle', '_tix_organizer_id' => $o_plain, '_thumbnail_id' => $img,
                '_tix_tickets_enabled' => '1'], $extra);
        };
        $cat = function ($name, $price, array $x = []) { return array_merge(['name' => $name, 'price' => $price, 'stock' => 100, 'online' => '1', 'desc' => ''], $x); };
        $E = [];
        $E['no_tickets']       = self::event('no_tickets', $base(14, ['_tix_tickets_enabled' => '0']));
        $E['box_office_only']  = self::event('box_office_only', $base(15, ['_tix_tickets_enabled' => '0', '_tix_box_office_only' => '1', '_tix_box_office_price' => '12,50', '_tix_box_office_desc' => 'Nur Abendkasse', '_tix_price_min' => '12.5']));
        $E['free_entry']       = self::event('free_entry', $base(16, ['_tix_tickets_enabled' => '0', '_tix_free_entry' => '1', '_tix_free_entry_note' => 'Hutspende erbeten']));
        $E['sale_prices']      = self::event('sale_prices', $base(17, ['_tix_ticket_categories' => [
            $cat('Normal', 20, ['sale_price' => 15]), $cat('VIP', 50), $cat('Ermäßigt', 12, ['sale_price' => '']),
        ]]));
        $E['phase_active']     = self::event('phase_active', $base(18, ['_tix_ticket_categories' => [
            $cat('Early Bird', 25, ['phases' => [['name' => 'Early Bird', 'price' => 18, 'until' => $day(5)], ['name' => 'Spät', 'price' => 22, 'until' => $day(10)]]]),
        ]]));
        $E['phase_expired']    = self::event('phase_expired', $base(19, ['_tix_ticket_categories' => [
            $cat('Normal', 25, ['phases' => [['name' => 'Early Bird', 'price' => 18, 'until' => $day(-3)]]]),
        ]]));
        $E['fee_customer_max'] = self::event('fee_customer_max', $base(20, ['_tix_organizer_id' => $o_cust, '_tix_ticket_categories' => [$cat('Normal', 30), $cat('Günstig', 8)]]));
        $E['fee_split']        = self::event('fee_split', $base(21, ['_tix_organizer_id' => $o_split, '_tix_ticket_categories' => [$cat('Normal', 30)]]));
        $E['bundle']           = self::event('bundle', $base(22, ['_tix_ticket_categories' => [$cat('Gruppe', 15, ['bundle_buy' => 4, 'bundle_pay' => 3, 'bundle_label' => '4 für 3'])]]));
        $E['sold_out_online']  = self::event('sold_out_online', $base(23, ['_tix_status' => 'sold_out', '_tix_status_label' => 'Ausverkauft', '_tix_ticket_categories' => [$cat('Normal', 20, ['stock' => 0])]]));
        $E['sold_out_offline'] = self::event('sold_out_offline', $base(24, ['_tix_tickets_enabled' => '0', '_tix_status' => 'sold_out', '_tix_status_label' => 'Ausverkauft']));
        $E['hidden_categories']= self::event('hidden_categories', $base(25, ['_tix_ticket_categories' => [
            $cat('Öffentlich', 20), $cat('Nur Admin', 0, ['admin_only' => 1]), $cat('Nicht online', 10, ['online' => '0']), $cat('Versteckt', 5, ['hidden' => 1]), $cat('Gutschein', 25, ['hidden' => 1, 'gift_card' => 1]),
        ]]));
        $E['multi_day']        = self::event('multi_day', $base(26, ['_tix_time_start' => '22:00', '_tix_date_end' => $day(28), '_tix_time_end' => '04:00', '_tix_ticket_categories' => [$cat('Festival-Pass', 89)]]));
        $E['overnight']        = self::event('overnight', $base(27, ['_tix_time_start' => '23:00', '_tix_date_end' => '', '_tix_time_end' => '05:00', '_tix_ticket_categories' => [$cat('Normal', 10)]]));
        $E['no_end_time']      = self::event('no_end_time', $base(29, ['_tix_date_end' => '', '_tix_time_end' => '', '_tix_ticket_categories' => [$cat('Normal', 10)]]));
        $E['no_time']          = self::event('no_time', $base(30, ['_tix_time_start' => '', '_tix_date_end' => '', '_tix_time_end' => '', '_tix_ticket_categories' => [$cat('Normal', 10)]]));
        $E['past']             = self::event('past', $base(-2, ['_tix_ticket_categories' => [$cat('Normal', 10)]]));
        $E['running_now']      = self::event('running_now', $base(0, ['_tix_time_start' => gmdate('H:i', current_time('timestamp') - HOUR_IN_SECONDS), '_tix_time_end' => '', '_tix_date_end' => '', '_tix_ticket_categories' => [$cat('Normal', 10)]]));
        $E['sandbox_demo']     = self::event('sandbox_demo', $base(31, ['_tix_demo_seed' => '1', '_tix_preview' => '1', '_tix_organizer_id' => $o_demo, '_tix_ticket_categories' => [$cat('Normal', 10)]]));
        $E['trash']            = self::event('trash', $base(32, ['_tix_ticket_categories' => [$cat('Normal', 10)]]));
        wp_trash_post($E['trash']);
        $E['draft']            = self::event('draft', $base(33, ['_tix_ticket_categories' => [$cat('Normal', 10)]]), 'draft');
        $E['syndicated']       = self::event('syndicated', $base(34, ['_tix_syndicated' => '1', '_tix_source_site' => 'Partner-Club', '_tix_source_url' => 'https://partner.example.test/event/1/',
            '_tix_source_fee' => ['fixed' => 1.0, 'percent' => 0, 'mode' => 'customer', 'label' => 'Servicegebühr', 'max_per_ticket' => 0, 'max_per_order' => 3.0],
            '_tix_ticket_categories' => [$cat('Normal', 22)]]));
        foreach ($E as $e) clean_post_cache($e);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return ['events' => $E, 'organizers' => ['plain' => $o_plain, 'fee_customer' => $o_cust, 'fee_split' => $o_split, 'demo' => $o_demo], 'location' => (int) $loc, 'image' => $img];
    }
}

TIX_Export_Fixtures::init();
