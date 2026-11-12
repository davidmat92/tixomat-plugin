<?php
/**
 * Mail-Protokoll fürs Studio (P2 Teil A, 1.38.785). Vertrag `uebergaben/vertrag-mail-protokoll-konten.md`.
 *
 * Baut auf `TIX_Email_Log` (Tabelle `tix_email_log`, erfasst jede wp_mail) auf:
 * - Art (`kind`) und Bezüge (Bestellung, Event, Veranstalter, Konto) je Zeile – aus einem ausdrücklichen Kontext
 *   (`TIX_Mail_Log::context()`) oder aus der sendenden Funktion (Quelle + deren Argumente).
 * - Routen: GET /mails/log, GET /mails/log/{id}, POST /mails/log/{id}/resend; `mails` in Bestellung und Kunde.
 * - Rechte wie /orders; auf evendis nur eigene Zeilen (organizer_id); fremde/unbekannte → 404.
 * - Mails mit Anmelde-Codes/-Links: im Inhalt geschwärzt, nicht erneut sendbar.
 */
if (!defined('ABSPATH')) exit;

class TIX_Mail_Log {

    const NS = 'tixomat/v1';
    const RATE_MAX = 5; // erneut senden je Mail und Stunde

    const KINDS = [
        'order_confirmation' => 'Bestellbestätigung', 'tickets' => 'Tickets', 'order_status' => 'Bestellstatus', 'invoice' => 'Rechnung', 'refund' => 'Erstattung',
        'reminder' => 'Erinnerung', 'followup' => 'Nach dem Event', 'giftcard' => 'Gutschein', 'ticket_transfer' => 'Ticket-Übertragung', 'table_reservation' => 'Tischreservierung',
        'waitlist' => 'Warteliste', 'guestlist' => 'Gästeliste', 'support' => 'Support', 'account' => 'Konto', 'account_activation' => 'Konto-Aktivierung',
        'password_reset' => 'Passwort zurücksetzen', 'login_code' => 'Anmelde-Code/-Link', 'broadcast' => 'Mitteilung an Käufer', 'newsletter' => 'Newsletter',
        'follow' => 'Folgen', 'abandoned_cart' => 'Warenkorb-Erinnerung', 'raffle' => 'Gewinnspiel', 'team' => 'Team/Einladung', 'organizer' => 'Hinweis an Veranstalter',
        'platform' => 'Plattform', 'system' => 'System',
    ];

    /** Nie erneut senden, Inhalt geschwärzt (Codes/Links zur Anmeldung). */
    const SECRET_KINDS = ['password_reset', 'account_activation', 'login_code'];

    /** Quelle (Klasse::Funktion, klein) → Art; erste passende Regel gewinnt. */
    const RULES = [
        '/retrieve_password|password_reset|wrap_password_reset/' => 'password_reset',
        '/account_activation|trigger_activation/' => 'account_activation',
        '/send_code_email|magic_link|send_login|mail_login|send_device_code|demo_access::send_link/' => 'login_code',
        '/invoices::send|channel_invoice::send/' => 'invoice',
        '/refund/' => 'refund',
        '/send_native_completed|order_confirmation/' => 'order_confirmation',
        '/send_native_status_email|send_bank_info/' => 'order_status',
        '/ticket_transfer/' => 'ticket_transfer',
        '/send_order_tickets_email|resend_ticket|send_ticket_email|ajax_send_ticket/' => 'tickets',
        '/send_reminder/' => 'reminder',
        '/send_followup/' => 'followup',
        '/giftcard/' => 'giftcard',
        '/table_reservation/' => 'table_reservation',
        '/waitlist/' => 'waitlist',
        '/guest_notification/' => 'guestlist',
        '/support/' => 'support',
        '/broadcast|promoter_notify/' => 'broadcast',
        '/newsletter|weekly_mail/' => 'newsletter',
        '/mail_follow|artist_follow/' => 'follow',
        '/abandoned_cart/' => 'abandoned_cart',
        '/raffle/' => 'raffle',
        '/team|site_users|owner_access|sales::send_invite|send_invite/' => 'team',
        '/organizer_admin|organizer_notifications|notify_org|low_stock|co_organizer|co_money|co_sites|artists::tell/' => 'organizer',
        '/platform_offers|org_approval|contract|payout|license|partners|mirror|modules|sales_previews|mail_transport|assistant|awareness/' => 'platform',
        '/welcome|new_user|wp_new_user_notification|send_welcome/' => 'account',
    ];

