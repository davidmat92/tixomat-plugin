<?php
/**
 * Tixomat – Datenspiegel: WordPress-Bestand → Spiegel-Zeilen
 *
 * plan() liefert für einen Postausgang-Eintrag die Operationen mit dem AKTUELLEN Stand:
 *   ['upsert', tabelle, [zeilen], on_conflict?] | ['delete', tabelle, filter] | ['rpc', funktion, args]
 *   | ['prune', tabelle, eltern_id, [behalten]] (Kinder, die es in WP nicht mehr gibt; je Runde gebündelt)
 * Gibt es das Objekt nicht mehr, wird gelöscht. Jede Tabelle bekommt immer dieselben
 * Spalten (PostgREST verlangt bei Bündeln gleiche Schlüssel).
 *
 * Nie gespiegelt: Passwörter, Login-/Push-/Download-Tokens, order_key, Check-in-Passwörter,
 * Awareness, Ausweisfotos, IBAN im Klartext, Gästelisten (kommen in Paket 2 als eigene Tabelle).
 *
 * @since Datenspiegel Paket 1 (Version vergibt die Zentrale)
 */

if (!defined('ABSPATH')) exit;

class TIX_Mirror_Map {

    /** Meta-Schlüssel, die nie in den Spiegel gehen (Teilstrings, ohne Groß/klein). */
    const DENY = ['pass', 'token', 'secret', 'iban', 'session', 'nonce', 'otp', 'awareness', 'api_key', 'apikey',
                  'private', 'id_photo', 'ausweis', '_tix_cart', 'guest_list', 'checkin_password', 'webhook'];

    public static function is_sensitive($key) {
        $k = strtolower((string) $key);
        foreach (self::DENY as $d) if (strpos($k, $d) !== false) return true;
        return false;
    }

    public static function plan($entity, $id, $op, $payload) {
        $site = TIX_Mirror::site();
        switch ($entity) {
            case 'site':     return [['upsert', 'sites', [self::site_row()], 'site']];
            case 'event':    return self::plan_event($site, (int) $id);
            case 'customer': return self::plan_customer($site, (int) $id, $op, $payload);
            case 'order':    return self::plan_order($site, (int) $id);
            case 'ticket':   return self::plan_ticket($site, (int) $id);
            case 'checkin':  return self::plan_event_row($site, 'checkins', $id, $payload);
            case 'refund':   return self::plan_event_row($site, 'refunds', $id, $payload);
        }
        return new WP_Error('entity', 'Unbekannter Typ ' . $entity);
    }

    private static function del($table, $site, $id) {
        return ['delete', $table, ['site' => 'eq.' . $site, 'id' => 'eq.' . $id]];
    }

    // ── Hilfen ──

