<?php
/**
 * Zwei-Seiten-Test Umzug Provision → selbstgehostet (1.38.702) über echtes HTTP – nur lokale Test-Seiten.
 * Plattform (Mehr-Veranstalter, z. B. http://tqc-plat) und neue Seite (Einzel-Seite, z. B. http://tqc-kk) als eigene Webserver.
 *
 *   plat eval-file … setup-plat <new_site_api> <key_in> <key_out>     → JSON {key, token, api, oid, events, codes}
 *   kk   eval-file … run-kk <plat_api> <json>       (Import trocken + echt + zweiter Lauf, Einlass mit alten QR-Codes)
 *   plat eval-file … run-plat <json>                (Rückmeldung, Abschluss, Spiegel, Abrechnung, Kundenart)
 *   kk   eval-file … push-kk <json>  ·  plat eval-file … check-push-plat <json> <json aus push-kk>
 *   kk   eval-file … cleanup-kk   ·   plat eval-file … cleanup-plat
 */
if (!defined('ABSPATH') || !class_exists('TIX_Site_Move')) { echo "WordPress mit TIX_Site_Move nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = (string) ($args[0] ?? '');
$GLOBALS['sm_f'] = 0; $GLOBALS['sm_n'] = 0;
function sm_eq($label, $exp, $act) {
    $GLOBALS['sm_n']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['sm_f']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function sm_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : (array) $d];
}
function sm_done() { echo ($GLOBALS['sm_f'] ? "FEHLER: {$GLOBALS['sm_f']}" : 'ALLES OK') . " ({$GLOBALS['sm_n']} Prüfungen)\n"; }
global $wpdb;

