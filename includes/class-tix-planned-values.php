<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Planned_Values – geplante Werte für vertragsrelevante Einstellungen (nur Mehr-Veranstalter-Modus mit Vertragsquelle
 * „Vorlage im Plugin“, also nur dort, wo die Werte im Vertrag stehen).
 *
 * Eine nachteilige Änderung (Gebühr/Fix rauf, Zahlungskosten nicht mehr enthalten, Gastanteil runter, Auszahlung später,
 * Kanal-Gebühr rauf) wird beim Speichern nicht wirksam: Der alte Wert bleibt, der neue liegt als geplanter Wert in der Option
 * tix_planned_values. Mit der Veröffentlichung der Vertragsversion (TIX_Contract_Template) bekommt er sein Gültig-ab
 * (Ankündigung + Vorlauf). Ab dem Stichtag liefern die Einstellungen den neuen Wert sofort (Filter option_*, also zählt der
 * Bestellzeitpunkt); der Cron schreibt ihn danach fest. Zugunsten-Änderungen gelten sofort.
 * Zurückziehen vor dem Stichtag (Admin): Eintrag weg; eine schon angekündigte Version wird zurückgenommen (Mail).
 *
 * Werte: tix_settings.fee_percent, fee_fixed, gateway_fee_included, fee_split_customer_share, settlement_delay_days;
 * tix_channel_fee_settings.percent, fixed (Vorgabe der Kanal-Gebühr für Partner).
 * Nicht geplant: Werte einzelner Veranstalter (eigene Gebühr = Zusatzvereinbarung mit Zustimmung, eigene Auszahlungsfrist)
 * und abweichende Kanal-Gebühr einzelner Partner – sie gelten mit der jeweiligen Vereinbarung.
 */
class TIX_Planned_Values {

    const OPTION = 'tix_planned_values';
    const KEYS = [
        'tix_settings' => [
            'fee_percent'              => ['gebuehr_prozent', 'Gebühr in %', 'float'],
            'fee_fixed'                => ['gebuehr_fix', 'Gebühr je Ticket (€)', 'money'],
            'gateway_fee_included'     => ['zahlungskosten', 'Zahlungskosten in der Gebühr enthalten', 'bool'],
            'fee_split_customer_share' => ['anteil_gast_prozent', 'Anteil des Gasts bei „geteilt“ (%)', 'float'],
            'settlement_delay_days'    => ['auszahlung_tage', 'Auszahlung nach Tagen', 'int'],
        ],
        'tix_channel_fee_settings' => [
            'percent' => ['kanal_gebuehr_prozent', 'Kanal-Gebühr in %', 'float'],
            'fixed'   => ['kanal_gebuehr_fix', 'Kanal-Gebühr je Ticket (€)', 'money'],
        ],
    ];

    private static $bypass = false;

    public static function active() {
        return class_exists('TIX_Contract_Template') && TIX_Contract_Template::active();
    }

