<?php
if (!defined('ABSPATH')) exit;

class TIX_CPT {

    public static function register() {

        // ── Rolle: Veranstalter (immer verfügbar) ──
        if (!get_role('tix_organizer')) {
            add_role('tix_organizer', 'Veranstalter', [
                'read'         => true,
                'upload_files' => true,
            ]);
        }

        // ── CPT: Event ──
        register_post_type('event', [
            'labels' => [
                'name'               => 'Events',
                'singular_name'      => 'Event',
                'add_new'            => 'Neues Event',
                'add_new_item'       => 'Neues Event anlegen',
                'edit_item'          => 'Event bearbeiten',
                'view_item'          => 'Event ansehen',
                'all_items'          => 'Alle Events',
                'search_items'       => 'Events suchen',
                'not_found'          => 'Keine Events gefunden',
            ],
            'public'       => true,
            'has_archive'  => true,
            'supports'     => ['title', 'excerpt', 'thumbnail'],
            'rewrite'      => ['slug' => 'events'],
            'show_in_menu'      => false,
            'show_in_admin_bar' => true,
            'show_in_rest'      => true,
        ]);

        // ── Taxonomy: Event-Kategorie ──
        register_taxonomy('event_category', 'event', [
            'labels' => [
                'name'              => 'Event-Kategorien',
                'singular_name'     => 'Event-Kategorie',
                'search_items'      => 'Kategorien suchen',
                'all_items'         => 'Alle Kategorien',
                'parent_item'       => 'Übergeordnete Kategorie',
                'parent_item_colon' => 'Übergeordnete Kategorie:',
                'edit_item'         => 'Kategorie bearbeiten',
                'update_item'       => 'Kategorie aktualisieren',
                'add_new_item'      => 'Neue Kategorie',
                'new_item_name'     => 'Neue Kategorie Name',
                'menu_name'         => 'Kategorien',
            ],
            'hierarchical'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'event-kategorie'],
        ]);

