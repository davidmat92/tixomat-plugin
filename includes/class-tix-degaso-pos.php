<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Degaso_Pos – Abendkasse über die TSE der Degaso-Kasse (1.38.557, Paket 4).
 *
 * Ist der Schalter an, wird jeder Bar- und Kartenverkauf der App-Kasse (/pos/orders, payment cash|card) VOR dem Anlegen der
 * Tixomat-Bestellung als Bon an die Degaso-Kasse geschickt und dort bezahlt (= mit TSE signiert):
 *   Router https://router.degaso.de:8080/route/<apiKey>/<Kasse>/orderArticles {articles, employee, table, dontPrint}
 *   → /payOrder {orderId, payedArticels, total, payment BAR|EC, freeText}
 * Ist die Kasse nicht erreichbar oder lehnt ab: KEIN Verkauf (503 tix_pos_tse_unavailable), nie still ohne TSE.
 * Kostenlose Verkäufe (0 €) brauchen keinen Bon. Schlägt danach das Anlegen der Tixomat-Bestellung fehl → Storno-Bon.
 * Kassen-Storno (/pos/orders/{id}/void) → Storno-Bon (payOrder isStorno). Antwortformate der Kasse sind bei Degaso nicht
 * beschrieben: Bestellnummer aus orderId|_id|order._id|data._id; payOrder 200 = bezahlt, 201 = war schon bezahlt.
 *
 * Artikel: Ticketart (Name, klein) → Degaso-Artikel {id, name}; sonst der Standard-Artikel. Der Preis kommt aus Tixomat
 * (Rabatt anteilig auf die Positionen), der Steuersatz aus dem Degaso-Artikel – er muss zum Ticket passen.
 *
 * Einstellungen (Option tix_degaso, Studio): tse_enabled (Standard aus), tse_cash (Kassenname), tse_default {id, name},
 * tse_map {ticketart_klein: {id, name}}, tse_employee (Bediener auf dem Bon, Standard „Abendkasse“).
 *
 * REST (Studio, Rechte wie /degaso/admin): GET /degaso/admin/pos → {cashes, products (live von der Kasse), ticket_names}
 *
 * Offene Steuerfragen (Plan docs/plan-degaso.md): Gutschein als Zahlart am Bon, Steuersatz Eintritt, ob Kartenzahlung über
 * den Bon gehen muss. Diese Klasse signiert bar und Karte; mit Gutschein bezahlte Anteile stehen nur im Freitext.
 */
class TIX_Degaso_Pos {

    const NS = 'tixomat/v1';

