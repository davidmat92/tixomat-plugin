<?php
/**
 * TIX_Newsletter_AI – KI-Generator für Newsletter im Studio (Modul Marketing, User 2026-11-05).
 *
 * Das Studio führt den Veranstalter durch 5–7 Fragen (Ziel, Event, Gutschein, Inhalte, Ton) und ruft dann
 * POST /marketing/newsletter/ai. Antwort: 3–5 Betreffzeilen, 3–5 Vorschautexte, 2–3 Entwürfe (Flyer groß, Kompakt, Story).
 *
 * Grundsatz: Die KI schreibt nur Texte und wählt Bausteine. Das HTML baut das Plugin aus festen Bausteinen
 * (TIX_Mail_Layout: Marke, Logo, Akzentfarbe, Dark Mode, 600 px, Tabellen). Datum, Uhrzeit, Ort, Preise, Line-up,
 * Programm, Gutschein-Code und Links kommen ausschließlich aus den echten Daten (Event-API, Gutscheine, Marke).
 * Fehlt etwas, entfällt der Baustein. Eine Prüfung (guard) entfernt Sätze mit Preisen, Prozenten, Daten, Uhrzeiten oder
 * Codes, die nicht in den Daten stehen, dazu Links, „Ballermann“ und Alkohol-Werbung.
 * Abmeldelink und Impressum setzt der Versand (TIX_Newsletter::html) immer in den Fuß – nicht entfernbar.
 *
 * An die KI gehen nur Event-Daten, die Marke und die Antworten des Veranstalters – keine Kundendaten.
 * Limit: 30 Erzeugungen je Bereich und Tag (Filter tix_nl_ai_daily_limit), Kosten-Protokoll in der Option tix_nl_ai_usage.
 *
 * REST (Inhaber/Team-Admin/Admin wie der Newsletter):
 *   GET  /marketing/newsletter/ai   Status, Limit, Kosten, Auswahl-Listen
 *   POST /marketing/newsletter/ai   erzeugen (Vertrag in DOCUMENTATION.md §80)
 */
if (!defined('ABSPATH')) exit;

class TIX_Newsletter_AI {

    const NS = 'tixomat/v1';
    const OPT_USAGE = 'tix_nl_ai_usage';
    const DAILY_LIMIT = 30;
    const KEEP_DAYS = 60;
    const SUBJECT_MAX = 60;
    const PREHEADER_MAX = 110;
    const MAX_EVENTS = 8;

