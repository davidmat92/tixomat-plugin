<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat — Sponsor Dashboard (Frontend)
 *
 * Shortcode: [tix_sponsor_dashboard]
 *
 * @since 1.38.171
 */
class TIX_Sponsor_Dashboard {

    public static function init() {
        add_shortcode('tix_sponsor_dashboard', [__CLASS__, 'render']);

        $actions = [
            'tix_sd_overview',           // KPIs + Pool-Liste
            'tix_sd_tickets',            // Alle Tickets des Sponsors
            'tix_sd_issue_tickets',      // Tickets ausgeben (bulk)
            'tix_sd_personalize',        // Ticket nachträglich personalisieren
            'tix_sd_resend_mail',        // Mail erneut senden
            'tix_sd_cancel_ticket',      // Ticket stornieren
        ];
        foreach ($actions as $a) {
            add_action('wp_ajax_' . $a, [__CLASS__, 'ajax_' . str_replace('tix_sd_', '', $a)]);
            add_action('wp_ajax_nopriv_' . $a, [__CLASS__, 'ajax_' . str_replace('tix_sd_', '', $a)]);
        }

        // CSV-Export + Bulk-PDF via admin-post
        add_action('admin_post_tix_sd_export_csv',         [__CLASS__, 'export_csv']);
        add_action('admin_post_nopriv_tix_sd_export_csv',  [__CLASS__, 'export_csv']);
        add_action('admin_post_tix_sd_bulk_pdf',           [__CLASS__, 'bulk_pdf']);
        add_action('admin_post_nopriv_tix_sd_bulk_pdf',    [__CLASS__, 'bulk_pdf']);
    }

    private static function guard() {
        check_ajax_referer('tix_sponsor_dashboard', 'nonce');
        if (!class_exists('TIX_Sponsor_Auth')) { wp_send_json_error(['message' => 'Auth-Modul fehlt.']); return null; }
        $s = TIX_Sponsor_Auth::get_current_sponsor();
        if (!$s) { wp_send_json_error(['message' => 'Nicht eingeloggt.']); return null; }
        return $s;
    }

    /* ──── AJAX: Overview (Pools mit Restkontingent) ──── */

    public static function ajax_overview() {
        $sponsor = self::guard();
        if (!$sponsor) return;
        $pools = TIX_Sponsor_DB::get_pools_by_sponsor(intval($sponsor->id));
        $data = [];
        foreach ($pools as $p) {
            $data[] = [
                'id'         => intval($p->id),
                'event_id'   => intval($p->event_id),
                'event_title'=> $p->event_title ?: '(Event #' . $p->event_id . ')',
                'cat_index'  => intval($p->cat_index),
                'cat_name'   => $p->cat_name,
                'total'      => intval($p->total),
                'used'       => intval($p->used),
                'available'  => max(0, intval($p->total) - intval($p->used)),
            ];
        }

        // Gutschein-Code des Sponsors (für reguläre Käufer) — aus Coupon-System auflösen
        $coupon = null;
        $code = trim((string) ($sponsor->coupon_code ?? ''));
        if ($code !== '' && class_exists('TIX_Coupons')) {
            $c = TIX_Coupons::find_by_code($code);
            if ($c) {
                $type     = $c['discount_type'] ?? 'percent';
                $val      = floatval($c['value'] ?? 0);
                $label    = ($type === 'percent')
                    ? rtrim(rtrim(number_format($val, 2, ',', '.'), '0'), ',') . ' % Rabatt'
                    : number_format($val, 2, ',', '.') . ' € Rabatt';
                $max      = intval($c['max_uses'] ?? 0);
                $used     = intval($c['used'] ?? 0);
                $coupon   = [
                    'code'        => strtoupper($code),
                    'discount'    => $label,
                    'expires'     => $c['expires'] ?? '',
                    'max_uses'    => $max,
                    'used'        => $used,
                    'remaining'   => $max > 0 ? max(0, $max - $used) : null,
                    'share_url'   => add_query_arg('coupon', strtoupper($code), home_url('/')),
                ];
            } else {
                $coupon = ['code' => strtoupper($code), 'error' => 'Code im Gutschein-System nicht gefunden — bitte Veranstalter kontaktieren.'];
            }
        }

        wp_send_json_success([
            'sponsor' => ['name' => $sponsor->name, 'contact_name' => $sponsor->contact_name],
            'pools'   => $data,
            'coupon'  => $coupon,
        ]);
    }

    /* ──── AJAX: Tickets (alle ausgegebenen) ──── */

    public static function ajax_tickets() {
        $sponsor = self::guard();
        if (!$sponsor) return;

        $tids = TIX_Sponsor_DB::get_sponsor_tickets(intval($sponsor->id));
        $rows = [];
        foreach ($tids as $tid) {
            $tid = intval($tid);
            $code        = get_post_meta($tid, '_tix_ticket_code', true);
            $event_id    = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
            $cat_name    = get_post_meta($tid, '_tix_ticket_cat_name', true);
            $owner_name  = get_post_meta($tid, '_tix_ticket_owner_name', true);
            $owner_email = get_post_meta($tid, '_tix_ticket_owner_email', true);
            $status      = get_post_meta($tid, '_tix_ticket_status', true);
            $checked     = intval(get_post_meta($tid, '_tix_ticket_checked_in', true)) ? true : false;
            $sent_at     = get_post_meta($tid, '_tix_ticket_sponsor_sent_at', true);
            $personalized= !empty($owner_email);

            $event = get_post($event_id);
            $event_title = $event ? $event->post_title : '';

            // Online-URL + PDF-URL via TIX_Tickets
            $online_url = class_exists('TIX_Tickets') ? TIX_Tickets::get_download_url($tid) : '';

            $rows[] = [
                'ticket_id'    => $tid,
                'code'         => $code,
                'event_title'  => $event_title,
                'event_id'     => $event_id,
                'cat_name'     => $cat_name,
                'owner_name'   => $owner_name,
                'owner_email'  => $owner_email,
                'status'       => $status,
                'checked_in'   => $checked,
                'personalized' => $personalized,
                'sent_at'      => $sent_at,
                'online_url'   => $online_url,
                'pdf_url'      => $online_url ? add_query_arg('format', 'pdf', $online_url) : '',
            ];
        }
        wp_send_json_success(['tickets' => $rows]);
    }

    /* ──── AJAX: Tickets ausgeben (Bulk) ──── */

