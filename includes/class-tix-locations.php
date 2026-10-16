<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Locations – Orte je Veranstalter und kanonische Orte für die öffentliche Darstellung (1.38.475).
 *
 * Mehr-Veranstalter-Modus (evendis):
 *   - Veranstalter und Team sehen und nutzen nur eigene Orte (`_tix_loc_organizer_id`, sonst Autor), Admins alle.
 *     Einzel-Seiten (KitchenKlub, Mallorca) bleiben unverändert: alle sehen alle.
 *   - Selbstheilung (einmalig, `tix_heal_loc_owner_475`, Protokoll `tix_loc_heal_log`): Orte ohne Veranstalter bekommen den Veranstalter, der sie in
 *     Events nutzt. Nutzen mehrere Veranstalter denselben Ort, bekommt jeder weitere eine eigene Kopie (Events werden
 *     umgehängt, die Kopie zeigt öffentlich auf das Original). Ungenutzte Orte bleiben bei der Plattform.
 *   - Kanonischer Ort (`_tix_venue_canonical`, 0/leer = selbst): öffentlich zählt für Gäste ein Ort, auch wenn mehrere
 *     Veranstalter ihn angelegt haben. Automatisch zusammengeführt wird bei gleicher `place_id` oder bei Koordinaten
 *     unter 50 m bzw. gleicher normalisierter Adresse UND ähnlichem Namen. Grenzfälle (nur Name oder nur Adresse
 *     bzw. Nähe) landen als Vorschlag in WP-Admin „Tixomat → Orte zusammenführen“. Ähnlichkeit per Trigrammen,
 *     Grenzwert `tix_venue_merge_threshold` (Vorgabe 0.6).
 *   - WP-CLI: `wp tixomat venues-merge [--dry-run]` (alle Orte einmal prüfen, Liste ausgeben),
 *     `wp tixomat locations-assign [--dry-run]` (Zuordnung wie die Selbstheilung).
 */
class TIX_Locations {

    const META_ORG   = '_tix_loc_organizer_id';
    const META_CANON = '_tix_venue_canonical';
    const META_PLACE = '_tix_loc_place_id';
    const OPT_SUGG   = 'tix_venue_merge_suggestions';
    const OPT_IGN    = 'tix_venue_merge_ignored';
    const OPT_THRESH = 'tix_venue_merge_threshold';
    const HEAL_OPT   = 'tix_heal_loc_owner_475';
    const NEAR_M     = 50;

    /** Während Kopien angelegt werden: keine Prüfung beim Speichern. */
    private static $suspend = false;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_heal'], 25);
        add_action('save_post_tix_location', [__CLASS__, 'on_save'], 50, 2);
        add_action('admin_menu', [__CLASS__, 'menu'], 32);
        add_action('admin_post_tix_venue_merge', [__CLASS__, 'handle_admin']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('tixomat venues-merge', [__CLASS__, 'cli_merge']);
            WP_CLI::add_command('tixomat locations-assign', [__CLASS__, 'cli_assign']);
        }
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Eigene Orte ═══════════════

    /** Veranstalter des Orts (0 = Plattform). */
    public static function owner($lid) {
        return intval(get_post_meta($lid, self::META_ORG, true));
    }

