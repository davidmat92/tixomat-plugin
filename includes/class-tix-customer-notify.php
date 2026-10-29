<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Customer_Notify – Lücken der Kunden-Mitteilungen (1.38.582): bisher nur per Mail, jetzt zusätzlich als
 * Eintrag in „Mitteilungen“ mit Push (TIX_Notifications::add_user_item), Antippen führt an die richtige Stelle.
 *   - Anfrage erledigt/geschlossen → support:ticket:<id>
 *   - Ticket umgeschrieben (bisheriger Inhaber) bzw. erhalten (neuer Inhaber) → tab:tickets
 *   - Gewinnspiel gewonnen → tab:tickets (Freikarte) bzw. event:<id>
 * Konto über die E-Mail – nur wenn die Adresse dem Konto sicher gehört (TIX_App_Account::email_trusted).
 * Schon vorhanden und unverändert: Support-Antwort, Awareness-Antwort, Guthaben/Bonus, Erinnerungen.
 */
class TIX_Customer_Notify {

    private static $old_owner = [];
    private static $transfers = [];

    public static function init() {
        add_action('tix_support_status_changed', [__CLASS__, 'on_support_status'], 20, 3);
        add_filter('update_post_metadata', [__CLASS__, 'remember_owner'], 10, 4);
        add_action('tix_ticket_transferred', [__CLASS__, 'on_transferred'], 20, 3);
        add_action('tix_raffle_won', [__CLASS__, 'on_raffle_won'], 20, 2);
        add_action('shutdown', [__CLASS__, 'flush_transfers']);
    }

    /** Konto zur E-Mail, nur wenn die Adresse sicher dazugehört. */
    public static function user_for_email($email) {
        $email = trim((string) $email);
        if (!is_email($email)) return null;
        $u = get_user_by('email', $email);
        if (!$u) return null;
        if (class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'email_trusted') && !TIX_App_Account::email_trusted($u)) return null;
        return $u;
    }

    private static function add($uid, $title, $body, array $args) {
        if (!$uid || !class_exists('TIX_Notifications')) return false;
        try { TIX_Notifications::add_user_item($uid, $title, $body, $args); return true; } catch (\Throwable $e) { return false; }
    }

    // ── Anfrage erledigt ──

    public static function on_support_status($id, $new, $old) {
        if (!in_array($new, ['tix_resolved', 'tix_closed'], true) || in_array($old, ['tix_resolved', 'tix_closed'], true)) return;
        if (get_post_meta($id, '_tix_sp_category', true) === 'awareness') return; // eigener Ablauf (TIX_Awareness_Live)
        if (get_post_meta($id, '_tix_sp_help', true)) return;                     // Betreiber-Anfrage eines Veranstalters
        $u = self::user_for_email(get_post_meta($id, '_tix_sp_email', true));
        if (!$u) return;
        $subject = html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8');
        self::add($u->ID, $new === 'tix_resolved' ? 'Deine Anfrage ist erledigt' : 'Deine Anfrage wurde geschlossen',
            '#' . $id . ($subject !== '' ? ' · ' . $subject : ''), ['type' => 'support', 'action' => 'support:ticket:' . $id]);
    }

    // ── Ticket umgeschrieben / erhalten ──

    /** Bisherigen Inhaber merken, bevor die Umschreibung ihn überschreibt. */
    public static function remember_owner($check, $object_id, $meta_key, $value) {
        if ($meta_key === '_tix_ticket_owner_email' && !isset(self::$old_owner[$object_id])) {
            $cur = (string) get_post_meta($object_id, '_tix_ticket_owner_email', true);
            if ($cur !== '') self::$old_owner[$object_id] = $cur; // beim Anlegen (leer) nichts merken
        }
        return $check;
    }

    public static function on_transferred($ticket_id, $new_email, $full_name) {
        $old = strtolower(self::$old_owner[$ticket_id] ?? '');
        unset(self::$old_owner[$ticket_id]);
        $new = strtolower((string) $new_email);
        if ($old === $new) return;
        $eid = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        self::$transfers[$old . '|' . $new . '|' . $eid] = ['old' => $old, 'new' => $new, 'event' => $eid, 'name' => (string) $full_name,
            'n' => (self::$transfers[$old . '|' . $new . '|' . $eid]['n'] ?? 0) + 1];
    }

    /** Eine Mitteilung je Umschreibung (auch bei mehreren Tickets). */
    public static function flush_transfers() {
        $list = self::$transfers;
        self::$transfers = [];
        foreach ($list as $t) {
            $title = $t['event'] ? html_entity_decode(get_the_title($t['event']), ENT_QUOTES, 'UTF-8') : '';
            $what = $t['n'] === 1 ? 'Ein Ticket' : $t['n'] . ' Tickets';
            if (($u = self::user_for_email($t['new']))) {
                self::add($u->ID, $t['n'] === 1 ? 'Du hast ein Ticket erhalten' : 'Du hast Tickets erhalten',
                    $what . ($title !== '' ? ' für ' . $title : '') . ' liegt jetzt in deinen Tickets.', ['type' => 'ticket', 'action' => 'tab:tickets']);
            }
            if ($t['old'] !== '' && ($u = self::user_for_email($t['old']))) {
                self::add($u->ID, 'Ticket umgeschrieben',
                    $what . ($title !== '' ? ' für ' . $title : '') . ' gehört jetzt ' . ($t['name'] !== '' ? $t['name'] : 'einer anderen Person') . '.', ['type' => 'ticket', 'action' => 'tab:tickets']);
            }
        }
    }

    // ── Gewinnspiel gewonnen ──

    public static function on_raffle_won($event_id, $winner) {
        $u = self::user_for_email($winner['email'] ?? '');
        if (!$u) return;
        $title = html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8');
        $prize = trim((string) ($winner['prize_name'] ?? ''));
        $tickets = !empty($winner['ticket_ids']) || !empty($winner['ticket_id']);
        self::add($u->ID, 'Du hast gewonnen!', ($prize !== '' ? $prize . ' · ' : '') . $title . ($tickets ? ' – deine Freikarte liegt in deinen Tickets.' : ''),
            ['type' => 'raffle', 'action' => $tickets ? 'tab:tickets' : 'event:' . intval($event_id), 'event_id' => intval($event_id)]);
    }
}
