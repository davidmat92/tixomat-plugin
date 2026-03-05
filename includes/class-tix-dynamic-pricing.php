<?php
/**
 * Tixomat – Dynamic Phase Pricing
 *
 * Stellt sicher, dass WooCommerce-Produktpreise IMMER den aktuellen
 * Phasenpreis widerspiegeln, auch wenn der Event-Sync nicht kürzlich lief.
 * So greifen Phasen und Gruppenrabatt korrekt zusammen.
 */
if (!defined('ABSPATH')) exit;

class TIX_Dynamic_Pricing {

    /** Cache: product_id => ['price' => float, 'sale' => float|null, 'regular' => float] */
    private static $cache = [];

    public static function init() {
        // Preisfilter – nur im Frontend und bei AJAX, nicht im Admin
        if (is_admin() && !defined('DOING_AJAX')) return;

        add_filter('woocommerce_product_get_price',         [__CLASS__, 'get_price'], 99, 2);
        add_filter('woocommerce_product_get_sale_price',    [__CLASS__, 'get_sale_price'], 99, 2);
        add_filter('woocommerce_product_get_regular_price', [__CLASS__, 'get_regular_price'], 99, 2);

        // Variation-Preise (falls später benötigt)
        add_filter('woocommerce_product_variation_get_price',         [__CLASS__, 'get_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_sale_price',    [__CLASS__, 'get_sale_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [__CLASS__, 'get_regular_price'], 99, 2);
    }

    /**
     * Effektiven Preis zurückgeben (Sale-Preis wenn Phase aktiv, sonst Original)
     */
    public static function get_price($price, $product) {
        $data = self::resolve($product->get_id());
        if (!$data) return $price;
        return $data['price'];
    }

    /**
     * Sale-Preis zurückgeben (Phase-Preis wenn aktiv)
     */
    public static function get_sale_price($price, $product) {
        $data = self::resolve($product->get_id());
        if (!$data) return $price;
        return $data['sale'] !== null ? (string)$data['sale'] : '';
    }

    /**
     * Regulären Preis zurückgeben
     */
    public static function get_regular_price($price, $product) {
        $data = self::resolve($product->get_id());
        if (!$data) return $price;
        return $data['regular'];
    }

    /**
     * Phase-Preis für ein Produkt auflösen (mit Cache)
     *
     * @return array|null ['price' => float, 'sale' => float|null, 'regular' => float]
     */
    private static function resolve($product_id) {
        // Cache-Hit
        if (array_key_exists($product_id, self::$cache)) {
            return self::$cache[$product_id];
        }

        // Ist es ein Tixomat-Produkt?
        $event_id = get_post_meta($product_id, '_tix_parent_event_id', true);
        if (!$event_id) {
            self::$cache[$product_id] = null;
            return null;
        }

        // Ticket-Kategorien des Events laden
        $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($categories)) {
            self::$cache[$product_id] = null;
            return null;
        }

        // Passende Kategorie finden
        $cat = null;
        foreach ($categories as $c) {
            if (intval($c['product_id'] ?? 0) === intval($product_id)) {
                $cat = $c;
                break;
            }
        }

        if (!$cat) {
            self::$cache[$product_id] = null;
            return null;
        }

        $regular = floatval($cat['price']);
        $raw_sale = $cat['sale_price'] ?? '';
        $sale    = ($raw_sale !== '' && $raw_sale !== null) ? floatval($raw_sale) : null;

        // Aktive Phase prüfen
        if (class_exists('TIX_Metabox')) {
            $active_phase = TIX_Metabox::get_active_phase($cat['phases'] ?? []);
            if ($active_phase) {
                $phase_price = floatval($active_phase['price']);
                if ($phase_price < $regular) {
                    // Phase ist günstiger → als Sale behandeln
                    $sale    = $phase_price;
                    $result  = [
                        'price'   => $phase_price,
                        'sale'    => $phase_price,
                        'regular' => $regular,
                    ];
                    self::$cache[$product_id] = $result;
                    return $result;
                } else {
                    // Phase ist teurer oder gleich → regulären Preis anpassen
                    $regular = $phase_price;
                }
            }
        }

        // Normaler Sale-Preis (kein Phase oder Phase teurer)
        if ($sale !== null && $sale < $regular) {
            $result = [
                'price'   => $sale,
                'sale'    => $sale,
                'regular' => $regular,
            ];
        } else {
            $result = [
                'price'   => $regular,
                'sale'    => null,
                'regular' => $regular,
            ];
        }

        self::$cache[$product_id] = $result;
        return $result;
    }

    /**
     * Cache leeren (z.B. nach Sync)
     */
    public static function clear_cache() {
        self::$cache = [];
    }
}
