<?php
if (!defined('ABSPATH')) exit;

/**
 * Verkäufer-Angaben für vermittelte Verkäufe (1.38.717, Rechtstexte `pruefmappe/co-geldempfaenger.md` §1/§5):
 * Vor der Bestellung muss der Verkäufer erkennbar sein (Art. 246a § 1 EGBGB) – Name, Anschrift, Impressum, Datenschutz, AGB.
 * Ein Host wie „kitchenklub.de“ reicht nicht.
 *
 * Form (überall gleich, leere Angaben = null):
 *   {name, address, imprint_url, privacy_url, terms_url, revocation_url, host, complete}
 * `complete` = Name, Anschrift, Impressum und Datenschutz vorhanden.
 */
class TIX_Co_Legal {
    const M_SOURCE = '_tix_source_seller';   // Spiegel: Verkäufer-Angaben der Quelle (aus der Event-Verteilung)
    const M_SITE   = '_tix_sale_seller_info'; // Kunden-Seite: Verkäufer des vermittelten Events (vom Abgleich)
    const KEYS     = ['name', 'address', 'imprint_url', 'privacy_url', 'terms_url', 'revocation_url', 'host'];

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function dec($s) {
        return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function host_of($url) {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) $url, PHP_URL_HOST)));
    }

    /** Angaben vereinheitlichen (auch Daten anderer Seiten). */
    public static function clean($in) {
        $in = is_array($in) ? $in : [];
        $out = [];
        foreach (self::KEYS as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') { $out[$k] = null; continue; }
            if (substr($k, -4) === '_url') $v = esc_url_raw($v, ['https', 'http']);
            elseif ($k === 'address') $v = mb_substr(sanitize_textarea_field($v), 0, 300);
            else $v = mb_substr(sanitize_text_field($v), 0, 190);
            $out[$k] = $v !== '' ? $v : null;
        }
        $out['complete'] = $out['name'] && $out['address'] && $out['imprint_url'] && $out['privacy_url'];
        return $out;
    }

    /** Diese Seite als Verkäufer (Lizenz- bzw. Kunden-Seite): Studio → Marke → Kontakt/Rechtstexte, sonst Einstellungen. */
    public static function site() {
        $c = class_exists('TIX_Site_Config') ? (array) TIX_Site_Config::contact() : [];
        $lg = class_exists('TIX_Site_Config') ? (array) (TIX_Site_Config::payload()['legal'] ?? []) : [];
        $s = (array) get_option('tix_settings', []);
        $name = (string) ($c['company'] ?? '') ?: (string) (tix_get_settings('syndication_site_name') ?: get_bloginfo('name'));
        return self::clean([
            'name' => self::dec($name),
            'address' => (string) ($c['address'] ?? ''),
            'imprint_url' => (string) ($lg['imprint'] ?? '') ?: (string) ($s['imprint_url'] ?? ($s['impressum_url'] ?? '')),
            'privacy_url' => (string) ($lg['privacy'] ?? '') ?: (string) ($s['privacy_url'] ?? ''),
            'terms_url' => (string) ($lg['terms'] ?? '') ?: (string) ($s['terms_url'] ?? ''),
            'revocation_url' => (string) ($s['revocation_url'] ?? ''),
            'host' => self::host_of(class_exists('TIX_Customer_URL') && TIX_Customer_URL::host() !== '' ? 'https://' . TIX_Customer_URL::host() : home_url()),
        ]);
    }

    /**
     * Plattform-Veranstalter als Verkäufer (evendis.de vermittelt): Name/Anschrift aus den Auszahlungsdaten, Impressum der
     * Veranstalter-Seite. AGB/Datenschutz: die der Plattform (evendis vermittelt nach deren AGB, der Veranstalter ist dort genannt).
     */
    public static function org($oid) {
        $oid = intval($oid);
        if (!$oid || get_post_type($oid) !== 'tix_organizer') return self::clean([]);
        if (class_exists('TIX_Co_Sites') && TIX_Co_Sites::is_site_profile($oid)) {
            $m = get_post_meta($oid, '_tix_site_seller', true); // von der Kunden-Seite gemeldet
            if (is_array($m) && !empty($m['name'])) return self::clean($m);
        }
        $pd = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::data($oid) : ['billing' => []];
        $b = (array) ($pd['billing'] ?? []);
        $name = trim((string) ($b['company'] ?? '')) ?: trim((string) ($b['name'] ?? '')) ?: self::dec(get_the_title($oid));
        $line2 = trim(trim((string) ($b['zip'] ?? '')) . ' ' . trim((string) ($b['city'] ?? '')));
        $addr = implode(', ', array_filter([trim((string) ($b['street'] ?? '')), $line2, ($b['country'] ?? 'DE') !== 'DE' ? (string) $b['country'] : '']));
        $imprint = (string) get_post_meta($oid, '_tix_org_imprint_url', true);
        if ($imprint === '' && trim((string) get_post_meta($oid, '_tix_org_imprint', true)) !== '' && class_exists('TIX_Organizer_Landing')) {
            $slug = class_exists('TIX_Public_Platform') ? (string) (TIX_Public_Platform::organizer_ref($oid)['slug'] ?? '') : '';
            if ($slug !== '') $imprint = TIX_Organizer_Landing::public_page_url($slug) . '#impressum';
        }
        $plat = self::site();
        return self::clean(['name' => $name, 'address' => $addr, 'imprint_url' => $imprint, 'privacy_url' => $plat['privacy_url'], 'terms_url' => $plat['terms_url'],
            'revocation_url' => $plat['revocation_url'], 'host' => $plat['host']]);
    }

    /** Wer verkauft dieses Event (aus Sicht dieser Seite)? → Angaben | null (unbekannt). */
    public static function for_event($eid) {
        $eid = intval($eid);
        if (self::multi()) {
            $syn = get_post_meta($eid, '_tix_syndicated', true) === '1';
            $owner = class_exists('TIX_Co_Money') ? TIX_Co_Money::owner_id($eid) : 0;
            if ($owner && class_exists('TIX_Co_Seller') && (!$syn || TIX_Co_Seller::sells_here($eid))) return self::org($owner);
            if ($syn) { $m = get_post_meta($eid, self::M_SOURCE, true); return is_array($m) && !empty($m['name']) ? self::clean($m) : null; }
            return self::org(intval(get_post_meta($eid, '_tix_organizer_id', true)));
        }
        if (class_exists('TIX_Co_Sale') && TIX_Co_Sale::is_sale_mirror($eid)) { $m = get_post_meta($eid, self::M_SITE, true); return is_array($m) && !empty($m['name']) ? self::clean($m) : null; }
        return self::site();
    }

    /** Für `syndicated`/`seller`: Angaben ergänzen, ohne vorhandene Felder zu überschreiben. */
    public static function merge(array $a, $eid) {
        $s = self::for_event($eid);
        if (!$s) return $a + ['seller_info' => null];
        if (empty($a['terms_url']) && $s['terms_url']) $a['terms_url'] = $s['terms_url'];
        return $a + ['seller_info' => $s, 'address' => $s['address'], 'imprint_url' => $s['imprint_url'], 'privacy_url' => $s['privacy_url']];
    }
}
