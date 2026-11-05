<?php
/**
 * Geführte Tour im Studio (ab 1.38.644): Gesehen-Stand je Konto, damit er auf allen Geräten gilt.
 *
 * GET  /studio/tour → {seen[], offered[], done[], welcome {state, step}, since, hidden_new}
 * POST /studio/tour {seen?[], offered?[], done?[], welcome?, hidden_new?, reset?} → neuer Stand
 *
 * - seen: IDs gesehener Schritte, offered: angebotene bzw. abgelehnte Bereichs-Touren, done: fertige Touren (werden ergänzt, nie gelöscht)
 * - welcome.state: new | later | running | done, welcome.step: Position (0–99)
 * - since: Tag des ersten Abrufs (Y-m-d) – Schritte bis dahin gelten für neue Konten als gesehen (keine „Neuerungen“ am ersten Tag)
 * - hidden_new: „× bis zur nächsten Neuerung“ – Neuerungen bis zu diesem Tag (neuAb) werden nicht mehr angeboten
 * - reset: true setzt alles zurück (Hilfe → „Alles von vorn“), since bleibt
 * Speicher: User-Meta _tix_studio_tour (JSON, nur IDs, höchstens 2000 Einträge je Liste). Jedes angemeldete Konto darf seinen
 * eigenen Stand lesen und schreiben (auch Team, Einlass, Kasse) – fremde Konten nie.
 */
if (!defined('ABSPATH')) exit;

class TIX_Studio_Tour {
    const NS = 'tixomat/v1';
    const META = '_tix_studio_tour';
    const MAX = 2000;
    const STATES = ['new', 'later', 'running', 'done'];

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes() {
        register_rest_route(self::NS, '/studio/tour', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => [__CLASS__, 'perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => [__CLASS__, 'perm']],
        ]);
    }

    public static function perm() {
        return is_user_logged_in() ? true : new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
    }

    private static function ids($v) {
        $out = [];
        foreach ((array) $v as $x) {
            $x = strtolower(trim((string) $x));
            if ($x !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $x)) $out[$x] = true;
        }
        return array_keys($out);
    }

    private static function day($v) { $v = (string) $v; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; }

    /** Stand eines Kontos lesen; beim ersten Abruf wird since gesetzt */
    public static function get_state($uid) {
        $raw = get_user_meta($uid, self::META, true);
        $s = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        $s = is_array($s) ? $s : [];
        $w = is_array($s['welcome'] ?? null) ? $s['welcome'] : [];
        $out = [
            'seen' => self::ids($s['seen'] ?? []), 'offered' => self::ids($s['offered'] ?? []), 'done' => self::ids($s['done'] ?? []),
            'welcome' => ['state' => in_array($w['state'] ?? '', self::STATES, true) ? $w['state'] : 'new', 'step' => max(0, min(99, intval($w['step'] ?? 0)))],
            'since' => self::day($s['since'] ?? ''), 'hidden_new' => self::day($s['hidden_new'] ?? ''),
        ];
        if ($out['since'] === '') { $out['since'] = current_time('Y-m-d'); self::put($uid, $out); }
        return $out;
    }

    /** Speichern; Listen auf die neuesten MAX Einträge gekürzt (auch im zurückgegebenen Stand) */
    private static function put($uid, array &$s) {
        foreach (['seen', 'offered', 'done'] as $k) $s[$k] = array_values(array_slice($s[$k], -self::MAX));
        update_user_meta($uid, self::META, wp_json_encode($s));
    }

    public static function rest_get(WP_REST_Request $req) {
        $r = rest_ensure_response(['ok' => true] + self::get_state(get_current_user_id()));
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    public static function rest_save(WP_REST_Request $req) {
        $uid = get_current_user_id();
        $s = self::get_state($uid);
        if (rest_sanitize_boolean($req->get_param('reset'))) $s = ['seen' => [], 'offered' => [], 'done' => [], 'welcome' => ['state' => 'new', 'step' => 0], 'since' => $s['since'], 'hidden_new' => ''];
        foreach (['seen', 'offered', 'done'] as $k) if ($req->has_param($k)) $s[$k] = array_values(array_unique(array_merge($s[$k], self::ids($req->get_param($k)))));
        if ($req->has_param('welcome')) {
            $w = (array) $req->get_param('welcome');
            if (isset($w['state']) && !in_array($w['state'], self::STATES, true)) return new WP_Error('tix_invalid_field', 'Unbekannter Stand der Tour.', ['status' => 400, 'field' => 'welcome']);
            $s['welcome'] = ['state' => $w['state'] ?? $s['welcome']['state'], 'step' => isset($w['step']) ? max(0, min(99, intval($w['step']))) : $s['welcome']['step']];
        }
        if ($req->has_param('hidden_new')) $s['hidden_new'] = self::day($req->get_param('hidden_new'));
        self::put($uid, $s);
        $r = rest_ensure_response(['ok' => true] + $s);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}

TIX_Studio_Tour::init();
