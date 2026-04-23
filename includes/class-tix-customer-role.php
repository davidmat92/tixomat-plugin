<?php
/**
 * TIX Customer Role
 *
 * Eigene Rolle „Kunde" (customer) für Ticket-Käufer.
 * Unabhängig von WooCommerce — wird beim Aktivieren des Plugins angelegt.
 *
 * Permissions: minimal (kein Admin-Zugriff), aber erlaubt Tickets, Profil,
 * Bestellungen einzusehen. Kann später erweitert werden (Transfer, Wallet, etc.)
 */
if (!defined('ABSPATH')) exit;

class TIX_Customer_Role {

    /** Rollen-Key (intern in WP) */
    const ROLE_KEY = 'tix_customer';

    /** Rollen-Label (UI) */
    const ROLE_LABEL = 'Kunde';

    public static function init() {
        // Rolle sicherstellen (wird aus tixomat.php direkt auf init gecallt → safe).
        self::ensure_role();

        // Admin-Action: Backfill bestehender Käufer (nur explizit triggerbar)
        add_action('admin_post_tix_backfill_customers', [__CLASS__, 'handle_backfill_action']);
    }

    /** Plugin-Aktivierung: Rolle anlegen */
    public static function on_activate() {
        self::ensure_role();
    }

    /**
     * Legt die Rolle an (oder updated Capabilities falls nötig).
     * Wird auch auf init() aufgerufen, damit Updates an Caps durchkommen
     * ohne dass der User das Plugin deaktivieren/aktivieren muss.
     */
    public static function ensure_role() {
        $role = get_role(self::ROLE_KEY);
        $caps = self::default_capabilities();

        if (!$role) {
            add_role(self::ROLE_KEY, self::ROLE_LABEL, $caps);
            return;
        }

        // Capabilities synchronisieren — falls wir sie später erweitern
        foreach ($caps as $cap => $allow) {
            if ($allow) {
                if (!$role->has_cap($cap)) $role->add_cap($cap);
            }
        }
    }

    /**
     * Default-Capabilities für Kunde.
     * Basiert auf „subscriber" + custom tix-caps für zukünftige Erweiterungen.
     */
    private static function default_capabilities() {
        return [
            'read'                => true,
            // Zukunft: Tickets transferieren, Wallet nutzen, etc.
            'tix_read_own_orders'    => true,
            'tix_read_own_tickets'   => true,
            'tix_transfer_own_ticket'=> true,
        ];
    }

    /**
     * Weist einem User die customer-Rolle zu, wenn er noch kein Editor/Admin/Organizer o.ä. ist.
     * Rolle wird GESETZT (set_role), nicht nur hinzugefügt — damit aus „subscriber" → „tix_customer" wird.
     * Ausnahme: existierende Privilege-Rollen behalten (administrator, editor, author, tix_organizer, shop_manager).
     */
    public static function assign_to_user($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return false;

        $protected = ['administrator', 'editor', 'author', 'contributor', 'shop_manager', 'tix_organizer'];
        foreach ((array) $user->roles as $r) {
            if (in_array($r, $protected, true)) return false;
        }

        // Nicht-Privileg-Rolle (wahrscheinlich subscriber) → durch customer ersetzen
        $user->set_role(self::ROLE_KEY);
        return true;
    }

    /**
     * Einmal-Backfill: alle User, die als billing_email in tix_orders existieren,
     * auf die Kunden-Rolle heben (sofern sie nicht privilegiert sind).
     *
     * Idempotent — kann mehrfach laufen, updated nur was nötig.
     * @return array{updated:int, skipped:int, total:int}
     */
    public static function backfill_existing_customers() {
        global $wpdb;

        $t = $wpdb->prefix . 'tix_orders';

        // Prüfen ob tix_orders überhaupt existiert
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            return ['updated' => 0, 'skipped' => 0, 'total' => 0];
        }

        // Alle Billing-Emails aus tix_orders (unique)
        $emails = $wpdb->get_col("SELECT DISTINCT billing_email FROM $t WHERE billing_email IS NOT NULL AND billing_email != ''");
        $updated = 0;
        $skipped = 0;

        foreach ($emails as $email) {
            $user = get_user_by('email', $email);
            if (!$user) { $skipped++; continue; }

            if (self::assign_to_user($user->ID)) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'total'   => count($emails),
        ];
    }

    /**
     * Handler für Backfill via Admin-URL:
     * /wp-admin/admin-post.php?action=tix_backfill_customers&_wpnonce=...
     */
    public static function handle_backfill_action() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');
        check_admin_referer('tix_backfill_customers');

        $stats = self::backfill_existing_customers();

        $redirect = wp_get_referer() ?: admin_url('users.php');
        $redirect = add_query_arg([
            'tix_backfill_done' => 1,
            'updated' => $stats['updated'],
            'skipped' => $stats['skipped'],
            'total'   => $stats['total'],
        ], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }
}
