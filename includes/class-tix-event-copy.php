<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Event_Copy – eine Ausnahmeliste für alle Kopierwege eines Events (1.38.457):
 * wp-admin „Duplizieren“, REST POST /events/{id}/duplicate, Veranstalter-Dashboard (AJAX), Serien/Termine (TIX_Recurrence).
 *
 * Nicht kopiert werden Laufzeit-Zustände: Archiv, „vergangen“, Benachrichtigungen bzw. Gesendet-Markierungen, Kalender-Links
 * (werden neu erzeugt), Abrechnung, Verteilung/Syndikat, Gewinnspiel-Status, Zähler, Sync, Serien-Verknüpfungen.
 * (Fund kitchenklub.de 2026-10-16: Kopien alter Events trugen `_tix_archived` und verschwanden aus der Admin-Liste.)
 */
class TIX_Event_Copy {

    const SKIP_PREFIXES = [
        '_tix_archived', '_tix_is_past', '_tix_notif_', '_tix_calendar_', '_tix_recurrence', '_tix_series_',
        '_tix_sold_', '_tix_syndicate_', '_tix_syndicated', '_tix_source_', '_tix_last_sync_', '_tix_feedback_',
        '_tix_ai_', '_tix_day_alert', '_tix_settle', '_tix_org_notified_', '_tix_raffle_winner', '_tix_awareness_feedback',
    ];

    const SKIP_KEYS = [
        '_tix_api_key', '_tix_tc_event_id', '_tix_product_ids', '_tix_synced_at', '_tix_guest_list', '_tix_guestlist', '_tix_guests',
        '_tix_low_stock_notified', '_tix_notified', '_tix_offline_count', '_tix_ticket_count', '_tix_checkin_count', '_tix_publish_held',
        '_tix_raffle_drawn_at', '_tix_raffle_status', '_tix_raffle_winners', '_tix_settled_at', '_tix_awareness_test',
        '_tix_status', '_tix_status_label', '_tix_event_status', '_tix_countdown_target', '_tix_presale_end_computed',
        '_tix_ticket_sponsor_sent_at', '_tix_completed_email_sent', '_tix_special_product_id', '_tix_system_giftcard_event',
        '_tix_autosave_time', '_tix_seatmap_data', '_edit_lock', '_edit_last',
    ];

    /** Darf diese Meta in eine Kopie? (nur `_tix_*`-Felder, ohne Laufzeit-Zustände) */
    public static function copyable($key) {
        $key = (string) $key;
        if (strpos($key, '_tix_') !== 0) return false;
        if (in_array($key, self::SKIP_KEYS, true)) return false;
        foreach (self::SKIP_PREFIXES as $p) if (strpos($key, $p) === 0) return false;
        return true;
    }

    /** Metas von $from nach $to kopieren; Ticket-Kategorien ohne Produkt-Verknüpfung und mit vollem Bestand. */
    public static function copy_meta($from, $to) {
        foreach (get_post_meta($from) as $key => $values) {
            if (!self::copyable($key)) continue;
            if ($key === '_tix_ticket_categories') {
                $cats = maybe_unserialize($values[0]);
                if (is_array($cats)) {
                    foreach ($cats as &$cat) {
                        if (!is_array($cat)) continue;
                        $cat['product_id'] = '';
                        $cat['tc_event_id'] = '';
                        $cat['sku'] = '';
                        unset($cat['sold'], $cat['sold_count']);
                        $qty = intval($cat['qty'] ?? ($cat['quantity'] ?? 0));
                        if ($qty > 0) $cat['stock'] = $qty; else unset($cat['stock']);
                    }
                    unset($cat);
                    update_post_meta($to, $key, $cats);
                }
                continue;
            }
            // eine Zeile je Feld (doppelte Zeilen im Original, z. B. _tix_tickets_enabled aus alten Kopien, nicht weitergeben)
            delete_post_meta($to, $key);
            add_post_meta($to, $key, maybe_unserialize($values[0]), true);
        }
    }

    /** Nach dem Kopieren: abgeleitete Felder neu erzeugen (Kalender-Links). */
    public static function after_copy($new_id) {
        // Künstler (1.38.517): Index neu, Mitteilungen für den neuen Termin
        if (class_exists('TIX_Artists')) TIX_Artists::after_copy($new_id);
        if (class_exists('TIX_Calendar') && method_exists('TIX_Calendar', 'save_meta')) TIX_Calendar::save_meta($new_id);
        // Vorgabe „alle Events auf evendis teilen“ (1.38.465): Duplikate vorbelegt, Verkaufsart wie im Original
        if (class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::default_on() && !TIX_Syndication_Push::is_system_event($new_id)) {
            update_post_meta($new_id, '_tix_syndicate', '1');
            if (!metadata_exists('post', $new_id, '_tix_partner_sales')) update_post_meta($new_id, '_tix_partner_sales', TIX_Syndication_Push::default_partner_sales() ? '1' : '0');
        }
    }

    // ── Selbstheilung 1.38.462 (einmal je Seite) ──

    const HEAL_OPTION = 'tix_heal_status_462';

    public static function init_heal() {
        if (get_option(self::HEAL_OPTION, '') !== '') return;
        add_action('init', [__CLASS__, 'heal_status'], 99);
    }

    /**
     * Events ohne Online-Verkauf (Tickets aus bzw. freier Eintritt) mit automatisch berechnetem „Ausverkauft“ neu berechnen
     * und doppelte _tix_*-Zeilen an Events auf die erste (gültige) Zeile zurückführen. → ['status' => n, 'duplicates' => n]
     */
    public static function heal_status() {
        global $wpdb;
        update_option(self::HEAL_OPTION, gmdate('c'), false);
        $out = ['status' => 0, 'duplicates' => 0];
        // doppelte Zeilen (z. B. _tix_tickets_enabled aus alten Kopien): erste Zeile = Wert, den get_post_meta liefert
        $dups = $wpdb->get_results("SELECT m.post_id, m.meta_key, MIN(m.meta_id) AS keep_id FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'event'
            WHERE m.meta_key LIKE '\\_tix\\_%' GROUP BY m.post_id, m.meta_key HAVING COUNT(*) > 1 LIMIT 5000");
        foreach ((array) $dups as $d) {
            $out['duplicates'] += intval($wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_id <> %d", $d->post_id, $d->meta_key, $d->keep_id)));
            wp_cache_delete(intval($d->post_id), 'post_meta');
        }
        $ids = $wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_tix_status' AND s.meta_value = 'sold_out'
            LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_tix_event_status'
            LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_tix_tickets_enabled'
            WHERE p.post_type = 'event' AND (o.meta_value IS NULL OR o.meta_value = '') AND (t.meta_value IS NULL OR t.meta_value <> '1')");
        foreach ((array) $ids as $id) {
            if (class_exists('TIX_App_Events') && method_exists('TIX_App_Events', 'resolve_status')) TIX_App_Events::resolve_status(intval($id));
            else { update_post_meta($id, '_tix_status', 'available'); update_post_meta($id, '_tix_status_label', 'Verfügbar'); }
            $out['status']++;
        }
        if ($out['status'] && class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        update_option(self::HEAL_OPTION, wp_json_encode(['at' => gmdate('c')] + $out), false);
        return $out;
    }
}
