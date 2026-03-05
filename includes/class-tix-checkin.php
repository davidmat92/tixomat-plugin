<?php
if (!defined('ABSPATH')) exit;

class TIX_Checkin {

    public static function init() {
        add_shortcode('tix_checkin', [__CLASS__, 'render']);

        // AJAX: auch für nicht-eingeloggte User (Türpersonal)
        add_action('wp_ajax_tix_guest_validate',        [__CLASS__, 'ajax_validate']);
        add_action('wp_ajax_nopriv_tix_guest_validate',  [__CLASS__, 'ajax_validate']);
        add_action('wp_ajax_tix_guest_list_status',      [__CLASS__, 'ajax_list_status']);
        add_action('wp_ajax_nopriv_tix_guest_list_status',[__CLASS__, 'ajax_list_status']);
        add_action('wp_ajax_tix_guest_update_checkin',        [__CLASS__, 'ajax_update_checkin']);
        add_action('wp_ajax_nopriv_tix_guest_update_checkin', [__CLASS__, 'ajax_update_checkin']);

        // Ticket-Check-in AJAX (Gekaufte Tickets)
        add_action('wp_ajax_tix_checkin_combined_list',        [__CLASS__, 'ajax_combined_list']);
        add_action('wp_ajax_nopriv_tix_checkin_combined_list', [__CLASS__, 'ajax_combined_list']);
        add_action('wp_ajax_tix_ticket_toggle_checkin',        [__CLASS__, 'ajax_ticket_toggle_checkin']);
        add_action('wp_ajax_nopriv_tix_ticket_toggle_checkin', [__CLASS__, 'ajax_ticket_toggle_checkin']);
    }

