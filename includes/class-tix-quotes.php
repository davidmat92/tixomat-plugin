<?php
/**
 * Tixomat Vorbestellungen (Quotes)
 *
 * Admin bereitet eine Bestellung vor (Event + Tickets + ggf. Sonderpreise + Kundendaten),
 * generiert einen Magic-Link. Kunde klickt → Cart wird gefüllt → direkt im /kasse/ Checkout.
 *
 * Use-Case: Kunde/Partner/B2B sagt was er will, Admin macht den Preis-Deal aus
 * und schickt einen Bezahl-Link.
 *
 * Storage: CPT tix_quote (Post-Status pending/redeemed/expired) mit Token + Items als Meta.
 * URL-Format: /?tix_quote=<token>
 *
 * REST (1.38.806, B11 „Vorbestellungen“ im Studio; Inhaber/Team-Admin/Admin wie die Statistik, evendis nur eigene Events):
 *   GET    /studio/quotes?status=open|redeemed|expired  → {ok, quotes[…], max_items, max_days}
 *   POST   /studio/quotes {items[{event_id, cat_index, qty, custom_price?}], customer{…}, note?, expiry_days?} → 201 {quote}
 *   DELETE /studio/quotes/{id}
 */
if (!defined('ABSPATH')) exit;

class TIX_Quotes {

    const CPT  = 'tix_quote';
    const META_TOKEN    = '_tix_quote_token';
    const META_ITEMS    = '_tix_quote_items';
    const META_CUSTOMER = '_tix_quote_customer';
    const META_NOTE     = '_tix_quote_note';
    const META_EXPIRES  = '_tix_quote_expires';
    const META_REDEEMED = '_tix_quote_redeemed_at';
    const META_ORDER_ID = '_tix_quote_order_id';

    public static function init() {
        add_action('init',       [__CLASS__, 'register_cpt']);
        add_action('init',       [__CLASS__, 'maybe_handle_quote_url'], 5);
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('wp_ajax_tix_create_quote', [__CLASS__, 'ajax_create_quote']);
        add_action('wp_ajax_tix_delete_quote', [__CLASS__, 'ajax_delete_quote']);
        // Quote als "redeemed" markieren wenn Order daraus entsteht
        add_action('tix_order_created', [__CLASS__, 'mark_redeemed_on_order'], 10, 2);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
    }

    const MAX_ITEMS = 20;
    const MAX_DAYS = 365;

    // ════════════════════════════════════════════════════════════
    // Kern: Vorbestellung anlegen (AJAX + REST)
    // ════════════════════════════════════════════════════════════

