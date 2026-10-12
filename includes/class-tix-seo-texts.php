<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_SEO_Texts – redaktionelle Texte für Sparten- und Städte-Seiten (z. B. evendis.de
 * /events/koeln/, /events/comedy/): Einleitung (HTML) und optional ein Bild.
 *
 * - Sparten: Term-Meta der Taxonomie `event_category` (`_tix_intro`, `_tix_image_id`),
 *   gepflegt unter Events → Kategorien.
 * - Städte: Site-Option `tix_city_texts` {slug: {name, intro, image_id}}, gepflegt unter
 *   Tixomat → Städte-Texte (Städte kommender Events + frei ergänzbar).
 * - Ausgabe: GET /public/categories und /public/cities um `intro`, `image` (und `slug`) ergänzt.
 */
class TIX_SEO_Texts {

    const META_INTRO  = '_tix_intro';
    const META_IMAGE  = '_tix_image_id';
    const OPTION_CITY = 'tix_city_texts';
    const PAGE        = 'tix-city-texts';

    public static function init() {
        add_action('event_category_add_form_fields',  [__CLASS__, 'term_add_fields']);
        add_action('event_category_edit_form_fields', [__CLASS__, 'term_edit_fields']);
        add_action('created_event_category', [__CLASS__, 'term_save']);
        add_action('edited_event_category',  [__CLASS__, 'term_save']);
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_post_tix_city_texts_save', [__CLASS__, 'city_save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    // ── Slug / Ausgabe ──

    /** Stadt → Slug wie in den URLs (Köln → koeln, Düsseldorf → duesseldorf, Weißenfels → weissenfels). */
    public static function city_slug($name) {
        $name = str_replace(['Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü', 'ß'], ['Ae', 'Oe', 'Ue', 'ae', 'oe', 'ue', 'ss'], (string) $name);
        return sanitize_title(remove_accents($name));
    }

    private static function image($id) {
        $id = intval($id);
        if (!$id) return null;
        $url = wp_get_attachment_image_url($id, 'large');
        if (!$url) return null;
        return [
            'url'   => (string) $url,
            'thumb' => (string) wp_get_attachment_image_url($id, 'medium'),
            'alt'   => trim((string) get_post_meta($id, '_wp_attachment_image_alt', true)),
        ];
    }

    /** Zusatzfelder einer Sparte für die API. */
    public static function category_extra($term_id) {
        return [
            'intro' => (string) get_term_meta(intval($term_id), self::META_INTRO, true),
            'image' => self::image(get_term_meta(intval($term_id), self::META_IMAGE, true)),
        ];
    }

    private static function cities() {
        $raw = get_option(self::OPTION_CITY, []);
        return is_array($raw) ? $raw : [];
    }

    /** Zusatzfelder einer Stadt für die API. */
    public static function city_extra($name) {
        $slug = self::city_slug($name);
        $row = self::cities()[$slug] ?? [];
        return [
            'slug'  => $slug,
            'intro' => (string) ($row['intro'] ?? ''),
            'image' => self::image($row['image_id'] ?? 0),
        ];
    }

    // ── Admin: Sparten ──

    public static function assets($hook) {
        $page = $_GET['page'] ?? '';
        $tax  = $_GET['taxonomy'] ?? '';
        if (!in_array($hook, ['edit-tags.php', 'term.php'], true) && $page !== self::PAGE) return;
        if (in_array($hook, ['edit-tags.php', 'term.php'], true) && !in_array($tax, ['event_category', 'tix_collection'], true)) return;
        wp_enqueue_media();
        wp_add_inline_script('jquery-core', "jQuery(function($){\n"
            . "$(document).on('click','.tix-seo-pick',function(e){e.preventDefault();var w=$(this).closest('.tix-seo-image'),f=wp.media({title:'Bild wählen',library:{type:'image'},multiple:false});"
            . "f.on('select',function(){var a=f.state().get('selection').first().toJSON();w.find('input').val(a.id);w.find('.tix-seo-preview').html('<img src=\"'+((a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url)+'\" style=\"max-width:240px;height:auto;border-radius:8px\">');});f.open();});\n"
            . "$(document).on('click','.tix-seo-clear',function(e){e.preventDefault();var w=$(this).closest('.tix-seo-image');w.find('input').val('');w.find('.tix-seo-preview').empty();});\n"
            . "});");
    }

    /** Bildauswahl (Mediathek) für Admin-Formulare – auch TIX_Collections. */
    public static function image_field($name, $id) {
        $id = intval($id);
        $img = $id ? wp_get_attachment_image_url($id, 'medium') : '';
        ob_start(); ?>
        <div class="tix-seo-image">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo $id ?: ''; ?>">
            <div class="tix-seo-preview" style="margin:0 0 8px;"><?php if ($img) echo '<img src="' . esc_url($img) . '" style="max-width:240px;height:auto;border-radius:8px">'; ?></div>
            <button type="button" class="button tix-seo-pick">Bild wählen</button>
            <button type="button" class="button-link tix-seo-clear" style="margin-left:8px;">Entfernen</button>
        </div>
        <?php return ob_get_clean();
    }

    public static function term_add_fields() {
        wp_nonce_field('tix_seo_term', '_tix_seo_nonce'); ?>
        <div class="form-field">
            <label for="tix-seo-intro">Einleitung für die Sparten-Seite</label>
            <textarea id="tix-seo-intro" name="tix_seo_intro" rows="6"></textarea>
            <p>Text über den Events dieser Sparte (z. B. evendis.de/events/comedy/). Einfaches HTML erlaubt (Absätze, Links, fett).</p>
        </div>
        <div class="form-field">
            <label>Bild</label>
            <?php echo self::image_field('tix_seo_image', 0); ?>
        </div>
        <?php
    }

    public static function term_edit_fields($term) {
        wp_nonce_field('tix_seo_term', '_tix_seo_nonce'); ?>
        <tr class="form-field">
            <th scope="row"><label for="tix-seo-intro">Einleitung für die Sparten-Seite</label></th>
            <td><textarea id="tix-seo-intro" name="tix_seo_intro" rows="8" class="large-text"><?php echo esc_textarea((string) get_term_meta($term->term_id, self::META_INTRO, true)); ?></textarea>
                <p class="description">Text über den Events dieser Sparte. Einfaches HTML erlaubt (Absätze, Links, fett).</p></td>
        </tr>
        <tr class="form-field">
            <th scope="row">Bild</th>
            <td><?php echo self::image_field('tix_seo_image', get_term_meta($term->term_id, self::META_IMAGE, true)); ?></td>
        </tr>
        <?php
    }

    public static function term_save($term_id) {
        if (!isset($_POST['_tix_seo_nonce']) || !wp_verify_nonce($_POST['_tix_seo_nonce'], 'tix_seo_term')) return;
        if (!current_user_can('manage_categories')) return;
        $intro = wp_kses_post(wp_unslash((string) ($_POST['tix_seo_intro'] ?? '')));
        if (trim($intro) === '') delete_term_meta($term_id, self::META_INTRO); else update_term_meta($term_id, self::META_INTRO, $intro);
        $img = intval($_POST['tix_seo_image'] ?? 0);
        if ($img && get_post_type($img) === 'attachment') update_term_meta($term_id, self::META_IMAGE, $img); else delete_term_meta($term_id, self::META_IMAGE);
    }

    // ── Admin: Städte ──

    public static function menu() {
        add_submenu_page('tixomat', 'Städte-Texte', 'Städte-Texte', 'manage_options', self::PAGE, [__CLASS__, 'city_page']);
    }

    /** Städte kommender Events (aus der öffentlichen Liste) + gespeicherte. */
    private static function city_rows() {
        $rows = [];
        if (class_exists('TIX_Public_Platform')) {
            $res = TIX_Public_Platform::rest_cities(new WP_REST_Request('GET', '/tixomat/v1/public/cities'));
            $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
            foreach ((array) ($data['cities'] ?? []) as $c) {
                $slug = self::city_slug($c['name']);
                $rows[$slug] = ['name' => $c['name'], 'upcoming' => intval($c['upcoming'] ?? 0)];
            }
        }
        foreach (self::cities() as $slug => $c) {
            if (!isset($rows[$slug])) $rows[$slug] = ['name' => (string) ($c['name'] ?? $slug), 'upcoming' => 0];
        }
        uasort($rows, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        return $rows;
    }

    public static function city_page() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        $saved = self::cities();
        $rows  = self::city_rows();
        ?>
        <div class="wrap">
            <h1>Städte-Texte</h1>
            <p style="max-width:720px;color:#64748b;">Einleitung und Bild für die Städte-Seiten (z. B. evendis.de/events/koeln/). Die Liste zeigt alle Städte mit kommenden Events; weitere kannst du unten ergänzen. Die Texte erscheinen in <code>GET /public/cities</code> als <code>intro</code> und <code>image</code>.</p>
            <?php if (!empty($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>'; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tix_city_texts_save">
                <?php wp_nonce_field('tix_city_texts'); ?>
                <table class="widefat striped" style="max-width:1000px;">
                    <thead><tr><th style="width:180px;">Stadt</th><th>Einleitung (einfaches HTML)</th><th style="width:270px;">Bild</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $slug => $r) : $s = $saved[$slug] ?? []; ?>
                        <tr>
                            <td><strong><?php echo esc_html($r['name']); ?></strong><br><code><?php echo esc_html($slug); ?></code><br><span style="color:#64748b;"><?php echo intval($r['upcoming']); ?> kommende Events</span>
                                <input type="hidden" name="cities[<?php echo esc_attr($slug); ?>][name]" value="<?php echo esc_attr($r['name']); ?>"></td>
                            <td><textarea name="cities[<?php echo esc_attr($slug); ?>][intro]" rows="5" class="large-text"><?php echo esc_textarea((string) ($s['intro'] ?? '')); ?></textarea></td>
                            <td><?php echo self::image_field('cities[' . $slug . '][image_id]', $s['image_id'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr>
                            <td><input type="text" name="new_city" placeholder="Weitere Stadt" class="regular-text" style="width:100%;"></td>
                            <td><textarea name="new_intro" rows="3" class="large-text" placeholder="Einleitung"></textarea></td>
                            <td><?php echo self::image_field('new_image', 0); ?></td>
                        </tr>
                    </tbody>
                </table>
                <p><button type="submit" class="button button-primary">Speichern</button></p>
            </form>
        </div>
        <?php
    }

    public static function city_save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_city_texts');
        $out = [];
        $in = isset($_POST['cities']) && is_array($_POST['cities']) ? wp_unslash($_POST['cities']) : [];
        $new = trim(sanitize_text_field(wp_unslash((string) ($_POST['new_city'] ?? ''))));
        if ($new !== '') {
            $in[self::city_slug($new)] = ['name' => $new, 'intro' => wp_unslash((string) ($_POST['new_intro'] ?? '')), 'image_id' => $_POST['new_image'] ?? 0];
        }
        foreach ($in as $slug => $c) {
            $slug  = self::city_slug((string) ($c['name'] ?? $slug));
            $intro = wp_kses_post((string) ($c['intro'] ?? ''));
            $img   = intval($c['image_id'] ?? 0);
            if ($img && get_post_type($img) !== 'attachment') $img = 0;
            if ($slug === '' || (trim($intro) === '' && !$img)) continue; // leere Zeilen nicht speichern
            $out[$slug] = ['name' => sanitize_text_field((string) ($c['name'] ?? $slug)), 'intro' => $intro, 'image_id' => $img];
        }
        update_option(self::OPTION_CITY, $out, false);
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'saved' => 1], admin_url('admin.php')));
        exit;
    }
}
