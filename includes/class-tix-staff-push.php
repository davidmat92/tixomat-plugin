<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Staff_Push – Mitteilungen für Veranstalter und Team (Paket P6, „evendis für Veranstalter“, 1.38.574).
 *
 * Getrennt von den Gast-Pushes (TIX_Notifications bleibt für Gäste unverändert):
 *   - Geräte: `POST /push/register` mit `app=staff` (+ bundle, platform) → eigene Liste `_tix_staff_push_tokens`,
 *     nur für angemeldete Team-Konten. KitchenKlub/Mallorca registrieren ihre eigene App (eigenes Bundle, eigener
 *     Weg), „evendis für Veranstalter“ (Bundle APP_BUNDLE) meldet sich bei jedem gespeicherten Shop an.
 *   - Versand: iOS per APNs mit dem Bundle des Geräts (gleicher .p8-Schlüssel des Teams); Android der eigenen App
 *     über das Firebase-Projekt der Seite (TIX_FCM). Geräte der neuen App gehen über die zentrale Weiterleitung
 *     auf evendis.de (`POST /push/relay`, HMAC je Seite) – nur dort liegt das Firebase-Dienstkonto der neuen App.
 *   - Verkäufe je Konto (1.38.592, `sales` in /staff/notify-prefs): sofort (Warteschlange, Verkäufe innerhalb von
 *     2 Min. zusammen), gesammelt alle X Stunden (1/2/3/4/6/12/24) oder zu festen Uhrzeiten (Europe/Berlin), aus.
 *     Vorgabe für Bestand: stündlich. Merker „zuletzt gemeldet“ je Konto; Cron alle 5 Minuten prüft, wer fällig ist.
 *   - Ereignisse: Verkäufe (s. o.); „ausverkauft“ und „Kontingent knapp“ sofort; neue
 *     Kundenanfrage; Awareness-Meldung. Je Konto einstellbar (`GET/POST /staff/notify-prefs`, auch im Studio).
 *   - Abschottung: auf der Plattform nur Konten des Veranstalters des Events (TIX_App_Scope).
 * Aktionen in `data.action`: staff:start · staff:event:<id> · staff:support:<id> · staff:awareness:<id>, jeweils mit
 *   `@<host>` des Shops (z. B. staff:event:12@kitchenklub.de); `data.site` = Host.
 */
class TIX_Staff_Push {

    const NS             = 'tixomat/v1';
    const OPT_TOKENS     = '_tix_staff_push_tokens';
    const META_PREFS     = '_tix_staff_notify';
    const OPT_DIGEST_AT  = 'tix_staff_push_digest_at';
    const CRON           = 'tix_staff_push_digest';
    const CRON_INSTANT   = 'tix_staff_push_instant';
    const META_SALES     = '_tix_staff_sales';                   // {mode, hours, times[], last}
    const OPT_INSTANT_Q  = 'tix_staff_push_instant_q';           // Bestellungen für „sofort“ (Warteschlange)
    const SALES_MODES    = ['instant', 'interval', 'times', 'off'];
    const SALES_HOURS    = [1, 2, 3, 4, 6, 12, 24];
    const INSTANT_DELAY  = 120;                                  // Verkäufe innerhalb von 2 Min. zusammen
    const TZ             = 'Europe/Berlin';
    const APP_BUNDLE     = 'de.evendis.veranstalter';           // „evendis für Veranstalter“ (iOS-Bundle = Android-Paket)
    const RELAY_DEFAULT  = 'https://veranstalter.evendis.de';    // zentrale Weiterleitung (evendis.de-WordPress)
    const OPT_RELAY_URL  = 'tix_staff_push_relay_url';
    const OPT_RELAY_KEY  = 'tix_staff_push_relay_secret';        // Shop: Schlüssel für die Weiterleitung
    const OPT_RELAY_SITES = 'tix_staff_push_relay_sites';        // evendis.de: {host: secret}
    const OPT_RELAY_SA   = '_tix_staff_fcm_service_account';     // evendis.de: Firebase-Dienstkonto der neuen App
    const LOW_MIN        = 5;                                    // „knapp“: höchstens 5 bzw. 10 % übrig

    const PREFS = [
        'sales_digest' => 'Verkäufe',
        'sold_out'     => 'Ausverkauft',
        'low_stock'    => 'Kontingent knapp',
        'support'      => 'Neue Kundenanfrage',
        'awareness'    => 'Awareness-Meldung',
    ];
    const STAFF_ROLES     = ['administrator', 'tix_organizer', 'tix_staff', 'tix_entrance', 'tix_cashier', 'tix_dj', 'tix_awareness'];
    const MANAGER_ROLES   = ['administrator', 'tix_organizer', 'tix_staff'];

