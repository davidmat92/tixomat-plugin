<?php
/**
 * Tixomat – Support: E-Mail-Eingang ins Ticket + Antwort-Kennzeichen
 *
 * Ausgehend: Jede Support-Mail an Kunden bekommt [#ID] im Betreff, Reply-To =
 * Support-Postfach, eine signierte Message-ID (tixsp.<id>.<rand>.<sig>@host),
 * In-Reply-To/References auf frühere Mails der Anfrage und eine signierte
 * Referenz im Text (TIX-<id>-<sig>). Dazu Knopf „Im Support antworten“.
 *
 * Eingehend: WP-Cron ruft das Postfach alle 2 Minuten per IMAP ab
 * (TIX_Support_IMAP, reine Sockets), ordnet Antworten der Anfrage zu und
 * speichert sie als Kundennachricht – wie eine Antwort im Portal.
 *
 * Option tix_support_mail (eigene Option, nicht in tix_settings, damit das
 * verschlüsselte Passwort beim Speichern der Einstellungen nicht verloren geht).
 *
 * @since 1.38.340
 */
if (!defined('ABSPATH')) exit;

class TIX_Support_Mail {

    const OPTION   = 'tix_support_mail';
    const STATE    = 'tix_support_mail_state';
    const LOG      = 'tix_support_mail_log';
    const LOCK     = 'tix_support_mail_lock';
    const HOOK     = 'tix_support_mail_fetch';
    const MARKER   = '##- Bitte oberhalb dieser Zeile antworten -##';

    const LOCK_TTL        = 300;                 // s
    const RUN_BUDGET      = 45;                  // s je Cron-Lauf
    const PER_RUN         = 30;                  // Mails je Cron-Lauf
    const MAX_MAIL_BYTES  = 30 * 1024 * 1024;    // größere Mails: nur Kopf + Hinweis
    const MAX_FILE_BYTES  = 10 * 1024 * 1024;    // je Anhang
    const MAX_FILES       = 10;                  // je Mail
    const MIN_INLINE_IMG  = 15 * 1024;           // kleinere Inline-Bilder = Signatur-Logos
    const NEW_PER_SENDER  = 5;                   // neue Anfragen je Absender und Stunde

