<?php
/**
 * Team-Verwaltung für die App (nur WordPress-Admins).
 *
 * Vergibt die App-Rollen an Nutzer, ohne das WordPress-Backend:
 *   - "admin"    -> Rolle tix_organizer (voller Veranstalter-Bereich)
 *   - "dj"       -> Rolle tix_dj (nur Musikwunsch-Liste)
 *   - "entrance" -> Rolle tix_entrance (nur Einlass/Scan + Namensliste)
 *
 * Echte WordPress-Administratoren werden angezeigt, aber NICHT über die App
 * geaendert (Schutz vor versehentlichem Aussperren). Basisrollen (z. B.
 * tix_customer) bleiben erhalten - es werden nur die App-Rollen umgesetzt.
 * Erweiterbar: weitere App-Rollen einfach in APP_ROLES ergaenzen.
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
    ];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
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
    }

    /** Nur echte WordPress-Admins duerfen das Team verwalten. */
    public static function check_admin(WP_REST_Request $req) {
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
        return [
            'id'       => $u->ID,
            'name'     => $u->display_name ?: $u->user_login,
            'email'    => $u->user_email,
            'role'     => $key,
            'editable' => $key !== 'admin_wp',
        ];
    }

    /** GET /team - alle Nutzer mit App-Rolle + WordPress-Admins. */
    public static function rest_list(WP_REST_Request $req) {
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
            'roles' => [
                ['key' => 'admin', 'label' => 'Admin', 'hint' => 'Voller Veranstalter-Bereich'],
                ['key' => 'entrance', 'label' => 'Eingang', 'hint' => 'Nur Einlass: scannen + Namensliste'],
                ['key' => 'dj', 'label' => 'DJ', 'hint' => 'Nur Musikwunsch-Liste'],
            ],
        ], 200);
    }

    /** POST /team {email, name?, role} - Person per E-Mail hinzufuegen/rollen. */
    public static function rest_add(WP_REST_Request $req) {
        $email = sanitize_email((string) $req->get_param('email'));
        $role  = sanitize_key((string) $req->get_param('role'));
        $name  = sanitize_text_field((string) $req->get_param('name'));
        if (!is_email($email)) {
            return new WP_Error('tix_bad_email', 'Bitte eine gueltige E-Mail-Adresse angeben.', ['status' => 400]);
        }
        if (!isset(self::APP_ROLES[$role])) {
            return new WP_Error('tix_bad_role', 'Unbekannte Rolle.', ['status' => 400]);
        }

        $user = get_user_by('email', $email);
        if (!$user) {
            $login = self::unique_login($email);
            $uid = wp_insert_user([
                'user_login'   => $login,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(20),
                'display_name' => $name !== '' ? $name : $login,
                'role'         => self::APP_ROLES[$role],
            ]);
            if (is_wp_error($uid)) return $uid;
            $user = get_user_by('id', $uid);
        } else {
            if (in_array('administrator', (array) $user->roles, true)) {
                return new WP_Error('tix_is_admin', 'Diese Person ist bereits Administrator.', ['status' => 409]);
            }
            if ($name !== '' && $name !== $user->display_name) {
                wp_update_user(['ID' => $user->ID, 'display_name' => $name]);
            }
            self::apply_role($user, $role);
        }
        return new WP_REST_Response(['ok' => true, 'member' => self::member(get_user_by('id', $user->ID))], 200);
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
        self::apply_role($user, $role);
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
        self::apply_role($user, 'none');
        return new WP_REST_Response(['ok' => true], 200);
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
}
