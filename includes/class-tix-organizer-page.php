<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Organizer_Page – Bausteine der Veranstalter-Seite auf evendis.de (Vertrag evendis-web, Bausteine 1–9).
 * Nur Mehr-Veranstalter-Modus; alles rein hinzufügend.
 *
 * GET /public/organizers/{id|slug} → organizer zusätzlich:
 *   facts {music, age, dresscode, opening, capacity}  (leere Werte fehlen; capacity Zahl)
 *   faq [{question, answer}]                          (answer einfaches HTML)
 *   followers <int>, since "JJJJ-MM-TT"|"", events_held <int>
 *   gallery [{url, thumb, event_id, event_title, date}] (vergangene Events, neueste zuerst, max. 24)
 *   venue {name, address, city, lat, lng, transit, parking} | null   (Hauptort bzw. Adresse des Veranstalters)
 *   schema_type "" | NightClub | EventVenue | MusicVenue | BarOrPub | Organization | PerformingGroup
 * GET /public/events → je Event series_id (Serie bzw. Wiederholungs-Elter, 0 = keine Serie)
 * Design der Seite (1.38.417): page_design galerie (Standard) | clubnacht | profil | ticketschalter | bento – in
 *   /public/organizers (Liste + Detail, immer gesetzt) und /organizer/profile (GET/POST, sonst 400 tix_invalid_design);
 *   Auswahl als Kacheln im WP-Admin (Veranstalter) und auf „Meine Landingpage“. Meta _tix_org_page_design.
 * Pflege: /organizer/profile (GET/POST: facts, faq, schema_type, transit, parking), WP-Admin (Veranstalter, Ort).
 * Meta: _tix_org_facts, _tix_org_faq [{q, a}] (Format wie _tix_faq), _tix_org_schema_type, _tix_org_transit,
 *       _tix_org_parking, am Ort _tix_loc_transit, _tix_loc_parking.
 */
class TIX_Organizer_Page {

    const FACT_KEYS    = ['music', 'age', 'dresscode', 'opening', 'capacity'];
    const SCHEMA_TYPES = ['NightClub', 'EventVenue', 'MusicVenue', 'BarOrPub', 'Organization', 'PerformingGroup'];
    const GALLERY_MAX  = 24;
    const FAQ_MAX      = 20;
    const DESIGN_DEFAULT = 'galerie';
    const DESIGNS = [
        'galerie'        => ['Galerie', 'hell und luftig, das nächste Event groß'],
        'clubnacht'      => ['Clubnacht', 'dunkel und filmisch, mit Countdown und Spielplan'],
        'profil'         => ['Profil', 'wie ein App-Profil, mit Filtern'],
        'ticketschalter' => ['Ticketschalter', 'Monatskalender, Events als Ticketabschnitte'],
        'bento'          => ['Bento', 'moderne Kacheln'],
    ];

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function init() {
        if (!self::multi()) return;
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_action('save_post_tix_organizer', [__CLASS__, 'save_organizer_admin'], 30, 2);
        add_action('save_post_tix_location', [__CLASS__, 'save_location_admin'], 30, 2);
    }

    // ── Lesen ──

    public static function facts($oid) {
        $raw = get_post_meta($oid, '_tix_org_facts', true);
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (self::FACT_KEYS as $k) {
            if (!isset($raw[$k])) continue;
            if ($k === 'capacity') { $n = intval($raw[$k]); if ($n > 0) $out[$k] = $n; continue; }
            $v = trim((string) $raw[$k]);
            if ($v !== '') $out[$k] = $v;
        }
        return $out;
    }

    public static function faq($oid) {
        $out = [];
        foreach ((array) get_post_meta($oid, '_tix_org_faq', true) as $f) {
            $q = trim((string) ($f['q'] ?? ''));
            if ($q === '') continue;
            $out[] = ['question' => $q, 'answer' => wp_kses_post(wpautop((string) ($f['a'] ?? '')))];
        }
        return $out;
    }

