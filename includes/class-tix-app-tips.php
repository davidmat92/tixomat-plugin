<?php
/**
 * App-Tipps („Tipp des Tages“) für die Apps KitchenKlub und evendis.
 *
 * Admins (`manage_options`) legen Tipps als eigenen CPT `tix_app_tip` an –
 * auch im Mehr-Veranstalter-Modus dürfen Veranstalter das nicht. Ein Tipp hat
 * Text, optional Person (Name + Porträt), eigenes Bild oder verknüpftes Event,
 * Etikett, Link/App-Aktion, Plätze in der App und einen Zeitraum.
 *
 *   GET /public/tips[?placement=<slug>]   (öffentlich, 60 s im Transient)
 *
 * Mehrere Tipps am selben Platz zeigt die App als Slider (Reihenfolge =
 * `menu_order`, danach neueste zuerst).
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Tips {

    const NS  = 'tixomat/v1';
    const CPT = 'tix_app_tip';
    const TTL = 60;

    /** Cache-Version (Option): Hochzählen verwirft alle Tipp-Transients, auch mit Objekt-Cache. */
    const CACHE_VERSION_OPTION = 'tix_app_tips_cache_v';

    const DEFAULT_LABEL = 'Tipp des Tages';

    /** Plätze in den Apps: Slug ⇒ Admin-Beschriftung. */
    const PLACEMENTS = [
        'home_top'     => 'Startseite oben (unter der Suche)',
        'home_middle'  => 'Startseite Mitte',
        'home_bottom'  => 'Startseite unten',
        'event_detail' => 'Event-Seite (unter den Infos)',
        'tickets'      => 'Tickets-Tab oben',
        'saved'        => 'Merkliste oben (evendis)',
        'account'      => 'Konto oben',
    ];

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        // Admin
        add_action('add_meta_boxes_' . self::CPT, [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'save'], 10, 2);
        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'column_content'], 10, 2);
        add_filter('manage_edit-' . self::CPT . '_sortable_columns', [__CLASS__, 'sortable_columns']);
        add_action('pre_get_posts', [__CLASS__, 'admin_order']);
        add_filter('parent_file', [__CLASS__, 'parent_file']);

        // Cache verwerfen: Tipp gespeichert/gelöscht/Status geändert, Event gespeichert
        add_action('save_post_' . self::CPT, [__CLASS__, 'flush'], 20);
        add_action('save_post_event', [__CLASS__, 'flush'], 20);
        add_action('transition_post_status', [__CLASS__, 'flush_on_transition'], 10, 3);
        add_action('deleted_post', [__CLASS__, 'flush_on_delete'], 10, 2);
    }

    // ──────────────────────────────────────────
    //  CPT
    // ──────────────────────────────────────────

    public static function register_cpt() {
        // Alle Rechte verlangen manage_options (auch Einzel-Rechte wie edit_post)
        $caps = [];
        foreach ([
            'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
            'publish_posts', 'read_private_posts', 'read', 'delete_posts', 'delete_private_posts',
            'delete_published_posts', 'delete_others_posts', 'edit_private_posts',
            'edit_published_posts', 'create_posts',
        ] as $c) {
            $caps[$c] = 'manage_options';
        }

        register_post_type(self::CPT, [
            'labels' => [
                'name'               => 'App-Tipps',
                'singular_name'      => 'App-Tipp',
                'add_new'            => 'Neuer Tipp',
                'add_new_item'       => 'Neuen App-Tipp anlegen',
                'edit_item'          => 'App-Tipp bearbeiten',
                'new_item'           => 'Neuer App-Tipp',
                'all_items'          => 'App-Tipps',
                'search_items'       => 'App-Tipps suchen',
                'not_found'          => 'Keine App-Tipps gefunden',
                'not_found_in_trash' => 'Keine App-Tipps im Papierkorb',
            ],
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => 'tixomat',
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'hierarchical'        => false,
            'supports'            => ['title', 'page-attributes'],
            'capabilities'        => $caps,
            'map_meta_cap'        => false,
        ]);
    }

    /** Menü „Tixomat“ beim Bearbeiten eines Tipps aufgeklappt lassen. */
    public static function parent_file($parent_file) {
        global $typenow;
        return $typenow === self::CPT ? 'tixomat' : $parent_file;
    }

    // ──────────────────────────────────────────
    //  Hilfen
    // ──────────────────────────────────────────

    /** Gespeicherten Zeitpunkt (lokal, 'Y-m-d H:i') als DateTimeImmutable in Seiten-Zeitzone. */
    private static function to_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return null;
        try {
            return new DateTimeImmutable($value, wp_timezone());
        } catch (Exception $e) {
            return null;
        }
    }

    /** Eingabe aus datetime-local ('Y-m-d\TH:i') → 'Y-m-d H:i' oder ''. */
    private static function sanitize_datetime($raw) {
        $raw = trim(str_replace('T', ' ', sanitize_text_field((string) $raw)));
        if ($raw === '') return '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw)) return '';
        $dt = self::to_datetime($raw);
        return $dt ? $dt->format('Y-m-d H:i') : '';
    }

    /** Link: URL (http/https/mailto/tel) oder App-Aktion wie `event:123`, `page:faq`. */
    private static function sanitize_link($raw) {
        $raw = trim(wp_unslash((string) $raw));
        if ($raw === '') return '';
        if (preg_match('#^(https?://|mailto:|tel:)#i', $raw)) return esc_url_raw($raw);
        return sanitize_text_field($raw);
    }

    /** Plätze eines Tipps (nur bekannte Slugs). */
    public static function placements_of($id) {
        $p = get_post_meta($id, '_tix_tip_placements', true);
        if (!is_array($p)) return [];
        return array_values(array_intersect(array_keys(self::PLACEMENTS), $p));
    }

    /** Gilt der Zeitraum des Tipps gerade? */
    private static function is_current($id, $now_ts) {
        $start = self::to_datetime(get_post_meta($id, '_tix_tip_start', true));
        $end   = self::to_datetime(get_post_meta($id, '_tix_tip_end', true));
        if ($start && $start->getTimestamp() > $now_ts) return false;
        if ($end && $end->getTimestamp() < $now_ts) return false;
        return true;
    }

    private static function iso($value) {
        $dt = self::to_datetime($value);
        return $dt ? $dt->format('c') : '';
    }

    /** Kommende veröffentlichte Events für die Auswahl (+ aktuell gewähltes). */
    private static function event_options($selected) {
        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'fields'         => 'ids',
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key'     => '_tix_date_start',
                'value'   => wp_date('Y-m-d'),
                'compare' => '>=',
                'type'    => 'DATE',
            ]],
        ]);
        if ($selected && !in_array($selected, $ids, true) && get_post_type($selected) === 'event') {
            array_unshift($ids, $selected);
        }
        $out = [];
        foreach ($ids as $id) {
            $date = (string) get_post_meta($id, '_tix_date_start', true);
            $label = get_the_title($id);
            if ($date !== '') $label = date_i18n('d.m.Y', strtotime($date)) . ' – ' . $label;
            if (get_post_status($id) !== 'publish') $label .= ' (nicht veröffentlicht)';
            $out[$id] = $label;
        }
        return $out;
    }

    // ──────────────────────────────────────────
    //  Metabox
    // ──────────────────────────────────────────

    public static function add_meta_boxes() {
        add_meta_box('tix_app_tip_meta', 'Tipp', [__CLASS__, 'render_meta_box'], self::CPT, 'normal', 'high');
    }

    private static function image_field($name, $label, $att_id, $hint) {
        $url = $att_id ? wp_get_attachment_image_url($att_id, 'medium') : '';
        ?>
        <div class="tix-tip-field">
            <label class="tix-tip-label"><?php echo esc_html($label); ?></label>
            <div class="tix-tip-image" data-field="<?php echo esc_attr($name); ?>">
                <div class="tix-tip-image-preview">
                    <?php if ($url) : ?>
                        <img src="<?php echo esc_url($url); ?>" alt="">
                    <?php else : ?>
                        <span>Kein Bild</span>
                    <?php endif; ?>
                </div>
                <div class="tix-tip-image-buttons">
                    <button type="button" class="button tix-tip-image-pick">Bild w&auml;hlen</button>
                    <button type="button" class="button tix-tip-image-remove" style="color:#ef4444;<?php echo $att_id ? '' : 'display:none;'; ?>">Entfernen</button>
                    <p class="description"><?php echo esc_html($hint); ?></p>
                </div>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($att_id ?: ''); ?>">
            </div>
        </div>
        <?php
    }

    public static function render_meta_box($post) {
        wp_nonce_field('tix_save_app_tip', 'tix_app_tip_nonce');
        wp_enqueue_media(['post' => $post->ID]);

        $text       = (string) get_post_meta($post->ID, '_tix_tip_text', true);
        $author     = (string) get_post_meta($post->ID, '_tix_tip_author', true);
        $author_img = intval(get_post_meta($post->ID, '_tix_tip_author_image', true));
        $image      = intval(get_post_meta($post->ID, '_tix_tip_image', true));
        $event_id   = intval(get_post_meta($post->ID, '_tix_tip_event_id', true));
        $label      = get_post_meta($post->ID, '_tix_tip_label', true);
        if ($label === '' && $post->post_status === 'auto-draft') $label = self::DEFAULT_LABEL;
        $link       = (string) get_post_meta($post->ID, '_tix_tip_link', true);
        $placements = self::placements_of($post->ID);
        $start      = (string) get_post_meta($post->ID, '_tix_tip_start', true);
        $end        = (string) get_post_meta($post->ID, '_tix_tip_end', true);
        ?>
        <style>
            .tix-tip-box { display:grid; gap:16px; padding:4px 0; }
            .tix-tip-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
            @media (max-width: 900px) { .tix-tip-row { grid-template-columns:1fr; } }
            .tix-tip-label { display:block; font-weight:600; margin-bottom:6px; }
            .tix-tip-field input[type=text], .tix-tip-field input[type=datetime-local],
            .tix-tip-field select, .tix-tip-field textarea { width:100%; max-width:100%; }
            .tix-tip-image { display:flex; gap:14px; align-items:flex-start; }
            .tix-tip-image-preview { width:120px; height:120px; border-radius:10px; background:#f3f4f6; border:2px dashed #d1d5db;
                display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; color:#9ca3af; font-size:12px; }
            .tix-tip-image-preview img { width:100%; height:100%; object-fit:cover; }
            .tix-tip-image-buttons { display:flex; flex-direction:column; gap:6px; }
            .tix-tip-places label { display:block; margin:4px 0; }
            .tix-tip-hint { background:#f0f6fc; border-left:4px solid #2271b1; padding:10px 12px; margin:0; }
        </style>
        <div class="tix-tip-box">
            <p class="tix-tip-hint">Mehrere Tipps am selben Platz erscheinen in der App als Slider (Reihenfolge = &bdquo;Reihenfolge&ldquo; rechts).</p>

            <div class="tix-tip-field">
                <label class="tix-tip-label" for="tix_tip_text">Text</label>
                <textarea id="tix_tip_text" name="tix_tip_text" rows="4" maxlength="600" placeholder="z. B. Der Honeyball Club feiert Frauenfu&szlig;ball &hellip; Du bist dabei? 🌈"><?php echo esc_textarea($text); ?></textarea>
                <p class="description">Kurz halten (1&ndash;3 S&auml;tze). Emojis sind erlaubt.</p>
            </div>

            <div class="tix-tip-row">
                <div class="tix-tip-field">
                    <label class="tix-tip-label" for="tix_tip_label">Etikett</label>
                    <input type="text" id="tix_tip_label" name="tix_tip_label" value="<?php echo esc_attr($label); ?>" placeholder="<?php echo esc_attr(self::DEFAULT_LABEL); ?>">
                    <p class="description">Leer = &bdquo;<?php echo esc_html(self::DEFAULT_LABEL); ?>&ldquo;.</p>
                </div>
                <div class="tix-tip-field">
                    <label class="tix-tip-label" for="tix_tip_author">Tipp von (optional)</label>
                    <input type="text" id="tix_tip_author" name="tix_tip_author" value="<?php echo esc_attr($author); ?>" placeholder="z. B. Sarah">
                </div>
            </div>

            <div class="tix-tip-row">
                <?php self::image_field('tix_tip_author_image', 'Porträt (optional)', $author_img, 'Quadratisch, mind. 300×300 px.'); ?>
                <?php self::image_field('tix_tip_image', 'Eigenes Bild (optional)', $image, 'Ohne eigenes Bild nimmt die App das Bild des verknüpften Events.'); ?>
            </div>

            <div class="tix-tip-row">
                <div class="tix-tip-field">
                    <label class="tix-tip-label" for="tix_tip_event_id">Verknüpftes Event (optional)</label>
                    <select id="tix_tip_event_id" name="tix_tip_event_id">
                        <option value="0">&mdash; Kein Event &mdash;</option>
                        <?php foreach (self::event_options($event_id) as $id => $title) : ?>
                            <option value="<?php echo intval($id); ?>" <?php selected($event_id, $id); ?>><?php echo esc_html($title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Kommende ver&ouml;ffentlichte Events.</p>
                </div>
                <div class="tix-tip-field">
                    <label class="tix-tip-label" for="tix_tip_link">Link oder App-Aktion (optional)</label>
                    <input type="text" id="tix_tip_link" name="tix_tip_link" value="<?php echo esc_attr($link); ?>" placeholder="https://… oder event:123, page:faq">
                    <p class="description">Leer + Event gesetzt = Antippen &ouml;ffnet das Event.</p>
                </div>
            </div>

            <div class="tix-tip-field">
                <span class="tix-tip-label">Pl&auml;tze in der App</span>
                <div class="tix-tip-places">
                    <?php foreach (self::PLACEMENTS as $slug => $title) : ?>
                        <label><input type="checkbox" name="tix_tip_placements[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $placements, true)); ?>> <?php echo esc_html($title); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tix-tip-row">
                <div class="tix-tip-field">
                    <label class="tix-tip-label" for="tix_tip_start">Sichtbar ab (optional)</label>
                    <input type="datetime-local" id="tix_tip_start" name="tix_tip_start" value="<?php echo esc_attr($start !== '' ? str_replace(' ', 'T', $start) : ''); ?>">
                </div>
                <div class="tix-tip-field">
                    <label class="tix-tip-label" for="tix_tip_end">Sichtbar bis (optional)</label>
                    <input type="datetime-local" id="tix_tip_end" name="tix_tip_end" value="<?php echo esc_attr($end !== '' ? str_replace(' ', 'T', $end) : ''); ?>">
                </div>
            </div>
            <p class="description" style="margin-top:-8px;">Zeitzone der Seite (<?php echo esc_html(wp_timezone_string()); ?>). Leer = unbegrenzt.</p>
        </div>
        <script>
        jQuery(function ($) {
            $('.tix-tip-image').each(function () {
                var $box = $(this), frame = null;
                $box.on('click', '.tix-tip-image-pick', function (e) {
                    e.preventDefault();
                    if (!frame) {
                        frame = wp.media({ title: 'Bild wählen', button: { text: 'Übernehmen' }, library: { type: 'image' }, multiple: false });
                        frame.on('select', function () {
                            var a = frame.state().get('selection').first().toJSON();
                            var url = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url;
                            $box.find('input[type=hidden]').val(a.id);
                            $box.find('.tix-tip-image-preview').html($('<img alt="">').attr('src', url));
                            $box.find('.tix-tip-image-remove').show();
                        });
                    }
                    frame.open();
                });
                $box.on('click', '.tix-tip-image-remove', function (e) {
                    e.preventDefault();
                    $box.find('input[type=hidden]').val('');
                    $box.find('.tix-tip-image-preview').html('<span>Kein Bild</span>');
                    $(this).hide();
                });
            });
        });
        </script>
        <?php
    }

    /** Metabox speichern (Nonce + manage_options). */
    public static function save($post_id, $post = null) {
        if (!isset($_POST['tix_app_tip_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tix_app_tip_nonce'])), 'tix_save_app_tip')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (get_post_type($post_id) !== self::CPT) return;
        if (!current_user_can('manage_options')) return;

        $text = sanitize_textarea_field(wp_unslash($_POST['tix_tip_text'] ?? ''));
        update_post_meta($post_id, '_tix_tip_text', $text);

        update_post_meta($post_id, '_tix_tip_author', sanitize_text_field(wp_unslash($_POST['tix_tip_author'] ?? '')));

        foreach (['tix_tip_author_image' => '_tix_tip_author_image', 'tix_tip_image' => '_tix_tip_image'] as $field => $key) {
            $att = absint($_POST[$field] ?? 0);
            if ($att && get_post_type($att) === 'attachment') update_post_meta($post_id, $key, $att);
            else delete_post_meta($post_id, $key);
        }

        $event_id = absint($_POST['tix_tip_event_id'] ?? 0);
        if ($event_id && get_post_type($event_id) === 'event') update_post_meta($post_id, '_tix_tip_event_id', $event_id);
        else delete_post_meta($post_id, '_tix_tip_event_id');

        $label = sanitize_text_field(wp_unslash($_POST['tix_tip_label'] ?? ''));
        update_post_meta($post_id, '_tix_tip_label', $label !== '' ? $label : self::DEFAULT_LABEL);

        update_post_meta($post_id, '_tix_tip_link', self::sanitize_link($_POST['tix_tip_link'] ?? ''));

        $raw = isset($_POST['tix_tip_placements']) && is_array($_POST['tix_tip_placements'])
            ? array_map('sanitize_key', wp_unslash($_POST['tix_tip_placements']))
            : [];
        update_post_meta($post_id, '_tix_tip_placements', array_values(array_intersect(array_keys(self::PLACEMENTS), $raw)));

        update_post_meta($post_id, '_tix_tip_start', self::sanitize_datetime($_POST['tix_tip_start'] ?? ''));
        update_post_meta($post_id, '_tix_tip_end', self::sanitize_datetime($_POST['tix_tip_end'] ?? ''));

        self::flush();
    }

    // ──────────────────────────────────────────
    //  Admin-Liste
    // ──────────────────────────────────────────

    public static function columns($cols) {
        $out = [];
        foreach ($cols as $k => $v) {
            $out[$k] = $v;
            if ($k === 'title') {
                $out['tix_tip_places'] = 'Plätze';
                $out['tix_tip_period'] = 'Zeitraum';
                $out['tix_tip_event']  = 'Event';
                $out['tix_tip_order']  = 'Reihenfolge';
            }
        }
        return $out;
    }

    public static function column_content($col, $post_id) {
        switch ($col) {
            case 'tix_tip_places':
                $names = array_map(function ($s) { return self::PLACEMENTS[$s]; }, self::placements_of($post_id));
                echo $names ? esc_html(implode(', ', $names)) : '<span style="color:#9ca3af;">&mdash; keiner &mdash;</span>';
                break;
            case 'tix_tip_period':
                $s = self::to_datetime(get_post_meta($post_id, '_tix_tip_start', true));
                $e = self::to_datetime(get_post_meta($post_id, '_tix_tip_end', true));
                if (!$s && !$e) { echo 'unbegrenzt'; break; }
                $fmt = 'd.m.Y H:i';
                echo esc_html(($s ? 'ab ' . $s->format($fmt) : '') . ($s && $e ? ' ' : '') . ($e ? 'bis ' . $e->format($fmt) : ''));
                if ($e && $e->getTimestamp() < time()) echo ' <span style="color:#ef4444;">(abgelaufen)</span>';
                elseif ($s && $s->getTimestamp() > time()) echo ' <span style="color:#d97706;">(geplant)</span>';
                break;
            case 'tix_tip_event':
                $eid = intval(get_post_meta($post_id, '_tix_tip_event_id', true));
                if ($eid && get_post_type($eid) === 'event') {
                    echo '<a href="' . esc_url(get_edit_post_link($eid)) . '">' . esc_html(get_the_title($eid)) . '</a>';
                } else {
                    echo '&mdash;';
                }
                break;
            case 'tix_tip_order':
                echo intval(get_post_field('menu_order', $post_id));
                break;
        }
    }

    public static function sortable_columns($cols) {
        $cols['tix_tip_order'] = 'menu_order';
        return $cols;
    }

    /** Admin-Liste standardmäßig nach Reihenfolge, dann neueste zuerst. */
    public static function admin_order($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== self::CPT) return;
        if (!$query->get('orderby')) {
            $query->set('orderby', ['menu_order' => 'ASC', 'date' => 'DESC']);
        }
    }

    // ──────────────────────────────────────────
    //  Cache
    // ──────────────────────────────────────────

    public static function flush() {
        update_option(self::CACHE_VERSION_OPTION, intval(get_option(self::CACHE_VERSION_OPTION, 1)) + 1, false);
    }

    public static function flush_on_transition($new_status, $old_status, $post) {
        if ($new_status !== $old_status && $post && $post->post_type === self::CPT) self::flush();
    }

    public static function flush_on_delete($post_id, $post = null) {
        $type = $post ? $post->post_type : get_post_type($post_id);
        if ($type === self::CPT || $type === 'event') self::flush();
    }

    private static function cache_key($placement) {
        return 'tix_pub_tips_' . intval(get_option(self::CACHE_VERSION_OPTION, 1)) . '_' . ($placement !== '' ? $placement : 'all');
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::NS, '/public/tips', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_list'],
            'permission_callback' => '__return_true',
            'args'                => [
                'placement' => ['type' => 'string', 'required' => false],
            ],
        ]);
    }

    /** Tipp im App-Format. */
    public static function payload($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::CPT) return null;

        $event_id = intval(get_post_meta($id, '_tix_tip_event_id', true));
        $event = ($event_id && class_exists('TIX_Public_Events')) ? TIX_Public_Events::payload($event_id, false) : null;
        if (!$event) $event_id = 0;

        $img_id     = intval(get_post_meta($id, '_tix_tip_image', true));
        $author_img = intval(get_post_meta($id, '_tix_tip_author_image', true));
        $label      = (string) get_post_meta($id, '_tix_tip_label', true);
        $start      = (string) get_post_meta($id, '_tix_tip_start', true);
        $end        = (string) get_post_meta($id, '_tix_tip_end', true);

        return [
            'id'           => intval($id),
            'title'        => (string) $post->post_title,
            'text'         => (string) get_post_meta($id, '_tix_tip_text', true),
            'author'       => (string) get_post_meta($id, '_tix_tip_author', true),
            'author_image' => $author_img ? (wp_get_attachment_image_url($author_img, 'medium') ?: '') : '',
            // Ohne eigenes Bild leer – die App nimmt dann das Event-Bild
            'image'        => $img_id ? (wp_get_attachment_image_url($img_id, 'large') ?: (wp_get_attachment_image_url($img_id, 'full') ?: '')) : '',
            'image_small'  => $img_id ? (wp_get_attachment_image_url($img_id, 'medium') ?: '') : '',
            'label'        => $label !== '' ? $label : self::DEFAULT_LABEL,
            'placements'   => self::placements_of($id),
            'event_id'     => $event_id,
            'event'        => $event,
            'link'         => (string) get_post_meta($id, '_tix_tip_link', true),
            'starts_at'    => self::iso($start),
            'ends_at'      => self::iso($end),
            'order'        => intval($post->menu_order),
        ];
    }

    /** GET /public/tips[?placement=] */
    public static function rest_list(WP_REST_Request $req) {
        $placement = sanitize_key((string) $req->get_param('placement'));
        $key = self::cache_key($placement);
        $cached = get_transient($key);
        if (is_array($cached)) return rest_ensure_response($cached);

        $ids = get_posts([
            'post_type'        => self::CPT,
            'post_status'      => 'publish',
            'posts_per_page'   => 200,
            'fields'           => 'ids',
            'orderby'          => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        $now  = time();
        $tips = [];
        foreach ($ids as $id) {
            if (!self::is_current($id, $now)) continue;
            if ($placement !== '' && !in_array($placement, self::placements_of($id), true)) continue;
            $p = self::payload($id);
            if ($p) $tips[] = $p;
        }

        $resp = ['tips' => $tips];
        set_transient($key, $resp, self::TTL);
        return rest_ensure_response($resp);
    }
}
