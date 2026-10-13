<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Event_Facts – Barrierefreiheit und Erstattungsregel am Event (evendis.de, nur Mehr-Veranstalter-Modus).
 *
 * Barrierefreiheit: Schlüssel (KEYS) + Hinweis. Am Ort (`tix_location`, Meta `_tix_accessibility`) pflegbar,
 * je Event überschreibbar (`_tix_accessibility_mode` inherit|custom|off, `_tix_accessibility`).
 * Ausgabe Event-Detail `accessibility {items:[{key,label}], note}` | null (nur mit Inhalt).
 *
 * Erstattungsregel: Typ + optional Tage/Hinweis am Event (`_tix_refund_policy`). Die Texte formuliert das Plugin
 * und stellen immer klar, dass bei Absage erstattet wird. Ausgabe `refund_policy {type, label, note, days,
 * legal_review_pending}` | null. Texte: Rechtsprüfung offen (`legal_review_pending: true`).
 *
 * Pflege: Metaboxen am Ort und am Event (WP-Admin), App-Editor (`accessibility_mode`, `accessibility`,
 * `refund_policy`). KitchenKlub/Mallorca (Ein-Veranstalter): keine Felder, keine Metaboxen.
 */
class TIX_Event_Facts {

    const META_ACC      = '_tix_accessibility';
    const META_ACC_MODE = '_tix_accessibility_mode';
    const META_REFUND   = '_tix_refund_policy';

    const KEYS = [
        'step_free'     => 'Stufenloser Zugang',
        'wheelchair_wc' => 'Rollstuhlgerechtes WC',
        'seats'         => 'Sitzplätze vorhanden',
        'hearing_loop'  => 'Induktionsschleife für Hörgeräte',
        'companion'     => 'Begleitperson mit Merkzeichen B frei',
        'parking'       => 'Behindertenparkplätze in der Nähe',
        'quiet_room'    => 'Ruheraum',
        'sign_language' => 'Gebärdensprache',
    ];

