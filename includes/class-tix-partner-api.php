<?php
/**
 * Partner-API der Quellseite: Eine Plattform (z. B. evendis.de) verkauft Tickets für
 * geteilte Events dieser Seite. Bestellung, Zahlung (eigener Zahlungsanbieter, eigene
 * Preise/Gebühren), Tickets, Bestand und Einlass bleiben hier.
 *
 * Routen (tixomat/v1, Auth: X-Tix-Partner-Id = Hostname der Plattform + X-Tix-Partner-Key,
 * Ratenbegrenzung je Partner):
 *   POST /partner/quote               {event_id, items, coupon?} → Angebot wie /customer/cart/quote
 *   POST /partner/orders              {event_id, items, billing, payment_method, idempotency_token,
 *                                      partner_ref, coupon?} → Gastbestellung + Zahlungsseite
 *   GET  /partner/orders/{id}?key=    Status + Tickets (Code, Kategorie, Preis, Status, eingecheckt)
 *   POST /partner/orders/{id}/resend  Ticket-Mail erneut senden
 *
 * Webhooks an die Plattform (POST {syndication_api_url}/partner/webhook, HMAC mit dem
 * API Key der Event-Verteilung, Wiederholung per Cron): order.paid, order.cancelled,
 * order.refunded, ticket.checked_in, ticket.checkin_reset, ticket.transferred – nur für
 * Bestellungen mit Partner-Markierung (Option `_tix_order_partner_{id}`). Jeder Webhook
 * trägt den aktuellen Stand der ganzen Bestellung (Status + alle Tickets).
 *
 * Gesperrt, solange „Verkauf über die Plattform“ aus ist oder kein Partner-Schlüssel gesetzt ist.
 */
if (!defined('ABSPATH')) exit;

class TIX_Partner_API {

    const NS          = 'tixomat/v1';
    const QUEUE       = 'tix_partner_webhook_queue';
    const MAX_TRIES   = 12;
    const RATE_MAX    = 600;   // Anfragen je Partner …
    const RATE_WINDOW = 300;   // … pro 5 Minuten

    private static $flush_pending = false;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        // Partner-Markierung, sobald die Bestellung existiert (vor dem Zahlungsstart)
        add_action('tix_native_order_created', [__CLASS__, 'mark_order'], 1, 1);

        // Webhooks
        add_action('tix_order_completed', function ($id) { self::enqueue('order.paid', $id); }, 60);
        add_action('tix_order_cancelled', function ($id) { self::enqueue('order.cancelled', $id); }, 60);
        add_action('tix_order_status_changed', function ($id, $new) {
            if ($new === 'refunded') self::enqueue('order.refunded', $id);
        }, 60, 2);
        add_action('tix_ticket_checked_in', function ($tid) { self::enqueue_ticket('ticket.checked_in', $tid); }, 60);
        add_action('tix_ticket_checkin_reset', function ($tid) { self::enqueue_ticket('ticket.checkin_reset', $tid); }, 60);
        add_action('tix_ticket_transferred', function ($tid) { self::enqueue_ticket('ticket.transferred', $tid); }, 60);

