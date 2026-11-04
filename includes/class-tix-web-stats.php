<?php
/**
 * Besucher → Kauf auf der Marken-Website, ohne Cookies und ohne Einwilligung (Vertrag uebergaben/vertrag-startseite.md §5).
 *
 * Gezählt werden nur Ereignisse je Tag, keine Personen: Seitenaufruf, Event-Seite, Kasse geöffnet, Kauf. Keine IP, kein
 * User-Agent, keine Kennung im Browser. Die Website (marken-web) schickt die Ereignisse serverseitig weiter, das Plugin sieht
 * also nur die Adresse des Website-Servers. Ein Kauf zählt nur einmal je Bestellung und nur, wenn die Bestellung mit
 * passendem Schlüssel existiert und bezahlt ist.
 *
 * Tabelle {prefix}tix_web_stats (day, step, ref, n): ref = Event-ID (event/checkout/purchase), 0 bei view, Bestell-ID bei
 * dem Merker „po“ (Tag 2000-01-01, verhindert doppelte Käufe), Veranstalter-ID bei „oview“ (Aufruf einer Veranstalterseite,
 * evendis.de). Aufbewahrung 400 Tage.
 *
 * evendis (Mehr-Veranstalter-Modus, 1.38.598): Veranstalter sehen nur ihre Events (Inhaber/Team-Admin), ihr „Seitenaufruf“ ist
 * der Aufruf ihrer Veranstalterseite; Admins sehen alles, optional ?organizer=<id>.
 *
 * Statistik Stufe 1 (1.38.617, docs/plan-statistik-marketing.md §4.1):
 *  - Schritt `quote` (Tickets gewählt = weiter zu „Deine Daten“, nach `checkout` = Kasse geöffnet) zusätzlich; `order`/`checkin`
 *    rechnet die Auswertung aus Bestellungen und Tickets.
 *  - Dimensionen in {prefix}tix_web_stats_dim (day, step, org_id, event_id, channel web|app, source, medium, campaign, n):
 *    weiter nur Tageszähler ohne Personenbezug. Quelle = utm_source, sonst Referrer-Host (medium referral), sonst „direct“.
 *    Kleine Gruppen (< 3) fasst die Auswertung zusammen.
 *  - App-Treffer: `src=app` (Schritte event/quote/checkout, nur in den Dimensionen, nicht in den Website-Zahlen).
 *  - Bots: Kennungen bekannter Bots werden geprüft, nie gespeichert.
 *  - Stufe 2: `push_open` (App, Mitteilung geöffnet) mit `campaign` = Kennung der Sendung (push-xxxxxxxx), nur Dimension.
 *  - Geschätzte Besuche (F1 B, Schalter tix_web_visits, Vorgabe AUS bis zur Rechtsprüfung): die Website schickt `vh`, einen
 *    Hash, den sie mit einem täglich neuen Salz im Arbeitsspeicher aus IP, Browser und Host bildet. Das Plugin hält nur
 *    md5(vh) für den laufenden Tag in {prefix}tix_web_visits und löscht frühere Tage; gespeichert bleibt die Zahl (`visit`).
 */
if (!defined('ABSPATH')) exit;

