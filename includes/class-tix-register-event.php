<?php
/**
 * TIX Register Event — "Event in 2 Minuten" Landing Page
 *
 * Shortcode [tix_register_event]: 3-Step Flow
 * 1. Event erstellen (KI-Chat / Flyer / URL)
 * 2. Vorschau & Anpassen
 * 3. Konto erstellen + Veröffentlichen
 */
if (!defined('ABSPATH')) exit;

class TIX_Register_Event {

    /** Lucide-Icons (ISC) für den evendis-Look: Name → innere SVG-Elemente (viewBox 24, Strich) */
    const LUCIDE = [
        'pencil'   => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
        'camera'   => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'check'    => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'user'     => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
        'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M10 16h4"/><path d="M12 14v4"/>',
        'rocket'   => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
    ];

    public static function icon($name, $size = 24) {
        if (!isset(self::LUCIDE[$name])) return '';
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . intval($size) . '" height="' . intval($size) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . self::LUCIDE[$name] . '</svg>';
    }

    /** evendis-Look (Mehr-Veranstalter-Modus): Manrope, Rot/Nachtblau, Lucide statt Emojis. */
    private static function evendis_look() {
        return (bool) apply_filters('tix_register_event_evendis_look', class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi());
    }

    public static function init() {
        add_shortcode('tix_register_event', [__CLASS__, 'render']);

        // AJAX: öffentlich (nicht eingeloggt)
        add_action('wp_ajax_nopriv_tix_register_and_publish', [__CLASS__, 'ajax_register_and_publish']);
        add_action('wp_ajax_tix_register_and_publish',        [__CLASS__, 'ajax_register_and_publish']);

        // KI-Endpoints auch für nicht-eingeloggte User öffnen
        add_action('wp_ajax_nopriv_tix_ai_chat',        ['TIX_AI_Writer', 'ajax_chat']);
        add_action('wp_ajax_nopriv_tix_ai_fill_fields', ['TIX_AI_Writer', 'ajax_fill_fields']);
        add_action('wp_ajax_nopriv_tix_ai_upload_image', ['TIX_AI_Writer', 'ajax_upload_image']);

        // Feuerwerk: erstes Event eines Organizers
        add_action('transition_post_status', [__CLASS__, 'check_first_event'], 10, 3);
        add_action('admin_footer', [__CLASS__, 'render_fireworks_popup']);
    }

    /**
     * Shortcode [tix_register_event]
     */
    public static function render($atts) {
        // Bereits eingeloggt? → zum Dashboard
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            $dash_url = admin_url('admin.php?page=tix-organizer-dashboard');
            return '<div class="tix-re" style="text-align:center;padding:40px 20px;">'
                . '<p>Du bist bereits eingeloggt.</p>'
                . '<a href="' . esc_url($dash_url) . '" class="tix-re-btn-primary">Zum Dashboard →</a>'
                . '</div>';
        }

        $atts = shortcode_atts([
            'title'    => 'Veröffentliche dein Event in 2 Minuten',
            'subtitle' => 'Kostenlos registrieren. Dein Event wird automatisch erstellt.',
            'mode'     => 'light',
        ], $atts);

