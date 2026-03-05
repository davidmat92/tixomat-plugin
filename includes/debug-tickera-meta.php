<?php
/**
 * Tixomat – Tickera Meta Key Debugger
 * Werkzeuge → EH Tickera Debug
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'tix_debug_menu');
function tix_debug_menu() {
    add_management_page(
        'EH Tickera Debug',
        'EH Tickera Debug',
        'manage_options',
        'tix-tickera-debug',
        'tix_debug_page'
    );
}

function tix_debug_page() {
    echo '<div class="wrap">';
    echo '<h1>Tixomat – Tickera Meta Key Debugger</h1>';
    echo '<p>Erstelle manuell ein Tickera-Event + Ticket-Type, dann lade diese Seite neu um die Meta-Keys zu sehen.</p>';

    $sections = [
        ['Tickera Events (tc_events)', 'tc_events', null],
        ['Tickera Ticket Types (tc_tickets)', 'tc_tickets', null],
    ];

    foreach ($sections as [$title, $cpt, $meta_query]) {
        echo "<h2>{$title}</h2>";
        $args = ['post_type' => $cpt, 'numberposts' => 3, 'post_status' => 'any'];
        if ($meta_query) $args['meta_query'] = $meta_query;

        $posts = get_posts($args);
        if (!$posts) {
            echo '<p><em>Keine Einträge gefunden.</em></p>';
            continue;
        }

        foreach ($posts as $p) {
            echo '<h3>' . esc_html($p->post_title) . ' (ID: ' . $p->ID . ')</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Meta Key</th><th>Value</th></tr></thead><tbody>';
            foreach (get_post_meta($p->ID) as $key => $vals) {
                if (strpos($key, '_edit_') === 0 || $key === '_wp_old_date') continue;
                echo '<tr><td><code>' . esc_html($key) . '</code></td>';
                echo '<td><pre style="margin:0;max-width:500px;overflow:auto;white-space:pre-wrap;">' . esc_html($vals[0]) . '</pre></td></tr>';
            }
            echo '</tbody></table><br>';
        }
    }

    // WC Bridge Produkte
    echo '<h2>WC Produkte mit Tickera Bridge</h2>';
    $products = get_posts([
        'post_type' => 'product', 'numberposts' => 3, 'post_status' => 'any',
        'meta_query' => [['key' => '_tc_is_ticket', 'value' => 'yes']],
    ]);
    if ($products) {
        foreach ($products as $p) {
            echo '<h3>' . esc_html($p->post_title) . ' (ID: ' . $p->ID . ')</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Meta Key</th><th>Value</th></tr></thead><tbody>';
            foreach (get_post_meta($p->ID) as $key => $vals) {
                if (strpos($key, '_tc_') === 0 || strpos($key, '_tix_') === 0 || in_array($key, ['_price', '_regular_price', '_stock', '_manage_stock', '_virtual'])) {
                    echo '<tr><td><code>' . esc_html($key) . '</code></td>';
                    echo '<td>' . esc_html($vals[0]) . '</td></tr>';
                }
            }
            echo '</tbody></table><br>';
        }
    } else {
        echo '<p><em>Keine Bridge-Produkte gefunden.</em></p>';
    }

    // Post Type Check
    echo '<h2>Post Type Check</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Post Type</th><th>Existiert?</th><th>Anzahl</th></tr></thead><tbody>';
    foreach (['tc_events', 'tc_tickets', 'tc_orders'] as $cpt) {
        $exists = post_type_exists($cpt);
        echo '<tr><td><code>' . $cpt . '</code></td><td>' . ($exists ? '✅' : '❌') . '</td>';
        echo '<td>' . ($exists ? wp_count_posts($cpt)->publish : '—') . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}
