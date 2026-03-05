<?php
if (!defined('ABSPATH')) exit;

class TIX_Sync {

    /**
     * Preis formatieren mit stylbarem MwSt-Hinweis.
     * Gibt z.B. '25,00 € <span class="tix-vat">(inkl. MwSt.)</span>' zurück.
     */
    private static function fmt_price($amount) {
        return number_format(floatval($amount), 2, ',', '.') . ' € <span class="tix-vat">inkl. MwSt.</span>';
    }

    public static function sync($post_id, $post) {

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if ($post->post_status === 'auto-draft') return;
        if ($post->post_status === 'trash') return;

        // Serien-Master nicht syncen (ist nur Vorlage)
        if (get_post_meta($post_id, '_tix_series_enabled', true) === '1') {
            self::save_breakdance_meta($post_id, []);
            return;
        }

        remove_action('save_post_event', [__CLASS__, 'sync'], 20);

        $enabled = get_post_meta($post_id, '_tix_tickets_enabled', true);

        // Nicht aktiviert → nichts syncen, aber Breakdance-Meta trotzdem speichern
        if ($enabled !== '1') {
            // Breakdance-Meta für Datum/Ort trotzdem updaten
            self::save_breakdance_meta($post_id, []);
            add_action('save_post_event', [__CLASS__, 'sync'], 20, 2);
            return;
        }

        $event_title    = get_the_title($post_id);
        $date_start     = get_post_meta($post_id, '_tix_date_start', true);
        $date_end       = get_post_meta($post_id, '_tix_date_end', true);
        $time_start     = get_post_meta($post_id, '_tix_time_start', true);
        $time_end       = get_post_meta($post_id, '_tix_time_end', true);
        $location       = get_post_meta($post_id, '_tix_location', true);
        $categories     = get_post_meta($post_id, '_tix_ticket_categories', true);
        $presale        = get_post_meta($post_id, '_tix_presale_active', true);
        $time_doors     = get_post_meta($post_id, '_tix_time_doors', true);
        $event_thumb_id = get_post_thumbnail_id($post_id);

        if (!is_array($categories) || empty($date_start)) {
            add_action('save_post_event', [__CLASS__, 'sync'], 20, 2);
            return;
        }

        $start_datetime = "{$date_start} {$time_start}";
        $end_datetime   = "{$date_end} {$time_end}";
        $wc_cat_ids     = self::sync_taxonomy($post_id);
        $log            = [];

        foreach ($categories as $i => &$cat) {

            $full_name   = "{$event_title} – {$cat['name']}";
            $tc_event_id = !empty($cat['tc_event_id']) ? intval($cat['tc_event_id']) : 0;
            $product_id  = !empty($cat['product_id'])  ? intval($cat['product_id'])  : 0;
            $cat_image   = !empty($cat['image_id'])    ? intval($cat['image_id'])    : 0;
            $image_id    = $cat_image ?: $event_thumb_id;
            $is_online   = ($cat['online'] ?? '1') === '1';

            $sale_price = $cat['sale_price'];
            $has_sale   = ($sale_price !== '' && $sale_price !== null && $sale_price !== false);

            // ── Offline-Ticket (Abendkasse): NUR Anzeige, kein WC/TC ──
            $is_offline_ticket = !empty($cat['offline_ticket']);

            if ($is_offline_ticket) {
                // Falls vorher online → deaktivieren (nicht löschen)
                if (tix_use_tickera() && $tc_event_id && get_post_status($tc_event_id) !== false) {
                    wp_update_post(['ID' => $tc_event_id, 'post_status' => 'draft']);
                    $log[] = "⏸️ TC #{$tc_event_id} deaktiviert (Offline-Ticket)";
                }
                if ($product_id && get_post_status($product_id) !== false) {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $product->set_status('draft');
                        $product->set_stock_status('outofstock');
                        $product->save();
                    }
                    $log[] = "⏸️ WC #{$product_id} deaktiviert (Offline-Ticket)";
                }
                continue;
            }

            // ── Online AUS (nicht Offline-Ticket) → deaktivieren ──
            if (!$is_online) {
                if (tix_use_tickera() && $tc_event_id && get_post_status($tc_event_id) !== false) {
                    wp_update_post(['ID' => $tc_event_id, 'post_status' => 'draft']);
                    $log[] = "⏸️ TC #{$tc_event_id} deaktiviert";
                }
                if ($product_id && get_post_status($product_id) !== false) {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $product->set_status('draft');
                        $product->set_stock_status('outofstock');
                        $product->save();
                    }
                    $log[] = "⏸️ WC #{$product_id} deaktiviert";
                }
                continue;
            }

            // ── SKU generieren (einmalig) ──
            if (empty($cat['sku'])) {
                $cat['sku'] = self::generate_sku($post_id, $cat['name'], $i);
            }