    /** WP-Ortszeit ("Y-m-d H:i:s") → ISO in UTC, leer → null. */
    private static function local_to_iso($local) {
        $local = trim((string) $local);
        if ($local === '' || strpos($local, '0000-00-00') === 0) return null;
        try {
            $d = new DateTimeImmutable($local, wp_timezone());
            return $d->setTimezone(new DateTimeZone('UTC'))->format('c');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function num($v) {
        if ($v === '' || $v === null || !is_numeric($v)) return null;
        return (float) $v;
    }

    private static function int_or_null($v) {
        $v = (int) $v;
        return $v > 0 ? $v : null;
    }

    private static function flag($v) {
        return !empty($v) && $v !== '0' && $v !== 'no' && $v !== 'false';
    }

    /** Alle _tix_-Post-Meta außer sensiblen, unserialisiert. */
    private static function post_meta($post_id, array $skip = []) {
        $out = [];
        foreach ((array) get_post_meta($post_id) as $k => $vals) {
            if (strpos($k, '_tix_') !== 0 || self::is_sensitive($k) || in_array($k, $skip, true)) continue;
            $v = maybe_unserialize($vals[0] ?? null);
            $out[$k] = is_object($v) ? (array) $v : $v;
        }
        ksort($out);
        return $out;
    }

    private static function json_safe($v) {
        // Ungültiges UTF-8 würde den ganzen Bündel-Upsert scheitern lassen.
        if (is_string($v)) return wp_check_invalid_utf8($v, true);
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = self::json_safe($x); }
        return $v;
    }

    // ── Seite ──

    public static function site_row() {
        return self::json_safe([
            'site'            => TIX_Mirror::site(),
            'name'            => (string) get_bloginfo('name'),
            'url'             => home_url('/'),
            'plugin_version'  => defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '',
            'multi_organizer' => get_option('tix_multi_organizer') === '1',
            'timezone'        => wp_timezone_string(),
            'data'            => ['locale' => get_locale(), 'wp_version' => get_bloginfo('version')],
            'last_seen'       => gmdate('c'),
        ]);
    }

    // ── Events + Kategorien ──

    private static function plan_event($site, $id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'event' || $p->post_status === 'auto-draft') {
            return [self::del('events', $site, $id), ['delete', 'ticket_categories', ['site' => 'eq.' . $site, 'event_id' => 'eq.' . $id]]];
        }
        $m = self::post_meta($id, ['_tix_ticket_categories']);
        $g = function ($k) use ($id) { return get_post_meta($id, $k, true); };
        $date = (string) $g('_tix_date_start');
        $starts = null;
        if ($date !== '') {
            try {
                $starts = (new DateTimeImmutable(trim($date . ' ' . ((string) $g('_tix_time_start') ?: '00:00')), wp_timezone()))
                    ->setTimezone(new DateTimeZone('UTC'))->format('c');
            } catch (\Throwable $e) { $starts = null; }
        }
        $row = [
            'site' => $site, 'id' => $id,
            'title'           => $p->post_title,
            'slug'            => $p->post_name,
            'status'          => $p->post_status,
            'starts_at'       => $starts,
            'date_start'      => $date,
            'date_end'        => (string) $g('_tix_date_end'),
            'time_start'      => (string) $g('_tix_time_start'),
            'time_end'        => (string) $g('_tix_time_end'),
            'time_doors'      => (string) $g('_tix_time_doors'),
            'location_id'     => self::int_or_null($g('_tix_location_id')),
            'location_name'   => (string) $g('_tix_location'),
            'address'         => (string) $g('_tix_address'),
            'organizer_id'    => self::int_or_null($g('_tix_organizer_id')),
            'co_organizer_id' => self::int_or_null($g('_tix_co_organizer_id')),
            'tickets_enabled' => self::flag($g('_tix_tickets_enabled')),
            'url'             => (string) get_permalink($p),
            'created_at'      => self::local_to_iso($p->post_date),
            'modified_at'     => self::local_to_iso($p->post_modified),
            'data'            => (object) $m,
        ];
        $cats = get_post_meta($id, '_tix_ticket_categories', true);
        $cat_rows = [];
        if (is_array($cats)) {
            foreach ($cats as $i => $c) {
                if (!is_array($c)) continue;
                $data = array_filter($c, function ($k) { return !self::is_sensitive($k); }, ARRAY_FILTER_USE_KEY);
                $cat_rows[] = self::json_safe([
                    'site' => $site, 'id' => $id . ':' . $i,
                    'event_id'   => $id,
                    'idx'        => (int) $i,
                    'name'       => (string) ($c['name'] ?? ''),
                    'price'      => self::num($c['price'] ?? null),
                    'sale_price' => self::num($c['sale_price'] ?? null),
                    'qty'        => isset($c['qty']) && $c['qty'] !== '' ? (int) $c['qty'] : null,
                    'stock'      => isset($c['stock']) && $c['stock'] !== '' ? (int) $c['stock'] : null,
                    'online'     => !isset($c['online']) || self::flag($c['online']),
                    'hidden'     => self::flag($c['hidden'] ?? ''),
                    'data'       => $data,
                ]);
            }
        }
        $ops = [['upsert', 'events', [self::json_safe($row)]]];
        if ($cat_rows) $ops[] = ['upsert', 'ticket_categories', $cat_rows];
        // Entfernte Kategorien im Spiegel löschen (alles, was nicht mehr im Array steht).
        $ops[] = ['prune', 'ticket_categories', $id, array_column($cat_rows, 'idx')];
        return $ops;
    }

