<?php
/**
 * App-Kasse: native Ticketbestellung für die KitchenKlub-App über REST.
 *
 * Nutzt denselben Ablauf wie der native Web-Checkout (TIX_Native_Checkout):
 * gleiche Preislogik (Dynamic Pricing), Gebühren (TIX_Fees), Steuer,
 * Gutscheine und Gateways. Die Zahlung selbst läuft über die gehostete
 * Zahlungsseite des Gateways (Mollie/Stripe/PayPal), die die App im
 * In-App-Sheet öffnet; Free-Bestellungen werden sofort abgeschlossen.
 *
 * Routen (Namespace tixomat/v1, Kunden-Token `X-Tix-Token`):
 *   POST /customer/cart/quote        Kategorien + Preise/Gebühren/Steuer/Zahlarten
 *   POST /customer/orders            Bestellung anlegen und Zahlung starten
 *   GET  /customer/orders/{id}       Status + Tickets einer eigenen Bestellung
 *
 * Die Rechnungsadresse wird wie im Web-Formular abgefragt (Vor-/Nachname, Firma
 * optional, Straße, PLZ, Ort, Land, Telefon optional); die E-Mail kommt immer
 * aus dem angemeldeten Konto. Die Adresse wird am Konto gemerkt (billing_*).
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Checkout {

    const NS        = 'tixomat/v1';
    const TOKEN_TTL = 6 * HOUR_IN_SECONDS;
    const MAX_QTY   = 20;

    /** Länder wie im Web-Checkout (Reihenfolge = Anzeige) */
    const COUNTRIES = [
        'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'NL' => 'Niederlande',
        'BE' => 'Belgien', 'LU' => 'Luxemburg', 'FR' => 'Frankreich', 'PL' => 'Polen',
        'DK' => 'Dänemark', 'CZ' => 'Tschechien', 'IT' => 'Italien', 'ES' => 'Spanien',
        'GB' => 'Vereinigtes Königreich',
    ];

    /** Rechnungsdaten zum Vorbelegen: Konto + zuletzt genutzte Adresse (User-Meta billing_*) */
    private static function billing_prefill($user) {
        $first = trim((string) $user->first_name);
        $last  = trim((string) $user->last_name);
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim((string) $user->display_name));
            $first = array_shift($parts) ?: '';
            $last  = trim(implode(' ', $parts));
        }
        $m = function ($key) use ($user) { return (string) get_user_meta($user->ID, $key, true); };
        $country = strtoupper($m('billing_country'));
        return [
            'first_name' => $m('billing_first_name') ?: $first,
            'last_name'  => $m('billing_last_name') ?: $last,
            'company'    => $m('billing_company'),
            'address_1'  => $m('billing_address_1'),
            'postcode'   => $m('billing_postcode'),
            'city'       => $m('billing_city'),
            'country'    => isset(self::COUNTRIES[$country]) ? $country : 'DE',
            'phone'      => $m('billing_phone'),
            'email'      => (string) $user->user_email,
        ];
    }

    /**
     * Rechnungsdaten aus dem Request prüfen (Pflichtfelder wie im Web-Formular:
     * Vor-/Nachname, Straße, PLZ, Ort, Land). E-Mail kommt immer aus dem Konto.
     */
    private static function read_billing(WP_REST_Request $req, $user) {
        $in = $req->get_param('billing');
        if (!is_array($in)) $in = [];
        $get = function ($key, $max = 120) use ($in, $req) {
            $v = $in[$key] ?? $req->get_param('billing_' . $key);
            return mb_substr(trim(sanitize_text_field((string) ($v ?? ''))), 0, $max);
        };
        $b = [
            'first_name' => $get('first_name', 60),
            'last_name'  => $get('last_name', 60),
            'company'    => $get('company', 120),
            'address_1'  => $get('address_1', 160),
            'postcode'   => $get('postcode', 16),
            'city'       => $get('city', 80),
            'country'    => strtoupper($get('country', 2)),
            'phone'      => $get('phone', 40),
            'email'      => (string) $user->user_email,
        ];
        $required = [
            'first_name' => 'Vorname', 'last_name' => 'Nachname', 'address_1' => 'Straße und Hausnummer',
            'postcode' => 'PLZ', 'city' => 'Ort',
        ];
        foreach ($required as $key => $label) {
            if ($b[$key] === '') {
                return new WP_Error('tix_billing', 'Bitte ' . $label . ' angeben.', ['status' => 400, 'field' => $key]);
            }
        }
        if (!isset(self::COUNTRIES[$b['country']])) {
            return new WP_Error('tix_billing', 'Bitte ein Land wählen.', ['status' => 400, 'field' => 'country']);
        }
        return $b;
    }

    /** Adresse am Konto merken (Vorbelegung beim nächsten Kauf, wie WooCommerce-Felder). */
    private static function remember_billing($user, array $b) {
        foreach (['first_name', 'last_name', 'company', 'address_1', 'postcode', 'city', 'country', 'phone'] as $k) {
            update_user_meta($user->ID, 'billing_' . $k, $b[$k]);
        }
        if (trim((string) $user->first_name) === '' && trim((string) $user->last_name) === '') {
            wp_update_user(['ID' => $user->ID, 'first_name' => $b['first_name'], 'last_name' => $b['last_name']]);
        }
    }

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/customer/cart/quote', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'quote'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
        register_rest_route(self::NS, '/customer/orders', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
        register_rest_route(self::NS, '/customer/orders/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'status'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
    }

    public static function check_customer(WP_REST_Request $req) {
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'check_authenticated')) {
            return TIX_REST_API::check_authenticated($req);
        }
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        }
        return true;
    }

    // ──────────────────────────────────────────
    //  Hilfen
    // ──────────────────────────────────────────

    private static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    /** Online-Verkauf für das Event möglich? (wie der Ticket-Selector der Event-Seite) */
    private static function sale_open($event_id) {
        if (get_post_status($event_id) !== 'publish') return false;
        if (get_post_meta($event_id, '_tix_tickets_enabled', true) !== '1') return false;
        $status = get_post_meta($event_id, '_tix_status', true);
        return !in_array($status, ['cancelled', 'postponed', 'past', 'sold_out', 'presale_closed'], true);
    }

    private static function is_public_category(array $cat) {
        if (!empty($cat['hidden']) || !empty($cat['admin_only'])) return false;
        if (isset($cat['online']) && (string) $cat['online'] === '0') return false;
        if (!empty($cat['offline_ticket'])) return false;
        return true;
    }

    private static function price_for($event_id, $index, array $cat) {
        if (class_exists('TIX_Dynamic_Pricing')) {
            $dyn = TIX_Dynamic_Pricing::get_dynamic_price(intval($event_id), intval($index));
            if ($dyn !== null) return floatval($dyn);
        }
        return floatval($cat['price'] ?? 0);
    }

    /** Öffentliche Kategorien eines Events im Format der App. */
    private static function categories($event_id) {
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) return [];
        $out = [];
        foreach ($cats as $i => $cat) {
            if (!is_array($cat) || !self::is_public_category($cat)) continue;
            $price = self::price_for($event_id, $i, $cat);
            $base  = floatval($cat['price'] ?? 0);
            $stock = isset($cat['stock']) ? intval($cat['stock']) : -1; // -1 = unbegrenzt
            $out[] = [
                'index'              => intval($i),
                'name'               => (string) ($cat['name'] ?? 'Ticket'),
                'price'              => round($price, 2),
                'base_price'         => round($base, 2),
                'description'        => (string) ($cat['desc'] ?? ($cat['description'] ?? '')),
                'quantity_available' => $stock,
                'sold_out'           => $stock === 0,
                'phase_label'        => ($price < $base) ? 'Aktionspreis' : '',
                'max_per_order'      => $stock >= 0 ? min(self::MAX_QTY, $stock) : self::MAX_QTY,
            ];
        }
        return $out;
    }

    /** Warenkorb im Format des Web-Checkouts aus der Auswahl der App bauen. */
    private static function build_cart($event_id, array $items) {
        if (!self::sale_open($event_id)) {
            return self::error('tix_sale_closed', 'Für dieses Event ist aktuell kein Online-Verkauf möglich.');
        }
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || empty($cats)) {
            return self::error('tix_no_tickets', 'Für dieses Event gibt es keine Online-Tickets.');
        }
        $event_title = get_the_title($event_id);
        $cart = ['items' => [], 'coupon' => null];
        $merged = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $idx = intval($it['index'] ?? -1);
            $qty = intval($it['qty'] ?? ($it['quantity'] ?? 0));
            if ($qty <= 0) continue;
            $merged[$idx] = ($merged[$idx] ?? 0) + $qty;
        }
        foreach ($merged as $idx => $qty) {
            if (!isset($cats[$idx]) || !is_array($cats[$idx]) || !self::is_public_category($cats[$idx])) {
                return self::error('tix_category', 'Diese Ticket-Kategorie ist nicht verfügbar.');
            }
            $cat   = $cats[$idx];
            $name  = sanitize_text_field($cat['name'] ?? 'Ticket');
            $stock = isset($cat['stock']) ? intval($cat['stock']) : -1;
            if ($stock === 0) {
                return self::error('tix_sold_out', $name . ' ist ausverkauft.');
            }
            if ($stock > 0 && $qty > $stock) {
                return self::error('tix_stock', sprintf('%s: nur noch %d verfügbar.', $name, $stock));
            }
            if ($qty > self::MAX_QTY) {
                return self::error('tix_max_qty', sprintf('Maximal %d Tickets pro Kategorie und Bestellung.', self::MAX_QTY));
            }
            $cart['items'][] = [
                'event_id'    => intval($event_id),
                'cat_index'   => intval($idx),
                'name'        => $name,
                'event_title' => $event_title,
                'price'       => self::price_for($event_id, $idx, $cat),
                'qty'         => $qty,
                'meta'        => [],
            ];
        }
        if (empty($cart['items'])) {
            return self::error('tix_empty', 'Bitte mindestens ein Ticket wählen.');
        }
        return $cart;
    }

    /** Gutschein (manuell oder Auto-Apply) anwenden – gleiche Logik wie im Web. */
    private static function apply_coupon(array $cart, $code) {
        $code = sanitize_text_field((string) $code);
        if (method_exists('TIX_Native_Checkout', 'app_prepare_cart')) {
            return TIX_Native_Checkout::app_prepare_cart($cart, $code);
        }
        return $cart;
    }

    /** Summen wie in TIX_Native_Checkout::create_order (Rabatt → Gebühr → Steuer). */
    private static function totals(array $cart) {
        $lines = [];
        $subtotal = 0.0;
        $count = 0;
        foreach ($cart['items'] as $it) {
            $line = round(floatval($it['price']) * intval($it['qty']), 2);
            $subtotal += $line;
            $count    += intval($it['qty']);
            $lines[] = [
                'index'      => intval($it['cat_index']),
                'name'       => (string) $it['name'],
                'qty'        => intval($it['qty']),
                'unit_price' => round(floatval($it['price']), 2),
                'total'      => $line,
            ];
        }
        $subtotal = round($subtotal, 2);
        $discount = !empty($cart['coupon']['discount']) ? round(floatval($cart['coupon']['discount']), 2) : 0.0;
        $total    = max(0, round($subtotal - $discount, 2));

        $fee = 0.0;
        $fee_label = '';
        if (class_exists('TIX_Fees')) {
            $fd  = TIX_Fees::calc_order_fees($cart['items']);
            $fee = round(floatval($fd['customer_fee_line'] ?? 0), 2);
            $fee_label = (string) ($fd['fee_label'] ?? 'Servicegebühr');
            if ($fee > 0) $total = round($total + $fee, 2);
        }

        $s = tix_get_settings();
        $tax = 0.0;
        $tax_rate = floatval($s['tax_rate'] ?? 0);
        $tax_inclusive = !empty($s['tax_inclusive']);
        if (!empty($s['tax_enabled']) && $tax_rate > 0) {
            if ($tax_inclusive) {
                $tax = round($total - ($total / (1 + $tax_rate / 100)), 2);
            } else {
                $tax   = round($total * $tax_rate / 100, 2);
                $total = round($total + $tax, 2);
            }
        }

        return [
            'lines'         => $lines,
            'count'         => $count,
            'subtotal'      => $subtotal,
            'discount'      => $discount,
            'fee'           => $fee,
            'fee_label'     => $fee_label,
            'tax'           => $tax,
            'tax_rate'      => $tax_rate,
            'tax_inclusive' => $tax_inclusive,
            'total'         => $total,
            'currency'      => 'EUR',
        ];
    }

    /** Zahlarten wie im Web-Checkout (Reihenfolge und Filter aus den Einstellungen). */
    private static function payment_methods($is_free) {
        if ($is_free) {
            return [['id' => 'free', 'title' => 'Kostenlos', 'icon' => '']];
        }
        $by_provider = ['mollie' => [], 'stripe' => [], 'paypal' => []];

        $mollie_enabled = (bool) (tix_get_settings('mollie_enabled') ?? 1);
        if ($mollie_enabled && class_exists('TIX_Gateway_Mollie') && TIX_Gateway_Mollie::is_available()) {
            $methods = TIX_Gateway_Mollie::get_methods();
            $allow = tix_get_settings('mollie_enabled_methods');
            if (is_array($allow) && !empty($allow)) {
                $methods = array_values(array_filter($methods, function ($m) use ($allow) {
                    return in_array($m['id'], $allow, true);
                }));
            }
            $order = array_filter(array_map('trim', explode(',', (string) tix_get_settings('mollie_methods_order'))));
            if (!empty($order) && class_exists('TIX_Settings') && method_exists('TIX_Settings', 'sort_methods_by_order')) {
                $methods = TIX_Settings::sort_methods_by_order($methods, $order);
            }
            if (empty($methods)) {
                $by_provider['mollie'][] = ['id' => 'mollie', 'title' => TIX_Gateway_Mollie::get_title(), 'icon' => TIX_Gateway_Mollie::get_icon()];
            } else {
                foreach ($methods as $m) {
                    if (in_array($m['id'], ['billie'], true)) continue; // nur B2B
                    $by_provider['mollie'][] = [
                        'id'    => 'mollie:' . $m['id'],
                        'title' => $m['description'],
                        'icon'  => $m['image'] ?: TIX_Gateway_Mollie::get_icon(),
                    ];
                }
            }
        }

        if (class_exists('TIX_Gateway_Stripe') && TIX_Gateway_Stripe::is_available()) {
            $methods = TIX_Gateway_Stripe::get_methods();
            $allow = tix_get_settings('stripe_enabled_methods');
            if (is_array($allow) && !empty($allow)) {
                $methods = array_values(array_filter($methods, function ($m) use ($allow) {
                    return in_array($m['id'], $allow, true);
                }));
            }
            $order = array_filter(array_map('trim', explode(',', (string) tix_get_settings('stripe_methods_order'))));
            if (!empty($order) && class_exists('TIX_Settings') && method_exists('TIX_Settings', 'sort_methods_by_order')) {
                $methods = TIX_Settings::sort_methods_by_order($methods, $order);
            }
            foreach ($methods as $m) {
                $by_provider['stripe'][] = [
                    'id'    => 'stripe:' . $m['id'],
                    'title' => $m['label'] ?? ($m['id'] ?? 'Stripe'),
                    'icon'  => $m['image'] ?? '',
                ];
            }
        }

        if (class_exists('TIX_Gateway_PayPal') && TIX_Gateway_PayPal::is_available()) {
            $by_provider['paypal'][] = ['id' => 'paypal', 'title' => TIX_Gateway_PayPal::get_title(), 'icon' => TIX_Gateway_PayPal::get_icon()];
        }

        $gateways = [];
        $provider_order = array_filter(array_map('trim', explode(',', (string) (tix_get_settings('gateway_provider_order') ?: 'stripe,mollie,paypal,bank'))));
        foreach ($provider_order as $p) {
            if (!empty($by_provider[$p])) {
                $gateways = array_merge($gateways, $by_provider[$p]);
                unset($by_provider[$p]);
            }
        }
        foreach ($by_provider as $rest) {
            if (!empty($rest)) $gateways = array_merge($gateways, $rest);
        }
        return $gateways;
    }

    /** Einfaches Rate-Limit pro Nutzer (REST-tauglich, liefert WP_Error). */
    private static function rate_limited($bucket, $max, $window) {
        $key  = 'tix_app_rl_' . $bucket . '_' . get_current_user_id();
        $data = get_transient($key);
        if (!is_array($data) || time() - intval($data['first']) >= $window) {
            $data = ['count' => 0, 'first' => time()];
        }
        $data['count']++;
        set_transient($key, $data, $window);
        return $data['count'] > $max;
    }

    private static function order_row($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", intval($order_id)
        ));
    }

    /** Tickets einer Bestellung im Format von GET /customer/tickets. */
    private static function order_tickets($order_id) {
        global $wpdb;
        $tickets = [];
        $table = $wpdb->prefix . 'tix_tickets';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", intval($order_id)
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                $event_id = intval($row['event_id'] ?? 0);
                $event    = $event_id ? get_post($event_id) : null;
                $tickets[] = [
                    'id'           => intval($row['id']),
                    'code'         => $row['ticket_code'] ?? '',
                    'event_id'     => $event_id,
                    'event_title'  => $event ? $event->post_title : ($row['event_name'] ?? ''),
                    'event_date'   => $event_id ? get_post_meta($event_id, '_tix_date_start', true) : '',
                    'event_time'   => $event_id ? get_post_meta($event_id, '_tix_time_start', true) : '',
                    'event_image'  => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
                    'category'     => $row['category_name'] ?? '',
                    'seat'         => $row['seat_id'] ?? '',
                    'status'       => $row['ticket_status'] ?? 'valid',
                    'checked_in'   => !empty($row['checked_in']),
                    'checkin_time' => $row['checkin_time'] ?? '',
                    'order_id'     => intval($row['order_id'] ?? 0),
                    'price'        => floatval($row['ticket_price'] ?? 0),
                    'purchased'    => $row['created_at'] ?? '',
                ];
            }
        }
        if (empty($tickets)) {
            $posts = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'meta_query'     => [
                    'relation' => 'OR',
                    ['key' => '_tix_ticket_order_id', 'value' => (string) intval($order_id)],
                    ['key' => '_tix_order_id',        'value' => (string) intval($order_id)],
                ],
            ]);
            foreach ($posts as $tp) {
                $event_id = intval(get_post_meta($tp->ID, '_tix_event_id', true));
                $event    = $event_id ? get_post($event_id) : null;
                $tickets[] = [
                    'id'           => $tp->ID,
                    'code'         => get_post_meta($tp->ID, '_tix_ticket_code', true),
                    'event_id'     => $event_id,
                    'event_title'  => $event ? $event->post_title : '',
                    'event_date'   => $event_id ? get_post_meta($event_id, '_tix_date_start', true) : '',
                    'event_time'   => $event_id ? get_post_meta($event_id, '_tix_time_start', true) : '',
                    'event_image'  => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
                    'category'     => get_post_meta($tp->ID, '_tix_ticket_category', true) ?: 'Ticket',
                    'seat'         => get_post_meta($tp->ID, '_tix_seat', true),
                    'status'       => get_post_meta($tp->ID, '_tix_status', true) ?: 'valid',
                    'checked_in'   => (bool) get_post_meta($tp->ID, '_tix_checked_in', true),
                    'checkin_time' => get_post_meta($tp->ID, '_tix_checkin_time', true) ?: '',
                    'order_id'     => intval($order_id),
                    'price'        => floatval(get_post_meta($tp->ID, '_tix_ticket_price', true)),
                    'purchased'    => $tp->post_date,
                ];
            }
        }
        return $tickets;
    }

    private static function order_response($order_id, $payment_url = '') {
        $o = self::order_row($order_id);
        if (!$o) return self::error('tix_order_missing', 'Bestellung nicht gefunden.', 404);
        $status = (string) $o->status;
        $paid   = in_array($status, ['completed', 'processing'], true);
        return rest_ensure_response([
            'ok'    => true,
            'order' => [
                'id'                   => intval($o->id),
                'order_number'         => (string) $o->order_number,
                'status'               => $status,
                'paid'                 => $paid,
                'total'                => round(floatval($o->total), 2),
                'currency'             => 'EUR',
                'payment_method'       => (string) $o->payment_method,
                'payment_method_title' => (string) $o->payment_method_title,
                'payment_url'          => (string) $payment_url,
                'created'              => (string) $o->date_created,
                'tickets'              => $paid ? self::order_tickets($order_id) : [],
            ],
        ]);
    }

    // ──────────────────────────────────────────
    //  Endpunkte
    // ──────────────────────────────────────────

    /** POST /customer/cart/quote */
    public static function quote(WP_REST_Request $req) {
        $event_id = intval($req->get_param('event_id'));
        if (!$event_id || get_post_type($event_id) !== 'event') {
            return self::error('tix_event', 'Event nicht gefunden.', 404);
        }
        $items  = $req->get_param('items');
        $items  = is_array($items) ? $items : [];
        $coupon = (string) ($req->get_param('coupon') ?? '');

        $user = wp_get_current_user();
        $countries = [];
        foreach (self::COUNTRIES as $code => $name) $countries[] = ['code' => $code, 'name' => $name];
        $resp = [
            'ok'              => true,
            'event_id'        => $event_id,
            'sale_open'       => self::sale_open($event_id),
            'categories'      => self::categories($event_id),
            'totals'          => null,
            'payment_methods' => [],
            'coupon'          => null,
            'billing'         => self::billing_prefill($user),
            'fields'          => [
                'company'   => !empty(tix_get_settings('show_company_field')),
                'countries' => $countries,
            ],
        ];
        if (!empty($items)) {
            $cart = self::build_cart($event_id, $items);
            if (is_wp_error($cart)) return $cart;
            $cart = self::apply_coupon($cart, $coupon);
            $t    = self::totals($cart);
            $resp['totals']          = $t;
            $resp['payment_methods'] = self::payment_methods($t['total'] <= 0);
            $resp['coupon'] = [
                'code'     => (string) ($cart['coupon']['code'] ?? $coupon),
                'valid'    => !empty($cart['coupon']['discount']),
                'discount' => $t['discount'],
            ];
        }
        return rest_ensure_response($resp);
    }

    /** POST /customer/orders */
    public static function create(WP_REST_Request $req) {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) return self::error('rest_not_logged_in', 'Authentifizierung erforderlich.', 401);
        if (self::rate_limited('orders', 10, 300)) {
            return self::error('tix_rate_limit', 'Zu viele Bestellversuche. Bitte kurz warten.', 429);
        }

        $token = sanitize_text_field((string) $req->get_param('idempotency_token'));
        if (strlen($token) < 8) return self::error('tix_token', 'idempotency_token fehlt.');
        $tkey = 'tix_app_order_' . md5($user->ID . '|' . $token);
        $existing = get_transient($tkey);
        if (is_array($existing) && !empty($existing['order_id'])) {
            return self::order_response(intval($existing['order_id']), (string) ($existing['payment_url'] ?? ''));
        }

        if (empty($req->get_param('accept_terms'))) {
            return self::error('tix_terms', 'Bitte AGB und Widerrufsbelehrung akzeptieren.');
        }

        $event_id = intval($req->get_param('event_id'));
        if (!$event_id || get_post_type($event_id) !== 'event') return self::error('tix_event', 'Event nicht gefunden.', 404);
        $items = $req->get_param('items');
        $cart  = self::build_cart($event_id, is_array($items) ? $items : []);
        if (is_wp_error($cart)) return $cart;
        $cart = self::apply_coupon($cart, (string) ($req->get_param('coupon') ?? ''));
        $t    = self::totals($cart);

        // Zahlart prüfen
        $payment_method = sanitize_text_field((string) ($req->get_param('payment_method') ?? ''));
        $mollie_method  = '';
        $stripe_method  = '';
        if ($t['total'] <= 0) {
            $payment_method = 'free';
        } else {
            $allowed = array_column(self::payment_methods(false), 'id');
            if (!in_array($payment_method, $allowed, true)) {
                return self::error('tix_payment_method', 'Bitte eine gültige Zahlungsart wählen.');
            }
            if (strpos($payment_method, 'mollie:') === 0) {
                $mollie_method  = substr($payment_method, 7);
                $payment_method = 'mollie';
            } elseif (strpos($payment_method, 'stripe:') === 0) {
                $stripe_method  = substr($payment_method, 7);
                $payment_method = 'stripe';
            }
        }

        // Rechnungsadresse wie im Web-Formular (E-Mail immer aus dem Konto)
        $billing = self::read_billing($req, $user);
        if (is_wp_error($billing)) return $billing;
        self::remember_billing($user, $billing);

        // Warenkorb in die Nutzer-Session legen: create_order liest Gutschein und Gebühren daraus
        $previous_cart = TIX_Native_Checkout::get_cart();
        TIX_Native_Checkout::save_cart($cart);
        $order_id = TIX_Native_Checkout::create_order([
            'billing_first_name' => $billing['first_name'],
            'billing_last_name'  => $billing['last_name'],
            'billing_email'      => $billing['email'],
            'billing_phone'      => $billing['phone'],
            'billing_company'    => $billing['company'],
            'billing_address_1'  => $billing['address_1'],
            'billing_city'       => $billing['city'],
            'billing_postcode'   => $billing['postcode'],
            'billing_country'    => $billing['country'],
            'payment_method'     => $payment_method,
            'total'              => $t['subtotal'],
            'items'              => $cart['items'],
        ]);
        // Web-Warenkorb des Nutzers unangetastet lassen
        if (!empty($previous_cart['items'])) {
            TIX_Native_Checkout::save_cart($previous_cart);
        } else {
            TIX_Native_Checkout::clear_cart();
        }
        if (!$order_id) return self::error('tix_order_failed', 'Bestellung konnte nicht erstellt werden.', 500);
        update_option('_tix_order_source_' . $order_id, 'app', false);

        // Zahlung starten
        if ($payment_method === 'free') {
            $result = TIX_Gateway_Free::process($order_id);
        } elseif ($payment_method === 'mollie') {
            $result = TIX_Gateway_Mollie::process($order_id, $mollie_method);
        } elseif ($payment_method === 'stripe') {
            $result = TIX_Gateway_Stripe::process($order_id, $stripe_method);
        } elseif ($payment_method === 'paypal') {
            $result = TIX_Gateway_PayPal::process($order_id);
        } else {
            $result = ['error' => 'Unbekannte Zahlungsart.'];
        }
        if (isset($result['error'])) {
            TIX_Native_Checkout::update_order_status($order_id, 'failed', 'app');
            return self::error('tix_payment_failed', (string) $result['error'], 502);
        }
        $payment_url = (string) ($result['redirect'] ?? '');
        set_transient($tkey, ['order_id' => $order_id, 'payment_url' => $payment_url], self::TOKEN_TTL);
        return self::order_response($order_id, $payment_url);
    }

    /** GET /customer/orders/{id} – nur eigene Bestellungen */
    public static function status(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $id   = intval($req['id']);
        $o    = self::order_row($id);
        if (!$o) return self::error('tix_order_missing', 'Bestellung nicht gefunden.', 404);
        $mine = (intval($o->customer_id) > 0 && intval($o->customer_id) === intval($user->ID))
             || strcasecmp((string) $o->billing_email, (string) $user->user_email) === 0;
        if (!$mine) return self::error('rest_forbidden', 'Keine Berechtigung.', 403);
        return self::order_response($id, '');
    }
}
