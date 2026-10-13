<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Weekly_Mail – Wochenmail „Was geht am Wochenende?“ je Stadt (evendis.de, nur Mehr-Veranstalter-Modus).
 *
 * Schalter: Option `tix_weekly_mail` → enabled (Vorgabe AUS – erst mit Datenschutztext und Go des Users).
 * REST: `GET /info` → `newsletter {enabled}`;
 *   POST /public/newsletter {email, city?, consent: true} → immer gleiche Antwort (kein Hinweis, ob die Adresse bekannt ist)
 *   GET  /public/newsletter/confirm?token=   Double-Opt-in → Weiterleitung auf die Kunden-Seite (?newsletter=bestaetigt)
 *   GET  /public/newsletter/unsubscribe?token=  Abmeldung → Weiterleitung (?newsletter=abgemeldet)
 * Tabelle `{prefix}tix_weekly_subscribers`: E-Mail, Stadt ('' = alle), Status pending|active|unsubscribed,
 * Einwilligung mit Text und Zeitpunkt (+ IP als HMAC), Bestätigung, letzter Versand.
 * Versand: Cron stündlich, ab Donnerstag (Einstellung) zur gewählten Stunde, je Stadt die Events von Donnerstag bis
 * Sonntag; in Paketen zu 100 Mails, Abmeldelink + List-Unsubscribe in jeder Mail, keine Tracking-Pixel, keine Werbung Dritter.
 * Aufräumen: unbestätigte Anmeldungen nach 7 Tagen, Abmeldungen nach 30 Tagen gelöscht.
 */
class TIX_Weekly_Mail {

    const NS       = 'tixomat/v1';
    const OPTION   = 'tix_weekly_mail';
    const DB_VER   = '1';
    const CRON     = 'tix_weekly_mail_cron';
    const BATCH    = 100;
    /** Gesetzt, solange eine Wochenmail/Bestätigungsmail rausgeht (Mail-Log: kein Öffnungs-Pixel). */
    public static $sending = false;

    private static function mail($to, $subject, $body, $headers = []) {
        self::$sending = true;
        try { return wp_mail($to, $subject, $body, $headers); } finally { self::$sending = false; }
    }

    const CONSENT_TEXT = 'Ja, ich möchte die Wochenmail von evendis mit Events am Wochenende (in meiner Stadt) per E-Mail erhalten. Die Einwilligung kann ich jederzeit über den Abmeldelink in jeder Mail widerrufen.';

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function settings() {
        $s = get_option(self::OPTION, []);
        $s = is_array($s) ? $s : [];
        return [
            'enabled' => !empty($s['enabled']),
            'day'     => max(1, min(7, intval($s['day'] ?? 4))),   // ISO-Wochentag, 4 = Donnerstag
            'hour'    => max(0, min(23, intval($s['hour'] ?? 9))),
        ];
    }

    public static function enabled() {
        return self::multi() && self::settings()['enabled'];
    }

