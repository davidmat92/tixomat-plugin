<?php
if (!defined('ABSPATH')) exit;

/**
 * Inhaber-Zugang und „Startklar“ (1.38.688, Doku §103, Vertrag uebergaben/vertrag-platform-customers.md, Audit 08.11.).
 *
 * Kundenseite:
 *   invite($email, $name)       Konto anlegen bzw. vorhandenes nutzen (Rolle tix_organizer = Inhaber), Mail „Passwort festlegen“
 *   readiness()                 {payment, legal, owner, homepage} – geht mit jeder Lizenzprüfung an den Lizenz-Server
 *   wp tixomat inhaber einladen --email=… [--name=…] [--ohne-mail] · wp tixomat inhaber status
 * Lizenz-Server (Plattform):
 *   queue($license_id, $email, $name)   Einladung vormerken; sie geht signiert mit der nächsten Prüfung an die gebundene Seite
 *   (Ping löst die Prüfung sofort aus). Die Seite meldet die erledigte Einladung mit der nächsten Prüfung zurück.
 */
class TIX_Owner_Access {

    const OWNER_META = '_tix_site_owner';
    const OPT_DONE   = 'tix_owner_invite_done';   // Kundenseite: zuletzt erledigte Einladung {nonce, ok, at, user_id, created}
    const OPT_QUEUE  = 'tix_lic_invite_';         // Lizenz-Server: je Lizenz {email, name, nonce, status queued|done|failed, at, done_at, result}

