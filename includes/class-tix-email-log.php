<?php
/**
 * TIX_Email_Log – E-Mail-Protokollierung für alle wp_mail()-Aufrufe.
 *
 * Loggt alle E-Mails in eine eigene DB-Tabelle, zeigt sie
 * im Tixomat-Admin unter "E-Mail-Log" an.
 *
 * @since 1.34.252
 */
if (!defined('ABSPATH')) exit;

class TIX_Email_Log {

    const TABLE_SUFFIX = 'tix_email_log';

    public static function init() {
        // Schema-Migration sicherstellen
        add_action('init', [__CLASS__, 'ensure_tracking_columns'], 1);

        // Alle ausgehenden Mails loggen
        add_filter('wp_mail', [__CLASS__, 'log_outgoing'], 999999);

        // Fehler abfangen
        add_action('wp_mail_failed', [__CLASS__, 'log_failure']);

        // Admin-Menü
        add_action('admin_menu', [__CLASS__, 'register_menu'], 30);

        // AJAX: Resend
        add_action('wp_ajax_tix_email_resend', [__CLASS__, 'ajax_resend']);

        // AJAX: Delete
        add_action('wp_ajax_tix_email_log_delete', [__CLASS__, 'ajax_delete']);

        // Cleanup: alte Logs löschen (> 90 Tage)
        add_action('tix_email_log_cleanup', [__CLASS__, 'cleanup']);
        if (!wp_next_scheduled('tix_email_log_cleanup')) {
            wp_schedule_event(time() + 3600, 'daily', 'tix_email_log_cleanup');
        }
    }

    // ══════════════════════════════════════
    // DB-Tabelle
    // ══════════════════════════════════════

    /**
     * Holt die E-Mails für eine Email-Adresse.
     * Optional via $since auf Bestellungs-Zeitraum eingrenzen.
     */
    public static function get_for_email($email, $limit = 20, $since = null) {
        global $wpdb;
        $t = self::table_name();
        if (!$email) return [];

        $sql = "SELECT * FROM $t WHERE to_email = %s";
        $args = [$email];
        if ($since) {
            $sql .= " AND date_created >= %s";
            $args[] = $since;
        }
        $sql .= " ORDER BY date_created DESC LIMIT %d";
        $args[] = intval($limit);

        return $wpdb->get_results($wpdb->prepare($sql, ...$args));
    }

