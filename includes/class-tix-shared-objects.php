<?php
if (!defined('ABSPATH')) exit;

/**
 * Geteilte Objekte als lokale, schreibgeschützte Spiegel (1.38.738, Auftrag uebergaben/auftrag-sync-geteilte-objekte-2026-11-10.md).
 *
 * Bei Kopien geteilter Events (Spiegel der Event-Verteilung auf evendis, Kopien von Plattform-Co-Events auf Kunden-Seiten) legt
 * diese Seite die zugehörigen Objekte lokal an bzw. aktualisiert sie:
 *   - Künstler (tix_artist): Name, Bilder (als Adresse), Kurztext, Links, Künstlerseite der Quelle; Index _tix_artist_ref am Event,
 *     damit sie in /public/artists und im Studio unter „Künstler“ erscheinen
 *   - Ort (tix_location, nur Kunden-Seiten): Name, Adresse, PLZ, Stadt, Koordinaten; _tix_location_id am Event
 *   - Kategorien (event_category, nur Kunden-Seiten): Begriffe nach Namen
 * Markierung Meta `_tix_mirror` = {host, site, source_id, url, key, …}; gibt es ein eigenes Objekt gleichen Namens, wird es verknüpft
 * statt einen Spiegel anzulegen. Spiegel sind über Studio/App nicht änderbar (409 tix_mirrored_readonly); Listen liefern `source`
 * und `readonly`. Ohne Event, das sie nutzt, werden Spiegel gelöscht.
 */
class TIX_Shared_Objects {

    const META = '_tix_mirror';

