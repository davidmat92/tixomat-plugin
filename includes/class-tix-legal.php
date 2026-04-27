<?php
/**
 * Tixomat Legal Pages
 *
 * 5 Rechtstext-Shortcodes mit konsistentem Frontend-Design:
 *   [tix_impressum]
 *   [tix_datenschutz]
 *   [tix_agb]
 *   [tix_zahlungsweisen]
 *   [tix_widerruf]
 *
 * Inhalte werden unter dem Option-Key `tix_legal_pages` gespeichert
 * (separates Storage vom restlichen tix_settings, da Texte mehrere KB haben können).
 *
 * Verwaltung über eigenes Submenü "Rechtstexte" unter Tixomat.
 */
if (!defined('ABSPATH')) exit;

class TIX_Legal {

    const OPTION_KEY = 'tix_legal_pages';

    /**
     * Definiert die 5 Rechtstexte: Slug => [Default-Title, Default-Body]
     */
    public static function pages() {
        return [
            'impressum' => [
                'label'    => 'Impressum',
                'shortcode'=> 'tix_impressum',
                'icon'     => 'dashicons-id-alt',
                'default_title' => 'Impressum',
                'default'  => "<h2>Angaben gemäß § 5 TMG</h2>\n<p>Firmenname<br>Straße Nr.<br>PLZ Ort<br>Deutschland</p>\n<h2>Vertreten durch</h2>\n<p>Vorname Nachname</p>\n<h2>Kontakt</h2>\n<p>Telefon: +49 ...<br>E-Mail: kontakt@example.com</p>\n<h2>Umsatzsteuer-ID</h2>\n<p>Umsatzsteuer-Identifikationsnummer gem. § 27 a UStG: DE...</p>",
            ],
            'datenschutz' => [
                'label'    => 'Datenschutz',
                'shortcode'=> 'tix_datenschutz',
                'icon'     => 'dashicons-shield',
                'default_title' => 'Datenschutzerklärung',
                'default'  => "<h2>1. Datenschutz auf einen Blick</h2>\n<p>Diese Datenschutzerklärung informiert dich über die Verarbeitung personenbezogener Daten beim Besuch unserer Website und bei der Nutzung unserer Ticketservices.</p>\n<h2>2. Verantwortliche Stelle</h2>\n<p>Firmenname<br>Straße Nr., PLZ Ort<br>E-Mail: datenschutz@example.com</p>\n<h2>3. Erhobene Daten</h2>\n<ul><li>Server-Logs (IP, User-Agent, Zeitpunkt)</li><li>Bestelldaten (Name, E-Mail, Adresse)</li><li>Cookies / Sessions für Warenkorb</li></ul>\n<h2>4. Rechtsgrundlagen</h2>\n<p>Vertragsabwicklung (Art. 6 Abs. 1 lit. b DSGVO), berechtigte Interessen (lit. f), Einwilligung (lit. a).</p>\n<h2>5. Speicherdauer</h2>\n<p>Steuerlich relevante Daten 10 Jahre, sonstige bis zum Vertragsende plus 3 Jahre.</p>\n<h2>6. Deine Rechte</h2>\n<p>Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit, Widerspruch, Beschwerde bei der Aufsichtsbehörde.</p>",
            ],
            'agb' => [
                'label'    => 'AGB',
                'shortcode'=> 'tix_agb',
                'icon'     => 'dashicons-clipboard',
                'default_title' => 'Allgemeine Geschäftsbedingungen',
                'default'  => "<h2>§ 1 Geltungsbereich</h2>\n<p>Diese AGB gelten für alle Verträge zwischen dem Veranstalter und dem Kunden über den Erwerb von Eintrittstickets.</p>\n<h2>§ 2 Vertragsschluss</h2>\n<p>Mit Klick auf den Bestell-Button gibt der Kunde ein verbindliches Angebot ab. Die Bestellbestätigung per E-Mail begründet den Vertragsschluss.</p>\n<h2>§ 3 Preise und Zahlung</h2>\n<p>Es gelten die zum Zeitpunkt der Bestellung angegebenen Preise inkl. der gesetzlichen MwSt. Zahlung per den im Checkout angebotenen Methoden.</p>\n<h2>§ 4 Lieferung</h2>\n<p>Tickets werden ausschließlich digital per E-Mail versandt.</p>\n<h2>§ 5 Veranstaltungsausfall</h2>\n<p>Bei Ausfall der Veranstaltung erstattet der Veranstalter den Ticketpreis. Service-/Versandgebühren sind nicht erstattungsfähig.</p>\n<h2>§ 6 Hausrecht</h2>\n<p>Der Veranstalter behält sich vor, einzelnen Personen den Zutritt zu verweigern.</p>",
            ],
            'zahlungsweisen' => [
                'label'    => 'Zahlungsweisen',
                'shortcode'=> 'tix_zahlungsweisen',
                'icon'     => 'dashicons-money-alt',
                'default_title' => 'Zahlungsweisen',
                'default'  => "<p>Wir bieten dir folgende Zahlungsarten an:</p>\n<h2>Sofort verfügbare Zahlung</h2>\n<ul><li><strong>Kreditkarte</strong> (Visa, Mastercard, American Express)</li><li><strong>PayPal</strong></li><li><strong>Sofortüberweisung / Klarna</strong></li><li><strong>Apple Pay / Google Pay</strong></li></ul>\n<h2>Verzögerte Zahlung</h2>\n<ul><li><strong>Vorkasse / Banküberweisung</strong> — Tickets werden nach Zahlungseingang versendet</li></ul>\n<p>Alle Preise inkl. der gesetzlichen MwSt. Eventuelle Service-Gebühren werden im Checkout transparent ausgewiesen.</p>",
            ],
            'widerruf' => [
                'label'    => 'Widerruf',
                'shortcode'=> 'tix_widerruf',
                'icon'     => 'dashicons-undo',
                'default_title' => 'Widerrufsbelehrung',
                'default'  => "<h2>Hinweis zum Widerrufsrecht</h2>\n<p><strong>Bei Eintrittskarten zu Veranstaltungen besteht nach § 312g Abs. 2 Nr. 9 BGB grundsätzlich kein Widerrufsrecht</strong>, sofern der Vertrag für die Erbringung von Dienstleistungen im Zusammenhang mit Freizeitbetätigungen einen spezifischen Termin oder Zeitraum für die Erbringung vorsieht.</p>\n<h2>Ausnahmefall: Sonstige Leistungen</h2>\n<p>Für sonstige Leistungen (z.B. Gutscheine ohne Bindung an einen konkreten Termin) gilt:</p>\n<h3>Widerrufsrecht</h3>\n<p>Du hast das Recht, binnen 14 Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.</p>\n<h3>Folgen des Widerrufs</h3>\n<p>Wir erstatten alle Zahlungen unverzüglich, spätestens innerhalb von 14 Tagen.</p>\n<h3>Ausübung des Widerrufs</h3>\n<p>Sende eine eindeutige Erklärung (Brief, E-Mail) an die im Impressum genannte Adresse.</p>",
            ],
        ];
    }

