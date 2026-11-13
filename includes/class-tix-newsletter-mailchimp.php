<?php
/**
 * TIX_Newsletter_Mailchimp – Mailchimp als zweiter Newsletter-Anbieter (1.38.807, User 13.11.2026).
 *
 * Anbieter-Wahl je Bereich (Einzel-Seite = 0, evendis = je Veranstalter) in den Newsletter-Einstellungen (TIX_Newsletter,
 * Schlüssel `provider` brevo|mailchimp). Der Mailchimp-Schlüssel wird nur geschrieben (verschlüsselt), nie ausgegeben.
 *
 * Kontakte und Double-Opt-in bleiben im Plugin (TIX_Newsletter). Mailchimp ist nur der Versandweg:
 *  - Kampagne: Empfänger (eigene Kontakte bzw. Käufer eines Events) werden in die gewählte Mailchimp-Zielgruppe
 *    (Audience) übernommen – status_if_new = subscribed, bestehende Abmeldungen in Mailchimp bleiben unangetastet –,
 *    in ein eigenes statisches Segment „evendis · Kampagne #<id>“ gelegt (je Lauf bis 5.000) und zur geplanten Zeit
 *    über dieses Segment versendet. Abmeldelink *|UNSUB|*, Vorname *|FNAME|*.
 *  - Kennzahlen aus /reports/{id}; Abmeldungen und Bounces per Webhook (/public/nl/hook/<geheim>, Mailchimp prüft per GET)
 *    und täglichem Abgleich. Segmente werden 30 Tage nach dem Versand gelöscht.
 *  - Mailchimp-Listen als eigene Zielgruppe (wie Brevo-Listen) gibt es nicht.
 * Listen-Abgleich (wie TIX_Brevo): Newsletter-Anmeldungen landen in der Zielgruppe, je Event bzw. Ticketart mit Tags;
 * Zeitpunkt immediate (Kauf-Häkchen) oder after_confirm (nach dem Double-Opt-in). Gespeichert in `mc_sync` je Bereich,
 * REST über /marketing/newsletter/sync (TIX_Brevo verzweigt hierher, wenn der Bereich Mailchimp nutzt).
 */
if (!defined('ABSPATH')) exit;

class TIX_Newsletter_Mailchimp {

    const KEY_RE = '/^[0-9a-f]{32}-[a-z]{2,4}[0-9]{1,3}$/';
    const CHUNK = 500;
    const CHUNKS_PER_RUN = 10;
    const MAX_MAPPINGS = 50;

    public static function init() {
        add_action('tix_newsletter_optin', [__CLASS__, 'on_optin'], 11, 4);
        add_action('tix_nl_confirmed', [__CLASS__, 'on_confirmed'], 11, 4);
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }

    // ═══════════════ API ═══════════════

    public static function dc($key) { return substr((string) strrchr((string) $key, '-'), 1); }

    /** Aufruf der Mailchimp-API → [code, array] oder WP_Error */
    public static function api($key, $method, $path, $body = null) {
        if (!preg_match(self::KEY_RE, (string) $key)) return new WP_Error('tix_mc_key', 'Kein gültiger Mailchimp-Schlüssel.', ['status' => 400, 'field' => 'mc_api_key']);
        $args = ['method' => $method, 'timeout' => 25, 'headers' => ['Authorization' => 'Basic ' . base64_encode('evendis:' . $key), 'Accept' => 'application/json', 'Content-Type' => 'application/json']];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $r = wp_remote_request('https://' . self::dc($key) . '.api.mailchimp.com/3.0' . $path, $args);
        if (is_wp_error($r)) return new WP_Error('tix_mc_down', 'Mailchimp ist gerade nicht erreichbar.', ['status' => 502]);
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        return [intval(wp_remote_retrieve_response_code($r)), is_array($j) ? $j : []];
    }

    public static function err($r, $fallback) {
        if (is_wp_error($r)) return $r->get_error_message();
        return mb_substr((string) ($r[1]['detail'] ?? $r[1]['title'] ?? $fallback), 0, 200);
    }

