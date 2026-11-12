<?php
/**
 * Daten je Objekt in einer eigenen Tabelle statt als Option je Objekt (Phase 0, 1.38.769).
 *
 * Bisher lag vieles als `wp_options`-Zeile `<präfix>_<id>` (PHP-serialisiert, ohne Index auf die Objekt-ID, nicht abfragbar).
 * Jetzt: {prefix}tix_object_data (kind, object_id, data JSON, updated_at), eindeutig je (kind, object_id).
 *
 * Umgezogene Arten (KINDS): Gebühren und Erstattung je Bestellung, abgezogener Bestand je Bestellung,
 * Live-Anfragen und Vertragsdaten je Lizenz. Für alle anderen Präfixe bleibt `read()` beim alten get_option –
 * so können Leser (Datenspiegel, Umzug) einheitlich über read() gehen, und weitere Arten ziehen später nur über KINDS um.
 *
 * Übergang: read() liest die Tabelle und fällt auf die alte Option zurück, solange der Migrations-Schritt `objekt_daten`
 * sie noch nicht verschoben hat; write() schreibt die Tabelle und löscht die alte Option.
 * Weg 3: lokale Tabelle, JSON, keine WP-Abhängigkeit im Format – ein künftiger Dienst liest sie direkt.
 */
if (!defined('ABSPATH')) exit;

class TIX_Object_Data {

    const DB_VERSION = '1';
    const OPT_DB     = 'tix_object_data_db';