    /** → Post-ID oder WP_Error (tix_invalid_field mit field). $allowed = erlaubte Event-IDs (null = alle). */
    public static function create($items, array $customer, $note, $expiry_days, $allowed = null) {
        if (!is_array($items) || !$items) return new WP_Error('tix_invalid_field', 'Mindestens ein Ticket erforderlich.', ['status' => 400, 'field' => 'items']);
        if (count($items) > self::MAX_ITEMS) return new WP_Error('tix_invalid_field', 'Höchstens ' . self::MAX_ITEMS . ' Positionen.', ['status' => 400, 'field' => 'items']);
        $clean = [];
        foreach (array_values($items) as $i => $it) {
            $eid = intval($it['event_id'] ?? 0);
            $idx = intval($it['cat_index'] ?? 0);
            $qty = intval($it['qty'] ?? 1);
            if (!$eid || get_post_type($eid) !== 'event' || ($allowed !== null && !in_array($eid, $allowed, true))) return new WP_Error('tix_invalid_field', 'Event nicht gefunden.', ['status' => 400, 'field' => "items.$i.event_id"]);
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            if (!is_array($cats) || !isset($cats[$idx])) return new WP_Error('tix_invalid_field', 'Diese Ticketart gibt es nicht.', ['status' => 400, 'field' => "items.$i.cat_index"]);
            if ($qty < 1 || $qty > 100) return new WP_Error('tix_invalid_field', 'Anzahl 1 bis 100.', ['status' => 400, 'field' => "items.$i.qty"]);
            $custom = $it['custom_price'] ?? '';
            if ($custom !== '' && $custom !== null) {
                $custom = floatval(str_replace(',', '.', (string) $custom));
                if ($custom < 0 || $custom > 10000) return new WP_Error('tix_invalid_field', 'Preis 0 bis 10.000 €.', ['status' => 400, 'field' => "items.$i.custom_price"]);
            } else $custom = '';
            $clean[] = ['event_id' => $eid, 'cat_index' => $idx, 'qty' => $qty, 'custom_price' => $custom];
        }
        $cust = [
            'first_name' => mb_substr(sanitize_text_field((string) ($customer['first_name'] ?? '')), 0, 80),
            'last_name'  => mb_substr(sanitize_text_field((string) ($customer['last_name'] ?? '')), 0, 80),
            'email'      => sanitize_email((string) ($customer['email'] ?? '')),
            'phone'      => mb_substr(sanitize_text_field((string) ($customer['phone'] ?? '')), 0, 40),
        ];
        if (($customer['email'] ?? '') !== '' && !is_email($cust['email'])) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'customer.email']);
        $days = max(1, min(self::MAX_DAYS, intval($expiry_days ?: 30)));
        $title = trim($cust['first_name'] . ' ' . $cust['last_name']);
        if ($title === '') $title = $cust['email'] ?: 'Vorbestellung';
        $title .= ' · ' . date_i18n('d.m.Y H:i');
        $id = wp_insert_post(['post_type' => self::CPT, 'post_status' => 'pending', 'post_title' => $title], true);
        if (is_wp_error($id) || !$id) return new WP_Error('tix_quote_save', 'Konnte Vorbestellung nicht speichern.', ['status' => 500]);
        update_post_meta($id, self::META_TOKEN, bin2hex(random_bytes(16)));
        update_post_meta($id, self::META_ITEMS, $clean);
        update_post_meta($id, self::META_CUSTOMER, $cust);
        update_post_meta($id, self::META_NOTE, mb_substr(wp_kses_post((string) $note), 0, 2000));
        update_post_meta($id, self::META_EXPIRES, time() + $days * DAY_IN_SECONDS);
        return $id;
    }

    public static function url_of($id) {
        return add_query_arg('tix_quote', (string) get_post_meta($id, self::META_TOKEN, true), home_url('/'));
    }

    // ════════════════════════════════════════════════════════════
    // REST (Studio)
    // ════════════════════════════════════════════════════════════

    public static function rest_routes() {
        $p = function ($req) { return class_exists('TIX_Studio_Stats') ? TIX_Studio_Stats::perm_manager($req) : current_user_can('manage_options'); };
        register_rest_route('tixomat/v1', '/studio/quotes', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $p],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => $p],
        ]);
        register_rest_route('tixomat/v1', '/studio/quotes/(?P<id>\d+)', [
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $p],
        ]);
    }

    private static function scope() {
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::is_admin()) return null;
        return class_exists('TIX_Studio_Stats') ? array_map('intval', TIX_Studio_Stats::scope_ids()) : null;
    }

    /** Vorbestellung sichtbar, wenn alle Events im eigenen Bereich liegen */
    private static function in_scope($id, $scope) {
        if ($scope === null) return true;
        $items = get_post_meta($id, self::META_ITEMS, true);
        if (!is_array($items) || !$items) return false;
        foreach ($items as $it) if (!in_array(intval($it['event_id'] ?? 0), $scope, true)) return false;
        return true;
    }

    public static function quote_payload($id) {
        $items = get_post_meta($id, self::META_ITEMS, true);
        $out = []; $total = 0.0;
        foreach ((array) $items as $it) {
            $eid = intval($it['event_id'] ?? 0); $idx = intval($it['cat_index'] ?? 0);
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            $cat = is_array($cats) && isset($cats[$idx]) ? $cats[$idx] : null;
            $list = $cat ? floatval($cat['price'] ?? 0) : 0.0;
            $custom = isset($it['custom_price']) && $it['custom_price'] !== '' && $it['custom_price'] !== null ? round(floatval($it['custom_price']), 2) : null;
            $price = $custom ?? $list;
            $total += $price * intval($it['qty'] ?? 1);
            $out[] = ['event_id' => $eid, 'event_title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'event_date' => (string) get_post_meta($eid, '_tix_date_start', true),
                'cat_index' => $idx, 'cat_name' => $cat ? (string) ($cat['name'] ?? '') : null, 'qty' => intval($it['qty'] ?? 1), 'list_price' => round($list, 2), 'custom_price' => $custom, 'price' => round($price, 2)];
        }
        $exp = intval(get_post_meta($id, self::META_EXPIRES, true));
        $ps = get_post_status($id);
        $status = $ps === 'redeemed' ? 'redeemed' : ($exp && $exp < time() || $ps === 'expired' ? 'expired' : 'open');
        $c = get_post_meta($id, self::META_CUSTOMER, true);
        return ['id' => intval($id), 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'), 'status' => $status, 'url' => self::url_of($id),
            'items' => $out, 'total' => round($total, 2), 'customer' => is_array($c) ? $c : null, 'note' => (string) get_post_meta($id, self::META_NOTE, true),
            'created_at' => get_post_time('c', true, $id), 'expires_at' => $exp ? gmdate('c', $exp) : null,
            'redeemed_at' => ($r = (string) get_post_meta($id, self::META_REDEEMED, true)) !== '' ? $r : null,
            'order_id' => intval(get_post_meta($id, self::META_ORDER_ID, true)) ?: null];
    }

    public static function rest_list(WP_REST_Request $req) {
        $scope = self::scope();
        $st = (string) $req->get_param('status');
        $ids = get_posts(['post_type' => self::CPT, 'post_status' => ['pending', 'redeemed', 'expired'], 'posts_per_page' => 300, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids']);
        $out = [];
        foreach ($ids as $id) {
            if (!self::in_scope($id, $scope)) continue;
            $q = self::quote_payload($id);
            if ($st !== '' && $q['status'] !== $st) continue;
            $out[] = $q;
        }
        $r = rest_ensure_response(['ok' => true, 'quotes' => $out, 'max_items' => self::MAX_ITEMS, 'max_days' => self::MAX_DAYS]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_create(WP_REST_Request $req) {
        $id = self::create($req->get_param('items'), (array) ($req->get_param('customer') ?: []), (string) $req->get_param('note'), intval($req->get_param('expiry_days') ?: 30), self::scope());
        if (is_wp_error($id)) return $id;
        return new WP_REST_Response(['ok' => true, 'quote' => self::quote_payload($id)], 201);
    }

    public static function rest_delete(WP_REST_Request $req) {
        $id = intval($req['id']);
        if (get_post_type($id) !== self::CPT || !self::in_scope($id, self::scope())) return new WP_Error('not_found', 'Vorbestellung nicht gefunden.', ['status' => 404]);
        wp_delete_post($id, true);
        return rest_ensure_response(['ok' => true, 'deleted' => $id]);
    }

    public static function register_cpt() {
        register_post_type(self::CPT, [
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'exclude_from_search' => true,
            'capability_type'     => 'post',
            'supports'            => ['title'],
        ]);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Vorbestellungen',
            'Vorbestellungen',
            'edit_posts',
            'tix-quotes',
            [__CLASS__, 'render_admin']
        );
    }

    // ════════════════════════════════════════════════════════════
    // URL-Handler: /?tix_quote=<token>
    // ════════════════════════════════════════════════════════════

    public static function maybe_handle_quote_url() {
        $token = $_GET['tix_quote'] ?? '';
        if (!$token || !preg_match('/^[a-z0-9]{32}$/i', $token)) return;

        $posts = get_posts([
            'post_type'      => self::CPT,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_key'       => self::META_TOKEN,
            'meta_value'     => $token,
        ]);

        if (empty($posts)) {
            wp_die('Diese Vorbestellung wurde nicht gefunden.', 'Vorbestellung', ['response' => 404]);
        }

        $quote   = $posts[0];
        $expires = (int) get_post_meta($quote->ID, self::META_EXPIRES, true);
        if ($expires && $expires < time()) {
            wp_die('Diese Vorbestellung ist leider abgelaufen. Bitte fordere einen neuen Link an.', 'Abgelaufen', ['response' => 410]);
        }

        if ($quote->post_status === 'redeemed') {
            // Bereits eingelöst — Kunde nochmal: zeige Hinweis aber Cart ist evtl. leer
            // (oder bezahlt/in Bestätigung). Wir leiten zur Kasse weiter, dort sieht er Status.
        }

        $items = get_post_meta($quote->ID, self::META_ITEMS, true);
        if (!is_array($items) || empty($items)) {
            wp_die('Diese Vorbestellung enthält keine Tickets.', 'Vorbestellung', ['response' => 400]);
        }

        // Cart füllen (überschreibt vorhandenen Cart!)
        if (!class_exists('TIX_Native_Checkout')) {
            wp_die('Checkout-System nicht verfügbar.', 'Fehler', ['response' => 500]);
        }

        $cart_items = [];
        foreach ($items as $item) {
            $event_id = intval($item['event_id'] ?? 0);
            $cat_idx  = intval($item['cat_index'] ?? 0);
            $qty      = max(1, intval($item['qty'] ?? 1));
            if (!$event_id) continue;

            $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (!is_array($categories) || !isset($categories[$cat_idx])) continue;
            $cat = $categories[$cat_idx];

            // Custom-Preis aus Quote oder Standard-Dynamic-Preis
            if (isset($item['custom_price']) && $item['custom_price'] !== '' && $item['custom_price'] !== null) {
                $price = floatval($item['custom_price']);
                $locked = true;
            } else {
                $price = class_exists('TIX_Dynamic_Pricing')
                    ? floatval(TIX_Dynamic_Pricing::get_dynamic_price($event_id, $cat_idx))
                    : floatval($cat['price'] ?? 0);
                $locked = false;
            }

            $cart_items[] = [
                'event_id'     => $event_id,
                'cat_index'    => $cat_idx,
                'name'         => sanitize_text_field($cat['name'] ?? 'Ticket'),
                'event_title'  => get_the_title($event_id),
                'price'        => $price,
                'qty'          => $qty,
                'meta'         => [],
                'locked_price' => $locked,
                'quote_token'  => $token,
            ];
        }

        if (empty($cart_items)) {
            wp_die('Vorbestellung enthält keine gültigen Tickets.', 'Vorbestellung', ['response' => 400]);
        }

        // Customer-Daten direkt in den Cart packen — der Checkout liest das beim Render
        // (zuverlässiger als Transient mit session_id, das hatte Race-Conditions)
        $customer = get_post_meta($quote->ID, self::META_CUSTOMER, true);
        $note     = (string) get_post_meta($quote->ID, self::META_NOTE, true);

        TIX_Native_Checkout::save_cart([
            'items'          => $cart_items,
            'coupon'         => null,
            'quote_token'    => $token,
            'quote_post_id'  => $quote->ID,
            'quote_customer' => is_array($customer) ? $customer : [],
            'quote_note'     => $note,
        ]);

        wp_safe_redirect(TIX_Native_Checkout::checkout_url());
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // AJAX: Quote erstellen
    // ════════════════════════════════════════════════════════════

    public static function ajax_create_quote() {
        check_ajax_referer('tix_create_quote', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung'], 403);

        $items_raw = $_POST['items'] ?? '';
        $items = is_string($items_raw) ? json_decode(wp_unslash($items_raw), true) : $items_raw;
        if (!is_array($items) || empty($items)) {
            wp_send_json_error(['message' => 'Mindestens ein Ticket erforderlich.']);
        }

        $clean_items = [];
        foreach ($items as $it) {
            $eid = intval($it['event_id'] ?? 0);
            $idx = intval($it['cat_index'] ?? 0);
            $qty = max(1, intval($it['qty'] ?? 1));
            if (!$eid) continue;
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            if (!is_array($cats) || !isset($cats[$idx])) continue;
            $custom = $it['custom_price'] ?? '';
            if ($custom !== '' && $custom !== null) {
                // Komma → Punkt für deutschsprachige Eingabe
                $custom = floatval(str_replace(',', '.', (string) $custom));
                if ($custom < 0) $custom = 0;
            } else {
                $custom = '';
            }
            $clean_items[] = [
                'event_id'     => $eid,
                'cat_index'    => $idx,
                'qty'          => $qty,
                'custom_price' => $custom,
            ];
        }
        if (empty($clean_items)) {
            wp_send_json_error(['message' => 'Keine gültigen Tickets in der Auswahl.']);
        }

        $customer = [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($_POST['last_name'] ?? ''),
            'email'      => sanitize_email($_POST['email'] ?? ''),
            'phone'      => sanitize_text_field($_POST['phone'] ?? ''),
        ];
        $note = wp_kses_post(wp_unslash($_POST['note'] ?? ''));

        $expiry_days = max(1, min(365, intval($_POST['expiry_days'] ?? 30)));

        // Quote-CPT erstellen
        $title = trim($customer['first_name'] . ' ' . $customer['last_name']);
        if ($title === '') $title = $customer['email'] ?: 'Vorbestellung';
        $title .= ' · ' . date_i18n('d.m.Y H:i');

        $token = bin2hex(random_bytes(16)); // 32 hex chars

        $post_id = wp_insert_post([
            'post_type'   => self::CPT,
            'post_status' => 'pending',
            'post_title'  => $title,
        ]);
        if (is_wp_error($post_id) || !$post_id) {
            wp_send_json_error(['message' => 'Konnte Vorbestellung nicht speichern.']);
        }

        update_post_meta($post_id, self::META_TOKEN,    $token);
        update_post_meta($post_id, self::META_ITEMS,    $clean_items);
        update_post_meta($post_id, self::META_CUSTOMER, $customer);
        update_post_meta($post_id, self::META_NOTE,     $note);
        update_post_meta($post_id, self::META_EXPIRES,  time() + ($expiry_days * DAY_IN_SECONDS));

        $url = add_query_arg('tix_quote', $token, home_url('/'));

        wp_send_json_success([
            'post_id' => $post_id,
            'token'   => $token,
            'url'     => $url,
            'title'   => $title,
        ]);
    }

    public static function ajax_delete_quote() {
        check_ajax_referer('tix_create_quote', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung'], 403);
        $id = intval($_POST['id'] ?? 0);
        if (!$id || get_post_type($id) !== self::CPT) wp_send_json_error(['message' => 'Ungültige ID']);
        wp_delete_post($id, true);
        wp_send_json_success();
    }

    // ════════════════════════════════════════════════════════════
    // Hook: Quote als redeemed markieren wenn Order entsteht
    // ════════════════════════════════════════════════════════════

    public static function mark_redeemed_on_order($order_id, $context = []) {
        if (!class_exists('TIX_Native_Checkout')) return;
        $cart = TIX_Native_Checkout::get_cart();
        $token = $cart['quote_token'] ?? null;
        $quote_post = $cart['quote_post_id'] ?? null;
        if (!$token || !$quote_post) return;

        wp_update_post(['ID' => $quote_post, 'post_status' => 'redeemed']);
        update_post_meta($quote_post, self::META_REDEEMED, current_time('mysql'));
        update_post_meta($quote_post, self::META_ORDER_ID, $order_id);
    }

    // ════════════════════════════════════════════════════════════
    // Admin-Seite: Vorbestellung erstellen + Liste
    // ════════════════════════════════════════════════════════════

    public static function render_admin() {
        if (!current_user_can('edit_posts')) wp_die('Keine Berechtigung.');

        // Events laden für Dropdown
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        // Vorhandene Quotes
        $quotes = get_posts([
            'post_type'      => self::CPT,
            'post_status'    => ['pending', 'redeemed', 'expired'],
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        // Event-Daten als JS-Array für Live-UI
        $events_data = [];
        foreach ($events as $ev) {
            $cats = get_post_meta($ev->ID, '_tix_ticket_categories', true);
            $cats_clean = [];
            if (is_array($cats)) {
                foreach ($cats as $idx => $c) {
                    $cats_clean[] = [
                        'index' => $idx,
                        'name'  => $c['name'] ?? ('Kategorie ' . ($idx + 1)),
                        'price' => floatval($c['price'] ?? 0),
                    ];
                }
            }
            $events_data[] = [
                'id'    => $ev->ID,
                'title' => $ev->post_title,
                'date'  => get_post_meta($ev->ID, '_tix_date_start', true),
                'cats'  => $cats_clean,
            ];
        }

        $nonce = wp_create_nonce('tix_create_quote');
        ?>
        <div class="wrap" style="max-width:1200px;">
            <h1>Vorbestellungen</h1>
            <p style="color:#6b7280;font-size:14px;margin:8px 0 24px;">
                Bereite eine Bestellung für einen Kunden vor (mit ggf. Sonderpreisen) und schicke ihm einen Magic-Link. Beim Klick landet er direkt im Checkout mit den vorbereiteten Tickets.
            </p>

            <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:24px;align-items:start;">

                <?php // ── Formular: Neue Vorbestellung ── ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;">
                    <h2 style="margin-top:0;font-size:18px;">Neue Vorbestellung</h2>

                    <table class="form-table" style="margin-top:8px;">
                        <tr>
                            <th scope="row"><label>Event</label></th>
                            <td>
                                <select id="tix-quote-event" style="min-width:400px;">
                                    <option value="">— Event wählen —</option>
                                    <?php foreach ($events_data as $ev):
                                        $date_fmt = $ev['date'] ? date_i18n('d.m.Y', strtotime($ev['date'])) : '';
                                    ?>
                                        <option value="<?php echo $ev['id']; ?>"><?php echo esc_html(($date_fmt ? '[' . $date_fmt . '] ' : '') . $ev['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <h3 style="margin:24px 0 8px;font-size:14px;">Tickets</h3>
                    <div id="tix-quote-items" style="border:1px solid #e5e7eb;border-radius:8px;padding:0;background:#f9fafb;min-height:60px;">
                        <p id="tix-quote-no-items" style="margin:0;padding:20px;text-align:center;color:#9ca3af;font-style:italic;">Noch keine Tickets ausgewählt.</p>
                    </div>
                    <button type="button" class="button" id="tix-quote-add-row" disabled style="margin-top:8px;">+ Ticket hinzufügen</button>

                    <h3 style="margin:28px 0 8px;font-size:14px;">Kundendaten <small style="color:#9ca3af;font-weight:400;">(optional, werden im Checkout vorausgefüllt)</small></h3>
                    <table class="form-table">
                        <tr>
                            <th><label>Vorname</label></th>
                            <td><input type="text" id="tix-quote-fn" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label>Nachname</label></th>
                            <td><input type="text" id="tix-quote-ln" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label>E-Mail</label></th>
                            <td><input type="email" id="tix-quote-email" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label>Telefon</label></th>
                            <td><input type="text" id="tix-quote-phone" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label>Notiz <small style="color:#9ca3af;font-weight:400;">(intern)</small></label></th>
                            <td><textarea id="tix-quote-note" rows="2" style="width:100%;max-width:500px;" placeholder="z.B. VIP-Kunde, Sonderpreis nach Telefonat 22.04."></textarea></td>
                        </tr>
                        <tr>
                            <th><label>Gültig für</label></th>
                            <td>
                                <input type="number" id="tix-quote-expiry" value="30" min="1" max="365" style="width:80px;"> Tage
                            </td>
                        </tr>
                    </table>

                    <p style="margin:20px 0 0;">
                        <button type="button" class="button button-primary button-large" id="tix-quote-submit">Magic-Link generieren</button>
                        <span id="tix-quote-status" style="margin-left:12px;color:#6b7280;font-size:13px;"></span>
                    </p>

                    <div id="tix-quote-result" style="display:none;margin-top:24px;padding:18px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;">
                        <p style="margin:0 0 10px;font-weight:600;color:#065f46;">✓ Vorbestellung erstellt</p>
                        <p style="margin:0 0 8px;font-size:13px;color:#047857;">Schicke diesen Link an den Kunden — beim Klick landet er direkt im Checkout:</p>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="text" id="tix-quote-url" readonly style="flex:1;padding:8px 12px;font-family:monospace;font-size:12px;background:#fff;border:1px solid #d1fae5;border-radius:6px;">
                            <button type="button" class="button" id="tix-quote-copy">Kopieren</button>
                        </div>
                    </div>
                </div>

                <?php // ── Liste: Bestehende Quotes ── ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;font-size:18px;">Bestehende Vorbestellungen</h2>
                    <?php if (empty($quotes)): ?>
                        <p style="color:#9ca3af;font-style:italic;">Noch keine Vorbestellungen vorhanden.</p>
                    <?php else: ?>
                        <div style="max-height:600px;overflow-y:auto;">
                        <?php foreach ($quotes as $q):
                            $token   = get_post_meta($q->ID, self::META_TOKEN, true);
                            $items_q = get_post_meta($q->ID, self::META_ITEMS, true);
                            $cust    = get_post_meta($q->ID, self::META_CUSTOMER, true);
                            $expires = intval(get_post_meta($q->ID, self::META_EXPIRES, true));
                            $url     = add_query_arg('tix_quote', $token, home_url('/'));
                            $is_expired = $expires && $expires < time();
                            $is_redeemed = $q->post_status === 'redeemed';
                            $color = $is_redeemed ? '#059669' : ($is_expired ? '#9ca3af' : '#6366f1');
                            $label = $is_redeemed ? 'Eingelöst' : ($is_expired ? 'Abgelaufen' : 'Offen');
                            $total_qty = is_array($items_q) ? array_sum(array_column($items_q, 'qty')) : 0;
                        ?>
                            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:start;gap:8px;">
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-weight:600;font-size:13px;color:#111;"><?php echo esc_html($q->post_title); ?></div>
                                        <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                            <?php echo esc_html($cust['email'] ?? '—'); ?>
                                            · <?php echo $total_qty; ?> Tickets
                                            <?php if ($expires): ?>
                                                · gültig bis <?php echo date_i18n('d.m.Y', $expires); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span style="font-size:10px;font-weight:700;background:<?php echo $color; ?>;color:#fff;padding:3px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:0.5px;flex-shrink:0;"><?php echo esc_html($label); ?></span>
                                </div>
                                <div style="display:flex;gap:6px;margin-top:10px;">
                                    <input type="text" value="<?php echo esc_attr($url); ?>" readonly style="flex:1;padding:5px 8px;font-family:monospace;font-size:11px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;" onclick="this.select();">
                                    <button type="button" class="button button-small tix-quote-copy-btn" data-url="<?php echo esc_attr($url); ?>">Kopieren</button>
                                    <button type="button" class="button button-small tix-quote-delete-btn" data-id="<?php echo $q->ID; ?>" style="color:#b91c1c;">×</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <script>
            (function(){
                var EVENTS = <?php echo wp_json_encode($events_data); ?>;
                var nonce = '<?php echo $nonce; ?>';
                var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

                var $event = document.getElementById('tix-quote-event');
                var $items = document.getElementById('tix-quote-items');
                var $noItems = document.getElementById('tix-quote-no-items');
                var $addBtn = document.getElementById('tix-quote-add-row');
                var $submit = document.getElementById('tix-quote-submit');
                var $status = document.getElementById('tix-quote-status');
                var $result = document.getElementById('tix-quote-result');
                var $url = document.getElementById('tix-quote-url');
                var $copy = document.getElementById('tix-quote-copy');

                var currentEvent = null;

                $event.addEventListener('change', function(){
                    var eid = parseInt(this.value);
                    currentEvent = EVENTS.find(function(e){ return e.id === eid; });
                    $addBtn.disabled = !currentEvent || !currentEvent.cats || !currentEvent.cats.length;
                    if ($addBtn.disabled && currentEvent) $addBtn.textContent = '+ Ticket hinzufügen (Event hat keine Kategorien)';
                    else $addBtn.textContent = '+ Ticket hinzufügen';
                });

                $addBtn.addEventListener('click', function(){
                    if (!currentEvent || !currentEvent.cats.length) return;
                    addRow();
                });

                function addRow() {
                    if ($noItems) { $noItems.remove(); $noItems = null; }
                    var row = document.createElement('div');
                    row.className = 'tix-quote-row';
                    row.dataset.eventId = currentEvent.id;
                    row.dataset.eventTitle = currentEvent.title;
                    row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;padding:10px;border-bottom:1px solid #e5e7eb;align-items:center;';

                    var catSelect = '<select class="tix-row-cat" style="width:100%;">';
                    currentEvent.cats.forEach(function(c){
                        catSelect += '<option value="' + c.index + '" data-price="' + c.price + '">' + escapeHtml(c.name) + ' (' + c.price.toFixed(2).replace('.', ',') + ' €)</option>';
                    });
                    catSelect += '</select>';

                    row.innerHTML =
                        '<div>' +
                            '<div style="font-size:12px;color:#6b7280;margin-bottom:4px;">' + escapeHtml(currentEvent.title) + '</div>' +
                            catSelect +
                        '</div>' +
                        '<input type="number" class="tix-row-qty" value="1" min="1" max="100" style="width:100%;">' +
                        '<input type="text" class="tix-row-price" placeholder="Standardpreis" style="width:100%;font-family:monospace;" title="Sonderpreis pro Ticket — leer = regulärer Phasen-Preis">' +
                        '<button type="button" class="button button-small tix-row-remove" title="Entfernen" style="color:#b91c1c;">×</button>';

                    $items.appendChild(row);

                    row.querySelector('.tix-row-remove').addEventListener('click', function(){
                        row.remove();
                        if (!$items.querySelector('.tix-quote-row')) {
                            $items.innerHTML = '<p id="tix-quote-no-items" style="margin:0;padding:20px;text-align:center;color:#9ca3af;font-style:italic;">Noch keine Tickets ausgewählt.</p>';
                            $noItems = document.getElementById('tix-quote-no-items');
                        }
                    });
                }

                $submit.addEventListener('click', function(){
                    var rows = $items.querySelectorAll('.tix-quote-row');
                    if (!rows.length) { $status.textContent = 'Bitte mindestens ein Ticket hinzufügen.'; $status.style.color = '#dc2626'; return; }

                    var items = [];
                    rows.forEach(function(r){
                        items.push({
                            event_id:    parseInt(r.dataset.eventId),
                            cat_index:   parseInt(r.querySelector('.tix-row-cat').value),
                            qty:         parseInt(r.querySelector('.tix-row-qty').value) || 1,
                            custom_price: r.querySelector('.tix-row-price').value.trim(),
                        });
                    });

                    $submit.disabled = true;
                    $status.textContent = 'Wird erstellt…'; $status.style.color = '#6b7280';

                    var fd = new FormData();
                    fd.append('action', 'tix_create_quote');
                    fd.append('nonce', nonce);
                    fd.append('items', JSON.stringify(items));
                    fd.append('first_name', document.getElementById('tix-quote-fn').value);
                    fd.append('last_name',  document.getElementById('tix-quote-ln').value);
                    fd.append('email',      document.getElementById('tix-quote-email').value);
                    fd.append('phone',      document.getElementById('tix-quote-phone').value);
                    fd.append('note',       document.getElementById('tix-quote-note').value);
                    fd.append('expiry_days', document.getElementById('tix-quote-expiry').value);

                    fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            $submit.disabled = false;
                            if (!res.success) { $status.textContent = '✗ ' + (res.data && res.data.message || 'Fehler'); $status.style.color = '#dc2626'; return; }
                            $status.textContent = '';
                            $url.value = res.data.url;
                            $result.style.display = 'block';
                            // Liste neu laden
                            setTimeout(function(){ location.reload(); }, 2000);
                        })
                        .catch(function(){ $submit.disabled = false; $status.textContent = 'Netzwerkfehler.'; $status.style.color = '#dc2626'; });
                });

                $copy.addEventListener('click', function(){ copy($url.value, $copy); });
                document.querySelectorAll('.tix-quote-copy-btn').forEach(function(b){
                    b.addEventListener('click', function(){ copy(b.dataset.url, b); });
                });
                document.querySelectorAll('.tix-quote-delete-btn').forEach(function(b){
                    b.addEventListener('click', function(){
                        if (!confirm('Vorbestellung wirklich löschen?')) return;
                        var fd = new FormData();
                        fd.append('action', 'tix_delete_quote');
                        fd.append('nonce', nonce);
                        fd.append('id', b.dataset.id);
                        fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
                            .then(function(r){ return r.json(); })
                            .then(function(res){ if (res.success) location.reload(); });
                    });
                });

                function copy(text, btn) {
                    navigator.clipboard.writeText(text).then(function(){
                        var orig = btn.textContent;
                        btn.textContent = '✓ Kopiert';
                        setTimeout(function(){ btn.textContent = orig; }, 1500);
                    });
                }
                function escapeHtml(s) {
                    var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML;
                }
            })();
            </script>
        </div>
        <?php
    }
}

if (!function_exists('session_id_safe')) {
    function session_id_safe() {
        if (!session_id() && !headers_sent()) @session_start();
        return session_id() ?: 'anon';
    }
}
