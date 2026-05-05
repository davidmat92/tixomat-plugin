<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat - Meine Tickets
 *
 * Shortcode: [tix_my_tickets]
 * Zeigt dem eingeloggten Kunden seine Bestellungen
 * mit Ticket-Downloads, Download-Links und Event-Infos.
 */
class TIX_My_Tickets {

    /** Preisformatierung – Fallback wenn WooCommerce nicht aktiv. */
    private static function format_price($price) {
        if (function_exists('wc_price')) {
            return wc_price($price);
        }
        return number_format((float) $price, 2, ',', '.') . '&nbsp;&euro;';
    }

    public static function init() {
        add_shortcode('tix_my_tickets', [__CLASS__, 'render']);
        add_shortcode('tix_order_history', [__CLASS__, 'render_order_history']);

        // Guest-Resend: Gast gibt Email ein → bekommt alle Tickets erneut zugeschickt
        add_action('wp_ajax_nopriv_tix_mt_guest_resend', [__CLASS__, 'ajax_guest_resend']);
        add_action('wp_ajax_tix_mt_guest_resend',        [__CLASS__, 'ajax_guest_resend']);
    }

    /**
     * Assets laden (nur wenn Shortcode genutzt wird)
     */
    public static function enqueue() {
        wp_enqueue_style(
            'tix-my-tickets',
            TIXOMAT_URL . 'assets/css/my-tickets.css',
            [],
            TIXOMAT_VERSION
        );
        wp_enqueue_script(
            'tix-qrcode-generator',
            TIXOMAT_URL . 'assets/js/qrcode-generator.js',
            [],
            TIXOMAT_VERSION,
            true
        );
        wp_enqueue_script(
            'tix-qr',
            TIXOMAT_URL . 'assets/js/tix-qr.js',
            ['tix-qrcode-generator'],
            TIXOMAT_VERSION,
            true
        );
        wp_enqueue_script(
            'tix-ticket-img',
            TIXOMAT_URL . 'assets/js/tix-ticket-img.js',
            [],
            TIXOMAT_VERSION,
            true
        );
        wp_enqueue_script(
            'tix-wallet',
            TIXOMAT_URL . 'assets/js/tix-wallet.js',
            [],
            TIXOMAT_VERSION,
            true
        );

        // Guest-Resend-Config (nur relevant für nicht-eingeloggte — aber harmlos zu localizen)
        wp_localize_script('tix-qr', 'tixMyTickets', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tix_mt_guest_resend'),
        ]);
    }

    /**
     * Shortcode rendern
     *
     * Drei Modi:
     *   1. Eingeloggt     → volle Ticket-Übersicht via $user_id
     *   2. Gast-Token     → ?tix_mt_token=XXX → Ticket-Übersicht via Billing-Email
     *   3. Sonst          → Login-Formular + Guest-Magic-Link-Formular
     */
    public static function render($atts = []) {
        self::enqueue();

        $user_id  = 0;
        $email    = '';
        $is_guest = false;

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $u = get_userdata($user_id);
            $email = $u ? $u->user_email : '';
        } else {
            // Magic-Link-Flow (per Email)
            $token = isset($_GET['tix_mt_token']) ? sanitize_text_field(wp_unslash($_GET['tix_mt_token'])) : '';
            if ($token) {
                $verified_email = self::verify_guest_token($token);
                if ($verified_email) {
                    // Existiert ein User-Account für diese Email? → automatisch einloggen
                    $existing_user = get_user_by('email', $verified_email);
                    if ($existing_user) {
                        wp_set_current_user($existing_user->ID);
                        wp_set_auth_cookie($existing_user->ID, true);
                        // Reload Page ohne Token (sauberer URL + Auth-Cookie greift)
                        wp_safe_redirect(remove_query_arg('tix_mt_token'));
                        exit;
                    }
                    // Kein Account → Gast-Modus
                    $email = $verified_email;
                    $is_guest = true;
                } else {
                    return self::render_token_expired();
                }
            }

            if (!$is_guest) {
                return self::render_login();
            }
        }

        // -- Bestellungen laden (dual-source: WC + native, deduped) --
        $orders = self::load_orders($user_id, $email);

        // Auch wenn keine Orders: könnte transferred/admin-assigned Tickets geben.
        // Pre-check ob assigned/transferred Tickets vorhanden sind.
        $has_assigned_or_transferred = false;
        if ($user_id) {
            global $wpdb;
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'tix_ticket' AND p.post_status = 'publish'
                 WHERE (pm.meta_key = '_tix_ticket_assigned_user_id' AND pm.meta_value = %s)
                    OR (pm.meta_key = '_tix_ticket_transfer_to' AND pm.meta_value = %s)",
                (string) $user_id, (string) $user_id
            ));
            $has_assigned_or_transferred = $count > 0;
        }

        if (empty($orders) && !$has_assigned_or_transferred) {
            return self::render_empty($is_guest ? $email : '');
        }

        $debug = isset($_GET['tix_debug']);

        // -- Bestellungen mit Tickets aufbereiten --
        $now       = current_time('Y-m-d H:i');
        $upcoming  = [];
        $past      = [];
        $skipped   = [];

        foreach ($orders as $order) {
            $order_data = self::build_order_data($order);
            if (empty($order_data['events']) && empty($order_data['combos'])) {
                if ($debug) $skipped[] = '#' . $order->get_id() . ' (' . $order->get_status() . ', ' . count($order->get_items()) . ' items)';
                continue;
            }

            // Bestimmen: upcoming oder past (anhand fruehestem Event-Datum)
            $is_upcoming = false;
            // Reguläre Events prüfen
            foreach ($order_data['events'] as $ev) {
                if (!empty($ev['date_start_raw']) && $ev['date_start_raw'] >= current_time('Y-m-d')) {
                    $is_upcoming = true;
                    break;
                }
                if (empty($ev['date_start_raw'])) {
                    $is_upcoming = true;
                    break;
                }
            }
            // Kombi-Events prüfen
            if (!$is_upcoming) {
                foreach ($order_data['combos'] as $combo) {
                    foreach ($combo['sub_events'] as $se) {
                        if (!empty($se['date_start_raw']) && $se['date_start_raw'] >= current_time('Y-m-d')) {
                            $is_upcoming = true;
                            break 2;
                        }
                        if (empty($se['date_start_raw'])) {
                            $is_upcoming = true;
                            break 2;
                        }
                    }
                }
            }

            if ($is_upcoming) {
                $upcoming[] = $order_data;
            } else {
                $past[] = $order_data;
            }
        }

        // ── Transferierte / Admin-zugeordnete Tickets ──
        // Vereinigt zwei Quellen:
        //   1) Frontend-Transfer per "Inhaber ändern" → _tix_ticket_transfer_to + status=transferred
        //   2) Admin-Override via Ticket-Edit-Page → _tix_ticket_assigned_user_id
        $transferred_to_me = [];
        $tix_transferred   = [];
        $assigned_to_me    = [];

        if ($user_id) {
            // TIX-Tickets: Transfers über _tix_ticket_transfer_to
            $tix_transferred = get_posts([
                'post_type'      => 'tix_ticket',
                'posts_per_page' => -1,
                'meta_query'     => [
                    ['key' => '_tix_ticket_transfer_to', 'value' => (string) $user_id],
                    ['key' => '_tix_ticket_status', 'value' => 'transferred'],
                ],
                'post_status' => 'publish',
            ]);

            // Admin-zugeordnete Tickets (via Backend Ticket-Edit-Metabox)
            $assigned_to_me = get_posts([
                'post_type'      => 'tix_ticket',
                'posts_per_page' => -1,
                'meta_key'       => '_tix_ticket_assigned_user_id',
                'meta_value'     => (string) $user_id,
                'post_status'    => 'publish',
            ]);
        }

        $seen_ids = [];
        foreach (array_merge($tix_transferred, $assigned_to_me) as $et) {
            if (isset($seen_ids[$et->ID])) continue;
            $seen_ids[$et->ID] = true;

            // Skip wenn als 'transferred' markiert UND user nicht der Empfänger (Sicherheits-Check)
            $status = get_post_meta($et->ID, '_tix_ticket_status', true);
            if ($status === 'transferred') {
                $to = get_post_meta($et->ID, '_tix_ticket_transfer_to', true);
                if ((string) $to !== (string) $user_id) continue;
            }

            $transferred_to_me[] = [
                'id'           => $et->ID,
                'code'         => get_post_meta($et->ID, '_tix_ticket_code', true) ?: $et->post_title,
                'type_name'    => '',
                'event_id'     => intval(get_post_meta($et->ID, '_tix_ticket_event_id', true)),
                'download_url' => class_exists('TIX_Tickets') ? TIX_Tickets::get_download_url($et->ID) : '',
                'source'       => 'eh',
                'is_assigned'  => $status !== 'transferred', // unterscheidet Frontend-Transfer vs. Admin-Override
            ];
            // Kategorie-Name ermitteln (Override-Meta hat Vorrang)
            $cat_name_meta = get_post_meta($et->ID, '_tix_ticket_cat_name', true);
            if ($cat_name_meta) {
                $transferred_to_me[count($transferred_to_me) - 1]['type_name'] = $cat_name_meta;
            } else {
                $eid = intval(get_post_meta($et->ID, '_tix_ticket_event_id', true));
                $ci  = intval(get_post_meta($et->ID, '_tix_ticket_cat_index', true));
                $cats = get_post_meta($eid, '_tix_ticket_categories', true);
                if (is_array($cats) && isset($cats[$ci])) {
                    $transferred_to_me[count($transferred_to_me) - 1]['type_name'] = $cats[$ci]['name'] ?? 'Ticket';
                }
            }
        }

        if (!empty($transferred_to_me)) {
            // Tickets nach Event gruppieren
            $transfer_events = [];
            foreach ($transferred_to_me as $tt) {
                $tix_event_id = $tt['event_id'];
                $event_key   = $tix_event_id ?: 'unknown_' . $tt['id'];

                if (!isset($transfer_events[$event_key])) {
                    $ev_data    = self::get_event_display_data($tix_event_id);
                    $event_name = $tix_event_id ? get_the_title($tix_event_id) : 'Event';
                    $transfer_events[$event_key] = array_merge($ev_data, [
                        'event_name' => $event_name,
                        'event_id'   => $tix_event_id,
                        'items'      => [],
                        'tickets'    => [],
                    ]);
                }

                $transfer_events[$event_key]['tickets'][] = [
                    'id'           => $tt['id'],
                    'code'         => $tt['code'],
                    'type_name'    => $tt['type_name'],
                    'download_url' => $tt['download_url'],
                ];
            }

            // Frühestes Transfer-Datum ermitteln
            $first = $transferred_to_me[0];
            if ($first['source'] === 'eh') {
                $earliest_transfer = get_post_meta($first['id'], '_tix_ticket_transfer_date', true);
                $transfer_name     = get_post_meta($first['id'], '_tix_ticket_owner_name', true);
            } else {
                $earliest_transfer = get_post_meta($first['id'], '_tix_transfer_date', true);
                $transfer_name     = get_post_meta($first['id'], '_tix_transfer_name', true);
            }
            $user_data = get_userdata($user_id);

            $transfer_od = [
                'order_id'              => 0,
                'order_date'            => $earliest_transfer ? date_i18n('d.m.Y', strtotime($earliest_transfer)) : '',
                'status'                => 'completed',
                'status_label'          => 'Umgeschrieben',
                'total'                 => 0,
                'currency'              => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
                'events'                => $transfer_events,
                'combos'                => [],
                'payment_method'        => '',
                'payment_method_id'     => '',
                'order_total_formatted' => '',
                'buyer_name'            => $transfer_name ?: '',
                'buyer_email'           => $user_data ? $user_data->user_email : '',
                'is_transfer'           => true,
            ];

            // Upcoming vs Past bestimmen
            $is_upcoming = false;
            foreach ($transfer_events as $ev) {
                if (!empty($ev['date_start_raw']) && $ev['date_start_raw'] >= current_time('Y-m-d')) {
                    $is_upcoming = true;
                    break;
                }
                if (empty($ev['date_start_raw'])) {
                    $is_upcoming = true;
                    break;
                }
            }

            if ($is_upcoming) {
                $upcoming[] = $transfer_od;
            } else {
                $past[] = $transfer_od;
            }
        }

        // Falls alle Bestellungen ohne Ticket-Produkte: leerer State
        if (empty($upcoming) && empty($past)) {
            return self::render_empty($is_guest ? $email : '');
        }

        ob_start();
        if ($debug) {
            echo '<!-- TIX_TICKETS_DEBUG total_orders=' . count($orders)
               . ' upcoming=' . count($upcoming) . ' past=' . count($past)
               . ' skipped=[' . implode(', ', $skipped) . ']'
               . ' user=' . $user_id
               . ' guest=' . ($is_guest ? '1' : '0') . ' -->';
        }
        ?>
        <div class="tix-mt" id="tix-my-tickets">

            <?php if ($is_guest) echo self::render_guest_banner($email); ?>

            <?php if (!empty($upcoming)): ?>
                <div class="tix-mt-section">
                    <h3 class="tix-mt-section-title">Kommende Events</h3>
                    <?php foreach ($upcoming as $od): ?>
                        <?php self::render_order_card($od, false); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($past)): ?>
                <div class="tix-mt-section">
                    <h3 class="tix-mt-section-title">Vergangene Events</h3>
                    <?php foreach ($past as $od): ?>
                        <?php self::render_order_card($od, true); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
        <script>
        (function(){
            var wrap = document.getElementById('tix-my-tickets');
            if (!wrap) return;
            wrap.addEventListener('click', function(e) {
                var btn = e.target.closest('.tix-mt-card-toggle');
                if (!btn) return;
                if (e.target.closest('a')) return;
                var expanded = btn.getAttribute('aria-expanded') === 'true';
                var panelId = btn.getAttribute('aria-controls');
                var panel = document.getElementById(panelId);
                if (!panel) return;
                if (expanded) {
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    panel.offsetHeight;
                    panel.style.maxHeight = '0';
                    panel.classList.remove('tix-mt-card-open');
                    btn.setAttribute('aria-expanded', 'false');
                    setTimeout(function(){ panel.setAttribute('hidden', ''); panel.style.maxHeight = ''; }, 350);
                } else {
                    panel.removeAttribute('hidden');
                    panel.classList.add('tix-mt-card-open');
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    btn.setAttribute('aria-expanded', 'true');
                    setTimeout(function(){ panel.style.maxHeight = 'none'; }, 350);
                    if (window.ehQR) {
                        var cvs = panel.querySelectorAll('canvas.tix-mt-qr-canvas[data-qr]');
                        for (var i = 0; i < cvs.length; i++) window.ehQR.render(cvs[i]);
                    }
                }
            });
            var op = wrap.querySelectorAll('.tix-mt-card-open');
            for (var i = 0; i < op.length; i++) op[i].style.maxHeight = 'none';
        })();
        </script>
        <?php echo tix_branding_footer(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Login-Ansicht — mit zusätzlicher Guest-Resend-Option
     * (Gast gibt Email ein → System sendet alle Tickets der zugehörigen Bestellungen nochmal zu)
     */
    private static function render_login() {
        ob_start();
        ?>
        <div class="tix-mt" id="tix-my-tickets">
            <div class="tix-mt-login">
                <div class="tix-mt-login-icon">&#128274;</div>
                <h2 class="tix-mt-login-title">Melde dich an</h2>
                <p class="tix-mt-login-text">Um deine Tickets zu sehen, melde dich mit deinem Konto an.</p>
                <?php
                // Hinweis auf Subdomain: evendis.de-Konto gilt auch hier
                if (defined('TIX_ON_ORG_SUBDOMAIN') && defined('TIX_LANDING_PARENT_HOST')):
                    $parent = TIX_LANDING_PARENT_HOST;
                ?>
                    <p class="tix-mt-login-hint" style="font-size:12px;color:#64748b;margin:0 0 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:8px 12px;">
                        💡 Dein <strong><?php echo esc_html($parent); ?></strong>-Konto gilt hier ebenfalls — einfach mit denselben Zugangsdaten anmelden.
                    </p>
                <?php endif; ?>
                <?php
                wp_login_form([
                    'redirect'       => get_permalink(),
                    'form_id'        => 'tix-mt-login-form',
                    'label_username' => 'E-Mail oder Benutzername',
                    'label_password' => 'Passwort',
                    'label_remember' => 'Angemeldet bleiben',
                    'label_log_in'   => 'Anmelden',
                ]);
                ?>
                <?php if (get_option('users_can_register')): ?>
                    <p class="tix-mt-login-register">
                        Noch kein Konto? <a href="<?php echo esc_url(wp_registration_url()); ?>">Jetzt registrieren</a>
                    </p>
                <?php endif; ?>

                <div class="tix-mt-guest-divider" role="separator" aria-label="oder">
                    <span>oder</span>
                </div>

                <?php // Konsistenter Magic-Link-Block (3 Szenarien: Konto, Gast, nicht gefunden) ?>
                <?php echo self::render_magic_link_block([
                    'heading' => 'Tickets per E-Mail-Link',
                    'text'    => 'Hast du ohne Konto bestellt oder Passwort vergessen? Wir schicken dir einen einmaligen Link.',
                    'btn'     => 'Link senden',
                    'id'      => 'tix-magic-mtpage',
                ]); ?>
            </div>
        </div>

        <style>
            .tix-mt-guest-divider {
                display: flex;
                align-items: center;
                gap: 12px;
                margin: 28px 0 18px;
                color: #94a3b8;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.08em;
            }
            .tix-mt-guest-divider::before,
            .tix-mt-guest-divider::after {
                content: '';
                flex: 1;
                height: 1px;
                background: #e2e8f0;
            }
            .tix-mt-guest { text-align: left; }
            .tix-mt-guest-title {
                font-size: 16px;
                margin: 0 0 6px;
                font-weight: 600;
                color: #0f172a;
            }
            .tix-mt-guest-text {
                font-size: 13px;
                color: #64748b;
                margin: 0 0 14px;
                line-height: 1.5;
            }
            .tix-mt-guest-form {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .tix-mt-guest-form input[type="email"] {
                flex: 1;
                min-width: 200px;
                padding: 10px 14px;
                border: 1px solid #cbd5e1;
                border-radius: 10px;
                font-size: 14px;
                font-family: inherit;
                background: #fff;
            }
            .tix-mt-guest-form input[type="email"]:focus {
                outline: none;
                border-color: #6366f1;
                box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
            }
            .tix-mt-guest-btn {
                padding: 10px 18px;
                border: none;
                border-radius: 10px;
                background: #0f172a;
                color: #fff;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                font-family: inherit;
                transition: background 0.15s;
            }
            .tix-mt-guest-btn:hover { background: #1e293b; }
            .tix-mt-guest-btn:disabled {
                background: #94a3b8;
                cursor: not-allowed;
            }
            .tix-mt-guest-result {
                margin-top: 14px;
                padding: 12px 14px;
                border-radius: 10px;
                font-size: 13px;
                line-height: 1.5;
            }
            .tix-mt-guest-result.is-success {
                background: #ecfdf5;
                border: 1px solid #a7f3d0;
                color: #065f46;
            }
            .tix-mt-guest-result.is-error {
                background: #fef2f2;
                border: 1px solid #fecaca;
                color: #991b1b;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Guest-Magic-Link — Gast tippt Email ein → wir senden ihm einen einmaligen Link
     * zur /tickets/-Seite, über den er ohne Login alle Tickets sieht.
     *
     * Sicherheit:
     *  - Rate-Limit: 3 Versuche / 10 min / IP (verhindert Enumeration + Mail-Spam)
     *  - Antwort ist IMMER generisch (kein Leak ob Email existiert)
     *  - Link geht NUR an die eingetippte Email (die muss gleichzeitig Billing-Email einer Bestellung sein)
     *  - Token ist 64-Hex, 30 min TTL, in Transient gespeichert
     *  - Nonce-Check
     */
    public static function ajax_guest_resend() {
        // Nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tix_mt_guest_resend')) {
            wp_send_json_error(['message' => 'Sicherheits-Token abgelaufen. Seite neu laden.']);
        }

        // Rate-Limit: 3 Versuche / 10 min / IP
        if (class_exists('TIX_Rate_Limit')) {
            TIX_Rate_Limit::check('mt_guest_resend', 3, 600, 'ajax');
        }

        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse eingeben.']);
        }

        // Drei Szenarien differenzieren:
        // 1. Email hat Account → Magic-Link loggt automatisch in Account ein
        // 2. Email hat Bestellungen aber kein Account → Gast-Ansicht der Tickets
        // 3. Email gar nicht im System → klare Fehlermeldung mit Support-Hinweis
        $has_account = (bool) get_user_by('email', $email);
        $has_orders  = self::email_has_orders($email);

        if (!$has_account && !$has_orders) {
            // Szenario 3: nichts gefunden → Support-Hinweis
            $support_email = get_bloginfo('admin_email');
            wp_send_json_success([
                'scenario' => 'not_found',
                'email'    => $email,
                'support_email' => $support_email,
                'message'  => 'Wir konnten keine Bestellung oder Konto mit dieser E-Mail-Adresse finden.',
            ]);
        }

        // Token generieren + Magic-Link-Mail senden
        $token     = self::generate_guest_token($email);
        $landing   = self::get_tickets_page_url();
        $magic_url = add_query_arg('tix_mt_token', $token, $landing);
        self::send_magic_link_email($email, $magic_url);

        $scenario = $has_account ? 'has_account' : 'has_orders';
        wp_send_json_success([
            'scenario' => $scenario,
            'email'    => $email,
            'message'  => $has_account
                ? 'Wir haben dir einen Link geschickt mit dem du dich direkt in dein Konto einloggen kannst.'
                : 'Wir haben dir einen Link geschickt mit dem du deine Tickets ohne Konto ansehen kannst.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // GUEST-TOKEN: Generate, Verify, Email
    // ═══════════════════════════════════════════════════════════════

    /**
     * Erstellt einen neuen Guest-Token für die Email und speichert ihn als Transient (30 min).
     * Gibt den Klartext-Token zurück (wird in die URL geschrieben).
     */
    private static function generate_guest_token($email) {
        $token = bin2hex(random_bytes(32)); // 64-hex
        $key   = 'tix_mt_guest_' . hash('sha256', $token);
        set_transient($key, [
            'email'      => sanitize_email($email),
            'created_at' => time(),
        ], 30 * MINUTE_IN_SECONDS);
        return $token;
    }

    /**
     * Validiert einen Guest-Token und gibt die zugehörige Email zurück (oder null).
     */
    private static function verify_guest_token($token) {
        $token = preg_replace('/[^a-f0-9]/i', '', (string) $token);
        if (strlen($token) !== 64) return null;
        $key  = 'tix_mt_guest_' . hash('sha256', $token);
        $data = get_transient($key);
        if (!is_array($data) || empty($data['email'])) return null;
        return sanitize_email($data['email']) ?: null;
    }

    /**
     * Prüft ob es für diese Email überhaupt Bestellungen gibt (WC oder native).
     */
    private static function email_has_orders($email) {
        if (function_exists('wc_get_orders')) {
            $wc = wc_get_orders([
                'billing_email' => $email,
                'limit'         => 1,
                'status'        => ['wc-completed', 'wc-processing'],
                'return'        => 'ids',
            ]);
            if (!empty($wc)) return true;
        }
        if (class_exists('TIX_Order')) {
            $natives = TIX_Order::query([
                'email'  => $email,
                'status' => ['completed', 'processing'],
                'limit'  => 1,
            ]);
            if (!empty($natives)) return true;
        }
        return false;
    }

    /**
     * URL der Seite die [tix_my_tickets] enthält.
     * 1. Referer wenn Host passt (Kunde ist dort gelandet)
     * 2. Configurable tix_my_tickets_page_id option
     * 3. /tickets/ als default
     */
    /**
     * Standalone Magic-Link-Block — kann auf jeder Login-Seite eingebunden werden.
     * Rendert: Heading + Form + Status-Box + Modal + CSS + JS.
     * Modal/CSS/JS werden nur EINMAL pro Page-Render ausgegeben (static-flag).
     */
    public static function render_magic_link_block($atts = []) {
        $atts = wp_parse_args($atts, [
            'heading' => 'Login per E-Mail-Link',
            'text'    => 'Wir schicken dir einen einmaligen Link, mit dem du dich ohne Passwort einloggen kannst — perfekt wenn du dein Passwort vergessen hast oder ohne Konto bestellt hast.',
            'btn'     => 'Magic-Link senden',
            'id'      => 'tix-magic',
        ]);
        self::enqueue();
        ob_start();
        ?>
        <div class="tix-magic-block" id="<?php echo esc_attr($atts['id']); ?>-block">
            <h3 class="tix-magic-title"><?php echo esc_html($atts['heading']); ?></h3>
            <p class="tix-magic-text"><?php echo esc_html($atts['text']); ?></p>
            <form class="tix-magic-form" id="<?php echo esc_attr($atts['id']); ?>-form" method="post" autocomplete="on" novalidate>
                <input type="email" name="email" placeholder="deine@email.de" autocomplete="email" required class="tix-magic-input">
                <button type="submit" class="tix-magic-btn"><?php echo esc_html($atts['btn']); ?></button>
            </form>
            <div class="tix-magic-result" id="<?php echo esc_attr($atts['id']); ?>-result" hidden></div>
        </div>
        <?php self::render_magic_link_assets_once(); ?>
        <script>
        (function(){
            var form = document.getElementById('<?php echo esc_js($atts['id']); ?>-form');
            if (!form) return;
            // tixMyTickets sicher setzen falls noch nicht vorhanden
            if (!window.tixMyTickets) {
                window.tixMyTickets = <?php echo wp_json_encode([
                    'ajax'  => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('tix_mt_guest_resend'),
                ]); ?>;
            }
            var input = form.querySelector('input[name="email"]');
            var btn   = form.querySelector('button');
            var result= document.getElementById('<?php echo esc_js($atts['id']); ?>-result');

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var email = (input.value || '').trim();
                if (!email || email.indexOf('@') === -1) {
                    if (result) { result.textContent = 'Bitte eine gültige E-Mail-Adresse eingeben.'; result.hidden = false; result.className = 'tix-magic-result is-error'; }
                    return;
                }
                btn.disabled = true;
                var orig = btn.textContent; btn.textContent = 'Sende…';
                if (result) result.hidden = true;

                var body = new URLSearchParams();
                body.append('action', 'tix_mt_guest_resend');
                body.append('nonce', window.tixMyTickets.nonce);
                body.append('email', email);

                fetch(window.tixMyTickets.ajax, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body.toString()
                })
                .then(function(r){ return r.json(); })
                .then(function(json){
                    btn.disabled = false; btn.textContent = orig;
                    if (json && json.success) {
                        var data = json.data || {};
                        var scenario = data.scenario || 'has_orders';
                        if (typeof window.tixMagicShowModal === 'function') {
                            window.tixMagicShowModal(scenario, email, data.support_email || '');
                        } else {
                            alert(data.message || 'E-Mail mit Link gesendet.');
                        }
                        // Input nur leeren wenn Mail gefunden — bei not_found behalten damit user die Adresse sieht
                        if (scenario !== 'not_found') input.value = '';
                    } else {
                        var msg = (json && json.data && json.data.message) || 'Fehler. Bitte später erneut versuchen.';
                        if (result) { result.textContent = msg; result.hidden = false; result.className = 'tix-magic-result is-error'; }
                    }
                })
                .catch(function(){
                    btn.disabled = false; btn.textContent = orig;
                    if (result) { result.textContent = 'Netzwerkfehler. Bitte erneut versuchen.'; result.hidden = false; result.className = 'tix-magic-result is-error'; }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Modal + CSS + Modal-JS — wird nur ein Mal pro Page-Render ausgegeben.
     */
    private static function render_magic_link_assets_once() {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
        <div class="tix-magic-modal" id="tix-magic-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tix-magic-modal-title">
            <div class="tix-magic-modal-backdrop" data-magic-close></div>
            <div class="tix-magic-modal-content">
                <button type="button" class="tix-magic-modal-x" aria-label="Schließen" data-magic-close>&times;</button>

                <?php // Variante: Account vorhanden — direktes Login ?>
                <div class="tix-magic-modal-variant" data-variant="has_account" hidden>
                    <div class="tix-magic-modal-icon" style="background:#dcfce7;color:#16a34a;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                    </div>
                    <h2 class="tix-magic-modal-title">Konto gefunden — Link gesendet</h2>
                    <p class="tix-magic-modal-text">
                        Wir haben dir einen Link an <strong class="tix-magic-modal-email"></strong> geschickt. Klick darauf, um dich automatisch in dein Konto einzuloggen — ohne Passwort.
                    </p>
                    <p class="tix-magic-modal-hint">
                        📥 Falls die Mail nicht in den nächsten Minuten ankommt: prüfe bitte auch deinen <strong>Spam-Ordner</strong>. Der Link ist 30 Minuten gültig.
                    </p>
                </div>

                <?php // Variante: Bestellungen vorhanden, kein Account — Gast-Ansicht ?>
                <div class="tix-magic-modal-variant" data-variant="has_orders" hidden>
                    <div class="tix-magic-modal-icon" style="background:#e0f2fe;color:#0284c7;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <h2 class="tix-magic-modal-title">E-Mail wurde versendet</h2>
                    <p class="tix-magic-modal-text">
                        Wir haben dir einen Link an <strong class="tix-magic-modal-email"></strong> geschickt. Über diesen Link kommst du direkt zu allen Tickets dieser E-Mail-Adresse — ganz ohne Konto.
                    </p>
                    <p class="tix-magic-modal-hint">
                        📥 Falls die Mail nicht in den nächsten Minuten ankommt: prüfe bitte auch deinen <strong>Spam-Ordner</strong>. Der Link ist 30 Minuten gültig.
                    </p>
                </div>

                <?php // Variante: Email gar nicht gefunden — Support-Hinweis ?>
                <div class="tix-magic-modal-variant" data-variant="not_found" hidden>
                    <div class="tix-magic-modal-icon" style="background:#fee2e2;color:#dc2626;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <h2 class="tix-magic-modal-title">E-Mail nicht gefunden</h2>
                    <p class="tix-magic-modal-text">
                        Mit der Adresse <strong class="tix-magic-modal-email"></strong> haben wir keine Bestellung und kein Konto im System.
                    </p>
                    <p class="tix-magic-modal-hint" style="background:#fef3c7;border-color:#fde68a;color:#92400e;">
                        💡 Vielleicht hast du mit einer <strong>anderen E-Mail-Adresse</strong> bestellt? Probier es noch einmal mit der Adresse, die du beim Kauf angegeben hast.
                    </p>
                    <p class="tix-magic-modal-text" style="font-size:13px;margin-top:14px;">
                        Du benötigst Hilfe? Schreib uns an <a class="tix-magic-modal-support" href="#" style="color:#0284c7;font-weight:600;">support</a> — wir helfen dir gern weiter.
                    </p>
                </div>

                <button type="button" class="tix-magic-modal-btn" data-magic-close>Alles klar</button>
            </div>
        </div>
        <style>
            .tix-magic-block { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px 24px; margin-top:16px; }
            .tix-magic-title { font-size:15px; font-weight:700; color:#0f172a; margin:0 0 6px; display:flex; align-items:center; gap:8px; }
            .tix-magic-title::before { content:"🔗"; font-size:18px; }
            .tix-magic-text { font-size:13px; color:#64748b; margin:0 0 14px; line-height:1.5; }
            .tix-magic-form { display:flex; flex-direction:column; gap:10px; }
            .tix-magic-input { width:100%; min-width:0; padding:11px 14px; border:1px solid #cbd5e1; border-radius:10px; font-size:14px; background:#fff; color:#0f172a; font-family:inherit; box-sizing:border-box; }
            /* iOS Safari Auto-Zoom: Inputs < 16px lösen Auto-Zoom aus → Mobile auf 16px bumpen */
            @media (max-width: 768px) { .tix-magic-input { font-size:16px !important; } }
            .tix-magic-input:focus { outline:none; border-color:#0284c7; box-shadow:0 0 0 3px rgba(2,132,199,.15); }
            .tix-magic-btn { width:100%; padding:11px 20px; background:#0284c7; color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit; transition:background .15s, opacity .15s; white-space:nowrap; box-sizing:border-box; }
            .tix-magic-btn:hover { filter:brightness(1.1); }
            .tix-magic-btn:disabled { opacity:.6; cursor:wait; }
            .tix-magic-result { margin-top:12px; padding:10px 14px; border-radius:8px; font-size:13px; line-height:1.5; }
            .tix-magic-result.is-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
            .tix-magic-result.is-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
            /* Modal */
            .tix-magic-modal { position:fixed; inset:0; z-index:99999; display:flex; align-items:center; justify-content:center; padding:20px; }
            .tix-magic-modal[hidden] { display:none !important; }
            .tix-magic-modal-backdrop { position:absolute; inset:0; background:rgba(15,23,42,.6); animation:tixMagFadeIn .2s ease; }
            .tix-magic-modal-content { position:relative; background:#fff; border-radius:18px; padding:32px 28px 24px; max-width:440px; width:100%; box-shadow:0 24px 60px rgba(0,0,0,.25); animation:tixMagScaleIn .25s cubic-bezier(.4,0,.2,1); text-align:center; }
            .tix-magic-modal-x { position:absolute; top:14px; right:14px; background:none; border:none; font-size:28px; line-height:1; color:#94a3b8; cursor:pointer; padding:4px 8px; border-radius:8px; }
            .tix-magic-modal-x:hover { background:#f1f5f9; color:#1e293b; }
            .tix-magic-modal-icon { width:72px; height:72px; margin:0 auto 18px; border-radius:50%; background:#e0f2fe; color:#0284c7; display:flex; align-items:center; justify-content:center; }
            .tix-magic-modal-title { font-size:20px; font-weight:700; color:#0f172a; margin:0 0 12px; }
            .tix-magic-modal-text { font-size:14px; color:#475569; line-height:1.55; margin:0 0 14px; }
            .tix-magic-modal-text strong { color:#0f172a; word-break:break-all; }
            .tix-magic-modal-hint { font-size:13px; color:#64748b; line-height:1.5; margin:0 0 22px; padding:12px 14px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0; }
            .tix-magic-modal-btn { background:#0284c7; color:#fff; border:none; padding:12px 28px; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit; }
            .tix-magic-modal-btn:hover { background:#0369a1; }
            @keyframes tixMagFadeIn { from { opacity:0; } to { opacity:1; } }
            @keyframes tixMagScaleIn { from { opacity:0; transform:scale(.92); } to { opacity:1; transform:scale(1); } }
        </style>
        <script>
        (function(){
            var modal = document.getElementById('tix-magic-modal');
            // window.tixMagicShowModal(scenario, email, supportEmail)
            //   scenario: 'has_account' | 'has_orders' | 'not_found'
            window.tixMagicShowModal = function(scenario, email, supportEmail) {
                if (!modal) {
                    var msg = scenario === 'not_found'
                        ? 'Keine Bestellung mit ' + email + ' gefunden.'
                        : 'E-Mail mit Link an ' + email + ' geschickt.';
                    alert(msg);
                    return;
                }
                // Alle Varianten verstecken, dann die richtige zeigen
                var variants = modal.querySelectorAll('.tix-magic-modal-variant');
                variants.forEach(function(v){ v.hidden = true; });
                var active = modal.querySelector('[data-variant="' + scenario + '"]');
                if (!active) active = modal.querySelector('[data-variant="has_orders"]'); // Fallback
                active.hidden = false;
                // Email-Placeholder befüllen
                active.querySelectorAll('.tix-magic-modal-email').forEach(function(el){ el.textContent = email; });
                // Support-Link befüllen (nur not_found)
                if (scenario === 'not_found' && supportEmail) {
                    active.querySelectorAll('.tix-magic-modal-support').forEach(function(el){
                        el.setAttribute('href', 'mailto:' + supportEmail);
                        el.textContent = supportEmail;
                    });
                }
                modal.removeAttribute('hidden');
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            };
            function close() { if (!modal) return; modal.setAttribute('hidden',''); modal.style.display=''; document.body.style.overflow=''; }
            if (modal) {
                modal.addEventListener('click', function(e){ if (e.target.hasAttribute('data-magic-close')) close(); });
                document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !modal.hasAttribute('hidden')) close(); });
            }
        })();
        </script>
        <?php
    }

    public static function get_tickets_page_url() {
        // 0. Settings-Override (gilt überall — Thank-You, Magic-Link, Email-CTA etc.)
        $tys = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (!empty($tys['ty_my_tickets_url'])) {
            return $tys['ty_my_tickets_url'];
        }
        // 1. Referer wenn Host passt (Kunde ist dort gelandet)
        $ref = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        if ($ref) {
            $ref_host = wp_parse_url($ref, PHP_URL_HOST);
            $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($ref_host && $site_host && $ref_host === $site_host) {
                // Query-Strings strippen
                $p = strtok($ref, '?#');
                if ($p) return $p;
            }
        }
        // 2. Configurable tix_my_tickets_page_id Option (legacy)
        $page_id = intval(get_option('tix_my_tickets_page_id', 0));
        if ($page_id) {
            $u = get_permalink($page_id);
            if ($u) return $u;
        }
        // 3. Page-Slug "meine-tickets" oder "tickets" automatisch finden
        foreach (['meine-tickets', 'tickets'] as $slug) {
            $page = get_page_by_path($slug);
            if ($page) return get_permalink($page->ID);
        }
        // 4. Fallback
        return home_url('/tickets/');
    }

    /**
     * Sendet die Magic-Link-Mail an den Käufer.
     */
    private static function send_magic_link_email($email, $url) {
        $site_name = get_bloginfo('name');
        $subject   = 'Deine Tickets ansehen – ' . $site_name;

        $body  = '<p>Hallo,</p>';
        $body .= '<p>du hast angefragt, deine Tickets ohne Konto abzurufen. Klicke auf den folgenden Button, um alle deine Tickets anzusehen und herunterzuladen:</p>';
        $body .= '<p style="text-align:center;margin:28px 0;">';
        $body .= '<a href="' . esc_url($url) . '" style="display:inline-block;padding:14px 28px;'
               . (function_exists('tix_btn_style') ? tix_btn_style() : 'background:#0f172a;color:#fff;border-radius:10px;')
               . 'text-decoration:none;font-weight:600;font-size:15px;">Meine Tickets ansehen</a>';
        $body .= '</p>';
        $body .= '<p style="color:#64748b;font-size:13px;line-height:1.6;">Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:<br><span style="word-break:break-all;color:#334155;">' . esc_html($url) . '</span></p>';
        $body .= '<p style="color:#64748b;font-size:13px;">Der Link ist aus Sicherheitsgründen <strong>30 Minuten gültig</strong>. Du kannst ihn beliebig oft innerhalb dieser Zeit verwenden.</p>';
        $body .= '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Falls du diesen Link nicht angefordert hast, kannst du diese E-Mail ignorieren — es wird nichts passieren.</p>';

        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html('Deine Tickets', $body, 'Zugang ohne Konto')
            : '<html><body>' . $body . '</body></html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($email, $subject, $html, $headers);
    }

    // ═══════════════════════════════════════════════════════════════
    // ORDER LOADING: Dual-Source (WC + native) — für User ODER Email
    // ═══════════════════════════════════════════════════════════════

    /**
     * Lädt Bestellungen für einen eingeloggten User oder eine Gast-Email.
     * Übergib entweder $user_id > 0 ODER $email (oder beides — $user_id hat Vorrang für WC).
     * Dedupliziert native Orders die als WC-Dual-Write existieren.
     */
    private static function load_orders($user_id, $email = '') {
        $orders = [];

        // -- WooCommerce --
        if (class_exists('WooCommerce')) {
            $wc_args = [
                'limit'    => 50,
                'orderby'  => 'date',
                'order'    => 'DESC',
                'status'   => ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending'],
            ];
            if ($user_id > 0) {
                $wc_args['customer_id'] = $user_id;
            } elseif ($email) {
                $wc_args['billing_email'] = $email;
            } else {
                $wc_args = null;
            }
            if ($wc_args) {
                $orders = wc_get_orders($wc_args);
                if (!is_array($orders)) $orders = [];
            }
        }

        // -- Native TIX_Order --
        if (class_exists('TIX_Order')) {
            // Beide Quellen laden + dedupen — wichtig wenn Gast-Bestellung mit später
            // erstelltem Konto existiert (customer_id=0 + matching email).
            $natives = [];
            $seen = [];
            $sources = [];
            if ($user_id > 0) {
                $sources[] = TIX_Order::query([
                    'customer_id' => $user_id,
                    'status'      => ['completed', 'processing'],
                    'limit'       => 50,
                ]);
            }
            if ($email) {
                $sources[] = TIX_Order::query([
                    'email'  => $email,
                    'status' => ['completed', 'processing'],
                    'limit'  => 50,
                ]);
            }
            foreach ($sources as $batch) {
                foreach ((array) $batch as $o) {
                    $oid = $o->get_id();
                    if (isset($seen[$oid])) continue;
                    $seen[$oid] = true;
                    $natives[] = $o;
                }
            }

            if (!empty($natives)) {
                $wc_order_ids = array_map(fn($o) => $o->get_id(), $orders);
                foreach ($natives as $native) {
                    $wc_id = method_exists($native, 'get_wc_order_id') ? $native->get_wc_order_id() : 0;
                    if ($wc_id && in_array($wc_id, $wc_order_ids)) continue; // Dual-Write
                    $orders[] = $native;
                }
            }

            // Bonus: Gast-Bestellungen (customer_id=0) rückwirkend zuordnen
            if ($user_id > 0 && $email) {
                global $wpdb;
                $wpdb->query($wpdb->prepare(
                    "UPDATE " . TIX_Order::table_name() . "
                     SET customer_id = %d
                     WHERE customer_id = 0 AND billing_email = %s",
                    $user_id, $email
                ));
            }
        }

        return $orders;
    }

    // ═══════════════════════════════════════════════════════════════
    // TEMPLATES: Guest-Banner, Token-Expired, Empty
    // ═══════════════════════════════════════════════════════════════

    /**
     * Banner oben in der Gast-Ansicht — zeigt Email + Hinweis + Logout.
     */
    private static function render_guest_banner($email) {
        $logout_url = esc_url(remove_query_arg('tix_mt_token'));
        ob_start();
        ?>
        <div class="tix-mt-guest-banner" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:12px 16px;margin:0 0 18px;border:1px solid #bae6fd;background:#f0f9ff;border-radius:12px;color:#075985;font-size:13px;">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:18px;">&#128274;</span>
                <div>
                    <strong>Gast-Ansicht</strong> für <code style="background:rgba(255,255,255,0.6);padding:2px 6px;border-radius:4px;"><?php echo esc_html($email); ?></code><br>
                    <span style="color:#0c4a6e;font-size:12px;">Link ist 30 Minuten gültig.</span>
                </div>
            </div>
            <a href="<?php echo $logout_url; ?>" class="tix-mt-guest-logout" style="font-size:12px;color:#0369a1;text-decoration:underline;">Abmelden</a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Seite wenn Token abgelaufen / ungültig ist — bietet Formular zum neu anfordern an.
     */
    private static function render_token_expired() {
        ob_start();
        ?>
        <div class="tix-mt" id="tix-my-tickets">
            <div class="tix-mt-login">
                <div class="tix-mt-login-icon">&#9201;</div>
                <h2 class="tix-mt-login-title">Link abgelaufen</h2>
                <p class="tix-mt-login-text">Dein Ticket-Link ist nicht mehr gültig (max. 30 Minuten). Fordere unten einen neuen an — oder melde dich an, falls du ein Konto hast.</p>
            </div>
        </div>
        <?php
        // Unterhalb das Login + Guest-Formular zeigen
        echo self::render_login();
        return ob_get_clean();
    }

    /**
     * Leerer State — falls $email gesetzt: Gast-Variante (kein „kauf was ein"-Wording)
     */
    private static function render_empty($email = '') {
        ob_start();
        ?>
        <div class="tix-mt" id="tix-my-tickets">
            <?php if ($email) echo self::render_guest_banner($email); ?>
            <div class="tix-mt-empty">
                <div class="tix-mt-empty-icon">&#127915;</div>
                <h2 class="tix-mt-empty-title">Keine Tickets gefunden</h2>
                <p class="tix-mt-empty-text">
                    <?php if ($email): ?>
                        Für <strong><?php echo esc_html($email); ?></strong> sind aktuell keine Tickets hinterlegt. Hast du vielleicht mit einer anderen E-Mail bestellt?
                    <?php else: ?>
                        Du hast noch keine Tickets gekauft. Entdecke unsere Events!
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Bestell-Daten aufbereiten
     */
    private static function build_order_data($order) {
        $order_id = $order->get_id();
        $status   = $order->get_status();

        // Status Labels (DE)
        $status_labels = [
            'completed'  => 'Abgeschlossen',
            'processing' => 'In Bearbeitung',
            'on-hold'    => 'Wartend',
            'pending'    => 'Ausstehend',
        ];

        // Tickets laden (Unified Interface)
        $all_tickets = [];
        $debug = isset($_GET['tix_debug']);

        if (class_exists('TIX_Tickets')) {
            $unified = TIX_Tickets::get_all_tickets_for_order($order_id);

            foreach ($unified as $ut) {
                // Transferierte Tickets herausfiltern
                if (!empty(get_post_meta($ut['id'], '_tix_transfer_date', true))) continue;
                if ($ut['source'] === 'eh' && $ut['status'] === 'transferred') continue;

                // TC Event-ID: Bei TIX-Tickets nicht vorhanden, bei TC-Tickets über event_id Meta
                $tc_event_id = '';
                if ($ut['source'] === 'tc') {
                    $tc_event_id = get_post_meta($ut['id'], 'event_id', true);
                }

                $all_tickets[] = [
                    'id'           => $ut['id'],
                    'code'         => $ut['code'],
                    'type_name'    => $ut['cat_name'],
                    'tc_event_id'  => $tc_event_id,
                    'tix_event_id'  => $ut['event_id'],
                    'product_id'   => $ut['product_id'],
                    'download_url' => $ut['download_url'],
                ];
            }

            if ($debug) {
                $dbg_codes = array_map(fn($t) => $t['code'], $all_tickets);
                echo '<!-- TIX_DEBUG order=' . esc_html($order_id)
                   . ' found=' . count($all_tickets)
                   . ' via=unified_interface'
                   . ' codes=[' . implode(',', $dbg_codes) . ']'
                   . ' mode=' . (tix_get_settings('ticket_system') ?: 'standalone')
                   . ' -->';
            }
        }

        // Events + Kombi-Gruppen aus Bestellpositionen ableiten
        $events = [];
        $combos = [];
        $separator = html_entity_decode(' &ndash; ', ENT_QUOTES, 'UTF-8');

        foreach ($order->get_items() as $item) {
            $is_native_item = ($order instanceof TIX_Order) || (is_object($item) && method_exists($item, 'get_event_id'));

            if ($is_native_item) {
                // Native TIX_Order item: has name, quantity, total, event_id directly
                $product_id   = method_exists($item, 'get_product_id') ? $item->get_product_id() : 0;
                $product_name = method_exists($item, 'get_name') ? $item->get_name() : ($item->name ?? '');
                $qty          = method_exists($item, 'get_quantity') ? $item->get_quantity() : (intval($item->quantity ?? 1));
                $total        = floatval(method_exists($item, 'get_total') ? $item->get_total() : ($item->total ?? 0));
            } else {
                $product_id = $item->get_product_id();
                $product    = $item->get_product();
                if (!$product) continue;

                // Nur Ticket-Produkte
                // Legacy: _tc_is_ticket check retained for backward compatibility with older products
                $is_ticket = get_post_meta($product_id, '_tc_is_ticket', true) === 'yes'
                          || get_post_meta($product_id, '_tix_is_ticket', true) === 'yes';
                if (!$is_ticket) continue;

                $product_name = $product->get_name();
                $qty          = $item->get_quantity();
                $total        = floatval($item->get_total());
            }

            // Event-Name und Ticket-Type aus Produktname ableiten
            $parts = explode($separator, $product_name, 2);
            if (count($parts) < 2) {
                $parts = explode(' - ', $product_name, 2);
            }
            $event_name = $parts[0] ?? $product_name;
            $type_name  = $parts[1] ?? '';

            // Event-Post finden
            if ($is_native_item && method_exists($item, 'get_event_id')) {
                // Native TIX_Order item: event_id is stored directly
                $event_id = intval($item->get_event_id());
            } else {
                // WC: Primär via _tix_source_event (direkt), dann _tix_parent_event_id, Fallback per Name
                $event_id = $product_id ? intval(get_post_meta($product_id, '_tix_source_event', true)) : 0;
                if (!$event_id && $product_id) {
                    $event_id = intval(get_post_meta($product_id, '_tix_parent_event_id', true));
                }
                if (!$event_id) {
                    $event_post = self::find_event_by_name($event_name);
                    $event_id   = $event_post ? $event_post->ID : 0;
                }
            }

            // TC Event-ID für präzises Matching (jede Kategorie hat eigenes TC-Event)
            $tc_event_id_of_product = $product_id ? get_post_meta($product_id, '_event_name', true) : '';

            // Event-Daten
            $ev_data = self::get_event_display_data($event_id);

            // Tickets für dieses Item finden
            $matching_tickets = self::match_tickets($all_tickets, $event_id, $product_id, $product_name, $type_name, $tc_event_id_of_product);

            // Kombi-Erkennung
            $combo_meta = method_exists($item, 'get_meta') ? $item->get_meta('_tix_combo') : null;

            if (!empty($combo_meta) && !empty($combo_meta['group_id'])) {
                // ── Kombi-Item ──
                $gid = $combo_meta['group_id'];
                if (!isset($combos[$gid])) {
                    $combos[$gid] = [
                        'combo_label'  => $combo_meta['label'] ?? '',
                        'combo_price'  => floatval($combo_meta['total_price'] ?? 0),
                        'qty'          => $qty,
                        'sub_events'   => [],
                        'all_tickets'  => [],
                    ];
                }

                $combos[$gid]['sub_events'][] = [
                    'event_name'     => $event_name,
                    'event_id'       => $event_id,
                    'date_start_raw' => $ev_data['date_start_raw'],
                    'date_start'     => $ev_data['date_start'],
                    'time_start'     => $ev_data['time_start'],
                    'time_doors'     => $ev_data['time_doors'],
                    'location'       => $ev_data['location'],
                    'thumbnail'      => $ev_data['thumbnail'],
                    'event_status'   => $ev_data['event_status'],
                    'type_name'      => $type_name,
                    'tickets'        => $matching_tickets,
                ];

                foreach ($matching_tickets as $mt) {
                    $combos[$gid]['all_tickets'][] = $mt;
                }

            } else {
                // ── Reguläres Item ──
                $event_key = $event_id ? $event_id : sanitize_title($event_name);
                if (!isset($events[$event_key])) {
                    $events[$event_key] = array_merge($ev_data, [
                        'event_name' => $event_name,
                        'event_id'   => $event_id,
                        'items'      => [],
                        'tickets'    => [],
                    ]);
                }

                $events[$event_key]['items'][] = [
                    'name'  => $type_name ?: $product_name,
                    'qty'   => $qty,
                    'total' => $total,
                ];

                foreach ($matching_tickets as $mt) {
                    $events[$event_key]['tickets'][] = $mt;
                }
            }
        }

        // Tickets die keinem Item zugeordnet wurden → zuordnen
        $assigned_ids = [];
        foreach ($events as $ev) {
            foreach ($ev['tickets'] as $t) $assigned_ids[] = $t['id'];
        }
        foreach ($combos as $cg) {
            foreach ($cg['all_tickets'] as $t) $assigned_ids[] = $t['id'];
        }
        $unassigned = array_filter($all_tickets, fn($tt) => !in_array($tt['id'], $assigned_ids));
        if (!empty($unassigned)) {
            if (!empty($events)) {
                // Erstem regulären Event anhängen
                $first_key = array_key_first($events);
                foreach ($unassigned as $u) {
                    $events[$first_key]['tickets'][] = $u;
                }
            } elseif (!empty($combos)) {
                // Nur Kombi-Items: versuche Tickets per TC-Event den Sub-Events zuzuordnen
                foreach ($unassigned as $u) {
                    $placed = false;
                    foreach ($combos as $gid => &$combo_ref) {
                        foreach ($combo_ref['sub_events'] as $si => &$se_ref) {
                            // Versuch: tix_event_id des Tickets passt zum Sub-Event
                            if (!empty($u['tix_event_id']) && !empty($se_ref['event_id']) && (string) $u['tix_event_id'] === (string) $se_ref['event_id']) {
                                $se_ref['tickets'][] = $u;
                                $combo_ref['all_tickets'][] = $u;
                                $placed = true;
                                break 2;
                            }
                        }
                        unset($se_ref);
                    }
                    unset($combo_ref);
                    // Fallback: erstem Kombi anhängen
                    if (!$placed) {
                        $first_gid = array_key_first($combos);
                        if (!empty($combos[$first_gid]['sub_events'])) {
                            $combos[$first_gid]['sub_events'][0]['tickets'][] = $u;
                            $combos[$first_gid]['all_tickets'][] = $u;
                        }
                    }
                }
            }
        }

        // Order-Number (konfigurierte Nummer) + Legacy-Nummer für migrierte Bestellungen
        $order_number = method_exists($order, 'get_order_number') ? $order->get_order_number() : (string) $order_id;
        $legacy_wc_number = get_post_meta($order_id, '_tix_legacy_wc_order_number', true);

        return [
            'order_id'       => $order_id,
            'order_number'   => $order_number,
            'legacy_wc_number' => $legacy_wc_number ?: '',
            'order_date'     => self::format_order_date($order),
            'status'         => $status,
            'status_label'   => isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status),
            'total'          => $order->get_total(),
            'currency'       => method_exists($order, 'get_currency') ? $order->get_currency() : (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR'),
            'events'         => $events,
            'combos'         => $combos,
            'payment_method'    => $order->get_payment_method_title(),
            'payment_method_id' => method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '',
            'order_total_formatted' => $order->get_formatted_order_total(),
            'buyer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'buyer_email' => $order->get_billing_email(),
        ];
    }

    /**
     * Format order date from WC_Order or TIX_Order
     */
    private static function format_order_date($order) {
        $date = $order->get_date_created();
        if (!$date) return '';
        if (is_object($date) && method_exists($date, 'date_i18n')) {
            return $date->date_i18n('d.m.Y');
        }
        // TIX_Order may return a string or timestamp
        if (is_string($date)) {
            return date_i18n('d.m.Y', strtotime($date));
        }
        return date_i18n('d.m.Y', $date);
    }

    /**
     * Event-Darstellungsdaten laden
     */
    private static function get_event_display_data($event_id) {
        $date_start = $event_id ? get_post_meta($event_id, '_tix_date_start', true) : '';
        $time_start = $event_id ? get_post_meta($event_id, '_tix_time_start', true) : '';
        $time_doors = $event_id ? get_post_meta($event_id, '_tix_time_doors', true) : '';
        $location   = $event_id ? get_post_meta($event_id, '_tix_location', true) : '';
        return [
            'date_start_raw' => $date_start,
            'date_start'     => $date_start ? date_i18n('D, d. M Y', strtotime($date_start)) : '',
            'time_start'     => $time_start ? date_i18n('H:i', strtotime($time_start)) . ' Uhr' : '',
            'time_doors'     => $time_doors ? date_i18n('H:i', strtotime($time_doors)) . ' Uhr' : '',
            'location'       => $location,
            'address'        => $event_id ? get_post_meta($event_id, '_tix_address', true) : '',
            'permalink'      => $event_id ? get_permalink($event_id) : '',
            'thumbnail'      => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
            'event_status'   => $event_id ? get_post_meta($event_id, '_tix_status', true) : '',
        ];
    }

    /**
     * Tickets einem Item zuordnen
     */
    private static function match_tickets(&$all_tickets, $event_id, $product_id, $product_name, $type_name, $tc_event_id_of_product = '') {
        $matched = [];
        foreach ($all_tickets as $k => $tt) {
            $is_match = false;

            // 1. TC Event-ID (präziseste Zuordnung: jede Kategorie hat eigenes TC-Event)
            if (!$is_match && $tc_event_id_of_product && !empty($tt['tc_event_id']) && (string) $tc_event_id_of_product === (string) $tt['tc_event_id']) {
                $is_match = true;
            }

            // 2. Produkt-ID
            if (!$is_match && $product_id && !empty($tt['product_id']) && (string) $tt['product_id'] === (string) $product_id) {
                $is_match = true;
            }

            // 3. Tixomat Event-ID
            if (!$is_match && $event_id && !empty($tt['tix_event_id']) && (string) $tt['tix_event_id'] === (string) $event_id) {
                $is_match = true;
            }

            // 4. Typ-Name in Produktname
            if (!$is_match && !empty($tt['type_name']) && stripos($product_name, $tt['type_name']) !== false) {
                $is_match = true;
            }

            if ($is_match) {
                $matched[] = $tt;
                unset($all_tickets[$k]); // Verhindern, dass ein Ticket doppelt zugeordnet wird
            }
        }
        return $matched;
    }

    /**
     * Event-Post per Name finden
     */
    private static function find_event_by_name($name) {
        static $cache = [];
        $key = sanitize_title($name);
        if (isset($cache[$key])) return $cache[$key];

        $posts = get_posts([
            'post_type'      => 'event',
            'title'          => $name,
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ]);

        $cache[$key] = !empty($posts) ? $posts[0] : null;
        return $cache[$key];
    }

    /**
     * Bestell-Card rendern
     */
    private static function render_order_card($od, $is_past) {
        static $card_index = 0;
        $card_index++;

        $status_classes = [
            'completed'  => 'tix-mt-status-ok',
            'processing' => 'tix-mt-status-ok',
            'on-hold'    => 'tix-mt-status-warn',
            'pending'    => 'tix-mt-status-wait',
        ];
        $sc = isset($status_classes[$od['status']]) ? $status_classes[$od['status']] : 'tix-mt-status-wait';
        $is_paid = in_array($od['status'], ['completed', 'processing']);

        // Erstes kommende Event standardmaessig offen
        $is_open = (!$is_past && $card_index === 1);

        // ── Kombi-Tickets rendern ──
        if (!empty($od['combos'])):
            foreach ($od['combos'] as $gid => $combo):
                $panel_id = 'tix-mt-panel-' . $card_index . '-combo-' . substr(md5($gid), 0, 8);
                $first_se = $combo['sub_events'][0] ?? [];
        ?>
        <div class="tix-mt-card tix-mt-card-combo<?php if ($is_past) echo ' tix-mt-card-past'; ?>">

            <button class="tix-mt-card-toggle" type="button"
                    aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>"
                    aria-controls="<?php echo esc_attr($panel_id); ?>">
                <div class="tix-mt-card-header">
                    <?php if (!empty($first_se['thumbnail'])): ?>
                        <div class="tix-mt-card-thumb">
                            <img src="<?php echo esc_url($first_se['thumbnail']); ?>" alt="" loading="lazy">
                        </div>
                    <?php endif; ?>

                    <div class="tix-mt-card-event">
                        <span class="tix-mt-card-title">
                            <span class="tix-mt-combo-badge">Kombi</span>
                            <?php echo esc_html($combo['combo_label']); ?>
                        </span>

                        <div class="tix-mt-card-meta">
                            <?php
                            // Alle Event-Namen als Sub-Übersicht
                            $event_names = array_map(fn($se) => $se['event_name'], $combo['sub_events']);
                            ?>
                            <span class="tix-mt-meta-item">
                                <span class="tix-mt-meta-icon">&#127915;</span>
                                <?php echo esc_html(implode(' + ', $event_names)); ?>
                            </span>
                            <?php if (!empty($first_se['location'])): ?>
                                <span class="tix-mt-meta-item">
                                    <span class="tix-mt-meta-icon">&#128205;</span>
                                    <?php echo esc_html($first_se['location']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tix-mt-card-right">
                        <span class="tix-mt-status <?php echo $sc; ?>">
                            <?php echo esc_html($od['status_label']); ?>
                        </span>
                        <span class="tix-mt-card-chevron">
                            <svg width="14" height="8" viewBox="0 0 14 8" fill="none"><path d="M1 1l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </div>
                </div>
            </button>

            <div class="tix-mt-card-collapse<?php if ($is_open) echo ' tix-mt-card-open'; ?>"
                 id="<?php echo esc_attr($panel_id); ?>"
                 <?php if (!$is_open) echo 'hidden'; ?>>

                <div class="tix-mt-card-body">

                    <?php // Sub-Events mit ihren Daten auflisten ?>
                    <div class="tix-mt-combo-events">
                        <?php foreach ($combo['sub_events'] as $se): ?>
                            <div class="tix-mt-combo-event-row">
                                <div class="tix-mt-combo-event-info">
                                    <span class="tix-mt-combo-event-name"><?php echo esc_html($se['event_name']); ?></span>
                                    <span class="tix-mt-combo-event-meta">
                                        <?php echo esc_html($se['date_start']); ?>
                                        <?php if (!empty($se['time_doors'])): ?>
                                            &middot; Einlass <?php echo esc_html($se['time_doors']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($se['type_name'])): ?>
                                            &middot; <?php echo esc_html($se['type_name']); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="tix-mt-combo-price">
                            <span>Kombi-Preis</span>
                            <strong><?php echo self::format_price($combo['combo_price']); ?></strong>
                            <?php if ($combo['qty'] > 1): ?>
                                <span class="tix-mt-combo-qty">&times; <?php echo (int) $combo['qty']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($combo['all_tickets']) && $is_paid): ?>
                        <?php
                        // Bundle-URL aus Token eines eigenen Tickets bauen (Owner-Filter im Handler)
                        $bundle_url_combo = '';
                        if (class_exists('TIX_Tickets') && !empty($combo['all_tickets'][0]['id'])) {
                            $own_token = TIX_Tickets::ensure_download_token($combo['all_tickets'][0]['id']);
                            if ($own_token) {
                                $bundle_url_combo = add_query_arg(['tix_bundle' => $own_token], home_url('/'));
                            }
                        }
                        if ($bundle_url_combo):
                        ?>
                            <a href="<?php echo esc_url($bundle_url_combo); ?>" target="_blank" class="tix-mt-bundle-link" style="display:inline-flex;align-items:center;gap:8px;margin-top:12px;margin-bottom:12px;padding:10px 14px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;color:#5b21b6;font-size:13px;font-weight:600;text-decoration:none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="16" width="18" height="4" rx="1"/></svg>
                                Alle Tickets dieser Bestellung in einer Ansicht öffnen →
                            </a>
                        <?php endif; ?>
                        <div class="tix-mt-tickets-grid">
                            <?php
                            $ticket_num = 0;
                            foreach ($combo['sub_events'] as $se):
                                foreach ($se['tickets'] as $t): $ticket_num++;
                                    self::render_ticket_card($t, $se, $ticket_num, $od);
                                endforeach;
                            endforeach;
                            ?>
                        </div>

                    <?php elseif (!$is_paid): ?>
                        <?php self::render_pending_box($od); ?>
                    <?php endif; ?>
                </div>

                <div class="tix-mt-card-footer">
                    <?php if (!empty($od['is_transfer'])): ?>
                        <span class="tix-mt-order-ref">&#128260; Umgeschrieben am <?php echo esc_html($od['order_date']); ?></span>
                    <?php else: ?>
                        <span class="tix-mt-order-ref">
                            Bestellung <?php echo esc_html($od['order_number'] ?? '#' . (int) $od['order_id']); ?>
                            <?php if (!empty($od['legacy_wc_number'])): ?>
                                <small style="opacity:.7;">(ehemals #<?php echo esc_html($od['legacy_wc_number']); ?>)</small>
                            <?php endif; ?>
                            &middot; <?php echo esc_html($od['order_date']); ?>
                            <?php if (!empty($od['payment_method'])): ?>
                                &middot; <?php echo esc_html($od['payment_method']); ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>

            </div>

        </div>
        <?php
            endforeach;
        endif;

        // ── Reguläre Events rendern ──
        foreach ($od['events'] as $ev):
            $panel_id = 'tix-mt-panel-' . $card_index . '-' . sanitize_title($ev['event_name']);
        ?>
        <div class="tix-mt-card<?php if ($is_past) echo ' tix-mt-card-past'; ?>">

            <button class="tix-mt-card-toggle" type="button"
                    aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>"
                    aria-controls="<?php echo esc_attr($panel_id); ?>">
                <div class="tix-mt-card-header">
                    <?php if (!empty($ev['thumbnail'])): ?>
                        <div class="tix-mt-card-thumb">
                            <img src="<?php echo esc_url($ev['thumbnail']); ?>" alt="" loading="lazy">
                        </div>
                    <?php endif; ?>

                    <div class="tix-mt-card-event">
                        <span class="tix-mt-card-title">
                            <?php echo esc_html($ev['event_name']); ?>
                        </span>

                        <div class="tix-mt-card-meta">
                            <?php if (!empty($ev['date_start'])): ?>
                                <span class="tix-mt-meta-item">
                                    <span class="tix-mt-meta-icon">&#128197;</span>
                                    <?php echo esc_html($ev['date_start']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($ev['time_doors'])): ?>
                                <span class="tix-mt-meta-item">
                                    <span class="tix-mt-meta-icon">&#128682;</span>
                                    Einlass <?php echo esc_html($ev['time_doors']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($ev['time_start']) && $ev['time_start'] !== $ev['time_doors']): ?>
                                <span class="tix-mt-meta-item">
                                    <span class="tix-mt-meta-icon">&#128336;</span>
                                    Beginn <?php echo esc_html($ev['time_start']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($ev['location'])): ?>
                                <span class="tix-mt-meta-item">
                                    <span class="tix-mt-meta-icon">&#128205;</span>
                                    <?php echo esc_html($ev['location']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (in_array($ev['event_status'] ?? '', ['cancelled', 'postponed'])): ?>
                            <span class="tix-mt-event-badge tix-mt-event-<?php echo esc_attr($ev['event_status']); ?>">
                                <?php echo $ev['event_status'] === 'cancelled' ? 'Abgesagt' : 'Verschoben'; ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="tix-mt-card-right">
                        <span class="tix-mt-status <?php echo $sc; ?>">
                            <?php echo esc_html($od['status_label']); ?>
                        </span>
                        <span class="tix-mt-card-chevron">
                            <svg width="14" height="8" viewBox="0 0 14 8" fill="none"><path d="M1 1l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </div>
                </div>
            </button>

            <div class="tix-mt-card-collapse<?php if ($is_open) echo ' tix-mt-card-open'; ?>"
                 id="<?php echo esc_attr($panel_id); ?>"
                 <?php if (!$is_open) echo 'hidden'; ?>>

                <div class="tix-mt-card-body">

                    <?php foreach ($ev['items'] as $it): ?>
                        <div class="tix-mt-ticket-row">
                            <div class="tix-mt-ticket-info">
                                <span class="tix-mt-ticket-type"><?php echo esc_html($it['name']); ?></span>
                                <span class="tix-mt-ticket-qty"><?php echo (int) $it['qty']; ?>&times; Ticket<?php echo $it['qty'] > 1 ? 's' : ''; ?></span>
                            </div>
                            <span class="tix-mt-ticket-price"><?php echo self::format_price($it['total']); ?></span>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($ev['tickets']) && $is_paid): ?>
                        <?php
                        // Bundle-URL aus Token EINES SICHTBAREN Tickets bauen (= das gehört
                        // dem aktuellen User). get_bundle_url($order_id) würde das ERSTE
                        // Ticket der Order nehmen — bei admin-zugeordneten Tickets gehört
                        // das aber evtl. einem anderen User → Filter würde dessen Tickets
                        // zeigen statt der eigenen.
                        $bundle_url = '';
                        if (class_exists('TIX_Tickets') && !empty($ev['tickets'][0]['id'])) {
                            $own_token = TIX_Tickets::ensure_download_token($ev['tickets'][0]['id']);
                            if ($own_token) {
                                $bundle_url = add_query_arg(['tix_bundle' => $own_token], home_url('/'));
                            }
                        }
                        if ($bundle_url && count($ev['tickets']) > 1):
                        ?>
                            <a href="<?php echo esc_url($bundle_url); ?>" target="_blank" class="tix-mt-bundle-link" style="display:inline-flex;align-items:center;gap:8px;margin-top:12px;margin-bottom:12px;padding:10px 14px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;color:#5b21b6;font-size:13px;font-weight:600;text-decoration:none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="16" width="18" height="4" rx="1"/></svg>
                                Alle Tickets dieser Bestellung in einer Ansicht öffnen →
                            </a>
                        <?php endif; ?>
                        <div class="tix-mt-tickets-grid">
                            <?php $ticket_num = 0; foreach ($ev['tickets'] as $t): $ticket_num++; ?>
                                <?php self::render_ticket_card($t, $ev, $ticket_num, $od); ?>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif (!$is_paid): ?>
                        <?php self::render_pending_box($od); ?>
                    <?php endif; ?>
                </div>

                <div class="tix-mt-card-footer">
                    <?php if (!empty($od['is_transfer'])): ?>
                        <span class="tix-mt-order-ref">&#128260; Umgeschrieben am <?php echo esc_html($od['order_date']); ?></span>
                    <?php else: ?>
                        <span class="tix-mt-order-ref">
                            Bestellung <?php echo esc_html($od['order_number'] ?? '#' . (int) $od['order_id']); ?>
                            <?php if (!empty($od['legacy_wc_number'])): ?>
                                <small style="opacity:.7;">(ehemals #<?php echo esc_html($od['legacy_wc_number']); ?>)</small>
                            <?php endif; ?>
                            &middot; <?php echo esc_html($od['order_date']); ?>
                            <?php if (!empty($od['payment_method'])): ?>
                                &middot; <?php echo esc_html($od['payment_method']); ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>

            </div>

        </div>
        <?php
        endforeach;
    }

    /**
     * Einzelne Ticket-Karte rendern
     */
    public static function render_ticket_card($t, $ev, $ticket_num, $od) {
        // Logo + Sponsor aus Settings / Event-Meta ziehen (für Canvas-Render)
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $logo_url = $s['ht_logo_url'] ?? '';
        $sponsor_url = '';
        if (!empty($ev['event_id'])) {
            $sponsor_id = intval(get_post_meta($ev['event_id'], '_tix_ticket_sponsor_image_id', true));
            if ($sponsor_id) {
                $sponsor_url = wp_get_attachment_url($sponsor_id) ?: '';
            } else {
                // Fallback: externe URL, falls kein Attachment gewählt
                $sponsor_url = esc_url_raw((string) get_post_meta($ev['event_id'], '_tix_ticket_sponsor_image_url', true));
            }
        }
        // Accent-Farben aus Settings (Header/Footer vom HTML-Ticket)
        $accent_bg  = $s['ht_header_bg']   ?? '#131020';
        $accent_fg  = $s['ht_header_text'] ?? '#ffffff';

        // Badge + Share + Zuordnung (nur wenn tix_ticket-ID verfügbar)
        $ticket_id = !empty($t['id']) ? intval($t['id']) : 0;
        $ticket_token = '';
        $share_url    = '';
        $assigned_raw = '';
        if ($ticket_id && class_exists('TIX_Tickets')) {
            $ticket_token = TIX_Tickets::ensure_download_token($ticket_id);
            $share_url    = TIX_Tickets::get_online_view_url($ticket_id);
            $assigned_raw = (string) get_post_meta($ticket_id, '_tix_ticket_assigned_name', true);
            // Modal + Assets nur einmal pro Seite emittieren
            TIX_Tickets::render_assign_modal_once();
        }
        ?>
        <div class="tix-mt-tcard"
             data-event="<?php echo esc_attr($ev['event_name']); ?>"
             data-date="<?php echo esc_attr($ev['date_start'] ?? ''); ?>"
             data-doors="<?php echo esc_attr(!empty($ev['time_doors']) ? 'Einlass ' . $ev['time_doors'] : ''); ?>"
             data-time="<?php echo esc_attr(!empty($ev['time_start']) ? 'Beginn ' . $ev['time_start'] : ''); ?>"
             data-location="<?php echo esc_attr($ev['location'] ?? ''); ?>"
             data-type="<?php echo esc_attr($t['type_name'] ?? ''); ?>"
             data-code="<?php echo esc_attr($t['code'] ?? ''); ?>"
             data-num="<?php echo $ticket_num; ?>"
             data-buyer="<?php echo esc_attr($od['buyer_name']); ?>"
             data-email="<?php echo esc_attr($od['buyer_email']); ?>"
             data-thumb="<?php echo esc_attr($ev['thumbnail'] ?? ''); ?>"
             data-logo="<?php echo esc_attr($logo_url); ?>"
             data-sponsor="<?php echo esc_attr($sponsor_url); ?>"
             data-share-url="<?php echo esc_attr($share_url); ?>"
             data-ticket-token="<?php echo esc_attr($ticket_token); ?>"
             data-has-assignment="<?php echo $assigned_raw !== '' ? '1' : '0'; ?>"
             data-assigned-name="<?php echo esc_attr($assigned_raw); ?>"
             data-accent-bg="<?php echo esc_attr($accent_bg); ?>"
             data-accent-fg="<?php echo esc_attr($accent_fg); ?>">
            <div class="tix-mt-tcard-qr">
                <?php if (!empty($t['code'])): ?>
                    <canvas class="tix-mt-qr-canvas" data-qr="<?php echo esc_attr($t['code']); ?>" width="120" height="120"></canvas>
                    <span class="tix-mt-tcard-code"><?php echo esc_html($t['code']); ?></span>
                <?php endif; ?>
            </div>
            <div class="tix-mt-tcard-info">
                <span class="tix-mt-tcard-num">Ticket <?php echo $ticket_num; ?></span>
                <?php if (!empty($t['type_name'])): ?>
                    <span class="tix-mt-tcard-type"><?php echo esc_html($t['type_name']); ?></span>
                <?php endif; ?>
                <span class="tix-mt-tcard-event"><?php echo esc_html($ev['event_name']); ?></span>
                <?php if (!empty($ev['date_start'])): ?>
                    <span class="tix-mt-tcard-date"><?php echo esc_html($ev['date_start']); ?></span>
                <?php endif; ?>

                <?php if ($ticket_id && class_exists('TIX_Tickets')): ?>
                    <div class="tix-mt-tcard-badge-row" style="display:flex;flex-direction:column;gap:6px;margin:8px 0 4px;align-items:center;">
                        <?php echo TIX_Tickets::render_badge_markup($ticket_id); ?>
                        <?php echo TIX_Tickets::render_shared_info_markup($ticket_id); ?>
                    </div>
                <?php endif; ?>
                <div class="tix-mt-tcard-actions">
                    <?php if (!empty($t['download_url'])):
                        $dl_label = (class_exists('TIX_Tickets') && !empty($t['id']))
                            ? TIX_Tickets::ticket_type_label($t['id'])
                            : 'Ticket';
                    ?>
                        <a href="<?php echo esc_url($t['download_url']); ?>" class="tix-mt-tcard-dl" target="_blank">&#8595; <?php echo esc_html($dl_label); ?></a>
                    <?php endif; ?>
                    <button type="button" class="tix-mt-tcard-save" onclick="ehTicketImg(this)">&#128247; Als Bild speichern</button>
                    <button type="button" class="tix-mt-tcard-share" onclick="ehTicketShare(this)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v7a2 2 0 002 2h12a2 2 0 002-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> Teilen</button>
                </div>
                <?php
                // Wallet-Buttons: immer zeigen (Default-Marketing). Echter Download nur wenn ready.
                $_w_apple_ok  = class_exists('TIX_Wallet') && TIX_Wallet::is_apple_ready();
                $_w_google_ok = class_exists('TIX_Wallet') && TIX_Wallet::is_google_ready();
                $_tid = !empty($ticket_id) ? intval($ticket_id) : 0;
                $_apple_href  = ($_w_apple_ok  && $_tid) ? TIX_Wallet::get_apple_pass_url($_tid)  : '';
                $_google_href = ($_w_google_ok && $_tid) ? TIX_Wallet::get_google_save_url($_tid) : '';
                ?>
                <div class="tix-mt-tcard-wallets" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px;">
                    <button type="button" class="tix-mt-tcard-wallet tix-mt-tcard-wallet-apple"
                            onclick="tixWalletShow('apple', this)" data-ticket-id="<?php echo esc_attr($_tid); ?>"<?php if ($_apple_href): ?> data-href="<?php echo esc_url($_apple_href); ?>"<?php endif; ?>
                            style="display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 10px;background:#000;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.1 12.5c0-2.4 2-3.5 2.1-3.6-1.1-1.6-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.9-1.6 0-3.2 1-4 2.4-1.7 3-.4 7.5 1.3 10 .8 1.2 1.8 2.5 3 2.5 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.1.8 1.3 0 2.2-1.2 3-2.5.9-1.4 1.3-2.8 1.3-2.9-.1 0-2.5-.9-2.5-3.9zM14.6 5c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.8 1.1.1 2.2-.6 2.8-1.3z"/></svg>
                        Apple Wallet
                    </button>
                    <button type="button" class="tix-mt-tcard-wallet tix-mt-tcard-wallet-google"
                            onclick="tixWalletShow('google', this)" data-ticket-id="<?php echo esc_attr($_tid); ?>"<?php if ($_google_href): ?> data-href="<?php echo esc_url($_google_href); ?>"<?php endif; ?>
                            style="display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 10px;background:#fff;color:#1f2937;border:1px solid #e5e7eb;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        Google Wallet
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Pending-Box rendern (für unbezahlte Bestellungen)
     */
    private static function render_pending_box($od) {
        ?>
        <div class="tix-mt-pending-box">
            <div class="tix-mt-pending-notice">
                &#9203; Tickets werden nach Zahlungseingang freigeschaltet.
            </div>

            <?php if (in_array($od['payment_method_id'] ?? '', ['bacs', 'bank'], true)): ?>
                <?php $bacs = self::get_bacs_details(); ?>
                <?php if (!empty($bacs)): ?>
                    <div class="tix-mt-bank-details">
                        <span class="tix-mt-bank-heading">Bankverbindung</span>
                        <?php foreach ($bacs as $acc): ?>
                            <div class="tix-mt-bank-account">
                                <?php if (!empty($acc['account_name'])): ?>
                                    <div class="tix-mt-bank-row">
                                        <span class="tix-mt-bank-label">Kontoinhaber</span>
                                        <span class="tix-mt-bank-value"><?php echo esc_html($acc['account_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($acc['bank_name'])): ?>
                                    <div class="tix-mt-bank-row">
                                        <span class="tix-mt-bank-label">Bank</span>
                                        <span class="tix-mt-bank-value"><?php echo esc_html($acc['bank_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($acc['iban'])): ?>
                                    <div class="tix-mt-bank-row">
                                        <span class="tix-mt-bank-label">IBAN</span>
                                        <span class="tix-mt-bank-value"><?php echo esc_html($acc['iban']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($acc['bic'])): ?>
                                    <div class="tix-mt-bank-row">
                                        <span class="tix-mt-bank-label">BIC</span>
                                        <span class="tix-mt-bank-value"><?php echo esc_html($acc['bic']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="tix-mt-bank-ref">
                            <span class="tix-mt-bank-label">Verwendungszweck</span>
                            <span class="tix-mt-bank-value tix-mt-bank-ref-value">Bestellung <?php echo esc_html($od['order_number'] ?? '#' . (int) $od['order_id']); ?></span>
                        </div>
                        <div class="tix-mt-bank-amount">
                            <span class="tix-mt-bank-label">Betrag</span>
                            <span class="tix-mt-bank-value"><?php echo wp_kses_post($od['order_total_formatted']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="tix-mt-pending-info">
                <p>Nach Geldeingang kann es bis zu <strong>48 Stunden</strong> dauern, bis deine Tickets freigeschaltet werden, da dieser Schritt manuell erfolgt.</p>
                <p>Du wirst per E-Mail benachrichtigt, sobald deine Tickets verfügbar sind.</p>
            </div>
        </div>
        <?php
    }

    /**
     * Bankdaten laden — funktioniert mit + ohne WooCommerce.
     * Priorität:
     *   1. Tixomat-Settings (bank_holder/iban/bic/name) — native Quelle
     *   2. WooCommerce BACS-Accounts (Legacy)
     */
    public static function get_bacs_details() {
        // 1. Native Tixomat-Settings
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (!empty($s['bank_iban']) || !empty($s['bank_holder'])) {
            return [[
                'account_name' => $s['bank_holder'] ?? '',
                'bank_name'    => $s['bank_name']   ?? '',
                'iban'         => $s['bank_iban']   ?? '',
                'bic'          => $s['bank_bic']    ?? '',
            ]];
        }
        // 2. WooCommerce BACS — nur wenn WC aktiv
        if (!function_exists('WC') || !function_exists('wc_get_orders')) return [];
        $gateways = WC()->payment_gateways();
        if (!$gateways) return [];
        $all = $gateways->payment_gateways();
        $bacs = isset($all['bacs']) ? $all['bacs'] : null;
        if (!$bacs) return [];
        $accounts = isset($bacs->account_details) ? $bacs->account_details : [];
        if (empty($accounts)) {
            $accounts = get_option('woocommerce_bacs_accounts', []);
        }
        return is_array($accounts) ? $accounts : [];
    }

    /**
     * Shortcode [tix_order_history] – shows native orders for logged-in users.
     */
    public static function render_order_history($atts = []) {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Bitte melde dich an, um deine Bestellungen zu sehen.', 'tixomat') . '</p>';
        }

        if (!class_exists('TIX_Order')) {
            return '<p>' . esc_html__('Bestellhistorie nicht verfügbar.', 'tixomat') . '</p>';
        }

        $user_id = get_current_user_id();
        $user    = get_user_by('id', $user_id);

        // Query native orders by customer_id, then fallback to email
        $orders = TIX_Order::query([
            'customer_id' => $user_id,
            'limit'       => 50,
        ]);
        if (empty($orders) && $user) {
            $orders = TIX_Order::query([
                'email' => $user->user_email,
                'limit' => 50,
            ]);
        }

        // Filter out dual-write orders (wc_order_id > 0)
        $orders = array_filter($orders, function($o) {
            $wc_id = method_exists($o, 'get_wc_order_id') ? $o->get_wc_order_id() : 0;
            return $wc_id == 0;
        });

        if (empty($orders)) {
            return '<p>' . esc_html__('Du hast noch keine Bestellungen.', 'tixomat') . '</p>';
        }

        $s      = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $accent = !empty($s['color_accent']) ? $s['color_accent'] : '#c8ff00';

        ob_start();
        ?>
        <div class="tix-order-history" style="max-width:800px;">
        <?php foreach ($orders as $order) :
            $order_num = method_exists($order, 'get_order_number') ? $order->get_order_number() : $order->get_id();
            $legacy_wc = get_post_meta($order->get_id(), '_tix_legacy_wc_order_number', true);
            $date = method_exists($order, 'get_date_created') && $order->get_date_created()
                ? $order->get_date_created()->format('d.m.Y H:i') : '';
            $status_labels = [
                'completed'  => 'Abgeschlossen',
                'processing' => 'In Bearbeitung',
                'on-hold'    => 'Wartend',
                'cancelled'  => 'Storniert',
                'pending'    => 'Ausstehend',
                'failed'     => 'Fehlgeschlagen',
                'refunded'   => 'Erstattet',
            ];
            $status_label = $status_labels[$order->get_status()] ?? ucfirst($order->get_status());
        ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <div>
                        <strong style="font-size:16px;">Bestellung <?php echo esc_html($order_num); ?></strong>
                        <?php if ($legacy_wc): ?>
                            <small style="color:#9ca3af;font-weight:400;">&middot; ehemals #<?php echo esc_html($legacy_wc); ?></small>
                        <?php endif; ?>
                    </div>
                    <span style="font-size:13px;color:#6b7280;"><?php echo esc_html($date); ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="display:inline-block;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;background:<?php echo $order->get_status() === 'completed' ? '#dcfce7;color:#166534' : '#fef3c7;color:#92400e'; ?>;">
                        <?php echo esc_html($status_label); ?>
                    </span>
                    <strong style="font-size:16px;"><?php echo number_format((float) $order->get_total(), 2, ',', '.'); ?> &euro;</strong>
                </div>
                <?php foreach ($order->get_items() as $item) : ?>
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px solid #f3f4f6;font-size:14px;">
                    <span><?php echo esc_html($item->get_name()); ?> &times; <?php echo esc_html($item->get_quantity()); ?></span>
                    <span><?php echo number_format((float) $item->get_total(), 2, ',', '.'); ?> &euro;</span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
