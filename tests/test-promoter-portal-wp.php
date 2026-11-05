<?php
/** Promoter-Bereich im Studio (TIX_Promoter_Portal, 1.38.651). wp eval-file …/tests/test-promoter-portal-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Promoter_Portal')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function pp_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function pp_rest($m, $r, array $b = [], $tok = '') { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($tok) $q->set_header('X-Tix-Promoter-Token', $tok); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create();
TIX_Promoter_DB::create_tables(); TIX_Promoter_DB::ensure_email_column(); TIX_Promoter_DB::ensure_org_column(); TIX_Promoter_DB::ensure_clicks_table(); TIX_Promoter_Portal::maybe_create();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $proms = [];
$GLOBALS['pp_mails'] = [];
add_filter('pre_wp_mail', function ($n, $a) { $GLOBALS['pp_mails'][] = $a; return true; }, 10, 2);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_pp\\_%' OR option_name LIKE '\\_transient\\_%tix\\_pp\\_sent\\_%'");
wp_cache_flush();
$set_before = get_option('tix_settings', []);
$s = is_array($set_before) ? $set_before : []; $s['promoter_enabled'] = 1; $s['promoter_self_signup'] = 1; update_option('tix_settings', $s);
$studio_before = get_option('tix_studio_url', null); update_option('tix_studio_url', 'https://studio.seite.test');
$owner = wp_insert_user(['user_login' => "pp_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "pp_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'PP Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'PP Andere']); $posts[] = $org2;
}
$mkev = function ($t, $o, $days) use (&$posts) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "PP $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS)]]); $posts[] = $id; return $id; };
$ev = $mkev("Party $sfx", $org, 7); $ev2 = $mkev("Fremd $sfx", $org2, 8); $evp = $mkev("Vorbei $sfx", $org, -5);
wp_set_current_user($owner);
[$st, $r] = pp_rest('POST', '/organizer/promoters', ['name' => 'Mia Promo', 'email' => "mia_$sfx@example.test", 'commission' => ['type' => 'fixed', 'value' => 2]]);
$pid = intval($r['promoter']['id'] ?? 0); $proms[] = $pid; $code = $r['promoter']['code'] ?? '';
pp_rest('POST', "/organizer/promoters/$pid/events", ['event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 3, 'discount_type' => 'percent', 'discount_value' => 10, 'promo_code' => "MIA$sfx"]);
wp_set_current_user(0);

// ═══ Anmeldung ═══
$n0 = count($GLOBALS['pp_mails']);
[$st, $d] = pp_rest('POST', '/promoter/auth/link', ['email' => "unbekannt_$sfx@example.test"]);
pp_eq('Unbekannte E-Mail: gleiche Antwort, keine Mail', [200, true, $n0], [$st, $d['sent'] ?? null, count($GLOBALS['pp_mails'])]);
[$st, $d] = pp_rest('POST', '/promoter/auth/link', ['email' => "MIA_$sfx@example.test"]);
$m = end($GLOBALS['pp_mails']); $msg = (string) ($m['message'] ?? '');
preg_match('#https://studio\.seite\.test/promoter/anmelden/\?t=([A-Za-z0-9]{32}\.\d+)#', html_entity_decode($msg), $lk);
preg_match('#letter-spacing:6px;[^>]*>(\d{6})<#', $msg, $cd);
pp_eq('Login-Mail: Link auf die Studio-Domain (nie WordPress) und Code', [200, true, true, false], [$st, !empty($lk[1]), !empty($cd[1]), strpos($msg, 'tix_pauth') !== false || (bool) preg_match('#href="[^"]*(wp-login|wp-admin|/promoter/\?|tix_pauth)#', $msg)]);
[$st, $d, $c] = pp_rest('POST', '/promoter/auth/confirm', ['email' => "mia_$sfx@example.test", 'code' => '000000' === ($cd[1] ?? '') ? '111111' : '000000']);
pp_eq('Falscher Code → 400 tix_login_invalid', [400, 'tix_login_invalid'], [$st, $c]);
[$st, $d] = pp_rest('POST', '/promoter/auth/confirm', ['email' => "mia_$sfx@example.test", 'code' => $cd[1] ?? '']);
$tok = (string) ($d['token'] ?? '');
pp_eq('Richtiger Code → Token (tp_…), Promoter', [200, true, $code], [$st, (bool) preg_match('/^tp_[A-Za-z0-9]{40}$/', $tok), $d['promoter']['code'] ?? '']);
[$st] = pp_rest('POST', '/promoter/auth/confirm', ['email' => "mia_$sfx@example.test", 'code' => $cd[1] ?? '']);
pp_eq('Code nur einmal gültig', 400, $st);
pp_rest('POST', '/promoter/auth/link', ['email' => "mia_$sfx@example.test"]);
preg_match('#\?t=([A-Za-z0-9]{32}\.\d+)#', html_entity_decode((string) (end($GLOBALS['pp_mails'])['message'] ?? '')), $lk2);
[$st, $d2] = pp_rest('POST', '/promoter/auth/confirm', ['t' => $lk2[1] ?? '']);
$tok2 = (string) ($d2['token'] ?? '');
pp_eq('Login-Link → zweites Token (zweites Gerät)', [200, true], [$st, $tok2 !== '' && $tok2 !== $tok]);
$legacy = TIX_Promoter_Auth::build_magic_url($pid); parse_str((string) wp_parse_url($legacy, PHP_URL_QUERY), $lq);
[$st, $d3] = pp_rest('POST', '/promoter/auth/confirm', ['legacy' => $lq['tix_pauth'] ?? '']);
pp_eq('Alter Magic-Link aus WordPress-Mails funktioniert weiter (legacy)', [200, true], [$st, !empty($d3['token'])]);
pp_eq('Promoter-Seite zeigt auf das Studio', 'https://studio.seite.test/promoter/', TIX_Promoter_Auth::get_promoter_page_url());

// ═══ Eigene Daten ═══
[$st] = pp_rest('GET', '/promoter/me');
pp_eq('Ohne Token → 401', 401, $st);
[$st, $me] = pp_rest('GET', '/promoter/me', [], $tok);
pp_eq('me: Name, Code, Links mit ?ref=, Marke', [200, 'Mia Promo', $code, true, true], [$st, $me['promoter']['name'] ?? '', $me['promoter']['code'] ?? '', strpos((string) ($me['promoter']['links']['link'] ?? ''), 'ref=' . $code) !== false, isset($me['promoter']['brand']['accent'])]);
[$st, $me2] = pp_rest('POST', '/promoter/me', ['name' => 'Mia P.'], $tok);
pp_eq('Name ändern', 'Mia P.', $me2['promoter']['name'] ?? '');
[$st, $L] = pp_rest('GET', '/promoter/links', [], $tok);
$ids = array_column(array_column((array) ($L['events'] ?? []), 'event'), 'id');
$evl = array_values(array_filter((array) ($L['events'] ?? []), function ($e) use ($ev) { return ($e['event']['id'] ?? 0) === $ev; }))[0] ?? [];
pp_eq('Links: eigenes kommendes Event mit Promo-Code und Provision, kein vorbeigegangenes', [200, true, false, "MIA$sfx", 3.0, true],
    [$st, in_array($ev, $ids, true), in_array($evp, $ids, true), $evl['promo_code'] ?? '', $evl['commission']['value'] ?? null, strpos((string) ($evl['links']['qr_link'] ?? ''), 'utm_medium=qr') !== false]);
if ($multi) pp_eq('Fremder Veranstalter: dessen Event fehlt', false, in_array($ev2, $ids, true));
// Verkauf (Event vorbei → auszahlbar) und einer, der wartet
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name(); $orders = [];
foreach ([[$evp, 2], [$ev, 1]] as $x) {
    $wpdb->insert($ot, ['order_number' => 'PP' . wp_rand(), 'status' => 'completed', 'order_key' => wp_generate_password(12, false), 'total' => 20, 'subtotal' => 20, 'payment_method' => 'mollie', 'billing_email' => "kaeufer_$sfx@example.test", 'billing_first_name' => 'Geheim', 'date_created' => current_time('mysql')]);
    $oid = intval($wpdb->insert_id); $orders[] = $oid;
    $wpdb->insert($oi, ['order_id' => $oid, 'event_id' => $x[0], 'quantity' => $x[1], 'total' => 20, 'name' => 'PP', 'cat_name' => 'Regulär']);
    TIX_Order_Attribution::set_meta($oid, '_tix_promoter_id', $pid); TIX_Order_Attribution::set_meta($oid, '_tix_promoter_attribution', 'referral');
    TIX_Promoter::calculate_commissions($oid);
}
[$st, $S] = pp_rest('GET', '/promoter/stats', [], $tok);
pp_eq('Übersicht: auszahlbar 4 € (vorbei), wartet 3 €, 3 Tickets, nächste Events', [200, 4.0, 3.0, 3, true], [$st, $S['earnings']['payable'] ?? null, $S['earnings']['waiting'] ?? null, $S['sales']['tickets'] ?? 0, count((array) ($S['upcoming'] ?? [])) >= 1]);
[$st, $V] = pp_rest('GET', '/promoter/sales', [], $tok);
$json = wp_json_encode($V);
pp_eq('Verkäufe ohne Käuferdaten (keine E-Mail, kein Name, keine Bestellnummer)', [200, 2, false, false, false], [$st, count((array) ($V['sales'] ?? [])), strpos($json, "kaeufer_$sfx") !== false, strpos($json, 'Geheim') !== false, strpos($json, 'order_id') !== false]);
pp_eq('Verkäufe je Event zusammengefasst', 2, count((array) ($V['by_event'] ?? [])));
// Auszahlungsdaten
[$st, $d, $c] = pp_rest('POST', '/promoter/payout-details', ['holder' => 'Mia P', 'iban' => 'DE00 1234'], $tok);
pp_eq('Ungültige IBAN → 400 am Feld', [400, 'iban'], [$st, $d['data']['field'] ?? '']);
[$st, $d] = pp_rest('POST', '/promoter/payout-details', ['holder' => 'Mia P', 'iban' => 'DE89 3704 0044 0532 0130 00'], $tok);
pp_eq('IBAN gespeichert, nur gekürzt sichtbar, verschlüsselt', [200, false, false], [$st, strpos(wp_json_encode($d), '370400440532013000') !== false, strpos(wp_json_encode(get_option('tix_promoter_payout_' . $pid)), '370400440532013000') !== false]);
wp_set_current_user($owner);
[$st, $D] = pp_rest('GET', "/organizer/promoters/$pid");
pp_eq('Veranstalter sieht die IBAN voll (er überweist)', 'DE89 3704 0044 0532 0130 00', $D['promoter']['payout_details']['iban'] ?? '');
[$st, $po] = pp_rest('POST', '/organizer/promoter-payouts', ['promoter_id' => $pid]);
wp_set_current_user(0);
[$st, $P] = pp_rest('GET', '/promoter/payouts', [], $tok);
pp_eq('Auszahlungen: 4 € offen, ohne Promoter-Namen', [200, 4.0, 'pending', false], [$st, $P['payouts'][0]['commission'] ?? null, $P['payouts'][0]['status'] ?? '', isset($P['payouts'][0]['promoter_name'])]);

// ═══ Abmelden ═══
pp_rest('POST', '/promoter/auth/logout', [], $tok2);
[$st] = pp_rest('GET', '/promoter/me', [], $tok2);
pp_eq('Abmelden: dieses Token ungültig', 401, $st);
[$st] = pp_rest('GET', '/promoter/me', [], $tok);
pp_eq('… das andere Gerät bleibt angemeldet', 200, $st);
pp_rest('POST', '/promoter/auth/logout-all', [], $tok);
[$st] = pp_rest('GET', '/promoter/me', [], $tok);
pp_eq('Überall abmelden → alle Token ungültig', 401, $st);
$tokL = (string) ($d3['token'] ?? '');
[$st] = pp_rest('GET', '/promoter/me', [], $tokL);
pp_eq('… auch das über den alten Link', 401, $st);

// ═══ Selbst-Anmeldung → Freigabe ═══
[$st, $d, $c] = pp_rest('POST', '/promoter/signup', ['name' => 'Neo Neu', 'email' => "neo_$sfx@example.test"] + ($multi ? [] : []));
if ($multi) { pp_eq('Plattform: Veranstalter Pflicht', [400, 'organizer_id'], [$st, $d['data']['field'] ?? '']); [$st, $d] = pp_rest('POST', '/promoter/signup', ['name' => 'Neo Neu', 'email' => "neo_$sfx@example.test", 'organizer_id' => $org]); }
$neo = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Promoter_DB::table_promoters() . " WHERE email = %s", "neo_$sfx@example.test")); if ($neo) $proms[] = intval($neo->id);
pp_eq('Selbst-Anmeldung: wartet auf Freigabe, Mail mit Studio-Link', [200, 'pending', $org, true], [$st, $neo->status ?? '', intval($neo->organizer_id ?? -1), strpos(html_entity_decode((string) (end($GLOBALS['pp_mails'])['message'] ?? '')), 'studio.seite.test/promoter/anmelden/') !== false]);
preg_match('#letter-spacing:6px;[^>]*>(\d{6})<#', (string) (end($GLOBALS['pp_mails'])['message'] ?? ''), $nc);
[$st, $nd] = pp_rest('POST', '/promoter/auth/confirm', ['email' => "neo_$sfx@example.test", 'code' => $nc[1] ?? '']);
[$st, $nl] = pp_rest('GET', '/promoter/links', [], (string) ($nd['token'] ?? ''));
pp_eq('Wartender Promoter kann sich anmelden, sieht aber keine Links', [200, true, []], [$st, $nl['pending'] ?? null, $nl['events'] ?? null]);
$GLOBALS['pp_mails'] = [];
wp_set_current_user($owner);
[$st, $L1] = pp_rest('GET', '/organizer/promoters', ['status' => 'pending']);
[$st, $ap] = pp_rest('POST', '/organizer/promoters/' . intval($neo->id ?? 0), ['status' => 'active']);
pp_eq('Studio: Liste „wartet“, Freigabe schickt den Zugang', [true, 'active', true], [in_array(intval($neo->id ?? 0), array_column((array) ($L1['promoters'] ?? []), 'id'), true), $ap['promoter']['status'] ?? '', $ap['access_sent'] ?? false]);
wp_set_current_user(0);
$s2 = get_option('tix_settings'); $s2['promoter_self_signup'] = 0; update_option('tix_settings', $s2);
[$st, $d, $c] = pp_rest('POST', '/promoter/signup', ['name' => 'X', 'email' => "x_$sfx@example.test", 'organizer_id' => $org]);
pp_eq('Selbst-Anmeldung aus → 404', [404, 'tix_signup_off'], [$st, $c]);
[$st, $site] = pp_rest('GET', '/promoter/site');
pp_eq('site: Marke, Studio-Adresse', [200, true, 'https://studio.seite.test/promoter/'], [$st, isset($site['brand']['name']), $site['studio_url'] ?? '']);

// Aufräumen
wp_set_current_user(1);
foreach ($orders as $o) { $wpdb->delete($ot, ['id' => $o]); $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]); }
foreach (array_unique(array_filter($proms)) as $p) { foreach ([TIX_Promoter_DB::table_promoters() => 'id', TIX_Promoter_DB::table_events() => 'promoter_id', TIX_Promoter_DB::table_commissions() => 'promoter_id', TIX_Promoter_DB::table_payouts() => 'promoter_id'] as $t => $col) $wpdb->delete($t, [$col => $p]);
    TIX_Promoter_Portal::revoke_all($p); delete_option('tix_promoter_payout_' . $p); delete_option('tix_promoter_epoch_' . $p); }
update_option('tix_settings', $set_before);
if ($studio_before === null) delete_option('tix_studio_url'); else update_option('tix_studio_url', $studio_before);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