    /**
     * Inline-Render: Email-Liste für Order-Detail / Customer-Detail.
     * Nimmt Array von Rows aus get_for_email().
     */
    public static function render_inline($rows, $email_for_filter = '') {
        if (empty($rows)) {
            echo '<p style="margin:0;color:#9ca3af;font-size:12px;font-style:italic;">Noch keine E-Mails an diese Adresse versendet.</p>';
            return;
        }

        $status_colors = [
            'sent'   => '#10b981',
            'failed' => '#ef4444',
            'queued' => '#f59e0b',
        ];

        echo '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
        echo '<thead><tr style="background:#f9fafb;">';
        echo '<th style="text-align:left;padding:6px 8px;font-weight:600;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">Datum</th>';
        echo '<th style="text-align:left;padding:6px 8px;font-weight:600;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">Betreff</th>';
        echo '<th style="text-align:left;padding:6px 8px;font-weight:600;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">Status</th>';
        echo '<th style="text-align:left;padding:6px 8px;font-weight:600;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">Tracking</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $color = $status_colors[$row->status] ?? '#6b7280';
            $date  = $row->date_created ? date_i18n('d.m. H:i', strtotime($row->date_created)) : '';
            $log_url = admin_url('admin.php?page=tix-email-log&log_id=' . $row->id);

            echo '<tr style="border-bottom:1px solid #f3f4f6;">';
            echo '<td style="padding:8px;color:#6b7280;white-space:nowrap;">' . esc_html($date) . '</td>';
            echo '<td style="padding:8px;color:#0f172a;">' . esc_html($row->subject) . ($row->source ? ' <span style="font-size:10px;color:#9ca3af;">(' . esc_html($row->source) . ')</span>' : '') . '</td>';
            echo '<td style="padding:8px;"><span style="display:inline-block;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:600;background:' . $color . '15;color:' . $color . ';">' . esc_html(strtoupper($row->status)) . '</span></td>';
            echo '<td style="padding:8px;white-space:nowrap;">' . self::tracking_badge_html($row, true) . '</td>';
            echo '<td style="padding:8px;text-align:right;"><a href="' . esc_url($log_url) . '" style="color:#0284c7;text-decoration:none;font-size:11px;" title="Im Log öffnen">→</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($email_for_filter) {
            $list_url = admin_url('admin.php?page=tix-email-log&search=' . urlencode($email_for_filter));
            echo '<p style="margin:10px 0 0;font-size:12px;"><a href="' . esc_url($list_url) . '" style="color:#0284c7;text-decoration:none;">→ Alle E-Mails an ' . esc_html($email_for_filter) . ' im Log anzeigen</a></p>';
        }
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function create_table() {
        global $wpdb;
        $t = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            to_email VARCHAR(500) NOT NULL DEFAULT '',
            subject VARCHAR(500) NOT NULL DEFAULT '',
            body LONGTEXT,
            headers TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            error_message TEXT,
            source VARCHAR(100) NOT NULL DEFAULT '',
            tracking_token VARCHAR(64) NOT NULL DEFAULT '',
            opened_at DATETIME NULL DEFAULT NULL,
            open_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_opened_at DATETIME NULL DEFAULT NULL,
            feedback_value VARCHAR(20) NOT NULL DEFAULT '',
            feedback_at DATETIME NULL DEFAULT NULL,
            feedback_text TEXT,
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY to_email (to_email(191)),
            KEY status (status),
            KEY date_created (date_created),
            KEY source (source),
            KEY tracking_token (tracking_token)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Migration: Tracking-Spalten zu existierender Tabelle hinzufügen.
     * Wird beim Plugin-Init geprüft (idempotent).
     */
    public static function ensure_tracking_columns() {
        global $wpdb;
        $t = self::table_name();
        // Schnell-Check via Static-Flag (1× pro Request)
        static $checked = false;
        if ($checked) return;
        $checked = true;

        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return;

        $columns = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
        if (!is_array($columns)) return;

        $needed = [
            'tracking_token'  => "ALTER TABLE $t ADD COLUMN tracking_token VARCHAR(64) NOT NULL DEFAULT '' AFTER source",
            'opened_at'       => "ALTER TABLE $t ADD COLUMN opened_at DATETIME NULL DEFAULT NULL AFTER tracking_token",
            'open_count'      => "ALTER TABLE $t ADD COLUMN open_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER opened_at",
            'last_opened_at'  => "ALTER TABLE $t ADD COLUMN last_opened_at DATETIME NULL DEFAULT NULL AFTER open_count",
            'feedback_value'  => "ALTER TABLE $t ADD COLUMN feedback_value VARCHAR(20) NOT NULL DEFAULT '' AFTER last_opened_at",
            'feedback_at'     => "ALTER TABLE $t ADD COLUMN feedback_at DATETIME NULL DEFAULT NULL AFTER feedback_value",
            'feedback_text'   => "ALTER TABLE $t ADD COLUMN feedback_text TEXT AFTER feedback_at",
        ];
        foreach ($needed as $col => $sql) {
            if (!in_array($col, $columns, true)) {
                $wpdb->query($sql);
            }
        }
        // Index nachziehen
        $idx = $wpdb->get_col("SHOW INDEX FROM $t WHERE Key_name='tracking_token'");
        if (empty($idx)) {
            $wpdb->query("ALTER TABLE $t ADD KEY tracking_token (tracking_token)");
        }
    }

    // ══════════════════════════════════════
    // LOGGING
    // ══════════════════════════════════════

    /**
     * wp_mail Filter — loggt jede ausgehende Mail.
     * Gibt die $args unverändert zurück.
     */
    public static function log_outgoing($args) {
        global $wpdb;
        $t = self::table_name();

        // Tabelle prüfen (leichtgewichtig per Static-Flag)
        static $table_ok = null;
        if ($table_ok === null) {
            $table_ok = ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t);
            if (!$table_ok) return $args;
        }
        if (!$table_ok) return $args;

        $to = is_array($args['to']) ? implode(', ', $args['to']) : ($args['to'] ?? '');
        $subject = $args['subject'] ?? '';
        $body = $args['message'] ?? '';
        $headers = '';
        if (!empty($args['headers'])) {
            $headers = is_array($args['headers']) ? implode("\n", $args['headers']) : $args['headers'];
        }

        // Quelle ermitteln (Backtrace)
        $source = self::detect_source();

        // Sicherstellen, dass Tracking-Spalten existieren (legacy-Migration)
        self::ensure_tracking_columns();

        // ── Tracking-Token generieren + Pixel/Feedback in HTML-Mail injizieren ──
        $token = '';
        $has_tracking_cols = self::table_has_tracking_columns();
        if ($has_tracking_cols && self::is_html_email($body, $headers) && self::should_track($source)) {
            $token = self::generate_tracking_token();
            $body  = self::inject_tracking_pixel_and_feedback($body, $token, $source);
            $args['message'] = $body;
        }

        $insert_data = [
            'to_email'       => mb_substr($to, 0, 500),
            'subject'        => mb_substr($subject, 0, 500),
            'body'           => $body,
            'headers'        => $headers,
            'status'         => 'sent',
            'error_message'  => '',
            'source'         => $source,
            'date_created'   => current_time('mysql'),
        ];
        if ($has_tracking_cols) {
            $insert_data['tracking_token'] = $token;
        }
        $wpdb->insert($t, $insert_data);

        // Log-ID für Fehler-Zuordnung merken
        if ($wpdb->insert_id) {
            self::$last_log_id = $wpdb->insert_id;
        }

        return $args;
    }

