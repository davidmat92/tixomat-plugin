<?php
/**
 * TIX_Org_Notify_REST – Benachrichtigungen je Veranstalter im Studio (1.38.884, WP-Admin-Restliste W3 / V3).
 * Vertrag: uebergaben/vertrag-team-kasse-benachrichtigungen-2026-11-17.md (ENDGÜLTIGE FORM), DOCUMENTATION.md §243.
 *
 *   GET  /organizer/notifications[?organizer_id=]  → {organizer_id, organizers?, can_edit, emails[], paused, paused_until, paused_indefinite, types{}, type_labels{}, pushover[]}
 *   POST /organizer/notifications {emails?, paused_until?, types?}  (nur mitgeschickte Teile)
 * Daten wie die Veranstalter-Metabox (TIX_Organizer_Notifications): _tix_org_notify_emails/_types/_paused_until.
 * Pushover nur lesend (Bezeichnung, an/aus), Schlüssel nie.
 * Rechte: evendis Inhaber/Team-Admin des eigenen Veranstalters, Admin mit organizer_id; Einzel-Seite Admin und Inhaber (Wahl des Veranstalters).
 */
if (!defined('ABSPATH')) exit;

class TIX_Org_Notify_REST {

    const NS = 'tixomat/v1';
    const TYPES = ['orders' => 'Neue Bestellung', 'cancelled' => 'Storniert', 'refunded' => 'Erstattet', 'failed' => 'Zahlung fehlgeschlagen',
        'support' => 'Kundenanfrage', 'checkin' => 'Einlass (jeder Scan)', 'transfer' => 'Ticket umgeschrieben'];
    const MAX_EMAILS = 10;

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes() {
        register_rest_route(self::NS, '/organizer/notifications', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => [__CLASS__, 'perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => [__CLASS__, 'perm']],
        ]);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    public static function perm() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        if (current_user_can('manage_options')) return true;
        if (self::multi()) return (TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) ? true : new WP_Error('rest_forbidden', 'Nur Inhaber und Team-Admins.', ['status' => 403]);
        return in_array('tix_organizer', (array) wp_get_current_user()->roles, true) ? true : new WP_Error('rest_forbidden', 'Nur Inhaber bzw. Admin.', ['status' => 403]);
    }