    public static function init() {
        add_filter('tix_degaso_defaults', function ($d) {
            return $d + ['tse_enabled' => false, 'tse_cash' => '', 'tse_default' => [], 'tse_map' => [], 'tse_employee' => 'Abendkasse'];
        });
        add_filter('tix_degaso_settings_out', [__CLASS__, 'settings_out'], 10, 2);
        add_filter('tix_degaso_settings_in', [__CLASS__, 'settings_in'], 10, 2);
        add_filter('tix_pos_sign_sale', [__CLASS__, 'sign_sale'], 10, 2);
        add_action('tix_pos_sign_cancel', [__CLASS__, 'cancel_receipt'], 10, 2);
        add_action('tix_pos_signed', [__CLASS__, 'on_signed'], 10, 2);
        add_action('tix_pos_voided', [__CLASS__, 'on_voided'], 10, 1);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function active() {
        $s = TIX_Degaso::settings();
        return TIX_Degaso::connected() && !empty($s['tse_enabled']) && $s['tse_cash'] !== '' && !empty($s['tse_default']['id']);
    }

    public static function settings_out($out, $s) {
        return $out + ['tse_enabled' => !empty($s['tse_enabled']), 'tse_cash' => (string) $s['tse_cash'], 'tse_default' => self::article($s['tse_default']),
            'tse_map' => (object) array_map([__CLASS__, 'article'], (array) $s['tse_map']), 'tse_employee' => (string) $s['tse_employee']];
    }

    private static function article($a) {
        return is_array($a) && !empty($a['id']) ? ['id' => mb_substr((string) $a['id'], 0, 64), 'name' => mb_substr((string) ($a['name'] ?? ''), 0, 80)] : null;
    }

    public static function settings_in($s, $p) {
        if (is_wp_error($s)) return $s;
        if (array_key_exists('tse_cash', $p)) $s['tse_cash'] = mb_substr(sanitize_text_field((string) $p['tse_cash']), 0, 80);
        if (array_key_exists('tse_default', $p)) $s['tse_default'] = self::article($p['tse_default']) ?? [];
        if (array_key_exists('tse_employee', $p)) $s['tse_employee'] = mb_substr(sanitize_text_field((string) $p['tse_employee']), 0, 40) ?: 'Abendkasse';
        if (array_key_exists('tse_map', $p) && is_array($p['tse_map'])) {
            $map = [];
            foreach ($p['tse_map'] as $k => $a) { $a = self::article($a); $k = mb_strtolower(trim(sanitize_text_field((string) $k))); if ($a && $k !== '') $map[mb_substr($k, 0, 80)] = $a; }
            $s['tse_map'] = array_slice($map, 0, 200, true);
        }
        if (array_key_exists('tse_enabled', $p)) {
            $on = filter_var($p['tse_enabled'], FILTER_VALIDATE_BOOLEAN);
            if ($on && ($s['tse_cash'] === '' || empty($s['tse_default']['id']))) {
                return new WP_Error('tix_invalid_field', 'Für die Abendkasse über Degaso bitte zuerst die Kasse und einen Standard-Artikel wählen.', ['status' => 400, 'field' => 'tse_enabled']);
            }
            $s['tse_enabled'] = $on;
        }
        return $s;
    }

    // ═══════════════ Verkauf signieren ═══════════════

    /** Positionen für den Bon: Rabatt anteilig, Summe = total (Rundung auf der letzten Position) */
    public static function lines(array $items, $total) {
        $s = TIX_Degaso::settings();
        $sub = 0;
        foreach ($items as $it) $sub += floatval($it['price']) * intval($it['qty']);
        $f = $sub > 0 ? $total / $sub : 0;
        $lines = []; $sum = 0;
        foreach ($items as $it) {
            $a = $s['tse_map'][mb_strtolower(trim((string) $it['name']))] ?? $s['tse_default'];
            $unit = round(floatval($it['price']) * $f, 2);
            $lines[] = ['_id' => (string) $a['id'], 'name' => (string) ($a['name'] ?: $it['name']) . ' – ' . $it['name'], 'price' => number_format($unit, 2, '.', ''), 'quantity' => intval($it['qty'])];
            $sum += $unit * intval($it['qty']);
        }
        $diff = round($total - $sum, 2);
        if ($lines && abs($diff) >= 0.01) { // Rundung: letzte Position um den Rest korrigieren (eigene Zeile, Menge 1)
            $last = array_pop($lines);
            if ($last['quantity'] > 1) { $last['quantity']--; $lines[] = $last; $last['quantity'] = 1; }
            $last['price'] = number_format(floatval($last['price']) + $diff, 2, '.', '');
            $lines[] = $last;
        }
        return $lines;
    }

    /**
     * Filter tix_pos_sign_sale (TIX_REST_API::pos_create_order, vor dem Anlegen):
     * null = keine TSE nötig | WP_Error = nicht verkaufen | array Beleg {provider, cash, order_id, total, payment, lines}.
     */
    public static function sign_sale($pre, array $sale) {
        if ($pre !== null || !self::active()) return $pre;
        if (!in_array($sale['payment'] ?? '', ['cash', 'card'], true) || floatval($sale['total'] ?? 0) <= 0) return null;
        $s = TIX_Degaso::settings();
        $total = round(floatval($sale['total']), 2);
        $lines = self::lines((array) $sale['items'], $total);
        $pay = $sale['payment'] === 'card' ? 'EC' : 'BAR';
        $o = TIX_Degaso::register_call('POST', '/orderArticles', $s['tse_cash'], ['table' => 'Abendkasse', 'employee' => (string) $s['tse_employee'], 'articles' => $lines, 'dontPrint' => true]);
        $oid = $o['ok'] ? self::order_id($o['data']) : '';
        if ($oid === '') return self::unavailable($o, 'Bestellung');
        $free = 'Tixomat ' . ($sale['event_title'] ?? '') . (floatval($sale['discount'] ?? 0) > 0 ? ' · Rabatt/Gutschein ' . number_format(floatval($sale['discount']), 2, ',', '.') . ' €' : '');
        $p = TIX_Degaso::register_call('POST', '/payOrder', $s['tse_cash'], ['orderId' => $oid, 'payedArticels' => $lines, 'total' => number_format($total, 2, '.', ''), 'payment' => $pay, 'freeText' => mb_substr(trim($free), 0, 120)]);
        if (!$p['ok'] && $p['status'] === 0) { // Antwort verloren: payOrder ist wiederholbar (201 = schon bezahlt)
            $p = TIX_Degaso::register_call('POST', '/payOrder', $s['tse_cash'], ['orderId' => $oid, 'payedArticels' => $lines, 'total' => number_format($total, 2, '.', ''), 'payment' => $pay]);
        }
        if (!$p['ok']) {
            TIX_Degaso::register_call('POST', '/stornoItem', $s['tse_cash'], ['orderId' => $oid, 'employee' => (string) $s['tse_employee'], 'reason' => 'Bezahlen fehlgeschlagen']); // offene Bestellung abräumen
            return self::unavailable($p, 'Bezahlung');
        }
        return ['provider' => 'degaso', 'cash' => $s['tse_cash'], 'order_id' => $oid, 'total' => $total, 'payment' => $pay, 'lines' => $lines,
            'signed_at' => current_time('mysql'), 'receipt' => self::receipt_info($p['data'])];
    }

    private static function order_id($d) {
        foreach ([$d['orderId'] ?? null, $d['_id'] ?? null, $d['order']['_id'] ?? null, $d['data']['_id'] ?? null, $d['data']['orderId'] ?? null] as $v) {
            if (is_scalar($v) && (string) $v !== '') return mb_substr((string) $v, 0, 64);
        }
        return '';
    }

    /** Nur Bon-Angaben (Nummer, TSE) aus der Antwort – nie den Rest durchreichen */
    private static function receipt_info($d) {
        $out = [];
        array_walk_recursive($d, function ($v, $k) use (&$out) {
            if (count($out) < 8 && is_scalar($v) && preg_match('/^(receipt|bon|checkout|transaction|tse|signature)/i', (string) $k) && !preg_match('/key/i', (string) $k)) $out[(string) $k] = mb_substr((string) $v, 0, 120);
        });
        return $out;
    }

    private static function unavailable($r, $step) {
        $why = $r['reason'] === 'network' || $r['reason'] === 'register_offline' ? 'ist gerade nicht erreichbar' : 'hat den Bon abgelehnt (' . $step . ')';
        TIX_Degaso::log('error', 'Abendkasse', 'Degaso-Kasse ' . $why . ' – Verkauf gestoppt.');
        return new WP_Error('tix_pos_tse_unavailable', 'Die Degaso-Kasse ' . $why . '. Ohne Kassenbon mit TSE wird nichts verkauft – bitte an der Degaso-Kasse kassieren oder es gleich nochmal versuchen.', ['status' => 503]);
    }

    /** Aktion tix_pos_sign_cancel: Bon signiert, aber die Tixomat-Bestellung kam nicht zustande → Storno-Bon */
    public static function cancel_receipt($receipt, $why = '') {
        if (!is_array($receipt) || ($receipt['provider'] ?? '') !== 'degaso') return;
        $r = self::storno($receipt);
        TIX_Degaso::log($r ? 'info' : 'conflict', 'Abendkasse', $r ? 'Storno-Bon für ' . $receipt['order_id'] . ' (' . $why . ').' : 'Storno-Bon für ' . $receipt['order_id'] . ' fehlgeschlagen – bitte an der Degaso-Kasse stornieren.');
    }

    private static function storno(array $rc) {
        $s = TIX_Degaso::settings();
        $neg = array_map(function ($l) { $l['price'] = number_format(-floatval($l['price']), 2, '.', ''); return $l; }, (array) $rc['lines']);
        $r = TIX_Degaso::register_call('POST', '/payOrder', (string) ($rc['cash'] ?: $s['tse_cash']), ['orderId' => $rc['order_id'], 'payedArticels' => $neg,
            'total' => number_format(-floatval($rc['total']), 2, '.', ''), 'payment' => $rc['payment'], 'isStorno' => true, 'freeText' => 'Storno Tixomat']);
        return $r['ok'];
    }

    /** Aktion tix_pos_signed: Beleg an der Bestellung merken */
    public static function on_signed($order_id, $receipt) {
        if (!is_array($receipt) || ($receipt['provider'] ?? '') !== 'degaso') return;
        update_option('_tix_pos_receipt_' . intval($order_id), $receipt, false);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note($order_id, '🧾 Kassenbon mit TSE über die Degaso-Kasse „' . $receipt['cash'] . '“ (Bestellung ' . $receipt['order_id'] . ')', 'pos');
        }
    }