    private static $support_queue = [];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::CRON, [__CLASS__, 'cron_digest']);
        add_action(self::CRON_INSTANT, [__CLASS__, 'flush_instant']);
        add_filter('cron_schedules', function ($s) {
            $s['tix_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Alle 5 Minuten (Tixomat)'];
            return $s;
        });
        add_action('init', function () {
            // alle 5 Minuten prüfen, wer fällig ist (vorher stündlich)
            if (wp_get_schedule(TIX_Staff_Push::CRON) !== 'tix_5min') {
                wp_clear_scheduled_hook(TIX_Staff_Push::CRON);
                wp_schedule_event(time() + 120, 'tix_5min', TIX_Staff_Push::CRON);
            }
        });
        add_action('tix_order_completed', [__CLASS__, 'on_order_completed'], 30);
        add_action('tix_order_completed', [__CLASS__, 'queue_instant'], 40);
        add_action('transition_post_status', [__CLASS__, 'on_support_status'], 10, 3);
        add_action('shutdown', [__CLASS__, 'flush_support_queue']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat staff-push', [__CLASS__, 'cli']);
    }

    // ═══════════════ Geräte ═══════════════

    public static function tokens() {
        $t = get_option(self::OPT_TOKENS, []);
        return is_array($t) ? $t : [];
    }

    private static function save_tokens(array $t) {
        $cutoff = time() - 180 * DAY_IN_SECONDS;
        foreach ($t as $k => $m) if (intval($m['ts'] ?? 0) < $cutoff) unset($t[$k]);
        if (count($t) > 3000) $t = array_slice($t, -3000, null, true);
        update_option(self::OPT_TOKENS, $t, false);
    }

    public static function is_staff($uid) {
        $u = $uid ? get_user_by('id', $uid) : null;
        return $u && array_intersect(self::STAFF_ROLES, (array) $u->roles);
    }

    /** Aus TIX_Notifications::rest_register_device bei `app=staff`. */
    public static function register(WP_REST_Request $req, $token, $platform, $bundle) {
        $uid = get_current_user_id();
        if (!$uid) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        if (!self::is_staff($uid)) return new WP_Error('rest_forbidden', 'Nur für Team-Konten.', ['status' => 403]);
        $t = self::tokens();
        $t[$token] = ['user' => $uid, 'platform' => $platform, 'bundle' => $bundle, 'ts' => time()];
        self::save_tokens($t);
        return new WP_REST_Response(['ok' => true, 'app' => 'staff', 'platform' => $platform, 'push_ready' => self::route_ready($platform, $bundle),
            'prefs' => self::prefs($uid)], 200);
    }

    public static function unregister($token) {
        $t = self::tokens();
        if (isset($t[$token])) { unset($t[$token]); update_option(self::OPT_TOKENS, $t, false); }
    }

    /** Kann ein Gerät dieser Art erreicht werden? */
    public static function route_ready($platform, $bundle) {
        if ($bundle === self::APP_BUNDLE && !self::is_relay_host()) return self::relay_secret() !== '' || ($platform === 'ios' && TIX_Notifications::apns_configured());
        if ($platform === 'android') return $bundle === self::APP_BUNDLE ? self::relay_fcm_ready() : TIX_Notifications::fcm_configured();
        return TIX_Notifications::apns_configured();
    }

    // ═══════════════ Einstellungen ═══════════════

    public static function prefs($uid) {
        $p = get_user_meta($uid, self::META_PREFS, true);
        $p = is_array($p) ? $p : [];
        $out = [];
        foreach (array_keys(self::PREFS) as $k) $out[$k] = array_key_exists($k, $p) ? (bool) $p[$k] : true;
        $out['sales_digest'] = self::sales($uid)['mode'] !== 'off';
        return $out;
    }

    /** Verkaufs-Mitteilungen des Kontos: {mode, hours, times[], last}. Bestand: stündlich bzw. aus, wenn abgeschaltet. */
    public static function sales($uid) {
        $s = get_user_meta($uid, self::META_SALES, true);
        if (!is_array($s) || !in_array($s['mode'] ?? '', self::SALES_MODES, true)) {
            $old = get_user_meta($uid, self::META_PREFS, true);
            $off = is_array($old) && array_key_exists('sales_digest', $old) && !$old['sales_digest'];
            $s = ['mode' => $off ? 'off' : 'interval', 'hours' => 1, 'times' => [], 'last' => 0];
        }
        $h = intval($s['hours'] ?? 1);
        return [
            'mode'  => $s['mode'],
            'hours' => in_array($h, self::SALES_HOURS, true) ? $h : 1,
            'times' => self::clean_times((array) ($s['times'] ?? [])),
            'last'  => intval($s['last'] ?? 0),
        ];
    }

    /** „HH:MM“, sortiert, ohne Doppelte, höchstens 6 */
    public static function clean_times(array $in) {
        $out = [];
        foreach ($in as $t) {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim((string) $t), $m)) $out[] = sprintf('%02d:%02d', $m[1], $m[2]);
        }
        $out = array_values(array_unique($out));
        sort($out);
        return array_slice($out, 0, 6);
    }

    private static function save_sales($uid, array $s) {
        update_user_meta($uid, self::META_SALES, ['mode' => $s['mode'], 'hours' => $s['hours'], 'times' => $s['times'], 'last' => intval($s['last'])]);
    }

    /** Öffentliche Form für App und Studio */
    private static function sales_payload($uid) {
        $s = self::sales($uid);
        return ['mode' => $s['mode'], 'hours' => $s['hours'], 'times' => $s['times'], 'hours_options' => self::SALES_HOURS,
            'last' => $s['last'] ? wp_date('c', $s['last']) : null, 'timezone' => self::TZ];
    }

    public static function routes() {
        register_rest_route(self::NS, '/staff/notify-prefs', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_prefs'], 'permission_callback' => [__CLASS__, 'perm_staff']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save_prefs'], 'permission_callback' => [__CLASS__, 'perm_staff']],
        ]);
        register_rest_route(self::NS, '/staff/notify-test', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_test'], 'permission_callback' => [__CLASS__, 'perm_staff'],
        ]);
        register_rest_route(self::NS, '/push/relay', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_relay'], 'permission_callback' => '__return_true',
        ]);
    }

    public static function perm_staff() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        return self::is_staff(get_current_user_id()) ? true : new WP_Error('rest_forbidden', 'Nur für Team-Konten.', ['status' => 403]);
    }

    public static function rest_prefs(WP_REST_Request $req) {
        $uid = get_current_user_id();
        $devices = 0;
        foreach (self::tokens() as $m) if (intval($m['user'] ?? 0) === $uid) $devices++;
        $labels = [];
        foreach (self::PREFS as $k => $l) $labels[] = ['key' => $k, 'label' => $l];
        return rest_ensure_response(['ok' => true, 'prefs' => self::prefs($uid), 'labels' => $labels, 'devices' => $devices, 'sales' => self::sales_payload($uid)]);
    }

    public static function rest_save_prefs(WP_REST_Request $req) {
        $uid = get_current_user_id();
        $in = $req->get_param('prefs');
        $sales_in = $req->get_param('sales');
        if (!is_array($in) && !is_array($sales_in)) return new WP_Error('tix_invalid_field', 'Bitte prefs als {key: true|false} bzw. sales senden.', ['status' => 400, 'field' => 'prefs']);
        $sales = self::sales($uid);
        if (is_array($sales_in)) {
            $mode = (string) ($sales_in['mode'] ?? $sales['mode']);
            if (!in_array($mode, self::SALES_MODES, true)) return new WP_Error('tix_invalid_field', 'Unbekannter Modus.', ['status' => 400, 'field' => 'sales.mode']);
            $hours = array_key_exists('hours', $sales_in) ? intval($sales_in['hours']) : $sales['hours'];
            if (!in_array($hours, self::SALES_HOURS, true)) return new WP_Error('tix_invalid_field', 'Bitte 1, 2, 3, 4, 6, 12 oder 24 Stunden.', ['status' => 400, 'field' => 'sales.hours']);
            $times = array_key_exists('times', $sales_in) ? self::clean_times((array) $sales_in['times']) : $sales['times'];
            if ($mode === 'times' && !$times) return new WP_Error('tix_invalid_field', 'Bitte mindestens eine Uhrzeit wählen.', ['status' => 400, 'field' => 'sales.times']);
            // Wechsel: ab jetzt zählen (nichts doppelt melden)
            if ($mode !== $sales['mode'] && !$sales['last']) $sales['last'] = time();
            $sales = ['mode' => $mode, 'hours' => $hours, 'times' => $times, 'last' => $sales['last'] ?: time()];
        }
        $p = self::prefs($uid);
        foreach ((array) $in as $k => $v) if (array_key_exists($k, self::PREFS)) $p[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
        // der alte Schalter „Verkäufe“ schaltet den Modus (an = bisheriger bzw. stündlich, aus = aus)
        if (is_array($in) && array_key_exists('sales_digest', $in) && !is_array($sales_in)) {
            if (!$p['sales_digest']) $sales['mode'] = 'off';
            elseif ($sales['mode'] === 'off') { $sales['mode'] = 'interval'; $sales['last'] = time(); }
        }
        $p['sales_digest'] = $sales['mode'] !== 'off';
        update_user_meta($uid, self::META_PREFS, $p);
        self::save_sales($uid, $sales);
        return rest_ensure_response(['ok' => true, 'prefs' => $p, 'sales' => self::sales_payload($uid)]);
    }

    /** Test-Mitteilung an die eigenen Geräte */
    public static function rest_test(WP_REST_Request $req) {
        $uid = get_current_user_id();
        $sent = self::send_to_users([$uid], 'Test', 'So kommen deine Mitteilungen an.', ['action' => 'staff:start']);
        return rest_ensure_response(['ok' => true, 'sent' => $sent]);
    }

    // ═══════════════ Empfänger ═══════════════

    /**
     * Team-Konten mit Geräten, die ein Ereignis zu diesen Veranstaltern bekommen.
     * Einzelseite: alle Team-Konten mit passender Rolle. Plattform: nur Konten des Veranstalters (keine Plattform-Admins).
     */
    public static function recipients(array $org_ids, $pref, array $roles = self::MANAGER_ROLES) {
        $users = [];
        foreach (self::tokens() as $m) $users[intval($m['user'] ?? 0)] = true;
        unset($users[0]);
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        $out = [];
        foreach (array_keys($users) as $uid) {
            $u = get_user_by('id', $uid);
            if (!$u || !array_intersect($roles, (array) $u->roles)) continue;
            if (!self::prefs($uid)[$pref]) continue;
            if ($multi) {
                $oid = TIX_App_Scope::organizer_id_for_user($uid);
                if (!$oid || !in_array($oid, array_map('intval', $org_ids), true)) continue;
            }
            $out[] = $uid;
        }
        return $out;
    }

    private static function site_name() {
        $n = class_exists('TIX_Site_Config') ? (string) (TIX_Site_Config::payload()['name'] ?? '') : '';
        return $n !== '' ? $n : (string) get_bloginfo('name');
    }

    private static function host() {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
    }

    // ═══════════════ Ereignisse ═══════════════

    /** Verkäufe (Tickets, Umsatz) je Event aus abgeschlossenen Bestellungen im Zeitraum ]$since, $until] (UTC-Sekunden) */
    public static function sales_between($since, $until, array $only_orders = null) {
        $out = [];
        if (!class_exists('TIX_Order')) return $out;
        $tz = wp_timezone();
        $orders = $only_orders !== null
            ? array_filter(array_map(['TIX_Order', 'get'], $only_orders))
            : (array) TIX_Order::query(['status' => ['completed', 'processing'], 'date_from' => wp_date('Y-m-d', $since), 'limit' => 20000]);
        foreach ($orders as $o) {
            if ($only_orders === null) {
                $c = $o->get_date_created();
                if (!$c) continue;
                $ts = (new DateTime($c->format('Y-m-d H:i:s'), $tz))->getTimestamp();
                if ($ts <= $since || $ts > $until) continue;
            }
            foreach ($o->get_items() as $it) {
                $eid = intval($it->get_event_id());
                if (!$eid || get_post_type($eid) !== 'event') continue;
                $out[$eid]['tickets'] = ($out[$eid]['tickets'] ?? 0) + intval($it->get_quantity());
                $out[$eid]['revenue'] = ($out[$eid]['revenue'] ?? 0) + (float) $it->get_total();
            }
        }
        return $out;
    }

    /** Nur die Events, die das Konto sehen darf (Plattform: eigener Veranstalter) */
    private static function sales_for_user($uid, array $per_event) {
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        if (!$multi) return $per_event;
        $oid = intval(TIX_App_Scope::organizer_id_for_user($uid));
        return array_filter($per_event, function ($v, $eid) use ($oid) {
            return $oid && in_array($oid, array_map('intval', (array) TIX_App_Scope::event_org_ids($eid)), true);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Meldung bauen und senden: Anzahl, Umsatz, Top-3-Events; Antippen → Event bzw. Start */
    public static function send_sales($uid, array $events) {
        $tickets = array_sum(array_column($events, 'tickets'));
        if ($tickets <= 0) return 0;
        $revenue = array_sum(array_column($events, 'revenue'));
        uasort($events, function ($a, $b) { return $b['tickets'] <=> $a['tickets']; });
        $money = function ($v) { return number_format((float) $v, (floor($v) == $v) ? 0 : 2, ',', '.') . ' €'; };
        $name = function ($eid) { return html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'); };
        $one = count($events) === 1 ? (int) array_key_first($events) : 0;
        $head = sprintf('%d %s', $tickets, $tickets === 1 ? 'Ticket' : 'Tickets');
        if ($one) {
            $title = self::site_name() . ' · Verkauf';
            $body = $head . ' · ' . $name($one) . ($revenue > 0 ? ' · ' . $money($revenue) : '');
        } else {
            $title = self::site_name() . ' · ' . $head . ($revenue > 0 ? ' · ' . $money($revenue) : '');
            $lines = [];
            foreach (array_slice($events, 0, 3, true) as $eid => $v) $lines[] = $name($eid) . ': ' . $v['tickets'];
            if (count($events) > 3) $lines[] = '+ ' . (count($events) - 3) . ' weitere';
            $body = implode(' · ', $lines);
        }
        return self::send_to_users([$uid], $title, $body, ['action' => $one ? 'staff:event:' . $one : 'staff:start', 'type' => 'sales']);
    }

    /** Konten mit Geräten, die Verkaufs-Mitteilungen bekommen können */
    private static function sales_users() {
        $users = [];
        foreach (self::tokens() as $m) $users[intval($m['user'] ?? 0)] = true;
        unset($users[0]);
        $out = [];
        foreach (array_keys($users) as $uid) {
            $u = get_user_by('id', $uid);
            if ($u && array_intersect(self::MANAGER_ROLES, (array) $u->roles)) $out[] = $uid;
        }
        return $out;
    }

    /** Ist eine gesammelte Meldung fällig? (interval: Stunden seit last; times: feste Uhrzeit seit last erreicht) */
    public static function sales_due(array $s, $now) {
        $last = intval($s['last']);
        if ($s['mode'] === 'interval') return $now - $last >= $s['hours'] * HOUR_IN_SECONDS - 60;
        if ($s['mode'] === 'times') {
            $tz = new DateTimeZone(self::TZ);
            foreach ([0, -1] as $day) {
                $d = (new DateTime('@' . $now))->setTimezone($tz)->modify("$day day");
                foreach (array_reverse($s['times']) as $t) {
                    [$h, $m] = array_map('intval', explode(':', $t));
                    $at = (clone $d)->setTime($h, $m)->getTimestamp();
                    if ($at <= $now) return $at > $last;
                }
            }
        }
        return false;
    }

    /** Alle 5 Minuten: je Konto prüfen, ob eine gesammelte Meldung fällig ist; liegengebliebene Sofort-Meldungen senden */
    public static function cron_digest($now = null) {
        $now = $now ?: time();
        $sent = 0;
        $q = (array) get_option(self::OPT_INSTANT_Q, []);
        if ($q && $now - intval(min(array_column($q, 'at'))) >= self::INSTANT_DELAY) $sent += self::flush_instant();
        $legacy = intval(get_option(self::OPT_DIGEST_AT, 0));
        $cache = [];
        foreach (self::sales_users() as $uid) {
            $s = self::sales($uid);
            if ($s['mode'] !== 'interval' && $s['mode'] !== 'times') continue;
            // Bestand ohne eigenen Merker: ab der letzten globalen Meldung bzw. eine Stunde
            if (!$s['last']) { $s['last'] = $legacy ?: $now - HOUR_IN_SECONDS; self::save_sales($uid, $s); }
            if (!self::sales_due($s, $now)) continue;
            $since = max($s['last'], $now - 7 * DAY_IN_SECONDS);
            $key = $since . '|' . $now;
            if (!isset($cache[$key])) $cache[$key] = self::sales_between($since, $now);
            $events = self::sales_for_user($uid, $cache[$key]);
            if ($events && self::prefs_allows($uid)) $sent += self::send_sales($uid, $events);
            $s['last'] = $now;
            self::save_sales($uid, $s);
        }
        update_option(self::OPT_DIGEST_AT, $now, false);
        return $sent;
    }

    private static function prefs_allows($uid) {
        return self::is_staff($uid);
    }

    /** Sofort: Bestellung in die Warteschlange (die Kasse wartet nicht auf den Versand) */
    public static function queue_instant($order_id) {
        $q = (array) get_option(self::OPT_INSTANT_Q, []);
        $q[] = ['order' => intval($order_id), 'at' => time()];
        update_option(self::OPT_INSTANT_Q, array_slice($q, -500), false);
        if (!wp_next_scheduled(self::CRON_INSTANT)) wp_schedule_single_event(time() + self::INSTANT_DELAY, self::CRON_INSTANT);
    }

    /** Warteschlange senden: je Konto mit Modus „sofort“ eine Meldung für alle gesammelten Bestellungen */
    public static function flush_instant() {
        $q = (array) get_option(self::OPT_INSTANT_Q, []);
        delete_option(self::OPT_INSTANT_Q);
        $orders = array_values(array_unique(array_filter(array_map('intval', array_column($q, 'order')))));
        if (!$orders) return 0;
        $per_event = self::sales_between(0, PHP_INT_MAX, $orders);
        $sent = 0;
        foreach (self::sales_users() as $uid) {
            $s = self::sales($uid);
            if ($s['mode'] !== 'instant') continue;
            $events = self::sales_for_user($uid, $per_event);
            if ($events) $sent += self::send_sales($uid, $events);
            $s['last'] = time();
            self::save_sales($uid, $s);
        }
        return $sent;
    }

    /** Veranstalter eines Events (Einzelseite: leer = alle) */
    private static function event_orgs($eid) {
        return class_exists('TIX_App_Scope') ? TIX_App_Scope::event_org_ids($eid) : [];
    }

    /** Nach einer Bestellung: „ausverkauft“ bzw. „knapp“ sofort (je Event und Stufe einmal). */
    public static function on_order_completed($order_id) {
        if (!class_exists('TIX_Order')) return;
        $order = TIX_Order::get($order_id);
        if (!$order || !class_exists('TIX_App_Scope')) return;
        foreach ((array) TIX_App_Scope::order_event_ids($order) as $eid) self::check_stock(intval($eid));
    }

    /** → 'sold_out' | 'low_stock' | null (für Tests öffentlich) */
    public static function stock_state($eid) {
        $cats = get_post_meta($eid, '_tix_ticket_categories', true);
        if (is_string($cats)) $cats = json_decode($cats, true);
        if (!is_array($cats) || !$cats) return null;
        $total = 0; $left = 0; $limited = 0;
        foreach ($cats as $c) {
            if (!is_array($c) || !empty($c['hidden'])) continue;
            $qty = intval($c['qty'] ?? 0);
            if ($qty <= 0) return null; // unbegrenzte Kategorie: nie ausverkauft/knapp
            $limited++;
            $total += $qty;
            $left += max(0, intval($c['stock'] ?? $qty));
        }
        if (!$limited) return null;
        if ($left <= 0) return 'sold_out';
        if ($left <= max(self::LOW_MIN, (int) floor($total * 0.1))) return 'low_stock';
        return null;
    }

    public static function check_stock($eid) {
        $state = self::stock_state($eid);
        if (!$state) return 0;
        $flag = '_tix_staff_notified_' . $state;
        if (get_post_meta($eid, $flag, true)) return 0;
        update_post_meta($eid, $flag, time());
        $title = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8');
        $users = self::recipients(self::event_orgs($eid), $state);
        return self::send_to_users($users,
            self::site_name() . ' · ' . ($state === 'sold_out' ? 'Ausverkauft' : 'Kontingent knapp'),
            $state === 'sold_out' ? $title . ' ist ausverkauft.' : 'Bei ' . $title . ' sind nur noch wenige Tickets übrig.',
            ['action' => 'staff:event:' . $eid, 'type' => $state]);
    }

    /** Neue Kundenanfrage / Awareness-Meldung: Meta wird nach dem Anlegen gesetzt → am Ende der Anfrage melden. */
    public static function on_support_status($new, $old, $post) {
        if (!$post || $post->post_type !== 'tix_support_ticket' || $old === $new || in_array($old, ['tix_open', 'publish'], true)) return;
        if (!in_array($new, ['tix_open', 'publish'], true)) return;
        self::$support_queue[intval($post->ID)] = true;
    }

    public static function flush_support_queue() {
        $q = array_keys(self::$support_queue);
        self::$support_queue = [];
        foreach ($q as $id) self::notify_support($id);
    }

    public static function notify_support($id) {
        if (get_post_meta($id, '_tix_staff_notified', true)) return 0;
        update_post_meta($id, '_tix_staff_notified', time());
        $aw = get_post_meta($id, '_tix_sp_category', true) === 'awareness';
        $oid = intval(get_post_meta($id, '_tix_sp_organizer_id', true));
        $users = self::recipients($oid ? [$oid] : [], $aw ? 'awareness' : 'support', $aw ? array_merge(self::MANAGER_ROLES, ['tix_awareness']) : self::MANAGER_ROLES);
        return self::send_to_users($users,
            self::site_name() . ' · ' . ($aw ? 'Awareness-Meldung' : 'Neue Kundenanfrage'),
            html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
            ['action' => ($aw ? 'staff:awareness:' : 'staff:support:') . $id, 'type' => $aw ? 'awareness' : 'support', '_skip_guest' => !$aw]);
    }

    // ═══════════════ Versand ═══════════════

    public static function send_to_users(array $uids, $title, $body, array $data = []) {
        if (!$uids) return 0;
        $uids = array_map('intval', $uids);
        $data += ['site' => self::host()];
        // Shop in der Aktion (`staff:event:12@kitchenklub.de`): die Veranstalter-App öffnet damit den richtigen Shop,
        // ohne native Änderung (angetippte Pushes liefern nur data.action)
        if (isset($data['action']) && strpos((string) $data['action'], 'staff:') === 0 && strpos((string) $data['action'], '@') === false) {
            $data['action'] .= '@' . $data['site'];
        }
        // Tests/Protokoll: Versand abfangen (null = normal senden)
        $pre = apply_filters('tix_staff_push_pre_send', null, $uids, $title, $body, $data);
        if ($pre !== null) return intval($pre);
        // Neue Kundenanfrage bekommt das Team schon als Eintrag + Push in der Gast-App (TIX_Support_Model):
        // dasselbe Gerät nicht doppelt benachrichtigen
        $guest = !empty($data['_skip_guest']) ? (array) get_option('_tix_push_tokens', []) : [];
        unset($data['_skip_guest']);
        $groups = ['local_ios' => [], 'local_android' => [], 'relay' => []];
        foreach (self::tokens() as $tok => $m) {
            if (!in_array(intval($m['user'] ?? 0), $uids, true)) continue;
            if (isset($guest[$tok]) && intval($guest[$tok]['user'] ?? 0) === intval($m['user'] ?? 0)) continue;
            $p = ($m['platform'] ?? 'ios') === 'android' ? 'android' : 'ios';
            $b = (string) ($m['bundle'] ?? '');
            if ($b === self::APP_BUNDLE && !self::is_relay_host() && !($p === 'ios' && TIX_Notifications::apns_configured())) {
                $groups['relay'][] = ['token' => $tok, 'platform' => $p];
            } elseif ($p === 'android') {
                $groups['local_android'][] = ['token' => $tok, 'bundle' => $b];
            } else {
                $groups['local_ios'][] = ['token' => $tok, 'bundle' => $b];
            }
        }
        $sent = 0; $invalid = [];
        // iOS: APNs mit dem Bundle des Geräts (Team-Schlüssel gilt für alle Apps)
        $by_topic = [];
        foreach ($groups['local_ios'] as $g) $by_topic[$g['bundle'] !== '' ? $g['bundle'] : ''][] = $g['token'];
        foreach ($by_topic as $topic => $toks) {
            $r = TIX_Notifications::apns_send($toks, $title, $body, $data, $topic !== '' ? $topic : null);
            $sent += $r['sent']; $invalid = array_merge($invalid, $r['invalid']);
        }
        // Android: eigene App über das Firebase-Projekt der Seite; neue App auf evendis.de über deren Dienstkonto
        $own = []; $app = [];
        foreach ($groups['local_android'] as $g) { if ($g['bundle'] === self::APP_BUNDLE) $app[] = $g['token']; else $own[] = $g['token']; }
        if ($own && TIX_Notifications::fcm_configured()) { $r = TIX_FCM::send($own, $title, $body, $data); $sent += $r['sent']; $invalid = array_merge($invalid, $r['invalid']); }
        if ($app && self::relay_fcm_ready()) { $r = self::relay_fcm($app, $title, $body, $data); $sent += $r['sent']; $invalid = array_merge($invalid, $r['invalid']); }
        if ($groups['relay']) { $r = self::relay_out($groups['relay'], $title, $body, $data); $sent += $r['sent']; $invalid = array_merge($invalid, $r['invalid']); }
        if ($invalid) {
            $t = self::tokens();
            foreach ($invalid as $tok) unset($t[$tok]);
            update_option(self::OPT_TOKENS, $t, false);
        }
        return $sent;
    }

    // ═══════════════ Zentrale Weiterleitung ═══════════════

    public static function is_relay_host() {
        return (string) get_option(self::OPT_RELAY_SA, '') !== '';
    }

    public static function relay_fcm_ready() {
        return self::is_relay_host() && class_exists('TIX_FCM');
    }

    private static function relay_secret() {
        return (string) get_option(self::OPT_RELAY_KEY, '');
    }

    private static function relay_fcm(array $tokens, $title, $body, array $data) {
        return TIX_FCM::using(self::OPT_RELAY_SA, function () use ($tokens, $title, $body, $data) {
            return TIX_FCM::send($tokens, $title, $body, $data);
        });
    }

    public static function sign($secret, $host, $ts, $raw) {
        return hash_hmac('sha256', $host . '|' . intval($ts) . '|' . hash('sha256', $raw), $secret);
    }

    /** Shop → evendis.de: Geräte der neuen App (Android immer, iOS ohne eigenen APNs-Schlüssel). */
    private static function relay_out(array $devices, $title, $body, array $data) {
        $secret = self::relay_secret();
        if ($secret === '') return ['sent' => 0, 'invalid' => []];
        $raw = wp_json_encode(['devices' => array_slice($devices, 0, 200), 'title' => (string) $title, 'body' => (string) $body, 'data' => $data]);
        $ts = time(); $host = self::host();
        $url = untrailingslashit((string) get_option(self::OPT_RELAY_URL, self::RELAY_DEFAULT)) . '/wp-json/tixomat/v1/push/relay';
        $res = wp_remote_post($url, ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json', 'X-Tix-Site' => $host, 'X-Tix-Ts' => (string) $ts,
            'X-Tix-Sig' => self::sign($secret, $host, $ts, $raw)], 'body' => $raw]);
        $j = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
        return ['sent' => intval($j['sent'] ?? 0), 'invalid' => array_values(array_filter((array) ($j['invalid'] ?? []), 'is_string'))];
    }

    /** evendis.de: signierte Weiterleitung annehmen und senden. */
    public static function rest_relay(WP_REST_Request $req) {
        if (!self::is_relay_host()) return new WP_Error('tix_relay_off', 'Keine Weiterleitung auf dieser Seite.', ['status' => 404]);
        $host = strtolower((string) $req->get_header('x-tix-site'));
        $ts = intval($req->get_header('x-tix-ts'));
        $sig = (string) $req->get_header('x-tix-sig');
        $sites = get_option(self::OPT_RELAY_SITES, []);
        $secret = is_array($sites) ? (string) ($sites[$host] ?? '') : '';
        $raw = (string) $req->get_body();
        if ($secret === '' || abs(time() - $ts) > 300 || !hash_equals(self::sign($secret, $host, $ts, $raw), $sig)) {
            return new WP_Error('tix_relay_auth', 'Nicht berechtigt.', ['status' => 403]);
        }
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('staff_relay', 600, 3600, $host)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Mitteilungen.', ['status' => 429]);
        }
        $j = json_decode($raw, true);
        $ios = []; $android = [];
        foreach ((array) ($j['devices'] ?? []) as $d) {
            $t = (string) ($d['token'] ?? '');
            if ($t === '') continue;
            if (($d['platform'] ?? '') === 'android') $android[] = $t; else $ios[] = $t;
        }
        $title = mb_substr(sanitize_text_field((string) ($j['title'] ?? '')), 0, 120);
        $body = mb_substr(sanitize_text_field((string) ($j['body'] ?? '')), 0, 400);
        $data = array_map(function ($v) { return is_scalar($v) ? mb_substr((string) $v, 0, 200) : ''; }, (array) ($j['data'] ?? []));
        $data['site'] = $host; // Absender bestimmt die Weiterleitung, nicht der Inhalt
        $sent = 0; $invalid = [];
        if ($android) { $r = self::relay_fcm($android, $title, $body, $data); $sent += $r['sent']; $invalid = array_merge($invalid, $r['invalid']); }
        if ($ios) { $r = TIX_Notifications::apns_send($ios, $title, $body, $data, self::APP_BUNDLE); $sent += $r['sent']; $invalid = array_merge($invalid, $r['invalid']); }
        return rest_ensure_response(['ok' => true, 'sent' => $sent, 'invalid' => $invalid]);
    }

    // ═══════════════ WP-CLI ═══════════════

    /**
     *   wp tixomat staff-push status
     *   wp tixomat staff-push relay-add <host>      (auf evendis.de: Schlüssel für einen Shop anlegen, wird ausgegeben)
     *   wp tixomat staff-push relay-key <schlüssel>  (im Shop: Schlüssel der Weiterleitung eintragen)
     *   wp tixomat staff-push digest                 (fällige Sammelmeldungen jetzt prüfen)
     *   wp tixomat staff-push test <user_id>
     */
    public static function cli($args) {
        $do = $args[0] ?? 'status';
        if ($do === 'relay-add' && !empty($args[1])) {
            $sites = get_option(self::OPT_RELAY_SITES, []);
            $sites = is_array($sites) ? $sites : [];
            $h = strtolower(preg_replace('/^www\./', '', (string) $args[1]));
            $sites[$h] = bin2hex(random_bytes(24));
            update_option(self::OPT_RELAY_SITES, $sites, false);
            WP_CLI::line($sites[$h]);
            return;
        }
        if ($do === 'relay-key' && !empty($args[1])) { update_option(self::OPT_RELAY_KEY, (string) $args[1], false); WP_CLI::success('Schlüssel gespeichert.'); return; }
        if ($do === 'digest') { WP_CLI::success(self::cron_digest() . ' Mitteilung(en).'); return; }
        if ($do === 'test' && !empty($args[1])) { WP_CLI::success(self::send_to_users([intval($args[1])], 'Test', 'Mitteilungen für Veranstalter.', ['action' => 'staff:start']) . ' zugestellt.'); return; }
        $c = ['ios' => 0, 'android' => 0, 'app' => 0];
        foreach (self::tokens() as $m) { $c[($m['platform'] ?? 'ios') === 'android' ? 'android' : 'ios']++; if (($m['bundle'] ?? '') === self::APP_BUNDLE) $c['app']++; }
        WP_CLI::line(sprintf('Geräte: %d iOS, %d Android (davon %d „evendis für Veranstalter“) · Weiterleitung: %s · Relay-Host: %s',
            $c['ios'], $c['android'], $c['app'], self::relay_secret() !== '' ? 'Schlüssel gesetzt' : 'kein Schlüssel', self::is_relay_host() ? 'ja' : 'nein'));
    }
}
