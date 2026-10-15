<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Awareness – Modul „Awareness“ (Konzept tixomat-apps/docs/konzept-awareness.md, Leitlinie 10:
 * kostenlos in allen Paketen). Stufe 1: Angaben am Event (B1), Etikett/Filter (B2), Hausregeln (B3).
 *
 * Felder: {team, sign, point, codeword, areas[], drink_tests, taxi, rules, alarm_enabled}
 * Quellen (erste zutreffende gilt):
 *   1. Event: `_tix_awareness_mode` = custom → `_tix_awareness`; = off → keine Angaben
 *   2. Veranstalter des Events (Vorgabe): `_tix_awareness` am tix_organizer mit enabled = 1
 *   3. Seite (Ein-Veranstalter-Betrieb, z. B. KitchenKlub): Option `tix_awareness` mit enabled = 1 (Vorgabe AUS)
 * Ausgabe nur mit Inhalt (Team, Getränke-Tests oder Text). REST: `awareness` im Event-Detail,
 * `awareness_team` in der Liste, Filter `?awareness=1` (TIX_Public_Events). Event-Verteilung schickt die
 * wirksamen Angaben als `awareness` mit; der Empfänger speichert sie am Event (custom).
 * REST (1.38.440): GET/POST /awareness/settings[?event_id=|?organizer=] – Angaben ohne WP-Admin pflegen.
 */
class TIX_Awareness {

