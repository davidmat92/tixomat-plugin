<?php
if (!defined('ABSPATH')) exit;

/**
 * Render + AJAX fuer den DP-Checkout.
 *
 * Aktivierungspfad:
 *  - Shortcode [dpc_checkout]
 *  - Page-Override: auf der WooCommerce-Checkout-Seite wird der Page-Content
 *    durch den Shortcode-Output ersetzt (the_content-Filter).
 *
 * CSS-Klassen bleiben absichtlich auf tix-co-*, damit das vorhandene Styling/JS
 * unveraendert greift.
 */
class DPC_Checkout {

    public static function init() {
        add_shortcode('dpc_checkout', [__CLASS__, 'render']);

        add_action('wp_ajax_dpc_update_cart',          [__CLASS__, 'ajax_update_cart']);
        add_action('wp_ajax_nopriv_dpc_update_cart',   [__CLASS__, 'ajax_update_cart']);
        add_action('wp_ajax_dpc_countdown_clear',      [__CLASS__, 'ajax_countdown_clear']);
        add_action('wp_ajax_nopriv_dpc_countdown_clear', [__CLASS__, 'ajax_countdown_clear']);
        add_action('wp_ajax_dpc_apply_coupon',         [__CLASS__, 'ajax_apply_coupon']);
        add_action('wp_ajax_nopriv_dpc_apply_coupon',  [__CLASS__, 'ajax_apply_coupon']);
        add_action('wp_ajax_dpc_remove_coupon',        [__CLASS__, 'ajax_remove_coupon']);
        add_action('wp_ajax_nopriv_dpc_remove_coupon', [__CLASS__, 'ajax_remove_coupon']);
        add_action('wp_ajax_nopriv_dpc_login',         [__CLASS__, 'ajax_login']);

        $settings = dpc_get_settings();

        if (!empty($settings['skip_cart'])) {
            add_filter('woocommerce_add_to_cart_redirect', [__CLASS__, 'skip_cart_redirect']);
            add_filter('wc_add_to_cart_message_html', '__return_empty_string');
        }

        // Guest-Checkout + Sign-up-Buttons immer erzwingen
        add_filter('woocommerce_checkout_registration_enabled', '__return_true');
        add_filter('pre_option_woocommerce_enable_guest_checkout', function () { return 'yes'; });
        add_filter('pre_option_woocommerce_enable_signup_and_login_buttons_on_checkout', function () { return 'yes'; });

        // Page-Override: WC-Checkout-Seite mit unserem Render bespielen
        add_filter('the_content', [__CLASS__, 'override_checkout_page'], 9);
    }

    public static function skip_cart_redirect($url) { return wc_get_checkout_url(); }

    /**
     * Wenn die aktuelle Seite die WooCommerce-Checkout-Seite ist und noch nicht
     * unser Shortcode/Markup enthaelt, ersetzen wir den Content durch unseren Render.
     */
    public static function override_checkout_page($content) {
        if (is_admin() || !function_exists('is_checkout') || !function_exists('WC')) return $content;
        if (!is_checkout()) return $content;
        // Wenn jemand den Shortcode bereits manuell in die Seite gepackt hat, lassen wir den Content unangetastet.
        if (has_shortcode($content, 'dpc_checkout')) return $content;
        // Auch Order-Pay / Order-Received Pages haben is_checkout() === true. Order-Received-Rendering uebernimmt render() selbst,
        // Order-Pay aber NICHT — dort den nativen WC-Pay-Form rendern lassen.
        global $wp;
        if (!empty($wp->query_vars['order-pay'])) return $content;
        return self::render([]);
    }

    // ══════════════════════════════════════════════
    // RENDER
    // ══════════════════════════════════════════════

