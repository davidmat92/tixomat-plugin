<?php
/**
 * Team-Verwaltung für die App (nur WordPress-Admins).
 *
 * Vergibt die App-Rollen an Nutzer, ohne das WordPress-Backend:
 *   - "admin"    -> Rolle tix_organizer (voller Veranstalter-Bereich)
 *   - "dj"       -> Rolle tix_dj (nur Musikwunsch-Liste)
 *   - "entrance" -> Rolle tix_entrance (nur Einlass/Scan + Namensliste)
 *   - "awareness" -> Rolle tix_awareness (nur Awareness: Dienst + Hinweise, TIX_Awareness_Live)
 *   - "cashier"  -> Rolle tix_cashier „Kasse & Bar“ (nur Abendkasse /pos/* und Guthaben abbuchen/aufladen)
 *
 * POST /team {email, name?, role, invite?} – mit invite: true geht eine Einladungs-Mail raus (1.38.441).
 * Passwort (1.38.587): POST /team {…, password_mode: invite|set, password?} – invite = Mail „Du wurdest ins Team … eingeladen“
 * mit 6-stelligem Code (7 Tage, gleicher Weg wie /auth/code purpose reset) zum selbst Festlegen; set = Admin legt das Passwort
 * fest (nicht für die Rolle Admin, nur für Konten ohne eigenes Passwort). Admin-Rolle: Einladung Pflicht, Status „Einladung
 * offen“ (`invite_pending`) bis das Passwort gesetzt ist. POST /team/{id}/invite = erneut senden, POST /team/{id}/password {password}.
 * Passwörter werden nie geloggt oder zurückgegeben.
 *
 * Echte WordPress-Administratoren werden angezeigt, aber NICHT über die App
 * geaendert (Schutz vor versehentlichem Aussperren). Basisrollen (z. B.
 * tix_customer) bleiben erhalten - es werden nur die App-Rollen umgesetzt.
 * Erweiterbar: weitere App-Rollen einfach in APP_ROLES ergaenzen.
 *
 * Mehr-Veranstalter-Modus (TIX_App_Scope): Der Inhaber eines Veranstalters
 * (und sein Team-Admin) verwaltet hier NUR sein eigenes Team. Mitglieder
 * tragen User-Meta `_tix_team_organizer_id`; fremde Team-Mitglieder,
 * Inhaber anderer Veranstalter und WordPress-Admins werden nie uebernommen.
 *
 * @package Tixomat
 */

if (!defined('ABSPATH')) exit;

class TIX_Team {
    const NS = 'tixomat/v1';

    /** App-Rollen-Schluessel -> WordPress-Rolle. */
    const APP_ROLES = [
        'admin'    => 'tix_organizer',
        'dj'       => 'tix_dj',
        'entrance' => 'tix_entrance',
        'awareness' => 'tix_awareness',
        'cashier'  => 'tix_cashier',
    ];

    /** Rollen-Liste für die Auswahl (App, evendis Studio). */
    private static function role_list($multi) {
        return [
            ['key' => 'admin', 'label' => $multi ? 'Team-Admin' : 'Admin', 'hint' => $multi ? 'Voller Veranstalter-Bereich (Events, Kasse, Bestellungen)' : 'Voller Veranstalter-Bereich'],
            ['key' => 'entrance', 'label' => 'Eingang', 'hint' => 'Nur Einlass: scannen + Namensliste'],
            ['key' => 'cashier', 'label' => 'Kasse & Bar', 'hint' => 'Nur verkaufen (Abendkasse) und Guthaben abbuchen bzw. aufladen'],
            ['key' => 'awareness', 'label' => 'Awareness', 'hint' => 'Nur Awareness: Dienst und Hinweise'],
            ['key' => 'dj', 'label' => 'DJ', 'hint' => 'Nur Musikwunsch-Liste'],
        ];
    }

    private static function role_label($key) {
        foreach (self::role_list(false) as $r) if ($r['key'] === $key) return $key === 'admin' ? 'Team-Admin' : $r['label'];
        return $key;
    }

