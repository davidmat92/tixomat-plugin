<?php
/** Aus Angebot anlegen (TIX_Offer_Hosting, §99). wp eval-file …/tests/test-offer-hosting-wp.php (Plattform). */
if (!defined('ABSPATH') || !class_exists('TIX_Offer_Hosting')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function oh_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function oh_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
oh_eq('Kurzname: Umlaute, Rechtsform', 'muellerevents', TIX_Offer_Hosting::suggest_kurz('Müller Events GmbH'));
oh_eq('Kurzname: max. 16, Ziffern vorn weg', 'xxlschlagerparty', TIX_Offer_Hosting::suggest_kurz('2 XXL Schlagerparty Mallorca'));
oh_eq('Kurzname: reserviert → Ersatz', 1, preg_match('/^kunde[a-f0-9]{4}$/', TIX_Offer_Hosting::suggest_kurz('Neu')));
if (!TIX_App_Scope::multi()) {
    [$st] = oh_rest('GET', '/platform/offers/1/hosting');
    oh_eq('Einzel-Seite: keine Route', 404, $st);
    echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n"; return;
}
$sfx = wp_rand(1000, 9999); $users = [];
$adm = wp_insert_user(['user_login' => "oh_adm_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "oh_adm_$sfx@example.test", 'role' => 'administrator']); $users[] = $adm;
$org = wp_insert_user(['user_login' => "oh_org_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "oh_org_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $org;
wp_set_current_user($adm);
[$st, $o] = oh_rest('POST', '/platform/offers', ['customer' => ['company' => 'Sommerbühne Bonn GmbH', 'name' => 'Ina Test', 'email' => 'ina@example.test', 'phone' => '0228 1'], 'items' => ['shop', 'app', ['catalog_key' => 'app_modul', 'qty' => 2], 'domain'], 'term_months' => 24]);
oh_eq('Angebot angelegt', 201, $st);
$id = $o['offer']['id'] ?? 0;
[$st, $p] = oh_rest('GET', "/platform/offers/$id/hosting");
oh_eq('Vorbelegung: Name, Kurzname, Adresse mit eigener Domain', [200, 'Sommerbühne Bonn GmbH', 'sommerbuehnebonn', 'domain', 'sommerbuehnebonn.evendis.de'], [$st, $p['name'] ?? '', $p['kurz'] ?? '', $p['address']['mode'] ?? '', $p['address']['transit'] ?? '']);
oh_eq('Module: website + app + Awareness (immer kostenlos), 2 zum Auswählen', [['website', 'app', 'awareness'], 2], [$p['modules'], $p['modules_pick']]);
oh_eq('Lizenz: Mail, Laufzeit aus term_months, Abrechnung jährlich', ['ina@example.test', gmdate('Y-m-d', strtotime('+24 months')), 'yearly'], [$p['license']['email'], $p['license']['valid_until'], $p['license']['billing']]);
$codes = array_column($p['warnings'], 'code');
oh_eq('Warnungen: nicht bestätigt + Module wählen, nicht „kein Shop“', [true, true, false], [in_array('tix_offer_not_accepted', $codes, true), in_array('tix_offer_pick_modules', $codes, true), in_array('tix_offer_not_selfhosted', $codes, true)]);
[$st, $o4] = oh_rest('POST', '/platform/offers', ['customer' => ['company' => 'Modulname GmbH', 'email' => 'm@example.test'], 'items' => ['shop_monatlich', 'app', ['catalog_key' => 'app_modul', 'qty' => 2, 'note' => 'Prämien und Musikwunsch']]]);
[$st, $p4] = oh_rest('GET', '/platform/offers/' . ($o4['offer']['id'] ?? 0) . '/hosting');
oh_eq('Module mit Namen in der Notiz, monatlich ohne term_months → 1 Monat', [['website', 'app', 'loyalty', 'music', 'awareness'], 0, 'monthly', gmdate('Y-m-d', strtotime('+1 months'))],
    [$p4['modules'] ?? null, $p4['modules_pick'] ?? -1, $p4['license']['billing'] ?? '', $p4['license']['valid_until'] ?? '']);
oh_eq('… keine Warnung „Module wählen“', false, in_array('tix_offer_pick_modules', array_column($p4['warnings'] ?? [], 'code'), true));
[$st, $o2] = oh_rest('POST', '/platform/offers', ['customer' => ['company' => 'Nur Tickets', 'email' => 'x@example.test'], 'items' => ['tickets']]);
[$st, $p2] = oh_rest('GET', '/platform/offers/' . ($o2['offer']['id'] ?? 0) . '/hosting');
oh_eq('Nur Tickets: Warnung nicht selbstgehostet, Übergangsadresse', [true, 'transit'], [in_array('tix_offer_not_selfhosted', array_column($p2['warnings'], 'code'), true), $p2['address']['mode']]);
[$st, $o3] = oh_rest('POST', '/platform/offers', ['customer' => ['company' => 'Alle', 'email' => 'a@example.test'], 'items' => ['lizenz', 'app', 'app_alle_module']]);
[$st, $p3] = oh_rest('GET', '/platform/offers/' . ($o3['offer']['id'] ?? 0) . '/hosting');
oh_eq('Alle App-Module → modules null', [null, null], [$p3['modules'], $p3['license']['modules']]);
[$st, , $code] = oh_rest('POST', "/platform/offers/$id/hosting", ['kurz' => 'Neu']);
oh_eq('Verknüpfen: ungültiger Kurzname → 400', [400, 'tix_invalid_field'], [$st, $code]);
[$st, $l] = oh_rest('POST', "/platform/offers/$id/hosting", ['kurz' => 'sommerbuehne', 'job_id' => '0123456789abcdef']);
[$st, , $code] = oh_rest('POST', '/platform/offers/' . ($o4['offer']['id'] ?? 0) . '/hosting', ['kurz' => 'sommerbuehne']);
oh_eq('Kurzname schon an anderem Angebot → 409', [409, 'tix_kurz_taken'], [$st, $code]);
[$st, $o5] = oh_rest('POST', '/platform/offers', ['customer' => ['company' => 'Sommerbuehne', 'email' => 's@example.test'], 'items' => ['shop']]);
[$st, $p5] = oh_rest('GET', '/platform/offers/' . ($o5['offer']['id'] ?? 0) . '/hosting');
oh_eq('Vorschlag weicht aus, wenn der Kurzname vergeben ist', ['sommerbuehne2', true], [$p5['kurz'] ?? '', in_array('tix_offer_kurz_taken', array_column($p5['warnings'] ?? [], 'code'), true)]);
oh_eq('Verknüpfen', [200, 'sommerbuehne', '0123456789abcdef'], [$st, $l['hosting']['kurz'] ?? '', $l['hosting']['job_id'] ?? '']);
[$st, $p] = oh_rest('GET', "/platform/offers/$id/hosting");
oh_eq('… danach Warnung „schon angelegt“ und hosting gesetzt', [true, 'sommerbuehne'], [in_array('tix_offer_already_hosted', array_column($p['warnings'], 'code'), true), $p['hosting']['kurz'] ?? '']);
oh_rest('PATCH', "/platform/offers/$id", ['internal_note' => 'geändert']);
[$st, $p] = oh_rest('GET', "/platform/offers/$id/hosting");
oh_eq('Ändern des Entwurfs behält die Verknüpfung', 'sommerbuehne', $p['hosting']['kurz'] ?? '');
[$st, $dup] = oh_rest('POST', "/platform/offers/$id/duplicate");
[$st, $pd] = oh_rest('GET', '/platform/offers/' . ($dup['offer']['id'] ?? 0) . '/hosting');
oh_eq('Duplikat ohne Verknüpfung', null, $pd['hosting'] ?? null);
wp_set_current_user($org);
[$st] = oh_rest('GET', "/platform/offers/$id/hosting");
oh_eq('Veranstalter → 403', 403, $st);
wp_set_current_user($adm);
global $wpdb;
foreach ([$id, $o2['offer']['id'] ?? 0, $o3['offer']['id'] ?? 0, $o4['offer']['id'] ?? 0, $o5['offer']['id'] ?? 0, $dup['offer']['id'] ?? 0] as $x) if ($x) $wpdb->delete(TIX_Platform_Offers::table(), ['id' => $x]);
require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($users as $u) wp_delete_user($u); wp_set_current_user(0);
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