    private static function ok($r, array $codes = [200]) { return !is_wp_error($r) && in_array($r[0], $codes, true); }

    private static function hash($email) { return md5(strtolower(trim((string) $email))); }

    // ═══════════════ Einstellungen ═══════════════

    /** Versandbereit? → true oder WP_Error (409, mit Feld) */
    public static function ready_settings(array $s) {
        if ($s['mc_key'] === '') return self::bad('mc_api_key', 'Bitte zuerst Mailchimp verbinden.', 'tix_nl_not_connected', 409);
        if ($s['mc_audience_id'] === '') return self::bad('mc_audience_id', 'Bitte eine Mailchimp-Zielgruppe wählen.', 'tix_nl_no_audience', 409);
        if ($s['mc_from_name'] === '' || !is_email($s['mc_reply_to'])) return self::bad('mc_reply_to', 'Bitte Absendername und Antwort-Adresse angeben.', 'tix_nl_no_sender', 409);
        return true;
    }

    /** Block `mailchimp` in GET /marketing/newsletter (Schlüssel nie, Konto und Zielgruppen 10 min zwischengespeichert) */
    public static function status($org, array $s, $fresh = false) {
        $o = ['connected' => $s['mc_key'] !== '', 'account' => null, 'audience' => $s['mc_audience_id'] !== '' ? ['id' => $s['mc_audience_id'], 'name' => $s['mc_audience_name']] : null,
            'audiences' => [], 'from_name' => $s['mc_from_name'] ?: null, 'reply_to' => $s['mc_reply_to'] ?: null, 'webhook' => $s['mc_webhook_id'] !== '',
            'ready' => self::ready_settings($s) === true, 'datacenter' => $s['mc_key'] !== '' ? self::dc($s['mc_key']) : null];
        if ($s['mc_key'] === '') return $o;
        $ck = 'tix_nl_mc_' . md5(intval($org) . '|' . $s['mc_key']);
        $c = $fresh ? false : get_transient($ck);
        if (!is_array($c)) {
            $a = self::api($s['mc_key'], 'GET', '/?fields=account_name,email');
            if (!self::ok($a)) { $o['error'] = self::err($a, 'Mailchimp nimmt den Schlüssel nicht an.'); return $o; }
            $l = self::api($s['mc_key'], 'GET', '/lists?count=100&fields=lists.id,lists.name,lists.stats.member_count');
            $aud = [];
            if (self::ok($l)) foreach ((array) ($l[1]['lists'] ?? []) as $x) $aud[] = ['id' => (string) $x['id'], 'name' => (string) ($x['name'] ?? ''), 'count' => intval($x['stats']['member_count'] ?? 0)];
            $c = ['account' => ['name' => (string) ($a[1]['account_name'] ?? ''), 'email' => (string) ($a[1]['email'] ?? '')], 'audiences' => $aud];
            set_transient($ck, $c, 10 * MINUTE_IN_SECONDS);
        }
        return array_merge($o, $c);
    }

    public static function flush($org, array $s) { if ($s['mc_key'] !== '') delete_transient('tix_nl_mc_' . md5(intval($org) . '|' . $s['mc_key'])); }