    public static function render($atts) {
        $atts = shortcode_atts([
            'terms_url'   => '',
            'privacy_url' => '',
            'variant'     => '',
        ], (array) $atts);

        if (!function_exists('WC') || !WC()->cart) {
            return '<p>Checkout nicht verfuegbar.</p>';
        }

        // ── Bestellung abgeschlossen? → Danke-Seite ──
        global $wp;
        if (isset($wp->query_vars['order-received'])) {
            $order_id  = absint($wp->query_vars['order-received']);
            $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
            $order     = wc_get_order($order_id);
            if ($order && $order->get_order_key() === $order_key) {
                wp_enqueue_style('dpc-checkout', DPC_URL . 'assets/css/checkout.css', [], DPC_VERSION);
                return self::render_thankyou($order);
            }
        }

        $s = dpc_get_settings();

        if (WC()->cart->is_empty()) {
            $empty_text = $s['empty_text'];
            $empty_link = $s['empty_link_text'];
            return '<div class="tix-co"><div class="tix-co-empty">'
                . '<p>' . esc_html($empty_text) . '</p>'
                . '<a href="' . esc_url(wc_get_page_permalink('shop') ?: home_url()) . '" class="tix-co-btn-back">' . esc_html($empty_link) . '</a>'
                . '</div></div>';
        }

        self::enqueue();

        $checkout       = WC()->checkout();
        $gateways       = WC()->payment_gateways()->get_available_payment_gateways();
        $is_logged      = is_user_logged_in();
        $coupons        = WC()->cart->get_applied_coupons();
        $terms_url      = $atts['terms_url']   ?: $s['terms_url']      ?: apply_filters('dpc_terms_url', '');
        $privacy_url    = $atts['privacy_url'] ?: $s['privacy_url']    ?: apply_filters('dpc_privacy_url', get_privacy_policy_url() ?: '');
        $revocation_url = $s['revocation_url'] ?: apply_filters('dpc_revocation_url', '');
        $vat_text       = $s['vat_text_checkout'];
        $show_company   = !empty($s['show_company_field']);
        $use_steps      = !empty($s['checkout_steps']);
        $use_countdown  = !empty($s['checkout_countdown']);
        $countdown_min  = intval($s['checkout_countdown_minutes']);
        $variant        = intval($atts['variant'] ?: $s['checkout_btn_variant']) === 2 ? 2 : 1;

        ob_start();
        ?>
        <div class="tix-co<?php echo $use_steps ? ' tix-co-stepped' : ''; ?>" id="tix-co"<?php if ($variant === 2): ?> style="--tix-btn1-bg:var(--tix-btn2-bg,transparent);--tix-btn1-color:var(--tix-btn2-color,inherit);--tix-btn1-hover-bg:var(--tix-btn2-hover-bg,transparent);--tix-btn1-hover-color:var(--tix-btn2-hover-color,inherit);--tix-btn1-radius:var(--tix-btn2-radius,8px);--tix-btn1-border:var(--tix-btn2-border,1px solid currentColor);--tix-btn1-font-size:var(--tix-btn2-font-size,0.9rem)"<?php endif; ?>>

            <?php if ($use_countdown): ?>
            <div class="tix-co-countdown" id="tix-co-countdown">
                <div class="tix-co-countdown-track"><div class="tix-co-countdown-bar" id="tix-co-countdown-bar"></div></div>
                <div class="tix-co-countdown-label">
                    <span>Verbleibende Zeit:</span>
                    <span class="tix-co-countdown-time" id="tix-co-countdown-time"><?php printf('%02d:%02d', $countdown_min, 0); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$is_logged): ?>
            <div class="tix-co-section tix-co-login-section" id="tix-co-login-section">
                <div class="tix-co-login-toggle">
                    <span>Bereits ein Konto?</span>
                    <button type="button" class="tix-co-link-btn" id="tix-co-login-toggle">Anmelden</button>
                </div>
                <div class="tix-co-login-form" id="tix-co-login-form" style="display:none;">
                    <div class="tix-co-fields">
                        <div class="tix-co-field tix-co-field-half">
                            <label class="tix-co-label" for="dpc_login_email">E-Mail oder Benutzername</label>
                            <input type="text" id="dpc_login_email" class="tix-co-input" autocomplete="username">
                        </div>
                        <div class="tix-co-field tix-co-field-half">
                            <label class="tix-co-label" for="dpc_login_pass">Passwort</label>
                            <input type="password" id="dpc_login_pass" class="tix-co-input" autocomplete="current-password">
                        </div>
                    </div>
                    <div class="tix-co-login-actions">
                        <button type="button" class="tix-co-btn-login" id="tix-co-login-btn">Anmelden</button>
                        <span class="tix-co-login-msg" id="tix-co-login-msg"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($use_steps): ?>
            <div class="tix-co-stepper" id="tix-co-stepper">
                <div class="tix-co-step-ind tix-co-step-active" data-step="1"><span class="tix-co-step-num">1</span><span class="tix-co-step-label">Warenkorb</span></div>
                <div class="tix-co-step-line"></div>
                <div class="tix-co-step-ind" data-step="2"><span class="tix-co-step-num">2</span><span class="tix-co-step-label">Adresse</span></div>
                <div class="tix-co-step-line"></div>
                <div class="tix-co-step-ind" data-step="3"><span class="tix-co-step-num">3</span><span class="tix-co-step-label">Bezahlung</span></div>
            </div>
            <?php endif; ?>

            <form name="checkout" method="post" class="tix-co-form" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data" id="tix-co-form">

                <?php // ══════════════ STEP 1: WARENKORB ══════════════ ?>
                <div class="tix-co-step-panel<?php echo $use_steps ? ' tix-co-step-visible' : ''; ?>" data-step="1">

                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Dein Warenkorb</h3>
                        <div class="tix-co-cart" id="tix-co-cart"><?php echo self::render_cart_items(); ?></div>
                        <a href="<?php echo esc_url(wc_get_page_permalink('shop') ?: home_url()); ?>" class="tix-co-btn-more">+ Weiter einkaufen</a>
                    </div>

