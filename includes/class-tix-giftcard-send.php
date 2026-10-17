<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Giftcard_Send – Geschenkgutschein direkt an die beschenkte Person schicken (1.38.496, User).
 *
 *  - POST /giftcards/{code}/send {email, name?, message?, from_name?, send_at?, copy?, key?}
 *      → {ok, code, sent {email, at} | null, sent_to, sent_at, send_at, scheduled, recipient_name, recipient_email, sends}
 *    Ohne send_at sofort, mit send_at (ISO 8601 bzw. YYYY-MM-DD = 9 Uhr Ortszeit, höchstens 400 Tage, nicht nach Ablauf) geplant.
 *    name/message/from_name ändern die Geschenk-Angaben (auch auf dem PDF). Erneut senden geht jederzeit (Limits unten).
 *    copy=true: Kopie an den Aufrufer (Käufer: Bestell-E-Mail).
 *    DELETE /giftcards/{code}/send → geplanten Versand absagen.
 *  - Wer darf: Admin; Käufer (Konto, bestätigte Bestell-E-Mail oder ?key=<Bestell-Key>); Einzel-Seite Veranstalter-Rolle (Team/Inhaber);
 *    Mehr-Veranstalter-Modus Inhaber/Team-Admin für Gutscheine des eigenen Veranstalters (Plattform-Gutscheine nur Admin).
 *  - Limits: 10 Versände je Konto (Gäste: je IP) pro Stunde, 5 je Gutschein pro Tag.
 *  - Beim Kauf: gift.recipient_email (+ gift.send_at) in Quote/Order → nach der Bezahlung automatisch (bzw. zum Termin);
 *    die Bestätigung an den Käufer nennt den Versand; ein geplanter Versand schickt dem Käufer danach eine kurze Bestätigung.
 *  - Am Gutschein (tix_coupons[code]): sent_to, sent_at (ISO 8601), send_at (geplant), sends[] {ts, to, by, mode, ok} (letzte 20).
 *  - Mail im Mail-CI (TIX_Mail_Layout): Geschenk von …, Nachricht, Betrag, großer QR, Code, Gültigkeit, Einlöse-Text
 *    (TIX_Giftcard_Gift::redeem_steps), App-Store-Links, Gutschein-PDF im Anhang.
 */
class TIX_Giftcard_Send {

