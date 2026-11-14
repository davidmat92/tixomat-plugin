<?php
/**
 * Zweitmarkt (Weiterverkauf) – reine Regeln ohne WordPress (Weg 1, Regel 3/7, §223).
 *
 * Alles hier ist eine reine Funktion: gleiche Eingabe, gleiches Ergebnis, kein Datenbank- oder WP-Aufruf.
 * Ein künftiger Dienst (evendis-api) übernimmt diese Datei 1:1 bzw. rechnet dieselben Ergebnisse
 * (Abnahme-Tests: tests/test-resale-wp.php, Abschnitt „Regeln“).
 */
if (!defined('ABSPATH')) exit;

class TIX_Resale_Rules {

    /**
     * Vorgaben (User-Entscheide F-RS3/F-RS4): aus, nur bei ausverkauft, höchstens Originalpreis, bis 2 h vor Beginn.
     * Höchstpreis (User 14.11.: „Max. Originalpreis“): immer der erste Kaufpreis – es gibt nur noch mode = original.
     */
    const DEFAULTS = ['enabled' => false, 'sold_out_only' => true, 'max_price' => ['mode' => 'original', 'percent' => 100], 'deadline_hours' => 2];

    const MIN_PRICE   = 1.0;      // kleinster Angebotspreis
    const PURGE_DAYS  = 30;       // Angebote ohne Verkauf: Löschfrist nach Veranstaltungsende (Rechtstexte, 14.11.)
    const LOCK_SETUP  = 900;      // Sekunden: Sperre zwischen Prüfung und angelegter Bestellung
    const LOCK_ORDER  = 3600;     // Sekunden: Sperre, solange die Zahlung läuft
    const PAYOUT_TRIES = 3;       // Versuche der Erstattung aufs Zahlungsmittel, dann Rückfall

    /** Zustandsautomat der Angebote: von → erlaubte Ziele. */
    const TRANSITIONS = [
        'open'      => ['locked', 'withdrawn', 'expired'],
        'locked'    => ['open', 'sold', 'expired', 'withdrawn'],
        'sold'      => [],
        'withdrawn' => [],
        'expired'   => [],
    ];

    /** Gründe, warum ein Ticket nicht angeboten werden kann (Schlüssel = `reason` der Kunden-Route). */
    const REASONS = [
        'disabled'     => 'Für dieses Event ist der Weiterverkauf nicht freigeschaltet.',
        'not_sold_out' => 'Weiterverkauf ist möglich, sobald die Kategorie ausverkauft ist.',
        'deadline'     => 'Die Frist für den Weiterverkauf ist abgelaufen.',
        'checked_in'   => 'Eingecheckte Tickets können nicht weiterverkauft werden.',
        'giftcard'     => 'Gutscheine und Extras können nicht weiterverkauft werden.',
        'transferred'  => 'Nur wer das Ticket gekauft hat, kann es weiterverkaufen.',
        'cancelled'    => 'Dieses Ticket ist nicht mehr gültig.',
        'resold'       => 'Dieses Ticket wurde bereits weiterverkauft.',
        'free'         => 'Kostenlose Tickets können nicht weiterverkauft werden.',
        'event'        => 'Für dieses Event ist kein Weiterverkauf mehr möglich.',
    ];

    public static function can_move($from, $to) {
        return in_array((string) $to, self::TRANSITIONS[(string) $from] ?? [], true);
    }

    /**
     * Regeln prüfen und normalisieren. Rückgabe [rules, null] oder [null, ['field' => …, 'message' => …]].
     * Fehlende Felder kommen aus $base (Vorgabe: DEFAULTS).
     * $strict (Speichern): ein Höchstpreis über dem Originalpreis (mode percent > 100, none) → Fehler.
     * Lesen ($strict = false): alte gespeicherte Werte werden still auf den Originalpreis gekappt.
     */
    public static function sanitize($in, array $base = null, $strict = true) {
        $base = $base ?: self::DEFAULTS;
        if (!is_array($in)) $in = [];
        $r = $base;
        $r['max_price'] = ['mode' => 'original', 'percent' => 100];
        if (array_key_exists('enabled', $in)) $r['enabled'] = self::bool($in['enabled']);
        if (array_key_exists('sold_out_only', $in)) $r['sold_out_only'] = self::bool($in['sold_out_only']);
        if (array_key_exists('max_price', $in) && $strict) {
            $mp = $in['max_price'];
            $msg = 'Der Höchstpreis ist immer der Originalpreis (max_price.mode = original).';
            if (is_string($mp)) $mp = ['mode' => $mp];
            if (!is_array($mp)) return [null, ['field' => 'max_price', 'message' => $msg]];
            $mode = (string) ($mp['mode'] ?? 'original');
            if ($mode === 'percent') {
                $pct = $mp['percent'] ?? null;
                if (!is_numeric($pct) || abs(floatval($pct) - 100) > 0.000001) return [null, ['field' => 'max_price.percent', 'message' => $msg]];
            } elseif ($mode !== 'original') {
                return [null, ['field' => 'max_price', 'message' => $msg]];
            } elseif (array_key_exists('percent', $mp) && (!is_numeric($mp['percent']) || abs(floatval($mp['percent']) - 100) > 0.000001)) {
                return [null, ['field' => 'max_price.percent', 'message' => $msg]];
            }
        }
        if (array_key_exists('deadline_hours', $in)) {
            $h = $in['deadline_hours'];
            if (!is_numeric($h) || floatval($h) < 0 || floatval($h) > 168 || floor(floatval($h)) != floatval($h)) {
                if ($strict) return [null, ['field' => 'deadline_hours', 'message' => 'Die Frist liegt zwischen 0 und 168 Stunden vor Beginn.']];
            } else {
                $r['deadline_hours'] = intval($h);
            }
        }
        return [$r, null];
    }

