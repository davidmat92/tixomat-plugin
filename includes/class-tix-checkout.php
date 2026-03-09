<?php
if (!defined('ABSPATH')) exit;

class TIX_Checkout {

    public static function init() {
        add_shortcode('tix_checkout', [__CLASS__, 'render']);

        add_action('wp_ajax_tix_update_cart',         [__CLASS__, 'ajax_update_cart']);
        add_action('wp_ajax_nopriv_tix_update_cart',   [__CLASS__, 'ajax_update_cart']);
        add_action('wp_ajax_tix_countdown_clear',      [__CLASS__, 'ajax_countdown_clear']);
        add_action('wp_ajax_nopriv_tix_countdown_clear', [__CLASS__, 'ajax_countdown_clear']);
        add_action('wp_ajax_tix_apply_coupon',        [__CLASS__, 'ajax_apply_coupon']);
        add_action('wp_ajax_nopriv_tix_apply_coupon',  [__CLASS__, 'ajax_apply_coupon']);
        add_action('wp_ajax_tix_remove_coupon',        [__CLASS__, 'ajax_remove_coupon']);
        add_action('wp_ajax_nopriv_tix_remove_coupon', [__CLASS__, 'ajax_remove_coupon']);
        add_action('wp_ajax_nopriv_tix_login',         [__CLASS__, 'ajax_login']);

        // Kombi-Meta in Bestellpositionen persistieren
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_combo_order_meta'], 10, 4);

        // Newsletter-Subscriber speichern
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'save_newsletter_subscriber'], 10, 3);

        // Abandoned Cart Recovery
        add_action('wp_ajax_tix_capture_cart_email',        [__CLASS__, 'ajax_capture_cart_email']);
        add_action('wp_ajax_nopriv_tix_capture_cart_email', [__CLASS__, 'ajax_capture_cart_email']);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'cancel_abandoned_cart_recovery'], 5, 3);
        add_action('template_redirect',                    [__CLASS__, 'handle_cart_recovery']);
        add_action('tix_send_abandoned_cart_email',         ['TIX_Emails', 'send_abandoned_cart']);
        add_action('tix_expire_abandoned_carts',            [__CLASS__, 'expire_old_abandoned_carts']);

        // Express Checkout
        add_action('wp_ajax_tix_express_checkout', [__CLASS__, 'ajax_express_checkout']);

        // Expire-Cron planen (Action Scheduler muss verfügbar sein)
        if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('tix_expire_abandoned_carts')) {
            as_schedule_recurring_action(time(), DAY_IN_SECONDS, 'tix_expire_abandoned_carts');
        }

        $settings = self::get_settings();

        if (!empty($settings['force_email_shipping'])) {
            add_filter('woocommerce_cart_needs_shipping_address', '__return_false');
            add_filter('woocommerce_cart_needs_shipping',         '__return_false');
            add_filter('woocommerce_package_rates', [__CLASS__, 'force_free_shipping'], 100, 2);
            add_filter('woocommerce_ship_to_different_address_checked', '__return_false');
        }

        if (!empty($settings['skip_cart'])) {
            add_filter('woocommerce_add_to_cart_redirect', [__CLASS__, 'skip_cart_redirect']);
            add_filter('wc_add_to_cart_message_html', '__return_empty_string');
        }

        add_filter('woocommerce_checkout_registration_enabled', '__return_true');
        add_filter('pre_option_woocommerce_enable_guest_checkout', function() { return 'yes'; });
        add_filter('pre_option_woocommerce_enable_signup_and_login_buttons_on_checkout', function() { return 'yes'; });

        add_action('template_redirect', function() {
            if (!function_exists('WC') || !WC()->cart) return;
            global $post;
            if ($post && has_shortcode($post->post_content, 'tix_checkout')) {
                add_filter('woocommerce_is_checkout', '__return_true');
                if (method_exists(WC()->session, 'has_session') && !WC()->session->has_session()) {
                    WC()->session->set_customer_session_cookie(true);
                }
            }
        });
    }

    public static function skip_cart_redirect($url) { return wc_get_checkout_url(); }

    public static function force_free_shipping($rates, $package) {
        $only_tickets = true;
        foreach (WC()->cart->get_cart() as $item) {
            // Legacy: _tc_is_ticket retained for backward compatibility with older products
            $is_ticket = get_post_meta($item['product_id'], '_tc_is_ticket', true) === 'yes'
                     || get_post_meta($item['product_id'], '_tix_is_ticket', true) === 'yes';
            if (!$is_ticket) {
                $only_tickets = false; break;
            }
        }
        if ($only_tickets && !empty($rates)) {
            foreach ($rates as $rate_id => $rate) {
                if ($rate->method_id !== 'free_shipping') unset($rates[$rate_id]);
            }
            if (empty($rates)) {
                $rates['free_shipping:eh'] = new WC_Shipping_Rate('free_shipping:eh', 'Kostenlos per E-Mail', 0, [], 'free_shipping');
            }
        }
        return $rates;
    }

    // ══════════════════════════════════════════════
    // RENDER
    // ══════════════════════════════════════════════

    public static function render($atts) {
        $atts = shortcode_atts(['terms_url' => '', 'privacy_url' => ''], $atts);

        if (!function_exists('WC') || !WC()->cart) return '<p>WooCommerce ist nicht aktiv.</p>';

        // ── Bestellung abgeschlossen? → Danke-Seite ──
        global $wp;
        if (isset($wp->query_vars['order-received'])) {
            $order_id = absint($wp->query_vars['order-received']);
            $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
            $order = wc_get_order($order_id);
            if ($order && $order->get_order_key() === $order_key) {
                wp_enqueue_style('tix-checkout', TIXOMAT_URL . 'assets/css/checkout.css', [], TIXOMAT_VERSION);
                return self::render_thankyou($order);
            }
        }

        $tix_s = tix_get_settings();

        if (WC()->cart->is_empty()) {
            $empty_text = $tix_s['empty_text'] ?? 'Dein Warenkorb ist leer.';
            $empty_link = $tix_s['empty_link_text'] ?? 'Zu den Events';
            return '<div class="tix-co"><div class="tix-co-empty">'
                . '<p>' . esc_html($empty_text) . '</p>'
                . '<a href="' . esc_url(get_post_type_archive_link('event') ?: home_url()) . '" class="tix-co-btn-back">' . esc_html($empty_link) . '</a>'
                . '</div></div>';
        }

        self::enqueue();

        $checkout    = WC()->checkout();
        $gateways    = WC()->payment_gateways()->get_available_payment_gateways();
        $is_logged   = is_user_logged_in();
        $coupons     = WC()->cart->get_applied_coupons();
        $terms_url      = $atts['terms_url']   ?: ($tix_s['terms_url'] ?? '') ?: apply_filters('tix_checkout_terms_url', '');
        $privacy_url    = $atts['privacy_url'] ?: ($tix_s['privacy_url'] ?? '') ?: apply_filters('tix_checkout_privacy_url', get_privacy_policy_url() ?: '');
        $revocation_url = ($tix_s['revocation_url'] ?? '') ?: apply_filters('tix_checkout_revocation_url', '');
        $vat_text    = $tix_s['vat_text_checkout'] ?? 'inkl. MwSt.';
        $show_company = !empty($tix_s['show_company_field']);
        $force_email  = !empty($tix_s['force_email_shipping']);
        $use_steps    = !empty($tix_s['checkout_steps']);
        $use_countdown = !empty($tix_s['checkout_countdown']);
        $countdown_min = intval($tix_s['checkout_countdown_minutes'] ?? 10);

        ob_start();
        ?>
        <div class="tix-co<?php echo $use_steps ? ' tix-co-stepped' : ''; ?>" id="tix-co">

            <?php // ── COUNTDOWN ── ?>
            <?php if ($use_countdown && !WC()->cart->is_empty()): ?>
            <div class="tix-co-countdown" id="tix-co-countdown">
                <div class="tix-co-countdown-track">
                    <div class="tix-co-countdown-bar" id="tix-co-countdown-bar"></div>
                </div>
                <div class="tix-co-countdown-label">
                    <span>Verbleibende Zeit:</span>
                    <span class="tix-co-countdown-time" id="tix-co-countdown-time"><?php printf('%02d:%02d', $countdown_min, 0); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── LOGIN ── ?>
            <?php if (!$is_logged): ?>
            <div class="tix-co-section tix-co-login-section" id="tix-co-login-section">
                <div class="tix-co-login-toggle">
                    <span>Bereits ein Konto?</span>
                    <button type="button" class="tix-co-link-btn" id="tix-co-login-toggle">Anmelden</button>
                </div>
                <div class="tix-co-login-form" id="tix-co-login-form" style="display:none;">
                    <div class="tix-co-fields">
                        <div class="tix-co-field tix-co-field-half">
                            <label class="tix-co-label" for="tix_login_email">E-Mail oder Benutzername</label>
                            <input type="text" id="tix_login_email" class="tix-co-input" autocomplete="username">
                        </div>
                        <div class="tix-co-field tix-co-field-half">
                            <label class="tix-co-label" for="tix_login_pass">Passwort</label>
                            <input type="password" id="tix_login_pass" class="tix-co-input" autocomplete="current-password">
                        </div>
                    </div>
                    <div class="tix-co-login-actions">
                        <button type="button" class="tix-co-btn-login" id="tix-co-login-btn">Anmelden</button>
                        <span class="tix-co-login-msg" id="tix-co-login-msg"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── STEPPER ── ?>
            <?php if ($use_steps): ?>
            <div class="tix-co-stepper" id="tix-co-stepper">
                <div class="tix-co-step-ind tix-co-step-active" data-step="1"><span class="tix-co-step-num">1</span><span class="tix-co-step-label">Tickets</span></div>
                <div class="tix-co-step-line"></div>
                <div class="tix-co-step-ind" data-step="2"><span class="tix-co-step-num">2</span><span class="tix-co-step-label">Adresse</span></div>
                <div class="tix-co-step-line"></div>
                <div class="tix-co-step-ind" data-step="3"><span class="tix-co-step-num">3</span><span class="tix-co-step-label">Bezahlung</span></div>
            </div>
            <?php endif; ?>

            <form name="checkout" method="post" class="tix-co-form" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data" id="tix-co-form">

                <?php // ══════════════ STEP 1: TICKETS ══════════════ ?>
                <div class="tix-co-step-panel<?php echo $use_steps ? ' tix-co-step-visible' : ''; ?>" data-step="1">

                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Deine Tickets</h3>
                        <div class="tix-co-cart" id="tix-co-cart"><?php echo self::render_cart_items(); ?></div>
                        <a href="<?php echo esc_url(get_post_type_archive_link('event') ?: home_url()); ?>" class="tix-co-btn-more">+ Weitere Tickets kaufen</a>
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
                                <button type="button" class="tix-co-coupon-btn" id="tix-co-coupon-btn">Einlösen</button>
                            </div>
                            <div class="tix-co-coupon-msg" id="tix-co-coupon-msg" style="display:none;"></div>
                        </div>
                    </div>

                    <?php // Mini-Zusammenfassung ?>
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
                                if (!in_array($key, $keep)) continue;
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
                                <div class="tix-co-field <?php echo $width_class; ?>" data-field="<?php echo esc_attr($key); ?>">
                                    <label class="tix-co-label" for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?><?php if ($required): ?> <abbr class="tix-co-req" title="erforderlich">*</abbr><?php endif; ?></label>
                                    <?php if ($type === 'country'): ?>
                                        <select name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" class="tix-co-input tix-co-select" <?php echo $required ? 'required' : ''; ?>>
                                            <option value="">Land wählen…</option>
                                            <?php foreach (WC()->countries->get_allowed_countries() as $code => $name): printf('<option value="%s"%s>%s</option>', esc_attr($code), selected($value, $code, false), esc_html($name)); endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'state'): ?>
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
                            <?php } ?>

                            <?php if ($show_company): ?>
                                <div class="tix-co-field tix-co-field-full tix-co-company-wrap">
                                    <button type="button" class="tix-co-link-btn tix-co-company-toggle" id="tix-co-company-toggle">+ Firma hinzufügen</button>
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
                                <span>Meine Daten für die nächste Buchung speichern</span>
                            </label>
                            <div class="tix-co-account-password" id="tix-co-account-pw" style="display:none;">
                                <div class="tix-co-field tix-co-field-full" style="margin-top:10px;">
                                    <label class="tix-co-label" for="account_password">Passwort wählen <abbr class="tix-co-req" title="erforderlich">*</abbr></label>
                                    <input type="password" name="account_password" id="account_password" class="tix-co-input" autocomplete="new-password" placeholder="Min. 8 Zeichen">
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($use_steps): ?>
                    <div class="tix-co-step-nav">
                        <button type="button" class="tix-co-step-btn tix-co-step-back" data-goto="1">← Zurück</button>
                        <button type="button" class="tix-co-step-btn tix-co-step-next" data-goto="3">Weiter zur Zahlung →</button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php // ══════════════ STEP 3: ZAHLUNG ══════════════ ?>
                <div class="tix-co-step-panel" data-step="3">

                    <?php if ($force_email): ?>
                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Versandmethode</h3>
                        <div class="tix-co-shipping-info">
                            <span class="tix-co-shipping-icon">✉</span>
                            <span><?php echo esc_html($tix_s['shipping_text'] ?? 'Kostenloser Versand per E-Mail'); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>

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
                                    <input type="checkbox" name="tix_accept_terms" id="tix_accept_terms" class="tix-co-check" required>
                                    <span class="tix-co-check-custom"></span>
                                    <span>Ich akzeptiere die <?php if ($terms_url): ?><a href="<?php echo esc_url($terms_url); ?>" target="_blank" class="tix-co-legal-link">Nutzungsbedingungen für Ticketkäufer</a><?php else: ?><u>Nutzungsbedingungen für Ticketkäufer</u><?php endif; ?>.</span>
                                </label>
                                <p class="tix-co-legal-note">Bitte beachte auch die <?php if ($privacy_url): ?><a href="<?php echo esc_url($privacy_url); ?>" target="_blank" class="tix-co-legal-link">Datenschutzhinweise</a><?php else: ?><u>Datenschutzhinweise</u><?php endif; ?> und die <?php if ($revocation_url): ?><a href="<?php echo esc_url($revocation_url); ?>" target="_blank" class="tix-co-legal-link">Widerrufsbelehrung</a><?php else: ?><u>Widerrufsbelehrung</u><?php endif; ?>.</p>
                            </div>
                        </div>

                        <?php
                        $nl_settings = TIX_Settings::get();
                        if (!empty($nl_settings['newsletter_enabled'])):
                            $nl_type  = $nl_settings['newsletter_type'] ?? 'email';
                            $nl_label = $nl_settings['newsletter_label'] ?: ($nl_type === 'whatsapp' ? 'Ich möchte den WhatsApp-Newsletter erhalten' : 'Ich möchte den Newsletter per E-Mail erhalten');
                            $nl_customer = WC()->customer;
                            $nl_prefill = '';
                            if ($nl_type === 'email') {
                                $nl_prefill = $nl_customer ? $nl_customer->get_billing_email() : '';
                            } else {
                                $nl_prefill = $nl_customer ? $nl_customer->get_billing_phone() : '';
                            }
                        ?>
                        <div class="tix-co-newsletter">
                            <h4 class="tix-co-newsletter-heading">Newsletter</h4>
                            <label class="tix-co-check-label">
                                <input type="checkbox" name="tix_newsletter_optin" id="tix_newsletter_optin" class="tix-co-check" value="1">
                                <span class="tix-co-check-custom"></span>
                                <span><?php echo esc_html($nl_label); ?></span>
                            </label>
                            <?php $nl_legal = $nl_settings['newsletter_legal'] ?? ''; if ($nl_legal): ?>
                                <p class="tix-co-newsletter-legal"><?php echo esc_html($nl_legal); ?></p>
                            <?php endif; ?>
                            <div class="tix-co-newsletter-field" id="tix-co-newsletter-field" style="display:none;">
                                <?php if ($nl_type === 'email'): ?>
                                    <input type="email" name="tix_newsletter_contact" class="tix-co-input" placeholder="E-Mail-Adresse" value="<?php echo esc_attr($nl_prefill); ?>">
                                <?php else: ?>
                                    <input type="tel" name="tix_newsletter_contact" class="tix-co-input" placeholder="WhatsApp-Nummer" value="<?php echo esc_attr($nl_prefill); ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>
                        <input type="hidden" name="ship_to_different_address" value="0">
                        <input type="hidden" name="terms" value="1">
                        <?php if ($force_email): ?><input type="hidden" name="shipping_method[0]" value="free_shipping:eh"><?php endif; ?>

                        <?php if ($use_steps): ?>
                        <div class="tix-co-step-nav" style="margin-bottom:16px;"><button type="button" class="tix-co-step-btn tix-co-step-back" data-goto="2">← Zurück</button><div></div></div>
                        <?php endif; ?>

                        <button type="submit" class="tix-co-submit" id="tix-co-submit">
                            <span class="tix-co-submit-text"><?php echo esc_html($tix_s['btn_text_checkout'] ?? 'Jetzt kostenpflichtig bestellen'); ?></span>
                            <span class="tix-co-submit-loading" style="display:none;">Bestellung wird verarbeitet…</span>
                        </button>
                    </div>
                </div>

                <div class="tix-co-message" id="tix-co-message" style="display:none;"></div>
            </form>
            <?php echo tix_branding_footer(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════════════
    // DANKE-SEITE
    // ══════════════════════════════════════════════

    private static function render_thankyou($order) {
        $order_id = $order->get_id();
        $status   = $order->get_status();
        $payment_method     = $order->get_payment_method();
        $is_offline_payment = in_array($payment_method, ['bacs', 'cod', 'cheque'], true);

        // Tickets für diese Bestellung finden (Unified Interface)
        $tickets = [];
        if (class_exists('TIX_Tickets')) {
            $unified = TIX_Tickets::get_all_tickets_for_order($order_id);
            foreach ($unified as $ut) {
                $tickets[] = [
                    'id'           => $ut['id'],
                    'code'         => $ut['code'],
                    'type_name'    => $ut['cat_name'],
                    'event_name'   => $ut['event_name'],
                    'download_url' => $ut['download_url'],
                ];
            }
        }

        ob_start();
        ?>
        <div class="tix-co tix-co-thankyou" id="tix-co">

            <?php // ── STATUS-BANNER ── ?>
            <div class="tix-co-ty-banner">
                <div class="tix-co-ty-icon">✓</div>
                <h2 class="tix-co-ty-title">Vielen Dank für deine Bestellung!</h2>
                <p class="tix-co-ty-subtitle">
                    Bestellnummer: <strong>#<?php echo $order_id; ?></strong>
                    <?php if ($order->get_billing_email()): ?>
                        — Bestätigung an <strong><?php echo esc_html($order->get_billing_email()); ?></strong>
                    <?php endif; ?>
                </p>
            </div>

            <?php // ── TICKETS ── ?>
            <?php if (!empty($tickets)): ?>
            <div class="tix-co-section">
                <h3 class="tix-co-heading">Deine Tickets</h3>
                <div class="tix-co-ty-tickets">
                    <?php foreach ($tickets as $t): ?>
                    <div class="tix-co-ty-ticket">
                        <div class="tix-co-ty-ticket-info">
                            <?php if ($t['event_name']): ?>
                                <span class="tix-co-ty-ticket-event"><?php echo esc_html($t['event_name']); ?></span>
                            <?php endif; ?>
                            <span class="tix-co-ty-ticket-type"><?php echo esc_html($t['type_name'] ?: 'Ticket'); ?></span>
                            <?php if ($t['code']): ?>
                                <span class="tix-co-ty-ticket-code"><?php echo esc_html($t['code']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($t['download_url']): ?>
                            <a href="<?php echo esc_url($t['download_url']); ?>" class="tix-co-ty-ticket-dl" target="_blank">
                                <span class="tix-co-ty-dl-icon">↓</span> Download
                            </a>
                        <?php else: ?>
                            <span class="tix-co-ty-ticket-pending">Wird bereitgestellt…</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php // Wenn BACS und noch nicht bezahlt → Bankverbindung auch bei vorhandenen Tickets anzeigen ?>
                <?php if ($payment_method === 'bacs' && in_array($status, ['on-hold', 'pending'], true)):
                    $bacs_gateway_t = new WC_Gateway_BACS();
                    $bacs_accounts_t = get_option('woocommerce_bacs_accounts', []);
                    $bacs_instructions_t = $bacs_gateway_t->get_option('instructions', '');
                ?>
                    <div class="tix-co-ty-payment-notice" style="margin-top: 16px;">
                        <p class="tix-co-ty-tickets-note">
                            Deine Tickets werden nach Zahlungseingang per E-Mail an
                            <strong><?php echo esc_html($order->get_billing_email()); ?></strong> versendet.
                        </p>
                        <?php if ($bacs_instructions_t): ?>
                            <p class="tix-co-ty-payment-instructions"><?php echo wp_kses_post(wpautop(wptexturize($bacs_instructions_t))); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($bacs_accounts_t)): ?>
                            <div class="tix-co-ty-bank-details">
                                <h4 class="tix-co-ty-bank-heading">Bankverbindung</h4>
                                <?php foreach ($bacs_accounts_t as $account): ?>
                                    <div class="tix-co-ty-bank-account">
                                        <?php if (!empty($account['bank_name'])): ?>
                                            <div class="tix-co-ty-bank-row">
                                                <span class="tix-co-ty-bank-label">Bank</span>
                                                <span class="tix-co-ty-bank-value"><?php echo esc_html($account['bank_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['account_name'])): ?>
                                            <div class="tix-co-ty-bank-row">
                                                <span class="tix-co-ty-bank-label">Kontoinhaber</span>
                                                <span class="tix-co-ty-bank-value"><?php echo esc_html($account['account_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['iban'])): ?>
                                            <div class="tix-co-ty-bank-row">
                                                <span class="tix-co-ty-bank-label">IBAN</span>
                                                <span class="tix-co-ty-bank-value"><?php echo esc_html($account['iban']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['bic'])): ?>
                                            <div class="tix-co-ty-bank-row">
                                                <span class="tix-co-ty-bank-label">BIC</span>
                                                <span class="tix-co-ty-bank-value"><?php echo esc_html($account['bic']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['sort_code'])): ?>
                                            <div class="tix-co-ty-bank-row">
                                                <span class="tix-co-ty-bank-label">Bankleitzahl</span>
                                                <span class="tix-co-ty-bank-value"><?php echo esc_html($account['sort_code']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($account['account_number'])): ?>
                                            <div class="tix-co-ty-bank-row">
                                                <span class="tix-co-ty-bank-label">Kontonummer</span>
                                                <span class="tix-co-ty-bank-value"><?php echo esc_html($account['account_number']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <p class="tix-co-ty-bank-ref">
                                    Verwendungszweck: <strong><?php echo esc_html($order->get_order_number()); ?></strong>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
            <?php else: ?>
            <div class="tix-co-section">
                <h3 class="tix-co-heading">Deine Tickets</h3>

                <?php if ($is_offline_payment): ?>

                    <div class="tix-co-ty-payment-notice">
                        <p class="tix-co-ty-tickets-note">
                            Deine Tickets werden nach Zahlungseingang per E-Mail an
                            <strong><?php echo esc_html($order->get_billing_email()); ?></strong> versendet.
                        </p>

                        <?php if ($payment_method === 'bacs'): ?>
                            <?php
                            // BACS Bankdaten aus WooCommerce laden
                            $bacs_gateway = new WC_Gateway_BACS();
                            $bacs_accounts = get_option('woocommerce_bacs_accounts', []);
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
                                                <div class="tix-co-ty-bank-row">
                                                    <span class="tix-co-ty-bank-label">Bank</span>
                                                    <span class="tix-co-ty-bank-value"><?php echo esc_html($account['bank_name']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($account['account_name'])): ?>
                                                <div class="tix-co-ty-bank-row">
                                                    <span class="tix-co-ty-bank-label">Kontoinhaber</span>
                                                    <span class="tix-co-ty-bank-value"><?php echo esc_html($account['account_name']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($account['iban'])): ?>
                                                <div class="tix-co-ty-bank-row">
                                                    <span class="tix-co-ty-bank-label">IBAN</span>
                                                    <span class="tix-co-ty-bank-value"><?php echo esc_html($account['iban']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($account['bic'])): ?>
                                                <div class="tix-co-ty-bank-row">
                                                    <span class="tix-co-ty-bank-label">BIC</span>
                                                    <span class="tix-co-ty-bank-value"><?php echo esc_html($account['bic']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($account['sort_code'])): ?>
                                                <div class="tix-co-ty-bank-row">
                                                    <span class="tix-co-ty-bank-label">Bankleitzahl</span>
                                                    <span class="tix-co-ty-bank-value"><?php echo esc_html($account['sort_code']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($account['account_number'])): ?>
                                                <div class="tix-co-ty-bank-row">
                                                    <span class="tix-co-ty-bank-label">Kontonummer</span>
                                                    <span class="tix-co-ty-bank-value"><?php echo esc_html($account['account_number']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <p class="tix-co-ty-bank-ref">
                                        Verwendungszweck: <strong><?php echo esc_html($order->get_order_number()); ?></strong>
                                    </p>
                                </div>
                            <?php endif; ?>

                        <?php elseif ($payment_method === 'cod'): ?>
                            <p class="tix-co-ty-payment-instructions">Die Zahlung erfolgt bei Abholung bzw. Übergabe.</p>

                        <?php elseif ($payment_method === 'cheque'): ?>
                            <?php
                            $cheque_gateway = new WC_Gateway_Cheque();
                            $cheque_instructions = $cheque_gateway->get_option('instructions', '');
                            ?>
                            <?php if ($cheque_instructions): ?>
                                <p class="tix-co-ty-payment-instructions"><?php echo wp_kses_post(wpautop(wptexturize($cheque_instructions))); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <p class="tix-co-ty-tickets-note">
                        <?php if ($status === 'processing' || $status === 'on-hold'): ?>
                            Deine Tickets werden gerade erstellt und stehen in Kürze zum Download bereit.
                            Du erhältst eine E-Mail, sobald sie fertig sind.
                        <?php elseif ($status === 'completed'): ?>
                            Deine Tickets wurden per E-Mail versendet. Prüfe dein Postfach.
                        <?php else: ?>
                            Deine Tickets werden nach Zahlungseingang bereitgestellt.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
            // Settings für MwSt.-Text
            $tix_s     = tix_get_settings();
            $vat_text = esc_html($tix_s['vat_text_checkout'] ?? 'inkl. MwSt.');
            ?>

            <?php // ── KOSTENSPLIT (Gruppenbestellung) ── ?>
            <?php
            if (class_exists('TIX_Group_Booking')) {
                $cost_split = TIX_Group_Booking::render_cost_split_frontend($order);
                if ($cost_split) {
                    echo '<div class="tix-co-section">' . $cost_split . '</div>';
                }
            }
            ?>

            <?php // ── BESTELLÜBERSICHT ── ?>
            <div class="tix-co-section">
                <h3 class="tix-co-heading">Bestellübersicht</h3>
                <div class="tix-co-ty-items">
                    <?php foreach ($order->get_items() as $item): ?>
                    <div class="tix-co-ty-item">
                        <span class="tix-co-ty-item-name"><?php echo esc_html($item->get_name()); ?></span>
                        <span class="tix-co-ty-item-qty">× <?php echo $item->get_quantity(); ?></span>
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
                    <div class="tix-co-summary-row">
                        <span>Rabatt</span>
                        <span>−<?php echo wc_price($order->get_total_discount()); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($order->get_total_tax() > 0): ?>
                    <div class="tix-co-summary-row">
                        <span>MwSt.</span>
                        <span><?php echo wc_price($order->get_total_tax()); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="tix-co-summary-row tix-co-summary-total">
                        <span>Gesamt</span>
                        <span class="tix-co-total"><?php echo wc_price($order->get_total()); ?> <span class="tix-co-ty-vat"><?php echo $vat_text; ?></span></span>
                    </div>
                </div>
            </div>

            <?php // ── ZAHLUNGSINFO ── ?>
            <div class="tix-co-section">
                <h3 class="tix-co-heading">Details</h3>
                <div class="tix-co-ty-details">
                    <div class="tix-co-ty-detail">
                        <span class="tix-co-ty-detail-label">Status</span>
                        <span class="tix-co-ty-detail-value tix-co-ty-status tix-co-ty-status-<?php echo esc_attr($status); ?>">
                            <?php echo esc_html(wc_get_order_status_name($status)); ?>
                        </span>
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

            <?php // ── CHARITY / SOZIALES PROJEKT ── ?>
            <?php
            $tix_ch_s = tix_get_settings();
            if (!empty($tix_ch_s['charity_enabled'])) {
                $ch_event_ids = [];
                foreach ($order->get_items() as $_ci) {
                    $_cpid = $_ci->get_product_id();
                    $_ceid = get_post_meta($_cpid, '_tix_parent_event_id', true);
                    if ($_ceid && !in_array(intval($_ceid), $ch_event_ids, true)) {
                        $ch_event_ids[] = intval($_ceid);
                    }
                }
                foreach ($ch_event_ids as $_ceid) {
                    if (get_post_meta($_ceid, '_tix_charity_enabled', true) !== '1') continue;
                    $ch_name    = get_post_meta($_ceid, '_tix_charity_name', true);
                    $ch_percent = intval(get_post_meta($_ceid, '_tix_charity_percent', true));
                    $ch_desc    = get_post_meta($_ceid, '_tix_charity_desc', true);
                    $ch_image   = intval(get_post_meta($_ceid, '_tix_charity_image', true));
                    $ch_img_url = $ch_image ? wp_get_attachment_image_url($ch_image, 'thumbnail') : '';
                    if ($ch_name && $ch_percent > 0) {
                        ?>
                        <div class="tix-co-ty-charity">
                            <?php if ($ch_img_url): ?>
                            <img class="tix-co-ty-charity-img" src="<?php echo esc_url($ch_img_url); ?>" alt="<?php echo esc_attr($ch_name); ?>">
                            <?php endif; ?>
                            <div class="tix-co-ty-charity-info">
                                <span class="tix-co-ty-charity-badge">♥ <?php echo $ch_percent; ?>% deines Einkaufs gehen an <?php echo esc_html($ch_name); ?></span>
                                <?php if ($ch_desc): ?>
                                <span class="tix-co-ty-charity-desc"><?php echo esc_html($ch_desc); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                    }
                }
            }
            ?>

            <?php // ── UPSELLING ── ?>
            <?php
            if (class_exists('TIX_Upsell')) {
                echo TIX_Upsell::render_for_thankyou($order);
            }
            ?>

            <?php // ── SPONSOR ── ?>
            <?php
            $tix_s = tix_get_settings();
            if (!empty($tix_s['sponsor_enabled']) && !empty($tix_s['sponsor_image_url'])):
                // Event-Name aus Bestellung ermitteln (über WC Product → EH Event)
                $sponsor_event_name = '';
                foreach ($order->get_items() as $_item) {
                    $_pid = $_item->get_product_id();
                    $_tix_event_id = get_post_meta($_pid, '_tix_parent_event_id', true);
                    if ($_tix_event_id) {
                        $sponsor_event_name = get_the_title($_tix_event_id);
                        break;
                    }
                }
                if (!$sponsor_event_name) {
                    $first_item = current($order->get_items());
                    if ($first_item) $sponsor_event_name = $first_item->get_name();
                }
            ?>
            <div class="tix-co-section tix-co-sponsor">
                <div class="tix-co-sponsor-inner">
                    <img src="<?php echo esc_url($tix_s['sponsor_image_url']); ?>" alt="Sponsor" class="tix-co-sponsor-logo" style="max-width:<?php echo intval($tix_s['sponsor_logo_width'] ?? 30); ?>%">
                    <p class="tix-co-sponsor-text">... wünscht euch viel Spaß bei <strong><?php echo esc_html($sponsor_event_name); ?></strong></p>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── ZURÜCK ── ?>
            <div class="tix-co-ty-back">
                <a href="<?php echo esc_url(get_post_type_archive_link('event') ?: home_url()); ?>" class="tix-co-btn-back">Weitere Events entdecken</a>
            </div>

            <?php
            // WooCommerce Danke-Hooks ausführen (Payment-Bestätigungen)
            // Aber WC-Standard-Bestelldetails unterdrücken (bereits oben dargestellt)
            remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);

            // BACS-Bankdaten KOMPLETT unterdrücken (bereits oben in eigenem Design dargestellt)
            // remove_all_actions ist nötig, da remove_action mit Objekt-Instanz nicht zuverlässig greift
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
        $event_names = [];
        $cart_items = WC()->cart->get_cart();

        // Pre-Scan: Gruppen-Booking? Items nach Member gruppieren
        $is_group = false;
        $member_items = [];
        foreach ($cart_items as $cart_key => $item) {
            if (!empty($item['_tix_group_member'])) {
                $is_group = true;
                $mname = $item['_tix_group_member'];
                $member_items[$mname][$cart_key] = $item;
            }
        }

        // Pre-Scan: Kombi-Gruppen identifizieren
        $combo_groups = [];
        $combo_keys   = [];
        foreach ($cart_items as $cart_key => $item) {
            if (!empty($item['_tix_combo'])) {
                $gid = $item['_tix_combo']['group_id'];
                $combo_groups[$gid]['items'][$cart_key] = $item;
                $combo_groups[$gid]['label'] = $item['_tix_combo']['label'];
                $combo_groups[$gid]['total_price'] = floatval($item['_tix_combo']['total_price']);
                $combo_keys[$cart_key] = true;
            }
        }

        // Gruppenbestellung: Items nach Member gruppiert rendern
        if ($is_group && count($member_items) > 0) {
            foreach ($member_items as $mname => $mitems) {
                $count = 0;
                foreach ($mitems as $mi) $count += $mi['quantity'];
                ?>
                <div class="tix-co-group-header">👤 <?php echo esc_html($mname); ?> (<?php echo $count; ?> <?php echo $count === 1 ? 'Ticket' : 'Tickets'; ?>)</div>
                <?php
                foreach ($mitems as $cart_key => $item) {
                    if (isset($combo_keys[$cart_key])) continue;
                    $product = $item['data']; $qty = $item['quantity']; $name = $product->get_name();
                    $price = floatval($product->get_price());
                    $parts = explode(' – ', $name, 2);
                    if (count($parts) === 2) $event_names[$parts[0]] = true;
                    ?>
                    <div class="tix-co-item" data-cart-key="<?php echo esc_attr($cart_key); ?>"
                         style="display:flex !important;flex-direction:row !important;align-items:center !important;gap:12px;padding:14px 16px;">
                        <div class="tix-co-item-info" style="flex:1;min-width:0;">
                            <span class="tix-co-item-name"><?php echo esc_html($name); ?></span>
                        </div>
                        <div class="tix-co-item-price" style="flex-shrink:0;white-space:nowrap;text-align:right;min-width:80px;">
                            <span class="tix-co-item-price-regular"><?php echo wc_price($price); ?></span>
                        </div>
                        <div class="tix-co-item-qty"
                             style="display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;align-items:center !important;gap:8px;flex-shrink:0;">
                            <span class="tix-co-qty-val" style="min-width:24px;text-align:center;font-weight:700;font-size:1rem;">× <?php echo $qty; ?></span>
                        </div>
                    </div>
                    <?php
                }
            }
            if (count($event_names) > 1) {
                ?>
                <div class="tix-co-multi-event-notice">
                    <span class="tix-co-multi-icon">ℹ</span>
                    Du kaufst Tickets für <strong><?php echo count($event_names); ?> verschiedene Events</strong>.
                </div>
                <?php
            }
            return ob_get_clean();
        }

        // Kombi-Gruppen rendern
        foreach ($combo_groups as $gid => $group) {
            $first_key = array_key_first($group['items']);
            $first_item = $group['items'][$first_key];
            $combo_qty = $first_item['quantity'];
            ?>
            <div class="tix-co-combo-group" data-combo-group="<?php echo esc_attr($gid); ?>">
                <div class="tix-co-item tix-co-combo-header"
                     style="display:flex !important;flex-direction:row !important;align-items:center !important;gap:12px;padding:14px 16px;">
                    <div class="tix-co-item-info" style="flex:1;min-width:0;">
                        <span class="tix-co-item-name">🎫 <?php echo esc_html($group['label']); ?></span>
                        <span class="tix-co-item-bundle-hint">
                            <?php
                            $event_labels = [];
                            foreach ($group['items'] as $ci) {
                                $event_labels[] = esc_html($ci['data']->get_name());
                            }
                            echo implode(' + ', $event_labels);
                            ?>
                        </span>
                    </div>
                    <div class="tix-co-item-price" style="flex-shrink:0;white-space:nowrap;text-align:right;min-width:80px;">
                        <span class="tix-co-item-price-regular"><?php echo wc_price($group['total_price']); ?></span>
                    </div>
                    <div class="tix-co-item-qty"
                         style="display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;align-items:center !important;gap:8px;flex-shrink:0;">
                        <button type="button" class="tix-co-qty-btn tix-co-qty-minus" data-key="<?php echo esc_attr($first_key); ?>" data-combo-group="<?php echo esc_attr($gid); ?>" aria-label="Weniger"
                                style="display:inline-flex !important;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;border:1px solid currentColor;background:transparent;color:inherit;font-size:18px;cursor:pointer;padding:0;flex-shrink:0;">−</button>
                        <span class="tix-co-qty-val" style="min-width:24px;text-align:center;font-weight:700;font-size:1rem;"><?php echo $combo_qty; ?></span>
                        <button type="button" class="tix-co-qty-btn tix-co-qty-plus" data-key="<?php echo esc_attr($first_key); ?>" data-combo-group="<?php echo esc_attr($gid); ?>" aria-label="Mehr"
                                style="display:inline-flex !important;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;border:1px solid currentColor;background:transparent;color:inherit;font-size:18px;cursor:pointer;padding:0;flex-shrink:0;">+</button>
                    </div>
                    <button type="button" class="tix-co-item-remove" data-key="<?php echo esc_attr($first_key); ?>" data-combo-group="<?php echo esc_attr($gid); ?>" aria-label="Entfernen" title="Entfernen"
                            style="flex-shrink:0;">✕</button>
                </div>
            </div>
            <?php
            // Event-Names für Multi-Event-Erkennung
            foreach ($group['items'] as $ci) {
                $parts = explode(' – ', $ci['data']->get_name(), 2);
                if (count($parts) === 2) $event_names[$parts[0]] = true;
            }
        }

        // Reguläre Items (keine Kombi-Items)
        foreach ($cart_items as $cart_key => $item) {
            if (isset($combo_keys[$cart_key])) continue;

            $product = $item['data']; $qty = $item['quantity']; $name = $product->get_name();
            $price = floatval($product->get_price());
            $regular = floatval($product->get_regular_price()); $sale = $product->get_sale_price();
            $has_sale = ($sale !== '' && $sale !== null && floatval($sale) < $regular);

            // Event-Name für Multi-Event-Erkennung
            $parts = explode(' – ', $name, 2);
            if (count($parts) === 2) $event_names[$parts[0]] = true;
            ?>
            <div class="tix-co-item" data-cart-key="<?php echo esc_attr($cart_key); ?>"
                 style="display:flex !important;flex-direction:row !important;align-items:center !important;gap:12px;padding:14px 16px;">
                <div class="tix-co-item-info" style="flex:1;min-width:0;">
                    <span class="tix-co-item-name"><?php echo esc_html($name); ?></span>
                    <?php if (!empty($item['_tix_bundle'])): ?>
                        <?php
                        $b = $item['_tix_bundle'];
                        $b_buy = intval($b['buy'] ?? 0); $b_pay = intval($b['pay'] ?? 0);
                        $b_free = $b_buy - $b_pay;
                        ?>
                        <span class="tix-co-item-bundle-hint">🎁 <?php echo $b_buy; ?>× zum Preis von <?php echo $b_pay; ?> – <?php echo $b_free; ?>× gratis</span>
                    <?php endif; ?>
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
                    <span class="tix-co-qty-val" style="min-width:24px;text-align:center;font-weight:700;font-size:1rem;"><?php echo $qty; ?></span>
                    <button type="button" class="tix-co-qty-btn tix-co-qty-plus" data-key="<?php echo esc_attr($cart_key); ?>" aria-label="Mehr"
                            style="display:inline-flex !important;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;border:1px solid currentColor;background:transparent;color:inherit;font-size:18px;cursor:pointer;padding:0;flex-shrink:0;">+</button>
                </div>
                <button type="button" class="tix-co-item-remove" data-key="<?php echo esc_attr($cart_key); ?>" aria-label="Entfernen" title="Entfernen"
                        style="flex-shrink:0;">✕</button>
            </div>
            <?php
        }
        // Multi-Event-Warnung
        if (count($event_names) > 1) {
            ?>
            <div class="tix-co-multi-event-notice">
                <span class="tix-co-multi-icon">ℹ</span>
                Du kaufst Tickets für <strong><?php echo count($event_names); ?> verschiedene Events</strong>.
            </div>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * WC-Fees als Zusammenfassungs-Zeilen rendern (Bundle-Deal, Gruppenrabatt etc.)
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
    // Kombi-Meta persistieren (Cart → Order Item)
    // ══════════════════════════════════════════════

    public static function save_combo_order_meta($item, $cart_item_key, $values, $order) {
        if (!empty($values['_tix_combo'])) {
            $item->add_meta_data('_tix_combo', $values['_tix_combo'], true);
        }
        // Bundle-Meta persistieren
        if (!empty($values['_tix_bundle'])) {
            $item->add_meta_data('_tix_bundle', $values['_tix_bundle'], true);
        }
        // Seatmap-Meta persistieren (Cart → Order Item)
        if (!empty($values['_tix_seats']) && is_array($values['_tix_seats'])) {
            $item->add_meta_data('_tix_seats', $values['_tix_seats'], true);
        }
        if (!empty($values['_tix_event_id'])) {
            $item->add_meta_data('_tix_event_id', intval($values['_tix_event_id']), true);
        }
        if (!empty($values['_tix_seatmap_id'])) {
            $item->add_meta_data('_tix_seatmap_id', intval($values['_tix_seatmap_id']), true);
        }
    }

    // ══════════════════════════════════════════════
    // AJAX
    // ══════════════════════════════════════════════

    public static function ajax_update_cart() {
        check_ajax_referer('tix_update_cart', 'nonce');
        $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
        $action   = sanitize_text_field($_POST['cart_action'] ?? '');
        if (!$cart_key) wp_send_json_error(['message' => 'Ungültiger Eintrag.']);
        $cart = WC()->cart; $cart_item = $cart->get_cart_item($cart_key);
        if (!$cart_item) wp_send_json_error(['message' => 'Eintrag nicht gefunden.']);

        // Kombi-Gruppe: alle Geschwister-Items mit-ändern
        $combo_group_id = $cart_item['_tix_combo']['group_id'] ?? '';
        $sibling_keys = [];
        if ($combo_group_id) {
            foreach ($cart->get_cart() as $k => $i) {
                if (!empty($i['_tix_combo']) && ($i['_tix_combo']['group_id'] ?? '') === $combo_group_id) {
                    $sibling_keys[] = $k;
                }
            }
        }

        if (!empty($sibling_keys)) {
            // Kombi: Aktion auf alle Geschwister anwenden
            switch ($action) {
                case 'increase':
                    foreach ($sibling_keys as $k) {
                        $si = $cart->get_cart_item($k);
                        if ($si) $cart->set_quantity($k, $si['quantity'] + 1);
                    }
                    break;
                case 'decrease':
                    $nq = $cart_item['quantity'] - 1;
                    foreach ($sibling_keys as $k) {
                        if ($nq <= 0) $cart->remove_cart_item($k);
                        else $cart->set_quantity($k, $nq);
                    }
                    break;
                case 'remove':
                    foreach ($sibling_keys as $k) { $cart->remove_cart_item($k); }
                    break;
            }
        } else {
            // Einzel-/Bundle-Item
            switch ($action) {
                case 'increase': $cart->set_quantity($cart_key, $cart_item['quantity'] + 1); break;
                case 'decrease': $nq = $cart_item['quantity'] - 1; if ($nq <= 0) $cart->remove_cart_item($cart_key); else $cart->set_quantity($cart_key, $nq); break;
                case 'remove': $cart->remove_cart_item($cart_key); break;
            }
        }

        $cart->calculate_totals(); self::send_cart_json();
    }

    public static function ajax_apply_coupon() {
        check_ajax_referer('tix_update_cart', 'nonce');
        $code = sanitize_text_field($_POST['coupon_code'] ?? '');
        if (!$code) wp_send_json_error(['message' => 'Bitte gib einen Gutscheincode ein.']);
        $result = WC()->cart->apply_coupon($code); WC()->cart->calculate_totals();
        if ($result) { $data = self::get_cart_json_data(); $data['message'] = 'Gutschein wurde angewendet.'; wp_send_json_success($data); }
        else { $notices = wc_get_notices('error'); wc_clear_notices(); $msg = !empty($notices) ? strip_tags(is_array($notices[0]) ? ($notices[0]['notice'] ?? '') : $notices[0]) : 'Ungültiger Gutscheincode.'; wp_send_json_error(['message' => $msg]); }
    }

    public static function ajax_remove_coupon() {
        check_ajax_referer('tix_update_cart', 'nonce');
        $code = sanitize_text_field($_POST['coupon_code'] ?? '');
        if ($code) { WC()->cart->remove_coupon($code); WC()->cart->calculate_totals(); }
        wp_send_json_success(self::get_cart_json_data());
    }

    public static function ajax_login() {
        check_ajax_referer('tix_update_cart', 'nonce');
        $user = sanitize_text_field($_POST['user'] ?? ''); $pass = $_POST['pass'] ?? '';
        if (!$user || !$pass) wp_send_json_error(['message' => 'Bitte E-Mail und Passwort eingeben.']);
        $result = wp_signon(['user_login' => $user, 'user_password' => $pass, 'remember' => true], is_ssl());
        if (is_wp_error($result)) wp_send_json_error(['message' => 'Anmeldung fehlgeschlagen. Bitte prüfe deine Zugangsdaten.']);
        wp_set_current_user($result->ID); wp_send_json_success(['message' => 'Erfolgreich angemeldet!', 'reload' => true]);
    }

    private static function get_cart_json_data() {
        $cart = WC()->cart; $coupons = $cart->get_applied_coupons(); $coupons_html = '';
        foreach ($coupons as $c) { $coupons_html .= '<div class="tix-co-coupon-tag"><span class="tix-co-coupon-code">' . esc_html(strtoupper($c)) . '</span><button type="button" class="tix-co-coupon-remove" data-coupon="' . esc_attr($c) . '" title="Entfernen">✕</button></div>'; }
        return ['html' => !$cart->is_empty() ? self::render_cart_items() : '', 'coupons_html' => $coupons_html, 'fees_html' => self::render_fee_rows(), 'subtotal' => $cart->get_cart_subtotal(), 'discount' => !empty($coupons) ? '−' . wc_price($cart->get_cart_discount_total()) : '', 'has_discount' => !empty($coupons), 'tax' => $cart->get_cart_tax(), 'total' => $cart->get_total(), 'count' => $cart->get_cart_contents_count(), 'empty' => $cart->is_empty()];
    }
    private static function send_cart_json() { wp_send_json_success(self::get_cart_json_data()); }
    private static function get_settings() { return tix_get_settings(); }

    /**
     * AJAX: Countdown abgelaufen → Warenkorb leeren
     */
    public static function ajax_countdown_clear() {
        check_ajax_referer('tix_update_cart', 'nonce');
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
        wp_send_json_success(['cleared' => true]);
    }

    private static function enqueue() {
        if (wp_script_is('tix-checkout', 'enqueued')) return;
        add_filter('woocommerce_is_checkout', '__return_true');
        wp_enqueue_style('tix-checkout', TIXOMAT_URL . 'assets/css/checkout.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-checkout', TIXOMAT_URL . 'assets/js/checkout.js', ['jquery'], TIXOMAT_VERSION, true);
        // Abandoned Cart: Event-ID aus dem Warenkorb ermitteln
        $ac_event_id = 0;
        $ac_enabled  = !empty(self::get_settings()['abandoned_cart_enabled']);
        if ($ac_enabled && function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $eid = intval($item['tix_event_id'] ?? 0);
                if (!$eid) $eid = intval(get_post_meta($item['product_id'], '_tix_event_id', true));
                if ($eid && get_post_meta($eid, '_tix_abandoned_cart', true) === '1') {
                    $ac_event_id = $eid;
                    break;
                }
            }
            // Wenn kein Event im Cart mit AC aktiv → deaktivieren
            if (!$ac_event_id) $ac_enabled = false;
        }

        wp_localize_script('tix-checkout', 'ehCo', [
            'ajaxUrl' => admin_url('admin-ajax.php'), 'wcCheckoutUrl' => add_query_arg('wc-ajax', 'checkout', home_url('/')),
            'nonce' => wp_create_nonce('tix_update_cart'), 'useSteps' => !empty(self::get_settings()['checkout_steps']),
            'countdown' => !empty(self::get_settings()['checkout_countdown']) ? intval(self::get_settings()['checkout_countdown_minutes'] ?? 10) : 0,
            'acEnabled' => $ac_enabled ? 1 : 0,
            'acEventId' => $ac_event_id,
        ]);
        $tix_s = self::get_settings(); $api_key = $tix_s['google_api_key'] ?? '';
        if ($api_key) { wp_enqueue_script('google-places', 'https://maps.googleapis.com/maps/api/js?key=' . esc_attr($api_key) . '&libraries=places&loading=async&callback=ehInitAutocomplete', ['tix-checkout'], null, true); }
    }

    /**
     * Newsletter-Subscriber speichern nach Checkout
     */
    public static function save_newsletter_subscriber($order_id, $posted_data, $order) {
        $nl = TIX_Settings::get();
        if (empty($nl['newsletter_enabled'])) return;
        if (empty($_POST['tix_newsletter_optin'])) return;

        $contact = sanitize_text_field($_POST['tix_newsletter_contact'] ?? '');
        if (empty($contact)) return;

        $nl_type = $nl['newsletter_type'] ?? 'email';

        // Daten aus Bestellung
        $name = '';
        $address = '';
        $purchase = [];
        if ($order) {
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

            // Adresse zusammenbauen
            $addr_parts = array_filter([
                $order->get_billing_address_1(),
                $order->get_billing_address_2(),
                trim($order->get_billing_postcode() . ' ' . $order->get_billing_city()),
                $order->get_billing_country(),
            ]);
            $address = implode(', ', $addr_parts);

            // Kauf-Daten sammeln
            $items = [];
            foreach ($order->get_items() as $item) {
                $items[] = $item->get_name() . ' × ' . $item->get_quantity();
            }
            $purchase = [
                'order_id' => $order_id,
                'date'     => current_time('c'),
                'items'    => $items,
                'total'    => $order->get_total(),
            ];
        }

        // Duplikat-Check → bestehenden Subscriber aktualisieren
        $existing = get_posts([
            'post_type'   => 'tix_subscriber',
            'post_status' => 'publish',
            'meta_key'    => '_tix_sub_contact',
            'meta_value'  => $contact,
            'numberposts' => 1,
        ]);

        if (!empty($existing)) {
            $sub_id = $existing[0]->ID;
            // Name + Adresse aktualisieren (immer neueste Daten)
            if ($name)    update_post_meta($sub_id, '_tix_sub_name', $name);
            if ($address) update_post_meta($sub_id, '_tix_sub_address', $address);
            // Kauf anhängen
            if (!empty($purchase)) {
                $purchases = get_post_meta($sub_id, '_tix_sub_purchases', true);
                if (!is_array($purchases)) $purchases = [];
                $purchases[] = $purchase;
                update_post_meta($sub_id, '_tix_sub_purchases', $purchases);
            }
            return;
        }

        // CPT-Eintrag erstellen
        $sub_id = wp_insert_post([
            'post_type'   => 'tix_subscriber',
            'post_title'  => $contact,
            'post_status' => 'publish',
        ]);

        if ($sub_id && !is_wp_error($sub_id)) {
            update_post_meta($sub_id, '_tix_sub_type', $nl_type);
            update_post_meta($sub_id, '_tix_sub_contact', $contact);
            update_post_meta($sub_id, '_tix_sub_name', $name);
            update_post_meta($sub_id, '_tix_sub_address', $address);
            update_post_meta($sub_id, '_tix_sub_order', $order_id);
            update_post_meta($sub_id, '_tix_sub_purchases', !empty($purchase) ? [$purchase] : []);
            update_post_meta($sub_id, '_tix_sub_date', current_time('c'));

            // Webhook
            $webhook_url = $nl['newsletter_webhook'] ?? '';
            if (!empty($webhook_url)) {
                wp_remote_post($webhook_url, [
                    'body'    => wp_json_encode([
                        'type'     => $nl_type,
                        'contact'  => $contact,
                        'name'     => $name,
                        'address'  => $address,
                        'order_id' => $order_id,
                        'items'    => $purchase['items'] ?? [],
                        'date'     => current_time('c'),
                    ]),
                    'headers'  => ['Content-Type' => 'application/json'],
                    'timeout'  => 10,
                    'blocking' => false,
                ]);
            }
        }
    }

    // ══════════════════════════════════════════════
    // ABANDONED CART RECOVERY
    // ══════════════════════════════════════════════

    /**
     * AJAX: E-Mail beim Checkout-Blur erfassen
     */
    public static function ajax_capture_cart_email() {
        check_ajax_referer('tix_update_cart', 'nonce');

        $email    = sanitize_email($_POST['email'] ?? '');
        $event_id = intval($_POST['event_id'] ?? 0);
        $cart_raw = $_POST['cart_data'] ?? '[]';

        if (!$email || !is_email($email)) wp_send_json_error();

        // Global + Per-Event prüfen
        $settings = self::get_settings();
        if (empty($settings['abandoned_cart_enabled'])) wp_send_json_error();
        if ($event_id && get_post_meta($event_id, '_tix_abandoned_cart', true) !== '1') wp_send_json_error();

        // WC-Session-ID für Duplikat-Erkennung
        $session_id = '';
        if (function_exists('WC') && WC()->session) {
            $session_id = WC()->session->get_customer_id();
        }

        // Vorhandenen offenen Cart für diese E-Mail suchen
        $existing = get_posts([
            'post_type'   => 'tix_abandoned_cart',
            'post_status' => 'publish',
            'meta_query'  => [
                ['key' => '_tix_ac_email', 'value' => $email],
                ['key' => '_tix_ac_status', 'value' => 'pending'],
            ],
            'numberposts' => 1,
        ]);

        if (!empty($existing)) {
            $ac_id = $existing[0]->ID;
            // Cart-Daten aktualisieren
            update_post_meta($ac_id, '_tix_ac_cart_data', $cart_raw);
            update_post_meta($ac_id, '_tix_ac_session_id', $session_id);
            // WC-Cart auch aktualisieren (für Recovery), aber nur wenn nicht leer
            if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
                update_post_meta($ac_id, '_tix_ac_wc_cart', maybe_serialize(WC()->cart->get_cart_for_session()));
            }
            // Scheduled Action aktualisieren (Delay neu starten)
            as_unschedule_all_actions('tix_send_abandoned_cart_email', [$ac_id]);
            $delay = intval($settings['abandoned_cart_delay'] ?? 30) * 60;
            as_schedule_single_action(time() + $delay, 'tix_send_abandoned_cart_email', [$ac_id]);
        } else {
            // Neuen Cart-Eintrag erstellen
            $token = wp_generate_password(32, false);
            $ac_id = wp_insert_post([
                'post_type'   => 'tix_abandoned_cart',
                'post_title'  => $email,
                'post_status' => 'publish',
            ]);

            if ($ac_id && !is_wp_error($ac_id)) {
                update_post_meta($ac_id, '_tix_ac_email', $email);
                update_post_meta($ac_id, '_tix_ac_cart_data', $cart_raw);
                update_post_meta($ac_id, '_tix_ac_session_id', $session_id);
                update_post_meta($ac_id, '_tix_ac_event_id', $event_id);
                update_post_meta($ac_id, '_tix_ac_token', $token);
                update_post_meta($ac_id, '_tix_ac_status', 'pending');

                // WC Cart Inhalt speichern (für Recovery)
                if (function_exists('WC') && WC()->cart) {
                    update_post_meta($ac_id, '_tix_ac_wc_cart', maybe_serialize(WC()->cart->get_cart_for_session()));
                }

                $delay = intval($settings['abandoned_cart_delay'] ?? 30) * 60;
                as_schedule_single_action(time() + $delay, 'tix_send_abandoned_cart_email', [$ac_id]);
            }
        }

        wp_send_json_success();
    }

    /**
     * Bei erfolgreichem Kauf: offene Abandoned Carts canceln
     */
    public static function cancel_abandoned_cart_recovery($order_id, $posted_data, $order) {
        if (!$order) return;
        $email = $order->get_billing_email();
        if (!$email) return;

        $carts = get_posts([
            'post_type'   => 'tix_abandoned_cart',
            'post_status' => 'publish',
            'meta_query'  => [
                ['key' => '_tix_ac_email', 'value' => $email],
                ['key' => '_tix_ac_status', 'value' => ['pending', 'sent'], 'compare' => 'IN'],
            ],
            'numberposts' => -1,
        ]);

        foreach ($carts as $cart) {
            update_post_meta($cart->ID, '_tix_ac_status', 'recovered');
            as_unschedule_all_actions('tix_send_abandoned_cart_email', [$cart->ID]);
        }
    }

    /**
     * Cart Recovery: Token-basierte Warenkorb-Wiederherstellung
     */
    public static function handle_cart_recovery() {
        if (empty($_GET['tix_recover_cart'])) return;
        if (!function_exists('WC') || !WC()->cart) return;

        $token = sanitize_text_field($_GET['tix_recover_cart']);

        $carts = get_posts([
            'post_type'   => 'tix_abandoned_cart',
            'post_status' => 'publish',
            'meta_query'  => [
                ['key' => '_tix_ac_token', 'value' => $token],
            ],
            'numberposts' => 1,
        ]);

        if (empty($carts)) return;

        $cart = $carts[0];
        $status = get_post_meta($cart->ID, '_tix_ac_status', true);
        if (!in_array($status, ['pending', 'sent'])) return;

        // WC Session starten
        if (method_exists(WC()->session, 'has_session') && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }

        // Warenkorb wiederherstellen
        $wc_cart = maybe_unserialize(get_post_meta($cart->ID, '_tix_ac_wc_cart', true));
        if (!empty($wc_cart) && is_array($wc_cart)) {
            WC()->cart->empty_cart();
            foreach ($wc_cart as $cart_item_key => $values) {
                $product_id   = $values['product_id'] ?? 0;
                $quantity     = $values['quantity'] ?? 1;
                $variation_id = $values['variation_id'] ?? 0;
                $variation    = $values['variation'] ?? [];
                $cart_data    = [];
                // Kombi/Event-Daten übernehmen
                foreach (['tix_event_id', 'tix_combo_id', 'tix_combo_label'] as $k) {
                    if (isset($values[$k])) $cart_data[$k] = $values[$k];
                }
                WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation, $cart_data);
            }
        }

        update_post_meta($cart->ID, '_tix_ac_status', 'recovered');

        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Alte Abandoned Carts ablaufen lassen (täglich via Action Scheduler)
     */
    public static function expire_old_abandoned_carts() {
        $carts = get_posts([
            'post_type'   => 'tix_abandoned_cart',
            'post_status' => 'publish',
            'meta_query'  => [
                ['key' => '_tix_ac_status', 'value' => ['pending', 'sent'], 'compare' => 'IN'],
            ],
            'date_query'  => [
                ['before' => '48 hours ago'],
            ],
            'numberposts' => 100,
        ]);

        foreach ($carts as $cart) {
            update_post_meta($cart->ID, '_tix_ac_status', 'expired');
            as_unschedule_all_actions('tix_send_abandoned_cart_email', [$cart->ID]);
        }
    }

    // ══════════════════════════════════════════════
    // EXPRESS CHECKOUT (1-Klick-Kauf)
    // ══════════════════════════════════════════════

    /**
     * Prüft ob Express Checkout für ein Event verfügbar ist
     */
    public static function can_express_checkout($event_id) {
        try {
            if (!is_user_logged_in()) return false;
            if (!function_exists('tix_get_settings')) return false;
            if (empty(tix_get_settings('express_checkout_enabled'))) return false;
            if (get_post_meta($event_id, '_tix_express_checkout', true) !== '1') return false;

            $uid = get_current_user_id();
            $has_tokens = class_exists('WC_Payment_Tokens')
                && !empty(WC_Payment_Tokens::get_customer_tokens($uid));
            $has_bacs = false;
            if (class_exists('WooCommerce') && function_exists('WC')) {
                $all_gateways = WC()->payment_gateways()->payment_gateways();
                $has_bacs = isset($all_gateways['bacs']) && $all_gateways['bacs']->enabled === 'yes';
            }
            if (!$has_tokens && !$has_bacs) return false;

            $customer = new \WC_Customer($uid);
            return !empty($customer->get_billing_first_name())
                && !empty($customer->get_billing_last_name())
                && !empty($customer->get_billing_email());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * AJAX: Express Checkout — Order programmatisch erstellen
     */
    public static function ajax_express_checkout() {
        // Akzeptiere Nonce von Ticket Selector oder Checkout
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tix_add_to_cart') &&
            !wp_verify_nonce($_POST['nonce'] ?? '', 'tix_update_cart')) {
            wp_send_json_error(['message' => 'Sicherheitsprüfung fehlgeschlagen.']);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bitte einloggen.']);
        }

        if (empty($_POST['terms_accepted'])) {
            wp_send_json_error(['message' => 'Bitte akzeptiere die Nutzungsbedingungen.']);
        }

        $user_id = get_current_user_id();
        $items   = json_decode(stripslashes($_POST['items'] ?? '[]'), true);

        if (empty($items) || !is_array($items)) {
            wp_send_json_error(['message' => 'Keine Tickets ausgewählt.']);
        }

        // Customer-Daten laden + Billing prüfen
        $customer = new \WC_Customer($user_id);
        if (empty($customer->get_billing_first_name()) || empty($customer->get_billing_last_name()) || empty($customer->get_billing_email())) {
            wp_send_json_error(['message' => 'Bitte vervollständige dein Profil (Name, E-Mail) bevor du Express Checkout nutzt.']);
        }

        // Payment-Methode bestimmen: Token oder BACS
        $tokens = class_exists('WC_Payment_Tokens')
            ? WC_Payment_Tokens::get_customer_tokens($user_id)
            : [];

        $token = null;
        $use_bacs = false;

        if (!empty($tokens)) {
            // Default Token oder ersten verfügbaren nehmen
            foreach ($tokens as $t) {
                if ($t->is_default()) { $token = $t; break; }
            }
            if (!$token) $token = reset($tokens);
        } else {
            // Kein Token → BACS prüfen (enabled, nicht "available" da AJAX-Kontext)
            $all_gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($all_gateways['bacs']) && $all_gateways['bacs']->enabled === 'yes') {
                $use_bacs = true;
            } else {
                wp_send_json_error(['message' => 'Keine Zahlungsmethode verfügbar.']);
            }
        }

        try {
            // Order erstellen
            $order = wc_create_order(['customer_id' => $user_id]);

            // Produkte hinzufügen (Einzel, Bundle, Combo)
            $normal_total = 0;
            $normal_qty   = 0;

            foreach ($items as $item) {
                // ── Kombi-Ticket ──
                if (!empty($item['combo'])) {
                    $combo_price = floatval($item['combo_price'] ?? 0);
                    $combo_label = sanitize_text_field($item['combo_label'] ?? 'Kombi');
                    $combo_qty   = max(1, intval($item['qty'] ?? 1));
                    $products    = $item['products'] ?? [];
                    $sum = 0;

                    foreach ($products as $p) {
                        $p_id = intval($p['product_id'] ?? 0);
                        $product = wc_get_product($p_id);
                        if (!$product) continue;
                        $order->add_product($product, $combo_qty);
                        $sum += floatval($product->get_price()) * $combo_qty;
                    }

                    // Combo-Rabatt als negative Fee
                    $combo_discount = $sum - ($combo_price * $combo_qty);
                    if ($combo_discount > 0) {
                        $fee = new \WC_Order_Item_Fee();
                        $fee->set_name(sprintf('🎫 %s', $combo_label));
                        $fee->set_amount(-$combo_discount);
                        $fee->set_total(-$combo_discount);
                        $order->add_item($fee);
                    }
                    continue;
                }

                // ── Bundle-Ticket ──
                if (!empty($item['bundle'])) {
                    $product_id  = intval($item['product_id'] ?? 0);
                    $bundle_qty  = max(1, intval($item['qty'] ?? 1));
                    $bundle_buy  = intval($item['bundle_buy'] ?? 0);
                    $bundle_pay  = intval($item['bundle_pay'] ?? 0);
                    $bundle_label = sanitize_text_field($item['bundle_label'] ?? 'Paket');
                    $product = wc_get_product($product_id);
                    if (!$product || $bundle_buy < 2 || $bundle_pay < 1) continue;

                    // Kaufe X × qty Tickets
                    $total_tickets = $bundle_buy * $bundle_qty;
                    $order->add_product($product, $total_tickets);

                    // Rabatt: (buy - pay) × qty × Preis
                    $free_per_pkg = $bundle_buy - $bundle_pay;
                    $discount = $free_per_pkg * $bundle_qty * floatval($product->get_price());
                    if ($discount > 0) {
                        $fee = new \WC_Order_Item_Fee();
                        $fee->set_name(sprintf('🎁 %s (%d für %d)', $bundle_label, $bundle_buy, $bundle_pay));
                        $fee->set_amount(-$discount);
                        $fee->set_total(-$discount);
                        $order->add_item($fee);
                    }
                    continue;
                }

                // ── Einzel-Ticket ──
                $product_id = intval($item['product_id'] ?? 0);
                $quantity   = max(1, intval($item['qty'] ?? 1));
                $product    = wc_get_product($product_id);
                if (!$product) continue;
                $order->add_product($product, $quantity);
                $normal_total += floatval($product->get_price()) * $quantity;
                $normal_qty   += $quantity;
            }

            // ── Mengenrabatt (Server-seitig validiert) ──
            $gd_percent = intval($_POST['group_discount_percent'] ?? 0);
            if ($gd_percent > 0 && $gd_percent <= 100 && $normal_total > 0) {
                $gd_discount = round($normal_total * ($gd_percent / 100), 2);
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name(sprintf('👥 Mengenrabatt (−%d%%)', $gd_percent));
                $fee->set_amount(-$gd_discount);
                $fee->set_total(-$gd_discount);
                $order->add_item($fee);
            }

            // Adresse setzen
            $order->set_address([
                'first_name' => $customer->get_billing_first_name(),
                'last_name'  => $customer->get_billing_last_name(),
                'company'    => $customer->get_billing_company(),
                'address_1'  => $customer->get_billing_address_1(),
                'address_2'  => $customer->get_billing_address_2(),
                'city'       => $customer->get_billing_city(),
                'state'      => $customer->get_billing_state(),
                'postcode'   => $customer->get_billing_postcode(),
                'country'    => $customer->get_billing_country(),
                'email'      => $customer->get_billing_email(),
                'phone'      => $customer->get_billing_phone(),
            ], 'billing');

            // Shipping = Free
            $shipping = new \WC_Order_Item_Shipping();
            $shipping->set_method_title('Kostenlos per E-Mail');
            $shipping->set_method_id('free_shipping');
            $shipping->set_total(0);
            $order->add_item($shipping);

            $order->calculate_totals();
            $order->add_meta_data('_tix_express_checkout', '1');

            if ($use_bacs) {
                // BACS: Order auf on-hold setzen
                $all_gw = WC()->payment_gateways()->payment_gateways();
                $bacs_gw = $all_gw['bacs'];
                $order->set_payment_method('bacs');
                $order->set_payment_method_title($bacs_gw->get_title());
                $order->save();

                $result = $bacs_gw->process_payment($order->get_id());

                if ($result['result'] === 'success') {
                    wp_send_json_success([
                        'redirect' => $result['redirect'] ?? $order->get_checkout_order_received_url(),
                    ]);
                } else {
                    $order->update_status('failed', 'Express Checkout: BACS fehlgeschlagen.');
                    wp_send_json_error(['message' => 'Bestellung fehlgeschlagen.']);
                }
            } else {
                // Token-basierte Zahlung
                $order->set_payment_method($token->get_gateway_id());
                $order->set_payment_method_title($token->get_display_name());
                $order->save();

                $gateways = WC()->payment_gateways()->get_available_payment_gateways();
                $gateway_id = $token->get_gateway_id();

                if (isset($gateways[$gateway_id])) {
                    $_POST['wc-' . $gateway_id . '-payment-token'] = $token->get_id();
                    $result = $gateways[$gateway_id]->process_payment($order->get_id());

                    if ($result['result'] === 'success') {
                        wp_send_json_success([
                            'redirect' => $order->get_checkout_order_received_url(),
                        ]);
                    } else {
                        $order->update_status('failed', 'Express Checkout: Zahlung fehlgeschlagen.');
                        wp_send_json_error(['message' => 'Zahlung fehlgeschlagen.']);
                    }
                } else {
                    $order->update_status('failed', 'Express Checkout: Gateway nicht verfügbar.');
                    wp_send_json_error(['message' => 'Zahlungsart nicht verfügbar.']);
                }
            }

        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Fehler: ' . $e->getMessage()]);
        }
    }
}