    /**
     * Cached-Check, ob die Tracking-Spalten in der DB existieren.
     */
    private static function table_has_tracking_columns() {
        static $cached = null;
        if ($cached !== null) return $cached;
        global $wpdb;
        $t = self::table_name();
        $col = $wpdb->get_var("SHOW COLUMNS FROM $t LIKE 'tracking_token'");
        $cached = !empty($col);
        return $cached;
    }

    /**
     * Erkennt, ob die Mail HTML-Inhalt hat (Pixel/Feedback nur in HTML sinnvoll).
     */
    private static function is_html_email($body, $headers) {
        if (stripos($body, '</body>') !== false || stripos($body, '<html') !== false) return true;
        if (stripos($headers, 'text/html') !== false) return true;
        return false;
    }

    /**
     * Tracking nur für eigene Mails (keine WP-Core, keine fremden Plugins).
     * Heuristik: Source beginnt mit 'tix_' oder Body enthält 'tix-email-container'.
     */
    private static function should_track($source) {
        // Per Filter deaktivierbar
        if (!apply_filters('tix_email_tracking_enabled', true, $source)) return false;
        return true;
    }

    /**
     * Generiert einen 32-Zeichen URL-sicheren Token.
     */
    public static function generate_tracking_token() {
        return bin2hex(random_bytes(16));
    }