            // ━━ TICKERA EVENT ━━━━━━━━━━━━━━━━━━━━━━
            if (tix_use_tickera()) {
                if ($tc_event_id && get_post_status($tc_event_id) !== false) {
                    wp_update_post(['ID' => $tc_event_id, 'post_title' => $full_name, 'post_status' => 'publish']);
                    $log[] = "✏️ TC #{$tc_event_id}";
                } else {
                    $tc_event_id = wp_insert_post([
                        'post_title'  => $full_name,
                        'post_type'   => 'tc_events',
                        'post_status' => 'publish',
                    ]);
                    $log[] = "✅ TC #{$tc_event_id}";
                }

                if ($tc_event_id && !is_wp_error($tc_event_id)) {
                    update_post_meta($tc_event_id, 'event_date_time',             $start_datetime);
                    update_post_meta($tc_event_id, 'event_end_date_time',         $end_datetime);
                    update_post_meta($tc_event_id, 'event_location',              $location);
                    update_post_meta($tc_event_id, 'event_presentation_page',     $tc_event_id);
                    update_post_meta($tc_event_id, 'show_tickets_automatically',  '0');
                    update_post_meta($tc_event_id, 'event_terms',                 '');
                    update_post_meta($tc_event_id, 'event_logo_file_url',         '');
                    update_post_meta($tc_event_id, 'sponsors_logo_file_url',      '');
                    update_post_meta($tc_event_id, 'limit_level',                 '0');
                    update_post_meta($tc_event_id, 'hide_event_after_expiration', '0');
                    update_post_meta($tc_event_id, '_tix_parent_event_id',         $post_id);
                    if ($time_doors) {
                        update_post_meta($tc_event_id, 'event_doors_time', "{$date_start} {$time_doors}");
                    }

                    if ($image_id) {
                        set_post_thumbnail($tc_event_id, $image_id);
                        $img_url = wp_get_attachment_url($image_id);
                        if ($img_url) update_post_meta($tc_event_id, 'event_logo_file_url', $img_url);
                    } else {
                        delete_post_thumbnail($tc_event_id);
                        update_post_meta($tc_event_id, 'event_logo_file_url', '');
                    }
                }
            } // end tix_use_tickera()

            // ━━ WOOCOMMERCE PRODUKT ━━━━━━━━━━━━━━━━
            $product = null;
            if ($product_id && get_post_status($product_id) !== false) {
                $product = wc_get_product($product_id);
            }

            // Bestimme Stock-Status basierend auf Presale + Online
            $should_be_purchasable = $is_online && ($presale === '1');

            if ($product) {
                $product->set_name($full_name);
                $product->set_status('publish');
                $product->set_short_description($cat['desc'] ?? '');
                $product->set_category_ids($wc_cat_ids);
                try { $product->set_sku($cat['sku']); } catch (\WC_Data_Exception $e) { /* SKU unverändert lassen */ }

                // ── Phase-aware Pricing ──
                $active_phase = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
                if ($active_phase) {
                    $phase_price = floatval($active_phase['price']);
                    if ($phase_price < $cat['price']) {
                        // Phase günstiger → als Sale anzeigen
                        $product->set_regular_price($cat['price']);
                        $product->set_sale_price($phase_price);
                    } else {
                        // Phase gleich/teurer → einfach als Preis setzen
                        $product->set_regular_price($phase_price);
                        $product->set_sale_price('');
                    }
                    $log[] = "⏱ Phase aktiv: {$active_phase['name']} ({$phase_price}€)";
                } else {
                    // Keine Phase → normaler Preis + manueller Sale
                    $product->set_regular_price($cat['price']);
                    if ($has_sale) { $product->set_sale_price($sale_price); }
                    else { $product->set_sale_price(''); }
                }

                if ($image_id) { $product->set_image_id($image_id); }
                else { $product->set_image_id(0); }

                // Presale beendet oder Nur Abendkasse → outofstock
                if (!$should_be_purchasable) {
                    $product->set_stock_status('outofstock');
                } else {
                    // Nur auf instock setzen wenn nicht manuell auf outofstock
                    $current = $product->get_stock_quantity();
                    if ($current === null || $current > 0) {
                        $product->set_stock_status('instock');
                    }
                }

                // Stock Management: nur wenn Kapazität > 0 eingetragen
                $qty = intval($cat['qty'] ?? 0);
                if ($qty > 0) {
                    if (!$product->get_manage_stock()) {
                        $product->set_manage_stock(true);
                        $product->set_stock_quantity($qty);
                    }
                } else {
                    // Keine Kapazität = unbegrenzt (kein Stock Management)
                    $product->set_manage_stock(false);
                    $product->set_stock_status($should_be_purchasable ? 'instock' : 'outofstock');
                }

                $product->save();
                if (tix_use_tickera() && $tc_event_id) {
                    update_post_meta($product_id, '_event_name', $tc_event_id);
                }
                $log[] = "✏️ WC #{$product_id}";

            } else {
                // ── Prüfe ob ein verwaistes Produkt mit dieser SKU existiert ──
                $existing_by_sku = wc_get_product_id_by_sku($cat['sku']);
                if ($existing_by_sku) {
                    $product    = wc_get_product($existing_by_sku);
                    $product_id = $existing_by_sku;
                    $log[]      = "♻️ WC #{$product_id} wiederverwendet (SKU: {$cat['sku']})";
                }

                if (!$product) {
                    $product = new WC_Product_Simple();
                }

                $product->set_name($full_name);
                $product->set_status('publish');
                $product->set_catalog_visibility('hidden');
                $product->set_short_description($cat['desc'] ?? '');
                $product->set_virtual(false);
                try { $product->set_sku($cat['sku']); } catch (\WC_Data_Exception $e) { /* SKU bereits vergeben, ok */ }
                $product->set_category_ids($wc_cat_ids);

                // ── Phase-aware Pricing ──
                $active_phase = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
                if ($active_phase) {
                    $phase_price = floatval($active_phase['price']);
                    if ($phase_price < $cat['price']) {
                        $product->set_regular_price($cat['price']);
                        $product->set_sale_price($phase_price);
                    } else {
                        $product->set_regular_price($phase_price);
                    }
                } else {
                    $product->set_regular_price($cat['price']);
                    if ($has_sale) $product->set_sale_price($sale_price);
                }

                // Stock Management: nur wenn Kapazität > 0
                $qty = intval($cat['qty'] ?? 0);
                if ($qty > 0) {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($qty);
                } else {
                    $product->set_manage_stock(false);
                }
                $product->set_stock_status($should_be_purchasable ? 'instock' : 'outofstock');

                if ($image_id) $product->set_image_id($image_id);

                $product_id = $product->save();
                if (!in_array("♻️ WC #{$product_id} wiederverwendet (SKU: {$cat['sku']})", $log)) {
                    $log[] = "✅ WC #{$product_id} (SKU: {$cat['sku']})";
                }
            }

