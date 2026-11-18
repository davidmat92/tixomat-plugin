<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Sales_Notify – Benachrichtigungen des Vertriebs auf der Plattform (1.38.903, User 18.11.).
 *
 * Ereignisse aus Demo-Zugang und Testphase (TIX_Trial) gehen per Mail an eine einstellbare Liste und – wenn
 * eingeschaltet – als Eintrag + Push in die App „evendis für Veranstalter“ an alle Plattform-Admins
 * (manage_options = `is_admin` in /me). Nur im Mehr-Veranstalter-Modus (evendis).
 *
 *   GET|POST /platform/sales-notify   {emails[], push, events{key: bool}} – nur Plattform-Admin
 *
 * Empfänger der Mail:
 *   - Einstellung gespeichert: deren Liste; leere Liste = alter Rückfall (alle administrator + tix_sales).
 *   - nicht gespeichert: alte Option tix_demo_notify (falls gesetzt), sonst DEFAULT_EMAILS.
 * Push-Text: nur Firma bzw. Name, nie E-Mail/Telefon. Kein Protokoll mit Personendaten.
 * Aktionen: staff:sales:trial:<id> · staff:sales:demo:<user_id> · staff:sales:demo (Anfrage noch ohne Konto) · staff:sales.
 */
class TIX_Sales_Notify {

    const NS  = 'tixomat/v1';
    const OPT = 'tix_sales_notify';
    const DEFAULT_EMAILS = ['david.mathauschek@evendis.de'];
    const MAX_EMAILS = 10;

    /** key => [Bezeichnung, Vorgabe] */
    const EVENTS = [
        'demo_requested'  => ['Demo angefordert (vor der Bestätigung)', true],
        'demo_created'    => ['Demo-Zugang bestätigt und angelegt', true],
        'trial_requested' => ['Testphase bestellt (vor der Bestätigung)', true],
        'trial_confirmed' => ['Testphase bestätigt, Shop wird eingerichtet', false],
        'trial_active'    => ['Testphase aktiv', true],
        'other'           => ['Weitere Meldungen (Zahlungen, Fehler, Kündigungen, Vertrag)', true],
    ];

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    // ═══════════════ Einstellung ═══════════════

    /** Gespeicherte Einstellung oder null (nie gespeichert) */
    private static function stored() {
        $o = get_option(self::OPT, null);
        return is_array($o) ? $o : null;
    }

    /** {emails, emails_source, push, events{key: bool}} */
    public static function settings() {
        $o = self::stored();
        $events = [];
        foreach (self::EVENTS as $k => [, $def]) $events[$k] = isset($o['events'][$k]) ? (bool) $o['events'][$k] : $def;
        if ($o !== null) { $emails = array_values((array) ($o['emails'] ?? [])); $src = 'saved'; }
        else {
            $legacy = get_option('tix_demo_notify', null);
            if (is_array($legacy) && array_filter($legacy)) { $emails = array_values(array_filter(array_map('sanitize_email', $legacy))); $src = 'legacy'; }
            else { $emails = self::DEFAULT_EMAILS; $src = 'default'; }
        }
        return ['emails' => $emails, 'emails_source' => $src, 'push' => $o !== null ? !empty($o['push']) : true, 'events' => $events];
    }

    /** Rein: Liste prüfen → [Liste, null] | [null, Index] */
    public static function clean_emails($in, $max = self::MAX_EMAILS) {
        if (!is_array($in) || count($in) > $max) return [null, ''];
        $out = [];
        foreach (array_values($in) as $i => $e) {
            $e = strtolower(trim((string) $e));
            if ($e === '' || !is_email($e)) return [null, (string) $i];
            if (!in_array($e, $out, true)) $out[] = $e;
        }
        return [$out, null];
    }

    /** Mail-Empfänger für den Versand */
    public static function mail_recipients() {
        $s = self::settings();
        $to = $s['emails'];
        if (!$to) { // nichts eingestellt (leere Liste): bisheriger Rückfall
            foreach (get_users(['role__in' => ['administrator', 'tix_sales'], 'fields' => ['user_email']]) as $u) $to[] = $u->user_email;
        }
        return array_values(array_unique(array_filter(array_map('sanitize_email', $to))));
    }

    /** Plattform-Admins (Push-Empfänger) */
    public static function admin_ids() {
        return array_map('intval', get_users(['role' => 'administrator', 'fields' => 'ID']));
    }

    /** Admins mit registrierter Veranstalter-App (Anzahl, ohne Personendaten) */
    private static function admin_devices() {
        if (!class_exists('TIX_Staff_Push')) return 0;
        $ids = self::admin_ids(); $n = [];
        foreach (TIX_Staff_Push::tokens() as $m) { $u = intval($m['user'] ?? 0); if (in_array($u, $ids, true)) $n[$u] = true; }
        return count($n);
    }

    // ═══════════════ Versand ═══════════════

