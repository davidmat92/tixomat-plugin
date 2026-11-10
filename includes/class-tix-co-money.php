<?php
if (!defined('ABSPATH')) exit;

/**
 * Co-Veranstalter: wer nimmt das Geld ein + Anteil (1.38.711, Vertrag `uebergaben/vertrag-co-geldempfaenger.md`).
 *
 * Paket 1: Datenmodell je Event (`_tix_co_money` creator|co, `_tix_co_share` 0–100), Sperre nach dem ersten bezahlten
 * Verkauf, Abrechnung auf evendis.de an den Geldempfänger mit Anteil (Position `co_share`), Ausgleichs-Abrechnung
 * `GET /events/{id}/co-balance` (+ PDF) an der Verkaufsinstanz.
 */
class TIX_Co_Money {
    const NS          = 'tixomat/v1';
    const META_OWNER  = '_tix_co_money';
    const META_SHARE  = '_tix_co_share';
    const META_BY     = '_tix_co_money_by';
    const OWNERS      = ['creator', 'co'];
    const FINAL_DAYS  = 14;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function dec($s) {
        return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8');
    }

    // ═══════════════ Grundlagen ═══════════════

    public static function owner($eid) {
        return get_post_meta(intval($eid), self::META_OWNER, true) === 'co' ? 'co' : 'creator';
    }

    public static function share($eid) {
        $v = get_post_meta(intval($eid), self::META_SHARE, true);
        return $v === '' ? 0.0 : max(0.0, min(100.0, round(floatval($v) * 2) / 2));
    }

    /** Art des Co am Event: local (Teil A) | mirror (Teil-B-Spiegel auf der Plattform) | remote (Lizenzseite) | platform_co (Anzeige-Spiegel einer Kunden-Seite) | null */
    public static function co_kind($eid) {
        $eid = intval($eid);
        if (self::multi()) {
            if (get_post_meta($eid, '_tix_syndicated', true) === '1') return intval(get_post_meta($eid, '_tix_co_remote_oid', true)) ? 'mirror' : null;
            return class_exists('TIX_Co_Organizer') && TIX_Co_Organizer::ref_id($eid) ? 'local' : null;
        }
        if (class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($eid)) return 'platform_co';
        return class_exists('TIX_Co_Remote') && TIX_Co_Remote::payload($eid) ? 'remote' : null;
    }

    /** Namen: [creator, co] */
    private static function names($eid) {
        $eid = intval($eid);
        $site = self::dec(get_bloginfo('name'));
        $creator = self::dec(get_the_title(intval(get_post_meta($eid, '_tix_organizer_id', true)))) ?: $site;
        $co = '';
        switch (self::co_kind($eid)) {
            case 'local':  $co = self::dec(get_the_title(TIX_Co_Organizer::ref_id($eid))); break;
            case 'mirror': $co = self::dec(get_the_title(intval(get_post_meta($eid, '_tix_co_remote_oid', true)))); break;
            case 'remote': $co = (string) (TIX_Co_Remote::payload($eid)['name'] ?? ''); break;
            case 'platform_co': $co = $site; $creator = (string) get_post_meta($eid, '_tix_platform_co_by', true) ?: $creator; break;
        }
        if (get_post_meta($eid, '_tix_syndicated', true) === '1') $creator = (string) (get_post_meta($eid, '_tix_source_site', true) ?: $creator);
        return [$creator, $co];
    }

