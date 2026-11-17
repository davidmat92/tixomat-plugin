<?php
/**
 * Einstellbare Kassenfelder – Geburtsdatum (1.38.875, §237): reine Prüfung, Standard + Event, App-Kasse, Kasse vor Ort, Web-Prüfung,
 * Bestell-Detail, Export, Statistik (Altersgruppen, Regionen), Anonymisieren.
 *   wp eval-file …/tests/test-checkout-fields-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Checkout_Fields')) { echo "Tixomat ab 1.38.875 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = ['tix_settings' => get_option('tix_settings'), TIX_Sale_Settings::OPT => get_option(TIX_Sale_Settings::OPT, null), 'tix_invoices_enabled' => get_option('tix_invoices_enabled', null)];
$posts = []; $orders = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(20, 200);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_app\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_app\\_rl\\_%'");
try {
    $C = 'TIX_Checkout_Fields';
    // ── Reine Funktionen ──
    $today = '2026-11-17';
    $eq('Datum JJJJ-MM-TT / TT.MM.JJJJ / 1-stellig', ['2001-04-23', '2001-04-23', '2001-04-03'],
        [$C::parse_birthdate('2001-04-23', $today)['ok'] ?? null, $C::parse_birthdate('23.04.2001', $today)['ok'] ?? null, $C::parse_birthdate('3.4.2001', $today)['ok'] ?? null]);
    $eq('Kein echtes Datum / falsches Format → format', ['format', 'format', 'format', 'format'],
        [$C::parse_birthdate('2001-02-30', $today)['error'] ?? null, $C::parse_birthdate('23/04/2001', $today)['error'] ?? null, $C::parse_birthdate('gestern', $today)['error'] ?? null, $C::parse_birthdate('', $today)['error'] ?? null]);
    $eq('Zukunft / älter als 120 → range; genau heute und genau 120 ok', ['range', 'range', '2026-11-17', '1906-11-17'],
        [$C::parse_birthdate('2026-11-18', $today)['error'] ?? null, $C::parse_birthdate('1906-11-16', $today)['error'] ?? null, $C::parse_birthdate('2026-11-17', $today)['ok'] ?? null, $C::parse_birthdate('1906-11-17', $today)['ok'] ?? null]);
    $eq('Alter am Tag (vor/an/nach Geburtstag, 29.2.)', [24, 25, 25, 3], [$C::age_on('2001-04-23', '2026-04-22'), $C::age_on('2001-04-23', '2026-04-23'), $C::age_on('2001-04-23', '2026-11-17'), $C::age_on('2020-02-29', '2024-02-28')]);
    $eq('Altersgruppen', ['u18', '18-20', '18-20', '21-24', '25-29', '30-39', '40+'], array_map([$C, 'age_group'], [17, 18, 20, 21, 29, 39, 40]));
    $eq('Strengstes / wirksam', ['required', 'optional', 'off', 'optional', 'off'],
        [$C::strictest(['off', 'required', 'optional']), $C::strictest(['off', 'optional']), $C::strictest([]), $C::effective(null, 'optional'), $C::effective('off', 'required')]);
    $eq('Region: DE-PLZ zwei Ziffern, AT Land, ungültig leer', [['40', '40…'], ['AT', 'AT'], ['', '']], [$C::region_key('40789', 'DE'), $C::region_key('1010', 'at'), $C::region_key('123', 'DE')]);
    $eq('Grenzen 120 Jahre', ['1906-11-17', '2026-11-17'], $C::bounds($today));

    // ── Aufbau ──
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    update_option('tix_invoices_enabled', '0');
    $org = 0;
    if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Kassenfelder Veranstalter']); $posts[] = $org; update_post_meta($org, '_tix_org_approval', 'approved'); TIX_Object_Data::remove(TIX_Sale_Settings::OBJ, $org); }
    else delete_option(TIX_Sale_Settings::OPT);
    $q = $multi ? ['organizer_id' => $org] : [];
    $evd = wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS);
    $mk = function ($t) use ($org, $evd, &$posts) { $e = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => $t, 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => $evd, '_tix_time_start' => '20:00',
        '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => [['name' => 'Gratis', 'price' => 0, 'qty' => 50]]]]); $posts[] = $e; return $e; };
    $ev = $mk('Kassenfelder Abend'); $ev2 = $mk('Kassenfelder Zweit');

    // ── Vorgabe aus: nichts ändert sich ──
    wp_set_current_user(0);
    [$st, $d] = $rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]]]);
    $eq('Vorgabe: Angebot birthdate off, extra leer', [200, 'off', []], [$st, $d['checkout_fields']['birthdate'] ?? null, $d['checkout_fields']['extra'] ?? null]);
    $order = function ($eid, $extra = null, $mail = null) use ($rest, &$orders) {
        $b = ['event_id' => $eid, 'items' => [['index' => 0, 'qty' => 1]], 'billing' => ['first_name' => 'Alex', 'last_name' => 'Feld', 'email' => $mail ?: 'kf_' . wp_rand(10000, 99999) . '@example.test', 'address_1' => 'Hauptstr. 1', 'postcode' => '40789', 'city' => 'Monheim', 'country' => 'DE'],
            'payment_method' => 'free', 'accept_terms' => 1, 'idempotency_token' => wp_generate_password(20, false)];
        if ($extra !== null) $b['extra_fields'] = $extra;
        [$st, $d, $c] = $rest('POST', '/customer/orders', $b);
        $oid = intval($d['order']['id'] ?? 0); if ($oid) $orders[] = $oid;
        return [$st, $d, $c, $oid];
    };
    [$st, , , $o0] = $order($ev, ['birthdate' => '2001-04-23']);
    $eq('Aus: Bestellung geht, mitgeschicktes Datum wird NICHT gespeichert', [true, null], [in_array($st, [200, 201], true), $C::for_order($o0)]);

    // ── Standard optional (Kassen-Einstellungen) ──
    wp_set_current_user($adm);
    [$st, $d] = $rest('POST', '/studio/checkout-settings', ['checkout' => ['birthdate_field' => 'optional']], $q);
    $eq('Kassen-Einstellungen: birthdate_field speichern + Optionen', [200, 'optional', ['off', 'optional', 'required']], [$st, $d['checkout']['birthdate_field'] ?? null, $d['options']['checkout.birthdate_field'] ?? null]);
    [$st, $d] = $rest('POST', '/studio/checkout-settings', ['checkout' => ['birthdate_field' => 'immer']], $q);
    $eq('Ungültiger Modus → 400 checkout.birthdate_field', [400, 'checkout.birthdate_field'], [$st, $d['data']['field'] ?? null]);
    wp_set_current_user(0);
    [$st, $d] = $rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]]]);
    $x = $d['checkout_fields']['extra'][0] ?? [];
    $eq('Optional: Angebot meldet Feld (Typ, Modus, Hinweis, Grenzen)', ['optional', 'birthdate', 'date', 'optional', 'Geburtsdatum', true, current_time('Y-m-d')],
        [$d['checkout_fields']['birthdate'] ?? null, $x['key'] ?? null, $x['type'] ?? null, $x['mode'] ?? null, $x['label'] ?? null, strpos((string) ($x['hint'] ?? ''), 'Alterskontrolle') !== false, $x['max'] ?? null]);
    [$st] = $order($ev);
    $eq('Optional: ohne Datum geht', true, in_array($st, [200, 201], true));
    [$st, $d, $c] = $order($ev, ['birthdate' => '31.02.2000']);
    $eq('Optional: falsches Datum → 400 tix_extra_field birthdate', [400, 'tix_extra_field', 'birthdate'], [$st, $c, $d['data']['field'] ?? null]);

    // ── Event überschreibt: Pflicht ──
    wp_set_current_user($adm);
    [$st, $d] = $rest('GET', "/events/$ev/extras");
    $eq('Editor: inherit, wirksam = Standard optional', [200, 'inherit', 'optional', 'optional'], [$st, $d['checkout_fields']['birthdate'] ?? null, $d['checkout_fields_effective']['birthdate'] ?? null, $d['checkout_fields_default']['birthdate'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['checkout_fields' => ['birthdate' => 'required']]);
    $eq('Editor: Pflicht speichern', [200, 'required', 'required', ['birthdate' => 'required']], [$st, $d['checkout_fields']['birthdate'] ?? null, $d['checkout_fields_effective']['birthdate'] ?? null, get_post_meta($ev, '_tix_checkout_fields', true)]);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['checkout_fields' => ['birthdate' => 'ja']]);
    $eq('Editor: ungültiger Wert → 400', [400, 'checkout_fields.birthdate'], [$st, $d['data']['field'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['checkout_fields' => ['schuhgroesse' => 'optional']]);
    $eq('Editor: unbekanntes Feld → 400', [400, 'checkout_fields.schuhgroesse'], [$st, $d['data']['field'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev2/extras", ['checkout_fields' => ['birthdate' => 'off']]);
    $eq('Editor: zweites Event aus (trotz Standard optional)', 'off', $d['checkout_fields_effective']['birthdate'] ?? null);

    wp_set_current_user(0);
    [$st, $d] = $rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]]]);
    $eq('Pflicht: Angebot required', ['required', 'required'], [$d['checkout_fields']['birthdate'] ?? null, $d['checkout_fields']['extra'][0]['mode'] ?? null]);
    [$st, $d, $c] = $order($ev);
    $eq('Pflicht: ohne Datum → 400 „Bitte Geburtsdatum angeben.“', [400, 'tix_extra_field', 'birthdate', 'Bitte Geburtsdatum angeben.'], [$st, $c, $d['data']['field'] ?? null, $d['message'] ?? null]);
    [$st, $d, $c] = $order($ev, ['birthdate' => wp_date('Y-m-d', time() + 3 * DAY_IN_SECONDS)]);
    $eq('Pflicht: Datum in der Zukunft → 400', [400, 'tix_extra_field'], [$st, $c]);
    $b20 = (intval(substr($evd, 0, 4)) - 20) . substr($evd, 4); // am Event-Tag genau 20
    [$st, $d, , $o1] = $order($ev, ['birthdate' => date('d.m.Y', strtotime($b20))], 'kf_anon_' . wp_rand(10000, 99999) . '@example.test');
    $eq('Pflicht: TT.MM.JJJJ → Bestellung, gespeichert (verschlüsselt) mit Alter am Event-Tag', [true, $b20, 20],
        [in_array($st, [200, 201], true), $C::for_order($o1)['birthdate'] ?? null, $C::for_order($o1)['age'] ?? null]);
    $raw = TIX_Object_Data::read('_tix_order_fields', $o1, []);
    $eq('Ablage: kein Klartext-Datum, age_on = Event-Tag', [false, $evd], [strpos(wp_json_encode($raw), $b20) !== false, $raw['age_on'] ?? null]);
    [$st, , , $o2] = $order($ev, ['birthdate' => '1990-01-15']);
    [$st, , , $o3] = $order($ev, ['birthdate' => '2012-06-01']);
    [$st, , , $o4] = $order($ev2, ['birthdate' => '1999-09-09']);
    $eq('Event aus: Datum nicht gespeichert', null, $C::for_order($o4));

    // ── Kasse vor Ort: nie Pflicht ──
    wp_set_current_user($adm);
    [$st, $d] = $rest('GET', '/pos/config', null, ['event_id' => $ev]);
    $eq('/pos/config: Pflicht wird an der Kasse optional', [200, 'optional'], [$st, $d['checkout']['birthdate'] ?? null]);
    [$st, $d] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]], 'payment' => 'free', 'customer_name' => 'Kasse Test']);
    $op = intval($d['order_id'] ?? ($d['order']['id'] ?? 0)); if ($op) $orders[] = $op;
    $eq('Kasse vor Ort: ohne Datum geht', 200, $st);
    [$st, $d, $c] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]], 'payment' => 'free', 'customer_name' => 'Kasse Test', 'extra_fields' => ['birthdate' => '99.99.1999']]);
    $eq('Kasse vor Ort: falsches Datum → 400', [400, 'tix_extra_field'], [$st, $c]);
    [$st, $d] = $rest('POST', '/pos/orders', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]], 'payment' => 'free', 'customer_name' => 'Kasse Test', 'extra_fields' => ['birthdate' => '1985-03-03']]);
    $op2 = intval($d['order_id'] ?? ($d['order']['id'] ?? 0)); if ($op2) $orders[] = $op2;
    $eq('Kasse vor Ort: mit Datum gespeichert', [200, '1985-03-03'], [$st, $C::for_order($op2)['birthdate'] ?? null]);

    // ── Web-Kasse: gleiche Prüfung, Feldname tix_extra[…], mehrere Events = strengstes ──
    $wm = $C::for_events([$ev2, $ev]);
    $eq('Web: Warenkorb aus + Pflicht → Pflicht', 'required', $wm['birthdate']);
    $e = $C::validate($wm, [], false, 'tix_extra[%s]');
    $eq('Web: fehlt → Fehler am Feld tix_extra[birthdate]', ['tix_extra_field', 'tix_extra[birthdate]'], [is_wp_error($e) ? $e->get_error_code() : null, is_wp_error($e) ? ($e->get_error_data()['field'] ?? null) : null]);
    $eq('Web: gültig → bereinigt', ['birthdate' => '2000-01-31'], $C::validate($wm, ['birthdate' => '31.01.2000'], false, 'tix_extra[%s]'));
    $eq('Web: Formular hat Datumsfeld (Render-Teil)', true, (function () use ($ev) {
        $html = ''; $defs = TIX_Checkout_Fields::definitions(TIX_Checkout_Fields::for_events([$ev]));
        return count($defs) === 1 && $defs[0]['mode'] === 'required';
    })());

    // ── Anzeige: Detail, Export ──
    [$st, $d] = $rest('GET', "/orders/$o2/detail");
    $eq('Bestell-Detail: extra_fields {birthdate, age}', [200, '1990-01-15', $C::age_on('1990-01-15', $evd)], [$st, $d['order']['extra_fields']['birthdate'] ?? null, $d['order']['extra_fields']['age'] ?? null]);
    [$st, $d] = $rest('GET', "/orders/$op/detail");
    $eq('Bestell-Detail ohne Angabe → null', [200, true, null], [$st, array_key_exists('extra_fields', (array) ($d['order'] ?? [])), $d['order']['extra_fields'] ?? null]);
    $eq('Export-Kopf endet mit Geburtsdatum', 'Geburtsdatum', TIX_Export::ORDER_HEADER[count(TIX_Export::ORDER_HEADER) - 1]);
    [$st, $d] = $rest('GET', '/organizer/export', null, ['type' => 'orders', 'event_id' => $ev, 'status' => 'all']);
    $csv = (string) base64_decode((string) ($d['data'] ?? ''));
    $eq('Export: Spalte Geburtsdatum mit TT.MM.JJJJ', [200, true, true], [$st, strpos($csv, 'Geburtsdatum') !== false, strpos($csv, '15.01.1990') !== false]);

    // ── Statistik ──
    $from = wp_date('Y-m-d', time() - DAY_IN_SECONDS); $to = wp_date('Y-m-d', time() + DAY_IN_SECONDS);
    [$st, $d] = $rest('GET', '/organizer/stats/audience', null, ['event_id' => $ev, 'from' => $from, 'to' => $to] + ($multi ? ['organizer' => $org] : []));
    $ages = $d['audience']['ages'] ?? []; $g = [];
    foreach ((array) ($ages['groups'] ?? []) as $x) $g[$x['key']] = $x['n'];
    $eq('Statistik: Altersgruppen (20 → 18–20, 1990 → 30–39, 2012 → u18, Kasse 1985 → 40+)', [200, 4, 1, 1, 1, 1], [$st, $ages['known'] ?? null, $g['18-20'] ?? null, $g['30-39'] ?? null, $g['u18'] ?? null, $g['40+'] ?? null]);
    $eq('Statistik: Bestellungen gesamt ≥ bekannt, Anteil in %', [true, 25.0], [($ages['orders'] ?? 0) >= 4, (function ($gr) { foreach ($gr as $x) if ($x['key'] === 'u18') return $x['share']; return null; })((array) ($ages['groups'] ?? []))]);
    $reg = $d['audience']['regions'] ?? [];
    $eq('Statistik: Region 40… Monheim (alle App-Bestellungen dort)', ['40', true], [$reg['items'][0]['key'] ?? null, strpos((string) ($reg['items'][0]['label'] ?? ''), 'Monheim') !== false]);
    $eq('Statistik: kleine Gruppen → weitere', [['key' => 'other']], array_map(function ($i) { return ['key' => $i['key']]; }, array_values(array_filter($C::region_stats([$o1, $o2])['items'] ?? [], function ($i) { return true; }))));

    // ── Anonymisieren ──
    $C::forget($o1);
    $eq('Anonymisieren: Datum weg, Alter bleibt', [null, 20], [(array) $C::for_order($o1) === [] ? 'x' : $C::for_order($o1)['birthdate'], $C::for_order($o1)['age'] ?? null]);
    $eq('Anonymisieren hängt in TIX_Account_Delete', true, strpos((string) file_get_contents(TIXOMAT_PATH . 'includes/class-tix-account-delete.php'), 'TIX_Checkout_Fields::forget($oid)') !== false);

    // ── Editor: zurück auf Standard ──
    wp_set_current_user($adm);
    [$st, $d] = $rest('POST', "/events/$ev/extras", ['checkout_fields' => ['birthdate' => 'inherit']]);
    $eq('Editor: inherit entfernt Meta, wirksam wieder optional', ['inherit', 'optional', ''], [$d['checkout_fields']['birthdate'] ?? null, $d['checkout_fields_effective']['birthdate'] ?? null, get_post_meta($ev, '_tix_checkout_fields', true)]);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($orders as $oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid]) as $t) wp_delete_post($t, true);
        foreach (['tix_order_items', 'tix_order_notes'] as $tb) $wpdb->delete($wpdb->prefix . $tb, ['order_id' => $oid]);
        $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
        TIX_Object_Data::remove('_tix_order_fields', $oid);
        delete_option('_tix_pos_order_' . $oid); delete_option('_tix_order_source_' . $oid);
    }
    foreach (array_reverse($posts) as $p) { if (get_post_type($p) === 'tix_organizer') TIX_Object_Data::remove(TIX_Sale_Settings::OBJ, $p); wp_delete_post($p, true); }
    foreach ($keep as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
