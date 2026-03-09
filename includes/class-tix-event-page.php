<?php
if (!defined('ABSPATH')) exit;

/**
 * [tix_event_page] – Dynamische Event-Detailseite
 *
 * Rendert eine komplette Event-Seite.
 * Zeigt nur Sektionen an, die auch Daten enthalten.
 * Bettet vorhandene Shortcodes ein: ticket_selector, faq, calendar, upsell.
 */
class TIX_Event_Page {

    public static function init() {
        add_shortcode('tix_event_page', [__CLASS__, 'render']);
    }

    /* ── Assets laden ── */
    private static function enqueue() {
        wp_enqueue_style(
            'tix-event-page',
            TIXOMAT_URL . 'assets/css/event-page.css',
            [],
            TIXOMAT_VERSION
        );
        wp_enqueue_script(
            'tix-event-page',
            TIXOMAT_URL . 'assets/js/event-page.js',
            [],
            TIXOMAT_VERSION,
            true
        );
    }

    /* ════════════════════════════════════
       RENDER (Haupteinstieg)
       ════════════════════════════════════ */

    public static function render($atts = []) {
        $atts    = shortcode_atts(['id' => 0], $atts, 'tix_event_page');
        $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') {
            return '<p style="color:#991b1b;">Event nicht gefunden.</p>';
        }

        $s = tix_get_settings();
        $layout = ($s['ep_layout'] ?? '2col') === '1col' ? '1col' : '2col';

        self::enqueue();
        ob_start();
        ?>
        <div class="tix-ep tix-ep--<?php echo $layout; ?>">

            <?php // ── Hero: Volle Breite ── ?>
            <?php self::render_hero($post_id); ?>

            <?php if ($layout === '2col'): ?>

                <?php // ── 2-Spalten Grid ── ?>
                <div class="tix-ep-grid">
                    <?php self::render_title($post_id); ?>
                    <div class="tix-ep-main">
                        <?php self::render_info_section($post_id, 'description', 'Beschreibung'); ?>
                        <?php self::render_info_section($post_id, 'lineup', 'Line-Up'); ?>
                        <?php self::render_info_section($post_id, 'specials', 'Specials'); ?>
                        <?php self::render_gallery($post_id); ?>
                        <?php self::render_video($post_id); ?>
                        <?php self::render_info_section($post_id, 'extra_info', 'Weitere Informationen'); ?>
                        <?php self::render_faq($post_id); ?>
                        <?php self::render_raffle($post_id); ?>
                        <?php self::render_series($post_id); ?>
                    </div>
                    <aside class="tix-ep-sidebar">
                        <div class="tix-ep-sidebar-inner">
                            <?php self::render_header($post_id); ?>
                            <?php self::render_tickets($post_id); ?>
                            <?php self::render_calendar($post_id); ?>
                            <?php self::render_charity($post_id); ?>
                            <?php self::render_location($post_id); ?>
                            <?php self::render_organizer($post_id); ?>
                        </div>
                    </aside>
                </div>

            <?php else: ?>

                <?php // ── 1-Spalten Layout ── ?>
                <?php self::render_title($post_id); ?>
                <?php self::render_header($post_id); ?>
                <?php self::render_tickets($post_id); ?>
                <?php self::render_info_section($post_id, 'description', 'Beschreibung'); ?>
                <?php self::render_info_section($post_id, 'lineup', 'Line-Up'); ?>
                <?php self::render_info_section($post_id, 'specials', 'Specials'); ?>
                <?php self::render_gallery($post_id); ?>
                <?php self::render_video($post_id); ?>
                <?php self::render_info_section($post_id, 'extra_info', 'Weitere Informationen'); ?>
                <?php self::render_faq($post_id); ?>
                <?php self::render_raffle($post_id); ?>
                <?php self::render_calendar($post_id); ?>
                <?php self::render_charity($post_id); ?>
                <?php self::render_location($post_id); ?>
                <?php self::render_organizer($post_id); ?>
                <?php self::render_series($post_id); ?>

