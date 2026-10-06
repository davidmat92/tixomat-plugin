<?php
if (!defined('ABSPATH')) exit;

/**
 * Abrechnungen für Veranstalter (Sammelkonto, nur Mehr-Veranstalter-Modus / evendis.de).
 *
 * Kunden zahlen auf das Konto der Plattform. Nach Event-Ende + Frist (Vorgabe 7 Tage, je Veranstalter
 * durch den Admin änderbar) erstellt ein Cron je Event eine Abrechnung:
 *
 *   Ticketumsatz (was Kunden für Tickets gezahlt haben, ohne Kundengebühren)
 *   − Erstattungen − Plattformgebühr (Veranstalter-Anteil) − Zahlungsgebühren (weiterberechnet)
 *   ± Korrekturen (spätere Erstattungen, Nachverkäufe, Überträge) − Abschläge = Auszahlungsbetrag
 *
 * Grundlage: native Bestellungen (`tix_orders`, wc_order_id = 0) mit Status completed/processing/
 * refunded für Events des Veranstalters (`_tix_organizer_id`). Nicht dabei: geteilte Events
 * (`_tix_syndicated`), vermittelte Bestellungen (eigene Tabelle), Kassenverkäufe vor Ort (pos_*,
 * nur zur Info), kostenlose Bestellungen.
 *
 * Status: draft (wartet auf Auszahlungsdaten) → ready → approved (Admin) → paid; held (zurückgestellt);
 * void (intern: ersetzter Abschlag).
 *
 * Tabellen: {prefix}tix_settlements (je Abrechnung) und {prefix}tix_settlement_items (Positionen;
 * settlement_id = 0 = offener Saldo des Veranstalters, wird mit der nächsten Abrechnung verrechnet).
 */
class TIX_Settlement {

    const DB_VERSION = '1';
    const DB_OPTION  = 'tix_settlement_db';
    const CRON       = 'tix_settlement_cron';
    const STATUSES   = ['draft', 'ready', 'approved', 'paid', 'held'];
    const GATEWAYS   = ['stripe', 'mollie', 'paypal'];

