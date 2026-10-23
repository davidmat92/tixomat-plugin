<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Retention – automatische Löschfristen (1.38.536, aus den Rechtstexten).
 *
 *  1. Warteliste und Vorverkaufs-Alarm (Tabelle tix_waitlist, Typen soldout/presale): 30 Tage nach Event-Ende gelöscht,
 *     außerdem bei Kontolöschung (E-Mail des Kontos) und beim endgültigen Löschen des Events (auf jedem Weg, nicht nur im WP-Admin).
 *  2. Support-Anfragen inkl. „Event melden“ und Awareness-Meldungen (tix_support_ticket): 2 Jahre nach Abschluss
 *     (gelöst/geschlossen; Zeitpunkt _tix_sp_closed_at, für ältere letzte Antwort bzw. letzte Änderung) anonymisiert:
 *     Name, E-Mail, Betreff, alle Nachrichteninhalte, Anhänge (Dateien), KI-Zusammenfassung, Zugangsschlüssel, Mail-Bezug,
 *     Bestell-/Ticket-Bezug. Kategorie, Status, Daten, Event und Veranstalter bleiben (Statistik).
 *     Auszahlungs-Anfragen mit _tix_sp_retain_until in der Zukunft bleiben bis dahin (TIX_Account_Delete).
 *  3. Musikwünsche (tix_song_requests): 30 Tage nach dem Event gelöscht (ohne Event: 30 Tage nach dem Wunsch),
 *     außerdem bei Kontolöschung (Konto-ID) und beim endgültigen Löschen des Events.
 * Täglicher Lauf (Cron tix_retention_daily); wp tixomat retention status | run [--dry-run].
 */
class TIX_Retention {

    const CRON = 'tix_retention_daily';
    const DAYS_WAITLIST = 30;
    const DAYS_MUSIC = 30;
    const YEARS_SUPPORT = 2;
    const META_CLOSED = '_tix_sp_closed_at';
    const META_ANON = '_tix_sp_anonymized_at';
    const ANON_NAME = '[Gelöscht]';
    const ANON_TEXT = '[Inhalt nach Fristende gelöscht]';