    /** Präfix der alten Option (ohne „_“ + ID) → Art in der Tabelle */
    const KINDS = [
        '_tix_order_fees'            => 'order_fees',
        '_tix_refund'                => 'order_refund_last',
        '_tix_refund_total'          => 'order_refund_total',
        '_tix_order_stock_taken'     => 'order_stock_taken',
        '_tix_order_stock_restored'  => 'order_stock_restored',
        'tix_lic_golive'             => 'license_golive',
        'tix_lic_contract'           => 'license_contract',
    ];

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_object_data';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table() . " (
            kind varchar(40) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            data longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (kind,object_id),
            KEY kind_updated (kind,updated_at)
        ) $c;");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    public static function ready() {
        static $ok = null;
        if ($ok) return true;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::install();
        return $ok = (get_option(self::OPT_DB) === self::DB_VERSION);
    }

    public static function kind_of($prefix) {
        return self::KINDS[rtrim((string) $prefix, '_')] ?? null;
    }

    /** JSON wie gespeichert → PHP (Arrays assoziativ). */
    private static function decode($s) {
        $v = json_decode((string) $s, true);
        return json_last_error() === JSON_ERROR_NONE ? $v : null;
    }

    /**
     * Lesen. $prefix = alter Optionsname ohne „_<id>“ (z. B. '_tix_order_fees'). Nicht umgezogene Präfixe: get_option.
     * Rückgabe $default, wenn es nichts gibt.
     */
    public static function read($prefix, $id, $default = null) {
        global $wpdb;
        $prefix = rtrim((string) $prefix, '_');
        $id = intval($id);
        $kind = self::kind_of($prefix);
        if (!$kind) return get_option($prefix . '_' . $id, $default);
        if (self::ready()) {
            $row = $wpdb->get_var($wpdb->prepare("SELECT data FROM " . self::table() . " WHERE kind = %s AND object_id = %d", $kind, $id));
            if ($row !== null) return self::decode($row);
        }
        return get_option($prefix . '_' . $id, $default); // noch nicht verschoben
    }

    public static function exists($prefix, $id) {
        return self::read($prefix, $id, '__tix_none__') !== '__tix_none__';
    }

    public static function write($prefix, $id, $value) {
        global $wpdb;
        $prefix = rtrim((string) $prefix, '_');
        $id = intval($id);
        $kind = self::kind_of($prefix);
        if (!$kind || !self::ready()) return update_option($prefix . '_' . $id, $value, false);
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) return false;
        $ok = $wpdb->query($wpdb->prepare("INSERT INTO " . self::table() . " (kind, object_id, data, updated_at) VALUES (%s, %d, %s, %s)
            ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)", $kind, $id, $json, current_time('mysql', true)));
        delete_option($prefix . '_' . $id);
        do_action('tix_object_data_changed', $kind, $id);
        return $ok !== false;
    }

    public static function remove($prefix, $id) {
        global $wpdb;
        $prefix = rtrim((string) $prefix, '_');
        $id = intval($id);
        $kind = self::kind_of($prefix);
        if ($kind && get_option(self::OPT_DB) === self::DB_VERSION) $wpdb->delete(self::table(), ['kind' => $kind, 'object_id' => $id], ['%s', '%d']);
        delete_option($prefix . '_' . $id);
        if ($kind) do_action('tix_object_data_changed', $kind, $id);
    }

    /** Mehrere IDs einer Art auf einmal [id => Wert] (Tabelle + noch nicht verschobene Optionen). */
    public static function read_many($prefix, array $ids) {
        global $wpdb;
        $prefix = rtrim((string) $prefix, '_');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $out = [];
        $kind = self::kind_of($prefix);
        if ($kind && self::ready()) {
            foreach (array_chunk($ids, 500) as $ch) {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT object_id, data FROM " . self::table() . " WHERE kind = %s AND object_id IN (" . implode(',', $ch) . ")", $kind));
                foreach ((array) $rows as $r) $out[intval($r->object_id)] = self::decode($r->data);
            }
        }
        $miss = array_diff($ids, array_keys($out));
        foreach (array_chunk(array_values($miss), 500) as $ch) {
            $names = array_map(function ($i) use ($prefix) { return $prefix . '_' . $i; }, $ch);
            $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('" . implode("','", array_map('esc_sql', $names)) . "')");
            foreach ((array) $rows as $r) $out[intval(substr($r->option_name, strlen($prefix) + 1))] = maybe_unserialize($r->option_value);
        }
        return $out;
    }

    /** Alle Einträge einer Art [id => Wert] (z. B. offene Live-Anfragen). */
    public static function all($prefix) {
        global $wpdb;
        $prefix = rtrim((string) $prefix, '_');
        $out = [];
        $kind = self::kind_of($prefix);
        if ($kind && self::ready()) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT object_id, data FROM " . self::table() . " WHERE kind = %s ORDER BY object_id", $kind)) as $r) {
                $out[intval($r->object_id)] = self::decode($r->data);
            }
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name REGEXP %s", '^' . preg_quote($prefix, '/') . '_[0-9]+$')) as $r) {
            $id = intval(substr($r->option_name, strlen($prefix) + 1));
            if (!isset($out[$id])) $out[$id] = maybe_unserialize($r->option_value);
        }
        ksort($out);
        return $out;
    }

    /** SQL-Ausdruck „gibt es einen Eintrag dieser Art für <spalte>“ (Tabelle oder alte Option), für WHERE/EXISTS. */
    public static function exists_sql($prefix, $id_column) {
        global $wpdb;
        $prefix = rtrim((string) $prefix, '_');
        $kind = self::kind_of($prefix);
        $opt = $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->options} op WHERE op.option_name = CONCAT(%s, $id_column))", $prefix . '_');
        if (!$kind || get_option(self::OPT_DB) !== self::DB_VERSION) return $opt;
        return '(' . $wpdb->prepare("EXISTS (SELECT 1 FROM " . self::table() . " od WHERE od.kind = %s AND od.object_id = $id_column)", $kind) . ' OR ' . $opt . ')';
    }

    /**
     * Migration: alle alten Optionen der umgezogenen Arten in die Tabelle (vorhandene Tabellenzeilen gewinnen) und Optionen löschen.
     * Rückgabe [kind => verschoben].
     */
    public static function migrate($limit = 20000) {
        global $wpdb;
        self::install();
        $t = self::table();
        $done = [];
        foreach (self::KINDS as $prefix => $kind) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name REGEXP %s LIMIT %d", '^' . preg_quote($prefix, '/') . '_[0-9]+$', $limit));
            $n = 0;
            foreach ((array) $rows as $r) {
                $id = intval(substr($r->option_name, strlen($prefix) + 1));
                $json = wp_json_encode(maybe_unserialize($r->option_value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                if ($json === false) continue; // nicht als JSON darstellbar → Option bleibt, read() liest sie weiter
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (kind, object_id, data, updated_at) VALUES (%s, %d, %s, %s)", $kind, $id, $json, current_time('mysql', true)));
                $chk = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE kind = %s AND object_id = %d", $kind, $id));
                if (intval($chk) === 1) { delete_option($r->option_name); $n++; }
            }
            $done[$kind] = $n;
        }
        return $done;
    }

    /** Zählung für Status/Wache: Zeilen je Art und noch nicht verschobene Optionen. */
    public static function health() {
        global $wpdb;
        $out = ['table' => get_option(self::OPT_DB) === self::DB_VERSION, 'rows' => [], 'legacy' => 0];
        if ($out['table']) {
            foreach ((array) $wpdb->get_results("SELECT kind, COUNT(*) n FROM " . self::table() . " GROUP BY kind") as $r) $out['rows'][$r->kind] = intval($r->n);
        }
        foreach (array_keys(self::KINDS) as $p) {
            $out['legacy'] += intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name REGEXP %s", '^' . preg_quote($p, '/') . '_[0-9]+$')));
        }
        return $out;
    }
}
