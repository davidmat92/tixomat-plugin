<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Event_Import – Eventseite per URL einlesen (1.38.435, alle Seiten).
 *
 * Genutzt von „Veranstalter werden“ ([tix_register_event], Weg „Eventseite analysieren“) und dem Bulk-Editor
 * über TIX_AI_Writer. Reihenfolge:
 *   1. Abruf mit wp_safe_remote_get (keine internen Adressen), Browser-Kennung, Weiterleitungen, max. 3 MB;
 *      bei 403/429/Zeitüberschreitung ein zweiter Versuch mit anderer Kennung.
 *   2. Strukturierte Daten: JSON-LD schema.org/*Event (auch @graph, Listen), danach OpenGraph/Microdata.
 *   3. Die KI ergänzt (Beschreibung, Line-up, Tickets …); Datum, Zeit, Ort und Titel aus den strukturierten Daten
 *      haben Vorrang. Ist die KI nicht verfügbar, reichen die strukturierten Daten allein.
 * Geht nichts: Fehler `url_unreadable` mit Ausweg „Flyer hochladen oder Event beschreiben“.
 * 1.38.502: Reine JavaScript-Seiten (wenig Text ohne JSON-LD bzw. SPA-Hülle) und Bot-Sperren (403/429/503) gehen an den
 * Render-Dienst (Headless Chromium, POST TIX_RENDER_URL mit X-Render-Key, bis zu 3 Unterseiten); Rückfall wie bisher mit Hinweis.
 */
class TIX_Event_Import {

    const MAX_BYTES = 3145728;
    const EVENT_TYPES = ['Event', 'MusicEvent', 'Festival', 'DanceEvent', 'TheaterEvent', 'ComedyEvent', 'SocialEvent', 'ExhibitionEvent',
        'BusinessEvent', 'SportsEvent', 'EducationEvent', 'ScreeningEvent', 'LiteraryEvent', 'ChildrensEvent', 'FoodEvent', 'SaleEvent',
        'VisualArtsEvent', 'Hackathon', 'CourseInstance', 'EventSeries'];

    // ── Render-Dienst (1.38.502): reine JavaScript-Seiten (Base44, Vite, React …) mit Headless Chromium lesen ──
    // Konfiguration nur per wp-config (TIX_RENDER_URL, TIX_RENDER_SECRET) bzw. Optionen tix_render_url / tix_render_secret.
    const RENDER_TIMEOUT = 30;
    const RENDER_SUBPAGES = 3;
    const PARTIAL_NOTICE = 'Die Seite konnte nur teilweise gelesen werden – bitte prüfe die Angaben.';

    /** ['url', 'secret'] oder null (nicht eingerichtet). Der Schlüssel wird nie ausgegeben oder geloggt. */
    public static function render_config() {
        $url = defined('TIX_RENDER_URL') ? (string) TIX_RENDER_URL : (string) get_option('tix_render_url', '');
        $secret = defined('TIX_RENDER_SECRET') ? (string) TIX_RENDER_SECRET : (string) get_option('tix_render_secret', '');
        if ($url === '' || $secret === '' || !preg_match('#^https://#i', $url)) return null;
        return ['url' => $url, 'secret' => $secret];
    }

