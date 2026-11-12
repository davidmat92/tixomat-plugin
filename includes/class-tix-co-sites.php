<?php
if (!defined('ABSPATH')) exit;

/**
 * Eigene Kunden-Seiten als Co-Veranstalter (1.38.706, Vertrag `uebergaben/vertrag-co-anzeigen-trennen.md`).
 *
 * Plattform (evendis.de): Kunden-Seiten = Lizenz-/Hosting-Kunden bzw. Partner aus TIX_Platform_Customers.
 * Beim Verbinden bekommt die Seite ein Veranstalter-Profil (Art `site`, ohne Konto), das dem Partner zugeordnet wird.
 * Die Kunden-Seite holt ihre Co-Events über GET /partner/co-events (TIX_Site_Move) und arbeitet per Durchgriff.
 */
class TIX_Co_Sites {
    const NS        = 'tixomat/v1';
    const KIND      = 'site';
    const META_KEY  = '_tix_org_site_key';
    const META_HOST = '_tix_org_site_host';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        // Änderungen an einem Event (Quelle per Verteilung oder Studio auf der Plattform) zeitnah an die Kunden-Seiten (1.38.772)
        add_action(self::SYNC_HOOK, [__CLASS__, 'trigger_sync']);
        add_action('save_post_event', function ($id) { if (!wp_is_post_revision($id) && !wp_is_post_autosave($id)) self::sync_sites_of_event($id); }, 99);
    }

    const SYNC_HOOK = 'tix_co_site_sync';

    /**
     * Kunden-Seiten, die ein Event als Co-Kopie führen, in ~20 s abgleichen lassen (1.38.772, Fund Line-up auf mallorcaparty-schwelm.de:
     * Kopie zeigte bis zum stündlichen Abgleich den alten Stand). Je Seite höchstens ein wartender Auftrag – er holt beim Lauf den
     * dann aktuellen Stand, mehrere Änderungen kurz hintereinander werden so zusammengefasst. Rückgabe: Zahl der Seiten.
     */
    public static function sync_sites_of_event($eid) {
        if (!self::multi()) return 0;
        $eid = intval($eid);
        if (!$eid || get_post_type($eid) !== 'event') return 0;
        $oids = class_exists('TIX_Co_Organizer') ? TIX_Co_Organizer::ids($eid) : [];
        if (class_exists('TIX_Co_Remote') && method_exists('TIX_Co_Remote', 'mirror_entries')) {
            foreach ((array) TIX_Co_Remote::mirror_entries($eid) as $e) $oids[] = intval(is_array($e) ? ($e['oid'] ?? 0) : 0);
        }
        $n = 0;
        foreach (array_unique(array_filter(array_map('intval', $oids))) as $oid) {
            if (!self::is_site_profile($oid)) continue;
            if (!wp_next_scheduled(self::SYNC_HOOK, [$oid])) wp_schedule_single_event(time() + 20, self::SYNC_HOOK, [$oid]);
            $n++;
        }
        return $n;
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function routes() {
        if (!self::multi()) return;
        register_rest_route(self::NS, '/co-organizer/sites', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_search'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
        register_rest_route(self::NS, '/syndicate/co-sites', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_partner_search'], 'permission_callback' => [__CLASS__, 'perm_partner']]);
    }

    // ═══════════════ Register ═══════════════

    private static function host_of($c) {
        if (!empty($c['_lic']['hosts'])) return (string) $c['_lic']['hosts'][0];
        if (!empty($c['_partner']['host'])) return (string) $c['_partner']['host'];
        return '';
    }

    private static function name_of($c) {
        $oid = intval($c['organizer_id'] ?? 0);
        if ($oid) return html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
        if (!empty($c['_lic'])) return (string) $c['_lic']['row']->customer;
        return (string) ($c['_partner']['name'] ?? '');
    }

    /** Ist der Kunde eine eigene Seite (Lizenz, Hosting oder Partner)? */
    private static function is_site($c) {
        return is_array($c) && (!empty($c['_lic']) || !empty($c['_partner'])) && self::host_of($c) !== '';
    }

    /** Partner (vollständig) des Kunden oder null. */
    private static function partner_of($c) {
        $pid = (string) ($c['partner_id'] ?? '');
        return $pid !== '' && class_exists('TIX_Partners') ? TIX_Partners::get($pid) : null;
    }

    private static function ready($c) {
        $p = self::partner_of($c);
        return $p && (string) ($p['key_in'] ?? '') !== '' && empty($p['pending']);
    }

    public static function row($c) {
        $host = self::host_of($c);
        $ready = self::ready($c);
        return ['key' => (string) $c['key'], 'name' => self::name_of($c) ?: $host, 'host' => $host, 'ready' => $ready, 'reason' => $ready ? null : 'not_connected',
            'message' => $ready ? null : $host . ' ist noch nicht mit ' . self::platform_name() . ' verbunden. Bitte zuerst auf der Seite die Event-Verteilung einrichten.'];
    }

    public static function platform_name() {
        return html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8') ?: 'der Plattform';
    }

    /** Suche; $exclude_oids = Veranstalter, die nicht vorkommen sollen; $exclude_partner = anfragende Seite. */
    public static function search($q, array $exclude_oids = [], $exclude_partner = '') {
        if (!class_exists('TIX_Platform_Customers')) return [];
        $q = mb_strtolower(trim((string) $q));
        $out = [];
        foreach (TIX_Platform_Customers::index()['customers'] as $c) {
            if (!self::is_site($c)) continue;
            if ($exclude_partner !== '' && (string) ($c['partner_id'] ?? '') === (string) $exclude_partner) continue;
            if (!empty($c['organizer_id']) && in_array(intval($c['organizer_id']), array_map('intval', $exclude_oids), true)) continue;
            $r = self::row($c);
            $hay = mb_strtolower($r['name'] . ' ' . $r['host'] . ' ' . $r['key']);
            if ($q !== '' && mb_strpos($hay, $q) === false) continue;
            $out[] = $r;
        }
        usort($out, function ($a, $b) { return ($b['ready'] <=> $a['ready']) ?: strcasecmp($a['name'], $b['name']); });
        return array_slice($out, 0, 10);
    }

    /**
     * Veranstalter-Profil der Kunden-Seite finden oder anlegen.
     * → [int $oid, bool $created, array $site] | WP_Error
     */
    public static function organizer_for($key) {
        $c = class_exists('TIX_Platform_Customers') ? TIX_Platform_Customers::raw($key) : null;
        if (!self::is_site($c)) return new WP_Error('tix_invalid_field', 'Diese Kunden-Seite gibt es nicht.', ['status' => 400, 'field' => 'site']);
        $site = ['key' => (string) $c['key'], 'host' => self::host_of($c), 'name' => self::name_of($c) ?: self::host_of($c)];
        if (!self::ready($c)) {
            return new WP_Error('tix_co_site_not_connected', $site['host'] . ' ist noch nicht mit ' . self::platform_name() . ' verbunden. Bitte zuerst auf der Seite die Event-Verteilung einrichten.', ['status' => 409, 'host' => $site['host']]);
        }
        $p = self::partner_of($c);
        $oid = intval($p['organizer_id'] ?? 0);
        $created = false;
        if (!$oid || get_post_type($oid) !== 'tix_organizer' || get_post_status($oid) === 'trash') {
            $oid = wp_insert_post(['post_type' => 'tix_organizer', 'post_title' => $site['name'], 'post_status' => 'publish'], true);
            if (is_wp_error($oid)) return $oid;
            update_post_meta($oid, TIX_Co_Organizer::ORG_KIND, self::KIND);
            $email = !empty($c['_lic']) ? sanitize_email((string) $c['_lic']['row']->email) : '';
            if ($email) update_post_meta($oid, '_tix_org_email', $email);
            $all = get_option(TIX_Partners::OPTION, []);
            if (isset($all[$p['id']]) && is_array($all[$p['id']])) {
                $all[$p['id']]['organizer_id'] = intval($oid);
                update_option(TIX_Partners::OPTION, $all, false);
            }
            TIX_Platform_Customers::flush();
            $created = true;
        }
        if (get_post_meta($oid, TIX_Co_Organizer::ORG_KIND, true) === '') update_post_meta($oid, TIX_Co_Organizer::ORG_KIND, self::KIND); // Profil aus der Kopplung (1.38.709)
        unset(self::$prof[intval($oid)]);
        update_post_meta($oid, self::META_KEY, $site['key']);
        update_post_meta($oid, self::META_HOST, $site['host']);
        if (get_post_status($oid) !== 'publish') return new WP_Error('tix_invalid_field', 'Der Veranstalter dieser Seite ist nicht freigegeben.', ['status' => 400, 'field' => 'site']);
        return [intval($oid), $created, $site];
    }

    /** Kunden-Seite zum Veranstalter (Profil Art `site` oder einem Partner zugeordnet) oder null. */
    public static function site_of($oid) {
        $oid = intval($oid);
        if (!$oid) return null;
        $host = (string) get_post_meta($oid, self::META_HOST, true);
        if ($host !== '') return ['key' => (string) get_post_meta($oid, self::META_KEY, true), 'host' => $host, 'name' => html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8')];
        if (!class_exists('TIX_Partners') || !self::multi()) return null;
        foreach (TIX_Partners::all() as $p) {
            if (intval($p['organizer_id'] ?? 0) !== $oid) continue;
            $h = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) ($p['api_base'] ?? ''), PHP_URL_HOST)));
            if ($h === '') continue;
            $key = class_exists('TIX_Platform_Customers') ? TIX_Platform_Customers::canonical('v-' . $oid) : null;
            return ['key' => $key ?: 'v-' . $oid, 'host' => $h, 'name' => html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8')];
        }
        return null;
    }

    private static $prof = [];

    /**
     * Plattform-Profil einer Kunden-Seite? (1.38.709) Art `site` oder Veranstalter eines Partners, dessen Host zu einer
     * Hosting-Lizenz gehört (Profil aus der Kopplung). Solche Profile erscheinen in der Veranstalter-Suche nie, nur als eigene Seite.
     */
    public static function is_site_profile($oid) {
        $oid = intval($oid);
        if (!$oid) return false;
        if (isset(self::$prof[$oid])) return self::$prof[$oid];
        $r = get_post_meta($oid, TIX_Co_Organizer::ORG_KIND, true) === self::KIND;
        if (!$r && self::multi() && class_exists('TIX_Partners') && method_exists('TIX_Partners', 'hosted_customer')) {
            foreach (TIX_Partners::all() as $p) {
                if (intval($p['organizer_id'] ?? 0) !== $oid) continue;
                $h = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) ($p['api_base'] ?? ''), PHP_URL_HOST)));
                if ($h !== '' && TIX_Partners::hosted_customer($h)) { $r = true; break; }
            }
        }
        return self::$prof[$oid] = $r;
    }

    /**
     * Öffentlich ausblenden: Profil einer Kunden-Seite, das an keinem veröffentlichten Event der Plattform beteiligt ist.
     * Beteiligt (1.38.726, Fund KMT #9510): als Veranstalter (`_tix_organizer_id`) oder als Co – lokal (`_tix_co_organizer_id`, je Co
     * eine Zeile) oder auf dem Spiegel einer Lizenzseite (`_tix_co_remote_oid`).
     */
    public static function public_hidden($oid) {
        if (!self::is_site_profile($oid)) return false;
        $v = strval(intval($oid));
        return !get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => ['relation' => 'OR', ['key' => '_tix_organizer_id', 'value' => $v], ['key' => '_tix_co_organizer_id', 'value' => $v],
                ['key' => class_exists('TIX_Co_Remote') ? TIX_Co_Remote::M_OID : '_tix_co_remote_oid', 'value' => $v]]]);
    }

    /** Mitteilung an die Kunden-Seite (1.38.715): [gemailt, Hinweis]; Ersatzadressen (….invalid) bekommen keine Mail. */
    public static function notify_result($event_id, $oid, $by = '') {
        $u = TIX_Co_Organizer::owner_user($oid);
        $to = $u ? (string) $u->user_email : (string) get_post_meta($oid, '_tix_org_email', true);
        if (!is_email($to) || preg_match('/\.invalid$/i', $to)) return [false, 'Für die Seite ist keine E-Mail-Adresse hinterlegt – das Event erscheint trotzdem in ihrem Studio.'];
        return [self::notify($event_id, $oid, $by), ''];
    }

    /** Kunden-Seite sofort abgleichen lassen (nicht blockierend, 1.38.715). */
    public static function trigger_sync($oid) {
        if (!class_exists('TIX_Co_Seller')) return false;
        $p = TIX_Co_Seller::site_partner($oid);
        if (!$p) return false;
        wp_remote_post(untrailingslashit($p['api_base']) . '/partner/co-sync', ['timeout' => 5, 'blocking' => false, 'body' => '{}',
            'headers' => ['Content-Type' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(), 'X-Tix-Partner-Key' => (string) $p['key_out']]]);
        return true;
    }

    /** Mail an den Inhaber der Kunden-Seite. */
    public static function notify($event_id, $oid, $by = '') {
        $u = TIX_Co_Organizer::owner_user($oid);
        $to = $u ? (string) $u->user_email : (string) get_post_meta($oid, '_tix_org_email', true);
        if (!is_email($to)) return false;
        $site = self::site_of($oid);
        $title = html_entity_decode((string) get_the_title($event_id), ENT_QUOTES, 'UTF-8');
        if ($by === '') $by = html_entity_decode((string) get_the_title(intval(get_post_meta($event_id, '_tix_organizer_id', true))), ENT_QUOTES, 'UTF-8') ?: self::platform_name();
        $where = $site ? $site['host'] : html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
        $access = TIX_Co_Organizer::has_access($event_id, $oid) || (class_exists('TIX_Co_Remote') && TIX_Co_Remote::mirror_has_access($event_id, $oid));
        $html = '<p>Hallo,</p><p>' . esc_html($by) . ' hat deine Seite <strong>' . esc_html($where) . '</strong> als Co-Veranstalter von <strong>' . esc_html($title) . '</strong> eingetragen.</p>'
            . '<p>Das Event erscheint in deinem Studio unter „Events“ mit dem Hinweis „Gemeinsam mit ' . esc_html($by) . '“ (spätestens nach einer Stunde, sofort über „Jetzt abgleichen“).'
            . ($access ? ' Du kannst es dort bearbeiten, Verkäufe und Gäste sehen und den Einlass machen.' : ' Du wirst beim Event als Mitveranstalter genannt.') . '</p>';
        $subject = 'Du bist Co-Veranstalter von ' . $title;
        if ($u && class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
            TIX_Notifications::add_user_item($u->ID, 'Du bist Co-Veranstalter', $title . ' · gemeinsam mit ' . $by, ['type' => 'message', 'action' => 'staff:event:' . intval($event_id)]);
        }
        if (class_exists('TIX_Mail_Layout')) return (bool) TIX_Mail_Layout::send($to, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        return (bool) wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ═══════════════ Routen ═══════════════

    public static function rest_search(WP_REST_Request $req) {
        if (!(TIX_App_Scope::is_admin() || TIX_App_Scope::is_org_manager())) return new WP_Error('rest_forbidden', 'Nur für Inhaber und Team-Admins.', ['status' => 403]);
        $q = trim(sanitize_text_field((string) $req->get_param('q')));
        if (mb_strlen($q) < 2) return new WP_Error('tix_invalid_field', 'Bitte mindestens 2 Zeichen eingeben.', ['status' => 400, 'field' => 'q']);
        $own = TIX_App_Scope::organizer_id_for_user();
        return rest_ensure_response(['ok' => true, 'items' => self::search($q, $own ? [$own] : [])]);
    }

    public static function perm_partner(WP_REST_Request $req) {
        if (!class_exists('TIX_Partners')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $p = TIX_Partners::find_by_key((string) $req->get_header('X-Tix-Syndication-Key'));
        if (!$p) return new WP_Error('unauthorized', 'Ungültiger Partner-Schlüssel.', ['status' => 401]);
        wp_set_current_user(0);
        $req->set_param('_partner', $p);
        return true;
    }

    /** GET /syndicate/co-sites?q= (Lizenzseite → Plattform): ohne die anfragende Seite und ihren Veranstalter. */
    public static function rest_partner_search(WP_REST_Request $req) {
        $p = (array) $req->get_param('_partner');
        $q = trim(sanitize_text_field((string) $req->get_param('q')));
        if (mb_strlen($q) < 2) return new WP_Error('tix_invalid_field', 'Bitte mindestens 2 Zeichen eingeben.', ['status' => 400, 'field' => 'q']);
        $ex = intval($p['organizer_id'] ?? 0);
        return rest_ensure_response(['ok' => true, 'items' => self::search($q, $ex ? [$ex] : [], (string) ($p['id'] ?? ''))]);
    }
}
