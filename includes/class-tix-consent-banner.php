<?php
/**
 * Einwilligungs-Banner für WordPress-Seiten (selbst gehostet, ohne externe Skripte).
 *
 * Kategorien: notwendig (immer), Statistik, Marketing – kein Vorab-Haken, „Nur notwendige“
 * gleichwertig zu „Alle akzeptieren“, Einstellungen später über einen Footer-Link
 * (`[tix_cookie_settings]`, jedes Element mit `data-tix-consent-open` oder `href="#tix-consent"`,
 * optional automatischer Link im Footer).
 *
 * Speichert die Wahl im **Format von CookieConsent v3** (Cookie `cc_cookie`, URL-kodiertes JSON
 * mit `categories`, `revision`, `consentId`, `consentTimestamp`, `lastConsentTimestamp`,
 * `expirationTime`, `services`, `languageCode`, `data`) – dasselbe Cookie wie die Next.js-Website
 * evendis.de. Mit Cookie-Domain `.evendis.de` gilt eine Einwilligung auf evendis.de und
 * veranstalter.evendis.de. Die Kategorie-Schlüssel sind einstellbar (Vorgabe `analytics`,
 * `marketing`) und müssen denen der Next-Website entsprechen.
 *
 * Option `tix_consent_banner` (Vorgabe aus):
 *   enabled (bool), cookie_domain ('' = aktueller Host, z. B. '.evendis.de'),
 *   key_statistics ('analytics'), key_marketing ('marketing'), revision (int, erhöhen = neu fragen),
 *   footer_link (bool, Vorgabe an), title, text (optional eigene Texte), accent (#hex, optional; sonst Primärfarbe)
 * Setzen z. B.: wp option update tix_consent_banner '{"enabled":true,"cookie_domain":".evendis.de"}' --format=json
 *
 * Nach der Wahl feuert `tix:consentGranted` (detail.categories = ['statistics','marketing'] je nach
 * Wahl); TIX_Consent / window.tixConsent werten das Cookie aus.
 */
if (!defined('ABSPATH')) exit;

class TIX_Consent_Banner {

    const OPTION = 'tix_consent_banner';

    public static function settings() {
        $raw = get_option(self::OPTION, []);
        if (is_string($raw)) $raw = json_decode($raw, true);
        $s = wp_parse_args(is_array($raw) ? $raw : [], [
            'enabled'        => false,
            'cookie_domain'  => '',
            'key_statistics' => 'analytics',
            'key_marketing'  => 'marketing',
            'revision'       => 0,
            'footer_link'    => true,
            'title'          => '',
            'text'           => '',
            'accent'         => '',   // eigene Banner-Farbe (1.38.458), sonst Primärfarbe der Seite
        ]);
        $s['enabled']     = !empty($s['enabled']) && $s['enabled'] !== 'false';
        $s['footer_link'] = !empty($s['footer_link']) && $s['footer_link'] !== 'false';
        $s['revision']    = max(0, intval($s['revision']));
        $dom = strtolower(trim((string) $s['cookie_domain']));
        $s['cookie_domain'] = preg_match('/^\.?[a-z0-9.-]+$/', $dom) ? $dom : '';
        foreach (['key_statistics' => 'analytics', 'key_marketing' => 'marketing'] as $k => $def) {
            $v = sanitize_key((string) $s[$k]);
            $s[$k] = $v !== '' ? $v : $def;
        }
        return $s;
    }

    public static function enabled() {
        return self::settings()['enabled'];
    }

