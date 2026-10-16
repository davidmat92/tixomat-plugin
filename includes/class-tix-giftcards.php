<?php
/**
 * TIX Giftcards — Geschenkgutscheine als Tickets mit Guthaben.
 *
 * Konzept: Der Coupon (tix_coupons) ist das Guthaben-Konto (discount_type 'giftcard',
 * balance = Restguthaben). Ticket-Scan (Tuer) und Online-Checkout buchen vom selben Saldo.
 *
 *   Kategorie-Flags (Event-Editor): gift_card = 1  → Kaeufe erzeugen automatisch je Ticket
 *   einen Gutschein-Code; gift_free_amount = 1 → Kaeufer gibt Wunschbetrag ein (10–500 €,
 *   via [tix_giftcard_amount]-Shortcode, Preis wird als locked_price durch den Checkout getragen).
 *
 *   Coupon-Struktur:
 *     discount_type: 'giftcard', value: Ausgabewert, balance: Rest,
 *     expires: Y-m-d (Default +3 Jahre, Option tix_giftcard_settings[validity_years]),
 *     ticket_id, order_id, redemptions: [{ts, amount, channel, ref, rest}]
 *     organizer_id (nur Gutscheine je Veranstalter): einlösbar nur bei Events dieses Veranstalters
 *
 *   Je Veranstalter (Mehr-Veranstalter-Modus, Modul „giftcards“ des Veranstalters, evendis-App Paket 3a):
 *   eigenes verstecktes Gutschein-Event (Meta `_tix_giftcard_organizer` = ID, `_tix_organizer_id` = ID),
 *   Beträge/Wunschbetrag/Gültigkeit am Veranstalter (`_tix_giftcard_settings`, sonst wie die Seite).
 *   GET /giftcards?organizer=ID liefert dessen Konfiguration mit `organizer_id`.
 */
if (!defined('ABSPATH')) exit;

class TIX_Giftcards {

    const OPT_SETTINGS = 'tix_giftcard_settings';
    const MIN_FREE = 10;
    const MAX_FREE = 500;

    /** true, solange die Bestätigungs-Mail die Codes selbst erzeugt (keine zweite Mail) */
    public static $in_mail = false;

    public static function init() {
        // Code-Erzeugung nach Ticket-Generierung (Tickets entstehen bei Prio 10 auf tix_order_completed)
        add_action('tix_order_completed', [__CLASS__, 'create_for_order'], 20);
        // Scan-Teileinloesung (Zugriff wie Scanner selbst: offen — Entscheidung Betreiber)
        add_action('wp_ajax_tix_giftcard_redeem',        [__CLASS__, 'ajax_redeem']);
        add_action('wp_ajax_nopriv_tix_giftcard_redeem', [__CLASS__, 'ajax_redeem']);
        // Wunschbetrag-Formular (Event-gebunden) + eigenstaendiger Gutschein-Shop
        add_shortcode('tix_giftcard_amount', [__CLASS__, 'shortcode_amount']);
        add_shortcode('tix_giftcards',       [__CLASS__, 'shortcode_shop']);
        // Kauf-Storno → Gutschein deaktivieren
        add_action('tix_order_cancelled', [__CLASS__, 'deactivate_for_order'], 20);
        // System-Event aus allen Frontend-Event-Listen ausblenden
        add_action('pre_get_posts', [__CLASS__, 'hide_system_event']);
    }

    /* ─────────── Settings ─────────── */

    public static function get_settings(): array {
        $s = get_option(self::OPT_SETTINGS, []);
        if (!is_array($s)) $s = [];
        $amounts = array_values(array_filter(array_map('floatval', (array) ($s['amounts'] ?? [25, 50, 100])), function ($a) { return $a > 0; }));
        list($fmin, $fmax) = self::clamp_limits($s['free_min'] ?? self::MIN_FREE, $s['free_max'] ?? self::MAX_FREE);
        return [
            'enabled'        => !empty($s['enabled']),
            'amounts'        => $amounts ?: [25, 50, 100],
            'free_amount'    => !isset($s['free_amount']) ? true : !empty($s['free_amount']),
            'free_min'       => $fmin,
            'free_max'       => $fmax,
            'validity_years' => max(1, min(10, intval($s['validity_years'] ?? 3) ?: 3)),
        ];
    }

    /** Wunschbetrag-Grenzen säubern (1.38.464 einstellbar, Vorgabe 10–500 €): [min, max] in ganzen Euro, 1–10000, max ≥ min. */
    public static function clamp_limits($min, $max): array {
        $min = max(1, min(10000, intval(round(floatval(str_replace(',', '.', (string) $min))))));
        $max = max(1, min(10000, intval(round(floatval(str_replace(',', '.', (string) $max))))));
        return [$min, max($min, $max)];
    }

    /** Wunschbetrag-Grenzen für ein Gutschein-Event (Veranstalter-Gutschein: dessen Einstellung, sonst die Seite). */
    public static function free_limits_for_event(int $event_id): array {
        $oid = self::event_organizer($event_id);
        $st = $oid ? self::organizer_settings($oid) : self::get_settings();
        return [intval($st['free_min']), intval($st['free_max'])];
    }