    public static function init() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat inhaber', [__CLASS__, 'cli']);
    }

    // ═══════════════ Kundenseite ═══════════════

    /** @return array|WP_Error {user_id, login, created, mailed} */
    public static function invite($email, $name = '', array $opt = []) {
        $email = sanitize_email((string) $email);
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        $name = mb_substr(sanitize_text_field((string) $name), 0, 120);
        $user = get_user_by('email', $email);
        $created = false;
        if (!$user) {
            $base = sanitize_user(strtolower(strtok($email, '@')), true) ?: 'inhaber';
            $login = $base;
            for ($i = 2; username_exists($login) && $i < 200; $i++) $login = $base . $i;
            $uid = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(32, true, true), 'role' => 'tix_organizer',
                'display_name' => $name ?: $login, 'first_name' => $name !== '' ? strtok($name, ' ') : '']);
            if (is_wp_error($uid)) return $uid;
            $user = get_user_by('id', $uid);
            $created = true;
        } elseif (!in_array('administrator', (array) $user->roles, true) && !in_array('tix_organizer', (array) $user->roles, true)) {
            $user->add_role('tix_organizer');
        }
        update_user_meta($user->ID, self::OWNER_META, 1);
        $mailed = false;
        if (empty($opt['no_mail'])) $mailed = self::send_invite($user, $name, !empty($opt['platform']));
        return ['user_id' => intval($user->ID), 'login' => (string) $user->user_login, 'created' => $created, 'mailed' => (bool) $mailed];
    }

    const INVITE_TTL = 7 * DAY_IN_SECONDS;

    /**
     * Abschnitt „Passwort festlegen“ für Einladungs-Mails (1.38.701, wie die Team-Einladung): 6-stelliger Code (7 Tage)
     * und Knopf ins Studio (`/passwort/?email=…[&art=…]`) plus Hinweis auf die App.
     * Ohne Studio Rückfall auf den WordPress-Link (wp-login.php?action=rp). Der Code steht nur in der Mail, nie im Link.
     * → ['html' => string, 'url' => string]
     */
    public static function password_block(WP_User $user, $art = '') {
        $studio = class_exists('TIX_Studio') && TIX_Studio::active();
        $code = $studio && class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'issue_invite_code') ? TIX_App_Account::issue_invite_code($user->user_email, self::INVITE_TTL) : '';
        if ($code === '') {
            $key = get_password_reset_key($user);
            if (is_wp_error($key)) return ['html' => '', 'url' => ''];
            $set = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');
            return ['html' => '<p>Lege jetzt dein Passwort fest – der Link gilt 24 Stunden.</p><p><a href="' . esc_url($set) . '">Passwort festlegen</a></p>', 'url' => $set];
        }
        $q = ['email' => $user->user_email];
        if ($art !== '') $q['art'] = sanitize_key($art);
        $url = add_query_arg($q, TIX_Studio::url('/passwort/'));
        $app = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'app_info') ? (string) (TIX_Studio::app_info()['name'] ?? '') : '';
        $h  = '<p>Leg jetzt dein Passwort fest – mit diesem Code:</p>';
        $h .= '<p style="text-align:center;margin:20px 0;"><span style="display:inline-block;padding:14px 22px;border-radius:12px;background:#F2F3F7;font:700 28px/1 ui-monospace,Menlo,monospace;letter-spacing:6px;">' . esc_html($code) . '</span></p>';
        $h .= '<p style="text-align:center;margin:20px 0;"><a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 22px;background:#2C2F3F;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;">Passwort festlegen</a></p>';
        $h .= '<p>Oder in der App' . ($app ? ' ' . esc_html($app) : '') . ': Veranstalter-Anmeldung → „Passwort vergessen“ → diese E-Mail-Adresse (' . esc_html($user->user_email) . ') und den Code eingeben.</p>';
        $h .= '<p style="color:#64748b;font-size:13px;">Der Code gilt 7 Tage und nur für dich. Gib ihn nie weiter – auch nicht an uns.</p>';
        return ['html' => $h, 'url' => $url];
    }

    /** Mail „Dein Zugang zu …“ mit Code + Link „Passwort festlegen“ im Studio (seit 1.38.701, vorher WordPress-Reset-Link). */
    public static function send_invite($user, $name = '', $platform = false) {
        $pw = self::password_block($user, $platform ? 'owner' : '');
        if ($pw['html'] === '') return false;
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $studio = class_exists('TIX_Studio') && TIX_Studio::active() ? TIX_Studio::url('/') : admin_url();
        $hello = $name !== '' ? 'Hallo ' . esc_html(strtok($name, ' ')) . ',' : 'Hallo,';
        $what = $platform ? 'dein Zugang als Veranstalter auf ' . esc_html($site) . ' ist angelegt.' : 'dein Zugang zu ' . esc_html($site) . ' ist eingerichtet.';
        $html = '<p>' . $hello . '</p><p>' . $what . '</p>' . $pw['html']
            . '<p>Danach meldest du dich hier an: <a href="' . esc_url($studio) . '">' . esc_html(preg_replace('#^https?://#', '', untrailingslashit($studio))) . '</a><br>E-Mail: ' . esc_html($user->user_email) . '</p>';
        $subject = 'Dein Zugang zu ' . $site;
        if (class_exists('TIX_Mail_Layout')) {
            return (bool) TIX_Mail_Layout::send($user->user_email, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        }
        return (bool) wp_mail($user->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    /** Hat die Seite einen Inhaber-Zugang (eingeladen oder Rolle tix_organizer)? */
    public static function has_owner() {
        $ids = get_users(['meta_key' => self::OWNER_META, 'meta_value' => '1', 'fields' => 'ID', 'number' => 1]);
        if ($ids) return true;
        return (bool) get_users(['role' => 'tix_organizer', 'fields' => 'ID', 'number' => 1]);
    }

    /** Startklar der Kundenseite (nur Ja/Nein, keine Inhalte). */
    public static function readiness() {
        $payment = false;
        if (class_exists('TIX_Studio_Payments')) {
            $s = get_option('tix_settings', []);
            $s = is_array($s) ? $s : [];
            foreach (['mollie', 'stripe', 'paypal', 'bank'] as $p) if (TIX_Studio_Payments::ready($p, $s)) { $payment = true; break; }
        }
        $legal = false;
        if (class_exists('TIX_Site_Config')) {
            $c = TIX_Site_Config::payload();
            $lg = (array) ($c['legal'] ?? []);
            $legal = !empty($lg['imprint']) && !empty($lg['privacy']) && !empty($lg['terms']);
        }
        $homepage = false;
        if (class_exists('TIX_Site_Config')) {
            $c = $c ?? TIX_Site_Config::payload();
            $homepage = is_array($c['homepage'] ?? null) && !empty($c['homepage']['enabled']);
        }
        return ['payment' => $payment, 'legal' => $legal, 'owner' => self::has_owner(), 'homepage' => $homepage];
    }

    /** Lizenzprüfung: Einladung aus der Antwort ausführen (einmal je nonce, nur mit gültiger Signatur). */
    public static function from_check($key, array $data) {
        $inv = $data['owner_invite'] ?? null;
        if (!is_array($inv) || empty($inv['nonce']) || empty($data['owner_invite_sig'])) return;
        $done = (array) get_option(self::OPT_DONE, []);
        if (($done['nonce'] ?? '') === (string) $inv['nonce']) return;
        if (!self::verify_invite($key, $inv, (string) $data['owner_invite_sig'], intval($data['issued_at'] ?? 0))) return;
        $r = self::invite((string) ($inv['email'] ?? ''), (string) ($inv['name'] ?? ''));
        update_option(self::OPT_DONE, ['nonce' => (string) $inv['nonce'], 'ok' => !is_wp_error($r), 'at' => time(),
            'created' => is_wp_error($r) ? false : $r['created'], 'mailed' => is_wp_error($r) ? false : $r['mailed'],
            'error' => is_wp_error($r) ? $r->get_error_message() : null], false);
    }

    /** Felder, die mit der Lizenzprüfung an den Server gehen. */
    public static function check_params() {
        $done = (array) get_option(self::OPT_DONE, []);
        return ['ready' => wp_json_encode(self::readiness()),
            'invite_done' => (string) ($done['nonce'] ?? ''), 'invite_ok' => !empty($done['ok']) ? '1' : '0', 'invite_mailed' => !empty($done['mailed']) ? '1' : '0'];
    }

    public static function invite_base($host, array $inv, $issued_at) {
        return implode('|', ['tix-invite-v1', strtolower((string) $host), strtolower((string) ($inv['email'] ?? '')), (string) ($inv['name'] ?? ''), (string) ($inv['nonce'] ?? ''), intval($issued_at)]);
    }

    public static function verify_invite($key, array $inv, $sig, $issued_at) {
        if (!$issued_at || abs(time() - $issued_at) > 2 * DAY_IN_SECONDS) return false;
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        $norm = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key)));
        return hash_equals(hash_hmac('sha256', self::invite_base($host, $inv, $issued_at), $norm), (string) $sig);
    }

    // ═══════════════ Lizenz-Server ═══════════════

    public static function queued($license_id) {
        $q = get_option(self::OPT_QUEUE . intval($license_id), null);
        return is_array($q) ? $q : null;
    }

    public static function queue($license_id, $email, $name = '') {
        $email = sanitize_email((string) $email);
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        $q = ['email' => strtolower($email), 'name' => mb_substr(sanitize_text_field((string) $name), 0, 120), 'nonce' => wp_generate_password(16, false),
            'status' => 'queued', 'at' => gmdate('c'), 'by' => get_current_user_id(), 'done_at' => null, 'result' => null];
        update_option(self::OPT_QUEUE . intval($license_id), $q, false);
        if (class_exists('TIX_License_Server')) TIX_License_Server::log(intval($license_id), 'owner_invite', ['email' => $q['email']]);
        return $q;
    }

    /** Antwort der Lizenzprüfung ergänzen (nur gebundener Host). */
    public static function add_to_check(array $out, $lic, $host) {
        $q = self::queued($lic->id);
        if (!$q || $q['status'] !== 'queued' || $host === '') return $out;
        $inv = ['email' => $q['email'], 'name' => $q['name'], 'nonce' => $q['nonce']];
        $issued = intval($out['issued_at'] ?? time());
        $out['owner_invite'] = $inv;
        $out['owner_invite_sig'] = hash_hmac('sha256', self::invite_base($host, $inv, $issued), TIX_License_Server::normalize_key($lic->license_key));
        return $out;
    }

    /** Rückmeldung der Seite (invite_done = nonce). */
    public static function report($lic, WP_REST_Request $req) {
        $q = self::queued($lic->id);
        $n = (string) $req->get_param('invite_done');
        if (!$q || $n === '' || $n !== $q['nonce'] || $q['status'] !== 'queued') return;
        $ok = (string) $req->get_param('invite_ok') === '1';
        $q['status'] = $ok ? 'done' : 'failed';
        $q['done_at'] = gmdate('c');
        $q['result'] = ['mailed' => (string) $req->get_param('invite_mailed') === '1'];
        update_option(self::OPT_QUEUE . intval($lic->id), $q, false);
        TIX_License_Server::log(intval($lic->id), 'owner_invite_' . $q['status'], ['email' => $q['email']]);
    }

    // ═══════════════ CLI ═══════════════

    /**
     * wp tixomat inhaber einladen --email=<mail> [--name=<name>] [--ohne-mail]
     * wp tixomat inhaber status
     */
    public static function cli($args, $assoc) {
        $do = $args[0] ?? 'status';
        if ($do === 'einladen') {
            $r = self::invite((string) ($assoc['email'] ?? ''), (string) ($assoc['name'] ?? ''), ['no_mail' => isset($assoc['ohne-mail'])]);
            if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
            WP_CLI::line('ERGEBNIS ' . wp_json_encode($r));
            WP_CLI::success(($r['created'] ? 'Konto angelegt' : 'Konto vorhanden') . ($r['mailed'] ? ', Einladung verschickt.' : ', keine Mail.'));
            return;
        }
        WP_CLI::line('STARTKLAR ' . wp_json_encode(self::readiness()));
    }
}

TIX_Owner_Access::init();