    /**
     * Fügt Tracking-Pixel + Feedback-Buttons vor </body> ein.
     * Wenn kein </body> vorhanden, wird's am Ende angehängt.
     */
    private static function inject_tracking_pixel_and_feedback($html, $token, $source) {
        $pixel_url = self::get_pixel_url($token);
        $pixel = '<img src="' . esc_url($pixel_url) . '" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0;outline:none;text-decoration:none;" />';

        // Feedback-Buttons nur, wenn der Body unser Mail-Template ist (ODER per Filter erzwungen)
        $show_feedback = apply_filters('tix_email_show_feedback', self::source_wants_feedback($source, $html), $source, $token);
        $feedback_html = $show_feedback ? self::build_feedback_buttons($token) : '';

        $injection = $feedback_html . $pixel;

        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $injection . '</body>', $html, 1);
        }
        return $html . $injection;
    }

    /**
     * Soll diese Mail Feedback-Buttons bekommen?
     * Robuste Erkennung via HTML-Marker (statt Backtrace, der bei WP-Hooks
     * unzuverlässig den Caller findet): jede Mail durch unser
     * build_generic_email_html() trägt die CSS-Klasse "tix-email-container".
     */
    private static function source_wants_feedback($source, $body = '') {
        // Filter-Override: Plugins können explizit Feedback aus/anschalten
        $forced = apply_filters('tix_email_feedback_force', null, $source, $body);
        if ($forced !== null) return (bool) $forced;

        // Heuristik 1: Body trägt unseren Container-Marker → ist unsere Mail
        if ($body && stripos($body, 'tix-email-container') !== false) return true;

        // Heuristik 2: Source enthält tix-prefix
        $list = ['tix_support', 'tix_crm', 'TIX_Support', 'TIX_CRM', 'TIX_Emails', 'TIX_My_Tickets'];
        foreach ($list as $needle) {
            if (stripos($source, $needle) !== false) return true;
        }
        return false;
    }

    /**
     * Baut den Feedback-Button-Block. Inline-Styles für maximale Mail-Client-Kompatibilität.
     */
    private static function build_feedback_buttons($token) {
        $base = self::get_feedback_base_url($token);
        $url_yes  = esc_url($base . '/helpful');
        $url_no   = esc_url($base . '/not_helpful');
        $url_more = esc_url($base . '/need_more');

        ob_start();
        ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 8px;">
    <tr><td align="center" style="padding:18px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;">
        <p style="margin:0 0 12px;font-size:13px;color:#475569;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">War diese E-Mail hilfreich?</p>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
            <tr>
                <td style="padding:0 6px;"><a href="<?php echo $url_yes; ?>" target="_blank" style="display:inline-block;padding:8px 16px;background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">👍 Ja, hilfreich</a></td>
                <td style="padding:0 6px;"><a href="<?php echo $url_no; ?>" target="_blank" style="display:inline-block;padding:8px 16px;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">👎 Nein</a></td>
                <td style="padding:0 6px;"><a href="<?php echo $url_more; ?>" target="_blank" style="display:inline-block;padding:8px 16px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">💬 Brauche mehr Hilfe</a></td>
            </tr>
        </table>
    </td></tr>
</table>
        <?php
        return ob_get_clean();
    }

    public static function get_pixel_url($token) {
        return rest_url('tix/v1/mail-pixel/' . $token . '.gif');
    }

    public static function get_feedback_base_url($token) {
        return rest_url('tix/v1/mail-feedback/' . $token);
    }

    /**
     * Open + Feedback Badge HTML — kompakt für Listen, Order-/Customer-Detail.
     * Erwartet Row mit: opened_at, open_count, feedback_value, feedback_at.
     */
    public static function tracking_badge_html($row, $compact = true) {
        // Wenn das Tracking-Schema noch nicht migriert ist → nichts anzeigen
        if (!isset($row->tracking_token) && !property_exists($row, 'opened_at')) return '';

        $opened_at = $row->opened_at ?? null;
        $opened    = !empty($opened_at) && $opened_at !== '0000-00-00 00:00:00';
        $count     = isset($row->open_count) ? intval($row->open_count) : 0;
        $fb_val    = $row->feedback_value ?? '';
        $fb_at     = $row->feedback_at ?? '';

        $out = '';
        if ($opened) {
            $title = 'Geöffnet ' . ($count > 1 ? "$count× — zuletzt: " : '') . date_i18n('d.m. H:i', strtotime($row->last_opened_at ?: $row->opened_at));
            $out .= '<span title="' . esc_attr($title) . '" style="display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;color:#15803d;background:#dcfce7;padding:1px 6px;border-radius:8px;">📨 Geöffnet' . ($count > 1 && !$compact ? ' ' . $count . '×' : '') . '</span>';
        } else {
            $out .= '<span title="Noch nicht geöffnet" style="display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;color:#6b7280;background:#f3f4f6;padding:1px 6px;border-radius:8px;">📭 Ungeöffnet</span>';
        }

        if ($fb_val) {
            $labels = [
                'helpful'     => ['👍', 'Hilfreich',     '#15803d', '#dcfce7'],
                'not_helpful' => ['👎', 'Nicht hilfreich','#b91c1c', '#fee2e2'],
                'need_more'   => ['💬', 'Brauche Hilfe',  '#92400e', '#fef3c7'],
            ];
            $l = $labels[$fb_val] ?? null;
            if ($l) {
                $title = 'Feedback ' . ($fb_at ? date_i18n('d.m. H:i', strtotime($fb_at)) : '');
                $out .= ' <span title="' . esc_attr($title) . '" style="display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;color:' . $l[2] . ';background:' . $l[3] . ';padding:1px 6px;border-radius:8px;">' . $l[0] . ' ' . esc_html($l[1]) . '</span>';
            }
        }
        return $out;
    }

    private static $last_log_id = 0;

    /**
     * wp_mail_failed Action — markiert den letzten Log-Eintrag als fehlerhaft.
     */
    public static function log_failure($error) {
        if (!self::$last_log_id) return;

        global $wpdb;
        $t = self::table_name();

        $msg = '';
        if (is_wp_error($error)) {
            $msg = $error->get_error_message();
        } elseif (is_string($error)) {
            $msg = $error;
        }

        $wpdb->update($t, [
            'status'        => 'failed',
            'error_message' => mb_substr($msg, 0, 65535),
        ], ['id' => self::$last_log_id]);

        self::$last_log_id = 0;
    }

    /**
     * Quelle der Mail erkennen (Plugin/Theme/Core).
     */
    private static function detect_source() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (!$file) continue;

            // Tixomat-spezifisch
            if (strpos($file, 'tixomat') !== false) {
                $class = $frame['class'] ?? '';
                $func  = $frame['function'] ?? '';
                if ($class && $func && $class !== __CLASS__) {
                    return $class . '::' . $func;
                }
            }

            // WooCommerce
            if (strpos($file, 'woocommerce') !== false) {
                return 'WooCommerce';
            }

            // WordPress Core
            if (strpos($file, 'wp-includes') !== false && strpos($file, 'pluggable.php') === false) {
                $func = $frame['function'] ?? '';
                if ($func && $func !== 'wp_mail') {
                    return 'WordPress: ' . $func;
                }
            }
        }
        return 'WordPress';
    }

    // ══════════════════════════════════════
    // CLEANUP
    // ══════════════════════════════════════

    public static function cleanup() {
        global $wpdb;
        $t = self::table_name();
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $days = max(7, intval($s['email_log_days'] ?? 90));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE date_created < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    // ══════════════════════════════════════
    // ADMIN
    // ══════════════════════════════════════

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'E-Mail-Log',
            'E-Mail-Log',
            'manage_options',
            'tix-email-log',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (isset($_GET['log_id'])) {
            self::render_detail(intval($_GET['log_id']));
            return;
        }
        self::render_list();
    }

    // ──────────────────────────────────────
    // Listenansicht
    // ──────────────────────────────────────
    private static function render_list() {
        global $wpdb;
        $t = self::table_name();

        $search = sanitize_text_field($_GET['s'] ?? '');
        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 30;
        $offset = ($paged - 1) * $per_page;

        $where = '1=1';
        $params = [];

        if ($status_filter) {
            $where .= ' AND status = %s';
            $params[] = $status_filter;
        }
        if ($search) {
            $where .= ' AND (to_email LIKE %s OR subject LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $total = $wpdb->get_var($params
            ? $wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $where", ...$params)
            : "SELECT COUNT(*) FROM $t WHERE $where"
        );
        $logs = $params
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY date_created DESC LIMIT %d OFFSET %d", ...array_merge($params, [$per_page, $offset])))
            : $wpdb->get_results("SELECT * FROM $t WHERE $where ORDER BY date_created DESC LIMIT $per_page OFFSET $offset");

        $total_pages = ceil($total / $per_page);

        // Zähler
        $count_all    = $wpdb->get_var("SELECT COUNT(*) FROM $t");
        $count_sent   = $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status = 'sent'");
        $count_failed = $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status = 'failed'");

        $nonce = wp_create_nonce('tix_email_log');

        ?>
        <div class="wrap" style="max-width:1200px;">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                <span class="dashicons dashicons-email-alt" style="font-size:28px;width:28px;height:28px;color:var(--tix-primary, #FF5500);"></span>
                E-Mail-Log
                <span style="font-size:14px;color:#6b7280;font-weight:400;"><?php echo intval($count_all); ?> gesamt</span>
            </h1>

            <?php // ── Filter ── ?>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                    <?php
                    $statuses = ['' => 'Alle (' . $count_all . ')', 'sent' => 'Gesendet (' . $count_sent . ')', 'failed' => 'Fehlgeschlagen (' . $count_failed . ')'];
                    foreach ($statuses as $val => $label):
                        $active = ($status_filter === $val) ? 'font-weight:700;color:var(--tix-primary, #FF5500);' : '';
                        $url = admin_url('admin.php?page=tix-email-log' . ($val ? '&status=' . $val : ''));
                    ?>
                        <a href="<?php echo esc_url($url); ?>" style="padding:4px 12px;font-size:13px;text-decoration:none;border-radius:6px;<?php echo $active; ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="get" style="display:flex;gap:6px;">
                    <input type="hidden" name="page" value="tix-email-log">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Suche nach E-Mail, Betreff..." style="padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:240px;">
                    <button type="submit" class="button">Suchen</button>
                </form>
            </div>

            <?php // ── Tabelle ── ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                <table class="widefat" style="border:none;margin:0;">
                    <thead>
                        <tr style="background:#fafafa;">
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;width:60px;">#</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Datum</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Empfänger</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Betreff</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Quelle</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;width:100px;">Status</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;width:200px;">Tracking</th>
                            <th style="padding:12px 8px;font-size:13px;font-weight:600;width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;">Keine E-Mails gefunden.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $log):
                            $is_failed = ($log->status === 'failed');
                            $color = $is_failed ? '#ef4444' : '#22c55e';
                            $status_label = $is_failed ? 'Fehlgeschlagen' : 'Gesendet';
                        ?>
                            <tr style="border-top:1px solid #f3f4f6;">
                                <td style="padding:12px 16px;font-size:12px;color:#9ca3af;"><?php echo $log->id; ?></td>
                                <td style="padding:12px 16px;font-size:13px;color:#6b7280;">
                                    <?php echo date_i18n('d.m.Y, H:i', strtotime($log->date_created)); ?>
                                </td>
                                <td style="padding:12px 16px;font-size:13px;">
                                    <?php echo esc_html(wp_trim_words($log->to_email, 5, '...')); ?>
                                </td>
                                <td style="padding:12px 16px;font-size:13px;">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tix-email-log&log_id=' . $log->id)); ?>" style="color:#1e293b;text-decoration:none;font-weight:500;">
                                        <?php echo esc_html(wp_trim_words($log->subject, 8, '...')); ?>
                                    </a>
                                </td>
                                <td style="padding:12px 16px;font-size:12px;color:#9ca3af;">
                                    <?php echo esc_html($log->source ?: '—'); ?>
                                </td>
                                <td style="padding:12px 16px;">
                                    <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:<?php echo $color; ?>;background:color-mix(in srgb, <?php echo $color; ?> 10%, #fff);padding:3px 10px;border-radius:20px;">
                                        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $color; ?>;"></span>
                                        <?php echo esc_html($status_label); ?>
                                    </span>
                                </td>
                                <td style="padding:12px 16px;white-space:nowrap;">
                                    <?php echo self::tracking_badge_html($log, false); ?>
                                </td>
                                <td style="padding:12px 8px;text-align:center;">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tix-email-log&log_id=' . $log->id)); ?>" title="Details" style="color:#6b7280;text-decoration:none;">
                                        <span class="dashicons dashicons-visibility" style="font-size:16px;width:16px;height:16px;"></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php // ── Pagination ── ?>
            <?php if ($total_pages > 1): ?>
                <div style="display:flex;justify-content:center;gap:4px;margin-top:16px;">
                    <?php
                    $range = 2;
                    $show_pages = [];
                    for ($p = 1; $p <= $total_pages; $p++) {
                        if ($p === 1 || $p === $total_pages || abs($p - $paged) <= $range) {
                            $show_pages[] = $p;
                        } elseif (end($show_pages) !== '...') {
                            $show_pages[] = '...';
                        }
                    }
                    foreach ($show_pages as $p):
                        if ($p === '...'):
                    ?>
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;font-size:13px;">...</span>
                    <?php else:
                        $url = admin_url('admin.php?page=tix-email-log&paged=' . $p . ($status_filter ? '&status=' . $status_filter : '') . ($search ? '&s=' . urlencode($search) : ''));
                        $active_pg = $p === $paged ? 'background:var(--tix-primary, #FF5500);color:#fff;' : 'background:#fff;border:1px solid #e5e7eb;';
                    ?>
                        <a href="<?php echo esc_url($url); ?>" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:13px;text-decoration:none;<?php echo $active_pg; ?>"><?php echo $p; ?></a>
                    <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ──────────────────────────────────────
    // Detailansicht
    // ──────────────────────────────────────
    private static function render_detail($log_id) {
        global $wpdb;
        $t = self::table_name();
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $log_id));

        if (!$log) {
            echo '<div class="wrap"><h1>E-Mail nicht gefunden</h1><a href="' . esc_url(admin_url('admin.php?page=tix-email-log')) . '">Zurück</a></div>';
            return;
        }

        $is_failed = ($log->status === 'failed');
        $color = $is_failed ? '#ef4444' : '#22c55e';
        $status_label = $is_failed ? 'Fehlgeschlagen' : 'Gesendet';
        $nonce = wp_create_nonce('tix_email_log');

        ?>
        <div class="wrap" style="max-width:900px;">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:24px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=tix-email-log')); ?>" style="color:#6b7280;text-decoration:none;" title="Zurück">
                    <span class="dashicons dashicons-arrow-left-alt" style="font-size:24px;width:24px;height:24px;"></span>
                </a>
                <span class="dashicons dashicons-email-alt" style="font-size:28px;width:28px;height:28px;color:var(--tix-primary, #FF5500);"></span>
                E-Mail #<?php echo $log_id; ?>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:<?php echo $color; ?>;background:color-mix(in srgb, <?php echo $color; ?> 10%, #fff);padding:4px 12px;border-radius:20px;">
                    <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $color; ?>;"></span>
                    <?php echo esc_html($status_label); ?>
                </span>
            </h1>

            <div style="display:grid;grid-template-columns:1fr 280px;gap:20px;">
                <?php // ── Mail-Body ── ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                    <div style="padding:16px 20px;border-bottom:1px solid #f3f4f6;">
                        <div style="font-size:15px;font-weight:600;margin-bottom:4px;"><?php echo esc_html($log->subject); ?></div>
                        <div style="font-size:13px;color:#6b7280;">An: <?php echo esc_html($log->to_email); ?></div>
                    </div>
                    <div style="padding:20px;">
                        <?php if (strpos($log->body, '<') !== false && strpos($log->body, '>') !== false): ?>
                            <iframe id="tix-email-preview" style="width:100%;border:none;min-height:500px;border-radius:8px;" srcdoc="<?php echo esc_attr($log->body); ?>"></iframe>
                            <script>
                            (function(){
                                var f = document.getElementById('tix-email-preview');
                                f.onload = function(){
                                    try { f.style.height = f.contentDocument.body.scrollHeight + 40 + 'px'; } catch(e){}
                                };
                            })();
                            </script>
                        <?php else: ?>
                            <pre style="white-space:pre-wrap;font-size:13px;line-height:1.6;color:#374151;"><?php echo esc_html($log->body); ?></pre>
                        <?php endif; ?>
                    </div>
                </div>

                <?php // ── Sidebar ── ?>
                <div>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;">
                        <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Details</h3>
                        <div style="font-size:13px;line-height:2;color:#374151;">
                            <div><strong>Datum:</strong> <?php echo date_i18n('d.m.Y, H:i:s', strtotime($log->date_created)); ?></div>
                            <div><strong>Empfänger:</strong> <?php echo esc_html($log->to_email); ?></div>
                            <div><strong>Quelle:</strong> <?php echo esc_html($log->source ?: '—'); ?></div>
                            <?php if ($log->headers): ?>
                                <div><strong>Headers:</strong></div>
                                <pre style="font-size:11px;background:#f9fafb;padding:8px;border-radius:6px;white-space:pre-wrap;color:#6b7280;margin:4px 0 0;"><?php echo esc_html($log->headers); ?></pre>
                            <?php endif; ?>
                            <?php if ($is_failed && $log->error_message): ?>
                                <div style="margin-top:8px;padding:8px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;">
                                    <strong style="color:#ef4444;">Fehler:</strong><br>
                                    <span style="font-size:12px;color:#991b1b;"><?php echo esc_html($log->error_message); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php // ── Tracking-Info ── ?>
                    <?php if (!empty($log->tracking_token)):
                        $opened = !empty($log->opened_at) && $log->opened_at !== '0000-00-00 00:00:00';
                    ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-top:12px;">
                        <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">📨 Tracking</h3>
                        <div style="font-size:13px;line-height:2;color:#374151;">
                            <div>
                                <strong>Geöffnet:</strong>
                                <?php if ($opened): ?>
                                    <span style="color:#15803d;font-weight:600;">Ja</span>
                                    <span style="color:#9ca3af;font-size:11px;">(<?php echo intval($log->open_count); ?>×)</span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">Noch nicht</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($opened): ?>
                                <div><strong>Erstmals:</strong> <span style="font-size:12px;"><?php echo date_i18n('d.m.Y, H:i', strtotime($log->opened_at)); ?></span></div>
                                <?php if ($log->last_opened_at && $log->last_opened_at !== $log->opened_at): ?>
                                    <div><strong>Zuletzt:</strong> <span style="font-size:12px;"><?php echo date_i18n('d.m.Y, H:i', strtotime($log->last_opened_at)); ?></span></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($log->feedback_value)):
                                $fb_labels = ['helpful' => '👍 Hilfreich', 'not_helpful' => '👎 Nicht hilfreich', 'need_more' => '💬 Brauche mehr Hilfe'];
                                $fb_label = $fb_labels[$log->feedback_value] ?? $log->feedback_value;
                            ?>
                                <div style="margin-top:8px;padding:8px;background:#f9fafb;border-radius:6px;">
                                    <strong>Feedback:</strong> <?php echo esc_html($fb_label); ?><br>
                                    <span style="font-size:11px;color:#9ca3af;"><?php echo date_i18n('d.m.Y, H:i', strtotime($log->feedback_at)); ?></span>
                                    <?php if (!empty($log->feedback_text)): ?>
                                        <div style="margin-top:6px;padding:6px 8px;background:#fff;border-left:3px solid #FF5500;font-size:12px;color:#475569;font-style:italic;">„<?php echo esc_html($log->feedback_text); ?>"</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php // ── Actions ── ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-top:12px;">
                        <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Aktionen</h3>
                        <button type="button" class="button" id="tix-email-resend" style="width:100%;margin-bottom:8px;" data-id="<?php echo $log_id; ?>">
                            <span class="dashicons dashicons-controls-repeat" style="vertical-align:middle;margin-right:4px;font-size:16px;width:16px;height:16px;"></span>
                            Erneut senden
                        </button>
                        <button type="button" class="button" id="tix-email-delete" style="width:100%;color:#ef4444;" data-id="<?php echo $log_id; ?>">
                            <span class="dashicons dashicons-trash" style="vertical-align:middle;margin-right:4px;font-size:16px;width:16px;height:16px;"></span>
                            Löschen
                        </button>
                        <div id="tix-email-action-msg" style="font-size:13px;margin-top:8px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function($){
            var nonce = <?php echo json_encode($nonce); ?>;

            $('#tix-email-resend').on('click', function(){
                var btn = $(this);
                var msg = $('#tix-email-action-msg');
                btn.prop('disabled', true).text('Sende...');
                $.post(ajaxurl, {
                    action: 'tix_email_resend',
                    nonce: nonce,
                    log_id: btn.data('id')
                }, function(r){
                    msg.text(r.success ? 'E-Mail erneut gesendet!' : (r.data.message || 'Fehler')).css('color', r.success ? '#22c55e' : '#ef4444');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-repeat" style="vertical-align:middle;margin-right:4px;font-size:16px;width:16px;height:16px;"></span> Erneut senden');
                }).fail(function(){
                    msg.text('Netzwerkfehler').css('color', '#ef4444');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-repeat" style="vertical-align:middle;margin-right:4px;font-size:16px;width:16px;height:16px;"></span> Erneut senden');
                });
            });

            $('#tix-email-delete').on('click', function(){
                if (!confirm('E-Mail-Log wirklich löschen?')) return;
                var btn = $(this);
                btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'tix_email_log_delete',
                    nonce: nonce,
                    log_id: btn.data('id')
                }, function(r){
                    if (r.success) {
                        window.location = <?php echo json_encode(admin_url('admin.php?page=tix-email-log')); ?>;
                    } else {
                        $('#tix-email-action-msg').text(r.data.message || 'Fehler').css('color', '#ef4444');
                        btn.prop('disabled', false);
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ══════════════════════════════════════
    // AJAX
    // ══════════════════════════════════════

    public static function ajax_resend() {
        check_ajax_referer('tix_email_log', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        global $wpdb;
        $t = self::table_name();
        $log_id = intval($_POST['log_id'] ?? 0);

        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $log_id));
        if (!$log) {
            wp_send_json_error(['message' => 'Log-Eintrag nicht gefunden.']);
        }

        $headers = [];
        if ($log->headers) {
            $headers = array_filter(explode("\n", $log->headers));
        }
        if (empty($headers)) {
            $headers = ['Content-Type: text/html; charset=UTF-8'];
        }

        $result = wp_mail($log->to_email, $log->subject, $log->body, $headers);

        wp_send_json_success(['sent' => $result]);
    }

    public static function ajax_delete() {
        check_ajax_referer('tix_email_log', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        global $wpdb;
        $t = self::table_name();
        $log_id = intval($_POST['log_id'] ?? 0);

        $wpdb->delete($t, ['id' => $log_id]);
        wp_send_json_success();
    }
}