    public static function init() {
        // Submenü „Rechtstexte" unter Tixomat
        add_action('admin_menu', [__CLASS__, 'register_admin_page'], 50);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        // Shortcodes registrieren
        $pages = self::pages();
        foreach ($pages as $slug => $page) {
            add_shortcode($page['shortcode'], function($atts) use ($slug) {
                return self::render_shortcode($slug);
            });
        }
    }

    public static function register_admin_page() {
        add_submenu_page(
            'tixomat',
            'Rechtstexte',
            'Rechtstexte',
            'manage_options',
            'tix-legal',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function register_settings() {
        register_setting('tix_legal_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => [],
        ]);
    }

    public static function sanitize_settings($input) {
        if (!is_array($input)) return [];
        $out = [];
        $pages = self::pages();
        foreach ($pages as $slug => $page) {
            $title   = isset($input[$slug]['title']) ? sanitize_text_field($input[$slug]['title']) : '';
            $content = isset($input[$slug]['content']) ? wp_kses_post(wp_unslash($input[$slug]['content'])) : '';
            $out[$slug] = [
                'title'   => $title !== '' ? $title : $page['default_title'],
                'content' => $content,
            ];
        }
        return $out;
    }

    /**
     * Liefert title + content für eine Seite (mit Default-Fallback)
     */
    public static function get_page($slug) {
        $pages   = self::pages();
        if (!isset($pages[$slug])) return null;
        $stored  = get_option(self::OPTION_KEY, []);
        $page    = $pages[$slug];
        $title   = !empty($stored[$slug]['title'])   ? $stored[$slug]['title']   : $page['default_title'];
        $content = !empty($stored[$slug]['content']) ? $stored[$slug]['content'] : $page['default'];
        return ['title' => $title, 'content' => $content];
    }

    // ════════════════════════════════════════════════════════════
    // FRONTEND: Shortcode-Rendering mit konsistentem Design
    // ════════════════════════════════════════════════════════════

    public static function render_shortcode($slug) {
        $data = self::get_page($slug);
        if (!$data) return '';

        self::enqueue_styles();

        ob_start();
        ?>
        <article class="tix-legal tix-legal--<?php echo esc_attr($slug); ?>">
            <header class="tix-legal-head">
                <h1 class="tix-legal-title"><?php echo esc_html($data['title']); ?></h1>
            </header>
            <div class="tix-legal-content">
                <?php echo wp_kses_post(wpautop(self::convert_basic($data['content']))); ?>
            </div>
            <footer class="tix-legal-footer">
                <small>Stand: <?php echo esc_html(date_i18n(get_option('date_format'), strtotime(get_option('tix_legal_updated_at') ?: current_time('mysql')))); ?></small>
            </footer>
        </article>
        <?php
        return ob_get_clean();
    }

    /**
     * Konvertiert minimal Markdown-ähnliches Format wenn der User
     * lieber **bold** und ## Headings tippt statt HTML.
     * Bestehender HTML-Inhalt bleibt unangetastet.
     */
    private static function convert_basic($content) {
        if (strpos($content, '<') !== false) return $content; // hat schon HTML
        $content = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $content);
        $content = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $content);
        $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
        return $content;
    }

    public static function enqueue_styles() {
        // Inline-CSS einmalig pro Page-Render ausgeben
        static $printed = false;
        if ($printed) return;
        $printed = true;

        $accent = '#FF5500';
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (!empty($s['color_primary'])) $accent = $s['color_primary'];
        ?>
        <style id="tix-legal-style">
            .tix-legal {
                max-width: 760px;
                margin: 40px auto;
                padding: 0 20px;
                font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: #1f2937;
                line-height: 1.7;
                font-size: 16px;
            }
            .tix-legal-head {
                margin-bottom: 32px;
                padding-bottom: 20px;
                border-bottom: 2px solid <?php echo esc_attr($accent); ?>;
            }
            .tix-legal-title {
                font-family: "Sora", "DM Sans", sans-serif;
                font-size: 2rem;
                font-weight: 800;
                margin: 0;
                color: #111827;
                letter-spacing: -0.02em;
            }
            .tix-legal-content h2 {
                font-family: "Sora", "DM Sans", sans-serif;
                font-size: 1.3rem;
                font-weight: 700;
                margin: 32px 0 12px;
                color: #111827;
            }
            .tix-legal-content h3 {
                font-family: "Sora", "DM Sans", sans-serif;
                font-size: 1.1rem;
                font-weight: 700;
                margin: 24px 0 8px;
                color: #1f2937;
            }
            .tix-legal-content p {
                margin: 0 0 14px;
            }
            .tix-legal-content ul,
            .tix-legal-content ol {
                margin: 0 0 14px;
                padding-left: 24px;
            }
            .tix-legal-content li {
                margin-bottom: 6px;
            }
            .tix-legal-content strong { color: #111827; }
            .tix-legal-content a {
                color: <?php echo esc_attr($accent); ?>;
                text-decoration: underline;
                text-underline-offset: 2px;
            }
            .tix-legal-content a:hover { text-decoration: none; }
            .tix-legal-footer {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px solid #e5e7eb;
                color: #9ca3af;
                font-size: 0.85rem;
                text-align: right;
            }
            @media (max-width: 600px) {
                .tix-legal { margin: 24px auto; padding: 0 16px; font-size: 15px; }
                .tix-legal-title { font-size: 1.6rem; }
                .tix-legal-content h2 { font-size: 1.15rem; }
                .tix-legal-content h3 { font-size: 1.02rem; }
            }
        </style>
        <?php
    }

    // ════════════════════════════════════════════════════════════
    // ADMIN: Verwaltungsseite mit 5 Tabs
    // ════════════════════════════════════════════════════════════

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');

        // Save handling
        if (isset($_POST['tix_legal_nonce']) && wp_verify_nonce($_POST['tix_legal_nonce'], 'tix_legal_save')) {
            $clean = self::sanitize_settings($_POST[self::OPTION_KEY] ?? []);
            update_option(self::OPTION_KEY, $clean);
            update_option('tix_legal_updated_at', current_time('mysql'));
            echo '<div class="notice notice-success is-dismissible"><p>Rechtstexte gespeichert.</p></div>';
        }

        $stored = get_option(self::OPTION_KEY, []);
        $pages  = self::pages();
        $active_tab = $_GET['tab'] ?? 'impressum';
        if (!isset($pages[$active_tab])) $active_tab = 'impressum';
        ?>
        <div class="wrap">
            <h1>Rechtstexte</h1>
            <p style="color:#6b7280;font-size:14px;margin:8px 0 20px;">
                Verwalte hier deine 5 Rechtstexte zentral. Jeder Text wird über den passenden Shortcode auf einer Seite eingefügt:
            </p>

            <?php // Shortcode-Übersicht ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-bottom:24px;">
                <?php foreach ($pages as $slug => $page): ?>
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
                        <div style="font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;"><?php echo esc_html($page['label']); ?></div>
                        <code style="font-size:12px;background:#fff;padding:3px 7px;border-radius:4px;display:inline-block;margin-top:4px;">[<?php echo esc_attr($page['shortcode']); ?>]</code>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // Tab-Nav ?>
            <h2 class="nav-tab-wrapper">
                <?php foreach ($pages as $slug => $page): ?>
                    <a href="?page=tix-legal&tab=<?php echo esc_attr($slug); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons <?php echo esc_attr($page['icon']); ?>" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;margin-right:4px;"></span>
                        <?php echo esc_html($page['label']); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post" style="background:#fff;border:1px solid #c3c4c7;border-top:0;padding:24px;">
                <?php wp_nonce_field('tix_legal_save', 'tix_legal_nonce'); ?>

                <?php
                // Hidden inputs für alle anderen Tabs damit deren Werte erhalten bleiben
                foreach ($pages as $slug => $page):
                    $current_title   = $stored[$slug]['title']   ?? $page['default_title'];
                    $current_content = $stored[$slug]['content'] ?? $page['default'];
                    if ($slug === $active_tab) continue;
                ?>
                    <input type="hidden" name="<?php echo self::OPTION_KEY; ?>[<?php echo esc_attr($slug); ?>][title]" value="<?php echo esc_attr($current_title); ?>">
                    <textarea name="<?php echo self::OPTION_KEY; ?>[<?php echo esc_attr($slug); ?>][content]" style="display:none;"><?php echo esc_textarea($current_content); ?></textarea>
                <?php endforeach; ?>

                <?php
                // Active Tab editieren
                $page = $pages[$active_tab];
                $current_title   = $stored[$active_tab]['title']   ?? $page['default_title'];
                $current_content = $stored[$active_tab]['content'] ?? $page['default'];
                ?>
                <h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
                    <span class="dashicons <?php echo esc_attr($page['icon']); ?>"></span>
                    <?php echo esc_html($page['label']); ?>
                </h2>
                <p style="color:#6b7280;margin:0 0 16px;">
                    Shortcode für diese Seite: <code style="background:#f3f4f6;padding:3px 8px;border-radius:4px;">[<?php echo esc_attr($page['shortcode']); ?>]</code>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tix-legal-title">Überschrift</label></th>
                        <td>
                            <input type="text" id="tix-legal-title" class="regular-text"
                                   name="<?php echo self::OPTION_KEY; ?>[<?php echo esc_attr($active_tab); ?>][title]"
                                   value="<?php echo esc_attr($current_title); ?>">
                            <p class="description">Wird als H1 oben auf der gerenderten Seite angezeigt.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tix-legal-content">Inhalt</label></th>
                        <td>
                            <textarea id="tix-legal-content"
                                      name="<?php echo self::OPTION_KEY; ?>[<?php echo esc_attr($active_tab); ?>][content]"
                                      rows="22" style="width:100%;font-family:Menlo,Monaco,monospace;font-size:13px;line-height:1.5;"
                                      placeholder="<?php echo esc_attr($page['default']); ?>"><?php echo esc_textarea($current_content); ?></textarea>
                            <p class="description">
                                HTML erlaubt: <code>&lt;h2&gt;</code> <code>&lt;h3&gt;</code> <code>&lt;p&gt;</code> <code>&lt;ul&gt;</code> <code>&lt;li&gt;</code> <code>&lt;strong&gt;</code> <code>&lt;a&gt;</code> u.a.
                                Alternativ Markdown-light: <code>## Überschrift</code>, <code>### Unterüberschrift</code>, <code>**fett**</code>.
                                Leerzeilen werden automatisch zu Absätzen.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Speichern</button>
                </p>
            </form>
        </div>
        <?php
    }
}
