<?php
if (!defined('ABSPATH')) exit;

/**
 * Co-Veranstalter (1.38.696, User 09.11.) – Vertrag `uebergaben/vertrag-co-veranstalter.md` Teil A.
 *
 * Nur im Mehr-Veranstalter-Modus (evendis.de). Der Ersteller (oder ein Plattform-Admin) setzt je Event einen
 * Co-Veranstalter – vorhandenen Veranstalter oder per E-Mail einladen – und schaltet seinen Zugriff.
 *
 * Meta am Event:
 *   _tix_co_organizer_ref     Co-Veranstalter (immer)
 *   _tix_co_organizer_access  '1' | '0'
 *   _tix_co_organizer_id      nur mit Zugriff gesetzt – darüber laufen alle bestehenden Abfragen (Scope, Listen, Statistik)
 *   _tix_co_organizer         Name (Anzeige)
 *   _tix_co_organizer_since   ISO-Zeit
 *   _tix_co_organizer_consent {at, by}
 * Meta am Veranstalter: _tix_org_kind = 'co' (über Einladung angelegt), _tix_org_co_choice {choice, at, message}.
 *
 * Rechte des Co-Veranstalters: wie eigenes Event, aber nicht löschen/wiederherstellen/archivieren/duplizieren,
 * Co-Veranstalter ändern, Datenexport mit Käuferdaten; Abrechnung bleibt beim Ersteller.
 */
class TIX_Co_Organizer {
    const NS          = 'tixomat/v1';
    const META_REF    = '_tix_co_organizer_ref';
    const META_ACCESS = '_tix_co_organizer_access';
    const META_ID     = '_tix_co_organizer_id';
    const META_NAME   = '_tix_co_organizer';
    const META_SINCE  = '_tix_co_organizer_since';
    const META_CONSENT = '_tix_co_organizer_consent';
    const ORG_KIND    = '_tix_org_kind';
    const ORG_CHOICE  = '_tix_org_co_choice';
    const CHOICES     = ['studio', 'provision', 'license'];
    const OPT_REQUESTS = 'tix_co_requests';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_request_before_callbacks', [__CLASS__, 'guard'], 10, 3);
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Grundlagen ═══════════════

    /** Co-Veranstalter des Events (unabhängig vom Zugriff) oder 0. Alt-Events: nur `_tix_co_organizer_id`. */
    public static function ref_id($event_id) {
        $event_id = intval($event_id);
        $ref = intval(get_post_meta($event_id, self::META_REF, true));
        return $ref ?: intval(get_post_meta($event_id, self::META_ID, true));
    }

    public static function has_access($event_id) {
        $event_id = intval($event_id);
        if (!self::ref_id($event_id)) return false;
        $a = get_post_meta($event_id, self::META_ACCESS, true);
        return $a === '' ? intval(get_post_meta($event_id, self::META_ID, true)) > 0 : $a === '1';
    }

    private static function org_ok($oid) {
        $p = get_post(intval($oid));
        return $p && $p->post_type === 'tix_organizer' && $p->post_status === 'publish' ? $p : null;
    }