            // Bridge-Meta: Tickera
            if ($product_id && $tc_event_id && tix_use_tickera()) {
                update_post_meta($product_id, '_tc_is_ticket',                  'yes');
                update_post_meta($product_id, '_event_name',                    $tc_event_id);
                $tpl = defined('TIX_TICKET_TEMPLATE_ID') ? TIX_TICKET_TEMPLATE_ID : '25';
                $tpl = apply_filters('tix_ticket_template_id', $tpl, $cat, $post_id);
                update_post_meta($product_id, '_ticket_template',               $tpl);
                update_post_meta($product_id, '_available_checkins_per_ticket',  '');
                update_post_meta($product_id, '_checkins_time_basis',            'no');
                update_post_meta($product_id, '_ticket_checkin_availability',    'open_ended');
                update_post_meta($product_id, '_allow_ticket_checkout',          'no');
                update_post_meta($product_id, '_ticket_availability',            'open_ended');
                update_post_meta($product_id, '_tix_parent_event_id',             $post_id);
            }

            // Bridge-Meta: Tixomat eigenes System
            if ($product_id) {
                if (tix_use_own_tickets()) {
                    update_post_meta($product_id, '_tix_is_ticket',      'yes');
                }
                // _tix_source_event immer setzen (nützlich für Upsell + Lookup)
                update_post_meta($product_id, '_tix_source_event', $post_id);
            }

