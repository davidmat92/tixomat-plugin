<?php
/**
 * Einwilligung (Cookies/Tracking) – gemeinsame Prüfung für Server und Browser.
 *
 * Kategorien: `statistics` (Kampagnen-/Seitenaufruf-Statistik, Landing-Zuordnung)
 * und `marketing` (Promoter-Zuordnung über Cookie). Ohne Einwilligung setzt Tixomat
 * dafür keine Cookies und keinen lokalen Speicher.
 *
 * Erkannt werden:
 *   - Cookiebot (Cookie `CookieConsent`: statistics:true / marketing:true)
 *   - CookieConsent v3 (Cookie `cc_cookie`, JSON `categories`; z. B. die Next.js-Website
 *     evendis.de – mit Cookie-Domain `.evendis.de` gilt die Einwilligung auch auf
 *     veranstalter.evendis.de)
 *   - Borlabs Cookie (Cookie `borlabs-cookie`, JSON `consents`)
 *   - die eingestellten Consent-Cookies von Meta Pixel / Google Tag
 *     (`meta_consent_cookie`, `google_consent_cookie`; vorhanden = volle Einwilligung)
 *   - Filter `tix_has_consent` ($granted, $category)
 * Im Browser zusätzlich: Ereignis `tix:consentGranted` (detail.categories),
 * Cookiebot `CookiebotOnAccept`, CookieConsent `cc:onConsent`/`cc:onChange`,
 * Borlabs `borlabs-cookie-consent-saved` (assets/js/consent.js, `window.tixConsent`).
 */
if (!defined('ABSPATH')) exit;

class TIX_Consent {

    /** Bezeichnungen der Banner-Kategorien je Tixomat-Kategorie. */
    const ALIASES = [
        'statistics' => ['statistics', 'statistik', 'analytics', 'analytik', 'performance', 'measurement'],
        'marketing'  => ['marketing', 'ads', 'advertising', 'werbung', 'targeting'],
    ];

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_script'], 0);
        // Tracking-Cookies ohne (bzw. nach entzogener) Einwilligung löschen – auch httpOnly-Cookies
        add_action('init', [__CLASS__, 'cleanup_cookies'], 2);
    }

    /** Kategorie-Bezeichnungen inkl. eigener Banner-Schlüssel (Filter tix_consent_aliases). */
    public static function aliases() {
        $a = (array) apply_filters('tix_consent_aliases', self::ALIASES);
        foreach ($a as $k => $v) $a[$k] = array_values(array_unique(array_map('strtolower', array_map('strval', (array) $v))));
        return $a;
    }

    /** Cookies je Kategorie, die Tixomat ohne Einwilligung nicht stehen lässt. */
    const TRACKING_COOKIES = [
        'statistics' => ['tix_campaign', 'tix_session', 'tix_visitor_fallback', 'tix_ol_src', 'tix_visitor'],
        'marketing'  => ['tix_promoter_ref'],
    ];

    public static function cleanup_cookies() {
        if (headers_sent() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) return;
        foreach (self::TRACKING_COOKIES as $cat => $names) {
            $present = array_filter($names, function ($n) { return isset($_COOKIE[$n]); });
            if (!$present || self::has($cat)) continue;
            foreach ($present as $n) {
                setcookie($n, '', time() - YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN);
                if ((COOKIEPATH ?: '/') !== '/') setcookie($n, '', time() - YEAR_IN_SECONDS, '/', COOKIE_DOMAIN);
                unset($_COOKIE[$n]);
            }
        }
    }

    /** Namen frei eingestellter Consent-Cookies (vorhanden = volle Einwilligung). */
    public static function generic_cookies() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $names = [];
        foreach (['meta_consent_cookie', 'google_consent_cookie'] as $k) {
            $n = trim((string) ($s[$k] ?? ''));
            if ($n !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $n)) $names[] = $n;
        }
        return array_values(array_unique($names));
    }

    /** Liegt eine Einwilligung für die Kategorie vor (statistics|marketing)? */
    public static function has($category) {
        $category = $category === 'marketing' ? 'marketing' : 'statistics';
        return (bool) apply_filters('tix_has_consent', self::detect($category), $category);
    }

    private static function detect($category) {
        $aliases = self::aliases()[$category] ?? [$category];
        // Cookiebot: "{stamp:'…',necessary:true,preferences:true,statistics:true,marketing:false,…}"
        if (!empty($_COOKIE['CookieConsent'])) {
            $v = urldecode((string) $_COOKIE['CookieConsent']);
            $key = $category === 'marketing' ? 'marketing' : 'statistics';
            if (preg_match('/[{,]\s*' . $key . '\s*:\s*true/i', $v)) return true;
        }
        // CookieConsent v3 (orestbida): {"categories":["necessary","analytics",…], …}
        if (!empty($_COOKIE['cc_cookie'])) {
            $j = json_decode(wp_unslash((string) $_COOKIE['cc_cookie']), true);
            if (!is_array($j)) $j = json_decode(urldecode(wp_unslash((string) $_COOKIE['cc_cookie'])), true);
            $cats = is_array($j) ? array_map('strtolower', array_map('strval', (array) ($j['categories'] ?? $j['level'] ?? []))) : [];
            if (array_intersect($aliases, $cats)) return true;
        }
        // Borlabs Cookie: {"consents":{"essential":[…],"statistics":[…],"marketing":[…]}}
        if (!empty($_COOKIE['borlabs-cookie'])) {
            $j = json_decode(urldecode(wp_unslash((string) $_COOKIE['borlabs-cookie'])), true);
            $groups = is_array($j) && is_array($j['consents'] ?? null) ? array_map('strtolower', array_keys($j['consents'])) : [];
            if (array_intersect($aliases, $groups)) return true;
        }
        foreach (self::generic_cookies() as $n) {
            if (isset($_COOKIE[$n]) && $_COOKIE[$n] !== '' && $_COOKIE[$n] !== '0' && strtolower((string) $_COOKIE[$n]) !== 'false') return true;
        }
        return false;
    }

    /** Browser-Gegenstück (window.tixConsent) für Skripte, die Cookies/Speicher nutzen. */
    public static function register_script() {
        wp_register_script('tix-consent', TIXOMAT_URL . 'assets/js/consent.js', [], TIXOMAT_VERSION, true);
        wp_localize_script('tix-consent', 'tixConsentCfg', [
            'aliases' => self::aliases(),
            'cookies' => self::generic_cookies(),
        ]);
    }
}
