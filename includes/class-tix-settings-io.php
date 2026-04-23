<?php
/**
 * TIX Settings Import/Export
 *
 * Exportiert ALLE Plugin-Einstellungen (Farben, Typografie, Checkboxen, Templates,
 * Landingpage-Footer, Event-Vorlagen, Typo-Klassen-Registry etc.) als JSON-Datei.
 * Import: lädt ein JSON hoch und wendet es auf die aktuelle Site an.
 *
 * Nicht enthalten (bewusst):
 *   - Bestellungen, Tickets, Kunden, WP-User (sind Daten, nicht Konfiguration)
 *   - Events, Veranstalter, Venues (sind Content)
 *   - API-Keys (security — optional-toggle beim Export)
 */
if (!defined('ABSPATH')) exit;

class TIX_Settings_IO {

    const PAGE_SLUG  = 'tix-settings-io';
    const NONCE_EXP  = 'tix_settings_export';
    const NONCE_IMP  = 'tix_settings_import';

    /**
     * Liste aller Options, die exportiert werden.
     * Alle Keys aus wp_options mit Prefix 'tix_' + explizite Ausnahmen.
     */
    const OPTION_KEYS = [
        'tix_settings',                    // Hauptkonfiguration (Farben, Typo, Toggles, etc.)
        'tix_event_templates',             // Event-Vorlagen
        'tix_landing_footer_credit',       // Footer-Credit global
        'tix_abandoned_cart_settings',     // Abandoned Cart-Konfiguration
        'tix_team_capabilities',           // Team-Rollen
        'tix_promoter_settings',           // Promoter-Einstellungen
        'tix_fees_settings',               // Gebühren-Konfiguration
    ];

    /**
     * Zusätzlich: alle Option-Rows deren name mit diesen Prefixen beginnt.
     * Fängt auch Keys ab, die wir oben vielleicht vergessen haben.
     */
    const OPTION_PREFIXES = [
        'tix_',
    ];

    /**
     * Keys, die NIEMALS exportiert werden (Security + Daten).
     */
    const EXCLUDED_KEYS = [
        // Security / API-Keys (nur auf Nutzerwunsch mitsenden → Option-Toggle)
        // Bleiben per Default drin, aber flagbar
        // Caches & Daten
        'tix_db_version',
        'tix_deleted_accounts_log',
        'tix_docs_hooks_scan',
        'tix_organizer_events_cache',
    ];

    /**
     * Default-sensitive Keys — werden nur auf explizite Nutzerwahl mit-exportiert.
     */
    const SENSITIVE_KEYS = [
        'anthropic_api_key',
        'openai_api_key',
        'ai_guard_api_key',
        'paypal_client_id',
        'paypal_client_secret',
        'paypal_sandbox_client_id',
        'paypal_sandbox_client_secret',
        'mollie_api_key',
        'mollie_test_api_key',
        'meta_pixel_token',
        'meta_ads_access_token',
        'bot_telegram_token',
        // Bank-Konfigurationsdaten — auch sensitiv?
    ];

