<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Demo_Shop – Demo-Instanz „Eigene Website“ (demo-shop.evendis.de, 1.38.841, Vertrag uebergaben/vertrag-demo-shop.md,
 * DOCUMENTATION §227). Eine ganze Einzel-Seite als Vorführung: Interessenten bekommen einen Zugang, kaufen auf der Homepage
 * mit Testzahlung, scannen am Einlass; nachts setzt der Server alles zurück.
 *
 * Alles hängt an der Option `tix_demo_instance` = '1' (setzt der Server beim Anlegen per WP-CLI) und wirkt nur auf einer
 * Einzel-Seite (nie im Mehr-Veranstalter-Modus) und nur auf Hosts, deren erster Teil mit „demo“ beginnt (oder lokalen
 * Testseiten). Ohne Schalter tut die Klasse nichts – jede Seite verhält sich exakt wie bisher.
 *
 *  - Nie echtes Geld: Live-Schlüssel (Mollie live_…, Stripe sk_/rk_/pk_/whsec-Live) werden beim Lesen und Speichern der
 *    Einstellungen entfernt, Stripe/PayPal laufen fest im Test-/Sandbox-Modus, Überweisung ist aus. Die App-Kasse bietet
 *    die Zahlart „demo“ (sofort bezahlt) plus Anbieter im Testmodus; alles andere wird abgelehnt.
 *  - Mails nur an Tester: Empfänger müssen Admin, Vertriebler oder ein gültiger Demo-Zugang der Instanz sein oder auf der
 *    Erlaubnisliste stehen (Option tix_demo_mail_allow). Der Rest wird verworfen und protokolliert, *.invalid nie versendet.
 *    Absender noreply@evendis.de, Reply-To vertrieb@evendis.de (Option tix_demo_reply_to).
 *  - Abschottung: Alle angemeldeten Nicht-Admins sehen keine Zahlungs-, Lizenz-, Team-, Domain- und Mail-Einstellungen
 *    (403 tix_demo_forbidden), Rechtstexte und Gebühren nur lesend.
 *  - Nie gezählt: /info, /me und /studio/config tragen demo_instance: true; alle Bestellungen und Events gelten als Sandbox
 *    (keine Rechnungen, kein Datenspiegel, keine Verteilung, keine Auszahlung).
 *  - Seed/Reset: wp tixomat demo-shop seed|reset|status [--vorlage=openair|club] → eine JSON-Zeile für die Wache.
 */
class TIX_Demo_Shop {

    const OPT = 'tix_demo_instance';
    const OPT_VORLAGE = 'tix_demo_shop_vorlage';
    const OPT_RESET_AT = 'tix_demo_shop_reset_at';
    const OPT_MAIL_ALLOW = 'tix_demo_mail_allow';
    const OPT_MAIL_LOG = 'tix_demo_mail_log';
    const OPT_REPLY_TO = 'tix_demo_reply_to';
    const OPT_LOGOS = 'tix_demo_shop_logos';
    const FROM = 'noreply@evendis.de';
    const REPLY_TO = 'vertrieb@evendis.de';
    const VORLAGEN = ['openair', 'club'];
    const RESET_H = 4;
    const RESET_M = 30;
    const ORDERS = 50;

    /** Für Nicht-Admins ganz gesperrt (alle Methoden) */
    const BLOCK_ALL = ['/studio/payments', '/studio/mail-transport', '/support/mail-inbox', '/studio/domain', '/organizer/domain', '/license/',
        '/team', '/site/users', '/studio/go-live', '/studio/contract-data', '/studio/operator-access', '/studio/operator-login',
        '/organizer/payout-details', '/ai/settings', '/assistant/settings', '/degaso/admin', '/move/', '/partner/connect', '/partner/connect-verify',
        '/partner/evendis-page', '/platform/'];
    /** Für Nicht-Admins nur lesend */
    const BLOCK_WRITE = ['/studio/legal', '/studio/fees', '/studio/invoice-config', '/studio/modules', '/studio/module-request', '/marketing/matomo',
        '/marketing/tracking', '/marketing/newsletter/sync', '/marketing/newsletter/settings', '/organizer/fee-mode', '/co-organizer/sites', '/syndicate/co-sites', '/syndicate/organizer'];
    /** Für Nicht-Admins gesperrt (Muster): Newsletter wirklich versenden bzw. Testversand über Brevo/Mailchimp (läuft nicht über wp_mail) */
    const BLOCK_RE = ['#^/marketing/newsletter/campaigns/\\d+/(send|test)$#'];

