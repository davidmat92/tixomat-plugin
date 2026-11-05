<?php
/**
 * TIX_Newsletter_Blocks – Baukasten für Newsletter (1.38.654, User 2026-11-05).
 *
 * Kampagnen speichern Blöcke als JSON (Spalte `blocks`); das HTML rendert der Server beim Versand frisch aus festen
 * Bausteinen (TIX_Mail_Layout, Marke, Dark Mode). Daten-Blöcke (Event, Event-Liste, Gutschein, Line-up, Programm, Preise,
 * Anfahrt, Galerie, App, Social) holen ihre Fakten bei jedem Rendern aus den echten Daten – wie der KI-Generator, der
 * dieselben Blöcke liefert. Alte Kampagnen ohne Blöcke erscheinen im Editor als ein HTML-Block.
 * Personalisierung: „{Vorname|du}“ in Überschrift/Text → Brevo {{ contact.FIRSTNAME | default: "du" }}.
 *
 * Dazu: Mediathek je Bereich (Upload mit Zuschnitt, max. 1200 px, JPEG/PNG; Event-Flyer und Galerie als Quellen),
 * Vorlagen (mitgeliefert + eigene), Render-Vorschau und Prüfhinweise. REST unter /marketing/newsletter/… (§86).
 */
if (!defined('ABSPATH')) exit;

class TIX_Newsletter_Blocks {

