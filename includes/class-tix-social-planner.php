<?php
/**
 * TIX_Social_Planner – Social-Planer aus Event-Daten, Stufe 1 (M3, Auftrag Marketing/Reichweite/Barrierefreiheit 13.11.2026;
 * F-M1 Empfehlung A: erst Erinnerung mit fertigem Bild und Text, direktes Posten später; ab 1.38.811).
 *
 * Je Event (Schalter, Vorgabe aus) entsteht ein Posting-Plan mit Terminen aus Event-Daten und Verkaufsstand:
 *   announce (Ankündigung) · presale_start (Vorverkaufsstart) · lineup (Line-up) · price_phase_end (Preisphase endet) ·
 *   few_left (nur noch wenige, ab 80 % verkauft) · day_before (Tag vorher) · today (heute) · thanks (Danke/Fotos)
 * Zum Termin bekommt das Team eine Erinnerung: Mitteilung in der Veranstalter-App (Staff-Push „Social-Planer“) und Mail
 * mit Bild, Text und Link. „Teilen“ (App/Studio) öffnet Instagram bzw. das Teilen-Menü.
 * Ändert sich das Event, wandern die Termine automatisch mit (Speichern + stündlicher Abgleich); bearbeitete Texte bleiben,
 * erledigte/übersprungene/erinnerte Posts bleiben unverändert. Eigene Posts lassen sich ergänzen.
 *
 * Tabelle {prefix}tix_social_posts (JSON in data). Schalter je Event: TIX_Object_Data (tix_event_social).
 * REST (Inhaber/Team-Admin/Admin mit Zugriff auf das Event):
 *   GET    /events/{id}/social-plan                     → {ok, event_id, enabled, posts[…], occasions{key: Bezeichnung}}
 *   POST   /events/{id}/social-plan {enabled}           → an/aus (an = Plan sofort anlegen; aus = offene Posts entfernen)
 *   POST   /events/{id}/social-plan/posts {text, due_at, format?} → eigener Post (201)
 *   POST   /events/{id}/social-plan/posts/{post} {text?, due_at?, status? planned|done|skipped, format?}
 *   POST   /events/{id}/social-plan/posts/{post}/ai     → Text per KI neu (Social-Texte, Instagram)
 *   DELETE /events/{id}/social-plan/posts/{post}        → nur eigene Posts (Regel-Posts: status skipped)
 */
if (!defined('ABSPATH')) exit;

