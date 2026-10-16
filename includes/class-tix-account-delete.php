<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Account_Delete – Kontolöschung (DSGVO Art. 17) für Website und App über einen gemeinsamen Kern (1.38.460).
 *
 * Bisher anonymisierte nur die Website (TIX_My_Account::ajax_delete_account) Bestellungen und Tickets; die App
 * (POST /auth/delete) löschte nur das Konto. Jetzt beide über delete():
 *   1. Bestellungen (tix_orders, WooCommerce HPOS; eigene über E-Mail oder Konto-ID; bezahlte siehe Aufbewahrung unten): Name, E-Mail, Telefon, Anschrift, Firma → „[Gelöscht]“ bzw.
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
 *
 * Aufbewahrung (1.38.461, User-Entscheid „B“, Option tix_delete_invoice_retention = 'retain' Vorgabe | 'anonymize'):
 *   Bezahlte Bestellungen (completed, processing, refunded, partially-refunded oder mit ausgestellter Rechnung
 *   _tix_invoice_<id>) behalten Rechnungsname, Firma und Anschrift bis zum Ende der Aufbewahrungsfrist
 *   (§147 Abs. 4 AO / §14b UStG: 10 Jahre ab Ende des Kalenderjahres der Bestellung → bis 31.12.(Jahr+10)).
 *   Sofort entfernt: E-Mail (steht nicht auf der Rechnung, §14 Abs. 4 UStG verlangt sie nicht; Lexoffice bekommt sie
 *   nicht), Telefon, Konto-Verknüpfung, Marketing-Einwilligung/Tracking-IDs der Bestellung. Die anonymisierte E-Mail ist
 *   zugleich die Markierung für den Cron tix_order_retention_expire (täglich), der nach Fristende Name/Anschrift leert.
 *   Unbezahlte/stornierte Bestellungen ohne Rechnung: sofort vollständig anonymisiert.
 */
class TIX_Account_Delete {

    const OPTION = 'tix_delete_invoice_retention';
    const CRON = 'tix_order_retention_expire';
    const PAID = ['completed', 'processing', 'refunded', 'partially-refunded'];
    const ANON_NAME = '[Gelöscht]';

    public static function init() {
        add_action(self::CRON, [__CLASS__, 'expire']);
        add_action('init', function () {
            if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
        });
    }

    /** 'retain' (Rechnungsdaten bis Fristende behalten, Vorgabe) oder 'anonymize' (alles sofort). */
    public static function mode() {
        return get_option(self::OPTION, 'retain') === 'anonymize' ? 'anonymize' : 'retain';
    }

    public static function years() {
        return max(1, intval(apply_filters('tix_invoice_retention_years', 10)));
    }

    /** Aufbewahrt bis (Y-m-d) für ein Bestelldatum. */
    public static function retained_until($date_created) {
        $y = intval(substr((string) $date_created, 0, 4)) ?: intval(current_time('Y'));
        return ($y + self::years()) . '-12-31';
    }

    private static function has_invoice($order_id) {
        $m = get_option('_tix_invoice_' . intval($order_id), null);
        return is_array($m) && (($m['invoice_number'] ?? '') !== '' || ($m['invoice_id'] ?? '') !== '') && ($m['status'] ?? '') !== 'error';
    }

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

