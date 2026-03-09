<?php
if (!defined('ABSPATH')) exit;

class TIX_Series {

    private static $syncing = false;

    // ──────────────────────────────────────────
    // Hook: save_post_event priority 25
    // ──────────────────────────────────────────
    public static function on_save($post_id, $post) {

        if (self::$syncing) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if ($post->post_status === 'trash') return;

        // Kinder lösen keine Serien-Generierung aus
        if (get_post_meta($post_id, '_tix_series_parent', true)) return;

        $enabled = get_post_meta($post_id, '_tix_series_enabled', true);

        if ($enabled !== '1') {
            // Serie wurde deaktiviert → Kinder abtrennen
            $children = get_post_meta($post_id, '_tix_series_children', true);
            if (!empty($children) && is_array($children)) {
                foreach ($children as $child_id) {
                    delete_post_meta($child_id, '_tix_series_parent');
                    delete_post_meta($child_id, '_tix_series_index');
                }
                delete_post_meta($post_id, '_tix_series_children');
            }
            return;
        }

        // Termine berechnen
        $mode = get_post_meta($post_id, '_tix_series_mode', true) ?: 'periodic';
        $dates = [];

        if ($mode === 'periodic') {
            $pattern    = get_post_meta($post_id, '_tix_series_pattern', true);
            $first_date = get_post_meta($post_id, '_tix_date_start', true);
            if (!empty($pattern) && $first_date) {
                $dates = self::compute_dates($pattern, $first_date);
            }
        } else {
            $manual = get_post_meta($post_id, '_tix_series_manual_dates', true);
            if (!empty($manual) && is_array($manual)) {
                $dates = self::compute_manual_dates($manual);
            }
        }

        if (!empty($dates)) {
            self::sync_children($post_id, $dates);
        }
    }

    // ──────────────────────────────────────────
    // Datum-Berechnung: Periodisch
    // ──────────────────────────────────────────
    public static function compute_dates($pattern, $first_date) {

        $frequency = $pattern['frequency'] ?? 'weekly';
        $end_mode  = $pattern['end_mode'] ?? 'count';
        $end_date  = $pattern['end_date'] ?? '';
        $end_count = max(1, min(365, intval($pattern['end_count'] ?? 10)));
        $max       = ($end_mode === 'count') ? $end_count : 365;
        $limit     = ($end_mode === 'date' && $end_date) ? $end_date : '2028-12-31';

        $dates = [];

        switch ($frequency) {

            case 'weekly':
            case 'biweekly':
                $days = array_map('intval', $pattern['days'] ?? []);
                if (empty($days)) break;
                $step     = ($frequency === 'biweekly') ? 2 : 1;
                $start_ts = strtotime($first_date);
                // Start am Montag der Startwoche
                $dow      = (int) date('N', $start_ts); // 1=Mon, 7=Sun
                $week_ts  = strtotime('-' . ($dow - 1) . ' days', $start_ts);

                while (count($dates) < $max) {
                    foreach ($days as $d) {
                        $ts   = strtotime('+' . ($d - 1) . ' days', $week_ts);
                        $date = date('Y-m-d', $ts);
                        if ($date < $first_date) continue;
                        if ($date > $limit) break 2;
                        $dates[] = ['date_start' => $date, 'date_end' => $date];
                        if (count($dates) >= $max) break 2;
                    }
                    $week_ts = strtotime('+' . $step . ' weeks', $week_ts);
                }
                break;

            case 'monthly_weekday':
                $week_of = intval($pattern['week_of'] ?? 1); // 1-4 or -1 (last)
                $day_of  = intval($pattern['day_of'] ?? 6);  // ISO 1=Mon, 7=Sun
                $day_map = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
                $day_name = $day_map[$day_of] ?? 'Saturday';
                $ordinals = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', -1 => 'last'];
                $ord      = $ordinals[$week_of] ?? 'first';

                $month_ts = strtotime(date('Y-m-01', strtotime($first_date)));
                while (count($dates) < $max) {
                    $ym   = date('Y-m', $month_ts);
                    $ts   = strtotime("{$ord} {$day_name} of {$ym}");
                    $date = date('Y-m-d', $ts);
                    if ($date >= $first_date && $date <= $limit) {
                        $dates[] = ['date_start' => $date, 'date_end' => $date];
                    }
                    $month_ts = strtotime('+1 month', $month_ts);
                    if (date('Y-m-d', $month_ts) > $limit) break;
                }
                break;

            case 'monthly_date':
                $day_num  = max(1, min(31, intval($pattern['day_num'] ?? 1)));
                $month_ts = strtotime(date('Y-m-01', strtotime($first_date)));
                while (count($dates) < $max) {
                    $ym        = date('Y-m', $month_ts);
                    $days_in   = (int) date('t', $month_ts);
                    $actual    = min($day_num, $days_in);
                    $date      = $ym . '-' . str_pad($actual, 2, '0', STR_PAD_LEFT);
                    if ($date >= $first_date && $date <= $limit) {
                        $dates[] = ['date_start' => $date, 'date_end' => $date];
                    }
                    $month_ts = strtotime('+1 month', $month_ts);
                    if (date('Y-m-d', $month_ts) > $limit) break;
                }
                break;
        }

        return $dates;
    }

