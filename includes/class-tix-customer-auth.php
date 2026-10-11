<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Customer_Auth – Kunden-Anmeldung ohne wp-login.php.
 *
 * Hinter der Durchleitung der Kundenseite (evendis.de → WordPress) sperrt der Proxy
 * POST /wp-login.php und /xmlrpc.php (fail2ban des WordPress-Servers sähe sonst alle
 * Besucher mit der Proxy-IP und würde sie gemeinsam sperren). Deshalb laufen Anmelden,
 * Abmelden, Passwort vergessen/zurücksetzen, Registrieren und Seitenpasswort über
 * admin-post.php (`action=tix_auth&tix_do=…`) und die Konto-Seite ([tix_account],
 * Ansichten `?tix_auth=lost|rp|register`). Schutz gegen Durchprobieren (ersetzt fail2ban
 * für diese Wege): Sperre nach Fehlversuchen je Besucher-IP (TIX_Client_IP) und je Konto,
 * für jede Anmeldung über wp_signon (Hook `authenticate`) und für POST /auth/login.
 *
 * Die Handler gibt es immer; die Umlenkung der Login-Adressen und Formulare greift nur
 * bei durchgeleiteten Anfragen (TIX_Customer_URL::is_proxied()). Reset-Links in Mails
 * an Kunden zeigen auf die Konto-Seite der Kunden-Adresse, sobald eine gesetzt ist.
 */
class TIX_Customer_Auth {

    const ACTION = 'tix_auth';

    /** Fehlversuche Anmeldung: je IP / je Konto in WINDOW Sekunden */
    const LOGIN_FAIL_IP    = 10;
    const LOGIN_FAIL_USER  = 10;
    const WINDOW           = 900;
    /** Passwort vergessen / Registrieren je IP und Stunde */
    const MAIL_PER_IP      = 5;
    const MIN_PASSWORD     = 8;

    const ERRORS = [
        'empty'      => 'Bitte E-Mail bzw. Benutzername und Passwort eingeben.',
        'invalid'    => 'E-Mail/Benutzername oder Passwort ist falsch.',
        'too_many'   => 'Zu viele Versuche. Bitte warte ein paar Minuten und versuche es dann erneut.',
        'invalidkey' => 'Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.',
        'mismatch'   => 'Die Passwörter stimmen nicht überein.',
        'short'      => 'Das Passwort muss mindestens 8 Zeichen haben.',
        'email'      => 'Bitte gib eine gültige E-Mail-Adresse ein.',
        'exists'     => 'Für diese E-Mail gibt es schon ein Konto. Melde dich an oder setze dein Passwort zurück.',
        'closed'     => 'Die Registrierung ist derzeit geschlossen.',
        'nonce'      => 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.',
        'failed'     => 'Das hat leider nicht geklappt. Bitte versuche es erneut.',
    ];

    const MESSAGES = [
        'lost_sent'  => 'Falls es ein Konto zu deiner Angabe gibt, haben wir dir eine E-Mail mit einem Link zum Zurücksetzen geschickt.',
        'reset_ok'   => 'Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.',
        'registered' => 'Fast geschafft: Wir haben dir eine E-Mail geschickt, mit der du dein Passwort festlegst.',
        'loggedout'  => 'Du bist abgemeldet.',
    ];

    public static function init() {
        add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::ACTION,        [__CLASS__, 'handle']);

        // Sperre nach Fehlversuchen für jede Anmeldung über wp_signon (nach der WordPress-Prüfung)
        add_filter('authenticate',     [__CLASS__, 'block_if_limited'], 99, 2);
        add_action('wp_login_failed',  [__CLASS__, 'on_login_failed'], 10, 2);

        // Reset-Links in Mails an Kunden → Konto-Seite der Kunden-Adresse
        add_filter('retrieve_password_message',      [__CLASS__, 'filter_reset_message'], 100, 4);
        add_filter('wp_new_user_notification_email', [__CLASS__, 'filter_new_user_email'], 100, 3);

        if (!class_exists('TIX_Customer_URL') || !TIX_Customer_URL::is_proxied()) return;

