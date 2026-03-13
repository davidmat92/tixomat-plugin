<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Promoter_Signup – Self-Signup & Post-Purchase Empfehlungsprogramm.
 *
 * Shortcode: [tix_promoter_signup]
 * Post-Purchase CTA auf Danke-Seite.
 * Referral-Link in Meine Tickets.
 *
 * @since 1.28.90
 */
class TIX_Promoter_Signup {

    public static function init() {
        $s = TIX_Settings::get();

        // Shortcode
        add_shortcode('tix_promoter_signup', [__CLASS__, 'shortcode']);

        // AJAX: Self-Signup
        add_action('wp_ajax_tix_promoter_self_signup', [__CLASS__, 'handle_signup']);
        add_action('wp_ajax_nopriv_tix_promoter_self_signup', [__CLASS__, 'handle_signup']);

        // Post-Purchase CTA
        if (!empty($s['promoter_post_purchase_enabled'])) {
            add_action('woocommerce_thankyou', [__CLASS__, 'thankyou_cta'], 25);
        }

        // My Tickets integration
        if (!empty($s['promoter_my_tickets_enabled'])) {
            add_action('tix_my_tickets_after_content', [__CLASS__, 'my_tickets_referral']);
            // Fallback: wp_footer mit Injection
            add_action('wp_footer', [__CLASS__, 'my_tickets_footer_inject']);
        }
    }