    public static function validity_years(): int {
        return self::get_settings()['validity_years'];
    }

    /* ─────────── Eigenstaendiger Modus: verstecktes System-Event ─────────── */

    public static function system_event_id(): int {
        $ids = get_posts([
            'post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_system_giftcard_event', 'value' => '1'], ['key' => '_tix_giftcard_organizer', 'compare' => 'NOT EXISTS']],
            'tix_include_system' => 1,
        ]);
        return $ids ? intval($ids[0]) : 0;
    }

    /* ─────────── Je Veranstalter (Mehr-Veranstalter-Modus) ─────────── */

    /** Modul „giftcards“ beim (freigegebenen) Veranstalter an? */
    public static function organizer_enabled(int $oid): bool {
        if ($oid <= 0 || !class_exists('TIX_App_Scope') || !TIX_App_Scope::multi() || get_post_type($oid) !== 'tix_organizer' || get_post_status($oid) !== 'publish') return false;
        if (class_exists('TIX_Org_Approval') && !TIX_Org_Approval::is_public($oid)) return false;
        if (class_exists('TIX_Modules') && !TIX_Modules::allowed('giftcards')) return false;
        $mods = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::modules($oid) : [];
        return !empty($mods['giftcards']);
    }

    /** Einstellungen des Veranstalters (Rückfall: Einstellungen der Seite). */
    public static function organizer_settings(int $oid): array {
        $base = self::get_settings();
        $o = get_post_meta($oid, '_tix_giftcard_settings', true);
        $o = is_array($o) ? $o : [];
        $amounts = array_values(array_filter(array_map('floatval', (array) ($o['amounts'] ?? $base['amounts'])), function ($a) { return $a > 0 && $a <= 10000; }));
        list($fmin, $fmax) = self::clamp_limits($o['free_min'] ?? $base['free_min'], $o['free_max'] ?? $base['free_max']);
        return [
            'enabled'        => self::organizer_enabled($oid),
            'amounts'        => $amounts ?: $base['amounts'],
            'free_amount'    => array_key_exists('free_amount', $o) ? !empty($o['free_amount']) : $base['free_amount'],
            'free_min'       => $fmin,
            'free_max'       => $fmax,
            'validity_years' => max(1, min(10, intval($o['validity_years'] ?? $base['validity_years']) ?: 3)),
        ];
    }

    public static function organizer_event_id(int $oid): int {
        if ($oid <= 0) return 0;
        $ids = get_posts([
            'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_system_giftcard_event', 'value' => '1'], ['key' => '_tix_giftcard_organizer', 'value' => (string) $oid]],
            'tix_include_system' => 1,
        ]);
        return $ids ? intval($ids[0]) : 0;
    }

    /** Gutschein-Event des Veranstalters anlegen bzw. Kategorien angleichen. */
    public static function ensure_organizer_event(int $oid): int {
        if (!self::organizer_enabled($oid)) return 0;
        $st = self::organizer_settings($oid);
        $event_id = self::organizer_event_id($oid);
        if (!$event_id) {
            $event_id = wp_insert_post([
                'post_type'   => 'event',
                'post_status' => 'publish',
                'post_title'  => 'Geschenkgutschein – ' . html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8'),
                'post_name'   => 'tix-geschenkgutschein-' . $oid,
                'meta_input'  => [
                    '_tix_system_giftcard_event' => '1',
                    '_tix_giftcard_organizer'    => (string) $oid,
                    '_tix_organizer_id'          => $oid,
                    '_tix_tickets_enabled'       => '1',
                ],
            ]);
            if (!$event_id || is_wp_error($event_id)) return 0;
        } elseif (get_post_status($event_id) !== 'publish') {
            wp_update_post(['ID' => $event_id, 'post_status' => 'publish']);
        }
        $cats = self::build_categories($st);
        if (get_post_meta($event_id, '_tix_ticket_categories', true) != $cats) update_post_meta($event_id, '_tix_ticket_categories', $cats);
        return $event_id;
    }

    /** Veranstalter-ID eines Gutschein-Events (0 = Gutschein der Seite). */
    public static function event_organizer(int $event_id): int {
        return intval(get_post_meta($event_id, '_tix_giftcard_organizer', true));
    }

    /**
     * Speichert Einstellungen und provisioniert/synct das versteckte System-Event.
     * Kategorien: je fester Betrag eine (gift_card+no_fee+hidden), optional Wunschbetrag.
     */
    public static function save_settings(array $in) {
        $amounts = [];
        foreach (preg_split('/[,;\s]+/', (string) ($in['amounts'] ?? '')) as $a) {
            $a = floatval(str_replace(',', '.', trim($a)));
            if ($a > 0 && $a <= 10000) $amounts[] = round($a, 2);
        }
        $amounts = array_values(array_unique($amounts)) ?: [25, 50, 100];
        sort($amounts);

        $old = get_option(self::OPT_SETTINGS, []);
        $old = is_array($old) ? $old : [];
        list($fmin, $fmax) = self::clamp_limits($in['free_min'] ?? ($old['free_min'] ?? self::MIN_FREE), $in['free_max'] ?? ($old['free_max'] ?? self::MAX_FREE));
        $settings = [
            'enabled'        => !empty($in['enabled']) ? 1 : 0,
            'amounts'        => $amounts,
            'free_amount'    => !empty($in['free_amount']) ? 1 : 0,
            'free_min'       => $fmin,
            'free_max'       => $fmax,
            'validity_years' => max(1, min(10, intval($in['validity_years'] ?? 3))),
        ];
        update_option(self::OPT_SETTINGS, $settings, false);

        if ($settings['enabled']) {
            self::ensure_system_event($settings);
        }
        return $settings;
    }

    private static function ensure_system_event(array $settings): int {
        $event_id = self::system_event_id();
        if (!$event_id) {
            $event_id = wp_insert_post([
                'post_type'   => 'event',
                'post_status' => 'publish',
                'post_title'  => 'Geschenkgutschein',
                'post_name'   => 'tix-geschenkgutschein',
            ]);
            if (!$event_id || is_wp_error($event_id)) return 0;
            update_post_meta($event_id, '_tix_system_giftcard_event', '1');
            update_post_meta($event_id, '_tix_tickets_enabled', '1');
            // Bewusst KEIN Datum — Checkin behaelt datumslose Events, Listen blenden es eh aus
        }

        update_post_meta($event_id, '_tix_ticket_categories', self::build_categories($settings));
        return $event_id;
    }

    /** Kategorien aus den Beträgen (je fester Betrag eine, optional Wunschbetrag). */
    private static function build_categories(array $settings): array {
        // Kategorien aus den Betraegen aufbauen (Sync bei jedem Speichern)
        $cats = [];
        foreach ($settings['amounts'] as $a) {
            $cats[] = [
                'name' => 'Gutschein ' . rtrim(rtrim(number_format($a, 2, ',', '.'), '0'), ',') . ' €',
                'price' => $a, 'sale_price' => '', 'qty' => 100000, 'desc' => '',
                'image_id' => 0, 'online' => '1', 'offline_ticket' => '0', 'admin_only' => 0,
                'hidden' => 1, 'no_fee' => 1, 'gift_card' => 1, 'gift_free_amount' => 0,
                'bundle_buy' => 0, 'bundle_pay' => 0, 'bundle_label' => '',
                'tc_event_id' => 0, 'product_id' => 0, 'sku' => '', 'group' => '',
                'seatmap_id' => 0, 'seatmap_section' => '', 'low_stock_mode' => 'off', 'phases' => [],
            ];
        }
        if (!empty($settings['free_amount'])) {
            $cats[] = [
                'name' => 'Gutschein Wunschbetrag',
                'price' => 0, 'sale_price' => '', 'qty' => 100000, 'desc' => '',
                'image_id' => 0, 'online' => '1', 'offline_ticket' => '0', 'admin_only' => 0,
                'hidden' => 1, 'no_fee' => 1, 'gift_card' => 1, 'gift_free_amount' => 1,
                'bundle_buy' => 0, 'bundle_pay' => 0, 'bundle_label' => '',
                'tc_event_id' => 0, 'product_id' => 0, 'sku' => '', 'group' => '',
                'seatmap_id' => 0, 'seatmap_section' => '', 'low_stock_mode' => 'off', 'phases' => [],
            ];
        }
        return $cats;
    }

    /**
     * Blendet das System-Event aus allen Frontend-Event-Queries aus
     * (Homepage-Sektionen, Archive, Selektoren). Direkte get_post()-Zugriffe
     * (Ticket-Rendering, Mails) sind nicht betroffen.
     */
    public static function hide_system_event($query) {
        if (is_admin()) return;
        if (!($query instanceof WP_Query)) return;
        if ($query->get('tix_include_system')) return;
        $pt = $query->get('post_type');
        $has_event = $pt === 'event' || (is_array($pt) && in_array('event', $pt, true));
        if (!$has_event) return;
        $mq = array_filter((array) $query->get('meta_query'), function ($v) { return $v !== '' && $v !== null; });
        $hide = ['key' => '_tix_system_giftcard_event', 'compare' => 'NOT EXISTS'];
        // Vorhandene Bedingungen per UND verknüpfen: einfach angehängt würde ein oberstes
        // relation=OR zu „… ODER kein Gutschein-Event“ und die Abfrage träfe fast alles
        $query->set('meta_query', $mq ? ['relation' => 'AND', $mq, $hide] : [$hide]);
    }

    /* ─────────── Kategorie-Helpers ─────────── */

    public static function cat_flags(int $event_id, int $cat_index): array {
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cat  = (is_array($cats) && isset($cats[$cat_index])) ? $cats[$cat_index] : null;
        return [
            'gift'        => is_array($cat) && !empty($cat['gift_card']),
            'free_amount' => is_array($cat) && !empty($cat['gift_free_amount']),
            'name'        => is_array($cat) ? (string) ($cat['name'] ?? '') : '',
        ];
    }

    /* ─────────── Karten-CRUD (auf tix_coupons) ─────────── */

    public static function get_card(string $code): ?array {
        wp_cache_delete('tix_coupons', 'options');
        $coupons = get_option('tix_coupons', []);
        foreach ($coupons as $k => $c) {
            if (strcasecmp($k, $code) === 0 && (($c['discount_type'] ?? '') === 'giftcard')) {
                $c['code'] = $k;
                return $c;
            }
        }
        return null;
    }

    private static function generate_code(array $existing): string {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // ohne I/L/O/0/1
        do {
            $p = '';
            for ($i = 0; $i < 8; $i++) $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $code = 'GS-' . substr($p, 0, 4) . '-' . substr($p, 4);
        } while (isset($existing[$code]));
        return $code;
    }

    /**
     * Erzeugt fuer alle Gutschein-Tickets einer bezahlten Order die Codes (idempotent).
     */
    public static function create_for_order($order_id) {
        global $wpdb;
        $order_id = intval($order_id);
        if ($order_id <= 0) return;

        $tickets = get_posts([
            'post_type' => 'tix_ticket', 'post_status' => 'publish', 'posts_per_page' => -1,
            'meta_query' => [['key' => '_tix_ticket_order_id', 'value' => (string) $order_id]],
            'orderby' => 'ID', 'order' => 'ASC', // Reihenfolge der Positionen (Betrag + Geschenk-Angaben je Stück)
        ]);
        if (empty($tickets)) return;

        // Betraege pro Kategorie aus den Order-Items (Wunschbetrag: eigene Rows dank No-Merge)
        // amounts[cat_name] = Liste von Einzelpreisen (expandiert nach qty)
        $amounts = [];
        $gifts   = []; // Geschenk-Angaben je Einzelstück (1.38.463, meta.gift_info der Position)
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT cat_name, name, quantity, total, meta FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", $order_id
        ));
        foreach ($items as $it) {
            $key = (string) $it->cat_name;
            $qty = max(1, intval($it->quantity));
            $unit = round(floatval($it->total) / $qty, 2);
            $meta = json_decode((string) $it->meta, true);
            $gi = is_array($meta['gift_info'] ?? null) ? $meta['gift_info'] : null;
            for ($i = 0; $i < $qty; $i++) { $amounts[$key][] = $unit; $gifts[$key][] = $gi; }
        }

        $coupons = null; // lazy laden
        $created = 0;

        foreach ($tickets as $t) {
            if (get_post_meta($t->ID, '_tix_ticket_gift_code', true)) continue; // idempotent

            $event_id  = intval(get_post_meta($t->ID, '_tix_ticket_event_id', true));
            $cat_index_raw = get_post_meta($t->ID, '_tix_ticket_cat_index', true);
            $cat_index = ($cat_index_raw === '' || $cat_index_raw === null || $cat_index_raw === false) ? -1 : intval($cat_index_raw);
            $cat_name  = (string) get_post_meta($t->ID, '_tix_ticket_cat_name', true);

            $flags = ($cat_index >= 0) ? self::cat_flags($event_id, $cat_index) : ['gift' => false];
            if (empty($flags['gift'])) continue;

            // Betrag: naechster Einzelpreis dieser Kategorie aus den Order-Items
            $amount = 0;
            $gift_info = null;
            if (!empty($amounts[$cat_name])) {
                $amount = floatval(array_shift($amounts[$cat_name]));
                $gift_info = array_shift($gifts[$cat_name]);
            }
            if ($amount <= 0) {
                // Fallback: Kategoriepreis
                $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
                $amount = floatval($cats[$cat_index]['price'] ?? 0);
            }
            if ($amount <= 0) continue; // 0-€-Gutschein macht keinen Sinn

            if ($coupons === null) {
                wp_cache_delete('tix_coupons', 'options');
                $coupons = get_option('tix_coupons', []);
            }
            $code = self::generate_code($coupons);
            $gift_org = self::event_organizer($event_id);
            $years = $gift_org ? self::organizer_settings($gift_org)['validity_years'] : self::validity_years();
            $coupons[$code] = [
                'discount_type' => 'giftcard',
                'value'         => round($amount, 2),
                'balance'       => round($amount, 2),
                'expires'       => date('Y-m-d', strtotime('+' . $years . ' years')),
                'organizer_id'  => $gift_org ?: null,
                'ticket_id'     => intval($t->ID),
                'order_id'      => $order_id,
                'redemptions'   => [],
                'created'       => current_time('mysql'),
                'description'   => 'Geschenkgutschein (Order #' . $order_id . ')',
                'max_uses'      => 0,
                'used'          => 0,
            ] + ($gift_info ? ['gift' => $gift_info] : []);
            update_post_meta($t->ID, '_tix_ticket_gift_code', $code);
            $created++;
        }

        if ($coupons !== null && $created > 0) {
            update_option('tix_coupons', $coupons);
            if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
                TIX_Order_Admin::add_note($order_id, '💳 ' . $created . ' Geschenkgutschein-Code(s) erzeugt.', 'system');
            }
            // Codes kamen nach der Bestätigungs-Mail: eigene Mail mit PDF (1.38.463)
            if (class_exists('TIX_Giftcard_Gift') && !self::$in_mail) TIX_Giftcard_Gift::send_late_mail($order_id);
        }
    }

    /** Kauf-Storno: verknuepfte Gutscheine deaktivieren (balance auf 0, Vermerk). */
    public static function deactivate_for_order($order_id) {
        $order_id = intval($order_id);
        wp_cache_delete('tix_coupons', 'options');
        $coupons = get_option('tix_coupons', []);
        $changed = false;
        foreach ($coupons as $k => &$c) {
            if (($c['discount_type'] ?? '') === 'giftcard' && intval($c['order_id'] ?? 0) === $order_id && floatval($c['balance'] ?? 0) > 0) {
                $c['redemptions'][] = ['ts' => current_time('mysql'), 'amount' => floatval($c['balance']), 'channel' => 'storno', 'ref' => 'order-cancelled', 'rest' => 0];
                $c['balance'] = 0;
                $changed = true;
            }
        }
        unset($c);
        if ($changed) update_option('tix_coupons', $coupons);
    }

    /* ─────────── Einloesen (atomar-ish: frischer Read + Clamp) ─────────── */

    /**
     * @return array{ok:bool, message:string, balance:float, value:float}
     */
    public static function redeem(string $code, float $amount, string $channel, string $ref = ''): array {
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) return ['ok' => false, 'message' => 'Ungueltiger Betrag.', 'balance' => 0, 'value' => 0];

        wp_cache_delete('tix_coupons', 'options');
        $coupons = get_option('tix_coupons', []);
        $key = null;
        foreach ($coupons as $k => $c) {
            if (strcasecmp($k, $code) === 0 && (($c['discount_type'] ?? '') === 'giftcard')) { $key = $k; break; }
        }
        if ($key === null) return ['ok' => false, 'message' => 'Gutschein nicht gefunden.', 'balance' => 0, 'value' => 0];

        $card = $coupons[$key];
        $value   = round(floatval($card['value'] ?? 0), 2);
        $balance = round(floatval($card['balance'] ?? 0), 2);

        if (!empty($card['expires']) && strtotime($card['expires'] . ' 23:59:59') < current_time('timestamp')) {
            return ['ok' => false, 'message' => 'Gutschein abgelaufen (' . $card['expires'] . ').', 'balance' => $balance, 'value' => $value];
        }
        if ($balance <= 0) {
            return ['ok' => false, 'message' => 'Guthaben aufgebraucht.', 'balance' => 0, 'value' => $value];
        }
        if ($amount > $balance + 0.001) {
            return ['ok' => false, 'message' => 'Betrag uebersteigt Restguthaben (' . number_format($balance, 2, ',', '.') . ' €).', 'balance' => $balance, 'value' => $value];
        }

        $new_balance = round($balance - $amount, 2);
        $coupons[$key]['balance'] = $new_balance;
        $coupons[$key]['redemptions'][] = [
            'ts' => current_time('mysql'), 'amount' => $amount, 'channel' => $channel, 'ref' => $ref, 'rest' => $new_balance,
        ];
        update_option('tix_coupons', $coupons);

        // Bei 0 → verknuepftes Ticket entwerten (Scan zeigt kuenftig "aufgebraucht")
        if ($new_balance <= 0 && !empty($card['ticket_id'])) {
            $tid = intval($card['ticket_id']);
            if (!get_post_meta($tid, '_tix_ticket_checked_in', true) && class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'checkin_ticket')) {
                TIX_Tickets::checkin_ticket($tid, 'giftcard-' . $channel);
            }
        }

        return ['ok' => true, 'message' => 'Eingeloest.', 'balance' => $new_balance, 'value' => $value];
    }

    /* ─────────── AJAX: Scan-Teileinloesung ─────────── */

    public static function ajax_redeem() {
        if (class_exists('TIX_Rate_Limit')) {
            TIX_Rate_Limit::check('giftcard_redeem', 30, 60, 'ajax');
        }
        $ticket_code = sanitize_text_field($_POST['ticket_code'] ?? '');
        $amount      = floatval($_POST['amount'] ?? 0);
        if ($ticket_code === '') wp_send_json_error(['message' => 'Kein Ticket-Code.']);

        // Ticket → Gutschein-Code aufloesen (Scanner kennt nur den Ticket-QR)
        $tickets = get_posts([
            'post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_ticket_code', 'value' => $ticket_code]],
        ]);
        if (empty($tickets)) wp_send_json_error(['message' => 'Ticket nicht gefunden.']);
        $gift_code = get_post_meta($tickets[0], '_tix_ticket_gift_code', true);
        if (!$gift_code) wp_send_json_error(['message' => 'Kein Gutschein-Ticket.']);
        // Gutschein eines Veranstalters: nur dessen Personal (bzw. Admin) löst ein
        $card = self::get_card($gift_code);
        if ($card && !empty($card['organizer_id']) && class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && !TIX_App_Scope::is_admin()
            && intval(TIX_App_Scope::organizer_id_for_user()) !== intval($card['organizer_id'])) {
            wp_send_json_error(['message' => 'Gilt nur bei ' . html_entity_decode(get_the_title(intval($card['organizer_id'])), ENT_QUOTES, 'UTF-8') . '.']);
        }

        $res = self::redeem($gift_code, $amount, 'scan', is_user_logged_in() ? wp_get_current_user()->user_login : 'door');
        if (!$res['ok']) wp_send_json_error(['message' => $res['message'], 'balance' => $res['balance']]);
        wp_send_json_success([
            'balance'     => $res['balance'],
            'balance_fmt' => number_format($res['balance'], 2, ',', '.'),
            'value_fmt'   => number_format($res['value'], 2, ',', '.'),
            'empty'       => $res['balance'] <= 0,
        ]);
    }

    /* ─────────── Wunschbetrag-Shortcode ─────────── */

    /**
     * [tix_giftcard_amount id="55" cat="3"] — Betragseingabe + In-den-Warenkorb.
     * Kategorie muss gift_card + gift_free_amount Flags tragen (Server prueft beim Add).
     */
    public static function shortcode_amount($atts) {
        if (class_exists('TIX_Modules') && !TIX_Modules::allowed('giftcards')) return '';
        $lim  = self::free_limits_for_event(intval($atts['id'] ?? 0));
        $atts = shortcode_atts(['id' => 0, 'cat' => -1, 'min' => $lim[0], 'max' => $lim[1]], $atts, 'tix_giftcard_amount');
        $event_id  = intval($atts['id']);
        $cat_index = intval($atts['cat']);
        if ($event_id <= 0 || $cat_index < 0) return '';
        $flags = self::cat_flags($event_id, $cat_index);
        if (empty($flags['gift']) || empty($flags['free_amount'])) return '<p>Wunschbetrag-Kategorie nicht konfiguriert.</p>';
        $min = max(1, intval($atts['min']));
        $max = max($min, intval($atts['max']));
        $nonce = wp_create_nonce('tix_add_to_cart');
        $uid = 'tixgca-' . $event_id . '-' . $cat_index;
        ob_start();
        ?>
        <div class="tix-giftcard-amount" id="<?php echo esc_attr($uid); ?>" style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px 24px;max-width:420px;">
            <div style="font-weight:700;font-size:15px;margin-bottom:4px;">💳 Wunschbetrag</div>
            <div style="font-size:13px;color:#64748b;margin-bottom:12px;">Frei waehlbar von <?php echo $min; ?> € bis <?php echo $max; ?> €</div>
            <div style="display:flex;gap:10px;">
                <input type="number" class="tix-gca-input" min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="1" placeholder="z.B. 40"
                       style="flex:1;min-width:0;padding:11px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:16px;box-sizing:border-box;">
                <button type="button" class="tix-gca-add" style="padding:11px 20px;background:#0f172a;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap;">In den Warenkorb</button>
            </div>
            <div class="tix-gca-msg" style="margin-top:10px;font-size:13px;"></div>
        </div>
        <script>
        (function(){
            var box = document.getElementById('<?php echo esc_js($uid); ?>');
            if (!box) return;
            var input = box.querySelector('.tix-gca-input');
            var btn = box.querySelector('.tix-gca-add');
            var msg = box.querySelector('.tix-gca-msg');
            btn.addEventListener('click', function(){
                var amount = parseFloat(input.value);
                if (!amount || amount < <?php echo $min; ?> || amount > <?php echo $max; ?>) {
                    msg.style.color = '#dc2626';
                    msg.textContent = 'Bitte Betrag zwischen <?php echo $min; ?> und <?php echo $max; ?> € eingeben.';
                    return;
                }
                btn.disabled = true;
                var body = new URLSearchParams();
                body.append('action', 'tix_add_to_cart');
                body.append('nonce', '<?php echo esc_js($nonce); ?>');
                body.append('items', JSON.stringify([{event_id: <?php echo $event_id; ?>, cat_index: <?php echo $cat_index; ?>, quantity: 1, custom_amount: amount}]));
                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        if (res.success) {
                            msg.style.color = '#059669';
                            msg.innerHTML = '✓ Gutschein ueber ' + amount.toFixed(2).replace('.', ',') + ' € im Warenkorb. <a href="' + (res.data && res.data.checkout_url ? res.data.checkout_url : '/checkout/') + '">Zur Kasse →</a>';
                            input.value = '';
                        } else {
                            msg.style.color = '#dc2626';
                            msg.textContent = (res.data && res.data.message) ? res.data.message : 'Fehler.';
                        }
                    })
                    .catch(function(){ btn.disabled = false; msg.style.color = '#dc2626'; msg.textContent = 'Netzwerkfehler.'; });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ─────────── Eigenstaendiger Gutschein-Shop: [tix_giftcards] ─────────── */

    public static function shortcode_shop($atts) {
        if (class_exists('TIX_Modules') && !TIX_Modules::allowed('giftcards')) return '';
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return current_user_can('manage_options')
                ? '<p><em>Geschenkgutscheine sind nicht aktiviert (Admin → Gutscheine → 💳 Geschenkgutscheine).</em></p>'
                : '';
        }
        $event_id = self::system_event_id();
        if (!$event_id) $event_id = self::ensure_system_event($settings);
        if (!$event_id) return '';

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) return '';

        $fixed = []; $free_idx = -1;
        foreach ($cats as $i => $c) {
            if (empty($c['gift_card'])) continue;
            if (!empty($c['gift_free_amount'])) { $free_idx = $i; continue; }
            $fixed[$i] = floatval($c['price'] ?? 0);
        }

        $s = get_option('tix_settings', []);
        $checkout_url = !empty($s['checkout_page_id']) ? get_permalink(intval($s['checkout_page_id'])) : home_url('/checkout/');
        $nonce = wp_create_nonce('tix_add_to_cart');
        $uid = 'tix-gcs-' . $event_id;

        ob_start();
        ?>
        <div class="tix-giftcard-shop" id="<?php echo esc_attr($uid); ?>" style="max-width:520px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">
                <?php foreach ($fixed as $idx => $amount): ?>
                <button type="button" class="tix-gcs-fixed" data-cat="<?php echo intval($idx); ?>" data-amount="<?php echo esc_attr($amount); ?>"
                        style="padding:22px 12px;background:#fff;border:2px solid #e2e8f0;border-radius:14px;cursor:pointer;text-align:center;transition:border-color .15s;">
                    <span style="display:block;font-size:26px;font-weight:900;">💳 <?php echo esc_html(rtrim(rtrim(number_format($amount, 2, ',', '.'), '0'), ',')); ?>&nbsp;€</span>
                    <span style="display:block;font-size:12px;color:#64748b;margin-top:4px;">Gutschein kaufen</span>
                </button>
                <?php endforeach; ?>
            </div>

            <?php if ($free_idx >= 0): ?>
            <div style="margin-top:14px;background:#fff;border:2px solid #e2e8f0;border-radius:14px;padding:18px 20px;">
                <div style="font-weight:700;font-size:14px;margin-bottom:8px;">Wunschbetrag (<?php echo intval($settings['free_min']); ?>–<?php echo intval($settings['free_max']); ?> €)</div>
                <div style="display:flex;gap:10px;">
                    <input type="number" class="tix-gcs-free-input" min="<?php echo intval($settings['free_min']); ?>" max="<?php echo intval($settings['free_max']); ?>" step="1" placeholder="z.B. 40"
                           style="flex:1;min-width:0;padding:11px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:16px;box-sizing:border-box;">
                    <button type="button" class="tix-gcs-free-add" data-cat="<?php echo intval($free_idx); ?>"
                            style="padding:11px 20px;background:#0f172a;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap;">Kaufen</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="tix-gcs-msg" style="margin-top:12px;font-size:14px;"></div>
        </div>
        <script>
        (function(){
            var box = document.getElementById('<?php echo esc_js($uid); ?>');
            if (!box) return;
            var msg = box.querySelector('.tix-gcs-msg');
            function addToCart(catIndex, customAmount, btn) {
                btn.disabled = true;
                var item = {event_id: <?php echo intval($event_id); ?>, cat_index: catIndex, quantity: 1};
                if (customAmount) item.custom_amount = customAmount;
                var body = new URLSearchParams();
                body.append('action', 'tix_add_to_cart');
                body.append('nonce', '<?php echo esc_js($nonce); ?>');
                body.append('items', JSON.stringify([item]));
                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        if (res.success) {
                            msg.style.color = '#059669';
                            msg.innerHTML = '✓ Gutschein im Warenkorb. <a href="<?php echo esc_js($checkout_url); ?>" style="font-weight:700;">Zur Kasse →</a>';
                        } else {
                            msg.style.color = '#dc2626';
                            msg.textContent = (res.data && res.data.message) ? res.data.message : 'Fehler.';
                        }
                    })
                    .catch(function(){ btn.disabled = false; msg.style.color = '#dc2626'; msg.textContent = 'Netzwerkfehler.'; });
            }
            box.querySelectorAll('.tix-gcs-fixed').forEach(function(b){
                b.addEventListener('click', function(){ addToCart(parseInt(this.getAttribute('data-cat'), 10), 0, this); });
                b.addEventListener('mouseenter', function(){ this.style.borderColor = '#0f172a'; });
                b.addEventListener('mouseleave', function(){ this.style.borderColor = '#e2e8f0'; });
            });
            var freeBtn = box.querySelector('.tix-gcs-free-add');
            if (freeBtn) {
                freeBtn.addEventListener('click', function(){
                    var input = box.querySelector('.tix-gcs-free-input');
                    var amount = parseFloat(input.value);
                    if (!amount || amount < <?php echo intval($settings['free_min']); ?> || amount > <?php echo intval($settings['free_max']); ?>) {
                        msg.style.color = '#dc2626';
                        msg.textContent = 'Bitte Betrag zwischen <?php echo intval($settings['free_min']); ?> und <?php echo intval($settings['free_max']); ?> € eingeben.';
                        return;
                    }
                    addToCart(parseInt(this.getAttribute('data-cat'), 10), amount, this);
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ─────────── Ticket-Ansicht: Gutschein-Block ─────────── */

    public static function render_ticket_block($ticket_id): string {
        $code = get_post_meta(intval($ticket_id), '_tix_ticket_gift_code', true);
        if (!$code) return '';
        $card = self::get_card($code);
        if (!$card) return '';
        $value   = number_format(floatval($card['value'] ?? 0), 2, ',', '.');
        $balance = number_format(floatval($card['balance'] ?? 0), 2, ',', '.');
        $expires = !empty($card['expires']) ? date_i18n('d.m.Y', strtotime($card['expires'])) : '';
        return '<div style="margin:18px 30px;padding:18px 22px;background:#fefce8;border:2px dashed #ca8a04;border-radius:12px;text-align:center;">'
            . '<div style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#a16207;font-weight:700;margin-bottom:6px;">💳 Geschenkgutschein</div>'
            // QR mit dem Gutschein-Code (1.38.436): in der App scannen → ins Guthaben übernehmen (POST /wallet/redeem-giftcard);
            // seit 1.38.463 lokal erzeugt (der Code ist Geld und geht nicht mehr an api.qrserver.com)
            . (($qr = self::qr_data_uri($code)) !== '' ? '<img src="' . esc_attr($qr) . '" width="120" height="120" alt="QR ' . esc_attr($code) . '" style="display:block;margin:6px auto 8px;image-rendering:pixelated;">' : '')
            . self::gift_lines($card)
            . '<div style="font-size:26px;font-weight:900;letter-spacing:0.04em;color:#1c1917;font-family:monospace;">' . esc_html($code) . '</div>'
            . '<div style="font-size:14px;color:#44403c;margin-top:8px;">Wert: <strong>' . esc_html($value) . ' €</strong> &middot; Restguthaben: <strong>' . esc_html($balance) . ' €</strong></div>'
            . '<div style="font-size:12px;color:#78716c;margin-top:6px;">Einlösbar online im Checkout (Code eingeben), vor Ort per Scan oder in der App ins Guthaben übernehmen.'
            . ($expires ? ' Gueltig bis ' . esc_html($expires) . '.' : '') . '</div>'
            . (($pdf = self::pdf_link($card)) !== '' ? '<div style="margin-top:10px;"><a href="' . esc_url($pdf) . '" style="font-size:13px;font-weight:700;color:#a16207;">Gutschein als PDF zum Verschenken ↓</a></div>' : '')
            . '</div>';
    }

    /** QR als data:-URI (lokal, TIX_QR) oder ''. */
    public static function qr_data_uri(string $code): string {
        if (!class_exists('TIX_QR')) return '';
        $im = TIX_QR::gd($code, 240, 2);
        if (!$im) return '';
        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    private static function gift_lines(array $card): string {
        if (!class_exists('TIX_Giftcard_Gift')) return '';
        $g = TIX_Giftcard_Gift::fields($card);
        $out = '';
        if ($g['gift_recipient']) $out .= '<div style="font-size:14px;color:#44403c;margin:4px 0;">für <strong>' . esc_html($g['gift_recipient']) . '</strong>' . ($g['gift_from'] ? ' · von ' . esc_html($g['gift_from']) : '') . '</div>';
        elseif ($g['gift_from']) $out .= '<div style="font-size:14px;color:#44403c;margin:4px 0;">von ' . esc_html($g['gift_from']) . '</div>';
        if ($g['gift_message']) $out .= '<div style="font-size:13px;color:#57534e;font-style:italic;margin:4px 0 8px;">„' . nl2br(esc_html($g['gift_message'])) . '“</div>';
        return $out;
    }

    /** Direkter PDF-Download mit Bestell-Key (für die Ticket-Ansicht im Web). */
    private static function pdf_link(array $card): string {
        global $wpdb;
        $oid = intval($card['order_id'] ?? 0);
        if (!$oid || !class_exists('TIX_Giftcard_Gift')) return '';
        $key = (string) $wpdb->get_var($wpdb->prepare("SELECT order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid));
        if ($key === '') return '';
        return add_query_arg(['key' => $key, 'download' => '1'], rest_url('tixomat/v1/customer/giftcards/' . rawurlencode((string) $card['code']) . '/pdf'));
    }
}

TIX_Giftcards::init();
