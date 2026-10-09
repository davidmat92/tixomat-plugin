<?php
/**
 * Wiederkehrende Events (fortlaufend, 1.38.367).
 *
 * Ein Event („Serie“, Master) mit `_tix_recurrence` = weekly|biweekly bekommt
 * seine nächsten Termine als eigene `event`-Posts – immer bis 8 Wochen im Voraus
 * (täglicher Cron + direkt nach dem Speichern des Masters). Wochentag = Wochentag
 * des Startdatums. Ende optional per `_tix_recurrence_until` (Datum) oder
 * `_tix_recurrence_count` (Anzahl Termine inkl. Master, höchstens 26).
 *
 * Termine sind Kopien des Masters (Titel, Inhalt, Bild, Sparte, Veranstalter, Ort,
 * alle `_tix_*`-Metas außer Statistik/Verkauf/Check-in/Bestell-/Sync-Daten),
 * Datum verschoben, Ticket-Kategorien mit Bestand = Kontingent und ohne
 * WooCommerce-Produkt. Verknüpfung: `_tix_recurrence_parent` (Master-ID) +
 * `_tix_recurrence_date` (Termin). Änderungen am Master erreichen künftige
 * Termine ohne verkaufte Tickets. Wird die Wiederholung abgeschaltet oder der
 * Master gelöscht, wandern künftige Termine ohne Verkäufe in den Papierkorb.
 *
 * Unabhängig von den klassischen Serienterminen (`TIX_Series`, `_tix_series_*`,
 * legt alle Termine auf einmal an); beide lassen sich nicht kombinieren.
 */
if (!defined('ABSPATH')) exit;

class TIX_Recurrence {

    const CRON        = 'tix_recurrence_daily';
    const WINDOW_DAYS = 56;   // 8 Wochen im Voraus
    const MAX_COUNT   = 26;
    const MODES       = ['none', 'weekly', 'biweekly'];

    const META_MODE    = '_tix_recurrence';
    const META_UNTIL   = '_tix_recurrence_until';
    const META_COUNT   = '_tix_recurrence_count';
    const META_PARENT  = '_tix_recurrence_parent';
    const META_DATE    = '_tix_recurrence_date';
    const META_HASH    = '_tix_recurrence_hash';
    const META_AUTO    = '_tix_recurrence_auto_trashed';
    const META_SKIP    = '_tix_recurrence_skip';

    /** Meta-Präfixe, die nie in einen Termin kopiert werden. */
    const SKIP_PREFIXES = [
        '_tix_recurrence', '_tix_series_', '_tix_date_', '_tix_time_', '_tix_doors_',
        '_tix_sold_', '_tix_syndicate_', '_tix_syndicated', '_tix_source_', '_tix_ai_',
        '_tix_feedback_', '_tix_last_sync_', '_tix_promoted', '_tix_day_alert', '_tix_api_key',
        '_tix_calendar_', '_tix_is_past', '_tix_notif_', '_tix_archived',
    ];

    /** Einzelne Metas, die nie kopiert werden (Statistik, Verkauf, Check-in, Bestellung, Sync). */
    const SKIP_KEYS = [
        '_tix_status', '_tix_status_label', '_tix_countdown_target', '_tix_product_ids',
        '_tix_tc_event_id', '_tix_synced_at', '_tix_ticket_count', '_tix_offline_count',
        '_tix_event_status', '_tix_low_stock_notified', '_tix_notified', '_tix_settled_at',
        '_tix_guest_list', '_tix_guestlist', '_tix_guests', '_tix_publish_held',
        '_tix_autosave_time', '_tix_special_product_id', '_tix_presale_end_computed',
        '_tix_raffle_status', '_tix_raffle_winners', '_tix_raffle_drawn_at',
        '_tix_seatmap_data', '_tix_system_giftcard_event', '_tix_checkin_count',
    ];

    /** Datumswerte, die mit dem Termin verschoben werden. */
    const SHIFT_KEYS = ['_tix_presale_start', '_tix_presale_end', '_tix_raffle_end_date'];

    private static $running = false;

