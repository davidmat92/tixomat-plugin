<?php
/**
 * TIX Broadcast — Rundmail an Kunden.
 *
 * Admin-Seite: Tixomat → 📣 Rundmail
 *   - Empfaenger: einzelnes Event (Dropdown mit Kaeuferzahl) ODER "Alle Kunden" (1 Klick)
 *   - Status-Filter (Default: completed + processing + on-hold = alle Zahler)
 *   - Live-Empfaengerzahl, Betreff + Nachricht (Platzhalter {vorname})
 *   - Test-Mail an eigene Adresse
 *   - Versand in Batches (25/Request) mit Fortschrittsbalken — kein Timeout
 *
 * WICHTIG: Es wird NIE automatisch versendet. Versand ausschliesslich durch
 * expliziten Klick auf "Jetzt senden" + Bestaetigungsdialog.
 */
if (!defined('ABSPATH')) exit;

class TIX_Broadcast {

    const CAPABILITY = 'manage_options';
    const BATCH_SIZE = 25;
    const OPT_DRAFT  = '_tix_broadcast_draft';
    const OPT_LOG    = '_tix_broadcast_log';
    const OPT_SENDER      = '_tix_broadcast_sender';
    const OPT_SENDER_NAME = '_tix_broadcast_sender_name';

    /** Absender fuer Rundmails (leer = WP/SMTP-Default). */
    public static function get_sender(): string {
        $v = get_option(self::OPT_SENDER, '');
        return is_email($v) ? $v : '';
    }

    /** Absender-Name fuer Rundmails (leer = email_brand_name aus den Einstellungen). */
    public static function get_sender_name(): string {
        $v = trim((string) get_option(self::OPT_SENDER_NAME, ''));
        if ($v !== '') return $v;
        $s = get_option('tix_settings', array());
        return !empty($s['email_brand_name']) ? $s['email_brand_name'] : get_bloginfo('name');
    }

    public static function init() {
        add_action('admin_menu',                     array(__CLASS__, 'register_menu'), 64);
        add_action('wp_ajax_tix_broadcast_count',    array(__CLASS__, 'ajax_count'));
        add_action('wp_ajax_tix_broadcast_search',   array(__CLASS__, 'ajax_search'));
        add_action('wp_ajax_tix_broadcast_test',     array(__CLASS__, 'ajax_test'));
        add_action('wp_ajax_tix_broadcast_start',    array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_tix_broadcast_batch',    array(__CLASS__, 'ajax_batch'));
        add_action('wp_ajax_tix_broadcast_draft',    array(__CLASS__, 'ajax_save_draft'));
        add_action('wp_ajax_tix_broadcast_load_last', array(__CLASS__, 'ajax_load_last'));
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Rundmail an Kunden',
            '📣 Rundmail',
            self::CAPABILITY,
            'tix-broadcast',
            array(__CLASS__, 'render_page')
        );
    }

    /* ─────────── Empfaenger-Query ─────────── */

