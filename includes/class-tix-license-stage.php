<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_License_Stage – Stufen der Lizenz auf der Kundenseite und ihre Sperren (1.38.566, Lizenz-Fernschalter).
 *
 *   active  – alles nach Paket/Extras
 *   notice  – wie active, dazu Hinweis (Admin, Studio, Veranstalter-Bereich) ab 14 Tagen vor Ablauf
 *   locked  – kein Verkauf, keine Änderungen (alle schreibenden Routen außer Einlass, Anmeldung, Support,
 *             Lizenz, Export); Einlass und „Meine Tickets“ laufen bis zum letzten Event mit verkauften Tickets
 *   basis   – Basis-Modus: nur /info, /license, /basis, Tickets lesen und Export
 *
 * Die Stufe kommt signiert vom Lizenzserver (TIX_License::check). Durchgesetzt wird nur, wenn die Lizenz
 * `enforce` trägt. Ist der Server nicht erreichbar, gilt der letzte signierte Stand 7 Tage weiter
 * (TIX_License::GRACE_DAYS); danach rechnet die Seite selbst aus dem bekannten Ablaufdatum. Von „locked“ nach
 * „basis“ geht es immer lokal, sobald das letzte Event mit verkauften Tickets vorbei ist.
 * Seiten ohne Lizenzprüfung (Betriebsart none: evendis.de, KitchenKlub, Mallorca) sind immer active.
 */
class TIX_License_Stage {

    const NS = 'tixomat/v1';

    /** Schreibend erlaubt in der Sperre (Einlass, Anmeldung, Support, Lizenz, Export, Push-Registrierung, Fehlerprotokoll). */
    const LOCKED_ALLOW = '#^/(auth|checkin|license|push|customer/support|support|app/log|invoices/\d+/export)(/|$)#';

    /** Im Basis-Modus überhaupt erreichbar (lesend, plus Anmeldung für den Export). */
    const BASIS_READ  = '#^/(info|license|basis|customer/tickets|me|organizer/export|events/\d+/report/export)(/|$)#';
    const BASIS_WRITE = '#^/(auth|license)(/|$)#';

    /** Web-Kasse (admin-ajax) – in Sperre und Basis aus. */
    const AJAX_SALES = ['tix_native_checkout', 'tix_native_add_to_cart', 'tix_mc_checkout_form', 'tix_update_cart', 'tix_native_update_qty'];