    /**
     * Teil von POST /marketing/newsletter/settings: {provider?, mc_api_key?, mc_audience_id?, mc_from_name?, mc_reply_to?}
     * → true oder WP_Error. Der Schlüssel wird geprüft (/ping), verschlüsselt gespeichert und nie ausgegeben.
     */
    public static function save($org, WP_REST_Request $req) {
        global $wpdb;
        $s = TIX_Newsletter::settings($org);
        if ($req->has_param('provider')) {
            $p = (string) $req->get_param('provider');
            if (!in_array($p, ['brevo', 'mailchimp'], true)) return self::bad('provider', 'Bitte Brevo oder Mailchimp wählen.');
            if ($p !== $s['provider']) {
                TIX_Newsletter::maybe_create();
                $busy = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Newsletter::tk() . " WHERE organizer_id = %d AND status IN ('scheduled','preparing')", intval($org))));
                if ($busy) return self::bad('provider', 'Erst geplante Kampagnen versenden oder zurückholen, dann den Anbieter wechseln.', 'tix_nl_busy', 409);
                TIX_Newsletter::save_settings($org, ['provider' => $p]);
            }
        }
        if ($req->has_param('mc_api_key')) {
            $key = trim((string) $req->get_param('mc_api_key'));
            if ($key !== '') {
                if (!preg_match(self::KEY_RE, $key)) return self::bad('mc_api_key', 'Der Mailchimp-Schlüssel endet auf „-us…“ (Mailchimp → Profil → Extras → API-Schlüssel).');
                $r = self::api($key, 'GET', '/ping');
                if (is_wp_error($r) && $r->get_error_code() === 'tix_mc_down') return $r;
                if (!self::ok($r)) return self::bad('mc_api_key', 'Mailchimp nimmt diesen Schlüssel nicht an.', 'tix_mc_key');
            }
            self::drop_webhook($s);
            self::flush($org, $s);
            TIX_Newsletter::save_settings($org, ['mc_key' => $key, 'mc_audience_id' => '', 'mc_audience_name' => '', 'mc_webhook_id' => '']);
            $s = TIX_Newsletter::settings($org);
        }
        if ($req->has_param('mc_audience_id')) {
            $aid = trim((string) $req->get_param('mc_audience_id'));
            if ($aid !== '') {
                if ($s['mc_key'] === '') return self::bad('mc_api_key', 'Bitte zuerst den Mailchimp-Schlüssel eintragen.');
                if (!preg_match('/^[a-z0-9]{4,20}$/i', $aid)) return self::bad('mc_audience_id', 'Diese Zielgruppe gibt es in Mailchimp nicht.');
                $r = self::api($s['mc_key'], 'GET', '/lists/' . rawurlencode($aid) . '?fields=id,name');
                if (!self::ok($r)) return self::bad('mc_audience_id', 'Diese Zielgruppe gibt es in Mailchimp nicht.');
                $name = (string) ($r[1]['name'] ?? '');
            } else $name = '';
            if ($aid !== $s['mc_audience_id']) { self::drop_webhook($s); TIX_Newsletter::save_settings($org, ['mc_audience_id' => $aid, 'mc_audience_name' => $name, 'mc_webhook_id' => '']); }
        }
        if ($req->has_param('mc_from_name')) TIX_Newsletter::save_settings($org, ['mc_from_name' => mb_substr(trim(wp_strip_all_tags((string) $req->get_param('mc_from_name'))), 0, 100)]);
        if ($req->has_param('mc_reply_to')) {
            $rt = strtolower(trim((string) $req->get_param('mc_reply_to')));
            if ($rt !== '' && !is_email($rt)) return self::bad('mc_reply_to', 'Bitte eine gültige Antwort-Adresse angeben.');
            TIX_Newsletter::save_settings($org, ['mc_reply_to' => $rt]);
        }
        $s = TIX_Newsletter::settings($org);
        if ($s['mc_key'] !== '' && $s['mc_audience_id'] !== '' && $s['mc_webhook_id'] === '') self::ensure_webhook($org, $s);
        self::flush($org, $s);
        return true;
    }

    /** Mailchimp meldet Abmeldungen und Bounces (cleaned) an /public/nl/hook/<geheim> (gemeinsames Geheimnis mit Brevo) */
    private static function ensure_webhook($org, array $s) {
        $secret = $s['hook_secret'] !== '' ? $s['hook_secret'] : wp_generate_password(32, false, false);
        if ($s['hook_secret'] === '') TIX_Newsletter::save_settings($org, ['hook_secret' => $secret]);
        $r = self::api($s['mc_key'], 'POST', '/lists/' . rawurlencode($s['mc_audience_id']) . '/webhooks', ['url' => rest_url(TIX_Newsletter::NS . '/public/nl/hook/' . $secret),
            'events' => ['subscribe' => false, 'unsubscribe' => true, 'profile' => false, 'cleaned' => true, 'upemail' => false, 'campaign' => false],
            'sources' => ['user' => true, 'admin' => true, 'api' => false]]);
        if (self::ok($r)) TIX_Newsletter::save_settings($org, ['mc_webhook_id' => (string) ($r[1]['id'] ?? '')]);
    }

    private static function drop_webhook(array $s) {
        if ($s['mc_key'] !== '' && $s['mc_audience_id'] !== '' && $s['mc_webhook_id'] !== '') self::api($s['mc_key'], 'DELETE', '/lists/' . rawurlencode($s['mc_audience_id']) . '/webhooks/' . rawurlencode($s['mc_webhook_id']));
    }

    // ═══════════════ Kampagnen ═══════════════

    /** Mail für Mailchimp: Abmeldelink und Vorname als Merge-Tags */
    public static function html(array $c) {
        $h = str_replace('{{ unsubscribe }}', '*|UNSUB|*', TIX_Newsletter::html($c));
        return preg_replace('/\{\{\s*contact\.FIRSTNAME\s*\|\s*default:\s*"([^"]*)"\s*\}\}/', '*|IF:FNAME|**|FNAME|**|ELSE:|*$1*|END:IF|*', $h);
    }

    /** Mailchimp-Kampagne anlegen bzw. aktualisieren (Inhalt immer neu) → Kampagnen-ID oder WP_Error */
    private static function push(array $c, array $s, $segment = 0) {
        $m = TIX_Newsletter::meta_of($c);
        $rcp = ['list_id' => $s['mc_audience_id']] + ($segment ? ['segment_opts' => ['saved_segment_id' => intval($segment)]] : []);
        $body = ['recipients' => $rcp, 'settings' => ['subject_line' => (string) $c['subject'], 'preview_text' => (string) $c['preheader'], 'title' => 'evendis · ' . $c['name'] . ' (#' . $c['id'] . ')',
            'from_name' => $s['mc_from_name'], 'reply_to' => $s['mc_reply_to'], 'auto_footer' => false, 'inline_css' => false]];
        $id = (string) ($m['mc_campaign'] ?? '');
        if ($id !== '') {
            $r = self::api($s['mc_key'], 'PATCH', '/campaigns/' . rawurlencode($id), $body);
            if (!self::ok($r)) { if (!is_wp_error($r) && $r[0] === 404) $id = ''; else return new WP_Error('tix_mc_error', self::err($r, 'Mailchimp lehnt die Kampagne ab.'), ['status' => 502]); }
        }
        if ($id === '') {
            $r = self::api($s['mc_key'], 'POST', '/campaigns', ['type' => 'regular'] + $body);
            if (!self::ok($r) || empty($r[1]['id'])) return new WP_Error('tix_mc_error', self::err($r, 'Mailchimp lehnt die Kampagne ab.'), ['status' => 502]);
            $id = (string) $r[1]['id'];
            $m['mc_campaign'] = $id;
            TIX_Newsletter::set_meta(intval($c['id']), $m);
        }
        $r = self::api($s['mc_key'], 'PUT', '/campaigns/' . rawurlencode($id) . '/content', ['html' => self::html($c)]);
        if (!self::ok($r)) return new WP_Error('tix_mc_error', self::err($r, 'Mailchimp nimmt den Inhalt nicht an.'), ['status' => 502]);
        return $id;
    }

    public static function test(array $c, array $s, array $emails) {
        $id = self::push($c, $s);
        if (is_wp_error($id)) return $id;
        $r = self::api($s['mc_key'], 'POST', '/campaigns/' . rawurlencode($id) . '/actions/test', ['test_emails' => $emails, 'send_type' => 'html']);
        if (!self::ok($r, [200, 204])) return new WP_Error('tix_mc_error', self::err($r, 'Der Testversand hat nicht geklappt.'), ['status' => 502]);
        return true;
    }

    private static function rcp_key($id) { return 'tix_nl_mc_rcp_' . intval($id); }

    /**
     * Versand Schritt für Schritt (Cron alle 5 min bzw. „Jetzt senden“):
     * scheduled (≤ 15 min vor dem Zeitpunkt) → Empfänger festhalten, Segment anlegen (preparing)
     * preparing → je Lauf bis 5.000 Empfänger in Zielgruppe + Segment; fertig und Zeitpunkt erreicht → senden (sent)
     */
    public static function advance(array $c, array $s) {
        global $wpdb;
        if (($ok = self::ready_settings($s)) !== true) return TIX_Newsletter::fail($c, 'Mailchimp ist nicht mehr verbunden.');
        $org = intval($c['organizer_id']);
        $ts = strtotime($c['scheduled_at'] . ' UTC');
        $aud = rawurlencode($s['mc_audience_id']);
        if ($c['status'] === 'scheduled') {
            if ($ts > time() + 15 * MINUTE_IN_SECONDS) return;
            $rcp = TIX_Newsletter::recipients($org, $c['audience'], intval($c['event_id']));
            if (!$rcp) return TIX_Newsletter::fail($c, 'In der Zielgruppe ist niemand mehr mit bestätigter Anmeldung.');
            ksort($rcp);
            set_transient(self::rcp_key($c['id']), $rcp, 3 * DAY_IN_SECONDS);
            $r = self::api($s['mc_key'], 'POST', '/lists/' . $aud . '/segments', ['name' => 'evendis · Kampagne #' . $c['id'] . ' · ' . wp_date('d.m.Y H:i'), 'static_segment' => []]);
            if (!self::ok($r) || empty($r[1]['id'])) return is_wp_error($r) && $r->get_error_code() === 'tix_mc_down' ? null : TIX_Newsletter::fail($c, self::err($r, 'Das Mailchimp-Segment ließ sich nicht anlegen.'));
            $m = TIX_Newsletter::meta_of($c);
            $m['provider'] = 'mailchimp'; $m['mc_segment'] = intval($r[1]['id']); $m['mc_done'] = 0;
            $wpdb->update(TIX_Newsletter::tk(), ['status' => 'preparing', 'recipients' => count($rcp), 'audience_meta' => wp_json_encode($m), 'updated' => current_time('mysql', true)], ['id' => intval($c['id'])]);
            $c = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", intval($c['id'])), ARRAY_A);
        }
        if ($c['status'] !== 'preparing') return;
        $m = TIX_Newsletter::meta_of($c);
        $rcp = get_transient(self::rcp_key($c['id']));
        if (!is_array($rcp)) return TIX_Newsletter::fail($c, 'Die Empfängerliste ist verloren gegangen. Bitte die Kampagne zurückholen und neu planen.');
        $all = array_keys($rcp);
        $done = intval($m['mc_done'] ?? 0);
        for ($i = 0; $i < self::CHUNKS_PER_RUN && $done < count($all); $i++) {
            $chunk = array_slice($all, $done, self::CHUNK);
            $members = array_map(function ($em) use ($rcp) { return ['email_address' => $em, 'status_if_new' => 'subscribed'] + ($rcp[$em] !== '' ? ['merge_fields' => ['FNAME' => $rcp[$em]]] : []); }, $chunk);
            $r = self::api($s['mc_key'], 'POST', '/lists/' . $aud, ['members' => $members, 'update_existing' => true]);
            if (!self::ok($r)) break; // beim nächsten Lauf weiter
            $r = self::api($s['mc_key'], 'POST', '/lists/' . $aud . '/segments/' . intval($m['mc_segment']), ['members_to_add' => $chunk]);
            if (!self::ok($r)) break;
            $done += count($chunk);
        }
        $m['mc_done'] = $done;
        TIX_Newsletter::set_meta(intval($c['id']), $m);
        if ($done < count($all) || $ts > time() + 60) return;
        $c = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", intval($c['id'])), ARRAY_A);
        $id = self::push($c, $s, intval($m['mc_segment']));
        if (is_wp_error($id)) return TIX_Newsletter::fail($c, $id->get_error_message());
        $r = self::api($s['mc_key'], 'POST', '/campaigns/' . rawurlencode($id) . '/actions/send');
        if (!self::ok($r, [200, 204])) return TIX_Newsletter::fail($c, self::err($r, 'Mailchimp hat den Versand abgelehnt.'));
        delete_transient(self::rcp_key($c['id']));
        $wpdb->update(TIX_Newsletter::tk(), ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'), 'updated' => current_time('mysql', true)], ['id' => intval($c['id'])]);
    }

    /** Zurückholen (vor dem Versand): Segment löschen, Merker weg */
    public static function cancel(array $c, array $s) {
        $m = TIX_Newsletter::meta_of($c);
        if (!empty($m['mc_segment']) && $s['mc_key'] !== '' && $s['mc_audience_id'] !== '') self::api($s['mc_key'], 'DELETE', '/lists/' . rawurlencode($s['mc_audience_id']) . '/segments/' . intval($m['mc_segment']));
        unset($m['mc_segment'], $m['mc_done'], $m['provider']);
        delete_transient(self::rcp_key($c['id']));
        TIX_Newsletter::set_meta(intval($c['id']), $m);
    }

    public static function delete(array $c, array $s) {
        $m = TIX_Newsletter::meta_of($c);
        if (!empty($m['mc_campaign']) && $s['mc_key'] !== '') self::api($s['mc_key'], 'DELETE', '/campaigns/' . rawurlencode((string) $m['mc_campaign']));
        self::cancel($c, $s);
    }

    /** Kennzahlen (Mailchimp-Bericht) im Format der Brevo-Kennzahlen */
    public static function stats(array $c, array $s) {
        $m = TIX_Newsletter::meta_of($c);
        if (empty($m['mc_campaign']) || $s['mc_key'] === '') return null;
        $r = self::api($s['mc_key'], 'GET', '/reports/' . rawurlencode((string) $m['mc_campaign']));
        if (!self::ok($r)) return null;
        $g = $r[1];
        $bounces = intval($g['bounces']['hard_bounces'] ?? 0) + intval($g['bounces']['soft_bounces'] ?? 0) + intval($g['bounces']['syntax_errors'] ?? 0);
        $sent = intval($g['emails_sent'] ?? 0);
        $st = ['sent' => $sent, 'delivered' => max(0, $sent - $bounces), 'opens' => intval($g['opens']['unique_opens'] ?? 0), 'clicks' => intval($g['clicks']['unique_subscriber_clicks'] ?? 0),
            'unsubscribed' => intval($g['unsubscribed'] ?? 0), 'bounces' => $bounces, 'complaints' => intval($g['abuse_reports'] ?? 0)];
        $st['open_rate'] = $st['delivered'] ? round($st['opens'] / $st['delivered'] * 100, 1) : null;
        $st['click_rate'] = $st['delivered'] ? round($st['clicks'] / $st['delivered'] * 100, 1) : null;
        return $st;
    }

    /** Täglich: Abmeldungen/Bounces aus Mailchimp übernehmen, Segmente 30 Tage nach dem Versand löschen */
    public static function daily($org, array $s) {
        global $wpdb;
        if ($s['mc_key'] === '' || $s['mc_audience_id'] === '') return;
        $since = rawurlencode(gmdate('c', time() - 2 * DAY_IN_SECONDS));
        foreach (['unsubscribed' => 'mailchimp', 'cleaned' => 'bounce'] as $status => $reason) {
            $r = self::api($s['mc_key'], 'GET', '/lists/' . rawurlencode($s['mc_audience_id']) . '/members?status=' . $status . '&since_last_changed=' . $since . '&count=1000&fields=members.email_address');
            if (self::ok($r)) foreach ((array) ($r[1]['members'] ?? []) as $x) TIX_Newsletter::unsubscribe_email($org, (string) ($x['email_address'] ?? ''), $reason);
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE organizer_id = %d AND status = 'sent' AND audience_meta LIKE %s AND sent_at < %s", intval($org), '%"mc_segment"%', gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)), ARRAY_A) as $k) {
            $m = TIX_Newsletter::meta_of($k);
            if (!empty($m['mc_segment'])) self::api($s['mc_key'], 'DELETE', '/lists/' . rawurlencode($s['mc_audience_id']) . '/segments/' . intval($m['mc_segment']));
            unset($m['mc_segment'], $m['mc_done']);
            TIX_Newsletter::set_meta(intval($k['id']), $m);
        }
    }

    // ═══════════════ Listen-Abgleich ═══════════════

    private static function sync_cfg(array $s) {
        $v = $s['mc_sync'];
        return ['enabled' => !empty($v['enabled']), 'mappings' => array_values(array_filter((array) ($v['mappings'] ?? []), 'is_array')), 'default_tag' => (string) ($v['default_tag'] ?? ''),
            'timing' => ($v['timing'] ?? '') === 'after_confirm' ? 'after_confirm' : 'immediate'];
    }

    public static function sync_payload($org) {
        $s = TIX_Newsletter::settings($org);
        $cfg = self::sync_cfg($s);
        $maps = array_map(function ($m) {
            $eid = intval($m['event_id'] ?? 0);
            return ['event_id' => $eid ?: null, 'event_title' => $eid ? html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') : null, 'cat_name' => (string) ($m['cat_name'] ?? ''), 'tag' => (string) ($m['tag'] ?? '')];
        }, $cfg['mappings']);
        $connected = $s['mc_key'] !== '';
        return ['ok' => true, 'provider' => 'mailchimp', 'providers' => ['brevo', 'mailchimp'], 'connected' => $connected, 'enabled' => $cfg['enabled'],
            'active' => $cfg['enabled'] && $connected && $s['mc_audience_id'] !== '', 'audience' => $s['mc_audience_id'] !== '' ? ['id' => $s['mc_audience_id'], 'name' => $s['mc_audience_name']] : null,
            'list_id' => $s['mc_audience_id'] ?: null, 'mappings' => $maps, 'default_tag' => $cfg['default_tag'] ?: null, 'max_mappings' => self::MAX_MAPPINGS, 'timing' => $cfg['timing']];
    }

    /** POST /marketing/newsletter/sync bei Mailchimp: {enabled?, mappings?[{event_id?, cat_name?, tag}], default_tag?, timing?} */
    public static function sync_save($org, WP_REST_Request $req) {
        $s = TIX_Newsletter::settings($org);
        $cfg = self::sync_cfg($s);
        if ($req->has_param('mappings')) {
            $in = $req->get_param('mappings');
            if (!is_array($in) || count($in) > self::MAX_MAPPINGS) return self::bad('mappings', 'Höchstens ' . self::MAX_MAPPINGS . ' Regeln.');
            $scope = class_exists('TIX_App_Scope') && TIX_App_Scope::is_admin() ? null : (class_exists('TIX_Studio_Stats') ? array_map('intval', TIX_Studio_Stats::scope_ids()) : null);
            $clean = [];
            foreach (array_values($in) as $i => $m) {
                $eid = intval($m['event_id'] ?? 0);
                $tag = mb_substr(trim(sanitize_text_field((string) ($m['tag'] ?? ''))), 0, 100);
                if ($eid && (get_post_type($eid) !== 'event' || ($scope !== null && !in_array($eid, $scope, true)))) return self::bad("mappings.$i.event_id", 'Event nicht gefunden.');
                if ($tag === '') return self::bad("mappings.$i.tag", 'Bitte einen Tag für Mailchimp angeben.');
                $clean[] = ['event_id' => $eid, 'cat_name' => mb_substr(sanitize_text_field((string) ($m['cat_name'] ?? '')), 0, 120), 'tag' => $tag];
            }
            $cfg['mappings'] = $clean;
        }
        if ($req->has_param('default_tag')) $cfg['default_tag'] = mb_substr(trim(sanitize_text_field((string) $req->get_param('default_tag'))), 0, 100);
        if ($req->has_param('timing')) {
            $t = (string) $req->get_param('timing');
            if (!in_array($t, ['immediate', 'after_confirm'], true)) return self::bad('timing', 'immediate oder after_confirm.');
            $cfg['timing'] = $t;
        }
        if ($req->has_param('enabled')) {
            $on = filter_var($req->get_param('enabled'), FILTER_VALIDATE_BOOLEAN);
            if ($on && $s['mc_key'] === '') return self::bad('enabled', 'Bitte zuerst Mailchimp verbinden.');
            if ($on && $s['mc_audience_id'] === '') return self::bad('list_id', 'Bitte zuerst eine Mailchimp-Zielgruppe wählen.');
            $cfg['enabled'] = $on;
        }
        TIX_Newsletter::save_settings($org, ['mc_sync' => $cfg]);
        $r = rest_ensure_response(self::sync_payload($org));
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** Tags einer Bestellung für einen Bereich (Regeln event_id/cat_name; nur Events des Bereichs) */
    public static function tags_for_order($org, $order_id, array $cfg) {
        $tags = $cfg['default_tag'] !== '' ? [$cfg['default_tag']] : [];
        if ($order_id && $cfg['mappings'] && class_exists('TIX_Order')) {
            global $wpdb;
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT event_id, cat_name FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d", intval($order_id))) as $it) {
                $ev = intval($it->event_id);
                if ($org && intval(get_post_meta($ev, '_tix_organizer_id', true)) !== intval($org)) continue;
                foreach ($cfg['mappings'] as $m) {
                    $me = intval($m['event_id'] ?? 0); $mc = trim((string) ($m['cat_name'] ?? ''));
                    if (($me === 0 || $me === $ev) && ($mc === '' || mb_strtolower($mc) === mb_strtolower(trim((string) $it->cat_name)))) $tags[] = (string) $m['tag'];
                }
            }
        }
        return array_values(array_unique($tags));
    }

    /** Kontakt in die Zielgruppe (neu = subscribed, bestehende Abmeldung bleibt) und Tags setzen */
    public static function push_member(array $s, $email, $first, array $tags) {
        $email = strtolower(trim(sanitize_email((string) $email)));
        if (!is_email($email) || $s['mc_key'] === '' || $s['mc_audience_id'] === '') return false;
        $path = '/lists/' . rawurlencode($s['mc_audience_id']) . '/members/' . self::hash($email);
        $r = self::api($s['mc_key'], 'PUT', $path, ['email_address' => $email, 'status_if_new' => 'subscribed'] + ((string) $first !== '' ? ['merge_fields' => ['FNAME' => mb_substr((string) $first, 0, 60)]] : []));
        if (!self::ok($r)) return false;
        if ($tags) self::api($s['mc_key'], 'POST', $path . '/tags', ['tags' => array_map(function ($t) { return ['name' => $t, 'status' => 'active']; }, $tags)]);
        return true;
    }

    private static function sync_active(array $s, $timing) {
        if ($s['provider'] !== 'mailchimp' || $s['mc_key'] === '' || $s['mc_audience_id'] === '') return null;
        $cfg = self::sync_cfg($s);
        return $cfg['enabled'] && $cfg['timing'] === $timing ? $cfg : null;
    }

    /** Kauf-Häkchen (native Kasse): je Bereich der gekauften Events, Zeitpunkt „sofort“ */
    public static function on_optin($order_id, $email, $first, $last) {
        $orgs = [0];
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && class_exists('TIX_Order')) {
            global $wpdb;
            $orgs = [];
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT event_id FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d", intval($order_id))) as $e) { $o = intval(get_post_meta(intval($e), '_tix_organizer_id', true)); if ($o) $orgs[$o] = $o; }
        }
        foreach ($orgs as $o) {
            $s = TIX_Newsletter::settings($o);
            if (!($cfg = self::sync_active($s, 'immediate'))) continue;
            self::push_member($s, $email, $first, self::tags_for_order($o, intval($order_id), $cfg));
        }
    }

    /** Bestätigte Anmeldung (Double-Opt-in), Zeitpunkt „nach Bestätigung“ */
    public static function on_confirmed($org, $email, $first, $order_id) {
        $s = TIX_Newsletter::settings(intval($org));
        if (!($cfg = self::sync_active($s, 'after_confirm'))) return;
        self::push_member($s, $email, $first, self::tags_for_order(intval($org), intval($order_id), $cfg));
    }
}

TIX_Newsletter_Mailchimp::init();
