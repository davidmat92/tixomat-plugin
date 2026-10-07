<?php
/**
 * Integrationstest Freigabe neuer Veranstalter (TIX_Org_Approval) gegen ein echtes
 * Test-WordPress mit aktivem Tixomat-Plugin (NIE auf einer Live-Seite ausführen –
 * legt Nutzer, Veranstalter und Events an und löscht sie am Ende wieder).
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-org-approval-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Org_Approval')) { echo "WordPress mit Tixomat nötig\n"; return; }
if (stripos(home_url(), 'localhost') === false && stripos(home_url(), '.test') === false) { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0; $GLOBALS['t_mails'] = [];
function t_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['t_mails'][] = $atts; return true; }, 10, 2);
function t_mails_to($to) { return count(array_filter($GLOBALS['t_mails'], function ($m) use ($to) { return in_array($to, (array) $m['to'], true) || $m['to'] === $to; })); }
function t_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    return [$res->get_status(), $res->get_data()];
}

update_option('tix_multi_organizer', '1');
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$created_users = []; $created_posts = [];
$sfx = wp_rand(1000, 9999);

// ── 1. Einführung: Bestand ohne Status → freigegeben ──
wp_set_current_user(0);
$legacy = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "Alt $sfx"]);
$created_posts[] = $legacy;
delete_post_meta($legacy, TIX_Org_Approval::META_STATUS);
t_eq('ohne Status gilt freigegeben', 'approved', TIX_Org_Approval::status($legacy));
delete_option(TIX_Org_Approval::DB_OPTION);
TIX_Org_Approval::maybe_migrate();
t_eq('Einführung setzt approved', 'approved', get_post_meta($legacy, TIX_Org_Approval::META_STATUS, true));
t_eq('Einführung schreibt Verlauf', 'approved', (get_post_meta($legacy, TIX_Org_Approval::META_LOG, true)[0]['action'] ?? ''));
t_eq('Vertrag eingerichtet', true, TIX_Org_Approval::terms()['version'] !== '' && TIX_Org_Approval::terms()['url'] !== '');

// ── 2. Admin legt Veranstalter an → freigegeben ──
wp_set_current_user($admin);
$by_admin = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "Admin-Org $sfx"]);
$created_posts[] = $by_admin;
t_eq('Admin-Anlage: approved', 'approved', TIX_Org_Approval::status($by_admin));

// ── 3. Registrierung (wie TIX_Register_Event) ──
wp_set_current_user(0);
$uid = wp_create_user("neu$sfx", wp_generate_password(), "neu$sfx@example.test");
$created_users[] = $uid;
wp_update_user(['ID' => $uid, 'role' => 'tix_organizer']);
$org = wp_insert_post(['post_type' => 'tix_organizer', 'post_title' => "Neu-Org $sfx", 'post_status' => 'publish', 'post_author' => $uid]);
$created_posts[] = $org;
update_post_meta($org, '_tix_org_user_id', $uid);
TIX_Org_Approval::set_status($org, 'pending', ['by' => $uid, 'note' => 'Registrierung', 'notify' => false]);
t_eq('Registrierung: pending', 'pending', TIX_Org_Approval::status($org));
$ev = wp_insert_post(['post_type' => 'event', 'post_title' => "Event $sfx", 'post_status' => 'publish', 'post_author' => $uid]);
$created_posts[] = $ev;
update_post_meta($ev, '_tix_organizer_id', $org);
update_post_meta($ev, '_tix_tickets_enabled', '1');
update_post_meta($ev, '_tix_date_start', gmdate('Y-m-d', time() + 20 * DAY_IN_SECONDS));
update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Standard', 'price' => 10, 'qty' => 0]]);
TIX_Org_Approval::process_queue();
t_eq('Event zurückgehalten (pending)', 'pending', get_post_status($ev));
t_eq('Wunsch-Status gemerkt', 'publish', get_post_meta($ev, TIX_Org_Approval::META_HELD, true));
t_eq('Event nicht im Katalog', null, TIX_Public_Events::payload($ev));
$se = TIX_Org_Approval::sale_error($ev);
t_eq('Kasse lehnt ab', 'organizer_not_approved', is_wp_error($se) ? $se->get_error_code() : null);
TIX_App_Scope::flush_public_cache();
list($code, $data) = t_rest('GET', '/public/organizers', []);
$names = array_map(function ($o) { return $o['name']; }, $data['organizers'] ?? []);
t_eq('nicht in /public/organizers', false, in_array("Neu-Org $sfx", $names, true));
t_eq('Admin-Org in /public/organizers', true, in_array("Admin-Org $sfx", $names, true));
list($code) = t_rest('GET', '/public/organizers/' . $org);
t_eq('/public/organizers/{id} 404', 404, $code);
t_eq('Veranstalter-Seite nicht öffentlich', false, apply_filters('tix_organizer_page_public', true, $org));
t_eq('Veranstalter-Seite (freigegeben) öffentlich', true, apply_filters('tix_organizer_page_public', true, $by_admin));
$sm = apply_filters('wp_sitemaps_posts_query_args', ['post_type' => 'tix_organizer', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1], 'tix_organizer');
$sm_ids = array_map('intval', get_posts($sm));
t_eq('Sitemap ohne wartenden Veranstalter', false, in_array($org, $sm_ids, true));
t_eq('Sitemap mit freigegebenem Veranstalter', true, in_array($by_admin, $sm_ids, true));

// ── 4. Veranstalter angemeldet: /me + /organizer/approval, Veröffentlichen gesperrt ──
wp_set_current_user($uid);
list($code, $me) = t_rest('GET', '/me');
t_eq('/me: approval.status', 'pending', $me['organizer']['approval']['status'] ?? null);
t_eq('/me: organizer_id gesetzt (Zugriff bleibt)', $org, $me['user']['organizer_id'] ?? null);
t_eq('/me: can_sell false', false, $me['organizer']['approval']['can_sell'] ?? null);
list($code, $ap) = t_rest('GET', '/organizer/approval');
t_eq('/organizer/approval 200', 200, $code);
t_eq('fehlende Angaben', ['name', 'address', 'contact', 'tax', 'payout', 'terms'], $ap['missing']);
list($code, $prof) = t_rest('GET', '/organizer/profile');
t_eq('Profil lesbar (wartend)', 200, $code);
list($code, $pd) = t_rest('GET', '/organizer/payout-details');
t_eq('Auszahlungsdaten lesbar (wartend)', 200, $code);
wp_update_post(['ID' => $ev, 'post_status' => 'draft']);
t_eq('Entwurf: Merker entfernt', '', get_post_meta($ev, TIX_Org_Approval::META_HELD, true));
wp_update_post(['ID' => $ev, 'post_status' => 'publish']);
t_eq('erneut veröffentlichen → pending', 'pending', get_post_status($ev));
t_eq('erneut gemerkt', 'publish', get_post_meta($ev, TIX_Org_Approval::META_HELD, true));
list($code, $list) = t_rest('GET', '/events', ['filter' => 'upcoming']);
$mine = array_values(array_filter($list['events'] ?? [], function ($e) use ($ev) { return intval($e['id']) === $ev; }));
t_eq('App-Liste enthält zurückgehaltenes Event', 1, count($mine));
t_eq('App-Liste: publish_held', true, $mine[0]['publish_held'] ?? null);
list($code, $ed) = t_rest('POST', '/events/' . $ev, ['title' => "Event $sfx", 'published' => false]);
t_eq('App speichert zurückgehaltenes Event (published=false)', 200, $code);
t_eq('Veröffentlichungswunsch bleibt', 'publish', get_post_meta($ev, TIX_Org_Approval::META_HELD, true));
t_eq('Antwort: event.publish_held', true, $ed['event']['publish_held'] ?? null);
t_eq('Antwort: edit.event.publish_held', true, $ed['edit']['event']['publish_held'] ?? ($ed['edit']['publish_held'] ?? null));
$ev2 = wp_insert_post(['post_type' => 'event', 'post_title' => "Event2 $sfx", 'post_status' => 'publish', 'post_author' => $uid]);
$created_posts[] = $ev2;
t_eq('neues Event als Veranstalter → pending', 'pending', get_post_status($ev2));
update_post_meta($ev2, '_tix_organizer_id', $org);
$evs = wp_insert_post(['post_type' => 'event', 'post_title' => "Geteilt $sfx", 'post_status' => 'publish', 'meta_input' => ['_tix_syndicated' => '1', '_tix_organizer_id' => $org]]);
$created_posts[] = $evs;
TIX_Org_Approval::process_queue();
t_eq('geteiltes Event bleibt online', 'publish', get_post_status($evs));

// Pflichtangaben ergänzen
update_post_meta($org, '_tix_org_phone', '+49 30 123456');
$r = TIX_Payout_Details::save($org, ['holder' => 'Neu GmbH', 'billing' => ['company' => 'Neu GmbH', 'street' => 'Weg 1', 'zip' => '10115', 'city' => 'Berlin', 'country' => 'DE'], 'tax_status' => 'small_business']);
t_eq('Auszahlungsdaten gespeichert', false, is_wp_error($r));
update_post_meta($org, TIX_Payout_Details::META_IBAN, TIX_Payout_Details::encrypt('DE89370400440532013000'));
list($code, $err) = t_rest('POST', '/organizer/approval/terms', ['version' => 'falsch']);
t_eq('Vertrag: falsche Version 409', 409, $code);
list($code, $ap) = t_rest('POST', '/organizer/approval/terms', ['version' => TIX_Org_Approval::terms()['version']]);
t_eq('Vertrag zugestimmt', true, $ap['terms']['accepted'] ?? null);
t_eq('alles vollständig', true, $ap['complete'] ?? null);
list($code) = t_rest('POST', '/organizer/approval/resubmit');
t_eq('resubmit nur bei abgelehnt', 409, $code);

// ── 5. Admin: Ablehnen → erneut einreichen → Freigeben ──
wp_set_current_user($admin);
$GLOBALS['t_mails'] = [];
TIX_Org_Approval::set_status($org, 'rejected', ['reason' => 'Impressum fehlt']);
t_eq('abgelehnt', 'rejected', TIX_Org_Approval::status($org));
t_eq('Mail an Inhaber (Ablehnung)', 1, t_mails_to("neu$sfx@example.test"));
$feed = get_user_meta($uid, '_tix_app_notif_user', true);
t_eq('Feed-Eintrag mit Aktion', 'organizer:approval', $feed[0]['action'] ?? null);
wp_set_current_user($uid);
list($code, $ap) = t_rest('GET', '/organizer/approval');
t_eq('Begründung sichtbar', 'Impressum fehlt', $ap['reason']);
t_eq('can_resubmit', true, $ap['can_resubmit']);
$GLOBALS['t_mails'] = [];
list($code, $ap) = t_rest('POST', '/organizer/approval/resubmit');
t_eq('erneut eingereicht', 'pending', $ap['status'] ?? null);
t_eq('Admin-Mail bei erneuter Einreichung', 1, t_mails_to(TIX_Org_Approval::admin_email()));
wp_set_current_user($admin);
TIX_Org_Approval::set_status($org, 'approved');
t_eq('freigegeben', 'approved', TIX_Org_Approval::status($org));
t_eq('Event online', 'publish', get_post_status($ev));
t_eq('Merker weg', '', get_post_meta($ev, TIX_Org_Approval::META_HELD, true));
t_eq('Event2 online', 'publish', get_post_status($ev2));
t_eq('Kasse frei', null, TIX_Org_Approval::sale_error($ev));
t_eq('im Katalog', true, is_array(TIX_Public_Events::payload($ev)));

// ── 6. Sperren → offline, Entsperren → wieder online ──
TIX_Org_Approval::set_status($org, 'blocked', ['reason' => 'Prüfung']);
t_eq('gesperrt: Event offline', 'pending', get_post_status($ev));
$se = TIX_Org_Approval::sale_error($ev);
t_eq('gesperrt: Kassenmeldung', true, is_wp_error($se) && strpos($se->get_error_message(), 'gesperrt') !== false);
t_eq('gesperrt: geteiltes Event bleibt', 'publish', get_post_status($evs));
TIX_Org_Approval::set_status($org, 'approved');
t_eq('entsperrt: Event online', 'publish', get_post_status($ev));

// ── 7. Admin weist veröffentlichtes Event einem wartenden Veranstalter zu ──
TIX_Org_Approval::set_status($org, 'pending', ['notify' => false]);
t_eq('wieder wartend: Events zurückgehalten', 'pending', get_post_status($ev2));
$ev3 = wp_insert_post(['post_type' => 'event', 'post_title' => "Admin-Event $sfx", 'post_status' => 'publish']);
$created_posts[] = $ev3;
t_eq('Admin-Event ohne Veranstalter online', 'publish', get_post_status($ev3));
update_post_meta($ev3, '_tix_organizer_id', $org);
TIX_Org_Approval::process_queue();
t_eq('nach Zuweisung zurückgehalten', 'pending', get_post_status($ev3));

// ── 8. Angemeldeter Nicht-Admin legt Veranstalter an → wartet ──
$uid2 = wp_create_user("selbst$sfx", wp_generate_password(), "selbst$sfx@example.test");
$created_users[] = $uid2;
wp_update_user(['ID' => $uid2, 'role' => 'tix_organizer']);
wp_set_current_user($uid2);
$GLOBALS['t_mails'] = [];
$org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "Selbst $sfx"]);
$created_posts[] = $org2;
t_eq('selbst angelegt: pending', 'pending', TIX_Org_Approval::status($org2));
t_eq('Admin benachrichtigt', 1, t_mails_to(TIX_Org_Approval::admin_email()));

// ── 9. Ohne Mehr-Veranstalter-Modus: alles wie vorher ──
update_option('tix_multi_organizer', '0');
t_eq('Ein-Veranstalter: approved', 'approved', TIX_Org_Approval::status($org));
wp_set_current_user($uid);
$ev4 = wp_insert_post(['post_type' => 'event', 'post_title' => "Single $sfx", 'post_status' => 'publish', 'post_author' => $uid, 'meta_input' => ['_tix_organizer_id' => $org]]);
$created_posts[] = $ev4;
t_eq('Ein-Veranstalter: Event online', 'publish', get_post_status($ev4));
t_eq('Ein-Veranstalter: Kasse frei', null, TIX_Org_Approval::sale_error($ev));
update_option('tix_multi_organizer', '1');

// ── Aufräumen ──
wp_set_current_user($admin);
foreach ($created_posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($created_users as $u) wp_delete_user($u);

echo "\n" . $GLOBALS['t_count'] . " Prüfungen, " . $GLOBALS['t_fails'] . " Fehler\n";