    // ──────────────────────────────────────────
    // Datum-Berechnung: Manuell
    // ──────────────────────────────────────────
    public static function compute_manual_dates($manual_dates) {
        $dates = [];
        foreach ($manual_dates as $entry) {
            if (!empty($entry['date_start'])) {
                $dates[] = [
                    'date_start' => sanitize_text_field($entry['date_start']),
                    'date_end'   => !empty($entry['date_end']) ? sanitize_text_field($entry['date_end']) : sanitize_text_field($entry['date_start']),
                ];
            }
        }
        usort($dates, fn($a, $b) => strcmp($a['date_start'], $b['date_start']));
        return $dates;
    }

    // ──────────────────────────────────────────
    // Kind-Events synchronisieren
    // ──────────────────────────────────────────
    public static function sync_children($master_id, $target_dates) {

        self::$syncing = true;

        // ALLE save_post_event-Hooks temporär deaktivieren.
        // wp_insert_post/wp_update_post feuern save_post_event für Kinder,
        // aber $_POST enthält die MASTER-Daten → würde Kind-Meta überschreiben.
        remove_action('save_post_event', ['TIX_Metabox', 'save'], 10);
        remove_action('save_post_event', ['TIX_Sync', 'sync'], 20);

        $existing = get_post_meta($master_id, '_tix_series_children', true) ?: [];
        if (!is_array($existing)) $existing = [];

        // Bestehende Kinder nach date_start mappen
        $by_date = [];
        foreach ($existing as $child_id) {
            if (!get_post($child_id)) continue; // gelöschter Post
            $ds = get_post_meta($child_id, '_tix_date_start', true);
            if ($ds) $by_date[$ds] = intval($child_id);
        }

        $new_children = [];
        foreach ($target_dates as $i => $date) {
            $ds = $date['date_start'];
            $de = $date['date_end'] ?: $ds;

            if (isset($by_date[$ds])) {
                // Kind existiert → Template-Felder aktualisieren
                $child_id = $by_date[$ds];
                self::update_child_from_master($master_id, $child_id);
                update_post_meta($child_id, '_tix_series_index', $i);
                unset($by_date[$ds]);
            } else {
                // Neues Kind erstellen
                $child_id = self::create_child($master_id, $ds, $de, $i);
            }

            if ($child_id) $new_children[] = intval($child_id);
        }

        // Übrige Kinder: Datum wurde entfernt
        if (!empty($by_date)) {
            // Cleanup-Hooks deaktivieren (delete_generated würde protect_product re-adden)
            remove_action('wp_trash_post',      ['TIX_Cleanup', 'delete_generated']);
            remove_action('before_delete_post',  ['TIX_Cleanup', 'delete_generated']);
            remove_action('wp_trash_post',      ['TIX_Cleanup', 'protect_product']);
            remove_action('before_delete_post',  ['TIX_Cleanup', 'protect_product']);

            foreach ($by_date as $orphan_id) {
                if (self::child_has_sales($orphan_id)) {
                    // Verkäufe vorhanden → abtrennen (wird Einzelevent)
                    delete_post_meta($orphan_id, '_tix_series_parent');
                    delete_post_meta($orphan_id, '_tix_series_index');
                } else {
                    self::delete_child_products($orphan_id);
                    wp_trash_post($orphan_id);
                }
            }

            add_action('wp_trash_post',      ['TIX_Cleanup', 'delete_generated']);
            add_action('before_delete_post',  ['TIX_Cleanup', 'delete_generated']);
            add_action('wp_trash_post',      ['TIX_Cleanup', 'protect_product']);
            add_action('before_delete_post',  ['TIX_Cleanup', 'protect_product']);
        }

        update_post_meta($master_id, '_tix_series_children', $new_children);

        // Hooks wieder aktivieren
        add_action('save_post_event', ['TIX_Metabox', 'save'], 10, 2);
        add_action('save_post_event', ['TIX_Sync', 'sync'], 20, 2);

        // Sync für alle Kinder auslösen (Breakdance-Meta + WC)
        // Jetzt haben alle Kinder korrekte Meta-Daten gesetzt.
        foreach ($new_children as $child_id) {
            $child_post = get_post($child_id);
            if ($child_post) {
                TIX_Sync::sync($child_id, $child_post);
            }
        }

        self::$syncing = false;
    }