    public static function init() {
        add_filter('option_tix_settings', [__CLASS__, 'filter_settings'], 999);
        add_filter('pre_update_option_tix_settings', [__CLASS__, 'filter_settings'], 999);
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard'], 5, 3);
        add_filter('rest_post_dispatch', [__CLASS__, 'flag'], 60, 3);
        add_filter('wp_mail', [__CLASS__, 'mail_atts'], 1);
        add_filter('pre_wp_mail', [__CLASS__, 'mail_drop'], 2, 2);
        add_filter('wp_mail_from', [__CLASS__, 'mail_from'], 1000);
        add_filter('tix_sandbox_organizer', [__CLASS__, 'sandbox'], 50, 2);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat demo-shop', [__CLASS__, 'cli']);
    }

    // ═══════════════ Schalter ═══════════════

    /** Host darf Demo-Instanz sein: erster Teil beginnt mit „demo“ (demo-shop.evendis.de) oder lokale Testseite. */
    public static function host_ok($host = null) {
        $host = strtolower((string) ($host ?? wp_parse_url(home_url(), PHP_URL_HOST)));
        $ok = $host !== '' && (strpos($host, 'demo') === 0 || strpos($host, '.') === false || preg_match('/\.(test|localhost|invalid)$/', $host));
        return (bool) apply_filters('tix_demo_instance_host_ok', $ok, $host);
    }

    public static function on() {
        // Mehr-Veranstalter-Modus direkt aus der Option (TIX_App_Scope ist beim frühen Laden evtl. noch nicht da)
        return get_option(self::OPT, '0') === '1' && get_option('tix_multi_organizer', '0') !== '1' && self::host_ok();
    }

    public static function vorlage() {
        $v = (string) get_option(self::OPT_VORLAGE, 'openair');
        return in_array($v, self::VORLAGEN, true) ? $v : 'openair';
    }

    /** Profil der Demo-Zugänge (Studio): openair → festival, club → club */
    public static function profile() {
        return self::vorlage() === 'club' ? 'club' : 'festival';
    }

    public static function next_reset_ts($now = null) {
        $now = $now === null ? time() : intval($now);
        $d = (new DateTime('@' . $now))->setTimezone(wp_timezone());
        $d->setTime(self::RESET_H, self::RESET_M, 0);
        if ($d->getTimestamp() <= $now) { $d->modify('+1 day'); $d->setTime(self::RESET_H, self::RESET_M, 0); }
        return $d->getTimestamp();
    }

    public static function last_reset() {
        $t = (string) get_option(self::OPT_RESET_AT, '');
        return $t !== '' ? $t : null;
    }

    public static function studio_url() {
        $o = trim((string) get_option('tix_demo_studio_url', ''));
        if ($o !== '') return trailingslashit($o);
        return 'https://studio.' . wp_parse_url(home_url(), PHP_URL_HOST) . '/';
    }

    public static function home_url() {
        return trailingslashit(home_url('/'));
    }

    /** Für /me (alle Konten) */
    public static function info() {
        return ['vorlage' => self::vorlage(), 'profile' => self::profile(), 'reset_at' => wp_date('c', self::next_reset_ts()), 'last_reset' => self::last_reset(),
            'studio' => self::studio_url(), 'home' => self::home_url(), 'test_only' => true];
    }

    // ═══════════════ Zahlung: nie echtes Geld ═══════════════

    const LIVE_KEYS = ['stripe_secret_key_live', 'stripe_publishable_key_live', 'stripe_webhook_secret_live'];

    /** option_ und pre_update_option_ tix_settings: Live-Schlüssel raus, Test-/Sandbox-Modus fest, Überweisung aus. */
    public static function filter_settings($s) {
        if (!self::on() || !is_array($s)) return $s;
        if (preg_match('/^live_/i', trim((string) ($s['mollie_api_key'] ?? '')))) $s['mollie_api_key'] = '';
        foreach (self::LIVE_KEYS as $k) if (isset($s[$k]) && $s[$k] !== '') $s[$k] = '';
        // „Test“-Felder mit Live-Schlüssel (falsch eingetragen) ebenfalls leeren
        foreach (['stripe_secret_key_test', 'stripe_publishable_key_test'] as $k) if (isset($s[$k]) && preg_match('/_live_/i', (string) $s[$k])) $s[$k] = '';
        $s['stripe_test_mode'] = 1;
        $s['mollie_test_mode'] = 1;
        $s['paypal_sandbox'] = 1;
        $s['bank_transfer_enabled'] = 0;
        return $s;
    }

