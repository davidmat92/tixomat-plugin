<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Artists – Künstler- und Tourseiten (Plan-Paket 1, 1.38.517, Vertrag uebergaben/vertrag-kuenstler.md; nur evendis,
 * auf Einzel-Seiten 404 und keine Event-Felder).
 *
 *  - Beitragstyp `tix_artist` (nicht öffentlich im WordPress-Sinn), Felder in Meta `_tix_artist`, Suchschlüssel
 *    `_tix_artist_norm`, `_tix_artist_spotify`, `_tix_artist_apple`; Touren als Liste am Künstler.
 *  - Event-Meta `_tix_artists` [{artist_id, role, tour, by_organizer, at}] + Index `_tix_artist_ref` (eine Zeile je Künstler).
 *    Zuordnen über den Event-Editor (Feld `artists`, auch im Vorschau-Paket – dort nur Anzeige).
 *  - Öffentlich erst mit ≥ 1 veröffentlichtem Event eines freigegebenen Veranstalters (Sandbox zählt nie).
 *  - Fremde Zuordnung → Mitteilung an den Verwalter (erst wenn das Event veröffentlicht ist); Entfernen mit Grund.
 */
class TIX_Artists {

    const NS = 'tixomat/v1';
    const CPT = 'tix_artist';
    const META = '_tix_artist';
    const META_NORM = '_tix_artist_norm';
    const META_SPOTIFY = '_tix_artist_spotify';
    const META_APPLE = '_tix_artist_apple';
    const META_LOG = '_tix_artist_log';
    const META_REDIRECT = '_tix_artist_merged_into';
    const EV_META = '_tix_artists';
    const EV_REF = '_tix_artist_ref';
    const KINDS = ['solo', 'band', 'comedian', 'dj', 'ensemble', 'show'];
    const ROLES = ['headliner', 'support', 'guest'];
    const MAX_PER_EVENT = 20;
    const LINKS = ['website', 'instagram', 'tiktok', 'youtube', 'spotify', 'apple_music'];

