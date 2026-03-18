<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat Admin Shell – Fullscreen-Modus mit eigener Sidebar.
 *
 * Versteckt WordPress-Chrome (Admin-Bar, Sidebar, Footer) auf allen
 * Tixomat-Seiten und rendert eine eigene fixe linke Sidebar-Navigation.
 * Deaktivierbar unter Einstellungen → Erweitert → Admin-Ansicht.
 */
class TIX_Admin_Shell {

    public static function init() {
        add_filter('admin_body_class',       [__CLASS__, 'body_class']);
        add_action('in_admin_header',        [__CLASS__, 'render_sidebar'], 1);
        add_action('admin_enqueue_scripts',  [__CLASS__, 'enqueue'], 5);
        add_action('edit_form_after_title',  [__CLASS__, 'render_floating_publish'], 99);
    }

    /**
     * Erkennt ob wir auf einer Tixomat-Admin-Seite sind.
     */
    public static function is_tixomat_page() {
        $screen = get_current_screen();
        if (!$screen) return false;

        // Event CPT Seiten (Liste, Editor, Kategorien)
        if ($screen->post_type === 'event') return true;

        // Event-Taxonomien (Kategorien)
        if (($screen->taxonomy ?? '') === 'event_category') return true;

        // Andere Tixomat CPTs
        $tix_cpts = [
            'tix_ticket', 'tix_ticket_tpl', 'tix_support_ticket',
            'tix_location', 'tix_organizer', 'tix_subscriber',
            'tix_abandoned_cart', 'tix_seatmap', 'tix_special',
        ];
        if (in_array($screen->post_type, $tix_cpts, true)) return true;

        // Custom Admin-Seiten
        $tix_pages = [
            'tix-settings', 'tix-statistics', 'tix-support', 'tix-docs',
            'tix-promoters', 'tix-marketing-export', 'tix-campaigns',
            'tix-organizer-dashboard', 'tix-organizer-orders',
            'tix-organizer-guestlist', 'tix-organizer-email', 'tix-organizer-billing',
            'tix-organizer-media',
        ];
        $page = $_GET['page'] ?? '';
        if (in_array($page, $tix_pages, true)) return true;

        // Organizer: auch Profil-Seite ist Tixomat-Seite
        if (class_exists('TIX_Organizer_Admin') && TIX_Organizer_Admin::is_organizer()) {
            if ($screen->base === 'profile') return true;
        }

        return false;
    }

    /**
     * Body-Klasse für Fullscreen.
     */
    public static function body_class($classes) {
        if (self::is_tixomat_page()) {
            $classes .= ' tix-fullscreen';
        }
        return $classes;
    }

    /**
     * Shell-Assets laden.
     */
    public static function enqueue($hook) {
        if (!self::is_tixomat_page()) return;

        wp_enqueue_style(
            'tix-admin-shell',
            TIXOMAT_URL . 'assets/css/admin-shell.css',
            [],
            TIXOMAT_VERSION
        );
        wp_enqueue_script(
            'tix-admin-shell',
            TIXOMAT_URL . 'assets/js/admin-shell.js',
            [],
            TIXOMAT_VERSION,
            true
        );

        // Organizer URL-Rewriting
        $is_org = class_exists('TIX_Organizer_Admin') && TIX_Organizer_Admin::is_organizer();
        if ($is_org) {
            $slug = tix_get_settings('organizer_slug');
            if ($slug) {
                wp_localize_script('tix-admin-shell', 'tixShell', [
                    'isOrganizer'   => true,
                    'organizerSlug' => $slug,
                    'homeUrl'       => home_url('/'),
                ]);
            }
        }
    }