class TIX_Social_Planner {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const CRON = 'tix_social_planner';
    const KIND = 'tix_event_social';
    const FEW_LEFT = 0.8;
    const OCCASIONS = [
        'announce' => 'Ankündigung', 'presale_start' => 'Vorverkaufsstart', 'lineup' => 'Line-up', 'price_phase_end' => 'Preisphase endet',
        'few_left' => 'Nur noch wenige Tickets', 'day_before' => 'Tag vorher', 'today' => 'Heute', 'thanks' => 'Danke & Fotos', 'custom' => 'Eigener Post',
    ];
    const STATUSES = ['planned', 'reminded', 'done', 'skipped'];
    const FORMATS = ['feed', 'story'];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 25);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('cron_schedules', function ($s) { $s['tix_15min'] = ['interval' => 900, 'display' => 'Alle 15 Minuten']; return $s; });
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 600, 'tix_15min', self::CRON); }, 52);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('save_post_event', function ($id) { if (!wp_is_post_revision($id) && self::enabled($id)) self::sync($id); }, 50);
    }

    public static function t() { global $wpdb; return $wpdb->prefix . 'tix_social_posts'; }

    public static function maybe_install() {
        if (get_option('tix_social_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::t() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            occasion VARCHAR(20) NOT NULL,
            due_at DATETIME NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'planned',
            data LONGTEXT NULL,
            reminded_at DATETIME NULL,
            created DATETIME NOT NULL,
            updated DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY event (event_id),
            KEY due (status, due_at)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_social_db', self::DB_VERSION, true);
    }

    private static function now() { return current_time('mysql', true); }
    private static function bad($f, $m, $code = 'tix_invalid_field', $st = 400) { return new WP_Error($code, $m, ['status' => $st, 'field' => $f]); }

    public static function enabled($eid) {
        if (!class_exists('TIX_Object_Data')) return false;
        $v = TIX_Object_Data::read(self::KIND, intval($eid), null);
        return is_array($v) && !empty($v['enabled']);
    }

    // ═══════════════ Regeln ═══════════════

    /** Lokale Zeit (Y-m-d H:i) → UTC DATETIME */
    private static function utc($local) {
        try { $d = new DateTimeImmutable($local, wp_timezone()); } catch (Exception $e) { return null; }
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function link($eid) {
        if (class_exists('TIX_Organizer_Link') && method_exists('TIX_Organizer_Link', 'share_url') && intval(get_post_meta($eid, '_tix_organizer_id', true))) return TIX_Organizer_Link::share_url($eid);
        $u = (string) get_permalink($eid);
        if (class_exists('TIX_Customer_URL')) $u = TIX_Customer_URL::to($u);
        return add_query_arg(['utm_source' => 'social', 'utm_medium' => 'planner'], $u);
    }

    private static function acts($eid) {
        if (!class_exists('TIX_Lineup_Links') || !method_exists('TIX_Lineup_Links', 'acts')) return [];
        $a = TIX_Lineup_Links::acts($eid);
        $names = [];
        foreach ((array) $a as $x) { $n = is_array($x) ? (string) ($x['name'] ?? '') : (string) $x; if ($n !== '') $names[] = $n; }
        return array_slice($names, 0, 6);
    }

    /** Vorlage für Text (ohne KI) */
    public static function text_for($occasion, $eid, array $ctx = []) {
        $title = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8');
        $date = (string) get_post_meta($eid, '_tix_date_start', true);
        $d = $date ? wp_date('d.m.', strtotime($date . ' 12:00:00')) : '';
        $loc = trim((string) get_post_meta($eid, '_tix_location', true));
        $link = self::link($eid);
        $doors = (string) get_post_meta($eid, '_tix_time_doors', true) ?: (string) get_post_meta($eid, '_tix_time_start', true);
        switch ($occasion) {
            case 'announce': return "Neu: $title am $d" . ($loc !== '' ? " in $loc" : '') . ". Tickets gibt's jetzt: $link";
            case 'presale_start': return "Der Vorverkauf für $title läuft! Sichere dir jetzt deine Tickets: $link";
            case 'lineup': return "Das Line-up für $title steht: " . implode(', ', $ctx['acts'] ?? []) . ". Tickets: $link";
            case 'price_phase_end': return 'Nur noch bis ' . ($ctx['until_de'] ?? '') . ': ' . ($ctx['phase'] ?? 'der aktuelle Preis') . " für $title. Danach wird's teurer – $link";
            case 'few_left': return "Fast ausverkauft! Nur noch wenige Tickets für $title: $link";
            case 'day_before': return "Morgen ist es so weit: $title! Letzte Tickets: $link";
            case 'today': return "Heute: $title!" . ($doors !== '' ? " Einlass $doors Uhr." : '') . " Tickets: $link";
            case 'thanks': return "Danke für eine unvergessliche Nacht bei $title! Die Fotos kommen bald.";
        }
        return '';
    }

    /** Soll-Liste der Regel-Posts → [occasion => [due_utc, ctx]] (nur Termine in der Zukunft) */
    public static function rules($eid, $now = null) {
        $now = $now ?: time();
        $date = substr((string) get_post_meta($eid, '_tix_date_start', true), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
        $start_t = (string) get_post_meta($eid, '_tix_time_start', true) ?: '20:00';
        $ev_ts = strtotime(self::utc("$date $start_t") . ' UTC');
        $out = [];
        $add = function ($k, $local, $ctx = []) use (&$out, $now) { $u = self::utc($local); if ($u && strtotime($u . ' UTC') > $now) $out[$k] = [$u, $ctx]; };
        // Ankündigung: 1 h nach dem Einschalten bzw. Veröffentlichen, sofern mehr als 2 Tage bis zum Event
        if (get_post_status($eid) === 'publish' && $ev_ts - $now > 2 * DAY_IN_SECONDS) $out['announce'] = [gmdate('Y-m-d H:i:s', $now + HOUR_IN_SECONDS), []];
        $ps = trim((string) get_post_meta($eid, '_tix_presale_start', true));
        if ($ps !== '') $add('presale_start', strlen($ps) <= 10 ? "$ps 10:00" : str_replace('T', ' ', substr($ps, 0, 16)));
        $acts = self::acts($eid);
        if ($acts && $ev_ts - $now > 5 * DAY_IN_SECONDS) {
            $mid = max($now + DAY_IN_SECONDS, intval(($now + $ev_ts) / 2));
            $add('lineup', wp_date('Y-m-d', $mid) . ' 18:00', ['acts' => $acts]);
        }
        $best = null;
        foreach ((array) get_post_meta($eid, '_tix_ticket_categories', true) as $cat) foreach ((array) ($cat['phases'] ?? []) as $ph) {
            $u = (string) ($ph['until'] ?? '');
            if ($u && strtotime("$u 12:00") > $now && (!$best || $u < $best[0])) $best = [$u, (string) ($ph['name'] ?? '') ?: 'der Frühbucher-Preis'];
        }
        if ($best && $best[0] < $date) $add('price_phase_end', $best[0] . ' 12:00', ['phase' => $best[1], 'until_de' => wp_date('d.m.', strtotime($best[0] . ' 12:00:00'))]);
        $add('day_before', wp_date('Y-m-d', strtotime($date . ' 12:00:00') - DAY_IN_SECONDS) . ' 18:00');
        $add('today', "$date 12:00");
        $add('thanks', wp_date('Y-m-d', strtotime($date . ' 12:00:00') + DAY_IN_SECONDS) . ' 13:00');
        return $out;
    }

    private static function image($eid) {
        $id = intval(get_post_thumbnail_id($eid));
        $u = $id ? (string) wp_get_attachment_image_url($id, 'large') : '';
        return $u !== '' ? $u : null;
    }

    // ═══════════════ Abgleich ═══════════════

    private static function rows($eid) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE event_id = %d ORDER BY due_at ASC, id ASC", intval($eid)), ARRAY_A);
    }

    private static function data($r) { $d = json_decode((string) ($r['data'] ?? ''), true); return is_array($d) ? $d : []; }

    /** Plan an Event-Daten angleichen (offene Regel-Posts: Termin + Text, solange nicht bearbeitet) */
    public static function sync($eid, $now = null) {
        self::maybe_install();
        global $wpdb;
        $eid = intval($eid);
        $want = self::rules($eid, $now);
        $have = [];
        foreach (self::rows($eid) as $r) if ($r['occasion'] !== 'custom') $have[$r['occasion']] = $r;
        $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
        foreach ($want as $occ => [$due, $ctx]) {
            $text = self::text_for($occ, $eid, $ctx);
            if (!isset($have[$occ])) {
                $wpdb->insert(self::t(), ['event_id' => $eid, 'organizer_id' => $oid, 'occasion' => $occ, 'due_at' => $due, 'status' => 'planned',
                    'data' => wp_json_encode(['text' => $text, 'edited' => false, 'format' => $occ === 'today' || $occ === 'day_before' ? 'story' : 'feed']), 'created' => self::now(), 'updated' => self::now()]);
                continue;
            }
            $r = $have[$occ];
            if ($r['status'] !== 'planned') continue;
            $d = self::data($r);
            // Ankündigung behält ihren ersten Termin (sonst wanderte sie bei jedem Abgleich eine Stunde weiter)
            $set = $occ === 'announce' ? [] : ['due_at' => $due];
            if (empty($d['edited'])) { $d['text'] = $text; $set['data'] = wp_json_encode($d); }
            if ($set) $wpdb->update(self::t(), $set + ['updated' => self::now()], ['id' => intval($r['id'])]);
        }
        // Regel fällt weg (z. B. Preisphase gelöscht): offene, unbearbeitete Posts entfernen
        foreach ($have as $occ => $r) {
            if (isset($want[$occ]) || $r['status'] !== 'planned' || in_array($occ, ['few_left', 'announce'], true)) continue;
            if (empty(self::data($r)['edited'])) $wpdb->delete(self::t(), ['id' => intval($r['id'])]);
        }
    }

    // ═══════════════ Erinnerung ═══════════════

    public static function cron() {
        self::maybe_install();
        global $wpdb;
        // „nur noch wenige“: ab 80 % der Kapazität verkauft (einmal je Event)
        $ids = [];
        if (class_exists('TIX_Object_Data')) foreach ((array) TIX_Object_Data::all(self::KIND) as $id => $v) if (is_array($v) && !empty($v['enabled'])) $ids[] = intval($id);
        foreach ($ids as $eid) {
            if (get_post_type($eid) !== 'event') continue;
            self::sync($eid); // Termine nachziehen (Verkaufsstand, Preisphasen, Änderungen ohne Speichern)
            self::maybe_few_left($eid);
        }
        $due = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE status = 'planned' AND due_at <= %s ORDER BY due_at ASC LIMIT 50", self::now()), ARRAY_A);
        foreach ($due as $r) self::remind($r);
    }

    public static function maybe_few_left($eid) {
        global $wpdb;
        if (!class_exists('TIX_Sales_Figures')) return false;
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::t() . " WHERE event_id = %d AND occasion = 'few_left'", $eid));
        if ($exists) return false;
        $date = substr((string) get_post_meta($eid, '_tix_date_start', true), 0, 10);
        if (!$date || $date < current_time('Y-m-d')) return false;
        $f = TIX_Sales_Figures::for_events([$eid])['events'][$eid] ?? null;
        if (!$f || !empty($f['unlimited']) || !$f['capacity'] || $f['tickets'] >= $f['capacity'] || $f['tickets'] < self::FEW_LEFT * $f['capacity']) return false;
        $wpdb->insert(self::t(), ['event_id' => $eid, 'organizer_id' => intval(get_post_meta($eid, '_tix_organizer_id', true)), 'occasion' => 'few_left', 'due_at' => self::now(), 'status' => 'planned',
            'data' => wp_json_encode(['text' => self::text_for('few_left', $eid), 'edited' => false, 'format' => 'story']), 'created' => self::now(), 'updated' => self::now()]);
        return true;
    }

    /** Mail-Empfänger: evendis Inhaber bzw. Veranstalter-Kontakt; Einzel-Seite Konten mit Rolle Veranstalter, sonst Admin-Mail */
    public static function mail_to($eid) {
        $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            $m = $oid && class_exists('TIX_Payout_Details') ? TIX_Payout_Details::owner_email($oid) : '';
            if ($m === '' && $oid) $m = (string) get_post_meta($oid, '_tix_org_email', true);
            if (!$oid) $m = (string) get_option('admin_email'); // Event der Plattform selbst
            return array_values(array_filter([$m], 'is_email'));
        }
        $list = [];
        foreach (get_users(['role' => 'tix_organizer', 'fields' => ['user_email'], 'number' => 5]) as $u) $list[] = $u->user_email;
        if (!$list) $list[] = (string) get_option('admin_email');
        return array_values(array_unique(array_filter($list, 'is_email')));
    }

    public static function remind(array $r) {
        global $wpdb;
        $eid = intval($r['event_id']);
        // Status zuerst setzen: kein doppelter Versand, wenn zwei Cron-Läufe überlappen
        $n = $wpdb->query($wpdb->prepare("UPDATE " . self::t() . " SET status = 'reminded', reminded_at = %s, updated = %s WHERE id = %d AND status = 'planned'", self::now(), self::now(), intval($r['id'])));
        if (!$n) return false;
        if (get_post_type($eid) !== 'event' || !self::enabled($eid)) return false;
        $d = self::data($r);
        $title = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8');
        $label = self::OCCASIONS[$r['occasion']] ?? 'Post';
        $img = self::image($eid);
        $oid = intval($r['organizer_id']);
        if (class_exists('TIX_Staff_Push')) {
            $uids = TIX_Staff_Push::recipients([$oid], 'social_post');
            if ($uids) TIX_Staff_Push::send_to_users($uids, 'Social-Planer: ' . $label, $title . ' – Bild und Text sind bereit zum Teilen.', ['action' => 'staff:social', 'event_id' => (string) $eid, 'post_id' => (string) intval($r['id'])]);
        }
        $to = self::mail_to($eid);
        if ($to && class_exists('TIX_Mail_Layout')) {
            $blocks = [];
            if ($img) $blocks[] = ['type' => 'html', 'html' => '<p><img src="' . esc_url($img) . '" alt="' . esc_attr('Bild zum Event ' . $title) . '" style="max-width:100%;height:auto;border-radius:8px;"></p>'];
            $blocks[] = ['type' => 'text', 'text' => 'Heute ist ein guter Moment für einen Post (' . $label . '). Text zum Kopieren:'];
            $blocks[] = ['type' => 'html', 'html' => '<p style="padding:12px;border:1px solid #ddd;border-radius:8px;white-space:pre-wrap;">' . esc_html((string) ($d['text'] ?? '')) . '</p>'];
            if ($img) $blocks[] = ['type' => 'button', 'text' => 'Bild öffnen', 'url' => $img];
            TIX_Mail_Layout::send($to, 'Social-Planer: ' . $label . ' – ' . $title, ['title' => 'Zeit für deinen Post', 'subtitle' => $title, 'blocks' => $blocks, 'kind' => 'admin',
                'note' => 'Du bekommst diese Mail, weil der Social-Planer für dieses Event eingeschaltet ist. Ausschalten im Studio unter Event → Social-Planer.'], ['customer' => false]);
        }
        return true;
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $p = [__CLASS__, 'perm'];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/social-plan', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $p],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_toggle'], 'permission_callback' => $p],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/social-plan/posts', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => $p]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/social-plan/posts/(?P<post>\d+)', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_update'], 'permission_callback' => $p],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $p],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/social-plan/posts/(?P<post>\d+)/ai', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_ai'], 'permission_callback' => $p]);
    }

    public static function perm(WP_REST_Request $req) {
        $c = class_exists('TIX_Studio_Stats') ? TIX_Studio_Stats::perm_manager($req) : TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    private static function out_post(array $r) {
        $d = self::data($r);
        return ['id' => intval($r['id']), 'occasion' => $r['occasion'], 'label' => self::OCCASIONS[$r['occasion']] ?? $r['occasion'], 'due_at' => mysql_to_rfc3339($r['due_at']),
            'status' => $r['status'], 'text' => (string) ($d['text'] ?? ''), 'edited' => !empty($d['edited']), 'format' => in_array($d['format'] ?? '', self::FORMATS, true) ? $d['format'] : 'feed',
            'image' => self::image(intval($r['event_id'])), 'link' => self::link(intval($r['event_id'])), 'reminded_at' => $r['reminded_at'] ? mysql_to_rfc3339($r['reminded_at']) : null];
    }

    private static function payload($eid) {
        return ['ok' => true, 'event_id' => intval($eid), 'enabled' => self::enabled($eid), 'posts' => array_map([__CLASS__, 'out_post'], self::rows($eid)), 'occasions' => self::OCCASIONS, 'formats' => self::FORMATS];
    }

    public static function rest_get(WP_REST_Request $req) { self::maybe_install(); return rest_ensure_response(self::payload(absint($req['id']))); }

    public static function rest_toggle(WP_REST_Request $req) {
        self::maybe_install();
        global $wpdb;
        $eid = absint($req['id']);
        if (!$req->has_param('enabled')) return self::bad('enabled', 'enabled: true oder false.');
        $on = filter_var($req->get_param('enabled'), FILTER_VALIDATE_BOOLEAN);
        if (class_exists('TIX_Object_Data')) { if ($on) TIX_Object_Data::write(self::KIND, $eid, ['enabled' => true, 'since' => gmdate('c')]); else TIX_Object_Data::remove(self::KIND, $eid); }
        if ($on) self::sync($eid); else $wpdb->query($wpdb->prepare("DELETE FROM " . self::t() . " WHERE event_id = %d AND status = 'planned'", $eid));
        return rest_ensure_response(self::payload($eid));
    }

    private static function due_param($v) {
        $ts = strtotime((string) $v);
        if (!$ts || $ts < time() - 300 || $ts > time() + 400 * DAY_IN_SECONDS) return null;
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function get_post($eid, $pid) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE id = %d AND event_id = %d", intval($pid), intval($eid)), ARRAY_A);
        return $r ?: null;
    }

    public static function rest_create(WP_REST_Request $req) {
        self::maybe_install();
        global $wpdb;
        $eid = absint($req['id']);
        if (!self::enabled($eid)) return self::bad('enabled', 'Bitte zuerst den Social-Planer für dieses Event einschalten.', 'tix_social_off', 409);
        $text = trim(sanitize_textarea_field((string) $req->get_param('text')));
        if ($text === '' || mb_strlen($text) > 2200) return self::bad('text', 'Text 1 bis 2.200 Zeichen.');
        $due = self::due_param($req->get_param('due_at'));
        if (!$due) return self::bad('due_at', 'Bitte einen Termin in der Zukunft angeben.');
        $fmt = in_array($req->get_param('format'), self::FORMATS, true) ? $req->get_param('format') : 'feed';
        $wpdb->insert(self::t(), ['event_id' => $eid, 'organizer_id' => intval(get_post_meta($eid, '_tix_organizer_id', true)), 'occasion' => 'custom', 'due_at' => $due, 'status' => 'planned',
            'data' => wp_json_encode(['text' => $text, 'edited' => true, 'format' => $fmt]), 'created' => self::now(), 'updated' => self::now()]);
        return new WP_REST_Response(['ok' => true, 'post' => self::out_post(self::get_post($eid, $wpdb->insert_id))], 201);
    }

    public static function rest_update(WP_REST_Request $req) {
        global $wpdb;
        $eid = absint($req['id']);
        $r = self::get_post($eid, $req['post']);
        if (!$r) return new WP_Error('not_found', 'Post nicht gefunden.', ['status' => 404]);
        $d = self::data($r); $set = [];
        if ($req->has_param('text')) {
            $text = trim(sanitize_textarea_field((string) $req->get_param('text')));
            if ($text === '' || mb_strlen($text) > 2200) return self::bad('text', 'Text 1 bis 2.200 Zeichen.');
            $d['text'] = $text; $d['edited'] = true;
        }
        if ($req->has_param('format')) {
            if (!in_array($req->get_param('format'), self::FORMATS, true)) return self::bad('format', 'feed oder story.');
            $d['format'] = $req->get_param('format');
        }
        if ($req->has_param('due_at')) {
            $due = self::due_param($req->get_param('due_at'));
            if (!$due) return self::bad('due_at', 'Bitte einen Termin in der Zukunft angeben.');
            $set['due_at'] = $due; $d['edited'] = true;
            if ($r['status'] === 'reminded') $set['status'] = 'planned';
        }
        if ($req->has_param('status')) {
            $st = (string) $req->get_param('status');
            if (!in_array($st, ['planned', 'done', 'skipped'], true)) return self::bad('status', 'planned, done oder skipped.');
            $set['status'] = $st;
        }
        $wpdb->update(self::t(), $set + ['data' => wp_json_encode($d), 'updated' => self::now()], ['id' => intval($r['id'])]);
        return rest_ensure_response(['ok' => true, 'post' => self::out_post(self::get_post($eid, $r['id']))]);
    }

    public static function rest_delete(WP_REST_Request $req) {
        global $wpdb;
        $eid = absint($req['id']);
        $r = self::get_post($eid, $req['post']);
        if (!$r) return new WP_Error('not_found', 'Post nicht gefunden.', ['status' => 404]);
        // Regel-Posts kämen beim nächsten Abgleich wieder → übersprungen statt gelöscht
        if ($r['occasion'] !== 'custom') $wpdb->update(self::t(), ['status' => 'skipped', 'updated' => self::now()], ['id' => intval($r['id'])]);
        else $wpdb->delete(self::t(), ['id' => intval($r['id'])]);
        return rest_ensure_response(['ok' => true]);
    }

    public static function rest_ai(WP_REST_Request $req) {
        global $wpdb;
        $eid = absint($req['id']);
        $r = self::get_post($eid, $req['post']);
        if (!$r) return new WP_Error('not_found', 'Post nicht gefunden.', ['status' => 404]);
        if (!class_exists('TIX_Meta_Ads') && defined('TIXOMAT_PATH') && is_readable(TIXOMAT_PATH . 'includes/class-tix-meta-ads.php')) require_once TIXOMAT_PATH . 'includes/class-tix-meta-ads.php';
        if (!class_exists('TIX_Meta_Ads') || !method_exists('TIX_Meta_Ads', 'social_for')) return new WP_Error('ai_unavailable', 'KI-Texte sind hier nicht verfügbar.', ['status' => 503]);
        $d = self::data($r);
        $res = TIX_Meta_Ads::social_for($eid, $d['format'] === 'story' ? 'instagram_story' : 'instagram');
        if (is_wp_error($res)) return $res;
        $v = array_values((array) ($res['variants'] ?? []));
        $text = is_array($v[0] ?? null) ? (string) ($v[0]['text'] ?? '') : (string) ($v[0] ?? '');
        if ($text === '') return new WP_Error('ai_empty', 'Die KI hat keinen Text geliefert.', ['status' => 502]);
        $d['text'] = mb_substr($text, 0, 2200); $d['edited'] = true;
        $wpdb->update(self::t(), ['data' => wp_json_encode($d), 'updated' => self::now()], ['id' => intval($r['id'])]);
        return rest_ensure_response(['ok' => true, 'post' => self::out_post(self::get_post($eid, $r['id'])), 'variants' => $v]);
    }
}

TIX_Social_Planner::init();
