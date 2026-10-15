<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Mail_Follow – Veranstaltern per E-Mail folgen, ohne Konto (evendis, Konzept veranstalter-shopseite §5).
 * Nur Mehr-Veranstalter-Modus. Schalter Option `tix_mail_follow` → enabled (Vorgabe AUS bis Datenschutz + Go).
 *
 * REST:
 *   GET  /info → `mail_follow {enabled}`
 *   POST /public/organizers/{id}/follow {email, consent: true, share_email?: bool} → immer gleiche Antwort, Double-Opt-in-Mail
 *   GET  /public/organizers/{id}/follow/confirm?token= → Weiterleitung auf /veranstalter/<slug>/?follow=bestaetigt
 *   GET  /public/organizers/{id}/unfollow?token=       → löscht den Eintrag, Weiterleitung ?follow=abgemeldet
 *   GET  /organizer/mail-followers (Inhaber/Team-Admin) → {total, shared: [{email, since}]} – Adressen nur mit Häkchen
 * Mails: neues Event des Veranstalters (veröffentlicht, auch geteilte Partner-Events) → höchstens eine gebündelte Mail
 * am Tag; Nachrichten des Veranstalters an Follower (Filter `tix_organizer_broadcast`). Jede Mail: „Nicht mehr folgen“.
 * Konto mit gleicher Adresse (Registrierung/Anmeldung) → Folgen wird ins Konto übernommen, Mail-Eintrag gelöscht.
 * Einwilligung mit Text + Zeitpunkt (+ IP als HMAC); unbestätigte Einträge nach 7 Tagen gelöscht.
 */
class TIX_Mail_Follow {

    const NS     = 'tixomat/v1';
    const OPTION = 'tix_mail_follow';
    const DB_VER = '1';
    const CRON   = 'tix_mail_follow_cron';
    const META_QUEUE = '_tix_mail_follow_queue';
    const CONSENT_TEXT = 'Ja, ich möchte per E-Mail erfahren, wenn %s neue Events veröffentlicht oder Neuigkeiten an Follower schickt. Abmeldung jederzeit über den Link in jeder Mail.';
    const SHARE_TEXT   = 'Meine E-Mail-Adresse darf %s für eigene Infos nutzen.';

    public static $sending = false;

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function enabled() {
        $o = get_option(self::OPTION, []);
        return self::multi() && is_array($o) && !empty($o['enabled']);
    }