        wp_enqueue_style('tix-register-event', TIXOMAT_URL . 'assets/css/register-event.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-register-event', TIXOMAT_URL . 'assets/js/register-event.js', ['jquery'], TIXOMAT_VERSION, true);
        $ai_name = tix_get_settings('ai_assistant_name') ?: 'Evendis-Assistent';
        $evendis = self::evendis_look();
        if ($evendis) wp_enqueue_style('tix-register-evendis', TIXOMAT_URL . 'assets/css/register-evendis.css', ['tix-register-event'], TIXOMAT_VERSION);
        $icons = [];
        if ($evendis) {
            // Emoji der Fortschrittsschritte → Lucide
            foreach (['📤' => 'upload', '🔍' => 'search', '📝' => 'file', '✅' => 'check', '🌐' => 'globe', '👤' => 'user', '🏢' => 'building', '🎉' => 'calendar', '🚀' => 'rocket'] as $emoji => $name) {
                $icons[$emoji] = self::icon($name, 18);
            }
        }
        wp_localize_script('tix-register-event', 'tixRegister', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tix_register_event'),
            'aiNonce' => wp_create_nonce('tix_admin_action'),
            'aiName'  => $ai_name,
            'icons'   => (object) $icons,
        ]);

        $dark = $atts['mode'] === 'dark';
        // Mehr-Veranstalter-Modus (evendis): neue Veranstalter werden erst geprüft
        $review = class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi();
        $terms  = $review ? TIX_Org_Approval::terms() : ['version' => ''];

        ob_start();
        ?>
        <div class="tix-re <?php echo $dark ? 'tix-re-dark' : ''; ?><?php echo $evendis ? ' tix-re-evendis' : ''; ?>">

            <div class="tix-re-header">
                <h1 class="tix-re-title"><?php echo esc_html($atts['title']); ?></h1>
                <p class="tix-re-subtitle"><?php echo esc_html($atts['subtitle']); ?></p>
            </div>

            <?php // Stepper ?>
            <div class="tix-re-stepper">
                <div class="tix-re-step active" data-step="1"><span class="tix-re-step-num">1</span><span>Event</span></div>
                <div class="tix-re-step-line"></div>
                <div class="tix-re-step" data-step="2"><span class="tix-re-step-num">2</span><span>Vorschau</span></div>
                <div class="tix-re-step-line"></div>
                <div class="tix-re-step" data-step="3"><span class="tix-re-step-num">3</span><span>Konto</span></div>
            </div>

            <?php // ═══ STEP 1: Event erstellen ═══ ?>
            <div class="tix-re-panel active" data-step="1">
                <h2 class="tix-re-panel-title">Wie möchtest du dein Event erstellen?</h2>

                <div class="tix-re-modes">
                    <button type="button" class="tix-re-mode" data-mode="chat">
                        <span class="tix-re-mode-icon"><?php echo $evendis ? self::icon('pencil') : '📝'; ?></span>
                        <strong>Beschreiben</strong>
                        <span>In Stichpunkten oder Text</span>
                    </button>
                    <button type="button" class="tix-re-mode" data-mode="upload">
                        <span class="tix-re-mode-icon"><?php echo $evendis ? self::icon('camera') : '📸'; ?></span>
                        <strong>Flyer hochladen</strong>
                        <span>Bild analysieren lassen</span>
                    </button>
                    <button type="button" class="tix-re-mode" data-mode="url">
                        <span class="tix-re-mode-icon"><?php echo $evendis ? self::icon('link') : '🔗'; ?></span>
                        <strong>URL eingeben</strong>
                        <span>Eventseite analysieren</span>
                    </button>
                </div>

                <?php // Chat-Modus ?>
                <div class="tix-re-input-area" id="tix-re-chat-area" style="display:none;">
                    <div class="tix-re-chat" id="tix-re-chat"></div>
                    <div class="tix-re-chat-footer">
                        <textarea id="tix-re-chat-input" rows="2" placeholder="Beschreibe dein Event…"></textarea>
                        <button type="button" class="tix-re-btn-send" id="tix-re-chat-send">→</button>
                    </div>
                </div>

                <?php // Upload-Modus ?>
                <div class="tix-re-input-area" id="tix-re-upload-area" style="display:none;">
                    <div class="tix-re-dropzone" id="tix-re-dropzone">
                        <input type="file" id="tix-re-file" accept="image/*" style="display:none;">
                        <span class="tix-re-dropzone-text"><?php echo $evendis ? self::icon('upload', 20) : '📸'; ?> Flyer hierher ziehen oder <a href="#" id="tix-re-browse">durchsuchen</a></span>
                        <span class="tix-re-dropzone-hint">JPG, PNG, WEBP — Foto aufnehmen oder aus Galerie wählen</span>
                    </div>
                    <div id="tix-re-upload-preview" style="display:none;"></div>
                </div>

                <?php // URL-Modus ?>
                <div class="tix-re-input-area" id="tix-re-url-area" style="display:none;">
                    <input type="url" id="tix-re-url-input" class="tix-re-input" placeholder="https://eventseite.de/mein-event">
                    <button type="button" class="tix-re-btn-primary" id="tix-re-url-analyze">Analysieren</button>
                </div>

                <div class="tix-re-status" id="tix-re-status" style="display:none;"></div>
            </div>

            <?php // ═══ STEP 2: Vorschau ═══ ?>
            <div class="tix-re-panel" data-step="2" style="display:none;">
                <h2 class="tix-re-panel-title">Sieht das richtig aus?</h2>
                <div class="tix-re-preview" id="tix-re-preview"></div>
                <?php // Tickets: online verkaufen oder nur eintragen (1.38.367) ?>
                <div class="tix-re-ticket-mode" id="tix-re-ticket-mode" style="margin:16px 0;">
                    <div class="tix-re-preview-label" style="margin-bottom:6px;">Tickets</div>
                    <label style="display:block;margin:4px 0;"><input type="radio" name="tix_re_ticket_mode" value="online" checked> Online verkaufen</label>
                    <label style="display:block;margin:4px 0;"><input type="radio" name="tix_re_ticket_mode" value="free"> Nur eintragen (Eintritt frei)</label>
                    <label style="display:block;margin:4px 0;"><input type="radio" name="tix_re_ticket_mode" value="box_office"> Nur eintragen (Abendkasse, Preis optional)</label>
                    <div id="tix-re-box-price-wrap" style="display:none;margin:6px 0 0 24px;">
                        <input type="number" id="tix-re-box-price" class="tix-re-input" min="0" step="0.01" placeholder="Preis in € (optional)" style="max-width:200px;">
                    </div>
                    <label style="display:block;margin:4px 0;"><input type="radio" name="tix_re_ticket_mode" value="external"> Nur eintragen – Tickets bei einem anderen Shop</label>
                    <div id="tix-re-ext-wrap" style="display:none;margin:6px 0 0 24px;">
                        <input type="url" id="tix-re-ext-url" class="tix-re-input" placeholder="https://dein-ticketshop.de/event" style="max-width:420px;margin-bottom:6px;">
                        <input type="number" id="tix-re-ext-price" class="tix-re-input" min="0" step="0.01" placeholder="Preis ab in € (optional)" style="max-width:200px;">
                    </div>
                </div>
                <div class="tix-re-nav">
                    <button type="button" class="tix-re-btn-back" data-goto="1">← Zurück</button>
                    <button type="button" class="tix-re-btn-primary" data-goto="3">Weiter →</button>
                </div>
            </div>

            <?php // ═══ STEP 3: Konto ═══ ?>
            <div class="tix-re-panel" data-step="3" style="display:none;">
                <h2 class="tix-re-panel-title">Fast geschafft — erstelle dein Konto</h2>
                <form id="tix-re-register-form">
                    <div class="tix-re-fields">
                        <div class="tix-re-field tix-re-field-half">
                            <label>Vorname *</label>
                            <input type="text" name="first_name" required class="tix-re-input">
                        </div>
                        <div class="tix-re-field tix-re-field-half">
                            <label>Nachname *</label>
                            <input type="text" name="last_name" required class="tix-re-input">
                        </div>
                        <div class="tix-re-field">
                            <label>E-Mail-Adresse *</label>
                            <input type="email" name="email" required class="tix-re-input">
                        </div>
                        <div class="tix-re-field tix-re-field-half">
                            <label>Passwort *</label>
                            <input type="password" name="password" required class="tix-re-input" minlength="8" placeholder="Min. 8 Zeichen">
                        </div>
                        <div class="tix-re-field tix-re-field-half">
                            <label>Passwort bestätigen *</label>
                            <input type="password" name="password_confirm" required class="tix-re-input">
                        </div>
                        <div class="tix-re-field">
                            <label>Veranstalter-/Firmenname *</label>
                            <input type="text" name="organizer_name" required class="tix-re-input" placeholder="z.B. Kitchen Klub, Max Events GmbH">
                        </div>
                    </div>
                    <div class="tix-re-legal">
                        <label><input type="checkbox" name="accept_terms" required> Ich akzeptiere die Nutzungsbedingungen und Datenschutzerklärung.</label>
                        <?php if ($terms['version'] !== '') : ?>
                        <label style="display:block;margin-top:8px;"><input type="checkbox" name="accept_contract" value="<?php echo esc_attr($terms['version']); ?>" required>
                            Ich stimme dem <?php if ($terms['url']) : ?><a href="<?php echo esc_url($terms['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($terms['title']); ?></a><?php else : echo esc_html($terms['title']); endif; ?>
                            (Version <?php echo esc_html($terms['version']); ?>) zu.</label>
                        <?php endif; ?>
                        <?php if ($review) : ?>
                        <p style="margin:10px 0 0;font-size:13px;opacity:.75;">Wir prüfen jedes neue Veranstalter-Konto. Dein Event geht online, sobald wir dich freigeschaltet haben.</p>
                        <?php endif; ?>
                    </div>
                    <div class="tix-re-nav">
                        <button type="button" class="tix-re-btn-back" data-goto="2">← Zurück</button>
                        <button type="submit" class="tix-re-btn-publish" id="tix-re-publish-btn">
                            <span class="tix-re-btn-text"><?php echo $review ? 'Registrieren &amp; zur Prüfung einreichen' : ($evendis ? 'Event veröffentlichen' : 'Event veröffentlichen 🚀'); ?></span>
                            <span class="tix-re-btn-loading" style="display:none;">Wird erstellt…</span>
                        </button>
                    </div>
                    <div class="tix-re-error" id="tix-re-error" style="display:none;"></div>
                </form>
            </div>

            <?php // ═══ STEP 4: Erfolg ═══ ?>
            <div class="tix-re-panel" data-step="4" style="display:none;">
                <div class="tix-re-success">
                    <canvas id="tix-re-fireworks" width="400" height="300" style="position:absolute;top:0;left:0;right:0;pointer-events:none;"></canvas>
                    <div class="tix-re-success-check"><?php echo $evendis ? self::icon('check', 44) : '🎉'; ?></div>
                    <h2 id="tix-re-success-heading"><?php echo $review ? 'Geschafft! Wir prüfen dein Konto.' : 'Geschafft! Dein Event ist live.'; ?></h2>
                    <p id="tix-re-success-msg"></p>
                    <div class="tix-re-success-links">
                        <a href="#" id="tix-re-link-event" class="tix-re-btn-primary" target="_blank">Event ansehen →</a>
                        <a href="#" id="tix-re-link-dashboard" class="tix-re-btn-back">Zum Dashboard →</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Registrieren + Event erstellen + Veröffentlichen (alles in einem)
     */
    public static function ajax_register_and_publish() {
        check_ajax_referer('tix_register_event', 'nonce');

        // ── Validierung ──
        $first_name     = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name      = sanitize_text_field($_POST['last_name'] ?? '');
        $email          = sanitize_email($_POST['email'] ?? '');
        $password        = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $organizer_name = sanitize_text_field($_POST['organizer_name'] ?? '');
        $event_data     = json_decode(stripslashes($_POST['event_data'] ?? '{}'), true);
        // Tickets: online (Standard, wie bisher) | free | box_office | external (nur eintragen, 1.38.436: anderer Shop)
        $ticket_mode    = sanitize_key($_POST['ticket_mode'] ?? 'online');
        if (!in_array($ticket_mode, ['online', 'free', 'box_office', 'external'], true)) $ticket_mode = 'online';
        $external_url   = '';
        if ($ticket_mode === 'external') {
            $external_url = self::clean_shop_url(wp_unslash($_POST['external_url'] ?? ''));
            if ($external_url === '') wp_send_json_error(['message' => 'Bitte die Adresse deines Ticketshops angeben (https://…).', 'field' => 'external_url']);
        }

        if (!$first_name || !$last_name) wp_send_json_error(['message' => 'Bitte Vor- und Nachname angeben.']);
        if (!$email || !is_email($email)) wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
        if (strlen($password) < 8) wp_send_json_error(['message' => 'Passwort muss mindestens 8 Zeichen lang sein.']);
        if ($password !== $password_confirm) wp_send_json_error(['message' => 'Passwörter stimmen nicht überein.']);
        if (!$organizer_name) wp_send_json_error(['message' => 'Bitte einen Veranstalternamen angeben.']);
        if (email_exists($email)) wp_send_json_error(['message' => 'Diese E-Mail-Adresse ist bereits registriert.']);

        // Mehr-Veranstalter-Modus: Zustimmung zum Vermittlungsvertrag (aktuelle Version) ist Pflicht
        $review = class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi();
        $terms  = $review ? TIX_Org_Approval::terms() : ['version' => ''];
        $contract = sanitize_text_field(wp_unslash($_POST['accept_contract'] ?? ''));
        if ($terms['version'] !== '' && $contract !== $terms['version']) {
            wp_send_json_error(['message' => 'Bitte stimme dem ' . $terms['title'] . ' zu (die Seite wurde ggf. aktualisiert – bitte neu laden).']);
        }

        // ── 1. User erstellen ──
        $username = sanitize_user(strtolower($first_name . '.' . $last_name));
        $base_username = $username;
        $attempt = 0;
        while (username_exists($username) && $attempt < 10) {
            $username = $base_username . wp_rand(10, 9999);
            $attempt++;
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) wp_send_json_error(['message' => $user_id->get_error_message()]);

        wp_update_user([
            'ID'         => $user_id,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'role'       => 'tix_organizer',
        ]);

        // ── 2. Organizer CPT erstellen ──
        $org_id = wp_insert_post([
            'post_type'   => 'tix_organizer',
            'post_title'  => $organizer_name,
            'post_status' => 'publish',
            'post_author' => $user_id,
        ]);

        if ($org_id && !is_wp_error($org_id)) {
            update_post_meta($org_id, '_tix_org_user_id', $user_id);
            // Vor dem Event: wartet auf Freigabe (Event wird dann zurückgehalten statt veröffentlicht)
            if ($review) {
                TIX_Org_Approval::set_status($org_id, 'pending', ['by' => $user_id, 'note' => 'Registrierung', 'notify' => false]);
                if ($terms['version'] !== '') TIX_Org_Approval::accept_terms($org_id, $user_id, $terms['version']);
            }
        }

        // ── 3. Event erstellen ──
        $event_title = sanitize_text_field($event_data['title'] ?? 'Neues Event');

        $event_id = wp_insert_post([
            'post_type'    => 'event',
            'post_title'   => $event_title,
            'post_excerpt' => sanitize_textarea_field($event_data['excerpt'] ?? ''),
            'post_status'  => 'publish',
            'post_author'  => $user_id,
        ]);

        if (is_wp_error($event_id)) wp_send_json_error(['message' => 'Event konnte nicht erstellt werden.']);

        // Organizer zuweisen
        if ($org_id) update_post_meta($event_id, '_tix_organizer_id', $org_id);

        // Alle Event-Meta setzen
        $meta_map = [
            'date_start'  => '_tix_date_start',
            'date_end'    => '_tix_date_end',
            'time_start'  => '_tix_time_start',
            'time_end'    => '_tix_time_end',
            'time_doors'  => '_tix_time_doors',
            'description' => '_tix_info_description',
            'lineup'      => '_tix_info_lineup',
            'specials'    => '_tix_info_specials',
            'extra_info'  => '_tix_info_extra_info',
            'excerpt'     => '_tix_info_excerpt',
        ];

        foreach ($meta_map as $key => $meta_key) {
            if (!empty($event_data[$key])) {
                update_post_meta($event_id, $meta_key, wp_kses_post($event_data[$key]));
            }
        }

        if (!empty($event_data['age_limit'])) {
            update_post_meta($event_id, '_tix_info_age_limit', intval($event_data['age_limit']));
        }

        // Tickets
        if ($ticket_mode !== 'online') {
            // Nur eintragen: kein Online-Verkauf, keine Ticket-Kategorien nötig
            self::apply_listing_mode($event_id, $ticket_mode, wp_unslash($_POST[$ticket_mode === 'external' ? 'external_price' : 'box_office_price'] ?? ''), $external_url);
        } else {
            update_post_meta($event_id, '_tix_tickets_enabled', '1');
        }
        if ($ticket_mode === 'online' && !empty($event_data['tickets']) && is_array($event_data['tickets'])) {
            $cats = [];
            foreach ($event_data['tickets'] as $t) {
                $cats[] = [
                    'name'       => sanitize_text_field($t['name'] ?? 'Standard'),
                    'price'      => floatval($t['price'] ?? 0),
                    'qty'        => 0, // unbegrenzt
                    'sale_mode'  => 'online',
                    'product_id' => '',
                    'tc_event_id' => '',
                    'sku'        => '',
                ];
            }
            update_post_meta($event_id, '_tix_ticket_categories', $cats);
        }

        // Location
        if (!empty($event_data['location'])) {
            $loc_name = sanitize_text_field($event_data['location']);
            $loc_addr = sanitize_text_field($event_data['location_address'] ?? '');

            // Suche oder erstelle Location
            $locations = get_posts(['post_type' => 'tix_location', 'title' => $loc_name, 'posts_per_page' => 1, 'post_status' => 'any']);
            if (!empty($locations)) {
                $loc_id = $locations[0]->ID;
            } else {
                $loc_id = wp_insert_post(['post_type' => 'tix_location', 'post_title' => $loc_name, 'post_status' => 'publish', 'post_author' => $user_id]);
                if ($loc_id && $loc_addr) {
                    update_post_meta($loc_id, '_tix_loc_address', $loc_addr);
                    // Koordinaten (beim Anlegen stand die Adresse noch nicht fest)
                    if (class_exists('TIX_Venues')) TIX_Venues::maybe_geocode($loc_id);
                }
            }
            if ($loc_id) {
                update_post_meta($event_id, '_tix_location_id', $loc_id);
                update_post_meta($event_id, '_tix_location', $loc_name);
                update_post_meta($event_id, '_tix_address', $loc_addr);
            }
        }

        // Kategorie
        if (!empty($event_data['event_type'])) {
            $term = get_term_by('slug', sanitize_title($event_data['event_type']), 'event_category');
            if (!$term) {
                $new = wp_insert_term(ucfirst($event_data['event_type']), 'event_category');
                if (!is_wp_error($new)) wp_set_object_terms($event_id, [$new['term_id']], 'event_category');
            } else {
                wp_set_object_terms($event_id, [$term->term_id], 'event_category');
            }
        }

        // Breakdance-Meta generieren (Sync)
        if (class_exists('TIX_Sync')) {
            $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
            TIX_Sync::save_breakdance_meta($event_id, is_array($cats) ? $cats : []);
        }

        // Tages-Erinnerungen der App (Datum/Ort stehen erst jetzt fest)
        if (class_exists('TIX_Day_Alerts') && get_post_status($event_id) === 'publish') {
            TIX_Day_Alerts::notify_event($event_id);
        }

        // ── 4. Auto-Login ──
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());

        // ── 5. Erstes Event feiern ──
        update_user_meta($user_id, '_tix_first_event_celebrated', '1');

        // Dashboard-URL
        $slug = tix_get_settings('organizer_slug');
        $dash_url = $slug ? home_url('/' . $slug . '/') : admin_url('admin.php?page=tix-organizer-dashboard');

        if ($review && $org_id && !is_wp_error($org_id)) {
            TIX_Org_Approval::notify_organizer($org_id, 'registered');
            TIX_Org_Approval::notify_admin_new($org_id);
            wp_send_json_success([
                'event_id'      => $event_id,
                'event_url'     => '',
                'dashboard_url' => admin_url('admin.php?page=' . TIX_Org_Approval::ORG_SLUG),
                'review'        => true,
                'heading'       => 'Geschafft! Wir prüfen dein Konto.',
                'message'       => 'Dein Event "' . $event_title . '" ist angelegt und geht online, sobald wir dein Konto freigeschaltet haben. Ergänze bis dahin bitte deine Angaben (Rechnungsadresse, Steuer, Auszahlung, Telefon).',
            ]);
        }

        wp_send_json_success([
            'event_id'      => $event_id,
            'event_url'     => get_permalink($event_id),
            'dashboard_url' => $dash_url,
            'message'       => 'Event "' . esc_html($event_title) . '" wurde veröffentlicht!',
        ]);
    }

    /**
     * „Nur eintragen“: kein Online-Verkauf; Eintritt frei oder Abendkasse (Preis optional).
     */
    public static function apply_listing_mode($event_id, $mode, $price = '', $external_url = '') {
        update_post_meta($event_id, '_tix_tickets_enabled', '0');
        update_post_meta($event_id, '_tix_free_entry', $mode === 'free' ? '1' : '0');
        update_post_meta($event_id, '_tix_box_office_only', $mode === 'box_office' ? '1' : '0');
        $p = trim(str_replace(',', '.', sanitize_text_field((string) $price)));
        $p = ($p !== '' && is_numeric($p)) ? (string) max(0, round(floatval($p), 2)) : '';
        if ($mode === 'box_office') update_post_meta($event_id, '_tix_box_office_price', $p);
        if ($mode === 'external') {
            // Externer Ticketshop (Felder wie im Event-Editor „Externer Shop“): Knopf ersetzt den Ticket-Kauf
            update_post_meta($event_id, '_tix_extshop_enabled', '1');
            update_post_meta($event_id, '_tix_extshop_url', esc_url_raw($external_url));
            update_post_meta($event_id, '_tix_extshop_mode', 'replace');
            if (!get_post_meta($event_id, '_tix_extshop_text', true)) update_post_meta($event_id, '_tix_extshop_text', 'Tickets kaufen');
            update_post_meta($event_id, '_tix_extshop_price', $p);
        } elseif (get_post_meta($event_id, '_tix_extshop_enabled', true) === '1' && get_post_meta($event_id, '_tix_extshop_mode', true) !== 'both') {
            update_post_meta($event_id, '_tix_extshop_enabled', '0'); // von „anderer Shop“ auf frei/Abendkasse gewechselt
        }
    }

    /** Ticket-Modus eines Events: online | free | box_office | '' (keine Angabe, Verkauf aus). */
    public static function ticket_mode_of($event_id) {
        if (get_post_meta($event_id, '_tix_tickets_enabled', true) === '1') return 'online';
        if (get_post_meta($event_id, '_tix_free_entry', true) === '1') return 'free';
        if (get_post_meta($event_id, '_tix_box_office_only', true) === '1') return 'box_office';
        if (get_post_meta($event_id, '_tix_extshop_enabled', true) === '1' && get_post_meta($event_id, '_tix_extshop_url', true) !== '') return 'external';
        return '';
    }

    /**
     * Online verkaufen oder nur eintragen: „free“ = Eintritt frei, „box_office“ = Abendkasse
     * (Preis optional). Nur eintragen schaltet den Online-Verkauf ab (_tix_tickets_enabled = 0).
     */
    public static function apply_ticket_mode($event_id, $mode, $price = '', $external_url = '') {
        if (!in_array($mode, ['online', 'free', 'box_office', 'external'], true)) return new WP_Error('tix_invalid_field', 'Unbekannte Ticket-Art.', ['status' => 400, 'field' => 'ticket_mode']);
        if ($mode === 'online') {
            update_post_meta($event_id, '_tix_tickets_enabled', '1');
            update_post_meta($event_id, '_tix_free_entry', '0');
            update_post_meta($event_id, '_tix_box_office_only', '0');
            if (self::ticket_mode_of($event_id) === 'external' || (get_post_meta($event_id, '_tix_extshop_enabled', true) === '1' && get_post_meta($event_id, '_tix_extshop_mode', true) !== 'both')) {
                update_post_meta($event_id, '_tix_extshop_enabled', '0');
            }
            return true;
        }
        if ($mode === 'external') {
            $external_url = self::clean_shop_url($external_url);
            if ($external_url === '') return new WP_Error('tix_invalid_field', 'Bitte die Adresse des Ticketshops angeben (https://…).', ['status' => 400, 'field' => 'external_shop.url']);
        }
        self::apply_listing_mode($event_id, $mode, $price, $external_url);
        return true;
    }

    /** Ticketshop-Adresse prüfen: nur https mit echtem Host, sonst ''. */
    public static function clean_shop_url($url) {
        $url = trim((string) $url);
        if ($url !== '' && !preg_match('#^[a-z]+://#i', $url)) $url = 'https://' . $url;
        if (!preg_match('#^https://#i', $url)) return '';
        $url = esc_url_raw($url, ['https']);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        return ($host !== '' && strpos($host, '.') !== false && !preg_match('/^(localhost|[0-9.]+)$/', $host)) ? $url : '';
    }

    /**
     * Prüfe ob ein Organizer sein ERSTES Event veröffentlicht
     */
    public static function check_first_event($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        if (!$post || $post->post_type !== 'event') return;

        $user_id = $post->post_author;
        if (!$user_id) return;

        $user = get_user_by('ID', $user_id);
        if (!$user || !in_array('tix_organizer', $user->roles)) return;

        // Bereits gefeiert?
        if (get_user_meta($user_id, '_tix_first_event_celebrated', true)) return;

        // Ist das wirklich das erste Event?
        $count = count(get_posts([
            'post_type'   => 'event',
            'post_status' => 'publish',
            'author'      => $user_id,
            'fields'      => 'ids',
        ]));

        if ($count <= 1) {
            update_user_meta($user_id, '_tix_first_event_celebrated', '1');
            set_transient('tix_fireworks_' . $user_id, $post->ID, 120);
        }
    }

    /**
     * Feuerwerk-Popup im Admin rendern
     */
    public static function render_fireworks_popup() {
        if (!is_user_logged_in()) return;
        $event_id = get_transient('tix_fireworks_' . get_current_user_id());
        if (!$event_id) return;

        delete_transient('tix_fireworks_' . get_current_user_id());
        $event_title = get_the_title($event_id);
        $event_url = get_permalink($event_id);
        ?>
        <div id="tix-fireworks-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;" onclick="this.remove();">
            <div style="background:#fff;border-radius:20px;padding:48px;text-align:center;max-width:420px;position:relative;animation:tixBounceIn 0.5s ease;">
                <div style="font-size:64px;margin-bottom:16px;">🎆</div>
                <h2 style="margin:0 0 8px;font-size:24px;">Glückwunsch!</h2>
                <p style="color:#6b7280;margin:0 0 20px;">Dein erstes Event "<strong><?php echo esc_html($event_title); ?></strong>" ist jetzt live!</p>
                <a href="<?php echo esc_url($event_url); ?>" target="_blank" style="display:inline-block;padding:12px 28px;background:var(--tix-primary, #FF5500);color:#fff;border-radius:10px;text-decoration:none;font-weight:600;">Event ansehen →</a>
            </div>
        </div>
        <style>@keyframes tixBounceIn{0%{transform:scale(0.5);opacity:0}50%{transform:scale(1.05)}100%{transform:scale(1);opacity:1}}</style>
        <?php
    }
}
