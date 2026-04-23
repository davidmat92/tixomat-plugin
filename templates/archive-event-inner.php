<?php
/**
 * Tixomat Archive Event — Content via Breakdance's Render-Flow.
 * Mimicked nach breakdance-no-template.php: Global Header + Content + Global Footer.
 */
if (!defined('ABSPATH')) exit;

$s = function_exists('tix_get_settings') ? tix_get_settings() : [];
$pad_x        = intval($s['ec_pad_x'] ?? 32);
$pad_y        = intval($s['ec_pad_y'] ?? 56);
$max_width    = max(600, min(2000, intval($s['ec_max_width'] ?? 1200)));
$show_search  = !empty($s['ec_show_search'] ?? 1);

$search_query = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

// Breakdance Header/Footer rendern (wie breakdance-no-template.php)
$rendered_header = '';
$rendered_footer = '';
if (function_exists('Breakdance\Themeless\get_breakdance_header_template_for_request')) {
    $rendered_header = \Breakdance\Themeless\get_breakdance_header_template_for_request();
}
if (function_exists('Breakdance\Themeless\get_breakdance_footer_template_for_request')) {
    $rendered_footer = \Breakdance\Themeless\get_breakdance_footer_template_for_request();
}

// Unseren Content als String vorbereiten
ob_start();
?>
<style>
    .tix-archive-wrap { width: 100%; max-width: 100%; box-sizing: border-box; }
    .tix-archive-inner { max-width: <?php echo $max_width; ?>px; margin: 0 auto; width: 100%; box-sizing: border-box; }
    body .tix-archive-inner .section { max-width: none !important; padding-left: 0 !important; padding-right: 0 !important; }
    body .tix-archive-inner .section > .section-inner { max-width: <?php echo $max_width; ?>px !important; width: 100% !important; padding-left: 0 !important; padding-right: 0 !important; }
</style>
<main class="tix-archive-wrap" style="padding:<?php echo $pad_y; ?>px <?php echo $pad_x; ?>px;">
    <div class="tix-archive-inner">
        <?php if ($search_query !== ''): ?>
            <?php
            $result_count_query = TIX_Event_Cards::query_events([
                'limit'    => 100,
                'category' => '',
                'featured' => '',
            ]);
            $result_count = is_array($result_count_query) ? count($result_count_query) : 0;
            $clear_url = remove_query_arg('s');
            ?>
            <div class="tix-archive-search-banner" style="margin:0 0 24px;padding:20px 24px;background:var(--tix-card-sand,#F8F5EF);border-radius:14px;display:flex;flex-wrap:wrap;align-items:center;gap:16px;justify-content:space-between;">
                <div>
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;margin-bottom:4px;">Suchergebnisse</div>
                    <h1 style="margin:0;font-size:1.35rem;font-weight:800;"><?php echo $result_count; ?> Event<?php echo $result_count !== 1 ? 's' : ''; ?> für „<em><?php echo esc_html($search_query); ?></em>"</h1>
                </div>
                <a href="<?php echo esc_url($clear_url); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:transparent;border:1.5px solid currentColor;border-radius:50px;text-decoration:none;color:inherit;font-size:0.85rem;font-weight:600;">✕ Suche aufheben</a>
            </div>
        <?php endif; ?>

        <?php
        $render_filter = ($show_search && !$search_query) ? '1' : '0';
        echo do_shortcode('[tix_events show_header="0" show_filter="' . $render_filter . '" limit="20"]');
        ?>
    </div>
</main>
<?php
$content = ob_get_clean();

// outputHeadHtml mimicked: HTML-Shell + wp_head + Placeholders für Breakdance-Dependencies
if (function_exists('Breakdance\Themeless\outputHeadHtml')) {
    \Breakdance\Themeless\outputHeadHtml();
} else {
    // Fallback
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php wp_head(); ?>
    </head>
    <body <?php body_class(); ?>>
    <?php if (function_exists('wp_body_open')) wp_body_open();
}

echo $rendered_header;
echo $content;
echo $rendered_footer;

if (!function_exists('Breakdance\Themeless\outputHeadHtml')) {
    ?>
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}
