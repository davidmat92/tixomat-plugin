<?php
if (!defined('ABSPATH')) exit;

/**
 * Co-Veranstalter als Verkaufsinstanz (1.38.713, Paket 3, Vertrag `uebergaben/vertrag-co-geldempfaenger.md` §5).
 *
 * Plattform:
 *   - seller($eid): self (die Plattform verkauft) | source (Broker an die Quelle) | site (Broker an die Kunden-Seite des Co)
 *   - POST /partner/co-events/{id}/local   (Kunden-Seite meldet lokale ID, Verkaufsstand, Ausgleich)
 *   - POST /partner/sale/{id}/quote|orders, GET …/orders/{oid}, GET …/balance   (Lizenzseite vermittelt ihr Event an den Co)
 *   - Aktion „money“ in POST /syndicate/{id}/co-organizer (Lizenzseite stellt Geldempfänger/Anteil um)
 * Kunden-Seite: verkaufsfähiger Spiegel (`_tix_platform_co_sells`), meldet sich per /local.
 * Lizenzseite: Kasse vermittelt über die Plattform (TIX_Co_Sale, Ziel /partner/sale/{remote}), Ticket-Felder gesperrt.
 */
class TIX_Co_Seller {
    const NS        = 'tixomat/v1';
    const M_PARTNER = '_tix_sale_partner';
    const M_SOURCE  = '_tix_sale_source_id';
    const M_SOLD    = '_tix_sale_sold';
    const M_BALANCE = '_tix_sale_balance';
    const M_FROZEN  = '_tix_sale_frozen';
    const M_URL     = '_tix_sale_url';      // Adresse des Events auf der Kunden-Seite des Co (von /local, 1.38.745)
    const M_SELLS   = '_tix_platform_co_sells';
    const TICKET_FIELDS = ['categories', 'tickets_enabled', 'presale_active', 'presale_start', 'presale_end_mode', 'presale_end', 'presale_end_offset'];
    const TICKET_META   = ['_tix_ticket_categories', '_tix_tickets_enabled', '_tix_presale_active', '_tix_presale_start', '_tix_presale_end_mode', '_tix_presale_end', '_tix_presale_end_offset'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('tix_co_money_sold', [__CLASS__, 'sold'], 10, 2);
        add_action('tix_co_money_changed', [__CLASS__, 'on_money_changed'], 10, 3);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Plattform: wer verkauft? ═══════════════

    /** Partner der Kunden-Seite eines Veranstalters (mit Zugang für die Vermittlung) oder null. */
    public static function site_partner($oid) {
        if (!class_exists('TIX_Partners') || !intval($oid)) return null;
        foreach (TIX_Partners::all() as $p) {
            if (intval($p['organizer_id'] ?? 0) === intval($oid) && (string) ($p['api_base'] ?? '') !== '' && strlen((string) ($p['key_out'] ?? '')) >= 20 && empty($p['pending'])) return $p;
        }
        return null;
    }

    private static function is_site($oid) {
        return class_exists('TIX_Co_Sites') && TIX_Co_Sites::is_site_profile($oid);
    }

    /** Co-Veranstalter, der das Geld einnimmt (Plattform-Veranstalter), oder 0 (mehrere Co: 1.38.716). */
    public static function co_org($eid) {
        return class_exists('TIX_Co_Money') ? TIX_Co_Money::owner_id($eid) : 0;
    }

    /** self | source | site (nur Plattform). */
    public static function seller($eid) {
        $eid = intval($eid);
        $syn = get_post_meta($eid, '_tix_syndicated', true) === '1';
        $co = self::co_org($eid);
        if (!$co) return $syn ? 'source' : 'self';
        return self::is_site($co) ? 'site' : 'self';
    }

    /** Verkauft die Plattform ein geteiltes Event selbst (Fall B)? */
    public static function sells_here($eid) {
        return self::multi() && get_post_meta(intval($eid), '_tix_syndicated', true) === '1' && self::seller($eid) === 'self';
    }

    /** Broker: Partner array | null (kein Broker) | false (wie bisher). */
    public static function broker_partner($eid) {
        if (!self::multi()) return false;
        $s = self::seller($eid);
        if ($s === 'source') return false;
        if ($s === 'self') return get_post_meta($eid, '_tix_syndicated', true) === '1' ? null : false;
        $p = self::site_partner(self::co_org($eid));
        return ($p && intval(get_post_meta($eid, self::M_SOURCE, true))) ? $p : null;
    }

    /**
     * Geteilter Spiegel, den die Kunden-Seite des Co verkauft (Fall B mit eigener Seite, 1.38.745):
     * {site, checkout_url} des Co statt der Quelle, sonst null.
     */
    public static function site_origin($eid) {
        $eid = intval($eid);
        if (!self::multi() || get_post_meta($eid, '_tix_syndicated', true) !== '1' || self::seller($eid) !== 'site') return null;
        $co = self::co_org($eid);
        $legal = class_exists('TIX_Co_Legal') ? TIX_Co_Legal::org($co) : [];
        $name = (string) (($legal['name'] ?? '') ?: html_entity_decode((string) get_the_title($co), ENT_QUOTES, 'UTF-8'));
        $url = (string) get_post_meta($eid, self::M_URL, true);
        // sonst Startseite der Kunden-Seite: gemeldeter Host, dann Host des Partners (nie der Host der Plattform)
        $sm = get_post_meta($co, '_tix_site_seller', true);
        $h = is_array($sm) ? (string) ($sm['host'] ?? '') : '';
        if ($h === '' && ($p = self::site_partner($co))) $h = (string) wp_parse_url((string) $p['api_base'], PHP_URL_HOST);
        if ($url === '' && $h !== '') $url = 'https://' . $h . '/';
        return ['site' => $name, 'checkout_url' => $url];
    }

    public static function source_id($eid) {
        if (self::multi() && self::seller($eid) === 'site') return intval(get_post_meta($eid, self::M_SOURCE, true));
        return intval(get_post_meta($eid, '_tix_source_id', true));
    }

    /** Keine Kanalgebühr bei Verkauf über eine Kunden-Seite (F2). */
    public static function no_fee($eid) {
        return self::multi() && self::seller($eid) === 'site';
    }

    public static function sold($sold, $eid) {
        if ($sold) return true;
        if (get_post_meta(intval($eid), self::M_SOLD, true) === '1') return true;
        foreach ((array) get_option('tix_co_sale_orders', []) as $m) if (intval($m['event'] ?? 0) === intval($eid) && !empty($m['paid'])) return true;
        return false;
    }

    /** Umstellen auf „Co verkauft“: geteilte Spiegel einfrieren (Fall B). */
    public static function on_money_changed($eid, $owner, $share) {
        if (self::multi() && get_post_meta($eid, '_tix_syndicated', true) === '1') {
            if ($owner === 'co' && self::seller($eid) === 'self') update_post_meta($eid, self::M_FROZEN, '1'); else delete_post_meta($eid, self::M_FROZEN);
        }
    }

    // ═══════════════ Plattform: Lizenzseite stellt um (Aktion „money“) ═══════════════

    /**
     * POST /syndicate/{id}/co-organizer {action: money, owner, share | shares} → array | WP_Error.
     * owner: 'creator' | 'co' (nur bei einem Co) | 'o:{ID}' bzw. ID; shares: {party => %} (1.38.716).
     */
    public static function syndicate_money($eid, array $b) {
        $ids = TIX_Co_Remote::mirror_ids($eid);
        if (!$ids) return new WP_Error('tix_invalid_field', 'Kein Co-Veranstalter gesetzt.', ['status' => 400]);
        $raw = $b['owner'] ?? 'creator';
        $co = $raw === 'co' ? (count($ids) === 1 ? $ids[0] : 0) : (is_string($raw) && strpos($raw, 'o:') === 0 ? intval(substr($raw, 2)) : (is_numeric($raw) ? intval($raw) : 0));
        if ($co) {
            if (!in_array($co, $ids, true)) return new WP_Error('tix_invalid_field', 'Dieser Veranstalter ist kein Co-Veranstalter des Events.', ['status' => 400, 'field' => 'owner']);
            if (!TIX_Co_Remote::mirror_has_access($eid, $co)) return new WP_Error('tix_co_money_unsupported', 'Der Co-Veranstalter braucht Zugriff, um das Geld einzunehmen.', ['status' => 409, 'reason' => 'no_access']);
            if (self::is_site($co) && !self::site_partner($co)) return new WP_Error('tix_co_money_unsupported', 'Die Seite des Co-Veranstalters ist noch nicht mit der Plattform verbunden.', ['status' => 409, 'reason' => 'site_not_ready']);
        } elseif ($raw === 'co') {
            return new WP_Error('tix_invalid_field', 'Bitte angeben, welcher Co-Veranstalter das Geld einnimmt.', ['status' => 400, 'field' => 'owner']);
        }
        $in = ['owner' => $co ?: 'creator'];
        if (isset($b['shares']) && is_array($b['shares'])) $in['shares'] = $b['shares'];
        elseif (array_key_exists('share', $b)) $in['share'] = $b['share'];
        $c = TIX_Co_Money::check_values($eid, $in);
        if (is_wp_error($c)) return $c;
        if ($c) TIX_Co_Money::propose($eid, $c, ($b['via'] ?? '') === 'platform' ? 'platform' : 'creator'); // Zustimmung der Co (1.38.717)
        return ['ok' => true, 'co_money' => TIX_Co_Money::payload($eid), 'seller' => self::seller($eid)];
    }

    // ═══════════════ Plattform: Routen ═══════════════

    public static function routes() {
        if (!self::multi()) return;
        register_rest_route(self::NS, '/partner/co-events/(?P<id>\d+)/local', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_local'], 'permission_callback' => [__CLASS__, 'perm_site']]);
        $p = ['permission_callback' => [__CLASS__, 'perm_source']];
        register_rest_route(self::NS, '/partner/sale/(?P<id>\d+)/quote', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_quote']] + $p);
        register_rest_route(self::NS, '/partner/sale/(?P<id>\d+)/orders', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_orders']] + $p);
        register_rest_route(self::NS, '/partner/sale/(?P<id>\d+)/orders/(?P<oid>\d+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_status']] + $p);
        register_rest_route(self::NS, '/partner/sale/(?P<id>\d+)/balance', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_balance']] + $p);
    }

    public static function perm_site(WP_REST_Request $req) {
        $ok = TIX_Site_Move::perm_partner($req);
        if ($ok !== true) return $ok;
        $oid = intval($req->get_param('_partner_oid'));
        if (!TIX_Site_Move::partner_event_ok($oid, absint($req['id']))) return new WP_Error('tix_co_forbidden', 'Kein gemeinsames Event.', ['status' => 403]);
        $p = TIX_Partners::find_by_key((string) $req->get_header('X-Tix-Syndication-Key'));
        $req->set_param('_partner_id', $p ? (string) $p['id'] : '');
        return true;
    }

    /** POST /partner/co-events/{id}/local {local_id, sold, balance} */
    public static function rest_local(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $b = $req->get_json_params() ?: [];
        if (self::seller($eid) !== 'site' || self::co_org($eid) !== intval($req->get_param('_partner_oid'))) return new WP_Error('tix_co_sale_unavailable', 'Diese Seite verkauft das Event nicht.', ['status' => 409, 'reason' => 'not_seller']);
        $local = absint($b['local_id'] ?? 0);
        if (!$local) return new WP_Error('tix_invalid_field', 'local_id fehlt.', ['status' => 400, 'field' => 'local_id']);
        update_post_meta($eid, self::M_PARTNER, (string) $req->get_param('_partner_id'));
        update_post_meta($eid, self::M_SOURCE, $local);
        if (!empty($b['sold'])) update_post_meta($eid, self::M_SOLD, '1');
        $url = esc_url_raw((string) ($b['url'] ?? ''), ['https', 'http']);
        if ($url !== '') update_post_meta($eid, self::M_URL, $url); // Event-Adresse der Kunden-Seite (1.38.745)
        if (is_array($b['seller'] ?? null) && class_exists('TIX_Co_Legal')) update_post_meta(intval($req->get_param('_partner_oid')), '_tix_site_seller', TIX_Co_Legal::clean($b['seller'])); // Verkäufer-Angaben der Seite (1.38.717)
        if (is_array($b['balance'] ?? null)) update_post_meta($eid, self::M_BALANCE, array_intersect_key($b['balance'], array_flip(['orders', 'tickets', 'gross', 'refunds', 'fees', 'net', 'share', 'share_amount', 'currency', 'as_of', 'final', 'payer', 'payee', 'seller', 'note'])));
        return rest_ensure_response(['ok' => true]);
    }

    /** Lizenzseite: Spiegel des eigenen Events, das ein Co verkauft. */
    public static function perm_source(WP_REST_Request $req) {
        $ok = TIX_Co_Remote::perm_syndicate($req);
        if ($ok !== true) return $ok;
        $eid = absint($req['id']);
        if (TIX_Co_Money::owner($eid) !== 'co' || self::seller($eid) === 'source') return new WP_Error('tix_co_sale_unavailable', 'Dieses Event verkauft der Veranstalter selbst.', ['status' => 409, 'reason' => 'not_co']);
        $p = (array) $req->get_param('_partner');
        $req->set_param('_site_host', strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) ($p['api_base'] ?? ''), PHP_URL_HOST))));
        return true;
    }

    private static function run($fn, WP_REST_Request $req, array $params, $id = 0) {
        return TIX_Co_Sale::run($fn, $params, (string) $req->get_param('_site_host'), $id);
    }

    public static function rest_quote(WP_REST_Request $req) {
        return self::run('quote', $req, ['event_id' => absint($req['id'])] + (array) ($req->get_json_params() ?: []));
    }

    public static function rest_orders(WP_REST_Request $req) {
        $b = (array) ($req->get_json_params() ?: []);
        $ret = (string) ($b['return_url'] ?? '');
        if ($ret !== '' && strtolower(preg_replace('/^www\./', '', (string) wp_parse_url($ret, PHP_URL_HOST))) !== (string) $req->get_param('_site_host')) return new WP_Error('tix_partner_return_url', 'Rücksprung nur auf die eigene Seite.', ['status' => 400]);
        return self::run('create', $req, ['event_id' => absint($req['id'])] + $b);
    }

    public static function rest_status(WP_REST_Request $req) {
        return self::run('status', $req, ['key' => (string) $req->get_param('key')], absint($req['oid']));
    }

    public static function rest_balance(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (self::seller($eid) === 'self') return rest_ensure_response(TIX_Co_Money::balance($eid) + ['source' => 'seller']);
        $b = get_post_meta($eid, self::M_BALANCE, true);
        return rest_ensure_response(is_array($b) ? ['ok' => true, 'source' => 'seller'] + $b : ['ok' => true, 'source' => 'seller', 'pending' => true, 'note' => 'Die Seite des Co-Veranstalters hat noch keinen Stand gemeldet.']);
    }

    // ═══════════════ Kunden-Seite ═══════════════

    public static function sells_local($eid) {
        return !self::multi() && get_post_meta(intval($eid), self::M_SELLS, true) === '1' && class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($eid);
    }

    /** Abgleich: Event verkaufsfähig machen (Ticket-Felder nur beim ersten Mal). */
    public static function apply_sync($id, array $it) {
        if (empty($it['sell'])) {
            if (get_post_meta($id, self::M_SELLS, true) === '1') { update_post_meta($id, self::M_SELLS, '0'); update_post_meta($id, '_tix_tickets_enabled', '0'); }
            return;
        }
        $first = get_post_meta($id, self::M_SELLS, true) !== '1' && get_post_meta($id, '_tix_sale_init', true) !== '1';
        update_post_meta($id, self::M_SELLS, '1');
        update_post_meta($id, '_tix_platform_co_sale', '0');
        update_post_meta($id, '_tix_extshop_enabled', '0');
        update_post_meta($id, TIX_Co_Money::META_OWNER, 'co');
        update_post_meta($id, TIX_Co_Money::META_SHARE, (string) floatval($it['share'] ?? 0));
        if (isset($it['parts']) && is_array($it['parts'])) update_post_meta($id, TIX_Co_Money::META_PARTS, array_values(array_filter($it['parts'], 'is_array'))); // mehrere Beteiligte (1.38.716)
        else delete_post_meta($id, TIX_Co_Money::META_PARTS);
        if ($first) {
            $cats = array_values(array_filter((array) ($it['categories'] ?? []), 'is_array'));
            update_post_meta($id, '_tix_ticket_categories', $cats);
            update_post_meta($id, '_tix_tickets_enabled', '1');
            foreach ((array) ($it['presale'] ?? []) as $k => $v) if (in_array($k, self::TICKET_META, true)) update_post_meta($id, $k, $v);
            update_post_meta($id, '_tix_sale_init', '1');
        }
    }

    /** Nach dem Abgleich: lokale ID, Verkaufsstand und Ausgleich an die Plattform melden. */
    public static function report($id) {
        $pid = TIX_Site_Move::platform_co_id($id);
        if (!$pid || !self::sells_local($id)) return;
        $sold = class_exists('TIX_Co_Money') && TIX_Co_Money::sold($id);
        $bal = class_exists('TIX_Co_Money') ? TIX_Co_Money::balance($id) : null;
        wp_remote_post(rtrim((string) tix_get_settings('syndication_api_url'), '/') . '/partner/co-events/' . $pid . '/local', ['timeout' => 20,
            'headers' => ['X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key'), 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode(['local_id' => intval($id), 'url' => class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to((string) get_permalink($id)) : (string) get_permalink($id), 'sold' => $sold, 'balance' => $bal, 'seller' => class_exists('TIX_Co_Legal') ? TIX_Co_Legal::site() : null])]);
    }

    // ═══════════════ Lizenzseite (Ersteller, Co verkauft) ═══════════════

    /** Ziel der Vermittlung für ein eigenes Event, das der Co verkauft, sonst ''. */
    public static function source_base($eid) {
        if (self::multi() || !class_exists('TIX_Co_Money') || !TIX_Co_Money::owner_id($eid)) return '';
        $remote = intval(get_post_meta($eid, '_tix_syndicate_remote_id', true));
        return $remote ? '/partner/sale/' . $remote : '';
    }

    /** Ticket-Felder gesperrt? (Ersteller-Seite, wenn der Co bzw. eine andere Seite verkauft) → WP_Error | null */
    public static function check_ticket_fields(array $body, $eid) {
        if (!$eid || !array_intersect(array_keys($body), self::TICKET_FIELDS)) return null;
        $locked = self::multi() ? (in_array(self::seller($eid), ['site'], true) && get_post_meta($eid, '_tix_syndicated', true) !== '1') : (self::source_base($eid) !== '');
        if (!$locked) return null;
        $name = class_exists('TIX_Co_Money') ? (string) ((TIX_Co_Money::payload($eid)['seller']['name'] ?? '') ?: 'der Co-Veranstalter') : 'der Co-Veranstalter';
        return new WP_Error('tix_co_money_seller', 'Tickets, Preise und Vorverkauf verwaltet ' . $name . ', solange der Co-Veranstalter das Geld einnimmt.', ['status' => 409, 'field' => 'categories']);
    }

    /** Lizenzseite: Ausgleich von der Plattform (Verkaufsinstanz). */
    public static function remote_balance($eid) {
        $base = self::source_base($eid);
        if ($base === '') return null;
        $r = wp_remote_get(rtrim((string) tix_get_settings('syndication_api_url'), '/') . $base . '/balance', ['timeout' => 15,
            'headers' => ['X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key'), 'Accept' => 'application/json']]);
        $d = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
        return is_array($d) ? $d : ['ok' => false, 'source' => 'seller', 'pending' => true, 'note' => 'Der Stand der Verkaufsinstanz ist gerade nicht abrufbar.'];
    }
}