            <?php endif; ?>

            <?php // ── Volle Breite: Upsell + Footer ── ?>
            <?php self::render_upsell($post_id); ?>
            <?php echo tix_branding_footer(); ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /* ── Titel (nur h1 + Status) ── */
    private static function render_title($id) {
        $title      = get_the_title($id);
        $status     = get_post_meta($id, '_tix_status', true);
        $status_lbl = get_post_meta($id, '_tix_status_label', true);
        ?>
        <div class="tix-ep-title-wrap">
            <h1 class="tix-ep-title">
                <?php echo esc_html($title); ?>
                <?php if ($status && $status !== 'available' && $status_lbl): ?>
                    <span class="tix-ep-status tix-ep-status--<?php echo esc_attr($status); ?>">
                        <?php echo esc_html($status_lbl); ?>
                    </span>
                <?php endif; ?>
            </h1>
        </div>
        <?php
    }

    /* ════════════════════════════════════
       SEKTIONEN
       ════════════════════════════════════ */

    /* ── 1. Hero-Bild (immer 16:9) ── */
    private static function render_hero($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_hero'])) return;

        $img_url = '';

        if (has_post_thumbnail($id)) {
            $img_url = get_the_post_thumbnail_url($id, 'full');
        } else {
            $gallery = get_post_meta($id, '_tix_gallery', true);
            if (!empty($gallery) && is_array($gallery)) {
                $img_url = wp_get_attachment_image_url($gallery[0], 'full');
            }
        }

        if (!$img_url) return;

        $status       = get_post_meta($id, '_tix_status', true);
        $status_label = get_post_meta($id, '_tix_status_label', true);
        $show_badge   = $status && $status !== 'available' && $status_label;
        ?>
        <div class="tix-ep-hero">
            <img src="<?php echo esc_url($img_url); ?>"
                 alt="<?php echo esc_attr(get_the_title($id)); ?>"
                 loading="eager">
            <?php if ($show_badge): ?>
                <span class="tix-ep-hero-badge tix-ep-hero-badge--<?php echo esc_attr($status); ?>">
                    <?php echo esc_html($status_label); ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ── 2. Header ── */
    private static function render_header($id) {
        $title      = get_the_title($id);
        $status     = get_post_meta($id, '_tix_status', true);
        $status_lbl = get_post_meta($id, '_tix_status_label', true);
        $date_disp  = get_post_meta($id, '_tix_date_display', true);
        $doors_disp = get_post_meta($id, '_tix_doors_display', true);
        $location   = get_post_meta($id, '_tix_location', true);
        $address    = get_post_meta($id, '_tix_address', true);
        $price      = get_post_meta($id, '_tix_price_range', true);
        $age        = get_post_meta($id, '_tix_age_limit_display', true);
        $organizer  = get_post_meta($id, '_tix_organizer', true);
        ?>
        <div class="tix-ep-header">
            <div class="tix-ep-meta">
                <?php if ($date_disp): ?>
                    <div class="tix-ep-meta-row">
                        <span class="tix-ep-meta-icon"><?php echo self::svg('calendar'); ?></span>
                        <div>
                            <span class="tix-ep-meta-value"><?php echo esc_html($date_disp); ?></span>
                            <?php if ($doors_disp): ?>
                                <span class="tix-ep-meta-sub"> &middot; <?php echo esc_html($doors_disp); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($location): ?>
                    <div class="tix-ep-meta-row">
                        <span class="tix-ep-meta-icon"><?php echo self::svg('pin'); ?></span>
                        <div>
                            <span class="tix-ep-meta-value"><?php echo esc_html($location); ?></span>
                            <?php if ($address): ?>
                                <span class="tix-ep-meta-sub"> &middot; <?php echo esc_html($address); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($price): ?>
                    <div class="tix-ep-meta-row">
                        <span class="tix-ep-meta-icon"><?php echo self::svg('ticket'); ?></span>
                        <span class="tix-ep-price-badge"><?php echo esc_html($price); ?></span>
                        <?php if ($age): ?>
                            <span class="tix-ep-age-badge"><?php echo esc_html($age); ?></span>
                        <?php endif; ?>
                    </div>
                <?php elseif ($age): ?>
                    <div class="tix-ep-meta-row">
                        <span class="tix-ep-meta-icon"><?php echo self::svg('shield'); ?></span>
                        <span class="tix-ep-age-badge"><?php echo esc_html($age); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($organizer): ?>
                    <div class="tix-ep-meta-row">
                        <span class="tix-ep-meta-icon"><?php echo self::svg('user'); ?></span>
                        <div>
                            <span class="tix-ep-meta-label">Veranstalter</span>
                            <span class="tix-ep-meta-value"><?php echo esc_html($organizer); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ── 3. Ticket-Selektor ── */
    private static function render_tickets($id) {
        $enabled = get_post_meta($id, '_tix_tickets_enabled', true);
        $status  = get_post_meta($id, '_tix_status', true);
        $blocked = ['cancelled', 'postponed', 'past'];

        if ($enabled !== '1' || in_array($status, $blocked, true)) return;
        ?>
        <div class="tix-ep-tickets">
            <?php echo do_shortcode('[tix_ticket_selector id="' . intval($id) . '"]'); ?>
        </div>
        <?php
    }

    /* ── 4/5/6/9. Info-Sektionen (generisch) ── */
    private static function render_info_section($id, $key, $default_label) {
        $content = get_post_meta($id, '_tix_info_' . $key, true);
        if (empty(trim(wp_strip_all_tags($content ?? '')))) return;

        $label = get_post_meta($id, '_tix_info_' . $key . '_label', true);
        if (empty($label)) $label = $default_label;

        // wpautop anwenden falls noch keine <p>-Tags vorhanden
        if (strpos($content, '<p') === false) {
            $content = wpautop($content);
        }
        ?>
        <div class="tix-ep-section">
            <h2 class="tix-ep-section-title"><?php echo esc_html($label); ?></h2>
            <div class="tix-ep-section-body"><?php echo wp_kses_post($content); ?></div>
        </div>
        <?php
    }

    /* ── 7. Galerie ── */
    private static function render_gallery($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_gallery'])) return;

        $gallery_ids = get_post_meta($id, '_tix_gallery', true);
        if (empty($gallery_ids) || !is_array($gallery_ids)) return;

        // Wenn nur 1 Bild und es bereits als Hero gezeigt wird → überspringen
        if (count($gallery_ids) === 1 && has_post_thumbnail($id)) return;

        $hero_class = count($gallery_ids) > 3 ? ' tix-ep-gallery-grid--hero' : '';
        ?>
        <div class="tix-ep-gallery tix-ep-section">
            <h2 class="tix-ep-section-title">Galerie</h2>
            <div class="tix-ep-gallery-grid<?php echo $hero_class; ?>">
                <?php foreach ($gallery_ids as $att_id):
                    $thumb = wp_get_attachment_image_url($att_id, 'medium');
                    $full  = wp_get_attachment_image_url($att_id, 'large');
                    $alt   = get_post_meta($att_id, '_wp_attachment_image_alt', true) ?: get_the_title($id);
                    if (!$thumb) continue;
                    ?>
                    <div class="tix-ep-gallery-item" data-full="<?php echo esc_url($full); ?>">
                        <img src="<?php echo esc_url($thumb); ?>"
                             alt="<?php echo esc_attr($alt); ?>"
                             loading="lazy">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ── 8. Video ── */
    private static function render_video($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_video'])) return;

        $url   = get_post_meta($id, '_tix_video_url', true);
        $embed = get_post_meta($id, '_tix_video_embed', true);
        if (empty($url)) return;

        // Embed-Code nutzen, oder via wp_oembed_get() generieren
        if (empty($embed)) {
            $embed = wp_oembed_get($url);
        }
        if (empty($embed)) return;
        ?>
        <div class="tix-ep-video tix-ep-section">
            <h2 class="tix-ep-section-title">Video</h2>
            <div class="tix-ep-video-wrap">
                <?php echo $embed; // phpcs:ignore WordPress.Security.EscapeOutput -- oEmbed HTML ?>
            </div>
        </div>
        <?php
    }

    /* ── 10. FAQ ── */
    private static function render_faq($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_faq'])) return;

        $faqs = get_post_meta($id, '_tix_faq', true);
        if (empty($faqs) || !is_array($faqs)) return;

        // Prüfen ob echte Einträge vorhanden
        $has_content = false;
        foreach ($faqs as $f) {
            if (!empty(trim($f['q'] ?? '')) && !empty(trim($f['a'] ?? ''))) {
                $has_content = true;
                break;
            }
        }
        if (!$has_content) return;
        ?>
        <div class="tix-ep-faq">
            <?php echo do_shortcode('[tix_faq id="' . intval($id) . '" wide="1"]'); ?>
        </div>
        <?php
    }

    /* ── Gewinnspiel ── */
    private static function render_raffle($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_raffle'])) return;
        if (get_post_meta($id, '_tix_raffle_enabled', true) !== '1') return;

        echo do_shortcode('[tix_raffle id="' . intval($id) . '"]');
    }

    /* ── 11. Location ── */
    private static function render_location($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_location'])) return;

        $location = get_post_meta($id, '_tix_location', true);
        $address  = get_post_meta($id, '_tix_address', true);
        if (empty($location) && empty($address)) return;

        $loc_id = get_post_meta($id, '_tix_location_id', true);
        $maps_q = urlencode(($location ?: '') . ' ' . ($address ?: ''));
        $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . $maps_q;

        $thumb = '';
        if ($loc_id && has_post_thumbnail($loc_id)) {
            $thumb = get_the_post_thumbnail_url($loc_id, 'thumbnail');
        }
        ?>
        <div class="tix-ep-location tix-ep-section">
            <h2 class="tix-ep-section-title">Veranstaltungsort</h2>
            <a href="<?php echo esc_url($maps_url); ?>"
               target="_blank" rel="noopener"
               class="tix-ep-location-card">
                <span class="tix-ep-location-icon">
                    <?php if ($thumb): ?>
                        <img src="<?php echo esc_url($thumb); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px;">
                    <?php else: ?>
                        <?php echo self::svg('pin'); ?>
                    <?php endif; ?>
                </span>
                <span class="tix-ep-location-info">
                    <?php if ($location): ?>
                        <span class="tix-ep-location-name"><?php echo esc_html($location); ?></span>
                    <?php endif; ?>
                    <?php if ($address): ?>
                        <span class="tix-ep-location-address"><?php echo esc_html($address); ?></span>
                    <?php endif; ?>
                </span>
                <span class="tix-ep-location-arrow"><?php echo self::svg('arrow-right'); ?></span>
            </a>
        </div>
        <?php
    }

    /* ── Organizer ── */
    private static function render_organizer($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_organizer'])) return;

        $organizer = get_post_meta($id, '_tix_organizer', true);
        if (empty($organizer)) return;

        $org_id = get_post_meta($id, '_tix_organizer_id', true);
        $thumb  = '';
        if ($org_id && has_post_thumbnail($org_id)) {
            $thumb = get_the_post_thumbnail_url($org_id, 'thumbnail');
        }
        ?>
        <div class="tix-ep-organizer">
            <div class="tix-ep-organizer-card">
                <span class="tix-ep-organizer-avatar">
                    <?php if ($thumb): ?>
                        <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($organizer); ?>">
                    <?php else: ?>
                        <?php echo self::svg('user'); ?>
                    <?php endif; ?>
                </span>
                <span class="tix-ep-organizer-info">
                    <span class="tix-ep-organizer-label">Veranstalter</span>
                    <span class="tix-ep-organizer-name"><?php echo esc_html($organizer); ?></span>
                </span>
            </div>
        </div>
        <?php
    }

    /* ── Calendar ── */
    private static function render_calendar($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_calendar'])) return;

        $date = get_post_meta($id, '_tix_date_start', true);
        if (empty($date)) return;

        $status  = get_post_meta($id, '_tix_status', true);
        if (in_array($status, ['cancelled', 'past'], true)) return;
        ?>
        <div class="tix-ep-calendar">
            <?php echo do_shortcode('[tix_calendar id="' . intval($id) . '"]'); ?>
        </div>
        <?php
    }

    /* ── 12. Serientermine ── */
    private static function render_series($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_series'])) return;

        $parent_id   = get_post_meta($id, '_tix_series_parent', true);
        $children    = get_post_meta($id, '_tix_series_children', true);
        $is_master   = get_post_meta($id, '_tix_series_enabled', true) === '1';

        // Alle Termine sammeln
        $all_ids = [];
        if ($parent_id && !$is_master) {
            // Kind-Event → Geschwister vom Master holen
            $all_ids = get_post_meta($parent_id, '_tix_series_children', true);
        } elseif ($is_master && !empty($children)) {
            $all_ids = $children;
        }

        if (empty($all_ids) || !is_array($all_ids) || count($all_ids) < 2) return;

        $monate = ['Jan', 'Feb', 'M&auml;r', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        ?>
        <div class="tix-ep-series tix-ep-section">
            <h2 class="tix-ep-section-title">Weitere Termine</h2>
            <div class="tix-ep-series-list">
                <?php foreach ($all_ids as $child_id):
                    $child = get_post($child_id);
                    if (!$child || $child->post_status !== 'publish') continue;

                    $is_current  = intval($child_id) === intval($id);
                    $c_date      = get_post_meta($child_id, '_tix_date_start', true);
                    $c_time      = get_post_meta($child_id, '_tix_time_start', true);
                    $c_status    = get_post_meta($child_id, '_tix_status', true) ?: 'available';
                    $c_status_lbl = get_post_meta($child_id, '_tix_status_label', true);
                    $c_location  = get_post_meta($child_id, '_tix_location', true);

                    if (!$c_date) continue;

                    $ts  = strtotime($c_date);
                    $day = date('d', $ts);
                    $mon = $monate[intval(date('m', $ts)) - 1];

                    $current_class = $is_current ? ' tix-ep-series-item--current' : '';
                    $tag = $is_current ? 'div' : 'a';
                    $href = $is_current ? '' : ' href="' . esc_url(get_permalink($child_id)) . '"';
                    ?>
                    <<?php echo $tag; ?> class="tix-ep-series-item<?php echo $current_class; ?>"<?php echo $href; ?>>
                        <span class="tix-ep-series-date">
                            <span class="tix-ep-series-day"><?php echo esc_html($day); ?></span>
                            <span class="tix-ep-series-month"><?php echo $mon; ?></span>
                        </span>
                        <span class="tix-ep-series-info">
                            <span class="tix-ep-series-title"><?php echo esc_html($child->post_title); ?></span>
                            <span class="tix-ep-series-time">
                                <?php if ($c_time) echo esc_html($c_time) . ' Uhr'; ?>
                                <?php if ($c_location) echo ' &middot; ' . esc_html($c_location); ?>
                            </span>
                        </span>
                        <?php if ($c_status !== 'available' && $c_status_lbl): ?>
                            <span class="tix-ep-series-status tix-ep-series-status--<?php echo esc_attr($c_status); ?>">
                                <?php echo esc_html($c_status_lbl); ?>
                            </span>
                        <?php endif; ?>
                    </<?php echo $tag; ?>>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ── Charity ── */
    private static function render_charity($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_charity'])) return;
        if (get_post_meta($id, '_tix_charity_enabled', true) !== '1') return;

        $name    = get_post_meta($id, '_tix_charity_name', true);
        $desc    = get_post_meta($id, '_tix_charity_desc', true);
        $percent = get_post_meta($id, '_tix_charity_percent', true);
        $img_id  = get_post_meta($id, '_tix_charity_image', true);

        if (empty($name)) return;
        ?>
        <div class="tix-ep-charity tix-ep-section">
            <h2 class="tix-ep-section-title">Soziales Projekt</h2>
            <div class="tix-ep-charity-card">
                <?php if ($img_id):
                    $img_url = wp_get_attachment_image_url($img_id, 'thumbnail');
                    if ($img_url): ?>
                        <img class="tix-ep-charity-img" src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($name); ?>">
                    <?php endif;
                endif; ?>
                <span class="tix-ep-charity-info">
                    <p class="tix-ep-charity-name"><?php echo esc_html($name); ?></p>
                    <?php if ($desc): ?>
                        <p class="tix-ep-charity-desc"><?php echo esc_html($desc); ?></p>
                    <?php endif; ?>
                </span>
                <?php if ($percent): ?>
                    <span class="tix-ep-charity-percent"><?php echo intval($percent); ?>%</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ── 13. Upsell ── */
    private static function render_upsell($id) {
        $s = tix_get_settings();
        if (empty($s['ep_show_upsell'])) return;
        if (get_post_meta($id, '_tix_upsell_disabled', true) === '1') return;

        $settings = tix_get_settings();
        if (empty($settings['show_upsell'])) return;
        ?>
        <div class="tix-ep-upsell">
            <?php echo do_shortcode('[tix_upsell id="' . intval($id) . '"]'); ?>
        </div>
        <?php
    }

    /* ════════════════════════════════════
       SVG ICONS
       ════════════════════════════════════ */

    private static function svg($name) {
        $icons = [
            'calendar'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd"/></svg>',
            'pin'         => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 00.281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 103 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 002.274 1.765 11.842 11.842 0 00.757.433c.113.058.2.1.281.14l.018.008.006.003zM10 11.25a2.25 2.25 0 100-4.5 2.25 2.25 0 000 4.5z" clip-rule="evenodd"/></svg>',
            'ticket'      => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M13 3v1.27a.75.75 0 001.5 0V3h2.25A2.25 2.25 0 0119 5.25v2.628a.75.75 0 01-.5.707 1.5 1.5 0 000 2.83c.3.106.5.39.5.707v2.628A2.25 2.25 0 0116.75 17H14.5v-1.27a.75.75 0 00-1.5 0V17H5.25A2.25 2.25 0 013 14.75v-2.628c0-.318.2-.601.5-.707a1.5 1.5 0 000-2.83.75.75 0 01-.5-.707V5.25A2.25 2.25 0 015.25 3H13zm1.5 4.396a.75.75 0 00-1.5 0v1.042a.75.75 0 001.5 0V7.396zm0 3.166a.75.75 0 00-1.5 0v1.042a.75.75 0 001.5 0v-1.042zm0 3.166a.75.75 0 00-1.5 0V14.77a.75.75 0 001.5 0v-1.042z" clip-rule="evenodd"/></svg>',
            'user'        => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10 8a3 3 0 100-6 3 3 0 000 6zM3.465 14.493a1.23 1.23 0 00.41 1.412A9.957 9.957 0 0010 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 00-13.074.003z"/></svg>',
            'shield'      => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.661 2.237a.531.531 0 01.678 0 11.947 11.947 0 007.078 2.749.5.5 0 01.479.425c.069.52.104 1.05.104 1.59 0 5.162-3.26 9.563-7.834 11.256a.48.48 0 01-.332 0C5.26 16.564 2 12.163 2 7c0-.538.035-1.069.104-1.589a.5.5 0 01.48-.425 11.947 11.947 0 007.077-2.75z" clip-rule="evenodd"/></svg>',
            'arrow-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>',
        ];
        return $icons[$name] ?? '';
    }
}
