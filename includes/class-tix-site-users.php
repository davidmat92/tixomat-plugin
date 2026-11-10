<?php
if (!defined('ABSPATH')) exit;

/**
 * Zugänge für Kunden-Seiten aus der Plattform-Akte (1.38.724, Auftrag uebergaben/auftrag-zugaenge-aus-kunden-akte-2026-11-10.md).
 *
 * Plattform (evendis.de, nur Plattform-Admin):
 *   GET    /platform/customers/{key}/users                      → {ok, users, roles, target}
 *   POST   /platform/customers/{key}/users                      {name, login?, email?, role, password_mode: set|invite, password?}
 *   PATCH  /platform/customers/{key}/users/{id}                 {role?, name?}   (POST geht auch)
 *   POST   /platform/customers/{key}/users/{id}/password        {mode: set|invite, password?}
 *   DELETE /platform/customers/{key}/users/{id}
 * Lizenz-Kunden: signierte Anfrage an die Kunden-Seite (POST /site/users, HMAC mit dem Lizenzschlüssel, Zeitstempel, nonce).
 * Provisions-/Co-Kunden: dasselbe direkt auf evendis gegen das Team des Veranstalters.
 * Regeln wie TIX_Team: App-Rollen, nie WordPress-Admins, nie das technische Betreiber-Konto, Inhaber nie entfernbar/änderbar.
 * Passwörter nie im Klartext protokolliert oder zurückgegeben.
 */
class TIX_Site_Users {