    private static $cache = [];
    private static $quiet = false;

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt'], 5);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('transition_post_status', [__CLASS__, 'on_publish'], 30, 3);
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'Künstler', 'singular_name' => 'Künstler'],
            'public' => false, 'publicly_queryable' => false, 'show_ui' => self::multi(), 'show_in_menu' => false, 'show_in_rest' => false,
            'exclude_from_search' => true, 'has_archive' => false, 'rewrite' => false, 'supports' => ['title'], 'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
    }

    // ═══════════════ Hilfen ═══════════════

    /** Name für die Dubletten-Suche: klein, ohne Akzente, nur Buchstaben/Ziffern, ohne „the“/„die“ am Anfang. */
    public static function norm($name) {
        $n = strtolower(remove_accents(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')));
        $n = preg_replace('/^(the|die|der|das|dj)\s+/u', '', trim($n));
        return preg_replace('/[^a-z0-9]+/', '', $n);
    }

    /** Spotify-Artist-ID aus Link bzw. ID */
    public static function spotify_id($v) {
        $v = trim((string) $v);
        if (preg_match('#open\.spotify\.com/(?:intl-[a-z]+/)?artist/([A-Za-z0-9]{22})#', $v, $m)) return $m[1];
        if (preg_match('#^spotify:artist:([A-Za-z0-9]{22})$#', $v, $m)) return $m[1];
        return preg_match('/^[A-Za-z0-9]{22}$/', $v) ? $v : '';
    }

    /** Apple-Music-Artist-ID aus Link bzw. ID */
    public static function apple_id($v) {
        $v = trim((string) $v);
        if (preg_match('#music\.apple\.com/[a-z]{2}/artist/(?:[^/]+/)?(\d{3,15})#', $v, $m)) return $m[1];
        return preg_match('/^\d{3,15}$/', $v) ? $v : '';
    }

    private static function bad($field, $msg, $status = 400) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => $status, 'field' => $field]);
    }

    private static function image($id, $credit = '') {
        $id = intval($id);
        if (!$id || get_post_type($id) !== 'attachment') return null;
        $full = (string) wp_get_attachment_url($id);
        return ['id' => $id, 'large' => (string) (wp_get_attachment_image_url($id, 'large') ?: $full), 'medium_large' => (string) (wp_get_attachment_image_url($id, 'medium_large') ?: $full),
            'medium' => (string) (wp_get_attachment_image_url($id, 'medium') ?: $full), 'credit' => (string) $credit];
    }

    private static function raw($id) {
        $id = intval($id);
        if (!$id || get_post_type($id) !== self::CPT) return null;
        $d = get_post_meta($id, self::META, true);
        $d = is_array($d) ? $d : [];
        return $d + ['kind' => 'solo', 'genres' => [], 'origin' => '', 'short_bio' => '', 'bio' => '', 'images' => [], 'links' => [], 'ids' => [], 'tours' => [],
            'status' => 'public', 'verified' => false, 'managed_by' => ['type' => 'evendis', 'id' => 0], 'duplicate_of' => 0];
    }

    private static function save_raw($id, array $d) {
        update_post_meta($id, self::META, $d);
        update_post_meta($id, self::META_NORM, self::norm(get_the_title($id)));
        update_post_meta($id, self::META_SPOTIFY, (string) ($d['ids']['spotify'] ?? ''));
        update_post_meta($id, self::META_APPLE, (string) ($d['ids']['apple'] ?? ''));
        unset(self::$cache[intval($id)]);
    }

    private static function log($id, $action, array $extra = []) {
        $l = get_post_meta($id, self::META_LOG, true);
        $l = is_array($l) ? $l : [];
        array_unshift($l, ['at' => current_time('c'), 'by' => get_current_user_id(), 'action' => $action] + $extra);
        update_post_meta($id, self::META_LOG, array_slice($l, 0, 200));
    }

    private static function my_org() {
        return class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
    }

    private static function is_admin() {
        return current_user_can('manage_options');
    }

    /** Verwalter (eigener Veranstalter verwaltet den Künstler) bzw. Admin? */
    private static function can_manage(array $d) {
        if (self::is_admin()) return true;
        $mb = (array) ($d['managed_by'] ?? []);
        return ($mb['type'] ?? '') === 'organizer' && intval($mb['id'] ?? 0) && intval($mb['id']) === self::my_org() && class_exists('TIX_App_Scope') && TIX_App_Scope::is_org_manager();
    }

    private static function sandbox_org($oid) {
        return class_exists('TIX_Sales') && TIX_Sales::is_sandbox_organizer(intval($oid));
    }

    /** Veröffentlichte Events (freigegeben, ohne Sandbox) eines Künstlers → IDs */
    public static function event_ids($artist_id, $statuses = ['publish']) {
        $ids = array_map('intval', get_posts(['post_type' => 'event', 'post_status' => $statuses, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            'suppress_filters' => true, 'tix_include_sandbox' => 1, 'meta_query' => [['key' => self::EV_REF, 'value' => (string) intval($artist_id)]]]));
        if ($statuses !== ['publish']) return $ids;
        return array_values(array_filter($ids, function ($eid) {
            $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
            if (self::sandbox_org($oid)) return false;
            return !class_exists('TIX_Org_Approval') || TIX_Org_Approval::event_allowed($eid);
        }));
    }

    /** Öffentlich sichtbar? (Status public, nicht zusammengeführt, ≥ 1 veröffentlichtes Event) */
    public static function is_public($id) {
        $d = self::raw($id);
        if (!$d || ($d['status'] ?? '') !== 'public' || get_post_meta($id, self::META_REDIRECT, true) || get_post_status($id) !== 'publish') return false;
        return (bool) self::event_ids($id);
    }

    /** ARTIST (Vertrag 1.1); $full: Verwalter-Sicht mit followers/managed_by */
    public static function payload($id, $full = false) {
        $d = self::raw($id);
        if (!$d) return null;
        $p = get_post($id);
        $img = (array) ($d['images'] ?? []);
        $mb = (array) ($d['managed_by'] ?? []);
        $mb_name = ($mb['type'] ?? '') === 'organizer' && intval($mb['id'] ?? 0) ? (string) get_the_title(intval($mb['id'])) : 'evendis';
        $out = [
            'id' => intval($id), 'slug' => (string) $p->post_name, 'name' => (string) $p->post_title, 'kind' => (string) $d['kind'], 'genres' => array_values((array) $d['genres']),
            'origin' => (string) $d['origin'], 'short_bio' => (string) $d['short_bio'], 'bio' => (string) $d['bio'],
            'images' => ['cover' => self::image($img['cover']['id'] ?? 0, $img['cover']['credit'] ?? ''), 'portrait' => self::image($img['portrait']['id'] ?? 0, $img['portrait']['credit'] ?? '')],
            'links' => array_merge(array_fill_keys(self::LINKS, null), array_filter((array) $d['links'])),
            'ids' => ['spotify' => ($d['ids']['spotify'] ?? '') ?: null, 'apple' => ($d['ids']['apple'] ?? '') ?: null],
            'tours' => array_map(function ($t) { $t['image'] = self::image($t['image'] ?? 0); return $t; }, array_values((array) $d['tours'])),
            'status' => (string) $d['status'], 'verified' => !empty($d['verified']),
            'managed_by' => $full ? ['type' => (string) ($mb['type'] ?? 'evendis'), 'id' => intval($mb['id'] ?? 0), 'name' => $mb_name] : ['name' => $mb_name],
        ];
        if ($full) {
            $out['followers'] = class_exists('TIX_Artist_Follow') ? TIX_Artist_Follow::count($id) : 0;
            $out['duplicate_of'] = intval($d['duplicate_of'] ?? 0) ?: null;
            $out['public'] = self::is_public($id);
        }
        return $out;
    }

    /** Karte für Listen */
    public static function card($id) {
        $a = self::payload($id);
        if (!$a) return null;
        $events = self::upcoming($id);
        $next = $events ? $events[0] : null;
        return ['id' => $a['id'], 'slug' => $a['slug'], 'name' => $a['name'], 'kind' => $a['kind'], 'genres' => $a['genres'],
            'image' => ($a['images']['portrait']['medium'] ?? null) ?: ($a['images']['cover']['medium'] ?? null), 'verified' => $a['verified'],
            'next' => $next ? ['date' => (string) get_post_meta($next, '_tix_date_start', true), 'city' => self::city($next)] : null, 'upcoming_count' => count($events)];
    }

    private static function city($eid) {
        $v = class_exists('TIX_Public_Platform') ? (array) TIX_Public_Platform::venue($eid) : [];
        return (string) ($v['city'] ?? '');
    }

    /** kommende veröffentlichte Events, nach Beginn */
    private static function upcoming($id) {
        $today = current_time('Y-m-d');
        $ev = array_values(array_filter(self::event_ids($id), function ($e) use ($today) {
            return ((string) (get_post_meta($e, '_tix_date_end', true) ?: get_post_meta($e, '_tix_date_start', true))) >= $today;
        }));
        usort($ev, function ($a, $b) { return strcmp(get_post_meta($a, '_tix_date_start', true) . get_post_meta($a, '_tix_time_start', true), get_post_meta($b, '_tix_date_start', true) . get_post_meta($b, '_tix_time_start', true)); });
        return $ev;
    }

    // ═══════════════ Event-Feld `artists` (Event-Editor, öffentliche Payloads) ═══════════════

    /** Zuordnungen eines Events (rohe Liste) */
    public static function event_list($event_id) {
        $l = get_post_meta(intval($event_id), self::EV_META, true);
        return is_array($l) ? array_values($l) : [];
    }

    /** Öffentlich: [{id, slug, name, role, tour, image}] – nur öffentliche Künstler (in Vorschauen: alle zugeordneten, nicht gesperrten) */
    public static function event_public($event_id) {
        if (!self::multi()) return [];
        $preview = class_exists('TIX_Sales_Previews') && TIX_Sales_Previews::rendering_event($event_id);
        $out = [];
        foreach (self::event_list($event_id) as $r) {
            $aid = intval($r['artist_id'] ?? 0);
            $d = self::raw($aid);
            if (!$d || ($d['status'] ?? '') === 'blocked') continue;
            if (!$preview && !isset(self::$cache['pub'][$aid])) self::$cache['pub'][$aid] = self::is_public($aid);
            if (!$preview && !self::$cache['pub'][$aid]) continue;
            $img = (array) ($d['images'] ?? []);
            $iid = intval($img['portrait']['id'] ?? 0) ?: intval($img['cover']['id'] ?? 0);
            $out[] = ['id' => $aid, 'slug' => (string) get_post_field('post_name', $aid), 'name' => (string) get_the_title($aid), 'role' => (string) ($r['role'] ?? 'headliner'),
                'tour' => ($r['tour'] ?? '') ?: null, 'image' => $iid ? (string) (wp_get_attachment_image_url($iid, 'medium') ?: '') : null];
        }
        return $out;
    }

    /** Editor: {artists: [{artist_id, name, slug, image, role, tour, tours[], managed}], artist_suggestions: [...]} */
    public static function editor_payload($event_id) {
        if (!self::multi()) return [];
        $list = [];
        foreach (self::event_list($event_id) as $r) {
            $aid = intval($r['artist_id'] ?? 0);
            $d = self::raw($aid);
            if (!$d) continue;
            $img = (array) ($d['images'] ?? []);
            $iid = intval($img['portrait']['id'] ?? 0) ?: intval($img['cover']['id'] ?? 0);
            $list[] = ['artist_id' => $aid, 'name' => (string) get_the_title($aid), 'slug' => (string) get_post_field('post_name', $aid), 'image' => $iid ? (string) (wp_get_attachment_image_url($iid, 'medium') ?: '') : null,
                'role' => (string) ($r['role'] ?? 'headliner'), 'tour' => ($r['tour'] ?? '') ?: null, 'tours' => array_map(function ($t) { return ['slug' => $t['slug'], 'name' => $t['name']]; }, (array) $d['tours']),
                'status' => (string) $d['status']];
        }
        return ['artists' => $list, 'artist_suggestions' => $event_id ? self::suggestions($event_id, array_column($list, 'artist_id')) : []];
    }

    /** Vorschläge aus dem Line-up: gleicher Name bzw. gleiche Spotify-/Apple-ID */
    public static function suggestions($event_id, array $skip = []) {
        if (!class_exists('TIX_Lineup_Links')) return [];
        $out = [];
        foreach ((array) TIX_Lineup_Links::for_event($event_id) as $row) {
            $hits = self::find(['name' => (string) ($row['artist'] ?? ''), 'spotify' => (string) ($row['spotify'] ?? ''), 'apple' => (string) ($row['apple_music'] ?? '')], 3, false);
            foreach ($hits as $h) {
                if (in_array($h['id'], $skip, true) || isset($out[$h['id']])) continue;
                $out[$h['id']] = $h + ['line' => (string) ($row['name'] ?? '')];
            }
        }
        return array_values($out);
    }

    /**
     * Feld `artists` aus dem Event-Editor prüfen (vor allen Änderungen) → null (nicht gesendet) | Liste | WP_Error.
     * Erlaubt: öffentliche Künstler bzw. die des eigenen Veranstalters, nicht gesperrt.
     */
    public static function check(array $body, $event_id = 0) {
        if (!array_key_exists('artists', $body)) return null;
        if (!self::multi()) return null;
        $in = $body['artists'];
        if ($in === null) $in = [];
        if (!is_array($in) || count($in) > self::MAX_PER_EVENT) return self::bad('artists', 'Höchstens ' . self::MAX_PER_EVENT . ' Künstler je Event.');
        $old = [];
        foreach ($event_id ? self::event_list($event_id) : [] as $r) $old[intval($r['artist_id'])] = $r;
        $org = self::my_org();
        $out = [];
        foreach (array_values($in) as $i => $r) {
            $aid = intval(is_array($r) ? ($r['artist_id'] ?? ($r['id'] ?? 0)) : $r);
            $d = self::raw($aid);
            if (!$d || get_post_meta($aid, self::META_REDIRECT, true)) return self::bad('artists.' . $i . '.artist_id', 'Künstler nicht gefunden.');
            if (($d['status'] ?? '') === 'blocked') return self::bad('artists.' . $i . '.artist_id', 'Dieser Künstler ist gesperrt.', 403);
            $own = ($d['managed_by']['type'] ?? '') === 'organizer' && intval($d['managed_by']['id'] ?? 0) === $org;
            if (($d['status'] ?? '') !== 'public' && !$own && !self::is_admin() && !isset($old[$aid])) return self::bad('artists.' . $i . '.artist_id', 'Dieser Künstler ist noch nicht öffentlich.', 403);
            if (isset($out[$aid])) return self::bad('artists.' . $i . '.artist_id', 'Künstler doppelt.');
            $role = (string) (is_array($r) ? ($r['role'] ?? 'headliner') : 'headliner');
            if (!in_array($role, self::ROLES, true)) return self::bad('artists.' . $i . '.role', 'Rolle: headliner, support oder guest.');
            $tour = is_array($r) ? (string) ($r['tour'] ?? '') : '';
            if ($tour !== '' && !in_array($tour, array_column((array) $d['tours'], 'slug'), true)) return self::bad('artists.' . $i . '.tour', 'Diese Tour gibt es beim Künstler nicht.');
            $out[$aid] = ['artist_id' => $aid, 'role' => $role, 'tour' => $tour ?: null,
                'by_organizer' => intval($old[$aid]['by_organizer'] ?? 0) ?: $org, 'at' => (string) ($old[$aid]['at'] ?? current_time('c')),
                'notified' => !empty($old[$aid]['notified'])];
        }
        return array_values($out);
    }

    /** Geprüfte Liste speichern, Index pflegen, Mitteilungen (nur für veröffentlichte, nicht gesperrte Events). */
    public static function apply($event_id, array $list) {
        update_post_meta($event_id, self::EV_META, $list);
        delete_post_meta($event_id, self::EV_REF);
        foreach ($list as $r) add_post_meta($event_id, self::EV_REF, (string) intval($r['artist_id']));
        foreach (array_column($list, 'artist_id') as $aid) unset(self::$cache['pub'][intval($aid)]);
        self::notify_pending($event_id);
    }

    /** Index `_tix_artist_ref` aus `_tix_artists` neu aufbauen (Kopie, Übernahme einer Vorschau). */
    public static function reindex($event_id) {
        delete_post_meta($event_id, self::EV_REF);
        foreach (self::event_list($event_id) as $r) add_post_meta($event_id, self::EV_REF, (string) intval($r['artist_id']));
    }

    /** Nach dem Kopieren eines Events (Zusatztermin): Index neu, Mitteilungen gelten für die Kopie neu. */
    public static function after_copy($event_id) {
        $list = self::event_list($event_id);
        if (!$list) return;
        foreach ($list as &$r) { $r['notified'] = false; $r['at'] = current_time('c'); }
        unset($r);
        update_post_meta($event_id, self::EV_META, $list);
        self::reindex($event_id);
    }

    /** Event veröffentlicht → offene Zuordnungen melden */
    public static function on_publish($new, $old, $post) {
        if ($new !== 'publish' || $old === 'publish' || !$post || $post->post_type !== 'event' || !self::multi()) return;
        self::notify_pending($post->ID);
    }

    private static function notify_pending($event_id) {
        if (self::$quiet || get_post_status($event_id) !== 'publish') return;
        if (class_exists('TIX_Sales_Previews') && TIX_Sales_Previews::locked_event($event_id)) return;
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        if (self::sandbox_org($oid)) return;
        $list = self::event_list($event_id);
        $changed = false;
        foreach ($list as &$r) {
            if (!empty($r['notified'])) continue;
            $r['notified'] = true;
            $changed = true;
            $aid = intval($r['artist_id']);
            $d = self::raw($aid);
            if (!$d) continue;
            $mb = intval($d['managed_by']['id'] ?? 0);
            $by = intval($r['by_organizer'] ?? 0) ?: $oid;
            self::log($aid, 'assigned', ['event' => intval($event_id), 'organizer' => $by]);
            if (($d['managed_by']['type'] ?? '') === 'organizer' && $mb && $mb !== $by) {
                self::tell_org($mb, 'Neuer Termin bei ' . get_the_title($aid), get_the_title($by) . ' hat „' . get_the_title($event_id) . '“ (' . get_post_meta($event_id, '_tix_date_start', true) . ') zugeordnet.', 'artist:' . $aid);
            }
            if (!empty($d['verified'])) self::tell_admin('Termin an verifiziertem Künstler: ' . get_the_title($aid), get_the_title($by) . ' hat „' . get_the_title($event_id) . '“ zugeordnet.');
        }
        unset($r);
        if ($changed) update_post_meta($event_id, self::EV_META, $list);
    }

    /** Mitteilung (Glocke + Mail) an Inhaber/Team-Admins eines Veranstalters */
    private static function tell_org($oid, $title, $body, $action) {
        $uids = class_exists('TIX_Org_Approval') ? (array) TIX_Org_Approval::manager_ids($oid) : [];
        foreach (array_unique(array_map('intval', $uids)) as $uid) {
            if (class_exists('TIX_Notifications')) TIX_Notifications::add_user_item($uid, $title, $body, ['type' => 'artist', 'action' => $action]);
            $u = get_userdata($uid);
            if ($u && class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($u->user_email, TIX_Mail_Layout::brand()['name'] . ' – ' . $title, ['title' => $title,
                'blocks' => [['type' => 'text', 'html' => esc_html($body)]], 'kind' => 'admin'], ['customer' => false]);
        }
    }

    private static function tell_admin($title, $body) {
        $to = class_exists('TIX_Org_Approval') ? (string) TIX_Org_Approval::admin_email() : '';
        if ($to === '') $to = (string) get_option('admin_email');
        if ($to !== '' && class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($to, TIX_Mail_Layout::brand()['name'] . ' – ' . $title, ['title' => $title, 'blocks' => [['type' => 'text', 'html' => esc_html($body)]], 'kind' => 'admin'], ['customer' => false]);
    }

    // ═══════════════ Suche, Dubletten ═══════════════

    /** Treffer per Spotify-/Apple-ID (zuerst) bzw. normalisiertem Namen → [{id, slug, name, image, upcoming_count, verified, match}] */
    public static function find(array $q, $limit = 10, $partial = true) {
        $res = [];
        $add = function ($ids, $match) use (&$res) { foreach ($ids as $id) { $id = intval($id); if (!isset($res[$id]) && !get_post_meta($id, self::META_REDIRECT, true)) $res[$id] = $match; } };
        $base = ['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true];
        if (($sp = self::spotify_id($q['spotify'] ?? '')) !== '') $add(get_posts($base + ['meta_key' => self::META_SPOTIFY, 'meta_value' => $sp]), 'id');
        if (($ap = self::apple_id($q['apple'] ?? '')) !== '') $add(get_posts($base + ['meta_key' => self::META_APPLE, 'meta_value' => $ap]), 'id');
        $n = self::norm($q['name'] ?? '');
        if (strlen($n) >= 2) {
            $add(get_posts($base + ['meta_key' => self::META_NORM, 'meta_value' => $n]), 'name');
            if ($partial) $add(get_posts($base + ['meta_query' => [['key' => self::META_NORM, 'value' => $n, 'compare' => 'LIKE']]]), 'name');
        }
        $out = [];
        foreach (array_slice($res, 0, $limit, true) as $id => $match) {
            $c = self::card($id);
            if ($c) $out[] = ['id' => $c['id'], 'slug' => $c['slug'], 'name' => $c['name'], 'image' => $c['image'], 'upcoming_count' => $c['upcoming_count'], 'verified' => $c['verified'], 'match' => $match,
                'status' => (string) (self::raw($id)['status'] ?? 'public')];
        }
        return $out;
    }

    // ═══════════════ Pflege (Felder prüfen) ═══════════════

    private static function clean_text($v, $field, $max) {
        $v = trim(sanitize_text_field((string) $v));
        return mb_strlen($v) > $max ? self::bad($field, 'Höchstens ' . $max . ' Zeichen.') : $v;
    }

    private static function clean_url($v, $field) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!preg_match('#^https://[^\s]+$#i', $v)) return self::bad($field, 'Bitte eine https-Adresse angeben.');
        return esc_url_raw($v, ['https']);
    }

    private static function slugify($s) {
        $s = sanitize_title(str_replace(['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'], ['ae', 'oe', 'ue', 'ae', 'oe', 'ue', 'ss'], (string) $s));
        return $s;
    }

    /** Bild aus der Mediathek: eigenes Bild (bzw. Admin), Rechte bestätigt, mit Quelle → [id, credit] | WP_Error */
    private static function clean_image($in, $field, array $cur) {
        if ($in === null || $in === 0 || $in === '0') return null;
        $id = intval(is_array($in) ? ($in['id'] ?? ($in['image_id'] ?? 0)) : $in);
        if ($id === intval($cur['id'] ?? 0)) return ['id' => $id, 'credit' => (string) (is_array($in) && isset($in['credit']) ? sanitize_text_field((string) $in['credit']) : ($cur['credit'] ?? ''))];
        if ($id <= 0 || get_post_type($id) !== 'attachment' || strpos((string) get_post_mime_type($id), 'image/') !== 0) return self::bad($field, 'Bild nicht gefunden.');
        if (!self::is_admin() && intval(get_post_field('post_author', $id)) !== get_current_user_id()) return self::bad($field, 'Bitte ein eigenes, hochgeladenes Bild verwenden.', 403);
        $ok = is_array($in) && filter_var($in['rights_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $credit = is_array($in) ? trim(sanitize_text_field((string) ($in['credit'] ?? ''))) : '';
        if (!$ok) return self::bad($field . '.rights_confirmed', 'Bitte bestätigen, dass du die Rechte an diesem Bild hast.');
        if ($credit === '') return self::bad($field . '.credit', 'Bitte die Quelle angeben („Foto: …“).');
        return ['id' => $id, 'credit' => mb_substr($credit, 0, 120)];
    }

    /** Eingaben auf einen Datensatz anwenden → [Datensatz, Name|null] | WP_Error */
    private static function clean(array $b, array $d, $is_new) {
        $name = null;
        if (array_key_exists('name', $b) || $is_new) {
            $name = self::clean_text($b['name'] ?? '', 'name', 120);
            if (is_wp_error($name)) return $name;
            if ($name === '') return self::bad('name', 'Bitte einen Namen angeben.');
        }
        if (array_key_exists('kind', $b)) {
            if (!in_array($b['kind'], self::KINDS, true)) return self::bad('kind', 'Art: ' . implode(', ', self::KINDS) . '.');
            $d['kind'] = $b['kind'];
        }
        if (array_key_exists('genres', $b)) {
            if (!is_array($b['genres']) || count($b['genres']) > 5) return self::bad('genres', 'Höchstens 5 Genres.');
            $d['genres'] = array_values(array_filter(array_map(function ($g) { return sanitize_title((string) $g); }, $b['genres'])));
        }
        foreach (['origin' => 80, 'short_bio' => 300] as $k => $max) {
            if (!array_key_exists($k, $b)) continue;
            $x = $k === 'short_bio' ? trim(sanitize_textarea_field((string) $b[$k])) : self::clean_text($b[$k], $k, $max);
            if (is_wp_error($x)) return $x;
            if (mb_strlen($x) > $max) return self::bad($k, 'Höchstens ' . $max . ' Zeichen.');
            $d[$k] = $x;
        }
        if (array_key_exists('bio', $b)) {
            if (mb_strlen((string) $b['bio']) > 20000) return self::bad('bio', 'Höchstens 20 000 Zeichen.');
            $d['bio'] = wp_kses_post((string) $b['bio']);
        }
        if (array_key_exists('links', $b)) {
            if (!is_array($b['links'])) return self::bad('links', 'Ungültige Links.');
            foreach (self::LINKS as $k) {
                if (!array_key_exists($k, $b['links'])) continue;
                $u = self::clean_url($b['links'][$k], 'links.' . $k);
                if (is_wp_error($u)) return $u;
                $d['links'][$k] = $u;
            }
        }
        // Kennungen: aus den Links bzw. ausdrücklich
        $ids = (array) ($d['ids'] ?? []);
        if (!empty($d['links']['spotify'])) $ids['spotify'] = self::spotify_id($d['links']['spotify']) ?: ($ids['spotify'] ?? '');
        if (!empty($d['links']['apple_music'])) $ids['apple'] = self::apple_id($d['links']['apple_music']) ?: ($ids['apple'] ?? '');
        if (isset($b['ids']) && is_array($b['ids'])) {
            if (array_key_exists('spotify', $b['ids'])) $ids['spotify'] = self::spotify_id($b['ids']['spotify']);
            if (array_key_exists('apple', $b['ids'])) $ids['apple'] = self::apple_id($b['ids']['apple']);
        }
        $d['ids'] = ['spotify' => (string) ($ids['spotify'] ?? ''), 'apple' => (string) ($ids['apple'] ?? '')];
        foreach (['cover', 'portrait'] as $slot) {
            $key = $slot . '_image';
            if (!array_key_exists($key, $b) && !(isset($b['images']) && is_array($b['images']) && array_key_exists($slot, $b['images']))) continue;
            $in = array_key_exists($key, $b) ? $b[$key] : $b['images'][$slot];
            $img = self::clean_image($in, $key, (array) ($d['images'][$slot] ?? []));
            if (is_wp_error($img)) return $img;
            $d['images'][$slot] = $img;
        }
        if (array_key_exists('tours', $b)) {
            if (!is_array($b['tours']) || count($b['tours']) > 20) return self::bad('tours', 'Höchstens 20 Touren.');
            $tours = [];
            foreach (array_values($b['tours']) as $i => $t) {
                if (!is_array($t)) return self::bad('tours.' . $i, 'Ungültige Tour.');
                $tn = self::clean_text($t['name'] ?? '', 'tours.' . $i . '.name', 120);
                if (is_wp_error($tn)) return $tn;
                if ($tn === '') return self::bad('tours.' . $i . '.name', 'Bitte einen Tour-Namen angeben.');
                $ts = self::slugify(($t['slug'] ?? '') ?: $tn);
                if (isset($tours[$ts])) return self::bad('tours.' . $i . '.slug', 'Tour-Adresse doppelt.');
                foreach (['from', 'to'] as $k) if (($t[$k] ?? '') !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $t[$k])) return self::bad('tours.' . $i . '.' . $k, 'Datum als JJJJ-MM-TT.');
                $img = 0;
                if (!empty($t['image'])) {
                    $iid = intval(is_array($t['image']) ? ($t['image']['id'] ?? 0) : $t['image']);
                    if (get_post_type($iid) !== 'attachment') return self::bad('tours.' . $i . '.image', 'Bild nicht gefunden.');
                    $img = $iid;
                }
                $tours[$ts] = ['slug' => $ts, 'name' => $tn, 'from' => (string) ($t['from'] ?? '') ?: null, 'to' => (string) ($t['to'] ?? '') ?: null, 'image' => $img,
                    'text' => wp_kses_post(mb_substr((string) ($t['text'] ?? ''), 0, 5000))];
            }
            $d['tours'] = array_values($tours);
        }
        if (array_key_exists('status', $b)) {
            if (!in_array($b['status'], ['draft', 'public'], true)) return self::bad('status', 'Status: draft oder public.');
            if (($d['status'] ?? '') === 'blocked' && !self::is_admin()) return self::bad('status', 'Dieser Künstler ist gesperrt.', 403);
            $d['status'] = $b['status'];
        }
        return [$d, $name];
    }

    private static function unique_slug($name, $skip = 0) {
        $base = self::slugify($name) ?: 'kuenstler';
        $slug = $base;
        for ($i = 2; ($p = get_page_by_path($slug, OBJECT, self::CPT)) && intval($p->ID) !== intval($skip); $i++) $slug = $base . '-' . $i;
        return $slug;
    }

    // ═══════════════ REST ═══════════════

    public static function perm_public() {
        return self::multi() ? true : new WP_Error('rest_no_route', 'Nur auf evendis.', ['status' => 404]);
    }

    public static function perm_staff(WP_REST_Request $req) {
        if (!self::multi()) return new WP_Error('rest_no_route', 'Nur auf evendis.', ['status' => 404]);
        if (self::is_admin()) return true;
        return class_exists('TIX_REST_API') ? TIX_REST_API::check_organizer($req) : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
    }

    public static function perm_manager(WP_REST_Request $req) {
        if (!self::multi()) return new WP_Error('rest_no_route', 'Nur auf evendis.', ['status' => 404]);
        if (self::is_admin()) return true;
        return class_exists('TIX_App_Scope') ? TIX_App_Scope::check_manager($req) : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
    }

    public static function perm_admin() {
        if (!self::multi()) return new WP_Error('rest_no_route', 'Nur auf evendis.', ['status' => 404]);
        return self::is_admin() ? true : new WP_Error('rest_forbidden', 'Nur für Plattform-Admins.', ['status' => 403]);
    }

    public static function routes() {
        $pub = ['permission_callback' => [__CLASS__, 'perm_public']];
        $staff = ['permission_callback' => [__CLASS__, 'perm_staff']];
        $mgr = ['permission_callback' => [__CLASS__, 'perm_manager']];
        $adm = ['permission_callback' => [__CLASS__, 'perm_admin']];
        register_rest_route(self::NS, '/public/artists', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public_list']] + $pub);
        register_rest_route(self::NS, '/public/artists/(?P<slug>[a-z0-9-]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public_detail']] + $pub);
        register_rest_route(self::NS, '/public/artists/(?P<slug>[a-z0-9-]+)/tours/(?P<tour>[a-z0-9-]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public_detail']] + $pub);
        register_rest_route(self::NS, '/artists/search', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_search']] + $staff);
        register_rest_route(self::NS, '/artists/mine', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_mine']] + $staff);
        register_rest_route(self::NS, '/artists', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create']] + $mgr);
        register_rest_route(self::NS, '/artists/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get']] + $staff,
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_update']] + $mgr,
        ]);
        register_rest_route(self::NS, '/artists/(?P<id>\d+)/image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_image']] + $mgr);
        register_rest_route(self::NS, '/artists/(?P<id>\d+)/events', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_events']] + $mgr);
        register_rest_route(self::NS, '/artists/(?P<id>\d+)/events/(?P<event_id>\d+)', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_unassign']] + $mgr);
        register_rest_route(self::NS, '/platform/artists', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin_list']] + $adm);
        register_rest_route(self::NS, '/platform/artists/(?P<id>\d+)', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_admin_set']] + $adm);
        register_rest_route(self::NS, '/platform/artists/(?P<id>\d+)/merge', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_merge']] + $adm);
    }

    private static function body(WP_REST_Request $req) {
        $b = $req->get_json_params();
        return is_array($b) ? $b : $req->get_body_params();
    }

    private static function load($id) {
        $id = intval($id);
        if (!self::raw($id)) return new WP_Error('tix_artist', 'Künstler nicht gefunden.', ['status' => 404]);
        return $id;
    }

    // ── Öffentlich ──

    /** GET /public/artists?q=&letter=&genre=&city=&sort=&page=&per_page=&sitemap= */
    public static function rest_public_list(WP_REST_Request $req) {
        $ids = array_map('intval', get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true,
            'orderby' => 'title', 'order' => 'ASC']));
        $ids = array_values(array_filter($ids, [__CLASS__, 'is_public']));
        if ($req->get_param('sitemap')) {
            $items = [];
            foreach ($ids as $id) {
                $d = self::raw($id);
                $items[] = ['slug' => (string) get_post_field('post_name', $id), 'lastmod' => gmdate('Y-m-d\TH:i:s\Z', strtotime(get_post_field('post_modified_gmt', $id) . ' UTC')), 'tours' => array_column((array) $d['tours'], 'slug')];
            }
            $r = rest_ensure_response(['ok' => true, 'items' => $items]);
            $r->header('Cache-Control', 'public, max-age=300');
            return $r;
        }
        $q = self::norm((string) $req->get_param('q'));
        $letter = strtolower(substr(remove_accents((string) $req->get_param('letter')), 0, 1));
        $genre = sanitize_title((string) $req->get_param('genre'));
        $city = mb_strtolower(trim((string) $req->get_param('city')));
        $cards = [];
        foreach ($ids as $id) {
            if ($q !== '' && strpos((string) get_post_meta($id, self::META_NORM, true), $q) === false) continue;
            $name = (string) get_the_title($id);
            if ($letter !== '' && strtolower(substr(remove_accents($name), 0, 1)) !== $letter) continue;
            $c = self::card($id);
            if (!$c) continue;
            if ($genre !== '' && !in_array($genre, $c['genres'], true)) continue;
            if ($city !== '' && !in_array($city, array_map(function ($e) { return mb_strtolower(self::city($e)); }, self::upcoming($id)), true)) continue;
            $c['_sort_new'] = max(array_map(function ($e) { return strtotime((string) get_post_field('post_date_gmt', $e)); }, self::event_ids($id)) ?: [0]);
            $cards[] = $c;
        }
        if ($req->get_param('sort') === 'new') usort($cards, function ($a, $b) { return $b['_sort_new'] - $a['_sort_new']; });
        else usort($cards, function ($a, $b) { return (($b['upcoming_count'] > 0) <=> ($a['upcoming_count'] > 0)) ?: strcasecmp(remove_accents($a['name']), remove_accents($b['name'])); });
        $per = max(1, min(100, intval($req->get_param('per_page') ?: 24)));
        $page = max(1, intval($req->get_param('page') ?: 1));
        $list = array_map(function ($c) { unset($c['_sort_new']); return $c; }, array_slice($cards, ($page - 1) * $per, $per));
        $r = rest_ensure_response(['ok' => true, 'artists' => $list, 'total' => count($cards), 'page' => $page, 'per_page' => $per]);
        $r->header('Cache-Control', 'public, max-age=60');
        return $r;
    }

    /** Zustand eines Termins für die Künstlerseite */
    public static function state($eid, array $p) {
        if ((string) get_post_meta($eid, '_tix_event_status', true) === 'cancelled' || ($p['status'] ?? '') === 'cancelled') return 'cancelled';
        if (!empty($p['free_entry'])) return 'free';
        $pre = class_exists('TIX_Event_Extras') ? TIX_Event_Extras::presale($eid) : ['started' => true];
        if (empty($pre['started'])) return 'presale';
        if (($p['status'] ?? '') === 'sold_out') return 'soldout_waitlist';
        if (class_exists('TIX_Event_Extras') && TIX_Event_Extras::external_shop($eid)) return 'external';
        if (empty($p['tickets_enabled']) && class_exists('TIX_Event_Extras') && TIX_Event_Extras::box_office($eid)) return 'box_office';
        if (($p['status'] ?? '') === 'few_tickets') return 'few';
        return 'onsale';
    }

    private static function termin($eid, $aid) {
        $p = class_exists('TIX_Public_Events') ? TIX_Public_Events::payload($eid) : null;
        if (!$p) return null;
        $role = 'headliner'; $tour = null;
        foreach (self::event_list($eid) as $r) if (intval($r['artist_id']) === intval($aid)) { $role = (string) $r['role']; $tour = $r['tour'] ?? null; }
        $pre = class_exists('TIX_Event_Extras') ? TIX_Event_Extras::presale($eid) : [];
        $p['artist_role'] = $role;
        $p['tour'] = $tour ?: null;
        $p['state'] = self::state($eid, $p);
        $p['presale_start'] = !empty($pre['starts_at']) && empty($pre['started']) ? $pre['starts_at'] : null;
        $p['waitlist'] = !empty($pre['waitlist_soldout']) || !empty($pre['waitlist_presale']);
        return $p;
    }

    private static function schema_type($p) {
        $c = (string) ($p['category']['slug'] ?? '');
        if (preg_match('/comedy|kabarett/', $c)) return 'ComedyEvent';
        if (preg_match('/theater|musical|oper/', $c)) return 'TheaterEvent';
        if (preg_match('/konzert|party|festival|musik|music|club/', $c)) return 'MusicEvent';
        return 'Event';
    }

    private static function jsonld(array $a, array $events, $url) {
        $type = ['band' => 'MusicGroup', 'ensemble' => 'PerformingGroup', 'show' => 'PerformingGroup', 'solo' => 'Person', 'comedian' => 'Person', 'dj' => 'Person'][$a['kind']] ?? 'PerformingGroup';
        $img = ($a['images']['cover']['large'] ?? null) ?: ($a['images']['portrait']['large'] ?? null);
        $sameas = array_values(array_filter([$a['links']['website'], $a['links']['instagram'], $a['links']['tiktok'], $a['links']['youtube'], $a['links']['spotify'], $a['links']['apple_music']]));
        $ev = [];
        foreach ($events as $e) {
            $avail = ['soldout_waitlist' => 'https://schema.org/SoldOut', 'presale' => 'https://schema.org/PreOrder', 'cancelled' => 'https://schema.org/Discontinued'][$e['state']] ?? 'https://schema.org/InStock';
            $node = ['@type' => self::schema_type($e), 'name' => $e['title'], 'startDate' => $e['starts_at'], 'url' => $e['url'],
                'eventStatus' => $e['state'] === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
                'performer' => ['@type' => $type, 'name' => $a['name']],
                'location' => ['@type' => 'Place', 'name' => (string) ($e['venue']['name'] ?? $e['location']), 'address' => (string) ($e['venue']['address'] ?? $e['address'])],
                'organizer' => ['@type' => 'Organization', 'name' => (string) ($e['organizer_info']['name'] ?? $e['organizer'])]];
            if ($e['ends_at']) $node['endDate'] = $e['ends_at'];
            if ($e['image']) $node['image'] = $e['image'];
            if ($e['price_from'] !== null || $e['state'] === 'free') $node['offers'] = ['@type' => 'Offer', 'price' => $e['state'] === 'free' ? 0 : $e['price_from'], 'priceCurrency' => 'EUR', 'availability' => $avail, 'url' => $e['url']];
            $ev[] = $node;
        }
        $base = class_exists('TIX_Customer_URL') ? (TIX_Customer_URL::base() ?: home_url('/')) : home_url('/');
        return [['@context' => 'https://schema.org', '@type' => $type, 'name' => $a['name'], 'url' => $url, 'image' => $img, 'description' => $a['short_bio'] ?: wp_strip_all_tags($a['bio']),
            'sameAs' => $sameas, 'event' => $ev],
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Künstler', 'item' => trailingslashit($base) . 'kuenstler/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $a['name'], 'item' => $url]]]];
    }

    /** GET /public/artists/{slug}[/tours/{tour}]?city=&month=&near=&radius=&tour= */
    public static function rest_public_detail(WP_REST_Request $req) {
        $slug = (string) $req['slug'];
        $p = get_page_by_path($slug, OBJECT, self::CPT);
        if (!$p || $p->post_status !== 'publish') {
            // alter Slug eines zusammengeführten Künstlers
            $old = get_posts(['post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true, 'meta_key' => '_wp_old_slug', 'meta_value' => $slug]);
            if ($old) $p = get_post($old[0]);
        }
        if (!$p) return new WP_Error('tix_artist', 'Künstler nicht gefunden.', ['status' => 404]);
        $into = intval(get_post_meta($p->ID, self::META_REDIRECT, true));
        if ($into && get_post_type($into) === self::CPT) return rest_ensure_response(['ok' => true, 'redirect' => '/kuenstler/' . get_post_field('post_name', $into) . '/']);
        if ($p->post_name !== $slug && $p->post_status === 'publish') return rest_ensure_response(['ok' => true, 'redirect' => '/kuenstler/' . $p->post_name . '/']);
        if (!self::is_public($p->ID)) return new WP_Error('tix_artist', 'Künstler nicht gefunden.', ['status' => 404]);
        $a = self::payload($p->ID);
        $tour_slug = (string) ($req['tour'] ?? '') ?: (string) $req->get_param('tour');
        $tour = null;
        if ($tour_slug !== '') {
            foreach ($a['tours'] as $t) if ($t['slug'] === $tour_slug) $tour = $t;
            if (!$tour && !empty($req['tour'])) return new WP_Error('tix_artist_tour', 'Tour nicht gefunden.', ['status' => 404]);
        }
        $today = current_time('Y-m-d');
        $all = [];
        foreach (self::event_ids($p->ID) as $eid) if ($t = self::termin($eid, $p->ID)) $all[] = $t;
        usort($all, function ($x, $y) { return strcmp($x['date_start'] . $x['time_start'], $y['date_start'] . $y['time_start']); });
        $up = array_values(array_filter($all, function ($e) use ($today) { return (($e['date_end'] ?: $e['date_start']) >= $today); }));
        $past = array_slice(array_reverse(array_values(array_filter($all, function ($e) use ($today) { return (($e['date_end'] ?: $e['date_start']) < $today); }))), 0, 20);
        // Filterwerte aus allen kommenden (vor dem Filtern)
        $cities = []; $months = []; $tours = [];
        foreach ($up as $e) {
            $c = (string) ($e['venue']['city'] ?? '');
            if ($c !== '') $cities[$c] = ($cities[$c] ?? 0) + 1;
            $months[substr($e['date_start'], 0, 7)] = true;
            if ($e['tour']) $tours[$e['tour']] = ($tours[$e['tour']] ?? 0) + 1;
        }
        arsort($cities);
        $f_city = mb_strtolower(trim((string) $req->get_param('city')));
        $f_month = preg_match('/^\d{4}-\d{2}$/', (string) $req->get_param('month')) ? (string) $req->get_param('month') : '';
        $near = class_exists('TIX_Public_Events') ? TIX_Public_Events::parse_near($req->get_param('near')) : null;
        $radius = class_exists('TIX_Public_Events') ? TIX_Public_Events::parse_radius($req->get_param('radius')) : 30;
        $events = array_values(array_filter($up, function ($e) use ($f_city, $f_month, $near, $radius, $tour_slug) {
            if ($tour_slug !== '' && $e['tour'] !== $tour_slug) return false;
            if ($f_city !== '' && mb_strtolower((string) ($e['venue']['city'] ?? '')) !== $f_city) return false;
            if ($f_month !== '' && substr($e['date_start'], 0, 7) !== $f_month) return false;
            if ($near) {
                $la = $e['venue']['lat'] ?? null; $ln = $e['venue']['lng'] ?? null;
                if ($la === null || $ln === null || TIX_Public_Events::distance_km($near[0], $near[1], floatval($la), floatval($ln)) > $radius) return false;
            }
            return true;
        }));
        $days = [];
        foreach ($up as $e) $days[$e['date_start']] = true;
        $base = class_exists('TIX_Customer_URL') ? (TIX_Customer_URL::base() ?: home_url('/')) : home_url('/');
        $url = trailingslashit($base) . 'kuenstler/' . $a['slug'] . '/' . ($tour ? $tour['slug'] . '/' : '');
        $years = array_values(array_unique(array_map(function ($e) { return substr($e['date_start'], 0, 4); }, $up)));
        $title = $a['name'] . ($tour ? ' – ' . $tour['name'] : '') . ' Tickets' . ($years ? ' ' . implode('/', array_slice($years, 0, 2)) : '') . ' – alle Termine | evendis';
        $desc = $a['short_bio'] ?: (count($up) ? count($up) . ' Termine von ' . $a['name'] . ' – Tickets, Orte und Daten auf evendis.' : 'Alle Termine von ' . $a['name'] . ' auf evendis.');
        $tour_counts = array_map(function ($t) use ($tours) { return ['slug' => $t['slug'], 'name' => $t['name'], 'count' => intval($tours[$t['slug']] ?? 0)]; }, $a['tours']);
        $r = rest_ensure_response(['ok' => true, 'artist' => $a, 'tour' => $tour, 'events' => $events, 'past' => $past,
            'filters' => ['cities' => array_map(function ($c, $n) { return ['city' => $c, 'count' => $n]; }, array_keys($cities), array_values($cities)), 'months' => array_keys($months), 'tours' => $tour_counts],
            'days' => array_keys($days), 'upcoming_count' => count($up),
            'seo' => ['title' => $title, 'description' => mb_substr($desc, 0, 160), 'canonical' => $url, 'jsonld' => self::jsonld($a, $tour ? array_values(array_filter($up, function ($e) use ($tour) { return $e['tour'] === $tour['slug']; })) : $up, $url)]]);
        $r->header('Cache-Control', 'public, max-age=60');
        return $r;
    }

    // ── Studio ──

    public static function rest_search(WP_REST_Request $req) {
        return rest_ensure_response(['ok' => true, 'results' => self::find(['name' => (string) $req->get_param('q'), 'spotify' => (string) $req->get_param('spotify'), 'apple' => (string) $req->get_param('apple')], 10)]);
    }

    /** POST /artists {name, kind, …, force?} → 201 {ok, artist, duplicates} | 409 tix_artist_duplicate */
    public static function rest_create(WP_REST_Request $req) {
        $b = self::body($req);
        $org = self::my_org();
        if (!self::is_admin() && (!$org || self::sandbox_org($org))) return new WP_Error('rest_forbidden', 'Mit diesem Konto können keine Künstler angelegt werden.', ['status' => 403]);
        // nur freigegebene Veranstalter (Entscheid F2)
        if (!self::is_admin() && class_exists('TIX_Org_Approval') && !TIX_Org_Approval::is_public($org)) return new WP_Error('organizer_pending', 'Künstler kannst du anlegen, sobald dein Veranstalter-Konto freigegeben ist.', ['status' => 403]);
        $base = ['kind' => 'solo', 'genres' => [], 'origin' => '', 'short_bio' => '', 'bio' => '', 'images' => [], 'links' => [], 'ids' => [], 'tours' => [], 'status' => 'public', 'verified' => false,
            'managed_by' => ['type' => 'organizer', 'id' => $org], 'duplicate_of' => 0];
        if (self::is_admin() && (($b['managed_by'] ?? '') === 'evendis' || !$org)) $base['managed_by'] = ['type' => 'evendis', 'id' => 0];
        $c = self::clean($b, $base, true);
        if (is_wp_error($c)) return $c;
        list($d, $name) = $c;
        $dups = self::find(['name' => $name, 'spotify' => $d['ids']['spotify'] ?? '', 'apple' => $d['ids']['apple'] ?? ''], 5, false);
        $force = !empty($b['force']) && filter_var($b['force'], FILTER_VALIDATE_BOOLEAN);
        if ($dups && !$force) return new WP_Error('tix_artist_duplicate', 'Diesen Künstler gibt es vielleicht schon. Meinst du einen dieser Einträge?', ['status' => 409, 'duplicates' => $dups]);
        if ($dups) $d['duplicate_of'] = $dups[0]['id'];
        $id = wp_insert_post(['post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $name, 'post_name' => self::unique_slug($name)], true);
        if (is_wp_error($id) || !$id) return new WP_Error('tix_artist_failed', 'Der Künstler konnte nicht angelegt werden.', ['status' => 500]);
        self::save_raw($id, $d);
        self::log($id, 'created', $dups ? ['duplicate_of' => $dups[0]['id']] : []);
        if ($dups) self::tell_admin('Mögliche Dublette: ' . $name, 'Neu angelegt trotz Treffer „' . $dups[0]['name'] . '“ (#' . $dups[0]['id'] . ').');
        $r = rest_ensure_response(['ok' => true, 'artist' => self::payload($id, true), 'duplicates' => $dups]);
        $r->set_status(201);
        return $r;
    }

    public static function rest_mine(WP_REST_Request $req) {
        $org = self::my_org();
        $ids = array_map('intval', get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'orderby' => 'title', 'order' => 'ASC']));
        $out = [];
        foreach ($ids as $id) {
            $d = self::raw($id);
            if (($d['managed_by']['type'] ?? '') !== 'organizer' || intval($d['managed_by']['id'] ?? 0) !== $org || get_post_meta($id, self::META_REDIRECT, true)) continue;
            $a = self::payload($id, true);
            $a['events_count'] = count(self::event_ids($id));
            $out[] = $a;
        }
        return rest_ensure_response(['ok' => true, 'artists' => $out]);
    }

    public static function rest_get(WP_REST_Request $req) {
        $id = self::load($req['id']);
        if (is_wp_error($id)) return $id;
        $full = self::can_manage(self::raw($id));
        return rest_ensure_response(['ok' => true, 'artist' => self::payload($id, $full), 'can_manage' => $full]);
    }

    public static function rest_update(WP_REST_Request $req) {
        $id = self::load($req['id']);
        if (is_wp_error($id)) return $id;
        $d = self::raw($id);
        if (!self::can_manage($d)) return new WP_Error('tix_artist_not_manager', 'Dieses Profil pflegt der Verwalter des Künstlers.', ['status' => 403]);
        $c = self::clean(self::body($req), $d, false);
        if (is_wp_error($c)) return $c;
        list($d, $name) = $c;
        if ($name !== null && $name !== get_the_title($id)) wp_update_post(['ID' => $id, 'post_title' => $name]);
        self::save_raw($id, $d);
        self::log($id, 'updated');
        return rest_ensure_response(['ok' => true, 'artist' => self::payload($id, true)]);
    }

    /** POST /artists/{id}/image {slot, rights_confirmed, credit} + Multipart image */
    public static function rest_image(WP_REST_Request $req) {
        $id = self::load($req['id']);
        if (is_wp_error($id)) return $id;
        $d = self::raw($id);
        if (!self::can_manage($d)) return new WP_Error('tix_artist_not_manager', 'Dieses Profil pflegt der Verwalter des Künstlers.', ['status' => 403]);
        $slot = (string) $req->get_param('slot');
        if (!in_array($slot, ['cover', 'portrait'], true)) return self::bad('slot', 'slot: cover oder portrait.');
        if (!filter_var($req->get_param('rights_confirmed'), FILTER_VALIDATE_BOOLEAN)) return self::bad('rights_confirmed', 'Bitte bestätigen, dass du die Rechte an diesem Bild hast.');
        $credit = trim(sanitize_text_field((string) $req->get_param('credit')));
        if ($credit === '') return self::bad('credit', 'Bitte die Quelle angeben („Foto: …“).');
        $img = class_exists('TIX_AI_REST') ? TIX_AI_REST::read_image($req) : new WP_Error('tix_invalid_field', 'Kein Bild.', ['status' => 400]);
        if (is_wp_error($img)) return $img;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$img[1]] ?? 'jpg';
        $up = wp_upload_bits('kuenstler-' . self::slugify(get_the_title($id)) . '-' . wp_generate_password(6, false, false) . '.' . $ext, null, $img[0]);
        if (!empty($up['error'])) return new WP_Error('tix_upload_failed', 'Das Bild konnte nicht gespeichert werden.', ['status' => 500]);
        $aid = wp_insert_attachment(['post_mime_type' => $img[1], 'post_title' => get_the_title($id), 'post_status' => 'inherit', 'post_parent' => $id, 'post_author' => get_current_user_id()], $up['file']);
        if (!$aid || is_wp_error($aid)) return new WP_Error('tix_upload_failed', 'Das Bild konnte nicht gespeichert werden.', ['status' => 500]);
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $up['file']));
        update_post_meta($aid, '_tix_artist_image_rights', ['confirmed_by' => get_current_user_id(), 'at' => current_time('c'), 'credit' => mb_substr($credit, 0, 120)]);
        $d['images'][$slot] = ['id' => intval($aid), 'credit' => mb_substr($credit, 0, 120)];
        self::save_raw($id, $d);
        self::log($id, 'image', ['slot' => $slot]);
        return rest_ensure_response(['ok' => true, 'artist' => self::payload($id, true)]);
    }

    /** GET /artists/{id}/events – alle Termine aller Veranstalter (lesend, ohne Umsätze) */
    public static function rest_events(WP_REST_Request $req) {
        $id = self::load($req['id']);
        if (is_wp_error($id)) return $id;
        if (!self::can_manage(self::raw($id))) return new WP_Error('tix_artist_not_manager', 'Nur der Verwalter sieht alle Termine.', ['status' => 403]);
        $out = [];
        foreach (self::event_ids($id, ['publish', 'future', 'draft', 'pending']) as $eid) {
            $oid = intval(get_post_meta($eid, '_tix_organizer_id', true));
            if (self::sandbox_org($oid)) continue;
            $by = 0; $role = 'headliner'; $tour = null;
            foreach (self::event_list($eid) as $r) if (intval($r['artist_id']) === $id) { $by = intval($r['by_organizer'] ?? 0); $role = $r['role']; $tour = $r['tour'] ?? null; }
            $out[] = ['id' => $eid, 'title' => (string) get_the_title($eid), 'date_start' => (string) get_post_meta($eid, '_tix_date_start', true), 'city' => self::city($eid),
                'status' => (string) get_post_status($eid), 'organizer' => ['id' => $oid, 'name' => (string) get_the_title($oid)], 'role' => $role, 'tour' => $tour,
                'assigned_by' => ['organizer_id' => $by ?: $oid, 'name' => (string) get_the_title($by ?: $oid)]];
        }
        usort($out, function ($a, $b) { return strcmp($a['date_start'], $b['date_start']); });
        return rest_ensure_response(['ok' => true, 'events' => $out]);
    }

    /** DELETE /artists/{id}/events/{event_id} {reason} */
    public static function rest_unassign(WP_REST_Request $req) {
        $id = self::load($req['id']);
        if (is_wp_error($id)) return $id;
        if (!self::can_manage(self::raw($id))) return new WP_Error('tix_artist_not_manager', 'Nur der Verwalter kann Zuordnungen entfernen.', ['status' => 403]);
        $eid = intval($req['event_id']);
        $list = self::event_list($eid);
        $hit = array_values(array_filter($list, function ($r) use ($id) { return intval($r['artist_id']) === $id; }));
        if (!$hit) return new WP_Error('tix_event', 'Dieses Event ist dem Künstler nicht zugeordnet.', ['status' => 404]);
        $reason = trim(sanitize_textarea_field((string) $req->get_param('reason')));
        if ($reason === '') return self::bad('reason', 'Bitte einen Grund angeben.');
        self::$quiet = true;
        self::apply($eid, array_values(array_filter($list, function ($r) use ($id) { return intval($r['artist_id']) !== $id; })));
        self::$quiet = false;
        self::log($id, 'removed', ['event' => $eid, 'reason' => mb_substr($reason, 0, 500)]);
        $by = intval($hit[0]['by_organizer'] ?? 0) ?: intval(get_post_meta($eid, '_tix_organizer_id', true));
        if ($by && $by !== self::my_org()) self::tell_org($by, 'Zuordnung entfernt: ' . get_the_title($id), '„' . get_the_title($eid) . '“ ist nicht mehr dem Künstler ' . get_the_title($id) . ' zugeordnet. Grund: ' . $reason, 'event:' . $eid);
        return rest_ensure_response(['ok' => true, 'removed' => true]);
    }

    // ── Admin ──

    public static function rest_admin_list(WP_REST_Request $req) {
        $ids = array_map('intval', get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 500, 'fields' => 'ids', 'suppress_filters' => true, 'orderby' => 'title', 'order' => 'ASC']));
        $dup = (bool) $req->get_param('duplicates');
        $out = [];
        foreach ($ids as $id) {
            if (get_post_meta($id, self::META_REDIRECT, true)) continue;
            $d = self::raw($id);
            if ($dup && empty($d['duplicate_of'])) continue;
            $out[] = self::payload($id, true);
        }
        return rest_ensure_response(['ok' => true, 'artists' => $out]);
    }

    /** POST /platform/artists/{id} {verified?, status?: blocked|public|draft, managed_by?: {type, id}|"evendis"} */
    public static function rest_admin_set(WP_REST_Request $req) {
        $id = self::load($req['id']);
        if (is_wp_error($id)) return $id;
        $d = self::raw($id);
        $b = self::body($req);
        if (array_key_exists('verified', $b)) $d['verified'] = filter_var($b['verified'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('status', $b)) {
            if (!in_array($b['status'], ['blocked', 'public', 'draft'], true)) return self::bad('status', 'Status: public, draft oder blocked.');
            $d['status'] = $b['status'];
        }
        if (array_key_exists('managed_by', $b)) {
            $m = $b['managed_by'];
            if ($m === 'evendis' || (is_array($m) && ($m['type'] ?? '') === 'evendis')) $d['managed_by'] = ['type' => 'evendis', 'id' => 0];
            elseif (is_array($m) && ($m['type'] ?? '') === 'organizer' && get_post_type(intval($m['id'] ?? 0)) === 'tix_organizer') $d['managed_by'] = ['type' => 'organizer', 'id' => intval($m['id'])];
            else return self::bad('managed_by', 'Verwalter: "evendis" oder {type: organizer, id}.');
        }
        if (array_key_exists('duplicate_of', $b) && !$b['duplicate_of']) $d['duplicate_of'] = 0;
        self::save_raw($id, $d);
        self::log($id, 'admin', array_intersect_key($b, array_flip(['verified', 'status', 'managed_by'])));
        return rest_ensure_response(['ok' => true, 'artist' => self::payload($id, true)]);
    }

    /** POST /platform/artists/{id}/merge {into} – Events, Follower, Links/IDs, Touren wandern; alte Adresse leitet weiter */
    public static function rest_merge(WP_REST_Request $req) {
        $from = self::load($req['id']);
        if (is_wp_error($from)) return $from;
        $into = intval(self::body($req)['into'] ?? 0);
        if (!self::raw($into) || $into === $from || get_post_meta($into, self::META_REDIRECT, true)) return self::bad('into', 'Ziel-Künstler nicht gefunden.');
        $a = self::raw($from); $b = self::raw($into);
        foreach (self::LINKS as $k) if (empty($b['links'][$k]) && !empty($a['links'][$k])) $b['links'][$k] = $a['links'][$k];
        foreach (['spotify', 'apple'] as $k) if (empty($b['ids'][$k]) && !empty($a['ids'][$k])) $b['ids'][$k] = $a['ids'][$k];
        $have = array_column((array) $b['tours'], 'slug');
        foreach ((array) $a['tours'] as $t) if (!in_array($t['slug'], $have, true)) $b['tours'][] = $t;
        foreach (['short_bio', 'bio', 'origin'] as $k) if (($b[$k] ?? '') === '' && ($a[$k] ?? '') !== '') $b[$k] = $a[$k];
        foreach (['cover', 'portrait'] as $s) if (empty($b['images'][$s]['id']) && !empty($a['images'][$s]['id'])) $b['images'][$s] = $a['images'][$s];
        self::save_raw($into, $b);
        $moved = 0;
        self::$quiet = true;
        foreach (self::event_ids($from, array_values(get_post_stati())) as $eid) {
            $list = self::event_list($eid);
            $has_into = in_array($into, array_map('intval', array_column($list, 'artist_id')), true);
            $new = [];
            foreach ($list as $r) {
                if (intval($r['artist_id']) === $from) { if ($has_into) continue; $r['artist_id'] = $into; }
                $new[] = $r;
            }
            self::apply($eid, $new);
            $moved++;
        }
        self::$quiet = false;
        if (class_exists('TIX_Artist_Follow')) TIX_Artist_Follow::merge($from, $into);
        // alte Adresse → Weiterleitung (alter Slug bleibt am Ziel als _wp_old_slug)
        add_post_meta($into, '_wp_old_slug', get_post_field('post_name', $from));
        update_post_meta($from, self::META_REDIRECT, $into);
        $a['status'] = 'blocked';
        self::save_raw($from, $a);
        wp_update_post(['ID' => $from, 'post_status' => 'draft', 'post_name' => get_post_field('post_name', $from) . '-zusammengefuehrt']);
        self::log($into, 'merged', ['from' => $from, 'events' => $moved]);
        self::log($from, 'merged_into', ['into' => $into]);
        return rest_ensure_response(['ok' => true, 'artist' => self::payload($into, true), 'moved_events' => $moved]);
    }
}

TIX_Artists::init();