    public static function init() {
        if (!(class_exists('TIX_Contract') && TIX_Contract::multi())) return;
        foreach (array_keys(self::KEYS) as $opt) {
            add_filter('pre_update_option_' . $opt, function ($new, $old) use ($opt) { return TIX_Planned_Values::intercept($opt, $new, $old); }, 5, 2);
            add_filter('option_' . $opt, function ($value) use ($opt) { return TIX_Planned_Values::overlay($opt, $value); }, 5);
        }
        add_action('admin_post_tix_planned_withdraw', [__CLASS__, 'handle_withdraw']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
    }

    // ── Speicher ──

    public static function all() {
        $l = get_option(self::OPTION, []);
        return is_array($l) ? array_values($l) : [];
    }

    private static function save(array $list) {
        if ($list) update_option(self::OPTION, array_values($list), false); else delete_option(self::OPTION);
    }

    private static function norm($type, $v) {
        switch ($type) {
            case 'bool':  return !empty($v) ? 1 : 0;
            case 'int':   return intval($v);
            case 'money': return round(floatval(str_replace(',', '.', (string) $v)), 2);
            default:      return round(floatval(str_replace(',', '.', (string) $v)), 4);
        }
    }

    /** Wirksamer Wert, wenn er noch nie gespeichert wurde (Vorgaben der jeweiligen Klasse). */
    private static function default_value($opt, $key) {
        if ($opt === 'tix_channel_fee_settings' && class_exists('TIX_Channel_Fee')) { $d = TIX_Channel_Fee::settings(); return $d[$key] ?? 0; }
        if (function_exists('tix_get_settings')) { $v = tix_get_settings($key); return $v === null ? 0 : $v; }
        return 0;
    }

    /** Ist die Änderung zugunsten der Veranstalter? (Richtung aus TIX_Contract_Template::FIELDS) */
    public static function favorable($field, $old, $new) {
        $dir = TIX_Contract_Template::FIELDS[$field][1] ?? 'none';
        if ($dir === 'any') return true;
        if ($dir === 'lower') return floatval($new) < floatval($old);
        if ($dir === 'higher') return floatval($new) > floatval($old);
        return false;
    }

    // ── Abfangen + Überlagern ──

    /** Beim Speichern: nachteilige Änderungen zurückhalten und planen. */
    public static function intercept($opt, $new, $old) {
        if (self::$bypass || !self::active() || !is_array($new)) return $new;
        $old = is_array($old) ? $old : [];
        $list = self::all();
        $changed = false;
        foreach (self::KEYS[$opt] as $key => $def) {
            if (!array_key_exists($key, $new) && $def[2] !== 'bool') continue;
            $o = self::norm($def[2], array_key_exists($key, $old) ? $old[$key] : self::default_value($opt, $key));
            $n = self::norm($def[2], $new[$key] ?? 0);
            $idx = null;
            foreach ($list as $i => $p) if ($p['option'] === $opt && $p['key'] === $key) $idx = $i;
            if ($n == $o) continue; // unverändert (das Formular zeigt den gültigen Wert)
            if (self::favorable($def[0], $o, $n)) {
                if ($idx !== null) { unset($list[$idx]); $changed = true; } // ersetzt einen geplanten Wert
                continue;
            }
            // nachteilig: alter Wert bleibt wirksam, neuer wird geplant
            $new[$key] = array_key_exists($key, $old) ? $old[$key] : $o;
            if ($def[2] === 'bool' && empty($new[$key])) unset($new[$key]);
            $entry = ['id' => wp_generate_password(10, false, false), 'option' => $opt, 'key' => $key, 'field' => $def[0], 'label' => $def[1],
                'old' => $o, 'new' => $n, 'created_at' => time(), 'by' => get_current_user_id(), 'valid_from' => null, 'version' => null];
            if ($idx !== null) { if ($list[$idx]['new'] == $n) continue; unset($list[$idx]); }
            $list[] = $entry;
            $changed = true;
            set_transient('tix_planned_notice_' . get_current_user_id(), 1, 120);
        }
        if ($changed) {
            self::save($list);
            // Bleibt der gespeicherte Wert gleich, löst WordPress keine update_option-Hooks aus → Vertrag hier prüfen
            if (class_exists('TIX_Contract_Template')) { TIX_Contract_Template::retract_obsolete(); TIX_Contract_Template::detect(); }
        }
        return $new;
    }

    /** Beim Lesen: fällige geplante Werte gelten sofort (Bestellzeitpunkt zählt). */
    public static function overlay($opt, $value) {
        if (self::$bypass || !is_array($value)) return $value;
        foreach (self::all() as $p) {
            if ($p['option'] !== $opt || empty($p['valid_from']) || intval($p['valid_from']) > time()) continue;
            $value[$p['key']] = $p['new'];
        }
        return $value;
    }

    /** Ziel-Werte (inkl. noch nicht fälliger Pläne) für den Vertrag: field → Wert. */
    public static function target_fields() {
        $out = [];
        foreach (self::all() as $p) $out[$p['field']] = $p['new'];
        return $out;
    }

    /** Fällige Pläne in die Einstellungen schreiben und entfernen (Cron). */
    public static function apply_due() {
        $list = self::all();
        $due = array_filter($list, function ($p) { return !empty($p['valid_from']) && intval($p['valid_from']) <= time(); });
        if (!$due) return 0;
        self::$bypass = true;
        foreach ($due as $p) {
            $v = get_option($p['option'], []);
            $v = is_array($v) ? $v : [];
            if ($p['field'] === 'zahlungskosten' && empty($p['new'])) unset($v[$p['key']]); else $v[$p['key']] = $p['new'];
            update_option($p['option'], $v);
        }
        self::$bypass = false;
        self::save(array_filter($list, function ($p) { return empty($p['valid_from']) || intval($p['valid_from']) > time(); }));
        if (function_exists('wp_cache_delete')) wp_cache_delete('alloptions', 'options');
        return count($due);
    }

    /** Mit der veröffentlichten Version verknüpfen: Gültig-ab = Inkrafttreten der Version. */
    public static function link_version($ver) {
        $list = self::all();
        if (!$list) return;
        foreach ($list as &$p) {
            if (!empty($p['valid_from'])) continue;
            $p['valid_from'] = intval($ver['valid_from']);
            $p['version'] = (string) $ver['version'];
        }
        unset($p);
        self::save($list);
        if (intval($ver['valid_from']) <= time()) self::apply_due(); // zugunsten/sofort veröffentlicht → gilt jetzt
    }

    // ── Zurückziehen ──

    public static function withdraw($id) {
        $list = self::all();
        $hit = null;
        foreach ($list as $i => $p) if ($p['id'] === (string) $id) { $hit = $p; unset($list[$i]); }
        if (!$hit) return false;
        self::save($list);
        if (class_exists('TIX_Contract_Template')) { TIX_Contract_Template::retract_obsolete(); TIX_Contract_Template::detect(); }
        return $hit;
    }

    public static function handle_withdraw() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        $id = sanitize_text_field(wp_unslash($_REQUEST['id'] ?? ''));
        check_admin_referer('tix_planned_withdraw_' . $id);
        $hit = self::withdraw($id);
        $msg = $hit ? 'Geplante Änderung zurückgezogen: ' . $hit['label'] . ' bleibt ' . self::fmt($hit, $hit['old']) . '.' : 'Nicht gefunden.';
        wp_safe_redirect(add_query_arg('tix_planned_msg', rawurlencode($msg), wp_get_referer() ?: admin_url('admin.php?page=' . TIX_Org_Approval::ADMIN_SLUG . '&tab=settings')));
        exit;
    }

