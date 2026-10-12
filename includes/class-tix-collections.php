<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Collections – Themen-Sammlungen („Karneval & 11.11.“, „Halloween“) für die Plattform.
 *
 * Taxonomie `tix_collection` am Event (mehrfach wählbar, nur Plattform-Admins mit
 * `manage_categories` pflegen und zuordnen). Term-Meta: `_tix_intro` (HTML), `_tix_image_id`,
 * `_tix_visible_from` / `_tix_visible_to` (Y-m-d, optional).
 * Ausgabe: `collections` [slug] je Event in /public/events, Filter `?collection=<slug>`,
 * Liste GET /public/collections (TIX_Public_Platform). Nicht Teil der Event-Verteilung an Partner.
 */
class TIX_Collections {

    const TAX       = 'tix_collection';
    const META_FROM = '_tix_visible_from';
    const META_TO   = '_tix_visible_to';

    public static function init() {
        add_action('init', [__CLASS__, 'register'], 11);
        add_action(self::TAX . '_add_form_fields',  [__CLASS__, 'add_fields']);
        add_action(self::TAX . '_edit_form_fields', [__CLASS__, 'edit_fields']);
        add_action('created_' . self::TAX, [__CLASS__, 'save']);
        add_action('edited_' . self::TAX,  [__CLASS__, 'save']);
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        // Öffentliche Event-Listen sofort aktualisieren
        foreach (['created_' . self::TAX, 'edited_' . self::TAX, 'delete_' . self::TAX] as $h) add_action($h, [__CLASS__, 'flush']);
        add_action('set_object_terms', function ($object_id, $terms, $tt_ids, $taxonomy) {
            if ($taxonomy === TIX_Collections::TAX) TIX_Collections::flush();
        }, 10, 4);
    }

    public static function register() {
        $caps = 'manage_categories';
        register_taxonomy(self::TAX, 'event', [
            'labels' => [
                'name'          => 'Themen-Sammlungen',
                'singular_name' => 'Themen-Sammlung',
                'search_items'  => 'Sammlungen suchen',
                'all_items'     => 'Alle Sammlungen',
                'edit_item'     => 'Sammlung bearbeiten',
                'update_item'   => 'Sammlung aktualisieren',
                'add_new_item'  => 'Neue Sammlung',
                'new_item_name' => 'Name der Sammlung',
                'menu_name'     => 'Sammlungen',
            ],
            // Häkchen-Liste im Event (mehrfach wählbar); keine eigenen WordPress-Seiten (die Website baut sie)
            'hierarchical'       => true,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'show_in_nav_menus'  => false,
            'show_in_rest'       => false,
            'show_admin_column'  => true,
            'rewrite'            => false,
            'capabilities'       => ['manage_terms' => $caps, 'edit_terms' => $caps, 'delete_terms' => $caps, 'assign_terms' => $caps],
        ]);
    }

    public static function menu() {
        add_submenu_page('tixomat', 'Themen-Sammlungen', 'Sammlungen', 'manage_categories', 'edit-tags.php?taxonomy=' . self::TAX . '&post_type=event');
    }

    public static function flush() {
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        delete_transient('tix_pub_events_all_upcoming');
    }

    // ── Admin-Felder ──

    public static function add_fields() {
        wp_nonce_field('tix_collection', '_tix_collection_nonce'); ?>
        <div class="form-field">
            <label for="tix-col-intro">Einleitung</label>
            <textarea id="tix-col-intro" name="tix_col_intro" rows="6"></textarea>
            <p>Text der Sammlungs-Seite (z. B. evendis.de/themen/halloween/). Einfaches HTML erlaubt.</p>
        </div>
        <div class="form-field">
            <label>Bild</label>
            <?php echo class_exists('TIX_SEO_Texts') ? TIX_SEO_Texts::image_field('tix_col_image', 0) : ''; ?>
        </div>
        <div class="form-field">
            <label>Sichtbar (optional)</label>
            <input type="date" name="tix_col_from" style="width:auto"> bis <input type="date" name="tix_col_to" style="width:auto">
            <p>Leer = immer. Außerhalb des Zeitraums erscheint die Sammlung nicht in <code>/public/collections</code>.</p>
        </div>
        <?php
    }

