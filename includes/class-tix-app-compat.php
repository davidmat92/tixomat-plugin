<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_App_Compat – Mindest-Version des Plugins für Apps (eigene App auf eigener Plattform, Paket 3b).
 *
 * Die App schickt bei REST-Anfragen (tixomat/v1) optional:
 *   X-Tix-App-Min-Plugin: 1.39.0     – Plugin-Version, die die App braucht
 *   X-Tix-App-Min-Since:  2026-10-20 – Erscheinungsdatum dieser Version (sonst: erster Kontakt)
 *   X-Tix-App-Id:         de.kitchenklub.app
 * Frist 30 Tage ab Erscheinen (User-Entscheid 12.10.2026), danach nur Hinweis – nichts wird gesperrt.
 * Antwort: Header `X-Tix-Plugin-Version` (immer), `X-Tix-Compat` ok|grace|outdated + `X-Tix-Compat-Deadline`
 * (wenn die App eine Mindest-Version schickt); `GET /info` → `compat`. Admin-Hinweis in WordPress.
 */
class TIX_App_Compat {

    const GRACE_DAYS = 30;
    const OPTION     = 'tix_app_requirements';

    public static function init() {
        add_filter('rest_post_dispatch', [__CLASS__, 'on_response'], 10, 3);
        add_action('admin_notices', [__CLASS__, 'notice']);
    }

    private static function valid_version($v) {
        return is_string($v) && preg_match('/^\d{1,4}(\.\d{1,5}){1,3}$/', $v);
    }

    /** Anforderung aus den Request-Headern (oder null). */
    public static function from_request(WP_REST_Request $req) {
        $min = trim((string) $req->get_header('x_tix_app_min_plugin'));
        if (!self::valid_version($min)) return null;
        $since = trim((string) $req->get_header('x_tix_app_min_since'));
        $app   = preg_replace('/[^a-z0-9._-]/', '', strtolower((string) $req->get_header('x_tix_app_id'))) ?: 'app'; // Bundle-ID, z. B. de.kitchenklub.app
        return [
            'app'   => mb_substr($app, 0, 64),
            'min'   => $min,
            'since' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) ? $since : '',
        ];
    }

    public static function requirements() {
        $r = get_option(self::OPTION, []);
        return is_array($r) ? $r : [];
    }

    /** Merken (höchstens einmal am Tag schreiben, es sei denn, die Anforderung ändert sich). */
    public static function record(array $want) {
        $all = self::requirements();
        $cur = $all[$want['app']] ?? null;
        $today = current_time('Y-m-d');
        if ($cur && $cur['min'] === $want['min'] && $cur['since'] === $want['since'] && ($cur['last_seen'] ?? '') === $today) return $cur;
        $first = ($cur && $cur['min'] === $want['min']) ? ($cur['first_seen'] ?? $today) : $today;
        $all[$want['app']] = ['min' => $want['min'], 'since' => $want['since'], 'first_seen' => $first, 'last_seen' => $today];
        if (count($all) > 10) {
            uasort($all, function ($a, $b) { return strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''); });
            $all = array_slice($all, 0, 10, true);
        }
        update_option(self::OPTION, $all, false);
        return $all[$want['app']];
    }

    /** ok | grace | outdated + Frist (Y-m-d). */
    public static function evaluate($min, $since = '', $first_seen = '') {
        if (version_compare(TIXOMAT_VERSION, $min, '>=')) return ['status' => 'ok', 'deadline' => null];
        $start = $since !== '' ? $since : ($first_seen !== '' ? $first_seen : current_time('Y-m-d'));
        $deadline = gmdate('Y-m-d', strtotime($start . ' +' . self::GRACE_DAYS . ' days'));
        return ['status' => current_time('Y-m-d') <= $deadline ? 'grace' : 'outdated', 'deadline' => $deadline];
    }

    public static function on_response($response, $server, $request) {
        if (!($request instanceof WP_REST_Request) || strpos((string) $request->get_route(), '/tixomat/v1') !== 0) return $response;
        if (!($response instanceof WP_HTTP_Response)) return $response;
        $response->header('X-Tix-Plugin-Version', TIXOMAT_VERSION);
        $want = self::from_request($request);
        if ($want) {
            $rec = self::record($want);
            $ev  = self::evaluate($want['min'], $want['since'], (string) ($rec['first_seen'] ?? ''));
            $response->header('X-Tix-Compat', $ev['status']);
            if ($ev['deadline']) $response->header('X-Tix-Compat-Deadline', $ev['deadline']);
        }
        return $response;
    }

    /** Für GET /info: Stand dieser Anfrage + bekannte Apps. */
    public static function info_payload(WP_REST_Request $req) {
        $out = ['plugin_version' => TIXOMAT_VERSION, 'grace_days' => self::GRACE_DAYS, 'status' => 'ok', 'required' => null, 'deadline' => null, 'apps' => []];
        $want = self::from_request($req);
        if ($want) {
            $rec = self::record($want);
            $ev  = self::evaluate($want['min'], $want['since'], (string) ($rec['first_seen'] ?? ''));
            $out['status'] = $ev['status']; $out['required'] = $want['min']; $out['deadline'] = $ev['deadline'];
        }
        foreach (self::requirements() as $app => $r) {
            $ev = self::evaluate((string) $r['min'], (string) ($r['since'] ?? ''), (string) ($r['first_seen'] ?? ''));
            $out['apps'][] = ['app_id' => (string) $app, 'required' => (string) $r['min'], 'status' => $ev['status'], 'deadline' => $ev['deadline']];
        }
        return $out;
    }

    public static function notice() {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? (string) $screen->id : '';
        if (!in_array($id, ['dashboard', 'plugins', 'update-core'], true) && strpos($id, 'tixomat') === false && strpos($id, 'tix-') === false) return;
        $how = class_exists('TIX_License') && TIX_License::active()
            ? ' Das Update findest du unter <a href="' . esc_url(admin_url('update-core.php')) . '">Dashboard → Aktualisierungen</a>.'
            : '';
        foreach (self::requirements() as $app => $r) {
            // Nur Apps, die sich in den letzten 30 Tagen gemeldet haben
            if (!empty($r['last_seen']) && strtotime($r['last_seen']) < strtotime('-30 days')) continue;
            $ev = self::evaluate((string) $r['min'], (string) ($r['since'] ?? ''), (string) ($r['first_seen'] ?? ''));
            if ($ev['status'] === 'ok') continue;
            $date = date_i18n('d.m.Y', strtotime($ev['deadline']));
            if ($ev['status'] === 'grace') {
                echo '<div class="notice notice-warning"><p><strong>Tixomat:</strong> Deine App (' . esc_html($app) . ') braucht Tixomat ' . esc_html($r['min']) . ' (installiert: ' . esc_html(TIXOMAT_VERSION) . '). Bitte bis ' . esc_html($date) . ' aktualisieren.' . $how . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p><strong>Tixomat:</strong> Die Frist für das Update auf ' . esc_html($r['min']) . ' ist am ' . esc_html($date) . ' abgelaufen. Deine App (' . esc_html($app) . ') zeigt deinen Gästen jetzt einen Update-Hinweis.' . $how . '</p></div>';
            }
        }
    }
}