    const GOALS = [
        'event' => 'Event bewerben', 'overview' => 'Mehrere Events (Übersicht)', 'last_minute' => 'Last-Minute / fast ausverkauft',
        'news' => 'Line-up / Neuigkeit', 'app' => 'App laden', 'thanks' => 'Danke & Rückblick', 'voucher' => 'Gutschein / Aktion', 'custom' => 'Eigenes Thema',
    ];
    const INCLUDES = ['lineup' => 'Line-up', 'program' => 'Programm & Zeiten', 'prices' => 'Preise & Vorverkauf', 'directions' => 'Anfahrt', 'gallery' => 'Galerie-Bilder', 'app' => 'App-Hinweis', 'social' => 'Social-Links'];
    const TONES = ['casual' => 'locker', 'festive' => 'festlich', 'short' => 'kurz & knackig', 'info' => 'informativ'];
    const DRAFTS = ['flyer' => 'Flyer groß', 'compact' => 'Kompakt', 'story' => 'Story'];
    const PARTS = ['subjects', 'preheaders', 'drafts'];
    /** Bausteine, aus denen die KI wählt (Daten-Bausteine füllt das Plugin) */
    const BLOCKS = ['text', 'event', 'events', 'coupon', 'lineup', 'program', 'prices', 'directions', 'gallery', 'app', 'social'];
    /** Schätzpreise USD je 1 Mio. Token [Eingabe, Ausgabe] (Filter tix_nl_ai_prices) */
    const PRICES = ['claude-sonnet-5-5' => [3.0, 15.0], 'claude-opus-5-5' => [15.0, 75.0], 'claude-haiku-5-5' => [1.0, 5.0]];
    const USD_EUR = 0.92;
    const WEEKDAYS = ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/marketing/newsletter/ai', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_status'], 'permission_callback' => ['TIX_Ad_Accounts', 'perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_generate'], 'permission_callback' => ['TIX_Ad_Accounts', 'perm_module']],
        ]);
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }

    // ═══════════════ Schlüssel, Modell, Limit, Kosten ═══════════════

    /** Anthropic-Schlüssel der Seite (wie KI-Texte und Assistent; Filter tix_nl_ai_api_key) */
    private static function api_key() {
        if (class_exists('TIX_AI_Provider') && TIX_AI_Provider::configured()) return TIX_AI_Provider::MARKER; // Anbieter aus dem Studio (1.38.646)
        return trim((string) apply_filters('tix_nl_ai_api_key', function_exists('tix_get_settings') ? (string) tix_get_settings('anthropic_api_key') : ''));
    }

    /** Claude-Modell der Seite; steht die Seite auf OpenAI, gilt die Claude-Vorgabe (der Generator nutzt Werkzeug-Aufrufe von Claude). */
    public static function model() {
        $m = class_exists('TIX_AI_Writer') ? TIX_AI_Writer::model() : 'claude-sonnet-5-5';
        return strpos($m, 'claude') === 0 ? $m : (class_exists('TIX_AI_Writer') ? TIX_AI_Writer::DEFAULT_MODEL : 'claude-sonnet-5-5');
    }

    public static function limit() { return max(0, intval(apply_filters('tix_nl_ai_daily_limit', self::DAILY_LIMIT))); }

    private static function day() { return current_time('Y-m-d'); }

    private static function usage_all() { $u = get_option(self::OPT_USAGE, []); return is_array($u) ? $u : []; }

    /** {n, cost_eur, in, out} eines Bereichs an einem Tag */
    public static function usage($org, $day = null) {
        $u = self::usage_all();
        $r = $u[$day ?: self::day()][intval($org)] ?? [];
        return ['n' => intval($r['n'] ?? 0), 'cost_eur' => floatval($r['cost_eur'] ?? 0), 'in' => intval($r['in'] ?? 0), 'out' => intval($r['out'] ?? 0)];
    }

    public static function cost_eur($model, array $usage) {
        $p = (array) apply_filters('tix_nl_ai_prices', self::PRICES);
        list($pi, $po) = $p[$model] ?? $p['claude-sonnet-5-5'] ?? [3.0, 15.0];
        $in = intval($usage['input_tokens'] ?? 0) + intval($usage['cache_creation_input_tokens'] ?? 0) * 1.25 + intval($usage['cache_read_input_tokens'] ?? 0) * 0.1;
        return ($in * $pi + intval($usage['output_tokens'] ?? 0) * $po) / 1e6 * self::USD_EUR;
    }

    private static function add_usage($org, $model, array $usage) {
        $u = self::usage_all();
        $d = self::day();
        $r = self::usage($org, $d);
        $cost = self::cost_eur($model, $usage);
        $u[$d][intval($org)] = ['n' => $r['n'] + 1, 'cost_eur' => round($r['cost_eur'] + $cost, 4), 'in' => $r['in'] + intval($usage['input_tokens'] ?? 0), 'out' => $r['out'] + intval($usage['output_tokens'] ?? 0)];
        $keep = gmdate('Y-m-d', strtotime($d) - self::KEEP_DAYS * DAY_IN_SECONDS);
        foreach (array_keys($u) as $k) if ($k < $keep) unset($u[$k]);
        update_option(self::OPT_USAGE, $u, false);
        return $cost;
    }

    private static function cost_days($org, $days) {
        $sum = 0.0;
        $from = gmdate('Y-m-d', strtotime(self::day()) - ($days - 1) * DAY_IN_SECONDS);
        foreach (self::usage_all() as $d => $rows) if ($d >= $from) $sum += floatval($rows[intval($org)]['cost_eur'] ?? 0);
        return round($sum, 2);
    }

    // ═══════════════ GET: Status ═══════════════

    public static function rest_status(WP_REST_Request $req) {
        $org = TIX_Ad_Accounts::org($req);
        $u = self::usage($org);
        $lim = self::limit();
        $r = rest_ensure_response(['ok' => true, 'available' => self::api_key() !== '', 'module_on' => TIX_Ad_Accounts::module_on($org), 'model' => self::model(),
            'limit' => $lim, 'used_today' => $u['n'], 'remaining' => max(0, $lim - $u['n']), 'cost_today_eur' => round($u['cost_eur'], 2), 'cost_30d_eur' => self::cost_days($org, 30),
            'subject_max' => self::SUBJECT_MAX, 'preheader_max' => self::PREHEADER_MAX, 'max_events' => self::MAX_EVENTS,
            'options' => ['goals' => self::GOALS, 'includes' => self::INCLUDES, 'tones' => self::TONES, 'drafts' => self::DRAFTS]]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    // ═══════════════ Eingaben ═══════════════

    private static function in_scope($eid) {
        return $eid && get_post_type($eid) === 'event' && in_array(intval($eid), array_map('intval', TIX_Studio_Stats::scope_ids()), true);
    }

    /** Gutschein des Bereichs (echte Daten) → {code, discount_label, expires, expires_label, events[]} | WP_Error */
    public static function coupon($code, $org) {
        $all = get_option('tix_coupons', []);
        foreach ((array) $all as $k => $c) {
            if (!is_array($c) || strcasecmp((string) $k, (string) $code) !== 0) continue;
            if (($c['discount_type'] ?? '') === 'giftcard' || !empty($c['is_promoter'])) break;
            if ($org && intval($c['organizer_id'] ?? 0) !== intval($org)) break;
            $exp = (string) ($c['expires'] ?? '');
            if ($exp !== '' && strtotime($exp . ' 23:59:59') < current_time('timestamp')) return self::bad('coupon', 'Dieser Gutschein ist abgelaufen.', 'tix_coupon_invalid', 409);
            $max = intval($c['max_uses'] ?? 0);
            if ($max > 0 && intval($c['used'] ?? 0) >= $max) return self::bad('coupon', 'Dieser Gutschein ist aufgebraucht.', 'tix_coupon_invalid', 409);
            $label = class_exists('TIX_App_Coupons') ? TIX_App_Coupons::discount_label($c) : (floatval($c['value'] ?? 0) . (($c['discount_type'] ?? '') === 'fixed' ? ' €' : ' %'));
            return ['code' => (string) $k, 'type' => (string) ($c['discount_type'] ?? 'percent'), 'value' => round(floatval($c['value'] ?? 0), 2), 'discount_label' => $label,
                'expires' => $exp, 'expires_label' => $exp !== '' ? self::date_label($exp) : '', 'events' => array_values(array_map('intval', (array) ($c['allowed_events'] ?? []))),
                'app_only' => !empty($c['app_only'])];
        }
        return self::bad('coupon', 'Gutschein nicht gefunden.', 'not_found', 404);
    }

    private static function money($v) {
        $v = round(floatval($v), 2);
        return (abs($v - round($v)) < 0.001 ? number_format($v, 0, ',', '.') : number_format($v, 2, ',', '.')) . ' €';
    }

    private static function date_label($ymd) {
        $t = strtotime((string) $ymd . ' 12:00:00');
        return $t ? self::WEEKDAYS[intval(gmdate('w', $t))] . ' ' . gmdate('d.m.Y', $t) : '';
    }

    private static function plain($html, $len = 0) {
        $t = trim(preg_replace('/[ \t]+/', ' ', html_entity_decode(wp_strip_all_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], "\n", (string) $html)), ENT_QUOTES, 'UTF-8')));
        $t = preg_replace("/\n\s*\n+/", "\n", $t);
        return $len ? mb_substr($t, 0, $len) : $t;
    }

    /** Echte Daten eines Events für Bausteine und KI */
    public static function facts($eid) {
        if (!class_exists('TIX_Public_Events')) return null;
        $p = TIX_Public_Events::payload($eid, true);
        if (!$p) return null;
        $lineup = [];
        foreach ((array) ($p['lineup_links'] ?? []) as $l) if (!empty($l['name'])) $lineup[] = (string) $l['name'];
        if (!$lineup && !empty($p['lineup'])) $lineup = array_values(array_filter(array_map('trim', explode("\n", self::plain($p['lineup'])))));
        $program = [];
        foreach ((array) (($p['timetable'] ?? null)['days'] ?? []) as $d) foreach ((array) ($d['slots'] ?? []) as $s) {
            if (($s['title'] ?? '') === '') continue;
            $program[] = ['date' => (string) $d['date'], 'time' => (string) $s['time'], 'end' => (string) ($s['end'] ?? ''), 'title' => (string) $s['title'], 'stage' => (string) ($s['stage_name'] ?? '')];
        }
        $prices = [];
        foreach ((array) ($p['categories'] ?? []) as $c) if (empty($c['gift_card'])) $prices[] = ['name' => (string) $c['name'], 'price' => self::money($c['price']), 'sold_out' => !empty($c['sold_out']), 'note' => (string) ($c['low_stock_text'] ?? '')];
        $gallery = array_slice(array_values(array_filter(array_map('strval', (array) ($p['gallery'] ?? [])))), 0, 4);
        $time = $p['time_start'] !== '' ? $p['time_start'] . ' Uhr' : '';
        $img = $p['image'] ?: (string) (($p['placeholder'] ?? null)['url'] ?? '');
        return [
            'id' => intval($p['id']), 'title' => html_entity_decode((string) $p['title'], ENT_QUOTES, 'UTF-8'), 'url' => (string) $p['url'], 'image' => (string) $img,
            'date' => (string) $p['date_start'], 'date_end' => (string) $p['date_end'], 'date_label' => self::date_label($p['date_start']),
            'time_label' => $time, 'doors_label' => $p['time_doors'] !== '' ? 'Einlass ' . $p['time_doors'] . ' Uhr' : '',
            'location' => (string) $p['location'], 'address' => (string) $p['address'],
            'price_from' => $p['price_from'] !== null && !$p['free_entry'] ? 'ab ' . self::money($p['price_from']) : '', 'free_entry' => !empty($p['free_entry']),
            'tickets' => !empty($p['tickets_enabled']), 'status' => (string) $p['status'], 'age' => (string) $p['age_label'], 'is_past' => !empty($p['is_past']),
            'excerpt' => self::plain($p['excerpt'] ?: ($p['description'] ?? ''), 600),
            'lineup' => array_slice($lineup, 0, 20), 'program' => array_slice($program, 0, 20), 'prices' => array_slice($prices, 0, 8), 'gallery' => $gallery,
        ];
    }

    // ═══════════════ POST: erzeugen ═══════════════

    public static function rest_generate(WP_REST_Request $req) {
        $org = TIX_Ad_Accounts::org($req);
        $goal = (string) ($req->get_param('goal') ?: 'event');
        if (!isset(self::GOALS[$goal])) return self::bad('goal', 'Bitte ein Ziel wählen.');
        $ids = $req->get_param('event_ids');
        if ($ids === null && $req->get_param('event_id')) $ids = [$req->get_param('event_id')];
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        $one = in_array($goal, ['event', 'last_minute', 'news', 'thanks'], true);
        if ($one && count($ids) !== 1) return self::bad('event_ids', 'Bitte ein Event wählen.');
        if ($goal === 'overview' && !$ids) return self::bad('event_ids', 'Bitte mindestens ein Event wählen.');
        if (count($ids) > self::MAX_EVENTS) return self::bad('event_ids', 'Höchstens ' . self::MAX_EVENTS . ' Events.');
        $events = [];
        foreach ($ids as $eid) {
            if (!self::in_scope($eid) || !($f = self::facts($eid))) return self::bad('event_ids', 'Event nicht gefunden.', 'not_found', 404);
            $events[] = $f;
        }
        $coupon = null;
        $code = trim((string) $req->get_param('coupon'));
        if ($code !== '') { $coupon = self::coupon($code, $org); if (is_wp_error($coupon)) return $coupon; }
        if ($goal === 'voucher' && !$coupon) return self::bad('coupon', 'Bitte einen Gutschein wählen oder anlegen.');
        $inc = array_values(array_intersect(array_keys(self::INCLUDES), array_map('strval', (array) $req->get_param('include'))));
        $tone = (string) ($req->get_param('tone') ?: 'casual');
        if (!isset(self::TONES[$tone])) return self::bad('tone', 'Bitte einen Ton wählen.');
        $form = (string) ($req->get_param('address') ?: 'du');
        if (!in_array($form, ['du', 'sie'], true)) return self::bad('address', 'Anrede: du oder sie.');
        $parts = $req->get_param('parts') ? array_values(array_intersect(self::PARTS, array_map('strval', (array) $req->get_param('parts')))) : self::PARTS;
        if (!$parts) return self::bad('parts', 'Teile: subjects, preheaders, drafts.');
        $draft = (string) $req->get_param('draft');
        if ($draft !== '' && !isset(self::DRAFTS[$draft])) return self::bad('draft', 'Entwurf: flyer, compact oder story.');
        $keys = $draft !== '' ? [$draft] : ($goal === 'overview' || count($events) > 1 ? ['flyer', 'compact'] : array_keys(self::DRAFTS));
        $note = mb_substr(trim(wp_strip_all_tags((string) $req->get_param('note'))), 0, 500);
        $rephrase = mb_substr(trim(wp_strip_all_tags((string) $req->get_param('rephrase'))), 0, 300);
        $cid = absint($req->get_param('campaign_id'));

        if (self::api_key() === '') return new WP_Error('ai_unavailable', 'Die KI ist hier nicht eingerichtet (kein API-Schlüssel).', ['status' => 503]);
        $lim = self::limit();
        if (self::usage($org)['n'] >= $lim) return new WP_Error('tix_nl_ai_limit', 'Das Tageslimit von ' . $lim . ' Erzeugungen ist erreicht – morgen geht es weiter.', ['status' => 429, 'limit' => $lim]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('nl_ai', 6, 60, 'u' . get_current_user_id())) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen – bitte kurz warten.', ['status' => 429]);

        $b = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand() : ['name' => get_bloginfo('name'), 'social' => [], 'ios' => '', 'android' => ''];
        $ctx = ['org' => $org, 'goal' => $goal, 'events' => $events, 'coupon' => $coupon, 'include' => $inc, 'tone' => $tone, 'address' => $form, 'brand' => $b,
            'name' => class_exists('TIX_Newsletter') ? TIX_Newsletter::owner_name($org) : (string) $b['name'], 'note' => $note, 'rephrase' => $rephrase, 'parts' => $parts, 'drafts' => $keys];
        $ai = self::call($ctx);
        if (is_wp_error($ai)) return $ai;
        $cost = self::add_usage($org, $ai['model'], $ai['usage']);

        $facts = self::allowed($ctx);
        $removed = 0;
        $out = ['ok' => true, 'subjects' => [], 'preheaders' => [], 'drafts' => []];
        foreach (['subjects' => self::SUBJECT_MAX, 'preheaders' => self::PREHEADER_MAX] as $k => $max) {
            if (!in_array($k, $parts, true)) continue;
            foreach ((array) ($ai['data'][$k] ?? []) as $t) {
                $t = self::line($t, 160);
                if ($t === '') continue;
                if (self::violates($t, $facts)) { $removed++; continue; }
                $len = mb_strlen($t);
                $out[$k][] = ['text' => $t, 'length' => $len, 'max' => $max, 'too_long' => $len > $max];
                if (count($out[$k]) >= 5) break;
            }
        }
        if (in_array('drafts', $parts, true)) {
            $by = [];
            foreach ((array) ($ai['data']['drafts'] ?? []) as $d) if (is_array($d) && isset(self::DRAFTS[$d['key'] ?? ''])) $by[$d['key']] = $d;
            $subject = $out['subjects'][0]['text'] ?? ($events[0]['title'] ?? $ctx['name']);
            $pre = $out['preheaders'][0]['text'] ?? '';
            foreach ($keys as $k) {
                $content = self::build($k, (array) ($by[$k] ?? []), $ctx, $facts, $removed);
                $out['drafts'][] = ['key' => $k, 'name' => self::DRAFTS[$k], 'content' => $content, 'html' => self::preview($content, $subject, $pre, $org, $cid), 'text' => self::text($content)];
            }
        }
        $u = self::usage($org);
        $out += ['facts' => ['events' => array_map(function ($e) { return ['id' => $e['id'], 'title' => $e['title'], 'date_label' => $e['date_label'], 'url' => $e['url']]; }, $events),
            'coupon' => $coupon ? ['code' => $coupon['code'], 'discount_label' => $coupon['discount_label'], 'expires' => $coupon['expires']] : null],
            'removed' => $removed, 'model' => $ai['model'], 'cost_eur' => round($cost, 4), 'used_today' => $u['n'], 'remaining' => max(0, $lim - $u['n']), 'limit' => $lim];
        $r = rest_ensure_response($out);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    // ═══════════════ KI ═══════════════

    private static function schema() {
        $str = ['type' => 'string'];
        return ['type' => 'object', 'required' => ['subjects', 'preheaders', 'drafts'], 'properties' => [
            'subjects' => ['type' => 'array', 'items' => $str, 'description' => '5 Betreffzeilen, je höchstens 50 Zeichen'],
            'preheaders' => ['type' => 'array', 'items' => $str, 'description' => '5 Vorschautexte, je 60–100 Zeichen'],
            'drafts' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['key', 'headline', 'intro', 'blocks'], 'properties' => [
                'key' => ['type' => 'string', 'enum' => array_keys(self::DRAFTS)],
                'badge' => ['type' => 'string', 'description' => 'kurzes Etikett über der Überschrift, höchstens 30 Zeichen, optional'],
                'headline' => ['type' => 'string', 'description' => 'Überschrift, höchstens 60 Zeichen'],
                'intro' => ['type' => 'string', 'description' => 'Einleitung, 1–3 Sätze (Story: bis 5)'],
                'blocks' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['type'], 'properties' => [
                    'type' => ['type' => 'string', 'enum' => self::BLOCKS],
                    'heading' => ['type' => 'string', 'description' => 'Zwischenüberschrift, optional'],
                    'text' => ['type' => 'string', 'description' => 'kurzer Text zum Baustein, optional; bei type=text der Absatz'],
                    'button' => ['type' => 'string', 'description' => 'Beschriftung des Knopfs, höchstens 28 Zeichen, optional'],
                ]]],
                'closing' => ['type' => 'string', 'description' => 'Schlusssatz, optional'],
            ]]],
        ]];
    }

    private static function system_prompt() {
        return "Du schreibst E-Mail-Newsletter für Veranstalter (Events, Partys, Konzerte, Festivals) auf Deutsch.\n"
            . "Du lieferst NUR Texte und die Reihenfolge der Bausteine über das Werkzeug „newsletter“. Das HTML baut das System.\n"
            . "Regeln:\n"
            . "- Erfinde keine Fakten. Datum, Uhrzeit, Ort, Preise, Line-up, Programm, Gutschein-Code und Rabatt zeigen die Bausteine aus den echten Daten. "
            . "Erwähne in deinen Texten nur Fakten, die wörtlich in den Daten stehen. Was fehlt, lässt du weg.\n"
            . "- Keine Links, keine Adressen, keine Telefonnummern im Text.\n"
            . "- Nie das Wort „Ballermann“. Keine gesundheitlichen oder rechtlichen Versprechen. Keine Werbung für übermäßigen Alkoholkonsum.\n"
            . "- Emojis sparsam: höchstens eins je Betreffzeile, nicht in jeder.\n"
            . "- Betreffzeilen höchstens 50 Zeichen, Vorschautexte 60–100 Zeichen, beide unterschiedlich und neugierig machend, nicht reißerisch.\n"
            . "- Halte die vorgegebene Anrede (du/Sie) und den Ton.\n"
            . "Bausteine: text (Absatz), event (Karte eines Events mit Knopf), events (Liste mehrerer Events), coupon (Gutschein-Code), lineup, program, prices, directions (Anfahrt), gallery, app (App laden), social. "
            . "Nimm nur Bausteine, für die Daten vorhanden sind (siehe „verfügbar“). Je Abschnitt ein klarer Knopf.\n"
            . "Entwürfe: flyer = großes Bild, kurze Texte, starker Knopf; compact = knapp, Fakten zuerst; story = erzählend mit Zwischenüberschriften.";
    }

    private static function available(array $ctx) {
        $e = $ctx['events'];
        $has = function ($k) use ($e) { foreach ($e as $x) if (!empty($x[$k])) return true; return false; };
        $b = $ctx['brand'];
        return array_values(array_filter([
            'text', $e ? 'event' : null, count($e) > 1 ? 'events' : null, $ctx['coupon'] ? 'coupon' : null,
            $has('lineup') ? 'lineup' : null, $has('program') ? 'program' : null, ($has('prices') || $has('price_from') || $has('free_entry')) ? 'prices' : null,
            ($has('address') || $has('location')) ? 'directions' : null, $has('gallery') ? 'gallery' : null,
            (!empty($b['ios']) || !empty($b['android'])) ? 'app' : null, !empty($b['social']) ? 'social' : null,
        ]));
    }

    private static function call(array $ctx) {
        $data = [
            'absender' => $ctx['name'], 'ziel' => self::GOALS[$ctx['goal']], 'ton' => self::TONES[$ctx['tone']], 'anrede' => $ctx['address'] === 'sie' ? 'Sie' : 'du',
            'gewuenschte_inhalte' => array_map(function ($k) { return self::INCLUDES[$k]; }, $ctx['include']),
            'verfuegbar' => self::available($ctx),
            'events' => array_map(function ($e) {
                return array_filter(['titel' => $e['title'], 'datum' => $e['date_label'], 'beginn' => $e['time_label'], 'einlass' => $e['doors_label'], 'ort' => $e['location'],
                    'preis' => $e['free_entry'] ? 'Eintritt frei' : $e['price_from'], 'status' => $e['status'] === 'sold_out' ? 'ausverkauft' : ($e['is_past'] ? 'vorbei' : ''),
                    'alter' => $e['age'], 'beschreibung' => $e['excerpt'], 'line_up' => $e['lineup'], 'programm' => array_map(function ($s) { return trim($s['time'] . ' ' . $s['title']); }, $e['program']),
                    'kategorien' => array_map(function ($p) { return $p['name'] . ' ' . $p['price'] . ($p['sold_out'] ? ' (ausverkauft)' : ($p['note'] !== '' ? ' (' . $p['note'] . ')' : '')); }, $e['prices'])]);
            }, $ctx['events']),
            'gutschein' => $ctx['coupon'] ? array_filter(['code' => $ctx['coupon']['code'], 'rabatt' => $ctx['coupon']['discount_label'], 'gueltig_bis' => $ctx['coupon']['expires_label'], 'nur_in_der_app' => $ctx['coupon']['app_only'] ? 'ja' : '']) : null,
            'wuensche_des_veranstalters' => $ctx['note'], 'anders_formulieren' => $ctx['rephrase'],
            'liefere' => ['teile' => $ctx['parts'], 'entwuerfe' => $ctx['drafts']],
        ];
        $model = self::model();
        // Claude 5.5 erlaubt kein erzwungenes Werkzeug (tool_choice tool/any) – daher auto + klare Anweisung; kein Nachdenken vor der Antwort (schneller, Studio wartet höchstens 90 s)
        $payload = ['model' => $model, 'max_tokens' => 8000, 'thinking' => ['type' => 'between_tools'], 'system' => self::system_prompt() . "\nAntworte ausschließlich, indem du das Werkzeug „newsletter“ genau einmal aufrufst – ohne Text davor oder danach.",
            'tools' => [['name' => 'newsletter', 'description' => 'Texte und Bausteine des Newsletters zurückgeben', 'input_schema' => self::schema()]],
            'tool_choice' => ['type' => 'auto'],
            'messages' => [['role' => 'user', 'content' => "Daten (JSON):\n" . wp_json_encode(array_filter($data, function ($v) { return $v !== null && $v !== '' && $v !== []; }), JSON_UNESCAPED_UNICODE)]]];
        $r = class_exists('TIX_AI_Writer') ? TIX_AI_Writer::anthropic_post($payload, self::api_key(), 85)
            : wp_remote_post('https://api.anthropic.com/v1/messages', ['timeout' => 85, 'headers' => ['x-api-key' => self::api_key(), 'content-type' => 'application/json', 'anthropic-version' => '2023-06-01'], 'body' => wp_json_encode($payload)]);
        if (is_wp_error($r)) return new WP_Error('ai_unavailable', 'Die KI ist gerade nicht erreichbar.', ['status' => 503]);
        $code = intval(wp_remote_retrieve_response_code($r));
        $body = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code === 429) return new WP_Error('tix_rate_limit', 'Die KI ist gerade ausgelastet – bitte kurz warten.', ['status' => 429]);
        if ($code < 200 || $code >= 300 || !is_array($body)) return new WP_Error('ai_unavailable', 'Die KI hat nicht geantwortet (HTTP ' . $code . ').', ['status' => 503]);
        foreach ((array) ($body['content'] ?? []) as $blk) {
            if (($blk['type'] ?? '') === 'tool_use' && is_array($blk['input'] ?? null)) {
                return ['data' => $blk['input'], 'usage' => (array) ($body['usage'] ?? []), 'model' => (string) ($body['model'] ?? $model)];
            }
        }
        return new WP_Error('ai_unavailable', 'Die KI-Antwort war unvollständig – bitte noch einmal.', ['status' => 503]);
    }

    // ═══════════════ Prüfung der Texte ═══════════════

    /** Zahl vergleichbar machen: „12,50“, „12.5“, „1.000“, „10“ → „12.5“, „1000“, „10“ */
    private static function num($s) {
        $s = (string) $s;
        if (strpos($s, ',') !== false) $s = str_replace(',', '.', str_replace('.', '', $s));
        elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
        return rtrim(rtrim(number_format(floatval($s), 2, '.', ''), '0'), '.');
    }

    /** Erlaubte Fakten: Beträge, Prozente, Tage, Uhrzeiten, Codes aus den echten Daten */
    private static function allowed(array $ctx) {
        $f = ['money' => [], 'pct' => [], 'days' => [], 'times' => [], 'codes' => [], 'words' => ''];
        $add_money = function ($label) use (&$f) { if (preg_match_all('/(\d+(?:[.,]\d+)*)\s?€/u', (string) $label, $m)) foreach ($m[1] as $x) $f['money'][] = self::num($x); };
        foreach ($ctx['events'] as $e) {
            foreach (['date', 'date_end'] as $k) if ($e[$k] !== '' && ($t = strtotime($e[$k] . ' 12:00:00'))) $f['days'][] = gmdate('j.n', $t);
            foreach ($e['program'] as $s) { if ($s['date'] !== '' && ($t = strtotime($s['date'] . ' 12:00:00'))) $f['days'][] = gmdate('j.n', $t); $f['times'][] = $s['time']; $f['times'][] = $s['end']; }
            if (preg_match_all('/(\d{1,2}):(\d{2})/', $e['time_label'] . ' ' . $e['doors_label'], $m, PREG_SET_ORDER)) foreach ($m as $x) $f['times'][] = intval($x[1]) . ':' . $x[2];
            $add_money($e['price_from']);
            foreach ($e['prices'] as $p) $add_money($p['price']);
            $f['words'] .= ' ' . $e['title'] . ' ' . implode(' ', $e['lineup']) . ' ' . $e['location'] . ' ' . implode(' ', array_column($e['program'], 'title')) . ' ' . $e['excerpt'];
        }
        if ($c = $ctx['coupon']) {
            $f['codes'][] = strtoupper($c['code']);
            if (in_array($c['type'], ['percent', 'per_ticket_percent'], true)) $f['pct'][] = self::num($c['value']); else $f['money'][] = self::num($c['value']);
            if ($c['expires'] !== '' && ($t = strtotime($c['expires'] . ' 12:00:00'))) $f['days'][] = gmdate('j.n', $t);
        }
        $f['times'] = array_values(array_filter(array_map(function ($t) { return preg_match('/^(\d{1,2}):(\d{2})$/', (string) $t, $m) ? intval($m[1]) . ':' . $m[2] : ''; }, $f['times'])));
        $f['words'] = ' ' . mb_strtoupper($f['words'] . ' ' . $ctx['name']) . ' ';
        return $f;
    }

    /** Verstößt ein Satz gegen die Regeln? (erfundene Fakten, Links, geschützter Begriff, Alkohol) */
    public static function violates($t, array $f) {
        if (preg_match('/ballermann/iu', $t)) return true;
        if (preg_match('/https?:\/\/|www\.|\b[\w.-]+\.(de|com|net|org|info|events)\b|@[\w-]+\.\w/iu', $t)) return true;
        if (preg_match('/saufen|flatrate|komasaufen|all you can drink|sturzbetrunken|vorglühen|freibier/iu', $t)) return true;
        if (preg_match_all('/(\d+(?:[.,]\d+)*)\s?(?:€|euro\b)/iu', $t, $m)) foreach ($m[1] as $x) if (!in_array(self::num($x), $f['money'], true)) return true;
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s?(?:%|prozent)/iu', $t, $m)) foreach ($m[1] as $x) if (!in_array(self::num($x), $f['pct'], true)) return true;
        if (preg_match_all('/\b(\d{1,2})\.\s?(\d{1,2})\.(?:\s?\d{2,4})?/u', $t, $m, PREG_SET_ORDER)) foreach ($m as $x) if (!in_array(intval($x[1]) . '.' . intval($x[2]), $f['days'], true)) return true;
        if (preg_match_all('/\b(\d{1,2})(?:[:.](\d{2}))?\s?Uhr\b/u', $t, $m, PREG_SET_ORDER)) foreach ($m as $x) if (!in_array(intval($x[1]) . ':' . ($x[2] ?? '' ?: '00'), $f['times'], true)) return true;
        if (preg_match_all('/\b[A-Z0-9][A-Z0-9_-]{4,}\b/u', $t, $m)) foreach ($m[0] as $x) {
            if (!preg_match('/\d|-/', $x) || preg_match('/^\d+$/', $x) || in_array($x, $f['codes'], true) || mb_strpos($f['words'], $x) !== false) continue;
            return true;
        }
        return false;
    }

    private static function line($t, $len) {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $t), ENT_QUOTES, 'UTF-8')));
        return mb_substr($t, 0, $len);
    }

    /** Text bereinigen: Sätze mit Verstößen fallen weg → reiner Text */
    private static function clean($t, array $f, &$removed, $len = 900) {
        $t = trim(html_entity_decode(wp_strip_all_tags((string) $t), ENT_QUOTES, 'UTF-8'));
        if ($t === '') return '';
        $paras = [];
        foreach (preg_split("/\n\s*\n/u", $t) as $p) {
            $keep = [];
            foreach (preg_split('/(?<=[.!?…])\s+/u', trim(preg_replace('/[ \t]+/u', ' ', $p))) as $s) {
                if ($s === '') continue;
                if (self::violates($s, $f)) { $removed++; continue; }
                $keep[] = $s;
            }
            if ($keep) $paras[] = implode(' ', $keep);
        }
        return mb_substr(implode("\n\n", $paras), 0, $len);
    }

    // ═══════════════ HTML aus Bausteinen ═══════════════

    private static function e($s) { return esc_html((string) $s); }

    private static function paras($t) {
        if ($t === '') return '';
        return implode('', array_map(function ($p) { return '<p style="margin:0 0 14px;">' . nl2br(self::e($p), false) . '</p>'; }, explode("\n\n", $t)));
    }

    private static function heading($t, $size = 20) {
        return $t === '' ? '' : '<h2 style="margin:8px 0 10px;font-size:' . intval($size) . 'px;line-height:' . intval($size * 1.25) . 'px;font-weight:800;">' . self::e($t) . '</h2>';
    }

    private static function map_url($e) {
        $q = trim($e['location'] . ', ' . $e['address'], ', ');
        return $q !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q) : '';
    }

    /** Daten-Baustein → Layout-Bausteine; leer, wenn die Daten fehlen */
    private static function data_block($type, array $blk, array $ctx, array $f, &$removed, $variant) {
        $ev = $ctx['events'];
        $e = $ev[0] ?? null;
        $acc = (string) ($ctx['brand']['accent'] ?? '#FC2F55');
        $heading = self::line($blk['heading'] ?? '', 60);
        if ($heading !== '' && self::violates($heading, $f)) { $removed++; $heading = ''; }
        $text = self::clean($blk['text'] ?? '', $f, $removed, 500);
        $btn = self::line($blk['button'] ?? '', 28);
        if ($btn !== '' && self::violates($btn, $f)) { $removed++; $btn = ''; }
        $lead = ($heading !== '' || $text !== '') ? [['type' => 'text', 'html' => self::heading($heading) . self::paras($text)]] : [];
        $ticket_label = function ($x) use ($btn) { return $btn !== '' ? $btn : ($x['tickets'] && !$x['is_past'] ? 'Jetzt Tickets sichern' : 'Zum Event'); };
        switch ($type) {
            case 'text':
                return $text !== '' || $heading !== '' ? $lead : [];
            case 'event':
                if (!$e) return [];
                $card = ['type' => 'event', 'title' => $e['title'], 'date' => $e['date_label'], 'time' => trim($e['time_label'] . ($e['doors_label'] !== '' ? ' · ' . $e['doors_label'] : '')), 'location' => $e['location'],
                    'image' => $variant === 'flyer' ? '' : $e['image']];
                return array_merge($lead, [$card, ['type' => 'button', 'label' => $ticket_label($e), 'url' => $e['url']]]);
            case 'events':
                if (count($ev) < 2) return [];
                $out = $lead;
                foreach ($ev as $x) {
                    $out[] = ['type' => 'event', 'title' => $x['title'], 'date' => $x['date_label'], 'time' => $x['time_label'], 'location' => $x['location'], 'image' => $variant === 'compact' ? '' : $x['image']];
                    $out[] = ['type' => 'text', 'html' => '<p style="margin:-6px 0 18px;text-align:center;"><a href="' . esc_url($x['url']) . '" class="tm-accent-text" style="color:' . $acc . ';font-weight:700;text-decoration:none;">' . self::e($x['tickets'] && !$x['is_past'] ? 'Tickets & Infos' : 'Mehr erfahren') . ' →</a></p>'];
                }
                return $out;
            case 'coupon':
                $c = $ctx['coupon'];
                if (!$c) return [];
                $hint = trim($c['discount_label'] . ' Rabatt' . ($c['expires_label'] !== '' ? ' · gültig bis ' . $c['expires_label'] : '') . ($c['app_only'] ? ' · nur in der App' : ''));
                $out = array_merge($lead, [['type' => 'code', 'value' => $c['code'], 'hint' => $hint]]);
                if ($e && !$c['app_only']) $out[] = ['type' => 'button', 'label' => $btn !== '' ? $btn : 'Code jetzt einlösen', 'url' => $e['url']];
                return $out;
            case 'lineup':
                $rows = [];
                foreach ($ev as $x) foreach ($x['lineup'] as $n) $rows[] = $n;
                if (!$rows) return [];
                $html = '<p style="margin:0 0 16px;font-size:17px;line-height:27px;font-weight:700;">' . implode(' <span style="color:' . $acc . ';" class="tm-accent-text">·</span> ', array_map([__CLASS__, 'e'], array_slice(array_unique($rows), 0, 20))) . '</p>';
                return array_merge($lead ?: [['type' => 'text', 'html' => self::heading('Line-up')]], [['type' => 'text', 'html' => $html]]);
            case 'program':
                if (!$e || !$e['program']) return [];
                $multi = count(array_unique(array_column($e['program'], 'date'))) > 1;
                $rows = array_map(function ($s) use ($multi) {
                    $d = $multi && $s['date'] !== '' ? self::WEEKDAYS[intval(gmdate('w', strtotime($s['date'] . ' 12:00:00')))] . ' ' : '';
                    return [$d . $s['time'] . ($s['end'] !== '' ? '–' . $s['end'] : ''), self::e($s['title']) . ($s['stage'] !== '' && $s['stage'] !== 'Programm' ? ' <span style="font-weight:400;">· ' . self::e($s['stage']) . '</span>' : '')];
                }, $e['program']);
                return array_merge($lead, [['type' => 'info', 'title' => $lead ? '' : 'Programm', 'rows' => $rows]]);
            case 'prices':
                if (!$e) return [];
                $rows = [];
                if ($e['free_entry']) $rows[] = ['Eintritt', 'frei'];
                foreach ($e['prices'] as $p) $rows[] = [$p['name'], $p['sold_out'] ? 'ausverkauft' : self::e($p['price']) . ($p['note'] !== '' ? '<br><span style="font-weight:400;font-size:12px;">' . self::e($p['note']) . '</span>' : '')];
                if (!$rows && $e['price_from'] !== '') $rows[] = ['Tickets', self::e($e['price_from'])];
                if (!$rows) return [];
                $out = array_merge($lead, [['type' => 'info', 'title' => $lead ? '' : 'Tickets & Preise', 'rows' => $rows]]);
                if ($e['tickets'] && !$e['is_past']) $out[] = ['type' => 'button', 'label' => $btn !== '' ? $btn : 'Tickets sichern', 'url' => $e['url']];
                return $out;
            case 'directions':
                if (!$e || ($e['location'] === '' && $e['address'] === '')) return [];
                $rows = array_values(array_filter([$e['location'] !== '' ? ['Ort', self::e($e['location'])] : null, $e['address'] !== '' ? ['Adresse', nl2br(self::e($e['address']), false)] : null]));
                $out = array_merge($lead, [['type' => 'info', 'title' => $lead ? '' : 'Anfahrt', 'rows' => $rows]]);
                if ($u = self::map_url($e)) $out[] = ['type' => 'text', 'html' => '<p style="margin:-6px 0 16px;text-align:center;"><a href="' . esc_url($u) . '" class="tm-accent-text" style="color:' . $acc . ';font-weight:700;text-decoration:none;">' . self::e($btn !== '' ? $btn : 'Route planen') . ' →</a></p>'];
                return $out;
            case 'gallery':
                $imgs = [];
                foreach ($ev as $x) foreach ($x['gallery'] as $g) $imgs[] = [$g, $x['title']];
                $imgs = array_slice($imgs, 0, 4);
                if (count($imgs) < 2) return [];
                $cells = '';
                foreach (array_chunk($imgs, 2) as $row) {
                    $cells .= '<tr>';
                    foreach ($row as $i => $g) $cells .= '<td width="50%" style="padding:' . ($i ? '0 0 8px 4px' : '0 4px 8px 0') . ';"><img src="' . esc_url($g[0]) . '" width="276" alt="' . esc_attr('Bild von ' . $g[1]) . '" class="tm-img" style="display:block;width:100%;max-width:276px;height:auto;border:0;border-radius:10px;"></td>';
                    if (count($row) === 1) $cells .= '<td width="50%"></td>';
                    $cells .= '</tr>';
                }
                return array_merge($lead, [['type' => 'text', 'html' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $cells . '</table>']]);
            case 'app':
                $b = $ctx['brand'];
                if (empty($b['ios']) && empty($b['android'])) return [];
                $links = array_values(array_filter([!empty($b['ios']) ? '<a href="' . esc_url($b['ios']) . '" class="tm-accent-text" style="color:' . $acc . ';font-weight:700;text-decoration:none;">App Store</a>' : '',
                    !empty($b['android']) ? '<a href="' . esc_url($b['android']) . '" class="tm-accent-text" style="color:' . $acc . ';font-weight:700;text-decoration:none;">Google Play</a>' : '']));
                $lead = $lead ?: [['type' => 'text', 'html' => self::heading('Hol dir die App') . self::paras(($ctx['address'] === 'sie' ? 'Ihre' : 'Deine') . ' Tickets immer dabei – auch ohne Empfang.')]];
                return array_merge($lead, [['type' => 'text', 'html' => '<p style="margin:0 0 16px;text-align:center;font-size:16px;">' . implode(' &nbsp;·&nbsp; ', $links) . '</p>']]);
            case 'social':
                $names = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'spotify' => 'Spotify', 'x' => 'X', 'twitter' => 'X', 'whatsapp' => 'WhatsApp', 'linkedin' => 'LinkedIn'];
                $links = [];
                foreach ((array) ($ctx['brand']['social'] ?? []) as $k => $u) if (is_string($u) && preg_match('#^https?://#', $u)) $links[] = '<a href="' . esc_url($u) . '" class="tm-accent-text" style="color:' . $acc . ';font-weight:700;text-decoration:none;">' . self::e($names[strtolower((string) $k)] ?? ucfirst((string) $k)) . '</a>';
                if (!$links) return [];
                return array_merge($lead, [['type' => 'text', 'html' => '<p style="margin:0 0 16px;text-align:center;font-size:15px;">' . implode(' &nbsp;·&nbsp; ', $links) . '</p>']]);
        }
        return [];
    }

    /** Ein Entwurf → Inhalt (HTML ohne Gerüst, für das Feld content der Kampagne) */
    public static function build($variant, array $d, array $ctx, array $f, &$removed) {
        $ev = $ctx['events'];
        $e = $ev[0] ?? null;
        $b = $ctx['brand'];
        $acc = (string) ($b['accent'] ?? '#FC2F55');
        $on = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::on_color($acc) : '#FFFFFF';
        $blocks = [];
        $badge = self::line($d['badge'] ?? '', 30);
        if ($badge !== '' && self::violates($badge, $f)) { $removed++; $badge = ''; }
        $head = self::line($d['headline'] ?? '', 80);
        if ($head === '' || self::violates($head, $f)) { if ($head !== '') $removed++; $head = $e ? $e['title'] : self::GOALS[$ctx['goal']]; }
        $hero = '';
        if ($variant === 'flyer' && $e && $e['image'] !== '') $hero .= '<img src="' . esc_url($e['image']) . '" width="568" alt="' . esc_attr($e['title']) . '" class="tm-img" style="display:block;width:100%;max-width:568px;height:auto;border:0;border-radius:12px;margin:0 0 18px;">';
        if ($badge !== '') $hero .= '<div style="margin:0 0 10px;"><span style="display:inline-block;background:' . $acc . ';color:' . $on . ';font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:5px 12px;border-radius:999px;">' . self::e($badge) . '</span></div>';
        $size = $variant === 'compact' ? 24 : 28;
        $hero .= '<h1 class="tm-title tm-nl-hero" style="margin:0 0 14px;font-size:' . $size . 'px;line-height:' . intval($size * 1.2) . 'px;font-weight:800;">' . self::e($head) . '</h1>';
        $greet = $ctx['address'] === 'sie' ? 'Guten Tag,' : 'Hallo {{ contact.FIRSTNAME | default: "du" }},';
        $intro = self::clean($d['intro'] ?? '', $f, $removed, $variant === 'story' ? 900 : 450);
        $blocks[] = ['type' => 'text', 'html' => $hero . '<p style="margin:0 0 12px;">' . $greet . '</p>' . self::paras($intro)];

        // Bausteine in der Reihenfolge der KI; Daten-Bausteine nur mit Daten, jeder höchstens einmal (Text beliebig oft)
        $seen = [];
        $order = [];
        foreach ((array) ($d['blocks'] ?? []) as $blk) {
            $t = is_array($blk) ? (string) ($blk['type'] ?? '') : '';
            if (!in_array($t, self::BLOCKS, true) || ($t !== 'text' && isset($seen[$t]))) continue;
            $seen[$t] = true;
            $order[] = $blk;
        }
        // Pflicht: Gutschein, wenn gewählt; Event-Karte bzw. Liste, wenn Events gewählt; gewünschte Inhalte
        $need = [];
        if ($ctx['coupon']) $need[] = 'coupon';
        if (count($ev) > 1) $need[] = 'events'; elseif ($e) $need[] = 'event';
        foreach ($ctx['include'] as $k) $need[] = $k;
        // Fehlende Pflicht-Bausteine: Event-Karte bzw. Liste und Gutschein vorn (nach der Einleitung), Wunsch-Inhalte hinten
        $front = [];
        foreach ($need as $t) {
            if (isset($seen[$t])) continue;
            $seen[$t] = true;
            if (in_array($t, ['event', 'events', 'coupon'], true)) $front[] = ['type' => $t]; else $order[] = ['type' => $t];
        }
        $order = array_merge($front, $order);
        if (isset($seen['events'])) $order = array_values(array_filter($order, function ($x) { return $x['type'] !== 'event'; }));
        // Kompakt: keine Galerie, keine langen Texte
        if ($variant === 'compact') $order = array_values(array_filter($order, function ($x) { return $x['type'] !== 'gallery'; }));
        foreach ($order as $blk) foreach (self::data_block($blk['type'], $blk, $ctx, $f, $removed, $variant) as $x) $blocks[] = $x;

        $closing = self::clean($d['closing'] ?? '', $f, $removed, 300);
        $sig = ($ctx['address'] === 'sie' ? 'Ihr' : 'Dein') . ' Team von ' . $ctx['name'];
        $blocks[] = ['type' => 'text', 'html' => self::paras($closing) . '<p style="margin:0 0 4px;font-weight:700;">' . self::e($sig) . '</p>'];
        $html = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::blocks_html($blocks) : implode('', array_map(function ($x) { return (string) ($x['html'] ?? ''); }, $blocks));
        return str_replace(["\r", "\n"], '', $html);
    }

    /** Vorschau wie im Postfach (Gerüst, Fuß mit Abmeldelink und Impressum, Links mit UTM) */
    private static function preview($content, $subject, $pre, $org, $cid) {
        if (!class_exists('TIX_Newsletter')) return $content;
        $html = TIX_Newsletter::html(['id' => $cid, 'organizer_id' => $org, 'subject' => $subject, 'preheader' => $pre, 'content' => $content]);
        return str_replace(['{{ unsubscribe }}', '{{ contact.FIRSTNAME | default: "du" }}'], ['#', 'du'], $html);
    }

    /** Text-Fassung (für Studio und Prüfung) */
    public static function text($content) {
        $t = preg_replace_callback('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', function ($m) { $l = trim(wp_strip_all_tags($m[2])); return $l . ' (' . html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') . ')'; }, (string) $content);
        $t = preg_replace('#<!--.*?-->#s', '', $t);
        $t = preg_replace('#</(p|h1|h2|tr|div|table)>|<br\s*/?>#i', "\n", $t);
        $t = preg_replace('#</td>#i', ' ', $t);
        $t = html_entity_decode(wp_strip_all_tags($t), ENT_QUOTES, 'UTF-8');
        $t = preg_replace("/[ \t]+/", ' ', $t);
        $t = preg_replace("/ *\n */", "\n", $t);
        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }
}

TIX_Newsletter_AI::init();