        // ── CPT: Location (classic editor — no Gutenberg) ──
        register_post_type('tix_location', [
            'labels' => [
                'name'               => 'Locations',
                'singular_name'      => 'Location',
                'add_new'            => 'Neue Location',
                'add_new_item'       => 'Neue Location anlegen',
                'edit_item'          => 'Location bearbeiten',
                'all_items'          => 'Locations',
                'search_items'       => 'Locations suchen',
                'not_found'          => 'Keine Locations gefunden',
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'tixomat',
            'supports'      => ['title'],
            'show_in_rest'  => false,
        ]);

        // ── CPT: Veranstalter (classic editor — no Gutenberg) ──
        register_post_type('tix_organizer', [
            'labels' => [
                'name'               => 'Veranstalter',
                'singular_name'      => 'Veranstalter',
                'add_new'            => 'Neuer Veranstalter',
                'add_new_item'       => 'Neuen Veranstalter anlegen',
                'edit_item'          => 'Veranstalter bearbeiten',
                'all_items'          => 'Veranstalter',
                'search_items'       => 'Veranstalter suchen',
                'not_found'          => 'Keine Veranstalter gefunden',
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'tixomat',
            'supports'      => ['title'],
            'show_in_rest'  => false,
        ]);

        // ── CPT: Verlassene Warenkörbe ──
        $ac_menu = function_exists('tix_get_settings') && tix_get_settings('abandoned_cart_enabled')
            ? 'tixomat'
            : false;
        register_post_type('tix_abandoned_cart', [
            'labels' => [
                'name'               => 'Warenkörbe',
                'singular_name'      => 'Warenkorb',
                'all_items'          => 'Verlassene Warenkörbe',
                'search_items'       => 'Warenkörbe suchen',
                'not_found'          => 'Keine Warenkörbe gefunden',
                'not_found_in_trash' => 'Keine Warenkörbe im Papierkorb',
            ],
            'public'          => false,
            'show_ui'         => $ac_menu !== false,
            'show_in_menu'    => $ac_menu,
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);

        // ── Admin-Menü ──
        add_action('admin_menu', [__CLASS__, 'register_admin_menu'], 5);
        add_action('admin_bar_menu', [__CLASS__, 'admin_bar'], 80);

        // ── Location Metabox ──
        add_action('add_meta_boxes', function() {
            add_meta_box('tix_location_meta', 'Location Details', [__CLASS__, 'render_location_meta'], 'tix_location', 'normal', 'high');
            add_meta_box('tix_organizer_meta', 'Veranstalter Details', [__CLASS__, 'render_organizer_meta'], 'tix_organizer', 'normal', 'high');
        });

        add_action('save_post_tix_location',  [__CLASS__, 'save_location'], 10, 2);
        add_action('save_post_tix_organizer', [__CLASS__, 'save_organizer'], 10, 2);

        // ── CPT: Newsletter-Subscriber ──
        register_post_type('tix_subscriber', [
            'labels' => [
                'name'               => 'Newsletter',
                'singular_name'      => 'Abonnent',
                'all_items'          => 'Newsletter',
                'search_items'       => 'Abonnenten suchen',
                'not_found'          => 'Keine Abonnenten gefunden',
                'not_found_in_trash' => 'Keine Abonnenten im Papierkorb',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'tixomat',
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);
    }

    // ── Admin Menu ──
    public static function register_admin_menu() {

        // Top-Level: Tixomat
        add_menu_page(
            'Tixomat',
            'Tixomat',
            'edit_posts',
            'tixomat',
            null,
            'dashicons-calendar-alt',
            26
        );

        // Submenu: Alle Events (Link auf edit.php?post_type=event)
        add_submenu_page('tixomat', 'Alle Events',    'Alle Events',    'edit_posts',    'edit.php?post_type=event');
        add_submenu_page('tixomat', 'Neues Event',     'Neues Event',     'edit_posts',    'post-new.php?post_type=event');
        add_submenu_page('tixomat', 'Kategorien',      'Kategorien',      'manage_categories', 'edit-tags.php?taxonomy=event_category&post_type=event');

        // Dummy-Eintrag "Tixomat" entfernen (erster auto-generierter Submenu-Eintrag)
        remove_submenu_page('tixomat', 'tixomat');

        // Menü-Highlighting für Event-CPT-Seiten korrigieren
        add_filter('parent_file', function($parent_file) {
            global $typenow;
            if (in_array($typenow, ['event', 'tix_location', 'tix_organizer', 'tix_abandoned_cart', 'tix_subscriber', 'tix_seatmap', 'tix_ticket', 'tix_support_ticket'])) {
                return 'tixomat';
            }
            return $parent_file;
        });
        add_filter('submenu_file', function($submenu_file, $parent_file) {
            global $typenow, $pagenow;
            if ($typenow === 'event') {
                if ($pagenow === 'post-new.php') return 'post-new.php?post_type=event';
                if ($pagenow === 'edit.php')     return 'edit.php?post_type=event';
            }
            return $submenu_file;
        }, 10, 2);
    }

    // ── Admin Bar ──
    public static function admin_bar($wp_admin_bar) {
        if (!current_user_can('edit_posts')) return;

        $wp_admin_bar->add_node([
            'id'    => 'tixomat',
            'title' => '<span class="ab-icon dashicons dashicons-calendar-alt" style="font:normal 20px/1 dashicons;padding:4px 0;"></span> Tixomat',
            'href'  => admin_url('edit.php?post_type=event'),
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'tixomat',
            'id'     => 'tix-all-events',
            'title'  => 'Alle Events',
            'href'   => admin_url('edit.php?post_type=event'),
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'tixomat',
            'id'     => 'tix-new-event',
            'title'  => 'Neues Event',
            'href'   => admin_url('post-new.php?post_type=event'),
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'tixomat',
            'id'     => 'tix-categories',
            'title'  => 'Kategorien',
            'href'   => admin_url('edit-tags.php?taxonomy=event_category&post_type=event'),
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'tixomat',
            'id'     => 'tix-locations',
            'title'  => 'Locations',
            'href'   => admin_url('edit.php?post_type=tix_location'),
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'tixomat',
            'id'     => 'tix-settings',
            'title'  => 'Einstellungen',
            'href'   => admin_url('admin.php?page=tix-settings'),
        ]);
    }

    // ── Location Metabox Render ──
    public static function render_location_meta($post) {
        wp_nonce_field('tix_save_location', 'tix_loc_nonce');
        $address     = get_post_meta($post->ID, '_tix_loc_address', true);
        $city        = get_post_meta($post->ID, '_tix_loc_city', true);
        $zip         = get_post_meta($post->ID, '_tix_loc_zip', true);
        $country     = get_post_meta($post->ID, '_tix_loc_country', true);
        $capacity    = get_post_meta($post->ID, '_tix_loc_capacity', true);
        $website     = get_post_meta($post->ID, '_tix_loc_website', true);
        $desc        = get_post_meta($post->ID, '_tix_loc_description', true);
        wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', [], TIXOMAT_VERSION);
        ?>
        <div class="tix-app" style="background:transparent;box-shadow:none;">
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-location"></span>
                    <h3>Adresse</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-field-grid">
                        <div class="tix-field tix-field-full">
                            <label class="tix-field-label">Straße &amp; Hausnummer</label>
                            <input type="text" name="tix_loc_address" value="<?php echo esc_attr($address); ?>" placeholder="z.B. Schanzenstraße 6-20">
                        </div>
                        <div class="tix-field">
                            <label class="tix-field-label">PLZ</label>
                            <input type="text" name="tix_loc_zip" value="<?php echo esc_attr($zip); ?>" placeholder="z.B. 51063">
                        </div>
                        <div class="tix-field">
                            <label class="tix-field-label">Stadt</label>
                            <input type="text" name="tix_loc_city" value="<?php echo esc_attr($city); ?>" placeholder="z.B. Köln">
                        </div>
                        <div class="tix-field">
                            <label class="tix-field-label">Land</label>
                            <input type="text" name="tix_loc_country" value="<?php echo esc_attr($country); ?>" placeholder="z.B. Deutschland">
                        </div>
                        <div class="tix-field">
                            <label class="tix-field-label">Kapazität</label>
                            <input type="number" name="tix_loc_capacity" value="<?php echo esc_attr($capacity); ?>" placeholder="z.B. 500" min="0">
                        </div>
                    </div>
                </div>
            </div>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-admin-links"></span>
                    <h3>Weitere Infos</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-field-grid">
                        <div class="tix-field tix-field-full">
                            <label class="tix-field-label">Website</label>
                            <input type="url" name="tix_loc_website" value="<?php echo esc_attr($website); ?>" placeholder="https://...">
                        </div>
                        <div class="tix-field tix-field-full">
                            <label class="tix-field-label">Beschreibung</label>
                            <textarea name="tix_loc_description" rows="4" placeholder="Optionale Beschreibung der Location"><?php echo esc_textarea($desc); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Location Save ──
    public static function save_location($post_id, $post) {
        if (!isset($_POST['tix_loc_nonce']) || !wp_verify_nonce($_POST['tix_loc_nonce'], 'tix_save_location')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_tix_loc_address',     sanitize_text_field($_POST['tix_loc_address'] ?? ''));
        update_post_meta($post_id, '_tix_loc_city',        sanitize_text_field($_POST['tix_loc_city'] ?? ''));
        update_post_meta($post_id, '_tix_loc_zip',         sanitize_text_field($_POST['tix_loc_zip'] ?? ''));
        update_post_meta($post_id, '_tix_loc_country',     sanitize_text_field($_POST['tix_loc_country'] ?? ''));
        update_post_meta($post_id, '_tix_loc_capacity',    sanitize_text_field($_POST['tix_loc_capacity'] ?? ''));
        update_post_meta($post_id, '_tix_loc_website',     esc_url_raw($_POST['tix_loc_website'] ?? ''));
        update_post_meta($post_id, '_tix_loc_description', wp_kses_post($_POST['tix_loc_description'] ?? ''));
    }

    // ── Veranstalter Metabox Render ──
    public static function render_organizer_meta($post) {
        wp_nonce_field('tix_save_organizer', 'tix_org_nonce');
        $address  = get_post_meta($post->ID, '_tix_org_address', true);
        $city     = get_post_meta($post->ID, '_tix_org_city', true);
        $zip      = get_post_meta($post->ID, '_tix_org_zip', true);
        $country  = get_post_meta($post->ID, '_tix_org_country', true);
        $email    = get_post_meta($post->ID, '_tix_org_email', true);
        $phone    = get_post_meta($post->ID, '_tix_org_phone', true);
        $website  = get_post_meta($post->ID, '_tix_org_website', true);
        $desc     = get_post_meta($post->ID, '_tix_org_description', true);
        $user_id  = intval(get_post_meta($post->ID, '_tix_org_user_id', true));
        wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', [], TIXOMAT_VERSION);
        ?>
        <div class="tix-app" style="background:transparent;box-shadow:none;">
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-location"></span>
                    <h3>Adresse</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-field-grid">
                        <div class="tix-field tix-field-full">
                            <label class="tix-field-label">Straße &amp; Hausnummer</label>
                            <input type="text" name="tix_org_address" value="<?php echo esc_attr($address); ?>" placeholder="z.B. Musterstraße 1">
                        </div>
                        <div class="tix-field">
                            <label class="tix-field-label">PLZ</label>
                            <input type="text" name="tix_org_zip" value="<?php echo esc_attr($zip); ?>" placeholder="z.B. 50667">
                        </div>
                        <div class="tix-field">
                            <label class="tix-field-label">Stadt</label>
                            <input type="text" name="tix_org_city" value="<?php echo esc_attr($city); ?>" placeholder="z.B. Köln">
                        </div>
                        <div class="tix-field">
                            <label class="tix-field-label">Land</label>
                            <input type="text" name="tix_org_country" value="<?php echo esc_attr($country); ?>" placeholder="z.B. Deutschland">
                        </div>
                    </div>
                </div>
            </div>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-email"></span>
                    <h3>Kontakt</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-field-grid">
                        <div class="tix-field">
                            <label class="tix-field-label">E-Mail</label>
                            <input type="email" name="tix_org_email" value="<?php echo esc_attr($email); ?>" placeholder="info@veranstalter.de">
                        </div>
                        <div class="tix-field">
                            <label class="tix-field-label">Telefon</label>
                            <input type="tel" name="tix_org_phone" value="<?php echo esc_attr($phone); ?>" placeholder="+49 221 12345">
                        </div>
                        <div class="tix-field tix-field-full">
                            <label class="tix-field-label">Website</label>
                            <input type="url" name="tix_org_website" value="<?php echo esc_attr($website); ?>" placeholder="https://...">
                        </div>
                    </div>
                </div>
            </div>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-admin-users"></span>
                    <h3>Verknüpfung</h3>
                </div>
                <div class="tix-card-body">
                    <div class="tix-field-grid">
                        <div class="tix-field tix-field-full">
                            <label class="tix-field-label">Verknüpfter Benutzer <small style="text-transform:none;font-weight:400;">(für Veranstalter-Dashboard)</small></label>
                            <?php
                            wp_dropdown_users([
                                'name'              => 'tix_org_user_id',
                                'selected'          => $user_id,
                                'show_option_none'  => '— Kein Benutzer —',
                                'option_none_value' => 0,
                            ]);
                            ?>
                        </div>
                        <div class="tix-field tix-field-full">
                            <label class="tix-field-label">Beschreibung</label>
                            <textarea name="tix_org_description" rows="4" placeholder="Optionale Beschreibung des Veranstalters"><?php echo esc_textarea($desc); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <?php // ── Gebühren-Override ── ?>
            <div class="tix-card">
                <div class="tix-card-header">
                    <span class="dashicons dashicons-money-alt"></span>
                    <h3>Individuelle Gebühren</h3>
                </div>
                <div class="tix-card-body">
                    <?php
                    $fee_override    = get_post_meta($post->ID, '_tix_fee_override', true);
                    $fee_fixed       = get_post_meta($post->ID, '_tix_fee_fixed', true);
                    $fee_percent     = get_post_meta($post->ID, '_tix_fee_percent', true);
                    $fee_mode        = get_post_meta($post->ID, '_tix_fee_mode', true) ?: 'organizer';
                    $fee_label       = get_post_meta($post->ID, '_tix_fee_label', true) ?: '';
                    $fee_max_ticket  = get_post_meta($post->ID, '_tix_fee_max_per_ticket', true);
                    $fee_max_order   = get_post_meta($post->ID, '_tix_fee_max_per_order', true);
                    $global          = function_exists('tix_get_settings') ? tix_get_settings() : [];
                    ?>
                    <div class="tix-field tix-field-full" style="margin-bottom:16px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="tix_fee_override" value="1" <?php checked($fee_override); ?>
                                   id="tix-fee-override-toggle" />
                            <strong>Individuelle Gebühren für diesen Veranstalter verwenden</strong>
                        </label>
                        <p style="color:#9ca3af;font-size:12px;margin:4px 0 0 26px;">
                            Wenn deaktiviert, gelten die globalen Einstellungen
                            (<?php echo number_format_i18n(floatval($global['fee_fixed'] ?? 0), 2); ?> € +
                            <?php echo number_format_i18n(floatval($global['fee_percent'] ?? 0), 1); ?> %,
                            <?php echo ($global['fee_mode'] ?? 'organizer') === 'customer' ? 'Kunde zahlt' : 'Veranstalter zahlt'; ?>).
                        </p>
                    </div>
                    <div id="tix-fee-override-fields" style="<?php echo $fee_override ? '' : 'display:none;'; ?>">
                        <div class="tix-field-grid">
                            <div class="tix-field">
                                <label class="tix-field-label">Fixbetrag pro Ticket</label>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <input type="number" name="tix_fee_fixed"
                                           value="<?php echo esc_attr($fee_fixed); ?>"
                                           step="0.01" min="0" style="width:100px;"
                                           placeholder="<?php echo esc_attr($global['fee_fixed'] ?? 0); ?>" />
                                    <span style="color:#6b7280;">€</span>
                                </div>
                            </div>
                            <div class="tix-field">
                                <label class="tix-field-label">Prozentualer Anteil</label>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <input type="number" name="tix_fee_percent"
                                           value="<?php echo esc_attr($fee_percent); ?>"
                                           step="0.01" min="0" max="100" style="width:100px;"
                                           placeholder="<?php echo esc_attr($global['fee_percent'] ?? 0); ?>" />
                                    <span style="color:#6b7280;">%</span>
                                </div>
                            </div>
                            <div class="tix-field">
                                <label class="tix-field-label">Wer trägt die Gebühr?</label>
                                <select name="tix_fee_mode" style="width:240px;">
                                    <option value="organizer" <?php selected($fee_mode, 'organizer'); ?>>Veranstalter (unsichtbar)</option>
                                    <option value="customer" <?php selected($fee_mode, 'customer'); ?>>Kunde (aufgeschlagen)</option>
                                </select>
                            </div>
                            <div class="tix-field">
                                <label class="tix-field-label">Bezeichnung für Kunden</label>
                                <input type="text" name="tix_fee_label"
                                       value="<?php echo esc_attr($fee_label); ?>"
                                       placeholder="<?php echo esc_attr(($global['fee_label'] ?? '') ?: 'Servicegebühr'); ?>"
                                       style="width:240px;" />
                            </div>
                            <div class="tix-field">
                                <label class="tix-field-label">Max. Gebühr pro Ticket</label>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <input type="number" name="tix_fee_max_per_ticket"
                                           value="<?php echo esc_attr($fee_max_ticket); ?>"
                                           step="0.01" min="0" style="width:100px;"
                                           placeholder="<?php echo esc_attr($global['fee_max_per_ticket'] ?? 0); ?>" />
                                    <span style="color:#6b7280;">€ <small>(0 = unbegrenzt)</small></span>
                                </div>
                            </div>
                            <div class="tix-field">
                                <label class="tix-field-label">Max. Gebühr pro Bestellung</label>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <input type="number" name="tix_fee_max_per_order"
                                           value="<?php echo esc_attr($fee_max_order); ?>"
                                           step="0.01" min="0" style="width:100px;"
                                           placeholder="<?php echo esc_attr($global['fee_max_per_order'] ?? 0); ?>" />
                                    <span style="color:#6b7280;">€ <small>(0 = unbegrenzt, überschreibt pro Ticket)</small></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <script>
                    (function(){
                        var cb = document.getElementById('tix-fee-override-toggle');
                        var wrap = document.getElementById('tix-fee-override-fields');
                        if (cb && wrap) {
                            cb.addEventListener('change', function(){ wrap.style.display = this.checked ? '' : 'none'; });
                        }
                    })();
                    </script>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Veranstalter Save ──
    public static function save_organizer($post_id, $post) {
        if (!isset($_POST['tix_org_nonce']) || !wp_verify_nonce($_POST['tix_org_nonce'], 'tix_save_organizer')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_tix_org_address',     sanitize_text_field($_POST['tix_org_address'] ?? ''));
        update_post_meta($post_id, '_tix_org_city',        sanitize_text_field($_POST['tix_org_city'] ?? ''));
        update_post_meta($post_id, '_tix_org_zip',         sanitize_text_field($_POST['tix_org_zip'] ?? ''));
        update_post_meta($post_id, '_tix_org_country',     sanitize_text_field($_POST['tix_org_country'] ?? ''));
        update_post_meta($post_id, '_tix_org_email',       sanitize_email($_POST['tix_org_email'] ?? ''));
        update_post_meta($post_id, '_tix_org_phone',       sanitize_text_field($_POST['tix_org_phone'] ?? ''));
        update_post_meta($post_id, '_tix_org_website',     esc_url_raw($_POST['tix_org_website'] ?? ''));
        update_post_meta($post_id, '_tix_org_description', wp_kses_post($_POST['tix_org_description'] ?? ''));

        $user_id = intval($_POST['tix_org_user_id'] ?? 0);
        update_post_meta($post_id, '_tix_org_user_id', $user_id);

        // ── Gebühren-Override ──
        $fee_override = !empty($_POST['tix_fee_override']);
        update_post_meta($post_id, '_tix_fee_override', $fee_override ? 1 : 0);
        if ($fee_override) {
            update_post_meta($post_id, '_tix_fee_fixed',   max(0, floatval($_POST['tix_fee_fixed'] ?? 0)));
            update_post_meta($post_id, '_tix_fee_percent', max(0, min(100, floatval($_POST['tix_fee_percent'] ?? 0))));
            update_post_meta($post_id, '_tix_fee_mode',    in_array($_POST['tix_fee_mode'] ?? '', ['organizer', 'customer']) ? $_POST['tix_fee_mode'] : 'organizer');
            update_post_meta($post_id, '_tix_fee_label',   sanitize_text_field($_POST['tix_fee_label'] ?? ''));
            update_post_meta($post_id, '_tix_fee_max_per_ticket', max(0, floatval($_POST['tix_fee_max_per_ticket'] ?? 0)));
            update_post_meta($post_id, '_tix_fee_max_per_order',  max(0, floatval($_POST['tix_fee_max_per_order'] ?? 0)));
        }
    }
}
