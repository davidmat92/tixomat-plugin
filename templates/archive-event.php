<?php
/**
 * Tixomat Archive Event Template — /events/
 * Automatische Event-Übersichtsseite mit [tix_events] Karten.
 */
if (!defined('ABSPATH')) exit;

get_header();

echo do_shortcode('[tix_events show_header="1" show_filter="1" header_title="Alle Events" header_label="Veranstaltungen" limit="20"]');

get_footer();