    public static function edit_fields($term) {
        wp_nonce_field('tix_collection', '_tix_collection_nonce'); ?>
        <tr class="form-field">
            <th scope="row"><label for="tix-col-intro">Einleitung</label></th>
            <td><textarea id="tix-col-intro" name="tix_col_intro" rows="8" class="large-text"><?php echo esc_textarea((string) get_term_meta($term->term_id, '_tix_intro', true)); ?></textarea>
                <p class="description">Text der Sammlungs-Seite. Einfaches HTML erlaubt (Absätze, Links, fett).</p></td>
        </tr>
        <tr class="form-field">
            <th scope="row">Bild</th>
            <td><?php echo class_exists('TIX_SEO_Texts') ? TIX_SEO_Texts::image_field('tix_col_image', get_term_meta($term->term_id, '_tix_image_id', true)) : ''; ?></td>
        </tr>
        <tr class="form-field">
            <th scope="row">Sichtbar (optional)</th>
            <td><input type="date" name="tix_col_from" value="<?php echo esc_attr((string) get_term_meta($term->term_id, self::META_FROM, true)); ?>" style="width:auto">
                bis <input type="date" name="tix_col_to" value="<?php echo esc_attr((string) get_term_meta($term->term_id, self::META_TO, true)); ?>" style="width:auto">
                <p class="description">Leer = immer sichtbar.</p></td>
        </tr>
        <?php
    }

    private static function date_or_empty($v) {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    public static function save($term_id) {
        if (!isset($_POST['_tix_collection_nonce']) || !wp_verify_nonce($_POST['_tix_collection_nonce'], 'tix_collection')) return;
        if (!current_user_can('manage_categories')) return;
        $intro = wp_kses_post(wp_unslash((string) ($_POST['tix_col_intro'] ?? '')));
        if (trim($intro) === '') delete_term_meta($term_id, '_tix_intro'); else update_term_meta($term_id, '_tix_intro', $intro);
        $img = intval($_POST['tix_col_image'] ?? 0);
        if ($img && get_post_type($img) === 'attachment') update_term_meta($term_id, '_tix_image_id', $img); else delete_term_meta($term_id, '_tix_image_id');
        foreach (['tix_col_from' => self::META_FROM, 'tix_col_to' => self::META_TO] as $field => $meta) {
            $d = self::date_or_empty($_POST[$field] ?? '');
            if ($d === '') delete_term_meta($term_id, $meta); else update_term_meta($term_id, $meta, $d);
        }
    }

    // ── Ausgabe ──

    /** Slugs der Sammlungen eines Events. */
    public static function event_slugs($event_id) {
        if (!taxonomy_exists(self::TAX)) return [];
        $terms = wp_get_object_terms(intval($event_id), self::TAX, ['fields' => 'slugs']);
        return is_wp_error($terms) ? [] : array_values(array_map('strval', $terms));
    }

    /** Heute im Sichtbarkeits-Zeitraum? */
    public static function visible($term_id) {
        $today = current_time('Y-m-d');
        $from = (string) get_term_meta($term_id, self::META_FROM, true);
        $to   = (string) get_term_meta($term_id, self::META_TO, true);
        if ($from !== '' && $today < $from) return false;
        if ($to !== '' && $today > $to) return false;
        return true;
    }

    private static function image($id) {
        $id = intval($id);
        if (!$id) return null;
        $src = wp_get_attachment_image_src($id, 'large');
        if (!$src) return null;
        return [
            'url'    => (string) $src[0],
            'alt'    => trim((string) get_post_meta($id, '_wp_attachment_image_alt', true)),
            'width'  => intval($src[1]),
            'height' => intval($src[2]),
        ];
    }

    /**
     * Sichtbare Sammlungen mit kommenden Events.
     * @param array $upcoming Event-Payloads (kommend), je mit `collections` [slug]
     */
    public static function public_list(array $upcoming) {
        if (!taxonomy_exists(self::TAX)) return [];
        $count = [];
        foreach ($upcoming as $e) {
            foreach ((array) ($e['collections'] ?? []) as $slug) $count[$slug] = ($count[$slug] ?? 0) + 1;
        }
        $terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false]);
        if (is_wp_error($terms)) return [];
        $out = [];
        foreach ($terms as $t) {
            $n = intval($count[$t->slug] ?? 0);
            if ($n < 1 || !self::visible($t->term_id)) continue;
            $from = (string) get_term_meta($t->term_id, self::META_FROM, true);
            $to   = (string) get_term_meta($t->term_id, self::META_TO, true);
            $out[] = [
                'slug'      => (string) $t->slug,
                'name'      => (string) $t->name,
                'intro'     => (string) get_term_meta($t->term_id, '_tix_intro', true),
                'image'     => self::image(get_term_meta($t->term_id, '_tix_image_id', true)),
                'upcoming'  => $n,
                'starts_at' => $from !== '' ? $from : null,
                'ends_at'   => $to !== '' ? $to : null,
            ];
        }
        // Zeitlich begrenzte zuerst (nach Ende), dann nach Anzahl
        usort($out, function ($a, $b) {
            if (($a['ends_at'] === null) !== ($b['ends_at'] === null)) return $a['ends_at'] === null ? 1 : -1;
            if ($a['ends_at'] !== $b['ends_at']) return strcmp((string) $a['ends_at'], (string) $b['ends_at']);
            return $b['upcoming'] <=> $a['upcoming'] ?: strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }
}