    public static function init() {
        add_action('save_post_event', [__CLASS__, 'save_settings_from_form'], 9, 2);
        add_action('save_post_event', [__CLASS__, 'on_save'], 30, 2);
        add_action('wp_trash_post', [__CLASS__, 'on_trash']);
        add_action('before_delete_post', [__CLASS__, 'on_delete'], 5);
        add_action(self::CRON, [__CLASS__, 'cron']);
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 900, 'daily', self::CRON);
        }
        add_filter('display_post_states', [__CLASS__, 'post_states'], 10, 2);
    }

    // ──────────────────────────────────────────
    //  Lesen
    // ──────────────────────────────────────────

    /**
     * Von einer Partnerseite übernommenes Event (Syndication): Wiederholung macht dort
     * die Quelle – ihre Termine kommen einzeln an. Hier nie eigene Termine anlegen,
     * und Serien-Metas der Quelle (fremde Post-IDs) nie auswerten.
     */
    public static function is_foreign($id) {
        return get_post_meta($id, '_tix_syndicated', true) === '1';
    }

    public static function mode($id) {
        if (self::is_foreign($id)) return 'none';
        $m = (string) get_post_meta($id, self::META_MODE, true);
        return in_array($m, ['weekly', 'biweekly'], true) ? $m : 'none';
    }

    public static function parent_of($id) {
        if (self::is_foreign($id)) return 0;
        return intval(get_post_meta($id, self::META_PARENT, true));
    }

    /** Für das App-Payload: Master oder Termin (auch klassische Serientermine). */
    public static function info($id) {
        $parent = self::parent_of($id);
        if (!$parent) $parent = intval(get_post_meta($id, '_tix_series_parent', true));
        $recurring = $parent > 0
            || self::mode($id) !== 'none'
            || get_post_meta($id, '_tix_series_enabled', true) === '1';
        return ['recurring' => (bool) $recurring, 'parent' => $parent];
    }

    /** Hat der Termin verkaufte (nicht stornierte) Tickets? */
    public static function has_sales($id) {
        global $wpdb;
        $n = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_tix_ticket_event_id' AND ev.meta_value = %s
             LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_tix_ticket_status'
             WHERE p.post_type = 'tix_ticket' AND p.post_status IN ('publish', 'private')
               AND (st.meta_value IS NULL OR st.meta_value <> 'cancelled')",
            (string) intval($id)
        ));
        if ($n > 0) return true;
        // Bestellungen ohne Tickets (Zahlung offen): die Kasse hat den Bestand schon abgezogen
        $oi = $wpdb->prefix . 'tix_order_items';
        $ot = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $oi)) === $oi) {
            $open = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$oi} i INNER JOIN {$ot} o ON o.id = i.order_id
                 WHERE i.event_id = %d AND o.status NOT IN ('cancelled', 'failed', 'refunded', 'trash')",
                intval($id)
            ));
            if ($open > 0) return true;
        }
        // Bestand unter Kontingent = es wurde verkauft bzw. reserviert
        $cats = get_post_meta($id, '_tix_ticket_categories', true);
        foreach ((array) $cats as $c) {
            if (!is_array($c) || !isset($c['stock']) || $c['stock'] === '') continue;
            $qty = intval($c['qty'] ?? ($c['quantity'] ?? 0));
            if ($qty > 0 && intval($c['stock']) < $qty) return true;
        }
        if (function_exists('wc_get_product')) {
            $cats = get_post_meta($id, '_tix_ticket_categories', true);
            foreach ((array) $cats as $c) {
                $pid = is_array($c) ? intval($c['product_id'] ?? 0) : 0;
                if (!$pid) continue;
                $product = wc_get_product($pid);
                if ($product && $product->get_total_sales() > 0) return true;
            }
        }
        return false;
    }

    /** Termine eines Masters: [date => post_id] (ohne automatisch weggeräumte). */
    public static function children($master_id) {
        $ids = get_posts([
            'post_type'        => 'event',
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'future', 'trash'],
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'meta_key'         => self::META_PARENT,
            'meta_value'       => (string) intval($master_id),
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        $out = [];
        foreach ($ids as $cid) {
            // Übernommene Events tragen die Master-ID der Partnerseite (fremde ID)
            if (self::is_foreign($cid)) continue;
            if (get_post_status($cid) === 'trash' && get_post_meta($cid, self::META_AUTO, true) === '1') continue;
            $d = (string) get_post_meta($cid, self::META_DATE, true);
            if ($d === '') $d = (string) get_post_meta($cid, '_tix_date_start', true);
            if ($d !== '' && !isset($out[$d])) $out[$d] = intval($cid);
        }
        ksort($out);
        return $out;
    }

    /** Fällige Termine des Masters: [date => Versatz in Tagen]. */
    public static function occurrences($master_id) {
        $mode = self::mode($master_id);
        if ($mode === 'none') return [];
        $ds = (string) get_post_meta($master_id, '_tix_date_start', true);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ds)) return [];
        $step  = $mode === 'biweekly' ? 14 : 7;
        $until = (string) get_post_meta($master_id, self::META_UNTIL, true);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) $until = '';
        $count = max(0, min(self::MAX_COUNT, intval(get_post_meta($master_id, self::META_COUNT, true))));
        $today   = current_time('Y-m-d');
        $horizon = self::shift_date($today, self::WINDOW_DAYS);
        $out = [];
        for ($k = 1; $k <= 2000; $k++) {
            if ($count > 0 && $k >= $count) break; // Anzahl inkl. Master
            $date = self::shift_date($ds, $k * $step);
            if ($date > $horizon) break;
            if ($until !== '' && $date > $until) break;
            if ($date < $today) continue;
            $out[$date] = $k * $step;
        }
        return $out;
    }

    // ──────────────────────────────────────────
    //  Einstellungen speichern
    // ──────────────────────────────────────────

    /** Admin-Metabox (Tab „Serientermine“): eigene Felder, gleiche Nonce wie das Event-Formular. */
    public static function save_settings_from_form($post_id, $post) {
        if (empty($_POST['tix_recurrence_form'])) return;
        if (!isset($_POST['tix_nonce']) || !wp_verify_nonce($_POST['tix_nonce'], 'tix_save_event')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $mode = sanitize_key(wp_unslash($_POST['tix_recurrence'] ?? 'none'));
        // Klassische Serie im selben Formular eingeschaltet → keine Wiederholung
        if (!empty($_POST['tix_series_enabled'])) $mode = 'none';
        self::save_settings(
            $post_id,
            $mode,
            sanitize_text_field(wp_unslash($_POST['tix_recurrence_until'] ?? '')),
            intval($_POST['tix_recurrence_count'] ?? 0)
        );
    }

    /** Einstellungen setzen (Metabox, Veranstalter-Dashboard). */
    public static function save_settings($post_id, $mode, $until = '', $count = 0) {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'none';
        // Termine und klassische Serien können keine Wiederholung haben
        if (self::parent_of($post_id) || get_post_meta($post_id, '_tix_series_parent', true)
            || get_post_meta($post_id, '_tix_series_enabled', true) === '1') {
            $mode = 'none';
        }
        $until = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $until) ? (string) $until : '';
        $count = max(0, min(self::MAX_COUNT, intval($count)));
        if ($mode === 'none' && !metadata_exists('post', $post_id, self::META_MODE)) return;
        update_post_meta($post_id, self::META_MODE, $mode);
        update_post_meta($post_id, self::META_UNTIL, $mode === 'none' ? '' : $until);
        update_post_meta($post_id, self::META_COUNT, $mode === 'none' ? 0 : $count);
    }

    // ──────────────────────────────────────────
    //  Hooks
    // ──────────────────────────────────────────

    public static function on_save($post_id, $post) {
        if (self::$running) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!$post || $post->post_type !== 'event') return;
        if (in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) return;
        if (self::parent_of($post_id)) return;
        if (!metadata_exists('post', $post_id, self::META_MODE)) return;
        self::sync($post_id);
    }

    public static function on_trash($post_id) {
        if (self::$running || get_post_type($post_id) !== 'event') return;
        if (self::parent_of($post_id)) return;
        if (!metadata_exists('post', $post_id, self::META_MODE)) return;
        self::retire_future($post_id);
    }

    public static function on_delete($post_id) {
        if (self::$running || get_post_type($post_id) !== 'event') return;
        $parent = self::parent_of($post_id);
        if ($parent) {
            // Endgültig gelöschter Termin: nicht neu anlegen
            if (get_post_meta($post_id, self::META_AUTO, true) === '1') return;
            $date = (string) get_post_meta($post_id, self::META_DATE, true);
            if ($date === '') return;
            $skip = get_post_meta($parent, self::META_SKIP, true);
            $skip = is_array($skip) ? $skip : [];
            if (!in_array($date, $skip, true)) {
                $skip[] = $date;
                $today = current_time('Y-m-d');
                $skip = array_values(array_filter($skip, function ($d) use ($today) { return $d >= $today; }));
                update_post_meta($parent, self::META_SKIP, $skip);
            }
            return;
        }
        if (metadata_exists('post', $post_id, self::META_MODE)) self::retire_future($post_id);
    }

    /** Täglich: fehlende Termine anlegen, geänderte Master übernehmen. */
    public static function cron() {
        $ids = get_posts([
            'post_type'        => 'event',
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'meta_query'       => [['key' => self::META_MODE, 'value' => ['weekly', 'biweekly'], 'compare' => 'IN']],
            'suppress_filters' => true,
        ]);
        $created = 0;
        foreach ($ids as $id) {
            if (self::parent_of($id)) continue;
            $r = self::sync($id);
            $created += $r['created'];
        }
        return $created;
    }

    public static function post_states($states, $post) {
        if (!$post || $post->post_type !== 'event') return $states;
        if (self::mode($post->ID) !== 'none') {
            $states['tix_recurrence'] = 'Serie';
        } elseif ($p = self::parent_of($post->ID)) {
            $states['tix_recurrence'] = 'Termin aus Serie #' . $p;
        }
        return $states;
    }

    // ──────────────────────────────────────────
    //  Abgleich
    // ──────────────────────────────────────────

    /**
     * Termine eines Masters abgleichen. Rückgabe: Zähler {created, updated, trashed}.
     */
    public static function sync($master_id) {
        $res = ['created' => 0, 'updated' => 0, 'trashed' => 0];
        $master = get_post($master_id);
        if (!$master || $master->post_type !== 'event' || self::parent_of($master_id)) return $res;
        if (in_array($master->post_status, ['trash', 'auto-draft'], true)) return $res;
        if (self::mode($master_id) === 'none') {
            $res['trashed'] = self::retire_future($master_id);
            return $res;
        }
        if (self::$running) return $res;

        self::$running = true;
        $post_backup = $_POST;
        $_POST = []; // Formular-Daten des Masters dürfen keine Termin-Speicherung auslösen
        try {
            $dates    = self::occurrences($master_id);
            $existing = self::children($master_id);
            $skip     = get_post_meta($master_id, self::META_SKIP, true);
            $skip     = is_array($skip) ? $skip : [];
            $sig      = self::signature($master_id);
            $today    = current_time('Y-m-d');

            // Künftige Termine, die nicht mehr in die Serie passen (Wochentag/Ende geändert)
            foreach ($existing as $date => $cid) {
                if ($date < $today || isset($dates[$date])) continue;
                if (get_post_status($cid) === 'trash') continue;
                if (self::occurrence_offset($master_id, $date) !== null) continue; // jenseits des Fensters, aber gültig
                if (self::has_sales($cid)) continue;
                self::trash_child($cid);
                $res['trashed']++;
            }

            foreach ($dates as $date => $offset) {
                if (in_array($date, $skip, true)) continue;
                if (isset($existing[$date])) {
                    $cid = $existing[$date];
                    if (get_post_status($cid) === 'trash') continue; // von Hand gelöscht
                    if (get_post_meta($cid, self::META_HASH, true) === $sig) continue;
                    if (self::has_sales($cid)) continue;
                    self::write_child($master_id, $date, $offset, $sig, $cid);
                    $res['updated']++;
                } else {
                    if (self::write_child($master_id, $date, $offset, $sig, 0)) $res['created']++;
                }
            }
            // Künftige Termine jenseits des 8-Wochen-Fensters (z. B. früher angelegt) ebenfalls aktuell halten
            foreach ($existing as $date => $cid) {
                if ($date < $today || isset($dates[$date]) || get_post_status($cid) === 'trash') continue;
                $offset = self::occurrence_offset($master_id, $date);
                if ($offset === null || get_post_meta($cid, self::META_HASH, true) === $sig || self::has_sales($cid)) continue;
                self::write_child($master_id, $date, $offset, $sig, $cid);
                $res['updated']++;
            }
        } finally {
            $_POST = $post_backup;
            self::$running = false;
        }
        if (($res['created'] || $res['updated'] || $res['trashed']) && class_exists('TIX_Public_Events')) {
            TIX_Public_Events::flush();
        }
        return $res;
    }

    /** Versatz eines Datums zur Serie (ohne Fenster-Grenze) oder null. */
    private static function occurrence_offset($master_id, $date) {
        $mode = self::mode($master_id);
        if ($mode === 'none') return null;
        $ds = (string) get_post_meta($master_id, '_tix_date_start', true);
        $diff = intval(round((strtotime($date . ' UTC') - strtotime($ds . ' UTC')) / DAY_IN_SECONDS));
        $step = $mode === 'biweekly' ? 14 : 7;
        if ($diff <= 0 || $diff % $step !== 0) return null;
        $until = (string) get_post_meta($master_id, self::META_UNTIL, true);
        if ($until !== '' && $date > $until) return null;
        $count = intval(get_post_meta($master_id, self::META_COUNT, true));
        if ($count > 0 && ($diff / $step) >= $count) return null;
        return $diff;
    }

    /** Künftige Termine ohne Verkäufe in den Papierkorb (Wiederholung aus / Master gelöscht). */
    public static function retire_future($master_id) {
        $n = 0;
        $today = current_time('Y-m-d');
        $was = self::$running;
        self::$running = true;
        try {
            foreach (self::children($master_id) as $date => $cid) {
                if ($date < $today || get_post_status($cid) === 'trash') continue;
                if (self::has_sales($cid)) continue;
                self::trash_child($cid);
                $n++;
            }
        } finally {
            self::$running = $was;
        }
        if ($n && class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return $n;
    }

    private static function trash_child($cid) {
        update_post_meta($cid, self::META_AUTO, '1');
        // WooCommerce-Produkte des Termins entfernen (Produkt-Schutz kurz aus)
        if (function_exists('wc_get_product')) {
            $had = class_exists('TIX_Cleanup') && has_action('before_delete_post', ['TIX_Cleanup', 'protect_product']);
            if ($had) {
                remove_action('wp_trash_post', ['TIX_Cleanup', 'protect_product']);
                remove_action('before_delete_post', ['TIX_Cleanup', 'protect_product']);
            }
            foreach ((array) get_post_meta($cid, '_tix_ticket_categories', true) as $c) {
                $pid = is_array($c) ? intval($c['product_id'] ?? 0) : 0;
                if ($pid && ($product = wc_get_product($pid))) $product->delete(true);
            }
            if ($had) {
                add_action('wp_trash_post', ['TIX_Cleanup', 'protect_product']);
                add_action('before_delete_post', ['TIX_Cleanup', 'protect_product']);
            }
        }
        wp_trash_post($cid);
    }

    // ──────────────────────────────────────────
    //  Kopieren
    // ──────────────────────────────────────────

    public static function copyable($key) {
        if (strpos($key, '_tix_') !== 0) return false;
        if (in_array($key, self::SKIP_KEYS, true)) return false;
        foreach (self::SKIP_PREFIXES as $p) {
            if (strpos($key, $p) === 0) return false;
        }
        return true;
    }

    private static function shift_date($date, $days) {
        return gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC') + intval($days) * DAY_IN_SECONDS);
    }

    /** „2026-10-16…“ um n Tage verschieben (Rest wie Uhrzeit bleibt). */
    private static function shift_date_string($s, $days) {
        if (!is_string($s) || !preg_match('/^(\d{4}-\d{2}-\d{2})(.*)$/s', $s, $m)) return $s;
        return self::shift_date($m[1], $days) . $m[2];
    }

    /** Kopierbare Metas des Masters (unverschoben). */
    private static function master_meta($master_id) {
        $out = [];
        foreach (get_post_meta($master_id) as $key => $values) {
            if (!self::copyable($key) || !isset($values[0])) continue;
            $out[$key] = maybe_unserialize($values[0]);
        }
        ksort($out);
        return $out;
    }

    /** Ticket-Kategorien für einen Termin: Bestand = Kontingent, kein Produkt, Phasen verschoben. */
    private static function child_categories($cats, $offset) {
        if (!is_array($cats)) return $cats;
        $out = [];
        foreach ($cats as $i => $c) {
            if (!is_array($c)) { $out[$i] = $c; continue; }
            $c['product_id']  = 0;
            $c['sku']         = '';
            $c['tc_event_id'] = 0;
            unset($c['sold'], $c['sold_count']);
            $qty = intval($c['qty'] ?? ($c['quantity'] ?? 0));
            if ($qty > 0) $c['stock'] = $qty; else unset($c['stock']);
            if (!empty($c['phases']) && is_array($c['phases'])) {
                foreach ($c['phases'] as $pi => $ph) {
                    if (is_array($ph) && !empty($ph['until'])) $c['phases'][$pi]['until'] = self::shift_date_string((string) $ph['until'], $offset);
                }
            }
            $out[$i] = $c;
        }
        return $out;
    }

    private static function child_value($key, $val, $offset) {
        if ($key === '_tix_ticket_categories') return self::child_categories($val, $offset);
        if (in_array($key, self::SHIFT_KEYS, true)) return self::shift_date_string($val, $offset);
        if ($key === '_tix_timetable' && is_array($val)) {
            $tt = [];
            foreach ($val as $day => $slots) {
                $nk = (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) ? self::shift_date($day, $offset) : $day;
                $tt[$nk] = $slots;
            }
            return $tt;
        }
        return $val;
    }

    /** Fingerabdruck des Masters: ändert er sich, werden Termine ohne Verkäufe nachgezogen. */
    public static function signature($master_id) {
        $m = get_post($master_id);
        $meta = self::master_meta($master_id);
        if (isset($meta['_tix_ticket_categories']) && is_array($meta['_tix_ticket_categories'])) {
            foreach ($meta['_tix_ticket_categories'] as $i => $c) {
                if (is_array($c)) unset($meta['_tix_ticket_categories'][$i]['stock'], $meta['_tix_ticket_categories'][$i]['product_id'], $meta['_tix_ticket_categories'][$i]['sku']);
            }
        }
        // Abgeleitete Anzeige-/Preis-Metas schreibt die Nachbearbeitung neu – nicht vergleichen
        foreach (array_keys($meta) as $k) {
            if (strpos($k, '_tix_price_') === 0) unset($meta[$k]);
        }
        $terms = [];
        foreach (get_object_taxonomies('event') as $tax) {
            $t = wp_get_object_terms($master_id, $tax, ['fields' => 'ids']);
            $terms[$tax] = is_wp_error($t) ? [] : array_map('intval', $t);
        }
        return md5(serialize([
            $m ? $m->post_title : '', $m ? $m->post_content : '', $m ? $m->post_excerpt : '',
            self::target_status($master_id), (int) get_post_thumbnail_id($master_id), $terms, $meta,
            (string) get_post_meta($master_id, '_tix_date_start', true), (string) get_post_meta($master_id, '_tix_date_end', true),
            (string) get_post_meta($master_id, '_tix_time_start', true), (string) get_post_meta($master_id, '_tix_time_end', true),
            (string) get_post_meta($master_id, '_tix_time_doors', true),
        ]));
    }

    /** Status der Termine: wie der Master (veröffentlicht nur, wenn der Master veröffentlicht ist). */
    private static function target_status($master_id) {
        $s = get_post_status($master_id);
        return in_array($s, ['publish', 'private', 'pending'], true) ? $s : 'draft';
    }

    /**
     * Termin anlegen ($cid = 0) oder vom Master aktualisieren. Gibt die Termin-ID zurück.
     */
    private static function write_child($master_id, $date, $offset, $sig, $cid) {
        $m = get_post($master_id);
        if (!$m) return 0;
        $meta = [];
        foreach (self::master_meta($master_id) as $k => $v) $meta[$k] = self::child_value($k, $v, $offset);
        // Termin-Daten (Start/Ende/Einlass; Ende am Folgetag bleibt erhalten)
        $de = (string) get_post_meta($master_id, '_tix_date_end', true);
        $meta['_tix_date_start'] = $date;
        $meta['_tix_date_end']   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) ? self::shift_date($de, $offset) : $de;
        foreach (['_tix_time_start', '_tix_time_end', '_tix_time_doors'] as $k) {
            $meta[$k] = (string) get_post_meta($master_id, $k, true);
        }
        $meta[self::META_PARENT] = intval($master_id);
        $meta[self::META_DATE]   = $date;
        $meta[self::META_HASH]   = $sig;
        $target = self::target_status($master_id);

        if (!$cid) {
            // wp_insert_post/update_post_meta erwarten „geslashte“ Werte
            $cid = wp_insert_post(wp_slash([
                'post_type'    => 'event',
                'post_title'   => $m->post_title,
                'post_name'    => sanitize_title($m->post_title . '-' . $date),
                'post_content' => $m->post_content,
                'post_excerpt' => $m->post_excerpt,
                'post_status'  => 'draft',
                'post_author'  => $m->post_author,
                'meta_input'   => $meta,
            ]), true);
            if (is_wp_error($cid) || !$cid) return 0;
            $is_new = true;
        } else {
            $is_new = false;
            // Kopierte Felder, die der Master nicht mehr hat, entfernen
            foreach (array_keys(get_post_meta($cid)) as $k) {
                if (self::copyable($k) && !array_key_exists($k, $meta)) delete_post_meta($cid, $k);
            }
            foreach ($meta as $k => $v) update_post_meta($cid, $k, wp_slash($v));
            $cur = get_post($cid);
            if ($cur && ($cur->post_title !== $m->post_title || $cur->post_content !== $m->post_content || $cur->post_excerpt !== $m->post_excerpt)) {
                wp_update_post(wp_slash(['ID' => $cid, 'post_title' => $m->post_title, 'post_content' => $m->post_content, 'post_excerpt' => $m->post_excerpt]));
            }
        }

        // Sparte & weitere Taxonomien, Beitragsbild
        foreach (get_object_taxonomies('event') as $tax) {
            $t = wp_get_object_terms($master_id, $tax, ['fields' => 'ids']);
            if (!is_wp_error($t)) wp_set_object_terms($cid, array_map('intval', $t), $tax);
        }
        // Beitragsbild 1:1 (auch wenn die Datei extern liegt – set_post_thumbnail prüft die Bildgröße)
        $thumb = (int) get_post_thumbnail_id($master_id);
        if ($thumb) update_post_meta($cid, '_thumbnail_id', $thumb); else delete_post_meta($cid, '_thumbnail_id');

        // Status zuletzt: Veröffentlichen löst die üblichen Hooks aus (Tages-Erinnerungen, Verteilung …)
        if (get_post_status($cid) !== $target) {
            wp_update_post(['ID' => $cid, 'post_status' => $target]);
        }

        // Nachbearbeitung wie beim normalen Speichern (Produkte/Anzeige-Metas, Status)
        clean_post_cache($cid);
        if (class_exists('TIX_Sync') && ($p = get_post($cid))) TIX_Sync::sync($cid, $p);
        if (class_exists('TIX_App_Events') && method_exists('TIX_App_Events', 'resolve_status')) {
            TIX_App_Events::resolve_status($cid);
        }
        do_action('tix_recurrence_child_written', $cid, $master_id, $date, $is_new);
        return intval($cid);
    }

    // ──────────────────────────────────────────
    //  Admin-Formular (Tab „Serientermine“)
    // ──────────────────────────────────────────

    /** true = Karte zeigt einen Termin/aktive Wiederholung; die klassische Serie wird dann ausgeblendet. */
    public static function render_admin_card($post) {
        $parent = self::parent_of($post->ID);
        if ($parent) {
            $master = get_post($parent);
            $date = (string) get_post_meta($post->ID, self::META_DATE, true);
            ?>
            <div class="tix-card" style="margin-bottom:16px;">
                <h4 style="margin:0 0 8px">Termin aus Serie #<?php echo intval($parent); ?></h4>
                <p style="margin:0 0 8px;">Dieser Termin<?php echo $date ? ' (' . esc_html(date_i18n('D, d.m.Y', strtotime($date))) . ')' : ''; ?> wurde automatisch aus der Serie <strong>&bdquo;<?php echo esc_html($master ? $master->post_title : '#' . $parent); ?>&ldquo;</strong> angelegt.
                    &Auml;nderungen am Serien-Event werden &uuml;bernommen, solange f&uuml;r diesen Termin keine Tickets verkauft sind.</p>
                <?php if ($master): ?><p style="margin:0;"><a class="button" href="<?php echo esc_url(get_edit_post_link($parent)); ?>">Serien-Event bearbeiten</a></p><?php endif; ?>
            </div>
            <?php
            return true;
        }
        if (get_post_meta($post->ID, '_tix_series_parent', true) || get_post_meta($post->ID, '_tix_series_enabled', true) === '1') {
            echo '<p class="tix-hint" style="margin:0 0 12px;">Fortlaufende Wiederholung: nicht verf&uuml;gbar, solange klassische Serientermine aktiv sind.</p>';
            return false;
        }
        $mode  = self::mode($post->ID);
        $until = (string) get_post_meta($post->ID, self::META_UNTIL, true);
        $count = intval(get_post_meta($post->ID, self::META_COUNT, true));
        $children = $mode !== 'none' ? self::children($post->ID) : [];
        $today = current_time('Y-m-d');
        ?>
        <div class="tix-card" style="margin-bottom:16px;">
            <h4 style="margin:0 0 4px">Wiederholung</h4>
            <p class="tix-hint" style="margin:0 0 12px;">Legt die n&auml;chsten Termine automatisch als eigene Events an &ndash; immer bis 8 Wochen im Voraus. Wochentag = Wochentag des Startdatums.</p>
            <input type="hidden" name="tix_recurrence_form" value="1">
            <p style="margin:0 0 10px;">
                <select name="tix_recurrence" id="tix-recurrence-mode">
                    <option value="none" <?php selected($mode, 'none'); ?>>Keine Wiederholung</option>
                    <option value="weekly" <?php selected($mode, 'weekly'); ?>>W&ouml;chentlich</option>
                    <option value="biweekly" <?php selected($mode, 'biweekly'); ?>>Alle 2 Wochen</option>
                </select>
            </p>
            <div id="tix-recurrence-end" style="<?php echo $mode === 'none' ? 'display:none;' : ''; ?>">
                <p style="margin:0 0 8px;"><label>Endet am (optional): <input type="date" name="tix_recurrence_until" value="<?php echo esc_attr($until); ?>" style="width:170px;"></label></p>
                <p style="margin:0 0 8px;"><label>oder nach <input type="number" name="tix_recurrence_count" value="<?php echo $count ?: ''; ?>" min="0" max="<?php echo self::MAX_COUNT; ?>" style="width:70px;" placeholder="&infin;"> Terminen (inkl. diesem, max. <?php echo self::MAX_COUNT; ?>)</label></p>
                <p class="tix-hint" style="margin:0;">&Auml;nderungen an diesem Event gehen an alle k&uuml;nftigen Termine ohne verkaufte Tickets. Wird die Wiederholung abgeschaltet, wandern k&uuml;nftige Termine ohne Verk&auml;ufe in den Papierkorb.</p>
            </div>
            <?php if ($children): ?>
                <table class="widefat striped" style="margin-top:12px;">
                    <thead><tr><th>Termin</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($children as $d => $cid):
                        if ($d < $today) continue;
                        $st = get_post_status($cid);
                        $labels = ['publish' => 'Veröffentlicht', 'draft' => 'Entwurf', 'pending' => 'Ausstehend', 'private' => 'Privat', 'trash' => 'Papierkorb'];
                        ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('D, d.m.Y', strtotime($d))); ?></td>
                            <td><?php echo esc_html($labels[$st] ?? $st); ?><?php echo self::has_sales($cid) ? ' &middot; Tickets verkauft' : ''; ?></td>
                            <td><?php if ($st !== 'trash'): ?><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($cid)); ?>">Bearbeiten</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var s = document.getElementById('tix-recurrence-mode');
            var e = document.getElementById('tix-recurrence-end');
            var c = document.getElementById('tix-series-toggle');
            if (!s || !e) return;
            function upd() {
                e.style.display = s.value === 'none' ? 'none' : '';
                if (c) { c.disabled = s.value !== 'none'; if (s.value !== 'none') c.checked = false; }
            }
            s.addEventListener('change', upd);
            upd();
        })();
        </script>
        <?php
        return $mode !== 'none';
    }
}
