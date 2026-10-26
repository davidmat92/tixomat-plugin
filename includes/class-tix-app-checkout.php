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
 * Routen (Namespace tixomat/v1; Kunden-Token `X-Tix-Token` optional – Gäste
 * bestellen ohne Konto, wie im Web-Checkout):
 *   POST /customer/cart/quote        Kategorien + Preise/Gebühren/Steuer/Zahlarten
 *   POST /customer/orders            Bestellung anlegen und Zahlung starten
 *                                    (Gast: billing.email Pflicht; optional
 *                                    create_account + password → Konto + Token)
 *   GET  /customer/orders/{id}       Status + Tickets: eigenes Konto oder ?key=order_key
 *
 * Die Rechnungsadresse wird wie im Web-Formular abgefragt (Vor-/Nachname, Firma
 * optional, Straße, PLZ, Ort, Land, Telefon optional); bei angemeldeten Kunden
 * kommt die E-Mail aus dem Konto und die Adresse wird dort gemerkt (billing_*).
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Checkout {

    const NS        = 'tixomat/v1';
    const TOKEN_TTL = 6 * HOUR_IN_SECONDS;
    const MAX_QTY   = 20;

    /**
     * Partner-Bestellung (Quelle): gesetzt von TIX_Partner_API während Angebot/Bestellung
     * für eine Plattform, ['id' => Plattform, 'ref' => Vermittlungs-ID]. Dann gilt die
     * Ratenbegrenzung je Partner (nicht je IP) und die Bestellung wird markiert.
     */
    public static $partner = null;

    /** Länder wie im Web-Checkout (Reihenfolge = Anzeige) */
    const COUNTRIES = [
        'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'NL' => 'Niederlande',
        'BE' => 'Belgien', 'LU' => 'Luxemburg', 'FR' => 'Frankreich', 'PL' => 'Polen',
        'DK' => 'Dänemark', 'CZ' => 'Tschechien', 'IT' => 'Italien', 'ES' => 'Spanien',
        'GB' => 'Vereinigtes Königreich',
    ];

    /** Rechnungsdaten zum Vorbelegen: Konto + zuletzt genutzte Adresse (User-Meta billing_*) */
    public static function billing_prefill($user) {
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
        // Gast: E-Mail aus dem Formular (Bestätigung + Tickets gehen dorthin)
        $email = $user ? (string) $user->user_email : sanitize_email($get('email', 120));
        if (!$user && !is_email($email)) {
            return new WP_Error('tix_billing', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        }
        $b = [
            'first_name' => $get('first_name', 60),
            'last_name'  => $get('last_name', 60),
            'company'    => $get('company', 120),
            'address_1'  => $get('address_1', 160),
            'postcode'   => $get('postcode', 16),
            'city'       => $get('city', 80),
            'country'    => strtoupper($get('country', 2)),
            'phone'      => $get('phone', 40),
            'email'      => $email,
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

    /**
     * Rechnungsadresse aus dem Profil speichern (POST /auth/profile {billing}) –
     * ohne Pflichtfeld-Prüfung, nur bereinigt; Land muss bekannt sein.
     */
    public static function save_billing($user, array $in) {
        $get = function ($key, $max = 120) use ($in) {
            return mb_substr(trim(sanitize_text_field((string) ($in[$key] ?? ''))), 0, $max);
        };
        $country = strtoupper($get('country', 2));
        $b = [
            'first_name' => $get('first_name', 60),
            'last_name'  => $get('last_name', 60),
            'company'    => $get('company', 120),
            'address_1'  => $get('address_1', 160),
            'postcode'   => $get('postcode', 16),
            'city'       => $get('city', 80),
            'country'    => isset(self::COUNTRIES[$country]) ? $country : 'DE',
            'phone'      => $get('phone', 40),
        ];
        foreach ($b as $k => $v) update_user_meta($user->ID, 'billing_' . $k, $v);
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
        // Zahlarten in /info neu lesen, sobald sich die Einstellungen ändern
        add_action('update_option_tix_settings', function () { delete_transient('tix_info_payment_methods'); });
        add_action('add_option_tix_settings', function () { delete_transient('tix_info_payment_methods'); });
    }

    public static function register_routes() {
        // Angebot und Bestellung auch ohne Konto (Gastbestellung wie im Web-Checkout)
        register_rest_route(self::NS, '/customer/cart/quote', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'quote'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/customer/orders', [
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'create'],
                'permission_callback' => '__return_true',
            ],
            // Bestellliste des Kontos (Website „Meine Bestellungen“)
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list_orders'],
                'permission_callback' => [__CLASS__, 'check_customer'],
            ],
        ]);
        register_rest_route(self::NS, '/giftcards', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'giftcards'],
            'permission_callback' => '__return_true',
        ]);
        // Ticket umschreiben (eigenes Ticket, Event erlaubt es) → {ok, name, count}
        register_rest_route(self::NS, '/customer/tickets/(?P<id>\d+)/transfer', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'transfer_ticket'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
        register_rest_route(self::NS, '/customer/orders/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'status'],
            'permission_callback' => '__return_true', // Konto oder ?key=order_key (siehe status())
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

    /** Geteiltes Event (Event-Verteilung von einer anderen Tixomat-Seite)? */
    public static function is_syndicated($event_id) {
        return get_post_meta(intval($event_id), '_tix_syndicated', true) === '1';
    }

    /**
     * Herkunft eines geteilten Events für die App: {site, checkout_url}, sonst null.
     * Tickets gibt es bei der Quelle; die evendis-Eventseite leitet dorthin weiter.
     */
    public static function syndicated_info($event_id) {
        $event_id = intval($event_id);
        if (!self::is_syndicated($event_id)) return null;
        $checkout = (string) get_post_meta($event_id, '_tix_source_checkout', true);
        if ($checkout === '') $checkout = (string) get_post_meta($event_id, '_tix_source_url', true);
        $broker = class_exists('TIX_Partner_Broker') ? TIX_Partner_Broker::partner_for_event($event_id) : null;
        return [
            'site'         => (string) (get_post_meta($event_id, '_tix_source_site', true) ?: 'Veranstalter'),
            'checkout_url' => $checkout,
            // true = die App verkauft über die Vermittlung (Zahlung beim Veranstalter)
            'sale_via_app' => (bool) $broker,
            'terms_url'    => $broker ? (string) $broker['terms_url'] : '',
        ];
    }

    /** Online-Verkauf für das Event möglich? (wie der Ticket-Selector der Event-Seite) */
    private static function sale_open($event_id) {
        if (get_post_status($event_id) !== 'publish') return false;
        // Geteilte Events verkauft die Quelle, nie die eigene Kasse
        if (self::is_syndicated($event_id)) return false;
        if (get_post_meta($event_id, '_tix_tickets_enabled', true) !== '1') return false;
        // Mehr-Veranstalter-Modus: Veranstalter noch nicht freigegeben bzw. gesperrt
        if (class_exists('TIX_Org_Approval') && !TIX_Org_Approval::event_allowed($event_id)) return false;
        // Vorverkauf noch nicht gestartet / beendet (wie die Ticketauswahl der Website)
        if (class_exists('TIX_Cart_Pricing') && TIX_Cart_Pricing::presale_error($event_id)) return false;
        $status = get_post_meta($event_id, '_tix_status', true);
        return !in_array($status, ['cancelled', 'postponed', 'past', 'sold_out', 'presale_closed'], true);
    }

    private static function syndicated_error($event_id) {
        $info = self::syndicated_info($event_id);
        return new WP_Error('tix_syndicated', 'Tickets gibt es beim Veranstalter (' . $info['site'] . ').', [
            'status'     => 409,
            'syndicated' => $info,
        ]);
    }

    private static function is_public_category(array $cat) {
        if (!empty($cat['admin_only'])) return false;
        if (isset($cat['online']) && (string) $cat['online'] === '0') return false;
        if (!empty($cat['offline_ticket'])) return false;
        // Gutschein-Kategorien sind im Web-Selector versteckt, in der App aber die Kaufbasis
        if (!empty($cat['hidden']) && empty($cat['gift_card'])) return false;
        return true;
    }

    private static function is_gift_event($event_id) {
        return get_post_meta(intval($event_id), '_tix_system_giftcard_event', true) === '1';
    }

    /** Geschenkgutschein-Einstellungen (wie [tix_giftcards]) */
    private static function giftcard_config($organizer_id = 0) {
        if (!class_exists('TIX_Giftcards')) return ['enabled' => false];
        if ($organizer_id) {
            // Je Veranstalter (Modul „giftcards“): eigenes Gutschein-Event, Antwort mit organizer_id
            $st = TIX_Giftcards::organizer_settings($organizer_id);
            if (empty($st['enabled'])) return ['enabled' => false];
            $event_id = TIX_Giftcards::ensure_organizer_event($organizer_id);
        } else {
            $st = TIX_Giftcards::get_settings();
            if (empty($st['enabled'])) return ['enabled' => false];
            $event_id = TIX_Giftcards::system_event_id();
        }
        if (!$event_id) return ['enabled' => false];
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        $fixed = [];
        $free_index = -1;
        foreach ((array) $cats as $i => $c) {
            if (!is_array($c) || empty($c['gift_card'])) continue;
            if (!empty($c['gift_free_amount'])) { $free_index = intval($i); continue; }
            $fixed[] = ['index' => intval($i), 'amount' => round(floatval($c['price'] ?? 0), 2), 'name' => (string) ($c['name'] ?? '')];
        }
        return [
            'enabled'        => true,
            'event_id'       => $event_id,
            'amounts'        => $fixed,
            'free_amount'    => !empty($st['free_amount']) && $free_index >= 0,
            'free_index'     => $free_index,
            'min'            => intval($st['free_min'] ?? TIX_Giftcards::MIN_FREE),
            'max'            => intval($st['free_max'] ?? TIX_Giftcards::MAX_FREE),
            'validity_years' => intval($st['validity_years']),
        ] + ($organizer_id ? ['organizer_id' => intval($organizer_id)] : []);
    }

    /** GET /giftcards[?organizer=ID] – öffentlich (Kachel nur zeigen, wenn aktiv) */
    public static function giftcards(WP_REST_Request $req) {
        $oid = (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) ? absint($req->get_param('organizer')) : 0;
        return rest_ensure_response(['ok' => true] + self::giftcard_config($oid));
    }

    private static function price_for($event_id, $index, array $cat) {
        if (class_exists('TIX_Cart_Pricing')) {
            $p = TIX_Cart_Pricing::category_price($event_id, $index);
            if ($p !== null) return $p;
        }
        if (class_exists('TIX_Dynamic_Pricing')) {
            $dyn = TIX_Dynamic_Pricing::get_dynamic_price(intval($event_id), intval($index));
            if ($dyn !== null) return floatval($dyn);
        }
        return floatval($cat['price'] ?? 0);
    }

    /** Öffentlicher Zugriff auf die Kategorien (Event-Katalog /public/events). */
    public static function public_categories($event_id) {
        return self::categories(intval($event_id));
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
            $phase  = class_exists('TIX_Cart_Pricing') ? TIX_Cart_Pricing::active_phase($event_id, $i) : null;
            $bundle = (class_exists('TIX_Cart_Pricing') && empty($cat['gift_card'])) ? TIX_Cart_Pricing::bundle($event_id, $i) : null;
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
                'gift_card'          => !empty($cat['gift_card']),
                'gift_free_amount'   => !empty($cat['gift_free_amount']),
                // Knappheits-Hinweis wie auf der Website („Nur noch 5 verfügbar!“ / Text des Veranstalters), '' = keiner
                'low_stock_text'     => class_exists('TIX_Event_Extras') ? TIX_Event_Extras::low_stock_text($event_id, $cat) : '',
                // Preisphase (z. B. Early Bird): Name, gültig bis (Y-m-d, einschließlich)
                'phase'              => $phase ? [
                    'name'  => (string) ($phase['name'] ?? ''),
                    'until' => (string) ($phase['until'] ?? ''),
                ] : null,
                // Paket „kaufe X, zahle Y“: Bestellung mit items[] {index, bundle: true, qty: Anzahl Pakete}
                'bundle'             => $bundle ? [
                    'buy'           => $bundle['buy'],
                    'pay'           => $bundle['pay'],
                    'name'          => $bundle['label'] !== '' ? $bundle['label'] : $bundle['buy'] . 'er-Paket ' . (string) ($cat['name'] ?? 'Ticket'),
                    'package_price' => round($price * $bundle['pay'], 2),
                    'max_packages'  => $stock >= 0 ? min(self::MAX_QTY, intdiv($stock, $bundle['buy'])) : self::MAX_QTY,
                ] : null,
            ];
        }
        return $out;
    }

    /**
     * Extras (Specials) eines Events, wie die Ticketauswahl der Website sie zeigt
     * (Einstellung „Specials“ aktiv + am Event „in der Ticketauswahl“ oder „im Checkout“).
     * Bestellung mit items[] {special_id, qty}.
     */
    public static function specials($event_id) {
        if (!class_exists('TIX_Specials') || !tix_get_settings('specials_enabled')) return [];
        if (get_post_meta($event_id, '_tix_specials_in_selector', true) !== '1'
            && get_post_meta($event_id, '_tix_specials_in_checkout', true) !== '1') return [];
        $out = [];
        foreach (TIX_Specials::get_event_specials($event_id) as $sp) {
            $price = floatval($sp['price']);
            if ($price <= 0) continue; // wie Web: Specials ohne Preis sind nicht kaufbar
            $remaining = -1;
            if (intval($sp['qty']) > 0) {
                $remaining = max(0, intval($sp['qty']) - intval(TIX_Specials::get_sold_count($sp['special_id'], $event_id)));
            }
            $value = floatval($sp['value']);
            $out[] = [
                'special_id'         => intval($sp['special_id']),
                'name'               => (string) $sp['name'],
                'description'        => wp_strip_all_tags((string) $sp['description']),
                'price'              => round($price, 2),
                'value'              => $value > $price ? round($value, 2) : null, // regulärer Wert (Ersparnis)
                'image'              => $sp['image'] ? (string) wp_get_attachment_image_url($sp['image'], 'medium') : '',
                'quantity_available' => $remaining,
                'sold_out'           => $remaining === 0,
                'max_per_order'      => $remaining >= 0 ? min(self::MAX_QTY, $remaining) : self::MAX_QTY,
            ];
        }
        return $out;
    }

    /** Kombi-Tickets eines Events für die App; Bestellung mit items[] {combo_id, qty} */
    public static function combos($event_id) {
        if (!class_exists('TIX_Cart_Pricing')) return [];
        $out = [];
        foreach (TIX_Cart_Pricing::combos($event_id) as $c) {
            $out[] = [
                'combo_id'      => $c['id'],
                'name'          => $c['label'],
                'price'         => round($c['price'], 2),
                'regular_price' => $c['original_sum'],
                'sold_out'      => !$c['in_stock'],
                'max_per_order' => min(self::MAX_QTY, $c['max']),
                'parts'         => array_map(function ($it) {
                    return ['event_id' => $it['event_id'], 'event_title' => $it['event_title'], 'category' => $it['name']];
                }, $c['items']),
            ];
        }
        return $out;
    }

    /** Warenkorb im Format des Web-Checkouts aus der Auswahl der App bauen. */
    private static function build_cart($event_id, array $items, $hold_token = '') {
        if (self::is_syndicated($event_id)) {
            return self::syndicated_error($event_id);
        }
        if (class_exists('TIX_Org_Approval') && ($e = TIX_Org_Approval::sale_error($event_id))) {
            return $e;
        }
        if (class_exists('TIX_Cart_Pricing') && ($e = TIX_Cart_Pricing::presale_error($event_id))) {
            return $e;
        }
        if (!self::sale_open($event_id)) {
            return self::error('tix_sale_closed', 'Für dieses Event ist aktuell kein Online-Verkauf möglich.');
        }
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || empty($cats)) {
            return self::error('tix_no_tickets', 'Für dieses Event gibt es keine Online-Tickets.');
        }
        $event_title = get_the_title($event_id);
        $cart = ['items' => [], 'coupon' => null];
        $merged = [];   // normale Tickets: je Kategorie eine Zeile
        $gifts  = [];   // Gutscheine: nie mergen (verschiedene Beträge)
        $bundles = [];  // Pakete: je Kategorie eine Zeile, qty = Anzahl Pakete
        $specials = []; // Extras: special_id => Menge
        $seats    = []; // Saalplan: Sitz-IDs (Halter = hold_token)
        $combos   = []; // Kombi-Tickets: combo_id => Anzahl
        foreach ($items as $item_no => $it) {
            if (!is_array($it)) continue;
            if (!empty($it['combo_id'])) {
                $cq = intval($it['qty'] ?? ($it['quantity'] ?? 0));
                if ($cq > 0) $combos[(string) $it['combo_id']] = ($combos[(string) $it['combo_id']] ?? 0) + $cq;
                continue;
            }
            if (!empty($it['seats']) && is_array($it['seats'])) {
                foreach ($it['seats'] as $sid) $seats[] = sanitize_text_field((string) $sid);
                continue;
            }
            if (!empty($it['special_id'])) {
                $sq = intval($it['qty'] ?? ($it['quantity'] ?? 0));
                if ($sq > 0) $specials[intval($it['special_id'])] = ($specials[intval($it['special_id'])] ?? 0) + $sq;
                continue;
            }
            $idx = intval($it['index'] ?? -1);
            $qty = intval($it['qty'] ?? ($it['quantity'] ?? 0));
            if ($qty <= 0) continue;
            $cat = (isset($cats[$idx]) && is_array($cats[$idx])) ? $cats[$idx] : null;
            // Guthaben aufladen (TIX_Wallet_Balance, 1.38.432): eigene Position, Betrag fest bzw. Wunschbetrag
            if ($cat && !empty($cat['wallet_topup']) && class_exists('TIX_Wallet_Balance')) {
                $wi = TIX_Wallet_Balance::cart_item($event_id, $idx, $cat, $qty, $it['custom_amount'] ?? 0);
                if (is_wp_error($wi)) return $wi;
                $cart['items'][] = $wi;
                continue;
            }
            if ($cat && !empty($cat['gift_card'])) {
                // Geschenk-Angaben (1.38.463): gift {recipient_name, message ≤ 200, from_name}
                $gift = class_exists('TIX_Giftcard_Gift') ? TIX_Giftcard_Gift::sanitize($it['gift'] ?? null, 'items[' . intval($item_no) . '].gift') : null;
                if (is_wp_error($gift)) return $gift;
                $gifts[] = ['index' => $idx, 'qty' => $qty, 'custom_amount' => round(floatval($it['custom_amount'] ?? 0), 2), 'gift' => $gift];
                continue;
            }
            if (!empty($it['bundle'])) {
                $bundles[$idx] = ($bundles[$idx] ?? 0) + $qty;
                continue;
            }
            $merged[$idx] = ($merged[$idx] ?? 0) + $qty;
        }
        foreach ($gifts as $g) {
            $idx = $g['index'];
            $cat = $cats[$idx];
            if (!self::is_public_category($cat)) {
                return self::error('tix_category', 'Dieser Gutschein ist nicht verfügbar.');
            }
            $name   = sanitize_text_field($cat['name'] ?? 'Gutschein');
            $locked = 0;
            if (!empty($cat['gift_free_amount'])) {
                $amount = $g['custom_amount'];
                list($min, $max) = class_exists('TIX_Giftcards') ? TIX_Giftcards::free_limits_for_event(intval($event_id)) : [10, 500];
                if ($amount < $min || $amount > $max) {
                    return self::error('tix_gift_amount', sprintf('Gutschein-Betrag muss zwischen %d und %d € liegen.', $min, $max));
                }
                $price  = $amount;
                $locked = 1;
                $name   = 'Gutschein ' . rtrim(rtrim(number_format($amount, 2, ',', '.'), '0'), ',') . ' €';
            } else {
                $price = self::price_for($event_id, $idx, $cat);
            }
            if ($g['qty'] > self::MAX_QTY) {
                return self::error('tix_max_qty', sprintf('Maximal %d Gutscheine pro Bestellung.', self::MAX_QTY));
            }
            $cart['items'][] = array_filter([
                'event_id'     => intval($event_id),
                'cat_index'    => intval($idx),
                'name'         => $name,
                'event_title'  => $event_title,
                'price'        => $price,
                'qty'          => intval($g['qty']),
                'locked_price' => $locked ?: null,
                'meta'         => array_filter(['gift' => 1, 'cat_index' => intval($idx), 'gift_info' => $g['gift'] ?? null], function ($v) { return $v !== null; }),
            ], function ($v) { return $v !== null; });
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
        foreach ($bundles as $idx => $packages) {
            $cat = (isset($cats[$idx]) && is_array($cats[$idx])) ? $cats[$idx] : null;
            $b   = ($cat && self::is_public_category($cat) && class_exists('TIX_Cart_Pricing')) ? TIX_Cart_Pricing::bundle($event_id, $idx) : null;
            if (!$b) {
                return self::error('tix_bundle', 'Dieses Paket ist nicht verfügbar.');
            }
            $name    = $b['label'] !== '' ? $b['label'] : $b['buy'] . 'er-Paket ' . (string) ($cat['name'] ?? 'Ticket');
            $tickets = $packages * $b['buy'];
            $stock   = isset($cat['stock']) ? intval($cat['stock']) : -1;
            // Einzeltickets derselben Kategorie teilen sich den Bestand
            $taken   = $merged[$idx] ?? 0;
            if ($stock >= 0 && $tickets + $taken > $stock) {
                return self::error('tix_stock', sprintf('%s: nur noch %d Tickets verfügbar.', $name, max(0, $stock - $taken)));
            }
            if ($packages > self::MAX_QTY) {
                return self::error('tix_max_qty', sprintf('Maximal %d Pakete pro Bestellung.', self::MAX_QTY));
            }
            $cart['items'][] = [
                'event_id'    => intval($event_id),
                'cat_index'   => intval($idx),
                'name'        => sanitize_text_field($name),
                'event_title' => $event_title,
                'price'       => self::price_for($event_id, $idx, $cat) * $b['pay'] / $b['buy'],
                'qty'         => $tickets,
                'meta'        => ['bundle' => 1],
            ];
        }
        if ($seats) {
            $holder = class_exists('TIX_Seatmap') ? TIX_Seatmap::app_holder($hold_token) : '';
            if ($holder === '') return self::error('tix_hold_token', 'Sitzplätze bitte zuerst über /customer/seats/hold reservieren (hold_token fehlt).');
            $sm   = TIX_Seatmap::event_seatmap($event_id);
            $data = $sm ? TIX_Seatmap::data($sm) : null;
            if (!$data) return self::error('tix_no_seatmap', 'Für dieses Event gibt es keinen Saalplan.');
            $by_section = [];
            foreach (array_unique($seats) as $sid) {
                $sec = TIX_Seatmap::section_of($data, $sid);
                if (!$sec) return self::error('tix_seat', 'Platz ' . TIX_Seatmap::seat_label($sid) . ' ist nicht buchbar.');
                $by_section[$sec['id']][] = $sid;
            }
            foreach ($by_section as $sec_seats) {
                $line = TIX_Native_Checkout::seat_line($event_id, $sec_seats, $holder);
                if (is_string($line)) return self::error('tix_seat_taken', $line, 409);
                $cart['items'][] = $line;
            }
        }
        foreach ($combos as $cid => $cq) {
            if ($cq > self::MAX_QTY) return self::error('tix_max_qty', sprintf('Maximal %d Kombi-Tickets pro Bestellung.', self::MAX_QTY));
            $lines = TIX_Cart_Pricing::combo_lines($event_id, sanitize_text_field($cid), $cq);
            if (is_string($lines)) return self::error('tix_combo', $lines, 409);
            foreach ($lines as $l) $cart['items'][] = $l;
        }
        if ($specials) {
            $offer = [];
            foreach (self::specials($event_id) as $sp) $offer[$sp['special_id']] = $sp;
            foreach ($specials as $sid => $sq) {
                $sp = $offer[$sid] ?? null;
                if (!$sp) return self::error('tix_special', 'Dieses Extra ist nicht verfügbar.');
                if ($sp['quantity_available'] >= 0 && $sq > $sp['quantity_available']) {
                    return self::error('tix_stock', $sp['quantity_available'] > 0
                        ? sprintf('%s: nur noch %d verfügbar.', $sp['name'], $sp['quantity_available'])
                        : $sp['name'] . ' ist ausverkauft.');
                }
                if ($sq > self::MAX_QTY) {
                    return self::error('tix_max_qty', sprintf('Maximal %d Stück pro Extra und Bestellung.', self::MAX_QTY));
                }
                $cart['items'][] = [
                    'event_id'    => intval($event_id),
                    'cat_index'   => -1, // -1 = kein Kategorie-Slot (wie Web-Kasse)
                    'name'        => sanitize_text_field($sp['name']),
                    'event_title' => $event_title,
                    'price'       => $sp['price'],
                    'qty'         => $sq,
                    'meta'        => ['special' => 1, 'special_id' => $sid],
                ];
            }
        }
        if (empty($cart['items'])) {
            return self::error('tix_empty', 'Bitte mindestens ein Ticket wählen.');
        }
        // Preise zentral: Phase, Paket, Mengenrabatt (gleich wie Web-Kasse)
        if (class_exists('TIX_Cart_Pricing')) {
            $cart['items'] = TIX_Cart_Pricing::reprice($cart['items']);
            if ($cerr = TIX_Cart_Pricing::combo_error($cart['items'])) return self::error('tix_combo', $cerr, 409);
        }
        return $cart;
    }

    /**
     * Gutschein (manuell oder Auto-Apply) anwenden – gleiche Logik wie im Web. Der seitenweite Web-Auto-Gutschein
     * (`web_auto`) gilt nur außerhalb der App (1.38.561): Kanal über TIX_App_Coupons::is_app_request – die Apps schicken
     * bei quote/orders `X-Tix-Client: app` (Core `ApiConstants.appClientHeaders`), ältere Builds sind am User-Agent `Dart/`
     * erkennbar; Websites (Next.js-Server, Browser) schicken nichts → Web.
     */
    private static function apply_coupon(array $cart, $code, $email = '') {
        $code = sanitize_text_field((string) $code);
        if (method_exists('TIX_Native_Checkout', 'app_prepare_cart')) {
            $web = !(class_exists('TIX_App_Coupons') && TIX_App_Coupons::is_app_request());
            return TIX_Native_Checkout::app_prepare_cart($cart, $code, sanitize_email((string) $email), $web);
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
            $meta = (array) ($it['meta'] ?? []);
            $row  = [
                'event_id'   => intval($it['event_id']), // Kombi-Zeilen können zu Partner-Events gehören
                'index'      => intval($it['cat_index']),
                'name'       => (string) $it['name'],
                'qty'        => intval($it['qty']),
                'unit_price' => round(floatval($it['price']), 2),
                'total'      => $line,
                // Preis vor Mengenrabatt und Rabatt in Prozent (null = kein Mengenrabatt)
                'list_price'     => isset($meta['list_price']) ? round(floatval($meta['list_price']), 2) : null,
                'group_discount' => isset($meta['group_discount']) ? floatval($meta['group_discount']) : null,
                'bundle'         => null,
                'special_id'     => !empty($meta['special_id']) ? intval($meta['special_id']) : null,
                'seats'          => !empty($meta['seats']) ? array_values((array) $meta['seats']) : null,
                'combo'          => is_array($meta['combo'] ?? null) ? ['combo_id' => (string) $meta['combo']['combo_id'], 'group_id' => (string) $meta['combo']['group_id'], 'name' => (string) $meta['combo']['label']] : null,
                // Gutschein-Position: Geschenk-Angaben wie gesendet (null = keine bzw. kein Gutschein)
                'gift'           => !empty($meta['gift']) ? (is_array($meta['gift_info'] ?? null) ? $meta['gift_info'] : null) : null,
            ];
            if (!empty($meta['bundle']) && class_exists('TIX_Cart_Pricing')
                && ($b = TIX_Cart_Pricing::bundle(intval($it['event_id']), intval($it['cat_index'])))) {
                $row['bundle'] = ['buy' => $b['buy'], 'pay' => $b['pay'], 'packages' => intdiv(intval($it['qty']), $b['buy'])];
            }
            $lines[] = $row;
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
            // Ersparnis durch Mengenrabatt (bereits in subtotal eingerechnet, nur Anzeige)
            'group_discount' => class_exists('TIX_Cart_Pricing') ? TIX_Cart_Pricing::group_savings($cart['items']) : 0.0,
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
    /** IDs der Online-Zahlarten (ohne Vorkasse) – z. B. für Anzahlungen von Tischreservierungen */
    public static function online_methods() {
        return array_values(array_filter(array_column(self::payment_methods(false), 'id'), function ($id) {
            return $id !== 'bank' && $id !== 'free';
        }));
    }

    /** Aktive Zahlarten [{id, title}] für /info (Vertrauens-Leiste im Web), 10 Minuten zwischengespeichert. */
    public static function info_methods() {
        $c = get_transient('tix_info_payment_methods');
        if (is_array($c)) return $c;
        $out = [];
        foreach (self::payment_methods(false) as $m) $out[] = ['id' => (string) $m['id'], 'title' => html_entity_decode(wp_strip_all_tags((string) $m['title']), ENT_QUOTES, 'UTF-8')];
        set_transient('tix_info_payment_methods', $out, 10 * MINUTE_IN_SECONDS);
        return $out;
    }

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
        if (self::$partner) return false; // Partner-API begrenzt selbst je Partner
        $who  = get_current_user_id() ?: (class_exists('TIX_App_Account') ? 'ip_' . TIX_App_Account::client_ip() : 'ip_' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $key  = 'tix_app_rl_' . $bucket . '_' . md5((string) $who);
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

    /** Geschenkgutschein-Felder eines Ticket-Posts (Code, Wert, Restguthaben, Gültigkeit). */
    public static function gift_fields($ticket_post_id) {
        $code = (string) get_post_meta(intval($ticket_post_id), '_tix_ticket_gift_code', true);
        if ($code === '' || !class_exists('TIX_Giftcards')) return [];
        $card = TIX_Giftcards::get_card($code);
        return [
            'gift_code'    => $code,
            'gift_value'   => $card ? round(floatval($card['value'] ?? 0), 2) : 0,
            'gift_balance' => $card ? round(floatval($card['balance'] ?? 0), 2) : 0,
            'gift_expires' => $card ? (string) ($card['expires'] ?? '') : '',
        ] + ($card && class_exists('TIX_Giftcard_Gift') ? TIX_Giftcard_Gift::fields($card) : ['gift_recipient_name' => null, 'gift_message' => null, 'gift_from_name' => null, 'gift_organizer_id' => 0, 'gift_organizer_name' => '', 'gift_pdf' => '', 'gift_recipient' => null, 'gift_from' => null]);
    }

    /**
     * Ticket-Post (CPT `tix_ticket`) im Format von GET /customer/tickets.
     * Auf kitchenklub.de gibt es keine Ticket-Tabelle `tix_tickets` – die
     * Posts sind die Quelle der Wahrheit (Meta `_tix_ticket_*`, Check-in setzt
     * `_tix_ticket_checked_in`/`_tix_ticket_status = used`).
     */
    public static function ticket_from_post($post) {
        $post = is_numeric($post) ? get_post(intval($post)) : $post;
        if (!$post || $post->post_type !== 'tix_ticket') return null;
        $id = intval($post->ID);
        $m  = function ($k) use ($id) { return get_post_meta($id, $k, true); };
        $event_id = intval($m('_tix_ticket_event_id'));
        $event    = $event_id ? get_post($event_id) : null;
        $cat      = (string) $m('_tix_ticket_cat_name');
        if ($cat === '' && $event_id) {
            $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
            $ci   = $m('_tix_ticket_cat_index');
            if (is_array($cats) && $ci !== '' && $ci !== false && isset($cats[intval($ci)]['name'])) {
                $cat = (string) $cats[intval($ci)]['name'];
            }
        }
        $status  = (string) ($m('_tix_ticket_status') ?: 'valid');
        $checked = (string) $m('_tix_ticket_checked_in') === '1' || $status === 'used';
        return [
            'id'             => $id,
            'code'           => (string) $m('_tix_ticket_code'),
            'event_id'       => $event_id,
            'event_title'    => $event ? $event->post_title : (string) $m('_tix_ticket_event_name'),
            'event_date'     => $event_id ? (string) get_post_meta($event_id, '_tix_date_start', true) : '',
            'event_time'     => $event_id ? (string) get_post_meta($event_id, '_tix_time_start', true) : '',
            'event_location' => $event_id ? (string) get_post_meta($event_id, '_tix_location', true) : '',
            'event_address'  => $event_id ? (string) get_post_meta($event_id, '_tix_address', true) : '',
            'event_doors'    => $event_id ? (string) get_post_meta($event_id, '_tix_time_doors', true) : '',
            // Flyer groß für Karte/Vollbild (1.38.532, vorher medium = 300 px), klein für Listen
            'event_image'    => $event_id ? (get_the_post_thumbnail_url($event_id, 'large') ?: '') : '',
            'event_image_small' => $event_id ? (get_the_post_thumbnail_url($event_id, 'medium') ?: '') : '',
            'category'       => $cat !== '' ? $cat : 'Ticket',
            'seat'           => (string) $m('_tix_ticket_seat_id'),
            'status'         => $status,
            'checked_in'     => $checked,
            'checkin_time'   => (string) $m('_tix_ticket_checkin_time'),
            'order_id'       => intval($m('_tix_ticket_order_id')),
            'order_number'   => class_exists('TIX_Order') ? TIX_Order::number_for($m('_tix_ticket_order_id')) : null,
            'price'          => floatval($m('_tix_ticket_price')),
            'purchased'      => (string) $post->post_date,
            'owner_name'     => (string) $m('_tix_ticket_owner_name'),
            // Sponsor-Logo auf dem Ticket und ob die App „Ticket umschreiben“ anbieten darf
            'sponsor'        => ($event_id && class_exists('TIX_Event_Extras')) ? TIX_Event_Extras::ticket_sponsor($event_id) : null,
            'can_transfer'   => class_exists('TIX_Ticket_Transfer') && TIX_Ticket_Transfer::can_transfer($id),
            // Apple/Google Wallet (signierte Links), null wenn Wallet aus oder Spiegel-Ticket
            'wallet'         => (string) $m('_tix_ticket_mirror') === '1' ? null : self::wallet_links($id),
            // Online-Ticket zum Teilen/Öffnen (Kunden-Adresse, Token wie in den Mails); '' bei Spiegel-Tickets (gelten bei der Quelle)
            'online_url'     => self::online_url($id, (string) $m('_tix_ticket_mirror') === '1'),
        ] + self::mirror_fields($id) + self::gift_fields($id);
    }

    /** Link auf das Online-Ticket (?tix_dl=…) auf der Kunden-Adresse; '' ohne Code oder bei Spiegel-Tickets. */
    public static function online_url($ticket_post_id, $mirror = false) {
        if ($mirror || !class_exists('TIX_Tickets') || (string) get_post_meta($ticket_post_id, '_tix_ticket_code', true) === '') return '';
        TIX_Tickets::ensure_download_token($ticket_post_id);
        $url = (string) TIX_Tickets::get_download_url($ticket_post_id);
        return $url !== '' && class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to($url) : $url;
    }

    /** Wallet-Links eines Ticket-Posts: {apple_url, google_url} ('' = Plattform nicht eingerichtet) oder null (Wallet aus). */
    public static function wallet_links($ticket_post_id) {
        if (!class_exists('TIX_Wallet') || !TIX_Wallet::is_master_enabled()) return null;
        $apple  = TIX_Wallet::is_apple_ready()  ? TIX_Customer_URL::to(TIX_Wallet::get_apple_pass_url($ticket_post_id))  : '';
        $google = TIX_Wallet::is_google_ready() ? TIX_Customer_URL::to(TIX_Wallet::get_google_save_url($ticket_post_id)) : '';
        if ($apple === '' && $google === '') return null;
        return ['apple_url' => $apple, 'google_url' => $google];
    }

    /** Rechnungs-PDF (signiert über den Order-Key) oder '' ohne erstellte Rechnung. */
    public static function invoice_url($order_id, $order_key = '') {
        if (!class_exists('TIX_Invoicing') || !TIX_Invoicing::has_downloadable_invoice(intval($order_id))) return '';
        return TIX_Customer_URL::to(TIX_Invoicing::get_download_url(intval($order_id), (string) $order_key));
    }

    /**
     * GET /customer/orders?page=&per_page= (≤ 50) – Bestellungen des Kontos, neueste zuerst:
     * eigene (customer_id bzw. Vermittlung user_id) und – nur mit per Code bestätigter
     * E-Mail – frühere Gastbestellungen mit dieser E-Mail. Enthält vermittelte Bestellungen
     * geteilter Events (`seller`).
     */
    public static function list_orders(WP_REST_Request $req) {
        global $wpdb;
        $user = wp_get_current_user();
        $per_page = max(1, min(50, intval($req->get_param('per_page') ?: 20)));
        $page     = max(1, intval($req->get_param('page') ?: 1));
        $email    = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : '';
        $cap      = 500;

        $rows = [];
        $t = $wpdb->prefix . 'tix_orders';
        if ($email !== '') {
            $native = $wpdb->get_results($wpdb->prepare(
                "SELECT id, date_created AS created FROM {$t} WHERE status <> 'trash' AND (customer_id = %d OR billing_email = %s) ORDER BY date_created DESC LIMIT %d",
                intval($user->ID), $email, $cap
            ));
        } else {
            $native = $wpdb->get_results($wpdb->prepare(
                "SELECT id, date_created AS created FROM {$t} WHERE status <> 'trash' AND customer_id = %d ORDER BY date_created DESC LIMIT %d",
                intval($user->ID), $cap
            ));
        }
        foreach ((array) $native as $r) $rows[] = ['type' => 'native', 'id' => intval($r->id), 'created' => (string) $r->created];

        $bt = $wpdb->prefix . 'tix_partner_orders';
        if (class_exists('TIX_Partner_Broker') && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $bt)) === $bt) {
            $broker = $email !== ''
                ? $wpdb->get_results($wpdb->prepare("SELECT id, created FROM {$bt} WHERE status NOT IN ('creating', 'failed') AND (user_id = %d OR billing_email = %s) ORDER BY created DESC LIMIT %d", intval($user->ID), $email, $cap))
                : $wpdb->get_results($wpdb->prepare("SELECT id, created FROM {$bt} WHERE status NOT IN ('creating', 'failed') AND user_id = %d ORDER BY created DESC LIMIT %d", intval($user->ID), $cap));
            foreach ((array) $broker as $r) $rows[] = ['type' => 'broker', 'id' => intval($r->id), 'created' => (string) $r->created];
        }

        usort($rows, function ($a, $b) { return strcmp($b['created'], $a['created']) ?: ($b['id'] <=> $a['id']); });
        $total = count($rows);
        $out = [];
        foreach (array_slice($rows, ($page - 1) * $per_page, $per_page) as $r) {
            $o = $r['type'] === 'native' ? self::order_summary($r['id']) : self::broker_summary($r['id']);
            if ($o) $out[] = $o;
        }
        return rest_ensure_response([
            'ok'       => true,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'has_more' => $page * $per_page < $total,
            'orders'   => $out,
        ]);
    }

    /** Event-Kurzinfo für die Bestellliste. */
    /** Event einer Bestellung {id, slug, title, date_start, url} oder null. */
    public static function order_event_info($event_id) {
        $event_id = intval($event_id);
        if (!$event_id || !($p = get_post($event_id))) return null;
        return [
            'id'         => $event_id,
            'slug'       => (string) $p->post_name,
            'title'      => html_entity_decode((string) $p->post_title, ENT_QUOTES, 'UTF-8'),
            'date_start' => (string) get_post_meta($event_id, '_tix_date_start', true),
            'url'        => TIX_Customer_URL::to((string) get_permalink($event_id)),
        ];
    }

    private static function order_summary($order_id) {
        global $wpdb;
        $o = self::order_row($order_id);
        if (!$o) return null;
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id, quantity FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d ORDER BY id ASC", intval($order_id)
        ));
        $event_id = 0;
        foreach ((array) $items as $it) { if (intval($it->event_id)) { $event_id = intval($it->event_id); break; } }
        $tickets = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ('_tix_ticket_order_id', '_tix_order_id') AND meta_value = %s",
            (string) intval($order_id)
        ));
        $status = (string) $o->status;
        return [
            'id'           => intval($o->id),
            'order_number' => (string) $o->order_number,
            'key'          => (string) $o->order_key,
            'status'       => $status,
            'paid'         => in_array($status, ['completed', 'processing'], true),
            'total'        => round(floatval($o->total), 2),
            'currency'     => 'EUR',
            'created'      => (string) $o->date_created,
            'event'        => self::order_event_info($event_id),
            'ticket_count' => $tickets,
            'invoice_url'  => self::invoice_url(intval($o->id), (string) $o->order_key),
            // alle Rechnungen/Belege/Korrekturen (Rechnungs-Kern, 1.38.487), [] ohne
            'invoices'     => class_exists('TIX_Invoices') ? TIX_Invoices::order_summary(intval($o->id), (string) $o->order_key) : [],
            'seller'       => null,
        ];
    }

    private static function broker_summary($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_partner_orders WHERE id = %d", intval($id)));
        if (!$row) return null;
        $status = (string) $row->status;
        $tickets = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->postmeta} x ON x.post_id = m.post_id AND x.meta_key = '_tix_ticket_mirror' AND x.meta_value = '1'
             WHERE m.meta_key = '_tix_ticket_order_id' AND m.meta_value = %s",
            (string) intval($row->id)
        ));
        $p = class_exists('TIX_Partners') ? TIX_Partners::get($row->partner) : null;
        return [
            'id'           => intval($row->id),
            'order_number' => (string) $row->source_order_number,
            'key'          => (string) $row->order_key,
            'status'       => $status,
            'paid'         => in_array($status, ['completed', 'processing'], true),
            'total'        => round(floatval($row->total), 2),
            'currency'     => 'EUR',
            'created'      => (string) $row->created,
            'event'        => self::order_event_info(intval($row->event_id)),
            'ticket_count' => $tickets,
            'invoice_url'  => '', // Rechnung stellt der Veranstalter aus
            'seller'       => ['name' => $p ? (string) $p['name'] : '', 'mail_from_seller' => true],
        ];
    }

    /** Spiegel-Ticket eines geteilten Events: Einlass beim Veranstalter (`source_site`). */
    private static function mirror_fields($ticket_id) {
        if ((string) get_post_meta($ticket_id, '_tix_ticket_mirror', true) !== '1') return [];
        return [
            'mirror'      => true,
            'source_site' => (string) get_post_meta($ticket_id, '_tix_ticket_source_site', true),
        ];
    }

    /** Alle Ticket-Posts einer E-Mail (aktueller Inhaber), neueste zuerst. */
    public static function tickets_for_email($email) {
        $email = sanitize_email((string) $email);
        if ($email === '') return [];
        $posts = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => ['publish', 'private'],
            'posts_per_page' => 300,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_tix_ticket_owner_email', 'value' => $email, 'compare' => '='],
                ['key' => '_tix_email',              'value' => $email, 'compare' => '='],
            ],
        ]);
        $out = [];
        foreach ($posts as $p) {
            $t = self::ticket_from_post($p);
            if ($t) $out[] = $t;
        }
        return $out;
    }

    /**
     * Tickets des Kontos: mit bestätigter E-Mail alle Tickets dieser E-Mail (Käufer oder Inhaber),
     * sonst nur Tickets aus Bestellungen, die im Konto selbst gekauft wurden.
     */
    public static function tickets_for_user($user) {
        if (!$user || empty($user->ID)) return [];
        $email = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : (string) $user->user_email;
        if ($email !== '') return self::tickets_for_email($email);
        global $wpdb;
        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tix_orders WHERE customer_id = %d AND status <> 'trash' ORDER BY id DESC LIMIT 200",
            intval($user->ID)
        ));
        if (!$order_ids) return [];
        $posts = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => ['publish', 'private'],
            'posts_per_page' => 300,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => [['key' => '_tix_ticket_order_id', 'value' => array_map('strval', $order_ids), 'compare' => 'IN']],
        ]);
        $out = [];
        foreach ($posts as $p) {
            $t = self::ticket_from_post($p);
            if ($t) $out[] = $t;
        }
        return $out;
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
                    'event_image'  => $event_id ? get_the_post_thumbnail_url($event_id, 'large') : '',
                    'event_image_small' => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
                    'category'     => $row['category_name'] ?? '',
                    'seat'         => $row['seat_id'] ?? '',
                    'status'       => $row['ticket_status'] ?? 'valid',
                    'checked_in'   => !empty($row['checked_in']),
                    'checkin_time' => $row['checkin_time'] ?? '',
                    'order_id'     => intval($row['order_id'] ?? 0),
                    'order_number' => class_exists('TIX_Order') ? TIX_Order::number_for($row['order_id'] ?? 0) : null,
                    'price'        => floatval($row['ticket_price'] ?? 0),
                    'purchased'    => $row['created_at'] ?? '',
                    'wallet'       => intval($row['ticket_post_id'] ?? 0) ? self::wallet_links(intval($row['ticket_post_id'])) : null,
                ] + self::gift_fields(intval($row['ticket_post_id'] ?? 0));
            }
        }
        if (empty($tickets)) {
            $posts = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_query'     => [
                    'relation' => 'OR',
                    ['key' => '_tix_ticket_order_id', 'value' => (string) intval($order_id)],
                    ['key' => '_tix_order_id',        'value' => (string) intval($order_id)],
                ],
            ]);
            foreach ($posts as $tp) {
                $t = self::ticket_from_post($tp);
                if ($t) $tickets[] = $t;
            }
        }
        return $tickets;
    }

    private static function order_response($order_id, $payment_url = '', array $extra = []) {
        $o = self::order_row($order_id);
        if (!$o) return self::error('tix_order_missing', 'Bestellung nicht gefunden.', 404);
        $status = (string) $o->status;
        $paid   = in_array($status, ['completed', 'processing'], true);
        // Event der Bestellung – auch unbezahlt (Web-Kasse: Rücksprung zur Event-Seite)
        global $wpdb;
        $event_id = intval($wpdb->get_var($wpdb->prepare(
            "SELECT event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d AND event_id > 0 ORDER BY id ASC LIMIT 1", intval($order_id)
        )));
        return rest_ensure_response($extra + [
            'ok'    => true,
            'order' => [
                'id'                   => intval($o->id),
                'order_number'         => (string) $o->order_number,
                'key'                  => (string) $o->order_key,
                'billing_email'        => (string) $o->billing_email,
                'guest'                => intval($o->customer_id) <= 0,
                'status'               => $status,
                'paid'                 => $paid,
                'total'                => round(floatval($o->total), 2),
                'currency'             => 'EUR',
                'payment_method'       => (string) $o->payment_method,
                'payment_method_title' => (string) $o->payment_method_title,
                'payment_url'          => (string) $payment_url,
                'created'              => (string) $o->date_created,
                'event'                => self::order_event_info($event_id),
                'tickets'              => $paid ? self::order_tickets($order_id) : [],
                // Rechnung (PDF), '' solange keine erstellt ist
                'invoice_url'          => self::invoice_url(intval($o->id), (string) $o->order_key),
                'invoices'             => class_exists('TIX_Invoices') ? TIX_Invoices::order_summary(intval($o->id), (string) $o->order_key) : [],
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

        $user = is_user_logged_in() ? wp_get_current_user() : null;
        // Geteiltes Event mit Verkauf über die Vermittlung: Angebot der Quelle
        if (!self::$partner && class_exists('TIX_Partner_Broker') && ($partner = TIX_Partner_Broker::partner_for_event($event_id))) {
            return TIX_Partner_Broker::quote($partner, $event_id, $req, $user);
        }
        $countries = [];
        foreach (self::COUNTRIES as $code => $name) $countries[] = ['code' => $code, 'name' => $name];
        $resp = [
            'ok'              => true,
            'event_id'        => $event_id,
            'sale_open'       => self::sale_open($event_id),
            'syndicated'      => self::syndicated_info($event_id),
            'categories'      => self::categories($event_id),
            // Mengenrabatt-Staffeln {tiers[{min_qty, percent}], combine_bundle, combine_combo, combine_phase} oder null
            'group_discount'  => class_exists('TIX_Cart_Pricing') ? TIX_Cart_Pricing::group_discount($event_id) : null,
            // Saalplan: true → Plätze über /public/events/{id}/seatmap wählen, items[] {seats: [...]} + hold_token
            'seatmap'         => class_exists('TIX_Seatmap') && TIX_Seatmap::event_seatmap($event_id) > 0,
            // Kombi-Tickets: Bestellung mit items[] {combo_id, qty}
            'combos'          => self::combos($event_id),
            // Extras (Specials): Bestellung mit items[] {special_id, qty}
            'specials'        => self::specials($event_id),
            // Vorverkauf {starts_at, started, waitlist_presale, waitlist_soldout}
            'presale'         => class_exists('TIX_Event_Extras') ? TIX_Event_Extras::presale($event_id) : null,
            'totals'          => null,
            'payment_methods' => [],
            'coupon'          => null,
            'guest'           => !$user,
            'billing'         => $user ? self::billing_prefill($user) : null,
            'fields'          => [
                'company'   => !empty(tix_get_settings('show_company_field')),
                'countries' => $countries,
            ],
        ];
        if (!empty($items)) {
            $cart = self::build_cart($event_id, $items, (string) $req->get_param('hold_token'));
            if (is_wp_error($cart)) return $cart;
            // E-Mail für „einmal pro E-Mail“: Konto oder (Gast) optional ?email=
            $email = $user ? (string) $user->user_email : (string) ($req->get_param('email') ?? '');
            // Guthaben aufladen: Konto Pflicht, Limits schon hier, keine Gutscheine
            $topup = class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::cart_topups($cart);
            if ($topup) {
                if ($coupon !== '') return self::error('tix_wallet_coupon', 'Gutscheine gelten nicht für das Aufladen von Guthaben.', 400);
                if ($e = TIX_Wallet_Balance::cart_error($cart, $user)) return $e;
            } else {
                $cart = self::apply_coupon($cart, $coupon, $email);
            }
            $t    = self::totals($cart);
            if ($topup) $resp['wallet_topup'] = TIX_Wallet_Balance::quote_info($cart);
            $resp['totals']          = $t;
            $resp['payment_methods'] = self::payment_methods($t['total'] <= 0);
            // Mit Guthaben bezahlen (1.38.436): erst Gutschein (in totals), dann Guthaben; Rest normal.
            // Seit 1.38.476 kommt `wallet` bei angemeldeten Kunden immer mit (auch ohne use_wallet), damit die App
            // „Guthaben nutzen (x €)“ anbieten kann: selected = use_wallet, can_apply = möglicher Anteil, applied/rest wirksam.
            if (class_exists('TIX_Wallet_Balance') && $user && !$topup && !self::$partner) {
                $use = filter_var($req->get_param('use_wallet'), FILTER_VALIDATE_BOOLEAN);
                $w = TIX_Wallet_Balance::checkout_info($user, $cart, $t['total']);
                $w['can_apply'] = $w['applied'];
                $w['selected'] = $use && $w['applied'] > 0;
                if (!$use) { $w['applied'] = 0.0; $w['rest'] = round(floatval($t['total']), 2); }
                $resp['wallet'] = $w;
                if ($w['selected'] && $w['rest'] <= 0) {
                    $resp['payment_methods'] = [['id' => 'wallet', 'title' => 'Guthaben', 'icon' => '']];
                }
            } elseif (class_exists('TIX_Wallet_Balance') && filter_var($req->get_param('use_wallet'), FILTER_VALIDATE_BOOLEAN)) {
                $resp['wallet'] = TIX_Wallet_Balance::checkout_info($user, $cart, $t['total']) + ['can_apply' => 0.0, 'selected' => false];
            }
            $resp['coupon'] = [
                'code'     => (string) ($cart['coupon']['code'] ?? $coupon),
                'valid'    => !empty($cart['coupon']['discount']),
                'discount' => $t['discount'],
                // Grund, wenn der eingegebene Code nicht gilt (leer = ok bzw. kein Code)
                'message'  => (string) ($cart['coupon_error'] ?? ''),
            ];
            // App-Gutscheine (1.38.431): eingelöst → freundliche Meldung; im Web → app_only + Link in die App
            if (!empty($cart['coupon']['code']) && class_exists('TIX_App_Coupons')) {
                $def = TIX_Native_Checkout::resolve_coupon((string) $cart['coupon']['code']);
                $resp['coupon']['app_only'] = !empty($def['app_only']);
                if ($resp['coupon']['valid'] && ($msg = TIX_App_Coupons::applied_message($def)) !== '') $resp['coupon']['message'] = $msg;
            } elseif (!empty($cart['coupon_app_only'])) {
                $resp['coupon'] += ['app_only' => true, 'error_code' => 'tix_coupon_app_only'] + (array) $cart['coupon_app_only'];
            }
        }
        return rest_ensure_response($resp);
    }

    /** Gast möchte mit der Bestellung ein Konto anlegen: Eingaben prüfen (ohne anzulegen). */
    private static function check_new_account(WP_REST_Request $req, $user, array $billing) {
        if ($user || empty($req->get_param('create_account'))) return false;
        $password = (string) $req->get_param('password');
        if (strlen($password) < 8) {
            return new WP_Error('weak_password', 'Das Passwort muss mindestens 8 Zeichen lang sein.', ['status' => 400, 'field' => 'password']);
        }
        if (email_exists($billing['email'])) {
            return new WP_Error('email_exists', 'Für diese E-Mail-Adresse gibt es bereits ein Konto – bitte melde dich an.', ['status' => 409, 'field' => 'email']);
        }
        return true;
    }

    /**
     * Konto anlegen (wie „Konto anlegen“ im Web-Checkout, aber mit eigenem Passwort →
     * sofort angemeldet, Tickets in der App). Liefert [WP_User, App-Token] oder WP_Error.
     */
    private static function create_new_account(WP_REST_Request $req, array $billing) {
        $username = method_exists('TIX_Native_Checkout', 'generate_username')
            ? TIX_Native_Checkout::generate_username($billing['first_name'], $billing['last_name'], $billing['email'])
            : sanitize_user(strtolower(explode('@', $billing['email'])[0]), true);
        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $billing['email'],
            'user_pass'    => (string) $req->get_param('password'),
            'first_name'   => $billing['first_name'],
            'last_name'    => $billing['last_name'],
            'display_name' => trim($billing['first_name'] . ' ' . $billing['last_name']) ?: $username,
            'role'         => 'subscriber',
        ]);
        if (is_wp_error($user_id)) {
            return new WP_Error('registration_failed', $user_id->get_error_message(), ['status' => 500]);
        }
        if (class_exists('TIX_Customer_Role')) TIX_Customer_Role::assign_to_user($user_id);
        update_user_meta($user_id, '_tix_app_created', current_time('mysql'));
        wp_set_current_user($user_id); // create_order setzt customer_id, Warenkorb landet am Konto
        $user  = wp_get_current_user();
        $token = '';
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'issue_app_token')) {
            $token = TIX_REST_API::issue_app_token($user_id, (string) ($req->get_param('device') ?? ''));
        }
        return [$user, $token];
    }

    /** POST /customer/orders */
    public static function create(WP_REST_Request $req) {
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        if (self::rate_limited('orders', 10, 300)) {
            return self::error('tix_rate_limit', 'Zu viele Bestellversuche. Bitte kurz warten.', 429);
        }

        $token = sanitize_text_field((string) $req->get_param('idempotency_token'));
        if (strlen($token) < 8) return self::error('tix_token', 'idempotency_token fehlt.');
        $who  = self::$partner ? 'partner|' . self::$partner['id'] : ($user ? $user->ID : 'guest');
        $tkey = 'tix_app_order_' . md5($who . '|' . $token);
        $existing = get_transient($tkey);
        if (is_array($existing) && !empty($existing['broker_id']) && class_exists('TIX_Partner_Broker')) {
            return TIX_Partner_Broker::order_response(intval($existing['broker_id']));
        }
        if (is_array($existing) && !empty($existing['order_id'])) {
            return self::order_response(intval($existing['order_id']), (string) ($existing['payment_url'] ?? ''));
        }

        if (empty($req->get_param('accept_terms'))) {
            return self::error('tix_terms', 'Bitte AGB und Widerrufsbelehrung akzeptieren.');
        }

        // Rücksprung nach der Zahlung an die eigene Website (nur freigegebene Hosts)
        $return_url = '';
        $return_raw = $req->get_param('return_url');
        if ($return_raw !== null && $return_raw !== '') {
            $return_url = TIX_Native_Checkout::validate_return_url((string) $return_raw);
            if (is_wp_error($return_url)) return $return_url;
        }

        $event_id = intval($req->get_param('event_id'));
        if (!$event_id || get_post_type($event_id) !== 'event') return self::error('tix_event', 'Event nicht gefunden.', 404);

        // Geteiltes Event mit Verkauf über die Vermittlung: Bestellung bei der Quelle
        $partner = (!self::$partner && class_exists('TIX_Partner_Broker')) ? TIX_Partner_Broker::partner_for_event($event_id) : null;
        if ($partner) {
            $billing = self::read_billing($req, $user);
            if (is_wp_error($billing)) return $billing;
            $wants_account = self::check_new_account($req, $user, $billing);
            if (is_wp_error($wants_account)) return $wants_account;
            $broker_id = TIX_Partner_Broker::create_order($partner, $event_id, $req, $billing, $user, $tkey);
            if (is_wp_error($broker_id)) return $broker_id;
            $extra = [];
            if ($wants_account === true) {
                $acc = self::create_new_account($req, $billing);
                if (!is_wp_error($acc)) {
                    list($user, $new_token) = $acc;
                    TIX_Partner_Broker::attach_user($broker_id, $user->ID);
                    if ($new_token !== '' && method_exists('TIX_REST_API', 'guest_user_payload')) {
                        $extra = ['token' => $new_token, 'user' => TIX_REST_API::guest_user_payload($user), 'account_created' => true];
                    }
                }
            }
            if ($user) self::remember_billing($user, $billing);
            set_transient($tkey, ['broker_id' => $broker_id], self::TOKEN_TTL);
            return TIX_Partner_Broker::order_response($broker_id, $extra);
        }

        $items = $req->get_param('items');
        $hold_token = (string) $req->get_param('hold_token');
        $cart  = self::build_cart($event_id, is_array($items) ? $items : [], $hold_token);
        if (is_wp_error($cart)) return $cart;
        if (class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::cart_topups($cart)) {
            // Guthaben aufladen: Konto Pflicht, Limits erneut prüfen, keine Gutscheine
            if ((string) ($req->get_param('coupon') ?? '') !== '') return self::error('tix_wallet_coupon', 'Gutscheine gelten nicht für das Aufladen von Guthaben.', 400);
            if ($e = TIX_Wallet_Balance::cart_error($cart, $user)) return $e;
        } else {
            $cart = self::apply_coupon($cart, (string) ($req->get_param('coupon') ?? ''));
        }
        // Nur-App-Gutschein außerhalb der App: zuerst melden (vor Zahlart/Adresse), mit Link in die App
        if (!empty($cart['coupon_app_only'])) {
            return new WP_Error('tix_coupon_app_only', (string) $cart['coupon_error'], ['status' => 409] + (array) $cart['coupon_app_only']);
        }
        $t    = self::totals($cart);

        // Mit Guthaben bezahlen (1.38.436): Anteil bis zum Saldo, Rest über die gewählte Zahlart
        $wallet = null;
        if (!self::$partner && class_exists('TIX_Wallet_Balance') && filter_var($req->get_param('use_wallet'), FILTER_VALIDATE_BOOLEAN) && $t['total'] > 0) {
            $wallet = TIX_Wallet_Balance::checkout_info($user, $cart, $t['total']);
            if (empty($wallet['applied'])) $wallet = null;
        }
        $due = $wallet ? round($t['total'] - $wallet['applied'], 2) : $t['total'];

        // Zahlart prüfen
        $payment_method = sanitize_text_field((string) ($req->get_param('payment_method') ?? ''));
        $mollie_method  = '';
        $stripe_method  = '';
        if ($wallet && $due <= 0) {
            $payment_method = 'wallet';
        } elseif ($t['total'] <= 0) {
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

        // Rechnungsadresse wie im Web-Formular (Konto: E-Mail aus dem Konto; Gast: aus dem Formular)
        $billing = self::read_billing($req, $user);
        if (is_wp_error($billing)) return $billing;

        // Gutschein mit der Rechnungs-E-Mail prüfen (z. B. „einmal pro E-Mail“)
        $coupon_in = (string) ($req->get_param('coupon') ?? '');
        if ($coupon_in !== '' && empty($cart['coupon']['code'])) {
            return self::error('tix_coupon', (string) ($cart['coupon_error'] ?: 'Gutscheincode ungültig.'), 409);
        }
        if ($cerr = TIX_Native_Checkout::checkout_coupon_error($cart, $billing['email'])) {
            return self::error('tix_coupon', $cerr, 409);
        }
        // Sitzplätze für die Zahlung sichern
        $seat_holder = class_exists('TIX_Seatmap') ? TIX_Seatmap::app_holder($hold_token) : '';
        if ($seat_holder !== '' && ($serr = TIX_Seatmap::secure_cart($cart['items'], $seat_holder))) {
            return self::error('tix_seat_taken', $serr, 409);
        }

        // Gast: optional gleich ein Konto anlegen (wie „Konto anlegen“ im Web-Checkout,
        // aber mit eigenem Passwort → sofort angemeldet, Tickets in der App)
        $new_token = '';
        $wants_account = self::$partner ? false : self::check_new_account($req, $user, $billing);
        if (is_wp_error($wants_account)) return $wants_account;
        if ($wants_account === true) {
            $acc = self::create_new_account($req, $billing);
            if (is_wp_error($acc)) return $acc;
            list($user, $new_token) = $acc;
        }
        if ($user) self::remember_billing($user, $billing);

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
        // Guthaben-Anteil atomar abbuchen; reicht es nicht mehr (zweite Bestellung zugleich), Bestellung abbrechen
        if ($wallet) {
            $wtx = TIX_Wallet_Balance::pay_order($order_id, $wallet['wallet_id'], TIX_Wallet_Balance::cents($wallet['applied']));
            if (is_wp_error($wtx)) {
                TIX_Native_Checkout::update_order_status($order_id, 'failed', 'wallet');
                return self::error($wtx->get_error_code() === 'tix_wallet_locked' ? 'tix_wallet_locked' : 'tix_wallet_insufficient', 'Dein Guthaben hat sich geändert – bitte prüfe den Betrag und bestelle erneut.', 409);
            }
            global $wpdb;
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}tix_orders SET total = GREATEST(0, ROUND(total - %f, 2)) WHERE id = %d", floatval($wallet['applied']), $order_id));
            if ($payment_method === 'wallet') {
                $wpdb->update($wpdb->prefix . 'tix_orders', ['payment_method' => 'wallet', 'payment_method_title' => 'Guthaben'], ['id' => $order_id]);
            }
        }
        if ($seat_holder !== '') TIX_Seatmap::attach_order($cart['items'], $seat_holder, $order_id);
        update_option('_tix_order_source_' . $order_id, self::$partner ? 'partner:' . self::$partner['id'] : 'app', false);
        if ($return_url !== '') update_post_meta($order_id, TIX_Native_Checkout::META_RETURN_URL, $return_url);
        if (!self::$partner) self::apply_attribution($order_id, $req->get_param('attribution'), $cart);
        // Herkunft: Bestellung über die eigene Domain eines beteiligten Veranstalters (Mails zeigen dann dorthin)
        if (!self::$partner && class_exists('TIX_Org_Domains') && $req->get_param('origin_host')) {
            TIX_Org_Domains::remember_origin($order_id, (string) $req->get_param('origin_host'), array_map('intval', array_column((array) ($cart['items'] ?? []), 'event_id')));
        }

        // Zahlung starten
        if ($payment_method === 'wallet') {
            TIX_Native_Checkout::update_order_status($order_id, 'completed', 'wallet');
            $result = ['success' => true];
        } elseif ($payment_method === 'free') {
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
        $extra = [];
        if ($wallet) $extra['wallet'] = ['applied' => $wallet['applied'], 'rest' => max(0, $due), 'wallet_id' => $wallet['wallet_id']];
        if ($new_token !== '' && $user && method_exists('TIX_REST_API', 'guest_user_payload')) {
            $extra = ['token' => $new_token, 'user' => TIX_REST_API::guest_user_payload($user), 'account_created' => true];
        }
        return self::order_response($order_id, $payment_url, $extra);
    }

    /**
     * Zuordnung einer Website-Bestellung (Feld `attribution`): Promoter-Code wie der
     * Referral-Cookie, UTM wie TIX_Campaign_Tracking, Meta-Kennungen nur mit Einwilligung
     * (`fbp`/`fbc`, von der Website nur nach Zustimmung mitgeschickt). Die Apps senden das
     * Feld nicht → für sie ändert sich nichts.
     */
    private static function apply_attribution($order_id, $attr, array $cart) {
        if (!is_array($attr)) return;
        $clip = function ($v, $len) { return mb_substr(sanitize_text_field((string) $v), 0, $len); };
        // Kanal merken (Bestellquelle bleibt „app“, damit die Apps keinen neuen Wert sehen)
        update_post_meta($order_id, '_tix_order_channel', 'web');

        // Promoter: ein Promo-Code als Gutschein (bereits beim Anlegen zugeordnet) hat Vorrang
        $code = $clip($attr['promoter_code'] ?? '', 64);
        if ($code !== '' && class_exists('TIX_Promoter_DB') && !get_post_meta($order_id, '_tix_promoter_id', true)) {
            $promoter = TIX_Promoter_DB::get_promoter_by_code($code);
            if ($promoter && !empty($promoter->id)) {
                update_post_meta($order_id, '_tix_promoter_id', intval($promoter->id));
                update_post_meta($order_id, '_tix_promoter_attribution', 'referral');
            }
        }

        // Kampagne (überschreibt das „direct“ der REST-Anfrage ohne Cookies)
        $map = [
            'utm_source'   => ['_tix_campaign_source',  50],
            'utm_medium'   => ['_tix_campaign_medium',  100],
            'utm_campaign' => ['_tix_campaign_name',    100],
            'utm_content'  => ['_tix_campaign_content', 100],
        ];
        $any = false;
        foreach ($map as $field => list($meta, $len)) {
            $v = $field === 'utm_source' ? sanitize_key((string) ($attr[$field] ?? '')) : $clip($attr[$field] ?? '', $len);
            $v = mb_substr($v, 0, $len);
            if ($v === '') continue;
            update_post_meta($order_id, $meta, $v);
            $any = true;
        }
        $ref = strtolower(preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($attr['referrer_host'] ?? '')));
        if ($ref !== '') {
            update_post_meta($order_id, '_tix_campaign_referrer', mb_substr($ref, 0, 190));
            if (!$any && get_post_meta($order_id, '_tix_campaign_source', true) === 'direct') {
                update_post_meta($order_id, '_tix_campaign_source', 'referral');
            }
        }

        // Meta Conversions-API: nur mit fbp/fbc (= Einwilligung); dann auch Besucher-IP und
        // Browser für den späteren Kauf-Event speichern (der Webhook kennt sie nicht)
        $fbp = $clip($attr['fbp'] ?? '', 200);
        $fbc = $clip($attr['fbc'] ?? '', 500);
        if ($fbp !== '' || $fbc !== '') {
            update_post_meta($order_id, '_tix_meta_consent', '1');
            if ($fbp !== '') update_post_meta($order_id, '_tix_meta_fbp', $fbp);
            if ($fbc !== '') update_post_meta($order_id, '_tix_meta_fbc', $fbc);
            update_post_meta($order_id, '_tix_meta_client_ip', class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : '');
            update_post_meta($order_id, '_tix_meta_client_ua', mb_substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 400));
        } else {
            update_post_meta($order_id, '_tix_meta_no_consent', '1');
        }
    }

    /** POST /customer/tickets/{id}/transfer {first_name, last_name, email} */
    public static function transfer_ticket(WP_REST_Request $req) {
        if (!class_exists('TIX_Ticket_Transfer')) return self::error('tix_transfer', 'Umschreiben ist nicht verfügbar.', 404);
        if (self::rate_limited('transfer', 10, 300)) return self::error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', 429);
        $id   = intval($req['id']);
        $user = wp_get_current_user();
        $mine = false;
        foreach (self::tickets_for_user($user) as $t) {
            if (intval($t['id']) === $id) { $mine = true; break; }
        }
        if (!$mine) return self::error('tix_not_found', 'Ticket nicht gefunden.', 404);
        $r = TIX_Ticket_Transfer::transfer([$id], sanitize_text_field((string) $req->get_param('first_name')), sanitize_text_field((string) $req->get_param('last_name')), sanitize_email((string) $req->get_param('email')));
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(['ok' => true] + $r);
    }

    /** Raten des Bestellschlüssels (?key=) begrenzen: 20 Fehlversuche je Besucher-IP in 10 min. */
    const ORDER_KEY_FAILS = 20;

    private static function order_key_rl() {
        return 'tix_order_key_fail_' . md5(class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public static function order_key_blocked() {
        return intval(get_transient(self::order_key_rl())) >= self::ORDER_KEY_FAILS;
    }

    public static function order_key_failed() {
        set_transient(self::order_key_rl(), intval(get_transient(self::order_key_rl())) + 1, 600);
    }

    /** GET /customer/orders/{id} – eigene Bestellung (Konto) oder Gast mit ?key=order_key */
    public static function status(WP_REST_Request $req) {
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        $id   = intval($req['id']);
        // Vermittelte Bestellung (geteiltes Event, Zahlung beim Veranstalter)
        if (class_exists('TIX_Partner_Broker') && TIX_Partner_Broker::is_broker_id($id)) {
            return TIX_Partner_Broker::status($id, $req, $user);
        }
        $o    = self::order_row($id);
        if (!$o) return self::error('tix_order_missing', 'Bestellung nicht gefunden.', 404);
        $trusted = $user && class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : '';
        $mine = $user && (
            (intval($o->customer_id) > 0 && intval($o->customer_id) === intval($user->ID))
            || ($trusted !== '' && strcasecmp((string) $o->billing_email, $trusted) === 0)
        );
        if (!$mine) {
            $key = sanitize_text_field((string) ($req->get_param('key') ?? ''));
            if ($key !== '' && self::order_key_blocked()) {
                return self::error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', 429);
            }
            if ($key === '' || !hash_equals((string) $o->order_key, $key)) {
                if ($key !== '') self::order_key_failed();
                return self::error($user ? 'rest_forbidden' : 'rest_not_logged_in', $user ? 'Keine Berechtigung.' : 'Authentifizierung erforderlich.', $user ? 403 : 401);
            }
        }
        return self::order_response($id, '');
    }
}
