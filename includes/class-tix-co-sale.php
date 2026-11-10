<?php
if (!defined('ABSPATH')) exit;

/**
 * Vermittlung von einer Kunden-Seite über evendis.de (1.38.712, Paket 2, Vertrag `uebergaben/vertrag-co-geldempfaenger.md` §4).
 *
 * Plattform: POST /partner/co-events/{id}/sale/quote|orders, GET …/sale/orders/{oid}?key= (Partner-Schlüssel der Kunden-Seite).
 *   Führt die App-Kasse der Plattform für das Event aus (eigenes Event bzw. Broker an die Quelle), ohne Kanalgebühr (F2),
 *   Rücksprung auf die Kunden-Seite erlaubt.
 * Kunden-Seite: Die App-Kasse (/customer/cart/quote, /customer/orders, /customer/orders/{id}) vermittelt Spiegel von Co-Events
 *   an die Plattform; lokale Bestell-IDs ab 900000001, Ticket-Spiegel für „Meine Tickets“. Kein eigener Zahlungsanbieter nötig.
 */
class TIX_Co_Sale {
    const NS       = 'tixomat/v1';
    const MAP      = 'tix_co_sale_orders';
    const ID_START = 900000001;
    const M_SALE   = '_tix_platform_co_sale';
    const M_TERMS  = '_tix_platform_co_terms';
    const M_SELLER = '_tix_platform_co_seller';