    /**
     * Liefert deduplizierte Empfaenger: [ ['email'=>..., 'first_name'=>...], ... ]
     * $event_id = 0 → alle Kunden. $cat_name filtert zusaetzlich auf Ticketart (nur mit Event).
     */
    public static function get_recipients(int $event_id, array $statuses, string $cat_name = ''): array {
        global $wpdb;
        if (empty($statuses)) $statuses = array('completed', 'processing', 'on-hold');
        $statuses = array_values(array_intersect($statuses, array('completed', 'processing', 'on-hold', 'pending', 'refunded')));
        if (empty($statuses)) return array();
        $ph = implode(',', array_fill(0, count($statuses), '%s'));

        if ($event_id > 0) {
            $cat_sql = '';
            $cat_params = array();
            if ($cat_name !== '') {
                $cat_sql = " AND i.cat_name = %s";
                $cat_params = array($cat_name);
            }
            $sql = "SELECT o.billing_email AS email, MAX(o.billing_first_name) AS first_name
                    FROM {$wpdb->prefix}tix_orders o
                    JOIN {$wpdb->prefix}tix_order_items i ON i.order_id = o.id
                    WHERE i.event_id = %d
                      AND o.status IN ($ph)$cat_sql
                      AND o.billing_email <> ''
                    GROUP BY o.billing_email";
            $params = array_merge(array($event_id), $statuses, $cat_params);
        } else {
            $sql = "SELECT o.billing_email AS email, MAX(o.billing_first_name) AS first_name
                    FROM {$wpdb->prefix}tix_orders o
                    WHERE o.status IN ($ph)
                      AND o.billing_email <> ''
                    GROUP BY o.billing_email";
            $params = $statuses;
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $out = array();
        $seen = array();
        foreach ($rows as $r) {
            $em = strtolower(trim($r['email']));
            if ($em === '' || isset($seen[$em]) || !is_email($em)) continue;
            $seen[$em] = 1;
            $out[] = array('email' => $em, 'first_name' => trim((string) $r['first_name']));
        }
        return $out;
    }

    private static function parse_statuses($raw): array {
        $sts = is_array($raw) ? array_map('sanitize_text_field', $raw) : array();
        if (empty($sts)) $sts = array('completed', 'processing', 'on-hold');
        return $sts;
    }

    /* ─────────── HTML-Wrapper ─────────── */

    public static function wrap_html(string $subject, string $body_html): string {
        $s = get_option('tix_settings', array());
        $brand = !empty($s['email_brand_name']) ? $s['email_brand_name'] : get_bloginfo('name');
        $logo_url = !empty($s['email_logo_url']) ? $s['email_logo_url'] : '';
        $logo_height = intval($s['email_logo_height'] ?? 40);
        if ($logo_height < 20) $logo_height = 40;

        // Header: Logo aus den E-Mail-Einstellungen (Fallback: Brand-Name als Text)
        if ($logo_url !== '') {
            $header_inner = '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($brand) . '" style="max-height:' . $logo_height . 'px;width:auto;display:inline-block;">';
        } else {
            $header_inner = '<span style="color:#fff;font-size:20px;font-weight:700;">' . esc_html($brand) . '</span>';
        }

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;">'
            . '<div style="max-width:600px;margin:0 auto;padding:24px 12px;">'
            . '<div style="background:#000000;border-radius:12px 12px 0 0;padding:20px 28px;text-align:center;">'
            . $header_inner
            . '</div>'
            . '<div style="background:#ffffff;border-radius:0 0 12px 12px;padding:28px;font-size:15px;line-height:1.6;color:#1f2937;">'
            . $body_html
            . '</div>'
            . '<div style="text-align:center;padding:16px;font-size:12px;color:#9ca3af;">'
            . esc_html(self::get_sender_name()) . ' &middot; Diese E-Mail wurde an Kunden von ' . esc_html(self::get_sender_name()) . ' gesendet.'
            . '</div>'
            . '</div></body></html>';
    }

    private static function prepare_body(string $raw_body): string {
        $body = wp_kses_post($raw_body);
        // Plain-Text-Eingabe → Zeilenumbrueche erhalten
        if (strpos($body, '<p') === false && strpos($body, '<br') === false && strpos($body, '<div') === false) {
            $body = nl2br($body);
        }
        return $body;
    }

    private static function personalize(string $text, array $recipient): string {
        $vorname = $recipient['first_name'] !== '' ? $recipient['first_name'] : 'Festival-Fan';
        return str_replace(array('{vorname}', '{VORNAME}'), $vorname, $text);
    }

    private static function send_one(array $recipient, string $subject, string $body_prepared): bool {
        $subj = self::personalize($subject, $recipient);
        $body = self::personalize($body_prepared, $recipient);
        $html = self::wrap_html($subj, $body);

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sender      = self::get_sender();
        $sender_name = self::get_sender_name();
        $from_filter = null;
        $name_filter = null;
        if ($sender !== '') {
            $headers[] = 'From: ' . $sender_name . ' <' . $sender . '>';
            // wp_mail_from-Filter zusaetzlich, damit auch SMTP-Plugins Absender + Name uebernehmen
            $from_filter = function () use ($sender) { return $sender; };
            $name_filter = function () use ($sender_name) { return $sender_name; };
            add_filter('wp_mail_from', $from_filter, 99);
            add_filter('wp_mail_from_name', $name_filter, 99);
        }

        $ok = (bool) wp_mail($recipient['email'], $subj, $html, $headers);

        if ($from_filter !== null) {
            remove_filter('wp_mail_from', $from_filter, 99);
            remove_filter('wp_mail_from_name', $name_filter, 99);
        }
        return $ok;
    }

    /* ─────────── AJAX ─────────── */

    /**
     * Autocomplete-Suche fuer Einzelkunden (E-Mail oder Name, dedupliziert pro E-Mail).
     */
    public static function ajax_search() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(array('message' => 'no perm'));
        check_ajax_referer('tix_broadcast');
        global $wpdb;
        $q = trim(sanitize_text_field($_POST['q'] ?? ''));
        if (strlen($q) < 2) wp_send_json_success(array());

        $like = '%' . $wpdb->esc_like($q) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT billing_email AS email,
                    MAX(billing_first_name) AS first_name,
                    MAX(billing_last_name)  AS last_name,
                    COUNT(*) AS orders
             FROM {$wpdb->prefix}tix_orders
             WHERE billing_email <> ''
               AND (billing_email LIKE %s OR billing_first_name LIKE %s OR billing_last_name LIKE %s
                    OR CONCAT(billing_first_name, ' ', billing_last_name) LIKE %s)
             GROUP BY billing_email
             ORDER BY MAX(date_created) DESC
             LIMIT 10",
            $like, $like, $like, $like
        ), ARRAY_A);

        $out = array();
        foreach ($rows as $r) {
            if (!is_email($r['email'])) continue;
            $out[] = array(
                'email'      => strtolower($r['email']),
                'name'       => trim($r['first_name'] . ' ' . $r['last_name']),
                'first_name' => trim((string) $r['first_name']),
                'orders'     => intval($r['orders']),
            );
        }
        wp_send_json_success($out);
    }

    public static function ajax_count() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(array('message' => 'no perm'));
        check_ajax_referer('tix_broadcast');
        $event_id = intval($_POST['event_id'] ?? 0);
        $statuses = self::parse_statuses($_POST['statuses'] ?? array());
        $cat_name = sanitize_text_field($_POST['cat_name'] ?? '');
        $recipients = self::get_recipients($event_id, $statuses, $cat_name);
        wp_send_json_success(array('count' => count($recipients)));
    }

    public static function ajax_save_draft() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(array('message' => 'no perm'));
        check_ajax_referer('tix_broadcast');
        update_option(self::OPT_DRAFT, array(
            'subject'  => sanitize_text_field($_POST['subject'] ?? ''),
            'body'     => wp_kses_post($_POST['body'] ?? ''),
            'event_id' => intval($_POST['event_id'] ?? 0),
            'saved_at' => current_time('mysql'),
        ), false);
        if (isset($_POST['sender'])) {
            $sender = sanitize_email($_POST['sender']);
            update_option(self::OPT_SENDER, is_email($sender) ? $sender : '', false);
        }
        if (isset($_POST['sender_name'])) {
            update_option(self::OPT_SENDER_NAME, sanitize_text_field($_POST['sender_name']), false);
        }
        wp_send_json_success(array('message' => 'Entwurf gespeichert'));
    }

    /**
     * Laedt Betreff + Text der zuletzt versendeten Rundmail als Vorlage.
     * Neuere Mails: aus _tix_broadcast_last. Aeltere (vor v1.38.261): Rekonstruktion
     * aus dem E-Mail-Log (Inhalt zwischen Content-Div und Footer extrahiert).
     */
    public static function ajax_load_last() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(array('message' => 'no perm'));
        check_ajax_referer('tix_broadcast');

        $last = get_option('_tix_broadcast_last', array());
        if (is_array($last) && !empty($last['body'])) {
            wp_send_json_success(array(
                'subject' => (string) $last['subject'],
                'body'    => (string) $last['body'],
                'source'  => 'gespeicherte Vorlage (' . ($last['when'] ?? '') . ')',
            ));
        }

        // Fallback: letzte Rundmail aus dem Broadcast-Log + Body aus dem E-Mail-Log rekonstruieren
        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log) || empty($log)) wp_send_json_error(array('message' => 'Keine fruehere Rundmail gefunden.'));
        $entry = end($log);
        $subject = (string) ($entry['subject'] ?? '');
        if ($subject === '') wp_send_json_error(array('message' => 'Keine fruehere Rundmail gefunden.'));

        global $wpdb;
        $log_table = $wpdb->prefix . 'tix_email_log';
        $html = '';
        if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") === $log_table) {
            $html = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT body FROM $log_table WHERE subject = %s ORDER BY id DESC LIMIT 1", $subject
            ));
        }
        if ($html === '') wp_send_json_error(array('message' => 'Text der letzten Rundmail nicht im E-Mail-Log gefunden.'));

        // Inhalt zwischen Content-Div und Footer extrahieren
        $marker_start = 'color:#1f2937;">';
        $marker_end   = '<div style="text-align:center;padding:16px';
        $p1 = strpos($html, $marker_start);
        $p2 = strrpos($html, $marker_end);
        if ($p1 === false || $p2 === false || $p2 <= $p1) {
            wp_send_json_error(array('message' => 'Text konnte nicht extrahiert werden.'));
        }
        $inner = substr($html, $p1 + strlen($marker_start), $p2 - $p1 - strlen($marker_start));
        // Schliessendes </div> des Content-Blocks abschneiden
        $inner = preg_replace('#</div>\s*$#', '', trim($inner));

        wp_send_json_success(array(
            'subject' => $subject,
            'body'    => trim($inner),
            'source'  => 'rekonstruiert aus E-Mail-Log (' . ($entry['when'] ?? '') . ') — Anrede pruefen, {vorname} ggf. wieder einsetzen',
        ));
    }

    public static function ajax_test() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(array('message' => 'no perm'));
        check_ajax_referer('tix_broadcast');
        $to      = sanitize_email($_POST['test_email'] ?? '');
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $body    = self::prepare_body((string) ($_POST['body'] ?? ''));
        if (!$to || !is_email($to)) wp_send_json_error(array('message' => 'Ungueltige Test-Adresse.'));
        if ($subject === '' || trim(wp_strip_all_tags($body)) === '') wp_send_json_error(array('message' => 'Betreff und Text ausfuellen.'));

        $current = wp_get_current_user();
        $rec = array('email' => $to, 'first_name' => $current ? $current->first_name : '');
        self::send_one($rec, '[TEST] ' . $subject, $body);
        wp_send_json_success(array('message' => 'Test-Mail an ' . $to . ' gesendet.'));
    }

    /**
     * Start: Empfaengerliste einfrieren, Job-Transient anlegen. Versendet noch NICHTS.
     */
    public static function ajax_start() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(array('message' => 'no perm'));
        check_ajax_referer('tix_broadcast');

        $event_id = intval($_POST['event_id'] ?? 0);
        $statuses = self::parse_statuses($_POST['statuses'] ?? array());
        $cat_name = sanitize_text_field($_POST['cat_name'] ?? '');
        $subject  = sanitize_text_field($_POST['subject'] ?? '');
        $body     = self::prepare_body((string) ($_POST['body'] ?? ''));

        if ($subject === '') wp_send_json_error(array('message' => 'Betreff fehlt.'));
        if (trim(wp_strip_all_tags($body)) === '') wp_send_json_error(array('message' => 'Nachricht fehlt.'));

        // Manueller Modus: einzelne, per Autocomplete ausgewaehlte Kunden
        $manual = array();
        if (!empty($_POST['manual_emails']) && is_array($_POST['manual_emails'])) {
            global $wpdb;
            $seen = array();
            foreach ($_POST['manual_emails'] as $raw) {
                $em = strtolower(trim(sanitize_email($raw)));
                if ($em === '' || !is_email($em) || isset($seen[$em])) continue;
                $seen[$em] = 1;
                $fn = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT billing_first_name FROM {$wpdb->prefix}tix_orders
                     WHERE billing_email = %s AND billing_first_name <> '' ORDER BY id DESC LIMIT 1",
                    $em
                ));
                $manual[] = array('email' => $em, 'first_name' => trim($fn));
            }
        }

        if (!empty($manual)) {
            $recipients = $manual;
            $event_id = -1; // Marker fuer Log: manuelle Auswahl
        } else {
            $recipients = self::get_recipients($event_id, $statuses, $cat_name);
        }
        if (empty($recipients)) wp_send_json_error(array('message' => 'Keine Empfaenger gefunden.'));

        $job_id = 'bc_' . time() . '_' . wp_generate_password(6, false, false);
        set_transient('tix_broadcast_' . $job_id, array(
            'recipients' => $recipients,
            'subject'    => $subject,
            'body'       => $body,
            'sent'       => 0,
            'event_id'   => $event_id,
            'cat_name'   => $cat_name,
        ), 12 * HOUR_IN_SECONDS);

        wp_send_json_success(array('job_id' => $job_id, 'total' => count($recipients)));
    }

    /**
     * Batch: naechste N Empfaenger abarbeiten. Wird vom Frontend wiederholt
     * aufgerufen bis fertig.
     */
    public static function ajax_batch() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(array('message' => 'no perm'));
        check_ajax_referer('tix_broadcast');

        $job_id = sanitize_text_field($_POST['job_id'] ?? '');
        $job = get_transient('tix_broadcast_' . $job_id);
        if (!is_array($job)) wp_send_json_error(array('message' => 'Job abgelaufen oder nicht gefunden.'));

        $total = count($job['recipients']);
        $sent  = intval($job['sent']);
        $chunk = array_slice($job['recipients'], $sent, self::BATCH_SIZE);

        foreach ($chunk as $rec) {
            self::send_one($rec, $job['subject'], $job['body']);
            $sent++;
        }

        $job['sent'] = $sent;

        if ($sent >= $total) {
            delete_transient('tix_broadcast_' . $job_id);
            // Letzte Mail als Vorlage speichern (fuer "Als Vorlage laden")
            update_option('_tix_broadcast_last', array(
                'subject' => $job['subject'],
                'body'    => $job['body'],
                'when'    => current_time('mysql'),
            ), false);
            // Abschluss-Log
            $log = get_option(self::OPT_LOG, array());
            if (!is_array($log)) $log = array();
            $current = wp_get_current_user();
            $log[] = array(
                'when'     => current_time('mysql'),
                'subject'  => $job['subject'],
                'event_id' => intval($job['event_id']),
                'cat_name' => (string) ($job['cat_name'] ?? ''),
                'total'    => $total,
                'by'       => $current ? $current->user_login : 'system',
            );
            if (count($log) > 50) $log = array_slice($log, -50);
            update_option(self::OPT_LOG, $log, false);
            delete_option(self::OPT_DRAFT);
            wp_send_json_success(array('done' => true, 'sent' => $sent, 'total' => $total));
        }

        set_transient('tix_broadcast_' . $job_id, $job, 12 * HOUR_IN_SECONDS);
        wp_send_json_success(array('done' => false, 'sent' => $sent, 'total' => $total));
    }

    /* ─────────── Admin-Seite ─────────── */

    public static function render_page() {
        if (!current_user_can(self::CAPABILITY)) return;
        global $wpdb;

        // Events mit Kaeuferzahl
        $events = get_posts(array(
            'post_type'      => 'event',
            'post_status'    => array('publish', 'draft', 'future'),
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ));
        $event_counts = array();
        foreach ($events as $ev) {
            $event_counts[$ev->ID] = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT o.billing_email)
                 FROM {$wpdb->prefix}tix_orders o
                 JOIN {$wpdb->prefix}tix_order_items i ON i.order_id = o.id
                 WHERE i.event_id = %d AND o.status IN ('completed','processing','on-hold') AND o.billing_email <> ''",
                $ev->ID
            )));
        }
        $all_count = intval($wpdb->get_var(
            "SELECT COUNT(DISTINCT billing_email) FROM {$wpdb->prefix}tix_orders
             WHERE status IN ('completed','processing','on-hold') AND billing_email <> ''"
        ));

        // Ticketarten pro Event (aus tatsaechlich verkauften Order-Items)
        $event_cats = array();
        $cat_rows = $wpdb->get_results(
            "SELECT DISTINCT i.event_id, i.cat_name
             FROM {$wpdb->prefix}tix_order_items i
             JOIN {$wpdb->prefix}tix_orders o ON o.id = i.order_id
             WHERE o.status IN ('completed','processing','on-hold') AND i.cat_name <> ''
             ORDER BY i.cat_name"
        );
        foreach ($cat_rows as $cr) {
            $eid = intval($cr->event_id);
            if (!isset($event_cats[$eid])) $event_cats[$eid] = array();
            $event_cats[$eid][] = $cr->cat_name;
        }

        $draft = get_option(self::OPT_DRAFT, array());
        if (!is_array($draft)) $draft = array();
        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log)) $log = array();
        $recent = array_slice(array_reverse($log), 0, 8);

        $nonce = wp_create_nonce('tix_broadcast');
        $current = wp_get_current_user();
        $admin_email = $current ? $current->user_email : get_option('admin_email');
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">📣 Rundmail an Kunden</h1>
            <p style="max-width:820px;color:#475569;">
                E-Mail an alle Kaeufer eines Events oder an <strong>alle Kunden</strong> senden.
                Empfaenger werden pro E-Mail-Adresse dedupliziert. Platzhalter: <code>{vorname}</code>.
                Es wird erst gesendet, wenn du unten auf „Jetzt senden" klickst und bestaetigst.
            </p>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:16px;max-width:860px;">

                <label style="font-weight:600;display:block;margin-bottom:8px;">Empfaenger</label>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px;">
                    <select id="tix-bc-event" style="min-width:380px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;">
                        <option value="0">🌍 Alle Kunden (<?php echo $all_count; ?> Empfaenger)</option>
                        <?php foreach ($events as $ev): if ($event_counts[$ev->ID] < 1 && $ev->post_status !== 'publish') continue; ?>
                            <option value="<?php echo intval($ev->ID); ?>" <?php selected(intval($draft['event_id'] ?? -1), $ev->ID); ?>>
                                <?php echo esc_html(get_the_title($ev->ID) ?: '(ohne Titel)'); ?> (<?php echo $event_counts[$ev->ID]; ?> Kaeufer)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="tix-bc-cat" style="display:none;min-width:200px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;">
                        <option value="">🎫 Alle Ticketarten</option>
                    </select>
                    <button type="button" class="button" id="tix-bc-all-btn" title="Mit einem Klick alle Kunden auswaehlen">🌍 Alle auswaehlen</button>
                    <span id="tix-bc-count" style="font-weight:700;color:#059669;"></span>
                </div>

                <div style="margin:10px 0 4px;">
                    <label style="font-weight:600;display:block;margin-bottom:6px;">Oder einzelne Kunden auswaehlen <small style="color:#94a3b8;font-weight:400;">(ueberschreibt die Event-Auswahl)</small></label>
                    <div style="position:relative;max-width:480px;">
                        <input type="text" id="tix-bc-manual-search" placeholder="Name oder E-Mail tippen…" autocomplete="off"
                               style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        <div id="tix-bc-manual-results" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:50;background:#fff;border:1px solid #d1d5db;border-radius:0 0 8px 8px;max-height:240px;overflow:auto;box-shadow:0 8px 20px rgba(15,23,42,.12);"></div>
                    </div>
                    <div id="tix-bc-manual-chips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;"></div>
                </div>
                <style>
                    .tix-bc-mres { padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9; }
                    .tix-bc-mres:hover { background:#f8fafc; }
                    .tix-bc-mres:last-child { border-bottom:none; }
                    .tix-bc-chip { display:inline-flex;align-items:center;gap:6px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:999px;padding:4px 10px;font-size:12px; }
                    .tix-bc-chip .x { cursor:pointer;font-weight:700;color:#dc2626; }
                </style>

                <details style="margin:8px 0 16px;">
                    <summary style="cursor:pointer;color:#64748b;font-size:13px;">Status-Filter (Standard: alle Zahler)</summary>
                    <div style="padding:10px 4px 0;display:flex;gap:16px;flex-wrap:wrap;font-size:13px;">
                        <label><input type="checkbox" class="tix-bc-status" value="completed" checked> ✅ Abgeschlossen</label>
                        <label><input type="checkbox" class="tix-bc-status" value="processing" checked> ⏳ In Bearbeitung</label>
                        <label><input type="checkbox" class="tix-bc-status" value="on-hold" checked> 🏦 Wartend (Ueberweisung)</label>
                    </div>
                </details>

                <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                    <div>
                        <label style="font-weight:600;display:block;margin-bottom:6px;">Absender-Name</label>
                        <input type="text" id="tix-bc-sender-name" value="<?php echo esc_attr(get_option(self::OPT_SENDER_NAME, '')); ?>" placeholder="z.B. Mallorca Festival XXL"
                               style="width:280px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-weight:600;display:block;margin-bottom:6px;">Absender-Adresse <small style="color:#94a3b8;font-weight:400;">(leer = Standard)</small></label>
                        <input type="email" id="tix-bc-sender" value="<?php echo esc_attr(self::get_sender()); ?>" placeholder="z.B. dm@mallorca-festival-xxl.de"
                               style="width:320px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
                    </div>
                </div>

                <label style="font-weight:600;display:block;margin-bottom:6px;">Betreff</label>
                <input type="text" id="tix-bc-subject" value="<?php echo esc_attr($draft['subject'] ?? ''); ?>" placeholder="z.B. Wichtige Info zu deinem Festival-Ticket"
                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;margin-bottom:14px;box-sizing:border-box;">

                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <label style="font-weight:600;">Nachricht <small style="color:#94a3b8;font-weight:400;">(HTML erlaubt, {vorname} wird ersetzt)</small></label>
                    <button type="button" class="button button-small" id="tix-bc-load-last" title="Betreff + Text der zuletzt versendeten Rundmail uebernehmen">📋 Letzte Mail als Vorlage laden</button>
                </div>
                <textarea id="tix-bc-body" rows="12" placeholder="Hallo {vorname},&#10;&#10;— hier kommt dein Text rein —"
                          style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit;box-sizing:border-box;"><?php echo esc_textarea($draft['body'] ?? ''); ?></textarea>
                <p style="color:#94a3b8;font-size:12px;margin:4px 0 16px;">Entwurf wird automatisch gespeichert. Die Mail bekommt automatisch Header/Footer im Site-Branding.</p>

                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;border-top:1px solid #f1f5f9;padding-top:16px;">
                    <input type="email" id="tix-bc-test-email" value="<?php echo esc_attr($admin_email); ?>" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;width:260px;">
                    <button type="button" class="button" id="tix-bc-test-btn">✉️ Test an mich</button>
                    <span style="flex:1;"></span>
                    <button type="button" class="button button-primary button-large" id="tix-bc-send-btn" style="background:#dc2626;border-color:#b91c1c;">📣 Jetzt senden</button>
                </div>

                <div id="tix-bc-progress" style="display:none;margin-top:16px;">
                    <div style="background:#f1f5f9;border-radius:8px;height:22px;overflow:hidden;">
                        <div id="tix-bc-bar" style="background:linear-gradient(90deg,#10b981,#059669);height:100%;width:0%;transition:width .3s;"></div>
                    </div>
                    <div id="tix-bc-progress-text" style="margin-top:6px;font-size:13px;color:#475569;"></div>
                </div>
                <div id="tix-bc-msg" style="margin-top:10px;font-size:13px;"></div>
            </div>

            <?php if (!empty($recent)): ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:16px;max-width:860px;">
                <h2 style="margin:0 0 12px;font-size:16px;">Letzte Rundmails</h2>
                <table class="widefat striped">
                    <thead><tr><th>Wann</th><th>Betreff</th><th>Empfaenger</th><th>Ziel</th><th>Von</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $entry):
                        $target = intval($entry['event_id'] ?? 0);
                        if ($target === -1) {
                            $target_label = 'Manuelle Auswahl';
                        } else {
                            $target_label = $target > 0 ? (get_the_title($target) ?: 'Event #' . $target) : 'Alle Kunden';
                        }
                        if (!empty($entry['cat_name'])) $target_label .= ' — nur ' . $entry['cat_name'];
                    ?>
                        <tr>
                            <td><?php echo esc_html($entry['when'] ?? ''); ?></td>
                            <td><strong><?php echo esc_html($entry['subject'] ?? ''); ?></strong></td>
                            <td><?php echo intval($entry['total'] ?? 0); ?></td>
                            <td><?php echo esc_html($target_label); ?></td>
                            <td><?php echo esc_html($entry['by'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function(){
            var nonce = '<?php echo esc_js($nonce); ?>';
            var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var evSel = document.getElementById('tix-bc-event');
            var catSel = document.getElementById('tix-bc-cat');
            var countEl = document.getElementById('tix-bc-count');
            var msgEl = document.getElementById('tix-bc-msg');
            var eventCats = <?php echo wp_json_encode($event_cats); ?>;

            function refreshCats() {
                var eid = evSel.value;
                var cats = (eid !== '0' && eventCats[eid]) ? eventCats[eid] : [];
                catSel.innerHTML = '<option value="">🎫 Alle Ticketarten</option>';
                if (cats.length > 0) {
                    cats.forEach(function(c){
                        var o = document.createElement('option');
                        o.value = c; o.textContent = c;
                        catSel.appendChild(o);
                    });
                    catSel.style.display = '';
                } else {
                    catSel.style.display = 'none';
                }
            }

            function statuses() {
                var out = [];
                var boxes = document.querySelectorAll('.tix-bc-status');
                for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) out.push(boxes[i].value);
                return out;
            }
            function post(data) {
                data._wpnonce = nonce;
                var body = new URLSearchParams();
                Object.keys(data).forEach(function(k){
                    if (Array.isArray(data[k])) data[k].forEach(function(v){ body.append(k + '[]', v); });
                    else body.append(k, data[k]);
                });
                return fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body }).then(function(r){ return r.json(); });
            }
            var manualList = []; // [{email, name}]

            function refreshCount() {
                if (manualList.length > 0) {
                    countEl.textContent = '→ ' + manualList.length + ' Empfaenger (manuelle Auswahl)';
                    evSel.style.opacity = '0.4'; catSel.style.opacity = '0.4';
                    return;
                }
                evSel.style.opacity = ''; catSel.style.opacity = '';
                countEl.textContent = '…';
                post({ action:'tix_broadcast_count', event_id: evSel.value, cat_name: catSel.value, statuses: statuses() }).then(function(res){
                    countEl.textContent = res.success ? ('→ ' + res.data.count + ' Empfaenger') : '?';
                });
            }

            // ── Einzelkunden-Autocomplete ──
            var mSearch = document.getElementById('tix-bc-manual-search');
            var mResults = document.getElementById('tix-bc-manual-results');
            var mChips = document.getElementById('tix-bc-manual-chips');
            var mTimer = null;

            function renderChips() {
                mChips.innerHTML = '';
                manualList.forEach(function(c, idx){
                    var chip = document.createElement('span');
                    chip.className = 'tix-bc-chip';
                    var label = document.createElement('span');
                    label.textContent = (c.name ? c.name + ' — ' : '') + c.email;
                    var x = document.createElement('span');
                    x.className = 'x';
                    x.textContent = '×';
                    x.addEventListener('click', function(){
                        manualList.splice(idx, 1);
                        renderChips();
                        refreshCount();
                    });
                    chip.appendChild(label);
                    chip.appendChild(x);
                    mChips.appendChild(chip);
                });
            }

            mSearch.addEventListener('input', function(){
                var q = mSearch.value.trim();
                clearTimeout(mTimer);
                if (q.length < 2) { mResults.style.display = 'none'; return; }
                mTimer = setTimeout(function(){
                    post({ action:'tix_broadcast_search', q: q }).then(function(res){
                        if (!res.success || !res.data.length) {
                            mResults.innerHTML = '<div class="tix-bc-mres" style="color:#94a3b8;cursor:default;">Kein Treffer</div>';
                            mResults.style.display = 'block';
                            return;
                        }
                        mResults.innerHTML = '';
                        res.data.forEach(function(c){
                            var row = document.createElement('div');
                            row.className = 'tix-bc-mres';
                            row.innerHTML = '<strong>' + (c.name || '(ohne Namen)') + '</strong> — ' + c.email +
                                '<span style="float:right;color:#94a3b8;">' + c.orders + ' Best.</span>';
                            row.addEventListener('click', function(){
                                var exists = manualList.some(function(m){ return m.email === c.email; });
                                if (!exists) manualList.push({ email: c.email, name: c.name });
                                renderChips();
                                refreshCount();
                                mSearch.value = '';
                                mResults.style.display = 'none';
                            });
                            mResults.appendChild(row);
                        });
                        mResults.style.display = 'block';
                    });
                }, 250);
            });
            document.addEventListener('click', function(e){
                if (!mResults.contains(e.target) && e.target !== mSearch) mResults.style.display = 'none';
            });
            evSel.addEventListener('change', function(){ refreshCats(); refreshCount(); });
            catSel.addEventListener('change', refreshCount);
            document.querySelectorAll('.tix-bc-status').forEach(function(b){ b.addEventListener('change', refreshCount); });
            document.getElementById('tix-bc-all-btn').addEventListener('click', function(){
                evSel.value = '0';
                refreshCats();
                refreshCount();
            });
            refreshCats();
            refreshCount();

            // Auto-Draft alle 5s bei Aenderung
            var draftDirty = false;
            ['tix-bc-subject','tix-bc-body','tix-bc-sender','tix-bc-sender-name'].forEach(function(id){
                document.getElementById(id).addEventListener('input', function(){ draftDirty = true; });
            });
            function draftPayload() {
                return { action:'tix_broadcast_draft',
                       subject: document.getElementById('tix-bc-subject').value,
                       body: document.getElementById('tix-bc-body').value,
                       sender: document.getElementById('tix-bc-sender').value,
                       sender_name: document.getElementById('tix-bc-sender-name').value,
                       event_id: evSel.value };
            }
            setInterval(function(){
                if (!draftDirty) return;
                draftDirty = false;
                post(draftPayload());
            }, 5000);
            // Absender auch direkt vor Test/Versand speichern
            function saveSenderNow() {
                return post(draftPayload());
            }

            document.getElementById('tix-bc-load-last').addEventListener('click', function(){
                var subjEl = document.getElementById('tix-bc-subject');
                var bodyEl = document.getElementById('tix-bc-body');
                if ((subjEl.value.trim() || bodyEl.value.trim()) &&
                    !confirm('Aktuellen Betreff/Text mit der letzten Rundmail ueberschreiben?')) return;
                var btn = this;
                btn.disabled = true;
                post({ action:'tix_broadcast_load_last' }).then(function(res){
                    btn.disabled = false;
                    if (!res.success) {
                        msgEl.style.color = '#dc2626';
                        msgEl.textContent = '❌ ' + (res.data && res.data.message ? res.data.message : 'Fehler');
                        return;
                    }
                    subjEl.value = res.data.subject;
                    bodyEl.value = res.data.body;
                    draftDirty = true;
                    msgEl.style.color = '#059669';
                    msgEl.textContent = '✅ Vorlage geladen — ' + res.data.source;
                });
            });

            document.getElementById('tix-bc-test-btn').addEventListener('click', function(){
                var btn = this;
                btn.disabled = true;
                saveSenderNow().then(function(){
                    return post({ action:'tix_broadcast_test',
                           test_email: document.getElementById('tix-bc-test-email').value,
                           subject: document.getElementById('tix-bc-subject').value,
                           body: document.getElementById('tix-bc-body').value
                    });
                }).then(function(res){
                    btn.disabled = false;
                    msgEl.style.color = res.success ? '#059669' : '#dc2626';
                    msgEl.textContent = res.success ? ('✅ ' + res.data.message) : ('❌ ' + (res.data && res.data.message ? res.data.message : 'Fehler'));
                });
            });

            document.getElementById('tix-bc-send-btn').addEventListener('click', function(){
                var btn = this;
                var cnt, target;
                if (manualList.length > 0) {
                    cnt = manualList.length;
                    target = 'Manuelle Auswahl (' + manualList.map(function(m){ return m.email; }).join(', ') + ')';
                } else {
                    cnt = countEl.textContent.replace(/[^0-9]/g, '') || '?';
                    target = evSel.options[evSel.selectedIndex].text;
                    if (catSel.style.display !== 'none' && catSel.value) target += ' — nur ' + catSel.value;
                }
                if (!confirm('Rundmail an ' + cnt + ' Empfaenger senden?\n\nZiel: ' + target + '\n\nDas kann nicht rueckgaengig gemacht werden.')) return;
                btn.disabled = true;
                msgEl.textContent = '';

                saveSenderNow().then(function(){
                    var payload = { action:'tix_broadcast_start',
                           event_id: evSel.value,
                           cat_name: catSel.value,
                           statuses: statuses(),
                           subject: document.getElementById('tix-bc-subject').value,
                           body: document.getElementById('tix-bc-body').value };
                    if (manualList.length > 0) payload.manual_emails = manualList.map(function(m){ return m.email; });
                    return post(payload);
                }).then(function(res){
                    if (!res.success) {
                        btn.disabled = false;
                        msgEl.style.color = '#dc2626';
                        msgEl.textContent = '❌ ' + (res.data && res.data.message ? res.data.message : 'Fehler');
                        return;
                    }
                    var jobId = res.data.job_id, total = res.data.total;
                    var prog = document.getElementById('tix-bc-progress');
                    var bar = document.getElementById('tix-bc-bar');
                    var ptext = document.getElementById('tix-bc-progress-text');
                    prog.style.display = 'block';

                    function step() {
                        post({ action:'tix_broadcast_batch', job_id: jobId }).then(function(r){
                            if (!r.success) {
                                msgEl.style.color = '#dc2626';
                                msgEl.textContent = '❌ ' + (r.data && r.data.message ? r.data.message : 'Batch-Fehler');
                                btn.disabled = false;
                                return;
                            }
                            var pct = Math.round(r.data.sent / r.data.total * 100);
                            bar.style.width = pct + '%';
                            ptext.textContent = r.data.sent + ' / ' + r.data.total + ' gesendet (' + pct + '%)';
                            if (r.data.done) {
                                msgEl.style.color = '#059669';
                                msgEl.textContent = '✅ Fertig — ' + r.data.total + ' Mails versendet.';
                                btn.disabled = false;
                            } else {
                                setTimeout(step, 400);
                            }
                        });
                    }
                    step();
                });
            });
        })();
        </script>
        <?php
    }
}

TIX_Broadcast::init();
