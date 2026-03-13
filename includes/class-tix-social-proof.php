<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Social_Proof – "X Personen sehen sich dieses Event gerade an"
 *
 * Heartbeat-basierter Live-Viewer-Count auf Event-Seiten.
 * Nutzt WordPress-Transients zur Speicherung.
 *
 * @since 1.28.90
 */
class TIX_Social_Proof {

    const TRANSIENT_PREFIX = 'tix_viewers_';
    const VIEWER_TTL       = 300; // 5 Minuten

    public static function init() {
        // AJAX Heartbeat
        add_action('wp_ajax_tix_heartbeat', [__CLASS__, 'handle_heartbeat']);
        add_action('wp_ajax_nopriv_tix_heartbeat', [__CLASS__, 'handle_heartbeat']);

        // Frontend: Assets auf Event-Seiten laden
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Assets nur auf Event-Seiten laden.
     */
    public static function enqueue_assets() {
        if (!is_singular('event')) return;

        $post_id = get_the_ID();
        $s = TIX_Settings::get();

        wp_enqueue_style('tix-social-proof', TIXOMAT_URL . 'assets/css/social-proof.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-social-proof', TIXOMAT_URL . 'assets/js/social-proof.js', [], TIXOMAT_VERSION, true);

        wp_localize_script('tix-social-proof', 'tixSocialProof', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'eventId'    => $post_id,
            'nonce'      => wp_create_nonce('tix_heartbeat'),
            'interval'   => intval($s['social_proof_interval'] ?? 30) * 1000,
            'text'       => $s['social_proof_text'] ?? '{count} Personen sehen sich dieses Event gerade an',
            'minCount'   => intval($s['social_proof_min_count'] ?? 2),
            'position'   => $s['social_proof_position'] ?? 'above_tickets',
        ]);
    }

    /**
     * AJAX Heartbeat: Viewer registrieren und Count zurückgeben.
     */
    public static function handle_heartbeat() {
        check_ajax_referer('tix_heartbeat', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error();

        $s = TIX_Settings::get();
        $multiplier = max(1.0, floatval($s['social_proof_multiplier'] ?? 1.5));

        // Viewer-Hash generieren (Session + IP)
        $hash = md5(($_POST['session_hash'] ?? '') . '_' . self::get_client_ip());

        // Aktuelle Viewer laden
        $transient_key = self::TRANSIENT_PREFIX . $event_id;
        $viewers = get_transient($transient_key);
        if (!is_array($viewers)) $viewers = [];

        $now = time();

        // Abgelaufene Einträge entfernen (> 5 Minuten)
        $viewers = array_filter($viewers, function($ts) use ($now) {
            return ($now - $ts) < self::VIEWER_TTL;
        });

        // Aktuellen Viewer hinzufügen/aktualisieren
        $viewers[$hash] = $now;

        // Speichern
        set_transient($transient_key, $viewers, self::VIEWER_TTL + 60);

        // Count berechnen (mit Multiplikator)
        $raw_count = count($viewers);
        $display_count = max(1, intval(round($raw_count * $multiplier)));

        wp_send_json_success([
            'count'   => $display_count,
            'raw'     => $raw_count,
        ]);
    }

    /**
     * Client-IP ermitteln.
     */
    private static function get_client_ip() {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }
}