    // ──────────────────────────────────────────
    // Shortcode: [tix_checkin]
    // ──────────────────────────────────────────
    public static function render($atts = []) {
        $atts = shortcode_atts(['event_id' => 0], $atts, 'tix_checkin');

        self::enqueue();

        // Alle Events laden: mit Gästeliste ODER mit verkauften Tickets
        $guest_events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_query'     => [
                ['key' => '_tix_guest_list_enabled', 'value' => '1', 'compare' => '='],
            ],
            'fields'   => 'ids',
        ]);

        // Events mit verkauften Tickets
        global $wpdb;
        $ticket_event_ids = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_tix_ticket_event_id'
             AND p.post_type = 'tix_ticket' AND p.post_status = 'publish'"
        );
        $ticket_event_ids = array_map('intval', $ticket_event_ids);

        $all_event_ids = array_unique(array_merge($guest_events, $ticket_event_ids));

        $events = [];
        if (!empty($all_event_ids)) {
            $events = get_posts([
                'post_type'      => 'event',
                'post_status'    => 'publish',
                'post__in'       => $all_event_ids,
                'posts_per_page' => 100,
                'orderby'        => 'meta_value',
                'meta_key'       => '_tix_date_start',
                'order'          => 'DESC',
            ]);
        }

        ob_start();
        ?>
        <div class="tix-ci" id="tix-checkin-app">

            <!-- Event-Auswahl -->
            <div class="tix-ci-header">
                <h2 class="tix-ci-title">Tixomat &middot; Check-In</h2>
                <select id="tix-ci-event" class="tix-ci-select">
                    <option value="">Event wählen…</option>
                    <?php foreach ($events as $ev):
                        $date = get_post_meta($ev->ID, '_tix_date_start', true);
                        $date_fmt = $date ? date_i18n('d.m.', strtotime($date)) : '';
                        $selected = intval($atts['event_id']) === $ev->ID ? ' selected' : '';
                    ?>
                    <option value="<?php echo $ev->ID; ?>"<?php echo $selected; ?>>
                        <?php echo esc_html($date_fmt . ' ' . $ev->post_title); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Passwort -->
            <div class="tix-ci-auth" id="tix-ci-auth" style="display:none;">
                <div class="tix-ci-pw-row">
                    <input type="password" id="tix-ci-password" class="tix-ci-input" placeholder="Check-in Passwort" autocomplete="off">
                    <button type="button" id="tix-ci-pw-submit" class="tix-ci-btn">OK</button>
                </div>
                <div id="tix-ci-pw-error" class="tix-ci-pw-error" style="display:none;"></div>
            </div>

            <!-- Scanner (nach Auth) -->
            <div class="tix-ci-scanner" id="tix-ci-scanner" style="display:none;">

                <!-- Kamera -->
                <div class="tix-ci-camera-wrap">
                    <video id="tix-ci-video" class="tix-ci-video" autoplay playsinline muted></video>
                    <canvas id="tix-ci-canvas" style="display:none;"></canvas>
                    <div class="tix-ci-overlay">
                        <div class="tix-ci-crosshair"></div>
                    </div>
                </div>

                <!-- Manuell -->
                <div class="tix-ci-manual">
                    <input type="text" id="tix-ci-code" class="tix-ci-input" placeholder="Code eingeben (12-stellig oder GL-...)" autocomplete="off">
                    <button type="button" id="tix-ci-code-submit" class="tix-ci-btn">✓</button>
                </div>

                <!-- Ergebnis -->
                <div id="tix-ci-result" class="tix-ci-result" style="display:none;"></div>

                <!-- Liste (Gäste + Tickets) -->
                <div class="tix-ci-list-section">
                    <div class="tix-ci-list-header">
                        <h3 id="tix-ci-list-title">Check-in</h3>
                        <input type="text" id="tix-ci-search" class="tix-ci-input tix-ci-search" placeholder="Suche nach Name…">
                    </div>
                    <div id="tix-ci-filters" class="tix-ci-filters" style="display:none;">
                        <button type="button" class="tix-ci-filter-btn active" data-filter="all">Alle</button>
                        <button type="button" class="tix-ci-filter-btn" data-filter="guest">Gäste</button>
                        <button type="button" class="tix-ci-filter-btn" data-filter="ticket">Tickets</button>
                    </div>
                    <div id="tix-ci-list" class="tix-ci-list"></div>
                </div>

            </div>
            <?php echo tix_branding_footer(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function enqueue() {
        wp_enqueue_script('tix-jsqr', TIXOMAT_URL . 'assets/js/jsqr.min.js', [], '1.4.0', true);
        wp_enqueue_script('tix-checkin', TIXOMAT_URL . 'assets/js/checkin.js', ['tix-jsqr'], TIXOMAT_VERSION, true);
        wp_enqueue_style('tix-checkin', TIXOMAT_URL . 'assets/css/checkin.css', [], TIXOMAT_VERSION);

        $popup_sec = class_exists('TIX_Settings') ? intval(TIX_Settings::get('ci_popup_duration')) : 5;
        if ($popup_sec < 1) $popup_sec = 5;

        wp_localize_script('tix-checkin', 'ehCheckin', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('tix_checkin_action'),
            'popupDuration' => $popup_sec * 1000,
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: QR validieren + einchecken
    // ──────────────────────────────────────────
    public static function ajax_validate() {
        check_ajax_referer('tix_checkin_action', 'nonce');

        $code = strtoupper(sanitize_text_field($_POST['code'] ?? ''));

        // ── 1. Direkter Code (ohne GL-Prefix) ──
        // Alt: TIX-XXXXXX (10 Zeichen) | Neu: 12 alphanumerische Zeichen
        if (preg_match('/^(TIX-[A-Z2-9]{6}|[A-Z0-9]{12})$/', $code)) {
            self::validate_ticket_code($code);
            return; // validate_ticket_code sendet JSON
        }

        // ── 2. GL-Format (QR-Scan): GL-{EVENT_ID}-{CODE} ──
        if (preg_match('/^GL-(\d+)-([A-Z0-9-]{3,20})$/', $code, $m)) {
            $event_id   = intval($m[1]);
            $inner_code = $m[2];

            // Ticket-Lookup (Code-Teil könnte ein Ticket-Code sein)
            if (class_exists('TIX_Tickets')) {
                $ticket = TIX_Tickets::get_ticket_by_code($inner_code);
                if ($ticket) {
                    self::validate_ticket_code($inner_code);
                    return;
                }
            }

            // Kein Ticket → als Gast-Code weiterverarbeiten
            $guest_id = $inner_code;
        } else {
            // Kein gültiges Format erkannt
            wp_send_json_error(['message' => 'Ungültiger Code.', 'status' => 'invalid']);
            return;
        }

        // Passwort prüfen
        $password  = sanitize_text_field($_POST['password'] ?? '');
        $stored_pw = get_post_meta($event_id, '_tix_checkin_password', true);
        if ($stored_pw && $password !== $stored_pw) {
            wp_send_json_error(['message' => 'Falsches Passwort.', 'status' => 'unauthorized']);
        }

        // Gästeliste laden
        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) {
            wp_send_json_error(['message' => 'Keine Gästeliste.', 'status' => 'not_found']);
        }

        foreach ($guests as &$g) {
            if (($g['id'] ?? '') !== $guest_id) continue;

            $total_expected = 1 + intval($g['plus'] ?? 0);

            // Backward-Compat: bestehende Gäste mit checked_in aber ohne checked_in_count
            if (!empty($g['checked_in']) && !isset($g['checked_in_count'])) {
                $g['checked_in_count'] = $total_expected;
            }

            $current_count = intval($g['checked_in_count'] ?? 0);

            // Vollständig eingecheckt?
            if ($current_count >= $total_expected) {
                wp_send_json_success([
                    'status'          => 'already',
                    'name'            => $g['name'],
                    'plus'            => intval($g['plus'] ?? 0),
                    'note'            => $g['note'] ?? '',
                    'time'            => $g['checkin_time'] ?? '',
                    'checked_in_count' => $current_count,
                    'total_expected'  => $total_expected,
                    'message'         => 'Bereits eingecheckt.',
                ]);
            }

            // Teilweise eingecheckt?
            if ($current_count > 0 && $current_count < $total_expected) {
                wp_send_json_success([
                    'status'          => 'partial',
                    'name'            => $g['name'],
                    'plus'            => intval($g['plus'] ?? 0),
                    'note'            => $g['note'] ?? '',
                    'time'            => $g['checkin_time'] ?? '',
                    'checked_in_count' => $current_count,
                    'total_expected'  => $total_expected,
                    'message'         => 'Teilweise eingecheckt (' . $current_count . '/' . $total_expected . ').',
                ]);
            }

            // Neu einchecken → vollständig
            $g['checked_in']       = true;
            $g['checked_in_count'] = $total_expected;
            $g['checkin_time']     = current_time('c');
            $g['checkin_by']       = is_user_logged_in() ? wp_get_current_user()->user_login : 'door';

            update_post_meta($event_id, '_tix_guest_list', $guests);

            wp_send_json_success([
                'status'          => 'ok',
                'name'            => $g['name'],
                'plus'            => intval($g['plus'] ?? 0),
                'note'            => $g['note'] ?? '',
                'checked_in_count' => $total_expected,
                'total_expected'  => $total_expected,
                'message'         => 'Willkommen!',
            ]);
        }
        unset($g);

        wp_send_json_error(['message' => 'Nicht auf der Liste.', 'status' => 'not_found']);
    }

    // ──────────────────────────────────────────
    // AJAX: Gästeliste für Check-in-Seite
    // ──────────────────────────────────────────
    public static function ajax_list_status() {
        check_ajax_referer('tix_checkin_action', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error(['message' => 'Kein Event.']);

        // Passwort prüfen
        $password  = sanitize_text_field($_POST['password'] ?? '');
        $stored_pw = get_post_meta($event_id, '_tix_checkin_password', true);
        if ($stored_pw && $password !== $stored_pw) {
            wp_send_json_error(['message' => 'Falsches Passwort.', 'status' => 'unauthorized']);
        }

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) $guests = [];

        $total   = count($guests);
        $checked = 0;
        $partial = 0;
        $list    = [];

        foreach ($guests as $g) {
            $total_expected = 1 + intval($g['plus'] ?? 0);

            // Backward-Compat: bestehende Gäste mit checked_in aber ohne checked_in_count
            if (!empty($g['checked_in']) && !isset($g['checked_in_count'])) {
                $checked_in_count = $total_expected;
            } else {
                $checked_in_count = intval($g['checked_in_count'] ?? 0);
            }

            if (!empty($g['checked_in'])) $checked++;
            if ($checked_in_count > 0 && $checked_in_count < $total_expected) $partial++;

            $list[] = [
                'id'              => $g['id'] ?? '',
                'name'            => $g['name'] ?? '',
                'plus'            => intval($g['plus'] ?? 0),
                'note'            => $g['note'] ?? '',
                'checked_in'      => !empty($g['checked_in']),
                'checkin_time'    => $g['checkin_time'] ?? '',
                'checked_in_count' => $checked_in_count,
                'total_expected'  => $total_expected,
                'code'            => 'GL-' . $event_id . '-' . ($g['id'] ?? ''),
            ];
        }

        wp_send_json_success([
            'total'      => $total,
            'checked_in' => $checked,
            'partial'    => $partial,
            'open'       => $total - $checked,
            'guests'     => $list,
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Manueller Check-in von der Liste
    // ──────────────────────────────────────────
    public static function ajax_manual_checkin() {
        check_ajax_referer('tix_checkin_action', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        $guest_id = sanitize_text_field($_POST['guest_id'] ?? '');
        $password = sanitize_text_field($_POST['password'] ?? '');

        if (!$event_id || !$guest_id) wp_send_json_error(['message' => 'Ungültige Daten.']);

        $stored_pw = get_post_meta($event_id, '_tix_checkin_password', true);
        if ($stored_pw && $password !== $stored_pw) {
            wp_send_json_error(['message' => 'Falsches Passwort.', 'status' => 'unauthorized']);
        }

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) wp_send_json_error(['message' => 'Keine Gästeliste.']);

        foreach ($guests as &$g) {
            if (($g['id'] ?? '') !== $guest_id) continue;

            $total_expected = 1 + intval($g['plus'] ?? 0);

            // Backward-Compat
            if (!empty($g['checked_in']) && !isset($g['checked_in_count'])) {
                $g['checked_in_count'] = $total_expected;
            }

            $current_count = intval($g['checked_in_count'] ?? 0);

            if ($current_count >= $total_expected) {
                wp_send_json_success([
                    'status'           => 'already',
                    'name'             => $g['name'],
                    'checked_in_count' => $current_count,
                    'total_expected'   => $total_expected,
                    'message'          => 'Bereits eingecheckt.',
                ]);
            }

            if ($current_count > 0 && $current_count < $total_expected) {
                wp_send_json_success([
                    'status'           => 'partial',
                    'name'             => $g['name'],
                    'plus'             => intval($g['plus'] ?? 0),
                    'note'             => $g['note'] ?? '',
                    'time'             => $g['checkin_time'] ?? '',
                    'checked_in_count' => $current_count,
                    'total_expected'   => $total_expected,
                    'message'          => 'Teilweise eingecheckt (' . $current_count . '/' . $total_expected . ').',
                ]);
            }

            // Neu einchecken → vollständig
            $g['checked_in']       = true;
            $g['checked_in_count'] = $total_expected;
            $g['checkin_time']     = current_time('c');
            $g['checkin_by']       = is_user_logged_in() ? wp_get_current_user()->user_login : 'door';

            update_post_meta($event_id, '_tix_guest_list', $guests);

            wp_send_json_success([
                'status'           => 'ok',
                'name'             => $g['name'],
                'plus'             => intval($g['plus'] ?? 0),
                'note'             => $g['note'] ?? '',
                'checked_in_count' => $total_expected,
                'total_expected'   => $total_expected,
                'message'          => 'Willkommen!',
            ]);
        }
        unset($g);

        wp_send_json_error(['message' => 'Gast nicht gefunden.', 'status' => 'not_found']);
    }

    // ──────────────────────────────────────────
    // AJAX: Check-in-Zähler bearbeiten
    // ──────────────────────────────────────────
    public static function ajax_update_checkin() {
        check_ajax_referer('tix_checkin_action', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        $guest_id = sanitize_text_field($_POST['guest_id'] ?? '');
        $password = sanitize_text_field($_POST['password'] ?? '');
        $count    = intval($_POST['count'] ?? -1);

        if (!$event_id || !$guest_id) wp_send_json_error(['message' => 'Ungültige Daten.']);

        // Passwort prüfen
        $stored_pw = get_post_meta($event_id, '_tix_checkin_password', true);
        if ($stored_pw && $password !== $stored_pw) {
            wp_send_json_error(['message' => 'Falsches Passwort.', 'status' => 'unauthorized']);
        }

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) wp_send_json_error(['message' => 'Keine Gästeliste.']);

        foreach ($guests as &$g) {
            if (($g['id'] ?? '') !== $guest_id) continue;

            $total_expected = 1 + intval($g['plus'] ?? 0);

            // Count validieren: 0 ≤ count ≤ total_expected
            if ($count < 0 || $count > $total_expected) {
                wp_send_json_error(['message' => 'Ungültiger Wert.']);
            }

            $g['checked_in_count'] = $count;
            $g['checked_in']       = ($count > 0);

            if ($count === 0) {
                $g['checkin_time'] = '';
                $g['checkin_by']   = '';
            } elseif (empty($g['checkin_time'])) {
                $g['checkin_time'] = current_time('c');
                $g['checkin_by']   = is_user_logged_in() ? wp_get_current_user()->user_login : 'door';
            }

            update_post_meta($event_id, '_tix_guest_list', $guests);

            wp_send_json_success([
                'status'           => $count === 0 ? 'reset' : ($count >= $total_expected ? 'full' : 'partial'),
                'name'             => $g['name'],
                'checked_in_count' => $count,
                'total_expected'   => $total_expected,
                'message'          => $count === 0 ? 'Check-in zurückgesetzt.' : $count . '/' . $total_expected . ' eingecheckt.',
            ]);
        }
        unset($g);

        wp_send_json_error(['message' => 'Gast nicht gefunden.', 'status' => 'not_found']);
    }

    // ──────────────────────────────────────────
    // Gast-QR-Seite (Self-Service)
    // ──────────────────────────────────────────
    public static function render_guest_qr_page() {
        if (empty($_GET['tix_guest'])) return;

        $code = strtoupper(sanitize_text_field($_GET['tix_guest']));
        if (!preg_match('/^GL-(\d+)-([A-Z0-9-]{3,20})$/', $code, $m)) {
            wp_die('Ungültiger Gästeliste-Code.', 'Fehler', ['response' => 404]);
        }

        $event_id = intval($m[1]);
        $guest_id = $m[2];

        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event') {
            wp_die('Event nicht gefunden.', 'Fehler', ['response' => 404]);
        }

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) {
            wp_die('Keine Gästeliste.', 'Fehler', ['response' => 404]);
        }

        $guest = null;
        foreach ($guests as $g) {
            if (($g['id'] ?? '') === $guest_id) { $guest = $g; break; }
        }

        if (!$guest) {
            wp_die('Gast nicht gefunden.', 'Fehler', ['response' => 404]);
        }

        // Event-Daten
        $date_start = get_post_meta($event_id, '_tix_date_start', true);
        $time_doors = get_post_meta($event_id, '_tix_time_doors', true);
        $time_start = get_post_meta($event_id, '_tix_time_start', true);
        $location   = get_post_meta($event_id, '_tix_location', true);
        $date_fmt   = $date_start ? date_i18n('l, d. F Y', strtotime($date_start)) : '';

        $qr_js_url  = TIXOMAT_URL . 'assets/js/tix-qr.js';

        // Farben aus Einstellungen
        $ci = class_exists('TIX_Settings') ? TIX_Settings::get() : [];
        $bg      = !empty($ci['ci_bg'])      ? $ci['ci_bg']      : '#f8fafc';
        $surface = !empty($ci['ci_surface'])  ? $ci['ci_surface'] : '#ffffff';
        $border  = !empty($ci['ci_border'])   ? $ci['ci_border']  : '#e2e8f0';
        $text    = !empty($ci['ci_text'])     ? $ci['ci_text']    : '#1e293b';
        $muted   = !empty($ci['ci_muted'])    ? $ci['ci_muted']   : '#64748b';

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Gästeliste – <?php echo esc_html($event->post_title); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: <?php echo esc_attr($bg); ?>; color: <?php echo esc_attr($text); ?>; min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }
        .card {
            background: <?php echo esc_attr($surface); ?>; border-radius: 16px; padding: 32px 24px;
            max-width: 380px; width: 100%; text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid <?php echo esc_attr($border); ?>;
        }
        .event-name { font-size: 1.3rem; font-weight: 700; margin-bottom: 4px; color: <?php echo esc_attr($text); ?>; }
        .event-date { color: <?php echo esc_attr($muted); ?>; font-size: 0.9rem; margin-bottom: 4px; }
        .event-location { color: <?php echo esc_attr($muted); ?>; font-size: 0.85rem; margin-bottom: 24px; opacity: 0.7; }
        .guest-name {
            font-size: 1.1rem; font-weight: 600; color: <?php echo esc_attr($text); ?>;
            margin-bottom: 4px;
        }
        .guest-plus { color: <?php echo esc_attr($muted); ?>; font-size: 0.85rem; margin-bottom: 20px; }
        .qr-wrap {
            display: inline-block; background: #fff; border-radius: 12px;
            padding: 16px; margin-bottom: 20px; border: 1px solid <?php echo esc_attr($border); ?>;
        }
        .qr-wrap canvas { display: block; }
        .qr-code { font-family: monospace; color: <?php echo esc_attr($muted); ?>; font-size: 0.8rem; letter-spacing: 1px; margin-bottom: 20px; }
        .hint {
            color: <?php echo esc_attr($muted); ?>; font-size: 0.8rem;
            border-top: 1px solid <?php echo esc_attr($border); ?>; padding-top: 16px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="event-name"><?php echo esc_html($event->post_title); ?></div>
        <div class="event-date"><?php echo esc_html($date_fmt); ?></div>
        <?php if ($location): ?>
        <div class="event-location"><?php echo esc_html($location); ?></div>
        <?php endif; ?>

        <div class="guest-name"><?php echo esc_html($guest['name']); ?></div>
        <?php if (!empty($guest['plus']) && $guest['plus'] > 0): ?>
        <div class="guest-plus">+<?php echo intval($guest['plus']); ?> Begleitung</div>
        <?php else: ?>
        <div class="guest-plus">&nbsp;</div>
        <?php endif; ?>

        <div class="qr-wrap">
            <canvas id="qr" data-qr="<?php echo esc_attr($code); ?>" width="200" height="200"></canvas>
        </div>
        <div class="qr-code"><?php echo esc_html($code); ?></div>

        <div class="hint">Bitte diesen QR-Code am Einlass vorzeigen.</div>
    </div>

    <script src="<?php echo esc_url($qr_js_url); ?>"></script>
    <script>
        if (window.ehQR) {
            window.ehQR.render(document.getElementById('qr'));
        }
    </script>
</body>
</html>
        <?php
        exit;
    }

    // ──────────────────────────────────────────
    // TICKET-CODE VALIDIERUNG
    // ──────────────────────────────────────────

    private static function validate_ticket_code($code) {
        if (!class_exists('TIX_Tickets')) {
            wp_send_json_error(['message' => 'Ticketsystem nicht aktiv.', 'status' => 'invalid']);
        }

        $ticket = TIX_Tickets::get_ticket_by_code($code);
        if (!$ticket) {
            wp_send_json_error(['message' => 'Ticket nicht gefunden.', 'status' => 'not_found']);
        }

        $status   = get_post_meta($ticket->ID, '_tix_ticket_status', true) ?: 'valid';
        $event_id = intval(get_post_meta($ticket->ID, '_tix_ticket_event_id', true));

        // Passwort prüfen (wenn Event bekannt)
        if ($event_id) {
            $password  = sanitize_text_field($_POST['password'] ?? '');
            $stored_pw = get_post_meta($event_id, '_tix_checkin_password', true);
            if ($stored_pw && $password !== $stored_pw) {
                wp_send_json_error(['message' => 'Falsches Passwort.', 'status' => 'unauthorized']);
            }
        }

        if ($status === 'cancelled') {
            wp_send_json_error([
                'message' => 'Ticket storniert.',
                'status'  => 'cancelled',
                'name'    => get_post_meta($ticket->ID, '_tix_ticket_owner_name', true),
                'type'    => 'ticket',
            ]);
        }

        $checked_in = (bool) get_post_meta($ticket->ID, '_tix_ticket_checked_in', true);

        if ($checked_in) {
            $time = get_post_meta($ticket->ID, '_tix_ticket_checkin_time', true);
            wp_send_json_success([
                'status'          => 'already',
                'name'            => get_post_meta($ticket->ID, '_tix_ticket_owner_name', true),
                'time'            => $time,
                'checked_in_count' => 1,
                'total_expected'  => 1,
                'type'            => 'ticket',
                'code'            => $code,
                'message'         => 'Bereits eingecheckt.',
            ]);
        }

        // Einchecken
        $by = is_user_logged_in() ? wp_get_current_user()->user_login : 'door';
        TIX_Tickets::checkin_ticket($ticket->ID, $by);

        // Custom DB aktualisieren
        try {
            if (class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
                TIX_Ticket_DB::update_ticket($code, [
                    'checked_in'    => 1,
                    'checkin_time'  => current_time('mysql'),
                    'ticket_status' => 'used',
                    'synced_supabase' => 0,
                    'synced_airtable' => 0,
                ]);
            }
        } catch (\Throwable $e) {
            // Fehler bei Custom-DB darf Check-in-Response nicht blockieren
        }

        // Kategorie-Name
        $cat_index = intval(get_post_meta($ticket->ID, '_tix_ticket_cat_index', true));
        $cats      = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cat_name  = (is_array($cats) && isset($cats[$cat_index])) ? ($cats[$cat_index]['name'] ?? '') : '';

        wp_send_json_success([
            'status'          => 'ok',
            'name'            => get_post_meta($ticket->ID, '_tix_ticket_owner_name', true),
            'checked_in_count' => 1,
            'total_expected'  => 1,
            'type'            => 'ticket',
            'code'            => $code,
            'cat'             => $cat_name,
            'seat'            => get_post_meta($ticket->ID, '_tix_ticket_seat_id', true),
            'message'         => 'Willkommen!',
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Kombinierte Liste (Gäste + Tickets)
    // ──────────────────────────────────────────

    public static function ajax_combined_list() {
        check_ajax_referer('tix_checkin_action', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error(['message' => 'Kein Event.']);

        // Passwort prüfen
        $password  = sanitize_text_field($_POST['password'] ?? '');
        $stored_pw = get_post_meta($event_id, '_tix_checkin_password', true);
        if ($stored_pw && $password !== $stored_pw) {
            wp_send_json_error(['message' => 'Falsches Passwort.', 'status' => 'unauthorized']);
        }

        $combined = [];
        $stats    = ['total' => 0, 'checked_in' => 0, 'guests' => 0, 'tickets' => 0, 'partial' => 0];

        // ── Gäste ──
        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (is_array($guests)) {
            foreach ($guests as $g) {
                $total_expected = 1 + intval($g['plus'] ?? 0);
                if (!empty($g['checked_in']) && !isset($g['checked_in_count'])) {
                    $checked_in_count = $total_expected;
                } else {
                    $checked_in_count = intval($g['checked_in_count'] ?? 0);
                }

                $is_checked = !empty($g['checked_in']);
                $is_partial = $checked_in_count > 0 && $checked_in_count < $total_expected;

                $combined[] = [
                    'id'              => $g['id'] ?? '',
                    'type'            => 'guest',
                    'name'            => $g['name'] ?? '',
                    'email'           => $g['email'] ?? '',
                    'plus'            => intval($g['plus'] ?? 0),
                    'note'            => $g['note'] ?? '',
                    'checked_in'      => $is_checked,
                    'checkin_time'    => $g['checkin_time'] ?? '',
                    'checked_in_count' => $checked_in_count,
                    'total_expected'  => $total_expected,
                    'code'            => 'GL-' . $event_id . '-' . ($g['id'] ?? ''),
                ];

                $stats['total']++;
                $stats['guests']++;
                if ($is_checked) $stats['checked_in']++;
                if ($is_partial) $stats['partial']++;
            }
        }

        // ── Gekaufte Tickets ──
        if (class_exists('TIX_Tickets')) {
            $tickets = TIX_Tickets::get_tickets_by_event($event_id);
            foreach ($tickets as $t) {
                $status     = get_post_meta($t->ID, '_tix_ticket_status', true) ?: 'valid';
                if ($status === 'cancelled') continue;

                $checked_in = (bool) get_post_meta($t->ID, '_tix_ticket_checked_in', true);
                $code       = get_post_meta($t->ID, '_tix_ticket_code', true);

                // Kategorie
                $cat_index = intval(get_post_meta($t->ID, '_tix_ticket_cat_index', true));
                $cats      = get_post_meta($event_id, '_tix_ticket_categories', true);
                $cat_name  = (is_array($cats) && isset($cats[$cat_index])) ? ($cats[$cat_index]['name'] ?? '') : '';

                $combined[] = [
                    'id'              => $t->ID,
                    'type'            => 'ticket',
                    'name'            => get_post_meta($t->ID, '_tix_ticket_owner_name', true),
                    'email'           => get_post_meta($t->ID, '_tix_ticket_owner_email', true),
                    'cat'             => $cat_name,
                    'seat'            => get_post_meta($t->ID, '_tix_ticket_seat_id', true),
                    'checked_in'      => $checked_in,
                    'checkin_time'    => get_post_meta($t->ID, '_tix_ticket_checkin_time', true),
                    'checked_in_count' => $checked_in ? 1 : 0,
                    'total_expected'  => 1,
                    'code'            => $code,
                    'ticket_status'   => $status,
                ];

                $stats['total']++;
                $stats['tickets']++;
                if ($checked_in) $stats['checked_in']++;
            }
        }

        wp_send_json_success([
            'total'      => $stats['total'],
            'checked_in' => $stats['checked_in'],
            'partial'    => $stats['partial'],
            'open'       => $stats['total'] - $stats['checked_in'],
            'guests_count'  => $stats['guests'],
            'tickets_count' => $stats['tickets'],
            'items'      => $combined,
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Ticket Check-in togglen
    // ──────────────────────────────────────────

    public static function ajax_ticket_toggle_checkin() {
        check_ajax_referer('tix_checkin_action', 'nonce');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $password  = sanitize_text_field($_POST['password'] ?? '');

        if (!$ticket_id || !class_exists('TIX_Tickets')) {
            wp_send_json_error(['message' => 'Ungültige Daten.']);
        }

        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_ticket') {
            wp_send_json_error(['message' => 'Ticket nicht gefunden.']);
        }

        // Passwort prüfen
        $event_id  = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        $stored_pw = get_post_meta($event_id, '_tix_checkin_password', true);
        if ($stored_pw && $password !== $stored_pw) {
            wp_send_json_error(['message' => 'Falsches Passwort.', 'status' => 'unauthorized']);
        }

        $checked_in = TIX_Tickets::is_checked_in($ticket_id);
        $by = is_user_logged_in() ? wp_get_current_user()->user_login : 'door';

        if ($checked_in) {
            TIX_Tickets::reset_checkin($ticket_id);
            $msg = 'Check-in zurückgesetzt.';
            $new_status = false;
        } else {
            TIX_Tickets::checkin_ticket($ticket_id, $by);
            $msg = 'Eingecheckt!';
            $new_status = true;
        }

        // Custom DB aktualisieren
        try {
            $code = get_post_meta($ticket_id, '_tix_ticket_code', true);
            if ($code && class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
                TIX_Ticket_DB::update_ticket($code, [
                    'checked_in'    => $new_status ? 1 : 0,
                    'checkin_time'  => $new_status ? current_time('mysql') : null,
                    'ticket_status' => $new_status ? 'used' : 'valid',
                    'synced_supabase' => 0,
                    'synced_airtable' => 0,
                ]);
            }
        } catch (\Throwable $e) {
            // Fehler bei Custom-DB darf Check-in-Response nicht blockieren
        }

        wp_send_json_success([
            'checked_in' => $new_status,
            'name'       => get_post_meta($ticket_id, '_tix_ticket_owner_name', true),
            'message'    => $msg,
        ]);
    }
}
