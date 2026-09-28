<?php
/**
 * Leichtes App-Fehlerprotokoll (selbst gehostet, kein Fremd-Dienst).
 * Die App meldet nicht abgefangene Fehler an POST /app/log; Admins sehen sie
 * unter GET /app/log (im Dashboard-Werkzeug „Fehlerprotokoll"). Ringpuffer in
 * einer Option, keine personenbezogenen Daten.
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Log {
    const NS  = 'tixomat/v1';
    const OPT = '_tix_app_logs';
    const MAX = 100;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/app/log', [
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'add'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list'],
                'permission_callback' => [__CLASS__, 'admin'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [__CLASS__, 'clear'],
                'permission_callback' => [__CLASS__, 'admin'],
            ],
        ]);
    }

    public static function admin($req) {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', 'Nur Admins.', ['status' => 403]);
        }
        return true;
    }

    private static function client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) ? $ip : '';
    }

    public static function add(WP_REST_Request $req) {
        // Rate-Limit je IP: max ~30 Meldungen / 5 min (Schutz vor Spam).
        $rk = '_tix_log_rl_' . md5(self::client_ip());
        $c  = (int) get_transient($rk);
        if ($c > 30) return new WP_REST_Response(['ok' => true], 200);
        set_transient($rk, $c + 1, 300);

        $logs = get_option(self::OPT, []);
        if (!is_array($logs)) $logs = [];

        $logs[] = [
            'ts'       => current_time('mysql'),
            'message'  => mb_substr(sanitize_textarea_field((string) $req->get_param('message')), 0, 300),
            'stack'    => mb_substr(sanitize_textarea_field((string) $req->get_param('stack')), 0, 1500),
            'where'    => mb_substr(sanitize_text_field((string) $req->get_param('where')), 0, 120),
            'platform' => mb_substr(sanitize_text_field((string) $req->get_param('platform')), 0, 20),
            'version'  => mb_substr(sanitize_text_field((string) $req->get_param('version')), 0, 40),
        ];
        if (count($logs) > self::MAX) $logs = array_slice($logs, -self::MAX);
        update_option(self::OPT, $logs, false);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function list(WP_REST_Request $req) {
        $logs = get_option(self::OPT, []);
        if (!is_array($logs)) $logs = [];
        return new WP_REST_Response(['ok' => true, 'logs' => array_reverse($logs)], 200);
    }

    public static function clear(WP_REST_Request $req) {
        delete_option(self::OPT);
        return new WP_REST_Response(['ok' => true], 200);
    }
}
