<?php
/**
 * TIX_Order_Manual – Bestellungen von Hand im Studio (1.38.879, WP-Admin-Restliste W1 = V1 + V12).
 * Vertrag: uebergaben/vertrag-bestellungen-von-hand.md, DOCUMENTATION.md §240.
 *
 *   POST /orders/{id}/mark-paid          Vorkasse als bezahlt setzen (gleiche Folgen wie eine Online-Zahlung)
 *   GET|POST /orders/{id}/notes          Notizen (Verlauf) lesen / schreiben
 *   POST /orders/{id}/regenerate-tickets fehlende Tickets nachholen
 *   POST /orders/{id}/send-bank-info     Bankverbindung an den Käufer
 *   POST /orders/manual                  Bestellung von Hand (free | paid | transfer)
 *   POST /tickets/{id}/status            Ticket stornieren / reaktivieren (ohne Geld und Bestand)
 *
 * Rechte: TIX_App_Events::perm_money + Bestellung/Event des eigenen Veranstalters. Auf evendis (Mehr-Veranstalter) gehen
 * Vorkasse-Geld und Bankdaten über das Konto der Plattform → mark-paid, send-bank-info, manual paid/transfer nur Plattform-Admin.
 * Verlauf: Bestell-Notiz + tix_audit_log (order_paid, order_manual, ticket_status).
 */
if (!defined('ABSPATH')) exit;

class TIX_Order_Manual {

