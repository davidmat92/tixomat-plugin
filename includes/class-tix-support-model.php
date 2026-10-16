<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Support_Model – Support-Modell Phase 1 (Plan tixomat-apps/docs/plan-support-modell.md, 1.38.451).
 *
 * Support-Modus (nur Admin bzw. evendis setzt ihn):
 *   Seite: Option `tix_support_mode` = evendis | self | evendis_package (Vorgabe: Plattform evendis, sonst self)
 *   Plattform je Veranstalter: Meta `_tix_support_mode` (Partner-Veranstalter Vorgabe self, eigene Veranstalter evendis)
 * Zuständigkeit je Anfrage (Ort des Kaufs; gespeichert beim Anlegen): `_tix_sp_responsible` organizer|evendis,
 *   `_tix_sp_resp_org`, `_tix_sp_resp_partner`. Geteilte Events (auf evendis verkauft): der Veranstalter (Partner), außer Paket.
 * Partner-Brücke (geteilte Events): Die Anfrage bleibt auf evendis die Kunden-Anfrage (Mail, App). Die Partner-Seite bekommt eine
 *   Kopie (`_tix_sp_origin` = platform) in ihr Postfach; Antworten des Partner-Teams gehen an evendis zurück und von dort an den
 *   Kunden; neue Kunden-Nachrichten gehen an die Kopie. Kein Versand an den Kunden von der Partner-Seite aus.
 *     evendis → Partner: POST {api_base}/partner/support/inbound, POST {api_base}/partner/support/{id}/messages  (X-Tix-Partner-Id/-Key)
 *     Partner → evendis: POST {syndication_api_url}/syndicate/support/{id}/reply bzw. /status            (X-Tix-Syndication-Key)
 * REST (Studio-Postfach): /support/inbox[/{id}[/reply|/status|/assign|/note|/inquiry]], /support/templates[/{id}],
 *   /support/inquiries[/{ticket}/{inquiry}/answer], /support/mode (Admin).  /support/config → responsible.
 *   1.38.452: /support/inbox/counts, /support/inbox/{id}/read, /support/notify; Zeile event/order/waiting/unread, Detail customer; nur Inhaber + Team-Admins.
 */
class TIX_Support_Model {

    const NS = 'tixomat/v1';
    const CPT = 'tix_support_ticket';
    const MODES = ['evendis', 'self', 'evendis_package'];
    const STATUS_MAP = ['open' => 'tix_open', 'progress' => 'tix_progress', 'resolved' => 'tix_resolved', 'closed' => 'tix_closed'];
    const CRON_FORWARD = 'tix_support_forward';
    const CRON_REMIND  = 'tix_support_inquiry_remind';
    const CRON_FLUSH   = 'tix_support_bridge_flush';

