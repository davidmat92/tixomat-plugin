<?php
/**
 * TIX_Offer_Plan – Planrechnung „Dein eigener Ticketshop“ fürs Angebot (AN1, Auftrag Angebote Selbstbetrieb 14.11.2026;
 * ab 1.38.816). Rechnung 1:1 wie rechne()/break_even() in docs/vertrieb/gen_angebot_muster.py (Strategie).
 *
 * Je Stufe t (Tickets im Jahr), Beträge netto aus Sicht des Veranstalters (Zahlungsgebühren wie beim Anbieter):
 *   Bestellungen = t / Tickets je Bestellung · Ticketumsatz = t × Ø Preis · eigene Gebühr = t × Service-/VVK-Gebühr
 *   Zahlungsanbieter = t × (Preis + Gebühr) × Mischsatz % + Bestellungen × Mischsatz fix
 *   evendis = Jahrespreis eigener Shop nach Staffel (bzw. fester Preis aus den Annahmen; über der letzten Stufe: auf Anfrage)
 *   bleibt bei dir = Umsatz + eigene Gebühr − Zahlungsanbieter − evendis
 *   Plattform: Gebühr je Ticket brutto = Preis × % + fix (Katalog „Tickets über evendis“), netto = brutto ÷ (1 + MwSt.)
 *     – Gäste zahlen sie: dir bleibt der Ticketumsatz · – du trägst sie: Umsatz − t × Gebühr netto (Vorsteuer abziehbar)
 *   Vorteil mit eigener Gebühr = bleibt bei dir − Plattform (Gäste zahlen); ohne eigene Gebühr = bleibt bei dir (ohne Gebühr) − Plattform (du trägst)
 *   Break-even = erste Ticketzahl mit Vorteil > 0 (linear je Staffel-Stufe).
 * Alle Annahmen sind je Aufruf änderbar (User 14.11.: „IMMER änderbar“). Vorgaben im Katalog `plan_defaults`:
 * Ø 25 €, eigene Gebühr 1,50 €, 2 Tickets je Bestellung, Mischung 60 % PayPal / 40 % Karte, Stufen 1.000/5.000/10.000 (Staffel 790 € bis 5.000, 1.690 € bis 10.000, darüber auf Anfrage).
 * Zahlungsanbieter: Mollie, Stripe und PayPal gleichwertig nebeneinander ohne Empfehlung (User 14.11.; PayPal seit 1.38.826).
 */
if (!defined('ABSPATH')) exit;

class TIX_Offer_Plan {

    const MAX_STEPS = 6;
    const MAX_T = 10000000;

    /** Staffel eigener Shop und selbstgehostet (User 14.11. abends: „Bis 5000: 790, bis 10000 1690, darüber auf Anfrage“, 1.38.825) */
    public static function default_tiers() { return [['max' => 5000, 'price' => 790.0], ['max' => 10000, 'price' => 1690.0]]; }
    /** Frühere Vorgaben (bis 1.38.824) – gespeicherte Kataloge mit genau diesen Werten werden auf die neue Staffel gehoben */
    public static function old_default_tiers() { return [['max' => 5000, 'price' => 790.0], ['max' => 20000, 'price' => 1490.0], ['max' => 50000, 'price' => 2490.0]]; }
    const OLD_DEFAULT_STEPS = [1000, 5000, 20000, 50000];

    public static function default_settings() {
        return ['avg_price' => 25.0, 'service_fee' => 1.50, 'tickets_per_order' => 2, 'mix' => ['paypal' => 60, 'card' => 40], 'steps' => [1000, 5000, 10000], 'channel_share' => 0];
    }

    private static function num($v, $min, $max) {
        if ($v === null || $v === '') return null;
        if (is_string($v)) $v = str_replace(',', '.', $v);
        if (!is_numeric($v)) return false;
        $v = floatval($v);
        return ($v < $min || $v > $max) ? false : $v;
    }

    private static function bad($f, $m) { return new WP_Error('tix_invalid_field', $m, ['status' => 400, 'field' => 'plan.' . $f]); }

