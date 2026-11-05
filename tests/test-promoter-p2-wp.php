<?php
/** Promoter-Ausbau Paket 2 (1.38.662): Mitteilungen an Promoter und Werbematerial. wp eval-file …/tests/test-promoter-p2-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Promoter_Notify') || !class_exists('TIX_Promoter_Material')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function p2_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function p2_rest($m, $r, array $b = [], $tok = '') { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($tok) $q->set_header('X-Tix-Promoter-Token', $tok); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Promoter_DB::create_tables(); TIX_Promoter_DB::ensure_email_column(); TIX_Promoter_DB::ensure_org_column(); TIX_Promoter_DB::ensure_clicks_table(); TIX_Promoter_Portal::maybe_create();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_pm\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_app\\_rl\\_pm\\_%'");
$keep = []; foreach (['tix_settings', TIX_Promoter_Notify::OPT_Q, TIX_AI_Provider::OPT, TIX_AI_Provider::OPT_USAGE] as $o) $keep[$o] = get_option($o, null);
$s = get_option('tix_settings', []); $s['promoter_enabled'] = 1; update_option('tix_settings', $s);
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $proms = [];
$GLOBALS['p2m'] = []; add_filter('pre_wp_mail', function ($n, $a) { $GLOBALS['p2m'][] = $a; return true; }, 10, 2);
$mails = function ($to, $sub = '') { return array_values(array_filter($GLOBALS['p2m'], function ($m) use ($to, $sub) { return in_array($to, (array) $m['to'], true) && ($sub === '' || strpos((string) $m['subject'], $sub) !== false); })); };
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "p2_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "p2_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P2 Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'P2 Andere']); $posts[] = $org2;
}
$mkev = function ($t, $o, $status = 'publish') use (&$posts) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => $status, 'post_title' => "P2 $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS), '_tix_time_start' => '22:00', '_tix_location' => 'P2 Halle']]); $posts[] = $id; return $id; };
$A = "p2_a_$sfx@example.test"; $B = "p2_b_$sfx@example.test";
$pa = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org, 'email' => $A, 'promoter_code' => "p2anna$sfx", 'display_name' => 'Anna', 'status' => 'active']); $proms[] = $pa;
$pb = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org, 'email' => $B, 'promoter_code' => "p2ben$sfx", 'display_name' => 'Ben', 'status' => 'active']); $proms[] = $pb;
$issue = new ReflectionMethod('TIX_Promoter_Portal', 'issue'); $issue->setAccessible(true);
$tokA = $issue->invoke(null, $pa, 'test')[0]; $tokB = $issue->invoke(null, $pb, 'test')[0];

// ═══ 1. Neues Event zugeteilt ═══
[$st, $d] = p2_rest('POST', '/promoter/me', ['notify' => ['event' => false]], $tokB);
p2_eq('Promoter schaltet „Neue Events“ ab (POST /promoter/me notify)', [200, false, true], [$st, $d['promoter']['notify']['event'] ?? null, $d['promoter']['notify']['sale'] ?? null]);
$ev = $mkev("Party $sfx", $org, 'draft');
TIX_Promoter_DB::assign_event(['promoter_id' => $pa, 'event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 2, 'discount_type' => 'percent', 'discount_value' => 10, 'promo_code' => "P2ANNA$sfx", 'status' => 'active']);
TIX_Promoter_DB::assign_event(['promoter_id' => $pb, 'event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 2, 'status' => 'active']);
p2_eq('Zuteilung zu einem Entwurf: noch keine Mail', 0, count($mails($A, 'Neues Event')));
wp_update_post(['ID' => $ev, 'post_status' => 'publish']);
$m = $mails($A, 'Neues Event');
p2_eq('Veröffentlichen: Mail „Neues Event“ mit Link inkl. Rabatt-Code und Provision', [1, true, true], [count($m), strpos((string) ($m[0]['message'] ?? ''), "coupon=P2ANNA$sfx") !== false, strpos((string) ($m[0]['message'] ?? ''), '2,00 €') !== false]);
p2_eq('Abbestellt: keine Mail', 0, count($mails($B, 'Neues Event')));
TIX_Promoter_DB::assign_event(['promoter_id' => $pa, 'event_id' => $ev, 'commission_type' => 'fixed', 'commission_value' => 2, 'discount_type' => 'percent', 'discount_value' => 10, 'promo_code' => "P2ANNA$sfx", 'status' => 'active']);
wp_update_post(['ID' => $ev, 'post_title' => "P2 Party $sfx neu"]);
p2_eq('Erneutes Speichern bzw. Ändern: keine zweite Mail', 1, count($mails($A, 'Neues Event')));
$GLOBALS['p2m'] = [];
$ev2 = $mkev("Zweites $sfx", $org);
TIX_Promoter_DB::assign_event(['promoter_id' => $pa, 'event_id' => $ev2, 'commission_type' => 'fixed', 'commission_value' => 1, 'status' => 'active']);
p2_eq('Zuteilung zu veröffentlichtem Event: Mail (Plattform: schon beim Veröffentlichen)', 1, count($mails($A, 'Neues Event für dich: P2 Zweites')));
TIX_Promoter_Notify::save_prefs($pb, ['event' => true]);
// Veröffentlichen: Promoter mit „Alle Events“ bekommt die Mail einmal
TIX_Promoter_DB::assign_event(['promoter_id' => $pb, 'event_id' => 0, 'commission_type' => 'percent', 'commission_value' => 5, 'status' => 'active']);
$draft = $mkev("Entwurf $sfx", $org, 'draft');
p2_eq('Entwurf: noch keine Mail', 0, count($mails($B, 'Entwurf')));
wp_update_post(['ID' => $draft, 'post_status' => 'publish']);
wp_update_post(['ID' => $draft, 'post_title' => "P2 Entwurf $sfx neu"]);
p2_eq('Veröffentlichen: Mail an Promoter mit „Alle Events“, nicht bei weiterem Speichern', 1, count($mails($B, 'Neues Event für dich: P2 Entwurf')));

// ═══ 2. Verkauf (gesammelt), Auszahlung, Wochenbericht ═══
$GLOBALS['p2m'] = [];
TIX_Promoter_DB::insert_commission(['promoter_id' => $pa, 'event_id' => $ev, 'order_id' => 999990 + wp_rand(1, 9), 'tickets_qty' => 2, 'order_total' => 40, 'commission_amount' => 4]);
TIX_Promoter_DB::insert_commission(['promoter_id' => $pa, 'event_id' => $ev, 'order_id' => 999980 + wp_rand(1, 9), 'tickets_qty' => 1, 'order_total' => 20, 'commission_amount' => 2]);
p2_eq('Verkauf: gesammelt, noch keine Mail', [0, true], [count($mails($A)), (bool) wp_next_scheduled(TIX_Promoter_Notify::CRON_FLUSH)]);
TIX_Promoter_Notify::flush();
$m = $mails($A, 'über deinen Link');
p2_eq('Eine Mail für beide Verkäufe: 3 Tickets, +6,00 €, keine Käuferdaten', [1, true, false], [count($m), strpos((string) ($m[0]['subject'] ?? ''), '3 neue Tickets') !== false && strpos((string) ($m[0]['subject'] ?? ''), '6,00') !== false, strpos((string) ($m[0]['message'] ?? ''), '@example.test') !== false && strpos((string) $m[0]['message'], $A) === false && strpos((string) $m[0]['message'], 'kunde') !== false]);
$wpdb->update(TIX_Promoter_DB::table_commissions(), ['created_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS)], ['promoter_id' => $pa]);
update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() - 3 * DAY_IN_SECONDS));
$po = TIX_Promoter_DB::create_payout(['promoter_id' => $pa, 'period_from' => '2000-01-01', 'period_to' => current_time('Y-m-d')]);
p2_eq('Auszahlung vorbereitet → Mail', [true, 1], [(bool) $po, count($mails($A, 'vorbereitet'))]);
TIX_Promoter_DB::mark_payout_paid(intval($po));
p2_eq('Auszahlung überwiesen → Mail', 1, count($mails($A, 'unterwegs')));
update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS));
$wpdb->insert(TIX_Promoter_DB::table_clicks(), ['promoter_id' => $pb, 'visitor_id' => 'p2v' . $sfx, 'page_path' => '/', 'event_id' => $ev, 'referrer_host' => '', 'device_type' => 'mobile', 'click_date' => current_time('mysql')]);
$GLOBALS['p2m'] = [];
TIX_Promoter_Notify::weekly();
p2_eq('Wochenbericht: Ben (Klick in dieser Woche) bekommt einen, mit Tipp', [1, true], [count($mails($B, 'Promoter-Woche')), strpos((string) ($mails($B, 'Promoter-Woche')[0]['message'] ?? ''), 'Tipp') !== false]);
TIX_Promoter_DB::update_promoter($pb, ['status' => 'inactive']);
$GLOBALS['p2m'] = [];
TIX_Promoter_Notify::weekly();
p2_eq('Inaktiver Promoter: kein Wochenbericht', 0, count($mails($B)));
TIX_Promoter_DB::update_promoter($pb, ['status' => 'active']);
$tokB = $issue->invoke(null, $pb, 'test')[0]; // Deaktivieren meldet alle Sitzungen ab

// ═══ 3. Werbematerial ═══
$att = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'p2bild', 'post_status' => 'inherit'], ABSPATH . 'wp-admin/images/w-logo-blue.png'); $posts[] = $att;
set_post_thumbnail($ev, $att);
[$st, $d] = p2_rest('GET', '/promoter/material', ['event_id' => $ev], $tokA);
p2_eq('Material: Vorlagen mit eigenem Link + Rabatt-Code, WhatsApp-Knopf', [200, 'template', true, "P2ANNA$sfx", true],
    [$st, $d['texts_source'] ?? '', strpos((string) ($d['texts']['whatsapp'] ?? ''), "ref=p2anna$sfx") !== false, $d['coupon'] ?? '', strpos((string) ($d['share']['whatsapp'] ?? ''), 'https://wa.me/?text=') === 0]);
update_option(TIX_AI_Provider::OPT, ['provider' => 'claude', 'key_enc' => TIX_Ad_Accounts::enc('sk-ant-api03-' . str_repeat('p', 40) . 'Zz99')], false);
$GLOBALS['p2ai'] = 0;
TIX_AI_Provider::$http = function ($url, $args) {
    $GLOBALS['p2ai']++;
    $in = ['whatsapp' => 'Kommst du mit zur Party? Hier gibts Tickets: {link}', 'instagram' => "Party am Samstag 🔥\nTickets: {link} #party", 'story' => 'Wer ist dabei?', 'telegram' => 'Ballermann-Party! {link}'];
    return ['headers' => [], 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null,
        'body' => wp_json_encode(['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'tool_use', 'id' => 'x', 'name' => 'werbetexte', 'input' => $in]], 'usage' => ['input_tokens' => 500, 'output_tokens' => 200]])];
};
[$st, $d] = p2_rest('POST', '/promoter/material/texts', ['event_id' => $ev], $tokA);
p2_eq('KI-Texte: erzeugt, Link eingesetzt, geschützter Begriff durch Vorlage ersetzt', [200, 'ai', true, false, 1],
    [$st, $d['texts_source'] ?? '', strpos((string) ($d['texts']['whatsapp'] ?? ''), "ref=p2anna$sfx") !== false, stripos((string) ($d['texts']['telegram'] ?? ''), 'ballermann') !== false, $GLOBALS['p2ai']]);
[$st, $d] = p2_rest('POST', '/promoter/material/texts', ['event_id' => $ev], $tokB);
p2_eq('Zweiter Promoter: Cache (keine neue KI-Anfrage), eigener Link', [200, 1, true], [$st, $GLOBALS['p2ai'], strpos((string) ($d['texts']['whatsapp'] ?? ''), "ref=p2ben$sfx") !== false]);
TIX_AI_Provider::$http = null;
[$st, $d] = p2_rest('GET', '/promoter/material/image', ['event_id' => $ev], $tokA);
p2_eq('Bild-Proxy: Data-URL des Event-Bilds', [200, true], [$st, strpos((string) ($d['data_url'] ?? ''), 'data:image/png;base64,') === 0]);
if ($multi) {
    $evF = $mkev("Fremd $sfx", $org2);
    [$st] = p2_rest('GET', '/promoter/material', ['event_id' => $evF], $tokA);
    [$st2] = p2_rest('GET', '/promoter/material/image', ['event_id' => $evF], $tokA);
    p2_eq('Fremdes Event: kein Material, kein Bild', [404, 404], [$st, $st2]);
}
[$st] = p2_rest('GET', '/promoter/material', ['event_id' => $ev]);
p2_eq('Ohne Token → 401', 401, $st);
// Veranstalter
wp_set_current_user($multi ? $owner : $admin);
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
[$st, $d] = p2_rest('POST', "/organizer/events/$ev/promoter-material", ['data' => $png, 'name' => 'Story-Vorlage.png']);
$up = $d['uploads'][0] ?? []; if (!empty($up['id'])) $posts[] = intval($up['id']);
p2_eq('Upload des Veranstalters: 201, Bild mit Name', [201, 1, 'image/png', 'Story-Vorlage'], [$st, count((array) ($d['uploads'] ?? [])), $up['mime'] ?? '', $up['name'] ?? '']);
[$st, $d, $c] = p2_rest('POST', "/organizer/events/$ev/promoter-material", ['data' => base64_encode('<?php echo 1;'), 'name' => 'x.php']);
p2_eq('Kein erlaubter Dateityp → 415', 415, $st);
[$st, $d] = p2_rest('POST', "/organizer/events/$ev/promoter-texts", ['texts' => ['whatsapp' => 'Unser Text mit {code}']]);
p2_eq('Text ändern: {link} wird ergänzt, Quelle edited', [200, 'edited', true], [$st, $d['texts_source'] ?? '', strpos((string) ($d['texts']['whatsapp'] ?? ''), '{link}') !== false]);
[$st, $d] = p2_rest('GET', '/promoter/material', ['event_id' => $ev], $tokA);
p2_eq('Promoter sieht geänderten Text mit Rabatt-Code und Upload', [true, 1], [strpos((string) ($d['texts']['whatsapp'] ?? ''), "Unser Text mit P2ANNA$sfx") === 0, count((array) ($d['uploads'] ?? []))]);
[$st, $d] = p2_rest('DELETE', "/organizer/events/$ev/promoter-material/" . intval($up['id'] ?? 0));
p2_eq('Upload löschen', [200, 0], [$st, count((array) ($d['uploads'] ?? []))]);
if ($multi) {
    [$st] = p2_rest('GET', "/organizer/events/$evF/promoter-material");
    p2_eq('Veranstalter: fremdes Event → 404', 404, $st);
}

// Aufräumen
wp_set_current_user($admin);
foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
wp_clear_scheduled_hook(TIX_Promoter_Notify::CRON_FLUSH);
foreach (array_unique(array_filter($proms)) as $p) {
    $wpdb->delete(TIX_Promoter_DB::table_events(), ['promoter_id' => $p]); $wpdb->delete(TIX_Promoter_DB::table_commissions(), ['promoter_id' => $p]);
    $wpdb->delete(TIX_Promoter_DB::table_payouts(), ['promoter_id' => $p]); $wpdb->delete(TIX_Promoter_DB::table_clicks(), ['promoter_id' => $p]);
    $wpdb->delete(TIX_Promoter_Portal::table(), ['promoter_id' => $p]); $wpdb->delete(TIX_Promoter_DB::table_promoters(), ['id' => $p]);
}
foreach (array_unique(array_filter($posts)) as $p) { if (get_post_type($p) === 'attachment' && get_attached_file($p) && strpos((string) get_attached_file($p), 'wp-admin') !== false) { wp_delete_post($p, true); continue; } get_post_type($p) === 'attachment' ? wp_delete_attachment($p, true) : wp_delete_post($p, true); }
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