    /** Anbieter darf zahlen (Testmodus und Test-Schlüssel) – zweite Sicherung in der Kasse */
    public static function provider_test_ok($provider) {
        $s = (array) get_option('tix_settings', []);
        switch ($provider) {
            case 'mollie': return (bool) preg_match('/^test_/', trim((string) ($s['mollie_api_key'] ?? '')));
            case 'stripe': return !empty($s['stripe_test_mode']) && (bool) preg_match('/^(sk|rk)_test_/', trim((string) ($s['stripe_secret_key_test'] ?? '')));
            case 'paypal': return !empty($s['paypal_sandbox']);
        }
        return false;
    }

    /** Kasse: Fehler, wenn die Zahlart hier nicht erlaubt ist (demo/free/wallet ok, Anbieter nur im Testmodus). */
    public static function payment_error($method) {
        if (!self::on()) return null;
        $m = (string) $method;
        $p = strpos($m, ':') !== false ? strstr($m, ':', true) : $m;
        if (in_array($p, ['demo', 'free', 'wallet'], true)) return null;
        if (in_array($p, ['mollie', 'stripe', 'paypal'], true) && self::provider_test_ok($p)) return null;
        return new WP_Error('tix_demo_test_only', 'Im Demo-Shop wird nur mit der Demo-Zahlung oder im Testmodus bezahlt – echtes Geld ist hier gesperrt.', ['status' => 400]);
    }

    /** POST /studio/payments (Admin): Live-Schlüssel und abgeschalteter Testmodus → 400 tix_demo_live_key {field} */
    private static function payments_error(WP_REST_Request $req) {
        $b = (array) $req->get_json_params();
        $err = function ($field, $msg) { return new WP_Error('tix_demo_live_key', $msg, ['status' => 400, 'field' => $field]); };
        if (is_array($b['mollie'] ?? null)) {
            $m = $b['mollie'];
            if (preg_match('/^live_/i', trim((string) ($m['api_key'] ?? '')))) return $err('mollie.api_key', 'Im Demo-Shop nur Test-Schlüssel (test_…) – echtes Geld ist hier gesperrt.');
            if (array_key_exists('test_mode', $m) && empty($m['test_mode'])) return $err('mollie.test_mode', 'Im Demo-Shop bleibt Mollie im Testmodus.');
        }
        if (is_array($b['stripe'] ?? null)) {
            $m = $b['stripe'];
            foreach (['secret_live', 'publishable_live', 'webhook_secret_live'] as $f) {
                if (array_key_exists($f, $m) && $m[$f] !== null && trim((string) $m[$f]) !== '') return $err('stripe.' . $f, 'Im Demo-Shop nur Test-Schlüssel – Live-Schlüssel sind hier gesperrt.');
            }
            foreach (['secret_test', 'publishable_test'] as $f) {
                if (preg_match('/_live_/i', (string) ($m[$f] ?? ''))) return $err('stripe.' . $f, 'Das ist ein Live-Schlüssel. Im Demo-Shop nur Test-Schlüssel (sk_test_…, pk_test_…).');
            }
            if (array_key_exists('test_mode', $m) && empty($m['test_mode'])) return $err('stripe.test_mode', 'Im Demo-Shop bleibt Stripe im Testmodus.');
        }
        if (is_array($b['paypal'] ?? null) && array_key_exists('sandbox', $b['paypal']) && empty($b['paypal']['sandbox'])) return $err('paypal.sandbox', 'Im Demo-Shop bleibt PayPal in der Sandbox.');
        if (is_array($b['bank'] ?? null) && !empty($b['bank']['enabled'])) return $err('bank.enabled', 'Überweisung ist im Demo-Shop gesperrt.');
        return null;
    }

    // ═══════════════ REST: Abschottung und Kennzeichen ═══════════════

    private static function route_match($route, $prefix) {
        if (substr($prefix, -1) === '/') return strpos($route, $prefix) === 0;
        return $route === $prefix || strpos($route, $prefix . '/') === 0;
    }

    /** Route (ohne /tixomat/v1) für Nicht-Admins gesperrt? */
    public static function blocked($route, $method) {
        foreach (self::BLOCK_ALL as $p) if (self::route_match($route, $p)) return true;
        if (strtoupper((string) $method) !== 'GET') foreach (self::BLOCK_WRITE as $p) if (self::route_match($route, $p)) return true;
        foreach (self::BLOCK_RE as $re) if (preg_match($re, $route)) return true;
        return false;
    }

