<?php
/**
 * TIX Gateway: Free (0€ Orders)
 * Verarbeitet kostenlose Bestellungen sofort ohne Zahlungsanbieter.
 */
if (!defined('ABSPATH')) exit;

class TIX_Gateway_Free {

    public static function get_id()    { return 'free'; }
    public static function get_title() { return 'Kostenlos'; }
    public static function get_icon()  { return ''; }

    public static function is_available() {
        return true; // Immer verfügbar wenn Gesamtbetrag = 0
    }

    /**
     * Verarbeite eine 0€-Bestellung.
     * @param int $order_id TIX Order ID
     * @return array ['success' => true]
     */
    public static function process($order_id) {
        TIX_Native_Checkout::update_order_status($order_id, 'completed', 'free');
        return ['success' => true];
    }
}