class TIX_Web_Stats {
    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const STEPS = ['view', 'event', 'checkout', 'purchase'];
    /** Schritte, die eine Website bzw. App melden darf */
    const HIT_STEPS = ['view', 'event', 'quote', 'checkout', 'purchase', 'push_open'];
    const DIM_VERSION = '2'; // 2 = utm_id (Kampagnen-ID der Werbung) als Dimension
    const OPT_VISITS = 'tix_web_visits';
    const BOT = '/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|monitor|curl|wget|python|axios|node-fetch|scrapy|httpclient|java\//i';
    const KEEP_DAYS = 400;
    const PER_MINUTE = 3000;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('init', [__CLASS__, 'maybe_create'], 20);
        add_action('init', [__CLASS__, 'maybe_create_dim'], 21);
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'tix_web_stats'; }

    public static function maybe_create() {
        if (get_option('tix_web_stats_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta("CREATE TABLE $t (
            day DATE NOT NULL,
            step VARCHAR(10) NOT NULL,
            ref BIGINT UNSIGNED NOT NULL DEFAULT 0,
            n INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (day, step, ref)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_web_stats_db', self::DB_VERSION, true);
    }

    public static function dim_table() { global $wpdb; return $wpdb->prefix . 'tix_web_stats_dim'; }
    public static function visits_table() { global $wpdb; return $wpdb->prefix . 'tix_web_visits'; }

    public static function maybe_create_dim() {
        if (get_option('tix_web_stats_dim_db') === self::DIM_VERSION) return;
        global $wpdb;
        // 1 → 2: Spalte utm_id in den Primärschlüssel (dbDelta ändert Primärschlüssel nicht)
        $d0 = self::dim_table();
        if (get_option('tix_web_stats_dim_db') === '1' && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $d0)) === $d0) {
            if (!$wpdb->get_var("SHOW COLUMNS FROM $d0 LIKE 'utm_id'")) $wpdb->query("ALTER TABLE $d0 ADD COLUMN utm_id VARCHAR(64) NOT NULL DEFAULT '' AFTER campaign");
            $wpdb->query("ALTER TABLE $d0 DROP PRIMARY KEY, ADD PRIMARY KEY (day, step, org_id, event_id, channel, source, medium, campaign, utm_id)");
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $d = self::dim_table(); $v = self::visits_table();
        dbDelta("CREATE TABLE $d (
            day DATE NOT NULL,
            step VARCHAR(10) NOT NULL,
            org_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            channel VARCHAR(10) NOT NULL DEFAULT 'web',
            source VARCHAR(64) NOT NULL DEFAULT '',
            medium VARCHAR(64) NOT NULL DEFAULT '',
            campaign VARCHAR(64) NOT NULL DEFAULT '',
            utm_id VARCHAR(64) NOT NULL DEFAULT '',
            n INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (day, step, org_id, event_id, channel, source, medium, campaign, utm_id),
            KEY event_day (event_id, day),
            KEY org_day (org_id, day)
        ) $c;");
        dbDelta("CREATE TABLE $v (
            day DATE NOT NULL,
            scope BIGINT UNSIGNED NOT NULL DEFAULT 0,
            h CHAR(32) NOT NULL,
            PRIMARY KEY  (day, scope, h)
        ) $c;");
        update_option('tix_web_stats_dim_db', self::DIM_VERSION, true);
    }

    /** Geschätzte Besuche eingeschaltet? (Vorgabe aus, bis der Anwalt geprüft hat) */
    public static function visits_on() { return get_option(self::OPT_VISITS, '0') === '1'; }

    /** Wert für eine Dimension: klein, ohne Leer-/Sonderzeichen, höchstens 64 Zeichen */
    public static function norm($v, $len = 64) {
        $v = mb_strtolower(trim(wp_strip_all_tags((string) $v)));
        $v = preg_replace('/[^\p{L}\p{N}._+-]+/u', '-', $v);
        return mb_substr(trim((string) $v, '-'), 0, $len);
    }

    /** Herkunft eines Treffers: [source, medium, campaign, utm_id] */
    public static function origin(WP_REST_Request $req) {
        $src = self::norm($req->get_param('utm_source') ?? $req->get_param('us'));
        $med = self::norm($req->get_param('utm_medium') ?? $req->get_param('um'));
        $cmp = self::norm($req->get_param('utm_campaign') ?? $req->get_param('uc'));
        $uid = (string) ($req->get_param('utm_id') ?? $req->get_param('ui'));
        $uid = preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $uid) ? $uid : '';
        if ($src !== '') return [$src, $med, $cmp, $uid];
        $host = strtolower(preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($req->get_param('ref_host') ?? $req->get_param('rh'))));
        $host = preg_replace('/^www\./', '', $host);
        $own = preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
        if ($host !== '' && $host !== $own && strpos($host, '.') !== false) return [mb_substr($host, 0, 64), 'referral', $cmp, $uid];
        return ['direct', '', $cmp, $uid];
    }

    private static function bump_dim($day, $step, $org, $event, $channel, array $o) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("INSERT INTO " . self::dim_table() . " (day, step, org_id, event_id, channel, source, medium, campaign, utm_id, n) VALUES (%s, %s, %d, %d, %s, %s, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1",
            $day, $step, $org, $event, $channel, $o[0], $o[1], $o[2], $o[3] ?? ''));
    }

    /** Veranstalter eines Events (0 auf Einzel-Seiten) */
    private static function org_of($event) {
        if (!$event || !self::multi()) return 0;
        return intval(get_post_meta($event, '_tix_organizer_id', true));
    }

    public static function routes() {
        register_rest_route(self::NS, '/public/stats/hit', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_hit'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/studio/stats/funnel', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_funnel'], 'permission_callback' => [__CLASS__, 'perm_funnel']]);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    /** Einzel-Seite: wie /studio/website. evendis: Admin oder Inhaber/Team-Admin eines freigegebenen Veranstalters. */
    public static function perm_funnel(WP_REST_Request $req) {
        if (!self::multi()) return TIX_Website::perm($req);
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (current_user_can('manage_options')) return true;
        if (TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) return true;
        return new WP_Error('rest_forbidden', 'Die Auswertung sehen Inhaber und Admins.', ['status' => 403]);
    }

    private static function today() { return wp_date('Y-m-d'); }

    private static function bump($day, $step, $ref) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("INSERT INTO " . self::table() . " (day, step, ref, n) VALUES (%s, %s, %d, 1) ON DUPLICATE KEY UPDATE n = n + 1", $day, $step, $ref));
    }

    /** Grobe Bremse gegen Fluten: höchstens PER_MINUTE Ereignisse je Minute für die ganze Seite */
    private static function throttled() {
        $k = 'tix_ws_rate_' . gmdate('YmdHi');
        $n = (int) get_transient($k);
        if ($n >= self::PER_MINUTE) return true;
        set_transient($k, $n + 1, 120);
        return false;
    }

    /**
     * POST /public/stats/hit {step, event_id?, order_id?, order_key?, organizer_id?, src? web|app, utm_source?, utm_medium?,
     * utm_campaign?, ref_host?, vh?} → 204 (auch wenn nichts gezählt wurde)
     */
    public static function rest_hit(WP_REST_Request $req) {
        $done = new WP_REST_Response(null, 204);
        $step = (string) $req->get_param('step');
        if (!in_array($step, self::HIT_STEPS, true) || self::throttled()) return $done;
        if (preg_match(self::BOT, (string) $req->get_header('user-agent'))) return $done;
        $channel = $req->get_param('src') === 'app' ? 'app' : 'web';
        // Mitteilung in der App geöffnet (Statistik Stufe 2): nur mit Kennung einer echten Sendung, nur in den Dimensionen
        if ($step === 'push_open') {
            $cmp = (string) ($req->get_param('campaign') ?? $req->get_param('utm_campaign'));
            $sc = $channel === 'app' && class_exists('TIX_Notifications') ? TIX_Notifications::campaign_scope($cmp) : null;
            if (!$sc) return $done;
            self::maybe_create_dim();
            self::bump_dim(self::today(), 'push_open', $sc['organizer_id'], $sc['event_id'], 'app', ['push', 'push', TIX_Notifications::campaign_key($cmp), '']);
            return $done;
        }
        // Apps melden keine Seitenaufrufe und keine Käufe (die zählen aus den Bestellungen)
        if ($channel === 'app' && !in_array($step, ['event', 'quote', 'checkout'], true)) return $done;
        $event = max(0, intval($req->get_param('event_id')));
        // Event-Schritte nur mit echtem Event (sonst verfälschen fremde/alte IDs die Summe)
        if ($event && get_post_type($event) !== 'event') return $done;
        if ($step !== 'view' && !$event) return $done;
        $day = self::today();
        if ($step === 'purchase') {
            $oid = intval($req->get_param('order_id'));
            $key = (string) $req->get_param('order_key');
            if (!$oid || $key === '' || !class_exists('TIX_Order')) return $done;
            global $wpdb;
            $o = $wpdb->get_row($wpdb->prepare("SELECT id, status, order_key FROM " . TIX_Order::table_name() . " WHERE id = %d", $oid));
            if (!$o || !hash_equals((string) $o->order_key, $key) || !in_array($o->status, ['completed', 'processing'], true)) return $done;
            // einmal je Bestellung: Merker anlegen, nur wenn neu
            $new = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO " . self::table() . " (day, step, ref, n) VALUES ('2000-01-01', 'po', %d, 1)", $oid));
            if (!$new) return $done;
        }
        $org = max(0, intval($req->get_param('organizer_id')));
        if ($org && get_post_type($org) !== 'tix_organizer') $org = 0;
        $o = self::origin($req);
        self::maybe_create_dim();
        if ($channel === 'web') {
            self::bump($day, $step, $step === 'view' ? 0 : $event);
            // Veranstalterseite (evendis): zusätzlich je Veranstalter
            if ($step === 'view' && $org) self::bump($day, 'oview', $org);
            if ($step === 'view') self::visit($day, $org, (string) $req->get_param('vh'));
        }
        self::bump_dim($day, $step, $step === 'view' ? $org : self::org_of($event), $step === 'view' ? 0 : $event, $channel, $o);
        if (mt_rand(1, 500) === 1) self::purge();
        return $done;
    }

    /** Geschätzter Besuch: einmal je Tag und Hash (Seite bzw. Veranstalterseite) */
    private static function visit($day, $org, $vh) {
        if (!self::visits_on() || !preg_match('/^[a-f0-9]{32,128}$/', $vh)) return;
        global $wpdb;
        $h = md5($vh . '|' . wp_salt('nonce'));
        foreach (array_unique([0, intval($org)]) as $scope) {
            $new = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO " . self::visits_table() . " (day, scope, h) VALUES (%s, %d, %s)", $day, $scope, $h));
            if (!$new) continue;
            self::bump($day, $scope ? 'ovisit' : 'visit', $scope);
            self::bump_dim($day, 'visit', $scope, 0, 'web', ['', '', '', '']);
        }
        // frühere Tage nie aufheben
        if (mt_rand(1, 50) === 1) $wpdb->query($wpdb->prepare("DELETE FROM " . self::visits_table() . " WHERE day < %s", $day));
    }

    private static function purge() {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::dim_table() . " WHERE day < %s", wp_date('Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS)));
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::visits_table() . " WHERE day < %s", wp_date('Y-m-d')));
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " WHERE day < %s AND step <> 'po'", wp_date('Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS)));
        // Merker „po“ bleiben (eine Zeile je Bestellung), sonst zählte ein später erneut geöffneter Kauf doppelt
    }

    /** GET /studio/stats/funnel?days=30 → {from, to, totals, days[], events[]} */
    public static function rest_funnel(WP_REST_Request $req) {
        global $wpdb;
        $days = min(365, max(1, intval($req->get_param('days') ?: 30)));
        $to = self::today();
        $from = wp_date('Y-m-d', strtotime($to . ' 12:00:00') - ($days - 1) * DAY_IN_SECONDS);
        $t = self::table();
        // Bereich: Veranstalter (evendis, kein Admin) bzw. ?organizer= für Admins; 0 = ganze Seite
        $org = 0;
        if (self::multi()) {
            $org = current_user_can('manage_options') ? max(0, intval($req->get_param('organizer'))) : intval(TIX_App_Scope::organizer_id_for_user());
        }
        if ($org) {
            $ids = array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [TIX_App_Scope::event_meta_clause($org)]]));
            $in = $ids ? implode(',', $ids) : '0';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT day, CASE step WHEN 'oview' THEN 'view' WHEN 'ovisit' THEN 'visit' ELSE step END AS step, SUM(n) AS n FROM $t WHERE day BETWEEN %s AND %s AND ((step IN ('oview','ovisit') AND ref = %d) OR (step IN ('event','quote','checkout','purchase') AND ref IN ($in))) GROUP BY day, CASE step WHEN 'oview' THEN 'view' WHEN 'ovisit' THEN 'visit' ELSE step END", $from, $to, $org));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT day, step, SUM(n) AS n FROM $t WHERE day BETWEEN %s AND %s AND step IN ('view','visit','event','quote','checkout','purchase') GROUP BY day, step", $from, $to));
        }
        $byDay = [];
        // quote/visit seit 1.38.617 (Stufe 1): ältere Studios lesen nur die vier alten Schlüssel
        $steps = array_merge(self::STEPS, ['quote', 'visit']);
        $totals = array_fill_keys($steps, 0);
        foreach ((array) $rows as $r) {
            $byDay[$r->day][$r->step] = intval($r->n);
            $totals[$r->step] += intval($r->n);
        }
        $list = [];
        for ($i = 0; $i < $days; $i++) {
            $d = wp_date('Y-m-d', strtotime($from . ' 12:00:00') + $i * DAY_IN_SECONDS);
            $list[] = ['date' => $d] + array_merge(array_fill_keys($steps, 0), $byDay[$d] ?? []);
        }
        $ev = $wpdb->get_results($wpdb->prepare("SELECT ref, step, SUM(n) AS n FROM $t WHERE day BETWEEN %s AND %s AND step IN ('event','quote','checkout','purchase') AND ref > 0" . ($org ? " AND ref IN ($in)" : '') . " GROUP BY ref, step", $from, $to));
        $events = [];
        foreach ((array) $ev as $r) {
            $id = intval($r->ref);
            if (!isset($events[$id])) $events[$id] = ['id' => $id, 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($id, '_tix_date_start', true), 'event' => 0, 'quote' => 0, 'checkout' => 0, 'purchase' => 0];
            $events[$id][$r->step] = intval($r->n);
        }
        usort($events, function ($a, $b) { return $b['event'] <=> $a['event'] ?: $b['purchase'] <=> $a['purchase']; });
        return rest_ensure_response(['scope' => $org ? ['organizer' => $org, 'name' => html_entity_decode(get_the_title($org), ENT_QUOTES, 'UTF-8'), 'view' => 'organizer_page'] : ['organizer' => 0, 'view' => 'site'], 'from' => $from, 'to' => $to, 'totals' => $totals, 'visits_enabled' => self::visits_on(), 'days' => $list, 'events' => array_slice(array_values($events), 0, 20)]);
    }
}

TIX_Web_Stats::init();
