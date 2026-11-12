<?php
/**
 * Kundenkonten zusammenführen (P2 Teil B, 1.38.785). Vertrag `uebergaben/vertrag-mail-protokoll-konten.md`.
 *
 * Vorschau (mit Token) → Ausführen genau dieser Vorschau → 7 Tage rückgängig machbar.
 * Übertragen: Bestellungen (Konto bzw. Gast-E-Mail), Ticket-Inhaber, Support, Warteliste, Rückmeldungen, Mail-Protokoll,
 * Tischreservierungen, Newsletter-Kontakte, Mail-Folgen, Musikwünsche, Guthaben (als Buchung), Prämien/Stempel,
 * Merkliste/Folgen/Tages-Hinweise; Zweitkonto gesperrt (E-Mail merged_…, Rolle weg, App meldet `401 tix_account_merged`).
 * Jede Änderung steht im Journal (genaue IDs und alte Werte) → Rückgängig stellt genau das wieder her, sonst 409 mit Konflikten.
 * Weg-1: eigene Tabelle `tix_customer_merges`, Protokoll `tix_audit_log` (statt Option `_tix_user_merges`), REST statt wp_ajax.
 */
if (!defined('ABSPATH')) exit;

class TIX_Customer_Merge {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const OPT_DB = 'tix_customer_merges_db';
    const UNDO_DAYS = 7;
    const META_INTO = '_tix_merged_into';
    const META_SECONDARY = '_tix_secondary_emails';
    const LIST_KEYS = ['_tix_app_following', '_tix_app_following_artists', '_tix_saved_events', '_tix_day_alerts', '_tix_app_following_since'];
    const STAFF_ROLES = ['administrator', 'editor', 'author', 'tix_organizer', 'tix_cashier', 'tix_promoter', 'tix_staff', 'shop_manager'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_authentication_errors', [__CLASS__, 'block_merged'], 99);
        add_action('tix_customer_merges_cleanup', [__CLASS__, 'cleanup']);
        add_action('init', function () { if (!wp_next_scheduled('tix_customer_merges_cleanup')) wp_schedule_event(time() + 3600, 'daily', 'tix_customer_merges_cleanup'); }, 30);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_customer_merges';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            audit_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            by_user bigint(20) unsigned NOT NULL DEFAULT 0,
            primary_id varchar(40) NOT NULL,
            secondary_id varchar(40) NOT NULL,
            primary_user bigint(20) unsigned NOT NULL DEFAULT 0,
            secondary_user bigint(20) unsigned NOT NULL DEFAULT 0,
            keep_email varchar(10) NOT NULL DEFAULT 'primary',
            moved longtext NULL,
            journal longtext NULL,
            undo_until datetime NOT NULL,
            undone_at datetime NULL,
            undone_by bigint(20) unsigned NULL,
            PRIMARY KEY  (id),
            KEY audit_id (audit_id)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option(self::OPT_DB, self::DB_VERSION, false);
        self::migrate_legacy_log();
    }

    private static function ready() {
        static $ok = null;
        if ($ok) return true;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::install();
        return $ok = (get_option(self::OPT_DB) === self::DB_VERSION);
    }

    /** Altes Protokoll (Option `_tix_user_merges`) ins zentrale Protokoll übernehmen (Weg-1: keine Option je Vorgang). */
    private static function migrate_legacy_log() {
        $old = get_option('_tix_user_merges', null);
        if (!is_array($old)) return;
        if (class_exists('TIX_Customers')) foreach ($old as $e) {
            if (!is_array($e)) continue;
            TIX_Customers::audit('customer_merge_legacy', (string) ($e['primary'] ?? ($e['primary_id'] ?? '')), ['scope' => 'site', 'oid' => 0],
                ['secondary' => $e['secondary'] ?? ($e['secondary_id'] ?? null), 'at' => $e['at'] ?? ($e['time'] ?? null), 'counts' => $e['counts'] ?? null]);
        }
        delete_option('_tix_user_merges');
    }

    // ═══════════════ Wer ist wer ═══════════════

    /** Kunden-ID (u<Konto>|e<Hash>) → {id, type: user|guest, user_id, email, name} oder null. */
    public static function resolve($cid) {
        global $wpdb;
        $cid = strtolower(trim((string) $cid));
        if (preg_match('/^u(\d+)$/', $cid, $m)) {
            $u = get_userdata(intval($m[1]));
            if (!$u) return null;
            return ['id' => $cid, 'type' => 'user', 'user_id' => intval($u->ID), 'email' => (string) $u->user_email, 'name' => (string) $u->display_name];
        }
        if (preg_match('/^e[0-9a-f]{1,20}$/', $cid) && class_exists('TIX_Customers')) {
            foreach ((array) $wpdb->get_results("SELECT billing_email, billing_first_name, billing_last_name FROM {$wpdb->prefix}tix_orders WHERE billing_email <> '' GROUP BY billing_email") as $r) {
                if (TIX_Customers::guest_id($r->billing_email) === $cid) return ['id' => $cid, 'type' => 'guest', 'user_id' => 0, 'email' => (string) $r->billing_email, 'name' => trim($r->billing_first_name . ' ' . $r->billing_last_name)];
            }
        }
        return null;
    }

