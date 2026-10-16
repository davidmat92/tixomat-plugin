<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Account_Delete – Kontolöschung (DSGVO Art. 17) für Website und App über einen gemeinsamen Kern (1.38.460).
 *
 * Bisher anonymisierte nur die Website (TIX_My_Account::ajax_delete_account) Bestellungen und Tickets; die App
 * (POST /auth/delete) löschte nur das Konto. Jetzt beide über delete():
 *   1. Bestellungen (tix_orders, WooCommerce HPOS): Name, E-Mail, Telefon, Anschrift, Firma → „[Gelöscht]“ bzw.
 *      gelöscht-<hash>@removed.invalid, Konto-Verknüpfung (customer_id) → 0. Beträge, Datum, Status, Positionen
 *      (Event, Kategorie, Preis), Bestellnummer, Zahlart bleiben.
 *   2. Tickets (tix_ticket): Inhaber-Name/-E-Mail anonymisiert; Code, Status, Check-in bleiben → gültige Tickets
 *      funktionieren am Einlass weiter (auch kommende; keine Sonderregel).
 *   3. Ticket-Spiegel-Tabelle {prefix}tixomat_tickets: Käuferdaten anonymisiert (neu; Code/Status/Check-in bleiben).
 *   4. Support-Anfragen (alle Status, bisher fielen offene/geschlossene durch): E-Mail, Name anonymisiert.  5. Newsletter-Abos gelöscht.
 *   6. Profilbild-Datei, App-Tokens, Konto + Nutzer-Meta (wp_delete_user; Folgen per Mail, Guthaben usw. hängen an deleted_user).
 *   7. Protokoll tix_deleted_accounts_log (ohne Personenbezug). Danach do_action('tix_account_deleted', $user_id, $email, $info).
 * Zuordnung per E-Mail nur mit bestätigter E-Mail (TIX_App_Account::match_email), sonst nur über die Konto-ID.
 * Rechte (wer löschen darf) prüft der Aufrufer.
 */
class TIX_Account_Delete {