                    <div class="tix-co-section">
                        <div class="tix-co-coupon" id="tix-co-coupon">
                            <div class="tix-co-coupon-applied" id="tix-co-coupon-applied">
                                <?php foreach ($coupons as $code): ?>
                                    <div class="tix-co-coupon-tag"><span class="tix-co-coupon-code"><?php echo esc_html(strtoupper($code)); ?></span><button type="button" class="tix-co-coupon-remove" data-coupon="<?php echo esc_attr($code); ?>" title="Entfernen">✕</button></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="tix-co-coupon-input-wrap">
                                <input type="text" id="tix-co-coupon-code" class="tix-co-input" placeholder="Gutscheincode eingeben">
                                <button type="button" class="tix-co-coupon-btn" id="tix-co-coupon-btn">Einloesen</button>
                            </div>
                            <div class="tix-co-coupon-msg" id="tix-co-coupon-msg" style="display:none;"></div>
                        </div>
                    </div>

                    <div class="tix-co-section">
                        <div class="tix-co-summary tix-co-summary-mini" id="tix-co-summary-mini">
                            <div class="tix-co-summary-row tix-co-discount-row tix-co-coupon-discount-row" <?php echo empty($coupons) ? 'style="display:none;"' : ''; ?>>
                                <span>Rabatt</span>
                                <span class="tix-co-discount"><?php echo !empty($coupons) ? '−' . wc_price(WC()->cart->get_cart_discount_total()) : ''; ?></span>
                            </div>
                            <div class="tix-co-fees"><?php echo self::render_fee_rows(); ?></div>
                            <div class="tix-co-summary-row tix-co-summary-total">
                                <span>Gesamt <span class="tix-co-vat-note"><?php echo esc_html($vat_text); ?></span></span>
                                <span class="tix-co-total"><?php echo WC()->cart->get_total(); ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($use_steps): ?>
                    <div class="tix-co-step-nav"><div></div><button type="button" class="tix-co-step-btn tix-co-step-next" data-goto="2">Weiter zur Adresse →</button></div>
                    <?php endif; ?>
                </div>

                <?php // ══════════════ STEP 2: ADRESSE ══════════════ ?>
                <div class="tix-co-step-panel" data-step="2">
                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Rechnungsadresse</h3>
                        <div class="tix-co-fields">
                            <?php
                            $billing_fields = $checkout->get_checkout_fields('billing');
                            $keep = ['billing_first_name','billing_last_name','billing_country','billing_address_1','billing_postcode','billing_city','billing_email','billing_phone'];

