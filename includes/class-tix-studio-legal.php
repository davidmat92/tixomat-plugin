<?php
/**
 * Rechtstexte im Studio (1.38.578): Impressum, Datenschutz, AGB, Widerruf bearbeiten statt im WordPress-Admin.
 *
 * Speicherort bleiben die WordPress-Seiten (wp_posts) – Website (marken-web `[seite]`) und Apps lesen sie unverändert.
 * Versionen: eigene Liste je Seite (Meta `_tix_legal_versions`, höchstens 20, neueste zuerst), unabhängig davon, ob
 * WordPress-Revisionen auf der Seite an sind. Pflichtangaben-Prüfung serverseitig, damit Studio und App gleich prüfen.
 *
 *   GET  /studio/legal                 → {items: [{key, title, page_id, slug, url, status, modified, modified_by, chars, checks, missing}]}
 *   GET  /studio/legal/{key}           → Eintrag + {html, versions: [{id, at, by, title, chars}]}
 *   POST /studio/legal/{key}           {html, title?} speichern · {restore: <version-id>} wiederherstellen
 *   GET  /studio/legal/{key}/versions/{id} → {id, at, by, title, html}
 *
 * Rechte wie Marke (TIX_Studio::perm_brand): Admin; auf Einzel-Seiten auch Inhaber (tix_organizer). Auf der Plattform
 * (evendis, Mehr-Veranstalter) nur der Plattform-Admin – die Texte gelten für die ganze Plattform.
 * Keine Rechtsberatung: die Prüfung sucht nur nach typischen Angaben.
 */
if (!defined('ABSPATH')) exit;

class TIX_Studio_Legal {

    const NS = 'tixomat/v1';
    const META_VERSIONS = '_tix_legal_versions';
    const META_BY = '_tix_legal_updated_by';
    const MAX_VERSIONS = 20;
    const MAX_HTML = 400000;