    /**
     * Sidebar-HTML rendern.
     */
    public static function render_sidebar() {
        if (!self::is_tixomat_page()) return;

        $screen       = get_current_screen();
        $current_page = $_GET['page'] ?? '';
        $post_type    = $screen ? $screen->post_type : '';
        $base         = $screen ? $screen->base : '';
        $taxonomy     = $screen ? ($screen->taxonomy ?? '') : '';
        $is_settings  = ($current_page === 'tix-settings');
        $is_docs      = ($current_page === 'tix-docs');
        $is_support   = ($current_page === 'tix-support');
        $s            = function_exists('tix_get_settings') ? tix_get_settings() : [];

        // ── Aktive Seite bestimmen ──
        $active = '';
        if ($base === 'edit' && $post_type === 'event')                         $active = 'events';
        elseif ($base === 'post' && $post_type === 'event')                     $active = 'event-edit';
        elseif ($base === 'edit-tags' && $taxonomy === 'event_category')        $active = 'categories';
        elseif ($post_type === 'tix_location')                                  $active = 'locations';
        elseif ($post_type === 'tix_organizer')                                 $active = 'organizers';
        elseif ($post_type === 'tix_ticket')                                    $active = 'tickets';
        elseif ($post_type === 'tix_ticket_tpl')                                $active = 'ticket-templates';
        elseif ($post_type === 'tix_seatmap')                                   $active = 'seatmaps';
        elseif ($post_type === 'tix_subscriber')                                $active = 'subscribers';
        elseif ($post_type === 'tix_abandoned_cart')                            $active = 'abandoned-carts';
        elseif ($post_type === 'tix_special')                                   $active = 'specials';
        elseif ($current_page === 'tix-statistics')                             $active = 'statistics';
        elseif ($current_page === 'tix-support')                                $active = 'support';
        elseif ($current_page === 'tix-promoters')                              $active = 'promoter';
        elseif ($current_page === 'tix-marketing-export')                       $active = 'marketing-export';
        elseif ($current_page === 'tix-campaigns')                              $active = 'campaigns';
        elseif ($current_page === 'tix-settings')                               $active = 'settings';
        elseif ($current_page === 'tix-docs')                                   $active = 'docs';

        // ── Settings Tabs ──
        $settings_tabs = [
            'design'          => ['icon' => 'art',                    'label' => 'Design'],
            'buttons'         => ['icon' => 'button',                 'label' => 'Buttons'],
            'selector'        => ['icon' => 'tickets-alt',            'label' => 'Ticket Selector'],
            'faq'             => ['icon' => 'editor-help',            'label' => 'FAQ'],
            'checkout'        => ['icon' => 'cart',                   'label' => 'Checkout'],
            'express'         => ['icon' => 'performance',            'label' => 'Express Checkout'],
            'my-tickets'      => ['icon' => 'id',                     'label' => 'Meine Tickets'],
            'newsletter'      => ['icon' => 'email-alt',              'label' => 'Newsletter'],
            'checkin'         => ['icon' => 'clipboard',              'label' => 'Check-in'],
            'ticket-template' => ['icon' => 'media-document',         'label' => 'Ticket-Template'],
            'advanced'        => ['icon' => 'admin-generic',          'label' => 'Erweitert'],
        ];
        $settings_more = [
            'data-sync'  => ['icon' => 'cloud-saved',              'label' => 'Daten-Sync'],
            'event-page' => ['icon' => 'welcome-widgets-menus',    'label' => 'Event-Seite'],
            'share'      => ['icon' => 'share',                    'label' => 'Share'],
            'marketing'  => ['icon' => 'megaphone',                'label' => 'Marketing'],
        ];

        // ── Docs Tabs ──
        $docs_tabs = [
            'meta'       => ['icon' => 'database',       'label' => 'Meta-Felder'],
            'shortcodes' => ['icon' => 'shortcode',      'label' => 'Shortcodes'],
            'functions'  => ['icon' => 'admin-tools',    'label' => 'Funktionen'],
            'ajax'       => ['icon' => 'rest-api',       'label' => 'AJAX & Hooks'],
            'templates'  => ['icon' => 'media-text',     'label' => 'Ticket-Vorlagen'],
            'promoter'   => ['icon' => 'businessman',    'label' => 'Promoter'],
            'organizer'  => ['icon' => 'groups',         'label' => 'Veranstalter'],
            'bot'        => ['icon' => 'format-chat',    'label' => 'Ticket-Bot'],
            'rest-api'   => ['icon' => 'rest-api',        'label' => 'REST API'],
        ];

        // Logo URL
        $logo_url = 'https://tixomat.de/wp-content/uploads/2026/03/logo-tixomat-light-500px.png';

        // Organizer-Check
        $is_organizer = class_exists('TIX_Organizer_Admin') && TIX_Organizer_Admin::is_organizer();

        ?>
        <div class="tix-shell-sidebar" id="tix-shell-sidebar">

            <!-- ── Brand ── -->
            <div class="tix-shell-brand">
                <img src="<?php echo esc_url($logo_url); ?>" alt="Tixomat" class="tix-shell-logo-img">
                <?php if (!$is_organizer) : ?>
                    <div class="tix-shell-version">v<?php echo esc_html(TIXOMAT_VERSION); ?></div>
                <?php endif; ?>
            </div>

            <!-- ── Navigation ── -->
            <nav class="tix-shell-nav">

            <?php if ($is_organizer) : ?>
                <?php // ═══ ORGANIZER SIDEBAR ═══ ?>

                <!-- Dashboard -->
                <div class="tix-shell-group">
                    <a href="<?php echo admin_url('admin.php?page=tix-organizer-dashboard'); ?>"
                       class="tix-shell-item<?php echo ($current_page === 'tix-organizer-dashboard') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-dashboard"></span>
                        <span>Dashboard</span>
                    </a>
                </div>

                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Meine Events</div>
                    <a href="<?php echo admin_url('edit.php?post_type=event'); ?>"
                       class="tix-shell-item<?php echo $active === 'events' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <span>Alle Events</span>
                    </a>
                    <a href="<?php echo admin_url('post-new.php?post_type=event'); ?>"
                       class="tix-shell-item<?php echo ($active === 'event-edit' && ($screen->action ?? '') === 'add') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <span>Neues Event</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_location'); ?>"
                       class="tix-shell-item<?php echo $active === 'locations' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-location"></span>
                        <span>Locations</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_seatmap'); ?>"
                       class="tix-shell-item<?php echo $active === 'seatmaps' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-layout"></span>
                        <span>Saalpl&auml;ne</span>
                    </a>
                </div>

                <!-- Ticketing -->
                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Ticketing</div>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_ticket'); ?>"
                       class="tix-shell-item<?php echo $active === 'tickets' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-tickets-alt"></span>
                        <span>Verkaufte Tickets</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=tix-organizer-orders'); ?>"
                       class="tix-shell-item<?php echo ($current_page === 'tix-organizer-orders') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-cart"></span>
                        <span>Bestellungen</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_ticket_tpl'); ?>"
                       class="tix-shell-item<?php echo $active === 'ticket-templates' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-media-document"></span>
                        <span>Ticket-Vorlagen</span>
                    </a>
                </div>

                <!-- Verwaltung -->
                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Verwaltung</div>
                    <a href="<?php echo admin_url('admin.php?page=tix-statistics'); ?>"
                       class="tix-shell-item<?php echo $active === 'statistics' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <span>Statistiken</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_subscriber'); ?>"
                       class="tix-shell-item<?php echo $active === 'subscribers' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-email-alt"></span>
                        <span>Newsletter</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=tix-organizer-guestlist'); ?>"
                       class="tix-shell-item<?php echo ($current_page === 'tix-organizer-guestlist') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-download"></span>
                        <span>G&auml;steliste Export</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=tix-organizer-email'); ?>"
                       class="tix-shell-item<?php echo ($current_page === 'tix-organizer-email') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-email"></span>
                        <span>E-Mail senden</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=tix-organizer-billing'); ?>"
                       class="tix-shell-item<?php echo ($current_page === 'tix-organizer-billing') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-media-spreadsheet"></span>
                        <span>Abrechnung</span>
                    </a>
                </div>

                <!-- Einstellungen -->
                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Einstellungen</div>
                    <a href="<?php echo admin_url('admin.php?page=tix-organizer-media'); ?>"
                       class="tix-shell-item<?php echo ($current_page === 'tix-organizer-media') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-format-image"></span>
                        <span>Meine Medien</span>
                    </a>
                    <a href="<?php echo admin_url('profile.php'); ?>"
                       class="tix-shell-item">
                        <span class="dashicons dashicons-admin-users"></span>
                        <span>Mein Profil</span>
                    </a>
                </div>

            <?php else : ?>
                <?php // ═══ ADMIN SIDEBAR (vollständig) ═══ ?>

                <!-- Events -->
                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Events</div>
                    <a href="<?php echo admin_url('edit.php?post_type=event'); ?>"
                       class="tix-shell-item<?php echo $active === 'events' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <span>Alle Events</span>
                    </a>
                    <a href="<?php echo admin_url('post-new.php?post_type=event'); ?>"
                       class="tix-shell-item<?php echo ($active === 'event-edit' && ($screen->action ?? '') === 'add') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <span>Neues Event</span>
                    </a>
                    <a href="<?php echo admin_url('edit-tags.php?taxonomy=event_category&post_type=event'); ?>"
                       class="tix-shell-item<?php echo $active === 'categories' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-tag"></span>
                        <span>Kategorien</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_location'); ?>"
                       class="tix-shell-item<?php echo $active === 'locations' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-location"></span>
                        <span>Locations</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_organizer'); ?>"
                       class="tix-shell-item<?php echo $active === 'organizers' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-businessperson"></span>
                        <span>Veranstalter</span>
                    </a>
                </div>

                <!-- Ticketing -->
                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Ticketing</div>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_ticket'); ?>"
                       class="tix-shell-item<?php echo $active === 'tickets' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-tickets-alt"></span>
                        <span>Verkaufte Tickets</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_ticket_tpl'); ?>"
                       class="tix-shell-item<?php echo $active === 'ticket-templates' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-media-document"></span>
                        <span>Ticket-Vorlagen</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_seatmap'); ?>"
                       class="tix-shell-item<?php echo $active === 'seatmaps' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-layout"></span>
                        <span>Saalpl&auml;ne</span>
                    </a>
                    <?php if (!empty($s['specials_enabled'])) : ?>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_special'); ?>"
                       class="tix-shell-item<?php echo $active === 'specials' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-star-filled"></span>
                        <span>Specials</span>
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Verwaltung -->
                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Verwaltung</div>
                    <a href="<?php echo admin_url('admin.php?page=tix-statistics'); ?>"
                       class="tix-shell-item<?php echo $active === 'statistics' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <span>Statistiken</span>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_subscriber'); ?>"
                       class="tix-shell-item<?php echo $active === 'subscribers' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-email-alt"></span>
                        <span>Newsletter</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=tix-organizer-guestlist'); ?>"
                       class="tix-shell-item<?php echo ($current_page === 'tix-organizer-guestlist') ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-download"></span>
                        <span>G&auml;steliste &amp; Tickets</span>
                    </a>
                    <?php if (!empty($s['abandoned_cart_enabled'])) : ?>
                    <a href="<?php echo admin_url('edit.php?post_type=tix_abandoned_cart'); ?>"
                       class="tix-shell-item<?php echo $active === 'abandoned-carts' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-dismiss"></span>
                        <span>Abgebrochene Bestellungen</span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($s['support_enabled'])) : ?>
                        <?php if ($is_support) : ?>
                            <a href="#tickets" class="tix-shell-item tix-shell-support-tab active" data-support-tab="tickets">
                                <span class="dashicons dashicons-format-chat"></span>
                                <span>Anfragen</span>
                            </a>
                            <a href="#search" class="tix-shell-item tix-shell-support-tab" data-support-tab="search">
                                <span class="dashicons dashicons-search"></span>
                                <span>Kunden-Suche</span>
                            </a>
                            <a href="#stats" class="tix-shell-item tix-shell-support-tab" data-support-tab="stats">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <span>Statistiken</span>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo admin_url('admin.php?page=tix-support'); ?>"
                               class="tix-shell-item<?php echo $active === 'support' ? ' active' : ''; ?>">
                                <span class="dashicons dashicons-format-chat"></span>
                                <span>Support</span>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($s['promoter_enabled'])) : ?>
                    <a href="<?php echo admin_url('admin.php?page=tix-promoters'); ?>"
                       class="tix-shell-item<?php echo $active === 'promoter' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-groups"></span>
                        <span>Promoter</span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($s['marketing_export_enabled'])) : ?>
                    <a href="<?php echo admin_url('admin.php?page=tix-marketing-export'); ?>"
                       class="tix-shell-item<?php echo $active === 'marketing-export' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-download"></span>
                        <span>Marketing Export</span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($s['campaign_tracking_enabled'])) : ?>
                    <a href="<?php echo admin_url('admin.php?page=tix-campaigns'); ?>"
                       class="tix-shell-item<?php echo $active === 'campaigns' ? ' active' : ''; ?>">
                        <span class="dashicons dashicons-megaphone"></span>
                        <span>Kampagnen</span>
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Einstellungen -->
                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Einstellungen</div>
                    <?php if ($is_settings) : ?>
                        <?php foreach ($settings_tabs as $tab => $t) : ?>
                            <a href="#<?php echo $tab; ?>"
                               class="tix-shell-item tix-shell-settings-tab<?php echo $tab === 'design' ? ' active' : ''; ?>"
                               data-settings-tab="<?php echo $tab; ?>">
                                <span class="dashicons dashicons-<?php echo $t['icon']; ?>"></span>
                                <span><?php echo $t['label']; ?></span>
                            </a>
                        <?php endforeach; ?>

                        <!-- Mehr-Toggle -->
                        <button type="button" class="tix-shell-item tix-shell-more-btn" id="tix-shell-settings-more-btn">
                            <span class="dashicons dashicons-ellipsis"></span>
                            <span>Mehr</span>
                            <span class="tix-shell-chevron dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        <div class="tix-shell-more-items" id="tix-shell-settings-more">
                            <?php foreach ($settings_more as $tab => $t) : ?>
                                <a href="#<?php echo $tab; ?>"
                                   class="tix-shell-item tix-shell-settings-tab"
                                   data-settings-tab="<?php echo $tab; ?>">
                                    <span class="dashicons dashicons-<?php echo $t['icon']; ?>"></span>
                                    <span><?php echo $t['label']; ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <a href="<?php echo admin_url('admin.php?page=tix-settings'); ?>"
                           class="tix-shell-item<?php echo $active === 'settings' ? ' active' : ''; ?>">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <span>Einstellungen</span>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Hilfe -->
                <div class="tix-shell-group">
                    <div class="tix-shell-group-label">Hilfe</div>
                    <?php if ($is_docs) : ?>
                        <?php foreach ($docs_tabs as $tab => $t) : ?>
                            <a href="#<?php echo $tab; ?>"
                               class="tix-shell-item tix-shell-docs-tab<?php echo $tab === 'meta' ? ' active' : ''; ?>"
                               data-docs-tab="<?php echo $tab; ?>">
                                <span class="dashicons dashicons-<?php echo $t['icon']; ?>"></span>
                                <span><?php echo $t['label']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <a href="<?php echo admin_url('admin.php?page=tix-docs'); ?>"
                           class="tix-shell-item<?php echo $active === 'docs' ? ' active' : ''; ?>">
                            <span class="dashicons dashicons-book-alt"></span>
                            <span>Dokumentation</span>
                        </a>
                    <?php endif; ?>
                </div>

            <?php endif; // end admin sidebar ?>

            </nav>

            <!-- ── Footer ── -->
            <div class="tix-shell-footer">
                <?php if ($is_organizer) : ?>
                    <a href="<?php echo esc_url(home_url()); ?>" class="tix-shell-back">
                        <span class="dashicons dashicons-admin-home"></span>
                        <span>Startseite</span>
                    </a>
                    <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="tix-shell-back" style="margin-top:2px;">
                        <span class="dashicons dashicons-exit"></span>
                        <span>Abmelden</span>
                    </a>
                <?php else : ?>
                    <a href="<?php echo admin_url(); ?>" class="tix-shell-back">
                        <span class="dashicons dashicons-arrow-left-alt"></span>
                        <span>WordPress</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Floating Publish/Update Button auf Post-Edit-Seiten.
     */
    public static function render_floating_publish($post) {
        if (!self::is_tixomat_page()) return;

        $status = get_post_status($post);
        $is_published = ($status === 'publish');
        $btn_label = $is_published ? 'Aktualisieren' : 'Veröffentlichen';
        $status_label = $is_published ? 'Veröffentlicht' : ucfirst($status);
        $preview_url = get_preview_post_link($post);
        $permalink = get_permalink($post);
        ?>
        <div class="tix-floating-publish">
            <?php if ($is_published && $permalink) : ?>
                <a href="<?php echo esc_url($permalink); ?>" class="tix-preview-link" target="_blank">Ansehen</a>
            <?php elseif ($preview_url) : ?>
                <a href="<?php echo esc_url($preview_url); ?>" class="tix-preview-link" target="_blank">Vorschau</a>
            <?php endif; ?>
            <span class="tix-status-indicator">
                <span class="tix-status-dot" style="background:<?php echo $is_published ? '#22c55e' : '#f59e0b'; ?>"></span>
                <?php echo esc_html($status_label); ?>
            </span>
            <button type="button" class="tix-publish-btn" id="tix-floating-publish-btn">
                <?php echo esc_html($btn_label); ?>
            </button>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var btn = document.getElementById('tix-floating-publish-btn');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var submit = document.getElementById('publish');
                if (submit) { submit.click(); return; }
                var form = document.getElementById('post');
                if (form) form.submit();
            });
        });
        </script>
        <?php
    }
}
