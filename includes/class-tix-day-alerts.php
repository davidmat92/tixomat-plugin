<?php
/**
 * „Erinnere mich“ für einen Tag (evendis-App, 1.38.367).
 *
 *   GET    /customer/day-alerts                     → {"alerts":[{"date":"2026-10-16","city":"Köln"}]}
 *   POST   /customer/day-alerts {date, city?}
 *   DELETE /customer/day-alerts?date=YYYY-MM-DD[&city=]
 *   POST   /customer/day-alerts/delete {date, city?}   (Ausweichweg ohne DELETE)
 *
 * Auth wie die anderen /customer/*-Routen (Gast-Token X-Tix-Token). Speicher:
 * User-Meta `_tix_day_alerts` (höchstens 20, vergangene Tage fallen beim Lesen weg).
 *
 * Wird ein Event veröffentlicht (auch ein neuer Serientermin), bekommen Nutzer mit
 * passendem Alarm (Event-Tage enthalten das Datum; Stadt leer oder gleich der
 * Stadt des Orts) einen persönlichen Hinweis + Push über TIX_Notifications –
 * je Nutzer und Event nur einmal (Merker `_tix_day_alert_sent` am Event).
 */
if (!defined('ABSPATH')) exit;

class TIX_Day_Alerts {

    const NS   = 'tixomat/v1';
    const META = '_tix_day_alerts';
    const SENT = '_tix_day_alert_sent';
    const MAX  = 20;