    // ── Anzeige ──

    public static function fmt($p, $v) {
        $t = self::KEYS[$p['option']][$p['key']][2] ?? 'float';
        if ($t === 'bool') return $v ? 'ja' : 'nein';
        if ($t === 'money') return number_format((float) $v, 2, ',', '.') . ' €';
        if ($t === 'int') return (string) intval($v);
        $s = number_format((float) $v, 2, ',', '.');
        return rtrim(rtrim($s, '0'), ',');
    }

    public static function for_key($opt, $key) {
        foreach (self::all() as $p) if ($p['option'] === $opt && $p['key'] === $key) return $p;
        return null;
    }

    /** Hinweis unter einem Einstellungsfeld: „aktuell X, ab TT.MM. Y“ + Zurückziehen. */
    public static function hint($opt, $key) {
        $p = self::for_key($opt, $key);
        if (!$p) return '';
        $when = !empty($p['valid_from']) ? 'ab ' . wp_date('d.m.Y', intval($p['valid_from'])) : 'nach Ankündigung + Vorlauf';
        $url = wp_nonce_url(add_query_arg(['action' => 'tix_planned_withdraw', 'id' => $p['id']], admin_url('admin-post.php')), 'tix_planned_withdraw_' . $p['id']);
        return '<p style="margin:4px 0 0;font-size:12px;color:#92400e;background:#fffbeb;border-radius:4px;padding:3px 6px;display:inline-block;">Aktuell ' . esc_html(self::fmt($p, $p['old'])) . ', ' . esc_html($when) . ' ' . esc_html(self::fmt($p, $p['new'])) . ' (geplant, laut Vertrag) · <a href="' . esc_url($url) . '">zurückziehen</a></p>';
    }

    /** Liste für App/Web: [{key, label, current, planned, current_label, planned_label, valid_from}] */
    public static function public_list($keys = null) {
        $out = [];
        foreach (self::all() as $p) {
            if ($keys !== null && !in_array($p['key'], $keys, true)) continue;
            $out[] = ['key' => $p['field'], 'setting' => $p['key'], 'label' => $p['label'], 'current' => $p['old'], 'planned' => $p['new'],
                'current_label' => self::fmt($p, $p['old']), 'planned_label' => self::fmt($p, $p['new']),
                'valid_from' => !empty($p['valid_from']) ? gmdate('Y-m-d\TH:i:s\Z', intval($p['valid_from'])) : null];
        }
        return $out;
    }

    public static function admin_notices() {
        if (!current_user_can('manage_options')) return;
        if (!empty($_GET['tix_planned_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['tix_planned_msg']))) . '</p></div>';
        if (get_transient('tix_planned_notice_' . get_current_user_id())) {
            delete_transient('tix_planned_notice_' . get_current_user_id());
            $items = array_map(function ($p) { return $p['label'] . ': ' . TIX_Planned_Values::fmt($p, $p['old']) . ' → ' . TIX_Planned_Values::fmt($p, $p['new']); }, self::all());
            echo '<div class="notice notice-warning"><p><strong>Geplant statt sofort:</strong> ' . esc_html(implode(' · ', $items)) . '. Diese Änderung ist laut Vermittlungsvertrag nachteilig für Veranstalter. Sie wird angekündigt und gilt erst nach dem Änderungsvorlauf; bis dahin rechnen Kasse und Abrechnung mit dem alten Wert. <a href="' . esc_url(admin_url('admin.php?page=' . TIX_Org_Approval::ADMIN_SLUG . '&tab=settings#tix-contract-draft')) . '">Vertrag prüfen</a></p></div>';
        }
    }

    /** Box in Veranstalter-Prüfung → Einstellungen. */
    public static function render_box() {
        $list = self::all();
        if (!$list) return;
        echo '<h3>Geplante Werte</h3><table class="widefat striped" style="max-width:900px;"><thead><tr><th>Wert</th><th>aktuell</th><th>geplant</th><th>gilt ab</th><th></th></tr></thead><tbody>';
        foreach ($list as $p) {
            $url = wp_nonce_url(add_query_arg(['action' => 'tix_planned_withdraw', 'id' => $p['id']], admin_url('admin-post.php')), 'tix_planned_withdraw_' . $p['id']);
            echo '<tr><td>' . esc_html($p['label']) . '</td><td>' . esc_html(self::fmt($p, $p['old'])) . '</td><td>' . esc_html(self::fmt($p, $p['new'])) . '</td><td>'
                . esc_html(!empty($p['valid_from']) ? wp_date('d.m.Y', intval($p['valid_from'])) . ' (Version ' . $p['version'] . ')' : 'nach Veröffentlichung + Vorlauf') . '</td><td><a href="' . esc_url($url) . '">zurückziehen</a></td></tr>';
        }
        echo '</tbody></table>';
    }
}
