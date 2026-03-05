<?php
/**
 * Tixomat – Ticket-Vorlagen CPT
 *
 * Verwaltet Ticket-Vorlagen als eigenen Post Type.
 * Jede Vorlage enthält eine Template-Konfiguration (JSON)
 * die im visuellen Editor bearbeitet wird.
 *
 * @since 1.28.0
 */
if (!defined('ABSPATH')) exit;

class TIX_Ticket_Template_CPT {

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('save_post_tix_ticket_tpl', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    // Register CPT
    public static function register_cpt() {
        register_post_type('tix_ticket_tpl', [
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'tixomat',  // Sub-menu under tixomat
            'show_in_rest'       => false,
            'supports'           => ['title', 'thumbnail'],
            'labels'             => [
                'name'               => 'Ticket-Vorlagen',
                'singular_name'      => 'Ticket-Vorlage',
                'add_new'            => 'Neue Vorlage',
                'add_new_item'       => 'Neue Ticket-Vorlage erstellen',
                'edit_item'          => 'Ticket-Vorlage bearbeiten',
                'new_item'           => 'Neue Vorlage',
                'view_item'          => 'Vorlage ansehen',
                'search_items'       => 'Vorlagen suchen',
                'not_found'          => 'Keine Vorlagen gefunden',
                'not_found_in_trash' => 'Keine Vorlagen im Papierkorb',
                'menu_name'          => 'Ticket-Vorlagen',
                'all_items'          => 'Ticket-Vorlagen',
            ],
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'menu_icon'          => 'dashicons-layout',
        ]);
    }

    // Metabox with the template editor
    public static function register_metabox() {
        add_meta_box(
            'tix_ticket_tpl_editor',
            'Template-Editor',
            [__CLASS__, 'render_editor'],
            'tix_ticket_tpl',
            'normal',
            'high'
        );
    }

    // Render the editor metabox
    // This uses the same TIX_TemplateEditor JS component as the Settings page
    public static function render_editor($post) {
        wp_nonce_field('tix_ticket_tpl_save', '_tix_tpl_nonce');

        $config_json = get_post_meta($post->ID, '_tix_template_config', true);
        if (!$config_json) $config_json = '';

        // Hidden input for the JSON config
        echo '<input type="hidden" id="tix-tpl-config" name="tix_template_config" value="' . esc_attr($config_json) . '">';

        // Editor container (JS will populate this)
        echo '<div id="tix-tpl-editor-wrap" class="tix-tte-wrap"></div>';
    }

    // Save handler
    public static function save($post_id, $post) {
        if (!isset($_POST['_tix_tpl_nonce']) || !wp_verify_nonce($_POST['_tix_tpl_nonce'], 'tix_ticket_tpl_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $config_json = wp_unslash($_POST['tix_template_config'] ?? '');

        // Sanitize via the existing template class
        if ($config_json && class_exists('TIX_Ticket_Template')) {
            $config = TIX_Ticket_Template::sanitize_config($config_json);
            $config_json = wp_json_encode($config);
        }

        update_post_meta($post_id, '_tix_template_config', $config_json);
    }

    // Enqueue editor assets on CPT edit screen
    public static function enqueue_assets($hook) {
        global $post_type;
        if ($post_type !== 'tix_ticket_tpl') return;
        if (!in_array($hook, ['post.php', 'post-new.php'])) return;

        wp_enqueue_media();

        $base_url = TIXOMAT_URL;
        $version  = TIXOMAT_VERSION;

        wp_enqueue_style('tix-template-editor', $base_url . 'assets/css/ticket-template-editor.css', [], $version);
        wp_enqueue_script('tix-template-editor', $base_url . 'assets/js/ticket-template-editor.js', ['jquery'], $version, true);

        // Field definitions from template class
        $field_defs = [];
        if (class_exists('TIX_Ticket_Template')) {
            $field_defs = TIX_Ticket_Template::field_definitions();
        }

        // Preview data for placeholder text
        $preview_data = [];
        if (class_exists('TIX_Ticket_Template')) {
            $preview_data = TIX_Ticket_Template::preview_data();
        }

        // Inline script to initialize the editor
        wp_add_inline_script('tix-template-editor', '
            jQuery(function($) {
                window.tixPreviewData = ' . wp_json_encode($preview_data) . ';
                if (typeof TIX_TemplateEditor === "function") {
                    new TIX_TemplateEditor("#tix-tpl-editor-wrap", {
                        inputSelector: "#tix-tpl-config",
                        nonceAction: "tix_template_preview",
                        nonce: "' . wp_create_nonce('tix_template_preview') . '",
                        ajaxUrl: "' . admin_url('admin-ajax.php') . '",
                        fieldDefs: ' . wp_json_encode($field_defs) . '
                    });
                }
            });
        ');
    }

    // Get all published templates as id => title
    public static function get_all_templates() {
        $posts = get_posts([
            'post_type'      => 'tix_ticket_tpl',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $templates = [];
        foreach ($posts as $p) {
            $templates[$p->ID] = $p->post_title;
        }
        return $templates;
    }

    // Get config for a specific template
    public static function get_config($template_id) {
        $post = get_post($template_id);
        if (!$post || $post->post_type !== 'tix_ticket_tpl' || $post->post_status !== 'publish') {
            return null;
        }

        $json = get_post_meta($template_id, '_tix_template_config', true);
        if (empty($json)) return null;

        $config = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($config)) return null;

        // Sanitize via template class
        if (class_exists('TIX_Ticket_Template')) {
            $config = TIX_Ticket_Template::sanitize_config($config);
            if (!$config['template_image_id']) return null;
        }

        return $config;
    }
}
