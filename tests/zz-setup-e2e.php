<?php
// nur lokal: Vertriebler + Interessent für den E2E-Test anlegen, Anmelde-Link ausgeben
add_filter('pre_wp_mail', function ($n, $a) { if (preg_match('~(https?://[^"\s]+/anmelden/link/\?t=[A-Za-z0-9%]+)~', $a['message'], $m)) echo "LINK " . $a['to'] . " " . html_entity_decode($m[1]) . "\n"; return true; }, 1, 2);
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
wp_set_current_user($admin);
foreach (['tim.e2e@example.org', 'lena.e2e@beispiel.test', 'max.e2e@beispiel.test'] as $e) if ($u = get_user_by('email', $e)) wp_delete_user($u->ID);
$r = new WP_REST_Request('POST', '/tixomat/v1/demo-access'); $r->set_header('content-type', 'application/json');
$r->set_body(wp_json_encode(['kind' => 'sales', 'name' => 'Tim Krüger', 'email' => 'tim.e2e@example.org'])); $d = rest_do_request($r)->get_data();
$sales = intval($d['access']['id']); wp_set_password('Vertrieb-Test-2026!', $sales); echo "SALES $sales\n";
wp_set_current_user($sales);
foreach ([['Lena Brandt', 'lena.e2e@beispiel.test', 'Beispiel Club Köln'], ['Max Mustermann', 'max.e2e@beispiel.test', '']] as $p) {
  $r = new WP_REST_Request('POST', '/tixomat/v1/demo-access'); $r->set_header('content-type', 'application/json');
  $r->set_body(wp_json_encode(['kind' => 'prospect', 'name' => $p[0], 'email' => $p[1], 'company' => $p[2]])); $d = rest_do_request($r)->get_data();
  echo "PROSPECT " . intval($d['access']['id']) . " code=" . TIX_Demo_Access::code(intval($d['access']['id'])) . "\n";
}