    /** Veröffentlichte Events des Veranstalters ohne Gutschein-Systemevents (IDs). */
    private static function event_ids($oid) {
        return array_map('intval', get_posts([
            'post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [
                ['key' => '_tix_organizer_id', 'value' => (string) intval($oid)],
                ['key' => '_tix_system_giftcard_event', 'compare' => 'NOT EXISTS'],
            ],
        ]));
    }

    private static function event_end($eid) {
        $end = (string) get_post_meta($eid, '_tix_date_end', true);
        return $end !== '' ? $end : (string) get_post_meta($eid, '_tix_date_start', true);
    }

    /** Seit wann auf der Plattform: frühestes von Veröffentlichung des Veranstalters und erstem Event. */
    public static function since($oid, $ids = null) {
        $ids = $ids ?? self::event_ids($oid);
        $dates = [];
        $p = get_post($oid);
        if ($p && $p->post_date && $p->post_date !== '0000-00-00 00:00:00') $dates[] = substr($p->post_date, 0, 10);
        foreach ($ids as $eid) {
            $d = (string) get_post_meta($eid, '_tix_date_start', true);
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) $dates[] = substr($d, 0, 10);
        }
        return $dates ? min($dates) : '';
    }

    /** Vergangene, nicht abgesagte Events (IDs, neueste zuerst). */
    private static function past_ids($ids) {
        $today = current_time('Y-m-d');
        $past = [];
        foreach ($ids as $eid) {
            $end = self::event_end($eid);
            if ($end === '' || substr($end, 0, 10) >= $today) continue;
            if (get_post_meta($eid, '_tix_status', true) === 'cancelled') continue;
            $past[$eid] = substr((string) get_post_meta($eid, '_tix_date_start', true), 0, 10);
        }
        arsort($past);
        return $past;
    }

    public static function gallery($past) {
        $out = [];
        if (!class_exists('TIX_Event_Extras')) return $out;
        foreach ($past as $eid => $date) {
            foreach (TIX_Event_Extras::gallery($eid) as $img) {
                $out[] = ['url' => $img['url'], 'thumb' => $img['thumb'], 'event_id' => intval($eid),
                    'event_title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => $date];
                if (count($out) >= self::GALLERY_MAX) return $out;
            }
        }
        return $out;
    }

    private static function coord($v) {
        return ($v === '' || $v === null || !is_numeric($v)) ? null : round(floatval($v), 6);
    }

    /** Hauptort: Ort mit den meisten Events des Veranstalters, sonst seine Adresse. */
    public static function venue($oid, $ids = null) {
        $ids = $ids ?? self::event_ids($oid);
        $count = [];
        foreach ($ids as $eid) {
            $loc = intval(get_post_meta($eid, '_tix_location_id', true));
            if ($loc) $count[$loc] = ($count[$loc] ?? 0) + 1;
        }
        arsort($count);
        $org_transit = trim((string) get_post_meta($oid, '_tix_org_transit', true));
        $org_parking = trim((string) get_post_meta($oid, '_tix_org_parking', true));
        foreach (array_keys($count) as $loc) {
            if (get_post_type($loc) !== 'tix_location' || get_post_status($loc) === 'trash') continue;
            $m = function ($k) use ($loc) { return trim((string) get_post_meta($loc, $k, true)); };
            return [
                'name'    => html_entity_decode(get_the_title($loc), ENT_QUOTES, 'UTF-8'),
                'address' => $m('_tix_loc_address'),
                'city'    => $m('_tix_loc_city'),
                'lat'     => self::coord(get_post_meta($loc, '_tix_loc_lat', true)),
                'lng'     => self::coord(get_post_meta($loc, '_tix_loc_lng', true)),
                'transit' => $m('_tix_loc_transit') ?: $org_transit,
                'parking' => $m('_tix_loc_parking') ?: $org_parking,
            ];
        }
        $address = trim((string) get_post_meta($oid, '_tix_org_address', true));
        if ($address === '') return null;
        return [
            'name' => html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8'), 'address' => $address,
            'city' => trim((string) get_post_meta($oid, '_tix_org_city', true)), 'lat' => null, 'lng' => null,
            'transit' => $org_transit, 'parking' => $org_parking,
        ];
    }

    /** Design der Seite; leer/unbekannt = galerie. */
    public static function page_design($oid) {
        $d = (string) get_post_meta($oid, '_tix_org_page_design', true);
        return isset(self::DESIGNS[$d]) ? $d : self::DESIGN_DEFAULT;
    }

    /** Design setzen (nur die fünf Werte) und öffentliche Zwischenspeicher leeren. */
    public static function set_design($oid, $value) {
        $v = (string) $value;
        if (!isset(self::DESIGNS[$v])) {
            return new WP_Error('tix_invalid_design', 'Unbekanntes Design. Erlaubt: ' . implode(', ', array_keys(self::DESIGNS)) . '.', ['status' => 400, 'field' => 'page_design']);
        }
        update_post_meta($oid, '_tix_org_page_design', $v);
        self::flush();
        return true;
    }

    private static function flush() {
        if (class_exists('TIX_App_Scope') && method_exists('TIX_App_Scope', 'flush_public_cache')) TIX_App_Scope::flush_public_cache();
        elseif (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }

    /** Auswahl als fünf Kacheln mit schematischer Vorschau (Radio-Feld „page_design“). */
    public static function design_picker($oid) {
        $cur = self::page_design($oid);
        $h = '<fieldset class="tix-pd"><legend class="tix-pd-legend">Design deiner Seite</legend><div class="tix-pd-grid">';
        foreach (self::DESIGNS as $k => $d) {
            $h .= '<label class="tix-pd-tile"><input type="radio" name="page_design" value="' . esc_attr($k) . '" ' . checked($cur, $k, false) . '>'
                . '<span class="tix-pd-card"><span class="tix-pd-prev tix-pd-' . esc_attr($k) . '" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>'
                . '<span class="tix-pd-name">' . esc_html($d[0]) . ($k === self::DESIGN_DEFAULT ? ' <small>Standard</small>' : '') . '</span>'
                . '<span class="tix-pd-desc">' . esc_html($d[1]) . '</span></span></label>';
        }
        $h .= '</div></fieldset>';
        return $h . '<style>
.tix-pd{border:0;margin:16px 0;padding:0}.tix-pd-legend{font-weight:700;font-size:14px;margin-bottom:8px}
.tix-pd-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px}
.tix-pd-tile{cursor:pointer;display:block}.tix-pd-tile input{position:absolute;opacity:0;pointer-events:none}
.tix-pd-card{display:flex;flex-direction:column;gap:4px;border:2px solid #e5e7eb;border-radius:12px;padding:10px;background:#fff;height:100%;box-sizing:border-box}
.tix-pd-tile input:checked+.tix-pd-card{border-color:#E8445A;box-shadow:0 0 0 3px rgba(232,68,90,.15)}
.tix-pd-tile input:focus-visible+.tix-pd-card{outline:2px solid #2271b1;outline-offset:2px}
.tix-pd-name{font-weight:700;font-size:14px;color:#1d2327}.tix-pd-name small{font-weight:600;font-size:11px;color:#E8445A;margin-left:4px}
.tix-pd-desc{font-size:12px;color:#646970;line-height:1.4}
.tix-pd-prev{position:relative;display:block;height:92px;border-radius:8px;overflow:hidden;background:#F2F3F7;margin-bottom:4px}
.tix-pd-prev i{position:absolute;display:block;border-radius:4px;background:#C9CCD6}
.tix-pd-galerie i:nth-child(1){left:8%;top:10%;width:84%;height:44%;background:#E8445A;opacity:.85}
.tix-pd-galerie i:nth-child(2){left:8%;top:62%;width:26%;height:26%}.tix-pd-galerie i:nth-child(3){left:37%;top:62%;width:26%;height:26%}.tix-pd-galerie i:nth-child(4){left:66%;top:62%;width:26%;height:26%}
.tix-pd-clubnacht{background:#15161E}.tix-pd-clubnacht i{background:#3A3D4F}
.tix-pd-clubnacht i:nth-child(1){left:8%;top:10%;width:60%;height:14%;background:#fff;opacity:.9}.tix-pd-clubnacht i:nth-child(2){left:8%;top:30%;width:34%;height:12%;background:#FC2F55;border-radius:99px}
.tix-pd-clubnacht i:nth-child(3){left:8%;top:52%;width:84%;height:10%}.tix-pd-clubnacht i:nth-child(4){left:8%;top:66%;width:84%;height:10%}.tix-pd-clubnacht i:nth-child(5){left:8%;top:80%;width:84%;height:10%}
.tix-pd-profil i:nth-child(1){left:8%;top:10%;width:22%;height:30%;border-radius:50%;background:#E8445A;opacity:.85}.tix-pd-profil i:nth-child(2){left:36%;top:16%;width:50%;height:9%;background:#2C2F3F}
.tix-pd-profil i:nth-child(3){left:8%;top:48%;width:20%;height:9%;border-radius:99px}.tix-pd-profil i:nth-child(4){left:31%;top:48%;width:20%;height:9%;border-radius:99px}
.tix-pd-profil i:nth-child(5){left:8%;top:63%;width:40%;height:28%}.tix-pd-profil i:nth-child(6){left:52%;top:63%;width:40%;height:28%}
.tix-pd-ticketschalter i:nth-child(1){left:8%;top:10%;width:40%;height:42%;background:repeating-linear-gradient(90deg,#C9CCD6 0 5px,transparent 5px 9px),repeating-linear-gradient(0deg,#C9CCD6 0 4px,transparent 4px 8px);background-blend-mode:multiply;opacity:.8}
.tix-pd-ticketschalter i:nth-child(2){left:54%;top:10%;width:38%;height:22%;background:#fff;border:1px dashed #E8445A}.tix-pd-ticketschalter i:nth-child(3){left:54%;top:38%;width:38%;height:22%;background:#fff;border:1px dashed #E8445A}
.tix-pd-ticketschalter i:nth-child(4){left:8%;top:62%;width:84%;height:26%;background:#fff;border:1px dashed #C9CCD6}
.tix-pd-bento i:nth-child(1){left:6%;top:8%;width:52%;height:50%;background:#E8445A;opacity:.85}.tix-pd-bento i:nth-child(2){left:62%;top:8%;width:32%;height:23%}.tix-pd-bento i:nth-child(3){left:62%;top:35%;width:32%;height:23%;background:#2C2F3F}
.tix-pd-bento i:nth-child(4){left:6%;top:62%;width:26%;height:30%}.tix-pd-bento i:nth-child(5){left:36%;top:62%;width:58%;height:30%}
</style>';
    }

    public static function schema_type($oid) {
        $t = (string) get_post_meta($oid, '_tix_org_schema_type', true);
        return in_array($t, self::SCHEMA_TYPES, true) ? $t : '';
    }

    /** Zusatzfelder für GET /public/organizers/{id|slug}. */
    public static function public_extra($oid) {
        $ids  = self::event_ids($oid);
        $past = self::past_ids($ids);
        return [
            'facts'       => (object) self::facts($oid),
            'faq'         => self::faq($oid),
            'followers'   => class_exists('TIX_Organizer_Link') ? intval(TIX_Organizer_Link::followers($oid)['total']) : 0,
            'since'       => self::since($oid, $ids),
            'events_held' => count($past),
            'gallery'     => self::gallery($past),
            'venue'       => self::venue($oid, $ids),
            'schema_type' => self::schema_type($oid),
            // Impressum für die eigene Domain (optional, vom Veranstalter gepflegt): HTML bzw. Adresse, sonst null
            'imprint'     => (($im = trim((string) get_post_meta($oid, '_tix_org_imprint', true))) !== '') ? wp_kses_post(wpautop($im)) : null,
            'imprint_url' => (($iu = (string) get_post_meta($oid, '_tix_org_imprint_url', true)) !== '') ? $iu : null,
        ];
    }

    /** Serie eines Events für die Liste: Wiederholungs-Elter bzw. manuelle Serie, sonst 0. */
    public static function series_id($eid, $rec = null) {
        $rec = $rec ?? (class_exists('TIX_Recurrence') ? TIX_Recurrence::info($eid) : ['recurring' => false, 'parent' => 0]);
        if (!empty($rec['parent'])) return intval($rec['parent']);
        return !empty($rec['recurring']) ? intval($eid) : 0;
    }

    // ── Pflege (App/Web: /organizer/profile) ──

    /** Für GET /organizer/profile: Rohwerte zum Bearbeiten. */
    public static function profile_extra($oid) {
        $faq = [];
        foreach ((array) get_post_meta($oid, '_tix_org_faq', true) as $f) {
            if (trim((string) ($f['q'] ?? '')) !== '') $faq[] = ['question' => (string) $f['q'], 'answer' => (string) ($f['a'] ?? '')];
        }
        return [
            'facts'       => (object) self::facts($oid),
            'faq'         => $faq,
            'schema_type' => self::schema_type($oid),
            'transit'     => (string) get_post_meta($oid, '_tix_org_transit', true),
            'parking'     => (string) get_post_meta($oid, '_tix_org_parking', true),
            'page_design' => self::page_design($oid),
            'imprint'     => (string) get_post_meta($oid, '_tix_org_imprint', true),
            'imprint_url' => (string) get_post_meta($oid, '_tix_org_imprint_url', true),
        ];
    }

    private static function clean_facts($in, $old = []) {
        $f = is_array($old) ? $old : [];
        foreach (self::FACT_KEYS as $k) {
            if (!array_key_exists($k, $in)) continue;
            if ($k === 'capacity') {
                $n = intval($in[$k]);
                if ($n > 0) $f[$k] = min($n, 1000000); else unset($f[$k]);
                continue;
            }
            $v = mb_substr(sanitize_text_field((string) $in[$k]), 0, 120);
            if ($v === '') unset($f[$k]); else $f[$k] = $v;
        }
        return $f;
    }

    private static function clean_faq($in) {
        $out = [];
        foreach ((array) $in as $row) {
            if (!is_array($row)) continue;
            $q = mb_substr(sanitize_text_field((string) ($row['question'] ?? $row['q'] ?? '')), 0, 200);
            $a = wp_kses_post((string) ($row['answer'] ?? $row['a'] ?? ''));
            if ($q === '') continue;
            $out[] = ['q' => $q, 'a' => mb_substr($a, 0, 3000)];
            if (count($out) >= self::FAQ_MAX) break;
        }
        return $out;
    }

    /** POST /organizer/profile: facts (Teil-Update), faq (ganze Liste), schema_type, transit, parking. */
    public static function save_from_profile($oid, array $b) {
        if (array_key_exists('page_design', $b)) {
            $r = self::set_design($oid, (string) $b['page_design']);
            if (is_wp_error($r)) return $r;
        }
        if (array_key_exists('facts', $b)) {
            if (!is_array($b['facts'])) return new WP_Error('tix_invalid_field', 'facts muss ein Objekt sein.', ['status' => 400, 'field' => 'facts']);
            update_post_meta($oid, '_tix_org_facts', self::clean_facts($b['facts'], get_post_meta($oid, '_tix_org_facts', true)));
        }
        if (array_key_exists('faq', $b)) {
            if (!is_array($b['faq'])) return new WP_Error('tix_invalid_field', 'faq muss eine Liste sein.', ['status' => 400, 'field' => 'faq']);
            update_post_meta($oid, '_tix_org_faq', self::clean_faq($b['faq']));
        }
        if (array_key_exists('schema_type', $b)) {
            $t = (string) $b['schema_type'];
            if ($t !== '' && !in_array($t, self::SCHEMA_TYPES, true)) {
                return new WP_Error('tix_invalid_field', 'Unbekannter Typ. Erlaubt: ' . implode(', ', self::SCHEMA_TYPES) . '.', ['status' => 400, 'field' => 'schema_type']);
            }
            update_post_meta($oid, '_tix_org_schema_type', $t);
        }
        foreach (['transit', 'parking'] as $k) {
            if (array_key_exists($k, $b)) update_post_meta($oid, '_tix_org_' . $k, mb_substr(sanitize_text_field((string) $b[$k]), 0, 200));
        }
        if (array_key_exists('imprint', $b)) update_post_meta($oid, '_tix_org_imprint', mb_substr(wp_kses_post((string) $b['imprint']), 0, 5000));
        if (array_key_exists('imprint_url', $b)) {
            $u = trim((string) $b['imprint_url']);
            if ($u !== '' && !preg_match('#^https://#i', $u)) return new WP_Error('tix_invalid_field', 'Bitte eine https-Adresse für das Impressum angeben.', ['status' => 400, 'field' => 'imprint_url']);
            update_post_meta($oid, '_tix_org_imprint_url', $u !== '' ? esc_url_raw($u, ['https']) : '');
        }
        return true;
    }

    // ── WP-Admin ──

    public static function meta_boxes() {
        add_meta_box('tix_org_page', 'Seite auf der Plattform: Steckbrief, FAQ, Anfahrt', [__CLASS__, 'render_organizer_box'], 'tix_organizer', 'normal', 'default');
        add_meta_box('tix_loc_route', 'Anfahrt (Veranstalter-Seite)', [__CLASS__, 'render_location_box'], 'tix_location', 'normal', 'default');
    }

    public static function render_organizer_box($post) {
        wp_nonce_field('tix_org_page', 'tix_org_page_nonce');
        $f = self::facts($post->ID);
        $labels = ['music' => ['Musik', 'House, Charts, 90er'], 'age' => ['Alter', 'ab 18'], 'dresscode' => ['Dresscode', 'Smart Casual'], 'opening' => ['Öffnungstage', 'Fr & Sa ab 23 Uhr'], 'capacity' => ['Kapazität (Personen)', '450']];
        $faq = (array) get_post_meta($post->ID, '_tix_org_faq', true);
        $faq[] = ['q' => '', 'a' => ''];
        $faq[] = ['q' => '', 'a' => ''];
        $type = self::schema_type($post->ID);
        ?>
        <?php echo self::design_picker($post->ID); ?>
        <p class="description">Erscheint auf der Veranstalter-Seite. Leere Felder werden nicht angezeigt.</p>
        <table class="form-table" role="presentation">
            <?php foreach ($labels as $k => $l) : ?>
            <tr><th><label for="tix_org_fact_<?php echo esc_attr($k); ?>"><?php echo esc_html($l[0]); ?></label></th>
                <td><input type="<?php echo $k === 'capacity' ? 'number' : 'text'; ?>" min="0" class="regular-text" id="tix_org_fact_<?php echo esc_attr($k); ?>" name="tix_org_facts[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($f[$k] ?? ''); ?>" placeholder="<?php echo esc_attr($l[1]); ?>"></td></tr>
            <?php endforeach; ?>
            <tr><th><label for="tix_org_schema_type">Art (für Suchmaschinen)</label></th>
                <td><select id="tix_org_schema_type" name="tix_org_schema_type"><option value="">– keine Angabe –</option>
                    <?php foreach (self::SCHEMA_TYPES as $t) : ?><option value="<?php echo esc_attr($t); ?>" <?php selected($type, $t); ?>><?php echo esc_html($t); ?></option><?php endforeach; ?>
                </select></td></tr>
            <tr><th><label for="tix_org_transit">Anfahrt mit Bus &amp; Bahn</label></th>
                <td><input type="text" class="large-text" id="tix_org_transit" name="tix_org_transit" value="<?php echo esc_attr(get_post_meta($post->ID, '_tix_org_transit', true)); ?>" placeholder="Bus 620 bis Aue, 2 min"><p class="description">Gilt, wenn am Ort nichts eingetragen ist.</p></td></tr>
            <tr><th><label for="tix_org_imprint">Impressum (eigene Domain)</label></th>
                <td><textarea class="large-text" rows="4" id="tix_org_imprint" name="tix_org_imprint" placeholder="Firma, Anschrift, Vertretung, Kontakt, USt-IdNr. …"><?php echo esc_textarea(get_post_meta($post->ID, '_tix_org_imprint', true)); ?></textarea>
                <input type="url" class="large-text" name="tix_org_imprint_url" value="<?php echo esc_attr(get_post_meta($post->ID, '_tix_org_imprint_url', true)); ?>" placeholder="oder Link auf ein bestehendes Impressum (https://…)"></td></tr>
            <tr><th><label for="tix_org_parking">Parken</label></th>
                <td><input type="text" class="large-text" id="tix_org_parking" name="tix_org_parking" value="<?php echo esc_attr(get_post_meta($post->ID, '_tix_org_parking', true)); ?>" placeholder="Parkhaus am Markt"></td></tr>
        </table>
        <h4>Häufige Fragen</h4>
        <?php foreach ($faq as $i => $row) : ?>
            <p><input type="text" class="large-text" name="tix_org_faq[<?php echo intval($i); ?>][q]" value="<?php echo esc_attr($row['q'] ?? ''); ?>" placeholder="Frage"><br>
               <textarea class="large-text" rows="2" name="tix_org_faq[<?php echo intval($i); ?>][a]" placeholder="Antwort"><?php echo esc_textarea($row['a'] ?? ''); ?></textarea></p>
        <?php endforeach; ?>
        <p class="description">Leere Frage = Eintrag entfernen. Nach dem Speichern erscheinen zwei neue leere Zeilen.</p>
        <?php
    }

    public static function render_location_box($post) {
        wp_nonce_field('tix_loc_route', 'tix_loc_route_nonce');
        ?>
        <table class="form-table" role="presentation">
            <tr><th><label for="tix_loc_transit">Bus &amp; Bahn</label></th>
                <td><input type="text" class="large-text" id="tix_loc_transit" name="tix_loc_transit" value="<?php echo esc_attr(get_post_meta($post->ID, '_tix_loc_transit', true)); ?>" placeholder="Bus 620 bis Aue, 2 min"></td></tr>
            <tr><th><label for="tix_loc_parking">Parken</label></th>
                <td><input type="text" class="large-text" id="tix_loc_parking" name="tix_loc_parking" value="<?php echo esc_attr(get_post_meta($post->ID, '_tix_loc_parking', true)); ?>" placeholder="Parkhaus am Markt"></td></tr>
        </table>
        <?php
    }

    public static function save_organizer_admin($post_id, $post) {
        if (!isset($_POST['tix_org_page_nonce']) || !wp_verify_nonce($_POST['tix_org_page_nonce'], 'tix_org_page')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $b = wp_unslash($_POST);
        update_post_meta($post_id, '_tix_org_facts', self::clean_facts(is_array($b['tix_org_facts'] ?? null) ? $b['tix_org_facts'] : [], []));
        update_post_meta($post_id, '_tix_org_faq', self::clean_faq($b['tix_org_faq'] ?? []));
        $t = (string) ($b['tix_org_schema_type'] ?? '');
        update_post_meta($post_id, '_tix_org_schema_type', in_array($t, self::SCHEMA_TYPES, true) ? $t : '');
        foreach (['transit', 'parking'] as $k) update_post_meta($post_id, '_tix_org_' . $k, mb_substr(sanitize_text_field((string) ($b['tix_org_' . $k] ?? '')), 0, 200));
        if (isset($b['tix_org_imprint'])) update_post_meta($post_id, '_tix_org_imprint', mb_substr(wp_kses_post((string) $b['tix_org_imprint']), 0, 5000));
        if (isset($b['tix_org_imprint_url'])) { $iu = trim((string) $b['tix_org_imprint_url']); update_post_meta($post_id, '_tix_org_imprint_url', preg_match('#^https://#i', $iu) ? esc_url_raw($iu, ['https']) : ''); }
        if (isset($b['page_design']) && isset(self::DESIGNS[(string) $b['page_design']])) update_post_meta($post_id, '_tix_org_page_design', (string) $b['page_design']);
        self::flush();
    }

    public static function save_location_admin($post_id, $post) {
        if (!isset($_POST['tix_loc_route_nonce']) || !wp_verify_nonce($_POST['tix_loc_route_nonce'], 'tix_loc_route')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        foreach (['transit', 'parking'] as $k) update_post_meta($post_id, '_tix_loc_' . $k, mb_substr(sanitize_text_field((string) wp_unslash($_POST['tix_loc_' . $k] ?? '')), 0, 200));
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }
}