if ($mode === 'setup-plat') {
    if (!TIX_App_Scope::multi()) { echo "nur Plattform\n"; return; }
    $admin = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user(intval($admin[0]->ID));
    $owner = wp_insert_user(['user_login' => 'smtest_owner_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'smowner' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_organizer']);
    $oid = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SMTEST Veranstalter', 'meta_input' => ['_tix_org_city' => 'Köln', '_tix_org_iban' => 'DE00GEHEIM', '_tix_org_user_id' => $owner]]);
    $loc = wp_insert_post(['post_type' => 'tix_location', 'post_status' => 'publish', 'post_title' => 'SMTEST Halle', 'meta_input' => ['_tix_loc_city' => 'Köln']]);
    $cats = [['name' => 'Regulär', 'price' => 20, 'qty' => 100, 'stock' => 98]];
    $evs = [];
    foreach ([1, 2] as $i) {
        $evs[] = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "SMTEST Party $i", 'post_content' => 'Text',
            'meta_input' => ['_tix_organizer_id' => $oid, '_tix_location_id' => $loc, '_tix_date_start' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS), '_tix_time_start' => '22:00',
                '_tix_tickets_enabled' => '1', '_tix_ticket_categories' => $cats, '_tix_settled_at' => '', '_tix_guest_list' => [['id' => 'SMG' . $i, 'name' => "Gast $i", 'plus' => 0, 'checked_in' => false]]]]);
    }
    delete_post_meta($evs[0], '_tix_settled_at'); delete_post_meta($evs[1], '_tix_settled_at');
    // eine Bestellung mit 2 Tickets für Event 1
    $num = 'SMT-' . wp_rand(10000, 99999);
    $wpdb->insert(TIX_Order::table_name(), ['order_number' => $num, 'status' => 'completed', 'total' => 40, 'subtotal' => 40, 'payment_method' => 'stripe', 'billing_first_name' => 'Kim', 'billing_last_name' => 'Kauf', 'billing_email' => 'smkauf@example.test', 'order_key' => 'key_' . wp_rand()]);
    $order = intval($wpdb->insert_id);
    $wpdb->insert(TIX_Order::items_table_name(), ['order_id' => $order, 'event_id' => $evs[0], 'quantity' => 2, 'total' => 40, 'name' => 'Regulär', 'cat_name' => 'Regulär']);
    $codes = [];
    foreach ([1, 2] as $i) {
        $code = strtoupper(wp_generate_password(12, false));
        $tid = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => "SMTEST Ticket $i",
            'meta_input' => ['_tix_ticket_code' => $code, '_tix_ticket_event_id' => $evs[0], '_tix_ticket_order_id' => $order, '_tix_ticket_status' => 'valid', '_tix_ticket_owner_email' => 'smkauf@example.test', '_tix_ticket_cat_index' => 0]]);
        $codes[] = $code;
    }
    // Co-Event eines anderen Erstellers
    $other = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SMTEST Ersteller']);
    $co = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SMTEST Co-Event', 'meta_input' => ['_tix_organizer_id' => $other, '_tix_date_start' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS), '_tix_time_start' => '21:00',
        '_tix_tickets_enabled' => '1', '_tix_ticket_categories' => [['name' => 'Abend', 'price' => 15, 'qty' => 50, 'stock' => 50]], '_tix_guest_list' => [['id' => 'SMCO1', 'name' => 'Co Gast', 'plus' => 0, 'checked_in' => false]]]]);
    TIX_Co_Organizer::set($co, $oid, true, 1);
    if (class_exists('TIX_Newsletter')) $wpdb->insert(TIX_Newsletter::tc(), ['organizer_id' => $oid, 'email' => 'smnl@example.test', 'status' => 'subscribed']);
    $cp = (array) get_option('tix_coupons', []); $cp['SMTEST10'] = ['type' => 'percent', 'amount' => 10, 'organizer_id' => $oid, 'event_ids' => [$evs[0]]]; update_option('tix_coupons', $cp);
    // Partner = neue Seite
    $host = sanitize_key((string) wp_parse_url((string) $args[1], PHP_URL_HOST));
    $all = (array) get_option(TIX_Partners::OPTION, []);
    $set = get_option('tix_settings', []);
    update_option('tix_sm_backup', ['owner' => $owner, 'recv' => $set['syndication_receive_enabled'] ?? '__none__', 'partners' => $all, 'oids' => [$oid, $other], 'posts' => array_merge($evs, [$co, $loc]), 'order' => $order], false);
    $all[$host] = ['id' => $host, 'name' => 'SMTEST Neue Seite', 'api_base' => rtrim((string) $args[1], '/'), 'key_in' => (string) ($args[2] ?? 'kin-smtest-' . str_repeat('a', 24)), 'key_out' => (string) ($args[3] ?? 'kout-smtest-' . str_repeat('b', 24)), 'organizer_id' => 0, 'created' => gmdate('c')];
    update_option(TIX_Partners::OPTION, $all, false);
    $set['syndication_receive_enabled'] = 1; update_option('tix_settings', $set);
    TIX_Platform_Customers::flush();
    [$st, $d] = sm_rest('POST', "/platform/customers/v-$oid/move");
    echo wp_json_encode(['status' => $st, 'key' => "v-$oid", 'oid' => $oid, 'token' => $d['token'] ?? '', 'events' => $evs, 'co' => $co, 'other' => $other, 'codes' => $codes, 'partner' => $host, 'order_number' => $num]) . "\n";
    return;
}

