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
        'profile'   => ['label' => 'Profil bearbeiten',  'icon' => 'dashicons-admin-users'],
        'password'  => ['label' => 'Passwort ändern',    'icon' => 'dashicons-lock'],
        'logout'    => ['label' => 'Abmelden',           'icon' => 'dashicons-exit'],
    ];

    public static function init() {
        add_shortcode('tix_account', [__CLASS__, 'render']);
        add_action('wp_ajax_tix_account_update_profile', [__CLASS__, 'ajax_update_profile']);
        add_action('wp_ajax_tix_account_change_password', [__CLASS__, 'ajax_change_password']);
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
                    case 'profile':
                        self::render_profile($user);
                        break;
                    case 'password':
                        self::render_password();
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
                <div class="tix-account-stat">
                    <span class="dashicons dashicons-cart"></span>
                    <span class="tix-account-stat-value"><?php echo $order_count; ?></span>
                    <span class="tix-account-stat-label">Bestellungen</span>
                </div>
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
}
