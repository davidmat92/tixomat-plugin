<?php
/**
 * W6c (1.38.889): Sponsoren-Zugänge (P9), Domain-Einstellungen + Website-Paket (P11), Awareness-Test (P12).
 *   wp eval-file …/test-w6c-platform-ops-wp.php   (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Platform_Ops')) { echo "Tixomat ab 1.38.889 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $req = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($q as $k => $v) $req->set_param($k, $v); if ($b !== null) { $req->set_header('content-type', 'application/json'); $req->set_body(wp_json_encode($b)); } $res = rest_do_request($req); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$demo = class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on();
$adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
$mails = [];
$cap = function ($atts) use (&$mails) { $mails[] = (string) (is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to']); return $atts; };
add_filter('wp_mail', $cap, 1); add_filter('pre_wp_mail', '__return_true', 99);
$keep = ['tix_org_domain_settings' => get_option('tix_org_domain_settings', null), 'tix_website_enabled' => get_option('tix_website_enabled', null)];
$sfx = substr(md5(microtime()), 0, 6);
$sp = 0; $ev = 0; $org = 0; $sub = 0; $tester = 0;
try {
    // ═══ P9 Sponsoren ═══
    [$s, $d] = $rest('POST', '/studio/sponsor-accounts', ['name' => 'Brauerei ' . $sfx, 'email' => "sponsor_$sfx@example.test", 'contact_name' => 'Frau Hopfen']);
    $sp = intval($d['sponsor']['id'] ?? 0);
    $eq('Sponsor angelegt, aktiv, ohne Passwort', [200, true, 'active', false], [$s, $sp > 0, $d['sponsor']['status'] ?? null, $d['sponsor']['has_password'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/studio/sponsor-accounts', ['name' => 'Doppelt', 'email' => "sponsor_$sfx@example.test"]);
    $eq('gleiche E-Mail → 409 email', [409, 'email'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', '/studio/sponsor-accounts', ['name' => 'X', 'email' => 'x@example.test', 'coupon_code' => 'GIBTSNICHT' . $sfx]);
    $eq('unbekannter Gutschein → 400 coupon_code', [400, 'coupon_code'], [$s, $fld]);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'W6c Event']);
    update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Normal', 'price' => 20, 'stock' => 50, 'online' => '1']]);
    [$s, $d] = $rest('POST', "/studio/sponsor-accounts/$sp/pools", ['event_id' => $ev, 'new_category' => ['name' => 'Sponsor-Freikarte', 'qty' => 20], 'total' => 20]);
    $cats = get_post_meta($ev, '_tix_ticket_categories', true);
    $eq('Kontingent mit neuer versteckter Kategorie', [200, 1, 20, 'Sponsor-Freikarte', '0', 1], [$s, count($d['pools'] ?? []), $d['pools'][0]['total'] ?? null, $cats[1]['name'] ?? null, (string) ($cats[1]['online'] ?? ''), intval($cats[1]['admin_only'] ?? 0)]);
    [$s, , , $fld] = $rest('POST', "/studio/sponsor-accounts/$sp/pools", ['event_id' => $ev, 'cat_index' => 7, 'total' => 5]);
    $eq('Ticket-Art fehlt → 400 cat_index', [400, 'cat_index'], [$s, $fld]);
    [, $d] = $rest('GET', '/studio/sponsor-accounts');
    $row = array_values(array_filter($d['sponsors'] ?? [], function ($x) use ($sp) { return $x['id'] === $sp; }))[0] ?? [];
    $eq('Liste: Kontingent-Summe', [20, 0, 20], [$row['total'] ?? null, $row['used'] ?? null, $row['available'] ?? null]);
    $mails = [];
    [$s, $d] = $rest('POST', "/studio/sponsor-accounts/$sp/send-login");
    $eq('Login-Link per Mail (Demo: verworfen)', [200, true], [$s, $demo || in_array("sponsor_$sfx@example.test", $mails, true)]);
    $pool = intval(TIX_Sponsor_DB::get_pools_by_sponsor($sp)[0]->id ?? 0);
    TIX_Sponsor_DB::increment_used($pool, 1);
    [$s, , $c] = $rest('DELETE', "/studio/sponsor-accounts/$sp/pools/$pool");
    $eq('genutztes Kontingent nicht löschbar → 409', [409, 'tix_pool_used'], [$s, $c]);
    [$s, $d] = $rest('DELETE', "/studio/sponsor-accounts/$sp");
    $eq('Sponsor deaktiviert', [200, 'inactive'], [$s, $d['sponsor']['status'] ?? null]);
    [$s, , $c] = $rest('POST', "/studio/sponsor-accounts/$sp/send-login");
    $eq('deaktiviert: kein Login-Link → 409', [409, 'tix_inactive'], [$s, $c]);

    // ═══ P11 ═══
    if ($multi) {
        [$s, $d] = $rest('POST', '/platform/domain-settings', ['cname_target' => 'domains.example.test', 'a_records' => ['203.0.113.10'], 'ask_secret' => str_repeat('s', 24)]);
        $eq('Domain-Einstellungen: gespeichert, Geheimnis nur als gesetzt', [200, 'domains.example.test', ['203.0.113.10'], true, false], [$s, $d['cname_target'] ?? null, $d['a_records'] ?? null, $d['ask_secret_set'] ?? null, strpos(wp_json_encode($d), str_repeat('s', 24)) !== false]);
        [$s, , , $fld] = $rest('POST', '/platform/domain-settings', ['a_records' => ['2001:db8::1']]);
        $eq('IPv6 als A-Eintrag → 400 a_records.0', [400, 'a_records.0'], [$s, $fld]);
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'W6c Club']);
        [$s, $d] = $rest('POST', "/platform/organizers/$org/domain-access", ['allowed' => true, 'fee_note' => '9 € / Monat']);
        $eq('eigene Domain für Veranstalter freigeschaltet', [200, true, '9 € / Monat'], [$s, $d['allowed'] ?? null, $d['fee_note'] ?? null]);
        [$s, $d] = $rest('GET', "/platform/organizers/$org");
        $eq('Veranstalter-Detail mit domain_access (1.38.892)', [200, true, '9 € / Monat', null], [$s, $d['organizer']['domain_access']['allowed'] ?? null, $d['organizer']['domain_access']['fee_note'] ?? null, array_key_exists('domain', (array) ($d['organizer']['domain_access'] ?? [])) ? $d['organizer']['domain_access']['domain'] : 'x']);
        [$s] = $rest('GET', '/studio/website-package');
        $eq('Plattform: kein Website-Paket → 404', 404, $s);
    } else {
        [$s] = $rest('GET', '/platform/domain-settings');
        $eq('Einzel-Seite: Domain-Einstellungen 404', 404, $s);
        [$s, $d] = $rest('POST', '/studio/website-package', ['enabled' => true]);
        $eq('Website-Paket an', [200, true], [$s, $d['enabled'] ?? null]);
        [$s, $d] = $rest('POST', '/studio/website-package', ['enabled' => false]);
        $eq('Website-Paket aus', [200, false], [$s, $d['enabled'] ?? null]);
    }

    // ═══ P12 Awareness-Test ═══
    $tester = wp_insert_user(['user_login' => 'awt_' . $sfx, 'user_pass' => wp_generate_password(), 'user_email' => "awt_$sfx@example.test", 'role' => 'subscriber']);
    [$s, $d] = $rest('POST', '/awareness/test', ['action' => 'tester', 'user' => "awt_$sfx"]);
    $eq('Tester eingetragen', [200, true], [$s, in_array($tester, array_column($d['testers'] ?? [], 'id'), true)]);
    [$s, $d] = $rest('POST', '/awareness/test', ['action' => 'start', 'minutes' => 30]);
    $started = intval($d['started'] ?? 0);
    $eq('Test-Event läuft (privat, unsichtbar)', [200, true, 'private'], [$s, in_array($started, array_column($d['events'] ?? [], 'id'), true), get_post_status($started)]);
    [$s, , , $fld] = $rest('POST', '/awareness/test', ['action' => 'start', 'minutes' => 5]);
    $eq('5 Minuten → 400 minutes', [400, 'minutes'], [$s, $fld]);
    [$s, $d] = $rest('POST', '/awareness/test', ['action' => 'cleanup']);
    $eq('aufgeräumt: kein Test-Event, keine Tester', [200, [], false], [$s, $d['events'] ?? null, in_array($tester, array_column($d['testers'] ?? [], 'id'), true)]);

    // ═══ Rechte ═══
    $sub = wp_insert_user(['user_login' => 'w6c_' . $sfx, 'user_pass' => wp_generate_password(), 'user_email' => "w6c_$sfx@example.test", 'role' => 'subscriber']);
    wp_set_current_user($sub);
    [$s1] = $rest('GET', '/studio/sponsor-accounts'); [$s2] = $rest('POST', '/awareness/test', ['action' => 'cleanup']);
    $eq('Kunde → 403', [403, 403], [$s1, $s2]);
    wp_set_current_user($adm->ID);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('wp_mail', $cap, 1); remove_filter('pre_wp_mail', '__return_true', 99);
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    if ($sp) { $wpdb->delete(TIX_Sponsor_DB::table_pools(), ['sponsor_id' => $sp]); $wpdb->delete(TIX_Sponsor_DB::table_sponsors(), ['id' => $sp]); }
    if ($ev) wp_delete_post($ev, true);
    if ($org) wp_delete_post($org, true);
    foreach ([$sub, $tester] as $u) if ($u) wp_delete_user($u);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