    const NS = 'tixomat/v1';
    const OPEN = ['on-hold', 'pending'];
    const PAID = ['completed', 'processing'];
    const OFFLINE = ['bank', 'bacs', 'manual'];
    const KINDS = ['free', 'paid', 'transfer'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $money = ['TIX_App_Events', 'perm_money'];
        $read  = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/mark-paid', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_mark_paid'], 'permission_callback' => $money]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/notes', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_notes'], 'permission_callback' => $read],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_add_note'], 'permission_callback' => $money],
        ]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/regenerate-tickets', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_regenerate'], 'permission_callback' => $money]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/send-bank-info', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_bank_info'], 'permission_callback' => $money]);
        register_rest_route(self::NS, '/orders/manual', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_manual'], 'permission_callback' => $money]);
        register_rest_route(self::NS, '/tickets/(?P<id>\d+)/status', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_ticket_status'], 'permission_callback' => $money]);
    }

    // ═══════════════ Reine Regeln (Umzug: Fixtures) ═══════════════

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }
    private static function admin() { return current_user_can('manage_options'); }

    /** Darf diese Bestellung von Hand als bezahlt gelten? → true | [code, Meldung, HTTP] */
    public static function mark_paid_check($status, $method, $total, $received, $multi, $is_admin) {
        if (!in_array($status, self::OPEN, true)) return ['tix_not_open', 'Nur offene Bestellungen (Vorkasse wartet) lassen sich als bezahlt setzen.', 409];
        if (!in_array($method, self::OFFLINE, true)) return ['tix_online_payment', 'Bei Online-Zahlungen kommt der Status vom Zahlungsanbieter.', 409];
        if ($multi && !$is_admin) return ['tix_platform_only', 'Vorkasse geht auf das Konto der Plattform – den Zahlungseingang bestätigt evendis.', 403];
        if ($received !== null && abs(round(floatval($received), 2) - round(floatval($total), 2)) > 0.009) {
            return ['tix_partial_payment', 'Teilzahlungen gehen nicht: Tickets gibt es erst beim vollen Betrag (' . number_format(floatval($total), 2, ',', '.') . ' €). Den Teilbetrag bitte als Notiz festhalten.', 409];
        }
        return true;
    }

    /** Ticket-Statuswechsel erlaubt? → true | [code, Meldung, HTTP] */
    public static function ticket_status_check($from, $to, $checked_in, $resale, $cancelled_by, $order_paid) {
        if ($to === 'cancelled') {
            if (!in_array($from, ['valid', 'transferred'], true)) return ['tix_ticket_state', 'Das Ticket ist nicht gültig.', 409];
            if ($checked_in) return ['tix_ticket_used', 'Das Ticket ist schon eingecheckt.', 409];
            if (in_array($resale, ['open', 'reserved'], true)) return ['tix_ticket_resale', 'Das Ticket wird gerade weiterverkauft.', 409];
            return true;
        }
        if ($from !== 'cancelled') return ['tix_ticket_state', 'Das Ticket ist nicht storniert.', 409];
        if ($cancelled_by !== 'studio' || !$order_paid) return ['tix_not_reactivatable', 'Dieses Ticket wurde mit einer Erstattung oder Stornierung zurückgenommen und lässt sich nicht reaktivieren.', 409];
        return true;
    }

    /** Art der Bestellung von Hand erlaubt? → true | [code, Meldung, HTTP] */
    public static function manual_kind_check($kind, $multi, $is_admin, $bank_ready) {
        if (!in_array($kind, self::KINDS, true)) return ['tix_invalid_field', 'Art: free, paid oder transfer.', 400];
        if ($multi && $kind === 'paid') return ['tix_platform_only', 'Auf evendis gibt es von Hand nur Freikarten (Geld außerhalb von evendis ist noch nicht geregelt).', 403];
        if ($multi && $kind === 'transfer' && !$is_admin) return ['tix_platform_only', 'Vorkasse läuft über das Konto der Plattform.', 403];
        if ($kind === 'transfer' && !$bank_ready) return ['tix_bank_off', 'Vorkasse ist nicht eingerichtet (Zahlungen → Überweisung).', 409];
        return true;
    }

    private static function err($r, $field = null) {
        return new WP_Error($r[0], $r[1], array_filter(['status' => $r[2], 'field' => $field], function ($v) { return $v !== null; }));
    }

    // ═══════════════ Hilfen ═══════════════

    private static function row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", intval($id)));
    }

    private static function ticket_ids($order_id) {
        return array_map('intval', get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_key' => '_tix_ticket_order_id', 'meta_value' => (string) intval($order_id), 'orderby' => 'ID', 'order' => 'ASC']));
    }

    private static function state($id) {
        $r = self::row($id);
        return ['status' => (string) ($r->status ?? ''), 'total' => round(floatval($r->total ?? 0), 2),
            'refunded_total' => class_exists('TIX_Order_Admin') ? TIX_Order_Admin::refunded_total($id) : 0.0];
    }

    private static function actor() { $u = wp_get_current_user(); return (string) ($u->display_name ?: $u->user_login ?: 'Studio'); }

    /** Änderungsprotokoll (ohne Klartextdaten Betroffener) */
    private static function audit($action, $subject, array $summary, $org = 0) {
        if (!class_exists('TIX_Customers') || !method_exists('TIX_Customers', 'audit')) return;
        $sc = self::multi() ? ($org ? ['scope' => 'organizer', 'oid' => intval($org)] : ['scope' => 'platform', 'oid' => 0]) : ['scope' => 'site', 'oid' => 0];
        TIX_Customers::audit($action, $subject, $sc, $summary);
    }

    private static function order_org($order_id) {
        global $wpdb;
        $eid = intval($wpdb->get_var($wpdb->prepare("SELECT event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d AND event_id > 0 LIMIT 1", intval($order_id))));
        return $eid ? intval(get_post_meta($eid, '_tix_organizer_id', true)) : 0;
    }

    public static function notes($order_id, $limit = 100) {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, note, note_type, author, date_created FROM {$wpdb->prefix}tix_order_notes WHERE order_id = %d ORDER BY id DESC LIMIT %d", intval($order_id), intval($limit)));
        return array_map(function ($r) {
            return ['id' => intval($r->id), 'text' => (string) $r->note, 'type' => (string) $r->note_type, 'author' => (string) $r->author, 'at' => mysql2date('Y-m-d H:i', $r->date_created)];
        }, $rows);
    }

    /** Für das Bestell-Detail: was geht von Hand? */
    public static function detail_actions($order_id) {
        $r = self::row($order_id);
        if (!$r) return null;
        $is_admin = self::admin();
        $out = [
            'can_mark_paid'      => self::mark_paid_check($r->status, $r->payment_method, $r->total, null, self::multi(), $is_admin) === true,
            'can_send_bank_info' => in_array($r->payment_method, ['bank', 'bacs'], true) && in_array($r->status, self::OPEN, true) && (!self::multi() || $is_admin),
            'can_regenerate'     => in_array($r->status, self::PAID, true),
            'tickets'            => (object) [],
        ];
        $paid = in_array($r->status, self::PAID, true);
        $t = [];
        foreach (self::ticket_ids($order_id) as $tid) {
            $st = (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid');
            $ci = (string) get_post_meta($tid, '_tix_ticket_checked_in', true) === '1';
            $rs = (string) get_post_meta($tid, '_tix_ticket_resale', true);
            $by = (string) get_post_meta($tid, '_tix_ticket_cancelled_by', true);
            $mirror = (string) get_post_meta($tid, '_tix_ticket_mirror', true) === '1';
            $t[(string) $tid] = ['cancel' => !$mirror && self::ticket_status_check($st, 'cancelled', $ci, $rs, $by, $paid) === true,
                                 'reactivate' => !$mirror && self::ticket_status_check($st, 'valid', $ci, $rs, $by, $paid) === true];
        }
        if ($t) $out['tickets'] = $t;
        return $out;
    }

    private static function order_or_error($id) {
        return class_exists('TIX_App_Events') && method_exists('TIX_App_Events', 'order_or_error')
            ? TIX_App_Events::order_or_error($id) : new WP_Error('not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
    }

    // ═══════════════ Routen ═══════════════

    public static function rest_mark_paid(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        $id = intval($order->get_id());
        $r = self::row($id);
        $received = $req->get_param('received');
        if ($received !== null && $received !== '' && !is_numeric(str_replace(',', '.', (string) $received))) return new WP_Error('tix_invalid_field', 'Ungültiger Betrag.', ['status' => 400, 'field' => 'received']);
        $received = ($received === null || $received === '') ? null : floatval(str_replace(',', '.', (string) $received));
        $ok = self::mark_paid_check((string) $r->status, (string) $r->payment_method, floatval($r->total), $received, self::multi(), self::admin());
        if ($ok !== true) return self::err($ok, $ok[0] === 'tix_partial_payment' ? 'received' : null);
        $note = mb_substr(sanitize_textarea_field((string) $req->get_param('note')), 0, 500);
        // Team-Mail „Neue Bestellung“ unterdrücken (das Team hat es selbst ausgelöst)
        set_transient('_tix_skip_admin_email_' . $id, 1, 300);
        TIX_Native_Checkout::update_order_status($id, 'completed', 'admin');
        if (class_exists('TIX_Order_Admin')) TIX_Order_Admin::add_note($id, '💶 Zahlung eingegangen (von Hand, ' . self::actor() . ')' . ($note !== '' ? "\n" . $note : ''), 'internal', self::actor());
        self::audit('order_paid', $id, ['total' => round(floatval($r->total), 2), 'method' => (string) $r->payment_method], self::order_org($id));
        return rest_ensure_response(['ok' => true, 'order' => self::state($id), 'tickets' => count(self::ticket_ids($id))]);
    }

    public static function rest_notes(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        return rest_ensure_response(['ok' => true, 'notes' => self::notes(intval($order->get_id()))]);
    }

    public static function rest_add_note(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        $text = trim(sanitize_textarea_field((string) $req->get_param('text')));
        if ($text === '' || mb_strlen($text) > 2000) return new WP_Error('tix_invalid_field', 'Bitte eine Notiz mit höchstens 2000 Zeichen eingeben.', ['status' => 400, 'field' => 'text']);
        $id = intval($order->get_id());
        $nid = TIX_Order_Admin::add_note($id, $text, 'internal', self::actor());
        $note = array_values(array_filter(self::notes($id, 20), function ($n) use ($nid) { return $n['id'] === intval($nid); }))[0] ?? null;
        return rest_ensure_response(['ok' => true, 'note' => $note]);
    }

    public static function rest_regenerate(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        $id = intval($order->get_id());
        if (!in_array(self::state($id)['status'], self::PAID, true)) return new WP_Error('tix_not_paid', 'Tickets gibt es erst für bezahlte Bestellungen.', ['status' => 409]);
        if (!class_exists('TIX_Tickets') || !method_exists('TIX_Tickets', 'on_native_order_completed')) return new WP_Error('tix_unavailable', 'Tickets sind nicht verfügbar.', ['status' => 500]);
        $before = count(self::ticket_ids($id));
        TIX_Tickets::on_native_order_completed($id); // Kern verhindert Doppelte
        $after = count(self::ticket_ids($id));
        $created = max(0, $after - $before);
        TIX_Order_Admin::add_note($id, $created > 0 ? '🎫 ' . $created . ' fehlende Tickets nachgeholt (' . self::actor() . ')' : '🎫 Tickets geprüft – keine fehlten (' . self::actor() . ')', 'system');
        return rest_ensure_response(['ok' => true, 'created' => $created, 'tickets' => $after]);
    }

    public static function rest_bank_info(WP_REST_Request $req) {
        $order = self::order_or_error($req['id']);
        if (is_wp_error($order)) return $order;
        $id = intval($order->get_id());
        $r = self::row($id);
        if (!in_array((string) $r->payment_method, ['bank', 'bacs'], true)) return new WP_Error('tix_not_bank', 'Die Bestellung ist keine Vorkasse.', ['status' => 409]);
        if (!in_array((string) $r->status, self::OPEN, true)) return new WP_Error('tix_not_open', 'Die Bestellung ist nicht mehr offen.', ['status' => 409]);
        if (self::multi() && !self::admin()) return new WP_Error('tix_platform_only', 'Vorkasse läuft über das Konto der Plattform.', ['status' => 403]);
        $to = TIX_Order_Admin::send_bank_info($id, sanitize_email((string) $req->get_param('email')));
        if (is_wp_error($to)) return $to;
        return rest_ensure_response(['ok' => true, 'email' => $to]);
    }

    public static function rest_ticket_status(WP_REST_Request $req) {
        $tid = absint($req['id']);
        if (get_post_type($tid) !== 'tix_ticket') return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        $oid = intval(get_post_meta($tid, '_tix_ticket_order_id', true));
        $order = self::order_or_error($oid);
        if (is_wp_error($order)) return $order;
        // Zugriff auch über das Event des Tickets (falls die Bestellung keine Positionen mehr hätte)
        $eid = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
        if ($eid && !TIX_REST_API::can_access_event($eid)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Ticket.', ['status' => 403]);
        if ((string) get_post_meta($tid, '_tix_ticket_mirror', true) === '1') return new WP_Error('tix_mirror', 'Geteiltes Ticket: Das ändert der Veranstalter, der das Event teilt.', ['status' => 409]);
        $to = (string) $req->get_param('status');
        if (!in_array($to, ['cancelled', 'valid'], true)) return new WP_Error('tix_invalid_field', 'Status: cancelled oder valid.', ['status' => 400, 'field' => 'status']);
        $from = (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid');
        $ok = self::ticket_status_check($from, $to, (string) get_post_meta($tid, '_tix_ticket_checked_in', true) === '1', (string) get_post_meta($tid, '_tix_ticket_resale', true),
            (string) get_post_meta($tid, '_tix_ticket_cancelled_by', true), in_array(self::state($oid)['status'], self::PAID, true));
        if ($ok !== true) return self::err($ok);
        $reason = mb_substr(sanitize_textarea_field((string) $req->get_param('reason')), 0, 300);
        update_post_meta($tid, '_tix_ticket_status', $to);
        if ($to === 'cancelled') update_post_meta($tid, '_tix_ticket_cancelled_by', 'studio'); else delete_post_meta($tid, '_tix_ticket_cancelled_by');
        $code = (string) get_post_meta($tid, '_tix_ticket_code', true);
        if ($code !== '' && class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled') && method_exists('TIX_Ticket_DB', 'update_ticket')) {
            try { TIX_Ticket_DB::update_ticket($code, ['ticket_status' => $to]); } catch (\Throwable $e) {}
        }
        do_action('tix_ticket_status_set', $tid, $to, $from);
        TIX_Order_Admin::add_note($oid, ($to === 'cancelled' ? '⛔ Ticket ' : '✅ Ticket ') . $code . ($to === 'cancelled' ? ' storniert' : ' wieder gültig') . ' (von Hand, ' . self::actor() . ', ohne Geld und Bestand)' . ($reason !== '' ? ': ' . $reason : ''), 'status_change', self::actor());
        self::audit('ticket_status', $tid, ['order_id' => $oid, 'from' => $from, 'to' => $to], self::order_org($oid));
        return rest_ensure_response(['ok' => true, 'ticket' => ['id' => $tid, 'status' => $to]]);
    }

    // ── Bestellung von Hand ──

    public static function rest_manual(WP_REST_Request $req) {
        $body = (array) ($req->get_json_params() ?: $req->get_body_params());
        $idem = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($body['idempotency_key'] ?? ''));
        $ik = '';
        if (strlen($idem) >= 8 && strlen($idem) <= 64) {
            $ik = 'tix_manual_idem_' . md5(get_current_user_id() . '|' . $idem);
            $prev = get_transient($ik);
            if (is_array($prev)) return rest_ensure_response($prev + ['replayed' => true]);
            if (!add_option($ik . '_lock', time(), '', 'no')) return new WP_Error('tix_in_progress', 'Diese Bestellung wird gerade schon angelegt.', ['status' => 409]);
        }
        try {
            $res = self::manual_run($body);
            if (!is_wp_error($res) && $ik) set_transient($ik, $res, DAY_IN_SECONDS);
            return is_wp_error($res) ? $res : rest_ensure_response($res);
        } finally {
            if ($ik) delete_option($ik . '_lock');
        }
    }

    private static function manual_run(array $b) {
        $eid = absint($b['event_id'] ?? 0);
        if (!$eid || get_post_type($eid) !== 'event') return new WP_Error('tix_invalid_field', 'Bitte ein Event wählen.', ['status' => 400, 'field' => 'event_id']);
        if (!TIX_REST_API::can_access_event($eid)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $kind = (string) ($b['kind'] ?? '');
        $bank_ready = class_exists('TIX_Gateway_Bank') && TIX_Gateway_Bank::is_available();
        $ok = self::manual_kind_check($kind, self::multi(), self::admin(), $bank_ready);
        if ($ok !== true) return self::err($ok, $ok[0] === 'tix_invalid_field' ? 'kind' : null);
        if (!class_exists('TIX_Native_Checkout')) return new WP_Error('no_native', 'Nativer Checkout nicht aktiv.', ['status' => 500]);
        if (class_exists('TIX_Seatmap') && intval(get_post_meta($eid, '_tix_seatmap_id', true)) > 0) return new WP_Error('tix_seatmap', 'Events mit Saalplan bitte über die Kasse verkaufen (Platzwahl).', ['status' => 409]);

        $c = (array) ($b['customer'] ?? []);
        $first = mb_substr(sanitize_text_field((string) ($c['first_name'] ?? '')), 0, 80);
        $last  = mb_substr(sanitize_text_field((string) ($c['last_name'] ?? '')), 0, 80);
        $email = sanitize_email((string) ($c['email'] ?? ''));
        if ($first === '') return new WP_Error('tix_invalid_field', 'Bitte den Vornamen angeben.', ['status' => 400, 'field' => 'customer.first_name']);
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'customer.email']);
        $disc = $kind === 'free' ? 0.0 : floatval($b['discount_percent'] ?? 0);
        if ($disc < 0 || $disc > 100) return new WP_Error('tix_invalid_field', 'Rabatt zwischen 0 und 100 %.', ['status' => 400, 'field' => 'discount_percent']);
        $note = mb_substr(sanitize_textarea_field((string) ($b['note'] ?? '')), 0, 500);
        $notify = !array_key_exists('notify', $b) || filter_var($b['notify'], FILTER_VALIDATE_BOOLEAN);

        $cats = get_post_meta($eid, '_tix_ticket_categories', true);
        $lines = array_values((array) ($b['items'] ?? []));
        if (!$lines || count($lines) > 20) return new WP_Error('tix_invalid_field', 'Bitte 1 bis 20 Ticket-Arten angeben.', ['status' => 400, 'field' => 'items']);
        $merged = [];
        foreach ($lines as $i => $l) {
            $ci = isset($l['cat_index']) ? intval($l['cat_index']) : -1;
            $q  = intval($l['qty'] ?? 0);
            if (!is_array($cats) || !isset($cats[$ci]) || !is_array($cats[$ci]) || !empty($cats[$ci]['gift_card'])) return new WP_Error('tix_invalid_field', 'Ticket-Art nicht gefunden.', ['status' => 400, 'field' => 'items.' . $i . '.cat_index']);
            if ($q < 1 || $q > 50) return new WP_Error('tix_invalid_field', 'Anzahl 1–50.', ['status' => 400, 'field' => 'items.' . $i . '.qty']);
            $merged[$ci] = ($merged[$ci] ?? 0) + $q;
        }
        $title = get_the_title($eid);
        $cart = ['items' => [], 'coupon' => null];
        $sum = 0.0;
        foreach ($merged as $ci => $q) {
            $base = $kind === 'free' ? 0.0 : floatval(TIX_REST_API::current_price($cats[$ci]));
            $price = round($base * (1 - $disc / 100), 2);
            $cart['items'][] = ['event_id' => $eid, 'cat_index' => intval($ci), 'name' => sanitize_text_field($cats[$ci]['name'] ?? 'Ticket'), 'event_title' => $title,
                'price' => $price, 'qty' => $q, 'locked_price' => true, // fester Preis: get_cart() rechnet sonst Listenpreis + Gebühr neu
                'meta' => array_filter(['manual' => 1, 'discount_percent' => $disc > 0 ? $disc : null])];
            $sum += $price * $q;
        }
        if ($sum <= 0 && $kind !== 'free') $kind = 'free';
        $method = ['free' => 'free', 'paid' => 'manual', 'transfer' => 'bank'][$kind];

        $previous = TIX_Native_Checkout::get_cart();
        TIX_Native_Checkout::save_cart($cart);
        $oid = TIX_Native_Checkout::create_order([
            'billing_first_name' => $first, 'billing_last_name' => $last, 'billing_email' => $email,
            'billing_phone' => mb_substr(sanitize_text_field((string) ($c['phone'] ?? '')), 0, 40), 'billing_company' => mb_substr(sanitize_text_field((string) ($c['company'] ?? '')), 0, 120),
            'billing_address_1' => '', 'billing_city' => '', 'billing_postcode' => '', 'billing_country' => 'DE',
            'payment_method' => $method, 'total' => round($sum, 2), 'items' => $cart['items'],
        ]);
        if (!empty($previous['items'])) TIX_Native_Checkout::save_cart($previous); else TIX_Native_Checkout::clear_cart();
        if (!$oid) {
            $e = TIX_Native_Checkout::$last_error;
            if (is_wp_error($e) && $e->get_error_code() === 'tix_sold_out') return new WP_Error('tix_sold_out', $e->get_error_message(), ['status' => 409] + (array) $e->get_error_data());
            return is_wp_error($e) ? $e : new WP_Error('order_failed', 'Bestellung konnte nicht angelegt werden.', ['status' => 500]);
        }
        global $wpdb;
        // Kunde = Konto mit dieser E-Mail (nicht der Mitarbeiter, der die Bestellung anlegt); Zahlart-Titel
        $cu = get_user_by('email', $email);
        $titles = ['free' => 'Freikarte (von Hand)', 'paid' => 'Bezahlt beim Veranstalter (von Hand)', 'transfer' => 'Banküberweisung (Vorkasse)'];
        $wpdb->update($wpdb->prefix . 'tix_orders', ['customer_id' => $cu ? intval($cu->ID) : 0, 'payment_method_title' => $titles[$kind]], ['id' => $oid]);
        update_option('_tix_order_source_' . $oid, 'manual', false);
        TIX_Order_Admin::add_note($oid, '✍️ Bestellung von Hand (' . ['free' => 'Freikarte', 'paid' => 'bezahlt beim Veranstalter', 'transfer' => 'Vorkasse'][$kind] . ') durch ' . self::actor()
            . ($disc > 0 ? "\nRabatt: " . rtrim(rtrim(number_format($disc, 2, ',', ''), '0'), ',') . ' %' : '') . ($note !== '' ? "\n" . $note : ''), 'internal', self::actor());
        set_transient('_tix_skip_admin_email_' . $oid, 1, 300);
        if (!$notify) set_transient('_tix_skip_customer_email_' . $oid, 1, 300);
        if ($kind === 'transfer') {
            TIX_Gateway_Bank::process($oid); // on-hold; mit notify geht die Mail „Bestellung wird geprüft“ samt Bankdaten raus
        } else {
            TIX_Native_Checkout::update_order_status($oid, 'completed', 'admin');
        }
        if (!$notify) delete_transient('_tix_skip_customer_email_' . $oid);
        $r = self::row($oid);
        self::audit('order_manual', $oid, ['kind' => $kind, 'total' => round(floatval($r->total), 2), 'tickets' => array_sum($merged)], self::order_org($oid));
        return ['ok' => true, 'order_id' => intval($oid), 'order_number' => (string) $r->order_number, 'status' => (string) $r->status,
            'total' => round(floatval($r->total), 2), 'payment_method' => (string) $r->payment_method, 'tickets' => count(self::ticket_ids($oid))];
    }
}

TIX_Order_Manual::init();