    public static function ajax_issue_tickets() {
        $sponsor = self::guard();
        if (!$sponsor) return;

        $pool_id   = intval($_POST['pool_id'] ?? 0);
        $mode      = sanitize_text_field($_POST['mode'] ?? 'personalized_mail');
        // mode: 'personalized_mail' | 'personalized_nomail' | 'anonymous'
        $recipients_raw = (string) ($_POST['recipients'] ?? '');
        $anonymous_qty  = intval($_POST['anonymous_qty'] ?? 0);
        $send_pdf       = !empty($_POST['send_pdf']);
        $send_online    = !empty($_POST['send_online']);

        $pool = TIX_Sponsor_DB::get_pool($pool_id);
        if (!$pool || intval($pool->sponsor_id) !== intval($sponsor->id)) {
            wp_send_json_error(['message' => 'Pool nicht gefunden.']);
        }

        $available = max(0, intval($pool->total) - intval($pool->used));
        if ($available <= 0) wp_send_json_error(['message' => 'Kontingent erschöpft.']);

        // Empfänger parsen
        $recipients = [];
        if ($mode === 'anonymous') {
            $count = min($anonymous_qty, $available);
            for ($i = 0; $i < $count; $i++) {
                $recipients[] = ['name' => '', 'email' => ''];
            }
        } else {
            // Personalized: "Name, Email" pro Zeile (oder nur Email)
            foreach (explode("\n", $recipients_raw) as $line) {
                $line = trim($line);
                if (!$line) continue;
                // Split: zuerst nach Komma, dann nach Semikolon, dann nach Tab
                $parts = preg_split('/[,;\t]/', $line, 2);
                $name  = trim($parts[0] ?? '');
                $email = trim($parts[1] ?? '');
                // Wenn nur Email → name = ''
                if (!$email && is_email($name)) {
                    $email = $name;
                    $name = '';
                }
                if (!$email && !$name) continue;
                if ($email && !is_email($email)) continue;
                $recipients[] = ['name' => $name, 'email' => $email];
            }
        }

        if (empty($recipients)) wp_send_json_error(['message' => 'Keine gültigen Empfänger gefunden.']);
        if (count($recipients) > $available) {
            wp_send_json_error(['message' => 'Nur noch ' . $available . ' Tickets verfügbar (du wolltest ' . count($recipients) . ').']);
        }

        // Tickets erstellen
        $created = [];
        $sent    = 0;
        foreach ($recipients as $rec) {
            $tid = self::create_ticket($sponsor, $pool, $rec);
            if (!$tid) continue;
            $created[] = $tid;
            // Pool used hochzählen
            TIX_Sponsor_DB::increment_used($pool_id, 1);

            // Mail versenden wenn personalized_mail + Email vorhanden
            if ($mode === 'personalized_mail' && !empty($rec['email'])) {
                self::send_ticket_email($tid, $rec['email'], $sponsor, $send_pdf, $send_online);
                update_post_meta($tid, '_tix_ticket_sponsor_sent_at', current_time('mysql'));
                $sent++;
            }
        }

        wp_send_json_success([
            'created' => count($created),
            'sent'    => $sent,
            'message' => count($created) . ' Tickets erstellt' . ($sent > 0 ? ' (' . $sent . ' per Mail versandt)' : ''),
        ]);
    }

    /**
     * Erstellt ein einzelnes Ticket (tix_ticket Post + Meta).
     */
    private static function create_ticket($sponsor, $pool, array $rec): int {
        $event_id = intval($pool->event_id);
        $cat_idx  = intval($pool->cat_index);
        $cat_name = $pool->cat_name;

        // Eindeutigen Code generieren
        $code = self::generate_unique_code();

        // Post-Anlage
        $tid = wp_insert_post([
            'post_type'   => 'tix_ticket',
            'post_status' => 'publish',
            'post_title'  => $code,
            'post_date'   => current_time('mysql'),
        ]);
        if (is_wp_error($tid) || !$tid) return 0;

        // Meta
        update_post_meta($tid, '_tix_ticket_code',       $code);
        update_post_meta($tid, '_tix_ticket_event_id',   $event_id);
        update_post_meta($tid, '_tix_ticket_order_id',   0); // keine Order — Sponsor-Ticket
        update_post_meta($tid, '_tix_ticket_cat_index',  $cat_idx);
        update_post_meta($tid, '_tix_ticket_cat_name',   $cat_name);
        update_post_meta($tid, '_tix_ticket_owner_name', $rec['name'] ?? '');
        update_post_meta($tid, '_tix_ticket_owner_email', $rec['email'] ?? '');
        update_post_meta($tid, '_tix_ticket_status',     'valid');
        update_post_meta($tid, '_tix_ticket_price',      0); // Sponsor-Tickets sind 0 €

        // Sponsor-Specific
        update_post_meta($tid, '_tix_ticket_sponsor_id',      intval($sponsor->id));
        update_post_meta($tid, '_tix_ticket_sponsor_pool_id', intval($pool->id));

        // Download-Token + Title aktualisieren
        if (class_exists('TIX_Tickets')) {
            TIX_Tickets::ensure_download_token($tid);
        } else {
            // Fallback: Token selbst generieren
            $token = bin2hex(random_bytes(32));
            update_post_meta($tid, '_tix_ticket_download_token', $token);
        }

        return $tid;
    }

