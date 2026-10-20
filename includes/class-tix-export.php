<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Export – CSV-Exporte für Veranstalter in App und Web (Leitlinie „Kein Einsperren“).
 *
 *   GET /organizer/export?type=guests|tickets|combined|orders|customers[&event_id][&from][&to][&status=paid|all]
 *     → {ok, filename, mime: "text/csv", rows, data (Base64)} – wie /organizer/settlements/{id}/pdf
 *     Rechte wie die übrigen App-Veranstalter-Routen (check_organizer); Events über can_access_event,
 *     im Mehr-Veranstalter-Modus zusätzlich Team-Rechte (view_guestlist bzw. view_orders).
 * CSV: UTF-8 mit BOM, Semikolon (Excel deutsch). Bestellungen und Kunden: nur eigene Positionen.
 */
class TIX_Export {

    const NS = 'tixomat/v1';
    const EVENT_HEADER = ['Typ', 'Ticket-Code', 'Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Adresse', 'Kategorie', 'Anzahl', 'Status', 'Kaufdatum', 'Notizen', 'Quelle'];
    const ORDER_HEADER = ['Bestellnr', 'Datum', 'Status', 'Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Adresse', 'PLZ', 'Ort', 'Event', 'Positionen', 'Tickets', 'Betrag (eigene Positionen)', 'Gesamtbetrag Bestellung', 'Zahlart'];
    const CUSTOMER_HEADER = ['Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Bestellungen', 'Tickets', 'Umsatz', 'Erste Bestellung', 'Letzte Bestellung'];
    const STATUS_PAID = ['completed', 'processing'];
    const STATUS_ALL  = ['completed', 'processing', 'on-hold', 'pending', 'refunded', 'cancelled'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/organizer/export', [
            // Einlass (Eingang) darf seit 1.38.454 den Gästeliste-Export eines Events; alles andere nur Inhaber und Team-Admins
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_export'], 'permission_callback' => ['TIX_REST_API', 'check_entrance'],
        ]);
    }

    /** CSV-Text (BOM, Semikolon). */
    public static function csv(array $header, array $rows) {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ';');
        foreach ($rows as $r) fputcsv($fh, array_values($r), ';');
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return (string) $out;
    }

    private static function money($v) {
        return number_format(floatval($v), 2, ',', '.');
    }

    public static function status_list($status) {
        return $status === 'all' ? self::STATUS_ALL : self::STATUS_PAID;
    }

    private static function status_label($st) {
        $l = ['completed' => 'Bezahlt', 'processing' => 'In Bearbeitung', 'on-hold' => 'Wartend', 'pending' => 'Offen', 'refunded' => 'Erstattet', 'cancelled' => 'Storniert'];
        return $l[$st] ?? $st;
    }

    private static function date_or_empty($d) {
        $d = trim((string) $d);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }

    /** Bestellungen mit Käuferdaten (nur Positionen der übergebenen Events). */
    public static function order_rows(array $event_ids, $from = '', $to = '', $status = 'paid') {
        list(, $result) = TIX_Organizer_Admin::event_orders($event_ids, [
            'status' => self::status_list($status), 'date_from' => self::date_or_empty($from), 'date_to' => self::date_or_empty($to),
        ]);
        $rows = [];
        foreach ($result as $r) {
            $o = $r['order'];
            $own = 0.0; $qty = 0; $names = []; $events = [];
            foreach ($r['items'] as $it) {
                $own += floatval($it->total);
                $qty += intval($it->quantity);
                $names[] = trim((string) $it->name) . ' × ' . intval($it->quantity);
                $title = get_the_title(intval($it->event_id));
                if ($title !== '' && !in_array($title, $events, true)) $events[] = $title;
            }
            $rows[] = [
                (string) $o->order_number,
                $o->date_created ? mysql2date('d.m.Y H:i', $o->date_created) : '',
                self::status_label((string) $o->status),
                (string) $o->billing_first_name,
                (string) $o->billing_last_name,
                (string) $o->billing_email,
                (string) ($o->billing_phone ?? ''),
                trim((string) ($o->billing_address_1 ?? '') . ' ' . (string) ($o->billing_address_2 ?? '')),
                (string) ($o->billing_postcode ?? ''),
                (string) ($o->billing_city ?? ''),
                implode(', ', $events),
                implode(', ', $names),
                $qty,
                self::money($own),
                self::money($o->total),
                (string) ($o->payment_method_title ?? $o->payment_method ?? ''),
            ];
        }
        return $rows;
    }

    /** Kunden (Käufer) über die Events: Bestellungen, Tickets, Umsatz der eigenen Positionen – nur bezahlt. */
    public static function customer_rows(array $event_ids, $from = '', $to = '') {
        list(, $result) = TIX_Organizer_Admin::event_orders($event_ids, [
            'status' => self::STATUS_PAID, 'date_from' => self::date_or_empty($from), 'date_to' => self::date_or_empty($to),
        ]);
        $by = [];
        foreach ($result as $r) {
            $o = $r['order'];
            $mail = strtolower(trim((string) $o->billing_email));
            if ($mail === '') continue;
            $own = 0.0; $qty = 0;
            foreach ($r['items'] as $it) { $own += floatval($it->total); $qty += intval($it->quantity); }
            if (!isset($by[$mail])) {
                $by[$mail] = ['first' => (string) $o->billing_first_name, 'last' => (string) $o->billing_last_name, 'phone' => (string) ($o->billing_phone ?? ''),
                    'orders' => 0, 'tickets' => 0, 'revenue' => 0.0, 'min' => (string) $o->date_created, 'max' => (string) $o->date_created];
            }
            $c = &$by[$mail];
            $c['orders']++; $c['tickets'] += $qty; $c['revenue'] += $own;
            if ((string) $o->date_created < $c['min']) $c['min'] = (string) $o->date_created;
            if ((string) $o->date_created > $c['max']) { $c['max'] = (string) $o->date_created; $c['first'] = (string) $o->billing_first_name ?: $c['first']; $c['last'] = (string) $o->billing_last_name ?: $c['last']; }
            if ($c['phone'] === '' && !empty($o->billing_phone)) $c['phone'] = (string) $o->billing_phone;
            unset($c);
        }
        uasort($by, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        $rows = [];
        foreach ($by as $mail => $c) {
            $rows[] = [$c['first'], $c['last'], $mail, $c['phone'], $c['orders'], $c['tickets'], self::money($c['revenue']),
                $c['min'] ? mysql2date('d.m.Y', $c['min']) : '', $c['max'] ? mysql2date('d.m.Y', $c['max']) : ''];
        }
        return $rows;
    }

    /** Events, auf die der aktuelle Nutzer in der App zugreifen darf. */
    public static function accessible_event_ids() {
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::scoped()) return TIX_App_Scope::own_event_ids();
        $all = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => -1, 'fields' => 'ids']);
        $ids = array_values(array_filter(array_map('intval', $all), ['TIX_REST_API', 'can_access_event']));
        // Plattform-Sicht: Demo Club / Vorschau nicht mitzählen (1.38.508)
        if (class_exists('TIX_Sales') && TIX_Sales::multi()) $ids = array_values(array_filter($ids, function ($id) { return !TIX_Sales::is_sandbox_event($id); }));
        return $ids;
    }

    private static function team_can($perm) {
        if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::scoped()) || !class_exists('TIX_Team')) return true;
        return TIX_Team::user_can(get_current_user_id(), $perm);
    }

    public static function rest_export(WP_REST_Request $req) {
        $type  = sanitize_key((string) $req->get_param('type'));
        $event = absint($req->get_param('event_id'));
        $from  = self::date_or_empty($req->get_param('from'));
        $to    = self::date_or_empty($req->get_param('to'));
        $status = (string) $req->get_param('status') === 'all' ? 'all' : 'paid';
        $types = ['guests' => 'guestlist', 'tickets' => 'tickets', 'combined' => 'combined', 'orders' => null, 'customers' => null, 'checkins' => null, 'coupons' => null];
        if (!array_key_exists($type, $types)) {
            return new WP_Error('tix_invalid_field', 'Bitte einen gültigen Export wählen.', ['status' => 400, 'field' => 'type']);
        }
        $manager = !class_exists('TIX_Studio_Stats') || TIX_Studio_Stats::is_manager();
        if (!$manager && $type !== 'guests') return new WP_Error('rest_forbidden', 'Dieser Export ist nur für Inhaber und Team-Admins. Der Einlass kann die Gästeliste eines Events exportieren.', ['status' => 403]);
        if ($event && !TIX_REST_API::can_access_event($event)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $slug = $event ? sanitize_title(get_the_title($event)) : sanitize_title(get_bloginfo('name'));
        if ($type === 'checkins') {
            if (!$event) return new WP_Error('tix_invalid_field', 'Bitte ein Event wählen.', ['status' => 400, 'field' => 'event_id']);
            $rows = TIX_Studio_Stats::checkin_rows($event);
            $csv  = self::csv(TIX_Studio_Stats::CHECKIN_HEADER, $rows);
            $name = 'einlass-' . $slug;
        } elseif ($type === 'coupons') {
            if (!self::team_can('view_orders')) return new WP_Error('forbidden', 'Keine Berechtigung für Bestellungen.', ['status' => 403]);
            $ids = $event ? [$event] : self::accessible_event_ids();
            $rows = TIX_Studio_Stats::coupon_rows($ids, $from, $to, $status);
            $csv  = self::csv(TIX_Studio_Stats::COUPON_HEADER, $rows);
            $name = 'gutscheine-' . $slug;
        } elseif ($types[$type] !== null) {
            if (!$event) return new WP_Error('tix_invalid_field', 'Bitte ein Event wählen.', ['status' => 400, 'field' => 'event_id']);
            if (!self::team_can('view_guestlist')) return new WP_Error('forbidden', 'Keine Berechtigung für Gästelisten.', ['status' => 403]);
            $rows = TIX_Organizer_Admin::collect_export_rows($event, $types[$type]);
            foreach ($rows as &$r) unset($r['date_sort']);
            unset($r);
            $name = ['guests' => 'gaesteliste', 'tickets' => 'tickets', 'combined' => 'gesamt'][$type] . '-' . $slug;
            $csv  = self::csv(self::EVENT_HEADER, $rows);
        } else {
            if (!self::team_can('view_orders')) return new WP_Error('forbidden', 'Keine Berechtigung für Bestellungen.', ['status' => 403]);
            $ids = $event ? [$event] : self::accessible_event_ids();
            if ($type === 'orders') {
                $rows = $ids ? self::order_rows($ids, $from, $to, $status) : [];
                $csv  = self::csv(self::ORDER_HEADER, $rows);
                $name = 'bestellungen-' . $slug;
            } else {
                $rows = $ids ? self::customer_rows($ids, $from, $to) : [];
                $csv  = self::csv(self::CUSTOMER_HEADER, $rows);
                $name = 'kunden-' . $slug;
            }
        }
        return rest_ensure_response([
            'ok'       => true,
            'filename' => $name . '-' . current_time('Y-m-d') . '.csv',
            'mime'     => 'text/csv',
            'rows'     => count($rows),
            'data'     => base64_encode($csv),
        ]);
    }
}