    const REFUND_TYPES = [
        'no_refund'  => 'Keine Rückgabe',
        'until_days' => 'Rückgabe bis kurz vor dem Event',
        'transfer'   => 'Ticket übertragbar',
        'custom'     => 'Regel des Veranstalters',
    ];
    const CANCEL_LINE = 'Wird das Event abgesagt, bekommst du den Ticketpreis erstattet.';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'boxes']);
        add_action('save_post_tix_location', [__CLASS__, 'save_location'], 20, 2);
        add_action('save_post_event', [__CLASS__, 'save_event'], 20, 2);
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ── Barrierefreiheit ──

    public static function sanitize_acc($in) {
        $in = is_array($in) ? $in : [];
        $items = $in['items'] ?? [];
        if (is_string($items)) $items = preg_split('/[,\s]+/', $items);
        $items = array_values(array_intersect(array_keys(self::KEYS), array_map('sanitize_key', (array) $items)));
        return ['items' => $items, 'note' => mb_substr(sanitize_textarea_field((string) ($in['note'] ?? '')), 0, 500)];
    }

    public static function acc_mode($event_id) {
        $m = (string) get_post_meta($event_id, self::META_ACC_MODE, true);
        return in_array($m, ['custom', 'off'], true) ? $m : 'inherit';
    }

    /** Wirksame Angaben (Rohform) oder null. */
    public static function accessibility_raw($event_id) {
        $mode = self::acc_mode($event_id);
        if ($mode === 'off') return null;
        if ($mode === 'custom') {
            $a = self::sanitize_acc(get_post_meta($event_id, self::META_ACC, true));
        } else {
            $loc = intval(get_post_meta($event_id, '_tix_location_id', true));
            $a = $loc ? self::sanitize_acc(get_post_meta($loc, self::META_ACC, true)) : ['items' => [], 'note' => ''];
        }
        return ($a['items'] || $a['note'] !== '') ? $a : null;
    }

    public static function accessibility($event_id) {
        $a = self::accessibility_raw($event_id);
        if (!$a) return null;
        return [
            'items' => array_map(function ($k) { return ['key' => $k, 'label' => self::KEYS[$k]]; }, $a['items']),
            'note'  => $a['note'],
        ];
    }

    // ── Erstattungsregel ──

    public static function sanitize_refund($in) {
        $in = is_array($in) ? $in : [];
        $type = sanitize_key((string) ($in['type'] ?? ''));
        if (!isset(self::REFUND_TYPES[$type])) return null;
        return [
            'type' => $type,
            'days' => $type === 'until_days' ? max(1, min(60, intval($in['days'] ?? 7))) : 0,
            'note' => mb_substr(sanitize_textarea_field((string) ($in['note'] ?? '')), 0, 500),
        ];
    }

    public static function refund_policy($event_id) {
        $r = self::sanitize_refund(get_post_meta($event_id, self::META_REFUND, true));
        if (!$r) return null;
        switch ($r['type']) {
            case 'no_refund':  $text = 'Eine Rückgabe des Tickets ist ausgeschlossen.'; $label = self::REFUND_TYPES['no_refund']; break;
            case 'until_days': $label = sprintf('Rückgabe bis %d %s vor dem Event', $r['days'], $r['days'] === 1 ? 'Tag' : 'Tage');
                               $text = sprintf('Du kannst dein Ticket bis %d %s vor dem Event zurückgeben.', $r['days'], $r['days'] === 1 ? 'Tag' : 'Tage'); break;
            case 'transfer':   $text = 'Eine Rückgabe ist ausgeschlossen, du kannst das Ticket aber auf eine andere Person übertragen.'; $label = self::REFUND_TYPES['transfer']; break;
            default:           $text = $r['note'] !== '' ? $r['note'] : 'Es gelten die Bedingungen des Veranstalters.'; $label = self::REFUND_TYPES['custom'];
        }
        if ($r['type'] !== 'custom' && $r['note'] !== '') $text .= ' ' . $r['note'];
        // Erstattungsanspruch bei Absage wird nie eingeschränkt
        $text .= ' ' . self::CANCEL_LINE;
        return ['type' => $r['type'], 'label' => $label, 'note' => trim($text), 'days' => $r['type'] === 'until_days' ? $r['days'] : null, 'legal_review_pending' => true];
    }

    // ── Pflege (App-Editor) ──

    public static function editor_payload($event_id) {
        return [
            'accessibility_mode' => self::acc_mode($event_id),
            'accessibility'      => self::sanitize_acc(get_post_meta($event_id, self::META_ACC, true)),
            'accessibility_effective' => self::accessibility($event_id),
            'refund_policy'      => self::sanitize_refund(get_post_meta($event_id, self::META_REFUND, true)),
            'accessibility_keys' => self::KEYS,
            'refund_types'       => self::REFUND_TYPES,
        ];
    }

    /** @return true|WP_Error */
    public static function apply_editor($event_id, array $body) {
        if (isset($body['accessibility_mode'])) {
            $m = sanitize_key((string) $body['accessibility_mode']);
            if (!in_array($m, ['inherit', 'custom', 'off'], true)) return new WP_Error('tix_invalid_field', 'Ungültige Einstellung zur Barrierefreiheit.', ['status' => 400, 'field' => 'accessibility_mode']);
            if ($m === 'inherit') delete_post_meta($event_id, self::META_ACC_MODE); else update_post_meta($event_id, self::META_ACC_MODE, $m);
        }
        if (isset($body['accessibility']) && is_array($body['accessibility'])) {
            update_post_meta($event_id, self::META_ACC, self::sanitize_acc(array_merge(self::sanitize_acc(get_post_meta($event_id, self::META_ACC, true)), $body['accessibility'])));
        }
        if (array_key_exists('refund_policy', $body)) {
            if ($body['refund_policy'] === null || $body['refund_policy'] === '') {
                delete_post_meta($event_id, self::META_REFUND);
            } else {
                $r = self::sanitize_refund($body['refund_policy']);
                if (!$r) return new WP_Error('tix_invalid_field', 'Ungültige Erstattungsregel.', ['status' => 400, 'field' => 'refund_policy']);
                update_post_meta($event_id, self::META_REFUND, $r);
            }
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return true;
    }

    // ── Metaboxen ──

    public static function boxes() {
        if (!self::multi()) return;
        add_meta_box('tix_acc_loc', 'Barrierefreiheit', [__CLASS__, 'render_location'], 'tix_location', 'normal', 'default');
        add_meta_box('tix_facts_event', 'Barrierefreiheit & Erstattung', [__CLASS__, 'render_event'], 'event', 'normal', 'default');
    }

    private static function acc_fields($name, array $a) {
        $out = '<div style="display:flex;flex-wrap:wrap;gap:6px 16px;">';
        foreach (self::KEYS as $k => $label) {
            $out .= '<label><input type="checkbox" name="' . esc_attr($name) . '[items][]" value="' . esc_attr($k) . '"' . checked(in_array($k, $a['items'], true), true, false) . '> ' . esc_html($label) . '</label>';
        }
        $out .= '</div><p><label>Hinweis (optional)<br><textarea name="' . esc_attr($name) . '[note]" rows="2" class="large-text" placeholder="z. B. Aufzug über den Hintereingang">' . esc_textarea($a['note']) . '</textarea></label></p>';
        return $out;
    }

    public static function render_location($post) {
        wp_nonce_field('tix_acc_loc', '_tix_acc_loc_nonce');
        echo self::acc_fields('tix_accessibility', self::sanitize_acc(get_post_meta($post->ID, self::META_ACC, true)));
        echo '<p class="description">Gilt für alle Events an diesem Ort; jedes Event kann es überschreiben. Erscheint als „Angabe des Veranstalters“.</p>';
    }

    public static function save_location($post_id, $post) {
        if (!isset($_POST['_tix_acc_loc_nonce']) || !wp_verify_nonce($_POST['_tix_acc_loc_nonce'], 'tix_acc_loc')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, self::META_ACC, self::sanitize_acc(wp_unslash((array) ($_POST['tix_accessibility'] ?? []))));
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }

    public static function render_event($post) {
        wp_nonce_field('tix_facts_event', '_tix_facts_nonce');
        $mode = self::acc_mode($post->ID);
        $r = self::sanitize_refund(get_post_meta($post->ID, self::META_REFUND, true));
        ?>
        <h4 style="margin:6px 0;">Barrierefreiheit</h4>
        <p>
            <label><input type="radio" name="tix_accessibility_mode" value="inherit" <?php checked($mode, 'inherit'); ?>> Angaben des Orts übernehmen</label>
            <label style="margin-left:12px;"><input type="radio" name="tix_accessibility_mode" value="custom" <?php checked($mode, 'custom'); ?>> eigene Angaben</label>
            <label style="margin-left:12px;"><input type="radio" name="tix_accessibility_mode" value="off" <?php checked($mode, 'off'); ?>> keine Angaben</label>
        </p>
        <?php echo self::acc_fields('tix_accessibility', self::sanitize_acc(get_post_meta($post->ID, self::META_ACC, true))); ?>
        <h4 style="margin:12px 0 6px;">Erstattungsregel</h4>
        <p><select name="tix_refund_policy[type]">
            <option value="">– keine Angabe –</option>
            <?php foreach (self::REFUND_TYPES as $k => $label) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($r['type'] ?? '', $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
        </select>
        Tage (bei „bis kurz vor dem Event“): <input type="number" name="tix_refund_policy[days]" min="1" max="60" value="<?php echo esc_attr($r['days'] ?? 7); ?>" style="width:70px;"></p>
        <p><label>Ergänzung / eigene Regel (optional)<br><textarea name="tix_refund_policy[note]" rows="2" class="large-text"><?php echo esc_textarea($r['note'] ?? ''); ?></textarea></label></p>
        <p class="description">Gäste sehen den Satz „<?php echo esc_html(self::CANCEL_LINE); ?>“ immer dazu. Formulierungen: Rechtsprüfung offen.</p>
        <?php
    }

    public static function save_event($post_id, $post) {
        if (!isset($_POST['_tix_facts_nonce']) || !wp_verify_nonce($_POST['_tix_facts_nonce'], 'tix_facts_event')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        $mode = sanitize_key($_POST['tix_accessibility_mode'] ?? 'inherit');
        if ($mode === 'inherit') delete_post_meta($post_id, self::META_ACC_MODE); else update_post_meta($post_id, self::META_ACC_MODE, $mode === 'off' ? 'off' : 'custom');
        update_post_meta($post_id, self::META_ACC, self::sanitize_acc(wp_unslash((array) ($_POST['tix_accessibility'] ?? []))));
        $r = self::sanitize_refund(wp_unslash((array) ($_POST['tix_refund_policy'] ?? [])));
        if ($r) update_post_meta($post_id, self::META_REFUND, $r); else delete_post_meta($post_id, self::META_REFUND);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }
}
