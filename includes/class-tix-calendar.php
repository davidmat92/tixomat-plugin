<?php
if (!defined('ABSPATH')) exit;

class TIX_Calendar {

    public static function init() {
        add_shortcode('tix_calendar', [__CLASS__, 'shortcode']);
        add_action('wp_ajax_tix_ics',        [__CLASS__, 'ajax_ics']);
        add_action('wp_ajax_nopriv_tix_ics',  [__CLASS__, 'ajax_ics']);
    }

    /**
     * Shortcode: [tix_calendar] oder [tix_calendar id="123"]
     */
    public static function shortcode($atts = []) {
        $atts = shortcode_atts(['id' => 0, 'class' => '', 'fullwidth' => '0', 'variant' => '2'], $atts, 'tix_calendar');
        $post_id = intval($atts['id']) ?: get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') return '';

        $date_start = get_post_meta($post_id, '_tix_date_start', true);
        if (!$date_start) return '';

        $google_url = self::google_url($post_id);
        $ics_url    = admin_url('admin-ajax.php') . '?action=tix_ics&event_id=' . $post_id;
        $extra_class = $atts['class'] ? ' ' . esc_attr($atts['class']) : '';
        if ($atts['fullwidth'] === '1') $extra_class .= ' tix-fullwidth';

        // Enqueue
        wp_enqueue_style('tix-calendar', TIXOMAT_URL . 'assets/css/calendar.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-calendar', TIXOMAT_URL . 'assets/js/calendar.js', [], TIXOMAT_VERSION, true);

        ob_start();
        ?>
        <?php $tix_v = intval($atts['variant']) === 2 ? 2 : 1; ?>
        <div class="tix-cal<?php echo $extra_class; ?>"<?php if ($tix_v === 2): ?> style="--tix-btn1-bg:var(--tix-btn2-bg,transparent);--tix-btn1-color:var(--tix-btn2-color,inherit);--tix-btn1-hover-bg:var(--tix-btn2-hover-bg,transparent);--tix-btn1-hover-color:var(--tix-btn2-hover-color,inherit);--tix-btn1-radius:var(--tix-btn2-radius,8px);--tix-btn1-border:var(--tix-btn2-border,1px solid currentColor);--tix-btn1-font-size:var(--tix-btn2-font-size,0.9rem)"<?php endif; ?>>
            <button type="button" class="tix-cal-btn">
                <svg class="tix-cal-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Zum Kalender hinzufügen
            </button>
            <div class="tix-cal-dropdown">
                <a href="<?php echo esc_url($google_url); ?>" target="_blank" rel="noopener" class="tix-cal-opt">
                    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-1-11v6h2v-6h-2zm0-4v2h2V7h-2z" fill="currentColor"/></svg>
                    Google Calendar
                </a>
                <a href="<?php echo esc_url($ics_url); ?>" download class="tix-cal-opt">
                    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M17 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2zm-5 14l-5-5h3V8h4v4h3l-5 5z" fill="currentColor"/></svg>
                    Apple Calendar
                </a>
                <a href="<?php echo esc_url($ics_url); ?>" download class="tix-cal-opt">
                    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M21 4H3a1 1 0 00-1 1v14a1 1 0 001 1h18a1 1 0 001-1V5a1 1 0 00-1-1zm-1 14H4V8h16v10z" fill="currentColor"/></svg>
                    Outlook
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Google Calendar URL bauen
     */
    public static function google_url($post_id) {
        $data = self::get_event_data($post_id);
        if (!$data) return '#';

        $params = [
            'action'   => 'TEMPLATE',
            'text'     => $data['title'],
            'dates'    => $data['gc_start'] . '/' . $data['gc_end'],
            'details'  => $data['description'],
            'location' => $data['location'],
        ];

        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }

    /**
     * AJAX: .ics-Datei generieren und ausliefern
     */
    public static function ajax_ics() {
        $post_id = intval($_GET['event_id'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== 'event') {
            wp_die('Event nicht gefunden.', 404);
        }

        $data = self::get_event_data($post_id);
        if (!$data) wp_die('Keine Event-Daten.', 400);

        $uid  = 'event-' . $post_id . '@' . parse_url(home_url(), PHP_URL_HOST);
        $now  = gmdate('Ymd\THis\Z');
        $slug = sanitize_title($data['title']);

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Tixomat//Event//DE\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$now}\r\n";
        $ics .= "DTSTART:{$data['ics_start']}\r\n";
        $ics .= "DTEND:{$data['ics_end']}\r\n";
        $ics .= "SUMMARY:" . self::ics_escape($data['title']) . "\r\n";
        if ($data['description']) {
            $ics .= "DESCRIPTION:" . self::ics_escape($data['description']) . "\r\n";
        }
        if ($data['location']) {
            $ics .= "LOCATION:" . self::ics_escape($data['location']) . "\r\n";
        }
        if ($data['url']) {
            $ics .= "URL:" . $data['url'] . "\r\n";
        }
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '.ics"');
        header('Cache-Control: no-cache, no-store');
        echo $ics;
        exit;
    }

    /**
     * Event-Daten für Kalender-Export sammeln
     */
    private static function get_event_data($post_id) {
        $date_start = get_post_meta($post_id, '_tix_date_start', true);
        if (!$date_start) return null;

        $date_end   = get_post_meta($post_id, '_tix_date_end', true) ?: $date_start;
        $time_start = get_post_meta($post_id, '_tix_time_start', true) ?: '00:00';
        $time_end   = get_post_meta($post_id, '_tix_time_end', true) ?: '23:59';
        $location   = get_post_meta($post_id, '_tix_location', true);
        $address    = get_post_meta($post_id, '_tix_address', true);

        $loc_full = $location;
        if ($address) $loc_full .= ', ' . $address;

        $title = get_the_title($post_id);
        $url   = get_permalink($post_id);

        // Beschreibung: Einlass + URL
        $desc_parts = [];
        $time_doors = get_post_meta($post_id, '_tix_time_doors', true);
        if ($time_doors) {
            $desc_parts[] = 'Einlass: ' . date('H:i', strtotime($time_doors)) . ' Uhr';
        }
        $desc_parts[] = $url;
        $description = implode("\n", $desc_parts);

        // DateTime-Objekte
        $start_ts = strtotime("{$date_start} {$time_start}");
        $end_ts   = strtotime("{$date_end} {$time_end}");

        // Fallback: Ende mindestens 1h nach Start
        if ($end_ts <= $start_ts) $end_ts = $start_ts + 3600;

        // Timezone (WP-Timezone verwenden)
        $tz = wp_timezone_string();

        return [
            'title'       => $title,
            'location'    => $loc_full,
            'description' => $description,
            'url'         => $url,
            // Google Calendar: UTC-Format
            'gc_start'    => gmdate('Ymd\THis\Z', $start_ts),
            'gc_end'      => gmdate('Ymd\THis\Z', $end_ts),
            // .ics: mit Timezone
            'ics_start'   => self::ics_datetime($start_ts, $tz),
            'ics_end'     => self::ics_datetime($end_ts, $tz),
        ];
    }

    /**
     * DateTime für .ics (mit TZID falls verfügbar)
     */
    private static function ics_datetime($timestamp, $tz) {
        if (strpos($tz, '/') !== false) {
            // Timezone wie Europe/Berlin → TZID verwenden
            $dt = new DateTime('@' . $timestamp);
            $dt->setTimezone(new DateTimeZone($tz));
            return 'TZID=' . $tz . ':' . $dt->format('Ymd\THis');
        }
        // Fallback: UTC
        return gmdate('Ymd\THis\Z', $timestamp);
    }

    /**
     * Text für .ics escapen (RFC 5545)
     */
    private static function ics_escape($text) {
        $text = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $text);
        // Zeilen-Folding (max 75 Zeichen)
        if (strlen($text) > 60) {
            $text = mb_substr($text, 0, 60) . "\r\n " . mb_substr($text, 60);
        }
        return $text;
    }

    /**
     * Breakdance-Meta: Google Calendar URL speichern
     */
    public static function save_meta($post_id) {
        $url = self::google_url($post_id);
        update_post_meta($post_id, '_tix_calendar_google_url', $url);

        $ics_url = admin_url('admin-ajax.php') . '?action=tix_ics&event_id=' . $post_id;
        update_post_meta($post_id, '_tix_calendar_ics_url', $ics_url);
    }
}
