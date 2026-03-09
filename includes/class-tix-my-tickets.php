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

    public static function init() {
        add_shortcode('tix_my_tickets', [__CLASS__, 'render']);
    }

    /**
     * Assets laden (nur wenn Shortcode genutzt wird)
     */
    private static function enqueue() {
        wp_enqueue_style(
            'tix-my-tickets',
            TIXOMAT_URL . 'assets/css/my-tickets.css',
            [],
            TIXOMAT_VERSION
        );
        wp_enqueue_script(
            'tix-qr',
            TIXOMAT_URL . 'assets/js/tix-qr.js',
            [],
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
    }

    /**
     * Shortcode rendern
     */
    public static function render($atts = []) {
        if (!class_exists('WooCommerce')) {
            return '<p>WooCommerce ist nicht aktiv.</p>';
        }

        self::enqueue();

        // -- Nicht eingeloggt: Login-Formular --
        if (!is_user_logged_in()) {
            return self::render_login();
        }

        $user_id = get_current_user_id();

        // -- Bestellungen laden --
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => 50,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending'],
        ]);

        if (empty($orders)) {
            return self::render_empty();
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

        // ── Transferierte Tickets (auf diesen User umgeschrieben) ──
        $transferred_to_me = [];

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
        foreach ($tix_transferred as $et) {
            $transferred_to_me[] = [
                'id'           => $et->ID,
                'code'         => get_post_meta($et->ID, '_tix_ticket_code', true) ?: $et->post_title,
                'type_name'    => '',
                'event_id'     => intval(get_post_meta($et->ID, '_tix_ticket_event_id', true)),
                'download_url' => class_exists('TIX_Tickets') ? TIX_Tickets::get_download_url($et->ID) : '',
                'source'       => 'eh',
            ];
            // Kategorie-Name ermitteln
            $eid = intval(get_post_meta($et->ID, '_tix_ticket_event_id', true));
            $ci  = intval(get_post_meta($et->ID, '_tix_ticket_cat_index', true));
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            if (is_array($cats) && isset($cats[$ci])) {
                $transferred_to_me[count($transferred_to_me) - 1]['type_name'] = $cats[$ci]['name'] ?? 'Ticket';
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
            return self::render_empty();
        }

        ob_start();
        if ($debug) {
            echo '<!-- TIX_TICKETS_DEBUG total_orders=' . count($orders)
               . ' upcoming=' . count($upcoming) . ' past=' . count($past)
               . ' skipped=[' . implode(', ', $skipped) . ']'
               . ' user=' . $user_id . ' -->';
        }
        ?>
        <div class="tix-mt" id="tix-my-tickets">

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
     * Login-Ansicht
     */
    private static function render_login() {
        ob_start();
        ?>
        <div class="tix-mt" id="tix-my-tickets">
            <div class="tix-mt-login">
                <div class="tix-mt-login-icon">&#128274;</div>
                <h2 class="tix-mt-login-title">Melde dich an</h2>
                <p class="tix-mt-login-text">Um deine Tickets zu sehen, musst du dich mit deinem Konto anmelden.</p>
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
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Leerer State
     */
    private static function render_empty() {
        ob_start();
        ?>
        <div class="tix-mt" id="tix-my-tickets">
            <div class="tix-mt-empty">
                <div class="tix-mt-empty-icon">&#127915;</div>
                <h2 class="tix-mt-empty-title">Keine Tickets vorhanden</h2>
                <p class="tix-mt-empty-text">Du hast noch keine Tickets gekauft. Entdecke unsere Events!</p>
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

            // Event-Name und Ticket-Type aus Produktname ableiten
            $parts = explode($separator, $product_name, 2);
            if (count($parts) < 2) {
                $parts = explode(' - ', $product_name, 2);
            }
            $event_name = $parts[0] ?? $product_name;
            $type_name  = $parts[1] ?? '';

            // Event-Post finden: Primär via _tix_source_event (direkt), dann _tix_parent_event_id, Fallback per Name
            $event_id = intval(get_post_meta($product_id, '_tix_source_event', true));
            if (!$event_id) {
                $event_id = intval(get_post_meta($product_id, '_tix_parent_event_id', true));
            }
            if (!$event_id) {
                $event_post = self::find_event_by_name($event_name);
                $event_id   = $event_post ? $event_post->ID : 0;
            }

            // TC Event-ID für präzises Matching (jede Kategorie hat eigenes TC-Event)
            $tc_event_id_of_product = get_post_meta($product_id, '_event_name', true);

            // Event-Daten
            $ev_data = self::get_event_display_data($event_id);

            // Tickets für dieses Item finden
            $matching_tickets = self::match_tickets($all_tickets, $event_id, $product_id, $product_name, $type_name, $tc_event_id_of_product);

            // Kombi-Erkennung
            $combo_meta = $item->get_meta('_tix_combo');

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

        return [
            'order_id'       => $order_id,
            'order_date'     => $order->get_date_created() ? $order->get_date_created()->date_i18n('d.m.Y') : '',
            'status'         => $status,
            'status_label'   => isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status),
            'total'          => $order->get_total(),
            'currency'       => $order->get_currency(),
            'events'         => $events,
            'combos'         => $combos,
            'payment_method'    => $order->get_payment_method_title(),
            'payment_method_id' => $order->get_payment_method(),
            'order_total_formatted' => $order->get_formatted_order_total(),
            'buyer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'buyer_email' => $order->get_billing_email(),
        ];
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
                            <strong><?php echo wc_price($combo['combo_price']); ?></strong>
                            <?php if ($combo['qty'] > 1): ?>
                                <span class="tix-mt-combo-qty">&times; <?php echo (int) $combo['qty']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($combo['all_tickets']) && $is_paid): ?>
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
                            Bestellung #<?php echo (int) $od['order_id']; ?> &middot; <?php echo esc_html($od['order_date']); ?>
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
                            <span class="tix-mt-ticket-price"><?php echo wc_price($it['total']); ?></span>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($ev['tickets']) && $is_paid): ?>
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
                            Bestellung #<?php echo (int) $od['order_id']; ?> &middot; <?php echo esc_html($od['order_date']); ?>
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
    private static function render_ticket_card($t, $ev, $ticket_num, $od) {
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
             data-thumb="<?php echo esc_attr($ev['thumbnail'] ?? ''); ?>">
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
                <div class="tix-mt-tcard-actions">
                    <?php if (!empty($t['download_url'])): ?>
                        <a href="<?php echo esc_url($t['download_url']); ?>" class="tix-mt-tcard-dl" target="_blank">&#8595; PDF</a>
                    <?php endif; ?>
                    <button type="button" class="tix-mt-tcard-save" onclick="ehTicketImg(this)">&#128247; Als Bild speichern</button>
                    <button type="button" class="tix-mt-tcard-share" onclick="ehTicketShare(this)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v7a2 2 0 002 2h12a2 2 0 002-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> Teilen</button>
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

            <?php if ($od['payment_method_id'] === 'bacs'): ?>
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
                            <span class="tix-mt-bank-value tix-mt-bank-ref-value">Bestellung #<?php echo (int) $od['order_id']; ?></span>
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
     * BACS-Bankdaten aus WooCommerce laden
     */
    private static function get_bacs_details() {
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
}
