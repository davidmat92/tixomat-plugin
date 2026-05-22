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

        // Ohne WooCommerce: nur Breakdance-Meta, kein Produkt-Sync
        if (!function_exists('wc_get_product')) {
            $cats = get_post_meta($post_id, '_tix_ticket_categories', true);
            self::save_breakdance_meta($post_id, is_array($cats) ? $cats : []);
            return;
        }

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
            $product_id  = !empty($cat['product_id'])  ? intval($cat['product_id'])  : 0;
            $cat_image   = !empty($cat['image_id'])    ? intval($cat['image_id'])    : 0;
            $image_id    = $cat_image ?: $event_thumb_id;
            $is_online   = ($cat['online'] ?? '1') === '1';

            $sale_price = $cat['sale_price'] ?? '';
            $has_sale   = ($sale_price !== '' && $sale_price !== null && $sale_price !== false);

            // ── Offline-Ticket (Abendkasse): NUR Anzeige, kein WC/TC ──
            $is_offline_ticket = !empty($cat['offline_ticket']);

            if ($is_offline_ticket) {
                // Falls vorher online → deaktivieren (nicht löschen)
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

            // Bridge-Meta: Tixomat eigenes System
            if ($product_id) {
                update_post_meta($product_id, '_tix_is_ticket', 'yes');
                update_post_meta($product_id, '_tix_source_event', $post_id);
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
    public static function save_breakdance_meta($post_id, $categories = []) {

        $date_start = get_post_meta($post_id, '_tix_date_start', true);
        $date_end   = get_post_meta($post_id, '_tix_date_end', true);
        $time_start = get_post_meta($post_id, '_tix_time_start', true);
        $time_end   = get_post_meta($post_id, '_tix_time_end', true);
        $time_doors = get_post_meta($post_id, '_tix_time_doors', true);

        // Kurz mit Wochentag-Abkürzung — z.B. "Do., 04.06.2026" (für Event-Cards, Breakdance-Dynamic-Data)
        update_post_meta($post_id, '_tix_date_start_formatted', $date_start ? date_i18n('D., d.m.Y', strtotime($date_start)) : '');
        update_post_meta($post_id, '_tix_date_end_formatted',   $date_end   ? date_i18n('D., d.m.Y', strtotime($date_end))   : '');
        // Lang mit vollem Wochentag — z.B. "Donnerstag, 04.06.2026"
        update_post_meta($post_id, '_tix_date_start_long', $date_start ? date_i18n('l, d.m.Y', strtotime($date_start)) : '');
        update_post_meta($post_id, '_tix_date_end_long',   $date_end   ? date_i18n('l, d.m.Y', strtotime($date_end))   : '');
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

            // Card-Datum: "Fr, 28. März · 20:00" (für Event-Karten)
            $card_date = date_i18n('D, j. F', strtotime($date_start));
            if ($ts) $card_date .= ' · ' . $ts;
            if (!$same_day && $date_end) {
                $card_date .= ' – ' . date_i18n('D, j. F', strtotime($date_end));
                if ($te) $card_date .= ' · ' . $te;
            }
            update_post_meta($post_id, '_tix_date_card', $card_date);

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

        // Location kurz: "Name, Stadt" (für Event-Karten)
        $loc_short = $location;
        if ($address) {
            // Stadt aus Adresse extrahieren (letzter Teil nach PLZ-Muster oder letztes Komma-Segment)
            $city = '';
            if (preg_match('/\d{4,5}\s+(.+?)$/', $address, $m)) {
                $city = trim($m[1]);
            } elseif (strpos($address, ',') !== false) {
                $parts = explode(',', $address);
                $city = trim(end($parts));
                // Wenn Stadt mit PLZ beginnt, PLZ entfernen
                $city = preg_replace('/^\d{4,5}\s*/', '', $city);
            }
            if ($city) $loc_short .= ', ' . $city;
        }
        update_post_meta($post_id, '_tix_location_short', $loc_short);

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

            // Altersbegrenzung speziell — 0 (oder leer) heißt explizit "keine Begrenzung"
            $age = get_post_meta($post_id, '_tix_info_age_limit', true);
            if ($age !== '' && $age !== null && intval($age) > 0) {
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
            $sale     = $cat['sale_price'] ?? '';
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

            update_post_meta($post_id, "_tix_ticket_{$n}_qty", $cat['qty'] ?? 0);
            update_post_meta($post_id, "_tix_ticket_{$n}_desc", $cat['desc'] ?? '');
            update_post_meta($post_id, "_tix_ticket_{$n}_product_id", $cat['product_id'] ?? 0);
            update_post_meta($post_id, "_tix_ticket_{$n}_tc_event_id", $cat['tc_event_id'] ?? 0);

            $img_id = intval($cat['image_id'] ?? 0) ?: get_post_thumbnail_id($post_id);
            if ($img_id) {
                $img_url = wp_get_attachment_image_url($img_id, 'large');
                update_post_meta($post_id, "_tix_ticket_{$n}_image_url", $img_url ?: '');
            }

            if (!empty($cat['product_id'])) {
                $pid = intval($cat['product_id']);
                $product_ids[] = $pid;

                // WC-Produkt → Event-Referenz setzen (für Order-Items-Sync)
                update_post_meta($pid, '_tix_parent_event_id', $post_id);

                if (function_exists('wc_get_cart_url')) {
                    $url = wc_get_cart_url() . '?add-to-cart=' . $pid;
                    update_post_meta($post_id, "_tix_ticket_{$n}_add_to_cart_url", $url);
                }
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
                update_post_meta($post_id, '_tix_price_card', number_format($min, 2, ',', '.') . ' €');
                update_post_meta($post_id, '_tix_price_label', 'Tickets ab ' . number_format($min, 2, ',', '.') . '€');
            } elseif ($min > 0) {
                update_post_meta($post_id, '_tix_price_range', 'ab ' . self::fmt_price($min));
                update_post_meta($post_id, '_tix_price_range_full',
                    number_format($min, 2, ',', '.') . ' € – ' . self::fmt_price($max));
                update_post_meta($post_id, '_tix_price_card', 'Ab ' . number_format($min, 2, ',', '.') . ' €');
                update_post_meta($post_id, '_tix_price_label', 'Tickets ab ' . number_format($min, 2, ',', '.') . '€');
            }
        } else {
            update_post_meta($post_id, '_tix_price_min', 0);
            update_post_meta($post_id, '_tix_price_max', 0);
            update_post_meta($post_id, '_tix_price_min_formatted', '');
            update_post_meta($post_id, '_tix_price_max_formatted', '');
            update_post_meta($post_id, '_tix_price_range', '');
            update_post_meta($post_id, '_tix_price_range_full', '');
            update_post_meta($post_id, '_tix_price_card', '');
            update_post_meta($post_id, '_tix_price_label', '');
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

            if (!$pid || !function_exists('wc_get_product')) { $all_sold_out = false; continue; }
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

        // Native Orders dazuzählen (Kategorien ohne WC-Produkt oder zusätzlich native)
        if (class_exists('TIX_Order')) {
            global $wpdb;
            $t  = TIX_Order::table_name();
            $ti = TIX_Order::items_table_name();
            $native_sold = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(i.quantity), 0)
                 FROM $ti i
                 INNER JOIN $t o ON i.order_id = o.id
                 WHERE o.status IN ('completed','processing')
                   AND o.wc_order_id = 0
                   AND i.event_id = %d",
                $post_id
            )));
            $total_sold += $native_sold;
        }

        // Stornierte Tickets abziehen
        global $wpdb;
        $cancelled_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_s ON p.ID = pm_s.post_id AND pm_s.meta_key = '_tix_ticket_status' AND pm_s.meta_value = 'cancelled'
             INNER JOIN {$wpdb->postmeta} pm_e ON p.ID = pm_e.post_id AND pm_e.meta_key = '_tix_ticket_event_id' AND pm_e.meta_value = %d
             WHERE p.post_type = 'tix_ticket' AND p.post_status = 'publish'",
            $post_id
        )));
        $total_sold = max(0, $total_sold - $cancelled_count);

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

        // Preisrange aktualisieren — IDENTISCH zu save_breakdance_meta() halten,
        // sonst entstehen Inkonsistenzen wenn nur eine der beiden Funktionen läuft
        if (!empty($prices)) {
            $min = min($prices);
            $max = max($prices);
            update_post_meta($post_id, '_tix_price_min', $min);
            update_post_meta($post_id, '_tix_price_max', $max);
            update_post_meta($post_id, '_tix_price_min_formatted', self::fmt_price($min));
            update_post_meta($post_id, '_tix_price_max_formatted', self::fmt_price($max));

            if ($min === $max && $min > 0) {
                update_post_meta($post_id, '_tix_price_range',      self::fmt_price($min));
                update_post_meta($post_id, '_tix_price_range_full', self::fmt_price($min));
                update_post_meta($post_id, '_tix_price_card',  number_format($min, 2, ',', '.') . ' €');
                update_post_meta($post_id, '_tix_price_label', 'Tickets ab ' . number_format($min, 2, ',', '.') . '€');
            } elseif ($min > 0) {
                update_post_meta($post_id, '_tix_price_range',      'ab ' . self::fmt_price($min));
                update_post_meta($post_id, '_tix_price_range_full', number_format($min, 2, ',', '.') . ' € – ' . self::fmt_price($max));
                update_post_meta($post_id, '_tix_price_card',  'Ab ' . number_format($min, 2, ',', '.') . ' €');
                update_post_meta($post_id, '_tix_price_label', 'Tickets ab ' . number_format($min, 2, ',', '.') . '€');
            }
        } else {
            // Keine Online-Cats → alle Preisfelder leeren (sonst bleiben stale-Werte)
            update_post_meta($post_id, '_tix_price_min', 0);
            update_post_meta($post_id, '_tix_price_max', 0);
            update_post_meta($post_id, '_tix_price_min_formatted', '');
            update_post_meta($post_id, '_tix_price_max_formatted', '');
            update_post_meta($post_id, '_tix_price_range', '');
            update_post_meta($post_id, '_tix_price_range_full', '');
            update_post_meta($post_id, '_tix_price_card', '');
            update_post_meta($post_id, '_tix_price_label', '');
        }
    }

    /**
     * Berechnet _tix_sold_total für ein Event live aus tix_order_items.
     * WC-UNABHÄNGIG — funktioniert allein aus den nativen tix_orders-Daten.
     * Wird bei JEDEM Order-Status-Change + bei Ticket-Stornierungen aufgerufen,
     * damit die Meta NIE stale wird.
     *
     * @return int Neue Sold-Total Zahl
     */
    public static function recompute_event_sold_count($event_id) {
        $event_id = intval($event_id);
        if ($event_id <= 0) return 0;

        global $wpdb;
        $ti = $wpdb->prefix . 'tix_order_items';
        $t  = $wpdb->prefix . 'tix_orders';

        // 1. Bezahlte Tickets aus tix_order_items (native + WC-Dual-Write)
        $paid = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $paid = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(i.quantity), 0)
                 FROM $ti i INNER JOIN $t o ON i.order_id = o.id
                 WHERE o.status IN ('completed','processing') AND i.event_id = %d",
                $event_id
            )));
        }

        // 2. Einzeln stornierte Tickets abziehen (tix_ticket CPT mit _tix_ticket_status = 'cancelled')
        $cancelled = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} ps ON p.ID = ps.post_id AND ps.meta_key = '_tix_ticket_status' AND ps.meta_value = 'cancelled'
             INNER JOIN {$wpdb->postmeta} pe ON p.ID = pe.post_id AND pe.meta_key = '_tix_ticket_event_id' AND pe.meta_value = %d
             WHERE p.post_type = 'tix_ticket'",
            $event_id
        )));

        $total_sold = max(0, $paid - $cancelled);

        // Meta updaten
        update_post_meta($event_id, '_tix_sold_total', $total_sold);

        // Sold-Percent + Display aktualisieren (Kapazität aus bestehender Meta, nicht neu berechnen)
        $total_cap = intval(get_post_meta($event_id, '_tix_capacity_total', true));
        if ($total_cap > 0) {
            $sold_pct = round(($total_sold / $total_cap) * 100);
            update_post_meta($event_id, '_tix_sold_percent', $sold_pct);
            update_post_meta($event_id, '_tix_sold_display', "{$total_sold}/{$total_cap} ({$sold_pct}%)");
        } else {
            update_post_meta($event_id, '_tix_sold_display', $total_sold > 0 ? "{$total_sold} verkauft" : '');
        }

        return $total_sold;
    }

    /**
     * Recompute für alle Events einer Bestellung.
     * Listener für tix_order_status_changed.
     */
    public static function on_order_status_changed($order_id, $new_status = '', $old_status = '', $gateway = '') {
        if (!class_exists('TIX_Order')) return;
        $order = TIX_Order::get($order_id);
        if (!$order) return;

        $event_ids = [];
        foreach ($order->get_items() as $it) {
            $eid = method_exists($it, 'get_event_id') ? intval($it->get_event_id()) : 0;
            if ($eid && !in_array($eid, $event_ids, true)) $event_ids[] = $eid;
        }
        foreach ($event_ids as $eid) {
            self::recompute_event_sold_count($eid);
        }
    }

    /**
     * Wenn ein einzelnes Ticket storniert oder re-aktiviert wird,
     * den Sold-Count des zugehörigen Events neu berechnen.
     */
    public static function on_ticket_status_changed($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key !== '_tix_ticket_status') return;
        if (get_post_type($post_id) !== 'tix_ticket') return;
        $event_id = intval(get_post_meta($post_id, '_tix_ticket_event_id', true));
        if ($event_id) self::recompute_event_sold_count($event_id);
    }

    /**
     * Automatisch Preis-Meta aktualisieren, wenn _tix_ticket_categories gespeichert wird.
     * Fängt ALLE Codepfade ab (Metabox, REST API, Organizer-Dashboard, Serien, etc.)
     */
    public static function auto_update_price_on_meta_change($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key !== '_tix_ticket_categories') return;
        if (get_post_type($post_id) !== 'event') return;

        // WordPress übergibt den serialisierten String an updated_postmeta
        $categories = maybe_unserialize($meta_value);
        if (!is_array($categories) || empty($categories)) return;

        // Rekursion vermeiden (update_price_meta schreibt auch postmeta)
        static $running = [];
        if (isset($running[$post_id])) return;
        $running[$post_id] = true;

        self::update_price_meta($post_id, $categories);

        // WC-Produkt-Preise nachziehen (wichtig wenn Kategorien geändert werden,
        // ohne dass der volle sync() über save_post_event läuft — z.B. REST API,
        // Organizer-Dashboard, Bulk-Update, direkte update_post_meta-Aufrufe).
        self::sync_wc_product_prices($categories);

        unset($running[$post_id]);
    }

    /**
     * Aktualisiert WC-Produkt-Preise für alle Kategorien mit product_id.
     * Leichtgewichtig — nur Preise, keine anderen Product-Felder.
     */
    public static function sync_wc_product_prices($categories) {
        if (!function_exists('wc_get_product')) return;
        if (!is_array($categories)) return;

        foreach ($categories as $cat) {
            $product_id = intval($cat['product_id'] ?? 0);
            if (!$product_id) continue;

            $product = wc_get_product($product_id);
            if (!$product) continue;

            $price    = floatval($cat['price'] ?? 0);
            $sale_raw = $cat['sale_price'] ?? '';
            $has_sale = ($sale_raw !== '' && $sale_raw !== null && $sale_raw !== false);
            $sale     = $has_sale ? floatval($sale_raw) : null;

            // Phase-aware Pricing
            $active_phase = class_exists('TIX_Metabox') ? TIX_Metabox::get_active_phase($cat['phases'] ?? []) : null;
            if ($active_phase) {
                $phase_price = floatval($active_phase['price']);
                if ($phase_price < $price) {
                    $product->set_regular_price($price);
                    $product->set_sale_price($phase_price);
                } else {
                    $product->set_regular_price($phase_price);
                    $product->set_sale_price('');
                }
            } else {
                $product->set_regular_price($price);
                if ($sale !== null) $product->set_sale_price($sale);
                else $product->set_sale_price('');
            }

            $product->save();
        }

        // Dynamic Pricing Cache leeren
        if (class_exists('TIX_Dynamic_Pricing')) {
            TIX_Dynamic_Pricing::clear_cache();
        }
    }
}