if ($mode === 'run-kk') {
    if (TIX_App_Scope::multi()) { echo "nur neue (Einzel-)Seite\n"; return; }
    $api = (string) $args[1]; $s = json_decode((string) $args[2], true);
    echo "== Neue Seite\n";
    $r = TIX_Site_Move::import($api, 'falsch-' . str_repeat('x', 30), true);
    sm_eq('falscher Schlüssel: Fehler 401', true, is_wp_error($r) && strpos($r->get_error_message(), '401') !== false);
    $r = TIX_Site_Move::import($api, $s['token'], true);
    sm_eq('Trockenlauf: zählt, legt nichts an', [2, 1, 2, 1, 0], [$r['counts']['events'] ?? null, $r['counts']['orders'] ?? null, $r['counts']['tickets'] ?? null, $r['counts']['coupons'] ?? null,
        count(get_posts(['post_type' => 'event', 'post_status' => 'any', 'meta_key' => TIX_Site_Move::META_FROM, 'fields' => 'ids', 'posts_per_page' => -1]))]);
    $probe = function ($new, $old, $post) { if ($post && $post->post_type === 'event' && $new === 'publish') { wp_mail('gast@example.test', 'Test', 'darf nicht raus'); wp_remote_get('https://push.example.test/send'); } };
    add_action('transition_post_status', $probe, 50, 3);
    $r = TIX_Site_Move::import($api, $s['token']);
    remove_action('transition_post_status', $probe, 50);
    sm_eq('Import: ohne Fehler', [], is_wp_error($r) ? [$r->get_error_message()] : $r['errors']);
    sm_eq('Import: gemeldet, Plattform-Status imported', [true, 'imported', 'imported'], [$r['reported'] ?? null, $r['platform_status'] ?? null, get_option(TIX_Site_Move::OPT_IMPORT)['platform_status'] ?? null]);
    sm_eq('Ruhemodus: Mails und fremdes HTTP gesperrt, Hooks danach wieder da', [true, true, 35], [($r['blocked']['mails'] ?? 0) >= 2, ($r['blocked']['http'] ?? 0) >= 2, has_action('save_post_event', ['TIX_Syndication_Push', 'on_save'])]);
    sm_eq('Import: Zahlen', [1, 1, 2, 1, 2, 1, 1, 1], [$r['counts']['organizer'], $r['counts']['locations'], $r['counts']['events'], $r['counts']['orders'], $r['counts']['tickets'], $r['counts']['newsletter'], $r['counts']['coupons'], 0 + ($r['counts']['artists'] === 0)]);
    $map = (array) get_option(TIX_Site_Move::OPT_MAP);
    $e1 = intval($map['events'][$s['events'][0]] ?? 0);
    sm_eq('Event: angelegt, Herkunft, Verteilung auf Plattform-ID', ['SMTEST Party 1', (string) $s['events'][0], '1', (string) $s['events'][0]],
        [get_the_title($e1), get_post_meta($e1, '_tix_moved_from', true), get_post_meta($e1, '_tix_syndicate', true), (string) get_post_meta($e1, '_tix_syndicate_remote_id', true)]);
    $org = intval($map['organizer']);
    sm_eq('Event: Veranstalter + Ort dieser Seite', [$org, intval($map['locations'][array_key_first($map['locations'])])], [intval(get_post_meta($e1, '_tix_organizer_id', true)), intval(get_post_meta($e1, '_tix_location_id', true))]);
    sm_eq('Veranstalter: ohne Bankdaten', '', (string) get_post_meta($org, '_tix_org_iban', true));
    sm_eq('Gästeliste mitgezogen', 'SMG1', (string) (get_post_meta($e1, '_tix_guest_list', true)[0]['id'] ?? ''));
    $o = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TIX_Order::table_name() . ' WHERE order_number = %s', $s['order_number']), ARRAY_A);
    $it = $o ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TIX_Order::items_table_name() . ' WHERE order_id = %d', $o['id']), ARRAY_A) : null;
    sm_eq('Bestellung: gleiche Nummer, Position auf neues Event', [true, $e1, '40.00'], [(bool) $o, intval($it['event_id'] ?? 0), $o['total'] ?? null]);
    $t = TIX_Tickets::get_ticket_by_code($s['codes'][0]);
    $tid = is_object($t) ? intval($t->ID) : 0;
    sm_eq('Ticket: gleicher Code, neues Event + Bestellung', [true, $e1, intval($o['id'] ?? -1)], [$tid > 0, intval(get_post_meta($tid, '_tix_ticket_event_id', true)), intval(get_post_meta($tid, '_tix_ticket_order_id', true))]);
    sm_eq('Gutschein: Event-Bezug umgeschrieben', [$e1], (array) (get_option('tix_coupons')['SMTEST10']['event_ids'] ?? []));
    sm_eq('Einlass: alte Plattform-ID im QR → neues Event', $e1, TIX_Site_Move::local_event_id($s['events'][0]));
    sm_eq('Einlass: Gast mit alter ID gefunden', $e1, TIX_Site_Move::guest_event_id($s['events'][0], 'SMG1'));
    $admin = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user(intval($admin[0]->ID));
    [$st, $d] = sm_rest('POST', '/checkin/scan', ['code' => 'GL-' . $s['events'][0] . '-' . $s['codes'][0]]);
    sm_eq('Scan GL-<alte ID>-<Code>: eingecheckt, kein „Falsches Event“', [200, true], [$st, in_array(($d['status'] ?? $d['result'] ?? ''), ['valid', 'ok', 'checked_in', 'success'], true) || !empty($d['valid']) || !empty($d['ok'])]);
    if ($st !== 200 || (($d['status'] ?? '') === 'wrong_event')) echo '      Antwort: ' . substr(wp_json_encode($d), 0, 300) . "\n";
    $r2 = TIX_Site_Move::import($api, $s['token']);
    sm_eq('Zweiter Lauf: keine Doppel', [2, 1, 2], [count(get_posts(['post_type' => 'event', 'post_status' => 'any', 'meta_key' => TIX_Site_Move::META_FROM, 'fields' => 'ids', 'posts_per_page' => -1])),
        intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TIX_Order::table_name() . ' WHERE order_number = %s', $s['order_number']))),
        count(get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'meta_key' => TIX_Site_Move::META_FROM, 'fields' => 'ids', 'posts_per_page' => -1]))]);
    sm_done(); return;
}

