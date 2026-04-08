<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Bot_Bridge – AJAX-Registrierung beim Python Hub.
 *
 * Auth-Konzept:
 *   bot_hub_master_key  → Registrierung + eigene Seite abmelden (hat jede Seite)
 *   bot_hub_admin_key   → Tenant-Liste + beliebige Tenants loeschen (hat nur der Plattform-Admin)
 */
class TIX_Bot_Bridge {

    public static function init() {
        add_action('wp_ajax_tix_bot_register',      [__CLASS__, 'ajax_register']);
        add_action('wp_ajax_tix_bot_unregister',    [__CLASS__, 'ajax_unregister']);
        add_action('wp_ajax_tix_bot_test',          [__CLASS__, 'ajax_test']);
        add_action('wp_ajax_tix_bot_list_tenants',  [__CLASS__, 'ajax_list_tenants']);
        add_action('wp_ajax_tix_bot_remove_tenant', [__CLASS__, 'ajax_remove_tenant']);
    }

    // ═══════════════════════════════════════════════════
    //  Registrierung beim Hub
    // ═══════════════════════════════════════════════════

    public static function ajax_register() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Falls Formularwerte mitgeschickt wurden, erst in DB speichern
        $live_fields = ['bot_hub_url', 'bot_hub_master_key', 'bot_hub_admin_key', 'bot_api_secret'];
        $settings = get_option('tix_settings', []);
        $changed  = false;
        foreach ($live_fields as $f) {
            if (isset($_POST[$f]) && $_POST[$f] !== '') {
                $settings[$f] = sanitize_text_field(wp_unslash($_POST[$f]));
                $changed = true;
            }
        }
        if ($changed) {
            update_option('tix_settings', $settings);
        }

        $s       = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        $hub_url = $s['bot_hub_url'] ?? '';

        if (!$hub_url) {
            wp_send_json_error('Hub-URL nicht konfiguriert.');
        }

        $payload = [
            'site_url'          => home_url(),
            'site_name'         => get_bloginfo('name'),
            'api_url'           => rest_url('tix-bot/v1'),
            'api_secret'        => $s['bot_api_secret'] ?? '',
            'telegram_token'    => $s['bot_telegram_token'] ?? '',
            'whatsapp_token'    => $s['bot_whatsapp_token'] ?? '',
            'whatsapp_phone_id' => $s['bot_whatsapp_phone_id'] ?? '',
            'whatsapp_verify'   => $s['bot_whatsapp_verify'] ?? '',
            'anthropic_key'     => $s['bot_anthropic_key'] ?? '',
            'bot_name'          => $s['bot_name'] ?? 'Ticket-Assistent',
            'bot_greeting'      => $s['bot_greeting'] ?? '',
            'bot_personality'   => $s['bot_personality'] ?? '',
            'channels'          => [
                'webchat'  => !empty($s['bot_webchat_enabled']),
                'telegram' => !empty($s['bot_telegram_enabled']),
                'whatsapp' => !empty($s['bot_whatsapp_enabled']),
            ],
        ];