    public static function init() {
        add_shortcode('tix_cookie_settings', [__CLASS__, 'shortcode']);
        if (!self::enabled()) return;
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 1);
        add_action('wp_footer', [__CLASS__, 'render'], 5);
        // Banner-Schlüssel auch für die Einwilligungsprüfung (eigene Kategorie-Namen)
        add_filter('tix_consent_aliases', [__CLASS__, 'aliases']);
    }

    public static function aliases($aliases) {
        $s = self::settings();
        $aliases['statistics'][] = $s['key_statistics'];
        $aliases['marketing'][]  = $s['key_marketing'];
        return $aliases;
    }

    /** [tix_cookie_settings text="Cookie-Einstellungen"] – Link, der das Banner öffnet. */
    public static function shortcode($atts = []) {
        $a = shortcode_atts(['text' => 'Cookie-Einstellungen'], $atts);
        if (!self::enabled()) return '';
        return '<a href="#tix-consent" class="tix-cc-open" data-tix-consent-open>' . esc_html($a['text']) . '</a>';
    }

    private static function privacy_url() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $u = trim((string) ($s['privacy_url'] ?? ''));
        if ($u === '') return '';
        return preg_match('#^https?://#i', $u) ? $u : home_url($u);
    }

    private static function imprint_url() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $u = trim((string) ($s['imprint_url'] ?? ($s['impressum_url'] ?? '')));
        if ($u === '') return '';
        return preg_match('#^https?://#i', $u) ? $u : home_url($u);
    }

    public static function enqueue() {
        $s = self::settings();
        if (function_exists('tix_register_local_fonts')) tix_register_local_fonts();
        wp_enqueue_style('tix-consent-banner', TIXOMAT_URL . 'assets/css/consent-banner.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-consent-banner', TIXOMAT_URL . 'assets/js/consent-banner.js', ['tix-consent'], TIXOMAT_VERSION, true);
        wp_localize_script('tix-consent-banner', 'tixConsentBanner', [
            'domain'   => $s['cookie_domain'],
            'revision' => $s['revision'],
            'keys'     => ['statistics' => $s['key_statistics'], 'marketing' => $s['key_marketing']],
            'secure'   => is_ssl(),
            'days'     => 182,
            // Beim Entzug aufräumen (vom Browser lesbare Cookies/Speicher; den Rest löscht der Server)
            'cleanup'  => [
                'statistics' => ['cookies' => ['tix_campaign', 'tix_session', 'tix_visitor_fallback', 'tix_ol_src'], 'storage' => ['tix_visitor']],
                'marketing'  => ['cookies' => ['_fbp', '_fbc', '_gcl_au'], 'storage' => []],
            ],
        ]);
        // Farbe der Seite (Einstellung „Primärfarbe“)
        $tix = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $accent = preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($tix['color_primary'] ?? '')) ? $tix['color_primary'] : '#111827';
        if (preg_match('/^#[0-9a-f]{3,8}$/i', (string) $s['accent'])) $accent = $s['accent'];
        wp_add_inline_style('tix-consent-banner', '.tix-cc{--tix-cc-accent:' . $accent . '}');
    }

    public static function render() {
        $s = self::settings();
        $privacy = self::privacy_url();
        $imprint = self::imprint_url();
        $title = $s['title'] !== '' ? $s['title'] : 'Deine Privatsphäre';
        $text  = $s['text'] !== '' ? $s['text'] : 'Wir verwenden technisch notwendige Cookies für Warenkorb und Anmeldung. Mit deiner Einwilligung nutzen wir zusätzlich Cookies für Statistik (welche Kampagnen Besucher bringen) und Marketing (Zuordnung von Promoter-Links, Werbe-Erfolgsmessung). Du kannst deine Wahl jederzeit über „Cookie-Einstellungen“ ändern.';
        ?>
        <div class="tix-cc" id="tix-consent" role="dialog" aria-modal="false" aria-labelledby="tix-cc-title" aria-describedby="tix-cc-text" hidden>
            <div class="tix-cc__box">
                <p class="tix-cc__title" id="tix-cc-title"><?php echo esc_html($title); ?></p>
                <p class="tix-cc__text" id="tix-cc-text"><?php echo esc_html($text); ?>
                    <?php if ($privacy) : ?><a href="<?php echo esc_url($privacy); ?>">Datenschutz</a><?php endif; ?>
                    <?php if ($imprint) : ?> · <a href="<?php echo esc_url($imprint); ?>">Impressum</a><?php endif; ?>
                </p>
                <div class="tix-cc__prefs" hidden>
                    <label class="tix-cc__row"><input type="checkbox" checked disabled> <span><strong>Notwendig</strong> – Warenkorb, Anmeldung, Sicherheit. Immer aktiv.</span></label>
                    <label class="tix-cc__row"><input type="checkbox" data-cat="statistics"> <span><strong>Statistik</strong> – Herkunft und Seitenaufrufe (Kampagnen-Auswertung).</span></label>
                    <label class="tix-cc__row"><input type="checkbox" data-cat="marketing"> <span><strong>Marketing</strong> – Promoter-Zuordnung, Werbe-Erfolgsmessung (z. B. Meta, Google Ads).</span></label>
                </div>
                <div class="tix-cc__btns">
                    <button type="button" class="tix-cc__btn" data-act="reject">Nur notwendige</button>
                    <button type="button" class="tix-cc__btn" data-act="accept">Alle akzeptieren</button>
                    <button type="button" class="tix-cc__btn tix-cc__btn--link" data-act="prefs">Einstellungen</button>
                    <button type="button" class="tix-cc__btn" data-act="save" hidden>Auswahl speichern</button>
                </div>
            </div>
        </div>
        <?php if ($s['footer_link']) : ?>
        <p class="tix-cc-footer"><a href="#tix-consent" class="tix-cc-open" data-tix-consent-open>Cookie-Einstellungen</a></p>
        <?php endif; ?>
        <?php
    }
}
