<?php
/**
 * Tixomat – Modal Ticket Checkout
 * Shortcode: [tix_ticket_modal]
 *
 * 2-Schritt-Modal: Ticket-Auswahl → Checkout (Billing + Payment)
 * Komplett eigenständig – bestehende Shortcodes unberührt.
 */
if (!defined('ABSPATH')) exit;

class TIX_Modal_Checkout {

    public static function init() {
        add_shortcode('tix_ticket_modal', [__CLASS__, 'render']);

        // AJAX: Checkout-Form laden (Step 2)
        add_action('wp_ajax_tix_mc_checkout_form',        [__CLASS__, 'ajax_checkout_form']);
        add_action('wp_ajax_nopriv_tix_mc_checkout_form', [__CLASS__, 'ajax_checkout_form']);
    }

    // ══════════════════════════════════════
    // SHORTCODE RENDER
    // ══════════════════════════════════════

    public static function render($atts) {
        $atts = shortcode_atts([
            'id'        => 0,
            'label'     => 'Tickets kaufen',
            'show_date' => '1',
            'fullwidth' => '0',
        ], $atts);

        $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') return '';

        // Voraussetzungen
        $enabled = get_post_meta($post_id, '_tix_tickets_enabled', true);
        if ($enabled !== '1') return '';

        $presale = get_post_meta($post_id, '_tix_presale_active', true);
        if ($presale !== '1') return '<span class="tix-mc-trigger"><span style="opacity:0.5;font-size:0.9rem;">Vorverkauf beendet</span></span>';

        $categories = get_post_meta($post_id, '_tix_ticket_categories', true);
        if (!is_array($categories) || empty($categories)) return '';

        // Nur Online-Tickets
        $categories = array_filter($categories, fn($c) =>
            ($c['online'] ?? '1') === '1' && empty($c['offline_ticket'])
        );
        if (empty($categories)) return '';

        // Assets
        self::enqueue();

        $tix_s    = tix_get_settings();
        $vat_text = $tix_s['vat_text_selector'] ?? 'inkl. MwSt.';

        $event_title = get_the_title($post_id);
        $date_display = get_post_meta($post_id, '_tix_date_display', true);
        $modal_id = 'tix-mc-' . $post_id . '-' . wp_unique_id();

        // Product IDs vorprimern
        $product_ids = array_filter(array_map(fn($c) => intval($c['product_id'] ?? 0), $categories));
        if (!empty($product_ids)) _prime_post_caches(array_values($product_ids), false, false);

        ob_start();
        ?>
        <?php $fw = $atts['fullwidth'] === '1' ? ' tix-fullwidth' : ''; ?>
        <span class="tix-mc-trigger<?php echo $fw; ?>" data-modal="<?php echo esc_attr($modal_id); ?>">
            <button type="button" class="tix-mc-trigger-btn"><?php echo esc_html($atts['label']); ?></button>
        </span>

        <div class="tix-mc-overlay" id="<?php echo esc_attr($modal_id); ?>" style="display:none;" data-event-id="<?php echo $post_id; ?>">
            <div class="tix-mc-modal">
                <?php // ── Header ── ?>
                <div class="tix-mc-header">
                    <div class="tix-mc-header-info">
                        <h3 class="tix-mc-title">Tickets</h3>
                        <span class="tix-mc-event-name"><?php echo esc_html($event_title); ?></span>
                        <?php if ($atts['show_date'] === '1' && $date_display): ?>
                            <span class="tix-mc-event-date"><?php echo esc_html($date_display); ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="tix-mc-close" aria-label="Schließen">&times;</button>
                </div>

                <?php // ════════════ STEP 1: TICKET-AUSWAHL ════════════ ?>
                <div class="tix-mc-step tix-mc-step-1 active">
                    <div class="tix-mc-body">
                        <div class="tix-mc-cats">
                            <?php foreach ($categories as $cat):
                                $product_id = intval($cat['product_id'] ?? 0);
                                $price      = floatval($cat['price']);
                                $sale_price = $cat['sale_price'] ?? '';
                                $has_sale   = ($sale_price !== '' && $sale_price !== null && $sale_price !== false);
                                $name       = esc_html($cat['name']);
                                $desc       = esc_html($cat['description'] ?? '');
                                $qty_max    = intval($cat['qty'] ?? 100);

                                // Phase Pricing
                                $active_phase = null;
                                if (class_exists('TIX_Metabox')) {
                                    $active_phase = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
                                }
                                if ($active_phase) {
                                    $phase_price = floatval($active_phase['price']);
                                    if ($phase_price < $price) {
                                        $effective = $phase_price;
                                        $has_sale  = true;
                                        $sale_price = $phase_price;
                                    } else {
                                        $effective = $phase_price;
                                        $price     = $phase_price;
                                        $has_sale  = false;
                                    }
                                } else {
                                    $effective = $has_sale ? floatval($sale_price) : $price;
                                }

                                // Stock
                                $in_stock = true;
                                if ($product_id) {
                                    $product = wc_get_product($product_id);
                                    if ($product) $in_stock = $product->is_in_stock();
                                }
                                if (!$in_stock) continue;
                            ?>
                            <div class="tix-mc-cat"
                                 data-product-id="<?php echo $product_id; ?>"
                                 data-price="<?php echo $effective; ?>"
                                 data-max="<?php echo $qty_max; ?>">
                                <div class="tix-mc-cat-info">
                                    <span class="tix-mc-cat-name"><?php echo $name; ?></span>
                                    <?php if ($desc): ?><span class="tix-mc-cat-desc"><?php echo $desc; ?></span><?php endif; ?>
                                    <span class="tix-mc-cat-price">
                                        <?php if ($has_sale): ?>
                                            <span class="tix-mc-price-sale"><?php echo number_format($effective, 2, ',', '.'); ?>&nbsp;€</span>
                                            <span class="tix-mc-price-old"><?php echo number_format($price, 2, ',', '.'); ?>&nbsp;€</span>
                                        <?php else: ?>
                                            <?php echo number_format($price, 2, ',', '.'); ?>&nbsp;€
                                        <?php endif; ?>
                                        <span class="tix-mc-vat"><?php echo esc_html($vat_text); ?></span>
                                    </span>
                                </div>
                                <div class="tix-mc-cat-qty">
                                    <button type="button" class="tix-mc-btn tix-mc-minus" aria-label="Weniger">&minus;</button>
                                    <span class="tix-mc-qty-val" data-qty="0">0</span>
                                    <button type="button" class="tix-mc-btn tix-mc-plus" aria-label="Mehr">+</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        // ── Angebote (Bundles, Combos, Mengenrabatt) ──
                        $has_bundles = false;
                        foreach ($categories as $cat) {
                            $bb = intval($cat['bundle_buy'] ?? 0);
                            $bp = intval($cat['bundle_pay'] ?? 0);
                            if ($bb >= 2 && $bp >= 1 && $bp < $bb && empty($cat['offline_ticket'])) {
                                $has_bundles = true;
                                break;
                            }
                        }

                        $combos     = get_post_meta($post_id, '_tix_combo_deals', true);
                        $has_combos = is_array($combos) && !empty($combos);

                        $group_discount = get_post_meta($post_id, '_tix_group_discount', true);
                        $gd_tiers = [];
                        $gd_combine_combo  = false;
                        $gd_combine_bundle = false;
                        if (!empty($group_discount['enabled']) && !empty($group_discount['tiers'])) {
                            $gd_tiers = $group_discount['tiers'];
                            $gd_combine_combo  = !empty($group_discount['combine_combo']);
                            $gd_combine_bundle = !empty($group_discount['combine_bundle']);
                        }
                        $has_gd     = !empty($gd_tiers);
                        $has_offers = $has_bundles || $has_combos || $has_gd;
                        ?>

                        <?php if ($has_offers): ?>
                        <div class="tix-mc-offers-toggle">
                            <button type="button" class="tix-mc-offers-btn">
                                <span class="tix-mc-offers-icon">+</span>
                                Angebote anzeigen
                            </button>
                        </div>

                        <div class="tix-mc-offers" style="display:none;">

                            <?php // ── Bundles ── ?>
                            <?php if ($has_bundles): ?>
                            <div class="tix-mc-offer-section">
                                <div class="tix-mc-offer-heading">🎁 Paketangebote</div>
                                <div class="tix-mc-cats">
                                    <?php foreach ($categories as $cat):
                                        $bb = intval($cat['bundle_buy'] ?? 0);
                                        $bp = intval($cat['bundle_pay'] ?? 0);
                                        if ($bb < 2 || $bp < 1 || $bp >= $bb || !empty($cat['offline_ticket'])) continue;

                                        $product_id = intval($cat['product_id'] ?? 0);
                                        $price      = floatval($cat['price']);
                                        $sale_price = $cat['sale_price'] ?? '';
                                        $has_sale   = ($sale_price !== '' && $sale_price !== null && $sale_price !== false);
                                        $name       = esc_html($cat['name']);
                                        $b_label    = $cat['bundle_label'] ?: $bb . 'er-Paket ' . $name;

                                        $active_phase = null;
                                        if (class_exists('TIX_Metabox')) {
                                            $active_phase = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
                                        }
                                        if ($active_phase) {
                                            $phase_price = floatval($active_phase['price']);
                                            $effective = $phase_price < $price ? $phase_price : $phase_price;
                                            if ($phase_price < $price) { $has_sale = true; $price = floatval($cat['price']); }
                                            else { $price = $phase_price; $has_sale = false; }
                                        } else {
                                            $effective = $has_sale ? floatval($sale_price) : $price;
                                        }

                                        $in_stock = true;
                                        if ($product_id) {
                                            $product = wc_get_product($product_id);
                                            if ($product) $in_stock = $product->is_in_stock();
                                        }
                                        if (!$in_stock) continue;

                                        $b_total = $bp * $effective;
                                        $b_orig  = $bb * $effective;
                                        $b_save  = round((1 - $bp / $bb) * 100);
                                        $b_free  = $bb - $bp;
                                        $qty_max = intval($cat['qty'] ?? 100);
                                        $pkg_max = max(1, floor($qty_max / $bb));
                                    ?>
                                    <div class="tix-mc-cat tix-mc-bundle"
                                         data-product-id="<?php echo $product_id; ?>"
                                         data-price="<?php echo $b_total; ?>"
                                         data-max="<?php echo $pkg_max; ?>"
                                         data-bundle="1"
                                         data-bundle-buy="<?php echo $bb; ?>"
                                         data-bundle-pay="<?php echo $bp; ?>"
                                         data-bundle-label="<?php echo esc_attr($b_label); ?>">
                                        <div class="tix-mc-cat-info">
                                            <span class="tix-mc-cat-name">🎁 <?php echo esc_html($b_label); ?></span>
                                            <span class="tix-mc-cat-price">
                                                <span class="tix-mc-price-sale"><?php echo number_format($b_total, 2, ',', '.'); ?>&nbsp;€</span>
                                                <span class="tix-mc-price-old"><?php echo number_format($b_orig, 2, ',', '.'); ?>&nbsp;€</span>
                                                <span class="tix-mc-vat"><?php echo esc_html($vat_text); ?></span>
                                            </span>
                                            <span class="tix-mc-offer-desc"><?php echo $bb; ?>× &ndash; nur <?php echo $bp; ?> zahlen, <?php echo $b_free; ?> gratis <span class="tix-mc-offer-save"><?php echo $b_save; ?>% sparen</span></span>
                                        </div>
                                        <div class="tix-mc-cat-qty">
                                            <button type="button" class="tix-mc-btn tix-mc-minus" aria-label="Weniger">&minus;</button>
                                            <span class="tix-mc-qty-val" data-qty="0">0</span>
                                            <button type="button" class="tix-mc-btn tix-mc-plus" aria-label="Mehr">+</button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php // ── Mengenrabatt ── ?>
                            <?php if ($has_gd): ?>
                            <div class="tix-mc-offer-section">
                                <div class="tix-mc-offer-heading">👥 Mengenrabatt</div>
                                <div class="tix-mc-gd" data-tiers='<?php echo esc_attr(json_encode($gd_tiers)); ?>' data-combine-bundle="<?php echo $gd_combine_bundle ? '1' : '0'; ?>" data-combine-combo="<?php echo $gd_combine_combo ? '1' : '0'; ?>">
                                    <div class="tix-mc-gd-tiers">
                                        <?php foreach ($gd_tiers as $tier): ?>
                                            <span class="tix-mc-gd-tier">Ab <?php echo $tier['min_qty']; ?> Tickets: <strong><?php echo $tier['percent']; ?>%</strong></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <span class="tix-mc-gd-badge" style="display:none;"></span>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                        <?php endif; ?>

                        <?php // ── Gutscheincode ── ?>
                        <?php if (!empty($tix_s['show_coupon_selector'])): ?>
                        <div class="tix-mc-coupon">
                            <div class="tix-mc-coupon-wrap">
                                <input type="text" class="tix-mc-coupon-code" placeholder="Gutscheincode">
                                <button type="button" class="tix-mc-coupon-btn">Einlösen</button>
                            </div>
                            <div class="tix-mc-coupon-result" style="display:none;"></div>
                        </div>
                        <?php endif; ?>

                        <div class="tix-mc-total">
                            <span class="tix-mc-total-label">Gesamt <span class="tix-mc-vat-note"><?php echo esc_html($vat_text); ?></span></span>
                            <span class="tix-mc-total-price">0,00 €</span>
                        </div>

                        <button type="button" class="tix-mc-next" disabled>
                            <span class="tix-mc-next-text">Weiter</span>
                            <span class="tix-mc-next-loading" style="display:none;">Wird hinzugefügt…</span>
                        </button>
                    </div>
                </div>

                <?php // ════════════ STEP 2: CHECKOUT ════════════ ?>
                <div class="tix-mc-step tix-mc-step-2">
                    <div class="tix-mc-checkout-wrap">
                        <div class="tix-mc-checkout-loading">Checkout wird geladen…</div>
                    </div>
                    <button type="button" class="tix-mc-back">&larr; Zurück zur Ticketauswahl</button>
                </div>

                <div class="tix-mc-message" style="display:none;"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════
    // AJAX: CHECKOUT FORM (STEP 2)
    // ══════════════════════════════════════

    public static function ajax_checkout_form() {
        check_ajax_referer('tix_modal_checkout', 'nonce');

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(['message' => 'WooCommerce nicht verfügbar.']);
            return;
        }

        if (WC()->cart->is_empty()) {
            wp_send_json_error(['message' => 'Warenkorb ist leer.']);
            return;
        }

        $checkout  = WC()->checkout();
        $gateways  = WC()->payment_gateways()->get_available_payment_gateways();
        $is_logged = is_user_logged_in();
        $tix_s     = tix_get_settings();

        $terms_url      = $tix_s['terms_url'] ?? '';
        $privacy_url    = $tix_s['privacy_url'] ?? '';
        $revocation_url = $tix_s['revocation_url'] ?? '';
        $vat_text       = $tix_s['vat_text_checkout'] ?? 'inkl. MwSt.';
        $force_email    = !empty($tix_s['force_email_shipping']);

        ob_start();
        ?>
        <form name="checkout" method="post" class="tix-mc-form" id="tix-mc-form" enctype="multipart/form-data">

            <?php // ── Login ── ?>
            <?php if (!$is_logged): ?>
            <div class="tix-mc-section tix-mc-login-section" id="tix-mc-login-section">
                <div class="tix-mc-login-toggle">
                    <span>Bereits ein Konto?</span>
                    <button type="button" class="tix-mc-link-btn" id="tix-mc-login-toggle">Anmelden</button>
                </div>
                <div class="tix-mc-login-form" id="tix-mc-login-form" style="display:none;">
                    <div class="tix-mc-fields">
                        <div class="tix-mc-field tix-mc-field-half">
                            <label class="tix-mc-label" for="tix_mc_login_email">E-Mail oder Benutzername</label>
                            <input type="text" id="tix_mc_login_email" class="tix-mc-input" autocomplete="username">
                        </div>
                        <div class="tix-mc-field tix-mc-field-half">
                            <label class="tix-mc-label" for="tix_mc_login_pass">Passwort</label>
                            <input type="password" id="tix_mc_login_pass" class="tix-mc-input" autocomplete="current-password">
                        </div>
                    </div>
                    <div class="tix-mc-login-actions">
                        <button type="button" class="tix-mc-btn-login" id="tix-mc-login-btn">Anmelden</button>
                        <span class="tix-mc-login-msg" id="tix-mc-login-msg"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── Billing ── ?>
            <div class="tix-mc-section">
                <h4 class="tix-mc-section-heading">Rechnungsadresse</h4>
                <div class="tix-mc-fields">
                    <?php
                    $billing_fields = $checkout->get_checkout_fields('billing');
                    $keep = ['billing_first_name','billing_last_name','billing_country','billing_address_1','billing_postcode','billing_city','billing_email','billing_phone'];

                    foreach ($billing_fields as $key => $field) {
                        if (!in_array($key, $keep)) continue;
                        $value       = $checkout->get_value($key);
                        $required    = !empty($field['required']);
                        $type        = $field['type'] ?? 'text';
                        $label       = $field['label'] ?? '';
                        $placeholder = $field['placeholder'] ?? '';
                        $field_class = implode(' ', $field['class'] ?? []);

                        $width_class = 'tix-mc-field-full';
                        if (strpos($field_class, 'form-row-first') !== false) $width_class = 'tix-mc-field-half';
                        elseif (strpos($field_class, 'form-row-last') !== false) $width_class = 'tix-mc-field-half';
                        if ($key === 'billing_postcode') $width_class = 'tix-mc-field-third';
                        if ($key === 'billing_city')     $width_class = 'tix-mc-field-twothirds';
                        ?>
                        <div class="tix-mc-field <?php echo $width_class; ?>" data-field="<?php echo esc_attr($key); ?>">
                            <label class="tix-mc-label" for="mc_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?><?php if ($required): ?> <abbr class="tix-mc-req" title="erforderlich">*</abbr><?php endif; ?></label>
                            <?php if ($type === 'country'): ?>
                                <select name="<?php echo esc_attr($key); ?>" id="mc_<?php echo esc_attr($key); ?>" class="tix-mc-input tix-mc-select" <?php echo $required ? 'required' : ''; ?>>
                                    <option value="">Land wählen…</option>
                                    <?php foreach (WC()->countries->get_allowed_countries() as $code => $name): printf('<option value="%s"%s>%s</option>', esc_attr($code), selected($value, $code, false), esc_html($name)); endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="<?php echo esc_attr($type === 'tel' ? 'tel' : ($type === 'email' ? 'email' : 'text')); ?>"
                                       name="<?php echo esc_attr($key); ?>" id="mc_<?php echo esc_attr($key); ?>"
                                       class="tix-mc-input" value="<?php echo esc_attr($value); ?>"
                                       placeholder="<?php echo esc_attr($placeholder); ?>"
                                       <?php echo $required ? 'required' : ''; ?>
                                       autocomplete="<?php echo esc_attr($field['autocomplete'] ?? ''); ?>">
                            <?php endif; ?>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <?php // ── Payment Gateways ── ?>
            <?php if (!empty($gateways)): ?>
            <div class="tix-mc-section">
                <h4 class="tix-mc-section-heading">Zahlungsart</h4>
                <div class="tix-mc-gateways">
                    <?php $first = true; foreach ($gateways as $gw_id => $gateway): ?>
                        <div class="tix-mc-gateway <?php echo $first ? 'tix-mc-gw-active' : ''; ?>" data-gw="<?php echo esc_attr($gw_id); ?>">
                            <label class="tix-mc-gw-label">
                                <input type="radio" name="payment_method" value="<?php echo esc_attr($gw_id); ?>" class="tix-mc-gw-radio" <?php checked($first, true); ?>>
                                <span class="tix-mc-gw-radio-custom"></span>
                                <span class="tix-mc-gw-title"><?php echo esc_html($gateway->get_title()); ?></span>
                                <?php if ($gateway->get_icon()): ?><span class="tix-mc-gw-icon"><?php echo $gateway->get_icon(); ?></span><?php endif; ?>
                            </label>
                            <?php ob_start(); $gateway->payment_fields(); $pf = ob_get_clean(); ?>
                            <?php if (trim($pf)): ?>
                                <div class="tix-mc-gw-fields" <?php echo $first ? '' : 'style="display:none;"'; ?>><?php echo $pf; ?></div>
                            <?php elseif ($gateway->get_description()): ?>
                                <div class="tix-mc-gw-fields" <?php echo $first ? '' : 'style="display:none;"'; ?>><p class="tix-mc-gw-desc"><?php echo wp_kses_post($gateway->get_description()); ?></p></div>
                            <?php endif; ?>
                        </div>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── Summary ── ?>
            <div class="tix-mc-summary">
                <div class="tix-mc-summary-row"><span>Zwischensumme</span><span><?php echo WC()->cart->get_cart_subtotal(); ?></span></div>
                <?php $coupons = WC()->cart->get_applied_coupons(); if (!empty($coupons)): ?>
                <div class="tix-mc-summary-row" style="color:var(--tx-green,#1DB86A)"><span>Rabatt</span><span>&minus;<?php echo wc_price(WC()->cart->get_cart_discount_total()); ?></span></div>
                <?php endif; ?>
                <div class="tix-mc-summary-row tix-mc-summary-total"><span>Gesamt <span class="tix-mc-vat-note"><?php echo esc_html($vat_text); ?></span></span><span><?php echo WC()->cart->get_total(); ?></span></div>
            </div>

            <?php // ── Legal ── ?>
            <div class="tix-mc-legal">
                <h4 class="tix-mc-legal-heading">Rechtliches</h4>
                <div class="tix-mc-legal-checks">
                    <label class="tix-mc-check-label">
                        <input type="checkbox" name="tix_accept_terms" class="tix-mc-check" required>
                        <span class="tix-mc-check-custom"></span>
                        <span>Ich akzeptiere die <?php if ($terms_url): ?><a href="<?php echo esc_url($terms_url); ?>" target="_blank" class="tix-mc-legal-link">Nutzungsbedingungen</a><?php else: ?><u>Nutzungsbedingungen</u><?php endif; ?>.</span>
                    </label>
                    <p class="tix-mc-legal-note">Bitte beachte auch die <?php if ($privacy_url): ?><a href="<?php echo esc_url($privacy_url); ?>" target="_blank" class="tix-mc-legal-link">Datenschutzhinweise</a><?php else: ?><u>Datenschutzhinweise</u><?php endif; ?> und die <?php if ($revocation_url): ?><a href="<?php echo esc_url($revocation_url); ?>" target="_blank" class="tix-mc-legal-link">Widerrufsbelehrung</a><?php else: ?><u>Widerrufsbelehrung</u><?php endif; ?>.</p>
                </div>
            </div>

            <?php // ── Hidden Fields ── ?>
            <?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>
            <input type="hidden" name="ship_to_different_address" value="0">
            <input type="hidden" name="terms" value="1">
            <?php if ($force_email): ?><input type="hidden" name="shipping_method[0]" value="free_shipping:eh"><?php endif; ?>

            <?php // ── Submit ── ?>
            <button type="submit" class="tix-mc-submit" id="tix-mc-submit">
                <span class="tix-mc-submit-text">Jetzt kostenpflichtig bestellen</span>
                <span class="tix-mc-submit-loading" style="display:none;">Bestellung wird verarbeitet…</span>
            </button>
        </form>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(['html' => $html]);
    }

    // ══════════════════════════════════════
    // ENQUEUE
    // ══════════════════════════════════════

    private static function enqueue() {
        if (wp_script_is('tix-modal-checkout', 'enqueued')) return;

        wp_enqueue_style(
            'tix-modal-checkout',
            TIXOMAT_URL . 'assets/css/modal-checkout.css',
            ['tix-google-fonts'],
            TIXOMAT_VERSION
        );

        wp_enqueue_script(
            'tix-modal-checkout',
            TIXOMAT_URL . 'assets/js/modal-checkout.js',
            [],
            TIXOMAT_VERSION,
            true
        );

        wp_localize_script('tix-modal-checkout', 'tixModal', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('tix_add_to_cart'),
            'checkoutNonce' => wp_create_nonce('tix_modal_checkout'),
            'loginNonce'    => wp_create_nonce('tix_update_cart'),
            'wcCheckoutUrl' => add_query_arg('wc-ajax', 'checkout', home_url('/')),
        ]);
    }
}
