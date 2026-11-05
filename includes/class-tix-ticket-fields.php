<?php
/**
 * TIX_Ticket_Fields – personalisierte Tickets und Zusatzfragen beim Kauf (1.38.647, User 2026-11-05).
 *
 * Einstellung je Event (Meta `_tix_ticket_fields`) oder als Vorgabe je Veranstalter (`_tix_ticket_fields_default` am
 * Veranstalter, Einzel-Seite Option `tix_ticket_fields_default`); das Event übernimmt die Vorgabe, solange es keine eigene
 * Einstellung hat (`inherit`). Felder: personalize off|optional|required (+ Kategorien), ask_email, age_confirm 0|16|18,
 * questions[] (je Ticket oder je Bestellung, Typen fest, höchstens 10), transfer allowed|until_hours|never, retention_days.
 * Bei required gibt es keine Ausnahme – auch nicht an der Studio- bzw. App-Kasse (User 2026-11-05).
 *
 * Kasse (POST /customer/orders, POST /pos/orders): holders[] {event_id?, index, first_name, last_name, email?, answers{}}
 * in Reihenfolge je Event und Kategorie, answers{} für Bestellfragen, age_confirmed. Prüfung serverseitig, Fehler 400
 * tix_holder_missing | tix_answer_invalid | tix_age_confirm mit data {event_id, index, seq, field}.
 * Ablage: Inhaber in den Ticket-Metas (_tix_ticket_owner_*), Antworten verschlüsselt in {prefix}tix_answers
 * (TIX_Ad_Accounts::enc), bis zur Ticket-Erzeugung verschlüsselt in der Option _tix_order_holders_<id>.
 * Löschen: Antworten und Zwischenablage retention_days nach Event-Ende (Cron täglich), sofort bei Konto-Löschung.
 */
if (!defined('ABSPATH')) exit;

class TIX_Ticket_Fields {