    public static function guard($result, $server, $request) {
        if ($result !== null || !($request instanceof WP_REST_Request) || !self::on()) return $result;
        $route = (string) $request->get_route();
        if (strpos($route, '/tixomat/v1/') !== 0) return $result;
        $r = substr($route, strlen('/tixomat/v1'));
        if (!is_user_logged_in()) return $result; // öffentliche Routen bleiben; Anmeldepflicht prüfen die Routen selbst
        if (current_user_can('manage_options')) {
            if ($r === '/studio/payments' && $request->get_method() === 'POST' && ($e = self::payments_error($request))) return $e;
            return $result;
        }
        if (self::blocked($r, $request->get_method())) {
            return new WP_Error('tix_demo_forbidden', 'Im Demo-Shop gibt es diese Einstellung nur für den Betreiber.', ['status' => 403]);
        }
        return $result;
    }

    /** demo_instance in /me, /info, /studio/config (nur mit Schalter – sonst bleibt die Antwort unverändert) */
    public static function flag($response, $server, $request) {
        if (!($response instanceof WP_REST_Response) || !($request instanceof WP_REST_Request) || !self::on()) return $response;
        $route = (string) $request->get_route();
        if (!in_array($route, ['/tixomat/v1/me', '/tixomat/v1/info', '/tixomat/v1/studio/config'], true)) return $response;
        $d = $response->get_data();
        if (!is_array($d) || $response->get_status() >= 400) return $response;
        $d['demo_instance'] = true;
        if ($route === '/tixomat/v1/me' || $route === '/tixomat/v1/info') $d['demo_shop'] = self::info();
        // /me: Demo-Zugang wie im Demo-Studio (dort nur im Mehr-Veranstalter-Zweig gesetzt)
        if ($route === '/tixomat/v1/me' && ($u = wp_get_current_user()) && $u->ID) {
            $d['is_admin'] = $u->has_cap('manage_options');
            $d['sales'] = class_exists('TIX_Sales') && TIX_Sales::is_sales_user($u);
            $d['demo_access'] = class_exists('TIX_Demo_Access') ? TIX_Demo_Access::me_payload($u) : null;
        }
        $response->set_data($d);
        return $response;
    }

    /** Alle Events/Bestellungen der Instanz gelten als Sandbox (Spiegel, Statistik, Rechnungen) */
    public static function sandbox($v, $oid = 0) {
        return self::on() ? true : $v;
    }

    // ═══════════════ Mails nur an Tester ═══════════════

    public static function reply_to() {
        $r = sanitize_email((string) get_option(self::OPT_REPLY_TO, self::REPLY_TO));
        return is_email($r) ? $r : self::REPLY_TO;
    }

    public static function allow_list() {
        $a = get_option(self::OPT_MAIL_ALLOW, []);
        $a = is_array($a) ? $a : preg_split('/[\s,;]+/', (string) $a);
        $a[] = self::reply_to();
        return array_values(array_unique(array_filter(array_map(function ($x) { return strtolower(trim((string) $x)); }, $a))));
    }

    /** Empfänger erlaubt? Admin, Vertriebler, gültiger Demo-Zugang oder Erlaubnisliste; *.invalid nie. */
    public static function mail_allowed($email) {
        $e = strtolower(trim((string) $email));
        if (!is_email($e) || preg_match('/\.invalid$/', $e)) return false;
        foreach (self::allow_list() as $a) {
            if ($a[0] === '@' ? substr($e, -strlen($a)) === $a : $e === $a) return true;
        }
        $u = get_user_by('email', $e);
        if (!$u) return false;
        if ($u->has_cap('manage_options')) return true;
        if (class_exists('TIX_Sales') && TIX_Sales::is_sales_user($u)) return true;
        if (class_exists('TIX_Demo_Access') && TIX_Demo_Access::is_prospect($u) && TIX_Demo_Access::usable($u->ID)) return true;
        return false;
    }

    private static function addr($s) {
        $s = trim((string) $s);
        return preg_match('/<([^>]+)>/', $s, $m) ? trim($m[1]) : $s;
    }

    private static $dropped = [];

