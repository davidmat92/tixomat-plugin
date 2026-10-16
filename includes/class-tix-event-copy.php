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
                        $qty = intval($cat['qty'] ?? ($cat['quantity'] ?? 0));
                        if ($qty > 0) $cat['stock'] = $qty; else unset($cat['stock']);
                    }
                    unset($cat);
                    update_post_meta($to, $key, $cats);
                }
                continue;
            }
            delete_post_meta($to, $key);
            foreach ($values as $v) add_post_meta($to, $key, maybe_unserialize($v));
        }
    }

    /** Nach dem Kopieren: abgeleitete Felder neu erzeugen (Kalender-Links). */
    public static function after_copy($new_id) {
        if (class_exists('TIX_Calendar') && method_exists('TIX_Calendar', 'save_meta')) TIX_Calendar::save_meta($new_id);
    }
}