    /** Länge des sichtbaren Texts (ohne script/style/noscript/template). */
    public static function visible_text_len($html) {
        $b = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#si', ' ', (string) $html);
        return mb_strlen(trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $b), ENT_QUOTES, 'UTF-8'))));
    }

    /** Typische SPA-Hülle (leeres Wurzel-Element, Vite-/Base44-/Next-Client-Bundle)? */
    public static function spa_shell($html) {
        $h = (string) $html;
        return (bool) (preg_match('#<div[^>]+id=["\'](root|app|__next|__nuxt|svelte)["\'][^>]*>\s*</div>#i', $h)
            || preg_match('#<script[^>]+type=["\']module["\'][^>]*src=["\'][^"\']*/assets/index-[A-Za-z0-9_-]+\.js#i', $h)
            || stripos($h, 'base44') !== false);
    }

    /** Rendern nötig? Nur ohne brauchbares JSON-LD und bei wenig Text (< 300 Zeichen) bzw. SPA-Hülle mit wenig Text (< 1500). */
    public static function needs_render($html, array $st) {
        if (!self::render_config()) return false;
        if (($st['_found'] ?? '') === 'jsonld' && self::usable($st)) return false;
        $len = self::visible_text_len($html);
        return $len < 300 || ($len < 1500 && self::spa_shell($html));
    }

    /**
     * Seite beim Render-Dienst rendern lassen (POST {url, subpages: 3, max_text}).
     * @return array Antwort {final_url, title, text, meta {description, og, jsonld[]}, links[], embeds[], images[], pages[], partial} | WP_Error render_failed
     */
    public static function render($url) {
        $cfg = self::render_config();
        if (!$cfg) return new WP_Error('render_off', 'Render-Dienst nicht eingerichtet.');
        $r = wp_safe_remote_post($cfg['url'], [
            'timeout' => self::RENDER_TIMEOUT, 'redirection' => 0,
            'headers' => ['X-Render-Key' => $cfg['secret'], 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode(['url' => (string) $url, 'subpages' => self::RENDER_SUBPAGES, 'max_text' => 60000]),
        ]);
        if (is_wp_error($r)) return new WP_Error('render_failed', 'Render-Dienst nicht erreichbar.', ['reason' => $r->get_error_message()]);
        $code = intval(wp_remote_retrieve_response_code($r));
        $d = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code !== 200 || !is_array($d) || empty($d['ok'])) {
            return new WP_Error('render_failed', 'Render-Dienst: ' . (is_array($d) ? (string) ($d['code'] ?? 'Fehler') : 'keine Antwort'), ['status' => $code, 'service_code' => is_array($d) ? (string) ($d['code'] ?? '') : '']);
        }
        return $d;
    }

    /** Gerenderte Antwort als schlankes HTML für structured()/enrich_tickets(): Titel, Meta/OG, JSON-LD, Links, Einbettungen, Bilder. */
    public static function rendered_html(array $r) {
        $e = function ($v) { return esc_attr((string) $v); };
        $h = '<html><head><title>' . esc_html((string) ($r['title'] ?? '')) . '</title>';
        $meta = is_array($r['meta'] ?? null) ? $r['meta'] : [];
        if (!empty($meta['description'])) $h .= '<meta name="description" content="' . $e($meta['description']) . '">';
        foreach ((array) ($meta['og'] ?? []) as $k => $v) {
            if (!is_scalar($v)) continue;
            $k = strpos((string) $k, 'og:') === 0 ? (string) $k : 'og:' . $k;
            $h .= '<meta property="' . $e($k) . '" content="' . $e($v) . '">';
        }
        foreach ((array) ($meta['jsonld'] ?? []) as $j) {
            if (is_array($j)) $h .= '<script type="application/ld+json">' . str_replace('</', '<\/', (string) wp_json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</script>';
        }
        $h .= '</head><body>';
        foreach ((array) ($r['embeds'] ?? []) as $em) {
            if (!is_array($em) || empty($em['src'])) continue;
            $h .= ($em['type'] ?? '') === 'script' ? '<script src="' . $e($em['src']) . '"></script>' : '<iframe src="' . $e($em['src']) . '"></iframe>';
        }
        foreach (array_slice((array) ($r['links'] ?? []), 0, 200) as $l) {
            if (is_array($l) && !empty($l['href'])) $h .= '<a href="' . $e($l['href']) . '">' . esc_html((string) ($l['text'] ?? '')) . '</a>';
        }
        foreach (array_slice((array) ($r['images'] ?? []), 0, 30) as $im) {
            if (is_array($im) && !empty($im['src'])) $h .= '<img src="' . $e($im['src']) . '" alt="' . $e($im['alt'] ?? '') . '">';
        }
        return $h . '</body></html>';
    }

    /** Text für die KI: Hauptseite und Unterseiten mit Überschrift, höchstens $limit Zeichen. */
    public static function rendered_text(array $r, $limit = 12000) {
        $parts = [];
        $meta = is_array($r['meta'] ?? null) ? $r['meta'] : [];
        $parts[] = 'URL: ' . (string) ($r['final_url'] ?? '');
        if (!empty($r['title'])) $parts[] = 'Seitentitel: ' . $r['title'];
        if (!empty($meta['description'])) $parts[] = 'Meta-Description: ' . $meta['description'];
        $parts[] = "Seitentext:\n" . trim((string) ($r['text'] ?? ''));
        foreach ((array) ($r['pages'] ?? []) as $pg) {
            if (!is_array($pg) || trim((string) ($pg['text'] ?? '')) === '') continue;
            $parts[] = 'Seite: ' . trim((string) ($pg['title'] ?? '')) . ' (' . (string) ($pg['url'] ?? '') . ")\n" . trim((string) $pg['text']);
        }
        return mb_substr(implode("\n\n", $parts), 0, $limit);
    }

    /** Hauptbild der gerenderten Seite: og:image, sonst das größte Bild ohne Logo/Icon. */
    public static function rendered_image(array $r) {
        $og = (array) ($r['meta']['og'] ?? []);
        $img = (string) ($og['og:image'] ?? ($og['image'] ?? ''));
        if ($img !== '') return $img;
        foreach ((array) ($r['images'] ?? []) as $im) {
            $src = is_array($im) ? (string) ($im['src'] ?? '') : '';
            if ($src === '' || preg_match('#logo|icon|avatar|\.svg#i', $src)) continue;
            return $src;
        }
        return '';
    }

    public static function unreadable_message() {
        return 'Wir konnten die Seite nicht lesen – lade den Flyer hoch oder beschreibe dein Event.';
    }

    private static function agents() {
        return [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
        ];
    }

    /** Nur http(s) und öffentliche Hosts. */
    public static function clean_url($url) {
        $url = trim((string) $url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $url = esc_url_raw($url, ['http', 'https']);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($url === '' || $host === '' || strpos($host, '.') === false) return '';
        return $url;
    }

    /**
     * Seite holen.
     * @return array {body, content_type, code, final_url} | WP_Error (url_unreadable)
     */
    public static function fetch($url) {
        $url = self::clean_url($url);
        if ($url === '') return new WP_Error('url_invalid', 'Bitte eine vollständige Adresse eingeben, z. B. https://deine-seite.de/event.');
        $last = null;
        foreach (self::agents() as $i => $ua) {
            $r = wp_safe_remote_get($url, [
                'timeout' => $i === 0 ? 12 : 8, 'redirection' => 5, 'user-agent' => $ua, 'limit_response_size' => self::MAX_BYTES,
                'headers' => ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', 'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.7'],
            ]);
            $last = $r;
            if (is_wp_error($r)) continue;
            $code = intval(wp_remote_retrieve_response_code($r));
            if (in_array($code, [403, 429, 503], true)) continue;
            if ($code >= 400) break;
            $body = (string) wp_remote_retrieve_body($r);
            if ($body === '') continue;
            return ['body' => $body, 'content_type' => (string) wp_remote_retrieve_header($r, 'content-type'), 'code' => $code, 'final_url' => $url];
        }
        $why = is_wp_error($last) ? $last->get_error_message() : ('HTTP ' . intval(wp_remote_retrieve_response_code($last)));
        return new WP_Error('url_unreadable', self::unreadable_message(), ['status' => 422, 'reason' => $why]);
    }

    // ── Strukturierte Daten ──

    /** Alle JSON-LD-Knoten (flach, inkl. @graph). */
    public static function jsonld_nodes($html) {
        $nodes = [];
        if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', (string) $html, $m)) return $nodes;
        foreach ($m[1] as $raw) {
            $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $raw = preg_replace('/^\s*<!--|-->\s*$/', '', $raw);
            $d = json_decode($raw, true);
            if (!is_array($d)) $d = json_decode(preg_replace('/[\x00-\x1F]/', ' ', $raw), true);
            if (!is_array($d)) continue;
            $stack = isset($d['@graph']) && is_array($d['@graph']) ? $d['@graph'] : (array_keys($d) === range(0, count($d) - 1) ? $d : [$d]);
            foreach ($stack as $n) {
                if (!is_array($n)) continue;
                $nodes[] = $n;
                if (isset($n['@graph']) && is_array($n['@graph'])) foreach ($n['@graph'] as $g) if (is_array($g)) $nodes[] = $g;
            }
        }
        return $nodes;
    }

    private static function is_event($node) {
        $t = (array) ($node['@type'] ?? []);
        foreach ($t as $x) if (in_array(preg_replace('#^.*/#', '', (string) $x), self::EVENT_TYPES, true)) return true;
        return false;
    }

    private static function text($v) {
        if (is_array($v)) $v = $v['name'] ?? ($v['@value'] ?? (isset($v[0]) ? self::text($v[0]) : ''));
        return trim(html_entity_decode(wp_strip_all_tags((string) $v), ENT_QUOTES, 'UTF-8'));
    }

    /** ISO-Datum → [Y-m-d, H:i] in der Zeitzone der Seite ('' wenn nicht lesbar). */
    public static function split_datetime($iso) {
        $iso = trim((string) $iso);
        if ($iso === '') return ['', ''];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return [$iso, ''];
        try {
            $has_tz = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $iso);
            $d = new DateTime($iso, wp_timezone());
            if ($has_tz) $d->setTimezone(wp_timezone());
            return [$d->format('Y-m-d'), $d->format('H:i') === '00:00' && !preg_match('/T\d{2}:\d{2}/', $iso) ? '' : $d->format('H:i')];
        } catch (Exception $e) {
            return ['', ''];
        }
    }

    private static function address($loc) {
        if (!is_array($loc)) return ['', self::text($loc)];
        if (isset($loc[0]) && is_array($loc[0])) $loc = $loc[0];
        $name = self::text($loc['name'] ?? '');
        $a = $loc['address'] ?? '';
        if (is_array($a)) {
            $street = self::text($a['streetAddress'] ?? '');
            $zip = self::text($a['postalCode'] ?? '');
            $city = self::text($a['addressLocality'] ?? '');
            // Straße enthält PLZ/Ort schon (z. B. Eventbrite) → nicht doppelt anhängen
            $tail = trim($zip . ' ' . $city);
            if ($street !== '' && (($zip !== '' && strpos($street, $zip) !== false) || ($city !== '' && mb_stripos($street, $city) !== false))) $tail = '';
            $addr = trim($street . ($street !== '' && $tail !== '' ? ', ' : '') . $tail);
        } else {
            $addr = self::text($a);
        }
        if (($loc['@type'] ?? '') === 'VirtualLocation') { $name = $name ?: 'Online'; $addr = ''; }
        return [$name, $addr];
    }

    private static function image($v) {
        if (is_string($v)) return $v;
        if (is_array($v)) {
            if (isset($v['url'])) return self::image($v['url']);
            if (isset($v['contentUrl'])) return self::image($v['contentUrl']);
            if (isset($v[0])) return self::image($v[0]);
        }
        return '';
    }

    /**
     * Ticketart in fester Form (1.38.444): {name, price, currency[, qty, description, sale_start, sale_end, available]}.
     * Für JSON-LD, Shop-Quellen und KI gleich.
     */
    public static function clean_ticket(array $t) {
        $name = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode((string) ($t['name'] ?? ''), ENT_QUOTES, 'UTF-8'))));
        if ($name === '') return null;
        $price = $t['price'] ?? 0;
        $price = is_numeric(str_replace(',', '.', (string) $price)) ? round(floatval(str_replace(',', '.', (string) $price)), 2) : 0.0;
        $out = ['name' => mb_substr($name, 0, 80), 'price' => max(0.0, $price), 'currency' => strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($t['currency'] ?? '')) ?: 'EUR')];
        if (isset($t['qty']) && intval($t['qty']) > 0) $out['qty'] = intval($t['qty']);
        $d = trim(wp_strip_all_tags((string) ($t['description'] ?? '')));
        if ($d !== '') $out['description'] = mb_substr($d, 0, 300);
        foreach (['sale_start', 'sale_end'] as $k) {
            if (!empty($t[$k])) { list($dd, $tt) = self::split_datetime((string) $t[$k]); if ($dd !== '') $out[$k] = trim($dd . ' ' . $tt); }
        }
        if (isset($t['available'])) $out['available'] = (bool) $t['available'];
        return $out;
    }

    /**
     * Ticketarten aus JSON-LD `offers`. Ein `AggregateOffer` ohne einzelne Angebote ergibt KEINE Ticketarten,
     * sondern $range {min, max, currency} (die Seite nennt nur die Spanne).
     */
    private static function tickets($offers, &$range = null) {
        $out = [];
        if (!is_array($offers)) return $out;
        $list = isset($offers['@type']) || isset($offers['price']) || isset($offers['lowPrice']) ? [$offers] : $offers;
        foreach ($list as $o) {
            if (!is_array($o)) continue;
            $type = is_array($o['@type'] ?? null) ? (string) ($o['@type'][0] ?? '') : (string) ($o['@type'] ?? '');
            if ($type === 'AggregateOffer' || (!isset($o['price']) && isset($o['lowPrice']))) {
                if (!empty($o['offers']) && is_array($o['offers'])) { $out = array_merge($out, self::tickets($o['offers'], $range)); continue; }
                $lo = $o['lowPrice'] ?? ($o['price'] ?? null);
                $hi = $o['highPrice'] ?? $lo;
                if ($lo !== null && $lo !== '') {
                    $range = ['min' => round(floatval(str_replace(',', '.', (string) $lo)), 2), 'max' => round(floatval(str_replace(',', '.', (string) $hi)), 2), 'currency' => (string) ($o['priceCurrency'] ?? 'EUR') ?: 'EUR'];
                }
                continue;
            }
            $price = $o['price'] ?? null;
            if ($price === null || $price === '') continue;
            $t = self::clean_ticket([
                'name' => self::text($o['name'] ?? '') ?: 'Ticket', 'price' => $price, 'currency' => $o['priceCurrency'] ?? 'EUR',
                'qty' => is_array($o['inventoryLevel'] ?? null) ? ($o['inventoryLevel']['value'] ?? 0) : 0,
                'description' => self::text($o['description'] ?? ''), 'sale_start' => $o['availabilityStarts'] ?? ($o['validFrom'] ?? ''), 'sale_end' => $o['availabilityEnds'] ?? '',
                'available' => isset($o['availability']) ? !preg_match('/SoldOut|OutOfStock|Discontinued/i', (string) $o['availability']) : null,
            ]);
            if ($t === null) continue;
            if (!isset($o['availability'])) unset($t['available']);
            $out[] = $t;
            if (count($out) >= 20) break;
        }
        return $out;
    }

    // ── Shop-Quellen für Ticketarten (öffentlich, ohne Schlüssel) ──

    /** JSON-Wert ab $pos (Objekt oder Liste) aus einem Text schneiden – Klammern zählen, Zeichenketten beachten. */
    public static function json_at($s, $pos) {
        $len = strlen($s); $depth = 0; $in = false; $esc = false; $start = -1;
        for ($i = $pos; $i < $len; $i++) {
            $c = $s[$i];
            if ($start < 0) { if ($c === '{' || $c === '[') { $start = $i; $depth = 1; } continue; }
            if ($in) { if ($esc) $esc = false; elseif ($c === '\\') $esc = true; elseif ($c === '"') $in = false; continue; }
            if ($c === '"') $in = true;
            elseif ($c === '{' || $c === '[') $depth++;
            elseif ($c === '}' || $c === ']') { $depth--; if ($depth === 0) { $j = json_decode(substr($s, $start, $i - $start + 1), true); return is_array($j) ? $j : null; } }
        }
        return null;
    }

    private static function get_json_or_html($url) {
        $r = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 3, 'user-agent' => self::agents()[0], 'limit_response_size' => self::MAX_BYTES,
            'headers' => ['Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8', 'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.7']]);
        if (is_wp_error($r) || intval(wp_remote_retrieve_response_code($r)) !== 200) return '';
        return (string) wp_remote_retrieve_body($r);
    }

    /** Eventbrite-ID aus der Event-Adresse (…-tickets-1999178899906, ?eid=). */
    public static function eventbrite_id($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!preg_match('/^(www\.)?eventbrite\.(com|de|at|ch|fr|es|it|nl|ie|be|pt|se|dk|fi|pl|ca|hk|sg|cl|co|co\.uk|co\.nz|com\.au|com\.br|com\.mx|com\.ar|com\.pe)$/', $host)) return '';
        if (preg_match('/[?&]eid=(\d{6,})/', $url, $m)) return $m[1];
        return preg_match('#/e/[^/?]*?-?(\d{9,})(?:[/?\#]|$)#', $url, $m) ? $m[1] : '';
    }

    /** Ticketarten aus dem Eventbrite-Widget (checkout-external, öffentlich): Netto-Preis ohne Eventbrite-Gebühr. */
    public static function eventbrite_tickets_from($html) {
        $p = strpos((string) $html, 'window.__SERVER_DATA__');
        if ($p === false) return [];
        $d = self::json_at($html, $p);
        $out = [];
        foreach ((array) ($d['tickets'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $variants = !empty($t['variants']) && is_array($t['variants']) ? $t['variants'] : [$t];
            foreach ($variants as $v) {
                $cost = $v['cost'] ?? ($t['cost'] ?? null);
                $name = (string) ($t['name'] ?? '');
                $vn = (string) ($v['display_name'] ?? ($v['name'] ?? ''));
                if ($vn !== '' && $vn !== $name && count($variants) > 1) $name = trim($name . ' – ' . $vn);
                $status = (string) ($v['on_sale_status'] ?? ($t['on_sale_status'] ?? ''));
                $x = self::clean_ticket([
                    'name' => $name ?: $vn, 'price' => is_array($cost) ? ($cost['major_value'] ?? 0) : 0,
                    'currency' => is_array($cost) ? ($cost['currency'] ?? '') : ($t['currency'] ?? ''),
                    'description' => (string) ($t['description'] ?? ''), 'sale_start' => (string) ($v['start_sales_with_tz']['utc'] ?? ''),
                    'available' => $status === '' ? null : in_array($status, ['AVAILABLE', 'LIMITED'], true),
                ]);
                if ($x === null) continue;
                if ($status === '') unset($x['available']);
                if (!empty($t['free'])) $x['price'] = 0.0;
                $out[] = $x;
                if (count($out) >= 20) break 2;
            }
        }
        return $out;
    }

    /** Pretix: /<veranstalter>/<event>/widget/product_list (öffentliche JSON-Quelle des Widgets). */
    public static function pretix_tickets_from(array $d) {
        $out = [];
        $cur = (string) ($d['currency'] ?? 'EUR');
        foreach ((array) ($d['items_by_category'] ?? []) as $cat) {
            if (preg_match('/merch|shirt|shop|fanartikel|getr(ä|ae)nk|essen|food|park/i', (string) ($cat['name'] ?? ''))) continue;
            foreach ((array) ($cat['items'] ?? []) as $it) {
                $vars = !empty($it['has_variations']) && !empty($it['variations']) ? $it['variations'] : [null];
                foreach ($vars as $v) {
                    $pr = $v ? ($v['price'] ?? null) : ($it['price'] ?? null);
                    $x = self::clean_ticket([
                        'name' => (string) ($it['name'] ?? '') . ($v ? ' – ' . (string) ($v['value'] ?? '') : ''),
                        'price' => is_array($pr) ? ($pr['gross'] ?? 0) : $pr, 'currency' => $cur,
                        'description' => (string) ($it['description'] ?? ''),
                        'available' => isset($it['avail'][0]) ? intval($it['avail'][0]) >= 100 : null,
                    ]);
                    if ($x === null) continue;
                    if (!isset($it['avail'][0])) unset($x['available']);
                    $out[] = $x;
                    if (count($out) >= 20) break 3;
                }
            }
        }
        return $out;
    }

    private static function pretix_base($url, $html) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $is = preg_match('/(^|\.)pretix\.eu$/', $host) || stripos((string) $html, 'pretixpresale') !== false || stripos((string) $html, 'content="pretix"') !== false;
        if (!$is || !preg_match('#^(https?://[^/]+/[^/?\#]+/[^/?\#]+)/?#i', $url, $m)) return '';
        return $m[1];
    }

    /**
     * Ticketarten ergänzen, wenn das JSON-LD keine einzelnen nennt: Eventbrite-Widget, Pretix-Widget.
     * Setzt `tickets`, `_tickets_source` (jsonld|eventbrite|pretix|range|none) und `price_range`.
     */
    public static function enrich_tickets(array $st, $url, $html) {
        if (!empty($st['tickets'])) { $st['_tickets_source'] = 'jsonld'; return $st; }
        $found = [];
        if (($eid = self::eventbrite_id($url)) !== '') {
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $found = self::eventbrite_tickets_from(self::get_json_or_html('https://' . $host . '/checkout-external?eid=' . $eid));
            if ($found) $st['_tickets_source'] = 'eventbrite';
        } elseif (($base = self::pretix_base($url, $html)) !== '') {
            $j = json_decode(self::get_json_or_html($base . '/widget/product_list'), true);
            if (is_array($j)) $found = self::pretix_tickets_from($j);
            if ($found) $st['_tickets_source'] = 'pretix';
        }
        if ($found) { $st['tickets'] = $found; return $st; }
        $st['_tickets_source'] = !empty($st['price_range']) ? 'range' : 'none';
        return $st;
    }

    /** Nur eine Preisspanne bekannt: eine Ticketart mit dem niedrigsten Preis (das Studio zeigt „Bitte Ticketarten prüfen“). */
    public static function range_ticket(array $range) {
        return ['name' => 'Ticket', 'price' => max(0.0, floatval($range['min'])), 'currency' => strtoupper((string) ($range['currency'] ?: 'EUR'))];
    }

    private static function guess_type($node, $title) {
        $t = preg_replace('#^.*/#', '', (string) (is_array($node['@type'] ?? null) ? ($node['@type'][0] ?? '') : ($node['@type'] ?? '')));
        $map = ['MusicEvent' => 'konzert', 'Festival' => 'festival', 'DanceEvent' => 'party', 'TheaterEvent' => 'theater', 'ComedyEvent' => 'comedy',
            'SportsEvent' => 'sport', 'EducationEvent' => 'workshop', 'BusinessEvent' => 'networking', 'ExhibitionEvent' => 'messe', 'SocialEvent' => 'party'];
        if (isset($map[$t])) return $map[$t];
        $l = mb_strtolower($title);
        foreach (['festival' => 'festival', 'party' => 'party', 'comedy' => 'comedy', 'konzert' => 'konzert', 'tour' => 'konzert', 'workshop' => 'workshop', 'theater' => 'theater', 'messe' => 'messe'] as $k => $v) {
            if (strpos($l, $k) !== false) return $v;
        }
        return '';
    }

    /** Meta-Tag (property oder name) lesen. */
    private static function meta($html, $key) {
        $k = preg_quote($key, '#');
        if (preg_match('#<meta[^>]+(?:property|name|itemprop)=["\']' . $k . '["\'][^>]*content=["\']([^"\']*)["\']#si', $html, $m)) return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        if (preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name|itemprop)=["\']' . $k . '["\']#si', $html, $m)) return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        return '';
    }

    /**
     * Felder aus strukturierten Daten (Format wie TIX_AI_Writer::sanitize_extracted_fields), dazu `_found`
     * (jsonld|og|none) und `_image`.
     */
    public static function structured($html, $url = '') {
        $f = ['title' => '', 'description' => '', 'lineup' => '', 'date_start' => '', 'date_end' => '', 'time_start' => '', 'time_end' => '', 'time_doors' => '',
            'location' => '', 'location_address' => '', 'tickets' => [], 'price_range' => null, 'event_type' => '', 'age_limit' => '', 'excerpt' => '', '_found' => 'none', '_image' => '', '_ticket_url' => ''];
        $ev = null;
        foreach (self::jsonld_nodes($html) as $n) { if (self::is_event($n)) { $ev = $n; break; } }
        if ($ev) {
            $f['_found'] = 'jsonld';
            $f['title'] = mb_substr(self::text($ev['name'] ?? ''), 0, 200);
            $desc = self::text($ev['description'] ?? '');
            $f['description'] = $desc !== '' ? wpautop(esc_html(mb_substr($desc, 0, 2500))) : '';
            list($f['date_start'], $f['time_start']) = self::split_datetime($ev['startDate'] ?? '');
            list($f['date_end'], $f['time_end']) = self::split_datetime($ev['endDate'] ?? '');
            if (!empty($ev['doorTime'])) {
                $dt = (string) $ev['doorTime'];
                $f['time_doors'] = preg_match('/^\d{1,2}:\d{2}/', $dt) ? substr($dt, 0, 5) : self::split_datetime($dt)[1];
            }
            list($f['location'], $f['location_address']) = self::address($ev['location'] ?? '');
            $perf = $ev['performer'] ?? ($ev['performers'] ?? []);
            $names = [];
            foreach ((isset($perf['name']) || is_string($perf) ? [$perf] : (array) $perf) as $p) { $x = self::text($p); if ($x !== '') $names[] = esc_html($x); }
            $f['lineup'] = implode('<br>', array_slice(array_unique($names), 0, 20));
            $range = null;
            $f['tickets'] = self::tickets($ev['offers'] ?? [], $range);
            if (!$f['tickets'] && $range) $f['price_range'] = $range;
            $f['_ticket_url'] = self::offer_url($ev['offers'] ?? []);
            $f['event_type'] = self::guess_type($ev, $f['title']);
            $age = self::text($ev['typicalAgeRange'] ?? '');
            if (preg_match('/(\d{1,2})/', $age, $mm)) $f['age_limit'] = intval($mm[1]);
            $f['_image'] = self::image($ev['image'] ?? '');
        }
        // OpenGraph / Microdata als Ergänzung bzw. Rückfall
        $og_title = self::meta($html, 'og:title');
        $og_desc = self::meta($html, 'og:description') ?: self::meta($html, 'description');
        if ($f['title'] === '' && $og_title !== '') { $f['title'] = mb_substr($og_title, 0, 200); if ($f['_found'] === 'none') $f['_found'] = 'og'; }
        if ($f['description'] === '' && $og_desc !== '') $f['description'] = wpautop(esc_html(mb_substr($og_desc, 0, 1500)));
        if ($f['date_start'] === '') {
            $sd = self::meta($html, 'startDate') ?: self::meta($html, 'event:start_time');
            if ($sd !== '') list($f['date_start'], $f['time_start']) = self::split_datetime($sd);
        }
        if ($f['_image'] === '') $f['_image'] = self::meta($html, 'og:image');
        if ($f['_image'] !== '' && $url) $f['_image'] = self::absolute($f['_image'], $url);
        $plain = wp_strip_all_tags($f['description']);
        $f['excerpt'] = $plain !== '' ? mb_substr(preg_replace('/\s+/', ' ', $plain), 0, 158) : '';
        return $f;
    }

    /** Erste Kauf-Adresse aus offers. */
    private static function offer_url($offers) {
        if (!is_array($offers)) return '';
        $list = isset($offers['@type']) || isset($offers['url']) ? [$offers] : $offers;
        foreach ($list as $o) {
            if (!is_array($o)) continue;
            if (!empty($o['url']) && is_string($o['url']) && preg_match('#^https://#i', $o['url'])) return $o['url'];
            if (!empty($o['offers'])) { $u = self::offer_url($o['offers']); if ($u !== '') return $u; }
        }
        return '';
    }

    /** Bekannte Ticketshops (Host-Endungen). */
    const SHOPS = ['eventbrite.de', 'eventbrite.com', 'eventbrite.co.uk', 'eventim.de', 'eventim.com', 'oeticket.com', 'reservix.de', 'ra.co', 'residentadvisor.net',
        'ticketmaster.de', 'ticketmaster.com', 'dice.fm', 'tickettailor.com', 'pretix.eu', 'ticket.io', 'eventix.io', 'tixforgigs.com', 'adticket.de', 'myticket.de',
        'ticketcorner.ch', 'koelnticket.de', 'universe.com', 'ticketino.com', 'eventfrog.de', 'eventfrog.ch', 'xceed.me', 'shotgun.live', 'vivenu.com', 'ticketpay.de', 'tickets.de', 'ticketswap.com'];

    public static function is_shop_host($url) {
        $h = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        if ($h === '') return false;
        foreach (self::SHOPS as $d) if ($h === $d || substr($h, -strlen('.' . $d)) === '.' . $d) return true;
        return false;
    }

    /**
     * Vorschlag „Tickets bei einem anderen Shop“: die importierte Seite ist selbst ein Ticketshop, oder ihre Event-Daten
     * verweisen auf einen. Nie die eigene Seite. '' = kein Vorschlag.
     */
    public static function shop_suggestion($page_url, array $st) {
        $own = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $cand = self::is_shop_host($page_url) ? $page_url : (self::is_shop_host($st['_ticket_url'] ?? '') ? (string) $st['_ticket_url'] : '');
        if ($cand === '' || strtolower((string) wp_parse_url($cand, PHP_URL_HOST)) === $own) return '';
        return esc_url_raw($cand);
    }

    public static function absolute($src, $base) {
        $src = trim((string) $src);
        if ($src === '' || preg_match('#^https?://#i', $src)) return $src;
        if (strpos($src, '//') === 0) return 'https:' . $src;
        $p = wp_parse_url($base);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/' . ltrim($src, '/');
    }

    /** Reicht das für einen Entwurf ohne KI? */
    public static function usable(array $f) {
        return $f['title'] !== '' && $f['date_start'] !== '';
    }

    /** Text der strukturierten Daten als Hinweis für die KI. */
    public static function summary(array $f) {
        $rows = [];
        foreach (['title' => 'Titel', 'date_start' => 'Datum', 'time_start' => 'Beginn', 'time_doors' => 'Einlass', 'date_end' => 'Ende (Datum)', 'time_end' => 'Ende (Zeit)', 'location' => 'Ort', 'location_address' => 'Adresse'] as $k => $l) {
            if ($f[$k] !== '' && $f[$k] !== null) $rows[] = $l . ': ' . $f[$k];
        }
        if ($f['lineup'] !== '') $rows[] = 'Line-up: ' . wp_strip_all_tags(str_replace('<br>', ', ', $f['lineup']));
        foreach ($f['tickets'] as $t) $rows[] = 'Ticket: ' . $t['name'] . ' – ' . number_format($t['price'], 2, ',', '.') . ' ' . ($t['currency'] ?? 'EUR');
        if (!$f['tickets'] && !empty($f['price_range'])) $rows[] = 'Preise von ' . number_format($f['price_range']['min'], 2, ',', '.') . ' bis ' . number_format($f['price_range']['max'], 2, ',', '.') . ' ' . $f['price_range']['currency'] . ' (Ticketarten bitte einzeln mit Namen aus dem Seitentext übernehmen, keine „ab/bis“-Einträge)';
        return $rows ? "Strukturierte Event-Daten der Seite (verlässlich, bitte übernehmen):\n" . implode("\n", $rows) : '';
    }

    /** Strukturierte Werte haben Vorrang vor der KI (Datum, Zeit, Ort, Titel, Tickets wenn vorhanden). */
    public static function merge(array $ai, array $st) {
        foreach (['title', 'date_start', 'date_end', 'time_start', 'time_end', 'time_doors', 'location', 'location_address'] as $k) {
            if (($st[$k] ?? '') !== '') $ai[$k] = $st[$k];
        }
        // „Ticket ab“/„Ticket bis“ der KI sind keine Ticketarten
        $ai['tickets'] = array_values(array_filter((array) ($ai['tickets'] ?? []), function ($t) {
            return is_array($t) && !preg_match('/^\s*(ticket|tickets|eintritt)?\s*(ab|bis|von|from|to)\b/iu', (string) ($t['name'] ?? ''));
        }));
        // Ticketarten: Seite/Shop (einzeln benannt) vor KI; nur Spanne → KI-Liste, wenn sie mindestens zwei nennt
        if (!empty($st['tickets'])) $ai['tickets'] = $st['tickets'];
        elseif (!empty($st['price_range']) && count((array) ($ai['tickets'] ?? [])) < 2) $ai['tickets'] = [self::range_ticket($st['price_range'])];
        if (empty($ai['lineup']) && !empty($st['lineup'])) $ai['lineup'] = $st['lineup'];
        if (($ai['age_limit'] ?? '') === '' && ($st['age_limit'] ?? '') !== '') $ai['age_limit'] = $st['age_limit'];
        return $ai;
    }
}