    private static function bool($v) {
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Höchstpreis = Originalpreis (erster Kaufpreis, Euro, 2 Stellen) – nie darüber, egal was gespeichert ist. */
    public static function max_price($original, array $rules = []) {
        return round(max(0, floatval($original)), 2);
    }

    /**
     * Löschfrist: Angebote ohne Verkauf (withdrawn, expired, open) werden PURGE_DAYS nach Veranstaltungsende gelöscht;
     * verkaufte (sold) und gerade gesperrte (locked) nie hier – sie folgen den Aufbewahrungsregeln der Bestellungen.
     */
    public static function purge_due($status, $event_end_ts, $now) {
        if (!in_array((string) $status, ['withdrawn', 'expired', 'open'], true)) return false;
        $end = intval($event_end_ts);
        return $end > 0 && intval($now) >= $end + self::PURGE_DAYS * 86400;
    }

    /** Letzter Zeitpunkt (Unix) zum Anbieten/Kaufen; 0 = Beginn unbekannt (dann kein Weiterverkauf). */
    public static function deadline_ts($start_ts, array $rules) {
        $start_ts = intval($start_ts);
        if ($start_ts <= 0) return 0;
        return $start_ts - intval($rules['deadline_hours'] ?? 2) * 3600;
    }

    /**
     * Darf dieses Ticket angeboten werden? → '' (ja) oder Grund aus REASONS.
     * $f: enabled, cat_off, event_ok, status, checked_in, gift, special, buyer, original, now, deadline, sold_out, sold_out_only
     * Reihenfolge = was der Gast zuerst lesen soll (Ticket-Zustand vor Event-Regeln).
     */
    public static function reason(array $f) {
        $status = (string) ($f['status'] ?? 'valid');
        if ($status === 'resold') return 'resold';
        if (in_array($status, ['cancelled', 'refunded'], true)) return 'cancelled';
        if (!empty($f['checked_in']) || $status === 'used') return 'checked_in';
        if (!empty($f['gift']) || !empty($f['special'])) return 'giftcard';
        if ($status === 'transferred' || empty($f['buyer'])) return 'transferred';
        if (empty($f['event_ok'])) return 'event';
        if (empty($f['enabled']) || !empty($f['cat_off'])) return 'disabled';
        if (round(floatval($f['original'] ?? 0), 2) < self::MIN_PRICE) return 'free';
        $deadline = intval($f['deadline'] ?? 0);
        if ($deadline <= 0 || intval($f['now'] ?? 0) >= $deadline) return 'deadline';
        if (!empty($f['sold_out_only']) && empty($f['sold_out'])) return 'not_sold_out';
        return '';
    }

    /** Preis prüfen → null (ok) oder ['code', 'message', 'max_price', 'min_price']. */
    public static function price_error($price, $original, array $rules) {
        $max = self::max_price($original, $rules);
        $min = self::MIN_PRICE;
        if (!is_numeric($price)) return ['code' => 'tix_resale_price', 'message' => 'Bitte einen Preis angeben.', 'max_price' => $max, 'min_price' => $min];
        $p = floatval($price);
        if (abs(round($p, 2) - $p) > 0.000001) return ['code' => 'tix_resale_price', 'message' => 'Der Preis hat höchstens zwei Nachkommastellen.', 'max_price' => $max, 'min_price' => $min];
        if ($p < $min) return ['code' => 'tix_resale_price', 'message' => sprintf('Der Preis muss mindestens %s € betragen.', self::money($min)), 'max_price' => $max, 'min_price' => $min];
        if ($p > $max + 0.000001) return ['code' => 'tix_resale_price', 'message' => sprintf('Der Preis darf höchstens %s € betragen.', self::money($max)), 'max_price' => $max, 'min_price' => $min];
        return null;
    }

    /**
     * Auszahlungsweg (F-RS1): Teil-Erstattung aufs ursprüngliche Zahlungsmittel, sonst Rückfall.
     * → 'gateway' (Mollie/Stripe/PayPal), 'wallet_back' (Bestellung war mit Guthaben bezahlt) oder 'fallback'.
     */
    public static function payout_way($payment_method, $refundable, $amount) {
        $m = (string) $payment_method;
        if (in_array($m, ['mollie', 'stripe', 'paypal'], true) && round(floatval($refundable), 2) + 0.000001 >= round(floatval($amount), 2)) return 'gateway';
        if ($m === 'wallet') return 'wallet_back';
        return 'fallback';
    }

    /** Name für Listen ohne volle Daten: „Mia K.“ */
    public static function short_name($first, $last) {
        $first = trim((string) $first); $last = trim((string) $last);
        if ($first === '' && $last === '') return '';
        return trim($first . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : ''));
    }

    /** Ticket-Code gekürzt: „••••4821“ (nie der volle Code). */
    public static function mask_code($code) {
        $c = (string) $code;
        return $c === '' ? '' : '••••' . mb_substr($c, -4);
    }

    public static function money($v) {
        return number_format(round(floatval($v), 2), 2, ',', '.');
    }
}