    /** Eingaben prüfen, fehlende aus den Katalog-Vorgaben → array oder WP_Error. $in = null → null (keine Planrechnung). */
    public static function clean($in, array $cat) {
        if ($in === null || $in === '' || $in === false) return null;
        if (!is_array($in)) return self::bad('', 'Planrechnung: Objekt erwartet.');
        $d = array_merge(self::default_settings(), (array) ($cat['plan_defaults'] ?? []));
        $rates = TIX_Platform_Offers::rates_by_key($cat['payment_rates'] ?? []);
        $out = [];
        foreach (['avg_price' => [0, 100000], 'service_fee' => [0, 1000]] as $k => [$mn, $mx]) {
            $v = array_key_exists($k, $in) ? self::num($in[$k], $mn, $mx) : floatval($d[$k]);
            if ($v === false || $v === null) return self::bad($k, 'Bitte eine Zahl angeben.');
            $out[$k] = $v;
        }
        $tpo = array_key_exists('tickets_per_order', $in) ? self::num($in['tickets_per_order'], 1, 50) : floatval($d['tickets_per_order']);
        if ($tpo === false || $tpo === null) return self::bad('tickets_per_order', 'Tickets je Bestellung 1 bis 50.');
        $out['tickets_per_order'] = $tpo;
        $mix = array_key_exists('mix', $in) ? $in['mix'] : $d['mix'];
        if (!is_array($mix) || !$mix) return self::bad('mix', 'Bitte die Zahlart-Mischung angeben.');
        $clean = []; $sum = 0.0;
        foreach ($mix as $k => $share) {
            $k = sanitize_key((string) $k);
            $v = self::num($share, 0, 100);
            if (!isset($rates[$k])) return self::bad('mix.' . $k, 'Diese Zahlart gibt es im Katalog nicht.');
            if ($v === false || $v === null) return self::bad('mix.' . $k, 'Anteil 0 bis 100 %.');
            if ($v > 0) { $clean[$k] = $v; $sum += $v; }
        }
        if (abs($sum - 100) > 0.01) return self::bad('mix', 'Die Anteile müssen zusammen 100 % ergeben.');
        $out['mix'] = $clean;
        $steps = array_key_exists('steps', $in) ? (array) $in['steps'] : (array) $d['steps'];
        $st = [];
        foreach ($steps as $s) { $v = intval($s); if ($v < 1 || $v > self::MAX_T) return self::bad('steps', 'Stufen 1 bis 10.000.000 Tickets.'); if (!in_array($v, $st, true)) $st[] = $v; }
        if (count($st) > self::MAX_STEPS) return self::bad('steps', 'Höchstens ' . self::MAX_STEPS . ' Stufen.');
        $custom = array_key_exists('custom', $in) && $in['custom'] !== null && $in['custom'] !== '' ? intval($in['custom']) : null;
        if ($custom !== null && ($custom < 1 || $custom > self::MAX_T)) return self::bad('custom', 'Eigene Zahl 1 bis 10.000.000 Tickets.');
        if ($custom !== null && !in_array($custom, $st, true)) $st[] = $custom;
        sort($st);
        if (!$st) return self::bad('steps', 'Bitte mindestens eine Stufe.');
        $out['steps'] = $st;
        $out['custom'] = $custom;
        $sp = array_key_exists('shop_price', $in) ? self::num($in['shop_price'], 0, 1000000) : null;
        if ($sp === false) return self::bad('shop_price', 'Jahrespreis 0 bis 1.000.000 €.');
        $out['shop_price'] = $sp; // null = Staffel aus dem Katalog
        $cs = array_key_exists('channel_share', $in) ? self::num($in['channel_share'], 0, 100) : floatval($d['channel_share'] ?? 0);
        if ($cs === false || $cs === null) return self::bad('channel_share', 'Anteil über evendis.de 0 bis 100 %.');
        $out['channel_share'] = $cs; // AN2: Anteil der Tickets über evendis.de (zählt nur mit Option evendis_channel)
        // Führende Stufe (1.38.822): Faktenblock ganz oben; null = Vorgabe (eigene Zahl, sonst Hochrechnung des Angebots, sonst 5.000)
        $lead = array_key_exists('lead', $in) && $in['lead'] !== null && $in['lead'] !== '' ? intval($in['lead']) : null;
        if ($lead !== null && ($lead < 1 || $lead > self::MAX_T)) return self::bad('lead', 'Führende Stufe 1 bis 10.000.000 Tickets.');
        $out['lead'] = $lead;
        return $out;
    }