        // Durchleitung: keine Adresse auf wp-login.php / xmlrpc.php
        add_filter('login_url',        [__CLASS__, 'filter_login_url'], 100, 2);
        add_filter('logout_url',       [__CLASS__, 'filter_logout_url'], 100, 2);
        add_filter('lostpassword_url', [__CLASS__, 'filter_lostpassword_url'], 100, 2);
        add_filter('register_url',     [__CLASS__, 'filter_register_url'], 100);
        add_filter('site_url',         [__CLASS__, 'filter_form_action'], 100, 3);
        add_action('login_init',       [__CLASS__, 'divert_wp_login'], -2000);
        // Kommentare/Pingbacks posten an wp-comments-post.php bzw. xmlrpc.php – auf Kundenseiten aus
        add_filter('comments_open', '__return_false', 100);
        add_filter('pings_open', '__return_false', 100);
        remove_action('wp_head', 'rsd_link');
        add_filter('wp_headers', function ($h) { unset($h['X-Pingback']); return $h; });
    }

    // ── Adressen ──

    /** Konto-Seite ([tix_account]) mit Parametern; bei durchgeleiteten Anfragen schon auf der Kunden-Adresse. */
    public static function account_url(array $args = []) {
        global $wpdb;
        static $id = null;
        if ($id === null) {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
                '%[' . $wpdb->esc_like('tix_account') . '%'
            ));
        }
        $url = $id ? (string) get_permalink($id) : home_url('/');
        if (class_exists('TIX_Customer_URL') && !TIX_Customer_URL::is_proxied()) $url = TIX_Customer_URL::to($url);
        $args = array_filter($args, function ($v) { return $v !== '' && $v !== null; });
        return $args ? add_query_arg(array_map('rawurlencode', $args), $url) : $url;
    }

    /** Ziel der Formulare: admin-post.php (läuft über die Durchleitung, nicht von fail2ban überwacht). */
    public static function handler_url($do, array $args = []) {
        return add_query_arg(array_merge(['action' => self::ACTION, 'tix_do' => $do], $args), admin_url('admin-post.php'));
    }

    public static function filter_login_url($url, $redirect = '') {
        return self::account_url(['redirect_to' => (string) $redirect]);
    }

    public static function filter_logout_url($url, $redirect = '') {
        return self::handler_url('logout', array_filter([
            '_wpnonce'    => wp_create_nonce('log-out'),
            'redirect_to' => $redirect !== '' ? rawurlencode((string) $redirect) : '',
        ]));
    }

    public static function filter_lostpassword_url($url, $redirect = '') {
        return self::account_url(['tix_auth' => 'lost']);
    }

    public static function filter_register_url($url) {
        return self::account_url(['tix_auth' => 'register']);
    }

    /** Formular-Ziele auf wp-login.php (wp_login_form, Seitenpasswort, Login-Formulare) → Handler. */
    public static function filter_form_action($url, $path, $scheme) {
        if (!in_array($scheme, ['login_post', 'lostpassword_post'], true)) return $url;
        $path = ltrim((string) $path, '/');
        if (strpos($path, 'wp-login.php') !== 0) return $url;
        parse_str((string) wp_parse_url('https://x/' . $path, PHP_URL_QUERY), $q);
        return self::handler_url(self::do_for((string) ($q['action'] ?? 'login')));
    }

    private static function do_for($wp_action) {
        $map = [
            'lostpassword' => 'lostpassword', 'retrievepassword' => 'lostpassword',
            'rp' => 'resetpass', 'resetpass' => 'resetpass',
            'register' => 'register', 'postpass' => 'postpass', 'logout' => 'logout',
        ];
        return $map[$wp_action] ?? 'login';
    }

    /** wp-login.php über die Durchleitung: GET → passende Ansicht der Konto-Seite, POST → Handler. */
    public static function divert_wp_login() {
        $action = sanitize_key((string) ($_REQUEST['action'] ?? 'login'));
        if ($action === 'confirmaction') return; // Datenschutz-Bestätigung (nur GET) bleibt bei WordPress
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $_REQUEST['tix_do'] = self::do_for($action);
            self::handle();
            exit;
        }
        $redirect = isset($_GET['redirect_to']) ? (string) wp_unslash($_GET['redirect_to']) : '';
        switch ($action) {
            case 'logout':
                $to = self::handler_url('logout', array_filter([
                    '_wpnonce'    => sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))),
                    'redirect_to' => $redirect !== '' ? rawurlencode($redirect) : '',
                ]));
                break;
            case 'lostpassword':
            case 'retrievepassword':
                $to = self::account_url(['tix_auth' => 'lost']);
                break;
            case 'rp':
            case 'resetpass':
                $to = self::account_url([
                    'tix_auth'     => 'rp',
                    'tix_rp_key'   => sanitize_text_field(wp_unslash((string) ($_GET['key'] ?? ''))),
                    'tix_rp_login' => sanitize_user(wp_unslash((string) ($_GET['login'] ?? ''))),
                ]);
                break;
            case 'register':
                $to = self::account_url(['tix_auth' => 'register']);
                break;
            case 'checkemail':
                $to = self::account_url(['tix_auth_msg' => ($_GET['checkemail'] ?? '') === 'registered' ? 'registered' : 'lost_sent']);
                break;
            default:
                $to = self::account_url([
                    'redirect_to'  => $redirect,
                    'tix_auth_msg' => !empty($_GET['loggedout']) ? 'loggedout' : '',
                ]);
        }
        wp_safe_redirect($to);
        exit;
    }

    // ── Handler ──

    public static function handle() {
        nocache_headers();
        // Ziele auf der Verwaltung (wp-admin für Team) erlauben, auch wenn home_url gerade die Kunden-Adresse ist
        add_filter('allowed_redirect_hosts', function ($hosts) {
            $h = strtolower((string) wp_parse_url((string) get_option('home'), PHP_URL_HOST));
            if ($h !== '') $hosts[] = $h;
            if (class_exists('TIX_Customer_URL') && TIX_Customer_URL::host() !== '') $hosts[] = TIX_Customer_URL::host();
            return array_values(array_unique($hosts));
        });
        $do = sanitize_key((string) ($_REQUEST['tix_do'] ?? ''));
        switch ($do) {
            case 'login':        self::do_login(); break;
            case 'logout':       self::do_logout(); break;
            case 'lostpassword': self::do_lostpassword(); break;
            case 'resetpass':    self::do_resetpass(); break;
            case 'register':     self::do_register(); break;
            case 'postpass':     self::do_postpass(); break;
            default:             self::go(self::account_url());
        }
        exit;
    }

    private static function go($url) {
        wp_safe_redirect($url, 303);
        exit;
    }

    /** Zurück zum Formular (Referer auf erlaubtem Host, sonst Konto-Seite) mit Hinweis. */
    private static function back(array $args, $view = '') {
        $ref = wp_validate_redirect((string) wp_get_referer(), '');
        if ($ref === '' || strpos($ref, '/wp-admin/') !== false || stripos($ref, 'wp-login.php') !== false) {
            $ref = self::account_url($view !== '' ? ['tix_auth' => $view] : []);
        }
        $ref = remove_query_arg(['tix_auth_err', 'tix_auth_msg'], $ref);
        self::go(add_query_arg(array_map('rawurlencode', array_filter($args)), $ref));
    }

    private static function post($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
    }

    // ── Rate-Limit ──

    private static function ip() {
        return class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function count($bucket, $id) {
        return intval(get_transient('tix_auth_' . $bucket . '_' . md5(strtolower((string) $id))));
    }

    private static function bump($bucket, $id, $window) {
        $key = 'tix_auth_' . $bucket . '_' . md5(strtolower((string) $id));
        $n = intval(get_transient($key)) + 1;
        set_transient($key, $n, $window);
        return $n;
    }

    /** Zu viele Fehlversuche von dieser IP oder für dieses Konto? */
    public static function login_blocked($login) {
        return self::count('lf_ip', self::ip()) >= self::LOGIN_FAIL_IP
            || ((string) $login !== '' && self::count('lf_user', $login) >= self::LOGIN_FAIL_USER);
    }

    /** Fehlversuch zählen (IP und Konto, WINDOW Sekunden). */
    public static function login_failed($login) {
        self::bump('lf_ip', self::ip(), self::WINDOW);
        if ((string) $login !== '') self::bump('lf_user', $login, self::WINDOW);
    }

    public static function block_if_limited($user, $username) {
        if ((string) $username === '' || !self::login_blocked($username)) return $user;
        return new WP_Error('tix_too_many', self::ERRORS['too_many']);
    }

    public static function on_login_failed($username, $error = null) {
        if ($error instanceof WP_Error && $error->get_error_code() === 'tix_too_many') return;
        self::login_failed((string) $username);
    }

    // ── Aktionen ──

    private static function do_login() {
        $login    = trim(self::post('log'));
        $password = self::post('pwd');
        $remember = self::post('rememberme') !== '';
        $requested = self::post('redirect_to');
        $keep = $requested !== '' ? ['redirect_to' => $requested] : [];

        if ($login === '' || $password === '') self::back(['tix_auth_err' => 'empty'] + $keep);

        // Sperre und Zählen über authenticate / wp_login_failed
        $user = wp_signon(['user_login' => $login, 'user_password' => $password, 'remember' => $remember], is_ssl());
        if (is_wp_error($user)) {
            self::back(['tix_auth_err' => $user->get_error_code() === 'tix_too_many' ? 'too_many' : 'invalid'] + $keep);
        }

        $redirect = wp_validate_redirect($requested, '');
        $redirect = (string) apply_filters('login_redirect', $redirect !== '' ? $redirect : self::account_url(), $requested, $user);
        self::go(wp_validate_redirect($redirect, self::account_url()));
    }

    private static function do_logout() {
        $redirect = wp_validate_redirect((string) wp_unslash($_REQUEST['redirect_to'] ?? ''), '');
        if (is_user_logged_in()) {
            if (!wp_verify_nonce((string) ($_REQUEST['_wpnonce'] ?? ''), 'log-out')) {
                self::go(self::account_url(['tix_auth_err' => 'nonce']));
            }
            $user = wp_get_current_user();
            wp_logout();
            $redirect = (string) apply_filters('logout_redirect', $redirect, (string) ($_REQUEST['redirect_to'] ?? ''), $user);
        }
        self::go(wp_validate_redirect($redirect, '') ?: self::account_url(['tix_auth_msg' => 'loggedout']));
    }

    private static function do_lostpassword() {
        $login = trim(self::post('user_login'));
        if ($login === '') self::back(['tix_auth_err' => 'email'], 'lost');
        if (self::bump('lp_ip', self::ip(), HOUR_IN_SECONDS) > self::MAIL_PER_IP) self::back(['tix_auth_err' => 'too_many'], 'lost');
        // Je Konto höchstens 3 Mails pro Stunde; Antwort immer gleich (kein Ausforschen von Konten)
        if (self::bump('lp_user', $login, HOUR_IN_SECONDS) <= 3) {
            retrieve_password($login);
        }
        self::go(self::account_url(['tix_auth_msg' => 'lost_sent']));
    }

    private static function do_resetpass() {
        $key   = trim(self::post('rp_key'));
        $login = sanitize_user(self::post('rp_login'));
        $pass1 = self::post('pass1');
        $pass2 = self::post('pass2');
        $view  = ['tix_auth' => 'rp', 'tix_rp_key' => $key, 'tix_rp_login' => $login];

        if (self::count('rp_ip', self::ip()) >= self::LOGIN_FAIL_IP) self::go(self::account_url(['tix_auth' => 'lost', 'tix_auth_err' => 'too_many']));
        $user = ($key !== '' && $login !== '') ? check_password_reset_key($key, $login) : new WP_Error('invalidkey');
        if (is_wp_error($user)) {
            self::bump('rp_ip', self::ip(), self::WINDOW);
            self::go(self::account_url(['tix_auth' => 'lost', 'tix_auth_err' => 'invalidkey']));
        }
        if (strlen($pass1) < self::MIN_PASSWORD) self::go(self::account_url($view + ['tix_auth_err' => 'short']));
        if ($pass1 !== $pass2) self::go(self::account_url($view + ['tix_auth_err' => 'mismatch']));

        $errors = new WP_Error();
        do_action('validate_password_reset', $errors, $user);
        if ($errors->has_errors()) self::go(self::account_url($view + ['tix_auth_err' => 'failed']));

        reset_password($user, $pass1);
        self::go(self::account_url(['tix_auth_msg' => 'reset_ok']));
    }

    private static function do_register() {
        if (!get_option('users_can_register')) self::back(['tix_auth_err' => 'closed'], 'register');
        $email = sanitize_email(self::post('user_email'));
        if (!is_email($email)) self::back(['tix_auth_err' => 'email'], 'register');
        if (self::bump('rg_ip', self::ip(), HOUR_IN_SECONDS) > self::MAIL_PER_IP) self::back(['tix_auth_err' => 'too_many'], 'register');
        if (email_exists($email) || username_exists($email)) self::back(['tix_auth_err' => 'exists'], 'register');
        $res = register_new_user($email, $email);
        if (is_wp_error($res)) {
            $code = $res->get_error_code();
            self::back(['tix_auth_err' => in_array($code, ['email_exists', 'username_exists'], true) ? 'exists' : 'failed'], 'register');
        }
        self::go(self::account_url(['tix_auth_msg' => 'registered']));
    }

    /** Wie wp-login.php?action=postpass (Seitenpasswort-Cookie). */
    private static function do_postpass() {
        $redirect = wp_validate_redirect(self::post('redirect_to') ?: (string) wp_get_referer(), home_url('/'));
        if (!isset($_POST['post_password']) || !is_string($_POST['post_password'])) self::go($redirect);
        require_once ABSPATH . WPINC . '/class-phpass.php';
        $hasher = new PasswordHash(8, true);
        $expire = apply_filters('post_password_expires', time() + 10 * DAY_IN_SECONDS);
        $secure = 'https' === wp_parse_url($redirect, PHP_URL_SCHEME);
        setcookie('wp-postpass_' . COOKIEHASH, $hasher->HashPassword(wp_unslash($_POST['post_password'])), $expire, COOKIEPATH, COOKIE_DOMAIN, $secure);
        self::go($redirect);
    }

    // ── Mails ──

    /** Reset-Link auf wp-login.php → Konto-Seite der Kunden-Adresse (nur Kunden, nur mit Kunden-Adresse). */
    private static function rewrite_reset_links($text, $user) {
        if (!class_exists('TIX_Customer_URL') || !TIX_Customer_URL::active() || !is_string($text)) return $text;
        if ($user instanceof WP_User && class_exists('TIX_Admin_Only') && TIX_Admin_Only::is_staff($user)) return $text;
        // Parameter-Reihenfolge je nach WordPress-Version verschieden (?action=rp&key=…&login=… bzw. ?login=…&key=…&action=rp)
        return preg_replace_callback(
            '~https?://[^\s"\'<>]*?/wp-login\.php\?([^\s"\'<>]+)~i',
            function ($m) {
                $amp = (strpos($m[1], '&amp;') !== false || strpos($m[1], '&#038;') !== false);
                parse_str(str_replace(['&amp;', '&#038;'], '&', $m[1]), $q);
                if (($q['action'] ?? '') !== 'rp' || empty($q['key']) || empty($q['login'])) return $m[0];
                $sep = $amp ? '&amp;' : '&';
                $acc = self::account_url();
                return $acc . (strpos($acc, '?') === false ? '?' : $sep) . 'tix_auth=rp' . $sep . 'tix_rp_key=' . rawurlencode((string) $q['key']) . $sep . 'tix_rp_login=' . rawurlencode((string) $q['login']);
            },
            $text
        );
    }

    public static function filter_reset_message($message, $key = '', $user_login = '', $user_data = null) {
        return self::rewrite_reset_links($message, $user_data);
    }

    public static function filter_new_user_email($email, $user = null, $blogname = '') {
        if (is_array($email) && isset($email['message'])) $email['message'] = self::rewrite_reset_links($email['message'], $user);
        return $email;
    }

    // ── Ansichten auf der Konto-Seite ──

    /** Hinweis aus ?tix_auth_err / ?tix_auth_msg (nur feste Texte). */
    public static function notice_html() {
        $err = sanitize_key((string) ($_GET['tix_auth_err'] ?? ''));
        $msg = sanitize_key((string) ($_GET['tix_auth_msg'] ?? ''));
        if ($err !== '' && isset(self::ERRORS[$err])) {
            return '<div class="tix-auth-notice tix-auth-notice-error" role="alert">' . esc_html(self::ERRORS[$err]) . '</div>';
        }
        if ($msg !== '' && isset(self::MESSAGES[$msg])) {
            return '<div class="tix-auth-notice tix-auth-notice-ok" role="status">' . esc_html(self::MESSAGES[$msg]) . '</div>';
        }
        return '';
    }

    /** Aktuelle Gast-Ansicht: lost | rp | register | '' (Anmelden). */
    public static function view() {
        $v = sanitize_key((string) ($_GET['tix_auth'] ?? ''));
        if ($v === 'register' && !get_option('users_can_register')) return '';
        return in_array($v, ['lost', 'rp', 'register'], true) ? $v : '';
    }

    /** Formular der Ansicht (ohne äußeren Rahmen). */
    public static function render_view($view) {
        $back = '<p class="tix-account-login-register"><a href="' . esc_url(self::account_url()) . '">Zurück zur Anmeldung</a></p>';
        ob_start();
        if ($view === 'lost') { ?>
            <h2>Passwort vergessen</h2>
            <p>Gib deine E-Mail-Adresse oder deinen Benutzernamen ein. Wir schicken dir einen Link, mit dem du ein neues Passwort festlegst.</p>
            <?php echo self::notice_html(); ?>
            <form class="tix-auth-form" method="post" action="<?php echo esc_url(self::handler_url('lostpassword')); ?>">
                <label for="tix-auth-login">E-Mail oder Benutzername</label>
                <input type="text" id="tix-auth-login" name="user_login" autocomplete="username" required>
                <input type="submit" value="Link schicken">
            </form>
            <?php echo $back;
        } elseif ($view === 'rp') {
            $key   = sanitize_text_field(wp_unslash((string) ($_GET['tix_rp_key'] ?? '')));
            $login = sanitize_user(wp_unslash((string) ($_GET['tix_rp_login'] ?? '')));
            $valid = $key !== '' && $login !== '' && !is_wp_error(check_password_reset_key($key, $login)); ?>
            <h2>Neues Passwort</h2>
            <?php if (!$valid): ?>
                <div class="tix-auth-notice tix-auth-notice-error" role="alert"><?php echo esc_html(self::ERRORS['invalidkey']); ?></div>
                <p class="tix-account-login-register"><a href="<?php echo esc_url(self::account_url(['tix_auth' => 'lost'])); ?>">Neuen Link anfordern</a></p>
            <?php else: ?>
                <p>Wähle ein sicheres Passwort mit mindestens <?php echo intval(self::MIN_PASSWORD); ?> Zeichen.</p>
                <?php echo self::notice_html(); ?>
                <form class="tix-auth-form" method="post" action="<?php echo esc_url(self::handler_url('resetpass')); ?>">
                    <input type="hidden" name="rp_key" value="<?php echo esc_attr($key); ?>">
                    <input type="hidden" name="rp_login" value="<?php echo esc_attr($login); ?>">
                    <input type="text" name="user_login_hint" value="<?php echo esc_attr($login); ?>" autocomplete="username" hidden>
                    <label for="tix-auth-pass1">Neues Passwort</label>
                    <input type="password" id="tix-auth-pass1" name="pass1" minlength="<?php echo intval(self::MIN_PASSWORD); ?>" autocomplete="new-password" required>
                    <label for="tix-auth-pass2">Passwort wiederholen</label>
                    <input type="password" id="tix-auth-pass2" name="pass2" minlength="<?php echo intval(self::MIN_PASSWORD); ?>" autocomplete="new-password" required>
                    <input type="submit" value="Passwort speichern">
                </form>
            <?php endif;
        } elseif ($view === 'register') { ?>
            <h2>Konto erstellen</h2>
            <p>Gib deine E-Mail-Adresse ein. Wir schicken dir einen Link, mit dem du dein Passwort festlegst.</p>
            <?php echo self::notice_html(); ?>
            <form class="tix-auth-form" method="post" action="<?php echo esc_url(self::handler_url('register')); ?>">
                <label for="tix-auth-email">E-Mail-Adresse</label>
                <input type="email" id="tix-auth-email" name="user_email" autocomplete="email" required>
                <input type="submit" value="Konto erstellen">
            </form>
            <?php echo $back;
        }
        return ob_get_clean();
    }

    /** Link „Passwort vergessen?“ unter Login-Formularen. */
    public static function lost_link_html() {
        return '<p class="tix-auth-lost"><a href="' . esc_url(wp_lostpassword_url()) . '">Passwort vergessen?</a></p>';
    }
}
