<?php
/**
 * Zweitmarkt (Weiterverkauf über unseren Shop, RS1/RS5/RS7, §223).
 *
 * Ablauf: Der Käufer eines Tickets bietet es an (Kunden-Route) → das Angebot erscheint öffentlich (je nach Regel nur bei
 * ausverkaufter Kategorie) → ein Käufer kauft es über die normale Kasse (Position {resale_offer: id}); das Angebot ist
 * dabei gesperrt (bedingtes UPDATE, kein Doppelverkauf) → bei Zahlungseingang wird das alte Ticket ungültig
 * (`_tix_ticket_status = resold`), der Käufer bekommt ein neues Ticket mit neuem Code (normale Ticket-Erstellung),
 * der Bestand bleibt gleich, der Verkäufer bekommt seinen Preis als Teil-Erstattung aufs ursprüngliche Zahlungsmittel
 * (Rückfall: Guthaben, sonst Gutschein).
 *
 * Daten: Tabelle {prefix}tix_resale_offers (Weg 1), Regeln der Seite als Option `tix_resale_settings`, je Veranstalter
 * und Event in TIX_Object_Data (resale_org, resale_event). Am Ticket nur der Anzeige-Merker `_tix_ticket_resale`
 * (open|reserved|sold), damit Ticketlisten ohne Zusatzabfrage auskommen.
 * Reine Regeln: TIX_Resale_Rules. Vertrag: uebergaben/vertrag-zweitmarkt.md.
 */
if (!defined('ABSPATH')) exit;

class TIX_Resale {

    const NS         = 'tixomat/v1';
    const DB_VERSION = '1';
    const OPT_DB     = 'tix_resale_db';
    const OPT_SITE   = 'tix_resale_settings';
    const CRON       = 'tix_resale_cron';
    const TICKET_META = '_tix_ticket_resale';

