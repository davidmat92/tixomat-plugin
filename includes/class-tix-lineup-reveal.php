<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Lineup_Reveal – Act-Enthüllung mit automatischem Platzhalter (1.38.907, Auftrag uebergaben/auftrag-act-enthuellung.md, User 23.11.).
 *
 * Ein Act kann später enthüllt werden: Bis zum Zeitpunkt steht er NUR in der privaten Meta `_tix_lineup_private_reveals`
 * (nie im Line-up-Text, nie in `_tix_artists`, nie im Programm-Titel) – damit erreicht er keinen bestehenden Ausgabeweg
 * (API, HTML, JSON-LD, Künstlerseiten, Suche, Verteilung, Spiegel; der Name „private“ hält ihn aus Spiegel und Export V5).
 * Öffentlich steht an seinem Platz ein Platzhalter-Act (TIX_Lineup_Links::acts): {placeholder: true, name = Platzhalter-Text, …}.
 *
 * Zum Zeitpunkt (Cron alle 2 Minuten, auch sofort nach dem Speichern, wenn schon fällig) wird der Act „normal“:
 * Zeile im Line-up-Text, Reihenfolge/Stern/Rolle, Künstler-Zuordnung (löst Künstler-Follow aus), Foto, Programm-Titel
 * (Slots mit reveal_id), dann Caches leeren, Spiegel/Verteilung/Homepages neu, optional Mitteilung an Käufer + Follower.
 * Ohne Namen bleibt der Platzhalter („Bald enthüllt“) – nie ein leerer Act.
 *
 * Slot: {id, name, artist_id, role, headliner, tour, photo_id, reveal_at (ISO, Europe/Berlin), placeholder_text, notify, countdown, created_at}
 */
class TIX_Lineup_Reveal {

