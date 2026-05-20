<?php
/**
 * TIX Invoicing — Manager + Settings + Customer-Frontend
 *
 * Pluggable Provider-System für externe Rechnungs-Anbieter (Lexoffice, sevdesk, ...).
 *
 * Provider registrieren sich via TIX_Invoicing::register_provider(['id', 'name', 'class']).
 * Jede Provider-Klasse muss diese statischen Methoden haben:
 *   - is_configured(): bool
 *   - create_invoice(int $order_id): array  → ['success'=>bool,'invoice_id','invoice_number','pdf_file_id','error']
 *   - fetch_pdf(int $order_id): string|null  → raw PDF binary
 *   - render_settings(array $settings): void
 *   - sanitize_settings(array $input): array
 *
 * Order-Meta (gespeichert als Option `_tix_invoice_<order_id>`):
 *   ['provider','invoice_id','invoice_number','pdf_file_id','status','created_at','error']
 *
 * @since 1.38.200
 */
if (!defined('ABSPATH')) exit;

class TIX_Invoicing {

    // Settings leben innerhalb von tix_settings unter dem Sub-Key 'invoicing'
    const TIX_SETTINGS_SUBKEY = 'invoicing';
    const LEGACY_OPTION_KEY   = 'tix_invoicing_settings'; // vor Migration
    const ORDER_META_PREFIX   = '_tix_invoice_';

    private static $providers = [];

    public static function init() {
        add_action('admin_post_tix_invoice_download',        [__CLASS__, 'handle_download']);
        add_action('admin_post_nopriv_tix_invoice_download', [__CLASS__, 'handle_download']);
        add_action('admin_post_tix_invoice_retry',           [__CLASS__, 'handle_retry']);

        // Auto-Trigger bei Bestellungsabschluss (alle Gateways feuern tix_order_completed)
        add_action('tix_order_completed', [__CLASS__, 'maybe_create_for_order'], 30, 1);

        // Einmalige Migration alter standalone-Option → tix_settings.invoicing
        add_action('admin_init', [__CLASS__, 'maybe_migrate_legacy_settings'], 5);
    }

    public static function maybe_migrate_legacy_settings() {
        $legacy = get_option(self::LEGACY_OPTION_KEY);
        if (!is_array($legacy) || empty($legacy)) return;
        $tix = (array) get_option('tix_settings', []);
        if (empty($tix[self::TIX_SETTINGS_SUBKEY])) {
            $tix[self::TIX_SETTINGS_SUBKEY] = $legacy;
            update_option('tix_settings', $tix);
        }
        delete_option(self::LEGACY_OPTION_KEY);
    }

    /* ─────────────── PROVIDER-REGISTRY ─────────────── */

    public static function register_provider(string $id, string $name, string $class) {
        self::$providers[$id] = ['id' => $id, 'name' => $name, 'class' => $class];
    }

    public static function get_providers(): array {
        return self::$providers;
    }

    public static function get_active_provider_id(): string {
        $s = self::get_settings();
        return (string) ($s['active_provider'] ?? '');
    }

    /** Master-Schalter — Default an, wenn nicht gesetzt (backwards-compat). */
    public static function is_enabled(): bool {
        $s = self::get_settings();
        return !isset($s['enabled']) || !empty($s['enabled']);
    }

    public static function get_active_provider_class(): ?string {
        $id = self::get_active_provider_id();
        return $id && isset(self::$providers[$id]) ? self::$providers[$id]['class'] : null;
    }

    public static function get_settings(string $provider_id = ''): array {
        $tix = (array) get_option('tix_settings', []);
        $s   = (array) ($tix[self::TIX_SETTINGS_SUBKEY] ?? []);
        if ($provider_id === '') return $s;
        return (array) ($s[$provider_id] ?? []);
    }

    public static function update_provider_settings(string $provider_id, array $values) {
        $tix = (array) get_option('tix_settings', []);
        $tix[self::TIX_SETTINGS_SUBKEY] = (array) ($tix[self::TIX_SETTINGS_SUBKEY] ?? []);
        $tix[self::TIX_SETTINGS_SUBKEY][$provider_id] = $values;
        update_option('tix_settings', $tix);
    }

    /* ─────────────── AUTO-CREATE BEI ORDER ─────────────── */

