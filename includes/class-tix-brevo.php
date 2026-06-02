<?php
/**
 * TIX Brevo Integration
 *
 * Synchronisiert Newsletter-Opt-Ins automatisch in eine Brevo-Kontaktliste.
 * - Hook: tix_newsletter_optin (native Checkout)
 * - Hook: save_post_tix_subscriber (WC Checkout + manuelle Anlage)
 * - Resync: AJAX-Endpoint pusht alle bestehenden Subscribers in einem Rutsch
 *
 * Settings (in tix_settings):
 *   - brevo_enabled        (bool)
 *   - brevo_api_key        (string, xkeysib-...)
 *   - brevo_list_id        (int, Liste-ID)
 *   - brevo_double_optin   (bool, optional — Brevo DOI-Templates müssten separat konfiguriert werden)
 */
if (!defined('ABSPATH')) exit;

class TIX_Brevo {

    const API_URL = 'https://api.brevo.com/v3';

    public static function init() {
        add_action('tix_newsletter_optin',  [__CLASS__, 'handle_native_optin'], 10, 4);
        add_action('save_post_tix_subscriber', [__CLASS__, 'handle_subscriber_save'], 20, 3);
        add_action('wp_ajax_tix_brevo_test',           [__CLASS__, 'ajax_test']);
        add_action('wp_ajax_tix_brevo_resync',         [__CLASS__, 'ajax_resync']);
        add_action('wp_ajax_tix_brevo_import_all',     [__CLASS__, 'ajax_import_all']);
        add_action('wp_ajax_tix_brevo_import_nonbuyers', [__CLASS__, 'ajax_import_nonbuyers']);
        add_action('wp_ajax_tix_brevo_import_external', [__CLASS__, 'ajax_import_external']);
    }

    /* ─────────── Bereitschaft ─────────── */

    public static function is_configured(): bool {
        if (empty(tix_get_settings('brevo_enabled')))    return false;
        if (empty(tix_get_settings('brevo_api_key')))    return false;
        // Mindestens entweder Default-Liste ODER eine Mapping-Regel
        $has_default  = intval(tix_get_settings('brevo_list_id')) > 0;
        $has_mappings = !empty(tix_get_settings('brevo_mappings'));
        return $has_default || $has_mappings;
    }

