<?php
if (!defined('ABSPATH')) exit;

class TIX_Cleanup {

    public static function init() {
        // Event löschen → generierte Einträge mit löschen
        add_action('wp_trash_post',      [__CLASS__, 'delete_generated']);
        add_action('before_delete_post', [__CLASS__, 'delete_generated']);

        // ── Löschschutz: WC-Produkte nicht direkt löschbar ──
        add_action('wp_trash_post',      [__CLASS__, 'protect_product']);
        add_action('before_delete_post',  [__CLASS__, 'protect_product']);

        // Admin-Hinweis wenn Löschung verhindert
        add_action('admin_notices', [__CLASS__, 'protected_notice']);

        // Orphan-Cleanup Admin-Action
        add_action('admin_post_tix_cleanup_orphans', [__CLASS__, 'cleanup_orphans']);
        add_action('admin_notices', [__CLASS__, 'orphan_cleanup_notice']);
    }

    /**
     * Beim Löschen eines Events: Generierte Einträge + alle verknüpften Daten mit löschen
     */
    public static function delete_generated($post_id) {
        if (get_post_type($post_id) !== 'event') return;

        // Temporär Schutz + eigene Hooks deaktivieren (wir löschen bewusst)
        remove_action('wp_trash_post',     [__CLASS__, 'delete_generated']);
        remove_action('before_delete_post', [__CLASS__, 'delete_generated']);
        remove_action('wp_trash_post',     [__CLASS__, 'protect_product']);
        remove_action('before_delete_post', [__CLASS__, 'protect_product']);
        // Series-Hooks: verhindern Doppelverarbeitung bei Kinder-Löschung
        if (class_exists('TIX_Series')) {
            remove_action('wp_trash_post',      ['TIX_Series', 'on_trash']);
            remove_action('before_delete_post', ['TIX_Series', 'on_trash']);
        }

        // WC-Produkte + TC-Events löschen
        $categories = get_post_meta($post_id, '_tix_ticket_categories', true);
        if (is_array($categories)) {
            foreach ($categories as $cat) {
                if (!empty($cat['tc_event_id'])) {
                    wp_delete_post(intval($cat['tc_event_id']), true);
                }
                if (!empty($cat['product_id'])) {
                    $product = wc_get_product(intval($cat['product_id']));
                    if ($product) $product->delete(true);
                }
            }
        }

        // ── Alle Custom-Table-Daten + verknüpfte CPTs löschen ──
        self::purge_event_data($post_id);

        // Hooks wieder aktivieren
        add_action('wp_trash_post',     [__CLASS__, 'delete_generated']);
        add_action('before_delete_post', [__CLASS__, 'delete_generated']);
        add_action('wp_trash_post',     [__CLASS__, 'protect_product']);
        add_action('before_delete_post', [__CLASS__, 'protect_product']);
        if (class_exists('TIX_Series')) {
            add_action('wp_trash_post',      ['TIX_Series', 'on_trash']);
            add_action('before_delete_post', ['TIX_Series', 'on_trash']);
        }
    }