    public static function maybe_create_for_order(int $order_id) {
        // Master-Schalter: globaler Stopp
        if (!self::is_enabled()) return;

        // Bereits Rechnung vorhanden? Dann skip (idempotent)
        if (self::get_invoice_meta($order_id)) return;

        // Aktiver Provider konfiguriert?
        $class = self::get_active_provider_class();
        if (!$class || !class_exists($class)) return;
        if (!call_user_func([$class, 'is_configured'])) return;

        // 0-Euro-Bestellungen überspringen (Freikarten, Sponsoring etc.)
        global $wpdb;
        $total = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT total FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if ($total <= 0.0) return;

        $result = call_user_func([$class, 'create_invoice'], $order_id);

        // Persistieren (Erfolg + Fehler — damit man im Admin Retry sehen kann)
        $meta = [
            'provider'       => self::get_active_provider_id(),
            'invoice_id'     => (string) ($result['invoice_id']     ?? ''),
            'invoice_number' => (string) ($result['invoice_number'] ?? ''),
            'pdf_file_id'    => (string) ($result['pdf_file_id']    ?? ''),
            'status'         => !empty($result['success']) ? 'created' : 'error',
            'created_at'     => current_time('mysql'),
            'error'          => !empty($result['success']) ? '' : (string) ($result['error'] ?? 'Unbekannter Fehler'),
        ];
        update_option(self::ORDER_META_PREFIX . $order_id, $meta, false);

        // Optionale Note in der Order-Note-Liste (falls vorhanden)
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            $msg = !empty($result['success'])
                ? '🧾 Rechnung erstellt (' . $meta['provider'] . '): ' . ($meta['invoice_number'] ?: $meta['invoice_id'])
                : '⚠️ Rechnungserstellung fehlgeschlagen (' . $meta['provider'] . '): ' . $meta['error'];
            TIX_Order_Admin::add_note($order_id, $msg);
        }
    }

    /* ─────────────── ORDER-API (für My-Account/Checkout-Anzeige) ─────────────── */

    public static function get_invoice_meta(int $order_id): ?array {
        $m = get_option(self::ORDER_META_PREFIX . $order_id, null);
        return is_array($m) ? $m : null;
    }

    public static function has_downloadable_invoice(int $order_id): bool {
        $m = self::get_invoice_meta($order_id);
        return $m && ($m['status'] ?? '') === 'created' && !empty($m['invoice_id']);
    }

    public static function get_download_url(int $order_id, string $order_key = ''): string {
        // Order-Key als Authentifizierung (Standard-Pattern im Plugin)
        if ($order_key === '') {
            global $wpdb;
            $order_key = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
            ));
        }
        return add_query_arg([
            'action'    => 'tix_invoice_download',
            'order_id'  => $order_id,
            'order_key' => $order_key,
        ], admin_url('admin-post.php'));
    }

    /* ─────────────── DOWNLOAD-ENDPOINT ─────────────── */

    public static function handle_download() {
        $order_id  = intval($_GET['order_id'] ?? 0);
        $order_key = sanitize_text_field($_GET['order_key'] ?? '');
        if (!$order_id || !$order_key) wp_die('Ungültige Anfrage.', 'Rechnung', ['response' => 400]);

        global $wpdb;
        $stored_key = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if (!$stored_key || !hash_equals($stored_key, $order_key)) {
            // Admin darf auch ohne Key
            if (!current_user_can('manage_options')) {
                wp_die('Nicht autorisiert.', 'Rechnung', ['response' => 403]);
            }
        }

        $meta = self::get_invoice_meta($order_id);
        if (!$meta || ($meta['status'] ?? '') !== 'created') {
            wp_die('Für diese Bestellung gibt es noch keine Rechnung.', 'Rechnung', ['response' => 404]);
        }

        $class = isset(self::$providers[$meta['provider']]) ? self::$providers[$meta['provider']]['class'] : null;
        if (!$class || !class_exists($class)) {
            wp_die('Rechnungs-Provider nicht verfügbar.', 'Rechnung', ['response' => 500]);
        }

        $pdf = call_user_func([$class, 'fetch_pdf'], $order_id);
        if (!$pdf) {
            wp_die('Rechnungs-PDF konnte nicht geladen werden. Bitte später erneut versuchen.', 'Rechnung', ['response' => 500]);
        }

        nocache_headers();
        $filename = 'Rechnung-' . ($meta['invoice_number'] ?: $order_id) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /* ─────────────── ADMIN-RETRY (manuell aus Order-Edit) ─────────────── */

    public static function handle_retry() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_invoice_retry');
        $order_id = intval($_GET['order_id'] ?? 0);
        if (!$order_id) wp_die('Order-ID fehlt.');
        // Existierendes Meta löschen, damit maybe_create_for_order neu durchläuft
        delete_option(self::ORDER_META_PREFIX . $order_id);
        self::maybe_create_for_order($order_id);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=tix-orders'));
        exit;
    }

    /* ─────────────── SETTINGS-RENDER (in tix-settings Tab) + SANITIZE ─────────────── */

    /**
     * Sanitize-Helper für TIX_Settings::sanitize.
     * Wird aus dem dortigen Code aufgerufen, wenn unser Tab-Marker im POST ist.
     */
    public static function sanitize_settings($input): array {
        $clean = [];
        $clean['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $allowed_ids = array_merge([''], array_keys(self::$providers));
        $clean['active_provider'] = in_array(($input['active_provider'] ?? ''), $allowed_ids, true) ? (string) $input['active_provider'] : '';

        foreach (self::$providers as $id => $p) {
            $provider_input = is_array($input[$id] ?? null) ? $input[$id] : [];
            if (class_exists($p['class']) && method_exists($p['class'], 'sanitize_settings')) {
                $clean[$id] = call_user_func([$p['class'], 'sanitize_settings'], $provider_input);
            } else {
                $clean[$id] = array_map('sanitize_text_field', $provider_input);
            }
        }
        return $clean;
    }

    /**
     * Rendert den Inhalt des "Rechnungen"-Tabs innerhalb der tix-settings Page.
     * Form + submit_button kommen vom Parent (tix-settings hat ein einziges Form).
     * Feld-Namen: tix_settings[invoicing][active_provider] / tix_settings[invoicing][<provider>][...]
     */
    public static function render_settings_pane() {
        $settings = self::get_settings();
        $active   = (string) ($settings['active_provider'] ?? '');
        $enabled  = self::is_enabled();
        $base     = 'tix_settings[' . self::TIX_SETTINGS_SUBKEY . ']';
        ?>
        <input type="hidden" name="tix_settings[invoicing_marker]" value="1">

        <div class="tix-card" style="<?php echo $enabled ? 'background:#f0fdf4;border-color:#bbf7d0;' : 'background:#fef2f2;border-color:#fecaca;'; ?>">
            <div class="tix-card-header">
                <span class="dashicons dashicons-<?php echo $enabled ? 'yes-alt' : 'no-alt'; ?>"></span>
                <h3>Master-Schalter</h3>
                <span style="margin-left:auto;font-size:11px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;<?php echo $enabled ? 'color:#065f46;background:#d1fae5;' : 'color:#7f1d1d;background:#fecaca;'; ?>padding:3px 10px;border-radius:99px;"><?php echo $enabled ? '● aktiv' : '⏸ pausiert'; ?></span>
            </div>
            <div class="tix-card-body">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
                    <input type="hidden" name="<?php echo esc_attr($base); ?>[enabled]" value="0">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[enabled]" value="1" <?php checked($enabled); ?> style="width:18px;height:18px;">
                    <span><strong>Rechnungserstellung aktivieren</strong> — wenn an, wird bei jeder bezahlten Bestellung (>0&nbsp;€) automatisch eine Rechnung erstellt.</span>
                </label>
                <?php if (!$enabled): ?>
                <p style="margin:10px 0 0;color:#7f1d1d;font-size:13px;background:rgba(255,255,255,0.6);padding:8px 12px;border-radius:6px;">⏸ <strong>Pausiert:</strong> Für neue Bestellungen werden keine Rechnungen erstellt. Bestehende Rechnungen bleiben unverändert verfügbar.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-media-document"></span>
                <h3>Aktiver Rechnungs-Anbieter</h3>
            </div>
            <div class="tix-card-body">
                <p style="color:#64748b;font-size:13px;margin-top:0;">Bei jeder bezahlten Bestellung wird automatisch eine Rechnung erstellt und dem Kunden in seiner Bestellübersicht zum Download angeboten. <strong>0-€-Bestellungen</strong> (Freikarten, Sponsoring) werden übersprungen.</p>
                <select name="<?php echo esc_attr($base); ?>[active_provider]" style="min-width:280px;padding:6px 10px;font-size:14px;">
                    <option value="" <?php selected($active, ''); ?>>— Kein Provider ausgewählt —</option>
                    <?php foreach (self::$providers as $p): ?>
                        <option value="<?php echo esc_attr($p['id']); ?>" <?php selected($active, $p['id']); ?>><?php echo esc_html($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php foreach (self::$providers as $id => $p):
            $is_active = ($id === $active);
            $provider_settings = (array) ($settings[$id] ?? []);
            $is_configured = class_exists($p['class']) && method_exists($p['class'], 'is_configured')
                ? call_user_func([$p['class'], 'is_configured']) : false;
            ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <h3><?php echo esc_html($p['name']); ?></h3>
                    <span style="margin-left:auto;font-size:11px;font-weight:500;letter-spacing:0.05em;text-transform:uppercase;<?php echo $is_configured ? 'color:#065f46;background:#d1fae5;' : 'color:#92400e;background:#fef3c7;'; ?>padding:3px 10px;border-radius:99px;"><?php echo $is_configured ? '✓ konfiguriert' : 'nicht konfiguriert'; ?><?php echo $is_active ? ' · aktiv' : ''; ?></span>
                </div>
                <div class="tix-card-body">
                    <?php
                    if (class_exists($p['class']) && method_exists($p['class'], 'render_settings')) {
                        call_user_func([$p['class'], 'render_settings'], $provider_settings, $base . '[' . $id . ']');
                    } else {
                        echo '<p style="color:#dc2626;">Provider-Klasse ' . esc_html($p['class']) . ' nicht gefunden.</p>';
                    }
                    ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php self::render_recent_invoices(); ?>
        <?php
    }

    private static function render_recent_invoices() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, order_number, billing_first_name, billing_last_name, billing_email, total, created_at FROM {$wpdb->prefix}tix_orders ORDER BY id DESC LIMIT 50");

        ?>
        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-list-view"></span>
                <h3>Letzte 50 Bestellungen — Rechnungs-Status</h3>
            </div>
            <div class="tix-card-body" style="padding:0;">
            <table class="widefat striped" style="margin-top:8px;">
                <thead><tr>
                    <th>Bestellung</th><th>Kunde</th><th>Betrag</th><th>Status</th><th>Rechnung</th><th>Aktion</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $o):
                    $meta = self::get_invoice_meta(intval($o->id));
                    $total = floatval($o->total);
                    $is_free = $total <= 0;
                    if (!$meta && !$is_free) {
                        $status_html = '<span style="color:#9ca3af;">noch nicht erstellt</span>';
                        $invoice_html = '—';
                    } elseif (!$meta && $is_free) {
                        $status_html = '<span style="color:#9ca3af;">übersprungen (0 €)</span>';
                        $invoice_html = '—';
                    } elseif (($meta['status'] ?? '') === 'created') {
                        $status_html = '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:600;">✓ erstellt</span>';
                        $invoice_html = '<code style="font-size:11px;">' . esc_html($meta['invoice_number'] ?: $meta['invoice_id']) . '</code>';
                    } else {
                        $status_html  = '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:600;" title="' . esc_attr($meta['error'] ?? '') . '">✗ Fehler</span>';
                        $invoice_html = '<span style="font-size:11px;color:#991b1b;">' . esc_html(mb_substr($meta['error'] ?? '', 0, 80)) . '</span>';
                    }
                    $retry_url    = wp_nonce_url(admin_url('admin-post.php?action=tix_invoice_retry&order_id=' . intval($o->id)), 'tix_invoice_retry');
                    $download_url = self::has_downloadable_invoice(intval($o->id)) ? self::get_download_url(intval($o->id)) : '';
                    $detail_url   = admin_url('admin.php?page=tix-orders&order_id=' . intval($o->id));
                ?>
                    <tr>
                        <td><a href="<?php echo esc_url($detail_url); ?>" title="Bestellung öffnen"><strong><?php echo esc_html($o->order_number); ?></strong></a></td>
                        <td><?php echo esc_html(trim($o->billing_first_name . ' ' . $o->billing_last_name)); ?><br><span style="font-size:11px;color:#64748b;"><?php echo esc_html($o->billing_email); ?></span></td>
                        <td><?php echo number_format($total, 2, ',', '.'); ?>&nbsp;€</td>
                        <td><?php echo $status_html; ?></td>
                        <td><?php echo $invoice_html; ?></td>
                        <td>
                            <?php if ($download_url): ?><a href="<?php echo esc_url($download_url); ?>" class="button button-small">PDF ↓</a> <?php endif; ?>
                            <?php if (!$is_free): ?><a href="<?php echo esc_url($retry_url); ?>" class="button button-small" title="Erneut versuchen">↻</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }
}