    const WEEKDAYS = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Nach allen save_post-Hooks: Metas (Datum, Ort) sind dann gespeichert
        add_action('wp_after_insert_post', [__CLASS__, 'on_after_insert'], 20, 4);
    }

    public static function register_routes() {
        $perm = class_exists('TIX_App_Account') ? ['TIX_App_Account', 'check_customer'] : function () { return is_user_logged_in(); };
        register_rest_route(self::NS, '/customer/day-alerts', [
            ['methods' => 'GET',    'callback' => [__CLASS__, 'rest_list'],   'permission_callback' => $perm],
            ['methods' => 'POST',   'callback' => [__CLASS__, 'rest_add'],    'permission_callback' => $perm],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_remove'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/customer/day-alerts/delete', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_remove'],
            'permission_callback' => $perm,
        ]);
    }

    // ──────────────────────────────────────────
    //  Speicher
    // ──────────────────────────────────────────

    private static function valid_date($s) {
        return is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate(intval($m[2]), intval($m[3]), intval($m[1]));
    }

    private static function norm_city($c) {
        $c = trim((string) $c);
        return function_exists('mb_strtolower') ? mb_strtolower($c, 'UTF-8') : strtolower($c);
    }

    /** Gültige, künftige Alarme des Nutzers (räumt vergangene Tage auf). */
    public static function alerts($user_id, $cleanup = true) {
        $raw = get_user_meta($user_id, self::META, true);
        $raw = is_array($raw) ? $raw : [];
        $today = current_time('Y-m-d');
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a) || !self::valid_date($a['date'] ?? '')) continue;
            if ($a['date'] < $today) continue;
            $out[] = ['date' => (string) $a['date'], 'city' => (string) ($a['city'] ?? '')];
        }
        usort($out, function ($a, $b) { return strcmp($a['date'] . $a['city'], $b['date'] . $b['city']); });
        if ($cleanup && count($out) !== count($raw)) update_user_meta($user_id, self::META, $out);
        return $out;
    }

    private static function uid() {
        $u = wp_get_current_user();
        return $u && $u->ID ? intval($u->ID) : 0;
    }

    private static function city_param($v) {
        return mb_substr(sanitize_text_field((string) $v), 0, 80);
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    public static function rest_list(WP_REST_Request $req) {
        return rest_ensure_response(['ok' => true, 'alerts' => self::alerts(self::uid())]);
    }

    public static function rest_add(WP_REST_Request $req) {
        $uid  = self::uid();
        $date = (string) $req->get_param('date');
        $city = self::city_param($req->get_param('city'));
        if (!self::valid_date($date)) return new WP_Error('tix_date', 'Bitte ein Datum als JJJJ-MM-TT angeben.', ['status' => 400]);
        if ($date < current_time('Y-m-d')) return new WP_Error('tix_date', 'Dieser Tag liegt in der Vergangenheit.', ['status' => 400]);
        $list = self::alerts($uid, false);
        foreach ($list as $a) {
            if ($a['date'] === $date && self::norm_city($a['city']) === self::norm_city($city)) {
                return rest_ensure_response(['ok' => true, 'alerts' => $list]);
            }
        }
        if (count($list) >= self::MAX) {
            return new WP_Error('tix_alert_limit', 'Du kannst höchstens ' . self::MAX . ' Tage vormerken.', ['status' => 400]);
        }
        $list[] = ['date' => $date, 'city' => $city];
        update_user_meta($uid, self::META, $list);
        return rest_ensure_response(['ok' => true, 'alerts' => self::alerts($uid)]);
    }

    /** DELETE ?date= (alle Alarme des Tages) bzw. mit city nur diesen. */
    public static function rest_remove(WP_REST_Request $req) {
        $uid  = self::uid();
        $date = (string) $req->get_param('date');
        if (!self::valid_date($date)) return new WP_Error('tix_date', 'Bitte ein Datum als JJJJ-MM-TT angeben.', ['status' => 400]);
        $has_city = $req->get_param('city') !== null;
        $city = self::norm_city(self::city_param($req->get_param('city')));
        $list = array_values(array_filter(self::alerts($uid, false), function ($a) use ($date, $has_city, $city) {
            if ($a['date'] !== $date) return true;
            return $has_city && self::norm_city($a['city']) !== $city;
        }));
        update_user_meta($uid, self::META, $list);
        return rest_ensure_response(['ok' => true, 'alerts' => $list]);
    }

    // ──────────────────────────────────────────
    //  Benachrichtigen
    // ──────────────────────────────────────────

    public static function on_after_insert($post_id, $post, $update, $post_before) {
        if (!$post || $post->post_type !== 'event' || $post->post_status !== 'publish') return;
        if ($post_before && $post_before->post_status === 'publish') return;
        self::notify_event($post_id);
    }

    /** „Neu am Freitag, 16.10.“ */
    public static function title_for($date) {
        $ts = strtotime($date . ' 12:00:00 UTC');
        return 'Neu am ' . self::WEEKDAYS[intval(gmdate('N', $ts))] . ', ' . gmdate('d.m.', $ts);
    }

    /**
     * Nutzer mit passendem Tages-Alarm benachrichtigen (je Nutzer und Event einmal).
     * Gibt die Anzahl der neu benachrichtigten Nutzer zurück.
     */
    public static function notify_event($event_id) {
        $event_id = intval($event_id);
        if (!class_exists('TIX_Public_Events') || !class_exists('TIX_Notifications')) return 0;
        // Ohne vorgemerkte Tage (z. B. Seiten ohne evendis-App) gar nichts tun
        $users = get_users(['meta_key' => self::META, 'fields' => 'ID', 'number' => 5000]);
        if (!$users) return 0;
        $p = TIX_Public_Events::payload($event_id, false);
        if (!$p || !empty($p['is_past'])) return 0;
        $today = current_time('Y-m-d');
        $days = TIX_Public_Events::days_in_range(TIX_Public_Events::day_span($event_id), $today, '9999-12-31');
        if (!$days) return 0;
        $city = self::norm_city($p['venue']['city'] ?? '');

        $sent = get_post_meta($event_id, self::SENT, true);
        $sent = is_array($sent) ? array_map('intval', $sent) : [];
        $n = 0;
        foreach ($users as $uid) {
            $uid = intval($uid);
            if (in_array($uid, $sent, true)) continue;
            $hit = '';
            foreach (self::alerts($uid, false) as $a) {
                if (!in_array($a['date'], $days, true)) continue;
                $ac = self::norm_city($a['city']);
                if ($ac !== '' && $ac !== $city) continue;
                $hit = $a['date'];
                break;
            }
            if ($hit === '') continue;
            // Merker zuerst setzen: auch bei parallelen Aufrufen nur ein Hinweis
            $sent[] = $uid;
            update_post_meta($event_id, self::SENT, $sent);
            TIX_Notifications::add_user_item($uid, self::title_for($hit), (string) $p['title'], [
                'type'     => 'reminder',
                'event_id' => $event_id,
                'action'   => 'event:' . $event_id,
            ]);
            $n++;
        }
        return $n;
    }
}