    public static function init() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard_rest'], 4, 3);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_init', [__CLASS__, 'guard_ajax'], 1);
        add_action('admin_notices', [__CLASS__, 'notice']);
        // Sperre/Basis: Online-Verkauf gilt überall als aus (Website-Knöpfe, /public/events, Apps) – außer im WP-Admin,
        // damit beim Speichern eines Events der echte Wert erhalten bleibt (1.38.570)
        add_filter('get_post_metadata', [__CLASS__, 'tickets_meta'], 10, 4);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat license', [__CLASS__, 'cli']);
    }

    /**
     * Notfall auf der Kundenseite (Paket L10):
     *   wp tixomat license status   – Betriebsart, Stufe, letzter Heartbeat
     *   wp tixomat license check    – sofort beim Lizenzserver prüfen
     *   wp tixomat license unlock   – Sperre hier aufheben, bis der nächste signierte Heartbeat kommt
     *                                 (enforce aus; z. B. wenn der Lizenzserver fehlerhaft sperrt)
     */
    public static function cli($args) {
        $do = $args[0] ?? 'status';
        if ($do === 'check' && class_exists('TIX_License')) TIX_License::check();
        if ($do === 'unlock' && class_exists('TIX_License')) {
            $s = TIX_License::status();
            $s['enforce'] = false;
            $s['stage'] = 'active';
            update_option(TIX_License::OPT_STATUS, $s, false);
            WP_CLI::success('Sperre aufgehoben. Der nächste signierte Heartbeat setzt den Stand vom Lizenzserver.');
        }
        self::reset();
        $p = self::payload();
        $s = class_exists('TIX_License') ? TIX_License::status() : [];
        WP_CLI::line(sprintf('Betriebsart %s · Stufe %s · durchgesetzt %s · signiert %s · Ablauf %s', $p['mode'], $p['stage'], $p['enforced'] ? 'ja' : 'nein',
            !empty($s['signed_at']) ? wp_date('d.m.Y H:i', intval($s['signed_at'])) : '–', (string) ($s['expires_at'] ?? '–')));
    }

    // ═══════════════ Stufe ═══════════════

    public static function enforced() {
        if (!class_exists('TIX_License') || !TIX_License::active()) return false;
        return !empty(TIX_License::status()['enforce']);
    }

    private static $cached = null;

    /** Aktuelle Stufe (immer active, wenn nicht durchgesetzt); je Anfrage zwischengespeichert. */
    public static function stage($now = null) {
        if ($now === null && self::$cached !== null) return self::$cached;
        $s = self::enforced() ? self::compute(TIX_License::status(), $now ?: time(), self::last_event_date()) : 'active';
        if ($now === null) self::$cached = $s;
        return $s;
    }

    /** Zwischenspeicher leeren (nach check(), in Tests). */
    public static function reset() {
        self::$cached = null;
    }

    public static function tickets_meta($value, $object_id, $meta_key, $single) {
        if ($meta_key !== '_tix_tickets_enabled' || is_admin()) return $value;
        $st = self::stage();
        if ($st !== 'locked' && $st !== 'basis') return $value;
        return $single ? '0' : ['0'];
    }

    /**
     * Reine Berechnung (testbar): letzter signierter Stand + Frist, sonst selbst aus Ablaufdatum.
     * $local_led = letztes Event mit verkauften Tickets laut dieser Seite.
     */
    public static function compute(array $s, $now, $local_led) {
        $today = wp_date('Y-m-d', $now);
        $stage = in_array($s['stage'] ?? '', ['active', 'notice', 'locked', 'basis'], true) ? $s['stage'] : 'active';
        $fresh = !empty($s['signed_at']) && ($now - intval($s['signed_at'])) < TIX_License::GRACE_DAYS * DAY_IN_SECONDS;
        if (!$fresh) {
            $exp = (string) ($s['expires_at'] ?? '');
            if (($exp !== '' && $exp < $today) || in_array($stage, ['locked', 'basis'], true)) {
                $stage = $stage === 'basis' ? 'basis' : 'locked';
            } elseif ($exp !== '' && (strtotime($exp) - strtotime($today)) <= 14 * DAY_IN_SECONDS) {
                $stage = 'notice';
            }
        }
        if ($stage === 'locked') {
            $led = max((string) ($s['last_event_date'] ?? ''), (string) $local_led);
            if ($led === '' || $led < $today) $stage = 'basis';
        }
        return $stage;
    }

    /** Letztes Event (Ende, sonst Beginn) mit mindestens einem verkauften Ticket, Y-m-d oder null. 1 h zwischengespeichert. */
    public static function last_event_date() {
        $c = get_transient('tix_last_sold_event');
        if ($c !== false) return $c === '' ? null : $c;
        global $wpdb;
        $pm = $wpdb->postmeta;
        $d = $wpdb->get_var("SELECT MAX(GREATEST(COALESCE(NULLIF(pe.meta_value, ''), ps.meta_value), ps.meta_value))
            FROM $pm tm
            JOIN {$wpdb->posts} t ON t.ID = tm.post_id AND t.post_type = 'tix_ticket' AND t.post_status NOT IN ('trash', 'auto-draft')
            JOIN $pm ps ON ps.post_id = tm.meta_value AND ps.meta_key = '_tix_date_start'
            LEFT JOIN $pm pe ON pe.post_id = tm.meta_value AND pe.meta_key = '_tix_date_end'
            WHERE tm.meta_key = '_tix_ticket_event_id'");
        $d = is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? substr($d, 0, 10) : '';
        set_transient('tix_last_sold_event', $d, HOUR_IN_SECONDS);
        return $d === '' ? null : $d;
    }

    /** `/info.license` (öffentlich) bzw. `/me.license`. */
    public static function payload() {
        $s = class_exists('TIX_License') ? TIX_License::status() : [];
        $stage = self::stage();
        $basis = (string) ($s['basis_url'] ?? '');
        return [
            'mode'            => class_exists('TIX_License') ? TIX_License::mode() : 'none',
            'enforced'        => self::enforced(),
            'stage'           => $stage,
            'expires_at'      => self::enforced() ? ($s['expires_at'] ?? null) : null,
            'notice_from'     => self::enforced() ? ($s['notice_from'] ?? null) : null,
            'last_event_date' => self::enforced() ? self::last_event_date() : null,
            'basis_url'       => $basis !== '' ? $basis : (self::enforced() ? home_url('/app.json') : null),
            'message'         => self::message($stage),
        ];
    }

    public static function message($stage) {
        $s = class_exists('TIX_License') ? TIX_License::status() : [];
        $exp = !empty($s['expires_at']) ? wp_date('d.m.Y', strtotime($s['expires_at'])) : '';
        switch ($stage) {
            case 'notice': return 'Die Lizenz läuft' . ($exp ? ' am ' . $exp : '') . ' ab. Bitte rechtzeitig verlängern.';
            case 'locked': return 'Die Lizenz ist abgelaufen. Verkauf und Änderungen sind gesperrt; Einlass und gekaufte Tickets laufen weiter.';
            case 'basis':  return 'Basis-Modus: Die Seite zeigt nur noch Infos. Daten können jederzeit exportiert werden.';
        }
        return '';
    }

    // ═══════════════ Durchsetzung ═══════════════

    /** Ist die Anfrage in dieser Stufe erlaubt? (rel = Route ohne Namespace) */
    public static function allowed($stage, $method, $rel) {
        $write = strtoupper($method) !== 'GET' && strtoupper($method) !== 'HEAD';
        if ($stage === 'locked') return !$write || (bool) preg_match(self::LOCKED_ALLOW, $rel);
        if ($stage === 'basis') return $write ? (bool) preg_match(self::BASIS_WRITE, $rel) : (bool) preg_match(self::BASIS_READ, $rel);
        return true;
    }

    public static function guard_rest($result, $server, $request) {
        if ($result !== null || !($request instanceof WP_REST_Request)) return $result;
        $route = (string) $request->get_route();
        if (strpos($route, '/' . self::NS . '/') !== 0) return $result;
        $stage = self::stage();
        if ($stage === 'active' || $stage === 'notice') return $result;
        $rel = substr($route, strlen('/' . self::NS));
        if (self::allowed($stage, $request->get_method(), $rel)) return $result;
        return new WP_Error($stage === 'basis' ? 'tix_license_basis' : 'tix_license_locked', self::message($stage), ['status' => 403, 'stage' => $stage]);
    }

    public static function guard_ajax() {
        if (!wp_doing_ajax()) return;
        $action = sanitize_key((string) ($_REQUEST['action'] ?? ''));
        if (!in_array($action, self::AJAX_SALES, true)) return;
        $stage = self::stage();
        if ($stage === 'locked' || $stage === 'basis') {
            wp_send_json_error(['message' => 'Der Ticketverkauf ist auf dieser Seite gerade nicht möglich.', 'code' => 'tix_license_' . $stage], 403);
        }
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        // Lizenzserver bittet um sofortige Prüfung („jetzt prüfen“). Löst nur einen Abruf beim Lizenzserver aus.
        register_rest_route(self::NS, '/license/ping', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_ping'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/license/status', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_status'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
    }

    public static function rest_ping(WP_REST_Request $req) {
        if (!class_exists('TIX_License') || !TIX_License::active()) return rest_ensure_response(['ok' => true, 'stage' => 'active', 'checked' => false]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('lic_ping', 5, 600)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Anfragen.', ['status' => 429]);
        }
        TIX_License::check();
        delete_transient('tix_last_sold_event');
        self::reset();
        return rest_ensure_response(['ok' => true, 'stage' => self::stage(), 'checked' => true]);
    }

    public static function rest_status(WP_REST_Request $req) {
        $s = class_exists('TIX_License') ? TIX_License::status() : [];
        unset($s['package_url']);
        return rest_ensure_response(['ok' => true, 'license' => self::payload(), 'raw' => $s]);
    }

    // ═══════════════ Admin ═══════════════

    public static function notice() {
        if (!current_user_can('manage_options')) return;
        $stage = self::stage();
        if ($stage === 'active') return;
        $cls = $stage === 'notice' ? 'notice-info' : 'notice-error';
        echo '<div class="notice ' . esc_attr($cls) . '"><p><strong>Tixomat:</strong> ' . esc_html(self::message($stage)) . '</p></div>';
    }
}
