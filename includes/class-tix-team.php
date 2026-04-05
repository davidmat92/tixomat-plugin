<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Team – Team-Mitglieder für Veranstalter.
 *
 * Ermöglicht Veranstaltern, weitere Benutzer mit abgestuften Rollen
 * (Admin, Mitarbeiter, Check-in) zu ihrem Organizer hinzuzufügen.
 *
 * Speicherung: User-Meta auf jedem Team-Mitglied:
 *   _tix_team_organizer_id  → Post-ID des tix_organizer CPT
 *   _tix_team_role          → admin | mitarbeiter | checkin
 *
 * @since 1.34.234
 */
class TIX_Team {

    const ROLE_ADMIN       = 'admin';
    const ROLE_MITARBEITER = 'mitarbeiter';
    const ROLE_CHECKIN     = 'checkin';

    const META_ORG_ID  = '_tix_team_organizer_id';
    const META_ROLE    = '_tix_team_role';

    /** @var array Rollen mit Labels */
    private static $roles = [
        'admin'       => 'Admin',
        'mitarbeiter' => 'Mitarbeiter',
        'checkin'     => 'Check-in',
    ];

    /** @var array Berechtigungs-Matrix */
    private static $permissions = [
        'edit_events'      => ['admin' => true, 'mitarbeiter' => true, 'checkin' => false],
        'view_tickets'     => ['admin' => true, 'mitarbeiter' => true, 'checkin' => false],
        'view_orders'      => ['admin' => true, 'mitarbeiter' => true, 'checkin' => false],
        'view_statistics'  => ['admin' => true, 'mitarbeiter' => true, 'checkin' => false],
        'view_guestlist'   => ['admin' => true, 'mitarbeiter' => true, 'checkin' => true],
        'perform_checkin'  => ['admin' => true, 'mitarbeiter' => true, 'checkin' => true],
        'manage_billing'   => ['admin' => true, 'mitarbeiter' => false, 'checkin' => false],
        'manage_settings'  => ['admin' => true, 'mitarbeiter' => false, 'checkin' => false],
        'manage_team'      => ['admin' => true, 'mitarbeiter' => false, 'checkin' => false],
    ];

    /** @var array<int, int|false> Cache: user_id → organizer_id */
    private static $org_cache = [];

    /** @var array<int, string> Cache: user_id → role */
    private static $role_cache = [];

    /* ══════════════════════════════════════════
     * Bootstrap
     * ══════════════════════════════════════════ */

    public static function init() {
        // AJAX Endpoints
        $actions = [
            'tix_team_get_members'    => 'ajax_get_members',
            'tix_team_add_member'     => 'ajax_add_member',
            'tix_team_update_member'  => 'ajax_update_member',
            'tix_team_remove_member'  => 'ajax_remove_member',
        ];
        foreach ($actions as $action => $method) {
            add_action('wp_ajax_' . $action, [__CLASS__, $method]);
        }

        // Cleanup bei Organizer-Löschung
        add_action('before_delete_post', [__CLASS__, 'on_organizer_delete']);
    }

    /* ══════════════════════════════════════════
     * Rollen & Labels
     * ══════════════════════════════════════════ */

    public static function get_roles() {
        return self::$roles;
    }

    public static function is_valid_role($role) {
        return isset(self::$roles[$role]);
    }

    /* ══════════════════════════════════════════
     * Lookup
     * ══════════════════════════════════════════ */

