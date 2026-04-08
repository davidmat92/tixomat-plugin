<?php
/**
 * Tixomat Archive Event Template — /events/
 * Automatische Event-Übersichtsseite mit [tix_events] Karten.
 */
if (!defined('ABSPATH')) exit;

get_header();

$s = function_exists('tix_get_settings') ? tix_get_settings() : [];
$pad_x = intval($s['ec_pad_x'] ?? 32);
$pad_y = intval($s['ec_pad_y'] ?? 56);
?>
<div style="padding:<?php echo $pad_y; ?>px <?php echo $pad_x; ?>px;">
    <?php echo do_shortcode('[tix_events show_header="0" show_filter="1" limit="20"]'); ?>
</div>
<?php

get_footer();
