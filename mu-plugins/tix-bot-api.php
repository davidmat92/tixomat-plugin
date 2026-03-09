<?php
/**
 * Plugin Name: Tixomat Bot – REST API
 * Description: REST-Endpunkte fuer den Tixomat-Chatbot (Events, Ticket-Lookup, Checkout).
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) exit;

// ═══════════════════════════════════════════════════
//  KONFIGURATION
// ═══════════════════════════════════════════════════

define('TIX_BOT_SECRET', defined('TIX_BOT_API_SECRET') ? TIX_BOT_API_SECRET : 'CHANGE_ME_IN_WP_CONFIG');
define('TIX_BOT_RATE_LIMIT_MAX', 5);          // Max Versuche pro E-Mail
define('TIX_BOT_RATE_LIMIT_WINDOW', 15 * 60); // 15 Minuten

// ═══════════════════════════════════════════════════
//  REST API REGISTRIERUNG
// ═══════════════════════════════════════════════════

add_action('rest_api_init', function () {
    $ns = 'tix-bot/v1';

    // GET /events – Kommende Events mit Ticketkategorien
    register_rest_route($ns, '/events', [
        'methods'             => 'GET',
        'callback'            => 'tixbot_get_events',
        'permission_callback' => 'tixbot_auth',
    ]);

    // GET /event/(?P<id>\d+) – Einzelnes Event
    register_rest_route($ns, '/event/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'tixbot_get_event',
        'permission_callback' => 'tixbot_auth',
    ]);

    // POST /tickets/lookup – Ticket-Suche mit Verifizierung
    register_rest_route($ns, '/tickets/lookup', [
        'methods'             => 'POST',
        'callback'            => 'tixbot_tickets_lookup',
        'permission_callback' => 'tixbot_auth',
    ]);

    // POST /cart/checkout-url – Checkout-URL generieren
    register_rest_route($ns, '/cart/checkout-url', [
        'methods'             => 'POST',
        'callback'            => 'tixbot_checkout_url',
        'permission_callback' => 'tixbot_auth',
    ]);

    // GET /customer/exists – Pruefen ob Bestellungen fuer E-Mail existieren
    register_rest_route($ns, '/customer/exists', [
        'methods'             => 'GET',
        'callback'            => 'tixbot_customer_exists',
        'permission_callback' => 'tixbot_auth',
    ]);
});

// ═══════════════════════════════════════════════════
//  AUTHENTIFIZIERUNG
// ═══════════════════════════════════════════════════

function tixbot_auth(WP_REST_Request $req) {
    $secret = $req->get_header('X-Bot-Secret');
    if (!$secret || !hash_equals(TIX_BOT_SECRET, $secret)) {
        return new WP_Error('tixbot_unauthorized', 'Unauthorized', ['status' => 401]);
    }
    return true;
}

// ═══════════════════════════════════════════════════
//  1. GET /events – Kommende Events
// ═══════════════════════════════════════════════════

function tixbot_get_events(WP_REST_Request $req) {
    $today = current_time('Y-m-d');

    $args = [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'meta_key'       => '_tix_date_start',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'     => '_tix_date_start',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE',
            ],
        ],
    ];

    $posts = get_posts($args);
    $events = [];

    foreach ($posts as $p) {
        $events[] = tixbot_format_event($p);
    }

    return rest_ensure_response([
        'ok'     => true,
        'count'  => count($events),
        'events' => $events,
    ]);
}

// ═══════════════════════════════════════════════════
//  2. GET /event/{id} – Einzelnes Event
// ═══════════════════════════════════════════════════

function tixbot_get_event(WP_REST_Request $req) {
    $id   = absint($req['id']);
    $post = get_post($id);

    if (!$post || $post->post_type !== 'event' || $post->post_status !== 'publish') {
        return new WP_Error('tixbot_not_found', 'Event nicht gefunden.', ['status' => 404]);
    }

    return rest_ensure_response([
        'ok'    => true,
        'event' => tixbot_format_event($post),
    ]);
}

// ═══════════════════════════════════════════════════
//  3. POST /tickets/lookup – Ticket-Suche
// ═══════════════════════════════════════════════════

function tixbot_tickets_lookup(WP_REST_Request $req) {
    $body  = $req->get_json_params();
    $email = sanitize_email($body['email'] ?? '');
    $type  = sanitize_text_field($body['verification_type'] ?? '');  // 'order_id' oder 'last_name'
    $value = sanitize_text_field($body['verification_value'] ?? '');

    // Validierung
    if (!$email || !is_email($email)) {
        return new WP_Error('tixbot_invalid_email', 'Ungueltige E-Mail-Adresse.', ['status' => 400]);
    }
    if (!in_array($type, ['order_id', 'last_name'], true)) {
        return new WP_Error('tixbot_invalid_type', 'verification_type muss "order_id" oder "last_name" sein.', ['status' => 400]);
    }
    if (!$value) {
        return new WP_Error('tixbot_missing_value', 'verification_value fehlt.', ['status' => 400]);
    }

    // ── Rate Limiting ──
    $rate_key  = 'tixbot_rl_' . md5(strtolower($email));
    $attempts  = (int) get_transient($rate_key);

    if ($attempts >= TIX_BOT_RATE_LIMIT_MAX) {
        return new WP_Error(
            'tixbot_rate_limited',
            'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.',
            ['status' => 429]
        );
    }

    set_transient($rate_key, $attempts + 1, TIX_BOT_RATE_LIMIT_WINDOW);

    // ── WooCommerce Orders suchen ──
    if (!function_exists('wc_get_orders')) {
        return new WP_Error('tixbot_wc_missing', 'WooCommerce nicht aktiv.', ['status' => 500]);
    }

    $order_args = [
        'billing_email' => strtolower($email),
        'status'        => ['completed', 'processing'],
        'limit'         => 50,
        'orderby'       => 'date',
        'order'         => 'DESC',
    ];

    $orders = wc_get_orders($order_args);

    if (empty($orders)) {
        return rest_ensure_response([
            'ok'      => false,
            'error'   => 'no_orders',
            'message' => 'Keine Bestellungen fuer diese E-Mail-Adresse gefunden.',
        ]);
    }

    // ── Verifizierung ──
    $verified_orders = [];

    foreach ($orders as $order) {
        if ($type === 'order_id') {
            if ((string) $order->get_id() === $value || (string) $order->get_order_number() === $value) {
                $verified_orders[] = $order;
            }
        } elseif ($type === 'last_name') {
            $billing_last = strtolower(trim($order->get_billing_last_name()));
            $check_val    = strtolower(trim($value));
            if ($billing_last === $check_val) {
                $verified_orders[] = $order;
            }
        }
    }

    if (empty($verified_orders)) {
        return rest_ensure_response([
            'ok'      => false,
            'error'   => 'verification_failed',
            'message' => 'Verifizierung fehlgeschlagen. Daten stimmen nicht ueberein.',
        ]);
    }

    // Bei Nachname-Verifizierung: alle zugehoerigen Bestellungen zurueckgeben
    if ($type === 'last_name') {
        $verified_orders = $orders; // Alle Bestellungen der E-Mail (Nachname bestaetigt Identitaet)
    }

    // ── Tickets sammeln ──
    $all_tickets = [];

    foreach ($verified_orders as $order) {
        $order_id = $order->get_id();

        // Primaer: ueber TIX_Tickets (normalisiert)
        if (class_exists('TIX_Tickets')) {
            $tickets = TIX_Tickets::get_all_tickets_for_order($order_id);
            foreach ($tickets as $t) {
                if (($t['status'] ?? '') === 'cancelled') continue;
                $all_tickets[] = [
                    'ticket_code'   => $t['code'],
                    'event_name'    => $t['event_name'],
                    'event_id'      => $t['event_id'],
                    'category'      => $t['cat_name'],
                    'status'        => $t['status'],
                    'order_id'      => $t['order_id'],
                    'order_date'    => $order->get_date_created() ? $order->get_date_created()->format('d.m.Y') : '',
                    'download_url'  => $t['download_url'] ?? '',
                ];
            }
        }
    }

    // Duplikate entfernen (gleicher ticket_code)
    $seen = [];
    $unique_tickets = [];
    foreach ($all_tickets as $t) {
        $key = $t['ticket_code'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique_tickets[] = $t;
        }
    }

    if (empty($unique_tickets)) {
        return rest_ensure_response([
            'ok'      => false,
            'error'   => 'no_tickets',
            'message' => 'Keine aktiven Tickets fuer diese Bestellung(en) gefunden.',
        ]);
    }

    // Rate-Limit bei Erfolg zuruecksetzen
    delete_transient($rate_key);

    return rest_ensure_response([
        'ok'      => true,
        'count'   => count($unique_tickets),
        'tickets' => $unique_tickets,
    ]);
}

// ═══════════════════════════════════════════════════
//  4. POST /cart/checkout-url – Checkout-URL
// ═══════════════════════════════════════════════════

function tixbot_checkout_url(WP_REST_Request $req) {
    $body  = $req->get_json_params();
    $items = $body['items'] ?? [];

    if (empty($items) || !is_array($items)) {
        return new WP_Error('tixbot_no_items', 'Keine Artikel angegeben.', ['status' => 400]);
    }

    // Checkout-URL zusammenbauen (WooCommerce add-to-cart)
    $base_url = wc_get_checkout_url();
    $parts    = [];

    foreach ($items as $i => $item) {
        $product_id = absint($item['product_id'] ?? 0);
        $quantity   = max(1, absint($item['quantity'] ?? 1));

        if (!$product_id) continue;

        // Pruefen ob Produkt existiert und kaufbar ist
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_purchasable()) continue;

        $parts[] = [
            'product_id' => $product_id,
            'quantity'   => $quantity,
            'title'      => $product->get_name(),
            'price'      => (float) $product->get_price(),
        ];
    }

    if (empty($parts)) {
        return new WP_Error('tixbot_invalid_items', 'Keine gueltigen Produkte gefunden.', ['status' => 400]);
    }

    // Einfache add-to-cart URL fuer erstes Produkt, Multi-Add per Parameter
    // WooCommerce Standard: /?add-to-cart=ID&quantity=N
    // Fuer mehrere Produkte bauen wir eine spezielle URL
    if (count($parts) === 1) {
        $url = add_query_arg([
            'add-to-cart' => $parts[0]['product_id'],
            'quantity'    => $parts[0]['quantity'],
        ], wc_get_checkout_url());
    } else {
        // Multi-Add: Wir erstellen einen Link, der zum Warenkorb fuehrt
        // Jedes Produkt wird einzeln als add-to-cart verarbeitet
        // Besserer Ansatz: Link zum ersten Produkt + Redirect zu Checkout
        $url = add_query_arg([
            'add-to-cart' => $parts[0]['product_id'],
            'quantity'    => $parts[0]['quantity'],
        ], wc_get_cart_url());

        // Zusaetzliche Produkte als Info mitgeben
        // (Frontend-Widget fuegt diese per WC AJAX hinzu)
    }

    return rest_ensure_response([
        'ok'           => true,
        'checkout_url' => $url,
        'items'        => $parts,
        'total'        => array_sum(array_map(function ($p) {
            return $p['price'] * $p['quantity'];
        }, $parts)),
    ]);
}

// ═══════════════════════════════════════════════════
//  5. GET /customer/exists – Kunde pruefen
// ═══════════════════════════════════════════════════

function tixbot_customer_exists(WP_REST_Request $req) {
    $email = sanitize_email($req->get_param('email'));

    if (!$email || !is_email($email)) {
        return new WP_Error('tixbot_invalid_email', 'Ungueltige E-Mail-Adresse.', ['status' => 400]);
    }

    if (!function_exists('wc_get_orders')) {
        return new WP_Error('tixbot_wc_missing', 'WooCommerce nicht aktiv.', ['status' => 500]);
    }

    $orders = wc_get_orders([
        'billing_email' => strtolower($email),
        'status'        => ['completed', 'processing'],
        'limit'         => 1,
        'return'        => 'ids',
    ]);

    $has_orders = !empty($orders);

    // Zusatzinfo: Kundenname (nur Vorname fuer Begruessung)
    $first_name = '';
    if ($has_orders) {
        $order = wc_get_order($orders[0]);
        if ($order) {
            $first_name = $order->get_billing_first_name();
        }
    }

    return rest_ensure_response([
        'ok'         => true,
        'exists'     => $has_orders,
        'first_name' => $first_name,
    ]);
}

// ═══════════════════════════════════════════════════
//  HILFSFUNKTIONEN
// ═══════════════════════════════════════════════════

/**
 * Event-Daten fuer API-Response formatieren.
 */
