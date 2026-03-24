<?php
/**
 * TIX Single Event — Eigenständiges Event-Template (Addon)
 *
 * Opt-in via Setting ep_template_enabled. Nutzt get_header()/get_footer()
 * für Breakdance-Kompatibilität. Nur der Content-Bereich wird ersetzt.
 */
if (!defined('ABSPATH')) exit;

class TIX_Single_Event {

    public static function init() {
        $enabled = function_exists('tix_get_settings') && tix_get_settings('ep_template_enabled');
        if ($enabled) {
            add_filter('single_template', [__CLASS__, 'load_template']);
        }
        // Assets nur auf Event-Singles laden
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue']);
    }

    /**
     * Template-Override für event CPT
     */
    public static function load_template($template) {
        if (get_post_type() === 'event') {
            $plugin_tpl = TIXOMAT_PATH . 'templates/single-event.php';
            if (file_exists($plugin_tpl)) return $plugin_tpl;
        }
        return $template;
    }

    /**
     * Assets auf Event-Einzelseiten laden
     */
    public static function maybe_enqueue() {
        if (!is_singular('event')) return;
        if (!tix_get_settings('ep_template_enabled')) return;

        wp_enqueue_style('tix-single-event', TIXOMAT_URL . 'assets/css/single-event.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-single-event', TIXOMAT_URL . 'assets/js/single-event.js', [], TIXOMAT_VERSION, true);

        $id = get_the_ID();
        wp_localize_script('tix-single-event', 'tixSingle', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('tix_cards_nonce'),
            'isLoggedIn'    => is_user_logged_in(),
            'eventId'       => $id,
            'eventDate'     => get_post_meta($id, '_tix_date_start', true) . ' ' . get_post_meta($id, '_tix_time_start', true),
            'eventEnd'      => get_post_meta($id, '_tix_date_end', true) . ' ' . (get_post_meta($id, '_tix_time_end', true) ?: '23:59'),
            'eventTitle'    => get_the_title($id),
            'eventUrl'      => get_permalink($id),
            'eventLocation' => get_post_meta($id, '_tix_location_full', true),
        ]);
    }

    /**
     * Prüfe ob eine Sektion gerendert werden soll
     */
    public static function should_show($section, $post_id) {
        $s = tix_get_settings();

        switch ($section) {
            case 'hero':        return !empty($s['ep_show_hero']) && has_post_thumbnail($post_id);
            case 'description': return (bool) get_post_meta($post_id, '_tix_info_description', true);
            case 'lineup':      return (bool) get_post_meta($post_id, '_tix_info_lineup', true);
            case 'specials':    return (bool) get_post_meta($post_id, '_tix_info_specials', true);
            case 'extra_info':  return (bool) get_post_meta($post_id, '_tix_info_extra_info', true);
            case 'tickets':     return get_post_meta($post_id, '_tix_tickets_enabled', true) === '1';
            case 'faq':         return !empty($s['ep_show_faq']) && !empty(get_post_meta($post_id, '_tix_faq', true));
            case 'timetable':   return !empty($s['ep_show_timetable']) && !empty(get_post_meta($post_id, '_tix_timetable', true));
            case 'gallery':     $g = get_post_meta($post_id, '_tix_gallery', true); return !empty($s['ep_show_gallery']) && is_array($g) && !empty($g);
            case 'video':       return !empty($s['ep_show_video']) && (bool) get_post_meta($post_id, '_tix_video_url', true);
            case 'location':    return !empty($s['ep_show_location']) && (bool) get_post_meta($post_id, '_tix_location_id', true);
            case 'organizer':   return !empty($s['ep_show_organizer']) && (bool) get_post_meta($post_id, '_tix_organizer_id', true);
            case 'raffle':      return !empty($s['ep_show_raffle']) && get_post_meta($post_id, '_tix_raffle_enabled', true) === '1';
            case 'calendar':    return !empty($s['ep_show_calendar']);
            case 'share':       return !empty($s['ep_show_share']);
            case 'countdown':   $ds = get_post_meta($post_id, '_tix_date_start', true); return $ds && strtotime($ds) > current_time('timestamp');
            case 'series':      return !empty($s['ep_show_series']) && !empty(get_post_meta($post_id, '_tix_series_children', true));
            case 'upsell':      return !empty($s['ep_show_upsell']);
            case 'charity':     return !empty($s['ep_show_charity']) && get_post_meta($post_id, '_tix_charity_enabled', true) === '1';
            case 'feedback':    return !empty($s['feedback_enabled']) && get_post_meta($post_id, '_tix_is_past', true);
            default:            return false;
        }
    }

    /**
     * Tabs für die Sticky-Leiste generieren
     */
    public static function get_tabs($post_id) {
        $tabs = [];
        if (self::should_show('tickets', $post_id))    $tabs[] = ['id' => 'tickets',    'label' => 'Tickets'];
        if (self::should_show('description', $post_id)) $tabs[] = ['id' => 'info',       'label' => 'Informationen'];
        if (self::should_show('lineup', $post_id))      $tabs[] = ['id' => 'lineup',     'label' => 'Line-Up'];
        if (self::should_show('timetable', $post_id))   $tabs[] = ['id' => 'timetable',  'label' => 'Programm'];
        if (self::should_show('gallery', $post_id))     $tabs[] = ['id' => 'gallery',    'label' => 'Galerie'];
        if (self::should_show('location', $post_id))    $tabs[] = ['id' => 'anfahrt',    'label' => 'Anfahrt'];
        if (self::should_show('faq', $post_id))         $tabs[] = ['id' => 'faq',        'label' => 'FAQ'];
        if (self::should_show('raffle', $post_id))      $tabs[] = ['id' => 'gewinnspiel','label' => 'Gewinnspiel'];
        return $tabs;
    }

    // ──────────────────────────────────────────
    // SECTION RENDERERS
    // ──────────────────────────────────────────

    public static function render_hero($post_id) {
        $thumb = get_the_post_thumbnail_url($post_id, 'full');
        if (!$thumb) return;
        $s = tix_get_settings();
        $height = intval($s['ep_hero_height'] ?? 380);
        ?>
        <div class="tse-hero m-img" style="height:<?php echo $height; ?>px;">
            <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr(get_the_title($post_id)); ?>">
            <?php
            $terms = wp_get_post_terms($post_id, 'event_category', ['fields' => 'all']);
            $status = get_post_meta($post_id, '_tix_status', true);
            $status_label = get_post_meta($post_id, '_tix_status_label', true);
            if (!is_wp_error($terms) && !empty($terms)):
            ?>
                <div class="tse-hero-badges">
                    <span class="ev-badge ev-badge-cat"><?php echo esc_html($terms[0]->name); ?></span>
                    <?php if ($status_label && in_array($status, ['sold_out', 'few_tickets', 'postponed'])): ?>
                        <span class="ev-badge" style="background:var(--tix-card-nacht, #131020);color:#fff;"><?php echo esc_html($status_label); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_intro($post_id) {
        $title = get_the_title($post_id);
        $excerpt = get_the_excerpt($post_id);
        $date_display = get_post_meta($post_id, '_tix_date_display', true);
        $location = get_post_meta($post_id, '_tix_location_short', true);
        ?>
        <div class="tse-intro m-intro">
            <div class="tse-intro-date"><?php echo esc_html($date_display); ?></div>
            <h1 class="tse-intro-title"><?php echo esc_html($title); ?></h1>
            <?php if ($location): ?>
                <div class="tse-intro-loc">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?php echo esc_html($location); ?>
                </div>
            <?php endif; ?>
            <?php if ($excerpt): ?>
                <p class="tse-intro-excerpt"><?php echo esc_html($excerpt); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_section($post_id, $key, $id_attr = '') {
        $content = get_post_meta($post_id, "_tix_info_{$key}", true);
        if (!$content) return;
        $label = get_post_meta($post_id, "_tix_info_{$key}_label", true) ?: ucfirst($key);
        ?>
        <div class="tse-sec m-<?php echo esc_attr($key); ?>" <?php echo $id_attr ? 'id="' . esc_attr($id_attr) . '"' : ''; ?>>
            <div class="tse-sec-label"><?php echo esc_html($label); ?></div>
            <div class="tse-sec-content"><?php echo wp_kses_post(wpautop($content)); ?></div>
        </div>
        <?php
    }

    public static function render_tickets($post_id) {
        ?>
        <div class="tse-sec m-tickets" id="tickets">
            <div class="tse-sec-label">Tickets</div>
            <h2 class="tse-sec-title">Tickets sichern</h2>
            <?php echo do_shortcode('[tix_ticket_selector]'); ?>
        </div>
        <?php
    }

    public static function render_sidebar_info($post_id) {
        $date_display = get_post_meta($post_id, '_tix_date_display', true);
        $doors        = get_post_meta($post_id, '_tix_doors_display', true);
        $location     = get_post_meta($post_id, '_tix_location', true);
        $address      = get_post_meta($post_id, '_tix_address', true);
        $price_range  = get_post_meta($post_id, '_tix_price_range', true);
        $age_display  = get_post_meta($post_id, '_tix_age_limit_display', true);
        $organizer    = get_post_meta($post_id, '_tix_organizer_display', true);
        $status       = get_post_meta($post_id, '_tix_status', true);
        $status_label = get_post_meta($post_id, '_tix_status_label', true);
        ?>
        <div class="tse-info-card sb-info">
            <?php if ($date_display): ?>
                <div class="tse-info-row">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <div>
                        <div class="tse-info-label">Datum & Uhrzeit</div>
                        <div class="tse-info-value"><?php echo esc_html($date_display); ?></div>
                        <?php if ($doors): ?><div class="tse-info-sub"><?php echo esc_html($doors); ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($location): ?>
                <div class="tse-info-row">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <div>
                        <div class="tse-info-label">Veranstaltungsort</div>
                        <div class="tse-info-value"><?php echo esc_html($location); ?></div>
                        <?php if ($address): ?><div class="tse-info-sub"><?php echo esc_html($address); ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($price_range): ?>
                <div class="tse-info-row">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    <div>
                        <div class="tse-info-label">Preis</div>
                        <div class="tse-info-value"><?php echo wp_kses_post($price_range); ?></div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($organizer): ?>
                <div class="tse-info-row">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <div>
                        <div class="tse-info-label">Veranstalter</div>
                        <div class="tse-info-value"><?php echo esc_html($organizer); ?></div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="tse-info-badges">
                <?php if ($status === 'sold_out'): ?>
                    <span class="tse-badge tse-badge-soldout">Ausverkauft</span>
                <?php else: ?>
                    <span class="tse-badge tse-badge-available">Verfügbar</span>
                <?php endif; ?>
                <?php if ($age_display): ?>
                    <span class="tse-badge tse-badge-age"><?php echo esc_html($age_display); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function render_calendar_btn($post_id) {
        if (!self::should_show('calendar', $post_id)) return;
        ?>
        <button class="tse-cal-btn sb-cal" id="tse-cal-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Zum Kalender hinzufügen
        </button>
        <?php
    }

    public static function render_share($post_id) {
        if (!self::should_show('share', $post_id)) return;
        ?>
        <div class="tse-share sb-share">
            <button class="tse-share-btn" id="tse-share-btn" title="Teilen">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                Teilen
            </button>
            <button class="tse-share-btn" id="tse-copy-btn" title="Link kopieren">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                Link
            </button>
            <a class="tse-share-btn" href="mailto:?subject=<?php echo esc_attr(get_the_title($post_id)); ?>&body=<?php echo esc_attr(get_permalink($post_id)); ?>" title="Per E-Mail teilen">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                E-Mail
            </a>
        </div>
        <?php
    }

    public static function render_countdown($post_id) {
        if (!self::should_show('countdown', $post_id)) return;
        ?>
        <div class="tse-countdown sb-countdown" id="tse-countdown">
            <div class="tse-countdown-label">Event startet in</div>
            <div class="tse-countdown-grid">
                <div class="tse-countdown-unit"><span class="tse-cd-val" id="tse-cd-days">--</span><span class="tse-cd-label">Tage</span></div>
                <div class="tse-countdown-unit"><span class="tse-cd-val" id="tse-cd-hours">--</span><span class="tse-cd-label">Std</span></div>
                <div class="tse-countdown-unit"><span class="tse-cd-val" id="tse-cd-mins">--</span><span class="tse-cd-label">Min</span></div>
                <div class="tse-countdown-unit"><span class="tse-cd-val" id="tse-cd-secs">--</span><span class="tse-cd-label">Sek</span></div>
            </div>
        </div>
        <?php
    }

    public static function render_gallery($post_id) {
        $gallery = get_post_meta($post_id, '_tix_gallery', true);
        if (!is_array($gallery) || empty($gallery)) return;
        ?>
        <div class="tse-sec m-gallery" id="gallery">
            <div class="tse-sec-label">Galerie</div>
            <div class="tse-gallery-grid">
                <?php foreach ($gallery as $att_id):
                    $url = wp_get_attachment_image_url($att_id, 'medium_large');
                    $full = wp_get_attachment_image_url($att_id, 'full');
                    if (!$url) continue;
                ?>
                    <a href="<?php echo esc_url($full); ?>" class="tse-gallery-item" target="_blank">
                        <img src="<?php echo esc_url($url); ?>" alt="" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public static function render_video($post_id) {
        $url = get_post_meta($post_id, '_tix_video_url', true);
        if (!$url) return;
        $embed = get_post_meta($post_id, '_tix_video_embed', true);
        if (!$embed) $embed = wp_oembed_get($url);
        if (!$embed) return;
        ?>
        <div class="tse-sec m-video" id="video">
            <div class="tse-sec-label">Video</div>
            <div class="tse-video-wrap"><?php echo $embed; ?></div>
        </div>
        <?php
    }

    public static function render_location($post_id) {
        $loc_id = get_post_meta($post_id, '_tix_location_id', true);
        if (!$loc_id) return;
        $name = get_the_title($loc_id);
        $address = get_post_meta($loc_id, '_tix_loc_address', true);
        ?>
        <div class="tse-sec m-anfahrt" id="anfahrt">
            <div class="tse-sec-label">Anfahrt</div>
            <h2 class="tse-sec-title"><?php echo esc_html($name); ?></h2>
            <?php if ($address): ?>
                <p class="tse-location-address"><?php echo esc_html($address); ?></p>
                <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($name . ' ' . $address); ?>" target="_blank" rel="noopener" class="tse-location-link">
                    In Google Maps öffnen →
                </a>
            <?php endif; ?>
        </div>
        <?php
    }
}
