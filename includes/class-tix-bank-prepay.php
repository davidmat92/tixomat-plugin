<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Bank_Prepay – Vorkasse per Überweisung in der App- und Homepage-Kasse (1.38.915, User 23.11., Vertrag
 * uebergaben/vertrag-vorkasse-ueberweisung-2026-11-23.md, Doku §262).
 *
 * Frist: früheres Datum von (Bestelltag + 7) und (Tag des frühesten Event-Beginns − 2), Ende des Tages Europe/Berlin;
 * früheste Event weniger als 3 Tage entfernt → keine Vorkasse. Nur Bestellungen über POST /customer/orders bekommen eine
 * Frist (WordPress-Kasse unverändert). Angeboten nur Clients, die `supports: ["bank"]` schicken (alte App-Builds sehen nichts).
 *
 * Pro Bestellung TIX_Object_Data `_tix_bank_due` {due_at, due_date, reminded_at, expired_at, set_by}.
 * Cron stündlich: Erinnerung 2 Tage vor Fristende (einmal), Auto-Storno nach Fristende (gateway bank_expired).
 * POST /orders/{id}/bank-due {due_date} – Frist ändern (Rechte wie mark-paid).
 */
class TIX_Bank_Prepay {

    const NS = 'tixomat/v1';
    const KEY = '_tix_bank_due';
    const DAYS = 7;
    const BEFORE_EVENT_DAYS = 2;
    const MIN_LEAD_HOURS = 72;
    const REMIND_DAYS = 2;
    const CRON = 'tix_bank_prepay_cron';
    const TZ = 'Europe/Berlin';
    const TITLE = 'Überweisung (Vorkasse)';
    const HINT = 'Überweisung wird in der Kasse als Vorkasse angeboten: 7 Tage Frist (spätestens 2 Tage vor dem Event, ab 3 Tagen vor dem Event nicht mehr), danach automatisches Storno.';

    /** Test: feste Uhr */
    public static $now = null;
    /** Storno läuft gerade wegen Fristablauf (für die Storno-Mail) */
    public static $expiring = 0;

    public static function init() {
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () { if (!wp_next_scheduled(TIX_Bank_Prepay::CRON)) wp_schedule_event(time() + 300, 'hourly', TIX_Bank_Prepay::CRON); }, 28);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function now() { return self::$now !== null ? intval(self::$now) : time(); }

    // ═══════════════ Reine Regel ═══════════════

    /**
     * Frist für eine Bestellung zum Zeitpunkt $order_ts bei Events mit Beginn $event_starts (Unix) →
     * {allowed, due_date, due_at (Unix), reason}. Ohne Events: 7 Tage.
     */
    public static function due_for($order_ts, array $event_starts) {
        $tz = new DateTimeZone(self::TZ);
        $order = (new DateTimeImmutable('@' . intval($order_ts)))->setTimezone($tz);
        $due = $order->modify('+' . self::DAYS . ' days')->setTime(0, 0);
        $starts = array_values(array_filter(array_map('intval', $event_starts)));
        if ($starts) {
            $first = min($starts);
            if ($first - intval($order_ts) < self::MIN_LEAD_HOURS * HOUR_IN_SECONDS) return ['allowed' => false, 'due_date' => null, 'due_at' => null, 'reason' => 'too_close'];
            $ev = (new DateTimeImmutable('@' . $first))->setTimezone($tz)->setTime(0, 0)->modify('-' . self::BEFORE_EVENT_DAYS . ' days');
            if ($ev < $due) $due = $ev;
        }
        $end = $due->setTime(23, 59, 59);
        return ['allowed' => true, 'due_date' => $due->format('Y-m-d'), 'due_at' => $end->getTimestamp(), 'reason' => null];
    }

    public static function iso($ts) { return (new DateTimeImmutable('@' . intval($ts)))->setTimezone(new DateTimeZone(self::TZ))->format('c'); }

    public static function label($date) { $d = DateTimeImmutable::createFromFormat('Y-m-d', (string) $date, new DateTimeZone(self::TZ)); return $d ? 'bis ' . $d->format('d.m.Y') : ''; }

    /** Beginn eines Events (Unix, Zeitzone der Seite) | 0 */
    public static function event_start($event_id) {
        $d = (string) get_post_meta(intval($event_id), '_tix_date_start', true);
        if ($d === '') return 0;
        $t = (string) get_post_meta(intval($event_id), '_tix_time_start', true);
        try { return (new DateTimeImmutable(trim($d . ' ' . ($t ?: '00:00')), wp_timezone()))->getTimestamp(); } catch (Exception $e) { return 0; }
    }

    /** Client kann Vorkasse darstellen? (`supports` = ["bank"] bzw. "bank,…") */
    public static function client_supports(WP_REST_Request $req) {
        $s = $req->get_param('supports');
        $l = is_array($s) ? $s : explode(',', (string) $s);
        return in_array('bank', array_map(function ($x) { return strtolower(trim((string) $x)); }, $l), true);
    }

    /** Überweisung eingerichtet? */
    public static function enabled() { return class_exists('TIX_Gateway_Bank') && TIX_Gateway_Bank::is_available(); }