    private static function drop_log($addr, $subject, $where) {
        if (preg_match('/\.invalid$/i', (string) $addr)) return; // Seed-Adressen: still verwerfen
        $log = get_option(self::OPT_MAIL_LOG, []);
        $log = is_array($log) ? $log : [];
        $masked = class_exists('TIX_Demo_Access') ? TIX_Demo_Access::mask_email($addr) : '***';
        $log[] = ['at' => current_time('mysql'), 'to' => $masked, 'subject' => mb_substr(wp_strip_all_tags((string) $subject), 0, 80), 'field' => $where];
        update_option(self::OPT_MAIL_LOG, array_slice($log, -100), false);
        if (function_exists('error_log')) error_log('[tixomat demo-shop] Mail verworfen (' . $where . '): ' . $masked);
    }

    /** wp_mail-Argumente: fremde Empfänger raus (An, Cc, Bcc), Reply-To auf den Vertrieb */
    public static function mail_atts($atts) {
        if (!self::on() || !is_array($atts)) return $atts;
        $subject = (string) ($atts['subject'] ?? '');
        $to = $atts['to'] ?? [];
        $list = is_array($to) ? $to : explode(',', (string) $to);
        $keep = [];
        foreach ($list as $t) {
            if (trim((string) $t) === '') continue;
            if (self::mail_allowed(self::addr($t))) $keep[] = trim((string) $t);
            else self::drop_log(self::addr($t), $subject, 'to');
        }
        $atts['to'] = $keep;
        $headers = $atts['headers'] ?? [];
        $lines = is_array($headers) ? $headers : preg_split("/\r\n|\n|\r/", (string) $headers);
        $out = [];
        foreach ($lines as $h) {
            $h = (string) $h;
            if (trim($h) === '' || strpos($h, ':') === false) { if (trim($h) !== '') $out[] = $h; continue; }
            list($name, $val) = array_map('trim', explode(':', $h, 2));
            $ln = strtolower($name);
            if ($ln === 'reply-to') continue;
            if ($ln === 'cc' || $ln === 'bcc') {
                $ok = [];
                foreach (explode(',', $val) as $a) {
                    if (trim($a) === '') continue;
                    if (self::mail_allowed(self::addr($a))) $ok[] = trim($a); else self::drop_log(self::addr($a), $subject, $ln);
                }
                if ($ok) $out[] = $name . ': ' . implode(', ', $ok);
                continue;
            }
            $out[] = $h;
        }
        $out[] = 'Reply-To: ' . self::reply_to();
        $atts['headers'] = $out;
        return $atts;
    }

    /** Ohne erlaubten Empfänger nicht senden (gilt als erledigt) */
    public static function mail_drop($pre, $atts) {
        if ($pre !== null || !self::on()) return $pre;
        $to = $atts['to'] ?? [];
        $list = array_filter(array_map('trim', is_array($to) ? $to : explode(',', (string) $to)));
        return $list ? $pre : true;
    }

    public static function mail_from($from) {
        return self::on() ? self::FROM : $from;
    }

    // ═══════════════ Seed / Reset ═══════════════