    /** Für Tests: Zeit festlegen (Unix). */
    public static $now = null;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 6);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('tix_order_completed', [__CLASS__, 'on_order_completed'], 25);
        add_action('tix_order_cancelled', [__CLASS__, 'on_order_cancelled'], 5);
        add_action('tix_ticket_checked_in', [__CLASS__, 'on_ticket_checked_in'], 5);
        add_action(self::CRON, [__CLASS__, 'cron']);
    }

    // ═══════════════ Tabelle ═══════════════

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_resale_offers';
    }

    public static function maybe_install() {
        if (get_option(self::OPT_DB) === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            cat_index int(11) NOT NULL DEFAULT -1,
            organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
            active_ticket bigint(20) unsigned DEFAULT NULL,
            seller_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            seller_email varchar(190) NOT NULL DEFAULT '',
            seller_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            channel varchar(20) NOT NULL DEFAULT '',
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            original_price decimal(10,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'open',
            lock_token varchar(40) NOT NULL DEFAULT '',
            lock_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            locked_until datetime DEFAULT NULL,
            buyer_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            new_ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
            buyer_fee decimal(10,2) NOT NULL DEFAULT 0.00,
            seat_id varchar(100) NOT NULL DEFAULT '',
            payout_method varchar(20) NOT NULL DEFAULT '',
            payout_status varchar(20) NOT NULL DEFAULT '',
            payout_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            payout_ref varchar(100) NOT NULL DEFAULT '',
            payout_tries int(11) NOT NULL DEFAULT 0,
            payout_error varchar(255) NOT NULL DEFAULT '',
            close_reason varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            sold_at datetime DEFAULT NULL,
            closed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY active_ticket (active_ticket),
            KEY event_status (event_id,status),
            KEY ticket (ticket_id),
            KEY lock_order (lock_order_id),
            KEY buyer_order (buyer_order_id),
            KEY organizer (organizer_id),
            KEY payout (status,payout_status)
        ) $c;");
        if ($wpdb->get_var("SHOW TABLES LIKE '" . self::table() . "'") === self::table()) update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    public static function ready() {
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::maybe_install();
        return get_option(self::OPT_DB) === self::DB_VERSION;
    }

    private static function now() { return self::$now !== null ? intval(self::$now) : time(); }
    private static function utc($ts = null) { return gmdate('Y-m-d H:i:s', $ts === null ? self::now() : intval($ts)); }
    /** UTC aus der Tabelle → ISO 8601 in der Zeitzone der Seite; '' für leer. */
    public static function iso($utc) {
        $utc = trim((string) $utc);
        if ($utc === '' || strpos($utc, '0000-00-00') === 0) return '';
        return wp_date('c', strtotime($utc . ' UTC'));
    }

    public static function get($id) {
        global $wpdb;
        if (!self::ready()) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", intval($id)), ARRAY_A);
        return $r ?: null;
    }

    /** Laufendes (offen/gesperrt) oder zuletzt verkauftes Angebot eines Tickets. */
    public static function for_ticket($ticket_id) {
        global $wpdb;
        if (!self::ready()) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE ticket_id = %d ORDER BY (status IN ('open','locked','sold')) DESC, id DESC LIMIT 1", intval($ticket_id)), ARRAY_A);
        return $r ?: null;
    }

    private static function update($id, array $fields, $where_extra = '') {
        global $wpdb;
        $fields['updated_at'] = self::utc();
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if ($v === null) { $set[] = "`$k` = NULL"; continue; }
            $set[] = "`$k` = " . (is_int($v) ? '%d' : (is_float($v) ? '%f' : '%s'));
            $vals[] = $v;
        }
        $vals[] = intval($id);
        $n = $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET " . implode(', ', $set) . " WHERE id = %d" . $where_extra, $vals));
        self::touch($id);
        return intval($n);
    }

    /** Datenspiegel (RS7) + Anzeige-Merker am Ticket nachziehen. */
    private static function touch($id) {
        $o = self::get($id);
        if (!$o) return;
        $flag = $o['status'] === 'open' ? 'open' : ($o['status'] === 'locked' ? 'reserved' : ($o['status'] === 'sold' ? 'sold' : ''));
        $tid = intval($o['ticket_id']);
        if ($tid) {
            if ($flag === '') {
                // Nur löschen, wenn kein anderes Angebot dieses Tickets läuft
                if (!self::active_for_ticket($tid)) delete_post_meta($tid, self::TICKET_META);
            } else {
                update_post_meta($tid, self::TICKET_META, $flag);
            }
        }
        if (class_exists('TIX_Mirror')) TIX_Mirror::touch('resale_offer', intval($id));
    }

    private static function active_for_ticket($ticket_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE ticket_id = %d AND status IN ('open','locked','sold') LIMIT 1", intval($ticket_id)));
    }

    // ═══════════════ Regeln ═══════════════

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    public static function event_org($event_id) {
        return intval(get_post_meta(intval($event_id), '_tix_organizer_id', true));
    }

    /** Regeln der Seite (org 0) bzw. des Veranstalters (Mehr-Veranstalter-Modus; ohne eigene → Seite). */
    public static function site_rules($org = 0) {
        $org = intval($org);
        if ($org && self::multi()) {
            $own = TIX_Object_Data::read('tix_resale_org', $org, null);
            if (is_array($own)) return TIX_Resale_Rules::sanitize($own, null, false)[0] ?: TIX_Resale_Rules::DEFAULTS;
        }
        $s = get_option(self::OPT_SITE, null);
        return is_array($s) ? (TIX_Resale_Rules::sanitize($s, null, false)[0] ?: TIX_Resale_Rules::DEFAULTS) : TIX_Resale_Rules::DEFAULTS;
    }

    /** Eintrag des Events {inherit, rules?, categories_off[]} */
    public static function event_entry($event_id) {
        $e = TIX_Object_Data::read('tix_resale_event', intval($event_id), null);
        $e = is_array($e) ? $e : [];
        return [
            'inherit'        => !isset($e['inherit']) || !empty($e['inherit']),
            'rules'          => isset($e['rules']) && is_array($e['rules']) ? (TIX_Resale_Rules::sanitize($e['rules'], null, false)[0] ?: null) : null,
            'categories_off' => array_values(array_unique(array_map('intval', (array) ($e['categories_off'] ?? [])))),
        ];
    }

    /** Wirksame Regeln eines Events. */
    public static function rules_for($event_id) {
        $e = self::event_entry($event_id);
        if (!$e['inherit'] && $e['rules']) return $e['rules'];
        return self::site_rules(self::event_org($event_id));
    }

    public static function start_ts($event_id) {
        $d = (string) get_post_meta(intval($event_id), '_tix_date_start', true);
        if ($d === '') return 0;
        try { return (new DateTime($d . ' ' . ((string) get_post_meta(intval($event_id), '_tix_time_start', true) ?: '00:00'), wp_timezone()))->getTimestamp(); } catch (\Throwable $e) { return 0; }
    }

    public static function deadline_ts($event_id) {
        return TIX_Resale_Rules::deadline_ts(self::start_ts($event_id), self::rules_for($event_id));
    }

    /** Event an sich weiterverkaufsfähig (veröffentlicht, findet statt, Veranstalter freigegeben, nicht geteilt)? */
    public static function event_ok($event_id) {
        $event_id = intval($event_id);
        if (get_post_type($event_id) !== 'event' || get_post_status($event_id) !== 'publish') return false;
        if (in_array((string) get_post_meta($event_id, '_tix_status', true), ['cancelled', 'postponed', 'past'], true)) return false;
        if (get_post_meta($event_id, '_tix_system_giftcard_event', true) === '1') return false;
        if (class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::is_wallet_event($event_id)) return false;
        if (class_exists('TIX_Org_Approval') && !TIX_Org_Approval::event_allowed($event_id)) return false;
        if (class_exists('TIX_Demo_Access') && TIX_Demo_Access::is_demo_event($event_id)) return false;
        return true;
    }

    /** Geteiltes Event (Spiegel einer anderen Seite)? Dann läuft der Weiterverkauf bei der Quelle (RS5). */
    public static function is_mirror_event($event_id) {
        return get_post_meta(intval($event_id), '_tix_syndicated', true) === '1'
            || (class_exists('TIX_Co_Sale') && TIX_Co_Sale::is_sale_mirror(intval($event_id)));
    }

    /** Kategorie ausverkauft? (Bestand 0 oder Event „ausverkauft“) */
    public static function sold_out($event_id, $cat_index) {
        if ((string) get_post_meta(intval($event_id), '_tix_status', true) === 'sold_out') return true;
        $cats = get_post_meta(intval($event_id), '_tix_ticket_categories', true);
        $c = is_array($cats) && isset($cats[intval($cat_index)]) && is_array($cats[intval($cat_index)]) ? $cats[intval($cat_index)] : null;
        if (!$c) return false;
        return isset($c['stock']) && $c['stock'] !== '' && intval($c['stock']) === 0;
    }

    public static function cat_name($event_id, $cat_index) {
        $cats = get_post_meta(intval($event_id), '_tix_ticket_categories', true);
        return (string) (is_array($cats) && isset($cats[intval($cat_index)]['name']) ? $cats[intval($cat_index)]['name'] : 'Ticket');
    }

    /** Käuferanteil der Gebühr für 1 Ticket zum Angebotspreis (F-RS2: normale Service-/VVK-Gebühr). */
    public static function buyer_fee($event_id, $cat_index, $price) {
        if (!class_exists('TIX_Fees')) return 0.0;
        return round(floatval(TIX_Fees::ticket_total(intval($event_id), intval($cat_index), floatval($price), 1)['fee']), 2);
    }

    // ═══════════════ Ticket prüfen ═══════════════

    private static function order_row($order_id) {
        global $wpdb;
        return $order_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", intval($order_id))) : null;
    }

    /** Originalpreis eines Tickets (bei weiterverkauften Tickets der erste Preis, damit die Grenze nicht wandert). */
    public static function original_price($tid) {
        $o = get_post_meta($tid, '_tix_resale_original_price', true);
        if ($o !== '' && $o !== false && $o !== null) return round(floatval($o), 2);
        return round(floatval(get_post_meta($tid, '_tix_ticket_price', true)), 2);
    }

    /**
     * Fakten + Ergebnis für ein Ticket. $seller = ['user_id' => …, 'email' => …] (wer anbieten will).
     * → ['allowed', 'reason', 'event_id', 'cat_index', 'original', 'max', 'deadline', 'rules', 'order']
     */
    public static function check_ticket($tid, array $seller) {
        $tid = intval($tid);
        $g = function ($k) use ($tid) { return get_post_meta($tid, $k, true); };
        $eid = intval($g('_tix_ticket_event_id'));
        $ci_raw = $g('_tix_ticket_cat_index');
        $ci = ($ci_raw === '' || $ci_raw === null || $ci_raw === false) ? -1 : intval($ci_raw);
        $rules = $eid ? self::rules_for($eid) : TIX_Resale_Rules::DEFAULTS;
        $order = self::order_row(intval($g('_tix_ticket_order_id')));
        $buyer = false;
        if ($order && in_array((string) $order->status, ['completed', 'processing'], true)) {
            $email = strtolower(trim((string) ($seller['email'] ?? '')));
            $buyer = (intval($seller['user_id'] ?? 0) > 0 && intval($order->customer_id) === intval($seller['user_id']))
                || ($email !== '' && strcasecmp(trim((string) $order->billing_email), $email) === 0);
        }
        $cats = $eid ? get_post_meta($eid, '_tix_ticket_categories', true) : [];
        $cat = is_array($cats) && isset($cats[$ci]) && is_array($cats[$ci]) ? $cats[$ci] : null;
        $entry = $eid ? self::event_entry($eid) : ['categories_off' => []];
        $status = (string) ($g('_tix_ticket_status') ?: 'valid');
        if ($order && in_array((string) $order->status, ['refunded', 'cancelled', 'failed'], true)) $status = 'cancelled';
        $deadline = $eid ? TIX_Resale_Rules::deadline_ts(self::start_ts($eid), $rules) : 0;
        $original = self::original_price($tid);
        $facts = [
            'status'        => $status,
            'checked_in'    => (string) $g('_tix_ticket_checked_in') === '1',
            'gift'          => (string) $g('_tix_ticket_gift_code') !== '' || !empty($cat['gift_card']) || !empty($cat['wallet_topup']),
            'special'       => (string) $g('_tix_ticket_type') === 'special' || $ci < 0 || !$cat,
            'buyer'         => $buyer,
            // Geteilte Events: Weiterverkauf nur bei der Quelle (RS5); Spiegel-Tickets gehen über mirror_ticket()
            'event_ok'      => $eid && self::event_ok($eid) && (string) $g('_tix_ticket_mirror') !== '1' && !self::is_mirror_event($eid),
            'enabled'       => !empty($rules['enabled']),
            'cat_off'       => in_array($ci, $entry['categories_off'], true),
            'original'      => $original,
            'now'           => self::now(),
            'deadline'      => $deadline,
            'sold_out'      => $eid ? self::sold_out($eid, $ci) : false,
            'sold_out_only' => !empty($rules['sold_out_only']),
        ];
        $reason = TIX_Resale_Rules::reason($facts);
        return ['allowed' => $reason === '', 'reason' => $reason, 'event_id' => $eid, 'cat_index' => $ci, 'original' => $original,
            'max' => TIX_Resale_Rules::max_price($original, $rules), 'deadline' => $deadline, 'rules' => $rules, 'order' => $order];
    }

    /** Gehört das Ticket dem Konto (wie „Meine Tickets“)? */
    public static function owns($user, $tid) {
        if (!$user || empty($user->ID) || get_post_type($tid) !== 'tix_ticket') return false;
        $trusted = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : (string) $user->user_email;
        if ($trusted !== '') {
            $owner = (string) get_post_meta($tid, '_tix_ticket_owner_email', true);
            $alt   = (string) get_post_meta($tid, '_tix_email', true);
            return ($owner !== '' && strcasecmp($owner, $trusted) === 0) || ($alt !== '' && strcasecmp($alt, $trusted) === 0);
        }
        $order = self::order_row(intval(get_post_meta($tid, '_tix_ticket_order_id', true)));
        return $order && intval($order->customer_id) === intval($user->ID);
    }

    private static function seller_of_user($user) {
        $trusted = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : (string) $user->user_email;
        return ['user_id' => intval($user->ID), 'email' => $trusted];
    }

    // ═══════════════ Antworten ═══════════════

    private static function info_text(array $rules) {
        return 'Tickets von anderen Gästen zum Originalpreis oder günstiger. Das alte Ticket wird ungültig, du bekommst ein neues.';
    }

    private static function terms_url() {
        $u = (string) (function_exists('tix_get_settings') ? tix_get_settings('terms_url') : '');
        if ($u === '' && class_exists('TIX_Co_Legal')) $u = (string) (TIX_Co_Legal::site()['terms_url'] ?? '');
        return $u !== '' ? strtok($u, '#') . '#weiterverkauf' : '';
    }

    public static function checkout_url($event_id, $offer_id) {
        $slug = (string) get_post_field('post_name', intval($event_id));
        $url = home_url('/kasse/' . ($slug !== '' ? $slug : intval($event_id)) . '/');
        if (class_exists('TIX_Customer_URL')) $url = TIX_Customer_URL::to($url);
        return add_query_arg('resale', intval($offer_id), $url);
    }

    private static function payout_text($method = '') {
        if ($method === 'wallet') return 'Das Geld wurde deinem Guthaben gutgeschrieben.';
        if ($method === 'voucher') return 'Du bekommst das Geld als Gutschein beim Veranstalter (Code per E-Mail).';
        return 'Das Geld geht nach dem Verkauf auf dein ursprüngliches Zahlungsmittel zurück. Geht das nicht mehr, bekommst du Guthaben bzw. einen Gutschein.';
    }

    /** Angebot in der Form der Kunden-Route (Vertrag Apps §2). */
    private static function customer_offer(array $o) {
        $status = $o['status'] === 'locked' ? 'reserved' : $o['status'];
        $payout = null;
        if ($o['status'] === 'sold') {
            $payout = ['amount' => round(floatval($o['payout_amount'] ?: $o['price']), 2), 'method' => $o['payout_method'] ?: 'original',
                'status' => $o['payout_status'] === 'paid' ? 'paid' : ($o['payout_status'] === 'failed' ? 'failed' : 'pending'),
                'text' => self::payout_text($o['payout_method'])];
        }
        return ['id' => intval($o['id']), 'price' => round(floatval($o['price']), 2), 'status' => $status,
            'created_at' => self::iso($o['created_at']), 'sold_at' => self::iso($o['sold_at']), 'payout' => $payout];
    }

    /** GET-Antwort der Kunden-Route für ein Ticket. */
    public static function ticket_payload($tid, array $seller) {
        self::expire_due();
        $c = self::check_ticket($tid, $seller);
        $offer = self::for_ticket($tid);
        $live = $offer && in_array($offer['status'], ['open', 'locked', 'sold'], true) ? $offer : null;
        $allowed = $c['allowed'];
        $reason = $c['reason'];
        if ($live && $live['status'] === 'sold') { $allowed = false; $reason = 'resold'; }
        return [
            'allowed'        => $allowed || ($live && $live['status'] === 'open' && $reason === ''),
            'reason'         => $live && $live['status'] === 'open' ? '' : $reason,
            'reason_text'    => ($live && $live['status'] === 'open') || $reason === '' ? '' : (TIX_Resale_Rules::REASONS[$reason] ?? ''),
            'original_price' => $c['original'],
            'max_price'      => $c['max'],
            'min_price'      => TIX_Resale_Rules::MIN_PRICE,
            'deadline'       => $c['deadline'] ? wp_date('c', $c['deadline']) : '',
            'fee_text'       => 'Die Gebühr zahlt der Käufer. Du bekommst deinen Preis voll.',
            'payout_text'    => self::payout_text(),
            'terms_url'      => self::terms_url(),
            'offer'          => $live ? self::customer_offer($live) : null,
        ];
    }

    /** Öffentliche Angebote eines Events (Vertrag Apps §1). $user = angemeldeter Gast (eigene Angebote markiert). */
    public static function public_payload($event_id, $user = null, $with_checkout = true) {
        self::expire_due();
        $event_id = intval($event_id);
        $rules = self::rules_for($event_id);
        $deadline = TIX_Resale_Rules::deadline_ts(self::start_ts($event_id), $rules);
        $entry = self::event_entry($event_id);
        $out = ['enabled' => !empty($rules['enabled']) && self::event_ok($event_id), 'visible' => false, 'reason' => '', 'count' => 0,
            'deadline' => $deadline ? wp_date('c', $deadline) : '', 'info' => self::info_text($rules), 'offers' => []];
        if (!$out['enabled']) { $out['reason'] = 'disabled'; return $out; }
        if ($deadline <= 0 || self::now() >= $deadline) { $out['reason'] = 'deadline'; return $out; }
        global $wpdb;
        $rows = self::ready() ? (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE event_id = %d AND status = 'open' ORDER BY price ASC, id ASC LIMIT 200", $event_id), ARRAY_A) : [];
        $uid = $user && !empty($user->ID) ? intval($user->ID) : 0;
        $any_sold_out = false;
        foreach ($rows as $o) {
            $ci = intval($o['cat_index']);
            if (in_array($ci, $entry['categories_off'], true)) continue;
            $so = self::sold_out($event_id, $ci);
            if (!empty($rules['sold_out_only']) && !$so) continue;
            $any_sold_out = $any_sold_out || $so;
            $fee = floatval($o['buyer_fee']) > 0 ? round(floatval($o['buyer_fee']), 2) : self::buyer_fee($event_id, $ci, $o['price']);
            $row = [
                'id'             => intval($o['id']),
                'category_index' => $ci,
                'category'       => self::cat_name($event_id, $ci),
                'price'          => round(floatval($o['price']), 2),
                'fee'            => $fee,
                'total'          => round(floatval($o['price']) + $fee, 2),
                'seat'           => (string) $o['seat_id'],
                'seat_label'     => ($o['seat_id'] !== '' && class_exists('TIX_Seatmap') && method_exists('TIX_Seatmap', 'ticket_seat_label') && intval($o['ticket_id'])) ? (string) TIX_Seatmap::ticket_seat_label(intval($o['ticket_id'])) : '',
                'checkout_url'   => $with_checkout ? self::checkout_url($event_id, $o['id']) : '',
            ];
            if ($uid) $row['own'] = intval($o['seller_user_id']) === $uid;
            $out['offers'][] = $row;
        }
        $out['count'] = count($out['offers']);
        $out['visible'] = $out['count'] > 0;
        if (!$out['visible']) {
            $out['reason'] = !empty($rules['sold_out_only']) && !$any_sold_out && !self::any_cat_sold_out($event_id) ? 'not_sold_out' : 'none';
        }
        return $out;
    }

    private static function any_cat_sold_out($event_id) {
        $cats = get_post_meta(intval($event_id), '_tix_ticket_categories', true);
        foreach ((array) $cats as $i => $c) if (is_array($c) && self::sold_out($event_id, $i)) return true;
        return false;
    }

    // ═══════════════ Anbieten / Zurückziehen ═══════════════

    /**
     * Anbieten bzw. Preis ändern. $seller = ['user_id', 'email'], $channel app|web|partner.
     * → Angebot (Zeile) oder WP_Error (tix_resale_not_allowed 403, tix_resale_price 422, tix_resale_terms 400, tix_resale_reserved 409).
     */
    public static function offer($tid, array $seller, $price, $accept_terms, $channel = 'web') {
        if (!self::ready()) return new WP_Error('tix_resale_unavailable', 'Weiterverkauf ist gerade nicht verfügbar.', ['status' => 503]);
        $tid = intval($tid);
        $live = self::for_ticket($tid);
        if ($live && $live['status'] === 'locked') return new WP_Error('tix_resale_reserved', 'Gerade kauft jemand dieses Ticket. Bitte warte einen Moment.', ['status' => 409]);
        if ($live && $live['status'] === 'sold') return new WP_Error('tix_resale_not_allowed', TIX_Resale_Rules::REASONS['resold'], ['status' => 403, 'reason' => 'resold']);
        $c = self::check_ticket($tid, $seller);
        if (!$c['allowed']) return new WP_Error('tix_resale_not_allowed', TIX_Resale_Rules::REASONS[$c['reason']] ?? 'Weiterverkauf nicht möglich.', ['status' => 403, 'reason' => $c['reason']]);
        if (!filter_var($accept_terms, FILTER_VALIDATE_BOOLEAN)) return new WP_Error('tix_resale_terms', 'Bitte die Bedingungen für den Weiterverkauf bestätigen.', ['status' => 400, 'field' => 'accept_terms']);
        if ($pe = TIX_Resale_Rules::price_error($price, $c['original'], $c['rules'])) {
            return new WP_Error($pe['code'], $pe['message'], ['status' => 422, 'field' => 'price', 'max_price' => $pe['max_price'], 'min_price' => $pe['min_price']]);
        }
        $price = round(floatval($price), 2);
        $fee = self::buyer_fee($c['event_id'], $c['cat_index'], $price);
        global $wpdb;
        if ($live && $live['status'] === 'open') {
            $n = self::update($live['id'], ['price' => $price, 'buyer_fee' => $fee], " AND status = 'open'");
            if (!$n) return new WP_Error('tix_resale_reserved', 'Gerade kauft jemand dieses Ticket. Bitte warte einen Moment.', ['status' => 409]);
            return self::get($live['id']);
        }
        $now = self::utc();
        $ok = $wpdb->insert(self::table(), [
            'event_id' => $c['event_id'], 'cat_index' => $c['cat_index'], 'organizer_id' => self::event_org($c['event_id']),
            'ticket_id' => $tid, 'active_ticket' => $tid, 'seller_user_id' => intval($seller['user_id'] ?? 0),
            'seller_email' => mb_substr(sanitize_email((string) ($seller['email'] ?? '')), 0, 190), 'seller_order_id' => $c['order'] ? intval($c['order']->id) : 0,
            'channel' => sanitize_key($channel), 'price' => $price, 'original_price' => $c['original'], 'status' => 'open', 'buyer_fee' => $fee,
            'seat_id' => (string) get_post_meta($tid, '_tix_ticket_seat_id', true), 'created_at' => $now, 'updated_at' => $now,
        ]);
        if (!$ok) {
            // Doppelt (zweite Anfrage gleichzeitig): das bestehende Angebot gilt
            $again = self::for_ticket($tid);
            if ($again && $again['status'] === 'open') return self::offer($tid, $seller, $price, true, $channel);
            return new WP_Error('tix_resale_failed', 'Angebot konnte nicht gespeichert werden.', ['status' => 500]);
        }
        $id = intval($wpdb->insert_id);
        self::touch($id);
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 600, 'hourly', self::CRON);
        do_action('tix_resale_offer_changed', $id, 'open');
        return self::get($id);
    }

    /** Zurückziehen. $by = seller|system. → Angebot oder WP_Error (tix_resale_reserved 409, tix_resale_missing 404). */
    public static function withdraw($offer_id, $reason = 'seller') {
        $o = self::get($offer_id);
        if (!$o) return new WP_Error('tix_resale_missing', 'Kein Angebot gefunden.', ['status' => 404]);
        if ($o['status'] === 'locked') return new WP_Error('tix_resale_reserved', 'Gerade kauft jemand dieses Ticket. Zurückziehen ist kurz gesperrt.', ['status' => 409]);
        if ($o['status'] !== 'open') return $o;
        $n = self::update($o['id'], ['status' => 'withdrawn', 'active_ticket' => null, 'closed_at' => self::utc(), 'close_reason' => sanitize_key($reason)], " AND status = 'open'");
        if (!$n) return new WP_Error('tix_resale_reserved', 'Gerade kauft jemand dieses Ticket. Zurückziehen ist kurz gesperrt.', ['status' => 409]);
        do_action('tix_resale_offer_changed', intval($o['id']), 'withdrawn');
        return self::get($o['id']);
    }

    /** Fällige Angebote schließen: offen + Frist vorbei → expired; Sperren ohne Zahlung → wieder frei. */
    public static function expire_due($force = false) {
        static $done = false;
        if ($done && self::$now === null) return;
        // Audit 15.11. (niedrig): öffentliche GETs räumen höchstens einmal je Minute auf (Cron stündlich, Kauf immer)
        if (!$force && self::$now === null && get_transient('tix_resale_expire_ran')) return;
        $done = true;
        if (!self::ready()) return;
        if (self::$now === null) set_transient('tix_resale_expire_ran', 1, MINUTE_IN_SECONDS);
        global $wpdb;
        $now = self::utc();
        $locks = (array) $wpdb->get_results($wpdb->prepare("SELECT id, lock_order_id FROM " . self::table() . " WHERE status = 'locked' AND locked_until IS NOT NULL AND locked_until < %s LIMIT 100", $now), ARRAY_A);
        foreach ($locks as $l) {
            $o = intval($l['lock_order_id']) ? self::order_row(intval($l['lock_order_id'])) : null;
            if ($o && in_array((string) $o->status, ['completed', 'processing'], true)) { self::finalize(intval($o->id)); continue; }
            self::update($l['id'], ['status' => 'open', 'lock_token' => '', 'lock_order_id' => 0, 'locked_until' => null], " AND status = 'locked'");
        }
        $open = (array) $wpdb->get_results("SELECT id, event_id FROM " . self::table() . " WHERE status = 'open' LIMIT 500", ARRAY_A);
        $dl = [];
        foreach ($open as $r) {
            $e = intval($r['event_id']);
            if (!isset($dl[$e])) $dl[$e] = self::deadline_ts($e);
            $ok = self::event_ok($e);
            if ($dl[$e] > 0 && self::now() < $dl[$e] && $ok) continue;
            self::update($r['id'], ['status' => 'expired', 'active_ticket' => null, 'closed_at' => $now, 'close_reason' => $ok ? 'deadline' : 'event'], " AND status = 'open'");
            do_action('tix_resale_offer_changed', intval($r['id']), 'expired');
        }
    }

    /** Offene Angebote schließen, deren Event den Weiterverkauf nicht mehr erlaubt (Rückweg: Schalter aus). */
    public static function sweep_disabled(array $event_ids = null) {
        global $wpdb;
        if (!self::ready()) return 0;
        $sql = "SELECT id, event_id, cat_index FROM " . self::table() . " WHERE status = 'open'";
        if ($event_ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $event_ids)));
            if (!$ids) return 0;
            $sql .= " AND event_id IN (" . implode(',', $ids) . ")";
        }
        $n = 0;
        foreach ((array) $wpdb->get_results($sql . " LIMIT 2000", ARRAY_A) as $r) {
            $rules = self::rules_for($r['event_id']);
            $off = in_array(intval($r['cat_index']), self::event_entry($r['event_id'])['categories_off'], true);
            if (!empty($rules['enabled']) && !$off) continue;
            if (!is_wp_error(self::withdraw($r['id'], 'disabled'))) {
                $n++;
                $o = self::get($r['id']);
                if ($o && intval($o['seller_user_id']) && class_exists('TIX_Notifications')) {
                    TIX_Notifications::add_user_item(intval($o['seller_user_id']), 'Weiterverkauf beendet', 'Der Veranstalter hat den Weiterverkauf für „' . html_entity_decode(get_the_title(intval($o['event_id'])), ENT_QUOTES, 'UTF-8') . '“ beendet. Dein Ticket bleibt gültig.', ['type' => 'resale', 'action' => 'tab:tickets']);
                }
            }
        }
        return $n;
    }

    // ═══════════════ Kasse ═══════════════

    /** Enthält die Bestellung ein Weiterverkaufs-Angebot? (Positionen der App-Kasse) */
    public static function wants($items) {
        foreach ((array) $items as $it) if (is_array($it) && !empty($it['resale_offer'])) return true;
        return false;
    }

    /**
     * Warenkorb für {resale_offer: id} (immer genau eine Position, 1 Ticket).
     * Fehler: tix_resale_mixed 400, tix_resale_taken 409, tix_resale_expired 409, tix_resale_own 400.
     */
    public static function build_cart($event_id, array $items) {
        $items = array_values(array_filter($items, 'is_array'));
        if (count($items) !== 1 || empty($items[0]['resale_offer'])) {
            return new WP_Error('tix_resale_mixed', 'Ein Weiterverkaufs-Ticket bitte einzeln bestellen.', ['status' => 400]);
        }
        $qty = intval($items[0]['qty'] ?? ($items[0]['quantity'] ?? 1));
        if ($qty !== 1) return new WP_Error('tix_resale_mixed', 'Ein Weiterverkaufs-Angebot enthält genau ein Ticket.', ['status' => 400]);
        self::expire_due(true);
        $o = self::get(intval($items[0]['resale_offer']));
        if (!$o || intval($o['event_id']) !== intval($event_id)) return new WP_Error('tix_resale_taken', 'Dieses Angebot ist nicht mehr verfügbar.', ['status' => 409]);
        if ($o['status'] === 'expired' || (self::deadline_ts($event_id) <= self::now())) return new WP_Error('tix_resale_expired', 'Die Frist für dieses Angebot ist abgelaufen.', ['status' => 409]);
        if ($o['status'] !== 'open') return new WP_Error('tix_resale_taken', 'Dieses Angebot ist gerade nicht verfügbar.', ['status' => 409]);
        $rules = self::rules_for($event_id);
        if (empty($rules['enabled']) || !self::event_ok($event_id) || in_array(intval($o['cat_index']), self::event_entry($event_id)['categories_off'], true)) {
            return new WP_Error('tix_resale_taken', 'Dieses Angebot ist nicht mehr verfügbar.', ['status' => 409]);
        }
        if (!empty($rules['sold_out_only']) && !self::sold_out($event_id, $o['cat_index'])) {
            return new WP_Error('tix_resale_taken', 'Dieses Angebot ist gerade nicht verfügbar.', ['status' => 409]);
        }
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        if ($user && intval($o['seller_user_id']) === intval($user->ID)) return new WP_Error('tix_resale_own', 'Das ist dein eigenes Angebot.', ['status' => 400]);
        $name = self::cat_name($event_id, $o['cat_index']);
        $meta = ['resale_offer' => intval($o['id'])];
        return ['items' => [[
            'event_id'     => intval($event_id),
            'cat_index'    => intval($o['cat_index']),
            'name'         => sanitize_text_field($name),
            'event_title'  => get_the_title(intval($event_id)),
            'price'        => round(floatval($o['price']), 2),
            'qty'          => 1,
            'locked_price' => 1,
            'meta'         => $meta,
        ]], 'coupon' => null, 'resale' => intval($o['id'])];
    }

    /** Angebot zur Kasse (quote): Kategorie, Preis, Gebühr. */
    public static function quote_info(array $cart) {
        $o = self::get(intval($cart['resale'] ?? 0));
        if (!$o) return null;
        return ['offer_id' => intval($o['id']), 'category_index' => intval($o['cat_index']), 'category' => self::cat_name($o['event_id'], $o['cat_index']),
            'price' => round(floatval($o['price']), 2), 'seat' => (string) $o['seat_id'], 'deadline' => ($d = self::deadline_ts($o['event_id'])) ? wp_date('c', $d) : ''];
    }

    /**
     * Angebot für die Kasse sperren (bedingtes UPDATE – zwei gleichzeitige Käufe: nur einer gewinnt).
     * → ['id', 'token'] oder WP_Error tix_resale_taken/tix_resale_own.
     */
    public static function lock(array $cart, $billing_email) {
        $id = intval($cart['resale'] ?? 0);
        $o = self::get($id);
        if (!$o) return new WP_Error('tix_resale_taken', 'Dieses Angebot ist nicht mehr verfügbar.', ['status' => 409]);
        $e = strtolower(trim((string) $billing_email));
        if (($e !== '' && strcasecmp((string) $o['seller_email'], $e) === 0) || ($e !== '' && $o['seller_order_id'] && ($so = self::order_row($o['seller_order_id'])) && strcasecmp((string) $so->billing_email, $e) === 0)) {
            return new WP_Error('tix_resale_own', 'Das ist dein eigenes Angebot.', ['status' => 400]);
        }
        $token = wp_generate_password(32, false);
        $n = self::update($id, ['status' => 'locked', 'lock_token' => $token, 'lock_order_id' => 0, 'locked_until' => self::utc(self::now() + TIX_Resale_Rules::LOCK_SETUP)], " AND status = 'open'");
        if (!$n) return new WP_Error('tix_resale_taken', 'Dieses Angebot hat gerade jemand anderes gekauft.', ['status' => 409]);
        return ['id' => $id, 'token' => $token];
    }

    /** Bestellung an die Sperre hängen (Zahlung läuft bis LOCK_ORDER). */
    public static function bind(array $lock, $order_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET lock_order_id = %d, locked_until = %s, updated_at = %s WHERE id = %d AND status = 'locked' AND lock_token = %s",
            intval($order_id), self::utc(self::now() + TIX_Resale_Rules::LOCK_ORDER), self::utc(), intval($lock['id']), (string) $lock['token']));
        self::touch(intval($lock['id']));
    }

    /** Sperre lösen (Bestellung kam nicht zustande). */
    public static function unlock(array $lock) {
        self::update(intval($lock['id']), ['status' => 'open', 'lock_token' => '', 'lock_order_id' => 0, 'locked_until' => null], $GLOBALS['wpdb']->prepare(" AND status = 'locked' AND lock_token = %s", (string) $lock['token']));
    }

    /** Angebot aus den Positionen einer Bestellung (oder 0). */
    public static function offer_of_order($order_id) {
        global $wpdb;
        $metas = (array) $wpdb->get_col($wpdb->prepare("SELECT meta FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d AND meta LIKE %s", intval($order_id), '%resale_offer%'));
        foreach ($metas as $m) {
            $d = json_decode((string) $m, true);
            if (is_array($d) && !empty($d['resale_offer'])) return intval($d['resale_offer']);
        }
        return 0;
    }

    // ═══════════════ Abschluss bei Zahlung ═══════════════

    public static function on_order_completed($order_id) {
        if (!self::ready()) return;
        if (self::offer_of_order($order_id)) self::finalize($order_id);
    }

    /** Neue Tickets einer Bestellung (IDs). */
    private static function order_ticket_ids($order_id) {
        global $wpdb;
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tix_ticket_order_id' AND m.meta_value = %s
             WHERE p.post_type = 'tix_ticket' ORDER BY p.ID ASC", (string) intval($order_id))));
    }

    /**
     * Bezahlte Weiterverkaufs-Bestellung abschließen (idempotent). Gewinnt nur, wenn das Angebot für genau diese Bestellung
     * gesperrt ist (oder noch frei) und das alte Ticket noch gültig ist – sonst Konflikt: neues Ticket storniert, Käufer
     * bekommt sein Geld zurück.
     */
    public static function finalize($order_id) {
        global $wpdb;
        $order_id = intval($order_id);
        $offer_id = self::offer_of_order($order_id);
        if (!$offer_id) return null;
        $o = self::get($offer_id);
        if (!$o) return null;
        if ($o['status'] === 'sold' && intval($o['buyer_order_id']) === $order_id) return $o; // schon erledigt
        $old = intval($o['ticket_id']);
        $old_status = (string) (get_post_meta($old, '_tix_ticket_status', true) ?: 'valid');
        $old_valid = in_array($old_status, ['valid', 'transferred'], true) && (string) get_post_meta($old, '_tix_ticket_checked_in', true) !== '1';
        $seller_order = self::order_row(intval($o['seller_order_id']));
        if ($seller_order && !in_array((string) $seller_order->status, ['completed', 'processing'], true)) $old_valid = false;
        $n = 0;
        if ($old_valid) {
            $n = intval($wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET status = 'sold', buyer_order_id = %d, sold_at = %s, updated_at = %s, active_ticket = NULL, lock_token = ''
                WHERE id = %d AND ((status = 'locked' AND lock_order_id = %d) OR status = 'open')", $order_id, self::utc(), self::utc(), $offer_id, $order_id)));
        }
        if (!$n) {
            // Sicherheits-Hotfix (Audit 15.11., H1): gleichzeitiger Abschluss derselben Bestellung (Webhook + Rückkehr) –
            // neu lesen; gehört das Angebot schon dieser Bestellung, nichts erstatten.
            $fresh = self::get($offer_id);
            if ($fresh && $fresh['status'] === 'sold' && intval($fresh['buyer_order_id']) === $order_id) return $fresh;
            return self::conflict($order_id, $fresh ?: $o, ($fresh && $fresh['status'] === 'sold') || $old_valid ? 'taken' : 'invalid');
        }

        // Altes Ticket ungültig, neues Ticket des Käufers verknüpfen
        $new_ids = self::order_ticket_ids($order_id);
        $new = $new_ids ? $new_ids[0] : 0;
        $seat = (string) get_post_meta($old, '_tix_ticket_seat_id', true);
        $seatmap = get_post_meta($old, '_tix_ticket_seatmap_id', true);
        update_post_meta($old, '_tix_ticket_resold_at', current_time('c'));
        update_post_meta($old, '_tix_ticket_resale_offer', $offer_id);
        if ($new) update_post_meta($old, '_tix_ticket_resold_to', $new);
        update_post_meta($old, '_tix_ticket_status', 'resold');
        if (class_exists('TIX_Ticket_DB')) TIX_Ticket_DB::update_ticket((string) get_post_meta($old, '_tix_ticket_code', true), ['ticket_status' => 'resold']);
        if ($new) {
            update_post_meta($new, '_tix_ticket_resale_offer', $offer_id);
            update_post_meta($new, '_tix_resale_original_price', round(floatval($o['original_price']), 2));
            if ($seat !== '') {
                // Platz zieht mit um (Saalplan-Zeile auf die neue Bestellung)
                delete_post_meta($old, '_tix_ticket_seat_id');
                update_post_meta($old, '_tix_resale_seat_id', $seat);
                update_post_meta($new, '_tix_ticket_seat_id', $seat);
                if ($seatmap !== '') update_post_meta($new, '_tix_ticket_seatmap_id', $seatmap);
                if (class_exists('TIX_Seatmap')) {
                    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}" . TIX_Seatmap::TABLE_SUFFIX . " SET order_id = %d, ticket_id = %d WHERE event_id = %d AND seat_id = %s AND status = 'sold'",
                        $order_id, $new, intval($o['event_id']), $seat));
                }
            }
        }
        self::update($offer_id, ['new_ticket_id' => $new]);
        if (class_exists('TIX_Order_Admin')) {
            TIX_Order_Admin::add_note($order_id, '🔁 Weiterverkauf: Ticket aus Angebot #' . $offer_id . ' (' . TIX_Resale_Rules::money($o['price']) . ' €).', 'system');
            if ($seller_order) TIX_Order_Admin::add_note(intval($seller_order->id), '🔁 Ticket ' . TIX_Resale_Rules::mask_code(get_post_meta($old, '_tix_ticket_code', true)) . ' weiterverkauft (Angebot #' . $offer_id . ', ' . TIX_Resale_Rules::money($o['price']) . ' €). Altes Ticket ungültig.', 'system');
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        do_action('tix_ticket_resold', $old, $new, $offer_id);
        do_action('tix_resale_offer_changed', $offer_id, 'sold');

        self::payout($offer_id);
        self::notify_seller($offer_id);
        return self::get($offer_id);
    }

    /** Konflikt (Angebot weg bzw. Ticket ungültig, z. B. Sperre abgelaufen): neues Ticket stornieren, Käufer erstatten. */
    private static function conflict($order_id, array $o, $why) {
        if ($why === 'invalid' && in_array($o['status'], ['open', 'locked'], true)) {
            self::update($o['id'], ['status' => 'withdrawn', 'active_ticket' => null, 'lock_token' => '', 'lock_order_id' => 0, 'locked_until' => null, 'closed_at' => self::utc(), 'close_reason' => 'ticket_invalid']);
        }
        if (class_exists('TIX_Order_Admin')) {
            TIX_Order_Admin::add_note($order_id, '⚠️ Weiterverkauf: Angebot #' . intval($o['id']) . ' war nicht mehr verfügbar – Bestellung wird erstattet.', 'system');
            $r = TIX_Order_Admin::process_refund($order_id, null, true, 'Weiterverkauf nicht mehr verfügbar', 'resale');
            if (is_wp_error($r)) {
                TIX_Order_Admin::add_note($order_id, '⚠️ Erstattung fehlgeschlagen (' . $r->get_error_message() . ') – bitte von Hand erstatten.', 'system');
                foreach (self::order_ticket_ids($order_id) as $tid) update_post_meta($tid, '_tix_ticket_status', 'cancelled');
            }
        }
        do_action('tix_resale_conflict', $order_id, intval($o['id']));
        return null;
    }

    // ═══════════════ Auszahlung (F-RS1) ═══════════════

    /** Erstattung buchen (wie TIX_Order_Admin::process_refund, ohne Statuswechsel der Bestellung). */
    private static function record_refund($order_id, $amount, $way, $note) {
        $before = class_exists('TIX_Order_Admin') ? TIX_Order_Admin::refunded_total($order_id) : 0.0;
        TIX_Object_Data::write('_tix_refund', $order_id, ['amount' => round($amount, 2), 'reason' => 'Weiterverkauf', 'type' => 'partial', 'date' => current_time('mysql'),
            'admin_user' => 0, 'gateway' => $way, 'source' => 'resale']);
        TIX_Object_Data::write('_tix_refund_total', $order_id, round($before + $amount, 2));
        do_action('tix_order_refund_recorded', $order_id, round($amount, 2), false);
        if (class_exists('TIX_Order_Admin')) TIX_Order_Admin::add_note($order_id, $note, 'refund');
    }

    /**
     * Auszahlung an den Verkäufer: Teil-Erstattung aufs ursprüngliche Zahlungsmittel; klappt das nach PAYOUT_TRIES
     * Versuchen nicht (z. B. Erstattungsfenster abgelaufen) bzw. gibt es keins → Guthaben, sonst Gutschein.
     */
    public static function payout($offer_id, $force_fallback = false) {
        $o = self::get($offer_id);
        if (!$o || $o['status'] !== 'sold' || in_array($o['payout_status'], ['paid', 'processing'], true)) return $o;
        // Sicherheits-Hotfix (Audit 15.11., H1): Auszahlung per bedingtem UPDATE beanspruchen (Zwischenstatus processing),
        // damit sich Abschluss und Cron nicht überlappen und doppelt auszahlen.
        if (!self::update($o['id'], ['payout_status' => 'processing'], $GLOBALS['wpdb']->prepare(" AND status = 'sold' AND payout_status = %s", (string) $o['payout_status']))) return self::get($o['id']);
        try {
            return self::payout_run($o, $force_fallback);
        } catch (\Throwable $e) {
            // Ausgang unklar (evtl. schon erstattet) → nicht automatisch wiederholen, von Hand prüfen
            self::update($o['id'], ['payout_status' => 'failed', 'payout_error' => mb_substr('Auszahlung abgebrochen – bitte prüfen: ' . $e->getMessage(), 0, 250)]);
            return self::get($o['id']);
        }
    }

    private static function payout_run(array $o, $force_fallback) {
        $amount = round(floatval($o['price']), 2);
        $order = self::order_row(intval($o['seller_order_id']));
        $refundable = 0.0;
        if ($order) $refundable = round(floatval($order->total) - (class_exists('TIX_Order_Admin') ? TIX_Order_Admin::refunded_total($order->id) : 0), 2);
        $way = $order ? TIX_Resale_Rules::payout_way((string) $order->payment_method, $refundable, $amount) : 'fallback';
        $tries = intval($o['payout_tries']);
        if ($way === 'gateway' && !$force_fallback && $tries < TIX_Resale_Rules::PAYOUT_TRIES) {
            $gw = (string) $order->payment_method;
            $cls = ['mollie' => 'TIX_Gateway_Mollie', 'stripe' => 'TIX_Gateway_Stripe', 'paypal' => 'TIX_Gateway_PayPal'][$gw] ?? '';
            $res = ($cls && class_exists($cls)) ? $cls::refund(intval($order->id), $amount) : ['error' => 'Zahlungsanbieter nicht verfügbar.'];
            if (empty($res['error'])) {
                self::record_refund(intval($order->id), $amount, $gw, 'Teil-Erstattung Weiterverkauf: ' . TIX_Resale_Rules::money($amount) . ' € (Angebot #' . intval($o['id']) . ').');
                self::update($o['id'], ['payout_method' => 'original', 'payout_status' => 'paid', 'payout_amount' => $amount, 'payout_tries' => $tries + 1, 'payout_error' => '', 'payout_ref' => $gw]);
                do_action('tix_resale_paid_out', intval($o['id']), 'original');
                return self::get($o['id']);
            }
            $tries++;
            $err = mb_substr((string) $res['error'], 0, 250);
            if ($tries < TIX_Resale_Rules::PAYOUT_TRIES) {
                self::update($o['id'], ['payout_method' => 'original', 'payout_status' => 'pending', 'payout_tries' => $tries, 'payout_error' => $err]);
                return self::get($o['id']); // erneuter Versuch per Cron
            }
            self::update($o['id'], ['payout_tries' => $tries, 'payout_error' => $err]);
        }
        if ($way === 'wallet_back' && class_exists('TIX_Wallet_Balance') && ($pay = TIX_Wallet_Balance::order_payment(intval($order->id)))) {
            $tx = TIX_Wallet_Balance::post_tx(intval($pay['wallet_id']), ['type' => 'refund', 'amount_paid' => TIX_Wallet_Balance::cents($amount), 'order_id' => intval($order->id),
                'channel' => 'system', 'allow_locked' => true, 'idempotency_key' => 'resale:' . intval($o['id']), 'meta' => ['reason' => 'Weiterverkauf', 'resale_offer' => intval($o['id'])]]);
            if (!is_wp_error($tx)) {
                self::record_refund(intval($order->id), $amount, 'wallet', 'Weiterverkauf: ' . TIX_Resale_Rules::money($amount) . ' € zurück ins Guthaben (Angebot #' . intval($o['id']) . ').');
                self::update($o['id'], ['payout_method' => 'wallet', 'payout_status' => 'paid', 'payout_amount' => $amount, 'payout_ref' => 'wallet_tx:' . intval($tx['id'])]);
                do_action('tix_resale_paid_out', intval($o['id']), 'wallet');
                return self::get($o['id']);
            }
        }
        return self::fallback($o, $order, $amount);
    }

    /** Rückfall: Guthaben (Konto + Guthaben aktiv) bzw. Gutschein beim Veranstalter. */
    private static function fallback(array $o, $order, $amount) {
        $uid = intval($o['seller_user_id']);
        if (!$uid && $o['seller_email'] !== '' && ($u = get_user_by('email', $o['seller_email']))) $uid = intval($u->ID);
        $org = self::multi() ? intval($o['organizer_id']) : 0;
        // Guthaben beim Veranstalter, sonst Plattform-/Seiten-Guthaben (falls aktiv)
        $scope = null;
        if ($uid && class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::ready()) {
            foreach (array_unique([$org, 0]) as $cand) if (TIX_Wallet_Balance::scope_enabled($cand)) { $scope = $cand; break; }
        }
        if ($scope !== null) {
            $w = TIX_Wallet_Balance::get_or_create($uid, $scope);
            if ($w) {
                $tx = TIX_Wallet_Balance::post_tx(intval($w['id']), ['type' => 'refund', 'amount_paid' => TIX_Wallet_Balance::cents($amount), 'order_id' => $order ? intval($order->id) : 0,
                    'channel' => 'system', 'allow_locked' => true, 'idempotency_key' => 'resale:' . intval($o['id']), 'organizer_id' => $scope, 'meta' => ['reason' => 'Weiterverkauf', 'resale_offer' => intval($o['id'])]]);
                if (!is_wp_error($tx)) {
                    if ($order) self::record_refund(intval($order->id), $amount, 'wallet', 'Weiterverkauf: ' . TIX_Resale_Rules::money($amount) . ' € als Guthaben gutgeschrieben (Erstattung aufs Zahlungsmittel nicht möglich, Angebot #' . intval($o['id']) . ').');
                    self::update($o['id'], ['payout_method' => 'wallet', 'payout_status' => 'paid', 'payout_amount' => $amount, 'payout_ref' => 'wallet_tx:' . intval($tx['id'])]);
                    do_action('tix_resale_paid_out', intval($o['id']), 'wallet');
                    return self::get($o['id']);
                }
            }
        }
        $code = self::create_voucher($o, $amount, $org);
        if ($code !== '') {
            if ($order) self::record_refund(intval($order->id), $amount, 'voucher', 'Weiterverkauf: ' . TIX_Resale_Rules::money($amount) . ' € als Gutschein ' . $code . ' (Erstattung aufs Zahlungsmittel nicht möglich, Angebot #' . intval($o['id']) . ').');
            self::update($o['id'], ['payout_method' => 'voucher', 'payout_status' => 'paid', 'payout_amount' => $amount, 'payout_ref' => $code]);
            do_action('tix_resale_paid_out', intval($o['id']), 'voucher');
            return self::get($o['id']);
        }
        self::update($o['id'], ['payout_status' => 'failed', 'payout_error' => 'Auszahlung nicht möglich – bitte von Hand erstatten.']);
        if ($order && class_exists('TIX_Order_Admin')) TIX_Order_Admin::add_note(intval($order->id), '⚠️ Weiterverkauf: Auszahlung von ' . TIX_Resale_Rules::money($amount) . ' € fehlgeschlagen – bitte von Hand erstatten (Angebot #' . intval($o['id']) . ').', 'system');
        return self::get($o['id']);
    }

    /** Gutschein-Code (Art giftcard in tix_coupons) über den Betrag; '' bei Fehler. Idempotent je Angebot. */
    private static function create_voucher(array $o, $amount, $org) {
        if ($o['payout_method'] === 'voucher' && $o['payout_ref'] !== '') return (string) $o['payout_ref'];
        wp_cache_delete('tix_coupons', 'options');
        $coupons = get_option('tix_coupons', []);
        if (!is_array($coupons)) $coupons = [];
        foreach ($coupons as $k => $c) if (is_array($c) && intval($c['resale_offer'] ?? 0) === intval($o['id'])) return (string) $k;
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $p = '';
            for ($i = 0; $i < 8; $i++) $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $code = 'GS-' . substr($p, 0, 4) . '-' . substr($p, 4);
        } while (isset($coupons[$code]));
        $years = class_exists('TIX_Giftcards') ? ($org ? TIX_Giftcards::organizer_settings($org)['validity_years'] : TIX_Giftcards::validity_years()) : 3;
        $coupons[$code] = [
            'discount_type' => 'giftcard', 'value' => round($amount, 2), 'balance' => round($amount, 2),
            'expires' => date('Y-m-d', strtotime('+' . max(1, intval($years)) . ' years')), 'organizer_id' => $org ?: null,
            'ticket_id' => 0, 'order_id' => intval($o['seller_order_id']), 'redemptions' => [], 'created' => current_time('mysql'),
            'description' => 'Weiterverkauf (Angebot #' . intval($o['id']) . ')', 'max_uses' => 0, 'used' => 0, 'resale_offer' => intval($o['id']),
        ];
        return update_option('tix_coupons', $coupons) ? $code : '';
    }

    /** Mail + Mitteilung an den Verkäufer (nie Daten des Käufers). */
    private static function notify_seller($offer_id) {
        $o = self::get($offer_id);
        if (!$o || $o['status'] !== 'sold') return;
        $title = html_entity_decode((string) get_the_title(intval($o['event_id'])), ENT_QUOTES, 'UTF-8');
        $amount = TIX_Resale_Rules::money($o['payout_amount'] ?: $o['price']);
        $way = $o['payout_method'] === 'wallet' ? 'als Guthaben gutgeschrieben' : ($o['payout_method'] === 'voucher' ? 'als Gutschein (Code unten)' : ($o['payout_status'] === 'paid' ? 'auf dein ursprüngliches Zahlungsmittel erstattet' : 'wird auf dein ursprüngliches Zahlungsmittel erstattet'));
        if ($o['payout_status'] === 'failed') $way = 'wird vom Veranstalter erstattet – wir melden uns';
        $body = 'Dein Ticket für „' . $title . '“ wurde weiterverkauft. ' . $amount . ' € ' . $way . '. Das alte Ticket ist nicht mehr gültig.';
        if (intval($o['seller_user_id']) && class_exists('TIX_Notifications')) {
            TIX_Notifications::add_user_item(intval($o['seller_user_id']), 'Dein Ticket wurde weiterverkauft', $body, ['type' => 'resale', 'action' => 'tab:tickets']);
            if ($o['payout_method'] === 'wallet') TIX_Notifications::add_user_item(intval($o['seller_user_id']), 'Guthaben gutgeschrieben', $amount . ' € aus deinem Weiterverkauf.', ['type' => 'wallet', 'action' => 'wallet']);
        }
        $to = (string) $o['seller_email'];
        if ($to === '' && ($so = self::order_row(intval($o['seller_order_id'])))) $to = (string) $so->billing_email;
        if ($to === '' || !is_email($to) || !class_exists('TIX_Mail_Layout')) return;
        $brand = TIX_Mail_Layout::brand()['name'];
        $blocks = [['type' => 'text', 'html' => esc_html($body)]];
        if ($o['payout_method'] === 'voucher') $blocks[] = ['type' => 'notice', 'tone' => 'success', 'html' => 'Dein Gutschein-Code: <strong>' . esc_html($o['payout_ref']) . '</strong> über ' . esc_html($amount) . ' €.'];
        TIX_Mail_Layout::send($to, 'Dein Ticket wurde weiterverkauft – ' . $brand, ['title' => 'Ticket weiterverkauft', 'blocks' => $blocks, 'kind' => 'customer',
            'note' => 'Du bekommst diese E-Mail, weil du ein Ticket bei ' . $brand . ' weiterverkauft hast.']);
    }

    // ═══════════════ Hooks ═══════════════

    /** Storno/fehlgeschlagen: Sperre der Käufer-Bestellung lösen; Angebote aus stornierten Verkäufer-Bestellungen zurückziehen. */
    public static function on_order_cancelled($order_id) {
        if (!self::ready()) return;
        global $wpdb;
        $order_id = intval($order_id);
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE status = 'locked' AND lock_order_id = %d", $order_id)) as $id) {
            $past = self::deadline_ts(intval(self::get($id)['event_id'])) <= self::now();
            self::update($id, $past ? ['status' => 'expired', 'active_ticket' => null, 'lock_token' => '', 'lock_order_id' => 0, 'locked_until' => null, 'closed_at' => self::utc(), 'close_reason' => 'deadline']
                : ['status' => 'open', 'lock_token' => '', 'lock_order_id' => 0, 'locked_until' => null], " AND status = 'locked'");
            do_action('tix_resale_offer_changed', intval($id), 'released');
        }
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE status = 'open' AND seller_order_id = %d", $order_id)) as $id) {
            self::withdraw($id, 'order_cancelled');
        }
    }

    public static function on_ticket_checked_in($ticket_id) {
        if (!self::ready()) return;
        $o = self::for_ticket($ticket_id);
        if ($o && $o['status'] === 'open') self::withdraw($o['id'], 'checked_in');
    }

    public static function cron() {
        if (!self::ready()) return;
        self::expire_due(true);
        self::purge_old();
        global $wpdb;
        $due = (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE status = 'sold' AND payout_status = 'pending' AND updated_at < %s LIMIT 50", self::utc(self::now() - 600)));
        foreach ($due as $id) self::payout(intval($id));
    }

    /** Veranstaltungsende (Unix): Enddatum/-zeit, sonst Beginn-Datum 23:59; 0 = unbekannt. */
    public static function end_ts($event_id) {
        $d = (string) (get_post_meta(intval($event_id), '_tix_date_end', true) ?: get_post_meta(intval($event_id), '_tix_date_start', true));
        if ($d === '') return 0;
        $t = (string) (get_post_meta(intval($event_id), '_tix_time_end', true) ?: '23:59');
        try { return (new DateTime($d . ' ' . $t, wp_timezone()))->getTimestamp(); } catch (\Throwable $e) { return 0; }
    }

    /**
     * Löschfrist (Rechtstexte 14.11.): Angebote ohne Verkauf 30 Tage nach der Veranstaltung löschen (Zeile weg, Spiegel löscht mit).
     * Gelöschte Events: Angebote ohne Verkauf sofort. Verkaufte bleiben (Aufbewahrung wie die Bestellungen). → Anzahl gelöscht.
     */
    public static function purge_old() {
        global $wpdb;
        if (!self::ready()) return 0;
        $n = 0; $ends = [];
        foreach ((array) $wpdb->get_results("SELECT id, event_id, status, ticket_id FROM " . self::table() . " WHERE status IN ('withdrawn','expired','open') ORDER BY id LIMIT 2000", ARRAY_A) as $r) {
            $e = intval($r['event_id']);
            if (!isset($ends[$e])) $ends[$e] = get_post_type($e) === 'event' ? self::end_ts($e) : -1;
            $gone = $ends[$e] === -1;
            if (!$gone && !TIX_Resale_Rules::purge_due($r['status'], $ends[$e], self::now())) continue;
            if ($wpdb->delete(self::table(), ['id' => intval($r['id'])], ['%d'])) {
                $n++;
                $tid = intval($r['ticket_id']);
                if ($tid && !self::active_for_ticket($tid) && in_array((string) get_post_meta($tid, self::TICKET_META, true), ['open', 'reserved'], true)) delete_post_meta($tid, self::TICKET_META);
                if (class_exists('TIX_Mirror')) TIX_Mirror::touch('resale_offer', intval($r['id']), 'delete');
            }
        }
        return $n;
    }

    // ═══════════════ Ticket-Anzeige ═══════════════

    /** Weiterverkaufs-Stand eines Tickets für Ticketlisten: '' | open | reserved | sold */
    public static function ticket_flag($ticket_id) {
        $f = (string) get_post_meta(intval($ticket_id), self::TICKET_META, true);
        return in_array($f, ['open', 'reserved', 'sold'], true) ? $f : '';
    }

    /** Status für Kunden-Apps: „resold“ kennen die Apps im Store nicht → „transferred“ (Vertrag Apps §3). */
    public static function public_status($status) {
        return $status === 'resold' ? 'transferred' : $status;
    }

    // ═══════════════ REST ═══════════════

    public static function register_routes() {
        register_rest_route(self::NS, '/public/events/(?P<id>\d+)/resale', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public'], 'permission_callback' => '__return_true']);
        $cust = ['TIX_App_Checkout', 'check_customer'];
        register_rest_route(self::NS, '/customer/tickets/(?P<id>\d+)/resale', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_ticket_get'], 'permission_callback' => $cust],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_ticket_offer'], 'permission_callback' => $cust],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_ticket_withdraw'], 'permission_callback' => $cust],
        ]);
        register_rest_route(self::NS, '/customer/tickets/(?P<id>\d+)/resale/withdraw', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_ticket_withdraw'], 'permission_callback' => $cust]);
        $mgr = ['TIX_Studio_Stats', 'perm_manager'];
        register_rest_route(self::NS, '/studio/resale/settings', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_site_get'], 'permission_callback' => $mgr],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_site_save'], 'permission_callback' => $mgr],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/resale/settings', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_event_get'], 'permission_callback' => $mgr],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_event_save'], 'permission_callback' => $mgr],
        ]);
        register_rest_route(self::NS, '/studio/resale', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_overview'], 'permission_callback' => $mgr]);
        // RS5: Durchgriff der Plattform (Partner-Schlüssel) bei der Quelle
        $partner = ['TIX_Partner_API', 'check_auth'];
        register_rest_route(self::NS, '/partner/resale', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_partner_list'], 'permission_callback' => $partner]);
        register_rest_route(self::NS, '/partner/resale/ticket', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_partner_ticket'], 'permission_callback' => $partner]);
    }

    private static function nocache($data, $status = 200) {
        $r = rest_ensure_response($data);
        $r->set_status($status);
        $r->header('Cache-Control', 'no-store, private');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    private static function rate_limited($bucket, $max, $window) {
        $who = get_current_user_id() ?: ('ip_' . (class_exists('TIX_App_Account') ? TIX_App_Account::client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '')));
        $key = 'tix_resale_rl_' . $bucket . '_' . md5((string) $who);
        $d = get_transient($key);
        if (!is_array($d) || time() - intval($d['first']) >= $window) $d = ['count' => 0, 'first' => time()];
        $d['count']++;
        set_transient($key, $d, $window);
        return $d['count'] > $max;
    }

    /** GET /public/events/{id}/resale – öffentlich (Konto optional). Geteilte Events: Angebote der Quelle (RS5). */
    public static function rest_public(WP_REST_Request $req) {
        $eid = intval($req['id']);
        if (get_post_type($eid) !== 'event' || get_post_status($eid) !== 'publish') return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        if (self::is_mirror_event($eid)) return self::nocache(self::mirror_public($eid));
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        return self::nocache(self::public_payload($eid, $user));
    }

    private static function load_own_ticket(WP_REST_Request $req) {
        $tid = intval($req['id']);
        $user = wp_get_current_user();
        if (!self::owns($user, $tid)) return new WP_Error('tix_not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        return [$tid, $user];
    }

    public static function rest_ticket_get(WP_REST_Request $req) {
        $l = self::load_own_ticket($req);
        if (is_wp_error($l)) return $l;
        list($tid, $user) = $l;
        if ((string) get_post_meta($tid, '_tix_ticket_mirror', true) === '1') return self::mirror_ticket($tid, 'info', $req);
        return self::nocache(self::ticket_payload($tid, self::seller_of_user($user)));
    }

    public static function rest_ticket_offer(WP_REST_Request $req) {
        if (self::rate_limited('offer', 20, 300)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', ['status' => 429]);
        $l = self::load_own_ticket($req);
        if (is_wp_error($l)) return $l;
        list($tid, $user) = $l;
        if ((string) get_post_meta($tid, '_tix_ticket_mirror', true) === '1') return self::mirror_ticket($tid, 'offer', $req);
        $r = self::offer($tid, self::seller_of_user($user), $req->get_param('price'), $req->get_param('accept_terms'), class_exists('TIX_App_Coupons') && TIX_App_Coupons::is_app_request() ? 'app' : 'web');
        if (is_wp_error($r)) return $r;
        return self::nocache(self::ticket_payload($tid, self::seller_of_user($user)));
    }

    public static function rest_ticket_withdraw(WP_REST_Request $req) {
        if (self::rate_limited('offer', 20, 300)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', ['status' => 429]);
        $l = self::load_own_ticket($req);
        if (is_wp_error($l)) return $l;
        list($tid, $user) = $l;
        if ((string) get_post_meta($tid, '_tix_ticket_mirror', true) === '1') return self::mirror_ticket($tid, 'withdraw', $req);
        $o = self::for_ticket($tid);
        if (!$o || !in_array($o['status'], ['open', 'locked'], true)) return new WP_Error('tix_resale_missing', 'Für dieses Ticket gibt es kein offenes Angebot.', ['status' => 404]);
        $r = self::withdraw($o['id'], 'seller');
        if (is_wp_error($r)) return $r;
        return self::nocache(self::ticket_payload($tid, self::seller_of_user($user)));
    }

    // ── Studio ──

    private static function rules_out(array $r) {
        return ['enabled' => !empty($r['enabled']), 'sold_out_only' => !empty($r['sold_out_only']),
            'max_price' => ['mode' => (string) $r['max_price']['mode'], 'percent' => intval($r['max_price']['percent'])], 'deadline_hours' => intval($r['deadline_hours'])];
    }

    private static function bad(array $err) {
        return new WP_Error('tix_invalid_field', $err['message'], ['status' => 400, 'field' => $err['field']]);
    }

    private static function req_org(WP_REST_Request $req) {
        return class_exists('TIX_Ad_Accounts') ? TIX_Ad_Accounts::org($req) : 0;
    }

    public static function rest_site_get(WP_REST_Request $req) {
        return self::nocache(self::rules_out(self::site_rules(self::req_org($req))));
    }

    public static function rest_site_save(WP_REST_Request $req) {
        $org = self::req_org($req);
        $p = $req->get_json_params() ?: $req->get_body_params();
        list($rules, $err) = TIX_Resale_Rules::sanitize((array) ($p['rules'] ?? $p), self::site_rules($org));
        if ($err) return self::bad($err);
        if ($org && self::multi()) TIX_Object_Data::write('tix_resale_org', $org, $rules);
        elseif (self::multi() && !current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Kein Veranstalter.', ['status' => 403]);
        else update_option(self::OPT_SITE, $rules, false);
        // Rückweg: Schalter aus → offene Angebote der betroffenen Events zurückziehen (verkaufte bleiben gültig)
        self::sweep_disabled($org && self::multi() ? self::org_event_ids($org) : null);
        return self::rest_site_get($req);
    }

    private static function org_event_ids($org) {
        return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [class_exists('TIX_App_Scope') ? TIX_App_Scope::event_meta_clause($org) : ['key' => '_tix_organizer_id', 'value' => (string) $org]]]));
    }

    private static function event_err($eid) {
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::can_access_event($eid)) return new WP_Error('rest_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        if (self::is_mirror_event($eid)) return new WP_Error('tix_resale_source', 'Den Weiterverkauf geteilter Events stellt der Ersteller ein.', ['status' => 409]);
        return null;
    }

    public static function rest_event_get(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if ($e = self::event_err($eid)) return $e;
        $entry = self::event_entry($eid);
        $site = self::site_rules(self::event_org($eid));
        $rules = !$entry['inherit'] && $entry['rules'] ? $entry['rules'] : $site;
        $cats = [];
        foreach ((array) get_post_meta($eid, '_tix_ticket_categories', true) as $i => $c) {
            if (!is_array($c) || !empty($c['gift_card']) || !empty($c['wallet_topup'])) continue;
            $cats[] = ['index' => intval($i), 'name' => (string) ($c['name'] ?? 'Ticket'), 'enabled' => !in_array(intval($i), $entry['categories_off'], true), 'sold_out' => self::sold_out($eid, $i)];
        }
        $start = self::start_ts($eid);
        return self::nocache(['inherit' => $entry['inherit'] || !$entry['rules'], 'rules' => self::rules_out($rules), 'site' => self::rules_out($site),
            'starts_at' => $start ? wp_date('c', $start) : '', 'categories' => $cats]);
    }

    public static function rest_event_save(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if ($e = self::event_err($eid)) return $e;
        $p = $req->get_json_params() ?: $req->get_body_params();
        $inherit = !array_key_exists('inherit', (array) $p) || filter_var($p['inherit'], FILTER_VALIDATE_BOOLEAN);
        $off = [];
        if (array_key_exists('categories_off', (array) $p)) {
            if (!is_array($p['categories_off'])) return self::bad(['field' => 'categories_off', 'message' => 'categories_off ist eine Liste von Kategorie-Nummern.']);
            $cats = (array) get_post_meta($eid, '_tix_ticket_categories', true);
            foreach ($p['categories_off'] as $i) {
                if (!is_numeric($i) || !isset($cats[intval($i)])) return self::bad(['field' => 'categories_off', 'message' => 'Unbekannte Kategorie ' . sanitize_text_field((string) $i) . '.']);
                $off[] = intval($i);
            }
        } else {
            $off = self::event_entry($eid)['categories_off'];
        }
        $entry = ['inherit' => $inherit, 'categories_off' => array_values(array_unique($off))];
        if (!$inherit) {
            $base = self::event_entry($eid)['rules'] ?: self::site_rules(self::event_org($eid));
            list($rules, $err) = TIX_Resale_Rules::sanitize((array) ($p['rules'] ?? []), $base);
            if ($err) return self::bad($err);
            $entry['rules'] = $rules;
        }
        TIX_Object_Data::write('tix_resale_event', $eid, $entry);
        self::sweep_disabled([$eid]);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return self::rest_event_get($req);
    }

    /** GET /studio/resale?status=open|sold|closed&event=&page=&per_page= */
    public static function rest_overview(WP_REST_Request $req) {
        global $wpdb;
        if (!self::ready()) return self::nocache(['items' => [], 'totals' => self::totals_row([]), 'by_event' => [], 'has_more' => false]);
        self::expire_due();
        $where = ['1=1'];
        $scoped = class_exists('TIX_App_Scope') && TIX_App_Scope::scoped();
        if ($scoped) {
            $own = TIX_App_Scope::own_event_ids();
            if (!$own) return self::nocache(['items' => [], 'totals' => self::totals_row([]), 'by_event' => [], 'has_more' => false]);
            $where[] = 'event_id IN (' . implode(',', array_map('intval', $own)) . ')';
        } elseif (self::multi() && ($org = self::req_org($req))) {
            $ids = self::org_event_ids($org);
            $where[] = $ids ? 'event_id IN (' . implode(',', $ids) . ')' : '1=0';
        }
        $event = absint($req->get_param('event'));
        if ($event) {
            if (!TIX_REST_API::can_access_event($event)) return new WP_Error('rest_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
            $where[] = $wpdb->prepare('event_id = %d', $event);
        }
        $base = implode(' AND ', $where);
        $all = (array) $wpdb->get_results("SELECT * FROM " . self::table() . " WHERE $base ORDER BY id DESC", ARRAY_A);
        $st = (string) $req->get_param('status');
        $map = ['open' => ['open', 'locked'], 'sold' => ['sold'], 'closed' => ['withdrawn', 'expired']];
        $list = isset($map[$st]) ? array_values(array_filter($all, function ($o) use ($map, $st) { return in_array($o['status'], $map[$st], true); })) : $all;
        $per = max(1, min(100, absint($req->get_param('per_page')) ?: 100));
        $page = max(1, absint($req->get_param('page')) ?: 1);
        $slice = array_slice($list, ($page - 1) * $per, $per);
        $orders = [];
        $name = function ($oid) use (&$orders) {
            $oid = intval($oid);
            if (!$oid) return '';
            if (!array_key_exists($oid, $orders)) $orders[$oid] = self::order_row($oid);
            return $orders[$oid] ? TIX_Resale_Rules::short_name($orders[$oid]->billing_first_name, $orders[$oid]->billing_last_name) : '';
        };
        $items = [];
        foreach ($slice as $o) {
            $eid = intval($o['event_id']);
            $items[] = [
                'id' => intval($o['id']), 'event_id' => $eid, 'event_title' => html_entity_decode((string) get_the_title($eid), ENT_QUOTES, 'UTF-8'),
                'event_date' => (string) get_post_meta($eid, '_tix_date_start', true), 'category' => self::cat_name($eid, $o['cat_index']),
                'ticket_code' => TIX_Resale_Rules::mask_code(get_post_meta(intval($o['ticket_id']), '_tix_ticket_code', true)),
                'status' => $o['status'], 'price' => round(floatval($o['price']), 2), 'original_price' => round(floatval($o['original_price']), 2),
                'buyer_fee' => round(floatval($o['buyer_fee']), 2),
                'seller' => ['name' => $name($o['seller_order_id'])],
                'buyer' => intval($o['buyer_order_id']) ? ['name' => $name($o['buyer_order_id'])] : null,
                'order_id' => intval($o['buyer_order_id']) ?: null,
                'refund' => $o['status'] === 'sold' ? ['amount' => round(floatval($o['payout_amount'] ?: $o['price']), 2),
                    'way' => ['original' => 'original', 'wallet' => 'credit', 'voucher' => 'voucher'][$o['payout_method']] ?? 'original',
                    'state' => $o['payout_status'] === 'paid' ? 'done' : ($o['payout_status'] === 'failed' ? 'failed' : 'pending')] : null,
                'created_at' => self::iso($o['created_at']), 'sold_at' => self::iso($o['sold_at']),
            ];
        }
        $by = [];
        foreach ($all as $o) {
            $eid = intval($o['event_id']);
            if (!isset($by[$eid])) $by[$eid] = ['event_id' => $eid, 'title' => html_entity_decode((string) get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($eid, '_tix_date_start', true), 'offers' => 0, 'sold' => 0, 'revenue' => 0.0, 'buyer_fees' => 0.0, 'refunded' => 0.0];
            $by[$eid]['offers']++;
            if ($o['status'] === 'sold') {
                $by[$eid]['sold']++;
                $by[$eid]['revenue'] = round($by[$eid]['revenue'] + floatval($o['price']), 2);
                $by[$eid]['buyer_fees'] = round($by[$eid]['buyer_fees'] + floatval($o['buyer_fee']), 2);
                if ($o['payout_status'] === 'paid') $by[$eid]['refunded'] = round($by[$eid]['refunded'] + floatval($o['payout_amount']), 2);
            }
        }
        return self::nocache(['items' => $items, 'totals' => self::totals_row($all), 'by_event' => array_values($by), 'has_more' => count($list) > $page * $per, 'total' => count($list)]);
    }

    private static function totals_row(array $all) {
        $t = ['open' => 0, 'sold' => 0, 'revenue' => 0.0, 'buyer_fees' => 0.0, 'refunded' => 0.0, 'refunded_credit' => 0.0, 'refund_failed' => 0];
        foreach ($all as $o) {
            if (in_array($o['status'], ['open', 'locked'], true)) $t['open']++;
            if ($o['status'] !== 'sold') continue;
            $t['sold']++;
            $t['revenue'] = round($t['revenue'] + floatval($o['price']), 2);
            $t['buyer_fees'] = round($t['buyer_fees'] + floatval($o['buyer_fee']), 2);
            if ($o['payout_status'] === 'paid') {
                $t['refunded'] = round($t['refunded'] + floatval($o['payout_amount']), 2);
                if (in_array($o['payout_method'], ['wallet', 'voucher'], true)) $t['refunded_credit'] = round($t['refunded_credit'] + floatval($o['payout_amount']), 2);
            }
            if ($o['payout_status'] === 'failed') $t['refund_failed']++;
        }
        return $t;
    }

    // ═══════════════ RS5: geteilte Events ═══════════════

    /** Quelle: GET /partner/resale?event_id= → Angebote wie /public/events/{id}/resale (ohne checkout_url). */
    public static function rest_partner_list(WP_REST_Request $req) {
        $eid = intval($req->get_param('event_id'));
        if (get_post_type($eid) !== 'event' || get_post_status($eid) !== 'publish') return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        if (get_post_meta($eid, '_tix_syndicate', true) !== '1' || get_post_meta($eid, '_tix_partner_sales', true) === '0') {
            return new WP_Error('tix_partner_event', 'Für dieses Event ist der Verkauf über Partner nicht freigegeben.', ['status' => 403]);
        }
        return self::nocache(['ok' => true] + self::public_payload($eid, null, false));
    }

    /**
     * Quelle: POST /partner/resale/ticket {ticket_id, order_id, key, action: info|offer|withdraw, price?, accept_terms?}
     * Die Plattform handelt für den Käufer einer Partner-Bestellung (Nachweis: Bestellnummer + Bestellschlüssel).
     */
    public static function rest_partner_ticket(WP_REST_Request $req) {
        $tid = intval($req->get_param('ticket_id'));
        $oid = intval($req->get_param('order_id'));
        $o = self::order_row($oid);
        $m = class_exists('TIX_Partner_API') ? TIX_Partner_API::marker($oid) : null;
        $key = (string) $req->get_param('key');
        if (!$o || !$m || $m['partner'] !== TIX_Partners::source_platform_id() || $key === '' || !hash_equals((string) $o->order_key, $key)
            || intval(get_post_meta($tid, '_tix_ticket_order_id', true)) !== $oid) {
            return new WP_Error('tix_not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        }
        $seller = ['user_id' => 0, 'email' => (string) $o->billing_email];
        $action = (string) $req->get_param('action');
        if ($action === 'offer') {
            $r = self::offer($tid, $seller, $req->get_param('price'), $req->get_param('accept_terms'), 'partner');
            if (is_wp_error($r)) return $r;
        } elseif ($action === 'withdraw') {
            $cur = self::for_ticket($tid);
            if (!$cur || !in_array($cur['status'], ['open', 'locked'], true)) return new WP_Error('tix_resale_missing', 'Für dieses Ticket gibt es kein offenes Angebot.', ['status' => 404]);
            $r = self::withdraw($cur['id'], 'seller');
            if (is_wp_error($r)) return $r;
        }
        return self::nocache(['ok' => true] + self::ticket_payload($tid, $seller));
    }

    /** Plattform: Partner des geteilten Events (Verkauf über die Vermittlung) oder null. */
    private static function mirror_partner($event_id) {
        return class_exists('TIX_Partner_Broker') ? TIX_Partner_Broker::partner_for_event($event_id) : null;
    }

    /** Plattform: Angebote der Quelle, checkout_url auf die eigene Kasse. */
    private static function mirror_public($eid) {
        $empty = ['enabled' => false, 'visible' => false, 'reason' => 'disabled', 'count' => 0, 'deadline' => '', 'info' => '', 'offers' => []];
        $p = self::mirror_partner($eid);
        if (!$p) return $empty;
        $cache = 'tix_resale_mp_' . $eid;
        $data = get_transient($cache);
        if (!is_array($data)) {
            $data = TIX_Partner_Broker::partner_call($p, 'GET', '/partner/resale', null, ['event_id' => (string) TIX_Partner_Broker::source_id_of($eid)]);
            if (is_wp_error($data)) return $empty;
            set_transient($cache, $data, 20);
        }
        unset($data['ok']);
        foreach ((array) ($data['offers'] ?? []) as $i => $of) $data['offers'][$i]['checkout_url'] = self::checkout_url($eid, intval($of['id']));
        return array_merge($empty, $data);
    }

    /** Plattform: Kunden-Route für ein Spiegel-Ticket → bei der Quelle (Nachweis über die Vermittlung). */
    private static function mirror_ticket($tid, $action, WP_REST_Request $req) {
        global $wpdb;
        $partner = (string) get_post_meta($tid, '_tix_ticket_source_partner', true);
        $p = class_exists('TIX_Partners') ? TIX_Partners::get($partner) : null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT source_order_id, source_order_key FROM {$wpdb->prefix}tix_partner_orders WHERE id = %d", intval(get_post_meta($tid, '_tix_ticket_order_id', true))));
        if (!$p || !$row || !intval($row->source_order_id) || !class_exists('TIX_Partner_Broker')) {
            return new WP_Error('tix_resale_not_allowed', 'Dieses Ticket kann hier nicht weiterverkauft werden.', ['status' => 403, 'reason' => 'disabled']);
        }
        $body = ['ticket_id' => intval(get_post_meta($tid, '_tix_ticket_source_ticket_id', true)), 'order_id' => intval($row->source_order_id), 'key' => (string) $row->source_order_key, 'action' => $action];
        if ($action === 'offer') { $body['price'] = $req->get_param('price'); $body['accept_terms'] = filter_var($req->get_param('accept_terms'), FILTER_VALIDATE_BOOLEAN); }
        $data = TIX_Partner_Broker::partner_call($p, 'POST', '/partner/resale/ticket', $body);
        if (is_wp_error($data)) return $data;
        unset($data['ok']);
        return self::nocache($data);
    }
}