        add_action('tix_partner_webhook_flush', [__CLASS__, 'flush']);
    }

    public static function register_routes() {
        $auth = [__CLASS__, 'check_auth'];
        register_rest_route(self::NS, '/partner/quote', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'quote'], 'permission_callback' => $auth,
        ]);
        register_rest_route(self::NS, '/partner/orders', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'create'], 'permission_callback' => $auth,
        ]);
        register_rest_route(self::NS, '/partner/orders/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'status'], 'permission_callback' => $auth,
        ]);
        register_rest_route(self::NS, '/partner/orders/(?P<id>\d+)/resend', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'resend'], 'permission_callback' => $auth,
        ]);
    }

    // ──────────────────────────────────────────
    //  Auth
    // ──────────────────────────────────────────

    public static function check_auth(WP_REST_Request $req) {
        if (!class_exists('TIX_Partners') || !TIX_Partners::source_api_enabled()) {
            return new WP_Error('tix_partner_disabled', 'Verkauf über Partner ist auf dieser Seite nicht freigeschaltet.', ['status' => 403]);
        }
        $id  = strtolower(trim((string) $req->get_header('X-Tix-Partner-Id')));
        $key = (string) $req->get_header('X-Tix-Partner-Key');
        $expected_key = (string) tix_get_settings('partner_api_key');
        if ($key === '' || !hash_equals($expected_key, $key) || $id !== TIX_Partners::source_platform_id()) {
            return new WP_Error('tix_partner_auth', 'Ungültige Partner-Zugangsdaten.', ['status' => 401]);
        }
        $rk = 'tix_partner_rl_' . md5($id);
        $rl = get_transient($rk);
        if (!is_array($rl) || time() - intval($rl['first']) >= self::RATE_WINDOW) $rl = ['count' => 0, 'first' => time()];
        $rl['count']++;
        set_transient($rk, $rl, self::RATE_WINDOW);
        if ($rl['count'] > self::RATE_MAX) {
            return new WP_Error('tix_rate_limit', 'Zu viele Anfragen des Partners. Bitte kurz warten.', ['status' => 429]);
        }
        // Server-zu-Server: nie als WordPress-Nutzer handeln (Gastbestellung)
        wp_set_current_user(0);
        return true;
    }

    // ──────────────────────────────────────────
    //  Hilfen
    // ──────────────────────────────────────────

    /** Event geteilt und für den Partner-Verkauf freigegeben? */
    private static function check_event($event_id) {
        $event_id = intval($event_id);
        if (!$event_id || get_post_type($event_id) !== 'event') {
            return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        }
        if (get_post_meta($event_id, '_tix_syndicate', true) !== '1' || get_post_meta($event_id, '_tix_partner_sales', true) === '0') {
            return new WP_Error('tix_partner_event', 'Für dieses Event ist der Verkauf über Partner nicht freigegeben.', ['status' => 403]);
        }
        return $event_id;
    }

    private static function legal() {
        return [
            'seller'         => (string) (tix_get_settings('syndication_site_name') ?: get_bloginfo('name')),
            'terms_url'      => (string) tix_get_settings('terms_url'),
            'privacy_url'    => (string) tix_get_settings('privacy_url'),
            'revocation_url' => (string) tix_get_settings('revocation_url'),
        ];
    }

    private static function sub_request($route, array $params) {
        $sub = new WP_REST_Request('POST', '/' . self::NS . $route);
        foreach ($params as $k => $v) $sub->set_param($k, $v);
        return $sub;
    }

    private static function data($resp) {
        if (is_wp_error($resp)) return $resp;
        $resp = rest_ensure_response($resp);
        return (array) $resp->get_data();
    }

    /** Partner-Markierung einer Bestellung: ['partner', 'ref', 'created'] oder null. */
    public static function marker($order_id) {
        $m = get_option('_tix_order_partner_' . intval($order_id));
        return is_array($m) && !empty($m['partner']) ? $m : null;
    }

    public static function mark_order($order_id) {
        if (!class_exists('TIX_App_Checkout') || empty(TIX_App_Checkout::$partner)) return;
        update_option('_tix_order_partner_' . intval($order_id), [
            'partner' => (string) TIX_App_Checkout::$partner['id'],
            'ref'     => (string) TIX_App_Checkout::$partner['ref'],
            'created' => time(),
        ], false);
    }

    private static function order_row($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", intval($order_id)));
    }

    /** Aktueller Stand einer Partner-Bestellung (Status + alle Tickets, auch stornierte). */
    public static function snapshot($order_id) {
        $o = self::order_row($order_id);
        if (!$o) return null;
        $m = self::marker($order_id);
        $tickets = [];
        $posts = get_posts([
            'post_type'      => 'tix_ticket',
            // „cancelled“ ist kein registrierter Status → explizit aufzählen
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'cancelled'],
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => '_tix_ticket_order_id',
            'meta_value'     => (string) intval($order_id),
        ]);
        foreach ($posts as $p) {
            $tid = intval($p->ID);
            $g = function ($k) use ($tid) { return get_post_meta($tid, $k, true); };
            $status = (string) ($g('_tix_ticket_status') ?: 'valid');
            if ($p->post_status === 'cancelled') $status = 'cancelled';
            $tickets[] = [
                'ticket_id'    => $tid,
                'code'         => (string) $g('_tix_ticket_code'),
                'category'     => (string) ($g('_tix_ticket_cat_name') ?: 'Ticket'),
                'cat_index'    => $g('_tix_ticket_cat_index') === '' ? null : intval($g('_tix_ticket_cat_index')),
                'price'        => round(floatval($g('_tix_ticket_price')), 2),
                'status'       => $status,
                'checked_in'   => (string) $g('_tix_ticket_checked_in') === '1' || $status === 'used',
                'checkin_time' => (string) $g('_tix_ticket_checkin_time'),
                'owner_name'   => (string) $g('_tix_ticket_owner_name'),
                'owner_email'  => (string) $g('_tix_ticket_owner_email'),
                'seat'         => (string) $g('_tix_ticket_seat_id'),
            ];
        }
        $status = (string) $o->status;
        return [
            'order' => [
                'id'                   => intval($o->id),
                'key'                  => (string) $o->order_key,
                'order_number'         => (string) $o->order_number,
                'status'               => $status,
                'paid'                 => in_array($status, ['completed', 'processing'], true),
                'total'                => round(floatval($o->total), 2),
                'currency'             => 'EUR',
                'payment_method'       => (string) $o->payment_method,
                'payment_method_title' => (string) $o->payment_method_title,
                'billing_email'        => (string) $o->billing_email,
                'created'              => (string) $o->date_created,
            ],
            'partner_ref' => $m ? (string) $m['ref'] : '',
            'tickets'     => $tickets,
        ];
    }

    /** Bestellung gehört zum anfragenden Partner und der Schlüssel passt? */
    private static function load_partner_order(WP_REST_Request $req, $need_key = true) {
        $id = intval($req['id']);
        $o  = self::order_row($id);
        $m  = self::marker($id);
        if (!$o || !$m || $m['partner'] !== TIX_Partners::source_platform_id()) {
            return new WP_Error('tix_order_missing', 'Bestellung nicht gefunden.', ['status' => 404]);
        }
        if ($need_key) {
            $key = sanitize_text_field((string) $req->get_param('key'));
            if ($key === '' || !hash_equals((string) $o->order_key, $key)) {
                return new WP_Error('tix_order_missing', 'Bestellung nicht gefunden.', ['status' => 404]);
            }
        }
        return $id;
    }

    // ──────────────────────────────────────────
    //  Endpunkte
    // ──────────────────────────────────────────

    /** POST /partner/quote */
    public static function quote(WP_REST_Request $req) {
        $event_id = self::check_event($req->get_param('event_id'));
        if (is_wp_error($event_id)) return $event_id;
        TIX_App_Checkout::$partner = ['id' => TIX_Partners::source_platform_id(), 'ref' => ''];
        try {
            $data = self::data(TIX_App_Checkout::quote(self::sub_request('/customer/cart/quote', [
                'event_id' => $event_id,
                'items'    => is_array($req->get_param('items')) ? $req->get_param('items') : [],
                'coupon'   => (string) ($req->get_param('coupon') ?? ''),
            ])));
        } finally {
            TIX_App_Checkout::$partner = null;
        }
        if (is_wp_error($data)) return $data;
        $data['legal'] = self::legal();
        return rest_ensure_response($data);
    }

    /** POST /partner/orders */
    public static function create(WP_REST_Request $req) {
        $event_id = self::check_event($req->get_param('event_id'));
        if (is_wp_error($event_id)) return $event_id;
        $ref   = sanitize_text_field((string) $req->get_param('partner_ref'));
        $token = sanitize_text_field((string) $req->get_param('idempotency_token'));
        $billing = $req->get_param('billing');
        if ($ref === '' || strlen($token) < 8 || !is_array($billing) || !is_email($billing['email'] ?? '')) {
            return new WP_Error('tix_partner_request', 'partner_ref, idempotency_token und billing.email sind Pflicht.', ['status' => 400]);
        }
        TIX_App_Checkout::$partner = ['id' => TIX_Partners::source_platform_id(), 'ref' => $ref];
        try {
            $data = self::data(TIX_App_Checkout::create(self::sub_request('/customer/orders', [
                'event_id'          => $event_id,
                'items'             => is_array($req->get_param('items')) ? $req->get_param('items') : [],
                'coupon'            => (string) ($req->get_param('coupon') ?? ''),
                'billing'           => $billing,
                'payment_method'    => (string) ($req->get_param('payment_method') ?? ''),
                'idempotency_token' => $token,
                'accept_terms'      => 1, // Zustimmung holt die Plattform in ihrer Kasse ein
            ])));
        } finally {
            TIX_App_Checkout::$partner = null;
        }
        if (is_wp_error($data)) return $data;
        $order_id = intval($data['order']['id'] ?? 0);
        $snap = $order_id ? self::snapshot($order_id) : null;
        if (!$snap) return new WP_Error('tix_order_failed', 'Bestellung konnte nicht erstellt werden.', ['status' => 500]);
        $snap['payment_url'] = (string) ($data['order']['payment_url'] ?? '');
        $snap['legal'] = self::legal();
        return rest_ensure_response(['ok' => true] + $snap);
    }

    /** GET /partner/orders/{id}?key= */
    public static function status(WP_REST_Request $req) {
        $id = self::load_partner_order($req);
        if (is_wp_error($id)) return $id;
        return rest_ensure_response(['ok' => true] + self::snapshot($id));
    }

    /** POST /partner/orders/{id}/resend {key} */
    public static function resend(WP_REST_Request $req) {
        $id = self::load_partner_order($req);
        if (is_wp_error($id)) return $id;
        $snap = self::snapshot($id);
        if (empty($snap['order']['paid'])) {
            return new WP_Error('tix_order_unpaid', 'Die Bestellung ist noch nicht bezahlt.', ['status' => 409]);
        }
        if (!class_exists('TIX_Emails') || !method_exists('TIX_Emails', 'send_native_completed')) {
            return new WP_Error('tix_mail', 'E-Mail-Versand nicht verfügbar.', ['status' => 500]);
        }
        TIX_Emails::send_native_completed($id);
        return rest_ensure_response(['ok' => true, 'sent_to' => $snap['order']['billing_email']]);
    }

    // ──────────────────────────────────────────
    //  Webhooks (Warteschlange + Versand)
    // ──────────────────────────────────────────

    private static function webhook_target() {
        $url = (string) tix_get_settings('syndication_api_url');
        $key = (string) tix_get_settings('syndication_api_key');
        if ($url === '' || strlen($key) < 20) return null;
        return ['url' => untrailingslashit($url) . '/partner/webhook', 'key' => $key];
    }

    public static function enqueue($type, $order_id, $ticket_id = 0) {
        $order_id = intval($order_id);
        if (!$order_id || !self::marker($order_id) || !self::webhook_target()) return;
        $q = get_option(self::QUEUE, []);
        if (!is_array($q)) $q = [];
        $q[] = [
            'id'        => wp_generate_uuid4(),
            'type'      => (string) $type,
            'order_id'  => $order_id,
            'ticket_id' => intval($ticket_id),
            'tries'     => 0,
            'next'      => time(),
            'created'   => time(),
        ];
        update_option(self::QUEUE, array_slice($q, -500), false);
        if (!self::$flush_pending) {
            self::$flush_pending = true;
            if (!wp_next_scheduled('tix_partner_webhook_flush')) {
                wp_schedule_single_event(time(), 'tix_partner_webhook_flush');
            }
            // Sofort im Hintergrund senden (Einlass soll nicht warten)
            add_action('shutdown', function () { if (function_exists('spawn_cron')) spawn_cron(); });
        }
    }

    public static function enqueue_ticket($type, $ticket_id) {
        $order_id = intval(get_post_meta(intval($ticket_id), '_tix_ticket_order_id', true));
        if ($order_id) self::enqueue($type, $order_id, $ticket_id);
    }

    /** Fällige Webhooks senden (Cron). Fehlversuche mit wachsender Pause, max. 12 Versuche. */
    public static function flush() {
        if (get_transient('tix_partner_wh_lock')) return;
        set_transient('tix_partner_wh_lock', 1, 55);
        $target = self::webhook_target();
        $q = get_option(self::QUEUE, []);
        if (!is_array($q) || empty($q) || !$target) {
            delete_transient('tix_partner_wh_lock');
            return;
        }
        $done = [];
        $retry = [];
        $sent = 0;
        foreach ($q as $job) {
            if ($sent >= 25 || intval($job['next']) > time()) continue;
            $sent++;
            $snap = self::snapshot($job['order_id']);
            if (!$snap) { $done[] = $job['id']; continue; }
            $body = wp_json_encode([
                'id'        => $job['id'],
                'type'      => $job['type'],
                'ticket_id' => intval($job['ticket_id']),
                'created'   => intval($job['created']),
                'sent'      => time(),
            ] + $snap);
            $resp = wp_remote_post($target['url'], [
                'timeout' => 10,
                'headers' => [
                    'Content-Type'            => 'application/json',
                    'X-Tix-Partner-Kid'       => TIX_Partners::kid($target['key']),
                    'X-Tix-Partner-Signature' => TIX_Partners::sign($body, $target['key']),
                ],
                'body'    => $body,
            ]);
            $code = is_wp_error($resp) ? 0 : intval(wp_remote_retrieve_response_code($resp));
            if ($code >= 200 && $code < 300) {
                $done[] = $job['id'];
            } else {
                $tries = intval($job['tries']) + 1;
                if ($tries >= self::MAX_TRIES) {
                    error_log('[TIX Partner] Webhook ' . $job['type'] . ' für Bestellung #' . $job['order_id'] . ' nach ' . $tries . ' Versuchen verworfen (HTTP ' . $code . ').');
                    $done[] = $job['id'];
                } else {
                    $retry[$job['id']] = ['tries' => $tries, 'next' => time() + min(3600, 60 * (2 ** ($tries - 1)))];
                }
            }
        }
        // Neu eingereihte Jobs während des Sendens nicht verlieren: frisch lesen und zusammenführen
        wp_cache_delete(self::QUEUE, 'options');
        $now = get_option(self::QUEUE, []);
        $out = [];
        foreach ((array) $now as $job) {
            if (in_array($job['id'], $done, true)) continue;
            if (isset($retry[$job['id']])) $job = array_merge($job, $retry[$job['id']]);
            $out[] = $job;
        }
        update_option(self::QUEUE, $out, false);
        delete_transient('tix_partner_wh_lock');
        if (!empty($out)) {
            $next = min(array_map(function ($j) { return intval($j['next']); }, $out));
            if (!wp_next_scheduled('tix_partner_webhook_flush')) {
                wp_schedule_single_event(max(time() + 30, $next), 'tix_partner_webhook_flush');
            }
        }
    }
}
