<?php
/**
 * TIX Rate Limiter
 *
 * Simple IP-basiertes Rate-Limiting für Checkout- und Cart-Endpoints.
 * Nutzt Transients als Backend (kein zusätzliches Setup nötig).
 * Admins sind ausgenommen.
 */
if (!defined('ABSPATH')) exit;

class TIX_Rate_Limit {

    /**
     * Prüft ob der aktuelle Request das Limit überschreitet.
     * Bei Überschreitung sofort abbrechen (AJAX-Error oder wp_die).
     *
     * @param string $bucket        Identifier (z.B. "checkout", "add_to_cart")
     * @param int    $max_requests  Max Requests im Window
     * @param int    $window_seconds Zeitfenster in Sekunden
     * @param string $response_type "ajax" | "die"
     * @return bool true wenn OK, false / exit bei Überschreitung
     */
    public static function check($bucket, $max_requests = 10, $window_seconds = 60, $response_type = 'ajax') {
        // Admins sind exempt
        if (current_user_can('manage_options')) return true;

        $ip = self::get_ip();
        if (!$ip) return true; // Im Zweifel durchlassen (kein IP verfügbar)

        $key = 'tix_rl_' . $bucket . '_' . md5($ip);
        $data = get_transient($key);

        if (!is_array($data)) {
            $data = ['count' => 0, 'first' => time()];
        }

        // Window abgelaufen → zurücksetzen
        if (time() - $data['first'] >= $window_seconds) {
            $data = ['count' => 0, 'first' => time()];
        }

        $data['count']++;
        set_transient($key, $data, $window_seconds);

        if ($data['count'] > $max_requests) {
            $retry_after = $window_seconds - (time() - $data['first']);
            self::block($response_type, $bucket, $retry_after);
            return false;
        }

        return true;
    }

    /**
     * Blockt den Request.
     */
    private static function block($response_type, $bucket, $retry_after) {
        if ($response_type === 'die') {
            status_header(429);
            header('Retry-After: ' . max(1, $retry_after));
            wp_die(
                'Zu viele Anfragen. Bitte warte ' . $retry_after . ' Sekunden und versuche es erneut.',
                'Rate Limit überschritten',
                ['response' => 429]
            );
        }

        // AJAX: JSON-Error mit 429
        status_header(429);
        wp_send_json_error([
            'message'     => 'Zu viele Anfragen. Bitte warte kurz und versuche es erneut.',
            'retry_after' => max(1, $retry_after),
            'bucket'      => $bucket,
        ]);
    }

    /**
     * Ermittelt die Client-IP (berücksichtigt Proxies/CloudFlare/RunCloud).
     */
    private static function get_ip() {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ($candidates as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = explode(',', $_SERVER[$h])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '';
    }
}
