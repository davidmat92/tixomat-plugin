<?php
/**
 * Tixomat – Universal Links (apple-app-site-association)
 *
 * Liefert /.well-known/apple-app-site-association (und /apple-app-site-association)
 * für die native App der Seite aus. Nur Links mit ?tix_sp_ticket=<id> (Knopf
 * „Im Support antworten“ in Support-Mails) öffnen die App, alle anderen Links
 * bleiben im Browser. App-IDs je Seite in der Option tix_app_link_ids
 * („TEAMID.bundle.id“), leer = 404.
 *
 * @since 1.38.342
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Links {

    const OPTION = 'tix_app_link_ids';

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_serve'], 0);
    }

    /** Gültige App-IDs (Team-ID mit 10 Zeichen + Bundle-ID) */
    public static function ids() {
        return self::sanitize_ids(get_option(self::OPTION, []));
    }

    public static function sanitize_ids($raw) {
        $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw);
        $out = [];
        foreach ($list as $id) {
            $id = trim((string) $id);
            if (preg_match('/^[A-Z0-9]{10}\.[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $id)) $out[] = $id;
        }
        return array_values(array_unique($out));
    }

    public static function document(array $ids) {
        return [
            'applinks' => [
                'details' => [[
                    'appIDs'     => $ids,
                    'components' => [[
                        '/'       => '*',
                        '?'       => ['tix_sp_ticket' => '?*'],
                        'comment' => 'Support-Verlauf in der App',
                    ]],
                ]],
            ],
        ];
    }

    public static function maybe_serve() {
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($path !== $base . '/.well-known/apple-app-site-association' && $path !== $base . '/apple-app-site-association') return;
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return;

        // Nicht zwischenspeichern (LiteSpeed Cache u. a.)
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        do_action('litespeed_control_set_nocache', 'tixomat aasa');
        nocache_headers();
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Content-Type: application/json');

        $ids = self::ids();
        if (!$ids) {
            status_header(404);
            echo '{"error":"not_configured"}';
            exit;
        }
        status_header(200);
        echo wp_json_encode(self::document($ids), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