    /**
     * Berechnet welche Listen-IDs für eine Bestellung greifen.
     * Geht alle Items der Order durch + matched gegen Mapping-Regeln.
     * Fallback: Default-Listen-ID. Liefert eindeutiges Array von list_ids.
     */
    public static function list_ids_for_order(int $order_id): array {
        $mappings = (array) (tix_get_settings('brevo_mappings') ?: []);
        $default  = intval(tix_get_settings('brevo_list_id') ?? 0);
        $list_ids = [];

        if (!empty($mappings) && $order_id > 0) {
            global $wpdb;
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT event_id, cat_name FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d",
                $order_id
            ));
            foreach ($items as $it) {
                $ev  = intval($it->event_id);
                $cat = trim((string) $it->cat_name);
                foreach ($mappings as $m) {
                    $rule_event = intval($m['event_id'] ?? 0);
                    $rule_cat   = trim((string) ($m['cat_name'] ?? ''));
                    // event_id=0 = any, cat_name='' = any
                    $event_match = ($rule_event === 0) || ($rule_event === $ev);
                    $cat_match   = ($rule_cat === '')  || (mb_strtolower($rule_cat) === mb_strtolower($cat));
                    if ($event_match && $cat_match) {
                        $list_ids[] = intval($m['list_id']);
                    }
                }
            }
        }

        // Fallback: Default-Liste, falls keine Regel matched
        if (empty($list_ids) && $default > 0) {
            $list_ids[] = $default;
        }

        return array_values(array_unique(array_filter($list_ids, fn($id) => $id > 0)));
    }

    /* ─────────── HOOKS ─────────── */

    public static function handle_native_optin($order_id, $email, $first_name, $last_name) {
        if (!self::is_configured()) return;
        $list_ids = self::list_ids_for_order(intval($order_id));
        if (empty($list_ids)) return; // weder Mapping noch Default → nichts pushen
        self::add_contact($email, $first_name, $last_name, [
            'source'   => 'tixomat_native_checkout',
            'order_id' => intval($order_id),
        ], $list_ids);
    }

    public static function handle_subscriber_save($post_id, $post, $update) {
        if (!self::is_configured()) return;
        if ($post->post_status !== 'publish') return;
        if (wp_is_post_revision($post_id)) return;
        $email = get_post_meta($post_id, '_tix_sub_email', true) ?: $post->post_title;
        if (!is_email($email)) return;
        $opt_in = get_post_meta($post_id, '_tix_sub_consent', true);
        if (!$opt_in) return;
        $first = (string) get_post_meta($post_id, '_tix_sub_first_name', true);
        $last  = (string) get_post_meta($post_id, '_tix_sub_last_name', true);
        // Subscriber-CPT hat keine Order → versuche order_id Meta, sonst nur Default-Liste
        $order_id = intval(get_post_meta($post_id, '_tix_sub_order_id', true));
        $list_ids = self::list_ids_for_order($order_id);
        if (empty($list_ids)) return;
        self::add_contact($email, $first, $last, [
            'source'        => 'tixomat_subscriber_cpt',
            'subscriber_id' => intval($post_id),
        ], $list_ids);
    }

    /* ─────────── API: Kontakt hinzufügen / aktualisieren ─────────── */

    /**
     * Fügt einen Kontakt zur konfigurierten Brevo-Liste hinzu (oder aktualisiert ihn).
     * @return array{success: bool, message: string, http?: int}
     */
    public static function add_contact(string $email, string $first = '', string $last = '', array $extra = [], ?array $list_ids = null): array {
        if (!self::is_configured()) return ['success' => false, 'message' => 'Brevo nicht konfiguriert.'];
        $email = strtolower(trim(sanitize_email($email)));
        if (!is_email($email)) return ['success' => false, 'message' => 'Ungültige E-Mail.'];

        $api_key = trim(tix_get_settings('brevo_api_key'));
        // Backward-compat: wenn keine Liste übergeben → Default
        if ($list_ids === null) {
            $default = intval(tix_get_settings('brevo_list_id') ?? 0);
            $list_ids = $default > 0 ? [$default] : [];
        }
        $list_ids = array_values(array_unique(array_filter(array_map('intval', $list_ids), fn($id) => $id > 0)));
        if (empty($list_ids)) return ['success' => false, 'message' => 'Keine Listen-ID — weder Mapping noch Default-Liste konfiguriert.'];

        $attributes = array_filter([
            'VORNAME'   => trim($first),
            'NACHNAME'  => trim($last),
            // Brevo's Default-Attribute heißen FIRSTNAME/LASTNAME — wir setzen beide damit's egal ist
            'FIRSTNAME' => trim($first),
            'LASTNAME'  => trim($last),
        ], fn($v) => $v !== '');

        $payload = [
            'email'         => $email,
            'attributes'    => $attributes,
            'listIds'       => $list_ids,
            'updateEnabled' => true, // Duplikate updaten statt Fehler werfen
        ];

        $resp = wp_remote_post(self::API_URL . '/contacts', [
            'timeout' => 10,
            'headers' => [
                'api-key'      => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => 'Verbindungsfehler: ' . $resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        // Brevo: 201 = neu erstellt, 204 = aktualisiert. Beides OK.
        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'message' => 'OK', 'http' => $code, 'id' => $body['id'] ?? null];
        }
        return [
            'success' => false,
            'http'    => $code,
            'message' => $body['message'] ?? wp_remote_retrieve_body($resp) ?: 'Unbekannter Fehler (HTTP ' . $code . ')',
        ];
    }

    /* ─────────── AJAX: Verbindungstest ─────────── */

    public static function ajax_test() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        $api_key = trim(sanitize_text_field($_POST['api_key'] ?? ''));
        $list_id = intval($_POST['list_id'] ?? 0);
        if ($api_key === '' || $list_id <= 0) {
            wp_send_json_error(['message' => 'API-Key und Listen-ID erforderlich.']);
        }
        // Account-Endpoint testet API-Key
        $resp = wp_remote_get(self::API_URL . '/account', [
            'timeout' => 10,
            'headers' => ['api-key' => $api_key, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($resp)) wp_send_json_error(['message' => 'Verbindungsfehler: ' . $resp->get_error_message()]);
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200) {
            wp_send_json_error(['message' => 'API-Key abgelehnt (HTTP ' . $code . '): ' . ($data['message'] ?? '')]);
        }
        // Liste verifizieren
        $resp2 = wp_remote_get(self::API_URL . '/contacts/lists/' . $list_id, [
            'timeout' => 10,
            'headers' => ['api-key' => $api_key, 'Accept' => 'application/json'],
        ]);
        $code2 = wp_remote_retrieve_response_code($resp2);
        $data2 = json_decode(wp_remote_retrieve_body($resp2), true);
        if ($code2 !== 200) {
            wp_send_json_error(['message' => 'Liste #' . $list_id . ' nicht gefunden (HTTP ' . $code2 . ')']);
        }
        wp_send_json_success([
            'message' => '✓ Verbunden mit ' . esc_html($data['companyName'] ?? $data['email'] ?? 'Brevo'),
            'list'    => $data2['name'] ?? ('Liste ' . $list_id),
            'count'   => intval($data2['totalSubscribers'] ?? 0),
        ]);
    }

    /* ─────────── AJAX: Resync alle bestehenden Opt-Ins ─────────── */

    public static function ajax_resync() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        if (!self::is_configured()) wp_send_json_error(['message' => 'Brevo nicht konfiguriert.']);

        $subs = get_posts([
            'post_type'      => 'tix_subscriber',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [['key' => '_tix_sub_consent', 'value' => '1']],
        ]);
        $ok = 0; $fail = 0; $errors = [];
        foreach ($subs as $s) {
            $email = get_post_meta($s->ID, '_tix_sub_email', true) ?: $s->post_title;
            if (!is_email($email)) { $fail++; continue; }
            $first = get_post_meta($s->ID, '_tix_sub_first_name', true);
            $last  = get_post_meta($s->ID, '_tix_sub_last_name', true);
            $order_id = intval(get_post_meta($s->ID, '_tix_sub_order_id', true));
            $list_ids = self::list_ids_for_order($order_id);
            if (empty($list_ids)) { $fail++; $errors[] = $email . ': keine passende Liste'; continue; }
            $r = self::add_contact($email, $first, $last, ['source' => 'tixomat_resync'], $list_ids);
            if ($r['success']) $ok++;
            else { $fail++; $errors[] = $email . ': ' . $r['message']; }
        }
        wp_send_json_success([
            'message' => sprintf('Resync fertig: %d OK, %d Fehler von %d Opt-In-Subscribers.', $ok, $fail, count($subs)),
            'ok'      => $ok,
            'fail'    => $fail,
            'total'   => count($subs),
            'errors'  => array_slice($errors, 0, 5),
        ]);
    }

    /* ─────────── AJAX: ALLE bezahlten Kaeufer importieren (One-Time) ─────────── */

    public static function ajax_import_all() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        if (!self::is_configured()) wp_send_json_error(['message' => 'Brevo nicht konfiguriert.']);

        global $wpdb;
        // Alle bezahlten Bestellungen mit Email — pro Email die NEUESTE Order (für Mapping)
        $orders = $wpdb->get_results(
            "SELECT id, billing_email, billing_first_name, billing_last_name
             FROM {$wpdb->prefix}tix_orders
             WHERE status IN ('completed','processing')
               AND billing_email <> ''
             ORDER BY id ASC"
        );

        // Dedupe nach Email — pro Email werden ALLE Order-IDs gesammelt damit
        // alle passenden Mapping-Listen-IDs vereinigt werden (Käufer war in mehreren Events).
        $by_email = [];
        foreach ($orders as $o) {
            $em = strtolower(trim($o->billing_email));
            if (!is_email($em)) continue;
            if (!isset($by_email[$em])) {
                $by_email[$em] = [
                    'first'     => $o->billing_first_name,
                    'last'      => $o->billing_last_name,
                    'order_ids' => [],
                ];
            }
            $by_email[$em]['order_ids'][] = intval($o->id);
            // Update Name auf den letzten (neueste Order = beste Datenqualität)
            if ($o->billing_first_name) $by_email[$em]['first'] = $o->billing_first_name;
            if ($o->billing_last_name)  $by_email[$em]['last']  = $o->billing_last_name;
        }

        $ok = 0; $fail = 0; $errors = [];
        foreach ($by_email as $email => $info) {
            // Listen-IDs aus ALLEN Orders dieses Käufers sammeln + dedupen
            $all_lists = [];
            foreach ($info['order_ids'] as $oid) {
                foreach (self::list_ids_for_order($oid) as $lid) $all_lists[$lid] = true;
            }
            $list_ids = array_keys($all_lists);
            if (empty($list_ids)) { $fail++; $errors[] = $email . ': keine passende Liste'; continue; }
            $r = self::add_contact($email, $info['first'], $info['last'], [
                'source'    => 'tixomat_import_all',
                'order_ids' => implode(',', $info['order_ids']),
            ], $list_ids);
            if ($r['success']) $ok++;
            else { $fail++; $errors[] = $email . ': ' . $r['message']; }
        }
        wp_send_json_success([
            'message' => sprintf('Import fertig: %d OK, %d Fehler von %d eindeutigen Käufern (%d Bestellungen gesamt).',
                $ok, $fail, count($by_email), count($orders)),
            'ok'      => $ok,
            'fail'    => $fail,
            'total'   => count($by_email),
            'errors'  => array_slice($errors, 0, 5),
        ]);
    }

    /* ─────────── AJAX: WP-Users ohne Bestellung importieren ─────────── */

    public static function ajax_import_nonbuyers() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        if (!self::is_configured()) wp_send_json_error(['message' => 'Brevo nicht konfiguriert.']);
        $list_id = intval($_POST['list_id'] ?? 0);
        if ($list_id < 1) wp_send_json_error(['message' => 'Ziel-Listen-ID fehlt.']);

        global $wpdb;
        // WP-Customer-Users die KEINE bezahlte Bestellung haben
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.user_email,
                    COALESCE(fn.meta_value, '') AS first_name,
                    COALESCE(ln.meta_value, '') AS last_name
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} cap ON u.ID = cap.user_id AND cap.meta_key = %s AND cap.meta_value LIKE %s
             LEFT JOIN {$wpdb->usermeta} fn ON u.ID = fn.user_id AND fn.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} ln ON u.ID = ln.user_id AND ln.meta_key = 'last_name'
             WHERE u.user_email <> ''
               AND u.user_email NOT IN (
                   SELECT DISTINCT billing_email FROM {$wpdb->prefix}tix_orders
                   WHERE status IN ('completed','processing') AND billing_email <> ''
               )",
            $wpdb->prefix . 'capabilities',
            '%tix_customer%'
        ));

        $ok = 0; $fail = 0; $errors = [];
        foreach ($rows as $r) {
            $email = strtolower(trim($r->user_email));
            if (!is_email($email)) { $fail++; continue; }
            $res = self::add_contact($email, $r->first_name, $r->last_name, ['source' => 'tixomat_nonbuyers'], [$list_id]);
            if ($res['success']) $ok++;
            else { $fail++; $errors[] = $email . ': ' . $res['message']; }
        }
        wp_send_json_success([
            'message' => sprintf('Nicht-Käufer-Import: %d OK, %d Fehler von %d WP-Users ohne Bestellung → Liste #%d.',
                $ok, $fail, count($rows), $list_id),
            'errors'  => array_slice($errors, 0, 5),
        ]);
    }

    /* ─────────── AJAX: Externe Quelle importieren (z.B. Tippspiel-Teilnehmer) ─────────── */

    public static function ajax_import_external() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        if (!self::is_configured()) wp_send_json_error(['message' => 'Brevo nicht konfiguriert.']);
        $list_id = intval($_POST['list_id'] ?? 0);
        $source  = sanitize_key($_POST['source'] ?? '');
        if ($list_id < 1) wp_send_json_error(['message' => 'Ziel-Listen-ID fehlt.']);

        global $wpdb;
        $rows = [];
        if ($source === 'mfxxl_teilnehmer') {
            $tbl = $wpdb->prefix . 'mfxxl_teilnehmer';
            if ($wpdb->get_var("SHOW TABLES LIKE '$tbl'") !== $tbl) {
                wp_send_json_error(['message' => 'Tabelle ' . $tbl . ' existiert nicht.']);
            }
            // Nur mit DSGVO-Einwilligung
            $rows = $wpdb->get_results("SELECT email, name FROM $tbl WHERE email <> '' AND einwilligung_dsgvo = 1");
            // Name → first/last splitten
            foreach ($rows as $r) {
                $parts = preg_split('/\s+/', trim($r->name ?: ''), 2);
                $r->first_name = $parts[0] ?? '';
                $r->last_name  = $parts[1] ?? '';
            }
        } else {
            wp_send_json_error(['message' => 'Unbekannte Quelle: ' . $source]);
        }

        $ok = 0; $fail = 0; $errors = [];
        foreach ($rows as $r) {
            $email = strtolower(trim($r->email));
            if (!is_email($email)) { $fail++; continue; }
            $res = self::add_contact($email, $r->first_name, $r->last_name, ['source' => $source], [$list_id]);
            if ($res['success']) $ok++;
            else { $fail++; $errors[] = $email . ': ' . $res['message']; }
        }
        wp_send_json_success([
            'message' => sprintf('Import von %s: %d OK, %d Fehler von %d Datensätzen → Liste #%d.',
                $source, $ok, $fail, count($rows), $list_id),
            'errors'  => array_slice($errors, 0, 5),
        ]);
    }
}