    public static function init() {
        add_action('admin_menu',                         [__CLASS__, 'register_menu'], 40);
        add_action('admin_post_tix_settings_export',     [__CLASS__, 'handle_export']);
        add_action('admin_post_tix_settings_import',     [__CLASS__, 'handle_import']);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Einstellungen Import/Export',
            'Import / Export',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // UI
    // ═══════════════════════════════════════════════════════════════

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');

        $msg  = $_GET['msg']  ?? '';
        $stats = isset($_GET['stats']) ? json_decode(base64_decode($_GET['stats']), true) : null;
        ?>
        <div class="wrap" style="max-width:900px;">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                <span class="dashicons dashicons-migrate" style="font-size:28px;width:28px;height:28px;color:var(--tix-primary,#FF5500);"></span>
                Einstellungen Import / Export
            </h1>

            <?php if ($msg === 'export_failed'): ?>
                <div class="notice notice-error"><p>Export fehlgeschlagen.</p></div>
            <?php elseif ($msg === 'import_ok' && is_array($stats)): ?>
                <div class="notice notice-success">
                    <p><strong>Import erfolgreich</strong> — <?php echo intval($stats['restored']); ?> Einstellungs-Keys wiederhergestellt, <?php echo intval($stats['skipped']); ?> übersprungen.</p>
                </div>
            <?php elseif ($msg === 'import_failed'): ?>
                <div class="notice notice-error"><p>Import fehlgeschlagen. Datei ungültig oder beschädigt.</p></div>
            <?php elseif ($msg === 'import_invalid'): ?>
                <div class="notice notice-error"><p>Datei-Format ungültig. Bitte eine gültige Tixomat-Settings-JSON hochladen.</p></div>
            <?php endif; ?>

            <p style="color:#64748b;font-size:14px;line-height:1.6;max-width:720px;">
                Exportiere alle Plugin-Einstellungen (Farben, Typografie, Checkboxen, Design, Templates, Integrationen) als JSON und importiere sie auf einer anderen Tixomat-Installation. <strong>Nicht enthalten:</strong> Bestellungen, Tickets, Kunden, Events, Veranstalter, WP-Benutzer — das sind Daten, keine Konfiguration.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:24px;">

                <?php // ═══ EXPORT ═══ ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;">
                    <h2 style="margin:0 0 8px;font-size:17px;">
                        <span class="dashicons dashicons-download" style="color:#2563eb;"></span>
                        Export
                    </h2>
                    <p style="color:#64748b;font-size:13px;margin:0 0 18px;line-height:1.5;">
                        Lädt eine JSON-Datei mit allen Plugin-Einstellungen dieser Installation herunter.
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tix_settings_export">
                        <?php wp_nonce_field(self::NONCE_EXP); ?>

                        <div style="padding:10px 12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:14px;">
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                                <input type="checkbox" name="include_sensitive" value="1">
                                <span><strong>API-Keys + Secrets mit-exportieren</strong></span>
                            </label>
                            <p style="margin:6px 0 0 24px;font-size:11px;color:#94a3b8;">PayPal, Mollie, Anthropic, OpenAI, Meta Pixel, Telegram-Bot. Standard: <strong>aus</strong>.</p>
                        </div>

                        <button type="submit" class="button button-primary" style="width:100%;padding:10px;">
                            <span class="dashicons dashicons-download" style="margin-top:4px;"></span>
                            Einstellungen herunterladen (.json)
                        </button>
                    </form>

                    <details style="margin-top:16px;font-size:12px;">
                        <summary style="cursor:pointer;color:#2563eb;">Was genau wird exportiert?</summary>
                        <ul style="margin:10px 0 0 18px;color:#64748b;line-height:1.7;font-size:12px;">
                            <li>Komplette <code>tix_settings</code>-Option (Farben, Typo, Checkboxen, Checkout-Konfiguration, etc.)</li>
                            <li>Event-Vorlagen</li>
                            <li>Landing-Footer-Credit</li>
                            <li>Abandoned-Cart-Konfiguration</li>
                            <li>Team-Berechtigungen</li>
                            <li>Promoter/Gebühren-Konfiguration</li>
                            <li>Alle weiteren <code>tix_*</code>-Optionen (außer Caches und Delete-Logs)</li>
                        </ul>
                    </details>
                </div>

                <?php // ═══ IMPORT ═══ ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;">
                    <h2 style="margin:0 0 8px;font-size:17px;">
                        <span class="dashicons dashicons-upload" style="color:#16a34a;"></span>
                        Import
                    </h2>
                    <p style="color:#64748b;font-size:13px;margin:0 0 18px;line-height:1.5;">
                        Lädt eine zuvor exportierte JSON-Datei und überschreibt die aktuellen Einstellungen.
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="tix_settings_import">
                        <?php wp_nonce_field(self::NONCE_IMP); ?>

                        <input type="file" name="tix_settings_file" accept="application/json,.json" required
                               style="display:block;width:100%;padding:8px;border:1px dashed #cbd5e1;border-radius:8px;font-size:13px;margin-bottom:14px;background:#f8fafc;">

                        <div style="padding:10px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;margin-bottom:14px;">
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                                <input type="checkbox" name="confirm_overwrite" value="1" required>
                                <span><strong>Ich bestätige:</strong> Bestehende Einstellungen werden überschrieben.</span>
                            </label>
                        </div>

                        <button type="submit" class="button button-primary" style="width:100%;padding:10px;background:#16a34a;border-color:#16a34a;">
                            <span class="dashicons dashicons-upload" style="margin-top:4px;"></span>
                            Einstellungen importieren
                        </button>
                    </form>
                </div>
            </div>

            <div style="margin-top:30px;padding:16px 20px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;font-size:13px;color:#1e3a8a;line-height:1.6;">
                <strong>Hinweis für Site-Migration:</strong> Exportiere zuerst auf der Quell-Site mit aktivem <em>„API-Keys mitnehmen"</em>-Toggle (nur wenn du die Zugangsdaten kopieren willst), dann importiere auf der Ziel-Site. Eine Neu-Installation des Plugins ist nicht nötig — das Ziel muss nur Tixomat installiert haben.
            </div>
        </div>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════
    // EXPORT HANDLER
    // ═══════════════════════════════════════════════════════════════

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_EXP);

