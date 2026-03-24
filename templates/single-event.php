<?php
/**
 * Tixomat Single Event Template
 *
 * Opt-in Template für Event-Einzelseiten.
 * Nutzt get_header()/get_footer() für Theme/Breakdance-Kompatibilität.
 */
if (!defined('ABSPATH')) exit;

get_header();

while (have_posts()) : the_post();
    include __DIR__ . '/single-event-content.php';
endwhile;

get_footer();