    public static function init() {
        if (!self::multi()) return;
        add_action('init', [__CLASS__, 'maybe_install'], 8);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Weekly_Mail::CRON)) wp_schedule_event(time() + 600, 'hourly', TIX_Weekly_Mail::CRON);
        }, 23);
        add_action('admin_menu', [__CLASS__, 'menu'], 43);
        add_action('admin_post_tix_weekly_mail', [__CLASS__, 'handle_admin']);
        // Kein Öffnungs-Pixel des Mail-Logs in Wochenmail und Bestätigungsmail (keine Tracking-Kopplung)
        add_filter('tix_email_tracking_enabled', function ($on) {
            return TIX_Weekly_Mail::$sending ? false : $on;
        }, 10, 1);
    }

    private static function t() {
        global $wpdb;
        return $wpdb->prefix . 'tix_weekly_subscribers';
    }

    public static function maybe_install() {
        if (get_option('tix_weekly_mail_db') === self::DB_VER) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::t() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            city varchar(120) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'pending',
            token varchar(64) NOT NULL,
            consent_text text NOT NULL,
            consent_at datetime NOT NULL,
            consent_ip varchar(64) NOT NULL DEFAULT '',
            confirmed_at datetime NULL,
            unsubscribed_at datetime NULL,
            last_sent datetime NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email_city (email, city),
            KEY token (token),
            KEY status (status)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_weekly_mail_db', self::DB_VER, false);
    }

    public static function register_routes() {
        $pub = ['permission_callback' => '__return_true'];
        register_rest_route(self::NS, '/public/newsletter', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_subscribe']] + $pub);
        register_rest_route(self::NS, '/public/newsletter/confirm', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_confirm']] + $pub);
        register_rest_route(self::NS, '/public/newsletter/unsubscribe', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_unsubscribe']] + $pub);
    }

    private static function customer_base() {
        $b = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::base() : '';
        return $b !== '' ? $b : untrailingslashit(home_url());
    }

    private static function link($action, $token) {
        return add_query_arg('token', rawurlencode($token), rest_url(self::NS . '/public/newsletter/' . $action));
    }

    private static function ip_hash() {
        $ip = class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : '';
    }

    public static function rest_subscribe(WP_REST_Request $req) {
        global $wpdb;
        if (!self::enabled()) return new WP_Error('tix_newsletter_off', 'Die Wochenmail ist noch nicht verfügbar.', ['status' => 404]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('newsletter', 5, 3600)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Anmeldungen. Bitte später erneut versuchen.', ['status' => 429]);
        }
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if (!is_email($email)) return new WP_Error('invalid_email', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        if (!filter_var($req->get_param('consent'), FILTER_VALIDATE_BOOLEAN)) {
            return new WP_Error('tix_consent_required', 'Bitte bestätige die Einwilligung.', ['status' => 400, 'field' => 'consent']);
        }
        $city = mb_substr(sanitize_text_field((string) $req->get_param('city')), 0, 120);
        self::maybe_install();
        $t = self::t();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE email = %s AND city = %s", $email, $city));
        $now = current_time('mysql');
        if (!$row || $row->status !== 'active') {
            // Offene Anmeldung behält ihren Schlüssel – der Link aus der ersten Mail bleibt gültig
            $token = ($row && $row->status === 'pending') ? (string) $row->token : wp_generate_password(40, false, false);
            $data = ['status' => 'pending', 'token' => $token, 'consent_text' => self::CONSENT_TEXT, 'consent_at' => $now, 'consent_ip' => self::ip_hash(), 'unsubscribed_at' => null];
            if ($row) $wpdb->update($t, $data, ['id' => $row->id]);
            else $wpdb->insert($t, $data + ['email' => $email, 'city' => $city, 'created' => $now]);
            self::send_confirm($email, $city, $token);
        }
        return rest_ensure_response(['ok' => true, 'message' => 'Fast geschafft: Bitte bestätige die Anmeldung über den Link in der E-Mail.']);
    }

    private static function brand() {
        return wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    }

    private static function send_confirm($email, $city, $token) {
        $where = $city !== '' ? ' in ' . $city : '';
        $body = "Hallo,\n\nbitte bestätige, dass du die Wochenmail{$where} bekommen möchtest:\n" . self::link('confirm', $token)
            . "\n\nWenn du dich nicht angemeldet hast, ignoriere diese Mail einfach – ohne Bestätigung schicken wir nichts.\n\n" . self::brand();
        self::mail($email, 'Bitte bestätige deine Anmeldung zur Wochenmail', $body);
    }

    private static function redirect($state) {
        wp_redirect(add_query_arg('newsletter', $state, self::customer_base() . '/'), 302, 'Tixomat');
        exit;
    }

    private static function by_token($token) {
        global $wpdb;
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
        if (strlen($token) < 20) return null;
        self::maybe_install();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE token = %s", $token));
    }

    public static function rest_confirm(WP_REST_Request $req) {
        global $wpdb;
        $row = self::by_token($req->get_param('token'));
        if (!$row || $row->status === 'unsubscribed') return self::redirect('ungueltig');
        if ($row->status === 'pending') {
            // Neuer Schlüssel für die Abmeldelinks (der Bestätigungslink wird damit ungültig)
            $wpdb->update(self::t(), ['status' => 'active', 'confirmed_at' => current_time('mysql'), 'token' => wp_generate_password(40, false, false)], ['id' => $row->id]);
        }
        return self::redirect('bestaetigt');
    }

    public static function rest_unsubscribe(WP_REST_Request $req) {
        global $wpdb;
        $row = self::by_token($req->get_param('token'));
        if ($row && $row->status !== 'unsubscribed') {
            $wpdb->update(self::t(), ['status' => 'unsubscribed', 'unsubscribed_at' => current_time('mysql')], ['id' => $row->id]);
        }
        return self::redirect('abgemeldet');
    }

    // ── Versand ──

    /** Anfang der Versandwoche (Montag 00:00, lokale Zeit) als MySQL-Datum. */
    private static function week_start() {
        $now = current_time('timestamp');
        $dow = intval(gmdate('N', $now));
        return gmdate('Y-m-d 00:00:00', $now - ($dow - 1) * DAY_IN_SECONDS);
    }

    /** Events Donnerstag–Sonntag dieser Woche in der Stadt ('' = alle). */
    public static function weekend_events($city) {
        $now = current_time('timestamp');
        $dow = intval(gmdate('N', $now));
        $from = gmdate('Y-m-d', $now - max(0, $dow - 4) * DAY_IN_SECONDS);
        $from = max($from, gmdate('Y-m-d', $now));
        $to   = gmdate('Y-m-d', $now + (7 - $dow) * DAY_IN_SECONDS);
        $q = new WP_REST_Request('GET', '/' . self::NS . '/public/events');
        $q->set_param('from', $from); $q->set_param('to', $to); $q->set_param('per_page', 12);
        if ($city !== '') $q->set_param('city', $city);
        $r = rest_do_request($q);
        $d = $r->get_data();
        return is_array($d) ? (array) ($d['events'] ?? $d['items'] ?? []) : [];
    }

    private static function mail_html($events, $city, $unsub) {
        $esc = 'esc_html';
        $title = 'Was geht am Wochenende' . ($city !== '' ? ' in ' . $city : '') . '?';
        $rows = '';
        foreach ($events as $e) {
            $when = trim(($e['date_start'] ? date_i18n('D, d.m.', strtotime($e['date_start'])) : '') . ($e['time_start'] ? ' · ' . $e['time_start'] . ' Uhr' : ''));
            $where = (string) ($e['venue']['name'] ?? $e['location'] ?? '');
            $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #eee;"><a href="' . esc_url((string) ($e['url'] ?? '')) . '" style="color:#111;font-weight:600;text-decoration:none;">' . $esc((string) $e['title']) . '</a><br><span style="color:#666;font-size:13px;">' . $esc($when . ($where !== '' ? ' · ' . $where : '')) . '</span></td></tr>';
        }
        return '<!doctype html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:16px;color:#111;">'
            . '<h1 style="font-size:20px;">' . $esc($title) . '</h1><table width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
            . '<p style="margin-top:20px;"><a href="' . esc_url(self::customer_base() . '/') . '">Alle Events ansehen</a></p>'
            . '<p style="color:#888;font-size:12px;margin-top:28px;">Du bekommst diese Mail, weil du die Wochenmail bestellt hast. <a href="' . esc_url($unsub) . '" style="color:#888;">Abmelden</a></p>'
            . '</body></html>';
    }

    /** Ein Durchlauf: höchstens BATCH Empfänger, die diese Woche noch nichts bekommen haben. */
    public static function send_batch($force = false) {
        global $wpdb;
        if (!self::enabled()) return 0;
        self::maybe_install();
        $st = self::settings();
        $now = current_time('timestamp');
        if (!$force && (intval(gmdate('N', $now)) < $st['day'] || (intval(gmdate('N', $now)) === $st['day'] && intval(gmdate('G', $now)) < $st['hour']))) return 0;
        $t = self::t();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status = 'active' AND (last_sent IS NULL OR last_sent < %s) ORDER BY city, id LIMIT %d", self::week_start(), self::BATCH));
        $cache = []; $sent = 0;
        foreach ((array) $rows as $r) {
            if (!isset($cache[$r->city])) $cache[$r->city] = self::weekend_events($r->city);
            $events = $cache[$r->city];
            $wpdb->update($t, ['last_sent' => current_time('mysql')], ['id' => $r->id]); // auch ohne Events: diese Woche erledigt
            if (!$events) continue;
            $unsub = self::link('unsubscribe', $r->token);
            $ok = self::mail($r->email, 'Was geht am Wochenende' . ($r->city !== '' ? ' in ' . $r->city : '') . '?', self::mail_html($events, $r->city, $unsub),
                ['Content-Type: text/html; charset=UTF-8', 'List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click']);
            if ($ok) $sent++;
        }
        return $sent;
    }

    public static function cron() {
        global $wpdb;
        if (get_option('tix_weekly_mail_db') === self::DB_VER) {
            $t = self::t();
            $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE status = 'pending' AND consent_at < %s", gmdate('Y-m-d H:i:s', current_time('timestamp') - 7 * DAY_IN_SECONDS)));
            $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE status = 'unsubscribed' AND unsubscribed_at < %s", gmdate('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS)));
        }
        self::send_batch();
    }

    // ── Admin ──

    public static function menu() {
        add_submenu_page('tixomat', 'Wochenmail', 'Wochenmail', 'manage_options', 'tix-weekly-mail', [__CLASS__, 'render']);
    }

    public static function handle_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_weekly_mail');
        $do = sanitize_key($_POST['do'] ?? 'save');
        if ($do === 'test') {
            $events = self::weekend_events(sanitize_text_field(wp_unslash($_POST['city'] ?? '')));
            self::mail(wp_get_current_user()->user_email, '[Test] Was geht am Wochenende?', self::mail_html($events, sanitize_text_field(wp_unslash($_POST['city'] ?? '')), self::customer_base() . '/'), ['Content-Type: text/html; charset=UTF-8']);
            $msg = 'test';
        } else {
            update_option(self::OPTION, ['enabled' => !empty($_POST['enabled']) ? 1 : 0, 'day' => intval($_POST['day'] ?? 4), 'hour' => intval($_POST['hour'] ?? 9)], false);
            $msg = 'saved';
        }
        wp_safe_redirect(admin_url('admin.php?page=tix-weekly-mail&msg=' . $msg));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        self::maybe_install();
        $s = self::settings();
        $counts = [];
        foreach ((array) $wpdb->get_results("SELECT status, COUNT(*) AS n FROM " . self::t() . " GROUP BY status") as $r) $counts[$r->status] = intval($r->n);
        $days = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
        ?>
        <div class="wrap">
            <h1>Wochenmail</h1>
            <?php if (!empty($_GET['msg'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo $_GET['msg'] === 'test' ? 'Test-Mail an dich gesendet.' : 'Gespeichert.'; ?></p></div><?php endif; ?>
            <p>„Was geht am Wochenende?“ je Stadt, mit Double-Opt-in und Abmeldelink in jeder Mail. Keine Tracking-Pixel, keine Werbung Dritter.</p>
            <p>Angemeldet: <strong><?php echo intval($counts['active'] ?? 0); ?></strong> · wartet auf Bestätigung: <?php echo intval($counts['pending'] ?? 0); ?> · abgemeldet: <?php echo intval($counts['unsubscribed'] ?? 0); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tix_weekly_mail'); ?><input type="hidden" name="action" value="tix_weekly_mail">
                <p><label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>> <strong>Wochenmail anbieten</strong> – erst einschalten, wenn der Datenschutztext live ist und der User zugestimmt hat</label></p>
                <p>Versand ab <select name="day"><?php foreach ($days as $k => $v) echo '<option value="' . $k . '"' . selected($s['day'], $k, false) . '>' . esc_html($v) . '</option>'; ?></select>
                   um <input type="number" name="hour" min="0" max="23" value="<?php echo intval($s['hour']); ?>" style="width:60px;"> Uhr</p>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tix_weekly_mail'); ?><input type="hidden" name="action" value="tix_weekly_mail"><input type="hidden" name="do" value="test">
                <p>Test-Mail an mich, Stadt: <input type="text" name="city" placeholder="leer = alle"> <button class="button">Senden</button></p>
            </form>
            <p class="description">Einwilligungstext (wird je Anmeldung mit Zeitpunkt gespeichert): „<?php echo esc_html(self::CONSENT_TEXT); ?>“</p>
        </div>
        <?php
    }
}