    /** Frist für einen Warenkorb jetzt → due_for */
    public static function for_cart(array $cart) {
        $starts = [];
        foreach ((array) ($cart['items'] ?? []) as $it) if (!empty($it['event_id']) && ($s = self::event_start($it['event_id']))) $starts[] = $s;
        return self::due_for(self::now(), $starts);
    }

    /** Zahlart-Eintrag für quote | null */
    public static function method_for_cart(array $cart) {
        if (!self::enabled()) return null;
        $d = self::for_cart($cart);
        if (!$d['allowed']) return null;
        return ['id' => 'bank', 'title' => self::TITLE, 'icon' => '', 'due_date' => $d['due_date'], 'due_at' => self::iso($d['due_at']), 'note' => 'Tickets nach Zahlungseingang'];
    }

    // ═══════════════ Pro Bestellung ═══════════════

    public static function get($order_id) {
        $d = class_exists('TIX_Object_Data') ? TIX_Object_Data::read(self::KEY, intval($order_id), null) : null;
        return is_array($d) ? $d : null;
    }

    public static function put($order_id, array $d) { TIX_Object_Data::write(self::KEY, intval($order_id), $d); }

    /** Frist an der neuen Bestellung speichern */
    public static function start($order_id, array $cart) {
        $d = self::for_cart($cart);
        $ts = $d['due_at'] ?: (self::now() + self::DAYS * DAY_IN_SECONDS);
        self::put($order_id, ['due_at' => $ts, 'due_date' => $d['due_date'], 'reminded_at' => null, 'expired_at' => null, 'set_by' => 'checkout']);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) TIX_Order_Admin::add_note($order_id, 'Vorkasse per Überweisung – Zahlungsfrist ' . self::label($d['due_date']) . ', danach automatisches Storno.', 'system');
    }

    /** Status der Vorkasse aus dem Bestellstatus */
    private static function status_of($order_status, $due) {
        if (in_array($order_status, ['completed', 'processing'], true)) return 'paid';
        if ($order_status === 'on-hold') return 'awaiting';
        if (in_array($order_status, ['cancelled', 'failed', 'refunded'], true)) return !empty($due['expired_at']) ? 'expired' : 'cancelled';
        return 'awaiting';
    }

    /** Bankdaten für Käufer {holder, iban, bic, bank_name, amount, currency, reference, due_*, note, status, expired_at} | null */
    public static function payload($o) {
        if (!$o || !in_array((string) ($o->payment_method ?? ''), ['bank', 'bacs'], true)) return null;
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $due = self::get($o->id);
        $iban = strtoupper(preg_replace('/\s+/', '', (string) ($s['bank_iban'] ?? '')));
        return ['holder' => (string) ($s['bank_holder'] ?? ''), 'iban' => trim(chunk_split($iban, 4, ' ')), 'bic' => (string) ($s['bank_bic'] ?? ''), 'bank_name' => (string) ($s['bank_name'] ?? ''),
            'amount' => round(floatval($o->total), 2), 'currency' => 'EUR', 'reference' => (string) ($o->order_number ?: $o->id),
            'due_date' => $due['due_date'] ?? null, 'due_at' => !empty($due['due_at']) ? self::iso($due['due_at']) : null, 'due_label' => !empty($due['due_date']) ? self::label($due['due_date']) : null,
            'note' => trim((string) ($s['bank_reference'] ?? '')) ?: null, 'status' => self::status_of((string) $o->status, $due),
            'expired_at' => !empty($due['expired_at']) ? (string) $due['expired_at'] : null];
    }

    /** Felder für Studio/Veranstalterbereich (/orders) */
    public static function staff_fields($order_id, $status, $method) {
        $due = self::get($order_id);
        $bank = in_array((string) $method, ['bank', 'bacs'], true);
        return ['awaiting_payment' => $bank && $status === 'on-hold', 'bank_due_at' => !empty($due['due_at']) ? self::iso($due['due_at']) : null,
            'bank_due_date' => $due['due_date'] ?? null, 'bank_status' => $bank ? self::status_of((string) $status, $due) : null];
    }

    // ═══════════════ Cron: Erinnerung + Auto-Storno ═══════════════

    public static function cron() {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';
        $ids = $wpdb->get_col("SELECT id FROM {$t} WHERE status = 'on-hold' AND payment_method IN ('bank','bacs') ORDER BY id ASC LIMIT 500");
        $out = ['reminded' => 0, 'expired' => 0];
        foreach (array_map('intval', (array) $ids) as $id) {
            $d = self::get($id);
            if (!$d || empty($d['due_at'])) continue; // ohne Frist (WordPress-Kasse): nichts tun
            $now = self::now();
            if ($now > intval($d['due_at'])) { if (self::expire($id)) $out['expired']++; continue; }
            if (empty($d['reminded_at']) && intval($d['due_at']) - $now <= self::REMIND_DAYS * DAY_IN_SECONDS && self::long_enough($id, $d)) {
                if (self::remind($id)) $out['reminded']++;
            }
        }
        return $out;
    }

    /** Erinnerung nur, wenn die Frist mindestens 3 Tage lang war */
    private static function long_enough($id, array $d) {
        global $wpdb;
        $c = (string) $wpdb->get_var($wpdb->prepare("SELECT date_created FROM {$wpdb->prefix}tix_orders WHERE id = %d", $id));
        try { $created = $c !== '' ? (new DateTimeImmutable($c, wp_timezone()))->getTimestamp() : 0; } catch (Exception $e) { $created = 0; }
        return !$created || intval($d['due_at']) - $created >= 3 * DAY_IN_SECONDS;
    }

    public static function remind($order_id) {
        $d = self::get($order_id);
        if (!$d || !empty($d['reminded_at'])) return false;
        $d['reminded_at'] = gmdate('c');
        self::put($order_id, $d);
        $order = class_exists('TIX_Order') ? TIX_Order::get($order_id) : null;
        if (!$order || !class_exists('TIX_Emails')) return false;
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : get_bloginfo('name');
        $html = TIX_Emails::build_email_html('on_hold', $order, 'Erinnerung: Bitte überweise ' . self::label($d['due_date']),
            [['type' => 'notice', 'tone' => 'info', 'html' => 'Wir haben deine Zahlung noch nicht erhalten. Bitte überweise ' . esc_html(self::label($d['due_date'])) . ' – sonst wird die Bestellung automatisch storniert.']]);
        TIX_Emails::send_html_mail($order->get_billing_email(), $brand . ' – Erinnerung: Bitte überweise ' . self::label($d['due_date']) . ' #' . $order->get_id(), $html, true);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) TIX_Order_Admin::add_note($order_id, 'Zahlungserinnerung gesendet (Frist ' . self::label($d['due_date']) . ').', 'system');
        return true;
    }

    public static function expire($order_id) {
        $d = self::get($order_id);
        if (!$d) return false;
        $d['expired_at'] = gmdate('c');
        self::put($order_id, $d);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) TIX_Order_Admin::add_note($order_id, 'Zahlungsfrist abgelaufen (' . self::label($d['due_date']) . ') – automatisch storniert.', 'system');
        self::$expiring = intval($order_id);
        $ok = TIX_Native_Checkout::update_order_status($order_id, 'cancelled', 'bank_expired');
        self::$expiring = 0;
        if ($ok === false) { $d['expired_at'] = null; self::put($order_id, $d); return false; }
        return true;
    }

    // ═══════════════ REST: Frist ändern ═══════════════

    public static function routes() {
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/bank-due', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_due'],
            'permission_callback' => ['TIX_App_Events', 'perm_money']]); // Rechte wie mark-paid
    }

    public static function rest_due(WP_REST_Request $req) {
        $id = absint($req['id']);
        $o = class_exists('TIX_Order') ? TIX_Order::get($id) : null;
        if (!$o) return new WP_Error('not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && !current_user_can('manage_options')) return new WP_Error('tix_platform_only', 'Vorkasse läuft über das Konto der Plattform.', ['status' => 403]);
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped() && !TIX_App_Scope::order_allowed($o)) return new WP_Error('forbidden', 'Kein Zugriff auf diese Bestellung.', ['status' => 403]);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $id));
        if (!$row || $row->status !== 'on-hold' || !in_array($row->payment_method, ['bank', 'bacs'], true)) return new WP_Error('tix_not_awaiting', 'Die Bestellung wartet nicht auf eine Überweisung.', ['status' => 409]);
        $date = (string) $req->get_param('due_date');
        $tz = new DateTimeZone(self::TZ);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if (!$d || $d->format('Y-m-d') !== $date) return new WP_Error('tix_invalid_field', 'Datum als JJJJ-MM-TT.', ['status' => 400, 'field' => 'due_date']);
        $end = $d->setTime(23, 59, 59)->getTimestamp();
        if ($end < self::now()) return new WP_Error('tix_invalid_field', 'Das Datum liegt in der Vergangenheit.', ['status' => 400, 'field' => 'due_date']);
        $starts = [];
        foreach (class_exists('TIX_App_Scope') ? TIX_App_Scope::order_event_ids($o) : [] as $eid) if ($s = self::event_start($eid)) $starts[] = $s;
        if ($starts && $end >= min($starts)) return new WP_Error('tix_invalid_field', 'Die Frist muss vor dem Event-Beginn enden.', ['status' => 400, 'field' => 'due_date']);
        $cur = self::get($id) ?: [];
        self::put($id, ['due_at' => $end, 'due_date' => $date, 'reminded_at' => null, 'expired_at' => null, 'set_by' => 'user:' . get_current_user_id()]);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) TIX_Order_Admin::add_note($id, 'Zahlungsfrist geändert: ' . (!empty($cur['due_date']) ? self::label($cur['due_date']) . ' → ' : '') . self::label($date) . '.', 'admin');
        $r = rest_ensure_response(['ok' => true, 'bank_due_at' => self::iso($end), 'bank_due_date' => $date]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}
TIX_Bank_Prepay::init();
