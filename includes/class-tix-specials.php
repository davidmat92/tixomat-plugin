<?php
/**
 * TIX_Specials – Globale Specials-Bibliothek (CPT + Admin + Helpers)
 *
 * Specials sind wiederverwendbare Zusatzprodukte (z.B. "Zahle 30€, trinke für 50€"),
 * die Events zugewiesen und im Checkout als Upsell angeboten werden.
 *
 * @since 1.32.0
 */

if (!defined('ABSPATH')) exit;

class TIX_Specials {

    const CPT = 'tix_special';

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);

        // Admin Metabox auf tix_special CPT
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'save_special'], 10, 2);

        // Admin-Spalten
        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'admin_column_content'], 10, 2);

        // Cart-Preis-Override (Event-spezifischer Preis)
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_price_overrides'], 20);

        // AJAX: Special zum Cart hinzufügen (aus Checkout heraus)
        add_action('wp_ajax_tix_add_special', [__CLASS__, 'ajax_add_special']);
        add_action('wp_ajax_nopriv_tix_add_special', [__CLASS__, 'ajax_add_special']);

    }

    // ══════════════════════════════════════════════
    // CPT Registration
    // ══════════════════════════════════════════════

    public static function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name'               => 'Specials',
                'singular_name'      => 'Special',
                'add_new'            => 'Neues Special',
                'add_new_item'       => 'Neues Special anlegen',
                'edit_item'          => 'Special bearbeiten',
                'new_item'           => 'Neues Special',
                'view_item'          => 'Special ansehen',
                'search_items'       => 'Specials suchen',
                'not_found'          => 'Keine Specials gefunden.',
                'not_found_in_trash' => 'Keine Specials im Papierkorb.',
                'all_items'          => 'Specials',
                'menu_name'          => 'Specials',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'edit.php?post_type=event',
            'menu_icon'    => 'dashicons-star-filled',
            'supports'     => ['title', 'editor', 'thumbnail'],
            'rewrite'      => false,
            'query_var'    => false,
        ]);
    }

    // ══════════════════════════════════════════════
    // Admin Metabox (auf tix_special CPT)
    // ══════════════════════════════════════════════

    public static function add_meta_boxes() {
        add_meta_box(
            'tix_special_settings',
            'Special-Einstellungen',
            [__CLASS__, 'render_meta_box'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public static function render_meta_box($post) {
        wp_nonce_field('tix_save_special', 'tix_special_nonce');

        $price      = get_post_meta($post->ID, '_tix_special_price', true);
        $value      = get_post_meta($post->ID, '_tix_special_value', true);
        $product_id = get_post_meta($post->ID, '_tix_special_product_id', true);
        $qty        = get_post_meta($post->ID, '_tix_special_qty', true);
        $template   = get_post_meta($post->ID, '_tix_special_template', true);

        // Verfügbare Templates laden
        $templates = get_posts([
            'post_type'      => 'tix_ticket_template',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        ?>
        <style>
            .tix-sp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 8px; }
            .tix-sp-field { display: flex; flex-direction: column; gap: 4px; }
            .tix-sp-field label { font-weight: 600; font-size: 13px; }
            .tix-sp-field input, .tix-sp-field select { padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
            .tix-sp-field .description { color: #666; font-size: 12px; margin-top: 2px; }
            .tix-sp-full { grid-column: 1 / -1; }
            .tix-sp-hint { background: #f0f6fc; border: 1px solid #c8d6e5; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; line-height: 1.5; }
        </style>

        <div class="tix-sp-hint">
            Specials sind wiederverwendbare Zusatzprodukte, die beliebig vielen Events zugewiesen werden k&ouml;nnen.
            Jedes Special braucht ein WooCommerce-Produkt f&uuml;r die Warenkorb-Integration.
        </div>

        <div class="tix-sp-grid">
            <div class="tix-sp-field">
                <label for="tix_special_price">Verkaufspreis (&euro;)</label>
                <input type="number" id="tix_special_price" name="tix_special_price"
                       value="<?php echo esc_attr($price); ?>" step="0.01" min="0" placeholder="30.00">
                <span class="description">Was der Kunde zahlt.</span>
            </div>

            <div class="tix-sp-field">
                <label for="tix_special_value">Gegenwert (&euro;) <small style="font-weight:normal;color:#666">(optional)</small></label>
                <input type="number" id="tix_special_value" name="tix_special_value"
                       value="<?php echo esc_attr($value); ?>" step="0.01" min="0" placeholder="50.00">
                <span class="description">Tats&auml;chlicher Wert &ndash; f&uuml;r &ldquo;Spare X%&rdquo;-Anzeige.</span>
            </div>

            <div class="tix-sp-field">
                <label for="tix_special_product_id">WC Product ID</label>
                <input type="number" id="tix_special_product_id" name="tix_special_product_id"
                       value="<?php echo esc_attr($product_id); ?>" min="0" placeholder="z.B. 456">
                <span class="description">ID des WooCommerce Simple Product. Wird f&uuml;r alle Events wiederverwendet.</span>
            </div>

            <div class="tix-sp-field">
                <label for="tix_special_qty">Standard-Mengenlimit</label>
                <input type="number" id="tix_special_qty" name="tix_special_qty"
                       value="<?php echo esc_attr($qty); ?>" min="0" placeholder="0 = unbegrenzt">
                <span class="description">0 oder leer = unbegrenzt. Kann pro Event &uuml;berschrieben werden.</span>
            </div>

            <div class="tix-sp-field tix-sp-full">
                <label for="tix_special_template">Voucher-Template</label>
                <select id="tix_special_template" name="tix_special_template">
                    <option value="">Standard (Event-Template)</option>
                    <?php foreach ($templates as $tpl): ?>
                        <option value="<?php echo $tpl->ID; ?>" <?php selected($template, $tpl->ID); ?>>
                            <?php echo esc_html($tpl->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">Eigenes Ticket-Design f&uuml;r dieses Special. Leer = Standard-Template des Events.</span>
            </div>
        </div>
        <?php
    }

    public static function save_special($post_id, $post) {
        if (!isset($_POST['tix_special_nonce']) || !wp_verify_nonce($_POST['tix_special_nonce'], 'tix_save_special')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $price = floatval($_POST['tix_special_price'] ?? 0);
        update_post_meta($post_id, '_tix_special_price', $price);
        update_post_meta($post_id, '_tix_special_value', floatval($_POST['tix_special_value'] ?? 0));
        update_post_meta($post_id, '_tix_special_qty', intval($_POST['tix_special_qty'] ?? 0));
        update_post_meta($post_id, '_tix_special_template', intval($_POST['tix_special_template'] ?? 0));

        // WC Product – Auto-erstellen wenn leer
        $product_id = intval($_POST['tix_special_product_id'] ?? 0);
        if (!$product_id && $price > 0 && class_exists('WC_Product_Simple')) {
            $product_id = self::create_wc_product($post_id, $post->post_title, $price);
        }
        update_post_meta($post_id, '_tix_special_product_id', $product_id);

        // WC Product mit Special-Meta markieren
        if ($product_id) {
            update_post_meta($product_id, '_tix_is_ticket', 'yes');
            update_post_meta($product_id, '_tix_is_special', 'yes');
            // Preis synchronisieren
            update_post_meta($product_id, '_regular_price', $price);
            update_post_meta($product_id, '_price', $price);
        }
    }

    // ══════════════════════════════════════════════
    // Admin-Spalten
    // ══════════════════════════════════════════════

    public static function admin_columns($columns) {
        $new = [];
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['tix_price']   = 'Preis';
                $new['tix_value']   = 'Wert';
                $new['tix_product'] = 'WC Product';
            }
        }
        return $new;
    }

    public static function admin_column_content($column, $post_id) {
        switch ($column) {
            case 'tix_price':
                $p = get_post_meta($post_id, '_tix_special_price', true);
                echo $p ? number_format((float)$p, 2, ',', '.') . ' €' : '–';
                break;
            case 'tix_value':
                $v = get_post_meta($post_id, '_tix_special_value', true);
                echo $v ? number_format((float)$v, 2, ',', '.') . ' €' : '–';
                break;
            case 'tix_product':
                $pid = get_post_meta($post_id, '_tix_special_product_id', true);
                if ($pid) {
                    echo '<a href="' . get_edit_post_link($pid) . '">#' . $pid . '</a>';
                } else {
                    echo '<span style="color:#999">–</span>';
                }
                break;
        }
    }

    // ══════════════════════════════════════════════
    // Helper-Funktionen
    // ══════════════════════════════════════════════

    /**
     * Alle aktiven (publizierten) Specials laden.
     */
    public static function get_all_active() {
        return get_posts([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
    }

    /**
     * Specials für ein Event laden (mit Overrides aufgelöst).
     * Gibt Array zurück: [special_id => ['post' => WP_Post, 'price' => float, 'value' => float, 'qty' => int, ...]]
     */
    public static function get_event_specials($event_id) {
        $event_specials = get_post_meta($event_id, '_tix_specials', true);
        if (!is_array($event_specials) || empty($event_specials)) return [];

        // Robustheit: wenn WP ein einzelnes assoziatives Array zurückgibt statt Array von Arrays
        if (isset($event_specials['special_id'])) {
            $event_specials = [$event_specials];
        }

        $result = [];
        foreach ($event_specials as $es) {
            if (!is_array($es)) continue;
            $sid = intval($es['special_id'] ?? 0);
            if (!$sid || empty($es['enabled'])) continue;

            $special_post = get_post($sid);
            if (!$special_post || $special_post->post_status !== 'publish') continue;

            $base_price = floatval(get_post_meta($sid, '_tix_special_price', true));
            $base_value = floatval(get_post_meta($sid, '_tix_special_value', true));
            $base_qty   = intval(get_post_meta($sid, '_tix_special_qty', true));
            $product_id = intval(get_post_meta($sid, '_tix_special_product_id', true));
            $template   = get_post_meta($sid, '_tix_special_template', true);

            // Overrides anwenden
            $price = !empty($es['price_override']) ? floatval($es['price_override']) : $base_price;
            $qty   = !empty($es['qty_override']) ? intval($es['qty_override']) : $base_qty;

            $result[$sid] = [
                'post'        => $special_post,
                'special_id'  => $sid,
                'name'        => $special_post->post_title,
                'description' => $special_post->post_content,
                'price'       => $price,
                'value'       => $base_value,
                'qty'         => $qty,
                'product_id'  => $product_id,
                'template'    => $template,
                'image'       => get_post_thumbnail_id($sid),
            ];
        }

        return $result;
    }

    /**
     * Effektiven Preis für ein Special in einem bestimmten Event ermitteln.
     */
    public static function get_effective_price($special_id, $event_id) {
        $event_specials = get_post_meta($event_id, '_tix_specials', true);
        if (!is_array($event_specials)) return floatval(get_post_meta($special_id, '_tix_special_price', true));

        foreach ($event_specials as $es) {
            if (intval($es['special_id'] ?? 0) === $special_id && !empty($es['price_override'])) {
                return floatval($es['price_override']);
            }
        }

        return floatval(get_post_meta($special_id, '_tix_special_price', true));
    }

    /**
     * Ersparnis in Prozent berechnen.
     */
    public static function get_savings_percent($price, $value) {
        if ($value <= 0 || $price >= $value) return 0;
        return round((1 - $price / $value) * 100);
    }

    // ══════════════════════════════════════════════
    // Cart-Preis-Override
    // ══════════════════════════════════════════════

    public static function apply_price_overrides($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_before_calculate_totals') >= 2) return;

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['_tix_special'])) continue;

            $special_id = intval($cart_item['_tix_special_id'] ?? 0);
            $event_id   = intval($cart_item['_tix_event_id'] ?? 0);

            if ($special_id && $event_id) {
                $price = self::get_effective_price($special_id, $event_id);
                if ($price > 0) {
                    $cart_item['data']->set_price($price);
                }
            } elseif ($special_id) {
                $price = floatval(get_post_meta($special_id, '_tix_special_price', true));
                if ($price > 0) {
                    $cart_item['data']->set_price($price);
                }
            }
        }
    }

    // ══════════════════════════════════════════════
    // AJAX: Special zum Cart hinzufügen
    // ══════════════════════════════════════════════

    public static function ajax_add_special() {
        check_ajax_referer('tix_add_special', 'nonce');

        $special_id = intval($_POST['special_id'] ?? 0);
        $event_id   = intval($_POST['event_id'] ?? 0);
        $quantity   = max(1, intval($_POST['quantity'] ?? 1));

        if (!$special_id) {
            wp_send_json_error(['message' => 'Ungültiges Special.']);
        }

        $product_id = intval(get_post_meta($special_id, '_tix_special_product_id', true));
        if (!$product_id) {
            wp_send_json_error(['message' => 'Kein Produkt zugeordnet.']);
        }

        $cart_item_data = [
            '_tix_special'    => 1,
            '_tix_special_id' => $special_id,
            '_tix_event_id'   => $event_id,
        ];

        $result = WC()->cart->add_to_cart($product_id, $quantity, 0, [], $cart_item_data);

        if ($result) {
            WC()->cart->calculate_totals();

            $totals = [
                'total'    => strip_tags(WC()->cart->get_total()),
                'subtotal' => strip_tags(WC()->cart->get_cart_subtotal()),
            ];

            if (class_exists('TIX_Checkout')) {
                $totals['fees_html'] = TIX_Checkout::render_fee_rows();
            }

            $response = [
                'message' => 'Special hinzugefügt.',
                'totals'  => $totals,
            ];

            // Cart-HTML mitliefern für Live-Update
            if (class_exists('TIX_Checkout')) {
                $response['cart_html'] = TIX_Checkout::render_cart_items();
            }

            wp_send_json_success($response);
        } else {
            wp_send_json_error(['message' => 'Konnte nicht hinzugefügt werden.']);
        }
    }

    // ══════════════════════════════════════════════
    // Rendering: Specials im Ticket-Selector
    // ══════════════════════════════════════════════

    /**
     * Rendert die Specials-Sektion für den Ticket-Selector.
     */
    public static function render_selector_section($event_id) {
        if (!tix_get_settings('specials_enabled')) return '';
        if (get_post_meta($event_id, '_tix_specials_in_selector', true) !== '1') return '';

        $specials = self::get_event_specials($event_id);
        if (empty($specials)) return '';

        $vat_text = tix_get_settings('vat_text_selector') ?: 'inkl. MwSt.';

        ob_start();
        ?>
        <div class="tix-sel-specials-section">
            <div class="tix-sel-group-header">Specials</div>
            <?php foreach ($specials as $sp):
                $savings = self::get_savings_percent($sp['price'], $sp['value']);
                $image_url = $sp['image'] ? wp_get_attachment_image_url($sp['image'], 'thumbnail') : '';
                $stock_class = '';
                $soldout = false;

                // Stock prüfen
                if ($sp['qty'] > 0) {
                    $sold = self::get_sold_count($sp['special_id'], $event_id);
                    $remaining = max(0, $sp['qty'] - $sold);
                    if ($remaining <= 0) {
                        $soldout = true;
                        $stock_class = ' tix-sel-soldout';
                    }
                }
            ?>
            <div class="tix-sel-cat tix-sel-special<?php echo $stock_class; ?>"
                 data-product-id="<?php echo esc_attr($sp['product_id']); ?>"
                 data-price="<?php echo esc_attr($sp['price']); ?>"
                 data-special="1"
                 data-special-id="<?php echo esc_attr($sp['special_id']); ?>"
                 data-event-id="<?php echo esc_attr($event_id); ?>">

                <?php if ($image_url): ?>
                <div class="tix-sel-special-image">
                    <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($sp['name']); ?>" loading="lazy">
                </div>
                <?php endif; ?>

                <div class="tix-sel-cat-info">
                    <div class="tix-sel-cat-name"><?php echo esc_html($sp['name']); ?></div>
                    <?php if (!empty($sp['description'])): ?>
                        <div class="tix-sel-cat-desc"><?php echo esc_html(wp_strip_all_tags($sp['description'])); ?></div>
                    <?php endif; ?>
                    <?php if ($savings > 0): ?>
                        <div class="tix-sel-special-badge"><?php echo $savings; ?>% sparen</div>
                    <?php endif; ?>
                </div>

                <div class="tix-sel-cat-price">
                    <?php if ($savings > 0): ?>
                        <span class="tix-sel-special-value"><?php echo number_format($sp['value'], 2, ',', '.'); ?>&nbsp;&euro;</span>
                    <?php endif; ?>
                    <span class="tix-sel-price-regular"><?php echo number_format($sp['price'], 2, ',', '.'); ?>&nbsp;&euro;</span>
                    <span class="tix-sel-vat"><?php echo esc_html($vat_text); ?></span>
                </div>

                <div class="tix-sel-cat-qty">
                    <?php if (!$soldout): ?>
                        <button type="button" class="tix-sel-btn tix-sel-minus" aria-label="Weniger">&minus;</button>
                        <span class="tix-sel-qty-val" data-qty="0">0</span>
                        <button type="button" class="tix-sel-btn tix-sel-plus" aria-label="Mehr">+</button>
                    <?php else: ?>
                        <span class="tix-sel-soldout-label">Ausverkauft</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Rendert die Specials-Upsell-Sektion für den Checkout.
     */
    public static function render_checkout_section() {
        if (!tix_get_settings('specials_enabled')) return;

        // Event-ID aus Cart-Items ermitteln
        $event_id = self::get_event_from_cart();
        if (!$event_id) return;
        if (get_post_meta($event_id, '_tix_specials_in_checkout', true) !== '1') return;

        $specials = self::get_event_specials($event_id);
        if (empty($specials)) return;

        // Bereits im Cart befindliche Special-Mengen ermitteln
        $cart_special_qty = [];
        foreach (WC()->cart->get_cart() as $ci) {
            if (!empty($ci['_tix_special_id'])) {
                $sid = intval($ci['_tix_special_id']);
                $cart_special_qty[$sid] = ($cart_special_qty[$sid] ?? 0) + intval($ci['quantity']);
            }
        }

        $nonce = wp_create_nonce('tix_add_special');
        ?>
        <div class="tix-co-specials" data-nonce="<?php echo $nonce; ?>">
            <h3 class="tix-co-specials-heading">Specials</h3>
            <div class="tix-co-specials-grid">
                <?php foreach ($specials as $sp):
                    $savings = self::get_savings_percent($sp['price'], $sp['value']);
                    $image_url = $sp['image'] ? wp_get_attachment_image_url($sp['image'], 'thumbnail') : '';
                    $in_cart = $cart_special_qty[$sp['special_id']] ?? 0;

                    // Stock prüfen
                    $soldout = false;
                    if ($sp['qty'] > 0) {
                        $sold = self::get_sold_count($sp['special_id'], $event_id);
                        $remaining = max(0, $sp['qty'] - $sold - $in_cart);
                        if ($remaining <= 0) $soldout = true;
                    }
                ?>
                <div class="tix-co-special-card<?php echo $in_cart ? ' tix-co-special-in-cart' : ''; ?><?php echo $soldout ? ' tix-co-special-soldout' : ''; ?>"
                     data-special-id="<?php echo esc_attr($sp['special_id']); ?>"
                     data-event-id="<?php echo esc_attr($event_id); ?>"
                     data-in-cart="<?php echo $in_cart; ?>">
                    <?php if ($image_url): ?>
                    <div class="tix-co-special-image">
                        <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($sp['name']); ?>" loading="lazy">
                    </div>
                    <?php endif; ?>
                    <div class="tix-co-special-info">
                        <strong><?php echo esc_html($sp['name']); ?></strong>
                        <?php if (!empty($sp['description'])): ?>
                            <span class="tix-co-special-desc"><?php echo esc_html(wp_strip_all_tags($sp['description'])); ?></span>
                        <?php endif; ?>
                        <?php if ($savings > 0): ?>
                            <span class="tix-co-special-savings"><?php echo $savings; ?>% sparen</span>
                        <?php endif; ?>
                        <?php if ($in_cart): ?>
                            <span class="tix-co-special-in-cart-badge">&times;<?php echo $in_cart; ?> im Warenkorb</span>
                        <?php endif; ?>
                    </div>
                    <div class="tix-co-special-action">
                        <span class="tix-co-special-price"><?php echo number_format($sp['price'], 2, ',', '.'); ?>&nbsp;&euro;</span>
                        <?php if (!$soldout): ?>
                            <button type="button" class="tix-co-special-add"><?php echo $in_cart ? 'Noch eins' : 'Hinzuf&uuml;gen'; ?></button>
                        <?php else: ?>
                            <span class="tix-co-special-soldout-label">Ausverkauft</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        (function(){
            var wrap = document.querySelector('.tix-co-specials');
            if (!wrap) return;
            var ajaxUrl = (typeof tixCo !== 'undefined' && tixCo.ajaxUrl) ? tixCo.ajaxUrl : '/wp-admin/admin-ajax.php';
            wrap.addEventListener('click', function(e){
                var btn = e.target.closest('.tix-co-special-add');
                if (!btn || btn.disabled) return;
                var card = btn.closest('.tix-co-special-card');
                if (!card) return;
                btn.disabled = true;
                var origText = btn.textContent;
                btn.textContent = '...';
                var fd = new FormData();
                fd.append('action', 'tix_add_special');
                fd.append('nonce', wrap.dataset.nonce);
                fd.append('special_id', card.dataset.specialId);
                fd.append('event_id', card.dataset.eventId);
                fd.append('quantity', '1');
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.success) {
                        // Menge im Badge aktualisieren
                        var newQty = (parseInt(card.dataset.inCart) || 0) + 1;
                        card.dataset.inCart = newQty;
                        card.classList.add('tix-co-special-in-cart');
                        var badge = card.querySelector('.tix-co-special-in-cart-badge');
                        if (badge) {
                            badge.textContent = '\u00d7' + newQty + ' im Warenkorb';
                        } else {
                            var info = card.querySelector('.tix-co-special-info');
                            if (info) {
                                badge = document.createElement('span');
                                badge.className = 'tix-co-special-in-cart-badge';
                                badge.textContent = '\u00d7' + newQty + ' im Warenkorb';
                                info.appendChild(badge);
                            }
                        }
                        btn.disabled = false;
                        btn.textContent = 'Noch eins';
                        // Totals aktualisieren
                        if (data.data.totals) {
                            var t = data.data.totals;
                            if (t.total) {
                                var $t = document.querySelector('.tix-co-total');
                                if ($t) $t.innerHTML = t.total;
                                var $bp = document.querySelector('.tix-co-submit-price');
                                if ($bp) $bp.innerHTML = t.total;
                            }
                            if (t.subtotal) {
                                var $st = document.querySelector('.tix-co-subtotal');
                                if ($st) $st.innerHTML = t.subtotal;
                            }
                            if (t.fees_html !== undefined) {
                                var $f = document.querySelector('.tix-co-fees');
                                if ($f) $f.innerHTML = t.fees_html;
                            }
                        }
                        // Cart-HTML aktualisieren
                        if (data.data.cart_html) {
                            var cartEl = document.getElementById('tix-co-cart');
                            if (cartEl) cartEl.innerHTML = data.data.cart_html;
                        }
                    } else {
                        btn.disabled = false;
                        btn.textContent = origText;
                        alert(data.data && data.data.message ? data.data.message : 'Fehler');
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    btn.textContent = origText;
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Rendert Specials in der Event-Metabox (Specials-Tab).
     */
    public static function render_metabox($post) {
        $all_specials   = self::get_all_active();
        $event_specials = get_post_meta($post->ID, '_tix_specials', true);
        if (!is_array($event_specials)) $event_specials = [];

        // Robustheit: einzelnes assoziatives Array → Array von Arrays
        if (isset($event_specials['special_id'])) {
            $event_specials = [$event_specials];
        }

        // Globale Flags
        $show_in_selector = get_post_meta($post->ID, '_tix_specials_in_selector', true);
        $show_in_checkout = get_post_meta($post->ID, '_tix_specials_in_checkout', true);

        // Index by special_id for quick lookup
        $es_map = [];
        foreach ($event_specials as $es) {
            if (!is_array($es)) continue;
            $es_map[intval($es['special_id'] ?? 0)] = $es;
        }
        ?>
        <div class="tix-section">
            <h3 style="margin:0 0 16px;font-size:15px;font-weight:600">Specials f&uuml;r dieses Event</h3>

            <div style="display:flex;gap:20px;margin-bottom:16px;padding:12px 16px;background:#f0f6fc;border:1px solid #c8d6e5;border-radius:8px">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                    <input type="checkbox" name="tix_specials_in_selector" value="1" <?php checked($show_in_selector, '1'); ?>>
                    <strong>Im Ticket-Selector anzeigen</strong>
                </label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                    <input type="checkbox" name="tix_specials_in_checkout" value="1" <?php checked($show_in_checkout, '1'); ?>>
                    <strong>Im Checkout / Modal anzeigen</strong>
                </label>
            </div>

            <?php if (empty($all_specials)): ?>
                <p style="color:#666;margin:0">
                    Noch keine Specials angelegt.
                    <a href="<?php echo admin_url('post-new.php?post_type=' . self::CPT); ?>" style="font-weight:600">Erstes Special erstellen &rarr;</a>
                </p>
            <?php else: ?>
                <div id="tix-specials-list" style="display:flex;flex-direction:column;gap:8px">
                    <?php foreach ($all_specials as $sp):
                        $sid     = $sp->ID;
                        $active  = isset($es_map[$sid]) && !empty($es_map[$sid]['enabled']);
                        $p_over  = $es_map[$sid]['price_override'] ?? '';
                        $q_over  = $es_map[$sid]['qty_override'] ?? '';
                        $price   = get_post_meta($sid, '_tix_special_price', true);
                        $value   = get_post_meta($sid, '_tix_special_value', true);
                        $pid     = get_post_meta($sid, '_tix_special_product_id', true);
                    ?>
                    <div class="tix-special-row" style="background:#f8f9fa;border:1px solid <?php echo $active ? '#3b82f6' : '#e5e7eb'; ?>;border-radius:8px;padding:12px;transition:border-color .2s">
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;flex:2;min-width:200px">
                                <input type="checkbox" name="tix_specials[<?php echo $sid; ?>][enabled]" value="1"
                                       <?php checked($active); ?>
                                       onchange="this.closest('.tix-special-row').style.borderColor=this.checked?'#3b82f6':'#e5e7eb'">
                                <strong><?php echo esc_html($sp->post_title); ?></strong>
                                <span style="color:#666;font-size:12px">
                                    (<?php echo number_format((float)$price, 2, ',', '.'); ?>&euro;<?php echo $value ? ' / Wert: ' . number_format((float)$value, 2, ',', '.') . '€' : ''; ?>)
                                </span>
                                <?php if (!$pid): ?>
                                    <span style="color:#dc2626;font-size:11px;font-weight:600" title="WC Product fehlt">&#x26A0; Kein Produkt</span>
                                <?php endif; ?>
                            </label>
                            <input type="hidden" name="tix_specials[<?php echo $sid; ?>][special_id]" value="<?php echo $sid; ?>">
                            <div style="display:flex;gap:8px;align-items:end">
                                <div style="width:100px">
                                    <label class="tix-mini-label" style="font-size:11px;color:#666">Preis-Override</label>
                                    <input type="number" name="tix_specials[<?php echo $sid; ?>][price_override]"
                                           value="<?php echo esc_attr($p_over); ?>" step="0.01" min="0"
                                           class="tix-input" style="padding:4px 6px;font-size:13px"
                                           placeholder="<?php echo esc_attr($price); ?>">
                                </div>
                                <div style="width:80px">
                                    <label class="tix-mini-label" style="font-size:11px;color:#666">Mengen-Limit</label>
                                    <input type="number" name="tix_specials[<?php echo $sid; ?>][qty_override]"
                                           value="<?php echo esc_attr($q_over); ?>" min="0"
                                           class="tix-input" style="padding:4px 6px;font-size:13px"
                                           placeholder="unbegr.">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p style="margin:12px 0 0">
                    <a href="<?php echo admin_url('edit.php?post_type=' . self::CPT); ?>" style="font-size:13px">Alle Specials verwalten &rarr;</a>
                    &nbsp;|&nbsp;
                    <a href="<?php echo admin_url('post-new.php?post_type=' . self::CPT); ?>" style="font-size:13px">Neues Special anlegen &rarr;</a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Specials für ein Event speichern (aus der Event-Metabox).
     */
    public static function save_event_specials($post_id) {
        // Display-Flags
        update_post_meta($post_id, '_tix_specials_in_selector', !empty($_POST['tix_specials_in_selector']) ? '1' : '');
        update_post_meta($post_id, '_tix_specials_in_checkout', !empty($_POST['tix_specials_in_checkout']) ? '1' : '');

        $raw = $_POST['tix_specials'] ?? [];
        if (!is_array($raw)) {
            update_post_meta($post_id, '_tix_specials', []);
            return;
        }

        $specials = [];
        foreach ($raw as $sid => $data) {
            $sid = intval($sid);
            if (!$sid) continue;

            $specials[] = [
                'special_id'     => $sid,
                'enabled'        => !empty($data['enabled']) ? '1' : '',
                'price_override' => ($data['price_override'] !== '' && $data['price_override'] !== null) ? floatval($data['price_override']) : '',
                'qty_override'   => ($data['qty_override'] !== '' && $data['qty_override'] !== null) ? intval($data['qty_override']) : '',
            ];
        }

        update_post_meta($post_id, '_tix_specials', $specials);
    }

    // ══════════════════════════════════════════════
    // Hilfsfunktionen
    // ══════════════════════════════════════════════

    /**
     * WC Simple Product für ein Special automatisch erstellen.
     */
    private static function create_wc_product($special_id, $title, $price) {
        $product = new \WC_Product_Simple();
        $product->set_name('Special: ' . $title);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_regular_price($price);
        $product->set_price($price);
        $product->set_virtual(true);
        $product->set_sold_individually(false);
        $product->save();

        $pid = $product->get_id();
        if ($pid) {
            update_post_meta($pid, '_tix_is_ticket', 'yes');
            update_post_meta($pid, '_tix_is_special', 'yes');
        }

        return $pid;
    }

    /**
     * Event-ID aus dem aktuellen Warenkorb ermitteln.
     */
    public static function get_event_from_cart() {
        if (!function_exists('WC') || !WC()->cart) return 0;

        foreach (WC()->cart->get_cart() as $ci) {
            // Direkte Event-ID aus Cart-Item-Meta
            if (!empty($ci['_tix_event_id'])) {
                return intval($ci['_tix_event_id']);
            }

            // Über Product-Meta
            $product_id = $ci['product_id'] ?? 0;
            if ($product_id) {
                $event_id = intval(get_post_meta($product_id, '_tix_source_event', true));
                if ($event_id) return $event_id;
            }
        }

        return 0;
    }

    /**
     * Anzahl verkaufter Specials für ein Event zählen.
     */
    public static function get_sold_count($special_id, $event_id) {
        global $wpdb;

        // Zähle Order-Items die dieses Special für dieses Event enthalten
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(oim_qty.meta_value), 0)
             FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_sp
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_ev
                 ON oim_sp.order_item_id = oim_ev.order_item_id
                 AND oim_ev.meta_key = '_tix_event_id'
                 AND oim_ev.meta_value = %d
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
                 ON oim_sp.order_item_id = oim_qty.order_item_id
                 AND oim_qty.meta_key = '_qty'
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
                 ON oim_sp.order_item_id = oi.order_item_id
             INNER JOIN {$wpdb->posts} p
                 ON oi.order_id = p.ID
                 AND p.post_status IN ('wc-completed', 'wc-processing')
             WHERE oim_sp.meta_key = '_tix_special_id'
               AND oim_sp.meta_value = %d",
            $event_id,
            $special_id
        ));

        return intval($count);
    }
}
