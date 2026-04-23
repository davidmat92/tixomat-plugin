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
                'messages'  => get_post_meta($t->ID, '_tix_sp_messages', true),
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