    const NS = 'tixomat/v1';
    const META = '_tix_ticket_fields';
    const META_DEFAULT = '_tix_ticket_fields_default';
    const OPT_DEFAULT = 'tix_ticket_fields_default';
    const DB_VERSION = '1';
    const TYPES = ['text', 'textarea', 'select', 'radio', 'checkbox', 'date', 'phone', 'accessibility'];
    const MAX_QUESTIONS = 10;
    /** Hinweis bei Fragen nach besonderen Kategorien (Art. 9 DSGVO) – gespeichert wird trotzdem, das Studio warnt */
    const SENSITIVE = '/gesundheit|krank|allergi|behinder|medikament|schwanger|religi|konfession|glaube|ethni|herkunft|sexuell|orientierung|partei|gewerkschaft|vorstraf/iu';

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_create'], 25);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_native_ticket_created', [__CLASS__, 'on_ticket_created'], 10, 5);
        add_action('tix_ticket_transferred', [__CLASS__, 'on_transferred'], 5, 1);
        add_action('tix_account_deleted', [__CLASS__, 'on_account_deleted'], 10, 2);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'checkin_info'], 10, 3);
        add_action('init', function () { if (!wp_next_scheduled('tix_ticket_fields_daily')) wp_schedule_event(time() + 2400, 'daily', 'tix_ticket_fields_daily'); }, 53);
        add_action('tix_ticket_fields_daily', [__CLASS__, 'daily']);
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'tix_answers'; }

    public static function maybe_create() {
        if (get_option('tix_answers_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta("CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ticket_id BIGINT UNSIGNED NULL,
            event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            q_key VARCHAR(20) NOT NULL DEFAULT '',
            label VARCHAR(190) NOT NULL DEFAULT '',
            value TEXT,
            at_checkin TINYINT(1) NOT NULL DEFAULT 0,
            created DATETIME NOT NULL,
            expires_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY ticket_id (ticket_id),
            KEY event_id (event_id),
            KEY expires_at (expires_at)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_answers_db', self::DB_VERSION, false);
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400, array $extra = []) {
        return new WP_Error($code, $msg, array_merge(['status' => $status, 'field' => $field], $extra));
    }

    private static function enc($v) { return TIX_Ad_Accounts::enc((string) $v); }
    private static function dec($v) { return (string) TIX_Ad_Accounts::dec((string) $v); }

    // ═══════════════ Einstellung ═══════════════

    /** Eingabe → bereinigte Einstellung | WP_Error */
    public static function sanitize($in) {
        if (!is_array($in)) return self::bad('fields', 'Ungültige Einstellung.');
        $p = (string) ($in['personalize'] ?? 'off');
        if (!in_array($p, ['off', 'optional', 'required'], true)) return self::bad('personalize', 'Personalisierung: off, optional oder required.');
        $age = intval($in['age_confirm'] ?? 0);
        if (!in_array($age, [0, 16, 18], true)) return self::bad('age_confirm', 'Altersbestätigung: 0, 16 oder 18.');
        $tr = (string) ($in['transfer'] ?? 'allowed');
        if (!in_array($tr, ['allowed', 'until_hours', 'never'], true)) return self::bad('transfer', 'Umschreiben: allowed, until_hours oder never.');
        $ret = isset($in['retention_days']) ? intval($in['retention_days']) : 30;
        if ($ret < 0 || $ret > 365) return self::bad('retention_days', 'Löschfrist: 0–365 Tage nach dem Event.');
        $qs = [];
        $keys = [];
        foreach (array_values((array) ($in['questions'] ?? [])) as $i => $q) {
            if (!is_array($q)) continue;
            if (count($qs) >= self::MAX_QUESTIONS) return self::bad('questions', 'Höchstens ' . self::MAX_QUESTIONS . ' Fragen.');
            $type = (string) ($q['type'] ?? 'text');
            if (!in_array($type, self::TYPES, true)) return self::bad('questions.' . $i . '.type', 'Unbekannter Fragetyp.');
            $label = $type === 'accessibility' ? (trim((string) ($q['label'] ?? '')) ?: 'Ich brauche einen barrierefreien Platz') : mb_substr(trim(sanitize_text_field((string) ($q['label'] ?? ''))), 0, 120);
            if ($label === '') return self::bad('questions.' . $i . '.label', 'Bitte eine Frage eingeben.');
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($q['key'] ?? '')));
            if ($key === '' || isset($keys[$key])) { $n = 1; while (isset($keys['q' . $n])) $n++; $key = 'q' . $n; }
            $keys[$key] = true;
            $opts = [];
            if (in_array($type, ['select', 'radio'], true)) {
                foreach ((array) ($q['options'] ?? []) as $o) { $o = mb_substr(trim(sanitize_text_field((string) $o)), 0, 80); if ($o !== '' && !in_array($o, $opts, true)) $opts[] = $o; }
                $opts = array_slice($opts, 0, 20);
                if (count($opts) < 2) return self::bad('questions.' . $i . '.options', 'Bitte mindestens zwei Antworten vorgeben.');
            }
            $qs[] = ['key' => substr($key, 0, 20), 'label' => $label, 'type' => $type, 'options' => $opts, 'required' => !empty($q['required']),
                'scope' => ($q['scope'] ?? 'ticket') === 'order' ? 'order' : 'ticket', 'categories' => array_values(array_unique(array_map('intval', (array) ($q['categories'] ?? [])))),
                'help' => mb_substr(trim(sanitize_text_field((string) ($q['help'] ?? ''))), 0, 200), 'max' => max(10, min(1000, intval($q['max'] ?? ($type === 'textarea' ? 1000 : 200)))),
                'at_checkin' => !empty($q['at_checkin'])];
        }
        return ['personalize' => $p, 'categories' => array_values(array_unique(array_map('intval', (array) ($in['categories'] ?? [])))), 'ask_email' => !empty($in['ask_email']),
            'age_confirm' => $age, 'questions' => $qs, 'transfer' => $tr, 'transfer_hours' => max(1, min(168, intval($in['transfer_hours'] ?? 24))), 'retention_days' => $ret];
    }

    /** Hinweise zum Datenschutz (Studio zeigt sie beim Speichern) */
    public static function warnings(array $c) {
        $w = [];
        foreach ($c['questions'] as $q) if ($q['type'] !== 'accessibility' && preg_match(self::SENSITIVE, $q['label'] . ' ' . $q['help'])) $w[] = ['key' => $q['key'], 'code' => 'sensitive', 'message' => '„' . $q['label'] . '“ fragt vermutlich nach besonders geschützten Daten (z. B. Gesundheit). Nur fragen, wenn es für das Event nötig ist – sonst bitte weglassen.'];
        return $w;
    }

    public static function empty_config() { return self::sanitize([]); }

    private static function org_of($event_id) {
        if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) return 0;
        return intval(get_post_meta($event_id, '_tix_organizer_id', true));
    }

    public static function default_for($org) {
        $v = $org ? get_post_meta(intval($org), self::META_DEFAULT, true) : get_option(self::OPT_DEFAULT, null);
        return is_array($v) ? $v : null;
    }

    /** Wirksame Einstellung eines Events (eigene, sonst Vorgabe des Veranstalters, sonst aus) */
    public static function config($event_id) {
        $own = get_post_meta(intval($event_id), self::META, true);
        if (is_array($own)) return $own;
        $d = self::default_for(self::org_of($event_id));
        return $d ?: self::empty_config();
    }

    public static function active(array $c) { return $c['personalize'] !== 'off' || $c['questions'] || $c['age_confirm'] > 0; }

    /** Für GET /public/events/{id}: null ohne Abfrage, sonst ohne Löschfrist und ohne Einlass-Kennzeichen */
    public static function public_config($event_id) {
        $c = self::config($event_id);
        if (!self::active($c)) return null;
        unset($c['retention_days']);
        $c['questions'] = array_map(function ($q) { unset($q['at_checkin']); return $q; }, $c['questions']);
        return $c;
    }

    private static function applies(array $cats, $cat) { return !$cats || in_array(intval($cat), $cats, true); }

    // ═══════════════ Prüfung in der Kasse ═══════════════

    /** Kasse-Zeile ist ein Eintrittsticket? */
    private static function is_ticket_line(array $it) {
        $m = (array) ($it['meta'] ?? []);
        return intval($it['event_id'] ?? 0) > 0 && empty($m['table_reservation']) && empty($m['wallet_topup']) && empty($m['gift']) && intval($it['qty'] ?? 0) > 0;
    }

    private static function check_value(array $q, $v, array $where) {
        $empty = $v === null || $v === '' || $v === false || $v === [];
        if ($empty) return $q['required'] ? self::bad($q['key'], '„' . $q['label'] . '“ fehlt.', 'tix_answer_invalid', 400, $where) : [null];
        switch ($q['type']) {
            case 'checkbox': case 'accessibility':
                $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) return self::bad($q['key'], '„' . $q['label'] . '“: ja oder nein.', 'tix_answer_invalid', 400, $where);
                if ($q['required'] && !$b) return self::bad($q['key'], '„' . $q['label'] . '“ bitte bestätigen.', 'tix_answer_invalid', 400, $where);
                return [$b ? 'ja' : 'nein'];
            case 'select': case 'radio':
                $s = (string) $v;
                if (!in_array($s, $q['options'], true)) return self::bad($q['key'], '„' . $q['label'] . '“: bitte eine der Antworten wählen.', 'tix_answer_invalid', 400, $where);
                return [$s];
            case 'date':
                $s = (string) $v;
                $d = DateTime::createFromFormat('!Y-m-d', $s);
                if (!$d || $d->format('Y-m-d') !== $s) return self::bad($q['key'], '„' . $q['label'] . '“: Datum im Format JJJJ-MM-TT.', 'tix_answer_invalid', 400, $where);
                return [$s];
            case 'phone':
                $s = trim(preg_replace('/[^0-9+ ()\/-]/', '', (string) $v));
                if (strlen(preg_replace('/\D/', '', $s)) < 5) return self::bad($q['key'], '„' . $q['label'] . '“: bitte eine Telefonnummer.', 'tix_answer_invalid', 400, $where);
                return [mb_substr($s, 0, 40)];
            default:
                $s = $q['type'] === 'textarea' ? sanitize_textarea_field((string) $v) : sanitize_text_field((string) $v);
                $s = trim($s);
                if ($s === '' && $q['required']) return self::bad($q['key'], '„' . $q['label'] . '“ fehlt.', 'tix_answer_invalid', 400, $where);
                if (mb_strlen($s) > $q['max']) return self::bad($q['key'], '„' . $q['label'] . '“: höchstens ' . $q['max'] . ' Zeichen.', 'tix_answer_invalid', 400, $where);
                return [$s === '' ? null : $s];
        }
    }

    /**
     * Prüft Inhaber, Antworten und Altersbestätigung zur Kasse. → [plan|null, WP_Error|null]
     * plan: ['slots' => ["eid:cat" => [[first, last, email, answers{key: value}], …]], 'order' => [eid => {key: value}], 'age' => 0|16|18]
     */
    public static function validate(array $items, $holders, $answers, $age_confirmed) {
        $lines = [];
        foreach ($items as $it) if (is_array($it) && self::is_ticket_line($it)) $lines[] = $it;
        if (!$lines) return [null, null];
        $cfg = [];
        foreach ($lines as $it) { $e = intval($it['event_id']); if (!isset($cfg[$e])) $cfg[$e] = self::config($e); }
        if (!array_filter($cfg, [__CLASS__, 'active'])) return [null, null];
        $main = intval($lines[0]['event_id']);
        $buckets = [];
        foreach (array_values(is_array($holders) ? $holders : []) as $h) {
            if (!is_array($h)) continue;
            $e = intval($h['event_id'] ?? 0) ?: $main;
            $buckets[$e . ':' . intval($h['index'] ?? ($h['cat_index'] ?? 0))][] = $h;
        }
        $plan = ['slots' => [], 'order' => [], 'age' => 0];
        $seqs = [];
        foreach ($lines as $it) {
            $e = intval($it['event_id']); $cat = intval($it['cat_index'] ?? 0); $c = $cfg[$e];
            if (!self::active($c)) continue;
            $plan['age'] = max($plan['age'], intval($c['age_confirm']));
            $named = $c['personalize'] !== 'off' && self::applies($c['categories'], $cat);
            $tq = array_values(array_filter($c['questions'], function ($q) use ($cat) { return $q['scope'] === 'ticket' && self::applies($q['categories'], $cat); }));
            if (!$named && !$tq) continue;
            $b = $e . ':' . $cat;
            for ($n = 0; $n < intval($it['qty']); $n++) {
                $seq = $seqs[$b] = ($seqs[$b] ?? -1) + 1;
                $h = $buckets[$b][$seq] ?? [];
                $where = ['event_id' => $e, 'index' => $cat, 'seq' => $seq];
                $first = mb_substr(trim(sanitize_text_field((string) ($h['first_name'] ?? ''))), 0, 80);
                $last = mb_substr(trim(sanitize_text_field((string) ($h['last_name'] ?? ''))), 0, 80);
                if ($named && $c['personalize'] === 'required' && ($first === '' || $last === '')) {
                    return [null, self::bad($first === '' ? 'first_name' : 'last_name', 'Bitte Vor- und Nachname für Ticket ' . ($seq + 1) . ' eintragen.', 'tix_holder_missing', 400, $where)];
                }
                $email = '';
                if (!empty($h['email'])) {
                    $email = strtolower(sanitize_email((string) $h['email']));
                    if (!is_email($email)) return [null, self::bad('email', 'Bitte eine gültige E-Mail für Ticket ' . ($seq + 1) . '.', 'tix_holder_missing', 400, $where)];
                }
                $ans = [];
                $hin = (array) ($h['answers'] ?? []);
                foreach ($tq as $q) {
                    $r = self::check_value($q, $hin[$q['key']] ?? null, $where);
                    if (is_wp_error($r)) return [null, $r];
                    if ($r[0] !== null) $ans[$q['key']] = $r[0];
                }
                $plan['slots'][$b][] = [$named ? $first : '', $named ? $last : '', $named ? $email : '', $ans];
            }
        }
        $ain = is_array($answers) ? $answers : [];
        foreach ($cfg as $e => $c) {
            foreach ($c['questions'] as $q) {
                if ($q['scope'] !== 'order') continue;
                $v = $ain['e' . $e][$q['key']] ?? ($ain[$q['key']] ?? null);
                $r = self::check_value($q, $v, ['event_id' => $e, 'index' => null, 'seq' => null]);
                if (is_wp_error($r)) return [null, $r];
                if ($r[0] !== null) $plan['order'][$e][$q['key']] = $r[0];
            }
        }
        if ($plan['age'] > 0 && !filter_var($age_confirmed, FILTER_VALIDATE_BOOLEAN)) {
            return [null, self::bad('age_confirmed', $plan['age'] === 18 ? 'Bitte bestätigen, dass alle Gäste mindestens 18 Jahre alt sind.' : 'Bitte bestätigen, dass alle Gäste mindestens 16 Jahre alt sind (oder mit Erziehungsbeauftragung kommen).', 'tix_age_confirm', 400)];
        }
        return [$plan, null];
    }

    /** Prüfen aus einer REST-Anfrage (Kasse und Studio-Kasse) */
    public static function validate_request(array $items, WP_REST_Request $req) {
        return self::validate($items, $req->get_param('holders'), $req->get_param('answers'), $req->get_param('age_confirmed'));
    }

    // ═══════════════ Ablage ═══════════════

    /** Ende eines Events (wie Auszahlung Promoter) + Löschfrist → MySQL-Datum (UTC) */
    public static function expires($event_id) {
        $ret = intval(self::config($event_id)['retention_days'] ?? 30);
        $ds = (string) get_post_meta($event_id, '_tix_date_start', true);
        $de = (string) get_post_meta($event_id, '_tix_date_end', true);
        $te = (string) get_post_meta($event_id, '_tix_time_end', true);
        $ts = (string) get_post_meta($event_id, '_tix_time_start', true);
        $end = time();
        if ($ds !== '') {
            try {
                $d = new DateTime(($de ?: $ds) . ' ' . ($te ?: '23:59'), wp_timezone());
                if ($de === '' && $te !== '' && $ts !== '' && $te < $ts) $d->modify('+1 day');
                $end = $d->getTimestamp();
            } catch (\Throwable $e) {}
        }
        return gmdate('Y-m-d H:i:s', max($end, time()) + $ret * DAY_IN_SECONDS);
    }

    private static function label_of($event_id, $key) {
        foreach (self::config($event_id)['questions'] as $q) if ($q['key'] === $key) return [$q['label'], !empty($q['at_checkin'])];
        return [$key, false];
    }

    private static function insert_answer($order_id, $ticket_id, $event_id, $key, $value) {
        global $wpdb;
        list($label, $at) = self::label_of($event_id, $key);
        $wpdb->insert(self::table(), ['order_id' => intval($order_id), 'ticket_id' => $ticket_id ? intval($ticket_id) : null, 'event_id' => intval($event_id), 'q_key' => $key,
            'label' => mb_substr($label, 0, 190), 'value' => self::enc($value), 'at_checkin' => $at ? 1 : 0, 'created' => current_time('mysql', true), 'expires_at' => self::expires($event_id)]);
    }

    /** Nach dem Anlegen der Bestellung: Plan ablegen, Bestellfragen speichern, Altersbestätigung festhalten */
    public static function store($order_id, $plan) {
        if (!$plan) return;
        self::maybe_create();
        $order_id = intval($order_id);
        if ($plan['slots']) update_option('_tix_order_holders_' . $order_id, self::enc(wp_json_encode($plan['slots'])), false);
        foreach ($plan['order'] as $e => $ans) foreach ($ans as $k => $v) self::insert_answer($order_id, null, $e, $k, $v);
        if ($plan['age'] > 0) update_option('_tix_order_age_' . $order_id, ['age' => $plan['age'], 'at' => current_time('mysql', true), 'by' => get_current_user_id()], false);
    }

    private static $cursor = [];

    /** Hook aus TIX_Tickets::on_native_order_completed: Inhaber und Antworten an das neue Ticket */
    public static function on_ticket_created($ticket_id, $order_id, $event_id, $cat_index, $seq = 0) {
        $raw = get_option('_tix_order_holders_' . intval($order_id));
        if (!$raw) return;
        $slots = json_decode(self::dec($raw), true);
        $b = intval($event_id) . ':' . intval($cat_index);
        if (!is_array($slots) || empty($slots[$b])) return;
        $k = intval($order_id) . '|' . $b;
        $i = self::$cursor[$k] = (self::$cursor[$k] ?? -1) + 1;
        $s = $slots[$b][$i] ?? null;
        if (!is_array($s)) return;
        list($first, $last, $email, $ans) = array_pad($s, 4, []);
        if ($first !== '' || $last !== '') {
            update_post_meta($ticket_id, '_tix_ticket_owner_first_name', $first);
            update_post_meta($ticket_id, '_tix_ticket_owner_last_name', $last);
            update_post_meta($ticket_id, '_tix_ticket_owner_name', trim($first . ' ' . $last));
            update_post_meta($ticket_id, '_tix_ticket_personalized', 1);
        }
        if ($email !== '') update_post_meta($ticket_id, '_tix_ticket_holder_email', $email); // Käufer bleibt _tix_ticket_owner_email (Konto, „Meine Tickets“)
        foreach ((array) $ans as $key => $v) self::insert_answer($order_id, $ticket_id, $event_id, $key, $v);
    }

    /** Antworten eines Tickets → [key => {label, value, at_checkin}] */
    public static function ticket_answers($ticket_id) {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT q_key, label, value, at_checkin FROM " . self::table() . " WHERE ticket_id = %d ORDER BY id", intval($ticket_id))) as $r) {
            $out[$r->q_key] = ['label' => $r->label, 'value' => self::dec($r->value), 'at_checkin' => (bool) $r->at_checkin];
        }
        return $out;
    }

    public static function order_answers($order_id) {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT event_id, q_key, label, value FROM " . self::table() . " WHERE order_id = %d AND ticket_id IS NULL ORDER BY id", intval($order_id))) as $r) {
            $out[] = ['event_id' => intval($r->event_id), 'key' => $r->q_key, 'label' => $r->label, 'value' => self::dec($r->value)];
        }
        return $out;
    }

    /** Inhaber eines Tickets (für Ticket-Ansicht, Einlass, Export) */
    public static function holder($ticket_id) {
        return ['first_name' => (string) get_post_meta($ticket_id, '_tix_ticket_owner_first_name', true), 'last_name' => (string) get_post_meta($ticket_id, '_tix_ticket_owner_last_name', true),
            'name' => (string) get_post_meta($ticket_id, '_tix_ticket_owner_name', true), 'email' => (string) (get_post_meta($ticket_id, '_tix_ticket_holder_email', true) ?: get_post_meta($ticket_id, '_tix_ticket_owner_email', true)),
            'personalized' => (bool) get_post_meta($ticket_id, '_tix_ticket_personalized', true)];
    }

    /** Für „Meine Tickets“: Inhaber, Pflicht und ob er fehlt bzw. änderbar ist (null ohne Abfrage) */
    public static function customer_holder($ticket_id) {
        $eid = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        $c = self::config($eid);
        if (!self::active($c)) return null;
        $cat = intval(get_post_meta($ticket_id, '_tix_ticket_cat_index', true));
        $named = $c['personalize'] !== 'off' && self::applies($c['categories'], $cat);
        $h = self::holder($ticket_id);
        $checked = (string) get_post_meta($ticket_id, '_tix_ticket_checked_in', true) === '1';
        return ['name' => $h['personalized'] ? $h['name'] : '', 'first_name' => $h['first_name'], 'last_name' => $h['last_name'], 'personalized' => $h['personalized'],
            'mode' => $named ? $c['personalize'] : 'off', 'missing' => $named && $c['personalize'] === 'required' && !$h['personalized'],
            'editable' => !$checked && (!$h['personalized'] || self::transfer_allowed($ticket_id) === true)];
    }

    // ═══════════════ Umschreiben, Löschen, Einlass ═══════════════

    /** Darf das Ticket umgeschrieben werden? (Regel des Events) → true | WP_Error */
    public static function transfer_allowed($ticket_id) {
        $eid = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        $c = self::config($eid);
        if (!self::active($c) || $c['transfer'] === 'allowed') return true;
        if ($c['transfer'] === 'never') return new WP_Error('tix_transfer', 'Tickets dieses Events lassen sich nicht umschreiben.', ['status' => 403]);
        $ds = (string) get_post_meta($eid, '_tix_date_start', true);
        $ts = (string) get_post_meta($eid, '_tix_time_start', true);
        try { $start = (new DateTime($ds . ' ' . ($ts ?: '00:00'), wp_timezone()))->getTimestamp(); } catch (\Throwable $e) { return true; }
        if ($start - time() < $c['transfer_hours'] * HOUR_IN_SECONDS) return new WP_Error('tix_transfer', 'Umschreiben geht nur bis ' . $c['transfer_hours'] . ' Stunden vor Beginn.', ['status' => 403]);
        return true;
    }

    /** Neuer Inhaber: alte Antworten löschen (nicht übertragen), Inhaber-E-Mail des Kaufs entfällt */
    public static function on_transferred($ticket_id) {
        global $wpdb;
        $wpdb->delete(self::table(), ['ticket_id' => intval($ticket_id)]);
        delete_post_meta($ticket_id, '_tix_ticket_holder_email');
        update_post_meta($ticket_id, '_tix_ticket_personalized', 1);
    }

    public static function on_account_deleted($uid, $email) {
        global $wpdb;
        $email = strtolower((string) $email);
        if ($email === '' || !class_exists('TIX_Order')) return;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . TIX_Order::table_name() . " WHERE LOWER(billing_email) = %s", $email));
        foreach ((array) $ids as $oid) { $wpdb->delete(self::table(), ['order_id' => intval($oid)]); delete_option('_tix_order_holders_' . intval($oid)); }
    }

    /** Täglich: abgelaufene Antworten löschen, Zwischenablage nach Event-Ende + Frist entfernen */
    public static function daily() {
        global $wpdb;
        $t = self::table();
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return;
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE expires_at IS NOT NULL AND expires_at < %s LIMIT 20000", current_time('mysql', true)));
        foreach ((array) $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_tix\\_order\\_holders\\_%' LIMIT 2000") as $name) {
            $oid = intval(substr($name, strlen('_tix_order_holders_')));
            $eid = class_exists('TIX_Order') ? intval($wpdb->get_var($wpdb->prepare("SELECT event_id FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d LIMIT 1", $oid))) : 0;
            if (!$eid || self::expires($eid) < current_time('mysql', true) || get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid])) delete_option($name);
        }
    }

    /** Einlass (POST /checkin/scan): Inhaber, Ausweis-Hinweis, Alter und Einlass-Fragen ergänzen */
    public static function checkin_info($res, $handler, $req) {
        if (!($req instanceof WP_REST_Request) || $req->get_route() !== '/' . self::NS . '/checkin/scan' || is_wp_error($res)) return $res;
        $res = rest_ensure_response($res);
        $d = $res->get_data();
        if (!is_array($d) || empty($d['code']) || ($d['type'] ?? '') !== 'ticket' || !class_exists('TIX_Tickets')) return $res;
        $t = TIX_Tickets::get_ticket_by_code((string) $d['code']);
        if (!$t) return $res;
        $eid = intval(get_post_meta($t->ID, '_tix_ticket_event_id', true));
        $c = self::config($eid);
        if (!self::active($c)) return $res;
        $h = self::holder($t->ID);
        $oid = intval(get_post_meta($t->ID, '_tix_ticket_order_id', true));
        $age = get_option('_tix_order_age_' . $oid);
        $d['holder'] = ['name' => $h['name'], 'personalized' => $h['personalized'], 'check_id' => $c['personalize'] !== 'off',
            'age_confirmed' => is_array($age) ? intval($age['age']) : 0,
            'answers' => array_values(array_map(function ($a) { return ['label' => $a['label'], 'value' => $a['value']]; }, array_filter(self::ticket_answers($t->ID), function ($a) { return $a['at_checkin']; })))];
        $res->set_data($d);
        return $res;
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $org = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/ticket-fields', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $org],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $org],
        ]);
        register_rest_route(self::NS, '/organizer/ticket-fields-default', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_default_get'], 'permission_callback' => ['TIX_Ad_Accounts', 'perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_default_save'], 'permission_callback' => ['TIX_Ad_Accounts', 'perm']],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/attendees', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_attendees'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/tickets/(?P<id>\d+)/holder', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_staff_holder'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/customer/tickets/(?P<id>\d+)/holder', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_customer_holder'], 'permission_callback' => '__return_true']);
    }

    private static function event_err($eid) {
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::can_access_event($eid)) return new WP_Error('rest_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return null;
    }

    private static function manager() { return current_user_can('manage_options') || (class_exists('TIX_Studio_Stats') && TIX_Studio_Stats::is_manager()); }

    public static function rest_get(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if ($e = self::event_err($eid)) return $e;
        $own = get_post_meta($eid, self::META, true);
        $c = self::config($eid);
        return rest_ensure_response(['ok' => true, 'inherit' => !is_array($own), 'config' => $c, 'default' => self::default_for(self::org_of($eid)), 'warnings' => self::warnings($c), 'types' => self::TYPES]);
    }

    public static function rest_save(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if ($e = self::event_err($eid)) return $e;
        if (!self::manager()) return new WP_Error('rest_forbidden', 'Einstellungen zu Ticket-Angaben ändern Inhaber und Team-Admins.', ['status' => 403]);
        if (rest_sanitize_boolean($req->get_param('inherit'))) { delete_post_meta($eid, self::META); return self::rest_get($req); }
        $p = $req->get_json_params() ?: $req->get_body_params();
        $c = self::sanitize((array) ($p['config'] ?? $p));
        if (is_wp_error($c)) return $c;
        update_post_meta($eid, self::META, $c);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return self::rest_get($req);
    }

    public static function rest_default_get(WP_REST_Request $req) {
        $d = self::default_for(TIX_Ad_Accounts::org($req));
        return rest_ensure_response(['ok' => true, 'config' => $d ?: self::empty_config(), 'set' => (bool) $d, 'warnings' => $d ? self::warnings($d) : []]);
    }

    public static function rest_default_save(WP_REST_Request $req) {
        $org = TIX_Ad_Accounts::org($req);
        if (rest_sanitize_boolean($req->get_param('clear'))) { if ($org) delete_post_meta($org, self::META_DEFAULT); else delete_option(self::OPT_DEFAULT); return self::rest_default_get($req); }
        $p = $req->get_json_params() ?: $req->get_body_params();
        $c = self::sanitize((array) ($p['config'] ?? $p));
        if (is_wp_error($c)) return $c;
        if ($org) update_post_meta($org, self::META_DEFAULT, $c); else update_option(self::OPT_DEFAULT, $c, false);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return self::rest_default_get($req);
    }

    /** Teilnehmerliste: GET /events/{id}/attendees[?format=csv] – E-Mail nur für Inhaber/Team-Admin */
    public static function rest_attendees(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if ($e = self::event_err($eid)) return $e;
        $mgr = self::manager();
        $c = self::config($eid);
        $tickets = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_event_id', 'meta_value' => $eid, 'orderby' => 'ID', 'order' => 'ASC']);
        $rows = [];
        $orders = [];
        foreach ($tickets as $tid) {
            $st = (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid');
            if (in_array($st, ['cancelled', 'refunded'], true)) continue;
            $h = self::holder($tid);
            $oid = intval(get_post_meta($tid, '_tix_ticket_order_id', true));
            $orders[$oid] = true;
            $ans = [];
            foreach (self::ticket_answers($tid) as $k => $a) $ans[$k] = $a['value'];
            $rows[] = ['ticket_id' => intval($tid), 'code' => (string) get_post_meta($tid, '_tix_ticket_code', true), 'category' => (string) get_post_meta($tid, '_tix_ticket_cat_name', true),
                'first_name' => $h['first_name'], 'last_name' => $h['last_name'], 'name' => $h['name'], 'email' => $mgr ? $h['email'] : null, 'personalized' => $h['personalized'],
                'checked_in' => (string) get_post_meta($tid, '_tix_ticket_checked_in', true) === '1', 'order_id' => $oid, 'answers' => $ans];
        }
        $order_answers = [];
        foreach (array_keys($orders) as $oid) foreach (self::order_answers($oid) as $a) if ($a['event_id'] === $eid) $order_answers[$oid][$a['key']] = $a['value'];
        foreach ($rows as &$r) $r['order_answers'] = $order_answers[$r['order_id']] ?? (object) [];
        unset($r);
        if ($req->get_param('format') === 'csv') {
            $qs = $c['questions'];
            $head = array_merge(['Ticket-Code', 'Kategorie', 'Vorname', 'Nachname'], $mgr ? ['E-Mail'] : [], ['Eingecheckt', 'Bestellung'], array_map(function ($q) { return $q['label']; }, $qs));
            $fh = fopen('php://temp', 'w+');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $head, ';');
            foreach ($rows as $r) {
                $line = array_merge([$r['code'], $r['category'], $r['first_name'] ?: $r['name'], $r['last_name']], $mgr ? [$r['email']] : [], [$r['checked_in'] ? 'ja' : 'nein', $r['order_id']]);
                foreach ($qs as $q) $line[] = $q['scope'] === 'order' ? (string) (((array) $r['order_answers'])[$q['key']] ?? '') : (string) ($r['answers'][$q['key']] ?? '');
                fputcsv($fh, array_map(function ($v) { $v = (string) $v; return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; }, $line), ';');
            }
            rewind($fh);
            $csv = stream_get_contents($fh);
            fclose($fh);
            $res = new WP_REST_Response(['ok' => true, 'filename' => 'teilnehmer-' . $eid . '-' . gmdate('Y-m-d') . '.csv', 'mime' => 'text/csv', 'data' => base64_encode($csv)]);
        } else {
            $res = new WP_REST_Response(['ok' => true, 'event_id' => $eid, 'config' => $c, 'attendees' => $rows, 'count' => count($rows)]);
        }
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /** Inhaber und Antworten eines Tickets setzen (Prüfung wie in der Kasse) */
    private static function set_holder($tid, WP_REST_Request $req, $staff) {
        $eid = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
        $c = self::config($eid);
        $cat = intval(get_post_meta($tid, '_tix_ticket_cat_index', true));
        $first = mb_substr(trim(sanitize_text_field((string) $req->get_param('first_name'))), 0, 80);
        $last = mb_substr(trim(sanitize_text_field((string) $req->get_param('last_name'))), 0, 80);
        if ($first === '' || $last === '') return self::bad($first === '' ? 'first_name' : 'last_name', 'Bitte Vor- und Nachname eintragen.', 'tix_holder_missing');
        $email = '';
        if ($req->get_param('email')) { $email = strtolower(sanitize_email((string) $req->get_param('email'))); if (!is_email($email)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse.'); }
        $in = (array) $req->get_param('answers');
        $new = [];
        foreach ($c['questions'] as $q) {
            if ($q['scope'] !== 'ticket' || !self::applies($q['categories'], $cat)) continue;
            if (!array_key_exists($q['key'], $in) && $staff) continue; // Team: nur übergebene Antworten ändern
            $r = self::check_value($q, $in[$q['key']] ?? null, ['ticket_id' => $tid]);
            if (is_wp_error($r)) return $r;
            $new[$q['key']] = $r[0];
        }
        global $wpdb;
        $oid = intval(get_post_meta($tid, '_tix_ticket_order_id', true));
        foreach ($new as $k => $v) {
            $wpdb->delete(self::table(), ['ticket_id' => intval($tid), 'q_key' => $k]);
            if ($v !== null) self::insert_answer($oid, $tid, $eid, $k, $v);
        }
        update_post_meta($tid, '_tix_ticket_owner_first_name', $first);
        update_post_meta($tid, '_tix_ticket_owner_last_name', $last);
        update_post_meta($tid, '_tix_ticket_owner_name', trim($first . ' ' . $last));
        update_post_meta($tid, '_tix_ticket_personalized', 1);
        if ($email !== '') update_post_meta($tid, '_tix_ticket_holder_email', $email);
        do_action('tix_ticket_holder_changed', $tid, $staff);
        return rest_ensure_response(['ok' => true, 'holder' => self::holder($tid), 'answers' => array_map(function ($a) { return $a['value']; }, self::ticket_answers($tid))]);
    }

    public static function rest_staff_holder(WP_REST_Request $req) {
        $tid = absint($req['id']);
        if (get_post_type($tid) !== 'tix_ticket') return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        if ($e = self::event_err(intval(get_post_meta($tid, '_tix_ticket_event_id', true)))) return $e;
        return self::set_holder($tid, $req, true);
    }

    /** Käufer trägt Namen nach bzw. korrigiert ihn (bis zum Einlass): angemeldet als Käufer oder mit ?key= der Bestellung */
    public static function rest_customer_holder(WP_REST_Request $req) {
        $tid = absint($req['id']);
        if (get_post_type($tid) !== 'tix_ticket') return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('ticket_holder', 20, 600)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen – bitte kurz warten.', ['status' => 429]);
        $oid = intval(get_post_meta($tid, '_tix_ticket_order_id', true));
        $order = class_exists('TIX_Order') ? TIX_Order::get($oid) : null;
        $mine = false;
        if (is_user_logged_in()) {
            $em = strtolower(wp_get_current_user()->user_email);
            $mine = $em !== '' && ($em === strtolower((string) get_post_meta($tid, '_tix_ticket_owner_email', true)) || ($order && strtolower($order->get_billing_email()) === $em));
        }
        $key = (string) $req->get_param('key');
        if (!$mine && $key !== '' && $order && hash_equals((string) $order->get_order_key(), $key)) $mine = true;
        if (!$mine) return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        if ((string) get_post_meta($tid, '_tix_ticket_checked_in', true) === '1') return self::bad('ticket', 'Das Ticket ist schon eingecheckt.', 'tix_holder_locked', 409);
        $st = (string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid');
        if (!in_array($st, ['valid', 'transferred'], true)) return self::bad('ticket', 'Das Ticket ist nicht gültig.', 'tix_holder_locked', 409);
        // Ein anderer Name als bisher ist bei „never“ bzw. nach der Frist ein Umschreiben
        $h = self::holder($tid);
        $changes = $h['personalized'] && $h['name'] !== '' && strcasecmp(trim($h['name']), trim(sanitize_text_field((string) $req->get_param('first_name')) . ' ' . sanitize_text_field((string) $req->get_param('last_name')))) !== 0;
        if ($changes && ($e = self::transfer_allowed($tid)) !== true) return $e;
        return self::set_holder($tid, $req, false);
    }
}

TIX_Ticket_Fields::init();
