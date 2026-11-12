<?php
/**
 * Bestand mit Sperre (Phase 0, 1.38.767).
 *
 * Quelle der Wahrheit für begrenzte Ticket-Kategorien ist die eigene Tabelle {prefix}tix_stock
 * (event_id, cat_index, available). Reserviert wird mit einem bedingten UPDATE
 * („available = available - q WHERE available >= q“). Es gibt nie zu viele verkaufte Tickets,
 * auch wenn viele zugleich kaufen. Mehrere Positionen gelten nur ganz oder gar nicht:
 * Fehlt eine, wird das schon Reservierte zurückgebucht.
 *
 * `stock` in `_tix_ticket_categories` bleibt eine Anzeige-Kopie und wird nach jeder Buchung
 * nachgezogen. Schreibt der Editor oder ein anderes Modul die Kategorien, folgt die Tabelle
 * den Postmeta-Hooks:
 * - Nur die Änderung (neu − alt) wird übernommen, damit gleichzeitige Verkäufe nicht verloren gehen.
 * - Hat sich die Reihenfolge oder Anzahl geändert, oder setzt der Editor `absolute`, wird der Wert übernommen.
 *
 * Weg 3 („Die Daten liegen stets bei dir“): Die Regeln stehen als reine Funktionen in `limit_of`
 * und `plan`, die Daten in einer lokalen Tabelle mit schlichtem SQL. Ein künftiger Dienst auf dem
 * Kundenserver nutzt dieselbe Tabelle und dieselben Anweisungen. Siehe DOCUMENTATION.md §171.
 */
if (!defined('ABSPATH')) exit;

class TIX_Stock {

    const DB_VERSION = '1';
    const OPT_DB     = 'tix_stock_db';
    const META       = '_tix_ticket_categories';