                            foreach ($billing_fields as $key => $field) {
                                if (!in_array($key, $keep, true)) continue;
                                $value       = $checkout->get_value($key);
                                $required    = !empty($field['required']);
                                $type        = $field['type'] ?? 'text';
                                $label       = $field['label'] ?? '';
                                $placeholder = $field['placeholder'] ?? '';
                                $field_class = implode(' ', $field['class'] ?? []);

                                $width_class = 'tix-co-field-full';
                                if (strpos($field_class, 'form-row-first') !== false) $width_class = 'tix-co-field-half';
                                elseif (strpos($field_class, 'form-row-last') !== false) $width_class = 'tix-co-field-half';
                                if ($key === 'billing_postcode') $width_class = 'tix-co-field-third';
                                if ($key === 'billing_city')     $width_class = 'tix-co-field-twothirds';
                                ?>
                                <div class="tix-co-field <?php echo esc_attr($width_class); ?>" data-field="<?php echo esc_attr($key); ?>">
                                    <label class="tix-co-label" for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?><?php if ($required): ?> <abbr class="tix-co-req" title="erforderlich">*</abbr><?php endif; ?></label>
                                    <?php if ($type === 'country'): ?>
                                        <select name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" class="tix-co-input tix-co-select" <?php echo $required ? 'required' : ''; ?>>
                                            <option value="">Land waehlen…</option>
                                            <?php foreach (WC()->countries->get_allowed_countries() as $code => $name): printf('<option value="%s"%s>%s</option>', esc_attr($code), selected($value, $code, false), esc_html($name)); endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'textarea'): ?>
                                        <textarea name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" class="tix-co-input tix-co-textarea" placeholder="<?php echo esc_attr($placeholder); ?>" <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea($value); ?></textarea>
                                    <?php else: ?>
                                        <input type="<?php echo esc_attr($type === 'tel' ? 'tel' : ($type === 'email' ? 'email' : 'text')); ?>"
                                               name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>"
                                               class="tix-co-input" value="<?php echo esc_attr($value); ?>"
                                               placeholder="<?php echo esc_attr($placeholder); ?>"
                                               <?php echo $required ? 'required' : ''; ?>
                                               autocomplete="<?php echo esc_attr($field['autocomplete'] ?? ''); ?>">
                                    <?php endif; ?>
                                </div>
                            <?php }

                            if ($show_company): ?>
                                <div class="tix-co-field tix-co-field-full tix-co-company-wrap">
                                    <button type="button" class="tix-co-link-btn tix-co-company-toggle" id="tix-co-company-toggle">+ Firma hinzufuegen</button>
                                    <div class="tix-co-company-field" id="tix-co-company-field" style="display:none;">
                                        <label class="tix-co-label" for="billing_company">Firma</label>
                                        <input type="text" name="billing_company" id="billing_company" class="tix-co-input"
                                               value="<?php echo esc_attr($checkout->get_value('billing_company')); ?>"
                                               placeholder="Firmenname (optional)" autocomplete="organization">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!$is_logged): ?>
                        <div class="tix-co-create-account">
                            <label class="tix-co-check-label">
                                <input type="checkbox" name="createaccount" id="createaccount" class="tix-co-check" value="1">
                                <span class="tix-co-check-custom"></span>
                                <span>Meine Daten fuer die naechste Bestellung speichern</span>
                            </label>
                            <div class="tix-co-account-password" id="tix-co-account-pw" style="display:none;">
                                <div class="tix-co-field tix-co-field-full" style="margin-top:10px;">
                                    <label class="tix-co-label" for="account_password">Passwort waehlen <abbr class="tix-co-req" title="erforderlich">*</abbr></label>
                                    <input type="password" name="account_password" id="account_password" class="tix-co-input" autocomplete="new-password" placeholder="Min. 8 Zeichen">
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($use_steps): ?>
                    <div class="tix-co-step-nav">
                        <button type="button" class="tix-co-step-btn tix-co-step-back" data-goto="1">← Zurueck</button>
                        <button type="button" class="tix-co-step-btn tix-co-step-next" data-goto="3">Weiter zur Zahlung →</button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php // ══════════════ STEP 3: ZAHLUNG ══════════════ ?>
                <div class="tix-co-step-panel" data-step="3">

                    <?php if (!empty($gateways)): ?>
                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Zahlungsart</h3>
                        <div class="tix-co-gateways">
                            <?php $first = true; foreach ($gateways as $gw_id => $gateway): ?>
                                <div class="tix-co-gateway <?php echo $first ? 'tix-co-gw-active' : ''; ?>" data-gw="<?php echo esc_attr($gw_id); ?>">
                                    <label class="tix-co-gw-label">
                                        <input type="radio" name="payment_method" value="<?php echo esc_attr($gw_id); ?>" class="tix-co-gw-radio" <?php checked($first, true); ?>>
                                        <span class="tix-co-gw-radio-custom"></span>
                                        <span class="tix-co-gw-title"><?php echo esc_html($gateway->get_title()); ?></span>
                                        <?php if ($gateway->get_icon()): ?><span class="tix-co-gw-icon"><?php echo $gateway->get_icon(); ?></span><?php endif; ?>
                                    </label>
                                    <?php ob_start(); $gateway->payment_fields(); $pf = ob_get_clean(); ?>
                                    <?php if (trim($pf)): ?>
                                        <div class="tix-co-gw-fields" <?php echo $first ? '' : 'style="display:none;"'; ?>><?php echo $pf; ?></div>
                                    <?php elseif ($gateway->get_description()): ?>
                                        <div class="tix-co-gw-fields" <?php echo $first ? '' : 'style="display:none;"'; ?>><p class="tix-co-gw-desc"><?php echo wp_kses_post($gateway->get_description()); ?></p></div>
                                    <?php endif; ?>
                                </div>
                            <?php $first = false; endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="tix-co-section">
                        <div class="tix-co-summary" id="tix-co-summary">
                            <div class="tix-co-summary-row"><span>Zwischensumme</span><span class="tix-co-subtotal"><?php echo WC()->cart->get_cart_subtotal(); ?></span></div>
                            <div class="tix-co-summary-row tix-co-discount-row tix-co-coupon-discount-row" <?php echo empty($coupons) ? 'style="display:none;"' : ''; ?>><span>Rabatt</span><span class="tix-co-discount"><?php echo !empty($coupons) ? '−' . wc_price(WC()->cart->get_cart_discount_total()) : ''; ?></span></div>
                            <div class="tix-co-fees" id="tix-co-fees"><?php echo self::render_fee_rows(); ?></div>
                            <?php if (wc_tax_enabled()): ?><div class="tix-co-summary-row"><span>MwSt.</span><span class="tix-co-tax"><?php echo WC()->cart->get_cart_tax(); ?></span></div><?php endif; ?>
                            <div class="tix-co-summary-row tix-co-summary-total"><span>Gesamt <span class="tix-co-vat-note"><?php echo esc_html($vat_text); ?></span></span><span class="tix-co-total"><?php echo WC()->cart->get_total(); ?></span></div>
                        </div>

                        <div class="tix-co-legal">
                            <h4 class="tix-co-legal-heading">Rechtliches</h4>
                            <div class="tix-co-legal-checks">
                                <label class="tix-co-check-label">
                                    <input type="checkbox" name="dpc_accept_terms" id="dpc_accept_terms" class="tix-co-check" required>
                                    <span class="tix-co-check-custom"></span>
                                    <span>Ich akzeptiere die <?php if ($terms_url): ?><a href="<?php echo esc_url($terms_url); ?>" target="_blank" class="tix-co-legal-link">Allgemeinen Geschaeftsbedingungen</a><?php else: ?><u>Allgemeinen Geschaeftsbedingungen</u><?php endif; ?>.</span>
                                </label>
                                <p class="tix-co-legal-note">Bitte beachte auch die <?php if ($privacy_url): ?><a href="<?php echo esc_url($privacy_url); ?>" target="_blank" class="tix-co-legal-link">Datenschutzhinweise</a><?php else: ?><u>Datenschutzhinweise</u><?php endif; ?> und die <?php if ($revocation_url): ?><a href="<?php echo esc_url($revocation_url); ?>" target="_blank" class="tix-co-legal-link">Widerrufsbelehrung</a><?php else: ?><u>Widerrufsbelehrung</u><?php endif; ?>.</p>
                            </div>
                        </div>

                        <?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>
                        <input type="hidden" name="ship_to_different_address" value="0">
                        <input type="hidden" name="terms" value="1">

                        <?php if ($use_steps): ?>
                        <div class="tix-co-step-nav" style="margin-bottom:16px;"><button type="button" class="tix-co-step-btn tix-co-step-back" data-goto="2">← Zurueck</button><div></div></div>
                        <?php endif; ?>

                        <button type="submit" class="tix-co-submit" id="tix-co-submit">
                            <span class="tix-co-submit-text"><?php echo esc_html($s['btn_text_checkout']); ?> · <span class="tix-co-submit-price"><?php echo strip_tags(WC()->cart->get_total()); ?></span></span>
                            <span class="tix-co-submit-loading" style="display:none;">Bestellung wird verarbeitet…</span>
                        </button>
                    </div>
                </div>

                <div class="tix-co-message" id="tix-co-message" style="display:none;"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════════════
    // DANKE-SEITE
    // ══════════════════════════════════════════════

    private static function render_thankyou($order) {
        $order_id           = $order->get_id();
        $status             = $order->get_status();
        $payment_method     = $order->get_payment_method();
        $is_offline_payment = in_array($payment_method, ['bacs', 'cod', 'cheque'], true);

        $s        = dpc_get_settings();
        $vat_text = esc_html($s['vat_text_checkout']);

        ob_start();
        ?>
        <div class="tix-co tix-co-thankyou" id="tix-co">

            <div class="tix-co-ty-banner">
                <div class="tix-co-ty-icon">✓</div>
                <h2 class="tix-co-ty-title">Vielen Dank fuer deine Bestellung!</h2>
                <p class="tix-co-ty-subtitle">
                    Bestellnummer: <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                    <?php if ($order->get_billing_email()): ?>
                        — Bestaetigung an <strong><?php echo esc_html($order->get_billing_email()); ?></strong>
                    <?php endif; ?>
                </p>
            </div>

            <?php // ── BACS / COD / CHEQUE Hinweise + Bankdaten ── ?>
            <?php if ($is_offline_payment && in_array($status, ['on-hold', 'pending', 'processing'], true)): ?>
            <div class="tix-co-section">
                <h3 class="tix-co-heading">Zahlungshinweise</h3>
                <div class="tix-co-ty-payment-notice">

                    <?php if ($payment_method === 'bacs'):
                        $bacs_gateway      = new WC_Gateway_BACS();
                        $bacs_accounts     = get_option('woocommerce_bacs_accounts', []);
                        $bacs_instructions = $bacs_gateway->get_option('instructions', '');
                    ?>
                        <?php if ($bacs_instructions): ?>
                            <p class="tix-co-ty-payment-instructions"><?php echo wp_kses_post(wpautop(wptexturize($bacs_instructions))); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($bacs_accounts)): ?>
                            <div class="tix-co-ty-bank-details">
                                <h4 class="tix-co-ty-bank-heading">Bankverbindung</h4>
                                <?php foreach ($bacs_accounts as $account): ?>
                                    <div class="tix-co-ty-bank-account">
                                        <?php if (!empty($account['bank_name'])): ?>
                                            <div class="tix-co-ty-bank-row"><span class="tix-co-ty-bank-label">Bank</span><span class="tix-co-ty-bank-value"><?php echo esc_html($account['bank_name']); ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['account_name'])): ?>
                                            <div class="tix-co-ty-bank-row"><span class="tix-co-ty-bank-label">Kontoinhaber</span><span class="tix-co-ty-bank-value"><?php echo esc_html($account['account_name']); ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['iban'])): ?>
                                            <div class="tix-co-ty-bank-row"><span class="tix-co-ty-bank-label">IBAN</span><span class="tix-co-ty-bank-value"><?php echo esc_html($account['iban']); ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['bic'])): ?>
                                            <div class="tix-co-ty-bank-row"><span class="tix-co-ty-bank-label">BIC</span><span class="tix-co-ty-bank-value"><?php echo esc_html($account['bic']); ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['sort_code'])): ?>
                                            <div class="tix-co-ty-bank-row"><span class="tix-co-ty-bank-label">Bankleitzahl</span><span class="tix-co-ty-bank-value"><?php echo esc_html($account['sort_code']); ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['account_number'])): ?>
                                            <div class="tix-co-ty-bank-row"><span class="tix-co-ty-bank-label">Kontonummer</span><span class="tix-co-ty-bank-value"><?php echo esc_html($account['account_number']); ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <p class="tix-co-ty-bank-ref">Verwendungszweck: <strong><?php echo esc_html($order->get_order_number()); ?></strong></p>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($payment_method === 'cod'): ?>
                        <p class="tix-co-ty-payment-instructions">Die Zahlung erfolgt bei Lieferung bzw. Abholung.</p>

                    <?php elseif ($payment_method === 'cheque'):
                        $cheque_gateway      = new WC_Gateway_Cheque();
                        $cheque_instructions = $cheque_gateway->get_option('instructions', '');
                    ?>
                        <?php if ($cheque_instructions): ?>
                            <p class="tix-co-ty-payment-instructions"><?php echo wp_kses_post(wpautop(wptexturize($cheque_instructions))); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>

            <?php // ── BESTELLUEBERSICHT ── ?>
            <div class="tix-co-section">
                <h3 class="tix-co-heading">Bestelluebersicht</h3>
                <div class="tix-co-ty-items">
                    <?php foreach ($order->get_items() as $item): ?>
                    <div class="tix-co-ty-item">
                        <span class="tix-co-ty-item-name"><?php echo esc_html($item->get_name()); ?></span>
                        <span class="tix-co-ty-item-qty">× <?php echo intval($item->get_quantity()); ?></span>
                        <span class="tix-co-ty-item-total"><?php echo wc_price($item->get_total()); ?> <span class="tix-co-ty-vat"><?php echo $vat_text; ?></span></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="tix-co-summary">
                    <div class="tix-co-summary-row">
                        <span>Zwischensumme</span>
                        <span><?php echo wc_price($order->get_subtotal()); ?> <span class="tix-co-ty-vat"><?php echo $vat_text; ?></span></span>
                    </div>
                    <?php if ($order->get_total_discount() > 0): ?>
                    <div class="tix-co-summary-row"><span>Rabatt</span><span>−<?php echo wc_price($order->get_total_discount()); ?></span></div>
                    <?php endif; ?>
                    <?php if ($order->get_shipping_total() > 0): ?>
                    <div class="tix-co-summary-row"><span>Versand</span><span><?php echo wc_price($order->get_shipping_total()); ?></span></div>
                    <?php endif; ?>
                    <?php if ($order->get_total_tax() > 0): ?>
                    <div class="tix-co-summary-row"><span>MwSt.</span><span><?php echo wc_price($order->get_total_tax()); ?></span></div>
                    <?php endif; ?>
                    <div class="tix-co-summary-row tix-co-summary-total">
                        <span>Gesamt</span>
                        <span class="tix-co-total"><?php echo wc_price($order->get_total()); ?> <span class="tix-co-ty-vat"><?php echo $vat_text; ?></span></span>
                    </div>
                </div>
            </div>

            <?php // ── DETAILS ── ?>
            <div class="tix-co-section">
                <h3 class="tix-co-heading">Details</h3>
                <div class="tix-co-ty-details">
                    <div class="tix-co-ty-detail">
                        <span class="tix-co-ty-detail-label">Status</span>
                        <span class="tix-co-ty-detail-value tix-co-ty-status tix-co-ty-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(wc_get_order_status_name($status)); ?></span>
                    </div>
                    <div class="tix-co-ty-detail">
                        <span class="tix-co-ty-detail-label">Zahlungsart</span>
                        <span class="tix-co-ty-detail-value"><?php echo esc_html($order->get_payment_method_title()); ?></span>
                    </div>
                    <div class="tix-co-ty-detail">
                        <span class="tix-co-ty-detail-label">Datum</span>
                        <span class="tix-co-ty-detail-value"><?php echo esc_html($order->get_date_created()->date_i18n('d.m.Y, H:i')); ?> Uhr</span>
                    </div>
                    <?php if ($order->get_billing_first_name()): ?>
                    <div class="tix-co-ty-detail">
                        <span class="tix-co-ty-detail-label">Rechnungsadresse</span>
                        <span class="tix-co-ty-detail-value"><?php echo wp_kses_post($order->get_formatted_billing_address()); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tix-co-ty-back">
                <a href="<?php echo esc_url(wc_get_page_permalink('shop') ?: home_url()); ?>" class="tix-co-btn-back">Weiter einkaufen</a>
            </div>

            <?php
            // WC-Standard-Bestelldetails unterdruecken (oben schon dargestellt).
            // BACS-Bankdaten doppelt vermeiden, weil wir die schon eigenstaendig rendern.
            remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
            if ($payment_method === 'bacs') {
                remove_all_actions('woocommerce_thankyou_bacs');
            }

            ob_start();
            do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order_id);
            do_action('woocommerce_thankyou', $order_id);
            $hook_output = ob_get_clean();
            if (trim($hook_output)):
            ?>
            <div class="tix-co-section tix-co-ty-hook-output">
                <?php echo $hook_output; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════════════
    // CART ITEMS
    // ══════════════════════════════════════════════

    public static function render_cart_items() {
        ob_start();
        foreach (WC()->cart->get_cart() as $cart_key => $item) {
            $product = $item['data'];
            if (!$product) continue;
            $qty     = $item['quantity'];
            $name    = $product->get_name();
            $price   = floatval($product->get_price());
            $regular = floatval($product->get_regular_price());
            $sale    = $product->get_sale_price();
            $has_sale = ($sale !== '' && $sale !== null && floatval($sale) < $regular);
            ?>
            <div class="tix-co-item" data-cart-key="<?php echo esc_attr($cart_key); ?>"
                 style="display:flex !important;flex-direction:row !important;align-items:center !important;gap:12px;padding:14px 16px;">
                <div class="tix-co-item-info" style="flex:1;min-width:0;">
                    <span class="tix-co-item-name"><?php echo esc_html($name); ?></span>
                </div>
                <div class="tix-co-item-price" style="flex-shrink:0;white-space:nowrap;text-align:right;min-width:80px;">
                    <?php if ($has_sale): ?>
                        <span class="tix-co-item-price-sale"><?php echo wc_price($price); ?></span>
                        <span class="tix-co-item-price-old"><?php echo wc_price($regular); ?></span>
                    <?php else: ?>
                        <span class="tix-co-item-price-regular"><?php echo wc_price($price); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tix-co-item-qty"
                     style="display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;align-items:center !important;gap:8px;flex-shrink:0;">
                    <button type="button" class="tix-co-qty-btn tix-co-qty-minus" data-key="<?php echo esc_attr($cart_key); ?>" aria-label="Weniger"
                            style="display:inline-flex !important;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;border:1px solid currentColor;background:transparent;color:inherit;font-size:18px;cursor:pointer;padding:0;flex-shrink:0;">−</button>
                    <span class="tix-co-qty-val" style="min-width:24px;text-align:center;font-weight:700;font-size:1rem;"><?php echo intval($qty); ?></span>
                    <button type="button" class="tix-co-qty-btn tix-co-qty-plus" data-key="<?php echo esc_attr($cart_key); ?>" aria-label="Mehr"
                            style="display:inline-flex !important;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;border:1px solid currentColor;background:transparent;color:inherit;font-size:18px;cursor:pointer;padding:0;flex-shrink:0;">+</button>
                </div>
                <button type="button" class="tix-co-item-remove" data-key="<?php echo esc_attr($cart_key); ?>" aria-label="Entfernen" title="Entfernen"
                        style="flex-shrink:0;">✕</button>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * WC-Fees als Zusammenfassungs-Zeilen rendern (z. B. Versand-Aufschlag, Rabatt-Fee).
     */
    public static function render_fee_rows() {
        $fees = WC()->cart->get_fees();
        if (empty($fees)) return '';
        ob_start();
        foreach ($fees as $fee) {
            $amount = floatval($fee->total);
            if ($amount == 0) continue;
            $is_discount = $amount < 0;
            $class = $is_discount ? ' tix-co-discount-row' : '';
            ?>
            <div class="tix-co-summary-row tix-co-fee-row<?php echo $class; ?>">
                <span><?php echo esc_html($fee->name); ?></span>
                <span><?php echo ($is_discount ? '−' : '') . wc_price(abs($amount)); ?></span>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    // ══════════════════════════════════════════════
    // AJAX
    // ══════════════════════════════════════════════

    public static function ajax_update_cart() {
        check_ajax_referer('dpc_update_cart', 'nonce');
        $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
        $action   = sanitize_text_field($_POST['cart_action'] ?? '');
        if (!$cart_key) wp_send_json_error(['message' => 'Ungueltiger Eintrag.']);
        $cart      = WC()->cart;
        $cart_item = $cart->get_cart_item($cart_key);
        if (!$cart_item) wp_send_json_error(['message' => 'Eintrag nicht gefunden.']);

        switch ($action) {
            case 'increase':
                $cart->set_quantity($cart_key, $cart_item['quantity'] + 1);
                break;
            case 'decrease':
                $nq = $cart_item['quantity'] - 1;
                if ($nq <= 0) $cart->remove_cart_item($cart_key);
                else          $cart->set_quantity($cart_key, $nq);
                break;
            case 'remove':
                $cart->remove_cart_item($cart_key);
                break;
        }

        $cart->calculate_totals();
        self::send_cart_json();
    }

    public static function ajax_apply_coupon() {
        check_ajax_referer('dpc_update_cart', 'nonce');
        $code = sanitize_text_field($_POST['coupon_code'] ?? '');
        if (!$code) wp_send_json_error(['message' => 'Bitte gib einen Gutscheincode ein.']);
        $result = WC()->cart->apply_coupon($code);
        WC()->cart->calculate_totals();
        if ($result) {
            $data = self::get_cart_json_data();
            $data['message'] = 'Gutschein wurde angewendet.';
            wp_send_json_success($data);
        }
        $notices = wc_get_notices('error');
        wc_clear_notices();
        $msg = !empty($notices) ? strip_tags(is_array($notices[0]) ? ($notices[0]['notice'] ?? '') : $notices[0]) : 'Ungueltiger Gutscheincode.';
        wp_send_json_error(['message' => $msg]);
    }

    public static function ajax_remove_coupon() {
        check_ajax_referer('dpc_update_cart', 'nonce');
        $code = sanitize_text_field($_POST['coupon_code'] ?? '');
        if ($code) {
            WC()->cart->remove_coupon($code);
            WC()->cart->calculate_totals();
        }
        wp_send_json_success(self::get_cart_json_data());
    }

    public static function ajax_login() {
        check_ajax_referer('dpc_update_cart', 'nonce');
        $user = sanitize_text_field($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        if (!$user || !$pass) wp_send_json_error(['message' => 'Bitte E-Mail und Passwort eingeben.']);
        $result = wp_signon(['user_login' => $user, 'user_password' => $pass, 'remember' => true], is_ssl());
        if (is_wp_error($result)) wp_send_json_error(['message' => 'Anmeldung fehlgeschlagen. Bitte pruefe deine Zugangsdaten.']);
        wp_set_current_user($result->ID);
        wp_send_json_success(['message' => 'Erfolgreich angemeldet!', 'reload' => true]);
    }

    public static function ajax_countdown_clear() {
        check_ajax_referer('dpc_update_cart', 'nonce');
        if (function_exists('WC') && WC()->cart) WC()->cart->empty_cart();
        wp_send_json_success(['cleared' => true]);
    }

    private static function get_cart_json_data() {
        $cart    = WC()->cart;
        $coupons = $cart->get_applied_coupons();
        $coupons_html = '';
        foreach ($coupons as $c) {
            $coupons_html .= '<div class="tix-co-coupon-tag"><span class="tix-co-coupon-code">' . esc_html(strtoupper($c)) . '</span><button type="button" class="tix-co-coupon-remove" data-coupon="' . esc_attr($c) . '" title="Entfernen">✕</button></div>';
        }
        return [
            'html'         => !$cart->is_empty() ? self::render_cart_items() : '',
            'coupons_html' => $coupons_html,
            'fees_html'    => self::render_fee_rows(),
            'subtotal'     => $cart->get_cart_subtotal(),
            'discount'     => !empty($coupons) ? '−' . wc_price($cart->get_cart_discount_total()) : '',
            'has_discount' => !empty($coupons),
            'tax'          => $cart->get_cart_tax(),
            'total'        => $cart->get_total(),
            'count'        => $cart->get_cart_contents_count(),
            'empty'        => $cart->is_empty(),
        ];
    }

    private static function send_cart_json() { wp_send_json_success(self::get_cart_json_data()); }

    private static function enqueue() {
        if (wp_script_is('dpc-checkout', 'enqueued')) return;
        add_filter('woocommerce_is_checkout', '__return_true');

        wp_enqueue_style('dpc-checkout', DPC_URL . 'assets/css/checkout.css', [], DPC_VERSION);
        wp_enqueue_script('dpc-checkout', DPC_URL . 'assets/js/checkout.js', ['jquery'], DPC_VERSION, true);

        $s = dpc_get_settings();
        wp_localize_script('dpc-checkout', 'dpcCo', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'wcCheckoutUrl' => add_query_arg('wc-ajax', 'checkout', home_url('/')),
            'nonce'         => wp_create_nonce('dpc_update_cart'),
            'useSteps'      => !empty($s['checkout_steps']),
            'countdown'     => !empty($s['checkout_countdown']) ? intval($s['checkout_countdown_minutes']) : 0,
        ]);

        $api_key = $s['google_api_key'] ?? '';
        if ($api_key) {
            wp_enqueue_script(
                'dpc-google-places',
                'https://maps.googleapis.com/maps/api/js?key=' . urlencode($api_key) . '&libraries=places&loading=async&callback=dpcInitAutocomplete',
                ['dpc-checkout'], null, true
            );
        }
    }
}
