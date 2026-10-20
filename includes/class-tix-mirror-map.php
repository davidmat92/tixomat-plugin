<?php
/**
 * Tixomat – Datenspiegel: WordPress-Bestand → Spiegel-Zeilen
 *
 * plan() liefert für einen Postausgang-Eintrag die Operationen mit dem AKTUELLEN Stand:
 *   ['upsert', tabelle, [zeilen], on_conflict?] | ['delete', tabelle, filter] | ['rpc', funktion, args]
 *   | ['prune', tabelle, eltern_id, [behalten]] (Kinder, die es in WP nicht mehr gibt; je Runde gebündelt)
 *   | ['purge', tabelle, id] (älteren Verlauf eines anonymisierten Datensatzes tilgen; je Runde gebündelt)
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
                  'private', 'id_photo', 'ausweis', '_tix_cart', 'guest_list', 'guestlist', 'checkin_password', 'webhook',
                  'pushover', 'payout_pending', 'code_hash', 'public_key',
                  // 1.38.470 (Fund KitchenKlub: Kassen-PIN-Hash): PINs, Hashes, Schlüssel, 2FA, Wiederherstellung
                  '_pin', 'pin_', 'hash', '_key', 'wfls_', '2fa', 'totp', 'recovery', 'application_password', 'credential'];

    /**
     * Konto-Meta im Spiegel: NUR diese Felder (Erlaubnisliste, 1.38.470). Alles andere – auch neue _tix_-Felder –
     * bleibt draußen, bis es hier bewusst aufgenommen wird. Prämien laufen getrennt (loyalty).
     */
    const CUSTOMER_META_ALLOW = ['_tix_phone', '_tix_email_confirmed', '_tix_email_confirmed_by', '_tix_team_organizer_id',
                  '_tix_organizer_name', '_tix_saved_events', '_tix_app_following', '_tix_app_verified',
                  '_tix_app_created', '_tix_first_event_celebrated', '_tix_legacy_source', '_tix_legacy_user_id',
                  '_tix_avatar_url', '_tix_avatar_id'];

    /** Werte, die wie Passwort-/PIN-Hashes aussehen, gehen nie in den Spiegel (egal unter welchem Feldnamen). */
    public static function looks_like_hash($v) {
        return is_string($v) && preg_match('/^\$(P|H|wp|2y|2a|2b|argon2id?|scrypt|pbkdf2[^$]*|[156])\$/', $v) === 1;
    }

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
        $plan = class_exists('TIX_Mirror_Extra') ? TIX_Mirror_Extra::plan($entity, $id, $op, $payload) : null;
        return $plan !== null ? $plan : new WP_Error('entity', 'Unbekannter Typ ' . $entity);
    }

    // ═══════════════ Sandbox (Live-Demo Vertrieb) ═══════════════
    // Demo Club und Vorschau-Veranstalter dürfen NIE in den Spiegel (Vertrag Live-Demo, Entscheid 4).
    // Quelle: TIX_Sales::is_sandbox_organizer($oid), zusätzlich Filter tix_sandbox_organizer.
    // Übernommene Vorschauen sind danach keine Sandbox mehr und kommen normal in den Spiegel.

    private static $sandbox_orgs = [];
    private static $sandbox_events = [];

    public static function sandbox_reset() { self::$sandbox_orgs = []; self::$sandbox_events = []; }

    public static function is_sandbox_org($oid) {
        $oid = (int) $oid;
        if ($oid <= 0) return false;
        if (!isset(self::$sandbox_orgs[$oid])) {
            $v = class_exists('TIX_Sales') && method_exists('TIX_Sales', 'is_sandbox_organizer') ? (bool) TIX_Sales::is_sandbox_organizer($oid) : false;
            self::$sandbox_orgs[$oid] = (bool) apply_filters('tix_sandbox_organizer', $v, $oid);
        }
        return self::$sandbox_orgs[$oid];
    }

    /** Event eines Sandbox-Veranstalters (maßgeblich ist der Haupt-Veranstalter). */
    public static function is_sandbox_event($eid) {
        $eid = (int) $eid;
        if ($eid <= 0) return false;
        if (!isset(self::$sandbox_events[$eid])) self::$sandbox_events[$eid] = self::is_sandbox_org((int) get_post_meta($eid, '_tix_organizer_id', true));
        return self::$sandbox_events[$eid];
    }

    /** Bestellung mit mindestens einer Position eines Sandbox-Events (bzw. Kasse an einem solchen Event). */
    public static function is_sandbox_order($order_id) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) return false;
        $prev = $wpdb->suppress_errors(true);
        $eids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", $order_id));
        $wpdb->suppress_errors($prev);
        // Zweite Sicherung: Demo-Bestellungen tragen die Zahlart 'demo' (TIX_Sales, 1.38.508)
        $prev = $wpdb->suppress_errors(true);
        $pm = (string) $wpdb->get_var($wpdb->prepare("SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id));
        $wpdb->suppress_errors($prev);
        if ($pm === 'demo') return true;
        $pos = get_option('_tix_pos_order_' . $order_id, null);
        if (is_array($pos) && !empty($pos['event_id'])) $eids[] = (int) $pos['event_id'];
        foreach ((array) $eids as $e) if (self::is_sandbox_event((int) $e)) return true;
        return false;
    }

    /** Markierung anonymisierter Datensätze (TIX_Account_Delete, 1.38.460). */
    const ANON_MARK = '@removed.invalid';

    public static function is_anonymized($email) {
        return substr(strtolower((string) $email), -strlen(self::ANON_MARK)) === self::ANON_MARK;
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

    /** Für Paket-2-Pläne: ungültiges UTF-8 entfernen. */
    public static function clean($v) { return self::json_safe($v); }

    private static function json_safe($v) {
        // Hash-artige Werte nie senden; ungültiges UTF-8 würde den ganzen Bündel-Upsert scheitern lassen.
        if (is_string($v)) return self::looks_like_hash($v) ? null : wp_check_invalid_utf8($v, true);
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = self::json_safe($x); }
        // (object)-Felder wie data/billing ebenfalls prüfen (sonst entgehen ihre Werte dem Filter)
        if ($v instanceof \stdClass) { $a = (array) $v; foreach ($a as $k => $x) $a[$k] = self::json_safe($x); $v = (object) $a; }
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
        if (!$p || $p->post_type !== 'event' || $p->post_status === 'auto-draft' || self::is_sandbox_event($id)) {
            return [self::del('events', $site, $id),
                    ['delete', 'ticket_categories', ['site' => 'eq.' . $site, 'event_id' => 'eq.' . $id]],
                    ['delete', 'guests', ['site' => 'eq.' . $site, 'event_id' => 'eq.' . $id]]];
        }
        $m = self::post_meta($id, ['_tix_ticket_categories', '_tix_guests']);
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
        $guests = self::guest_rows($site, $id);
        if ($guests) $ops[] = ['upsert', 'guests', $guests];
        $ops[] = ['prune', 'guests', $id, array_column($guests, 'id')];
        // Entfernte Kategorien im Spiegel löschen (alles, was nicht mehr im Array steht).
        $ops[] = ['prune', 'ticket_categories', $id, array_column($cat_rows, 'idx')];
        return $ops;
    }

    /** Gästelisten eines Events: _tix_guest_list (mit id) und die Dashboard-Liste _tix_guestlist (ohne id). */
    public static function guest_rows($site, $event_id) {
        $rows = [];
        $seen = [];
        $list = get_post_meta($event_id, '_tix_guest_list', true);
        foreach (is_array($list) ? $list : [] as $i => $g) {
            if (!is_array($g)) continue;
            $gid = (string) ($g['id'] ?? '') !== '' ? (string) $g['id'] : 'i' . $i;
            $key = $event_id . ':' . $gid;
            if (isset($seen[$key])) $key .= ':' . $i; // doppelte IDs nicht verlieren
            $seen[$key] = true;
            $rows[] = self::guest_row($site, $event_id, $key, 'guest_list', $g);
        }
        $legacy = get_post_meta($event_id, '_tix_guests', true); // ältere Liste je Ticket (Name/E-Mail zum Code)
        foreach (is_array($legacy) ? $legacy : [] as $i => $g) {
            if (!is_array($g)) continue;
            $rows[] = self::guest_row($site, $event_id, $event_id . ':t' . $i, 'tickets', $g);
        }
        $dash = get_post_meta($event_id, '_tix_guestlist', true);
        foreach (is_array($dash) ? $dash : [] as $i => $g) {
            if (!is_array($g)) continue;
            $rows[] = self::guest_row($site, $event_id, $event_id . ':d' . $i, 'dashboard', ['plus' => max(0, (int) ($g['tickets'] ?? 1) - 1)] + $g);
        }
        return $rows;
    }

    private static function guest_row($site, $event_id, $id, $source, array $g) {
        $data = array_diff_key($g, array_flip(['name', 'email', 'plus', 'note', 'checked_in', 'checked_in_count', 'checkin_time', 'checkin_by']));
        return self::json_safe([
            'site' => $site, 'id' => $id,
            'event_id'         => $event_id,
            'source'           => $source,
            'name'             => (string) ($g['name'] ?? ''),
            'email'            => (string) ($g['email'] ?? ''),
            'plus'             => (int) ($g['plus'] ?? 0),
            'note'             => (string) ($g['note'] ?? ''),
            'checked_in'       => self::flag($g['checked_in'] ?? ''),
            'checked_in_count' => (int) ($g['checked_in_count'] ?? 0),
            'checkin_time'     => (string) ($g['checkin_time'] ?? ''),
            'checkin_by'       => (string) ($g['checkin_by'] ?? ''),
            'data'             => (object) $data,
        ]);
    }

    // ── Kunden (WP-Nutzer) ──

    const USER_META = ['first_name', 'last_name', 'nickname', 'billing_first_name', 'billing_last_name', 'billing_company',
                       'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_city', 'billing_country',
                       'billing_phone', 'billing_email'];

    /** Löst eine Nutzer-Meta-Änderung einen Spiegel-Eintrag aus? */
    public static function user_meta_relevant($key) {
        if (strpos($key, '_tix_loyalty_points') === 0 || strpos($key, '_tix_loyalty_log') === 0 || strpos($key, '_tix_loyalty_stamped') === 0) return true;
        if (substr($key, -12) === 'capabilities') return true;
        return self::customer_meta_allowed($key);
    }

    /** Darf dieses Konto-Meta-Feld in customers.data? (Erlaubnisliste + Sperrliste als zweite Sicherung) */
    public static function customer_meta_allowed($key) {
        if (self::is_sensitive($key)) return false;
        return in_array($key, self::USER_META, true) || in_array($key, self::CUSTOMER_META_ALLOW, true);
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
            if (!self::customer_meta_allowed($k)) continue; // Erlaubnisliste (1.38.470); Prämien → loyalty
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
        $ops = [['upsert', 'customers', [self::json_safe($row)]]];
        $loyalty = self::loyalty_rows($site, $id);
        if ($loyalty) $ops[] = ['upsert', 'loyalty', $loyalty];
        $ops[] = ['prune', 'loyalty', $id, array_column($loyalty, 'id')];
        return $ops;
    }

    /** Stempelkarte je Konto: _tix_loyalty_points[_o<veranstalter>] + _log + _stamped. */
    public static function loyalty_rows($site, $user_id) {
        $rows = [];
        foreach ((array) get_user_meta($user_id) as $k => $vals) {
            if (!preg_match('/^_tix_loyalty_points(?:_o(\d+))?$/', $k, $m)) continue;
            $org = isset($m[1]) ? (int) $m[1] : 0;
            if ($org && self::is_sandbox_org($org)) continue; // Demo/Vorschau
            $sfx = $org ? '_o' . $org : '';
            $log = get_user_meta($user_id, '_tix_loyalty_log' . $sfx, true);
            $stamped = get_user_meta($user_id, '_tix_loyalty_stamped' . $sfx, true);
            $rows[] = self::json_safe([
                'site' => $site, 'id' => $user_id . ':' . $org,
                'user_id'      => $user_id,
                'organizer_id' => $org,
                'points'       => (int) maybe_unserialize($vals[0] ?? 0),
                'log'          => array_values(is_array($log) ? $log : []),
                'stamped'      => array_values(is_array($stamped) ? $stamped : []),
            ]);
        }
        return $rows;
    }

    // ── Bestellungen + Positionen + Zahlung ──

    private static function plan_order($site, $id) {
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT *, UNIX_TIMESTAMP(date_modified) AS modified_ts FROM {$wpdb->prefix}tix_orders WHERE id = %d", $id), ARRAY_A);
        if (!$o) {
            return [self::del('orders', $site, $id), self::del('payments', $site, $id),
                    ['delete', 'order_items', ['site' => 'eq.' . $site, 'order_id' => 'eq.' . $id]]];
        }
        if (self::is_sandbox_order($id)) { // Demo/Vorschau: nie im Spiegel
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
        // Pixel-Einwilligung (1.38.458) als Post-Meta an der Bestell-ID
        if (get_post_meta($id, '_tix_meta_consent', true) === '1') $data['meta_consent'] = true;
        elseif (get_post_meta($id, '_tix_meta_no_consent', true) === '1') $data['meta_consent'] = false;
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
            'retained_until'       => self::retained_until($o),
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
        // Anonymisiert (Konto gelöscht bzw. Aufbewahrungsfrist vorbei): älteren Verlauf mit Personenbezug tilgen.
        if (self::is_anonymized($o['billing_email'])) $ops[] = ['purge', 'orders', $id];
        return $ops;
    }

    /**
     * Aufbewahrte Rechnungsdaten (1.38.461): Konto gelöscht (E-Mail anonymisiert), Name bzw. Anschrift stehen noch
     * → aufbewahrt bis 31.12.(Bestelljahr + Frist). Nach dem Ablauf-Cron (Name „[Gelöscht]“, Anschrift leer) → null.
     */
    public static function retained_until(array $o) {
        if (!self::is_anonymized($o['billing_email'] ?? '')) return null;
        $kept = (($o['billing_first_name'] ?? '') !== '' && $o['billing_first_name'] !== '[Gelöscht]')
            || ($o['billing_last_name'] ?? '') !== '' || ($o['billing_company'] ?? '') !== ''
            || ($o['billing_address_1'] ?? '') !== '' || ($o['billing_city'] ?? '') !== '';
        if (!$kept) return null;
        if (class_exists('TIX_Account_Delete') && method_exists('TIX_Account_Delete', 'retained_until')) {
            return (string) TIX_Account_Delete::retained_until($o['date_created'] ?? '');
        }
        $y = (int) substr((string) ($o['date_created'] ?? ''), 0, 4) ?: (int) current_time('Y');
        return ($y + 10) . '-12-31';
    }

    // ── Tickets ──

    private static function plan_ticket($site, $id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'tix_ticket' || self::is_sandbox_event((int) get_post_meta($id, '_tix_ticket_event_id', true))) return [self::del('tickets', $site, $id)];
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
        $ops = [['upsert', 'tickets', [self::json_safe($row)]]];
        if (self::is_anonymized($row['owner_email'])) $ops[] = ['purge', 'tickets', $id];
        return $ops;
    }

    // ── Ereignisse ohne eigene WP-Zeile (Check-in-Verlauf, Stornos/Erstattungen) ──

    private static function plan_event_row($site, $table, $id, $payload) {
        if (!is_array($payload)) return new WP_Error('payload', 'Ereignis ohne Inhalt');
        // Demo/Vorschau: Check-ins und Stornos von Sandbox-Events nie spiegeln
        if (!empty($payload['event_id']) && self::is_sandbox_event((int) $payload['event_id'])) return [];
        if (!empty($payload['ticket_id']) && self::is_sandbox_event((int) get_post_meta((int) $payload['ticket_id'], '_tix_ticket_event_id', true))) return [];
        if ($table === 'refunds' && !empty($payload['order_id']) && self::is_sandbox_order((int) $payload['order_id'])) return [];
        $row = array_merge($payload, ['site' => $site, 'id' => (string) $id]);
        return [['upsert', $table, [self::json_safe($row)]]];
    }
}