        // 1. Native Bestellungen (eigene über E-Mail oder Konto-ID)
        $retain = self::mode() === 'retain';
        $retained = [];
        $full = ['billing_email' => $anon_email, 'billing_first_name' => $anon_name, 'billing_last_name' => '', 'billing_phone' => '',
            'billing_address_1' => '', 'billing_address_2' => '', 'billing_city' => '', 'billing_postcode' => '', 'billing_company' => '', 'customer_id' => 0];
        $t = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, order_number, status, date_created FROM $t WHERE billing_email = %s OR customer_id = %d", $email, $uid));
            foreach ((array) $rows as $r) {
                $oid = intval($r->id);
                if ($retain && (in_array($r->status, self::PAID, true) || self::has_invoice($oid))) {
                    $wpdb->update($t, ['billing_email' => $anon_email, 'billing_phone' => '', 'customer_id' => 0], ['id' => $oid]);
                    $retained[] = ['order_id' => $oid, 'order_number' => (string) $r->order_number, 'retained_until' => self::retained_until($r->date_created)];
                } else {
                    $wpdb->update($t, $full, ['id' => $oid]);
                }
                // Marketing-Einwilligung und Tracking-IDs der Bestellung (Meta-Pixel) sofort weg
                foreach (['_tix_meta_consent', '_tix_meta_fbp', '_tix_meta_fbc'] as $mk) delete_post_meta($oid, $mk);
                $orders++;
            }
        }
        // WooCommerce (HPOS), falls noch vorhanden – gleiche Regel
        $wc_t = $wpdb->prefix . 'wc_orders';
        $wc_a = $wpdb->prefix . 'wc_order_addresses';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t) {
            $wc = $wpdb->get_results($wpdb->prepare("SELECT id, status, date_created_gmt FROM $wc_t WHERE billing_email = %s OR customer_id = %d", $email, $uid));
            foreach ((array) $wc as $r) {
                $oid = intval($r->id);
                $keep = $retain && in_array(preg_replace('/^wc-/', '', (string) $r->status), self::PAID, true);
                $wpdb->update($wc_t, ['billing_email' => $anon_email, 'customer_id' => 0], ['id' => $oid]);
                if ($keep) {
                    $wpdb->query($wpdb->prepare("UPDATE $wc_a SET email=%s, phone='' WHERE address_type='billing' AND order_id=%d", $anon_email, $oid));
                    $retained[] = ['order_id' => $oid, 'order_number' => 'WC-' . $oid, 'retained_until' => self::retained_until($r->date_created_gmt)];
                } else {
                    $wpdb->query($wpdb->prepare("UPDATE $wc_a SET email=%s, first_name=%s, last_name='', company='', phone='', address_1='', address_2='', city='', postcode='', state='' WHERE address_type='billing' AND order_id=%d", $anon_email, $anon_name, $oid));
                }
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
        $log[] = ['deleted_at' => gmdate('c'), 'anon_hash' => $anon_hash, 'source' => $source, 'orders' => $orders, 'retained' => count($retained), 'tickets' => count($tickets), 'support' => count($sp)];
        if (count($log) > 500) $log = array_slice($log, -500);
        update_option('tix_deleted_accounts_log', $log, false);

        $info = ['anon_hash' => $anon_hash, 'source' => $source, 'orders' => $orders, 'tickets' => count($tickets), 'support' => count($sp),
            'retention' => $retain ? 'retain' : 'anonymize', 'retained' => $retained];
        /** Konto ist gelöscht und anonymisiert (z. B. Datenspiegel: Verlauf mit Personenbezug tilgen). */
        do_action('tix_account_deleted', $uid, $account_email, $info);
        return $info;
    }

    /** Cron: nach Fristende Rechnungsname/Anschrift aufbewahrter Bestellungen gelöschter Konten leeren. */
    public static function expire() {
        global $wpdb;
        $cut = (intval(current_time('Y')) - self::years()) . '-01-01 00:00:00';   // Bestellung im Jahr Y: bis 31.12.(Y+10), ab 1.1.(Y+11) leeren
        $n = 0;
        $t = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $n += intval($wpdb->query($wpdb->prepare("UPDATE $t SET billing_first_name=%s, billing_last_name='', billing_company='', billing_address_1='', billing_address_2='', billing_city='', billing_postcode='', billing_phone=''
                WHERE billing_email LIKE %s AND billing_first_name <> %s AND date_created < %s", self::ANON_NAME, '%@removed.invalid', self::ANON_NAME, $cut)));
        }
        $wc_t = $wpdb->prefix . 'wc_orders';
        $wc_a = $wpdb->prefix . 'wc_order_addresses';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t) {
            $n += intval($wpdb->query($wpdb->prepare("UPDATE $wc_a a JOIN $wc_t o ON o.id = a.order_id SET a.first_name=%s, a.last_name='', a.company='', a.address_1='', a.address_2='', a.city='', a.postcode='', a.state='', a.phone=''
                WHERE a.address_type='billing' AND o.billing_email LIKE %s AND a.first_name <> %s AND o.date_created_gmt < %s", self::ANON_NAME, '%@removed.invalid', self::ANON_NAME, $cut)));
        }
        if ($n) do_action('tix_order_retention_expired', $n);
        return $n;
    }
}