if ($mode === 'run-plat') {
    $s = json_decode((string) $args[1], true);
    $admin = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user(intval($admin[0]->ID));
    echo "== Plattform\n";
    [$st, $d] = sm_rest('GET', "/platform/customers/{$s['key']}/move");
    sm_eq('Akte: Umzug importiert, Zuordnung 2 Events', [200, 'imported', 2], [$st, $d['move']['status'] ?? null, $d['move']['map_count'] ?? null]);
    sm_eq('Vorschau: Co-Event gezählt', 1, $d['preview']['co_events'] ?? null);
    [$st, $d] = sm_rest('POST', "/platform/customers/{$s['key']}/move/switch", ['partner_id' => 'gibtsnicht']);
    sm_eq('Abschluss mit unbekanntem Partner: 400', 400, $st);
    [$st, $d] = sm_rest('POST', "/platform/customers/{$s['key']}/move/switch", ['partner_id' => $s['partner']]);
    sm_eq('Abschluss: 200, 2 Events umgestellt', [200, 2], [$st, $d['switched'] ?? null]);
    $e = $s['events'][0];
    sm_eq('Event ist Spiegel der neuen Seite', ['1', $s['partner'], true], [get_post_meta($e, '_tix_syndicated', true), get_post_meta($e, '_tix_source_partner', true), intval(get_post_meta($e, '_tix_source_id', true)) > 0]);
    sm_eq('Abrechnung zählt das Event weiter', true, TIX_Site_Move::settles_as_own($e));
    sm_eq('Co-Event bleibt beim Ersteller', ['', $s['other']], [(string) get_post_meta($s['co'], '_tix_syndicated', true), intval(get_post_meta($s['co'], '_tix_organizer_id', true))]);
    sm_eq('Partner dem Kunden zugeordnet → Akte nicht mehr Provision', [$s['oid'], true], [intval(TIX_Partners::get($s['partner'])['organizer_id'] ?? 0), ($d['customer']['art'] ?? '') !== 'provision']);
    [$st, $d] = sm_rest('POST', "/platform/customers/{$s['key']}/move/switch", ['partner_id' => $s['partner']]);
    sm_eq('Zweiter Abschluss: 409', 409, $st);
    $r = new WP_REST_Request('GET', '/tixomat/v1/move/export'); $r->set_query_params(['part' => 'manifest']); $r->set_header('X-Tix-Move-Token', 'falsch' . str_repeat('x', 30));
    sm_eq('Export mit falschem Schlüssel: 401', 401, rest_do_request($r)->get_status());
    sm_done(); return;
}

