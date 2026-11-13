<?php
/**
 * TIX_Guest_Flows – automatische Gäste-Abläufe (M4, Auftrag Marketing/Reichweite 13.11.2026; ab 1.38.813).
 *
 * Bestand (Stand 13.11., nicht neu gebaut, nur in der Übersicht und – wo sinnvoll – schaltbar):
 *   reminder_mail   Erinnerungs-Mail 24 h vorher an Käufer (tix_settings email_reminder)           – Seite
 *   event_day_push  Mitteilung + Push am Event-Tag an Käufer mit App-Konto (immer an)            – TIX_Notifications
 *   followup_mail   Danke-Mail 24 h danach mit Sterne-Feedback (email_followup, feedback_enabled)   – Seite
 *   artist_alert    Künstlern folgen: neuer Termin + Vorverkaufsstart (evendis)                   – TIX_Artist_Follow
 *   mail_follow     Veranstaltern per Mail folgen: neue Events gebündelt (evendis, Schalter des Users) – TIX_Mail_Follow
 *   day_alerts      „Erinnere mich“ für einen Tag (evendis-App)                                     – TIX_Day_Alerts
 *   weekly_mail     Wochenmail je Stadt (evendis, Schalter des Users)                              – TIX_Weekly_Mail
 * Neu (Lücken; je Seite bzw. evendis-Veranstalter schaltbar, Vorgabe AUS):
 *   follower_alert  Konto-Follower des Veranstalters (App) bekommen Mitteilung + Push bei neuem Event und
 *                   Vorverkaufsstart (evendis; auf Einzel-Seiten gibt es keine Veranstalter-Follower)
 *   new_event_all   Einzel-Seite: neues Event bzw. Vorverkaufsstart als Mitteilung an alle App-Nutzer
 *   thanks          Tag nach dem Event: „Danke“ an Käufer mit App-Konto, mit Fotos-Hinweis, wenn eine Galerie da ist
 *   next_event      Neues Event veröffentlicht → Mitteilung an die Gäste des letzten vergangenen Events desselben
 *                   Veranstalters bzw. der Seite (nur App-Konten, nicht an Käufer, die schon ein Ticket haben)
 * Kanal der neuen Abläufe: Mitteilung in der App + Push (TIX_Notifications; Nutzer mit „Mitteilungen aus“ bekommen nichts).
 * Mails gehen nur über den Newsletter mit Einwilligung (Zielgruppe „Käufer eines Events“), nie automatisch an Käufer.
 * Jeder Ablauf meldet sich je Event höchstens einmal (Tabelle {prefix}tix_guest_flow_log).
 *
 * REST (Inhaber/Team-Admin/Admin): GET|POST /organizer/guest-flows {flows: {key: bool}, reminder_mail?, followup_mail?}
 *   → {ok, scope, flows[{key, label, desc, enabled, editable, kind: new|existing, channel}]}
 *   Seiten-Schalter (reminder_mail, followup_mail) nur Einzel-Seite bzw. Admin.
 */
if (!defined('ABSPATH')) exit;

