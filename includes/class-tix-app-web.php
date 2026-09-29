<?php
/**
 * App-Design-Webseiten (SEO-Spur der KitchenKlub-App).
 *
 * Rendert einzelne Event-Seiten (CPT `event`) server-seitig im exakten Look der
 * Flutter-App (dunkler Grund #4A4A4A, Manrope, Karten #3B3B3B, Signal-Akzent) –
 * OHNE Breakdance zu ersetzen. Der übrige Website-Aufbau (Startseite, Impressum …)
 * bleibt vom Page-Builder gerendert.
 *
 * Muster wie TIX_Music::maybe_render_dj_page / TIX_Organizer_Shell: wir übernehmen
 * die Seite auf `template_redirect` und geben eigenes HTML aus (kein Theme).
 *
 * Sicher & reversibel:
 *   - Vorschau: /events/<slug>/?kkapp=1  → App-Design, `noindex` (Live-Seite bleibt Breakdance)
 *   - Live-Standard: Option `_tix_app_web_events = on`  → App-Design ist die echte Seite (indexierbar)
 *   - Ausschalten: Option leeren / löschen → sofort wieder Breakdance
 *
 * Datenquelle = TIX_Public_Events::payload($id, true) (identisch zur App).
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Web {

    const OPT_EVENTS = '_tix_app_web_events'; // 'on' => App-Design als Live-Standard für Event-Seiten
    const OPT_HOME   = '_tix_app_web_home';   // 'on' => App-Design als Live-Standard für die Startseite

    public static function init() {
        // Priorität 6: nach Organizer-/DJ-Shell (5), damit Personal-Vollbild Vorrang hat.
        add_action('template_redirect', [__CLASS__, 'maybe_render_home'], 6);
        add_action('template_redirect', [__CLASS__, 'maybe_render_event'], 6);
    }

    // ──────────────────────────────────────────
    //  Entscheidung + Ausgabe
    // ──────────────────────────────────────────

    public static function maybe_render_event() {
        if (is_admin() || is_feed() || is_embed()) return;
        if (!is_singular('event')) return;

        $is_preview = isset($_GET['kkapp']);
        $live       = get_option(self::OPT_EVENTS) === 'on';
        if (!$is_preview && !$live) return; // Breakdance rendert normal

        $id = get_queried_object_id();
        if (!$id) return;
        $e = TIX_Public_Events::payload($id, true);
        if (!$e) return; // nicht veröffentlicht o. Ä. → Breakdance/404 wie gehabt

        // Nur die echte Standard-Seite darf in den Index; die ?kkapp-Vorschau nicht.
        $indexable = $live && !$is_preview;

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        self::render($e, $indexable);
        exit;
    }

    public static function maybe_render_home() {
        if (is_admin() || is_feed() || is_embed()) return;
        if (!(is_front_page() || is_home())) return;

        $is_preview = isset($_GET['kkapp']);
        $live       = get_option(self::OPT_HOME) === 'on';
        if (!$is_preview && !$live) return; // Breakdance rendert die Startseite normal

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        self::render_home($live && !$is_preview, $is_preview);
        exit;
    }

    /** Kommende Events (nicht vergangen), nach Datum aufsteigend, als Payloads. */
    private static function upcoming_events($limit = 30) {
        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ]);
        $out = [];
        foreach ($ids as $id) {
            $p = TIX_Public_Events::payload($id, false);
            if ($p && empty($p['is_past'])) $out[] = $p;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    // ──────────────────────────────────────────
    //  Hilfen (Zeit / Format / Status)
    // ──────────────────────────────────────────

    private static $WD = ['', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    private static $WD_SHORT = ['', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.', 'So.'];
    private static $MON = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    private static $MON_SHORT = ['', 'Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.'];
    // Datums-Kachel wie KkDateTile: Monat 3-stellig GROSS ohne Punkt.
    private static $MON_TILE = ['', 'JAN', 'FEB', 'MÄR', 'APR', 'MAI', 'JUN', 'JUL', 'AUG', 'SEP', 'OKT', 'NOV', 'DEZ'];

    /** DateTime in WP-Zeitzone aus Datum (Y-m-d) + Zeit (H:i); null wenn kein Datum. */
    private static function dt($date, $time, $fallback_time) {
        $date = trim((string) $date);
        if ($date === '') return null;
        $time = trim((string) $time);
        $dt = DateTime::createFromFormat(
            'Y-m-d H:i',
            $date . ' ' . ($time !== '' ? $time : $fallback_time),
            wp_timezone()
        );
        return $dt ?: null;
    }

    /** „Freitag, 3. Oktober 2026 · 20:00 Uhr“ (mit Zeit, wenn vorhanden). */
    private static function long_date(DateTime $dt, $with_time = true) {
        $s = self::$WD[(int) $dt->format('N')] . ', ' . (int) $dt->format('j') . '. '
            . self::$MON[(int) $dt->format('n')] . ' ' . $dt->format('Y');
        if ($with_time) $s .= ' · ' . $dt->format('H:i') . ' Uhr';
        return $s;
    }

    /** Kurzform wie in der App (KkDateTile): „Fr., 30. Okt. 2026 · 23:00 Uhr“. */
    private static function short_date(DateTime $dt, $with_time = true) {
        $s = self::$WD_SHORT[(int) $dt->format('N')] . ' ' . (int) $dt->format('j') . '. '
            . self::$MON_SHORT[(int) $dt->format('n')] . ' ' . $dt->format('Y');
        if ($with_time) $s .= ' · ' . $dt->format('H:i') . ' Uhr';
        return $s;
    }

    /** true, wenn Status abgesagt. */
    private static function is_cancelled($e) {
        $s = strtolower((string) ($e['status'] ?? ''));
        return strpos($s, 'cancel') !== false || strpos($s, 'abgesagt') !== false;
    }

    /** true, wenn ausverkauft (Status oder alle Kategorien 0). */
    private static function is_soldout($e) {
        $s = strtolower((string) ($e['status'] ?? ''));
        if (strpos($s, 'sold') !== false || $s === 'soldout' || strpos($s, 'ausverkauft') !== false) return true;
        $cats = $e['categories'] ?? [];
        if ($cats) {
            $all_out = true;
            foreach ($cats as $c) if (empty($c['sold_out'])) { $all_out = false; break; }
            if ($all_out) return true;
        }
        return false;
    }

    private static function money($v) {
        return number_format((float) $v, 2, ',', '.') . ' €';
    }

    /** Kauf-Ziel. Vorerst die reguläre (Breakdance-)Event-Seite mit Ticket-Widget.
     *  Später per Filter auf einen eigenen Kauf-Fluss umlenkbar. */
    private static function buy_url($e) {
        $url = (string) ($e['url'] ?? '');
        return apply_filters('tix_app_web_buy_url', $url, $e);
    }

    // ──────────────────────────────────────────
    //  Render
    // ──────────────────────────────────────────

    private static function render($e, $indexable) {
        $title    = (string) $e['title'];
        $permalink = (string) ($e['url'] ?? home_url('/'));
        $flyer    = (string) ($e['image'] ?? '');
        $flyer_sm = (string) ($e['image_small'] ?? $e['thumbnail'] ?? '');
        $venue    = trim((string) ($e['location'] ?? ''));
        $address  = trim((string) ($e['address'] ?? ''));
        $single   = apply_filters('tix_app_web_single_venue', true); // KitchenKlub: nur Adresse
        $place    = $single ? ($address !== '' ? $address : $venue)
                            : trim($venue . ($address !== '' ? ' · ' . $address : ''));

        $start = self::dt($e['date_start'] ?? '', $e['time_start'] ?? '', '00:00');
        $end   = self::dt($e['date_end'] ?? ($e['date_start'] ?? ''), $e['time_end'] ?? '', '23:59');
        $doors = self::dt($e['date_start'] ?? '', $e['time_doors'] ?? '', '');
        if ($doors && ($e['time_doors'] ?? '') === '') $doors = null;

        $cancelled = self::is_cancelled($e);
        $soldout   = !$cancelled && self::is_soldout($e);
        $enabled   = !empty($e['tickets_enabled']);
        $price_from = $e['price_from'];
        $is_free   = $enabled && $price_from !== null && (float) $price_from == 0.0;
        $has_presale = $enabled && !$cancelled && !$soldout;
        $has_cta     = $has_presale || $soldout || $cancelled;
        $age       = trim((string) ($e['age_label'] ?? ''));
        $status_label = trim((string) ($e['status_label'] ?? ''));

        // SEO-Beschreibung
        $desc_parts = [];
        if ($start) $desc_parts[] = self::long_date($start, false);
        if ($place !== '') $desc_parts[] = $place;
        if ($age !== '') $desc_parts[] = $age;
        $excerpt = trim(wp_strip_all_tags((string) ($e['excerpt'] ?? '')));
        if ($excerpt === '') $excerpt = trim(wp_strip_all_tags((string) ($e['description'] ?? '')));
        $meta_desc = $excerpt !== '' ? $excerpt : implode(' · ', $desc_parts);
        $meta_desc = mb_substr($meta_desc, 0, 200);

        // Countdown-Zeiten für JS (ISO mit Offset)
        $start_iso = $start ? $start->format('c') : '';
        $end_iso   = $end ? $end->format('c') : '';

        // Google-Kalender-Link (UTC)
        $cal_url = '';
        if ($start) {
            $s_utc = (clone $start)->setTimezone(new DateTimeZone('UTC'));
            $e_utc = $end ? (clone $end)->setTimezone(new DateTimeZone('UTC')) : (clone $start)->modify('+4 hours')->setTimezone(new DateTimeZone('UTC'));
            $cal_url = 'https://calendar.google.com/calendar/render?' . http_build_query([
                'action'   => 'TEMPLATE',
                'text'     => $title,
                'dates'    => $s_utc->format('Ymd\THis\Z') . '/' . $e_utc->format('Ymd\THis\Z'),
                'details'  => $permalink,
                'location' => trim($venue . ($address !== '' ? ', ' . $address : '')),
            ]);
        }

        $share_text = $title . ($start ? ' – ' . self::long_date($start, false) : '') . "\n" . $permalink;
        $wa   = 'https://wa.me/?text=' . rawurlencode($share_text);
        $fb   = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($permalink);
        $mail = 'mailto:?subject=' . rawurlencode($title) . '&body=' . rawurlencode($share_text);
        $maps = $place !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($venue . ' ' . $address)) : '';

        // Logo
        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // ── Badges ──
        $badges = [];
        if ($age !== '') $badges[] = ['t' => $age, 'c' => 'light'];
        if ($cancelled)      $badges[] = ['t' => 'Abgesagt', 'c' => 'signal'];
        elseif ($soldout)    $badges[] = ['t' => 'Ausverkauft', 'c' => 'signal'];
        elseif ($status_label !== '') $badges[] = ['t' => $status_label, 'c' => 'light'];
        elseif ($enabled)    $badges[] = ['t' => 'Verfügbar', 'c' => 'light'];
        if ($has_presale)    $badges[] = ['t' => 'Vorverkauf', 'c' => 'presale'];
        if ($enabled && $is_free) $badges[] = ['t' => 'Freier Eintritt', 'c' => 'teal'];

        // ── HEAD ──
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>' . esc_html($title) . ' · KitchenKlub</title>';
        echo '<meta name="description" content="' . esc_attr($meta_desc) . '">';
        echo '<link rel="canonical" href="' . esc_url($permalink) . '">';
        if (!$indexable) echo '<meta name="robots" content="noindex,follow">';
        echo '<meta name="theme-color" content="#4A4A4A">';
        // Open Graph / Twitter (Flyer-Vorschau beim Teilen)
        echo '<meta property="og:type" content="article">';
        echo '<meta property="og:site_name" content="KitchenKlub">';
        echo '<meta property="og:title" content="' . esc_attr($title) . '">';
        echo '<meta property="og:description" content="' . esc_attr($meta_desc) . '">';
        echo '<meta property="og:url" content="' . esc_url($permalink) . '">';
        if ($flyer !== '') echo '<meta property="og:image" content="' . esc_url($flyer) . '">';
        echo '<meta name="twitter:card" content="summary_large_image">';
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">';
        echo '<meta name="twitter:description" content="' . esc_attr($meta_desc) . '">';
        if ($flyer !== '') echo '<meta name="twitter:image" content="' . esc_url($flyer) . '">';
        // Fonts
        echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
        // JSON-LD Event
        self::json_ld($e, $start, $end, $permalink, $flyer, $venue, $address, $price_from, $is_free);
        echo '<style>' . self::css() . '</style>';
        echo '</head><body' . ($has_cta ? ' class="has-cta"' : '') . '>';

        // ── Sticky Kopf ──
        echo '<header class="bar"><a class="brand" href="' . esc_url(home_url('/')) . '" aria-label="KitchenKlub Startseite">';
        if ($logo_url !== '') echo '<img src="' . esc_url($logo_url) . '" alt="KitchenKlub">';
        else echo '<span class="brandtxt">KITCHENKLUB</span>';
        echo '</a>';
        echo '<button class="cbtn share-native" data-url="' . esc_attr($permalink) . '" data-title="' . esc_attr($title) . '" aria-label="Teilen">'
            . self::icon('share') . '</button>';
        echo '</header>';

        echo '<main class="wrap">';

        // ── Flyer ──
        if ($flyer !== '') {
            echo '<div class="flyer"><img src="' . esc_url($flyer) . '" alt="' . esc_attr($title) . '" '
                . ($flyer_sm !== '' ? 'style="background-image:url(' . esc_url($flyer_sm) . ')"' : '') . '></div>';
        }

        // ── Titel + Ort ──
        echo '<h1 class="title">' . esc_html($title) . '</h1>';
        if ($place !== '') {
            echo '<p class="place">' . self::icon('pin') . '<span>' . esc_html($place) . '</span></p>';
        }

        // ── Badges ──
        if ($badges) {
            echo '<div class="badges">';
            foreach ($badges as $b) echo '<span class="badge b-' . esc_attr($b['c']) . '">' . esc_html($b['t']) . '</span>';
            echo '</div>';
        }

        // ── Termin-Tabelle ──
        echo '<div class="card infos">';
        echo self::info_row('Beginn', $start ? self::short_date($start) : 'Termin folgt');
        if ($end && (($e['date_end'] ?? '') !== '' || ($e['time_end'] ?? '') !== '')) {
            $end_same_day = $start && $start->format('Y-m-d') === $end->format('Y-m-d');
            echo self::info_row('Ende', $end_same_day ? $end->format('H:i') . ' Uhr' : self::short_date($end));
        }
        if ($doors) echo self::info_row('Einlass', $doors->format('H:i') . ' Uhr');
        echo '</div>';

        // ── Countdown ──
        if ($start_iso !== '') {
            echo '<div class="countdown" id="cd" data-start="' . esc_attr($start_iso) . '" data-end="' . esc_attr($end_iso) . '"></div>';
        }

        // ── Kalender ──
        if ($cal_url !== '') {
            echo '<a class="btn btn-outline" href="' . esc_url($cal_url) . '" target="_blank" rel="noopener">'
                . self::icon('cal') . 'Zum Kalender hinzufügen</a>';
        }

        // ── Teilen ──
        echo '<section class="sec"><h2>Teilen</h2><div class="sharerow">';
        echo '<a class="cbtn" href="' . esc_url($wa) . '" target="_blank" rel="noopener" aria-label="WhatsApp">' . self::icon('wa') . '</a>';
        echo '<a class="cbtn" href="' . esc_url($fb) . '" target="_blank" rel="noopener" aria-label="Facebook">' . self::icon('fb') . '</a>';
        echo '<a class="cbtn" href="' . esc_url($mail) . '" aria-label="E-Mail">' . self::icon('mail') . '</a>';
        echo '<button class="cbtn copy-link" data-url="' . esc_attr($permalink) . '" aria-label="Link kopieren">' . self::icon('copy') . '</button>';
        echo '</div></section>';

        // ── Textabschnitte ──
        self::section('Beschreibung', $e['description'] ?? '');
        self::section('Line-Up', $e['lineup'] ?? '');
        self::section('Specials', $e['specials'] ?? '');
        self::section('Weitere Infos', $e['extra_info'] ?? '');

        // ── Galerie ──
        $gallery = $e['gallery'] ?? [];
        if ($gallery) {
            echo '<section class="sec"><h2>Galerie</h2><div class="gallery">';
            foreach ($gallery as $g) {
                echo '<a class="gimg" href="' . esc_url($g) . '" target="_blank" rel="noopener"><img src="' . esc_url($g) . '" alt="" loading="lazy"></a>';
            }
            echo '</div></section>';
        }

        // ── Tickets (Kategorien) ──
        $cats = $e['categories'] ?? [];
        if ($cats && !$cancelled) {
            echo '<section class="sec" id="tickets"><h2>Tickets</h2><div class="card catlist">';
            foreach ($cats as $c) {
                if (!empty($c['gift_card'])) continue;
                $cout = !empty($c['sold_out']);
                echo '<div class="catrow' . ($cout ? ' out' : '') . '">';
                echo '<div class="catmeta"><div class="catname">' . esc_html($c['name'] ?? 'Ticket');
                if (!empty($c['phase_label'])) echo ' <span class="tag">' . esc_html($c['phase_label']) . '</span>';
                echo '</div>';
                if (!empty($c['description'])) echo '<div class="catdesc">' . esc_html($c['description']) . '</div>';
                echo '</div>';
                echo '<div class="catprice">';
                if ($cout) echo '<span class="soldout">Ausverkauft</span>';
                else {
                    $p = (float) ($c['price'] ?? 0);
                    if (!empty($c['phase_label']) && (float) ($c['base_price'] ?? 0) > $p) {
                        echo '<span class="old">' . esc_html(self::money($c['base_price'])) . '</span> ';
                    }
                    echo '<span class="now">' . ($p == 0.0 ? 'Frei' : esc_html(self::money($p))) . '</span>';
                }
                echo '</div></div>';
            }
            echo '</div></section>';
        }

        // ── Anfahrt ──
        if ($place !== '' && $maps !== '') {
            echo '<section class="sec"><h2>Anfahrt</h2>';
            echo '<a class="card row" href="' . esc_url($maps) . '" target="_blank" rel="noopener">';
            echo '<span class="iconbox">' . self::icon('pin') . '</span>';
            echo '<span class="rowlabel">' . esc_html($single ? $place : $venue);
            if (!$single && $address !== '') echo '<span class="rowsub">' . esc_html($address) . '</span>';
            echo '</span>' . self::icon('nav') . '</a></section>';
        }

        echo '<div class="footspace"></div>';
        echo '</main>';

        // ── Sticky CTA ──
        if ($has_cta) {
            echo '<div class="cta">';
            if ($has_presale && !$is_free && $price_from !== null) {
                echo '<div class="ctaprice"><span class="lbl">Preis</span><span class="val">ab ' . esc_html(self::money($price_from)) . '</span></div>';
            }
            $label = $cancelled ? 'Event abgesagt' : ($soldout ? 'Ausverkauft' : ($is_free ? 'Infos & Tickets' : 'Tickets sichern'));
            $dis   = ($cancelled || $soldout);
            if ($dis) {
                echo '<button class="btn btn-primary" disabled>' . esc_html($label) . '</button>';
            } else {
                echo '<a class="btn btn-primary" href="' . esc_url(self::buy_url($e)) . '">' . self::icon('ticket') . esc_html($label) . '</a>';
            }
            echo '</div>';
        }

        echo self::js();
        echo '</body></html>';
    }

    // ──────────────────────────────────────────
    //  Startseite
    // ──────────────────────────────────────────

    /** Event-Link; in der Vorschau mit ?kkapp=1, damit die ganze Tour App-Design bleibt. */
    private static function event_link($e, $preview) {
        $url = (string) ($e['url'] ?? '');
        if ($preview && $url !== '') $url .= (strpos($url, '?') !== false ? '&' : '?') . 'kkapp=1';
        return $url;
    }

    private static function presale_active($e) {
        return !empty($e['tickets_enabled']) && !self::is_cancelled($e) && !self::is_soldout($e);
    }

    /** Kompaktes Datum wie Fmt.dateCompact: „Fr., 2. Okt.“ */
    private static function compact_date(DateTime $dt) {
        return self::$WD_SHORT[(int) $dt->format('N')] . ', ' . (int) $dt->format('j') . '. '
            . self::$MON_SHORT[(int) $dt->format('n')];
    }

    /** Weiße Datums-Kachel wie KkDateTile: Wochentag / Tag / MONAT. */
    private static function date_tile($e) {
        $dt = self::dt($e['date_start'] ?? '', $e['time_start'] ?? '', '00:00');
        if (!$dt) {
            return '<div class="dtile"><span class="dwd"></span><span class="dday">–</span><span class="dmon">BALD</span></div>';
        }
        return '<div class="dtile"><span class="dwd">' . esc_html(self::$WD_SHORT[(int) $dt->format('N')]) . '</span>'
            . '<span class="dday">' . (int) $dt->format('j') . '</span>'
            . '<span class="dmon">' . esc_html(self::$MON_TILE[(int) $dt->format('n')]) . '</span></div>';
    }

    /** Highlight-Karte wie _HighlightCard: Flyer + Datums-Badge + Vorverkauf, Titel, Ort. */
    private static function highlight_card($e, $preview) {
        $link  = self::event_link($e, $preview);
        $img   = (string) ($e['thumbnail'] ?? $e['image'] ?? '');
        $sm    = (string) ($e['image_small'] ?? '');
        $title = (string) $e['title'];
        $addr  = trim((string) ($e['address'] ?? ''));
        $venue = trim((string) ($e['location'] ?? ''));
        $place = $addr !== '' ? $addr : $venue;
        $dt    = self::dt($e['date_start'] ?? '', $e['time_start'] ?? '', '00:00');
        $badge = $dt ? '<span class="hbadge">' . self::icon('calblank') . esc_html(self::compact_date($dt)) . '</span>' : '';
        $tag   = self::presale_active($e) ? '<span class="htag">' . self::icon('ticket') . 'Vorverkauf</span>' : '';
        $imgt  = $img !== ''
            ? '<img src="' . esc_url($img) . '" alt="' . esc_attr($title) . '" loading="lazy"'
                . ($sm !== '' ? ' style="background-image:url(' . esc_url($sm) . ')"' : '') . '>' : '';
        return '<a class="hcard" href="' . esc_url($link) . '"><div class="hcard-img">' . $imgt . $badge . $tag . '</div>'
            . '<div class="hcard-b"><div class="hcard-t">' . esc_html($title) . '</div>'
            . '<div class="hcard-m">' . self::icon('pin') . '<span>' . esc_html($place !== '' ? $place : 'Mehr erfahren') . '</span></div>'
            . '</div></a>';
    }

    /** Event-Zeile wie EventRow: Datums-Kachel + Karte (Flyer, Titel, Uhrzeit). */
    private static function event_row($e, $preview) {
        $link  = self::event_link($e, $preview);
        $img   = (string) ($e['image_small'] ?? $e['thumbnail'] ?? '');
        $title = (string) $e['title'];
        $dt    = self::dt($e['date_start'] ?? '', $e['time_start'] ?? '', '00:00');
        $hasTime = trim((string) ($e['time_start'] ?? '')) !== '';
        if (self::is_cancelled($e))      $meta = ['warn', 'Abgesagt'];
        elseif (self::is_soldout($e))    $meta = ['warn', 'Ausverkauft'];
        else                             $meta = ['pin', ($dt && $hasTime) ? $dt->format('H:i') : ''];
        $tag = self::presale_active($e) ? '<span class="rowtag">' . self::icon('ticket') . 'Vorverkauf</span>' : '';
        $imgt = $img !== '' ? '<img src="' . esc_url($img) . '" alt="' . esc_attr($title) . '" loading="lazy">' : '';
        $metah = $meta[1] !== '' ? '<div class="row-m ' . ($meta[0] === 'warn' ? 'row-warn' : '') . '">'
            . self::icon($meta[0]) . '<span>' . esc_html($meta[1]) . '</span></div>' : '';
        return '<a class="erow" href="' . esc_url($link) . '">' . self::date_tile($e)
            . '<div class="erow-c"><div class="erow-img">' . $imgt . $tag . '</div>'
            . '<div class="erow-b"><div class="erow-t">' . esc_html($title) . '</div>' . $metah . '</div></div></a>';
    }

    /** Kachel-Aktion → Web-Ziel. WordPress-Seiten direkt, App-Funktionen in die echte App. */
    private static function tile_href($action, $preview) {
        $a = strtolower(trim((string) $action));
        if (strpos($a, 'url:') === 0) return substr($action, 4);
        if (strpos($a, 'page:') === 0) {
            $slug = substr($action, 5);
            $pg = get_page_by_path($slug);
            return $pg ? get_permalink($pg) : home_url('/' . $slug . '/');
        }
        return home_url('/app-preview/');
    }

    /** Kacheln + Hashtag: aus _tix_app_config (falls gesetzt), sonst App-Defaults. */
    private static function home_content() {
        $cfg = get_option('_tix_app_config');
        if (is_string($cfg)) $cfg = json_decode($cfg, true);
        $tiles = [];
        $hashtag = '#kitchengehtimmer';
        if (is_array($cfg)) {
            $home = isset($cfg['home']) && is_array($cfg['home']) ? $cfg['home'] : [];
            if (!empty($home['hashtag'])) $hashtag = (string) $home['hashtag'];
            if (!empty($home['tiles']) && is_array($home['tiles'])) {
                foreach ($home['tiles'] as $t) {
                    if (!is_array($t)) continue;
                    $tiles[] = [
                        'icon'   => (string) ($t['icon'] ?? 'link'),
                        'label'  => (string) ($t['label'] ?? ''),
                        'action' => (string) ($t['action'] ?? ''),
                    ];
                }
            }
        }
        if (!$tiles) {
            $tiles = [
                ['icon' => 'calendar', 'label' => 'Events',        'action' => 'tab:events'],
                ['icon' => 'ticket',   'label' => 'Vorverkauf',    'action' => 'presale'],
                ['icon' => 'heart',    'label' => 'Musikwunsch',   'action' => 'page:musikwunsch'],
                ['icon' => 'gift',     'label' => 'Gutscheine',    'action' => 'giftcards'],
                ['icon' => 'info',     'label' => 'Ü16-Partys',    'action' => 'page:u16'],
                ['icon' => 'doc',      'label' => 'Muttizettel',   'action' => 'muttizettel'],
                ['icon' => 'loyalty',  'label' => 'Prämien',       'action' => 'loyalty'],
                ['icon' => 'help',     'label' => 'FAQ',           'action' => 'page:faq'],
                ['icon' => 'ticket',   'label' => 'Meine Tickets', 'action' => 'tab:tickets'],
                ['icon' => 'chat',     'label' => 'Kontakt',       'action' => 'page:kontakt'],
            ];
        }
        return ['tiles' => $tiles, 'hashtag' => $hashtag];
    }

    private static function section_title($title, $link_label = '', $link_href = '') {
        $a = ($link_label !== '' && $link_href !== '')
            ? '<a class="seclink" href="' . esc_url($link_href) . '">' . esc_html($link_label) . '</a>' : '';
        return '<div class="sectitle"><h2>' . esc_html($title) . '</h2>' . $a . '</div>';
    }

    private static function render_home($indexable, $preview) {
        $events = self::upcoming_events(30);
        $content = self::home_content();
        $site_name = get_bloginfo('name') ?: 'KitchenKlub';
        $tagline   = trim((string) get_bloginfo('description'));
        $home_url  = home_url('/');
        // „Alle Events“ / Sektions-Links: vorerst in die echte App (Events-Tab).
        $events_href = home_url('/app-preview/');
        $title = $site_name . ($tagline !== '' ? ' – ' . $tagline : ' – Club, Events & Tickets');
        $desc  = $tagline !== '' ? $tagline : 'Club-Events, Partys und Tickets bei KitchenKlub.';
        if ($events) {
            $names = array_map(function ($e) { return (string) $e['title']; }, array_slice($events, 0, 3));
            $desc = 'Kommende Events: ' . implode(' · ', $names) . '. Tickets & Infos bei KitchenKlub.';
        }
        $desc = mb_substr($desc, 0, 200);
        $og_img = '';
        foreach ($events as $e) { if (!empty($e['image'])) { $og_img = (string) $e['image']; break; } }

        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // ── HEAD ──
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<meta name="description" content="' . esc_attr($desc) . '">';
        echo '<link rel="canonical" href="' . esc_url($home_url) . '">';
        if (!$indexable) echo '<meta name="robots" content="noindex,follow">';
        echo '<meta name="theme-color" content="#4A4A4A">';
        echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . esc_attr($site_name) . '">';
        echo '<meta property="og:title" content="' . esc_attr($title) . '">';
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">';
        echo '<meta property="og:url" content="' . esc_url($home_url) . '">';
        if ($og_img !== '') { echo '<meta property="og:image" content="' . esc_url($og_img) . '">'; echo '<meta name="twitter:card" content="summary_large_image">'; }
        echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
        echo '<style>' . self::css() . '</style></head><body>';

        // ── Kopf (Logo + Glocke + Konto, wie KkHeader) ──
        echo '<header class="bar">';
        echo '<a class="brand" href="' . esc_url($home_url) . '" aria-label="KitchenKlub">';
        if ($logo_url !== '') echo '<img src="' . esc_url($logo_url) . '" alt="KitchenKlub">';
        else echo '<span class="brandtxt">KITCHENKLUB</span>';
        echo '</a>';
        echo '<div class="bar-actions">';
        echo '<a class="hact" href="' . esc_url(home_url('/app-preview/')) . '" aria-label="Mitteilungen">' . self::icon('bell') . '</a>';
        echo '<a class="hact" href="' . esc_url(home_url('/app-preview/')) . '" aria-label="Konto">' . self::icon('user') . '</a>';
        echo '</div></header>';

        echo '<main class="wrap home">';

        // ── Highlights (Karussell) ──
        echo '<section class="block">';
        echo self::section_title('Highlights', 'Alle Events', $events_href);
        if ($events) {
            echo '<div class="carousel" id="hlc"><div class="track">';
            foreach ($events as $e) echo self::highlight_card($e, $preview);
            echo '<a class="moretile" href="' . esc_url($events_href) . '"><span class="more-ic">' . self::icon('arrow') . '</span><span class="more-l">Alle Events</span></a>';
            echo '</div></div>';
            if (count($events) > 1) {
                echo '<div class="dots" id="hld">';
                for ($i = 0; $i < count($events); $i++) echo '<span class="dot' . ($i === 0 ? ' on' : '') . '"></span>';
                echo '</div>';
            }
        } else {
            echo '<div class="hl-empty">' . self::brand_mark() . '</div>';
        }
        echo '</section>';

        // ── Nächste Partys (2 Zeilen) ──
        $now = time();
        $next = array_slice($events, 0, 2);
        if ($next) {
            echo '<section class="block">';
            echo self::section_title('Nächste Partys', 'Alle anzeigen', $events_href);
            echo '<div class="rows">';
            foreach ($next as $e) echo self::event_row($e, $preview);
            echo '</div></section>';
        }

        // ── Hashtag ──
        if ($content['hashtag'] !== '') {
            echo '<div class="hashtag">' . esc_html($content['hashtag']) . '</div>';
        }

        // ── Kacheln ──
        echo '<section class="block tiles">';
        foreach ($content['tiles'] as $t) {
            if (($t['label'] ?? '') === '') continue;
            echo '<a class="tile" href="' . esc_url(self::tile_href($t['action'], $preview)) . '">'
                . '<span class="tile-ic">' . self::icon((string) $t['icon']) . '</span>'
                . '<span class="tile-l">' . esc_html($t['label']) . '</span>'
                . '<span class="tile-cv">' . self::icon('caret') . '</span></a>';
        }
        echo '</section>';

        // ── Tickets im Vorverkauf ──
        $presale = array_values(array_filter($events, function ($e) { return self::presale_active($e); }));
        if ($presale) {
            echo '<section class="block">';
            echo self::section_title('Tickets im Vorverkauf', 'Alle anzeigen', $events_href);
            echo '<div class="rows">';
            foreach ($presale as $e) echo self::event_row($e, $preview);
            echo '</div></section>';
        }

        // ── Footer (dezent; rechtlich nötig, in der App unter „Konto“) ──
        echo '<footer class="foot">';
        echo '<a class="foot-ig" href="https://www.instagram.com/kitchen.klub/" target="_blank" rel="noopener">' . self::icon('ig') . '@kitchen.klub</a>';
        $legal = [];
        $imp = get_page_by_path('impressum');
        if ($imp) $legal[] = '<a href="' . esc_url(get_permalink($imp)) . '">Impressum</a>';
        $priv = get_privacy_policy_url();
        if ($priv) $legal[] = '<a href="' . esc_url($priv) . '">Datenschutz</a>';
        if ($legal) echo '<div class="foot-legal">' . implode('<span class="dot">·</span>', $legal) . '</div>';
        echo '<div class="foot-c">© ' . esc_html(date('Y')) . ' ' . esc_html($site_name) . '</div>';
        echo '</footer>';

        echo '</main>' . self::home_js() . '</body></html>';
    }

    private static function brand_mark() {
        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
        return $logo_url ? '<img src="' . esc_url($logo_url) . '" alt="KitchenKlub" style="height:30px;opacity:.7">' : '<span class="brandtxt">KITCHENKLUB</span>';
    }

    // ──────────────────────────────────────────
    //  Bausteine
    // ──────────────────────────────────────────

    private static function info_row($label, $value) {
        return '<div class="inforow"><span class="k">' . esc_html($label) . '</span><span class="v">' . esc_html($value) . '</span></div>';
    }

    private static function section($title, $html) {
        $html = (string) $html;
        if (trim(wp_strip_all_tags($html)) === '') return;
        echo '<section class="sec"><h2>' . esc_html($title) . '</h2><div class="rich">' . $html . '</div></section>';
    }

    private static function json_ld($e, $start, $end, $url, $flyer, $venue, $address, $price_from, $is_free) {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Event',
            'name'     => (string) $e['title'],
            'url'      => $url,
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ];
        if ($start) $data['startDate'] = $start->format('c');
        if ($end)   $data['endDate']   = $end->format('c');
        if ($flyer !== '') $data['image'] = [$flyer];
        if ($venue !== '' || $address !== '') {
            $data['location'] = array_filter([
                '@type'   => 'Place',
                'name'    => $venue !== '' ? $venue : 'KitchenKlub',
                'address' => $address !== '' ? $address : null,
            ]);
        }
        if (!empty($e['tickets_enabled'])) {
            $data['offers'] = [
                '@type' => 'Offer',
                'url'   => $url,
                'price' => $is_free ? '0' : (string) ($price_from ?? '0'),
                'priceCurrency' => 'EUR',
                'availability' => self::is_soldout($e) ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
            ];
        }
        echo '<script type="application/ld+json">' . wp_json_encode($data) . '</script>';
    }

    /** Icon = echtes Phosphor-Regular-SVG (identisch zur App), viewBox 256, fill.
     *  $name akzeptiert Phosphor-Namen (map-pin) oder Kurz-Aliasse (pin, cal …). */
    private static function icon($name) {
        static $alias = [
            'pin' => 'map-pin', 'cal' => 'calendar-plus', 'share' => 'export',
            'wa' => 'whatsapp-logo', 'fb' => 'facebook-logo', 'mail' => 'envelope-simple',
            'nav' => 'navigation-arrow', 'ig' => 'instagram-logo', 'caret' => 'caret-right',
            'arrow' => 'arrow-right', 'warn' => 'warning-circle', 'caldot' => 'calendar-dots',
            'calblank' => 'calendar-blank', 'doc' => 'file-text', 'loyalty' => 'seal-check',
            'help' => 'question', 'chat' => 'chat-circle', 'calendar' => 'calendar-dots',
            'music' => 'heart', 'faq' => 'question', 'contact' => 'chat-circle',
            'document' => 'file-text', 'muttizettel' => 'file-text', 'praemien' => 'seal-check',
            'account' => 'user', 'konto' => 'user',
        ];
        static $map = null;
        if ($map === null) {
            $f = __DIR__ . '/tix-app-web-icons.php';
            $map = is_readable($f) ? include $f : [];
            if (!is_array($map)) $map = [];
        }
        $key = isset($alias[$name]) ? $alias[$name] : $name;
        $p = isset($map[$key]) ? $map[$key] : (isset($map['arrow-up-right']) ? $map['arrow-up-right'] : '');
        return '<svg viewBox="0 0 256 256" fill="currentColor" aria-hidden="true">' . $p . '</svg>';
    }

    // ──────────────────────────────────────────
    //  CSS (Design-Tokens der App)
    // ──────────────────────────────────────────

    private static function css() {
        return <<<CSS
:root{--bg:#4A4A4A;--card:#3B3B3B;--night:#131020;--hair:rgba(255,255,255,.12);--hairS:rgba(255,255,255,.25);--body:rgba(255,255,255,.8);--sec:rgba(255,255,255,.7);--muted:rgba(255,255,255,.5);--signal:#E8445A;--teal:#14B8A6}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--bg);color:#fff;font-family:'Manrope',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif,'Apple Color Emoji','Segoe UI Emoji';-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;font-size:14px;line-height:1.5;padding-bottom:env(safe-area-inset-bottom)}
a{color:inherit;text-decoration:none}
img{display:block;max-width:100%}
.bar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 20px;padding-top:calc(10px + env(safe-area-inset-top));background:rgba(74,74,74,.86);backdrop-filter:saturate(1.2) blur(10px);-webkit-backdrop-filter:saturate(1.2) blur(10px);border-bottom:1px solid var(--hair)}
.brand img{height:34px;width:auto}
.brandtxt{font-weight:800;letter-spacing:.5px;font-size:16px}
.wrap{max-width:640px;margin:0 auto;padding:16px 20px 0}
.cbtn{width:44px;height:44px;flex:0 0 44px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;border:0;background:var(--card);color:#fff;cursor:pointer;transition:background .15s}
.cbtn:hover{background:var(--hair)}
.cbtn svg{width:20px;height:20px}
.flyer{border-radius:16px;overflow:hidden;aspect-ratio:1.85;background:var(--card)}
.flyer img{width:100%;height:100%;object-fit:cover;background-size:cover;background-position:center}
.title{font-size:22px;font-weight:700;line-height:1.25;letter-spacing:-.4px;margin:16px 0 8px}
.place{display:flex;align-items:flex-start;gap:5px;color:var(--sec);font-size:13.5px;margin:0}
.place svg{width:15px;height:15px;flex:0 0 15px;margin-top:2px;color:var(--sec)}
.place span{color:var(--sec)}
.badges{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.badge{font-size:11px;font-weight:600;line-height:1;padding:6px 9px;border-radius:8px}
.b-light{background:#fff;color:var(--night)}
.b-signal{background:var(--signal);color:#fff}
.b-teal{background:var(--teal);color:#fff}
.b-presale{background:var(--signal);color:#fff}
.card{background:var(--card);border:1px solid var(--hair);border-radius:16px}
.infos{margin-top:24px;padding:4px 16px}
.inforow{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid var(--hair)}
.inforow:last-child{border-bottom:0}
.inforow .k{color:var(--sec);font-size:13px}
.inforow .v{font-weight:600;text-align:right}
.countdown{margin-top:16px;border-radius:16px;padding:16px;text-align:center}
.cd-live{background:#fff;color:var(--night)}
.cd-live .cd-lbl{color:#5C5A57}
.cd-soon{background:var(--card);border:1px solid var(--hair)}
.cd-past{background:rgba(59,59,59,.5);border:1px solid var(--hair);color:var(--muted)}
.cd-lbl{font-size:12px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;color:var(--muted)}
.cd-time{font-size:26px;font-weight:800;letter-spacing:-.5px;margin-top:6px;font-variant-numeric:tabular-nums}
.cd-live .cd-time{font-size:16px;font-weight:700}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:56px;border-radius:12px;font-size:16px;font-weight:600;cursor:pointer;border:0;padding:14px 20px}
.btn svg{width:20px;height:20px}
.btn-outline{background:transparent;border:1.2px solid var(--hairS);color:#fff;margin-top:16px}
.btn-outline:hover{background:var(--hair)}
.btn-primary{background:#fff;color:var(--night)}
.btn-primary[disabled]{background:rgba(255,255,255,.35);color:rgba(19,16,32,.6);cursor:default}
.sec{margin-top:24px}
.sec h2{font-size:18px;font-weight:700;letter-spacing:-.2px;margin:0 0 12px}
.sharerow{display:flex;gap:10px}
.rich{color:var(--body);font-size:14px;line-height:1.6}
.rich p{margin:0 0 10px}.rich p:last-child{margin:0}
.rich a{color:#fff;text-decoration:underline}
.rich ul,.rich ol{margin:0 0 10px;padding-left:20px}
.rich strong,.rich b{color:#fff}
.rich img{border-radius:12px;margin:8px 0}
.gallery{display:flex;gap:10px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:4px;scrollbar-width:none}
.gallery::-webkit-scrollbar{display:none}
.gimg{flex:0 0 auto;width:200px;height:150px;border-radius:12px;overflow:hidden;background:var(--card)}
.gimg img{width:100%;height:100%;object-fit:cover}
.catlist{padding:4px 16px}
.catrow{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 0;border-bottom:1px solid var(--hair)}
.catrow:last-child{border-bottom:0}
.catrow.out{opacity:.55}
.catname{font-weight:600;font-size:15px}
.catname .tag{display:inline-block;font-size:10px;font-weight:700;color:var(--signal);border:1px solid var(--signal);border-radius:6px;padding:2px 6px;margin-left:6px;vertical-align:middle}
.catdesc{color:var(--sec);font-size:12.5px;margin-top:3px}
.catprice{text-align:right;white-space:nowrap}
.catprice .now{font-weight:700;font-size:15px}
.catprice .old{color:var(--muted);text-decoration:line-through;font-size:12.5px}
.catprice .soldout{color:var(--signal);font-weight:600;font-size:13px}
.row{display:flex;align-items:center;gap:12px;padding:14px 16px}
.iconbox{width:40px;height:40px;flex:0 0 40px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;background:var(--hair)}
.iconbox svg{width:20px;height:20px}
.rowlabel{flex:1;display:flex;flex-direction:column;font-weight:500}
.rowsub{color:var(--sec);font-size:12.5px;font-weight:400;margin-top:2px}
.row>svg{width:18px;height:18px;color:#fff}
.footspace{height:28px}
body.has-cta .footspace{height:104px}
.cta{position:fixed;left:0;right:0;bottom:0;z-index:30;display:flex;align-items:center;gap:16px;max-width:640px;margin:0 auto;padding:14px 20px;padding-bottom:calc(14px + env(safe-area-inset-bottom));background:linear-gradient(to bottom,rgba(74,74,74,0),var(--bg) 30%)}
.ctaprice{display:flex;flex-direction:column;flex:0 0 auto}
.ctaprice .lbl{color:var(--sec);font-size:12.5px}
.ctaprice .val{font-size:18px;font-weight:700}
.cta .btn{flex:1}
@media(min-width:641px){.cta{border-radius:16px 16px 0 0}}
/* ── Startseite (App-getreu) ── */
.wrap.home{padding-top:4px;padding-bottom:0}
.bar-actions{display:flex;align-items:center;gap:10px}
.hact{width:36px;height:36px;flex:0 0 36px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:var(--card);color:#fff}
.hact svg{width:17px;height:17px}
.block{margin-top:24px}
.block:first-child{margin-top:0}
.sectitle{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.sectitle h2{font-size:18px;font-weight:700;letter-spacing:-.2px;margin:0}
.seclink{color:var(--sec);font-size:14px;font-weight:500;white-space:nowrap}
.carousel{overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;margin-right:-20px;padding-right:20px}
.carousel::-webkit-scrollbar{display:none}
.track{display:flex;gap:12px;align-items:stretch}
.hcard{flex:0 0 74%;max-width:340px;min-width:210px;scroll-snap-align:start;background:var(--card);border-radius:16px;padding:8px;display:block}
.hcard-img{position:relative;border-radius:12px;overflow:hidden;aspect-ratio:1.85;background:var(--card)}
.hcard-img img{width:100%;height:100%;object-fit:cover;background-size:cover;background-position:center}
.hbadge,.htag{position:absolute;top:10px;display:inline-flex;align-items:center;gap:5px;border-radius:8px;padding:4px 8px;font-size:11px;font-weight:600;line-height:1}
.hbadge{left:10px;background:#fff;color:var(--night)}
.htag{right:10px;background:var(--signal);color:#fff}
.hbadge svg,.htag svg{width:12px;height:12px}
.hcard-b{padding:10px 6px 4px}
.hcard-t{font-size:15px;font-weight:600;letter-spacing:-.1px;line-height:1.3;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hcard-m{display:flex;align-items:center;gap:4px;margin-top:4px;color:var(--sec);font-size:12.5px}
.hcard-m svg{width:13px;height:13px;flex:0 0 13px;color:var(--sec)}
.hcard-m span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.moretile{flex:0 0 128px;scroll-snap-align:start;background:var(--card);border:1px solid var(--hair);border-radius:16px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:0 6px;text-align:center}
.more-ic{width:40px;height:40px;border-radius:20px;background:#fff;display:flex;align-items:center;justify-content:center}
.more-ic svg{width:20px;height:20px;color:var(--night)}
.more-l{font-size:12.5px;font-weight:700;color:#fff}
.dots{display:flex;justify-content:center;gap:4px;margin-top:12px}
.dot{width:16px;height:4px;border-radius:20px;background:rgba(255,255,255,.38);transition:width .25s,background .25s}
.dot.on{width:30px;background:#fff}
.hl-empty{aspect-ratio:1.85;background:var(--card);border:1px solid var(--hair);border-radius:16px;display:flex;align-items:center;justify-content:center}
.rows{display:flex;flex-direction:column;gap:12px}
.erow{display:flex;align-items:stretch;gap:10px}
.dtile{flex:0 0 46px;width:46px;background:#fff;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1.1}
.dwd{font-size:11px;font-weight:600;color:#5C5A57}
.dday{font-size:18px;font-weight:700;color:var(--night);margin:1px 0}
.dmon{font-size:10.5px;font-weight:700;letter-spacing:.6px;color:#5C5A57}
.erow-c{flex:1;min-width:0;background:var(--card);border-radius:16px;display:flex;align-items:center;overflow:hidden}
.erow-img{padding:8px;flex:0 0 auto;position:relative}
.erow-img img{height:66px;aspect-ratio:1.9;width:auto;object-fit:cover;border-radius:8px;display:block}
.rowtag{position:absolute;left:12px;bottom:12px;display:inline-flex;align-items:center;gap:3px;background:var(--signal);color:#fff;border-radius:5px;padding:3px 6px;font-size:9.5px;font-weight:600;line-height:1}
.rowtag svg{width:10px;height:10px}
.erow-b{flex:1;min-width:0;padding:8px 12px 8px 4px}
.erow-t{font-size:15px;font-weight:600;line-height:1.25;color:#fff;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.row-m{display:flex;align-items:center;gap:4px;margin-top:3px;color:var(--sec);font-size:12px}
.row-m svg{width:12px;height:12px;flex:0 0 12px;color:var(--sec)}
.row-m span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row-warn{color:#F2899A}.row-warn svg{color:#F2899A}
.hashtag{text-align:center;color:var(--sec);font-size:16px;font-weight:600;padding:28px 0 14px}
.tiles{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:24px}
.tile{display:flex;align-items:center;gap:10px;background:var(--card);border-radius:12px;padding:9px 12px;min-height:52px}
.tile-ic{flex:0 0 20px;display:flex}.tile-ic svg{width:20px;height:20px;color:#fff}
.tile-l{flex:1;min-width:0;font-size:14px;font-weight:500;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tile-cv{flex:0 0 auto;display:flex}.tile-cv svg{width:14px;height:14px;color:var(--muted)}
.foot{margin-top:28px;padding-top:20px;border-top:1px solid var(--hair);text-align:center;padding-bottom:24px}
.foot-ig{display:inline-flex;align-items:center;gap:8px;font-weight:600;font-size:14px;color:#fff}
.foot-ig svg{width:20px;height:20px}
.foot-legal{margin-top:12px;color:var(--sec);font-size:13px}
.foot-legal a{color:var(--sec)}.foot-legal a:hover{color:#fff}
.foot-legal .dot{margin:0 8px;color:var(--muted)}
.foot-c{margin-top:12px;color:var(--muted);font-size:12px}
@media(min-width:700px){.hcard{flex:0 0 calc((100% - 24px)/3)}}
CSS;
    }

    // ──────────────────────────────────────────
    //  JS (Countdown, Teilen, Kopieren)
    // ──────────────────────────────────────────

    private static function js() {
        return <<<'JS'
<script>
(function(){
  // Countdown
  var el=document.getElementById('cd');
  if(el){
    var s=new Date(el.dataset.start).getTime();
    var e=el.dataset.end?new Date(el.dataset.end).getTime():s+4*3600e3;
    function fmt2(n){return(n<10?'0':'')+n;}
    function tick(){
      var now=Date.now();
      if(now<s){
        var d=s-now,days=Math.floor(d/864e5),h=Math.floor(d%864e5/36e5),m=Math.floor(d%36e5/6e4),sec=Math.floor(d%6e4/1e3);
        el.className='countdown cd-soon';
        el.innerHTML='<div class="cd-lbl">Noch bis zum Einlass</div><div class="cd-time">'+(days>0?days+'T ':'')+fmt2(h)+':'+fmt2(m)+':'+fmt2(sec)+'</div>';
      }else if(now<e){
        var st=new Date(s),et=new Date(e);
        function hm(x){return fmt2(x.getHours())+':'+fmt2(x.getMinutes());}
        el.className='countdown cd-live';
        el.innerHTML='<div class="cd-lbl">Läuft gerade</div><div class="cd-time">seit '+hm(st)+' Uhr · bis '+hm(et)+' Uhr</div>';
      }else{
        el.className='countdown cd-past';
        el.innerHTML='<div class="cd-lbl">Vorbei</div><div class="cd-time" style="font-size:16px">Diese Veranstaltung ist beendet</div>';
        clearInterval(iv);
      }
    }
    tick();var iv=setInterval(tick,1000);
  }
  // Nativer Teilen-Dialog
  document.querySelectorAll('.share-native').forEach(function(b){
    b.addEventListener('click',function(){
      var url=b.dataset.url,title=b.dataset.title||document.title;
      if(navigator.share){navigator.share({title:title,url:url}).catch(function(){});}
      else{navigator.clipboard&&navigator.clipboard.writeText(url);b.classList.add('done');}
    });
  });
  // Link kopieren
  document.querySelectorAll('.copy-link').forEach(function(b){
    b.addEventListener('click',function(){
      var url=b.dataset.url;
      if(navigator.clipboard){navigator.clipboard.writeText(url).then(function(){
        var o=b.innerHTML;b.textContent='✓';setTimeout(function(){b.innerHTML=o;},1200);
      });}
    });
  });
})();
</script>
JS;
    }

    /** Karussell: aktive Punkte beim Scrollen + Auto-Weiterblättern (7 s, wie App). */
    private static function home_js() {
        return <<<'JS'
<script>
(function(){
  var c=document.getElementById('hlc'); if(!c) return;
  var track=c.querySelector('.track'); var dots=document.getElementById('hld');
  function ext(){ var f=track&&track.children[0]; return f?f.getBoundingClientRect().width+12:c.clientWidth; }
  function idx(){ return Math.round(c.scrollLeft/ext()); }
  function paint(){ if(!dots) return; var i=idx(),d=dots.children; for(var k=0;k<d.length;k++){ d[k].className='dot'+(k===i?' on':''); } }
  var t; c.addEventListener('scroll',function(){ clearTimeout(t); t=setTimeout(paint,80); });
  var slides=dots?dots.children.length:0;
  if(slides>1){ setInterval(function(){ var n=(idx()+1)%slides; c.scrollTo({left:Math.round(n*ext()),behavior:'smooth'}); },7000); }
})();
</script>
JS;
    }
}