    // ──────────────────────────────────────────
    // Kind erstellen
    // ──────────────────────────────────────────
    private static function create_child($master_id, $date_start, $date_end, $index) {

        $master     = get_post($master_id);
        $time_start = get_post_meta($master_id, '_tix_time_start', true);
        $time_end   = get_post_meta($master_id, '_tix_time_end', true);
        $time_doors = get_post_meta($master_id, '_tix_time_doors', true);

        $child_id = wp_insert_post([
            'post_type'    => 'event',
            'post_title'   => $master->post_title,
            'post_content' => $master->post_content,
            'post_excerpt' => $master->post_excerpt,
            'post_status'  => $master->post_status,
            'post_author'  => $master->post_author,
        ]);

        if (is_wp_error($child_id)) return 0;

        // Serien-Link
        update_post_meta($child_id, '_tix_series_parent', $master_id);
        update_post_meta($child_id, '_tix_series_index', $index);

        // Eigene Daten
        update_post_meta($child_id, '_tix_date_start', $date_start);
        update_post_meta($child_id, '_tix_date_end', $date_end);
        update_post_meta($child_id, '_tix_time_start', $time_start);
        update_post_meta($child_id, '_tix_time_end', $time_end);
        update_post_meta($child_id, '_tix_time_doors', $time_doors);

        // Template-Meta kopieren
        self::copy_template_meta($master_id, $child_id);

        // Taxonomie kopieren
        $terms = wp_get_object_terms($master_id, 'event_category', ['fields' => 'ids']);
        if (!empty($terms) && !is_wp_error($terms)) {
            wp_set_object_terms($child_id, $terms, 'event_category');
        }

        // Beitragsbild
        $thumb = get_post_thumbnail_id($master_id);
        if ($thumb) set_post_thumbnail($child_id, $thumb);

        return $child_id;
    }

