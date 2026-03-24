<?php
/**
 * Tixomat Single Event — Content Partial
 * Used by both the full template (single-event.php) and the [tix_event_page] shortcode.
 */
if (!defined('ABSPATH')) exit;

$id = get_the_ID();
$tabs = TIX_Single_Event::get_tabs($id);

// JS-Daten für Countdown/Kalender/Share
if (!wp_script_is('tix-single-event', 'enqueued')) {
    TIX_Single_Event::enqueue_assets();
}
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
?>

<?php if (!empty($tabs)): ?>
<div class="tse-tabs" id="tse-tabs">
    <div class="tse-tabs-inner">
        <?php foreach ($tabs as $i => $tab): ?>
            <a class="tse-tab <?php echo $i === 0 ? 'active' : ''; ?>" href="#<?php echo esc_attr($tab['id']); ?>"><?php echo esc_html($tab['label']); ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="tse-wrap">
    <div class="tse-grid">

        <div class="tse-sidebar">
            <?php TIX_Single_Event::render_sidebar_info($id); ?>
            <?php TIX_Single_Event::render_calendar_btn($id); ?>
            <?php TIX_Single_Event::render_share($id); ?>
            <?php TIX_Single_Event::render_countdown($id); ?>
        </div>

        <?php if (TIX_Single_Event::should_show('hero', $id)):
            TIX_Single_Event::render_hero($id);
        endif; ?>

        <?php TIX_Single_Event::render_intro($id); ?>

        <?php if (TIX_Single_Event::should_show('tickets', $id)):
            TIX_Single_Event::render_tickets($id);
        endif; ?>

        <?php if (TIX_Single_Event::should_show('description', $id)):
            TIX_Single_Event::render_section($id, 'description', 'info');
        endif; ?>

        <?php if (TIX_Single_Event::should_show('lineup', $id)):
            TIX_Single_Event::render_section($id, 'lineup', 'lineup');
        endif; ?>

        <?php if (TIX_Single_Event::should_show('specials', $id)):
            TIX_Single_Event::render_section($id, 'specials');
        endif; ?>

        <?php if (TIX_Single_Event::should_show('extra_info', $id)):
            TIX_Single_Event::render_section($id, 'extra_info');
        endif; ?>

        <?php if (TIX_Single_Event::should_show('timetable', $id)): ?>
            <div class="tse-sec m-timetable" id="timetable">
                <div class="tse-sec-label">Programm</div>
                <?php echo do_shortcode('[tix_timetable]'); ?>
            </div>
        <?php endif; ?>

        <?php if (TIX_Single_Event::should_show('gallery', $id)):
            TIX_Single_Event::render_gallery($id);
        endif; ?>

        <?php if (TIX_Single_Event::should_show('video', $id)):
            TIX_Single_Event::render_video($id);
        endif; ?>

        <?php if (TIX_Single_Event::should_show('faq', $id)): ?>
            <div class="tse-sec m-faq" id="faq">
                <div class="tse-sec-label">Häufige Fragen</div>
                <?php echo do_shortcode('[tix_faq]'); ?>
            </div>
        <?php endif; ?>

        <?php if (TIX_Single_Event::should_show('location', $id)):
            TIX_Single_Event::render_location($id);
        endif; ?>

        <?php if (TIX_Single_Event::should_show('raffle', $id)): ?>
            <div class="tse-sec m-gewinnspiel" id="gewinnspiel">
                <div class="tse-sec-label">Gewinnspiel</div>
                <?php echo do_shortcode('[tix_raffle]'); ?>
            </div>
        <?php endif; ?>

        <?php if (TIX_Single_Event::should_show('feedback', $id)): ?>
            <div class="tse-sec m-feedback">
                <?php echo do_shortcode('[tix_feedback]'); ?>
            </div>
        <?php endif; ?>

        <?php if (TIX_Single_Event::should_show('upsell', $id)): ?>
            <div class="tse-sec m-upsell" style="grid-column:1/-1;">
                <?php echo do_shortcode('[tix_upsell]'); ?>
            </div>
        <?php endif; ?>

    </div>
</div>
