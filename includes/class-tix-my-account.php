<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat - Mein Konto
 *
 * Shortcode: [tix_account]
 * Natives Account-Management ohne WooCommerce-Abhaengigkeit.
 * Tabs: Dashboard, Meine Tickets, Profil, Passwort, Logout.
 */
class TIX_My_Account {

    private static $tabs = [
        'dashboard' => ['label' => 'Dashboard',          'icon' => 'dashicons-admin-home'],
        'tickets'   => ['label' => 'Meine Tickets',      'icon' => 'dashicons-tickets-alt'],
        'orders'    => ['label' => 'Meine Bestellungen', 'icon' => 'dashicons-cart'],
        'profile'   => ['label' => 'Profil bearbeiten',  'icon' => 'dashicons-admin-users'],
        'password'  => ['label' => 'Passwort ändern',    'icon' => 'dashicons-lock'],
        'privacy'   => ['label' => 'Datenschutz',        'icon' => 'dashicons-shield'],
        'logout'    => ['label' => 'Abmelden',           'icon' => 'dashicons-exit'],
    ];

    public static function init() {
        add_shortcode('tix_account', [__CLASS__, 'render']);
        add_action('wp_ajax_tix_account_update_profile',  [__CLASS__, 'ajax_update_profile']);
        add_action('wp_ajax_tix_account_change_password', [__CLASS__, 'ajax_change_password']);
        add_action('wp_ajax_tix_account_export_data',     [__CLASS__, 'ajax_export_data']);
        add_action('wp_ajax_tix_account_delete',          [__CLASS__, 'ajax_delete_account']);
    }

