<?php
/** Integrationstest Newsletter über Brevo (TIX_Newsletter). Brevo wird über pre_http_request nachgebildet, Mails über
 *  pre_wp_mail abgefangen – es geht nichts ins Netz. wp eval-file …/tests/test-newsletter-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Newsletter')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function nl_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function nl_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '', $res->get_headers()]; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create(); TIX_Newsletter::maybe_create();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = [];
$opt_before = ['s' => get_option(TIX_Newsletter::OPT, null), 't' => get_option('tix_marketing_trial_until', null), 'm' => get_option('tix_marketing_enabled', null), 'ts' => get_option('tix_settings', null)];
delete_option(TIX_Newsletter::OPT);
$ts = is_array($opt_before['ts']) ? $opt_before['ts'] : []; unset($ts['brevo_api_key']); update_option('tix_settings', $ts);
delete_option('tix_marketing_trial_until');
$owner = wp_insert_user(['user_login' => "nl_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "nl_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "nl_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "nl_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'NL Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner, '_tix_org_address' => "Hauptstr. 1\n50667 Köln"]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'NL Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "NL Party $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS), '_tix_ticket_categories' => [['name' => 'Regulär', 'price' => 10, 'qty' => 100]]]]); $posts[] = $ev;

// ── Nachbildung Brevo + Mail ──
$KEY = 'xkeysib-' . str_repeat('a1', 20);
$GLOBALS['nl_mock'] = ['calls' => [], 'process' => 'completed', 'imported' => [], 'blacklisted' => []];
add_filter('pre_http_request', function ($pre, $args, $url) use ($KEY) {
    if (strpos($url, 'https://api.brevo.com/v3') !== 0) return $pre;
    $path = substr($url, strlen('https://api.brevo.com/v3'));
    $m = strtoupper((string) ($args['method'] ?? 'GET'));
    $b = json_decode((string) ($args['body'] ?? ''), true) ?: [];
    $GLOBALS['nl_mock']['calls'][] = [$m, $path, $b];
    $J = function ($code, $body = []) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => 'x'], 'cookies' => [], 'filename' => null]; };
    if (($args['headers']['api-key'] ?? '') !== $KEY) return $J(401, ['message' => 'Key not found']);
    if ($path === '/account') return $J(200, ['companyName' => 'NL GmbH', 'email' => 'konto@example.test']);
    if ($path === '/senders') return $J(200, ['senders' => [['id' => 7, 'name' => 'NL Team', 'email' => 'news@example.test', 'active' => true], ['id' => 8, 'name' => 'Offen', 'email' => 'x@example.test', 'active' => false]]]);
    if ($path === '/webhooks' && $m === 'POST') return $J(201, ['id' => 55]);
    if ($path === '/contacts/folders' && $m === 'POST') return $J(201, ['id' => 3]);
    if ($path === '/contacts/lists' && $m === 'POST') return $J(201, ['id' => 901]);
    if (strpos($path, '/contacts/lists?') === 0) return $J(200, ['lists' => [['id' => 2, 'name' => 'Stammgäste', 'uniqueSubscribers' => 40], ['id' => 901, 'name' => 'Tixomat · Kampagne #1']]]);
    if ($path === '/contacts/import') { $GLOBALS['nl_mock']['imported'] = $b; return $J(202, ['processId' => 77]); }
    if ($path === '/processes/77') return $J(200, ['status' => $GLOBALS['nl_mock']['process']]);
    if ($path === '/emailCampaigns' && $m === 'POST') return $J(201, ['id' => 4242]);
    if ($path === '/emailCampaigns/4242' && $m === 'PUT') return $J(204);
    if ($path === '/emailCampaigns/4242/sendTest') return $J(204);
    if ($path === '/emailCampaigns/4242/sendNow') return $J(204);
    if (strpos($path, '/emailCampaigns/4242?statistics') === 0) return $J(200, ['statistics' => ['globalStats' => ['sent' => 2, 'delivered' => 2, 'uniqueViews' => 1, 'uniqueClicks' => 1, 'unsubscriptions' => 0, 'hardBounces' => 0, 'softBounces' => 0, 'complaints' => 0]]]);
    if (strpos($path, '/contacts?') === 0) return $J(200, ['contacts' => array_map(function ($e) { return ['email' => $e, 'emailBlacklisted' => true]; }, $GLOBALS['nl_mock']['blacklisted'])]);
    if ($m === 'DELETE') return $J(204);
    return $J(404, ['message' => 'nicht nachgebildet ' . $path]);
}, 10, 3);
$GLOBALS['nl_mails'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['nl_mails'][] = $atts; return true; }, 10, 2);
$last_link = function () { $m = end($GLOBALS['nl_mails']); preg_match('#(https?://[^"\'\s<>]+/public/nl/confirm\?token=[A-Za-z0-9%]+)#', (string) ($m['message'] ?? ''), $x); return $x[1] ?? ''; };
$token_of = function ($url) { parse_str((string) wp_parse_url(html_entity_decode($url), PHP_URL_QUERY), $q); return (string) ($q['token'] ?? ''); };

// ═══ Modul + Einstellungen ═══
wp_set_current_user($owner);
[$st] = nl_rest('POST', '/marketing/newsletter/settings', ['api_key' => $KEY]);
if (!$multi) {
    nl_eq('Ohne Modul gesperrt (Einzel-Seite)', 403, $st);
    nl_rest('POST', '/marketing/trial');
}
[$st, $d, $code] = nl_rest('POST', '/marketing/newsletter/settings', ['api_key' => 'falsch']);
nl_eq('Schlüssel ohne xkeysib- → 400', 400, $st);
[$st, $d, $code] = nl_rest('POST', '/marketing/newsletter/settings', ['api_key' => 'xkeysib-' . str_repeat('b2', 20)]);
nl_eq('Abgelehnter Schlüssel → tix_brevo_key', 'tix_brevo_key', $code);
[$st, $r] = nl_rest('POST', '/marketing/newsletter/settings', ['api_key' => $KEY]);
nl_eq('Verbunden: Konto, nur bestätigte Absender, Webhook angelegt', [200, true, 'NL GmbH', [7], true], [$st, $r['connected'] ?? null, $r['account']['company'] ?? '', array_column((array) ($r['senders'] ?? []), 'id'), $r['webhook'] ?? null]);
$hooks = array_values(array_filter($GLOBALS['nl_mock']['calls'], function ($c) { return $c[1] === '/webhooks'; })); $hook = $hooks ? $hooks[count($hooks) - 1] : [];
nl_eq('Webhook: unsubscribed, hardBounce, spam an /public/nl/hook/<geheim>', [['unsubscribed', 'hardBounce', 'spam'], true], [$hook[2]['events'] ?? null, (bool) preg_match('#/public/nl/hook/[A-Za-z0-9]{32}$#', (string) ($hook[2]['url'] ?? ''))]);
$stored = $multi ? get_post_meta($org, TIX_Newsletter::META, true) : get_option(TIX_Newsletter::OPT);
nl_eq('Schlüssel verschlüsselt gespeichert', [false, $KEY], [strpos(wp_json_encode($stored), 'xkeysib') !== false, TIX_Newsletter::settings($org)['api_key']]);
[$st, $d, $code] = nl_rest('POST', '/marketing/newsletter/settings', ['sender_id' => 8]);
nl_eq('Unbestätigter Absender → 400', 400, $st);
[$st, $r] = nl_rest('POST', '/marketing/newsletter/settings', ['sender_id' => 7]);
nl_eq('Absender gewählt, Impressum-Adresse vorbelegt', ['news@example.test', true], [$r['sender']['email'] ?? '', ($r['address'] ?? '') !== '']);

// ═══ Anmeldung mit Double-Opt-in ═══
wp_set_current_user(0);
[$st, $r] = nl_rest('GET', '/public/nl/consent', ['organizer_id' => $org]);
nl_eq('Einwilligungstext nennt den Absender, Newsletter verfügbar', [true, true], [$r['enabled'] ?? null, strpos((string) ($r['text'] ?? ''), TIX_Newsletter::owner_name($org)) !== false]);
[$st, $d, $code] = nl_rest('POST', '/public/nl/subscribe', ['email' => "gast_$sfx@example.test", 'organizer_id' => $org]);
nl_eq('Ohne Einwilligung → 400', [400, 'tix_consent_required'], [$st, $code]);
[$st] = nl_rest('POST', '/public/nl/subscribe', ['email' => "gast_$sfx@example.test", 'first_name' => 'Gina', 'organizer_id' => $org, 'consent' => true]);
$c1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tc() . " WHERE email = %s", "gast_$sfx@example.test"));
$link = $last_link();
nl_eq('Anmeldung: pending, Einwilligung gespeichert, Bestätigungsmail', [200, 'pending', true, true], [$st, $c1->status ?? '', strpos((string) ($c1->consent_text ?? ''), 'Abmeldelink') !== false, $link !== '']);
[$st, $d, $code, $hd] = nl_rest('GET', '/public/nl/confirm', ['token' => $token_of($link)]);
$c1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tc() . " WHERE id = %d", $c1->id));
nl_eq('Bestätigen: 302 auf ?newsletter=bestaetigt, aktiv, neuer Schlüssel', [302, true, 'active', true], [$st, strpos((string) ($hd['Location'] ?? ''), 'newsletter=bestaetigt') !== false, $c1->status, $c1->token !== $token_of($link)]);
[$st, $d, $code, $hd] = nl_rest('GET', '/public/nl/confirm', ['token' => $token_of($link)]);
nl_eq('Alter Bestätigungslink ist ungültig', true, strpos((string) ($hd['Location'] ?? ''), 'newsletter=ungueltig') !== false);
// Kasse: Häkchen → pending je Veranstalter; Bestellung für die Event-Zielgruppe
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
$mk_order = function ($email, array $attr = []) use ($wpdb, $ot, $oi, $ev, &$orders) {
    $wpdb->insert($ot, ['order_number' => 'NL' . wp_rand(), 'status' => 'completed', 'order_key' => wp_generate_password(12, false), 'total' => 20, 'subtotal' => 20, 'payment_method' => 'mollie', 'billing_email' => $email, 'date_created' => current_time('mysql')]);
    $id = intval($wpdb->insert_id); $orders[] = $id;
    $wpdb->insert($oi, ['order_id' => $id, 'event_id' => $ev, 'quantity' => 2, 'total' => 20, 'name' => 'NL – Regulär', 'cat_name' => 'Regulär']);
    if ($attr) TIX_Order_Attribution::set($id, $attr + ['channel' => 'web']);
    return $id;
};
$oK = $mk_order("kaeufer_$sfx@example.test");
TIX_Newsletter::from_checkout($oK, "kaeufer_$sfx@example.test", 'Kai', [$ev]);
$ck = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tc() . " WHERE email = %s", "kaeufer_$sfx@example.test"));
nl_eq('Kasse: Kontakt pending beim Veranstalter des Events, Quelle checkout', ['pending', intval($org), 'checkout'], [$ck->status ?? '', intval($ck->organizer_id ?? -1), $ck->source ?? '']);
$mk_order("gast_$sfx@example.test");
wp_set_current_user($owner);
[$st, $a1] = nl_rest('GET', '/marketing/newsletter/audience', ['audience' => 'contacts']);
[$st, $a2] = nl_rest('GET', '/marketing/newsletter/audience', ['audience' => 'event', 'event_id' => $ev]);
nl_eq('Zielgruppen: nur bestätigte (1), Käufer des Events mit Anmeldung (1)', [1, 1], [$a1['count'] ?? null, $a2['count'] ?? null]);
nl_rest('GET', '/public/nl/confirm', ['token' => $ck->token]);
[$st, $a2] = nl_rest('GET', '/marketing/newsletter/audience', ['audience' => 'event', 'event_id' => $ev]);
nl_eq('Nach Bestätigung des Käufers: 2', 2, $a2['count'] ?? null);
[$st, $bl] = nl_rest('GET', '/marketing/newsletter/audience', ['audience' => 'brevo_list']);
nl_eq('Brevo-Listen ohne eigene Tixomat-Listen', [[2]], [array_column((array) ($bl['lists'] ?? []), 'id')]);

// ═══ Kampagne ═══
$site = rtrim((class_exists('TIX_Customer_URL') ? TIX_Customer_URL::base() : '') ?: home_url(), '/');
[$st, $r] = nl_rest('POST', '/marketing/newsletter/campaigns', ['subject' => 'Nur noch 20 Tickets', 'preheader' => 'Schnell sein', 'content' => '<p>Hallo! <a href="' . $site . '/events/nl-party/">Tickets</a> · <a href="/events/">Alle</a> · <a href="https://example.com/x">Partner</a></p><script>x</script>', 'audience' => 'event', 'event_id' => $ev]);
$C = $r['campaign'] ?? [];
nl_eq('Kampagne angelegt: Entwurf, Name aus Betreff', [200, 'draft', 'Nur noch 20 Tickets', 'nl-' . ($C['id'] ?? 0)], [$st, $C['status'] ?? '', $C['name'] ?? '', $C['utm_campaign'] ?? '']);
[$st, $p] = nl_rest('GET', '/marketing/newsletter/campaigns/' . $C['id'] . '/preview');
$html = (string) ($p['html'] ?? '');
nl_eq('Vorschau: eigene Links mit UTM + c=, fremde ohne, kein Script, Abmeldelink und Adresse', [true, true, false, true, true], [
    substr_count($html, 'utm_campaign=nl-' . $C['id']) === 2 && strpos($html, 'c=nl-' . $C['id']) !== false && strpos($html, 'href="' . $site . '/events/?') !== false,
    strpos($html, 'https://example.com/x"') !== false, strpos($html, '<script') !== false, strpos($html, 'Abmelden') !== false,
    strpos($html, $multi ? 'Hauptstr. 1' : (string) TIX_Newsletter::owner_name(0)) !== false]);
[$st, $d, $code] = nl_rest('POST', '/marketing/newsletter/campaigns', ['audience' => 'brevo_list', 'list_id' => 2]);
nl_eq('Brevo-Liste nur mit Bestätigung der Einwilligung', 400, $st);
[$st, $t] = nl_rest('POST', '/marketing/newsletter/campaigns/' . $C['id'] . '/test', ['emails' => ['ich@example.test']]);
$created = array_values(array_filter($GLOBALS['nl_mock']['calls'], function ($c) { return $c[0] === 'POST' && $c[1] === '/emailCampaigns'; }));
nl_eq('Testversand: Brevo-Kampagne mit Absender und Abmelde-Platzhalter, Test an die Adresse', [200, ['ich@example.test'], 7, true], [$st, $t['sent_to'] ?? null, $created[0][2]['sender']['id'] ?? null, strpos((string) ($created[0][2]['htmlContent'] ?? ''), '{{ unsubscribe }}') !== false]);
[$st, $d, $code] = nl_rest('POST', '/marketing/newsletter/campaigns/' . $C['id'] . '/send', ['at' => gmdate('c', time() + 60)]);
nl_eq('Planen zu früh (unter 10 min) → 400', 400, $st);
[$st, $s] = nl_rest('POST', '/marketing/newsletter/campaigns/' . $C['id'] . '/send');
$imp = $GLOBALS['nl_mock']['imported'];
$sendnow = array_filter($GLOBALS['nl_mock']['calls'], function ($c) { return $c[1] === '/emailCampaigns/4242/sendNow'; });
nl_eq('Jetzt senden: Liste + Import der 2 Empfänger, Kampagne mit Liste, sendNow, Status versendet', [200, 2, [901], 1, 'sent', 2], [$st, count((array) ($imp['jsonBody'] ?? [])), $imp['listIds'] ?? null, count($sendnow), $s['campaign']['status'] ?? '', $s['campaign']['recipients'] ?? null]);
[$st, $d, $code] = nl_rest('POST', '/marketing/newsletter/campaigns/' . $C['id'], ['subject' => 'Neu']);
nl_eq('Versendet: nicht mehr änderbar', [409, 'tix_nl_locked'], [$st, $code]);
$mk_order("gast_$sfx@example.test", ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'nl-' . $C['id']]);
$mk_order("kaeufer_$sfx@example.test", ['source' => 'newsletter', 'link_code' => 'nl-' . $C['id']]);
[$st, $g] = nl_rest('GET', '/marketing/newsletter/campaigns/' . $C['id']);
nl_eq('Auswertung: Öffnungen/Klicks von Brevo, 2 Käufe über UTM bzw. c=, 40 €', [1, 1, 50.0, 2, 40.0], [$g['campaign']['stats']['opens'] ?? null, $g['campaign']['stats']['clicks'] ?? null, floatval($g['campaign']['stats']['open_rate'] ?? 0), $g['campaign']['orders'] ?? null, $g['campaign']['revenue'] ?? null]);
// Planen + zurückholen
[$st, $r] = nl_rest('POST', '/marketing/newsletter/campaigns', ['subject' => 'Später', 'content' => 'Text', 'audience' => 'contacts']);
$C2 = $r['campaign'];
[$st, $s2] = nl_rest('POST', '/marketing/newsletter/campaigns/' . $C2['id'] . '/send', ['at' => gmdate('c', time() + 3 * DAY_IN_SECONDS)]);
nl_eq('Geplant in 3 Tagen: Status geplant, noch nichts an Brevo', ['scheduled', 0], [$s2['campaign']['status'] ?? '', intval($wpdb->get_var($wpdb->prepare("SELECT brevo_list FROM " . TIX_Newsletter::tk() . " WHERE id = %d", $C2['id'])))]);
[$st, $s3] = nl_rest('POST', '/marketing/newsletter/campaigns/' . $C2['id'] . '/cancel');
nl_eq('Zurückholen → Entwurf', 'draft', $s3['campaign']['status'] ?? '');

// ═══ Trennung der Veranstalter ═══
if ($multi) {
    wp_set_current_user($other);
    [$st, $lo] = nl_rest('GET', '/marketing/newsletter/campaigns');
    [$st2] = nl_rest('GET', '/marketing/newsletter/campaigns/' . $C['id']);
    [$st3, $co] = nl_rest('GET', '/marketing/newsletter/contacts');
    nl_eq('Anderer Veranstalter: keine Kampagnen, 404, keine Kontakte', [0, 404, 0], [count((array) ($lo['campaigns'] ?? [])), $st2, intval($co['total'] ?? -1)]);
    wp_set_current_user($owner);
}
[$st, $co] = nl_rest('GET', '/marketing/newsletter/contacts', ['status' => 'active']);
nl_eq('Kontaktliste: 2 aktive', 2, intval($co['total'] ?? 0));

// ═══ Abmeldung: Link, Webhook, Abgleich ═══
wp_set_current_user(0);
$g1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tc() . " WHERE email = %s", "gast_$sfx@example.test"));
[$st, $d, $code, $hd] = nl_rest('GET', '/public/nl/unsubscribe', ['token' => $g1->token]);
nl_eq('Abmeldelink: abgemeldet', [302, 'unsubscribed', 'link'], [$st, $wpdb->get_var($wpdb->prepare("SELECT status FROM " . TIX_Newsletter::tc() . " WHERE id = %d", $g1->id)), $wpdb->get_var($wpdb->prepare("SELECT unsub_reason FROM " . TIX_Newsletter::tc() . " WHERE id = %d", $g1->id))]);
$secret = TIX_Newsletter::settings($org)['hook_secret'];
[$st] = nl_rest('POST', '/public/nl/hook/' . str_repeat('x', 32), ['event' => 'unsubscribe', 'email' => "kaeufer_$sfx@example.test"]);
nl_eq('Webhook mit falschem Geheimnis → 403', 403, $st);
[$st, $hk] = nl_rest('POST', '/public/nl/hook/' . $secret, ['event' => 'spam', 'email' => "kaeufer_$sfx@example.test"]);
nl_eq('Webhook Spam-Beschwerde → abgemeldet', [200, 1, 'spam'], [$st, $hk['handled'] ?? 0, $wpdb->get_var($wpdb->prepare("SELECT unsub_reason FROM " . TIX_Newsletter::tc() . " WHERE email = %s", "kaeufer_$sfx@example.test"))]);
// Abgleich + Aufräumen
TIX_Newsletter::subscribe($org, "abgleich_$sfx@example.test", '', 'form');
$wpdb->query($wpdb->prepare("UPDATE " . TIX_Newsletter::tc() . " SET status = 'active' WHERE email = %s", "abgleich_$sfx@example.test"));
TIX_Newsletter::subscribe($org, "alt_$sfx@example.test", '', 'form');
$wpdb->query($wpdb->prepare("UPDATE " . TIX_Newsletter::tc() . " SET consent_at = %s WHERE email = %s", gmdate('Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS), "alt_$sfx@example.test"));
$wpdb->query($wpdb->prepare("UPDATE " . TIX_Newsletter::tc() . " SET unsubscribed_at = %s WHERE email = %s", gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS), "gast_$sfx@example.test"));
$GLOBALS['nl_mock']['blacklisted'] = ["abgleich_$sfx@example.test"];
TIX_Newsletter::daily();
nl_eq('Täglich: Brevo-Sperrliste übernommen, Unbestätigte nach 7 Tagen weg, alte Abmeldung nur noch als Hash', ['unsubscribed', null, null, 1], [
    $wpdb->get_var($wpdb->prepare("SELECT status FROM " . TIX_Newsletter::tc() . " WHERE email = %s", "abgleich_$sfx@example.test")),
    $wpdb->get_var($wpdb->prepare("SELECT id FROM " . TIX_Newsletter::tc() . " WHERE email = %s", "alt_$sfx@example.test")),
    $wpdb->get_var($wpdb->prepare("SELECT id FROM " . TIX_Newsletter::tc() . " WHERE email = %s", "gast_$sfx@example.test")),
    intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Newsletter::tc() . " WHERE id = %d AND status = 'blocked' AND first_name = ''", $g1->id)))]);
wp_set_current_user($owner);
[$st, $d, $code] = nl_rest('POST', '/marketing/newsletter/campaigns/' . $C2['id'] . '/send');
nl_eq('Ohne bestätigte Empfänger kein Versand', [409, 'tix_nl_empty'], [$st, $code]);
$staff = wp_insert_user(['user_login' => "nl_staff_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "nl_staff_$sfx@example.test", 'role' => 'tix_staff']); $users[] = $staff;
if ($multi) update_user_meta($staff, '_tix_team_organizer_id', $org);
wp_set_current_user($staff);
[$st] = nl_rest('GET', '/marketing/newsletter/campaigns');
nl_eq('Mitarbeiter → 403', 403, $st);

// Aufräumen
wp_set_current_user(1);
foreach ($orders as $o) { $wpdb->delete($ot, ['id' => $o]); $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]); }
TIX_Newsletter::purge_organizer($org);
if ($multi) TIX_Newsletter::purge_organizer($org2);
foreach ($opt_before as $k => $v) { $name = ['s' => TIX_Newsletter::OPT, 't' => 'tix_marketing_trial_until', 'm' => 'tix_marketing_enabled', 'ts' => 'tix_settings'][$k]; if ($v === null) delete_option($name); else update_option($name, $v); }
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
