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
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY to_email (to_email(191)),
            KEY status (status),
            KEY date_created (date_created),
            KEY source (source)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
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

        $wpdb->insert($t, [
            'to_email'     => mb_substr($to, 0, 500),
            'subject'      => mb_substr($subject, 0, 500),
            'body'         => $body,
            'headers'      => $headers,
            'status'       => 'sent',
            'error_message' => '',
            'source'       => $source,
            'date_created' => current_time('mysql'),
        ]);

        // Log-ID für Fehler-Zuordnung merken
        if ($wpdb->insert_id) {
            self::$last_log_id = $wpdb->insert_id;
        }

        return $args;
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
                            <th style="padding:12px 8px;font-size:13px;font-weight:600;width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="7" style="padding:40px;text-align:center;color:#9ca3af;">Keine E-Mails gefunden.</td></tr>
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