    /** @return array{anon_hash:string,orders:int,tickets:int,support:int}|WP_Error */
    public static function delete($user_id, $source = 'web') {
        global $wpdb;
        $user = get_userdata(intval($user_id));
        if (!$user) return new WP_Error('tix_delete_failed', 'Konto nicht gefunden.', ['status' => 404]);
        $uid = intval($user->ID);
        // Offenes Guthaben: je nach Richtlinie verweigern (TIX_Wallet_Balance), sonst anonymisiert der Guthaben-Teil selbst
        if (class_exists('TIX_Wallet_Balance') && method_exists('TIX_Wallet_Balance', 'delete_block_error') && ($werr = TIX_Wallet_Balance::delete_block_error($uid))) return $werr;

        $account_email = (string) $user->user_email;
        $email = class_exists('TIX_App_Account') ? TIX_App_Account::match_email($user) : $account_email;
        $anon_hash = substr(hash('sha256', $email . wp_generate_password(32, true)), 0, 12);
        $anon_email = 'gelöscht-' . $anon_hash . '@removed.invalid';
        $anon_name = '[Gelöscht]';
        $orders = 0;
        // Alle Status (auch eigene wie tix_open/tix_closed der Anfragen und Papierkorb) – 'any' lässt exclude_from_search-Status aus
        $all = array_values(get_post_stati());

        // 1. Native Bestellungen
        $t = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $orders += intval($wpdb->update($t, [
                'billing_email' => $anon_email, 'billing_first_name' => $anon_name, 'billing_last_name' => '', 'billing_phone' => '',
                'billing_address_1' => '', 'billing_address_2' => '', 'billing_city' => '', 'billing_postcode' => '', 'billing_company' => '', 'customer_id' => 0,
            ], ['billing_email' => $email]));
            $orders += intval($wpdb->update($t, ['customer_id' => 0], ['customer_id' => $uid]));
        }
        // WooCommerce (HPOS), falls noch vorhanden
        $wc_t = $wpdb->prefix . 'wc_orders';
        $wc_a = $wpdb->prefix . 'wc_order_addresses';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t) {
            $wc_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $wc_t WHERE billing_email = %s OR customer_id = %d", $email, $uid));
            if ($wc_ids) {
                $wpdb->update($wc_t, ['billing_email' => $anon_email, 'customer_id' => 0], ['billing_email' => $email]);
                $wpdb->update($wc_t, ['customer_id' => 0], ['customer_id' => $uid]);
                $ph = implode(',', array_fill(0, count($wc_ids), '%d'));
                $wpdb->query($wpdb->prepare("UPDATE $wc_a SET email=%s, first_name=%s, last_name=%s, phone=%s, address_1=%s, address_2=%s, city=%s, postcode=%s, state=%s WHERE address_type='billing' AND order_id IN ($ph)",
                    ...array_merge([$anon_email, $anon_name, '', '', '', '', '', '', ''], array_map('intval', $wc_ids))));
            }
        }

        // 2. Tickets (Codes und Check-in bleiben)
        $tickets = get_posts(['post_type' => 'tix_ticket', 'posts_per_page' => -1, 'post_status' => $all, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_ticket_owner_email', 'value' => $email]]]);
        foreach ($tickets as $tid) {
            update_post_meta($tid, '_tix_ticket_owner_email', $anon_email);
            update_post_meta($tid, '_tix_ticket_owner_name', $anon_name);
            delete_post_meta($tid, '_tix_ticket_owner_first_name');
            delete_post_meta($tid, '_tix_ticket_owner_last_name');
        }
        // 3. Ticket-Spiegel-Tabelle
        if (class_exists('TIX_Ticket_DB') && TIX_Ticket_DB::table_exists()) {
            $tt = $wpdb->prefix . TIX_Ticket_DB::TABLE_SUFFIX;
            $wpdb->update($tt, ['buyer_name' => $anon_name, 'buyer_email' => $anon_email, 'buyer_phone' => '', 'buyer_company' => '', 'buyer_city' => '', 'buyer_zip' => ''], ['buyer_email' => $email]);
        }

        // 4. Support-Anfragen
        $sp = get_posts(['post_type' => 'tix_support_ticket', 'posts_per_page' => -1, 'post_status' => $all, 'fields' => 'ids', 'meta_key' => '_tix_sp_email', 'meta_value' => $email]);
        foreach ($sp as $sid) {
            update_post_meta($sid, '_tix_sp_email', $anon_email);
            update_post_meta($sid, '_tix_sp_name', $anon_name);
        }
        // 5. Newsletter
        $subs = get_posts(['post_type' => 'tix_subscriber', 'posts_per_page' => -1, 'post_status' => $all, 'fields' => 'ids', 'meta_key' => '_tix_sub_email', 'meta_value' => $email]);
        foreach ($subs as $s) wp_delete_post($s, true);

        // 6. Profilbild-Datei (außerhalb der Mediathek), Tokens, Konto
        $file = basename((string) get_user_meta($uid, '_tix_avatar_file', true));
        if ($file !== '' && $file !== '.' && $file !== '..') {
            $path = trailingslashit(wp_get_upload_dir()['basedir']) . 'tix-avatars/' . $file;
            if (is_file($path)) @unlink($path);
        }
        delete_user_meta($uid, '_tix_app_tokens');
        delete_user_meta($uid, '_tix_app_token');
        if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
        if (!wp_delete_user($uid)) return new WP_Error('tix_delete_failed', 'Konto konnte nicht gelöscht werden.', ['status' => 500]);

        // 7. Protokoll ohne Personenbezug
        $log = get_option('tix_deleted_accounts_log', []);
        $log = is_array($log) ? $log : [];
        $log[] = ['deleted_at' => gmdate('c'), 'anon_hash' => $anon_hash, 'source' => $source, 'orders' => $orders, 'tickets' => count($tickets), 'support' => count($sp)];
        if (count($log) > 500) $log = array_slice($log, -500);
        update_option('tix_deleted_accounts_log', $log, false);

        $info = ['anon_hash' => $anon_hash, 'source' => $source, 'orders' => $orders, 'tickets' => count($tickets), 'support' => count($sp)];
        /** Konto ist gelöscht und anonymisiert (z. B. Datenspiegel: Verlauf mit Personenbezug tilgen). */
        do_action('tix_account_deleted', $uid, $account_email, $info);
        return $info;
    }
}