    /** Aktion tix_pos_voided: Kassen-Storno → Storno-Bon */
    public static function on_voided($order_id) {
        $rc = get_option('_tix_pos_receipt_' . intval($order_id));
        if (!is_array($rc) || !empty($rc['voided'])) return;
        $ok = self::storno($rc);
        $rc['voided'] = $ok ? current_time('mysql') : '';
        update_option('_tix_pos_receipt_' . intval($order_id), $rc, false);
        if (!$ok) TIX_Degaso::log('conflict', 'Abendkasse', 'Storno-Bon für Bestellung ' . intval($order_id) . ' fehlgeschlagen – bitte an der Degaso-Kasse stornieren.');
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note($order_id, $ok ? '🧾 Storno-Bon an der Degaso-Kasse erstellt' : '⚠️ Storno-Bon an der Degaso-Kasse fehlgeschlagen – bitte dort stornieren', 'pos');
        }
    }

    // ═══════════════ Studio ═══════════════

    public static function routes() {
        register_rest_route(self::NS, '/degaso/admin/pos', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin'], 'permission_callback' => ['TIX_Degaso', 'check_admin']]);
    }

    /** Kassen (Cloud), Artikel der gewählten Kasse (live über den Router) und die Ticketarten der Events (±60 Tage) */
    public static function rest_admin(WP_REST_Request $req) {
        if (!TIX_Degaso::connected()) return new WP_Error('tix_degaso_nokey', 'Es ist noch kein Degaso-Schlüssel eingetragen.', ['status' => 409]);
        $s = TIX_Degaso::settings();
        $cash = sanitize_text_field((string) ($req->get_param('cash') ?? '')) ?: (string) $s['tse_cash'];
        $products = null; $msg = '';
        if ($cash !== '') {
            $r = TIX_Degaso::register_call('GET', '/getAllProducts', $cash);
            if ($r['ok']) {
                $products = [];
                foreach ((array) ($r['data']['data'] ?? $r['data']) as $p) {
                    if (!is_array($p) || empty($p['_id'] ?? $p['id'] ?? '')) continue;
                    $products[] = ['id' => (string) ($p['_id'] ?? $p['id']), 'name' => (string) ($p['name'] ?? ''), 'price' => round(floatval(str_replace(',', '.', (string) ($p['price'] ?? 0))), 2),
                        'tax' => (string) ($p['tax'] ?? ''), 'category' => (string) ($p['category'] ?? '')];
                }
            } else {
                $msg = 'Die Kasse „' . $cash . '“ ist gerade nicht erreichbar – sie muss an und online sein, damit wir ihre Artikel sehen.';
            }
        }
        $out = new WP_REST_Response(['ok' => true, 'cashes' => TIX_Degaso::register_names() ?? [], 'cash' => $cash, 'products' => $products, 'message' => $msg, 'ticket_names' => self::ticket_names()]);
        $out->header('Cache-Control', 'no-store');
        return $out;
    }

    /** Namen der Ticketarten aus Events der letzten 60 und nächsten 60 Tage (ohne Gutschein-/Guthaben-Events) */
    public static function ticket_names() {
        if (!function_exists('get_posts')) return [];
        $ids = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'future'], 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_date_start', 'value' => [date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+60 days'))], 'compare' => 'BETWEEN', 'type' => 'DATE']]]);
        $names = [];
        foreach ($ids as $id) {
            if (get_post_meta($id, '_tix_system_giftcard_event', true) === '1') continue;
            foreach ((array) get_post_meta($id, '_tix_ticket_categories', true) as $c) {
                if (!is_array($c) || !empty($c['gift_card'])) continue;
                $n = trim((string) ($c['name'] ?? ''));
                if ($n !== '') $names[mb_strtolower($n)] = $n;
            }
        }
        ksort($names);
        return array_values($names);
    }
}

TIX_Degaso_Pos::init();