    /** Eigene Schreibvorgänge an der Anzeige-Kopie lösen keinen Abgleich aus. */
    private static $writing = 0;
    /** Kategorien vor dem Speichern (für die Änderung neu − alt). */
    private static $before = [];
    /** Editor setzt den Bestand absolut (Kontingent − verkauft), statt eine Änderung zu übergeben. */
    public static $absolute = false;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_stock';
    }

    public static function init() {
        add_filter('update_post_metadata', [__CLASS__, 'before_meta'], 10, 5);
        add_action('added_post_meta',   [__CLASS__, 'after_meta'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'after_meta'], 10, 4);
        add_action('deleted_post_meta', [__CLASS__, 'deleted_meta'], 10, 4);
        add_action('deleted_post',      [__CLASS__, 'deleted_post'], 10, 1);
    }

    // ── Tabelle ──

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table() . " (
            event_id bigint(20) unsigned NOT NULL,
            cat_index int(11) NOT NULL,
            available int(11) NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (event_id,cat_index)
        ) $c;");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    /** Tabelle bereit (legt sie bei Bedarf an – auch ohne Migrations-Lauf, z. B. auf frischen Seiten). */
    public static function ready() {
        static $ok = null;
        if ($ok) return true;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::install();
        return $ok = (get_option(self::OPT_DB) === self::DB_VERSION);
    }

    /** Alle Events einmalig aus der Anzeige-Kopie übernehmen (Migration; vorhandene Zeilen bleiben). */
    public static function backfill() {
        global $wpdb;
        if (!self::ready()) return 0;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META));
        $n = 0;
        foreach ($ids as $id) $n += self::ensure(intval($id));
        return $n;
    }

    // ── Reine Regeln (ohne WordPress) ──

    /** Begrenzter Bestand einer Kategorie oder null (unbegrenzt: fehlt, leer, negativ). */
    public static function limit_of($cat) {
        if (!is_array($cat) || !array_key_exists('stock', $cat)) return null;
        $v = $cat['stock'];
        if ($v === '' || $v === null || is_array($v) || !is_numeric($v)) return null;
        $v = intval($v);
        return $v >= 0 ? $v : null;
    }

    /**
     * Was eine Änderung der Kategorien für die Tabelle bedeutet.
     * Rückgabe: ['set' => [idx => Wert], 'delta' => [idx => [Änderung, neuer Wert]], 'drop' => [idx, …]].
     * Gleiche Schlüssel und Namen: Es wird die Änderung neu − alt übernommen, sonst (oder bei $absolute) der neue Wert.
     */
    public static function plan($old, $new, $absolute = false) {
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        $same = !$absolute && $old && array_keys($old) == array_keys($new);
        if ($same) {
            foreach ($new as $i => $c) {
                if ((string) ($c['name'] ?? '') !== (string) ($old[$i]['name'] ?? '')) { $same = false; break; }
            }
        }
        $out = ['set' => [], 'delta' => [], 'drop' => []];
        foreach ($new as $i => $c) {
            if (!is_numeric($i)) continue;
            $n = self::limit_of($c);
            if ($n === null) { $out['drop'][] = intval($i); continue; }
            $o = $same ? self::limit_of($old[$i]) : null;
            if ($o === null) { $out['set'][intval($i)] = $n; continue; }
            if ($n !== $o) $out['delta'][intval($i)] = [$n - $o, $n];
        }
        // Weggefallene Kategorien
        foreach (array_keys($old) as $i) if (is_numeric($i) && !array_key_exists($i, $new)) $out['drop'][] = intval($i);
        return $out;
    }

    // ── Abgleich mit der Anzeige-Kopie ──

    public static function before_meta($check, $object_id, $meta_key, $meta_value, $prev_value) {
        if ($meta_key === self::META && !self::$writing) {
            wp_cache_delete($object_id, 'post_meta');
            self::$before[intval($object_id)] = get_post_meta($object_id, self::META, true);
        }
        return $check;
    }

    public static function after_meta($meta_id, $object_id, $meta_key, $meta_value) {
        if ($meta_key !== self::META || self::$writing) return;
        $object_id = intval($object_id);
        $old = self::$before[$object_id] ?? null;
        unset(self::$before[$object_id]);
        if (!self::ready()) return;
        self::apply($object_id, self::plan($old, maybe_unserialize($meta_value), self::$absolute));
    }

    public static function deleted_meta($meta_ids, $object_id, $meta_key, $meta_value) {
        if ($meta_key !== self::META || self::$writing) return;
        self::drop_event(intval($object_id));
    }

    public static function deleted_post($post_id) {
        if (get_option(self::OPT_DB) === self::DB_VERSION) self::drop_event(intval($post_id));
    }

    private static function drop_event($event_id) {
        global $wpdb;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) return;
        $wpdb->delete(self::table(), ['event_id' => $event_id], ['%d']);
    }

    /** Plan auf die Tabelle anwenden und die Anzeige-Kopie nachziehen, falls der Stand abweicht. */
    private static function apply($event_id, array $plan) {
        global $wpdb;
        $t = self::table();
        $now = current_time('mysql', true);
        foreach ($plan['drop'] as $i) $wpdb->delete($t, ['event_id' => $event_id, 'cat_index' => intval($i)], ['%d', '%d']);
        foreach ($plan['set'] as $i => $v) {
            $wpdb->query($wpdb->prepare("INSERT INTO $t (event_id, cat_index, available, updated_at) VALUES (%d, %d, %d, %s)
                ON DUPLICATE KEY UPDATE available = VALUES(available), updated_at = VALUES(updated_at)", $event_id, $i, $v, $now));
        }
        $changed = false;
        foreach ($plan['delta'] as $i => [$d, $v]) {
            // Noch keine Zeile → neuer Wert; sonst Änderung (gleichzeitige Verkäufe bleiben erhalten)
            $ins = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (event_id, cat_index, available, updated_at) VALUES (%d, %d, %d, %s)", $event_id, $i, $v, $now));
            if (!$ins) {
                $wpdb->query($wpdb->prepare("UPDATE $t SET available = GREATEST(0, available + %d), updated_at = %s WHERE event_id = %d AND cat_index = %d", $d, $now, $event_id, $i));
                $changed = true;
            }
        }
        if ($changed) self::mirror($event_id);
    }

    /** Zeilen für ein Event aus der Anzeige-Kopie anlegen, falls noch keine da sind. Liefert die Zahl neuer Zeilen. */
    public static function ensure($event_id) {
        global $wpdb;
        $event_id = intval($event_id);
        if (!$event_id || !self::ready()) return 0;
        $cats = get_post_meta($event_id, self::META, true);
        if (!is_array($cats)) return 0;
        $t = self::table();
        $now = current_time('mysql', true);
        $n = 0;
        foreach ($cats as $i => $c) {
            if (!is_numeric($i)) continue;
            $v = self::limit_of($c);
            if ($v === null) continue;
            $n += intval($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (event_id, cat_index, available, updated_at) VALUES (%d, %d, %d, %s)", $event_id, $i, $v, $now)));
        }
        return $n;
    }

    /** Bestand je Kategorie [cat_index => verfügbar] (nur begrenzte). */
    public static function levels($event_id) {
        global $wpdb;
        if (!self::ready()) return [];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT cat_index, available FROM " . self::table() . " WHERE event_id = %d", intval($event_id)));
        $out = [];
        foreach ((array) $rows as $r) $out[intval($r->cat_index)] = intval($r->available);
        return $out;
    }

    /** Anzeige-Kopie (`stock` in den Kategorien) auf den Stand der Tabelle bringen. */
    public static function mirror($event_id) {
        $event_id = intval($event_id);
        for ($try = 0; $try < 3; $try++) {
            $lv = self::levels($event_id);
            wp_cache_delete($event_id, 'post_meta');
            $cats = get_post_meta($event_id, self::META, true);
            if (!is_array($cats)) return;
            $diff = false;
            foreach ($lv as $i => $v) {
                if (!isset($cats[$i]) || !is_array($cats[$i]) || self::limit_of($cats[$i]) === null) continue;
                if (intval($cats[$i]['stock']) !== $v) { $cats[$i]['stock'] = $v; $diff = true; }
            }
            if (!$diff) return;
            self::$writing++;
            try { update_post_meta($event_id, self::META, $cats); } finally { self::$writing--; }
            // Hat zwischendurch jemand anderes gebucht, gleich noch einmal angleichen
            if (self::levels($event_id) === $lv) return;
        }
    }

    // ── Buchen ──

    /** Positionen auf (event_id|cat_index) zusammenfassen; Specials (cat_index < 0) haben eigenen Bestand. */
    public static function lines(array $items) {
        $need = [];
        foreach ($items as $it) {
            $e = intval($it['event_id'] ?? 0); $c = intval($it['cat_index'] ?? -1); $q = intval($it['qty'] ?? 0);
            if (!$e || $c < 0 || $q <= 0) continue;
            $k = $e . '|' . $c;
            if (!isset($need[$k])) $need[$k] = ['event_id' => $e, 'cat_index' => $c, 'qty' => 0, 'name' => (string) ($it['name'] ?? '')];
            $need[$k]['qty'] += $q;
        }
        return array_values($need);
    }

    /**
     * Alle Positionen reservieren – ganz oder gar nicht.
     * Rückgabe: Liste [{event_id, cat_index, qty}] des tatsächlich abgezogenen Bestands (unbegrenzte fehlen),
     * oder WP_Error tix_sold_out (409) mit event_id, cat_index, available.
     * $allow_oversell: alter Weg (nie ablehnen, höchstens auf 0) – nur für Demo-/Beispieldaten.
     */
    public static function reserve(array $items, $allow_oversell = false) {
        global $wpdb;
        $need = self::lines($items);
        if (!$need || !self::ready()) return [];
        $t = self::table();
        $now = current_time('mysql', true);
        foreach (array_unique(array_column($need, 'event_id')) as $e) self::ensure($e);
        $done = [];
        foreach ($need as $l) {
            $avail = $wpdb->get_var($wpdb->prepare("SELECT available FROM $t WHERE event_id = %d AND cat_index = %d", $l['event_id'], $l['cat_index']));
            if ($avail === null) continue; // unbegrenzt
            if ($allow_oversell) {
                $wpdb->query($wpdb->prepare("UPDATE $t SET available = GREATEST(0, available - %d), updated_at = %s WHERE event_id = %d AND cat_index = %d", $l['qty'], $now, $l['event_id'], $l['cat_index']));
                $take = min($l['qty'], max(0, intval($avail)));
                if ($take > 0) $done[] = ['event_id' => $l['event_id'], 'cat_index' => $l['cat_index'], 'qty' => $take];
                continue;
            }
            $ok = $wpdb->query($wpdb->prepare("UPDATE $t SET available = available - %d, updated_at = %s WHERE event_id = %d AND cat_index = %d AND available >= %d",
                $l['qty'], $now, $l['event_id'], $l['cat_index'], $l['qty']));
            if (!$ok) {
                self::release($done, false);
                $left = intval($wpdb->get_var($wpdb->prepare("SELECT available FROM $t WHERE event_id = %d AND cat_index = %d", $l['event_id'], $l['cat_index'])));
                foreach (array_unique(array_column($done, 'event_id')) as $e) self::mirror($e);
                self::mirror($l['event_id']);
                $name = $l['name'] !== '' ? $l['name'] : self::cat_name($l['event_id'], $l['cat_index']);
                $msg = $left > 0 ? sprintf('%s: Nur noch %d verfügbar.', $name, $left) : sprintf('%s ist leider ausverkauft.', $name);
                return new WP_Error('tix_sold_out', $msg, ['status' => 409, 'event_id' => $l['event_id'], 'cat_index' => $l['cat_index'], 'available' => $left]);
            }
            $done[] = ['event_id' => $l['event_id'], 'cat_index' => $l['cat_index'], 'qty' => $l['qty']];
        }
        foreach (array_unique(array_column($need, 'event_id')) as $e) self::mirror($e);
        return $done;
    }

    /** Bestand zurückbuchen (+) bzw. erneut abziehen (−, höchstens bis 0). Unbegrenzte Kategorien bleiben unberührt. */
    public static function adjust(array $lines, $sign, $mirror = true) {
        global $wpdb;
        if (!self::ready()) return;
        $t = self::table();
        $now = current_time('mysql', true);
        $events = [];
        foreach (self::lines($lines) as $l) {
            self::ensure($l['event_id']);
            $d = $sign > 0 ? $l['qty'] : -$l['qty'];
            $wpdb->query($wpdb->prepare("UPDATE $t SET available = GREATEST(0, available + %d), updated_at = %s WHERE event_id = %d AND cat_index = %d", $d, $now, $l['event_id'], $l['cat_index']));
            $events[$l['event_id']] = 1;
        }
        if ($mirror) foreach (array_keys($events) as $e) self::mirror($e);
    }

    public static function release(array $lines, $mirror = true) {
        self::adjust($lines, +1, $mirror);
    }

    private static function cat_name($event_id, $cat_index) {
        $cats = get_post_meta($event_id, self::META, true);
        return is_array($cats) && isset($cats[$cat_index]['name']) ? (string) $cats[$cat_index]['name'] : 'Ticket';
    }

    /** Zustand für `wp tixomat migrate status` / Prüfungen: Zeilen und Abweichungen zur Anzeige-Kopie. */
    public static function health() {
        global $wpdb;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) return ['table' => false];
        $rows = $wpdb->get_results("SELECT event_id, cat_index, available FROM " . self::table());
        $diff = [];
        foreach ((array) $rows as $r) {
            $cats = get_post_meta(intval($r->event_id), self::META, true);
            $v = is_array($cats) ? self::limit_of($cats[intval($r->cat_index)] ?? null) : null;
            if ($v !== intval($r->available)) $diff[] = ['event_id' => intval($r->event_id), 'cat_index' => intval($r->cat_index), 'table' => intval($r->available), 'meta' => $v];
        }
        return ['table' => true, 'rows' => count((array) $rows), 'mismatch' => $diff];
    }
}
