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
        wp_localize_script('tix-register-event', 'tixRegister', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tix_register_event'),
            'aiNonce' => wp_create_nonce('tix_admin_action'),
            'aiName'  => $ai_name,
        ]);

        $dark = $atts['mode'] === 'dark';

        ob_start();
        ?>
        <div class="tix-re <?php echo $dark ? 'tix-re-dark' : ''; ?>">

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
                        <span class="tix-re-mode-icon">📝</span>
                        <strong>Beschreiben</strong>
                        <span>In Stichpunkten oder Text</span>
                    </button>
                    <button type="button" class="tix-re-mode" data-mode="upload">
                        <span class="tix-re-mode-icon">📸</span>
                        <strong>Flyer hochladen</strong>
                        <span>Bild analysieren lassen</span>
                    </button>
                    <button type="button" class="tix-re-mode" data-mode="url">
                        <span class="tix-re-mode-icon">🔗</span>
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
                        <span class="tix-re-dropzone-text">📸 Flyer hierher ziehen oder <a href="#" id="tix-re-browse">durchsuchen</a></span>
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
                    </div>
                    <div class="tix-re-nav">
                        <button type="button" class="tix-re-btn-back" data-goto="2">← Zurück</button>
                        <button type="submit" class="tix-re-btn-publish" id="tix-re-publish-btn">
                            <span class="tix-re-btn-text">Event veröffentlichen 🚀</span>
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
                    <div class="tix-re-success-check">🎉</div>
                    <h2>Geschafft! Dein Event ist live.</h2>
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

        if (!$first_name || !$last_name) wp_send_json_error(['message' => 'Bitte Vor- und Nachname angeben.']);
        if (!$email || !is_email($email)) wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
        if (strlen($password) < 8) wp_send_json_error(['message' => 'Passwort muss mindestens 8 Zeichen lang sein.']);
        if ($password !== $password_confirm) wp_send_json_error(['message' => 'Passwörter stimmen nicht überein.']);
        if (!$organizer_name) wp_send_json_error(['message' => 'Bitte einen Veranstalternamen angeben.']);
        if (email_exists($email)) wp_send_json_error(['message' => 'Diese E-Mail-Adresse ist bereits registriert.']);

        // ── 1. User erstellen ──
        $username = sanitize_user(strtolower($first_name . '.' . $last_name));
        if (username_exists($username)) $username .= wp_rand(10, 99);
        if (username_exists($username)) $username .= wp_rand(100, 999);

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
        update_post_meta($event_id, '_tix_tickets_enabled', '1');
        if (!empty($event_data['tickets']) && is_array($event_data['tickets'])) {
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
                if ($loc_id && $loc_addr) update_post_meta($loc_id, '_tix_loc_address', $loc_addr);
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
            TIX_Sync::save_breakdance_meta($event_id, []);
        }

        // ── 4. Auto-Login ──
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());

        // ── 5. Erstes Event feiern ──
        update_user_meta($user_id, '_tix_first_event_celebrated', '1');

        // Dashboard-URL
        $slug = tix_get_settings('organizer_slug');
        $dash_url = $slug ? home_url('/' . $slug . '/') : admin_url('admin.php?page=tix-organizer-dashboard');

        wp_send_json_success([
            'event_id'      => $event_id,
            'event_url'     => get_permalink($event_id),
            'dashboard_url' => $dash_url,
            'message'       => 'Event "' . esc_html($event_title) . '" wurde veröffentlicht!',
        ]);
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
