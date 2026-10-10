<?php
/**
 * Kunden-Adresse (Option `tix_customer_base_url`, z. B. `https://evendis.de`).
 *
 * Zieht WordPress auf eine eigene Verwaltungs-Adresse um (evendis: `veranstalter.evendis.de`)
 * und läuft die Kundenseite getrennt (Next.js auf `evendis.de`), zeigen alle kundenseitigen
 * Links, die das Plugin erzeugt – Mails an Kunden, URLs in Schnittstellen-Antworten,
 * Wallet-Pässe, Kalender, Rücksprung nach der Zahlung – auf die Kunden-Adresse.
 * Der Weg zurück zu WordPress läuft dort über den Proxy (`/wp-admin/admin-post.php`,
 * `/wp-admin/admin-ajax.php`, `/?tix_payment_return…`, `/kasse/` usw.).
 *
 * Leer (Vorgabe, KitchenKlub/Mallorca/tixomat.de) = keine Änderung.
 * Nie umgeschrieben: `/wp-json/…` (Schnittstelle), `/wp-admin/…` außer `admin-post.php`
 * und `admin-ajax.php`, `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php` sowie alle Adressen
 * fremder Hosts. Webhook-/Notify-Adressen der Zahlungsanbieter laufen nicht hier durch.
 * Ebenfalls auf WordPress bleiben Links mit `tix_`-Parametern, die WordPress verarbeitet
 * (`/?tix_dl=…`, `/?tix_thankyou=…`, `/events/x/?tix_feedback=…` usw.; Ausnahme
 * `tix_sp_ticket` = Universal Link, siehe stays()).
 *
 * Setzen: `wp option update tix_customer_base_url https://evendis.de`
 */
if (!defined('ABSPATH')) exit;

class TIX_Customer_URL {

    const OPTION = 'tix_customer_base_url';

    public static function init() {
        // Neue Kunden-Adresse sofort in den Schnittstellen-Antworten (Event-Cache leeren)
        foreach (['add_option_' . self::OPTION, 'update_option_' . self::OPTION, 'delete_option_' . self::OPTION] as $hook) {
            add_action($hook, [__CLASS__, 'flush_caches']);
        }
    }

    public static function flush_caches() {
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        wp_cache_flush();
    }

    /** Geprüfte Kunden-Adresse ohne Schrägstrich am Ende ('' = aus). */
    public static function base() {
        $raw = trim((string) get_option(self::OPTION, ''));
        $out = '';
        if ($raw !== '') {
            $p = wp_parse_url($raw);
            $scheme = strtolower((string) ($p['scheme'] ?? ''));
            $host   = strtolower((string) ($p['host'] ?? ''));
            if (in_array($scheme, ['https', 'http'], true) && $host !== '' && empty($p['query']) && empty($p['user'])) {
                $out = $scheme . '://' . $host . (!empty($p['port']) ? ':' . intval($p['port']) : '') . rtrim((string) ($p['path'] ?? ''), '/');
                // Gleich der WordPress-Adresse → nichts zu tun
                if (strcasecmp($out, untrailingslashit(home_url())) === 0) $out = '';
            }
        }
        return (string) apply_filters('tix_customer_base_url', $out);
    }

    public static function active() {
        return self::base() !== '';
    }

    /** Host der Kunden-Adresse ('' = aus) – für erlaubte Weiterleitungen. */
    public static function host() {
        $b = self::base();
        return $b === '' ? '' : strtolower((string) wp_parse_url($b, PHP_URL_HOST));
    }

    /** WordPress-Adresse(n), deren Links umgeschrieben werden (http und https). */
    private static function home_prefixes() {
        $home = untrailingslashit(home_url());
        $alt  = strpos($home, 'https://') === 0 ? 'http://' . substr($home, 8) : 'https://' . substr($home, 7);
        return [$home, $alt];
    }

    /** Bleibt dieser Pfad (relativ zur WordPress-Adresse) auf WordPress? */
    private static function stays($rel) {
        $path = (string) wp_parse_url('https://x' . ($rel === '' ? '/' : $rel), PHP_URL_PATH);
        $path = '/' . ltrim($path, '/');

        if (preg_match('#^/(wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-signup\.php|wp-activate\.php)(/|$)#i', $path)) return true;
        if (stripos($path, '/wp-admin') === 0) {
            return !preg_match('#^/wp-admin/(admin-post|admin-ajax)\.php$#i', $path);
        }
        // Abfragen der Schnittstelle ohne schöne Adressen
        $q = (string) wp_parse_url('https://x' . $rel, PHP_URL_QUERY);
        if ($q !== '' && preg_match('/(^|&|&amp;)rest_route=/', $q)) return true;
        // Von WordPress verarbeitete Links mit tix_-Parametern (/?tix_dl=, tix_bundle, tix_view,
        // tix_thankyou, tix_guest, tix_activate, tix_unsub, tix_mt_token, tix_payment_*, aber auch
        // /events/x/?tix_feedback= oder ?tix_recover_cart=): WordPress rendert bzw. verarbeitet
        // sie, ihre AJAX-Aufrufe gehen an die WordPress-Adresse. Ausnahme: der Support-Link
        // ?tix_sp_ticket= (Universal Link der App, muss auf der Kunden-Domain liegen).
        if ($q !== '' && preg_match_all('/(?:^|&|&amp;)(tix_[a-z0-9_]+)=/i', $q, $m)) {
            foreach ($m[1] as $key) {
                if (strtolower($key) !== 'tix_sp_ticket') return true;
            }
        }
        return false;
    }

    /** Eine Adresse der WordPress-Seite auf die Kunden-Adresse umschreiben (sonst unverändert). */
    public static function to($url) {
        $url = (string) $url;
        $base = self::base();
        if ($base === '' || $url === '') return $url;
        foreach (self::home_prefixes() as $home) {
            $len = strlen($home);
            if (strncasecmp($url, $home, $len) !== 0) continue;
            $rest = substr($url, $len);
            // nur ganze Host-Grenze (nicht evendis.de.example.com)
            if ($rest !== '' && !in_array($rest[0], ['/', '?', '#'], true)) continue;
            if (self::stays($rest)) return $url;
            return $base . $rest;
        }
        return $url;
    }

    /** Alle Links der WordPress-Adresse in einem Text/HTML umschreiben (Kunden-Mails). */
    public static function in_text($text) {
        $text = (string) $text;
        if (!self::active() || $text === '') return $text;
        $hosts = [];
        foreach (self::home_prefixes() as $h) $hosts[] = preg_quote($h, '#');
        $re = '#(?:' . implode('|', array_unique($hosts)) . ')(?=[/?\#"\'\s<>)]|$)[^\s"\'<>)]*#i';
        return (string) preg_replace_callback($re, function ($m) {
            // HTML-kodierte Und-Zeichen bleiben erhalten (to() arbeitet mit dem Rohtext)
            return self::to($m[0]);
        }, $text);
    }

    /** Ein Array (Payload) rekursiv: nur die genannten Schlüssel umschreiben. */
    public static function in_fields($data, array $keys) {
        if (!self::active() || !is_array($data)) return $data;
        foreach ($data as $k => $v) {
            if (is_array($v)) $data[$k] = self::in_fields($v, $keys);
            elseif (is_string($v) && in_array((string) $k, $keys, true)) $data[$k] = self::to($v);
        }
        return $data;
    }

    /** wp_mail für Mails an Kunden: Links im Text zeigen auf die Kunden-Adresse. */
    public static function mail($to, $subject, $message, $headers = '', $attachments = []) {
        return wp_mail($to, $subject, self::in_text($message), $headers, $attachments);
    }
}
