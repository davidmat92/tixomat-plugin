<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Studio – Anbindung des Veranstalter-Dashboards „evendis Studio“ (Next.js, z. B. veranstalter.evendis.de/studio/).
 *
 * Alles hängt an der Option `tix_studio_url` (Basis-Adresse ohne Schrägstrich am Ende). Leer = altes Verhalten.
 *
 * - Einmal-Code (60 s, einmal nutzbar, an den Nutzer gebunden) nach dem WordPress-Login eines Veranstalters bzw.
 *   Team-Kontos und nach der Registrierung auf /veranstalter-werden/ → Weiterleitung auf <studio>/anmelden/?code=…
 * - POST /auth/handoff {code, device} → {success, token, user} wie /auth/login
 * - Experten-Modus (klassische Veranstalter-Seite in wp-admin): feste Rückweg-Leiste „← Zurück zur einfachen Ansicht“
 * - GET /organizer/tasks: offene Aufgaben des Veranstalters für die Studio-Startseite
 */
class TIX_Studio {

    const NS          = 'tixomat/v1';
    const OPTION      = 'tix_studio_url';
    const CODE_PREFIX = 'tix_sho_';
    const CODE_TTL    = 60;

    public static function init() {
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 99, 3);
        add_filter('allowed_redirect_hosts', [__CLASS__, 'allowed_hosts']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_tix_studio_open', [__CLASS__, 'open']);
        // Experten-Modus: Rückweg-Leiste + evendis-Look
        add_filter('admin_body_class', [__CLASS__, 'body_class']);
        add_action('admin_head', [__CLASS__, 'bar_styles']);
        add_action('in_admin_header', [__CLASS__, 'render_bar'], 0);
        add_action('admin_menu', [__CLASS__, 'cleanup_menu'], 1000);
        add_action('admin_bar_menu', [__CLASS__, 'admin_bar_node'], 90);
    }

    // ──────────────────────────────────────────
    //  Grundlagen
    // ──────────────────────────────────────────

    /** Basis-Adresse des Studios ('' = aus). */
    public static function base() {
        $u = trim((string) get_option(self::OPTION, ''));
        if ($u === '' || !preg_match('#^https?://[^/\s]+#i', $u)) return '';
        return untrailingslashit(esc_url_raw($u));
    }

    public static function active() {
        return self::base() !== '';
    }

    /** Studio-Adresse für einen Pfad (z. B. '/events/12/'). */
    public static function url($path = '/') {
        return self::base() . '/' . ltrim((string) $path, '/');
    }

    /** Veranstalter bzw. Team-Konto (kein Admin)? */
    public static function is_studio_user($user = null) {
        $user = $user ?: wp_get_current_user();
        if (!$user || !$user->ID) return false;
        if (user_can($user, 'manage_options')) return false;
        if (in_array('tix_organizer', (array) $user->roles, true)) return true;
        return class_exists('TIX_App_Scope') && (bool) TIX_App_Scope::organizer_post_for_user($user->ID);
    }

    // ──────────────────────────────────────────
    //  Einmal-Code
    // ──────────────────────────────────────────