        $include_sensitive = !empty($_POST['include_sensitive']);

        $export = [
            '_meta' => [
                'format_version' => 1,
                'exported_at'    => gmdate('c'),
                'source_host'    => parse_url(home_url(), PHP_URL_HOST),
                'plugin_version' => defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : 'unknown',
                'sensitive'      => $include_sensitive,
            ],
            'options' => [],
        ];

        $options_data = self::collect_options($include_sensitive);
        $export['options'] = $options_data;

        if (empty($options_data)) {
            wp_safe_redirect(add_query_arg('msg', 'export_failed', admin_url('admin.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        $filename = 'tixomat-settings-' . parse_url(home_url(), PHP_URL_HOST) . '-' . gmdate('Y-m-d-His') . '.json';

        while (ob_get_level() > 0) ob_end_clean();
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Sammelt alle relevanten Option-Werte aus wp_options.
     * @return array [option_name => option_value]
     */
    private static function collect_options($include_sensitive) {
        global $wpdb;
        $out = [];

        // 1. Explizite Keys
        foreach (self::OPTION_KEYS as $key) {
            $val = get_option($key, null);
            if ($val !== null) $out[$key] = maybe_unserialize(is_string($val) ? $val : (is_array($val) ? $val : $val));
        }

        // 2. Alles mit tix_-Prefix (aus DB direkt, um nichts zu übersehen)
        foreach (self::OPTION_PREFIXES as $prefix) {
            $like = $wpdb->esc_like($prefix) . '%';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ));
            foreach ($rows as $r) {
                $key = $r->option_name;

                // Caches + Logs + Transients überspringen
                if (in_array($key, self::EXCLUDED_KEYS, true)) continue;
                if (strpos($key, '_transient_') === 0) continue;
                if (strpos($key, '_site_transient_') === 0) continue;
                if (strpos($key, 'tix_stats_') === 0) continue;
                if (strpos($key, '_tix_order_') === 0) continue;      // per-order options
                if (strpos($key, '_tix_ticket_') === 0) continue;     // per-ticket

                $out[$key] = maybe_unserialize($r->option_value);
            }
        }

        // 3. Sensitive Keys aus tix_settings entfernen, wenn nicht explizit included
        if (!$include_sensitive && isset($out['tix_settings']) && is_array($out['tix_settings'])) {
            foreach (self::SENSITIVE_KEYS as $sk) {
                if (isset($out['tix_settings'][$sk])) {
                    $out['tix_settings'][$sk] = ''; // leeren, nicht unset (damit Schema beim Import ok bleibt)
                }
            }
        }

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════
    // IMPORT HANDLER
    // ═══════════════════════════════════════════════════════════════

    public static function handle_import() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_IMP);

        $redirect_base = admin_url('admin.php?page=' . self::PAGE_SLUG);

        if (empty($_FILES['tix_settings_file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('msg', 'import_failed', $redirect_base));
            exit;
        }

        $json = file_get_contents($_FILES['tix_settings_file']['tmp_name']);
        $data = json_decode($json, true);

        if (!is_array($data) || empty($data['options']) || empty($data['_meta'])) {
            wp_safe_redirect(add_query_arg('msg', 'import_invalid', $redirect_base));
            exit;
        }

        $restored = 0;
        $skipped  = 0;

        foreach ($data['options'] as $key => $value) {
            // Whitelist-Check: nur tix_-Keys importieren
            $allowed = false;
            foreach (self::OPTION_PREFIXES as $prefix) {
                if (strpos($key, $prefix) === 0) { $allowed = true; break; }
            }
            if (!$allowed || in_array($key, self::EXCLUDED_KEYS, true)) {
                $skipped++;
                continue;
            }

            // Spezial-Behandlung: tix_settings — mit existierenden Sensitive-Keys mergen
            // falls der User keine API-Keys mit-exportiert hat und die Ziel-Site welche hat
            if ($key === 'tix_settings' && is_array($value)) {
                $existing = get_option('tix_settings', []);
                if (is_array($existing)) {
                    foreach (self::SENSITIVE_KEYS as $sk) {
                        // Wenn Import-Wert leer ist, behalte vorhandenen Wert
                        if (empty($value[$sk]) && !empty($existing[$sk])) {
                            $value[$sk] = $existing[$sk];
                        }
                    }
                }
            }

            update_option($key, $value, 'yes');
            $restored++;
        }

        // Cache flushen
        wp_cache_flush();

        $stats = base64_encode(wp_json_encode([
            'restored' => $restored,
            'skipped'  => $skipped,
        ]));

        wp_safe_redirect(add_query_arg(['msg' => 'import_ok', 'stats' => $stats], $redirect_base));
        exit;
    }
}