    /**
     * Organizer-ID für einen User (Owner ODER Team-Mitglied).
     * Cached pro Request.
     */
    public static function get_organizer_for_user($user_id) {
        if (isset(self::$org_cache[$user_id])) {
            return self::$org_cache[$user_id];
        }

        // 1. Owner-Check (bestehende Logik)
        $orgs = get_posts([
            'post_type'      => 'tix_organizer',
            'meta_key'       => '_tix_org_user_id',
            'meta_value'     => intval($user_id),
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);
        if (!empty($orgs)) {
            self::$org_cache[$user_id] = $orgs[0];
            return $orgs[0];
        }

        // 2. Team-Mitglied-Check
        $team_org_id = get_user_meta($user_id, self::META_ORG_ID, true);
        if ($team_org_id && get_post_type(intval($team_org_id)) === 'tix_organizer') {
            self::$org_cache[$user_id] = intval($team_org_id);
            return intval($team_org_id);
        }

        self::$org_cache[$user_id] = false;
        return false;
    }

    /**
     * Rolle eines Users: 'owner', 'admin', 'mitarbeiter', 'checkin' oder false.
     */
    public static function get_user_role($user_id) {
        if (isset(self::$role_cache[$user_id])) {
            return self::$role_cache[$user_id];
        }

        // Owner?
        $orgs = get_posts([
            'post_type'      => 'tix_organizer',
            'meta_key'       => '_tix_org_user_id',
            'meta_value'     => intval($user_id),
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);
        if (!empty($orgs)) {
            self::$role_cache[$user_id] = 'owner';
            return 'owner';
        }

        // Team-Mitglied?
        $role = get_user_meta($user_id, self::META_ROLE, true);
        if ($role && self::is_valid_role($role)) {
            self::$role_cache[$user_id] = $role;
            return $role;
        }

        self::$role_cache[$user_id] = false;
        return false;
    }

    /**
     * Prüft ob User eine bestimmte Team-Berechtigung hat.
     * Owner haben immer alle Berechtigungen.
     */
    public static function user_can($user_id, $capability) {
        $role = self::get_user_role($user_id);
        if (!$role) return false;
        if ($role === 'owner') return true;
        return !empty(self::$permissions[$capability][$role]);
    }

    /**
     * Ist der User Owner des Organizers?
     */
    public static function is_owner($user_id, $organizer_id = null) {
        if (!$organizer_id) {
            $organizer_id = self::get_organizer_for_user($user_id);
        }
        if (!$organizer_id) return false;
        return intval(get_post_meta($organizer_id, '_tix_org_user_id', true)) === intval($user_id);
    }

    /**
     * Alle Team-Mitglieder eines Organizers (ohne Owner).
     */
    public static function get_members($organizer_id) {
        $users = get_users([
            'meta_key'   => self::META_ORG_ID,
            'meta_value' => intval($organizer_id),
        ]);

        $members = [];
        foreach ($users as $user) {
            $members[] = [
                'user_id' => $user->ID,
                'role'    => get_user_meta($user->ID, self::META_ROLE, true) ?: self::ROLE_CHECKIN,
                'name'    => $user->display_name,
                'email'   => $user->user_email,
            ];
        }

        return $members;
    }

    /* ══════════════════════════════════════════
     * CRUD
     * ══════════════════════════════════════════ */

    /**
     * Team-Mitglied hinzufügen. Erstellt WP-User wenn nötig.
     *
     * @return int|WP_Error  User-ID bei Erfolg
     */
    public static function add_member($organizer_id, $email, $first_name, $last_name, $role) {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return new \WP_Error('invalid_email', 'Ungültige E-Mail-Adresse.');
        }
        if (!self::is_valid_role($role)) {
            return new \WP_Error('invalid_role', 'Ungültige Rolle.');
        }
        if (!$first_name) {
            return new \WP_Error('missing_name', 'Vorname ist erforderlich.');
        }

        // Prüfe ob User bereits existiert
        $existing = get_user_by('email', $email);

        if ($existing) {
            // Prüfe ob schon Team-Mitglied oder Owner woanders
            $existing_org = get_user_meta($existing->ID, self::META_ORG_ID, true);
            if ($existing_org && intval($existing_org) !== intval($organizer_id)) {
                return new \WP_Error('already_team', 'Dieser Benutzer gehört bereits zu einem anderen Veranstalter.');
            }

            // Prüfe ob Owner eines Organizers
            $owner_orgs = get_posts([
                'post_type'      => 'tix_organizer',
                'meta_key'       => '_tix_org_user_id',
                'meta_value'     => $existing->ID,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            if (!empty($owner_orgs)) {
                return new \WP_Error('is_owner', 'Dieser Benutzer ist bereits Inhaber eines Veranstalters.');
            }

            // Sicherheits-Check: kein Admin/Editor
            if (array_intersect(['administrator', 'editor'], (array) $existing->roles)) {
                return new \WP_Error('admin_user', 'Administrator/Editor können nicht als Team-Mitglied hinzugefügt werden.');
            }

            $user_id = $existing->ID;

            // tix_organizer Rolle hinzufügen falls nötig
            if (!in_array('tix_organizer', (array) $existing->roles, true)) {
                $existing->add_role('tix_organizer');
            }
        } else {
            // Neuen User erstellen
            $user_id = self::create_wp_user($email, $first_name, $last_name);
            if (is_wp_error($user_id)) return $user_id;
        }

        // Meta setzen
        update_user_meta($user_id, self::META_ORG_ID, intval($organizer_id));
        update_user_meta($user_id, self::META_ROLE, $role);

        // Cache invalidieren
        unset(self::$org_cache[$user_id], self::$role_cache[$user_id]);

        return $user_id;
    }

    /**
     * Rolle eines Team-Mitglieds ändern.
     */
    public static function update_member_role($user_id, $new_role) {
        if (!self::is_valid_role($new_role)) {
            return new \WP_Error('invalid_role', 'Ungültige Rolle.');
        }

        $org_id = get_user_meta($user_id, self::META_ORG_ID, true);
        if (!$org_id) {
            return new \WP_Error('not_member', 'Benutzer ist kein Team-Mitglied.');
        }

        update_user_meta($user_id, self::META_ROLE, $new_role);
        unset(self::$role_cache[$user_id]);

        return true;
    }

    /**
     * Team-Mitglied entfernen.
     */
    public static function remove_member($user_id) {
        $org_id = get_user_meta($user_id, self::META_ORG_ID, true);
        if (!$org_id) {
            return new \WP_Error('not_member', 'Benutzer ist kein Team-Mitglied.');
        }

        delete_user_meta($user_id, self::META_ORG_ID);
        delete_user_meta($user_id, self::META_ROLE);

        // WP-Rolle entfernen wenn kein Owner eines anderen Organizers
        $owner_orgs = get_posts([
            'post_type'      => 'tix_organizer',
            'meta_key'       => '_tix_org_user_id',
            'meta_value'     => $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        if (empty($owner_orgs)) {
            $u = get_userdata($user_id);
            if ($u) $u->remove_role('tix_organizer');
        }

        // Cache invalidieren
        unset(self::$org_cache[$user_id], self::$role_cache[$user_id]);

        return true;
    }

    /* ══════════════════════════════════════════
     * User-Erstellung
     * ══════════════════════════════════════════ */

    private static function create_wp_user($email, $first_name, $last_name) {
        $username = sanitize_user(strtolower($first_name . '.' . $last_name));
        if (!$username) $username = sanitize_user(strtolower(explode('@', $email)[0]));

        $base = $username;
        $i = 1;
        while (username_exists($username)) {
            $username = $base . $i;
            $i++;
        }

        $password = wp_generate_password(16, true, true);
        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) return $user_id;

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => sanitize_text_field($first_name),
            'last_name'    => sanitize_text_field($last_name),
            'display_name' => sanitize_text_field($first_name . ' ' . $last_name),
            'role'         => 'tix_organizer',
        ]);

        // Welcome-Mail mit Passwort-Reset-Link
        wp_new_user_notification($user_id, null, 'user');

        return $user_id;
    }

    /* ══════════════════════════════════════════
     * Cleanup bei Organizer-Löschung
     * ══════════════════════════════════════════ */

    public static function on_organizer_delete($post_id) {
        if (get_post_type($post_id) !== 'tix_organizer') return;

        $members = self::get_members($post_id);
        foreach ($members as $m) {
            self::remove_member($m['user_id']);
        }
    }

    /* ══════════════════════════════════════════
     * AJAX Endpoints
     * ══════════════════════════════════════════ */

    private static function ajax_guard() {
        // Akzeptiere beide Nonces (Dashboard + Admin)
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'tix_organizer_dashboard') &&
            !wp_verify_nonce($_REQUEST['nonce'] ?? '', 'tix_team_nonce') &&
            !check_ajax_referer('tix_team_nonce', 'nonce', false)) {
            // Fallback: Dashboard-Nonce
            check_ajax_referer('tix_organizer_dashboard', 'nonce');
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Nicht eingeloggt.']);
            return null;
        }

        $user_id = get_current_user_id();

        // WP-Admins dürfen jeden Organizer verwalten (via POST organizer_id)
        if (current_user_can('manage_options')) {
            $org_id = intval($_REQUEST['organizer_id'] ?? 0);
            if (!$org_id || get_post_type($org_id) !== 'tix_organizer') {
                wp_send_json_error(['message' => 'Ungültiger Veranstalter.']);
                return null;
            }
            return $org_id;
        }

        $org_id = self::get_organizer_for_user($user_id);
        if (!$org_id) {
            wp_send_json_error(['message' => 'Kein Veranstalter-Zugang.']);
            return null;
        }

        if (!self::user_can($user_id, 'manage_team')) {
            wp_send_json_error(['message' => 'Keine Berechtigung zur Team-Verwaltung.']);
            return null;
        }

        return $org_id;
    }

