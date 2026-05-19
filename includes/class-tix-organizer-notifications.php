<?php
/**
 * TIX Organizer Notifications
 *
 * Sendet Event-bezogene Benachrichtigungen (Bestellungen, Support-Anfragen, etc.)
 * an die vom Veranstalter konfigurierten Empfänger:
 *   - Mehrere E-Mail-Adressen
 *   - Mehrere Pushover-Endpunkte (User-Key + App-Token)
 *
 * Storage: Post-Meta auf tix_organizer CPT
 *   _tix_org_notify_emails   array of {email, label, enabled}
 *   _tix_org_notify_pushover array of {user_key, app_token, label, enabled}
 *   _tix_org_notify_types    array of type-toggle ['orders'=>1, 'support'=>1, ...]
 *
 * Hooks:
 *   tix_order_status_changed  → Verkaufs-Benachrichtigung (bei Bezahlung/Bank-Eingang/etc.)
 *   save_post_tix_support_ticket → Support-Anfrage-Benachrichtigung (einmalig)
 *   add_meta_boxes            → Metabox auf Organizer-Edit-Screen
 *   save_post_tix_organizer   → Meta speichern
 */
if (!defined('ABSPATH')) exit;

class TIX_Organizer_Notifications {

    const PUSHOVER_API = 'https://api.pushover.net/1/messages.json';

    const PAUSE_META         = '_tix_org_notify_paused_until';
    const PAUSE_INDEFINITE   = 4102444800; // 2100-01-01, in Praxis "unbegrenzt"

    public static function init() {
        // Organizer-Edit-Screen
        add_action('add_meta_boxes',         [__CLASS__, 'register_metabox']);
        add_action('save_post_tix_organizer',[__CLASS__, 'save_metabox'], 20, 2);

        // Pause-Toggle (AJAX, ohne Post-Save)
        add_action('wp_ajax_tix_org_notify_pause', [__CLASS__, 'ajax_pause_toggle']);

        // Test-Push auf Edit-Screen (feuert nur bei GET mit Nonce, nicht beim Save)
        add_action('admin_init', [__CLASS__, 'maybe_fire_test_push']);

        // Trigger: Verkauf (completed/processing/on-hold), Stornierung, Erstattung, Zahlungsfehler
        add_action('tix_order_status_changed', [__CLASS__, 'handle_order_status_changed'], 20, 4);

        // Trigger: Support-Ticket (einmalig pro Ticket)
        add_action('save_post_tix_support_ticket', [__CLASS__, 'handle_support_ticket'], 20, 3);

        // Trigger: Check-in
        add_action('tix_ticket_checked_in', [__CLASS__, 'handle_ticket_checkin'], 20, 1);

        // Trigger: Ticket-Transfer (via Meta-Update auf _tix_ticket_transfer_to)
        add_action('added_post_meta',   [__CLASS__, 'handle_ticket_meta_change'], 10, 4);
        add_action('updated_postmeta',  [__CLASS__, 'handle_ticket_meta_change'], 10, 4);
    }

    // ═══════════════════════════════════════════════════════════════
    // METABOX
    // ═══════════════════════════════════════════════════════════════

    public static function register_metabox() {
        add_meta_box(
            'tix_org_notifications',
            'Benachrichtigungen',
            [__CLASS__, 'render_metabox'],
            'tix_organizer',
            'normal',
            'default'
        );
    }

