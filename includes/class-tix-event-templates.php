<?php
/**
 * TIX Event Templates – Vorlagen-System für Event-Erstellung
 *
 * Verwaltet Event-Vorlagen die steuern welche Tabs sichtbar sind
 * und welche Standardwerte beim Erstellen vorbelegt werden.
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Templates {

    const OPTION_KEY = 'tix_event_templates';

    /** Alle verfügbaren Tabs mit Labels */
    public static function all_tabs() {
        return [
            'details'    => 'Details',
            'info'       => 'Info',
            'tickets'    => 'Tickets',
            'media'      => 'Medien',
            'template'   => 'Ticket-Vorlage',
            'faq'        => 'FAQ',
            'upsell'     => 'Zusatzprodukte',
            'specials'   => 'Specials',
            'series'     => 'Serientermine',
            'guestlist'  => 'Gästeliste',
            'discounts'  => 'Rabattcodes',
            'raffle'     => 'Gewinnspiel',
            'timetable'  => 'Programm',
            'tables'     => 'Tische',
            'campaigns'  => 'Kampagnen',
            'promoter'   => 'Promoter',
            'advanced'   => 'Erweitert',
        ];
    }

    /** Standard-Icons für Auswahl */
    public static function available_icons() {
        return [
            'dashicons-format-audio'   => 'Musik',
            'dashicons-groups'         => 'Gruppe',
            'dashicons-star-filled'    => 'Stern',
            'dashicons-palmtree'       => 'Festival',
            'dashicons-welcome-learn-more' => 'Workshop',
            'dashicons-megaphone'      => 'Marketing',
            'dashicons-food'           => 'Gastronomie',
            'dashicons-heart'          => 'Gala',
            'dashicons-networking'     => 'Networking',
            'dashicons-admin-generic'  => 'Standard',
            'dashicons-tickets-alt'    => 'Tickets',
            'dashicons-calendar-alt'   => 'Kalender',
            'dashicons-location'       => 'Location',
            'dashicons-superhero-alt'  => 'Sport',
            'dashicons-microphone'     => 'Comedy',
            'dashicons-businessman'    => 'Business',
        ];
    }

    /**
     * Alle Vorlagen aus DB holen
     */
    public static function get_all() {
        return get_option(self::OPTION_KEY, []);
    }

    /**
     * Eine Vorlage speichern/aktualisieren
     */
    public static function save($slug, $data) {
        $templates = self::get_all();
        $templates[$slug] = $data;
        update_option(self::OPTION_KEY, $templates);
    }

    /**
     * Eine Vorlage löschen
     */
    public static function delete($slug) {
        $templates = self::get_all();
        unset($templates[$slug]);
        update_option(self::OPTION_KEY, $templates);
    }

    /**
     * Hooks registrieren
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 30);
        add_action('admin_post_tix_save_template',   [__CLASS__, 'handle_save']);
        add_action('admin_post_tix_delete_template',  [__CLASS__, 'handle_delete']);
        add_action('admin_post_tix_create_template_from_event', [__CLASS__, 'handle_create_from_event']);
    }

    /**
     * Admin-Menü registrieren
     */
    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Vorlagen',
            'Vorlagen',
            'edit_posts',
            'tix-templates',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Admin-Seite rendern
     */
    public static function render_page() {
        $templates = self::get_all();
        $editing   = isset($_GET['edit']) ? sanitize_key($_GET['edit']) : null;
        $from_event = isset($_GET['from_event']) ? intval($_GET['from_event']) : 0;
        $all_tabs  = self::all_tabs();
        $icons     = self::available_icons();
        $categories = get_terms(['taxonomy' => 'event_category', 'hide_empty' => false]);

        // Vorbefüllung aus Event
        $prefill = null;
        if ($from_event) {
            $prefill = self::analyze_event($from_event);
        } elseif ($editing && isset($templates[$editing])) {
            $prefill = $templates[$editing];
            $prefill['slug'] = $editing;
        }

        ?>
        <div class="wrap" style="max-width:900px;">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                <span class="dashicons dashicons-layout" style="font-size:28px;width:28px;height:28px;color:#7c3aed;"></span>
                Event-Vorlagen
            </h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>Vorlage gespeichert.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible"><p>Vorlage gelöscht.</p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

                <?php // ── Formular ── ?>
                <div>
                    <div style="background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:24px;">
                        <h2 style="margin:0 0 16px;font-size:16px;">
                            <?php echo $editing ? 'Vorlage bearbeiten' : ($from_event ? 'Vorlage aus Event erstellen' : 'Neue Vorlage'); ?>
                        </h2>

                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="tix_save_template">
                            <?php wp_nonce_field('tix_save_template'); ?>
                            <?php if ($editing): ?>
                                <input type="hidden" name="old_slug" value="<?php echo esc_attr($editing); ?>">
                            <?php endif; ?>
                            <?php if ($from_event): ?>
                                <input type="hidden" name="source_event" value="<?php echo $from_event; ?>">
                            <?php endif; ?>

                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th style="padding:8px 0;width:120px;"><label>Name *</label></th>
                                    <td style="padding:8px 0;">
                                        <input type="text" name="tpl_label" required
                                               value="<?php echo esc_attr($prefill['label'] ?? ''); ?>"
                                               placeholder="z.B. Clubbing, Workshop, Festival"
                                               style="width:100%;font-size:14px;padding:8px;">
                                    </td>
                                </tr>
                                <tr>
                                    <th style="padding:8px 0;"><label>Beschreibung</label></th>
                                    <td style="padding:8px 0;">
                                        <input type="text" name="tpl_desc"
                                               value="<?php echo esc_attr($prefill['desc'] ?? $prefill['description'] ?? ''); ?>"
                                               placeholder="Kurze Beschreibung"
                                               style="width:100%;font-size:13px;padding:6px 8px;">
                                    </td>
                                </tr>
                                <tr>
                                    <th style="padding:8px 0;"><label>Icon</label></th>
                                    <td style="padding:8px 0;">
                                        <div style="display:flex;flex-wrap:wrap;gap:4px;">
                                            <?php foreach ($icons as $icon_class => $icon_label):
                                                $sel = ($prefill['icon'] ?? 'dashicons-admin-generic') === $icon_class;
                                            ?>
                                                <label style="display:flex;align-items:center;gap:2px;padding:6px 8px;border:2px solid <?php echo $sel ? '#7c3aed' : '#e0e0e0'; ?>;border-radius:8px;cursor:pointer;background:<?php echo $sel ? '#f5f3ff' : '#fff'; ?>;" title="<?php echo esc_attr($icon_label); ?>">
                                                    <input type="radio" name="tpl_icon" value="<?php echo esc_attr($icon_class); ?>" <?php checked($sel); ?> style="display:none;">
                                                    <span class="dashicons <?php echo esc_attr($icon_class); ?>" style="font-size:18px;width:18px;height:18px;color:<?php echo $sel ? '#7c3aed' : '#666'; ?>;"></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th style="padding:8px 0;"><label>Kategorie</label></th>
                                    <td style="padding:8px 0;">
                                        <select name="tpl_category_id" style="width:100%;">
                                            <option value="">— Keine automatische Zuweisung —</option>
                                            <?php if (!is_wp_error($categories)):
                                                foreach ($categories as $cat): ?>
                                                    <option value="<?php echo $cat->term_id; ?>" <?php selected(intval($prefill['category_id'] ?? 0), $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                                                <?php endforeach;
                                            endif; ?>
                                        </select>
                                    </td>
                                </tr>
                            </table>

                            <h3 style="margin:20px 0 10px;font-size:14px;color:#374151;">Sichtbare Tabs</h3>
                            <p class="description" style="margin:0 0 8px;">Details ist immer sichtbar. Nicht ausgewählte Tabs sind unter "Alle Tabs" erreichbar.</p>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                                <?php
                                $preset_tabs = $prefill['tabs'] ?? '*';
                                $active_tabs = ($preset_tabs === '*') ? array_keys($all_tabs) : (array) $preset_tabs;
                                foreach ($all_tabs as $tab_slug => $tab_label):
                                    if ($tab_slug === 'details') continue; // Immer sichtbar
                                    $checked = in_array($tab_slug, $active_tabs);
                                ?>
                                    <label style="display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;font-size:13px;">
                                        <input type="checkbox" name="tpl_tabs[]" value="<?php echo esc_attr($tab_slug); ?>" <?php checked($checked); ?>>
                                        <?php echo esc_html($tab_label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <h3 style="margin:20px 0 10px;font-size:14px;color:#374151;">Standardwerte</h3>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                                <?php
                                $defs = $prefill['defaults'] ?? [];
                                ?>
                                <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                                    <input type="checkbox" name="tpl_def[tix_tickets_enabled]" value="1" <?php checked(!empty($defs['tix_tickets_enabled'])); ?>>
                                    Ticketverkauf aktiviert
                                </label>
                                <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                                    Altersbegrenzung:
                                    <input type="number" name="tpl_def_age" value="<?php echo esc_attr($defs['tix_info_age_limit'] ?? ''); ?>" min="0" max="99" style="width:60px;" placeholder="—">
                                </label>
                            </div>

                            <div style="margin-top:20px;display:flex;gap:8px;">
                                <button type="submit" class="button button-primary" style="background:#7c3aed;border-color:#6d28d9;">
                                    <?php echo $editing ? 'Aktualisieren' : 'Vorlage speichern'; ?>
                                </button>
                                <?php if ($editing || $from_event): ?>
                                    <a href="<?php echo admin_url('admin.php?page=tix-templates'); ?>" class="button">Abbrechen</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <?php // ── Liste ── ?>
                <div>
                    <div style="background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:24px;">
                        <h2 style="margin:0 0 16px;font-size:16px;">Vorhandene Vorlagen</h2>

                        <?php if (empty($templates)): ?>
                            <p style="color:#9ca3af;font-style:italic;">Noch keine Vorlagen erstellt.</p>
                        <?php else: ?>
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <?php foreach ($templates as $slug => $tpl): ?>
                                    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;">
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <span class="dashicons <?php echo esc_attr($tpl['icon'] ?? 'dashicons-admin-generic'); ?>" style="color:#7c3aed;font-size:20px;width:20px;height:20px;"></span>
                                            <div>
                                                <strong style="font-size:14px;"><?php echo esc_html($tpl['label']); ?></strong>
                                                <?php if (!empty($tpl['desc'])): ?>
                                                    <br><span style="font-size:12px;color:#6b7280;"><?php echo esc_html($tpl['desc']); ?></span>
                                                <?php endif; ?>
                                                <br><span style="font-size:11px;color:#9ca3af;">
                                                    <?php
                                                    $tabs = $tpl['tabs'] ?? '*';
                                                    if ($tabs === '*') {
                                                        echo 'Alle Tabs';
                                                    } else {
                                                        $tab_labels = [];
                                                        foreach ((array) $tabs as $t) {
                                                            $tab_labels[] = $all_tabs[$t] ?? $t;
                                                        }
                                                        echo esc_html(implode(', ', $tab_labels));
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div style="display:flex;gap:6px;">
                                            <a href="<?php echo admin_url('admin.php?page=tix-templates&edit=' . $slug); ?>" class="button" style="font-size:12px;">Bearbeiten</a>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=tix_delete_template&slug=' . $slug), 'tix_delete_template_' . $slug); ?>"
                                               class="button" style="font-size:12px;color:#ef4444;" onclick="return confirm('Vorlage löschen?');">Löschen</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * Event analysieren: welche Tabs/Felder sind befüllt?
     */
    public static function analyze_event($event_id) {
        $post = get_post($event_id);
        if (!$post || $post->post_type !== 'event') return null;

        $used_tabs = ['details']; // Immer

        // Info-Tab: mindestens ein Feld befüllt?
        $info_keys = ['description', 'lineup', 'specials', 'extra_info', 'age_limit'];
        foreach ($info_keys as $k) {
            if (get_post_meta($event_id, "_tix_info_{$k}", true)) {
                $used_tabs[] = 'info';
                break;
            }
        }

        // Tickets
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (is_array($cats) && !empty($cats)) $used_tabs[] = 'tickets';

        // Media
        $gallery = get_post_meta($event_id, '_tix_gallery', true);
        $video   = get_post_meta($event_id, '_tix_video_url', true);
        if (($gallery && !empty($gallery)) || $video || has_post_thumbnail($event_id)) $used_tabs[] = 'media';

        // FAQ
        $faq = get_post_meta($event_id, '_tix_faq', true);
        if (is_array($faq) && !empty($faq)) $used_tabs[] = 'faq';

        // Upsell
        $upsell = get_post_meta($event_id, '_tix_upsell_events', true);
        if (!empty($upsell)) $used_tabs[] = 'upsell';

        // Timetable
        $timetable = get_post_meta($event_id, '_tix_timetable', true);
        if (!empty($timetable)) $used_tabs[] = 'timetable';

        // Series
        if (get_post_meta($event_id, '_tix_series_enabled', true)) $used_tabs[] = 'series';

        // Discounts
        $discounts = get_post_meta($event_id, '_tix_discount_codes', true);
        if (is_array($discounts) && !empty($discounts)) $used_tabs[] = 'discounts';

        // Raffle
        if (get_post_meta($event_id, '_tix_raffle_enabled', true)) $used_tabs[] = 'raffle';

        // Guestlist
        if (get_post_meta($event_id, '_tix_guest_list_enabled', true)) $used_tabs[] = 'guestlist';

        // Defaults ableiten
        $defaults = [];
        if (get_post_meta($event_id, '_tix_tickets_enabled', true)) {
            $defaults['tix_tickets_enabled'] = '1';
        }
        $age = get_post_meta($event_id, '_tix_info_age_limit', true);
        if ($age) $defaults['tix_info_age_limit'] = $age;

        // Kategorie
        $terms = wp_get_post_terms($event_id, 'event_category', ['fields' => 'ids']);
        $cat_id = (!is_wp_error($terms) && !empty($terms)) ? $terms[0] : 0;

        return [
            'label'        => $post->post_title . ' (Vorlage)',
            'icon'         => 'dashicons-admin-generic',
            'desc'         => '',
            'tabs'         => array_unique($used_tabs),
            'defaults'     => $defaults,
            'category_id'  => $cat_id,
            'source_event' => $event_id,
        ];
    }

    /**
     * Handle: Vorlage speichern
     */
    public static function handle_save() {
        check_admin_referer('tix_save_template');
        if (!current_user_can('edit_posts')) wp_die('Keine Berechtigung.');

        $label = sanitize_text_field($_POST['tpl_label'] ?? '');
        if (empty($label)) {
            wp_safe_redirect(admin_url('admin.php?page=tix-templates&error=name'));
            exit;
        }

        // Slug generieren
        $old_slug = sanitize_key($_POST['old_slug'] ?? '');
        $slug = $old_slug ?: sanitize_title($label);
        if ($slug === 'all') $slug .= '-1'; // "all" ist reserviert

        // Tabs
        $tabs = array_map('sanitize_key', (array) ($_POST['tpl_tabs'] ?? []));
        array_unshift($tabs, 'details'); // Immer Details
        $tabs = array_unique($tabs);

        // Defaults
        $defaults = [];
        if (!empty($_POST['tpl_def']['tix_tickets_enabled'])) {
            $defaults['tix_tickets_enabled'] = '1';
        }
        if (!empty($_POST['tpl_def_age'])) {
            $defaults['tix_info_age_limit'] = intval($_POST['tpl_def_age']);
        }

        $data = [
            'label'        => $label,
            'icon'         => sanitize_text_field($_POST['tpl_icon'] ?? 'dashicons-admin-generic'),
            'desc'         => sanitize_text_field($_POST['tpl_desc'] ?? ''),
            'tabs'         => $tabs,
            'defaults'     => $defaults,
            'category_id'  => intval($_POST['tpl_category_id'] ?? 0),
            'source_event' => intval($_POST['source_event'] ?? 0),
        ];

        // Alten Slug löschen wenn umbenannt
        if ($old_slug && $old_slug !== $slug) {
            self::delete($old_slug);
        }

        self::save($slug, $data);

        wp_safe_redirect(admin_url('admin.php?page=tix-templates&saved=1'));
        exit;
    }

    /**
     * Handle: Vorlage löschen
     */
    public static function handle_delete() {
        $slug = sanitize_key($_GET['slug'] ?? '');
        check_admin_referer('tix_delete_template_' . $slug);
        if (!current_user_can('edit_posts')) wp_die('Keine Berechtigung.');

        self::delete($slug);

        wp_safe_redirect(admin_url('admin.php?page=tix-templates&deleted=1'));
        exit;
    }

    /**
     * Handle: Vorlage aus Event erstellen (Redirect zur Vorlagen-Seite)
     */
    public static function handle_create_from_event() {
        $event_id = intval($_GET['event_id'] ?? 0);
        check_admin_referer('tix_template_from_' . $event_id);
        if (!current_user_can('edit_posts')) wp_die('Keine Berechtigung.');

        wp_safe_redirect(admin_url('admin.php?page=tix-templates&from_event=' . $event_id));
        exit;
    }
}
