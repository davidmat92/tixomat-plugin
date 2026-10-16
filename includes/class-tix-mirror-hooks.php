<?php
/**
 * Tixomat – Datenspiegel: Erfassen der Änderungen
 *
 * Statt an jeder der vielen Schreibstellen einen Aufruf einzubauen, hört der Spiegel
 * auf die Stellen, durch die jede Änderung ohnehin läuft:
 *  - Post-Meta, save_post, Statuswechsel, Löschen → Events (inkl. Kategorien) und Tickets
 *  - Nutzer und Nutzer-Meta, delete_user → Kunden und Löschweg
 *  - Optionen _tix_*_{bestell-id} (Gutschein, Gebühren, Zahlungsreferenzen, Erstattung) → Bestellung
 *  - Post-Meta _tix_payment_* (an der Bestell-ID) → Bestellung
 *  - Tixomat-Aktionen (Bestellung angelegt, Status, Erstattung, Check-in) → Bestellung, Verlauf
 *  - Direkte $wpdb-Schreibzugriffe auf tix_orders holt TIX_Mirror::sweep() über date_modified.
 * Jeder Callback ist billig (Eintrag im Speicher) und schluckt Fehler.
 *
 * @since Datenspiegel Paket 1 (Version vergibt die Zentrale)
 */

if (!defined('ABSPATH')) exit;

class TIX_Mirror_Hooks {

    /** Post-Meta, die keinen Spiegel-Eintrag wert ist (Sperren, Zähler). */
    const IGNORE_POST_META = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_tix_ticket_shared_count', '_tix_last_sync_time', '_tix_last_sync_log'];

    /** Optionen je Bestellung: Präfix vor "_{id}". */
    const ORDER_OPTIONS = '/^_tix_(order_coupon|order_fees|order_campaign|order_group_data|order_newsletter|order_partner|pos_order|refund|refund_total|mollie_payment|paypal_order|paypal_capture|stripe_session|stripe_method|stripe_pi)_(\d+)$/';

    public static function init() {
        // Posts (Events, Tickets)
        add_action('save_post', [__CLASS__, 'on_save_post'], 99, 2);
        add_action('added_post_meta', [__CLASS__, 'on_post_meta'], 99, 3);
        add_action('updated_post_meta', [__CLASS__, 'on_post_meta'], 99, 3);
        add_action('deleted_post_meta', [__CLASS__, 'on_post_meta'], 99, 3);
        add_action('transition_post_status', [__CLASS__, 'on_transition'], 99, 3);
        add_action('deleted_post', [__CLASS__, 'on_deleted_post'], 99, 2);

        // Nutzer
        add_action('user_register', [__CLASS__, 'on_user'], 99);
        add_action('profile_update', [__CLASS__, 'on_user'], 99);
        add_action('set_user_role', [__CLASS__, 'on_user'], 99);
        add_action('added_user_meta', [__CLASS__, 'on_user_meta'], 99, 3);
        add_action('updated_user_meta', [__CLASS__, 'on_user_meta'], 99, 3);
        add_action('deleted_user_meta', [__CLASS__, 'on_user_meta'], 99, 3);
        add_action('delete_user', [__CLASS__, 'on_delete_user'], 1);

        // Optionen je Bestellung
        add_action('added_option', [__CLASS__, 'on_option'], 99, 1);
        add_action('updated_option', [__CLASS__, 'on_option'], 99, 1);
        add_action('deleted_option', [__CLASS__, 'on_option'], 99, 1);

        // Tixomat-Aktionen
        add_action('tix_native_order_created', [__CLASS__, 'on_order'], 99, 1);
        add_action('tix_order_status_changed', [__CLASS__, 'on_order_status'], 99, 3);
        add_action('tix_order_completed', [__CLASS__, 'on_order'], 99, 1);
        add_action('tix_order_cancelled', [__CLASS__, 'on_order'], 99, 1);
        add_action('tix_order_refund_recorded', [__CLASS__, 'on_refund'], 99, 3);
        add_action('tix_order_chargeback', [__CLASS__, 'on_chargeback'], 99, 3);
        add_action('tix_ticket_checked_in', [__CLASS__, 'on_checkin'], 99, 1);
        add_action('tix_ticket_checkin_reset', [__CLASS__, 'on_checkin_reset'], 99, 1);
    }

    private static function type_entity($type) {
        if ($type === 'event') return 'event';
        if ($type === 'tix_ticket') return 'ticket';
        return '';
    }

    // ── Posts ──

    public static function on_save_post($post_id, $post) {
        if (!$post || wp_is_post_revision($post_id)) return;
        if ($e = self::type_entity($post->post_type)) TIX_Mirror::touch($e, $post_id);
    }

    public static function on_post_meta($meta_id, $object_id, $meta_key) {
        if (in_array($meta_key, self::IGNORE_POST_META, true)) return;
        // Gebühren der Zahlungsanbieter hängen an der Bestell-ID (nicht an einem Post).
        if (strpos((string) $meta_key, '_tix_payment_') === 0) { TIX_Mirror::touch('order', (int) $object_id); return; }
        if (!TIX_Mirror::capturing()) return;
        if ($e = self::type_entity(get_post_type($object_id))) TIX_Mirror::touch($e, (int) $object_id);
    }