    private static $pending_mid = '';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::HOOK, [__CLASS__, 'cron_fetch']);
        add_action('admin_init', [__CLASS__, 'ensure_schedule']);
        add_action('phpmailer_init', [__CLASS__, 'apply_message_id'], 999);

        add_action('admin_post_tix_support_mail_save', [__CLASS__, 'handle_save']);
        add_action('wp_ajax_tix_support_mail_test',     [__CLASS__, 'ajax_test']);
        add_action('wp_ajax_tix_support_mail_run',      [__CLASS__, 'ajax_run']);
        add_action('wp_ajax_tix_support_mail_backfill', [__CLASS__, 'ajax_backfill']);
    }

    // ══════════════════════════════════════════════
    // EINSTELLUNGEN
    // ══════════════════════════════════════════════

    public static function defaults() {
        return [
            'enabled'      => 0,
            'address'      => '',          // Support-Postfach (Reply-To)
            'host'         => '',
            'port'         => 993,
            'encryption'   => 'ssl',       // ssl | tls | none
            'user'         => '',
            'password_enc' => '',          // AES-256-GCM, siehe encrypt()
            'folder'       => 'INBOX',
            'after'        => 'seen',      // seen | move
            'move_folder'  => 'Tixomat-Verarbeitet',
            'unmatched'    => 'ticket',    // ticket | ignore
            'portal_url'   => '',          // leer = Seite mit [tix_support] suchen
            'app_link'     => '',          // z. B. kitchenklub://support/{id}
        ];
    }

    public static function settings() {
        $s = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($s) ? $s : []);
    }

    /** Eingang aktiv = Support an + Schalter an + Zugangsdaten vollständig */
    public static function is_active() {
        if (!function_exists('tix_get_settings') || !tix_get_settings('support_enabled')) return false;
        $s = self::settings();
        return !empty($s['enabled']) && $s['host'] !== '' && $s['user'] !== '' && $s['password_enc'] !== '';
    }

    /** Adresse, an die Kunden antworten (Reply-To) */
    public static function support_address() {
        $s = self::settings();
        if (is_email($s['address'])) return $s['address'];
        if (is_email($s['user'])) return $s['user'];
        return '';
    }

    // ── Passwort-Verschlüsselung ──

    private static function crypto_key() {
        return hash('sha256', 'tix-sp-mail-pass|' . wp_salt('secure_auth'), true);
    }

    public static function encrypt($plain) {
        if (!function_exists('openssl_encrypt')) return '';
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt((string) $plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return '';
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt($enc) {
        if (!is_string($enc) || strpos($enc, 'v1:') !== 0 || !function_exists('openssl_decrypt')) return '';
        $bin = base64_decode(substr($enc, 3), true);
        if ($bin === false || strlen($bin) < 29) return '';
        $pt = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $pt === false ? '' : $pt;
    }

    // ══════════════════════════════════════════════
    // KENNZEICHEN (signiert, je Seite eigener Schlüssel)
    // ══════════════════════════════════════════════

    private static function secret() {
        return hash('sha256', 'tix-sp-mail-token|' . wp_salt('auth'));
    }

    public static function sign($data) {
        return substr(hash_hmac('sha256', (string) $data, self::secret()), 0, 16);
    }

    /** Sichtbare Referenz im Mailtext, übersteht meist das Zitat in der Antwort */
    public static function ref_token($ticket_id) {
        $id = intval($ticket_id);
        return 'TIX-' . $id . '-' . self::sign('ref|' . $id);
    }

    public static function subject_tag($ticket_id) {
        return '[#' . intval($ticket_id) . ']';
    }

    private static function mail_host() {
        $addr = self::support_address();
        if ($addr && strpos($addr, '@') !== false) return strtolower(substr(strrchr($addr, '@'), 1));
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return $host ? strtolower($host) : 'localhost';
    }

    public static function new_message_id($ticket_id) {
        $id   = intval($ticket_id);
        $rand = bin2hex(random_bytes(4));
        return '<tixsp.' . $id . '.' . $rand . '.' . self::sign('mid|' . $id . '|' . $rand) . '@' . self::mail_host() . '>';
    }

    /** Ticket-ID aus einer unserer Message-IDs (0 = keine/gefälscht) */
    public static function ticket_from_message_id($mid) {
        if (!preg_match('/^<?tixsp\.(\d{1,10})\.([a-f0-9]{8})\.([a-f0-9]{16})@/i', trim((string) $mid), $m)) return 0;
        return hash_equals(self::sign('mid|' . intval($m[1]) . '|' . strtolower($m[2])), strtolower($m[3])) ? intval($m[1]) : 0;
    }

    /** Ticket-IDs aus gültigen TIX-<id>-<sig>-Referenzen in einem Text */
    public static function tickets_from_ref_tokens($text) {
        $ids = [];
        if (preg_match_all('/TIX-(\d{1,10})-([a-f0-9]{16})/i', (string) $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                if (hash_equals(self::sign('ref|' . intval($m[1])), strtolower($m[2]))) $ids[] = intval($m[1]);
            }
        }
        return array_values(array_unique($ids));
    }

    // ══════════════════════════════════════════════
    // AUSGEHEND: Mails an Kunden
    // ══════════════════════════════════════════════

    /** Portal-URL: Einstellung oder erste veröffentlichte Seite mit [tix_support] */
    public static function portal_url() {
        $s = self::settings();
        if (!empty($s['portal_url'])) return $s['portal_url'];
        $cached = get_transient('tix_sp_portal_url');
        if ($cached !== false) return $cached === 'none' ? '' : $cached;
        global $wpdb;
        $id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE '%[tix\\_support%' ORDER BY post_type = 'page' DESC, ID ASC LIMIT 1");
        if (!$id) {
            // Page-Builder (Breakdance, Elementor) speichern den Shortcode in Meta-Feldern
            $id = $wpdb->get_var("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_status = 'publish' AND p.post_type = 'page' AND m.meta_key IN ('_breakdance_data','_elementor_data') AND m.meta_value LIKE '%[tix\\_support%' ORDER BY p.ID ASC LIMIT 1");
        }
        $url = $id ? get_permalink(intval($id)) : '';
        set_transient('tix_sp_portal_url', $url ?: 'none', 12 * HOUR_IN_SECONDS);
        return $url ?: '';
    }

    /** Link ins Portal, öffnet direkt die Anfrage (access_key statt E-Mail in der URL) */
    public static function portal_link($ticket_id) {
        $base = self::portal_url();
        if (!$base || !class_exists('TIX_Support')) return '';
        $key = TIX_Support::ensure_ticket_access_key($ticket_id);
        if (!$key) return '';
        return add_query_arg(['tix_sp_ticket' => intval($ticket_id), 'tix_sp_key' => $key], $base) . '#tix-sp-frontend';
    }

    /** App-Deep-Link aus der Vorlage ({id}), leer wenn nicht eingerichtet */
    public static function app_link($ticket_id) {
        $tpl = trim((string) self::settings()['app_link']);
        if ($tpl === '' || strpos($tpl, '{id}') === false) return '';
        return str_replace('{id}', (string) intval($ticket_id), $tpl);
    }

    /** Zeile ganz oben in der Mail (Schnittkante für das Zitat der Kundenantwort) */
    public static function body_top_html() {
        if (!self::is_active()) return '';
        return '<p style="margin:0 0 14px;font-size:11px;line-height:1.4;color:#9ca3af;">' . esc_html(self::MARKER) . '</p>';
    }

    /** Antwort-Block unten: Knopf ins Portal, App-Link, Hinweis „einfach antworten“, Referenz */
    public static function body_footer_html($ticket_id) {
        $portal = self::portal_link($ticket_id);
        $app    = self::app_link($ticket_id);
        $active = self::is_active();
        $html   = '';
        if ($portal || $app || $active) {
            $html .= '<div style="margin:24px 0 8px;padding:18px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;text-align:center;">';
            if ($portal) {
                $html .= '<a href="' . esc_url($portal) . '" style="display:inline-block;padding:12px 24px;' . (function_exists('tix_btn_style') ? tix_btn_style() : 'background:#111;color:#fff;border-radius:8px;') . 'text-decoration:none;font-weight:700;">Im Support antworten</a>';
            }
            if ($app) {
                $html .= '<p style="margin:10px 0 0;font-size:13px;"><a href="' . esc_url($app, ['http', 'https', preg_replace('/[^a-z0-9+.-].*$/i', '', $app)]) . '" style="color:#2563eb;text-decoration:none;">In der App öffnen →</a></p>';
            }
            if ($active) {
                $html .= '<p style="margin:' . ($portal || $app ? '12px' : '0') . ' 0 0;font-size:13px;color:#64748b;">Du kannst auch einfach auf diese E-Mail antworten – deine Nachricht landet direkt bei deiner Anfrage #' . intval($ticket_id) . '.</p>';
            }
            $html .= '</div>';
        }
        $html .= '<p style="margin:8px 0 0;font-size:11px;color:#9ca3af;">Anfrage-Referenz: ' . esc_html(self::ref_token($ticket_id)) . '</p>';
        return $html;
    }

    /**
     * Support-Mail an den Kunden senden – mit Reply-To, signierter Message-ID
     * und Threading. $kind: received | reply | resolved
     */
    public static function send_customer_mail($ticket_id, $to, $subject, $html, $kind, $attachments = []) {
        $ticket_id = intval($ticket_id);
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'X-Tixomat-Support: ' . sanitize_key($kind) . '; ticket=' . $ticket_id,
        ];
        if (self::is_active() && ($addr = self::support_address())) {
            $headers[] = 'Reply-To: ' . $addr;
        }
        $thread = get_post_meta($ticket_id, '_tix_sp_mail_thread', true);
        if (!is_array($thread)) $thread = [];
        if ($thread) {
            $headers[] = 'In-Reply-To: ' . end($thread);
            $headers[] = 'References: ' . implode(' ', array_slice($thread, -10));
        }
        if ($kind !== 'reply') {
            // Automatische Mails: Abwesenheitsnotizen sollen darauf nicht antworten
            $headers[] = 'Auto-Submitted: auto-generated';
        }
        $mid = self::new_message_id($ticket_id);
        self::$pending_mid = $mid;
        $ok = wp_mail($to, $subject, $html, $headers, $attachments);
        self::$pending_mid = '';
        $thread[] = $mid;
        update_post_meta($ticket_id, '_tix_sp_mail_thread', array_slice($thread, -20));
        return $ok;
    }

    /** Header für Team-Benachrichtigungen (Schleifenschutz, falls admin_email = Support-Postfach) */
    public static function team_headers() {
        return ['Content-Type: text/html; charset=UTF-8', 'X-Tixomat-Support: team'];
    }

    public static function apply_message_id($phpmailer) {
        if (self::$pending_mid !== '' && is_object($phpmailer)) {
            $phpmailer->MessageID = self::$pending_mid;
        }
    }

    // ══════════════════════════════════════════════
    // CRON
    // ══════════════════════════════════════════════

    public static function cron_schedules($s) {
        if (!isset($s['tix_every_2min'])) {
            $s['tix_every_2min'] = ['interval' => 120, 'display' => 'Alle 2 Minuten (Tixomat)'];
        }
        return $s;
    }

    public static function ensure_schedule() {
        $next = wp_next_scheduled(self::HOOK);
        if (self::is_active()) {
            if (!$next) wp_schedule_event(time() + 30, 'tix_every_2min', self::HOOK);
        } elseif ($next) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    public static function cron_fetch() {
        if (!self::is_active()) return;
        self::fetch();
    }

    private static function lock() {
        $now = time();
        if (add_option(self::LOCK, $now + self::LOCK_TTL, '', 'no')) return true;
        $until = intval(get_option(self::LOCK));
        if ($until && $until > $now) return false;
        // Abgelaufene Sperre (abgebrochener Lauf) übernehmen
        delete_option(self::LOCK);
        return add_option(self::LOCK, $now + self::LOCK_TTL, '', 'no');
    }

    private static function unlock() {
        delete_option(self::LOCK);
    }

    private static function state() {
        $s = get_option(self::STATE, []);
        return is_array($s) ? $s : [];
    }

    private static function save_state(array $s) {
        update_option(self::STATE, $s, false);
    }

    private static function mailbox_ident(array $s) {
        return md5(strtolower($s['host'] . '|' . $s['user'] . '|' . $s['folder']));
    }

    /**
     * Verbindung mit den gespeicherten (oder übergebenen) Zugangsdaten.
     * @throws RuntimeException
     */
    private static function connect(?array $s = null) {
        $s = $s ?: self::settings();
        $pass = isset($s['password']) ? (string) $s['password'] : self::decrypt($s['password_enc']);
        if ($pass === '') throw new RuntimeException('Kein (lesbares) Passwort gespeichert – bitte neu eingeben.');
        if (!class_exists('TIX_Support_IMAP')) require_once __DIR__ . '/class-tix-support-imap.php';
        $imap = new TIX_Support_IMAP();
        $verify = (bool) apply_filters('tix_support_mail_ssl_verify', true);
        $imap->connect($s['host'], intval($s['port']), $s['encryption'], 20, $verify);
        $imap->login($s['user'], $pass);
        return $imap;
    }

    /**
     * Neue Mails abrufen (ab der zuletzt verarbeiteten UID).
     * Beim allerersten Lauf wird nur die Startposition gemerkt – Altbestand
     * kommt ausschließlich über den Rückwärts-Import.
     */
    public static function fetch($limit = self::PER_RUN) {
        if (!self::lock()) return ['ok' => false, 'message' => 'Abruf läuft bereits.'];
        $started = time();
        $state   = self::state();
        $sum     = ['imported' => 0, 'skipped' => 0, 'checked' => 0];
        $imap    = null;
        try {
            $s     = self::settings();
            $imap  = self::connect($s);
            $sel   = $imap->select($s['folder']);
            $ident = self::mailbox_ident($s);

            if (($state['ident'] ?? '') !== $ident || intval($state['uidvalidity'] ?? 0) !== $sel['uidvalidity'] || !isset($state['last_uid'])) {
                $state = array_merge($state, [
                    'ident'       => $ident,
                    'uidvalidity' => $sel['uidvalidity'],
                    'last_uid'    => max(0, $sel['uidnext'] - 1),
                    'since'       => time(),
                ]);
                $state['last_run']   = time();
                $state['last_error'] = '';
                self::save_state($state);
                self::log(['result' => 'start', 'reason' => 'Startposition gemerkt (UID ' . $state['last_uid'] . '). Ältere Mails nur per Rückwärts-Import.']);
                return ['ok' => true, 'message' => 'Postfach verbunden. Ab jetzt werden neue Mails übernommen.'] + $sum;
            }

            $last = intval($state['last_uid']);
            $uids = array_values(array_filter($imap->uid_search('UID ' . ($last + 1) . ':*'), function ($u) use ($last) { return $u > $last; }));
            foreach (array_slice($uids, 0, max(1, intval($limit))) as $uid) {
                if (time() - $started > self::RUN_BUDGET) break;
                $res = self::process_uid($imap, $uid, ['mode' => 'cron']);
                $sum['checked']++;
                $res['result'] === 'imported' ? $sum['imported']++ : $sum['skipped']++;
                $state['last_uid'] = $uid;
                self::save_state($state);
            }
            $state['last_run']   = time();
            $state['last_error'] = '';
            $state['imported_total'] = intval($state['imported_total'] ?? 0) + $sum['imported'];
            self::save_state($state);
            return ['ok' => true, 'message' => $sum['checked'] ? ($sum['imported'] . ' übernommen, ' . $sum['skipped'] . ' übersprungen.') : 'Keine neuen Mails.'] + $sum;
        } catch (\Throwable $e) {
            $state['last_run']   = time();
            $state['last_error'] = $e->getMessage();
            self::save_state($state);
            self::log(['result' => 'error', 'reason' => $e->getMessage()]);
            return ['ok' => false, 'message' => $e->getMessage()] + $sum;
        } finally {
            if ($imap) $imap->logout();
            self::unlock();
        }
    }

    /**
     * Rückwärts-Import: Mails der letzten $days Tage, mit Duplikatschutz.
     * $allow_new = false: nur Antworten zu bestehenden Anfragen (Vorgabe –
     * sonst würde ein gemeinsames Postfach jede alte Mail zur Anfrage machen).
     * Läuft in Häppchen (after_uid), die Admin-Oberfläche ruft bis „fertig“.
     */
    public static function backfill($days = 30, $allow_new = false, $after_uid = 0) {
        if (!self::lock()) return ['ok' => false, 'message' => 'Ein Abruf läuft gerade – bitte gleich noch einmal.'];
        $started = time();
        $imap = null;
        $sum = ['imported' => 0, 'skipped' => 0, 'checked' => 0, 'total' => 0, 'more' => false, 'next_after' => intval($after_uid), 'tickets' => []];
        try {
            $s    = self::settings();
            $imap = self::connect($s);
            $imap->select($s['folder']);
            $since = gmdate('j-M-Y', time() - max(1, min(365, intval($days))) * DAY_IN_SECONDS);
            $uids  = $imap->uid_search('SINCE ' . $since);
            $sum['total'] = count($uids);
            $after = intval($after_uid);
            foreach ($uids as $uid) {
                if ($uid <= $after) continue;
                if (time() - $started > 20) { $sum['more'] = true; break; }
                $res = self::process_uid($imap, $uid, ['mode' => 'backfill', 'allow_new' => (bool) $allow_new, 'notify' => false, 'confirm' => false]);
                $sum['checked']++;
                if ($res['result'] === 'imported') {
                    $sum['imported']++;
                    if (!empty($res['ticket'])) $sum['tickets'][] = intval($res['ticket']);
                } else {
                    $sum['skipped']++;
                }
                $sum['next_after'] = $uid;
            }
            $sum['tickets'] = array_values(array_unique($sum['tickets']));
            return ['ok' => true] + $sum;
        } catch (\Throwable $e) {
            self::log(['result' => 'error', 'reason' => 'Rückwärts-Import: ' . $e->getMessage()]);
            return ['ok' => false, 'message' => $e->getMessage()] + $sum;
        } finally {
            if ($imap) $imap->logout();
            self::unlock();
        }
    }

    /** Eine Mail im Postfach prüfen, ggf. laden, übernehmen und markieren */
    private static function process_uid(TIX_Support_IMAP $imap, $uid, array $ctx) {
        $head    = $imap->fetch_header($uid);
        $headers = TIX_Support_Mime::parse_headers_only($head);
        $pre     = self::precheck($headers);
        if ($pre) {
            self::log(['uid' => $uid, 'from' => self::from_of($headers), 'subject' => TIX_Support_Mime::header_decoded($headers, 'subject'), 'result' => $pre['result'], 'reason' => $pre['reason'], 'ticket' => $pre['ticket'] ?? 0]);
            return $pre;
        }
        $size = $imap->fetch_size($uid);
        if ($size > self::MAX_MAIL_BYTES) {
            $raw = rtrim($head) . "\r\n\r\n";
            $ctx['oversize'] = $size;
        } else {
            $raw = $imap->fetch_raw($uid);
        }
        $res = self::process_raw($raw, $ctx);
        if ($res['result'] === 'imported') {
            $s = self::settings();
            try {
                if ($s['after'] === 'move' && trim($s['move_folder']) !== '') {
                    $imap->move($uid, trim($s['move_folder']));
                } else {
                    $imap->mark_seen($uid);
                }
            } catch (\Throwable $e) {
                $res['reason'] = trim(($res['reason'] ?? '') . ' (Markieren fehlgeschlagen: ' . $e->getMessage() . ')');
            }
        }
        self::log(['uid' => $uid] + $res);
        return $res;
    }

    private static function from_of(array $headers) {
        $a = TIX_Support_Mime::parse_address(TIX_Support_Mime::header_decoded($headers, 'from'));
        return $a['email'];
    }

    /** Prüfung allein anhand der Kopfzeilen: Duplikat, eigene Mail, Automatik */
    private static function precheck(array $headers) {
        $mid  = TIX_Support_Mime::message_ids(TIX_Support_Mime::header($headers, 'message-id'));
        $hash = $mid ? self::hash_id($mid[0]) : '';
        if ($hash && ($tid = self::find_duplicate($hash))) {
            return ['result' => 'duplicate', 'reason' => 'Bereits übernommen', 'ticket' => $tid];
        }
        $from    = self::from_of($headers);
        $subject = TIX_Support_Mime::header_decoded($headers, 'subject');
        if ($why = self::is_own_mail($headers, $from)) return ['result' => 'skipped_loop', 'reason' => $why];
        if ($why = self::detect_automatic($headers, $from, $subject)) return ['result' => 'skipped_auto', 'reason' => $why];
        return null;
    }

    // ══════════════════════════════════════════════
    // VERARBEITUNG EINER MAIL
    // ══════════════════════════════════════════════

    /**
     * Rohmail verarbeiten. Ergebnis: imported | duplicate | skipped_loop |
     * skipped_auto | skipped_invalid | ignored_unmatched | ratelimited | error
     * $ctx: mode (cron|backfill), allow_new, notify, confirm, oversize
     */
    public static function process_raw($raw, array $ctx = []) {
        $s   = self::settings();
        $ctx = array_merge([
            'mode'      => 'cron',
            'allow_new' => $s['unmatched'] === 'ticket',
            'notify'    => true,
            'confirm'   => true,
            'oversize'  => 0,
        ], $ctx);

        $mail    = TIX_Support_Mime::parse($raw);
        $h       = $mail['headers'];
        $from    = TIX_Support_Mime::parse_address(TIX_Support_Mime::header_decoded($h, 'from'));
        $subject = trim(TIX_Support_Mime::header_decoded($h, 'subject'));
        $mids    = TIX_Support_Mime::message_ids(TIX_Support_Mime::header($h, 'message-id'));
        $date_ts = strtotime(TIX_Support_Mime::header($h, 'date')) ?: time();
        if ($date_ts > time() + 300) $date_ts = time();
        $hash    = $mids ? self::hash_id($mids[0]) : sha1(strtolower($from['email'] . '|' . TIX_Support_Mime::header($h, 'date') . '|' . $subject . '|' . strlen($raw)));
        $base    = ['from' => $from['email'], 'subject' => $subject];

        if ($tid = self::find_duplicate($hash)) {
            return $base + ['result' => 'duplicate', 'reason' => 'Bereits übernommen', 'ticket' => $tid];
        }
        if ($why = self::is_own_mail($h, $from['email'])) {
            return $base + ['result' => 'skipped_loop', 'reason' => $why];
        }
        if ($why = self::detect_automatic($h, $from['email'], $subject)) {
            return $base + ['result' => 'skipped_auto', 'reason' => $why];
        }
        if (!is_email($from['email'])) {
            return $base + ['result' => 'skipped_invalid', 'reason' => 'Kein gültiger Absender'];
        }

        list($ticket_id, $how) = self::match_ticket($mail, $from['email'], $subject);

        $content = self::extract_reply($mail['text'], $mail['html']);
        if ($ctx['oversize']) {
            $content = trim($content . "\n\n[Mail zu groß für die Übernahme (" . size_format($ctx['oversize']) . ") – bitte im Postfach ansehen.]");
        }
        $files = self::pick_attachments($mail['attachments']);
        if ($content === '' && !$files['keep']) {
            $content = '(leere Nachricht)';
        }

        $is_new = false;
        if (!$ticket_id) {
            if (!$ctx['allow_new']) {
                return $base + ['result' => 'ignored_unmatched', 'reason' => 'Keiner Anfrage zuzuordnen'];
            }
            if (!self::rate_ok($from['email'])) {
                return $base + ['result' => 'ratelimited', 'reason' => 'Zu viele neue Anfragen von diesem Absender'];
            }
            $ticket_id = TIX_Support::create_ticket_from_email([
                'email'   => $from['email'],
                'name'    => $from['name'],
                'subject' => self::clean_subject($subject) ?: 'Anfrage per E-Mail',
                'date_ts' => $date_ts,
            ]);
            if (is_wp_error($ticket_id) || !$ticket_id) {
                return $base + ['result' => 'error', 'reason' => 'Anfrage konnte nicht angelegt werden'];
            }
            $is_new = true;
            $how = 'new';
        }

        // Anhänge speichern (Ticket-ID steht jetzt fest)
        $stored = [];
        foreach ($files['keep'] as $f) {
            $r = TIX_Support::store_attachment_data($ticket_id, $f['name'], $f['data']);
            if (!is_wp_error($r)) $stored[] = ['url' => $r['url'], 'name' => $r['name'], 'mime' => $r['mime']];
            else $files['dropped'][] = $f['name'] . ' (' . $r->get_error_message() . ')';
        }
        if ($content === '' ) $content = '(Anhang)';

        $ticket_email = (string) get_post_meta($ticket_id, '_tix_sp_email', true);
        $notes = [];
        if (!$is_new && strcasecmp($ticket_email, $from['email']) !== 0) {
            $notes[] = 'Antwort per E-Mail von abweichender Adresse ' . $from['email'] . ' (zugeordnet über das Kennzeichen der Support-Mail).';
        }
        if ($files['dropped']) {
            $notes[] = 'Nicht übernommene Anhänge: ' . implode(', ', $files['dropped']) . ' – bitte im Postfach ansehen.';
        }

        $msg = [
            'id'          => 'msg_' . bin2hex(random_bytes(8)),
            'type'        => 'customer',
            'author'      => $from['name'] ?: (get_post_meta($ticket_id, '_tix_sp_name', true) ?: $from['email']),
            'email'       => $from['email'],
            'content'     => $content,
            'date'        => wp_date('c', $date_ts),
            'attachments' => $stored,
            'source'      => 'email',
        ];
        TIX_Support::add_email_message($ticket_id, $msg, [
            'new'     => $is_new,
            'notify'  => (bool) $ctx['notify'],
            'confirm' => (bool) $ctx['confirm'] && $ctx['mode'] === 'cron',
            'notes'   => $notes,
        ]);
        add_post_meta($ticket_id, '_tix_sp_mail_msgid', $hash);
        if ($is_new) self::rate_hit($from['email']);

        return $base + ['result' => 'imported', 'ticket' => intval($ticket_id), 'how' => $how, 'reason' => $is_new ? 'Neue Anfrage' : 'Zu #' . intval($ticket_id)];
    }

    /**
     * Zuordnung: 1) signierte Message-ID in In-Reply-To/References,
     * 2) signierte Referenz im Betreff/Text, 3) [#ID] (oder altes „Anfrage #ID“)
     * im Betreff UND Absender = Kunden-E-Mail der Anfrage.
     * @return array [ticket_id, how]
     */
    public static function match_ticket(array $mail, $from_email, $subject) {
        $h = $mail['headers'];
        $refs = array_merge(
            TIX_Support_Mime::message_ids(TIX_Support_Mime::header($h, 'in-reply-to')),
            array_reverse(TIX_Support_Mime::message_ids(TIX_Support_Mime::header($h, 'references')))
        );
        foreach ($refs as $r) {
            $id = self::ticket_from_message_id($r);
            if ($id && self::ticket_exists($id)) return [$id, 'message-id'];
        }

        $text = $subject . "\n" . $mail['text'] . "\n" . wp_strip_all_tags($mail['html']);
        foreach (self::tickets_from_ref_tokens($text) as $id) {
            if (self::ticket_exists($id)) return [$id, 'referenz'];
        }

        $cands = [];
        if (preg_match_all('/\[#(\d{1,10})\]/', $subject, $m)) $cands = array_merge($cands, $m[1]);
        if (preg_match_all('/Anfrage[^#\[\]\n]{0,40}#(\d{1,10})/iu', $subject, $m)) $cands = array_merge($cands, $m[1]);
        foreach (array_unique(array_map('intval', $cands)) as $id) {
            if (!self::ticket_exists($id)) continue;
            if (strcasecmp((string) get_post_meta($id, '_tix_sp_email', true), (string) $from_email) === 0) return [$id, 'betreff'];
        }
        return [0, ''];
    }

    private static function ticket_exists($id) {
        $p = $id ? get_post(intval($id)) : null;
        return $p && $p->post_type === 'tix_support_ticket' && in_array($p->post_status, ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'], true);
    }

    /** Eigene Mails nicht wieder einlesen (Schleifenschutz) */
    public static function is_own_mail(array $h, $from_email) {
        if (TIX_Support_Mime::header($h, 'x-tixomat-support') !== '') return 'Eigene Support-Mail';
        foreach (TIX_Support_Mime::message_ids(TIX_Support_Mime::header($h, 'message-id')) as $mid) {
            if (self::ticket_from_message_id($mid)) return 'Eigene Support-Mail';
        }
        $from_email = strtolower((string) $from_email);
        if ($from_email !== '') {
            $own = array_filter([strtolower(self::support_address()), strtolower((string) self::settings()['user'])]);
            if (in_array($from_email, $own, true)) return 'Absender ist das Support-Postfach';
        }
        return '';
    }

    /** Abwesenheitsnotizen, Bounces, Newsletter, Spam erkennen */
    public static function detect_automatic(array $h, $from_email, $subject) {
        $hv = function ($n) use ($h) { return strtolower(trim(TIX_Support_Mime::header($h, $n))); };

        $as = $hv('auto-submitted');
        if ($as !== '' && $as !== 'no') return 'Automatische Mail (Auto-Submitted)';
        foreach (['x-autoreply', 'x-autorespond', 'x-autoresponder', 'x-autogenerated'] as $k) {
            if ($hv($k) !== '') return 'Automatische Antwort (' . $k . ')';
        }
        if (preg_match('/\b(bulk|junk|list|auto_reply)\b/', $hv('precedence') . ' ' . $hv('x-precedence'))) return 'Massenmail (Precedence)';
        if ($hv('list-id') !== '' || $hv('list-unsubscribe') !== '') return 'Newsletter / Verteiler';
        if (strpos($hv('content-type'), 'multipart/report') !== false) return 'Zustellbericht (Bounce)';
        if ($hv('return-path') === '<>') return 'Zustellbericht (Bounce)';
        if (preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounces?)([@+._-])/i', (string) $from_email)) return 'Systemabsender';
        if (preg_match('/^yes/', $hv('x-spam-flag')) || preg_match('/^yes/', $hv('x-spam-status'))) return 'Spam';
        if (preg_match('/^\s*(automatische antwort|automatic reply|auto[- ]?reply|autoreply|abwesenheitsnotiz|out of (the )?office|ich bin (derzeit |zurzeit |momentan )?(nicht im büro|abwesend)|undeliverable|unzustellbar|nicht zustellbar|delivery status notification|mail delivery (failed|subsystem)|returned mail|undelivered mail|zustellung fehlgeschlagen|warning: could not send)/iu', (string) $subject)) {
            return 'Automatische Antwort (Betreff)';
        }
        return '';
    }

    // ── Text der Antwort (ohne Zitat und Signatur) ──

    public static function extract_reply($text, $html = '') {
        $text = (string) $text;
        if (trim($text) === '' && trim((string) $html) !== '') {
            $text = self::html_to_text($html);
        }
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $text  = str_replace("\xC2\xA0", ' ', $text);
        $lines = explode("\n", $text);
        $out   = [];
        $n     = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            $t    = trim($line);
            $next = $i + 1 < $n ? trim($lines[$i + 1]) : '';

            // Unsere Schnittkante
            if (stripos($t, 'Bitte oberhalb dieser Zeile antworten') !== false) break;
            // Zitat-Kopf: „Am 27.09.2026 um 10:00 schrieb KitchenKlub <…>:“ / „On … wrote:“ (auch zweizeilig)
            $quote_head = '/^(Am|On|Le|El|Il)\s.{4,}(schrieb|wrote|a écrit|escribió|ha scritto)\s*.{0,200}:$/iu';
            if (preg_match($quote_head, $t)) break;
            // Gmail bricht lange Zitatköpfe um („… schrieb KitchenKlub <“ / „info@…>:“)
            if (preg_match('/^(Am|On|Le|El|Il)\s/iu', $t) && strlen($t) < 250 && preg_match($quote_head, $t . ' ' . $next)) break;
            if (preg_match('/^.{0,120}<[^>]+@[^>]+>\s*(schrieb|wrote)\s*:$/iu', $t)) break;
            // Outlook & Co.
            if (preg_match('/^-{2,}\s*(Original Message|Ursprüngliche Nachricht|Originalnachricht|Weitergeleitete Nachricht|Forwarded message|Original-Nachricht)\s*-{2,}/iu', $t)) break;
            if (preg_match('/^_{10,}$/', $t)) break;
            if (preg_match('/^(Von|From)\s*:\s*\S/iu', $t)) {
                $look = implode("\n", array_slice($lines, $i + 1, 5));
                if (preg_match('/^\s*(Gesendet|Sent|Datum|Date|An|To|Betreff|Subject)\s*:/imu', $look)) break;
            }
            // Signatur-Trenner und Mobil-Fußzeilen
            if ($line === '-- ' || $t === '--' || $t === '-- ') break;
            if (preg_match('/^(Gesendet von meinem|Von meinem .{1,40} gesendet|Sent from my|Get Outlook for|Outlook für (iOS|Android) beziehen|Diese Nachricht wurde von meinem .{1,60} gesendet|Gesendet mit der .{1,40} App)/iu', $t)) break;
            // Zitierte Zeilen
            if (strpos(ltrim($line), '>') === 0) continue;
            $out[] = rtrim($line);
        }

        $res = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
        if ($res === '') {
            // Nichts übrig (z. B. nur Zitat, Antworten im Zitat) → Original behalten
            $res = trim(preg_replace("/\n{3,}/", "\n\n", $text));
        }
        if (function_exists('mb_substr') && mb_strlen($res) > 20000) $res = mb_substr($res, 0, 20000) . ' …';
        return sanitize_textarea_field($res);
    }

    /** HTML → Text; zitierte Blöcke (blockquote, gmail_quote, Outlook-Antwortkopf) fliegen vorher raus */
    public static function html_to_text($html) {
        $html = (string) $html;
        if (class_exists('DOMDocument')) {
            $prev = libxml_use_internal_errors(true);
            $doc  = new DOMDocument();
            if ($doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
                $xp = new DOMXPath($doc);
                $kill = [];
                foreach ($xp->query('//blockquote | //style | //script | //head | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")] | //*[contains(concat(" ", normalize-space(@class), " "), " moz-cite-prefix ")] | //*[contains(concat(" ", normalize-space(@class), " "), " yahoo_quoted ")]') as $node) {
                    $kill[] = $node;
                }
                // Outlook: ab #divRplyFwdMsg / #appendonsend alles Folgende
                foreach ($xp->query('//*[@id="divRplyFwdMsg" or @id="appendonsend" or @id="mail-editor-reference-message-container"]') as $node) {
                    $sib = $node;
                    while ($sib) { $kill[] = $sib; $sib = $sib->nextSibling; }
                }
                foreach ($kill as $node) {
                    if ($node->parentNode) $node->parentNode->removeChild($node);
                }
                $html = $doc->saveHTML();
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        } else {
            $html = preg_replace('#<blockquote\b.*?</blockquote>#is', '', $html);
        }
        $html = preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|table)>#i', "\n", $html);
        $html = preg_replace('#<hr[^>]*>#i', "\n" . str_repeat('_', 20) . "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** Anhänge filtern: Größe, Anzahl, sichere Typen, Signatur-Logos weg */
    private static function pick_attachments(array $atts) {
        $keep = []; $dropped = [];
        foreach ($atts as $a) {
            $is_img = strpos($a['mime'], 'image/') === 0;
            if ($is_img && $a['inline'] && $a['size'] < self::MIN_INLINE_IMG) continue; // Logo/Icon der Signatur
            if ($a['size'] === 0) continue;
            if ($a['size'] > self::MAX_FILE_BYTES) { $dropped[] = $a['name'] . ' (zu groß)'; continue; }
            if (count($keep) >= self::MAX_FILES)   { $dropped[] = $a['name'] . ' (zu viele Anhänge)'; continue; }
            if (!TIX_Support::is_allowed_attachment_data($a['data'])) { $dropped[] = $a['name'] . ' (Dateityp nicht erlaubt)'; continue; }
            $keep[] = $a;
        }
        return ['keep' => $keep, 'dropped' => $dropped];
    }

    private static function clean_subject($s) {
        $s = trim((string) $s);
        do {
            $before = $s;
            $s = trim(preg_replace('/^(re|aw|wg|fw|fwd|antw|sv|vs)\s*(\[\d+\])?\s*:\s*/iu', '', $s));
        } while ($s !== $before && $s !== '');
        return sanitize_text_field($s);
    }

    // ── Duplikate / Drosselung ──

    private static function hash_id($mid) {
        return sha1(strtolower(trim((string) $mid)));
    }

    private static function find_duplicate($hash) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tix_sp_mail_msgid' AND meta_value = %s LIMIT 1",
            $hash
        )));
    }

    private static function rate_ok($email) {
        return intval(get_transient('tix_sp_mail_rl_' . md5(strtolower($email)))) < self::NEW_PER_SENDER;
    }

    private static function rate_hit($email) {
        $k = 'tix_sp_mail_rl_' . md5(strtolower($email));
        set_transient($k, intval(get_transient($k)) + 1, HOUR_IN_SECONDS);
    }

    // ── Protokoll (letzte 100 Einträge, nie Zugangsdaten) ──

    private static function log(array $e) {
        $log = get_option(self::LOG, []);
        if (!is_array($log)) $log = [];
        $subject = (string) ($e['subject'] ?? '');
        array_unshift($log, [
            't'       => time(),
            'uid'     => intval($e['uid'] ?? 0),
            'from'    => sanitize_text_field((string) ($e['from'] ?? '')),
            'subject' => sanitize_text_field(function_exists('mb_substr') ? mb_substr($subject, 0, 90) : substr($subject, 0, 90)),
            'result'  => sanitize_key((string) ($e['result'] ?? '')),
            'ticket'  => intval($e['ticket'] ?? 0),
            'reason'  => sanitize_text_field((string) ($e['reason'] ?? '')),
        ]);
        update_option(self::LOG, array_slice($log, 0, 100), false);
    }

    // ══════════════════════════════════════════════
    // ADMIN: Speichern, Test, Abruf, Rückwärts-Import
    // ══════════════════════════════════════════════

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_support_mail_save');
        $in  = wp_unslash($_POST['tix_sp_mail'] ?? []);
        $old = self::settings();
        $new = $old;

        $new['enabled']     = !empty($in['enabled']) ? 1 : 0;
        $new['address']     = sanitize_email($in['address'] ?? '');
        $host = trim(sanitize_text_field($in['host'] ?? ''));
        $new['host']        = preg_replace('#^[a-z]+://#i', '', rtrim($host, '/'));
        $port = intval($in['port'] ?? 993);
        $new['port']        = ($port > 0 && $port < 65536) ? $port : 993;
        $new['encryption']  = in_array($in['encryption'] ?? '', ['ssl', 'tls', 'none'], true) ? $in['encryption'] : 'ssl';
        $new['user']        = trim(sanitize_text_field($in['user'] ?? ''));
        $new['folder']      = trim(sanitize_text_field($in['folder'] ?? 'INBOX')) ?: 'INBOX';
        $new['after']       = ($in['after'] ?? '') === 'move' ? 'move' : 'seen';
        $new['move_folder'] = trim(sanitize_text_field($in['move_folder'] ?? '')) ?: 'Tixomat-Verarbeitet';
        $new['unmatched']   = ($in['unmatched'] ?? '') === 'ignore' ? 'ignore' : 'ticket';
        $new['portal_url']  = esc_url_raw(trim($in['portal_url'] ?? ''));
        $new['app_link']    = trim(sanitize_text_field($in['app_link'] ?? ''));
        if (class_exists('TIX_App_Links') && isset($in['app_link_ids'])) {
            update_option(TIX_App_Links::OPTION, TIX_App_Links::sanitize_ids($in['app_link_ids']));
        }

        $notice = 'saved';
        $pass = isset($in['password']) ? str_replace(["\r", "\n"], '', (string) $in['password']) : '';
        if (!empty($in['clear_password'])) {
            $new['password_enc'] = '';
        } elseif ($pass !== '') {
            $enc = self::encrypt($pass);
            if ($enc === '') $notice = 'nocrypto';
            else $new['password_enc'] = $enc;
        }
        update_option(self::OPTION, $new);
        delete_transient('tix_sp_portal_url');
        self::ensure_schedule();

        wp_safe_redirect(add_query_arg(['page' => 'tix-support', 'tab' => 'mail', 'tix_sp_mail' => $notice], admin_url('admin.php')) . '#mail');
        exit;
    }

    private static function ajax_guard() {
        check_ajax_referer('tix_support_mail', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
    }

    /** Verbindung testen – mit den Formularwerten, Passwort leer = gespeichertes */
    public static function ajax_test() {
        self::ajax_guard();
        $in = wp_unslash($_POST);
        $s  = self::settings();
        foreach (['host', 'user', 'folder', 'move_folder'] as $k) {
            if (isset($in[$k]) && trim($in[$k]) !== '') $s[$k] = trim(sanitize_text_field($in[$k]));
        }
        $s['host'] = preg_replace('#^[a-z]+://#i', '', rtrim($s['host'], '/'));
        if (!empty($in['port'])) $s['port'] = intval($in['port']);
        if (isset($in['encryption']) && in_array($in['encryption'], ['ssl', 'tls', 'none'], true)) $s['encryption'] = $in['encryption'];
        if (!empty($in['password'])) $s['password'] = str_replace(["\r", "\n"], '', (string) $in['password']);
        if ($s['host'] === '' || $s['user'] === '') wp_send_json_error(['message' => 'Bitte Server und Benutzer eintragen.']);

        $imap = null;
        try {
            $imap = self::connect($s);
            $sel  = $imap->select($s['folder']);
            $move = $imap->has_cap('MOVE');
            $msg  = 'Verbunden. Ordner „' . $s['folder'] . '“: ' . $sel['exists'] . ' Mails.';
            if (($in['after'] ?? $s['after']) === 'move') {
                $msg .= ' Zielordner „' . $s['move_folder'] . '“ ' . ($imap->folder_exists($s['move_folder']) ? 'vorhanden' : 'fehlt noch (wird beim ersten Verschieben angelegt)') . '.';
                $msg .= $move ? '' : ' Hinweis: Server kann nicht verschieben – Mails werden kopiert und als gelesen markiert.';
            }
            wp_send_json_success(['message' => $msg]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        } finally {
            if ($imap) $imap->logout();
        }
    }

    public static function ajax_run() {
        self::ajax_guard();
        if (!self::is_active()) wp_send_json_error(['message' => 'Eingang ist nicht aktiv (Schalter, Server, Benutzer, Passwort).']);
        $r = self::fetch();
        $r['ok'] ? wp_send_json_success($r) : wp_send_json_error($r);
    }

    public static function ajax_backfill() {
        self::ajax_guard();
        $s = self::settings();
        if ($s['host'] === '' || $s['user'] === '' || $s['password_enc'] === '') wp_send_json_error(['message' => 'Bitte zuerst die Zugangsdaten speichern.']);
        $days  = max(1, min(365, intval($_POST['days'] ?? 30)));
        $new   = !empty($_POST['allow_new']);
        $after = max(0, intval($_POST['after_uid'] ?? 0));
        $r = self::backfill($days, $new, $after);
        $r['ok'] ? wp_send_json_success($r) : wp_send_json_error($r);
    }

    // ══════════════════════════════════════════════
    // ADMIN: Reiter „E-Mail-Eingang“ im Support-Bereich
    // ══════════════════════════════════════════════

    public static function render_admin_pane() {
        $s      = self::settings();
        $state  = self::state();
        $log    = get_option(self::LOG, []);
        $active = self::is_active();
        $next   = wp_next_scheduled(self::HOOK);
        $portal = self::portal_url();
        $has_pw = $s['password_enc'] !== '';
        $pw_ok  = $has_pw && self::decrypt($s['password_enc']) !== '';
        $notice = sanitize_key($_GET['tix_sp_mail'] ?? '');
        $labels = [
            'imported' => 'übernommen', 'duplicate' => 'schon da', 'skipped_loop' => 'eigene Mail', 'skipped_auto' => 'automatisch',
            'skipped_invalid' => 'ungültig', 'ignored_unmatched' => 'nicht zugeordnet', 'ratelimited' => 'gedrosselt', 'error' => 'Fehler', 'start' => 'Start',
        ];
        $f = function ($k) use ($s) { return esc_attr((string) $s[$k]); };
        ?>
        <div class="tix-sp-mail" style="max-width:920px;">
            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success inline"><p>Einstellungen gespeichert.</p></div>
            <?php elseif ($notice === 'nocrypto'): ?>
                <div class="notice notice-error inline"><p>Passwort nicht gespeichert: PHP-Erweiterung OpenSSL fehlt.</p></div>
            <?php endif; ?>

            <div class="tix-card" style="margin-bottom:16px;">
                <div class="tix-card-header"><span class="dashicons dashicons-email-alt"></span><h3>E-Mail-Eingang</h3></div>
                <div class="tix-card-body">
                    <p style="margin-top:0;color:#475569;">Antworten von Kunden per E-Mail landen automatisch bei der passenden Anfrage. Support-Mails gehen mit <code>Reply-To</code> = Support-Postfach und <code>[#Nummer]</code> im Betreff raus; das Postfach wird alle 2 Minuten abgerufen. Mails werden nur als gelesen markiert oder verschoben, nie gelöscht.</p>
                    <p style="margin:0 0 4px;"><strong>Status:</strong>
                        <?php if ($active): ?><span style="color:#059669;">aktiv</span><?php else: ?><span style="color:#b45309;">aus</span><?php endif; ?>
                        <?php if (!empty($state['last_run'])): ?> · letzter Abruf <?php echo esc_html(wp_date('d.m.Y H:i', intval($state['last_run']))); ?><?php endif; ?>
                        <?php if ($next): ?> · nächster <?php echo esc_html(wp_date('H:i', $next)); ?><?php endif; ?>
                        <?php if (!empty($state['imported_total'])): ?> · bisher <?php echo intval($state['imported_total']); ?> übernommen<?php endif; ?>
                    </p>
                    <?php if (!empty($state['last_error'])): ?>
                        <p style="margin:4px 0;color:#b91c1c;"><strong>Letzter Fehler:</strong> <?php echo esc_html($state['last_error']); ?></p>
                    <?php endif; ?>
                    <?php if ($has_pw && !$pw_ok): ?>
                        <p style="margin:4px 0;color:#b91c1c;">Das gespeicherte Passwort lässt sich nicht mehr entschlüsseln (WordPress-Schlüssel geändert?) – bitte neu eingeben.</p>
                    <?php endif; ?>
                    <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
                        <p style="margin:4px 0;color:#475569;">WP-Cron läuft über einen System-Cron – der Abruf folgt dessen Takt.</p>
                    <?php else: ?>
                        <p style="margin:4px 0;color:#64748b;font-size:12px;">Hinweis: WP-Cron läuft nur bei Seitenaufrufen. Für pünktlichen Abruf nachts einen System-Cron auf <code>wp-cron.php</code> (jede Minute) einrichten.</p>
                    <?php endif; ?>
                    <?php if (!function_exists('openssl_encrypt')): ?>
                        <p style="margin:4px 0;color:#b91c1c;">PHP-Erweiterung OpenSSL fehlt – SSL/TLS und Passwort-Verschlüsselung nicht möglich.</p>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="tix-sp-mail-form" autocomplete="off">
                <input type="hidden" name="action" value="tix_support_mail_save">
                <?php wp_nonce_field('tix_support_mail_save'); ?>
                <div class="tix-card" style="margin-bottom:16px;">
                    <div class="tix-card-header"><span class="dashicons dashicons-admin-generic"></span><h3>Postfach</h3></div>
                    <div class="tix-card-body">
                        <table class="form-table" role="presentation" style="margin-top:0;">
                            <tr><th scope="row">Eingang</th><td><label><input type="checkbox" name="tix_sp_mail[enabled]" value="1" <?php checked(!empty($s['enabled'])); ?>> E-Mail-Antworten ins Support-System übernehmen</label></td></tr>
                            <tr><th scope="row"><label for="tix-sp-mail-address">Support-Postfach</label></th><td><input type="email" class="regular-text" id="tix-sp-mail-address" name="tix_sp_mail[address]" value="<?php echo $f('address'); ?>" placeholder="support@deine-seite.de"><p class="description">Antwort-Adresse (Reply-To) aller Support-Mails. Leer = IMAP-Benutzer, falls es eine E-Mail-Adresse ist. Am besten ein eigenes Postfach nur für den Support.</p></td></tr>
                            <tr><th scope="row"><label for="tix-sp-mail-host">IMAP-Server</label></th><td>
                                <input type="text" class="regular-text" id="tix-sp-mail-host" name="tix_sp_mail[host]" value="<?php echo $f('host'); ?>" placeholder="imap.example.com">
                                <input type="number" id="tix-sp-mail-port" name="tix_sp_mail[port]" value="<?php echo $f('port'); ?>" min="1" max="65535" style="width:90px;">
                                <select id="tix-sp-mail-enc" name="tix_sp_mail[encryption]">
                                    <option value="ssl" <?php selected($s['encryption'], 'ssl'); ?>>SSL/TLS (993)</option>
                                    <option value="tls" <?php selected($s['encryption'], 'tls'); ?>>STARTTLS (143)</option>
                                    <option value="none" <?php selected($s['encryption'], 'none'); ?>>unverschlüsselt</option>
                                </select>
                            </td></tr>
                            <tr><th scope="row"><label for="tix-sp-mail-user">Benutzer</label></th><td><input type="text" class="regular-text" id="tix-sp-mail-user" name="tix_sp_mail[user]" value="<?php echo $f('user'); ?>" autocomplete="off"></td></tr>
                            <tr><th scope="row"><label for="tix-sp-mail-pass">Passwort</label></th><td>
                                <input type="password" class="regular-text" id="tix-sp-mail-pass" name="tix_sp_mail[password]" value="" autocomplete="new-password" placeholder="<?php echo $has_pw ? '•••••••• (gespeichert – leer lassen zum Behalten)' : ''; ?>">
                                <?php if ($has_pw): ?><label style="margin-left:8px;"><input type="checkbox" name="tix_sp_mail[clear_password]" value="1"> löschen</label><?php endif; ?>
                                <p class="description">Wird verschlüsselt gespeichert und nie angezeigt.</p>
                            </td></tr>
                            <tr><th scope="row"><label for="tix-sp-mail-folder">Ordner</label></th><td><input type="text" id="tix-sp-mail-folder" name="tix_sp_mail[folder]" value="<?php echo $f('folder'); ?>"></td></tr>
                            <tr><th scope="row">Nach Übernahme</th><td>
                                <label><input type="radio" name="tix_sp_mail[after]" value="seen" <?php checked($s['after'], 'seen'); ?>> als gelesen markieren</label><br>
                                <label><input type="radio" name="tix_sp_mail[after]" value="move" <?php checked($s['after'], 'move'); ?>> in Ordner verschieben:</label>
                                <input type="text" id="tix-sp-mail-move" name="tix_sp_mail[move_folder]" value="<?php echo $f('move_folder'); ?>">
                                <p class="description">Nicht übernommene Mails (Newsletter, Abwesenheitsnotizen …) bleiben unverändert.</p>
                            </td></tr>
                            <tr><th scope="row">Nicht zuordenbar</th><td>
                                <label><input type="radio" name="tix_sp_mail[unmatched]" value="ticket" <?php checked($s['unmatched'], 'ticket'); ?>> neue Anfrage anlegen (Kategorie „Allgemein“)</label><br>
                                <label><input type="radio" name="tix_sp_mail[unmatched]" value="ignore" <?php checked($s['unmatched'], 'ignore'); ?>> ignorieren (bleibt im Postfach)</label>
                            </td></tr>
                            <tr><th scope="row"><label for="tix-sp-mail-portal">Support-Portal</label></th><td><input type="url" class="regular-text" id="tix-sp-mail-portal" name="tix_sp_mail[portal_url]" value="<?php echo $f('portal_url'); ?>" placeholder="<?php echo esc_attr($portal ?: 'Seite mit [tix_support]'); ?>"><p class="description">Ziel des Knopfs „Im Support antworten“. Leer = automatisch<?php echo $portal ? ' (' . esc_html($portal) . ')' : ' – keine Seite mit <code>[tix_support]</code> gefunden, Knopf entfällt'; ?>.</p></td></tr>
                            <tr><th scope="row"><label for="tix-sp-mail-app">App-Link</label></th><td><input type="text" class="regular-text" id="tix-sp-mail-app" name="tix_sp_mail[app_link]" value="<?php echo $f('app_link'); ?>" placeholder="https://… oder app://support/{id}"><p class="description">Optional, <code>{id}</code> = Anfrage-Nummer. Erscheint als „In der App öffnen“.</p></td></tr>
                            <?php if (class_exists('TIX_App_Links')): ?>
                            <tr><th scope="row"><label for="tix-sp-mail-aasa">Universal Links</label></th><td><input type="text" class="regular-text" id="tix-sp-mail-aasa" name="tix_sp_mail[app_link_ids]" value="<?php echo esc_attr(implode(' ', TIX_App_Links::ids())); ?>" placeholder="TEAMID.de.beispiel.app"><p class="description">App-IDs (Team-ID.Bundle-ID, mehrere mit Leerzeichen). Dann öffnet der Knopf „Im Support antworten“ auf dem iPhone direkt die App (<code>/.well-known/apple-app-site-association</code>, nur Links mit <code>tix_sp_ticket</code>). Leer = aus.</p></td></tr>
                            <?php endif; ?>
                        </table>
                        <p>
                            <button type="submit" class="button button-primary">Speichern</button>
                            <button type="button" class="button" id="tix-sp-mail-test">Test-Verbindung</button>
                            <button type="button" class="button" id="tix-sp-mail-run" <?php disabled(!$active); ?>>Jetzt abrufen</button>
                            <span id="tix-sp-mail-result" style="margin-left:8px;"></span>
                        </p>
                    </div>
                </div>
            </form>

            <div class="tix-card" style="margin-bottom:16px;">
                <div class="tix-card-header"><span class="dashicons dashicons-backup"></span><h3>Rückwärts-Import</h3></div>
                <div class="tix-card-body">
                    <p style="margin-top:0;color:#475569;">Holt bereits eingegangene Kunden-Antworten nachträglich in die Anfragen (einmalig, doppelte Mails werden erkannt). Team-Benachrichtigungen werden dabei nicht verschickt.</p>
                    <p>
                        Zeitraum: letzte <input type="number" id="tix-sp-mail-days" value="30" min="1" max="365" style="width:70px;"> Tage
                        <label style="margin-left:12px;"><input type="checkbox" id="tix-sp-mail-allow-new"> auch nicht zuordenbare Mails als neue Anfragen</label>
                    </p>
                    <p><button type="button" class="button" id="tix-sp-mail-backfill" <?php disabled(!$has_pw); ?>>Rückwärts-Import starten</button> <span id="tix-sp-mail-bf-result" style="margin-left:8px;"></span></p>
                </div>
            </div>

            <div class="tix-card">
                <div class="tix-card-header"><span class="dashicons dashicons-list-view"></span><h3>Protokoll</h3></div>
                <div class="tix-card-body" style="overflow-x:auto;">
                    <?php if (empty($log)): ?>
                        <p style="color:#64748b;margin:0;">Noch keine Einträge.</p>
                    <?php else: ?>
                        <table class="widefat striped" style="font-size:12px;">
                            <thead><tr><th>Zeit</th><th>Absender</th><th>Betreff</th><th>Ergebnis</th><th>Anfrage</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($log, 0, 30) as $e): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo esc_html(wp_date('d.m. H:i', intval($e['t']))); ?></td>
                                    <td><?php echo esc_html($e['from']); ?></td>
                                    <td><?php echo esc_html($e['subject']); ?></td>
                                    <td><?php echo esc_html(($labels[$e['result']] ?? $e['result']) . ($e['reason'] ? ' – ' . $e['reason'] : '')); ?></td>
                                    <td><?php if ($e['ticket']): ?><a href="<?php echo esc_url(admin_url('admin.php?page=tix-support&ticket=' . intval($e['ticket']))); ?>">#<?php echo intval($e['ticket']); ?></a><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function ($) {
            var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('tix_support_mail')); ?>;
            if (/[?&]tab=mail\b/.test(location.search)) {
                $(function () { $('.tix-nav-tab[data-tab="mail"]').trigger('click'); });
            }
            function out(el, ok, text) { $(el).css('color', ok ? '#059669' : '#b91c1c').text(text); }
            $('#tix-sp-mail-test').on('click', function () {
                var b = $(this).prop('disabled', true); out('#tix-sp-mail-result', true, 'Verbinde …');
                $.post(ajax, {
                    action: 'tix_support_mail_test', nonce: nonce,
                    host: $('#tix-sp-mail-host').val(), port: $('#tix-sp-mail-port').val(), encryption: $('#tix-sp-mail-enc').val(),
                    user: $('#tix-sp-mail-user').val(), password: $('#tix-sp-mail-pass').val(), folder: $('#tix-sp-mail-folder').val(),
                    after: $('input[name="tix_sp_mail[after]"]:checked').val(), move_folder: $('#tix-sp-mail-move').val()
                }).done(function (r) { out('#tix-sp-mail-result', r.success, (r.data && r.data.message) || 'Fehler'); })
                  .fail(function () { out('#tix-sp-mail-result', false, 'Anfrage fehlgeschlagen.'); })
                  .always(function () { b.prop('disabled', false); });
            });
            $('#tix-sp-mail-run').on('click', function () {
                var b = $(this).prop('disabled', true); out('#tix-sp-mail-result', true, 'Rufe ab …');
                $.post(ajax, { action: 'tix_support_mail_run', nonce: nonce })
                  .done(function (r) { out('#tix-sp-mail-result', r.success, (r.data && r.data.message) || 'Fehler'); })
                  .fail(function () { out('#tix-sp-mail-result', false, 'Anfrage fehlgeschlagen.'); })
                  .always(function () { b.prop('disabled', false); });
            });
            $('#tix-sp-mail-backfill').on('click', function () {
                var b = $(this).prop('disabled', true), days = $('#tix-sp-mail-days').val(), allowNew = $('#tix-sp-mail-allow-new').is(':checked') ? 1 : 0;
                var imported = 0, checked = 0, tickets = [];
                function step(after) {
                    out('#tix-sp-mail-bf-result', true, 'Läuft … ' + checked + ' geprüft, ' + imported + ' übernommen');
                    $.post(ajax, { action: 'tix_support_mail_backfill', nonce: nonce, days: days, allow_new: allowNew, after_uid: after })
                      .done(function (r) {
                          var d = r.data || {};
                          if (!r.success) { out('#tix-sp-mail-bf-result', false, d.message || 'Fehler'); b.prop('disabled', false); return; }
                          imported += d.imported; checked += d.checked; tickets = tickets.concat(d.tickets || []);
                          if (d.more) { step(d.next_after); return; }
                          var uniq = tickets.filter(function (v, i, a) { return a.indexOf(v) === i; });
                          out('#tix-sp-mail-bf-result', true, 'Fertig: ' + d.total + ' Mails im Zeitraum, ' + imported + ' übernommen' + (uniq.length ? ' (Anfragen #' + uniq.join(', #') + ')' : '') + '.');
                          b.prop('disabled', false);
                      })
                      .fail(function () { out('#tix-sp-mail-bf-result', false, 'Anfrage fehlgeschlagen.'); b.prop('disabled', false); });
                }
                step(0);
            });
        })(jQuery);
        </script>
        <?php
    }
}
