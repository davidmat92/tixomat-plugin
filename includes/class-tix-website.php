<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Website – Aufbau der Marken-Websites (Website-Plan Phase 3, 1.38.515, Vertrag uebergaben/vertrag-website.md;
 * Format: tixomat-apps/docs/homepages-vorlagen-phase0.md Abschnitt 5). Nur Einzel-Seiten, auf evendis 404.
 *
 *   GET/POST /studio/website   Admin und Inhaber (TIX_Studio::perm_brand): Entwurf speichern, veröffentlichen, verwerfen;
 *                              Domain-Status nur Admin
 *   GET /public/website        veröffentlichter Stand (bzw. ?draft=<Vorschau-Schlüssel> = Entwurf, no-store)
 *   /public/site-config        zusätzlich `website` (veröffentlicht, aufbereitet) und `domain`
 * Abschnitte hinter einem App-Schalter (TIX_Features) kommen mit available:false, wenn er aus ist.
 *
 * Website-Paket (1.38.528): Option `tix_website_enabled` je Seite ('1'|'0', Vorgabe aus; Admin-Schalter Tixomat → Marken-Website),
 * im Studio als `GET /studio/config` → `website {enabled}`. Beim Update einmalig an auf den Seiten mit Homepage (AUTO_HOSTS).
 * Nur Anzeige-Schalter für das Studio – die Routen oben bleiben unverändert erreichbar.
 */
class TIX_Website {

    const NS = 'tixomat/v1';
    const OPT_ENABLED = 'tix_website_enabled';
    // Live-Seiten mit Homepage, auf denen das Paket beim Update automatisch angeht (Auftrag Zentrale 2026-10-23)
    const AUTO_HOSTS = ['kitchenklub.de', 'mallorca-festival-xxl.de'];
    const OPTION = 'tix_website';
    const OPT_DOMAIN = 'tix_website_domain';
    const TEMPLATES = ['club', 'festival', 'klar'];
    const HISTORY = 10;
    const PREVIEW_TTL = DAY_IN_SECONDS;

    /** key => [Bezeichnung, Optionen, App-Schalter (TIX_Features::map bzw. app) | null] */
    const SECTIONS = [
        'stage'      => ['Bühne (nächstes Event)', [], null],
        'ticker'     => ['Laufband', ['title', 'eyebrow'], null],
        'tickets'    => ['Tickets (Kategorien)', ['title', 'eyebrow'], 'checkout'],
        'arrival'    => ['Anreise & vor Ort', ['title', 'eyebrow'], null],
        'more_dates' => ['Weitere Termine', ['max', 'title', 'eyebrow'], null],
        'highlights' => ['Highlights', ['max'], null],
        'next'       => ['Nächste Events', ['max', 'title'], null],
        'presale'    => ['Vorverkauf', [], null],
        'service'    => ['Service-Kacheln', [], null],
        'lineup'     => ['Line-up', ['max', 'title'], null],
        'about'      => ['Über uns', ['eyebrow', 'title', 'html', 'facts'], null],
        // ohne gepflegte Bilder: leer – die Website zeigt dann die Flyer der kommenden Events
        'gallery'    => ['Galerie', ['images', 'video'], null],
        'faq'        => ['FAQ', ['max'], null],
        'giftcards'  => ['Gutscheine', [], 'giftcards'],
        'wallet'     => ['Guthaben', [], 'wallet'],
        'loyalty'    => ['Prämien', [], 'loyalty'],
        'music'      => ['Musikwunsch', [], 'music'],
        'u16'        => ['Ü16 / Muttizettel', [], 'muttizettel'],
        'awareness'  => ['Awareness', [], 'awareness'],
        'raffles'    => ['Gewinnspiele', ['max'], 'raffles'],
        'tips'       => ['Tipps', ['max'], 'tips'],
        'live_now'   => ['Jetzt & gleich', [], 'live_now'],
        'app_banner' => ['App-Banner', [], 'app'],
        'newsletter' => ['Newsletter', [], null],
        'partners'   => ['Partner', ['title', 'logos'], null],
        'contact'    => ['Kontakt', [], null],
    ];