    public static function init() {
        if (!self::multi()) return;
        add_action('init', [__CLASS__, 'maybe_install'], 8);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('transition_post_status', [__CLASS__, 'on_publish'], 20, 3);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Mail_Follow::CRON)) wp_schedule_event(time() + 900, 'hourly', TIX_Mail_Follow::CRON);
        }, 24);
        add_filter('tix_organizer_broadcast', [__CLASS__, 'on_broadcast'], 10, 6);
        add_action('user_register', [__CLASS__, 'adopt_for_user'], 20, 1);
        add_action('wp_login', function ($login, $user) { TIX_Mail_Follow::adopt_for_user($user->ID); }, 20, 2);
        add_filter('tix_email_tracking_enabled', function ($on) { return TIX_Mail_Follow::$sending ? false : $on; });
        add_action('admin_menu', [__CLASS__, 'menu'], 44);
        add_action('admin_post_tix_mail_follow', [__CLASS__, 'save_admin']);
    }

    private static function t() {
        global $wpdb;
        return $wpdb->prefix . 'tix_mail_followers';
    }

    public static function maybe_install() {
        if (get_option('tix_mail_follow_db') === self::DB_VER) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::t() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            organizer_id bigint(20) unsigned NOT NULL,
            email varchar(190) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            token varchar(64) NOT NULL,
            share_email tinyint(1) NOT NULL DEFAULT 0,
            consent_text text NOT NULL,
            consent_at datetime NOT NULL,
            consent_ip varchar(64) NOT NULL DEFAULT '',
            confirmed_at datetime NULL,
            last_notified datetime NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY org_email (organizer_id, email),
            KEY token (token)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_mail_follow_db', self::DB_VER, false);
    }

    /** Bestätigte Mail-Follower; mit $since (Ortszeit 'Y-m-d H:i:s') nur die seitdem bestätigten. */
    public static function count($oid, $since = '') {
        global $wpdb;
        if (get_option('tix_mail_follow_db') !== self::DB_VER) return 0;
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM " . self::t() . " WHERE organizer_id = %d AND status = 'active'", $oid);
        if ($since !== '') $sql .= $wpdb->prepare(" AND confirmed_at >= %s", $since);
        return intval($wpdb->get_var($sql));
    }

    private static function name($oid) {
        return html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
    }

    private static function page_url($oid) {
        $base = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::base() : '';
        $slug = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : (string) get_post_field('post_name', $oid);
        return ($base !== '' ? $base : untrailingslashit(home_url())) . '/veranstalter/' . $slug . '/';
    }

    private static function link($oid, $action, $token) {
        $path = $action === 'confirm' ? '/follow/confirm' : '/unfollow';
        return add_query_arg('token', rawurlencode($token), rest_url(self::NS . '/public/organizers/' . intval($oid) . $path));
    }

    private static function mail($to, $subject, $body, $headers = []) {
        self::$sending = true;
        try { return wp_mail($to, $subject, $body, $headers); } finally { self::$sending = false; }
    }

    private static function organizer_ok($oid) {
        if (get_post_type($oid) !== 'tix_organizer' || get_post_status($oid) !== 'publish') return false;
        return !class_exists('TIX_Org_Approval') || TIX_Org_Approval::is_public($oid);
    }

    public static function register_routes() {
        $pub = ['permission_callback' => '__return_true'];
        register_rest_route(self::NS, '/public/organizers/(?P<id>\d+)/follow', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_follow']] + $pub);
        register_rest_route(self::NS, '/public/organizers/(?P<id>\d+)/follow/confirm', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_confirm']] + $pub);
        register_rest_route(self::NS, '/public/organizers/(?P<id>\d+)/unfollow', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_unfollow']] + $pub);
        register_rest_route(self::NS, '/organizer/mail-followers', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => ['TIX_App_Scope', 'check_manager']]);
    }

    public static function rest_follow(WP_REST_Request $req) {
        global $wpdb;
        if (!self::enabled()) return new WP_Error('tix_follow_mail_off', 'Folgen per E-Mail ist noch nicht verfügbar.', ['status' => 404]);
        $oid = intval($req['id']);
        if (!self::organizer_ok($oid)) return new WP_Error('tix_organizer', 'Veranstalter nicht gefunden.', ['status' => 404]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('mail_follow', 10, 3600)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte später erneut versuchen.', ['status' => 429]);
        }
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if (!is_email($email)) return new WP_Error('invalid_email', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        if (!filter_var($req->get_param('consent'), FILTER_VALIDATE_BOOLEAN)) return new WP_Error('tix_consent_required', 'Bitte bestätige die Einwilligung.', ['status' => 400, 'field' => 'consent']);
        $share = filter_var($req->get_param('share_email'), FILTER_VALIDATE_BOOLEAN);
        self::maybe_install();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE organizer_id = %d AND email = %s", $oid, $email));
        if (!$row || $row->status !== 'active') {
            $name = self::name($oid);
            $text = sprintf(self::CONSENT_TEXT, $name) . ($share ? ' ' . sprintf(self::SHARE_TEXT, $name) : '');
            $token = ($row && $row->status === 'pending') ? (string) $row->token : wp_generate_password(40, false, false);
            $ip = class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $data = ['status' => 'pending', 'token' => $token, 'share_email' => $share ? 1 : 0, 'consent_text' => $text, 'consent_at' => current_time('mysql'), 'consent_ip' => $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : ''];
            if ($row) $wpdb->update(self::t(), $data, ['id' => $row->id]);
            else $wpdb->insert(self::t(), $data + ['organizer_id' => $oid, 'email' => $email, 'created' => current_time('mysql')]);
            self::mail($email, 'Bitte bestätige: ' . $name . ' folgen',
                "Hallo,\n\nbitte bestätige, dass du {$name} per E-Mail folgen möchtest:\n" . self::link($oid, 'confirm', $token)
                . "\n\nOhne Bestätigung schicken wir dir nichts.\n\n" . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        }
        return rest_ensure_response(['ok' => true, 'message' => 'Fast geschafft: Bitte bestätige über den Link in der E-Mail.']);
    }

    private static function redirect($oid, $state) {
        wp_redirect(add_query_arg('follow', $state, self::page_url($oid)), 302, 'Tixomat');
        exit;
    }

    private static function by_token($oid, $token) {
        global $wpdb;
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
        if (strlen($token) < 20 || get_option('tix_mail_follow_db') !== self::DB_VER) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE organizer_id = %d AND token = %s", $oid, $token));
    }

    public static function rest_confirm(WP_REST_Request $req) {
        global $wpdb;
        $oid = intval($req['id']);
        $row = self::by_token($oid, $req->get_param('token'));
        if (!$row) return self::redirect($oid, 'ungueltig');
        if ($row->status === 'pending') {
            $wpdb->update(self::t(), ['status' => 'active', 'confirmed_at' => current_time('mysql'), 'token' => wp_generate_password(40, false, false)], ['id' => $row->id]);
            // Gibt es schon ein Konto mit der Adresse → gleich übernehmen
            $u = get_user_by('email', $row->email);
            if ($u) self::adopt_for_user($u->ID);
        }
        return self::redirect($oid, 'bestaetigt');
    }

    public static function rest_unfollow(WP_REST_Request $req) {
        global $wpdb;
        $oid = intval($req['id']);
        $row = self::by_token($oid, $req->get_param('token'));
        if ($row) $wpdb->delete(self::t(), ['id' => $row->id]); // Löschen beim Abmelden
        return self::redirect($oid, 'abgemeldet');
    }

    /** Bestätigtes Mail-Folgen ins Konto übernehmen (gleiche Adresse). */
    public static function adopt_for_user($user_id) {
        global $wpdb;
        $u = get_user_by('id', $user_id);
        if (!$u || get_option('tix_mail_follow_db') !== self::DB_VER) return;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE email = %s AND status = 'active'", strtolower((string) $u->user_email)));
        if (!$rows) return;
        $list = get_user_meta($user_id, '_tix_app_following', true);
        $list = is_array($list) ? array_map('intval', $list) : [];
        foreach ($rows as $r) {
            if (!in_array(intval($r->organizer_id), $list, true)) $list[] = intval($r->organizer_id);
            $wpdb->delete(self::t(), ['id' => $r->id]);
        }
        update_user_meta($user_id, '_tix_app_following', array_values($list));
    }

    // ── Benachrichtigung ──

    /** Veröffentlicht → vormerken; Veranstalter erst im Cron lesen (Admin-Metabox und Verteilung setzen `_tix_organizer_id` nach dem Statuswechsel). */
    public static function on_publish($new, $old, $post) {
        if ($new !== 'publish' || $old === 'publish' || !$post || $post->post_type !== 'event' || !self::enabled()) return;
        $q = get_option('tix_mail_follow_new', []);
        $q = is_array($q) ? $q : [];
        if (!in_array(intval($post->ID), $q, true)) $q[] = intval($post->ID);
        update_option('tix_mail_follow_new', array_slice($q, -500), false);
    }

    /** Vorgemerkte Events dem Veranstalter zuordnen (auch geteilte Partner-Events), Gutschein-Systemevents nie. */
    public static function distribute() {
        $q = get_option('tix_mail_follow_new', []);
        if (!is_array($q) || !$q) return;
        delete_option('tix_mail_follow_new');
        foreach ($q as $eid) {
            $eid = intval($eid);
            if (get_post_status($eid) !== 'publish' || get_post_meta($eid, '_tix_system_giftcard_event', true) || get_post_meta($eid, '_tix_system_wallet_event', true)) continue;
            $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
            if (!$oid) continue;
            $list = get_post_meta($oid, self::META_QUEUE, true);
            $list = is_array($list) ? $list : [];
            if (!in_array($eid, $list, true)) $list[] = $eid;
            update_post_meta($oid, self::META_QUEUE, array_slice($list, -30));
        }
    }

    /** Stündlich: je Veranstalter höchstens eine gebündelte Mail am Tag. */
    public static function cron() {
        global $wpdb;
        if (get_option('tix_mail_follow_db') === self::DB_VER) {
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::t() . " WHERE status = 'pending' AND consent_at < %s", gmdate('Y-m-d H:i:s', current_time('timestamp') - 7 * DAY_IN_SECONDS)));
        }
        if (!self::enabled()) { delete_option('tix_mail_follow_new'); return 0; }
        self::distribute();
        $sent = 0;
        foreach (get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 500, 'fields' => 'ids', 'meta_key' => self::META_QUEUE]) as $oid) {
            $sent += self::send_digest(intval($oid));
        }
        return $sent;
    }

    public static function send_digest($oid, $force = false) {
        global $wpdb;
        $q = get_post_meta($oid, self::META_QUEUE, true);
        $q = is_array($q) ? $q : [];
        $events = array_values(array_filter($q, function ($id) { return get_post_status($id) === 'publish'; }));
        if (!$events) { delete_post_meta($oid, self::META_QUEUE); return 0; }
        $today = current_time('Y-m-d');
        if (!$force && (string) get_post_meta($oid, '_tix_mail_follow_last', true) === $today) return 0;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE organizer_id = %d AND status = 'active'", $oid));
        delete_post_meta($oid, self::META_QUEUE);
        update_post_meta($oid, '_tix_mail_follow_last', $today);
        $name = self::name($oid);
        $list = '';
        foreach ($events as $eid) {
            $d = (string) get_post_meta($eid, '_tix_date_start', true);
            $url = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to((string) get_permalink($eid)) : (string) get_permalink($eid);
            $list .= '<li style="margin:8px 0;"><a href="' . esc_url($url) . '" style="color:#111;font-weight:600;">' . esc_html(get_the_title($eid)) . '</a>' . ($d ? ' <span style="color:#666;">· ' . esc_html(date_i18n('D, d.m.Y', strtotime($d))) . '</span>' : '') . '</li>';
        }
        $n = 0;
        foreach ((array) $rows as $r) {
            $unsub = self::link($oid, 'unfollow', $r->token);
            $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:16px;">'
                . '<h1 style="font-size:19px;">' . esc_html(count($events) === 1 ? 'Neues Event von ' . $name : 'Neue Events von ' . $name) . '</h1><ul style="padding-left:18px;">' . $list . '</ul>'
                . '<p><a href="' . esc_url(self::page_url($oid)) . '">Alle Events von ' . esc_html($name) . '</a></p>'
                . '<p style="color:#888;font-size:12px;margin-top:24px;">Du folgst ' . esc_html($name) . ' per E-Mail. <a href="' . esc_url($unsub) . '" style="color:#888;">Nicht mehr folgen</a></p></body></html>';
            if (self::mail($r->email, count($events) === 1 ? 'Neues Event von ' . $name : 'Neue Events von ' . $name, $html,
                ['Content-Type: text/html; charset=UTF-8', 'List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'])) $n++;
            $wpdb->update(self::t(), ['last_notified' => current_time('mysql')], ['id' => $r->id]);
        }
        return $n;
    }

    /** Nachricht des Veranstalters an Follower → auch an Mail-Follower. */
    public static function on_broadcast($extra, $oid, $title, $body, $event_id, $target) {
        global $wpdb;
        if ($target !== 'followers' || !self::enabled() || get_option('tix_mail_follow_db') !== self::DB_VER) return $extra;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE organizer_id = %d AND status = 'active'", $oid));
        foreach ((array) $rows as $r) {
            $unsub = self::link($oid, 'unfollow', $r->token);
            $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:16px;">'
                . '<h1 style="font-size:19px;">' . esc_html($title) . '</h1><p>' . nl2br(esc_html($body)) . '</p>'
                . '<p><a href="' . esc_url(self::page_url($oid)) . '">Zu ' . esc_html(self::name($oid)) . '</a></p>'
                . '<p style="color:#888;font-size:12px;margin-top:24px;"><a href="' . esc_url($unsub) . '" style="color:#888;">Nicht mehr folgen</a></p></body></html>';
            if (self::mail($r->email, $title, $html, ['Content-Type: text/html; charset=UTF-8', 'List-Unsubscribe: <' . $unsub . '>'])) $extra++;
        }
        return $extra;
    }

    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        $oid = TIX_App_Scope::organizer_id_for_user();
        if (!$oid) return new WP_Error('tix_no_organizer', 'Kein Veranstalter-Profil gefunden.', ['status' => 404]);
        $shared = [];
        if (get_option('tix_mail_follow_db') === self::DB_VER) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT email, confirmed_at FROM " . self::t() . " WHERE organizer_id = %d AND status = 'active' AND share_email = 1 ORDER BY confirmed_at DESC", $oid)) as $r) {
                $shared[] = ['email' => (string) $r->email, 'since' => (string) $r->confirmed_at];
            }
        }
        return rest_ensure_response(['ok' => true, 'total' => self::count($oid), 'shared' => $shared]);
    }

    // ── Admin ──

    public static function menu() {
        add_submenu_page('tixomat', 'Folgen per E-Mail', 'Folgen per E-Mail', 'manage_options', 'tix-mail-follow', [__CLASS__, 'render']);
    }

    public static function save_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_mail_follow');
        update_option(self::OPTION, ['enabled' => !empty($_POST['enabled']) ? 1 : 0], false);
        wp_safe_redirect(admin_url('admin.php?page=tix-mail-follow&saved=1'));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        self::maybe_install();
        $active = intval($wpdb->get_var("SELECT COUNT(*) FROM " . self::t() . " WHERE status = 'active'"));
        ?>
        <div class="wrap"><h1>Folgen per E-Mail</h1>
            <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <p>Gäste folgen Veranstaltern ohne Konto per E-Mail (Double-Opt-in). Bei neuen Events höchstens eine gebündelte Mail am Tag, Abmelden mit einem Klick. Adressen sieht ein Veranstalter nur mit dem Häkchen des Gasts. Bestätigt: <strong><?php echo $active; ?></strong></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tix_mail_follow'); ?><input type="hidden" name="action" value="tix_mail_follow">
                <p><label><input type="checkbox" name="enabled" value="1" <?php checked(self::enabled()); ?>> <strong>Folgen per E-Mail anbieten</strong> – erst mit Datenschutz-Absatz und Go des Users</label></p>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
        </div>
        <?php
    }
}