    // ──────────────────────────────────────────
    // Template-Meta vom Master → Kind kopieren
    // ──────────────────────────────────────────
    private static function copy_template_meta($master_id, $child_id) {

        $copy_keys = [
            '_tix_location_id', '_tix_location', '_tix_address',
            '_tix_organizer_id', '_tix_organizer',
            '_tix_tickets_enabled', '_tix_presale_active',
            '_tix_presale_end_mode', '_tix_presale_end_offset', '_tix_presale_end',
            '_tix_event_status',
            '_tix_info_description', '_tix_info_description_label',
            '_tix_info_lineup', '_tix_info_lineup_label',
            '_tix_info_specials', '_tix_info_specials_label',
            '_tix_info_extra_info', '_tix_info_extra_info_label',
            '_tix_info_age_limit', '_tix_info_age_limit_label',
            '_tix_faq',
            '_tix_gallery', '_tix_video_url', '_tix_video_id', '_tix_video_embed', '_tix_video_type',
            '_tix_group_discount',
            '_tix_upsell_events', '_tix_upsell_disabled',
            '_tix_embed_enabled', '_tix_embed_domains',
            '_tix_abandoned_cart', '_tix_express_checkout', '_tix_ticket_transfer',
        ];

        foreach ($copy_keys as $key) {
            $val = get_post_meta($master_id, $key, true);
            if ($val !== '' && $val !== false) {
                update_post_meta($child_id, $key, $val);
            } else {
                delete_post_meta($child_id, $key);
            }
        }

        // Ticket-Kategorien als Template (ohne product_id/sku)
        $cats = get_post_meta($master_id, '_tix_ticket_categories', true);
        if (is_array($cats)) {
            $existing_cats = get_post_meta($child_id, '_tix_ticket_categories', true);
            $template_cats = [];

            foreach ($cats as $i => $cat) {
                $child_cat = $cat;
                // Bestehende Produkt-IDs des Kindes beibehalten
                if (is_array($existing_cats) && isset($existing_cats[$i])) {
                    $child_cat['product_id']  = $existing_cats[$i]['product_id'] ?? '';
                    $child_cat['sku']         = $existing_cats[$i]['sku'] ?? '';
                } else {
                    $child_cat['product_id']  = '';
                    $child_cat['sku']         = '';
                }
                $template_cats[] = $child_cat;
            }

            update_post_meta($child_id, '_tix_ticket_categories', $template_cats);
        }
    }

    // ──────────────────────────────────────────
    // Kind von Master aktualisieren
    // ──────────────────────────────────────────
    private static function update_child_from_master($master_id, $child_id) {

        // Detached → nicht aktualisieren
        if (get_post_meta($child_id, '_tix_series_detached', true) === '1') return;

        $master = get_post($master_id);

        // Titel + Status synchronisieren
        wp_update_post([
            'ID'           => $child_id,
            'post_title'   => $master->post_title,
            'post_content' => $master->post_content,
            'post_excerpt' => $master->post_excerpt,
            'post_status'  => $master->post_status,
        ]);

        // Zeiten synchronisieren
        update_post_meta($child_id, '_tix_time_start', get_post_meta($master_id, '_tix_time_start', true));
        update_post_meta($child_id, '_tix_time_end', get_post_meta($master_id, '_tix_time_end', true));
        update_post_meta($child_id, '_tix_time_doors', get_post_meta($master_id, '_tix_time_doors', true));

        // Template-Meta kopieren
        self::copy_template_meta($master_id, $child_id);

        // Taxonomie
        $terms = wp_get_object_terms($master_id, 'event_category', ['fields' => 'ids']);
        if (!empty($terms) && !is_wp_error($terms)) {
            wp_set_object_terms($child_id, $terms, 'event_category');
        }

        // Beitragsbild
        $thumb = get_post_thumbnail_id($master_id);
        if ($thumb) set_post_thumbnail($child_id, $thumb);
        else delete_post_thumbnail($child_id);
    }

    // ──────────────────────────────────────────
    // Prüfen ob Kind Verkäufe hat
    // ──────────────────────────────────────────
    private static function child_has_sales($child_id) {
        $cats = get_post_meta($child_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) return false;
        foreach ($cats as $cat) {
            $product_id = intval($cat['product_id'] ?? 0);
            if (!$product_id) continue;
            if (!function_exists('wc_get_product')) continue;
            $product = wc_get_product($product_id);
            if (!$product) continue;
            if ($product->get_total_sales() > 0) return true;
        }
        return false;
    }

    // ──────────────────────────────────────────
    // WC-Produkte eines Kindes löschen
    // ──────────────────────────────────────────
    private static function delete_child_products($child_id) {
        $cats = get_post_meta($child_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) return;

        foreach ($cats as $cat) {
            if (!empty($cat['product_id'])) {
                if (function_exists('wc_get_product')) {
                    $product = wc_get_product(intval($cat['product_id']));
                    if ($product) $product->delete(true);
                }
            }
        }
    }