    // ── Kunden (WP-Nutzer) ──

    const USER_META = ['first_name', 'last_name', 'nickname', 'billing_first_name', 'billing_last_name', 'billing_company',
                       'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_city', 'billing_country',
                       'billing_phone', 'billing_email'];

    /** Löst eine Nutzer-Meta-Änderung einen Spiegel-Eintrag aus? */
    public static function user_meta_relevant($key) {
        if (self::is_sensitive($key)) return false;
        return in_array($key, self::USER_META, true) || strpos($key, '_tix_') === 0 || substr($key, -12) === 'capabilities';
    }

    private static function plan_customer($site, $id, $op, $payload) {
        $u = get_userdata($id);
        if (!$u) {
            // Konto gelöscht: Profil weg und Verlauf mit Personenbezug tilgen (Löschweg).
            return [['rpc', 'mirror_forget', [
                'p_site'        => $site,
                'p_customer_id' => $id,
                'p_email'       => (string) ($payload['email'] ?? ''),
            ]]];
        }
        $meta = [];
        foreach ((array) get_user_meta($id) as $k => $vals) {
            if (!self::user_meta_relevant($k) || substr($k, -12) === 'capabilities' || substr($k, -10) === 'user_level') continue;
            if (in_array($k, ['_tix_avatar_file', '_tix_app_created'], true)) continue;
            $meta[$k] = maybe_unserialize($vals[0] ?? null);
        }
        ksort($meta);
        $billing = [];
        foreach (self::USER_META as $k) if (strpos($k, 'billing_') === 0 && isset($meta[$k]) && $meta[$k] !== '') $billing[substr($k, 8)] = $meta[$k];
        $newsletter = false;
        if ($u->user_email !== '') {
            global $wpdb;
            $newsletter = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tix_sub_email'
                 WHERE p.post_type = 'tix_subscriber' AND p.post_status = 'publish' AND m.meta_value = %s LIMIT 1", $u->user_email));
        }
        $row = [
            'site' => $site, 'id' => $id,
            'email'             => $u->user_email,
            'first_name'        => (string) $u->first_name,
            'last_name'         => (string) $u->last_name,
            'display_name'      => (string) $u->display_name,
            'roles'             => array_values((array) $u->roles),
            'registered_at'     => $u->user_registered ? gmdate('c', strtotime($u->user_registered . ' UTC')) : null,
            'phone'             => (string) ($meta['_tix_phone'] ?? ($meta['billing_phone'] ?? '')),
            'billing'           => (object) $billing,
            'email_confirmed'   => self::flag($meta['_tix_email_confirmed'] ?? ''),
            'team_organizer_id' => self::int_or_null($meta['_tix_team_organizer_id'] ?? 0),
            'newsletter'        => $newsletter,
            'data'              => (object) $meta,
        ];
        return [['upsert', 'customers', [self::json_safe($row)]]];
    }

    // ── Bestellungen + Positionen + Zahlung ──

