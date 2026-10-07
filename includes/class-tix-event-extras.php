<?php
/**
 * Optionale Event-Inhalte – EINE Quelle für App-Schnittstelle und Website.
 *
 * Die Daten-Funktionen (faq(), timetable(), video() …) liefern die Inhalte so, wie sie
 * GET /public/events/{id} ausgibt (Apps: evendis, KitchenKlub) und wie die Website-Bausteine
 * sie rendern. Sie geben null bzw. [] zurück, wenn nichts gepflegt ist.
 *
 * Website-Shortcodes (geben leer zurück, wenn nichts gepflegt ist – für [tix_section]):
 *   [tix_event_gallery] [tix_event_video] [tix_event_notes] [tix_event_hints] [tix_event_charity] [tix_event_series]
 * Gewinnspiel, Programm und FAQ rendern weiterhin [tix_raffle], [tix_timetable], [tix_faq].
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Extras {

    public static function init() {
        add_shortcode('tix_event_gallery', [__CLASS__, 'sc_gallery']);
        add_shortcode('tix_event_video',   [__CLASS__, 'sc_video']);
        add_shortcode('tix_event_notes',   [__CLASS__, 'sc_notes']);
        add_shortcode('tix_event_hints',   [__CLASS__, 'sc_hints']);
        add_shortcode('tix_event_charity', [__CLASS__, 'sc_charity']);
        add_shortcode('tix_event_series',  [__CLASS__, 'sc_series']);
    }

    // ══════════════════════════════════════
    //  Daten
    // ══════════════════════════════════════

    /** Häufige Fragen: [{question, answer(HTML)}] */
    public static function faq($id) {
        $out = [];
        foreach ((array) get_post_meta($id, '_tix_faq', true) as $f) {
            $q = trim((string) ($f['q'] ?? ''));
            if ($q === '') continue;
            $out[] = ['question' => $q, 'answer' => wp_kses_post(wpautop((string) ($f['a'] ?? '')))];
        }
        return $out;
    }

    /** Programm: {times_tba, stages[{name,color}], days[{date, slots[{time,end,stage,stage_name,title,description}]}]} oder null */
    public static function timetable($id) {
        $tt = get_post_meta($id, '_tix_timetable', true);
        if (!is_array($tt) || empty($tt)) return null;
        $stages = get_post_meta($id, '_tix_stages', true);
        if (!is_array($stages) || empty($stages)) $stages = [['name' => 'Programm', 'color' => '#FF5500']];
        $stages = array_values(array_map(function ($s) {
            return ['name' => (string) ($s['name'] ?? ''), 'color' => (string) ($s['color'] ?? '')];
        }, $stages));
        ksort($tt);
        $days = [];
        foreach ($tt as $date => $slots) {
            if (!is_array($slots) || empty($slots)) continue;
            $out = [];
            foreach ($slots as $sl) {
                $si = intval($sl['stage'] ?? 0);
                $out[] = [
                    'time'        => (string) ($sl['time'] ?? ''),
                    'end'         => (string) ($sl['end'] ?? ''),
                    'stage'       => $si,
                    'stage_name'  => (string) ($stages[$si]['name'] ?? ''),
                    'title'       => (string) ($sl['title'] ?? ''),
                    'description' => (string) ($sl['desc'] ?? ''),
                ];
            }
            $days[] = ['date' => (string) $date, 'slots' => $out];
        }
        if (!$days) return null;
        return [
            'times_tba' => get_post_meta($id, '_tix_timetable_times_tba', true) === '1',
            'stages'    => $stages,
            'days'      => $days,
        ];
    }

    /** Video: {url, type: youtube|vimeo|file|external, embed_url} oder null */
    public static function video($id) {
        $url = (string) get_post_meta($id, '_tix_video_url', true);
        $vid = intval(get_post_meta($id, '_tix_video_id', true));
        if ($url === '' && $vid) $url = (string) wp_get_attachment_url($vid);
        if ($url === '') return null;
        $type = 'external';
        $embed = '';
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{6,})~', $url, $m)) {
            $type = 'youtube';
            $embed = 'https://www.youtube-nocookie.com/embed/' . $m[1];
        } elseif (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            $type = 'vimeo';
            $embed = 'https://player.vimeo.com/video/' . $m[1];
        } elseif ($vid || preg_match('~\.(mp4|webm|mov)(\?|$)~i', $url)) {
            $type = 'file';
            $embed = $url;
        }
        return ['url' => $url, 'type' => $type, 'embed_url' => $embed];
    }

    /** Hinweise aus dem Editor (Gruppe „Ticket“): Dresscode (Text), Einlassregeln + Ticket-Hinweise (HTML) */
    public static function notes($id) {
        $html = function ($key) use ($id) {
            $v = (string) get_post_meta($id, $key, true);
            return trim(wp_strip_all_tags($v)) === '' ? '' : wp_kses_post(wpautop($v));
        };
        return [
            'dresscode'    => trim(wp_strip_all_tags((string) get_post_meta($id, '_tix_info_dresscode', true))),
            'entry_rules'  => $html('_tix_info_entry_rules'),
            'ticket_notes' => $html('_tix_info_ticket_notes'),
        ];
    }

    /** Soziales Projekt: {name, percent, description, image} oder null (nur wenn global + am Event an) */
    public static function charity($id) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (empty($s['charity_enabled']) || get_post_meta($id, '_tix_charity_enabled', true) !== '1') return null;
        $name = (string) get_post_meta($id, '_tix_charity_name', true);
        $pct  = intval(get_post_meta($id, '_tix_charity_percent', true));
        if ($name === '' || $pct <= 0) return null;
        $img = intval(get_post_meta($id, '_tix_charity_image', true));
        return [
            'name'        => $name,
            'percent'     => $pct,
            'description' => (string) get_post_meta($id, '_tix_charity_desc', true),
            'image'       => $img ? (string) wp_get_attachment_image_url($img, 'medium') : '',
        ];
    }

    /** Weitere Termine derselben Serie (kommende, veröffentlichte, ohne dieses Event) */
    public static function series($id) {
        $parent = intval(get_post_meta($id, '_tix_series_parent', true));
        if (!$parent) {
            if (get_post_meta($id, '_tix_series_enabled', true) !== '1') return [];
            $parent = intval($id);
        }
        $children = get_post_meta($parent, '_tix_series_children', true);
        if (!is_array($children) || empty($children)) return [];
        $today = wp_date('Y-m-d');
        $out = [];
        foreach ($children as $cid) {
            $cid = intval($cid);
            if ($cid === intval($id) || get_post_status($cid) !== 'publish') continue;
            $ds = (string) get_post_meta($cid, '_tix_date_start', true);
            if ($ds === '' || $ds < $today) continue;
            $out[] = [
                'id'         => $cid,
                'title'      => (string) get_post_field('post_title', $cid),
                'date_start' => $ds,
                'time_start' => (string) get_post_meta($cid, '_tix_time_start', true),
                'url'        => (string) get_permalink($cid),
                'status'     => (string) (get_post_meta($cid, '_tix_status', true) ?: 'available'),
            ];
        }
        usort($out, function ($a, $b) { return strcmp($a['date_start'] . $a['time_start'], $b['date_start'] . $b['time_start']); });
        return $out;
    }

    /** Nur Abendkasse (ohne Online-Verkauf): {price, description} oder null */
    public static function box_office($id) {
        if (get_post_meta($id, '_tix_tickets_enabled', true) === '1') return null;
        if (get_post_meta($id, '_tix_box_office_only', true) !== '1') return null;
        $price = (string) get_post_meta($id, '_tix_box_office_price', true);
        return [
            'price'       => $price !== '' ? round(floatval(str_replace(',', '.', $price)), 2) : null,
            'description' => (string) get_post_meta($id, '_tix_box_office_desc', true),
        ];
    }

    /** Externer Ticketshop: {url, text, mode: replace|both} oder null */
    public static function external_shop($id) {
        if (get_post_meta($id, '_tix_extshop_enabled', true) !== '1') return null;
        $url = (string) get_post_meta($id, '_tix_extshop_url', true);
        if ($url === '') return null;
        return [
            'url'  => esc_url_raw($url),
            'text' => (string) (get_post_meta($id, '_tix_extshop_text', true) ?: 'Tickets kaufen'),
            'mode' => (string) (get_post_meta($id, '_tix_extshop_mode', true) ?: 'replace'),
        ];
    }

    /** Vorverkauf: {starts_at (ISO, '' = schon offen), started, waitlist_presale, waitlist_soldout} */
    public static function presale($id) {
        $raw = (string) get_post_meta($id, '_tix_presale_start', true);
        $ts  = $raw !== '' ? strtotime(str_replace('T', ' ', $raw)) : 0;
        $started = !$ts || $ts <= current_time('timestamp');
        $wl = class_exists('TIX_Waitlist');
        return [
            'starts_at'        => $ts ? wp_date('c', strtotime(get_gmt_from_date(date('Y-m-d H:i:s', $ts)) . ' UTC')) : '',
            'started'          => $started,
            'waitlist_presale' => $wl && !$started && TIX_Waitlist::available($id, 'presale'),
            'waitlist_soldout' => $wl && TIX_Waitlist::available($id, 'soldout'),
        ];
    }

    /** Galerie-Bilder: [{url (large), thumb (medium_large)}] */
    public static function gallery($id) {
        $out = [];
        foreach ((array) get_post_meta($id, '_tix_gallery', true) as $item) {
            if (is_numeric($item)) {
                $large = wp_get_attachment_image_url(intval($item), 'large');
                if ($large) $out[] = ['url' => $large, 'thumb' => wp_get_attachment_image_url(intval($item), 'medium_large') ?: $large];
            } elseif (is_string($item) && $item !== '') {
                $out[] = ['url' => $item, 'thumb' => $item];
            }
        }
        return $out;
    }

    // ══════════════════════════════════════
    //  Website-Bausteine (leer ohne Inhalt)
    // ══════════════════════════════════════

    private static function cur() { return intval(get_the_ID()); }

    public static function sc_gallery() {
        $imgs = self::gallery(self::cur());
        if (!$imgs) return '';
        $h = '<div class="tix-gal">';
        foreach ($imgs as $i) {
            $h .= '<a class="tix-gal__item" href="' . esc_url($i['url']) . '" target="_blank" rel="noopener"><img src="' . esc_url($i['thumb']) . '" alt="" loading="lazy"></a>';
        }
        return $h . '</div>';
    }

    public static function sc_video() {
        $v = self::video(self::cur());
        if (!$v) return '';
        if ($v['type'] === 'file') {
            return '<div class="tix-video"><video src="' . esc_url($v['embed_url']) . '" controls playsinline preload="metadata"></video></div>';
        }
        if ($v['embed_url'] !== '') {
            return '<div class="tix-video"><iframe src="' . esc_url($v['embed_url']) . '" title="Video" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe></div>';
        }
        return '<p><a href="' . esc_url($v['url']) . '" target="_blank" rel="noopener">Video ansehen</a></p>';
    }

    /** Dresscode, Einlassregeln, Ticket-Hinweise als Zeilen (Absätze mit Icon-Klasse für die Vorlage) */
    public static function sc_notes() {
        $n = self::notes(self::cur());
        $h = '';
        if ($n['dresscode'] !== '') $h .= '<p class="ico-shirt">Dresscode: ' . esc_html($n['dresscode']) . '</p>';
        foreach (['entry_rules' => 'ico-door-open', 'ticket_notes' => 'ico-ticket'] as $k => $ico) {
            if ($n[$k] === '') continue;
            foreach (preg_split('~</p>\s*~i', $n[$k]) as $para) {
                $t = trim(wp_strip_all_tags($para));
                if ($t !== '') $h .= '<p class="' . $ico . '">' . esc_html($t) . '</p>';
            }
        }
        return $h;
    }

    /** „Gut zu wissen“: Specials + Weitere Infos + Dresscode/Einlassregeln/Ticket-Hinweise als Zeilen */
    public static function sc_hints() {
        $id = self::cur();
        $h = '';
        foreach (['_tix_info_specials' => 'ico-sparkle', '_tix_info_extra_info' => 'ico-info'] as $key => $ico) {
            $v = (string) get_post_meta($id, $key, true);
            if (trim(wp_strip_all_tags($v)) === '') continue;
            foreach (preg_split('~</p>\s*|<br\s*/?>|\n~i', wpautop($v)) as $para) {
                $t = trim(html_entity_decode(wp_strip_all_tags($para)));
                if ($t !== '') $h .= '<p class="' . $ico . '">' . esc_html($t) . '</p>';
            }
        }
        return $h . self::sc_notes();
    }

    public static function sc_charity() {
        $c = self::charity(self::cur());
        if (!$c) return '';
        $img = $c['image'] !== '' ? '<img class="tix-charity__img" src="' . esc_url($c['image']) . '" alt="">' : '';
        return '<div class="tix-charity">' . $img . '<div class="tix-charity__t"><b>' . intval($c['percent']) . ' % für ' . esc_html($c['name']) . '</b>'
            . ($c['description'] !== '' ? '<span>' . esc_html($c['description']) . '</span>' : '') . '</div></div>';
    }

    public static function sc_series() {
        $list = self::series(self::cur());
        if (!$list) return '';
        $h = '<div class="tix-series">';
        foreach ($list as $e) {
            $label = date_i18n('D, j. F Y', strtotime($e['date_start'])) . ($e['time_start'] !== '' ? ' · ' . $e['time_start'] . ' Uhr' : '');
            $h .= '<a class="tix-series__row" href="' . esc_url($e['url']) . '"><span>' . esc_html($label) . '</span><span class="tix-series__act">Tickets ›</span></a>';
        }
        return $h . '</div>';
    }
}