    /** Neu angelegte Anfragen dieser Anfrage (Verarbeitung am Ende, wenn alle Metas stehen) */
    private static $new = [];
    /** Nachrichten, die gerade über die Brücke hereinkommen (nicht zurückschicken) */
    private static $bridge = false;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('wp_insert_post', [__CLASS__, 'on_insert'], 20, 3);
        add_action('shutdown', [__CLASS__, 'process_new'], 1);
        add_action('tix_support_message_added', [__CLASS__, 'on_message'], 10, 2);
        add_action('tix_support_status_changed', [__CLASS__, 'on_status'], 10, 3);
        add_action(self::CRON_FORWARD, [__CLASS__, 'forward'], 10, 1);
        add_action(self::CRON_REMIND, [__CLASS__, 'remind']);
        add_action(self::CRON_FLUSH, [__CLASS__, 'flush'], 10, 1);
        add_action('added_post_meta', [__CLASS__, 'on_meta'], 10, 3);
        add_action('updated_post_meta', [__CLASS__, 'on_meta'], 10, 3);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Support_Model::CRON_REMIND)) wp_schedule_event(time() + 600, 'hourly', TIX_Support_Model::CRON_REMIND);
        }, 29);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function bad($field, $msg, $status = 400) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => $status, 'field' => $field]);
    }

    // ═══════════════ Modus + Zuständigkeit ═══════════════

    public static function site_mode() {
        $m = (string) get_option('tix_support_mode', '');
        return in_array($m, self::MODES, true) ? $m : (self::multi() ? 'evendis' : 'self');
    }

    /** Partner-Eintrag (Partner-Verzeichnis) eines Veranstalters auf der Plattform oder null. */
    private static function partner_of_org($oid) {
        if (!$oid || !class_exists('TIX_Partners')) return null;
        foreach (TIX_Partners::all() as $p) if (intval($p['organizer_id']) === intval($oid)) return $p;
        return null;
    }

    public static function org_mode($oid) {
        $m = (string) get_post_meta(intval($oid), '_tix_support_mode', true);
        if (in_array($m, self::MODES, true)) return $m;
        return self::partner_of_org($oid) ? 'self' : 'evendis';
    }

    /** Events einer Anfrage (Bestellung bzw. Event-Bezug). */
    private static function ticket_events($tid) {
        $ids = [];
        $eid = intval(get_post_meta($tid, '_tix_sp_event_id', true));
        if ($eid) $ids[] = $eid;
        $oid = intval(get_post_meta($tid, '_tix_sp_order_id', true));
        if ($oid && class_exists('TIX_Order') && ($o = TIX_Order::get($oid))) {
            foreach ($o->get_items() as $it) if ($it->get_event_id()) $ids[] = intval($it->get_event_id());
        }
        $code = (string) get_post_meta($tid, '_tix_sp_ticket_code', true);
        if (!$ids && $code !== '' && class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'get_ticket_by_code') && ($t = TIX_Tickets::get_ticket_by_code($code))) {
            $e = intval(get_post_meta(is_object($t) ? $t->ID : intval($t), '_tix_ticket_event_id', true));
            if ($e) $ids[] = $e;
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /** Zuständigkeit ermitteln: ['type' => organizer|evendis, 'org' => id, 'partner' => id|''] */
    public static function compute($tid) {
        if (!self::multi()) {
            $mode = self::site_mode();
            return ['type' => $mode === 'self' ? 'organizer' : 'evendis', 'org' => 0, 'partner' => ''];
        }
        $org = 0;
        foreach (self::ticket_events($tid) as $eid) {
            $o = intval(get_post_meta($eid, '_tix_organizer_id', true));
            if (!$org && $o) $org = $o;
            if (get_post_meta($eid, '_tix_syndicated', true) === '1') {
                $pid = (string) get_post_meta($eid, '_tix_source_partner', true);
                $p = $pid !== '' && class_exists('TIX_Partners') ? TIX_Partners::get($pid) : null;
                $porg = $p ? intval($p['organizer_id']) : $o;
                $mode = $porg ? self::org_mode($porg) : 'self';
                return ['type' => $mode === 'evendis_package' || $mode === 'evendis' ? 'evendis' : 'organizer', 'org' => $porg, 'partner' => $p ? (string) $p['id'] : ''];
            }
        }
        // Eigene Plattform-Events bzw. ohne Bestellung: evendis (Veranstalter bleibt für Übersicht und Rückfragen)
        if (!$org) $org = intval(get_post_meta($tid, '_tix_sp_organizer_id', true));
        return ['type' => 'evendis', 'org' => $org, 'partner' => ''];
    }

    /** Gespeicherte Zuständigkeit (ältere Anfragen: einmal ermitteln). */
    public static function responsible($tid) {
        $t = (string) get_post_meta($tid, '_tix_sp_responsible', true);
        if ($t === '') {
            $r = self::compute($tid);
            update_post_meta($tid, '_tix_sp_responsible', $r['type']);
            update_post_meta($tid, '_tix_sp_resp_org', $r['org']);
            update_post_meta($tid, '_tix_sp_resp_partner', $r['partner']);
            return $r;
        }
        return ['type' => $t, 'org' => intval(get_post_meta($tid, '_tix_sp_resp_org', true)), 'partner' => (string) get_post_meta($tid, '_tix_sp_resp_partner', true)];
    }

    /** Kopie einer evendis-Anfrage auf der Partner-Seite? (Antworten gehen an evendis, nicht an den Kunden) */
    public static function is_platform_copy($tid) {
        return get_post_meta(intval($tid), '_tix_sp_origin', true) === 'platform';
    }

    public static function on_insert($post_id, $post, $update) {
        if ($update || !$post || $post->post_type !== self::CPT) return;
        self::$new[] = intval($post_id);
    }

    /** Ende der Anfrage: Zuständigkeit speichern, Team benachrichtigen, ggf. an den Partner weiterleiten. */
    public static function process_new() {
        $ids = array_unique(self::$new);
        self::$new = [];
        foreach ($ids as $tid) {
            if (get_post_type($tid) !== self::CPT) continue;
            delete_post_meta($tid, '_tix_sp_responsible');
            $r = self::responsible($tid);
            if ((string) get_post_meta($tid, '_tix_sp_category', true) === 'awareness') continue; // eigener Ablauf (TIX_Awareness_Live)
            if ($r['partner'] !== '' && $r['type'] === 'organizer' && self::multi()) self::forward($tid);
            self::notify_team_new($tid, $r);
        }
    }

    /** Team der zuständigen Stelle informieren: App-Feed + Push, Mail an die Ausgewählten (Inhalt nur Betreff). */
    private static function notify_team_new($tid, array $r) {
        if (!self::multi()) {
            if (self::site_mode() !== 'self' && !self::is_platform_copy($tid)) return;
        } elseif ($r['type'] !== 'evendis') {
            return; // Partner-Anfragen benachrichtigt die Partner-Seite selbst
        }
        $subject = (string) get_the_title($tid);
        $admin_mail = strtolower((string) get_option('admin_email'));
        foreach (self::notify_ids(0) as $uid) {
            if (class_exists('TIX_Notifications')) {
                try { TIX_Notifications::add_user_item($uid, 'Neue Support-Anfrage', '#' . $tid . ' · ' . $subject, ['type' => 'support_team', 'action' => 'support:inbox:' . $tid]); } catch (\Throwable $e) {}
            }
            $u = get_userdata($uid);
            // admin_email bekommt die Hinweis-Mail schon von TIX_Support
            if ($u && is_email($u->user_email) && strtolower($u->user_email) !== $admin_mail) {
                wp_mail($u->user_email, 'Neue Support-Anfrage #' . $tid . ': ' . $subject, "Hallo,\n\nes gibt eine neue Support-Anfrage (#{$tid}): {$subject}\n\nDu findest sie im Support-Postfach.\n");
            }
        }
    }

    // ── Wer wird benachrichtigt (/support/notify) ──

    /** Mögliche Empfänger: Seite = Team (Admins + Inhaber/Team-Admins), Plattform-Veranstalter = seine Manager. */
    private static function notify_candidates($oid) {
        if ($oid) return class_exists('TIX_Org_Approval') ? array_map('intval', TIX_Org_Approval::manager_ids($oid)) : [];
        return array_column(self::team(), 'id');
    }

    /** Empfänger neuer Anfragen ($oid = 0: Seite bzw. evendis-Team) bzw. von Rückfragen ($oid = Veranstalter). Vorgabe: alle Kandidaten. */
    public static function notify_ids($oid) {
        $cand = self::notify_candidates($oid);
        $set = $oid ? get_post_meta($oid, '_tix_support_notify', true) : get_option('tix_support_notify', null);
        if (!is_array($set)) return $cand;
        return array_values(array_intersect(array_map('intval', $set), $cand));
    }

    // ═══════════════ Partner-Brücke ═══════════════

    /** Cron: evendis-Anfrage an die Partner-Seite weiterleiten (Kopie anlegen). */
    public static function forward($tid) {
        $tid = intval($tid);
        if (!self::multi() || get_post_type($tid) !== self::CPT || get_post_meta($tid, '_tix_sp_forward_id', true)) return;
        $r = self::responsible($tid);
        $p = $r['partner'] !== '' && class_exists('TIX_Partners') ? TIX_Partners::get($r['partner']) : null;
        if (!$p || $p['api_base'] === '' || $p['key_out'] === '') { self::fallback_mail($tid, $r); return; }
        $msgs = [];
        foreach ((array) TIX_Support::get_messages_public($tid) as $m) {
            if (($m['type'] ?? '') === 'customer') $msgs[] = ['author' => (string) ($m['author'] ?? ''), 'content' => (string) ($m['content'] ?? ''), 'date' => (string) ($m['date'] ?? '')];
        }
        $source_event = 0;
        foreach (self::ticket_events($tid) as $eid) { if (($s = intval(get_post_meta($eid, '_tix_source_id', true)))) { $source_event = $s; break; } }
        $order_id = intval(get_post_meta($tid, '_tix_sp_order_id', true));
        $res = self::call_partner($p, 'POST', '/partner/support/inbound', [
            'remote_id' => $tid, 'subject' => (string) get_the_title($tid), 'category' => (string) get_post_meta($tid, '_tix_sp_category', true),
            'name' => (string) get_post_meta($tid, '_tix_sp_name', true), 'email' => (string) get_post_meta($tid, '_tix_sp_email', true),
            'event_id' => $source_event, 'platform_order' => $order_id ? ('#' . $order_id) : '', 'ticket_code' => (string) get_post_meta($tid, '_tix_sp_ticket_code', true),
            'messages' => $msgs,
        ]);
        if (is_wp_error($res) || empty($res['ticket_id'])) {
            $tries = intval(get_post_meta($tid, '_tix_sp_forward_tries', true)) + 1;
            update_post_meta($tid, '_tix_sp_forward_tries', $tries);
            if ($tries < 6) wp_schedule_single_event(time() + 300 * $tries, self::CRON_FORWARD, [$tid]);
            else self::fallback_mail($tid, $r);
            return;
        }
        update_post_meta($tid, '_tix_sp_forward_id', intval($res['ticket_id']));
        TIX_Support::staff_note($tid, 'An ' . ($p['name'] ?: 'den Veranstalter') . ' weitergeleitet (dort Anfrage #' . intval($res['ticket_id']) . '). Antworten kommen über die Partner-Brücke.', 'System');
    }

    /** Partner nicht erreichbar bzw. ohne Partner-API: Veranstalter per Mail informieren, Anfrage bleibt hier. */
    private static function fallback_mail($tid, array $r) {
        if (get_post_meta($tid, '_tix_sp_forward_mail', true)) return;
        $to = $r['org'] ? (string) get_post_meta($r['org'], '_tix_org_email', true) : '';
        update_post_meta($tid, '_tix_sp_forward_mail', current_time('mysql'));
        if (!is_email($to)) { TIX_Support::staff_note($tid, 'Weiterleitung an den Veranstalter nicht möglich (keine Partner-Verbindung, keine E-Mail) – bitte selbst klären.', 'System'); return; }
        wp_mail($to, 'Support-Anfrage zu deinem Event: ' . get_the_title($tid), "Hallo,\n\nauf evendis.de ist eine Anfrage zu deinem geteilten Event eingegangen (Anfrage #{$tid}). Bitte antworte dem Kunden direkt:\n" . get_post_meta($tid, '_tix_sp_email', true) . "\n\nBetreff: " . get_the_title($tid) . "\n\nevendis");
        TIX_Support::staff_note($tid, 'Veranstalter per E-Mail informiert (' . $to . '), keine Partner-Verbindung.', 'System');
    }

    private static function call_partner(array $p, $method, $route, array $body) {
        $r = wp_remote_request(untrailingslashit($p['api_base']) . $route, ['method' => $method, 'timeout' => 20, 'body' => wp_json_encode($body), 'headers' => [
            'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(), 'X-Tix-Partner-Key' => $p['key_out']]]);
        if (is_wp_error($r)) return $r;
        $code = intval(wp_remote_retrieve_response_code($r));
        $data = json_decode((string) wp_remote_retrieve_body($r), true);
        return ($code >= 200 && $code < 300 && is_array($data)) ? $data : new WP_Error('tix_bridge', 'HTTP ' . $code);
    }

    private static function call_platform($method, $route, array $body) {
        $base = rtrim((string) tix_get_settings('syndication_api_url'), '/');
        if ($base === '' || (string) tix_get_settings('syndication_api_key') === '') return new WP_Error('tix_bridge', 'nicht verbunden');
        $r = wp_remote_request($base . $route, ['method' => $method, 'timeout' => 20, 'body' => wp_json_encode($body), 'headers' => [
            'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key')]]);
        if (is_wp_error($r)) return $r;
        $code = intval(wp_remote_retrieve_response_code($r));
        return ($code >= 200 && $code < 300) ? true : new WP_Error('tix_bridge', 'HTTP ' . $code);
    }

    /** Neue Nachricht: Kunde (evendis) → Kopie beim Partner; Team (Partner-Kopie) → evendis; evendis-Team → Notiz beim Partner. */
    public static function on_message($tid, $msg) {
        if (self::$bridge) return;
        $type = (string) ($msg['type'] ?? '');
        if (self::is_platform_copy($tid)) {
            if ($type !== 'admin') return;
            self::bridge_send($tid, ['to' => 'platform', 'route' => '/syndicate/support/' . intval(get_post_meta($tid, '_tix_sp_remote_id', true)) . '/reply',
                'body' => ['content' => (string) ($msg['content'] ?? ''), 'author' => (string) ($msg['author'] ?? ''), 'message_id' => (string) ($msg['id'] ?? '')]]);
            return;
        }
        $fid = intval(get_post_meta($tid, '_tix_sp_forward_id', true));
        if (!$fid || !self::multi() || !in_array($type, ['customer', 'admin'], true)) return;
        self::bridge_send($tid, ['to' => 'partner', 'route' => '/partner/support/' . $fid . '/messages',
            'body' => ['type' => $type === 'customer' ? 'customer' : 'note', 'author' => (string) ($msg['author'] ?? ''), 'content' => (string) ($msg['content'] ?? ''), 'message_id' => (string) ($msg['id'] ?? '')]]);
    }

    /** Status der Partner-Kopie → evendis (evendis mailt dem Kunden bei „gelöst“). */
    public static function on_status($tid, $new, $old) {
        if (self::$bridge || !self::is_platform_copy($tid) || $new === $old) return;
        self::bridge_send($tid, ['to' => 'platform', 'route' => '/syndicate/support/' . intval(get_post_meta($tid, '_tix_sp_remote_id', true)) . '/status', 'body' => ['status' => self::status_key($new)]]);
    }

    /** Über die Brücke senden; schlägt es fehl, kommt die Nachricht in die Warteschlange der Anfrage (Cron, bis zu 6 Versuche). */
    private static function bridge_send($tid, array $job) {
        $queue = get_post_meta($tid, '_tix_sp_bridge_queue', true);
        $queue = is_array($queue) ? $queue : [];
        if ($queue) { // Reihenfolge wahren
            $queue[] = $job + ['tries' => 0];
            update_post_meta($tid, '_tix_sp_bridge_queue', $queue);
            return self::flush($tid);
        }
        $res = self::send_job($tid, $job);
        if (!is_wp_error($res)) return true;
        update_post_meta($tid, '_tix_sp_bridge_queue', [$job + ['tries' => 1]]);
        if (!wp_next_scheduled(self::CRON_FLUSH, [intval($tid)])) wp_schedule_single_event(time() + 300, self::CRON_FLUSH, [intval($tid)]);
        return $res;
    }

    private static function send_job($tid, array $job) {
        if ($job['to'] === 'platform') return self::call_platform('POST', $job['route'], $job['body']);
        $r = self::responsible($tid);
        $p = $r['partner'] !== '' && class_exists('TIX_Partners') ? TIX_Partners::get($r['partner']) : null;
        if (!$p) return new WP_Error('tix_bridge', 'Partner fehlt');
        $res = self::call_partner($p, 'POST', $job['route'], $job['body']);
        return is_wp_error($res) ? $res : true;
    }

    /** Cron: Warteschlange einer Anfrage abarbeiten. */
    public static function flush($tid) {
        $tid = intval($tid);
        $queue = get_post_meta($tid, '_tix_sp_bridge_queue', true);
        if (!is_array($queue) || !$queue) return true;
        while ($queue) {
            $job = $queue[0];
            $res = self::send_job($tid, $job);
            if (is_wp_error($res)) {
                $queue[0]['tries'] = intval($job['tries'] ?? 0) + 1;
                if ($queue[0]['tries'] >= 6) {
                    array_shift($queue);
                    TIX_Support::staff_note($tid, 'Übertragung über die Partner-Brücke endgültig fehlgeschlagen (' . $res->get_error_message() . ') – bitte direkt klären.', 'System');
                    continue;
                }
                update_post_meta($tid, '_tix_sp_bridge_queue', $queue);
                if (!wp_next_scheduled(self::CRON_FLUSH, [$tid])) wp_schedule_single_event(time() + 300 * $queue[0]['tries'], self::CRON_FLUSH, [$tid]);
                return $res;
            }
            array_shift($queue);
        }
        delete_post_meta($tid, '_tix_sp_bridge_queue');
        return true;
    }

    // ═══════════════ Routen ═══════════════

    public static function register_routes() {
        $staff = [__CLASS__, 'perm_staff'];
        $r = function ($path, $method, $cb, $perm) { register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm]); };
        $r('/support/inbox', 'GET', 'rest_list', $staff);
        $r('/support/inbox/counts', 'GET', 'rest_counts', $staff);
        $r('/support/inbox/(?P<id>\d+)/read', 'POST', 'rest_read', $staff);
        $r('/support/notify', 'GET', 'rest_notify', $staff);
        $r('/support/notify', 'POST', 'rest_notify_save', $staff);
        $r('/support/inbox/(?P<id>\d+)', 'GET', 'rest_detail', $staff);
        $r('/support/inbox/(?P<id>\d+)/reply', 'POST', 'rest_reply', $staff);
        $r('/support/inbox/(?P<id>\d+)/status', 'POST', 'rest_status', $staff);
        $r('/support/inbox/(?P<id>\d+)/assign', 'POST', 'rest_assign', $staff);
        $r('/support/inbox/(?P<id>\d+)/note', 'POST', 'rest_note', $staff);
        $r('/support/inbox/(?P<id>\d+)/inquiry', 'POST', 'rest_inquiry_create', $staff);
        $r('/support/templates', 'GET', 'rest_templates', $staff);
        $r('/support/templates', 'POST', 'rest_template_save', $staff);
        $r('/support/templates/(?P<tpl>[A-Za-z0-9_-]+)', 'DELETE', 'rest_template_delete', $staff);
        $r('/support/inquiries', 'GET', 'rest_inquiries', $staff);
        $r('/support/inquiries/(?P<id>\d+)/(?P<inq>[A-Za-z0-9_-]+)/answer', 'POST', 'rest_inquiry_answer', $staff);
        $r('/support/mode', 'GET', 'rest_mode', $staff);
        $r('/support/mode', 'POST', 'rest_mode_save', function () { return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur Admins setzen den Support-Modus.', ['status' => 403]); });
        // Partner-Brücke
        $r('/partner/support/inbound', 'POST', 'rest_bridge_inbound', [__CLASS__, 'perm_from_platform']);
        $r('/partner/support/(?P<id>\d+)/messages', 'POST', 'rest_bridge_messages', [__CLASS__, 'perm_from_platform']);
        $r('/syndicate/support/(?P<id>\d+)/reply', 'POST', 'rest_bridge_reply', [__CLASS__, 'perm_from_partner']);
        $r('/syndicate/support/(?P<id>\d+)/status', 'POST', 'rest_bridge_status', [__CLASS__, 'perm_from_partner']);
    }

    /** Postfach: Admins sowie Inhaber und Team-Admins (Rolle Veranstalter); Einlass, DJ, Awareness, Kasse und App-Mitarbeiter 403. */
    public static function perm_staff(WP_REST_Request $req) {
        if (!class_exists('TIX_REST_API')) return false;
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        return self::support_user() ? true : new WP_Error('rest_forbidden', 'Den Support bearbeiten nur Inhaber und Team-Admins.', ['status' => 403]);
    }

    private static function support_user($uid = 0) {
        $u = $uid ? get_userdata(intval($uid)) : wp_get_current_user();
        if (!$u || !$u->ID) return false;
        if (user_can($u, 'manage_options')) return true;
        if (self::multi()) return TIX_App_Scope::is_org_manager($u->ID);
        return in_array('tix_organizer', (array) $u->roles, true);
    }

    /** Darf das Konto Anfragen bearbeiten (antworten, Status, Zuweisung)? Plattform: nur Admins (Veranstalter lesen). */
    private static function can_write($tid = 0) {
        if (current_user_can('manage_options')) return true;
        if (self::multi()) return false;
        return self::site_mode() === 'self' || ($tid && self::is_platform_copy($tid));
    }

    /** Sichtbare Anfrage? */
    private static function visible($tid) {
        if (get_post_type($tid) !== self::CPT) return false;
        if (current_user_can('manage_options')) return true;
        if ((string) get_post_meta($tid, '_tix_sp_category', true) === 'awareness') return false; // Awareness: nur Admin bzw. Awareness-Team
        if (!self::multi()) return true;
        $oid = TIX_App_Scope::organizer_id_for_user();
        $r = self::responsible($tid);
        return $oid && intval($r['org']) === $oid;
    }

    private static function status_key($st) {
        $k = array_search($st, self::STATUS_MAP, true);
        return $k === false ? 'open' : $k;
    }

    private static function list_row($p) {
        $tid = intval($p->ID);
        $r = self::responsible($tid);
        $as = intval(get_post_meta($tid, '_tix_sp_assignee', true));
        $msgs = (array) TIX_Support::get_messages_public($tid);
        $last = $msgs ? end($msgs) : [];
        $flags = self::flags_from($msgs);
        $evs = self::ticket_events($tid);
        $event = $evs ? ['id' => $evs[0], 'title' => html_entity_decode(get_the_title($evs[0]), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($evs[0], '_tix_date_start', true) ?: null] : null;
        $oid = intval(get_post_meta($tid, '_tix_sp_order_id', true));
        $order = null;
        if ($oid && class_exists('TIX_Order') && ($o = TIX_Order::get($oid))) {
            $oe = 0;
            foreach ($o->get_items() as $it) { if ($it->get_event_id()) { $oe = intval($it->get_event_id()); break; } }
            $order = ['id' => $oid, 'number' => (string) $o->get_order_number(), 'event_id' => $oe ?: null];
        }
        $active = in_array($p->post_status, ['tix_open', 'tix_progress'], true);
        $read = get_post_meta($tid, '_tix_sp_read', true);
        $read_at = is_array($read) ? intval($read[get_current_user_id()] ?? 0) : 0;
        return [
            'event' => $event, 'order' => $order, 'waiting' => $flags['waiting'],
            'unread' => $active && $flags['waiting'] === 'team' && $flags['customers'] > $read_at,
            'id' => $tid, 'subject' => (string) $p->post_title, 'status' => self::status_key($p->post_status),
            'category' => (string) get_post_meta($tid, '_tix_sp_category', true), 'name' => (string) get_post_meta($tid, '_tix_sp_name', true),
            'email' => (string) get_post_meta($tid, '_tix_sp_email', true), 'order_id' => intval(get_post_meta($tid, '_tix_sp_order_id', true)) ?: null,
            'event_id' => (self::ticket_events($tid)[0] ?? null), 'source' => (string) get_post_meta($tid, '_tix_sp_source', true),
            'responsible' => $r['type'], 'origin' => self::is_platform_copy($tid) ? 'platform' : 'local',
            'assignee' => $as ? ['id' => $as, 'name' => (string) get_the_author_meta('display_name', $as)] : null,
            'last_message' => $last ? ['type' => (string) ($last['type'] ?? ''), 'date' => (string) ($last['date'] ?? ''), 'preview' => mb_substr(wp_strip_all_tags((string) ($last['content'] ?? '')), 0, 120)] : null,
            'created' => (string) $p->post_date, 'updated' => (string) get_post_meta($tid, '_tix_sp_last_reply', true),
            'can_reply' => self::can_write($tid),
        ];
    }

    /** GET /support/inbox?status&event_id&order_id&category&assignee&search&page&per_page */
    public static function rest_list(WP_REST_Request $req) {
        $per = max(1, min(100, intval($req->get_param('per_page') ?: 25)));
        $page = max(1, intval($req->get_param('page') ?: 1));
        $st = sanitize_key((string) $req->get_param('status'));
        $args = ['post_type' => self::CPT, 'post_status' => isset(self::STATUS_MAP[$st]) ? [self::STATUS_MAP[$st]] : array_values(self::STATUS_MAP),
            'posts_per_page' => 500, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids', 'meta_query' => []];
        if (($c = sanitize_key((string) $req->get_param('category'))) !== '') $args['meta_query'][] = ['key' => '_tix_sp_category', 'value' => $c];
        if (($o = intval($req->get_param('order_id')))) $args['meta_query'][] = ['key' => '_tix_sp_order_id', 'value' => (string) $o];
        if (($a = $req->get_param('assignee')) !== null && $a !== '') $args['meta_query'][] = $a === 'me' ? ['key' => '_tix_sp_assignee', 'value' => (string) get_current_user_id()] : ($a === 'none' ? ['key' => '_tix_sp_assignee', 'compare' => 'NOT EXISTS'] : ['key' => '_tix_sp_assignee', 'value' => (string) intval($a)]);
        $q = trim(sanitize_text_field((string) $req->get_param('search')));
        if ($q !== '' && ctype_digit(ltrim($q, '#'))) $args['post__in'] = [intval(ltrim($q, '#'))];
        $ids = get_posts($args);
        if ($q !== '' && !ctype_digit(ltrim($q, '#'))) {
            $ids = array_values(array_filter($ids, function ($tid) use ($q) {
                foreach ([get_the_title($tid), get_post_meta($tid, '_tix_sp_email', true), get_post_meta($tid, '_tix_sp_name', true), get_post_meta($tid, '_tix_sp_ticket_code', true)] as $h) {
                    if ($h !== '' && mb_stripos((string) $h, $q) !== false) return true;
                }
                return false;
            }));
        }
        $ev = intval($req->get_param('event_id'));
        $wt = sanitize_key((string) $req->get_param('waiting'));
        $ids = array_values(array_filter($ids, function ($tid) use ($ev, $wt) {
            if (!self::visible($tid) || (string) get_post_meta($tid, '_tix_sp_category', true) === 'awareness') return false;
            if (in_array($wt, ['team', 'customer'], true) && self::waiting_of($tid) !== $wt) return false;
            return !$ev || in_array($ev, self::ticket_events($tid), true);
        }));
        $total = count($ids);
        $rows = array_map(function ($tid) { return self::list_row(get_post($tid)); }, array_slice($ids, ($page - 1) * $per, $per));
        return rest_ensure_response(['ok' => true, 'mode' => self::multi() ? 'platform' : self::site_mode(), 'can_reply' => self::can_write(), 'total' => $total, 'page' => $page, 'per_page' => $per, 'has_more' => $page * $per < $total, 'counts' => self::counts(), 'tickets' => $rows]);
    }

    private static function load($req) {
        $tid = absint($req['id']);
        if (!self::visible($tid)) return new WP_Error('not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        return $tid;
    }

    public static function rest_detail(WP_REST_Request $req) {
        $tid = self::load($req);
        if (is_wp_error($tid)) return $tid;
        $row = self::list_row(get_post($tid));
        $msgs = TIX_Support::staff_messages($tid);
        if (!current_user_can('manage_options') && self::multi()) $msgs = array_values(array_filter($msgs, function ($m) { return ($m['type'] ?? '') !== 'note'; }));
        $row['messages'] = $msgs;
        $row['ticket_code'] = (string) get_post_meta($tid, '_tix_sp_ticket_code', true);
        $row['inquiries'] = self::inquiries_of($tid);
        $row['team'] = self::can_write($tid) ? self::team() : [];
        $row['customer'] = self::customer_info($tid);
        self::mark_read($tid, true);
        $row['unread'] = false;
        return rest_ensure_response(['ok' => true, 'ticket' => $row]);
    }

    private static function deny_write($tid) {
        return self::can_write($tid) ? null : new WP_Error('rest_forbidden', 'Diese Anfrage beantwortet evendis – du kannst sie nur lesen.', ['status' => 403]);
    }

    /** POST /support/inbox/{id}/reply {content | template_id} */
    public static function rest_reply(WP_REST_Request $req) {
        $tid = self::load($req);
        if (is_wp_error($tid)) return $tid;
        if ($e = self::deny_write($tid)) return $e;
        $content = sanitize_textarea_field((string) $req->get_param('content'));
        if ($content === '' && ($tpl = (string) $req->get_param('template_id')) !== '') $content = (string) (self::render_tpl($tpl, $tid) ?? '');
        if (trim($content) === '') return self::bad('content', 'Bitte eine Antwort eingeben.');
        $st = $req->get_param('status');
        $st = $st === null || $st === '' ? '' : sanitize_key((string) $st);
        if ($st !== '' && !isset(self::STATUS_MAP[$st])) return self::bad('status', 'Status: open, progress, resolved oder closed.');
        $u = wp_get_current_user();
        $copy = self::is_platform_copy($tid);
        $res = TIX_Support::staff_reply($tid, $content, ['author' => $u->display_name ?: 'Support-Team', 'user_id' => $u->ID, 'deliver' => !$copy]);
        // Antwort + Status in einem: die Antwort-Mail genügt, keine zweite „gelöst“-Mail (Kopie: Status geht über die Brücke)
        if ($st !== '') TIX_Support::set_status($tid, self::STATUS_MAP[$st], false);
        self::mark_read($tid, true);
        return rest_ensure_response(['ok' => true] + $res + ['ticket' => self::list_row(get_post($tid))]);
    }

    public static function rest_status(WP_REST_Request $req) {
        $tid = self::load($req);
        if (is_wp_error($tid)) return $tid;
        if ($e = self::deny_write($tid)) return $e;
        $st = sanitize_key((string) $req->get_param('status'));
        if (!isset(self::STATUS_MAP[$st])) return self::bad('status', 'Status: open, progress, resolved oder closed.');
        TIX_Support::set_status($tid, self::STATUS_MAP[$st], !self::is_platform_copy($tid));
        return rest_ensure_response(['ok' => true, 'ticket' => self::list_row(get_post($tid))]);
    }

    /** Team für Zuweisung und Benachrichtigung: Admins + Inhaber/Team-Admins (Ein-Veranstalter) bzw. Admins (Plattform). */
    private static function team() {
        $roles = self::multi() ? ['administrator'] : ['administrator', 'tix_organizer'];
        return array_map(function ($u) { return ['id' => intval($u->ID), 'name' => (string) ($u->display_name ?: $u->user_login)]; }, get_users(['role__in' => $roles, 'number' => 100]));
    }

    public static function rest_assign(WP_REST_Request $req) {
        $tid = self::load($req);
        if (is_wp_error($tid)) return $tid;
        if ($e = self::deny_write($tid)) return $e;
        $uid = $req->get_param('user_id');
        if ($uid === null || $uid === '' || intval($uid) === 0) {
            delete_post_meta($tid, '_tix_sp_assignee');
        } else {
            $uid = intval($uid);
            if (!in_array($uid, array_column(self::team(), 'id'), true)) return self::bad('user_id', 'Diese Person gehört nicht zum Team.');
            update_post_meta($tid, '_tix_sp_assignee', $uid);
            if ($uid !== get_current_user_id() && class_exists('TIX_Notifications')) {
                try { TIX_Notifications::add_user_item($uid, 'Support-Anfrage zugewiesen', '#' . $tid . ' · ' . get_the_title($tid), ['type' => 'support_team', 'action' => 'support:inbox:' . $tid]); } catch (\Throwable $e) {}
            }
        }
        return rest_ensure_response(['ok' => true, 'ticket' => self::list_row(get_post($tid))]);
    }

    public static function rest_note(WP_REST_Request $req) {
        $tid = self::load($req);
        if (is_wp_error($tid)) return $tid;
        if ($e = self::deny_write($tid)) return $e;
        $c = sanitize_textarea_field((string) $req->get_param('content'));
        if (trim($c) === '') return self::bad('content', 'Bitte eine Notiz eingeben.');
        $u = wp_get_current_user();
        return rest_ensure_response(['ok' => true, 'message' => TIX_Support::staff_note($tid, $c, $u->display_name ?: 'Team', $u->ID)]);
    }

    // ── Zähler, wartet auf, gelesen ──

    /** waiting (team = letzte Nachricht vom Kunden bzw. keine, customer = vom Team) + Anzahl der Kunden-Nachrichten (für „ungelesen“). Notizen zählen nicht. */
    private static function flags_from(array $msgs) {
        $w = 'team'; $n = 0;
        foreach ($msgs as $m) {
            $t = (string) ($m['type'] ?? '');
            if ($t === 'customer') { $w = 'team'; $n++; }
            elseif ($t === 'admin') $w = 'customer';
        }
        return ['waiting' => $w, 'customers' => $n];
    }

    /** Jede Änderung am Verlauf (alle Wege: App, Portal, Mail-Eingang, Brücke) hält die Merker aktuell. */
    public static function on_meta($meta_id, $post_id, $key) {
        if ($key !== '_tix_sp_messages' || get_post_type($post_id) !== self::CPT) return;
        self::refresh_flags($post_id);
    }

    private static function refresh_flags($tid) {
        $f = self::flags_from((array) TIX_Support::get_messages_public($tid));
        if (get_post_meta($tid, '_tix_sp_waiting', true) !== $f['waiting']) update_post_meta($tid, '_tix_sp_waiting', $f['waiting']);
        if (get_post_meta($tid, '_tix_sp_customer_n', true) === '' || intval(get_post_meta($tid, '_tix_sp_customer_n', true)) !== $f['customers']) update_post_meta($tid, '_tix_sp_customer_n', $f['customers']);
        return $f;
    }

    private static function waiting_of($tid) {
        $w = (string) get_post_meta($tid, '_tix_sp_waiting', true);
        return $w !== '' ? $w : self::refresh_flags($tid)['waiting'];
    }

    /** Gelesen je Mitarbeiter = Anzahl der Kunden-Nachrichten beim Lesen. */
    private static function mark_read($tid, $read) {
        $l = get_post_meta($tid, '_tix_sp_read', true);
        $l = is_array($l) ? $l : [];
        if ($read) $l[get_current_user_id()] = self::flags_from((array) TIX_Support::get_messages_public($tid))['customers']; else unset($l[get_current_user_id()]);
        update_post_meta($tid, '_tix_sp_read', $l);
    }

    /** POST /support/inbox/{id}/read {unread?: true} */
    public static function rest_read(WP_REST_Request $req) {
        $tid = self::load($req);
        if (is_wp_error($tid)) return $tid;
        self::mark_read($tid, !filter_var($req->get_param('unread'), FILTER_VALIDATE_BOOLEAN));
        return rest_ensure_response(['ok' => true, 'ticket' => self::list_row(get_post($tid))]);
    }

    /**
     * Zähler für Menüpunkt und Reiter (sichtbare Anfragen ohne Awareness). mine/unassigned/waiting/unread nur offene + in Arbeit.
     * Eine SQL-Abfrage; fehlende Merker älterer Anfragen werden einmalig nachgetragen.
     */
    public static function counts() {
        global $wpdb;
        $statuses = array_values(self::STATUS_MAP);
        $in = "'" . implode("','", $statuses) . "'";
        $oid = self::multi() && !current_user_can('manage_options') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
        // Nachtragen: Merker bzw. Zuständigkeit (Plattform) fehlen
        $need = $wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} w ON w.post_id = p.ID AND w.meta_key = '_tix_sp_waiting'
            LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_tix_sp_responsible'
            WHERE p.post_type = '" . self::CPT . "' AND p.post_status IN ($in) AND (w.meta_id IS NULL OR r.meta_id IS NULL) LIMIT 500");
        foreach ($need as $tid) { self::refresh_flags(intval($tid)); self::responsible(intval($tid)); }
        $uid = get_current_user_id();
        $sql = "SELECT p.post_status st, a.meta_value asg, w.meta_value wt, p.ID id, lc.meta_value lc
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_tix_sp_category'
            LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_tix_sp_assignee'
            LEFT JOIN {$wpdb->postmeta} w ON w.post_id = p.ID AND w.meta_key = '_tix_sp_waiting'
            LEFT JOIN {$wpdb->postmeta} lc ON lc.post_id = p.ID AND lc.meta_key = '_tix_sp_customer_n'"
            . ($oid ? " INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_tix_sp_resp_org' AND o.meta_value = '" . $oid . "'" : '')
            . " WHERE p.post_type = '" . self::CPT . "' AND p.post_status IN ($in) AND (c.meta_value IS NULL OR c.meta_value <> 'awareness')";
        if (self::multi() && !$oid && !current_user_can('manage_options')) return ['open' => 0, 'progress' => 0, 'resolved' => 0, 'closed' => 0, 'mine' => 0, 'unassigned' => 0, 'waiting' => 0, 'unread' => 0];
        $out = ['open' => 0, 'progress' => 0, 'resolved' => 0, 'closed' => 0, 'mine' => 0, 'unassigned' => 0, 'waiting' => 0, 'unread' => 0];
        $active_ids = [];
        foreach ((array) $wpdb->get_results($sql) as $row) {
            $out[self::status_key($row->st)]++;
            if (!in_array($row->st, ['tix_open', 'tix_progress'], true)) continue;
            if (intval($row->asg) === $uid && $uid) $out['mine']++;
            if (!intval($row->asg)) $out['unassigned']++;
            if ($row->wt !== 'customer') { $out['waiting']++; $active_ids[intval($row->id)] = intval($row->lc); }
        }
        if ($active_ids) {
            $reads = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tix_sp_read' AND post_id IN (" . implode(',', array_keys($active_ids)) . ")");
            $ra = [];
            foreach ((array) $reads as $rr) { $v = maybe_unserialize($rr->meta_value); $ra[intval($rr->post_id)] = is_array($v) ? intval($v[$uid] ?? 0) : 0; }
            foreach ($active_ids as $id => $lc) if ($lc > ($ra[$id] ?? 0)) $out['unread']++;
        }
        return $out;
    }

    /** GET /support/inbox/counts – günstig für den Menüpunkt. */
    public static function rest_counts(WP_REST_Request $req) {
        return rest_ensure_response(['ok' => true, 'counts' => self::counts()]);
    }

    /** Kunde: Konto vorhanden? + letzte 5 Bestellungen zur E-Mail (Plattform-Veranstalter: nur eigene Events). */
    private static function customer_info($tid) {
        $email = (string) get_post_meta($tid, '_tix_sp_email', true);
        $out = ['has_account' => is_email($email) && (bool) get_user_by('email', $email), 'orders' => []];
        if (!is_email($email) || !class_exists('TIX_Order')) return $out;
        $scoped = self::multi() && !current_user_can('manage_options');
        foreach (TIX_Order::get_by_customer($email, ['limit' => $scoped ? 50 : 5]) as $o) {
            if ($scoped && !TIX_App_Scope::order_allowed($o)) continue;
            $eid = 0;
            foreach ($o->get_items() as $it) { if ($it->get_event_id()) { $eid = intval($it->get_event_id()); break; } }
            $d = $o->get_date_created();
            $out['orders'][] = ['id' => intval($o->get_id()), 'number' => (string) $o->get_order_number(), 'event_id' => $eid ?: null,
                'event_title' => $eid ? html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') : null,
                'date' => $d ? (is_object($d) && method_exists($d, 'format') ? $d->format('c') : (string) $d) : null,
                'total' => round($o->get_total(), 2), 'status' => (string) $o->get_status()];
            if (count($out['orders']) >= 5) break;
        }
        return $out;
    }

    // ── Benachrichtigung ──

    private static function notify_scope() {
        return self::multi() && !current_user_can('manage_options') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
    }

    /** GET /support/notify → wer bei neuen Anfragen (Plattform-Veranstalter: bei Rückfragen) Push + Mail bekommt. */
    public static function rest_notify(WP_REST_Request $req) {
        $oid = self::notify_scope();
        $cand = self::notify_candidates($oid);
        $set = $oid ? get_post_meta($oid, '_tix_support_notify', true) : get_option('tix_support_notify', null);
        $sel = self::notify_ids($oid);
        return rest_ensure_response(['ok' => true, 'scope' => $oid ? 'organizer' : 'site', 'event' => $oid ? 'inquiry' : 'new_ticket', 'default' => !is_array($set), 'user_ids' => $sel,
            'candidates' => array_map(function ($id) use ($sel) { $u = get_userdata($id); return ['id' => $id, 'name' => $u ? (string) ($u->display_name ?: $u->user_login) : '', 'selected' => in_array($id, $sel, true)]; }, $cand)]);
    }

    /** POST /support/notify {user_ids: [..]} – [] = niemand, {reset: true} = Vorgabe (alle Inhaber und Team-Admins). */
    public static function rest_notify_save(WP_REST_Request $req) {
        $oid = self::notify_scope();
        if (filter_var($req->get_param('reset'), FILTER_VALIDATE_BOOLEAN)) {
            if ($oid) delete_post_meta($oid, '_tix_support_notify'); else delete_option('tix_support_notify');
            return self::rest_notify($req);
        }
        $ids = $req->get_param('user_ids');
        if (!is_array($ids)) return self::bad('user_ids', 'Bitte eine Liste von Team-Mitgliedern angeben.');
        $cand = self::notify_candidates($oid);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach ($ids as $k => $id) if (!in_array($id, $cand, true)) return self::bad('user_ids[' . $k . ']', 'Diese Person gehört nicht zum Team.');
        if ($oid) update_post_meta($oid, '_tix_support_notify', $ids); else update_option('tix_support_notify', $ids, false);
        return self::rest_notify($req);
    }

    // ── Textbausteine ──

    private static function tpl_allowed() {
        return current_user_can('manage_options') || (!self::multi() && self::site_mode() === 'self');
    }

    private static function render_tpl($id, $tid) {
        if (!class_exists('TIX_Support_Templates')) return null;
        foreach (TIX_Support_Templates::get_all() as $t) {
            if (($t['id'] ?? '') !== $id) continue;
            $name = trim((string) get_post_meta($tid, '_tix_sp_name', true));
            $parts = preg_split('/\s+/', $name, 2);
            $ev = self::ticket_events($tid);
            return TIX_Support_Templates::render_template(wp_strip_all_tags((string) $t['body']), ['first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'email' => (string) get_post_meta($tid, '_tix_sp_email', true),
                'ticket_id' => $tid, 'order_id' => (string) get_post_meta($tid, '_tix_sp_order_id', true), 'event_name' => $ev ? html_entity_decode(get_the_title($ev[0]), ENT_QUOTES, 'UTF-8') : '', 'ticket_code' => (string) get_post_meta($tid, '_tix_sp_ticket_code', true)]);
        }
        return null;
    }

    /** GET /support/templates[?ticket_id=] – mit ticket_id sind Platzhalter ersetzt (`rendered`). */
    public static function rest_templates(WP_REST_Request $req) {
        if (!self::tpl_allowed() || !class_exists('TIX_Support_Templates')) return new WP_Error('rest_forbidden', 'Textbausteine gibt es nur im eigenen Support.', ['status' => 403]);
        $tid = absint($req->get_param('ticket_id'));
        if ($tid && !self::visible($tid)) $tid = 0;
        $out = [];
        foreach (TIX_Support_Templates::get_all() as $t) {
            $row = ['id' => (string) ($t['id'] ?? ''), 'title' => (string) ($t['title'] ?? ''), 'body' => (string) ($t['body'] ?? ''), 'categories' => array_values((array) ($t['categories'] ?? [])), 'shortcut' => (string) ($t['shortcut'] ?? '')];
            if ($tid) $row['rendered'] = (string) self::render_tpl($row['id'], $tid);
            $out[] = $row;
        }
        return rest_ensure_response(['ok' => true, 'placeholders' => ['{{first_name}}', '{{last_name}}', '{{email}}', '{{ticket_id}}', '{{order_id}}', '{{event_name}}', '{{ticket_code}}'], 'templates' => $out]);
    }

    public static function rest_template_save(WP_REST_Request $req) {
        if (!self::tpl_allowed() || !class_exists('TIX_Support_Templates')) return new WP_Error('rest_forbidden', 'Textbausteine gibt es nur im eigenen Support.', ['status' => 403]);
        $title = trim(sanitize_text_field((string) $req->get_param('title')));
        $body = trim((string) $req->get_param('body'));
        if ($title === '') return self::bad('title', 'Bitte einen Titel angeben.');
        if ($body === '' || mb_strlen($body) > 5000) return self::bad('body', 'Bitte einen Text mit höchstens 5000 Zeichen angeben.');
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $req->get_param('id'));
        $data = ['title' => $title, 'body' => $body, 'categories' => (array) ($req->get_param('categories') ?? []), 'shortcut' => (string) ($req->get_param('shortcut') ?? '')];
        if ($id !== '') {
            if (!in_array($id, array_column(TIX_Support_Templates::get_all(), 'id'), true)) return new WP_Error('not_found', 'Textbaustein nicht gefunden.', ['status' => 404]);
            $data['id'] = $id;
        }
        return rest_ensure_response(['ok' => true, 'template' => TIX_Support_Templates::save_template($data)]);
    }

    public static function rest_template_delete(WP_REST_Request $req) {
        if (!self::tpl_allowed() || !class_exists('TIX_Support_Templates')) return new WP_Error('rest_forbidden', 'Textbausteine gibt es nur im eigenen Support.', ['status' => 403]);
        $id = (string) $req['tpl'];
        if (!in_array($id, array_column(TIX_Support_Templates::get_all(), 'id'), true)) return new WP_Error('not_found', 'Textbaustein nicht gefunden.', ['status' => 404]);
        TIX_Support_Templates::delete_template($id);
        return rest_ensure_response(['ok' => true, 'deleted' => $id]);
    }

    // ── Rückfragen (evendis → Veranstalter) ──

    private static function inquiries_of($tid) {
        $l = get_post_meta($tid, '_tix_sp_inquiries', true);
        return is_array($l) ? array_values($l) : [];
    }

    /** POST /support/inbox/{id}/inquiry {question, kind: info|refund, amount?, due_hours?} – evendis-Support (Admin) fragt den Veranstalter. */
    public static function rest_inquiry_create(WP_REST_Request $req) {
        $tid = self::load($req);
        if (is_wp_error($tid)) return $tid;
        if (!current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Rückfragen stellt der evendis-Support.', ['status' => 403]);
        $r = self::responsible($tid);
        if (!$r['org']) return new WP_Error('tix_no_organizer', 'Zu dieser Anfrage gibt es keinen Veranstalter.', ['status' => 409]);
        $q = trim(sanitize_textarea_field((string) $req->get_param('question')));
        if ($q === '') return self::bad('question', 'Bitte die Frage an den Veranstalter eingeben.');
        $kind = sanitize_key((string) ($req->get_param('kind') ?: 'info'));
        if (!in_array($kind, ['info', 'refund'], true)) return self::bad('kind', 'Art: info oder refund.');
        $amount = $req->get_param('amount');
        if ($kind === 'refund' && ($amount === null || !is_numeric($amount) || floatval($amount) <= 0)) return self::bad('amount', 'Bitte den Betrag der Erstattung angeben.');
        $hours = max(1, min(240, intval($req->get_param('due_hours') ?: 48)));
        $inq = ['id' => 'inq_' . wp_generate_password(8, false, false), 'kind' => $kind, 'question' => $q, 'amount' => $kind === 'refund' ? round(floatval($amount), 2) : null,
            'organizer_id' => intval($r['org']), 'status' => 'open', 'asked_by' => get_current_user_id(), 'asked_at' => current_time('mysql'),
            'due' => gmdate('Y-m-d H:i:s', current_time('timestamp') + $hours * HOUR_IN_SECONDS), 'answer' => '', 'approved' => null, 'answered_at' => null, 'answered_by' => 0, 'reminded' => false];
        $list = self::inquiries_of($tid);
        $list[] = $inq;
        update_post_meta($tid, '_tix_sp_inquiries', $list);
        TIX_Support::staff_note($tid, 'Rückfrage an den Veranstalter: ' . $q . ($kind === 'refund' ? ' (Erstattung ' . number_format($inq['amount'], 2, ',', '.') . ' € – braucht Freigabe)' : ''), wp_get_current_user()->display_name ?: 'evendis', get_current_user_id());
        self::notify_org($inq['organizer_id'], 'Rückfrage von evendis', '#' . $tid . ' · ' . $q, $tid);
        return rest_ensure_response(['ok' => true, 'inquiry' => $inq]);
    }

    private static function notify_org($oid, $title, $body, $tid) {
        foreach (self::notify_ids(intval($oid)) as $uid) {
            if (class_exists('TIX_Notifications')) { try { TIX_Notifications::add_user_item(intval($uid), $title, $body, ['type' => 'support_inquiry', 'action' => 'support:inquiry:' . $tid]); } catch (\Throwable $e) {} }
        }
        $to = (string) get_post_meta($oid, '_tix_org_email', true);
        if (is_email($to)) wp_mail($to, $title, $body . "\n\nBitte im Studio unter „Rückfragen“ antworten.");
    }

    /** Rückfragen an den eigenen Veranstalter (Admin: alle). ?status=open|answered|all */
    public static function rest_inquiries(WP_REST_Request $req) {
        $st = sanitize_key((string) ($req->get_param('status') ?: 'open'));
        $oid = self::multi() && !current_user_can('manage_options') ? TIX_App_Scope::organizer_id_for_user() : 0;
        if (!current_user_can('manage_options') && !$oid) return rest_ensure_response(['ok' => true, 'inquiries' => []]);
        $ids = get_posts(['post_type' => self::CPT, 'post_status' => array_values(self::STATUS_MAP), 'posts_per_page' => 300, 'fields' => 'ids', 'meta_key' => '_tix_sp_inquiries', 'orderby' => 'modified', 'order' => 'DESC']);
        $out = [];
        foreach ($ids as $tid) {
            foreach (self::inquiries_of($tid) as $inq) {
                if ($oid && intval($inq['organizer_id']) !== $oid) continue;
                if ($st !== 'all' && $inq['status'] !== $st) continue;
                $ev = self::ticket_events($tid);
                $out[] = $inq + ['ticket_id' => intval($tid), 'subject' => (string) get_the_title($tid), 'order_id' => intval(get_post_meta($tid, '_tix_sp_order_id', true)) ?: null,
                    'event' => $ev ? ['id' => $ev[0], 'title' => html_entity_decode(get_the_title($ev[0]), ENT_QUOTES, 'UTF-8')] : null, 'overdue' => $inq['status'] === 'open' && strtotime($inq['due']) < current_time('timestamp')];
            }
        }
        return rest_ensure_response(['ok' => true, 'inquiries' => $out]);
    }

    /** POST /support/inquiries/{ticket}/{inquiry}/answer {answer, approve?} – Veranstalter (Inhaber/Team-Admin) antwortet evendis. */
    public static function rest_inquiry_answer(WP_REST_Request $req) {
        $tid = absint($req['id']);
        $iid = (string) $req['inq'];
        $list = self::inquiries_of($tid);
        $i = array_search($iid, array_column($list, 'id'), true);
        if (get_post_type($tid) !== self::CPT || $i === false) return new WP_Error('not_found', 'Rückfrage nicht gefunden.', ['status' => 404]);
        $inq = $list[$i];
        $admin = current_user_can('manage_options');
        if (!$admin && (!self::multi() || TIX_App_Scope::organizer_id_for_user() !== intval($inq['organizer_id']) || !TIX_App_Scope::is_org_manager())) {
            return new WP_Error('rest_forbidden', 'Nur der Veranstalter selbst (Inhaber oder Team-Admin) beantwortet Rückfragen.', ['status' => 403]);
        }
        if ($inq['status'] !== 'open') return new WP_Error('tix_inquiry_answered', 'Diese Rückfrage ist schon beantwortet.', ['status' => 409]);
        $answer = trim(sanitize_textarea_field((string) $req->get_param('answer')));
        if ($answer === '') return self::bad('answer', 'Bitte eine Antwort eingeben.');
        if ($inq['kind'] === 'refund') {
            if ($req->get_param('approve') === null) return self::bad('approve', 'Bitte die Erstattung freigeben oder ablehnen (approve: true/false).');
            $inq['approved'] = filter_var($req->get_param('approve'), FILTER_VALIDATE_BOOLEAN);
        }
        $inq['status'] = 'answered';
        $inq['answer'] = $answer;
        $inq['answered_at'] = current_time('mysql');
        $inq['answered_by'] = get_current_user_id();
        $list[$i] = $inq;
        update_post_meta($tid, '_tix_sp_inquiries', $list);
        TIX_Support::staff_note($tid, 'Antwort des Veranstalters auf die Rückfrage: ' . $answer . ($inq['kind'] === 'refund' ? ($inq['approved'] ? ' – Erstattung FREIGEGEBEN' : ' – Erstattung ABGELEHNT') : ''), wp_get_current_user()->display_name ?: 'Veranstalter', get_current_user_id());
        wp_mail(get_option('admin_email'), 'Rückfrage beantwortet: #' . $tid, $answer);
        return rest_ensure_response(['ok' => true, 'inquiry' => $inq]);
    }

    /** Erstattung zu dieser Anfrage vom Veranstalter freigegeben? (Kulanz-Regel Plattform) */
    public static function refund_approved($tid) {
        foreach (self::inquiries_of($tid) as $inq) if ($inq['kind'] === 'refund' && $inq['approved'] === true) return true;
        return false;
    }

    /** Stündlich: überfällige offene Rückfragen einmal erinnern. */
    public static function remind() {
        $ids = get_posts(['post_type' => self::CPT, 'post_status' => array_values(self::STATUS_MAP), 'posts_per_page' => 300, 'fields' => 'ids', 'meta_key' => '_tix_sp_inquiries']);
        $n = 0;
        foreach ($ids as $tid) {
            $list = self::inquiries_of($tid); $ch = false;
            foreach ($list as $k => $inq) {
                if ($inq['status'] !== 'open' || !empty($inq['reminded']) || strtotime($inq['due']) > current_time('timestamp')) continue;
                self::notify_org(intval($inq['organizer_id']), 'Erinnerung: Rückfrage von evendis', '#' . $tid . ' · ' . $inq['question'], $tid);
                $list[$k]['reminded'] = true; $ch = true; $n++;
            }
            if ($ch) update_post_meta($tid, '_tix_sp_inquiries', $list);
        }
        return $n;
    }

    // ── Modus ──

    public static function rest_mode(WP_REST_Request $req) {
        $out = ['ok' => true, 'site_mode' => self::site_mode(), 'platform' => self::multi()];
        if (self::multi() && ($oid = TIX_App_Scope::organizer_id_for_user())) $out['organizer_mode'] = self::org_mode($oid);
        return rest_ensure_response($out);
    }

    /** POST /support/mode {mode[, organizer_id]} – nur Admin (Plattform je Veranstalter mit organizer_id). */
    public static function rest_mode_save(WP_REST_Request $req) {
        $m = sanitize_key((string) $req->get_param('mode'));
        if (!in_array($m, self::MODES, true)) return self::bad('mode', 'Modus: evendis, self oder evendis_package.');
        $oid = intval($req->get_param('organizer_id'));
        if ($oid) {
            if (get_post_type($oid) !== 'tix_organizer') return new WP_Error('not_found', 'Veranstalter nicht gefunden.', ['status' => 404]);
            update_post_meta($oid, '_tix_support_mode', $m);
        } else {
            update_option('tix_support_mode', $m, false);
        }
        return self::rest_mode($req);
    }

    // ── Kunden-Sicht ──

    /** Ansprechpartner für /support/config (optional event_id; Bestellungen nur intern). */
    public static function responsible_public($event_id = 0, $order_id = 0) {
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        if (!self::multi()) {
            $m = self::site_mode();
            if ($m === 'self') return ['type' => 'organizer', 'name' => $site . '-Team'];
            return ['type' => 'evendis', 'name' => 'evendis-Support'] + ($m === 'evendis_package' ? ['on_behalf' => $site] : []);
        }
        $events = [];
        if ($event_id && get_post_type(intval($event_id)) === 'event' && get_post_status(intval($event_id)) === 'publish') $events[] = intval($event_id);
        if ($order_id && class_exists('TIX_Order') && ($o = TIX_Order::get(intval($order_id)))) foreach ($o->get_items() as $it) $events[] = intval($it->get_event_id());
        foreach (array_filter($events) as $eid) {
            if (get_post_meta($eid, '_tix_syndicated', true) !== '1') continue;
            $p = class_exists('TIX_Partners') ? TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true)) : null;
            $org = $p ? intval($p['organizer_id']) : intval(get_post_meta($eid, '_tix_organizer_id', true));
            $name = $org ? html_entity_decode(get_the_title($org), ENT_QUOTES, 'UTF-8') : (string) get_post_meta($eid, '_tix_source_site', true);
            if ($org && self::org_mode($org) === 'evendis_package') return ['type' => 'evendis', 'name' => 'evendis-Support', 'on_behalf' => $name];
            return ['type' => 'organizer', 'name' => $name];
        }
        return ['type' => 'evendis', 'name' => 'evendis-Support'];
    }

    // ═══════════════ Brücken-Routen ═══════════════

    /** Partner-Seite: Aufruf der Plattform (gleiche Zugangsdaten wie die Partner-API). */
    public static function perm_from_platform(WP_REST_Request $req) {
        if (self::multi()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        if (!class_exists('TIX_Partner_API')) return false;
        $id = strtolower(trim((string) $req->get_header('X-Tix-Partner-Id')));
        $key = (string) $req->get_header('X-Tix-Partner-Key');
        $exp = (string) tix_get_settings('partner_api_key');
        if (!TIX_Partners::source_api_enabled() || $key === '' || !hash_equals($exp, $key) || $id !== TIX_Partners::source_platform_id()) return new WP_Error('tix_partner_auth', 'Ungültige Partner-Zugangsdaten.', ['status' => 401]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('support_bridge_in', 300, 600, 'platform')) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen.', ['status' => 429]);
        wp_set_current_user(0);
        return true;
    }

    /** Plattform: Aufruf einer Partner-Seite (eigener Partner-Schlüssel); die Anfrage muss an diesen Partner gehen. */
    public static function perm_from_partner(WP_REST_Request $req) {
        if (!self::multi() || !class_exists('TIX_Partners')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $p = TIX_Partners::find_by_key((string) $req->get_header('X-Tix-Syndication-Key'));
        if (!$p) return new WP_Error('unauthorized', 'Ungültiger Partner-Schlüssel.', ['status' => 401]);
        $tid = absint($req['id']);
        if (get_post_type($tid) !== self::CPT || (string) get_post_meta($tid, '_tix_sp_resp_partner', true) !== (string) $p['id']) return new WP_Error('not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('support_bridge', 300, 600, 'p' . $p['id'])) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen.', ['status' => 429]);
        wp_set_current_user(0);
        return true;
    }

    /** Partner-Seite: Kopie einer evendis-Anfrage anlegen (idempotent je remote_id). */
    public static function rest_bridge_inbound(WP_REST_Request $req) {
        $remote = intval($req->get_param('remote_id'));
        $email = sanitize_email((string) $req->get_param('email'));
        $subject = mb_substr(sanitize_text_field((string) $req->get_param('subject')), 0, 200);
        if (!$remote || $subject === '') return self::bad('remote_id', 'remote_id und subject sind Pflicht.');
        $found = get_posts(['post_type' => self::CPT, 'post_status' => array_values(self::STATUS_MAP), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => [['key' => '_tix_sp_origin', 'value' => 'platform'], ['key' => '_tix_sp_remote_id', 'value' => (string) $remote]]]);
        if ($found) return rest_ensure_response(['ok' => true, 'ticket_id' => intval($found[0]), 'existing' => true]);
        self::$bridge = true;
        $tid = wp_insert_post(['post_type' => self::CPT, 'post_title' => $subject, 'post_status' => 'tix_open', 'post_author' => 0]);
        if (!$tid || is_wp_error($tid)) { self::$bridge = false; return new WP_Error('tix_support_failed', 'Anfrage konnte nicht angelegt werden.', ['status' => 500]); }
        $meta = ['_tix_sp_origin' => 'platform', '_tix_sp_remote_id' => $remote, '_tix_sp_email' => $email, '_tix_sp_name' => sanitize_text_field((string) $req->get_param('name')),
            '_tix_sp_category' => sanitize_key((string) $req->get_param('category')) ?: 'other', '_tix_sp_priority' => 'normal', '_tix_sp_source' => 'evendis', '_tix_sp_last_reply' => current_time('c'),
            '_tix_sp_platform_order' => sanitize_text_field((string) $req->get_param('platform_order')), '_tix_sp_ticket_code' => strtoupper(sanitize_text_field((string) $req->get_param('ticket_code')))];
        $eid = intval($req->get_param('event_id'));
        if ($eid && get_post_type($eid) === 'event') $meta['_tix_sp_event_id'] = $eid;
        foreach ($meta as $k => $v) update_post_meta($tid, $k, $v);
        foreach ((array) $req->get_param('messages') as $m) {
            if (!is_array($m) || trim((string) ($m['content'] ?? '')) === '') continue;
            TIX_Support::add_customer_message($tid, sanitize_textarea_field((string) $m['content']), sanitize_text_field((string) ($m['author'] ?? '')) ?: $email, $email);
        }
        TIX_Support::staff_note($tid, 'Über evendis.de eingegangen (dort Anfrage #' . $remote . ', Bestellung ' . ($meta['_tix_sp_platform_order'] ?: '–') . '). Deine Antwort geht über evendis an den Kunden.', 'System');
        self::$bridge = false;
        return rest_ensure_response(['ok' => true, 'ticket_id' => intval($tid)]);
    }

    /** Partner-Seite: Kunden-Nachricht bzw. evendis-Notiz zur Kopie. */
    public static function rest_bridge_messages(WP_REST_Request $req) {
        $tid = absint($req['id']);
        if (get_post_type($tid) !== self::CPT || !self::is_platform_copy($tid)) return new WP_Error('not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        $c = sanitize_textarea_field((string) $req->get_param('content'));
        if (trim($c) === '') return self::bad('content', 'Nachricht fehlt.');
        if (self::seen($tid, $req->get_param('message_id'))) return rest_ensure_response(['ok' => true, 'duplicate' => true]);
        self::$bridge = true;
        if ($req->get_param('type') === 'note') TIX_Support::staff_note($tid, 'evendis-Support: ' . $c, 'evendis');
        else TIX_Support::add_customer_message($tid, $c, sanitize_text_field((string) $req->get_param('author')) ?: 'Kunde', (string) get_post_meta($tid, '_tix_sp_email', true));
        self::$bridge = false;
        if ($req->get_param('type') !== 'note') self::notify_assignee($tid, 'Neue Nachricht zur Anfrage');
        return rest_ensure_response(['ok' => true]);
    }

    /** Über die Brücke schon angekommen? (Wiederholung nach verlorener Antwort) */
    private static function seen($tid, $mid) {
        $mid = sanitize_key((string) $mid);
        if ($mid === '') return false;
        $l = get_post_meta($tid, '_tix_sp_bridge_seen', true);
        $l = is_array($l) ? $l : [];
        if (in_array($mid, $l, true)) return true;
        $l[] = $mid;
        update_post_meta($tid, '_tix_sp_bridge_seen', array_slice($l, -200));
        return false;
    }

    private static function notify_assignee($tid, $title) {
        $uid = intval(get_post_meta($tid, '_tix_sp_assignee', true));
        if ($uid && class_exists('TIX_Notifications')) { try { TIX_Notifications::add_user_item($uid, $title, '#' . $tid . ' · ' . get_the_title($tid), ['type' => 'support_team', 'action' => 'support:inbox:' . $tid]); } catch (\Throwable $e) {} }
    }

    /** Plattform: Antwort des Partner-Teams → an den Kunden (Mail + App) im Namen des Veranstalters. */
    public static function rest_bridge_reply(WP_REST_Request $req) {
        $tid = absint($req['id']);
        $c = sanitize_textarea_field((string) $req->get_param('content'));
        if (trim($c) === '') return self::bad('content', 'Nachricht fehlt.');
        if (self::seen($tid, $req->get_param('message_id'))) return rest_ensure_response(['ok' => true, 'duplicate' => true]);
        $r = self::responsible($tid);
        $org = $r['org'] ? html_entity_decode(get_the_title($r['org']), ENT_QUOTES, 'UTF-8') : 'Veranstalter';
        self::$bridge = true;
        $res = TIX_Support::staff_reply($tid, $c, ['author' => $org . '-Team']);
        self::$bridge = false;
        return rest_ensure_response(['ok' => true, 'message_id' => (string) ($res['message']['id'] ?? '')]);
    }

    public static function rest_bridge_status(WP_REST_Request $req) {
        $tid = absint($req['id']);
        $st = sanitize_key((string) $req->get_param('status'));
        if (!isset(self::STATUS_MAP[$st])) return self::bad('status', 'Ungültiger Status.');
        self::$bridge = true;
        TIX_Support::set_status($tid, self::STATUS_MAP[$st], true);
        self::$bridge = false;
        return rest_ensure_response(['ok' => true]);
    }
}