    /**
     * Ereignis melden. $mail: [subject, paras[]]; $push: [title, body, action] (body nur Firma/Name) oder null (kein Push).
     * → ['mail' => Anzahl, 'push' => Anzahl Geräte] ; außerhalb der Plattform bzw. Ereignis aus: nichts.
     */
    public static function send($event, array $mail, $push = null) {
        if (!isset(self::EVENTS[$event])) $event = 'other';
        $out = ['mail' => 0, 'push' => 0];
        if (!self::multi()) return $out;
        if (class_exists('TIX_Shadow') && TIX_Shadow::active()) return $out;
        $s = self::settings();
        if (empty($s['events'][$event])) return $out;
        [$subject, $paras] = $mail + [1 => []];
        foreach (self::mail_recipients() as $a) {
            if (self::mail($a, (string) $subject, (array) $paras)) $out['mail']++;
        }
        if ($s['push'] && is_array($push) && class_exists('TIX_Staff_Push')) {
            [$title, $body, $action] = $push + [1 => '', 2 => 'staff:sales'];
            $title = mb_substr(wp_strip_all_tags((string) $title), 0, 80);
            $body = mb_substr(wp_strip_all_tags((string) $body), 0, 120);
            $uids = self::admin_ids();
            foreach ($uids as $uid) {
                if (class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
                    TIX_Notifications::add_user_item($uid, $title, $body, ['type' => 'sales', 'kind' => 'transactional', 'action' => $action, 'no_push' => true]);
                }
            }
            $out['push'] = intval(TIX_Staff_Push::send_to_users($uids, $title, $body, ['action' => $action, 'type' => 'sales_' . $event]));
        }
        return $out;
    }

    private static function mail($to, $subject, array $paras) {
        $h = '';
        foreach ($paras as $p) $h .= '<p>' . esc_html((string) $p) . '</p>';
        if (class_exists('TIX_Mail_Layout')) return (bool) TIX_Mail_Layout::send($to, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $h]], 'kind' => 'admin'], ['customer' => false]);
        return (bool) wp_mail($to, $subject, $h, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $perm = class_exists('TIX_Platform_Admin') ? ['TIX_Platform_Admin', 'perm'] : function () { return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]); };
        register_rest_route(self::NS, '/platform/sales-notify', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
    }

    public static function payload() {
        $s = self::settings();
        $events = [];
        foreach (self::EVENTS as $k => [$label, $def]) $events[] = ['key' => $k, 'label' => $label, 'enabled' => $s['events'][$k], 'default' => $def];
        return ['ok' => true, 'emails' => $s['emails'], 'emails_source' => $s['emails_source'], 'emails_default' => self::DEFAULT_EMAILS,
            'fallback' => 'Ist die Liste leer, gehen Mails an alle Admins und Vertriebs-Konten.', 'push' => $s['push'],
            'push_devices' => self::admin_devices(), 'events' => $events, 'limits' => ['emails' => self::MAX_EMAILS]];
    }

    public static function rest_get(WP_REST_Request $req) {
        $r = rest_ensure_response(self::payload());
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** POST {emails?, push?, events?{key: bool}} – nur Mitgeschicktes; Protokoll platform_sales_notify (nur Anzahlen) */
    public static function rest_save(WP_REST_Request $req) {
        $b = $req->get_json_params();
        $b = is_array($b) ? $b : (array) $req->get_body_params();
        foreach (array_keys($b) as $k) if (!in_array($k, ['emails', 'push', 'events', 'ok', 'emails_source', 'emails_default', 'fallback', 'push_devices', 'limits'], true))
            return new WP_Error('tix_invalid_field', 'Unbekanntes Feld: ' . $k . '.', ['status' => 400, 'field' => $k]);
        $cur = self::settings();
        $new = ['emails' => $cur['emails'], 'push' => $cur['push'], 'events' => $cur['events']];
        if (array_key_exists('emails', $b)) {
            [$l, $bad] = self::clean_emails($b['emails']);
            if ($l === null) return new WP_Error('tix_invalid_field', $bad === '' ? 'Bitte höchstens ' . self::MAX_EMAILS . ' Adressen als Liste.' : 'Ungültige E-Mail-Adresse.', ['status' => 400, 'field' => 'emails' . ($bad !== '' ? '.' . $bad : '')]);
            $new['emails'] = $l;
        }
        if (array_key_exists('push', $b)) {
            if (!is_bool($b['push']) && !in_array($b['push'], [0, 1, '0', '1'], true)) return new WP_Error('tix_invalid_field', 'push: true oder false.', ['status' => 400, 'field' => 'push']);
            $new['push'] = (bool) $b['push'];
        }
        if (array_key_exists('events', $b)) {
            if (!is_array($b['events'])) return new WP_Error('tix_invalid_field', 'events als Objekt {key: true|false}.', ['status' => 400, 'field' => 'events']);
            foreach ($b['events'] as $k => $v) {
                if (is_array($v) && array_key_exists('enabled', $v)) $v = $v['enabled']; // auch [{key, enabled}]-Form der Antwort
                if (is_int($k) && is_array($b['events'][$k]) && isset($b['events'][$k]['key'])) $k = (string) $b['events'][$k]['key'];
                if (!isset(self::EVENTS[$k])) return new WP_Error('tix_invalid_field', 'Unbekanntes Ereignis: ' . $k . '.', ['status' => 400, 'field' => 'events.' . $k]);
                if (!is_bool($v) && !in_array($v, [0, 1, '0', '1'], true)) return new WP_Error('tix_invalid_field', 'true oder false.', ['status' => 400, 'field' => 'events.' . $k]);
                $new['events'][$k] = (bool) $v;
            }
        }
        update_option(self::OPT, $new, false);
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) {
            TIX_Customers::audit('platform_sales_notify', 0, ['scope' => 'platform', 'oid' => 0], ['emails' => count($new['emails']), 'push' => $new['push'], 'events' => $new['events']]);
        }
        return self::rest_get($req);
    }
}
TIX_Sales_Notify::init();