    public static function on_transition($new, $old, $post) {
        if ($new === $old || !$post) return;
        if ($e = self::type_entity($post->post_type)) TIX_Mirror::touch($e, $post->ID);
    }

    public static function on_deleted_post($post_id, $post = null) {
        $type = $post ? $post->post_type : '';
        if ($e = self::type_entity($type)) TIX_Mirror::touch($e, (int) $post_id, 'delete');
    }

    // ── Nutzer ──

    public static function on_user($user_id) {
        TIX_Mirror::touch('customer', (int) $user_id);
    }

    public static function on_user_meta($meta_id, $user_id, $meta_key) {
        if (TIX_Mirror_Map::user_meta_relevant($meta_key)) TIX_Mirror::touch('customer', (int) $user_id);
    }

    /** Löschweg: vor dem Löschen die E-Mail merken, damit der Spiegel auch den Verlauf tilgen kann. */
    public static function on_delete_user($user_id) {
        if (!TIX_Mirror::capturing()) return;
        $u = get_userdata($user_id);
        TIX_Mirror::touch('customer', (int) $user_id, 'forget', ['email' => $u ? (string) $u->user_email : '']);
    }

    // ── Optionen ──

    public static function on_option($name) {
        if (strncmp((string) $name, '_tix_', 5) !== 0) return;
        if (preg_match(self::ORDER_OPTIONS, $name, $m)) TIX_Mirror::touch('order', (int) $m[2]);
    }

    // ── Tixomat-Aktionen ──

    public static function on_order($order_id) {
        TIX_Mirror::touch('order', (int) $order_id);
    }

    public static function on_order_status($order_id, $new = '', $old = '') {
        TIX_Mirror::touch('order', (int) $order_id);
        if ($new === 'cancelled' && $old !== 'cancelled') {
            self::refund_event($order_id, 'cancel', ['amount' => null, 'is_full' => null, 'data' => ['old_status' => (string) $old]]);
        }
    }

    public static function on_refund($order_id, $amount = 0, $is_full = false) {
        TIX_Mirror::touch('order', (int) $order_id);
        $last = get_option('_tix_refund_' . (int) $order_id, []);
        $last = is_array($last) ? $last : [];
        self::refund_event($order_id, 'refund', [
            'amount'     => (float) $amount,
            'is_full'    => (bool) $is_full,
            'reason'     => (string) ($last['reason'] ?? ''),
            'gateway'    => (string) ($last['gateway'] ?? ''),
            'source'     => (string) ($last['source'] ?? ''),
            'admin_user' => isset($last['admin_user']) ? (string) $last['admin_user'] : '',
            'data'       => $last,
        ]);
    }

    public static function on_chargeback($order_id, $amount = 0, $gateway = '') {
        TIX_Mirror::touch('order', (int) $order_id);
        self::refund_event($order_id, 'chargeback', ['amount' => (float) $amount, 'gateway' => (string) $gateway]);
    }

    private static function refund_event($order_id, $kind, array $extra) {
        if (!TIX_Mirror::capturing()) return;
        $at = gmdate('c');
        $row = array_merge([
            'order_id' => (int) $order_id, 'kind' => $kind, 'amount' => null, 'is_full' => null,
            'reason' => '', 'gateway' => '', 'source' => '', 'admin_user' => '',
            'total_refunded' => (float) get_option('_tix_refund_total_' . (int) $order_id, 0),
            'at' => $at, 'data' => [],
        ], $extra);
        $row['data'] = (object) (is_array($row['data']) ? $row['data'] : []);
        TIX_Mirror::touch('refund', $order_id . ':' . $kind . ':' . substr(str_replace('.', '', (string) microtime(true)), 0, 16), 'upsert', $row);
    }

    public static function on_checkin($ticket_id) { self::checkin_event($ticket_id, 'in'); }
    public static function on_checkin_reset($ticket_id) { self::checkin_event($ticket_id, 'reset'); }

    private static function checkin_event($ticket_id, $action) {
        if (!TIX_Mirror::capturing()) return;
        $ticket_id = (int) $ticket_id;
        TIX_Mirror::touch('ticket', $ticket_id);
        TIX_Mirror::touch('checkin', $ticket_id . ':' . $action . ':' . substr(str_replace('.', '', (string) microtime(true)), 0, 16), 'upsert', [
            'ticket_id'   => $ticket_id,
            'ticket_code' => (string) get_post_meta($ticket_id, '_tix_ticket_code', true),
            'event_id'    => (int) get_post_meta($ticket_id, '_tix_ticket_event_id', true) ?: null,
            'action'      => $action,
            'at'          => gmdate('c'),
            'by'          => $action === 'in' ? (string) get_post_meta($ticket_id, '_tix_ticket_checkin_by', true) : (string) get_current_user_id(),
            'data'        => (object) [],
        ]);
    }
}