    private static function plan_order($site, $id) {
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT *, UNIX_TIMESTAMP(date_modified) AS modified_ts FROM {$wpdb->prefix}tix_orders WHERE id = %d", $id), ARRAY_A);
        if (!$o) {
            return [self::del('orders', $site, $id), self::del('payments', $site, $id),
                    ['delete', 'order_items', ['site' => 'eq.' . $site, 'order_id' => 'eq.' . $id]]];
        }
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d ORDER BY id", $id), ARRAY_A);

        $opt = function ($k) use ($id) { return get_option($k . '_' . $id, null); };
        $event_ids = [];
        $item_rows = [];
        foreach ((array) $items as $it) {
            $meta = json_decode((string) $it['meta'], true);
            if (!is_array($meta)) $meta = $it['meta'] !== null && $it['meta'] !== '' ? ['raw' => maybe_unserialize($it['meta'])] : [];
            if ((int) $it['event_id']) $event_ids[(int) $it['event_id']] = true;
            $item_rows[] = self::json_safe([
                'site' => $site, 'id' => (int) $it['id'],
                'order_id'   => $id,
                'product_id' => self::int_or_null($it['product_id']),
                'event_id'   => self::int_or_null($it['event_id']),
                'quantity'   => (int) $it['quantity'],
                'total'      => (float) $it['total'],
                'tax'        => (float) $it['tax'],
                'name'       => (string) $it['name'],
                'cat_name'   => (string) $it['cat_name'],
                'meta'       => (object) array_filter($meta, function ($k) { return !self::is_sensitive($k); }, ARRAY_FILTER_USE_KEY),
            ]);
        }
        $pos = $opt('_tix_pos_order');
        if (is_array($pos) && !empty($pos['event_id'])) $event_ids[(int) $pos['event_id']] = true;
        $event_ids = array_keys($event_ids);
        $organizer = null;
        foreach ($event_ids as $eid) { if ($org = (int) get_post_meta($eid, '_tix_organizer_id', true)) { $organizer = $org; break; } }

        $refunded = self::num($opt('_tix_refund_total')) ?? 0.0;
        $newsletter = $opt('_tix_order_newsletter');
        $coupon = $opt('_tix_order_coupon');
        $data = [];
        foreach (['fees' => '_tix_order_fees', 'campaign' => '_tix_order_campaign', 'group' => '_tix_order_group_data',
                  'partner' => '_tix_order_partner', 'pos' => '_tix_pos_order', 'coupon' => '_tix_order_coupon',
                  'last_refund' => '_tix_refund'] as $name => $key) {
            $v = $opt($key);
            if ($v !== null && $v !== '' && $v !== false) $data[$name] = $v;
        }

        $row = [
            'site' => $site, 'id' => $id,
            'order_number'         => (string) $o['order_number'],
            'status'               => (string) $o['status'],
            'total'                => (float) $o['total'],
            'subtotal'             => (float) $o['subtotal'],
            'tax'                  => (float) $o['tax'],
            'discount'             => (float) $o['discount'],
            'refunded_total'       => (float) $refunded,
            'payment_method'       => (string) $o['payment_method'],
            'payment_method_title' => (string) $o['payment_method_title'],
            'billing_first_name'   => (string) $o['billing_first_name'],
            'billing_last_name'    => (string) $o['billing_last_name'],
            'billing_email'        => (string) $o['billing_email'],
            'billing_phone'        => (string) $o['billing_phone'],
            'billing_company'      => (string) $o['billing_company'],
            'billing_address_1'    => (string) $o['billing_address_1'],
            'billing_address_2'    => (string) $o['billing_address_2'],
            'billing_city'         => (string) $o['billing_city'],
            'billing_postcode'     => (string) $o['billing_postcode'],
            'billing_country'      => (string) $o['billing_country'],
            'customer_id'          => self::int_or_null($o['customer_id']),
            'organizer_id'         => $organizer,
            'event_ids'            => array_map('intval', $event_ids),
            'checked_in'           => self::flag($o['checked_in']),
            'newsletter_optin'     => self::flag($newsletter),
            'coupon'               => is_array($coupon) ? (string) ($coupon['code'] ?? '') : (string) $coupon,
            'created_at'           => self::local_to_iso($o['date_created']),
            'modified_at'          => $o['modified_ts'] ? gmdate('c', (int) $o['modified_ts']) : null,
            'data'                 => (object) $data,
        ];

        // Zahlung: Anbieter-Referenz nur als Kennung, keine Kartendaten (hat das Plugin ohnehin nicht).
        $ref = '';
        foreach (['_tix_mollie_payment', '_tix_paypal_capture', '_tix_paypal_order', '_tix_stripe_pi', '_tix_stripe_session'] as $k) {
            $v = $opt($k);
            if (is_scalar($v) && (string) $v !== '') { $ref = (string) $v; break; }
        }
        $fee = function ($k) use ($id) { $v = get_post_meta($id, $k, true); return $v === '' ? null : $v; };
        $pay = [
            'site' => $site, 'id' => $id,
            'order_id'       => $id,
            'provider'       => (string) ($fee('_tix_payment_gateway') ?: $o['payment_method']),
            'method_title'   => (string) $o['payment_method_title'],
            'status'         => (string) $o['status'],
            'amount'         => (float) $o['total'],
            'refunded_total' => (float) $refunded,
            'provider_ref'   => $ref,
            'fee'            => self::num($fee('_tix_payment_fee')),
            'fee_gross'      => self::num($fee('_tix_payment_fee_gross')),
            'fee_net'        => self::num($fee('_tix_payment_fee_net')),
            'data'           => (object) array_filter([
                'stripe_method' => $opt('_tix_stripe_method'),
                'paypal_order'  => $opt('_tix_paypal_order'),
            ], function ($v) { return $v !== null && $v !== ''; }),
        ];

        $ops = [['upsert', 'orders', [self::json_safe($row)]]];
        if ($item_rows) $ops[] = ['upsert', 'order_items', $item_rows];
        $ops[] = ['prune', 'order_items', $id, array_column($item_rows, 'id')];
        $ops[] = ['upsert', 'payments', [self::json_safe($pay)]];
        return $ops;
    }