    /**
     * Generiert einen eindeutigen 9-Zeichen Ticket-Code.
     */
    private static function generate_unique_code(): string {
        global $wpdb;
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 20; $try++) {
            $code = '';
            for ($i = 0; $i < 9; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
            // Existiert schon?
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tix_ticket_code' AND meta_value = %s LIMIT 1",
                $code
            ));
            if (!$exists) return $code;
        }
        return strtoupper(substr(md5(uniqid('', true)), 0, 9));
    }

    /**
     * Sendet Ticket-Mail an Empfänger.
     */
    private static function send_ticket_email(int $tid, string $email, $sponsor, bool $send_pdf, bool $send_online) {
        $event_id   = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
        $event      = get_post($event_id);
        $event_name = $event ? $event->post_title : '';
        $owner_name = get_post_meta($tid, '_tix_ticket_owner_name', true);
        $code       = get_post_meta($tid, '_tix_ticket_code', true);
        $online_url = class_exists('TIX_Tickets') ? TIX_Tickets::get_download_url($tid) : '';

        $subject = 'Dein Ticket für ' . $event_name;

        $body  = '<p>Hallo' . ($owner_name ? ' ' . esc_html($owner_name) : '') . ',</p>';
        $body .= '<p>du bekommst dieses Ticket als <strong>Sponsoring-Einladung</strong> von <strong>' . esc_html($sponsor->name) . '</strong>:</p>';
        $body .= '<p style="background:#fef3c7;padding:14px 18px;border-radius:8px;font-size:16px;">';
        $body .= '<strong>' . esc_html($event_name) . '</strong><br>';
        $body .= 'Ticket-Code: <code>' . esc_html($code) . '</code></p>';

        if ($send_online && $online_url) {
            $body .= '<p style="text-align:center;margin:24px 0;">';
            $body .= '<a href="' . esc_url($online_url) . '" style="display:inline-block;padding:14px 28px;background:#FF5500;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:15px;">Online-Ticket öffnen</a>';
            $body .= '</p>';
        }

        $body .= '<p style="color:#64748b;font-size:13px;">Bitte beim Einlass den QR-Code zeigen (Papier oder Handy — beides funktioniert).</p>';
        $body .= '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Bei Fragen wende dich an ' . esc_html($sponsor->name) . ' (' . esc_html($sponsor->email) . ').</p>';

        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html('Dein Ticket', $body, $event_name)
            : '<html><body>' . $body . '</body></html>';

        $attachments = [];
        if ($send_pdf && class_exists('TIX_Tickets') && TIX_Tickets::has_pdf_template($tid)) {
            $bin = TIX_Tickets::get_pdf_binary($tid);
            if ($bin) {
                $upload_dir = wp_upload_dir();
                $tmp_dir = trailingslashit($upload_dir['basedir']) . 'tix-sponsor-tmp';
                if (!file_exists($tmp_dir)) {
                    wp_mkdir_p($tmp_dir);
                    @file_put_contents($tmp_dir . '/.htaccess', "deny from all\n");
                }
                $path = $tmp_dir . '/ticket-' . $code . '.pdf';
                if (file_put_contents($path, $bin)) {
                    $attachments[] = $path;
                }
            }
        }

        wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $attachments);

        // Tmp-Files aufräumen
        foreach ($attachments as $f) @unlink($f);
    }

    /* ──── AJAX: Personalisieren / Edit ──── */

    public static function ajax_personalize() {
        $sponsor = self::guard();
        if (!$sponsor) return;

        $tid   = intval($_POST['ticket_id'] ?? 0);
        $name  = sanitize_text_field($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $send_mail = !empty($_POST['send_mail']);

        if (intval(get_post_meta($tid, '_tix_ticket_sponsor_id', true)) !== intval($sponsor->id)) {
            wp_send_json_error(['message' => 'Ticket gehört nicht zu diesem Sponsor.']);
        }
        if ($email && !is_email($email)) wp_send_json_error(['message' => 'Ungültige E-Mail-Adresse.']);

        update_post_meta($tid, '_tix_ticket_owner_name', $name);
        update_post_meta($tid, '_tix_ticket_owner_email', $email);

        if ($send_mail && $email) {
            self::send_ticket_email($tid, $email, $sponsor, true, true);
            update_post_meta($tid, '_tix_ticket_sponsor_sent_at', current_time('mysql'));
        }

        wp_send_json_success(['message' => 'Personalisierung gespeichert' . ($send_mail && $email ? ' + Mail versandt' : '')]);
    }

    /* ──── AJAX: Re-Send Mail ──── */

    public static function ajax_resend_mail() {
        $sponsor = self::guard();
        if (!$sponsor) return;

        $tid = intval($_POST['ticket_id'] ?? 0);
        if (intval(get_post_meta($tid, '_tix_ticket_sponsor_id', true)) !== intval($sponsor->id)) {
            wp_send_json_error(['message' => 'Ticket gehört nicht zu diesem Sponsor.']);
        }
        $email = get_post_meta($tid, '_tix_ticket_owner_email', true);
        if (!$email) wp_send_json_error(['message' => 'Keine E-Mail-Adresse für dieses Ticket.']);

        self::send_ticket_email($tid, $email, $sponsor, true, true);
        update_post_meta($tid, '_tix_ticket_sponsor_sent_at', current_time('mysql'));
        wp_send_json_success(['message' => 'Mail erneut versandt an ' . $email]);
    }

    /* ──── AJAX: Ticket stornieren ──── */

    public static function ajax_cancel_ticket() {
        $sponsor = self::guard();
        if (!$sponsor) return;

        $tid = intval($_POST['ticket_id'] ?? 0);
        if (intval(get_post_meta($tid, '_tix_ticket_sponsor_id', true)) !== intval($sponsor->id)) {
            wp_send_json_error(['message' => 'Ticket gehört nicht zu diesem Sponsor.']);
        }
        $pool_id = intval(get_post_meta($tid, '_tix_ticket_sponsor_pool_id', true));

        update_post_meta($tid, '_tix_ticket_status', 'cancelled');

        // Pool used dekrementieren
        if ($pool_id) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE " . TIX_Sponsor_DB::table_pools() . " SET used = GREATEST(0, used - 1) WHERE id = %d",
                $pool_id
            ));
        }

        wp_send_json_success(['message' => 'Ticket storniert. Kontingent wieder frei.']);
    }

    /* ──── CSV-Export ──── */

    public static function export_csv() {
        if (!class_exists('TIX_Sponsor_Auth')) wp_die('Auth fehlt.');
        $sponsor = TIX_Sponsor_Auth::get_current_sponsor();
        if (!$sponsor) wp_die('Nicht eingeloggt.');

        $filename = 'sponsor-tickets-' . sanitize_file_name($sponsor->name) . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Code', 'Event', 'Kategorie', 'Name', 'E-Mail', 'Status', 'Eingecheckt', 'Versandt am', 'Online-Link'], ';');

        $tids = TIX_Sponsor_DB::get_sponsor_tickets(intval($sponsor->id));
        foreach ($tids as $tid) {
            $tid = intval($tid);
            $event = get_post(intval(get_post_meta($tid, '_tix_ticket_event_id', true)));
            $online_url = class_exists('TIX_Tickets') ? TIX_Tickets::get_download_url($tid) : '';
            fputcsv($out, [
                get_post_meta($tid, '_tix_ticket_code', true),
                $event ? $event->post_title : '',
                get_post_meta($tid, '_tix_ticket_cat_name', true),
                get_post_meta($tid, '_tix_ticket_owner_name', true),
                get_post_meta($tid, '_tix_ticket_owner_email', true),
                get_post_meta($tid, '_tix_ticket_status', true),
                intval(get_post_meta($tid, '_tix_ticket_checked_in', true)) ? 'ja' : 'nein',
                get_post_meta($tid, '_tix_ticket_sponsor_sent_at', true),
                $online_url,
            ], ';');
        }
        fclose($out);
        exit;
    }

    /* ──── Bulk-PDF (alle Tickets in einer PDF) ──── */

    public static function bulk_pdf() {
        if (!class_exists('TIX_Sponsor_Auth')) wp_die('Auth fehlt.');
        $sponsor = TIX_Sponsor_Auth::get_current_sponsor();
        if (!$sponsor) wp_die('Nicht eingeloggt.');

        $pool_id = intval($_GET['pool_id'] ?? 0);
        $args = ['pool_id' => $pool_id];
        $tids = TIX_Sponsor_DB::get_sponsor_tickets(intval($sponsor->id), $args);
        if (empty($tids)) wp_die('Keine Tickets vorhanden.');

        if (!class_exists('TIX_Tickets')) wp_die('TIX_Tickets fehlt.');

        // Strategie: alle Einzel-PDFs holen und concatenaten
        // Einfachste Variante: nutze die existing get_pdf_binary() pro Ticket
        // und konkatenieren mit FPDI (falls vorhanden) oder schreibe alle als ein PDF mit minimal_pdf

        // Pragmatische Umsetzung: ein PDF pro Seite mit eingebettetem JPG (wie minimal_pdf macht)
        $jpegs = []; // [jpeg_binary, w, h]
        foreach ($tids as $tid) {
            $tid = intval($tid);
            if (!TIX_Tickets::has_pdf_template($tid)) continue;
            $event_id = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
            if (!class_exists('TIX_Ticket_Template')) continue;
            $config = TIX_Ticket_Template::get_effective_config($event_id);
            if (!$config) continue;
            $gd = TIX_Ticket_Template::render_ticket_image($tid, $config);
            if (!$gd) continue;
            ob_start();
            imagejpeg($gd, null, 90);
            $jpeg = ob_get_clean();
            $jpegs[] = ['data' => $jpeg, 'w' => imagesx($gd), 'h' => imagesy($gd)];
            imagedestroy($gd);
        }
        if (empty($jpegs)) wp_die('Keine PDF-Templates für die Tickets konfiguriert.');

        // PDF zusammenbauen
        $pdf = self::build_multi_page_pdf($jpegs);
        $filename = 'sponsor-tickets-' . sanitize_file_name($sponsor->name) . '-' . date('Y-m-d') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /**
     * Multi-Page-PDF: pro Ticket eine Seite mit eingebettetem JPEG.
     * Minimal-PDF-Struktur wie TIX_Ticket_Template::create_minimal_pdf, erweitert auf N Seiten.
     */
    private static function build_multi_page_pdf(array $jpegs): string {
        $objects = [];
        $catalog_id = 1;
        $pages_id = 2;

        // Pro Ticket: Image-Object + Page-Object + Content-Stream
        $page_ids = [];
        foreach ($jpegs as $i => $jpeg) {
            $img_id = 3 + $i * 3;
            $content_id = $img_id + 1;
            $page_id = $img_id + 2;
            $page_ids[] = $page_id;

            $w = $jpeg['w']; $h = $jpeg['h'];
            // PDF-Seite in Punkten — wir nehmen die JPG-Maße direkt
            $page_w = $w; $page_h = $h;

            $objects[$img_id] = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg['data']) . " >>\nstream\n" . $jpeg['data'] . "\nendstream";
            $content = "q\n$page_w 0 0 $page_h 0 0 cm\n/Im0 Do\nQ\n";
            $objects[$content_id] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
            $objects[$page_id] = "<< /Type /Page /Parent $pages_id 0 R /MediaBox [0 0 $page_w $page_h] /Contents $content_id 0 R /Resources << /XObject << /Im0 $img_id 0 R >> >> >>";
        }

        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $page_ids));
        $objects[$pages_id] = "<< /Type /Pages /Kids [$kids] /Count " . count($page_ids) . " >>";
        $objects[$catalog_id] = "<< /Type /Catalog /Pages $pages_id 0 R >>";

        // PDF zusammenbauen
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref_offset = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $off = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size $count /Root $catalog_id 0 R >>\nstartxref\n$xref_offset\n%%EOF";

        return $pdf;
    }

    /* ══════════════════════════════════════════
     * Render Shortcode
     * ══════════════════════════════════════════ */

    public static function render($atts = []) {
        if (!class_exists('TIX_Sponsor_DB')) {
            return '<div class="tix-sd"><p>Sponsor-Modul nicht verfügbar.</p></div>';
        }

        $sponsor = class_exists('TIX_Sponsor_Auth') ? TIX_Sponsor_Auth::get_current_sponsor() : null;
        if (!$sponsor) return self::render_login();

        wp_enqueue_script('jquery');
        wp_enqueue_style('dashicons');

        $nonce  = wp_create_nonce('tix_sponsor_dashboard');
        $logout = TIX_Sponsor_Auth::logout_url();
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=tix_sd_export_csv'), 'tix_sd_export');

        $is_admin_preview = !empty($_GET['tix_admin_preview']) && current_user_can('manage_options');
        ob_start();
        ?>
        <style>
        #tix-sponsor-dashboard .button,
        #tix-sponsor-dashboard a.button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.2;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #0f172a;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s, border-color .15s, color .15s, transform .05s, box-shadow .15s;
            box-shadow: 0 1px 0 rgba(15,23,42,0.04);
            font-family: inherit;
            white-space: nowrap;
        }
        #tix-sponsor-dashboard .button:hover { background: #f9fafb; border-color: #9ca3af; color: #0f172a; }
        #tix-sponsor-dashboard .button:active { transform: translateY(1px); }
        #tix-sponsor-dashboard .button:focus { outline: 2px solid rgba(255,85,0,0.35); outline-offset: 1px; }
        #tix-sponsor-dashboard .button-primary,
        #tix-sponsor-dashboard a.button-primary {
            background: var(--tix-acc-primary, #FF5500);
            border-color: var(--tix-acc-primary, #FF5500);
            color: #fff;
            box-shadow: 0 2px 6px rgba(255,85,0,0.22);
        }
        #tix-sponsor-dashboard .button-primary:hover { background: #e64a00; border-color: #e64a00; color: #fff; }
        #tix-sponsor-dashboard .button[disabled],
        #tix-sponsor-dashboard .button:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }
        #tix-sponsor-dashboard .button-small { padding: 4px 9px; font-size: 11px; font-weight: 500; border-radius: 6px; }
        </style>
        <div class="tix-sd" id="tix-sponsor-dashboard" style="--tix-acc-primary: #FF5500;">
            <?php if ($is_admin_preview): ?>
            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 16px;margin-bottom:16px;color:#7c2d12;font-size:13px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <span><strong>👁 Admin-Vorschau:</strong> Du siehst gerade das Portal aus Sicht des Sponsors <strong><?php echo esc_html($sponsor->name); ?></strong>. Alle Aktionen wirken sich real aus.</span>
                <a href="<?php echo esc_url($logout); ?>" style="background:#7c2d12;color:#fff;padding:4px 12px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;">← Vorschau beenden</a>
            </div>
            <?php endif; ?>
            <header style="display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:22px;">
                <div>
                    <h1 style="margin:0 0 4px;font-size:24px;">🎟️ Sponsor-Portal</h1>
                    <p style="margin:0;color:#64748b;font-size:14px;">Hallo, <strong><?php echo esc_html($sponsor->contact_name ?: $sponsor->name); ?></strong> — willkommen.</p>
                </div>
                <a href="<?php echo esc_url($logout); ?>" style="font-size:12px;color:#64748b;text-decoration:none;border:1px solid #e5e7eb;padding:6px 12px;border-radius:6px;">Abmelden</a>
            </header>

            <!-- Erklärungs-Card -->
            <details open style="background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border:1px solid #fcd34d;border-radius:12px;margin-bottom:18px;color:#7c2d12;">
                <summary style="cursor:pointer;padding:14px 20px;font-weight:700;font-size:15px;list-style:none;display:flex;align-items:center;justify-content:space-between;">
                    <span>👋 Willkommen — so funktioniert dein Sponsor-Portal</span>
                    <span style="font-size:11px;opacity:0.7;">Klick zum Aus-/Einklappen</span>
                </summary>
                <div style="padding:0 20px 16px;font-size:13px;line-height:1.7;">

                    <p style="margin:0 0 12px;">Du verteilst hier deine Sponsor-Tickets <strong>selbständig</strong>. Wir haben dir alles in 3 Schritten erklärt — kein Aufwand, keine technischen Tricks.</p>

                    <!-- Schritt 1 -->
                    <div style="background:rgba(255,255,255,0.6);border-radius:8px;padding:12px 14px;margin-bottom:10px;">
                        <strong style="font-size:14px;">1️⃣ Pool auswählen</strong><br>
                        Unten siehst du deine <strong>Ticket-Pools</strong> — pro Event und Kategorie eine Karte. Dort steht wie viele Tickets du noch frei hast.
                    </div>

                    <!-- Schritt 2 -->
                    <div style="background:rgba(255,255,255,0.6);border-radius:8px;padding:12px 14px;margin-bottom:10px;">
                        <strong style="font-size:14px;">2️⃣ Tickets ausgeben — drei Wege</strong>
                        <ul style="margin:6px 0 0;padding-left:22px;">
                            <li><strong>📧 Personalisiert + per Mail:</strong> Du tippst Name + E-Mail rein, wir verschicken automatisch ein Ticket mit PDF und Online-Link. Empfehlung für die meisten Fälle.</li>
                            <li><strong>✏️ Personalisiert ohne Mail:</strong> Tickets mit Namen erstellen, aber du verteilst sie selbst (z.B. ausdrucken und persönlich übergeben).</li>
                            <li><strong>🎫 Anonym:</strong> N Tickets ohne Namen — perfekt zum Drucken auf Vorrat. Namen kannst du jederzeit später nachtragen.</li>
                        </ul>
                    </div>

                    <!-- Schritt 3 -->
                    <div style="background:rgba(255,255,255,0.6);border-radius:8px;padding:12px 14px;margin-bottom:0;">
                        <strong style="font-size:14px;">3️⃣ Was du mit jedem Ticket machen kannst</strong>
                        <div style="margin-top:6px;display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:6px;">
                            <div>👁 <strong>Online-Link</strong> öffnen + teilen</div>
                            <div>↓ <strong>PDF</strong> herunterladen + drucken</div>
                            <div>✎ <strong>Name/E-Mail</strong> nachträglich ändern</div>
                            <div>📧 <strong>Mail erneut senden</strong></div>
                            <div>× <strong>Stornieren</strong> (Kontingent wird frei)</div>
                            <div>📋 <strong>Bulk-PDF</strong> aller Tickets eines Pools</div>
                        </div>
                    </div>

                    <p style="margin:12px 0 0;font-style:italic;font-size:12px;opacity:0.85;">💡 <strong>Tipp:</strong> Die Empfänger brauchen das Ticket beim Einlass — entweder den QR-Code auf dem Handy oder auf Papier. Beides funktioniert.</p>
                </div>
            </details>

            <!-- Gutschein-Code für reguläre Käufer -->
            <div id="tix-sd-coupon"></div>

            <!-- Pool-Liste -->
            <div id="tix-sd-pools"></div>

            <!-- Tickets-Tabelle -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;margin-top:18px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                    <h2 style="margin:0;font-size:16px;">Alle ausgegebenen Tickets</h2>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <a href="<?php echo esc_url($export_url); ?>" class="button">📥 CSV-Export</a>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;" id="tix-sd-ticket-table">
                        <thead><tr style="background:#f9fafb;">
                            <th style="text-align:left;padding:10px;">Code</th>
                            <th style="text-align:left;padding:10px;">Event</th>
                            <th style="text-align:left;padding:10px;">Kategorie</th>
                            <th style="text-align:left;padding:10px;">Name / E-Mail</th>
                            <th style="text-align:left;padding:10px;">Status</th>
                            <th style="text-align:right;padding:10px;">Aktionen</th>
                        </tr></thead>
                        <tbody id="tix-sd-tickets"><tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;">Lade&hellip;</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ISSUE-Modal: Tickets ausgeben -->
        <div id="tix-sd-issue-modal" style="display:none;position:fixed;inset:0;z-index:99999;">
            <div style="position:absolute;inset:0;background:rgba(15,23,42,0.55);" class="tix-sd-modal-close"></div>
            <div style="position:relative;max-width:640px;margin:30px auto;background:#fff;border-radius:14px;box-shadow:0 24px 64px rgba(15,23,42,0.25);max-height:calc(100vh - 60px);overflow-y:auto;">
                <header style="padding:16px 22px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h2 style="margin:0;font-size:18px;">Tickets ausgeben</h2>
                        <p style="margin:4px 0 0;color:#64748b;font-size:13px;"><span id="tix-sd-issue-pool-label">—</span></p>
                    </div>
                    <button type="button" class="button button-small tix-sd-modal-close">×</button>
                </header>
                <div style="padding:22px;">
                    <div style="margin-bottom:16px;">
                        <strong style="font-size:13px;display:block;margin-bottom:8px;">Wie willst du die Tickets ausgeben?</strong>
                        <label style="display:block;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:6px;cursor:pointer;">
                            <input type="radio" name="tix-sd-mode" value="anonymous" checked>
                            <strong>🎫 Stapel ohne Namen — z. B. 50 Tickets auf einmal</strong>
                            <div style="font-size:12px;color:#64748b;margin-left:22px;">Du gibst nur eine Anzahl ein. Tickets werden sofort erstellt und können später personalisiert oder als PDF gedruckt werden.</div>
                        </label>
                        <label style="display:block;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:6px;cursor:pointer;">
                            <input type="radio" name="tix-sd-mode" value="personalized_mail">
                            <strong>📧 Mit Namen + sofort per Mail versenden</strong>
                            <div style="font-size:12px;color:#64748b;margin-left:22px;">Liste von Empfängern (eine pro Zeile) — jeder bekommt sein Ticket sofort per Mail (mit PDF + Online-Link).</div>
                        </label>
                        <label style="display:block;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                            <input type="radio" name="tix-sd-mode" value="personalized_nomail">
                            <strong>✏️ Mit Namen, OHNE Mail</strong>
                            <div style="font-size:12px;color:#64748b;margin-left:22px;">Tickets werden mit Namen erstellt, du verteilst sie selbst (Bulk-PDF / Online-Links).</div>
                        </label>
                    </div>

                    <div id="tix-sd-recipients-wrap" style="display:none;">
                        <strong style="font-size:13px;display:block;margin-bottom:6px;">Empfänger — eine pro Zeile (Format: <em>Name, E-Mail</em>)</strong>
                        <textarea id="tix-sd-recipients" rows="8" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;box-sizing:border-box;" placeholder="Max Mustermann, max@beispiel.de&#10;Anna Schmidt, anna@web.de&#10;… (beliebig viele Zeilen)"></textarea>
                        <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">💡 Tipp: Du kannst zwei Spalten direkt aus Excel/Google Sheets kopieren und hier einfügen. Jede Zeile = 1 Ticket. <span id="tix-sd-recipients-count" style="font-weight:600;color:#0f172a;"></span></small>
                    </div>

                    <div id="tix-sd-anonymous-wrap">
                        <strong style="font-size:13px;display:block;margin-bottom:8px;">Wie viele Tickets erstellen?</strong>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <input type="number" id="tix-sd-anonymous-qty" value="50" min="1" max="500" style="width:120px;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:18px;font-weight:700;text-align:center;">
                            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                <button type="button" class="button button-small tix-sd-qty-chip" data-qty="10">10</button>
                                <button type="button" class="button button-small tix-sd-qty-chip" data-qty="25">25</button>
                                <button type="button" class="button button-small tix-sd-qty-chip" data-qty="50">50</button>
                                <button type="button" class="button button-small tix-sd-qty-chip" data-qty="100">100</button>
                            </div>
                        </div>
                        <small style="color:#64748b;font-size:11px;display:block;margin-top:6px;">Maximum entspricht deinem Restkontingent in diesem Pool.</small>
                    </div>

                    <div id="tix-sd-mail-options" style="margin-top:14px;padding:10px 12px;background:#f9fafb;border-radius:8px;">
                        <label style="display:block;margin-bottom:4px;font-size:13px;"><input type="checkbox" id="tix-sd-send-pdf" checked> PDF-Ticket als Anhang mitsenden</label>
                        <label style="display:block;font-size:13px;"><input type="checkbox" id="tix-sd-send-online" checked> Online-Link in der Mail erwähnen</label>
                    </div>
                </div>
                <footer style="padding:14px 22px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px;background:#f9fafb;">
                    <button type="button" class="button tix-sd-modal-close">Abbrechen</button>
                    <button type="button" class="button button-primary" id="tix-sd-issue-submit">Tickets erstellen</button>
                </footer>
            </div>
        </div>

        <!-- PERSONALIZE-Modal -->
        <div id="tix-sd-pers-modal" style="display:none;position:fixed;inset:0;z-index:99999;">
            <div style="position:absolute;inset:0;background:rgba(15,23,42,0.55);" class="tix-sd-pers-close"></div>
            <div style="position:relative;max-width:520px;margin:60px auto;background:#fff;border-radius:14px;box-shadow:0 24px 64px rgba(15,23,42,0.25);">
                <header style="padding:16px 22px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
                    <h2 style="margin:0;font-size:18px;">Ticket personalisieren</h2>
                    <button type="button" class="button button-small tix-sd-pers-close">×</button>
                </header>
                <div style="padding:22px;display:flex;flex-direction:column;gap:12px;">
                    <input type="hidden" id="tix-sd-pers-tid" value="0">
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:4px;">Name</label>
                        <input type="text" id="tix-sd-pers-name" style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="Max Mustermann">
                    </div>
                    <div>
                        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:4px;">E-Mail (optional)</label>
                        <input type="email" id="tix-sd-pers-email" style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="max@beispiel.de">
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#475569;">
                        <input type="checkbox" id="tix-sd-pers-mail"> Ticket nach Speichern direkt per Mail versenden
                    </label>
                </div>
                <footer style="padding:14px 22px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px;background:#f9fafb;">
                    <button type="button" class="button tix-sd-pers-close">Abbrechen</button>
                    <button type="button" class="button button-primary" id="tix-sd-pers-save">Speichern</button>
                </footer>
            </div>
        </div>

        <script>
        (function($) {
            var nonce = '<?php echo esc_js($nonce); ?>';
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var currentIssuePool = null;

            function esc(s) { var d = document.createElement('div'); d.textContent = (s === null || s === undefined ? '' : s); return d.innerHTML; }

            function loadOverview() {
                $.post(ajaxUrl, { action: 'tix_sd_overview', nonce: nonce }, function(r) {
                    if (!r.success) return;

                    // Coupon-Karte (Promo-Code für reguläre Käufer)
                    var couponHtml = '';
                    var cp = r.data.coupon;
                    if (cp && cp.error) {
                        couponHtml = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 18px;margin-bottom:14px;color:#991b1b;font-size:13px;">'
                                   + '<strong>Gutschein-Code „' + esc(cp.code) + '"</strong> — ' + esc(cp.error)
                                   + '</div>';
                    } else if (cp && cp.code) {
                        var usageLine = (cp.max_uses > 0)
                            ? cp.used + ' von ' + cp.max_uses + ' Einlösungen verbraucht (' + cp.remaining + ' frei)'
                            : cp.used + ' Einlösungen bisher';
                        var expLine = cp.expires ? ' · gültig bis ' + esc(cp.expires) : '';
                        couponHtml = '<div style="background:linear-gradient(135deg,#fff 0%,#fef3c7 100%);border:1px solid #fde68a;border-radius:12px;padding:18px 22px;margin-bottom:14px;">'
                                   + '<div style="font-size:11px;color:#92400e;text-transform:uppercase;letter-spacing:0.06em;font-weight:700;margin-bottom:6px;">Dein Promo-Code für deine Kunden</div>'
                                   + '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">'
                                       + '<div style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:28px;font-weight:800;letter-spacing:0.08em;color:#0f172a;background:#fff;border:2px dashed #f59e0b;border-radius:10px;padding:8px 18px;">' + esc(cp.code) + '</div>'
                                       + '<div>'
                                           + '<div style="font-size:18px;font-weight:700;color:#b45309;">' + esc(cp.discount) + ' auf reguläre Tickets</div>'
                                           + '<div style="font-size:12px;color:#78350f;margin-top:2px;">' + esc(usageLine) + esc(expLine) + '</div>'
                                       + '</div>'
                                   + '</div>'
                                   + '<div style="margin-top:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;color:#475569;">'
                                       + '<span>Direktlink zum Teilen:</span>'
                                       + '<input type="text" readonly value="' + esc(cp.share_url) + '" style="flex:1;min-width:240px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;background:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;" onclick="this.select();">'
                                       + '<button type="button" class="button button-small tix-sd-copy-link" data-link="' + esc(cp.share_url) + '">Link kopieren</button>'
                                   + '</div>'
                                   + '</div>';
                    }
                    $('#tix-sd-coupon').html(couponHtml);

                    var html = '';
                    if (!r.data.pools.length) {
                        html = '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;text-align:center;color:#9ca3af;">Noch keine Kontingente zugewiesen. Bitte wende dich an den Veranstalter.</div>';
                    } else {
                        html = '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px,1fr));gap:14px;">';
                        $.each(r.data.pools, function(i, p) {
                            var pct = p.total > 0 ? Math.round((p.used / p.total) * 100) : 0;
                            var bulkPdfUrl = '<?php echo esc_url(admin_url('admin-post.php?action=tix_sd_bulk_pdf')); ?>&pool_id=' + p.id;
                            html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;">' +
                                '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">' + esc(p.event_title) + '</div>' +
                                '<div style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:10px;">' + esc(p.cat_name) + '</div>' +
                                '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;">' +
                                    '<span style="font-size:28px;font-weight:800;color:#10b981;">' + p.available + '</span>' +
                                    '<span style="font-size:12px;color:#64748b;">von ' + p.total + ' frei</span>' +
                                '</div>' +
                                '<div style="height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-bottom:12px;">' +
                                    '<div style="height:100%;width:' + pct + '%;background:#FF5500;"></div>' +
                                '</div>' +
                                '<div style="display:flex;gap:6px;flex-wrap:wrap;">' +
                                    '<button class="button button-primary tix-sd-issue-btn" data-pool-id="' + p.id + '" data-pool-label="' + esc(p.event_title + ' — ' + p.cat_name + ' (' + p.available + ' frei)') + '"' + (p.available <= 0 ? ' disabled' : '') + '>+ Tickets ausgeben</button>' +
                                    (p.used > 0 ? '<a href="' + bulkPdfUrl + '" class="button">↓ Bulk-PDF</a>' : '') +
                                '</div>' +
                                '</div>';
                        });
                        html += '</div>';
                    }
                    $('#tix-sd-pools').html(html);
                });
            }

            function loadTickets() {
                $.post(ajaxUrl, { action: 'tix_sd_tickets', nonce: nonce }, function(r) {
                    if (!r.success) return;
                    var html = '';
                    if (!r.data.tickets.length) {
                        html = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">Noch keine Tickets ausgegeben.</td></tr>';
                    } else {
                        $.each(r.data.tickets, function(i, t) {
                            var statusBadge = '';
                            if (t.status === 'cancelled') {
                                statusBadge = '<span style="background:#fef2f2;color:#991b1b;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:700;">storniert</span>';
                            } else if (t.checked_in) {
                                statusBadge = '<span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:700;">✓ eingecheckt</span>';
                            } else if (t.sent_at) {
                                statusBadge = '<span style="background:#dbeafe;color:#1d4ed8;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:700;">versandt</span>';
                            } else if (t.personalized) {
                                statusBadge = '<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:700;">erstellt</span>';
                            } else {
                                statusBadge = '<span style="background:#f1f5f9;color:#475569;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:700;">anonym</span>';
                            }
                            var ownerDisp = t.owner_name || t.owner_email
                                ? '<strong>' + esc(t.owner_name || '(ohne Name)') + '</strong>' + (t.owner_email ? '<br><span style="color:#64748b;font-size:11px;">' + esc(t.owner_email) + '</span>' : '')
                                : '<span style="color:#9ca3af;font-style:italic;">noch nicht personalisiert</span>';

                            var actions = '';
                            actions += '<a href="' + esc(t.online_url) + '" target="_blank" class="button button-small" title="Online-Ticket öffnen">👁</a> ';
                            actions += '<a href="' + esc(t.pdf_url) + '" target="_blank" class="button button-small" title="PDF herunterladen">↓</a> ';
                            actions += '<button class="button button-small tix-sd-pers-btn" data-tid="' + t.ticket_id + '" data-name="' + esc(t.owner_name) + '" data-email="' + esc(t.owner_email) + '" title="Personalisieren/Ändern">✎</button> ';
                            if (t.owner_email && t.status !== 'cancelled') {
                                actions += '<button class="button button-small tix-sd-resend-btn" data-tid="' + t.ticket_id + '" title="Mail erneut senden">📧</button> ';
                            }
                            if (t.status !== 'cancelled' && !t.checked_in) {
                                actions += '<button class="button button-small tix-sd-cancel-btn" data-tid="' + t.ticket_id + '" title="Stornieren" style="color:#dc2626;">×</button>';
                            }

                            html += '<tr>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;"><code style="font-size:11px;">' + esc(t.code) + '</code></td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;">' + esc(t.event_title) + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;">' + esc(t.cat_name) + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;">' + ownerDisp + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;">' + statusBadge + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;text-align:right;white-space:nowrap;">' + actions + '</td>' +
                                '</tr>';
                        });
                    }
                    $('#tix-sd-tickets').html(html);
                });
            }

            // Mode-Switch
            $(document).on('change', 'input[name="tix-sd-mode"]', function() {
                var m = $(this).val();
                $('#tix-sd-recipients-wrap').toggle(m !== 'anonymous');
                $('#tix-sd-anonymous-wrap').toggle(m === 'anonymous');
                $('#tix-sd-mail-options').toggle(m === 'personalized_mail');
            });

            // Schnellwahl-Chips (10/25/50/100)
            $(document).on('click', '.tix-sd-qty-chip', function(e) {
                e.preventDefault();
                $('#tix-sd-anonymous-qty').val($(this).data('qty')).trigger('input');
            });

            // Promo-Code Direktlink kopieren
            $(document).on('click', '.tix-sd-copy-link', function() {
                var $btn = $(this); var link = $btn.data('link');
                var done = function() { var t = $btn.text(); $btn.text('✓ Kopiert'); setTimeout(function(){ $btn.text(t); }, 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(link).then(done, function(){ window.prompt('Link kopieren:', link); });
                } else {
                    window.prompt('Link kopieren:', link);
                }
            });

            // Live-Counter für Empfänger-Textarea
            $(document).on('input', '#tix-sd-recipients', function() {
                var lines = $(this).val().split('\n').filter(function(l) { return l.trim().length > 0; }).length;
                $('#tix-sd-recipients-count').text(lines > 0 ? '— erkannt: ' + lines + ' Ticket' + (lines === 1 ? '' : 's') : '');
            });

            // Issue-Modal öffnen
            $(document).on('click', '.tix-sd-issue-btn', function() {
                currentIssuePool = $(this).data('pool-id');
                $('#tix-sd-issue-pool-label').text($(this).data('pool-label'));
                $('#tix-sd-recipients').val('').trigger('input');
                $('#tix-sd-anonymous-qty').val('50');
                $('input[name="tix-sd-mode"][value="anonymous"]').prop('checked', true).trigger('change');
                $('#tix-sd-issue-modal').show();
                document.body.style.overflow = 'hidden';
            });

            $(document).on('click', '.tix-sd-modal-close', function() {
                $('#tix-sd-issue-modal').hide();
                document.body.style.overflow = '';
            });

            $('#tix-sd-issue-submit').on('click', function() {
                if (!currentIssuePool) return;
                var $btn = $(this); $btn.prop('disabled', true).text('Erstelle…');
                var mode = $('input[name="tix-sd-mode"]:checked').val();
                var data = {
                    action: 'tix_sd_issue_tickets', nonce: nonce,
                    pool_id: currentIssuePool, mode: mode,
                    recipients: $('#tix-sd-recipients').val(),
                    anonymous_qty: $('#tix-sd-anonymous-qty').val(),
                    send_pdf: $('#tix-sd-send-pdf').is(':checked') ? 1 : 0,
                    send_online: $('#tix-sd-send-online').is(':checked') ? 1 : 0,
                };
                $.post(ajaxUrl, data, function(r) {
                    $btn.prop('disabled', false).text('Tickets erstellen');
                    if (r.success) {
                        alert(r.data.message);
                        $('#tix-sd-issue-modal').hide();
                        document.body.style.overflow = '';
                        loadOverview(); loadTickets();
                    } else {
                        alert((r.data && r.data.message) || 'Fehler.');
                    }
                });
            });

            // Personalize-Modal
            $(document).on('click', '.tix-sd-pers-btn', function() {
                $('#tix-sd-pers-tid').val($(this).data('tid'));
                $('#tix-sd-pers-name').val($(this).data('name'));
                $('#tix-sd-pers-email').val($(this).data('email'));
                $('#tix-sd-pers-mail').prop('checked', false);
                $('#tix-sd-pers-modal').show();
                document.body.style.overflow = 'hidden';
            });
            $(document).on('click', '.tix-sd-pers-close', function() {
                $('#tix-sd-pers-modal').hide();
                document.body.style.overflow = '';
            });
            $('#tix-sd-pers-save').on('click', function() {
                var $btn = $(this); $btn.prop('disabled', true).text('Speichere…');
                $.post(ajaxUrl, {
                    action: 'tix_sd_personalize', nonce: nonce,
                    ticket_id: $('#tix-sd-pers-tid').val(),
                    name:      $('#tix-sd-pers-name').val(),
                    email:     $('#tix-sd-pers-email').val(),
                    send_mail: $('#tix-sd-pers-mail').is(':checked') ? 1 : 0,
                }, function(r) {
                    $btn.prop('disabled', false).text('Speichern');
                    if (r.success) {
                        $('#tix-sd-pers-modal').hide();
                        document.body.style.overflow = '';
                        loadTickets();
                    } else {
                        alert((r.data && r.data.message) || 'Fehler.');
                    }
                });
            });

            // Resend
            $(document).on('click', '.tix-sd-resend-btn', function() {
                if (!confirm('Ticket-Mail erneut senden?')) return;
                $.post(ajaxUrl, { action: 'tix_sd_resend_mail', nonce: nonce, ticket_id: $(this).data('tid') }, function(r) {
                    alert(r.success ? r.data.message : ((r.data && r.data.message) || 'Fehler.'));
                    if (r.success) loadTickets();
                });
            });

            // Cancel
            $(document).on('click', '.tix-sd-cancel-btn', function() {
                if (!confirm('Ticket wirklich stornieren? Kontingent wird wieder frei.')) return;
                $.post(ajaxUrl, { action: 'tix_sd_cancel_ticket', nonce: nonce, ticket_id: $(this).data('tid') }, function(r) {
                    if (r.success) { loadOverview(); loadTickets(); }
                    else alert((r.data && r.data.message) || 'Fehler.');
                });
            });

            // Init
            loadOverview();
            loadTickets();
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    private static function render_login() {
        $nonce = wp_create_nonce('tix_sponsor_login');
        $err_flag = isset($_GET['tix_sauth_err']) ? sanitize_key($_GET['tix_sauth_err']) : '';
        $err_msg = '';
        if ($err_flag === 'expired') $err_msg = 'Dieser Login-Link ist abgelaufen. Bitte fordere einen neuen an.';
        if ($err_flag === 'invalid') $err_msg = 'Dieser Login-Link ist ungültig.';

        wp_enqueue_script('jquery');

        ob_start();
        ?>
        <div class="tix-sd-login" style="max-width:440px;margin:40px auto;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 4px 12px rgba(15,23,42,0.06);">
            <div style="font-size:42px;text-align:center;margin-bottom:8px;">🎟️</div>
            <h2 style="text-align:center;margin:0 0 6px;font-size:22px;">Sponsor-Portal</h2>
            <p style="text-align:center;color:#64748b;margin:0 0 22px;font-size:14px;">Gib deine E-Mail-Adresse ein — wir schicken dir einen Login-Link.</p>

            <?php if ($err_msg): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;">
                <?php echo esc_html($err_msg); ?>
            </div>
            <?php endif; ?>

            <form id="tix-sd-magic-form" style="display:flex;flex-direction:column;gap:10px;">
                <label style="font-weight:600;font-size:13px;color:#0f172a;">E-Mail-Adresse</label>
                <input type="email" id="tix-sd-magic-email" required placeholder="kontakt@firma.de" autocomplete="email" style="padding:11px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                <button type="submit" style="background:#FF5500;color:#fff;border:none;border-radius:8px;padding:12px 18px;font-weight:700;cursor:pointer;font-size:14px;">Login-Link senden</button>
                <div id="tix-sd-magic-msg" style="font-size:13px;margin-top:6px;display:none;"></div>
            </form>
        </div>
        <script>
        (function($) {
            $('#tix-sd-magic-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $(this).find('button[type="submit"]');
                var $msg = $('#tix-sd-magic-msg');
                $btn.prop('disabled', true).text('Sende…');
                $msg.hide();
                $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    action: 'tix_sponsor_request_login',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    email: $('#tix-sd-magic-email').val().trim()
                }, function(r) {
                    $btn.prop('disabled', false).text('Login-Link senden');
                    if (r.success) {
                        $msg.css({background:'#ecfdf5',border:'1px solid #a7f3d0',color:'#065f46',padding:'10px 14px',borderRadius:'8px'}).text(r.data.message).show();
                    } else {
                        $msg.css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b',padding:'10px 14px',borderRadius:'8px'}).text((r.data && r.data.message) || 'Fehler.').show();
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }
}
