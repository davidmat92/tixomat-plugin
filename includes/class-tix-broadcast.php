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
    const OPT_SENDER = '_tix_broadcast_sender';

    /** Absender fuer Rundmails (leer = WP/SMTP-Default). */
    public static function get_sender(): string {
        $v = get_option(self::OPT_SENDER, '');
        return is_email($v) ? $v : '';
    }

    public static function init() {
        add_action('admin_menu',                     array(__CLASS__, 'register_menu'), 64);
        add_action('wp_ajax_tix_broadcast_count',    array(__CLASS__, 'ajax_count'));
        add_action('wp_ajax_tix_broadcast_test',     array(__CLASS__, 'ajax_test'));
        add_action('wp_ajax_tix_broadcast_start',    array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_tix_broadcast_batch',    array(__CLASS__, 'ajax_batch'));
        add_action('wp_ajax_tix_broadcast_draft',    array(__CLASS__, 'ajax_save_draft'));
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
     * $event_id = 0 → alle Kunden.
     */
    public static function get_recipients(int $event_id, array $statuses): array {
        global $wpdb;
        if (empty($statuses)) $statuses = array('completed', 'processing', 'on-hold');
        $statuses = array_values(array_intersect($statuses, array('completed', 'processing', 'on-hold', 'pending', 'refunded')));
        if (empty($statuses)) return array();
        $ph = implode(',', array_fill(0, count($statuses), '%s'));

        if ($event_id > 0) {
            $sql = "SELECT o.billing_email AS email, MAX(o.billing_first_name) AS first_name
                    FROM {$wpdb->prefix}tix_orders o
                    JOIN {$wpdb->prefix}tix_order_items i ON i.order_id = o.id
                    WHERE i.event_id = %d
                      AND o.status IN ($ph)
                      AND o.billing_email <> ''
                    GROUP BY o.billing_email";
            $params = array_merge(array($event_id), $statuses);
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
        $accent = !empty($s['email_accent_color']) ? $s['email_accent_color'] : '#FF5500';

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;">'
            . '<div style="max-width:600px;margin:0 auto;padding:24px 12px;">'
            . '<div style="background:' . esc_attr($accent) . ';border-radius:12px 12px 0 0;padding:20px 28px;">'
            . '<span style="color:#fff;font-size:20px;font-weight:700;">' . esc_html($brand) . '</span>'
            . '</div>'
            . '<div style="background:#ffffff;border-radius:0 0 12px 12px;padding:28px;font-size:15px;line-height:1.6;color:#1f2937;">'
            . $body_html
            . '</div>'
            . '<div style="text-align:center;padding:16px;font-size:12px;color:#9ca3af;">'
            . esc_html($brand) . ' &middot; Diese E-Mail wurde an Kunden von ' . esc_html($brand) . ' gesendet.'
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
        $sender = self::get_sender();
        $from_filter = null;
        if ($sender !== '') {
            $s = get_option('tix_settings', array());
            $brand = !empty($s['email_brand_name']) ? $s['email_brand_name'] : get_bloginfo('name');
            $headers[] = 'From: ' . $brand . ' <' . $sender . '>';
            // wp_mail_from-Filter zusaetzlich, damit auch SMTP-Plugins den Absender uebernehmen
            $from_filter = function () use ($sender) { return $sender; };
            add_filter('wp_mail_from', $from_filter, 99);
        }

        $ok = (bool) wp_mail($recipient['email'], $subj, $html, $headers);

        if ($from_filter !== null) {
            remove_filter('wp_mail_from', $from_filter, 99);
        }
        return $ok;
    }

    /* ─────────── AJAX ─────────── */

    public static function ajax_count() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(array('message' => 'no perm'));
        check_ajax_referer('tix_broadcast');
        $event_id = intval($_POST['event_id'] ?? 0);
        $statuses = self::parse_statuses($_POST['statuses'] ?? array());
        $recipients = self::get_recipients($event_id, $statuses);
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
        wp_send_json_success(array('message' => 'Entwurf gespeichert'));
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
        $subject  = sanitize_text_field($_POST['subject'] ?? '');
        $body     = self::prepare_body((string) ($_POST['body'] ?? ''));

        if ($subject === '') wp_send_json_error(array('message' => 'Betreff fehlt.'));
        if (trim(wp_strip_all_tags($body)) === '') wp_send_json_error(array('message' => 'Nachricht fehlt.'));

        $recipients = self::get_recipients($event_id, $statuses);
        if (empty($recipients)) wp_send_json_error(array('message' => 'Keine Empfaenger gefunden.'));

        $job_id = 'bc_' . time() . '_' . wp_generate_password(6, false, false);
        set_transient('tix_broadcast_' . $job_id, array(
            'recipients' => $recipients,
            'subject'    => $subject,
            'body'       => $body,
            'sent'       => 0,
            'event_id'   => $event_id,
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
            // Abschluss-Log
            $log = get_option(self::OPT_LOG, array());
            if (!is_array($log)) $log = array();
            $current = wp_get_current_user();
            $log[] = array(
                'when'     => current_time('mysql'),
                'subject'  => $job['subject'],
                'event_id' => intval($job['event_id']),
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
                    <button type="button" class="button" id="tix-bc-all-btn" title="Mit einem Klick alle Kunden auswaehlen">🌍 Alle auswaehlen</button>
                    <span id="tix-bc-count" style="font-weight:700;color:#059669;"></span>
                </div>

                <details style="margin:8px 0 16px;">
                    <summary style="cursor:pointer;color:#64748b;font-size:13px;">Status-Filter (Standard: alle Zahler)</summary>
                    <div style="padding:10px 4px 0;display:flex;gap:16px;flex-wrap:wrap;font-size:13px;">
                        <label><input type="checkbox" class="tix-bc-status" value="completed" checked> ✅ Abgeschlossen</label>
                        <label><input type="checkbox" class="tix-bc-status" value="processing" checked> ⏳ In Bearbeitung</label>
                        <label><input type="checkbox" class="tix-bc-status" value="on-hold" checked> 🏦 Wartend (Ueberweisung)</label>
                    </div>
                </details>

                <label style="font-weight:600;display:block;margin-bottom:6px;">Absender-Adresse <small style="color:#94a3b8;font-weight:400;">(leer = Standard-Absender)</small></label>
                <input type="email" id="tix-bc-sender" value="<?php echo esc_attr(self::get_sender()); ?>" placeholder="z.B. dm@mallorca-festival-xxl.de"
                       style="width:100%;max-width:420px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;margin-bottom:14px;box-sizing:border-box;">

                <label style="font-weight:600;display:block;margin-bottom:6px;">Betreff</label>
                <input type="text" id="tix-bc-subject" value="<?php echo esc_attr($draft['subject'] ?? ''); ?>" placeholder="z.B. Wichtige Info zu deinem Festival-Ticket"
                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;margin-bottom:14px;box-sizing:border-box;">

                <label style="font-weight:600;display:block;margin-bottom:6px;">Nachricht <small style="color:#94a3b8;font-weight:400;">(HTML erlaubt, {vorname} wird ersetzt)</small></label>
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
                        $target_label = $target > 0 ? (get_the_title($target) ?: 'Event #' . $target) : 'Alle Kunden';
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
            var countEl = document.getElementById('tix-bc-count');
            var msgEl = document.getElementById('tix-bc-msg');

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
            function refreshCount() {
                countEl.textContent = '…';
                post({ action:'tix_broadcast_count', event_id: evSel.value, statuses: statuses() }).then(function(res){
                    countEl.textContent = res.success ? ('→ ' + res.data.count + ' Empfaenger') : '?';
                });
            }
            evSel.addEventListener('change', refreshCount);
            document.querySelectorAll('.tix-bc-status').forEach(function(b){ b.addEventListener('change', refreshCount); });
            document.getElementById('tix-bc-all-btn').addEventListener('click', function(){
                evSel.value = '0';
                refreshCount();
            });
            refreshCount();

            // Auto-Draft alle 5s bei Aenderung
            var draftDirty = false;
            ['tix-bc-subject','tix-bc-body','tix-bc-sender'].forEach(function(id){
                document.getElementById(id).addEventListener('input', function(){ draftDirty = true; });
            });
            setInterval(function(){
                if (!draftDirty) return;
                draftDirty = false;
                post({ action:'tix_broadcast_draft',
                       subject: document.getElementById('tix-bc-subject').value,
                       body: document.getElementById('tix-bc-body').value,
                       sender: document.getElementById('tix-bc-sender').value,
                       event_id: evSel.value });
            }, 5000);
            // Absender auch direkt vor Test/Versand speichern
            function saveSenderNow() {
                return post({ action:'tix_broadcast_draft',
                       subject: document.getElementById('tix-bc-subject').value,
                       body: document.getElementById('tix-bc-body').value,
                       sender: document.getElementById('tix-bc-sender').value,
                       event_id: evSel.value });
            }

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
                var cnt = countEl.textContent.replace(/[^0-9]/g, '') || '?';
                var target = evSel.options[evSel.selectedIndex].text;
                if (!confirm('Rundmail an ' + cnt + ' Empfaenger senden?\n\nZiel: ' + target + '\n\nDas kann nicht rueckgaengig gemacht werden.')) return;
                btn.disabled = true;
                msgEl.textContent = '';

                saveSenderNow().then(function(){
                    return post({ action:'tix_broadcast_start',
                           event_id: evSel.value,
                           statuses: statuses(),
                           subject: document.getElementById('tix-bc-subject').value,
                           body: document.getElementById('tix-bc-body').value
                    });
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