    const NS = 'tixomat/v1';
    const MAX_BLOCKS = 80;
    const MAX_WIDTH = 1200;
    const MAX_UPLOAD = 10485760;
    const META_MEDIA = '_tix_nl_media';
    const OPT_TPL = 'tix_nl_templates';
    const META_TPL = '_tix_nl_templates';
    const SIMPLE = ['heading', 'text', 'image', 'button', 'divider', 'spacer'];
    const DATA = ['event', 'events', 'coupon', 'lineup', 'program', 'prices', 'directions', 'gallery', 'app', 'social'];
    const TYPES = ['heading', 'text', 'image', 'image_text', 'button', 'divider', 'spacer', 'video', 'quote', 'columns', 'html',
        'event', 'events', 'coupon', 'lineup', 'program', 'prices', 'directions', 'gallery', 'app', 'social'];
    const SPAM = ['100% kostenlos', 'gratis!!!', 'jetzt klicken', 'nur heute!!!', 'gewinnen sie', 'garantiert', 'kein risiko', '€€€', 'click here', 'act now', 'free money'];

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }

    // ═══════════════ Prüfen ═══════════════

    private static function hex($v) { $v = trim((string) $v); return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : ''; }
    private static function url($v) { $u = esc_url_raw(trim((string) $v)); return preg_match('#^(https?://|mailto:|tel:|/)#i', $u) ? $u : ''; }
    private static function line($v, $n) { return mb_substr(trim(sanitize_text_field((string) $v)), 0, $n); }

    private static function rich($html) {
        $allowed = ['p' => ['style' => true], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'a' => ['href' => true, 'style' => true],
            'ul' => ['style' => true], 'ol' => ['style' => true], 'li' => [], 'span' => ['style' => true]];
        return mb_substr(wp_kses((string) $html, $allowed), 0, 20000);
    }

    /** Blöcke prüfen → bereinigte Liste | WP_Error (field = blocks.<n>.<feld>) */
    public static function sanitize($blocks, $depth = 0, $path = 'blocks') {
        if (!is_array($blocks)) return self::bad($path, 'Blöcke fehlen.');
        if (count($blocks) > self::MAX_BLOCKS) return self::bad($path, 'Höchstens ' . self::MAX_BLOCKS . ' Blöcke.');
        $out = [];
        foreach (array_values($blocks) as $i => $b) {
            $p = $path . '.' . $i;
            if (!is_array($b)) return self::bad($p, 'Ungültiger Block.');
            $t = (string) ($b['type'] ?? '');
            if (!in_array($t, self::TYPES, true) || ($depth > 0 && !in_array($t, self::SIMPLE, true))) return self::bad($p . '.type', 'Unbekannter Block.');
            $x = ['type' => $t, 'id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($b['id'] ?? '')) ?: wp_generate_password(8, false, false)];
            $st = (array) ($b['style'] ?? []);
            $x['style'] = array_filter(['bg' => self::hex($st['bg'] ?? ''), 'padding' => in_array($st['padding'] ?? '', ['none', 's', 'm', 'l'], true) ? $st['padding'] : '',
                'align' => in_array($st['align'] ?? '', ['left', 'center', 'right'], true) ? $st['align'] : '']);
            switch ($t) {
                case 'heading':
                    $x['text'] = self::line($b['text'] ?? '', 160);
                    if ($x['text'] === '') return self::bad($p . '.text', 'Die Überschrift ist leer.');
                    $x['level'] = intval($b['level'] ?? 1) === 2 ? 2 : 1;
                    $x['badge'] = self::line($b['badge'] ?? '', 30);
                    $x['hero'] = !empty($b['hero']);
                    break;
                case 'text':
                    $x['html'] = self::rich($b['html'] ?? '');
                    break;
                case 'quote':
                    $x['text'] = mb_substr(trim(sanitize_textarea_field((string) ($b['text'] ?? ''))), 0, 600);
                    $x['author'] = self::line($b['author'] ?? '', 80);
                    if ($x['text'] === '') return self::bad($p . '.text', 'Das Zitat ist leer.');
                    break;
                case 'image': case 'image_text': case 'video':
                    $x['src'] = self::url($b['src'] ?? '');
                    $x['alt'] = self::line($b['alt'] ?? '', 160);
                    $x['link'] = self::url($b['link'] ?? ($t === 'video' ? ($b['url'] ?? '') : ''));
                    if ($t === 'video') {
                        if ($x['link'] === '') return self::bad($p . '.url', 'Bitte den Link zum Video angeben.');
                        if ($x['src'] === '' && ($yt = self::youtube_id($x['link']))) $x['src'] = 'https://img.youtube.com/vi/' . $yt . '/hqdefault.jpg';
                    }
                    if ($x['src'] === '') return self::bad($p . '.src', 'Bitte ein Bild wählen.');
                    if ($t === 'image') $x['width'] = in_array($b['width'] ?? '', ['full', 'half'], true) ? $b['width'] : 'full';
                    if ($t === 'image_text') { $x['html'] = self::rich($b['html'] ?? ''); $x['image_side'] = ($b['image_side'] ?? 'left') === 'right' ? 'right' : 'left'; }
                    break;
                case 'button':
                    $x['label'] = self::line($b['label'] ?? '', 40);
                    $x['url'] = self::url($b['url'] ?? '');
                    $x['color'] = self::hex($b['color'] ?? '');
                    if ($x['label'] === '') return self::bad($p . '.label', 'Bitte eine Beschriftung eingeben.');
                    if ($x['url'] === '') return self::bad($p . '.url', 'Bitte einen gültigen Link angeben.');
                    break;
                case 'spacer':
                    $x['height'] = max(8, min(96, intval($b['height'] ?? 24)));
                    break;
                case 'divider':
                    break;
                case 'columns':
                    foreach (['left', 'right'] as $side) {
                        $r = self::sanitize((array) ($b[$side] ?? []), $depth + 1, $p . '.' . $side);
                        if (is_wp_error($r)) return $r;
                        $x[$side] = $r;
                    }
                    break;
                case 'html':
                    $x['html'] = wp_kses_post((string) ($b['html'] ?? ''));
                    break;
                case 'event': case 'lineup': case 'program': case 'prices': case 'directions': case 'gallery':
                    $x['event_id'] = absint($b['event_id'] ?? 0);
                    if (!$x['event_id'] || get_post_type($x['event_id']) !== 'event') return self::bad($p . '.event_id', 'Bitte ein Event wählen.');
                    $x['button'] = self::line($b['button'] ?? '', 28);
                    if ($t === 'event') $x['show_image'] = !array_key_exists('show_image', $b) || !empty($b['show_image']);
                    else $x['with_title'] = !empty($b['with_title']); // Überschrift mit Eventtitel (mehrere Events, 1.38.658)
                    if ($t === 'lineup') $x['limit'] = max(2, min(16, intval($b['limit'] ?? 8))); // Acts im Raster, danach „und X weitere“
                    break;
                case 'events':
                    $x['mode'] = ($b['mode'] ?? 'pick') === 'upcoming' ? 'upcoming' : 'pick';
                    $x['event_ids'] = array_values(array_unique(array_filter(array_map('absint', (array) ($b['event_ids'] ?? [])))));
                    $x['limit'] = max(1, min(12, intval($b['limit'] ?? 6)));
                    $x['show_images'] = !empty($b['show_images']);
                    if ($x['mode'] === 'pick' && !$x['event_ids']) return self::bad($p . '.event_ids', 'Bitte Events wählen.');
                    break;
                case 'coupon':
                    $x['code'] = strtoupper(self::line($b['code'] ?? '', 40));
                    $x['button'] = self::line($b['button'] ?? '', 28);
                    $x['event_id'] = absint($b['event_id'] ?? 0);
                    if ($x['code'] === '') return self::bad($p . '.code', 'Bitte einen Gutschein wählen.');
                    break;
                case 'app': case 'social':
                    $x['text'] = self::line($b['text'] ?? '', 200);
                    break;
            }
            $out[] = $x;
        }
        return $out;
    }

    private static function youtube_id($u) { return preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([A-Za-z0-9_-]{11})#', (string) $u, $m) ? $m[1] : ''; }

    // ═══════════════ Rendern ═══════════════

    private static function personal($s) {
        return preg_replace_callback('/\{Vorname(?:\|([^}]{0,30}))?\}/u', function ($m) { $d = str_replace('"', '', trim($m[1] ?? '')) ?: 'du'; return '{{ contact.FIRSTNAME | default: "' . $d . '" }}'; }, (string) $s);
    }

    private static function wrap($html, array $style) {
        if ($html === '' || (empty($style['bg']) && empty($style['padding']))) return $html;
        $pad = ['none' => '0', 's' => '8px 12px', 'm' => '16px 20px', 'l' => '28px 28px'][$style['padding'] ?? ''] ?? '16px 20px';
        $bg = $style['bg'] ?? '';
        $cls = $bg !== '' && class_exists('TIX_Mail_Layout') ? '' : '';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr><td' . ($bg !== '' ? ' bgcolor="' . $bg . '"' : '') . $cls . ' style="' . ($bg !== '' ? 'background:' . $bg . ';' : '') . 'padding:' . $pad . ';border-radius:12px;">' . $html . '</td></tr></table>';
    }

    private static function lb($html) { return ['type' => 'text', 'html' => $html]; }

    private static $facts = [];
    private static function facts($eid) {
        if (!array_key_exists($eid, self::$facts)) self::$facts[$eid] = class_exists('TIX_Newsletter_AI') ? TIX_Newsletter_AI::facts($eid) : null;
        return self::$facts[$eid];
    }

    private static function upcoming($org, $limit) {
        $ids = class_exists('TIX_Studio_Stats') ? array_map('intval', TIX_Studio_Stats::scope_ids()) : [];
        $args = ['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids', 'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]];
        if ($org) $args['meta_query'][] = ['relation' => 'OR', ['key' => '_tix_organizer_id', 'value' => $org], ['key' => '_tix_co_organizer_id', 'value' => $org]];
        elseif ($ids && class_exists('TIX_App_Scope') && TIX_App_Scope::scoped()) $args['post__in'] = $ids;
        return array_map('intval', get_posts($args));
    }

    /** Daten-Block über die Bausteine des KI-Generators (Fakten frisch aus den Daten) → Layout-Bausteine */
    private static function data(array $b, $org, array $brand) {
        $ids = $b['type'] === 'events' ? ($b['mode'] === 'upcoming' ? self::upcoming($org, $b['limit']) : array_slice($b['event_ids'], 0, 12)) : array_filter([intval($b['event_id'] ?? 0)]);
        $events = array_values(array_filter(array_map([__CLASS__, 'facts'], $ids)));
        $coupon = null;
        if ($b['type'] === 'coupon') {
            $c = TIX_Newsletter_AI::coupon($b['code'], $org);
            if (is_wp_error($c)) return [];
            $coupon = $c;
            if (!empty($b['event_id']) && ($f = self::facts(intval($b['event_id'])))) $events = [$f];
        }
        if ($b['type'] !== 'coupon' && $b['type'] !== 'app' && $b['type'] !== 'social' && !$events) return [];
        if ($b['type'] === 'events' && count($events) < 2) $b['type'] = $events ? 'event' : 'events';
        $ctx = ['events' => $events, 'coupon' => $coupon, 'brand' => $brand, 'address' => 'du', 'include' => [], 'name' => (string) ($brand['name'] ?? ''), 'goal' => 'event', 'title_heads' => !empty($b['with_title']), 'lineup_limit' => intval($b['limit'] ?? 8)];
        $variant = $b['type'] === 'event' ? (!empty($b['show_image']) ? 'story' : 'flyer') : ($b['type'] === 'events' ? (!empty($b['show_images']) ? 'story' : 'compact') : 'story');
        $blk = ['heading' => '', 'text' => (string) ($b['text'] ?? ''), 'button' => (string) ($b['button'] ?? '')];
        $removed = 0;
        return TIX_Newsletter_AI::data_block($b['type'], $blk, $ctx, ['money' => [], 'pct' => [], 'days' => [], 'times' => [], 'codes' => [], 'words' => ''], $removed, $variant);
    }

    private static function one(array $b, $org, array $brand) {
        $acc = (string) ($brand['accent'] ?? '#FC2F55');
        $on = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::on_color($acc) : '#FFFFFF';
        $align = $b['style']['align'] ?? '';
        $ta = $align !== '' ? 'text-align:' . $align . ';' : '';
        $e = function ($s) { return esc_html((string) $s); };
        switch ($b['type']) {
            case 'heading':
                $h = $b['badge'] !== '' ? '<div style="margin:0 0 10px;' . $ta . '"><span style="display:inline-block;background:' . $acc . ';color:' . $on . ';font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:5px 12px;border-radius:999px;">' . $e($b['badge']) . '</span></div>' : '';
                $size = $b['level'] === 1 ? 28 : 20;
                $h .= '<h' . $b['level'] . ' class="tm-title' . ($b['level'] === 1 ? ' tm-nl-hero' : '') . '" style="margin:' . ($b['level'] === 1 ? '0 0 14px' : '8px 0 10px') . ';font-size:' . $size . 'px;line-height:' . intval($size * 1.22) . 'px;font-weight:800;' . $ta . '">' . self::personal($e($b['text'])) . '</h' . $b['level'] . '>';
                return [self::lb($h)];
            case 'text':
                $html = self::personal(preg_replace('#<p>#i', '<p style="margin:0 0 14px;">', $b['html']));
                return $html === '' ? [] : [self::lb($ta !== '' ? '<div style="' . $ta . '">' . $html . '</div>' : $html)];
            case 'quote':
                return [self::lb('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr><td style="border-left:4px solid ' . $acc . ';padding:6px 0 6px 16px;">'
                    . '<p style="margin:0;font-size:18px;line-height:27px;font-style:italic;">' . nl2br($e($b['text']), false) . '</p>' . ($b['author'] !== '' ? '<p class="tm-muted" style="margin:6px 0 0;font-size:14px;">– ' . $e($b['author']) . '</p>' : '') . '</td></tr></table>')];
            case 'image': case 'video':
                $w = ($b['width'] ?? 'full') === 'half' ? 280 : 568;
                $img = '<img src="' . esc_url($b['src']) . '" width="' . $w . '" alt="' . esc_attr($b['alt']) . '" class="tm-img" style="display:block;width:100%;max-width:' . $w . 'px;height:auto;border:0;border-radius:12px;margin:0 ' . ($align === 'left' ? 'auto 0 0' : ($align === 'right' ? '0 0 auto' : 'auto')) . ';">';
                if ($b['link'] !== '') $img = '<a href="' . esc_url($b['link']) . '" style="text-decoration:none;">' . $img . '</a>';
                $cap = $b['type'] === 'video' ? '<p style="margin:8px 0 0;text-align:center;"><a href="' . esc_url($b['link']) . '" class="tm-accent-text" style="color:' . $acc . ';font-weight:700;text-decoration:none;">▶ Video ansehen</a></p>' : '';
                return [self::lb('<div style="margin:0 0 16px;">' . $img . $cap . '</div>')];
            case 'image_text':
                $img = '<img src="' . esc_url($b['src']) . '" width="220" alt="' . esc_attr($b['alt']) . '" class="tm-img" style="display:block;width:100%;max-width:220px;height:auto;border:0;border-radius:10px;">';
                if ($b['link'] !== '') $img = '<a href="' . esc_url($b['link']) . '">' . $img . '</a>';
                $ti = '<td width="40%" valign="top" style="padding:0 ' . ($b['image_side'] === 'left' ? '16px 0 0' : '0 0 16px') . ';">' . $img . '</td>';
                $tt = '<td valign="top" style="font-size:15px;line-height:23px;' . $ta . '">' . self::personal(preg_replace('#<p>#i', '<p style="margin:0 0 10px;">', $b['html'])) . '</td>';
                return [self::lb('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr>' . ($b['image_side'] === 'left' ? $ti . $tt : $tt . $ti) . '</tr></table>')];
            case 'button':
                $html = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::button($b['label'], $b['url']) : '<a href="' . esc_url($b['url']) . '">' . $e($b['label']) . '</a>';
                if ($b['color'] !== '') $html = str_replace([$acc, 'color:' . $on], [$b['color'], 'color:' . (class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::on_color($b['color']) : '#FFFFFF')], $html);
                if ($align === 'left' || $align === 'right') $html = str_replace('align="center" style="margin:12px auto 16px;"', 'align="' . $align . '" style="margin:12px 0 16px;"', $html);
                return [self::lb($html)];
            case 'divider': return [['type' => 'divider']];
            case 'spacer': return [self::lb('<div style="height:' . intval($b['height']) . 'px;line-height:' . intval($b['height']) . 'px;font-size:1px;">&nbsp;</div>')];
            case 'columns':
                $col = function ($list) use ($org, $brand) { return self::html_of($list, $org, $brand); };
                return [self::lb('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;"><tr><td width="50%" valign="top" style="padding:0 8px 0 0;">' . $col($b['left']) . '</td><td width="50%" valign="top" style="padding:0 0 0 8px;">' . $col($b['right']) . '</td></tr></table>')];
            case 'html': return $b['html'] === '' ? [] : [['type' => 'html', 'html' => $b['html']]];
            default: return self::data($b, $org, $brand);
        }
    }

    private static function html_of(array $blocks, $org, array $brand) {
        $out = '';
        foreach ($blocks as $b) {
            $parts = self::one($b, $org, $brand);
            if (!$parts) continue;
            $inner = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::blocks_html($parts) : implode('', array_column($parts, 'html'));
            $out .= self::wrap($inner, (array) ($b['style'] ?? []));
        }
        return $out;
    }

    /** Blöcke → Inhalt der Mail (ohne Gerüst; das setzt TIX_Newsletter::html) */
    public static function render(array $blocks, $org = 0) {
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand() : [];
        self::$facts = []; // je Rendern frisch (Versand im Cron: mehrere Kampagnen in einer Anfrage)
        return str_replace(["\r", "\n"], '', self::html_of($blocks, intval($org), $brand));
    }

    /** Alte Kampagne ohne Blöcke → ein HTML-Block */
    public static function from_legacy($content) {
        return trim((string) $content) === '' ? [] : [['type' => 'html', 'id' => 'legacy', 'style' => [], 'html' => wp_kses_post((string) $content)]];
    }

    // ═══════════════ Prüfhinweise ═══════════════

    /** → [{code, message, block?}] */
    public static function check($subject, $preheader, array $blocks, $html) {
        $w = [];
        $n = mb_strlen((string) $subject);
        if ($n === 0) $w[] = ['code' => 'subject_missing', 'message' => 'Der Betreff fehlt.'];
        elseif ($n > 60) $w[] = ['code' => 'subject_long', 'message' => 'Der Betreff ist mit ' . $n . ' Zeichen lang – auf dem Handy wird er nach etwa 40–60 Zeichen abgeschnitten.'];
        if (mb_strlen((string) $preheader) > 110) $w[] = ['code' => 'preheader_long', 'message' => 'Der Vorschautext ist länger als 110 Zeichen.'];
        $walk = function ($list, $prefix) use (&$walk, &$w) {
            foreach ($list as $i => $b) {
                $id = $b['id'] ?? ($prefix . $i);
                if (in_array($b['type'], ['image', 'image_text', 'video'], true) && trim((string) ($b['alt'] ?? '')) === '') $w[] = ['code' => 'alt_missing', 'message' => 'Ein Bild hat keinen Alt-Text (wichtig für Bildschirmleser und blockierte Bilder).', 'block' => $id];
                if ($b['type'] === 'button' && preg_match('#^https?://(localhost|127\.|example\.)#i', (string) $b['url'])) $w[] = ['code' => 'link_test', 'message' => 'Ein Knopf zeigt auf eine Test-Adresse.', 'block' => $id];
                if ($b['type'] === 'columns') { $walk($b['left'], $id . '.l'); $walk($b['right'], $id . '.r'); }
            }
        };
        $walk($blocks, 'b');
        if (preg_match_all('#href="([^"]*)"#i', (string) $html, $m)) foreach ($m[1] as $u) {
            $u = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
            if ($u === '' || $u === '#' || (!preg_match('#^(https?://[^/\s]+\.[^/\s]+|mailto:|tel:|\{\{)#i', $u))) { $w[] = ['code' => 'link_broken', 'message' => 'Ein Link ist leer oder ungültig: „' . mb_substr($u, 0, 60) . '“.']; break; }
        }
        $text = mb_strtolower(wp_strip_all_tags((string) $subject . ' ' . $html));
        foreach (self::SPAM as $s) if (mb_strpos($text, $s) !== false) { $w[] = ['code' => 'spam_word', 'message' => '„' . $s . '“ wirkt auf Spamfilter verdächtig.']; break; }
        if (preg_match('/[A-ZÄÖÜ]{12,}/u', (string) $subject) || substr_count((string) $subject, '!') > 2) $w[] = ['code' => 'spam_style', 'message' => 'Viele Großbuchstaben bzw. Ausrufezeichen im Betreff landen leicht im Spam.'];
        $kb = round(strlen((string) $html) / 1024);
        if ($kb > 100) $w[] = ['code' => 'too_big', 'message' => 'Die Mail ist ' . $kb . ' KB groß – Gmail kürzt ab etwa 102 KB.'];
        if (!$blocks) $w[] = ['code' => 'empty', 'message' => 'Die Mail ist noch leer.'];
        return $w;
    }

    // ═══════════════ Vorlagen ═══════════════

    public static function builtin_templates($org = 0) {
        $ev = class_exists('TIX_Studio_Stats') ? self::upcoming($org, 3) : [];
        $e = $ev[0] ?? 0;
        $site = trailingslashit(class_exists('TIX_Customer_URL') && TIX_Customer_URL::base() ? TIX_Customer_URL::base() : home_url('/'));
        $ev_blocks = function ($k) use ($e) { return $e ? [['type' => $k, 'event_id' => $e]] : []; };
        return [
            ['key' => 'event', 'name' => 'Event', 'blocks' => array_merge([['type' => 'heading', 'text' => 'Unser nächstes Highlight', 'level' => 1], ['type' => 'text', 'html' => '<p>Hallo {Vorname|du},</p><p>wir haben etwas Besonderes für dich vorbereitet.</p>']], $ev_blocks('event'), $ev_blocks('lineup'), [['type' => 'text', 'html' => '<p>Wir sehen uns!</p>']])],
            ['key' => 'overview', 'name' => 'Monatsübersicht', 'blocks' => [['type' => 'heading', 'text' => 'Das steht an', 'level' => 1], ['type' => 'text', 'html' => '<p>Hallo {Vorname|du}, hier sind unsere nächsten Events.</p>'], ['type' => 'events', 'mode' => 'upcoming', 'limit' => 6, 'show_images' => true]]],
            ['key' => 'voucher', 'name' => 'Gutschein-Aktion', 'blocks' => array_merge([['type' => 'heading', 'text' => 'Nur für dich', 'level' => 1, 'badge' => 'Aktion'], ['type' => 'text', 'html' => '<p>Hallo {Vorname|du},</p><p>mit diesem Code sparst du bei deinen nächsten Tickets.</p>']], $ev_blocks('event'))],
            ['key' => 'thanks', 'name' => 'Danke & Rückblick', 'blocks' => [['type' => 'heading', 'text' => 'Danke, dass du dabei warst!', 'level' => 1], ['type' => 'text', 'html' => '<p>Hallo {Vorname|du},</p><p>was für eine Nacht! Hier ein paar Eindrücke.</p>'], ['type' => 'social'], ['type' => 'button', 'label' => 'Nächste Events', 'url' => $site]]],
            ['key' => 'app', 'name' => 'App laden', 'blocks' => [['type' => 'heading', 'text' => 'Deine Tickets in der App', 'level' => 1], ['type' => 'text', 'html' => '<p>Hallo {Vorname|du},</p><p>mit unserer App hast du deine Tickets immer dabei – auch ohne Empfang.</p>'], ['type' => 'app']]],
        ];
    }

    private static function tpl_get($org) { $v = $org ? get_post_meta($org, self::META_TPL, true) : get_option(self::OPT_TPL, []); return is_array($v) ? array_values($v) : []; }
    private static function tpl_set($org, array $v) { if ($org) update_post_meta($org, self::META_TPL, array_values($v)); else update_option(self::OPT_TPL, array_values($v), false); }

    // ═══════════════ Mediathek ═══════════════

    private static function media_item($id, $source = 'library') {
        $m = wp_get_attachment_metadata($id);
        return ['id' => intval($id), 'url' => (string) wp_get_attachment_url($id), 'thumb' => (string) (wp_get_attachment_image_url($id, 'medium') ?: wp_get_attachment_url($id)),
            'width' => intval($m['width'] ?? 0), 'height' => intval($m['height'] ?? 0), 'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true), 'source' => $source];
    }

    /** Datei verkleinern (max. 1200 px), zuschneiden, als JPEG (bzw. PNG mit Transparenz) in die Mediathek → Anhang-ID | WP_Error */
    public static function store_image($tmp, $name, $org, array $crop = [], $alt = '') {
        if (!file_exists($tmp) || filesize($tmp) > self::MAX_UPLOAD) return self::bad('file', 'Das Bild ist zu groß (höchstens 10 MB).');
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return self::bad('file', 'Bitte ein Bild (JPEG, PNG, GIF oder WebP) wählen.');
        // Der Bild-Editor erkennt den Typ an der Endung: Kopie mit passender Endung verarbeiten (Upload-Dateien haben keine)
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$info['mime']];
        $work = $tmp . '.' . $ext;
        if (!@copy($tmp, $work)) return self::bad('file', 'Das Bild lässt sich nicht verarbeiten.');
        $ed = wp_get_image_editor($work);
        if (is_wp_error($ed)) { @unlink($work); return self::bad('file', 'Das Bild lässt sich nicht verarbeiten.'); }
        if ($crop && intval($crop['w'] ?? 0) > 10 && intval($crop['h'] ?? 0) > 10) $ed->crop(intval($crop['x'] ?? 0), intval($crop['y'] ?? 0), intval($crop['w']), intval($crop['h']));
        $sz = $ed->get_size();
        if ($sz['width'] > self::MAX_WIDTH) $ed->resize(self::MAX_WIDTH, null, false);
        $png = $info['mime'] === 'image/png' || $info['mime'] === 'image/gif';
        $mime = $png ? 'image/png' : 'image/jpeg'; // WebP zeigt Outlook nicht an → JPEG
        $ed->set_quality(82);
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'tix-newsletter/' . intval($org);
        wp_mkdir_p($dir);
        $base = sanitize_file_name(pathinfo((string) $name, PATHINFO_FILENAME) ?: 'bild') . '-' . wp_generate_password(6, false, false);
        $saved = $ed->save($dir . '/' . $base . ($png ? '.png' : '.jpg'), $mime);
        @unlink($work);
        if (is_wp_error($saved)) return self::bad('file', 'Das Bild ließ sich nicht speichern.');
        $att = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => $base, 'post_status' => 'inherit'], $saved['path']);
        if (!$att || is_wp_error($att)) return self::bad('file', 'Das Bild ließ sich nicht speichern.');
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $saved['path']));
        update_post_meta($att, self::META_MEDIA, intval($org));
        if ($alt !== '') update_post_meta($att, '_wp_attachment_image_alt', $alt);
        return intval($att);
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $mod = ['TIX_Ad_Accounts', 'perm_module'];
        $r = function ($path, $m, $cb) use ($mod) { register_rest_route(self::NS, $path, ['methods' => $m, 'callback' => [__CLASS__, $cb], 'permission_callback' => $mod]); };
        $r('/marketing/newsletter/render', 'POST', 'rest_render');
        $r('/marketing/newsletter/media', 'GET', 'rest_media');
        $r('/marketing/newsletter/media', 'POST', 'rest_upload');
        $r('/marketing/newsletter/media/(?P<id>\d+)', 'POST', 'rest_media_update');
        $r('/marketing/newsletter/media/(?P<id>\d+)', 'DELETE', 'rest_media_delete');
        $r('/marketing/newsletter/templates', 'GET', 'rest_templates');
        $r('/marketing/newsletter/templates', 'POST', 'rest_template_save');
        $r('/marketing/newsletter/templates/(?P<key>[a-z0-9-]{3,40})', 'DELETE', 'rest_template_delete');
    }

    private static function org(WP_REST_Request $req) { return TIX_Ad_Accounts::org($req); }

    /** POST /marketing/newsletter/render {blocks, subject?, preheader?, campaign_id?} → {html (Vorschau), content, size_kb, warnings} */
    public static function rest_render(WP_REST_Request $req) {
        $org = self::org($req);
        $b = self::sanitize($req->get_param('blocks'));
        if (is_wp_error($b)) return $b;
        $content = self::render($b, $org);
        $c = ['id' => absint($req->get_param('campaign_id')), 'organizer_id' => $org, 'subject' => self::line($req->get_param('subject'), 200), 'preheader' => self::line($req->get_param('preheader'), 200), 'content' => $content, 'blocks' => wp_json_encode($b)];
        $html = TIX_Newsletter::html($c);
        return rest_ensure_response(['ok' => true, 'blocks' => $b, 'content' => $content, 'html' => TIX_Newsletter::preview_html($html), 'size_kb' => round(strlen($html) / 1024, 1),
            'warnings' => self::check($c['subject'], $c['preheader'], $b, $html)]);
    }

    /** GET /marketing/newsletter/media?source=library|flyers|gallery&q&page */
    public static function rest_media(WP_REST_Request $req) {
        $org = self::org($req);
        $src = (string) ($req->get_param('source') ?: 'library');
        $page = max(1, intval($req->get_param('page') ?: 1));
        $items = [];
        if ($src === 'library') {
            $q = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 40, 'paged' => $page, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC',
                'meta_query' => [['key' => self::META_MEDIA, 'value' => $org]], 's' => (string) $req->get_param('q')]);
            foreach ($q->posts as $id) $items[] = self::media_item($id);
            $more = $page < $q->max_num_pages;
        } else {
            $ids = class_exists('TIX_Studio_Stats') ? array_map('intval', TIX_Studio_Stats::scope_ids()) : [];
            $evs = $ids ? get_posts(['post_type' => 'event', 'post__in' => $ids, 'post_status' => 'publish', 'posts_per_page' => 60, 'fields' => 'ids', 'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'DESC']) : [];
            foreach ($evs as $eid) {
                $title = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8');
                if ($src === 'flyers' && ($tid = get_post_thumbnail_id($eid))) $items[] = ['event_id' => intval($eid), 'event_title' => $title] + self::media_item($tid, 'flyer') + ['alt' => $title];
                if ($src === 'gallery') foreach (array_slice((array) get_post_meta($eid, '_tix_gallery', true), 0, 12) as $g) {
                    if (is_numeric($g) && wp_get_attachment_url(intval($g))) $items[] = ['event_id' => intval($eid), 'event_title' => $title] + self::media_item(intval($g), 'gallery');
                    elseif (is_string($g) && preg_match('#^https?://#', $g)) $items[] = ['id' => 0, 'url' => $g, 'thumb' => $g, 'width' => 0, 'height' => 0, 'alt' => 'Bild von ' . $title, 'source' => 'gallery', 'event_id' => intval($eid), 'event_title' => $title];
                }
            }
            $more = false;
        }
        return rest_ensure_response(['ok' => true, 'source' => $src, 'items' => $items, 'has_more' => $more, 'max_width' => self::MAX_WIDTH]);
    }

    /** POST /marketing/newsletter/media – Datei (multipart „file“) oder {data (Base64/data-URL), filename}; optional crop {x,y,w,h}, alt */
    public static function rest_upload(WP_REST_Request $req) {
        $org = self::org($req);
        $crop = (array) ($req->get_param('crop') ?: []);
        if (is_string($req->get_param('crop'))) $crop = (array) json_decode((string) $req->get_param('crop'), true);
        $alt = self::line($req->get_param('alt'), 160);
        $files = $req->get_file_params();
        if (!empty($files['file']['tmp_name'])) {
            $id = self::store_image($files['file']['tmp_name'], (string) $files['file']['name'], $org, $crop, $alt);
        } else {
            $data = (string) $req->get_param('data');
            if (preg_match('#^data:image/[a-z+]+;base64,#i', $data)) $data = substr($data, strpos($data, ',') + 1);
            $bin = base64_decode($data, true);
            if ($bin === false || strlen($bin) < 20) return self::bad('file', 'Bitte ein Bild hochladen.');
            if (strlen($bin) > self::MAX_UPLOAD) return self::bad('file', 'Das Bild ist zu groß (höchstens 10 MB).');
            if (!function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php';
            $tmp = wp_tempnam('tix-nl');
            file_put_contents($tmp, $bin);
            $id = self::store_image($tmp, (string) ($req->get_param('filename') ?: 'bild.jpg'), $org, $crop, $alt);
            @unlink($tmp);
        }
        if (is_wp_error($id)) return $id;
        return new WP_REST_Response(['ok' => true, 'item' => self::media_item($id)], 201);
    }

    private static function own_media($id, $org) {
        return get_post_type($id) === 'attachment' && get_post_meta($id, self::META_MEDIA, true) !== '' && intval(get_post_meta($id, self::META_MEDIA, true)) === intval($org);
    }

    public static function rest_media_update(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (!self::own_media($id, self::org($req))) return new WP_Error('not_found', 'Bild nicht gefunden.', ['status' => 404]);
        update_post_meta($id, '_wp_attachment_image_alt', self::line($req->get_param('alt'), 160));
        return rest_ensure_response(['ok' => true, 'item' => self::media_item($id)]);
    }

    public static function rest_media_delete(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (!self::own_media($id, self::org($req))) return new WP_Error('not_found', 'Bild nicht gefunden.', ['status' => 404]);
        wp_delete_attachment($id, true);
        return rest_ensure_response(['ok' => true]);
    }

    public static function rest_templates(WP_REST_Request $req) {
        $org = self::org($req);
        return rest_ensure_response(['ok' => true, 'builtin' => self::builtin_templates($org), 'own' => self::tpl_get($org)]);
    }

    /** POST /marketing/newsletter/templates {name, blocks} | {name, campaign_id} – höchstens 30 eigene Vorlagen */
    public static function rest_template_save(WP_REST_Request $req) {
        $org = self::org($req);
        $name = self::line($req->get_param('name'), 80);
        if ($name === '') return self::bad('name', 'Bitte einen Namen für die Vorlage angeben.');
        $blocks = $req->get_param('blocks');
        if ($blocks === null && ($cid = absint($req->get_param('campaign_id')))) $blocks = TIX_Newsletter::campaign_blocks($cid, $org);
        $b = self::sanitize($blocks);
        if (is_wp_error($b)) return $b;
        $all = self::tpl_get($org);
        if (count($all) >= 30) return self::bad('name', 'Höchstens 30 eigene Vorlagen – bitte eine alte löschen.', 'tix_tpl_full', 409);
        $key = 'own-' . strtolower(wp_generate_password(8, false, false));
        $all[] = ['key' => $key, 'name' => $name, 'blocks' => $b, 'created' => current_time('mysql', true)];
        self::tpl_set($org, $all);
        return new WP_REST_Response(['ok' => true, 'template' => end($all)], 201);
    }

    public static function rest_template_delete(WP_REST_Request $req) {
        $org = self::org($req);
        $all = self::tpl_get($org);
        $left = array_values(array_filter($all, function ($t) use ($req) { return ($t['key'] ?? '') !== (string) $req['key']; }));
        if (count($left) === count($all)) return new WP_Error('not_found', 'Vorlage nicht gefunden.', ['status' => 404]);
        self::tpl_set($org, $left);
        return rest_ensure_response(['ok' => true]);
    }
}

TIX_Newsletter_Blocks::init();
