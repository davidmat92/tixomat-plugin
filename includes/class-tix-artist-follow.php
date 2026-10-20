<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Artist_Follow – Künstlern folgen (Plan-Paket 5, 1.38.518, Vertrag uebergaben/vertrag-kuenstler.md §4; nur evendis).
 *
 *  - Konto/App: User-Meta `_tix_app_following_artists` = {artist_id: {cities: [..]|null, since}} (Gäste über Gast-Token wie
 *    /customer/following). Mitteilungen in die Glocke (+ Push), Typ `artist`, Aktion `artist:<id>`.
 *  - E-Mail ohne Konto mit Double-Opt-in wie TIX_Mail_Follow (Tabelle `tix_artist_mail_followers`), nur wenn der Schalter
 *    `tix_mail_follow` an ist (TIX_Mail_Follow::enabled(); den schaltet nur der User). Konto: Mails an/aus ohne Bestätigungs-Mail.
 *  - Auslöser: neuer Termin (Event mit dem Künstler veröffentlicht bzw. zugeordnet; „Zusatztermin“ bei weiterem Termin
 *    derselben Tour) und Vorverkaufsstart. Gesammelt, je Empfänger höchstens eine Mitteilung bzw. Mail am Tag (ab 18:00).
 *    `cities` = nur Termine in diesen Städten (Ticketalarm „nur meine Stadt“).
 */
class TIX_Artist_Follow {

    const NS = 'tixomat/v1';
    const META = '_tix_app_following_artists';
    const DB_VER = '1';
    const OPT_DB = 'tix_artist_mail_follow_db';
    const OPT_QUEUE = 'tix_artist_follow_queue';
    const OPT_LAST = 'tix_artist_follow_last';
    const OPT_PRESALE_RUN = 'tix_artist_follow_presale_run';
    const CRON = 'tix_artist_follow_cron';
    const DIGEST_HOUR = 18;
    const MAX_CITIES = 10;
    const CONSENT_TEXT = 'Ja, ich möchte per E-Mail erfahren, wenn %s neue Termine bekommt oder der Vorverkauf startet. Höchstens eine Mail am Tag, Abmeldung jederzeit über den Link in jeder Mail.';
    const CONSENT_ACCOUNT_TEXT = 'Mit „Mails an“ bekommst du eine E-Mail, wenn der Künstler neue Termine bekommt oder der Vorverkauf startet – höchstens eine am Tag. Ausschalten geht jederzeit im Konto oder über den Link in jeder Mail.';