    const NS          = 'tixomat/v1';
    const META_BY     = '_tix_platform_created'; // Konto über die Plattform-Akte angelegt
    const OPT_NONCES  = 'tix_site_users_nonces';
    const TTL         = 120;
    const FALLBACK    = 'ohne-mail.invalid';     // Ersatzadresse ohne E-Mail: <login>@<host>.ohne-mail.invalid

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function routes() {
        if (class_exists('TIX_Platform_Customers')) {
            $p = ['permission_callback' => ['TIX_Platform_Customers', 'perm']];
            $k = '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/users';
            register_rest_route(self::NS, $k, [
                ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform']] + $p,
                ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_platform']] + $p,
            ]);
            register_rest_route(self::NS, $k . '/(?P<id>\d+)', [
                ['methods' => 'PATCH, POST', 'callback' => [__CLASS__, 'rest_platform']] + $p,
                ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_platform']] + $p,
            ]);
            register_rest_route(self::NS, $k . '/(?P<id>\d+)/password', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_platform']] + $p);
            register_rest_route(self::NS, $k . '/(?P<id>\d+)/owner', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_platform']] + $p); // Inhaberschaft wechseln (1.38.727)
        }
        register_rest_route(self::NS, '/site/users', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_site'], 'permission_callback' => '__return_true']);
    }

    // ═══════════════ Plattform ═══════════════

    /** Aktion aus Methode + Pfad */
    private static function action_of(WP_REST_Request $req) {
        $m = $req->get_method();
        $id = absint($req['id'] ?? 0);
        if (!$id) return $m === 'GET' ? 'list' : 'create';
        if (preg_match('#/password$#', $req->get_route())) return 'password';
        if (preg_match('#/owner$#', $req->get_route())) return 'transfer_owner';
        // DELETE …?delete=1 bzw. {delete: true} = Konto löschen (1.38.727); ohne = nur aus dem Team (Rolle weg)
        if ($m === 'DELETE') {
            $b = $req->get_json_params();
            $d = is_array($b) && array_key_exists('delete', $b) ? $b['delete'] : $req->get_param('delete');
            return filter_var($d, FILTER_VALIDATE_BOOLEAN) ? 'delete' : 'remove';
        }
        // Form des Studios (2e4d1440): PATCH {password} | {invite: true} = Passwort setzen bzw. Link per Mail
        $b = $req->get_json_params();
        $b = is_array($b) ? $b : (array) $req->get_body_params();
        if (array_key_exists('password', $b) || !empty($b['invite'])) return 'password';
        return 'update';
    }

    private static function args_of(WP_REST_Request $req) {
        $b = $req->get_json_params();
        $b = is_array($b) ? $b : (array) $req->get_body_params();
        $a = array_intersect_key($b, array_flip(['name', 'login', 'email', 'role', 'password_mode', 'mode', 'password', 'reassign', 'old']));
        foreach (['customers', 'reassign', 'old'] as $qk) if (!array_key_exists($qk, $a) && $req->get_param($qk) !== null && !is_array($req->get_param($qk))) $a[$qk] = $req->get_param($qk); // auch als Query (1.38.727)
        // Form des Studios: {invite: true} bzw. {password} statt password_mode/mode
        $inv = filter_var($b['invite'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!isset($a['password_mode']) && !isset($a['mode'])) {
            if ($inv) { $a['password_mode'] = 'invite'; $a['mode'] = 'invite'; }
            elseif (($b['password'] ?? '') !== '') { $a['password_mode'] = 'set'; $a['mode'] = 'set'; }
        }
        if (absint($req['id'] ?? 0)) $a['id'] = absint($req['id']);
        return $a;
    }

    public static function rest_platform(WP_REST_Request $req) {
        $c = TIX_Platform_Customers::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $action = self::action_of($req);
        $args = self::args_of($req);
        $u = wp_get_current_user();
        $by = ['name' => (string) $u->display_name, 'email' => (string) $u->user_email];
        if (($c['art'] ?? '') === 'bestand') return new WP_Error('tix_customer_bestand', 'Bei KitchenKlub und Mallorca bleibt der Server unverändert – Zugänge legt die Seite selbst an.', ['status' => 409]);
        if (!empty($c['_lic'])) {
            if (!class_exists('TIX_License_Server') || !TIX_License_Server::enabled()) return new WP_Error('tix_license_server_off', 'Der Lizenz-Server ist hier nicht aktiv.', ['status' => 409]);
            $r = self::send($c, $action, $args, $by);
            $target = 'site';
        } elseif (in_array($c['art'], ['provision', 'co'], true) && !empty($c['organizer_id'])) {
            $r = self::exec($action, $args, intval($c['organizer_id']), $by);
            $target = 'organizer';
        } else {
            return new WP_Error('tix_users_no_target', 'Für diesen Kunden gibt es noch keine Seite und keinen Veranstalter.', ['status' => 409]);
        }
        if (is_wp_error($r)) return $r;
        if (!empty($c['license_id']) && class_exists('TIX_License_Server') && $action !== 'list') {
            TIX_License_Server::log(intval($c['license_id']), 'site_user_' . $action, ['by' => $by['email'], 'user' => $r['user']['login'] ?? ($args['id'] ?? null), 'role' => $args['role'] ?? null]);
        }
        // Felder für das Studio: source (site|platform), mailed
        if (isset($r['invited']) && !isset($r['mailed'])) $r['mailed'] = (bool) $r['invited'];
        $res = rest_ensure_response($r + ['target' => $target, 'source' => $target === 'site' ? 'site' : 'platform']);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /** Gebundene Seite (erste ohne dev) → Host | '' */
    private static function site_host($c) {
        if (!class_exists('TIX_License_Server')) return '';
        foreach (TIX_License_Server::sites(intval($c['license_id'])) as $s) if (!$s->dev) return strtolower(preg_replace('/^www\./', '', (string) $s->host));
        return '';
    }

    public static function sign($raw, $host, $key) {
        return hash_hmac('sha256', 'tix-users-v1|' . strtolower((string) $host) . '|' . $raw, strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key))));
    }

    /** Anfrage an die Kunden-Seite → Ergebnis-Array | WP_Error */
    private static function send($c, $action, array $args, array $by) {
        $host = self::site_host($c);
        if ($host === '') return new WP_Error('tix_operator_no_site', 'Die Lizenz ist noch an keine Seite gebunden.', ['status' => 409]);
        $raw = wp_json_encode(['action' => $action, 'args' => $args ?: new stdClass(), 'by' => $by, 'ts' => time(), 'nonce' => wp_generate_password(24, false, false)]);
        $resp = wp_remote_post('https://' . $host . '/wp-json/' . self::NS . '/site/users', ['timeout' => 20, 'body' => $raw,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Signature' => self::sign($raw, $host, (string) $c['_lic']['row']->license_key)]]);
        if (is_wp_error($resp)) return new WP_Error('tix_site_unreachable', 'Die Kunden-Seite ist gerade nicht erreichbar.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($resp));
        $d = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code === 404 && is_array($d) && ($d['code'] ?? '') === 'rest_no_route') return new WP_Error('rest_no_route', 'Die Kunden-Seite braucht dafür Plugin 1.38.724.', ['status' => 404, 'site_outdated' => true]);
        if ($code !== 200 || !is_array($d)) {
            $data = is_array($d) && is_array($d['data'] ?? null) ? $d['data'] : [];
            return new WP_Error(is_array($d) && !empty($d['code']) ? (string) $d['code'] : 'tix_users_error', is_array($d) && !empty($d['message']) ? (string) $d['message'] : 'Die Kunden-Seite hat die Anfrage abgelehnt.',
                ['status' => in_array($code, [400, 401, 403, 404, 409, 429], true) ? $code : 502] + array_intersect_key($data, ['field' => 1]));
        }
        unset($d['_sig']);
        return $d;
    }

    // ═══════════════ Kunden-Seite ═══════════════

    public static function rest_site(WP_REST_Request $req) {
        if (self::multi() || !class_exists('TIX_License') || !TIX_License::active()) return new WP_Error('tix_users_unavailable', 'Diese Seite nimmt keine Zugänge von der Plattform an.', ['status' => 404]);
        $raw = (string) $req->get_body();
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        $key = (string) get_option(TIX_License::OPT_KEY, '');
        if ($key === '' || !hash_equals(self::sign($raw, $host, $key), (string) $req->get_header('X-Tix-Signature'))) return new WP_Error('tix_users_auth', 'Ungültige Signatur.', ['status' => 401]);
        $b = json_decode($raw, true);
        if (!is_array($b) || abs(time() - intval($b['ts'] ?? 0)) > self::TTL || empty($b['nonce'])) return new WP_Error('tix_users_auth', 'Anfrage abgelaufen.', ['status' => 401]);
        $used = array_filter((array) get_option(self::OPT_NONCES, []), function ($t) { return intval($t) > time() - 2 * self::TTL; });
        if (isset($used[(string) $b['nonce']])) return new WP_Error('tix_users_auth', 'Anfrage schon ausgeführt.', ['status' => 401]);
        $used[(string) $b['nonce']] = time();
        update_option(self::OPT_NONCES, $used, false);
        $by = ['name' => mb_substr(sanitize_text_field((string) ($b['by']['name'] ?? '')), 0, 120), 'email' => sanitize_email((string) ($b['by']['email'] ?? ''))];
        $r = self::exec(sanitize_key((string) ($b['action'] ?? '')), is_array($b['args'] ?? null) ? $b['args'] : [], 0, $by);
        if (is_wp_error($r)) return $r;
        $res = rest_ensure_response($r);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    // ═══════════════ Ausführen (Kunden-Seite: $org 0, evendis: Team des Veranstalters $org) ═══════════════

    public static function roles() {
        return [
            ['key' => 'admin', 'label' => 'Admin', 'hint' => 'Voller Veranstalter-Bereich'],
            ['key' => 'entrance', 'label' => 'Eingang', 'hint' => 'Nur Einlass: scannen + Namensliste'],
            ['key' => 'cashier', 'label' => 'Kasse & Bar', 'hint' => 'Nur verkaufen (Abendkasse) und Guthaben abbuchen bzw. aufladen'],
            ['key' => 'awareness', 'label' => 'Awareness', 'hint' => 'Nur Awareness: Dienst und Hinweise'],
            ['key' => 'dj', 'label' => 'DJ', 'hint' => 'Nur Musikwunsch-Liste'],
        ];
    }

    private static function label($key) {
        foreach (self::roles() as $r) if ($r['key'] === $key) return $r['label'];
        return $key ? (string) $key : null;
    }

    private static function app_roles() {
        return class_exists('TIX_Team') ? TIX_Team::APP_ROLES : ['admin' => 'tix_organizer', 'dj' => 'tix_dj', 'entrance' => 'tix_entrance', 'awareness' => 'tix_awareness', 'cashier' => 'tix_cashier'];
    }

    private static function role_key(WP_User $u) {
        foreach (self::app_roles() as $k => $wp) if (in_array($wp, (array) $u->roles, true)) return $k;
        return null;
    }

    private static function is_fallback($email) {
        return substr(strtolower((string) $email), -strlen(self::FALLBACK) - 1) === '.' . self::FALLBACK;
    }

    private static function is_owner(WP_User $u, $org) {
        if ($org) return intval(get_post_meta($org, '_tix_org_user_id', true)) === $u->ID;
        return intval(get_user_meta($u->ID, '_tix_site_owner', true)) === 1; // TIX_Owner_Access::OWNER_META
    }

    private static function hidden(WP_User $u) {
        return in_array('administrator', (array) $u->roles, true) || get_user_meta($u->ID, '_tix_operator', true) === '1';
    }

    private static function last_login(WP_User $u) {
        $t = 0;
        foreach (['_tix_last_login', 'last_login'] as $k) { $v = get_user_meta($u->ID, $k, true); if ($v) $t = max($t, is_numeric($v) ? intval($v) : intval(strtotime((string) $v))); }
        foreach ((array) get_user_meta($u->ID, '_tix_app_tokens', true) as $tk) if (is_array($tk)) $t = max($t, intval($tk['created'] ?? 0));
        return $t ? gmdate('c', $t) : null;
    }

    /** Art eines Kontos (1.38.727): owner | team | wp_admin | technical | customer */
    private static function kind(WP_User $u, $org) {
        if (get_user_meta($u->ID, '_tix_operator', true) === '1') return 'technical';
        if (self::is_owner($u, $org)) return 'owner';
        if (!$org && in_array('administrator', (array) $u->roles, true)) return 'wp_admin';
        if (self::role_key($u) || ($org && intval(get_user_meta($u->ID, TIX_App_Scope::META_TEAM_ORG, true)) === $org)) return 'team';
        return 'customer';
    }

    /** Inhaber der Seite bzw. des Veranstalters (IDs) */
    private static function owner_ids($org) {
        if ($org) { $o = intval(get_post_meta($org, '_tix_org_user_id', true)); return $o ? [$o] : []; }
        return array_map('intval', get_users(['meta_key' => '_tix_site_owner', 'meta_value' => '1', 'fields' => 'ID', 'number' => 0]));
    }

    private static function row(WP_User $u, $org) {
        $kind = self::kind($u, $org);
        $owner = $kind === 'owner';
        $inv = class_exists('TIX_Team') ? intval(get_user_meta($u->ID, TIX_Team::META_INVITE, true)) : 0;
        $labels = ['owner' => 'Inhaber', 'wp_admin' => 'WordPress-Admin', 'technical' => 'evendis Betreiber (technisch)', 'customer' => 'Kundenkonto'];
        $role = $owner ? 'owner' : ($kind === 'wp_admin' ? 'admin_wp' : ($kind === 'technical' ? 'operator' : ($kind === 'customer' ? 'customer' : self::role_key($u))));
        $del = self::delete_block($u, $org);
        return ['id' => $u->ID, 'name' => html_entity_decode((string) ($u->display_name ?: $u->user_login), ENT_QUOTES, 'UTF-8'), 'login' => (string) $u->user_login,
            'email' => self::is_fallback($u->user_email) ? null : (string) $u->user_email, 'email_placeholder' => self::is_fallback($u->user_email),
            'role' => $role, 'role_label' => $labels[$kind] ?? self::label(self::role_key($u)), 'kind' => $kind, 'wp_roles' => array_values((array) $u->roles),
            'owner' => $owner, 'editable' => $kind === 'team', 'removable' => $kind === 'team',
            // 1.38.727: Konto löschen (mit Übertragung der Inhalte) und Inhaber werden
            'deletable' => $del === null, 'delete_hint' => $del, 'can_become_owner' => !$owner && $kind !== 'technical' && ($org ? $kind === 'team' : true),
            'last_login' => self::last_login($u), 'invite_pending' => $inv > 0, 'created_by_platform' => (bool) get_user_meta($u->ID, self::META_BY, true)];
    }

    /**
     * Mitglieder. Kunden-Seite: alle Konten (Inhaber, WordPress-Admins, Team, technische Konten; Kundenkonten nur mit
     * $customers, sonst nur gezählt). Veranstalter auf evendis: Inhaber + Team (andere Plattform-Konten gehören nicht dazu).
     */
    private static function members($org, $customers = false, &$counts = null) {
        $out = []; $seen = [];
        $counts = ['owner' => 0, 'wp_admin' => 0, 'team' => 0, 'technical' => 0, 'customer' => 0];
        if ($org) {
            $oid = intval(get_post_meta($org, '_tix_org_user_id', true));
            if ($oid && ($o = get_user_by('id', $oid))) { $out[] = self::row($o, $org); $seen[$oid] = 1; $counts['owner']++; }
            foreach (get_users(['meta_key' => TIX_App_Scope::META_TEAM_ORG, 'meta_value' => intval($org), 'number' => 0]) as $u) if (!isset($seen[$u->ID]) && !in_array('administrator', (array) $u->roles, true)) { $out[] = self::row($u, $org); $seen[$u->ID] = 1; $counts['team']++; }
        } else {
            // Team, Admins, Inhaber, technische Konten gezielt; Kundenkonten nur zählen (große Seiten haben Tausende)
            $ids = array_map('intval', get_users(['role__in' => array_merge(['administrator'], array_values(self::app_roles())), 'fields' => 'ID', 'number' => 0]));
            $ids = array_merge($ids, self::owner_ids(0), array_map('intval', get_users(['meta_key' => '_tix_operator', 'meta_value' => '1', 'fields' => 'ID', 'number' => 0])));
            foreach (array_unique($ids) as $id) {
                $u = get_user_by('id', $id);
                if (!$u) continue;
                $k = self::kind($u, 0);
                $counts[$k] = ($counts[$k] ?? 0) + 1;
                $out[] = self::row($u, 0);
                $seen[$id] = 1;
            }
            $total = intval(count_users()['total_users'] ?? 0);
            $counts['customer'] = max(0, $total - count($seen));
            if ($customers && $counts['customer'] > 0) { // höchstens 200, neueste zuerst
                foreach (get_users(['exclude' => array_keys($seen), 'number' => 200, 'orderby' => 'registered', 'order' => 'DESC']) as $u) $out[] = self::row($u, 0);
            }
        }
        $order = ['owner' => 0, 'wp_admin' => 1, 'team' => 2, 'technical' => 3, 'customer' => 4];
        usort($out, function ($a, $b) use ($order) { return (($order[$a['kind']] ?? 9) <=> ($order[$b['kind']] ?? 9)) ?: strcasecmp($a['name'], $b['name']); });
        return $out;
    }

    /** Warum ein Konto nicht gelöscht werden darf (Text) bzw. null. */
    private static function delete_block(WP_User $u, $org) {
        if ($org) return 'Auf evendis werden Konten nicht gelöscht – nur aus dem Team entfernt bzw. die Inhaberschaft gewechselt.';
        if (self::is_owner($u, 0) && count(array_diff(self::owner_ids(0), [$u->ID])) === 0) return 'Erst die Inhaberschaft an ein anderes Konto übergeben.';
        if (in_array('administrator', (array) $u->roles, true) && count(get_users(['role' => 'administrator', 'fields' => 'ID', 'exclude' => [$u->ID], 'number' => 1])) === 0) return 'Das ist der letzte WordPress-Admin der Seite.';
        return null;
    }

    private static function bad($code, $msg, $status = 400, $field = null) {
        return new WP_Error($code, $msg, ['status' => $status] + ($field ? ['field' => $field] : []));
    }

    private static function pw_error($pw, WP_User $u) {
        $pw = (string) $pw;
        if (strlen($pw) < 10) return self::bad('tix_weak_password', 'Das Passwort braucht mindestens 10 Zeichen.', 400, 'password');
        if (strlen($pw) > 200) return self::bad('tix_weak_password', 'Das Passwort ist zu lang.', 400, 'password');
        $local = strtok((string) $u->user_email, '@');
        if (($local !== '' && stripos($pw, $local) !== false) || stripos($pw, $u->user_login) !== false) return self::bad('tix_weak_password', 'Das Passwort darf Login oder E-Mail nicht enthalten.', 400, 'password');
        $kinds = (int) preg_match('/[a-z]/', $pw) + (int) preg_match('/[A-Z]/', $pw) + (int) preg_match('/\d/', $pw) + (int) preg_match('/[^A-Za-z0-9]/', $pw);
        if ($kinds < 2) return self::bad('tix_weak_password', 'Bitte mische Buchstaben mit Zahlen oder Zeichen.', 400, 'password');
        return null;
    }

    /** Mitglied {id} im Bereich, bearbeitbar? → WP_User | WP_Error */
    private static function target($id, $org) {
        $u = get_user_by('id', intval($id));
        if (!$u || self::hidden($u)) return self::bad('tix_no_user', 'Zugang nicht gefunden.', 404);
        if ($org ? intval(get_user_meta($u->ID, TIX_App_Scope::META_TEAM_ORG, true)) !== $org && !self::is_owner($u, $org) : !self::role_key($u)) return self::bad('tix_no_user', 'Zugang nicht gefunden.', 404);
        if (self::is_owner($u, $org)) return self::bad('tix_is_owner', 'Der Inhaber-Zugang lässt sich hier nicht ändern oder entfernen.', 409);
        return $u;
    }

    private static function audit($action, WP_User $u, $org, array $by, array $extra = []) {
        if (!class_exists('TIX_Customers') || !method_exists('TIX_Customers', 'audit')) return;
        TIX_Customers::audit($action, (string) $u->ID, ['oid' => intval($org), 'scope' => $org ? 'organizer' : 'site'], ['via' => 'platform', 'by' => $by['email'] ?? '', 'login' => $u->user_login] + $extra);
    }

    private static function end_sessions($uid) {
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($uid)->destroy_all();
        delete_user_meta($uid, '_tix_app_tokens');
        delete_user_meta($uid, '_tix_app_token');
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'sync_token_index')) TIX_REST_API::sync_token_index($uid);
    }

    /** Einladung bzw. Link zum Passwort-Setzen per Mail (Code wie Team/Inhaber). */
    private static function mail_invite(WP_User $u, $role) {
        if (self::is_fallback($u->user_email) || !class_exists('TIX_Owner_Access')) return false;
        $pw = TIX_Owner_Access::password_block($u, 'team');
        if ($pw['html'] === '') return false;
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $label = 'Team';
        foreach (self::roles() as $r) if ($r['key'] === $role) $label = $r['label'];
        $html = '<p>Hallo' . ($u->display_name ? ' ' . esc_html(strtok((string) $u->display_name, ' ')) : '') . ',</p><p>für dich ist ein Zugang zu ' . esc_html($site) . ' angelegt (Rolle: ' . esc_html($label) . ').</p>' . $pw['html']
            . '<p>Benutzername: ' . esc_html($u->user_login) . '</p>';
        $subject = 'Dein Zugang zu ' . $site;
        if (class_exists('TIX_Mail_Layout')) return (bool) TIX_Mail_Layout::send($u->user_email, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        return (bool) wp_mail($u->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    private static function set_role(WP_User $u, $role) {
        foreach (self::app_roles() as $wp) if (in_array($wp, (array) $u->roles, true)) $u->remove_role($wp);
        if ($role !== 'none' && isset(self::app_roles()[$role])) $u->add_role(self::app_roles()[$role]);
        $u = get_user_by('id', $u->ID);
        if (empty($u->roles)) $u->add_role(get_role('tix_customer') ? 'tix_customer' : 'subscriber');
    }

    /** Löschen (mit Übertragung) und Inhaberwechsel (1.38.727). */
    private static function exec_owner_delete($action, array $a, $org, array $by) {
        $u = get_user_by('id', intval($a['id'] ?? 0));
        if (!$u) return self::bad('tix_no_user', 'Konto nicht gefunden.', 404);
        $kind = self::kind($u, $org);
        if ($org && $kind === 'customer') return self::bad('tix_no_user', 'Konto nicht gefunden.', 404);

        if ($action === 'delete') {
            if ($org) return self::bad('tix_users_delete_platform', 'Auf evendis werden Konten nicht gelöscht – nur aus dem Team entfernt bzw. die Inhaberschaft gewechselt.', 409);
            if (self::is_owner($u, 0) && !array_diff(self::owner_ids(0), [$u->ID])) return self::bad('tix_last_owner', 'Erst die Inhaberschaft an ein anderes Konto übergeben – die Seite braucht immer einen Inhaber.', 409);
            if (in_array('administrator', (array) $u->roles, true) && !get_users(['role' => 'administrator', 'fields' => 'ID', 'exclude' => [$u->ID], 'number' => 1])) return self::bad('tix_last_admin', 'Das ist der letzte WordPress-Admin der Seite.', 409);
            $re = intval($a['reassign'] ?? 0);
            if (!$re) { $cand = array_values(array_diff(self::owner_ids(0), [$u->ID])); $re = $cand[0] ?? 0; }
            if (!$re) { $adm = get_users(['role' => 'administrator', 'fields' => 'ID', 'exclude' => [$u->ID], 'number' => 1]); $re = intval($adm[0] ?? 0); }
            if (!$re || $re === $u->ID || !get_user_by('id', $re)) return self::bad('tix_invalid_field', 'Bitte ein anderes Konto wählen, das die Inhalte übernimmt.', 400, 'reassign');
            $info = ['login' => $u->user_login, 'kind' => $kind, 'reassign' => $re];
            self::audit('user_deleted', $u, 0, $by, $info);
            require_once ABSPATH . 'wp-admin/includes/user.php';
            if (!wp_delete_user($u->ID, $re)) return self::bad('tix_users_error', 'Das Konto konnte nicht gelöscht werden.', 500);
            return ['ok' => true, 'deleted' => true, 'reassigned_to' => $re];
        }

        // Inhaberschaft wechseln: $u wird Inhaber, alter Inhaber old = admin (bleibt Admin) | remove (verliert die Rolle)
        $old_mode = in_array($a['old'] ?? '', ['admin', 'remove'], true) ? $a['old'] : 'admin';
        if ($kind === 'technical') return self::bad('tix_invalid_field', 'Das technische Betreiber-Konto kann nicht Inhaber werden.', 400, 'id');
        if ($kind === 'owner') return self::bad('tix_is_owner', 'Dieses Konto ist schon Inhaber.', 409);
        if ($org) {
            if ($kind !== 'team') return self::bad('tix_invalid_field', 'Inhaber kann nur ein Mitglied des Teams werden.', 400, 'id');
            $p = TIX_App_Scope::organizer_post_for_user($u->ID);
            if ($p && intval($p->ID) !== $org) return self::bad('tix_other_team', 'Diese Person gehört bereits zu einem anderen Veranstalter.', 409);
            $old = intval(get_post_meta($org, '_tix_org_user_id', true));
            update_post_meta($org, '_tix_org_user_id', $u->ID);
            delete_user_meta($u->ID, TIX_App_Scope::META_TEAM_ORG);
            if (!in_array('tix_organizer', (array) $u->roles, true)) $u->add_role('tix_organizer');
            if ($old && ($ou = get_user_by('id', $old))) {
                if ($old_mode === 'admin') { update_user_meta($old, TIX_App_Scope::META_TEAM_ORG, $org); if (!in_array('tix_organizer', (array) $ou->roles, true)) $ou->add_role('tix_organizer'); }
                else self::end_sessions($old);
                self::audit('owner_removed', $ou, $org, $by, ['mode' => $old_mode, 'new' => $u->ID]);
            }
        } else {
            $olds = array_diff(self::owner_ids(0), [$u->ID]);
            update_user_meta($u->ID, '_tix_site_owner', 1);
            if (!in_array('administrator', (array) $u->roles, true)) self::set_role($u, 'admin'); // Inhaberrechte (tix_organizer)
            foreach ($olds as $oid) {
                $ou = get_user_by('id', $oid);
                if (!$ou) continue;
                delete_user_meta($oid, '_tix_site_owner');
                if ($old_mode === 'remove') { if (!in_array('administrator', (array) $ou->roles, true)) self::set_role($ou, 'none'); self::end_sessions($oid); }
                self::audit('owner_removed', $ou, 0, $by, ['mode' => $old_mode, 'new' => $u->ID]);
            }
        }
        if (class_exists('TIX_App_Scope') && method_exists('TIX_App_Scope', 'flush_public_cache')) TIX_App_Scope::flush_public_cache();
        self::audit('owner_set', $u, $org, $by, ['old' => $old_mode]);
        return ['ok' => true, 'owner' => self::row(get_user_by('id', $u->ID), $org), 'users' => self::members($org)];
    }

    /** → {ok, …} | WP_Error */
    public static function exec($action, array $a, $org, array $by) {
        $org = intval($org);
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        if ($action === 'list') {
            $counts = [];
            $users = self::members($org, filter_var($a['customers'] ?? false, FILTER_VALIDATE_BOOLEAN), $counts);
            return ['ok' => true, 'users' => $users, 'roles' => self::roles(), 'counts' => $counts, 'customers_included' => filter_var($a['customers'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        }
        if ($action === 'delete' || $action === 'transfer_owner') return self::exec_owner_delete($action, $a, $org, $by);

        if ($action === 'create') {
            $role = sanitize_key((string) ($a['role'] ?? ''));
            if (!isset(self::app_roles()[$role])) return self::bad('tix_bad_role', 'Unbekannte Rolle.', 400, 'role');
            $name = mb_substr(sanitize_text_field((string) ($a['name'] ?? '')), 0, 120);
            $email = sanitize_email((string) ($a['email'] ?? ''));
            $login = sanitize_user((string) ($a['login'] ?? ''), true);
            $mode = sanitize_key((string) ($a['password_mode'] ?? ''));
            $pw = (string) ($a['password'] ?? '');
            if (($a['email'] ?? '') !== '' && !is_email($email)) return self::bad('tix_bad_email', 'Bitte eine gültige E-Mail-Adresse angeben.', 400, 'email');
            if ($email === '' && $login === '') return self::bad('tix_invalid_field', 'Bitte Login oder E-Mail angeben.', 400, 'login');
            if (!in_array($mode, ['set', 'invite'], true)) $mode = $email !== '' ? 'invite' : 'set';
            if ($mode === 'invite' && $email === '') return self::bad('tix_invalid_field', 'Ohne E-Mail geht nur „Passwort selbst setzen“.', 400, 'password_mode');
            if ($login !== '' && (strlen($login) < 3 || strlen($login) > 60)) return self::bad('tix_invalid_field', 'Login: 3 bis 60 Zeichen (Buchstaben, Ziffern, . _ -).', 400, 'login');
            if ($login !== '' && username_exists($login)) return self::bad('tix_login_taken', 'Diesen Login gibt es schon.', 409, 'login');
            if ($email !== '' && ($ex = get_user_by('email', $email))) {
                return self::bad('tix_user_exists', 'Zu dieser E-Mail gibt es schon ein Konto. Bitte eine andere Adresse nehmen oder das Konto im Team des Kunden zuordnen.', 409, 'email');
            }
            if ($login === '') {
                $base = sanitize_user(strtolower(strtok($email, '@')), true) ?: 'team';
                $login = $base;
                for ($i = 2; username_exists($login) && $i < 500; $i++) $login = $base . $i;
            }
            $mail = $email !== '' ? $email : $login . '@' . ($host ?: 'seite') . '.' . self::FALLBACK;
            if ($email === '' && email_exists($mail)) return self::bad('tix_login_taken', 'Diesen Login gibt es schon.', 409, 'login');
            $uid = wp_insert_user(['user_login' => $login, 'user_email' => $mail, 'user_pass' => wp_generate_password(32, true, true), 'display_name' => $name !== '' ? $name : $login,
                'first_name' => $name !== '' ? strtok($name, ' ') : '', 'role' => self::app_roles()[$role]]);
            if (is_wp_error($uid)) return self::bad('tix_invalid_field', $uid->get_error_message(), 400, 'login');
            $u = get_user_by('id', $uid);
            update_user_meta($uid, self::META_BY, gmdate('c'));
            if (class_exists('TIX_Team')) update_user_meta($uid, TIX_Team::META_CREATED, 1);
            if ($org) update_user_meta($uid, TIX_App_Scope::META_TEAM_ORG, $org);
            $out = ['ok' => true, 'created' => true];
            if ($mode === 'set') {
                if ($e = self::pw_error($pw, $u)) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); return $e; }
                wp_set_password($pw, $uid);
                $out['password_set'] = true;
            } else {
                if (class_exists('TIX_Team')) update_user_meta($uid, TIX_Team::META_INVITE, time());
                $out['invited'] = self::mail_invite($u, $role);
                $out['mailed'] = $out['invited'];
            }
            self::audit('team_added', $u, $org, $by, ['role' => $role, 'password' => $mode]);
            $out['user'] = self::row(get_user_by('id', $uid), $org);
            return $out;
        }

        $u = self::target($a['id'] ?? 0, $org);
        if (is_wp_error($u)) return $u;

        if ($action === 'update') {
            $done = [];
            if (array_key_exists('role', $a)) {
                $role = sanitize_key((string) $a['role']);
                if (!isset(self::app_roles()[$role])) return self::bad('tix_bad_role', 'Unbekannte Rolle.', 400, 'role');
                self::set_role($u, $role);
                $done['role'] = $role;
            }
            if (array_key_exists('name', $a)) {
                $name = mb_substr(sanitize_text_field((string) $a['name']), 0, 120);
                if ($name === '') return self::bad('tix_invalid_field', 'Bitte einen Namen angeben.', 400, 'name');
                wp_update_user(['ID' => $u->ID, 'display_name' => $name]);
                $done['name'] = true;
            }
            if (!$done) return self::bad('tix_invalid_field', 'Nichts zu ändern.', 400);
            self::audit('team_role_changed', $u, $org, $by, $done);
            return ['ok' => true, 'user' => self::row(get_user_by('id', $u->ID), $org)];
        }

        if ($action === 'password') {
            $mode = sanitize_key((string) ($a['mode'] ?? ($a['password_mode'] ?? '')));
            if ($mode === 'invite') {
                if (self::is_fallback($u->user_email)) return self::bad('tix_invalid_field', 'Dieser Zugang hat keine E-Mail-Adresse – bitte das Passwort selbst setzen.', 400, 'mode');
                $n = intval(get_transient('tix_site_users_inv_' . $u->ID));
                if ($n >= 5) return self::bad('tix_rate_limit', 'Zu viele Mails. Bitte in einer Stunde noch einmal.', 429);
                set_transient('tix_site_users_inv_' . $u->ID, $n + 1, HOUR_IN_SECONDS);
                $sent = self::mail_invite($u, self::role_key($u) ?: 'admin');
                if (!$sent) return self::bad('tix_mail_failed', 'Die E-Mail konnte nicht gesendet werden.', 502);
                self::audit('team_reset_sent', $u, $org, $by);
                return ['ok' => true, 'mailed' => true, 'user' => self::row($u, $org)];
            }
            if ($mode !== 'set') return self::bad('tix_invalid_field', 'mode: set oder invite.', 400, 'mode');
            // Nur Konten, die das Team bzw. die Akte angelegt hat – sonst könnte man ein fremdes Kundenkonto übernehmen
            if (!get_user_meta($u->ID, self::META_BY, true) && !(class_exists('TIX_Team') && get_user_meta($u->ID, TIX_Team::META_CREATED, true))) {
                return self::bad('tix_has_password', 'Diese Person hat ein eigenes Konto. Schick ihr den Link zum Passwort-Setzen.', 409, 'mode');
            }
            $pw = (string) ($a['password'] ?? '');
            if ($e = self::pw_error($pw, $u)) return $e;
            wp_set_password($pw, $u->ID);
            self::end_sessions($u->ID);
            self::audit('team_password_set', $u, $org, $by);
            return ['ok' => true, 'password_set' => true, 'user' => self::row(get_user_by('id', $u->ID), $org)];
        }

        if ($action === 'remove') {
            self::set_role($u, 'none');
            if ($org) delete_user_meta($u->ID, TIX_App_Scope::META_TEAM_ORG);
            self::end_sessions($u->ID);
            self::audit('team_removed', $u, $org, $by);
            return ['ok' => true, 'removed' => true];
        }
        return self::bad('tix_invalid_field', 'Unbekannte Aktion.', 400);
    }
}

TIX_Site_Users::init();