    /** Plattform: Kanalgebühr für diesen Aufruf aus (F2). */
    public static $no_fee = false;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function host_of($url) {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) $url, PHP_URL_HOST)));
    }

    public static function routes() {
        if (!self::multi()) return;
        $p = ['permission_callback' => [__CLASS__, 'perm_platform']];
        register_rest_route(self::NS, '/partner/co-events/(?P<id>\d+)/sale/quote', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_quote']] + $p);
        register_rest_route(self::NS, '/partner/co-events/(?P<id>\d+)/sale/orders', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_orders']] + $p);
        register_rest_route(self::NS, '/partner/co-events/(?P<id>\d+)/sale/orders/(?P<oid>\d+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_status']] + $p);
    }

    // ═══════════════ Plattform ═══════════════

    /** Verkauft die Plattform das Event (selbst oder über den Broker) und nimmt der Ersteller das Geld ein? */
    public static function platform_sale_ready($eid) {
        $eid = intval($eid);
        if (get_post_status($eid) !== 'publish') return false;
        if (class_exists('TIX_Co_Money') && TIX_Co_Money::owner($eid) !== 'creator') return false; // Co nimmt ein → Paket 3
        if (get_post_meta($eid, '_tix_syndicated', true) === '1') return class_exists('TIX_Partner_Broker') && (bool) TIX_Partner_Broker::partner_for_event($eid);
        return get_post_meta($eid, '_tix_tickets_enabled', true) === '1';
    }

    /** Verkäufer (Name) + AGB für die Kunden-Seite. */
    public static function platform_seller($eid) {
        $info = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::syndicated_info($eid) : null;
        if ($info) return ['name' => (string) $info['site'], 'terms_url' => (string) ($info['terms_url'] ?? '')];
        $site = html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $terms = (string) tix_get_settings('terms_url');
        return ['name' => html_entity_decode((string) get_the_title(intval(get_post_meta($eid, '_tix_organizer_id', true))), ENT_QUOTES, 'UTF-8') ?: $site,
            'terms_url' => $terms === '' ? '' : (preg_match('#^https?://#i', $terms) ? $terms : home_url($terms))];
    }

    public static function perm_platform(WP_REST_Request $req) {
        $ok = TIX_Site_Move::perm_partner($req);
        if ($ok !== true) return $ok;
        $oid = intval($req->get_param('_partner_oid'));
        $eid = absint($req['id']);
        if (!TIX_Site_Move::partner_event_ok($oid, $eid)) return new WP_Error('tix_co_forbidden', 'Kein gemeinsames Event.', ['status' => 403]);
        if (!self::platform_sale_ready($eid)) return new WP_Error('tix_co_sale_unavailable', 'Für dieses Event gibt es gerade keinen Verkauf über die Plattform.', ['status' => 409, 'reason' => 'not_ready']);
        $p = TIX_Partners::find_by_key((string) $req->get_header('X-Tix-Syndication-Key'));
        $req->set_param('_site_host', $p ? self::host_of($p['api_base']) : '');
        return true;
    }

    /** App-Kasse der Plattform als Gast ausführen (ohne Kanalgebühr, Rücksprung zur Kunden-Seite erlaubt). */
    public static function run($fn, array $params, $host, $id = 0) {
        $sub = new WP_REST_Request($fn === 'status' ? 'GET' : 'POST', '/' . self::NS . '/customer/' . ($fn === 'quote' ? 'cart/quote' : 'orders'));
        if ($fn === 'status') { $sub->set_url_params(['id' => intval($id)]); $sub->set_query_params($params); }
        else { $sub->set_header('content-type', 'application/json'); $sub->set_body(wp_json_encode($params)); }
        wp_set_current_user(0);
        self::$no_fee = true;
        $old = TIX_Native_Checkout::$extra_return_hosts;
        if ($host !== '') TIX_Native_Checkout::$extra_return_hosts = [$host];
        try {
            $res = $fn === 'quote' ? TIX_App_Checkout::quote($sub) : ($fn === 'create' ? TIX_App_Checkout::create($sub) : TIX_App_Checkout::status($sub));
        } finally {
            self::$no_fee = false;
            TIX_Native_Checkout::$extra_return_hosts = $old;
        }
        return $res;
    }

    private static function body(WP_REST_Request $req) {
        $b = $req->get_json_params();
        return is_array($b) ? $b : [];
    }

    public static function rest_quote(WP_REST_Request $req) {
        return self::run('quote', ['event_id' => absint($req['id'])] + self::body($req), (string) $req->get_param('_site_host'));
    }

    public static function rest_orders(WP_REST_Request $req) {
        $b = self::body($req);
        $ret = (string) ($b['return_url'] ?? '');
        $host = (string) $req->get_param('_site_host');
        if ($ret !== '' && self::host_of($ret) !== $host) return new WP_Error('tix_partner_return_url', 'Rücksprung nur auf die eigene Seite.', ['status' => 400]);
        return self::run('create', ['event_id' => absint($req['id'])] + $b, $host);
    }

    public static function rest_status(WP_REST_Request $req) {
        return self::run('status', ['key' => (string) $req->get_param('key')], (string) $req->get_param('_site_host'), absint($req['oid']));
    }

    // ═══════════════ Kunden-Seite ═══════════════

    /** Ziel der Vermittlung (Route-Basis auf der Plattform) oder ''. Kunden-Seite: Co-Spiegel; Lizenzseite: eigenes Event, das der Co verkauft (1.38.713). */
    public static function target($eid) {
        if (self::multi()) return '';
        $eid = intval($eid);
        if (class_exists('TIX_Site_Move') && ($pid = TIX_Site_Move::platform_co_id($eid)) && get_post_meta($eid, self::M_SALE, true) === '1') return '/partner/co-events/' . $pid . '/sale';
        return class_exists('TIX_Co_Seller') ? TIX_Co_Seller::source_base($eid) : '';
    }

    public static function is_sale_mirror($eid) {
        return self::target($eid) !== '';
    }

    public static function is_local_id($id) {
        return !self::multi() && intval($id) >= self::ID_START;
    }

    /** Herkunft für syndicated_info auf der Kunden-Seite. */
    public static function syndicated_info($eid) {
        $seller = (string) (get_post_meta($eid, self::M_SELLER, true) ?: get_post_meta($eid, '_tix_platform_co_by', true));
        if ($seller === '' && class_exists('TIX_Co_Remote') && ($d = TIX_Co_Remote::payload($eid, class_exists('TIX_Co_Money') ? TIX_Co_Money::owner_id($eid) : 0))) $seller = (string) $d['name']; // Lizenzseite: Co verkauft (der Geldempfänger unter mehreren Co)
        $info = ['site' => $seller ?: 'Veranstalter', 'checkout_url' => '', 'sale_via_app' => true, 'terms_url' => (string) get_post_meta($eid, self::M_TERMS, true), 'brokered' => true];
        return class_exists('TIX_Co_Legal') ? TIX_Co_Legal::merge($info, $eid) : $info; // Verkäufer-Angaben (1.38.717)
    }

    private static function site_call($method, $route, array $body = null, array $query = []) {
        $url = rtrim((string) tix_get_settings('syndication_api_url'), '/') . $route;
        if ($query) $url = add_query_arg(array_map('rawurlencode', $query), $url);
        $args = ['method' => $method, 'timeout' => 30, 'headers' => ['X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key'), 'Accept' => 'application/json', 'Content-Type' => 'application/json']];
        if ($body !== null) $args['body'] = wp_json_encode($body ?: new stdClass());
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) return new WP_Error('tix_partner_unreachable', 'Der Ticketverkauf ist gerade nicht erreichbar. Bitte gleich noch einmal versuchen.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($r));
        $d = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code >= 200 && $code < 300 && is_array($d)) return $d;
        if (is_array($d) && !empty($d['code']) && $code >= 400 && $code < 500 && $code !== 401) return new WP_Error((string) $d['code'], (string) ($d['message'] ?? 'Fehler beim Verkauf.'), ['status' => $code] + (array) ($d['data'] ?? []));
        return new WP_Error('tix_co_sale_error', 'Der Ticketverkauf ist gerade nicht verfügbar.', ['status' => 502]);
    }

    private static function own_hosts() {
        $out = [self::host_of(home_url())];
        if (class_exists('TIX_Customer_URL') && TIX_Customer_URL::host() !== '') $out[] = strtolower(TIX_Customer_URL::host());
        return array_values(array_unique(array_filter($out)));
    }

    public static function site_quote($eid, WP_REST_Request $req) {
        $base = self::target($eid);
        $b = $req->get_json_params() ?: $req->get_params();
        unset($b['event_id']);
        $d = self::site_call('POST', $base . '/quote', (array) $b);
        if (is_wp_error($d)) return $d;
        $d['event_id'] = intval($eid);
        $d['syndicated'] = array_merge((array) ($d['syndicated'] ?? []), self::syndicated_info($eid));
        return rest_ensure_response($d);
    }

    public static function site_create($eid, WP_REST_Request $req) {
        $base = self::target($eid);
        $b = (array) ($req->get_json_params() ?: $req->get_params());
        unset($b['event_id'], $b['create_account'], $b['password']);
        $ret = (string) ($b['return_url'] ?? '');
        if ($ret !== '' && !in_array(self::host_of($ret), self::own_hosts(), true)) return new WP_Error('tix_partner_return_url', 'Rücksprung nur auf diese Seite.', ['status' => 400]);
        $d = self::site_call('POST', $base . '/orders', $b);
        if (is_wp_error($d)) return $d;
        $po = intval($d['order']['id'] ?? 0);
        if (!$po) return new WP_Error('tix_order_failed', 'Bestellung konnte nicht erstellt werden.', ['status' => 502]);
        $map = (array) get_option(self::MAP, []);
        foreach ($map as $lid => $m) if (intval($m['pid'] ?? 0) === $po && (string) ($m['base'] ?? '') === $base) { $local = intval($lid); break; }
        if (empty($local)) {
            $local = max(self::ID_START - 1, $map ? max(array_map('intval', array_keys($map))) : 0) + 1;
            $map[$local] = ['pid' => $po, 'key' => (string) ($d['order']['key'] ?? ''), 'event' => intval($eid), 'base' => $base,
                'email' => sanitize_email((string) ($d['order']['billing_email'] ?? ($b['billing']['email'] ?? ''))), 'created' => gmdate('c')];
            update_option(self::MAP, $map, false);
        }
        return rest_ensure_response(self::local_response($local, $d));
    }

    public static function site_status($id, WP_REST_Request $req) {
        $map = (array) get_option(self::MAP, []);
        $m = $map[intval($id)] ?? null;
        $key = (string) $req->get_param('key');
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        $mine = $user && !empty($m['email']) && strcasecmp((string) $user->user_email, (string) $m['email']) === 0;
        if (!$m || (!$mine && ($key === '' || !hash_equals((string) $m['key'], $key)))) return new WP_Error('tix_order_missing', 'Bestellung nicht gefunden.', ['status' => 404]);
        $d = self::site_call('GET', (string) $m['base'] . '/orders/' . intval($m['pid']), null, ['key' => (string) $m['key']]);
        if (is_wp_error($d)) return $d;
        return rest_ensure_response(self::local_response(intval($id), $d));
    }

    /** Antwort der Plattform auf die lokale Bestellung umschreiben, Ticket-Spiegel anlegen. */
    private static function local_response($local, array $d) {
        $map = (array) get_option(self::MAP, []);
        $m = $map[$local];
        if (isset($d['order']) && is_array($d['order'])) {
            $o = $d['order'];
            $o['id'] = intval($local);
            $o['event'] = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::order_event_info(intval($m['event'])) : ($o['event'] ?? null);
            $o['seller'] = TIX_App_Checkout::seller_of(intval($m['event']), ['name' => (string) (get_post_meta(intval($m['event']), self::M_SELLER, true) ?: ($o['seller']['name'] ?? '')), 'mail_from_seller' => true]);
            if (!empty($o['paid'])) {
                $o['tickets'] = self::mirror_tickets($local, $m, (array) ($o['tickets'] ?? []));
                if (empty($m['paid'])) { $map[$local]['paid'] = 1; update_option(self::MAP, $map, false); }
            }
            else $o['tickets'] = [];
            $o['invoice_url'] = '';
            $d['order'] = $o;
        }
        return $d;
    }

    private static function mirror_tickets($local, array $m, array $tickets) {
        $out = [];
        $eid = intval($m['event']);
        foreach ($tickets as $t) {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($t['code'] ?? '')));
            if ($code === '') continue;
            $ids = get_posts(['post_type' => 'tix_ticket', 'post_status' => ['publish', 'private'], 'posts_per_page' => 1, 'fields' => 'ids',
                'meta_query' => [['key' => '_tix_ticket_code', 'value' => $code], ['key' => '_tix_ticket_co_sale', 'value' => strval($local)]]]);
            $tid = $ids ? intval($ids[0]) : 0;
            if (!$tid) {
                $tid = intval(wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => $code, 'post_author' => 1]));
                if (!$tid) continue;
                foreach (['_tix_ticket_mirror' => 1, '_tix_ticket_co_sale' => strval($local), '_tix_ticket_code' => $code, '_tix_ticket_order_id' => intval($local),
                    '_tix_ticket_event_id' => $eid, '_tix_ticket_event_name' => get_the_title($eid),
                    '_tix_ticket_source_site' => (string) (get_post_meta($eid, self::M_SELLER, true) ?: get_post_meta($eid, '_tix_platform_co_by', true))] as $k => $v) update_post_meta($tid, $k, $v);
            }
            update_post_meta($tid, '_tix_ticket_cat_name', sanitize_text_field((string) ($t['category'] ?? 'Ticket')));
            update_post_meta($tid, '_tix_ticket_price', round(floatval($t['price'] ?? 0), 2));
            update_post_meta($tid, '_tix_ticket_status', sanitize_key((string) ($t['status'] ?? 'valid')) ?: 'valid');
            update_post_meta($tid, '_tix_ticket_checked_in', !empty($t['checked_in']) ? 1 : 0);
            update_post_meta($tid, '_tix_ticket_owner_email', sanitize_email((string) ($t['owner_email'] ?? '')) ?: (string) $m['email']);
            update_post_meta($tid, '_tix_ticket_owner_name', sanitize_text_field((string) ($t['owner_name'] ?? '')));
            $p = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::ticket_from_post($tid) : null;
            if ($p) $out[] = $p;
        }
        return $out;
    }

    /** Verkauft diese Seite selbst online (eigene Events mit Online-Verkauf, ohne Co-Spiegel)? */
    public static function payments_needed() {
        if (self::multi()) return true;
        $own = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'future'], 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => '_tix_tickets_enabled', 'value' => '1'], ['key' => '_tix_platform_co', 'compare' => 'NOT EXISTS']]]);
        if ($own) return true;
        // Diese Seite verkauft für den Co selbst (1.38.713): dann braucht sie einen Zahlungsanbieter (1.38.717)
        if (get_posts(['post_type' => 'event', 'post_status' => ['publish', 'future'], 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => '_tix_platform_co_sells', 'value' => '1']]])) return true;
        $brokered = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'future'], 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => self::M_SALE, 'value' => '1']]]);
        return !$brokered;
    }
}