    /** Code für den Nutzer erzeugen (Klartext nur hier; gespeichert wird der SHA-256). */
    public static function issue_code($user_id, $next = '') {
        global $wpdb;
        $user_id = intval($user_id);
        if (!$user_id) return '';
        $now = time();
        // Abgelaufene Codes wegräumen (Wert = "<uid>:<ablauf>[:<next>]")
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, ':', 2), ':', -1) AS UNSIGNED) < %d",
            $wpdb->esc_like(self::CODE_PREFIX) . '%', $now
        ));
        $code = wp_generate_password(40, false, false);
        $val  = $user_id . ':' . ($now + self::CODE_TTL) . ($next !== '' ? ':' . $next : '');
        $wpdb->insert($wpdb->options, ['option_name' => self::CODE_PREFIX . hash('sha256', $code), 'option_value' => $val, 'autoload' => 'no']);
        return $code;
    }

    /** Code einlösen: liefert ['uid' => …, 'next' => …] oder null. Atomar – nur ein Aufruf gewinnt. */
    public static function consume_code($code) {
        global $wpdb;
        $code = trim((string) $code);
        if ($code === '' || !preg_match('/^[A-Za-z0-9]{20,80}$/', $code)) return null;
        $name = self::CODE_PREFIX . hash('sha256', $code);
        $val  = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($val === null) return null;
        $gone = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name));
        if (intval($gone) !== 1) return null;
        $parts = explode(':', (string) $val, 3);
        $uid = intval($parts[0] ?? 0);
        $exp = intval($parts[1] ?? 0);
        if (!$uid || $exp < time()) return null;
        return ['uid' => $uid, 'next' => (string) ($parts[2] ?? '')];
    }

    /** Erlaubter Folgepfad im Studio (nur relativ, z. B. /studio/events/12/). */
    private static function clean_next($next) {
        $next = trim((string) $next);
        if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || preg_match('/[\s\\\\]/', $next)) return '';
        $base_path = (string) wp_parse_url(self::base(), PHP_URL_PATH);
        if ($base_path !== '' && strpos($next, untrailingslashit($base_path) . '/') !== 0) return '';
        return mb_substr($next, 0, 200);
    }

    /** Anmelde-Adresse im Studio mit frischem Code. */
    public static function handoff_url($user_id, $next = '') {
        if (!self::active()) return '';
        $next = self::clean_next($next);
        $code = self::issue_code($user_id, $next);
        if ($code === '') return '';
        $args = ['code' => $code];
        if ($next !== '') $args['next'] = $next;
        return add_query_arg(array_map('rawurlencode', $args), self::url('/anmelden/'));
    }

    // ──────────────────────────────────────────
    //  Weiterleitung nach Login / Registrierung
    // ──────────────────────────────────────────

    /** Standard-Ziele (kein ausdrücklich verlangtes Ziel) – dann geht es ins Studio. */
    private static function is_default_target($requested) {
        $requested = (string) $requested;
        if ($requested === '') return true;
        $defaults = [admin_url(), admin_url('/'), admin_url('index.php'), admin_url('admin.php?page=tixomat'), admin_url('profile.php')];
        if (class_exists('TIX_Org_Approval')) $defaults[] = admin_url('admin.php?page=' . TIX_Org_Approval::ORG_SLUG);
        return in_array($requested, $defaults, true);
    }

    public static function login_redirect($redirect_to, $requested, $user) {
        if (!self::active() || !$user || is_wp_error($user) || !($user instanceof WP_User)) return $redirect_to;
        if (!self::is_studio_user($user)) return $redirect_to;
        if (!self::is_default_target($requested)) {
            // Ausdrücklich verlangte Seite (z. B. Experten-Modus-Link aus dem Studio) behalten
            $admin_host = (string) wp_parse_url(admin_url(), PHP_URL_HOST);
            if (strpos((string) $requested, admin_url()) === 0 && wp_parse_url((string) $requested, PHP_URL_HOST) === $admin_host) return $requested;
            return $redirect_to;
        }
        $url = self::handoff_url($user->ID);
        return $url !== '' ? $url : $redirect_to;
    }

    public static function allowed_hosts($hosts) {
        $h = self::active() ? (string) wp_parse_url(self::base(), PHP_URL_HOST) : '';
        if ($h !== '') $hosts[] = $h;
        return $hosts;
    }

    /** Ziel nach der Registrierung auf /veranstalter-werden/ ('' = bisheriges Ziel). */
    public static function registration_target($user_id) {
        $u = get_user_by('id', intval($user_id));
        if (!self::active() || !$u || user_can($u, 'manage_options')) return '';
        return self::handoff_url($u->ID);
    }

    /** admin-post.php?action=tix_studio_open[&next=/studio/…] – angemeldet ins Studio wechseln (auch für Admins). */
    public static function open() {
        if (!is_user_logged_in()) auth_redirect();
        if (!self::active()) {
            wp_safe_redirect(admin_url());
            exit;
        }
        $u = wp_get_current_user();
        if (!user_can($u, 'manage_options') && !self::is_studio_user($u)) {
            wp_safe_redirect(self::url('/'));
            exit;
        }
        $url = self::handoff_url($u->ID, (string) wp_unslash($_GET['next'] ?? ''));
        wp_safe_redirect($url !== '' ? $url : self::url('/'));
        exit;
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::NS, '/auth/handoff', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_handoff'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/organizer/tasks', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_tasks'],
            'permission_callback' => [__CLASS__, 'perm_tasks'],
        ]);
    }

    /** POST /auth/handoff {code, device} → {success, token, user[, next]} wie /auth/login. */
    public static function rest_handoff(WP_REST_Request $req) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('handoff', 20, 60)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Versuche. Bitte warte kurz und versuche es dann erneut.', ['status' => 429]);
        }
        $hit  = self::consume_code((string) ($req->get_param('code') ?? ''));
        $user = $hit ? get_user_by('id', $hit['uid']) : null;
        if (!$user) {
            return new WP_Error('tix_handoff_invalid', 'Der Anmelde-Link ist abgelaufen oder wurde schon benutzt. Bitte melde dich erneut an.', ['status' => 401]);
        }
        $token = TIX_REST_API::issue_app_token($user->ID, (string) ($req->get_param('device') ?: 'evendis Studio'));
        $out = [
            'success' => true,
            'token'   => $token,
            'user'    => TIX_REST_API::guest_user_payload($user),
        ];
        if ($hit['next'] !== '') $out['next'] = $hit['next'];
        return rest_ensure_response($out);
    }

    /** Veranstalter des Aufrufs (Admins: ?organizer=<id>). */
    private static function task_org_id(WP_REST_Request $req) {
        if (current_user_can('manage_options')) {
            $oid = intval($req->get_param('organizer'));
            return ($oid && get_post_type($oid) === 'tix_organizer') ? $oid : 0;
        }
        $p = class_exists('TIX_App_Scope') ? TIX_App_Scope::organizer_post_for_user(get_current_user_id()) : null;
        return $p ? intval($p->ID) : 0;
    }

    public static function perm_tasks(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (!class_exists('TIX_App_Scope') || !TIX_App_Scope::multi()) {
            return new WP_Error('tix_not_multi', 'Nur auf Plattformen mit mehreren Veranstaltern verfügbar.', ['status' => 403]);
        }
        if (!self::task_org_id($req)) {
            return current_user_can('manage_options')
                ? new WP_Error('tix_invalid_field', 'Bitte gib den Veranstalter an (?organizer=<id>).', ['status' => 400, 'field' => 'organizer'])
                : new WP_Error('no_organizer', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        }
        return true;
    }

    /** Offene Aufgaben: [{key, title, done, target, hint[, event_id]}]. */
    public static function tasks($oid) {
        $oid = intval($oid);
        $tasks = [];
        $reqs = [];
        if (class_exists('TIX_Org_Approval')) {
            foreach (TIX_Org_Approval::requirements($oid) as $r) $reqs[$r['key']] = $r;
        }

        // Auszahlungsdaten (Rechnungsadresse, Steuer, IBAN)
        $pay_keys = array_values(array_intersect(['name', 'address', 'tax', 'payout'], array_keys($reqs)));
        if ($pay_keys) {
            $open = array_values(array_filter($pay_keys, function ($k) use ($reqs) { return !$reqs[$k]['done']; }));
            $tasks[] = [
                'key' => 'payout', 'title' => 'Auszahlungsdaten hinterlegen', 'done' => !$open, 'target' => 'payout',
                'hint' => $open ? 'Fehlt noch: ' . implode(', ', array_map(function ($k) use ($reqs) { return $reqs[$k]['label']; }, $open)) : '',
            ];
        }

        // Vertrag
        if (isset($reqs['terms'])) {
            $done = (bool) $reqs['terms']['done'];
            if ($done && class_exists('TIX_Contract_Template') && TIX_Contract_Template::active() && TIX_Contract_Template::outdated($oid)) $done = false;
            $tasks[] = ['key' => 'terms', 'title' => 'Vertrag annehmen', 'done' => $done, 'target' => 'terms', 'hint' => $done ? '' : (string) $reqs['terms']['hint']];
        }

        // Profil (Telefon, Logo, Beschreibung)
        $m = function ($k) use ($oid) { return trim((string) get_post_meta($oid, $k, true)); };
        $miss = [];
        if ($m('_tix_org_phone') === '') $miss[] = 'Telefon';
        if (!intval($m('_tix_org_landing_logo_id')) && !intval($m('_tix_org_image_id'))) $miss[] = 'Logo';
        if ($m('_tix_org_landing_description') === '' && $m('_tix_org_description') === '' && $m('_tix_org_short_desc') === '') $miss[] = 'Beschreibung';
        $tasks[] = ['key' => 'profile', 'title' => 'Profil vervollständigen', 'done' => !$miss, 'target' => 'profile', 'hint' => $miss ? 'Fehlt noch: ' . implode(', ', $miss) : ''];

        // Entwürfe (nicht die wegen der Freigabe zurückgehaltenen Events)
        $drafts = class_exists('TIX_App_Scope') ? get_posts([
            'post_type' => 'event', 'post_status' => 'draft', 'posts_per_page' => 5, 'orderby' => 'modified', 'order' => 'DESC',
            'meta_query' => [TIX_App_Scope::event_meta_clause($oid)], 'suppress_filters' => true,
        ]) : [];
        foreach ($drafts as $d) {
            $title = html_entity_decode((string) get_the_title($d), ENT_QUOTES, 'UTF-8');
            $tasks[] = [
                'key' => 'draft:' . intval($d->ID), 'title' => 'Entwurf fertigstellen' . ($title !== '' ? ': ' . $title : ''),
                'done' => false, 'target' => 'event', 'hint' => 'Noch nicht veröffentlicht', 'event_id' => intval($d->ID),
            ];
        }

        // Freigabe des Kontos
        if (class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi()) {
            $st = TIX_Org_Approval::status($oid);
            $titles = [
                'approved' => ['Konto freigegeben', ''],
                'pending'  => ['Wartet auf Freigabe', 'Wir prüfen deine Angaben – du kannst schon alles vorbereiten.'],
                'rejected' => ['Angaben ergänzen und erneut einreichen', 'Dein Konto wurde nicht freigegeben.'],
                'blocked'  => ['Konto gesperrt', 'Bitte wende dich an den Support.'],
            ];
            $t = $titles[$st] ?? $titles['pending'];
            $tasks[] = ['key' => 'approval', 'title' => $t[0], 'done' => $st === 'approved', 'target' => 'approval', 'hint' => $t[1], 'status' => $st];
        }
        return $tasks;
    }

    public static function rest_tasks(WP_REST_Request $req) {
        $oid = self::task_org_id($req);
        $tasks = self::tasks($oid);
        $open = count(array_filter($tasks, function ($t) { return !$t['done']; }));
        return rest_ensure_response(['organizer_id' => $oid, 'open' => $open, 'all_done' => $open === 0, 'tasks' => $tasks]);
    }

    // ──────────────────────────────────────────
    //  Experten-Modus (klassische Seite in wp-admin)
    // ──────────────────────────────────────────

    private static function bar_visible() {
        return is_admin() && self::active() && self::is_studio_user() && !wp_doing_ajax();
    }

    /** Event, aus dem heraus die Leiste aufgerufen wird (0 = keins). */
    private static function current_event_id() {
        $id = 0;
        if (isset($_GET['post'])) $id = intval($_GET['post']);
        elseif (isset($_GET['event_id'])) $id = intval($_GET['event_id']);
        elseif (isset($_GET['event'])) $id = intval($_GET['event']);
        return ($id && get_post_type($id) === 'event') ? $id : 0;
    }

    public static function body_class($classes) {
        return self::bar_visible() ? $classes . ' tix-studio-bar' : $classes;
    }

    public static function cleanup_menu() {
        if (!self::bar_visible()) return;
        // Was Veranstalter nicht brauchen (Veranstalter-Rolle ist schon auf Tixomat, Medien, Profil begrenzt)
        foreach (['index.php', 'edit.php', 'edit-comments.php', 'tools.php', 'themes.php', 'plugins.php', 'options-general.php'] as $slug) {
            remove_menu_page($slug);
        }
    }

    public static function bar_styles() {
        if (!self::bar_visible()) return;
        ?>
<style id="tix-studio-bar-css">
html.wp-toolbar{padding-top:56px!important}
body.tix-studio-bar #wpadminbar,body.tix-studio-bar #screen-meta-links,body.tix-studio-bar #wpfooter{display:none!important}
body.tix-studio-bar .tix-shell-sidebar{top:56px!important;height:calc(100vh - 56px)!important}
body.tix-studio-bar #adminmenuback,body.tix-studio-bar #adminmenuwrap{top:56px}
.tix-studio-topbar{position:fixed;top:0;left:0;right:0;height:56px;z-index:100100;display:flex;align-items:center;gap:16px;padding:0 20px;background:#2C2F3F;color:#fff;font-family:'Figtree','Manrope',system-ui,-apple-system,'Segoe UI',sans-serif;box-shadow:0 2px 12px rgba(44,47,63,.18);box-sizing:border-box}
.tix-studio-topbar *{box-sizing:border-box}
.tix-studio-logo{font-size:21px;font-weight:800;letter-spacing:-.02em;color:#fff;text-decoration:none;line-height:1}
.tix-studio-logo:hover,.tix-studio-logo:focus{color:#fff}
.tix-studio-logo span{color:#FC2F55}
.tix-studio-mode{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#A0A4B4;padding:5px 10px;border:1px solid rgba(255,255,255,.18);border-radius:999px;white-space:nowrap}
.tix-studio-back{margin-left:auto;display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 18px;border-radius:12px;background:#FC2F55;color:#fff!important;font-size:14px;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s}
.tix-studio-back:hover,.tix-studio-back:focus{background:#e0234a;color:#fff;box-shadow:none}
@media (max-width:782px){body.tix-studio-bar.tix-fullscreen #wpbody{padding-top:0}}
@media (max-width:600px){.tix-studio-mode{display:none}.tix-studio-topbar{padding:0 12px;gap:10px}.tix-studio-back{padding:0 12px;font-size:13px}}
</style>
        <?php
    }

    public static function render_bar() {
        if (!self::bar_visible()) return;
        $eid  = self::current_event_id();
        $back = $eid ? self::url('/events/' . $eid . '/') : self::url('/');
        ?>
<div class="tix-studio-topbar" role="banner">
    <a class="tix-studio-logo" href="<?php echo esc_url(self::url('/')); ?>">evendis<span>.</span></a>
    <span class="tix-studio-mode">Experten-Modus</span>
    <a class="tix-studio-back" href="<?php echo esc_url($back); ?>">&larr; Zurück zur einfachen Ansicht</a>
</div>
        <?php
    }

    /** Admins: Eintrag „evendis Studio“ in der Admin-Leiste (angemeldet per Einmal-Code). */
    public static function admin_bar_node($bar) {
        if (!self::active() || !current_user_can('manage_options')) return;
        $bar->add_node([
            'id'    => 'tix-studio',
            'title' => 'evendis Studio',
            'href'  => admin_url('admin-post.php?action=tix_studio_open'),
            'meta'  => ['target' => '_blank'],
        ]);
    }
}
