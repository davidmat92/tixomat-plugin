<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_App_Sos – Menüpunkt „SOS“ (Rettungsring) im Tab-Menü der Einzel-Veranstalter-Apps (1.38.434, alle Seiten).
 *
 * Option `_tix_app_nav_sos` {enabled, label, text, numbers:[{label, number}]} – eigene Option, damit
 * `POST /app/config` (ersetzt die ganze App-Konfiguration) sie nur ändert, wenn `nav.sos` mitkommt.
 *
 *   GET  /app/config      → zusätzlich `nav: {sos: {enabled:true, label, icon, text, numbers, live}}`, nur wenn an
 *                           (aus bzw. nie gesetzt: Antwort unverändert)
 *   POST /app/config      → `nav.sos` im Body wird hier gespeichert (nicht in `_tix_app_config`)
 *   GET  /app/config/edit → zusätzlich `nav_sos` (immer, auch wenn aus – für den Schalter im Editor)
 *   GET  /app/sos         → live für die SOS-Seite: laufendes Event, Awareness-Angaben, Team im Dienst, Melden
 *
 * Admin: Tixomat → Awareness, Abschnitt „SOS in der App“.
 */
class TIX_App_Sos {

    const NS  = 'tixomat/v1';
    const OPT = '_tix_app_nav_sos';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_tix_app_sos', [__CLASS__, 'save_admin']);
    }

    public static function default_numbers() {
        return [['label' => 'Polizei', 'number' => '110'], ['label' => 'Feuerwehr & Rettungsdienst', 'number' => '112']];
    }

    /** Rohwerte säubern (Admin, POST /app/config). */
    public static function sanitize($in, array $base = null) {
        $s = $base ?: self::settings();
        $in = is_array($in) ? $in : [];
        if (array_key_exists('enabled', $in)) $s['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('label', $in)) {
            $l = mb_substr(trim(sanitize_text_field((string) $in['label'])), 0, 20);
            $s['label'] = $l !== '' ? $l : 'SOS';
        }
        if (array_key_exists('text', $in)) $s['text'] = mb_substr(trim(sanitize_textarea_field((string) $in['text'])), 0, 600);
        if (array_key_exists('numbers', $in)) {
            $nums = [];
            foreach ((array) $in['numbers'] as $n) {
                if (is_string($n)) $n = ['number' => $n, 'label' => ''];
                if (!is_array($n)) continue;
                $num = preg_replace('/[^0-9+ \/-]/', '', (string) ($n['number'] ?? ''));
                $num = trim(preg_replace('/\s+/', ' ', $num));
                if ($num === '' || strlen(preg_replace('/\D/', '', $num)) < 3) continue;
                $nums[] = ['label' => mb_substr(trim(sanitize_text_field((string) ($n['label'] ?? ''))), 0, 40), 'number' => mb_substr($num, 0, 25)];
            }
            $s['numbers'] = $nums ? array_slice($nums, 0, 4) : self::default_numbers();
        }
        return $s;
    }

    public static function settings() {
        $raw = get_option(self::OPT, null);
        $base = ['enabled' => false, 'label' => 'SOS', 'text' => '', 'numbers' => self::default_numbers()];
        if (!is_array($raw)) return $base;
        return [
            'enabled' => !empty($raw['enabled']),
            'label'   => (string) ($raw['label'] ?? 'SOS') ?: 'SOS',
            'text'    => (string) ($raw['text'] ?? ''),
            'numbers' => !empty($raw['numbers']) && is_array($raw['numbers']) ? array_values($raw['numbers']) : self::default_numbers(),
        ];
    }

    public static function save(array $in) {
        $s = self::sanitize($in);
        update_option(self::OPT, $s, false);
        return $s;
    }

    /** Statischer Block für /app/config (nur wenn an). */
    public static function nav_block() {
        $s = self::settings();
        if (!$s['enabled']) return null;
        return ['enabled' => true, 'label' => $s['label'], 'icon' => 'lifebuoy', 'text' => $s['text'], 'numbers' => $s['numbers'], 'live' => '/app/sos'];
    }

    // ── Live-Daten für die SOS-Seite ──

    /** Laufendes Event (Fenster wie Musikwunsch/Awareness). */
    public static function current_event() {
        $cur = class_exists('TIX_Music') ? TIX_Music::current_event() : null;
        return $cur ? intval($cur['id']) : 0;
    }

    /** GET /app/sos[?event_id=] */
    public static function rest_live(WP_REST_Request $req) {
        $s = self::settings();
        $r = function ($d) { $x = new WP_REST_Response($d); $x->header('Cache-Control', 'no-store'); $x->header('X-LiteSpeed-Cache-Control', 'no-cache'); return $x; };
        if (!$s['enabled']) return $r(['ok' => true, 'enabled' => false]);
        $eid = absint($req->get_param('event_id'));
        $test = class_exists('TIX_Awareness_Test');
        if ($eid && (get_post_type($eid) !== 'event' || get_post_status($eid) !== 'publish') && !($test && TIX_Awareness_Test::visible($eid))) $eid = 0;
        // Test-Modus: Tester sehen das laufende Test-Event vor echten Events
        if (!$eid && $test) $eid = TIX_Awareness_Test::running_for_current_user();
        if (!$eid) $eid = self::current_event();
        $event = null; $aw = null;
        $flags = class_exists('TIX_Awareness_Live') ? TIX_Awareness_Live::flags() : ['report_enabled' => false, 'alarm_live' => false];
        $status = ['active' => false, 'on_duty' => false, 'report_enabled' => (bool) $flags['report_enabled']];
        if ($eid) {
            $w = class_exists('TIX_Music') ? TIX_Music::event_window($eid) : null;
            $event = [
                'id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'),
                'date' => (string) get_post_meta($eid, '_tix_date_start', true), 'location' => (string) get_post_meta($eid, '_tix_location', true),
                'starts_at' => $w ? gmdate('Y-m-d H:i', $w['start']) : null, 'ends_at' => $w ? gmdate('Y-m-d H:i', $w['end']) : null,
                'test' => $test && TIX_Awareness_Test::is_test_event($eid),
            ];
            $aw = class_exists('TIX_Awareness') ? TIX_Awareness::public_info($eid) : null;
            if (class_exists('TIX_Awareness_Live')) {
                $active = TIX_Awareness_Live::alarm_possible($eid);
                $status = ['active' => $active, 'on_duty' => $active && (bool) TIX_Awareness_Live::on_duty_users($eid), 'report_enabled' => (bool) $flags['report_enabled']];
            }
        }
        return $r(['ok' => true, 'enabled' => true, 'label' => $s['label'], 'icon' => 'lifebuoy', 'text' => $s['text'], 'numbers' => $s['numbers'],
            'event' => $event, 'awareness' => $aw ?: null, 'status' => $status,
            'hotline' => ['label' => 'Hilfetelefon Gewalt gegen Frauen', 'number' => '116 016', 'url' => 'https://www.hilfetelefon.de']]);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/app/sos', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_live'], 'permission_callback' => '__return_true']);
    }

    // ── Admin (Seite „Awareness“) ──

    public static function render_admin() {
        $s = self::settings();
        $nums = $s['numbers']; while (count($nums) < 4) $nums[] = ['label' => '', 'number' => ''];
        ?>
        <hr style="margin:28px 0 8px;">
        <h2>SOS in der App</h2>
        <p class="description">Menüpunkt mit Rettungsring unten im Tab-Menü der App (Club- und Festival-Profil). Die SOS-Seite zeigt die Notrufnummern, euren Text und – wenn gerade ein Event läuft – dessen Awareness-Angaben und „Hilfe vor Ort“. Ohne neuen App-Build schaltbar.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tix_app_sos'); ?><input type="hidden" name="action" value="tix_app_sos">
            <table class="form-table" role="presentation">
                <tr><th>Menüpunkt</th><td><label><input type="checkbox" name="sos[enabled]" value="1" <?php checked($s['enabled']); ?>> In der App anzeigen</label></td></tr>
                <tr><th><label for="tix-sos-label">Beschriftung</label></th><td><input type="text" id="tix-sos-label" name="sos[label]" maxlength="20" value="<?php echo esc_attr($s['label']); ?>" placeholder="SOS"> <span class="description">z. B. „SOS“ oder „Hilfe“</span></td></tr>
                <tr><th><label for="tix-sos-text">Text</label></th><td><textarea id="tix-sos-text" name="sos[text]" rows="3" class="large-text" maxlength="600" placeholder="z. B. Unser Awareness-Team erkennst du an den lila Westen. Sprich uns jederzeit an."><?php echo esc_textarea($s['text']); ?></textarea></td></tr>
                <tr><th>Notrufnummern</th><td><?php foreach ($nums as $i => $n) : ?>
                    <p><input type="text" name="sos[numbers][<?php echo $i; ?>][label]" value="<?php echo esc_attr($n['label']); ?>" placeholder="Bezeichnung" style="width:220px"> <input type="text" name="sos[numbers][<?php echo $i; ?>][number]" value="<?php echo esc_attr($n['number']); ?>" placeholder="Nummer" style="width:140px"></p>
                <?php endforeach; ?><p class="description">Leer = Polizei 110 und Feuerwehr &amp; Rettungsdienst 112.</p></td></tr>
            </table>
            <p><button class="button button-primary">SOS speichern</button></p>
        </form>
        <?php
    }

    public static function save_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_app_sos');
        $in = (array) wp_unslash($_POST['sos'] ?? []);
        $in['enabled'] = !empty($in['enabled']);
        $in['numbers'] = array_values((array) ($in['numbers'] ?? []));
        self::save($in);
        wp_safe_redirect(admin_url('admin.php?page=tix-awareness&saved=1'));
        exit;
    }
}
