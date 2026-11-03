<?php
/** Integrationstest Studio-Audit 2026-11-03 (K1, H2, K2 …). wp eval-file …/tests/test-audit-fixes-wp.php – einmal ohne, einmal mit tix_multi_organizer=1 */
if (!defined('ABSPATH') || !class_exists('TIX_Staff_Login_Code')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function af_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function af_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$mk = function ($role, $tag) use (&$users, $sfx) { $id = wp_insert_user(['user_login' => "af_{$tag}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "af_{$tag}_$sfx@example.test", 'role' => $role]); $users[] = $id; return $id; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$owner = $mk('tix_organizer', 'owner');
$customer = $mk('subscriber', 'kunde');
update_user_meta($customer, '_tix_app_passwordless', 1); // Gastbesteller aus dem Code-Login
$org = 0;
if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AF Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org; }

// ── K1: Kunde ins Team holen und übernehmen ──
// Team verwalten: Plattform = Inhaber, Einzel-Seite = nur WP-Admin; Codes erzeugt in beiden Fällen der Inhaber (tix_organizer)
$mgr = $multi ? $owner : 1;
wp_set_current_user($mgr);
[$st, $d] = af_rest('POST', '/team', ['email' => "af_kunde_$sfx@example.test", 'role' => 'entrance']);
af_eq('K1 Kunde ins Team (Rolle) geht weiter', 200, $st);
wp_set_current_user($owner);
[$st, , $code] = af_rest('POST', '/staff/login-code', ['user_id' => $customer]);
af_eq('K1 kein Anmelde-Code für fremdes Kundenkonto', 403, $st);
wp_set_current_user($mgr);
if ($multi) {
    [$st, , $code] = af_rest('POST', "/team/$customer/password", ['password' => 'Sehr-Geheim-2026x']);
    af_eq('K1 kein Passwort-Setzen für passwortloses Kundenkonto', 409, $st);
}
$m = null; [, $L] = af_rest('GET', '/team'); foreach ((array) ($L['team'] ?? []) as $x) if (is_array($x) && intval($x['id'] ?? 0) === $customer) $m = $x;
af_eq('K1 Mitglied meldet can_login_code (nur WP-Admin darf)', !$multi, $m['can_login_code'] ?? null);
if ($multi) af_eq('K1 Mitglied meldet can_set_password=false', false, $m['can_set_password'] ?? null);
// selbst angelegtes Helfer-Konto: Code und Passwort gehen
[$st, $d] = af_rest('POST', '/team', ['email' => "af_helfer_$sfx@example.test", 'name' => 'Helfer', 'role' => 'entrance', 'password_mode' => 'set', 'password' => 'Helfer-Pass-2026x']);
af_eq('K1 Helfer neu mit Passwort', 200, $st);
$helper = (int) (get_user_by('email', "af_helfer_$sfx@example.test")->ID ?? 0); $users[] = $helper;
wp_set_current_user($owner);
[$st] = af_rest('POST', '/staff/login-code', ['user_id' => $helper]);
af_eq('K1 Code für selbst angelegten Helfer', 200, $st);
// ── H2: Team-Admin → Inhaber ──
wp_set_current_user($mgr);
[$st] = af_rest('POST', '/team', ['email' => "af_tadmin_$sfx@example.test", 'name' => 'TA', 'role' => 'admin']);
af_eq('H2 Team-Admin angelegt', 200, $st);
$tadmin = (int) (get_user_by('email', "af_tadmin_$sfx@example.test")->ID ?? 0); $users[] = $tadmin;
wp_set_current_user($owner);
[$st] = af_rest('POST', '/staff/login-code', ['user_id' => $tadmin]);
af_eq('H2 kein Code für Team-Admin', 403, $st);
if ($tadmin) {
    wp_set_current_user($tadmin);
    [$st] = af_rest('POST', '/staff/login-code', ['user_id' => $owner]);
    af_eq('H2 Team-Admin bekommt keinen Code für den Inhaber', 403, $st);
    [$st] = af_rest('POST', '/staff/login-code');
    af_eq('H2 eigener Code geht', 200, $st);
}

// ── K2: Studio-Speichern ohne sale_price/desc/hidden behält sie ──
wp_set_current_user($owner);
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'AF Party 🎉', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 7 * 86400), '_tix_tickets_enabled' => '1',
  '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 20, 'sale_price' => 15, 'qty' => 50, 'desc' => 'inkl. Getränk', 'hidden' => 0, 'online' => '1'],
                               ['name' => 'Gästeliste', 'price' => 0, 'sale_price' => '', 'qty' => 20, 'desc' => '', 'hidden' => 1, 'online' => '1']]]]); $posts[] = $ev;
[$st, $E] = af_rest('GET', "/events/$ev/edit");
af_eq('K2 Editor lesbar', 200, $st);
$body = ['title' => 'AF Party 🎉', 'date_start' => wp_date('Y-m-d', time() + 7 * 86400), 'time_start' => '22:00', 'ticket_mode' => 'online', 'tickets_enabled' => true,
  'categories' => [['index' => 0, 'name' => 'Normal', 'price' => 20, 'qty' => 50], ['index' => 1, 'name' => 'Gästeliste', 'price' => 0, 'qty' => 20]]];