    private static function enqueue() {
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'tix-account',
            TIXOMAT_URL . 'assets/css/tix-account.css',
            [],
            TIXOMAT_VERSION
        );
    }

    // ══════════════════════════════════════
    // RENDER
    // ══════════════════════════════════════

    public static function render($atts = []) {
        self::enqueue();

        if (!is_user_logged_in()) {
            return self::render_login();
        }

        $user = wp_get_current_user();
        $current_tab = sanitize_key($_GET['tix_tab'] ?? 'dashboard');
        if (!isset(self::$tabs[$current_tab])) $current_tab = 'dashboard';

        // Logout: direkt weiterleiten
        if ($current_tab === 'logout') {
            $current_tab = 'dashboard'; // Fallback falls JS nicht läuft
        }

        $primary = function_exists('tix_primary') ? tix_primary() : '#FF5500';
        $page_url = get_permalink();

        ob_start();
        ?>
        <div class="tix-account" style="--tix-acc-primary: <?php echo esc_attr($primary); ?>;">
            <nav class="tix-account-nav">
                <ul>
                    <?php foreach (self::$tabs as $slug => $tab):
                        if ($slug === 'logout'):
                            $url = wp_logout_url($page_url);
                        else:
                            $url = add_query_arg('tix_tab', $slug, $page_url);
                        endif;
                        $active = ($slug === $current_tab) ? ' is-active' : '';
                    ?>
                        <li class="tix-account-nav-item<?php echo $active; ?>">
                            <a href="<?php echo esc_url($url); ?>">
                                <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                                <?php echo esc_html($tab['label']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <div class="tix-account-content">
                <?php
                switch ($current_tab) {
                    case 'tickets':
                        self::render_tickets();
                        break;
                    case 'orders':
                        self::render_orders($user);
                        break;
                    case 'profile':
                        self::render_profile($user);
                        break;
                    case 'password':
                        self::render_password();
                        break;
                    case 'privacy':
                        self::render_privacy($user);
                        break;
                    default:
                        self::render_dashboard($user);
                        break;
                }
                ?>
            </div>
        </div>

        <script>
        (function() {
            var forms = document.querySelectorAll('.tix-account-form');
            forms.forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var btn = form.querySelector('button[type="submit"]');
                    var notice = form.querySelector('.tix-account-notice');
                    var origText = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = 'Speichern...';
                    if (notice) notice.style.display = 'none';

                    var data = new FormData(form);

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: data,
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        btn.textContent = origText;
                        if (notice) {
                            notice.textContent = res.data || (res.success ? 'Gespeichert!' : 'Fehler');
                            notice.className = 'tix-account-notice ' + (res.success ? 'success' : 'error');
                            notice.style.display = 'block';
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.textContent = origText;
                        if (notice) {
                            notice.textContent = 'Verbindungsfehler. Bitte erneut versuchen.';
                            notice.className = 'tix-account-notice error';
                            notice.style.display = 'block';
                        }
                    });
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════
    // LOGIN
    // ══════════════════════════════════════

    private static function render_login() {
        ob_start();
        ?>
        <div class="tix-account">
            <div class="tix-account-login">
                <div class="tix-account-login-icon">&#128100;</div>
                <h2>Mein Konto</h2>
                <p>Melde dich an, um dein Konto zu verwalten.</p>
                <?php
                if (defined('TIX_ON_ORG_SUBDOMAIN') && defined('TIX_LANDING_PARENT_HOST')):
                    $parent = TIX_LANDING_PARENT_HOST;
                ?>
                    <p style="font-size:12px;color:#64748b;margin:0 0 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:8px 12px;">
                        💡 Dein <strong><?php echo esc_html($parent); ?></strong>-Konto gilt hier ebenfalls — einfach mit denselben Zugangsdaten anmelden.
                    </p>
                <?php endif; ?>
                <?php
                wp_login_form([
                    'redirect'       => get_permalink(),
                    'form_id'        => 'tix-account-login-form',
                    'label_username' => 'E-Mail oder Benutzername',
                    'label_password' => 'Passwort',
                    'label_remember' => 'Angemeldet bleiben',
                    'label_log_in'   => 'Anmelden',
                ]);
                ?>
                <?php if (get_option('users_can_register')): ?>
                    <p class="tix-account-login-register">
                        Noch kein Konto? <a href="<?php echo esc_url(wp_registration_url()); ?>">Jetzt registrieren</a>
                    </p>
                <?php endif; ?>

                <?php // Magic-Link-Login: konsistenter Block auf jeder Login-Seite ?>
                <?php if (class_exists('TIX_My_Tickets')): ?>
                    <?php echo TIX_My_Tickets::render_magic_link_block([
                        'heading' => 'Login per E-Mail-Link',
                        'text'    => 'Kein Passwort zur Hand? Wir schicken dir einen einmaligen Link, mit dem du dich direkt einloggen kannst — egal ob als bestehender Kunde oder als Gast (du siehst dann deine Tickets ohne Konto).',
                        'btn'     => 'Magic-Link senden',
                        'id'      => 'tix-magic-account',
                    ]); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════
    // TAB: DASHBOARD
    // ══════════════════════════════════════

    private static function render_dashboard($user) {
        global $wpdb;
        $email = $user->user_email;

        // Ticket-Anzahl
        $ticket_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_ticket_owner_email' AND pm.meta_value = %s
             INNER JOIN {$wpdb->postmeta} ps ON p.ID = ps.post_id AND ps.meta_key = '_tix_ticket_status' AND ps.meta_value IN ('valid','used')
             WHERE p.post_type = 'tix_ticket' AND p.post_status = 'publish'",
            $email
        )));

        // Bestellungen
        $order_count = 0;
        if (class_exists('TIX_Order')) {
            $t = TIX_Order::table_name();
            $order_count = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $t WHERE billing_email = %s AND status IN ('completed','processing')",
                $email
            )));
        }
        if (function_exists('wc_get_orders')) {
            $order_count += count(wc_get_orders([
                'customer' => $user->ID,
                'status'   => ['wc-completed', 'wc-processing'],
                'return'   => 'ids',
                'limit'    => -1,
            ]));
        }

        // Kommende Events
        $upcoming = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm_event.meta_value)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_tix_ticket_owner_email' AND pm_email.meta_value = %s
             INNER JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_tix_ticket_status' AND pm_status.meta_value = 'valid'
             INNER JOIN {$wpdb->postmeta} pm_event ON p.ID = pm_event.post_id AND pm_event.meta_key = '_tix_ticket_event_id'
             INNER JOIN {$wpdb->postmeta} pm_date ON pm_event.meta_value = pm_date.post_id AND pm_date.meta_key = '_tix_date_start' AND pm_date.meta_value >= %s
             WHERE p.post_type = 'tix_ticket' AND p.post_status = 'publish'",
            $email,
            current_time('Y-m-d')
        )));

        $page_url = get_permalink();
        ?>
        <div class="tix-account-dashboard">
            <h2>Willkommen, <?php echo esc_html($user->first_name ?: $user->display_name); ?>!</h2>
            <p class="tix-account-subtitle">Hier ist deine Konto-Übersicht.</p>

            <div class="tix-account-stats">
                <a href="<?php echo esc_url(add_query_arg('tix_tab', 'tickets', $page_url)); ?>" class="tix-account-stat">
                    <span class="dashicons dashicons-tickets-alt"></span>
                    <span class="tix-account-stat-value"><?php echo $ticket_count; ?></span>
                    <span class="tix-account-stat-label">Tickets</span>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tix_tab', 'orders', $page_url)); ?>" class="tix-account-stat">
                    <span class="dashicons dashicons-cart"></span>
                    <span class="tix-account-stat-value"><?php echo $order_count; ?></span>
                    <span class="tix-account-stat-label">Bestellungen</span>
                </a>
                <div class="tix-account-stat">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <span class="tix-account-stat-value"><?php echo $upcoming; ?></span>
                    <span class="tix-account-stat-label">Kommende Events</span>
                </div>
            </div>

            <div class="tix-account-quick-links">
                <h3>Schnellzugriff</h3>
                <div class="tix-account-links-grid">
                    <a href="<?php echo esc_url(add_query_arg('tix_tab', 'tickets', $page_url)); ?>" class="tix-account-link">
                        <span class="dashicons dashicons-tickets-alt"></span> Meine Tickets ansehen
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('tix_tab', 'orders', $page_url)); ?>" class="tix-account-link">
                        <span class="dashicons dashicons-cart"></span> Meine Bestellungen
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('tix_tab', 'profile', $page_url)); ?>" class="tix-account-link">
                        <span class="dashicons dashicons-admin-users"></span> Profil bearbeiten
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('tix_tab', 'password', $page_url)); ?>" class="tix-account-link">
                        <span class="dashicons dashicons-lock"></span> Passwort ändern
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    // ══════════════════════════════════════
    // TAB: MEINE TICKETS
    // ══════════════════════════════════════

    private static function render_tickets() {
        if (class_exists('TIX_My_Tickets')) {
            echo TIX_My_Tickets::render();
        } else {
            echo '<p>Ticket-Ansicht ist nicht verfügbar.</p>';
        }
    }

    // ══════════════════════════════════════
    // TAB: MEINE BESTELLUNGEN
    // ══════════════════════════════════════

    private static function render_orders($user) {
        global $wpdb;
        $email = $user->user_email;

        // Native TIX_Orders + WC-Orders zusammen sammeln
        $orders = [];

        if (class_exists('TIX_Order')) {
            // Beide Quellen laden + dedupen (Customer-ID UND Email — z.B. Gast-Bestellung
            // mit später erstelltem Konto bleibt sonst unsichtbar)
            $native_by_id    = TIX_Order::query(['customer_id' => $user->ID, 'limit' => 50]);
            $native_by_email = TIX_Order::query(['email' => $email, 'limit' => 50]);
            $seen_ids = [];
            foreach (array_merge((array) $native_by_id, (array) $native_by_email) as $o) {
                $oid = $o->get_id();
                if (isset($seen_ids[$oid])) continue;
                $seen_ids[$oid] = true;
                $orders[] = self::map_native_order($o);
            }

            // Bonus: Gast-Bestellungen (customer_id=0) jetzt rückwirkend dem User zuordnen,
            // damit beim nächsten Aufruf nur eine Query nötig ist.
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE " . TIX_Order::table_name() . "
                 SET customer_id = %d
                 WHERE customer_id = 0 AND billing_email = %s",
                $user->ID, $email
            ));
        }

        if (function_exists('wc_get_orders')) {
            $wc_orders = wc_get_orders([
                'customer' => $user->ID,
                'limit'    => 50,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ]);
            foreach ((array) $wc_orders as $wo) {
                // Doppelung mit native (wc_order_id) ausschließen
                $skip = false;
                foreach ($orders as $existing) {
                    if (!empty($existing['wc_order_id']) && intval($existing['wc_order_id']) === $wo->get_id()) { $skip = true; break; }
                }
                if (!$skip) $orders[] = self::map_wc_order($wo);
            }
        }

        // Sort: neueste zuerst
        usort($orders, fn($a, $b) => strcmp($b['date_raw'], $a['date_raw']));
        ?>
        <h2>Meine Bestellungen</h2>
        <p class="tix-account-subtitle">Eine Übersicht über alle deine Bestellungen — abgeschlossene wie auch noch offene Zahlungen.</p>

        <?php if (empty($orders)): ?>
            <div class="tix-account-empty">
                <span class="dashicons dashicons-cart"></span>
                <p>Du hast noch keine Bestellungen aufgegeben.</p>
            </div>
        <?php else: ?>
            <div class="tix-account-orders">
                <?php foreach ($orders as $od): ?>
                    <div class="tix-account-order tix-account-order-<?php echo esc_attr($od['status']); ?>">
                        <div class="tix-account-order-head">
                            <div>
                                <span class="tix-account-order-number">Bestellung <?php echo esc_html($od['order_number']); ?></span>
                                <span class="tix-account-order-date"><?php echo esc_html($od['date_display']); ?></span>
                            </div>
                            <span class="tix-account-order-status tix-account-status-<?php echo esc_attr($od['status']); ?>">
                                <?php echo esc_html($od['status_label']); ?>
                            </span>
                        </div>

                        <?php if (!empty($od['items'])): ?>
                        <ul class="tix-account-order-items">
                            <?php foreach ($od['items'] as $it): ?>
                                <li>
                                    <span class="tix-account-order-item-name"><?php echo esc_html($it['name']); ?></span>
                                    <span class="tix-account-order-item-qty">×&nbsp;<?php echo intval($it['qty']); ?></span>
                                    <span class="tix-account-order-item-price"><?php echo esc_html($it['price']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <div class="tix-account-order-total">
                            <span>Gesamt</span>
                            <strong><?php echo esc_html($od['total_formatted']); ?></strong>
                        </div>

                        <?php // Bei pending/on-hold + Banküberweisung: Bankdaten zeigen ?>
                        <?php
                        $needs_payment = in_array($od['status'], ['pending', 'on-hold'], true);
                        $is_bank = in_array($od['payment_method_id'] ?? '', ['bacs', 'bank'], true);
                        if ($needs_payment && $is_bank):
                            $bacs = class_exists('TIX_My_Tickets') ? TIX_My_Tickets::get_bacs_details() : [];
                            $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
                        ?>
                            <div class="tix-account-pay-box">
                                <div class="tix-account-pay-heading">⏳ Zahlung ausstehend — bitte überweise:</div>
                                <table class="tix-account-pay-table">
                                    <?php foreach ($bacs as $acc): ?>
                                        <?php if (!empty($acc['account_name'])): ?><tr><td>Kontoinhaber</td><td><?php echo esc_html($acc['account_name']); ?></td></tr><?php endif; ?>
                                        <?php if (!empty($acc['iban'])):         ?><tr><td>IBAN</td>        <td><?php echo esc_html($acc['iban']); ?></td></tr><?php endif; ?>
                                        <?php if (!empty($acc['bic'])):          ?><tr><td>BIC</td>         <td><?php echo esc_html($acc['bic']); ?></td></tr><?php endif; ?>
                                        <?php if (!empty($acc['bank_name'])):    ?><tr><td>Bank</td>        <td><?php echo esc_html($acc['bank_name']); ?></td></tr><?php endif; ?>
                                    <?php endforeach; ?>
                                    <tr><td>Betrag</td><td><?php echo esc_html($od['total_formatted']); ?></td></tr>
                                    <tr><td>Verwendungszweck</td><td><?php echo esc_html($od['order_number']); ?></td></tr>
                                </table>
                                <?php if (!empty($s['bank_reference'])): ?>
                                    <p class="tix-account-pay-hint"><?php echo esc_html($s['bank_reference']); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($needs_payment): ?>
                            <div class="tix-account-pay-box">
                                <div class="tix-account-pay-heading">⏳ Zahlung wird verarbeitet</div>
                                <p class="tix-account-pay-hint">Sobald die Zahlung eingegangen ist, werden deine Tickets automatisch freigeschaltet und du erhältst eine Bestätigungs-E-Mail.</p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($od['view_tickets_url'])): ?>
                            <a href="<?php echo esc_url($od['view_tickets_url']); ?>" class="tix-account-order-link">
                                <span class="dashicons dashicons-tickets-alt"></span> Tickets ansehen
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <style>
            .tix-account-empty { text-align:center; padding:40px 20px; color:#94a3b8; }
            .tix-account-empty .dashicons { font-size:48px; width:48px; height:48px; opacity:.4; }
            .tix-account-orders { display:flex; flex-direction:column; gap:14px; }
            .tix-account-order { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; }
            .tix-account-order-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:14px; }
            .tix-account-order-number { display:block; font-weight:700; font-size:15px; color:#0f172a; }
            .tix-account-order-date { display:block; font-size:12px; color:#94a3b8; margin-top:2px; }
            .tix-account-order-status { display:inline-block; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; flex-shrink:0; }
            .tix-account-status-completed, .tix-account-status-processing { background:#dcfce7; color:#166534; }
            .tix-account-status-pending,   .tix-account-status-on-hold     { background:#fef3c7; color:#92400e; }
            .tix-account-status-cancelled, .tix-account-status-failed,
            .tix-account-status-refunded                                   { background:#fee2e2; color:#991b1b; }
            .tix-account-order-items { list-style:none; padding:0; margin:0 0 12px; }
            .tix-account-order-items li { display:flex; gap:10px; padding:6px 0; font-size:14px; border-bottom:1px solid #f1f5f9; }
            .tix-account-order-items li:last-child { border-bottom:none; }
            .tix-account-order-item-name { flex:1; font-weight:500; }
            .tix-account-order-item-qty { color:#94a3b8; font-size:13px; }
            .tix-account-order-item-price { font-weight:600; min-width:80px; text-align:right; }
            .tix-account-order-total { display:flex; justify-content:space-between; padding:10px 0; border-top:2px solid #e5e7eb; margin-bottom:12px; font-size:15px; }
            .tix-account-order-total strong { font-size:17px; color:#0f172a; }
            .tix-account-pay-box { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px 16px; margin:12px 0; }
            .tix-account-pay-heading { font-weight:700; font-size:14px; color:#92400e; margin-bottom:10px; }
            .tix-account-pay-table { width:100%; font-size:13px; border-collapse:collapse; }
            .tix-account-pay-table td { padding:5px 0; vertical-align:top; }
            .tix-account-pay-table td:first-child { color:#92400e; padding-right:14px; white-space:nowrap; width:140px; }
            .tix-account-pay-table td:last-child { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-weight:600; color:#0f172a; word-break:break-word; }
            .tix-account-pay-hint { margin:10px 0 0; font-size:12px; color:#78350f; line-height:1.5; }
            .tix-account-order-link { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:#f1f5f9; border-radius:8px; text-decoration:none; color:#475569 !important; font-size:13px; font-weight:600; }
            .tix-account-order-link:hover { background:#e2e8f0; color:#0f172a !important; }
            .tix-account-order-link .dashicons { font-size:16px; width:16px; height:16px; }
            @media (max-width:540px) {
                .tix-account-pay-table td:first-child { width:auto; }
                .tix-account-order-items li { flex-wrap:wrap; }
            }
        </style>
        <?php
    }

    /**
     * Native TIX_Order in einheitliches Format mappen
     */
    private static function map_native_order($o) {
        $items_raw = method_exists($o, 'get_items') ? $o->get_items() : [];
        $items = [];
        foreach ($items_raw as $i) {
            $items[] = [
                'name'  => $i->name ?? 'Ticket',
                'qty'   => intval($i->quantity ?? 1),
                'price' => number_format(floatval($i->total ?? 0), 2, ',', '.') . ' €',
            ];
        }
        $page_url = get_permalink();
        $tickets_url = $page_url ? add_query_arg('tix_tab', 'tickets', $page_url) : '';
        $status      = $o->get_status();
        $status_lbl  = self::status_label($status);

        return [
            'order_number'      => $o->get_order_number(),
            'order_id'          => $o->get_id(),
            'wc_order_id'       => method_exists($o, 'get_wc_order_id') ? $o->get_wc_order_id() : 0,
            'date_raw'          => method_exists($o, 'get_date_created') && $o->get_date_created() ? $o->get_date_created()->format('Y-m-d H:i:s') : '',
            'date_display'      => method_exists($o, 'get_date_created') && $o->get_date_created() ? $o->get_date_created()->format('d.m.Y, H:i') . ' Uhr' : '',
            'total_formatted'   => method_exists($o, 'get_formatted_order_total') ? wp_strip_all_tags($o->get_formatted_order_total()) : (number_format(floatval($o->total ?? 0), 2, ',', '.') . ' €'),
            'status'            => $status,
            'status_label'      => $status_lbl,
            'payment_method_id' => $o->get_payment_method() ?? '',
            'items'             => $items,
            'view_tickets_url'  => $tickets_url,
        ];
    }

    /**
     * WooCommerce-Order in einheitliches Format mappen
     */
    private static function map_wc_order($wo) {
        $items_raw = $wo->get_items();
        $items = [];
        foreach ($items_raw as $i) {
            $items[] = [
                'name'  => $i->get_name(),
                'qty'   => $i->get_quantity(),
                'price' => wp_strip_all_tags(wc_price($i->get_total())),
            ];
        }
        $status = $wo->get_status();
        $page_url = get_permalink();
        $tickets_url = $page_url ? add_query_arg('tix_tab', 'tickets', $page_url) : '';

        return [
            'order_number'      => $wo->get_order_number(),
            'order_id'          => $wo->get_id(),
            'wc_order_id'       => $wo->get_id(),
            'date_raw'          => $wo->get_date_created() ? $wo->get_date_created()->format('Y-m-d H:i:s') : '',
            'date_display'      => $wo->get_date_created() ? $wo->get_date_created()->format('d.m.Y, H:i') . ' Uhr' : '',
            'total_formatted'   => wp_strip_all_tags($wo->get_formatted_order_total()),
            'status'            => $status,
            'status_label'      => self::status_label($status),
            'payment_method_id' => $wo->get_payment_method(),
            'items'             => $items,
            'view_tickets_url'  => $tickets_url,
        ];
    }

    private static function status_label($status) {
        $map = [
            'completed'  => 'Abgeschlossen',
            'processing' => 'In Bearbeitung',
            'pending'    => 'Zahlung ausstehend',
            'on-hold'    => 'Wartet auf Zahlung',
            'cancelled'  => 'Storniert',
            'failed'     => 'Fehlgeschlagen',
            'refunded'   => 'Erstattet',
        ];
        return $map[$status] ?? ucfirst($status);
    }

    // ══════════════════════════════════════
    // TAB: PROFIL
    // ══════════════════════════════════════

    private static function render_profile($user) {
        $phone = get_user_meta($user->ID, '_tix_phone', true);
        ?>
        <h2>Profil bearbeiten</h2>
        <p class="tix-account-subtitle">Aktualisiere deine persönlichen Daten.</p>

        <form class="tix-account-form" method="post">
            <input type="hidden" name="action" value="tix_account_update_profile">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('tix_account_nonce'); ?>">

            <div class="tix-account-notice" style="display:none;"></div>

            <div class="tix-account-form-row">
                <div class="tix-account-field">
                    <label for="tix_first_name">Vorname</label>
                    <input type="text" id="tix_first_name" name="first_name" value="<?php echo esc_attr($user->first_name); ?>" required>
                </div>
                <div class="tix-account-field">
                    <label for="tix_last_name">Nachname</label>
                    <input type="text" id="tix_last_name" name="last_name" value="<?php echo esc_attr($user->last_name); ?>" required>
                </div>
            </div>

            <div class="tix-account-field">
                <label for="tix_email">E-Mail-Adresse</label>
                <input type="email" id="tix_email" value="<?php echo esc_attr($user->user_email); ?>" disabled>
                <span class="tix-account-field-hint">Die E-Mail-Adresse kann nicht geändert werden.</span>
            </div>

            <div class="tix-account-field">
                <label for="tix_phone">Telefonnummer</label>
                <input type="tel" id="tix_phone" name="phone" value="<?php echo esc_attr($phone); ?>" placeholder="+49 ...">
            </div>

            <div class="tix-account-field">
                <label for="tix_display_name">Anzeigename</label>
                <input type="text" id="tix_display_name" name="display_name" value="<?php echo esc_attr($user->display_name); ?>">
            </div>

            <button type="submit" class="tix-account-btn">Profil speichern</button>
        </form>
        <?php
    }

    // ══════════════════════════════════════
    // TAB: PASSWORT
    // ══════════════════════════════════════

    private static function render_password() {
        ?>
        <h2>Passwort ändern</h2>
        <p class="tix-account-subtitle">Wähle ein sicheres Passwort mit mindestens 8 Zeichen.</p>

        <form class="tix-account-form" method="post">
            <input type="hidden" name="action" value="tix_account_change_password">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('tix_account_nonce'); ?>">

            <div class="tix-account-notice" style="display:none;"></div>

            <div class="tix-account-field">
                <label for="tix_current_pw">Aktuelles Passwort</label>
                <input type="password" id="tix_current_pw" name="current_password" required autocomplete="current-password">
            </div>

            <div class="tix-account-form-row">
                <div class="tix-account-field">
                    <label for="tix_new_pw">Neues Passwort</label>
                    <input type="password" id="tix_new_pw" name="new_password" required minlength="8" autocomplete="new-password">
                </div>
                <div class="tix-account-field">
                    <label for="tix_confirm_pw">Passwort bestätigen</label>
                    <input type="password" id="tix_confirm_pw" name="confirm_password" required minlength="8" autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="tix-account-btn">Passwort ändern</button>
        </form>
        <?php
    }

    // ══════════════════════════════════════
    // RENDER: DATENSCHUTZ (DSGVO)
    // ══════════════════════════════════════

    private static function render_privacy($user) {
        ?>
        <h2>Datenschutz &amp; DSGVO</h2>
        <p class="tix-account-subtitle">Du hast das Recht auf Auskunft und Löschung deiner persönlichen Daten (DSGVO Art. 15, 17, 20).</p>

        <div class="tix-account-privacy-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:16px;">
            <h3 style="margin:0 0 6px;font-size:17px;">
                <span class="dashicons dashicons-download" style="color:#2563eb;vertical-align:middle;"></span>
                Meine Daten exportieren
            </h3>
            <p style="color:#64748b;font-size:14px;line-height:1.6;margin:0 0 16px;">
                Lade alle deine persönlichen Daten in einem maschinenlesbaren Format (JSON) herunter: Profil, Bestellungen, Tickets, Support-Anfragen, gespeicherte Events, Newsletter-Abos.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="tix_account_export_data">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('tix_account_privacy'); ?>">
                <button type="submit" class="tix-account-btn" style="background:#2563eb;">
                    <span class="dashicons dashicons-download" style="margin-top:4px;"></span>
                    Daten herunterladen (.json)
                </button>
            </form>
        </div>

        <div class="tix-account-privacy-card" style="background:#fff;border:1px solid #fecaca;border-radius:12px;padding:24px;">
            <h3 style="margin:0 0 6px;font-size:17px;color:#991b1b;">
                <span class="dashicons dashicons-trash" style="color:#dc2626;vertical-align:middle;"></span>
                Konto löschen
            </h3>
            <p style="color:#64748b;font-size:14px;line-height:1.6;margin:0 0 16px;">
                Dein Konto und alle persönlichen Daten werden <strong>unwiderruflich gelöscht</strong>. Vorhandene Bestellungen bleiben aus buchhalterischen Gründen als anonymisierte Datensätze erhalten (Name + E-Mail werden entfernt, Bestell-Summen bleiben für die Steuerprüfung bestehen — DSGVO Art. 17 Abs. 3 lit. b).
            </p>
            <p style="color:#991b1b;font-size:13px;font-weight:600;margin:0 0 16px;">
                ⚠ Bereits gekaufte Tickets für zukünftige Events bleiben nutzbar (sind an die Ticket-Codes gebunden), aber du verlierst den Zugriff über dein Konto.
            </p>

            <form id="tix-acc-delete-form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="tix_account_delete">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('tix_account_privacy'); ?>">

                <div class="tix-account-field" style="margin-bottom:14px;">
                    <label>Zum Bestätigen: aktuelles Passwort eingeben</label>
                    <input type="password" name="current_password" required autocomplete="current-password"
                           style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                </div>
                <div class="tix-account-field" style="margin-bottom:14px;">
                    <label>Zum Bestätigen tippe: <code>LÖSCHEN</code></label>
                    <input type="text" name="confirm_phrase" required pattern="^(LÖSCHEN|LOESCHEN)$"
                           placeholder="LÖSCHEN" autocomplete="off"
                           style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:monospace;">
                </div>

                <div class="tix-account-notice" style="display:none;"></div>

                <button type="submit" class="tix-account-btn" style="background:#dc2626;">
                    <span class="dashicons dashicons-trash" style="margin-top:4px;"></span>
                    Konto unwiderruflich löschen
                </button>
            </form>

            <script>
            (function(){
                const form = document.getElementById('tix-acc-delete-form');
                if (!form) return;
                form.addEventListener('submit', function(e) {
                    if (!confirm('Wirklich löschen? Diese Aktion ist endgültig.')) {
                        e.preventDefault();
                        return;
                    }
                    // Fallback AJAX-Submit (wenn das bestehende JS es nicht handled)
                    e.preventDefault();
                    const btn = form.querySelector('button[type="submit"]');
                    const notice = form.querySelector('.tix-account-notice');
                    btn.disabled = true; btn.textContent = 'Lösche…';
                    const fd = new FormData(form);
                    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
                      .then(r => r.json())
                      .then(d => {
                          if (d && d.success) {
                              notice.style.display = 'block';
                              notice.style.cssText += 'background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:10px 14px;border-radius:8px;';
                              notice.textContent = d.data?.message || 'Konto wurde gelöscht.';
                              setTimeout(() => {
                                  window.location.href = d.data?.redirect || '<?php echo esc_url(home_url('/')); ?>';
                              }, 1500);
                          } else {
                              btn.disabled = false;
                              btn.innerHTML = '<span class="dashicons dashicons-trash" style="margin-top:4px;"></span> Konto unwiderruflich löschen';
                              notice.style.display = 'block';
                              notice.style.cssText += 'background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;';
                              notice.textContent = d?.data?.message || d?.data || 'Löschen fehlgeschlagen.';
                          }
                      })
                      .catch(err => {
                          btn.disabled = false;
                          btn.innerHTML = '<span class="dashicons dashicons-trash" style="margin-top:4px;"></span> Konto unwiderruflich löschen';
                          alert('Netzwerkfehler: ' + err.message);
                      });
                });
            })();
            </script>
        </div>
        <?php
    }

    // ══════════════════════════════════════
    // AJAX: PROFIL SPEICHERN
    // ══════════════════════════════════════

    public static function ajax_update_profile() {
        check_ajax_referer('tix_account_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error('Nicht eingeloggt.');

        $first_name   = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name    = sanitize_text_field($_POST['last_name'] ?? '');
        $phone        = sanitize_text_field($_POST['phone'] ?? '');
        $display_name = sanitize_text_field($_POST['display_name'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            wp_send_json_error('Vor- und Nachname sind Pflichtfelder.');
        }

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $display_name ?: "$first_name $last_name",
        ]);

        update_user_meta($user_id, '_tix_phone', $phone);

        wp_send_json_success('Profil wurde gespeichert.');
    }

    // ══════════════════════════════════════
    // AJAX: PASSWORT ÄNDERN
    // ══════════════════════════════════════

    public static function ajax_change_password() {
        check_ajax_referer('tix_account_nonce', 'nonce');

        $user = wp_get_current_user();
        if (!$user->ID) wp_send_json_error('Nicht eingeloggt.');

        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) {
            wp_send_json_error('Bitte alle Felder ausfüllen.');
        }

        if (!wp_check_password($current, $user->user_pass, $user->ID)) {
            wp_send_json_error('Das aktuelle Passwort ist falsch.');
        }

        if (strlen($new) < 8) {
            wp_send_json_error('Das neue Passwort muss mindestens 8 Zeichen lang sein.');
        }

        if ($new !== $confirm) {
            wp_send_json_error('Die Passwörter stimmen nicht überein.');
        }

        wp_set_password($new, $user->ID);

        // Session erhalten (wp_set_password zerstört alle Sessions)
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        wp_send_json_success('Passwort wurde geändert.');
    }

    // ══════════════════════════════════════
    // AJAX: DSGVO — DATEN EXPORTIEREN (Art. 15 / Art. 20)
    // Liefert JSON-Download mit allen personenbezogenen Daten
    // ══════════════════════════════════════

    public static function ajax_export_data() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tix_account_privacy')) {
            wp_die('Sicherheits-Token abgelaufen.');
        }
        $user = wp_get_current_user();
        if (!$user->ID) wp_die('Nicht eingeloggt.');

        $email = $user->user_email;
        global $wpdb;

        // ── WP-User + Meta ──
        $all_user_meta = get_user_meta($user->ID);
        $user_meta = [];
        foreach ($all_user_meta as $key => $values) {
            // Sensible interne Keys filtern
            if (in_array($key, ['session_tokens', 'wp_capabilities', 'wp_user_level', 'default_password_nag'], true)) continue;
            $user_meta[$key] = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }

        $export = [
            '_meta' => [
                'exported_at'  => gmdate('c'),
                'site'         => home_url(),
                'legal_basis'  => 'DSGVO Art. 15 (Auskunftsrecht) / Art. 20 (Datenportabilität)',
                'format'       => 'JSON',
            ],
            'account' => [
                'user_id'        => $user->ID,
                'user_login'     => $user->user_login,
                'user_email'     => $user->user_email,
                'first_name'     => $user->first_name,
                'last_name'      => $user->last_name,
                'display_name'   => $user->display_name,
                'user_registered'=> $user->user_registered,
                'roles'          => $user->roles,
            ],
            'user_meta' => $user_meta,
        ];

        // ── Bestellungen (native) ──
        $export['orders_native'] = [];
        $t = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $ti = $wpdb->prefix . 'tix_order_items';
            $orders = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t WHERE billing_email = %s OR customer_id = %d ORDER BY date_created DESC",
                $email, $user->ID
            ), ARRAY_A);
            foreach ($orders as $o) {
                $o['items'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM $ti WHERE order_id = %d", $o['id']), ARRAY_A);
                $o['attribution_source'] = get_post_meta($o['id'], '_tix_ol_source', true);
                $export['orders_native'][] = $o;
            }
        }

        // ── Bestellungen (WC HPOS) ──
        $export['orders_wc'] = [];
        $wc_t = $wpdb->prefix . 'wc_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t) {
            $wc_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT o.* FROM $wc_t o
                 LEFT JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'
                 WHERE o.billing_email = %s OR a.email = %s OR o.customer_id = %d
                 ORDER BY o.date_created_gmt DESC",
                $email, $email, $user->ID
            ), ARRAY_A);
            foreach ($wc_rows as $r) {
                $export['orders_wc'][] = $r;
            }
        }

        // ── Tickets ──
        $export['tickets'] = [];
        $tickets = get_posts([
            'post_type'      => 'tix_ticket',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_tix_ticket_owner_email', 'value' => $email],
                ['key' => '_tix_ticket_transfer_to', 'value' => (string) $user->ID],
            ],
        ]);
        foreach ($tickets as $tk) {
            $export['tickets'][] = [
                'id'              => $tk->ID,
                'code'            => get_post_meta($tk->ID, '_tix_ticket_code', true),
                'event_id'        => get_post_meta($tk->ID, '_tix_ticket_event_id', true),
                'event_title'     => get_the_title(intval(get_post_meta($tk->ID, '_tix_ticket_event_id', true))),
                'status'          => get_post_meta($tk->ID, '_tix_ticket_status', true),
                'price'           => get_post_meta($tk->ID, '_tix_ticket_price', true),
                'checkin_time'    => get_post_meta($tk->ID, '_tix_ticket_checkin_time', true),
                'owner_name'      => get_post_meta($tk->ID, '_tix_ticket_owner_name', true),
                'owner_email'     => get_post_meta($tk->ID, '_tix_ticket_owner_email', true),
                'order_id'        => get_post_meta($tk->ID, '_tix_ticket_order_id', true),
                'purchase_date'   => $tk->post_date,
            ];
        }

        // ── Support-Tickets ──
        $export['support_tickets'] = [];
        $sp = get_posts([
            'post_type'      => 'tix_support_ticket',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_key'       => '_tix_sp_email',
            'meta_value'     => $email,
        ]);
        foreach ($sp as $t) {
            $export['support_tickets'][] = [
                'id'        => $t->ID,
                'subject'   => $t->post_title,
                'status'    => $t->post_status,
                'created'   => $t->post_date,
                'category'  => get_post_meta($t->ID, '_tix_sp_category', true),
                'priority'  => get_post_meta($t->ID, '_tix_sp_priority', true),
                'messages'  => class_exists('TIX_Support') ? TIX_Support::get_messages_public($t->ID) : get_post_meta($t->ID, '_tix_sp_messages', true),
            ];
        }

        // ── Newsletter-Abonnement ──
        $export['newsletter'] = [];
        $subs = get_posts([
            'post_type'      => 'tix_subscriber',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_key'       => '_tix_sub_email',
            'meta_value'     => $email,
        ]);
        foreach ($subs as $s) {
            $export['newsletter'][] = [
                'id'     => $s->ID,
                'email'  => get_post_meta($s->ID, '_tix_sub_email', true),
                'name'   => get_post_meta($s->ID, '_tix_sub_name', true),
                'status' => $s->post_status,
                'date'   => $s->post_date,
            ];
        }

        // ── Gespeicherte Events ──
        $saved = get_user_meta($user->ID, '_tix_saved_events', true);
        $export['saved_events'] = [];
        if (is_array($saved)) {
            foreach ($saved as $eid) {
                $export['saved_events'][] = [
                    'id'         => $eid,
                    'title'      => get_the_title($eid),
                    'date_start' => get_post_meta($eid, '_tix_date_start', true),
                ];
            }
        }

        // ── JSON-Download ausliefern ──
        while (ob_get_level() > 0) ob_end_clean();
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="meine-daten-' . sanitize_file_name($user->user_login) . '-' . date('Y-m-d') . '.json"');
        echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ══════════════════════════════════════
    // AJAX: DSGVO — KONTO LÖSCHEN (Art. 17)
    // Anonymisiert Bestellungen, löscht User + Meta
    // ══════════════════════════════════════

    public static function ajax_delete_account() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tix_account_privacy')) {
            wp_send_json_error(['message' => 'Sicherheits-Token abgelaufen.']);
        }
        $user = wp_get_current_user();
        if (!$user->ID) wp_send_json_error(['message' => 'Nicht eingeloggt.']);

        // Admins dürfen sich nicht selbst über diese Route löschen (Sicherheit)
        if (in_array('administrator', (array) $user->roles, true)) {
            wp_send_json_error(['message' => 'Admin-Konten müssen über das WP-Backend gelöscht werden.']);
        }

        $current  = $_POST['current_password'] ?? '';
        $confirm  = trim($_POST['confirm_phrase'] ?? '');

        if (!in_array(mb_strtoupper($confirm), ['LÖSCHEN', 'LOESCHEN'], true)) {
            wp_send_json_error(['message' => 'Bitte tippe „LÖSCHEN" exakt so ein, um zu bestätigen.']);
        }

        if (empty($current) || !wp_check_password($current, $user->user_pass, $user->ID)) {
            wp_send_json_error(['message' => 'Passwort ist falsch.']);
        }

        $email   = $user->user_email;
        $user_id = $user->ID;

        global $wpdb;

        // Anonymisierungs-Marker (unique, aber nicht-rückführbar)
        $anon_hash  = substr(hash('sha256', $email . wp_generate_password(32, true)), 0, 12);
        $anon_email = 'gelöscht-' . $anon_hash . '@removed.invalid';
        $anon_name  = '[Gelöscht]';

        // ── 1. Native tix_orders: Billing anonymisieren, customer_id auf 0 ──
        $t = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $wpdb->update($t,
                [
                    'billing_email'      => $anon_email,
                    'billing_first_name' => $anon_name,
                    'billing_last_name'  => '',
                    'billing_phone'      => '',
                    'billing_address_1'  => '',
                    'billing_address_2'  => '',
                    'billing_city'       => '',
                    'billing_postcode'   => '',
                    'billing_company'    => '',
                    'customer_id'        => 0,
                ],
                ['billing_email' => $email]
            );
            // Zusätzlich via customer_id (falls Email abweicht)
            $wpdb->update($t,
                ['customer_id' => 0],
                ['customer_id' => $user_id]
            );
        }

        // ── 2. WC-Orders (HPOS): Billing anonymisieren ──
        $wc_t = $wpdb->prefix . 'wc_orders';
        $wc_a = $wpdb->prefix . 'wc_order_addresses';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t) {
            $wc_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $wc_t WHERE billing_email = %s OR customer_id = %d", $email, $user_id));
            if (!empty($wc_ids)) {
                $wpdb->update($wc_t,
                    ['billing_email' => $anon_email, 'customer_id' => 0],
                    ['billing_email' => $email]
                );
                $wpdb->update($wc_t,
                    ['customer_id' => 0],
                    ['customer_id' => $user_id]
                );
                // Adressen auch leeren
                $placeholders = implode(',', array_fill(0, count($wc_ids), '%d'));
                $params = array_merge(
                    [$anon_email, $anon_name, '', '', '', '', '', '', ''],
                    array_map('intval', $wc_ids)
                );
                $wpdb->query($wpdb->prepare(
                    "UPDATE $wc_a SET email=%s, first_name=%s, last_name=%s, phone=%s, address_1=%s, address_2=%s, city=%s, postcode=%s, state=%s
                     WHERE address_type='billing' AND order_id IN ($placeholders)",
                    ...$params
                ));
            }
        }

        // ── 3. Tickets: Owner-Meta anonymisieren (Ticket-Codes bleiben gültig) ──
        $tickets = get_posts([
            'post_type'      => 'tix_ticket',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [['key' => '_tix_ticket_owner_email', 'value' => $email]],
        ]);
        foreach ($tickets as $tk) {
            update_post_meta($tk->ID, '_tix_ticket_owner_email', $anon_email);
            update_post_meta($tk->ID, '_tix_ticket_owner_name', $anon_name);
        }

        // ── 4. Support-Tickets: Email + Name anonymisieren ──
        $sp_posts = get_posts([
            'post_type'      => 'tix_support_ticket',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_key'       => '_tix_sp_email',
            'meta_value'     => $email,
        ]);
        foreach ($sp_posts as $s) {
            update_post_meta($s->ID, '_tix_sp_email', $anon_email);
            update_post_meta($s->ID, '_tix_sp_name', $anon_name);
        }

        // ── 5. Newsletter-Abo komplett löschen (kein berechtigtes Interesse) ──
        $subs = get_posts([
            'post_type'      => 'tix_subscriber',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_key'       => '_tix_sub_email',
            'meta_value'     => $email,
        ]);
        foreach ($subs as $s) { wp_delete_post($s->ID, true); }

        // ── 6. WP-User löschen (inkl. aller User-Meta) ──
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id); // reassign = null → Posts werden gelöscht wenn vorhanden

        // ── 7. Log (als Option, nicht an User gebunden) ──
        $log = get_option('tix_deleted_accounts_log', []);
        if (!is_array($log)) $log = [];
        $log[] = [
            'deleted_at' => gmdate('c'),
            'anon_hash'  => $anon_hash,
            'tickets'    => count($tickets),
            'support'    => count($sp_posts),
        ];
        if (count($log) > 500) $log = array_slice($log, -500); // Cap
        update_option('tix_deleted_accounts_log', $log, false);

        // Session zerstören
        wp_clear_auth_cookie();

        wp_send_json_success([
            'message'  => 'Dein Konto wurde gelöscht. Du wirst gleich abgemeldet.',
            'redirect' => home_url('/'),
        ]);
    }
}