    /** Vorgaben je Vorlage: diese Abschnitte sind an (Reihenfolge = Startseite), alle übrigen aus. */
    // Abstimmung mit dem Homepages-Chat (1.38.516, erweitert 1.38.523 – Schritt 6); available folgt weiter den App-Schaltern
    const DEFAULTS = [
        'club'     => ['stage', 'live_now', 'highlights', 'next', 'presale', 'service', 'tips', 'raffles', 'about', 'gallery', 'music', 'loyalty', 'giftcards', 'wallet', 'u16', 'awareness', 'faq', 'newsletter', 'app_banner'],
        'festival' => ['stage', 'ticker', 'lineup', 'tickets', 'raffles', 'about', 'arrival', 'more_dates', 'tips', 'gallery', 'wallet', 'awareness', 'faq', 'newsletter', 'app_banner'],
        'klar'     => ['stage', 'next', 'tips', 'raffles', 'about', 'arrival', 'service', 'faq', 'contact', 'newsletter', 'app_banner'],
    ];

    const MAX = ['highlights' => 24, 'next' => 12, 'lineup' => 48, 'faq' => 20, 'raffles' => 12, 'tips' => 12, 'more_dates' => 12];
    const TEXTS = ['claim', 'seo_title', 'seo_description', 'newsletter_title', 'newsletter_text'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('init', [__CLASS__, 'maybe_enable']);
        add_action('admin_menu', [__CLASS__, 'menu'], 34);
        add_action('admin_post_tix_website_package', [__CLASS__, 'handle_package']);
    }

    // ═══════════════ Website-Paket (1.38.528) ═══════════════

    /** Ist das Website-Paket auf dieser Seite freigeschaltet? (Mehr-Veranstalter-Modus: nie) */
    public static function package_enabled() {
        return !self::multi() && get_option(self::OPT_ENABLED, '0') === '1';
    }

    /** Für GET /studio/config: {enabled} */
    public static function package_payload() {
        return ['enabled' => self::package_enabled()];
    }

    /** Einmalig: Seiten mit Homepage (AUTO_HOSTS) bekommen das Paket an, sobald /studio/website eine Adresse liefert. */
    public static function maybe_enable() {
        if (get_option(self::OPT_ENABLED, null) !== null) return;
        $on = false;
        if (!self::multi()) {
            $host = strtolower(preg_replace('/^www\./', '', (string) self::domain()['live_host']));
            $on = $host !== '' && in_array($host, self::AUTO_HOSTS, true);
        }
        update_option(self::OPT_ENABLED, $on ? '1' : '0', false);
    }

    public static function menu() {
        if (self::multi()) return;
        add_submenu_page('tixomat', 'Marken-Website', 'Marken-Website', 'manage_options', 'tix-website', [__CLASS__, 'render_admin']);
    }