[$st, $R] = af_rest('POST', "/events/$ev", $body);
af_eq('K2 Speichern wie Studio alt', 200, $st);
$c = get_post_meta($ev, '_tix_ticket_categories', true);
af_eq('K2 Aktionspreis bleibt', 15.0, floatval($c[0]['sale_price'] ?? 0));
af_eq('K2 Beschreibung bleibt', 'inkl. Getränk', $c[0]['desc'] ?? '');
af_eq('K2 versteckt bleibt', 1, intval($c[1]['hidden'] ?? 0));
$body['categories'][0] += ['sale_price' => '', 'desc' => '']; $body['categories'][1]['hidden'] = false;
[$st] = af_rest('POST', "/events/$ev", $body);
$c = get_post_meta($ev, '_tix_ticket_categories', true);
af_eq('K2 mitgeschickt leer = gelöscht (Aktionspreis)', '', $c[0]['sale_price'] ?? 'x');
af_eq('K2 mitgeschickt false = sichtbar', 0, intval($c[1]['hidden'] ?? 9));
af_eq('Emoji-Slug ohne %', false, strpos((string) get_post_field('post_name', $ev), '%') !== false);

// ── H1: Einlass von Event A nimmt kein Ticket von Event B ──
$evA = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AF A', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d')]]); $posts[] = $evA;
$evB = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AF B', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 86400)]]); $posts[] = $evB;
$tk = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'AFTK' . $sfx . 'XYZ0', 'meta_input' => ['_tix_ticket_event_id' => $evB, '_tix_ticket_code' => 'AFTK' . $sfx . 'XYZ0', '_tix_ticket_status' => 'valid', '_tix_ticket_cat_index' => 0]]); $posts[] = $tk;
wp_set_current_user($owner);
[$st, $d] = af_rest('POST', '/checkin/scan', ['code' => 'AFTK' . $sfx . 'XYZ0', 'event_id' => $evA]);
af_eq('H1 falsches Event abgelehnt', 'invalid', $d['status'] ?? '');
af_eq('H1 Grund wrong_event', true, !empty($d['wrong_event']));
af_eq('H1 Ticket nicht verbraucht', 'valid', get_post_meta($tk, '_tix_ticket_status', true));
[$st, $d] = af_rest('POST', '/checkin/scan', ['code' => 'AFTK' . $sfx . 'XYZ0', 'event_id' => $evB]);
af_eq('H1 richtiges Event geht', true, in_array($d['status'] ?? '', ['valid', 'success', 'ok', 'checked_in'], true) || !empty($d['ok']) && ($d['status'] ?? '') !== 'invalid');

// ── H3/H4/H11: App-Inhalte ──
if (!$multi) {
    $staffer = $mk('tix_staff', 'staff');
    wp_set_current_user($staffer);
    [$st] = af_rest('POST', '/app/config', ['pages' => ['kontakt' => ['html' => '<p>x</p>']]]);
    af_eq('H4 Mitarbeiter darf App-Inhalte nicht ändern', 403, $st);
    wp_set_current_user($owner);
    $prev = get_option('_tix_app_config', null);
    [$st] = af_rest('POST', '/app/config', ['pages' => ['kontakt' => ['html' => '<p>Hallo</p><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>'], 'faq' => ['faq' => [['q' => 'F', 'a' => '<script>alert(1)</script>ok']]]]]);
    af_eq('H4 Inhaber darf', 200, $st);
    $cfg = get_option('_tix_app_config', []);
    $html = (string) ($cfg['pages']['kontakt']['html'] ?? '');
    af_eq('H3 onerror entfernt', false, stripos($html, 'onerror') !== false);
    af_eq('H3 javascript: entfernt', false, stripos($html, 'javascript:') !== false);
    af_eq('H3 script in FAQ entfernt', false, stripos((string) ($cfg['pages']['faq']['faq'][0]['a'] ?? ''), '<script') !== false);
    // H11: GET-Antwort unverändert zurückschicken → 200, keine Schalter neu gesetzt
    [, $G] = af_rest('GET', '/app/config');
    unset($G['ok']);
    [$st, $d] = af_rest('POST', '/app/config', is_array($G) ? $G : []);
    af_eq('H11 ganze GET-Antwort zurück = 200', 200, $st);
    if ($prev === null) delete_option('_tix_app_config'); else update_option('_tix_app_config', $prev, false);
}

// ── H9: Vertriebler aus bestehendem Konto, Entfernen löscht es nicht ──
if ($multi && class_exists('TIX_Sales')) {
    wp_set_current_user(1);
    $gast = $mk('subscriber', 'vgast');
    [$st, $d] = af_rest('POST', '/platform/sales', ['email' => "af_vgast_$sfx@example.test", 'name' => 'V Gast']);
    af_eq('H9 bestehendes Kundenkonto als Vertrieb', 201, $st);
    [$st, $d] = af_rest('DELETE', "/platform/sales/$gast");
    af_eq('H9 Entfernen ok', 200, $st);
    $gu = get_userdata($gast);
    af_eq('H9 Kundenkonto bleibt bestehen', true, (bool) $gu);
    af_eq('H9 Rolle zurück auf subscriber', ['subscriber'], $gu ? array_values((array) $gu->roles) : []);
    $tm = $mk('tix_entrance', 'vteam'); $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AF Fremd']); $posts[] = $org2;
    update_user_meta($tm, '_tix_team_organizer_id', $org2);
    [$st] = af_rest('POST', '/platform/sales', ['email' => "af_vteam_$sfx@example.test", 'name' => 'V Team']);
    af_eq('H9 Team-Mitglied eines Veranstalters abgelehnt', 409, $st);
}

// Aufräumen
wp_set_current_user(1);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
