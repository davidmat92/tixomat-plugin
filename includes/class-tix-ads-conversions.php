<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Ads_Conversions – Google-Ads-Conversions vom Server, ohne Pixel (1.38.906, Vertrag vertrag-ads-offline-conversions.md §2/§4/§5).
 *
 * Schalter: Option tix_ads_conversions ('1' = an), Vorgabe AUS. Aus → /public/ads/click 404, Feld `ads` der Testphase wird ignoriert.
 *
 *   POST /public/ads/click                    {click_id_type, click_id, conversion, source, reference, email?, consent{ad_user_data, ad_personalization}}
 *                                              → 202 {ok} | 400 tix_invalid_field | 409 tix_no_consent | 429 tix_rate_limited (5 je IP in 10 min)
 *   GET|POST /platform/ads-conversions        Einstellung + Zähler (nur Admin, manage_options; nie Klick-IDs)
 *   POST /platform/ads-conversions/export     CSV „Conversions from clicks“ für den manuellen Import in Google Ads → markiert gemeldet + leert
 *
 * Datenschutz: E-Mail sofort SHA-256 (trim + klein), nie Klartext. Klick-ID und Prüfwert nie in Logs, Mails oder Audit.
 * Status: wartet (Testphase noch nicht bestätigt) · neu · gemeldet · fehler · verfallen.
 * Löschung: nach dem Export sofort (Testphase: Klick-ID bis zur Conversion „kunde“), spätestens 90 Tage nach clicked_at (täglicher Cron).
 * Upload über die Google-Ads-API folgt später (§5); bis dahin nur der CSV-Weg.
 */
class TIX_Ads_Conversions {

    const NS = 'tixomat/v1';
    const OPT = 'tix_ads_conversions';
    const OPT_NAMES = 'tix_ads_conversion_names';
    const DB_VERSION = '1';
    const KEEP_DAYS = 90;
    const MIN_AGE_HOURS = 6;
    const TYPES = ['gclid', 'gbraid', 'wbraid'];
    const CONVERSIONS = ['beratung', 'testphase', 'kunde'];
    const PUBLIC_CONVERSIONS = ['beratung', 'testphase']; // „kunde“ meldet nur das Plugin selbst
    const STATUSES = ['wartet', 'neu', 'gemeldet', 'fehler', 'verfallen'];
    const DEFAULT_NAMES = ['beratung' => 'Beratung angefragt', 'testphase' => 'Testphase gestartet', 'kunde' => 'Kunde'];
    const CSV_TZ = 'Europe/Berlin';
    const CSV_HEADER = ['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Ad User Data', 'Ad Personalization'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_ads_cleanup', [__CLASS__, 'cleanup']);
        add_action('init', function () {
            if (get_option('tix_ads_db') && !wp_next_scheduled('tix_ads_cleanup')) wp_schedule_event(time() + 900, 'daily', 'tix_ads_cleanup');
        });
        add_action('tix_trial_ordered', [__CLASS__, 'on_trial_ordered'], 10, 3);
        add_action('tix_trial_queued', [__CLASS__, 'on_trial_queued'], 10, 1);
        add_action('tix_trial_converted', [__CLASS__, 'on_trial_converted'], 10, 2);
    }

    public static function enabled() { return (string) get_option(self::OPT, '0') === '1'; }

    public static function t() { global $wpdb; return $wpdb->prefix . 'tix_ads_clicks'; }