    /** key => [Titel, Slugs (erster = neu anlegen), site-config legal-Schlüssel] */
    const DOCS = [
        'impressum'   => ['Impressum', ['impressum', 'imprint'], 'imprint'],
        'datenschutz' => ['Datenschutzerklärung', ['datenschutz', 'datenschutzerklaerung', 'privacy-policy'], 'privacy'],
        'agb'         => ['AGB', ['agb', 'terms', 'nutzungsbedingungen'], 'terms'],
        'widerruf'    => ['Widerrufsbelehrung', ['widerruf', 'widerrufsbelehrung', 'widerrufsrecht'], null],
    ];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $perm = [__CLASS__, 'perm'];
        $key = '(?P<key>impressum|datenschutz|agb|widerruf)';
        register_rest_route(self::NS, '/studio/legal', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/studio/legal/' . $key, [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/studio/legal/' . $key . '/versions/(?P<vid>[a-z0-9]{6,20})', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_version'], 'permission_callback' => $perm,
        ]);
    }

    /**
     * Rechte wie Marke (perm_brand). Plattform (Mehr-Veranstalter, User-Entscheid 2026-10-29): nur der Plattform-Admin –
     * Veranstalter pflegen ihr Impressum auf der Veranstalterseite (/organizer/profile `imprint`).
     */
    public static function perm(WP_REST_Request $req) {
        if (is_user_logged_in() && !current_user_can('manage_options') && class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            return new WP_Error('rest_forbidden', 'Die Rechtstexte der Plattform pflegt der Plattform-Betreiber. Dein Impressum trägst du auf deiner Veranstalterseite ein.', ['status' => 403]);
        }
        return TIX_Studio::perm_brand($req);
    }

    // ═══════════════ Seiten finden ═══════════════

    private static $legal = null;

    /** Seite zum Schlüssel: Link aus site-config, dann die bekannten Slugs; auch Entwürfe (zum Bearbeiten). */
    public static function page($key) {
        $d = self::DOCS[$key] ?? null;
        if (!$d) return null;
        $slugs = $d[1];
        if ($d[2] && class_exists('TIX_Site_Config')) {
            if (self::$legal === null) self::$legal = (array) (TIX_Site_Config::payload()['legal'] ?? []);
            $legal = self::$legal;
            $sl = (string) ($legal[$d[2] . '_slug'] ?? '');
            if ($sl !== '') array_unshift($slugs, $sl);
        }
        foreach (array_unique($slugs) as $sl) {
            $p = get_page_by_path($sl, OBJECT, 'page');
            if ($p && in_array($p->post_status, ['publish', 'draft', 'private'], true)) return $p;
        }
        return null;
    }

    private static function user_name($uid) {
        $u = $uid ? get_userdata($uid) : null;
        return $u ? (string) $u->display_name : '';
    }

    private static function item($key, $with_html = false) {
        $d = self::DOCS[$key];
        $p = self::page($key);
        $html = $p ? (string) $p->post_content : '';
        $checks = self::checks($key, $html, (bool) $p);
        $out = [
            'key' => $key,
            'title' => $p ? html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8') : $d[0],
            'default_title' => $d[0],
            'page_id' => $p ? intval($p->ID) : null,
            'slug' => $p ? (string) $p->post_name : $d[1][0],
            'url' => $p && $p->post_status === 'publish' ? (class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to(get_permalink($p)) : get_permalink($p)) : null,
            'status' => $p ? ($p->post_status === 'publish' ? 'publish' : 'draft') : 'missing',
            'modified' => $p ? mysql_to_rfc3339($p->post_modified_gmt ?: $p->post_modified) : null,
            'modified_by' => $p ? self::user_name(intval(get_post_meta($p->ID, self::META_BY, true) ?: get_post_meta($p->ID, '_edit_last', true))) : '',
            'chars' => mb_strlen(trim(wp_strip_all_tags($html))),
            'checks' => $checks,
            'missing' => count(array_filter($checks, function ($c) { return !$c['ok'] && $c['level'] !== 'tip'; })),
        ];
        if ($with_html) {
            $out['html'] = $html;
            $out['versions'] = array_map(function ($v) {
                return ['id' => $v['id'], 'at' => wp_date('c', intval($v['at'])), 'by' => (string) ($v['by'] ?? ''), 'title' => (string) ($v['title'] ?? ''), 'chars' => mb_strlen(trim(wp_strip_all_tags((string) $v['html'])))];
            }, $p ? self::versions($p->ID) : []);
        }
        return $out;
    }

    private static function versions($pid) {
        $v = get_post_meta($pid, self::META_VERSIONS, true);
        return is_array($v) ? array_values(array_filter($v, function ($x) { return is_array($x) && isset($x['id'], $x['html']); })) : [];
    }

    // ═══════════════ Pflichtangaben ═══════════════

    private static function feature_on($k) {
        if ($k === 'newsletter') return function_exists('tix_get_settings') && !empty(tix_get_settings('brevo_enabled'));
        if ($k === 'awareness') return class_exists('TIX_Features') && (TIX_Features::on('awareness_report') || TIX_Features::on('awareness_alarm'));
        return class_exists('TIX_Features') && TIX_Features::on($k);
    }

    /**
     * Grobe Prüfung auf typische Angaben (keine Rechtsberatung). level: must = Pflichtangabe fehlt, module = Absatz zu
     * einer eingeschalteten Funktion fehlt, tip = Empfehlung. ok = gefunden bzw. erfüllt.
     */
    public static function checks($key, $html, $exists = true) {
        if (!$exists) return [['key' => 'page', 'label' => 'Seite fehlt', 'ok' => false, 'level' => 'must', 'hint' => 'Leg den Text an – Website und App verlinken ihn.']];
        $t = mb_strtolower(html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8'));
        if (trim($t) === '') return [['key' => 'content', 'label' => 'Text ist leer', 'ok' => false, 'level' => 'must', 'hint' => 'Setz eine Vorlage ein oder schreib den Text.']];
        $has = function ($re) use ($t, $html) { return (bool) preg_match($re . 'u', $t) || (bool) preg_match($re . 'u', mb_strtolower((string) $html)); };
        $mail = $has('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/');
        $addr = $has('/\b\d{5}\b/') && $has('/(str\.|straße|strasse|weg\b|platz|allee|gasse|ring\b|damm|ufer|aue\b|\s\d{1,4}\s?[a-z]?(,|\s|$))/');
        $c = [];
        $add = function ($k, $label, $ok, $level = 'must', $hint = '') use (&$c) { $c[] = ['key' => $k, 'label' => $label, 'ok' => (bool) $ok, 'level' => $level, 'hint' => $hint]; };
        switch ($key) {
            case 'impressum':
                $corp = $has('/\b(gmbh|ug\b|ug \(|ag\b|kg\b|ohg|e\. ?v\.|eg\b|kgaa|se\b)/');
                $add('name', 'Name bzw. Firma', $has('/(gmbh|ug\b|ag\b|kg\b|ohg|gbr|e\. ?k\.|e\. ?v\.|inhaber|angaben gemäß|anbieter)/'), 'must', 'Vollständiger Name bzw. Firma mit Rechtsform.');
                $add('address', 'Anschrift (Straße, PLZ, Ort)', $addr, 'must', 'Eine ladungsfähige Anschrift – kein Postfach.');
                $add('email', 'E-Mail-Adresse', $mail, 'must', '');
                $add('phone', 'Telefon oder anderer schneller Kontakt', $has('/(telefon|tel\.|\+49|whatsapp|\b0\d{2,5}[\s\/-]?\d{3,})/'), 'tip', 'Empfohlen neben der E-Mail.');
                if ($corp) {
                    $add('register', 'Registereintrag (Registergericht, Nummer)', $has('/(hrb|hra|vr ?\d|handelsregister|registergericht|vereinsregister|genossenschaftsregister)/'), 'must', 'Bei GmbH, UG, AG, KG, e. V.');
                    $add('rep', 'Vertretungsberechtigte Person', $has('/(geschäftsführ|vertreten durch|vertretungsberechtigt|vorstand|inhaber)/'), 'must', 'Zum Beispiel „Geschäftsführer: …“.');
                }
                $add('vat', 'USt-IdNr. (falls vorhanden)', $has('/(ust|umsatzsteuer|steuernummer)/'), 'tip', '');
                $add('odr', 'Kein Link mehr auf die EU-OS-Plattform', !$has('/ec\.europa\.eu\/consumers\/odr/'), 'tip', 'Die OS-Plattform der EU wurde am 20.07.2025 eingestellt – den Hinweis entfernen.');
                break;
            case 'datenschutz':
                $add('controller', 'Verantwortliche Stelle mit Anschrift und E-Mail', $addr && $mail && $has('/verantwortlich/'), 'must', '');
                $add('rights', 'Rechte (Auskunft, Widerspruch, Beschwerde)', $has('/auskunft/') && $has('/(widerspruch|beschwerde)/'), 'must', '');
                $add('storage', 'Speicherdauer bzw. Löschung', $has('/(speicherdauer|gelöscht|löschung|aufbewahr)/'), 'must', '');
                $add('legal_basis', 'Rechtsgrundlagen (Art. 6 DSGVO)', $has('/(art\.? ?6|artikel 6)/'), 'must', '');
                $mods = [
                    'assistant' => ['KI-Assistent', '/(ki-assistent|künstliche intelligenz|anthropic)/'],
                    'newsletter' => ['Newsletter', '/newsletter/'],
                    'degaso' => ['Degaso-Kasse', '/degaso/'],
                    'awareness' => ['Awareness-Meldungen', '/awareness/'],
                    'wallet' => ['Guthaben', '/guthaben/'],
                ];
                foreach ($mods as $mk => $m) if (self::feature_on($mk)) $add('mod_' . $mk, 'Absatz „' . $m[0] . '“', $has($m[1]), 'module', $m[0] . ' ist eingeschaltet – der Absatz dazu fehlt.');
                break;
            case 'agb':
                $add('scope', 'Geltungsbereich bzw. Vertragsschluss', $has('/(geltungsbereich|vertragsschluss|vertragspartner|zustandekommen)/'), 'must', '');
                $add('prices', 'Preise und Zahlung', $has('/(preis|zahlung)/'), 'must', '');
                if (self::feature_on('wallet')) $add('mod_wallet', 'Absatz „Guthaben“', $has('/guthaben/'), 'module', 'Guthaben ist eingeschaltet – der Absatz dazu fehlt.');
                if (self::feature_on('giftcards')) $add('mod_giftcards', 'Absatz „Gutscheine“', $has('/gutschein/'), 'module', 'Geschenkgutscheine sind eingeschaltet – der Absatz dazu fehlt.');
                break;
            case 'widerruf':
                $add('leisure', 'Hinweis: kein Widerruf bei Tickets für Freizeit-Events', $has('/(freizeit|312 ?g)/'), 'must', '§ 312g Abs. 2 Nr. 9 BGB – sonst gilt das Widerrufsrecht auch für Tickets.');
                $add('form', 'Muster-Widerrufsformular', $has('/widerrufsformular/'), 'tip', 'Nötig, wenn du auch Waren oder Gutscheine verkaufst.');
                break;
        }
        return $c;
    }

    // ═══════════════ REST ═══════════════

    public static function rest_list(WP_REST_Request $req) {
        $r = new WP_REST_Response(['ok' => true, 'items' => array_map(function ($k) { return self::item($k); }, array_keys(self::DOCS)), 'scope' => class_exists('TIX_App_Scope') && TIX_App_Scope::multi() ? 'platform' : 'site']);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_get(WP_REST_Request $req) {
        $r = new WP_REST_Response(['ok' => true] + self::item((string) $req['key'], true));
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_version(WP_REST_Request $req) {
        $p = self::page((string) $req['key']);
        foreach ($p ? self::versions($p->ID) : [] as $v) {
            if ($v['id'] === (string) $req['vid']) return rest_ensure_response(['ok' => true, 'id' => $v['id'], 'at' => wp_date('c', intval($v['at'])), 'by' => (string) ($v['by'] ?? ''), 'title' => (string) ($v['title'] ?? ''), 'html' => (string) $v['html']]);
        }
        return new WP_Error('tix_not_found', 'Diese Version gibt es nicht mehr.', ['status' => 404]);
    }

    public static function rest_save(WP_REST_Request $req) {
        $key = (string) $req['key'];
        $d = self::DOCS[$key];
        $p = self::page($key);
        $title = $req->has_param('title') ? trim(sanitize_text_field((string) $req->get_param('title'))) : '';
        if ($req->has_param('restore')) {
            $vid = (string) $req->get_param('restore');
            $found = null;
            foreach ($p ? self::versions($p->ID) : [] as $v) if ($v['id'] === $vid) { $found = $v; break; }
            if (!$found) return new WP_Error('tix_not_found', 'Diese Version gibt es nicht mehr.', ['status' => 404]);
            $html = (string) $found['html'];
            if ($title === '') $title = (string) ($found['title'] ?? '');
        } else {
            if (!$req->has_param('html')) return new WP_Error('rest_invalid_param', 'Kein Text übergeben.', ['status' => 400, 'field' => 'html']);
            $html = (string) $req->get_param('html');
            if (strlen($html) > self::MAX_HTML) return new WP_Error('rest_invalid_param', 'Der Text ist zu lang.', ['status' => 413, 'field' => 'html']);
            $html = wp_kses_post($html);
        }
        $title = mb_substr($title !== '' ? $title : ($p ? get_the_title($p) : $d[0]), 0, 120);
        $uid = get_current_user_id();

        if ($p) {
            // Vorherigen Stand als Version sichern (nur wenn sich etwas ändert)
            if ((string) $p->post_content === $html && get_the_title($p) === $title) return self::rest_get($req);
            $vers = self::versions($p->ID);
            array_unshift($vers, ['id' => strtolower(wp_generate_password(10, false, false)), 'at' => strtotime($p->post_modified_gmt . ' UTC') ?: time(),
                'by' => self::user_name(intval(get_post_meta($p->ID, self::META_BY, true) ?: get_post_meta($p->ID, '_edit_last', true))),
                'title' => html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8'), 'html' => (string) $p->post_content]);
            update_post_meta($p->ID, self::META_VERSIONS, array_slice($vers, 0, self::MAX_VERSIONS));
            // kses nicht doppelt anwenden (Inhalt ist schon geprüft); Breakdance/Gutenberg-Blöcke bleiben, was sie sind
            $res = wp_update_post(['ID' => $p->ID, 'post_content' => $html, 'post_title' => $title, 'post_status' => 'publish'], true);
            if (is_wp_error($res)) return new WP_Error('tix_save_failed', 'Speichern hat nicht geklappt.', ['status' => 500]);
            $pid = $p->ID;
        } else {
            $pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $d[1][0], 'post_content' => $html, 'post_author' => $uid], true);
            if (is_wp_error($pid)) return new WP_Error('tix_save_failed', 'Die Seite konnte nicht angelegt werden.', ['status' => 500]);
            if ($d[2] && class_exists('TIX_Site_Config')) { TIX_Site_Config::set_legal_link($d[2], get_permalink($pid)); self::$legal = null; }
        }
        update_post_meta($pid, self::META_BY, $uid);
        if (class_exists('TIX_Web_Revalidate') && class_exists('TIX_Customer_URL')) {
            $host = (string) wp_parse_url((string) TIX_Customer_URL::base(), PHP_URL_HOST);
            if ($host !== '') TIX_Web_Revalidate::touch_domain($host);
        }
        clean_post_cache($pid);
        return self::rest_get($req);
    }
}