    /** Co-Veranstalter setzen/ändern (ohne Rechteprüfung). $access null = bisherigen Zugriff behalten (neu: an). */
    public static function set($event_id, $oid, $access = null, $consent_by = 0) {
        $event_id = intval($event_id);
        $oid = intval($oid);
        $old = self::ref_id($event_id);
        if ($access === null) $access = $old === $oid ? self::has_access($event_id) : true;
        update_post_meta($event_id, self::META_REF, $oid);
        update_post_meta($event_id, self::META_ACCESS, $access ? '1' : '0');
        update_post_meta($event_id, self::META_NAME, html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8'));
        if ($access) update_post_meta($event_id, self::META_ID, $oid);
        else delete_post_meta($event_id, self::META_ID);
        if ($old !== $oid) {
            update_post_meta($event_id, self::META_SINCE, gmdate('c'));
            delete_post_meta($event_id, self::META_CONSENT);
        }
        if ($access && $consent_by && !get_post_meta($event_id, self::META_CONSENT, true)) {
            update_post_meta($event_id, self::META_CONSENT, ['at' => gmdate('c'), 'by' => intval($consent_by)]);
        }
        clean_post_cache($event_id);
        return $old !== $oid; // neu verknüpft?
    }

    public static function clear($event_id) {
        $event_id = intval($event_id);
        foreach ([self::META_REF, self::META_ACCESS, self::META_ID, self::META_NAME, self::META_SINCE, self::META_CONSENT] as $k) delete_post_meta($event_id, $k);
        clean_post_cache($event_id);
    }

    /** Rolle des aktuellen Nutzers am Event: owner | co | admin | null. */
    public static function role($event_id, $uid = 0) {
        $event_id = intval($event_id);
        $uid = $uid ?: get_current_user_id();
        $u = get_user_by('id', $uid);
        if ($u && $u->has_cap('manage_options')) return 'admin';
        if (!class_exists('TIX_App_Scope')) return null;
        $oid = TIX_App_Scope::organizer_id_for_user($uid);
        if (!$oid) return null;
        if (!$event_id) return 'owner';
        if (intval(get_post_meta($event_id, '_tix_organizer_id', true)) === $oid) return 'owner';
        if (intval(get_post_meta($event_id, self::META_ID, true)) === $oid) return 'co';
        return null;
    }

    /** Ist das Event für den aktuellen Nutzer ein gemeinsames Event, bei dem er Co-Veranstalter ist? */
    public static function is_co($event_id) {
        return self::multi() && self::role($event_id) === 'co';
    }

    private static function deny($msg = 'Das darf nur der Ersteller des Events.') {
        return new WP_Error('tix_co_forbidden', $msg, ['status' => 403]);
    }

    private static function ref($oid) {
        if (!$oid) return null;
        // organizer_ref liefert nur freigegebene Veranstalter – eingeladene Co-Veranstalter warten oft noch auf die Prüfung
        $r = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::organizer_ref($oid) : null;
        if ($r) return $r;
        $logo = '';
        foreach (['_tix_org_landing_logo_id', '_tix_org_image_id'] as $k) {
            $aid = intval(get_post_meta($oid, $k, true));
            if ($aid && ($u = wp_get_attachment_image_url($aid, 'medium'))) { $logo = $u; break; }
        }
        return ['id' => intval($oid), 'slug' => '', 'name' => (string) get_the_title($oid), 'logo' => $logo];
    }

    /** Öffentlich nennen: veröffentlicht und nicht gesperrt/abgelehnt (die Freigabe zum eigenen Verkauf ist dafür nicht nötig). */
    public static function public_ok($oid) {
        if (!self::org_ok($oid)) return false;
        return !(class_exists('TIX_Org_Approval') && in_array(TIX_Org_Approval::status($oid), ['blocked', 'rejected'], true));
    }

    public static function owner_user($oid) {
        $uid = intval(get_post_meta(intval($oid), '_tix_org_user_id', true));
        return $uid ? get_user_by('id', $uid) : null;
    }

    /** invited = Konto ohne gesetztes Passwort (eingeladen, nie angemeldet). */
    private static function org_status($oid) {
        $u = self::owner_user($oid);
        if (!$u) return 'invited';
        $invited = get_user_meta($u->ID, '_tix_invited_at', true);
        $seen = get_user_meta($u->ID, '_tix_last_login', true) ?: get_user_meta($u->ID, 'last_login', true);
        if ($invited && !$seen && get_user_meta($u->ID, '_tix_co_password_set', true) !== '1') return 'invited';
        return 'active';
    }

    public static function payload($event_id) {
        $oid = self::ref_id($event_id);
        if (!$oid || !get_post($oid)) return null;
        $r = self::ref($oid) ?: ['id' => $oid, 'name' => '', 'slug' => '', 'logo' => ''];
        $u = self::owner_user($oid);
        $status = self::org_status($oid);
        $c = get_post_meta(intval($event_id), self::META_CONSENT, true);
        return [
            'id' => intval($r['id']), 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'), 'logo' => (string) $r['logo'], 'slug' => (string) $r['slug'],
            'access' => self::has_access($event_id),
            'status' => $status,
            'invited_email' => ($status === 'invited' && $u) ? (string) $u->user_email : '',
            'since' => (string) get_post_meta(intval($event_id), self::META_SINCE, true) ?: null,
            'consent' => is_array($c) && !empty($c['at']) ? ['at' => (string) $c['at'], 'by' => intval($c['by'] ?? 0)] : null,
        ];
    }

    /** Zusatzfelder für GET /events/{id}/edit und /events/form. */
    public static function editor_payload($event_id) {
        if (!self::multi()) return class_exists('TIX_Co_Remote') ? TIX_Co_Remote::editor_payload($event_id)
            : ['co_organizer_available' => false, 'co_organizer_mode' => 'remote', 'co_organizer_editable' => false, 'event_role' => null, 'co_organizer' => null];
        $role = self::role($event_id) ?: ($event_id ? null : 'owner');
        return [
            'co_organizer_available' => true,
            'co_organizer_mode'      => 'local',
            'co_organizer_editable'  => in_array($role, ['owner', 'admin'], true),
            'event_role'             => $role,
            'co_organizer'           => $event_id ? self::payload($event_id) : null,
        ];
    }

    /** Zusatzfelder für GET /events und /events/{id}: Rolle + „Gemeinsam mit …“. */
    public static function list_fields($event_id) {
        if (!self::multi()) return class_exists('TIX_Co_Remote') ? TIX_Co_Remote::list_fields($event_id) : [];
        $role = self::role($event_id);
        $with = 0;
        if ($role === 'co') $with = intval(get_post_meta(intval($event_id), '_tix_organizer_id', true));
        elseif ($role === 'owner' || $role === 'admin') $with = self::ref_id($event_id);
        $r = $with ? self::ref($with) : null;
        return ['event_role' => $role, 'shared_with' => $r ? ['id' => intval($r['id']), 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'), 'logo' => (string) $r['logo']] : null];
    }

    /** Öffentliche Angabe „mit …“ (unabhängig vom Zugriff). */
    public static function public_ref($event_id) {
        if (get_post_meta(intval($event_id), '_tix_syndicated', true)) { // geteilte Events: nur der Co-Veranstalter, den die Lizenzseite gemeldet hat (Teil B)
            $r = intval(get_post_meta(intval($event_id), '_tix_co_remote_oid', true));
            return $r && self::public_ok($r) ? self::ref($r) : null;
        }
        if (!self::multi() && class_exists('TIX_Co_Remote')) return TIX_Co_Remote::public_ref($event_id);
        $oid = self::ref_id($event_id);
        return $oid && self::public_ok($oid) ? self::ref($oid) : null;
    }

    // ═══════════════ Editor: prüfen + speichern ═══════════════

    /** Feld `co_organizer` im Body prüfen. → null (unverändert) | ['op' => 'clear'|'set', …] | WP_Error */
    public static function check(array $body, $post_id, $is_new) {
        if (!array_key_exists('co_organizer', $body)) return null;
        if (!self::multi()) return class_exists('TIX_Co_Remote') ? TIX_Co_Remote::check($body, $post_id, $is_new) : null;
        $role = $is_new ? (TIX_App_Scope::is_admin() ? 'admin' : 'owner') : self::role($post_id);
        if (!in_array($role, ['owner', 'admin'], true)) return self::deny('Den Co-Veranstalter kann nur der Ersteller des Events ändern.');
        $v = $body['co_organizer'];
        if ($v === null || $v === false || $v === '' || $v === 0) return ['op' => 'clear'];
        if (!is_array($v)) return new WP_Error('tix_invalid_field', 'co_organizer: null oder {id, access, consent}.', ['status' => 400, 'field' => 'co_organizer']);
        $oid = isset($v['id']) ? intval($v['id']) : ($is_new ? 0 : self::ref_id($post_id));
        if (!$oid) return new WP_Error('tix_invalid_field', 'Bitte einen Co-Veranstalter wählen.', ['status' => 400, 'field' => 'co_organizer']);
        if (!self::org_ok($oid)) return new WP_Error('tix_invalid_field', 'Diesen Veranstalter gibt es nicht oder er ist nicht freigegeben.', ['status' => 400, 'field' => 'co_organizer']);
        $own = $is_new ? TIX_App_Scope::organizer_id_for_user() : intval(get_post_meta($post_id, '_tix_organizer_id', true));
        if ($own && $own === $oid) return new WP_Error('tix_invalid_field', 'Der eigene Veranstalter kann nicht Co-Veranstalter sein.', ['status' => 400, 'field' => 'co_organizer']);
        $access = array_key_exists('access', $v) ? filter_var($v['access'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if (array_key_exists('access', $v) && $access === null) return new WP_Error('tix_invalid_field', 'co_organizer.access: true oder false.', ['status' => 400, 'field' => 'co_organizer.access']);
        $same = !$is_new && self::ref_id($post_id) === $oid;
        $eff = $access === null ? ($same ? self::has_access($post_id) : true) : $access;
        $had = $same && get_post_meta($post_id, self::META_CONSENT, true); // Bestätigung bleibt gespeichert, auch wenn der Zugriff zwischendurch aus war
        if ($eff && $role !== 'admin' && !$had && empty($v['consent'])) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'co_organizer.consent']);
        }
        return ['op' => 'set', 'id' => $oid, 'access' => $access, 'consent' => !empty($v['consent']) || $role === 'admin'];
    }

    public static function apply($post_id, $c) {
        if (!$c) return;
        if (($c['op'] ?? '') === 'remote') return; // Lizenzseite: schon bei der Prüfung an evendis gemeldet (TIX_Co_Remote::check)
        if ($c['op'] === 'clear') { self::clear($post_id); return; }
        $new = self::set($post_id, $c['id'], $c['access'], $c['consent'] ? get_current_user_id() : 0);
        if ($new) self::notify($post_id, $c['id'], null);
    }

    // ═══════════════ Sperren (Rechte) ═══════════════

    /** Routen, die der Co-Veranstalter an gemeinsamen Events nicht nutzen darf. */
    public static function guard($response, $handler, $request) {
        if ($response instanceof WP_Error || !self::multi() || !is_user_logged_in()) return $response;
        $route = (string) $request->get_route();
        $method = $request->get_method();
        if (preg_match('#^/tixomat/v1/events/(\d+)(/restore|/archive|/duplicate)?$#', $route, $m)) {
            $sub = $m[2] ?? '';
            $blocked = ($sub === '' && $method === 'DELETE') || ($sub !== '' && $method === 'POST');
            if ($blocked && self::is_co(intval($m[1]))) {
                return self::deny($sub === '/duplicate' ? 'Ein gemeinsames Event kann nur der Ersteller duplizieren.' : 'Ein gemeinsames Event kann nur der Ersteller löschen oder archivieren.');
            }
        }
        if ($route === '/tixomat/v1/organizer/export') {
            $eid = absint($request->get_param('event_id'));
            $type = sanitize_key((string) $request->get_param('type'));
            if ($eid && self::is_co($eid) && !in_array($type, ['guests', 'checkins'], true)) {
                return self::deny('Käuferdaten eines gemeinsamen Events kann nur der Ersteller exportieren.');
            }
        }
        return $response;
    }

    /** Für Exporte ohne event_id: gemeinsame Events herausnehmen. */
    public static function without_co(array $ids) {
        if (!self::multi() || (class_exists('TIX_App_Scope') && TIX_App_Scope::is_admin())) return $ids;
        return array_values(array_filter($ids, function ($id) { return !self::is_co($id); }));
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        if (!self::multi()) return;
        $org = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/organizers/search', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_search'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-organizer/invite', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_invite'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/organizer/co-choice', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_choice'], 'permission_callback' => [__CLASS__, 'perm_manager']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save_choice'], 'permission_callback' => [__CLASS__, 'perm_manager']],
        ]);
        $adm = ['permission_callback' => ['TIX_Platform_Customers', 'perm']];
        register_rest_route(self::NS, '/platform/co-organizers', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform_list']] + $adm);
        register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/convert', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_convert']] + $adm);
    }

    public static function perm_manager() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (TIX_App_Scope::is_admin()) return true;
        return TIX_App_Scope::is_org_manager() ? true : new WP_Error('rest_forbidden', 'Nur für Inhaber und Team-Admins.', ['status' => 403]);
    }

    private static function creator_ok() {
        return TIX_App_Scope::is_admin() || TIX_App_Scope::is_org_manager();
    }

    public static function rest_search(WP_REST_Request $req) {
        if (!self::creator_ok()) return new WP_Error('rest_forbidden', 'Nur für Inhaber und Team-Admins.', ['status' => 403]);
        $q = trim(sanitize_text_field((string) $req->get_param('q')));
        if (mb_strlen($q) < 2) return new WP_Error('tix_invalid_field', 'Bitte mindestens 2 Zeichen eingeben.', ['status' => 400, 'field' => 'q']);
        $own = TIX_App_Scope::organizer_id_for_user();
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 11, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC']);
        $items = [];
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id === $own) continue;
            if (class_exists('TIX_Sales') && method_exists('TIX_Sales', 'is_demo_organizer') && TIX_Sales::is_demo_organizer($id)) continue;
            $r = self::ref($id);
            $items[] = ['id' => $id, 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'), 'slug' => (string) $r['slug'], 'logo' => (string) $r['logo'],
                'city' => (string) (get_post_meta($id, '_tix_org_city', true) ?: get_post_meta($id, '_tix_org_landing_city', true))];
            if (count($items) >= 10) break;
        }
        return rest_ensure_response(['ok' => true, 'items' => $items]);
    }

    /** Veranstalter zur E-Mail (Inhaber) oder null. */
    private static function org_for_user($uid) {
        $p = TIX_App_Scope::owned_organizer($uid);
        return $p ?: null;
    }


    /**
     * Veranstalter zur E-Mail finden oder Co-Veranstalter-Profil (Konto + Veranstalter) anlegen.
     * → [WP_Post $org, bool $created, WP_User $user, bool $new_user] | WP_Error
     */
    public static function find_or_create($email, $org_name, $name, array $ctx = []) {
        $user = get_user_by('email', $email);
        $org = $user ? self::org_for_user($user->ID) : null;
        if ($user && $user->has_cap('manage_options') && !$org) return new WP_Error('tix_invalid_field', 'Diese E-Mail gehört zu einem Plattform-Konto.', ['status' => 400, 'field' => 'email']);
        $created = false;
        $new_user = false;
        if (!$org) {
            if ($org_name === '') return new WP_Error('tix_invalid_field', 'Bitte den Namen des Veranstalters angeben.', ['status' => 400, 'field' => 'organizer']);
            if (!$user) {
                $r = TIX_Owner_Access::invite($email, $name, ['no_mail' => true]);
                if (is_wp_error($r)) return $r;
                $user = get_user_by('id', $r['user_id']);
                $new_user = true;
                update_user_meta($user->ID, '_tix_invited_at', gmdate('c'));
            } elseif (!in_array('tix_organizer', (array) $user->roles, true)) {
                $user->add_role('tix_organizer');
            }
            $oid = wp_insert_post(['post_type' => 'tix_organizer', 'post_title' => $org_name, 'post_status' => 'publish', 'post_author' => $user->ID], true);
            if (is_wp_error($oid)) return $oid;
            update_post_meta($oid, '_tix_org_user_id', $user->ID);
            update_post_meta($oid, self::ORG_KIND, 'co');
            update_post_meta($oid, '_tix_org_co_invited_by', $ctx + ['user' => get_current_user_id(), 'at' => gmdate('c')]);
            if (class_exists('TIX_Org_Approval')) TIX_Org_Approval::set_status($oid, 'pending', ['by' => get_current_user_id(), 'note' => 'Als Co-Veranstalter eingeladen', 'notify' => false]);
            $org = get_post($oid);
            $created = true;
            if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush();
        } elseif ($org->post_status !== 'publish') {
            return new WP_Error('tix_invalid_field', 'Der Veranstalter zu dieser E-Mail ist nicht freigegeben.', ['status' => 400, 'field' => 'email']);
        }
        return [$org, $created, $user, $new_user];
    }

    public static function rest_invite(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $role = self::role($eid);
        if (!in_array($role, ['owner', 'admin'], true)) return self::deny('Einen Co-Veranstalter kann nur der Ersteller des Events einladen.');
        $b = $req->get_json_params() ?: $req->get_params();
        $email = sanitize_email((string) ($b['email'] ?? ''));
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        $org_name = trim(sanitize_text_field((string) ($b['organizer'] ?? '')));
        $name = mb_substr(trim(sanitize_text_field((string) ($b['name'] ?? ''))), 0, 120);
        $access = array_key_exists('access', (array) $b) ? filter_var($b['access'], FILTER_VALIDATE_BOOLEAN) : true;
        if ($access && $role !== 'admin' && empty($b['consent'])) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'consent']);
        }
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $r = self::find_or_create($email, $org_name, $name, ['event_id' => $eid, 'organizer_id' => $main]);
        if (is_wp_error($r)) return $r;
        [$org, $created, $user, $new_user] = $r;
        if (intval($org->ID) === $main) return new WP_Error('tix_co_self', 'Diese E-Mail gehört zum eigenen Veranstalter.', ['status' => 409, 'field' => 'email']);
        self::set($eid, $org->ID, $access, ($access && !empty($b['consent'])) || $role === 'admin' ? get_current_user_id() : 0);
        $mailed = self::notify($eid, intval($org->ID), $new_user ? $user : null, $name);
        $res = rest_ensure_response(['ok' => true, 'created' => $created, 'mailed' => (bool) $mailed, 'co_organizer' => self::payload($eid)]);
        $res->set_status(201);
        return $res;
    }

    // ═══════════════ Mitteilungen ═══════════════

    /** Mail + Mitteilung an den Inhaber des Co-Veranstalters. $new_user = neues Konto → Link zum Passwort. */
    public static function notify($event_id, $oid, $new_user = null, $name = '') {
        $u = $new_user ?: self::owner_user($oid);
        if (!$u) return false;
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $title = html_entity_decode((string) get_the_title($event_id), ENT_QUOTES, 'UTF-8');
        $by = html_entity_decode((string) get_the_title(intval(get_post_meta($event_id, '_tix_organizer_id', true))), ENT_QUOTES, 'UTF-8') ?: $site;
        $studio = class_exists('TIX_Studio') && TIX_Studio::active() ? TIX_Studio::url('/') : admin_url();
        $access = self::has_access($event_id);
        $hello = $name !== '' ? 'Hallo ' . esc_html(strtok($name, ' ')) . ',' : 'Hallo,';
        $html = '<p>' . $hello . '</p><p>' . esc_html($by) . ' hat dich als Co-Veranstalter von <strong>' . esc_html($title) . '</strong> eingetragen.</p>';
        if ($access) $html .= '<p>Im Studio siehst du das Event unter „Events“ mit dem Hinweis „Gemeinsam mit ' . esc_html($by) . '“. Du kannst es dort bearbeiten, Verkäufe und Gäste sehen und den Einlass machen.</p>';
        else $html .= '<p>Du wirst beim Event als Mitveranstalter genannt.</p>';
        if ($new_user) { // wie die Team-Einladung: Code + Passwort im Studio festlegen (1.38.701)
            $pw = TIX_Owner_Access::password_block($new_user, 'co');
            if ($pw['html'] !== '') $html .= '<p>Für dich wurde ein Zugang angelegt (E-Mail: ' . esc_html($new_user->user_email) . ').</p>' . $pw['html'];
        }
        $html .= '<p>Zum Studio: <a href="' . esc_url($studio) . '">' . esc_html(preg_replace('#^https?://#', '', untrailingslashit($studio))) . '</a></p>';
        $subject = 'Du bist Co-Veranstalter von ' . $title;
        if (class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
            TIX_Notifications::add_user_item($u->ID, 'Du bist Co-Veranstalter', $title . ' · gemeinsam mit ' . $by, ['type' => 'message', 'action' => 'staff:event:' . intval($event_id)]);
        }
        if (class_exists('TIX_Mail_Layout')) {
            return (bool) TIX_Mail_Layout::send($u->user_email, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        }
        return (bool) wp_mail($u->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ═══════════════ Wahl Studio / Provision / Lizenz ═══════════════

    public static function is_co_kind($oid) {
        return get_post_meta(intval($oid), self::ORG_KIND, true) === 'co';
    }

    /** Gemeinsame Events, bei denen der Veranstalter Co-Veranstalter ist (mit und ohne Zugriff). */
    public static function shared_event_ids($oid) {
        $oid = intval($oid);
        if (!$oid) return [];
        return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => ['relation' => 'OR', ['key' => self::META_REF, 'value' => strval($oid)], ['key' => self::META_ID, 'value' => strval($oid)], ['key' => '_tix_co_remote_oid', 'value' => strval($oid)]]]));
    }

    public static function choice_of($oid) {
        $c = get_post_meta(intval($oid), self::ORG_CHOICE, true);
        return is_array($c) && in_array($c['choice'] ?? '', self::CHOICES, true) ? $c : null;
    }

    private static function my_org() {
        $p = TIX_App_Scope::organizer_post_for_user(get_current_user_id());
        return $p ? intval($p->ID) : 0;
    }

    public static function rest_choice(WP_REST_Request $req) {
        $oid = self::my_org();
        $c = $oid ? self::choice_of($oid) : null;
        $is_co = $oid && self::is_co_kind($oid);
        return rest_ensure_response(['ok' => true, 'show' => $is_co && !$c, 'is_co' => $is_co, 'choice' => $c ? $c['choice'] : null,
            'requested_at' => ($c && $c['choice'] !== 'studio') ? (string) $c['at'] : null, 'shared_events' => $oid ? count(self::shared_event_ids($oid)) : 0]);
    }

    public static function rest_save_choice(WP_REST_Request $req) {
        $oid = self::my_org();
        if (!$oid) return new WP_Error('no_organizer', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        $b = $req->get_json_params() ?: $req->get_params();
        $choice = sanitize_key((string) ($b['choice'] ?? ''));
        if (!in_array($choice, self::CHOICES, true)) return new WP_Error('tix_invalid_field', 'choice: studio, provision oder license.', ['status' => 400, 'field' => 'choice']);
        $msg = mb_substr(sanitize_textarea_field((string) ($b['message'] ?? '')), 0, 1000);
        $c = ['choice' => $choice, 'at' => gmdate('c'), 'message' => $msg, 'by' => get_current_user_id()];
        update_post_meta($oid, self::ORG_CHOICE, $c);
        if ($choice !== 'studio') self::request_admin($oid, $c);
        if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush();
        return rest_ensure_response(['ok' => true, 'choice' => $choice, 'requested_at' => $choice !== 'studio' ? $c['at'] : null, 'show' => false]);
    }

    private static function request_admin($oid, array $c) {
        $name = html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
        $u = self::owner_user($oid);
        $what = $c['choice'] === 'license' ? 'eine Lizenz (eigene Seite/App)' : 'eigene Events auf ' . html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8') . ' (Provision)';
        $html = '<p>Der Co-Veranstalter <strong>' . esc_html($name) . '</strong> möchte ' . esc_html($what) . '.</p>'
            . ($u ? '<p>Kontakt: ' . esc_html($u->display_name) . ' · ' . esc_html($u->user_email) . '</p>' : '')
            . ($c['message'] !== '' ? '<p>Nachricht:<br>' . nl2br(esc_html($c['message'])) . '</p>' : '')
            . '<p>Im Plattform-Studio unter Kunden → Co-Veranstalter.</p>';
        $subject = 'Anfrage Co-Veranstalter: ' . $name;
        $to = (string) get_option('admin_email');
        if (class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($to, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        else wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ═══════════════ Plattform ═══════════════

    /** Zusatz für die Kundenzeile (oder null). */
    public static function customer_info($oid) {
        $oid = intval($oid);
        if (!$oid) return null;
        $shared = self::shared_event_ids($oid);
        if (!self::is_co_kind($oid) && !$shared) return null;
        $c = self::choice_of($oid);
        return ['choice' => $c ? $c['choice'] : null, 'requested_at' => ($c && $c['choice'] !== 'studio') ? (string) $c['at'] : null,
            'message' => $c ? (string) ($c['message'] ?? '') : '', 'shared_events' => count($shared)];
    }

    public static function rest_platform_list(WP_REST_Request $req) {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s) AND meta_value <> ''", self::META_REF, self::META_ID, '_tix_co_remote_oid')));
        $kind = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'co'", self::ORG_KIND)));
        $ids = array_values(array_unique(array_filter(array_merge($ids, $kind))));
        $items = [];
        foreach ($ids as $oid) {
            $p = get_post($oid);
            if (!$p || $p->post_type !== 'tix_organizer') continue;
            $u = self::owner_user($oid);
            $c = self::choice_of($oid);
            $events = [];
            foreach (self::shared_event_ids($oid) as $eid) {
                $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
                $events[] = ['id' => $eid, 'title' => html_entity_decode((string) get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($eid, '_tix_date_start', true),
                    'owner' => $main ? ['id' => $main, 'name' => html_entity_decode((string) get_the_title($main), ENT_QUOTES, 'UTF-8')] : null,
                    'access' => get_post_meta($eid, '_tix_syndicated', true) === '1' ? get_post_meta($eid, '_tix_co_remote_access', true) === '1' : self::has_access($eid),
                    'remote' => get_post_meta($eid, '_tix_syndicated', true) === '1' ? ['site' => (string) get_post_meta($eid, '_tix_source_site', true), 'source_id' => intval(get_post_meta($eid, '_tix_source_id', true))] : null];
            }
            $key = class_exists('TIX_Platform_Customers') ? TIX_Platform_Customers::canonical('v-' . $oid) : 'v-' . $oid;
            $items[] = ['organizer_id' => $oid, 'key' => $key, 'name' => html_entity_decode((string) $p->post_title, ENT_QUOTES, 'UTF-8'), 'email' => $u ? (string) $u->user_email : '',
                'kind' => self::is_co_kind($oid) ? 'co' : 'other', 'status' => self::org_status($oid),
                'choice' => $c ? $c['choice'] : null, 'requested_at' => ($c && $c['choice'] !== 'studio') ? (string) $c['at'] : null, 'message' => $c ? (string) ($c['message'] ?? '') : '',
                'events' => $events];
        }
        usort($items, function ($a, $b) { return strcmp((string) $b['requested_at'], (string) $a['requested_at']) ?: strcasecmp($a['name'], $b['name']); });
        return rest_ensure_response(['ok' => true, 'items' => $items]);
    }

    public static function rest_convert(WP_REST_Request $req) {
        $b = $req->get_json_params() ?: $req->get_params();
        if (($b['to'] ?? '') !== 'provision') return new WP_Error('tix_invalid_field', 'to: provision (Lizenz über Angebote).', ['status' => 400, 'field' => 'to']);
        $c = TIX_Platform_Customers::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $oid = intval($c['organizer_id']);
        if (!$oid || !self::is_co_kind($oid)) return new WP_Error('tix_not_co', 'Dieser Kunde ist kein Co-Veranstalter.', ['status' => 409]);
        update_post_meta($oid, self::ORG_KIND, 'provision');
        if (!self::choice_of($oid)) update_post_meta($oid, self::ORG_CHOICE, ['choice' => 'provision', 'at' => gmdate('c'), 'message' => '', 'by' => get_current_user_id()]);
        TIX_Platform_Customers::flush();
        return rest_ensure_response(['ok' => true, 'key' => TIX_Platform_Customers::canonical('v-' . $oid), 'art' => 'provision']);
    }
}