    public static $sending = false;

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Mail-Folgen an? (gleicher Schalter wie bei Veranstaltern) */
    public static function mail_on() {
        return class_exists('TIX_Mail_Follow') && TIX_Mail_Follow::enabled();
    }

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_artist_event_added', [__CLASS__, 'on_event_added'], 10, 2);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (!TIX_Artist_Follow::multi()) return;
            TIX_Artist_Follow::maybe_install();
            if (!wp_next_scheduled(TIX_Artist_Follow::CRON)) wp_schedule_event(time() + 600, 'hourly', TIX_Artist_Follow::CRON);
        }, 26);
        add_action('deleted_user', [__CLASS__, 'delete_account_rows']);
        add_filter('tix_email_tracking_enabled', function ($on) { return TIX_Artist_Follow::$sending ? false : $on; });
    }

    private static function t() {
        global $wpdb;
        return $wpdb->prefix . 'tix_artist_mail_followers';
    }

    public static function maybe_install() {
        if (get_option(self::OPT_DB) === self::DB_VER) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::t() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            artist_id bigint(20) unsigned NOT NULL,
            email varchar(190) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            token varchar(64) NOT NULL,
            cities text NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            consent_text text NOT NULL,
            consent_at datetime NOT NULL,
            consent_ip varchar(64) NOT NULL DEFAULT '',
            confirmed_at datetime NULL,
            last_notified datetime NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY artist_email (artist_id, email),
            KEY token (token),
            KEY user_id (user_id)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option(self::OPT_DB, self::DB_VER, false);
    }

    private static function db_ready() {
        return get_option(self::OPT_DB) === self::DB_VER;
    }

    // ═══════════════ Hilfen ═══════════════

    private static function artist_ok($aid) {
        return class_exists('TIX_Artists') && TIX_Artists::is_public(intval($aid));
    }

    private static function name($aid) {
        return html_entity_decode((string) get_the_title($aid), ENT_QUOTES, 'UTF-8');
    }

    private static function page_url($aid) {
        $base = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::base() : '';
        return ($base !== '' ? $base : untrailingslashit(home_url())) . '/kuenstler/' . get_post_field('post_name', $aid) . '/';
    }

    private static function link($aid, $action, $token) {
        $path = $action === 'confirm' ? '/follow/confirm' : '/unfollow';
        return add_query_arg('token', rawurlencode($token), rest_url(self::NS . '/public/artists/' . intval($aid) . $path));
    }

    public static function unsubscribe_url($aid, $token) {
        return add_query_arg('token', rawurlencode($token), self::page_url($aid) . 'abmelden/');
    }

    /** Städte-Filter säubern → Liste (≤ 10) | null */
    private static function clean_cities($v) {
        if ($v === null || $v === '' || $v === []) return null;
        if (!is_array($v)) $v = [$v];
        $out = [];
        foreach ($v as $c) { $c = trim(sanitize_text_field((string) $c)); if ($c !== '' && !isset($out[mb_strtolower($c)])) $out[mb_strtolower($c)] = $c; }
        return $out ? array_slice(array_values($out), 0, self::MAX_CITIES) : null;
    }

    private static function ip_hash() {
        $ip = class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : '';
    }

    private static function mail($to, $subject, $html, $headers = []) {
        self::$sending = true;
        try { return wp_mail($to, $subject, $html, array_merge(['Content-Type: text/html; charset=UTF-8'], $headers)); } finally { self::$sending = false; }
    }

    private static function render(array $m) {
        return class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::render($m) : wp_strip_all_tags(implode("\n\n", array_map(function ($b) { return ($b['html'] ?? '') . ' ' . ($b['url'] ?? ''); }, $m['blocks'])));
    }

    // ═══════════════ Konto ═══════════════

    private static function user_id() {
        return get_current_user_id(); // Konto bzw. Gast-Token (check_customer setzt den Nutzer)
    }

    /** {artist_id: {cities, since}} */
    public static function following($uid) {
        $m = get_user_meta(intval($uid), self::META, true);
        return is_array($m) ? $m : [];
    }

    private static function account_rows($uid) {
        global $wpdb;
        $u = get_userdata($uid);
        if (!$u || !self::db_ready()) return [];
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE status = 'active' AND (user_id = %d OR email = %s)", $uid, strtolower((string) $u->user_email))) as $r) $out[intval($r->artist_id)] = $r;
        return $out;
    }

    private static function list_payload($uid) {
        $f = self::following($uid);
        $rows = self::account_rows($uid);
        $artists = [];
        foreach ($f as $aid => $cfg) {
            $c = class_exists('TIX_Artists') ? TIX_Artists::card(intval($aid)) : null;
            if (!$c) continue;
            $artists[] = $c + ['cities' => $cfg['cities'] ?? null, 'since' => $cfg['since'] ?? null, 'mail' => isset($rows[intval($aid)])];
        }
        return ['ok' => true, 'artist_ids' => array_map('intval', array_keys($f)), 'artists' => $artists, 'mail_enabled' => self::mail_on(), 'consent_text' => self::CONSENT_ACCOUNT_TEXT];
    }

    /** GET /customer/following/artists */
    public static function rest_list(WP_REST_Request $req) {
        return rest_ensure_response(self::list_payload(self::user_id()));
    }

    /** POST /customer/following/artists {artist_id, cities?, mail?: bool, consent?: true} */
    public static function rest_add(WP_REST_Request $req) {
        global $wpdb;
        $uid = self::user_id();
        $aid = intval($req->get_param('artist_id'));
        if (!self::artist_ok($aid)) return new WP_Error('tix_artist', 'Künstler nicht gefunden.', ['status' => 404]);
        $f = self::following($uid);
        $cities = $req->has_param('cities') ? self::clean_cities($req->get_param('cities')) : ($f[$aid]['cities'] ?? null);
        $f[$aid] = ['cities' => $cities, 'since' => $f[$aid]['since'] ?? current_time('c')];
        update_user_meta($uid, self::META, $f);
        if ($req->has_param('mail')) {
            $want = filter_var($req->get_param('mail'), FILTER_VALIDATE_BOOLEAN);
            $u = get_userdata($uid);
            if ($want) {
                if (!self::mail_on()) return new WP_Error('tix_follow_mail_off', 'Folgen per E-Mail ist noch nicht verfügbar.', ['status' => 404]);
                if (!filter_var($req->get_param('consent'), FILTER_VALIDATE_BOOLEAN)) return new WP_Error('tix_consent_required', 'Bitte bestätige die Einwilligung.', ['status' => 400, 'field' => 'consent']);
                if ($u && is_email($u->user_email)) {
                    self::maybe_install();
                    $email = strtolower((string) $u->user_email);
                    $now = current_time('mysql');
                    $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM " . self::t() . " WHERE artist_id = %d AND email = %s", $aid, $email));
                    $data = ['status' => 'active', 'user_id' => $uid, 'cities' => $cities ? wp_json_encode($cities) : null, 'consent_text' => self::CONSENT_ACCOUNT_TEXT, 'consent_at' => $now,
                        'consent_ip' => self::ip_hash(), 'confirmed_at' => $now, 'token' => wp_generate_password(40, false, false)];
                    if ($row) $wpdb->update(self::t(), $data, ['id' => $row->id]);
                    else $wpdb->insert(self::t(), $data + ['artist_id' => $aid, 'email' => $email, 'created' => $now]);
                }
            } elseif ($u && self::db_ready()) {
                $wpdb->query($wpdb->prepare("DELETE FROM " . self::t() . " WHERE artist_id = %d AND (user_id = %d OR email = %s)", $aid, $uid, strtolower((string) $u->user_email)));
            }
        } elseif ($req->has_param('cities') && self::db_ready()) {
            $wpdb->query($wpdb->prepare("UPDATE " . self::t() . " SET cities = %s WHERE artist_id = %d AND user_id = %d", $cities ? wp_json_encode($cities) : null, $aid, $uid));
        }
        return rest_ensure_response(self::list_payload($uid));
    }

    /** DELETE /customer/following/artists/{id} – Folgen beenden (auch die Mails des Kontos) */
    public static function rest_remove(WP_REST_Request $req) {
        global $wpdb;
        $uid = self::user_id();
        $aid = intval($req['id']);
        $f = self::following($uid);
        unset($f[$aid]);
        update_user_meta($uid, self::META, $f);
        $u = get_userdata($uid);
        if ($u && self::db_ready()) $wpdb->query($wpdb->prepare("DELETE FROM " . self::t() . " WHERE artist_id = %d AND (user_id = %d OR email = %s)", $aid, $uid, strtolower((string) $u->user_email)));
        return rest_ensure_response(self::list_payload($uid));
    }

    public static function delete_account_rows($uid) {
        global $wpdb;
        if (self::db_ready()) $wpdb->delete(self::t(), ['user_id' => intval($uid)]);
    }

    // ═══════════════ E-Mail ohne Konto (Double-Opt-in) ═══════════════

    /** POST /public/artists/{id}/follow {email, consent: true, cities?} → immer gleiche Antwort */
    public static function rest_follow(WP_REST_Request $req) {
        global $wpdb;
        if (!self::mail_on()) return new WP_Error('tix_follow_mail_off', 'Folgen per E-Mail ist noch nicht verfügbar.', ['status' => 404]);
        $aid = intval($req['id']);
        if (!self::artist_ok($aid)) return new WP_Error('tix_artist', 'Künstler nicht gefunden.', ['status' => 404]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('artist_mail_follow', 10, 3600)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte später erneut versuchen.', ['status' => 429]);
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if (!is_email($email)) return new WP_Error('invalid_email', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        if (!filter_var($req->get_param('consent'), FILTER_VALIDATE_BOOLEAN)) return new WP_Error('tix_consent_required', 'Bitte bestätige die Einwilligung.', ['status' => 400, 'field' => 'consent']);
        $cities = self::clean_cities($req->get_param('cities'));
        self::maybe_install();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE artist_id = %d AND email = %s", $aid, $email));
        if (!$row || $row->status !== 'active') {
            $name = self::name($aid);
            $token = ($row && $row->status === 'pending') ? (string) $row->token : wp_generate_password(40, false, false);
            $data = ['status' => 'pending', 'token' => $token, 'cities' => $cities ? wp_json_encode($cities) : null, 'consent_text' => sprintf(self::CONSENT_TEXT, $name),
                'consent_at' => current_time('mysql'), 'consent_ip' => self::ip_hash()];
            if ($row) $wpdb->update(self::t(), $data, ['id' => $row->id]);
            else $wpdb->insert(self::t(), $data + ['artist_id' => $aid, 'email' => $email, 'created' => current_time('mysql')]);
            self::mail($email, 'Bitte bestätige: ' . $name . ' folgen', self::render(['title' => 'Bitte bestätige', 'kind' => 'account', 'blocks' => [
                ['type' => 'text', 'html' => 'Hallo,<br>bitte bestätige, dass du <strong>' . esc_html($name) . '</strong> per E-Mail folgen möchtest' . ($cities ? ' (Termine in ' . esc_html(implode(', ', $cities)) . ')' : '') . ':'],
                ['type' => 'button', 'label' => 'Folgen bestätigen', 'url' => self::link($aid, 'confirm', $token)],
                ['type' => 'text', 'html' => 'Ohne Bestätigung schicken wir dir nichts.'],
            ]]));
        }
        return rest_ensure_response(['ok' => true, 'message' => 'Fast geschafft: Bitte bestätige über den Link in der E-Mail.']);
    }

    private static function by_token($aid, $token) {
        global $wpdb;
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
        if (strlen($token) < 20 || !self::db_ready()) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE artist_id = %d AND token = %s", intval($aid), $token));
    }

    private static function redirect($aid, $state) {
        wp_redirect(add_query_arg('follow', $state, self::page_url($aid)), 302, 'Tixomat');
        exit;
    }

    /** GET …/follow/confirm?token= → Weiterleitung auf die Künstlerseite */
    public static function rest_confirm(WP_REST_Request $req) {
        global $wpdb;
        $aid = intval($req['id']);
        $row = self::by_token($aid, $req->get_param('token'));
        if (!$row) return self::redirect($aid, 'ungueltig');
        if ($row->status === 'pending') $wpdb->update(self::t(), ['status' => 'active', 'confirmed_at' => current_time('mysql'), 'token' => wp_generate_password(40, false, false)], ['id' => $row->id]);
        return self::redirect($aid, 'bestaetigt');
    }

    /** GET …/unfollow?token= → nur zur Abmeldeseite (Mail-Scanner öffnen Links) */
    public static function rest_unfollow(WP_REST_Request $req) {
        $aid = intval($req['id']);
        wp_redirect(self::unsubscribe_url($aid, preg_replace('/[^A-Za-z0-9]/', '', (string) $req->get_param('token'))), 302, 'Tixomat');
        exit;
    }

    /** POST …/unfollow {token} (auch RFC 8058 One-Click) → Eintrag löschen */
    public static function rest_unfollow_post(WP_REST_Request $req) {
        global $wpdb;
        $aid = intval($req['id']);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('artist_mail_unfollow', 30, 600)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte später erneut versuchen.', ['status' => 429]);
        $row = self::by_token($aid, $req->get_param('token'));
        if (!$row) return new WP_Error('tix_token_invalid', 'Der Abmelde-Link ist ungültig oder wurde schon benutzt.', ['status' => 404]);
        $wpdb->delete(self::t(), ['id' => $row->id]);
        $r = new WP_REST_Response(['ok' => true, 'artist_id' => $aid]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    // ═══════════════ Zählen, Zusammenführen ═══════════════

    private static function account_followers($aid) {
        $out = [];
        foreach (get_users(['meta_key' => self::META, 'fields' => 'ID', 'number' => -1]) as $uid) {
            $f = self::following(intval($uid));
            if (isset($f[intval($aid)])) $out[intval($uid)] = $f[intval($aid)];
        }
        return $out;
    }

    /** Follower (Konten + bestätigte Mail-Adressen ohne Konto) */
    public static function count($aid) {
        global $wpdb;
        $n = count(self::account_followers($aid));
        if (self::db_ready()) $n += intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::t() . " WHERE artist_id = %d AND status = 'active' AND user_id = 0", $aid)));
        return $n;
    }

    /** Zusammenführen: Follower von $from gehen an $into (ohne Doppelte). */
    public static function merge($from, $into) {
        global $wpdb;
        foreach (self::account_followers($from) as $uid => $cfg) {
            $f = self::following($uid);
            if (!isset($f[$into])) $f[$into] = $cfg;
            unset($f[$from]);
            update_user_meta($uid, self::META, $f);
        }
        if (!self::db_ready()) return;
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, email FROM " . self::t() . " WHERE artist_id = %d", $from)) as $r) {
            $dup = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::t() . " WHERE artist_id = %d AND email = %s", $into, $r->email));
            if ($dup) $wpdb->delete(self::t(), ['id' => $r->id]);
            else $wpdb->update(self::t(), ['artist_id' => $into], ['id' => $r->id]);
        }
    }

    // ═══════════════ Auslöser + Tageslauf ═══════════════

    /** Neuer Termin am Künstler (TIX_Artists, nur veröffentlichte Events ohne Sandbox) → Warteschlange */
    public static function on_event_added($aid, $event_id) {
        $aid = intval($aid); $event_id = intval($event_id);
        $kind = 'new';
        foreach (TIX_Artists::event_list($event_id) as $r) {
            if (intval($r['artist_id']) !== $aid || empty($r['tour'])) continue;
            // weiterer Termin derselben Tour = Zusatztermin
            foreach (TIX_Artists::event_ids($aid) as $other) {
                if ($other === $event_id) continue;
                foreach (TIX_Artists::event_list($other) as $o) if (intval($o['artist_id']) === $aid && ($o['tour'] ?? '') === $r['tour']) { $kind = 'extra'; break 2; }
            }
        }
        self::queue($aid, $event_id, $kind);
    }

    public static function queue($aid, $event_id, $kind) {
        $q = get_option(self::OPT_QUEUE, []);
        $q = is_array($q) ? $q : [];
        $q[intval($aid)][intval($event_id)] = $kind;
        update_option(self::OPT_QUEUE, $q, false);
    }

    /** Stündlich: Vorverkaufsstarts einsammeln, ab 18:00 einmal am Tag zustellen, unbestätigte nach 7 Tagen löschen. */
    public static function cron($force = false) {
        global $wpdb;
        if (!self::multi()) return;
        self::collect_presales();
        $today = current_time('Y-m-d');
        if ($force || (intval(current_time('G')) >= self::DIGEST_HOUR && get_option(self::OPT_LAST) !== $today)) {
            update_option(self::OPT_LAST, $today, false);
            self::digest();
        }
        if (self::db_ready()) $wpdb->query($wpdb->prepare("DELETE FROM " . self::t() . " WHERE status = 'pending' AND created < %s", gmdate('Y-m-d H:i:s', current_time('timestamp') - 7 * DAY_IN_SECONDS)));
    }

    /** Events mit Künstlern, deren Vorverkauf seit dem letzten Lauf begonnen hat */
    public static function collect_presales() {
        $now = current_time('timestamp');
        $last = intval(get_option(self::OPT_PRESALE_RUN, 0)) ?: $now - HOUR_IN_SECONDS;
        update_option(self::OPT_PRESALE_RUN, $now, false);
        $ids = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'suppress_filters' => true, 'tix_include_sandbox' => 1,
            'meta_query' => [['key' => '_tix_artist_ref', 'compare' => 'EXISTS'], ['key' => '_tix_presale_start', 'value' => '', 'compare' => '!=']]]);
        foreach ($ids as $eid) {
            $raw = (string) get_post_meta($eid, '_tix_presale_start', true);
            $ts = $raw !== '' ? strtotime(str_replace('T', ' ', $raw)) : 0;
            if (!$ts || $ts <= $last || $ts > $now) continue;
            $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
            if (class_exists('TIX_Sales') && TIX_Sales::is_sandbox_organizer($oid)) continue;
            foreach (TIX_Artists::event_list($eid) as $r) if (TIX_Artists::is_public(intval($r['artist_id']))) self::queue(intval($r['artist_id']), $eid, 'presale');
        }
    }

    private static function event_line($eid, $kind) {
        $label = ['new' => 'Neuer Termin', 'extra' => 'Zusatztermin', 'presale' => 'Vorverkauf gestartet'][$kind] ?? 'Neuer Termin';
        $date = (string) get_post_meta($eid, '_tix_date_start', true);
        $v = class_exists('TIX_Public_Platform') ? (array) TIX_Public_Platform::venue($eid) : [];
        $city = (string) ($v['city'] ?? '');
        $url = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to((string) get_permalink($eid)) : (string) get_permalink($eid);
        return ['label' => $label, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => $date ? wp_date('d.m.Y', strtotime($date)) : '', 'city' => $city, 'url' => $url];
    }

    private static function city_ok($eid, $cities) {
        if (!$cities) return true;
        $c = class_exists('TIX_Public_Platform') ? mb_strtolower((string) (((array) TIX_Public_Platform::venue($eid))['city'] ?? '')) : '';
        return $c !== '' && in_array($c, array_map('mb_strtolower', (array) $cities), true);
    }

    /** Warteschlange zustellen: je Konto eine Glocken-Mitteilung, je Mail-Adresse eine Mail (gebündelt über alle Künstler). */
    public static function digest() {
        global $wpdb;
        $q = get_option(self::OPT_QUEUE, []);
        update_option(self::OPT_QUEUE, [], false);
        if (!is_array($q) || !$q) return ['users' => 0, 'mails' => 0];
        // nur noch gültige Termine (veröffentlicht, Künstler öffentlich)
        foreach ($q as $aid => $evs) {
            if (!TIX_Artists::is_public($aid)) { unset($q[$aid]); continue; }
            $pub = TIX_Artists::event_ids($aid);
            foreach (array_keys($evs) as $eid) if (!in_array(intval($eid), $pub, true)) unset($q[$aid][$eid]);
            if (!$q[$aid]) unset($q[$aid]);
        }
        $n_users = 0; $n_mails = 0;
        // Konten
        foreach (get_users(['meta_key' => self::META, 'fields' => 'ID', 'number' => -1]) as $uid) {
            $f = self::following(intval($uid));
            $hits = [];
            foreach ($q as $aid => $evs) {
                if (!isset($f[$aid])) continue;
                foreach ($evs as $eid => $kind) if (self::city_ok($eid, $f[$aid]['cities'] ?? null)) $hits[] = [$aid, $eid, $kind];
            }
            if (!$hits || !class_exists('TIX_Notifications')) continue;
            $names = array_values(array_unique(array_map(function ($h) { return self::name($h[0]); }, $hits)));
            $title = count($names) === 1 ? 'Neues von ' . $names[0] : 'Neues von ' . $names[0] . ' und ' . (count($names) - 1) . ' weiteren';
            $body = implode(' · ', array_slice(array_map(function ($h) { $l = self::event_line($h[1], $h[2]); return $l['label'] . ': ' . $l['date'] . ($l['city'] !== '' ? ' ' . $l['city'] : ''); }, $hits), 0, 4));
            // Aktion mit Slug (App öffnet /public/artists/{slug}; die Route nimmt auch die ID)
            TIX_Notifications::add_user_item(intval($uid), $title, $body, ['type' => 'artist', 'action' => 'artist:' . get_post_field('post_name', intval($hits[0][0])), 'event_id' => intval($hits[0][1])]);
            $n_users++;
        }
        // Mail (nur mit Schalter)
        if (self::mail_on() && self::db_ready() && $q) {
            $in = implode(',', array_map('intval', array_keys($q)));
            $rows = (array) $wpdb->get_results("SELECT * FROM " . self::t() . " WHERE status = 'active' AND artist_id IN ($in)");
            $by = [];
            foreach ($rows as $r) {
                $to = (intval($r->user_id) && ($u = get_userdata(intval($r->user_id))) && is_email($u->user_email)) ? strtolower($u->user_email) : (string) $r->email;
                if ($r->last_notified && substr((string) $r->last_notified, 0, 10) === current_time('Y-m-d')) continue;
                $cities = $r->cities ? json_decode((string) $r->cities, true) : null;
                foreach ($q[intval($r->artist_id)] ?? [] as $eid => $kind) if (self::city_ok($eid, $cities)) $by[$to][intval($r->artist_id)]['events'][] = [$eid, $kind];
                if (isset($by[$to][intval($r->artist_id)])) { $by[$to][intval($r->artist_id)]['token'] = (string) $r->token; $by[$to]['_ids'][] = intval($r->id); }
            }
            foreach ($by as $to => $arts) {
                $ids = $arts['_ids'] ?? []; unset($arts['_ids']);
                if (!$arts) continue;
                $blocks = [];
                $first = null;
                foreach ($arts as $aid => $a) {
                    $first = $first ?: [$aid, $a['token']];
                    $lines = array_map(function ($e) { $l = self::event_line($e[0], $e[1]); return '<li><strong>' . esc_html($l['label']) . ':</strong> <a href="' . esc_url($l['url']) . '">' . esc_html($l['title']) . '</a> – ' . esc_html(trim($l['date'] . ' ' . $l['city'])) . '</li>'; }, $a['events']);
                    $blocks[] = ['type' => 'text', 'html' => '<strong>' . esc_html(self::name($aid)) . '</strong><ul>' . implode('', $lines) . '</ul><a href="' . esc_url(self::page_url($aid)) . '">Alle Termine</a> · <a href="' . esc_url(self::unsubscribe_url($aid, $a['token'])) . '">Nicht mehr folgen</a>'];
                }
                $names = array_map([__CLASS__, 'name'], array_keys($arts));
                $sent = self::mail($to, 'Neue Termine: ' . implode(', ', array_slice($names, 0, 3)), self::render(['title' => 'Neue Termine', 'kind' => 'customer', 'blocks' => $blocks]),
                    ['List-Unsubscribe: <' . self::link($first[0], 'unfollow', $first[1]) . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click']);
                if ($sent) { $n_mails++; if ($ids) $wpdb->query("UPDATE " . self::t() . " SET last_notified = '" . esc_sql(current_time('mysql')) . "' WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")"); }
            }
        }
        return ['users' => $n_users, 'mails' => $n_mails];
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        if (!self::multi()) return;
        $pub = ['permission_callback' => '__return_true'];
        $customer = ['permission_callback' => ['TIX_App_Account', 'check_customer']];
        register_rest_route(self::NS, '/customer/following/artists', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list']] + $customer,
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_add']] + $customer,
        ]);
        register_rest_route(self::NS, '/customer/following/artists/(?P<id>\d+)', ['methods' => ['DELETE', 'POST'], 'callback' => [__CLASS__, 'rest_remove']] + $customer);
        register_rest_route(self::NS, '/public/artists/(?P<id>\d+)/follow', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_follow']] + $pub);
        register_rest_route(self::NS, '/public/artists/(?P<id>\d+)/follow/confirm', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_confirm']] + $pub);
        register_rest_route(self::NS, '/public/artists/(?P<id>\d+)/unfollow', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_unfollow']] + $pub,
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_unfollow_post']] + $pub,
        ]);
    }
}

TIX_Artist_Follow::init();