    private static function is_staff($uid) {
        $u = $uid ? get_userdata($uid) : null;
        if (!$u) return false;
        if (array_intersect(self::STAFF_ROLES, (array) $u->roles) || $u->has_cap('manage_options') || $u->has_cap('edit_posts')) return true;
        if (get_user_meta($uid, '_tix_technical', true) === '1') return true;
        if (class_exists('TIX_App_Scope') && get_user_meta($uid, TIX_App_Scope::META_TEAM_ORG, true)) return true;
        return false;
    }

    /** Gesperrt? null oder {code, message} (rein bis auf DB-Lesezugriffe). */
    private static function blocked($p, $s) {
        global $wpdb;
        if (!$p || !$s) return ['code' => 'tix_customer_missing', 'message' => 'Kunde nicht gefunden.'];
        if ($p['id'] === $s['id'] || ($p['user_id'] && $p['user_id'] === $s['user_id']) || strtolower($p['email']) === strtolower($s['email'])) return ['code' => 'tix_merge_same', 'message' => 'Das ist dasselbe Konto.'];
        if ($p['type'] === 'guest' && $s['type'] === 'user') return ['code' => 'tix_merge_primary_guest', 'message' => 'Das Hauptkonto muss das Konto mit Anmeldung sein. Bitte die Reihenfolge tauschen.'];
        foreach ([$p, $s] as $x) if ($x['user_id'] && self::is_staff($x['user_id'])) return ['code' => 'tix_merge_staff', 'message' => 'Team-, Kassen- oder Admin-Konten werden nicht zusammengeführt.'];
        if ($s['user_id'] && get_user_meta($s['user_id'], self::META_INTO, true)) return ['code' => 'tix_merge_already', 'message' => 'Dieses Konto wurde schon zusammengeführt.'];
        $o = $wpdb->prefix . 'tix_orders';
        foreach ([$p, $s] as $x) {
            $open = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $o WHERE status IN ('pending','on-hold','processing-refund') AND (billing_email = %s OR (customer_id > 0 AND customer_id = %d))", $x['email'], $x['user_id'])));
            if ($open) return ['code' => 'tix_merge_open_payment', 'message' => 'Es gibt noch eine offene Zahlung. Bitte erst abschließen oder stornieren.'];
        }
        return null;
    }

    // ═══════════════ Was wird übertragen ═══════════════

    /** Ziele: [Tabelle-ohne-Präfix, E-Mail-Spalte] */
    const EMAIL_TABLES = [['tix_waitlist', 'email'], ['tix_feedback', 'email'], ['tix_email_log', 'to_email'], ['tix_table_reservations', 'customer_email'], ['tix_nl_contacts', 'email']];