    private static function host() {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(class_exists('TIX_Customer_URL') && TIX_Customer_URL::host() !== '' ? 'https://' . TIX_Customer_URL::host() : home_url(), PHP_URL_HOST)));
    }

    /** Gibt es eine bezahlte Bestellung (auch vermittelt, auch Kasse)? */
    public static function sold($eid) {
        global $wpdb;
        $eid = intval($eid);
        $o = $wpdb->prefix . 'tix_orders'; $oi = $wpdb->prefix . 'tix_order_items';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $oi)) === $oi) {
            $n = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o} o JOIN {$oi} i ON i.order_id = o.id WHERE i.event_id = %d AND o.status IN ('completed','processing','refunded') AND o.total > 0", $eid)));
            if ($n) return true;
        }
        $pt = $wpdb->prefix . 'tix_partner_orders';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pt)) === $pt) {
            if (intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pt} WHERE event_id = %d AND paid_at IS NOT NULL", $eid)))) return true;
        }
        return (bool) apply_filters('tix_co_money_sold', false, $eid);
    }

    /** Welche Geldempfänger gehen hier (Paket 1: Co nur bei Teil A mit Zugriff)? */
    public static function choosable($eid) {
        $k = self::co_kind($eid);
        $out = ['creator'];
        if ($k === 'local' && TIX_Co_Organizer::has_access($eid)) $out[] = 'co';
        return (array) apply_filters('tix_co_money_choosable', $out, intval($eid), $k);
    }

    /** Wählbar beim Einladen auf einer Lizenzseite (vor dem Speichern des Co). */
    public static function choosable_for_new($eid, $access) {
        return (array) apply_filters('tix_co_money_choosable', ['creator'], intval($eid), 'remote_new', (bool) $access);
    }

    private static function can_edit($eid) {
        if (self::multi()) return class_exists('TIX_Co_Organizer') && in_array(TIX_Co_Organizer::role($eid), ['owner', 'admin'], true);
        $u = wp_get_current_user();
        return $u && $u->ID && ($u->has_cap('manage_options') || in_array('tix_organizer', (array) $u->roles, true));
    }

    /** Editor/Plattform-Zeile: co_money oder null (kein Co). */
    public static function payload($eid) {
        $eid = intval($eid);
        $k = self::co_kind($eid);
        if (!$k) return null;
        [$creator, $co] = self::names($eid);
        $owner = self::owner($eid);
        $share = self::share($eid);
        $choosable = self::choosable($eid);
        $sold = self::sold($eid);
        $readonly = in_array($k, ['mirror', 'platform_co'], true); // Werte kommen vom Ersteller
        $seller_name = $owner === 'co' ? $co : $creator;
        $seller_host = $owner === 'creator' && in_array($k, ['local', 'remote'], true) ? self::host() : null;
        $note = 'Verkauf, Zahlung, Tickets und Erstattungen laufen über ' . ($seller_host ?: $seller_name) . '.';
        if ($share > 0) $note .= ' ' . self::pct($share) . ' % des Nettos gehen an ' . ($owner === 'co' ? $creator : $co) . (self::settles_here($eid) ? ' (mit der Abrechnung).' : ' (Ausgleich zwischen euch).');
        return ['owner' => $owner, 'share' => $share, 'share_to' => $owner === 'co' ? 'creator' : 'co', 'choosable' => $readonly ? [$owner] : $choosable,
            'locked' => $sold || $readonly, 'locked_reason' => $sold ? 'sold' : ($readonly ? 'not_supported' : (count($choosable) < 2 ? 'not_supported' : null)),
            'editable' => !$sold && !$readonly && self::can_edit($eid),
            'seller' => ['name' => $seller_name, 'host' => $seller_host, 'side' => $owner], 'note' => $note];
    }

    private static function pct($v) {
        return rtrim(rtrim(number_format(floatval($v), 1, ',', ''), '0'), ',');
    }

    /** Wird das Event auf dieser Seite über die Plattform-Abrechnung abgerechnet? */
    private static function settles_here($eid) {
        return self::multi() && class_exists('TIX_Settlement') && get_post_meta(intval($eid), '_tix_syndicated', true) !== '1';
    }

    // ═══════════════ Editor ═══════════════

    /**
     * Feld `co_money` {owner?, share?} bzw. Angaben beim Verbinden prüfen → null | ['owner' =>, 'share' =>] | WP_Error.
     * $in = ['owner' => ?, 'share' => ?]; $co_after = gibt es nach dem Speichern einen Co (beim Verbinden true).
     */
    public static function check_values($eid, array $in, $co_after = null, array $choosable = null) {
        $eid = intval($eid);
        if (!array_key_exists('owner', $in) && !array_key_exists('share', $in)) return null;
        $owner = array_key_exists('owner', $in) && $in['owner'] !== null ? sanitize_key((string) $in['owner']) : self::owner($eid);
        if (!in_array($owner, self::OWNERS, true)) return new WP_Error('tix_invalid_field', 'Geldempfänger: creator oder co.', ['status' => 400, 'field' => 'co_money.owner']);
        $share = self::share($eid);
        if (array_key_exists('share', $in) && $in['share'] !== null && $in['share'] !== '') {
            if (!is_numeric($in['share']) || floatval($in['share']) < 0 || floatval($in['share']) > 100) return new WP_Error('tix_invalid_field', 'Anteil: 0 bis 100 Prozent.', ['status' => 400, 'field' => 'co_money.share']);
            $share = round(floatval($in['share']) * 2) / 2;
        }
        $changed = $owner !== self::owner($eid) || abs($share - self::share($eid)) > 0.001;
        if (!$changed) return null;
        if ($eid && self::sold($eid)) return new WP_Error('tix_co_money_locked', 'Nach dem ersten bezahlten Verkauf lassen sich Geldempfänger und Anteil nicht mehr ändern.', ['status' => 409]);
        $ch = $choosable ?? ($eid ? self::choosable($eid) : ['creator']);
        if (!in_array($owner, $ch, true)) return new WP_Error('tix_co_money_unsupported', 'Der Co-Veranstalter kann bei diesem Event (noch) nicht das Geld einnehmen.', ['status' => 409, 'field' => 'co_money.owner']);
        if ($co_after === false && ($owner === 'co' || $share > 0)) return new WP_Error('tix_invalid_field', 'Ohne Co-Veranstalter gibt es keinen Anteil.', ['status' => 400, 'field' => 'co_money']);
        return ['owner' => $owner, 'share' => $share];
    }

    /** POST /events/{id} mit `co_money` (Ersteller/Admin). */
    public static function check(array $body, $post_id, $is_new) {
        if (!array_key_exists('co_money', $body)) return null;
        if ($is_new) return new WP_Error('tix_invalid_field', 'Geldempfänger erst nach dem Verbinden des Co-Veranstalters wählen.', ['status' => 400, 'field' => 'co_money']);
        if (!self::can_edit($post_id)) return new WP_Error('tix_co_forbidden', 'Den Geldempfänger kann nur der Ersteller des Events ändern.', ['status' => 403]);
        $k = self::co_kind($post_id);
        if (in_array($k, ['mirror', 'platform_co'], true)) return new WP_Error('tix_co_forbidden', 'Den Geldempfänger legt die Seite des Erstellers fest.', ['status' => 403]);
        $v = $body['co_money'];
        if (!is_array($v)) return new WP_Error('tix_invalid_field', 'co_money: {owner, share}.', ['status' => 400, 'field' => 'co_money']);
        if (!$k) return new WP_Error('tix_invalid_field', 'Ohne Co-Veranstalter gibt es keinen Geldempfänger zu wählen.', ['status' => 400, 'field' => 'co_money']);
        $c = self::check_values($post_id, $v, true);
        // Lizenzseite: die Plattform prüft und übernimmt (Verkaufsinstanz beim Co, 1.38.713)
        if (is_array($c) && $k === 'remote' && class_exists('TIX_Co_Remote')) {
            $r = TIX_Co_Remote::send_money($post_id, $c['owner'], $c['share']);
            if (is_wp_error($r)) return $r;
        }
        return $c;
    }

    public static function apply($eid, $c, $via = 'creator') {
        if (!is_array($c)) return;
        update_post_meta($eid, self::META_OWNER, $c['owner']);
        update_post_meta($eid, self::META_SHARE, (string) $c['share']);
        update_post_meta($eid, self::META_BY, ['at' => gmdate('c'), 'by' => get_current_user_id(), 'via' => $via]);
        do_action('tix_co_money_changed', intval($eid), $c['owner'], $c['share']);
    }

    /** Co gelöst: zurück auf Ersteller ohne Anteil. */
    public static function reset($eid) {
        delete_post_meta($eid, self::META_OWNER);
        delete_post_meta($eid, self::META_SHARE);
        delete_post_meta($eid, self::META_BY);
    }

    /** Lösen erlaubt? Bei Co als Geldempfänger bzw. Anteil nur ohne bezahlte Bestellung. */
    public static function can_clear($eid) {
        if (self::owner($eid) === 'creator' && self::share($eid) <= 0) return true;
        if (!self::sold($eid)) return true;
        return new WP_Error('tix_co_money_locked', 'Der Co-Veranstalter nimmt Geld ein bzw. bekommt einen Anteil, und es gibt schon Verkäufe. Die Verbindung kann erst nach der Abrechnung gelöst werden.', ['status' => 409]);
    }

    // ═══════════════ Abrechnung (evendis.de) ═══════════════

    /** Veranstalter, an den die Event-Abrechnung geht. */
    public static function settlement_org($eid) {
        $eid = intval($eid);
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        if (self::owner($eid) === 'co' && self::co_kind($eid) === 'local') {
            $co = intval(TIX_Co_Organizer::ref_id($eid));
            if ($co && get_post_type($co) === 'tix_organizer') return $co;
        }
        // Geteiltes Event, das die Plattform für den Co verkauft (Fall B, 1.38.713)
        if (self::owner($eid) === 'co' && self::co_kind($eid) === 'mirror' && class_exists('TIX_Co_Seller') && TIX_Co_Seller::sells_here($eid)) {
            $co = intval(get_post_meta($eid, '_tix_co_remote_oid', true));
            if ($co && get_post_type($co) === 'tix_organizer') return $co;
        }
        return $main;
    }

    /** Die andere Partei (bekommt den Anteil) oder 0. */
    public static function share_org($eid) {
        $eid = intval($eid);
        if (self::share($eid) <= 0 || self::co_kind($eid) !== 'local') return 0;
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $co = intval(TIX_Co_Organizer::ref_id($eid));
        $o = self::owner($eid) === 'co' ? $main : $co;
        return $o && get_post_type($o) === 'tix_organizer' ? $o : 0;
    }

    /**
     * Anteil in einer Event-Abrechnung: Position `co_share` (−) beim Geldempfänger, offener Posten (+) für die andere Partei.
     * Wiederholbar (Neu berechnen): ersetzt eigene Position und noch offene Gegenposten, verrechnet schon übernommene.
     */
    public static function apply_share($sid) {
        global $wpdb;
        $s = TIX_Settlement::get($sid);
        if (!$s || $s->type !== 'event' || !$s->event_id) return;
        $eid = intval($s->event_id);
        $other = self::share_org($eid);
        $si = TIX_Settlement::items_table();
        $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE settlement_id = %d AND type = 'co_share'", $sid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE settlement_id = 0 AND type = 'co_share' AND ref_id = %d", $sid));
        if (!$other) return;
        $net = round(floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM {$si} WHERE settlement_id = %d AND type IN ('order','late')", $sid))), 2);
        $pct = self::share($eid);
        $amt = round($net * $pct / 100, 2);
        $done = round(floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM {$si} WHERE settlement_id > 0 AND type = 'co_share' AND ref_id = %d AND organizer_id = %d", $sid, $other))), 2);
        $title = self::dec($s->event_title);
        if (abs($amt) >= 0.01) {
            TIX_Settlement::insert_item(['settlement_id' => $sid, 'organizer_id' => intval($s->organizer_id), 'event_id' => $eid, 'type' => 'co_share', 'amount' => -$amt, 'ref_id' => $sid,
                'note' => 'Anteil an ' . self::dec(get_the_title($other)) . ' (' . self::pct($pct) . ' %)']);
        }
        if (abs($amt - $done) >= 0.01) {
            TIX_Settlement::insert_item(['settlement_id' => 0, 'organizer_id' => $other, 'event_id' => $eid, 'type' => 'co_share', 'amount' => round($amt - $done, 2), 'ref_id' => $sid,
                'note' => 'Anteil aus ' . $title . ' (' . self::pct($pct) . ' %)']);
        }
    }

    /** Nachverkauf nach der Abrechnung: Anteil als offene Posten (−/+). */
    public static function late_share($eid, $owner_org, array $line, $order_id) {
        $other = self::share_org($eid);
        if (!$other) return;
        $amt = round(floatval($line['amount'] ?? 0) * self::share($eid) / 100, 2);
        if (abs($amt) < 0.01) return;
        $title = self::dec(get_the_title($eid));
        TIX_Settlement::insert_item(['settlement_id' => 0, 'organizer_id' => intval($owner_org), 'event_id' => intval($eid), 'order_id' => intval($order_id), 'type' => 'co_share', 'amount' => -$amt,
            'note' => 'Anteil an ' . self::dec(get_the_title($other)) . ' (Nachverkauf)']);
        TIX_Settlement::insert_item(['settlement_id' => 0, 'organizer_id' => $other, 'event_id' => intval($eid), 'order_id' => intval($order_id), 'type' => 'co_share', 'amount' => $amt,
            'note' => 'Anteil aus ' . $title . ' (Nachverkauf)']);
    }

    // ═══════════════ Ausgleichs-Abrechnung ═══════════════

    public static function balance($eid) {
        $eid = intval($eid);
        [$creator, $co] = self::names($eid);
        $owner = self::owner($eid);
        $share = self::share($eid);
        $orders = 0; $tickets = 0; $gross = 0.0; $refunds = 0.0; $pf = 0.0; $gf = 0.0; $net = 0.0;
        if (class_exists('TIX_Settlement')) {
            foreach (TIX_Settlement::event_orders($eid, false) as $row) {
                $l = TIX_Settlement::order_line($row, $eid, false);
                $orders++; $tickets += intval($l['tickets']); $gross += $l['gross']; $refunds += $l['refund']; $pf += $l['platform_fee']; $gf += $l['gateway_fee']; $net += $l['amount'];
            }
        }
        $end = class_exists('TIX_Settlement') ? intval(TIX_Settlement::event_end_ts($eid)) : 0;
        $payer = $owner === 'co' ? $co : $creator;
        $payee = $owner === 'co' ? $creator : $co;
        $amt = round($net * $share / 100, 2);
        return ['ok' => true,
            'event' => ['id' => $eid, 'title' => self::dec(get_the_title($eid)), 'date' => (string) get_post_meta($eid, '_tix_date_start', true)],
            'seller' => ['name' => $payer, 'host' => self::host()], 'owner' => $owner, 'share' => $share,
            'payer' => ['name' => $payer], 'payee' => ['name' => $payee],
            'orders' => $orders, 'tickets' => $tickets, 'gross' => round($gross, 2), 'refunds' => round($refunds, 2),
            'fees' => ['platform' => round($pf, 2), 'gateway' => round($gf, 2)], 'net' => round($net, 2), 'share_amount' => $amt, 'currency' => 'EUR',
            'as_of' => gmdate('c'), 'final' => $end > 0 && time() > $end + self::FINAL_DAYS * DAY_IN_SECONDS,
            'settled_by_platform' => self::settles_here($eid),
            'note' => self::settles_here($eid) ? 'Der Anteil wird mit der Abrechnung von ' . self::dec(get_bloginfo('name')) . ' verrechnet.'
                : ($share > 0 ? 'Den Ausgleich überweist ' . $payer . ' an ' . $payee . ' selbst.' : 'Kein Anteil vereinbart.')];
    }

    public static function perm_balance(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::co_kind($eid)) return new WP_Error('tix_co_none', 'Dieses Event hat keinen Co-Veranstalter.', ['status' => 409]);
        if (self::multi() && !in_array(TIX_Co_Organizer::role($eid), ['owner', 'admin', 'co'], true)) return new WP_Error('tix_co_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    public static function rest_balance(WP_REST_Request $req) {
        $eid = absint($req['id']);
        // Lizenzseite, Co verkauft: Stand der Verkaufsinstanz über die Plattform (1.38.713)
        if (class_exists('TIX_Co_Seller') && ($rb = TIX_Co_Seller::remote_balance($eid)) !== null) return rest_ensure_response($rb);
        return rest_ensure_response(self::balance($eid));
    }

    public static function rest_balance_pdf(WP_REST_Request $req) {
        $b = self::balance(absint($req['id']));
        if (!class_exists('TIX_Simple_PDF') && defined('TIXOMAT_PATH')) require_once TIXOMAT_PATH . 'includes/class-tix-event-report.php';
        if (!class_exists('TIX_Simple_PDF')) return new WP_Error('tix_pdf', 'PDF nicht verfügbar.', ['status' => 500]);
        $eur = function ($v) { return number_format(floatval($v), 2, ',', '.') . ' EUR'; };
        $pdf = new TIX_Simple_PDF(595, 842, 50, 40);
        $pdf->add_page();
        $y = 60;
        $pdf->set_font('Helvetica-Bold', 16); $pdf->text(50, $y, 'Ausgleichs-Abrechnung'); $y += 24;
        $pdf->set_font('Helvetica', 11);
        foreach ([
            ['Event', $b['event']['title'] . ($b['event']['date'] ? ' (' . $b['event']['date'] . ')' : '')],
            ['Verkauf durch', $b['seller']['name'] . ($b['seller']['host'] ? ' · ' . $b['seller']['host'] : '')],
            ['Stand', wp_date('d.m.Y H:i') . ($b['final'] ? ' (abgeschlossen)' : ' (Zwischenstand)')],
        ] as [$k, $v]) { $pdf->text(50, $y, $k . ':'); $pdf->text(180, $y, $v); $y += 16; }
        $y += 10;
        foreach ([
            ['Bestellungen / Tickets', $b['orders'] . ' / ' . $b['tickets']],
            ['Ticketumsatz', $eur($b['gross'])], ['Erstattungen', '- ' . $eur($b['refunds'])],
            ['Gebühren Plattform', '- ' . $eur($b['fees']['platform'])], ['Gebühren Zahlungsanbieter', '- ' . $eur($b['fees']['gateway'])],
            ['Netto', $eur($b['net'])], ['Anteil ' . self::pct($b['share']) . ' %', $eur($b['share_amount'])],
        ] as [$k, $v]) { $pdf->text(50, $y, $k); $pdf->text(330, $y, $v); $y += 16; }
        $y += 12;
        $pdf->set_font('Helvetica-Bold', 11);
        $pdf->text(50, $y, $b['share_amount'] > 0 ? $b['payer']['name'] . ' zahlt an ' . $b['payee']['name'] . ': ' . $eur($b['share_amount']) : 'Kein Ausgleich.'); $y += 18;
        $pdf->set_font('Helvetica', 9);
        $pdf->text(50, $y, $b['note']); $y += 12;
        $pdf->text(50, $y, 'Diese Übersicht ist keine Rechnung. Die Überweisung erfolgt zwischen den Veranstaltern.');
        $bytes = $pdf->output();
        return rest_ensure_response(['ok' => true, 'filename' => 'ausgleich-' . sanitize_title($b['event']['title']) . '.pdf', 'mime' => 'application/pdf', 'data' => base64_encode($bytes)]);
    }

    public static function routes() {
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-balance', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_balance'], 'permission_callback' => [__CLASS__, 'perm_balance']]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-balance/pdf', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_balance_pdf'], 'permission_callback' => [__CLASS__, 'perm_balance']]);
    }
}
