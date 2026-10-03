<?php
/**
 * Vermittlung geteilter Events auf der Plattform (z. B. evendis.de).
 *
 * Die App spricht weiter nur mit der Plattform (gleiche Routen, gleiche Antwortformate):
 * TIX_App_Checkout leitet Angebot, Bestellung und Status für geteilte Events an diese
 * Klasse weiter, wenn die Quelle im Partner-Verzeichnis für den Verkauf freigeschaltet ist
 * und das Event das Häkchen „über die Plattform verkaufen“ trägt.
 *
 *  - Angebot/Bestellung/Status: Server zu Server an die Partner-API der Quelle
 *    (Zahlung, Preise, Gebühren und Tickets beim Veranstalter).
 *  - Vermittlungs-Datensatz je Bestellung: Tabelle {prefix}tix_partner_orders, IDs ab
 *    800000001 (getrennt von tix_orders). Die App bekommt die lokale ID + den lokalen Schlüssel.
 *  - Ticket-Spiegel nach Zahlung: je Quell-Ticket ein tix_ticket mit gleichem Code,
 *    _tix_ticket_mirror=1 – erscheint ohne App-Änderung unter „Meine Tickets“. Storno bleibt
 *    sichtbar (_tix_ticket_status=cancelled, Post bleibt veröffentlicht).
 *  - Einlass an der Plattform ist für Spiegel-Tickets gesperrt (gilt beim Einlass der Quelle).
 *  - Webhooks der Quelle: POST /partner/webhook (HMAC mit dem API Key der Quelle);
 *    zusätzlich holt ein Cron offene/aktuelle Bestellungen nach.
 */
if (!defined('ABSPATH')) exit;

class TIX_Partner_Broker {