    private static function table_has($t, $col) {
        global $wpdb;
        static $c = [];
        $k = "$t.$col";
        if (!isset($c[$k])) $c[$k] = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}$t'") && in_array($col, (array) $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}$t"), true);
        return $c[$k];
    }

    /** Bestandsaufnahme: was beim Zweitkonto liegt (IDs je Bereich). */
    public static function inventory($s) {
        global $wpdb;
        $o = $wpdb->prefix . 'tix_orders';
        $inv = [];
        $inv['orders_customer'] = $s['user_id'] ? array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM $o WHERE customer_id = %d", $s['user_id']))) : [];
        $inv['orders_email'] = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM $o WHERE LOWER(billing_email) = %s", strtolower($s['email']))));
        $inv['ticket_owner'] = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_tix_ticket_owner_email','_tix_ticket_holder_email') AND LOWER(meta_value) = %s", strtolower($s['email']))));
        $inv['support'] = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_tix_sp_email' AND LOWER(meta_value) = %s", strtolower($s['email']))));
        foreach (self::EMAIL_TABLES as [$t, $col]) {
            $inv['rows'][$t] = self::table_has($t, $col) ? array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}$t WHERE LOWER($col) = %s", strtolower($s['email'])))) : [];
        }
        $inv['mail_follow'] = self::table_has('tix_mail_followers', 'email')
            ? array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tix_mail_followers WHERE LOWER(email) = %s", strtolower($s['email'])))) : [];
        $inv['song_requests'] = ($s['user_id'] && self::table_has('tix_song_requests', 'user_id')) ? array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tix_song_requests WHERE user_id = %d", $s['user_id']))) : [];
        $inv['wallets'] = [];
        if ($s['user_id'] && class_exists('TIX_Wallet_Balance') && self::table_has('tix_wallets', 'user_id')) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, organizer_id, balance_paid, balance_bonus FROM " . TIX_Wallet_Balance::t('wallets') . " WHERE user_id = %d", $s['user_id']), ARRAY_A) as $w) {
                if (intval($w['balance_paid']) + intval($w['balance_bonus']) > 0) $inv['wallets'][] = ['id' => intval($w['id']), 'org' => intval($w['organizer_id']), 'paid' => intval($w['balance_paid']), 'bonus' => intval($w['balance_bonus'])];
            }
        }
        $inv['loyalty'] = [];
        if ($s['user_id']) foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $s['user_id'], $wpdb->esc_like('_tix_loyalty_') . '%'), ARRAY_A) as $m) {
            $inv['loyalty'][$m['meta_key']] = maybe_unserialize($m['meta_value']);
        }
        $inv['lists'] = [];
        if ($s['user_id']) foreach (self::LIST_KEYS as $k) { $v = get_user_meta($s['user_id'], $k, true); if (!empty($v)) $inv['lists'][$k] = $v; }
        $tok = $s['user_id'] ? get_user_meta($s['user_id'], '_tix_app_tokens', true) : [];
        $inv['app_sessions'] = is_array($tok) ? count($tok) : 0;
        return $inv;
    }

    private static function points($inv) {
        $n = 0;
        foreach ((array) $inv['loyalty'] as $k => $v) if (strpos($k, '_tix_loyalty_points') === 0) $n += intval($v);
        return $n;
    }

    /** Zahlen je Bereich (für Vorschau und Ergebnis). */
    public static function counts(array $inv) {
        $orders = array_values(array_unique(array_merge($inv['orders_customer'], $inv['orders_email'])));
        $rows = $inv['rows'];
        return ['orders' => count($orders), 'tickets' => count($inv['ticket_owner']), 'support' => count($inv['support']), 'waitlist' => count($rows['tix_waitlist'] ?? []),
            'feedback' => count($rows['tix_feedback'] ?? []), 'mails' => count($rows['tix_email_log'] ?? []), 'table_reservations' => count($rows['tix_table_reservations'] ?? []),
            'newsletter' => count($rows['tix_nl_contacts'] ?? []), 'mail_follow' => count($inv['mail_follow']), 'song_requests' => count($inv['song_requests']),
            'wallet_cents' => array_sum(array_map(function ($w) { return $w['paid'] + $w['bonus']; }, $inv['wallets'])), 'loyalty_points' => self::points($inv),
            'favorites' => count((array) ($inv['lists']['_tix_saved_events'] ?? [])), 'following' => count((array) ($inv['lists']['_tix_app_following'] ?? [])) + count((array) ($inv['lists']['_tix_app_following_artists'] ?? [])),
            'app_sessions' => $inv['app_sessions']];
    }

    private static function token(array $p, array $s, array $inv) {
        return substr(hash_hmac('sha256', wp_json_encode([$p['id'], $s['id'], $inv]), wp_salt('auth')), 0, 32);
    }

    /** Vorschau: {primary, secondary, moves, conflicts, blocked, token} */
    public static function preview($pid, $sid) {
        $p = self::resolve($pid); $s = self::resolve($sid);
        $b = self::blocked($p, $s);
        if (!$p || !$s) return ['primary' => $p ? self::who($p) : null, 'secondary' => $s ? self::who($s) : null, 'moves' => null, 'conflicts' => [], 'blocked' => $b, 'token' => null];
        $inv = self::inventory($s);
        $conf = [];
        if ($inv['app_sessions']) $conf[] = ['area' => 'app_sessions', 'detail' => 'Das Zweitkonto ist in ' . $inv['app_sessions'] . ' App(s) angemeldet; es muss sich mit dem Hauptkonto neu anmelden.'];
        if ($inv['wallets']) $conf[] = ['area' => 'wallet', 'detail' => 'Guthaben wird als Buchung auf das Hauptkonto übertragen.'];
        if ($p['type'] === 'guest') $conf[] = ['area' => 'orders', 'detail' => 'Beide sind Gast-Käufe ohne Konto: Die Bestellungen des Zweitkontos bekommen die E-Mail des Hauptkontos.'];
        return ['primary' => self::who($p), 'secondary' => self::who($s), 'moves' => self::counts($inv), 'conflicts' => $conf, 'blocked' => $b, 'token' => $b ? null : self::token($p, $s, $inv)];
    }

    private static function who($x) {
        return ['id' => $x['id'], 'name' => $x['name'], 'email' => $x['email'], 'type' => $x['type']];
    }

    // ═══════════════ Ausführen ═══════════════

    public static function apply($pid, $sid, $keep_email, $token, $by = 0) {
        global $wpdb;
        if (!self::ready()) return new WP_Error('tix_merge_unavailable', 'Zusammenführen ist nicht eingerichtet.', ['status' => 503]);
        $p = self::resolve($pid); $s = self::resolve($sid);
        if ($b = self::blocked($p, $s)) return new WP_Error($b['code'], $b['message'], ['status' => $b['code'] === 'tix_customer_missing' ? 404 : 409]);
        $inv = self::inventory($s);
        if (!hash_equals(self::token($p, $s, $inv), (string) $token)) return new WP_Error('tix_merge_changed', 'Seit der Vorschau hat sich etwas geändert. Bitte die Vorschau neu laden.', ['status' => 409]);
        $keep_email = $keep_email === 'secondary' && $p['type'] === 'user' && $s['type'] === 'user' ? 'secondary' : 'primary';
        $j = ['p' => $p, 's' => $s, 'keep_email' => $keep_email];
        $o = $wpdb->prefix . 'tix_orders';
        $orders_touched = [];

        // Bestellungen: Konto (customer_id) bzw. bei Gast→Gast die E-Mail
        if ($p['user_id'] && $inv['orders_customer']) {
            $wpdb->query($wpdb->prepare("UPDATE $o SET customer_id = %d WHERE id IN (" . implode(',', $inv['orders_customer']) . ")", $p['user_id']));
            $j['orders_customer'] = $inv['orders_customer']; $orders_touched = array_merge($orders_touched, $inv['orders_customer']);
        }
        if ($p['user_id'] && $inv['orders_email']) {
            $rest = array_diff($inv['orders_email'], $inv['orders_customer']);
            if ($rest) { $wpdb->query($wpdb->prepare("UPDATE $o SET customer_id = %d WHERE customer_id = 0 AND id IN (" . implode(',', array_map('intval', $rest)) . ")", $p['user_id'])); $j['orders_customer_from0'] = array_values($rest); $orders_touched = array_merge($orders_touched, $rest); }
        }
        if (!$p['user_id'] && $inv['orders_email']) {
            $wpdb->query($wpdb->prepare("UPDATE $o SET billing_email = %s WHERE id IN (" . implode(',', $inv['orders_email']) . ")", $p['email']));
            $j['orders_email'] = ['ids' => $inv['orders_email'], 'old' => $s['email']]; $orders_touched = array_merge($orders_touched, $inv['orders_email']);
        }
        $target_email = $keep_email === 'secondary' ? $s['email'] : $p['email'];
        // Ticket-Inhaber, Support (Postmeta), E-Mail-Tabellen
        foreach (['ticket_owner', 'support'] as $k) if ($inv[$k]) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id IN (" . implode(',', $inv[$k]) . ")", $target_email));
            $j['postmeta'][$k] = ['ids' => $inv[$k], 'old' => $s['email'], 'new' => $target_email];
        }
        foreach (self::EMAIL_TABLES as [$t, $col]) if (!empty($inv['rows'][$t])) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}$t SET $col = %s WHERE id IN (" . implode(',', $inv['rows'][$t]) . ")", $target_email));
            $j['rows'][$t] = ['col' => $col, 'ids' => $inv['rows'][$t], 'old' => $s['email']];
        }
        // Mail-Folgen: eindeutig je Veranstalter+E-Mail → Doppelte beim Hauptkonto: Zeile des Zweitkontos sichern und löschen
        if ($inv['mail_follow']) {
            $mt = $wpdb->prefix . 'tix_mail_followers';
            foreach ($inv['mail_follow'] as $id) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $mt WHERE id = %d", $id), ARRAY_A);
                if (!$row) continue;
                $dup = $wpdb->get_var($wpdb->prepare("SELECT id FROM $mt WHERE organizer_id = %d AND LOWER(email) = %s", $row['organizer_id'], strtolower($target_email)));
                if ($dup) { $wpdb->delete($mt, ['id' => $id]); $j['mail_follow_deleted'][] = $row; }
                else { $wpdb->update($mt, ['email' => $target_email, 'user_id' => $p['user_id']], ['id' => $id]); $j['mail_follow_moved'][] = ['id' => $id, 'email' => $row['email'], 'user_id' => intval($row['user_id'])]; }
            }
        }
        if ($inv['song_requests'] && $p['user_id']) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}tix_song_requests SET user_id = %d WHERE id IN (" . implode(',', $inv['song_requests']) . ")", $p['user_id']));
            $j['song_requests'] = $inv['song_requests'];
        }
        // Guthaben: je Geldbörse Ab- und Zubuchung (nie neu berechnen)
        foreach ($inv['wallets'] as $w) {
            if (!$p['user_id']) break;
            $meta = ['reason' => 'customer_merge', 'from' => $s['id'], 'to' => $p['id']];
            $out = TIX_Wallet_Balance::post_tx($w['id'], ['type' => 'adjust', 'amount_paid' => -$w['paid'], 'amount_bonus' => -$w['bonus'], 'channel' => 'admin', 'allow_locked' => true, 'meta' => $meta, 'organizer_id' => $w['org'], 'staff_user_id' => $by]);
            if (is_wp_error($out)) { $j['errors'][] = 'wallet ' . $w['id'] . ': ' . $out->get_error_message(); continue; }
            $pw = TIX_Wallet_Balance::get_or_create($p['user_id'], $w['org']);
            $in = TIX_Wallet_Balance::post_tx(intval($pw['id']), ['type' => 'adjust', 'amount_paid' => $w['paid'], 'amount_bonus' => $w['bonus'], 'channel' => 'admin', 'allow_locked' => true, 'meta' => $meta, 'organizer_id' => $w['org'], 'staff_user_id' => $by, 'ref_tx_id' => intval($out['id'])]);
            $j['wallets'][] = ['from' => $w['id'], 'to' => intval($pw['id']), 'org' => $w['org'], 'paid' => $w['paid'], 'bonus' => $w['bonus'], 'tx_out' => intval($out['id']), 'tx_in' => is_wp_error($in) ? 0 : intval($in['id'])];
        }
        // Prämien und Listen (Benutzer-Meta): vorher-Stände beider Konten sichern
        if ($p['user_id'] && $s['user_id']) {
            foreach ($inv['loyalty'] as $k => $v) {
                $pv = get_user_meta($p['user_id'], $k, true);
                $j['user_meta'][$k] = ['p' => metadata_exists('user', $p['user_id'], $k) ? $pv : null, 's' => $v];
                if (strpos($k, '_tix_loyalty_points') === 0) $nv = intval($pv) + intval($v);
                elseif (is_array($v)) { $pa = is_array($pv) ? $pv : []; $nv = strpos($k, '_tix_loyalty_log') === 0 ? array_slice(array_merge($pa, $v), -40) : array_values(array_unique(array_merge($pa, $v), SORT_REGULAR)); }
                else $nv = $pv;
                update_user_meta($p['user_id'], $k, $nv); delete_user_meta($s['user_id'], $k);
            }
            foreach ($inv['lists'] as $k => $v) {
                $pv = get_user_meta($p['user_id'], $k, true);
                $j['user_meta'][$k] = ['p' => metadata_exists('user', $p['user_id'], $k) ? $pv : null, 's' => $v];
                $pa = is_array($pv) ? $pv : []; $va = is_array($v) ? $v : [];
                $nv = ($pa && array_keys($pa) !== range(0, count($pa) - 1)) || ($va && array_keys($va) !== range(0, count($va) - 1))
                    ? $pa + $va : array_values(array_unique(array_merge($pa, $va), SORT_REGULAR));
                update_user_meta($p['user_id'], $k, $nv); delete_user_meta($s['user_id'], $k);
            }
        }
        // Konten: Zweitkonto sperren, ggf. E-Mail tauschen, Zweit-E-Mails merken
        if ($p['user_id']) {
            $sec = get_user_meta($p['user_id'], self::META_SECONDARY, true);
            $j['secondary_emails_before'] = is_array($sec) ? $sec : null;
            $sec = is_array($sec) ? $sec : [];
            if ($s['user_id']) {
                $su = get_userdata($s['user_id']);
                $j['s_account'] = ['email' => (string) $su->user_email, 'pass' => (string) $su->user_pass, 'roles' => array_values((array) $su->roles), 'name' => (string) $su->display_name];
                $archived = 'merged_' . time() . '_' . $su->user_email;
                $wpdb->update($wpdb->users, ['user_email' => mb_substr($archived, 0, 100), 'user_pass' => wp_hash_password(wp_generate_password(32))], ['ID' => $s['user_id']]);
                $su->set_role('');
                update_user_meta($s['user_id'], self::META_INTO, $p['user_id']);
                clean_user_cache($s['user_id']);
                do_action('profile_update', $s['user_id'], $su);
            }
            if ($keep_email === 'secondary') {
                $pu = get_userdata($p['user_id']);
                $j['p_email_before'] = (string) $pu->user_email;
                $wpdb->update($wpdb->users, ['user_email' => $s['email']], ['ID' => $p['user_id']]);
                $sec[] = (string) $pu->user_email;
            } else {
                $sec[] = $s['email'];
            }
            update_user_meta($p['user_id'], self::META_SECONDARY, array_values(array_unique(array_map('strtolower', $sec))));
            clean_user_cache($p['user_id']);
            do_action('profile_update', $p['user_id'], get_userdata($p['user_id']));
        }
        $moved = self::counts($inv);
        $sc = (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) ? ['scope' => 'platform', 'oid' => 0] : ['scope' => 'site', 'oid' => 0];
        $audit = class_exists('TIX_Customers') ? TIX_Customers::audit('customer_merge', $p['id'], $sc, ['primary' => $p['id'], 'secondary' => $s['id'], 'keep_email' => $keep_email, 'moved' => $moved, 'by' => $by]) : 0;
        $until = gmdate('Y-m-d H:i:s', time() + self::UNDO_DAYS * DAY_IN_SECONDS);
        $wpdb->insert(self::table(), ['audit_id' => intval($audit), 'created_at' => current_time('mysql', true), 'by_user' => intval($by), 'primary_id' => $p['id'], 'secondary_id' => $s['id'],
            'primary_user' => $p['user_id'], 'secondary_user' => $s['user_id'], 'keep_email' => $keep_email, 'moved' => wp_json_encode($moved), 'journal' => wp_json_encode($j), 'undo_until' => $until]);
        self::mirror_touch($orders_touched, [$p['user_id'], $s['user_id']]);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) foreach (array_slice(array_unique($orders_touched), 0, 200) as $oid) TIX_Order_Admin::add_note($oid, 'Kundenkonten zusammengeführt (' . $s['id'] . ' → ' . $p['id'] . ').', 'system');
        return ['ok' => true, 'moved' => $moved, 'audit_id' => intval($audit), 'undo_until' => gmdate('c', strtotime($until . ' UTC')), 'errors' => $j['errors'] ?? []];
    }

    private static function mirror_touch(array $orders, array $users) {
        if (!class_exists('TIX_Mirror') || !method_exists('TIX_Mirror', 'touch')) return;
        foreach (array_unique(array_map('intval', $orders)) as $oid) if ($oid) TIX_Mirror::touch('order', $oid);
    }

    // ═══════════════ Rückgängig ═══════════════

    private static function record($audit_id) {
        global $wpdb;
        if (!self::ready()) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE audit_id = %d", intval($audit_id)), ARRAY_A);
        return $r ?: null;
    }

    /** Was einem Rückgängig im Weg steht (rein bis auf Lesezugriffe): [{area, detail}] */
    public static function undo_conflicts(array $j) {
        global $wpdb;
        $c = [];
        foreach ((array) ($j['wallets'] ?? []) as $w) {
            $pw = TIX_Wallet_Balance::get_wallet($w['to']);
            if (!$pw || intval($pw['balance_paid']) < $w['paid'] || intval($pw['balance_bonus']) < $w['bonus']) $c[] = ['area' => 'wallet', 'detail' => 'Das übertragene Guthaben wurde inzwischen ausgegeben.'];
        }
        if (!empty($j['user_meta']) && !empty($j['p']['user_id'])) foreach ($j['user_meta'] as $k => $v) {
            if (strpos($k, '_tix_loyalty_points') !== 0) continue;
            if (intval(get_user_meta($j['p']['user_id'], $k, true)) < intval($v['s'])) $c[] = ['area' => 'loyalty', 'detail' => 'Übertragene Prämienpunkte wurden inzwischen eingelöst.'];
        }
        foreach ((array) ($j['postmeta'] ?? []) as $k => $m) {
            if (!$m['ids']) continue;
            $now = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id IN (" . implode(',', array_map('intval', $m['ids'])) . ")");
            if (count($now) !== count($m['ids']) || array_filter($now, function ($v) use ($m) { return strtolower($v) !== strtolower($m['new']); })) {
                $c[] = ['area' => $k === 'ticket_owner' ? 'tickets' : 'support', 'detail' => $k === 'ticket_owner' ? 'Ein übertragenes Ticket wurde inzwischen umgeschrieben.' : 'Eine Support-Anfrage wurde inzwischen geändert.'];
            }
        }
        if (!empty($j['s_account']) && !empty($j['s']['user_id'])) {
            $taken = get_user_by('email', $j['s_account']['email']);
            if ($taken && intval($taken->ID) !== intval($j['s']['user_id']) && !($j['keep_email'] === 'secondary' && intval($taken->ID) === intval($j['p']['user_id']))) $c[] = ['area' => 'account', 'detail' => 'Die E-Mail des Zweitkontos gehört inzwischen einem anderen Konto.'];
        }
        return $c;
    }

    public static function undo($audit_id, $by = 0) {
        global $wpdb;
        $r = self::record($audit_id);
        if (!$r) return new WP_Error('tix_not_found', 'Zusammenführung nicht gefunden.', ['status' => 404]);
        if ($r['undone_at']) return new WP_Error('tix_merge_undone', 'Schon rückgängig gemacht.', ['status' => 409]);
        if (strtotime($r['undo_until'] . ' UTC') < time() || !$r['journal']) return new WP_Error('tix_merge_undo_expired', 'Rückgängig ist nur ' . self::UNDO_DAYS . ' Tage lang möglich.', ['status' => 409]);
        $j = json_decode((string) $r['journal'], true);
        if (!is_array($j)) return new WP_Error('tix_merge_undo_expired', 'Für diese Zusammenführung gibt es keine Rückgängig-Daten mehr.', ['status' => 409]);
        if ($c = self::undo_conflicts($j)) return new WP_Error('tix_merge_undo_conflict', 'Rückgängig ist nicht mehr vollständig möglich – es wurde nichts geändert.', ['status' => 409, 'conflicts' => $c]);
        $o = $wpdb->prefix . 'tix_orders';
        $p = $j['p']; $s = $j['s'];
        $orders = [];
        if (!empty($j['orders_customer'])) { $wpdb->query($wpdb->prepare("UPDATE $o SET customer_id = %d WHERE id IN (" . implode(',', array_map('intval', $j['orders_customer'])) . ")", intval($s['user_id']))); $orders = array_merge($orders, $j['orders_customer']); }
        if (!empty($j['orders_customer_from0'])) { $wpdb->query("UPDATE $o SET customer_id = 0 WHERE id IN (" . implode(',', array_map('intval', $j['orders_customer_from0'])) . ")"); $orders = array_merge($orders, $j['orders_customer_from0']); }
        if (!empty($j['orders_email'])) { $wpdb->query($wpdb->prepare("UPDATE $o SET billing_email = %s WHERE id IN (" . implode(',', array_map('intval', $j['orders_email']['ids'])) . ")", $j['orders_email']['old'])); $orders = array_merge($orders, $j['orders_email']['ids']); }
        foreach ((array) ($j['postmeta'] ?? []) as $m) if ($m['ids']) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id IN (" . implode(',', array_map('intval', $m['ids'])) . ")", $m['old']));
        foreach ((array) ($j['rows'] ?? []) as $t => $m) if ($m['ids']) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}$t SET {$m['col']} = %s WHERE id IN (" . implode(',', array_map('intval', $m['ids'])) . ")", $m['old']));
        if (!empty($j['mail_follow_moved']) || !empty($j['mail_follow_deleted'])) {
            $mt = $wpdb->prefix . 'tix_mail_followers';
            foreach ((array) ($j['mail_follow_moved'] ?? []) as $m) $wpdb->update($mt, ['email' => $m['email'], 'user_id' => $m['user_id']], ['id' => $m['id']]);
            foreach ((array) ($j['mail_follow_deleted'] ?? []) as $row) $wpdb->insert($mt, $row);
        }
        if (!empty($j['song_requests'])) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}tix_song_requests SET user_id = %d WHERE id IN (" . implode(',', array_map('intval', $j['song_requests'])) . ")", intval($s['user_id'])));
        foreach ((array) ($j['wallets'] ?? []) as $w) {
            $meta = ['reason' => 'customer_merge_undo', 'audit_id' => intval($audit_id)];
            $out = TIX_Wallet_Balance::post_tx($w['to'], ['type' => 'adjust', 'amount_paid' => -$w['paid'], 'amount_bonus' => -$w['bonus'], 'channel' => 'admin', 'allow_locked' => true, 'meta' => $meta, 'organizer_id' => $w['org'], 'staff_user_id' => $by]);
            if (!is_wp_error($out)) TIX_Wallet_Balance::post_tx($w['from'], ['type' => 'adjust', 'amount_paid' => $w['paid'], 'amount_bonus' => $w['bonus'], 'channel' => 'admin', 'allow_locked' => true, 'meta' => $meta, 'organizer_id' => $w['org'], 'staff_user_id' => $by, 'ref_tx_id' => intval($out['id'])]);
        }
        if (!empty($p['user_id']) && !empty($s['user_id'])) foreach ((array) ($j['user_meta'] ?? []) as $k => $v) {
            if ($v['p'] === null) delete_user_meta($p['user_id'], $k); else update_user_meta($p['user_id'], $k, $v['p']);
            update_user_meta($s['user_id'], $k, $v['s']);
        }
        if (!empty($p['user_id'])) {
            if ($j['keep_email'] === 'secondary' && !empty($j['p_email_before'])) $wpdb->update($wpdb->users, ['user_email' => $j['p_email_before']], ['ID' => $p['user_id']]);
            if ($j['secondary_emails_before'] === null) delete_user_meta($p['user_id'], self::META_SECONDARY); else update_user_meta($p['user_id'], self::META_SECONDARY, $j['secondary_emails_before']);
            clean_user_cache($p['user_id']);
            do_action('profile_update', $p['user_id'], get_userdata($p['user_id']));
        }
        if (!empty($j['s_account']) && !empty($s['user_id'])) {
            $wpdb->update($wpdb->users, ['user_email' => $j['s_account']['email'], 'user_pass' => $j['s_account']['pass']], ['ID' => $s['user_id']]);
            $su = new WP_User($s['user_id']);
            foreach ((array) $j['s_account']['roles'] as $i => $role) { if ($i === 0) $su->set_role($role); else $su->add_role($role); }
            delete_user_meta($s['user_id'], self::META_INTO);
            clean_user_cache($s['user_id']);
            do_action('profile_update', $s['user_id'], get_userdata($s['user_id']));
        }
        $wpdb->update(self::table(), ['undone_at' => current_time('mysql', true), 'undone_by' => intval($by)], ['id' => intval($r['id'])]);
        if (class_exists('TIX_Customers')) TIX_Customers::audit('customer_merge_undo', (string) $p['id'], ['scope' => (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) ? 'platform' : 'site', 'oid' => 0], ['audit_id' => intval($audit_id), 'by' => $by]);
        self::mirror_touch($orders, [$p['user_id'], $s['user_id']]);
        return ['ok' => true, 'restored' => json_decode((string) $r['moved'], true)];
    }

    /** Nach Ablauf der Rückgängig-Frist: Journal (alte E-Mails, Passwort-Hash) löschen, Zahlen bleiben. */
    public static function cleanup() {
        global $wpdb;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) return;
        $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET journal = NULL WHERE journal IS NOT NULL AND undo_until < %s", current_time('mysql', true)));
    }

    // ═══════════════ App: gesperrtes Zweitkonto ═══════════════

    public static function block_merged($err) {
        if (!empty($err)) return $err;
        $uid = get_current_user_id();
        if (!$uid) return $err;
        $into = intval(get_user_meta($uid, self::META_INTO, true));
        if (!$into) return $err;
        $pu = get_userdata($into);
        return new WP_Error('tix_account_merged', 'Dieses Konto wurde zusammengeführt. Bitte mit ' . ($pu ? $pu->user_email : 'dem Hauptkonto') . ' anmelden.', ['status' => 401, 'login_email' => $pu ? (string) $pu->user_email : null]);
    }

    // ═══════════════ Kandidaten ═══════════════

    /** Mögliche Doppelte im Bereich: gleicher Name (verschiedene Kunden), gleiche Telefonnummer. [{a, b, reason}] */
    public static function candidates($q = '', $limit = 50) {
        global $wpdb;
        $o = $wpdb->prefix . 'tix_orders';
        $like = $q !== '' ? $wpdb->prepare(' AND (billing_email LIKE %s OR CONCAT(billing_first_name, " ", billing_last_name) LIKE %s)', '%' . $wpdb->esc_like($q) . '%', '%' . $wpdb->esc_like($q) . '%') : '';
        $rows = $wpdb->get_results("SELECT LOWER(TRIM(CONCAT(billing_first_name, ' ', billing_last_name))) n, REPLACE(REPLACE(billing_phone, ' ', ''), '/', '') ph, billing_email e, MAX(customer_id) u FROM $o WHERE billing_email <> ''$like GROUP BY billing_email ORDER BY MAX(id) DESC LIMIT 5000");
        $id = function ($r) { return intval($r->u) ? 'u' . intval($r->u) : TIX_Customers::guest_id($r->e); };
        $by = ['name' => [], 'phone' => []];
        foreach ((array) $rows as $r) {
            if (mb_strlen($r->n) >= 5) $by['name'][$r->n][$id($r)] = $r;
            if (strlen(preg_replace('/\D/', '', (string) $r->ph)) >= 8) $by['phone'][preg_replace('/\D/', '', (string) $r->ph)][$id($r)] = $r;
        }
        $out = []; $seen = [];
        foreach ($by as $reason => $groups) foreach ($groups as $g) {
            if (count($g) < 2) continue;
            $ids = array_keys($g);
            for ($i = 1; $i < count($ids); $i++) {
                $k = min($ids[0], $ids[$i]) . '|' . max($ids[0], $ids[$i]);
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $a = $g[$ids[0]]; $b = $g[$ids[$i]];
                $out[] = ['a' => ['id' => $ids[0], 'email' => $a->e], 'b' => ['id' => $ids[$i], 'email' => $b->e], 'reason' => $reason === 'name' ? 'gleicher Name' : 'gleiche Telefonnummer'];
                if (count($out) >= $limit) return $out;
            }
        }
        return $out;
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        $read = ['permission_callback' => [__CLASS__, 'perm_read']];
        $exec = ['permission_callback' => [__CLASS__, 'perm_exec']];
        register_rest_route(self::NS, '/customers/merge/candidates', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_candidates']] + $read);
        register_rest_route(self::NS, '/customers/merge/preview', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_preview']] + $exec);
        register_rest_route(self::NS, '/customers/merge', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_merge']] + $exec);
        register_rest_route(self::NS, '/customers/merges', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list']] + $exec);
        register_rest_route(self::NS, '/customers/merge/(?P<audit>\d+)/undo', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_undo']] + $exec);
    }

    public static function perm_read(WP_REST_Request $req) {
        return class_exists('TIX_Customers') ? TIX_Customers::perm($req) : new WP_Error('rest_forbidden', 'Nicht verfügbar.', ['status' => 403]);
    }

    /** Ausführen: Einzel-Seite Admin/Inhaber; evendis nur Admins (Konten sind plattformweit, User 12.11.). */
    public static function perm_exec(WP_REST_Request $req) {
        $r = self::perm_read($req);
        if ($r !== true) return $r;
        if (current_user_can('manage_options')) return true;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return new WP_Error('rest_forbidden', 'Konten zusammenführen können auf evendis nur Admins.', ['status' => 403]);
        return in_array('tix_organizer', (array) wp_get_current_user()->roles, true) && !get_user_meta(get_current_user_id(), TIX_App_Scope::META_TEAM_ORG, true) ? true : new WP_Error('rest_forbidden', 'Nur Admin bzw. Inhaber.', ['status' => 403]);
    }

    public static function rest_candidates(WP_REST_Request $req) {
        return rest_ensure_response(['items' => self::candidates(sanitize_text_field((string) $req->get_param('q')))]);
    }

    private static function body(WP_REST_Request $req) {
        $b = $req->get_json_params();
        return is_array($b) ? $b : (array) $req->get_body_params();
    }

    public static function rest_preview(WP_REST_Request $req) {
        $b = self::body($req);
        return rest_ensure_response(self::preview((string) ($b['primary'] ?? ''), (string) ($b['secondary'] ?? '')));
    }

    public static function rest_merge(WP_REST_Request $req) {
        $b = self::body($req);
        $r = self::apply((string) ($b['primary'] ?? ''), (string) ($b['secondary'] ?? ''), (string) ($b['keep_email'] ?? 'primary'), (string) ($b['token'] ?? ''), get_current_user_id());
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }

    public static function rest_undo(WP_REST_Request $req) {
        $r = self::undo(absint($req['audit']), get_current_user_id());
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }

    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        self::ready();
        $per = 30; $page = max(1, intval($req->get_param('page') ?: 1));
        $t = self::table();
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $t"));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t ORDER BY id DESC LIMIT %d OFFSET %d", $per, ($page - 1) * $per), ARRAY_A);
        $items = [];
        foreach ((array) $rows as $r) {
            $j = json_decode((string) $r['journal'], true);
            $who = function ($x) { return is_array($x) ? ['id' => $x['id'], 'name' => $x['name'], 'email' => $x['email']] : null; };
            $p = is_array($j) ? $who($j['p']) : ['id' => $r['primary_id'], 'name' => null, 'email' => null];
            $s = is_array($j) ? $who($j['s']) : ['id' => $r['secondary_id'], 'name' => null, 'email' => null];
            $by = $r['by_user'] ? get_userdata(intval($r['by_user'])) : null;
            $can = !$r['undone_at'] && $r['journal'] && strtotime($r['undo_until'] . ' UTC') >= time();
            $items[] = ['audit_id' => intval($r['audit_id']), 'at' => gmdate('c', strtotime($r['created_at'] . ' UTC')), 'by' => $by ? (string) $by->display_name : null, 'primary' => $p, 'secondary' => $s,
                'moved' => json_decode((string) $r['moved'], true), 'undo_until' => gmdate('c', strtotime($r['undo_until'] . ' UTC')), 'undone_at' => $r['undone_at'] ? gmdate('c', strtotime($r['undone_at'] . ' UTC')) : null, 'can_undo' => $can];
        }
        return rest_ensure_response(['items' => $items, 'total' => $total, 'has_more' => $page * $per < $total]);
    }

    /** Für TIX_Customers: Zweit-E-Mails eines Kontos bzw. Hauptkonto eines gesperrten Zweitkontos. */
    public static function secondary_emails($uid) {
        $v = $uid ? get_user_meta($uid, self::META_SECONDARY, true) : [];
        return is_array($v) ? array_values($v) : [];
    }

    public static function merged_into($uid) {
        $into = $uid ? intval(get_user_meta($uid, self::META_INTO, true)) : 0;
        return $into ? 'u' . $into : null;
    }

    /** E-Mail → Konto über Zweit-E-Mails (für die Kundenliste). [email => user_id] */
    public static function secondary_map() {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META_SECONDARY)) as $r) {
            foreach ((array) maybe_unserialize($r->meta_value) as $e) $out[strtolower((string) $e)] = intval($r->user_id);
        }
        return $out;
    }
}
