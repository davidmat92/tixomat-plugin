<?php
if (!defined('ABSPATH')) exit;

class TIX_CPT {

    public static function register() {

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

        // ── CPT: Location ──
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
            'show_in_rest'  => true,
        ]);

        // ── CPT: Veranstalter ──
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
            'show_in_rest'  => true,
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

        // ── CPT: Tixomat Order (eigenes Bestellsystem) ──
        register_post_type('tix_order', [
            'labels' => [
                'name'          => 'Bestellungen (Tix)',
                'singular_name' => 'Bestellung',
            ],
            'public'          => false,
            'show_ui'         => false,
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);

        // ── CPT: Tixomat Order Item (Bestellposition) ──
        register_post_type('tix_order_item', [
            'labels' => [
                'name'          => 'Bestellpositionen',
                'singular_name' => 'Bestellposition',
            ],
            'public'          => false,
            'show_ui'         => false,
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
        $address = get_post_meta($post->ID, '_tix_loc_address', true);
        $desc    = get_post_meta($post->ID, '_tix_loc_description', true);
        ?>
        <p><label><strong>Adresse</strong></label><br>
        <input type="text" name="tix_loc_address" value="<?php echo esc_attr($address); ?>" style="width:100%" placeholder="z.B. Bartholomäus-Schink-Str. 65, 50825 Köln"></p>
        <p><label><strong>Beschreibung</strong></label><br>
        <textarea name="tix_loc_description" rows="4" style="width:100%" placeholder="Optionale Beschreibung der Location"><?php echo esc_textarea($desc); ?></textarea></p>
        <?php
    }

    // ── Location Save ──
    public static function save_location($post_id, $post) {
        if (!isset($_POST['tix_loc_nonce']) || !wp_verify_nonce($_POST['tix_loc_nonce'], 'tix_save_location')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_tix_loc_address', sanitize_text_field($_POST['tix_loc_address'] ?? ''));
        update_post_meta($post_id, '_tix_loc_description', wp_kses_post($_POST['tix_loc_description'] ?? ''));
    }

    // ── Veranstalter Metabox Render ──
    public static function render_organizer_meta($post) {
        wp_nonce_field('tix_save_organizer', 'tix_org_nonce');
        $address = get_post_meta($post->ID, '_tix_org_address', true);
        $desc    = get_post_meta($post->ID, '_tix_org_description', true);
        $user_id = intval(get_post_meta($post->ID, '_tix_org_user_id', true));
        ?>
        <p><label><strong>Adresse</strong></label><br>
        <input type="text" name="tix_org_address" value="<?php echo esc_attr($address); ?>" style="width:100%" placeholder="z.B. Musterstraße 1, 50667 Köln"></p>
        <p><label><strong>Beschreibung</strong></label><br>
        <textarea name="tix_org_description" rows="4" style="width:100%" placeholder="Optionale Beschreibung des Veranstalters"><?php echo esc_textarea($desc); ?></textarea></p>
        <p><label><strong>Verkn&uuml;pfter Benutzer</strong> <small>(f&uuml;r Veranstalter-Dashboard)</small></label><br>
        <?php
        wp_dropdown_users([
            'name'             => 'tix_org_user_id',
            'selected'         => $user_id,
            'show_option_none' => '— Kein Benutzer —',
            'option_none_value' => 0,
        ]);
        ?>
        </p>
        <?php
    }

    // ── Veranstalter Save ──
    public static function save_organizer($post_id, $post) {
        if (!isset($_POST['tix_org_nonce']) || !wp_verify_nonce($_POST['tix_org_nonce'], 'tix_save_organizer')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_tix_org_address', sanitize_text_field($_POST['tix_org_address'] ?? ''));
        update_post_meta($post_id, '_tix_org_description', wp_kses_post($_POST['tix_org_description'] ?? ''));

        // Verknüpfter Benutzer (für Veranstalter-Dashboard)
        $user_id = intval($_POST['tix_org_user_id'] ?? 0);
        update_post_meta($post_id, '_tix_org_user_id', $user_id);
    }
}