    const NS = 'tixomat/v1';
    const META = '_tix_lineup_private_reveals';
    const CRON = 'tix_lineup_reveal_cron';
    const KEY_PREFIX = 'tixreveal';
    const MAX = 20;
    const MAX_TEXT = 60;
    const MAX_NAME = 40;
    const TZ = 'Europe/Berlin';
    const DUE_TEXT = 'Bald enthüllt';
    const OPEN_TEXT = 'Kommt noch'; // Slot ohne Datum (1.38.914): nie automatisch enthüllt
    const ROLES = ['headliner', 'support', 'guest', 'dj', 'host'];
    const MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    /** Test: feste Uhr (Unix-Zeit) */
    public static $now = null;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_filter('cron_schedules', function ($s) {
            if (!isset($s['tix_every_2min'])) $s['tix_every_2min'] = ['interval' => 120, 'display' => 'Alle 2 Minuten (evendis)'];
            return $s;
        });
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Lineup_Reveal::CRON)) wp_schedule_event(time() + 120, 'tix_every_2min', TIX_Lineup_Reveal::CRON);
        }, 27);
    }

    public static function now() { return self::$now !== null ? intval(self::$now) : time(); }

    // ═══════════════ Reine Helfer ═══════════════

    public static function key($id) { return self::KEY_PREFIX . preg_replace('/[^a-z0-9]/', '', strtolower((string) $id)); }

    public static function is_key($k) { return strpos((string) $k, self::KEY_PREFIX) === 0; }

    /** Zeitpunkt lesen: ISO mit Offset oder „Y-m-d H:i“ (Berlin) → Unix-Zeit | null */
    public static function parse_at($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        try {
            $tz = new DateTimeZone(self::TZ);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v .= ' 00:00';
            $d = new DateTimeImmutable(str_replace('T', ' ', preg_replace('/Z$/', '+00:00', $v)), $tz);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return null;
            return $d->getTimestamp();
        } catch (Exception $e) { return null; }
    }

    public static function iso($ts) { return (new DateTimeImmutable('@' . intval($ts)))->setTimezone(new DateTimeZone(self::TZ))->format('c'); }

    /** „Neuer Act am 1. Dezember“ (Mitternacht) bzw. „… um 18 Uhr“ / „… um 18:30 Uhr“ */
    public static function date_label($ts) {
        $d = (new DateTimeImmutable('@' . intval($ts)))->setTimezone(new DateTimeZone(self::TZ));
        $s = 'Neuer Act am ' . intval($d->format('j')) . '. ' . self::MONTHS[intval($d->format('n'))];
        if ($d->format('H:i') !== '00:00') $s .= ' um ' . ($d->format('i') === '00' ? intval($d->format('G')) : $d->format('G:i')) . ' Uhr';
        return $s;
    }

    /** „Wird am 01.12. um 18 Uhr enthüllt“ */
    public static function hint($ts) {
        $d = (new DateTimeImmutable('@' . intval($ts)))->setTimezone(new DateTimeZone(self::TZ));
        $s = 'Wird am ' . $d->format('d.m.');
        if ($d->format('H:i') !== '00:00') $s .= ' um ' . ($d->format('i') === '00' ? intval($d->format('G')) : $d->format('G:i')) . ' Uhr';
        return $s . ' enthüllt';
    }

    /** Ohne Datum („kommt noch“, 1.38.914): nur von Hand enthüllen */
    public static function is_open(array $s) { return self::parse_at($s['reveal_at'] ?? '') === null; }

    /** Zeitpunkt erreicht? Slots ohne Datum sind nie fällig. */
    public static function due(array $s, $now = null) {
        $at = self::parse_at($s['reveal_at'] ?? '');
        return $at !== null && $at <= ($now ?? self::now());
    }

    /** Öffentlicher Text des Platzhalters */
    public static function label(array $s, $now = null) {
        $t = trim((string) ($s['placeholder_text'] ?? ''));
        if ($t !== '') return $t;
        if (self::is_open($s)) return self::OPEN_TEXT;
        if (self::due($s, $now)) return self::DUE_TEXT;
        return self::date_label(self::parse_at($s['reveal_at']));
    }

    /** Öffentliche Platzhalter-Zeile im Format von lineup_acts – ohne Name, Foto, Künstler */
    public static function public_act(array $s, $now = null) {
        $due = self::due($s, $now);
        $at = self::parse_at($s['reveal_at'] ?? '');
        $label = self::label($s, $now);
        $head = !empty($s['headliner']) || ($s['role'] ?? '') === 'headliner';
        return ['position' => 0, 'name' => $label, 'line' => $label, 'placeholder' => true, 'reveal_key' => self::key($s['id']),
            'reveal_at' => (!$due && $at) ? self::iso($at) : null, 'reveal_label' => $label, 'reveal_hint' => (!$due && $at) ? self::hint($at) : null,
            'countdown' => !$due && $at !== null && !empty($s['countdown']), 'reveal_open' => $at === null,
            'artist_id' => null, 'artist_slug' => null, 'artist_url' => null, 'image' => null, 'image_thumb' => null, 'image_source' => null,
            'role' => $head ? 'headliner' : 'support', 'headliner' => $head,
            'time' => null, 'end' => null, 'start_at' => null, 'end_at' => null, 'stage_name' => null, 'next_day' => false,
            'spotify' => null, 'apple_music' => null, 'artist_link' => null, 'artist_suggestion' => null];
    }

    /** Studio-Zeile: wie public_act, dazu der echte Inhalt unter `reveal` */
    public static function editor_act(array $s, $now = null) {
        $a = self::public_act($s, $now);
        $name = (string) ($s['name'] ?? '');
        $a['name'] = $name !== '' ? $name : $a['reveal_label'];
        $a['line'] = $name;
        $a['artist_id'] = !empty($s['artist_id']) ? intval($s['artist_id']) : null;
        $a['role'] = !empty($s['headliner']) ? 'headliner' : (string) ($s['role'] ?? 'support');
        $a['reveal'] = self::slot_payload($s, $now);
        return $a;
    }

    public static function slot_payload(array $s, $now = null) {
        $at = self::parse_at($s['reveal_at'] ?? '');
        return ['id' => (string) $s['id'], 'key' => self::key($s['id']), 'name' => (string) ($s['name'] ?? ''), 'artist_id' => !empty($s['artist_id']) ? intval($s['artist_id']) : null,
            'role' => (string) ($s['role'] ?? 'support'), 'headliner' => !empty($s['headliner']), 'tour' => (string) ($s['tour'] ?? ''), 'photo_id' => intval($s['photo_id'] ?? 0) ?: null,
            'reveal_at' => $at ? self::iso($at) : null, 'placeholder_text' => (string) ($s['placeholder_text'] ?? ''), 'notify' => !empty($s['notify']), 'countdown' => !empty($s['countdown']),
            'open' => $at === null, 'due' => self::due($s, $now), 'waiting_for_name' => self::due($s, $now) && trim((string) ($s['name'] ?? '')) === '',
            'label' => self::label($s, $now), 'hint' => $at ? self::hint($at) : null];
    }

    /**
     * Eingabe prüfen (Studio) → [slot, null] | [null, WP_Error]. $cur = bisheriger Slot (Update) oder null.
     * Felder: name?, artist_id?, role?, headliner?, tour?, photo_id?, reveal_at? (leer/null bzw. reveal_open: true = ohne Datum, „Kommt noch“, 1.38.914),
     * placeholder_text?, notify?, countdown?
     */
    public static function clean(array $in, $cur = null, $field = 'reveal') {
        $s = $cur ?: ['id' => substr(md5(wp_generate_uuid4()), 0, 10), 'name' => '', 'artist_id' => null, 'role' => 'support', 'headliner' => false, 'tour' => '', 'photo_id' => 0,
            'reveal_at' => '', 'placeholder_text' => '', 'notify' => false, 'countdown' => false, 'created_at' => self::iso(self::now())];
        $bad = function ($f, $m) use ($field) { return [null, new WP_Error('tix_invalid_field', $m, ['status' => 400, 'field' => $field . '.' . $f])]; };
        if (array_key_exists('name', $in)) {
            $n = trim(sanitize_text_field((string) $in['name']));
            if (mb_strlen($n) > self::MAX_NAME) return $bad('name', 'Höchstens ' . self::MAX_NAME . ' Zeichen.');
            $s['name'] = $n;
        }
        $open = !empty($in['reveal_open']) && $in['reveal_open'] !== '0' && $in['reveal_open'] !== 'false';
        if ($open || (array_key_exists('reveal_at', $in) && ($in['reveal_at'] === null || trim((string) $in['reveal_at']) === ''))) {
            $s['reveal_at'] = ''; // ohne Datum: nur von Hand enthüllen
        } elseif (array_key_exists('reveal_at', $in)) {
            $at = self::parse_at($in['reveal_at']);
            if ($at === null) return $bad('reveal_at', 'Bitte Datum und Uhrzeit angeben (z. B. 2026-12-01T18:00) oder leer lassen.');
            if ($at > self::now() + 2 * YEAR_IN_SECONDS) return $bad('reveal_at', 'Höchstens zwei Jahre im Voraus.');
            $s['reveal_at'] = self::iso($at);
        }
        if (array_key_exists('placeholder_text', $in)) {
            $t = trim(sanitize_text_field((string) $in['placeholder_text']));
            if (mb_strlen($t) > self::MAX_TEXT) return $bad('placeholder_text', 'Höchstens ' . self::MAX_TEXT . ' Zeichen.');
            $s['placeholder_text'] = $t;
        }
        if (array_key_exists('role', $in) || array_key_exists('headliner', $in)) {
            $head = !empty($in['headliner']) || (($in['role'] ?? '') === 'headliner');
            $role = $head ? 'headliner' : (string) ($in['role'] ?? ($s['role'] === 'headliner' ? 'support' : $s['role']));
            if (!in_array($role, self::ROLES, true)) return $bad('role', 'Rolle: headliner, support, guest, dj oder host.');
            $s['role'] = $role; $s['headliner'] = $head;
        }
        if (array_key_exists('artist_id', $in)) $s['artist_id'] = $in['artist_id'] ? intval($in['artist_id']) : null;
        if (array_key_exists('tour', $in)) $s['tour'] = sanitize_title((string) $in['tour']);
        if (array_key_exists('photo_id', $in)) {
            $p = intval($in['photo_id']);
            if ($p && (get_post_type($p) !== 'attachment' || strpos((string) get_post_mime_type($p), 'image/') !== 0)) return $bad('photo_id', 'Bitte ein Bild aus der Mediathek wählen.');
            $s['photo_id'] = $p;
        }
        foreach (['notify', 'countdown'] as $b) if (array_key_exists($b, $in)) $s[$b] = !empty($in[$b]) && $in[$b] !== '0' && $in[$b] !== 'false';
        return [$s, null];
    }

    // ═══════════════ Speicher ═══════════════

    public static function slots($event_id) {
        $l = get_post_meta(intval($event_id), self::META, true);
        return is_array($l) ? array_values(array_filter($l, function ($s) { return is_array($s) && !empty($s['id']); })) : [];
    }

    public static function save_slots($event_id, array $slots) {
        $slots = array_values($slots);
        if ($slots) update_post_meta(intval($event_id), self::META, $slots); else delete_post_meta(intval($event_id), self::META);
        self::flush($event_id, false);
    }

    public static function find($event_id, $rid) {
        foreach (self::slots($event_id) as $s) if ((string) $s['id'] === (string) $rid) return $s;
        return null;
    }

    /** Künstler eines Slots prüfen (gehört dem Veranstalter, veröffentlicht) – ohne zu speichern */
    private static function check_artist($event_id, array $s) {
        if (empty($s['artist_id']) || !class_exists('TIX_Artists')) return true;
        $r = TIX_Artists::check(['artists' => [['artist_id' => intval($s['artist_id']), 'role' => $s['role'] ?: 'support', 'tour' => (string) ($s['tour'] ?? '')]]], $event_id);
        return is_wp_error($r) ? $r : true;
    }

    // ═══════════════ Platzhalter im Line-up ═══════════════

    /** Offene Slots als Line-up-Zeilen ($public: Platzhalter; sonst Studio-Zeilen) */
    public static function acts_for($event_id, $public = true) {
        $out = [];
        foreach (self::slots($event_id) as $s) $out[] = $public ? self::public_act($s) : self::editor_act($s);
        return $out;
    }

    // ═══════════════ Enthüllen ═══════════════

    /** Alle fälligen Slots mit Namen eines Events enthüllen → Anzahl */
    public static function run_due($event_id) {
        $n = 0;
        foreach (self::slots($event_id) as $s) {
            if (!self::due($s) || trim((string) ($s['name'] ?? '')) === '') continue;
            if (self::reveal($event_id, $s['id'])) $n++;
        }
        return $n;
    }

    public static function cron() {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META));
        $n = 0;
        foreach (array_map('intval', (array) $ids) as $eid) if (get_post_type($eid) === 'event') $n += self::run_due($eid);
        return $n;
    }

    /** Einen Slot zum normalen Act machen → true | false (kein Name / unbekannt) */
    public static function reveal($event_id, $rid) {
        $event_id = intval($event_id);
        $s = self::find($event_id, $rid);
        if (!$s) return false;
        $name = trim((string) ($s['name'] ?? ''));
        if ($name === '') return false;
        // Erst aus der privaten Liste nehmen (doppelter Cron-Lauf enthüllt nicht doppelt)
        self::save_slots($event_id, array_filter(self::slots($event_id), function ($x) use ($rid) { return (string) $x['id'] !== (string) $rid; }));
        $L = 'TIX_Lineup_Links';
        $old = self::key($s['id']);
        $new = $L::act_key_of($name);
        // 1. Line-up-Text (und Breakdance-Kopie): Zeile anhängen, wenn es den Act dort noch nicht gibt
        foreach (['_tix_info_lineup', '_tix_lineup'] as $mk) {
            if ($mk === '_tix_lineup' && !metadata_exists('post', $event_id, $mk)) continue;
            $html = (string) get_post_meta($event_id, $mk, true);
            $have = false;
            foreach (preg_split('~</p>|<br\s*/?>|\n~i', $html) as $p) {
                $t = trim(preg_replace('/^[\s•\-–·*]+/u', '', html_entity_decode(wp_strip_all_tags($p), ENT_QUOTES, 'UTF-8')));
                if ($t !== '' && $L::similar($t, $name)) { $have = true; break; }
            }
            if (!$have) update_post_meta($event_id, $mk, (trim($html) !== '' ? rtrim($html) . "\n" : '') . '<p>' . esc_html($name) . '</p>');
        }
        // 2. Reihenfolge, Stern, Rolle
        $order = array_values((array) get_post_meta($event_id, $L::META_ORDER, true));
        if ($order) { $order = array_map(function ($k) use ($old, $new) { return (string) $k === $old ? $new : (string) $k; }, $order); update_post_meta($event_id, $L::META_ORDER, array_values(array_unique($order))); }
        if (metadata_exists('post', $event_id, $L::META_HEAD)) {
            $h = array_values(array_filter(array_map('strval', (array) get_post_meta($event_id, $L::META_HEAD, true)), function ($k) use ($old) { return $k !== $old; }));
            if (!empty($s['headliner']) && !in_array($new, $h, true)) $h[] = $new;
            update_post_meta($event_id, $L::META_HEAD, $h);
        }
        $role = !empty($s['headliner']) ? 'headliner' : (string) ($s['role'] ?? 'support');
        if (!in_array($role, ['headliner', 'support'], true)) { $roles = (array) get_post_meta($event_id, $L::META_ROLES, true); $roles[$new] = $role; update_post_meta($event_id, $L::META_ROLES, $roles); }
        // 3. Künstler zuordnen (löst Künstler-Follow aus – erst jetzt)
        if (!empty($s['artist_id']) && class_exists('TIX_Artists')) {
            $list = array_map(function ($r) { return ['artist_id' => intval($r['artist_id']), 'role' => (string) ($r['role'] ?? 'support'), 'tour' => (string) ($r['tour'] ?? '')]; }, TIX_Artists::event_list($event_id));
            if (!in_array(intval($s['artist_id']), array_column($list, 'artist_id'), true)) {
                $list[] = ['artist_id' => intval($s['artist_id']), 'role' => $role, 'tour' => (string) ($s['tour'] ?? '')];
                $chk = TIX_Artists::check(['artists' => $list], $event_id);
                if (!is_wp_error($chk) && $chk !== null) TIX_Artists::apply($event_id, $chk);
            }
            TIX_Artists::link_line($event_id, $name, intval($s['artist_id']));
        }
        // 4. Foto
        if (!empty($s['photo_id']) && method_exists($L, 'set_photo')) $L::set_photo($event_id, $name, intval($s['photo_id']));
        // 5. Programm: Slots mit reveal_id bekommen den Namen
        $tt = get_post_meta($event_id, '_tix_timetable', true);
        if (is_array($tt)) {
            $ch = false;
            foreach ($tt as $d => &$day) { if (!is_array($day)) continue; foreach ($day as &$sl) if (is_array($sl) && (string) ($sl['reveal_id'] ?? '') === (string) $s['id']) { $sl['title'] = $name; unset($sl['reveal_id']); $ch = true; } unset($sl); }
            unset($day);
            if ($ch) update_post_meta($event_id, '_tix_timetable', $tt);
        }
        self::flush($event_id, true);
        $sent = !empty($s['notify']) ? self::announce($event_id, $name) : 0;
        if (class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) TIX_Customers::audit('lineup_reveal', $event_id, ['scope' => 'event', 'oid' => intval(get_post_meta($event_id, '_tix_organizer_id', true))], ['reveal_id' => (string) $s['id'], 'notified' => $sent]);
        do_action('tix_lineup_revealed', $event_id, self::slot_payload($s), $sent);
        return true;
    }

    /** Caches leeren; $out = auch Verteilung, Homepages, Seiten-Cache */
    public static function flush($event_id, $out = true) {
        wp_cache_delete('_transient_tix_pub_events_d' . intval($event_id), 'options'); wp_cache_delete('tix_pub_events_d' . intval($event_id), 'transient');
        delete_transient('tix_pub_events_d' . intval($event_id)); // zuerst: Detail auch bei Objekt-Cache sofort aktuell (nach dem SQL-Löschen fände delete_option die Zeile nicht mehr)
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        if (class_exists('TIX_Artists')) TIX_Artists::flush_cache();
        clean_post_cache(intval($event_id));
        if (!$out) return;
        if (class_exists('TIX_Syndication_Push') && get_post_meta($event_id, '_tix_syndicate', true) === '1' && method_exists('TIX_Syndication_Push', 'push_event')) TIX_Syndication_Push::push_event($event_id);
        do_action('litespeed_purge_post', intval($event_id));
        if (class_exists('TIX_Web_Revalidate') && method_exists('TIX_Web_Revalidate', 'touch_event')) TIX_Web_Revalidate::touch_event(intval($event_id)); // Homepages/evendis-web
    }

    /** Mitteilung „Neu im Line-up: …“ an Ticketkäufer des Events und Follower des Veranstalters → Anzahl */
    public static function announce($event_id, $name) {
        if (!class_exists('TIX_Notifications')) return 0;
        if (class_exists('TIX_Features') && !TIX_Features::on('notifications')) return 0;
        if (get_post_status($event_id) !== 'publish') return 0;
        $uids = [];
        foreach (TIX_Notifications::event_ticket_user_ids($event_id) as $u) $uids[intval($u)] = true;
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        if ($oid && class_exists('TIX_Guest_Flows') && method_exists('TIX_Guest_Flows', 'followers')) foreach (TIX_Guest_Flows::followers($oid) as $u) $uids[intval($u)] = true;
        $title = html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8');
        $body = 'Neu im Line-up: ' . $name;
        $cmp = 'reveal-' . intval($event_id) . '-' . substr(md5($name), 0, 6);
        $n = 0;
        foreach (array_keys($uids) as $uid) {
            if ($uid <= 0) continue;
            TIX_Notifications::add_user_item($uid, $title, $body, ['type' => 'message', 'event_id' => intval($event_id), 'action' => 'event:' . intval($event_id), 'campaign' => $cmp]);
            $n++;
        }
        return $n;
    }

    // ═══════════════ Line-up-Liste (POST /events/{id} lineup_list) ═══════════════

    /** Ist dieser Eintrag ein später zu enthüllender Act? */
    public static function is_reveal_item(array $it) {
        return !empty($it['reveal_id']) || !empty($it['reveal_open']) || (array_key_exists('reveal_at', $it) && $it['reveal_at'] !== null && $it['reveal_at'] !== '');
    }

    /**
     * Einträge mit reveal_at/reveal_id aus der Liste übernehmen → [keys je Index, WP_Error|null].
     * reveal_at null bei bekanntem reveal_id = „nicht mehr später“ → Slot weg, Act normal (Aufrufer).
     * $sync = true: nicht genannte Slots löschen; sonst bleiben sie (alte Clients).
     */
    public static function from_list($event_id, array $list, $sync = false) {
        $cur = []; foreach (self::slots($event_id) as $s) $cur[(string) $s['id']] = $s;
        $keep = []; $keys = []; $seen = [];
        foreach (array_values($list) as $i => $it) {
            if (!is_array($it) || !self::is_reveal_item($it)) continue;
            $rid = (string) ($it['reveal_id'] ?? '');
            if ($rid !== '' && !isset($cur[$rid])) return [null, new WP_Error('tix_invalid_field', 'Unbekannter Act (reveal_id).', ['status' => 400, 'field' => 'lineup_list.' . $i . '.reveal_id'])];
            if ($rid !== '' && empty($it['reveal_open']) && array_key_exists('reveal_at', $it) && ($it['reveal_at'] === null || $it['reveal_at'] === '')) { $seen[$rid] = true; continue; } // wird normaler Act („Sofort zeigen“)
            [$s, $e] = self::clean($it, $rid !== '' ? $cur[$rid] : null, 'lineup_list.' . $i);
            if ($e) return [null, $e];
            $c = self::check_artist($event_id, $s);
            if (is_wp_error($c)) return [null, $c];
            $keep[(string) $s['id']] = $s; $seen[(string) $s['id']] = true;
            $keys[$i] = self::key($s['id']);
        }
        if (!$sync) foreach ($cur as $id => $s) if (!isset($seen[$id])) $keep[$id] = $s;
        return [['slots' => array_values($keep), 'keys' => $keys, 'dropped' => array_values(array_diff(array_keys($cur), array_keys($keep)))], null];
    }

    // ═══════════════ REST (Studio, Veranstalterbereich) ═══════════════

    public static function routes() {
        $perm = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/lineup-reveals', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/lineup-reveals/(?P<rid>[a-z0-9]{6,20})', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/lineup-reveals/(?P<rid>[a-z0-9]{6,20})/reveal', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_reveal_now'], 'permission_callback' => $perm]);
    }

    private static function event_guard(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (class_exists('TIX_REST_API') && !TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        if (class_exists('TIX_Artists') && TIX_Artists::is_copy($id)) return new WP_Error('tix_source_owned', 'Das Line-up gehört dem Ersteller des Events.', ['status' => 409]);
        return $id;
    }

    private static function out($event_id, $extra = []) {
        $r = rest_ensure_response(['ok' => true, 'timezone' => self::TZ, 'reveals' => array_map(function ($s) { return self::slot_payload($s); }, self::slots($event_id)),
            'limits' => ['reveals' => self::MAX, 'placeholder_text' => self::MAX_TEXT, 'name' => self::MAX_NAME]] + $extra);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_list(WP_REST_Request $req) {
        $id = self::event_guard($req);
        return is_wp_error($id) ? $id : self::out($id);
    }

    /** POST …/lineup-reveals (neu, hinten in der Reihenfolge) bzw. …/lineup-reveals/{rid} (ändern) */
    public static function rest_save(WP_REST_Request $req) {
        $id = self::event_guard($req);
        if (is_wp_error($id)) return $id;
        $b = $req->get_json_params(); $b = is_array($b) ? $b : (array) $req->get_body_params();
        $rid = (string) ($req['rid'] ?? '');
        $cur = $rid !== '' ? self::find($id, $rid) : null;
        if ($rid !== '' && !$cur) return new WP_Error('not_found', 'Act nicht gefunden.', ['status' => 404]);
        $slots = self::slots($id);
        if (!$cur && count($slots) >= self::MAX) return new WP_Error('tix_invalid_field', 'Höchstens ' . self::MAX . ' Acts zum Enthüllen.', ['status' => 400, 'field' => 'reveal']);
        [$s, $e] = self::clean($b, $cur);
        if ($e) return $e;
        $c = self::check_artist($id, $s);
        if (is_wp_error($c)) return $c;
        $done = false;
        foreach ($slots as &$x) if ((string) $x['id'] === (string) $s['id']) { $x = $s; $done = true; }
        unset($x);
        if (!$done) {
            $slots[] = $s;
            $L = 'TIX_Lineup_Links';
            $order = array_values((array) get_post_meta($id, $L::META_ORDER, true));
            if ($order) { $order[] = self::key($s['id']); update_post_meta($id, $L::META_ORDER, $order); }
            if (!empty($s['headliner']) && metadata_exists('post', $id, $L::META_HEAD)) { $h = (array) get_post_meta($id, $L::META_HEAD, true); $h[] = self::key($s['id']); update_post_meta($id, $L::META_HEAD, array_values($h)); }
        }
        self::save_slots($id, $slots);
        self::flush($id, true); // Platzhalter erscheint (bzw. Text ändert sich) auch auf Spiegeln und Homepages
        $revealed = self::run_due($id);
        return self::out($id, ['reveal' => $revealed ? null : self::slot_payload($s), 'revealed' => $revealed > 0]);
    }

    public static function rest_delete(WP_REST_Request $req) {
        $id = self::event_guard($req);
        if (is_wp_error($id)) return $id;
        $rid = (string) $req['rid'];
        if (!self::find($id, $rid)) return new WP_Error('not_found', 'Act nicht gefunden.', ['status' => 404]);
        self::save_slots($id, array_filter(self::slots($id), function ($x) use ($rid) { return (string) $x['id'] !== $rid; }));
        self::drop_keys($id, [$rid]);
        self::flush($id, true);
        return self::out($id);
    }

    /** Reihenfolge/Stern von gelöschten Slots bereinigen */
    public static function drop_keys($event_id, array $rids) {
        if (!$rids) return;
        $L = 'TIX_Lineup_Links';
        $ks = array_map([__CLASS__, 'key'], $rids);
        foreach ([$L::META_ORDER, $L::META_HEAD] as $mk) {
            if (!metadata_exists('post', $event_id, $mk)) continue;
            $v = array_values(array_filter(array_map('strval', (array) get_post_meta($event_id, $mk, true)), function ($k) use ($ks) { return !in_array($k, $ks, true); }));
            if ($mk === $L::META_ORDER && !$v) delete_post_meta($event_id, $mk); else update_post_meta($event_id, $mk, $v);
        }
    }

    /** POST …/{rid}/reveal → jetzt enthüllen (409 ohne Namen) */
    public static function rest_reveal_now(WP_REST_Request $req) {
        $id = self::event_guard($req);
        if (is_wp_error($id)) return $id;
        $s = self::find($id, (string) $req['rid']);
        if (!$s) return new WP_Error('not_found', 'Act nicht gefunden.', ['status' => 404]);
        if (trim((string) ($s['name'] ?? '')) === '') return new WP_Error('tix_reveal_no_name', 'Bitte zuerst den Namen eintragen.', ['status' => 409, 'field' => 'name']);
        self::reveal($id, $s['id']);
        return self::out($id, ['revealed' => true]);
    }
}
TIX_Lineup_Reveal::init();
