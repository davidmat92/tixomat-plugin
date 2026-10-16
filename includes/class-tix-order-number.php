<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Order_Number – kurze, zufällige Bestellnummern mit Prüfzeichen (1.38.481, Plan „Bestellnummern und Rechnungen“, Phase 1).
 *
 * Format `<Kürzel>-<5 Zeichen><Prüfzeichen>`, z. B. `KK-7HQ4M2`. Alphabet mit 30 Zeichen ohne 0/1/I/L/O/U
 * (am Telefon eindeutig), Prüfzeichen nach Luhn mod 30 (User-Entscheid 2026-10-16). Zufällig statt fortlaufend:
 * verrät keine Verkaufszahlen. Die interne ID der Bestellung bleibt fortlaufend.
 *
 * Schalter je Seite `tix_order_number_mode` = legacy (Vorgabe, bisheriges Format) | short.
 * Kürzel `tix_order_number_code` (2–5 Zeichen A–Z/0–9), Vorgabe: evendis `EV`, sonst aus dem bisherigen Präfix.
 * Nur neue Bestellungen; alte Nummern bleiben gültig. Suche über beide Formate: TIX_Order::find_by_number(),
 * tolerant ohne Bindestrich und Groß-/Kleinschreibung (TIX_Order_Number::normalize()).
 */
class TIX_Order_Number {

    const ALPHA = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    const OPT_MODE = 'tix_order_number_mode';
    const OPT_CODE = 'tix_order_number_code';

    public static function mode() {
        return get_option(self::OPT_MODE, 'legacy') === 'short' ? 'short' : 'legacy';
    }

    /** Kürzel der Seite (2–5 Zeichen). */
    public static function code() {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) get_option(self::OPT_CODE, '')));
        if ($c !== '') return substr($c, 0, 5);
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return 'EV';
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $p = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($s['order_number_prefix'] ?? '')));
        if ($p !== '' && $p !== 'TIX') return substr($p, 0, 5);
        $name = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : get_bloginfo('name');
        $words = preg_split('/\s+/', trim(preg_replace('/[^A-Za-z0-9 ]/', ' ', remove_accents((string) $name))));
        $ini = '';
        foreach ($words as $w) if ($w !== '') $ini .= strtoupper($w[0]);
        return strlen($ini) >= 2 ? substr($ini, 0, 4) : (strlen($p) ? substr($p, 0, 5) : 'TIX');
    }

    /** Prüfzeichen (Luhn mod N) zu einer Zeichenfolge aus ALPHA. */
    public static function check_char($chars) {
        $n = strlen(self::ALPHA);
        $factor = 2;
        $sum = 0;
        for ($i = strlen($chars) - 1; $i >= 0; $i--) {
            $cp = strpos(self::ALPHA, $chars[$i]);
            if ($cp === false) return '';
            $add = $factor * $cp;
            $factor = $factor === 2 ? 1 : 2;
            $add = intdiv($add, $n) + ($add % $n);
            $sum += $add;
        }
        $rem = $sum % $n;
        return self::ALPHA[($n - $rem) % $n];
    }

    /** Stimmt das Prüfzeichen (letztes Zeichen) der 6-stelligen Folge? */
    public static function valid_body($body) {
        $body = strtoupper((string) $body);
        if (!preg_match('/^[' . self::ALPHA . ']{6}$/', $body)) return false;
        return self::check_char(substr($body, 0, 5)) === substr($body, 5, 1);
    }

    public static function make($code = null) {
        $code = $code ?? self::code();
        $body = '';
        for ($i = 0; $i < 5; $i++) $body .= self::ALPHA[random_int(0, strlen(self::ALPHA) - 1)];
        return $code . '-' . $body . self::check_char($body);
    }

    /** Neue eindeutige Nummer (bis zu 8 Versuche gegen den UNIQUE-Index). */
    public static function generate() {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';
        $code = self::code();
        for ($i = 0; $i < 8; $i++) {
            $n = self::make($code);
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE order_number = %s LIMIT 1", $n))) return $n;
        }
        return $code . '-' . strtoupper(wp_generate_password(8, false, false));
    }

    /** Eingabe → Vergleichsform: Großbuchstaben, ohne Leerzeichen, Bindestriche, Raute. */
    public static function normalize($s) {
        return strtoupper(preg_replace('/[\s\-#_.]+/', '', trim((string) $s)));
    }

    /**
     * Sieht die Eingabe wie eine Nummer im neuen Format aus (Kürzel + 6 Zeichen)? Dann [Kürzel, Rumpf, gültig].
     * Sonst null.
     */
    public static function parse($s) {
        $n = self::normalize($s);
        if (!preg_match('/^([A-Z0-9]{2,5}?)([' . self::ALPHA . ']{6})$/', $n, $m)) return null;
        return [$m[1], $m[2], self::valid_body($m[2])];
    }
}