class TIX_Guest_Flows {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const CRON = 'tix_guest_flows';
    const OPT = 'tix_guest_flows';            // Einzel-Seite
    const KIND = 'tix_org_guest_flows';        // evendis je Veranstalter (TIX_Object_Data)
    const NEW_FLOWS = [
        'follower_alert' => ['Ticket-Alarm für Follower', 'Wer dir in der App folgt, bekommt eine Mitteilung bei neuen Events und zum Vorverkaufsstart.', 'multi'],
        'new_event_all'  => ['Neue Events an alle App-Nutzer', 'Neue Events und Vorverkaufsstarts als Mitteilung an alle Nutzer deiner App.', 'single'],
        'thanks'         => ['Danke nach dem Event', 'Am Tag danach eine Mitteilung an deine Gäste – mit Hinweis auf die Fotos, wenn du eine Galerie hochlädst.', 'both'],
        'next_event'     => ['Nächstes Event an die letzten Gäste', 'Sobald du ein neues Event veröffentlichst, bekommen die Gäste deines letzten Events eine Mitteilung.', 'both'],
    ];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 26);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('transition_post_status', [__CLASS__, 'on_publish'], 25, 3);
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 900, 'hourly', self::CRON); }, 53);
        add_action(self::CRON, [__CLASS__, 'cron']);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }
    private static function tl() { global $wpdb; return $wpdb->prefix . 'tix_guest_flow_log'; }

    public static function maybe_install() {
        if (get_option('tix_guest_flows_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::tl() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            flow VARCHAR(20) NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            recipients INT UNSIGNED NOT NULL DEFAULT 0,
            created DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY flow_event (flow, event_id)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_guest_flows_db', self::DB_VERSION, true);
    }

    // ═══════════════ Schalter ═══════════════

    public static function settings($org) {
        $v = $org && self::multi() && class_exists('TIX_Object_Data') ? TIX_Object_Data::read(self::KIND, intval($org), []) : get_option(self::OPT, []);
        $v = is_array($v) ? $v : [];
        $out = [];
        foreach (array_keys(self::NEW_FLOWS) as $k) $out[$k] = !empty($v[$k]);
        return $out;
    }

    private static function save($org, array $set) {
        $cur = self::settings($org);
        $cur = array_merge($cur, $set);
        if ($org && self::multi() && class_exists('TIX_Object_Data')) TIX_Object_Data::write(self::KIND, intval($org), $cur);
        else update_option(self::OPT, $cur, false);
    }

    public static function on($flow, $org) { return !empty(self::settings($org)[$flow]); }

    private static function scope_of($eid) { return self::multi() ? intval(get_post_meta($eid, '_tix_organizer_id', true)) : 0; }

    // ═══════════════ Protokoll (einmal je Event) ═══════════════

    /** true = dieser Ablauf darf für das Event jetzt laufen (und ist damit vergeben) */
    private static function claim($flow, $eid) {
        global $wpdb;
        self::maybe_install();
        return (bool) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO " . self::tl() . " (flow, event_id, recipients, created) VALUES (%s, %d, 0, %s)", $flow, intval($eid), current_time('mysql', true)));
    }

    private static function done($flow, $eid, $n) {
        global $wpdb;
        $wpdb->update(self::tl(), ['recipients' => intval($n)], ['flow' => $flow, 'event_id' => intval($eid)]);
    }

    public static function sent($flow, $eid) {
        global $wpdb;
        self::maybe_install();
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::tl() . " WHERE flow = %s AND event_id = %d", $flow, intval($eid)));
    }

    // ═══════════════ Auslöser ═══════════════

    private static function real_event($eid) {
        return get_post_type($eid) === 'event' && get_post_status($eid) === 'publish' && !get_post_meta($eid, '_tix_system_giftcard_event', true) && !get_post_meta($eid, '_tix_system_wallet_event', true)
            && get_post_meta($eid, '_tix_syndicated', true) !== '1';
    }

    /** Neues Event veröffentlicht → vormerken (Verarbeitung im stündlichen Lauf bzw. sofort in Tests) */
    public static function on_publish($new, $old, $post) {
        if ($new !== 'publish' || $old === 'publish' || !$post || $post->post_type !== 'event') return;
        $q = get_option('tix_guest_flows_new', []);
        $q = is_array($q) ? $q : [];
        if (!in_array(intval($post->ID), $q, true)) $q[] = intval($post->ID);
        update_option('tix_guest_flows_new', array_slice($q, -200), false);
    }

    private static function when($eid) {
        $d = (string) get_post_meta($eid, '_tix_date_start', true);
        return $d ? wp_date('d.m.', strtotime($d . ' 12:00:00')) : '';
    }

    private static function org_name($oid) { return $oid ? html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8') : html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8'); }

    private static function presale_future($eid) {
        $ps = trim((string) get_post_meta($eid, '_tix_presale_start', true));
        if ($ps === '') return false;
        $ts = strtotime(strlen($ps) <= 10 ? "$ps 00:00:00" : str_replace('T', ' ', $ps));
        return $ts && $ts > current_time('timestamp');
    }

    /** Konto-Follower eines Veranstalters (App) */
    public static function followers($oid) {
        global $wpdb;
        $out = [];
        $meta = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::META_FOLLOWING : '_tix_app_following';
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $meta)) as $r) {
            $l = maybe_unserialize($r->meta_value);
            if (is_array($l) && in_array(intval($oid), array_map('intval', $l), true)) $out[] = intval($r->user_id);
        }
        return $out;
    }

    private static function notify(array $uids, $title, $body, $eid, $flow) {
        if (!class_exists('TIX_Notifications')) return 0;
        $n = 0;
        foreach (array_unique(array_map('intval', $uids)) as $uid) {
            if ($uid <= 0) continue;
            TIX_Notifications::add_user_item($uid, $title, $body, ['type' => 'message', 'event_id' => intval($eid), 'action' => 'event:' . intval($eid), 'campaign' => 'flow-' . $flow . '-' . intval($eid)]);
            $n++;
        }
        return $n;
    }

    /** Neues Event: Follower-Alarm, alle App-Nutzer (Einzel-Seite), nächstes Event an die letzten Gäste */
    public static function process_new($eid) {
        $eid = intval($eid);
        if (!self::real_event($eid)) return [];
        $date = (string) get_post_meta($eid, '_tix_date_start', true);
        if ($date === '' || $date < current_time('Y-m-d')) return [];
        $oid = self::scope_of($eid);
        $title = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8');
        $presale = self::presale_future($eid);
        $res = [];
        $text = $presale ? 'Neu angekündigt: ' . $title . ' am ' . self::when($eid) . '. Der Vorverkauf startet bald.' : $title . ' am ' . self::when($eid) . ' – Tickets gibt es jetzt.';
        if (self::multi() && $oid && self::on('follower_alert', $oid) && self::claim('follower_new', $eid)) {
            $n = self::notify(self::followers($oid), 'Neu von ' . self::org_name($oid), $text, $eid, 'follower');
            self::done('follower_new', $eid, $n); $res['follower_new'] = $n;
        }
        if (!self::multi() && self::on('new_event_all', 0) && class_exists('TIX_Notifications') && self::claim('all_new', $eid)) {
            TIX_Notifications::send_broadcast('Neues Event', $text, $eid, 'all', 'event:' . $eid, 'flow-all-' . $eid);
            self::done('all_new', $eid, 1); $res['all_new'] = 1;
        }
        if (self::on('next_event', $oid) && self::claim('next_event', $eid)) {
            $last = self::last_past_event($oid, $eid);
            $n = 0;
            if ($last && class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'event_ticket_user_ids')) {
                $have = TIX_Notifications::event_ticket_user_ids($eid);
                $uids = array_diff(TIX_Notifications::event_ticket_user_ids($last), $have);
                $n = self::notify($uids, 'Dein nächstes Event', 'Du warst bei ' . html_entity_decode(get_the_title($last), ENT_QUOTES, 'UTF-8') . ' – als Nächstes: ' . $title . ' am ' . self::when($eid) . '.', $eid, 'next');
            }
            self::done('next_event', $eid, $n); $res['next_event'] = $n;
        }
        return $res;
    }

    /** Letztes vergangenes Event desselben Veranstalters (evendis) bzw. der Seite, höchstens 12 Monate zurück */
    public static function last_past_event($oid, $except = 0) {
        $today = current_time('Y-m-d');
        $mq = [['key' => '_tix_date_start', 'value' => $today, 'compare' => '<', 'type' => 'DATE'],
            ['key' => '_tix_date_start', 'value' => wp_date('Y-m-d', time() - 365 * DAY_IN_SECONDS), 'compare' => '>=', 'type' => 'DATE']];
        if (self::multi()) $mq[] = ['key' => '_tix_organizer_id', 'value' => intval($oid)];
        $ids = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'private'], 'posts_per_page' => 5, 'fields' => 'ids', 'no_found_rows' => true, 'post__not_in' => [intval($except)],
            'meta_query' => $mq, 'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'DESC']);
        foreach ($ids as $id) if (get_post_meta($id, '_tix_syndicated', true) !== '1') return intval($id);
        return 0;
    }

    /** Vorverkaufsstart erreicht (seit dem letzten Lauf) → Follower bzw. alle App-Nutzer */
    public static function process_presale($eid) {
        $eid = intval($eid);
        if (!self::real_event($eid)) return [];
        $oid = self::scope_of($eid);
        $title = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8');
        $res = [];
        if (self::multi() && $oid && self::on('follower_alert', $oid) && self::claim('follower_presale', $eid)) {
            $n = self::notify(self::followers($oid), 'Vorverkauf gestartet', 'Tickets für ' . $title . ' am ' . self::when($eid) . ' gibt es jetzt.', $eid, 'presale');
            self::done('follower_presale', $eid, $n); $res['follower_presale'] = $n;
        }
        if (!self::multi() && self::on('new_event_all', 0) && class_exists('TIX_Notifications') && self::claim('all_presale', $eid)) {
            TIX_Notifications::send_broadcast('Vorverkauf gestartet', 'Tickets für ' . $title . ' am ' . self::when($eid) . ' gibt es jetzt.', $eid, 'all', 'event:' . $eid, 'flow-presale-' . $eid);
            self::done('all_presale', $eid, 1); $res['all_presale'] = 1;
        }
        return $res;
    }

    /** Tag nach dem Event: Danke an die Gäste mit App-Konto */
    public static function process_thanks($eid) {
        $eid = intval($eid);
        if (!self::real_event($eid) && get_post_status($eid) !== 'private') return 0;
        $oid = self::scope_of($eid);
        if (!self::on('thanks', $oid) || !class_exists('TIX_Notifications') || !method_exists('TIX_Notifications', 'event_ticket_user_ids')) return 0;
        if (!self::claim('thanks', $eid)) return 0;
        $title = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8');
        $gal = get_post_meta($eid, '_tix_gallery_ids', true) ?: get_post_meta($eid, '_tix_gallery', true);
        $body = 'Schön, dass du bei ' . $title . ' warst!' . ($gal ? ' Die Fotos sind online – schau rein.' : ' Wir freuen uns auf das nächste Mal.');
        $n = self::notify(TIX_Notifications::event_ticket_user_ids($eid), 'Danke für deinen Besuch', $body, $eid, 'thanks');
        self::done('thanks', $eid, $n);
        return $n;
    }

    public static function cron() {
        self::maybe_install();
        $q = get_option('tix_guest_flows_new', []);
        if (is_array($q) && $q) { delete_option('tix_guest_flows_new'); foreach ($q as $eid) self::process_new($eid); }
        // Vorverkaufsstart in der letzten Stunde (plus Puffer) erreicht
        $now = current_time('timestamp');
        foreach (get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => '_tix_presale_start', 'value' => wp_date('Y-m-d', $now - DAY_IN_SECONDS), 'compare' => '>=']]]) as $eid) {
            $ps = trim((string) get_post_meta($eid, '_tix_presale_start', true));
            $ts = strtotime(strlen($ps) <= 10 ? "$ps 00:00:00" : str_replace('T', ' ', $ps));
            if ($ts && $ts <= $now && $ts > $now - 2 * HOUR_IN_SECONDS) self::process_presale($eid);
        }
        // Danke: Events von gestern (ab 11 Uhr)
        if (intval(current_time('G')) >= 11) {
            $y = wp_date('Y-m-d', $now - DAY_IN_SECONDS);
            foreach (get_posts(['post_type' => 'event', 'post_status' => ['publish', 'private'], 'posts_per_page' => 100, 'fields' => 'ids', 'no_found_rows' => true,
                'meta_query' => ['relation' => 'OR', ['key' => '_tix_date_end', 'value' => $y], ['relation' => 'AND', ['key' => '_tix_date_start', 'value' => $y], ['key' => '_tix_date_end', 'compare' => 'NOT EXISTS']]]]) as $eid) self::process_thanks($eid);
        }
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $p = class_exists('TIX_Ad_Accounts') ? ['TIX_Ad_Accounts', 'perm'] : function () { return current_user_can('manage_options'); };
        register_rest_route(self::NS, '/organizer/guest-flows', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $p],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $p],
        ]);
    }

    private static function org(WP_REST_Request $req) { return class_exists('TIX_Ad_Accounts') ? intval(TIX_Ad_Accounts::org($req)) : 0; }

    private static function site_editable() { return current_user_can('manage_options') || !self::multi(); }

    public static function payload($org) {
        $s = (array) get_option('tix_settings', []);
        $multi = self::multi();
        $set = self::settings($org);
        $flows = [];
        foreach (self::NEW_FLOWS as $k => [$label, $desc, $where]) {
            if (($where === 'multi' && !$multi) || ($where === 'single' && $multi)) continue;
            $flows[] = ['key' => $k, 'label' => $label, 'desc' => $desc, 'enabled' => $set[$k], 'editable' => true, 'kind' => 'new', 'channel' => 'app'];
        }
        $flows[] = ['key' => 'reminder_mail', 'label' => 'Erinnerungs-Mail', 'desc' => '24 Stunden vor dem Event an alle Käufer, mit Tickets und Anfahrt.', 'enabled' => !empty($s['email_reminder']), 'editable' => self::site_editable(), 'kind' => 'existing', 'channel' => 'mail'];
        $flows[] = ['key' => 'event_day_push', 'label' => 'Erinnerung am Event-Tag', 'desc' => 'Mitteilung in der App einige Stunden vor Beginn an Käufer mit App-Konto.', 'enabled' => true, 'editable' => false, 'kind' => 'existing', 'channel' => 'app'];
        $flows[] = ['key' => 'followup_mail', 'label' => 'Danke-Mail mit Feedback', 'desc' => '24 Stunden nach dem Event an alle Käufer, mit Sterne-Bewertung.', 'enabled' => !empty($s['email_followup']), 'editable' => self::site_editable(), 'kind' => 'existing', 'channel' => 'mail'];
        if ($multi) {
            $flows[] = ['key' => 'artist_alert', 'label' => 'Künstler-Alarm', 'desc' => 'Wer einem Künstler folgt, erfährt von neuen Terminen und Vorverkaufsstarts.', 'enabled' => class_exists('TIX_Artist_Follow'), 'editable' => false, 'kind' => 'existing', 'channel' => 'app'];
            $flows[] = ['key' => 'mail_follow', 'label' => 'Folgen per Mail', 'desc' => 'Neue Events gebündelt per Mail an Follower ohne Konto.', 'enabled' => class_exists('TIX_Mail_Follow') && TIX_Mail_Follow::enabled(), 'editable' => false, 'kind' => 'existing', 'channel' => 'mail'];
        }
        return ['ok' => true, 'scope' => $org ? ['organizer' => $org] : ['site' => true], 'flows' => $flows];
    }

    public static function rest_get(WP_REST_Request $req) { return rest_ensure_response(self::payload(self::org($req))); }

    public static function rest_save(WP_REST_Request $req) {
        $org = self::org($req);
        $in = $req->get_param('flows');
        if ($in !== null) {
            if (!is_array($in)) return new WP_Error('tix_invalid_field', 'flows: {schlüssel: true|false}.', ['status' => 400, 'field' => 'flows']);
            $set = [];
            foreach ($in as $k => $v) {
                if (!isset(self::NEW_FLOWS[$k])) return new WP_Error('tix_invalid_field', 'Unbekannter Ablauf.', ['status' => 400, 'field' => 'flows.' . $k]);
                $set[$k] = rest_sanitize_boolean($v);
            }
            self::save($org, $set);
        }
        foreach (['reminder_mail' => 'email_reminder', 'followup_mail' => 'email_followup'] as $p => $k) {
            if (!$req->has_param($p)) continue;
            if (!self::site_editable()) return new WP_Error('rest_forbidden', 'Mails an Käufer stellt auf der Plattform ein Admin ein.', ['status' => 403]);
            $s = (array) get_option('tix_settings', []);
            $s[$k] = rest_sanitize_boolean($req->get_param($p)) ? 1 : 0;
            update_option('tix_settings', $s);
        }
        return self::rest_get($req);
    }
}

TIX_Guest_Flows::init();
