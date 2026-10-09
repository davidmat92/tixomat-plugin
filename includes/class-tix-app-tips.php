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
 * Verwaltung aus den Apps (Veranstalter-Bereich, X-Tix-Token) – nur für
 * WordPress-Admins (`manage_options`), sonst 403 `rest_forbidden`:
 *
 *   GET    /app/tips                 alle Tipps (inkl. Entwürfe/abgelaufene) + Plätze
 *   POST   /app/tips                 anlegen (JSON)
 *   POST   /app/tips/{id}            ändern (nur übergebene Felder)
 *   POST   /app/tips/{id}/delete     in den Papierkorb (auch DELETE /app/tips/{id})
 *   POST   /app/tips/{id}/image      Bild hochladen (multipart `file`, `kind` = author|image)
 *   GET    /app/tips/events          Auswahlliste kommender Events
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
        'day_results'  => 'Ergebnisse nach Datum (evendis)',
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

    /**
     * Eingabe aus datetime-local ('Y-m-d\TH:i', Seiten-Zeitzone) oder ISO mit Zeitzone
     * ('2026-10-10T08:00:00Z', '…+02:00', wie `starts_at`) → 'Y-m-d H:i' (Seiten-Zeitzone) oder ''.
     */
    private static function sanitize_datetime($raw) {
        $raw = trim(sanitize_text_field((string) $raw));
        if ($raw === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $raw)) {
            try {
                $dt = (new DateTimeImmutable($raw))->setTimezone(wp_timezone());
            } catch (Exception $e) {
                return '';
            }
            return $dt->format('Y-m-d H:i');
        }
        $raw = str_replace('T', ' ', $raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw)) return '';
        $dt = self::to_datetime($raw);
        return $dt ? $dt->format('Y-m-d H:i') : '';
    }

    /**
     * App-Routen: Zeitraum prüfen, statt ein falsches Datum still als „unbegrenzt“ zu speichern.
     * $tip_id > 0: fehlende Grenze kommt aus dem gespeicherten Tipp.
     */
    private static function validate_dates(array $d, $tip_id = 0) {
        $vals = [];
        foreach (['start' => '_tix_tip_start', 'end' => '_tix_tip_end'] as $k => $meta) {
            if (array_key_exists($k, $d)) {
                $raw = trim((string) $d[$k]);
                $vals[$k] = self::sanitize_datetime($raw);
                if ($raw !== '' && $vals[$k] === '') {
                    return new WP_Error('tix_tip_date', '„' . $k . '“ bitte als JJJJ-MM-TTTHH:MM oder ISO-Datum angeben.', ['status' => 400]);
                }
            } else {
                $vals[$k] = $tip_id ? (string) get_post_meta($tip_id, $meta, true) : '';
            }
        }
        if ($vals['start'] !== '' && $vals['end'] !== '' && $vals['end'] <= $vals['start']) {
            return new WP_Error('tix_tip_date', 'Das Ende muss nach dem Start liegen.', ['status' => 400]);
        }
        return true;
    }

    /** Link: URL (http/https/mailto/tel) oder App-Aktion wie `event:123`, `page:faq`. */
    private static function sanitize_link($raw) {
        $raw = trim((string) $raw);
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

    /** IDs kommender veröffentlichter Events, nach Datum aufsteigend. */
    private static function upcoming_event_ids($limit) {
        return get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
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
    }

    /** Kommende veröffentlichte Events für die Auswahl (+ aktuell gewähltes). */
    private static function event_options($selected) {
        $ids = self::upcoming_event_ids(300);
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

        $p = wp_unslash($_POST);
        self::apply_fields($post_id, [
            'text'            => $p['tix_tip_text'] ?? '',
            'author'          => $p['tix_tip_author'] ?? '',
            'author_image_id' => $p['tix_tip_author_image'] ?? 0,
            'image_id'        => $p['tix_tip_image'] ?? 0,
            'event_id'        => $p['tix_tip_event_id'] ?? 0,
            'label'           => $p['tix_tip_label'] ?? '',
            'link'            => $p['tix_tip_link'] ?? '',
            'placements'      => (isset($p['tix_tip_placements']) && is_array($p['tix_tip_placements'])) ? $p['tix_tip_placements'] : [],
            'start'           => $p['tix_tip_start'] ?? '',
            'end'             => $p['tix_tip_end'] ?? '',
        ]);

        self::flush();
    }

    /**
     * Felder eines Tipps bereinigen und speichern – gemeinsam für Metabox und App-Routen.
     * Nur die übergebenen Schlüssel werden geändert (Werte ungeslasht; update_post_meta
     * entfernt selbst Slashes, deshalb wp_slash vor dem Speichern – Backslashes bleiben):
     * text, author, author_image_id, image_id, event_id, label, link, placements, start, end.
     */
    private static function apply_fields($post_id, array $d) {
        if (array_key_exists('text', $d)) {
            update_post_meta($post_id, '_tix_tip_text', wp_slash(sanitize_textarea_field((string) $d['text'])));
        }
        if (array_key_exists('author', $d)) {
            update_post_meta($post_id, '_tix_tip_author', wp_slash(sanitize_text_field((string) $d['author'])));
        }
        foreach (['author_image_id' => '_tix_tip_author_image', 'image_id' => '_tix_tip_image'] as $field => $key) {
            if (!array_key_exists($field, $d)) continue;
            $att = absint($d[$field]);
            if ($att && get_post_type($att) === 'attachment') update_post_meta($post_id, $key, $att);
            else delete_post_meta($post_id, $key);
        }
        if (array_key_exists('event_id', $d)) {
            $event_id = absint($d['event_id']);
            if ($event_id && get_post_type($event_id) === 'event') update_post_meta($post_id, '_tix_tip_event_id', $event_id);
            else delete_post_meta($post_id, '_tix_tip_event_id');
        }
        if (array_key_exists('label', $d)) {
            $label = sanitize_text_field((string) $d['label']);
            update_post_meta($post_id, '_tix_tip_label', wp_slash($label !== '' ? $label : self::DEFAULT_LABEL));
        }
        if (array_key_exists('link', $d)) {
            update_post_meta($post_id, '_tix_tip_link', wp_slash(self::sanitize_link($d['link'])));
        }
        if (array_key_exists('placements', $d)) {
            $raw = $d['placements'];
            if (is_string($raw)) $raw = $raw === '' ? [] : explode(',', $raw);
            $raw = is_array($raw) ? array_map('sanitize_key', array_map('strval', $raw)) : [];
            update_post_meta($post_id, '_tix_tip_placements', array_values(array_intersect(array_keys(self::PLACEMENTS), $raw)));
        }
        if (array_key_exists('start', $d)) {
            update_post_meta($post_id, '_tix_tip_start', self::sanitize_datetime($d['start']));
        }
        if (array_key_exists('end', $d)) {
            update_post_meta($post_id, '_tix_tip_end', self::sanitize_datetime($d['end']));
        }
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

        // Verwaltung aus den Apps – nur Admins
        $admin = [__CLASS__, 'check_admin'];
        register_rest_route(self::NS, '/app/tips', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'rest_admin_list'], 'permission_callback' => $admin],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'],     'permission_callback' => $admin],
        ]);
        register_rest_route(self::NS, '/app/tips/events', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_events'], 'permission_callback' => $admin,
        ]);
        register_rest_route(self::NS, '/app/tips/(?P<id>\d+)', [
            ['methods' => 'POST',   'callback' => [__CLASS__, 'rest_update'], 'permission_callback' => $admin],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $admin],
        ]);
        register_rest_route(self::NS, '/app/tips/(?P<id>\d+)/delete', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $admin,
        ]);
        register_rest_route(self::NS, '/app/tips/(?P<id>\d+)/image', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_image'], 'permission_callback' => $admin,
        ]);
    }

    /** Nur WordPress-Admins – auch im Mehr-Veranstalter-Modus nie Veranstalter/Team. */
    public static function check_admin($req = null) {
        if (!is_user_logged_in()) {
            // Abgelaufenes Token: App führt zur Anmeldung (wie die übrigen Routen)
            return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', 'App-Tipps pflegen nur Admins.', ['status' => 403]);
        }
        return true;
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
        // Unbekannter Platz: sofort leer, ohne Cache-Eintrag (sonst legt jeder Zufallswert einen Transient an)
        if ($placement !== '' && !isset(self::PLACEMENTS[$placement])) {
            return rest_ensure_response(['tips' => []]);
        }
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

    // ──────────────────────────────────────────
    //  REST: Verwaltung (nur Admins)
    // ──────────────────────────────────────────

    /** Gespeicherten Zeitpunkt ('Y-m-d H:i') im Eingabeformat 'Y-m-d\TH:i' ('' = unbegrenzt). */
    private static function input_datetime($value) {
        $dt = self::to_datetime($value);
        return $dt ? $dt->format('Y-m-d\TH:i') : '';
    }

    /** Tipp im Admin-Format: App-Format + Status, Anhang-IDs, Zeitraum zum Bearbeiten, aktiv. */
    public static function admin_payload($id) {
        $out = self::payload($id);
        if (!$out) return null;
        $post  = get_post($id);
        $start = (string) get_post_meta($id, '_tix_tip_start', true);
        $end   = (string) get_post_meta($id, '_tix_tip_end', true);
        // Verknüpftes Event auch dann nennen, wenn es (nicht mehr) veröffentlicht ist – `event` bleibt dann null
        $event_id = intval(get_post_meta($id, '_tix_tip_event_id', true));
        if ($event_id && get_post_type($event_id) === 'event') $out['event_id'] = $event_id;

        $out['status']          = $post->post_status === 'publish' ? 'publish' : 'draft';
        $out['author_image_id'] = intval(get_post_meta($id, '_tix_tip_author_image', true));
        $out['image_id']        = intval(get_post_meta($id, '_tix_tip_image', true));
        $out['start']           = self::input_datetime($start);
        $out['end']             = self::input_datetime($end);
        $out['active']          = $post->post_status === 'publish' && self::is_current($id, time());
        return $out;
    }

    /** Tipp-Post zur ID (nicht im Papierkorb) oder 404. */
    private static function find_tip($id) {
        $post = get_post(absint($id));
        if (!$post || $post->post_type !== self::CPT || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
            return new WP_Error('tix_not_found', 'Tipp nicht gefunden.', ['status' => 404]);
        }
        return $post;
    }

    /** Daten aus JSON oder Formular. */
    private static function request_data(WP_REST_Request $req) {
        $d = $req->get_json_params();
        if (!is_array($d) || !$d) $d = $req->get_body_params();
        return is_array($d) ? $d : [];
    }

    /** Post-Felder (Titel, Status, Reihenfolge) aus den Daten; WP_Error bei ungültigem Status. */
    private static function post_fields(array $d) {
        $post = [];
        if (array_key_exists('title', $d)) $post['post_title'] = sanitize_text_field((string) $d['title']);
        if (array_key_exists('status', $d)) {
            $status = sanitize_key((string) $d['status']);
            if (!in_array($status, ['publish', 'draft'], true)) {
                return new WP_Error('tix_tip_status', 'Status muss „publish“ oder „draft“ sein.', ['status' => 400]);
            }
            $post['post_status'] = $status;
        }
        if (array_key_exists('order', $d)) $post['menu_order'] = intval($d['order']);
        return $post;
    }

    /** Ersatztitel für die Admin-Liste, wenn kein Titel kommt (WordPress lehnt leere Tipps sonst ab). */
    private static function fallback_title($text) {
        $t = wp_trim_words(sanitize_textarea_field((string) $text), 8, '…');
        return $t !== '' ? $t : 'App-Tipp';
    }

    /** GET /app/tips */
    public static function rest_admin_list(WP_REST_Request $req) {
        $ids = get_posts([
            'post_type'        => self::CPT,
            'post_status'      => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page'   => 500,
            'fields'           => 'ids',
            'orderby'          => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        $tips = [];
        foreach ($ids as $id) {
            $p = self::admin_payload($id);
            if ($p) $tips[] = $p;
        }
        $placements = [];
        foreach (self::PLACEMENTS as $slug => $label) $placements[] = ['slug' => $slug, 'label' => $label];
        return rest_ensure_response(['tips' => $tips, 'placements' => $placements]);
    }

    /** POST /app/tips */
    public static function rest_create(WP_REST_Request $req) {
        $d = self::request_data($req);
        $ok = self::validate_dates($d);
        if (is_wp_error($ok)) return $ok;
        $post = self::post_fields($d);
        if (is_wp_error($post)) return $post;
        $post += ['post_title' => '', 'post_status' => 'publish', 'menu_order' => 0];
        if ($post['post_title'] === '') $post['post_title'] = self::fallback_title($d['text'] ?? '');
        $post['post_type'] = self::CPT;

        $id = wp_insert_post(wp_slash($post), true);
        if (is_wp_error($id)) {
            return new WP_Error('tix_tip_save', $id->get_error_message(), ['status' => 500]);
        }
        // Neuer Tipp: fehlende Felder mit Standardwerten (wie ein leeres Metabox-Formular)
        self::apply_fields($id, $d + [
            'text' => '', 'author' => '', 'label' => '', 'link' => '', 'event_id' => 0,
            'placements' => [], 'start' => '', 'end' => '',
        ]);
        self::flush();
        return new WP_REST_Response(['tip' => self::admin_payload($id)], 201);
    }

    /** POST /app/tips/{id} */
    public static function rest_update(WP_REST_Request $req) {
        $tip = self::find_tip($req['id']);
        if (is_wp_error($tip)) return $tip;
        $d = self::request_data($req);
        $ok = self::validate_dates($d, $tip->ID);
        if (is_wp_error($ok)) return $ok;
        $post = self::post_fields($d);
        if (is_wp_error($post)) return $post;
        if ($post) {
            if (isset($post['post_title']) && $post['post_title'] === '') {
                $post['post_title'] = self::fallback_title(array_key_exists('text', $d) ? $d['text'] : get_post_meta($tip->ID, '_tix_tip_text', true));
            }
            $post['ID'] = $tip->ID;
            $r = wp_update_post(wp_slash($post), true);
            if (is_wp_error($r)) {
                return new WP_Error('tix_tip_save', $r->get_error_message(), ['status' => 500]);
            }
        }
        self::apply_fields($tip->ID, $d);
        self::flush();
        return rest_ensure_response(['tip' => self::admin_payload($tip->ID)]);
    }

    /** POST /app/tips/{id}/delete bzw. DELETE /app/tips/{id} → Papierkorb */
    public static function rest_delete(WP_REST_Request $req) {
        $tip = self::find_tip($req['id']);
        if (is_wp_error($tip)) return $tip;
        if (!wp_trash_post($tip->ID)) {
            return new WP_Error('tix_tip_delete', 'Der Tipp konnte nicht gelöscht werden.', ['status' => 500]);
        }
        self::flush();
        return rest_ensure_response(['ok' => true]);
    }

    /** POST /app/tips/{id}/image (multipart `file`, `kind` = author|image) */
    public static function rest_image(WP_REST_Request $req) {
        $tip = self::find_tip($req['id']);
        if (is_wp_error($tip)) return $tip;

        $kind = sanitize_key((string) $req->get_param('kind'));
        $keys = ['author' => '_tix_tip_author_image', 'image' => '_tix_tip_image'];
        if (!isset($keys[$kind])) {
            return new WP_Error('tix_tip_kind', '„kind“ muss „author“ oder „image“ sein.', ['status' => 400]);
        }

        $files = $req->get_file_params();
        $file  = $files['file'] ?? ($files['image'] ?? null);
        if (empty($file) || empty($file['tmp_name'])) {
            return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400]);
        }
        if (!empty($file['error'])) {
            return new WP_Error('upload_failed', 'Upload fehlgeschlagen.', ['status' => 400]);
        }
        if (intval($file['size'] ?? 0) > 8 * MB_IN_BYTES) {
            return new WP_Error('too_large', 'Das Bild ist zu groß (max. 8 MB).', ['status' => 400]);
        }
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'] ?? 'tipp.jpg');
        $mime  = $check['type'] ?: (string) ($file['type'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $file_array = [
            'name'     => sanitize_file_name('app-tipp-' . $tip->ID . '-' . ($kind === 'author' ? 'portraet' : 'bild') . '-' . time() . '.' . $ext),
            'tmp_name' => $file['tmp_name'],
            'type'     => $mime,
            'size'     => $file['size'] ?? 0,
        ];
        $title = $tip->post_title !== '' ? $tip->post_title : 'App-Tipp ' . $tip->ID;
        $attachment_id = media_handle_sideload($file_array, $tip->ID, $title);
        if (is_wp_error($attachment_id)) {
            return new WP_Error('upload_failed', $attachment_id->get_error_message(), ['status' => 500]);
        }
        update_post_meta($tip->ID, $keys[$kind], $attachment_id);
        self::flush();
        return rest_ensure_response(['tip' => self::admin_payload($tip->ID)]);
    }

    /** GET /app/tips/events – Auswahlliste fürs Event-Feld */
    public static function rest_events(WP_REST_Request $req) {
        $out = [];
        foreach (self::upcoming_event_ids(200) as $id) {
            $out[] = [
                'id'         => intval($id),
                'title'      => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
                'date_start' => (string) get_post_meta($id, '_tix_date_start', true),
            ];
        }
        return rest_ensure_response(['events' => $out]);
    }
}