    const OPTION   = 'tix_awareness';
    const META     = '_tix_awareness';
    const META_MODE = '_tix_awareness_mode';
    const DEFAULT_AREAS = ['Tanzfläche', 'Bar', 'Toiletten', 'Eingang', 'Draußen', 'Weiß nicht'];
    const MAX_AREAS = 12;
    const SIGN_MAX  = 160;

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('save_post_event', [__CLASS__, 'save_event'], 20, 2);
        add_action('save_post_tix_organizer', [__CLASS__, 'save_organizer_box'], 20, 2);
        add_action('admin_menu', [__CLASS__, 'menu'], 42);
        add_action('admin_post_tix_awareness_site', [__CLASS__, 'save_site']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /** Felder bereinigen (Text kurz, Bereiche 1–12 Einträge). */
    public static function sanitize($in) {
        $in = is_array($in) ? $in : [];
        $areas = $in['areas'] ?? self::DEFAULT_AREAS;
        if (is_string($areas)) $areas = preg_split('/\r\n|\r|\n|,/', $areas);
        $areas = array_values(array_unique(array_filter(array_map(function ($a) { return mb_substr(sanitize_text_field((string) $a), 0, 40); }, (array) $areas), 'strlen')));
        $t = function ($k, $max) use ($in) { return mb_substr(sanitize_text_field((string) ($in[$k] ?? '')), 0, $max); };
        return [
            'enabled'       => !empty($in['enabled']),
            'team'          => !empty($in['team']),
            'sign'          => $t('sign', self::SIGN_MAX),
            'point'         => $t('point', 120),
            'codeword'      => $t('codeword', 120),
            'areas'         => array_slice($areas ?: self::DEFAULT_AREAS, 0, self::MAX_AREAS),
            'drink_tests'   => !empty($in['drink_tests']),
            'taxi'          => $t('taxi', 60),
            'rules'         => mb_substr(sanitize_textarea_field((string) ($in['rules'] ?? '')), 0, 1000),
            'alarm_enabled' => !empty($in['alarm_enabled']),
        ];
    }

    public static function has_content(array $a) {
        return !empty($a['team']) || !empty($a['drink_tests']) || $a['sign'] !== '' || $a['point'] !== '' || $a['codeword'] !== '' || $a['taxi'] !== '' || $a['rules'] !== '';
    }

    public static function site() {
        return self::sanitize(get_option(self::OPTION, []));
    }

    public static function organizer($oid) {
        return self::sanitize(get_post_meta(intval($oid), self::META, true));
    }

    public static function event_mode($event_id) {
        $m = (string) get_post_meta(intval($event_id), self::META_MODE, true);
        return in_array($m, ['custom', 'off'], true) ? $m : 'inherit';
    }

    /** Wirksame Angaben eines Events (bereinigt, ohne `enabled`) oder null. */
    public static function effective($event_id) {
        $event_id = intval($event_id);
        $mode = self::event_mode($event_id);
        if ($mode === 'off') return null;
        if ($mode === 'custom') {
            $a = self::sanitize(get_post_meta($event_id, self::META, true));
        } else {
            $a = null;
            $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
            if ($oid) {
                $o = self::organizer($oid);
                if ($o['enabled']) $a = $o;
            }
            if ($a === null) {
                $s = self::site();
                if ($s['enabled']) $a = $s;
            }
            if ($a === null) return null;
        }
        if (!self::has_content($a)) return null;
        unset($a['enabled']);
        return $a;
    }

    /** Für /public/events-Detail. */
    public static function public_info($event_id) {
        return self::effective($event_id);
    }

    public static function has_team($event_id) {
        $a = self::effective($event_id);
        return $a !== null && !empty($a['team']);
    }

    // ── Formular-Bausteine ──

    /** Eingabefelder; $name = Formular-Präfix, z. B. tix_awareness. */
    public static function fields_html($name, array $a, $with_enabled = true, $enabled_label = 'Awareness-Angaben anzeigen') {
        $n = function ($k) use ($name) { return esc_attr($name . '[' . $k . ']'); };
        ob_start(); ?>
        <div class="tix-awareness-fields" style="display:grid;gap:10px;max-width:640px;">
            <?php if ($with_enabled) : ?>
            <label><input type="checkbox" name="<?php echo $n('enabled'); ?>" value="1" <?php checked(!empty($a['enabled'])); ?>> <strong><?php echo esc_html($enabled_label); ?></strong></label>
            <?php endif; ?>
            <label><input type="checkbox" name="<?php echo $n('team'); ?>" value="1" <?php checked(!empty($a['team'])); ?>> Es gibt ein Awareness-Team</label>
            <label>Woran erkennt man das Team?<br><input type="text" name="<?php echo $n('sign'); ?>" value="<?php echo esc_attr($a['sign']); ?>" class="regular-text" placeholder="z. B. lila Shirts"></label>
            <label>Wo ist der Awareness-Punkt?<br><input type="text" name="<?php echo $n('point'); ?>" value="<?php echo esc_attr($a['point']); ?>" class="regular-text" placeholder="z. B. neben der Garderobe"></label>
            <label>Codewort<br><input type="text" name="<?php echo $n('codeword'); ?>" value="<?php echo esc_attr($a['codeword']); ?>" class="regular-text" placeholder="z. B. „Frag an der Bar nach Luisa“"></label>
            <label><input type="checkbox" name="<?php echo $n('drink_tests'); ?>" value="1" <?php checked(!empty($a['drink_tests'])); ?>> Getränke-Tests am Awareness-Punkt erhältlich</label>
            <label>Taxi-Nummer (optional)<br><input type="text" name="<?php echo $n('taxi'); ?>" value="<?php echo esc_attr($a['taxi']); ?>" class="regular-text"></label>
            <label>Hausregeln (kurz)<br><textarea name="<?php echo $n('rules'); ?>" rows="3" class="large-text"><?php echo esc_textarea($a['rules']); ?></textarea></label>
            <label>Bereiche für „Hilfe vor Ort“ (einer je Zeile)<br><textarea name="<?php echo $n('areas'); ?>" rows="3" class="large-text"><?php echo esc_textarea(implode("\n", $a['areas'])); ?></textarea></label>
            <label><input type="checkbox" name="<?php echo $n('alarm_enabled'); ?>" value="1" <?php checked(!empty($a['alarm_enabled'])); ?>> Hilfe-Knopf „Hilfe vor Ort“ aktiv (nur wenn das Team im Dienst ist)</label>
            <p class="description" style="margin:0;">Angabe des Veranstalters. Die App ersetzt keinen Notruf – bei Gefahr sofort 110.</p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // ── Event (Überschreiben) ──

    public static function add_metabox() {
        add_meta_box('tix_awareness', 'Awareness', [__CLASS__, 'render_metabox'], 'event', 'normal', 'default');
        // Vorgabe je Veranstalter (Mehr-Veranstalter-Modus); im Ein-Veranstalter-Betrieb gilt Tixomat → Awareness
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            add_meta_box('tix_awareness_org', 'Awareness (Vorgabe für deine Events)', [__CLASS__, 'render_organizer_box'], 'tix_organizer', 'normal', 'default');
        }
    }

    public static function render_organizer_box($post) {
        wp_nonce_field('tix_awareness_org', '_tix_awareness_org_nonce');
        echo self::fields_html('tix_awareness_org', self::organizer($post->ID), true, 'Awareness-Angaben bei meinen Events anzeigen');
        echo '<p class="description">Jedes Event übernimmt diese Vorgabe und kann sie im Event-Editor überschreiben.</p>';
    }

    public static function save_organizer_box($post_id, $post) {
        if (!isset($_POST['_tix_awareness_org_nonce']) || !wp_verify_nonce($_POST['_tix_awareness_org_nonce'], 'tix_awareness_org')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        self::save_organizer($post_id, wp_unslash((array) ($_POST['tix_awareness_org'] ?? [])));
    }

    public static function render_metabox($post) {
        wp_nonce_field('tix_awareness_event', '_tix_awareness_nonce');
        $mode = self::event_mode($post->ID);
        $a = self::sanitize(get_post_meta($post->ID, self::META, true));
        $oid = intval(get_post_meta($post->ID, '_tix_organizer_id', true));
        $src = ($oid && self::organizer($oid)['enabled']) ? 'Vorgabe des Veranstalters' : (self::site()['enabled'] ? 'Vorgabe der Seite' : 'keine Vorgabe');
        ?>
        <p>
            <label><input type="radio" name="tix_awareness_mode" value="inherit" <?php checked($mode, 'inherit'); ?>> Vorgabe übernehmen (<?php echo esc_html($src); ?>)</label><br>
            <label><input type="radio" name="tix_awareness_mode" value="custom" <?php checked($mode, 'custom'); ?>> Für dieses Event eigene Angaben</label><br>
            <label><input type="radio" name="tix_awareness_mode" value="off" <?php checked($mode, 'off'); ?>> Bei diesem Event keine Awareness-Angaben</label>
        </p>
        <?php echo self::fields_html('tix_awareness', $a, false); ?>
        <?php
    }

    public static function save_event($post_id, $post) {
        if (!isset($_POST['_tix_awareness_nonce']) || !wp_verify_nonce($_POST['_tix_awareness_nonce'], 'tix_awareness_event')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        $mode = sanitize_key($_POST['tix_awareness_mode'] ?? 'inherit');
        if ($mode === 'inherit') {
            delete_post_meta($post_id, self::META_MODE);
        } else {
            update_post_meta($post_id, self::META_MODE, $mode === 'off' ? 'off' : 'custom');
        }
        if ($mode === 'custom') {
            $a = self::sanitize(wp_unslash((array) ($_POST['tix_awareness'] ?? [])));
            $a['enabled'] = true;
            update_post_meta($post_id, self::META, $a);
        }
        self::flush();
    }

    /** Veranstalter-Vorgabe speichern (Formular oder REST). */
    public static function save_organizer($oid, $in) {
        $a = self::sanitize($in);
        update_post_meta(intval($oid), self::META, $a);
        self::flush();
        return $a;
    }

    public static function flush() {
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }

    // ── REST: Angaben ohne WP-Admin pflegen (App, evendis Studio, evendis-App) ──

    const FIELDS = ['enabled', 'team', 'sign', 'point', 'codeword', 'areas', 'drink_tests', 'taxi', 'rules', 'alarm_enabled'];

    public static function register_routes() {
        register_rest_route('tixomat/v1', '/awareness/settings', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'rest_settings'],      'permission_callback' => [__CLASS__, 'perm_settings']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save_settings'], 'permission_callback' => [__CLASS__, 'perm_settings']],
        ]);
    }

    /** Staff (Admin, Veranstalter, Mitarbeiter) oder Awareness-Team; Mehr-Veranstalter-Modus über TIX_App_Scope. */
    public static function perm_settings(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        $staff = class_exists('TIX_REST_API') && TIX_REST_API::is_staff_user($u);
        if (!$staff && !$u->has_cap('tix_app_awareness')) return new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
        if (!$u->has_cap('manage_options') && class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            $r = TIX_App_Scope::require_organizer();
            if (is_wp_error($r)) return $r;
        }
        return true;
    }

    /**
     * Ziel der Anfrage: ['scope' => event|organizer|site, 'id' => …] oder WP_Error.
     * $write: Vorgaben (Veranstalter/Seite) ändern nur Admin bzw. Inhaber/Team-Admin; Events wie im Event-Editor.
     */
    private static function settings_target(WP_REST_Request $req, $write) {
        $u = wp_get_current_user();
        $admin = $u->has_cap('manage_options');
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        $staff = class_exists('TIX_REST_API') && TIX_REST_API::is_staff_user($u);
        $eid = absint($req->get_param('event_id'));
        if ($eid) {
            if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
            $ok = $admin || (class_exists('TIX_REST_API') ? TIX_REST_API::user_can_access_event($eid) : !$multi);
            if (!$ok) {
                return new WP_Error('rest_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
            }
            if ($write && !$staff) return new WP_Error('rest_forbidden', 'Nur Veranstalter und Mitarbeiter können Events ändern.', ['status' => 403]);
            return ['scope' => 'event', 'id' => $eid];
        }
        if ($multi) {
            $oid = $admin ? absint($req->get_param('organizer')) : TIX_App_Scope::organizer_id_for_user();
            if ($admin && $oid && get_post_type($oid) !== 'tix_organizer') return new WP_Error('not_found', 'Veranstalter nicht gefunden.', ['status' => 404]);
            if ($oid) {
                if ($write && !$admin && !TIX_App_Scope::is_org_manager()) {
                    return new WP_Error('rest_forbidden', 'Nur der Veranstalter selbst (Inhaber oder Team-Admin) kann die Vorgabe ändern.', ['status' => 403]);
                }
                return ['scope' => 'organizer', 'id' => $oid];
            }
            if (!$admin) return new WP_Error('no_organizer', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        }
        if ($write && !$admin && !$staff) return new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
        return ['scope' => 'site', 'id' => 0];
    }

    private static function settings_payload(array $t) {
        $out = ['ok' => true, 'scope' => $t['scope']];
        if ($t['scope'] === 'event') {
            $a = self::sanitize(get_post_meta($t['id'], self::META, true));
            $out += ['event_id' => intval($t['id']), 'mode' => self::event_mode($t['id'])];
            unset($a['enabled']);
            $out['settings'] = $a;
            $out['effective'] = self::effective($t['id']);
            $oid = intval(get_post_meta($t['id'], '_tix_organizer_id', true));
            $out['source'] = ($oid && self::organizer($oid)['enabled']) ? 'organizer' : (self::site()['enabled'] ? 'site' : 'none');
        } else {
            if ($t['scope'] === 'organizer') $out['organizer_id'] = intval($t['id']);
            $out['settings'] = $t['scope'] === 'organizer' ? self::organizer($t['id']) : self::site();
        }
        $out['default_areas'] = self::DEFAULT_AREAS;
        $out['limits'] = ['areas' => self::MAX_AREAS, 'sign' => self::SIGN_MAX];
        $out['alarm_live'] = class_exists('TIX_Awareness_Live') && TIX_Awareness_Live::flags()['alarm_live'];
        return $out;
    }

    /** GET /awareness/settings[?event_id=|?organizer=] */
    public static function rest_settings(WP_REST_Request $req) {
        $t = self::settings_target($req, false);
        return is_wp_error($t) ? $t : rest_ensure_response(self::settings_payload($t));
    }

    /** POST /awareness/settings {mode?, settings{…} | Felder oben} – nur übergebene Felder ändern sich. */
    public static function rest_save_settings(WP_REST_Request $req) {
        $t = self::settings_target($req, true);
        if (is_wp_error($t)) return $t;
        $body = $req->get_json_params();
        if (!is_array($body)) $body = $req->get_body_params();
        $body = is_array($body) ? $body : [];
        $in = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;
        $in = array_intersect_key($in, array_flip(self::FIELDS));
        if (isset($in['areas'])) {
            $areas = is_string($in['areas']) ? preg_split('/\r\n|\r|\n|,/', $in['areas']) : (array) $in['areas'];
            $areas = array_filter(array_map('trim', array_map('strval', $areas)), 'strlen');
            if (count($areas) > self::MAX_AREAS) return new WP_Error('tix_invalid_field', 'Höchstens ' . self::MAX_AREAS . ' Orte.', ['status' => 400, 'field' => 'areas']);
            $in['areas'] = array_values($areas);
        }
        if (isset($in['sign']) && mb_strlen((string) $in['sign']) > self::SIGN_MAX) {
            return new WP_Error('tix_invalid_field', 'Das Erkennungszeichen darf höchstens ' . self::SIGN_MAX . ' Zeichen lang sein.', ['status' => 400, 'field' => 'sign']);
        }
        if ($t['scope'] === 'event') {
            if (isset($body['mode'])) {
                $m = sanitize_key((string) $body['mode']);
                if (!in_array($m, ['inherit', 'custom', 'off'], true)) return new WP_Error('tix_invalid_field', 'Ungültiger Modus (inherit, custom, off).', ['status' => 400, 'field' => 'mode']);
                if ($m === 'inherit') delete_post_meta($t['id'], self::META_MODE); else update_post_meta($t['id'], self::META_MODE, $m);
            }
            unset($in['enabled']);
            if ($in) {
                $a = self::sanitize(array_merge(self::sanitize(get_post_meta($t['id'], self::META, true)), $in));
                $a['enabled'] = true;
                update_post_meta($t['id'], self::META, $a);
                // Eigene Angaben ohne Modus → eigene Angaben gelten
                if (!isset($body['mode']) && self::event_mode($t['id']) === 'inherit') update_post_meta($t['id'], self::META_MODE, 'custom');
            }
        } elseif ($t['scope'] === 'organizer') {
            self::save_organizer($t['id'], array_merge(self::organizer($t['id']), $in));
        } else {
            update_option(self::OPTION, self::sanitize(array_merge(self::site(), $in)), false);
        }
        self::flush();
        return rest_ensure_response(self::settings_payload($t));
    }

    // ── Seite (Ein-Veranstalter-Betrieb, Rückfall im Mehr-Veranstalter-Modus) ──

    public static function menu() {
        add_submenu_page('tixomat', 'Awareness', 'Awareness', 'manage_options', 'tix-awareness', [__CLASS__, 'render_site']);
    }

    public static function render_site() {
        if (!current_user_can('manage_options')) return;
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        ?>
        <div class="wrap">
            <h1>Awareness</h1>
            <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <p>Unterstützt das Awareness-Team: Angaben auf der Event-Seite (App und Web), Etikett „Awareness-Team“ und Filter. Kostenlos, kein Notruf-Ersatz.
                <?php echo $multi
                    ? 'Mehr-Veranstalter-Modus: Jeder Veranstalter pflegt seine Vorgabe selbst; diese Angaben gelten nur für Events ohne Veranstalter-Vorgabe.'
                    : 'Diese Angaben gelten für alle Events der Seite; jedes Event kann sie im Editor überschreiben oder abschalten.'; ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tix_awareness_site'); ?><input type="hidden" name="action" value="tix_awareness_site">
                <?php echo self::fields_html('tix_awareness', self::site(), true, 'Awareness-Angaben für alle Events anzeigen'); ?>
                <?php $fl = class_exists('TIX_Awareness_Live') ? TIX_Awareness_Live::flags() : ['report_enabled' => false, 'alarm_live' => false]; ?>
                <h2>Freischaltung (für alle Veranstalter dieser Seite)</h2>
                <p><label><input type="checkbox" name="tix_awareness_flags[report_enabled]" value="1" <?php checked($fl['report_enabled']); ?>> <strong>Melden &amp; Feedback</strong> (Vorfall melden, auch anonym; „Hast du dich sicher gefühlt?“) – erst mit Datenschutz-Absatz einschalten</label></p>
                <p><label><input type="checkbox" name="tix_awareness_flags[alarm_live]" value="1" <?php checked($fl['alarm_live']); ?>> <strong>„Hilfe vor Ort“</strong> (stiller Alarm an das Awareness-Team im Dienst) – <strong>erst nach der rechtlichen Prüfung</strong> einschalten</label></p>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
            <?php if (class_exists('TIX_Awareness_Live')) TIX_Awareness_Live::render_admin_alerts(); ?>
            <?php if (class_exists('TIX_Awareness_Test')) TIX_Awareness_Test::render_admin(); ?>
            <?php if (class_exists('TIX_App_Sos')) TIX_App_Sos::render_admin(); ?>
        </div>
        <?php
    }

    public static function save_site() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_awareness_site');
        update_option(self::OPTION, self::sanitize(wp_unslash((array) ($_POST['tix_awareness'] ?? []))), false);
        $fl = (array) ($_POST['tix_awareness_flags'] ?? []);
        update_option('tix_awareness_flags', ['report_enabled' => !empty($fl['report_enabled']) ? 1 : 0, 'alarm_live' => !empty($fl['alarm_live']) ? 1 : 0], false);
        self::flush();
        wp_safe_redirect(admin_url('admin.php?page=tix-awareness&saved=1'));
        exit;
    }
}