function tixbot_format_event($post) {
    $id = $post->ID;

    // Basis-Daten
    $date_start = get_post_meta($id, '_tix_date_start', true);
    $date_end   = get_post_meta($id, '_tix_date_end', true);
    $time_start = get_post_meta($id, '_tix_time_start', true);
    $time_end   = get_post_meta($id, '_tix_time_end', true);
    $time_doors = get_post_meta($id, '_tix_time_doors', true);
    $location   = get_post_meta($id, '_tix_location', true);
    $address    = get_post_meta($id, '_tix_address', true);
    $organizer  = get_post_meta($id, '_tix_organizer', true);
    $status     = get_post_meta($id, '_tix_event_status', true);

    // Ticketkategorien
    $categories_raw = get_post_meta($id, '_tix_ticket_categories', true);
    $categories     = [];

    if (is_array($categories_raw)) {
        foreach ($categories_raw as $i => $cat) {
            // Nur online-verkaeufliche Kategorien
            if (($cat['online'] ?? '1') === '0') continue;
            if (($cat['offline_ticket'] ?? '0') === '1') continue;

            $product_id = absint($cat['product_id'] ?? 0);
            $qty_total  = absint($cat['qty'] ?? 0);

            // Verfuegbarkeit ermitteln
            $qty_sold      = 0;
            $qty_available = $qty_total;
            $is_sold_out   = false;

            if ($product_id && function_exists('wc_get_product')) {
                $product = wc_get_product($product_id);
                if ($product) {
                    if ($product->managing_stock()) {
                        // Stock-Management aktiv → exakte Berechnung
                        $stock = (int) $product->get_stock_quantity();
                        if ($qty_total > 0) {
                            $qty_sold      = max(0, $qty_total - $stock);
                            $qty_available = max(0, $stock);
                        } else {
                            // Kein Kontingent gesetzt → Stock direkt verwenden
                            $qty_available = max(0, $stock);
                        }
                        $is_sold_out = $qty_available <= 0;
                    } else {
                        // Kein Stock-Management → WC-Status entscheidet
                        $is_sold_out = !$product->is_in_stock();
                        if (!$is_sold_out) {
                            // Produkt ist "vorrätig" ohne Mengenbegrenzung
                            $qty_available = $qty_total > 0 ? $qty_total : 999;
                        } else {
                            $qty_available = 0;
                        }
                    }
                }
            } elseif ($qty_total === 0) {
                // Kein Produkt verknüpft, kein Kontingent → verfuegbar
                $qty_available = 999;
            }

            // Aktuellen Preis bestimmen (Phasen beruecksichtigen)
            $price = tixbot_current_price($cat);

            $categories[] = [
                'index'         => $i,
                'name'          => $cat['name'] ?? '',
                'group'         => $cat['group'] ?? '',
                'price'         => $price,
                'original_price'=> (float) ($cat['price'] ?? 0),
                'product_id'    => $product_id,
                'quantity_total'=> $qty_total,
                'quantity_sold' => $qty_sold,
                'quantity_available' => $qty_available,
                'sold_out'      => $is_sold_out,
                'description'   => $cat['desc'] ?? '',
            ];
        }
    }

    // Presale-Status
    $presale_active = get_post_meta($id, '_tix_presale_active', true) === '1';

    // Thumbnail
    $thumb_id  = get_post_thumbnail_id($id);
    $thumbnail = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : '';

    // Event-URL
    $url = get_permalink($id);

    return [
        'id'          => $id,
        'title'       => $post->post_title,
        'excerpt'     => wp_strip_all_tags($post->post_excerpt),
        'url'         => $url,
        'thumbnail'   => $thumbnail,
        'date_start'  => $date_start,
        'date_end'    => $date_end,
        'time_start'  => $time_start,
        'time_end'    => $time_end,
        'time_doors'  => $time_doors,
        'date_formatted' => $date_start ? date_i18n('l, d. F Y', strtotime($date_start)) : '',
        'location'    => $location,
        'address'     => $address,
        'organizer'   => $organizer,
        'status'      => $status ?: 'available',
        'presale_active' => $presale_active,
        'categories'  => $categories,
        'total_capacity' => array_sum(array_column($categories, 'quantity_total')),
        'total_available'=> array_sum(array_column($categories, 'quantity_available')),
    ];
}

/**
 * Aktuellen Preis einer Kategorie bestimmen (inkl. Phasen / Early Bird).
 */
function tixbot_current_price($cat) {
    $now    = current_time('Y-m-d');
    $phases = $cat['phases'] ?? [];

    if (!empty($phases) && is_array($phases)) {
        foreach ($phases as $phase) {
            $until = $phase['until'] ?? '';
            if ($until && $now <= $until) {
                return (float) ($phase['price'] ?? $cat['price'] ?? 0);
            }
        }
    }

    // Sale-Price hat Vorrang
    if (!empty($cat['sale_price']) && (float) $cat['sale_price'] > 0) {
        return (float) $cat['sale_price'];
    }

    return (float) ($cat['price'] ?? 0);
}