    const NS = 'tixomat/v1';
    const HOOK = 'tix_giftcard_send_due';
    const SWEEP = 'tix_giftcard_send_sweep';
    const MAX_DAYS = 400;
    const KEEP_SENDS = 20;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::HOOK, [__CLASS__, 'run_due'], 10, 1);
        add_action(self::SWEEP, [__CLASS__, 'sweep']);
        add_action('init', function () {
            if (!wp_next_scheduled(self::SWEEP)) wp_schedule_event(time() + 900, 'hourly', self::SWEEP);
        });
    }

    public static function routes() {
        register_rest_route(self::NS, '/giftcards/(?P<code>[A-Za-z0-9-]{4,40})/send', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_send'], 'permission_callback' => '__return_true'],   // Konto oder key
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_cancel'], 'permission_callback' => '__return_true'],
        ]);
    }

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    // ═══════════════ Termin ═══════════════

    /** Versandzeitpunkt prüfen → Unix-Zeit | WP_Error (field). YYYY-MM-DD = 9 Uhr Ortszeit der Seite. */
    public static function parse_send_at($raw, $field = 'send_at') {
        $raw = trim((string) $raw);
        if ($raw === '') return 0;
        try {
            $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)
                ? new DateTime($raw . ' 09:00:00', wp_timezone())
                : new DateTime($raw, wp_timezone());
        } catch (Exception $e) {
            return new WP_Error('tix_invalid_field', 'Bitte Datum und Uhrzeit im Format 2026-12-24T09:00 angeben.', ['status' => 400, 'field' => $field]);
        }
        $ts = $dt->getTimestamp();
        if ($ts <= time() + 60) return new WP_Error('tix_invalid_field', 'Der Versandzeitpunkt muss in der Zukunft liegen.', ['status' => 400, 'field' => $field]);
        if ($ts > time() + self::MAX_DAYS * DAY_IN_SECONDS) return new WP_Error('tix_invalid_field', sprintf('Höchstens %d Tage im Voraus.', self::MAX_DAYS), ['status' => 400, 'field' => $field]);
        return $ts;
    }

    // ═══════════════ Gutschein lesen/ändern ═══════════════

    /** Gutschein frisch aus der Datenbank ändern: $fn(array $card) → array. Gibt den neuen Gutschein (mit code) zurück. */
    private static function update_card($code, callable $fn) {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('tix_coupons', 'options');
        $coupons = get_option('tix_coupons', []);
        foreach ((array) $coupons as $k => $c) {
            if (strcasecmp((string) $k, (string) $code) === 0 && ($c['discount_type'] ?? '') === 'giftcard') {
                $coupons[$k] = $fn($c);
                update_option('tix_coupons', $coupons);
                return $coupons[$k] + ['code' => (string) $k];
            }
        }
        return null;
    }

    private static function expired($card) {
        return !empty($card['expires']) && strtotime($card['expires'] . ' 23:59:59') < current_time('timestamp');
    }

    /** Antwortfelder zum Versand. */
    public static function state($card) {
        $g = is_array($card['gift'] ?? null) ? $card['gift'] : [];
        $sends = [];
        foreach (array_slice((array) ($card['sends'] ?? []), -5) as $s) {
            if (is_array($s)) $sends[] = ['at' => (string) ($s['ts'] ?? ''), 'email' => (string) ($s['to'] ?? ''), 'mode' => (string) ($s['mode'] ?? ''), 'ok' => !empty($s['ok'])];
        }
        $sent = !empty($card['sent_to']) && !empty($card['sent_at']) ? ['email' => (string) $card['sent_to'], 'at' => (string) $card['sent_at']] : null;
        return [
            'code'            => (string) $card['code'],
            'sent'            => $sent,
            'sent_to'         => $sent ? $sent['email'] : null,
            'sent_at'         => $sent ? $sent['at'] : null,
            'send_at'         => !empty($card['send_at']) ? (string) $card['send_at'] : null,
            'scheduled'       => !empty($card['send_at']),
            'recipient_name'  => ($g['recipient_name'] ?? '') !== '' ? (string) $g['recipient_name'] : null,
            'recipient_email' => ($g['recipient_email'] ?? '') !== '' ? (string) $g['recipient_email'] : null,
            'from_name'       => ($g['from_name'] ?? '') !== '' ? (string) $g['from_name'] : null,
            'message'         => ($g['message'] ?? '') !== '' ? (string) $g['message'] : null,
            'sends'           => $sends,
        ];
    }

    /** Kurzform für Listen (Studio: recent, /customers/{id} giftcards): {sent {email, at}|null, sent_to, sent_at, send_at}. */
    public static function list_fields($card) {
        $s = self::state($card);
        return ['sent' => $s['sent'], 'sent_to' => $s['sent_to'], 'sent_at' => $s['sent_at'], 'send_at' => $s['send_at']];
    }

    // ═══════════════ Rechte ═══════════════

    private static function order_row($card) {
        global $wpdb;
        $oid = intval($card['order_id'] ?? 0);
        return $oid ? $wpdb->get_row($wpdb->prepare("SELECT id, customer_id, billing_email, order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid)) : null;
    }

    /** → ['role' => admin|team|buyer, 'copy_to' => E-Mail] oder WP_Error */
    private static function who($card, WP_REST_Request $req) {
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        $o = self::order_row($card);
        if ($user && $user->has_cap('manage_options')) return ['role' => 'admin', 'copy_to' => (string) $user->user_email];
        if ($user) {
            $oid = intval($card['organizer_id'] ?? 0);
            if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
                if ($oid && TIX_App_Scope::is_org_manager() && intval(TIX_App_Scope::organizer_id_for_user()) === $oid) return ['role' => 'team', 'copy_to' => (string) $user->user_email];
            } elseif (in_array('tix_organizer', (array) $user->roles, true)) {
                return ['role' => 'team', 'copy_to' => (string) $user->user_email];
            }
            $trusted = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : '';
            if ($o && intval($o->customer_id) > 0 && intval($o->customer_id) === intval($user->ID)) return ['role' => 'buyer', 'copy_to' => (string) $o->billing_email];
            if ($o && $trusted !== '' && strcasecmp((string) $o->billing_email, $trusted) === 0) return ['role' => 'buyer', 'copy_to' => (string) $o->billing_email];
        }
        $key = sanitize_text_field((string) ($req->get_param('key') ?? ''));
        if ($key !== '') {
            if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('gift_send_key', 20, 600, 'ip')) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', ['status' => 429]);
            if ($o && (string) $o->order_key !== '' && hash_equals((string) $o->order_key, $key)) return ['role' => 'buyer', 'copy_to' => (string) $o->billing_email];
        }
        return $user ? new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]) : new WP_Error('rest_not_logged_in', 'Bitte anmelden oder den Link aus der Bestellung nutzen.', ['status' => 401]);
    }

    // ═══════════════ REST ═══════════════

    /** POST /giftcards/{code}/send */
    public static function rest_send(WP_REST_Request $req) {
        $card = class_exists('TIX_Giftcards') ? TIX_Giftcards::get_card((string) $req['code']) : null;
        if (!$card) return new WP_Error('tix_giftcard_unknown', 'Gutschein nicht gefunden.', ['status' => 404]);
        $who = self::who($card, $req);
        if (is_wp_error($who)) return $who;
        $email = sanitize_email(trim((string) ($req->get_param('email') ?? $req->get_param('recipient_email') ?? '')));
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        $email = strtolower($email);
        if (self::expired($card)) return new WP_Error('tix_giftcard_expired', 'Dieser Gutschein ist abgelaufen.', ['status' => 409, 'expires' => (string) $card['expires']]);
        if (round(floatval($card['balance'] ?? 0), 2) <= 0) return new WP_Error('tix_giftcard_balance', 'Auf diesem Gutschein ist kein Guthaben mehr.', ['status' => 409, 'balance' => 0.0]);
        // Geschenk-Angaben: gesendete Felder ersetzen die gespeicherten
        $g = is_array($card['gift'] ?? null) ? $card['gift'] : [];
        $map = ['name' => 'recipient_name', 'recipient_name' => 'recipient_name', 'message' => 'message', 'from_name' => 'from_name'];
        foreach ($map as $param => $k) {
            $v = $req->get_param($param);
            if ($v !== null) $g[$k] = (string) $v;
        }
        $g['recipient_email'] = $email;
        $at = (string) ($req->get_param('send_at') ?? '');
        $clean = TIX_Giftcard_Gift::sanitize(array_intersect_key($g, array_flip(['recipient_name', 'message', 'from_name', 'recipient_email'])), 'gift');
        if (is_wp_error($clean)) {
            $d = (array) $clean->get_error_data();
            $f = isset($d['field']) ? preg_replace(['/^gift\.recipient_name$/', '/^gift\./'], ['name', ''], (string) $d['field']) : '';
            return new WP_Error($clean->get_error_code(), $clean->get_error_message(), array_merge($d, ['field' => $f]));
        }
        $ts = self::parse_send_at($at, 'send_at');
        if (is_wp_error($ts)) return $ts;
        if ($ts && !empty($card['expires']) && $ts > strtotime($card['expires'] . ' 23:59:59')) {
            return new WP_Error('tix_invalid_field', 'Der Termin liegt nach dem Ablauf des Gutscheins.', ['status' => 400, 'field' => 'send_at']);
        }
        // Limits erst nach den Prüfungen (Tippfehler kosten keinen Versuch)
        $uid = get_current_user_id();
        if (class_exists('TIX_App_Account')) {
            if (TIX_App_Account::rate_limited('gift_send', 10, HOUR_IN_SECONDS, $uid ? 'u' . $uid : '')) return new WP_Error('tix_rate_limit', 'Zu viele Versände – bitte später nochmal.', ['status' => 429]);
            if (TIX_App_Account::rate_limited('gift_send_code', 5, DAY_IN_SECONDS, 'c' . strtoupper((string) $card['code']))) return new WP_Error('tix_rate_limit', 'Dieser Gutschein wurde heute schon oft verschickt. Bitte morgen nochmal.', ['status' => 429]);
        }
        $by = $who['role'] . ($uid ? ':' . $uid : '');
        $code = (string) $card['code'];
        $card = self::update_card($code, function ($c) use ($clean) {
            $c['gift'] = array_merge(is_array($c['gift'] ?? null) ? $c['gift'] : [], (array) $clean);
            foreach (['recipient_name', 'message', 'from_name'] as $k) if (!isset($clean[$k])) unset($c['gift'][$k]);
            unset($c['gift']['send_at']); // Termin vom Kauf gilt nicht mehr
            return $c;
        });
        if ($ts) {
            $card = self::schedule($code, $ts, $by);
            return self::out(['ok' => true, 'scheduled' => true] + self::state($card));
        }
        $res = self::deliver($code, ['by' => $by, 'mode' => 'manual', 'copy_to' => filter_var($req->get_param('copy'), FILTER_VALIDATE_BOOLEAN) ? $who['copy_to'] : '']);
        if (is_wp_error($res)) return $res;
        return self::out(['ok' => true, 'scheduled' => false] + self::state($res));
    }

    /** DELETE /giftcards/{code}/send – geplanten Versand absagen. */
    public static function rest_cancel(WP_REST_Request $req) {
        $card = class_exists('TIX_Giftcards') ? TIX_Giftcards::get_card((string) $req['code']) : null;
        if (!$card) return new WP_Error('tix_giftcard_unknown', 'Gutschein nicht gefunden.', ['status' => 404]);
        $who = self::who($card, $req);
        if (is_wp_error($who)) return $who;
        wp_clear_scheduled_hook(self::HOOK, [(string) $card['code']]);
        $card = self::update_card((string) $card['code'], function ($c) { unset($c['send_at'], $c['send_by']); return $c; });
        return self::out(['ok' => true, 'scheduled' => false] + self::state($card));
    }

    // ═══════════════ Versand ═══════════════

    private static function schedule($code, $ts, $by) {
        wp_clear_scheduled_hook(self::HOOK, [(string) $code]);
        $card = self::update_card($code, function ($c) use ($ts, $by) { $c['send_at'] = wp_date('c', $ts); $c['send_by'] = $by; return $c; });
        wp_schedule_single_event($ts, self::HOOK, [(string) $code]);
        return $card;
    }

    /**
     * Gutschein an gift.recipient_email schicken. $opt: by, mode (manual|purchase|scheduled), copy_to.
     * → Gutschein (mit sent_to/sent_at) | WP_Error
     */
    public static function deliver($code, array $opt = []) {
        global $wpdb;
        $lock = 'tix_gcs_' . md5(strtoupper((string) $code));
        if (!intval($wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)))) return new WP_Error('tix_busy', 'Gerade viel los – bitte nochmal.', ['status' => 409]);
        try {
            wp_cache_delete('alloptions', 'options');
            $card = TIX_Giftcards::get_card((string) $code);
            if (!$card) return new WP_Error('tix_giftcard_unknown', 'Gutschein nicht gefunden.', ['status' => 404]);
            $to = (string) ($card['gift']['recipient_email'] ?? '');
            if (!is_email($to)) return new WP_Error('tix_invalid_field', 'Keine E-Mail der beschenkten Person hinterlegt.', ['status' => 400, 'field' => 'email']);
            $mail = self::build_mail($card);
            $ok = TIX_Mail_Layout::send($to, $mail['subject'], $mail['m'], ['attachments' => $mail['files']]);
            $copy = (string) ($opt['copy_to'] ?? '');
            if ($ok && is_email($copy) && strcasecmp($copy, $to) !== 0) {
                TIX_Mail_Layout::send($copy, 'Kopie: ' . $mail['subject'], array_merge($mail['m'], ['blocks' => array_merge(
                    [['type' => 'notice', 'tone' => 'info', 'html' => 'Kopie der E-Mail an <strong>' . esc_html($to) . '</strong>.']], $mail['m']['blocks'])]), ['attachments' => $mail['files']]);
            }
            if (class_exists('TIX_Giftcard_Gift')) TIX_Giftcard_Gift::cleanup($mail['files']);
            $now = wp_date('c');
            $entry = ['ts' => $now, 'to' => $to, 'by' => (string) ($opt['by'] ?? 'system'), 'mode' => (string) ($opt['mode'] ?? 'manual'), 'ok' => (bool) $ok];
            $card = self::update_card((string) $code, function ($c) use ($ok, $to, $now, $entry) {
                $c['sends'] = array_slice(array_merge((array) ($c['sends'] ?? []), [$entry]), -self::KEEP_SENDS);
                if ($ok) { $c['sent_to'] = $to; $c['sent_at'] = $now; unset($c['send_at'], $c['send_by']); }
                return $c;
            });
            if (!$ok) return new WP_Error('tix_mail_failed', 'Die E-Mail konnte gerade nicht verschickt werden. Bitte später nochmal.', ['status' => 502]);
            wp_clear_scheduled_hook(self::HOOK, [(string) $card['code']]);
            if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note') && !empty($card['order_id'])) {
                TIX_Order_Admin::add_note(intval($card['order_id']), sprintf('📨 Gutschein %s an %s geschickt', $card['code'], $to), 'system');
            }
            return $card;
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Nach der Bezahlung: neue Gutscheine mit gift.recipient_email sofort bzw. zum Termin verschicken. */
    public static function auto_for_order($order_id, array $codes) {
        foreach ($codes as $code) {
            $card = TIX_Giftcards::get_card((string) $code);
            $g = $card && is_array($card['gift'] ?? null) ? $card['gift'] : [];
            if (empty($g['recipient_email']) || !empty($card['sent_at'])) continue;
            $ts = !empty($g['send_at']) ? strtotime((string) $g['send_at']) : 0;
            if ($ts && $ts > time() + 60) {
                self::schedule((string) $code, $ts, 'buyer');
            } else {
                $r = self::deliver((string) $code, ['by' => 'buyer', 'mode' => 'purchase']);
                if (is_wp_error($r) && class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
                    TIX_Order_Admin::add_note(intval($order_id), sprintf('⚠️ Gutschein %s konnte nicht an %s geschickt werden: %s', $code, $g['recipient_email'], $r->get_error_message()), 'system');
                }
            }
        }
    }

    /** Geplanter Versand fällig (Einzel-Termin oder stündlicher Nachlauf). */
    public static function run_due($code) {
        $card = class_exists('TIX_Giftcards') ? TIX_Giftcards::get_card((string) $code) : null;
        if (!$card || empty($card['send_at'])) return false;
        $ts = strtotime((string) $card['send_at']);
        if ($ts > time() + 30) return false; // verschoben – der neue Termin läuft separat
        if (self::expired($card) || round(floatval($card['balance'] ?? 0), 2) <= 0) {
            self::update_card((string) $code, function ($c) {
                $c['sends'] = array_slice(array_merge((array) ($c['sends'] ?? []), [['ts' => wp_date('c'), 'to' => (string) ($c['gift']['recipient_email'] ?? ''), 'by' => 'system', 'mode' => 'scheduled', 'ok' => false, 'reason' => 'abgelaufen oder aufgebraucht']]), -self::KEEP_SENDS);
                unset($c['send_at'], $c['send_by']);
                return $c;
            });
            return false;
        }
        $res = self::deliver((string) $code, ['by' => (string) ($card['send_by'] ?? 'system'), 'mode' => 'scheduled']);
        if (is_wp_error($res)) return false;
        self::notify_buyer($res);
        return true;
    }

    /** Stündlich: überfällige geplante Versände (falls der Einzel-Termin von WP-Cron verpasst wurde). */
    public static function sweep() {
        wp_cache_delete('tix_coupons', 'options');
        $n = 0;
        foreach ((array) get_option('tix_coupons', []) as $k => $c) {
            if (($c['discount_type'] ?? '') !== 'giftcard' || empty($c['send_at'])) continue;
            if (strtotime((string) $c['send_at']) <= time() && self::run_due((string) $k)) $n++;
            if ($n >= 20) break;
        }
        return $n;
    }

    /** Käufer: kurze Bestätigung nach einem geplanten Versand. */
    private static function notify_buyer($card) {
        $o = !empty($card['order_id']) && class_exists('TIX_Order') ? TIX_Order::get(intval($card['order_id'])) : null;
        $to = $o ? (string) $o->get_billing_email() : '';
        if (!is_email($to) || strcasecmp($to, (string) $card['sent_to']) === 0 || !class_exists('TIX_Mail_Layout')) return;
        $g = is_array($card['gift'] ?? null) ? $card['gift'] : [];
        $name = ($g['recipient_name'] ?? '') !== '' ? $g['recipient_name'] : $card['sent_to'];
        TIX_Mail_Layout::send($to, TIX_Mail_Layout::brand()['name'] . ' – Dein Gutschein ist verschickt', [
            'title' => 'Dein Gutschein ist unterwegs',
            'blocks' => [
                ['type' => 'text', 'html' => 'Hallo' . ($o->get_billing_first_name() ? ' ' . esc_html($o->get_billing_first_name()) : '') . ',<br>wie geplant haben wir deinen Gutschein über <strong>' . esc_html(self::eur($card['value'] ?? 0)) . '</strong> an <strong>' . esc_html($name) . '</strong> geschickt.'],
                ['type' => 'info', 'rows' => [['Gutschein', esc_html($card['code'])], ['Verschickt an', esc_html($card['sent_to'])], ['Am', esc_html(wp_date('d.m.Y, H:i', strtotime($card['sent_at'])) . ' Uhr')]]],
            ],
            'kind' => 'customer',
        ]);
    }

    private static function eur($v) {
        $s = number_format(floatval($v), 2, ',', '.');
        return (substr($s, -3) === ',00' ? substr($s, 0, -3) : $s) . ' €';
    }

    /** Mail an die beschenkte Person → ['subject', 'm' (TIX_Mail_Layout::render), 'files' (PDF, temporär)]. */
    public static function build_mail($card) {
        $code = (string) $card['code'];
        $g = is_array($card['gift'] ?? null) ? $card['gift'] : [];
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand() : ['name' => html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8'), 'ios' => '', 'android' => ''];
        $oid = intval($card['organizer_id'] ?? 0);
        $where = $oid ? html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8') : (string) $brand['name'];
        $from = trim((string) ($g['from_name'] ?? ''));
        $rec = trim((string) ($g['recipient_name'] ?? ''));
        $value = self::eur($card['value'] ?? 0);
        $balance = round(floatval($card['balance'] ?? 0), 2);
        $exp = !empty($card['expires']) ? date_i18n('d.m.Y', strtotime($card['expires'])) : '';
        $blocks = [];
        $blocks[] = ['type' => 'text', 'html' => 'Hallo' . ($rec !== '' ? ' ' . esc_html($rec) : '') . ',<br>'
            . ($from !== '' ? '<strong>' . esc_html($from) . '</strong> schenkt dir' : 'du bekommst') . ' einen Gutschein über <strong>' . esc_html($value) . '</strong> bei <strong>' . esc_html($where) . '</strong>. 🎁'];
        if (($g['message'] ?? '') !== '') {
            $blocks[] = ['type' => 'notice', 'tone' => 'success', 'html' => '<em>„' . nl2br(esc_html((string) $g['message'])) . '“</em>' . ($from !== '' ? '<br>– ' . esc_html($from) : '')];
        }
        $blocks[] = ['type' => 'qr', 'src' => class_exists('TIX_QR') ? TIX_QR::url($code, 480) : '', 'value' => $code, 'size' => 240,
            'caption' => $exp !== '' ? 'Gültig bis ' . $exp : ''];
        $rows = [['Betrag', esc_html($value)]];
        if ($balance < round(floatval($card['value'] ?? 0), 2)) $rows[] = ['Noch drauf', esc_html(self::eur($balance))];
        if ($exp !== '') $rows[] = ['Gültig bis', esc_html($exp)];
        $rows[] = ['Gilt bei', esc_html($where)];
        $blocks[] = ['type' => 'info', 'rows' => $rows];
        $steps = TIX_Giftcard_Gift::redeem_steps($card);
        $html = '<strong>So löst du deinen Gutschein ein:</strong>';
        foreach ($steps as $i => $st) $html .= '<br><br>' . (count($steps) > 1 ? ($i + 1) . '. ' : '') . esc_html($st);
        $blocks[] = ['type' => 'text', 'html' => $html];
        if (TIX_Giftcard_Gift::app_redeem($card)) {
            if (!empty($brand['ios'])) $blocks[] = ['type' => 'button', 'label' => 'Im App Store laden', 'url' => (string) $brand['ios']];
            if (!empty($brand['android'])) $blocks[] = ['type' => 'button', 'label' => 'Bei Google Play laden', 'url' => (string) $brand['android']];
        }
        // PDF im Anhang
        $files = [];
        $pdf = TIX_Giftcard_Gift::pdf($code);
        if ($pdf) {
            $dir = trailingslashit(wp_get_upload_dir()['basedir']) . 'tix-private-tmp';
            if (!is_dir($dir) && wp_mkdir_p($dir)) @file_put_contents($dir . '/.htaccess', "deny from all\n");
            $f = $dir . '/' . wp_generate_password(12, false) . '-' . TIX_Giftcard_Gift::filename($code);
            if (@file_put_contents($f, $pdf) !== false) $files[] = $f;
        }
        if ($files) $blocks[] = ['type' => 'notice', 'tone' => 'info', 'html' => 'Den Gutschein zum Ausdrucken findest du als PDF im Anhang.'];
        $subject = $brand['name'] . ' – ' . ($from !== '' ? $from . ' schenkt dir einen Gutschein' : 'Ein Gutschein für dich');
        return [
            'subject' => $subject,
            'files'   => $files,
            'm'       => [
                'title'     => $from !== '' ? $from . ' schenkt dir einen Gutschein' : 'Ein Gutschein für dich',
                'subtitle'  => $value . ' · ' . $where,
                'preheader' => 'Dein Gutschein über ' . $value . ' – so löst du ihn ein.',
                'blocks'    => $blocks,
                'kind'      => 'customer',
            ],
        ];
    }
}

TIX_Giftcard_Send::init();
