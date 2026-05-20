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

    const OPTION_KEY      = 'tix_invoicing_settings';
    const ORDER_META_PREFIX = '_tix_invoice_';

    private static $providers = [];

    public static function init() {
        add_action('admin_menu',        [__CLASS__, 'register_menu'], 60);
        add_action('admin_init',        [__CLASS__, 'register_settings']);
        add_action('admin_post_tix_invoice_download',        [__CLASS__, 'handle_download']);
        add_action('admin_post_nopriv_tix_invoice_download', [__CLASS__, 'handle_download']);
        add_action('admin_post_tix_invoice_retry',           [__CLASS__, 'handle_retry']);

        // Auto-Trigger bei Bestellungsabschluss (alle Gateways feuern tix_order_completed)
        add_action('tix_order_completed', [__CLASS__, 'maybe_create_for_order'], 30, 1);
    }

    /* ─────────────── PROVIDER-REGISTRY ─────────────── */

    public static function register_provider(string $id, string $name, string $class) {
        self::$providers[$id] = ['id' => $id, 'name' => $name, 'class' => $class];
    }

    public static function get_providers(): array {
        return self::$providers;
    }

    public static function get_active_provider_id(): string {
        $s = (array) get_option(self::OPTION_KEY, []);
        return (string) ($s['active_provider'] ?? '');
    }

    public static function get_active_provider_class(): ?string {
        $id = self::get_active_provider_id();
        return $id && isset(self::$providers[$id]) ? self::$providers[$id]['class'] : null;
    }

    public static function get_settings(string $provider_id = ''): array {
        $s = (array) get_option(self::OPTION_KEY, []);
        if ($provider_id === '') return $s;
        return (array) ($s[$provider_id] ?? []);
    }

    public static function update_provider_settings(string $provider_id, array $values) {
        $s = (array) get_option(self::OPTION_KEY, []);
        $s[$provider_id] = $values;
        update_option(self::OPTION_KEY, $s);
    }

    /* ─────────────── AUTO-CREATE BEI ORDER ─────────────── */

    public static function maybe_create_for_order(int $order_id) {
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

    /* ─────────────── ADMIN-MENU + SETTINGS-PAGE ─────────────── */

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Rechnungen',
            'Rechnungen',
            'manage_options',
            'tix-invoicing',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting('tix_invoicing_group', self::OPTION_KEY, [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
        ]);
    }

    public static function sanitize_settings($input): array {
        $clean = [];
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

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $settings = (array) get_option(self::OPTION_KEY, []);
        $active   = (string) ($settings['active_provider'] ?? '');
        ?>
        <div class="wrap" style="max-width:880px;">
            <h1 style="display:flex;align-items:center;gap:10px;"><span class="dashicons dashicons-media-document"></span> Rechnungen</h1>
            <p>Automatische Erstellung von Rechnungen bei jeder bezahlten Bestellung. <strong>0-€-Bestellungen</strong> (Freikarten, Sponsoring etc.) werden übersprungen. Die Rechnung wird dem Kunden in seiner Bestellungs-Übersicht zum Download angeboten.</p>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('tix_invoicing_group'); ?>

                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px 26px;margin-bottom:18px;">
                    <h2 style="margin-top:0;font-size:18px;">Aktiver Rechnungs-Anbieter</h2>
                    <p style="color:#64748b;font-size:13px;margin-top:0;">Nur einer ist aktiv. Konfiguration dann unten je Provider.</p>
                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[active_provider]" style="min-width:280px;padding:6px 10px;font-size:14px;">
                        <option value="" <?php selected($active, ''); ?>>— Deaktiviert (keine Rechnungserstellung) —</option>
                        <?php foreach (self::$providers as $p): ?>
                            <option value="<?php echo esc_attr($p['id']); ?>" <?php selected($active, $p['id']); ?>><?php echo esc_html($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php foreach (self::$providers as $id => $p):
                    $is_active = ($id === $active);
                    $provider_settings = (array) ($settings[$id] ?? []);
                    $is_configured = class_exists($p['class']) && method_exists($p['class'], 'is_configured')
                        ? call_user_func([$p['class'], 'is_configured']) : false;
                    ?>
                    <details<?php echo $is_active ? ' open' : ''; ?> style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:0;margin-bottom:14px;">
                        <summary style="cursor:pointer;padding:16px 24px;font-size:16px;font-weight:600;display:flex;justify-content:space-between;align-items:center;list-style:none;">
                            <span><?php echo esc_html($p['name']); ?></span>
                            <span style="font-size:11px;font-weight:500;letter-spacing:0.05em;text-transform:uppercase;<?php echo $is_configured ? 'color:#065f46;background:#d1fae5;' : 'color:#92400e;background:#fef3c7;'; ?>padding:3px 10px;border-radius:99px;"><?php echo $is_configured ? '✓ konfiguriert' : 'nicht konfiguriert'; ?><?php echo $is_active ? ' · aktiv' : ''; ?></span>
                        </summary>
                        <div style="padding:0 24px 22px;">
                            <?php
                            if (class_exists($p['class']) && method_exists($p['class'], 'render_settings')) {
                                call_user_func([$p['class'], 'render_settings'], $provider_settings, self::OPTION_KEY . '[' . $id . ']');
                            } else {
                                echo '<p style="color:#dc2626;">Provider-Klasse ' . esc_html($p['class']) . ' nicht gefunden.</p>';
                            }
                            ?>
                        </div>
                    </details>
                <?php endforeach; ?>

                <?php submit_button('Einstellungen speichern'); ?>
            </form>

            <?php self::render_recent_invoices(); ?>
        </div>
        <?php
    }

    private static function render_recent_invoices() {
        global $wpdb;
        // Letzte 20 Bestellungen mit Rechnungs-Status (über Options-Tabelle)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.id, o.order_number, o.billing_first_name, o.billing_last_name, o.billing_email, o.total, o.created_at, op.option_value
             FROM {$wpdb->prefix}tix_orders o
             LEFT JOIN {$wpdb->options} op ON op.option_name = %s
             WHERE op.option_value IS NOT NULL
             ORDER BY o.id DESC LIMIT 20",
            'placeholder' // Workaround: wir lassen den JOIN-Filter weg, weil concat in WHERE komplex
        ));
        // Einfacherer Ansatz: lade die letzten 50 Orders, prüfe per Option-Lookup
        $rows = $wpdb->get_results("SELECT id, order_number, billing_first_name, billing_last_name, billing_email, total, created_at FROM {$wpdb->prefix}tix_orders ORDER BY id DESC LIMIT 50");

        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px 26px;margin-top:18px;">
            <h2 style="margin-top:0;font-size:16px;">Letzte 50 Bestellungen — Rechnungs-Status</h2>
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
                    $retry_url = wp_nonce_url(admin_url('admin-post.php?action=tix_invoice_retry&order_id=' . intval($o->id)), 'tix_invoice_retry');
                    $download_url = self::has_downloadable_invoice(intval($o->id)) ? self::get_download_url(intval($o->id)) : '';
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($o->order_number); ?></strong></td>
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
        <?php
    }
}
