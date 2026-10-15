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
 */
class TIX_Event_Import {

    const MAX_BYTES = 3145728;
    const EVENT_TYPES = ['Event', 'MusicEvent', 'Festival', 'DanceEvent', 'TheaterEvent', 'ComedyEvent', 'SocialEvent', 'ExhibitionEvent',
        'BusinessEvent', 'SportsEvent', 'EducationEvent', 'ScreeningEvent', 'LiteraryEvent', 'ChildrensEvent', 'FoodEvent', 'SaleEvent',
        'VisualArtsEvent', 'Hackathon', 'CourseInstance', 'EventSeries'];

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

    private static function tickets($offers) {
        $out = [];
        if (!is_array($offers)) return $out;
        $list = isset($offers['@type']) || isset($offers['price']) || isset($offers['lowPrice']) ? [$offers] : $offers;
        foreach ($list as $o) {
            if (!is_array($o)) continue;
            if (($o['@type'] ?? '') === 'AggregateOffer' && !empty($o['offers']) && is_array($o['offers'])) { $out = array_merge($out, self::tickets($o['offers'])); continue; }
            $price = $o['price'] ?? ($o['lowPrice'] ?? null);
            if ($price === null || $price === '') continue;
            $name = self::text($o['name'] ?? '') ?: (($o['@type'] ?? '') === 'AggregateOffer' ? 'Ticket ab' : 'Ticket');
            $out[] = ['name' => mb_substr($name, 0, 80), 'price' => round(floatval(str_replace(',', '.', (string) $price)), 2), 'description' => ''];
            if (count($out) >= 8) break;
        }
        return $out;
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
            'location' => '', 'location_address' => '', 'tickets' => [], 'event_type' => '', 'age_limit' => '', 'excerpt' => '', '_found' => 'none', '_image' => '', '_ticket_url' => ''];
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
            $f['tickets'] = self::tickets($ev['offers'] ?? []);
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
        foreach ($f['tickets'] as $t) $rows[] = 'Ticket: ' . $t['name'] . ' – ' . number_format($t['price'], 2, ',', '.') . ' €';
        return $rows ? "Strukturierte Event-Daten der Seite (verlässlich, bitte übernehmen):\n" . implode("\n", $rows) : '';
    }

    /** Strukturierte Werte haben Vorrang vor der KI (Datum, Zeit, Ort, Titel, Tickets wenn vorhanden). */
    public static function merge(array $ai, array $st) {
        foreach (['title', 'date_start', 'date_end', 'time_start', 'time_end', 'time_doors', 'location', 'location_address'] as $k) {
            if (($st[$k] ?? '') !== '') $ai[$k] = $st[$k];
        }
        if (empty($ai['tickets']) && !empty($st['tickets'])) $ai['tickets'] = $st['tickets'];
        if (empty($ai['lineup']) && !empty($st['lineup'])) $ai['lineup'] = $st['lineup'];
        if (($ai['age_limit'] ?? '') === '' && ($st['age_limit'] ?? '') !== '') $ai['age_limit'] = $st['age_limit'];
        return $ai;
    }
}