    /** Führende Stufe: gewählt → eigene Zahl → Hinweis aus dem Angebot (Hochrechnung) → 5.000 (bzw. mittlere Stufe). Auch Grundlage des Jahrespreises (1.38.823). */
    public static function lead_of(array $p, $hint = null) {
        $lead = $p['lead'] ?? null;
        if ($lead === null) $lead = $p['custom'] ?? null;
        if ($lead === null && !empty($hint)) $lead = max(1, min(self::MAX_T, intval($hint)));
        $steps = (array) ($p['steps'] ?? []);
        if ($lead === null) $lead = in_array(5000, $steps, true) || !$steps ? 5000 : $steps[intdiv(count($steps), 2)];
        return intval($lead);
    }

    /** Jahrespreis eigener Shop für t Tickets (null = auf Anfrage) */
    public static function shop_price($t, array $tiers, $fixed = null) {
        if ($fixed !== null) return floatval($fixed);
        usort($tiers, function ($a, $b) { return $a['max'] <=> $b['max']; });
        foreach ($tiers as $x) if ($t <= intval($x['max'])) return floatval($x['price']);
        return null;
    }

    /** Mischsatz {pct (Anteil), fix (€)} aus Mischung (Prozent je Zahlart) und Katalog-Sätzen */
    public static function blend(array $mix, array $rates) {
        $pct = 0.0; $fix = 0.0;
        foreach ($mix as $k => $share) { $r = $rates[$k] ?? []; $pct += floatval($r['percent'] ?? 0) / 100 * $share / 100; $fix += floatval($r['fixed'] ?? 0) * $share / 100; }
        return ['pct' => $pct, 'fix' => $fix];
    }

    /** Eine Stufe wie rechne() (ungerundet) */
    public static function row($t, array $p, array $b, $shop, array $pf, $vat, $with_fee = true, array $x = []) {
        $sg = $with_fee ? $p['service_fee'] : 0.0;
        $orders = $t / $p['tickets_per_order'];
        $umsatz = $t * $p['avg_price'];
        $ein = $t * $sg;
        $zahlung = $t * ($p['avg_price'] + $sg) * $b['pct'] + $orders * $b['fix'];
        $pf_brutto = $p['avg_price'] * $pf['percent'] / 100 + $pf['fixed'];
        $pf_netto = $pf_brutto / $vat;
        $opt = floatval($x['extras_year'] ?? 0); // angehakte Optionen im Jahr (AN2)
        $server = floatval($x['server'] ?? 0); // selbstgehostet: Server beim Hoster des Kunden (ca., je Jahr)
        $kanal = !empty($x['channel']) ? $t * floatval($p['channel_share'] ?? 0) / 100 * ($p['avg_price'] * $x['channel']['percent'] / 100 + $x['channel']['fixed']) / $vat : 0.0; // netto
        $selbst = $shop === null ? null : $umsatz + $ein - $zahlung - $shop - $opt - $server - $kanal;
        $pf_g = $umsatz; $pf_s = $umsatz - $t * $pf_netto;
        return ['tickets' => $t, 'orders' => $orders, 'revenue' => $umsatz, 'own_fee' => $ein, 'psp' => $zahlung, 'evendis' => $shop, 'options' => $opt, 'server' => $server, 'channel' => $kanal, 'kept' => $selbst,
            'pf_gross' => $pf_brutto, 'pf_net' => $pf_netto, 'platform_guests' => $pf_g, 'platform_self' => $pf_s,
            'advantage' => $selbst === null ? null : $selbst - ($with_fee ? $pf_g : $pf_s)];
    }

