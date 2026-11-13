<?php
/**
 * P5 Expertenmodus (1.38.801): Serien (B4), Event-Vorlagen (B12), Sammeländerung (B13).
 *   wp eval-file …/test-event-tools-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Event_Tools')) { echo "Tixomat ab 1.38.801 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$posts = []; $tpls = []; $users = [];
$all_children = function ($master) { return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => TIX_Recurrence::META_PARENT, 'meta_value' => (string) $master])); };
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $org = 0;
    if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P5 Club']); $posts[] = $org; }
    $cats = [['name' => 'Normal', 'price' => 10, 'qty' => 100, 'stock' => 60], ['name' => 'VIP', 'price' => 30, 'qty' => 20, 'stock' => 20]];
    $mk = function ($t, $days) use (&$posts, $org, $cats) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => $t, 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS), '_tix_date_end' => wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS), '_tix_time_start' => '22:00', '_tix_location' => 'Keller', '_tix_ticket_categories' => $cats, '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1']]); $posts[] = $id; return $id; };

    // ═══ B4 Serien ═══
    $m = $mk('P5 Freitagsparty', 3);
    [$st, $d] = $rest('GET', "/events/$m/series");
    $eq('Einzel-Event: role single', [200, 'single', 'none'], [$st, $d['role'] ?? '', $d['mode'] ?? '']);
    [$st, $d] = $rest('POST', "/events/$m/series", ['mode' => 'weekly', 'count' => 4]);
    $kids = $all_children($m); foreach ($kids as $k) $posts[] = $k;
    $eq('Wöchentlich, 4 Termine inkl. Serie → 3 Termine angelegt, role master', [200, 'master', 3, 3], [$st, $d['role'] ?? '', count($kids), count(array_filter((array) ($d['occurrences'] ?? []), function ($o) { return $o['event_id']; }))]);
    $c1 = $kids[0] ?? 0; $c2 = $kids[1] ?? 0;
    [$st, $d] = $rest('GET', "/events/$c1/series");
    $eq('Termin: role occurrence mit master_id', ['occurrence', $m], [$d['role'] ?? '', $d['master_id'] ?? 0]);
    $ga = $rest('GET', "/events/$m")[1]; $ga = $ga['event'] ?? $ga; $gb = $rest('GET', "/events/$c1")[1]; $gb = $gb['event'] ?? $gb;
    $eq('GET /events: series_role master/occurrence (1.38.805)', ['master', 'occurrence', $m], [$ga['series_role'] ?? '', $gb['series_role'] ?? '', $gb['series_master_id'] ?? 0]);
    [$st, , $c] = $rest('POST', "/events/$c1/series", ['mode' => 'none']);
    $eq('Wiederholung am Termin ändern → 409', [409, 'tix_series_occurrence'], [$st, $c]);
    [$st, , $c] = $rest('POST', "/events/$m/series", ['mode' => 'monthly']);
    $eq('Unbekannter Modus → 400', 400, $st);
    $d2 = (string) get_post_meta($c2, TIX_Recurrence::META_DATE, true);
    [$st, $d] = $rest('POST', "/events/$m/series/skip", ['date' => $d2, 'skip' => true]);
    $o2 = array_values(array_filter((array) ($d['occurrences'] ?? []), function ($o) use ($d2) { return $o['date'] === $d2; }))[0] ?? [];
    $eq('Termin auslassen: im Papierkorb, skipped', [200, 'trash', true], [$st, get_post_status($c2), $o2['skipped'] ?? null]);
    [$st] = $rest('POST', "/events/$m/series/skip", ['date' => $d2, 'skip' => false]);
    clean_post_cache($c2);
    $eq('Wieder aufnehmen: Termin zurück, veröffentlicht wie vorher', [200, 'publish'], [$st, get_post_status($c2)]);
    [$st, $d] = $rest('POST', "/events/$c1/series/detach", ['detached' => true]);
    $eq('Termin einzeln ändern (gelöst)', [200, true], [$st, $d['detached'] ?? null]);
    update_post_meta($m, '_tix_location', 'Dach-Terrasse');
    TIX_Recurrence::sync($m);
    clean_post_cache($c1); clean_post_cache($c2);
    $eq('Änderung an der Serie: gelöster Termin bleibt, andere folgen', ['Keller', 'Dach-Terrasse'], [(string) get_post_meta($c1, '_tix_location', true), (string) get_post_meta($c2, '_tix_location', true)]);
    [$st] = $rest('POST', "/events/$c1/series/detach", ['detached' => false]);
    clean_post_cache($c1);
    $eq('Wieder an die Serie: übernimmt die Serie', 'Dach-Terrasse', (string) get_post_meta($c1, '_tix_location', true));

    // ═══ B12 Vorlagen ═══
    $src = $mk('P5 Vorlage-Quelle', 20);
    [$st, $d] = $rest('POST', '/studio/event-templates', ['event_id' => $src, 'name' => 'Clubnacht Standard']);
    $tid = intval($d['template']['id'] ?? 0); if ($tid) $tpls[] = $tid;
    $eq('Vorlage aus Event → 201, Zusammenfassung', [201, 'Clubnacht Standard', 2, '22:00', 'Keller'], [$st, $d['template']['name'] ?? '', $d['template']['summary']['categories'] ?? 0, $d['template']['summary']['time_start'] ?? '', $d['template']['summary']['location'] ?? '']);
    [$st, $d] = $rest('GET', '/studio/event-templates');
    $eq('Liste enthält die Vorlage', true, in_array($tid, array_column((array) ($d['templates'] ?? []), 'id'), true));
    $nd = wp_date('Y-m-d', time() + 40 * DAY_IN_SECONDS);
    [$st, $d] = $rest('POST', "/studio/event-templates/$tid/create", ['date_start' => $nd, 'title' => 'P5 Neue Clubnacht']);
    $new = intval($d['event_id'] ?? 0); if ($new) $posts[] = $new;
    $nc = (array) get_post_meta($new, '_tix_ticket_categories', true);
    $eq('Event aus Vorlage: Entwurf, Datum, Ort, Kategorien mit vollem Kontingent', [201, 'draft', $nd, 'Keller', 2, 100], [$st, get_post_status($new), get_post_meta($new, '_tix_date_start', true), get_post_meta($new, '_tix_location', true), count($nc), intval($nc[0]['stock'] ?? 0)]);
    if ($multi) $eq('evendis: Veranstalter übernommen', $org, intval(get_post_meta($new, '_tix_organizer_id', true)));
    [$st, , $c] = $rest('POST', "/studio/event-templates/$tid/create", ['date_start' => '13.11.']);
    $eq('Ungültiges Datum → 400', 400, $st);
    [$st, $d] = $rest('POST', "/studio/event-templates/$tid", ['name' => 'Clubnacht']);
    $eq('Umbenennen', 'Clubnacht', $d['template']['name'] ?? '');

    // ═══ B13 Sammeländerung ═══
    $a = $mk('P5 Bulk A', 10); $b = $mk('P5 Bulk B', 11);
    [$st, $d] = $rest('POST', '/studio/events/bulk', ['event_ids' => [$a, $b], 'changes' => ['price' => ['mode' => 'percent', 'value' => 10, 'categories' => ['Normal']]], 'dry_run' => true]);
    $eq('Probelauf: Änderungen gezeigt, nichts gespeichert', [200, true, 11.0, 10], [$st, $d['dry_run'] ?? null, $d['results'][0]['changed'][0]['to'] ?? null, intval(get_post_meta($a, '_tix_ticket_categories', true)[0]['price'])]);
    [$st, $d] = $rest('POST', '/studio/events/bulk', ['event_ids' => [$a, $b], 'changes' => ['price' => ['mode' => 'percent', 'value' => 10, 'categories' => ['Normal']], 'presale_active' => false, 'presale_end' => '2027-01-31', 'status' => 'draft']]);
    clean_post_cache($a);
    $ca = (array) get_post_meta($a, '_tix_ticket_categories', true);
    $eq('Sammeländerung: Preis nur „Normal“, Vorverkauf aus, Ende, Entwurf', [200, 2, 11.0, 30.0, '0', '2027-01-31T23:59', 'draft'],
        [$st, $d['summary']['changed'] ?? 0, floatval($ca[0]['price']), floatval($ca[1]['price']), get_post_meta($a, '_tix_presale_active', true), get_post_meta($a, '_tix_presale_end_computed', true), get_post_status($a)]);
    [$st, , $c] = $rest('POST', '/studio/events/bulk', ['event_ids' => [$a], 'changes' => ['price' => ['mode' => 'percent', 'value' => 900]]]);
    $eq('Unsinniger Preis → 400', [400, 'changes.price.value'], [$st, $rest('POST', '/studio/events/bulk', ['event_ids' => [$a], 'changes' => ['price' => ['mode' => 'percent', 'value' => 900]]])[1]['data']['field'] ?? null]);
    if (class_exists('TIX_Edit_Lock')) {
        $u2 = wp_insert_user(['user_login' => 'p5b' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'p5b' . wp_rand(1000, 9999) . '@example.test', 'role' => 'administrator', 'display_name' => 'Bea']); $users[] = $u2;
        wp_set_current_user($u2); $rest('POST', "/events/$b/lock", []); wp_set_current_user($adm);
        [$st, $d] = $rest('POST', '/studio/events/bulk', ['event_ids' => [$a, $b], 'changes' => ['tickets_enabled' => false]]);
        $eq('Gesperrtes Event wird übersprungen, andere geändert', [1, 'tix_event_locked', '0'], [$d['summary']['ok'] ?? 0, $d['results'][1]['code'] ?? '', get_post_meta($a, '_tix_tickets_enabled', true)]);
        $wpdb->delete(TIX_Edit_Lock::table(), ['event_id' => $b]);
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($tpls as $t) $wpdb->delete(TIX_Event_Tools::table(), ['id' => $t]);
    foreach (array_reverse(array_unique($posts)) as $p) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