    // ── Tickets ──

    private static function plan_ticket($site, $id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'tix_ticket') return [self::del('tickets', $site, $id)];
        $g = function ($k) use ($id) { return get_post_meta($id, $k, true); };
        $ct = (string) $g('_tix_ticket_checkin_time');
        $ct_iso = null;
        if ($ct !== '') { try { $ct_iso = (new DateTimeImmutable($ct, wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('c'); } catch (\Throwable $e) {} }
        $row = [
            'site' => $site, 'id' => $id,
            'code'             => (string) ($g('_tix_ticket_code') ?: $p->post_title),
            'order_id'         => self::int_or_null($g('_tix_ticket_order_id') ?: $p->post_parent),
            'event_id'         => self::int_or_null($g('_tix_ticket_event_id')),
            'event_name'       => (string) $g('_tix_ticket_event_name'),
            'status'           => (string) ($g('_tix_ticket_status') ?: 'valid'),
            'post_status'      => $p->post_status,
            'owner_email'      => (string) $g('_tix_ticket_owner_email'),
            'owner_name'       => (string) $g('_tix_ticket_owner_name'),
            'assigned_name'    => (string) $g('_tix_ticket_assigned_name'),
            'assigned_user_id' => self::int_or_null($g('_tix_ticket_assigned_user_id')),
            'customer_id'      => self::int_or_null($p->post_author > 1 ? $p->post_author : 0),
            'cat_name'         => (string) $g('_tix_ticket_cat_name'),
            'cat_index'        => $g('_tix_ticket_cat_index') === '' ? null : (int) $g('_tix_ticket_cat_index'),
            'price'            => self::num($g('_tix_ticket_price')),
            'checked_in'       => self::flag($g('_tix_ticket_checked_in')),
            'checkin_at'       => $ct_iso,
            'checkin_by'       => (string) $g('_tix_ticket_checkin_by'),
            'seat_id'          => (string) $g('_tix_ticket_seat_id'),
            'ticket_type'      => (string) $g('_tix_ticket_type'),
            'is_mirror'        => self::flag($g('_tix_ticket_mirror')),
            'source_site'      => (string) $g('_tix_ticket_source_site'),
            'created_at'       => self::local_to_iso($p->post_date),
            'modified_at'      => self::local_to_iso($p->post_modified),
            'data'             => (object) self::post_meta($id),
        ];
        return [['upsert', 'tickets', [self::json_safe($row)]]];
    }

    // ── Ereignisse ohne eigene WP-Zeile (Check-in-Verlauf, Stornos/Erstattungen) ──

    private static function plan_event_row($site, $table, $id, $payload) {
        if (!is_array($payload)) return new WP_Error('payload', 'Ereignis ohne Inhalt');
        $row = array_merge($payload, ['site' => $site, 'id' => (string) $id]);
        return [['upsert', $table, [self::json_safe($row)]]];
    }
}