    // Sonderregeln je Veranstalter (nur Admin)
    const META_DELAY   = '_tix_settle_delay_days';
    const META_ADVANCE = '_tix_settle_advance_pct';
    const META_HOLD    = '_tix_settle_hold';

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 6);
        add_action(self::CRON, [__CLASS__, 'run_cron']);
        add_action('init', function () {
            if (self::enabled() && !wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + 120, 'hourly', self::CRON);
            }
        }, 20);
    }

    /** Mehr-Veranstalter-Modus an (evendis.de)? Sonst bleibt alles unberührt. */
    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function enabled() {
        return self::multi() && get_option(self::DB_OPTION) === self::DB_VERSION;
    }

    /** Einstellung mit Vorgabe (tix_get_settings kennt die neuen Schlüssel nicht von sich aus). */
    public static function opt($key) {
        $defaults = [
            'settlement_enabled' => 1, 'settlement_delay_days' => 7, 'settlement_since' => '',
            'settlement_tax_mode' => 'agency', 'settlement_ticket_vat_rate' => 7, 'settlement_fee_vat_rate' => 19,
            'settlement_prefix' => 'EV', 'settlement_invoice_prefix' => 'EVR', 'settlement_refund_alert' => 15,
            'settlement_sepa_enabled' => 0, 'settlement_debtor_name' => '', 'settlement_debtor_iban' => '', 'settlement_debtor_bic' => '',
        ];
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $v = is_array($s) && array_key_exists($key, $s) ? $s[$key] : ($defaults[$key] ?? null);
        if ($key === 'settlement_since' && !$v) $v = get_option('tix_settlement_since', '');
        return $v;
    }

    public static function table()       { global $wpdb; return $wpdb->prefix . 'tix_settlements'; }
    public static function items_table() { global $wpdb; return $wpdb->prefix . 'tix_settlement_items'; }

    /** Tabellen anlegen – nur im Mehr-Veranstalter-Modus (kitchenklub.de/Mallorca bleiben unberührt). */
    public static function maybe_install() {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) return;
        if (!self::multi()) return;
        self::create_tables();
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t  = self::table();
        $ti = self::items_table();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            number varchar(32) NOT NULL DEFAULT '',
            type varchar(16) NOT NULL DEFAULT 'event',
            organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_title varchar(255) NOT NULL DEFAULT '',
            event_date date NULL DEFAULT NULL,
            event_end datetime NULL DEFAULT NULL,
            period_from datetime NULL DEFAULT NULL,
            period_to datetime NULL DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            gross decimal(12,2) NOT NULL DEFAULT 0,
            refunds decimal(12,2) NOT NULL DEFAULT 0,
            platform_fee decimal(12,2) NOT NULL DEFAULT 0,
            gateway_fees decimal(12,2) NOT NULL DEFAULT 0,
            corrections decimal(12,2) NOT NULL DEFAULT 0,
            advances decimal(12,2) NOT NULL DEFAULT 0,
            carry_over decimal(12,2) NOT NULL DEFAULT 0,
            payout_amount decimal(12,2) NOT NULL DEFAULT 0,
            customer_fees decimal(12,2) NOT NULL DEFAULT 0,
            pos_total decimal(12,2) NOT NULL DEFAULT 0,
            tickets int(11) NOT NULL DEFAULT 0,
            orders int(11) NOT NULL DEFAULT 0,
            due_date date NULL DEFAULT NULL,
            tax_mode varchar(16) NOT NULL DEFAULT 'agency',
            invoice_number varchar(32) NOT NULL DEFAULT '',
            invoice_total decimal(12,2) NOT NULL DEFAULT 0,
            held_reason text NULL,
            note text NULL,
            snapshot longtext NULL,
            approved_at datetime NULL DEFAULT NULL,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            paid_at datetime NULL DEFAULT NULL,
            paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
            paid_ref varchar(190) NOT NULL DEFAULT '',
            created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY organizer_status (organizer_id, status),
            KEY event_id (event_id),
            KEY status (status)
        ) {$charset};");
        dbDelta("CREATE TABLE {$ti} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            settlement_id bigint(20) unsigned NOT NULL DEFAULT 0,
            organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(16) NOT NULL DEFAULT 'order',
            gross decimal(12,2) NOT NULL DEFAULT 0,
            refund decimal(12,2) NOT NULL DEFAULT 0,
            platform_fee decimal(12,2) NOT NULL DEFAULT 0,
            gateway_fee decimal(12,2) NOT NULL DEFAULT 0,
            gateway_fee_source varchar(16) NOT NULL DEFAULT '',
            customer_fee decimal(12,2) NOT NULL DEFAULT 0,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            tickets int(11) NOT NULL DEFAULT 0,
            note varchar(255) NOT NULL DEFAULT '',
            ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY settlement_id (settlement_id),
            KEY organizer_open (organizer_id, settlement_id),
            KEY order_event (order_id, event_id)
        ) {$charset};");
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t || $wpdb->get_var("SHOW TABLES LIKE '{$ti}'") !== $ti) return;
        if (!get_option('tix_settlement_since')) update_option('tix_settlement_since', wp_date('Y-m-d'), false);
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    // ──────────────────────────────────────────
    //  Grundlagen: Event-Ende, Fristen, Bestellungen
    // ──────────────────────────────────────────

    /** Event-Ende als Unix-Zeit (wie Einlass-Logik: Ende-Datum/-Zeit, über Mitternacht, sonst Start + 6 h). */
    public static function event_end_ts($event_id) {
        $m  = fn($k) => (string) get_post_meta(intval($event_id), $k, true);
        $ds = $m('_tix_date_start'); $ts = $m('_tix_time_start');
        $de = $m('_tix_date_end');   $te = $m('_tix_time_end');
        $tz = wp_timezone();
        $mk = function ($d, $t) use ($tz) {
            try { return (new DateTimeImmutable($d . ' ' . $t, $tz))->getTimestamp(); } catch (\Exception $e) { return 0; }
        };
        if ($de && $te) return $mk($de, $te);
        if ($de) return $mk($de, '23:59:59');
        if ($te && $ds) {
            $end = $mk($ds, $te);
            if ($end && $ts && strcmp($te, $ts) <= 0) $end += DAY_IN_SECONDS;
            return $end;
        }
        if ($ds && $ts) return $mk($ds, $ts) + 6 * HOUR_IN_SECONDS;
        if ($ds) return $mk($ds, '23:59:59');
        return 0;
    }

    /** Frist in Tagen nach Event-Ende (Sonderregel des Admins oder zentral). */
    public static function delay_days($organizer_id) {
        $v = get_post_meta(intval($organizer_id), self::META_DELAY, true);
        if ($v !== '' && $v !== null && is_numeric($v)) return max(0, intval($v));
        return max(0, intval(self::opt('settlement_delay_days')));
    }

    private static function local_date($ts) {
        return $ts ? wp_date('Y-m-d', $ts) : null;
    }

    private static function local_dt($ts) {
        return $ts ? wp_date('Y-m-d H:i:s', $ts) : null;
    }

    /** Gebühren-Daten einer Bestellung (Option `_tix_order_fees_{id}`) mit Ableitung für alte Bestellungen. */
    public static function order_fees($order_id) {
        $f = get_option('_tix_order_fees_' . intval($order_id));
        $f = is_array($f) ? $f : [];
        $pf   = round(floatval($f['platform_fee'] ?? 0), 2);
        $mode = (string) ($f['platform_fee_mode'] ?? 'organizer');
        if (isset($f['platform_fee_customer'])) {
            $pfc = round(floatval($f['platform_fee_customer']), 2);
            $pfo = round(floatval($f['platform_fee_organizer'] ?? ($pf - $pfc)), 2);
        } else {
            $pfc = $mode === 'customer' ? $pf : 0.0;
            $pfo = $mode === 'customer' ? 0.0 : $pf;
        }
        return [
            'platform_fee'           => $pf,
            'platform_fee_customer'  => $pfc,
            'platform_fee_organizer' => $pfo,
            'customer_fee_line'      => round(floatval($f['customer_fee_line'] ?? 0), 2),
            'gateway_fee'            => round(floatval($f['gateway_fee'] ?? 0), 2),
            'gateway_fee_mode'       => (string) ($f['gateway_fee_mode'] ?? 'organizer'),
            'mode'                   => $mode,
        ];
    }

    /** Bisher erstatteter Betrag einer Bestellung (Summe aller Erstattungen, gedeckelt auf den Gesamtbetrag). */
    public static function refunded_amount($order) {
        $id    = intval($order->id);
        $total = round(floatval($order->total), 2);
        $acc = get_option('_tix_refund_total_' . $id, false);
        if ($acc === false) {
            $last = get_option('_tix_refund_' . $id);
            $acc  = is_array($last) ? floatval($last['amount'] ?? 0) : 0;
        }
        $acc = floatval($acc);
        if ($order->status === 'refunded') $acc = max($acc, $total);
        return round(min($acc, $total), 2);
    }

    /**
     * Zahlungsgebühr einer Bestellung, die der Veranstalter trägt: tatsächliche Anbietergebühr,
     * wo abrufbar (Stripe/Mollie/PayPal), sonst zentraler Satz. 0, wenn der Kunde sie trägt oder
     * keine Online-Zahlung (Überweisung).
     * @return array [betrag, quelle: actual|rate|customer|none]
     */
    public static function gateway_fee_for_order($order, array $fees, $fetch = true) {
        $gw = (string) $order->payment_method;
        if (!in_array($gw, self::GATEWAYS, true)) return [0.0, 'none'];
        if ($fees['gateway_fee_mode'] === 'customer') return [0.0, 'customer'];
        $id = intval($order->id);
        $actual = get_post_meta($id, '_tix_payment_fee', true);
        $usable = function ($v) use ($id, $gw) {
            if ($v === '' || $v === null || !is_numeric($v)) return false;
            if (floatval($v) <= 0 && $gw === 'mollie') return false; // Mollie: 0 = noch nicht abgerechnet
            $cur = (string) get_post_meta($id, '_tix_payment_fee_currency', true);
            return $cur === '' || strtoupper($cur) === 'EUR';
        };
        if (!$usable($actual) && $fetch) {
            try {
                if ($gw === 'stripe' && class_exists('TIX_Gateway_Stripe') && method_exists('TIX_Gateway_Stripe', 'backfill_fee')) TIX_Gateway_Stripe::backfill_fee($id);
                if ($gw === 'mollie' && class_exists('TIX_Gateway_Mollie')) TIX_Gateway_Mollie::backfill_fee($id);
                if ($gw === 'paypal' && class_exists('TIX_Gateway_PayPal')) TIX_Gateway_PayPal::backfill_fee($id);
            } catch (\Throwable $e) {}
            $actual = get_post_meta($id, '_tix_payment_fee', true);
        }
        if ($usable($actual)) return [round(floatval($actual), 2), 'actual'];
        $cfg = class_exists('TIX_Fees') ? TIX_Fees::get_fee_config() : [];
        if (empty($cfg['gateway_fee_fixed']) && empty($cfg['gateway_fee_percent'])) return [0.0, 'rate'];
        return [TIX_Fees::calc_gateway_fee(floatval($order->total), 'organizer', $cfg), 'rate'];
    }

    /**
     * Abrechnungsrelevante Bestellungen eines Events: [order_row, line_event, line_all, tickets].
     * $only_new = nur Bestellungen ohne Position (order/late) für dieses Event.
     */
    public static function event_orders($event_id, $only_new = true) {
        global $wpdb;
        $o  = $wpdb->prefix . 'tix_orders';
        $oi = $wpdb->prefix . 'tix_order_items';
        $si = self::items_table();
        $new_clause = $only_new ? $wpdb->prepare(
            " AND NOT EXISTS (SELECT 1 FROM {$si} s WHERE s.order_id = o.id AND s.event_id = %d AND s.type IN ('order','late'))",
            intval($event_id)
        ) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, SUM(i.total) AS line_event, SUM(i.quantity) AS qty_event,
                    (SELECT SUM(i2.total) FROM {$oi} i2 WHERE i2.order_id = o.id) AS line_all
             FROM {$o} o JOIN {$oi} i ON i.order_id = o.id
             WHERE i.event_id = %d AND o.wc_order_id = 0
               AND o.status IN ('completed','refunded')
               AND o.payment_method NOT LIKE 'pos\\_%%' AND o.payment_method NOT LIKE 'tix\\_pos\\_%%'
               AND (o.total > 0 OR o.discount > 0) {$new_clause}
             GROUP BY o.id ORDER BY o.id ASC",
            intval($event_id)
        ));
        $out = [];
        foreach ((array) $rows as $r) {
            // 100-%-Gutscheine ohne Geldfluss überspringen; Geschenkgutscheine zählen (Geld liegt bei der Plattform)
            if (floatval($r->total) <= 0 && self::giftcard_credit($r->id) <= 0) continue;
            $out[] = $r;
        }
        return $out;
    }

    /** Mit einem Geschenkgutschein der Plattform bezahlter Betrag (zählt zum Ticketumsatz). */
    public static function giftcard_credit($order_id) {
        $c = get_option('_tix_order_coupon_' . intval($order_id));
        if (!is_array($c) || empty($c['code']) || floatval($c['discount'] ?? 0) <= 0) return 0.0;
        if (!class_exists('TIX_Giftcards') || !method_exists('TIX_Giftcards', 'get_card')) return 0.0;
        return TIX_Giftcards::get_card((string) $c['code']) ? round(floatval($c['discount']), 2) : 0.0;
    }

    /** Kassenverkäufe vor Ort (nur Info: das Geld hat der Veranstalter schon). */
    public static function event_pos_total($event_id) {
        global $wpdb;
        $o  = $wpdb->prefix . 'tix_orders';
        $oi = $wpdb->prefix . 'tix_order_items';
        return round(floatval($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(i.total) FROM {$o} o JOIN {$oi} i ON i.order_id = o.id
             WHERE i.event_id = %d AND o.status IN ('completed','processing')
               AND (o.payment_method LIKE 'pos\\_%%' OR o.payment_method LIKE 'tix\\_pos\\_%%')",
            intval($event_id)
        ))), 2);
    }

    /** Werte einer Bestellung anteilig für ein Event (Position „order“). */
    public static function order_line($row, $event_id, $fetch_fee = true) {
        $line_all = floatval($row->line_all);
        $share = $line_all > 0 ? min(1.0, floatval($row->line_event) / $line_all) : 1.0;
        $fees  = self::order_fees($row->id);
        $paid  = round(floatval($row->total), 2);
        $ticket_amount = max(0.0, round($paid - $fees['customer_fee_line'] + self::giftcard_credit($row->id), 2));
        $refunded = self::refunded_amount($row);
        list($gw, $gw_src) = self::gateway_fee_for_order($row, $fees, $fetch_fee);

        $gross  = round($ticket_amount * $share, 2);
        $refund = round(min($refunded, $ticket_amount) * $share, 2);
        $pfee   = round($fees['platform_fee_organizer'] * $share, 2);
        $gfee   = round($gw * $share, 2);
        return [
            'gross'              => $gross,
            'refund'             => $refund,
            'platform_fee'       => $pfee,
            'gateway_fee'        => $gfee,
            'gateway_fee_source' => $gw_src,
            'customer_fee'       => round($fees['customer_fee_line'] * $share, 2),
            'amount'             => round($gross - $refund - $pfee - $gfee, 2),
            'tickets'            => intval($row->qty_event),
        ];
    }

    // ──────────────────────────────────────────
    //  Cron
    // ──────────────────────────────────────────

    public static function run_cron() {
        if (!self::enabled() || !intval(self::opt('settlement_enabled'))) return;
        if (get_transient('tix_settlement_lock')) return;
        set_transient('tix_settlement_lock', 1, 10 * MINUTE_IN_SECONDS);
        $created = [];
        try {
            self::reconcile_refunds();
            self::collect_late_orders();
            foreach (self::due_events() as $eid) {
                $sid = self::create_for_event($eid);
                if ($sid) $created[] = $sid;
            }
            foreach (self::due_balances() as $oid) {
                $sid = self::create_balance($oid);
                if ($sid) $created[] = $sid;
            }
        } finally {
            delete_transient('tix_settlement_lock');
        }
        if ($created) self::notify_admin($created);
    }

    /** Events, deren Frist abgelaufen ist und die noch keine Abrechnung haben. */
    public static function due_events() {
        $since = (string) self::opt('settlement_since');
        $since_ts = 0;
        if ($since) {
            // Mitternacht in der Zeitzone der Seite (Event-Ende wird ebenfalls lokal gerechnet)
            try { $since_ts = (new DateTimeImmutable($since . ' 00:00:00', wp_timezone()))->getTimestamp(); } catch (\Exception $e) { $since_ts = 0; }
        }
        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
            'posts_per_page' => 300,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_organizer_id', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC'],
                ['key' => '_tix_settled_at', 'compare' => 'NOT EXISTS'],
                ['relation' => 'OR',
                    ['key' => '_tix_syndicated', 'compare' => 'NOT EXISTS'],
                    ['key' => '_tix_syndicated', 'value' => '1', 'compare' => '!='],
                ],
                ['key' => '_tix_date_start', 'value' => $since ? wp_date('Y-m-d', $since_ts - 60 * DAY_IN_SECONDS) : '1970-01-01', 'compare' => '>='],
            ],
        ]);
        $due = [];
        $now = time();
        foreach ($ids as $eid) {
            $end = self::event_end_ts($eid);
            if (!$end || ($since_ts && $end < $since_ts)) continue;
            $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
            if ($end + self::delay_days($oid) * DAY_IN_SECONDS <= $now) $due[] = intval($eid);
        }
        return $due;
    }

    /** Erstattungen nach der Abrechnung → Korrektur (offener Saldo). */
    public static function reconcile_refunds() {
        global $wpdb;
        $si = self::items_table();
        $o  = $wpdb->prefix . 'tix_orders';
        $since = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
        $pairs = $wpdb->get_results($wpdb->prepare(
            "SELECT s.order_id, s.event_id, s.organizer_id, SUM(s.refund) AS refunded_before
             FROM {$si} s JOIN {$o} o ON o.id = s.order_id
             WHERE s.order_id > 0 AND s.type IN ('order','late','refund') AND s.created >= %s
               AND (o.status = 'refunded' OR EXISTS (SELECT 1 FROM {$wpdb->options} op WHERE op.option_name = CONCAT('_tix_refund_', o.id)))
             GROUP BY s.order_id, s.event_id, s.organizer_id",
            $since
        ));
        foreach ((array) $pairs as $p) {
            $row = self::order_row_for_event($p->order_id, $p->event_id);
            if (!$row) continue;
            $line = self::order_line($row, $p->event_id, false);
            $delta = round($line['refund'] - floatval($p->refunded_before), 2);
            if ($delta < 0.01) continue;
            self::insert_item([
                'settlement_id' => 0, 'organizer_id' => intval($p->organizer_id), 'event_id' => intval($p->event_id),
                'order_id' => intval($p->order_id), 'type' => 'refund', 'refund' => $delta, 'amount' => -$delta,
                'note' => 'Erstattung nach Abrechnung, Bestellung ' . $row->order_number,
            ]);
        }
    }

    private static function order_row_for_event($order_id, $event_id) {
        global $wpdb;
        $o  = $wpdb->prefix . 'tix_orders';
        $oi = $wpdb->prefix . 'tix_order_items';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, SUM(i.total) AS line_event, SUM(i.quantity) AS qty_event,
                    (SELECT SUM(i2.total) FROM {$oi} i2 WHERE i2.order_id = o.id) AS line_all
             FROM {$o} o JOIN {$oi} i ON i.order_id = o.id
             WHERE o.id = %d AND i.event_id = %d GROUP BY o.id",
            intval($order_id), intval($event_id)
        ));
    }

    /** Bestellungen, die nach der Abrechnung eines Events bezahlt wurden → Nachverkauf (offener Saldo). */
    public static function collect_late_orders() {
        $events = get_posts([
            'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => [
                ['key' => '_tix_settled_at', 'value' => time() - 120 * DAY_IN_SECONDS, 'compare' => '>=', 'type' => 'NUMERIC'],
            ],
        ]);
        foreach ($events as $eid) {
            $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
            if (!$oid) continue;
            foreach (self::event_orders($eid, true) as $row) {
                $line = self::order_line($row, $eid);
                self::insert_item(['settlement_id' => 0, 'organizer_id' => $oid, 'event_id' => $eid, 'order_id' => intval($row->id),
                    'type' => 'late', 'note' => 'Nachverkauf nach Abrechnung, Bestellung ' . $row->order_number] + $line);
            }
        }
    }

    /** Veranstalter mit offenem positivem Saldo, ohne baldige Event-Abrechnung → Saldo-Abrechnung. */
    public static function due_balances() {
        global $wpdb;
        $si = self::items_table();
        $rows = $wpdb->get_results(
            "SELECT organizer_id, SUM(amount) AS total, MIN(created) AS oldest FROM {$si}
             WHERE settlement_id = 0 GROUP BY organizer_id HAVING total > 0"
        );
        $out = [];
        foreach ((array) $rows as $r) {
            $limit = time() - (self::delay_days($r->organizer_id) + 7) * DAY_IN_SECONDS;
            if (strtotime($r->oldest . ' UTC') <= $limit) $out[] = intval($r->organizer_id);
        }
        return $out;
    }

    // ──────────────────────────────────────────
    //  Abrechnungen erstellen
    // ──────────────────────────────────────────

    public static function insert_item(array $d) {
        global $wpdb;
        $row = array_merge([
            'settlement_id' => 0, 'organizer_id' => 0, 'event_id' => 0, 'order_id' => 0, 'type' => 'order',
            'gross' => 0, 'refund' => 0, 'platform_fee' => 0, 'gateway_fee' => 0, 'gateway_fee_source' => '',
            'customer_fee' => 0, 'amount' => 0, 'tickets' => 0, 'note' => '', 'ref_id' => 0,
        ], array_intersect_key($d, array_flip(['settlement_id', 'organizer_id', 'event_id', 'order_id', 'type', 'gross', 'refund',
            'platform_fee', 'gateway_fee', 'gateway_fee_source', 'customer_fee', 'amount', 'tickets', 'note', 'ref_id'])));
        $row['note'] = mb_substr((string) $row['note'], 0, 250);
        $row['created'] = current_time('mysql', true);
        $wpdb->insert(self::items_table(), $row);
        return intval($wpdb->insert_id);
    }

    /** Nächste fortlaufende Nummer (atomar, je Jahr): EV-2026-0001. */
    public static function next_number($kind = 'settlement') {
        global $wpdb;
        $year   = wp_date('Y');
        $prefix = $kind === 'invoice' ? (string) self::opt('settlement_invoice_prefix') : (string) self::opt('settlement_prefix');
        $prefix = $prefix ?: ($kind === 'invoice' ? 'EVR' : 'EV');
        $opt    = 'tix_settlement_seq_' . ($kind === 'invoice' ? 'inv_' : '') . $year;
        if (get_option($opt) === false) add_option($opt, '0', '', 'no');
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", $opt));
        $seq = intval($wpdb->get_var("SELECT LAST_INSERT_ID()"));
        wp_cache_delete($opt, 'options');
        wp_cache_delete('alloptions', 'options');
        return sprintf('%s-%s-%04d', $prefix, $year, $seq);
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", intval($id)));
    }

    public static function items($settlement_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::items_table() . " WHERE settlement_id = %d ORDER BY id ASC", intval($settlement_id))) ?: [];
    }

    /** Abrechnung für ein Event erstellen (Cron oder Admin „jetzt abrechnen“). */
    public static function create_for_event($event_id, $force = false) {
        global $wpdb;
        $event_id = intval($event_id);
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        if (!$oid || get_post_meta($event_id, '_tix_syndicated', true) === '1') return 0;
        if (!$force && get_post_meta($event_id, '_tix_settled_at', true)) return 0;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE event_id = %d AND type = 'event' AND status <> 'void'", $event_id));
        if ($existing) { update_post_meta($event_id, '_tix_settled_at', time()); return 0; }

        $orders  = self::event_orders($event_id, true);
        $open    = self::open_total($oid);
        $adv     = self::advances_for_event($event_id);
        if (!$orders && abs($open) < 0.01 && !$adv) {
            update_post_meta($event_id, '_tix_settled_at', time()); // nichts abzurechnen
            return 0;
        }
        $end = self::event_end_ts($event_id);
        $sid = self::insert_settlement([
            'type'         => 'event',
            'organizer_id' => $oid,
            'event_id'     => $event_id,
            'event_title'  => html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8'),
            'event_date'   => (string) get_post_meta($event_id, '_tix_date_start', true) ?: null,
            'event_end'    => self::local_dt($end),
            'due_date'     => self::local_date(($end ?: time()) + self::delay_days($oid) * DAY_IN_SECONDS),
        ]);
        if (!$sid) return 0;
        update_post_meta($event_id, '_tix_settled_at', time());
        self::build($sid);
        self::issue($sid);
        return $sid;
    }

    /** Saldo-Abrechnung ohne Event (offene Korrekturen/Nachverkäufe). */
    public static function create_balance($organizer_id) {
        $oid = intval($organizer_id);
        if (abs(self::open_total($oid)) < 0.01) return 0;
        $sid = self::insert_settlement([
            'type' => 'balance', 'organizer_id' => $oid, 'event_title' => 'Saldo-Abrechnung',
            'due_date' => wp_date('Y-m-d'),
        ]);
        if (!$sid) return 0;
        self::build($sid);
        self::issue($sid);
        return $sid;
    }

    /** Abschlag vor dem Event (nur wenn der Admin ihn für den Veranstalter erlaubt hat). */
    public static function create_advance($event_id, $percent = null) {
        global $wpdb;
        $event_id = intval($event_id);
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        $pct = $percent !== null ? floatval($percent) : floatval(get_post_meta($oid, self::META_ADVANCE, true));
        if (!$oid || $pct <= 0) return new WP_Error('tix_no_advance', 'Für diesen Veranstalter ist kein Abschlag freigeschaltet.');
        $final = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE event_id = %d AND type = 'event' AND status <> 'void'", $event_id));
        if ($final) return new WP_Error('tix_settled', 'Für dieses Event gibt es schon die Abrechnung.');
        $net = 0.0;
        $tickets = 0; $count = 0;
        foreach (self::event_orders($event_id, true) as $row) {
            $l = self::order_line($row, $event_id);
            $net += $l['amount']; $tickets += $l['tickets']; $count++;
        }
        $already = self::advances_for_event($event_id);
        $amount = round(max(0, $net * min(100, $pct) / 100 - $already), 2);
        if ($amount < 0.01) return new WP_Error('tix_no_amount', 'Kein Betrag für einen Abschlag verfügbar.');
        $sid = self::insert_settlement([
            'type' => 'advance', 'organizer_id' => $oid, 'event_id' => $event_id,
            'event_title' => html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8'),
            'event_date' => (string) get_post_meta($event_id, '_tix_date_start', true) ?: null,
            'due_date' => wp_date('Y-m-d'), 'payout_amount' => $amount, 'tickets' => $tickets, 'orders' => $count,
            'note' => sprintf('Abschlag %s %% vom bisherigen Netto (%s €)', rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ','), number_format($net, 2, ',', '.')),
        ]);
        if (!$sid) return 0;
        self::update($sid, ['snapshot' => wp_json_encode(self::snapshot($oid))]);
        self::issue($sid);
        return $sid;
    }

    /** Summe der Abschläge eines Events (freigegeben/ausgezahlt/bereit). */
    private static function advances_for_event($event_id) {
        global $wpdb;
        return round(floatval($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(payout_amount) FROM " . self::table() . " WHERE event_id = %d AND type = 'advance' AND status IN ('draft','ready','approved','paid','held')",
            intval($event_id)
        ))), 2);
    }

    private static function insert_settlement(array $d) {
        global $wpdb;
        $now = current_time('mysql');
        $row = array_merge([
            'number' => self::next_number(), 'status' => 'draft', 'tax_mode' => (string) self::opt('settlement_tax_mode'),
            'created' => $now, 'updated' => $now,
        ], $d);
        $wpdb->insert(self::table(), $row);
        return intval($wpdb->insert_id);
    }

    public static function update($id, array $fields) {
        global $wpdb;
        $fields['updated'] = current_time('mysql');
        return $wpdb->update(self::table(), $fields, ['id' => intval($id)]);
    }

    public static function open_total($organizer_id) {
        global $wpdb;
        return round(floatval($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM " . self::items_table() . " WHERE settlement_id = 0 AND organizer_id = %d", intval($organizer_id)
        ))), 2);
    }

    /**
     * Positionen zusammenstellen und Summen berechnen (auch für „Neu berechnen“):
     * Bestellungen des Events, offene Posten des Veranstalters, Abschläge.
     */
    public static function build($sid, $fetch_fees = true) {
        global $wpdb;
        $s = self::get($sid);
        if (!$s || $s->type === 'advance') return;
        $si = self::items_table();
        $oid = intval($s->organizer_id);

        // Event-Bestellungen
        if ($s->type === 'event' && $s->event_id) {
            foreach (self::event_orders($s->event_id, true) as $row) {
                $line = self::order_line($row, $s->event_id, $fetch_fees);
                self::insert_item(['settlement_id' => $sid, 'organizer_id' => $oid, 'event_id' => intval($s->event_id),
                    'order_id' => intval($row->id), 'type' => 'order', 'note' => (string) $row->order_number] + $line);
            }
            // Abschläge verrechnen; nicht freigegebene werden ersetzt
            $advs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE event_id = %d AND type = 'advance' AND status NOT IN ('void')", intval($s->event_id)
            ));
            foreach ((array) $advs as $a) {
                if (in_array($a->status, ['approved', 'paid'], true)) {
                    self::insert_item(['settlement_id' => $sid, 'organizer_id' => $oid, 'event_id' => intval($s->event_id),
                        'type' => 'advance', 'amount' => -round(floatval($a->payout_amount), 2), 'ref_id' => intval($a->id),
                        'note' => 'Abschlag ' . $a->number]);
                } else {
                    self::update($a->id, ['status' => 'void', 'held_reason' => 'Ersetzt durch Abrechnung ' . $s->number]);
                }
            }
        }
        // Offene Posten (Korrekturen, Nachverkäufe, Überträge) übernehmen
        $wpdb->query($wpdb->prepare("UPDATE {$si} SET settlement_id = %d WHERE settlement_id = 0 AND organizer_id = %d", $sid, $oid));

        self::totals($sid);
    }

    /** Summen aus den Positionen; negativer Betrag → 0 auszahlen, Rest als Übertrag offen lassen. */
    public static function totals($sid) {
        global $wpdb;
        $s = self::get($sid);
        if (!$s) return;
        $si = self::items_table();
        $sum = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN type = 'order' THEN gross ELSE 0 END) AS gross,
                SUM(CASE WHEN type = 'order' THEN refund ELSE 0 END) AS refunds,
                SUM(CASE WHEN type = 'order' THEN platform_fee ELSE 0 END) AS platform_fee,
                SUM(CASE WHEN type = 'order' THEN gateway_fee ELSE 0 END) AS gateway_fees,
                SUM(CASE WHEN type IN ('late','refund','correction','carry') THEN amount ELSE 0 END) AS corrections,
                SUM(CASE WHEN type = 'advance' THEN -amount ELSE 0 END) AS advances,
                SUM(CASE WHEN type = 'carry_out' THEN -amount ELSE 0 END) AS carry_out,
                SUM(CASE WHEN type IN ('order','late') THEN customer_fee ELSE 0 END) AS customer_fees,
                SUM(CASE WHEN type IN ('order','late') THEN tickets ELSE 0 END) AS tickets,
                COUNT(DISTINCT CASE WHEN type IN ('order','late') THEN order_id END) AS orders,
                SUM(CASE WHEN type IN ('order','late') THEN platform_fee ELSE 0 END) AS fee_all,
                SUM(CASE WHEN type IN ('order','late') THEN gateway_fee ELSE 0 END) AS gw_all,
                SUM(CASE WHEN type <> 'carry_out' THEN amount ELSE 0 END) AS net
             FROM {$si} WHERE settlement_id = %d",
            $sid
        ));
        $net = round(floatval($sum->net), 2);
        // Alten Übertrag dieser Abrechnung entfernen und neu bilden
        $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE type = 'carry' AND ref_id = %d AND settlement_id = 0", $sid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE type = 'carry_out' AND settlement_id = %d", $sid));
        $carry = 0.0;
        if ($net < 0) {
            $carry = $net;
            self::insert_item(['settlement_id' => $sid, 'organizer_id' => intval($s->organizer_id), 'type' => 'carry_out',
                'amount' => -$net, 'note' => 'Übertrag auf die nächste Abrechnung']);
            self::insert_item(['settlement_id' => 0, 'organizer_id' => intval($s->organizer_id), 'type' => 'carry',
                'amount' => $net, 'ref_id' => $sid, 'note' => 'Übertrag aus Abrechnung ' . $s->number]);
        }
        $period = $wpdb->get_row($wpdb->prepare(
            "SELECT MIN(o.date_created) AS f, MAX(o.date_created) AS t FROM {$wpdb->prefix}tix_orders o
             JOIN {$si} s ON s.order_id = o.id WHERE s.settlement_id = %d", $sid
        ));
        $fee_inv = round(floatval($sum->fee_all) + floatval($sum->gw_all), 2);
        $fields = [
            'gross'         => round(floatval($sum->gross), 2),
            'refunds'       => round(floatval($sum->refunds), 2),
            'platform_fee'  => round(floatval($sum->platform_fee), 2),
            'gateway_fees'  => round(floatval($sum->gateway_fees), 2),
            'corrections'   => round(floatval($sum->corrections), 2),
            'advances'      => round(floatval($sum->advances), 2),
            'carry_over'    => round($carry, 2),
            'payout_amount' => max(0, $net),
            'customer_fees' => round(floatval($sum->customer_fees), 2),
            'tickets'       => intval($sum->tickets),
            'orders'        => intval($sum->orders),
            'period_from'   => $period && $period->f ? $period->f : null,
            'period_to'     => $period && $period->t ? $period->t : null,
            'invoice_total' => $s->tax_mode === 'agency' ? max(0, $fee_inv) : 0,
            'snapshot'      => wp_json_encode(self::snapshot($s->organizer_id)),
        ];
        if ($s->type === 'event' && $s->event_id) $fields['pos_total'] = self::event_pos_total($s->event_id);
        if ($fields['invoice_total'] > 0 && $s->invoice_number === '') $fields['invoice_number'] = self::next_number('invoice');
        self::update($sid, $fields);
    }

    /** Status nach dem Erstellen setzen + Mitteilungen. */
    private static function issue($sid) {
        $s = self::get($sid);
        if (!$s) return;
        $oid = intval($s->organizer_id);
        if (get_post_meta($oid, self::META_HOLD, true)) {
            self::update($sid, ['status' => 'held', 'held_reason' => 'Auszahlungen für diesen Veranstalter angehalten.']);
        } elseif (class_exists('TIX_Payout_Details') && TIX_Payout_Details::complete($oid)) {
            self::update($sid, ['status' => 'ready']);
        } else {
            self::update($sid, ['status' => 'draft']);
        }
        self::notify_created($sid);
    }

    /** Positionen lösen und neu aufbauen (Entwurf/bereit/zurückgestellt). */
    public static function recalculate($sid, $fetch_fees = true) {
        global $wpdb;
        $s = self::get($sid);
        if (!$s || !in_array($s->status, ['draft', 'ready', 'held'], true)) return false;
        if ($s->type === 'advance') return false;
        $si = self::items_table();
        // Wurde der Übertrag schon in einer späteren Abrechnung verrechnet, nicht mehr anfassen
        $used = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$si} WHERE type = 'carry' AND ref_id = %d AND settlement_id > 0", $sid));
        if ($used) return false;
        $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE settlement_id = %d AND type IN ('order','advance','carry_out')", $sid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE settlement_id = 0 AND type = 'carry' AND ref_id = %d", $sid));
        $wpdb->query($wpdb->prepare("UPDATE {$si} SET settlement_id = 0 WHERE settlement_id = %d", $sid));
        // ersetzte Abschläge wiederherstellen
        if ($s->event_id) {
            $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET status = 'ready' WHERE event_id = %d AND type = 'advance' AND status = 'void' AND held_reason = %s",
                intval($s->event_id), 'Ersetzt durch Abrechnung ' . $s->number));
        }
        self::build($sid, $fetch_fees);
        return true;
    }

    /** Snapshot für Beleg/PDF: Veranstalter-, Rechnungs- und Bankdaten (IBAN verschlüsselt). */
    public static function snapshot($organizer_id) {
        $oid = intval($organizer_id);
        $pd  = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::payload($oid) : [];
        $iban = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::iban($oid) : '';
        return [
            'organizer'   => html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8'),
            'holder'      => (string) ($pd['holder'] ?? ''),
            'iban_enc'    => $iban !== '' ? TIX_Payout_Details::encrypt($iban) : '',
            'iban_masked' => (string) ($pd['iban_masked'] ?? ''),
            'bic'         => (string) ($pd['bic'] ?? ''),
            'billing'     => $pd['billing'] ?? [],
            'tax_status'  => (string) ($pd['tax_status'] ?? ''),
            'vat_id'      => (string) ($pd['vat_id'] ?? ''),
            'tax_number'  => (string) ($pd['tax_number'] ?? ''),
            'email'       => class_exists('TIX_Payout_Details') ? TIX_Payout_Details::billing_email($oid) : '',
        ];
    }

    public static function snap($s) {
        $d = json_decode((string) $s->snapshot, true);
        return is_array($d) ? $d : [];
    }

    // ──────────────────────────────────────────
    //  Status (Admin)
    // ──────────────────────────────────────────

    /** Auszahlungsdaten geändert: Entwürfe → bereit; freigegebene mit anderer IBAN → erneut freigeben. */
    public static function payout_details_changed($organizer_id) {
        global $wpdb;
        if (!self::enabled()) return;
        $oid = intval($organizer_id);
        $complete = class_exists('TIX_Payout_Details') && TIX_Payout_Details::complete($oid);
        $iban = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::iban($oid) : '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE organizer_id = %d AND status IN ('draft','ready','approved')", $oid));
        foreach ((array) $rows as $s) {
            if ($s->status === 'draft' && $complete) {
                self::update($s->id, ['status' => 'ready', 'snapshot' => wp_json_encode(self::snapshot($oid))]);
                self::notify_created($s->id); // jetzt mit PDF
            } elseif ($s->status === 'ready') {
                self::update($s->id, $complete ? ['snapshot' => wp_json_encode(self::snapshot($oid))] : ['status' => 'draft']);
            } elseif ($s->status === 'approved') {
                $snap = self::snap($s);
                $old = !empty($snap['iban_enc']) ? TIX_Payout_Details::decrypt($snap['iban_enc']) : '';
                if ($old !== $iban) {
                    self::update($s->id, ['status' => $complete ? 'ready' : 'draft', 'approved_at' => null, 'approved_by' => 0,
                        'note' => trim((string) $s->note . "\nBankverbindung am " . wp_date('d.m.Y H:i') . ' geändert – bitte erneut freigeben.'),
                        'snapshot' => wp_json_encode(self::snapshot($oid))]);
                }
            }
        }
    }

    public static function approve($sid) {
        $s = self::get($sid);
        if (!$s || $s->status !== 'ready') return new WP_Error('tix_state', 'Nur bereite Abrechnungen können freigegeben werden.');
        if (!TIX_Payout_Details::complete($s->organizer_id)) return new WP_Error('tix_details', 'Auszahlungsdaten des Veranstalters sind unvollständig.');
        self::update($sid, ['status' => 'approved', 'approved_at' => current_time('mysql'), 'approved_by' => get_current_user_id(),
            'snapshot' => wp_json_encode(self::snapshot($s->organizer_id))]);
        return true;
    }

    public static function hold($sid, $reason) {
        $s = self::get($sid);
        if (!$s || !in_array($s->status, ['draft', 'ready', 'approved'], true)) return new WP_Error('tix_state', 'Diese Abrechnung kann nicht zurückgestellt werden.');
        self::update($sid, ['status' => 'held', 'held_reason' => sanitize_text_field($reason), 'approved_at' => null, 'approved_by' => 0]);
        return true;
    }

    public static function release($sid) {
        $s = self::get($sid);
        if (!$s || $s->status !== 'held') return new WP_Error('tix_state', 'Nur zurückgestellte Abrechnungen können wieder aufgenommen werden.');
        $ok = TIX_Payout_Details::complete($s->organizer_id);
        self::update($sid, ['status' => $ok ? 'ready' : 'draft', 'held_reason' => '']);
        return true;
    }

    public static function mark_paid($sid, $ref = '', $date = '') {
        $s = self::get($sid);
        if (!$s || $s->status !== 'approved') return new WP_Error('tix_state', 'Nur freigegebene Abrechnungen können als ausgezahlt markiert werden.');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) ? $date . ' 12:00:00' : current_time('mysql');
        self::update($sid, ['status' => 'paid', 'paid_at' => $date, 'paid_by' => get_current_user_id(), 'paid_ref' => sanitize_text_field($ref)]);
        self::notify_paid($sid);
        return true;
    }

    /** Verwendungszweck für die Überweisung (max. 140 Zeichen SEPA). */
    public static function remittance($s) {
        $t = $s->type === 'event' || $s->type === 'advance' ? ' ' . $s->event_title : '';
        $label = $s->type === 'advance' ? 'Abschlag ' : 'Abrechnung ';
        $txt = $label . $s->number . $t;
        $txt = preg_replace('/[^A-Za-z0-9 \/\-\?:\(\)\.,\'\+ÄÖÜäöüß&]/u', '', $txt);
        return mb_substr(trim($txt), 0, 140);
    }

    // ──────────────────────────────────────────
    //  Mitteilungen
    // ──────────────────────────────────────────

    /** Inhaber + Team-Admins (Rolle tix_organizer) eines Veranstalters. */
    public static function manager_user_ids($organizer_id) {
        $oid = intval($organizer_id);
        $ids = [];
        $owner = intval(get_post_meta($oid, '_tix_org_user_id', true));
        if ($owner) $ids[] = $owner;
        $team = get_users(['meta_key' => '_tix_team_organizer_id', 'meta_value' => $oid, 'role' => 'tix_organizer', 'fields' => 'ID']);
        foreach ((array) $team as $u) $ids[] = intval($u);
        return array_values(array_unique(array_filter($ids)));
    }

    private static function feed($organizer_id, $title, $body, $action) {
        if (!class_exists('TIX_Notifications') || !method_exists('TIX_Notifications', 'add_user_item')) return;
        foreach (self::manager_user_ids($organizer_id) as $uid) {
            try {
                TIX_Notifications::add_user_item($uid, $title, $body, ['type' => 'settlement', 'action' => $action]);
            } catch (\Throwable $e) {}
        }
    }

    public static function money($v) {
        return number_format(floatval($v), 2, ',', '.') . ' €';
    }

    private static function dashboard_url() {
        return admin_url('admin.php?page=tix-organizer-payouts');
    }

    public static function notify_created($sid) {
        $s = self::get($sid);
        if (!$s) return;
        $oid = intval($s->organizer_id);
        $title = $s->type === 'advance' ? 'Abschlag für ' . $s->event_title . ' erstellt'
            : ($s->type === 'balance' ? 'Saldo-Abrechnung erstellt' : 'Abrechnung für ' . $s->event_title . ' erstellt');
        $due = $s->due_date ? mysql2date('d.m.Y', $s->due_date) : '';
        $body = 'Auszahlung: ' . self::money($s->payout_amount) . ($due ? ' · geplant ab ' . $due : '');
        if ($s->status === 'draft') {
            $body = 'Bitte hinterlege deine Auszahlungsdaten, damit wir ' . self::money($s->payout_amount) . ' überweisen können.';
        }
        self::feed($oid, $title, $body, $s->status === 'draft' ? 'payout:details' : 'settlement:' . $sid);

        $to = TIX_Payout_Details::billing_email($oid);
        if (!$to) return;
        $html = '<p>' . esc_html($title) . '.</p>'
              . '<table style="border-collapse:collapse;font-size:14px;">'
              . '<tr><td style="padding:3px 16px 3px 0;">Nummer</td><td><strong>' . esc_html($s->number) . '</strong></td></tr>'
              . ($s->type !== 'advance' ? '<tr><td style="padding:3px 16px 3px 0;">Ticketumsatz</td><td>' . esc_html(self::money($s->gross)) . '</td></tr>' : '')
              . '<tr><td style="padding:3px 16px 3px 0;">Auszahlungsbetrag</td><td><strong>' . esc_html(self::money($s->payout_amount)) . '</strong></td></tr>'
              . ($due ? '<tr><td style="padding:3px 16px 3px 0;">Geplante Auszahlung</td><td>ab ' . esc_html($due) . '</td></tr>' : '')
              . '</table>';
        if ($s->status === 'draft') {
            $html .= '<p><strong>Es fehlen noch deine Auszahlungsdaten</strong> (Kontoinhaber, IBAN, Rechnungsadresse, Steuer-Status). '
                   . 'Du kannst sie in der App unter Veranstalter → Auszahlung oder im Veranstalter-Bereich hinterlegen.</p>';
        } else {
            $html .= '<p>Die Abrechnung liegt als PDF bei und ist im Veranstalter-Bereich und in der App abrufbar.</p>';
        }
        $html .= '<p><a href="' . esc_url(self::dashboard_url()) . '">Zu den Abrechnungen</a></p>';
        $files = $s->status === 'draft' ? [] : self::temp_pdfs($s);
        TIX_Payout_Details::mail($to, $title . ' (' . $s->number . ')', $title, $html, $files);
        foreach ($files as $f) @unlink($f);
    }

    public static function notify_paid($sid) {
        $s = self::get($sid);
        if (!$s) return;
        $oid = intval($s->organizer_id);
        $title = self::money($s->payout_amount) . ' wurde überwiesen';
        $what = $s->type === 'balance' ? 'Saldo-Abrechnung ' . $s->number : ($s->type === 'advance' ? 'Abschlag ' : 'Abrechnung ') . $s->number . ' · ' . $s->event_title;
        self::feed($oid, $title, $what, 'settlement:' . $sid);
        $to = TIX_Payout_Details::billing_email($oid);
        if (!$to) return;
        $snap = self::snap($s);
        $html = '<p>Wir haben <strong>' . esc_html(self::money($s->payout_amount)) . '</strong> überwiesen (' . esc_html($what) . ').</p>'
              . '<p>Empfänger: ' . esc_html($snap['holder'] ?? '') . ' · ' . esc_html($snap['iban_masked'] ?? '') . '<br>'
              . 'Verwendungszweck: ' . esc_html(self::remittance($s)) . '</p>'
              . '<p>Je nach Bank ist das Geld in 1–2 Werktagen auf deinem Konto.</p>';
        TIX_Payout_Details::mail($to, $title, 'Auszahlung überwiesen', $html);
    }

    /** Admin: neue Abrechnungen warten auf Freigabe. */
    private static function notify_admin(array $ids) {
        $to = (string) (tix_get_settings('invoice_email') ?: get_option('admin_email'));
        if (!$to) return;
        $rows = '';
        foreach ($ids as $id) {
            $s = self::get($id);
            if (!$s) continue;
            $rows .= '<tr><td style="padding:3px 12px 3px 0;">' . esc_html($s->number) . '</td><td style="padding:3px 12px 3px 0;">'
                   . esc_html(get_the_title($s->organizer_id)) . '</td><td style="padding:3px 12px 3px 0;">' . esc_html($s->event_title)
                   . '</td><td style="text-align:right;">' . esc_html(self::money($s->payout_amount)) . '</td></tr>';
        }
        if (!$rows) return;
        $html = '<p>Neue Abrechnungen warten auf deine Freigabe:</p><table style="border-collapse:collapse;font-size:14px;">' . $rows . '</table>'
              . '<p><a href="' . esc_url(admin_url('admin.php?page=tix-payouts')) . '">Zu den Auszahlungen</a></p>';
        TIX_Payout_Details::mail($to, count($ids) . ' neue Abrechnung(en) zur Freigabe', 'Auszahlungen', $html);
    }

    /** PDFs als Mail-Anhang in temporäre Dateien schreiben. */
    private static function temp_pdfs($s) {
        if (!class_exists('TIX_Settlement_PDF')) return [];
        $out = [];
        $dir = trailingslashit(get_temp_dir());
        foreach (['settlement', 'invoice'] as $doc) {
            $pdf = TIX_Settlement_PDF::render($s, $doc);
            if (!$pdf) continue;
            $file = $dir . TIX_Settlement_PDF::filename($s, $doc);
            if (@file_put_contents($file, $pdf['bytes']) !== false) $out[] = $file;
        }
        return $out;
    }

    // ──────────────────────────────────────────
    //  Ausgabe für App/Web
    // ──────────────────────────────────────────

    /** Listen-Element (App-Vertrag). */
    public static function list_payload($s) {
        $paid = $s->paid_at ? substr($s->paid_at, 0, 10) : null;
        return [
            'id'             => intval($s->id),
            'number'         => (string) $s->number,
            'type'           => (string) $s->type,
            'event_id'       => intval($s->event_id),
            'event_title'    => (string) $s->event_title,
            'event_date'     => $s->event_date ?: null,
            'status'         => (string) $s->status,
            'payout_amount'  => round(floatval($s->payout_amount), 2),
            'payout_date'    => $paid ?: ($s->due_date ?: null),
            'paid_at'        => $s->paid_at ?: null,
            'has_pdf'        => $s->status !== 'draft',
            'has_invoice'    => $s->status !== 'draft' && $s->invoice_number !== '' && floatval($s->invoice_total) > 0,
            'invoice_number' => (string) $s->invoice_number,
        ];
    }

    /** Detail (App-Vertrag): Liste + Summen + Zusatzzeilen. */
    public static function detail_payload($s) {
        $snap = self::snap($s);
        $lines = [];
        foreach (self::items($s->id) as $it) {
            if (in_array($it->type, ['order'], true)) continue;
            $lines[] = ['label' => self::item_label($it), 'amount' => round(floatval($it->amount), 2), 'type' => (string) $it->type];
        }
        return self::list_payload($s) + [
            'period_from'   => $s->period_from ? substr($s->period_from, 0, 10) : null,
            'period_to'     => $s->period_to ? substr($s->period_to, 0, 10) : null,
            'gross'         => round(floatval($s->gross), 2),
            'refunds'       => round(floatval($s->refunds), 2),
            'platform_fee'  => round(floatval($s->platform_fee), 2),
            'gateway_fees'  => round(floatval($s->gateway_fees), 2),
            'corrections'   => round(floatval($s->corrections), 2),
            'advances'      => round(floatval($s->advances), 2),
            'carry_over'    => round(floatval($s->carry_over), 2),
            'customer_fees' => round(floatval($s->customer_fees), 2),
            'pos_total'     => round(floatval($s->pos_total), 2),
            'tickets'       => intval($s->tickets),
            'orders'        => intval($s->orders),
            'held_reason'   => $s->status === 'held' ? (string) $s->held_reason : '',
            'tax_mode'      => (string) $s->tax_mode,
            'note'          => (string) $s->note,
            'lines'         => $lines,
            'iban_masked'   => (string) ($snap['iban_masked'] ?? ''),
        ];
    }

    public static function item_label($it) {
        switch ($it->type) {
            case 'late':      return 'Nachverkauf: ' . $it->note;
            case 'refund':    return $it->note ?: 'Erstattung nach Abrechnung';
            case 'carry':     return $it->note ?: 'Übertrag';
            case 'carry_out': return $it->note ?: 'Übertrag auf die nächste Abrechnung';
            case 'advance':   return $it->note ?: 'Abschlag';
            case 'correction':return $it->note ?: 'Korrektur';
        }
        return (string) $it->note;
    }

    /**
     * Saldo-Übersicht eines Veranstalters (App: GET /organizer/balance).
     * sold_total = Ticketumsatz über die Plattform (abgerechnet + noch offen), ohne Kundengebühren.
     */
    public static function balance($organizer_id) {
        global $wpdb;
        $oid = intval($organizer_id);
        $t = self::table();
        $ready = self::enabled();
        $settled_gross = 0.0; $paid = 0.0; $open = 0.0; $next = null;
        if ($ready) {
            $settled_gross = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT SUM(gross) FROM " . self::items_table() . " WHERE organizer_id = %d AND type IN ('order','late')", $oid)));
            $paid = floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(payout_amount) FROM {$t} WHERE organizer_id = %d AND status = 'paid'", $oid)));
            $open = self::open_total($oid);
            $n = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t} WHERE organizer_id = %d AND status IN ('draft','ready','approved','held') ORDER BY due_date ASC, id ASC LIMIT 1", $oid));
            if ($n) $next = ['amount' => round(floatval($n->payout_amount), 2), 'date' => $n->due_date ?: null, 'settlement_id' => intval($n->id), 'status' => (string) $n->status];
        }
        // Noch nicht abgerechnete Events (Schätzung, ohne Anbieter-Abfragen)
        $pending_gross = 0.0; $pending_net = 0.0; $soonest = null;
        $events = get_posts([
            'post_type' => 'event', 'post_status' => ['publish', 'private', 'draft', 'pending', 'future'], 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => [
                ['key' => '_tix_organizer_id', 'value' => strval($oid)],
                ['key' => '_tix_settled_at', 'compare' => 'NOT EXISTS'],
            ],
        ]);
        foreach ($events as $eid) {
            if (get_post_meta($eid, '_tix_syndicated', true) === '1') continue;
            $g = 0.0; $n2 = 0.0;
            foreach (self::event_orders($eid, true) as $row) {
                $l = self::order_line($row, $eid, false);
                $g += $l['gross'] - $l['refund']; $n2 += $l['amount'];
            }
            if ($g <= 0 && $n2 <= 0) continue;
            $pending_gross += $g; $pending_net += $n2;
            $end = self::event_end_ts($eid);
            $date = self::local_date(($end ?: time()) + self::delay_days($oid) * DAY_IN_SECONDS);
            if ($soonest === null || strcmp($date, $soonest['date']) < 0) $soonest = ['amount' => round($n2, 2), 'date' => $date, 'settlement_id' => null, 'status' => 'pending'];
        }
        if (!$next && $soonest) $next = $soonest;
        return [
            'currency'                => 'EUR',
            'sold_total'              => round($settled_gross + $pending_gross, 2),
            'pending_total'           => round($pending_net, 2),
            'paid_out_total'          => round($paid, 2),
            'open_balance'            => round($open, 2),
            'next_payout'             => $next,
            'payout_details_complete' => class_exists('TIX_Payout_Details') ? TIX_Payout_Details::complete($oid) : false,
            'delay_days'              => self::delay_days($oid),
        ];
    }

    /** Abrechnungen eines Veranstalters (ohne ersetzte Abschläge). */
    public static function for_organizer($organizer_id, $page = 1, $per_page = 20) {
        global $wpdb;
        $t = self::table();
        $page = max(1, intval($page));
        $per = max(1, min(100, intval($per_page)));
        $total = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE organizer_id = %d AND status <> 'void'", intval($organizer_id))));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE organizer_id = %d AND status <> 'void' ORDER BY id DESC LIMIT %d OFFSET %d",
            intval($organizer_id), $per, ($page - 1) * $per
        ));
        return ['rows' => $rows ?: [], 'total' => $total, 'has_more' => $page * $per < $total];
    }
}