            if (tix_use_tickera()) {
                $cat['tc_event_id'] = $tc_event_id;
            }
            $cat['product_id']  = $product_id;
        }
        unset($cat);

        update_post_meta($post_id, '_tix_ticket_categories', $categories);
        self::save_breakdance_meta($post_id, $categories);

        update_post_meta($post_id, '_tix_last_sync_log', $log);
        update_post_meta($post_id, '_tix_last_sync_time', current_time('mysql'));
        set_transient('tix_sync_log_' . $post_id, $log, 30);

        // Dynamischen Preis-Cache leeren (Phase-Preise könnten sich geändert haben)
        if (class_exists('TIX_Dynamic_Pricing')) {
            TIX_Dynamic_Pricing::clear_cache();
        }

        // ━━ TICKERA API-KEY BÜNDELN ━━━━━━━━━━━━━━━━
        if (tix_use_tickera()) {
            self::sync_api_key($post_id, $event_title, $categories);
        }

        add_action('save_post_event', [__CLASS__, 'sync'], 20, 2);
    }

    /**
     * SKU generieren: TIX-{EventID}-{slug}
     */
    private static function generate_sku($post_id, $cat_name, $index) {
        $slug = sanitize_title($cat_name);
        if (!$slug) $slug = 'cat' . ($index + 1);
        $sku = 'TIX-' . $post_id . '-' . $slug;

        // Prüfe Einzigartigkeit
        $existing = wc_get_product_id_by_sku($sku);
        if ($existing) {
            $sku .= '-' . ($index + 1);
        }
        return $sku;
    }

    /**
     * event_category → product_cat Sync
     */
    private static function sync_taxonomy($post_id) {
        $event_terms = wp_get_post_terms($post_id, 'event_category', ['fields' => 'all']);
        if (is_wp_error($event_terms) || empty($event_terms)) return [];

        $wc_cat_ids = [];
        foreach ($event_terms as $term) {
            $wc_term = get_term_by('slug', $term->slug, 'product_cat');
            if ($wc_term) {
                $wc_cat_ids[] = $wc_term->term_id;
            } else {
                $parent_wc_id = 0;
                if ($term->parent) {
                    $parent_event_term = get_term($term->parent, 'event_category');
                    if ($parent_event_term) {
                        $parent_wc_term = get_term_by('slug', $parent_event_term->slug, 'product_cat');
                        if ($parent_wc_term) $parent_wc_id = $parent_wc_term->term_id;
                    }
                }
                $new_term = wp_insert_term($term->name, 'product_cat', [
                    'slug' => $term->slug, 'description' => $term->description, 'parent' => $parent_wc_id,
                ]);
                if (!is_wp_error($new_term)) $wc_cat_ids[] = $new_term['term_id'];
            }
        }
        return $wc_cat_ids;
    }

    /**
     * Flache Meta-Felder für Breakdance
     */
    private static function save_breakdance_meta($post_id, $categories) {

        $date_start = get_post_meta($post_id, '_tix_date_start', true);
        $date_end   = get_post_meta($post_id, '_tix_date_end', true);
        $time_start = get_post_meta($post_id, '_tix_time_start', true);
        $time_end   = get_post_meta($post_id, '_tix_time_end', true);
        $time_doors = get_post_meta($post_id, '_tix_time_doors', true);

        update_post_meta($post_id, '_tix_date_start_formatted', $date_start ? date_i18n('d.m.Y', strtotime($date_start)) : '');
        update_post_meta($post_id, '_tix_date_end_formatted', $date_end ? date_i18n('d.m.Y', strtotime($date_end)) : '');
        update_post_meta($post_id, '_tix_time_start_formatted', $time_start ? date('H:i', strtotime($time_start)) : '');
        update_post_meta($post_id, '_tix_time_end_formatted', $time_end ? date('H:i', strtotime($time_end)) : '');

        // Einlass
        update_post_meta($post_id, '_tix_doors_formatted', $time_doors ? date('H:i', strtotime($time_doors)) : '');
        if ($time_doors) {
            update_post_meta($post_id, '_tix_doors_display', 'Einlass: ' . date('H:i', strtotime($time_doors)) . ' Uhr');
        } else {
            update_post_meta($post_id, '_tix_doors_display', '');
        }

        // Datum-Display
        if ($date_start) {
            $ds = date_i18n('d.m.Y', strtotime($date_start));
            $de = $date_end ? date_i18n('d.m.Y', strtotime($date_end)) : $ds;
            $ts = $time_start ? date('H:i', strtotime($time_start)) : '';
            $te = $time_end ? date('H:i', strtotime($time_end)) : '';
            $same_day = (!$date_end || $date_start === $date_end);

            if ($same_day) {
                if ($ts && $te) {
                    $display = "{$ds}, {$ts} – {$te} Uhr";
                } elseif ($ts) {
                    $display = "{$ds}, {$ts} Uhr";
                } else {
                    $display = $ds;
                }
            } else {
                if ($ts && $te) {
                    $display = "{$ds}, {$ts} – {$de}, {$te} Uhr";
                } elseif ($ts) {
                    $display = "{$ds}, {$ts} Uhr – {$de}";
                } else {
                    $display = "{$ds} – {$de}";
                }
            }
            update_post_meta($post_id, '_tix_date_display', $display);

            // Mehrzeiliges Datum
            if ($same_day) {
                if ($ts && $te) {
                    update_post_meta($post_id, '_tix_date_multiline', "{$ds}<br>{$ts} – {$te} Uhr");
                } elseif ($ts) {
                    update_post_meta($post_id, '_tix_date_multiline', "{$ds}<br>{$ts} Uhr");
                } else {
                    update_post_meta($post_id, '_tix_date_multiline', $ds);
                }
            } else {
                $line1 = $ts ? "{$ds}, {$ts} Uhr" : $ds;
                $line2 = $te ? "{$de}, {$te} Uhr" : $de;
                update_post_meta($post_id, '_tix_date_multiline', "{$line1}<br>{$line2}");
            }

            // Nur-Datum (ohne Uhrzeit) z.B. "15.03.2026" oder "15.03. – 17.03.2026"
            if ($date_end && $date_end !== $date_start) {
                update_post_meta($post_id, '_tix_date_only', "{$ds} – {$de}");
            } else {
                update_post_meta($post_id, '_tix_date_only', $ds);
            }

            // Nur-Uhrzeit z.B. "19:00 Uhr" oder "19:00 – 23:00 Uhr"
            if ($time_start) {
                if ($time_end) {
                    update_post_meta($post_id, '_tix_time_only', "{$ts} – {$te} Uhr");
                } else {
                    update_post_meta($post_id, '_tix_time_only', "{$ts} Uhr");
                }
            } else {
                update_post_meta($post_id, '_tix_time_only', '');
            }
        }

        // Veranstalter
        $organizer = get_post_meta($post_id, '_tix_organizer', true);
        update_post_meta($post_id, '_tix_organizer_display', $organizer ?: '');

        // Adresse + kombinierte Anzeige
        $location = get_post_meta($post_id, '_tix_location', true);
        $address  = get_post_meta($post_id, '_tix_address', true);
        $loc_full = $location;
        if ($address) $loc_full .= ', ' . $address;
        update_post_meta($post_id, '_tix_location_full', $loc_full);
        update_post_meta($post_id, '_tix_address_display', $address ?: '');

        // ── Info-Sektionen (Breakdance-Meta) ──
        if (class_exists('TIX_Metabox')) {
            $sections = TIX_Metabox::info_sections();
            foreach ($sections as $key => $def) {
                $content = get_post_meta($post_id, "_tix_info_{$key}", true);
                $label   = get_post_meta($post_id, "_tix_info_{$key}_label", true) ?: $def['label'];

                // Flache Felder für Breakdance (wpautop nur auf alte Daten ohne <p>)
                if ($def['type'] === 'textarea' && $content && strpos($content, '<p>') === false) {
                    update_post_meta($post_id, "_tix_{$key}", wpautop($content));
                } else {
                    update_post_meta($post_id, "_tix_{$key}", $content);
                }
                update_post_meta($post_id, "_tix_{$key}_label", $label);
            }

            // Altersbegrenzung speziell
            $age = get_post_meta($post_id, '_tix_info_age_limit', true);
            if ($age !== '' && $age !== null) {
                update_post_meta($post_id, '_tix_age_limit_display', intval($age) . '+');
            } else {
                update_post_meta($post_id, '_tix_age_limit_display', '');
            }
        }

        // Cleanup alte nummerierte Felder
        for ($n = 1; $n <= 20; $n++) {
            foreach (['name','price','price_formatted','sale_price','sale_price_formatted',
                       'qty','desc','product_id','tc_event_id','add_to_cart_url','image_url'] as $f) {
                delete_post_meta($post_id, "_tix_ticket_{$n}_{$f}");
            }
            foreach (['name','price','price_formatted','sale_price','sale_price_formatted','desc'] as $f) {
                delete_post_meta($post_id, "_tix_offline_{$n}_{$f}");
            }
        }

        // ── Online-Kategorien: Breakdance-Meta & Preisberechnung ──
        $online_cats  = array_filter($categories, fn($c) => ($c['online'] ?? '1') === '1' && empty($c['offline_ticket']));
        $product_ids  = [];
        $prices       = [];

        $n = 0;
        foreach ($online_cats as $cat) {
            $n++;
            $price    = floatval($cat['price']);
            $sale     = $cat['sale_price'];
            $has_sale = ($sale !== '' && $sale !== null && $sale !== false);

            // Phase-aware: aktive Phase bestimmt den effektiven Preis
            update_post_meta($post_id, "_tix_ticket_{$n}_name", $cat['name']);
            $active_phase = null;
            if (class_exists('TIX_Metabox')) {
                $active_phase = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
            }

            if ($active_phase) {
                $phase_price = floatval($active_phase['price']);
                if ($phase_price < $price) {
                    // Phase günstiger → Sale-Darstellung
                    $effective = $phase_price;
                    update_post_meta($post_id, "_tix_ticket_{$n}_price", $price);
                    update_post_meta($post_id, "_tix_ticket_{$n}_price_formatted", self::fmt_price($price));
                    update_post_meta($post_id, "_tix_ticket_{$n}_sale_price", $phase_price);
                    update_post_meta($post_id, "_tix_ticket_{$n}_sale_price_formatted", self::fmt_price($phase_price));
                } else {
                    // Phase gleich/teurer
                    $effective = $phase_price;
                    update_post_meta($post_id, "_tix_ticket_{$n}_price", $phase_price);
                    update_post_meta($post_id, "_tix_ticket_{$n}_price_formatted", self::fmt_price($phase_price));
                    delete_post_meta($post_id, "_tix_ticket_{$n}_sale_price");
                    delete_post_meta($post_id, "_tix_ticket_{$n}_sale_price_formatted");
                }
                update_post_meta($post_id, "_tix_ticket_{$n}_phase_name", $active_phase['name']);
                update_post_meta($post_id, "_tix_ticket_{$n}_phase_until", $active_phase['until']);
            } else {
                $effective = $has_sale ? floatval($sale) : $price;
                update_post_meta($post_id, "_tix_ticket_{$n}_price", $price);
                update_post_meta($post_id, "_tix_ticket_{$n}_price_formatted", self::fmt_price($price));

                if ($has_sale) {
                    update_post_meta($post_id, "_tix_ticket_{$n}_sale_price", floatval($sale));
                    update_post_meta($post_id, "_tix_ticket_{$n}_sale_price_formatted", self::fmt_price($sale));
                }
                delete_post_meta($post_id, "_tix_ticket_{$n}_phase_name");
                delete_post_meta($post_id, "_tix_ticket_{$n}_phase_until");
            }

            update_post_meta($post_id, "_tix_ticket_{$n}_qty", $cat['qty']);
            update_post_meta($post_id, "_tix_ticket_{$n}_desc", $cat['desc']);
            update_post_meta($post_id, "_tix_ticket_{$n}_product_id", $cat['product_id']);
            update_post_meta($post_id, "_tix_ticket_{$n}_tc_event_id", $cat['tc_event_id']);

            $img_id = intval($cat['image_id'] ?? 0) ?: get_post_thumbnail_id($post_id);
            if ($img_id) {
                $img_url = wp_get_attachment_image_url($img_id, 'large');
                update_post_meta($post_id, "_tix_ticket_{$n}_image_url", $img_url ?: '');
            }

            if (!empty($cat['product_id'])) {
                $url = wc_get_cart_url() . '?add-to-cart=' . intval($cat['product_id']);
                update_post_meta($post_id, "_tix_ticket_{$n}_add_to_cart_url", $url);
                $product_ids[] = $cat['product_id'];
            }
            $prices[] = $effective;
        }

        update_post_meta($post_id, '_tix_ticket_count', $n);
        update_post_meta($post_id, '_tix_product_ids', implode(',', $product_ids));
        update_post_meta($post_id, '_tix_tickets_enabled', get_post_meta($post_id, '_tix_tickets_enabled', true));

        // ── Offline-Tickets: Breakdance-Meta (Anzeige, kein Warenkorb) ──
        $offline_cats = array_filter($categories, fn($c) => !empty($c['offline_ticket']));
        $m = 0;
        $offline_prices = [];
        foreach ($offline_cats as $cat) {
            $m++;
            $price    = floatval($cat['price']);
            $sale     = $cat['sale_price'] ?? '';
            $has_sale = ($sale !== '' && $sale !== null && $sale !== false);
            $effective = $has_sale ? floatval($sale) : $price;

            update_post_meta($post_id, "_tix_offline_{$m}_name", $cat['name']);
            update_post_meta($post_id, "_tix_offline_{$m}_price", $price);
            update_post_meta($post_id, "_tix_offline_{$m}_price_formatted", self::fmt_price($price));
            update_post_meta($post_id, "_tix_offline_{$m}_desc", $cat['desc'] ?? '');

            if ($has_sale) {
                update_post_meta($post_id, "_tix_offline_{$m}_sale_price", floatval($sale));
                update_post_meta($post_id, "_tix_offline_{$m}_sale_price_formatted", self::fmt_price($sale));
            }

            $offline_prices[] = $effective;
        }
        update_post_meta($post_id, '_tix_offline_count', $m);

        // ── Preisrange: Online + Offline kombiniert ──
        $all_prices = array_merge($prices, $offline_prices);
        if (!empty($all_prices)) {
            $min = min($all_prices);
            $max = max($all_prices);
            update_post_meta($post_id, '_tix_price_min', $min);
            update_post_meta($post_id, '_tix_price_max', $max);
            update_post_meta($post_id, '_tix_price_min_formatted', self::fmt_price($min));
            update_post_meta($post_id, '_tix_price_max_formatted', self::fmt_price($max));

            if ($min === $max && $min > 0) {
                update_post_meta($post_id, '_tix_price_range', self::fmt_price($min));
                update_post_meta($post_id, '_tix_price_range_full', self::fmt_price($min));
            } elseif ($min > 0) {
                update_post_meta($post_id, '_tix_price_range', 'ab ' . self::fmt_price($min));
                update_post_meta($post_id, '_tix_price_range_full',
                    number_format($min, 2, ',', '.') . ' € – ' . self::fmt_price($max));
            }
        } else {
            update_post_meta($post_id, '_tix_price_min', 0);
            update_post_meta($post_id, '_tix_price_max', 0);
            update_post_meta($post_id, '_tix_price_min_formatted', '');
            update_post_meta($post_id, '_tix_price_max_formatted', '');
            update_post_meta($post_id, '_tix_price_range', '');
            update_post_meta($post_id, '_tix_price_range_full', '');
        }

        // ── FAQ: flache Meta-Felder ──
        $faqs = get_post_meta($post_id, '_tix_faq', true);
        if (!is_array($faqs)) $faqs = [];

        // Alte FAQ-Meta aufräumen
        $old_faq_count = (int) get_post_meta($post_id, '_tix_faq_count', true);
        for ($i = 1; $i <= max($old_faq_count, 50); $i++) {
            delete_post_meta($post_id, "_tix_faq_{$i}_question");
            delete_post_meta($post_id, "_tix_faq_{$i}_answer");
            delete_post_meta($post_id, "_tix_faq_{$i}_answer_html");
        }

        // Neue FAQ-Meta schreiben
        $faq_n = 0;
        foreach ($faqs as $faq) {
            $q = $faq['q'] ?? '';
            $a = $faq['a'] ?? '';
            if (empty($q) && empty($a)) continue;
            $faq_n++;
            update_post_meta($post_id, "_tix_faq_{$faq_n}_question", $q);
            update_post_meta($post_id, "_tix_faq_{$faq_n}_answer", $a);
            update_post_meta($post_id, "_tix_faq_{$faq_n}_answer_html", wpautop($a));
        }
        update_post_meta($post_id, '_tix_faq_count', $faq_n);

        // ── Kapazität & Verkaufs-Meta ──
        $total_sold = 0;
        $total_cap  = 0;
        $has_unlimited = false;
        $all_sold_out = true;

        $online_cats = array_filter($categories, fn($c) =>
            ($c['online'] ?? '1') === '1' && empty($c['offline_ticket'])
        );

        foreach ($online_cats as $cat) {
            $pid = intval($cat['product_id'] ?? 0);
            $cap = intval($cat['qty'] ?? 0);

            if ($cap > 0) {
                $total_cap += $cap;
            } else {
                $has_unlimited = true;
            }

            if (!$pid) { $all_sold_out = false; continue; }
            $product = wc_get_product($pid);
            if (!$product) { $all_sold_out = false; continue; }

            $stk = $product->get_stock_quantity();
            if ($stk !== null && $product->get_manage_stock()) {
                $sold = max(0, $cap - $stk);
                $total_sold += $sold;
                if ($stk > 0) $all_sold_out = false;
            } else {
                // Kein Stock Management = unbegrenzt → nie ausverkauft
                $all_sold_out = false;
            }
        }

        // Wenn keine Online-Kategorien, nicht "sold out"
        if (empty($online_cats)) $all_sold_out = false;

        $sold_pct = $total_cap > 0 ? round(($total_sold / $total_cap) * 100) : 0;

        update_post_meta($post_id, '_tix_sold_total', $total_sold);
        update_post_meta($post_id, '_tix_capacity_total', $has_unlimited ? '' : $total_cap);
        update_post_meta($post_id, '_tix_sold_percent', $has_unlimited ? '' : $sold_pct);
        if ($has_unlimited) {
            update_post_meta($post_id, '_tix_sold_display', $total_sold > 0 ? "{$total_sold} verkauft" : '');
        } else {
            update_post_meta($post_id, '_tix_sold_display', "{$total_sold}/{$total_cap} ({$sold_pct}%)");
        }

        // ── Event-Status (Auto oder Manuell) ──
        $manual_status = get_post_meta($post_id, '_tix_event_status', true) ?: '';
        $status_labels = [
            'available'   => 'Verfügbar',
            'few_tickets' => 'Wenige Tickets',
            'sold_out'    => 'Ausverkauft',
            'cancelled'   => 'Abgesagt',
            'postponed'   => 'Verschoben',
            'past'        => 'Vergangen',
        ];

        if ($manual_status !== '') {
            // Manueller Override
            $resolved_status = $manual_status;
        } else {
            // Auto-Detect
            $presale_active = get_post_meta($post_id, '_tix_presale_active', true);
            if ($presale_active !== '1' && !empty($online_cats)) {
                $resolved_status = 'sold_out';
            } elseif ($all_sold_out) {
                $resolved_status = 'sold_out';
            } elseif ($sold_pct >= 90 && $total_cap > 0) {
                $resolved_status = 'few_tickets';
            } else {
                $resolved_status = 'available';
            }
        }

        update_post_meta($post_id, '_tix_status', $resolved_status);
        update_post_meta($post_id, '_tix_status_label', $status_labels[$resolved_status] ?? '');

        // ── Berechnetes Presale-Ende ──
        $presale_end_mode = get_post_meta($post_id, '_tix_presale_end_mode', true) ?: 'manual';
        $computed_end = '';
        if ($presale_end_mode === 'fixed') {
            $computed_end = get_post_meta($post_id, '_tix_presale_end', true);
        } elseif ($presale_end_mode === 'before_event') {
            $ds = get_post_meta($post_id, '_tix_date_start', true);
            $ts = get_post_meta($post_id, '_tix_time_start', true);
            if ($ds && $ts) {
                $event_start = strtotime("{$ds} {$ts}");
                $offset_h = intval(get_post_meta($post_id, '_tix_presale_end_offset', true));
                $end_ts = $event_start - ($offset_h * 3600);
                $computed_end = date('Y-m-d\TH:i', $end_ts);
            }
        }
        update_post_meta($post_id, '_tix_presale_end_computed', $computed_end);

        // ── Countdown Target (ISO) ──
        if ($date_start) {
            $ts = $time_start ?: '00:00';
            update_post_meta($post_id, '_tix_countdown_target', $date_start . 'T' . $ts);
        } else {
            update_post_meta($post_id, '_tix_countdown_target', '');
        }

        // ── Is Past (bei Sync prüfen) ──
        if ($date_end) {
            $te = $time_end ?: '23:59';
            $end_ts = strtotime("{$date_end} {$te}") + 86400;
            if (current_time('timestamp') >= $end_ts) {
                update_post_meta($post_id, '_tix_is_past', '1');
                update_post_meta($post_id, '_tix_is_past_label', 'Vergangen');
                // Status überschreiben (nur bei Auto)
                if ($manual_status === '') {
                    update_post_meta($post_id, '_tix_status', 'past');
                    update_post_meta($post_id, '_tix_status_label', 'Vergangen');
                }
            } else {
                update_post_meta($post_id, '_tix_is_past', '0');
                update_post_meta($post_id, '_tix_is_past_label', '');
            }
        }

        // ── Kalender-Export URLs ──
        if (class_exists('TIX_Calendar')) {
            TIX_Calendar::save_meta($post_id);
        }
    }

    /**
     * Leichtgewichtiges Preis-Meta-Update (für Cron-Phase-Check)
     * Aktualisiert nur die Preis-Breakdance-Meta ohne vollen Sync
     */
    public static function update_price_meta($post_id, $categories) {
        $online_cats  = array_filter($categories, fn($c) => ($c['online'] ?? '1') === '1' && empty($c['offline_ticket']));
        $prices = [];

        $n = 0;
        foreach ($online_cats as $cat) {
            $n++;
            $price = floatval($cat['price']);

            $active_phase = null;
            if (class_exists('TIX_Metabox')) {
                $active_phase = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
            }

            if ($active_phase) {
                $phase_price = floatval($active_phase['price']);
                $effective = $phase_price;
                if ($phase_price < $price) {
                    update_post_meta($post_id, "_tix_ticket_{$n}_price_formatted", self::fmt_price($price));
                    update_post_meta($post_id, "_tix_ticket_{$n}_sale_price", $phase_price);
                    update_post_meta($post_id, "_tix_ticket_{$n}_sale_price_formatted", self::fmt_price($phase_price));
                } else {
                    update_post_meta($post_id, "_tix_ticket_{$n}_price_formatted", self::fmt_price($phase_price));
                    delete_post_meta($post_id, "_tix_ticket_{$n}_sale_price");
                    delete_post_meta($post_id, "_tix_ticket_{$n}_sale_price_formatted");
                }
                update_post_meta($post_id, "_tix_ticket_{$n}_phase_name", $active_phase['name']);
            } else {
                $sale = $cat['sale_price'] ?? '';
                $has_sale = ($sale !== '' && $sale !== null && $sale !== false);
                $effective = $has_sale ? floatval($sale) : $price;
                delete_post_meta($post_id, "_tix_ticket_{$n}_phase_name");
            }

            $prices[] = $effective;
        }

        // Preisrange aktualisieren
        if (!empty($prices)) {
            $min = min($prices);
            $max = max($prices);
            update_post_meta($post_id, '_tix_price_min', $min);
            update_post_meta($post_id, '_tix_price_max', $max);
            update_post_meta($post_id, '_tix_price_min_formatted', self::fmt_price($min));
            update_post_meta($post_id, '_tix_price_max_formatted', self::fmt_price($max));

            if ($min === $max && $min > 0) {
                update_post_meta($post_id, '_tix_price_range', self::fmt_price($min));
            } elseif ($min > 0) {
                update_post_meta($post_id, '_tix_price_range', 'ab ' . self::fmt_price($min));
            }
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TICKERA API-KEY: Alle TC-Events eines TIX-Events bündeln
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private static function sync_api_key($post_id, $event_title, $categories) {
        // CPT tc_api_keys muss existieren
        if (!post_type_exists('tc_api_keys')) return;

        // Alle tc_event_ids dieses Events sammeln
        $tc_event_ids = [];
        foreach ($categories as $cat) {
            $tc_id = intval($cat['tc_event_id'] ?? 0);
            if ($tc_id > 0) $tc_event_ids[] = $tc_id;
        }
        if (empty($tc_event_ids)) return;

        // Bestehenden API-Key für dieses Event suchen
        $existing = get_posts([
            'post_type'   => 'tc_api_keys',
            'post_status' => 'any',
            'meta_query'  => [
                ['key' => '_tix_parent_event_id', 'value' => $post_id],
            ],
            'numberposts' => 1,
        ]);

        if (!empty($existing)) {
            $api_post_id = $existing[0]->ID;
            // Titel aktualisieren
            wp_update_post([
                'ID'         => $api_post_id,
                'post_title' => $event_title,
            ]);
        } else {
            // Neuen API-Key erstellen
            $api_key = strtoupper(substr(md5(uniqid(wp_rand(), true)), 0, 8));
            $api_post_id = wp_insert_post([
                'post_type'   => 'tc_api_keys',
                'post_title'  => $event_title,
                'post_status' => 'publish',
            ]);
            if (!$api_post_id || is_wp_error($api_post_id)) return;

            update_post_meta($api_post_id, 'api_key', $api_key);
            update_post_meta($api_post_id, '_tix_parent_event_id', $post_id);
        }

        // event_name im Tickera-Format: ['all' => ['123', '456', ...]]
        $event_ids_strings = array_map('strval', $tc_event_ids);
        update_post_meta($api_post_id, 'event_name', ['all' => $event_ids_strings]);
        update_post_meta($api_post_id, 'api_key_name', $event_title);

        // API-Key-ID + Key-String im Event speichern (für Metabox-Anzeige)
        $api_key_string = get_post_meta($api_post_id, 'api_key', true);
        update_post_meta($post_id, '_tix_api_key_id', $api_post_id);
        update_post_meta($post_id, '_tix_api_key', $api_key_string);
    }
}
