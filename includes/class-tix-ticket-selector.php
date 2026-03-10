<?php
if (!defined('ABSPATH')) exit;

class TIX_Ticket_Selector {

    public static function init() {
        add_shortcode('tix_ticket_selector', [__CLASS__, 'render']);
        add_shortcode('tix_countdown',       [__CLASS__, 'render_countdown']);
        add_shortcode('tix_express_checkout', [__CLASS__, 'render_express_modal']);
        add_action('wp_ajax_tix_add_to_cart',        [__CLASS__, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_tix_add_to_cart',  [__CLASS__, 'ajax_add_to_cart']);
        add_action('wp_ajax_tix_validate_coupon',        [__CLASS__, 'ajax_validate_coupon']);
        add_action('wp_ajax_nopriv_tix_validate_coupon', [__CLASS__, 'ajax_validate_coupon']);
    }

    /**
     * Shortcode: [tix_ticket_selector]
     * Optional: [tix_ticket_selector id="123"] für eine bestimmte Event-ID
     */
    public static function render($atts) {

        $atts = shortcode_atts(['id' => 0, 'fullwidth' => '0', 'variant' => '1'], $atts);
        $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') {
            return '<p class="tix-sel-error">Kein Event gefunden.</p>';
        }

        // Ticketverkauf aktiviert?
        $enabled = get_post_meta($post_id, '_tix_tickets_enabled', true);
        if ($enabled !== '1') return '';

        // Vorverkauf noch aktiv?
        $presale = get_post_meta($post_id, '_tix_presale_active', true);
        if ($presale !== '1') {
            return '<div class="tix-sel"><p class="tix-sel-presale-ended">Der Vorverkauf für dieses Event ist beendet.</p></div>';
        }

        // ── Presale-Start Countdown ──
        $presale_start = get_post_meta($post_id, '_tix_presale_start', true);
        if ($presale_start) {
            $start_ts = strtotime(str_replace('T', ' ', $presale_start));
            $now_ts   = current_time('timestamp');
            if ($start_ts && $start_ts > $now_ts) {
                $tix_s = tix_get_settings();
                $wl_nonce = wp_create_nonce('tix_waitlist_' . $post_id);
                self::enqueue();
                ob_start();
                ?>
                <div class="tix-sel">
                    <div class="tix-sel-presale-soon">
                        <div class="tix-sel-presale-countdown" data-start="<?php echo esc_attr($presale_start); ?>">
                            <span class="tix-sel-presale-label">Vorverkauf startet in</span>
                            <span class="tix-sel-presale-timer"></span>
                        </div>
                        <?php if (!empty($tix_s['waitlist_enabled'])): ?>
                        <form class="tix-sel-notify-form" data-event="<?php echo $post_id; ?>" data-type="presale" data-nonce="<?php echo $wl_nonce; ?>">
                            <p class="tix-sel-notify-text">Lass dich benachrichtigen, wenn der Vorverkauf startet:</p>
                            <div class="tix-sel-notify-fields">
                                <input type="email" name="email" placeholder="Deine E-Mail-Adresse" required>
                                <button type="submit" class="tix-sel-notify-btn">Erinnern</button>
                            </div>
                            <div class="tix-sel-notify-msg" hidden></div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <script>
                (function(){
                    /* Presale Countdown */
                    var cd = document.querySelector('.tix-sel-presale-countdown');
                    if (cd) {
                        var startStr = cd.dataset.start;
                        var startTime = new Date(startStr.replace(' ','T')).getTime();
                        var timer = cd.querySelector('.tix-sel-presale-timer');
                        function tick() {
                            var diff = startTime - Date.now();
                            if (diff <= 0) { location.reload(); return; }
                            var d = Math.floor(diff/86400000);
                            var h = Math.floor((diff%86400000)/3600000);
                            var m = Math.floor((diff%3600000)/60000);
                            var s = Math.floor((diff%60000)/1000);
                            var p = [];
                            if (d>0) p.push(d+(d===1?' Tag':' Tage'));
                            if (h>0) p.push(h+' Std');
                            p.push(m+' Min');
                            if (d===0) p.push(s+' Sek');
                            timer.textContent = p.join(', ');
                            setTimeout(tick, 1000);
                        }
                        tick();
                    }
                    /* Notify forms */
                    document.querySelectorAll('.tix-sel-notify-form').forEach(function(form){
                        form.addEventListener('submit', function(e){
                            e.preventDefault();
                            var btn = form.querySelector('.tix-sel-notify-btn');
                            var msg = form.querySelector('.tix-sel-notify-msg');
                            var email = form.querySelector('input[name="email"]').value.trim();
                            if (!email) return;
                            btn.disabled = true; btn.textContent = '…';
                            var fd = new FormData();
                            fd.append('action','tix_waitlist_join');
                            fd.append('event_id', form.dataset.event);
                            fd.append('type', form.dataset.type);
                            fd.append('nonce', form.dataset.nonce);
                            fd.append('email', email);
                            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST',body:fd})
                                .then(function(r){return r.json();})
                                .then(function(data){
                                    if (data.success) {
                                        form.innerHTML = '<p class="tix-sel-notify-success">✅ '+data.data.message+'</p>';
                                    } else {
                                        msg.textContent = data.data.message||'Fehler';
                                        msg.hidden = false; msg.className = 'tix-sel-notify-msg tix-sel-notify-msg--error';
                                        btn.disabled = false; btn.textContent = 'Erinnern';
                                    }
                                })
                                .catch(function(){
                                    msg.textContent = 'Verbindungsfehler.';
                                    msg.hidden = false; msg.className = 'tix-sel-notify-msg tix-sel-notify-msg--error';
                                    btn.disabled = false; btn.textContent = 'Erinnern';
                                });
                        });
                    });
                })();
                </script>
                <?php
                return ob_get_clean();
            }
        }

        // ── Saalplan-Modus prüfen ──
        $seatmap_id   = intval(get_post_meta($post_id, '_tix_seatmap_id', true));
        $seatmap_mode = get_post_meta($post_id, '_tix_seatmap_mode', true) ?: 'manual';
        $has_seatmap  = $seatmap_id > 0 && class_exists('TIX_Seatmap');

        if ($has_seatmap) {
            $section_data = TIX_Seatmap::get_section_data($seatmap_id, $post_id);
            if (!empty($section_data)) {
                return self::render_seatmap_mode($post_id, $seatmap_id, $seatmap_mode, $section_data);
            }
        }

        $categories = get_post_meta($post_id, '_tix_ticket_categories', true);
        if (!is_array($categories) || empty($categories)) {
            return '<p class="tix-sel-error">Keine Tickets verfügbar.</p>';
        }

        // Online + Offline-Tickets anzeigen (nicht: beide AUS)
        $categories = array_filter($categories, fn($c) =>
            ($c['online'] ?? '1') === '1' || !empty($c['offline_ticket'])
        );
        if (empty($categories)) {
            return '<p class="tix-sel-error">Keine Tickets verfügbar.</p>';
        }

        // Assets laden
        self::enqueue();

        // Settings
        $tix_s = tix_get_settings();
        $vat_text = $tix_s['vat_text_selector'] ?? 'inkl. MwSt.';

        ob_start();
        ?>
        <?php $tix_v = intval($atts['variant']) === 2 ? 2 : 1; ?>
        <div class="tix-sel<?php echo $atts['fullwidth'] === '1' ? ' tix-fullwidth' : ''; ?>" data-event-id="<?php echo $post_id; ?>"<?php if ($tix_v === 2): ?> style="--tix-btn1-bg:var(--tix-btn2-bg,transparent);--tix-btn1-color:var(--tix-btn2-color,inherit);--tix-btn1-hover-bg:var(--tix-btn2-hover-bg,transparent);--tix-btn1-hover-color:var(--tix-btn2-hover-color,inherit);--tix-btn1-radius:var(--tix-btn2-radius,8px);--tix-btn1-border:var(--tix-btn2-border,1px solid currentColor);--tix-btn1-font-size:var(--tix-btn2-font-size,0.9rem)"<?php endif; ?>>

            <?php
            // ── Charity-Banner ──
            $ch_global  = !empty($tix_s['charity_enabled']);
            $ch_enabled = $ch_global ? get_post_meta($post_id, '_tix_charity_enabled', true) : '';
            if ($ch_global && $ch_enabled === '1') {
                $ch_name    = get_post_meta($post_id, '_tix_charity_name', true);
                $ch_percent = intval(get_post_meta($post_id, '_tix_charity_percent', true));
                $ch_desc    = get_post_meta($post_id, '_tix_charity_desc', true);
                $ch_image   = intval(get_post_meta($post_id, '_tix_charity_image', true));
                $ch_img_url = $ch_image ? wp_get_attachment_image_url($ch_image, 'thumbnail') : '';
                if ($ch_name && $ch_percent > 0) {
            ?>
            <div class="tix-sel-charity">
                <?php if ($ch_img_url): ?>
                <img class="tix-sel-charity-img" src="<?php echo esc_url($ch_img_url); ?>" alt="<?php echo esc_attr($ch_name); ?>">
                <?php endif; ?>
                <div class="tix-sel-charity-info">
                    <span class="tix-sel-charity-badge">♥ <?php echo $ch_percent; ?>% für <?php echo esc_html($ch_name); ?></span>
                    <?php if ($ch_desc): ?>
                    <span class="tix-sel-charity-desc"><?php echo esc_html($ch_desc); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php
                }
            }
            ?>

            <div class="tix-sel-categories">
                <?php
                // Prüfe ob alle Online-Tickets ausverkauft (für Warteliste)
                $all_online_soldout = true;
                $has_any_online = false;
                foreach ($categories as $_chk_cat) {
                    $is_chk_offline = !empty($_chk_cat['offline_ticket']);
                    if (!$is_chk_offline) {
                        $has_any_online = true;
                        $chk_pid = intval($_chk_cat['product_id'] ?? 0);
                        if ($chk_pid) {
                            $chk_p = wc_get_product($chk_pid);
                            if ($chk_p && $chk_p->is_in_stock()) {
                                $all_online_soldout = false;
                                break;
                            }
                        }
                    }
                }
                if (!$has_any_online) $all_online_soldout = false;
                ?>
                <?php
                // Batch: Alle Produkt-IDs vorladen (1 Query statt N)
                $product_ids = array_filter(array_map(function($c) {
                    return empty($c['offline_ticket']) ? intval($c['product_id'] ?? 0) : 0;
                }, $categories));
                if (!empty($product_ids)) {
                    _prime_post_caches(array_values($product_ids), false, false);
                }
                ?>
                <?php
                // Tickets nach Gruppe gruppieren
                $grouped = [];
                foreach ($categories as $i => $cat) {
                    $grp = $cat['group'] ?? '';
                    $grouped[$grp][] = ['index' => $i, 'cat' => $cat];
                }
                ?>
                <?php foreach ($grouped as $group_name => $items):
                    if ($group_name !== ''):
                ?>
                    <div class="tix-sel-group-header"><?php echo esc_html($group_name); ?></div>
                <?php endif; ?>
                <?php foreach ($items as $item):
                    $i   = $item['index'];
                    $cat = $item['cat'];
                    $n          = $i + 1;
                    $product_id = intval($cat['product_id'] ?? 0);
                    $price      = floatval($cat['price']);
                    $sale_price = $cat['sale_price'] ?? '';
                    $has_sale   = ($sale_price !== '' && $sale_price !== null && $sale_price !== false);
                    $name       = esc_html($cat['name']);
                    $desc       = esc_html($cat['desc'] ?? '');
                    $qty_max    = intval($cat['qty'] ?? 100);
                    $is_offline = !empty($cat['offline_ticket']);

                    // Bundle Deal
                    $bundle_buy   = intval($cat['bundle_buy'] ?? 0);
                    $bundle_pay   = intval($cat['bundle_pay'] ?? 0);
                    $bundle_label = $cat['bundle_label'] ?? '';
                    $has_bundle   = !$is_offline && $bundle_buy >= 2 && $bundle_pay >= 1 && $bundle_pay < $bundle_buy;

                    // ── Phase-aware Pricing ──
                    $original_price = $price; // Hauptpreis sichern für Phase-Timeline
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

                    // Stock-Check über WooCommerce (nur für Online-Tickets)
                    $in_stock = true;
                    $stock_qty = null;
                    if (!$is_offline && $product_id) {
                        $product = wc_get_product($product_id);
                        if ($product) {
                            $in_stock  = $product->is_in_stock();
                            $stock_qty = $product->get_stock_quantity();
                        }
                    }
                ?>
                    <?php // ── Einzelticket-Zeile ── ?>
                    <div class="tix-sel-cat <?php echo $is_offline ? 'tix-sel-offline' : (!$in_stock ? 'tix-sel-soldout' : ''); ?>"
                         data-product-id="<?php echo $is_offline ? '0' : $product_id; ?>"
                         data-price="<?php echo $is_offline ? '0' : $effective; ?>"
                         data-index="<?php echo $n; ?>"
                         data-has-phase="<?php echo $active_phase ? '1' : '0'; ?>">

                        <div class="tix-sel-cat-info">
                            <div class="tix-sel-cat-name"><?php echo $name; ?></div>
                            <?php if ($active_phase): ?>
                                <div class="tix-sel-phase-badge"><?php echo esc_html($active_phase['name']); ?></div>
                            <?php endif; ?>
                            <?php if ($desc): ?>
                                <div class="tix-sel-cat-desc"><?php echo $desc; ?></div>
                            <?php endif; ?>
                            <?php
                            // ── Phasen-Timeline (alle Phasen + Hauptpreis) ──
                            $all_phases = $cat['phases'] ?? [];
                            $ep_s = tix_get_settings();
                            if (!empty($all_phases) && is_array($all_phases) && !empty($ep_s['ep_show_phases'])):
                                $now = current_time('Y-m-d');
                            ?>
                            <div class="tix-sel-phases-timeline">
                                <?php foreach ($all_phases as $pi => $ph):
                                    $ph_name  = $ph['name'] ?? ('Phase ' . ($pi + 1));
                                    $ph_price = floatval($ph['price'] ?? 0);
                                    $ph_until = $ph['until'] ?? '';
                                    $is_active = ($active_phase && ($active_phase['name'] ?? '') === $ph_name && ($active_phase['until'] ?? '') === $ph_until);
                                    $is_past   = ($ph_until && $now > $ph_until);
                                    $state_class = $is_active ? 'tix-sel-phase--active' : ($is_past ? 'tix-sel-phase--past' : 'tix-sel-phase--future');
                                ?>
                                <div class="tix-sel-phase-item <?php echo $state_class; ?>">
                                    <span class="tix-sel-phase-dot"></span>
                                    <span class="tix-sel-phase-name"><?php echo esc_html($ph_name); ?></span>
                                    <span class="tix-sel-phase-price"><?php echo number_format($ph_price, 2, ',', '.'); ?>&nbsp;&euro;</span>
                                    <?php if ($ph_until): ?>
                                        <span class="tix-sel-phase-until">bis <?php echo date_i18n('d.m.Y', strtotime($ph_until)); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <div class="tix-sel-phase-item tix-sel-phase--regular <?php echo (!$active_phase ? 'tix-sel-phase--active' : ''); ?>">
                                    <span class="tix-sel-phase-dot"></span>
                                    <span class="tix-sel-phase-name">Regul&auml;r</span>
                                    <span class="tix-sel-phase-price"><?php echo number_format($original_price, 2, ',', '.'); ?>&nbsp;&euro;</span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="tix-sel-cat-price">
                            <?php if ($has_sale && !$is_offline): ?>
                                <span class="tix-sel-price-sale"><?php echo number_format($effective, 2, ',', '.'); ?>&nbsp;€</span>
                                <span class="tix-sel-price-old"><?php echo number_format($price, 2, ',', '.'); ?>&nbsp;€</span>
                            <?php else: ?>
                                <span class="tix-sel-price-regular"><?php echo number_format($price, 2, ',', '.'); ?>&nbsp;€</span>
                            <?php endif; ?>
                            <span class="tix-sel-vat"><?php echo esc_html($vat_text); ?></span>
                            <?php
                            // ── Low-Stock-Badge ──
                            $low_threshold = intval(tix_get_settings('low_stock_threshold') ?? 10);
                            if (!$is_offline && $in_stock && $stock_qty !== null && $stock_qty > 0 && $low_threshold > 0 && $stock_qty <= $low_threshold):
                            ?>
                                <span class="tix-sel-low-stock">Nur noch <?php echo intval($stock_qty); ?> verfügbar!</span>
                            <?php endif; ?>
                        </div>

                        <div class="tix-sel-cat-qty">
                            <?php if ($is_offline): ?>
                                <span class="tix-sel-offline-label">Abendkasse</span>
                            <?php elseif ($in_stock): ?>
                                <button type="button" class="tix-sel-btn tix-sel-minus" aria-label="Weniger">−</button>
                                <span class="tix-sel-qty-val" data-qty="0">0</span>
                                <button type="button" class="tix-sel-btn tix-sel-plus" aria-label="Mehr">+</button>
                            <?php else: ?>
                                <span class="tix-sel-soldout-label">Ausverkauft</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php
                    // ── Saalplan-Picker (wenn Kategorie einen Saalplan hat) ──
                    $cat_seatmap_id = intval($cat['seatmap_id'] ?? 0);
                    if ($cat_seatmap_id && $in_stock && !$is_offline):
                        $cat_seatmap_section = $cat['seatmap_section'] ?? '';
                        self::enqueue_seatmap();
                    ?>
                    <div class="tix-sel-seatmap-picker"
                         data-tix-seatmap-picker
                         data-event-id="<?php echo $post_id; ?>"
                         data-seatmap-id="<?php echo $cat_seatmap_id; ?>"
                         data-section-id="<?php echo esc_attr($cat_seatmap_section); ?>"
                         data-category-index="<?php echo $i; ?>"
                         data-max-seats="<?php echo $qty_max ?: 99; ?>"
                         data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">
                    </div>
                    <?php endif; ?>

                    <?php // ── Bundle-Zeile (eigene eigenständige Position) ── ?>
                    <?php if ($has_bundle && $in_stock):
                        $b_name   = $bundle_label ?: $bundle_buy . 'er-Paket ' . $name;
                        $b_total  = $bundle_pay * $effective;
                        $b_orig   = $bundle_buy * $effective;
                        $b_save   = round((1 - $bundle_pay / $bundle_buy) * 100);
                        $b_free   = $bundle_buy - $bundle_pay;
                    ?>
                    <div class="tix-sel-cat tix-sel-bundle"
                         data-product-id="<?php echo $product_id; ?>"
                         data-price="<?php echo $b_total; ?>"
                         data-index="<?php echo $n; ?>b"
                         data-bundle="1"
                         data-bundle-buy="<?php echo $bundle_buy; ?>"
                         data-bundle-pay="<?php echo $bundle_pay; ?>"
                         data-bundle-label="<?php echo esc_attr($b_name); ?>"
                         data-has-phase="<?php echo $active_phase ? '1' : '0'; ?>">

                        <div class="tix-sel-cat-info">
                            <div class="tix-sel-cat-name">
                                <span class="tix-sel-bundle-icon">🎁</span>
                                <?php echo esc_html($b_name); ?>
                            </div>
                            <div class="tix-sel-cat-desc">
                                <?php echo $bundle_buy; ?>× <?php echo $name; ?> – nur <?php echo $bundle_pay; ?> zahlen, <?php echo $b_free; ?> gratis
                                <span class="tix-sel-bundle-save"><?php echo $b_save; ?>% sparen</span>
                            </div>
                        </div>

                        <div class="tix-sel-cat-price">
                            <span class="tix-sel-price-sale"><?php echo number_format($b_total, 2, ',', '.'); ?>&nbsp;€</span>
                            <span class="tix-sel-price-old"><?php echo number_format($b_orig, 2, ',', '.'); ?>&nbsp;€</span>
                            <span class="tix-sel-vat"><?php echo esc_html($vat_text); ?></span>
                        </div>

                        <div class="tix-sel-cat-qty">
                            <button type="button" class="tix-sel-btn tix-sel-minus" aria-label="Weniger">−</button>
                            <span class="tix-sel-qty-val" data-qty="0">0</span>
                            <button type="button" class="tix-sel-btn tix-sel-plus" aria-label="Mehr">+</button>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php endforeach; ?>
                <?php endforeach; ?>

            <?php // ── Kombi-Tickets (automatische Gruppe) ── ?>
            <?php
            $combos = get_post_meta($post_id, '_tix_combo_deals', true);
            if (is_array($combos) && !empty($combos)):
                // Batch: Alle Partner-Event-IDs vorladen
                $partner_event_ids = [];
                foreach ($combos as $combo) {
                    foreach (($combo['partners'] ?? []) as $p) {
                        $partner_event_ids[] = intval($p['event_id']);
                    }
                }
                if (!empty($partner_event_ids)) {
                    _prime_post_caches(array_unique($partner_event_ids), true, true);
                }

                // ── Pre-Validierung: nur Header zeigen wenn mind. 1 Kombi gültig ──
                $any_combo_valid = false;
                foreach ($combos as $_c) {
                    $_sci = intval($_c['self_cat_index'] ?? 0);
                    $_sc  = $categories[$_sci] ?? null;
                    if (!$_sc || empty($_sc['product_id'])) continue;
                    $_pv = true;
                    foreach (($_c['partners'] ?? []) as $_p) {
                        $_pid = intval($_p['event_id']);
                        $_pev = get_post($_pid);
                        if (!$_pev || $_pev->post_status !== 'publish') { $_pv = false; break; }
                        if (get_post_meta($_pid, '_tix_tickets_enabled', true) !== '1' || get_post_meta($_pid, '_tix_presale_active', true) !== '1') { $_pv = false; break; }
                        $_pcats = get_post_meta($_pid, '_tix_ticket_categories', true);
                        $_pci = intval($_p['cat_index'] ?? 0);
                        if (!is_array($_pcats) || !isset($_pcats[$_pci]) || empty($_pcats[$_pci]['product_id'])) { $_pv = false; break; }
                    }
                    if ($_pv && !empty($_c['partners'])) { $any_combo_valid = true; break; }
                }
            ?>
                <?php if ($any_combo_valid): ?>
                <div class="tix-sel-group-header">Kombi-Tickets</div>
                <?php endif; ?>
                <?php foreach ($combos as $ci => $combo):
                    $combo_label = esc_html($combo['label']);
                    $combo_price = floatval($combo['price']);
                    $combo_id    = esc_attr($combo['id'] ?? '');

                    // Self-Event Kategorie auflösen
                    $self_ci  = intval($combo['self_cat_index'] ?? 0);
                    $self_cat = $categories[$self_ci] ?? null;
                    if (!$self_cat || empty($self_cat['product_id'])) continue;

                    $combo_items = [];
                    $combo_event_labels = [];
                    $original_sum = 0;
                    $all_in_stock = true;

                    // Self-Event
                    $self_product = wc_get_product(intval($self_cat['product_id']));
                    if (!$self_product || !$self_product->is_in_stock()) $all_in_stock = false;
                    $self_price = $self_product ? floatval($self_product->get_price()) : 0;
                    $original_sum += $self_price;
                    $combo_items[] = ['product_id' => intval($self_cat['product_id']), 'price' => $self_price];
                    $combo_event_labels[] = get_the_title($post_id) . ' – ' . esc_html($self_cat['name']);

                    // Partner-Events
                    $partners_valid = true;
                    foreach (($combo['partners'] ?? []) as $partner) {
                        $pev_id = intval($partner['event_id']);
                        $pev = get_post($pev_id);
                        if (!$pev || $pev->post_status !== 'publish') { $partners_valid = false; break; }

                        $p_enabled = get_post_meta($pev_id, '_tix_tickets_enabled', true);
                        $p_presale = get_post_meta($pev_id, '_tix_presale_active', true);
                        if ($p_enabled !== '1' || $p_presale !== '1') { $partners_valid = false; break; }

                        $p_cats = get_post_meta($pev_id, '_tix_ticket_categories', true);
                        $p_ci = intval($partner['cat_index'] ?? 0);
                        if (!is_array($p_cats) || !isset($p_cats[$p_ci])) { $partners_valid = false; break; }
                        $p_cat = $p_cats[$p_ci];
                        $p_pid = intval($p_cat['product_id'] ?? 0);
                        if (!$p_pid) { $partners_valid = false; break; }

                        $p_product = wc_get_product($p_pid);
                        if (!$p_product || !$p_product->is_in_stock()) $all_in_stock = false;
                        $p_price = $p_product ? floatval($p_product->get_price()) : 0;
                        $original_sum += $p_price;
                        $combo_items[] = ['product_id' => $p_pid, 'price' => $p_price];
                        $combo_event_labels[] = esc_html($pev->post_title) . ' – ' . esc_html($p_cat['name']);
                    }

                    if (!$partners_valid || count($combo_items) < 2) continue;

                    $savings = $original_sum - $combo_price;
                    $savings_pct = $original_sum > 0 ? round(($savings / $original_sum) * 100) : 0;
                ?>
                <div class="tix-sel-cat tix-sel-combo <?php echo !$all_in_stock ? 'tix-sel-soldout' : ''; ?>"
                     data-combo-id="<?php echo $combo_id; ?>"
                     data-combo-price="<?php echo $combo_price; ?>"
                     data-combo-items='<?php echo esc_attr(json_encode($combo_items)); ?>'
                     data-combo-label="<?php echo esc_attr($combo['label']); ?>"
                     data-index="combo<?php echo $ci; ?>">

                    <div class="tix-sel-cat-info">
                        <div class="tix-sel-cat-name">
                            <span class="tix-sel-combo-icon">🎫</span>
                            <?php echo $combo_label; ?>
                        </div>
                        <div class="tix-sel-cat-desc tix-sel-combo-events">
                            <?php echo implode('<br>', $combo_event_labels); ?>
                            <?php if ($savings_pct > 0): ?>
                                <span class="tix-sel-bundle-save"><?php echo $savings_pct; ?>% sparen</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tix-sel-cat-price">
                        <?php if ($savings > 0): ?>
                            <span class="tix-sel-price-sale"><?php echo number_format($combo_price, 2, ',', '.'); ?>&nbsp;€</span>
                            <span class="tix-sel-price-old"><?php echo number_format($original_sum, 2, ',', '.'); ?>&nbsp;€</span>
                        <?php else: ?>
                            <span class="tix-sel-price-regular"><?php echo number_format($combo_price, 2, ',', '.'); ?>&nbsp;€</span>
                        <?php endif; ?>
                        <span class="tix-sel-vat"><?php echo esc_html($vat_text); ?></span>
                    </div>

                    <div class="tix-sel-cat-qty">
                        <?php if ($all_in_stock): ?>
                            <button type="button" class="tix-sel-btn tix-sel-minus" aria-label="Weniger">−</button>
                            <span class="tix-sel-qty-val" data-qty="0">0</span>
                            <button type="button" class="tix-sel-btn tix-sel-plus" aria-label="Mehr">+</button>
                        <?php else: ?>
                            <span class="tix-sel-soldout-label">Ausverkauft</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>

            <?php
            // Footer nur anzeigen wenn mindestens eine Online-Kategorie existiert
            $has_online = false;
            foreach ($categories as $c) {
                if (empty($c['offline_ticket']) && ($c['online'] ?? '1') === '1') {
                    $has_online = true;
                    break;
                }
            }
            ?>

            <?php if ($has_online): ?>

            <?php // ── Gruppenrabatt-Hinweis ── ?>
            <?php
            $group_discount = get_post_meta($post_id, '_tix_group_discount', true);
            $gd_tiers = [];
            $gd_combine_combo  = false;
            $gd_combine_bundle = false;
            $gd_combine_phase  = false;
            if (!empty($group_discount['enabled']) && !empty($group_discount['tiers'])) {
                $gd_tiers = $group_discount['tiers'];
                $gd_combine_combo  = !empty($group_discount['combine_combo']);
                $gd_combine_bundle = !empty($group_discount['combine_bundle']);
                $gd_combine_phase  = !empty($group_discount['combine_phase']);
            }
            ?>
            <?php if (!empty($gd_tiers)): ?>
            <div class="tix-sel-group-discount" data-tiers='<?php echo esc_attr(json_encode($gd_tiers)); ?>' data-combine-combo="<?php echo $gd_combine_combo ? '1' : '0'; ?>" data-combine-bundle="<?php echo $gd_combine_bundle ? '1' : '0'; ?>" data-combine-phase="<?php echo $gd_combine_phase ? '1' : '0'; ?>">
                <div class="tix-sel-gd-icon">👥</div>
                <div class="tix-sel-gd-info">
                    <span class="tix-sel-gd-title">Mengenrabatt</span>
                    <span class="tix-sel-gd-tiers">
                        <?php foreach ($gd_tiers as $tier): ?>
                            <span class="tix-sel-gd-tier">Ab <?php echo $tier['min_qty']; ?> Tickets: <strong><?php echo $tier['percent']; ?>%</strong></span>
                        <?php endforeach; ?>
                    </span>
                </div>
                <div class="tix-sel-gd-badge" style="display:none;"></div>
            </div>
            <?php endif; ?>

            <?php // ── Warteliste (bei Ausverkauf) ── ?>
            <?php
            $wl_event_enabled = get_post_meta($post_id, '_tix_waitlist_enabled', true);
            if ($all_online_soldout && !empty($tix_s['waitlist_enabled']) && $wl_event_enabled === '1'):
                $wl_nonce = wp_create_nonce('tix_waitlist_' . $post_id);
            ?>
            <div class="tix-sel-waitlist">
                <form class="tix-sel-notify-form" data-event="<?php echo $post_id; ?>" data-type="soldout" data-nonce="<?php echo $wl_nonce; ?>">
                    <p class="tix-sel-notify-text">Auf die Warteliste setzen:</p>
                    <div class="tix-sel-notify-fields">
                        <input type="email" name="email" placeholder="Deine E-Mail-Adresse" required>
                        <button type="submit" class="tix-sel-notify-btn">Benachrichtigen</button>
                    </div>
                    <div class="tix-sel-notify-msg" hidden></div>
                </form>
            </div>
            <script>
            (function(){
                document.querySelectorAll('.tix-sel-notify-form').forEach(function(form){
                    if (form._tixBound) return; form._tixBound = true;
                    form.addEventListener('submit', function(e){
                        e.preventDefault();
                        var btn = form.querySelector('.tix-sel-notify-btn');
                        var msg = form.querySelector('.tix-sel-notify-msg');
                        var email = form.querySelector('input[name="email"]').value.trim();
                        if (!email) return;
                        btn.disabled = true; btn.textContent = '…';
                        var fd = new FormData();
                        fd.append('action','tix_waitlist_join');
                        fd.append('event_id', form.dataset.event);
                        fd.append('type', form.dataset.type);
                        fd.append('nonce', form.dataset.nonce);
                        fd.append('email', email);
                        fetch(ehSel.ajaxUrl, {method:'POST',body:fd})
                            .then(function(r){return r.json();})
                            .then(function(data){
                                if (data.success) {
                                    form.innerHTML = '<p class="tix-sel-notify-success">✅ '+data.data.message+'</p>';
                                } else {
                                    msg.textContent = data.data.message||'Fehler';
                                    msg.hidden = false; msg.className = 'tix-sel-notify-msg tix-sel-notify-msg--error';
                                    btn.disabled = false; btn.textContent = 'Benachrichtigen';
                                }
                            })
                            .catch(function(){
                                msg.textContent = 'Verbindungsfehler.';
                                msg.hidden = false; msg.className = 'tix-sel-notify-msg tix-sel-notify-msg--error';
                                btn.disabled = false; btn.textContent = 'Benachrichtigen';
                            });
                    });
                });
            })();
            </script>
            <?php endif; ?>

            <?php // ── Gutscheincode ── ?>
            <?php if (!empty($tix_s['show_coupon_selector'])): ?>
            <div class="tix-sel-coupon">
                <div class="tix-sel-coupon-input-wrap">
                    <input type="text" class="tix-sel-coupon-code" placeholder="Gutscheincode">
                    <button type="button" class="tix-sel-coupon-btn">Einlösen</button>
                </div>
                <div class="tix-sel-coupon-result" style="display:none;"></div>
            </div>
            <?php endif; ?>

            <div class="tix-sel-footer">
                <div class="tix-sel-total">
                    <span class="tix-sel-total-label">Gesamt <span class="tix-sel-vat-note"><?php echo esc_html($vat_text); ?></span></span>
                    <span class="tix-sel-total-price">0,00 €</span>
                </div>
                <button type="button" class="tix-sel-buy" disabled>
                    <?php $buy_text = $tix_s['btn_text_buy'] ?? 'Weiter zur Kasse'; ?>
                    <span class="tix-sel-buy-text"><?php echo esc_html($buy_text); ?></span>
                    <span class="tix-sel-buy-loading" style="display:none;">Wird hinzugefügt…</span>
                </button>
                <?php
                $show_express = false;
                try {
                    if (is_user_logged_in()
                        && function_exists('tix_get_settings')
                        && !empty(tix_get_settings('express_checkout_enabled'))
                        && get_post_meta($post_id, '_tix_express_checkout', true) === '1'
                    ) {
                        $uid = get_current_user_id();
                        $has_tokens = class_exists('WC_Payment_Tokens')
                            && !empty(WC_Payment_Tokens::get_customer_tokens($uid));
                        $has_bacs = false;
                        if (class_exists('WooCommerce') && function_exists('WC')) {
                            $all_gateways = WC()->payment_gateways()->payment_gateways();
                            $has_bacs = isset($all_gateways['bacs']) && $all_gateways['bacs']->enabled === 'yes';
                        }
                        if ($has_tokens || $has_bacs) {
                            $customer = new \WC_Customer($uid);
                            $show_express = !empty($customer->get_billing_first_name())
                                && !empty($customer->get_billing_last_name())
                                && !empty($customer->get_billing_email());
                        }
                    }
                } catch (\Throwable $e) { $show_express = false; }
                if ($show_express):
                    $terms_url      = tix_get_settings('terms_url') ?: '';
                    $privacy_url    = tix_get_settings('privacy_url') ?: '';
                    $revocation_url = tix_get_settings('revocation_url') ?: '';
                ?>
                <div class="tix-sel-express-wrap">
                    <label class="tix-sel-express-terms">
                        <input type="checkbox" class="tix-sel-express-terms-check" value="1">
                        <span class="tix-sel-express-terms-custom"></span>
                        <span>Ich akzeptiere die <?php if ($terms_url): ?><a href="<?php echo esc_url($terms_url); ?>" target="_blank">Nutzungsbedingungen</a><?php else: ?><u>Nutzungsbedingungen</u><?php endif; ?>, <?php if ($privacy_url): ?><a href="<?php echo esc_url($privacy_url); ?>" target="_blank">Datenschutzhinweise</a><?php else: ?><u>Datenschutzhinweise</u><?php endif; ?> und <?php if ($revocation_url): ?><a href="<?php echo esc_url($revocation_url); ?>" target="_blank">Widerrufsbelehrung</a><?php else: ?><u>Widerrufsbelehrung</u><?php endif; ?>. Mit Klick auf &bdquo;Express-Checkout&ldquo; wird der Kauf direkt ausgelöst.</span>
                    </label>
                    <button type="button" class="tix-sel-express" disabled>
                        <span class="tix-sel-express-text">Express-Checkout</span>
                        <span class="tix-sel-express-loading" style="display:none;">Wird verarbeitet…</span>
                    </button>
                    <span class="tix-sel-express-note">Express-Checkout überspringt den Warenkorb und schließt den Kauf direkt ab.</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="tix-sel-message" style="display:none;"></div>

            <?php
            // ── Gemeinsam buchen ──
            $group_enabled = tix_get_settings('group_booking');
            if ($group_enabled && !isset($_GET['tix_embed'])):
            ?>
            <div class="tix-group-trigger">
                <button type="button" class="tix-group-btn">👥 Gemeinsam buchen</button>
            </div>

            <div class="tix-group-panel" style="display:none;"></div>
            <?php endif; ?>
            <?php echo tix_branding_footer(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: [tix_express_checkout]
     * Optional: [tix_express_checkout id="123" label="Jetzt kaufen"]
     * Zeigt einen Button, der ein Modal mit Ticket-Auswahl + Express-Checkout öffnet.
     */
    public static function render_express_modal($atts) {
        $atts = shortcode_atts(['id' => 0, 'label' => 'Express-Checkout'], $atts);
        $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') {
            return '';
        }

        // Voraussetzungen prüfen
        $enabled = get_post_meta($post_id, '_tix_tickets_enabled', true);
        if ($enabled !== '1') return '';

        $presale = get_post_meta($post_id, '_tix_presale_active', true);
        if ($presale !== '1') return '';

        // Express Checkout muss global + Event aktiviert sein
        if (empty(tix_get_settings('express_checkout_enabled'))) return '';
        if (get_post_meta($post_id, '_tix_express_checkout', true) !== '1') return '';

        // User muss eingeloggt sein + Zahlungsmethode haben
        if (!is_user_logged_in()) return '';

        $can_express = false;
        try {
            $uid = get_current_user_id();
            $has_tokens = class_exists('WC_Payment_Tokens')
                && !empty(WC_Payment_Tokens::get_customer_tokens($uid));
            $has_bacs = false;
            if (class_exists('WooCommerce') && function_exists('WC')) {
                $all_gateways = WC()->payment_gateways()->payment_gateways();
                $has_bacs = isset($all_gateways['bacs']) && $all_gateways['bacs']->enabled === 'yes';
            }
            if ($has_tokens || $has_bacs) {
                $customer = new \WC_Customer($uid);
                $can_express = !empty($customer->get_billing_first_name())
                    && !empty($customer->get_billing_last_name())
                    && !empty($customer->get_billing_email());
            }
        } catch (\Throwable $e) { $can_express = false; }

        if (!$can_express) return '';

        $categories = get_post_meta($post_id, '_tix_ticket_categories', true);
        if (!is_array($categories) || empty($categories)) return '';

        // Nur Online-Tickets mit Lagerbestand
        $categories = array_filter($categories, fn($c) =>
            ($c['online'] ?? '1') === '1' && empty($c['offline_ticket'])
        );
        if (empty($categories)) return '';

        // Assets laden
        self::enqueue();
        wp_enqueue_style('tix-express-modal', TIXOMAT_URL . 'assets/css/express-modal.css', ['tix-ticket-selector'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-express-modal', TIXOMAT_URL . 'assets/js/express-modal.js', ['tix-ticket-selector'], TIXOMAT_VERSION, true);

        $tix_s = tix_get_settings();
        $vat_text = $tix_s['vat_text_selector'] ?? 'inkl. MwSt.';
        $terms_url      = tix_get_settings('terms_url') ?: '';
        $privacy_url    = tix_get_settings('privacy_url') ?: '';
        $revocation_url = tix_get_settings('revocation_url') ?: '';
        $event_title = get_the_title($post_id);
        $modal_id = 'tix-ec-modal-' . $post_id . '-' . wp_unique_id();

        ob_start();
        ?>
        <span class="tix-ec-trigger" data-modal="<?php echo esc_attr($modal_id); ?>">
            <button type="button" class="tix-ec-trigger-btn"><?php echo esc_html($atts['label']); ?></button>
        </span>

        <div class="tix-ec-overlay" id="<?php echo esc_attr($modal_id); ?>" style="display:none;" data-event-id="<?php echo $post_id; ?>">
            <div class="tix-ec-modal">
                <div class="tix-ec-header">
                    <div>
                        <h3 class="tix-ec-title">Express-Checkout</h3>
                        <span class="tix-ec-event-name"><?php echo esc_html($event_title); ?></span>
                    </div>
                    <button type="button" class="tix-ec-close" aria-label="Schließen">&times;</button>
                </div>

                <div class="tix-ec-body">
                    <div class="tix-ec-cats">
                        <?php
                        $product_ids = array_filter(array_map(fn($c) => intval($c['product_id'] ?? 0), $categories));
                        if (!empty($product_ids)) _prime_post_caches(array_values($product_ids), false, false);

                        foreach ($categories as $i => $cat):
                            $product_id = intval($cat['product_id'] ?? 0);
                            $price      = floatval($cat['price']);
                            $sale_price = $cat['sale_price'] ?? '';
                            $has_sale   = ($sale_price !== '' && $sale_price !== null && $sale_price !== false);
                            $name       = esc_html($cat['name']);
                            $qty_max    = intval($cat['qty'] ?? 100);

                            // Phase-aware Pricing
                            $active_phase = null;
                            if (class_exists('TIX_Metabox')) {
                                $active_phase = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
                            }
                            if ($active_phase) {
                                $phase_price = floatval($active_phase['price']);
                                if ($phase_price < $price) {
                                    $effective = $phase_price;
                                    $has_sale = true;
                                    $sale_price = $phase_price;
                                } else {
                                    $effective = $phase_price;
                                    $price = $phase_price;
                                    $has_sale = false;
                                }
                            } else {
                                $effective = $has_sale ? floatval($sale_price) : $price;
                            }

                            // Stock-Check
                            $in_stock = true;
                            if ($product_id) {
                                $product = wc_get_product($product_id);
                                if ($product) $in_stock = $product->is_in_stock();
                            }
                            if (!$in_stock) continue;
                        ?>
                        <div class="tix-ec-cat"
                             data-product-id="<?php echo $product_id; ?>"
                             data-price="<?php echo $effective; ?>"
                             data-max="<?php echo $qty_max; ?>">
                            <div class="tix-ec-cat-info">
                                <span class="tix-ec-cat-name"><?php echo $name; ?></span>
                                <span class="tix-ec-cat-price">
                                    <?php if ($has_sale): ?>
                                        <span class="tix-ec-price-sale"><?php echo number_format($effective, 2, ',', '.'); ?>&nbsp;€</span>
                                        <span class="tix-ec-price-old"><?php echo number_format($price, 2, ',', '.'); ?>&nbsp;€</span>
                                    <?php else: ?>
                                        <?php echo number_format($price, 2, ',', '.'); ?>&nbsp;€
                                    <?php endif; ?>
                                    <span class="tix-ec-vat"><?php echo esc_html($vat_text); ?></span>
                                </span>
                            </div>
                            <div class="tix-ec-cat-qty">
                                <button type="button" class="tix-ec-btn tix-ec-minus" aria-label="Weniger">−</button>
                                <span class="tix-ec-qty-val" data-qty="0">0</span>
                                <button type="button" class="tix-ec-btn tix-ec-plus" aria-label="Mehr">+</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    // ── Angebote sammeln (Bundles, Combos, Mengenrabatt) ──
                    $has_bundles = false;
                    foreach ($categories as $cat) {
                        $bb = intval($cat['bundle_buy'] ?? 0);
                        $bp = intval($cat['bundle_pay'] ?? 0);
                        if ($bb >= 2 && $bp >= 1 && $bp < $bb && empty($cat['offline_ticket'])) {
                            $has_bundles = true;
                            break;
                        }
                    }

                    $combos = get_post_meta($post_id, '_tix_combo_deals', true);
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
                    $has_gd = !empty($gd_tiers);

                    $has_offers = $has_bundles || $has_combos || $has_gd;
                    ?>

                    <?php if ($has_offers): ?>
                    <div class="tix-ec-offers-toggle">
                        <button type="button" class="tix-ec-offers-btn">
                            <span class="tix-ec-offers-icon">+</span>
                            Angebote anzeigen
                        </button>
                    </div>

                    <div class="tix-ec-offers" style="display:none;">

                        <?php // ── Paketangebote (Bundles) ── ?>
                        <?php if ($has_bundles): ?>
                        <div class="tix-ec-offer-section">
                            <div class="tix-ec-offer-heading">🎁 Paketangebote</div>
                            <div class="tix-ec-cats">
                                <?php foreach ($categories as $i => $cat):
                                    $bb = intval($cat['bundle_buy'] ?? 0);
                                    $bp = intval($cat['bundle_pay'] ?? 0);
                                    if ($bb < 2 || $bp < 1 || $bp >= $bb || !empty($cat['offline_ticket'])) continue;

                                    $product_id = intval($cat['product_id'] ?? 0);
                                    $price      = floatval($cat['price']);
                                    $sale_price = $cat['sale_price'] ?? '';
                                    $has_sale   = ($sale_price !== '' && $sale_price !== null && $sale_price !== false);
                                    $name       = esc_html($cat['name']);
                                    $b_label    = $cat['bundle_label'] ?: $bb . 'er-Paket ' . $name;

                                    // Phase Pricing
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

                                    // Stock
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
                                    // Max Pakete = floor(max_qty / bundle_buy)
                                    $pkg_max = max(1, floor($qty_max / $bb));
                                ?>
                                <div class="tix-ec-cat tix-ec-bundle"
                                     data-product-id="<?php echo $product_id; ?>"
                                     data-price="<?php echo $b_total; ?>"
                                     data-max="<?php echo $pkg_max; ?>"
                                     data-bundle="1"
                                     data-bundle-buy="<?php echo $bb; ?>"
                                     data-bundle-pay="<?php echo $bp; ?>"
                                     data-bundle-label="<?php echo esc_attr($b_label); ?>">
                                    <div class="tix-ec-cat-info">
                                        <span class="tix-ec-cat-name">🎁 <?php echo esc_html($b_label); ?></span>
                                        <span class="tix-ec-cat-price">
                                            <span class="tix-ec-price-sale"><?php echo number_format($b_total, 2, ',', '.'); ?>&nbsp;€</span>
                                            <span class="tix-ec-price-old"><?php echo number_format($b_orig, 2, ',', '.'); ?>&nbsp;€</span>
                                            <span class="tix-ec-vat"><?php echo esc_html($vat_text); ?></span>
                                        </span>
                                        <span class="tix-ec-offer-desc"><?php echo $bb; ?>× – nur <?php echo $bp; ?> zahlen, <?php echo $b_free; ?> gratis <span class="tix-ec-offer-save"><?php echo $b_save; ?>% sparen</span></span>
                                    </div>
                                    <div class="tix-ec-cat-qty">
                                        <button type="button" class="tix-ec-btn tix-ec-minus" aria-label="Weniger">−</button>
                                        <span class="tix-ec-qty-val" data-qty="0">0</span>
                                        <button type="button" class="tix-ec-btn tix-ec-plus" aria-label="Mehr">+</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php // ── Kombi-Tickets ── ?>
                        <?php if ($has_combos):
                            $partner_event_ids = [];
                            foreach ($combos as $combo) {
                                foreach (($combo['partners'] ?? []) as $p) {
                                    $partner_event_ids[] = intval($p['event_id']);
                                }
                            }
                            if (!empty($partner_event_ids)) {
                                _prime_post_caches(array_unique($partner_event_ids), true, true);
                            }
                            // Pre-Validierung: mind. 1 Kombi gültig + auf Lager?
                            $any_ec_combo_valid = false;
                            foreach ($combos as $_c) {
                                $_sci = intval($_c['self_cat_index'] ?? 0);
                                $_sc  = $categories[$_sci] ?? null;
                                if (!$_sc || empty($_sc['product_id'])) continue;
                                $_pv = true; $_ais = true;
                                $_sp = wc_get_product(intval($_sc['product_id']));
                                if (!$_sp || !$_sp->is_in_stock()) $_ais = false;
                                foreach (($_c['partners'] ?? []) as $_p) {
                                    $_pid = intval($_p['event_id']); $_pev = get_post($_pid);
                                    if (!$_pev || $_pev->post_status !== 'publish') { $_pv = false; break; }
                                    if (get_post_meta($_pid, '_tix_tickets_enabled', true) !== '1' || get_post_meta($_pid, '_tix_presale_active', true) !== '1') { $_pv = false; break; }
                                    $_pcats = get_post_meta($_pid, '_tix_ticket_categories', true); $_pci = intval($_p['cat_index'] ?? 0);
                                    if (!is_array($_pcats) || !isset($_pcats[$_pci]) || empty($_pcats[$_pci]['product_id'])) { $_pv = false; break; }
                                    $_pp = wc_get_product(intval($_pcats[$_pci]['product_id']));
                                    if (!$_pp || !$_pp->is_in_stock()) $_ais = false;
                                }
                                if ($_pv && $_ais && !empty($_c['partners'])) { $any_ec_combo_valid = true; break; }
                            }
                        ?>
                        <?php if ($any_ec_combo_valid): ?>
                        <div class="tix-ec-offer-section">
                            <div class="tix-ec-offer-heading">🎫 Kombi-Tickets</div>
                            <div class="tix-ec-cats">
                                <?php foreach ($combos as $ci => $combo):
                                    $combo_label = esc_html($combo['label']);
                                    $combo_price = floatval($combo['price']);
                                    $combo_id    = esc_attr($combo['id'] ?? '');

                                    $self_ci  = intval($combo['self_cat_index'] ?? 0);
                                    $self_cat = $categories[$self_ci] ?? null;
                                    if (!$self_cat || empty($self_cat['product_id'])) continue;

                                    $combo_items = [];
                                    $combo_event_labels = [];
                                    $original_sum = 0;
                                    $all_in_stock = true;

                                    $self_product = wc_get_product(intval($self_cat['product_id']));
                                    if (!$self_product || !$self_product->is_in_stock()) $all_in_stock = false;
                                    $self_price = $self_product ? floatval($self_product->get_price()) : 0;
                                    $original_sum += $self_price;
                                    $combo_items[] = ['product_id' => intval($self_cat['product_id']), 'price' => $self_price];
                                    $combo_event_labels[] = get_the_title($post_id) . ' – ' . esc_html($self_cat['name']);

                                    $partners_valid = true;
                                    foreach (($combo['partners'] ?? []) as $partner) {
                                        $pev_id = intval($partner['event_id']);
                                        $pev = get_post($pev_id);
                                        if (!$pev || $pev->post_status !== 'publish') { $partners_valid = false; break; }
                                        $p_enabled = get_post_meta($pev_id, '_tix_tickets_enabled', true);
                                        $p_presale = get_post_meta($pev_id, '_tix_presale_active', true);
                                        if ($p_enabled !== '1' || $p_presale !== '1') { $partners_valid = false; break; }
                                        $p_cats = get_post_meta($pev_id, '_tix_ticket_categories', true);
                                        $p_ci = intval($partner['cat_index'] ?? 0);
                                        if (!is_array($p_cats) || !isset($p_cats[$p_ci])) { $partners_valid = false; break; }
                                        $p_cat = $p_cats[$p_ci];
                                        $p_pid = intval($p_cat['product_id'] ?? 0);
                                        if (!$p_pid) { $partners_valid = false; break; }
                                        $p_product = wc_get_product($p_pid);
                                        if (!$p_product || !$p_product->is_in_stock()) $all_in_stock = false;
                                        $p_price = $p_product ? floatval($p_product->get_price()) : 0;
                                        $original_sum += $p_price;
                                        $combo_items[] = ['product_id' => $p_pid, 'price' => $p_price];
                                        $combo_event_labels[] = esc_html($pev->post_title) . ' – ' . esc_html($p_cat['name']);
                                    }

                                    if (!$partners_valid || count($combo_items) < 2 || !$all_in_stock) continue;

                                    $savings = $original_sum - $combo_price;
                                    $savings_pct = $original_sum > 0 ? round(($savings / $original_sum) * 100) : 0;
                                ?>
                                <div class="tix-ec-cat tix-ec-combo"
                                     data-combo-id="<?php echo $combo_id; ?>"
                                     data-combo-price="<?php echo $combo_price; ?>"
                                     data-combo-items='<?php echo esc_attr(json_encode($combo_items)); ?>'
                                     data-combo-label="<?php echo esc_attr($combo['label']); ?>"
                                     data-price="<?php echo $combo_price; ?>"
                                     data-max="20">
                                    <div class="tix-ec-cat-info">
                                        <span class="tix-ec-cat-name">🎫 <?php echo $combo_label; ?></span>
                                        <span class="tix-ec-cat-price">
                                            <?php if ($savings > 0): ?>
                                                <span class="tix-ec-price-sale"><?php echo number_format($combo_price, 2, ',', '.'); ?>&nbsp;€</span>
                                                <span class="tix-ec-price-old"><?php echo number_format($original_sum, 2, ',', '.'); ?>&nbsp;€</span>
                                            <?php else: ?>
                                                <?php echo number_format($combo_price, 2, ',', '.'); ?>&nbsp;€
                                            <?php endif; ?>
                                            <span class="tix-ec-vat"><?php echo esc_html($vat_text); ?></span>
                                        </span>
                                        <span class="tix-ec-offer-desc">
                                            <?php echo implode(' + ', $combo_event_labels); ?>
                                            <?php if ($savings_pct > 0): ?>
                                                <span class="tix-ec-offer-save"><?php echo $savings_pct; ?>% sparen</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="tix-ec-cat-qty">
                                        <button type="button" class="tix-ec-btn tix-ec-minus" aria-label="Weniger">−</button>
                                        <span class="tix-ec-qty-val" data-qty="0">0</span>
                                        <button type="button" class="tix-ec-btn tix-ec-plus" aria-label="Mehr">+</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; // $any_ec_combo_valid ?>
                        <?php endif; // $has_combos ?>

                        <?php // ── Mengenrabatt-Hinweis ── ?>
                        <?php if ($has_gd): ?>
                        <div class="tix-ec-offer-section tix-ec-gd"
                             data-tiers='<?php echo esc_attr(json_encode($gd_tiers)); ?>'
                             data-combine-bundle="<?php echo $gd_combine_bundle ? '1' : '0'; ?>"
                             data-combine-combo="<?php echo $gd_combine_combo ? '1' : '0'; ?>">
                            <div class="tix-ec-offer-heading">👥 Mengenrabatt</div>
                            <div class="tix-ec-gd-tiers">
                                <?php foreach ($gd_tiers as $tier): ?>
                                    <span class="tix-ec-gd-tier">Ab <?php echo $tier['min_qty']; ?> Tickets: <strong>−<?php echo $tier['percent']; ?>%</strong></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="tix-ec-gd-badge" style="display:none;"></div>
                        </div>
                        <?php endif; ?>

                    </div>
                    <?php endif; ?>

                    <div class="tix-ec-total">
                        <span class="tix-ec-total-label">Gesamt <span class="tix-ec-vat-note"><?php echo esc_html($vat_text); ?></span></span>
                        <span class="tix-ec-total-price">0,00 €</span>
                    </div>

                    <label class="tix-ec-terms">
                        <input type="checkbox" class="tix-ec-terms-check" value="1">
                        <span class="tix-ec-terms-custom"></span>
                        <span>Ich akzeptiere die <?php if ($terms_url): ?><a href="<?php echo esc_url($terms_url); ?>" target="_blank">Nutzungsbedingungen</a><?php else: ?><u>Nutzungsbedingungen</u><?php endif; ?>, <?php if ($privacy_url): ?><a href="<?php echo esc_url($privacy_url); ?>" target="_blank">Datenschutzhinweise</a><?php else: ?><u>Datenschutzhinweise</u><?php endif; ?> und <?php if ($revocation_url): ?><a href="<?php echo esc_url($revocation_url); ?>" target="_blank">Widerrufsbelehrung</a><?php else: ?><u>Widerrufsbelehrung</u><?php endif; ?>. Mit Klick auf &bdquo;Jetzt kaufen&ldquo; wird der Kauf direkt ausgelöst.</span>
                    </label>

                    <button type="button" class="tix-ec-buy" disabled>
                        <span class="tix-ec-buy-text">Jetzt kaufen</span>
                        <span class="tix-ec-buy-loading" style="display:none;">Wird verarbeitet…</span>
                    </button>

                    <span class="tix-ec-note">Express-Checkout überspringt den Warenkorb und schließt den Kauf direkt ab.</span>
                </div>

                <div class="tix-ec-message" style="display:none;"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Mehrere Produkte auf einmal in den Warenkorb
     */
    public static function ajax_add_to_cart() {

        check_ajax_referer('tix_add_to_cart', 'nonce');

        $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);

        if (empty($items)) {
            wp_send_json_error(['message' => 'Keine Tickets ausgewählt.']);
        }

        $added = 0;

        foreach ($items as $item) {
            // ── Kombi-Ticket ──
            if (!empty($item['combo'])) {
                $combo_id    = sanitize_text_field($item['combo_id'] ?? '');
                $combo_label = sanitize_text_field($item['combo_label'] ?? '');
                $combo_price = floatval($item['combo_price'] ?? 0);
                $combo_qty   = intval($item['quantity'] ?? 0);
                $products    = $item['products'] ?? [];

                if (empty($combo_id) || $combo_qty <= 0 || empty($products)) continue;

                $group_id = 'combo_' . time() . '_' . wp_generate_password(6, false, false);
                $item_count = count($products);

                foreach ($products as $p) {
                    $p_id = intval($p['product_id'] ?? 0);
                    if ($p_id <= 0) continue;

                    $cart_item_data = [
                        '_tix_combo' => [
                            'group_id'    => $group_id,
                            'combo_id'    => $combo_id,
                            'label'       => $combo_label,
                            'total_price' => $combo_price,
                            'item_count'  => $item_count,
                        ],
                    ];

                    $result = WC()->cart->add_to_cart($p_id, $combo_qty, 0, [], $cart_item_data);
                    if ($result) $added++;
                }
                continue;
            }

            // ── Einzel- / Bundle-Ticket ──
            $product_id = intval($item['product_id'] ?? 0);
            $quantity   = intval($item['quantity'] ?? 0);

            if ($product_id <= 0 || $quantity <= 0) continue;

            // Bundle-Meta am Cart-Item speichern
            $cart_item_data = [];
            if (!empty($item['bundle'])) {
                $cart_item_data['_tix_bundle'] = [
                    'buy'   => intval($item['bundle_buy'] ?? 0),
                    'pay'   => intval($item['bundle_pay'] ?? 0),
                    'label' => sanitize_text_field($item['bundle_label'] ?? ''),
                ];
            }

            // Sitzplatz-Meta am Cart-Item speichern
            if (!empty($item['seats']) && is_array($item['seats'])) {
                $cart_item_data['_tix_seats'] = array_map('sanitize_text_field', $item['seats']);
                $cart_item_data['_tix_event_id'] = intval($item['event_id'] ?? 0);
                $cart_item_data['_tix_seatmap_id'] = intval($item['seatmap_id'] ?? 0);
            }

            $result = WC()->cart->add_to_cart($product_id, $quantity, 0, [], $cart_item_data);
            if ($result) $added++;
        }

        if ($added > 0) {
            // Gutscheincode anwenden wenn mitgesendet
            $coupon_code = sanitize_text_field($_POST['coupon_code'] ?? '');
            if ($coupon_code && !WC()->cart->has_discount($coupon_code)) {
                WC()->cart->apply_coupon($coupon_code);
            }

            $checkout_url = wc_get_checkout_url();
            $cart_url     = wc_get_cart_url();

            // Embed-Modus: Cart-Transfer-Token erstellen (gegen Third-Party-Cookie-Blocking)
            if (!empty($_POST['is_embed'])) {
                $token_data = [
                    'items'  => $items,
                    'coupon' => $coupon_code,
                    'time'   => time(),
                ];
                $token = wp_generate_password(32, false);
                set_transient('tix_cart_transfer_' . $token, $token_data, 900); // 15 Min gültig

                $checkout_url = add_query_arg('tix_cart', $token, $checkout_url);
                $cart_url     = add_query_arg('tix_cart', $token, $cart_url);
            }

            wp_send_json_success([
                'message'      => $added . ' Ticket(s) zum Warenkorb hinzugefügt.',
                'cart_url'     => $cart_url,
                'checkout_url' => $checkout_url,
                'cart_count'   => WC()->cart->get_cart_contents_count(),
            ]);
        } else {
            wp_send_json_error(['message' => 'Tickets konnten nicht hinzugefügt werden.']);
        }
    }

    /**
     * AJAX: Gutscheincode validieren (ohne zum Warenkorb hinzuzufügen)
     */
    public static function ajax_validate_coupon() {
        check_ajax_referer('tix_add_to_cart', 'nonce');

        $code = sanitize_text_field($_POST['coupon_code'] ?? '');
        if (!$code) wp_send_json_error(['message' => 'Bitte Gutscheincode eingeben.']);

        $coupon = new WC_Coupon($code);
        if (!$coupon->get_id()) {
            wp_send_json_error(['message' => 'Ungültiger Gutscheincode.']);
        }

        // Validierung
        $discounts = new WC_Discounts(WC()->cart);
        $valid = $discounts->is_coupon_valid($coupon);
        if (is_wp_error($valid)) {
            wp_send_json_error(['message' => $valid->get_error_message()]);
        }

        // Rabatt-Typ + Betrag
        $type   = $coupon->get_discount_type();
        $amount = $coupon->get_amount();

        $info = '';
        if ($type === 'percent') {
            $info = $amount . '% Rabatt';
        } elseif ($type === 'fixed_product') {
            $info = number_format($amount, 2, ',', '.') . ' € Rabatt pro Ticket';
        } else {
            $info = number_format($amount, 2, ',', '.') . ' € Rabatt';
        }

        wp_send_json_success([
            'message'       => $info,
            'code'          => $code,
            'type'          => $type,
            'amount'        => floatval($amount),
            'product_ids'   => $coupon->get_product_ids(),
        ]);
    }

    /**
     * Shortcode: [tix_countdown]
     * Zeigt einen Countdown bis zum Event-Start
     */
    public static function render_countdown($atts) {
        $atts = shortcode_atts(['id' => 0, 'label' => ''], $atts);
        $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') return '';

        $date_start = get_post_meta($post_id, '_tix_date_start', true);
        $time_start = get_post_meta($post_id, '_tix_time_start', true);
        if (!$date_start) return '';

        $target = $date_start . 'T' . ($time_start ?: '00:00');
        $label  = $atts['label'] ? esc_html($atts['label']) : '';

        self::enqueue();

        return '<div class="tix-countdown" data-target="' . esc_attr($target) . '">'
            . ($label ? '<span class="tix-countdown-label">' . $label . '</span>' : '')
            . '<div class="tix-countdown-units">'
            . '<div class="tix-countdown-unit"><span class="tix-countdown-num tix-cd-days">--</span><span class="tix-countdown-lbl">Tage</span></div>'
            . '<div class="tix-countdown-sep">:</div>'
            . '<div class="tix-countdown-unit"><span class="tix-countdown-num tix-cd-hours">--</span><span class="tix-countdown-lbl">Std</span></div>'
            . '<div class="tix-countdown-sep">:</div>'
            . '<div class="tix-countdown-unit"><span class="tix-countdown-num tix-cd-mins">--</span><span class="tix-countdown-lbl">Min</span></div>'
            . '<div class="tix-countdown-sep">:</div>'
            . '<div class="tix-countdown-unit"><span class="tix-countdown-num tix-cd-secs">--</span><span class="tix-countdown-lbl">Sek</span></div>'
            . '</div></div>';
    }

    private static function enqueue() {
        // Nur einmal laden
        if (wp_script_is('tix-ticket-selector', 'enqueued')) return;

        wp_enqueue_script(
            'tix-ticket-selector',
            TIXOMAT_URL . 'assets/js/ticket-selector.js',
            [],
            TIXOMAT_VERSION,
            true
        );
        wp_localize_script('tix-ticket-selector', 'ehSel', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tix_add_to_cart'),
            'skipCart' => (bool) tix_get_settings('skip_cart'),
            'isEmbed'  => isset($_GET['tix_embed']),
        ]);

        wp_enqueue_style(
            'tix-ticket-selector',
            TIXOMAT_URL . 'assets/css/ticket-selector.css',
            [],
            TIXOMAT_VERSION
        );

        // ── Group Booking ──
        $group_enabled = tix_get_settings('group_booking');
        if ($group_enabled && !isset($_GET['tix_embed'])) {
            wp_enqueue_script(
                'tix-group-booking',
                TIXOMAT_URL . 'assets/js/group-booking.js',
                ['tix-ticket-selector'],
                TIXOMAT_VERSION,
                true
            );
            wp_localize_script('tix-group-booking', 'ehGroup', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('tix_group_booking'),
                'groupToken' => sanitize_text_field($_GET['tix_group'] ?? ''),
            ]);
        }
    }

    /**
     * Saalplan-Modus: Sektionen als Kategorien + Modal
     */
    private static function render_seatmap_mode($post_id, $seatmap_id, $seatmap_mode, $section_data) {
        self::enqueue();
        TIX_Seatmap::enqueue_picker_assets();

        $tix_s    = tix_get_settings();
        $vat_text = $tix_s['vat_text_selector'] ?? 'inkl. MwSt.';

        ob_start();
        ?>
        <div class="tix-sel tix-sel-seatmap" data-event-id="<?php echo $post_id; ?>"
             data-seatmap-id="<?php echo $seatmap_id; ?>"
             data-seatmap-mode="<?php echo esc_attr($seatmap_mode); ?>">

            <div class="tix-sel-categories">
                <?php foreach ($section_data as $sec):
                    if ($sec['price'] <= 0) continue;
                ?>
                <div class="tix-sel-cat tix-sel-seatmap-cat"
                     data-product-id="<?php echo $sec['product_id']; ?>"
                     data-price="<?php echo $sec['price']; ?>"
                     data-section-id="<?php echo esc_attr($sec['id']); ?>"
                     style="--section-color: <?php echo esc_attr($sec['color']); ?>">

                    <div class="tix-sel-cat-info">
                        <div class="tix-sel-cat-name">
                            <span class="tix-sel-section-dot" style="background:<?php echo esc_attr($sec['color']); ?>"></span>
                            <?php echo esc_html($sec['label']); ?>
                        </div>
                        <div class="tix-sel-cat-desc">
                            <?php echo $sec['available']; ?> von <?php echo $sec['total']; ?> Plätzen verfügbar
                        </div>
                    </div>

                    <div class="tix-sel-cat-price">
                        <?php echo number_format($sec['price'], 2, ',', '.'); ?>&nbsp;€
                        <span class="tix-sel-vat"><?php echo esc_html($vat_text); ?></span>
                    </div>

                    <div class="tix-sel-cat-qty">
                        <span class="tix-sel-qty-val tix-sel-seatmap-qty" data-qty="0" data-section="<?php echo esc_attr($sec['id']); ?>">0 Plätze</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Saalplan-Aktionen -->
            <div class="tix-sel-seatmap-actions">
                <button type="button" class="tix-sel-btn-seatmap"
                        data-event-id="<?php echo $post_id; ?>"
                        data-seatmap-id="<?php echo $seatmap_id; ?>"
                        data-mode="<?php echo esc_attr($seatmap_mode); ?>">
                    <span class="dashicons dashicons-layout"></span>
                    Platz wählen
                </button>
                <button type="button" class="tix-sel-btn-best-available"
                        data-event-id="<?php echo $post_id; ?>"
                        data-seatmap-id="<?php echo $seatmap_id; ?>">
                    <span class="dashicons dashicons-star-filled"></span>
                    Besten Platz finden
                </button>
            </div>

            <!-- Ausgewählte Plätze -->
            <div class="tix-sel-seatmap-selected" id="tix-seatmap-selected-<?php echo $post_id; ?>" style="display:none">
                <div class="tix-sel-seatmap-selected-header">Ausgewählte Plätze:</div>
                <div class="tix-sel-seatmap-selected-list"></div>
            </div>

            <!-- Gesamt -->
            <div class="tix-sel-total">
                <span class="tix-sel-total-price">0,00 €</span>
            </div>

            <!-- Kaufen Button -->
            <button type="button" class="tix-sel-buy" disabled>
                <span class="tix-sel-buy-text">In den Warenkorb</span>
                <span class="tix-sel-buy-loading" style="display:none">Wird hinzugefügt…</span>
            </button>

            <div class="tix-sel-message" style="display:none"></div>

            <!-- Hidden Seat Data Container -->
            <div id="tix-seatmap-selection" data-event-id="<?php echo $post_id; ?>"
                 data-seatmap-id="<?php echo $seatmap_id; ?>"></div>

            <!-- Saalplan-Modal -->
            <div id="tix-seatmap-modal" class="tix-sp-modal-overlay" style="display:none">
                <div class="tix-sp-modal">
                    <div class="tix-sp-modal-header">
                        <h3><span class="dashicons dashicons-layout"></span> Sitzplatz wählen</h3>
                        <button type="button" class="tix-sp-modal-close">&times;</button>
                    </div>
                    <div class="tix-sp-modal-body">
                        <div class="tix-sp-modal-picker"
                             data-tix-seatmap-picker
                             data-event-id="<?php echo $post_id; ?>"
                             data-seatmap-id="<?php echo $seatmap_id; ?>"
                             data-mode="modal"
                             data-sections='<?php echo esc_attr(wp_json_encode($section_data)); ?>'
                             data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                        </div>
                    </div>
                    <div class="tix-sp-modal-footer">
                        <div class="tix-sp-modal-summary">
                            <span class="tix-sp-modal-count">0 Plätze gewählt</span>
                            <span class="tix-sp-modal-total">0,00 €</span>
                        </div>
                        <button type="button" class="tix-sp-modal-confirm" disabled>
                            Auswahl übernehmen
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Seatmap-Picker Assets laden (nur einmal)
     */
    private static function enqueue_seatmap() {
        if (wp_script_is('tix-seatmap-picker', 'enqueued')) return;

        wp_enqueue_script(
            'tix-seatmap-picker',
            TIXOMAT_URL . 'assets/js/seatmap-picker.js',
            [],
            TIXOMAT_VERSION,
            true
        );
        wp_enqueue_style(
            'tix-seatmap-picker',
            TIXOMAT_URL . 'assets/css/seatmap-picker.css',
            [],
            TIXOMAT_VERSION
        );
    }

}