    // ──────────────────────────────────────────
    // Master-Löschung: Kinder aufräumen
    // ──────────────────────────────────────────
    public static function on_trash($post_id) {
        if (get_post_type($post_id) !== 'event') return;
        $children = get_post_meta($post_id, '_tix_series_children', true);
        if (!is_array($children) || empty($children)) return;

        self::$syncing = true;

        // ALLE Hooks deaktivieren die bei Löschung stören könnten.
        // TIX_Cleanup-Hooks: delete_generated re-addet protect_product → exit.
        // save_post_event-Hooks: TIX_Sync::sync erstellt NEUE Produkte für gelöschte Events!
        remove_action('wp_trash_post',      ['TIX_Cleanup', 'delete_generated']);
        remove_action('before_delete_post',  ['TIX_Cleanup', 'delete_generated']);
        remove_action('wp_trash_post',      ['TIX_Cleanup', 'protect_product']);
        remove_action('before_delete_post',  ['TIX_Cleanup', 'protect_product']);
        remove_action('save_post_event',     ['TIX_Metabox', 'save'], 10);
        remove_action('save_post_event',     ['TIX_Sync', 'sync'], 20);
        remove_action('save_post_event',     [__CLASS__, 'on_save'], 25);

        foreach ($children as $child_id) {
            if (self::child_has_sales($child_id)) {
                // Verkäufe vorhanden → abtrennen (wird Einzelevent)
                delete_post_meta($child_id, '_tix_series_parent');
                delete_post_meta($child_id, '_tix_series_index');
            } else {
                // Produkte löschen + Kind endgültig entfernen
                // wp_delete_post statt wp_trash_post: feuert KEIN save_post_event
                self::delete_child_products($child_id);
                wp_delete_post(intval($child_id), true);
            }
        }

        delete_post_meta($post_id, '_tix_series_children');

        // Master-eigene Produkte + API-Key löschen
        // (existieren wenn Event vor Series-Aktivierung als normales Event gespeichert wurde)
        self::delete_child_products($post_id);
        $api_key_id = get_post_meta($post_id, '_tix_api_key_id', true);
        if ($api_key_id) {
            wp_delete_post(intval($api_key_id), true);
        }

        // Hooks wieder aktivieren
        add_action('wp_trash_post',      ['TIX_Cleanup', 'delete_generated']);
        add_action('before_delete_post',  ['TIX_Cleanup', 'delete_generated']);
        add_action('wp_trash_post',      ['TIX_Cleanup', 'protect_product']);
        add_action('before_delete_post',  ['TIX_Cleanup', 'protect_product']);
        add_action('save_post_event',     ['TIX_Metabox', 'save'], 10, 2);
        add_action('save_post_event',     ['TIX_Sync', 'sync'], 20, 2);
        add_action('save_post_event',     [__CLASS__, 'on_save'], 25, 2);

        self::$syncing = false;
    }

    // ──────────────────────────────────────────
    // Shortcode: [tix_series_dates]
    // ──────────────────────────────────────────
    public static function render_dates_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post_id = $atts['id'] ?: get_the_ID();
        if (!$post_id) return '';

        // Parent ermitteln
        $parent_id = get_post_meta($post_id, '_tix_series_parent', true);
        if (!$parent_id) {
            // Vielleicht ist das der Master
            if (get_post_meta($post_id, '_tix_series_enabled', true) !== '1') return '';
            $parent_id = $post_id;
        }

        $children = get_post_meta($parent_id, '_tix_series_children', true);
        if (!is_array($children) || empty($children)) return '';

        $today = wp_date('Y-m-d');
        $html  = '<div class="tix-series-dates">';
        $html .= '<h3 class="tix-series-dates-title">Weitere Termine</h3>';
        $html .= '<ul class="tix-series-dates-list">';

        foreach ($children as $child_id) {
            if (intval($child_id) === intval($post_id)) continue; // aktuelles Event überspringen
            if (get_post_status($child_id) !== 'publish') continue;
            $ds = get_post_meta($child_id, '_tix_date_start', true);
            if (!$ds || $ds < $today) continue;

            $display = date_i18n('D, d.m.Y', strtotime($ds));
            $url     = get_permalink($child_id);
            $html   .= '<li><a href="' . esc_url($url) . '">' . esc_html($display) . ' &rarr;</a></li>';
        }

        $html .= '</ul></div>';
        return $html;
    }
}
