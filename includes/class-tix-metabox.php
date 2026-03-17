<?php
if (!defined('ABSPATH')) exit;

class TIX_Metabox {

    public static function register() {
        add_meta_box('tix_event_manager', 'Event Manager', [__CLASS__, 'render_all'], 'event', 'normal', 'high');
        add_action('admin_notices', [__CLASS__, 'publish_error_notice']);
    }

    public static function publish_error_notice() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'event' || $screen->base !== 'post') return;
        global $post;
        if (!$post) return;

        // Harte Blockade bei neuem Veröffentlichen
        $missing = get_transient('tix_publish_error_' . $post->ID);
        if ($missing && is_array($missing)) {
            delete_transient('tix_publish_error_' . $post->ID);
            echo '<div class="notice tix-publish-error is-dismissible">';
            echo '<p><strong>Event kann nicht veröffentlicht werden.</strong> Folgende Pflichtfelder fehlen:</p><ul>';
            foreach ($missing as $field) echo '<li>— ' . esc_html($field) . '</li>';
            echo '</ul></div>';
        }

        // Warnung bei bereits veröffentlichten Events
        $warning = get_transient('tix_publish_warning_' . $post->ID);
        if ($warning && is_array($warning)) {
            delete_transient('tix_publish_warning_' . $post->ID);
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Hinweis:</strong> Folgende Pflichtfelder sind nicht ausgefüllt:</p><ul>';
            foreach ($warning as $field) echo '<li>— ' . esc_html($field) . '</li>';
            echo '</ul></div>';
        }
    }

    public static function enqueue_assets($hook) {
        global $post_type, $post;

        // List-CSS für ALLE Tixomat Post-Types laden
        $tix_types = ['event', 'tix_location', 'tix_organizer', 'tix_subscriber', 'tix_abandoned_cart', 'tix_ticket'];
        if (in_array($post_type, $tix_types) && $hook === 'edit.php') {
            wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', ['tix-google-fonts'], TIXOMAT_VERSION);
            // Ticket-Liste: eigenes JS laden
            if ($post_type === 'tix_ticket') {
                wp_enqueue_script('tix-admin-tickets', TIXOMAT_URL . 'assets/js/admin-tickets.js', ['jquery'], TIXOMAT_VERSION, true);
                wp_localize_script('tix-admin-tickets', 'tixTickets', [
                    'ajax'  => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('tix_ticket_action'),
                ]);
                return;
            }
            if ($post_type !== 'event') return;
        }

        // Taxonomy-Seite (event_category)
        if ($hook === 'edit-tags.php' || $hook === 'term.php') {
            $tax = $_GET['taxonomy'] ?? '';
            if ($tax === 'event_category') {
                wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', ['tix-google-fonts'], TIXOMAT_VERSION);
            }
            return;
        }

        if ($post_type !== 'event') return;

        // Event-Liste: nur CSS (schon oben geladen)
        if ($hook === 'edit.php') return;

        if (!in_array($hook, ['post.php', 'post-new.php'])) return;

        if ($post) { wp_enqueue_media(['post' => $post->ID]); } else { wp_enqueue_media(); }

        wp_enqueue_style('dashicons');
        wp_enqueue_style('tix-admin',  TIXOMAT_URL . 'assets/css/admin.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-qr', TIXOMAT_URL . 'assets/js/tix-qr.js', [], TIXOMAT_VERSION, true);

        // Google Places API VOR admin.js laden (damit google global bei Modal-Init verfügbar ist)
        $google_api_key = function_exists('tix_get_settings') ? tix_get_settings('google_api_key') : '';
        $admin_deps = ['jquery', 'jquery-ui-sortable', 'tix-qr'];
        if ($google_api_key) {
            wp_enqueue_script('google-places',
                'https://maps.googleapis.com/maps/api/js?key=' . esc_attr($google_api_key) . '&libraries=places&v=weekly',
                ['jquery'], null, true);
            $admin_deps[] = 'google-places';
        }
        wp_enqueue_script('tix-admin', TIXOMAT_URL . 'assets/js/admin.js', $admin_deps, TIXOMAT_VERSION, true);
        // Event-Kategorien für Kombi-Ticket-Picker vorladen
        $combo_events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'post__not_in'   => $post ? [$post->ID] : [],
            'orderby'        => 'meta_value',
            'meta_key'       => '_tix_date_start',
            'order'          => 'ASC',
        ]);
        $event_cats = [];
        $available_events = [];
        foreach ($combo_events as $ev) {
            $cats = get_post_meta($ev->ID, '_tix_ticket_categories', true);
            if (!is_array($cats)) $cats = [];
            $ec = [];
            foreach ($cats as $ci => $c) {
                if (empty($c['name']) || !empty($c['offline_ticket'])) continue;
                $ec[] = ['index' => $ci, 'name' => $c['name']];
            }
            if (!empty($ec)) $event_cats[$ev->ID] = $ec;

            // Für Kombi-Partner-Picker: Event-Liste mit Titel + Datum
            $date = get_post_meta($ev->ID, '_tix_date_start', true);
            $available_events[] = [
                'id'    => $ev->ID,
                'title' => $ev->post_title,
                'date'  => get_post_meta($ev->ID, '_tix_date_display', true),
                'date_fmt' => $date ? date_i18n('d.m.Y', strtotime($date)) : '',
            ];
        }

        wp_localize_script('tix-admin', 'tixAdmin', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('tix_admin_action'),
            'modalNonce'      => wp_create_nonce('tix_admin_nonce'),
            'eventCategories' => (object) $event_cats,
            'availableEvents' => $available_events,
            'googleApiKey'    => $google_api_key ?: '',
        ]);

        // Ticket-Template Editor (für Metabox)
        wp_enqueue_style('tix-tte-editor', TIXOMAT_URL . 'assets/css/ticket-template-editor.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-tte-editor', TIXOMAT_URL . 'assets/js/ticket-template-editor.js', ['jquery'], TIXOMAT_VERSION, true);

        // Raffle Draw Animation
        wp_enqueue_style('tix-raffle-draw', TIXOMAT_URL . 'assets/css/raffle-draw.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-mp4-muxer', TIXOMAT_URL . 'assets/js/vendor/mp4-muxer.js', [], '5.2.2', true);
        wp_enqueue_script('tix-raffle-draw', TIXOMAT_URL . 'assets/js/raffle-draw.js', ['tix-mp4-muxer'], TIXOMAT_VERSION, true);
    }

    /**
     * Tooltip-Icon mit Hinweistext
     */
    private static function tip($text) {
        echo '<span class="tix-tip" tabindex="0"><span class="tix-tip-icon">?</span><span class="tix-tip-text">' . esc_html($text) . '</span></span>';
    }

    // ──────────────────────────────────────────
    // Tabbed Interface (Main Render)
    // ──────────────────────────────────────────
    public static function render_all($post) {
        wp_nonce_field('tix_save_event', 'tix_nonce');

        $series_parent  = get_post_meta($post->ID, '_tix_series_parent', true);
        $series_enabled = get_post_meta($post->ID, '_tix_series_enabled', true);
        $series_children = get_post_meta($post->ID, '_tix_series_children', true) ?: [];
        ?>
        <div class="tix-app">

            <?php // ── Serien Info-Bar ── ?>
            <?php if ($series_enabled === '1' && !empty($series_children)): ?>
                <div class="tix-series-bar tix-series-master">
                    <span class="dashicons dashicons-backup"></span>
                    <strong>Serientermin (Master)</strong> &mdash; <?php echo count($series_children); ?> Termine generiert
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=event&tix_series_parent=' . $post->ID)); ?>" class="tix-series-link">Alle Termine anzeigen &rarr;</a>
                </div>
            <?php elseif ($series_parent):
                $master = get_post($series_parent);
                if ($master):
                    $siblings = get_post_meta($series_parent, '_tix_series_children', true) ?: [];
                    $pos = array_search($post->ID, $siblings);
                    ?>
                    <div class="tix-series-bar tix-series-child">
                        <span class="dashicons dashicons-backup"></span>
                        Teil einer Serie: <strong><?php echo esc_html($master->post_title); ?></strong>
                        <a href="<?php echo esc_url(get_edit_post_link($series_parent)); ?>" class="tix-series-link">Master bearbeiten</a>
                        <?php if ($pos !== false): ?>
                            <span class="tix-series-nav">
                            <?php if ($pos > 0): ?>
                                <a href="<?php echo esc_url(get_edit_post_link($siblings[$pos - 1])); ?>">&larr; Vorheriger</a>
                            <?php endif; ?>
                            <?php if ($pos < count($siblings) - 1): ?>
                                <a href="<?php echo esc_url(get_edit_post_link($siblings[$pos + 1])); ?>">N&auml;chster &rarr;</a>
                            <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            $wizard_enabled = function_exists('tix_get_settings') ? tix_get_settings('wizard_enabled') : 1;
            ?>

            <?php if ($wizard_enabled): ?>
            <?php // ── Modus-Toggle ── ?>
            <div class="tix-mode-toggle">
                <button type="button" class="tix-mode-btn active" data-mode="wizard">
                    <span class="dashicons dashicons-screenoptions"></span> Geführt
                </button>
                <button type="button" class="tix-mode-btn" data-mode="expert">
                    <span class="dashicons dashicons-admin-tools"></span> Experte
                </button>
            </div>

            <?php // ── WIZARD ── ?>
            <?php self::render_wizard($post); ?>
            <?php endif; ?>

            <?php // ── EXPERTEN-MODUS (bestehend) ── ?>
            <div class="tix-expert" id="tix-expert" <?php echo $wizard_enabled ? 'style="display:none;"' : ''; ?>>
            <div class="tix-progress" id="tix-progress">
                <div class="tix-progress-header">
                    <span class="tix-progress-count"><span id="tix-prog-done">0</span> / <span id="tix-prog-total">6</span> Pflichtfelder</span>
                </div>
                <div class="tix-progress-track">
                    <div class="tix-progress-fill" id="tix-prog-fill" style="width:0%"></div>
                </div>
                <div class="tix-progress-items" id="tix-prog-items"></div>
            </div>
            <nav class="tix-nav">
                <button type="button" class="tix-nav-tab active" data-tab="details">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <span class="tix-nav-label">Details</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="info">
                    <span class="dashicons dashicons-info"></span>
                    <span class="tix-nav-label">Info</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="tickets">
                    <span class="dashicons dashicons-tickets-alt"></span>
                    <span class="tix-nav-label">Tickets</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="template">
                    <span class="dashicons dashicons-media-document"></span>
                    <span class="tix-nav-label">Vorlage</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="media">
                    <span class="dashicons dashicons-format-gallery"></span>
                    <span class="tix-nav-label">Medien</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="faq">
                    <span class="dashicons dashicons-editor-help"></span>
                    <span class="tix-nav-label">FAQ</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="upsell">
                    <span class="dashicons dashicons-megaphone"></span>
                    <span class="tix-nav-label">Zusatzprodukte</span>
                </button>
                <?php if (function_exists('tix_get_settings') && tix_get_settings('specials_enabled')): ?>
                <button type="button" class="tix-nav-tab" data-tab="specials">
                    <span class="dashicons dashicons-star-filled"></span>
                    <span class="tix-nav-label">Specials</span>
                </button>
                <?php endif; ?>
                <button type="button" class="tix-nav-tab" data-tab="series">
                    <span class="dashicons dashicons-backup"></span>
                    <span class="tix-nav-label">Serientermine</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="guestlist">
                    <span class="dashicons dashicons-groups"></span>
                    <span class="tix-nav-label">Gästeliste</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="discounts">
                    <span class="dashicons dashicons-tag"></span>
                    <span class="tix-nav-label">Rabattcodes</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="raffle">
                    <span class="dashicons dashicons-tickets"></span>
                    <span class="tix-nav-label">Gewinnspiel</span>
                </button>
                <button type="button" class="tix-nav-tab" data-tab="timetable">
                    <span class="dashicons dashicons-schedule"></span>
                    <span class="tix-nav-label">Programm</span>
                </button>
                <?php if (function_exists('tix_get_settings') && tix_get_settings('table_reservation_enabled')): ?>
                <button type="button" class="tix-nav-tab" data-tab="tables">
                    <span class="dashicons dashicons-food"></span>
                    <span class="tix-nav-label">Tische</span>
                </button>
                <?php endif; ?>
                <?php if (function_exists('tix_get_settings') && tix_get_settings('campaign_tracking_enabled')): ?>
                <button type="button" class="tix-nav-tab" data-tab="campaigns">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <span class="tix-nav-label">Kampagnen</span>
                </button>
                <?php endif; ?>
                <?php if (function_exists('tix_get_settings') && tix_get_settings('promoter_enabled') && class_exists('TIX_Promoter_Admin')): ?>
                <button type="button" class="tix-nav-tab" data-tab="promoter">
                    <span class="dashicons dashicons-businessman"></span>
                    <span class="tix-nav-label">Promoter</span>
                </button>
                <?php endif; ?>
                <?php if (function_exists('tix_get_settings') && (tix_get_settings('abandoned_cart_enabled') || tix_get_settings('express_checkout_enabled') || tix_get_settings('ticket_transfer_enabled') || tix_get_settings('barcode_enabled') || tix_get_settings('charity_enabled') || class_exists('TIX_Seatmap'))): ?>
                <button type="button" class="tix-nav-tab" data-tab="advanced">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <span class="tix-nav-label">Erweitert</span>
                </button>
                <?php endif; ?>
            </nav>
            <div class="tix-content">
                <div class="tix-pane active" data-pane="details">
                    <?php self::render_details($post); ?>
                </div>
                <div class="tix-pane" data-pane="info">
                    <?php self::render_info($post); ?>
                </div>
                <div class="tix-pane" data-pane="tickets">
                    <?php self::render_tickets($post); ?>
                </div>
                <div class="tix-pane" data-pane="template">
                    <?php self::render_template($post); ?>
                </div>
                <div class="tix-pane" data-pane="media">
                    <?php self::render_media($post); ?>
                </div>
                <div class="tix-pane" data-pane="faq">
                    <?php self::render_faq($post); ?>
                </div>
                <div class="tix-pane" data-pane="upsell">
                    <?php self::render_upsell($post); ?>
                </div>
                <?php if (function_exists('tix_get_settings') && tix_get_settings('specials_enabled') && class_exists('TIX_Specials')): ?>
                <div class="tix-pane" data-pane="specials">
                    <?php TIX_Specials::render_metabox($post); ?>
                </div>
                <?php endif; ?>
                <div class="tix-pane" data-pane="series">
                    <?php self::render_series($post); ?>
                </div>
                <div class="tix-pane" data-pane="guestlist">
                    <?php self::render_guestlist($post); ?>
                </div>
                <div class="tix-pane" data-pane="discounts">
                    <?php self::render_discounts($post); ?>
                </div>
                <div class="tix-pane" data-pane="raffle">
                    <?php self::render_raffle($post); ?>
                </div>
                <div class="tix-pane" data-pane="timetable">
                    <?php self::render_timetable($post); ?>
                </div>
                <?php if (function_exists('tix_get_settings') && tix_get_settings('table_reservation_enabled') && class_exists('TIX_Table_Reservation')): ?>
                <div class="tix-pane" data-pane="tables">
                    <?php TIX_Table_Reservation::render_metabox($post); ?>
                </div>
                <?php endif; ?>
                <?php if (function_exists('tix_get_settings') && tix_get_settings('campaign_tracking_enabled')): ?>
                <div class="tix-pane" data-pane="campaigns">
                    <?php self::render_campaign_links($post); ?>
                </div>
                <?php endif; ?>
                <?php if (function_exists('tix_get_settings') && tix_get_settings('promoter_enabled') && class_exists('TIX_Promoter_Admin')): ?>
                <div class="tix-pane" data-pane="promoter">
                    <?php TIX_Promoter_Admin::render_event_tab($post); ?>
                </div>
                <?php endif; ?>
                <?php if (function_exists('tix_get_settings') && (tix_get_settings('abandoned_cart_enabled') || tix_get_settings('express_checkout_enabled') || tix_get_settings('ticket_transfer_enabled') || tix_get_settings('barcode_enabled') || tix_get_settings('charity_enabled') || class_exists('TIX_Seatmap'))): ?>
                <div class="tix-pane" data-pane="advanced">
                    <?php self::render_advanced($post); ?>
                </div>
                <?php endif; ?>
            </div>
            </div><?php // /.tix-expert ?>
        </div>

        <?php // ── Ticket-Template-Editor Init + Radio-Toggle ── ?>
        <script>
        jQuery(function($) {
            // Preview-Daten für Placeholder
            window.tixPreviewData = <?php echo wp_json_encode(TIX_Ticket_Template::preview_data()); ?>;

            // Radio-Toggle: Custom-Editor / Template-Dropdown ein/ausblenden
            $('input[name="tix_ticket_template_mode"]').on('change', function() {
                var mode = $(this).val();
                // Template-Dropdown
                if (mode === 'template') {
                    $('#tix-template-select-wrap').show();
                } else {
                    $('#tix-template-select-wrap').hide();
                }
                // Custom-Editor
                if (mode === 'custom') {
                    $('#tix-tte-metabox-editor-wrap').show();
                    // Editor initialisieren wenn noch nicht geschehen
                    if (typeof TIX_TemplateEditor !== 'undefined' && !$('#tix-tte-metabox-editor-wrap').data('tix-tte-init')) {
                        new TIX_TemplateEditor('#tix-tte-metabox-editor-wrap', {
                            inputSelector: '#tix-tte-metabox-input',
                            nonce: '<?php echo wp_create_nonce('tix_template_preview'); ?>',
                            ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
                            fieldDefs: <?php echo wp_json_encode(array_map(function($d) { return ['label' => $d['label'], 'type' => $d['type']]; }, TIX_Ticket_Template::field_definitions())); ?>
                        });
                        $('#tix-tte-metabox-editor-wrap').data('tix-tte-init', true);
                    }
                } else {
                    $('#tix-tte-metabox-editor-wrap').hide();
                }
            });
            // Beim Laden: aktuellen Modus triggern
            var currentMode = $('input[name="tix_ticket_template_mode"]:checked').val();
            if (currentMode === 'custom' || currentMode === 'template') {
                $('input[name="tix_ticket_template_mode"]:checked').trigger('change');
            }
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────
    // Geführter Modus (Wizard)
    // ──────────────────────────────────────────
    public static function render_wizard($post) {

        $date_start  = get_post_meta($post->ID, '_tix_date_start', true);
        $date_end    = get_post_meta($post->ID, '_tix_date_end', true);
        $time_start  = get_post_meta($post->ID, '_tix_time_start', true);
        $time_end    = get_post_meta($post->ID, '_tix_time_end', true);
        $time_doors  = get_post_meta($post->ID, '_tix_time_doors', true);
        $location_id  = intval(get_post_meta($post->ID, '_tix_location_id', true));
        $organizer_id = intval(get_post_meta($post->ID, '_tix_organizer_id', true));
        $description  = get_post_meta($post->ID, '_tix_info_description', true);
        $categories   = get_post_meta($post->ID, '_tix_ticket_categories', true);
        if (!is_array($categories) || empty($categories)) $categories = [];

        $locations  = get_posts(['post_type' => 'tix_location',  'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish']);
        $organizers = get_posts(['post_type' => 'tix_organizer', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish']);
        ?>
        <div class="tix-wizard" id="tix-wizard">

            <?php // ── Step Indicator ── ?>
            <div class="tix-wiz-steps">
                <div class="tix-wiz-step active" data-step="1"><span class="tix-wiz-num">1</span><span class="tix-wiz-label">Grunddaten</span></div>
                <div class="tix-wiz-step" data-step="2"><span class="tix-wiz-num">2</span><span class="tix-wiz-label">Ort</span></div>
                <div class="tix-wiz-step" data-step="3"><span class="tix-wiz-num">3</span><span class="tix-wiz-label">Tickets</span></div>
                <div class="tix-wiz-step" data-step="4"><span class="tix-wiz-num">4</span><span class="tix-wiz-label">Details</span></div>
                <div class="tix-wiz-step" data-step="5"><span class="tix-wiz-num">5</span><span class="tix-wiz-label">Fertig</span></div>
            </div>

            <?php // ── Step 1: Grunddaten ── ?>
            <div class="tix-wiz-pane active" data-wstep="1">
                <h3 class="tix-wiz-title">Wann findet dein Event statt?</h3>
                <p class="tix-wiz-desc">Gib die wichtigsten Zeitdaten ein.</p>
                <div class="tix-wiz-fields">
                    <div class="tix-wiz-field tix-wiz-field-full">
                        <label>Event-Titel <span class="tix-req">*</span></label>
                        <input type="text" id="wiz-title" value="<?php echo esc_attr($post->post_title !== 'Automatischer Entwurf' ? $post->post_title : ''); ?>" placeholder="z.B. Sommerparty 2026" class="tix-wiz-input-lg">
                    </div>
                    <div class="tix-wiz-field">
                        <label>Startdatum <span class="tix-req">*</span></label>
                        <input type="date" id="wiz-date-start" value="<?php echo esc_attr($date_start); ?>" class="tix-wiz-input">
                    </div>
                    <div class="tix-wiz-field">
                        <label>Startzeit <span class="tix-req">*</span></label>
                        <input type="time" id="wiz-time-start" value="<?php echo esc_attr($time_start); ?>" class="tix-wiz-input">
                    </div>
                    <div class="tix-wiz-field">
                        <label>Enddatum</label>
                        <input type="date" id="wiz-date-end" value="<?php echo esc_attr($date_end); ?>" class="tix-wiz-input">
                    </div>
                    <div class="tix-wiz-field">
                        <label>Endzeit</label>
                        <input type="time" id="wiz-time-end" value="<?php echo esc_attr($time_end); ?>" class="tix-wiz-input">
                    </div>
                    <div class="tix-wiz-field">
                        <label>Einlass <span class="tix-wiz-opt">(optional)</span></label>
                        <input type="time" id="wiz-time-doors" value="<?php echo esc_attr($time_doors); ?>" placeholder="Leer = gleich wie Start" class="tix-wiz-input">
                    </div>
                </div>
            </div>

            <?php // ── Step 2: Ort ── ?>
            <div class="tix-wiz-pane" data-wstep="2">
                <h3 class="tix-wiz-title">Wo findet es statt?</h3>
                <p class="tix-wiz-desc">Wähle den Veranstaltungsort.</p>
                <div class="tix-wiz-fields">
                    <div class="tix-wiz-field tix-wiz-field-full">
                        <label>Veranstaltungsort <span class="tix-req">*</span></label>
                        <select id="wiz-location" class="tix-wiz-input">
                            <option value="">— Location wählen —</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo $loc->ID; ?>" data-address="<?php echo esc_attr(get_post_meta($loc->ID, '_tix_loc_address', true)); ?>" <?php selected($location_id, $loc->ID); ?>><?php echo esc_html($loc->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="tix-add-new-link" data-modal="tix-modal-location">+ Neue Location</button>
                    </div>
                    <div class="tix-wiz-field tix-wiz-field-full">
                        <label>Adresse</label>
                        <input type="text" id="wiz-address" value="<?php echo $location_id ? esc_attr(get_post_meta($location_id, '_tix_loc_address', true)) : ''; ?>" readonly class="tix-wiz-input tix-readonly-field" placeholder="Wird automatisch aus der Location geladen">
                    </div>
                    <div class="tix-wiz-field tix-wiz-field-full">
                        <label>Veranstalter <span class="tix-wiz-opt">(optional)</span></label>
                        <select id="wiz-organizer" class="tix-wiz-input">
                            <option value="">— Veranstalter wählen —</option>
                            <?php foreach ($organizers as $org): ?>
                                <option value="<?php echo $org->ID; ?>" <?php selected($organizer_id, $org->ID); ?>><?php echo esc_html($org->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="tix-add-new-link" data-modal="tix-modal-organizer">+ Neuer Veranstalter</button>
                    </div>
                </div>
            </div>

            <?php // ── Step 3: Tickets ── ?>
            <div class="tix-wiz-pane" data-wstep="3">
                <h3 class="tix-wiz-title">Welche Tickets willst du verkaufen?</h3>
                <p class="tix-wiz-desc">Lege deine Ticketkategorien mit Name, Preis und Kapazität an. Alle Tickets werden automatisch online im Vorverkauf angeboten.</p>
                <div class="tix-wiz-tickets">
                    <table class="tix-wiz-ticket-table" id="wiz-ticket-table">
                        <thead>
                            <tr>
                                <th style="width:40%">Kategorie <span class="tix-req">*</span></th>
                                <th style="width:20%">Preis (€) <span class="tix-req">*</span></th>
                                <th style="width:20%" title="Leer = unbegrenzt">Kapazität</th>
                                <th style="width:20%">Beschreibung</th>
                                <th style="width:36px"></th>
                            </tr>
                        </thead>
                        <tbody id="wiz-ticket-rows">
                            <?php if (empty($categories)): ?>
                            <tr class="tix-wiz-trow">
                                <td><input type="text" class="wiz-tk-name" placeholder="z.B. Standard" value=""></td>
                                <td><input type="number" class="wiz-tk-price" step="0.01" min="0" placeholder="29.90" value=""></td>
                                <td><input type="number" class="wiz-tk-qty" min="0" placeholder="∞" value=""></td>
                                <td><input type="text" class="wiz-tk-desc" placeholder="Optional" value=""></td>
                                <td><button type="button" class="button tix-wiz-tk-del" title="Entfernen">&times;</button></td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($categories as $cat): ?>
                                <tr class="tix-wiz-trow">
                                    <td><input type="text" class="wiz-tk-name" value="<?php echo esc_attr($cat['name'] ?? ''); ?>"></td>
                                    <td><input type="number" class="wiz-tk-price" step="0.01" min="0" value="<?php echo esc_attr($cat['price'] ?? ''); ?>"></td>
                                    <td><input type="number" class="wiz-tk-qty" min="0" placeholder="∞" value="<?php echo esc_attr($cat['qty'] ?? ''); ?>"></td>
                                    <td><input type="text" class="wiz-tk-desc" value="<?php echo esc_attr($cat['desc'] ?? ''); ?>"></td>
                                    <td><button type="button" class="button tix-wiz-tk-del" title="Entfernen">&times;</button></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <button type="button" class="button" id="wiz-add-ticket">+ Kategorie hinzufügen</button>
                </div>
                <p class="tix-wiz-hint">Vorverkauf startet sofort nach Veröffentlichung. Weitere Optionen (Phasenpreise, Pakete, Abendkasse) findest du im Experten-Modus.</p>
            </div>

            <?php // ── Step 4: Details (optional) ── ?>
            <div class="tix-wiz-pane" data-wstep="4">
                <h3 class="tix-wiz-title">Beschreibe dein Event <span class="tix-wiz-opt">(optional)</span></h3>
                <p class="tix-wiz-desc">Gib eine kurze Beschreibung ein. Du kannst alles auch später im Experten-Modus ergänzen (Bilder, FAQ, Line-Up etc.).</p>
                <div class="tix-wiz-fields">
                    <div class="tix-wiz-field tix-wiz-field-full">
                        <label>Beschreibung</label>
                        <textarea id="wiz-description" rows="6" class="tix-wiz-input" placeholder="Worum geht es bei deinem Event?"><?php echo esc_textarea($description); ?></textarea>
                    </div>
                </div>
                <p class="tix-wiz-hint">Beitragsbild und weitere Medien kannst du über den Experten-Modus hinzufügen.</p>
            </div>

            <?php // ── Step 5: Zusammenfassung ── ?>
            <div class="tix-wiz-pane" data-wstep="5">
                <h3 class="tix-wiz-title">Dein Event im Überblick</h3>
                <p class="tix-wiz-desc">Prüfe die Angaben und veröffentliche dein Event.</p>
                <div class="tix-wiz-summary" id="wiz-summary">
                    <?php // wird dynamisch per JS befüllt ?>
                </div>
                <div class="tix-wiz-publish">
                    <button type="button" class="button button-primary button-hero tix-wiz-publish-btn" id="wiz-publish">Event veröffentlichen</button>
                    <button type="button" class="button tix-wiz-draft-btn" id="wiz-draft">Als Entwurf speichern</button>
                </div>
            </div>

            <?php // ── Wizard Navigation ── ?>
            <div class="tix-wiz-nav">
                <button type="button" class="button tix-wiz-prev" id="wiz-prev" style="display:none">&larr; Zurück</button>
                <div class="tix-wiz-nav-spacer"></div>
                <button type="button" class="button button-primary tix-wiz-next" id="wiz-next">Weiter &rarr;</button>
            </div>
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Event Details
    // ──────────────────────────────────────────
    public static function render_details($post) {

        $date_start = get_post_meta($post->ID, '_tix_date_start', true);
        $date_end   = get_post_meta($post->ID, '_tix_date_end', true);
        $time_start = get_post_meta($post->ID, '_tix_time_start', true);
        $time_end   = get_post_meta($post->ID, '_tix_time_end', true);
        $time_doors = get_post_meta($post->ID, '_tix_time_doors', true);
        $location_id = intval(get_post_meta($post->ID, '_tix_location_id', true));
        $organizer_id = intval(get_post_meta($post->ID, '_tix_organizer_id', true));

        // CPT-Listen laden
        $locations = get_posts(['post_type' => 'tix_location', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish']);
        $organizers = get_posts(['post_type' => 'tix_organizer', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish']);
        ?>
        <p class="tix-req-hint">* = Pflichtfeld</p>
        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-edit"></span>
                <h3>Event-Titel</h3>
            </div>
            <div class="tix-card-body">
                <div class="tix-field-grid">
                    <div class="tix-field tix-field-full">
                        <label class="tix-field-label" for="tix-expert-title">Titel <span class="tix-req">*</span></label>
                        <input type="text" id="tix-expert-title" value="<?php echo esc_attr($post->post_title !== 'Automatischer Entwurf' ? $post->post_title : ''); ?>" placeholder="z.B. Sommerparty 2026" class="tix-wiz-input-lg" data-tix-required="Titel">
                    </div>
                </div>
            </div>
        </div>

        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-calendar-alt"></span>
                <h3>Zeitraum</h3>
                <?php self::tip('Wann beginnt und endet das Event? Bei eintägigen Events gleiches Datum für Start und Ende verwenden.'); ?>
            </div>
            <div class="tix-card-body">
                <div class="tix-field-grid">
                    <div class="tix-field">
                        <label class="tix-field-label">Start <span class="tix-req">*</span></label>
                        <div class="tix-field-inline">
                            <input type="date" name="tix_date_start" value="<?php echo esc_attr($date_start); ?>" data-tix-required="Startdatum">
                            <input type="time" name="tix_time_start" value="<?php echo esc_attr($time_start); ?>" data-tix-required="Startzeit">
                        </div>
                    </div>
                    <div class="tix-field">
                        <label class="tix-field-label">Ende</label>
                        <div class="tix-field-inline">
                            <input type="date" name="tix_date_end" value="<?php echo esc_attr($date_end); ?>">
                            <input type="time" name="tix_time_end" value="<?php echo esc_attr($time_end); ?>">
                        </div>
                    </div>
                    <div class="tix-field">
                        <label class="tix-field-label">Einlass <?php self::tip('Ab wann dürfen Gäste rein? Leer lassen wenn gleich wie Startzeit.'); ?></label>
                        <input type="time" id="tix_time_doors" name="tix_time_doors" value="<?php echo esc_attr($time_doors); ?>" placeholder="Optional">
                    </div>
                </div>
            </div>
        </div>

        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-location"></span>
                <h3>Ort & Veranstalter</h3>
            </div>
            <div class="tix-card-body">
                <div class="tix-field-grid">
                    <div class="tix-field">
                        <label class="tix-field-label" for="tix_location_id">Veranstaltungsort <span class="tix-req">*</span> <?php self::tip('Wähle eine bereits angelegte Location. Neue Locations kannst du unter Events → Locations anlegen.'); ?></label>
                        <select id="tix_location_id" name="tix_location_id" data-tix-required="Location" class="tix-cpt-select">
                            <option value="">— Location wählen —</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo $loc->ID; ?>" data-address="<?php echo esc_attr(get_post_meta($loc->ID, '_tix_loc_address', true)); ?>" <?php selected($location_id, $loc->ID); ?>><?php echo esc_html($loc->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="tix-add-new-link" data-modal="tix-modal-location">+ Neue Location</button>
                    </div>
                    <div class="tix-field">
                        <label class="tix-field-label" for="tix_location_address_display">Adresse</label>
                        <input type="text" id="tix_location_address_display" value="<?php echo $location_id ? esc_attr(get_post_meta($location_id, '_tix_loc_address', true)) : ''; ?>" readonly class="tix-readonly-field" placeholder="Wird automatisch aus der Location geladen">
                    </div>
                    <div class="tix-field tix-field-full">
                        <label class="tix-field-label" for="tix_organizer_id">Veranstalter <?php self::tip('Wer veranstaltet das Event? Neue Veranstalter unter Events → Veranstalter anlegen.'); ?></label>
                        <select id="tix_organizer_id" name="tix_organizer_id" class="tix-cpt-select">
                            <option value="">— Veranstalter wählen —</option>
                            <?php foreach ($organizers as $org): ?>
                                <option value="<?php echo $org->ID; ?>" <?php selected($organizer_id, $org->ID); ?>><?php echo esc_html($org->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="tix-add-new-link" data-modal="tix-modal-organizer">+ Neuer Veranstalter</button>
                    </div>
                </div>
            </div>
        </div>

        <?php // ── Modals: Inline-Erstellung Location & Veranstalter ── ?>
        <div id="tix-modal-location" class="tix-modal-overlay" style="display:none">
            <div class="tix-modal">
                <div class="tix-modal-header">
                    <h3><span class="dashicons dashicons-location"></span> Neue Location</h3>
                    <button type="button" class="tix-modal-close">&times;</button>
                </div>
                <div class="tix-modal-body">
                    <div class="tix-field">
                        <label class="tix-field-label">Name <span class="tix-req">*</span></label>
                        <input type="text" id="tix-modal-loc-name" placeholder="z.B. Kölner Philharmonie">
                    </div>
                    <div class="tix-field">
                        <label class="tix-field-label">Adresse</label>
                        <input type="text" id="tix-modal-loc-address" placeholder="Adresse eingeben…" class="tix-places-autocomplete">
                    </div>
                    <div class="tix-field">
                        <label class="tix-field-label">Beschreibung</label>
                        <textarea id="tix-modal-loc-desc" rows="3" placeholder="Optionale Beschreibung"></textarea>
                    </div>
                    <div class="tix-modal-error" style="display:none"></div>
                </div>
                <div class="tix-modal-footer">
                    <button type="button" class="button tix-modal-cancel">Abbrechen</button>
                    <button type="button" class="button button-primary tix-modal-save" data-type="location"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px"></span> Location erstellen</button>
                </div>
            </div>
        </div>

        <div id="tix-modal-organizer" class="tix-modal-overlay" style="display:none">
            <div class="tix-modal">
                <div class="tix-modal-header">
                    <h3><span class="dashicons dashicons-id-alt"></span> Neuer Veranstalter</h3>
                    <button type="button" class="tix-modal-close">&times;</button>
                </div>
                <div class="tix-modal-body">
                    <div class="tix-field">
                        <label class="tix-field-label">Name <span class="tix-req">*</span></label>
                        <input type="text" id="tix-modal-org-name" placeholder="z.B. MDJ Veranstaltungs UG">
                    </div>
                    <div class="tix-field">
                        <label class="tix-field-label">Adresse</label>
                        <input type="text" id="tix-modal-org-address" placeholder="Adresse eingeben…" class="tix-places-autocomplete">
                    </div>
                    <div class="tix-field">
                        <label class="tix-field-label">Beschreibung</label>
                        <textarea id="tix-modal-org-desc" rows="3" placeholder="Optionale Beschreibung"></textarea>
                    </div>
                    <div class="tix-modal-error" style="display:none"></div>
                </div>
                <div class="tix-modal-footer">
                    <button type="button" class="button tix-modal-cancel">Abbrechen</button>
                    <button type="button" class="button button-primary tix-modal-save" data-type="organizer"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px"></span> Veranstalter erstellen</button>
                </div>
            </div>
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Event Informationen (frei benennbare Sektionen)
    // ──────────────────────────────────────────

    /**
     * Standardmäßige Info-Sektionen
     */
    public static function info_sections() {
        return [
            'description' => ['label' => 'Beschreibung',           'type' => 'textarea'],
            'lineup'      => ['label' => 'Line-Up',                'type' => 'textarea'],
            'specials'    => ['label' => 'Specials',               'type' => 'textarea'],
            'age_limit'   => ['label' => 'Altersbegrenzung',       'type' => 'number'],
            'extra_info'  => ['label' => 'Weitere Informationen',  'type' => 'textarea'],
        ];
    }

    public static function render_info($post) {
        $sections = self::info_sections();
        ?>
        <p class="description" style="margin-bottom:12px;">
            Klicke auf die graue Überschrift um sie umzubenennen. Leere Felder werden im Frontend nicht angezeigt.
        </p>
        <div class="tix-info-sections">
        <?php foreach ($sections as $key => $def):
            $label   = get_post_meta($post->ID, "_tix_info_{$key}_label", true) ?: $def['label'];
            $content = get_post_meta($post->ID, "_tix_info_{$key}", true);
            ?>
            <div class="tix-info-section">
                <div class="tix-info-header">
                    <input type="text" name="tix_info_labels[<?php echo $key; ?>]"
                           value="<?php echo esc_attr($label); ?>"
                           class="tix-info-label-input"
                           placeholder="<?php echo esc_attr($def['label']); ?>">
                </div>
                <?php if ($def['type'] === 'textarea'): ?>
                    <div class="tix-info-editor">
                    <?php wp_editor($content ?: '', 'tix_info_' . $key, [
                        'textarea_name' => "tix_info[$key]",
                        'media_buttons' => false,
                        'textarea_rows' => 5,
                        'teeny'         => true,
                        'quicktags'     => ['buttons' => 'strong,em,link,ul,ol,li'],
                    ]); ?>
                    </div>
                <?php elseif ($def['type'] === 'number'): ?>
                    <div class="tix-info-number-wrap">
                        <input type="number" name="tix_info[<?php echo $key; ?>]"
                               value="<?php echo esc_attr($content); ?>"
                               min="0" max="99"
                               class="tix-info-number"
                               placeholder="z.B. 18">
                        <span class="description">Jahre (leer = keine Begrenzung)</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // FAQ (Repeater)
    // ──────────────────────────────────────────
    public static function render_faq($post) {
        $faqs = get_post_meta($post->ID, '_tix_faq', true);
        if (!is_array($faqs) || empty($faqs)) $faqs = [];
        ?>
        <p class="description" style="margin-bottom:10px;">
            Häufig gestellte Fragen zum Event. Wird im Frontend als aufklappbares Accordion angezeigt.
            <?php self::tip('Nutze den Shortcode [tix_faq] in Breakdance um die FAQs auf der Event-Seite anzuzeigen. Reihenfolge ändern: Zeile am ☰ Symbol ziehen.'); ?>
        </p>
        <table class="widefat tix-tbl tix-faq-tbl" id="tix-faq-table">
            <thead>
                <tr>
                    <th style="width:3%"></th>
                    <th style="width:35%">Frage</th>
                    <th style="width:55%">Antwort</th>
                    <th style="width:7%"></th>
                </tr>
            </thead>
            <tbody id="tix-faq-rows">
                <?php if (!empty($faqs)):
                    foreach ($faqs as $i => $faq): ?>
                        <tr class="tix-faq-row" draggable="true">
                            <td class="tix-faq-drag" title="Reihenfolge ändern">☰</td>
                            <td>
                                <input type="text" name="tix_faq[<?php echo $i; ?>][q]"
                                       value="<?php echo esc_attr($faq['q'] ?? ''); ?>"
                                       placeholder="Frage eingeben…" style="width:100%">
                            </td>
                            <td>
                                <textarea name="tix_faq[<?php echo $i; ?>][a]"
                                          rows="2" placeholder="Antwort eingeben…"
                                          style="width:100%"><?php echo esc_textarea($faq['a'] ?? ''); ?></textarea>
                            </td>
                            <td>
                                <button type="button" class="button tix-faq-del" title="Entfernen">&times;</button>
                            </td>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr class="tix-faq-empty"><td colspan="4" style="text-align:center;color:#999;padding:16px;">Noch keine FAQs. Klicke unten auf „+ Frage hinzufügen".</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:10px;">
            <button type="button" class="button" id="tix-faq-add">+ Frage hinzufügen</button>
        </p>
        <?php
    }

    // ──────────────────────────────────────────
    // Upselling
    // ──────────────────────────────────────────
    public static function render_upsell($post) {
        $disabled     = get_post_meta($post->ID, '_tix_upsell_disabled', true);
        $selected_ids = get_post_meta($post->ID, '_tix_upsell_events', true);
        if (!is_array($selected_ids)) $selected_ids = [];

        // Alle publizierten Events (außer dem aktuellen) für den Dropdown laden
        $all_events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'post__not_in'   => [$post->ID],
            'orderby'        => 'meta_value',
            'meta_key'       => '_tix_date_start',
            'order'          => 'ASC',
        ]);

        // Ausgewählte Events mit Titel/Datum
        $selected_events = [];
        foreach ($selected_ids as $sid) {
            $sp = get_post($sid);
            if ($sp && $sp->post_type === 'event') {
                $selected_events[] = $sp;
            }
        }
        ?>
        <div class="tix-upsell-meta">
            <p>
                <label>
                    <input type="checkbox" name="tix_upsell_disabled" value="1" <?php checked($disabled, '1'); ?> id="tix-upsell-disabled">
                    <strong>Zusatzprodukte für dieses Event deaktivieren</strong>
                </label>
            </p>
            <div id="tix-upsell-options"<?php echo $disabled ? ' style="opacity:0.35;pointer-events:none;"' : ''; ?>>
            <p class="description" style="margin:0 0 10px;">
                Wähle Events, die als Empfehlung auf der Event-Seite und der Danke-Seite angezeigt werden.<br>
                <strong>Leer lassen</strong> = automatisch verwandte/kommende Events.
            </p>

            <?php // Ausgewählte Events als sortierbare Tags ?>
            <div class="tix-upsell-selected" id="tix-upsell-selected">
                <?php foreach ($selected_events as $ev): ?>
                    <div class="tix-upsell-tag" data-id="<?php echo $ev->ID; ?>">
                        <input type="hidden" name="tix_upsell_events[]" value="<?php echo $ev->ID; ?>">
                        <span class="tix-upsell-tag-title"><?php echo esc_html($ev->post_title); ?></span>
                        <span class="tix-upsell-tag-date"><?php echo esc_html(get_post_meta($ev->ID, '_tix_date_display', true)); ?></span>
                        <button type="button" class="tix-upsell-tag-remove" title="Entfernen">&times;</button>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // Event hinzufügen ?>
            <div class="tix-upsell-add">
                <select id="tix-upsell-select" class="regular-text">
                    <option value="">Event hinzufügen…</option>
                    <?php foreach ($all_events as $ev):
                        $date = get_post_meta($ev->ID, '_tix_date_start', true);
                        $date_fmt = $date ? date_i18n('d.m.Y', strtotime($date)) : '';
                        $already = in_array($ev->ID, $selected_ids);
                        ?>
                        <option value="<?php echo $ev->ID; ?>"
                                data-title="<?php echo esc_attr($ev->post_title); ?>"
                                data-date="<?php echo esc_attr(get_post_meta($ev->ID, '_tix_date_display', true)); ?>"
                                <?php echo $already ? 'disabled' : ''; ?>>
                            <?php echo esc_html($ev->post_title); ?><?php echo $date_fmt ? " ({$date_fmt})" : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            </div>
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Kombi-Tickets
    // ──────────────────────────────────────────
    private static function render_combos($post, $categories) {
        $combos = get_post_meta($post->ID, '_tix_combo_deals', true);
        if (!is_array($combos)) $combos = [];

        // Eigene Kategorien für Dropdown
        $self_cats = [];
        if (is_array($categories)) {
            foreach ($categories as $ci => $c) {
                if (empty($c['name']) || !empty($c['offline_ticket'])) continue;
                $self_cats[] = ['index' => $ci, 'name' => $c['name']];
            }
        }

        // Alle Events für Partner-Picker
        $all_events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'post__not_in'   => [$post->ID],
            'orderby'        => 'meta_value',
            'meta_key'       => '_tix_date_start',
            'order'          => 'ASC',
        ]);
        ?>
        <div class="tix-combo-wrap" style="margin-top:20px; padding-top:16px; border-top:1px solid #ddd;">
            <div class="tix-toggle-wrap">
                <label class="tix-toggle-label">
                    <span class="tix-toggle-text">🎫 Kombi-Tickets</span>
                </label>
                <?php self::tip('Erstelle Kombi-Angebote mit anderen Events. Kunden kaufen ein Bundle und erhalten Tickets für alle enthaltenen Events zum Kombipreis.'); ?>
            </div>

            <div id="tix-combo-deals">
                <?php foreach ($combos as $ci => $combo): ?>
                <div class="tix-combo-deal" data-combo-index="<?php echo $ci; ?>">
                    <input type="hidden" name="tix_combos[<?php echo $ci; ?>][id]" value="<?php echo esc_attr($combo['id'] ?? ''); ?>">
                    <div class="tix-combo-deal-header">
                        <strong>Kombi #<?php echo $ci + 1; ?></strong>
                        <button type="button" class="button tix-combo-remove" title="Kombi entfernen">&times;</button>
                    </div>
                    <div class="tix-combo-deal-fields">
                        <div class="tix-combo-row">
                            <label class="tix-combo-label">Bezeichnung</label>
                            <input type="text" name="tix_combos[<?php echo $ci; ?>][label]" value="<?php echo esc_attr($combo['label'] ?? ''); ?>" placeholder="z.B. Festival-Kombi Fr+Sa+So" style="width:100%">
                        </div>
                        <div class="tix-combo-row-inline">
                            <div>
                                <label class="tix-combo-label">Kombi-Preis (€)</label>
                                <input type="number" name="tix_combos[<?php echo $ci; ?>][price]" value="<?php echo esc_attr($combo['price'] ?? ''); ?>" step="0.01" min="0" style="width:120px" placeholder="45.00">
                            </div>
                            <div>
                                <label class="tix-combo-label">Kategorie dieses Events</label>
                                <select name="tix_combos[<?php echo $ci; ?>][self_cat_index]" class="tix-combo-self-cat">
                                    <?php foreach ($self_cats as $sc): ?>
                                        <option value="<?php echo $sc['index']; ?>" <?php selected($sc['index'], intval($combo['self_cat_index'] ?? 0)); ?>><?php echo esc_html($sc['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="tix-combo-partners-section">
                            <label class="tix-combo-label">Partner-Events</label>
                            <div class="tix-combo-partners" data-combo-index="<?php echo $ci; ?>">
                                <?php
                                $partners = $combo['partners'] ?? [];
                                $selected_partner_ids = array_map(fn($p) => intval($p['event_id']), $partners);
                                foreach ($partners as $pi => $partner):
                                    $pev = get_post(intval($partner['event_id']));
                                    if (!$pev || $pev->post_type !== 'event') continue;
                                    $p_cats = get_post_meta($pev->ID, '_tix_ticket_categories', true);
                                    if (!is_array($p_cats)) $p_cats = [];
                                ?>
                                <div class="tix-combo-partner-tag" data-event-id="<?php echo $pev->ID; ?>">
                                    <input type="hidden" name="tix_combos[<?php echo $ci; ?>][partners][<?php echo $pi; ?>][event_id]" value="<?php echo $pev->ID; ?>">
                                    <div class="tix-combo-partner-top">
                                        <div class="tix-combo-partner-info">
                                            <span class="tix-combo-partner-title"><?php echo esc_html($pev->post_title); ?></span>
                                            <span class="tix-combo-partner-date"><?php echo esc_html(get_post_meta($pev->ID, '_tix_date_display', true)); ?></span>
                                        </div>
                                        <button type="button" class="tix-combo-partner-remove" title="Entfernen">&times;</button>
                                    </div>
                                    <div class="tix-combo-partner-cat-row">
                                        <span class="tix-combo-partner-cat-label">Kategorie</span>
                                        <select name="tix_combos[<?php echo $ci; ?>][partners][<?php echo $pi; ?>][cat_index]" class="tix-combo-cat-select">
                                            <?php foreach ($p_cats as $pci => $pc):
                                                if (empty($pc['name']) || !empty($pc['offline_ticket'])) continue;
                                            ?>
                                                <option value="<?php echo $pci; ?>" <?php selected($pci, intval($partner['cat_index'] ?? 0)); ?>><?php echo esc_html($pc['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="tix-combo-add-partner">
                                <select class="tix-combo-event-select regular-text">
                                    <option value="">Partner-Event hinzufügen…</option>
                                    <?php foreach ($all_events as $ev):
                                        $date = get_post_meta($ev->ID, '_tix_date_start', true);
                                        $date_fmt = $date ? date_i18n('d.m.Y', strtotime($date)) : '';
                                        $already = in_array($ev->ID, $selected_partner_ids);
                                    ?>
                                        <option value="<?php echo $ev->ID; ?>"
                                                data-title="<?php echo esc_attr($ev->post_title); ?>"
                                                data-date="<?php echo esc_attr(get_post_meta($ev->ID, '_tix_date_display', true)); ?>"
                                                <?php echo $already ? 'disabled' : ''; ?>>
                                            <?php echo esc_html($ev->post_title); ?><?php echo $date_fmt ? " ({$date_fmt})" : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p style="margin-top:10px;">
                <button type="button" class="button" id="tix-combo-add">+ Kombi hinzufügen</button>
            </p>

            <?php // Event-Optionen kommen jetzt via tixAdmin.availableEvents (wp_localize_script) ?>
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Medien (Galerie & Video)
    // ──────────────────────────────────────────
    public static function render_media($post) {
        $gallery_ids = get_post_meta($post->ID, '_tix_gallery', true);
        if (!is_array($gallery_ids)) $gallery_ids = [];

        $video_url = get_post_meta($post->ID, '_tix_video_url', true);
        $video_id  = get_post_meta($post->ID, '_tix_video_id', true);
        ?>
        <div class="tix-media-meta">

            <?php // ── Galerie ── ?>
            <div class="tix-media-section">
                <h4 style="margin:0 0 8px;">📷 Bildergalerie</h4>
                <p class="description" style="margin:0 0 10px;">
                    Bilder für die Event-Galerie. Reihenfolge per Drag &amp; Drop ändern.<br>
                    Abrufbar als Breakdance-Meta: <code>_tix_gallery_ids</code> (kommagetrennte IDs), <code>_tix_gallery_urls</code> (kommagetrennte URLs).
                </p>

                <div class="tix-gallery-thumbs" id="tix-gallery-thumbs">
                    <?php foreach ($gallery_ids as $img_id):
                        $img_id = intval($img_id);
                        $thumb = wp_get_attachment_image_url($img_id, 'thumbnail');
                        if (!$thumb) continue;
                    ?>
                        <div class="tix-gallery-thumb" data-id="<?php echo $img_id; ?>">
                            <img src="<?php echo esc_url($thumb); ?>" alt="">
                            <input type="hidden" name="tix_gallery[]" value="<?php echo $img_id; ?>">
                            <button type="button" class="tix-gallery-remove" title="Entfernen">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="button" id="tix-gallery-add">+ Bilder hinzufügen</button>
            </div>

            <hr style="border:0; border-top:1px solid #e5e7eb; margin:16px 0;">

            <?php // ── Video ── ?>
            <div class="tix-media-section">
                <h4 style="margin:0 0 8px;">🎬 Video</h4>
                <p class="description" style="margin:0 0 10px;">
                    YouTube-, Vimeo- oder selbst gehostetes Video. Breakdance-Meta: <code>_tix_video_url</code>, <code>_tix_video_embed</code> (oEmbed-HTML).
                </p>

                <div class="tix-video-fields">
                    <div style="display:flex; gap:8px; align-items:flex-start;">
                        <div style="flex:1;">
                            <label style="font-size:12px; font-weight:600; display:block; margin-bottom:3px;">Video-URL</label>
                            <input type="text" name="tix_video_url" value="<?php echo esc_attr($video_url); ?>" class="large-text" placeholder="https://youtube.com/watch?v=… oder https://vimeo.com/…">
                        </div>
                        <div>
                            <label style="font-size:12px; font-weight:600; display:block; margin-bottom:3px;">&nbsp;</label>
                            <button type="button" class="button" id="tix-video-media">Aus Mediathek</button>
                        </div>
                    </div>
                    <input type="hidden" name="tix_video_id" id="tix-video-id" value="<?php echo esc_attr($video_id); ?>">
                    <?php if ($video_url): ?>
                        <div class="tix-video-preview" id="tix-video-preview" style="margin-top:8px;">
                            <?php
                            // Vorschau für bekannte URLs
                            if (preg_match('/youtube\.com|youtu\.be|vimeo\.com/', $video_url)) {
                                echo '<span style="font-size:12px; color:#4caf50;">✓ Video-URL erkannt</span>';
                            } elseif ($video_id) {
                                echo '<span style="font-size:12px; color:#4caf50;">✓ Mediathek-Video (#' . intval($video_id) . ')</span>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Ticketverkauf
    // ──────────────────────────────────────────
    public static function render_tickets($post) {

        $enabled    = get_post_meta($post->ID, '_tix_tickets_enabled', true);
        $presale    = get_post_meta($post->ID, '_tix_presale_active', true);
        $categories = get_post_meta($post->ID, '_tix_ticket_categories', true);

        // Externer Ticketshop
        $ext_enabled = get_post_meta($post->ID, '_tix_extshop_enabled', true);
        $ext_url     = get_post_meta($post->ID, '_tix_extshop_url', true);
        $ext_text    = get_post_meta($post->ID, '_tix_extshop_text', true) ?: '';
        $ext_mode    = get_post_meta($post->ID, '_tix_extshop_mode', true) ?: 'replace';

        // Gruppenrabatt-Status vorab laden (für Paket-Exklusivität)
        $group_discount = get_post_meta($post->ID, '_tix_group_discount', true);
        $gd_enabled = !empty($group_discount['enabled']);

        // Presale-End + Event-Status
        $presale_end    = get_post_meta($post->ID, '_tix_presale_end', true);
        $presale_end_mode = get_post_meta($post->ID, '_tix_presale_end_mode', true) ?: 'manual';
        $presale_end_offset = get_post_meta($post->ID, '_tix_presale_end_offset', true);
        if ($presale_end_offset === '') $presale_end_offset = '0';
        $event_status      = get_post_meta($post->ID, '_tix_event_status', true) ?: '';
        $presale_start     = get_post_meta($post->ID, '_tix_presale_start', true);
        $waitlist_enabled  = get_post_meta($post->ID, '_tix_waitlist_enabled', true);

        // Defaults für neue Events
        if ($presale === '') $presale = '1';
        if (!is_array($categories) || empty($categories)) {
            $categories = [self::empty_row()];
        }

        // Stock-Daten von WC holen
        $stock_data = self::get_stock_data($categories);
        ?>

        <?php // ── Hauptschalter ── ?>
        <div class="tix-toggle-wrap">
            <label class="tix-toggle-label">
                <input type="hidden" name="tix_tickets_enabled" value="0">
                <input type="checkbox" name="tix_tickets_enabled" value="1" id="tix-tickets-enabled"
                       <?php checked($enabled, '1'); ?>>
                <span class="tix-toggle-text">Tickets für dieses Event verkaufen</span>
            </label>
            <?php self::tip('Aktiviere dies, wenn du für dieses Event Tickets anbieten möchtest. Ohne Haken wird das Event als Info-Seite ohne Ticketverkauf angezeigt.'); ?>
        </div>

        <?php // ── Ticket-Bereich (nur sichtbar wenn aktiviert) ── ?>
        <div id="tix-tickets-panel" <?php echo $enabled !== '1' ? 'style="display:none;"' : ''; ?>>

            <?php
            // ── API-Key Anzeige ──
            $api_key = get_post_meta($post->ID, '_tix_api_key', true);
            if ($api_key):
            ?>
            <div class="tix-api-key-info">
                <span class="tix-api-key-label">API-Key (Einlass):</span>
                <code class="tix-api-key-value"><?php echo esc_html($api_key); ?></code>
                <button type="button" class="button tix-csv-teilnehmer" data-event="<?php echo $post->ID; ?>">
                    📋 Teilnehmerliste CSV
                </button>
            </div>
            <?php endif; ?>

            <?php // ── Vorverkauf-Steuerung ── ?>
            <div class="tix-presale-bar" id="tix-presale-bar">
                <div class="tix-presale-status">
                    <span class="tix-presale-dot <?php echo $presale === '1' ? 'tix-dot-active' : 'tix-dot-ended'; ?>"></span>
                    <span class="tix-presale-text">
                        Vorverkauf: <strong><?php echo $presale === '1' ? 'Aktiv' : 'Beendet'; ?></strong>
                    </span>
                    <?php self::tip('Steuert ob Tickets aktuell online gekauft werden können. Bei „Beendet" werden alle Tickets als ausverkauft angezeigt – praktisch um den Verkauf z.B. kurz vor Event-Start zu stoppen.'); ?>
                </div>
                <label class="tix-presale-toggle">
                    <input type="hidden" name="tix_presale_active" value="0">
                    <input type="checkbox" name="tix_presale_active" value="1" id="tix-presale-toggle"
                           <?php checked($presale, '1'); ?>>
                    <span class="tix-presale-switch"></span>
                </label>
            </div>

            <?php // ── Auto-Presale-Ende ── ?>
            <div class="tix-presale-end-wrap">
                <div class="tix-presale-end-row">
                    <label class="tix-field-label">Vorverkauf endet</label>
                    <?php self::tip('Automatisches Beenden des Vorverkaufs. „Manuell" = nur per Toggle oben. „Vor Event-Start" = automatisch X Stunden vor Beginn. „Festes Datum" = exakter Zeitpunkt.'); ?>
                    <select name="tix_presale_end_mode" id="tix-presale-end-mode" class="tix-select-sm">
                        <option value="manual" <?php selected($presale_end_mode, 'manual'); ?>>Manuell</option>
                        <option value="before_event" <?php selected($presale_end_mode, 'before_event'); ?>>Vor Event-Start</option>
                        <option value="fixed" <?php selected($presale_end_mode, 'fixed'); ?>>Festes Datum</option>
                    </select>
                    <span id="tix-presale-end-offset-wrap" style="<?php echo $presale_end_mode !== 'before_event' ? 'display:none;' : ''; ?>">
                        <input type="number" name="tix_presale_end_offset" value="<?php echo esc_attr($presale_end_offset); ?>"
                               min="0" max="168" step="1" class="tix-input-sm" style="width:60px">
                        <span class="tix-field-hint">Stunden vorher</span>
                    </span>
                    <span id="tix-presale-end-fixed-wrap" style="<?php echo $presale_end_mode !== 'fixed' ? 'display:none;' : ''; ?>">
                        <input type="datetime-local" name="tix_presale_end" value="<?php echo esc_attr($presale_end); ?>" class="tix-input-sm">
                    </span>
                </div>
            </div>

            <?php // ── Vorverkauf-Start (Countdown) ── ?>
            <div class="tix-presale-end-wrap" style="margin-top:8px;">
                <div class="tix-presale-end-row">
                    <label class="tix-field-label">Vorverkauf startet am</label>
                    <?php self::tip('Wenn gesetzt, wird vor diesem Zeitpunkt ein Countdown angezeigt und Besucher können sich per E-Mail benachrichtigen lassen.'); ?>
                    <input type="datetime-local" name="tix_presale_start" value="<?php echo esc_attr($presale_start); ?>" class="tix-input-sm">
                    <?php if ($presale_start): ?>
                        <button type="button" class="button tix-btn-sm" onclick="this.previousElementSibling.value='';this.style.display='none';" title="Zurücksetzen" style="margin-left:4px;">✕</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php // ── Warteliste ── ?>
            <div class="tix-presale-end-wrap" style="margin-top:8px;">
                <div class="tix-presale-end-row">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="tix_waitlist_enabled" value="1" <?php checked($waitlist_enabled, '1'); ?>>
                        <span class="tix-field-label" style="margin:0;">Warteliste aktivieren</span>
                    </label>
                    <?php self::tip('Bei ausverkauften Tickets wird ein E-Mail-Formular angezeigt. Besucher werden automatisch benachrichtigt, wenn wieder Tickets verfügbar sind.'); ?>
                </div>
            </div>

            <?php // ── Externer Ticketshop ── ?>
            <div class="tix-presale-end-wrap" style="margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0;">
                <div class="tix-presale-end-row">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="hidden" name="tix_extshop_enabled" value="0">
                        <input type="checkbox" name="tix_extshop_enabled" value="1" id="tix-extshop-toggle"
                               <?php checked($ext_enabled, '1'); ?>>
                        <span class="tix-field-label" style="margin:0;">Externer Ticketshop</span>
                    </label>
                    <?php self::tip('Leite Besucher zu einem externen Ticketshop weiter (z.B. Eventim, Ticketmaster). Der interne Ticket-Selector kann ersetzt oder ergänzt werden.'); ?>
                </div>
                <div id="tix-extshop-panel" style="margin-top:10px;padding:12px 16px;background:#f8fafc;border-radius:6px;<?php echo $ext_enabled !== '1' ? 'display:none;' : ''; ?>">
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div>
                            <label class="tix-field-label" style="display:block;margin-bottom:4px;">URL</label>
                            <input type="url" name="tix_extshop_url" value="<?php echo esc_attr($ext_url); ?>"
                                   placeholder="https://www.eventim.de/event/..." class="widefat" style="max-width:500px;">
                        </div>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <div>
                                <label class="tix-field-label" style="display:block;margin-bottom:4px;">Button-Text</label>
                                <input type="text" name="tix_extshop_text" value="<?php echo esc_attr($ext_text); ?>"
                                       placeholder="Tickets kaufen" class="regular-text" style="width:250px;">
                            </div>
                            <div>
                                <label class="tix-field-label" style="display:block;margin-bottom:4px;">Anzeige</label>
                                <select name="tix_extshop_mode" class="tix-select-sm">
                                    <option value="replace" <?php selected($ext_mode, 'replace'); ?>>Ersetzt Ticket-Selector</option>
                                    <option value="both" <?php selected($ext_mode, 'both'); ?>>Zusätzlich zum Ticket-Selector</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            (function(){
                var cb = document.getElementById('tix-extshop-toggle');
                var pn = document.getElementById('tix-extshop-panel');
                if (cb && pn) cb.addEventListener('change', function(){ pn.style.display = this.checked ? '' : 'none'; });
            })();
            </script>

            <?php // ── Event-Status ── ?>
            <div class="tix-status-wrap">
                <label class="tix-field-label">Event-Status</label>
                <?php self::tip('Manueller Status-Override. „Automatisch" erkennt ausverkaufte Events selbst. Wird als Breakdance-Meta ausgegeben.'); ?>
                <select name="tix_event_status" class="tix-select-sm">
                    <option value="" <?php selected($event_status, ''); ?>>Automatisch</option>
                    <option value="available" <?php selected($event_status, 'available'); ?>>Verfügbar</option>
                    <option value="few_tickets" <?php selected($event_status, 'few_tickets'); ?>>Wenige Tickets</option>
                    <option value="sold_out" <?php selected($event_status, 'sold_out'); ?>>Ausverkauft</option>
                    <option value="cancelled" <?php selected($event_status, 'cancelled'); ?>>Abgesagt</option>
                    <option value="postponed" <?php selected($event_status, 'postponed'); ?>>Verschoben</option>
                </select>
            </div>

            <?php // ── Stock-Übersicht (nur wenn sync'd) ── ?>
            <?php if (!empty($stock_data['has_products'])): ?>
            <div class="tix-stock-overview">
                <div class="tix-stock-item">
                    <span class="tix-stock-num"><?php echo $stock_data['total_sold']; ?></span>
                    <span class="tix-stock-lbl">Verkauft</span>
                </div>
                <div class="tix-stock-item">
                    <span class="tix-stock-num"><?php echo $stock_data['total_remaining']; ?></span>
                    <span class="tix-stock-lbl">Verfügbar</span>
                </div>
                <div class="tix-stock-item">
                    <span class="tix-stock-num"><?php echo $stock_data['total_capacity']; ?></span>
                    <span class="tix-stock-lbl">Kapazität</span>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // ── Saalplan-Info (wenn aktiv → Kategorien kommen aus Saalplan) ──
            $sm_id_tickets = intval(get_post_meta($post->ID, '_tix_seatmap_id', true));
            if ($sm_id_tickets && class_exists('TIX_Seatmap')):
                $sm_title_tickets = get_the_title($sm_id_tickets);
                $sm_sections = TIX_Seatmap::get_section_data($sm_id_tickets, $post->ID);
            ?>
            <div class="tix-card" id="tix-seatmap-categories-card" style="margin-bottom:16px;">
                <div class="tix-card-header" style="background:linear-gradient(135deg,#ede9fe,#e0e7ff);">
                    <span class="dashicons dashicons-layout"></span>
                    <h3>Ticket-Kategorien via Saalplan: <?php echo esc_html($sm_title_tickets); ?></h3>
                </div>
                <div class="tix-card-body">
                    <p style="margin:0 0 12px;color:#475569;">Die Ticket-Kategorien werden automatisch aus den Sektionen des Saalplans generiert. Die manuellen Kategorien unten werden für dieses Event ignoriert.</p>
                    <?php if (!empty($sm_sections)): ?>
                    <table class="widefat tix-tbl" style="margin-bottom:12px;">
                        <thead>
                            <tr>
                                <th>Sektion</th>
                                <th style="width:100px;">Plätze</th>
                                <th style="width:100px;">Verfügbar</th>
                                <th style="width:100px;">Preis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sm_sections as $sec): ?>
                            <tr>
                                <td><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr($sec['color']); ?>;margin-right:6px;vertical-align:middle;"></span><?php echo esc_html($sec['label']); ?></td>
                                <td><?php echo intval($sec['total']); ?></td>
                                <td><?php echo intval($sec['available']); ?></td>
                                <td><?php echo number_format($sec['price'], 2, ',', '.'); ?> €</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                    <a href="<?php echo admin_url('post.php?post=' . $sm_id_tickets . '&action=edit'); ?>" class="button" target="_blank">
                        🪑 Saalplan bearbeiten
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── Ticket-Tabelle ── ?>
            <div id="tix-manual-categories-wrap" <?php echo $sm_id_tickets ? 'style="display:none;"' : ''; ?>>
            <table class="widefat tix-tbl" id="tix-ticket-table">
                <thead>
                    <tr>
                        <th style="width:15%">Kategorie <?php self::tip('Name der Preiskategorie, z.B. „Early Bird", „VIP", „Abendkasse".'); ?></th>
                        <th style="width:8%">Preis (€) <?php self::tip('Regulärer Preis inkl. MwSt.'); ?></th>
                        <th style="width:8%">Sale (€) <?php self::tip('Reduzierter Preis – leer lassen wenn kein Sale.'); ?></th>
                        <th style="width:6%">Kapaz. <?php self::tip('Wie viele Tickets dieser Kategorie insgesamt verfügbar sind.'); ?></th>
                        <th style="width:17%">Beschreibung</th>
                        <th style="width:4%">Bild</th>
                        <th style="width:12%">Verkauf <?php self::tip('Online = Ticket wird im Shop verkauft. Offline = Wird angezeigt, aber nicht online verkauft (z.B. Abendkasse). Aus = Nicht verfügbar.'); ?></th>
                        <th style="width:14%">Stock <?php self::tip('Verkaufte und verfügbare Tickets. Bestand manuell ändern: neue Zahl eingeben und speichern.'); ?></th>
                        <th style="width:4%"></th>
                    </tr>
                </thead>
                <tbody id="tix-ticket-rows">
                    <?php foreach ($categories as $i => $cat):
                        self::render_row($i, $cat, $stock_data['items'][$i] ?? null);
                    endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:10px;">
                <button type="button" class="button" id="tix-add-row">+ Kategorie hinzufügen</button>
            </p>
            </div><?php // /tix-manual-categories-wrap ?>

            <?php // ── Gruppenrabatt ── ?>
            <?php
                // $group_discount und $gd_enabled bereits oben geladen
                $gd_tiers   = $group_discount['tiers'] ?? [];
                if (empty($gd_tiers)) $gd_tiers = [['min_qty' => '', 'percent' => '']];
                $gd_combine_bundle = !empty($group_discount['combine_bundle']);
                $gd_combine_combo  = !empty($group_discount['combine_combo']);
                $gd_combine_phase  = !empty($group_discount['combine_phase']);
            ?>
            <div class="tix-group-discount-wrap" style="margin-top:20px; padding-top:16px; border-top:1px solid #ddd;">
                <div class="tix-toggle-wrap">
                    <label class="tix-toggle-label">
                        <input type="hidden" name="tix_group_discount[enabled]" value="0">
                        <input type="checkbox" name="tix_group_discount[enabled]" value="1" id="tix-gd-toggle"
                               <?php checked($gd_enabled); ?>>
                        <span class="tix-toggle-text">Mengenrabatt aktivieren</span>
                    </label>
                    <?php self::tip('Rabatt wird automatisch gewährt wenn die Gesamtanzahl der Tickets für dieses Event die Mindestmenge erreicht. Gilt über alle Kategorien hinweg.'); ?>
                </div>
                <div id="tix-gd-panel" <?php echo !$gd_enabled ? 'style="display:none;"' : ''; ?>>
                    <table class="widefat tix-tbl" style="max-width:450px; margin-top:10px;">
                        <thead>
                            <tr>
                                <th style="width:45%">Ab Tickets <?php self::tip('Mindestanzahl Tickets (über alle Kategorien dieses Events zusammen) ab der der Rabatt greift.'); ?></th>
                                <th style="width:45%">Rabatt (%) <?php self::tip('Prozentualer Rabatt auf alle Tickets dieses Events.'); ?></th>
                                <th style="width:10%"></th>
                            </tr>
                        </thead>
                        <tbody id="tix-gd-rows">
                            <?php foreach ($gd_tiers as $ti => $tier): ?>
                            <tr class="tix-gd-row">
                                <td><input type="number" name="tix_group_discount[tiers][<?php echo $ti; ?>][min_qty]" value="<?php echo esc_attr($tier['min_qty']); ?>" min="2" step="1" class="tix-input-sm" style="width:100%" placeholder="z.B. 5"></td>
                                <td><input type="number" name="tix_group_discount[tiers][<?php echo $ti; ?>][percent]" value="<?php echo esc_attr($tier['percent']); ?>" min="1" max="99" step="1" class="tix-input-sm" style="width:100%" placeholder="z.B. 10"></td>
                                <td><button type="button" class="button tix-gd-remove" title="Entfernen">&times;</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:8px;">
                        <button type="button" class="button" id="tix-gd-add-tier">+ Staffel hinzufügen</button>
                    </p>

                    <div style="margin-top:14px; padding:10px 12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
                        <strong style="font-size:12px; display:block; margin-bottom:6px;">Kombinierbar mit:</strong>
                        <label style="display:block; margin:4px 0; font-size:12px; cursor:pointer;">
                            <input type="hidden" name="tix_group_discount[combine_bundle]" value="0">
                            <input type="checkbox" name="tix_group_discount[combine_bundle]" value="1" <?php checked($gd_combine_bundle); ?>>
                            Paketangebote (🎁 Kaufe X, zahle Y)
                        </label>
                        <label style="display:block; margin:4px 0; font-size:12px; cursor:pointer;">
                            <input type="hidden" name="tix_group_discount[combine_combo]" value="0">
                            <input type="checkbox" name="tix_group_discount[combine_combo]" value="1" <?php checked($gd_combine_combo); ?>>
                            Kombi-Tickets (🎫 Mehrere Events)
                        </label>
                        <label style="display:block; margin:4px 0; font-size:12px; cursor:pointer;">
                            <input type="hidden" name="tix_group_discount[combine_phase]" value="0">
                            <input type="checkbox" name="tix_group_discount[combine_phase]" value="1" <?php checked($gd_combine_phase); ?>>
                            Phasen-Preise (Frühbucher, Spätbucher, etc.)
                        </label>
                        <p class="description" style="margin:6px 0 0; font-size:11px; color:#666;">Nicht angehakte Rabattarten werden deaktiviert sobald der Mengenrabatt greift.</p>
                    </div>
                </div>
            </div>

            <?php // ── Kombi-Tickets ── ?>
            <?php self::render_combos($post, $categories); ?>

            <?php // ── Embed Widget ── ?>
            <?php
                $embed_enabled = get_post_meta($post->ID, '_tix_embed_enabled', true);
                $embed_domains = get_post_meta($post->ID, '_tix_embed_domains', true) ?: '';
                $embed_url     = add_query_arg('tix_embed', $post->ID, home_url('/'));
            ?>
            <div class="tix-embed-wrap" style="margin-top:20px; padding-top:16px; border-top:1px solid #ddd;">
                <div class="tix-toggle-wrap">
                    <label class="tix-toggle-label">
                        <input type="hidden" name="tix_embed_enabled" value="0">
                        <input type="checkbox" name="tix_embed_enabled" value="1" id="tix-embed-toggle"
                               <?php checked($embed_enabled, '1'); ?>>
                        <span class="tix-toggle-text">Embed Widget aktivieren</span>
                    </label>
                    <?php self::tip('Erlaubt das Einbetten des Ticket-Selectors als iFrame auf externen Websites. Generiert einen HTML-Code zum Kopieren.'); ?>
                </div>
                <div id="tix-embed-panel" <?php echo $embed_enabled !== '1' ? 'style="display:none;"' : ''; ?>>
                    <table class="form-table" style="margin-top:8px;">
                        <tr>
                            <th style="width:140px; padding:6px 10px 6px 0;"><label class="tix-field-label">Erlaubte Domains</label></th>
                            <td style="padding:6px 0;">
                                <input type="text" name="tix_embed_domains" value="<?php echo esc_attr($embed_domains); ?>"
                                       class="regular-text" placeholder="example.com, band-website.de" style="width:100%;">
                                <p class="description" style="margin:4px 0 0;">Kommagetrennt. Leer = alle Domains erlaubt.</p>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:6px 10px 6px 0;"><label class="tix-field-label">Embed-Code</label></th>
                            <td style="padding:6px 0;">
                                <div style="position:relative;">
                                    <textarea id="tix-embed-code" readonly rows="3" class="large-text code" style="font-size:12px; background:#f6f7f7; resize:none; cursor:pointer;"
                                        onclick="this.select(); document.execCommand('copy');">&lt;iframe src="<?php echo esc_url($embed_url); ?>" style="width:100%;border:none;min-height:400px;" allow="payment"&gt;&lt;/iframe&gt;
&lt;script&gt;window.addEventListener('message',function(e){if(e.data&&e.data.type==='tix-embed-resize'){document.querySelector('iframe[src*="tix_embed=<?php echo $post->ID; ?>"]').style.height=e.data.height+'px';}if(e.data&&e.data.type==='tix-embed-navigate'){window.open(e.data.url,'_blank');}});&lt;/script&gt;</textarea>
                                    <p class="description" style="margin:4px 0 0;">Klicken zum Kopieren. Füge diesen Code auf der externen Seite ein.</p>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>


        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Tab: Ticket-Vorlage
    // ──────────────────────────────────────────
    private static function render_template($post) {
        $tt_mode   = get_post_meta($post->ID, '_tix_ticket_template_mode', true) ?: 'global';
        $tt_json   = get_post_meta($post->ID, '_tix_ticket_template', true) ?: '';
        $tt_tpl_id = intval(get_post_meta($post->ID, '_tix_ticket_template_id', true));
        $has_global = !empty(TIX_Ticket_Template::get_global_config());

        // Verfügbare Ticket-Vorlagen (CPT)
        $templates = [];
        if (class_exists('TIX_Ticket_Template_CPT')) {
            $templates = TIX_Ticket_Template_CPT::get_all_templates();
        }
        $has_templates = !empty($templates);
        ?>
        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-media-document"></span>
                <h3>Ticket-Vorlage</h3>
            </div>
            <div class="tix-card-body">
                <p class="description" style="margin:0 0 16px;">Wähle, welche Vorlage für die Tickets dieses Events verwendet wird.</p>
                <div class="tix-template-mode-radios" style="margin-bottom:16px;display:flex;flex-direction:column;gap:8px;">
                    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;">
                        <input type="radio" name="tix_ticket_template_mode" value="global" <?php checked($tt_mode, 'global'); ?>>
                        Globale Vorlage<?php echo $has_global ? '' : ' <em style="opacity:.5">(nicht konfiguriert)</em>'; ?>
                    </label>
                    <?php if ($has_templates): ?>
                    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;">
                        <input type="radio" name="tix_ticket_template_mode" value="template" <?php checked($tt_mode, 'template'); ?>>
                        Vorlage wählen
                    </label>
                    <?php endif; ?>
                    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;">
                        <input type="radio" name="tix_ticket_template_mode" value="custom" <?php checked($tt_mode, 'custom'); ?>>
                        Eigene Vorlage
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;">
                        <input type="radio" name="tix_ticket_template_mode" value="none" <?php checked($tt_mode, 'none'); ?>>
                        Keine (HTML-Ticket)
                    </label>
                </div>

                <?php if ($has_templates): ?>
                <div id="tix-template-select-wrap" style="margin-bottom:16px;<?php echo $tt_mode !== 'template' ? 'display:none;' : ''; ?>">
                    <select name="tix_ticket_template_id" id="tix-template-select" style="min-width:250px;">
                        <option value="">— Vorlage wählen —</option>
                        <?php foreach ($templates as $tpl_id => $tpl_title): ?>
                            <option value="<?php echo esc_attr($tpl_id); ?>" <?php selected($tt_tpl_id, $tpl_id); ?>>
                                <?php echo esc_html($tpl_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=tix_ticket_tpl')); ?>" target="_blank" style="margin-left:8px;font-size:12px;">Vorlagen verwalten</a>
                </div>
                <?php endif; ?>

                <div id="tix-tte-metabox-editor-wrap" class="tix-tte-wrap" style="<?php echo $tt_mode !== 'custom' ? 'display:none;' : ''; ?>"></div>
                <input type="hidden" name="tix_ticket_template" id="tix-tte-metabox-input" value="<?php echo esc_attr($tt_json); ?>">
            </div>
        </div>
        <?php
    }

    private static function render_row($i, $cat, $stock = null) {
        $has_ids  = !empty($cat['tc_event_id']) || !empty($cat['product_id']);
        $image_id = intval($cat['image_id'] ?? 0);
        $img_url  = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
        $online   = ($cat['online'] ?? '1') === '1' || ($cat['online'] ?? '1') === 1;
        $offline  = !empty($cat['offline_ticket']);
        $sku      = $cat['sku'] ?? '';
        $phases   = $cat['phases'] ?? [];

        // Row-Klassen
        $row_class = 'tix-row';
        if ($offline) $row_class .= ' tix-row-offline-ticket';
        elseif (!$online) $row_class .= ' tix-row-offline';

        // Aktive Phase bestimmen
        $active_phase = self::get_active_phase($phases);

        // Stock Info
        $has_stock = $stock !== null;
        $sold      = $has_stock ? $stock['sold'] : 0;
        $remaining = $has_stock ? $stock['remaining'] : '—';
        $stock_status = $has_stock ? $stock['status'] : '';
        ?>
        <tr class="<?php echo $row_class; ?>">
            <td>
                <input type="text" name="tix_tickets[<?php echo $i; ?>][name]"
                       value="<?php echo esc_attr($cat['name'] ?? ''); ?>" placeholder="z.B. VIP" style="width:100%">
                <input type="text" name="tix_tickets[<?php echo $i; ?>][group]"
                       value="<?php echo esc_attr($cat['group'] ?? ''); ?>" class="tix-group-input" placeholder="Gruppe (optional)">
                <?php if ($sku): ?>
                    <span class="tix-sku"><?php echo esc_html($sku); ?></span>
                <?php endif; ?>
                <a href="#" class="tix-phases-toggle<?php echo !empty($phases) ? ' has-phases' : ''; ?>" title="Preisphasen">
                    ⏱ <?php echo !empty($phases) ? count($phases) . ' Phase' . (count($phases) > 1 ? 'n' : '') : 'Phasen'; ?>
                    <?php if ($active_phase): ?>
                        <span class="tix-phase-active-badge">● <?php echo esc_html($active_phase['name']); ?></span>
                    <?php endif; ?>
                </a>
                <?php
                    $bundle_buy   = intval($cat['bundle_buy'] ?? 0);
                    $bundle_pay   = intval($cat['bundle_pay'] ?? 0);
                    $bundle_label = $cat['bundle_label'] ?? '';
                    $has_bundle   = $bundle_buy >= 2 && $bundle_pay >= 1 && $bundle_pay < $bundle_buy;
                ?>
                <a href="#" class="tix-bundle-toggle<?php echo $has_bundle ? ' has-bundle' : ''; ?>" title="Bundle-Angebot">
                    🎁 <?php echo $has_bundle ? ($bundle_label ?: $bundle_buy . 'er-Paket') : 'Paket'; ?>
                </a>
                <div class="tix-bundle-fields" <?php echo !$has_bundle ? 'style="display:none;"' : ''; ?>>
                    <label style="font-size:11px;color:#888;">Kaufe
                        <input type="number" name="tix_tickets[<?php echo $i; ?>][bundle_buy]"
                               value="<?php echo $bundle_buy ?: ''; ?>" min="2" step="1" class="tix-input-sm" style="width:50px" placeholder="11">
                    </label>
                    <label style="font-size:11px;color:#888;">zahle
                        <input type="number" name="tix_tickets[<?php echo $i; ?>][bundle_pay]"
                               value="<?php echo $bundle_pay ?: ''; ?>" min="1" step="1" class="tix-input-sm" style="width:50px" placeholder="10">
                    </label>
                    <label style="font-size:11px;color:#888;">Label
                        <input type="text" name="tix_tickets[<?php echo $i; ?>][bundle_label]"
                               value="<?php echo esc_attr($bundle_label); ?>" class="tix-input-sm" style="width:160px" placeholder="z.B. Mannschafts-Ticket">
                    </label>
                </div>
                <?php
                    // Saalplan wird jetzt auf Event-Ebene konfiguriert (Erweitert-Tab)
                    $event_sm_id = intval(get_post_meta($post->ID, '_tix_seatmap_id', true));
                    if ($event_sm_id):
                        $sm_title = get_the_title($event_sm_id);
                ?>
                <span style="font-size:11px;color:#10b981;" title="Saalplan auf Event-Ebene konfiguriert (Erweitert-Tab)">
                    🪑 <?php echo esc_html($sm_title); ?>
                </span>
                <?php endif; ?>
            </td>
            <td>
                <input type="number" name="tix_tickets[<?php echo $i; ?>][price]"
                       value="<?php echo esc_attr($cat['price'] ?? ''); ?>" step="0.01" min="0" style="width:100%">
            </td>
            <td>
                <input type="number" name="tix_tickets[<?php echo $i; ?>][sale_price]"
                       value="<?php echo esc_attr($cat['sale_price'] ?? ''); ?>" step="0.01" min="0"
                       placeholder="—" style="width:100%" class="tix-sale-input">
            </td>
            <td>
                <input type="number" name="tix_tickets[<?php echo $i; ?>][qty]"
                       value="<?php echo esc_attr($cat['qty'] ?? ''); ?>" min="0" placeholder="∞" style="width:100%">
            </td>
            <td>
                <input type="text" name="tix_tickets[<?php echo $i; ?>][desc]"
                       value="<?php echo esc_attr($cat['desc'] ?? ''); ?>" placeholder="Optional" style="width:100%">
            </td>
            <td class="tix-img-cell">
                <div class="tix-img-wrap">
                    <div class="tix-img-box <?php echo $img_url ? 'has-img' : ''; ?>" data-i="<?php echo $i; ?>">
                        <?php if ($img_url): ?>
                            <img src="<?php echo esc_url($img_url); ?>">
                        <?php else: ?>
                            <span class="dashicons dashicons-format-image"></span>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="tix_tickets[<?php echo $i; ?>][image_id]" class="tix-img-val" value="<?php echo $image_id ?: ''; ?>">
                    <?php if ($img_url): ?><a href="#" class="tix-img-clear">entfernen</a><?php endif; ?>
                </div>
            </td>
            <?php
                $sale_mode = 'online';
                if ($offline) $sale_mode = 'offline';
                elseif (!$online) $sale_mode = 'off';
            ?>
            <td>
                <select name="tix_tickets[<?php echo $i; ?>][sale_mode]" class="tix-sale-mode">
                    <option value="online" <?php selected($sale_mode, 'online'); ?>>🌐 Online</option>
                    <option value="offline" <?php selected($sale_mode, 'offline'); ?>>🏪 Abendkasse</option>
                    <option value="off" <?php selected($sale_mode, 'off'); ?>>⛔ Aus</option>
                </select>
            </td>
            <td class="tix-stock-cell">
                <?php if ($has_stock): ?>
                    <div class="tix-stock-info">
                        <span class="tix-stock-sold" title="Verkauft"><?php echo $sold; ?> vk.</span>
                        <span class="tix-stock-sep">/</span>
                        <input type="number" name="tix_tickets[<?php echo $i; ?>][stock_override]"
                               value="" placeholder="<?php echo $remaining; ?>"
                               class="tix-stock-input" min="0"
                               title="Neuen Bestand setzen (leer = unverändert)">
                        <span class="tix-stock-avail" title="Verfügbar">verf.</span>
                    </div>
                    <?php if ($stock_status === 'outofstock'): ?>
                        <span class="tix-badge tix-badge-oos">Ausverkauft</span>
                    <?php endif; ?>
                <?php else: ?>
                    <em style="color:#bbb;font-size:12px">—</em>
                <?php endif; ?>
            </td>
            <td>
                <input type="hidden" name="tix_tickets[<?php echo $i; ?>][tc_event_id]" value="<?php echo esc_attr($cat['tc_event_id'] ?? ''); ?>">
                <input type="hidden" name="tix_tickets[<?php echo $i; ?>][product_id]" value="<?php echo esc_attr($cat['product_id'] ?? ''); ?>">
                <input type="hidden" name="tix_tickets[<?php echo $i; ?>][sku]" value="<?php echo esc_attr($sku); ?>">
                <input type="hidden" name="tix_tickets[<?php echo $i; ?>][seatmap_id]" value="<?php echo esc_attr($cat['seatmap_id'] ?? ''); ?>" class="tix-seatmap-id-input">
                <input type="hidden" name="tix_tickets[<?php echo $i; ?>][seatmap_section]" value="<?php echo esc_attr($cat['seatmap_section'] ?? ''); ?>" class="tix-seatmap-section-input">
                <button type="button" class="button tix-del" title="Entfernen">&times;</button>
            </td>
        </tr>
        <?php // ── Preisphasen Sub-Row ── ?>
        <tr class="tix-phases-row" style="display:none;">
            <td colspan="9">
                <div class="tix-phases-wrap">
                    <div class="tix-phases-header">
                        <strong>⏱ Preisphasen</strong>
                        <span class="tix-phases-hint">Phasen werden chronologisch abgearbeitet. Nach der letzten Phase gilt der Standardpreis.</span>
                    </div>
                    <table class="tix-phases-table">
                        <thead>
                            <tr>
                                <th style="width:30%">Phasenname</th>
                                <th style="width:20%">Preis (€)</th>
                                <th style="width:30%">Gültig bis</th>
                                <th style="width:10%">Status</th>
                                <th style="width:10%"></th>
                            </tr>
                        </thead>
                        <tbody class="tix-phases-body">
                            <?php if (!empty($phases)):
                                foreach ($phases as $p => $phase):
                                    self::render_phase_row($i, $p, $phase);
                                endforeach;
                            else: ?>
                                <tr class="tix-phases-empty"><td colspan="5"><em>Keine Phasen definiert. Klicke „+ Phase" um eine Preisphase hinzuzufügen.</em></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <button type="button" class="button tix-phase-add" style="margin-top:6px;">+ Phase hinzufügen</button>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Einzelne Phase-Zeile rendern
     */
    private static function render_phase_row($ticket_idx, $phase_idx, $phase) {
        $now   = current_time('Y-m-d');
        $until = $phase['until'] ?? '';
        $is_past   = ($until && $until < $now);
        $is_active = !$is_past && ($until >= $now);

        // Prüfe ob eine vorherige Phase noch aktiv ist (dann ist diese "wartend")
        // Das wird nur visuell approximiert, die echte Logik ist in get_active_phase()
        $status_class = $is_past ? 'tix-phase-past' : ($is_active ? 'tix-phase-current' : '');
        $status_label = $is_past ? '✓ Abgelaufen' : ($is_active ? '● Aktiv' : '○ Wartend');
        ?>
        <tr class="tix-phase-row <?php echo $status_class; ?>">
            <td>
                <input type="text" name="tix_tickets[<?php echo $ticket_idx; ?>][phases][<?php echo $phase_idx; ?>][name]"
                       value="<?php echo esc_attr($phase['name'] ?? ''); ?>" placeholder="z.B. Early Bird" style="width:100%">
            </td>
            <td>
                <input type="number" name="tix_tickets[<?php echo $ticket_idx; ?>][phases][<?php echo $phase_idx; ?>][price]"
                       value="<?php echo esc_attr($phase['price'] ?? ''); ?>" step="0.01" min="0" style="width:100%">
            </td>
            <td>
                <input type="date" name="tix_tickets[<?php echo $ticket_idx; ?>][phases][<?php echo $phase_idx; ?>][until]"
                       value="<?php echo esc_attr($until); ?>" style="width:100%">
            </td>
            <td>
                <span class="tix-phase-status"><?php echo $status_label; ?></span>
            </td>
            <td>
                <button type="button" class="button tix-phase-del" title="Phase entfernen">&times;</button>
            </td>
        </tr>
        <?php
    }

    /**
     * Aktive Phase ermitteln (erste Phase, deren "bis"-Datum noch nicht abgelaufen ist)
     */
    public static function get_active_phase($phases) {
        if (empty($phases) || !is_array($phases)) return null;
        $now = current_time('Y-m-d');
        foreach ($phases as $phase) {
            $until = $phase['until'] ?? '';
            if ($until && $now <= $until) return $phase;
        }
        return null; // Alle Phasen abgelaufen → Standardpreis
    }

    /**
     * Stock-Daten aus WC-Produkten holen
     */
    private static function get_stock_data($categories) {
        $data = ['has_products' => false, 'total_sold' => 0, 'total_remaining' => 0, 'total_capacity' => 0, 'items' => []];

        foreach ($categories as $i => $cat) {
            $product_id = intval($cat['product_id'] ?? 0);
            if (!$product_id) { $data['items'][$i] = null; continue; }

            $product = wc_get_product($product_id);
            if (!$product) { $data['items'][$i] = null; continue; }

            $data['has_products'] = true;

            $capacity  = intval($cat['qty'] ?? 0);
            $wc_stock  = $product->get_stock_quantity();
            $remaining = $wc_stock !== null ? $wc_stock : $capacity;
            $sold      = max(0, $capacity - $remaining);

            // Bestellungen zählen (genauer)
            $sold_real = self::get_sold_count($product_id);
            if ($sold_real !== false) $sold = $sold_real;

            $data['items'][$i] = [
                'sold'      => $sold,
                'remaining' => $remaining,
                'capacity'  => $capacity,
                'status'    => $product->get_stock_status(),
            ];

            $data['total_sold']      += $sold;
            $data['total_remaining'] += $remaining;
            $data['total_capacity']  += $capacity;
        }

        return $data;
    }

    /**
     * Verkaufte Menge aus Bestellungen
     */
    private static function get_sold_count($product_id) {
        global $wpdb;
        // WC HPOS-kompatibel: lookup-table oder order_itemmeta
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(oim.meta_value)
             FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
             JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
             JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oim2.order_item_id = oi.order_item_id AND oim2.meta_key = '_product_id' AND oim2.meta_value = %d
             WHERE oim.meta_key = '_qty'
             AND oi.order_item_type = 'line_item'",
            $product_id
        ));
        return $count !== null ? intval($count) : false;
    }

    // ──────────────────────────────────────────
    // Serientermine
    // ──────────────────────────────────────────
    public static function render_series($post) {
        $series_parent = get_post_meta($post->ID, '_tix_series_parent', true);

        // Wenn Kind-Event → vereinfachte Ansicht
        if ($series_parent) {
            $master = get_post($series_parent);
            $detached = get_post_meta($post->ID, '_tix_series_detached', true);
            ?>
            <div class="tix-card">
                <p>Dieser Termin ist Teil der Serie <strong>&bdquo;<?php echo esc_html($master ? $master->post_title : '#' . $series_parent); ?>&ldquo;</strong>.</p>
                <p><a href="<?php echo esc_url(get_edit_post_link($series_parent)); ?>" class="button">Master bearbeiten</a></p>
                <label class="tix-toggle-wrap" style="margin-top:12px;">
                    <input type="checkbox" name="tix_series_detached" value="1" <?php checked($detached, '1'); ?>>
                    <strong>Von Master-Aktualisierungen trennen</strong>
                    <span class="tix-hint">Wenn aktiviert, werden Änderungen am Master nicht mehr auf diesen Termin übertragen.</span>
                </label>
            </div>
            <?php
            return;
        }

        // Master-Ansicht
        $enabled      = get_post_meta($post->ID, '_tix_series_enabled', true);
        $mode         = get_post_meta($post->ID, '_tix_series_mode', true) ?: 'periodic';
        $pattern      = get_post_meta($post->ID, '_tix_series_pattern', true) ?: [];
        $manual_dates = get_post_meta($post->ID, '_tix_series_manual_dates', true) ?: [];
        $children     = get_post_meta($post->ID, '_tix_series_children', true) ?: [];

        $frequency = $pattern['frequency'] ?? 'weekly';
        $days      = $pattern['days'] ?? [];
        $week_of   = $pattern['week_of'] ?? 1;
        $day_of    = $pattern['day_of'] ?? 6;
        $day_num   = $pattern['day_num'] ?? 1;
        $end_mode  = $pattern['end_mode'] ?? 'count';
        $end_date  = $pattern['end_date'] ?? '';
        $end_count = $pattern['end_count'] ?? 12;
        ?>

        <?php // ── Hauptschalter ── ?>
        <label class="tix-toggle-wrap">
            <input type="checkbox" id="tix-series-toggle" name="tix_series_enabled" value="1" <?php checked($enabled, '1'); ?>>
            <strong>Serientermine aktivieren</strong>
            <span class="tix-hint">Erstellt automatisch Kind-Events für jeden Termin der Serie.</span>
        </label>

        <div id="tix-series-panel" style="<?php echo $enabled !== '1' ? 'display:none' : ''; ?>">

            <?php // ── Modus ── ?>
            <div class="tix-card" style="margin-top:16px;">
                <h4 style="margin:0 0 12px">Modus</h4>
                <label style="margin-right:24px;">
                    <input type="radio" name="tix_series_mode" value="periodic" <?php checked($mode, 'periodic'); ?>> Periodisch
                </label>
                <label>
                    <input type="radio" name="tix_series_mode" value="manual" <?php checked($mode, 'manual'); ?>> Manuelle Termine
                </label>
            </div>

            <?php // ── Periodisch ── ?>
            <div id="tix-series-periodic" class="tix-card" style="margin-top:12px;<?php echo $mode !== 'periodic' ? 'display:none' : ''; ?>">
                <h4 style="margin:0 0 12px">Wiederholung</h4>

                <p>
                    <select name="tix_series_frequency" id="tix-series-freq">
                        <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Wöchentlich</option>
                        <option value="biweekly" <?php selected($frequency, 'biweekly'); ?>>Alle 2 Wochen</option>
                        <option value="monthly_weekday" <?php selected($frequency, 'monthly_weekday'); ?>>Monatlich (Wochentag)</option>
                        <option value="monthly_date" <?php selected($frequency, 'monthly_date'); ?>>Monatlich (Datum)</option>
                    </select>
                </p>

                <?php // Tage-Picker (weekly/biweekly) ?>
                <div id="tix-series-days" class="tix-day-picker" style="margin:12px 0;<?php echo !in_array($frequency, ['weekly', 'biweekly']) ? 'display:none' : ''; ?>">
                    <?php
                    $day_labels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
                    for ($d = 1; $d <= 7; $d++):
                    ?>
                        <label class="tix-day-btn">
                            <input type="checkbox" name="tix_series_days[]" value="<?php echo $d; ?>" <?php if (in_array($d, $days)) echo 'checked'; ?>>
                            <span><?php echo $day_labels[$d - 1]; ?></span>
                        </label>
                    <?php endfor; ?>
                </div>

                <?php // Monatlich Wochentag ?>
                <div id="tix-series-mw" style="margin:12px 0;<?php echo $frequency !== 'monthly_weekday' ? 'display:none' : ''; ?>">
                    <label>Jede/r
                        <select name="tix_series_week_of">
                            <option value="1" <?php selected($week_of, 1); ?>>1.</option>
                            <option value="2" <?php selected($week_of, 2); ?>>2.</option>
                            <option value="3" <?php selected($week_of, 3); ?>>3.</option>
                            <option value="4" <?php selected($week_of, 4); ?>>4.</option>
                            <option value="-1" <?php selected($week_of, -1); ?>>Letzte/r</option>
                        </select>
                        <select name="tix_series_day_of">
                            <?php foreach (['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'] as $i => $name): ?>
                                <option value="<?php echo $i + 1; ?>" <?php selected($day_of, $i + 1); ?>><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                        des Monats
                    </label>
                </div>

                <?php // Monatlich Datum ?>
                <div id="tix-series-md" style="margin:12px 0;<?php echo $frequency !== 'monthly_date' ? 'display:none' : ''; ?>">
                    <label>Am
                        <select name="tix_series_day_num">
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?php echo $d; ?>" <?php selected($day_num, $d); ?>><?php echo $d; ?>.</option>
                            <?php endfor; ?>
                        </select>
                        des Monats
                    </label>
                </div>

                <?php // Ende der Serie ?>
                <h4 style="margin:16px 0 8px">Serie endet</h4>
                <label style="display:block;margin-bottom:8px;">
                    <input type="radio" name="tix_series_end_mode" value="count" <?php checked($end_mode, 'count'); ?>>
                    Nach <input type="number" name="tix_series_end_count" value="<?php echo intval($end_count); ?>" min="1" max="365" style="width:60px;"> Terminen
                </label>
                <label style="display:block;">
                    <input type="radio" name="tix_series_end_mode" value="date" <?php checked($end_mode, 'date'); ?>>
                    Am Datum: <input type="date" name="tix_series_end_date" value="<?php echo esc_attr($end_date); ?>" style="width:160px;">
                </label>
            </div>

            <?php // ── Manuelle Termine ── ?>
            <div id="tix-series-manual" class="tix-card" style="margin-top:12px;<?php echo $mode !== 'manual' ? 'display:none' : ''; ?>">
                <h4 style="margin:0 0 12px">Termine</h4>
                <table class="widefat" id="tix-series-dates-table">
                    <thead>
                        <tr>
                            <th>Startdatum</th>
                            <th>Enddatum (optional)</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($manual_dates)) $manual_dates = [['date_start' => '', 'date_end' => '']];
                        foreach ($manual_dates as $mi => $md):
                        ?>
                        <tr>
                            <td><input type="date" name="tix_series_dates[<?php echo $mi; ?>][date_start]" value="<?php echo esc_attr($md['date_start'] ?? ''); ?>" style="width:100%"></td>
                            <td><input type="date" name="tix_series_dates[<?php echo $mi; ?>][date_end]" value="<?php echo esc_attr($md['date_end'] ?? ''); ?>" style="width:100%"></td>
                            <td><button type="button" class="button tix-series-rm-date" title="Entfernen">&times;</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="button" id="tix-series-add-date" style="margin-top:8px">+ Termin hinzufügen</button>
            </div>

            <?php // ── Bestehende Termine ── ?>
            <?php if (!empty($children)): ?>
            <div class="tix-card" style="margin-top:12px;">
                <h4 style="margin:0 0 12px">Generierte Termine (<?php echo count($children); ?>)</h4>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Datum</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($children as $ci => $child_id):
                            $child = get_post($child_id);
                            if (!$child) continue;
                            $cds = get_post_meta($child_id, '_tix_date_start', true);
                            $detached = get_post_meta($child_id, '_tix_series_detached', true) === '1';
                            $statuses = ['publish' => 'Veröffentlicht', 'draft' => 'Entwurf', 'trash' => 'Papierkorb'];
                            $status_label = $statuses[$child->post_status] ?? $child->post_status;
                        ?>
                        <tr>
                            <td><?php echo $ci + 1; ?></td>
                            <td><?php echo $cds ? esc_html(date_i18n('D, d.m.Y', strtotime($cds))) : '—'; ?></td>
                            <td>
                                <?php echo esc_html($status_label); ?>
                                <?php if ($detached): ?><span class="tix-hint" style="color:#d97706"> (getrennt)</span><?php endif; ?>
                            </td>
                            <td><a href="<?php echo esc_url(get_edit_post_link($child_id)); ?>" class="button button-small">Bearbeiten</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="tix-hint" style="margin-top:8px">Termine werden beim Speichern erstellt/aktualisiert. Termine mit Ticketverkäufen werden nicht gelöscht, sondern abgetrennt.</p>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Gästeliste
    // ──────────────────────────────────────────
    public static function render_guestlist($post) {
        $enabled  = get_post_meta($post->ID, '_tix_guest_list_enabled', true);
        $password = get_post_meta($post->ID, '_tix_checkin_password', true);
        $guests   = get_post_meta($post->ID, '_tix_guest_list', true);
        if (!is_array($guests)) $guests = [];

        $total      = count($guests);
        $checked_in = 0;
        foreach ($guests as $g) {
            if (!empty($g['checked_in'])) $checked_in++;
        }
        $open = $total - $checked_in;
        ?>

        <!-- Toggle + Passwort -->
        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-groups"></span>
                <h3>Gästeliste</h3>
                <?php self::tip('Gäste ohne Ticketkauf auf die Liste setzen. QR-Code wird automatisch generiert und kann am Einlass gescannt werden.'); ?>
            </div>
            <div class="tix-card-body">
                <div class="tix-toggle-wrap">
                    <label class="tix-toggle-label">
                        <input type="hidden" name="tix_guest_list_enabled" value="0">
                        <input type="checkbox" name="tix_guest_list_enabled" value="1" id="tix-gl-toggle"
                            <?php checked($enabled, '1'); ?>>
                        <span class="tix-toggle-switch"></span>
                        Gästeliste aktivieren
                    </label>
                </div>
                <div id="tix-gl-panel" style="<?php echo $enabled !== '1' ? 'display:none;' : ''; ?>margin-top:16px;">
                    <div class="tix-field" style="max-width:300px;">
                        <label class="tix-field-label">Check-in Passwort <?php self::tip('Dieses Passwort wird vom Türpersonal auf der Check-in-Seite eingegeben. Kein WordPress-Login nötig.'); ?></label>
                        <input type="text" name="tix_checkin_password" value="<?php echo esc_attr($password); ?>" placeholder="z.B. tuer2026" autocomplete="off">
                    </div>
                </div>
            </div>
        </div>

        <!-- Übersicht + Tabelle -->
        <div id="tix-gl-panel-content" style="<?php echo $enabled !== '1' ? 'display:none;' : ''; ?>">

            <?php if ($total > 0): ?>
            <div class="tix-gl-overview">
                <div class="tix-gl-stat">
                    <span class="tix-gl-stat-num" id="tix-gl-total"><?php echo $total; ?></span>
                    <span class="tix-gl-stat-lbl">Gäste</span>
                </div>
                <div class="tix-gl-stat">
                    <span class="tix-gl-stat-num tix-gl-stat-ok" id="tix-gl-checked"><?php echo $checked_in; ?></span>
                    <span class="tix-gl-stat-lbl">Eingecheckt</span>
                </div>
                <div class="tix-gl-stat">
                    <span class="tix-gl-stat-num" id="tix-gl-open"><?php echo $open; ?></span>
                    <span class="tix-gl-stat-lbl">Offen</span>
                </div>
            </div>
            <?php endif; ?>

            <table class="widefat tix-tbl tix-gl-tbl" id="tix-gl-table">
                <thead>
                    <tr>
                        <th style="width:22%">Name *</th>
                        <th style="width:18%">E-Mail</th>
                        <th style="width:5%">+1</th>
                        <th style="width:18%">Notiz</th>
                        <th style="width:10%">QR</th>
                        <th style="width:12%">Status</th>
                        <th style="width:15%">Aktionen</th>
                    </tr>
                </thead>
                <tbody id="tix-gl-rows">
                    <?php if (!empty($guests)):
                        foreach ($guests as $i => $g):
                            $qr_code = 'GL-' . $post->ID . '-' . ($g['id'] ?? '');
                            $is_checked = !empty($g['checked_in']);
                            ?>
                            <tr class="tix-gl-row<?php echo $is_checked ? ' tix-gl-checked' : ''; ?>" data-guest-id="<?php echo esc_attr($g['id'] ?? ''); ?>">
                                <td>
                                    <input type="text" name="tix_guest_list[<?php echo $i; ?>][name]"
                                           value="<?php echo esc_attr($g['name'] ?? ''); ?>"
                                           placeholder="Name eingeben…" style="width:100%" required>
                                    <input type="hidden" name="tix_guest_list[<?php echo $i; ?>][id]"
                                           value="<?php echo esc_attr($g['id'] ?? ''); ?>">
                                </td>
                                <td>
                                    <input type="email" name="tix_guest_list[<?php echo $i; ?>][email]"
                                           value="<?php echo esc_attr($g['email'] ?? ''); ?>"
                                           placeholder="Optional" style="width:100%">
                                </td>
                                <td>
                                    <input type="number" name="tix_guest_list[<?php echo $i; ?>][plus]"
                                           value="<?php echo intval($g['plus'] ?? 0); ?>"
                                           min="0" max="10" style="width:100%">
                                </td>
                                <td>
                                    <input type="text" name="tix_guest_list[<?php echo $i; ?>][note]"
                                           value="<?php echo esc_attr($g['note'] ?? ''); ?>"
                                           placeholder="z.B. VIP Tisch 4" style="width:100%">
                                </td>
                                <td class="tix-gl-qr-cell">
                                    <canvas class="tix-gl-qr" data-qr="<?php echo esc_attr($qr_code); ?>" width="52" height="52"></canvas>
                                </td>
                                <td class="tix-gl-status-cell">
                                    <?php if ($is_checked): ?>
                                        <span class="tix-gl-badge tix-gl-badge-ok" title="<?php echo esc_attr($g['checkin_time'] ?? ''); ?>">✓ Eingecheckt</span>
                                    <?php else: ?>
                                        <span class="tix-gl-badge tix-gl-badge-open">○ Offen</span>
                                    <?php endif; ?>
                                </td>
                                <td class="tix-gl-actions">
                                    <button type="button" class="button tix-gl-checkin" data-event="<?php echo $post->ID; ?>" data-guest="<?php echo esc_attr($g['id'] ?? ''); ?>" title="<?php echo $is_checked ? 'Check-in rückgängig' : 'Einchecken'; ?>">
                                        <?php echo $is_checked ? '↩' : '✓'; ?>
                                    </button>
                                    <?php if (!empty($g['email'])): ?>
                                    <button type="button" class="button tix-gl-send-email" data-event="<?php echo $post->ID; ?>" data-guest="<?php echo esc_attr($g['id'] ?? ''); ?>" title="QR per E-Mail senden">✉</button>
                                    <?php endif; ?>
                                    <button type="button" class="button tix-gl-del" title="Gast entfernen">&times;</button>
                                </td>
                            </tr>
                        <?php endforeach;
                    else: ?>
                        <tr class="tix-gl-empty"><td colspan="7" style="text-align:center;color:#999;padding:16px;">Noch keine Gäste. Klicke unten auf „+ Gast hinzufügen".</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="tix-gl-footer">
                <button type="button" class="button" id="tix-gl-add">+ Gast hinzufügen</button>
                <button type="button" class="button" id="tix-gl-csv-import">CSV importieren</button>
                <button type="button" class="button" id="tix-gl-csv-export">CSV exportieren</button>
                <input type="file" id="tix-gl-csv-file" accept=".csv,.txt" style="display:none;">
                <?php
                $emails_count = 0;
                foreach ($guests as $g) { if (!empty($g['email'])) $emails_count++; }
                if ($emails_count > 0): ?>
                <button type="button" class="button" id="tix-gl-send-all" data-event="<?php echo $post->ID; ?>" title="QR-Code an alle Gäste mit E-Mail senden">✉ Alle benachrichtigen (<?php echo $emails_count; ?>)</button>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Programm / Timetable
    // ──────────────────────────────────────────
    public static function render_timetable($post) {
        $stages    = get_post_meta($post->ID, '_tix_stages', true);
        $timetable = get_post_meta($post->ID, '_tix_timetable', true);
        $times_tba = get_post_meta($post->ID, '_tix_timetable_times_tba', true);
        if (!is_array($stages)) $stages = [];
        if (!is_array($timetable)) $timetable = [];

        // Event-Datumsbereich für Tage
        $date_start = get_post_meta($post->ID, '_tix_date_start', true);
        $date_end   = get_post_meta($post->ID, '_tix_date_end', true);
        if (!$date_end) $date_end = $date_start;

        // Tage generieren
        $days = [];
        if ($date_start) {
            $ds = date_create($date_start);
            $de = date_create($date_end ?: $date_start);
            if ($ds && $de) {
                while ($ds <= $de) {
                    $days[] = $ds->format('Y-m-d');
                    $ds->modify('+1 day');
                }
            }
        }
        if (empty($days)) {
            $days = array_keys($timetable);
            if (empty($days)) $days = [date('Y-m-d')];
            sort($days);
        }
        ?>
        <p class="description" style="margin-bottom:14px;">
            Erstelle das Veranstaltungsprogramm mit mehreren Bühnen/Räumen. Definiere zuerst die Bühnen, dann die einzelnen Programmslots pro Tag.
        </p>

        <p style="margin-bottom:14px;">
            <label>
                <input type="checkbox" name="tix_timetable_times_tba" value="1" <?php checked($times_tba, '1'); ?>>
                Hinweis anzeigen: „Uhrzeiten werden noch bekanntgegeben"
            </label>
            <span class="description" style="margin-left:6px;">Wird angezeigt, wenn keine Uhrzeiten eingetragen sind.</span>
        </p>

        <?php // ── Bühnen ── ?>
        <div style="margin-bottom:16px;">
            <label class="tix-field-label" style="margin-bottom:6px;display:block;">Bühnen / Räume</label>
            <div id="tix-tt-stages">
                <?php if (!empty($stages)):
                    foreach ($stages as $si => $stage): ?>
                    <div class="tix-tt-stage-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                        <input type="text" name="tix_stages[<?php echo $si; ?>][name]" value="<?php echo esc_attr($stage['name'] ?? ''); ?>" placeholder="Bühnenname" style="width:200px;" class="regular-text">
                        <input type="color" name="tix_stages[<?php echo $si; ?>][color]" value="<?php echo esc_attr($stage['color'] ?? '#FF5500'); ?>" style="width:40px;height:32px;padding:2px;cursor:pointer;">
                        <button type="button" class="button tix-tt-stage-del" title="Entfernen">&times;</button>
                    </div>
                    <?php endforeach;
                endif; ?>
            </div>
            <button type="button" class="button" id="tix-tt-stage-add">+ Bühne hinzufügen</button>
        </div>

        <?php // ── Tages-Tabs + Slots ── ?>
        <div style="margin-bottom:8px;">
            <label class="tix-field-label" style="margin-bottom:6px;display:block;">Programm-Slots</label>
            <div id="tix-tt-day-tabs" style="display:flex;gap:4px;margin-bottom:8px;flex-wrap:wrap;">
                <?php foreach ($days as $di => $day):
                    $dt = date_create($day);
                    $label = $dt ? $dt->format('D d.m.') : $day;
                    $label = str_replace(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], ['Mo','Di','Mi','Do','Fr','Sa','So'], $label);
                ?>
                    <button type="button" class="button tix-tt-day-tab<?php echo $di === 0 ? ' button-primary' : ''; ?>" data-day="<?php echo esc_attr($day); ?>"><?php echo esc_html($label); ?></button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($days as $di => $day):
                $day_slots = $timetable[$day] ?? [];
            ?>
            <div class="tix-tt-day-pane" data-day="<?php echo esc_attr($day); ?>" style="<?php echo $di !== 0 ? 'display:none;' : ''; ?>">
                <table class="widefat tix-tbl" style="margin-bottom:8px;">
                    <thead>
                        <tr>
                            <th style="width:12%">Start</th>
                            <th style="width:12%">Ende</th>
                            <th style="width:18%">Bühne</th>
                            <th style="width:28%">Act / Titel</th>
                            <th style="width:22%">Beschreibung</th>
                            <th style="width:8%"></th>
                        </tr>
                    </thead>
                    <tbody class="tix-tt-slots">
                        <?php if (!empty($day_slots)):
                            foreach ($day_slots as $si => $slot): ?>
                            <tr class="tix-tt-slot-row">
                                <td><input type="time" name="tix_timetable[<?php echo $day; ?>][<?php echo $si; ?>][time]" value="<?php echo esc_attr($slot['time'] ?? ''); ?>" style="width:100%"></td>
                                <td><input type="time" name="tix_timetable[<?php echo $day; ?>][<?php echo $si; ?>][end]" value="<?php echo esc_attr($slot['end'] ?? ''); ?>" style="width:100%"></td>
                                <td>
                                    <select name="tix_timetable[<?php echo $day; ?>][<?php echo $si; ?>][stage]" style="width:100%" class="tix-tt-stage-select">
                                        <?php foreach ($stages as $sti => $st): ?>
                                            <option value="<?php echo $sti; ?>" <?php selected(intval($slot['stage'] ?? 0), $sti); ?>><?php echo esc_html($st['name'] ?? 'Bühne ' . ($sti + 1)); ?></option>
                                        <?php endforeach; ?>
                                        <?php if (empty($stages)): ?>
                                            <option value="0">Standard</option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="tix_timetable[<?php echo $day; ?>][<?php echo $si; ?>][title]" value="<?php echo esc_attr($slot['title'] ?? ''); ?>" placeholder="z.B. DJ Name" style="width:100%"></td>
                                <td><input type="text" name="tix_timetable[<?php echo $day; ?>][<?php echo $si; ?>][desc]" value="<?php echo esc_attr($slot['desc'] ?? ''); ?>" placeholder="Optional" style="width:100%"></td>
                                <td><button type="button" class="button tix-tt-slot-del" title="Entfernen">&times;</button></td>
                            </tr>
                            <?php endforeach;
                        else: ?>
                            <tr class="tix-tt-slot-empty"><td colspan="6" style="text-align:center;color:#999;padding:12px;">Noch keine Einträge.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <button type="button" class="button tix-tt-slot-add" data-day="<?php echo esc_attr($day); ?>">+ Slot hinzufügen</button>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function(){
            /* ── Bühnen Repeater ── */
            var stagesWrap = document.getElementById('tix-tt-stages');
            var stageAdd = document.getElementById('tix-tt-stage-add');
            if (stageAdd) {
                stageAdd.addEventListener('click', function(){
                    var idx = stagesWrap.querySelectorAll('.tix-tt-stage-row').length;
                    var div = document.createElement('div');
                    div.className = 'tix-tt-stage-row';
                    div.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';
                    div.innerHTML =
                        '<input type="text" name="tix_stages['+idx+'][name]" placeholder="Bühnenname" style="width:200px;" class="regular-text">' +
                        '<input type="color" name="tix_stages['+idx+'][color]" value="#FF5500" style="width:40px;height:32px;padding:2px;cursor:pointer;">' +
                        '<button type="button" class="button tix-tt-stage-del" title="Entfernen">&times;</button>';
                    stagesWrap.appendChild(div);
                });
                stagesWrap.addEventListener('click', function(e){
                    if (e.target.classList.contains('tix-tt-stage-del')) {
                        e.target.closest('.tix-tt-stage-row').remove();
                    }
                });
            }

            /* ── Tages-Tabs ── */
            var dayTabs = document.getElementById('tix-tt-day-tabs');
            if (dayTabs) {
                dayTabs.querySelectorAll('.tix-tt-day-tab').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        dayTabs.querySelectorAll('.tix-tt-day-tab').forEach(function(b){
                            b.classList.remove('button-primary');
                        });
                        btn.classList.add('button-primary');
                        var day = btn.dataset.day;
                        document.querySelectorAll('.tix-tt-day-pane').forEach(function(p){
                            p.style.display = p.dataset.day === day ? '' : 'none';
                        });
                    });
                });
            }

            /* ── Slot Repeater ── */
            document.querySelectorAll('.tix-tt-slot-add').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var day = btn.dataset.day;
                    var tbody = btn.previousElementSibling.querySelector('.tix-tt-slots');
                    var empty = tbody.querySelector('.tix-tt-slot-empty');
                    if (empty) empty.remove();
                    var idx = tbody.querySelectorAll('.tix-tt-slot-row').length;
                    var stageRows = stagesWrap.querySelectorAll('.tix-tt-stage-row');
                    var options = '';
                    if (stageRows.length > 0) {
                        stageRows.forEach(function(sr, si){
                            var name = sr.querySelector('input[type="text"]').value || 'Bühne '+(si+1);
                            options += '<option value="'+si+'">'+name+'</option>';
                        });
                    } else {
                        options = '<option value="0">Standard</option>';
                    }
                    var tr = document.createElement('tr');
                    tr.className = 'tix-tt-slot-row';
                    tr.innerHTML =
                        '<td><input type="time" name="tix_timetable['+day+']['+idx+'][time]" style="width:100%"></td>' +
                        '<td><input type="time" name="tix_timetable['+day+']['+idx+'][end]" style="width:100%"></td>' +
                        '<td><select name="tix_timetable['+day+']['+idx+'][stage]" style="width:100%" class="tix-tt-stage-select">'+options+'</select></td>' +
                        '<td><input type="text" name="tix_timetable['+day+']['+idx+'][title]" placeholder="z.B. DJ Name" style="width:100%"></td>' +
                        '<td><input type="text" name="tix_timetable['+day+']['+idx+'][desc]" placeholder="Optional" style="width:100%"></td>' +
                        '<td><button type="button" class="button tix-tt-slot-del" title="Entfernen">&times;</button></td>';
                    tbody.appendChild(tr);
                });
            });

            /* ── Slot löschen ── */
            document.addEventListener('click', function(e){
                if (e.target.classList.contains('tix-tt-slot-del')) {
                    e.target.closest('tr').remove();
                }
            });
        })();
        </script>
        <?php
    }

    // ──────────────────────────────────────────
    // Rabattcodes
    // ──────────────────────────────────────────
    public static function render_discounts($post) {
        $codes = get_post_meta($post->ID, '_tix_discount_codes', true);
        if (!is_array($codes)) $codes = [];

        // Aktuelle Nutzungszahlen laden
        foreach ($codes as &$c) {
            if (!empty($c['coupon_id'])) {
                $coupon = new \WC_Coupon($c['coupon_id']);
                if ($coupon->get_id()) {
                    $c['usage'] = $coupon->get_usage_count();
                }
            }
            if (!isset($c['usage'])) $c['usage'] = 0;
        }
        unset($c);
        ?>
        <p class="description" style="margin-bottom:10px;">
            Erstelle Event-spezifische Rabattcodes. Jeder Code wird als WooCommerce-Gutschein angelegt und gilt nur für Tickets dieses Events.
        </p>

        <table class="widefat tix-tbl" id="tix-discount-table">
            <thead>
                <tr>
                    <th style="width:20%">Code</th>
                    <th style="width:14%">Typ</th>
                    <th style="width:10%">Wert</th>
                    <th style="width:10%">Limit</th>
                    <th style="width:16%">Ablaufdatum</th>
                    <th style="width:10%">Genutzt</th>
                    <th style="width:10%"></th>
                </tr>
            </thead>
            <tbody id="tix-discount-rows">
                <?php if (!empty($codes)):
                    foreach ($codes as $i => $code): ?>
                        <tr class="tix-discount-row">
                            <td>
                                <input type="text" name="tix_discounts[<?php echo $i; ?>][code]"
                                       value="<?php echo esc_attr($code['code'] ?? ''); ?>"
                                       placeholder="z.B. EARLY20" style="width:100%;text-transform:uppercase;" autocomplete="off">
                                <input type="hidden" name="tix_discounts[<?php echo $i; ?>][coupon_id]"
                                       value="<?php echo intval($code['coupon_id'] ?? 0); ?>">
                            </td>
                            <td>
                                <select name="tix_discounts[<?php echo $i; ?>][type]" style="width:100%">
                                    <option value="percent" <?php selected($code['type'] ?? '', 'percent'); ?>>Prozent (%)</option>
                                    <option value="fixed_cart" <?php selected($code['type'] ?? '', 'fixed_cart'); ?>>Festbetrag (€)</option>
                                </select>
                            </td>
                            <td>
                                <input type="number" name="tix_discounts[<?php echo $i; ?>][amount]"
                                       value="<?php echo esc_attr($code['amount'] ?? ''); ?>"
                                       min="0" step="0.01" style="width:100%" placeholder="20">
                            </td>
                            <td>
                                <input type="number" name="tix_discounts[<?php echo $i; ?>][limit]"
                                       value="<?php echo esc_attr($code['limit'] ?? ''); ?>"
                                       min="0" step="1" style="width:100%" placeholder="0=∞">
                            </td>
                            <td>
                                <input type="date" name="tix_discounts[<?php echo $i; ?>][expiry]"
                                       value="<?php echo esc_attr($code['expiry'] ?? ''); ?>"
                                       style="width:100%">
                            </td>
                            <td style="text-align:center;">
                                <span style="font-weight:600;<?php echo intval($code['usage'] ?? 0) > 0 ? 'color:#059669;' : 'color:#94a3b8;'; ?>">
                                    <?php echo intval($code['usage'] ?? 0); ?><?php if (!empty($code['limit']) && intval($code['limit']) > 0): ?>/<?php echo intval($code['limit']); ?><?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="button tix-discount-del" title="Entfernen">&times;</button>
                            </td>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr class="tix-discount-empty"><td colspan="7" style="text-align:center;color:#999;padding:16px;">Noch keine Rabattcodes. Klicke unten auf „+ Code hinzufügen".</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:10px;display:flex;gap:8px;">
            <button type="button" class="button" id="tix-discount-add">+ Code hinzufügen</button>
            <button type="button" class="button" id="tix-discount-generate">🎲 Zufallscode generieren</button>
        </p>

        <script>
        (function(){
            var tbody = document.getElementById('tix-discount-rows');
            var addBtn = document.getElementById('tix-discount-add');
            var genBtn = document.getElementById('tix-discount-generate');
            if (!tbody || !addBtn) return;

            function getNextIndex() {
                var rows = tbody.querySelectorAll('.tix-discount-row');
                return rows.length;
            }

            function addRow(code) {
                var empty = tbody.querySelector('.tix-discount-empty');
                if (empty) empty.remove();
                var idx = getNextIndex();
                var tr = document.createElement('tr');
                tr.className = 'tix-discount-row';
                tr.innerHTML =
                    '<td><input type="text" name="tix_discounts['+idx+'][code]" value="'+(code||'')+'" placeholder="z.B. EARLY20" style="width:100%;text-transform:uppercase;" autocomplete="off"><input type="hidden" name="tix_discounts['+idx+'][coupon_id]" value="0"></td>' +
                    '<td><select name="tix_discounts['+idx+'][type]" style="width:100%"><option value="percent">Prozent (%)</option><option value="fixed_cart">Festbetrag (€)</option></select></td>' +
                    '<td><input type="number" name="tix_discounts['+idx+'][amount]" min="0" step="0.01" style="width:100%" placeholder="20"></td>' +
                    '<td><input type="number" name="tix_discounts['+idx+'][limit]" min="0" step="1" style="width:100%" placeholder="0=∞"></td>' +
                    '<td><input type="date" name="tix_discounts['+idx+'][expiry]" style="width:100%"></td>' +
                    '<td style="text-align:center;"><span style="color:#94a3b8;">0</span></td>' +
                    '<td><button type="button" class="button tix-discount-del" title="Entfernen">&times;</button></td>';
                tbody.appendChild(tr);
            }

            addBtn.addEventListener('click', function(){ addRow(''); });

            genBtn.addEventListener('click', function(){
                var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                var code = '';
                for (var i = 0; i < 8; i++) code += chars.charAt(Math.floor(Math.random() * chars.length));
                addRow(code);
            });

            tbody.addEventListener('click', function(e){
                if (e.target.classList.contains('tix-discount-del')) {
                    e.target.closest('tr').remove();
                    if (!tbody.querySelector('.tix-discount-row')) {
                        var empty = document.createElement('tr');
                        empty.className = 'tix-discount-empty';
                        empty.innerHTML = '<td colspan="7" style="text-align:center;color:#999;padding:16px;">Noch keine Rabattcodes.</td>';
                        tbody.appendChild(empty);
                    }
                }
            });
        })();
        </script>
        <?php
    }

    // ──────────────────────────────────────────
    // Gewinnspiel (Raffle)
    // ──────────────────────────────────────────
    // ──────────────────────────────────────────
    // Tab: Kampagnen (Link-Generator)
    // ──────────────────────────────────────────

    public static function render_campaign_links($post) {
        $permalink = get_permalink($post->ID);
        $channels  = class_exists('TIX_Campaign_Tracking') ? TIX_Campaign_Tracking::get_all_channels() : [];

        // Icons für bekannte Kanäle
        $icons = [
            'instagram'  => '📸', 'tiktok'    => '🎵', 'facebook'  => '📘',
            'linkedin'   => '💼', 'xing'      => '🔷', 'whatsapp'  => '💬',
            'youtube'    => '▶️',  'email'     => '📧', 'google_ads'=> '🔍',
            'flyer'      => '📄', 'website'   => '🌐', 'twitter'   => '🐦',
            'podcast'    => '🎙️', 'telegram'  => '✈️',
        ];
        ?>
        <div class="tix-expert-section">
            <h3>🔗 Marketing-Links</h3>
            <p class="description" style="margin-bottom:16px">
                Verwende diese Links in deinen Social-Media-Posts, Newslettern und Werbung.
                Jeder Klick wird automatisch dem Kanal zugeordnet &ndash; du siehst unter
                <strong>Tixomat &rarr; Kampagnen</strong>, welcher Kanal Besucher und Tickets bringt.
            </p>

            <div id="tix-campaign-links" style="display:grid;gap:8px;max-width:700px">
                <?php foreach ($channels as $slug => $label):
                    $url = add_query_arg('tix_src', $slug, $permalink);
                    $icon = $icons[$slug] ?? '📌';
                ?>
                <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
                    <span style="font-size:18px;width:24px;text-align:center"><?php echo $icon; ?></span>
                    <strong style="min-width:110px;font-size:13px"><?php echo esc_html($label); ?></strong>
                    <input type="text" readonly value="<?php echo esc_attr($url); ?>"
                           class="tix-campaign-url"
                           style="flex:1;font-size:12px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;background:#fff;font-family:monospace;cursor:pointer"
                           onclick="this.select(); navigator.clipboard.writeText(this.value).then(function(){});">
                    <button type="button" class="button button-small tix-copy-campaign-url"
                            data-url="<?php echo esc_attr($url); ?>"
                            style="flex-shrink:0;font-size:11px">📋 Kopieren</button>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:20px;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;max-width:700px">
                <h4 style="margin:0 0 8px;font-size:13px">➕ Kampagnen-Name hinzuf&uuml;gen</h4>
                <p class="description" style="margin-bottom:10px">
                    Nutze Kampagnen-Namen um verschiedene Posts auf dem gleichen Kanal zu unterscheiden.
                </p>
                <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px">Kanal</label>
                        <select id="tix-camp-channel" style="min-width:140px">
                            <?php foreach ($channels as $slug => $label): ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px">Kampagne</label>
                        <input type="text" id="tix-camp-name" placeholder="z.B. summer_sale" style="width:180px">
                    </div>
                    <button type="button" class="button" id="tix-camp-generate">Link generieren</button>
                </div>
                <div id="tix-camp-result" style="margin-top:10px;display:none">
                    <input type="text" readonly id="tix-camp-result-url"
                           style="width:100%;font-size:12px;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;background:#fff;font-family:monospace;cursor:pointer"
                           onclick="this.select(); navigator.clipboard.writeText(this.value).then(function(){});">
                </div>
            </div>

            <div style="margin-top:20px;padding:16px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;max-width:700px">
                <h4 style="margin:0 0 4px;font-size:13px">💡 Tipp: UTM-Parameter</h4>
                <p class="description" style="margin:0">
                    Standard UTM-Parameter (<code>?utm_source=...</code>) werden ebenfalls erkannt.
                    Bestehende UTM-Links funktionieren weiterhin &ndash; <code>tix_src</code> hat aber Priorit&auml;t.
                </p>
            </div>
        </div>

        <script>
        (function(){
            var permalink = <?php echo wp_json_encode($permalink); ?>;

            // Copy buttons
            document.querySelectorAll('.tix-copy-campaign-url').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var url = this.getAttribute('data-url');
                    navigator.clipboard.writeText(url).then(function() {
                        btn.textContent = '✓ Kopiert!';
                        setTimeout(function() { btn.textContent = '📋 Kopieren'; }, 2000);
                    });
                });
            });

            // Campaign link generator
            var genBtn = document.getElementById('tix-camp-generate');
            if (genBtn) {
                genBtn.addEventListener('click', function() {
                    var ch = document.getElementById('tix-camp-channel').value;
                    var camp = document.getElementById('tix-camp-name').value.trim().replace(/\s+/g, '_').toLowerCase();
                    var url = permalink + (permalink.indexOf('?') > -1 ? '&' : '?') + 'tix_src=' + ch;
                    if (camp) url += '&tix_camp=' + encodeURIComponent(camp);
                    var result = document.getElementById('tix-camp-result');
                    var input = document.getElementById('tix-camp-result-url');
                    input.value = url;
                    result.style.display = 'block';
                    input.select();
                    navigator.clipboard.writeText(url).catch(function(){});
                });
            }
        })();
        </script>
        <?php
    }

    public static function render_raffle($post) {
        $enabled      = get_post_meta($post->ID, '_tix_raffle_enabled', true);
        $title        = get_post_meta($post->ID, '_tix_raffle_title', true) ?: '';
        $description  = get_post_meta($post->ID, '_tix_raffle_description', true) ?: '';
        $end_date     = get_post_meta($post->ID, '_tix_raffle_end_date', true) ?: '';
        $max_entries  = get_post_meta($post->ID, '_tix_raffle_max_entries', true) ?: '';
        $hide_count   = get_post_meta($post->ID, '_tix_raffle_hide_count', true);
        $consent_text = get_post_meta($post->ID, '_tix_raffle_consent_text', true) ?: '';
        $header_bg    = get_post_meta($post->ID, '_tix_raffle_header_bg', true) ?: '';
        $header_color = get_post_meta($post->ID, '_tix_raffle_header_color', true) ?: '';
        $status       = get_post_meta($post->ID, '_tix_raffle_status', true) ?: 'open';
        $prizes      = get_post_meta($post->ID, '_tix_raffle_prizes', true);
        $winners     = get_post_meta($post->ID, '_tix_raffle_winners', true);
        $drawn_at    = get_post_meta($post->ID, '_tix_raffle_drawn_at', true);

        if (!is_array($prizes)) $prizes = [];

        // Teilnehmer-Zahl
        $entry_count = 0;
        if ($enabled === '1' && class_exists('TIX_Raffle')) {
            $entry_count = TIX_Raffle::count_entries($post->ID);
        }

        // Ticket-Kategorien für "Freikarte"-Typ
        $categories = get_post_meta($post->ID, '_tix_ticket_categories', true);
        if (!is_array($categories)) $categories = [];
        ?>
        <div class="tix-raffle-admin">

            <?php // ── Aktivierung ── ?>
            <p>
                <label>
                    <input type="checkbox" name="tix_raffle_enabled" value="1" <?php checked($enabled, '1'); ?> id="tix-raffle-toggle">
                    <strong>Gewinnspiel für dieses Event aktivieren</strong>
                </label>
            </p>

            <div id="tix-raffle-options" style="<?php echo $enabled !== '1' ? 'display:none;' : ''; ?>">

                <?php // ── Status-Badge ── ?>
                <div style="margin-bottom:16px;">
                    <?php
                    $status_labels = ['open' => 'Offen', 'closed' => 'Geschlossen', 'drawn' => 'Ausgelost'];
                    $status_colors = ['open' => '#16a34a', 'closed' => '#d97706', 'drawn' => '#FF5500'];
                    $s_color = $status_colors[$status] ?? '#64748b';
                    $s_label = $status_labels[$status] ?? $status;
                    ?>
                    <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:6px;font-size:.85rem;font-weight:600;background:<?php echo $s_color; ?>15;color:<?php echo $s_color; ?>;border:1px solid <?php echo $s_color; ?>40;">
                        ● <?php echo esc_html($s_label); ?>
                    </span>
                    <?php if ($entry_count > 0): ?>
                        <span style="margin-left:12px;font-size:.9rem;color:#64748b;">
                            <?php echo $entry_count; ?> Teilnehmer
                        </span>
                    <?php endif; ?>
                    <?php if ($drawn_at): ?>
                        <span style="margin-left:12px;font-size:.85rem;color:#64748b;">
                            Ausgelost am <?php echo date_i18n('d.m.Y H:i', strtotime($drawn_at)); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php // ── Titel ── ?>
                <table class="form-table tix-form-table">
                    <tr>
                        <th><label for="tix-raffle-title">Titel</label></th>
                        <td>
                            <input type="text" id="tix-raffle-title" name="tix_raffle_title"
                                   value="<?php echo esc_attr($title); ?>"
                                   placeholder="Gewinnspiel" class="regular-text" style="width:100%;">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tix-raffle-desc">Beschreibung</label></th>
                        <td>
                            <textarea id="tix-raffle-desc" name="tix_raffle_description"
                                      rows="3" class="large-text"
                                      placeholder="Teilnahmebedingungen, Ablauf, etc."><?php echo esc_textarea($description); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tix-raffle-end">Teilnahmeschluss</label></th>
                        <td>
                            <input type="datetime-local" id="tix-raffle-end" name="tix_raffle_end_date"
                                   value="<?php echo esc_attr($end_date); ?>" class="regular-text">
                            <?php self::tip('Nach diesem Zeitpunkt wird die Teilnahme automatisch geschlossen und die Gewinner werden ausgelost.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tix-raffle-max">Max. Teilnehmer</label></th>
                        <td>
                            <input type="number" id="tix-raffle-max" name="tix_raffle_max_entries"
                                   value="<?php echo esc_attr($max_entries); ?>"
                                   min="0" step="1" class="small-text" placeholder="0">
                            <span class="description">0 = unbegrenzt</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tix-raffle-hide-count">Teilnehmer verbergen</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="tix-raffle-hide-count" name="tix_raffle_hide_count" value="1" <?php checked($hide_count, '1'); ?>>
                                Teilnehmerzahl im Frontend nicht anzeigen
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tix-raffle-consent">Zustimmungstext</label></th>
                        <td>
                            <textarea id="tix-raffle-consent" name="tix_raffle_consent_text"
                                      rows="2" class="large-text"
                                      placeholder="Ich stimme den Teilnahmebedingungen zu und akzeptiere die Datenschutzerklärung."><?php echo esc_textarea($consent_text); ?></textarea>
                            <p class="description">Checkbox-Text für die Zustimmung. Leer = keine Checkbox.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tix-raffle-header-bg">Header-Farbe</label></th>
                        <td>
                            <input type="color" id="tix-raffle-header-bg"
                                   value="<?php echo esc_attr($header_bg ?: '#FF5500'); ?>"
                                   style="width:50px;height:34px;padding:2px;cursor:pointer;vertical-align:middle;">
                            <input type="text" name="tix_raffle_header_bg" value="<?php echo esc_attr($header_bg); ?>"
                                   placeholder="#FF5500" class="small-text" style="margin-left:6px;vertical-align:middle;"
                                   id="tix-raffle-header-bg-text">
                            <span class="description" style="margin-left:6px;">Hintergrundfarbe des Titelbereichs</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tix-raffle-header-color">Textfarbe Header</label></th>
                        <td>
                            <input type="color" id="tix-raffle-header-color"
                                   value="<?php echo esc_attr($header_color ?: '#ffffff'); ?>"
                                   style="width:50px;height:34px;padding:2px;cursor:pointer;vertical-align:middle;">
                            <input type="text" name="tix_raffle_header_color" value="<?php echo esc_attr($header_color); ?>"
                                   placeholder="#ffffff" class="small-text" style="margin-left:6px;vertical-align:middle;"
                                   id="tix-raffle-header-color-text">
                            <span class="description" style="margin-left:6px;">Textfarbe von Titel &amp; Beschreibung</span>
                        </td>
                    </tr>
                    <script>
                    jQuery(function($) {
                        // Header BG sync
                        $('#tix-raffle-header-bg').on('input', function() {
                            $('#tix-raffle-header-bg-text').val(this.value);
                        });
                        $('#tix-raffle-header-bg-text').on('input', function() {
                            var v = $(this).val();
                            if (/^#[0-9A-Fa-f]{6}$/.test(v)) $('#tix-raffle-header-bg').val(v);
                        });
                        // Header Color sync
                        $('#tix-raffle-header-color').on('input', function() {
                            $('#tix-raffle-header-color-text').val(this.value);
                        });
                        $('#tix-raffle-header-color-text').on('input', function() {
                            var v = $(this).val();
                            if (/^#[0-9A-Fa-f]{6}$/.test(v)) $('#tix-raffle-header-color').val(v);
                        });
                    });
                    </script>
                </table>

                <?php // ── Preise (Repeater) ── ?>
                <h3 style="margin:24px 0 10px;font-size:.95rem;">Preise</h3>
                <p class="description" style="margin-bottom:10px;">
                    Definiere die Preise. "Gewinner" = Anzahl der Gewinner, "pro Gewinner" = Stück pro Gewinner (z.B. 2 × 2 Karten). Typ "Freikarte" erstellt automatisch ein Ticket.
                </p>
                <table class="widefat tix-tbl" id="tix-raffle-prize-table">
                    <thead>
                        <tr>
                            <th style="width:3%"></th>
                            <th style="width:30%">Preis-Name</th>
                            <th style="width:10%">Gewinner</th>
                            <th style="width:10%">pro Gewinner</th>
                            <th style="width:16%">Typ</th>
                            <th style="width:21%">Kategorie</th>
                            <th style="width:10%"></th>
                        </tr>
                    </thead>
                    <tbody id="tix-raffle-prize-rows">
                        <?php if (!empty($prizes)):
                            foreach ($prizes as $i => $p): ?>
                                <tr class="tix-raffle-prize-row" draggable="true">
                                    <td class="tix-faq-drag" title="Reihenfolge ändern">☰</td>
                                    <td>
                                        <input type="text" name="tix_raffle_prizes[<?php echo $i; ?>][name]"
                                               value="<?php echo esc_attr($p['name'] ?? ''); ?>"
                                               placeholder="z.B. VIP-Upgrade" style="width:100%">
                                    </td>
                                    <td>
                                        <input type="number" name="tix_raffle_prizes[<?php echo $i; ?>][qty]"
                                               value="<?php echo intval($p['qty'] ?? 1); ?>"
                                               min="1" step="1" style="width:100%">
                                    </td>
                                    <td>
                                        <input type="number" name="tix_raffle_prizes[<?php echo $i; ?>][per_winner]"
                                               value="<?php echo intval($p['per_winner'] ?? 1); ?>"
                                               min="1" step="1" style="width:100%">
                                    </td>
                                    <td>
                                        <select name="tix_raffle_prizes[<?php echo $i; ?>][type]" class="tix-raffle-type-select" style="width:100%">
                                            <option value="text" <?php selected(($p['type'] ?? 'text'), 'text'); ?>>Freitext</option>
                                            <option value="ticket" <?php selected(($p['type'] ?? 'text'), 'ticket'); ?>>Freikarte</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="tix_raffle_prizes[<?php echo $i; ?>][cat_index]" class="tix-raffle-cat-select" style="width:100%;<?php echo ($p['type'] ?? 'text') !== 'ticket' ? 'display:none;' : ''; ?>">
                                            <?php foreach ($categories as $ci => $c): ?>
                                                <option value="<?php echo $ci; ?>" <?php selected(intval($p['cat_index'] ?? 0), $ci); ?>>
                                                    <?php echo esc_html($c['name'] ?? "Kategorie {$ci}"); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if (empty($categories)): ?>
                                                <option value="0">Keine Kategorien</option>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <button type="button" class="button tix-raffle-prize-del" title="Entfernen">&times;</button>
                                    </td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr class="tix-raffle-prize-empty"><td colspan="7" style="text-align:center;color:#999;padding:16px;">Noch keine Preise. Klicke unten auf „+ Preis hinzufügen".</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p style="margin-top:10px;">
                    <button type="button" class="button" id="tix-raffle-prize-add">+ Preis hinzufügen</button>
                </p>

                <?php // ── Auslosen-Button + Status-Reset ── ?>
                <div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;">
                    <?php if ($status !== 'drawn' && $entry_count > 0): ?>
                        <div style="margin-bottom:12px;">
                            <span style="font-size:13px;font-weight:500;margin-right:8px;">Video-Format:</span>
                            <span class="tix-raffle-format-wrap">
                                <label><input type="radio" name="tix_raffle_format" value="9:16" checked> <span>9:16</span></label>
                                <label><input type="radio" name="tix_raffle_format" value="1:1"> <span>1:1</span></label>
                                <label><input type="radio" name="tix_raffle_format" value="16:9"> <span>16:9</span></label>
                            </span>
                        </div>
                        <button type="button" class="button button-primary" id="tix-raffle-draw"
                                data-event="<?php echo $post->ID; ?>">
                            🎲 Jetzt auslosen (<?php echo $entry_count; ?> Teilnehmer)
                        </button>
                    <?php elseif ($status === 'drawn'): ?>
                        <p style="color:#FF5500;font-weight:600;">✅ Gewinner wurden bereits ausgelost.</p>
                        <button type="button" class="button" id="tix-raffle-reset"
                                data-event="<?php echo $post->ID; ?>"
                                style="margin-top:8px;">
                            Status zurücksetzen (auf "offen")
                        </button>
                    <?php else: ?>
                        <p style="color:#64748b;">Noch keine Teilnehmer vorhanden.</p>
                    <?php endif; ?>
                </div>

                <?php // ── Gewinnerliste (nach Auslosung) ── ?>
                <?php if ($status === 'drawn' && !empty($winners) && is_array($winners)): ?>
                    <div style="margin-top:20px;">
                        <h3 style="font-size:.95rem;margin:0 0 10px;">Gewinner</h3>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>E-Mail</th>
                                    <th>Preis</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($winners as $w): ?>
                                    <tr>
                                        <td><?php echo esc_html($w['name']); ?></td>
                                        <td><a href="mailto:<?php echo esc_attr($w['email']); ?>"><?php echo esc_html($w['email']); ?></a></td>
                                        <td><?php echo esc_html($w['prize_name'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </div>

            <?php // ── Inline JS für den Raffle-Tab ── ?>
            <script>
            jQuery(function($) {
                // Toggle
                $('#tix-raffle-toggle').on('change', function() {
                    $('#tix-raffle-options').toggle(this.checked);
                });

                // Preis hinzufügen
                var prizeIdx = <?php echo max(count($prizes), 0); ?>;
                $('#tix-raffle-prize-add').on('click', function() {
                    var cats = <?php echo wp_json_encode(array_map(function($c) { return $c['name'] ?? ''; }, $categories)); ?>;
                    var catOpts = '';
                    for (var i = 0; i < cats.length; i++) {
                        catOpts += '<option value="' + i + '">' + $('<span>').text(cats[i] || 'Kategorie ' + i).html() + '</option>';
                    }
                    if (!cats.length) catOpts = '<option value="0">Keine Kategorien</option>';

                    var row = '<tr class="tix-raffle-prize-row" draggable="true">' +
                        '<td class="tix-faq-drag" title="Reihenfolge ändern">☰</td>' +
                        '<td><input type="text" name="tix_raffle_prizes[' + prizeIdx + '][name]" placeholder="z.B. VIP-Upgrade" style="width:100%"></td>' +
                        '<td><input type="number" name="tix_raffle_prizes[' + prizeIdx + '][qty]" value="1" min="1" style="width:100%"></td>' +
                        '<td><input type="number" name="tix_raffle_prizes[' + prizeIdx + '][per_winner]" value="1" min="1" style="width:100%"></td>' +
                        '<td><select name="tix_raffle_prizes[' + prizeIdx + '][type]" class="tix-raffle-type-select" style="width:100%"><option value="text">Freitext</option><option value="ticket">Freikarte</option></select></td>' +
                        '<td><select name="tix_raffle_prizes[' + prizeIdx + '][cat_index]" class="tix-raffle-cat-select" style="width:100%;display:none;">' + catOpts + '</select></td>' +
                        '<td><button type="button" class="button tix-raffle-prize-del" title="Entfernen">&times;</button></td>' +
                        '</tr>';
                    $('#tix-raffle-prize-rows .tix-raffle-prize-empty').remove();
                    $('#tix-raffle-prize-rows').append(row);
                    prizeIdx++;
                });

                // Preis entfernen
                $(document).on('click', '.tix-raffle-prize-del', function() {
                    $(this).closest('tr').remove();
                    if (!$('#tix-raffle-prize-rows tr').length) {
                        $('#tix-raffle-prize-rows').append('<tr class="tix-raffle-prize-empty"><td colspan="7" style="text-align:center;color:#999;padding:16px;">Noch keine Preise.</td></tr>');
                    }
                });

                // Typ-Umschalter: Kategorie-Dropdown ein/ausblenden
                $(document).on('change', '.tix-raffle-type-select', function() {
                    var catSel = $(this).closest('tr').find('.tix-raffle-cat-select');
                    catSel.toggle($(this).val() === 'ticket');
                });

                // Drag & Drop für Preise (Muster wie FAQ)
                var prizeTable = document.getElementById('tix-raffle-prize-rows');
                if (prizeTable) {
                    var dragRow = null;
                    prizeTable.addEventListener('dragstart', function(e) {
                        dragRow = e.target.closest('tr');
                        if (dragRow) e.dataTransfer.effectAllowed = 'move';
                    });
                    prizeTable.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        var target = e.target.closest('tr');
                        if (target && target !== dragRow && target.parentNode === prizeTable) {
                            var rect = target.getBoundingClientRect();
                            var mid = rect.top + rect.height / 2;
                            if (e.clientY < mid) {
                                prizeTable.insertBefore(dragRow, target);
                            } else {
                                prizeTable.insertBefore(dragRow, target.nextSibling);
                            }
                        }
                    });
                    prizeTable.addEventListener('dragend', function() { dragRow = null; });
                }

                // Auslosen mit Animation + Recording
                var raffleNonce = '<?php echo wp_create_nonce('tix_raffle_admin'); ?>';
                $('#tix-raffle-draw').on('click', function() {
                    var btn = $(this);
                    if (!confirm('Gewinner jetzt auslosen? Dies kann nicht rückgängig gemacht werden.')) return;
                    btn.prop('disabled', true).text('Lade Teilnehmer…');

                    var format = $('input[name="tix_raffle_format"]:checked').val() || '9:16';
                    var eventId = btn.data('event');

                    // 1) Teilnehmer laden
                    $.post(tixAdmin.ajaxUrl, {
                        action: 'tix_raffle_get_participants',
                        event_id: eventId,
                        nonce: raffleNonce
                    }, function(pRes) {
                        if (!pRes.success) {
                            alert('Fehler: ' + (pRes.data && pRes.data.message || 'Teilnehmer konnten nicht geladen werden'));
                            btn.prop('disabled', false).text('🎲 Jetzt auslosen');
                            return;
                        }

                        var pData = pRes.data;
                        btn.text('Animation startet…');

                        // 2) Animation initialisieren + starten
                        if (typeof TixRaffleDraw === 'undefined') {
                            alert('Raffle-Draw Script nicht geladen. Bitte Seite neu laden.');
                            btn.prop('disabled', false).text('🎲 Jetzt auslosen');
                            return;
                        }

                        TixRaffleDraw.init({
                            format: format,
                            names: pData.names,
                            total: pData.total,
                            prizes: pData.prizes,
                            eventTitle: pData.eventTitle,
                            onFinish: function() {
                                // Sofort nach Animation: Echte Auslosung auf dem Server speichern
                                $.post(tixAdmin.ajaxUrl, {
                                    action: 'tix_raffle_draw',
                                    event_id: eventId,
                                    nonce: raffleNonce
                                }, function(dRes) {
                                    if (!dRes.success) {
                                        alert('Auslosung-Fehler: ' + (dRes.data && dRes.data.message || 'Unbekannter Fehler'));
                                    }
                                }).fail(function() {
                                    alert('Verbindungsfehler beim Speichern der Gewinner. Bitte manuell prüfen.');
                                });
                            },
                            onClose: function() {
                                // User klickt "Schließen" → Seite neu laden
                                location.reload();
                            },
                            onError: function(msg) {
                                btn.prop('disabled', false).text('🎲 Jetzt auslosen');
                                if (msg) alert(msg);
                            }
                        });
                        TixRaffleDraw.start();

                    }).fail(function() {
                        alert('Verbindungsfehler.');
                        btn.prop('disabled', false).text('🎲 Jetzt auslosen');
                    });
                });

                // Status zurücksetzen (AJAX)
                $('#tix-raffle-reset').on('click', function() {
                    if (!confirm('Status auf "offen" zurücksetzen?\n\nGewinner-Markierungen werden entfernt.\nTeilnehmer bleiben erhalten und können erneut ausgelost werden.')) return;
                    var btn = $(this);
                    btn.prop('disabled', true).text('Wird zurückgesetzt…');
                    $.post(tixAdmin.ajaxUrl, {
                        action: 'tix_raffle_reset',
                        event_id: btn.data('event'),
                        nonce: raffleNonce
                    }, function(res) {
                        if (res.success) {
                            location.reload();
                        } else {
                            alert('Fehler: ' + (res.data && res.data.message || 'Unbekannter Fehler'));
                            btn.prop('disabled', false).text('Status zurücksetzen (auf "offen")');
                        }
                    }).fail(function() {
                        alert('Verbindungsfehler.');
                        btn.prop('disabled', false).text('Status zurücksetzen (auf "offen")');
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Erweitert (Per-Event-Toggles)
    // ──────────────────────────────────────────
    public static function render_advanced($post) {
        $ac_global = function_exists('tix_get_settings') && tix_get_settings('abandoned_cart_enabled');
        $ex_global = function_exists('tix_get_settings') && tix_get_settings('express_checkout_enabled');
        $tt_global = function_exists('tix_get_settings') && tix_get_settings('ticket_transfer_enabled');

        if ($ac_global) {
            $ac_enabled = get_post_meta($post->ID, '_tix_abandoned_cart', true);
            ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-cart"></span>
                    <h3>Verlassene Warenkörbe</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-toggle-wrap">
                        <label>
                            <input type="hidden" name="tix_meta[_tix_abandoned_cart]" value="0">
                            <input type="checkbox" name="tix_meta[_tix_abandoned_cart]" value="1" <?php checked($ac_enabled, '1'); ?>>
                            Abandoned Cart Recovery für dieses Event aktivieren
                        </label>
                    </div>
                    <p class="description" style="margin-top:8px;">Wenn ein Nutzer seine E-Mail im Checkout eingibt aber nicht kauft, wird nach der eingestellten Verzögerung eine Erinnerungsmail gesendet.</p>
                </div>
            </div>
            <?php
        }

        if ($ex_global) {
            $ex_enabled = get_post_meta($post->ID, '_tix_express_checkout', true);
            ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-performance"></span>
                    <h3>Express Checkout</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-toggle-wrap">
                        <label>
                            <input type="hidden" name="tix_meta[_tix_express_checkout]" value="0">
                            <input type="checkbox" name="tix_meta[_tix_express_checkout]" value="1" <?php checked($ex_enabled, '1'); ?>>
                            1-Klick-Kauf für dieses Event aktivieren
                        </label>
                    </div>
                    <p class="description" style="margin-top:8px;">Zeigt einen „Sofort kaufen"-Button für eingeloggte Nutzer mit gespeicherten Zahlungsmethoden.</p>
                </div>
            </div>
            <?php
        }

        if ($tt_global) {
            $tt_enabled = get_post_meta($post->ID, '_tix_ticket_transfer', true);
            ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-randomize"></span>
                    <h3>Ticket-Umschreibung</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-toggle-wrap">
                        <label>
                            <input type="hidden" name="tix_meta[_tix_ticket_transfer]" value="0">
                            <input type="checkbox" name="tix_meta[_tix_ticket_transfer]" value="1" <?php checked($tt_enabled, '1'); ?>>
                            Ticket-Umschreibung für dieses Event aktivieren
                        </label>
                    </div>
                    <p class="description" style="margin-top:8px;">Erlaubt Käufern, ihre Tickets über den Shortcode <code>[tix_ticket_transfer]</code> auf eine andere Person umzuschreiben.</p>
                </div>
            </div>
            <?php
        }

        $bc_global = function_exists('tix_get_settings') && tix_get_settings('barcode_enabled');
        if ($bc_global) {
            $bc_enabled = get_post_meta($post->ID, '_tix_barcode', true);
            ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-editor-code"></span>
                    <h3>Strichcode (Barcode)</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-toggle-wrap">
                        <label>
                            <input type="hidden" name="tix_meta[_tix_barcode]" value="0">
                            <input type="checkbox" name="tix_meta[_tix_barcode]" value="1" <?php checked($bc_enabled, '1'); ?>>
                            Strichcode für dieses Event aktivieren
                        </label>
                    </div>
                    <p class="description" style="margin-top:8px;">Zeigt einen Code128-Barcode auf dem Ticket, der von Handscannern am Einlass gelesen werden kann.</p>
                </div>
            </div>
            <?php
        }

        // ── Charity / Soziales Projekt ──
        $ch_global = function_exists('tix_get_settings') && tix_get_settings('charity_enabled');
        if ($ch_global) {
            $ch_enabled = get_post_meta($post->ID, '_tix_charity_enabled', true);
            $ch_name    = get_post_meta($post->ID, '_tix_charity_name', true);
            $ch_percent = get_post_meta($post->ID, '_tix_charity_percent', true);
            $ch_desc    = get_post_meta($post->ID, '_tix_charity_desc', true);
            $ch_image   = get_post_meta($post->ID, '_tix_charity_image', true);
            $ch_img_url = $ch_image ? wp_get_attachment_image_url(intval($ch_image), 'thumbnail') : '';
            ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-heart"></span>
                    <h3>Soziales Projekt</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-toggle-wrap" style="margin-bottom:14px;">
                        <label>
                            <input type="hidden" name="tix_meta[_tix_charity_enabled]" value="0">
                            <input type="checkbox" name="tix_meta[_tix_charity_enabled]" value="1" <?php checked($ch_enabled, '1'); ?> id="tix-charity-toggle">
                            Soziales Projekt für dieses Event aktivieren
                        </label>
                    </div>
                    <div id="tix-charity-fields" style="<?php echo $ch_enabled !== '1' ? 'display:none;' : ''; ?>">
                        <div class="tix-field" style="margin-bottom:12px;">
                            <label class="tix-field-label">Projektname</label>
                            <input type="text" name="tix_meta[_tix_charity_name]" value="<?php echo esc_attr($ch_name); ?>" class="widefat" placeholder="z.B. Kinderhospiz Sternenbrücke">
                        </div>
                        <div class="tix-field" style="margin-bottom:12px;">
                            <label class="tix-field-label">Anteil (%)</label>
                            <input type="number" name="tix_meta[_tix_charity_percent]" value="<?php echo esc_attr($ch_percent); ?>" min="1" max="100" step="1" style="width:80px;" placeholder="10">
                            <span class="description">Prozent des Warenkorbs</span>
                        </div>
                        <div class="tix-field" style="margin-bottom:12px;">
                            <label class="tix-field-label">Kurzbeschreibung (optional)</label>
                            <textarea name="tix_meta[_tix_charity_desc]" rows="2" class="widefat" placeholder="Kurze Beschreibung des Projekts…"><?php echo esc_textarea($ch_desc); ?></textarea>
                        </div>
                        <div class="tix-field" style="margin-bottom:12px;">
                            <label class="tix-field-label">Projekt-Logo / Bild</label>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="hidden" name="tix_meta[_tix_charity_image]" id="tix-charity-image-val" value="<?php echo esc_attr($ch_image); ?>">
                                <?php if ($ch_img_url): ?>
                                <img id="tix-charity-image-preview" src="<?php echo esc_url($ch_img_url); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid #ddd;">
                                <?php else: ?>
                                <img id="tix-charity-image-preview" src="" style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid #ddd;display:none;">
                                <?php endif; ?>
                                <button type="button" class="button" id="tix-charity-image-btn">Bild wählen</button>
                                <button type="button" class="button" id="tix-charity-image-remove" <?php echo !$ch_image ? 'style="display:none;"' : ''; ?>>✕</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }

        // ── Saalplan ──
        $all_seatmaps = class_exists('TIX_Seatmap') ? TIX_Seatmap::get_all_seatmaps() : [];
        if (!empty($all_seatmaps)):
            $sm_id   = intval(get_post_meta($post->ID, '_tix_seatmap_id', true));
            $sm_mode = get_post_meta($post->ID, '_tix_seatmap_mode', true) ?: 'manual';
        ?>
        <div class="tix-card">
            <div class="tix-card-header">
                <span class="dashicons dashicons-layout"></span>
                <h3>Saalplan</h3>
            </div>
            <div class="tix-card-body">
                <div class="tix-field" style="margin-bottom:12px;">
                    <label class="tix-field-label">Saalplan auswählen</label>
                    <select name="tix_meta[_tix_seatmap_id]" id="tix-event-seatmap" style="width:100%;max-width:400px;">
                        <option value="0">— Kein Saalplan —</option>
                        <?php foreach ($all_seatmaps as $sid => $stitle): ?>
                            <option value="<?php echo $sid; ?>" <?php selected($sm_id, $sid); ?>><?php echo esc_html($stitle); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tix-field" id="tix-seatmap-mode-wrap" <?php echo !$sm_id ? 'style="display:none"' : ''; ?>>
                    <label class="tix-field-label">Platzvergabe</label>
                    <label style="display:block;margin-bottom:4px;">
                        <input type="radio" name="tix_meta[_tix_seatmap_mode]" value="manual" <?php checked($sm_mode, 'manual'); ?>>
                        Kunde wählt Platz selbst
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="tix_meta[_tix_seatmap_mode]" value="best" <?php checked($sm_mode, 'best'); ?>>
                        Bester verfügbarer Platz (automatisch)
                    </label>
                </div>
                <p class="description" style="margin-top:8px;">
                    Wenn ein Saalplan aktiviert ist, werden die Ticket-Kategorien automatisch aus den Saalplan-Sektionen generiert.
                    Die manuell angelegten Kategorien werden dann für dieses Event ignoriert.
                </p>
            </div>
        </div>
        <?php
        endif;
    }

    // ──────────────────────────────────────────
    // Speichern
    // ──────────────────────────────────────────
    public static function save($post_id, $post) {

        if (!isset($_POST['tix_nonce']) || !wp_verify_nonce($_POST['tix_nonce'], 'tix_save_event')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Event-Details
        $fields = [
            '_tix_date_start' => sanitize_text_field($_POST['tix_date_start'] ?? ''),
            '_tix_date_end'   => sanitize_text_field($_POST['tix_date_end'] ?? ''),
            '_tix_time_start' => sanitize_text_field($_POST['tix_time_start'] ?? ''),
            '_tix_time_end'   => sanitize_text_field($_POST['tix_time_end'] ?? ''),
            '_tix_time_doors' => sanitize_text_field($_POST['tix_time_doors'] ?? ''),
        ];
        foreach ($fields as $key => $val) update_post_meta($post_id, $key, $val);

        // Location aus CPT
        $loc_id = intval($_POST['tix_location_id'] ?? 0);
        update_post_meta($post_id, '_tix_location_id', $loc_id);
        if ($loc_id) {
            update_post_meta($post_id, '_tix_location', get_the_title($loc_id));
            update_post_meta($post_id, '_tix_address', get_post_meta($loc_id, '_tix_loc_address', true));
        } else {
            update_post_meta($post_id, '_tix_location', '');
            update_post_meta($post_id, '_tix_address', '');
        }

        // Veranstalter aus CPT
        $org_id = intval($_POST['tix_organizer_id'] ?? 0);
        update_post_meta($post_id, '_tix_organizer_id', $org_id);
        if ($org_id) {
            update_post_meta($post_id, '_tix_organizer', get_the_title($org_id));
        } else {
            update_post_meta($post_id, '_tix_organizer', '');
        }

        // Event-Informationen (Sektionen + Labels)
        $sections = self::info_sections();
        $info_data = $_POST['tix_info'] ?? [];
        $info_labels = $_POST['tix_info_labels'] ?? [];

        foreach ($sections as $key => $def) {
            // Label speichern (nur wenn vom Default abweichend)
            $label = sanitize_text_field($info_labels[$key] ?? '');
            if ($label && $label !== $def['label']) {
                update_post_meta($post_id, "_tix_info_{$key}_label", $label);
            } else {
                delete_post_meta($post_id, "_tix_info_{$key}_label");
            }

            // Content speichern
            $content = $info_data[$key] ?? '';
            if ($def['type'] === 'number') {
                $content = $content !== '' ? intval($content) : '';
            } else {
                $content = wp_kses_post($content);
            }
            update_post_meta($post_id, "_tix_info_{$key}", $content);
        }

        // FAQ speichern
        $raw_faq = $_POST['tix_faq'] ?? [];
        $faqs = [];
        foreach ($raw_faq as $faq) {
            $q = sanitize_text_field($faq['q'] ?? '');
            $a = wp_kses_post($faq['a'] ?? '');
            if (empty($q) && empty($a)) continue;
            $faqs[] = ['q' => $q, 'a' => $a];
        }
        update_post_meta($post_id, '_tix_faq', $faqs);

        // ── Gewinnspiel (Raffle) speichern ──
        $raffle_enabled = !empty($_POST['tix_raffle_enabled']) ? '1' : '';
        update_post_meta($post_id, '_tix_raffle_enabled', $raffle_enabled);

        if ($raffle_enabled) {
            update_post_meta($post_id, '_tix_raffle_title', sanitize_text_field($_POST['tix_raffle_title'] ?? ''));
            update_post_meta($post_id, '_tix_raffle_description', wp_kses_post($_POST['tix_raffle_description'] ?? ''));
            update_post_meta($post_id, '_tix_raffle_end_date', sanitize_text_field($_POST['tix_raffle_end_date'] ?? ''));
            update_post_meta($post_id, '_tix_raffle_max_entries', max(0, intval($_POST['tix_raffle_max_entries'] ?? 0)));
            update_post_meta($post_id, '_tix_raffle_hide_count', !empty($_POST['tix_raffle_hide_count']) ? '1' : '');
            update_post_meta($post_id, '_tix_raffle_consent_text', wp_kses_post($_POST['tix_raffle_consent_text'] ?? ''));
            $hdr_bg = sanitize_hex_color($_POST['tix_raffle_header_bg'] ?? '');
            update_post_meta($post_id, '_tix_raffle_header_bg', $hdr_bg);
            $hdr_color = sanitize_hex_color($_POST['tix_raffle_header_color'] ?? '');
            update_post_meta($post_id, '_tix_raffle_header_color', $hdr_color);

            // Status-Reset
            if (!empty($_POST['tix_raffle_status_reset'])) {
                update_post_meta($post_id, '_tix_raffle_status', 'open');
            }

            // Preise
            $raw_prizes = $_POST['tix_raffle_prizes'] ?? [];
            $prizes = [];
            if (is_array($raw_prizes)) {
                foreach ($raw_prizes as $p) {
                    $name       = sanitize_text_field($p['name'] ?? '');
                    $qty        = max(1, intval($p['qty'] ?? 1));
                    $per_winner = max(1, intval($p['per_winner'] ?? 1));
                    $type       = in_array($p['type'] ?? '', ['text', 'ticket']) ? $p['type'] : 'text';
                    if (empty($name)) continue;
                    $prize = ['name' => $name, 'qty' => $qty, 'per_winner' => $per_winner, 'type' => $type];
                    if ($type === 'ticket') {
                        $prize['cat_index'] = intval($p['cat_index'] ?? 0);
                    }
                    $prizes[] = $prize;
                }
            }
            update_post_meta($post_id, '_tix_raffle_prizes', $prizes);

            // Status initial setzen falls noch leer
            $current_status = get_post_meta($post_id, '_tix_raffle_status', true);
            if (empty($current_status) || empty($_POST['tix_raffle_status_reset'])) {
                if (empty($current_status)) {
                    update_post_meta($post_id, '_tix_raffle_status', 'open');
                }
            }
        }

        // ── Timetable / Programm speichern ──
        update_post_meta($post_id, '_tix_timetable_times_tba', !empty($_POST['tix_timetable_times_tba']) ? '1' : '');

        $raw_stages = $_POST['tix_stages'] ?? [];
        $stages = [];
        if (is_array($raw_stages)) {
            foreach ($raw_stages as $st) {
                $name  = sanitize_text_field($st['name'] ?? '');
                $color = sanitize_hex_color($st['color'] ?? '') ?: '#FF5500';
                if (empty($name)) continue;
                $stages[] = ['name' => $name, 'color' => $color];
            }
        }
        update_post_meta($post_id, '_tix_stages', $stages);

        $raw_tt = $_POST['tix_timetable'] ?? [];
        $timetable = [];
        if (is_array($raw_tt)) {
            foreach ($raw_tt as $day => $day_slots) {
                $day = sanitize_text_field($day);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;
                $slots = [];
                if (is_array($day_slots)) {
                    foreach ($day_slots as $slot) {
                        $time  = sanitize_text_field($slot['time'] ?? '');
                        $end   = sanitize_text_field($slot['end'] ?? '');
                        $stage = intval($slot['stage'] ?? 0);
                        $title = sanitize_text_field($slot['title'] ?? '');
                        $desc  = sanitize_text_field($slot['desc'] ?? '');
                        if (empty($time) && empty($title)) continue;
                        $slots[] = [
                            'time'  => $time,
                            'end'   => $end,
                            'stage' => $stage,
                            'title' => $title,
                            'desc'  => $desc,
                        ];
                    }
                    // Nach Startzeit sortieren
                    usort($slots, fn($a, $b) => strcmp($a['time'], $b['time']));
                }
                if (!empty($slots)) {
                    $timetable[$day] = $slots;
                }
            }
        }
        update_post_meta($post_id, '_tix_timetable', $timetable);

        // ── Rabattcodes speichern ──
        $raw_discounts = $_POST['tix_discounts'] ?? [];
        $old_codes = get_post_meta($post_id, '_tix_discount_codes', true);
        if (!is_array($old_codes)) $old_codes = [];
        $old_coupon_ids = array_column($old_codes, 'coupon_id');

        // Produkt-IDs für dieses Event sammeln (Coupon-Scope)
        $event_cats = get_post_meta($post_id, '_tix_ticket_categories', true);
        $event_product_ids = [];
        if (is_array($event_cats)) {
            foreach ($event_cats as $ec) {
                $pid = intval($ec['product_id'] ?? 0);
                if ($pid) $event_product_ids[] = $pid;
            }
        }

        $discount_codes = [];
        $new_coupon_ids = [];
        if (is_array($raw_discounts)) {
            foreach ($raw_discounts as $dc) {
                $code   = strtoupper(sanitize_text_field($dc['code'] ?? ''));
                $type   = in_array($dc['type'] ?? '', ['percent', 'fixed_cart']) ? $dc['type'] : 'percent';
                $amount = floatval($dc['amount'] ?? 0);
                $limit  = max(0, intval($dc['limit'] ?? 0));
                $expiry = sanitize_text_field($dc['expiry'] ?? '');
                $coupon_id = intval($dc['coupon_id'] ?? 0);

                if (empty($code) && $amount <= 0) continue;
                if (empty($code)) continue;

                // WC_Coupon erstellen oder aktualisieren
                $coupon = new \WC_Coupon($coupon_id ?: 0);

                // Prüfe ob Coupon-ID gültig ist
                if ($coupon_id && !$coupon->get_id()) {
                    $coupon = new \WC_Coupon(0);
                    $coupon_id = 0;
                }

                // Code setzen (nur bei neuem Coupon oder geändertem Code)
                if (!$coupon_id || $coupon->get_code() !== strtolower($code)) {
                    $coupon->set_code(strtolower($code));
                }
                $coupon->set_discount_type($type);
                $coupon->set_amount($amount);
                $coupon->set_usage_limit($limit ?: 0);
                $coupon->set_date_expires($expiry ?: null);
                if (!empty($event_product_ids)) {
                    $coupon->set_product_ids($event_product_ids);
                }
                $coupon->set_individual_use(false);
                $coupon->save();

                $saved_id = $coupon->get_id();
                if ($saved_id) {
                    update_post_meta($saved_id, '_tix_event_coupon', $post_id);
                    $new_coupon_ids[] = $saved_id;
                }

                $discount_codes[] = [
                    'code'      => $code,
                    'type'      => $type,
                    'amount'    => $amount,
                    'limit'     => $limit,
                    'expiry'    => $expiry,
                    'coupon_id' => $saved_id ?: 0,
                ];
            }
        }
        update_post_meta($post_id, '_tix_discount_codes', $discount_codes);

        // Entfernte Coupons in den Papierkorb verschieben
        foreach ($old_coupon_ids as $old_cid) {
            if ($old_cid && !in_array($old_cid, $new_coupon_ids)) {
                wp_trash_post($old_cid);
            }
        }

        // ── Specials speichern ──
        if (class_exists('TIX_Specials')) {
            TIX_Specials::save_event_specials($post_id);
        }

        // Upsell-Events speichern
        update_post_meta($post_id, '_tix_upsell_disabled', !empty($_POST['tix_upsell_disabled']) ? '1' : '');
        $raw_upsell = $_POST['tix_upsell_events'] ?? [];
        $upsell_ids = [];
        if (is_array($raw_upsell)) {
            foreach ($raw_upsell as $uid) {
                $uid = intval($uid);
                if ($uid && $uid !== $post_id) $upsell_ids[] = $uid;
            }
        }
        update_post_meta($post_id, '_tix_upsell_events', $upsell_ids);

        // Galerie speichern
        $raw_gallery = $_POST['tix_gallery'] ?? [];
        $gallery_ids = [];
        if (is_array($raw_gallery)) {
            foreach ($raw_gallery as $gid) {
                $gid = intval($gid);
                if ($gid) $gallery_ids[] = $gid;
            }
        }
        update_post_meta($post_id, '_tix_gallery', $gallery_ids);

        // Breakdance-Meta: Gallery
        update_post_meta($post_id, '_tix_gallery_ids', implode(',', $gallery_ids));
        $gallery_urls = [];
        $gallery_urls_large = [];
        foreach ($gallery_ids as $gid) {
            $url = wp_get_attachment_image_url($gid, 'full');
            $url_large = wp_get_attachment_image_url($gid, 'large');
            if ($url) $gallery_urls[] = $url;
            if ($url_large) $gallery_urls_large[] = $url_large;
        }
        update_post_meta($post_id, '_tix_gallery_urls', implode(',', $gallery_urls));
        update_post_meta($post_id, '_tix_gallery_urls_large', implode(',', $gallery_urls_large));
        update_post_meta($post_id, '_tix_gallery_count', count($gallery_ids));

        // Video speichern
        $video_url = esc_url_raw($_POST['tix_video_url'] ?? '');
        $video_id  = intval($_POST['tix_video_id'] ?? 0);
        update_post_meta($post_id, '_tix_video_url', $video_url);
        update_post_meta($post_id, '_tix_video_id', $video_id);

        // Breakdance-Meta: Video Embed
        if ($video_url) {
            // Self-hosted video
            if ($video_id) {
                $mime = get_post_mime_type($video_id);
                $embed = '<video controls preload="metadata" style="width:100%;max-width:100%;"><source src="' . esc_url($video_url) . '" type="' . esc_attr($mime) . '"></video>';
                update_post_meta($post_id, '_tix_video_embed', $embed);
                update_post_meta($post_id, '_tix_video_type', 'self-hosted');
            } else {
                // oEmbed for YouTube/Vimeo
                $oembed = wp_oembed_get($video_url);
                update_post_meta($post_id, '_tix_video_embed', $oembed ?: '');
                // Type detection
                if (preg_match('/youtube\.com|youtu\.be/', $video_url)) {
                    update_post_meta($post_id, '_tix_video_type', 'youtube');
                } elseif (preg_match('/vimeo\.com/', $video_url)) {
                    update_post_meta($post_id, '_tix_video_type', 'vimeo');
                } else {
                    update_post_meta($post_id, '_tix_video_type', 'external');
                }
            }
        } else {
            delete_post_meta($post_id, '_tix_video_embed');
            delete_post_meta($post_id, '_tix_video_type');
        }

        // Tickets-Schalter
        $enabled = !empty($_POST['tix_tickets_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_tix_tickets_enabled', $enabled);

        $presale = !empty($_POST['tix_presale_active']) ? '1' : '0';
        update_post_meta($post_id, '_tix_presale_active', $presale);

        // Presale-Start (Countdown)
        $presale_start = sanitize_text_field($_POST['tix_presale_start'] ?? '');
        update_post_meta($post_id, '_tix_presale_start', $presale_start);

        // Warteliste pro Event
        $waitlist_enabled = !empty($_POST['tix_waitlist_enabled']) ? '1' : '';
        update_post_meta($post_id, '_tix_waitlist_enabled', $waitlist_enabled);

        // Presale-End-Modus
        $presale_end_mode = sanitize_text_field($_POST['tix_presale_end_mode'] ?? 'manual');
        $presale_end_offset = intval($_POST['tix_presale_end_offset'] ?? 0);
        $presale_end_fixed  = sanitize_text_field($_POST['tix_presale_end'] ?? '');

        // Wenn Vorverkauf manuell eingeschaltet: prüfen ob berechnete Endzeit bereits vorbei ist
        // Falls ja → Modus auf "Manuell" umstellen, damit Cron nicht sofort wieder deaktiviert
        if ($presale === '1' && $presale_end_mode !== 'manual') {
            $now = current_time('timestamp');
            $computed_end_ts = 0;

            if ($presale_end_mode === 'before_event') {
                $ds = get_post_meta($post_id, '_tix_date_start', true);
                $ts = get_post_meta($post_id, '_tix_time_start', true);
                if ($ds && $ts) {
                    $event_start_ts = strtotime("{$ds} {$ts}");
                    $computed_end_ts = $event_start_ts - ($presale_end_offset * 3600);
                }
            } elseif ($presale_end_mode === 'fixed' && $presale_end_fixed) {
                $computed_end_ts = strtotime($presale_end_fixed);
            }

            if ($computed_end_ts > 0 && $now >= $computed_end_ts) {
                // Endzeit liegt in der Vergangenheit → Modus auf Manuell setzen
                $presale_end_mode = 'manual';
            }
        }

        update_post_meta($post_id, '_tix_presale_end_mode', $presale_end_mode);
        update_post_meta($post_id, '_tix_presale_end_offset', $presale_end_offset);
        update_post_meta($post_id, '_tix_presale_end', $presale_end_fixed);

        // Externer Ticketshop
        $ext_enabled = !empty($_POST['tix_extshop_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_tix_extshop_enabled', $ext_enabled);
        update_post_meta($post_id, '_tix_extshop_url', esc_url_raw($_POST['tix_extshop_url'] ?? ''));
        update_post_meta($post_id, '_tix_extshop_text', sanitize_text_field($_POST['tix_extshop_text'] ?? ''));
        $ext_mode = in_array($_POST['tix_extshop_mode'] ?? '', ['replace', 'both']) ? $_POST['tix_extshop_mode'] : 'replace';
        update_post_meta($post_id, '_tix_extshop_mode', $ext_mode);

        // Event-Status
        $event_status = sanitize_text_field($_POST['tix_event_status'] ?? '');
        update_post_meta($post_id, '_tix_event_status', $event_status);

        // Ticket-Kategorien
        $raw = $_POST['tix_tickets'] ?? [];
        $categories = [];

        foreach ($raw as $ticket) {
            $name = sanitize_text_field($ticket['name'] ?? '');
            if (empty($name)) continue;

            $sale = $ticket['sale_price'] ?? '';
            $sale = ($sale !== '' && $sale !== null) ? floatval($sale) : '';

            $cat = [
                'name'           => $name,
                'price'          => floatval($ticket['price'] ?? 0),
                'sale_price'     => $sale,
                'qty'            => intval($ticket['qty'] ?? 1),
                'desc'           => sanitize_text_field($ticket['desc'] ?? ''),
                'image_id'       => intval($ticket['image_id'] ?? 0),
                'online'         => (($ticket['sale_mode'] ?? 'online') === 'online') ? '1' : '0',
                'offline_ticket' => (($ticket['sale_mode'] ?? 'online') === 'offline') ? '1' : '0',
                'bundle_buy'     => intval($ticket['bundle_buy'] ?? 0),
                'bundle_pay'     => intval($ticket['bundle_pay'] ?? 0),
                'bundle_label'   => sanitize_text_field($ticket['bundle_label'] ?? ''),
                'tc_event_id'    => intval($ticket['tc_event_id'] ?? 0),
                'product_id'     => intval($ticket['product_id'] ?? 0),
                'sku'              => sanitize_text_field($ticket['sku'] ?? ''),
                'group'            => sanitize_text_field($ticket['group'] ?? ''),
                'seatmap_id'       => intval($ticket['seatmap_id'] ?? 0),
                'seatmap_section'  => sanitize_key($ticket['seatmap_section'] ?? ''),
            ];

            // Preisphasen
            $raw_phases = $ticket['phases'] ?? [];
            $phases = [];
            if (is_array($raw_phases)) {
                foreach ($raw_phases as $ph) {
                    $ph_name  = sanitize_text_field($ph['name'] ?? '');
                    $ph_price = $ph['price'] ?? '';
                    $ph_until = sanitize_text_field($ph['until'] ?? '');
                    if (empty($ph_name) && $ph_price === '' && empty($ph_until)) continue;
                    $phases[] = [
                        'name'  => $ph_name,
                        'price' => ($ph_price !== '' && $ph_price !== null) ? floatval($ph_price) : 0,
                        'until' => $ph_until,
                    ];
                }
                // Nach Datum sortieren
                usort($phases, fn($a, $b) => strcmp($a['until'], $b['until']));
            }
            $cat['phases'] = $phases;

            // Stock-Override: Wenn ein Wert eingegeben wurde, WC-Produkt aktualisieren
            $stock_override = $ticket['stock_override'] ?? '';
            if ($stock_override !== '' && $cat['product_id']) {
                $product = wc_get_product($cat['product_id']);
                if ($product) {
                    $new_stock = max(0, intval($stock_override));
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($new_stock);
                    $product->set_stock_status($new_stock > 0 ? 'instock' : 'outofstock');
                    $product->save();
                }
            }

            $categories[] = $cat;
        }

        update_post_meta($post_id, '_tix_ticket_categories', $categories);

        // Gruppenrabatt speichern
        $raw_gd = $_POST['tix_group_discount'] ?? [];
        $gd = [
            'enabled'         => !empty($raw_gd['enabled']),
            'tiers'           => [],
            'combine_bundle'  => !empty($raw_gd['combine_bundle']),
            'combine_combo'   => !empty($raw_gd['combine_combo']),
            'combine_phase'   => !empty($raw_gd['combine_phase']),
        ];
        if (!empty($raw_gd['tiers']) && is_array($raw_gd['tiers'])) {
            foreach ($raw_gd['tiers'] as $tier) {
                $min = intval($tier['min_qty'] ?? 0);
                $pct = intval($tier['percent'] ?? 0);
                if ($min >= 2 && $pct >= 1 && $pct <= 99) {
                    $gd['tiers'][] = ['min_qty' => $min, 'percent' => $pct];
                }
            }
            usort($gd['tiers'], fn($a, $b) => $a['min_qty'] - $b['min_qty']);
        }

        update_post_meta($post_id, '_tix_group_discount', $gd);

        // ── Kombi-Tickets speichern ──
        $raw_combos = $_POST['tix_combos'] ?? [];
        $combos = [];
        if (is_array($raw_combos)) {
            foreach ($raw_combos as $combo) {
                $label = sanitize_text_field($combo['label'] ?? '');
                $price = floatval($combo['price'] ?? 0);
                if (empty($label) || $price <= 0) continue;

                $self_cat_index = intval($combo['self_cat_index'] ?? 0);

                $partners = [];
                $raw_partners = $combo['partners'] ?? [];
                if (is_array($raw_partners)) {
                    foreach ($raw_partners as $p) {
                        $eid = intval($p['event_id'] ?? 0);
                        $ci  = intval($p['cat_index'] ?? 0);
                        if ($eid > 0) {
                            $partners[] = ['event_id' => $eid, 'cat_index' => $ci];
                        }
                    }
                }
                if (empty($partners)) continue;

                $combo_id = sanitize_text_field($combo['id'] ?? '');
                if (empty($combo_id)) {
                    $combo_id = 'combo_' . wp_generate_password(8, false, false);
                }

                $combos[] = [
                    'id'             => $combo_id,
                    'label'          => $label,
                    'price'          => $price,
                    'self_cat_index' => $self_cat_index,
                    'partners'       => $partners,
                ];
            }
        }
        update_post_meta($post_id, '_tix_combo_deals', $combos);

        // ── Tischreservierung ──
        if (class_exists('TIX_Table_Reservation') && isset($_POST['tix_table_reservation'])) {
            TIX_Table_Reservation::save_metabox($post_id);
        }

        // ── Embed Widget ──
        update_post_meta($post_id, '_tix_embed_enabled', !empty($_POST['tix_embed_enabled']) ? '1' : '0');
        update_post_meta($post_id, '_tix_embed_domains', sanitize_text_field($_POST['tix_embed_domains'] ?? ''));

        // ── Gästeliste ──
        update_post_meta($post_id, '_tix_guest_list_enabled',
            !empty($_POST['tix_guest_list_enabled']) ? '1' : '0');
        update_post_meta($post_id, '_tix_checkin_password',
            sanitize_text_field($_POST['tix_checkin_password'] ?? ''));

        $raw_guests = $_POST['tix_guest_list'] ?? [];
        $existing_guests = get_post_meta($post_id, '_tix_guest_list', true);
        if (!is_array($existing_guests)) $existing_guests = [];

        // Lookup: bestehende Gäste per ID → Check-in-Status bewahren
        $existing_by_id = [];
        foreach ($existing_guests as $eg) {
            if (!empty($eg['id'])) $existing_by_id[$eg['id']] = $eg;
        }

        $guests = [];
        if (is_array($raw_guests)) {
            foreach ($raw_guests as $g) {
                $name = sanitize_text_field($g['name'] ?? '');
                if (empty($name)) continue;

                $id = sanitize_text_field($g['id'] ?? '');
                if (empty($id)) {
                    $id = strtoupper(wp_generate_password(6, false, false));
                }

                $prev = $existing_by_id[$id] ?? null;

                $guests[] = [
                    'id'           => $id,
                    'name'         => $name,
                    'email'        => sanitize_email($g['email'] ?? ''),
                    'plus'         => max(0, intval($g['plus'] ?? 0)),
                    'note'         => sanitize_text_field($g['note'] ?? ''),
                    'checked_in'   => $prev ? ($prev['checked_in'] ?? false) : false,
                    'checkin_time' => $prev ? ($prev['checkin_time'] ?? '') : '',
                    'checkin_by'   => $prev ? ($prev['checkin_by'] ?? '') : '',
                    'created'      => $prev ? ($prev['created'] ?? current_time('c')) : current_time('c'),
                ];
            }
        }
        update_post_meta($post_id, '_tix_guest_list', $guests);

        // ── Ticket-Template ──
        $tt_mode = sanitize_text_field($_POST['tix_ticket_template_mode'] ?? 'global');
        if (!in_array($tt_mode, ['global', 'custom', 'template', 'none'])) $tt_mode = 'global';
        update_post_meta($post_id, '_tix_ticket_template_mode', $tt_mode);

        if ($tt_mode === 'template') {
            $tpl_id = absint($_POST['tix_ticket_template_id'] ?? 0);
            update_post_meta($post_id, '_tix_ticket_template_id', $tpl_id);
        }

        if ($tt_mode === 'custom') {
            $raw_tt = wp_unslash($_POST['tix_ticket_template'] ?? '');
            if (!empty($raw_tt) && class_exists('TIX_Ticket_Template')) {
                $tt_config = TIX_Ticket_Template::sanitize_config($raw_tt);
                update_post_meta($post_id, '_tix_ticket_template', wp_json_encode($tt_config));
            }
        }

        // ── Erweitert: Per-Event-Toggles ──
        $tix_meta = $_POST['tix_meta'] ?? [];
        update_post_meta($post_id, '_tix_abandoned_cart', !empty($tix_meta['_tix_abandoned_cart']) ? '1' : '0');
        update_post_meta($post_id, '_tix_express_checkout', !empty($tix_meta['_tix_express_checkout']) ? '1' : '0');
        update_post_meta($post_id, '_tix_ticket_transfer', !empty($tix_meta['_tix_ticket_transfer']) ? '1' : '0');
        update_post_meta($post_id, '_tix_barcode', !empty($tix_meta['_tix_barcode']) ? '1' : '0');

        // Charity / Soziales Projekt
        update_post_meta($post_id, '_tix_charity_enabled', !empty($tix_meta['_tix_charity_enabled']) ? '1' : '0');
        update_post_meta($post_id, '_tix_charity_name', sanitize_text_field($tix_meta['_tix_charity_name'] ?? ''));
        update_post_meta($post_id, '_tix_charity_percent', max(0, min(100, intval($tix_meta['_tix_charity_percent'] ?? 0))));
        update_post_meta($post_id, '_tix_charity_desc', sanitize_textarea_field($tix_meta['_tix_charity_desc'] ?? ''));
        update_post_meta($post_id, '_tix_charity_image', intval($tix_meta['_tix_charity_image'] ?? 0));

        // Saalplan
        update_post_meta($post_id, '_tix_seatmap_id', intval($tix_meta['_tix_seatmap_id'] ?? 0));
        update_post_meta($post_id, '_tix_seatmap_mode', sanitize_key($tix_meta['_tix_seatmap_mode'] ?? 'manual'));

        // ── Serientermine ──
        // Kind-Event: Detached-Flag speichern
        if (get_post_meta($post_id, '_tix_series_parent', true)) {
            update_post_meta($post_id, '_tix_series_detached', !empty($_POST['tix_series_detached']) ? '1' : '0');
        } else {
            // Master-Konfiguration speichern
            $series_enabled = !empty($_POST['tix_series_enabled']) ? '1' : '0';
            update_post_meta($post_id, '_tix_series_enabled', $series_enabled);

            if ($series_enabled === '1') {
                $series_mode = sanitize_text_field($_POST['tix_series_mode'] ?? 'periodic');
                update_post_meta($post_id, '_tix_series_mode', $series_mode);

                if ($series_mode === 'periodic') {
                    $pattern = [
                        'frequency' => sanitize_text_field($_POST['tix_series_frequency'] ?? 'weekly'),
                        'days'      => array_map('intval', $_POST['tix_series_days'] ?? []),
                        'week_of'   => intval($_POST['tix_series_week_of'] ?? 1),
                        'day_of'    => intval($_POST['tix_series_day_of'] ?? 6),
                        'day_num'   => max(1, min(31, intval($_POST['tix_series_day_num'] ?? 1))),
                        'end_mode'  => sanitize_text_field($_POST['tix_series_end_mode'] ?? 'count'),
                        'end_date'  => sanitize_text_field($_POST['tix_series_end_date'] ?? ''),
                        'end_count' => max(1, min(365, intval($_POST['tix_series_end_count'] ?? 10))),
                    ];
                    update_post_meta($post_id, '_tix_series_pattern', $pattern);
                } else {
                    $raw = $_POST['tix_series_dates'] ?? [];
                    $manual = [];
                    if (is_array($raw)) {
                        foreach ($raw as $d) {
                            $ds = sanitize_text_field($d['date_start'] ?? '');
                            $de = sanitize_text_field($d['date_end'] ?? '');
                            if ($ds) $manual[] = ['date_start' => $ds, 'date_end' => $de ?: $ds];
                        }
                    }
                    update_post_meta($post_id, '_tix_series_manual_dates', $manual);
                }
            }
        }

        // ── Pflichtfeld-Validierung beim Veröffentlichen ──
        $is_series_master = get_post_meta($post_id, '_tix_series_enabled', true) === '1';
        if ($post->post_status === 'publish') {
            $missing = [];
            if (empty($post->post_title) || $post->post_title === 'Automatischer Entwurf')
                $missing[] = 'Titel';
            if (empty(get_post_meta($post_id, '_tix_date_start', true)))
                $missing[] = 'Startdatum';
            if (empty(get_post_meta($post_id, '_tix_time_start', true)))
                $missing[] = 'Startzeit';
            if (!$is_series_master && empty(intval(get_post_meta($post_id, '_tix_location_id', true))))
                $missing[] = 'Location';
            if (!$is_series_master && get_post_meta($post_id, '_tix_tickets_enabled', true) === '1') {
                $cats = get_post_meta($post_id, '_tix_ticket_categories', true);
                if (empty($cats) || !is_array($cats))
                    $missing[] = 'Mind. 1 Ticket-Kategorie';
            }

            if (!empty($missing)) {
                $was_published = ($_POST['original_post_status'] ?? '') === 'publish';

                if ($was_published) {
                    // Bereits veröffentlicht → Warnung, aber speichern erlauben
                    set_transient('tix_publish_warning_' . $post_id, $missing, 60);
                } else {
                    // Neu veröffentlichen → blockieren, zurück auf Entwurf
                    remove_action('save_post_event', [__CLASS__, 'save'], 10);
                    wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
                    add_action('save_post_event', [__CLASS__, 'save'], 10, 2);
                    set_transient('tix_publish_error_' . $post_id, $missing, 60);
                }
            }
        }
    }

    private static function empty_row() {
        return [
            'name' => '', 'price' => '', 'sale_price' => '', 'qty' => '',
            'desc' => '', 'image_id' => 0, 'online' => '1', 'offline_ticket' => '0',
            'bundle_buy' => 0, 'bundle_pay' => 0, 'bundle_label' => '',
            'tc_event_id' => 0, 'product_id' => 0, 'sku' => '', 'group' => '', 'phases' => [],
            'seatmap_id' => 0, 'seatmap_section' => '',
        ];
    }

    // ──────────────────────────────────────────
    // Gästeliste AJAX: Admin Check-in Toggle
    // ──────────────────────────────────────────
    public static function ajax_guest_checkin() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $event_id = intval($_POST['event_id'] ?? 0);
        $guest_id = sanitize_text_field($_POST['guest_id'] ?? '');
        if (!$event_id || !$guest_id) wp_send_json_error(['message' => 'Ungültige Daten.']);

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) wp_send_json_error(['message' => 'Keine Gästeliste.']);

        $found = null;
        foreach ($guests as &$g) {
            if (($g['id'] ?? '') === $guest_id) {
                $g['checked_in']  = empty($g['checked_in']);
                $g['checkin_time'] = $g['checked_in'] ? current_time('c') : '';
                $g['checkin_by']   = $g['checked_in'] ? wp_get_current_user()->user_login : '';
                $found = $g;
                break;
            }
        }
        unset($g);

        if (!$found) wp_send_json_error(['message' => 'Gast nicht gefunden.']);

        update_post_meta($event_id, '_tix_guest_list', $guests);

        // Stats berechnen
        $total = count($guests);
        $checked = 0;
        foreach ($guests as $g) { if (!empty($g['checked_in'])) $checked++; }

        wp_send_json_success([
            'guest' => $found,
            'stats' => [
                'total'      => $total,
                'checked_in' => $checked,
                'open'       => $total - $checked,
            ],
        ]);
    }

    // ──────────────────────────────────────────
    // Gästeliste AJAX: E-Mail senden
    // ──────────────────────────────────────────
    public static function ajax_guest_send_email() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $event_id = intval($_POST['event_id'] ?? 0);
        $guest_id = sanitize_text_field($_POST['guest_id'] ?? '');
        if (!$event_id || !$guest_id) wp_send_json_error(['message' => 'Ungültige Daten.']);

        if (!class_exists('TIX_Emails')) {
            require_once TIXOMAT_PATH . 'includes/class-tix-emails.php';
        }

        $result = TIX_Emails::send_guest_notification($event_id, $guest_id);
        if ($result) {
            wp_send_json_success(['message' => 'E-Mail gesendet.']);
        } else {
            wp_send_json_error(['message' => 'Senden fehlgeschlagen. Keine E-Mail-Adresse?']);
        }
    }

    // ──────────────────────────────────────────
    // Gästeliste AJAX: Alle E-Mails senden
    // ──────────────────────────────────────────
    public static function ajax_guest_send_all_emails() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error(['message' => 'Ungültige Daten.']);

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) wp_send_json_error(['message' => 'Keine Gästeliste.']);

        if (!class_exists('TIX_Emails')) {
            require_once TIXOMAT_PATH . 'includes/class-tix-emails.php';
        }

        $sent = 0;
        $total = 0;
        foreach ($guests as $g) {
            if (empty($g['email'])) continue;
            $total++;
            if (TIX_Emails::send_guest_notification($event_id, $g['id'])) {
                $sent++;
            }
        }

        wp_send_json_success(['sent' => $sent, 'total' => $total]);
    }

    // ──────────────────────────────────────────
    // Teilnehmerliste CSV (AJAX)
    // ──────────────────────────────────────────
    public static function ajax_teilnehmer_csv() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error(['message' => 'Kein Event.']);

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) wp_send_json_success(['rows' => []]);

        $product_ids = array_filter(array_map('intval', array_column($cats, 'product_id')));
        if (empty($product_ids)) wp_send_json_success(['rows' => []]);

        // WooCommerce-Bestellungen mit diesen Produkten
        $orders = wc_get_orders([
            'status' => ['wc-completed', 'wc-processing'],
            'limit'  => -1,
        ]);

        // Ticket-Codes vorladen (alle für dieses Event)
        $ticket_codes_by_order = [];

        // TIX-eigene Tickets
        if (post_type_exists('tix_ticket')) {
            $tix_tickets = get_posts([
                'post_type'      => 'tix_ticket',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'meta_query'     => [['key' => '_tix_ticket_event_id', 'value' => (string) $post_id]],
            ]);
            foreach ($tix_tickets as $et) {
                $code     = get_post_meta($et->ID, '_tix_ticket_code', true);
                $order_id = get_post_meta($et->ID, '_tix_ticket_order_id', true);
                if ($code && $order_id) {
                    $ticket_codes_by_order[intval($order_id)][] = $code;
                }
            }
        }

        $rows = [];
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $pid = $item->get_product_id();
                if (!in_array($pid, $product_ids, true)) continue;

                // Ticket-Kategorie-Name finden
                $cat_name = '';
                foreach ($cats as $cat) {
                    if (intval($cat['product_id'] ?? 0) === $pid) {
                        $cat_name = $cat['name'] ?? '';
                        break;
                    }
                }

                // Ticketcodes für diese Bestellung
                $oid = $order->get_id();
                $codes = $ticket_codes_by_order[$oid] ?? [];

                $rows[] = [
                    'order_id'     => $oid,
                    'date'         => $order->get_date_created() ? $order->get_date_created()->date('d.m.Y H:i') : '',
                    'first_name'   => $order->get_billing_first_name(),
                    'last_name'    => $order->get_billing_last_name(),
                    'email'        => $order->get_billing_email(),
                    'phone'        => $order->get_billing_phone(),
                    'ticket'       => $cat_name,
                    'qty'          => $item->get_quantity(),
                    'total'        => number_format(floatval($item->get_total()), 2, ',', '.') . ' €',
                    'ticket_codes' => implode(', ', $codes),
                    'status'       => wc_get_order_status_name($order->get_status()),
                    'checkin'      => '',
                ];
            }
        }

        wp_send_json_success(['rows' => $rows]);
    }

    // ──────────────────────────────────────────
    // Inline-Erstellung: Location (AJAX)
    // ──────────────────────────────────────────
    public static function ajax_create_location() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Keine Berechtigung');

        $name    = sanitize_text_field($_POST['name'] ?? '');
        $address = sanitize_text_field($_POST['address'] ?? '');
        $desc    = wp_kses_post($_POST['description'] ?? '');

        if (empty($name)) wp_send_json_error('Name ist erforderlich');

        $post_id = wp_insert_post([
            'post_type'   => 'tix_location',
            'post_title'  => $name,
            'post_status' => 'publish',
        ]);
        if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());

        if ($address) update_post_meta($post_id, '_tix_loc_address', $address);
        if ($desc)    update_post_meta($post_id, '_tix_loc_description', $desc);

        wp_send_json_success(['id' => $post_id, 'title' => $name, 'address' => $address]);
    }

    // ──────────────────────────────────────────
    // Inline-Erstellung: Veranstalter (AJAX)
    // ──────────────────────────────────────────
    public static function ajax_create_organizer() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Keine Berechtigung');

        $name    = sanitize_text_field($_POST['name'] ?? '');
        $address = sanitize_text_field($_POST['address'] ?? '');
        $desc    = wp_kses_post($_POST['description'] ?? '');

        if (empty($name)) wp_send_json_error('Name ist erforderlich');

        $post_id = wp_insert_post([
            'post_type'   => 'tix_organizer',
            'post_title'  => $name,
            'post_status' => 'publish',
        ]);
        if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());

        if ($address) update_post_meta($post_id, '_tix_org_address', $address);
        if ($desc)    update_post_meta($post_id, '_tix_org_description', $desc);

        wp_send_json_success(['id' => $post_id, 'title' => $name, 'address' => $address]);
    }
}
