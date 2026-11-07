<?php
/** Kunden an einem Ort (TIX_Platform_Customers, §100). wp eval-file …/tests/test-platform-customers-wp.php (Plattform, Einzel-Seite). */
if (!defined('ABSPATH') || !class_exists('TIX_Platform_Customers')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function pc_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function pc_rest($m, $r, array $b = []) { TIX_Platform_Customers::flush(); $q = new WP_REST_Request($m, '/tixomat/v1' . (strpos($r, '?') ? strstr($r, '?', true) : $r)); if (strpos($r, '?')) { parse_str(substr(strstr($r, '?'), 1), $qs); $q->set_query_params($qs); } if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
function pc_find($list, $key) { foreach ((array) $list as $c) if (($c['key'] ?? '') === $key) return $c; return null; }

if (!TIX_App_Scope::multi()) {
    $adm = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    wp_set_current_user($adm ? intval($adm[0]) : 0);
    [$st] = pc_rest('GET', '/platform/customers');
    pc_eq('Einzel-Seite: keine Route', 404, $st);
    wp_set_current_user(0);
    echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n"; return;
}

global $wpdb;
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $lics = []; $offers = []; $setts = [];
$adm = wp_insert_user(['user_login' => "pc_adm_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "pc_adm_$sfx@example.test", 'role' => 'administrator']); $users[] = $adm;
$own = wp_insert_user(['user_login' => "pc_own_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "pc_own_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $own;
wp_set_current_user($adm);

// Lizenz-Server kurz an (Testkopie)
$ls_old = get_option('tix_license_server', '0');
update_option('tix_license_server', '1');
TIX_License_Server::maybe_install();

// Veranstalter: A Provision (wartet), B eigene Seite mit Lizenz, C eigene Seite ohne Lizenz
$mk = function ($title) use (&$posts) { $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $title]); $posts[] = $id; return $id; };
$A = $mk("PC Lachwerk $sfx"); update_post_meta($A, '_tix_org_user_id', $own);
if (class_exists('TIX_Org_Approval')) update_post_meta($A, TIX_Org_Approval::META_STATUS, 'pending');
$B = $mk("PC Bestand $sfx");
$C = $mk("PC Partner ohne Lizenz $sfx");

// Partner-Verzeichnis
$p_old = get_option(TIX_Partners::OPTION, []);
$pp = is_array($p_old) ? $p_old : [];
$pp["pcb$sfx"] = ['id' => "pcb$sfx", 'name' => 'PC Bestand', 'api_base' => "https://www.pcbestand$sfx.de/wp-json/tixomat/v1", 'organizer_id' => $B, 'sales_enabled' => 1];
$pp["pcc$sfx"] = ['id' => "pcc$sfx", 'name' => 'PC Partner C', 'api_base' => "https://pcc$sfx.de/wp-json/tixomat/v1", 'organizer_id' => $C];
update_option(TIX_Partners::OPTION, $pp);

// Lizenzen: L1 Bestand (Host über Lizenz-Seite), L2 gehostet (Kurzname + Host in der Notiz, läuft in 10 Tagen ab), L3 nur Lizenz
$L1 = TIX_License_Server::create(['customer' => 'PC Bestand GmbH', 'email' => "best$sfx@example.test", 'valid_until' => gmdate('Y-m-d', strtotime('+200 days'))]); $lics[] = $L1->id;
$wpdb->insert($wpdb->prefix . 'tix_license_sites', ['license_id' => $L1->id, 'host' => "pcbestand$sfx.de", 'dev' => 0, 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql')]);
$kurz = 'pck' . $sfx;
$L2 = TIX_License_Server::create(['customer' => 'PC Gehostet', 'email' => "host$sfx@example.test", 'valid_until' => gmdate('Y-m-d', strtotime('+10 days')), 'note' => "[kunden-hetzner:$kurz] Gehostet auf kunden-hetzner, Host $kurz.evendis.de (Betriebsart managed).", 'modules' => 'website,app']); $lics[] = $L2->id;
$L3 = TIX_License_Server::create(['customer' => 'PC Nur Lizenz', 'email' => "nur$sfx@example.test", 'valid_until' => gmdate('Y-m-d', strtotime('+300 days'))]); $lics[] = $L3->id;

[$st, $r] = pc_rest('GET', '/platform/customers');
pc_eq('Liste 200', 200, $st);
$cA = pc_find($r['customers'], "v-$A"); $cB = pc_find($r['customers'], "l-{$L1->id}"); $cC = pc_find($r['customers'], "v-$C");
$cH = pc_find($r['customers'], "h-$kurz"); $cL = pc_find($r['customers'], "l-{$L3->id}");
pc_eq('Arten', ['provision', 'bestand', 'bestand', 'hosted', 'license'], [$cA['art'] ?? '', $cB['art'] ?? '', $cC['art'] ?? '', $cH['art'] ?? '', $cL['art'] ?? '']);
pc_eq('Bestand mit Lizenz: Veranstalter über Host verbunden, Partner, Domain', [$B, intval($L1->id), "pcb$sfx", "pcbestand$sfx.de"], [$cB['organizer_id'] ?? 0, $cB['license_id'] ?? 0, $cB['partner_id'] ?? '', $cB['domain'] ?? '']);
pc_eq('Veranstalter B nicht doppelt als eigener Kunde', null, pc_find($r['customers'], "v-$B"));
pc_eq('Gehostet: Kurzname, Lizenz, Domain aus der Notiz, Status Lizenz', [$kurz, intval($L2->id), "$kurz.evendis.de", 'active', 'license'], [$cH['hosting_kurz'] ?? '', $cH['license_id'] ?? 0, $cH['domain'] ?? '', $cH['status'] ?? '', $cH['status_source'] ?? '']);
pc_eq('Provision: Status aus der Freigabe, Name, Events', ['pending', 'approval', "PC Lachwerk $sfx", 0], [$cA['status'] ?? '', $cA['status_source'] ?? '', $cA['name'] ?? '', $cA['events']['all'] ?? -1]);
pc_eq('Aufmerksamkeit: Freigabe offen / Lizenz läuft ab / nichts', [['approval_pending'], ['license_expiring'], []], [array_column($cA['attention'] ?? [], 'code'), array_column($cH['attention'] ?? [], 'code'), array_column($cL['attention'] ?? [], 'code')]);
pc_eq('Aliase der Bestands-Akte', ["l-{$L1->id}", "p-pcb$sfx", "v-$B"], $cB['aliases'] ?? []);
pc_eq('Zähler vorhanden', true, ($r['counts']['all'] ?? 0) >= 5 && ($r['counts']['hosted'] ?? 0) >= 1 && ($r['counts']['attention'] ?? 0) >= 2);

[$st, $r] = pc_rest('GET', '/platform/customers?art=hosted');
pc_eq('Filter art=hosted', true, $st === 200 && pc_find($r['customers'], "h-$kurz") !== null && !pc_find($r['customers'], "v-$A"));
[$st, $r] = pc_rest('GET', "/platform/customers?q=$kurz");
pc_eq('Suche nach Kurzname', ["h-$kurz"], array_column($r['customers'], 'key'));
[$st, $r] = pc_rest('GET', "/platform/customers?q=pc_own_$sfx");
pc_eq('Suche nach Inhaber-Mail', ["v-$A"], array_column($r['customers'], 'key'));
[$st, $r] = pc_rest('GET', '/platform/customers?attention=1&q=PC');
pc_eq('Filter attention', true, pc_find($r['customers'], "v-$A") !== null && pc_find($r['customers'], "l-{$L3->id}") === null);
[$st, , $code] = pc_rest('GET', '/platform/customers?art=foo');
pc_eq('art unbekannt → 400', [400, 'tix_invalid_field'], [$st, $code]);

// Akte
[$st, $d] = pc_rest('GET', "/platform/customers/v-$B");
pc_eq('Akte über Alias v-B → fester Schlüssel l-L1', [200, "l-{$L1->id}", "v-$B"], [$st, $d['customer']['key'] ?? '', $d['customer']['requested_key'] ?? '']);
pc_eq('Akte: Veranstalter, Lizenz, Partner', [$B, intval($L1->id), "pcbestand$sfx.de"], [$d['customer']['organizer']['id'] ?? 0, $d['customer']['license']['id'] ?? 0, $d['customer']['partner']['host'] ?? '']);
pc_eq('Akte: Module beider Ebenen (Lizenz = alle)', [true, true, null], [is_array($d['customer']['modules']['organizer'] ?? null), $d['customer']['modules']['license']['all'] ?? null, array_key_exists('keys', (array) ($d['customer']['modules']['license'] ?? [])) ? $d['customer']['modules']['license']['keys'] : 'x']);
[$st, $d] = pc_rest('GET', "/platform/customers/h-$kurz");
pc_eq('Akte gehostet: Lizenz-Module, kein Veranstalter', [['website', 'app'], null], [$d['customer']['modules']['license']['keys'] ?? null, $d['customer']['organizer']]);
[$st, $d] = pc_rest('GET', "/platform/customers/l-{$L2->id}");
pc_eq('Alias l-L2 → h-kurz', "h-$kurz", $d['customer']['key'] ?? '');
[$st, , $code] = pc_rest('GET', '/platform/customers/v-999999999');
pc_eq('Unbekannt → 404', [404, 'tix_not_found'], [$st, $code]);

// Support ?customer=
$t1 = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'tix_open', 'post_title' => "PC Hilfe A $sfx"]); $posts[] = $t1;
update_post_meta($t1, '_tix_sp_help', '1'); update_post_meta($t1, '_tix_sp_help_org', $A); update_post_meta($t1, '_tix_sp_help_unread', 1);
$t2 = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'tix_open', 'post_title' => "PC Hilfe Seite $sfx"]); $posts[] = $t2;
update_post_meta($t2, '_tix_sp_help', '1'); update_post_meta($t2, '_tix_sp_help_site', "https://pcbestand$sfx.de");
[$st, $r] = pc_rest('GET', "/platform/support?customer=v-$A");
pc_eq('Support ?customer=v-A', [200, [$t1], "v-$A"], [$st, array_column($r['tickets'] ?? [], 'id'), $r['customer']['key'] ?? '']);
[$st, $r] = pc_rest('GET', "/platform/support?customer=l-{$L1->id}");
pc_eq('Support ?customer=Bestand über Host der eigenen Seite', [$t2], array_column($r['tickets'] ?? [], 'id'));
[$st, , $code] = pc_rest('GET', '/platform/support?customer=v-999999999');
pc_eq('Support: unbekannter Kunde → 404', 404, $st);
[$st, $r] = pc_rest('GET', '/platform/customers?q=' . rawurlencode("PC Lachwerk $sfx"));
pc_eq('Aufmerksamkeit: ungelesene Betreiber-Anfrage', ['approval_pending', 'support_unread'], array_column($r['customers'][0]['attention'] ?? [], 'code'));

// Abrechnungen ?customer=
$st_t = $wpdb->prefix . 'tix_settlements';
if ($wpdb->get_var("SHOW TABLES LIKE '$st_t'") === $st_t) {
    foreach ([[$A, 'held', 50], [$B, 'paid', 70]] as [$o, $s, $amt]) {
        $wpdb->insert($st_t, ['organizer_id' => $o, 'event_id' => 0, 'status' => $s, 'payout_amount' => $amt, 'gross' => $amt, 'created' => current_time('mysql'), 'number' => "PC-$sfx-$o"]);
        $setts[] = $wpdb->insert_id;
    }
    [$st, $r] = pc_rest('GET', "/platform/settlements?customer=v-$A");
    pc_eq('Abrechnungen ?customer=v-A: nur A, Summen nur A', [200, [$A], 50.0, 0.0], [$st, array_values(array_unique(array_column(array_column($r['settlements'] ?? [], 'organizer'), 'id'))), $r['totals']['open'] ?? -1, $r['totals']['paid'] ?? -1]);
    [$st, $r] = pc_rest('GET', "/platform/customers?q=" . rawurlencode("PC Lachwerk $sfx"));
    pc_eq('Aufmerksamkeit: Abrechnung zurückgestellt', true, in_array('settlement_held', array_column($r['customers'][0]['attention'] ?? [], 'code'), true));
} else {
    echo "-     Abrechnungen: Tabelle fehlt, übersprungen\n";
}

// Angebote ?customer=
[$st, $o1] = pc_rest('POST', '/platform/offers', ['customer' => ['company' => 'PC Nur Lizenz', 'email' => "NUR$sfx@example.test"], 'items' => ['lizenz']]);
$offers[] = $o1['offer']['id'] ?? 0;
[$st, $o2] = pc_rest('POST', '/platform/offers', ['customer' => ['company' => 'Andere', 'email' => "andere$sfx@example.test"], 'items' => ['tickets']]);
$offers[] = $o2['offer']['id'] ?? 0;
[$st, $o3] = pc_rest('POST', '/platform/offers', ['customer' => ['company' => 'Aus Akte', 'email' => "akte$sfx@example.test"], 'items' => ['tickets'], 'customer_key' => "v-$B"]);
$offers[] = $o3['offer']['id'] ?? 0;
pc_eq('Angebot aus der Akte: Alias wird zum festen Schlüssel', [201, "l-{$L1->id}"], [$st, $o3['offer']['customer_key'] ?? ($o3['offer']['summary']['customer_key'] ?? null)]);
[$st, , $code] = pc_rest('POST', '/platform/offers', ['customer' => ['company' => 'X'], 'items' => ['tickets'], 'customer_key' => 'v-999999999']);
pc_eq('Angebot mit unbekanntem Kunden → 400', [400, 'tix_invalid_field'], [$st, $code]);
[$st, $r] = pc_rest('GET', "/platform/offers?customer=l-{$L3->id}");
pc_eq('Angebote ?customer=: über E-Mail (Groß/klein egal)', [200, [$o1['offer']['id']], "l-{$L3->id}"], [$st, array_column($r['offers'] ?? [], 'id'), $r['customer']['key'] ?? '']);
[$st, $r] = pc_rest('GET', "/platform/offers?customer=l-{$L1->id}");
pc_eq('Angebote ?customer=Bestand: fest verknüpft', [$o3['offer']['id']], array_column($r['offers'] ?? [], 'id'));
[$st, $l] = pc_rest('POST', "/platform/customers/v-$A/offers", ['offer_id' => $o2['offer']['id']]);
pc_eq('Verknüpfen', [200, "v-$A"], [$st, $l['customer_key'] ?? '']);
[$st, $r] = pc_rest('GET', "/platform/offers?customer=v-$A");
pc_eq('… danach in der Liste mit customer_key', [[$o2['offer']['id']], "v-$A"], [array_column($r['offers'] ?? [], 'id'), $r['offers'][0]['customer_key'] ?? null]);
pc_rest('PATCH', '/platform/offers/' . $o2['offer']['id'], ['internal_note' => 'x', 'customer' => ['company' => 'Andere 2', 'email' => "andere$sfx@example.test"]]);
[$st, $r] = pc_rest('GET', "/platform/offers?customer=v-$A");
pc_eq('Ändern des Entwurfs behält die Verknüpfung', [$o2['offer']['id']], array_column($r['offers'] ?? [], 'id'));
[$st, $v] = pc_rest('POST', '/platform/offers/' . $o2['offer']['id'] . '/version');
$offers[] = $v['offer']['id'] ?? 0;
[$st, $dup] = pc_rest('POST', '/platform/offers/' . $o2['offer']['id'] . '/duplicate');
$offers[] = $dup['offer']['id'] ?? 0;
[$st, $r] = pc_rest('GET', "/platform/offers?customer=v-$A&all=1");
$ids = array_column($r['offers'] ?? [], 'id');
pc_eq('Neue Version bleibt beim Kunden, Duplikat nicht', [true, false], [in_array($v['offer']['id'] ?? -1, $ids, true), in_array($dup['offer']['id'] ?? -1, $ids, true)]);
[$st, $d] = pc_rest('GET', "/platform/customers/v-$A");
pc_eq('Akte: Zähler Support/Abrechnungen/Angebote (Entwurf + neue Version)', [1, true, 2], [$d['customer']['counts']['support_open'] ?? -1, ($d['customer']['counts']['settlements'] ?? 0) >= ($setts ? 1 : 0), $d['customer']['counts']['offers'] ?? -1]);
[$st, $u] = pc_rest('DELETE', "/platform/customers/v-$A/offers", ['offer_id' => $v['offer']['id']]);
[$st, $r] = pc_rest('GET', "/platform/offers?customer=v-$A&all=1");
pc_eq('Lösen', [200, false], [$st, in_array($v['offer']['id'], array_column($r['offers'] ?? [], 'id'), true)]);

// Rechte
wp_set_current_user($own);
[$st] = pc_rest('GET', '/platform/customers');
pc_eq('Veranstalter → 403', 403, $st);
wp_set_current_user(0);
[$st] = pc_rest('GET', '/platform/customers');
pc_eq('Abgemeldet → 401', 401, $st);
wp_set_current_user($adm);

// Aufräumen
foreach ($offers as $x) if ($x) $wpdb->delete(TIX_Platform_Offers::table(), ['id' => $x]);
foreach ($setts as $x) $wpdb->delete($st_t, ['id' => $x]);
foreach ($lics as $x) { $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $x]); $wpdb->delete($wpdb->prefix . 'tix_license_sites', ['license_id' => $x]); $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $x]); }
foreach ($posts as $x) wp_delete_post($x, true);
update_option(TIX_Partners::OPTION, $p_old);
update_option('tix_license_server', $ls_old);
require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($users as $u) wp_delete_user($u); wp_set_current_user(0);
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