    public static function init() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard'], 7, 3);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'decorate'], 40, 3);
        add_action('trashed_post', [__CLASS__, 'on_event_gone']);
        add_action('deleted_post', [__CLASS__, 'on_event_gone']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function mirror($post_id) {
        $m = get_post_meta(intval($post_id), self::META, true);
        return is_array($m) && !empty($m['host']) ? $m : null;
    }

    private static function host_of($url) {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) $url, PHP_URL_HOST)));
    }

    /** Herkunft einer Kopie → {host, site} | null (kein geteiltes Event) */
    public static function source_of($eid) {
        $eid = intval($eid);
        if (get_post_meta($eid, '_tix_syndicated', true) === '1') { // Spiegel der Event-Verteilung (evendis)
            $p = class_exists('TIX_Partners') ? TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true)) : null;
            $host = $p ? self::host_of($p['api_base'] ?? '') : self::host_of(get_post_meta($eid, '_tix_source_site', true) ?: get_post_meta($eid, '_tix_source_url', true));
            $site = $p ? (string) $p['name'] : (string) get_post_meta($eid, '_tix_source_site', true);
            return $host !== '' ? ['host' => $host, 'site' => $site ?: $host] : null;
        }
        if (!self::multi() && intval(get_post_meta($eid, '_tix_platform_co', true)) > 0) { // Kopie eines Plattform-Co-Events (Kunden-Seite)
            $src = (string) get_post_meta($eid, '_tix_platform_co_source', true);
            $host = $src !== '' ? (self::host_of($src) ?: strtolower($src)) : self::host_of((string) tix_get_settings('syndication_api_url'));
            $by = (string) get_post_meta($eid, '_tix_platform_co_by', true);
            return $host !== '' ? ['host' => $host, 'site' => $src !== '' ? $host : ($by ?: $host)] : null;
        }
        return null;
    }

    // ═══════════════ Künstler ═══════════════

    /** Eigener (kein Spiegel) Künstler gleichen Schlüssels im Bereich des Events → ID | 0 */
    private static function own_artist($key, $eid) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", TIX_Artists::CPT));
        $org = self::multi() ? intval(get_post_meta($eid, '_tix_organizer_id', true)) : 0;
        foreach ((array) $rows as $r) {
            if (TIX_Artists::act_key($r->post_title) !== $key || self::mirror($r->ID) || get_post_meta($r->ID, TIX_Artists::META_REDIRECT, true)) continue;
            if ($org) { $d = (array) get_post_meta($r->ID, TIX_Artists::META, true); if (($d['managed_by']['type'] ?? '') !== 'organizer' || intval($d['managed_by']['id'] ?? 0) !== $org) continue; }
            return intval($r->ID);
        }
        return 0;
    }

    private static function find_mirror($type, $key) {
        $ids = get_posts(['post_type' => $type, 'post_status' => ['publish', 'draft'], 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => [['key' => self::META . '_key', 'value' => $key]]]);
        return $ids ? intval($ids[0]) : 0;
    }

    /** Künstler einer Kopie spiegeln bzw. eigene verknüpfen; Index am Event setzen. → [Künstler-IDs] */
    public static function sync_artists($eid, array $snapshot) {
        $src = self::source_of($eid);
        if (!$src || !class_exists('TIX_Artists')) return [];
        $ids = [];
        $org = self::multi() ? intval(get_post_meta($eid, '_tix_organizer_id', true)) : 0;
        foreach ($snapshot as $a) {
            if (!is_array($a) || trim((string) ($a['name'] ?? '')) === '') continue;
            $name = (string) $a['name'];
            $key = TIX_Artists::act_key($name);
            if ($key === '') continue;
            if ($own = self::own_artist($key, $eid)) { $ids[] = $own; continue; }
            $mkey = $src['host'] . '|' . (intval($a['source_id'] ?? 0) ?: $key);
            $mid = self::find_mirror(TIX_Artists::CPT, $mkey);
            if (!$mid) {
                $mid = wp_insert_post(['post_type' => TIX_Artists::CPT, 'post_status' => 'publish', 'post_title' => $name, 'post_name' => sanitize_title($name)], true);
                if (is_wp_error($mid)) continue;
                update_post_meta($mid, self::META . '_key', $mkey);
            } elseif (get_the_title($mid) !== $name) {
                wp_update_post(['ID' => $mid, 'post_title' => $name]);
            }
            $links = [];
            foreach ((array) ($a['links'] ?? []) as $k => $v) if ($v) $links[$k] = esc_url_raw((string) $v);
            update_post_meta($mid, TIX_Artists::META, ['kind' => (string) ($a['kind'] ?? 'solo'), 'short_bio' => (string) ($a['short_bio'] ?? ''), 'links' => $links,
                'images' => [], 'ids' => ['spotify' => '', 'apple' => ''], 'tours' => [], 'status' => 'public', 'verified' => false,
                'managed_by' => $org ? ['type' => 'organizer', 'id' => $org] : ['type' => 'site', 'id' => 0]]);
            update_post_meta($mid, TIX_Artists::META_NORM, TIX_Artists::norm($name));
            update_post_meta($mid, self::META, ['host' => $src['host'], 'site' => $src['site'], 'source_id' => intval($a['source_id'] ?? 0) ?: null,
                'url' => ($a['url'] ?? null) ?: null, 'portrait' => $a['portrait'] ?? null, 'cover' => $a['cover'] ?? null, 'credit' => (string) ($a['credit'] ?? ''), 'at' => gmdate('c')]);
            $ids[] = intval($mid);
        }
        $ids = array_values(array_unique($ids));
        delete_post_meta($eid, TIX_Artists::EV_REF);
        foreach ($ids as $id) add_post_meta($eid, TIX_Artists::EV_REF, (string) $id);
        TIX_Artists::flush_cache();
        self::cleanup();
        return $ids;
    }

    /** Bilder eines gespiegelten Künstlers (Adressen der Quelle) für images_public */
    public static function artist_images($aid) {
        $m = self::mirror($aid);
        if (!$m) return null;
        $one = function ($f) { return is_array($f) && (!empty($f['large']) || !empty($f['medium'])) ? ['id' => null, 'large' => (string) ($f['large'] ?: $f['medium']), 'medium_large' => (string) (($f['medium_large'] ?? '') ?: ($f['large'] ?: $f['medium'])), 'medium' => (string) ($f['medium'] ?: $f['large']), 'credit' => (string) ($f['credit'] ?? '')] : null; };
        return ['portrait' => $one($m['portrait'] ?? null), 'cover' => $one($m['cover'] ?? null)];
    }

    // ═══════════════ Orte, Kategorien (Kunden-Seiten) ═══════════════

    private static function norm($s) {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'))));
    }

    /** Ort einer Kopie: eigener gleichen Namens + Adresse, sonst Spiegel. → Ort-ID | 0 */
    public static function sync_location($eid, $venue) {
        $src = self::source_of($eid);
        if (!$src || self::multi() || !is_array($venue)) return 0;
        $name = trim((string) ($venue['name'] ?? '')); $addr = trim((string) ($venue['address'] ?? ''));
        if ($name === '' && $addr === '') return 0;
        $nk = self::norm($name) . '|' . self::norm($addr);
        $lid = 0;
        foreach (get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => 300, 'fields' => 'ids', 'suppress_filters' => true]) as $x) {
            if (self::mirror($x)) continue;
            if (self::norm(get_the_title($x)) . '|' . self::norm(get_post_meta($x, '_tix_loc_address', true)) === $nk || ($addr === '' && self::norm(get_the_title($x)) === self::norm($name))) { $lid = intval($x); break; }
        }
        if (!$lid) {
            $mkey = $src['host'] . '|' . (intval($venue['id'] ?? 0) ?: $nk);
            $lid = self::find_mirror('tix_location', $mkey);
            if (!$lid) {
                $lid = wp_insert_post(['post_type' => 'tix_location', 'post_status' => 'publish', 'post_title' => $name ?: $addr], true);
                if (is_wp_error($lid)) return 0;
                update_post_meta($lid, self::META . '_key', $mkey);
            }
            foreach (['address' => '_tix_loc_address', 'zip' => '_tix_loc_zip', 'city' => '_tix_loc_city', 'country' => '_tix_loc_country'] as $k => $mk) update_post_meta($lid, $mk, sanitize_text_field((string) ($venue[$k] ?? '')));
            if (is_numeric($venue['lat'] ?? null) && is_numeric($venue['lng'] ?? null)) { update_post_meta($lid, '_tix_loc_lat', (string) floatval($venue['lat'])); update_post_meta($lid, '_tix_loc_lng', (string) floatval($venue['lng'])); }
            update_post_meta($lid, self::META, ['host' => $src['host'], 'site' => $src['site'], 'source_id' => intval($venue['id'] ?? 0) ?: null, 'at' => gmdate('c')]);
        }
        update_post_meta($eid, '_tix_location_id', $lid);
        self::cleanup();
        return $lid;
    }

    public static function sync_categories($eid, $names) {
        if (self::multi() || !is_array($names) || !self::source_of($eid)) return;
        $names = array_values(array_filter(array_map(function ($n) { return sanitize_text_field((string) $n); }, $names)));
        wp_set_object_terms($eid, $names, 'event_category', false);
    }

    // ═══════════════ Aufräumen, Schutz, Listen ═══════════════

    /** Spiegel ohne nutzendes Event (nicht im Papierkorb) löschen. → Anzahl */
    public static function cleanup() {
        $n = 0;
        $live = ['publish', 'draft', 'pending', 'private', 'future'];
        foreach (get_posts(['post_type' => [class_exists('TIX_Artists') ? TIX_Artists::CPT : 'tix_artist', 'tix_location'], 'post_status' => 'any', 'posts_per_page' => 500, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => [['key' => self::META . '_key', 'compare' => 'EXISTS']]]) as $id) {
            $type = get_post_type($id);
            $q = $type === 'tix_location' ? [['key' => '_tix_location_id', 'value' => (string) $id]] : [['key' => '_tix_artist_ref', 'value' => (string) $id]];
            if (get_posts(['post_type' => 'event', 'post_status' => $live, 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true, 'meta_query' => $q])) continue;
            wp_delete_post($id, true);
            $n++;
        }
        return $n;
    }

    public static function on_event_gone($post_id) {
        if (get_post_type($post_id) === 'event' || !get_post($post_id)) {
            if (!has_action('shutdown', [__CLASS__, 'cleanup'])) add_action('shutdown', [__CLASS__, 'cleanup'], 20);
        }
    }

    /** Studio/App: Spiegel nicht ändern */
    public static function guard($result, $server, $request) {
        if ($result !== null || $request->get_method() === 'GET') return $result;
        if (preg_match('#^/tixomat/v1/(artists|locations)/(\d+)(/|$)#', (string) $request->get_route(), $m) && ($mm = self::mirror(intval($m[2])))) {
            return new WP_Error('tix_mirrored_readonly', 'Dieser Eintrag kommt von ' . $mm['site'] . ' und wird dort gepflegt – hier nur lesbar.', ['status' => 409, 'source' => self::source_payload($mm)]);
        }
        return $result;
    }

    public static function source_payload(array $m) {
        return ['host' => (string) $m['host'], 'site' => (string) ($m['site'] ?? $m['host']), 'label' => 'von ' . (string) $m['host'], 'url' => ($m['url'] ?? null) ?: null];
    }

    /** Listen und Einzelansichten: source + readonly an Künstlern und Orten */
    public static function decorate($response, $handler, $request) {
        if (is_wp_error($response) || $request->get_method() !== 'GET') return $response;
        $route = (string) $request->get_route();
        if (!preg_match('#^/tixomat/v1/(artists/mine|artists/\d+|locations|public/artists(/[a-z0-9-]+)?)$#', $route)) return $response;
        $response = rest_ensure_response($response);
        $d = $response->get_data();
        if (!is_array($d)) return $response;
        $add = function (array $row) {
            $id = intval($row['id'] ?? 0);
            $m = $id ? self::mirror($id) : null;
            $row['source'] = $m ? self::source_payload($m) : null;
            $row['readonly'] = (bool) $m;
            if ($m && array_key_exists('editable', $row)) $row['editable'] = false;
            return $row;
        };
        foreach (['artists', 'locations', 'items'] as $k) if (isset($d[$k]) && is_array($d[$k])) $d[$k] = array_map(function ($r) use ($add) { return is_array($r) ? $add($r) : $r; }, $d[$k]);
        foreach (['artist', 'location'] as $k) if (isset($d[$k]) && is_array($d[$k])) $d[$k] = $add($d[$k]);
        if (isset($d['id']) && !isset($d['artists'])) $d = $add($d);
        $response->set_data($d);
        return $response;
    }
}

TIX_Shared_Objects::init();
