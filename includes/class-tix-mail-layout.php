<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Mail_Layout – ein Mail-Layout für alle Seiten und alle Mails (1.38.471).
 *
 * Marke kommt aus der Seite (Studio-Marke, Seiten-Konfiguration), Lizenzseiten bekommen dasselbe ohne Anpassung:
 *   Name, Akzentfarbe (TIX_Studio::accent), Fußzeile mit Impressum/Datenschutz, Hilfe, Social, App-Links.
 * Mail-Felder der Studio-Marke (tix_studio_brand, 1.38.473, alle optional, im Studio unter „Marke“):
 *   mail_header light | brand | color (mail_header_color, Hex; leer = Akzent) · mail_logo_id (Logo auf hellem Grund, sonst das normale Logo) · mail_logo_on_color_id
 *   (Logo für farbigen Kopf; fehlt es, gilt light) · mail_from (Vorgabe noreply@<Domain der Seite>) · mail_from_name
 *   (Vorgabe Marke) · mail_reply_to (Vorgabe Kontakt- bzw. Support-Adresse).
 *
 * Bausteine (render(['title', 'subtitle', 'preheader', 'blocks' => [...], 'note', 'kind']):
 *   text {html} · button {label, url} · info {title?, rows [[label, value]]} · event {title, date, time, location, image}
 *   ticket {label, code, qr, sub} · table {rows [[name, qty, amount]], total, total_label} · notice {tone info|warn|success|error, html}
 *   code {value, hint} · divider · html {html} (fremdes HTML, wird an das Layout angeglichen, siehe normalize())
 * kind: customer (Standard) | account | admin | promo – steuert Feedback-Platz und Reply-To.
 *
 * Alle Mails laufen hier durch (wp_mail-Filter, Prio 900):
 *   - Text-Mails und HTML-Fragmente (auch WordPress-Kern und fremde Plugins) werden ins Layout gesetzt,
 *     vollständige fremde HTML-Dokumente bleiben unverändert, Kopfzeile „X-Tix-Raw: 1“ schaltet ab.
 *   - Absender je Seite statt „WordPress <wordpress@…>“: mail_from_name <mail_from> (FluentSMTP findet die Verbindung per Mapping).
 *   - Reply-To an das Support-Postfach bei Mails an Kunden (nicht an die Admin-Adresse).
 *   - Text-Fassung (multipart) aus den Bausteinen bzw. sauber aus dem HTML (ohne CSS und Vorschauzeile).
 * Technik: Tabellen, 600 px, mobil volle Breite, große Knöpfe (VML für Outlook), Systemschriften, Dark Mode
 * (prefers-color-scheme + Outlook.com [data-ogsc]).
 */
class TIX_Mail_Layout {

    const MARK = 'x-tix-mail';

    /** Text-Fassungen der zuletzt gerenderten Mails (md5(HTML) → Text), für phpmailer_init. */
    private static $alt = [];

    public static function init() {
        add_filter('wp_mail_from', [__CLASS__, 'filter_from'], 20);
        add_filter('wp_mail_from_name', [__CLASS__, 'filter_from_name'], 20);
        add_filter('wp_mail', [__CLASS__, 'filter_mail'], 900);
        add_action('phpmailer_init', [__CLASS__, 'phpmailer'], 5);
        add_action('update_option_tix_studio_brand', [__CLASS__, 'flush']);
    }

    // ═══════════════ Marke ═══════════════

    private static function hex($c, $fallback) {
        $c = ltrim(trim((string) $c), '#');
        if (preg_match('/^[0-9a-f]{3}$/i', $c)) $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        return preg_match('/^[0-9a-f]{6}$/i', $c) ? '#' . strtoupper($c) : $fallback;
    }

    private static function lum($hex) {
        $h = ltrim($hex, '#');
        $f = function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); };
        return 0.2126 * $f(hexdec(substr($h, 0, 2))) + 0.7152 * $f(hexdec(substr($h, 2, 2))) + 0.0722 * $f(hexdec(substr($h, 4, 2)));
    }

    /** Akzent für Text auf dunklem Grund (Dark Mode): zu dunkle Farben mit Weiß aufhellen, bis sie lesbar sind. */
    public static function accent_on_dark($hex) {
        $h = ltrim($hex, '#');
        $rgb = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        for ($t = 0; $t <= 0.8 && self::lum(sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2])) < 0.18; $t += 0.1) {
            $rgb = array_map(function ($v) { return (int) round($v + (255 - $v) * 0.2); }, $rgb);
        }
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }

    /** Schriftfarbe auf einer Fläche (weiß bzw. fast schwarz nach Kontrast). */
    public static function on_color($hex) {
        return self::lum($hex) > 0.45 ? '#111111' : '#FFFFFF';
    }

    private static $brand = null;

    public static function flush() { self::$brand = null; }

    /** Pfad (/impressum) → volle Adresse auf der Kundenseite. */
    private static function abs_url($u, $base) {
        $u = trim((string) $u);
        if ($u === '') return '';
        if ($u[0] === '/' && substr($u, 0, 2) !== '//') return untrailingslashit($base) . $u;
        return preg_match('#^https?://#i', $u) ? $u : '';
    }

    /** Marke der Seite; $override nur für Vorschau/Tests (z. B. andere Seite simulieren). */
    public static function brand(array $override = []) {
        if (self::$brand === null) {
            $o = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'mail_settings') ? (array) TIX_Studio::mail_settings() : [];
            $site = class_exists('TIX_Site_Config') && method_exists('TIX_Site_Config', 'payload') ? (array) TIX_Site_Config::payload() : [];
            $s = function_exists('tix_get_settings') ? (array) tix_get_settings() : [];
            $name = trim((string) ($site['name'] ?? '')) ?: trim((string) ($s['email_brand_name'] ?? ''))
                ?: html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
            $accent = self::hex(class_exists('TIX_Studio') ? TIX_Studio::accent() : '', '#FC2F55');
            $img = function ($id) { $id = intval($id); return $id ? (string) (wp_get_attachment_image_url($id, 'medium') ?: wp_get_attachment_url($id)) : ''; };
            $logo = $img($o['mail_logo_id'] ?? 0) ?: (class_exists('TIX_Studio') ? (string) TIX_Studio::logo() : '');
            if ($logo === '') $logo = (string) ($s['email_logo_url'] ?? '');
            $logo_on_accent = $img($o['mail_logo_on_color_id'] ?? 0);
            $legal = (array) ($site['legal'] ?? []);
            $contact = (array) ($site['contact'] ?? []);
            $social = array_filter((array) ($site['social'] ?? []), 'is_string');
            $app = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'app_info') ? (array) TIX_Studio::app_info() : [];
            $has_app = !empty($app['has_app']);
            $url = class_exists('TIX_Customer_URL') ? (TIX_Customer_URL::base() ?: home_url('/')) : home_url('/');
            $host = preg_replace('/^www\./', '', strtolower((string) wp_parse_url($url, PHP_URL_HOST)));
            $support = trim((string) ($o['mail_reply_to'] ?? '')) ?: trim((string) ($contact['email'] ?? '')) ?: trim((string) ($s['support_email'] ?? ''));
            $hdr = (string) ($o['mail_header'] ?? '');
            $header = $logo_on_accent === '' ? 'light' : ($hdr === 'light' ? 'light' : 'accent'); // „brand“/„color“ ohne Logo für farbigen Grund → hell
            // Eigene Kopf-Farbe (1.38.479): mail_header = color + mail_header_color; leer = Akzentfarbe. Knöpfe/Linien bleiben Akzent.
            $hcol = self::hex($o['mail_header_color'] ?? '', '');
            $head_bg = ($hdr === 'color' && $hcol !== '') ? $hcol : $accent;
            self::$brand = [
                'name' => $name,
                'accent' => $accent,
                'on_accent' => self::on_color($accent),
                'head_bg' => $head_bg,
                'on_head' => self::on_color($head_bg),
                'header_mode' => $header === 'light' ? 'light' : ($hdr === 'color' && $hcol !== '' ? 'color' : 'brand'),
                'logo' => $logo,
                'logo_on_accent' => $logo_on_accent,
                'header' => $header,
                'site_url' => untrailingslashit($url),
                'host' => $host,
                'support_email' => is_email($support) ? $support : '',
                'imprint' => self::abs_url($legal['imprint'] ?? '', $url),
                'privacy' => self::abs_url($legal['privacy'] ?? '', $url),
                'social' => $social,
                'ios' => $has_app ? (string) ($app['ios_url'] ?? '') : '',
                'android' => $has_app ? (string) ($app['android_url'] ?? '') : '',
                'company' => is_string($contact['company'] ?? null) ? $contact['company'] : '',
                'address' => is_string($contact['address'] ?? null) ? $contact['address'] : '',
                'from_email' => is_email(trim((string) ($o['mail_from'] ?? ''))) ? trim((string) $o['mail_from']) : ($host !== '' && strpos($host, '.') !== false ? 'noreply@' . $host : ''),
                'from_name' => trim((string) ($o['mail_from_name'] ?? '')) ?: $name,
            ];
        }
        return $override ? array_merge(self::$brand, $override) : self::$brand;
    }

    // ═══════════════ Bausteine ═══════════════

    private static $c = [];

    private static function colors(array $b) {
        return [
            'bg' => '#F3F4F6', 'card' => '#FFFFFF', 'text' => '#111827', 'muted' => '#6B7280', 'line' => '#E5E7EB', 'soft' => '#F9FAFB',
            'accent' => $b['accent'], 'on_accent' => $b['on_accent'],
            'head_bg' => $b['head_bg'] ?? $b['accent'], 'on_head' => $b['on_head'] ?? $b['on_accent'],
        ];
    }

    private static function e($s) { return esc_html((string) $s); }

    /** Knopf in Markenfarbe (VML für Outlook). Öffentlich für Mails, die eigenes HTML bauen. */
    public static function button($label, $url) {
        $c = self::$c ?: self::colors(self::brand());
        $label = self::e(html_entity_decode((string) $label, ENT_QUOTES, 'UTF-8'));
        $url = esc_url($url);
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:12px auto 16px;"><tr><td align="center" class="tm-btn-td" bgcolor="' . $c['accent'] . '" style="border-radius:10px;">'
            . '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $url . '" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="20%" stroke="f" fillcolor="' . $c['accent'] . '"><w:anchorlock/><center style="color:' . $c['on_accent'] . ';font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">' . $label . '</center></v:roundrect><![endif]-->'
            . '<!--[if !mso]><!--><a href="' . $url . '" class="tm-btn" style="display:inline-block;padding:15px 28px;min-width:200px;text-align:center;background:' . $c['accent'] . ';color:' . $c['on_accent'] . ';font-weight:700;font-size:16px;line-height:20px;text-decoration:none;border-radius:10px;">' . $label . '</a><!--<![endif]-->'
            . '</td></tr></table>';
    }

    const TONES = ['info' => ['#EFF6FF', '#1E3A8A'], 'warn' => ['#FFFBEB', '#78350F'], 'success' => ['#ECFDF5', '#064E3B'], 'error' => ['#FEF2F2', '#7F1D1D']];

    private static function block(array $b) {
        $c = self::$c;
        $type = (string) ($b['type'] ?? 'text');
        switch ($type) {
            case 'text':
                return '<div class="tm-text" style="margin:0 0 16px;font-size:16px;line-height:24px;color:' . $c['text'] . ';">' . wp_kses_post((string) ($b['html'] ?? '')) . '</div>';
            case 'button':
                return self::button($b['label'] ?? 'Öffnen', $b['url'] ?? '#');
            case 'divider':
                return '<div class="tm-line" style="height:1px;background:' . $c['line'] . ';margin:20px 0;line-height:1px;font-size:1px;">&nbsp;</div>';
            case 'html':
                return '<div class="tm-legacy" style="font-size:16px;line-height:24px;color:' . $c['text'] . ';">' . self::normalize((string) ($b['html'] ?? '')) . '</div>';
            case 'notice':
                $tone = isset(self::TONES[$b['tone'] ?? '']) ? $b['tone'] : 'info';
                list($bg, $fg) = self::TONES[$tone];
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr><td class="tm-notice tm-notice-' . $tone . '" style="background:' . $bg . ';color:' . $fg . ';border-radius:10px;padding:14px 16px;font-size:14px;line-height:21px;">' . wp_kses_post((string) ($b['html'] ?? '')) . '</td></tr></table>';
            case 'code':
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 16px;"><tr><td align="center" class="tm-soft" style="background:' . $c['soft'] . ';border:1px solid ' . $c['line'] . ';border-radius:12px;padding:18px 12px;">'
                    . '<div class="tm-code" style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:30px;line-height:36px;letter-spacing:6px;font-weight:700;color:' . $c['text'] . ';">' . self::e($b['value'] ?? '') . '</div>'
                    . (!empty($b['hint']) ? '<div class="tm-muted" style="margin-top:6px;font-size:13px;color:' . $c['muted'] . ';">' . self::e($b['hint']) . '</div>' : '')
                    . '</td></tr></table>';
            case 'info':
                $rows = '';
                foreach ((array) ($b['rows'] ?? []) as $r) {
                    $rows .= '<tr><td class="tm-muted" style="padding:6px 12px 6px 0;font-size:14px;color:' . $c['muted'] . ';width:40%;vertical-align:top;">' . self::e($r[0] ?? '') . '</td><td style="padding:6px 0;font-size:14px;color:' . $c['text'] . ';font-weight:600;text-align:right;vertical-align:top;word-break:break-word;">' . wp_kses_post((string) ($r[1] ?? '')) . '</td></tr>';
                }
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr><td class="tm-soft" style="background:' . $c['soft'] . ';border-radius:12px;padding:14px 16px;">'
                    . (!empty($b['title']) ? '<div class="tm-muted" style="font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:' . $c['muted'] . ';margin-bottom:4px;">' . self::e($b['title']) . '</div>' : '')
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></td></tr></table>';
            case 'event':
                $img = (string) ($b['image'] ?? '');
                $meta = trim(implode(' · ', array_filter([(string) ($b['date'] ?? ''), (string) ($b['time'] ?? '')])));
                $inner = ($img !== '' ? '<tr><td style="padding:0;"><img src="' . esc_url($img) . '" width="568" alt="" class="tm-img" style="display:block;width:100%;max-width:568px;height:auto;border:0;border-radius:12px 12px 0 0;"></td></tr>' : '')
                    . '<tr><td style="padding:14px 16px 16px;">'
                    . '<div style="font-size:18px;line-height:24px;font-weight:700;color:' . $c['text'] . ';">' . self::e($b['title'] ?? '') . '</div>'
                    . ($meta !== '' ? '<div style="margin-top:6px;font-size:14px;color:' . $c['text'] . ';">📅 ' . self::e($meta) . '</div>' : '')
                    . (!empty($b['location']) ? '<div class="tm-muted" style="margin-top:2px;font-size:14px;color:' . $c['muted'] . ';">📍 ' . self::e($b['location']) . '</div>' : '')
                    . '</td></tr>';
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-card2" style="margin:0 0 16px;border:1px solid ' . $c['line'] . ';border-radius:12px;border-collapse:separate;">' . $inner . '</table>';
            case 'ticket':
                $qr = (string) ($b['qr'] ?? '');
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-card2" style="margin:0 0 12px;border:1px solid ' . $c['line'] . ';border-radius:12px;border-collapse:separate;"><tr>'
                    . ($qr !== '' ? '<td width="112" style="padding:12px;vertical-align:middle;"><img src="' . esc_url($qr) . '" width="96" height="96" alt="QR-Code" style="display:block;border:0;background:#FFFFFF;border-radius:6px;"></td>' : '')
                    . '<td style="padding:12px 16px 12px ' . ($qr !== '' ? '0' : '16px') . ';vertical-align:middle;">'
                    . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . $c['accent'] . ';" class="tm-accent-text">' . self::e($b['label'] ?? 'Ticket') . '</div>'
                    . '<div style="margin-top:2px;font-size:16px;font-weight:700;color:' . $c['text'] . ';">' . self::e($b['title'] ?? '') . '</div>'
                    . (!empty($b['sub']) ? '<div class="tm-muted" style="margin-top:2px;font-size:13px;color:' . $c['muted'] . ';">' . self::e($b['sub']) . '</div>' : '')
                    . (!empty($b['code']) ? '<div style="margin-top:6px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:15px;letter-spacing:2px;color:' . $c['text'] . ';">' . self::e($b['code']) . '</div>' : '')
                    . (!empty($b['link']) && !empty($b['link_label']) ? '<div style="margin-top:8px;font-size:14px;"><a href="' . esc_url($b['link']) . '" class="tm-accent-text" style="color:' . $c['accent'] . ';font-weight:700;text-decoration:none;">' . self::e($b['link_label']) . ' →</a></div>' : '')
                    . '</td></tr></table>';
            case 'table':
                $rows = '';
                foreach ((array) ($b['rows'] ?? []) as $r) {
                    $rows .= '<tr><td class="tm-line" style="padding:10px 0;border-bottom:1px solid ' . $c['line'] . ';font-size:15px;color:' . $c['text'] . ';">' . self::e($r[0] ?? '') . (isset($r[1]) && $r[1] !== '' ? ' <span class="tm-muted" style="color:' . $c['muted'] . ';">× ' . self::e($r[1]) . '</span>' : '') . '</td>'
                        . '<td class="tm-line" style="padding:10px 0 10px 12px;border-bottom:1px solid ' . $c['line'] . ';font-size:15px;color:' . $c['text'] . ';text-align:right;white-space:nowrap;">' . self::e($r[2] ?? '') . '</td></tr>';
                }
                if (!empty($b['total'])) {
                    $rows .= '<tr><td style="padding:12px 0 0;font-size:16px;font-weight:700;color:' . $c['text'] . ';">' . self::e($b['total_label'] ?? 'Gesamt') . '</td><td style="padding:12px 0 0;font-size:16px;font-weight:700;color:' . $c['text'] . ';text-align:right;white-space:nowrap;">' . self::e($b['total']) . '</td></tr>';
                }
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">' . $rows . '</table>';
        }
        return '';
    }

    // ═══════════════ Fremdes HTML angleichen ═══════════════

    /**
     * Bestehende HTML-Bausteine (ältere Mails, Texte aus Einstellungen) an das Layout angleichen:
     * Knöpfe → Knopf in Markenfarbe, eigene Schriften raus, helle Kästen/graue Texte bekommen Dark-Mode-Klassen,
     * Absätze ohne Stil bekommen den Grundstil.
     */
    public static function normalize($html) {
        $html = (string) $html;
        if ($html === '') return '';
        // Knöpfe: <a … style="…inline-block…background…">Label</a> (auch in <p>/<div> mit Zentrierung)
        $btn = '<a\s(?![^>]*tm-btn)[^>]*?href=(?<q>["\'])(?<href>[^"\']+)(?P=q)[^>]*?style="[^"]*(?:display\s*:\s*inline-block|display\s*:\s*block)[^"]*background[^"]*"[^>]*>(?<label>.*?)</a>';
        $to_button = function ($m) {
            $label = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($m['label'])));
            return ($label !== '' && mb_strlen($label) <= 60) ? self::button($label, html_entity_decode($m['href'], ENT_QUOTES, 'UTF-8')) : $m[0];
        };
        $html = preg_replace_callback('#<(?<tag>p|div)\b[^>]*>\s*' . $btn . '\s*</(?P=tag)>#is', $to_button, $html);
        $html = preg_replace_callback('#' . $btn . '#is', $to_button, $html);
        // Eigene Schriften (Inter, Google-Fonts) raus – Systemschriften aus dem Gerüst
        $html = preg_replace('#<link\b[^>]*fonts\.googleapis[^>]*>#i', '', $html);
        $html = preg_replace_callback('#style="([^"]*)"#i', function ($m) {
            $st = $m[1];
            if (stripos($st, 'monospace') === false) $st = preg_replace('/font-family\s*:[^;"]*;?/i', '', $st);
            return 'style="' . $st . '"';
        }, $html);
        // Kästen mit hellem Grund und graue Texte: Dark-Mode-Klassen
        $html = preg_replace_callback('#<(td|div|p|span|table)\b((?:(?!class=)[^>])*?)style="([^"]*)"#i', function ($m) {
            $st = $m[3];
            $cls = [];
            if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-f]{3,6})\b/i', $st, $bg)) {
                $h = self::hex($bg[1], '');
                if ($h !== '' && $h !== '#FFFFFF') $cls[] = self::lum($h) > 0.6 ? 'tm-soft' : 'tm-dark';
            }
            if (!$cls && preg_match('/(?<![-\w])color\s*:\s*(#[0-9a-f]{3,6})\b/i', $st, $fg)) {
                $h = self::hex($fg[1], '');
                if ($h !== '' && self::lum($h) > 0.12 && self::lum($h) < 0.5) $cls[] = 'tm-muted';
            }
            return '<' . $m[1] . ($cls ? ' class="' . implode(' ', $cls) . '"' : '') . $m[2] . 'style="' . $st . '"';
        }, $html);
        // Absätze ohne Stil: Grundstil
        $html = preg_replace('#<p>#i', '<p style="margin:0 0 14px;">', $html);
        return $html;
    }

    // ═══════════════ Gerüst ═══════════════

    /** Komplette HTML-Mail. $m: title, subtitle, preheader, blocks, note, kind, brand (Override). */
    public static function render(array $m) {
        $b = self::brand((array) ($m['brand'] ?? []));
        $prev_c = self::$c;
        self::$c = self::colors($b);
        $c = self::$c;
        $kind = in_array($m['kind'] ?? '', ['customer', 'account', 'admin', 'promo'], true) ? $m['kind'] : 'customer';
        $title = html_entity_decode((string) ($m['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sub = html_entity_decode((string) ($m['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $pre = html_entity_decode((string) ($m['preheader'] ?? ''), ENT_QUOTES, 'UTF-8') ?: $sub;
        $body = '';
        foreach ((array) ($m['blocks'] ?? []) as $blk) $body .= self::block((array) $blk);

        // Kopf: hell (Logo auf Weiß + Akzentlinie) oder in Akzentfarbe (Logo für farbigen Grund bzw. Name in Weiß)
        $accent_head = $b['header'] === 'accent';
        $logo = $accent_head ? ($b['logo_on_accent'] ?: '') : $b['logo'];
        $brand_html = $logo !== ''
            ? '<img src="' . esc_url($logo) . '" alt="' . esc_attr($b['name']) . '" height="44" style="display:block;height:44px;width:auto;max-width:220px;border:0;margin:0 auto;">'
            : '<span style="font-size:22px;font-weight:800;color:' . ($accent_head ? $c['on_head'] : $c['text']) . ';">' . self::e($b['name']) . '</span>';
        $head = $accent_head
            ? '<tr><td align="center" bgcolor="' . $c['head_bg'] . '" class="tm-head-accent" style="background:' . $c['head_bg'] . ';padding:24px 24px;border-radius:16px 16px 0 0;">' . $brand_html . '</td></tr>'
            : '<tr><td align="center" class="tm-head" style="background:' . $c['card'] . ';padding:24px 24px 18px;border-radius:16px 16px 0 0;">' . $brand_html . '</td></tr>'
              . '<tr><td style="height:4px;line-height:4px;font-size:4px;background:' . $c['accent'] . ';">&nbsp;</td></tr>';

        // Fußzeile
        $links = [];
        if ($b['site_url']) $links[] = '<a href="' . esc_url($b['site_url']) . '" style="color:' . $c['muted'] . ';text-decoration:underline;">' . self::e($b['host'] ?: $b['name']) . '</a>';
        if ($b['imprint']) $links[] = '<a href="' . esc_url($b['imprint']) . '" style="color:' . $c['muted'] . ';text-decoration:underline;">Impressum</a>';
        if ($b['privacy']) $links[] = '<a href="' . esc_url($b['privacy']) . '" style="color:' . $c['muted'] . ';text-decoration:underline;">Datenschutz</a>';
        if ($b['support_email']) $links[] = '<a href="mailto:' . esc_attr($b['support_email']) . '" style="color:' . $c['muted'] . ';text-decoration:underline;">Hilfe</a>';
        $social_names = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp', 'spotify' => 'Spotify'];
        $soc = [];
        if ($kind !== 'admin') {
            foreach ($b['social'] as $k => $u) if (isset($social_names[$k]) && is_string($u) && preg_match('#^https?://#i', $u)) $soc[] = '<a href="' . esc_url($u) . '" style="color:' . $c['muted'] . ';text-decoration:none;font-weight:600;">' . $social_names[$k] . '</a>';
        }
        $apps = [];
        if ($kind !== 'admin') {
            if ($b['ios']) $apps[] = '<a href="' . esc_url($b['ios']) . '" style="color:' . $c['muted'] . ';text-decoration:none;font-weight:600;">App Store</a>';
            if ($b['android']) $apps[] = '<a href="' . esc_url($b['android']) . '" style="color:' . $c['muted'] . ';text-decoration:none;font-weight:600;">Google Play</a>';
        }
        $foot = '<tr><td class="tm-foot" style="padding:20px 24px 28px;text-align:center;font-size:12px;line-height:19px;color:' . $c['muted'] . ';">'
            . (!empty($m['note']) ? '<div style="margin-bottom:10px;">' . wp_kses_post(html_entity_decode((string) $m['note'], ENT_QUOTES, 'UTF-8')) . '</div>' : '')
            . ($soc ? '<div style="margin-bottom:6px;">' . implode(' &nbsp;·&nbsp; ', $soc) . '</div>' : '')
            . ($apps ? '<div style="margin-bottom:6px;">' . self::e('Hol dir die App: ') . implode(' &nbsp;·&nbsp; ', $apps) . '</div>' : '')
            . '<div>' . implode(' &nbsp;·&nbsp; ', $links) . '</div>'
            . (($b['company'] || $b['address']) ? '<div style="margin-top:6px;">' . self::e(trim($b['company'] . ($b['company'] && $b['address'] ? ', ' : '') . $b['address'])) . '</div>' : '')
            . '</td></tr>';


        $dark_soft = '#1F1F28';
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $html = '<!DOCTYPE html><html lang="de" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<meta name="' . self::MARK . '" content="' . $kind . '">'
            . '<title>' . self::e($title) . '</title>'
            . '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>td,a,div,span,p{font-family:Arial,sans-serif !important;}</style><![endif]-->'
            . '<style>'
            . ':root{color-scheme:light dark;supported-color-schemes:light dark;}'
            . 'body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;}'
            . 'a{color:' . $c['accent'] . ';}img{-ms-interpolation-mode:bicubic;}'
            . 'a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;}'
            . '@media (max-width:620px){.tm-wrap{width:100%!important;}.tm-pad{padding:20px 18px!important;}.tm-outer{padding:0!important;}.tm-card{border-radius:0!important;}.tm-head,.tm-head-accent{border-radius:0!important;}.tm-btn{display:block!important;min-width:0!important;}.tm-title{font-size:24px!important;line-height:30px!important;}.tm-legacy table{max-width:100%!important;}}'
            . '@media (prefers-color-scheme:dark){body,.tm-bg{background:#0B0B0F!important;}.tm-card,.tm-head{background:#16161D!important;}'
            . '.tm-text,.tm-title,td,div,span,p,li,strong,b,h1,h2,h3,h4{color:#ECECF1!important;}'
            . '.tm-muted,.tm-foot,.tm-foot a,.tm-foot div,.tm-foot span{color:#A1A1AA!important;}.tm-accent-text,.tm-legacy a{color:' . self::accent_on_dark($c['accent']) . '!important;}'
            . '.tm-line{border-color:#2A2A35!important;}.tm-soft{background:' . $dark_soft . '!important;border-color:#2A2A35!important;}.tm-card2{border-color:#2A2A35!important;}'
            . '.tm-notice-info{background:#172036!important;}.tm-notice-warn{background:#2A2012!important;}.tm-notice-success{background:#10261D!important;}.tm-notice-error{background:#2C1416!important;}'
            . '.tm-btn,.tm-btn-td,.tm-legacy .tm-btn{color:' . $c['on_accent'] . '!important;}.tm-head-accent,.tm-head-accent *{color:' . $c['on_head'] . '!important;}}'
            . '[data-ogsc] .tm-card,[data-ogsc] .tm-head{background:#16161D!important;}[data-ogsc] .tm-text,[data-ogsc] .tm-title{color:#ECECF1!important;}[data-ogsc] .tm-soft{background:' . $dark_soft . '!important;}'
            . '</style></head>'
            . '<body class="tm-bg" style="margin:0;padding:0;background:' . $c['bg'] . ';font-family:' . $font . ';">'
            . ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e($pre) . str_repeat('&#847;&zwnj;&nbsp;', 30) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-bg" style="background:' . $c['bg'] . ';"><tr><td align="center" class="tm-outer" style="padding:28px 12px;">'
            . '<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="tm-wrap" style="width:600px;max-width:600px;">'
            . '<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-card" style="background:' . $c['card'] . ';border-radius:16px;border-collapse:separate;">'
            . $head
            . '<tr><td class="tm-pad" style="padding:28px 32px 12px;font-family:' . $font . ';">'
            . ($title !== '' ? '<h1 class="tm-title" style="margin:0 0 ' . ($sub !== '' ? '6px' : '16px') . ';font-size:26px;line-height:32px;font-weight:800;color:' . $c['text'] . ';">' . self::e($title) . '</h1>' : '')
            . ($sub !== '' ? '<div class="tm-muted" style="margin:0 0 20px;font-size:14px;line-height:20px;color:' . $c['muted'] . ';">' . self::e($sub) . '</div>' : '')
            . $body
            . '</td></tr>'
            . '</table></td></tr>'
            . $foot
            . '</table>'
            . '<!--[if mso]></td></tr></table><![endif]-->'
            . '</td></tr></table></body></html>';
        self::$alt[md5($html)] = self::text($m);
        if (count(self::$alt) > 20) self::$alt = array_slice(self::$alt, -20, null, true);
        self::$c = $prev_c;
        return $html;
    }

    /** Text-Fassung (multipart) aus denselben Angaben. */
    public static function text(array $m) {
        $b = self::brand((array) ($m['brand'] ?? []));
        $d = function ($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); };
        $out = [];
        if (!empty($m['title'])) { $t = $d($m['title']); $out[] = $t . "\n" . str_repeat('=', min(60, mb_strlen($t))); }
        if (!empty($m['subtitle'])) $out[] = $d($m['subtitle']);
        foreach ((array) ($m['blocks'] ?? []) as $blk) {
            $t = (string) ($blk['type'] ?? 'text');
            if ($t === 'text' || $t === 'notice' || $t === 'html') $out[] = self::html_to_text((string) ($blk['html'] ?? ''));
            elseif ($t === 'button') $out[] = $d($blk['label'] ?? '') . ': ' . (string) ($blk['url'] ?? '');
            elseif ($t === 'code') $out[] = (string) ($blk['value'] ?? '') . (!empty($blk['hint']) ? ' (' . $blk['hint'] . ')' : '');
            elseif ($t === 'info') { $l = !empty($blk['title']) ? [$d($blk['title'])] : []; foreach ((array) ($blk['rows'] ?? []) as $r) $l[] = $d($r[0] ?? '') . ': ' . self::html_to_text((string) ($r[1] ?? '')); $out[] = implode("\n", $l); }
            elseif ($t === 'event') $out[] = trim($d($blk['title'] ?? '') . "\n" . trim((string) ($blk['date'] ?? '') . ' ' . (string) ($blk['time'] ?? '')) . "\n" . (string) ($blk['location'] ?? ''));
            elseif ($t === 'ticket') $out[] = trim($d($blk['label'] ?? 'Ticket') . ': ' . $d($blk['title'] ?? '') . ' ' . (string) ($blk['code'] ?? '') . (!empty($blk['link']) ? "\n" . $blk['link'] : ''));
            elseif ($t === 'table') { $l = []; foreach ((array) ($blk['rows'] ?? []) as $r) $l[] = $d($r[0] ?? '') . (isset($r[1]) && $r[1] !== '' ? ' × ' . $r[1] : '') . ' – ' . (string) ($r[2] ?? ''); if (!empty($blk['total'])) $l[] = ($blk['total_label'] ?? 'Gesamt') . ': ' . $blk['total']; $out[] = implode("\n", $l); }
        }
        if (!empty($m['note'])) $out[] = self::html_to_text((string) $m['note']);
        $out[] = "--\n" . $b['name'] . ($b['site_url'] ? ' · ' . $b['site_url'] : '') . ($b['imprint'] ? "\nImpressum: " . $b['imprint'] : '') . ($b['privacy'] ? "\nDatenschutz: " . $b['privacy'] : '');
        return implode("\n\n", array_filter($out, function ($s) { return trim($s) !== ''; }));
    }

    /** HTML → lesbarer Text (Links als „Text (URL)“, ohne Kopf, CSS, Vorschauzeile und Outlook-Kommentare). */
    public static function html_to_text($html) {
        $html = (string) $html;
        $html = preg_replace('#<head\b.*?</head>#is', '', $html);
        $html = preg_replace('#<(style|script|title)\b.*?</\1>#is', '', $html);
        $html = preg_replace('#<!--\[if.*?<!\[endif\]-->#is', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $html = preg_replace('#<div[^>]*display:none[^>]*>.*?</div>#is', '', $html);
        $html = preg_replace('#<img\b[^>]*>#i', '', $html);
        $html = preg_replace_callback('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#si', function ($m) {
            $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $t = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($m[2])));
            if (stripos($url, 'mailto:') === 0) return $t !== '' ? $t : substr($url, 7);
            return ($t === '' || $t === $url) ? $url : $t . ' (' . $url . ')';
        }, $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|tr|h[1-6]|li|table)>#i', "\n", $html);
        $html = preg_replace('#</td>#i', ' ', $html);
        $html = preg_replace('#<hr[^>]*>#i', "\n---\n", $html);
        $t = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
        $t = str_replace(["\xCD\x8F", "\xE2\x80\x8C", "\xC2\xA0"], ['', '', ' '], $t);
        $t = preg_replace('/[ \t]+/', ' ', $t);
        $t = preg_replace('/ *\n */', "\n", $t);
        $t = preg_replace('/\n{3,}/', "\n\n", $t);
        return trim($t);
    }

    // ═══════════════ Bequeme Wege ═══════════════

    /** Älteres HTML (Überschrift + Inhalt) im Layout. Für TIX_Emails::build_generic_email_html und Fremd-Fragmente. */
    public static function legacy($heading, $body_html, $subtitle = '', $footer_note = '', $kind = 'customer') {
        return self::render([
            'title' => wp_strip_all_tags((string) $heading),
            'subtitle' => wp_strip_all_tags((string) $subtitle),
            'blocks' => [['type' => 'html', 'html' => (string) $body_html]],
            'note' => (string) $footer_note,
            'kind' => $kind,
        ]);
    }

    /** Text-Mail → Layout (Absätze, Links klickbar, Liste „- “ bleibt Zeile). */
    public static function from_plain($subject, $text, $kind = 'customer') {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $paras = preg_split('/\n{2,}/', trim($text));
        $html = '';
        foreach ($paras as $p) {
            $p = esc_html($p);
            $p = preg_replace_callback('#(https?://[^\s<>"]+)#', function ($m) {
                $u = rtrim($m[1], '.,;:)');
                $rest = substr($m[1], strlen($u));
                return '<a href="' . $u . '" style="word-break:break-all;">' . $u . '</a>' . $rest;
            }, $p);
            $html .= '<p style="margin:0 0 14px;">' . nl2br($p, false) . '</p>';
        }
        $title = trim(preg_replace('/^\[[^\]]+\]\s*/', '', html_entity_decode((string) $subject, ENT_QUOTES, 'UTF-8')));
        $title = trim(preg_replace('/\s+[–—-]\s+' . preg_quote(self::brand()['name'], '/') . '$/u', '', $title));
        return self::render(['title' => $title, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => $kind]);
    }

    /** Mail im Layout senden. $m wie render(); $opt: headers[], attachments[], customer (Kunden-Links umschreiben, Standard: kind customer/account/promo). */
    public static function send($to, $subject, array $m, array $opt = []) {
        $html = self::render($m);
        $headers = array_merge(['Content-Type: text/html; charset=UTF-8'], (array) ($opt['headers'] ?? []));
        $att = (array) ($opt['attachments'] ?? []);
        $customer = $opt['customer'] ?? in_array($m['kind'] ?? 'customer', ['customer', 'account', 'promo'], true);
        if ($customer && class_exists('TIX_Customer_URL')) return TIX_Customer_URL::mail($to, $subject, $html, $headers, $att);
        return wp_mail($to, $subject, $html, $headers, $att);
    }

    public static function is_layout($html) {
        return strpos((string) $html, 'name="' . self::MARK . '"') !== false;
    }

    public static function kind_of($html) {
        return preg_match('#name="' . self::MARK . '" content="([a-z]+)"#', (string) $html, $m) ? $m[1] : '';
    }

    // ═══════════════ Versand: Absender, Layout für alle Mails ═══════════════

    /** Absender der Seite: mail_from aus der Studio-Marke, sonst noreply@<Domain der Seite>. */
    public static function default_from() {
        return self::brand()['from_email'];
    }

    /**
     * „wordpress@…“ (WordPress-Standard) bzw. leer → Absender der Seite. Ausdrücklich gesetzte Absender (Kopfzeile From,
     * Rundmail-Absender) bleiben. FluentSMTP wählt die Verbindung über das Mapping dieser Adresse.
     */
    public static function filter_from($from) {
        $from = (string) $from;
        if ($from !== '' && stripos($from, 'wordpress@') !== 0) return $from;
        $own = self::default_from();
        return $own !== '' ? $own : $from;
    }

    public static function filter_from_name($name) {
        $name = (string) $name;
        if ($name === '' || $name === 'WordPress') return self::brand()['from_name'];
        return $name;
    }

    private static function header_list($h) {
        if (is_array($h)) return array_values(array_filter(array_map('strval', $h), 'strlen'));
        $h = trim((string) $h);
        return $h === '' ? [] : preg_split("/\r\n|\n|\r/", $h);
    }

    private static function has_header(array $headers, $name) {
        foreach ($headers as $h) if (stripos(ltrim($h), $name . ':') === 0) return true;
        return false;
    }

    private static function is_admin_recipient($to) {
        $list = is_array($to) ? $to : explode(',', (string) $to);
        $admin = strtolower((string) get_option('admin_email'));
        foreach ($list as $t) {
            if (preg_match('/<([^>]+)>/', (string) $t, $m)) $t = $m[1];
            if (strtolower(trim((string) $t)) === $admin) return true;
        }
        return false;
    }

    /** wp_mail-Filter: Text-Mails und HTML-Fragmente ins Layout setzen, Reply-To ergänzen. */
    public static function filter_mail($atts) {
        if (!is_array($atts)) return $atts;
        $headers = self::header_list($atts['headers'] ?? []);
        $raw = false;
        foreach ($headers as $i => $h) {
            if (stripos(ltrim($h), 'X-Tix-Raw:') === 0) { $raw = true; unset($headers[$i]); }
        }
        $headers = array_values($headers);
        $msg = (string) ($atts['message'] ?? '');
        $is_html = false;
        foreach ($headers as $h) if (stripos($h, 'content-type:') !== false && stripos($h, 'text/html') !== false) { $is_html = true; break; }
        if (!$is_html && apply_filters('wp_mail_content_type', 'text/plain') === 'text/html') $is_html = true;
        $full_doc = stripos($msg, '<html') !== false || stripos($msg, '<!doctype') !== false;
        $admin = self::is_admin_recipient($atts['to'] ?? '');

        if (!$raw && apply_filters('tix_mail_layout_wrap', true, $atts) && trim($msg) !== '') {
            if (!$full_doc && (!$is_html || !preg_match('/<[a-z!\/][^>]*>/i', $msg))) {
                $atts['message'] = self::from_plain($atts['subject'] ?? '', $msg, $admin ? 'admin' : 'account');
                $headers = array_values(array_filter($headers, function ($h) { return stripos(ltrim($h), 'content-type:') !== 0; }));
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
            } elseif ($is_html && !$full_doc) {
                $atts['message'] = self::legacy(preg_replace('/^\[[^\]]+\]\s*/', '', (string) ($atts['subject'] ?? '')), $msg, '', '', $admin ? 'admin' : 'customer');
            }
        }
        // Antworten an das Support-Postfach (nicht bei Mails an die Admin-Adresse, sonst landen Admin-Antworten als Anfrage im Support)
        $b = self::brand();
        if (!$admin && $b['support_email'] !== '' && !self::has_header($headers, 'Reply-To')) {
            $headers[] = 'Reply-To: ' . self::encode_name($b['name']) . ' <' . $b['support_email'] . '>';
        }
        $atts['headers'] = $headers;
        return $atts;
    }

    private static function encode_name($n) {
        $n = str_replace(['"', "\r", "\n"], '', (string) $n);
        return preg_match('/[,;<>@()]/', $n) ? '"' . $n . '"' : $n;
    }

    /** Text-Fassung: aus den Bausteinen (eigene Mails) bzw. sauber aus dem HTML. */
    public static function phpmailer($phpmailer) {
        if ($phpmailer->ContentType !== 'text/html' || !empty($phpmailer->AltBody) || empty($phpmailer->Body)) return;
        $body = (string) $phpmailer->Body;
        $key = md5($body);
        if (isset(self::$alt[$key])) { $phpmailer->AltBody = self::$alt[$key]; return; }
        if (self::is_layout($body)) {
            // Mail-Protokoll hat Pixel/Feedback eingefügt → Text aus dem HTML
            $t = self::html_to_text($body);
            if ($t !== '') $phpmailer->AltBody = $t;
        }
    }
}