    private static $ctx = [];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    // ═══════════════ Erfassen ═══════════════

    /** Mails in $fn mit Art/Bezügen protokollieren: context(['kind' => 'tickets', 'order_id' => 12], function () { wp_mail(…); }) */
    public static function context(array $c, callable $fn) {
        self::$ctx[] = $c;
        try { return $fn(); } finally { array_pop(self::$ctx); }
    }

    /** Art aus der Quelle (rein). */
    public static function classify($source) {
        $s = strtolower(str_replace(['TIX_', '-'], ['', '_'], (string) $source));
        foreach (self::RULES as $re => $kind) if (preg_match($re, $s)) return $kind;
        return 'system';
    }

    /**
     * Zusatzfelder für eine neue Zeile: kind, order_id, event_id, organizer_id, user_id, transport.
     * $frame = sendende Funktion mit Argumenten (aus TIX_Email_Log::detect_frame).
     */
    public static function enrich($source, $frame, $to) {
        $c = self::$ctx ? end(self::$ctx) : [];
        $kind = isset($c['kind'], self::KINDS[$c['kind']]) ? $c['kind'] : self::classify($source);
        $order = intval($c['order_id'] ?? 0);
        $event = intval($c['event_id'] ?? 0);
        if (!$order && is_array($frame)) {
            $fn = strtolower((string) ($frame['function'] ?? ''));
            $a = array_values((array) ($frame['args'] ?? []));
            $first = $a[0] ?? null;
            if (is_object($first) && isset($first->id) && isset($first->order_key)) $order = intval($first->id);
            elseif ((is_int($first) || (is_string($first) && ctype_digit($first))) && (strpos($fn, 'order') !== false || in_array($kind, ['order_confirmation', 'order_status', 'tickets', 'refund', 'invoice', 'reminder', 'followup'], true))) $order = intval($first);
            if (!$event && in_array($kind, ['reminder', 'followup'], true) && isset($a[1]) && is_numeric($a[1])) $event = intval($a[1]);
            if (!$event && $kind === 'guestlist' && is_numeric($first)) $event = intval($first);
        }
        global $wpdb;
        $user = intval($c['user_id'] ?? 0);
        if ($order) {
            $o = $wpdb->get_row($wpdb->prepare("SELECT id, customer_id FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order));
            if (!$o) $order = 0;
            elseif (!$user) $user = intval($o->customer_id);
            if ($order && !$event) $event = intval($wpdb->get_var($wpdb->prepare("SELECT event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d AND event_id > 0 ORDER BY id LIMIT 1", $order)));
        }
        if (!$user && is_email(trim((string) $to))) { $u = get_user_by('email', trim((string) $to)); if ($u) $user = intval($u->ID); }
        $org = intval($c['organizer_id'] ?? 0) ?: ($event ? intval(get_post_meta($event, '_tix_organizer_id', true)) : 0);
        $transport = 'wp';
        if (class_exists('TIX_Mail_Transport') && method_exists('TIX_Mail_Transport', 'stored')) {
            $m = (string) (TIX_Mail_Transport::stored()['mode'] ?? '');
            if (in_array($m, ['evendis', 'own'], true)) $transport = $m;
        }
        return ['kind' => $kind, 'order_id' => $order ?: null, 'event_id' => $event ?: null, 'organizer_id' => $org ?: null, 'user_id' => $user ?: null, 'transport' => $transport];
    }

    // ═══════════════ Rechte, Bereich ═══════════════

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function perm(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $u = wp_get_current_user();
        if (in_array('tix_cashier', (array) $u->roles, true) && !$u->has_cap('manage_options')) return new WP_Error('rest_forbidden', 'Für die Kasse nicht verfügbar.', ['status' => 403]);
        if (self::multi() && !current_user_can('manage_options') && !(TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user())) {
            return new WP_Error('rest_forbidden', 'Nur Inhaber bzw. Team sehen das Mail-Protokoll.', ['status' => 403]);
        }
        return true;
    }

    /** SQL-Bedingung für den Aufrufer (evendis: eigene organizer_id; Einzel-Seite: alles). Co über Durchgriff: nur Bestellungen eines Events. */
    private static function scope_sql() {
        global $wpdb;
        if (!self::multi() || current_user_can('manage_options')) return '1=1';
        return $wpdb->prepare('organizer_id = %d', intval(TIX_App_Scope::organizer_id_for_user()));
    }

    private static function table() {
        return TIX_Email_Log::table_name();
    }

    private static function row_visible($id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d AND " . self::scope_sql(), intval($id)));
        return $r ?: null;
    }

    // ═══════════════ Ausgabe ═══════════════

    public static function head($r) {
        $kind = (string) ($r->kind ?? '') ?: 'system';
        $order = intval($r->order_id ?? 0);
        $event = intval($r->event_id ?? 0);
        $num = null;
        if ($order) { global $wpdb; $num = (string) ($wpdb->get_var($wpdb->prepare("SELECT order_number FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order)) ?: $order); }
        $att = json_decode((string) ($r->attachments ?? ''), true);
        $cid = null;
        if (intval($r->user_id ?? 0)) $cid = 'u' . intval($r->user_id);
        elseif (is_email(trim((string) $r->to_email)) && class_exists('TIX_Customers')) $cid = TIX_Customers::guest_id(trim((string) $r->to_email));
        [$can, $why] = self::resendable($r);
        return [
            'id' => intval($r->id), 'at' => mysql2date('c', (string) $r->date_created), 'to' => (string) $r->to_email, 'subject' => (string) $r->subject,
            'kind' => $kind, 'kind_label' => self::KINDS[$kind] ?? $kind, 'status' => (string) $r->status === 'failed' ? 'failed' : 'sent', 'error' => (string) ($r->error_message ?? '') ?: null,
            'opened_at' => !empty($r->opened_at) ? mysql2date('c', (string) $r->opened_at) : null, 'open_count' => intval($r->open_count ?? 0), 'transport' => (string) ($r->transport ?? '') ?: 'wp',
            'order_id' => $order ?: null, 'order_number' => $num, 'event_id' => $event ?: null, 'event_title' => $event ? html_entity_decode(get_the_title($event), ENT_QUOTES, 'UTF-8') : null,
            'customer_id' => $cid, 'has_attachments' => is_array($att) && !empty($att), 'resent_from' => intval($r->resent_from ?? 0) ?: null,
            'resendable' => $can, 'resend_blocked_reason' => $why,
        ];
    }

    private static function resendable($r) {
        $kind = (string) ($r->kind ?? '') ?: 'system';
        if (in_array($kind, self::SECRET_KINDS, true)) return [false, 'Mails mit Anmelde-Codes oder -Links werden nicht erneut gesendet. Bitte einen neuen Code anfordern lassen.'];
        if ((string) $r->body === '') return [false, 'Der Inhalt ist nicht mehr gespeichert (Aufbewahrung abgelaufen).'];
        return [true, null];
    }

    /** Inhalt für die Anzeige: ohne Zähl-Pixel, Rückmelde-Links und Skripte; Anmelde-Codes/-Links geschwärzt (rein). */
    public static function clean_html($html, $kind) {
        $h = (string) $html;
        $h = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $h);
        $h = preg_replace('#<img[^>]+/mail-pixel/[^>]*>#i', '', $h);
        $h = preg_replace('#<a\b[^>]*href=["\'][^"\']*/mail-feedback/[^"\']*["\'][^>]*>.*?</a>#is', '', $h);
        $h = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $h);
        if (in_array($kind, self::SECRET_KINDS, true)) {
            $h = preg_replace('#(<a\b[^>]*href=)(["\'])[^"\']*\2#i', '$1$2#$2', $h);
            $h = preg_replace('#https?://[^\s<"\']+#i', '••••••', $h);
            $h = preg_replace('/\b\d{4,8}\b/', '••••••', $h);
            $h = preg_replace('/\b[A-Za-z0-9]{20,}\b/', '••••••', $h);
        }
        return $h;
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        $p = ['permission_callback' => [__CLASS__, 'perm']];
        register_rest_route(self::NS, '/mails/log', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list']] + $p);
        register_rest_route(self::NS, '/mails/log/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_detail']] + $p);
        register_rest_route(self::NS, '/mails/log/(?P<id>\d+)/resend', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_resend']] + $p);
    }

    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        TIX_Email_Log::ensure_columns();
        $t = self::table();
        $w = [self::scope_sql()];
        if (($e = trim((string) $req->get_param('email'))) !== '') $w[] = $wpdb->prepare('to_email LIKE %s', '%' . $wpdb->esc_like($e) . '%');
        foreach (['order_id', 'event_id'] as $k) if (intval($req->get_param($k))) $w[] = $wpdb->prepare("$k = %d", intval($req->get_param($k)));
        if (($k = (string) $req->get_param('kind')) !== '' && isset(self::KINDS[$k])) $w[] = $wpdb->prepare('kind = %s', $k);
        if (in_array($s = (string) $req->get_param('status'), ['sent', 'failed'], true)) $w[] = $wpdb->prepare('status = %s', $s);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param('from'))) $w[] = $wpdb->prepare('date_created >= %s', $req->get_param('from') . ' 00:00:00');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param('to'))) $w[] = $wpdb->prepare('date_created <= %s', $req->get_param('to') . ' 23:59:59');
        if (($q = trim((string) $req->get_param('q'))) !== '') { $l = '%' . $wpdb->esc_like($q) . '%'; $w[] = $wpdb->prepare('(to_email LIKE %s OR subject LIKE %s)', $l, $l); }
        $per = max(1, min(100, intval($req->get_param('per_page') ?: 30)));
        $page = max(1, intval($req->get_param('page') ?: 1));
        $where = implode(' AND ', $w);
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where"));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $per, ($page - 1) * $per));
        $kinds = [];
        foreach ((array) $wpdb->get_col("SELECT DISTINCT kind FROM $t WHERE " . self::scope_sql()) as $k) {
            $k = $k ?: 'system';
            $kinds[$k] = ['key' => $k, 'label' => self::KINDS[$k] ?? $k];
        }
        return rest_ensure_response(['items' => array_map([__CLASS__, 'head'], (array) $rows), 'total' => $total, 'page' => $page, 'per_page' => $per,
            'has_more' => $page * $per < $total, 'kinds' => array_values($kinds)]);
    }

    public static function rest_detail(WP_REST_Request $req) {
        TIX_Email_Log::ensure_columns();
        $r = self::row_visible(absint($req['id']));
        if (!$r) return new WP_Error('tix_mail_not_found', 'Mail nicht gefunden.', ['status' => 404]);
        $h = self::head($r);
        $from = null; $reply = null;
        foreach (preg_split('/\r?\n/', (string) $r->headers) as $line) {
            if (stripos($line, 'from:') === 0) $from = trim(substr($line, 5));
            if (stripos($line, 'reply-to:') === 0) $reply = trim(substr($line, 9));
        }
        $att = json_decode((string) ($r->attachments ?? ''), true);
        $list = [];
        foreach ((array) $att as $a) { $f = is_array($a) ? (string) ($a['file'] ?? '') : (string) $a; $list[] = ['name' => basename($f), 'size' => is_file($f) ? filesize($f) : null]; }
        return rest_ensure_response($h + ['html' => self::clean_html($r->body, $h['kind']), 'attachments' => $list, 'from' => $from, 'reply_to' => $reply]);
    }

    public static function rest_resend(WP_REST_Request $req) {
        global $wpdb;
        TIX_Email_Log::ensure_columns();
        $r = self::row_visible(absint($req['id']));
        if (!$r) return new WP_Error('tix_mail_not_found', 'Mail nicht gefunden.', ['status' => 404]);
        $kind = (string) ($r->kind ?? '') ?: 'system';
        if (in_array($kind, self::SECRET_KINDS, true)) return new WP_Error('tix_mail_not_resendable', 'Mails mit Anmelde-Codes oder -Links werden nicht erneut gesendet.', ['status' => 409]);
        if ((string) $r->body === '') return new WP_Error('tix_mail_gone', 'Der Inhalt ist nicht mehr gespeichert.', ['status' => 409]);
        $b = $req->get_json_params();
        $to = trim((string) (is_array($b) ? ($b['to'] ?? '') : ''));
        if ($to !== '' && !is_email($to)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse.', ['status' => 400, 'field' => 'to']);
        $key = 'tix_mail_rs_' . intval($r->id);
        $n = intval(get_transient($key));
        if ($n >= self::RATE_MAX) return new WP_Error('tix_mail_rate', 'Diese Mail wurde in der letzten Stunde schon ' . self::RATE_MAX . '-mal gesendet.', ['status' => 429]);
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        $headers = array_filter(preg_split('/\r?\n/', (string) $r->headers));
        if (!$headers) $headers = ['Content-Type: text/html; charset=UTF-8'];
        $body = preg_replace('#<img[^>]+/mail-pixel/[^>]+>#i', '', (string) $r->body);
        $files = TIX_Email_Log::attachments_of($r);
        $ctx = ['kind' => $kind, 'order_id' => intval($r->order_id ?? 0), 'event_id' => intval($r->event_id ?? 0), 'organizer_id' => intval($r->organizer_id ?? 0), 'user_id' => intval($r->user_id ?? 0)];
        TIX_Email_Log::$next_resent_from = intval($r->id);
        $sent = self::context($ctx, function () use ($r, $to, $body, $headers, $files) { return wp_mail($to !== '' ? $to : (string) $r->to_email, (string) $r->subject, $body, $headers, $files); });
        TIX_Email_Log::$next_resent_from = 0;
        $new = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE resent_from = %d ORDER BY id DESC LIMIT 1", intval($r->id))));
        if ($to !== '' && class_exists('TIX_Customers')) {
            TIX_Customers::audit('mail_resend_other', (string) $r->id, ['scope' => self::multi() ? 'organizer' : 'site', 'oid' => intval($r->organizer_id ?? 0)], ['mail' => intval($r->id), 'new' => $new, 'by' => get_current_user_id()]);
        }
        return rest_ensure_response(['ok' => (bool) $sent, 'id' => $new ?: null]);
    }

    /** Für Bestell- und Kunden-Detail: die letzten 10 Mails. */
    public static function recent(array $where_parts, $limit = 10) {
        global $wpdb;
        if (!$where_parts) return [];
        TIX_Email_Log::ensure_columns();
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . " WHERE (" . implode(' OR ', $where_parts) . ") AND " . self::scope_sql() . " ORDER BY id DESC LIMIT " . intval($limit));
        return array_map(function ($r) { $h = self::head($r); return ['id' => $h['id'], 'at' => $h['at'], 'kind_label' => $h['kind_label'], 'subject' => $h['subject'], 'status' => $h['status'], 'opened_at' => $h['opened_at']]; }, (array) $rows);
    }

    public static function for_order($order_id) {
        global $wpdb;
        return self::recent([$wpdb->prepare('order_id = %d', intval($order_id))]);
    }

    public static function for_customer($user_id, array $emails) {
        global $wpdb;
        $w = [];
        if ($user_id) $w[] = $wpdb->prepare('user_id = %d', intval($user_id));
        foreach (array_slice(array_filter(array_unique(array_map('strtolower', $emails))), 0, 10) as $e) $w[] = $wpdb->prepare('to_email = %s', $e);
        return self::recent($w);
    }
}
