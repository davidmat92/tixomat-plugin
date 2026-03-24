<?php
/**
 * TIX Gateway: Banküberweisung (Vorkasse)
 * Bestellung wird als "pending" erstellt, Tickets nach manuellem Zahlungseingang.
 */
if (!defined('ABSPATH')) exit;

class TIX_Gateway_Bank {

    public static function get_id()    { return 'bank'; }
    public static function get_title() { return 'Banküberweisung (Vorkasse)'; }
    public static function get_icon()  { return ''; }

    public static function is_available() {
        $s = tix_get_settings();
        return !empty($s['bank_transfer_enabled']) && !empty($s['bank_iban']);
    }

    /**
     * Bestellung als "on-hold" markieren — Tickets werden erst bei Zahlungseingang erstellt
     */
    public static function process($order_id) {
        TIX_Native_Checkout::update_order_status($order_id, 'on-hold', 'bank');

        // Bankdaten in Order-Meta speichern für Thank-You Seite
        global $wpdb;
        $s = tix_get_settings();
        $wpdb->update(
            $wpdb->prefix . 'tix_orders',
            [
                'payment_method'       => 'bank',
                'payment_method_title' => 'Banküberweisung',
            ],
            ['id' => $order_id]
        );

        return ['success' => true];
    }
}
