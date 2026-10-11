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
 *
 * Durchleitung (seit 1.38.384): Liefert die Kundenseite Seiten von WordPress per Reverse Proxy
 * aus (Host = Verwaltung, `X-Forwarded-Host` = Kunden-Host, Absender in `tix_trusted_proxies`),
 * gibt sich WordPress für diese Anfrage vollständig als Kunden-Adresse aus (boot_proxied()):
 * HTTP_HOST, home_url/site_url/content_url/plugins_url/includes_url/rest_url, admin-ajax/
 * admin-post, Weiterleitungen und fest gespeicherte Adressen im HTML. Nur wp-admin bleibt
 * auf der Verwaltung. So landen Kunden nie auf der Verwaltungs-Adresse.
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
                if (strcasecmp($out, self::wp_home()) === 0) $out = '';
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

    /** WordPress-Adresse ohne Filter (home_url() ist in der Durchleitung selbst umgeschrieben). */
    private static function wp_home() {
        static $h = null;
        if ($h === null) $h = untrailingslashit((string) get_option('home'));
        return $h;
    }

    /** WordPress-Adresse(n), deren Links umgeschrieben werden (http und https). */
    private static function home_prefixes() {
        $home = self::wp_home();
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
        // Links mit tix_-Parametern (/?tix_dl=, tix_thankyou, /events/x/?tix_feedback= …) gehen seit
        // 1.38.384 ebenfalls auf die Kunden-Adresse: Kunden landen nie auf der Verwaltung. Die
        // Kundenseite leitet solche Anfragen per Durchleitung an WordPress (X-Forwarded-Host),
        // WordPress rendert sie dann als Kunden-Adresse (siehe boot_proxied()).
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

    // ── Durchleitung (Reverse Proxy der Kundenseite) ──

    private static $proxied = null;

    /** Kommt die Anfrage über die Durchleitung der Kundenseite (vertrauter Proxy + X-Forwarded-Host)? */
    public static function is_proxied() {
        if (self::$proxied !== null) return self::$proxied;
        self::$proxied = false;
        if ((defined('WP_CLI') && WP_CLI) || empty($_SERVER['HTTP_X_FORWARDED_HOST'])) return false;
        $host = self::host();
        if ($host === '') return false;
        $xfh = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'])[0]));
        $xfh = preg_replace('/:\d+$/', '', $xfh);
        if ($xfh !== $host) return false;
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if (!class_exists('TIX_Client_IP') || !TIX_Client_IP::in_list($remote, TIX_Client_IP::trusted_proxies())) return false;
        return self::$proxied = true;
    }

    /** Früh beim Laden des Plugins: durchgeleitete Anfragen als Kunden-Adresse ausliefern. */
    public static function boot_proxied() {
        if (!self::is_proxied()) return;
        $base = self::base();
        $p = wp_parse_url($base);
        $_SERVER['HTTP_HOST'] = strtolower((string) $p['host']) . (!empty($p['port']) ? ':' . intval($p['port']) : '');
        $_SERVER['SERVER_NAME'] = strtolower((string) $p['host']);
        if (strtolower((string) ($p['scheme'] ?? '')) === 'https') { $_SERVER['HTTPS'] = 'on'; $_SERVER['SERVER_PORT'] = !empty($p['port']) ? intval($p['port']) : 443; }
        foreach (['home_url', 'site_url', 'content_url', 'plugins_url', 'includes_url', 'rest_url', 'wp_redirect'] as $f) {
            add_filter($f, [__CLASS__, 'proxied_url'], 99);
        }
        add_filter('admin_url', [__CLASS__, 'proxied_admin_url'], 99);
        add_filter('allowed_redirect_hosts', function ($hosts) { $hosts[] = TIX_Customer_URL::host(); return array_values(array_unique($hosts)); });
        // Fest gespeicherte Adressen (Breakdance, Menüs, Inhalte) in Seiten der Durchleitung ersetzen
        add_action('template_redirect', [__CLASS__, 'start_html_rewrite'], -1000);
        add_action('login_init', [__CLASS__, 'start_html_rewrite'], -1000);
    }

    /** WordPress-Adresse → Kunden-Adresse, außer wp-admin (ohne admin-ajax/admin-post). */
    public static function proxied_url($url) {
        $url = (string) $url;
        $base = self::base();
        foreach (self::home_prefixes() as $home) {
            $len = strlen($home);
            if (strncasecmp($url, $home, $len) !== 0) continue;
            $rest = substr($url, $len);
            if ($rest !== '' && !in_array($rest[0], ['/', '?', '#'], true)) continue;
            $path = (string) wp_parse_url('https://x' . ($rest === '' ? '/' : $rest), PHP_URL_PATH);
            if (stripos($path, '/wp-admin') === 0 && !preg_match('#^/wp-admin/(admin-post|admin-ajax)\.php$#i', $path)) return $url;
            return $base . $rest;
        }
        return $url;
    }

    public static function proxied_admin_url($url) {
        return preg_match('#/wp-admin/(admin-post|admin-ajax)\.php#i', (string) $url) ? self::proxied_url($url) : $url;
    }

    public static function start_html_rewrite() {
        static $started = false;
        if ($started || is_admin() || wp_doing_ajax()) return;
        $started = true;
        ob_start([__CLASS__, 'rewrite_html']);
    }

    /** Alle Adressen der Verwaltung im HTML (auch JSON-maskiert) → Kunden-Adresse, außer wp-admin. */
    public static function rewrite_html($html) {
        if (!is_string($html) || $html === '') return $html;
        $base = self::base();
        foreach (self::home_prefixes() as $home) {
            foreach ([false, true] as $json) {
                $from = $json ? str_replace('/', '\/', $home) : $home;
                $to   = $json ? str_replace('/', '\/', $base) : $base;
                // Host-Grenze: danach kein weiteres Host-Zeichen (nicht veranstalter.evendis.de.example.com)
                $re = '~' . preg_quote($from, '~') . '(?![A-Za-z0-9.-])([^\s"\'<>)]*)~i';
                $html = preg_replace_callback($re, function ($m) use ($to, $json) {
                    $rest  = $m[1];
                    $plain = $json ? str_replace('\/', '/', $rest) : $rest;
                    $path  = (string) wp_parse_url('https://x' . ($plain === '' || $plain[0] !== '/' ? '/' . ltrim($plain, '/') : $plain), PHP_URL_PATH);
                    if (stripos($path, '/wp-admin') === 0 && !preg_match('~^/wp-admin/(admin-post|admin-ajax)\.php~i', $path)) return $m[0];
                    return $to . $rest;
                }, $html);
            }
        }
        return $html;
    }
}
