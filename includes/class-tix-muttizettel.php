<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Muttizettel – Muttizettel je Veranstalter (nur Mehr-Veranstalter-Modus, evendis).
 *
 * Der Veranstalter schaltet das Modul `muttizettel` selbst (Meta `_tix_org_modules`, App: /organizer/profile,
 * WP-Admin: Metabox „Module“ am Veranstalter). Am Event optional ein Hinweis (`_tix_muttizettel_note`,
 * z. B. „Muttizettel am Einlass vorzeigen, Begleitperson ab 18“).
 * Ausgabe: /public/events (Liste + Detail) → `modules` (Module des Veranstalters) und `muttizettel_note`
 * (nur bei eingeschaltetem Modul), /public/organizers/{id} → `modules` wie bisher.
 * Das PDF entsteht lokal in App bzw. Browser – der Server speichert keine Muttizettel-Daten.
 * KitchenKlub/Mallorca (Ein-Veranstalter): keine Änderung, keine neuen Felder.
 */
class TIX_Muttizettel {

    const META_NOTE = '_tix_muttizettel_note';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'boxes']);
        add_action('save_post_tix_organizer', [__CLASS__, 'save_organizer'], 20, 2);
        add_action('save_post_event', [__CLASS__, 'save_event'], 20, 2);
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Hinweis am Event – nur, wenn der Veranstalter das Modul eingeschaltet hat. */
    public static function note($event_id) {
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        if (!$oid || !class_exists('TIX_Public_Platform') || empty(TIX_Public_Platform::modules($oid)['muttizettel'])) return '';
        return (string) get_post_meta($event_id, self::META_NOTE, true);
    }

    /** Module des Veranstalters eines Events (oder null ohne Veranstalter). */
    public static function event_modules($event_id) {
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        return ($oid && class_exists('TIX_Public_Platform')) ? TIX_Public_Platform::modules($oid) : null;
    }

    public static function boxes() {
        if (!self::multi() || !class_exists('TIX_Public_Platform')) return;
        add_meta_box('tix_org_modules', 'Module (Angebot auf deiner Seite)', [__CLASS__, 'render_organizer'], 'tix_organizer', 'side', 'default');
        add_meta_box('tix_muttizettel', 'Muttizettel', [__CLASS__, 'render_event'], 'event', 'side', 'default');
    }

    public static function render_organizer($post) {
        wp_nonce_field('tix_org_modules', '_tix_org_modules_nonce');
        $mods = TIX_Public_Platform::modules($post->ID);
        $labels = ['tickets' => 'Tickets', 'music' => 'Musikwunsch', 'loyalty' => 'Prämien (Stempelkarte)', 'giftcards' => 'Gutscheine', 'support' => 'Support', 'muttizettel' => 'Muttizettel', 'wallet' => 'Guthaben (Bezahlen vor Ort)'];
        foreach ($mods as $k => $on) {
            $dis = $k === 'tickets' ? ' disabled' : '';
            echo '<p style="margin:4px 0;"><label><input type="checkbox" name="tix_org_modules[' . esc_attr($k) . ']" value="1"' . checked($on || $k === 'tickets', true, false) . $dis . '> ' . esc_html($labels[$k] ?? $k) . '</label></p>';
        }
        echo '<p class="description">Muttizettel: Gäste erstellen das Formular in der App bzw. im Browser, es wird nicht gespeichert.</p>';
    }

    public static function save_organizer($post_id, $post) {
        if (!isset($_POST['_tix_org_modules_nonce']) || !wp_verify_nonce($_POST['_tix_org_modules_nonce'], 'tix_org_modules')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        $in = (array) ($_POST['tix_org_modules'] ?? []);
        $mods = TIX_Public_Platform::modules($post_id);
        foreach ($mods as $k => $v) $mods[$k] = !empty($in[$k]);
        $mods['tickets'] = true;
        update_post_meta($post_id, TIX_Public_Platform::META_MODULES, wp_json_encode($mods));
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }

    public static function render_event($post) {
        wp_nonce_field('tix_muttizettel', '_tix_muttizettel_nonce');
        $oid = intval(get_post_meta($post->ID, '_tix_organizer_id', true));
        $on = $oid && !empty(TIX_Public_Platform::modules($oid)['muttizettel']);
        ?>
        <p><label for="tix-mz-note">Hinweis für Gäste (optional)</label>
            <textarea id="tix-mz-note" name="tix_muttizettel_note" rows="3" style="width:100%;" placeholder="z. B. Muttizettel am Einlass vorzeigen, Begleitperson ab 18"><?php echo esc_textarea((string) get_post_meta($post->ID, self::META_NOTE, true)); ?></textarea></p>
        <p class="description"><?php echo $on ? 'Das Modul Muttizettel ist beim Veranstalter an.' : 'Erscheint erst, wenn der Veranstalter das Modul Muttizettel einschaltet.'; ?></p>
        <?php
    }

    public static function save_event($post_id, $post) {
        if (!isset($_POST['_tix_muttizettel_nonce']) || !wp_verify_nonce($_POST['_tix_muttizettel_nonce'], 'tix_muttizettel')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        self::set_note($post_id, wp_unslash((string) ($_POST['tix_muttizettel_note'] ?? '')));
    }

    public static function set_note($post_id, $text) {
        $text = mb_substr(sanitize_textarea_field((string) $text), 0, 300);
        if ($text === '') delete_post_meta($post_id, self::META_NOTE); else update_post_meta($post_id, self::META_NOTE, $text);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }
}
