<?php
/**
 * App-Look für Breakdance-Seiten: Bausteine im Design der evendis-App.
 *
 * Die Seiten selbst (Startseite, Event-Seite, Veranstalter-Seite) sind Breakdance-Vorlagen
 * (assets/breakdance/app-look-*.json, einzuspielen mit `wp tixomat app-look install`). Diese
 * Klasse liefert nur, was Breakdance nicht selbst berechnen kann:
 *   [tix_app key="…"]          Einzelwerte für Breakdance-Textbausteine (dynamisches Feld „Shortcode“):
 *                               Titel ohne Emojis, Datums-Kachel, Uhrzeit, Ort, Pillen-Texte …
 *   [tix_app_search]           Suche + Chips „Heute · Morgen · Wochenende“ (filtert [tix_app_list filter="1"])
 *   [tix_app_categories]       Kategorien-Kacheln (filtern ebenfalls)
 *   [tix_app_cards]            große Event-Karten („Demnächst“)
 *   [tix_app_list]             Event-Zeilen (Datums-Kachel + Karte); style="compact" wie auf der Veranstalter-Seite der App
 *   [tix_app_related]          „Das könnte dir auch gefallen“ (kleine Karten, gleiche Sparte)
 *   [tix_app_live]             „Jetzt & gleich“: läuft gerade oder beginnt in den nächsten 3 Std.
 *   [tix_app_organizers]       „Veranstalter entdecken“: Kacheln mit Banner, Logo, Folgen
 *   [tix_app_lineup]           Line-up als Reihe runder Initialen-Kreise (ArtistStrip)
 *   [tix_app_heart]            Herz (Merken) für das Event der Seite
 *   [tix_app_follow]           Button „Folgen“ / „Du folgst“ (gleiche Liste wie in der App)
 * Ohne diese Shortcodes auf einer Seite: keine Assets, keine geänderte Ausgabe.
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Look {

    const WD      = ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'];
    const WD_LONG = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    const MON     = ['JAN', 'FEB', 'MÄR', 'APR', 'MAI', 'JUN', 'JUL', 'AUG', 'SEP', 'OKT', 'NOV', 'DEZ'];
    const MON_L   = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    /** Breakdance-Vorlagen des App-Looks: Datei => [Titel, Vorlagentyp] */
    const TEMPLATES = [
        'app-look-start.json'     => ['Startseite (App-Look)', 'front-page'],
        'app-look-event.json'     => ['Einzel-Event (App-Look)', 'event'],
        'app-look-organizer.json' => ['Veranstalter (App-Look)', 'tix_organizer'],
        'app-look-page.json'      => ['Seite (App-Look)', 'page'],
    ];

    /** Shortcodes, deren Seiten die Vorlage „Seite (App-Look)“ bekommen (Kasse, Konto/Tickets, Support) */
    const APP_PAGES = ['tix_checkout', 'tix_account', 'tix_my_tickets', 'tix_support'];
    const OPT_REPLACED = 'tix_app_look_replaced';
    const META_HASH    = '_tix_app_look_md5';

    /** md5 früher ausgelieferter Vorlagen (1.38.371); unverändert = darf überschrieben werden */
    const SHIPPED = [
        '614d0b872e77ec7c349fac22059476a2', // Startseite 1.38.371
        'e62e7a6f57639db3e99df904ef86b066', // Einzel-Event 1.38.371
        '90c2e6aa043426f86c4e46d3cdb7e15f', // Veranstalter 1.38.371
    ];

    /** Lucide-Icons (ISC), Strich 2 */
    const ICONS = [
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'pin'      => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'ticket'   => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2m0 10v2m0-8v2"/>',
        'chevron'  => '<path d="m9 18 6-6-6-6"/>',
        'plus'     => '<path d="M5 12h14M12 5v14"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'play'     => '<polygon points="6 3 20 12 6 21 6 3"/>',
        // Sparten
        'sparkles' => '<path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0L14.06 8.5A2 2 0 0 0 15.5 9.94l6.13 1.58a.5.5 0 0 1 0 .96L15.5 14.06a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0z"/><path d="M20 3v4m2-2h-4"/>',
        'party'    => '<path d="M5.8 11.3 2 22l10.7-3.79M4 3h.01M22 8h.01M15 2h.01M22 20h.01"/><path d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L14 10m8 3-.82-.33c-.86-.34-1.82.2-1.98 1.11c-.11.7-.72 1.22-1.43 1.22H17M11 2l.33.82c.34.86-.2 1.82-1.11 1.98C9.52 4.9 9 5.52 9 6.23V7"/><path d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2"/>',
        'chat'     => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'music'    => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'star'     => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.12 2.12 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.12 2.12 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.12 2.12 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.12 2.12 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.12 2.12 0 0 0 1.597-1.16z"/>',
        'trophy'   => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6m12 5h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22m7-7.34V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'food'     => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20m14-7V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
        'cap'      => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        'tent'     => '<path d="M3.5 21 14 3m6.5 18L10 3m5.5 18L12 15l-3.5 6M2 21h20"/>',
    ];

    /** Verläufe der App-Kacheln (evGradients) */
    const GRADIENTS = [
        ['#46D2FD', '#5351F0'],
        ['#FFB340', '#FF6961'],
        ['#7FC4D6', '#4A879A'],
        ['#FFB38A', '#E74459'],
        ['#3B3450', '#151221'],
        ['#30DB5B', '#0D9488'],
    ];

    public static function init() {
        add_shortcode('tix_app', [__CLASS__, 'sc_value']);
        add_shortcode('tix_app_search', [__CLASS__, 'sc_search']);
        add_shortcode('tix_app_categories', [__CLASS__, 'sc_categories']);
        add_shortcode('tix_app_cards', [__CLASS__, 'sc_cards']);
        add_shortcode('tix_app_list', [__CLASS__, 'sc_list']);
        add_shortcode('tix_app_follow', [__CLASS__, 'sc_follow']);
        add_shortcode('tix_app_lineup', [__CLASS__, 'sc_lineup']);
        add_shortcode('tix_app_heart', [__CLASS__, 'sc_heart']);
        add_shortcode('tix_app_related', [__CLASS__, 'sc_related']);
        add_shortcode('tix_app_live', [__CLASS__, 'sc_live']);
        add_shortcode('tix_app_organizers', [__CLASS__, 'sc_organizers']);
        add_action('wp_ajax_tix_app_follow', [__CLASS__, 'ajax_follow']);
        add_action('wp_ajax_nopriv_tix_app_follow', [__CLASS__, 'ajax_follow']);
        // Nach der Anmeldung auf der Kontoseite ([tix_account]) zurück zum Veranstalter (nur mit ?tix_back=…)
        add_filter('login_form_middle', [__CLASS__, 'login_back_field'], 10, 2);
        add_filter('login_redirect', [__CLASS__, 'login_back_redirect'], 20, 3);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('tixomat app-look', [__CLASS__, 'cli']);
        }
    }

    // ── Grundlagen ──

    /** Stylesheet + Skript beim ersten Baustein; nach wp_head direkt ausgeben (Breakdance rendert spät). */
    public static function assets() {
        static $done = false;
        if ($done || is_admin()) return;
        $done = true;
        wp_register_style('tix-app-look', TIXOMAT_URL . 'assets/css/app-look.css', [], TIXOMAT_VERSION);
        if (did_action('wp_head')) wp_print_styles('tix-app-look');
        else wp_enqueue_style('tix-app-look');
        wp_enqueue_script('tix-app-look', TIXOMAT_URL . 'assets/js/app-look.js', [], TIXOMAT_VERSION, true);
        wp_localize_script('tix-app-look', 'tixAppLook', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tix_app_follow'),
            'today'   => current_time('Y-m-d'),
        ]);
    }

    /** Merkliste wie die Karten von [tix_events] (event-cards.js, gleiche Nonce). */
    private static function enqueue_cards() {
        wp_enqueue_script('tix-event-cards', TIXOMAT_URL . 'assets/js/event-cards.js', [], TIXOMAT_VERSION, true);
        wp_localize_script('tix-event-cards', 'tixCards', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('tix_cards_nonce'),
            'isLoggedIn' => is_user_logged_in(),
        ]);
    }

    public static function icon($name, $class = 'evs-ico') {
        return '<svg class="' . esc_attr($class) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (self::ICONS[$name] ?? self::ICONS['star']) . '</svg>';
    }

    /** Emojis (Bildzeichen, Variationen, Flaggen) aus einem Titel entfernen – wie die App. */
    public static function clean_title($t) {
        $t = html_entity_decode((string) $t, ENT_QUOTES, 'UTF-8');
        $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B05}-\x{2B55}\x{231A}\x{231B}\x{23E9}-\x{23FA}\x{2934}\x{2935}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F1E6}-\x{1F1FF}\x{E0020}-\x{E007F}\x{FE0E}\x{FE0F}\x{200D}\x{20E3}]+/u', ' ', $t);
        return trim(preg_replace('/\s{2,}/u', ' ', (string) $t));
    }

    // ── Event-Daten ──

    /** Event im App-Format plus Anzeigewerte; null = nicht öffentlich. */
    public static function data($id) {
        static $cache = [];
        $id = intval($id);
        if (array_key_exists($id, $cache)) return $cache[$id];
        $p = class_exists('TIX_Public_Events') ? TIX_Public_Events::payload($id) : null;
        if (!$p) return $cache[$id] = null;

        $start = $p['date_start'] !== '' ? (strtotime($p['date_start'] . ' ' . ($p['time_start'] !== '' ? $p['time_start'] : '00:00')) ?: 0) : 0;
        $end = 0;
        if ($start && ($p['date_end'] !== '' || $p['time_end'] !== '')) {
            $end = strtotime(($p['date_end'] !== '' ? $p['date_end'] : $p['date_start']) . ' ' . ($p['time_end'] !== '' ? $p['time_end'] : '23:59')) ?: 0;
            if ($end && $end <= $start) $end += DAY_IN_SECONDS;
        }
        $doors = ($p['time_doors'] !== '' && $p['date_start'] !== '') ? (strtotime($p['date_start'] . ' ' . $p['time_doors']) ?: 0) : 0;

        $status  = (string) $p['status'];
        $cancel  = $status === 'cancelled';
        $soldout = $status === 'sold_out';
        $free    = !empty($p['free_entry']) && empty($p['tickets_enabled']);
        $price   = $free ? 0.0 : $p['price_from'];

        // Etikett wie flyerBadge() der App: „Vorverkauf“ vor „Freier Eintritt“, nie doppelt
        if ($cancel)                           $tag = ['Abgesagt', 'off'];
        elseif ($soldout)                      $tag = ['Ausverkauft', 'off'];
        elseif (!empty($p['tickets_enabled'])) $tag = ['Vorverkauf', 'presale'];
        elseif ($free)                         $tag = ['Freier Eintritt', 'free'];
        else                                   $tag = null;

        // Tage (Y-m-d) für die Datums-Chips; Ende vor 06:00 zählt nicht als weiterer Tag
        $days = [];
        $span = method_exists('TIX_Public_Events', 'day_span') ? TIX_Public_Events::day_span($id) : null;
        if ($span) {
            for ($d = $span[0], $i = 0; $d <= $span[1] && $i < 14; $d = date('Y-m-d', strtotime($d . ' +1 day')), $i++) $days[] = $d;
        }

        $place = $p['location'] !== '' ? $p['location'] : $p['address'];
        $short = (string) get_post_meta($id, '_tix_location_short', true);
        $city  = is_array($p['venue'] ?? null) ? (string) ($p['venue']['city'] ?? '') : '';
        if ($short === '') $short = ($city !== '' && stripos($place, $city) === false) ? trim($place . ', ' . $city, ', ') : $place;

        $thumb_id = get_post_thumbnail_id($id);
        $fit = false; // Bild deutlich anders als 16:9 → ganz zeigen, Ränder unscharf füllen (wie die App)
        if ($thumb_id) {
            $meta = wp_get_attachment_metadata($thumb_id);
            if (!empty($meta['width']) && !empty($meta['height'])) {
                $r = ($meta['width'] / $meta['height']) / (16 / 9);
                $fit = abs($r - 1) > 0.15;
            }
        }

        return $cache[$id] = [
            'id'        => $id,
            'url'       => $p['url'],
            'title'     => self::clean_title($p['title']),
            'start'     => $start,
            'end'       => $end,
            'doors'     => $doors,
            'has_time'  => $p['time_start'] !== '',
            'place'     => $place,
            'address'   => $p['address'],
            'short'     => $short,
            'city'      => $city,
            'organizer' => (string) ($p['organizer_info']['name'] ?? $p['organizer']),
            'category'  => $p['category'],
            'thumb'     => $p['thumbnail'] !== '' ? $p['thumbnail'] : $p['image'],
            'small'     => $p['image_small'] !== '' ? $p['image_small'] : $p['thumbnail'],
            'fit'       => $fit,
            'age'       => (string) $p['age_label'],
            'price'     => $price,
            'free'      => $free,
            'tickets'   => !empty($p['tickets_enabled']),
            'cancel'    => $cancel,
            'soldout'   => $soldout,
            'tag'       => $tag,
            'days'      => $days,
        ];
    }

    public static function price($v) {
        return number_format((float) $v, 2, ',', '.') . ' €';
    }

    /**
     * Pillen der Event-Seite wie in der App: genau eine Haupt-Pille (Status/Preis/Eintritt frei/Abendkasse),
     * dazu „Vorverkauf“ und das Alter. Schlüssel = [tix_app key="pill_…"].
     */
    private static function pills($d) {
        $p = ['pill_status' => '', 'pill_price' => '', 'pill_free' => '', 'pill_door' => '', 'pill_presale' => ''];
        if ($d['cancel'])                                                 $p['pill_status'] = 'Abgesagt';
        elseif ($d['soldout'])                                            $p['pill_status'] = 'Ausverkauft';
        elseif ($d['free'] || ($d['price'] !== null && $d['price'] <= 0)) $p['pill_free'] = 'Eintritt frei';
        elseif ($d['price'] !== null)                                     $p['pill_price'] = 'ab ' . self::price($d['price']);
        elseif ($d['tickets'])                                            $p['pill_price'] = 'Tickets online';
        else                                                              $p['pill_door'] = 'Abendkasse';
        if (!$d['cancel'] && !$d['soldout'] && $d['tickets'])            $p['pill_presale'] = 'Vorverkauf';
        return $p;
    }

    // ── [tix_app key="…"]: Einzelwerte für Breakdance ──

    /** Event der Seite (auf Veranstalter-Seiten: keins). */
    private static function current_event() {
        $id = get_the_ID();
        return ($id && get_post_type($id) === 'event') ? self::data($id) : null;
    }

    public static function sc_value($atts) {
        $atts = shortcode_atts(['key' => ''], $atts, 'tix_app');
        self::assets();
        return esc_html(self::value((string) $atts['key']));
    }

    /** Preis kurz wie in der App: „15 €“, „12,50 €“. */
    public static function price_short($v) {
        $v = (float) $v;
        return (floor($v) == $v ? number_format($v, 0, ',', '.') : number_format($v, 2, ',', '.')) . ' €';
    }

    /** Link zur Route (Google Maps) mit Verkehrsmittel. */
    private static function route_url($d, $mode) {
        $q = implode(', ', array_filter([$d['place'], ($d['address'] !== $d['place'] ? $d['address'] : '')]));
        if ($q === '') return '';
        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($q) . ($mode ? '&travelmode=' . $mode : '');
    }

    /** Countdown wie in der App: „in 12 Tagen“, „Morgen“, „in 3 Std.“, „Heute“; '' wenn vorbei/läuft. */
    private static function countdown($d) {
        $now = current_time('timestamp');
        $s = $d['start'];
        if (!$s || $s <= $now) return '';
        $days = (int) round((strtotime(date('Y-m-d', $s)) - strtotime(date('Y-m-d', $now))) / DAY_IN_SECONDS);
        if ($days > 1) return 'in ' . $days . ' Tagen';
        if ($days === 1) return 'Morgen';
        $h = (int) floor(($s - $now) / HOUR_IN_SECONDS);
        return $h >= 1 ? 'in ' . $h . ' Std.' : 'in ' . max(1, (int) ceil(($s - $now) / 60)) . ' Min.';
    }

    private static function is_live($d) {
        $now = current_time('timestamp');
        return $d['start'] && $d['start'] <= $now && ($d['end'] ? $d['end'] > $now : $d['start'] + 6 * HOUR_IN_SECONDS > $now);
    }

    /** Klartext-Wert; '' = nicht vorhanden (Breakdance-Bedingung „ist nicht leer“). */
    public static function value($key) {
        $now = current_time('timestamp');
        switch ($key) {
            case 'greeting': // wie _Greeting in der App
                $h = intval(date('G', $now));
                return $h >= 5 && $h < 11 ? 'Guten Morgen' : ($h >= 11 && $h < 17 ? 'Hallo' : ($h >= 17 && $h < 23 ? 'Guten Abend' : 'Noch wach?'));
            case 'question':
                $w = intval(date('N', $now));
                $h = intval(date('G', $now));
                if ($w === 5 || $w === 6) return 'Was geht am Wochenende?';
                return ($h >= 17 || $h < 5) ? 'Was geht heute Abend?' : 'Worauf hast du Lust?';
            case 'categories':
                $n = count(self::categories(self::upcoming(60)));
                return $n ? (string) $n : '';
            case 'live_now':   return self::live_events() ? '1' : '';
            case 'app_page':   return self::is_app_page() ? '1' : '';
            case 'organizers': return self::organizer_list() ? '1' : '';
        }

        $d = self::current_event();
        if (!$d) return '';
        $s = $d['start'];
        switch ($key) {
            case 'title':     return $d['title'];
            case 'organizer': return $d['organizer'];
            case 'related':   return count(self::related($d)) ? '1' : '';
            case 'lineup':    return self::lineup_names($d['id']) ? '1' : '';
            case 'weekday':   return $s ? self::WD[intval(date('w', $s))] : '';
            case 'day':       return $s ? (string) intval(date('j', $s)) : '';
            case 'month':     return $s ? self::MON[intval(date('n', $s)) - 1] : '';
            case 'date_long':
                return $s ? self::WD_LONG[intval(date('w', $s))] . ', ' . intval(date('j', $s)) . '. ' . self::MON_L[intval(date('n', $s)) - 1] . ' ' . date('Y', $s) : '';
            case 'countdown': return self::countdown($d);
            case 'live':      return self::is_live($d) ? 'Läuft gerade' : '';
            case 'time_range':
                if (!$s || !$d['has_time']) return '';
                if ($d['end'] && $d['end'] > $s) {
                    $next = date('Y-m-d', $d['end']) !== date('Y-m-d', $s);
                    return date('H:i', $s) . ' – ' . ($next ? self::WD[intval(date('w', $d['end']))] . ' ' : '') . date('H:i', $d['end']) . ' Uhr';
                }
                return date('H:i', $s) . ' Uhr';
            case 'doors':     return $d['doors'] ? 'Einlass ' . date('H:i', $d['doors']) . ' Uhr' : '';
            case 'place':     return $d['place'];
            case 'address':   return ($d['address'] !== '' && $d['address'] !== $d['place']) ? $d['address'] : '';
            case 'maps_url':
                $q = implode(', ', array_filter([$d['place'], ($d['address'] !== $d['place'] ? $d['address'] : '')]));
                return $q !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q) : '';
            case 'route_car':     return self::route_url($d, 'driving');
            case 'route_transit': return self::route_url($d, 'transit');
            case 'route_walk':    return self::route_url($d, 'walking');
            case 'age':       return $d['age'];
            // Ticket-Abschnitt nur mit Online-Tickets (Eintritt frei steht als Pille + Leiste)
            case 'tickets':   return ($d['tickets'] && !$d['cancel']) ? 'Tickets' : '';
            // Leiste unten: Preis + „Tickets kaufen“ | „Eintritt frei“ | Hinweis
            case 'bar_price':
                return ($d['tickets'] && !$d['cancel'] && !$d['soldout'] && $d['price'] !== null && $d['price'] > 0) ? self::price($d['price']) : '';
            case 'bar_buy':
                return ($d['tickets'] && !$d['cancel'] && !$d['soldout'] && !($d['price'] !== null && $d['price'] > 0)) ? 'Tickets kaufen' : '';
            case 'bar_free':  return (!$d['cancel'] && !$d['soldout'] && !$d['tickets'] && ($d['free'] || ($d['price'] !== null && $d['price'] <= 0))) ? '1' : '';
            case 'bar_state':
                if ($d['cancel'])  return 'Abgesagt';
                if ($d['soldout']) return 'Ausverkauft';
                if (!$d['tickets'] && !$d['free'] && !($d['price'] !== null && $d['price'] <= 0)) return 'Tickets an der Abendkasse';
                return '';
        }
        $pills = self::pills($d);
        return $pills[$key] ?? '';
    }

    // ── Listen-Bausteine ──

    private static function date_tile($ts) {
        if (!$ts) return '';
        return '<span class="evs-dtile" aria-hidden="true">'
            . '<span class="evs-dtile__wd">' . self::WD[intval(date('w', $ts))] . '</span>'
            . '<b class="evs-dtile__d">' . intval(date('j', $ts)) . '</b>'
            . '<span class="evs-dtile__m">' . self::MON[intval(date('n', $ts)) - 1] . '</span></span>';
    }

    /** „SA, 12. OKT · KÖLN“ (rote Zeile über dem Kartentitel) */
    private static function overline($d) {
        if (!$d['start']) return '';
        $s = $d['start'];
        $t = strtoupper(rtrim(self::WD[intval(date('w', $s))], '.')) . ', ' . intval(date('j', $s)) . '. ' . self::MON[intval(date('n', $s)) - 1];
        $city = $d['city'] !== '' ? $d['city'] : $d['short'];
        return $t . ($city !== '' ? ' · ' . mb_strtoupper($city, 'UTF-8') : '');
    }

    private static function tag_pill($tag) {
        if (!$tag) return '';
        return '<span class="evs-tag evs-tag--' . esc_attr($tag[1]) . '">' . self::icon('ticket') . esc_html($tag[0]) . '</span>';
    }

    private static function img($d, $src, $class) {
        $style = $src !== '' ? ' style="--evs-img:url(\'' . esc_url($src) . '\')"' : '';
        $cls = $class . ($d['fit'] ? ' evs-img--fit' : '') . ($src === '' ? ' evs-img--none' : '');
        $inner = $src !== '' ? '<img src="' . esc_url($src) . '" alt="" loading="lazy" decoding="async">' : '';
        return '<span class="' . esc_attr($cls) . '"' . $style . '>' . $inner . '</span>';
    }

    private static function heart($id, $saved) {
        $on = in_array($id, $saved);
        return '<button type="button" class="ev-save evs-save' . ($on ? ' saved' : '') . '" data-event-id="' . intval($id) . '" aria-label="Merken" onclick="event.preventDefault();event.stopPropagation();tixToggleSave(this);">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="' . ($on ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg></button>';
    }

    private static function filter_attrs($d) {
        $q = mb_strtolower($d['title'] . ' ' . $d['place'] . ' ' . $d['short'] . ' ' . $d['organizer'] . ' ' . ($d['category']['name'] ?? ''), 'UTF-8');
        return ' data-days="' . esc_attr(implode(' ', $d['days'])) . '" data-cat="' . esc_attr((string) ($d['category']['slug'] ?? '')) . '" data-q="' . esc_attr($q) . '"';
    }

    private static function meta_line($d) {
        return '<span class="evs-meta">' . self::tag_pill($d['tag'])
            . ($d['short'] !== '' ? '<span class="evs-meta__loc">' . self::icon('pin') . '<span>' . esc_html($d['city'] !== '' ? $d['city'] : $d['short']) . '</span></span>' : '')
            . '</span>';
    }

    /** Event-Zeile (EventRow): rote Datums-Kachel, daneben weiße Karte (Bild 100×56, Titel, Pille + Stadt, Herz). */
    public static function row($d, $saved = []) {
        return '<a class="evs-row" href="' . esc_url($d['url']) . '"' . self::filter_attrs($d) . '>'
            . self::date_tile($d['start'])
            . '<span class="evs-row__card">'
            . self::img($d, $d['small'], 'evs-img evs-row__img')
            . '<span class="evs-row__b"><span class="evs-row__top"><span class="evs-row__t">' . esc_html($d['title']) . '</span>' . self::heart($d['id'], $saved) . '</span>'
            . self::meta_line($d) . '</span>'
            . '</span></a>';
    }

    /** Preis-Etikett oben links auf der großen Karte */
    private static function price_badge($d) {
        if ($d['cancel'] || $d['soldout'] || $d['free'] || ($d['price'] !== null && $d['price'] <= 0)) return '';
        if ($d['price'] !== null) return 'ab ' . self::price_short($d['price']);
        return $d['tickets'] ? 'Tickets online' : 'Abendkasse';
    }

    /** Große Karte (EventCardLarge): Bild 16:9 randlos mit Etiketten, darunter rote Zeile + Herz, Titel. */
    public static function card($d, $saved = []) {
        $badge = self::price_badge($d);
        $tag = $d['tag'];
        return '<a class="evs-card" href="' . esc_url($d['url']) . '"' . self::filter_attrs($d) . '>'
            . '<span class="evs-card__media">' . self::img($d, $d['thumb'], 'evs-img evs-card__img')
            . ($badge !== '' ? '<span class="evs-badge evs-badge--price">' . esc_html($badge) . '</span>' : '')
            . ($tag ? '<span class="evs-badge evs-badge--' . esc_attr($tag[1]) . '">' . self::icon('ticket') . esc_html($tag[0]) . '</span>' : '')
            . '</span>'
            . '<span class="evs-card__b">'
            . '<span class="evs-card__over"><span>' . esc_html(self::overline($d)) . '</span>' . self::heart($d['id'], $saved) . '</span>'
            . '<span class="evs-card__t">' . esc_html($d['title']) . '</span>'
            . '</span></a>';
    }

    /** Kleine Karte („Das könnte dir auch gefallen“): Bild 16:9, rote Datumszeile, Titel, Ort. */
    public static function mini($d) {
        $s = $d['start'];
        $date = $s ? strtoupper(rtrim(self::WD[intval(date('w', $s))], '.')) . ', ' . intval(date('j', $s)) . '. ' . self::MON[intval(date('n', $s)) - 1] : '';
        return '<a class="evs-mini" href="' . esc_url($d['url']) . '">'
            . self::img($d, $d['thumb'], 'evs-img evs-mini__img')
            . '<span class="evs-mini__d">' . esc_html($date) . '</span>'
            . '<span class="evs-mini__t">' . esc_html($d['title']) . '</span>'
            . ($d['short'] !== '' ? '<span class="evs-mini__p">' . esc_html($d['short']) . '</span>' : '')
            . '</a>';
    }

    /** Kompakte Zeile (Veranstalter-Seite): Bild 112×63, rote Datumszeile, Titel, „Ort · ab 12,00 €“. */
    public static function compact($d, $saved = []) {
        $s = $d['start'];
        $date = $s ? self::WD[intval(date('w', $s))] . ', ' . intval(date('j', $s)) . '. ' . self::MON[intval(date('n', $s)) - 1] . ($d['has_time'] ? ' · ' . date('H:i', $s) : '') : '';
        $sub = [$d['short']];
        if ($d['free'] || ($d['price'] !== null && $d['price'] <= 0)) $sub[] = 'Eintritt frei';
        elseif ($d['price'] !== null) $sub[] = 'ab ' . self::price($d['price']);
        return '<a class="evs-crow" href="' . esc_url($d['url']) . '">'
            . self::img($d, $d['small'], 'evs-img evs-crow__img')
            . '<span class="evs-crow__b"><span class="evs-crow__d">' . esc_html($date) . '</span>'
            . '<span class="evs-crow__t">' . esc_html($d['title']) . '</span>'
            . '<span class="evs-crow__s">' . esc_html(implode(' · ', array_filter($sub))) . '</span></span>'
            . self::heart($d['id'], $saved) . '</a>';
    }

    /** Kommende öffentliche Events (gleiche Abfrage wie [tix_events]). */
    private static function upcoming($limit, $extra = []) {
        static $cache = [];
        $atts = array_merge(['limit' => intval($limit), 'category' => '', 'featured' => '', 'organizer' => '', 'exclude' => ''], $extra);
        $k = md5(wp_json_encode($atts) . '|' . get_the_ID());
        if (isset($cache[$k])) return $cache[$k];
        $out = [];
        if (class_exists('TIX_Event_Cards')) {
            foreach (TIX_Event_Cards::query_events($atts) as $post) {
                $d = self::data($post->ID);
                if ($d) $out[] = $d;
            }
        }
        return $cache[$k] = $out;
    }

    /** „Das könnte dir auch gefallen“: kommende Events gleicher Sparte, sonst gleicher Veranstalter. */
    private static function related($d, $limit = 8) {
        $slug = (string) ($d['category']['slug'] ?? '');
        $list = $slug !== '' ? self::upcoming($limit + 1, ['category' => $slug, 'exclude' => 'current']) : [];
        if (!$list) $list = self::upcoming($limit + 1, ['organizer' => 'current', 'exclude' => 'current']);
        return array_slice(array_values(array_filter($list, function ($x) use ($d) { return $x['id'] !== $d['id']; })), 0, $limit);
    }

    private static function saved() {
        return class_exists('TIX_Event_Cards') ? TIX_Event_Cards::get_saved_events_static() : [];
    }

    public static function sc_list($atts) {
        $atts = shortcode_atts([
            'limit' => 60, 'organizer' => '', 'exclude' => '', 'category' => '', 'filter' => '0', 'style' => 'row', 'empty' => 'Keine Events gefunden.',
        ], $atts, 'tix_app_list');
        $events = self::upcoming($atts['limit'], ['organizer' => $atts['organizer'], 'exclude' => $atts['exclude'], 'category' => $atts['category']]);
        if (!$events && $atts['empty'] === '') return '';
        self::assets();
        self::enqueue_cards();
        $saved = self::saved();
        $filter = $atts['filter'] === '1';
        $compact = $atts['style'] === 'compact';
        $html = $filter ? '<button type="button" class="evs-reset" hidden>Filter zurücksetzen</button>' : '';
        $html .= '<div class="evs-list' . ($filter ? ' evs-list--filter' : '') . ($compact ? ' evs-list--compact' : '') . '">';
        foreach ($events as $d) $html .= $compact ? self::compact($d, $saved) : self::row($d, $saved);
        $html .= '</div>';
        if ($atts['empty'] !== '') {
            $html .= '<p class="evs-empty"' . ($events ? ' hidden' : '') . '>' . esc_html($atts['empty']) . '</p>';
        }
        return $html;
    }

    public static function sc_cards($atts) {
        $atts = shortcode_atts(['limit' => 6], $atts, 'tix_app_cards');
        $events = self::upcoming(60);
        if (!$events) return '';
        self::assets();
        self::enqueue_cards();
        $saved = self::saved();
        $html = '<div class="evs-cards">';
        foreach (array_slice($events, 0, max(1, intval($atts['limit']))) as $d) $html .= self::card($d, $saved);
        return $html . '</div>';
    }

    public static function sc_related($atts) {
        $d = self::current_event();
        $list = $d ? self::related($d) : [];
        if (!$list) return '';
        self::assets();
        $html = '<div class="evs-minis">';
        foreach ($list as $x) $html .= self::mini($x);
        return $html . '</div>';
    }

    /** Herz für das Event der Seite (neben dem Titel). */
    public static function sc_heart($atts) {
        $d = self::current_event();
        if (!$d) return '';
        self::assets();
        self::enqueue_cards();
        return self::heart($d['id'], self::saved());
    }

    /** Künstler aus dem Line-up (je Absatz einer; „Floor 1: Name“ → „Name“), wie ArtistStrip der App. */
    private static function lineup_names($id) {
        $html = (string) get_post_meta($id, '_tix_info_lineup', true);
        if ($html === '') $html = (string) get_post_meta($id, '_tix_lineup', true);
        $parts = preg_split('~</p>|<br\s*/?>|\n~i', $html);
        $out = [];
        foreach ($parts as $p) {
            $t = trim(html_entity_decode(wp_strip_all_tags($p), ENT_QUOTES, 'UTF-8'));
            if ($t !== '') $out[] = $t;
        }
        return $out;
    }

    private static function artist($line) {
        $i = mb_strpos($line, ':');
        $n = trim($i !== false ? mb_substr($line, $i + 1) : $line);
        return $n !== '' ? $n : trim($line);
    }

    private static function initials($name) {
        $parts = array_values(array_filter(explode(' ', preg_replace('/[^\p{L}\p{N} ]/u', ' ', $name))));
        if (!$parts) return '?';
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''), 'UTF-8');
    }

    /** Line-up als Reihe runder Initialen-Kreise; ein Künstler: Zeile mit Kreis, Name und Abspielen. */
    public static function sc_lineup($atts) {
        $d = self::current_event();
        $names = $d ? self::lineup_names($d['id']) : [];
        if (!$names) return '';
        self::assets();
        $spotify = function ($n) { return 'https://open.spotify.com/search/' . rawurlencode($n); };
        if (count($names) === 1) {
            $n = self::artist($names[0]);
            return '<a class="evs-artist1" href="' . esc_url($spotify($n)) . '" target="_blank" rel="noopener">'
                . '<span class="evs-artist__c">' . esc_html(self::initials($n)) . '</span>'
                . '<span class="evs-artist1__n">' . esc_html($names[0]) . '</span>'
                . '<span class="evs-artist1__play">' . self::icon('play') . '</span></a>';
        }
        $html = '<div class="evs-artists">';
        foreach ($names as $line) {
            $n = self::artist($line);
            $html .= '<a class="evs-artist" href="' . esc_url($spotify($n)) . '" target="_blank" rel="noopener" title="' . esc_attr($line) . '">'
                . '<span class="evs-artist__c">' . esc_html(self::initials($n)) . '</span>'
                . '<span class="evs-artist__n">' . esc_html($n) . '</span></a>';
        }
        return $html . '</div>';
    }

    /** Seite mit Kasse, Konto, Tickets oder Support (Bedingung der Vorlage „Seite (App-Look)“) */
    private static function is_app_page() {
        $id = get_queried_object_id();
        if (!$id || get_post_type($id) !== 'page') return false;
        $content = (string) get_post_field('post_content', $id);
        foreach (self::APP_PAGES as $tag) if (has_shortcode($content, $tag)) return true;
        return false;
    }

    // ── „Jetzt & gleich“ ──

    /** Läuft gerade oder beginnt in den nächsten 3 Std.; laufende zuerst (wie liveNowEvents der App). */
    private static function live_events() {
        static $out = null;
        if ($out !== null) return $out;
        $now = current_time('timestamp');
        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 60,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => '_tix_date_start',
                'value'   => [date('Y-m-d', $now - DAY_IN_SECONDS), date('Y-m-d', $now + DAY_IN_SECONDS)],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ]],
        ]);
        $live = $soon = [];
        foreach ($ids as $id) {
            $d = self::data($id);
            if (!$d || !$d['start'] || $d['cancel']) continue;
            if (self::is_live($d)) $live[] = $d;
            elseif ($d['start'] > $now && $d['start'] <= $now + 3 * HOUR_IN_SECONDS) $soon[] = $d;
        }
        $by = function ($a, $b) { return $a['start'] <=> $b['start']; };
        usort($live, $by);
        usort($soon, $by);
        return $out = array_merge($live, $soon);
    }

    public static function sc_live($atts) {
        $list = self::live_events();
        if (!$list) return '';
        self::assets();
        $now = current_time('timestamp');
        $html = '<div class="evs-lives">';
        foreach ($list as $d) {
            $live = self::is_live($d);
            $min = (int) ceil(($d['start'] - $now) / 60);
            $label = $live ? 'Läuft gerade' : ($min >= 60 ? 'in ' . floor($min / 60) . ' Std.' . ($min % 60 ? ' ' . ($min % 60) . ' Min.' : '') : 'in ' . max(1, $min) . ' Min.');
            $html .= '<a class="evs-lcard" href="' . esc_url($d['url']) . '">'
                . '<span class="evs-lcard__media">' . self::img($d, $d['thumb'], 'evs-img evs-lcard__img')
                . '<span class="evs-badge evs-badge--' . ($live ? 'live' : 'soon') . '">' . ($live ? '<i class="evs-pulse"></i>' : '') . esc_html($label) . '</span></span>'
                . '<span class="evs-lcard__t">' . esc_html($d['title']) . '</span>'
                . ($d['short'] !== '' ? '<span class="evs-lcard__p">' . esc_html($d['short']) . '</span>' : '')
                . '</a>';
        }
        return $html . '</div>';
    }

    // ── „Veranstalter entdecken“ ──

    /** Veranstalter mit öffentlicher Seite, die mit kommenden Events zuerst (wie _OrganizerStrip der App). */
    private static function organizer_list($limit = 10) {
        static $out = null;
        if ($out !== null) return $out;
        $out = [];
        if (!class_exists('TIX_Public_Platform') || !class_exists('TIX_Organizer_Pages')) return $out;
        $resp = TIX_Public_Platform::rest_organizers(new WP_REST_Request('GET', '/' . TIX_Public_Platform::NS . '/public/organizers'));
        $data = $resp instanceof WP_REST_Response ? $resp->get_data() : (array) $resp;
        $list = (array) ($data['organizers'] ?? []);
        usort($list, function ($a, $b) { return intval($b['upcoming_count']) <=> intval($a['upcoming_count']); });
        foreach ($list as $o) {
            $url = TIX_Organizer_Pages::url(intval($o['id']));
            if ($url === '') continue;
            $o['url'] = $url;
            $out[] = $o;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public static function sc_organizers($atts) {
        $list = self::organizer_list();
        if (!$list) return '';
        self::assets();
        $following = is_user_logged_in() ? self::following(get_current_user_id()) : [];
        $html = '<div class="evs-otiles">';
        foreach ($list as $i => $o) {
            $g = self::GRADIENTS[$i % count(self::GRADIENTS)];
            $n = intval($o['upcoming_count']);
            $sub = implode(' · ', array_filter([(string) $o['city'], $n === 0 ? 'keine Events' : ($n === 1 ? '1 Event' : $n . ' Events')]));
            $ini = self::initials((string) $o['name']);
            $on = in_array(intval($o['id']), $following, true);
            $html .= '<div class="evs-otile">'
                . '<a class="evs-otile__link" href="' . esc_url($o['url']) . '">'
                . '<span class="evs-otile__banner" style="background:' . ($o['hero'] !== '' ? 'url(\'' . esc_url($o['hero']) . '\') center/cover' : 'linear-gradient(135deg,' . $g[0] . ',' . $g[1] . ')') . '"></span>'
                . '<span class="evs-otile__logo">' . ($o['logo'] !== '' ? '<img src="' . esc_url($o['logo']) . '" alt="" loading="lazy">' : '<b>' . esc_html($ini) . '</b>') . '</span>'
                . '<span class="evs-otile__n">' . esc_html($o['name']) . '</span>'
                . '<span class="evs-otile__s">' . esc_html($sub) . '</span></a>'
                . '<button type="button" class="evs-follow evs-follow--pill' . ($on ? ' is-on' : '') . '" data-org="' . intval($o['id']) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">'
                . '<span class="evs-follow__off">' . self::icon('plus') . 'Folgen</span>'
                . '<span class="evs-follow__on">' . self::icon('check') . 'Folge ich</span></button>'
                . '</div>';
        }
        return $html . '</div>';
    }

    public static function sc_search($atts) {
        $atts = shortcode_atts(['placeholder' => 'Event suchen'], $atts, 'tix_app_search');
        self::assets();
        return '<div class="evs-finder">'
            . '<label class="evs-search">' . self::icon('search')
            . '<input type="search" class="evs-search__in" placeholder="' . esc_attr($atts['placeholder']) . '" autocomplete="off" aria-label="Events suchen"></label>'
            . '<div class="evs-chips" role="group" aria-label="Zeitraum">'
            . '<button type="button" class="evs-chip" data-when="today" aria-pressed="false">Heute</button>'
            . '<button type="button" class="evs-chip" data-when="tomorrow" aria-pressed="false">Morgen</button>'
            . '<button type="button" class="evs-chip" data-when="weekend" aria-pressed="false">Wochenende</button>'
            . '</div></div>';
    }

    /** Sparten mit kommenden Events, nach Anzahl sortiert. */
    private static function categories($events) {
        $cats = [];
        foreach ($events as $d) {
            if (empty($d['category']['slug'])) continue;
            $slug = $d['category']['slug'];
            if (!isset($cats[$slug])) $cats[$slug] = ['name' => html_entity_decode((string) $d['category']['name']), 'n' => 0];
            $cats[$slug]['n']++;
        }
        uasort($cats, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['name'], $b['name']); });
        return $cats;
    }

    /** Symbol je Sparte (wie CategoryCard.iconFor in der App) */
    private static function category_icon($slug, $name) {
        $k = strtolower($slug . ' ' . $name);
        $map = [
            'club' => 'sparkles', 'party' => 'party', 'comedy' => 'chat', 'festival' => 'sun', 'open' => 'tent',
            'konzert' => 'music', 'live' => 'music', 'theater' => 'star', 'kultur' => 'star', 'sport' => 'trophy',
            'lauf' => 'trophy', 'kind' => 'users', 'familie' => 'users', 'food' => 'food', 'essen' => 'food',
            'markt' => 'food', 'workshop' => 'cap', 'kurs' => 'cap', 'silvester' => 'sparkles',
        ];
        foreach ($map as $needle => $icon) if (strpos($k, $needle) !== false) return $icon;
        return 'star';
    }

    /** Kategorien als weiße Pillen mit rot getöntem Icon-Kreis (filtern die Liste). */
    public static function sc_categories($atts) {
        $cats = self::categories(self::upcoming(60));
        if (!$cats) return '';
        self::assets();
        $html = '<div class="evs-cats">';
        foreach ($cats as $slug => $c) {
            $html .= '<button type="button" class="evs-cat" data-cat="' . esc_attr($slug) . '" aria-pressed="false">'
                . '<span class="evs-cat__ic">' . self::icon(self::category_icon($slug, $c['name'])) . '</span>'
                . '<span class="evs-cat__n">' . esc_html($c['name']) . '</span></button>';
        }
        return $html . '</div>';
    }

    // ── Folgen ──

    private static function following($uid) {
        $meta = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::META_FOLLOWING : '_tix_app_following';
        $v = get_user_meta($uid, $meta, true);
        return array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []))));
    }

    public static function sc_follow($atts) {
        $oid = get_post_type(get_the_ID()) === 'tix_organizer' ? intval(get_the_ID()) : 0;
        if (!$oid) return '';
        self::assets();
        $on = is_user_logged_in() && in_array($oid, self::following(get_current_user_id()), true);
        return '<button type="button" class="evs-follow' . ($on ? ' is-on' : '') . '" data-org="' . $oid . '" aria-pressed="' . ($on ? 'true' : 'false') . '">'
            . '<span class="evs-follow__off">' . self::icon('plus') . 'Folgen</span>'
            . '<span class="evs-follow__on">' . self::icon('check') . 'Du folgst</span></button>';
    }

    /**
     * Anmeldung für Gäste: die Kontoseite (veröffentlichte Seite mit dem Shortcode [tix_account])
     * mit Rücksprung ?tix_back=…; gibt es keine, die WordPress-Anmeldung.
     */
    public static function account_url($back) {
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            's'              => '[tix_account',
        ]);
        if ($pages) return add_query_arg('tix_back', rawurlencode($back), get_permalink($pages[0]));
        return wp_login_url($back);
    }

    /** Gültiges Rücksprung-Ziel (gleiche Seite) oder ''. */
    private static function back_target($raw) {
        if (!is_string($raw) || $raw === '') return '';
        return wp_validate_redirect(esc_url_raw(rawurldecode(wp_unslash($raw))), '');
    }

    /** Rücksprung als verstecktes Feld ins Anmeldeformular der Kontoseite. */
    public static function login_back_field($html, $args) {
        $back = self::back_target($_GET['tix_back'] ?? '');
        if ($back === '') return $html;
        return $html . '<input type="hidden" name="tix_back" value="' . esc_attr($back) . '">';
    }

    /** Nach der Anmeldung dorthin zurück (z. B. zur Veranstalter-Seite). */
    public static function login_back_redirect($to, $requested, $user) {
        $back = self::back_target($_POST['tix_back'] ?? '');
        return ($back !== '' && !is_wp_error($user)) ? $back : $to;
    }

    public static function ajax_follow() {
        if (!is_user_logged_in()) {
            $back = isset($_POST['back']) ? esc_url_raw(wp_unslash($_POST['back'])) : home_url('/');
            wp_send_json_error(['login' => self::account_url($back)]);
        }
        check_ajax_referer('tix_app_follow', 'nonce');
        $oid = intval($_POST['org'] ?? 0);
        if (!$oid || get_post_type($oid) !== 'tix_organizer') wp_send_json_error(['message' => 'Unbekannter Veranstalter']);
        $uid  = get_current_user_id();
        $meta = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::META_FOLLOWING : '_tix_app_following';
        $list = self::following($uid);
        $on   = !in_array($oid, $list, true);
        $list = $on ? array_values(array_unique(array_merge($list, [$oid]))) : array_values(array_diff($list, [$oid]));
        update_user_meta($uid, $meta, $list);
        wp_send_json_success(['following' => $on]);
    }

    // ── WP-CLI: Breakdance-Vorlagen einspielen und umschalten ──

    /**
     * Breakdance-Vorlagen des App-Looks (Startseite, Event, Veranstalter) verwalten.
     *
     * ## OPTIONS
     *
     * [--force]
     * : bei install auch Vorlagen überschreiben, die im Builder geändert wurden
     *
     * [<aktion>]
     * : install = Vorlagen anlegen/aktualisieren (neue bleiben aus), on = App-Look an (bisherige Vorlagen
     * gleichen Typs aus), off = zurück zu den bisherigen Vorlagen, status = Übersicht (Standard)
     *
     * ## EXAMPLES
     *
     *     wp tixomat app-look install
     *     wp tixomat app-look install --force   (auch im Builder geänderte Vorlagen überschreiben)
     *     wp tixomat app-look on
     *     wp tixomat app-look off
     */
    public static function cli($args, $assoc_args = []) {
        $action = $args[0] ?? 'status';
        if (!function_exists('\Breakdance\Data\set_meta')) WP_CLI::error('Breakdance ist nicht aktiv.');
        $ours = self::cli_templates();

        if ($action === 'install') {
            foreach (self::TEMPLATES as $file => [$title, $type]) {
                $json = (string) file_get_contents(TIXOMAT_PATH . 'assets/breakdance/' . $file);
                if (!json_decode($json, true)) WP_CLI::error("$file fehlt oder ist kein JSON.");
                $id = $ours[$type] ?? 0;
                $new = !$id;
                // Im Builder geändert (weder ausgeliefert noch zuletzt eingespielt)? Dann nicht ungefragt überschreiben.
                if (!$new) {
                    $cur = (array) \Breakdance\Data\get_meta($id, '_breakdance_data');
                    $md5 = md5((string) ($cur['tree_json_string'] ?? ''));
                    $known = array_merge(self::SHIPPED, [(string) get_post_meta($id, self::META_HASH, true), md5($json)]);
                    if (!in_array($md5, $known, true) && empty($assoc_args['force'])) {
                        WP_CLI::warning("#$id $title wurde im Builder geändert – übersprungen (mit --force überschreiben).");
                        continue;
                    }
                }
                if ($new) {
                    $id = wp_insert_post(['post_type' => 'breakdance_template', 'post_status' => 'publish', 'post_title' => $title], true);
                    if (is_wp_error($id)) WP_CLI::error($id->get_error_message());
                }
                \Breakdance\Data\set_meta($id, '_breakdance_data', ['tree_json_string' => $json]);
                update_post_meta($id, self::META_HASH, md5($json));
                $settings = json_decode((string) \Breakdance\Data\get_meta($id, '_breakdance_template_settings'), true) ?: [];
                $settings = array_merge(['type' => $type, 'ruleGroups' => [], 'priority' => 30, 'triggers' => []], $settings);
                // Seiten-Vorlage nur für Kasse/Konto/Tickets/Support: Bedingung „[tix_app key="app_page"] ist nicht leer“
                if ($type === 'page') $settings['ruleGroups'] = [[self::cli_rule('app_page')]];
                if ($new) $settings['disabled'] = true;
                \Breakdance\Data\set_meta($id, '_breakdance_template_settings', wp_json_encode($settings));
                \Breakdance\Render\generateCacheForPost($id);
                WP_CLI::log(($new ? 'Angelegt' : 'Aktualisiert') . " #$id $title" . (!empty($settings['disabled']) ? ' (aus)' : ' (an)'));
            }
            WP_CLI::success('Vorlagen eingespielt.');
            return;
        }

        if ($action === 'on' || $action === 'off') {
            if (count($ours) < count(self::TEMPLATES)) WP_CLI::error('Erst „wp tixomat app-look install“ ausführen.');
            $replaced = array_map('intval', (array) get_option(self::OPT_REPLACED, []));
            if ($action === 'on') {
                foreach ($ours as $type => $id) {
                    foreach (self::cli_others($type, $ours) as $oid) {
                        if (self::cli_set_disabled($oid, true)) $replaced[] = $oid;
                    }
                    self::cli_set_disabled($id, false);
                }
                update_option(self::OPT_REPLACED, array_values(array_unique($replaced)), false);
            } else {
                foreach ($ours as $id) self::cli_set_disabled($id, true);
                foreach ($replaced as $oid) self::cli_set_disabled($oid, false);
                delete_option(self::OPT_REPLACED);
            }
            WP_CLI::success('App-Look ' . ($action === 'on' ? 'an' : 'aus') . '.');
        }

        foreach (self::TEMPLATES as [$title, $type]) {
            foreach (get_posts(['post_type' => 'breakdance_template', 'post_status' => 'publish', 'numberposts' => -1]) as $p) {
                $s = json_decode((string) \Breakdance\Data\get_meta($p->ID, '_breakdance_template_settings'), true) ?: [];
                if (($s['type'] ?? '') !== $type || !empty($s['fallback'])) continue;
                WP_CLI::log(sprintf('%-14s #%-4d %-34s %s', $type, $p->ID, $p->post_title, empty($s['disabled']) ? 'an' : 'aus'));
            }
        }
    }

    /** Breakdance-Regel „dynamisches Feld [tix_app key=…] ist nicht leer“ */
    private static function cli_rule($key) {
        $sc = '[tix_app key="' . $key . '"]';
        $dyn = "[breakdance_dynamic field='shortcode' params='" . wp_json_encode(['shortcode' => $sc], JSON_UNESCAPED_SLASHES) . "']";
        return ['ruleSlug' => 'dynamic-data', 'operand' => 'is not empty', 'ruleDynamic' => $dyn,
                'ruleDynamicMeta' => ['field' => 'shortcode', 'shortcode' => $dyn, 'attributes' => ['shortcode' => $sc]]];
    }

    /** Eigene Vorlagen: Typ => ID (erkannt am Titel). */
    private static function cli_templates() {
        $out = [];
        foreach (self::TEMPLATES as [$title, $type]) {
            $p = get_posts(['post_type' => 'breakdance_template', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1]);
            if ($p) $out[$type] = $p[0]->ID;
        }
        return $out;
    }

    /** Andere eingeschaltete Vorlagen gleichen Typs (keine Breakdance-Fallbacks). */
    private static function cli_others($type, $ours) {
        $ids = [];
        foreach (get_posts(['post_type' => 'breakdance_template', 'post_status' => 'publish', 'numberposts' => -1]) as $p) {
            if (in_array($p->ID, $ours, true)) continue;
            $s = json_decode((string) \Breakdance\Data\get_meta($p->ID, '_breakdance_template_settings'), true) ?: [];
            if (($s['type'] ?? '') === $type && empty($s['fallback']) && empty($s['disabled'])) $ids[] = $p->ID;
        }
        return $ids;
    }

    /** Schalter einer Vorlage setzen; true = geändert. */
    private static function cli_set_disabled($id, $disabled) {
        $s = json_decode((string) \Breakdance\Data\get_meta($id, '_breakdance_template_settings'), true) ?: [];
        if (!empty($s['disabled']) === $disabled) return false;
        $s['disabled'] = $disabled;
        \Breakdance\Data\set_meta($id, '_breakdance_template_settings', wp_json_encode($s));
        WP_CLI::log("#$id " . get_the_title($id) . ': ' . ($disabled ? 'aus' : 'an'));
        return true;
    }
}