    public static function maybe_install() {
        if (get_option('tix_ads_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::t() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            click_id_type varchar(10) NOT NULL DEFAULT '',
            click_id varchar(150) NOT NULL DEFAULT '',
            conversion varchar(20) NOT NULL DEFAULT '',
            source varchar(120) NOT NULL DEFAULT '',
            reference varchar(40) NOT NULL DEFAULT '',
            email_sha256 char(64) NOT NULL DEFAULT '',
            consent varchar(255) NOT NULL DEFAULT '',
            conversion_value decimal(10,2) NULL DEFAULT NULL,
            currency_code char(3) NOT NULL DEFAULT '',
            clicked_at datetime NOT NULL,
            uploaded_at datetime NULL DEFAULT NULL,
            status varchar(12) NOT NULL DEFAULT 'neu',
            PRIMARY KEY  (id),
            KEY status (status),
            KEY reference (reference),
            KEY clicked_at (clicked_at)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_ads_db', self::DB_VERSION, false);
    }

    // ═══════════════ Reine Helfer ═══════════════

    /** E-Mail → SHA-256 hex (getrimmt, kleingeschrieben) | '' */
    public static function hash_email($email) {
        $e = strtolower(trim((string) $email));
        return ($e !== '' && is_email($e)) ? hash('sha256', $e) : '';
    }

    /** Einwilligung prüfen → ['ad_user_data'=>'GRANTED', 'ad_personalization'=>'GRANTED|DENIED'] | null (keine Einwilligung) | false (Format falsch) */
    public static function clean_consent($c) {
        if (!is_array($c)) return false;
        $u = strtoupper(trim((string) ($c['ad_user_data'] ?? '')));
        $p = strtoupper(trim((string) ($c['ad_personalization'] ?? 'DENIED')));
        if (!in_array($u, ['GRANTED', 'DENIED', ''], true) || !in_array($p, ['GRANTED', 'DENIED'], true)) return false;
        if ($u !== 'GRANTED') return null;
        return ['ad_user_data' => 'GRANTED', 'ad_personalization' => $p];
    }

    /** Klick prüfen → [daten, null] | [null, feld] */
    public static function clean_click(array $b, array $allowed = self::PUBLIC_CONVERSIONS) {
        $type = strtolower(trim((string) ($b['click_id_type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) return [null, 'click_id_type'];
        $id = trim((string) ($b['click_id'] ?? ''));
        if (!preg_match('/^[\w-]{10,150}$/', $id)) return [null, 'click_id'];
        $conv = strtolower(trim((string) ($b['conversion'] ?? '')));
        if (!in_array($conv, $allowed, true)) return [null, 'conversion'];
        $ref = trim((string) ($b['reference'] ?? ''));
        if ($ref !== '' && !preg_match('/^(support|trial):\d{1,12}$/', $ref)) return [null, 'reference'];
        $src = mb_substr(sanitize_text_field((string) ($b['source'] ?? '')), 0, 120);
        $email = $b['email'] ?? '';
        if ($email !== '' && $email !== null && self::hash_email($email) === '') return [null, 'email'];
        return [['click_id_type' => $type, 'click_id' => $id, 'conversion' => $conv, 'reference' => $ref, 'source' => $src, 'email_sha256' => self::hash_email($email)], null];
    }

    /** Conversion-Namen (wie in Google Ads angelegt) */
    public static function names() {
        $o = get_option(self::OPT_NAMES, []);
        $out = [];
        foreach (self::DEFAULT_NAMES as $k => $def) $out[$k] = (is_array($o) && isset($o[$k]) && trim((string) $o[$k]) !== '') ? (string) $o[$k] : $def;
        return $out;
    }

    /** UTC-Zeit (Y-m-d H:i:s) → Ortszeit für die CSV */
    public static function csv_time($utc) {
        $d = new DateTimeImmutable((string) $utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone(self::CSV_TZ))->format('Y-m-d H:i:s');
    }

    /** Zeilen → CSV-Text im Format der Google-Vorlage „Conversions from clicks“ */
    public static function build_csv(array $rows, array $names) {
        $h = fopen('php://temp', 'r+');
        fwrite($h, 'Parameters:TimeZone=' . self::CSV_TZ . "\n");
        fwrite($h, implode(',', self::CSV_HEADER) . "\n");
        foreach ($rows as $r) {
            $c = json_decode((string) $r->consent, true) ?: [];
            fputcsv($h, [
                (string) $r->click_id,
                (string) ($names[$r->conversion] ?? $r->conversion),
                self::csv_time($r->clicked_at),
                $r->conversion_value !== null ? number_format((float) $r->conversion_value, 2, '.', '') : '',
                $r->conversion_value !== null ? ($r->currency_code ?: 'EUR') : '',
                ($c['ad_user_data'] ?? '') === 'GRANTED' ? 'Granted' : 'Denied',
                ($c['ad_personalization'] ?? '') === 'GRANTED' ? 'Granted' : 'Denied',
            ], ',', '"', '');
        }
        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);
        return $csv;
    }

    // ═══════════════ Speichern ═══════════════

    /** Eintrag anlegen (ohne Doppel: gleiche Klick-ID + Conversion + Bezug) → id | 0 */
    public static function store(array $c, array $consent, $status = 'neu', array $extra = []) {
        if (class_exists('TIX_Shadow') && TIX_Shadow::active()) return 0;
        global $wpdb;
        self::maybe_install();
        $dup = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t() . ' WHERE click_id = %s AND conversion = %s AND reference = %s LIMIT 1', $c['click_id'], $c['conversion'], $c['reference']));
        if ($dup) return intval($dup);
        $row = ['click_id_type' => $c['click_id_type'], 'click_id' => $c['click_id'], 'conversion' => $c['conversion'], 'source' => (string) ($c['source'] ?? ''),
            'reference' => (string) $c['reference'], 'email_sha256' => (string) ($c['email_sha256'] ?? ''), 'consent' => wp_json_encode($consent),
            'clicked_at' => gmdate('Y-m-d H:i:s'), 'status' => $status] + $extra;
        $wpdb->insert(self::t(), $row);
        return intval($wpdb->insert_id);
    }

    // ═══════════════ Testphase (§3) ═══════════════

    /** Bestellung mit Feld ads → Eintrag „testphase“ (wartet bis zur Bestätigung). Fehlerhaftes ads bricht die Bestellung nie ab. */
    public static function on_trial_ordered($trial_id, $ads, $email) {
        if (!self::enabled() || !is_array($ads) || !$trial_id) return;
        $consent = self::clean_consent($ads['consent'] ?? null);
        if (!$consent) return;
        [$c] = self::clean_click(['click_id_type' => $ads['click_id_type'] ?? '', 'click_id' => $ads['click_id'] ?? '', 'conversion' => 'testphase',
            'reference' => 'trial:' . intval($trial_id), 'source' => $ads['source'] ?? 'demo.evendis.de/testen', 'email' => $email]);
        if ($c) self::store($c, $consent, 'wartet');
    }

    /** Testphase bestätigt (E-Mail bzw. Zahlungsmittel) → Eintrag wird meldefähig */
    public static function on_trial_queued($t) {
        if (!get_option('tix_ads_db') || empty($t->id)) return;
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET status = 'neu' WHERE reference = %s AND conversion = 'testphase' AND status = 'wartet' AND click_id <> ''", 'trial:' . intval($t->id)));
    }

    /** Erstes bezahltes „Aktiv halten“ → Eintrag „kunde“ mit Netto-Paketpreis, solange der Klick < 90 Tage alt ist */
    public static function on_trial_converted($t, $net) {
        if (!self::enabled() || !get_option('tix_ads_db') || empty($t->id)) return;
        global $wpdb;
        $ref = 'trial:' . intval($t->id);
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t() . " WHERE reference = %s AND conversion = 'kunde' LIMIT 1", $ref))) return;
        $src = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t() . " WHERE reference = %s AND conversion = 'testphase' AND click_id <> '' AND status IN ('neu','gemeldet') AND clicked_at >= %s ORDER BY id DESC LIMIT 1",
            $ref, gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS)));
        if (!$src) return;
        $consent = json_decode((string) $src->consent, true);
        if (($consent['ad_user_data'] ?? '') !== 'GRANTED') return;
        self::store(['click_id_type' => $src->click_id_type, 'click_id' => $src->click_id, 'conversion' => 'kunde', 'reference' => $ref, 'source' => $src->source, 'email_sha256' => $src->email_sha256],
            $consent, 'neu', ['conversion_value' => $net !== null ? round(floatval($net), 2) : null, 'currency_code' => 'EUR']);
        if ($src->status === 'gemeldet') $wpdb->update(self::t(), ['click_id' => '', 'email_sha256' => ''], ['id' => $src->id]); // nur noch für „kunde“ gebraucht
    }

    // ═══════════════ Löschfrist (§4) ═══════════════

    /** Täglich: alles älter als 90 Tage ohne Klick-ID und Prüfwert; Offenes wird „verfallen“ → Anzahl geleerter Zeilen */
    public static function cleanup() {
        if (!get_option('tix_ads_db')) return 0;
        global $wpdb;
        $cut = gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS);
        return intval($wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET click_id = '', email_sha256 = '', status = IF(status IN ('wartet','neu','fehler'), 'verfallen', status)
            WHERE clicked_at < %s AND (click_id <> '' OR email_sha256 <> '' OR status IN ('wartet','neu','fehler'))", $cut)));
    }

    // ═══════════════ REST ═══════════════

    private static function err($code, $msg, $status, $field = null) {
        return new WP_Error($code, $msg, ['status' => $status] + ($field !== null ? ['field' => $field] : []));
    }

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    public static function routes() {
        register_rest_route(self::NS, '/public/ads/click', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_click'], 'permission_callback' => '__return_true']);
        $admin = function () { return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur für Admins.', ['status' => is_user_logged_in() ? 403 : 401]); };
        register_rest_route(self::NS, '/platform/ads-conversions', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $admin],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $admin],
        ]);
        register_rest_route(self::NS, '/platform/ads-conversions/export', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_export'], 'permission_callback' => $admin]);
    }

    public static function rest_click(WP_REST_Request $req) {
        if (!self::enabled()) return self::err('rest_no_route', 'Nicht gefunden.', 404);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('ads_click', 5, 600))
            return self::err('tix_rate_limited', 'Zu viele Anfragen. Bitte später noch einmal.', 429);
        $b = $req->get_json_params();
        $b = is_array($b) ? $b : (array) $req->get_body_params();
        [$c, $bad] = self::clean_click($b);
        if (!$c) return self::err('tix_invalid_field', 'Ungültiger Wert.', 400, $bad);
        $consent = self::clean_consent($b['consent'] ?? null);
        if ($consent === false) return self::err('tix_invalid_field', 'consent: ad_user_data und ad_personalization als GRANTED oder DENIED.', 400, 'consent');
        if ($consent === null) return self::err('tix_no_consent', 'Ohne Einwilligung wird nichts gespeichert.', 409);
        self::store($c, $consent, 'neu');
        return self::out(['ok' => true], 202);
    }

    /** Zähler je Conversion und Status (nie Klick-IDs) */
    public static function counts() {
        $out = [];
        foreach (self::CONVERSIONS as $k) $out[$k] = array_fill_keys(self::STATUSES, 0);
        if (!get_option('tix_ads_db')) return $out;
        global $wpdb;
        foreach ((array) $wpdb->get_results('SELECT conversion, status, COUNT(*) n FROM ' . self::t() . ' GROUP BY conversion, status') as $r) {
            if (isset($out[$r->conversion][$r->status])) $out[$r->conversion][$r->status] = intval($r->n);
        }
        return $out;
    }

    /** Offene Einträge, die jetzt exportierbar sind (gclid, älter als 6 h, < 90 Tage) bzw. noch nicht */
    private static function exportable_where() {
        return ["status = 'neu' AND click_id_type = 'gclid' AND click_id <> '' AND clicked_at <= %s AND clicked_at >= %s",
            [gmdate('Y-m-d H:i:s', time() - self::MIN_AGE_HOURS * HOUR_IN_SECONDS), gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS)]];
    }

    public static function payload() {
        $pending = ['exportable' => 0, 'too_young' => 0, 'braid' => 0];
        if (get_option('tix_ads_db')) {
            global $wpdb;
            [$w, $a] = self::exportable_where();
            $pending['exportable'] = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t() . ' WHERE ' . $w, ...$a)));
            $pending['too_young'] = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t() . " WHERE status = 'neu' AND click_id_type = 'gclid' AND click_id <> '' AND clicked_at > %s", $a[0])));
            $pending['braid'] = intval($wpdb->get_var('SELECT COUNT(*) FROM ' . self::t() . " WHERE status = 'neu' AND click_id_type IN ('gbraid','wbraid') AND click_id <> ''"));
        }
        $names = self::names();
        $conv = [];
        foreach (self::CONVERSIONS as $k) $conv[] = ['key' => $k, 'name' => $names[$k], 'default_name' => self::DEFAULT_NAMES[$k]];
        return ['ok' => true, 'enabled' => self::enabled(), 'upload' => 'csv', 'conversions' => $conv, 'counts' => self::counts(), 'pending' => $pending,
            'keep_days' => self::KEEP_DAYS, 'min_age_hours' => self::MIN_AGE_HOURS, 'csv_timezone' => self::CSV_TZ,
            'hint' => 'Die CSV im Google-Ads-Konto unter Ziele → Conversions → Uploads hochladen. Die Conversion-Namen müssen genau so in Google Ads angelegt sein (Typ „Import → Klicks“). gbraid/wbraid gehen erst mit der API.'];
    }

    public static function rest_get(WP_REST_Request $req) { return self::out(self::payload()); }

    /** POST {enabled?, names?{beratung,testphase,kunde}} */
    public static function rest_save(WP_REST_Request $req) {
        $b = $req->get_json_params();
        $b = is_array($b) ? $b : (array) $req->get_body_params();
        foreach (array_keys($b) as $k) if (!in_array($k, ['enabled', 'names'], true)) return self::err('tix_invalid_field', 'Unbekanntes Feld: ' . $k . '.', 400, $k);
        if (array_key_exists('enabled', $b)) {
            if (!is_bool($b['enabled']) && !in_array($b['enabled'], [0, 1, '0', '1'], true)) return self::err('tix_invalid_field', 'enabled: true oder false.', 400, 'enabled');
            update_option(self::OPT, $b['enabled'] ? '1' : '0', false);
            if ($b['enabled']) self::maybe_install();
        }
        if (array_key_exists('names', $b)) {
            if (!is_array($b['names'])) return self::err('tix_invalid_field', 'names als Objekt.', 400, 'names');
            $cur = (array) get_option(self::OPT_NAMES, []);
            foreach ($b['names'] as $k => $v) {
                if (!isset(self::DEFAULT_NAMES[$k])) return self::err('tix_invalid_field', 'Unbekannte Conversion: ' . $k . '.', 400, 'names.' . $k);
                $v = trim(sanitize_text_field((string) $v));
                if (mb_strlen($v) > 100) return self::err('tix_invalid_field', 'Höchstens 100 Zeichen.', 400, 'names.' . $k);
                $cur[$k] = $v; // leer = Vorgabe
            }
            update_option(self::OPT_NAMES, $cur, false);
        }
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) {
            TIX_Customers::audit('ads_conversions_settings', 0, ['scope' => 'platform', 'oid' => 0], ['enabled' => self::enabled()]);
        }
        return self::rest_get($req);
    }

    /**
     * POST {conversion?} → {ok, filename, count, csv, by_conversion}. Exportiert alle meldefähigen gclid-Einträge,
     * markiert sie „gemeldet“ und leert Klick-ID + Prüfwert (Testphase: Klick-ID bleibt, bis „kunde“ angelegt ist bzw. 90 Tage).
     * Kein zweiter Abruf derselben Daten – die Datei gleich in Google Ads hochladen.
     */
    public static function rest_export(WP_REST_Request $req) {
        $conv = (string) ($req->get_param('conversion') ?? '');
        if ($conv !== '' && !in_array($conv, self::CONVERSIONS, true)) return self::err('tix_invalid_field', 'Unbekannte Conversion.', 400, 'conversion');
        $names = self::names();
        $rows = [];
        if (get_option('tix_ads_db')) {
            global $wpdb;
            [$w, $a] = self::exportable_where();
            if ($conv !== '') { $w .= ' AND conversion = %s'; $a[] = $conv; }
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::t() . ' WHERE ' . $w . ' ORDER BY id ASC LIMIT 5000', ...$a));
        }
        $csv = self::build_csv($rows, $names);
        $by = array_fill_keys(self::CONVERSIONS, 0);
        if ($rows) {
            global $wpdb;
            $now = gmdate('Y-m-d H:i:s');
            foreach ($rows as $r) {
                $by[$r->conversion]++;
                $keep = $r->conversion === 'testphase' && !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t() . " WHERE reference = %s AND conversion = 'kunde' LIMIT 1", $r->reference));
                $wpdb->update(self::t(), ['status' => 'gemeldet', 'uploaded_at' => $now, 'email_sha256' => '', 'click_id' => $keep ? $r->click_id : ''], ['id' => $r->id]);
            }
        }
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) {
            TIX_Customers::audit('ads_conversions_export', 0, ['scope' => 'platform', 'oid' => 0], ['count' => count($rows), 'by_conversion' => $by]);
        }
        return self::out(['ok' => true, 'filename' => 'google-ads-conversions-' . wp_date('Y-m-d-His') . '.csv', 'content_type' => 'text/csv; charset=utf-8',
            'count' => count($rows), 'by_conversion' => $by, 'csv' => $csv]);
    }
}
TIX_Ads_Conversions::init();