    /** Break-even wie break_even(), je Staffel-Stufe linear → Tickets oder null */
    public static function break_even(array $p, array $b, array $tiers, $fixed, array $pf, $vat, $with_fee, array $x = []) {
        $segs = [];
        if ($fixed !== null) $segs[] = [0, self::MAX_T, floatval($fixed)];
        else { usort($tiers, function ($a, $c) { return $a['max'] <=> $c['max']; }); $prev = 0; foreach ($tiers as $tr) { $segs[] = [$prev, intval($tr["max"]), floatval($tr["price"])]; $prev = intval($tr["max"]); } }
        foreach ($segs as [$from, $to, $price]) {
            $lo = self::row(0, $p, $b, $price, $pf, $vat, $with_fee, $x)['advantage'];
            $hi = self::row(1, $p, $b, $price, $pf, $vat, $with_fee, $x)['advantage'] - $lo;
            if ($hi <= 0) continue;
            $t = intval(-$lo / $hi) + 1;
            if ($t <= $from) $t = $from + 1;
            if ($t <= $to) return $t;
        }
        return null;
    }

    /** Planrechnung komplett (gerundet) für die Studio-Vorschau, das PDF und die Online-Ansicht */
    /**
     * $ctx (aus dem Angebot, AN2): tiers (frei gesetzte Staffel), fixed (Jahrespreis des angehakten Grundpakets), extras_year,
     * extras [{name, year}], once (einmalig), channel {percent, fixed} | null (Option evendis_channel), factor (Rabatt auf „an evendis“)
     */
    public static function compute(array $p, array $cat, array $ctx = []) {
        $rates = TIX_Platform_Offers::rates_by_key($cat['payment_rates'] ?? []);
        // Betriebsart (User 14.11.): own_shop = unsere Server, self_hosted = Server des Kunden; Vorgabe own_shop (AN1)
        $mode = in_array($ctx['mode'] ?? '', ['own_shop', 'self_hosted'], true) ? $ctx['mode'] : 'own_shop';
        $f = isset($ctx['factor']) ? floatval($ctx['factor']) : 1.0;
        $tiers_of = function ($m) use ($ctx, $cat, $f) {
            $t = !empty($ctx['tiers_by_mode'][$m]) ? (array) $ctx['tiers_by_mode'][$m]
                : ($m === 'self_hosted' ? (!empty($cat['self_hosted_tiers']) ? (array) $cat['self_hosted_tiers'] : self::default_tiers())
                : (!empty($cat['shop_tiers']) ? (array) $cat['shop_tiers'] : self::default_tiers()));
            return abs($f - 1) > 0.00001 ? array_map(function ($x) use ($f) { return ['max' => $x['max'], 'price' => floatval($x['price']) * $f]; }, $t) : $t;
        };
        if (!empty($ctx['tiers'])) $ctx['tiers_by_mode'][$mode] = $ctx['tiers'];
        $tiers = $tiers_of($mode);
        $fixed = $p['shop_price'] !== null ? $p['shop_price'] : (isset($ctx['fixed']) && $ctx['fixed'] !== null ? floatval($ctx['fixed']) * $f : null);
        $server_year = array_key_exists('server_year', $ctx) ? floatval($ctx['server_year']) : floatval($cat['server_month'] ?? 20) * 12;
        $x = ['extras_year' => floatval($ctx['extras_year'] ?? 0) * $f, 'channel' => $ctx['channel'] ?? null, 'server' => $mode === 'self_hosted' ? $server_year : 0.0];
        $vat = 1 + floatval($cat['tax_rate'] ?? 19) / 100;
        $pl = TIX_Platform_Offers::catalog_item('tickets');
        $pf = ['percent' => floatval($pl['fee_percent'] ?? 2.9), 'fixed' => floatval($pl['fee_fixed'] ?? 0.49)];
        $b = self::blend($p['mix'], $rates);
        $r2 = function ($v) { return $v === null ? null : round($v, 2); };
        // Führende Stufe: gewählt → eigene Zahl → Hinweis aus dem Angebot (Hochrechnung) → 5.000 (bzw. mittlere Stufe); fehlt sie in den Stufen, kommt sie dazu
        $lead = self::lead_of($p, $ctx['lead_hint'] ?? null);
        if (!in_array($lead, $p['steps'], true)) { $p['steps'][] = $lead; sort($p['steps']); }
        $p['lead'] = $lead;
        $rows = [];
        foreach ($p['steps'] as $t) {
            $shop = self::shop_price($t, $tiers, $fixed);
            $w = self::row($t, $p, $b, $shop, $pf, $vat, true, $x);
            $wo = self::row($t, $p, $b, $shop, $pf, $vat, false, $x);
            $rows[] = ['tickets' => $t, 'custom' => $p['custom'] === $t, 'orders' => round($w['orders'], 1), 'revenue' => $r2($w['revenue']), 'own_fee' => $r2($w['own_fee']),
                'psp' => $r2($w['psp']), 'evendis' => $r2($shop), 'on_request' => $shop === null, 'options' => $r2($w['options']), 'channel_fee' => $r2($w['channel']),
                'evendis_total' => $shop === null ? null : $r2($shop + $w['options'] + $w['channel']), 'server' => $r2($w['server']), 'kept' => $r2($w['kept']),
                'platform_guests_pay' => $r2($w['platform_guests']), 'platform_you_pay' => $r2($w['platform_self']),
                'advantage' => $r2($w['advantage']), 'kept_without_fee' => $r2($wo['kept']), 'advantage_without_fee' => $r2($wo['advantage'])];
        }
        // Vergleich der Betriebsarten (Plattform steckt in jeder Zeile): eigener Shop und selbstgehostet mit ihrer Staffel
        $by_mode = []; $raw = [];
        foreach (['own_shop', 'self_hosted'] as $m) {
            $tm = $m === $mode ? $tiers : $tiers_of($m);
            $fm = $m === $mode ? $fixed : null;
            $xm = ['extras_year' => $x['extras_year'], 'channel' => $x['channel'], 'server' => $m === 'self_hosted' ? $server_year : 0.0];
            $by_mode[$m] = ['break_even' => ['with_fee' => self::break_even($p, $b, $tm, $fm, $pf, $vat, true, $xm), 'without_fee' => self::break_even($p, $b, $tm, $fm, $pf, $vat, false, $xm)]];
            foreach ($p['steps'] as $i => $t) {
                $sp = self::shop_price($t, $tm, $fm);
                $w = self::row($t, $p, $b, $sp, $pf, $vat, true, $xm);
                $rows[$i]['compare'][$m] = ['evendis' => $r2($sp), 'server' => $r2($w['server']), 'kept' => $r2($w['kept']), 'advantage' => $r2($w['advantage'])];
                $raw[$i][$m] = $w + ['shop' => $sp];
            }
        }
        // Summen-Zusammenfassung je Stufe und Betriebsart (User 14.11.: was insgesamt an evendis, an den Zahlungsdienstleister, was übrig bleibt)
        // Im 1. Jahr inkl. einmaliger Kosten (Einrichtung, App-Einrichtung …). evendis netto (zzgl. USt, Vorsteuer), Ticketumsatz und eigene Gebühr brutto wie vom Gast bezahlt.
        $once_by = (array) ($ctx['once_by_mode'] ?? []);
        $totals = [];
        foreach ($p['steps'] as $i => $t) {
            $modes = [];
            foreach (['own_shop', 'self_hosted'] as $m) {
                $w = $raw[$i][$m];
                $once = floatval($once_by[$m] ?? ($ctx['once'] ?? 0)); // Rabatt schon eingerechnet (plan_context)
                if ($w['shop'] === null) { $modes[$m] = ['on_request' => true]; continue; }
                $ev = $w['shop'] + $w['options'] + $w['channel'] + $once;
                $kept = $w['revenue'] + $w['own_fee'] - $ev - $w['psp'] - $w['server'];
                $modes[$m] = ['on_request' => false, 'revenue' => $r2($w['revenue']), 'own_fee' => $r2($w['own_fee']), 'to_evendis' => $r2($ev), 'to_evendis_once' => $r2($once),
                    'to_psp' => $r2($w['psp']), 'to_hoster' => $r2($w['server']), 'kept' => $r2($kept), 'kept_per_ticket' => $t > 0 ? $r2($kept / $t) : null,
                    'kept_percent' => $w['revenue'] > 0 ? round($kept / $w['revenue'] * 100, 1) : null];
            }
            $w = $raw[$i]['own_shop'];
            $pf_you = $t * $w['pf_net'];
            $modes['platform'] = ['on_request' => false, 'revenue' => $r2($w['revenue']), 'own_fee' => 0.0, 'to_evendis' => $r2($pf_you), 'to_evendis_once' => 0.0, 'to_psp' => 0.0, 'to_hoster' => 0.0,
                'kept' => $r2($w['revenue'] - $pf_you), 'kept_per_ticket' => $t > 0 ? $r2(($w['revenue'] - $pf_you) / $t) : null, 'kept_percent' => $w['revenue'] > 0 ? round(($w['revenue'] - $pf_you) / $w['revenue'] * 100, 1) : null,
                'bearer' => 'organizer', 'guests_pay_variant' => ['guest_fee_total' => $r2($t * $w['pf_gross']), 'kept' => $r2($w['revenue'])]];
            $totals[] = ['tickets' => $t, 'custom' => $p['custom'] === $t, 'modes' => $modes];
        }
        // Faktenblock der führenden Stufe (User 14.11.: „ganz oben als Fakt … das Hauptargument“) + Beispiel einer Bestellung
        $li = array_search($lead, $p['steps'], true);
        $tm = $totals[$li]['modes'];
        $own = $tm[$mode];
        $wl = $raw[$li][$mode];
        $order_gross = $p['tickets_per_order'] * ($p['avg_price'] + $p['service_fee']);
        $ex = []; $blended_fee = 0.0;
        foreach ($p['mix'] as $k => $share) {
            $r = $rates[$k] ?? [];
            $fee = $order_gross * floatval($r['percent'] ?? 0) / 100 + floatval($r['fixed'] ?? 0);
            $blended_fee += $fee * $share / 100;
            $ex[] = ['key' => $k, 'label' => (string) ($r['label'] ?? $k), 'provider' => (string) ($r['provider'] ?? ''), 'share' => $share,
                'percent' => isset($r['percent']) ? floatval($r['percent']) : null, 'fixed' => isset($r['fixed']) ? floatval($r['fixed']) : null, 'fee' => round($fee, 2)];
        }
        $lead_block = empty($own['on_request']) ? [
            'tickets' => $lead, 'mode' => $mode, 'on_request' => false,
            'revenue' => $own['revenue'], 'own_fee' => $own['own_fee'], 'orders' => round($wl['orders'], 1),
            'to_evendis' => $own['to_evendis'], 'to_evendis_once' => $own['to_evendis_once'], 'base_price' => $r2($wl['shop']), 'options_year' => $r2($wl['options']), 'channel_fee' => $r2($wl['channel']),
            'to_psp' => $own['to_psp'], 'to_hoster' => $own['to_hoster'],
            'kept' => $own['kept'], 'kept_per_ticket' => $own['kept_per_ticket'], 'kept_percent' => $own['kept_percent'],
            'platform' => ['fee_per_ticket_gross' => $r2($wl['pf_gross']), 'fee_per_ticket_net' => $r2($wl['pf_net']), 'kept_you_pay' => $tm['platform']['kept'],
                'kept_guests_pay' => $tm['platform']['guests_pay_variant']['kept'], 'guest_fee_total' => $tm['platform']['guests_pay_variant']['guest_fee_total']],
            'savings_vs_platform' => $r2($own['kept'] - $tm['platform']['kept']), // gegenüber Plattform, wenn du die Gebühr trägst
            'savings_vs_platform_guests' => $r2($own['kept'] - $tm['platform']['guests_pay_variant']['kept']), // gegenüber Plattform, wenn Gäste die Gebühr zahlen
            'other_mode' => $mode === 'self_hosted' ? 'own_shop' : 'self_hosted', 'other_kept' => $tm[$mode === 'self_hosted' ? 'own_shop' : 'self_hosted']['kept'] ?? null,
            'order_example' => ['tickets' => $p['tickets_per_order'], 'ticket_price' => round($p['avg_price'], 2), 'own_fee' => round($p['service_fee'], 2), 'gross' => round($order_gross, 2),
                'methods' => $ex, 'blended_fee' => round($blended_fee, 2)],
        ] : ['tickets' => $lead, 'mode' => $mode, 'on_request' => true];
        $methods = [];
        foreach ($p['mix'] as $k => $share) {
            $r = $rates[$k];
            $methods[] = ['key' => $k, 'label' => (string) ($r['label'] ?? $k), 'provider' => (string) ($r['provider'] ?? ''), 'detail' => (string) ($r['detail'] ?? ''), 'share' => $share,
                'percent' => isset($r['percent']) ? floatval($r['percent']) : null, 'fixed' => isset($r['fixed']) ? floatval($r['fixed']) : null,
                'as_of' => (string) ($r['as_of'] ?? ''), 'source' => (string) ($r['source'] ?? '')];
        }
        $pf_gross = $p['avg_price'] * $pf['percent'] / 100 + $pf['fixed'];
        $sorted = $tiers; usort($sorted, function ($a, $c) { return $a['max'] <=> $c['max']; });
        $all_rates = [];
        foreach ($rates as $k => $r) $all_rates[] = ['key' => $k, 'label' => (string) ($r['label'] ?? $k), 'provider' => (string) ($r['provider'] ?? ''), 'percent' => $r['percent'] ?? null, 'fixed' => $r['fixed'] ?? null, 'as_of' => (string) ($r['as_of'] ?? ''), 'source' => (string) ($r['source'] ?? '')];
        return [
            'input' => $p,
            'mode' => $mode, // Betriebsart der Hauptzeilen (rows.kept); rows[].compare und by_mode für beide eigenen Betriebsarten
            'server_month' => round($server_year / 12, 2),
            'by_mode' => $by_mode,
            'totals' => $totals,
            'lead' => $lead_block,
            'totals_note' => 'Im 1. Jahr inkl. einmaliger Kosten. Ticketumsatz und eigene Gebühr brutto (wie vom Gast bezahlt, inkl. USt); an evendis netto zzgl. USt; Plattformgebühr netto (÷ ' . number_format($vat, 2, ',', '') . '); Zahlungsgebühren wie vom Anbieter berechnet (ohne USt); Server beim Hoster ca.',
            'rows' => $rows,
            'break_even' => ['with_fee' => self::break_even($p, $b, $tiers, $fixed, $pf, $vat, true, $x), 'without_fee' => self::break_even($p, $b, $tiers, $fixed, $pf, $vat, false, $x)],
            // Block 1: an evendis
            'to_evendis' => ['tiers' => array_map(function ($x) { return ['max' => intval($x['max']), 'price' => round(floatval($x['price']), 2)]; }, $sorted), 'fixed_price' => $fixed === null ? null : round($fixed, 2),
                'base' => $ctx['base'] ?? null, 'options' => array_values((array) ($ctx['extras'] ?? [])), 'options_year' => round($x['extras_year'], 2), 'once' => round(floatval($ctx['once'] ?? 0), 2),
                'channel' => $x['channel'] ? $x['channel'] + ['share' => $p['channel_share'] ?? 0, 'tax_note' => 'inkl. MwSt.'] : null,
                'above' => 'auf Anfrage', 'per_ticket' => 0.0, 'tax_note' => 'zzgl. MwSt.'],
            // Block 2: an den Zahlungsanbieter (Mollie, Stripe und PayPal nebeneinander, ohne Empfehlung)
            'to_psp' => ['methods' => $methods, 'blended_percent' => round($b['pct'] * 100, 4), 'blended_fixed' => round($b['fix'], 4), 'catalog' => $all_rates,
                'note' => (string) ($cat['payment_note'] ?? ''), 'providers_note' => 'Mollie, Stripe und PayPal stehen gleichwertig nebeneinander – du wählst deinen Anbieter.'],
            // Block 3: was dein Gast zahlt
            'guest' => ['ticket_price' => round($p['avg_price'], 2), 'own_fee' => round($p['service_fee'], 2), 'pays_own_shop' => round($p['avg_price'] + $p['service_fee'], 2),
                'platform_fee' => round($pf_gross, 2), 'pays_platform' => round($p['avg_price'] + $pf_gross, 2), 'own_fee_stays_with_you' => true],
            'platform' => $pf + ['tax_note' => 'inkl. MwSt. und Zahlung'],
            'notes' => ['Alle Beträge netto (zzgl. MwSt.), Zahlungsgebühren wie beim Anbieter, ohne Gewähr.', 'Annahmen sind jederzeit änderbar.'],
        ];
    }
}