    /** Alles Veränderliche der Instanz löschen: Events (ohne System-Events), Bestellungen, Tickets, Gutscheine, Anfragen, Demo-Künstler, Platz-Reservierungen. */
    public static function wipe() {
        global $wpdb;
        $n = ['events' => 0, 'orders' => 0, 'tickets' => 0, 'coupons' => 0, 'support' => 0, 'artists' => 0];
        $events = [];
        foreach (get_posts(['post_type' => 'event', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids', 'tix_include_system' => 1, 'suppress_filters' => true]) as $eid) {
            if (get_post_meta($eid, '_tix_system_giftcard_event', true) || get_post_meta($eid, '_tix_system_wallet_event', true)) continue;
            $events[] = intval($eid);
        }
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true]) as $tid) {
            wp_delete_post($tid, true);
            $n['tickets']++;
        }
        if (class_exists('TIX_Ticket_DB') && TIX_Ticket_DB::table_exists()) $wpdb->query("DELETE FROM {$wpdb->prefix}" . TIX_Ticket_DB::TABLE_SUFFIX);
        $ot = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ot)) === $ot) {
            while ($ids = array_map('intval', (array) $wpdb->get_col("SELECT id FROM $ot ORDER BY id LIMIT 500"))) {
                $n['orders'] += TIX_Demo::delete_orders($ids);
            }
        }
        foreach ($events as $eid) { wp_delete_post($eid, true); $n['events']++; }
        $sr = $wpdb->prefix . 'tix_seat_reservations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sr)) === $sr) $wpdb->query("DELETE FROM $sr");
        foreach (get_posts(['post_type' => 'tix_support_ticket', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true]) as $sid) {
            wp_delete_post($sid, true);
            $n['support']++;
        }
        if (class_exists('TIX_Artists')) {
            foreach (get_posts(['post_type' => TIX_Artists::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'meta_key' => '_tix_demo_artist', 'meta_value' => '1']) as $a) {
                wp_delete_post($a, true);
                $n['artists']++;
            }
        }
        wp_cache_delete('tix_coupons', 'options');
        $cp = get_option('tix_coupons', []);
        if (is_array($cp) && $cp) { $n['coupons'] = count($cp); update_option('tix_coupons', []); }
        return $n;
    }

    /** Fiktive Inhalte der Vorlage anlegen → Zähler */
    public static function seed_content($vorlage) {
        $out = ['events' => 0, 'orders' => 0, 'tickets' => 0, 'checked_in' => 0, 'giftcards' => 0, 'sponsors' => 0, 'today_event' => 0];
        mt_srand(intval(wp_date('Ymd')) + ($vorlage === 'club' ? 0 : 11));
        $ev = [];
        if ($vorlage === 'club') {
            foreach (TIX_Demo_Content::club_events() as $s) {
                $eid = TIX_Demo::make_from($s, 0, TIX_Demo_Content::ADDRESS_CLUB);
                if (!$eid) continue;
                $ev[$s['key']] = $eid;
                TIX_Demo_Content::no_lookup($eid, array_map('wp_strip_all_tags', preg_split('/<br\s*\/?>/', (string) $s['lineup'])));
            }
            list($o, $t) = TIX_Demo::seed_orders($ev, self::ORDERS, 'past');
            $out['orders'] += $o; $out['tickets'] += $t;
        } else {
            foreach (TIX_Demo_Content::openair_events() as $s) {
                $eid = TIX_Demo::make_from($s, 0, TIX_Demo_Content::ADDRESS_FESTIVAL);
                if (!$eid) continue;
                $ev[$s['key']] = $eid;
                TIX_Demo_Content::no_lookup($eid, array_map('wp_strip_all_tags', preg_split('/<br\s*\/?>/', (string) $s['lineup'])));
            }
            list($o, $t) = TIX_Demo::seed_orders($ev, intval(self::ORDERS * 0.6), 'past');
            $out['orders'] += $o; $out['tickets'] += $t;
            $f = TIX_Demo::seed_festival(0, intval(self::ORDERS * 0.4)); // Festival über zwei Tage mit Programm, Line-up, Künstlern
            $out['events'] += intval($f['events'] ?? 0); $out['orders'] += intval($f['orders'] ?? 0); $out['tickets'] += intval($f['tickets'] ?? 0);
        }
        $out['events'] += count($ev);
        $out['today_event'] = intval($ev['live'] ?? 0);
        // Einlass: vergangenes Event fast ganz, „heute Abend“ teilweise
        foreach (['past' => 90, 'live' => 30] as $k => $pct) {
            if (empty($ev[$k])) continue;
            foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['key' => '_tix_ticket_event_id', 'value' => (string) $ev[$k]]]]) as $tid) {
                if (mt_rand(1, 100) <= $pct && class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'checkin_ticket')) { TIX_Tickets::checkin_ticket($tid, 'demo'); $out['checked_in']++; }
            }
        }
        // Gästeliste am Abend
        if (!empty($ev['live'])) {
            $g = [];
            for ($i = 0; $i < 8; $i++) {
                $in = $i < 3;
                $g[] = ['id' => wp_generate_uuid4(), 'name' => TIX_Demo::FIRST[($i * 3) % count(TIX_Demo::FIRST)] . ' ' . TIX_Demo::LAST[($i * 5) % count(TIX_Demo::LAST)], 'email' => '', 'plus' => $i % 2,
                    'note' => $i === 0 ? 'Presse' : '', 'checked_in' => $in, 'checked_in_count' => $in ? 1 : 0, 'checkin_time' => $in ? current_time('mysql') : '', 'checkin_by' => $in ? 'demo' : '',
                    'added_by' => 'demo', 'added_at' => current_time('mysql')];
            }
            update_post_meta($ev['live'], '_tix_guest_list', $g);
        }
        // Gutscheine (fiktiv)
        wp_cache_delete('tix_coupons', 'options');
        $cp = get_option('tix_coupons', []);
        $cp = is_array($cp) ? $cp : [];
        foreach ([[50, 50], [25, 12.5], [100, 0]] as $j => $gv) {
            $code = 'GS-DEMO-' . strtoupper(wp_generate_password(4, false, false));
            $cp[$code] = ['discount_type' => 'giftcard', 'value' => $gv[0], 'balance' => $gv[1], 'expires' => wp_date('Y-m-d', time() + 3 * YEAR_IN_SECONDS), 'organizer_id' => 0, 'ticket_id' => 0, 'order_id' => 0,
                'redemptions' => $gv[1] < $gv[0] ? [['ts' => current_time('mysql'), 'amount' => $gv[0] - $gv[1], 'channel' => 'bar', 'ref' => 'demo', 'rest' => $gv[1]]] : [],
                'created' => current_time('mysql'), 'description' => 'Beispiel-Gutschein', 'max_uses' => 0, 'used' => 0, 'demo' => 1,
                'gift' => ['recipient_name' => TIX_Demo::FIRST[$j + 5], 'from_name' => TIX_Demo::FIRST[$j + 9], 'message' => 'Viel Spaß!']];
            $out['giftcards']++;
        }
        update_option('tix_coupons', $cp);
        $out['sponsors'] = self::seed_sponsors();
        return $out;
    }

    /** Sponsoren der Seite: fiktive Namen mit erzeugtem Schriftzug (kein fremdes Bild) */
    public static function seed_sponsors() {
        if (!class_exists('TIX_Sponsor_Logos') || !TIX_Sponsor_Logos::ready()) return 0;
        $list = [['Brauhaus Seeblick', '#1F8F5F', 'main'], ['Musterstadt Energie', '#3D7BF7', 'main'], ['Eiscafé am Ufer', '#E8445A', 'partner'], ['Radio Welle Muster', '#8E5CF7', 'partner']];
        $main = []; $partners = [];
        foreach ($list as $i => $s) {
            $logo = self::logo($s[0], $s[1]);
            if (!$logo) return 0;
            $row = ['name' => $s[0] . ' (Beispiel)', 'logo' => ['id' => $logo], 'link' => '', 'active' => true];
            if ($s[2] === 'main') $main[] = $row + ['description' => 'Fiktiver Sponsor der Demo – so erscheint ein Hauptsponsor auf Website und Ticket.', 'button_label' => ''];
            else $partners[] = $row;
        }
        $clean = TIX_Sponsor_Logos::validate(['main' => $main, 'partners' => $partners]);
        if (isset($clean['error'])) return 0;
        TIX_Sponsor_Logos::replace('site', 0, $clean);
        $st = TIX_Sponsor_Logos::settings('site', 0);
        $st['enabled'] = true;
        TIX_Sponsor_Logos::put_settings('site', 0, $st);
        return count($main) + count($partners);
    }

    /** PNG-Schriftzug (GD) einmal als Medium anlegen → Medien-ID (0 ohne GD) */
    private static function logo($name, $hex) {
        $map = get_option(self::OPT_LOGOS, []);
        $map = is_array($map) ? $map : [];
        $k = sanitize_title($name);
        if (!empty($map[$k]) && get_post_type(intval($map[$k])) === 'attachment') return intval($map[$k]);
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return 0;
        $w = strlen($name) * 9 + 24; $h = 36;
        $small = imagecreatetruecolor($w, $h);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 255, 255, 255, 127));
        list($r, $g, $b) = sscanf($hex, '#%02x%02x%02x');
        imagestring($small, 5, 12, 10, $name, imagecolorallocate($small, $r, $g, $b));
        $big = imagecreatetruecolor($w * 4, $h * 4);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 255, 255, 255, 127));
        imagecopyresized($big, $small, 0, 0, 0, 0, $w * 4, $h * 4, $w, $h);
        ob_start(); imagepng($big); $png = (string) ob_get_clean();
        imagedestroy($small); imagedestroy($big);
        $up = wp_upload_bits('demo-sponsor-' . $k . '.png', null, $png);
        if (!empty($up['error'])) return 0;
        $aid = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Beispiel-Sponsor ' . $name, 'post_status' => 'inherit'], $up['file']);
        if (!$aid || is_wp_error($aid)) return 0;
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $up['file']));
        update_post_meta($aid, '_tix_demo_image', 1);
        $map[$k] = intval($aid);
        update_option(self::OPT_LOGOS, $map, false);
        return intval($aid);
    }

    /** Schon Demo-Inhalte da? */
    public static function seeded() {
        return (bool) get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_demo', 'meta_value' => '1', 'suppress_filters' => true]);
    }

    /**
     * seed (nur wenn noch keine Demo-Inhalte da sind) bzw. reset (alles löschen und neu anlegen). Nutzer, Zugänge, Gestaltung,
     * Test-Schlüssel, Rechtstexte und Einstellungen bleiben. → Ergebnis für die Wache (ok, action, vorlage, wiped, seeded …)
     */
    public static function run($action, $vorlage = '') {
        $t0 = microtime(true);
        $base = ['ok' => false, 'action' => $action, 'site' => (string) wp_parse_url(home_url(), PHP_URL_HOST), 'version' => defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : ''];
        if (!self::on()) {
            $why = get_option(self::OPT, '0') !== '1' ? 'Schalter tix_demo_instance ist aus' : (get_option('tix_multi_organizer', '0') === '1' ? 'Mehr-Veranstalter-Modus' : 'Host ist keine Demo-Adresse');
            return $base + ['error' => 'tix_demo_off', 'message' => 'Keine Demo-Instanz (' . $why . ') – nichts geändert.'];
        }
        if ($vorlage !== '') {
            if (!in_array($vorlage, self::VORLAGEN, true)) return $base + ['error' => 'tix_invalid_field', 'message' => 'Vorlage: openair oder club.'];
            update_option(self::OPT_VORLAGE, $vorlage, false);
        }
        $vorlage = self::vorlage();
        if ($action === 'seed' && self::seeded()) {
            return ['ok' => true, 'skipped' => true, 'vorlage' => $vorlage, 'last_reset' => self::last_reset()] + $base;
        }
        if (!class_exists('TIX_Demo') || !class_exists('TIX_Demo_Content') || !class_exists('TIX_Native_Checkout')) return $base + ['error' => 'tix_demo_missing', 'message' => 'Demo-Bausteine fehlen.'];
        // Während des Laufs keine Mails, keine Mitteilungen
        add_filter('pre_wp_mail', '__return_true', 1);
        add_filter('tix_staff_push_recipients', '__return_empty_array', 99);
        $lock = 'tix_demo_shop_running';
        if (get_transient($lock)) { remove_filter('pre_wp_mail', '__return_true', 1); remove_filter('tix_staff_push_recipients', '__return_empty_array', 99); return $base + ['error' => 'tix_demo_busy', 'message' => 'Läuft schon.']; }
        set_transient($lock, 1, 10 * MINUTE_IN_SECONDS);
        try {
            $wiped = $action === 'reset' ? self::wipe() : null;
            $seeded = self::seed_content($vorlage);
        } finally {
            delete_transient($lock);
            remove_filter('pre_wp_mail', '__return_true', 1);
            remove_filter('tix_staff_push_recipients', '__return_empty_array', 99);
        }
        $at = wp_date('c');
        update_option(self::OPT_RESET_AT, $at, false);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return ['ok' => true, 'vorlage' => $vorlage, 'last_reset' => $at, 'next_reset' => wp_date('c', self::next_reset_ts()), 'wiped' => $wiped, 'seeded' => $seeded,
            'ms' => intval((microtime(true) - $t0) * 1000)] + $base;
    }

    public static function reset($vorlage = '') {
        return self::run('reset', $vorlage);
    }

    /**
     * WP-CLI: wp tixomat demo-shop seed|reset|status [--vorlage=openair|club]
     * Ausgabe: genau eine JSON-Zeile (für die Wache); Fehler → Exit-Code 1.
     */
    public static function cli($args, $assoc) {
        $do = (string) ($args[0] ?? '');
        if ($do === 'status') {
            $line = ['ok' => self::on(), 'action' => 'status', 'site' => (string) wp_parse_url(home_url(), PHP_URL_HOST), 'version' => defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '',
                'demo_instance' => self::on(), 'vorlage' => self::vorlage(), 'last_reset' => self::last_reset(), 'next_reset' => wp_date('c', self::next_reset_ts()), 'seeded' => self::on() ? self::seeded() : null];
            WP_CLI::line(wp_json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!self::on()) WP_CLI::halt(1);
            return;
        }
        if (!in_array($do, ['seed', 'reset'], true)) {
            WP_CLI::line(wp_json_encode(['ok' => false, 'error' => 'tix_usage', 'message' => 'Aufruf: wp tixomat demo-shop seed|reset|status [--vorlage=openair|club]'], JSON_UNESCAPED_UNICODE));
            WP_CLI::halt(1);
            return;
        }
        $r = self::run($do, (string) ($assoc['vorlage'] ?? ''));
        WP_CLI::line(wp_json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (empty($r['ok'])) WP_CLI::halt(1);
    }
}

TIX_Demo_Shop::init();