    /** Veranstalter der Seite (Einzel-Seite), meistgenutzte zuerst */
    private static function site_organizers() {
        global $wpdb;
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => ['publish', 'private', 'draft'], 'posts_per_page' => 50, 'fields' => 'ids']);
        if (!$ids) return [];
        $counts = [];
        foreach ((array) $wpdb->get_results("SELECT meta_value o, COUNT(*) n FROM {$wpdb->postmeta} WHERE meta_key = '_tix_organizer_id' GROUP BY meta_value") as $r) $counts[intval($r->o)] = intval($r->n);
        usort($ids, function ($a, $b) use ($counts) { return ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0) ?: $a <=> $b; });
        return array_map(function ($id) use ($counts) { return ['id' => intval($id), 'name' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'), 'events' => $counts[$id] ?? 0]; }, $ids);
    }

    /** → [org_id, organizers|null] | WP_Error */
    private static function org(WP_REST_Request $req) {
        $want = absint($req->get_param('organizer_id'));
        if (self::multi() && !current_user_can('manage_options')) return [intval(TIX_App_Scope::organizer_id_for_user()), null];
        $list = self::multi() ? null : self::site_organizers();
        if ($want) {
            if (get_post_type($want) !== 'tix_organizer') return new WP_Error('not_found', 'Veranstalter nicht gefunden.', ['status' => 404]);
            return [$want, $list];
        }
        if (self::multi()) return new WP_Error('tix_organizer_required', 'Bitte organizer_id angeben.', ['status' => 400, 'field' => 'organizer_id']);
        if (!$list) return new WP_Error('tix_no_organizer', 'Auf dieser Seite gibt es noch keinen Veranstalter.', ['status' => 409]);
        return [$list[0]['id'], $list];
    }

    // ── Reine Prüfung (Umzug: Fixtures) ──

    /** emails: string[] oder [{email, label?, enabled?}] → [liste, null] | [null, feld] */
    public static function clean_emails($in) {
        if (!is_array($in) || count($in) > self::MAX_EMAILS) return [null, 'emails'];
        $out = []; $seen = [];
        foreach (array_values($in) as $i => $row) {
            $row = is_array($row) ? $row : ['email' => $row];
            $e = sanitize_email((string) ($row['email'] ?? ''));
            if ($e === '' || !is_email($e)) return [null, 'emails.' . $i];
            if (isset($seen[strtolower($e)])) continue;
            $seen[strtolower($e)] = 1;
            $out[] = ['email' => $e, 'label' => mb_substr(sanitize_text_field((string) ($row['label'] ?? '')), 0, 60), 'enabled' => !array_key_exists('enabled', $row) || !empty($row['enabled']) ? 1 : 0];
        }
        return [$out, null];
    }

    /** paused_until: '' (aus) | 'indefinite' | 'YYYY-MM-DD' (heute bis +365 Tage, bis Tagesende) → [timestamp|0, null] | [null, fehler] */
    public static function clean_pause($v, $now) {
        if ($v === '' || $v === null || $v === false) return [0, null];
        if ($v === 'indefinite') return [TIX_Organizer_Notifications::PAUSE_INDEFINITE, null];
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return [null, 'paused_until'];
        try { $ts = (new DateTime($v . ' 23:59:59', wp_timezone()))->getTimestamp(); } catch (\Throwable $e) { return [null, 'paused_until']; }
        if ($ts < $now || $ts > $now + 366 * DAY_IN_SECONDS) return [null, 'paused_until'];
        return [$ts, null];
    }

    // ── Routen ──

    private static function payload($org, $list) {
        $until = TIX_Organizer_Notifications::paused_until($org);
        $paused = $until > time();
        $types = TIX_Organizer_Notifications::get_types($org);
        $out = ['ok' => true, 'organizer_id' => $org, 'can_edit' => true,
            'emails' => array_values(array_map(function ($r) { return ['email' => (string) ($r['email'] ?? ''), 'label' => (string) ($r['label'] ?? ''), 'enabled' => !empty($r['enabled'])]; }, TIX_Organizer_Notifications::get_emails($org))),
            'max_emails' => self::MAX_EMAILS,
            'paused' => $paused, 'paused_indefinite' => $paused && $until >= TIX_Organizer_Notifications::PAUSE_INDEFINITE,
            'paused_until' => $paused && $until < TIX_Organizer_Notifications::PAUSE_INDEFINITE ? wp_date('Y-m-d', $until) : '',
            'types' => array_map(function ($k) use ($types) { return !empty($types[$k]); }, array_combine(array_keys(self::TYPES), array_keys(self::TYPES))),
            'type_labels' => self::TYPES,
            'pushover' => array_values(array_map(function ($r) { return ['label' => (string) ($r['label'] ?? ''), 'enabled' => !empty($r['enabled'])]; }, TIX_Organizer_Notifications::get_pushovers($org)))];
        if ($list !== null) $out['organizers'] = $list;
        return $out;
    }

    public static function rest_get(WP_REST_Request $req) {
        $o = self::org($req);
        if (is_wp_error($o)) return $o;
        $r = rest_ensure_response(self::payload($o[0], $o[1]));
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_save(WP_REST_Request $req) {
        $o = self::org($req);
        if (is_wp_error($o)) return $o;
        [$org] = $o;
        $b = (array) ($req->get_json_params() ?: $req->get_body_params());
        $write = [];
        if (array_key_exists('emails', $b)) {
            [$e, $bad] = self::clean_emails($b['emails']);
            if ($e === null) return new WP_Error('tix_bad_email', $bad === 'emails' ? 'Höchstens ' . self::MAX_EMAILS . ' Empfänger.' : 'Bitte eine gültige E-Mail-Adresse eingeben.', ['status' => 400, 'field' => $bad]);
            $write['_tix_org_notify_emails'] = $e;
        }
        $pause = null;
        if (array_key_exists('paused_until', $b)) {
            [$pause, $bad] = self::clean_pause($b['paused_until'], time());
            if ($pause === null) return new WP_Error('tix_invalid_field', 'Pause: Datum ab heute bis in einem Jahr, „indefinite“ oder leer.', ['status' => 400, 'field' => 'paused_until']);
        }
        if (array_key_exists('types', $b)) {
            if (!is_array($b['types'])) return new WP_Error('tix_invalid_field', 'types: Objekt erwartet.', ['status' => 400, 'field' => 'types']);
            $t = TIX_Organizer_Notifications::get_types($org);
            foreach ($b['types'] as $k => $v) {
                if (!isset(self::TYPES[$k])) return new WP_Error('tix_invalid_field', 'Unbekannte Art: ' . $k . '.', ['status' => 400, 'field' => 'types.' . $k]);
                $t[$k] = !empty($v) ? 1 : 0;
            }
            $write['_tix_org_notify_types'] = $t;
        }
        foreach ($write as $k => $v) update_post_meta($org, $k, $v);
        if ($pause !== null) { if ($pause) update_post_meta($org, TIX_Organizer_Notifications::PAUSE_META, $pause); else delete_post_meta($org, TIX_Organizer_Notifications::PAUSE_META); }
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit') && ($write || $pause !== null)) {
            TIX_Customers::audit('org_notifications', $org, ['scope' => self::multi() ? 'organizer' : 'site', 'oid' => self::multi() ? $org : 0],
                ['emails' => isset($write['_tix_org_notify_emails']) ? count($write['_tix_org_notify_emails']) : null, 'types' => isset($write['_tix_org_notify_types']) ? array_keys(array_filter($write['_tix_org_notify_types'])) : null, 'paused' => $pause === null ? null : (bool) $pause]);
        }
        return self::payload($org, $o[1]);
    }
}

TIX_Org_Notify_REST::init();
