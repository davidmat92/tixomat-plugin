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
        // Einmal nach 1.38.740: vorhandene Spiegel neu abgleichen (Bilder)
        add_action('tix_shared_objects_repair', [__CLASS__, 'repair']);
        // 1.38.743 erneut: vollständig (alle Kopien, eigene Künstler ohne Bild, Bilder umgezogener Künstler)
        add_action('init', function () {
            if (get_option('tix_shared_objects_repair_755', '') !== '') return; // 1.38.755: versehentlich eigene → Spiegel
            update_option('tix_shared_objects_repair_755', gmdate('c'), false);
            if (!wp_next_scheduled('tix_shared_objects_repair')) wp_schedule_single_event(time() + 300, 'tix_shared_objects_repair');
        }, 41);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat spiegel', function ($args) {
            if (($args[0] ?? '') === 'reparieren') {
                $r = self::repair(true);
                WP_CLI::log(wp_json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                WP_CLI::success(sprintf('%d Events neu gespiegelt, %d Künstler ohne Bild (vorher %d).', $r['events'], $r['after']['without_image'], $r['before']['without_image']));
                return;
            }
            if (($args[0] ?? '') === 'pruefen') { // alle öffentlichen Künstler mit Herkunft und Bildstand
                $sum = self::artist_report();
                foreach ($sum['items'] as $i) WP_CLI::line(sprintf('%-6d %-11s %-32s Bild: %s', $i['id'], $i['type'], mb_substr($i['name'], 0, 32), $i['image']));
                WP_CLI::success(sprintf('%d Künstler, %d mit Bild, %d ohne Bild.', $sum['total'], $sum['total'] - $sum['without_image'], $sum['without_image']));
                return;
            }
            foreach (get_posts(['post_type' => ['tix_artist', 'tix_location'], 'post_status' => 'any', 'posts_per_page' => 500, 'fields' => 'ids', 'meta_query' => [['key' => self::META . '_key', 'compare' => 'EXISTS']]]) as $id) {
                $m = self::mirror($id) ?: [];
                WP_CLI::line(sprintf('%-6d %-14s %-30s von %s · Bild %s%s', $id, get_post_type($id), get_the_title($id), $m['host'] ?? '?', !empty($m['attachment']) ? '#' . $m['attachment'] : (!empty($m['image']) || !empty($m['portrait']) ? 'Quelle' : '–'), !empty($m['img_error']) ? ' (' . $m['img_error'] . ')' : ''));
            }
        });
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

    /**
     * Eigener Künstler, der nur versehentlich „eigen“ ist (1.38.755, Fund KMT): aus dem Line-up angelegt („Künstler aus Line-up
     * übernehmen“ an einer Kopie, vor 1.38.743), nur Kopien geteilter Events zugeordnet, keine eigenen Angaben (Text, Links, Genres,
     * Touren, eigenes Bild), keine Follower, nicht umgezogen. Solche Einträge ersetzt der Spiegel der Quelle.
     */
    public static function accidental_own($aid) {
        $aid = intval($aid);
        if (!$aid || self::mirror($aid) || intval(get_post_meta($aid, '_tix_moved_from', true)) > 0 || get_post_meta($aid, TIX_Artists::META_REDIRECT, true)) return false;
        $log = (array) get_post_meta($aid, TIX_Artists::META_LOG, true);
        $from_lineup = false;
        foreach ($log as $l) {
            $act = (string) ($l['action'] ?? '');
            if ($act === 'created' && ($l['from'] ?? '') === 'lineup') $from_lineup = true;
            elseif (!in_array($act, ['created', 'assigned', 'unassigned'], true)) return false; // bearbeitet, zusammengeführt …
        }
        if (!$from_lineup) return false;
        $d = get_post_meta($aid, TIX_Artists::META, true);
        $d = is_array($d) ? $d : [];
        if (trim((string) ($d['short_bio'] ?? '')) !== '' || trim(wp_strip_all_tags((string) ($d['bio'] ?? ''))) !== '' || array_filter((array) ($d['links'] ?? []))
            || array_filter((array) ($d['genres'] ?? [])) || array_filter((array) ($d['tours'] ?? [])) || !empty($d['verified'])) return false;
        foreach (['portrait', 'cover'] as $slot) {
            $att = intval($d['images'][$slot]['id'] ?? 0);
            if ($att && intval(get_post_meta($att, '_tix_adopted_for', true)) !== $aid) return false; // eigenes Bild
        }
        if (class_exists('TIX_Artist_Follow') && method_exists('TIX_Artist_Follow', 'count') && intval(TIX_Artist_Follow::count($aid)) > 0) return false;
        $evs = TIX_Artists::event_ids($aid, ['publish', 'draft', 'future', 'private', 'pending']);
        foreach ($evs as $e) if (!TIX_Artists::is_copy($e)) return false;
        return true;
    }

    /** Versehentlich eigenen Künstler entfernen (übernommenes Bild mit), Zuordnungen an Kopien lösen. */
    private static function drop_own($aid) {
        $aid = intval($aid);
        $d = get_post_meta($aid, TIX_Artists::META, true);
        foreach (['portrait', 'cover'] as $slot) {
            $att = intval(is_array($d) ? ($d['images'][$slot]['id'] ?? 0) : 0);
            if ($att && intval(get_post_meta($att, '_tix_adopted_for', true)) === $aid) wp_delete_attachment($att, true);
        }
        foreach (TIX_Artists::event_ids($aid, ['publish', 'draft', 'future', 'private', 'pending']) as $e) {
            $list = array_values(array_filter((array) get_post_meta($e, TIX_Artists::EV_META, true), function ($r) use ($aid) { return intval($r['artist_id'] ?? 0) !== $aid; }));
            if ($list) update_post_meta($e, TIX_Artists::EV_META, $list); else delete_post_meta($e, TIX_Artists::EV_META);
            delete_post_meta($e, TIX_Artists::EV_REF, (string) $aid);
        }
        wp_delete_post($aid, true);
        update_option('tix_shared_objects_dropped', array_slice(array_merge((array) get_option('tix_shared_objects_dropped', []), [['id' => $aid, 'at' => gmdate('c')]]), -200), false);
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
            if ($own = self::own_artist($key, $eid)) {
                // 1.38.755: versehentlich „eigen“ (aus dem Line-up einer Kopie angelegt, ohne eigene Angaben) → durch Spiegel ersetzen
                if (self::accidental_own($own)) self::drop_own($own);
                else { self::adopt_image($own, $a, $name); $ids[] = $own; continue; }
            }
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
            // Bild (1.38.740): Porträt, sonst Titelbild, sonst das Bild des Acts (Act-Foto) – in die Mediathek dieser Seite
            $src_url = (string) (($a['portrait']['large'] ?? '') ?: ($a['portrait']['medium'] ?? '') ?: ($a['cover']['large'] ?? '') ?: ($a['cover']['medium'] ?? '') ?: ($a['image'] ?? ''));
            $prev = self::mirror($mid) ?: [];
            $att = intval($prev['attachment'] ?? 0);
            $err = null;
            if ($src_url === '') { if ($att) wp_delete_attachment($att, true); $att = 0; }
            elseif ($src_url !== (string) ($prev['img_src'] ?? '') || !$att || !get_post($att)) {
                $new = self::sideload($src_url, $mid, $name);
                if (is_wp_error($new)) { $err = $new->get_error_message(); }
                else { if ($att && $att !== $new) wp_delete_attachment($att, true); $att = $new; }
            }
            $credit = (string) (($a['portrait']['credit'] ?? '') ?: ($a['credit'] ?? ''));
            update_post_meta($mid, TIX_Artists::META, ['kind' => (string) ($a['kind'] ?? 'solo'), 'short_bio' => (string) ($a['short_bio'] ?? ''), 'links' => $links,
                'images' => $att ? ['portrait' => ['id' => $att, 'credit' => $credit]] : [], 'ids' => ['spotify' => '', 'apple' => ''], 'tours' => [], 'status' => 'public', 'verified' => false,
                'managed_by' => $org ? ['type' => 'organizer', 'id' => $org] : ['type' => 'site', 'id' => 0]]);
            update_post_meta($mid, TIX_Artists::META_NORM, TIX_Artists::norm($name));
            update_post_meta($mid, self::META, ['host' => $src['host'], 'site' => $src['site'], 'source_id' => intval($a['source_id'] ?? 0) ?: null,
                'url' => ($a['url'] ?? null) ?: null, 'portrait' => $a['portrait'] ?? null, 'cover' => $a['cover'] ?? null, 'image' => ($a['image'] ?? null) ?: null, 'credit' => $credit,
                'img_src' => $src_url !== '' && !$err ? $src_url : (string) ($prev['img_src'] ?? ''), 'attachment' => $att ?: null, 'img_error' => $err, 'at' => gmdate('c')]);
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
        $p = $one($m['portrait'] ?? null); $c = $one($m['cover'] ?? null);
        if (!$p && !$c && !empty($m['image'])) $p = $one(['medium' => (string) $m['image'], 'large' => (string) $m['image'], 'credit' => (string) ($m['credit'] ?? '')]); // Act-Foto der Quelle
        return ['portrait' => $p, 'cover' => $c];
    }

    /**
     * Bild der Quelle in die Mediathek laden (Größen für Karte/Rundbild entstehen dabei), nur über IPv4 (Kunden-Server mit IPv6 ohne
     * Route scheitern sonst), höchstens 8 MB, nur Bilder. → Anhang-ID | WP_Error
     */
    public static function sideload($url, $parent, $title = '') {
        $url = esc_url_raw((string) $url, ['https', 'http']);
        if ($url === '') return new WP_Error('tix_mirror_img', 'Keine Bildadresse.');
        $test = apply_filters('tix_shared_objects_sideload', null, $url, $parent); // Tests
        if ($test !== null) return $test;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $v4 = function ($h) { if (defined('CURLOPT_IPRESOLVE')) curl_setopt($h, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); };
        add_action('http_api_curl', $v4);
        $tmp = download_url($url, 20);
        remove_action('http_api_curl', $v4);
        if (is_wp_error($tmp)) return new WP_Error('tix_mirror_img', 'Download fehlgeschlagen: ' . $tmp->get_error_message());
        $info = @getimagesize($tmp);
        if (!is_array($info) || !in_array((string) $info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || filesize($tmp) > 8 * MB_IN_BYTES) { @unlink($tmp); return new WP_Error('tix_mirror_img', 'Kein gültiges Bild.'); }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime']];
        $aid = media_handle_sideload(['name' => sanitize_file_name(($title ?: 'kuenstler') . '-' . substr(md5($url), 0, 8) . '.' . $ext), 'tmp_name' => $tmp], 0, $title ?: null);
        if (is_wp_error($aid)) { @unlink($tmp); return new WP_Error('tix_mirror_img', 'Bild konnte nicht gespeichert werden: ' . $aid->get_error_message()); }
        update_post_meta($aid, '_tix_mirror_of', intval($parent));
        return intval($aid);
    }

    /** Bild-Adresse eines Schnappschuss-Eintrags: Porträt, sonst Titelbild, sonst Act-Foto */
    private static function snap_image_url(array $a) {
        return (string) (($a['portrait']['large'] ?? '') ?: ($a['portrait']['medium'] ?? '') ?: ($a['images']['portrait']['large'] ?? '') ?: ($a['cover']['large'] ?? '') ?: ($a['cover']['medium'] ?? '') ?: ($a['images']['cover']['large'] ?? '') ?: ($a['image'] ?? ''));
    }

    /** Hat der (eigene) Künstler ein gültiges eigenes Bild? Kopierte Bild-IDs umgezogener Künstler zählen nicht. */
    public static function own_has_image($aid) {
        $d = get_post_meta(intval($aid), TIX_Artists::META, true);
        foreach (['portrait', 'cover'] as $slot) {
            $att = intval(is_array($d) ? ($d['images'][$slot]['id'] ?? 0) : 0);
            if (!$att) continue;
            $ok = class_exists('TIX_Site_Move') && method_exists('TIX_Site_Move', 'artist_image_ok') ? TIX_Site_Move::artist_image_ok($aid, $att)
                : (get_post_type($att) === 'attachment' && strpos((string) get_post_mime_type($att), 'image/') === 0);
            if ($ok) return true;
        }
        return false;
    }

    /**
     * Eigener Künstler an einer Kopie (1.38.743): hat er kein Bild, übernimmt er das Bild der Quelle (in die Mediathek, als Porträt).
     * Eigene Bilder bleiben unangetastet; ein übernommenes Bild wird nachgezogen, wenn sich die Quelle ändert. → true wenn ein Bild gesetzt wurde
     */
    private static function adopt_image($aid, array $a, $name) {
        $url = self::snap_image_url($a);
        if ($url === '') return false;
        $d = get_post_meta($aid, TIX_Artists::META, true);
        $d = is_array($d) ? $d : [];
        $mine = intval($d['images']['portrait']['id'] ?? 0);
        $adopted = $mine && intval(get_post_meta($mine, '_tix_adopted_for', true)) === intval($aid);
        if (!$adopted && self::own_has_image($aid)) return false; // eigenes Bild hat Vorrang
        if ($adopted && (string) get_post_meta($aid, '_tix_adopted_src', true) === $url && get_post($mine)) return false; // schon aktuell
        $att = self::sideload($url, $aid, $name);
        if (is_wp_error($att)) { update_post_meta($aid, '_tix_adopted_error', $att->get_error_message()); return false; }
        delete_post_meta($att, '_tix_mirror_of');
        update_post_meta($att, '_tix_adopted_for', intval($aid));
        update_post_meta($att, '_tix_artist_img_of', intval($aid)); // gilt auch bei umgezogenen Künstlern als eigenes Bild
        if ($adopted && $mine !== $att) wp_delete_attachment($mine, true);
        $d['images']['portrait'] = ['id' => intval($att), 'credit' => (string) (($a['portrait']['credit'] ?? '') ?: ($a['credit'] ?? ''))];
        unset($d['images']['cover']); // kopierte/ungültige Titelbild-ID nicht stehen lassen
        update_post_meta($aid, TIX_Artists::META, $d);
        update_post_meta($aid, '_tix_adopted_src', $url);
        delete_post_meta($aid, '_tix_adopted_error');
        return true;
    }

    /** Stand aller öffentlichen Künstler: Herkunft (eigen/umgezogen/Spiegel) und Bild (eigen/übernommen/Quelle/fremd/–) */
    public static function artist_report() {
        $items = []; $without = 0;
        foreach (get_posts(['post_type' => TIX_Artists::CPT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'orderby' => 'title', 'order' => 'ASC']) as $aid) {
            $m = self::mirror($aid);
            $moved = intval(get_post_meta($aid, '_tix_moved_from', true)) > 0;
            $d = get_post_meta($aid, TIX_Artists::META, true);
            $att = intval(is_array($d) ? (($d['images']['portrait']['id'] ?? 0) ?: ($d['images']['cover']['id'] ?? 0)) : 0);
            if ($m) $img = !empty($m['attachment']) && get_post($m['attachment']) ? 'Mediathek #' . $m['attachment'] : (self::snap_image_url($m) !== '' ? 'Quelle (' . ($m['img_error'] ?: 'nicht geladen') . ')' : '–');
            elseif (self::own_has_image($aid)) $img = intval(get_post_meta($att, '_tix_adopted_for', true)) === intval($aid) ? 'übernommen #' . $att : 'eigen #' . $att;
            else $img = $att ? 'fremd/ungültig #' . $att : ('–' . (($e = get_post_meta($aid, '_tix_adopted_error', true)) ? ' (' . $e . ')' : ''));
            $has = $m ? $img !== '–' : self::own_has_image($aid);
            if (!$has) $without++;
            $items[] = ['id' => intval($aid), 'name' => html_entity_decode(get_the_title($aid), ENT_QUOTES, 'UTF-8'), 'type' => $m ? 'Spiegel' : ($moved ? 'umgezogen' : 'eigen'), 'image' => $img, 'has_image' => $has];
        }
        return ['total' => count($items), 'without_image' => $without, 'items' => $items];
    }

    /**
     * Reparatur (1.38.740, vollständig seit 1.38.743): erst die Bilder umgezogener Künstler (Plattform), dann alle Kopien mit
     * Künstler-Schnappschuss neu spiegeln – ohne Obergrenze, fehlgeschlagene Bilder werden erneut geladen, eigene Künstler ohne Bild
     * übernehmen das Bild der Quelle. → Anzahl Events | mit $report: {events, moved, before, after}
     */
    public static function repair($report = false) {
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $before = $report && class_exists('TIX_Artists') ? self::artist_report() : null;
        $moved = class_exists('TIX_Site_Move') && method_exists('TIX_Site_Move', 'repair_artist_images') ? TIX_Site_Move::repair_artist_images() : null;
        $n = 0; $page = 1;
        do {
            $ids = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'future', 'private', 'pending'], 'posts_per_page' => 200, 'paged' => $page++, 'fields' => 'ids', 'suppress_filters' => true,
                'orderby' => 'ID', 'order' => 'ASC', 'meta_query' => [['key' => '_tix_source_artists', 'compare' => 'EXISTS']]]);
            foreach ($ids as $eid) {
                $snap = get_post_meta($eid, '_tix_source_artists', true);
                if (is_array($snap) && $snap && self::source_of($eid)) { self::sync_artists($eid, $snap); $n++; }
            }
        } while (count($ids) === 200);
        if (!$report) return $n;
        unset($before['items']);
        $after = self::artist_report();
        unset($after['items']);
        return ['events' => $n, 'moved' => $moved, 'before' => $before, 'after' => $after];
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
            foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 20, 'fields' => 'ids', 'meta_key' => '_tix_mirror_of', 'meta_value' => (string) $id]) as $att) wp_delete_attachment($att, true);
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