        $response = wp_remote_post(trailingslashit($hub_url) . 'tenants/register', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Hub-Key'    => $s['bot_hub_master_key'] ?? '',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Verbindungsfehler: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['ok']) && !empty($body['tenant_id'])) {
            $settings = get_option('tix_settings', []);
            $settings['bot_tenant_id']  = sanitize_text_field($body['tenant_id']);
            $settings['bot_registered'] = 1;
            update_option('tix_settings', $settings);

            wp_send_json_success([
                'tenant_id' => $body['tenant_id'],
                'message'   => 'Registrierung erfolgreich!',
            ]);
        } else {
            $error_msg = $body['error'] ?? $body['detail'] ?? 'Registrierung fehlgeschlagen.';
            if ($code >= 400) {
                $error_msg .= ' (HTTP ' . $code . ')';
            }
            wp_send_json_error($error_msg);
        }
    }

    // ═══════════════════════════════════════════════════
    //  Eigene Seite vom Hub abmelden (mit Ownership-Pruefung)
    // ═══════════════════════════════════════════════════

    public static function ajax_unregister() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $s         = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        $hub_url   = $s['bot_hub_url'] ?? '';
        $tenant_id = $s['bot_tenant_id'] ?? '';

        if (!$hub_url || !$tenant_id) {
            wp_send_json_error('Hub-URL oder Tenant-ID fehlt.');
        }

        // Eigenen api_secret mitsenden fuer Ownership-Pruefung auf dem Hub
        $response = wp_remote_post(trailingslashit($hub_url) . 'tenants/unregister', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Hub-Key'    => $s['bot_hub_master_key'] ?? '',
            ],
            'body' => wp_json_encode([
                'tenant_id'  => $tenant_id,
                'api_secret' => $s['bot_api_secret'] ?? '',
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Verbindungsfehler: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Status zuruecksetzen
        $settings = get_option('tix_settings', []);
        $settings['bot_registered'] = 0;
        $settings['bot_tenant_id']  = '';
        update_option('tix_settings', $settings);

        wp_send_json_success([
            'message' => 'Bot wurde abgemeldet.',
        ]);
    }

    // ═══════════════════════════════════════════════════
    //  Alle Tenants vom Hub laden (nur mit Admin-Key)
    // ═══════════════════════════════════════════════════

    public static function ajax_list_tenants() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $s         = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        $hub_url   = $s['bot_hub_url'] ?? '';
        $admin_key = $s['bot_hub_admin_key'] ?? '';

        if (!$hub_url || !$admin_key) {
            wp_send_json_error('no_admin_key');
        }

        $response = wp_remote_get(trailingslashit($hub_url) . 'tenants/list', [
            'timeout' => 15,
            'headers' => [
                'X-Hub-Admin-Key' => $admin_key,
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Verbindungsfehler: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['ok'])) {
            wp_send_json_success([
                'tenants'    => $body['tenants'] ?? [],
                'count'      => $body['count'] ?? 0,
                'own_tenant' => $s['bot_tenant_id'] ?? '',
            ]);
        } else {
            wp_send_json_error($body['error'] ?? 'Tenants konnten nicht geladen werden.');
        }
    }

    // ═══════════════════════════════════════════════════
    //  Beliebigen Tenant vom Hub entfernen (nur mit Admin-Key)
    // ═══════════════════════════════════════════════════

    public static function ajax_remove_tenant() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $tenant_id = sanitize_text_field($_POST['tenant_id'] ?? '');
        if (!$tenant_id) {
            wp_send_json_error('Tenant-ID fehlt.');
        }

        $s         = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        $hub_url   = $s['bot_hub_url'] ?? '';
        $admin_key = $s['bot_hub_admin_key'] ?? '';

        if (!$hub_url || !$admin_key) {
            wp_send_json_error('Hub-URL oder Admin-Key nicht konfiguriert.');
        }

        $response = wp_remote_post(trailingslashit($hub_url) . 'tenants/unregister', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'   => 'application/json',
                'X-Hub-Admin-Key' => $admin_key,
            ],
            'body' => wp_json_encode(['tenant_id' => $tenant_id]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Verbindungsfehler: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Wenn der geloeschte Tenant unsere eigene Seite war, Status zuruecksetzen
        $own_tenant = $s['bot_tenant_id'] ?? '';
        if ($tenant_id === $own_tenant) {
            $settings = get_option('tix_settings', []);
            $settings['bot_registered'] = 0;
            $settings['bot_tenant_id']  = '';
            update_option('tix_settings', $settings);
        }

        if (!empty($body['ok'])) {
            wp_send_json_success(['message' => 'Tenant wurde entfernt.']);
        } else {
            wp_send_json_error($body['error'] ?? 'Tenant konnte nicht entfernt werden.');
        }
    }

    // ═══════════════════════════════════════════════════
    //  Verbindungstest
    // ═══════════════════════════════════════════════════

    public static function ajax_test() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $s       = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        $hub_url = $s['bot_hub_url'] ?? '';

        if (!$hub_url) {
            wp_send_json_error('Hub-URL nicht konfiguriert.');
        }

        $response = wp_remote_get(trailingslashit($hub_url) . 'health', [
            'timeout' => 15,
            'headers' => [
                'X-Hub-Key' => $s['bot_hub_master_key'] ?? '',
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Verbindungsfehler: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body)) {
            wp_send_json_success([
                'message' => 'Hub erreichbar.',
                'status'  => $body,
            ]);
        } else {
            wp_send_json_error('Hub nicht erreichbar (HTTP ' . $code . ').');
        }
    }
}