    public static function init() {
        add_action(self::CRON, [__CLASS__, 'run']);
        add_action('init', function () {
            if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON);
        }, 30);
        add_action('transition_post_status', [__CLASS__, 'on_support_status'], 10, 3);
        add_action('delete_user', [__CLASS__, 'on_delete_user'], 5);
        add_action('tix_account_deleted', [__CLASS__, 'on_account_deleted'], 10, 2);
        add_action('before_delete_post', [__CLASS__, 'on_delete_event'], 5);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat retention', [__CLASS__, 'cli']);
    }

    private static function table_exists($t) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
    }

    /** Ende eines Events (Y-m-d) oder '' (unbekannt bzw. gelöscht). */
    private static function event_end($eid) {
        if (get_post_type(intval($eid)) !== 'event') return '';
        $d = (string) (get_post_meta($eid, '_tix_date_end', true) ?: get_post_meta($eid, '_tix_date_start', true));
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? substr($d, 0, 10) : '';
    }

    /** Events einer Tabelle, deren Ende länger als $days her ist (oder die es nicht mehr gibt). → [event_id] */
    private static function expired_events($table, $days) {
        global $wpdb;
        $cut = gmdate('Y-m-d', current_time('timestamp') - $days * DAY_IN_SECONDS);
        $out = [];
        foreach ((array) $wpdb->get_col("SELECT DISTINCT event_id FROM $table WHERE event_id > 0") as $eid) {
            $eid = intval($eid);
            if (get_post_type($eid) !== 'event') { $out[] = $eid; continue; }
            $end = self::event_end($eid);
            if ($end !== '' && $end < $cut) $out[] = $eid;
        }
        return $out;
    }

    // ═══════════════ 1. Warteliste / Vorverkaufs-Alarm ═══════════════

    public static function waitlist($dry = false) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_waitlist';
        if (!self::table_exists($t)) return 0;
        $ids = self::expired_events($t, self::DAYS_WAITLIST);
        if (!$ids) return 0;
        $in = implode(',', array_map('intval', $ids));
        return $dry ? intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE event_id IN ($in)")) : intval($wpdb->query("DELETE FROM $t WHERE event_id IN ($in)"));
    }

    // ═══════════════ 3. Musikwünsche ═══════════════

    public static function music($dry = false) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_song_requests';
        if (!self::table_exists($t)) return 0;
        $n = 0;
        $ids = self::expired_events($t, self::DAYS_MUSIC);
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $n += $dry ? intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE event_id IN ($in)")) : intval($wpdb->query("DELETE FROM $t WHERE event_id IN ($in)"));
        }
        // ohne Event: 30 Tage nach dem Wunsch
        $cut = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::DAYS_MUSIC * DAY_IN_SECONDS);
        $n += $dry ? intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id = 0 AND created_at < %s", $cut)))
            : intval($wpdb->query($wpdb->prepare("DELETE FROM $t WHERE event_id = 0 AND created_at < %s", $cut)));
        return $n;
    }

    // ═══════════════ 2. Support-Anfragen ═══════════════

    /** Abschluss merken (gelöst/geschlossen), beim Wiedereröffnen entfernen. */
    public static function on_support_status($new, $old, $post) {
        if (!$post || $post->post_type !== 'tix_support_ticket' || $new === $old) return;
        $done = ['tix_resolved', 'tix_closed'];
        if (in_array($new, $done, true) && !in_array($old, $done, true)) update_post_meta($post->ID, self::META_CLOSED, current_time('mysql'));
        elseif (!in_array($new, $done, true)) delete_post_meta($post->ID, self::META_CLOSED);
    }

    /** Abschlusszeitpunkt (Ortszeit) einer abgeschlossenen Anfrage. */
    public static function closed_at($id) {
        $c = (string) get_post_meta($id, self::META_CLOSED, true);
        if ($c !== '') return $c;
        $last = (string) get_post_meta($id, '_tix_sp_last_reply', true);
        $ts = $last !== '' ? strtotime($last) : 0;
        $mod = (string) get_post_field('post_modified', $id);
        $c = $ts ? wp_date('Y-m-d H:i:s', $ts) : '';
        return ($c === '' || $mod > $c) ? $mod : $c;
    }

    /** Fällige Anfragen (abgeschlossen vor mehr als 2 Jahren, noch nicht anonymisiert). → [id] */
    public static function support_due($limit = 200) {
        $cut = wp_date('Y-m-d H:i:s', strtotime('-' . self::YEARS_SUPPORT . ' years', current_time('timestamp')));
        $today = current_time('Y-m-d');
        $ids = get_posts(['post_type' => 'tix_support_ticket', 'post_status' => ['tix_resolved', 'tix_closed'], 'posts_per_page' => -1, 'fields' => 'ids',
            'suppress_filters' => true, 'no_found_rows' => true,
            'meta_query' => [['key' => self::META_ANON, 'compare' => 'NOT EXISTS']]]);
        $out = [];
        foreach ($ids as $id) {
            if (self::closed_at($id) >= $cut) continue;
            $until = (string) get_post_meta($id, '_tix_sp_retain_until', true);
            if ($until !== '' && $until >= $today) continue; // Auszahlungs-Anfrage: eigene Frist
            $out[] = intval($id);
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** Anfrage anonymisieren (Statistik bleibt). */
    public static function anonymize_ticket($id) {
        $id = intval($id);
        if (get_post_type($id) !== 'tix_support_ticket') return false;
        $anon = 'gelöscht-' . substr(hash('sha256', $id . wp_generate_password(32, true)), 0, 12) . '@removed.invalid';
        $msgs = get_post_meta($id, '_tix_sp_messages', true);
        if (is_array($msgs)) {
            foreach ($msgs as $i => $m) {
                if (!is_array($m)) continue;
                $msgs[$i]['content'] = self::ANON_TEXT;
                unset($msgs[$i]['attachments'], $msgs[$i]['files'], $msgs[$i]['html']);
                if (($m['type'] ?? '') === 'customer' || !empty($m['email'])) { $msgs[$i]['email'] = $anon; }
                if (($m['type'] ?? '') === 'customer') $msgs[$i]['author'] = self::ANON_NAME;
            }
            update_post_meta($id, '_tix_sp_messages', $msgs);
        }
        update_post_meta($id, '_tix_sp_email', $anon);
        update_post_meta($id, '_tix_sp_name', self::ANON_NAME);
        foreach (['_tix_sp_attachments', '_tix_sp_file_map', '_tix_sp_ai_summary', '_tix_sp_access_key', '_tix_sp_mail_thread', '_tix_sp_mail_msgid', '_tix_sp_inquiries',
            '_tix_sp_order_id', '_tix_sp_platform_order', '_tix_sp_ticket_code', '_tix_sp_customer_n'] as $k) delete_post_meta($id, $k);
        // Anhänge im geschützten Ordner
        if (class_exists('TIX_Support')) {
            $dir = TIX_Support::private_base() . '/support/' . $id;
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $f) if (is_file($f)) @unlink($f);
                foreach ((array) glob($dir . '/.*') as $f) if (is_file($f)) @unlink($f);
                @rmdir($dir);
            }
        }
        $cat = (string) get_post_meta($id, '_tix_sp_category', true);
        wp_update_post(['ID' => $id, 'post_title' => 'Anfrage #' . $id . ($cat !== '' ? ' (' . $cat . ')' : '') . ' – gelöscht', 'post_content' => '', 'post_excerpt' => '']);
        update_post_meta($id, self::META_ANON, current_time('mysql'));
        return true;
    }

    public static function support($dry = false) {
        $ids = self::support_due($dry ? 100000 : 200);
        if ($dry) return count($ids);
        foreach ($ids as $id) self::anonymize_ticket($id);
        return count($ids);
    }

    // ═══════════════ Konto- und Event-Löschung ═══════════════

    private static function delete_for($email, $uid) {
        global $wpdb;
        $email = strtolower(trim((string) $email));
        $w = $wpdb->prefix . 'tix_waitlist';
        if ($email !== '' && self::table_exists($w)) $wpdb->query($wpdb->prepare("DELETE FROM $w WHERE LOWER(email) = %s", $email));
        $m = $wpdb->prefix . 'tix_song_requests';
        if (intval($uid) > 0 && self::table_exists($m)) $wpdb->query($wpdb->prepare("DELETE FROM $m WHERE user_id = %d", intval($uid)));
    }

    /** WordPress-Konto wird gelöscht (vor dem Löschen, E-Mail noch bekannt). */
    public static function on_delete_user($uid) {
        $u = get_userdata(intval($uid));
        if ($u) self::delete_for($u->user_email, $u->ID);
    }

    /** Kontolöschung über Website/App/Studio (auch Gastkunden mit E-Mail). */
    public static function on_account_deleted($uid, $email = '') {
        self::delete_for($email, $uid);
    }

    /** Event endgültig gelöscht → Musikwünsche und Warteliste weg (TIX_Cleanup greift nur in der WP-Verwaltung). */
    public static function on_delete_event($post_id) {
        if (get_post_type($post_id) !== 'event') return;
        global $wpdb;
        foreach (['tix_song_requests', 'tix_waitlist'] as $tb) {
            $t = $wpdb->prefix . $tb;
            if (self::table_exists($t)) $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE event_id = %d", intval($post_id)));
        }
    }

    // ═══════════════ Lauf, Status, CLI ═══════════════

    /** Täglicher Lauf. → {waitlist, support, music} (Zahl gelöschter Zeilen bzw. anonymisierter Anfragen) */
    public static function run($dry = false) {
        $dry = $dry === true;
        $r = ['waitlist' => self::waitlist($dry), 'support' => self::support($dry), 'music' => self::music($dry)];
        if (!$dry) update_option('tix_retention_last', ['at' => current_time('mysql')] + $r, false);
        return $r;
    }

    /** wp tixomat retention status | run [--dry-run] */
    public static function cli($args, $assoc) {
        $cmd = (string) ($args[0] ?? 'status');
        if ($cmd === 'status' || ($cmd === 'run' && !empty($assoc['dry-run']))) {
            $r = self::run(true);
            WP_CLI::line(wp_json_encode(['fällig' => $r, 'fristen' => ['warteliste_tage_nach_event' => self::DAYS_WAITLIST, 'support_jahre_nach_abschluss' => self::YEARS_SUPPORT,
                'musikwuensche_tage_nach_event' => self::DAYS_MUSIC], 'letzter_lauf' => get_option('tix_retention_last', null)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($cmd === 'run') { WP_CLI::success('Aufgeräumt: ' . wp_json_encode(self::run(false), JSON_UNESCAPED_UNICODE)); return; }
        WP_CLI::error('Aufruf: wp tixomat retention status | run [--dry-run]');
    }
}