    public static function render_metabox($post) {
        wp_nonce_field('tix_org_notify_save', '_tix_org_notify_nonce');

        $emails  = self::get_emails($post->ID);
        $pushes  = self::get_pushovers($post->ID);
        $types   = self::get_types($post->ID);

        // Min 1 leere Zeile für UX
        if (empty($emails)) $emails = [['email' => '', 'label' => '', 'enabled' => 1]];
        if (empty($pushes)) $pushes = [['user_key' => '', 'app_token' => '', 'label' => '', 'enabled' => 1]];

        ?>
        <style>
            .tix-notify-tabs { display:flex; gap:0; border-bottom:1px solid #e5e7eb; margin-bottom:16px; }
            .tix-notify-tab { padding:10px 18px; cursor:pointer; font-weight:600; color:#64748b; border-bottom:2px solid transparent; margin-bottom:-1px; }
            .tix-notify-tab.is-active { color:#0f172a; border-bottom-color:#2563eb; }
            .tix-notify-pane { display:none; }
            .tix-notify-pane.is-active { display:block; }

            .tix-notify-row {
                display:grid; gap:8px; align-items:center;
                padding:10px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px;
                margin-bottom:8px;
            }
            .tix-notify-row-email    { grid-template-columns: 24px 1fr 160px 32px; }
            .tix-notify-row-pushover { grid-template-columns: 24px 1fr 1fr 160px 32px; }
            .tix-notify-row input[type="text"],
            .tix-notify-row input[type="email"] {
                width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;
            }
            .tix-notify-row input[type="checkbox"] { width:16px; height:16px; }
            .tix-notify-remove {
                background:transparent; border:none; cursor:pointer; color:#94a3b8; font-size:18px;
                width:28px; height:28px; padding:0; border-radius:6px;
            }
            .tix-notify-remove:hover { background:#fee2e2; color:#dc2626; }
            .tix-notify-add {
                background:#eff6ff; color:#1e40af; border:1px dashed #bfdbfe; border-radius:6px;
                padding:8px 14px; cursor:pointer; font-weight:600; font-size:13px; margin-top:6px;
            }
            .tix-notify-add:hover { background:#dbeafe; }
            .tix-notify-types label { display:inline-flex; align-items:center; gap:6px; margin-right:18px; padding:8px 0; font-size:13px; cursor:pointer; }
            .tix-notify-types { background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:16px; }
            .tix-notify-intro { color:#64748b; font-size:13px; line-height:1.5; margin-bottom:12px; }
            .tix-notify-intro code { background:#fff; padding:1px 5px; border-radius:3px; font-size:12px; }
        </style>

        <p class="tix-notify-intro">
            Benachrichtigungen für diesen Veranstalter: E-Mail und/oder Pushover-Push. Es gehen jeweils <strong>alle aktivierten</strong> Empfänger die Nachricht raus — Events dieses Veranstalters lösen den Versand aus.
        </p>

        <?php
        $is_paused    = self::is_paused($post->ID);
        $pause_remain = self::paused_remaining_text($post->ID);
        $pause_nonce  = wp_create_nonce('tix_org_notify_pause');
        ?>
        <div id="tix-org-pause-panel"
             data-org-id="<?php echo intval($post->ID); ?>"
             data-nonce="<?php echo esc_attr($pause_nonce); ?>"
             style="border-radius:10px;padding:14px 16px;margin-bottom:16px;<?php echo $is_paused
                ? 'background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d;'
                : 'background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d;'; ?>">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:18px;"><?php echo $is_paused ? '⏸' : '🔔'; ?></span>
                    <div>
                        <strong style="font-size:14px;display:block;">
                            <?php echo $is_paused
                                ? 'Benachrichtigungen sind aktuell PAUSIERT'
                                : 'Benachrichtigungen sind aktiv'; ?>
                        </strong>
                        <span style="font-size:12px;opacity:0.85;" class="tix-org-pause-detail">
                            <?php if ($is_paused): ?>
                                Bestellungen, Check-ins etc. lösen aktuell keine Mail/Push aus. <?php echo esc_html($pause_remain); ?>
                            <?php else: ?>
                                Du kannst sie temporär ausschalten — z.B. für einen Bulk-Import oder Wartung.
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="tix-org-pause-actions" style="display:flex;gap:5px;flex-wrap:wrap;">
                    <?php if ($is_paused): ?>
                        <button type="button" class="button button-primary tix-org-pause-stop">▶ Wieder aktivieren</button>
                    <?php else: ?>
                        <button type="button" class="button tix-org-pause-start" data-duration="3600">1 Std. pausieren</button>
                        <button type="button" class="button tix-org-pause-start" data-duration="14400">4 Std.</button>
                        <button type="button" class="button tix-org-pause-start" data-duration="86400">24 Std.</button>
                        <button type="button" class="button tix-org-pause-start" data-duration="indefinite" title="Bis du es manuell wieder aktivierst">Unbegrenzt</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function($) {
            var $panel = $('#tix-org-pause-panel');
            if (!$panel.length) return;
            var orgId  = $panel.data('org-id');
            var nonce  = $panel.data('nonce');
            var ajaxUrl = ajaxurl;

            function reload() { window.location.reload(); }

            $panel.on('click', '.tix-org-pause-start', function() {
                var $btn = $(this);
                var duration = $btn.data('duration');
                if (duration === 'indefinite') {
                    if (!confirm('Benachrichtigungen unbegrenzt pausieren? Du musst sie hier manuell wieder aktivieren.')) return;
                }
                $btn.prop('disabled', true).text('…');
                $.post(ajaxUrl, {
                    action: 'tix_org_notify_pause', nonce: nonce,
                    org_id: orgId, pause_action: 'start', duration: duration
                }, function(r) {
                    if (r.success) reload();
                    else { alert((r.data && r.data.message) || 'Fehler.'); $btn.prop('disabled', false); }
                });
            });
            $panel.on('click', '.tix-org-pause-stop', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('…');
                $.post(ajaxUrl, {
                    action: 'tix_org_notify_pause', nonce: nonce,
                    org_id: orgId, pause_action: 'stop'
                }, function(r) {
                    if (r.success) reload();
                    else { alert((r.data && r.data.message) || 'Fehler.'); $btn.prop('disabled', false); }
                });
            });
        })(jQuery);
        </script>

        <div class="tix-notify-types">
            <strong style="display:block;margin-bottom:6px;">Welche Ereignisse sollen benachrichtigen?</strong>
            <label><input type="checkbox" name="tix_org_notify_types[orders]"       value="1" <?php checked(!empty($types['orders']));       ?>> 💰 Neue Bestellungen</label>
            <label><input type="checkbox" name="tix_org_notify_types[cancelled]"    value="1" <?php checked(!empty($types['cancelled']));    ?>> ❌ Stornierungen</label>
            <label><input type="checkbox" name="tix_org_notify_types[refunded]"     value="1" <?php checked(!empty($types['refunded']));     ?>> 💸 Erstattungen</label>
            <label><input type="checkbox" name="tix_org_notify_types[failed]"       value="1" <?php checked(!empty($types['failed']));       ?>> ⚠ Zahlungsprobleme</label>
            <label><input type="checkbox" name="tix_org_notify_types[support]"      value="1" <?php checked(!empty($types['support']));      ?>> 🆘 Support-Anfragen</label>
            <label><input type="checkbox" name="tix_org_notify_types[checkin]"      value="1" <?php checked(!empty($types['checkin']));      ?>> ✅ Check-ins</label>
            <label><input type="checkbox" name="tix_org_notify_types[transfer]"     value="1" <?php checked(!empty($types['transfer']));     ?>> 🔄 Ticket-Transfers</label>
        </div>

        <div class="tix-notify-tabs">
            <div class="tix-notify-tab is-active" data-target="email">📧 E-Mails (<?php echo count(array_filter($emails, fn($e) => !empty($e['email']))); ?>)</div>
            <div class="tix-notify-tab" data-target="pushover">🔔 Pushover (<?php echo count(array_filter($pushes, fn($p) => !empty($p['user_key']))); ?>)</div>
        </div>

        <?php // ── E-Mail-Tab ── ?>
        <div class="tix-notify-pane is-active" data-pane="email">
            <div id="tix-notify-email-list">
                <?php foreach ($emails as $i => $row): ?>
                <div class="tix-notify-row tix-notify-row-email">
                    <input type="checkbox" name="tix_org_notify_emails[<?php echo $i; ?>][enabled]" value="1" <?php checked(!empty($row['enabled'])); ?> title="Aktiv">
                    <input type="email" name="tix_org_notify_emails[<?php echo $i; ?>][email]" value="<?php echo esc_attr($row['email']); ?>" placeholder="name@beispiel.de">
                    <input type="text"  name="tix_org_notify_emails[<?php echo $i; ?>][label]" value="<?php echo esc_attr($row['label'] ?? ''); ?>" placeholder="Bezeichnung (optional)">
                    <button type="button" class="tix-notify-remove" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="tix-notify-add" id="tix-notify-add-email">+ E-Mail hinzufügen</button>
        </div>

        <?php // ── Pushover-Tab ── ?>
        <div class="tix-notify-pane" data-pane="pushover">
            <div id="tix-notify-pushover-list">
                <?php foreach ($pushes as $i => $row): ?>
                <div class="tix-notify-row tix-notify-row-pushover">
                    <input type="checkbox" name="tix_org_notify_pushover[<?php echo $i; ?>][enabled]" value="1" <?php checked(!empty($row['enabled'])); ?> title="Aktiv">
                    <input type="text" name="tix_org_notify_pushover[<?php echo $i; ?>][user_key]"  value="<?php echo esc_attr($row['user_key']);  ?>" placeholder="User-Key (u...)">
                    <input type="text" name="tix_org_notify_pushover[<?php echo $i; ?>][app_token]" value="<?php echo esc_attr($row['app_token']); ?>" placeholder="App-Token (a...)">
                    <input type="text" name="tix_org_notify_pushover[<?php echo $i; ?>][label]"     value="<?php echo esc_attr($row['label'] ?? ''); ?>" placeholder="Bezeichnung (z.B. iPhone Max)">
                    <button type="button" class="tix-notify-remove" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="tix-notify-add" id="tix-notify-add-pushover">+ Pushover-Endpunkt hinzufügen</button>
            <p style="font-size:12px;color:#64748b;margin-top:12px;line-height:1.5;">
                Du brauchst für jede Konfiguration einen <strong>User-Key</strong> (pro Gerät/Account) und einen <strong>App-Token</strong> (pro App). Beides bekommst du auf <a href="https://pushover.net" target="_blank" rel="noopener">pushover.net</a>. Lege eine eigene Tixomat-App an — das Icon erscheint dann auf den Push-Nachrichten.
            </p>

            <details style="margin-top:10px;font-size:12px;">
                <summary style="cursor:pointer;color:#2563eb;">🧪 Test-Push senden</summary>
                <p style="margin:10px 0 6px;">Speichere erst die Seite, dann klick hier um einen Test-Push an alle aktiven Pushover-Empfänger zu schicken:</p>
                <a href="<?php echo esc_url(add_query_arg(['tix_test_push' => '1', '_wpnonce' => wp_create_nonce('tix_test_push_' . $post->ID)])); ?>" class="button">Test-Push senden</a>
            </details>
        </div>

        <script>
        (function(){
            // Tab-Switching
            document.querySelectorAll('.tix-notify-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.tix-notify-tab').forEach(t => t.classList.remove('is-active'));
                    document.querySelectorAll('.tix-notify-pane').forEach(p => p.classList.remove('is-active'));
                    tab.classList.add('is-active');
                    document.querySelector('.tix-notify-pane[data-pane="' + tab.dataset.target + '"]').classList.add('is-active');
                });
            });

            // Add Email Row
            document.getElementById('tix-notify-add-email')?.addEventListener('click', () => {
                const list = document.getElementById('tix-notify-email-list');
                const i = list.children.length;
                const row = document.createElement('div');
                row.className = 'tix-notify-row tix-notify-row-email';
                row.innerHTML = `
                    <input type="checkbox" name="tix_org_notify_emails[${i}][enabled]" value="1" checked title="Aktiv">
                    <input type="email" name="tix_org_notify_emails[${i}][email]" placeholder="name@beispiel.de">
                    <input type="text" name="tix_org_notify_emails[${i}][label]" placeholder="Bezeichnung (optional)">
                    <button type="button" class="tix-notify-remove" onclick="this.parentElement.remove()">✕</button>
                `;
                list.appendChild(row);
            });

            // Add Pushover Row
            document.getElementById('tix-notify-add-pushover')?.addEventListener('click', () => {
                const list = document.getElementById('tix-notify-pushover-list');
                const i = list.children.length;
                const row = document.createElement('div');
                row.className = 'tix-notify-row tix-notify-row-pushover';
                row.innerHTML = `
                    <input type="checkbox" name="tix_org_notify_pushover[${i}][enabled]" value="1" checked title="Aktiv">
                    <input type="text" name="tix_org_notify_pushover[${i}][user_key]" placeholder="User-Key (u...)">
                    <input type="text" name="tix_org_notify_pushover[${i}][app_token]" placeholder="App-Token (a...)">
                    <input type="text" name="tix_org_notify_pushover[${i}][label]" placeholder="Bezeichnung">
                    <button type="button" class="tix-notify-remove" onclick="this.parentElement.remove()">✕</button>
                `;
                list.appendChild(row);
            });
        })();
        </script>
        <?php
    }

    public static function save_metabox($post_id, $post) {
        if (!isset($_POST['_tix_org_notify_nonce']) || !wp_verify_nonce($_POST['_tix_org_notify_nonce'], 'tix_org_notify_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // E-Mails
        $emails_in = $_POST['tix_org_notify_emails'] ?? [];
        $emails_clean = [];
        if (is_array($emails_in)) {
            foreach ($emails_in as $row) {
                if (!is_array($row)) continue;
                $email = sanitize_email($row['email'] ?? '');
                if (!$email) continue;
                $emails_clean[] = [
                    'email'   => $email,
                    'label'   => sanitize_text_field($row['label'] ?? ''),
                    'enabled' => !empty($row['enabled']) ? 1 : 0,
                ];
            }
        }
        update_post_meta($post_id, '_tix_org_notify_emails', $emails_clean);

        // Pushover
        $pushes_in = $_POST['tix_org_notify_pushover'] ?? [];
        $pushes_clean = [];
        if (is_array($pushes_in)) {
            foreach ($pushes_in as $row) {
                if (!is_array($row)) continue;
                $user_key  = sanitize_text_field($row['user_key']  ?? '');
                $app_token = sanitize_text_field($row['app_token'] ?? '');
                if (!$user_key || !$app_token) continue;
                $pushes_clean[] = [
                    'user_key'  => $user_key,
                    'app_token' => $app_token,
                    'label'     => sanitize_text_field($row['label'] ?? ''),
                    'enabled'   => !empty($row['enabled']) ? 1 : 0,
                ];
            }
        }
        update_post_meta($post_id, '_tix_org_notify_pushover', $pushes_clean);

        // Typen
        $types_in = $_POST['tix_org_notify_types'] ?? [];
        $type_keys = ['orders', 'cancelled', 'refunded', 'failed', 'support', 'checkin', 'transfer'];
        $types_clean = [];
        foreach ($type_keys as $k) {
            $types_clean[$k] = !empty($types_in[$k]) ? 1 : 0;
        }
        update_post_meta($post_id, '_tix_org_notify_types', $types_clean);

    }

    /**
     * Feuert den Test-Push bei GET-Aufruf von ?tix_test_push=1 auf der Edit-Seite.
     * Läuft auf admin_init, BEVOR die Seite rendert, damit admin_notices sichtbar sind.
     */
    public static function maybe_fire_test_push() {
        if (empty($_GET['tix_test_push'])) return;
        $post_id = intval($_GET['post'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== 'tix_organizer') return;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tix_test_push_' . $post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        self::send_test_push($post_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // DATA ACCESS
    // ═══════════════════════════════════════════════════════════════

    public static function get_emails($org_id) {
        $v = get_post_meta($org_id, '_tix_org_notify_emails', true);
        return is_array($v) ? $v : [];
    }

    public static function get_pushovers($org_id) {
        $v = get_post_meta($org_id, '_tix_org_notify_pushover', true);
        return is_array($v) ? $v : [];
    }

    public static function get_types($org_id) {
        $v = get_post_meta($org_id, '_tix_org_notify_types', true);
        if (!is_array($v)) $v = [];
        // Defaults: die wichtigsten Events aktiv (Standard-Setup ohne manuelle Konfig)
        return array_merge([
            'orders'    => 1,
            'cancelled' => 1,
            'refunded'  => 1,
            'failed'    => 1,
            'support'   => 1,
            'checkin'   => 0,   // Default aus (zu viele Pings bei großen Events)
            'transfer'  => 1,
        ], $v);
    }

    /** Aktive E-Mail-Empfänger */
    public static function active_emails($org_id) {
        return array_values(array_filter(array_map(
            fn($r) => !empty($r['enabled']) ? $r['email'] : null,
            self::get_emails($org_id)
        )));
    }

    /** Aktive Pushover-Configs */
    public static function active_pushovers($org_id) {
        return array_values(array_filter(self::get_pushovers($org_id), fn($r) => !empty($r['enabled'])));
    }

    // ═══════════════════════════════════════════════════════════════
    // CORE: Notification Dispatcher
    // ═══════════════════════════════════════════════════════════════

    /**
     * Zentrale Versand-Methode für Veranstalter-Benachrichtigungen.
     *
     * @param int    $org_id      tix_organizer post ID
     * @param string $type        'orders' | 'support'
     * @param array  $payload     [title, message, email_subject, email_body, url, url_title, sound]
     */
    /* ──────────────── PAUSE ──────────────── */

    public static function paused_until(int $org_id): int {
        return (int) get_post_meta($org_id, self::PAUSE_META, true);
    }

    public static function is_paused(int $org_id): bool {
        return self::paused_until($org_id) > time();
    }

    public static function paused_remaining_text(int $org_id): string {
        $until = self::paused_until($org_id);
        if ($until <= time()) return '';
        if ($until >= self::PAUSE_INDEFINITE) return 'unbegrenzt — bis du es manuell aufhebst';
        $diff = $until - time();
        if ($diff < 60)          return 'noch < 1 Min.';
        if ($diff < 3600)        return 'noch ' . intval($diff / 60) . ' Min.';
        if ($diff < 86400)       return 'noch ' . round($diff / 3600, 1) . ' Std.';
        return 'bis ' . wp_date('d.m.Y, H:i', $until);
    }

    public static function ajax_pause_toggle() {
        check_ajax_referer('tix_org_notify_pause', 'nonce');
        $org_id = intval($_POST['org_id'] ?? 0);
        if (!$org_id || !current_user_can('edit_post', $org_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }
        $action = sanitize_key($_POST['pause_action'] ?? '');

        if ($action === 'stop') {
            delete_post_meta($org_id, self::PAUSE_META);
            wp_send_json_success(['paused' => false, 'message' => 'Benachrichtigungen sind wieder aktiv.']);
        }

        if ($action === 'start') {
            $duration = sanitize_text_field($_POST['duration'] ?? '');
            $until = 0;
            if ($duration === 'indefinite') {
                $until = self::PAUSE_INDEFINITE;
            } else {
                $secs = intval($duration);
                if ($secs <= 0 || $secs > 30 * DAY_IN_SECONDS) {
                    wp_send_json_error(['message' => 'Ungültige Dauer.']);
                }
                $until = time() + $secs;
            }
            update_post_meta($org_id, self::PAUSE_META, $until);
            wp_send_json_success([
                'paused'    => true,
                'until'     => $until,
                'remaining' => self::paused_remaining_text($org_id),
                'message'   => 'Benachrichtigungen pausiert.',
            ]);
        }

        wp_send_json_error(['message' => 'Ungültige Aktion.']);
    }

    public static function notify_organizer($org_id, $type, array $payload) {
        $types = self::get_types($org_id);
        if (empty($types[$type])) return; // dieser Typ deaktiviert
        if (self::is_paused(intval($org_id))) return; // alle Benachrichtigungen temporär aus

        // E-Mails
        $emails = self::active_emails($org_id);
        if (!empty($emails)) {
            $subject = $payload['email_subject'] ?? $payload['title'] ?? 'Tixomat-Benachrichtigung';
            $body    = $payload['email_body']    ?? $payload['message'] ?? '';
            $html = class_exists('TIX_Emails')
                ? TIX_Emails::build_generic_email_html($payload['title'] ?? $subject, $body, '', '')
                : '<html><body>' . $body . '</body></html>';

            $headers = ['Content-Type: text/html; charset=UTF-8'];
            foreach ($emails as $to) {
                wp_mail($to, $subject, $html, $headers);
            }
        }

        // Pushover
        $pushes = self::active_pushovers($org_id);
        foreach ($pushes as $cfg) {
            self::send_pushover($cfg['user_key'], $cfg['app_token'], $payload);
        }
    }

    /** Pushover API Call */
    public static function send_pushover($user_key, $app_token, array $payload) {
        $body = [
            'token'   => $app_token,
            'user'    => $user_key,
            'title'   => mb_substr($payload['title']   ?? 'Tixomat', 0, 250),
            'message' => mb_substr($payload['message'] ?? '',       0, 1024),
        ];
        if (!empty($payload['url']))       $body['url']       = $payload['url'];
        if (!empty($payload['url_title'])) $body['url_title'] = mb_substr($payload['url_title'], 0, 100);
        if (!empty($payload['sound']))     $body['sound']     = $payload['sound'];
        if (!empty($payload['priority'])) {
            $body['priority'] = intval($payload['priority']);
        }
        // HTML-Formatierung (für kursiv/bold/links im message-body)
        $body['html'] = 1;

        $res = wp_remote_post(self::PUSHOVER_API, [
            'timeout' => 8,
            'body'    => $body,
        ]);
        if (is_wp_error($res)) return false;
        return wp_remote_retrieve_response_code($res) === 200;
    }

    // ═══════════════════════════════════════════════════════════════
    // TRIGGERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Order-Status-Änderung: verzweigt auf den richtigen Benachrichtigungs-Typ.
     * Neu-Bestellungen, Stornierungen, Erstattungen, Zahlungsprobleme.
     * Dedupe PRO (order_id, type) damit nicht bei jedem Status-Flip doppelt feuert.
     */
    public static function handle_order_status_changed($order_id, $new_status, $old_status, $gateway) {
        // Status → Notification-Typ-Mapping
        $type_map = [
            'completed'  => 'orders',
            'processing' => 'orders',
            'on-hold'    => 'orders',
            'pending'    => null,        // noch kein Verkaufs-Ping (warten auf Zahlungseingang)
            'cancelled'  => 'cancelled',
            'refunded'   => 'refunded',
            'failed'     => 'failed',
        ];
        $type = $type_map[$new_status] ?? null;
        if (!$type) return;

        // Dedupe pro (order, type)
        $notify_key = 'tix_notify_' . $order_id . '_' . $type;
        if (get_transient($notify_key)) return;
        set_transient($notify_key, 1, DAY_IN_SECONDS);

        // Order-Daten + Events ermitteln
        $data = self::collect_order_data($order_id);
        if (empty($data['event_ids'])) return;

        $org_ids = self::collect_organizer_ids($data['event_ids']);
        if (empty($org_ids)) return;

        $event_titles = array_map(fn($eid) => get_the_title($eid) ?: 'Event #' . $eid, $data['event_ids']);
        $event_title  = implode(', ', $event_titles);

        // Typ-spezifische Payloads
        $price_de  = number_format($data['total'], 2, ',', '.');
        $order_url = admin_url('admin.php?page=tix-orders&order_id=' . $order_id);
        $customer_line = '<b>' . esc_html($data['customer'] ?: '(ohne Namen)') . '</b>';
        if ($data['email']) $customer_line .= ' <small>(' . esc_html($data['email']) . ')</small>';

        switch ($type) {
            case 'orders':
                $status_map = [
                    'completed'  => ['💰', 'abgeschlossen',           'cashregister'],
                    'processing' => ['⏳', 'in Bearbeitung',          'pushover'],
                    'on-hold'    => ['🏦', 'Überweisung ausstehend',  'pushover'],
                ];
                [$emoji, $status_label, $sound] = $status_map[$new_status] ?? ['📦', $new_status, 'pushover'];
                $title   = "$emoji Neue Bestellung – $price_de €";
                $message = $customer_line . "\n\n"
                    . "<b>" . intval($data['ticket_qty']) . "× Ticket</b> · " . esc_html($event_title) . "\n"
                    . "Bestellung #" . intval($order_id) . " · " . esc_html($status_label);
                if ($gateway) $message .= " · " . esc_html($gateway);
                break;

            case 'cancelled':
                $title   = "❌ Bestellung storniert – $price_de €";
                $message = $customer_line . "\n\n"
                    . "Bestellung #" . intval($order_id) . " wurde <b>storniert</b>.\n"
                    . intval($data['ticket_qty']) . "× Ticket · " . esc_html($event_title);
                $sound   = 'falling';
                break;

            case 'refunded':
                $title   = "💸 Erstattung – $price_de €";
                $message = $customer_line . "\n\n"
                    . "Bestellung #" . intval($order_id) . " wurde <b>erstattet</b>.\n"
                    . intval($data['ticket_qty']) . "× Ticket · " . esc_html($event_title);
                $sound   = 'falling';
                break;

            case 'failed':
                $title   = "⚠ Zahlung fehlgeschlagen – $price_de €";
                $message = $customer_line . "\n\n"
                    . "Bestellung #" . intval($order_id) . " · Zahlung fehlgeschlagen"
                    . ($gateway ? " (" . esc_html($gateway) . ")" : "") . ".\n"
                    . "Event: " . esc_html($event_title);
                $sound   = 'siren';
                break;

            default:
                return;
        }

        $payload = [
            'title'         => $title,
            'message'       => $message,
            'url'           => $order_url,
            'url_title'     => 'Bestellung öffnen',
            'sound'         => $sound,
            'priority'      => in_array($type, ['failed'], true) ? 1 : 0,
            'email_subject' => $title,
            'email_body'    => nl2br($message)
                . '<p style="margin-top:24px;"><a href="' . esc_url($order_url) . '" style="display:inline-block;padding:10px 20px;background:#0f172a;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Bestellung öffnen →</a></p>',
        ];

        foreach (array_keys($org_ids) as $org_id) {
            self::notify_organizer($org_id, $type, $payload);
        }
    }

    // ── Helpers ──

    /** Order-Daten normalisiert (native oder WC) */
    private static function collect_order_data($order_id) {
        $data = ['event_ids' => [], 'total' => 0, 'customer' => '', 'email' => '', 'ticket_qty' => 0];

        if (class_exists('TIX_Order')) {
            $order = TIX_Order::get($order_id);
            if ($order) {
                $data['total']    = (float) $order->get_total();
                $data['customer'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $data['email']    = $order->get_billing_email();
                foreach ($order->get_items() as $it) {
                    $eid = method_exists($it, 'get_event_id') ? intval($it->get_event_id()) : 0;
                    if ($eid && !in_array($eid, $data['event_ids'], true)) $data['event_ids'][] = $eid;
                    $data['ticket_qty'] += method_exists($it, 'get_quantity') ? intval($it->get_quantity()) : 0;
                }
                return $data;
            }
        }
        if (function_exists('wc_get_order')) {
            $wo = wc_get_order($order_id);
            if ($wo) {
                $data['total']    = (float) $wo->get_total();
                $data['customer'] = trim($wo->get_billing_first_name() . ' ' . $wo->get_billing_last_name());
                $data['email']    = $wo->get_billing_email();
                foreach ($wo->get_items() as $it) {
                    $pid = $it->get_product_id();
                    $eid = intval(get_post_meta($pid, '_tix_parent_event_id', true));
                    if ($eid && !in_array($eid, $data['event_ids'], true)) $data['event_ids'][] = $eid;
                    $data['ticket_qty'] += $it->get_quantity();
                }
            }
        }
        return $data;
    }

    /** Organizer-IDs aus Event-Liste (inkl. Co-Organizer) */
    private static function collect_organizer_ids(array $event_ids) {
        $org_ids = [];
        foreach ($event_ids as $eid) {
            $oid  = intval(get_post_meta($eid, '_tix_organizer_id', true));
            $coid = intval(get_post_meta($eid, '_tix_co_organizer_id', true));
            if ($oid)  $org_ids[$oid]  = true;
            if ($coid) $org_ids[$coid] = true;
        }
        return $org_ids;
    }

    /**
     * Support-Ticket: einmalig bei Anlage des Posts benachrichtigen.
     * Wir nutzen meta-flag damit Update-Saves nicht erneut pingen.
     */
    public static function handle_support_ticket($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if ($post->post_type !== 'tix_support_ticket') return;

        // Dedupe: pro Ticket nur einmal benachrichtigen
        if (get_post_meta($post_id, '_tix_notified', true)) return;
        update_post_meta($post_id, '_tix_notified', time());

        $subject_full = get_the_title($post_id) ?: 'Support-Anfrage';
        $customer_email = get_post_meta($post_id, '_tix_sp_email', true);
        $customer_name  = get_post_meta($post_id, '_tix_sp_name',  true);
        $event_id       = intval(get_post_meta($post_id, '_tix_sp_event_id', true));
        $order_id       = intval(get_post_meta($post_id, '_tix_sp_order_id', true));
        $category       = get_post_meta($post_id, '_tix_sp_category', true);

        // Erste Nachricht extrahieren für Preview (über Helper damit Legacy-Encoding repariert wird)
        $messages = class_exists('TIX_Support')
            ? TIX_Support::get_messages_public($post_id)
            : get_post_meta($post_id, '_tix_sp_messages', true);
        $preview = '';
        if (is_array($messages) && !empty($messages)) {
            $first = reset($messages);
            $preview = is_array($first) ? ($first['content'] ?? $first['message'] ?? $first['text'] ?? '') : (string) $first;
            $preview = wp_strip_all_tags($preview);
            if (mb_strlen($preview) > 200) $preview = mb_substr($preview, 0, 200) . '…';
        } else {
            $preview = wp_strip_all_tags($post->post_content ?? '');
            if (mb_strlen($preview) > 200) $preview = mb_substr($preview, 0, 200) . '…';
        }

        // Veranstalter bestimmen (via event_id oder order_id)
        $org_ids = [];
        if ($event_id) {
            $oid  = intval(get_post_meta($event_id, '_tix_organizer_id', true));
            $coid = intval(get_post_meta($event_id, '_tix_co_organizer_id', true));
            if ($oid)  $org_ids[$oid]  = true;
            if ($coid) $org_ids[$coid] = true;
        }
        // Fallback: aus order → event → organizer (nur wenn Event noch nicht bekannt)
        if (empty($org_ids) && $order_id && class_exists('TIX_Order')) {
            $order = TIX_Order::get($order_id);
            if ($order) {
                foreach ($order->get_items() as $it) {
                    $eid = method_exists($it, 'get_event_id') ? intval($it->get_event_id()) : 0;
                    if (!$eid) continue;
                    $oid  = intval(get_post_meta($eid, '_tix_organizer_id', true));
                    $coid = intval(get_post_meta($eid, '_tix_co_organizer_id', true));
                    if ($oid)  $org_ids[$oid]  = true;
                    if ($coid) $org_ids[$coid] = true;
                }
            }
        }
        if (empty($org_ids)) return; // kein Veranstalter zuordenbar → Admin-Only (WP-Standard)

        $support_url = admin_url('admin.php?page=tix-support#ticket-' . $post_id);
        $title   = "🆘 Neue Support-Anfrage";
        $message = "<b>" . esc_html($customer_name ?: $customer_email ?: '(anonym)') . "</b>";
        if ($customer_email && $customer_name) $message .= " <small>(" . esc_html($customer_email) . ")</small>";
        $message .= "\n\n<b>" . esc_html($subject_full) . "</b>";
        if ($category) $message .= "\nKategorie: " . esc_html($category);
        if ($preview)  $message .= "\n\n" . esc_html($preview);

        $payload = [
            'title'         => $title,
            'message'       => $message,
            'url'           => $support_url,
            'url_title'     => 'Anfrage öffnen',
            'sound'         => 'intermission',
            'priority'      => 0,
            'email_subject' => $title . ': ' . $subject_full,
            'email_body'    => nl2br($message)
                . '<p style="margin-top:24px;"><a href="' . esc_url($support_url) . '" style="display:inline-block;padding:10px 20px;background:#dc2626;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Anfrage öffnen →</a></p>',
        ];

        foreach (array_keys($org_ids) as $org_id) {
            self::notify_organizer($org_id, 'support', $payload);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // CHECK-IN
    // ═══════════════════════════════════════════════════════════════

    public static function handle_ticket_checkin($ticket_id) {
        $ticket_id = intval($ticket_id);
        if (!$ticket_id) return;

        // Sponsor-Tickets lautlos halten (keine Push/Mail an Veranstalter beim Einlass)
        if (get_post_meta($ticket_id, '_tix_ticket_sponsor_id', true)) return;

        // Dedupe (falls Hook mehrfach feuert)
        $notify_key = 'tix_checkin_notified_' . $ticket_id;
        if (get_transient($notify_key)) return;
        set_transient($notify_key, 1, HOUR_IN_SECONDS);

        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        if (!$event_id) return;

        $org_ids = self::collect_organizer_ids([$event_id]);
        if (empty($org_ids)) return;

        $code       = get_post_meta($ticket_id, '_tix_ticket_code', true);
        $owner_name = get_post_meta($ticket_id, '_tix_ticket_owner_name', true);
        $by         = get_post_meta($ticket_id, '_tix_ticket_checkin_by', true);
        $event_title = get_the_title($event_id) ?: 'Event #' . $event_id;

        $title   = "✅ Check-in bei " . mb_substr($event_title, 0, 60);
        $message = "<b>" . esc_html($owner_name ?: 'Gast') . "</b>";
        if ($code) $message .= " · <code>" . esc_html($code) . "</code>";
        $message .= "\n\n" . esc_html($event_title);
        if ($by) $message .= "\nvia: " . esc_html($by);

        $payload = [
            'title'         => $title,
            'message'       => $message,
            'url'           => admin_url('admin.php?page=tix-checkin&event_id=' . $event_id),
            'url_title'     => 'Check-in-Liste',
            'sound'         => 'magic',
            'priority'      => -1, // leise — viele Check-ins bei Einlass
            'email_subject' => $title,
            'email_body'    => nl2br($message),
        ];

        foreach (array_keys($org_ids) as $org_id) {
            self::notify_organizer($org_id, 'checkin', $payload);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // TICKET-TRANSFER (auf Meta-Change reagiert)
    // ═══════════════════════════════════════════════════════════════

    public static function handle_ticket_meta_change($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key !== '_tix_ticket_transfer_to') return;
        if (get_post_type($post_id) !== 'tix_ticket') return;
        if (empty($meta_value)) return; // leerer Wert = kein Transfer

        // Sponsor-Tickets lautlos halten (keine Push/Mail an Veranstalter beim Transfer)
        if (get_post_meta($post_id, '_tix_ticket_sponsor_id', true)) return;

        // Dedupe
        $notify_key = 'tix_transfer_notified_' . $post_id;
        if (get_transient($notify_key)) return;
        set_transient($notify_key, 1, DAY_IN_SECONDS);

        $event_id = intval(get_post_meta($post_id, '_tix_ticket_event_id', true));
        if (!$event_id) return;

        $org_ids = self::collect_organizer_ids([$event_id]);
        if (empty($org_ids)) return;

        $code        = get_post_meta($post_id, '_tix_ticket_code', true);
        $from_name   = get_post_meta($post_id, '_tix_ticket_owner_name', true);
        $to_user_id  = intval($meta_value);
        $to_user     = $to_user_id ? get_userdata($to_user_id) : null;
        $to_name     = $to_user ? trim($to_user->first_name . ' ' . $to_user->last_name) : '';
        if (!$to_name && $to_user) $to_name = $to_user->display_name;

        $event_title = get_the_title($event_id) ?: 'Event #' . $event_id;
        $title   = "🔄 Ticket umgeschrieben";
        $message = "Ticket <code>" . esc_html($code) . "</code> für <b>" . esc_html($event_title) . "</b>\n\n"
                 . "Von: " . esc_html($from_name ?: '(unbekannt)') . "\n"
                 . "Auf: " . esc_html($to_name ?: '(User #' . $to_user_id . ')');

        $payload = [
            'title'         => $title,
            'message'       => $message,
            'url'           => admin_url('post.php?post=' . $post_id . '&action=edit'),
            'url_title'     => 'Ticket öffnen',
            'sound'         => 'pushover',
            'email_subject' => $title,
            'email_body'    => nl2br($message),
        ];

        foreach (array_keys($org_ids) as $org_id) {
            self::notify_organizer($org_id, 'transfer', $payload);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // TEST-PUSH (Admin-only)
    // ═══════════════════════════════════════════════════════════════

    private static function send_test_push($org_id) {
        $org = get_post($org_id);
        if (!$org) return;

        $payload = [
            'title'         => '✅ Test-Push von Tixomat',
            'message'       => "<b>" . esc_html($org->post_title) . "</b>\n\nDeine Pushover-Konfiguration funktioniert! Du erhältst ab jetzt Benachrichtigungen für Bestellungen und Support-Anfragen deiner Events.",
            'url'           => admin_url('post.php?post=' . $org_id . '&action=edit'),
            'url_title'     => 'Konfiguration öffnen',
            'sound'         => 'magic',
        ];

        $sent_count = 0;
        foreach (self::active_pushovers($org_id) as $cfg) {
            if (self::send_pushover($cfg['user_key'], $cfg['app_token'], $payload)) $sent_count++;
        }

        add_action('admin_notices', function() use ($sent_count) {
            $cls = $sent_count > 0 ? 'notice-success' : 'notice-warning';
            $msg = $sent_count > 0
                ? "Test-Push an {$sent_count} Pushover-Endpunkt(e) gesendet."
                : "Kein Test-Push gesendet — keine aktiven Pushover-Konfigurationen.";
            echo '<div class="notice ' . $cls . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        });
    }
}
