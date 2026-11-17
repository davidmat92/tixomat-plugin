<?php
/**
 * TIX_Abandoned_Carts_REST – abgebrochene Warenkörbe im Studio (1.38.885, WP-Admin-Restliste W4 / V11). Nur Anzeige.
 *   GET /organizer/abandoned-carts?days=30&status=pending|sent|recovered
 *   → {ok, items: [{id, created, email, event: {id, title}|null, tickets, total, status}], counts: {pending, sent, recovered}, days}
 * Rechte: Admin; Einzel-Seite Inhaber (tix_organizer); evendis Inhaber/Team-Admin – nur Warenkörbe eigener Events.
 * Die E-Mail steht nur hier (Rückruf); Warenkorb-Daten kommen vom Browser und werden nur gezählt, nie ausgegeben.
 */
if (!defined('ABSPATH')) exit;

class TIX_Abandoned_Carts_REST {

    const NS = 'tixomat/v1';
    const CPT = 'tix_abandoned_cart';
    const STATUSES = ['pending', 'sent', 'recovered'];

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes() {
        register_rest_route(self::NS, '/organizer/abandoned-carts', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => [__CLASS__, 'perm']]);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    public static function perm() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        if (current_user_can('manage_options')) return true;
        if (self::multi()) return (TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) ? true : new WP_Error('rest_forbidden', 'Nur Inhaber und Team-Admins.', ['status' => 403]);
        return in_array('tix_organizer', (array) wp_get_current_user()->roles, true) ? true : new WP_Error('rest_forbidden', 'Nur Inhaber bzw. Admin.', ['status' => 403]);
    }

    /** Rein: Warenkorb-Daten (JSON vom Browser) → [tickets, total] */
    public static function cart_sums($raw) {
        $d = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($d)) return [0, 0.0];
        $items = isset($d['items']) && is_array($d['items']) ? $d['items'] : $d;
        $n = 0; $sum = 0.0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $q = max(0, min(100, intval($it['qty'] ?? ($it['quantity'] ?? 0))));
            $p = is_numeric($it['price'] ?? null) ? max(0, min(10000, floatval($it['price']))) : 0.0;
            $n += $q; $sum += $q * $p;
        }
        return [$n, round($sum, 2)];
    }

    public static function rest_list(WP_REST_Request $req) {
        $days = max(1, min(365, intval($req->get_param('days') ?: 30)));
        $status = (string) $req->get_param('status');
        $q = ['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true,
            'date_query' => [['after' => wp_date('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS), 'inclusive' => true]]];
        $own = null;
        if (self::multi() && !current_user_can('manage_options')) $own = intval(TIX_App_Scope::organizer_id_for_user());
        $items = []; $counts = array_fill_keys(self::STATUSES, 0);
        foreach (get_posts($q) as $p) {
            $eid = intval(get_post_meta($p->ID, '_tix_ac_event_id', true));
            if ($own !== null && (!$eid || intval(get_post_meta($eid, '_tix_organizer_id', true)) !== $own)) continue;
            $st = (string) get_post_meta($p->ID, '_tix_ac_status', true);
            if (!in_array($st, self::STATUSES, true)) $st = 'pending';
            $counts[$st]++;
            if ($status !== '' && $status !== $st) continue;
            [$n, $sum] = self::cart_sums(get_post_meta($p->ID, '_tix_ac_cart_data', true));
            $items[] = ['id' => intval($p->ID), 'created' => mysql_to_rfc3339($p->post_date_gmt && $p->post_date_gmt !== '0000-00-00 00:00:00' ? get_date_from_gmt($p->post_date_gmt) : $p->post_date),
                'email' => (string) get_post_meta($p->ID, '_tix_ac_email', true),
                'event' => $eid && get_post_type($eid) === 'event' ? ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8')] : null,
                'tickets' => $n, 'total' => $sum, 'status' => $st];
        }
        $r = rest_ensure_response(['ok' => true, 'items' => $items, 'counts' => $counts, 'days' => $days,
            'enabled' => function_exists('tix_get_settings') && !empty(tix_get_settings('abandoned_cart_enabled'))]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}

TIX_Abandoned_Carts_REST::init();
