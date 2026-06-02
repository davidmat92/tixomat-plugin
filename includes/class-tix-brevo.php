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
        add_action('wp_ajax_tix_brevo_test',   [__CLASS__, 'ajax_test']);
        add_action('wp_ajax_tix_brevo_resync', [__CLASS__, 'ajax_resync']);
    }

    /* ─────────── Bereitschaft ─────────── */

    public static function is_configured(): bool {
        return !empty(tix_get_settings('brevo_enabled'))
            && !empty(tix_get_settings('brevo_api_key'))
            && intval(tix_get_settings('brevo_list_id')) > 0;
    }

    /* ─────────── HOOKS ─────────── */

    public static function handle_native_optin($order_id, $email, $first_name, $last_name) {
        if (!self::is_configured()) return;
        self::add_contact($email, $first_name, $last_name, [
            'source'   => 'tixomat_native_checkout',
            'order_id' => intval($order_id),
        ]);
    }

    public static function handle_subscriber_save($post_id, $post, $update) {
        if (!self::is_configured()) return;
        if ($post->post_status !== 'publish') return;
        if (wp_is_post_revision($post_id)) return;
        $email = get_post_meta($post_id, '_tix_sub_email', true) ?: $post->post_title;
        if (!is_email($email)) return;
        // Nur opt-in
        $opt_in = get_post_meta($post_id, '_tix_sub_consent', true);
        if (!$opt_in) return;
        $first = (string) get_post_meta($post_id, '_tix_sub_first_name', true);
        $last  = (string) get_post_meta($post_id, '_tix_sub_last_name', true);
        self::add_contact($email, $first, $last, [
            'source'        => 'tixomat_subscriber_cpt',
            'subscriber_id' => intval($post_id),
        ]);
    }

    /* ─────────── API: Kontakt hinzufügen / aktualisieren ─────────── */

    /**
     * Fügt einen Kontakt zur konfigurierten Brevo-Liste hinzu (oder aktualisiert ihn).
     * @return array{success: bool, message: string, http?: int}
     */
    public static function add_contact(string $email, string $first = '', string $last = '', array $extra = []): array {
        if (!self::is_configured()) return ['success' => false, 'message' => 'Brevo nicht konfiguriert.'];
        $email = strtolower(trim(sanitize_email($email)));
        if (!is_email($email)) return ['success' => false, 'message' => 'Ungültige E-Mail.'];

        $api_key  = trim(tix_get_settings('brevo_api_key'));
        $list_id  = intval(tix_get_settings('brevo_list_id'));

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
            'listIds'       => [$list_id],
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
            $r = self::add_contact($email, $first, $last, ['source' => 'tixomat_resync']);
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
}