    /**
     * Shortcode: [tix_promoter_signup]
     */
    public static function shortcode($atts = []) {
        $user = wp_get_current_user();

        // Prüfen ob bereits Promoter
        if ($user->ID && class_exists('TIX_Promoter_DB')) {
            $existing = TIX_Promoter_DB::get_promoter_by_user($user->ID);
            if ($existing) {
                return self::render_referral_card($existing);
            }
        }

        ob_start();
        ?>
        <div class="tix-ps-signup" id="tix-promoter-signup">
            <h3 class="tix-ps-title">Empfehlungsprogramm</h3>
            <p class="tix-ps-desc"><?php echo esc_html(self::get_signup_text()); ?></p>

            <form class="tix-ps-form" id="tix-ps-form">
                <?php wp_nonce_field('tix_promoter_signup', 'tix_ps_nonce'); ?>
                <div class="tix-ps-field">
                    <label>Name</label>
                    <input type="text" name="display_name" required
                           value="<?php echo esc_attr($user->ID ? $user->display_name : ''); ?>"
                           placeholder="Dein Name">
                </div>
                <div class="tix-ps-field">
                    <label>E-Mail</label>
                    <input type="email" name="email" required
                           value="<?php echo esc_attr($user->ID ? $user->user_email : ''); ?>"
                           placeholder="deine@email.de">
                </div>
                <button type="submit" class="tix-ps-submit">Jetzt Promoter werden</button>
                <div class="tix-ps-msg" id="tix-ps-msg" style="display:none"></div>
            </form>
        </div>

        <script>
        (function() {
            var form = document.getElementById('tix-ps-form');
            if (!form) return;
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = form.querySelector('.tix-ps-submit');
                var msg = document.getElementById('tix-ps-msg');
                btn.disabled = true;
                btn.textContent = 'Wird registriert...';

                var data = new FormData(form);
                data.append('action', 'tix_promoter_self_signup');

                fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                    method: 'POST', body: data, credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    msg.style.display = 'block';
                    if (res.success) {
                        msg.className = 'tix-ps-msg tix-ps-msg--ok';
                        msg.innerHTML = 'Dein Empfehlungslink: <strong>' + res.data.link + '</strong>';
                        form.innerHTML = '<p class="tix-ps-msg tix-ps-msg--ok">Registrierung erfolgreich! Dein Code: <strong>' + res.data.code + '</strong></p>';
                    } else {
                        msg.className = 'tix-ps-msg tix-ps-msg--err';
                        msg.textContent = res.data || 'Fehler bei der Registrierung.';
                        btn.disabled = false;
                        btn.textContent = 'Jetzt Promoter werden';
                    }
                })
                .catch(function() {
                    msg.style.display = 'block';
                    msg.className = 'tix-ps-msg tix-ps-msg--err';
                    msg.textContent = 'Netzwerkfehler. Bitte erneut versuchen.';
                    btn.disabled = false;
                    btn.textContent = 'Jetzt Promoter werden';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Self-Signup verarbeiten.
     */
    public static function handle_signup() {
        check_ajax_referer('tix_promoter_signup', 'tix_ps_nonce');

        $name  = sanitize_text_field($_POST['display_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($name) || empty($email)) {
            wp_send_json_error('Name und E-Mail sind erforderlich.');
        }

        if (!class_exists('TIX_Promoter_DB')) {
            wp_send_json_error('Promoter-System nicht verfügbar.');
        }

        // User finden oder erstellen
        $user = get_user_by('email', $email);
        if (!$user) {
            // WP-User erstellen
            $username = sanitize_user(strtolower(str_replace(' ', '.', $name)));
            if (username_exists($username)) {
                $username .= '_' . wp_rand(100, 999);
            }
            $password = wp_generate_password(16);
            $user_id  = wp_create_user($username, $password, $email);
            if (is_wp_error($user_id)) {
                wp_send_json_error('Benutzer konnte nicht erstellt werden.');
            }
            wp_update_user(['ID' => $user_id, 'display_name' => $name, 'first_name' => explode(' ', $name)[0]]);
        } else {
            $user_id = $user->ID;
        }

        // Prüfen ob bereits Promoter
        $existing = TIX_Promoter_DB::get_promoter_by_user($user_id);
        if ($existing) {
            wp_send_json_success([
                'code' => $existing->promoter_code,
                'link' => home_url('?ref=' . $existing->promoter_code),
            ]);
            return;
        }

        // Promoter-Code generieren
        $code = self::generate_code($name);

        // In DB einfügen
        $s = TIX_Settings::get();
        $promoter_id = TIX_Promoter_DB::create_promoter([
            'user_id'       => $user_id,
            'promoter_code' => $code,
            'display_name'  => $name,
            'status'        => 'active',
            'notes'         => 'Self-Signup',
        ]);

        if (!$promoter_id) {
            wp_send_json_error('Promoter konnte nicht erstellt werden.');
        }

        // Auto-Assign Events
        if (!empty($s['promoter_signup_auto_events'])) {
            self::auto_assign_events($promoter_id, $s);
        }

        wp_send_json_success([
            'code' => $code,
            'link' => home_url('?ref=' . $code),
        ]);
    }

    /**
     * Danke-Seite CTA.
     */
    public static function thankyou_cta($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $user_id = $order->get_customer_id();
        if (!$user_id) return;

        // Bereits Promoter?
        if (class_exists('TIX_Promoter_DB')) {
            $existing = TIX_Promoter_DB::get_promoter_by_user($user_id);
            if ($existing) {
                echo self::render_referral_card($existing);
                return;
            }
        }

        $s = TIX_Settings::get();
        $commission_text = ($s['promoter_signup_commission_type'] ?? 'fixed') === 'percent'
            ? intval($s['promoter_signup_commission_value'] ?? 2) . '%'
            : number_format(floatval($s['promoter_signup_commission_value'] ?? 2), 2, ',', '.') . ' €';

        ?>
        <div class="tix-ps-cta" style="margin:24px 0;padding:20px;border:1px solid var(--tix-border,#e5e7eb);border-radius:var(--tix-radius,10px);background:var(--tix-card-bg,#fff);text-align:center">
            <h3 style="margin:0 0 8px;font-size:1.1rem">🎉 Empfehle uns weiter!</h3>
            <p style="margin:0 0 16px;color:rgba(0,0,0,.55);font-size:.9rem">Teile deinen Link und verdiene <strong><?php echo $commission_text; ?></strong> pro verkauftem Ticket.</p>
            <button type="button" class="tix-ps-quick-signup" onclick="tixQuickPromoterSignup(this)"
                    style="padding:10px 24px;background:var(--tix-buy-bg,#FF5500);color:var(--tix-buy-color,#fff);border:none;border-radius:var(--tix-radius,8px);font-weight:700;cursor:pointer;font-size:.9rem">
                Jetzt Empfehlungslink erhalten
            </button>
            <div id="tix-ps-quick-result" style="margin-top:12px;display:none"></div>
        </div>

        <script>
        function tixQuickPromoterSignup(btn) {
            btn.disabled = true;
            btn.textContent = 'Wird erstellt...';
            var data = new FormData();
            data.append('action', 'tix_promoter_self_signup');
            data.append('tix_ps_nonce', '<?php echo wp_create_nonce("tix_promoter_signup"); ?>');
            data.append('display_name', '<?php echo esc_js($order->get_billing_first_name() . " " . $order->get_billing_last_name()); ?>');
            data.append('email', '<?php echo esc_js($order->get_billing_email()); ?>');

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {method:'POST',body:data,credentials:'same-origin'})
            .then(function(r){return r.json()})
            .then(function(res) {
                var el = document.getElementById('tix-ps-quick-result');
                el.style.display = 'block';
                if (res.success) {
                    btn.parentNode.innerHTML = '<div style="padding:16px;background:rgba(76,175,80,.08);border-radius:8px;border:1px solid rgba(76,175,80,.2)"><p style="margin:0 0 8px;font-weight:700;color:#2e7d32">✓ Dein Empfehlungslink:</p><input type="text" value="' + res.data.link + '" readonly onclick="this.select();document.execCommand(\'copy\')" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:6px;text-align:center;font-size:.85rem;cursor:pointer" title="Klicken zum Kopieren"></div>';
                } else {
                    el.textContent = res.data || 'Fehler.';
                    btn.disabled = false;
                    btn.textContent = 'Erneut versuchen';
                }
            });
        }
        </script>
        <?php
    }

    /**
     * My Tickets: Referral-Link Card anzeigen.
     */
    public static function my_tickets_referral() {
        $user_id = get_current_user_id();
        if (!$user_id || !class_exists('TIX_Promoter_DB')) return;

        $promoter = TIX_Promoter_DB::get_promoter_by_user($user_id);
        if (!$promoter) return;

        echo self::render_referral_card($promoter);
    }

    /**
     * Fallback: Inject via wp_footer auf My Tickets Seite.
     */
    public static function my_tickets_footer_inject() {
        if (!is_page() || !has_shortcode(get_post()->post_content ?? '', 'tix_my_tickets')) return;

        $user_id = get_current_user_id();
        if (!$user_id || !class_exists('TIX_Promoter_DB')) return;

        $promoter = TIX_Promoter_DB::get_promoter_by_user($user_id);
        if (!$promoter) return;

        $card_html = self::render_referral_card($promoter);
        ?>
        <script>
        (function() {
            var mt = document.querySelector('.tix-mt');
            if (!mt) return;
            var div = document.createElement('div');
            div.innerHTML = <?php echo wp_json_encode($card_html); ?>;
            mt.appendChild(div.firstElementChild);
        })();
        </script>
        <?php
    }

    /**
     * Referral-Link Card HTML.
     */
    private static function render_referral_card($promoter) {
        $link = home_url('?ref=' . $promoter->promoter_code);
        $code = $promoter->promoter_code;

        return '<div class="tix-ps-referral" style="margin:24px 0;padding:20px;border:1px solid var(--tix-border,#e5e7eb);border-radius:var(--tix-radius,10px);background:var(--tix-card-bg,#fff)">'
            . '<h4 style="margin:0 0 8px;font-size:1rem">🔗 Dein Empfehlungslink</h4>'
            . '<p style="margin:0 0 12px;font-size:.85rem;color:rgba(0,0,0,.5)">Teile diesen Link – du verdienst bei jedem Verkauf.</p>'
            . '<div style="display:flex;gap:8px;align-items:stretch">'
            . '<input type="text" value="' . esc_attr($link) . '" readonly onclick="this.select();document.execCommand(\'copy\');this.nextElementSibling.textContent=\'Kopiert!\';setTimeout(function(){this.nextElementSibling.textContent=\'Kopieren\'}.bind(this),2000)" style="flex:1;padding:8px 12px;border:1px solid var(--tix-border,#e5e7eb);border-radius:var(--tix-radius,6px);font-size:.85rem;background:rgba(0,0,0,.02)">'
            . '<button type="button" onclick="this.previousElementSibling.select();document.execCommand(\'copy\');this.textContent=\'Kopiert!\';var b=this;setTimeout(function(){b.textContent=\'Kopieren\'},2000)" style="padding:8px 16px;background:var(--tix-buy-bg,#FF5500);color:var(--tix-buy-color,#fff);border:none;border-radius:var(--tix-radius,6px);font-weight:600;cursor:pointer;font-size:.85rem;white-space:nowrap">Kopieren</button>'
            . '</div>'
            . '<p style="margin:8px 0 0;font-size:.75rem;color:rgba(0,0,0,.35)">Code: ' . esc_html($code) . '</p>'
            . '</div>';
    }

    /**
     * Signup-Text mit Provisions-Info.
     */
    private static function get_signup_text() {
        $s = TIX_Settings::get();
        $type = $s['promoter_signup_commission_type'] ?? 'fixed';
        $val  = floatval($s['promoter_signup_commission_value'] ?? 2);

        if ($type === 'percent') {
            $amount = intval($val) . '%';
        } else {
            $amount = number_format($val, 2, ',', '.') . ' €';
        }

        return 'Teile deinen persönlichen Link und verdiene ' . $amount . ' für jedes verkaufte Ticket.';
    }

    /**
     * Promoter-Code generieren.
     */
    private static function generate_code($name) {
        $base = sanitize_title(mb_substr($name, 0, 10));
        $base = preg_replace('/[^a-z0-9]/', '', $base);
        if (strlen($base) < 3) $base = 'ref';
        $code = $base . wp_rand(100, 999);

        // Unique check
        if (class_exists('TIX_Promoter_DB')) {
            $attempts = 0;
            while (TIX_Promoter_DB::get_promoter_by_code($code) && $attempts < 10) {
                $code = $base . wp_rand(1000, 9999);
                $attempts++;
            }
        }

        return $code;
    }

    /**
     * Alle aktiven Events dem neuen Promoter zuweisen.
     */
    private static function auto_assign_events($promoter_id, $s) {
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $commission_type  = $s['promoter_signup_commission_type'] ?? 'fixed';
        $commission_value = floatval($s['promoter_signup_commission_value'] ?? 2);

        foreach ($events as $event_id) {
            TIX_Promoter_DB::assign_event($promoter_id, $event_id, [
                'commission_type'  => $commission_type,
                'commission_value' => $commission_value,
                'status'           => 'active',
            ]);
        }
    }
}