    const NS         = 'tixomat/v1';
    const TABLE      = 'tix_partner_orders';
    const DB_VERSION = '1';
    const ID_START   = 800000001;
    const PAID       = ['completed', 'processing'];
    const FINAL      = ['completed', 'processing', 'cancelled', 'failed', 'refunded'];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 5);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('tix_partner_broker_poll', [__CLASS__, 'poll']);
        add_action('init', function () {
            if (!wp_next_scheduled('tix_partner_broker_poll') && get_option('tix_partner_orders_db') === self::DB_VERSION) {
                wp_schedule_event(time() + 300, 'tix_quarter_hour', 'tix_partner_broker_poll');
            }
        }, 20);
    }

    public static function cron_schedules($s) {
        $s['tix_quarter_hour'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Alle 15 Minuten (Tixomat)'];
        return $s;
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/partner/webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'webhook'],
            'permission_callback' => '__return_true', // Signaturprüfung im Callback
        ]);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Tabelle anlegen, sobald es Partner gibt (auf Quellseiten bleibt alles unberührt). */
    public static function maybe_install() {
        if (get_option('tix_partner_orders_db') === self::DB_VERSION) return;
        if (!class_exists('TIX_Partners') || empty(TIX_Partners::all())) return;
        global $wpdb;
        $t = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_key varchar(40) NOT NULL DEFAULT '',
            partner varchar(64) NOT NULL DEFAULT '',
            event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_order_key varchar(64) NOT NULL DEFAULT '',
            source_order_number varchar(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            billing_email varchar(190) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'creating',
            total decimal(10,2) NOT NULL DEFAULT 0,
            payment_method varchar(64) NOT NULL DEFAULT '',
            payment_method_title varchar(190) NOT NULL DEFAULT '',
            payment_url text NULL,
            idem varchar(64) NOT NULL DEFAULT '',
            data longtext NULL,
            note text NULL,
            created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            synced datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY partner_source (partner, source_order_id),
            KEY billing_email (billing_email),
            KEY idem (idem),
            KEY status (status)
        ) {$charset};");
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) return;
        // Eigener ID-Bereich, damit GET /customer/orders/{id} vermittelte Bestellungen erkennt
        $max = intval($wpdb->get_var("SELECT MAX(id) FROM {$t}"));
        if ($max < self::ID_START) {
            $wpdb->query("ALTER TABLE {$t} AUTO_INCREMENT = " . intval(self::ID_START));
        }
        update_option('tix_partner_orders_db', self::DB_VERSION, false);
    }

    // ──────────────────────────────────────────
    //  Zuordnung
    // ──────────────────────────────────────────

    /** Partner, über den dieses geteilte Event verkauft werden darf – sonst null. */
    public static function partner_for_event($event_id) {
        $event_id = intval($event_id);
        if (!class_exists('TIX_Partners') || get_post_meta($event_id, '_tix_syndicated', true) !== '1') return null;
        if (get_post_meta($event_id, '_tix_partner_sales', true) !== '1') return null;
        if (get_option('tix_partner_orders_db') !== self::DB_VERSION) return null;
        $p = TIX_Partners::get((string) get_post_meta($event_id, '_tix_source_partner', true));
        return TIX_Partners::sales_ready($p) ? $p : null;
    }

    public static function is_broker_id($id) {
        return intval($id) >= self::ID_START && get_option('tix_partner_orders_db') === self::DB_VERSION;
    }

    private static function row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", intval($id)));
    }

    private static function update_row($id, array $fields) {
        global $wpdb;
        $fields['updated'] = current_time('mysql');
        $wpdb->update(self::table(), $fields, ['id' => intval($id)]);
    }

    public static function attach_user($id, $user_id) {
        self::update_row($id, ['user_id' => intval($user_id)]);
    }

    // ──────────────────────────────────────────
    //  Partner-API der Quelle aufrufen
    // ──────────────────────────────────────────

    private static function call(array $p, $method, $route, array $body = null, array $query = []) {
        $url = untrailingslashit($p['api_base']) . $route;
        if ($query) $url = add_query_arg(array_map('rawurlencode', $query), $url);
        $args = [
            'method'  => $method,
            'timeout' => 25,
            'headers' => [
                'Content-Type'      => 'application/json',
                'Accept'            => 'application/json',
                'X-Tix-Partner-Id'  => TIX_Partners::platform_id(),
                'X-Tix-Partner-Key' => $p['key_out'],
            ],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            error_log('[TIX Partner] ' . $p['id'] . ' ' . $route . ': ' . $resp->get_error_message());
            return new WP_Error('tix_partner_unreachable', 'Der Veranstalter ist gerade nicht erreichbar. Bitte versuche es gleich noch einmal.', ['status' => 502]);
        }
        $code = intval(wp_remote_retrieve_response_code($resp));
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code >= 200 && $code < 300 && is_array($data)) return $data;
        // Fehler der Quelle (z. B. ausverkauft, Gutschein ungültig) an die App durchreichen
        if ($code >= 400 && $code < 500 && is_array($data) && !empty($data['code']) && $code !== 401 && $code !== 403) {
            $extra = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
            return new WP_Error((string) $data['code'], (string) ($data['message'] ?? 'Fehler beim Veranstalter.'), ['status' => $code] + $extra);
        }
        error_log('[TIX Partner] ' . $p['id'] . ' ' . $route . ': HTTP ' . $code . ' ' . substr((string) wp_remote_retrieve_body($resp), 0, 300));
        return new WP_Error('tix_partner_error', 'Der Ticketverkauf des Veranstalters ist gerade nicht verfügbar.', ['status' => 502]);
    }

    private static function syndicated_payload($event_id, $p) {
        $info = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::syndicated_info($event_id) : null;
        return is_array($info) ? $info : ['site' => $p['name'], 'checkout_url' => '', 'sale_via_app' => true, 'terms_url' => $p['terms_url']];
    }

    // ──────────────────────────────────────────
    //  Angebot
    // ──────────────────────────────────────────

    /** POST /customer/cart/quote für ein geteiltes Event – Antwort im Format der App-Kasse. */
    public static function quote(array $p, $event_id, WP_REST_Request $req, $user) {
        $items = $req->get_param('items');
        $data = self::call($p, 'POST', '/partner/quote', [
            'event_id' => intval(get_post_meta($event_id, '_tix_source_id', true)),
            'items'    => is_array($items) ? $items : [],
            'coupon'   => (string) ($req->get_param('coupon') ?? ''),
        ]);
        if (is_wp_error($data)) return $data;
        $legal = isset($data['legal']) && is_array($data['legal']) ? $data['legal'] : [];
        unset($data['legal']);
        $data['event_id']   = intval($event_id);
        $data['guest']      = !$user;
        $data['billing']    = $user ? TIX_App_Checkout::billing_prefill($user) : null;
        $data['syndicated'] = self::syndicated_payload($event_id, $p);
        if (empty($data['syndicated']['terms_url']) && !empty($legal['terms_url'])) {
            $data['syndicated']['terms_url'] = (string) $legal['terms_url'];
        }
        $data['seller'] = [
            'name'           => (string) ($legal['seller'] ?? $p['name']),
            'terms_url'      => (string) ($data['syndicated']['terms_url'] ?? ''),
            'privacy_url'    => (string) ($legal['privacy_url'] ?? ''),
            'revocation_url' => (string) ($legal['revocation_url'] ?? ''),
            'notice'         => sprintf('Der Kauf erfolgt beim Veranstalter %s. Deine Daten werden dafür an ihn übermittelt; %s vermittelt nur.', $p['name'], get_bloginfo('name')),
        ];
        return rest_ensure_response($data);
    }

    // ──────────────────────────────────────────
    //  Bestellung
    // ──────────────────────────────────────────

    /** Bestellung bei der Quelle anlegen. Liefert die lokale Vermittlungs-ID oder WP_Error. */
    public static function create_order(array $p, $event_id, WP_REST_Request $req, array $billing, $user, $idem) {
        global $wpdb;
        $idem = md5((string) $idem);
        // Gleiche Anfrage noch einmal (z. B. Netzabbruch) → bestehende Vermittlung
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE idem = %s AND source_order_id > 0 LIMIT 1", $idem));
        if ($existing) return intval($existing);

        $now = current_time('mysql');
        $wpdb->insert(self::table(), [
            'order_key'       => 'tix_' . wp_generate_password(16, false),
            'partner'         => $p['id'],
            'event_id'        => intval($event_id),
            'source_event_id' => intval(get_post_meta($event_id, '_tix_source_id', true)),
            'user_id'         => $user ? intval($user->ID) : 0,
            'billing_email'   => $billing['email'],
            'status'          => 'creating',
            'idem'            => $idem,
            'created'         => $now,
            'updated'         => $now,
        ]);
        $id = intval($wpdb->insert_id);
        if ($id < self::ID_START) {
            if ($id) $wpdb->delete(self::table(), ['id' => $id]);
            return new WP_Error('tix_order_failed', 'Bestellung konnte nicht erstellt werden.', ['status' => 500]);
        }

        $items = $req->get_param('items');
        $data = self::call($p, 'POST', '/partner/orders', [
            'event_id'          => intval(get_post_meta($event_id, '_tix_source_id', true)),
            'items'             => is_array($items) ? $items : [],
            'coupon'            => (string) ($req->get_param('coupon') ?? ''),
            'billing'           => $billing,
            'payment_method'    => sanitize_text_field((string) ($req->get_param('payment_method') ?? '')),
            'idempotency_token' => 'p' . $idem,
            'partner_ref'       => (string) $id,
        ]);
        if (is_wp_error($data) || empty($data['order']['id'])) {
            $wpdb->delete(self::table(), ['id' => $id]);
            return is_wp_error($data) ? $data : new WP_Error('tix_order_failed', 'Bestellung konnte nicht erstellt werden.', ['status' => 502]);
        }
        self::update_row($id, [
            'source_order_id'     => intval($data['order']['id']),
            'source_order_key'    => (string) $data['order']['key'],
            'source_order_number' => (string) ($data['order']['order_number'] ?? ''),
            'payment_url'         => (string) ($data['payment_url'] ?? ''),
        ]);
        self::apply_snapshot(self::row($id), $data);
        return $id;
    }

    /** Stand der Quelle übernehmen: Status, Summe, Zahlart und Ticket-Spiegel. */
    private static function apply_snapshot($row, array $snap) {
        if (!$row || empty($snap['order']) || intval($snap['order']['id']) !== intval($row->source_order_id)) return;
        $o = $snap['order'];
        $status = sanitize_key((string) ($o['status'] ?? $row->status));
        self::update_row($row->id, [
            'status'               => $status,
            'total'                => round(floatval($o['total'] ?? 0), 2),
            'payment_method'       => sanitize_text_field((string) ($o['payment_method'] ?? '')),
            'payment_method_title' => sanitize_text_field((string) ($o['payment_method_title'] ?? '')),
            'source_order_number'  => sanitize_text_field((string) ($o['order_number'] ?? $row->source_order_number)),
            'data'                 => wp_json_encode($snap),
            'synced'               => current_time('mysql'),
        ]);
        $tickets = isset($snap['tickets']) && is_array($snap['tickets']) ? $snap['tickets'] : [];
        self::mirror(self::row($row->id), $tickets, in_array($status, self::PAID, true));
    }

    // ──────────────────────────────────────────
    //  Ticket-Spiegel
    // ──────────────────────────────────────────

    private static function find_mirror($partner, $source_ticket_id) {
        $ids = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'cancelled'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_ticket_source_partner', 'value' => (string) $partner],
                ['key' => '_tix_ticket_source_ticket_id', 'value' => (string) intval($source_ticket_id)],
            ],
        ]);
        return $ids ? intval($ids[0]) : 0;
    }

    /**
     * Spiegel anlegen (nur wenn bezahlt) bzw. Status/Check-in/Inhaber nachführen.
     * Spiegel-Posts bleiben veröffentlicht – auch storniert – damit „Meine Tickets“ sie zeigt.
     */
    private static function mirror($row, array $tickets, $paid) {
        if (!$row) return;
        $event_id = intval($row->event_id);
        $p = TIX_Partners::get($row->partner);
        $site = $p ? $p['name'] : (string) get_post_meta($event_id, '_tix_source_site', true);
        foreach ($tickets as $t) {
            $sid  = intval($t['ticket_id'] ?? 0);
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($t['code'] ?? '')));
            if (!$sid || $code === '') continue;
            $mid = self::find_mirror($row->partner, $sid);
            if (!$mid) {
                if (!$paid) continue;
                // Gleicher Code schon als eigenes Ticket vorhanden? Spiegel trotzdem anlegen
                // (Einlass hier ist gesperrt), aber protokollieren.
                if (class_exists('TIX_Tickets') && ($dup = TIX_Tickets::get_ticket_by_code($code))) {
                    $note = sprintf('Code %s existiert hier bereits (Ticket #%d).', $code, is_object($dup) ? $dup->ID : intval($dup));
                    error_log('[TIX Partner] Vermittlung #' . $row->id . ': ' . $note);
                    self::update_row($row->id, ['note' => trim((string) $row->note . "\n" . $note)]);
                }
                $mid = wp_insert_post([
                    'post_type'   => 'tix_ticket',
                    'post_status' => 'publish',
                    'post_title'  => $code,
                    'post_author' => intval($row->user_id) ?: 1,
                ]);
                if (!$mid || is_wp_error($mid)) continue;
                $mid = intval($mid);
                update_post_meta($mid, '_tix_ticket_mirror', 1);
                update_post_meta($mid, '_tix_ticket_source_partner', (string) $row->partner);
                update_post_meta($mid, '_tix_ticket_source_ticket_id', $sid);
                update_post_meta($mid, '_tix_ticket_source_site', $site);
                update_post_meta($mid, '_tix_ticket_code', $code);
                update_post_meta($mid, '_tix_ticket_order_id', intval($row->id));
                update_post_meta($mid, '_tix_ticket_event_id', $event_id);
                update_post_meta($mid, '_tix_ticket_event_name', get_the_title($event_id));
                update_post_meta($mid, '_tix_ticket_cat_name', sanitize_text_field((string) ($t['category'] ?? 'Ticket')));
                update_post_meta($mid, '_tix_ticket_price', round(floatval($t['price'] ?? 0), 2));
            }
            $status = sanitize_key((string) ($t['status'] ?? 'valid')) ?: 'valid';
            $email  = sanitize_email((string) ($t['owner_email'] ?? '')) ?: (string) $row->billing_email;
            update_post_meta($mid, '_tix_ticket_status', $status);
            update_post_meta($mid, '_tix_ticket_checked_in', !empty($t['checked_in']) ? 1 : 0);
            update_post_meta($mid, '_tix_ticket_checkin_time', sanitize_text_field((string) ($t['checkin_time'] ?? '')));
            update_post_meta($mid, '_tix_ticket_owner_email', $email);
            update_post_meta($mid, '_tix_ticket_owner_name', sanitize_text_field((string) ($t['owner_name'] ?? '')));
            if (!empty($t['seat'])) update_post_meta($mid, '_tix_ticket_seat_id', sanitize_text_field((string) $t['seat']));
            // Spiegel bleibt sichtbar (auch wenn jemand den Post-Status ändert)
            if (get_post_status($mid) !== 'publish') wp_update_post(['ID' => $mid, 'post_status' => 'publish']);
        }
    }

    /** Spiegel-Ticket? (Einlass gilt nur an der Quelle) */
    public static function is_mirror($ticket_id) {
        return (string) get_post_meta(intval($ticket_id), '_tix_ticket_mirror', true) === '1';
    }

    /** Hinweis für Einlass-Ansichten: „Ticket gilt beim Einlass von …“ */
    public static function mirror_notice($ticket_id) {
        $site = (string) get_post_meta(intval($ticket_id), '_tix_ticket_source_site', true);
        return 'Ticket gilt beim Einlass von ' . ($site !== '' ? $site : 'dem Veranstalter') . '.';
    }

    // ──────────────────────────────────────────
    //  Status + Antwort an die App
    // ──────────────────────────────────────────

    /** Stand bei der Quelle nachfragen (höchstens alle 5 s je Bestellung). */
    private static function refresh($row, $force = false) {
        if (!$row || !$row->source_order_id) return $row;
        if (!$force && $row->synced && strtotime($row->synced) > strtotime(current_time('mysql')) - 5) return $row;
        $p = TIX_Partners::get($row->partner);
        if (!$p || $p['api_base'] === '' || $p['key_out'] === '') return $row;
        $data = self::call($p, 'GET', '/partner/orders/' . intval($row->source_order_id), null, ['key' => (string) $row->source_order_key]);
        if (!is_wp_error($data)) self::apply_snapshot($row, $data);
        return self::row($row->id);
    }

    /** Antwort im Format von GET/POST /customer/orders (App-Vertrag). */
    public static function order_response($id, array $extra = []) {
        $row = self::row($id);
        if (!$row) return new WP_Error('tix_order_missing', 'Bestellung nicht gefunden.', ['status' => 404]);
        $status = (string) $row->status === 'creating' ? 'pending' : (string) $row->status;
        $paid   = in_array($status, self::PAID, true);
        $tickets = [];
        if ($paid && class_exists('TIX_App_Checkout')) {
            $ids = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => ['publish', 'private'],
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'AND',
                    ['key' => '_tix_ticket_order_id', 'value' => (string) intval($row->id)],
                    ['key' => '_tix_ticket_mirror', 'value' => '1'],
                ],
            ]);
            foreach ($ids as $tid) {
                $t = TIX_App_Checkout::ticket_from_post($tid);
                if ($t) $tickets[] = $t;
            }
        }
        $p = TIX_Partners::get($row->partner);
        return rest_ensure_response($extra + [
            'ok'    => true,
            'order' => [
                'id'                   => intval($row->id),
                'order_number'         => (string) $row->source_order_number,
                'key'                  => (string) $row->order_key,
                'billing_email'        => (string) $row->billing_email,
                'guest'                => intval($row->user_id) <= 0,
                'status'               => $status,
                'paid'                 => $paid,
                'total'                => round(floatval($row->total), 2),
                'currency'             => 'EUR',
                'payment_method'       => (string) $row->payment_method,
                'payment_method_title' => (string) $row->payment_method_title,
                'payment_url'          => $paid ? '' : (string) $row->payment_url,
                'created'              => (string) $row->created,
                'tickets'              => $tickets,
                // Zusatz: Verkauf beim Veranstalter (Bestätigungs-Mail kommt von dort)
                'seller'               => ['name' => $p ? $p['name'] : '', 'mail_from_seller' => true],
            ],
        ]);
    }

    /** GET /customer/orders/{id} für vermittelte Bestellungen (Konto oder ?key=). */
    public static function status($id, WP_REST_Request $req, $user) {
        $row = self::row($id);
        if (!$row) return new WP_Error('tix_order_missing', 'Bestellung nicht gefunden.', ['status' => 404]);
        $mine = $user && (
            (intval($row->user_id) > 0 && intval($row->user_id) === intval($user->ID))
            || strcasecmp((string) $row->billing_email, (string) $user->user_email) === 0
        );
        if (!$mine) {
            $key = sanitize_text_field((string) ($req->get_param('key') ?? ''));
            if ($key === '' || !hash_equals((string) $row->order_key, $key)) {
                return new WP_Error($user ? 'rest_forbidden' : 'rest_not_logged_in', $user ? 'Keine Berechtigung.' : 'Authentifizierung erforderlich.', ['status' => $user ? 403 : 401]);
            }
        }
        if (!in_array((string) $row->status, self::FINAL, true)) {
            self::refresh($row);
        }
        return self::order_response($id);
    }

    // ──────────────────────────────────────────
    //  Webhook der Quelle + Nachholen per Cron
    // ──────────────────────────────────────────

    /** POST /partner/webhook */
    public static function webhook(WP_REST_Request $req) {
        $p = class_exists('TIX_Partners') ? TIX_Partners::find_by_kid((string) $req->get_header('X-Tix-Partner-Kid')) : null;
        $body = (string) $req->get_body();
        if (!$p || !TIX_Partners::verify($body, (string) $req->get_header('X-Tix-Partner-Signature'), $p['key_in'])) {
            return new WP_Error('tix_partner_auth', 'Ungültige Signatur.', ['status' => 401]);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['id']) || empty($data['order']['id'])) {
            return new WP_Error('tix_partner_payload', 'Ungültige Daten.', ['status' => 400]);
        }
        $seen = 'tix_pwh_' . md5($p['id'] . '|' . $data['id']);
        if (get_transient($seen)) return rest_ensure_response(['ok' => true, 'duplicate' => true]);

        $row = self::row(intval($data['partner_ref'] ?? 0));
        if (!$row || $row->partner !== $p['id'] || intval($row->source_order_id) !== intval($data['order']['id'])) {
            // Unbekannt (z. B. Bestellung direkt bei der Quelle) → bestätigen, nicht wiederholen lassen
            return rest_ensure_response(['ok' => true, 'ignored' => true]);
        }
        self::apply_snapshot($row, $data);
        set_transient($seen, 1, DAY_IN_SECONDS);
        return rest_ensure_response(['ok' => true]);
    }

    /** Offene Bestellungen (3 Tage) und bezahlte mit bevorstehendem Event regelmäßig nachholen. */
    public static function poll() {
        if (get_option('tix_partner_orders_db') !== self::DB_VERSION) return;
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results(
            "SELECT * FROM {$t}
             WHERE source_order_id > 0 AND (
                (status IN ('creating','pending','on-hold') AND created > DATE_SUB(NOW(), INTERVAL 3 DAY))
                OR (status IN ('completed','processing') AND synced < DATE_SUB(NOW(), INTERVAL 6 HOUR) AND created > DATE_SUB(NOW(), INTERVAL 120 DAY))
             )
             ORDER BY synced ASC LIMIT 25"
        );
        foreach ((array) $rows as $row) {
            if (in_array($row->status, self::PAID, true)) {
                $date = (string) get_post_meta(intval($row->event_id), '_tix_date_start', true);
                if ($date !== '' && strtotime($date . ' 23:59') < current_time('timestamp') - DAY_IN_SECONDS) {
                    self::update_row($row->id, ['synced' => current_time('mysql')]); // vergangen: nicht mehr nachholen
                    continue;
                }
            }
            self::refresh($row, true);
        }
    }
}