    public static function ajax_get_members() {
        $org_id = self::ajax_guard();
        if (!$org_id) return;

        $members = self::get_members($org_id);

        // Owner hinzufügen
        $owner_id = intval(get_post_meta($org_id, '_tix_org_user_id', true));
        $owner = get_userdata($owner_id);
        $owner_data = $owner ? [
            'user_id' => $owner->ID,
            'role'    => 'owner',
            'name'    => $owner->display_name,
            'email'   => $owner->user_email,
        ] : null;

        wp_send_json_success([
            'owner'   => $owner_data,
            'members' => $members,
            'roles'   => self::$roles,
        ]);
    }

    public static function ajax_add_member() {
        $org_id = self::ajax_guard();
        if (!$org_id) return;

        $email      = sanitize_email($_POST['email'] ?? '');
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
        $role       = sanitize_text_field($_POST['role'] ?? self::ROLE_CHECKIN);

        $result = self::add_member($org_id, $email, $first_name, $last_name, $role);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $user = get_userdata($result);
        wp_send_json_success([
            'message' => 'Team-Mitglied hinzugefügt.',
            'member'  => [
                'user_id' => $user->ID,
                'role'    => $role,
                'name'    => $user->display_name,
                'email'   => $user->user_email,
            ],
        ]);
    }

    public static function ajax_update_member() {
        $org_id = self::ajax_guard();
        if (!$org_id) return;

        $user_id  = intval($_POST['user_id'] ?? 0);
        $new_role = sanitize_text_field($_POST['role'] ?? '');

        // Sicherheit: nur eigene Team-Mitglieder
        $member_org = get_user_meta($user_id, self::META_ORG_ID, true);
        if (intval($member_org) !== intval($org_id)) {
            wp_send_json_error(['message' => 'Benutzer gehört nicht zu diesem Veranstalter.']);
            return;
        }

        $result = self::update_member_role($user_id, $new_role);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success(['message' => 'Rolle aktualisiert.']);
    }

    public static function ajax_remove_member() {
        $org_id = self::ajax_guard();
        if (!$org_id) return;

        $user_id = intval($_POST['user_id'] ?? 0);

        // Sicherheit: nur eigene Team-Mitglieder
        $member_org = get_user_meta($user_id, self::META_ORG_ID, true);
        if (intval($member_org) !== intval($org_id)) {
            wp_send_json_error(['message' => 'Benutzer gehört nicht zu diesem Veranstalter.']);
            return;
        }

        $result = self::remove_member($user_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success(['message' => 'Team-Mitglied entfernt.']);
    }
}