    /**
     * ALLE verknüpften Daten eines Events restlos aus der Datenbank entfernen.
     *
     * Räumt auf:
     * - Custom Tables: Raffle, Waitlist, Feedback, Seatmap, Ticket-DB, Promoter,
     *   Campaign-Views, Meta-Pixel-Conversions
     * - Verknüpfte CPTs: TIX-Tickets, Abandoned Carts, Subscribers, WC-Coupons
     * - Geplante Cron-Jobs (Reminder + Follow-up E-Mails)
     * - Per-Event Transients + KI-Schutz Post-Meta
     * - Serien-Kinder (ohne Verkäufe: komplett löschen; mit Verkäufen: entkoppeln)
     * - Exklusive Medien-Attachments (Featured Image, Gallery)
     *
     * Kann sowohl von delete_generated() (Trash/Delete) als auch von
     * handle_force_delete() (Admin-Action) aufgerufen werden.
     *
     * @param int $event_id Event Post-ID
     * @return array Anzahl gelöschter Einträge pro Typ
     */
    public static function purge_event_data($event_id) {
        global $wpdb;
        $event_id = intval($event_id);
        $counts   = [];

        // Rekursions-Schutz
        static $purging = [];
        if (isset($purging[$event_id])) return $counts;
        $purging[$event_id] = true;

        // ── 1. Custom Tables ──

        // Raffle-Einträge
        $counts['raffle'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tix_raffle_entries WHERE event_id = %d", $event_id)
        );

        // Waitlist-Einträge
        $counts['waitlist'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tix_waitlist WHERE event_id = %d", $event_id)
        );

        // Feedback-Einträge
        $counts['feedback'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tix_feedback WHERE event_id = %d", $event_id)
        );

        // Sitzplatz-Reservierungen
        $counts['seats'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tix_seat_reservations WHERE event_id = %d", $event_id)
        );

        // Ticket-Datenbank (denormalisierte Kopie)
        $counts['ticket_db'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tixomat_tickets WHERE event_id = %d", $event_id)
        );

        // Promoter-Event-Zuordnungen
        $counts['promoter_events'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tixomat_promoter_events WHERE event_id = %d", $event_id)
        );

        // Promoter-Provisionen (Buchhaltung → löschen, da Event weg)
        $counts['promoter_commissions'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tixomat_promoter_commissions WHERE event_id = %d", $event_id)
        );

        // Campaign-Views (UTM Tracking)
        $counts['campaign_views'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tixomat_campaign_views WHERE event_id = %d", $event_id)
        );

        // Meta-Pixel Conversions
        $counts['meta_conversions'] = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}tix_meta_conversions WHERE event_post_id = %d", $event_id)
        );

        // ── 2. Verknüpfte CPTs ──

        // TIX-Tickets (einzelne Ticket-Posts)
        if (post_type_exists('tix_ticket')) {
            $tix_ticket_ids = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'meta_key'       => '_tix_ticket_event_id',
                'meta_value'     => $event_id,
                'fields'         => 'ids',
            ]);
            foreach ($tix_ticket_ids as $tt_id) {
                wp_delete_post($tt_id, true);
            }
            $counts['tix_tickets'] = count($tix_ticket_ids);
        }

        // Abandoned Carts
        $ac_ids = get_posts([
            'post_type'      => 'tix_abandoned_cart',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_tix_ac_event_id',
            'meta_value'     => $event_id,
            'fields'         => 'ids',
        ]);
        foreach ($ac_ids as $ac_id) {
            wp_delete_post($ac_id, true);
        }
        $counts['abandoned_carts'] = count($ac_ids);

        // Event-spezifische Subscribers
        $sub_ids = get_posts([
            'post_type'      => 'tix_subscriber',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_tix_sub_event_id',
            'meta_value'     => $event_id,
            'fields'         => 'ids',
        ]);
        foreach ($sub_ids as $sub_id) {
            wp_delete_post($sub_id, true);
        }
        $counts['subscribers'] = count($sub_ids);

        // WC-Coupons die zu diesem Event gehören
        $coupon_ids = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_tix_event_coupon',
            'meta_value'     => $event_id,
            'fields'         => 'ids',
        ]);
        foreach ($coupon_ids as $coupon_id) {
            wp_delete_post($coupon_id, true);
        }
        $counts['coupons'] = count($coupon_ids);

        // ── 3. Geplante Cron-Jobs (Reminder + Follow-up E-Mails) ──
        $cron_cleared = 0;

        // Action Scheduler (wenn vorhanden)
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('tix_send_reminder_email', null, 'tixomat');
            as_unschedule_all_actions('tix_send_followup_email', null, 'tixomat');
            // Hinweis: as_unschedule_all_actions ohne args löscht alle für diesen Hook.
            // Wir nutzen stattdessen die DB direkt für gezielte Löschung:
        }

        // WP-Cron: Geplante Events mit diesem Event-ID entfernen
        $crons = _get_cron_array();
        if (is_array($crons)) {
            foreach ($crons as $timestamp => $hooks) {
                foreach (['tix_send_reminder_email', 'tix_send_followup_email'] as $hook) {
                    if (isset($hooks[$hook])) {
                        foreach ($hooks[$hook] as $key => $data) {
                            $args = $data['args'] ?? [];
                            // Args: [$order_id, $event_id]
                            if (isset($args[1]) && intval($args[1]) === $event_id) {
                                wp_unschedule_event($timestamp, $hook, $args);
                                $cron_cleared++;
                            }
                        }
                    }
                }
            }
        }
        $counts['cron_jobs'] = $cron_cleared;

        // ── 4. Per-Event Transients ──
        delete_transient('tix_sync_log_' . $event_id);
        delete_transient('tix_ai_flag_' . $event_id);
        delete_transient('tix_publish_error_' . $event_id);
        delete_transient('tix_publish_warning_' . $event_id);

        // ── 5. Per-Event Post-Meta (KI-Schutz, etc.) ──
        // WordPress löscht postmeta automatisch bei wp_delete_post(),
        // aber bei wp_trash_post bleiben sie. Explizit aufräumen:
        $ai_meta_keys = [
            '_tix_ai_content_hash', '_tix_ai_checked_at',
            '_tix_ai_flagged', '_tix_ai_flag_reason', '_tix_ai_approved',
        ];
        foreach ($ai_meta_keys as $key) {
            delete_post_meta($event_id, $key);
        }

        // ── 6. Serien-Kinder aufräumen ──
        $series_children = get_post_meta($event_id, '_tix_series_children', true);
        if (is_array($series_children) && !empty($series_children)) {
            $children_deleted = 0;
            foreach ($series_children as $child_id) {
                $child_id = intval($child_id);
                if (!$child_id || get_post_status($child_id) === false) continue;

                // Kind-Event hat eigene Verkäufe? → nur entkoppeln
                $child_orders = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tix_order_items WHERE event_id = %d", $child_id
                ));

                if ($child_orders > 0) {
                    // Nur Serien-Link entfernen, Event bleibt bestehen
                    delete_post_meta($child_id, '_tix_series_parent');
                    delete_post_meta($child_id, '_tix_series_detached');
                } else {
                    // Keine Verkäufe → komplett löschen inkl. aller Daten
                    $child_cats = get_post_meta($child_id, '_tix_ticket_categories', true);
                    if (is_array($child_cats)) {
                        foreach ($child_cats as $cat) {
                            if (!empty($cat['product_id']) && function_exists('wc_get_product')) {
                                $product = wc_get_product(intval($cat['product_id']));
                                if ($product) $product->delete(true);
                            }
                        }
                    }
                    self::purge_event_data($child_id);
                    wp_delete_post($child_id, true);
                    $children_deleted++;
                }
            }
            $counts['series_children'] = $children_deleted;
        }

        // ── 7. Medien-Attachments die NUR zu diesem Event gehören ──
        $thumb_id = get_post_meta($event_id, '_thumbnail_id', true);
        if ($thumb_id) {
            $thumb_id = intval($thumb_id);
            // Nur löschen wenn Attachment exklusiv diesem Event gehört
            if (get_post_field('post_parent', $thumb_id) == $event_id) {
                wp_delete_attachment($thumb_id, true);
            }
        }
        $gallery = get_post_meta($event_id, '_tix_gallery', true);
        if (is_array($gallery)) {
            foreach ($gallery as $att_id) {
                $att_id = intval($att_id);
                if ($att_id && get_post_field('post_parent', $att_id) == $event_id) {
                    wp_delete_attachment($att_id, true);
                }
            }
        }

        unset($purging[$event_id]);
        return $counts;
    }

    /**
     * Verhindere das Löschen/Trashen von WC-Produkten die zu Tixomat gehören
     */
    public static function protect_product($post_id) {
        // Nur WC-Produkte schützen
        if (get_post_type($post_id) !== 'product') return;

        // Gehört zu Tixomat?
        $parent_event_id = get_post_meta($post_id, '_tix_parent_event_id', true);
        if (!$parent_event_id) return;

        // Prüfe ob das Parent-Event noch existiert und aktiv ist
        $parent_status = get_post_status($parent_event_id);
        if ($parent_status === false || $parent_status === 'trash') return;

        // Löschung verhindern
        $product_name = get_the_title($post_id);
        $event_name   = get_the_title($parent_event_id);

        // Fehler-Transient setzen für Admin-Notice
        set_transient('tix_delete_blocked_' . get_current_user_id(), [
            'product' => $product_name,
            'event'   => $event_name,
            'event_id' => $parent_event_id,
        ], 30);

        // Redirect zurück (verhindert Löschung)
        if (wp_doing_ajax()) {
            wp_send_json_error([
                'message' => "Dieses Produkt gehört zum Event \"{$event_name}\" und kann nicht direkt gelöscht werden. Bitte lösche es über das Event."
            ]);
        }

        wp_safe_redirect(admin_url('edit.php?post_type=product&tix_blocked=1'));
        exit;
    }

    /**
     * Admin-Hinweis bei blockierter Löschung
     */
    public static function protected_notice() {
        if (!isset($_GET['tix_blocked'])) return;

        $data = get_transient('tix_delete_blocked_' . get_current_user_id());
        if (!$data) return;
        delete_transient('tix_delete_blocked_' . get_current_user_id());

        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>Tixomat:</strong> Das Produkt „%s" gehört zum Event „<a href="%s">%s</a>" und kann nicht direkt gelöscht werden. Lösche das Produkt über den Event-Editor oder lösche zuerst das Event.</p></div>',
            esc_html($data['product']),
            esc_url(get_edit_post_link($data['event_id'])),
            esc_html($data['event'])
        );
    }

    /**
     * Admin-Action: Orphan-Cleanup mit Nonce-Check + Redirect
     */
    public static function cleanup_orphans() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_cleanup_orphans');

        $result = self::run_cleanup();

        set_transient('tix_orphan_cleanup_result_' . get_current_user_id(), $result, 30);

        wp_safe_redirect(admin_url('edit.php?post_type=event&tix_cleaned=1'));
        exit;
    }

    /**
     * Kompletter Orphan-Cleanup: Verwaiste Event-Kinder, WC-Produkte, TC-Events, API-Keys.
     * "Verwaist" = Parent-Event existiert nicht mehr oder ist im Papierkorb.
     * Kann direkt aufgerufen werden (z.B. nach Force-Delete).
     *
     * @return array Anzahl gelöschter Einträge pro Typ
     */
    public static function run_cleanup() {
        $deleted_events   = 0;
        $deleted_products = 0;

        // ALLE Hooks deaktivieren
        remove_action('wp_trash_post',      [__CLASS__, 'delete_generated']);
        remove_action('before_delete_post',  [__CLASS__, 'delete_generated']);
        remove_action('wp_trash_post',      [__CLASS__, 'protect_product']);
        remove_action('before_delete_post',  [__CLASS__, 'protect_product']);
        remove_action('wp_trash_post',      ['TIX_Series', 'on_trash']);
        remove_action('before_delete_post', ['TIX_Series', 'on_trash']);
        remove_action('save_post_event',    ['TIX_Metabox', 'save'], 10);
        remove_action('save_post_event',    ['TIX_Sync', 'sync'], 20);
        remove_action('save_post_event',    ['TIX_Series', 'on_save'], 25);

        // ── 1. Verwaiste Event-Kinder (Serien-Kinder deren Master fehlt) ──
        $orphan_events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_tix_series_parent',
            'fields'         => 'ids',
        ]);

        foreach ($orphan_events as $event_id) {
            $parent_id = get_post_meta($event_id, '_tix_series_parent', true);
            if (!$parent_id) continue;
            $parent_status = get_post_status(intval($parent_id));
            if ($parent_status === false || $parent_status === 'trash') {
                // Erst Produkte/TC-Events dieses Events löschen
                $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
                if (is_array($cats)) {
                    foreach ($cats as $cat) {
                        if (!empty($cat['product_id']) && function_exists('wc_get_product')) {
                            $product = wc_get_product(intval($cat['product_id']));
                            if ($product) {
                                $product->delete(true);
                                $deleted_products++;
                            }
                        }
                    }
                }
                // Custom Tables + verknüpfte CPTs aufräumen
                self::purge_event_data($event_id);
                // Event selbst löschen
                wp_delete_post($event_id, true);
                $deleted_events++;
            }
        }

        // ── 2. Verwaiste WC-Produkte (Parent-Event fehlt) ──
        $products = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_tix_parent_event_id',
            'fields'         => 'ids',
        ]);

        foreach ($products as $product_id) {
            $parent_id = get_post_meta($product_id, '_tix_parent_event_id', true);
            if (!$parent_id) continue;
            $parent_status = get_post_status(intval($parent_id));
            if ($parent_status === false || $parent_status === 'trash') {
                if (function_exists('wc_get_product')) {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $product->delete(true);
                        $deleted_products++;
                    }
                }
            }
        }

        // ── 3. Verwaiste TIX-Tickets (Event fehlt) ──
        $deleted_tix_tickets = 0;
        if (post_type_exists('tix_ticket')) {
            $tix_tickets = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'meta_key'       => '_tix_ticket_event_id',
                'fields'         => 'ids',
            ]);

            foreach ($tix_tickets as $et_id) {
                $event_id = get_post_meta($et_id, '_tix_ticket_event_id', true);
                if (!$event_id) continue;
                $event_status = get_post_status(intval($event_id));
                if ($event_status === false || $event_status === 'trash') {
                    wp_delete_post($et_id, true);
                    $deleted_tix_tickets++;
                }
            }
        }

        // Hooks wieder aktivieren
        add_action('wp_trash_post',      [__CLASS__, 'delete_generated']);
        add_action('before_delete_post',  [__CLASS__, 'delete_generated']);
        add_action('wp_trash_post',      [__CLASS__, 'protect_product']);
        add_action('before_delete_post',  [__CLASS__, 'protect_product']);
        add_action('wp_trash_post',      ['TIX_Series', 'on_trash']);
        add_action('before_delete_post', ['TIX_Series', 'on_trash']);
        add_action('save_post_event',    ['TIX_Metabox', 'save'], 10, 2);
        add_action('save_post_event',    ['TIX_Sync', 'sync'], 20, 2);
        add_action('save_post_event',    ['TIX_Series', 'on_save'], 25, 2);

        return [
            'events'     => $deleted_events,
            'products'   => $deleted_products,
            'tix_tickets' => $deleted_tix_tickets,
        ];
    }

    /**
     * Admin-Notice nach Orphan-Cleanup
     */
    public static function orphan_cleanup_notice() {
        if (!isset($_GET['tix_cleaned'])) return;

        $data = get_transient('tix_orphan_cleanup_result_' . get_current_user_id());
        if (!$data) return;
        delete_transient('tix_orphan_cleanup_result_' . get_current_user_id());

        $parts = [];
        if (($data['events'] ?? 0) > 0)   $parts[] = intval($data['events']) . ' Event-Kinder';
        if (($data['products'] ?? 0) > 0)  $parts[] = intval($data['products']) . ' WC-Produkte';
        if (($data['tix_tickets'] ?? 0) > 0) $parts[] = intval($data['tix_tickets']) . ' TIX-Tickets';

        if (empty($parts)) {
            $msg = 'Keine verwaisten Einträge gefunden.';
        } else {
            $msg = 'Orphan-Cleanup abgeschlossen. Gelöscht: ' . implode(', ', $parts) . '.';
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p><strong>Tixomat:</strong> %s</p></div>',
            esc_html($msg)
        );
    }
}