    /** Darf der aktuelle Nutzer den Ort sehen/nutzen? Einzel-Seiten und Admins: immer. */
    public static function is_own($lid, $user_id = 0) {
        if (!self::multi()) return true;
        $user_id = $user_id ?: get_current_user_id();
        if (user_can($user_id, 'manage_options')) return true;
        $oid = class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user($user_id)) : 0;
        $owner = self::owner($lid);
        if ($oid && $owner === $oid) return true;
        return !$owner && intval(get_post_field('post_author', $lid)) === intval($user_id);
    }

    /** Sichtbare Orte des aktuellen Nutzers (IDs), Reihenfolge nach Titel. */
    public static function visible_ids($limit = 500) {
        $ids = get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true]);
        if (!self::multi() || current_user_can('manage_options')) return array_map('intval', $ids);
        return array_values(array_filter(array_map('intval', $ids), function ($lid) { return self::is_own($lid); }));
    }

    /** Zuletzt genutzt: Datum des jüngsten Events (angelegt/geändert) an diesem Ort, ISO oder null. */
    public static function last_used($lid) {
        global $wpdb;
        $d = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(p.post_modified) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tix_location_id' AND pm.meta_value = %s AND p.post_type = 'event' AND p.post_status NOT IN ('trash', 'auto-draft')",
            (string) intval($lid)
        ));
        return $d ? mysql2date('c', $d, false) : null;
    }

    /** Eigener Ort mit dieser place_id (für „schon vorhanden“). */
    public static function find_own_by_place($place_id, $org_id) {
        if ($place_id === '') return 0;
        $q = get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => self::META_PLACE, 'value' => $place_id]]]);
        foreach ($q as $lid) {
            if (!self::multi() || current_user_can('manage_options') && !$org_id) return intval($lid);
            if (self::owner($lid) === intval($org_id) || (!self::owner($lid) && intval(get_post_field('post_author', $lid)) === get_current_user_id())) return intval($lid);
        }
        return 0;
    }

    // ═══════════════ Selbstheilung: Zuordnung ═══════════════

    public static function maybe_heal() {
        if (!self::multi() || get_option(self::HEAL_OPT) === '1') return;
        if (get_transient('tix_loc_heal_lock')) return;
        set_transient('tix_loc_heal_lock', 1, 5 * MINUTE_IN_SECONDS);
        // Schritte merken (für die Übergabe: wp option get tix_loc_heal_log --format=json)
        update_option('tix_loc_heal_log', ['at' => current_time('mysql'), 'steps' => self::assign_owners(false)], false);
        update_option(self::HEAL_OPT, '1', false);
        delete_transient('tix_loc_heal_lock');
    }

    /** Events je Ort und Veranstalter: [lid => [oid => [event_ids]]]. */
    private static function usage() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT pm.post_id AS eid, pm.meta_value AS lid, COALESCE(o.meta_value, '0') AS oid
            FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} o ON o.post_id = pm.post_id AND o.meta_key = '_tix_organizer_id'
            WHERE pm.meta_key = '_tix_location_id' AND pm.meta_value <> '' AND pm.meta_value <> '0' AND p.post_type = 'event' AND p.post_status NOT IN ('trash', 'auto-draft')");
        $u = [];
        foreach ((array) $rows as $r) $u[intval($r->lid)][intval($r->oid)][] = intval($r->eid);
        return $u;
    }

    /**
     * Orte den nutzenden Veranstaltern zuordnen. Rückgabe: Liste der Schritte (für CLI/Übergabe).
     * Ohne Veranstalter: der mit den meisten Events wird Eigentümer; jeder weitere Veranstalter bekommt eine Kopie.
     */
    public static function assign_owners($dry = false) {
        $log = [];
        foreach (self::usage() as $lid => $by_org) {
            if (get_post_type($lid) !== 'tix_location') continue;
            unset($by_org[0]); // Events ohne Veranstalter (Plattform) bleiben am Ort
            if (!$by_org) continue;
            $owner = self::owner($lid);
            if (!$owner) {
                uasort($by_org, function ($a, $b) { return count($b) <=> count($a); });
                $owner = intval(array_key_first($by_org));
                $log[] = ['action' => 'assign', 'location' => $lid, 'title' => get_the_title($lid), 'organizer' => $owner];
                if (!$dry) update_post_meta($lid, self::META_ORG, $owner);
            }
            foreach ($by_org as $oid => $events) {
                if (intval($oid) === $owner) continue;
                $copy = $dry ? 0 : self::copy_for($lid, intval($oid));
                $log[] = ['action' => 'copy', 'location' => $lid, 'title' => get_the_title($lid), 'organizer' => intval($oid), 'copy' => $copy, 'events' => $events];
                if (!$dry && $copy) foreach ($events as $eid) update_post_meta($eid, '_tix_location_id', $copy);
            }
        }
        return $log;
    }

    /** Eigene Kopie eines Orts für einen Veranstalter; öffentlich zeigt sie auf das Original. */
    public static function copy_for($lid, $oid) {
        $src = get_post($lid);
        if (!$src) return 0;
        self::$suspend = true;
        $new = wp_insert_post(['post_type' => 'tix_location', 'post_status' => 'publish', 'post_title' => $src->post_title, 'post_content' => $src->post_content, 'post_excerpt' => $src->post_excerpt], true);
        self::$suspend = false;
        if (is_wp_error($new) || !$new) return 0;
        foreach (get_post_meta($lid) as $k => $vals) {
            if (in_array($k, [self::META_ORG, self::META_CANON, '_edit_lock', '_edit_last', '_tix_loc_listed'], true) || strpos($k, '_wp_') === 0) continue;
            foreach ($vals as $v) add_post_meta($new, $k, maybe_unserialize($v));
        }
        $thumb = get_post_thumbnail_id($lid);
        if ($thumb) set_post_thumbnail($new, $thumb);
        update_post_meta($new, self::META_ORG, $oid);
        update_post_meta($new, self::META_CANON, self::canonical_id($lid));
        return intval($new);
    }

    // ═══════════════ Kanonischer Ort ═══════════════

    public static function canonical_id($lid) {
        $lid = intval($lid);
        for ($i = 0, $cur = $lid; $i < 6; $i++) {
            $next = intval(get_post_meta($cur, self::META_CANON, true));
            if (!$next || $next === $cur || get_post_type($next) !== 'tix_location' || get_post_status($next) !== 'publish') return $cur;
            $cur = $next;
        }
        return $cur;
    }

    /** Alle Orte einer Gruppe (kanonisch + zusammengeführte). */
    public static function group($cid) {
        $cid = intval($cid);
        $ids = get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => self::META_CANON, 'value' => '', 'compare' => '!=']]]);
        $out = [$cid];
        foreach ($ids as $lid) if (intval($lid) !== $cid && self::canonical_id($lid) === $cid) $out[] = intval($lid);
        return $out;
    }

    // ═══════════════ Vergleich ═══════════════

    public static function norm($s) {
        $s = mb_strtolower(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a', 'ó' => 'o', 'ñ' => 'n', '&' => ' und ']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Straße + Hausnummer (ohne Zusatz) + PLZ, normalisiert. */
    public static function norm_address($street, $zip = '') {
        $a = self::norm($street);
        $a = preg_replace('/\b(strasse|str)\b/', 'str', $a);
        $a = preg_replace('/(\w)(strasse|str)\b/', '$1 str', $a);
        $a = preg_replace('/\b(\d+)\s*[a-z]\b/', '$1', $a); // 12a → 12
        $a = preg_replace('/\b(\d+)\s*-\s*\d+\b/', '$1', $a); // 12-14 → 12
        $zip = preg_replace('/\D/', '', (string) $zip);
        return trim($a . ($zip !== '' ? ' ' . $zip : ''));
    }

    private static function trigrams($s) {
        $s = '  ' . $s . ' ';
        $t = [];
        for ($i = 0, $n = strlen($s) - 2; $i < $n; $i++) $t[substr($s, $i, 3)] = true;
        return $t;
    }

    /** Namensähnlichkeit 0..1 (Trigramme; enthält ein Name den anderen → mindestens 0.9). */
    public static function name_similarity($a, $b) {
        $stop = '/\b(club|bar|the|die|der|das|im|am|in|cafe|halle|location|eventlocation|gmbh|e v|ev)\b/';
        $a = trim(preg_replace('/\s+/', ' ', preg_replace($stop, ' ', self::norm($a))));
        $b = trim(preg_replace('/\s+/', ' ', preg_replace($stop, ' ', self::norm($b))));
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $ta = self::trigrams($a); $tb = self::trigrams($b);
        $inter = count(array_intersect_key($ta, $tb));
        $j = $inter / max(1, count($ta) + count($tb) - $inter);
        if ((strlen($a) >= 4 && strpos($b, $a) !== false) || (strlen($b) >= 4 && strpos($a, $b) !== false)) $j = max($j, 0.9);
        return round($j, 3);
    }

    public static function distance_m($lat1, $lng1, $lat2, $lng2) {
        $r = 6371000; $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
        $dp = $p2 - $p1; $dl = deg2rad($lng2 - $lng1);
        $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($h)));
    }

    /** Vergleichsdaten eines Orts. */
    public static function info($lid) {
        $m = function ($k) use ($lid) { return (string) get_post_meta($lid, $k, true); };
        $lat = $m('_tix_loc_lat'); $lng = $m('_tix_loc_lng');
        $has = is_numeric($lat) && is_numeric($lng) && floatval($lat) != 0;
        return ['id' => intval($lid), 'name' => html_entity_decode(get_the_title($lid), ENT_QUOTES, 'UTF-8'), 'place_id' => $m(self::META_PLACE),
            'lat' => $has ? floatval($lat) : null, 'lng' => $has ? floatval($lng) : null,
            'addr' => self::norm_address($m('_tix_loc_address'), $m('_tix_loc_zip') ?: $m('_tix_loc_postcode')), 'city' => self::norm($m('_tix_loc_city')),
            'owner' => self::owner($lid)];
    }

    /** Vergleich zweier Orte → ['auto' => bool, 'suggest' => bool, 'reason' => string, 'score' => float]. */
    public static function compare(array $a, array $b) {
        $thr = floatval(get_option(self::OPT_THRESH, 0.6)) ?: 0.6;
        $name = self::name_similarity($a['name'], $b['name']);
        $out = ['auto' => false, 'suggest' => false, 'reason' => '', 'score' => $name];
        if ($a['place_id'] !== '' && $a['place_id'] === $b['place_id']) return ['auto' => true, 'suggest' => false, 'reason' => 'gleiche place_id', 'score' => 1.0];
        $dist = ($a['lat'] !== null && $b['lat'] !== null) ? self::distance_m($a['lat'], $a['lng'], $b['lat'], $b['lng']) : null;
        $same_addr = $a['addr'] !== '' && $a['addr'] === $b['addr'] && preg_match('/\d/', $a['addr']);
        $near = $dist !== null && $dist < self::NEAR_M;
        if (($near || $same_addr) && $name >= $thr) return ['auto' => true, 'suggest' => false, 'reason' => ($near ? 'unter ' . self::NEAR_M . ' m' : 'gleiche Adresse') . ' + ähnlicher Name (' . $name . ')', 'score' => $name];
        if ($near || $same_addr) return ['auto' => false, 'suggest' => true, 'reason' => ($near ? 'unter ' . self::NEAR_M . ' m (' . round($dist) . ' m)' : 'gleiche Adresse') . ', Name verschieden (' . $name . ')', 'score' => $name];
        if ($dist !== null && $dist < 150 && $name >= $thr) return ['auto' => false, 'suggest' => true, 'reason' => 'ähnlicher Name, ' . round($dist) . ' m entfernt', 'score' => $name];
        if ($name >= 0.8 && ($dist === null || $dist < 2000) && ($a['city'] === '' || $b['city'] === '' || $a['city'] === $b['city'])) return ['auto' => false, 'suggest' => true, 'reason' => 'sehr ähnlicher Name (' . $name . ')' . ($dist !== null ? ', ' . round($dist) . ' m' : ''), 'score' => $name];
        return $out;
    }

    private static function pair_key($a, $b) {
        return min($a, $b) . '-' . max($a, $b);
    }

    /** Wer bleibt kanonisch? Schon Ziel anderer › Plattform-Ort › mit place_id › älterer. */
    private static function winner(array $a, array $b) {
        $ga = count(self::group($a['id'])) > 1; $gb = count(self::group($b['id'])) > 1;
        if ($ga !== $gb) return $ga ? $a['id'] : $b['id'];
        if (!$a['owner'] !== !$b['owner']) return !$a['owner'] ? $a['id'] : $b['id'];
        if (($a['place_id'] !== '') !== ($b['place_id'] !== '')) return $a['place_id'] !== '' ? $a['id'] : $b['id'];
        return min($a['id'], $b['id']);
    }

    /** Zwei Orte zusammenführen (öffentlich ein Ort). */
    public static function merge($a, $b) {
        $ca = self::canonical_id($a); $cb = self::canonical_id($b);
        if ($ca === $cb) return $ca;
        $win = self::winner(self::info($ca), self::info($cb));
        $lose = $win === $ca ? $cb : $ca;
        foreach (self::group($lose) as $lid) update_post_meta($lid, self::META_CANON, $win);
        update_post_meta($lose, self::META_CANON, $win);
        delete_post_meta($win, self::META_CANON);
        $s = get_option(self::OPT_SUGG, []);
        if (is_array($s)) { unset($s[self::pair_key($a, $b)]); update_option(self::OPT_SUGG, $s, false); }
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return $win;
    }

    /** Aus einer Gruppe lösen (öffentlich wieder eigener Ort). */
    public static function unmerge($lid) {
        delete_post_meta($lid, self::META_CANON);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
    }

    /** Einen Ort gegen alle anderen prüfen: zusammenführen bzw. Vorschlag. Rückgabe [merged => [...], suggested => [...]]. */
    public static function check($lid, $dry = false, array $all = null) {
        $res = ['merged' => [], 'suggested' => []];
        if (get_post_type($lid) !== 'tix_location' || get_post_status($lid) !== 'publish') return $res;
        $me = self::info($lid);
        $ign = (array) get_option(self::OPT_IGN, []);
        $sugg = get_option(self::OPT_SUGG, []);
        $sugg = is_array($sugg) ? $sugg : [];
        $all = $all ?? get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true]);
        foreach ($all as $other) {
            $other = intval($other);
            if ($other === intval($lid)) continue;
            $key = self::pair_key($lid, $other);
            if (in_array($key, $ign, true)) continue;
            if (!$dry && self::canonical_id($lid) === self::canonical_id($other)) continue;
            $o = self::info($other);
            $c = self::compare($me, $o);
            if ($c['auto']) {
                $res['merged'][] = ['a' => intval($lid), 'b' => $other, 'a_title' => $me['name'], 'b_title' => $o['name'], 'reason' => $c['reason']];
                if (!$dry) self::merge($lid, $other);
            } elseif ($c['suggest']) {
                $res['suggested'][] = ['a' => intval($lid), 'b' => $other, 'a_title' => $me['name'], 'b_title' => $o['name'], 'reason' => $c['reason']];
                if (!$dry && !isset($sugg[$key])) $sugg[$key] = ['a' => min($lid, $other), 'b' => max($lid, $other), 'reason' => $c['reason'], 'score' => $c['score'], 'at' => current_time('mysql')];
            }
        }
        if (!$dry) update_option(self::OPT_SUGG, $sugg, false);
        return $res;
    }

    /** Alle Orte einmal prüfen (jede Paarung einmal). */
    public static function check_all($dry = false) {
        $all = array_map('intval', get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC']));
        $res = ['merged' => [], 'suggested' => []];
        foreach ($all as $i => $lid) {
            $r = self::check($lid, $dry, array_slice($all, $i + 1));
            $res['merged'] = array_merge($res['merged'], $r['merged']);
            $res['suggested'] = array_merge($res['suggested'], $r['suggested']);
        }
        return $res;
    }

    public static function on_save($post_id, $post) {
        if (self::$suspend || !self::multi() || wp_is_post_revision($post_id) || $post->post_status !== 'publish') return;
        static $busy = false;
        if ($busy) return;
        $busy = true;
        self::check($post_id);
        $busy = false;
    }

    // ═══════════════ Admin „Orte zusammenführen?“ ═══════════════

    public static function menu() {
        if (!self::multi()) return;
        $n = count((array) get_option(self::OPT_SUGG, []));
        add_submenu_page('tixomat', 'Orte zusammenführen', 'Orte zusammenführen' . ($n ? ' <span class="awaiting-mod">' . $n . '</span>' : ''), 'manage_options', 'tix-venue-merge', [__CLASS__, 'render']);
    }

    public static function handle_admin() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_venue_merge');
        $do = sanitize_key($_POST['do'] ?? '');
        $a = absint($_POST['a'] ?? 0); $b = absint($_POST['b'] ?? 0);
        $msg = '';
        if ($do === 'merge' && $a && $b) { self::merge($a, $b); $msg = 'Zusammengeführt.'; }
        elseif ($do === 'ignore' && $a && $b) {
            $ign = (array) get_option(self::OPT_IGN, []); $ign[] = self::pair_key($a, $b); update_option(self::OPT_IGN, array_values(array_unique($ign)), false);
            $s = (array) get_option(self::OPT_SUGG, []); unset($s[self::pair_key($a, $b)]); update_option(self::OPT_SUGG, $s, false);
            $msg = 'Als verschieden markiert.';
        } elseif ($do === 'unmerge' && $a) { self::unmerge($a); $msg = 'Getrennt.'; }
        elseif ($do === 'scan') { $r = self::check_all(false); $msg = count($r['merged']) . ' zusammengeführt, ' . count($r['suggested']) . ' Vorschläge.'; }
        wp_safe_redirect(add_query_arg(['page' => 'tix-venue-merge', 'msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    private static function label($lid) {
        $i = self::info($lid);
        $org = $i['owner'] ? get_the_title($i['owner']) : 'Plattform';
        return '<strong>' . esc_html($i['name']) . '</strong> <span style="color:#6b7280;">#' . intval($lid) . ' · ' . esc_html($org) . ' · ' . esc_html((string) get_post_meta($lid, '_tix_loc_address', true) . ' ' . (string) get_post_meta($lid, '_tix_loc_city', true)) . '</span>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $s = (array) get_option(self::OPT_SUGG, []);
        $form = function ($do, $a, $b, $label, $cls = 'button') {
            return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">' . wp_nonce_field('tix_venue_merge', '_wpnonce', true, false)
                . '<input type="hidden" name="action" value="tix_venue_merge"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="a" value="' . intval($a) . '"><input type="hidden" name="b" value="' . intval($b) . '">'
                . '<button class="' . esc_attr($cls) . '">' . esc_html($label) . '</button></form>';
        };
        $groups = [];
        foreach (get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['key' => self::META_CANON, 'value' => '', 'compare' => '!=']]]) as $lid) {
            $c = self::canonical_id($lid);
            if ($c !== intval($lid)) $groups[$c][] = intval($lid);
        }
        echo '<div class="wrap"><h1>Orte zusammenführen</h1>';
        if (!empty($_GET['msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(wp_unslash((string) $_GET['msg'])) . '</p></div>';
        echo '<p>Öffentlich (evendis.de) gilt je Gruppe ein Ort mit allen Events aller Veranstalter. Veranstalter behalten im Studio ihren eigenen Ort. Gleiche place_id bzw. nahe/gleiche Adresse mit ähnlichem Namen werden automatisch zusammengeführt; hier stehen die Grenzfälle.</p>';
        echo '<p>' . $form('scan', 0, 0, 'Alle Orte jetzt prüfen') . '</p>';
        echo '<h2>Vorschläge (' . count($s) . ')</h2><table class="widefat striped"><thead><tr><th>Ort A</th><th>Ort B</th><th>Grund</th><th></th></tr></thead><tbody>';
        foreach ($s as $row) {
            if (get_post_type($row['a']) !== 'tix_location' || get_post_type($row['b']) !== 'tix_location') continue;
            echo '<tr><td>' . self::label($row['a']) . '</td><td>' . self::label($row['b']) . '</td><td>' . esc_html($row['reason']) . '</td><td>' . $form('merge', $row['a'], $row['b'], 'Zusammenführen', 'button button-primary') . ' ' . $form('ignore', $row['a'], $row['b'], 'Nicht dasselbe') . '</td></tr>';
        }
        if (!$s) echo '<tr><td colspan="4">Keine offenen Vorschläge.</td></tr>';
        echo '</tbody></table><h2>Zusammengeführt (' . count($groups) . ')</h2><table class="widefat striped"><thead><tr><th>Öffentlicher Ort</th><th>Zusammengeführt</th></tr></thead><tbody>';
        foreach ($groups as $c => $members) {
            echo '<tr><td>' . self::label($c) . '</td><td>';
            foreach ($members as $m) echo '<div>' . self::label($m) . ' ' . $form('unmerge', $m, 0, 'Trennen', 'button-link') . '</div>';
            echo '</td></tr>';
        }
        if (!$groups) echo '<tr><td colspan="2">Noch keine.</td></tr>';
        echo '</tbody></table></div>';
    }

    // ═══════════════ WP-CLI ═══════════════

    /**
     * Alle Orte einmal prüfen. [--dry-run] zeigt nur, was passieren würde.
     */
    public static function cli_merge($args, $assoc) {
        $dry = !empty($assoc['dry-run']);
        $r = self::check_all($dry);
        WP_CLI::log(($dry ? '[Probelauf] ' : '') . 'Automatisch zusammengeführt: ' . count($r['merged']));
        foreach ($r['merged'] as $m) WP_CLI::log(sprintf('  #%d %s  ⇄  #%d %s  (%s)', $m['a'], $m['a_title'], $m['b'], $m['b_title'], $m['reason']));
        WP_CLI::log('Vorschläge (Tixomat → Orte zusammenführen): ' . count($r['suggested']));
        foreach ($r['suggested'] as $m) WP_CLI::log(sprintf('  #%d %s  ?  #%d %s  (%s)', $m['a'], $m['a_title'], $m['b'], $m['b_title'], $m['reason']));
    }

    /**
     * Orte den nutzenden Veranstaltern zuordnen. [--dry-run]
     */
    public static function cli_assign($args, $assoc) {
        $dry = !empty($assoc['dry-run']);
        $log = self::assign_owners($dry);
        WP_CLI::log(($dry ? '[Probelauf] ' : '') . count($log) . ' Schritte');
        foreach ($log as $l) WP_CLI::log($l['action'] === 'assign'
            ? sprintf('  Zuordnen: #%d %s → Veranstalter #%d', $l['location'], $l['title'], $l['organizer'])
            : sprintf('  Kopie: #%d %s für Veranstalter #%d (%d Events)%s', $l['location'], $l['title'], $l['organizer'], count($l['events']), $l['copy'] ? ' → #' . $l['copy'] : ''));
        if (!$dry) update_option(self::HEAL_OPT, '1', false);
    }
}