    public static function handle_package() {
        if (!current_user_can('manage_options') || !check_admin_referer('tix_website_package')) wp_die('Keine Berechtigung.');
        update_option(self::OPT_ENABLED, !empty($_POST['enabled']) ? '1' : '0', false);
        wp_safe_redirect(add_query_arg('tix_web', 'saved', admin_url('admin.php?page=tix-website')));
        exit;
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        $o = self::store();
        $d = self::domain();
        ?>
        <div class="wrap">
            <h1>Marken-Website</h1>
            <?php if (($_GET['tix_web'] ?? '') === 'saved'): ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;max-width:820px;margin:12px 0;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tix_website_package"><?php wp_nonce_field('tix_website_package'); ?>
                    <label><input type="checkbox" name="enabled" value="1" <?php checked(self::package_enabled()); ?>> <strong>Website-Paket freigeschaltet</strong></label>
                    <p style="color:#64748b;margin:6px 0 10px;">Zeigt im Studio den Bereich „Website“ (Aufbau, Entwurf, Veröffentlichen). Ausschalten blendet ihn aus; gespeicherte Inhalte und die veröffentlichte Website bleiben.</p>
                    <?php submit_button('Speichern', 'primary', 'submit', false); ?>
                </form>
                <p style="margin-top:14px;">Stand: <?php echo $o['published'] ? 'veröffentlicht' . ($o['published_at'] ? ' am ' . esc_html(wp_date('d.m.Y H:i', strtotime($o['published_at']))) : '') : 'noch nicht veröffentlicht'; ?><?php echo $o['draft'] ? ' · Entwurf vorhanden' : ''; ?>
                    · Adresse <?php echo esc_html($d['live_host']); ?> (Vorschau <?php echo esc_html($d['preview_host']); ?>, <?php echo $d['status'] === 'live' ? 'live' : 'Vorschau'; ?>)</p>
            </div>
        </div>
        <?php
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function routes() {
        register_rest_route(self::NS, '/studio/website', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => [__CLASS__, 'perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => [__CLASS__, 'perm']],
        ]);
        register_rest_route(self::NS, '/public/website', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public'], 'permission_callback' => [__CLASS__, 'perm_public']]);
    }

    public static function perm(WP_REST_Request $req) {
        if (self::multi()) return new WP_Error('rest_no_route', 'Auf dieser Seite gibt es keine Marken-Website.', ['status' => 404]);
        return class_exists('TIX_Studio') ? TIX_Studio::perm_brand($req) : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
    }

    public static function perm_public() {
        return self::multi() ? new WP_Error('rest_no_route', 'Auf dieser Seite gibt es keine Marken-Website.', ['status' => 404]) : true;
    }

    // ═══════════════ Speicher ═══════════════

    private static function store() {
        $o = get_option(self::OPTION, []);
        $o = is_array($o) ? $o : [];
        return $o + ['published' => null, 'draft' => null, 'published_at' => null, 'updated_at' => '', 'updated_by' => 0, 'history' => []];
    }

    private static function put(array $o) {
        update_option(self::OPTION, $o, false);
    }

    /** Vorlage aus dem App-Profil (club → club, festival* → festival). */
    public static function profile_template() {
        $p = class_exists('TIX_Studio') ? (string) TIX_Studio::app_layout() : 'club';
        return strpos($p, 'festival') === 0 ? 'festival' : 'club';
    }

    // ═══════════════ Schalter ═══════════════

    private static $avail = null;

    /** Funktion an? (dieselben Schalter wie die App) */
    public static function switch_on($switch) {
        if ($switch === null) return true;
        if (self::$avail === null) {
            $m = class_exists('TIX_Features') ? (array) TIX_Features::map() : [];
            self::$avail = [];
            foreach ($m as $k => $v) self::$avail[$k] = is_array($v) ? !empty($v['enabled']) : (bool) $v;
            $app = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'app_info') ? (array) TIX_Studio::app_info() : [];
            self::$avail['app'] = !empty($app['has_app']);
            self::$avail['checkout'] = true; // Kasse gibt es auf jeder Einzel-Seite (wie site-config.features.checkout)
        }
        return !empty(self::$avail[$switch]);
    }

    // ═══════════════ Prüfen ═══════════════

    private static function bad($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]);
    }

    private static function text($v, $field, $max = 120) {
        $v = trim(sanitize_text_field((string) $v));
        if (mb_strlen($v) > $max) return self::bad($field, 'Höchstens ' . $max . ' Zeichen.');
        return $v;
    }

    private static function url($v, $field) {
        $v = trim((string) $v);
        if ($v === '') return '';
        if (preg_match('#^/[^\s]*$#', $v)) return $v;
        if (preg_match('#^https://[^\s/$.?\#].[^\s]*$#i', $v)) return esc_url_raw($v, ['https']);
        return self::bad($field, 'Bitte einen Pfad (/…) oder eine https-Adresse angeben.');
    }

    private static function image_id($v, $field) {
        $id = intval($v);
        if ($id <= 0 || get_post_type($id) !== 'attachment' || strpos((string) get_post_mime_type($id), 'image/') !== 0) return self::bad($field, 'Bild nicht gefunden.');
        return $id;
    }

    private static function link($l, $field) {
        if (!is_array($l)) return self::bad($field, 'Ungültiger Link.');
        $label = self::text($l['label'] ?? '', $field . '.label', 40);
        if (is_wp_error($label)) return $label;
        $url = self::url($l['url'] ?? '', $field . '.url');
        if (is_wp_error($url)) return $url;
        if ($label === '' || $url === '') return self::bad($field, 'Link braucht Text und Adresse.');
        return ['label' => $label, 'url' => $url];
    }

    /** WEBSITE prüfen und säubern → array | WP_Error */
    public static function clean(array $w) {
        $out = ['version' => 1];
        $t = (string) ($w['template'] ?? '');
        if ($t !== '' && !in_array($t, self::TEMPLATES, true)) return self::bad('template', 'Vorlage: club, festival oder klar.');
        $out['template'] = $t;
        $out['sections'] = [];
        $seen = [];
        if (isset($w['sections']) && !is_array($w['sections'])) return self::bad('sections', 'Ungültige Abschnitte.');
        foreach (array_values((array) ($w['sections'] ?? [])) as $i => $s) {
            $f = 'sections.' . $i;
            if (!is_array($s)) return self::bad($f, 'Ungültiger Abschnitt.');
            $key = (string) ($s['key'] ?? '');
            if (!isset(self::SECTIONS[$key])) return self::bad($f . '.key', 'Unbekannter Abschnitt „' . $key . '“.');
            if (isset($seen[$key])) return self::bad($f . '.key', 'Abschnitt „' . $key . '“ doppelt.');
            $seen[$key] = true;
            $c = ['key' => $key, 'enabled' => !array_key_exists('enabled', $s) || filter_var($s['enabled'], FILTER_VALIDATE_BOOLEAN)];
            foreach (self::SECTIONS[$key][1] as $opt) {
                if (!array_key_exists($opt, $s) || $s[$opt] === null || $s[$opt] === '') continue;
                $v = $s[$opt];
                switch ($opt) {
                    case 'max':
                        $max = self::MAX[$key] ?? 24;
                        if (!is_numeric($v) || intval($v) < 1 || intval($v) > $max) return self::bad($f . '.max', 'Zahl von 1 bis ' . $max . '.');
                        $c['max'] = intval($v);
                        break;
                    case 'title':
                    case 'eyebrow':
                        $x = self::text($v, $f . '.' . $opt);
                        if (is_wp_error($x)) return $x;
                        $c[$opt] = $x;
                        break;
                    case 'html':
                        if (mb_strlen((string) $v) > 20000) return self::bad($f . '.html', 'Höchstens 20 000 Zeichen.');
                        $c['html'] = wp_kses_post((string) $v);
                        break;
                    case 'facts':
                        if (!is_array($v) || count($v) > 8) return self::bad($f . '.facts', 'Höchstens 8 Fakten.');
                        $c['facts'] = [];
                        foreach (array_values($v) as $j => $fa) {
                            if (!is_array($fa)) return self::bad($f . '.facts.' . $j, 'Ungültiger Eintrag.');
                            $row = [];
                            foreach (['icon' => 40, 'label' => 120, 'note' => 120] as $fk => $ml) {
                                $x = self::text($fa[$fk] ?? '', $f . '.facts.' . $j . '.' . $fk, $ml);
                                if (is_wp_error($x)) return $x;
                                $row[$fk] = $x;
                            }
                            if ($row['label'] !== '') $c['facts'][] = $row;
                        }
                        break;
                    case 'images':
                        if (!is_array($v) || count($v) > 24) return self::bad($f . '.images', 'Höchstens 24 Bilder.');
                        $c['images'] = [];
                        foreach (array_values($v) as $j => $im) {
                            $id = self::image_id(is_array($im) ? ($im['id'] ?? 0) : $im, $f . '.images.' . $j);
                            if (is_wp_error($id)) return $id;
                            $c['images'][] = $id;
                        }
                        break;
                    case 'video':
                        if (!preg_match('#^https://#i', (string) $v)) return self::bad($f . '.video', 'Video bitte als https-Adresse.');
                        $c['video'] = esc_url_raw((string) $v, ['https']);
                        break;
                    case 'logos':
                        if (!is_array($v) || count($v) > 24) return self::bad($f . '.logos', 'Höchstens 24 Logos.');
                        $c['logos'] = [];
                        foreach (array_values($v) as $j => $lg) {
                            if (!is_array($lg)) return self::bad($f . '.logos.' . $j, 'Ungültiges Logo.');
                            $id = self::image_id(is_array($lg['image'] ?? null) ? ($lg['image']['id'] ?? 0) : ($lg['image'] ?? 0), $f . '.logos.' . $j . '.image');
                            if (is_wp_error($id)) return $id;
                            $u = self::url($lg['url'] ?? '', $f . '.logos.' . $j . '.url');
                            if (is_wp_error($u)) return $u;
                            $n = self::text($lg['name'] ?? '', $f . '.logos.' . $j . '.name');
                            if (is_wp_error($n)) return $n;
                            $c['logos'][] = ['image' => $id, 'url' => $u, 'name' => $n];
                        }
                        break;
                }
            }
            $out['sections'][] = $c;
        }
        // Menü
        $nav = is_array($w['nav'] ?? null) ? $w['nav'] : [];
        $out['nav'] = ['header' => [], 'cta' => null, 'footer' => []];
        if (isset($nav['header'])) {
            if (!is_array($nav['header']) || count($nav['header']) > 8) return self::bad('nav.header', 'Höchstens 8 Menüpunkte.');
            foreach (array_values($nav['header']) as $j => $l) { $x = self::link($l, 'nav.header.' . $j); if (is_wp_error($x)) return $x; $out['nav']['header'][] = $x; }
        }
        if (!empty($nav['cta'])) { $x = self::link($nav['cta'], 'nav.cta'); if (is_wp_error($x)) return $x; $out['nav']['cta'] = $x; }
        if (isset($nav['footer'])) {
            if (!is_array($nav['footer']) || count($nav['footer']) > 5) return self::bad('nav.footer', 'Höchstens 5 Gruppen.');
            foreach (array_values($nav['footer']) as $j => $g) {
                if (!is_array($g)) return self::bad('nav.footer.' . $j, 'Ungültige Gruppe.');
                $title = self::text($g['title'] ?? '', 'nav.footer.' . $j . '.title', 40);
                if (is_wp_error($title)) return $title;
                $links = [];
                if (!is_array($g['links'] ?? []) || count((array) ($g['links'] ?? [])) > 8) return self::bad('nav.footer.' . $j . '.links', 'Höchstens 8 Links je Gruppe.');
                foreach (array_values((array) ($g['links'] ?? [])) as $k => $l) { $x = self::link($l, 'nav.footer.' . $j . '.links.' . $k); if (is_wp_error($x)) return $x; $links[] = $x; }
                $out['nav']['footer'][] = ['title' => $title, 'links' => $links];
            }
        }
        // Texte
        $tx = is_array($w['texts'] ?? null) ? $w['texts'] : [];
        $out['texts'] = [];
        foreach (self::TEXTS as $k) {
            $x = self::text($tx[$k] ?? '', 'texts.' . $k, $k === 'seo_description' || $k === 'newsletter_text' ? 300 : 120);
            if (is_wp_error($x)) return $x;
            $out['texts'][$k] = $x;
        }
        return $out;
    }

    // ═══════════════ Aufbereiten ═══════════════

    private static function image_payload($id) {
        $id = intval($id);
        if (!$id || get_post_type($id) !== 'attachment') return null;
        $full = (string) wp_get_attachment_url($id);
        return ['id' => $id, 'large' => (string) (wp_get_attachment_image_url($id, 'large') ?: $full), 'medium_large' => (string) (wp_get_attachment_image_url($id, 'medium_large') ?: $full),
            'medium' => (string) (wp_get_attachment_image_url($id, 'medium') ?: $full)];
    }

    /** Vollständige Website: Vorlage, alle Abschnitte (fehlende nach Vorgabe), available, Bilder als Größen. */
    public static function expand(array $w = null) {
        self::$avail = null; // Schalter je Aufbereitung frisch lesen
        $w = $w ?: ['version' => 1, 'template' => '', 'sections' => [], 'nav' => ['header' => [], 'cta' => null, 'footer' => []], 'texts' => array_fill_keys(self::TEXTS, '')];
        $tpl = in_array((string) ($w['template'] ?? ''), self::TEMPLATES, true) ? $w['template'] : self::profile_template();
        $defaults = self::DEFAULTS[$tpl];
        $list = [];
        $have = [];
        foreach ((array) ($w['sections'] ?? []) as $s) { $list[] = $s; $have[$s['key']] = true; }
        // fehlende: zuerst die Vorgabe der Vorlage in ihrer Reihenfolge, dann die übrigen (aus)
        foreach (array_merge($defaults, array_diff(array_keys(self::SECTIONS), $defaults)) as $k) {
            if (!isset($have[$k])) $list[] = ['key' => $k, 'enabled' => in_array($k, $defaults, true)];
        }
        $out = [];
        foreach ($list as $s) {
            $k = $s['key'];
            $s['available'] = self::switch_on(self::SECTIONS[$k][2]);
            if (isset($s['images'])) $s['images'] = array_values(array_filter(array_map([__CLASS__, 'image_payload'], $s['images'])));
            if (isset($s['logos'])) $s['logos'] = array_map(function ($l) { $l['image'] = self::image_payload($l['image']); return $l; }, $s['logos']);
            $out[] = $s;
        }
        return ['version' => 1, 'template' => $tpl, 'sections' => $out, 'nav' => $w['nav'] ?? ['header' => [], 'cta' => null, 'footer' => []], 'texts' => ($w['texts'] ?? []) + array_fill_keys(self::TEXTS, '')];
    }

    /** Für /public/site-config und /public/website: veröffentlichter Stand (bzw. Vorgabe) aufbereitet. */
    public static function public_website($draft = false) {
        $o = self::store();
        $w = $draft ? ($o['draft'] ?? $o['published']) : $o['published'];
        $out = self::expand($w);
        $out['source'] = $w ? ($draft && $o['draft'] ? 'draft' : 'published') : 'default';
        $out['published_at'] = $o['published_at'] ?: null;
        return $out;
    }

    public static function catalog() {
        self::$avail = null;
        $sections = [];
        foreach (self::SECTIONS as $k => $def) {
            $d = [];
            foreach (self::TEMPLATES as $t) $d[$t] = in_array($k, self::DEFAULTS[$t], true);
            $sections[] = ['key' => $k, 'label' => $def[0], 'options' => $def[1], 'switch' => $def[2], 'available' => self::switch_on($def[2]), 'defaults' => $d] + (isset(self::MAX[$k]) ? ['max' => self::MAX[$k]] : []);
        }
        return ['templates' => self::TEMPLATES, 'sections' => $sections];
    }

    // ═══════════════ Domain ═══════════════

    public static function domain() {
        $d = get_option(self::OPT_DOMAIN, []);
        $d = is_array($d) ? $d : [];
        $live = class_exists('TIX_Customer_URL') && TIX_Customer_URL::host() ? (string) TIX_Customer_URL::host() : (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $live = (string) ($d['live_host'] ?? '') ?: $live;
        // Vorgabe der Vorschau-Adresse: neu.<live_host> (1.38.516), ohne „www.“
        $preview = (string) ($d['preview_host'] ?? '') ?: ($live !== '' ? 'neu.' . preg_replace('/^www\./', '', $live) : '');
        return ['status' => in_array($d['status'] ?? '', ['preview', 'live'], true) ? $d['status'] : 'preview', 'preview_host' => $preview,
            'live_host' => $live, 'switched_at' => $d['switched_at'] ?? null];
    }

    public static function domain_public() {
        $d = self::domain();
        unset($d['switched_at']);
        return $d;
    }

    private static function clean_domain($in) {
        if (!is_array($in)) return self::bad('domain', 'Ungültiger Domain-Status.');
        $d = self::domain();
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], ['preview', 'live'], true)) return self::bad('domain.status', 'Status: preview oder live.');
            if ($in['status'] === 'live' && $d['status'] !== 'live') $d['switched_at'] = current_time('c');
            if ($in['status'] === 'preview') $d['switched_at'] = null;
            $d['status'] = $in['status'];
        }
        foreach (['preview_host', 'live_host'] as $k) {
            if (!array_key_exists($k, $in)) continue;
            $h = strtolower(trim((string) $in[$k]));
            if ($h !== '' && !preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $h)) return self::bad('domain.' . $k, 'Bitte nur den Hostnamen angeben, z. B. neu.kitchenklub.de.');
            $d[$k] = $h;
        }
        return $d;
    }

    // ═══════════════ Vorschau-Schlüssel ═══════════════

    public static function preview_key($exp = null) {
        $exp = $exp ?: time() + self::PREVIEW_TTL;
        return $exp . '.' . substr(hash_hmac('sha256', 'website|' . $exp, wp_salt('auth')), 0, 32);
    }

    public static function preview_ok($key) {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{32})$/', (string) $key, $m)) return false;
        return intval($m[1]) > time() && hash_equals(self::preview_key(intval($m[1])), (string) $key);
    }

    // ═══════════════ REST ═══════════════

    private static function studio_payload() {
        $o = self::store();
        $by = $o['updated_by'] ? get_userdata(intval($o['updated_by'])) : null;
        $exp = time() + self::PREVIEW_TTL;
        return ['ok' => true, 'website' => $o['published'], 'draft' => $o['draft'], 'published_at' => $o['published_at'] ?: null, 'updated_at' => (string) $o['updated_at'],
            'updated_by' => $by ? ['id' => intval($by->ID), 'name' => (string) $by->display_name] : null,
            'preview' => ['key' => self::preview_key($exp), 'expires_at' => wp_date('c', $exp)],
            'catalog' => self::catalog(), 'features' => class_exists('TIX_Features') ? TIX_Features::map() : [], 'domain' => self::domain(), 'enabled' => self::package_enabled(),
            'history' => array_map(function ($h) { $u = get_userdata(intval($h['by'] ?? 0)); return ['published_at' => $h['at'] ?? null, 'by' => $u ? ['id' => intval($u->ID), 'name' => (string) $u->display_name] : null]; }, array_slice((array) $o['history'], 0, self::HISTORY))];
    }

    public static function rest_get(WP_REST_Request $req) {
        $r = rest_ensure_response(self::studio_payload());
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** POST /studio/website {website?, publish?, discard_draft?, domain?, updated_at} */
    public static function rest_save(WP_REST_Request $req) {
        $b = $req->get_json_params();
        $b = is_array($b) ? $b : $req->get_body_params();
        $o = self::store();
        if ((string) ($b['updated_at'] ?? '') !== (string) $o['updated_at']) {
            return new WP_Error('tix_website_conflict', 'Die Website wurde inzwischen von jemand anderem geändert. Bitte neu laden.', ['status' => 409, 'current' => self::studio_payload()]);
        }
        $ignored = [];
        $domain = null;
        if (array_key_exists('domain', $b)) {
            if (current_user_can('manage_options')) {
                $domain = self::clean_domain($b['domain']);
                if (is_wp_error($domain)) return $domain;
            } else {
                $ignored[] = 'domain';
            }
        }
        if (array_key_exists('website', $b)) {
            if (!is_array($b['website'])) return self::bad('website', 'Ungültige Website.');
            $w = self::clean($b['website']);
            if (is_wp_error($w)) return $w;
            $o['draft'] = $w;
        }
        $publish = !empty($b['publish']) && filter_var($b['publish'], FILTER_VALIDATE_BOOLEAN);
        if (!empty($b['discard_draft']) && filter_var($b['discard_draft'], FILTER_VALIDATE_BOOLEAN)) $o['draft'] = null;
        if ($publish) {
            $o['published'] = $o['draft'] ?? ($o['published'] ?? self::clean([]));
            $o['draft'] = null;
            $o['published_at'] = current_time('c');
            array_unshift($o['history'], ['at' => $o['published_at'], 'by' => get_current_user_id()]);
            $o['history'] = array_slice($o['history'], 0, self::HISTORY);
        }
        // Entwurf gleich veröffentlichtem Stand → kein Entwurf
        if ($o['draft'] !== null && $o['published'] !== null && wp_json_encode($o['draft']) === wp_json_encode($o['published'])) $o['draft'] = null;
        $o['updated_at'] = (string) round(microtime(true) * 1000);
        $o['updated_by'] = get_current_user_id();
        self::put($o);
        if ($domain !== null) update_option(self::OPT_DOMAIN, $domain, false);
        if ($publish && class_exists('TIX_Web_Revalidate')) {
            $d = self::domain();
            foreach (array_filter([$d['live_host'], $d['preview_host']]) as $h) TIX_Web_Revalidate::touch_domain($h);
        }
        $r = rest_ensure_response(self::studio_payload() + ['ignored' => $ignored]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** GET /public/website[?draft=<Vorschau-Schlüssel>] */
    public static function rest_public(WP_REST_Request $req) {
        $key = (string) $req->get_param('draft');
        $draft = $key !== '';
        if ($draft && !self::preview_ok($key)) return new WP_Error('tix_website_preview', 'Der Vorschau-Link ist abgelaufen. Bitte im Studio neu öffnen.', ['status' => 403]);
        $r = rest_ensure_response(['ok' => true, 'website' => self::public_website($draft), 'draft' => $draft, 'domain' => self::domain_public()]);
        if ($draft) { $r->header('Cache-Control', 'no-store'); $r->header('X-Robots-Tag', 'noindex, nofollow'); }
        else $r->header('Cache-Control', 'public, max-age=60');
        return $r;
    }
}

TIX_Website::init();