if ($mode === 'push-kk') { // nach dem Abschluss: Änderung auf der neuen Seite aktualisiert das Plattform-Event (kein Doppel)
    $s = json_decode((string) $args[1], true);
    $map = (array) get_option(TIX_Site_Move::OPT_MAP);
    $e1 = intval($map['events'][$s['events'][0]] ?? 0);
    wp_update_post(['ID' => $e1, 'post_title' => 'SMTEST Party 1 (neu)']);
    TIX_Syndication_Push::push_event($e1);
    echo wp_json_encode(['remote' => intval(get_post_meta($e1, '_tix_syndicate_remote_id', true)), 'new' => $e1]) . "\n"; return;
}
if ($mode === 'check-push-plat') {
    $s = json_decode((string) $args[1], true);
    $p = json_decode((string) $args[2], true);
    sm_eq('Push der neuen Seite: gleiches Plattform-Event aktualisiert', [$s['events'][0], 'SMTEST Party 1 (neu)', $p['new']],
        [intval($p['remote'] ?? 0), get_the_title($s['events'][0]), intval(get_post_meta($s['events'][0], '_tix_source_id', true))]);
    sm_eq('kein zweites Event', 1, count(get_posts(['post_type' => 'event', 'post_status' => 'any', 's' => 'SMTEST Party 1', 'fields' => 'ids', 'posts_per_page' => -1])));
    sm_done(); return;
}
if ($mode === 'co-kk') { // Schritt 2 auf der neuen Seite; Phase: an | aus | weg
    $s = json_decode((string) $args[1], true); $phase = (string) ($args[2] ?? 'an');
    $admin = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user(intval($admin[0]->ID));
    echo "== Neue Seite: Co-Events ($phase)\n";
    $r = TIX_Site_Move::sync_co_events();
    $mir = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'trash'], 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_platform_co', 'meta_value' => strval($s['co'])]);
    $m = $mir ? intval($mir[0]) : 0;
    if ($phase === 'weg') {
        sm_eq('Abgleich: entfernt, Spiegel im Papierkorb', [true, 1, 'trash'], [!is_wp_error($r), $r['removed'] ?? null, get_post_status($m)]);
        sm_done(); return;
    }
    if ($phase === 'aus') {
        sm_eq('Zugriff aus: Spiegel bleibt, Durchgriff 403', [true, '0', 403], [!is_wp_error($r), get_post_meta($m, '_tix_platform_co_access', true), sm_rest('GET', "/platform-co/$m/events/{$s['co']}/edit")[0]]);
        sm_done(); return;
    }
    sm_eq('Abgleich: 1 Spiegel angelegt', [true, 1], [!is_wp_error($r), (is_wp_error($r) ? 0 : $r['created'] + $r['updated'])]);
    if (is_wp_error($r)) { echo '      ' . $r->get_error_message() . "\n"; sm_done(); return; }
    // seit 1.38.712: Verkauf über die Plattform (Vermittlung) statt Link zum Ersteller → Tickets an, externer Shop aus
    sm_eq('Spiegel: Titel, Ersteller, Kauf über die Plattform, nie zurückverteilt', ['SMTEST Co-Event', 'SMTEST Ersteller', '0', '1', '0', true],
        [get_the_title($m), get_post_meta($m, '_tix_platform_co_by', true), get_post_meta($m, '_tix_extshop_enabled', true), get_post_meta($m, '_tix_tickets_enabled', true), get_post_meta($m, '_tix_syndicate', true), TIX_Syndication_Push::is_system_event($m)]);
    $lf = TIX_Co_Organizer::list_fields($m);
    sm_eq('Liste: Rolle co, remote, Basis', ['co', true, "/platform-co/$m"], [$lf['event_role'] ?? null, $lf['remote'] ?? null, $lf['base'] ?? null]);
    [$st, $d] = sm_rest('GET', '/platform-co/events');
    sm_eq('/platform-co/events listet den Spiegel', [200, 1], [$st, count(array_filter((array) ($d['items'] ?? []), function ($i) use ($m) { return intval($i['id']) === $m; }))]);
    sm_eq('/platform-co/events: origin_id 0 bei reinem Plattform-Event (1.38.718)', [0, '0'], [(array_column((array) ($d['items'] ?? []), null, 'id')[$m]['origin_id'] ?? null), get_post_meta($m, '_tix_platform_co_origin', true)]);
    [$st, $d] = sm_rest('POST', "/events/$m", ['title' => 'lokal geändert']);
    sm_eq('Lokales Speichern gesperrt: 409', [409, 'tix_co_platform_event'], [$st, $d['code'] ?? null]);
    [$st, $d] = sm_rest('GET', "/platform-co/$m/events/{$s['co']}/edit");
    sm_eq('Durchgriff: Editor-Daten von der Plattform', [200, 'SMTEST Co-Event'], [$st, html_entity_decode((string) ($d['event']['title'] ?? $d['title'] ?? ''), ENT_QUOTES)]);
    [$st] = sm_rest('POST', "/platform-co/$m/events/{$s['co']}", ['title' => 'SMTEST Co-Event (geändert)']);
    sm_eq('Durchgriff: Speichern 200', 200, $st);
    [$st, $d] = sm_rest('POST', "/platform-co/$m/events/{$s['co']}/guestlist", ['name' => 'Neuer Co Gast']);
    sm_eq('Durchgriff: Gast angelegt', true, $st >= 200 && $st < 300);
    [$st, $d] = sm_rest('POST', "/platform-co/$m/orders/999999/refund", []);
    sm_eq('Storno-Route freigegeben (Ersteller = Provisionskunde), fremde Bestellung 403', [403, 'Diese Bestellung gehört nicht zu diesem Event.'], [$st, $d['message'] ?? null]);
    [$st, $d] = sm_rest('POST', "/platform-co/$m/events/{$s['co']}", ['co_organizer' => null]);
    sm_eq('Co-Veranstalter ändern: 403', 403, $st);
    $r = TIX_Site_Move::sync_co_events();
    sm_eq('Nächster Abgleich übernimmt den neuen Titel', ['SMTEST Co-Event (geändert)', 1], [get_the_title($m), $r['updated'] ?? null]);
    sm_done(); return;
}
if ($mode === 'co-plat') { // Phase aus | weg
    $s = json_decode((string) $args[1], true);
    if (($args[2] ?? '') === 'aus') TIX_Co_Organizer::set($s['co'], $s['oid'], false, 0); else TIX_Co_Organizer::clear($s['co']);
    $names = array_column((array) get_post_meta($s['co'], '_tix_guest_list', true), 'name');
    echo (in_array('Neuer Co Gast', $names, true) ? 'ok    Plattform: Gast aus dem Durchgriff gespeichert' : 'FAIL  Plattform: Gast fehlt') . "\n";
    echo "co-plat ok\n"; return;
}
if ($mode === 'cleanup-kk') {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $map = (array) get_option(TIX_Site_Move::OPT_MAP, []);
    foreach (get_posts(['post_type' => 'event', 'post_status' => ['any', 'trash'], 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_platform_co']) as $p) wp_delete_post($p, true);
    delete_option('tix_platform_co_sync');
    foreach (['event', 'tix_ticket', 'tix_location', 'tix_artist', 'tix_organizer'] as $t) foreach (get_posts(['post_type' => $t, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => TIX_Site_Move::META_FROM]) as $p) {
        remove_all_actions('before_delete_post'); wp_delete_post($p, true);
    }
    foreach ((array) ($map['orders'] ?? []) as $o) if ($o > 0) { $wpdb->delete(TIX_Order::items_table_name(), ['order_id' => $o]); $wpdb->delete(TIX_Order::notes_table_name(), ['order_id' => $o]); $wpdb->delete(TIX_Order::table_name(), ['id' => $o]); }
    $wpdb->query("DELETE FROM {$wpdb->prefix}" . TIX_Ticket_DB::TABLE_SUFFIX . " WHERE buyer_email = 'smkauf@example.test' OR ticket_post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
    if (class_exists('TIX_Newsletter')) $wpdb->delete(TIX_Newsletter::tc(), ['email' => 'smnl@example.test']);
    $cp = (array) get_option('tix_coupons', []); unset($cp['SMTEST10']); update_option('tix_coupons', $cp);
    delete_option(TIX_Site_Move::OPT_MAP); delete_option(TIX_Site_Move::OPT_IMPORT);
    echo "cleanup-kk ok\n"; return;
}
if ($mode === 'cleanup-plat') {
    $b = get_option('tix_sm_backup', null);
    if (is_array($b)) {
        update_option(TIX_Partners::OPTION, $b['partners'], false);
        $set = get_option('tix_settings', []); if ($b['recv'] === '__none__') unset($set['syndication_receive_enabled']); else $set['syndication_receive_enabled'] = $b['recv']; update_option('tix_settings', $set);
        foreach ($b['posts'] as $p) wp_delete_post($p, true);
        foreach ($b['oids'] as $o) { delete_option(TIX_Site_Move::OPT . $o); wp_delete_post($o, true); }
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 's' => 'SMTEST']) as $p) wp_delete_post($p, true);
        require_once ABSPATH . 'wp-admin/includes/user.php'; if (!empty($b['owner'])) wp_delete_user($b['owner']);
        $wpdb->delete(TIX_Order::items_table_name(), ['order_id' => $b['order']]); $wpdb->delete(TIX_Order::table_name(), ['id' => $b['order']]);
        if (class_exists('TIX_Newsletter')) $wpdb->delete(TIX_Newsletter::tc(), ['email' => 'smnl@example.test']);
        $cp = (array) get_option('tix_coupons', []); unset($cp['SMTEST10']); update_option('tix_coupons', $cp);
    }
    delete_option('tix_sm_backup');
    echo "cleanup-plat ok\n"; return;
}
echo "Modus: setup-plat | run-kk | run-plat | cleanup-kk | cleanup-plat\n";