    const META_INVITE  = '_tix_team_invite';   // Zeitpunkt der letzten Einladung, solange das Passwort fehlt
    const META_CREATED = '_tix_team_created';  // Konto wurde über das Team angelegt (Admin darf ein erstes Passwort setzen)
    const INVITE_TTL   = 7 * DAY_IN_SECONDS;
    const MIN_PASSWORD = 10;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Passwort gesetzt (Code, WP-Admin, Zurücksetzen) → Einladung erledigt
        add_action('password_reset', function ($user) { if ($user instanceof WP_User) delete_user_meta($user->ID, self::META_INVITE); });
        add_action('wp_set_password', function ($pw, $uid) { delete_user_meta(intval($uid), self::META_INVITE); }, 10, 2);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/team', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'rest_list'],
                'permission_callback' => [__CLASS__, 'check_admin'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_add'],
                'permission_callback' => [__CLASS__, 'check_admin'],
            ],
        ]);
        register_rest_route(self::NS, '/team/(?P<id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_set_role'],
                'permission_callback' => [__CLASS__, 'check_admin'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [__CLASS__, 'rest_remove'],
                'permission_callback' => [__CLASS__, 'check_admin'],
            ],
        ]);
        register_rest_route(self::NS, '/team/(?P<id>\d+)/invite', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_invite'], 'permission_callback' => [__CLASS__, 'check_admin'],
        ]);
        register_rest_route(self::NS, '/team/(?P<id>\d+)/password', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_password'], 'permission_callback' => [__CLASS__, 'check_admin'],
        ]);
    }

    /** Nur echte WordPress-Admins duerfen das Team verwalten (Mehr-Veranstalter-Modus: auch der Veranstalter fuer sein Team). */
    public static function check_admin(WP_REST_Request $req) {
        // Vertrieb (1.38.508): keine Team-Verwaltung im Demo Club
        if (class_exists('TIX_Sales') && TIX_Sales::is_sales_user()) return new WP_Error('rest_forbidden', 'Das Team des Beispiel-Clubs verwaltet die Plattform.', ['status' => 403]);
        if (is_user_logged_in() && !current_user_can('manage_options') && class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            if (TIX_App_Scope::is_org_manager()) return true;
            $r = TIX_App_Scope::require_organizer();
            return is_wp_error($r) ? $r : TIX_App_Scope::deny('Nur der Veranstalter selbst kann sein Team verwalten.');
        }
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', 'Nur Admins duerfen das Team verwalten.', ['status' => 403]);
        }
        return true;
    }

    private static function wp_roles() {
        return array_values(self::APP_ROLES);
    }

    /** App-Rollen-Schluessel eines Nutzers (admin_wp, admin, dj, entrance oder null). */
    private static function role_key(WP_User $u) {
        if (in_array('administrator', (array) $u->roles, true)) return 'admin_wp';
        foreach (self::APP_ROLES as $key => $wp) {
            if (in_array($wp, (array) $u->roles, true)) return $key;
        }
        return null;
    }

    private static function member($u) {
        $key = self::role_key($u);
        $inv = intval(get_user_meta($u->ID, self::META_INVITE, true));
        return [
            'id'       => $u->ID,
            'name'     => $u->display_name ?: $u->user_login,
            'email'    => $u->user_email,
            'role'     => $key,
            'editable' => $key !== 'admin_wp',
            // 1.38.587: Einladung offen (Passwort noch nicht gesetzt) und ob der Admin ein Passwort setzen darf
            'invite_pending'   => $inv > 0,
            'invite_sent_at'   => $inv ? wp_date('c', $inv) : null,
            // 1.38.590: Admin setzt das Passwort für jede Rolle außer Admin – nie fürs eigene Konto
            'can_set_password' => $key !== 'admin_wp' && $key !== 'admin' && $u->ID !== get_current_user_id() && self::admin_may_set($u),
        ];
    }

    /** Erstes Passwort durch den Admin nur für Konten ohne eigenes Passwort (über das Team angelegt bzw. passwortlos). */
    private static function may_set_password(WP_User $u) {
        if (in_array('administrator', (array) $u->roles, true)) return false;
        return (bool) get_user_meta($u->ID, self::META_CREATED, true) || (bool) get_user_meta($u->ID, '_tix_app_passwordless', true);
    }

    /**
     * 1.38.590: Direkt setzen darf der Site-Admin für jedes Mitglied (außer Admins). Ein Veranstalter im
     * Mehr-Veranstalter-Modus nur bei Konten, die sein Team angelegt hat – sonst könnte er ein fremdes Kundenkonto
     * ins Team holen und übernehmen. Dort bleibt der Link per Mail.
     */
    private static function admin_may_set(WP_User $u) {
        if (in_array('administrator', (array) $u->roles, true)) return false;
        return current_user_can('manage_options') || self::may_set_password($u);
    }

    /** Passwort-Regeln (gleich in Studio und App): mind. 10 Zeichen, nicht die E-Mail, nicht nur eine Zeichenart. */
    private static function password_error($pw, WP_User $u) {
        $pw = (string) $pw;
        if (strlen($pw) < self::MIN_PASSWORD) return new WP_Error('tix_weak_password', 'Das Passwort braucht mindestens ' . self::MIN_PASSWORD . ' Zeichen.', ['status' => 400, 'field' => 'password']);
        if (strlen($pw) > 200) return new WP_Error('tix_weak_password', 'Das Passwort ist zu lang.', ['status' => 400, 'field' => 'password']);
        if (stripos($pw, strtok($u->user_email, '@')) !== false || strcasecmp($pw, $u->user_email) === 0) return new WP_Error('tix_weak_password', 'Das Passwort darf die E-Mail-Adresse nicht enthalten.', ['status' => 400, 'field' => 'password']);
        $kinds = (int) preg_match('/[a-z]/', $pw) + (int) preg_match('/[A-Z]/', $pw) + (int) preg_match('/\d/', $pw) + (int) preg_match('/[^A-Za-z0-9]/', $pw);
        if ($kinds < 2) return new WP_Error('tix_weak_password', 'Bitte mische Buchstaben mit Zahlen oder Zeichen.', ['status' => 400, 'field' => 'password']);
        return null;
    }

    /** Veranstalter, auf den das Team begrenzt ist (0 = ganze Site, Admin/Ein-Club-Modus). */
    private static function scope_org() {
        if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) || current_user_can('manage_options')) return 0;
        return TIX_App_Scope::organizer_id_for_user();
    }

    /** Gehoert der Nutzer schon zu einem ANDEREN Veranstalter (Inhaber oder Team)? */
    private static function belongs_elsewhere(WP_User $u, $org) {
        $p = TIX_App_Scope::organizer_post_for_user($u->ID);
        return $p && intval($p->ID) !== intval($org);
    }

    /** GET /team im Mehr-Veranstalter-Modus: Inhaber + eigene Team-Mitglieder. */
    private static function list_scoped($org) {
        $out = [];
        $owner_id = intval(get_post_meta($org, '_tix_org_user_id', true));
        if ($owner_id && ($owner = get_user_by('id', $owner_id))) {
            $out[] = [
                'id'       => $owner->ID,
                'name'     => $owner->display_name ?: $owner->user_login,
                'email'    => $owner->user_email,
                'role'     => 'owner',
                'editable' => false,
            ];
        }
        $members = get_users([
            'meta_key'   => TIX_App_Scope::META_TEAM_ORG,
            'meta_value' => intval($org),
            'number'     => 0,
        ]);
        foreach ($members as $u) {
            if ($u->ID === $owner_id) continue;
            $m = self::member($u);
            $m['editable'] = $u->ID !== get_current_user_id() && $m['role'] !== 'admin_wp';
            $out[] = $m;
        }
        return new WP_REST_Response([
            'ok'    => true,
            'team'  => $out,
            'roles' => self::role_list(true),
        ], 200);
    }

    /** GET /team - alle Nutzer mit App-Rolle + WordPress-Admins. */
    public static function rest_list(WP_REST_Request $req) {
        if ($org = self::scope_org()) return self::list_scoped($org);
        $roles = array_merge(['administrator'], self::wp_roles());
        $seen  = [];
        $out   = [];
        foreach ($roles as $role) {
            $users = get_users(['role' => $role, 'number' => 0]);
            foreach ($users as $u) {
                if (isset($seen[$u->ID])) continue;
                $seen[$u->ID] = true;
                $out[] = self::member($u);
            }
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return new WP_REST_Response([
            'ok'    => true,
            'team'  => $out,
            'roles' => self::role_list(false),
        ], 200);
    }

    /** POST /team {email, name?, role} - Person per E-Mail hinzufuegen/rollen. */
    public static function rest_add(WP_REST_Request $req) {
        $email = sanitize_email((string) $req->get_param('email'));
        $role  = sanitize_key((string) $req->get_param('role'));
        $name  = sanitize_text_field((string) $req->get_param('name'));
        // Passwort-Weg (1.38.587): invite (Standard, für Admin Pflicht) | set (Admin legt fest); ohne Angabe wie bisher über invite
        $mode  = sanitize_key((string) $req->get_param('password_mode'));
        $pw    = (string) $req->get_param('password');
        if ($role === 'admin' && ($mode === 'set' || $pw !== '')) {
            return new WP_Error('tix_admin_invite_only', 'Admins legen ihr Passwort selbst fest – sie bekommen eine Einladung per Mail.', ['status' => 400, 'field' => 'password_mode']);
        }
        if (!is_email($email)) {
            return new WP_Error('tix_bad_email', 'Bitte eine gueltige E-Mail-Adresse angeben.', ['status' => 400]);
        }
        if (!isset(self::APP_ROLES[$role])) {
            return new WP_Error('tix_bad_role', 'Unbekannte Rolle.', ['status' => 400]);
        }

        $org  = self::scope_org();
        $user = get_user_by('email', $email);
        $created = false;
        if ($org && $user) {
            if (in_array('administrator', (array) $user->roles, true) || self::belongs_elsewhere($user, $org)) {
                return new WP_Error('tix_other_team', 'Diese Person gehoert bereits zu einem anderen Veranstalter.', ['status' => 409]);
            }
            if (intval(get_post_meta($org, '_tix_org_user_id', true)) === $user->ID) {
                return new WP_Error('tix_is_owner', 'Das ist das Konto des Veranstalters selbst.', ['status' => 409]);
            }
        }
        if (!$user) {
            $login = self::unique_login($email);
            $uid = wp_insert_user([
                'user_login'   => $login,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(32, true, true),
                'display_name' => $name !== '' ? $name : $login,
                'role'         => self::APP_ROLES[$role],
            ]);
            if (is_wp_error($uid)) return $uid;
            $user = get_user_by('id', $uid);
            $created = true;
            update_user_meta($user->ID, self::META_CREATED, 1);
            if ($org) update_user_meta($user->ID, TIX_App_Scope::META_TEAM_ORG, intval($org));
        } else {
            if (in_array('administrator', (array) $user->roles, true)) {
                return new WP_Error('tix_is_admin', 'Diese Person ist bereits Administrator.', ['status' => 409]);
            }
            if ($name !== '' && $name !== $user->display_name) {
                wp_update_user(['ID' => $user->ID, 'display_name' => $name]);
            }
            self::apply_role($user, $role);
            if ($org) update_user_meta($user->ID, TIX_App_Scope::META_TEAM_ORG, intval($org));
        }
        $user = get_user_by('id', $user->ID);
        $out = ['ok' => true, 'created' => $created];
        if ($mode === 'set') {
            // Beim Hinzufügen nie ein fremdes Konto überschreiben – für bestehende Mitglieder gibt es POST /team/{id}/password
            if (!self::may_set_password($user)) {
                return new WP_Error('tix_has_password', 'Diese Person hat schon ein eigenes Konto mit Passwort. Schick ihr stattdessen die Einladung.', ['status' => 409, 'field' => 'password_mode', 'member' => self::member($user)]);
            }
            if ($e = self::password_error($pw, $user)) return $e;
            wp_set_password($pw, $user->ID);
            self::end_sessions($user->ID);
            delete_user_meta($user->ID, '_tix_app_passwordless');
            self::log('team_password_set', $user, ['role' => $role, 'first' => true]);
            $out['password_set'] = true;
        } elseif ($mode === 'invite' || $role === 'admin' || filter_var($req->get_param('invite'), FILTER_VALIDATE_BOOLEAN)) {
            $out['invited'] = (bool) self::send_invite($user, $role, $org, $created);
        }
        $out['member'] = self::member(get_user_by('id', $user->ID));
        return new WP_REST_Response($out, 200);
    }

    /** POST /team/{id}/invite – Einladung (neuer Code) erneut senden; höchstens 5 je Stunde und Person. */
    public static function rest_invite(WP_REST_Request $req) {
        $user = self::editable_user($req);
        if (is_wp_error($user)) return $user;
        $n = intval(get_transient('tix_team_inv_n_' . $user->ID));
        if ($n >= 5) return new WP_Error('tix_rate_limit', 'Zu viele Einladungen. Bitte versuche es in einer Stunde noch einmal.', ['status' => 429]);
        set_transient('tix_team_inv_n_' . $user->ID, $n + 1, HOUR_IN_SECONDS);
        // Einladung offen → Einladung erneut; sonst Link zum Passwort-Setzen (das alte gilt nach dem Setzen nicht mehr)
        $kind = get_user_meta($user->ID, self::META_INVITE, true) ? 'invite' : 'reset';
        $sent = (bool) self::send_invite($user, self::role_key($user) ?: 'admin', self::scope_org(), false, $kind);
        if (!$sent) return new WP_Error('tix_mail_failed', 'Die E-Mail konnte nicht gesendet werden.', ['status' => 502]);
        self::log($kind === 'invite' ? 'team_invite_sent' : 'team_reset_sent', $user, ['role' => self::role_key($user)]);
        return new WP_REST_Response(['ok' => true, 'invited' => $kind === 'invite', 'reset_sent' => $kind === 'reset', 'member' => self::member(get_user_by('id', $user->ID))], 200);
    }

    /** POST /team/{id}/password {password} – Admin legt das Passwort fest (nicht für Admins, nur ohne eigenes Passwort). */
    public static function rest_password(WP_REST_Request $req) {
        $user = self::editable_user($req);
        if (is_wp_error($user)) return $user;
        if (self::role_key($user) === 'admin') return new WP_Error('tix_admin_invite_only', 'Admins legen ihr Passwort selbst fest – schick ihnen die Einladung.', ['status' => 400]);
        if (!self::admin_may_set($user)) return new WP_Error('tix_has_password', 'Diese Person hat ein eigenes Konto. Schick ihr den Link zum Passwort-Setzen.', ['status' => 409]);
        $pw = (string) $req->get_param('password');
        if ($e = self::password_error($pw, $user)) return $e;
        // 1.38.590: für jedes Mitglied (außer Admins); hatte die Person schon ein Passwort, bekommt sie eine Info-Mail
        $first = (bool) get_user_meta($user->ID, self::META_INVITE, true);
        wp_set_password($pw, $user->ID);
        self::end_sessions($user->ID);
        delete_user_meta($user->ID, '_tix_app_passwordless');
        self::log('team_password_set', $user, ['role' => self::role_key($user), 'first' => $first]);
        $notified = $first ? false : self::send_changed_notice($user);
        return new WP_REST_Response(['ok' => true, 'password_set' => true, 'notified' => $notified, 'member' => self::member(get_user_by('id', $user->ID))], 200);
    }

    /** Mitglied aus {id}: vorhanden, kein WP-Admin, im Mehr-Veranstalter-Modus im eigenen Team. */
    private static function editable_user(WP_REST_Request $req) {
        $user = get_user_by('id', absint($req['id']));
        if (!$user || !self::role_key($user)) return new WP_Error('tix_no_user', 'Nutzer nicht gefunden.', ['status' => 404]);
        if (in_array('administrator', (array) $user->roles, true)) return new WP_Error('tix_is_admin', 'Administratoren werden im WordPress-Backend verwaltet.', ['status' => 409]);
        if ($user->ID === get_current_user_id()) return new WP_Error('tix_self', 'Dein eigenes Passwort änderst du unter Konto.', ['status' => 409]);
        if ($org = self::scope_org()) { $own = self::own_member_error($user, $org); if ($own) return $own; }
        return $user;
    }

    /**
     * Einladungs-Mail „Du wurdest ins Team von … eingeladen“ (1.38.587): Rolle, 6-stelliger Code (7 Tage) und Knopf zum
     * Passwort-Festlegen im Studio (bzw. in der App „Passwort vergessen“ → Code). Konten ohne eigenes Passwort bekommen den
     * Status „Einladung offen“, bis es gesetzt ist. Der Code steht nur in der Mail, nie im Link oder Log.
     */
    private static function send_invite(WP_User $user, $role, $org, $created, $kind = 'invite') {
        if ($kind === 'reset') return self::send_reset_link($user, $role, $org);
        $site  = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $team  = $org ? html_entity_decode(get_the_title($org), ENT_QUOTES, 'UTF-8') : $site;
        $by    = wp_get_current_user()->display_name;
        $needs = self::may_set_password($user);
        $code  = class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'issue_invite_code') ? TIX_App_Account::issue_invite_code($user->user_email, self::INVITE_TTL) : '';
        $studio = class_exists('TIX_Studio') && TIX_Studio::active() && !in_array($role, ['dj', 'awareness'], true);
        $url = $studio ? add_query_arg(['email' => $user->user_email], TIX_Studio::url('/passwort/')) : '';
        $app = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'app_info') ? (string) (TIX_Studio::app_info()['name'] ?? '') : '';
        $body  = '<p style="font-size:17px;font-weight:700;margin:0 0 12px;">Du wurdest ins Team von ' . esc_html($team) . ' eingeladen</p>';
        $body .= '<p>Hallo ' . esc_html($user->display_name ?: '') . ',</p>';
        $body .= '<p>' . esc_html($by ?: $team) . ' hat dich eingeladen. Deine Rolle: <strong>' . esc_html(self::role_label($role)) . '</strong>.</p>';
        if ($code !== '') {
            $body .= '<p>' . ($needs ? 'Leg jetzt dein Passwort fest' : 'Du kannst dich mit deinem bisherigen Passwort anmelden oder ein neues festlegen') . ' – mit diesem Code:</p>';
            $body .= '<p style="text-align:center;margin:20px 0;"><span style="display:inline-block;padding:14px 22px;border-radius:12px;background:#F2F3F7;font:700 28px/1 ui-monospace,Menlo,monospace;letter-spacing:6px;">' . esc_html($code) . '</span></p>';
            if ($url) $body .= '<p style="text-align:center;margin:20px 0;"><a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 22px;background:#2C2F3F;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;">Passwort festlegen</a></p>';
            $body .= '<p>' . ($url ? 'Oder in der App' . ($app ? ' ' . esc_html($app) : '') : 'In der App' . ($app ? ' ' . esc_html($app) : '')) . ': Veranstalter-Anmeldung → „Passwort vergessen“ → diese E-Mail-Adresse (' . esc_html($user->user_email) . ') und den Code eingeben.</p>';
            $body .= '<p style="color:#64748b;font-size:13px;">Der Code gilt 7 Tage und nur für dich. Gib ihn nie weiter – auch nicht an uns.</p>';
        } else {
            $body .= '<p>Melde dich in der App mit dieser E-Mail-Adresse (' . esc_html($user->user_email) . ') und „Code per E-Mail“ an.</p>';
        }
        $body .= '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Du kennst das Team nicht? Dann ignoriere diese E-Mail – ohne Code passiert nichts.</p>';
        $html = class_exists('TIX_Emails') ? TIX_Emails::build_generic_email_html('Einladung ins Team', $body, $team) : '<html><body>' . $body . '</body></html>';
        $subject = 'Du wurdest ins Team von ' . $team . ' eingeladen';
        $ok = class_exists('TIX_Customer_URL')
            ? TIX_Customer_URL::mail($user->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8'])
            : wp_mail($user->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        if ($ok && $needs) update_user_meta($user->ID, self::META_INVITE, time());
        return $ok;
    }

    /** Team-Name für Mails: Veranstalter im Mehr-Veranstalter-Modus, sonst die Site. */
    private static function team_name($org) {
        return html_entity_decode($org ? get_the_title($org) : get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
    }

    private static function team_mail(WP_User $user, $subject, $title, $body, $team) {
        $html = class_exists('TIX_Emails') ? TIX_Emails::build_generic_email_html($title, $body, $team) : '<html><body>' . $body . '</body></html>';
        return class_exists('TIX_Customer_URL')
            ? TIX_Customer_URL::mail($user->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8'])
            : wp_mail($user->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * 1.38.590: „Link zum Passwort-Setzen“ für bestehende Mitglieder – 6-stelliger Code (60 min) und Knopf ins Studio.
     * Mit dem Code legt die Person ein neues Passwort fest; das alte gilt dann nicht mehr, alle Sitzungen enden
     * (TIX_App_Account::confirm_code, purpose reset). Bis dahin bleibt das alte Passwort gültig.
     */
    private static function send_reset_link(WP_User $user, $role, $org) {
        $team = self::team_name($org);
        $code = class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'issue_invite_code') ? TIX_App_Account::issue_invite_code($user->user_email, HOUR_IN_SECONDS) : '';
        if ($code === '') return false;
        $studio = class_exists('TIX_Studio') && TIX_Studio::active() && !in_array($role, ['dj', 'awareness'], true);
        $url = $studio ? add_query_arg(['email' => $user->user_email], TIX_Studio::url('/passwort/')) : '';
        $app = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'app_info') ? (string) (TIX_Studio::app_info()['name'] ?? '') : '';
        $by  = wp_get_current_user()->display_name;
        $body  = '<p style="font-size:17px;font-weight:700;margin:0 0 12px;">Neues Passwort festlegen</p>';
        $body .= '<p>Hallo ' . esc_html($user->display_name ?: '') . ',</p>';
        $body .= '<p>' . esc_html($by ?: $team) . ' aus dem Team von ' . esc_html($team) . ' hat dir einen Link zum Passwort-Setzen geschickt. Mit diesem Code legst du ein neues Passwort fest:</p>';
        $body .= '<p style="text-align:center;margin:20px 0;"><span style="display:inline-block;padding:14px 22px;border-radius:12px;background:#F2F3F7;font:700 28px/1 ui-monospace,Menlo,monospace;letter-spacing:6px;">' . esc_html($code) . '</span></p>';
        if ($url) $body .= '<p style="text-align:center;margin:20px 0;"><a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 22px;background:#2C2F3F;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;">Passwort festlegen</a></p>';
        $body .= '<p>' . ($url ? 'Oder in der App' : 'In der App') . ($app ? ' ' . esc_html($app) : '') . ': Veranstalter-Anmeldung → „Passwort vergessen“ → diese E-Mail-Adresse (' . esc_html($user->user_email) . ') und den Code eingeben.</p>';
        $body .= '<p style="color:#64748b;font-size:13px;">Der Code gilt 60 Minuten. Danach gilt nur noch das neue Passwort, und du wirst auf allen Geräten abgemeldet. Gib den Code nie weiter.</p>';
        $body .= '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Du hast das nicht erwartet? Dann ignoriere diese E-Mail – ohne Code bleibt dein Passwort, wie es ist.</p>';
        return self::team_mail($user, 'Neues Passwort festlegen – ' . $team, 'Neues Passwort', $body, $team);
    }

    /** Kurze Info an die Person, wenn der Admin ihr Passwort geändert hat (ohne das Passwort). */
    private static function send_changed_notice(WP_User $user) {
        $team = self::team_name(self::scope_org());
        $by   = wp_get_current_user()->display_name;
        $body  = '<p style="font-size:17px;font-weight:700;margin:0 0 12px;">Dein Passwort wurde vom Team geändert</p>';
        $body .= '<p>Hallo ' . esc_html($user->display_name ?: '') . ',</p>';
        $body .= '<p>' . esc_html($by ?: $team) . ' hat dein Passwort für ' . esc_html($team) . ' am ' . esc_html(wp_date('d.m.Y, H:i')) . ' Uhr neu festgelegt. Du wurdest auf allen Geräten abgemeldet. Das neue Passwort bekommst du persönlich.</p>';
        $body .= '<p style="color:#64748b;font-size:13px;">Du weißt nichts davon? Dann melde dich bitte direkt beim Team.</p>';
        return (bool) self::team_mail($user, 'Dein Passwort wurde vom Team geändert', 'Passwort geändert', $body, $team);
    }

    /** Alle Sitzungen beenden: WordPress-Cookies (Studio/Backend) und App-Tokens. */
    private static function end_sessions($uid) {
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($uid)->destroy_all();
        delete_user_meta($uid, '_tix_app_tokens');
        delete_user_meta($uid, '_tix_app_token');
    }

    /** Ereignis ins Änderungsprotokoll (tix_audit_log) – nie mit Passwort oder Code. */
    private static function log($action, WP_User $user, array $summary) {
        if (!class_exists('TIX_Customers') || !method_exists('TIX_Customers', 'audit')) return;
        $org = intval(self::scope_org());
        TIX_Customers::audit($action, (string) $user->ID, ['oid' => $org, 'scope' => $org ? 'organizer' : 'site'], $summary);
    }

    /** POST /team/{id} {role} - Rolle aendern (admin|dj|entrance|none). */

    public static function rest_set_role(WP_REST_Request $req) {
        $id   = absint($req['id']);
        $role = sanitize_key((string) $req->get_param('role'));
        $user = get_user_by('id', $id);
        if (!$user) return new WP_Error('tix_no_user', 'Nutzer nicht gefunden.', ['status' => 404]);
        if (in_array('administrator', (array) $user->roles, true)) {
            return new WP_Error('tix_is_admin', 'Administratoren werden im WordPress-Backend verwaltet.', ['status' => 409]);
        }
        if ($role !== 'none' && !isset(self::APP_ROLES[$role])) {
            return new WP_Error('tix_bad_role', 'Unbekannte Rolle.', ['status' => 400]);
        }
        if ($org = self::scope_org()) {
            $own = self::own_member_error($user, $org);
            if ($own) return $own;
        }
        self::apply_role($user, $role);
        if ($role === 'none' && $org) delete_user_meta($user->ID, TIX_App_Scope::META_TEAM_ORG);
        return new WP_REST_Response(['ok' => true, 'member' => self::member(get_user_by('id', $id))], 200);
    }

    /** DELETE /team/{id} - alle App-Rollen entfernen. */
    public static function rest_remove(WP_REST_Request $req) {
        $id   = absint($req['id']);
        $user = get_user_by('id', $id);
        if (!$user) return new WP_Error('tix_no_user', 'Nutzer nicht gefunden.', ['status' => 404]);
        if (in_array('administrator', (array) $user->roles, true)) {
            return new WP_Error('tix_is_admin', 'Administratoren werden im WordPress-Backend verwaltet.', ['status' => 409]);
        }
        if ($org = self::scope_org()) {
            $own = self::own_member_error($user, $org);
            if ($own) return $own;
        }
        self::apply_role($user, 'none');
        if ($org) delete_user_meta($user->ID, TIX_App_Scope::META_TEAM_ORG);
        return new WP_REST_Response(['ok' => true], 200);
    }

    /** Mehr-Veranstalter-Modus: nur eigene Team-Mitglieder (nicht man selbst, nicht der Inhaber). */
    private static function own_member_error(WP_User $user, $org) {
        if (intval(get_user_meta($user->ID, TIX_App_Scope::META_TEAM_ORG, true)) !== intval($org)) {
            return new WP_Error('tix_no_user', 'Nutzer nicht gefunden.', ['status' => 404]);
        }
        if ($user->ID === get_current_user_id() || intval(get_post_meta($org, '_tix_org_user_id', true)) === $user->ID) {
            return new WP_Error('tix_self', 'Das eigene Konto kann hier nicht geaendert werden.', ['status' => 409]);
        }
        return null;
    }

    /**
     * Setzt genau eine App-Rolle (oder keine bei "none"). Andere App-Rollen
     * werden entfernt, sonstige Rollen (z. B. tix_customer) bleiben erhalten.
     */
    private static function apply_role(WP_User $user, $role) {
        foreach (self::APP_ROLES as $wp) {
            if (in_array($wp, (array) $user->roles, true)) $user->remove_role($wp);
        }
        if ($role !== 'none' && isset(self::APP_ROLES[$role])) {
            $user->add_role(self::APP_ROLES[$role]);
        }
        $user = get_user_by('id', $user->ID);
        if (empty($user->roles)) {
            $user->add_role(get_role('tix_customer') ? 'tix_customer' : 'subscriber');
        }
    }

    private static function unique_login($email) {
        $base = sanitize_user(strtolower(explode('@', $email)[0]), true) ?: 'nutzer';
        $login = $base;
        $i = 1;
        while (username_exists($login)) {
            $login = $base . $i;
            $i++;
        }
        return $login;
    }

    // ── Rückfall für Aufrufer der früheren Team-Klasse ──
    // Die alte Team-Mitgliedschaft mit eigenen Rollen (admin/mitarbeiter/checkin) gibt es
    // seit dem Umbau auf App-Rollen nicht mehr. Veranstalter-Dashboard, Admin-Shell und
    // Event-Caps (map_event_caps) rufen diese Methoden aber weiterhin auf; ohne sie endet
    // jeder Aufruf im Fatal Error ("Call to undefined method").

    /** Veranstalter-ID des Nutzers (Inhaber oder Team, nur freigegebene Einträge), sonst 0. */
    public static function get_organizer_for_user($user_id) {
        if (class_exists('TIX_App_Scope')) return TIX_App_Scope::organizer_id_for_user(intval($user_id));
        return 0;
    }

    /**
     * Team-Berechtigung wie die alte Rechte-Matrix: Admins, Inhaber und Team-Admins
     * (Rolle tix_organizer) dürfen alles; Eingang (App) nur Gästeliste und Check-in
     * (wie die alte Rolle „checkin“); DJ und sonstige Nutzer nichts.
     */
    public static function user_can($user_id, $capability) {
        $user = get_userdata(intval($user_id));
        if (!$user) return false;
        if ($user->has_cap('manage_options')) return true;
        if (class_exists('TIX_App_Scope')) {
            if (TIX_App_Scope::is_org_manager($user->ID)) return true;
        } elseif (get_posts([
            'post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_key' => '_tix_org_user_id', 'meta_value' => intval($user->ID),
        ])) {
            return true;
        }
        if ($user->has_cap('tix_app_entrance') || in_array('tix_entrance', (array) $user->roles, true)) {
            return in_array($capability, ['view_guestlist', 'perform_checkin'], true);
        }
        return false;
    }
}
