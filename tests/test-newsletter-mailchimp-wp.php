<?php
/**
 * Mailchimp als zweiter Newsletter-Anbieter (1.38.807): Anbieter-Wahl, Schlüssel nur schreiben, Kampagne (Test, Versand über
 * Segment, Kennzahlen, Zurückholen), Webhook (Prüfung per GET, Abmeldung), Listen-Abgleich je Event/Ticketart mit Tags.
 * Die Mailchimp-API wird nachgebildet (pre_http_request), es geht nichts nach außen.
 *   wp eval-file …/test-newsletter-mailchimp-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Newsletter_Mailchimp')) { echo "Tixomat ab 1.38.807 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = [], $form = false) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { if ($form) $x->set_body_params($b); else { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
TIX_Newsletter::maybe_create();
$before = ['s' => get_option(TIX_Newsletter::OPT, null), 't' => get_option('tix_marketing_trial_until', null), 'm' => get_option('tix_marketing_enabled', null)];
$KEY = str_repeat('ab12', 8) . '-us21';
// ── nachgebildete Mailchimp-API ──
$mc = ['members' => [], 'segments' => [], 'seg' => 100, 'campaigns' => [], 'tests' => [], 'webhooks' => [], 'calls' => []];
$fake = function ($pre, $args, $url) use (&$mc, $KEY) {
    if (strpos($url, '.api.mailchimp.com/3.0') === false) return $pre;
    $auth = (string) ($args['headers']['Authorization'] ?? '');
    $m = strtoupper($args['method'] ?? 'GET'); $path = (string) wp_parse_url($url, PHP_URL_PATH); $path = substr($path, strlen('/3.0'));
    $b = isset($args['body']) ? json_decode((string) $args['body'], true) : null;
    $mc['calls'][] = "$m $path";
    $res = function ($code, $body = []) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null]; };
    if ($auth !== 'Basic ' . base64_encode('evendis:' . $KEY)) return $res(401, ['title' => 'API Key Invalid', 'detail' => 'Your API key may be invalid.']);
    if ($path === '/ping') return $res(200, ['health_status' => 'ok']);
    if ($path === '/') return $res(200, ['account_name' => 'Club Test', 'email' => 'owner@club.test']);
    if ($path === '/lists' && $m === 'GET') return $res(200, ['lists' => [['id' => 'aud1', 'name' => 'Club-Newsletter', 'stats' => ['member_count' => 3]]]]);
    if (preg_match('#^/lists/([^/]+)$#', $path, $x)) {
        if ($x[1] !== 'aud1') return $res(404, ['title' => 'Resource Not Found']);
        if ($m === 'GET') return $res(200, ['id' => 'aud1', 'name' => 'Club-Newsletter']);
        foreach ((array) ($b['members'] ?? []) as $mem) { $h = md5($mem['email_address']); if (!isset($mc['members'][$h])) $mc['members'][$h] = ['email' => $mem['email_address'], 'status' => $mem['status_if_new'], 'tags' => [], 'fname' => $mem['merge_fields']['FNAME'] ?? '']; }
        return $res(200, ['new_members' => [], 'errors' => []]);
    }
    if (preg_match('#^/lists/aud1/webhooks(?:/(.+))?$#', $path, $x)) { if ($m === 'POST') { $mc['webhooks'][] = $b['url']; return $res(200, ['id' => 'wh' . count($mc['webhooks'])]); } return $res(204); }
    if ($path === '/lists/aud1/segments' && $m === 'POST') { $id = ++$mc['seg']; $mc['segments'][$id] = []; return $res(200, ['id' => $id]); }
    if (preg_match('#^/lists/aud1/segments/(\d+)$#', $path, $x)) { if ($m === 'DELETE') { unset($mc['segments'][$x[1]]); return $res(204); } $mc['segments'][$x[1]] = array_merge($mc['segments'][$x[1]] ?? [], (array) $b['members_to_add']); return $res(200, ['total_added' => count((array) $b['members_to_add'])]); }
    if (preg_match('#^/lists/aud1/members/([a-f0-9]{32})/tags$#', $path, $x)) { foreach ((array) $b['tags'] as $t) $mc['members'][$x[1]]['tags'][] = $t['name']; return $res(204); }
    if (preg_match('#^/lists/aud1/members/([a-f0-9]{32})$#', $path, $x)) { if (!isset($mc['members'][$x[1]])) $mc['members'][$x[1]] = ['email' => $b['email_address'], 'status' => $b['status_if_new'], 'tags' => [], 'fname' => $b['merge_fields']['FNAME'] ?? '']; return $res(200, ['id' => $x[1]]); }
    if ($path === '/lists/aud1/members') return $res(200, ['members' => []]);
    if ($path === '/campaigns' && $m === 'POST') { $id = 'c' . (count($mc['campaigns']) + 1); $mc['campaigns'][$id] = $b + ['html' => '', 'sent' => false]; return $res(200, ['id' => $id]); }
    if (preg_match('#^/campaigns/([a-z0-9]+)(/content|/actions/test|/actions/send)?$#', $path, $x)) {
        $id = $x[1]; if (!isset($mc['campaigns'][$id])) return $res(404, ['title' => 'Resource Not Found']);
        $sfx = $x[2] ?? '';
        if ($sfx === '/content') { $mc['campaigns'][$id]['html'] = $b['html']; return $res(200, []); }
        if ($sfx === '/actions/test') { $mc['tests'][] = $b['test_emails']; return $res(204); }
        if ($sfx === '/actions/send') { $mc['campaigns'][$id]['sent'] = true; return $res(204); }
        if ($m === 'PATCH') { $mc['campaigns'][$id] = array_merge($mc['campaigns'][$id], $b); return $res(200, ['id' => $id]); }
        if ($m === 'DELETE') { unset($mc['campaigns'][$id]); return $res(204); }
    }
    if (preg_match('#^/reports/([a-z0-9]+)$#', $path, $x)) return $res(200, ['emails_sent' => 10, 'bounces' => ['hard_bounces' => 1, 'soft_bounces' => 0, 'syntax_errors' => 0], 'opens' => ['unique_opens' => 6], 'clicks' => ['unique_subscriber_clicks' => 3], 'unsubscribed' => 1, 'abuse_reports' => 0]);
    return $res(404, ['title' => 'unbekannt ' . $path]);
};
add_filter('pre_http_request', $fake, 10, 3);
$cids = []; $contacts = []; $posts = []; $users = []; $items = [];
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    if (!TIX_Ad_Accounts::module_on(0)) { delete_option('tix_marketing_trial_until'); $rest('POST', '/marketing/trial'); }
    delete_option(TIX_Newsletter::OPT);

    [$st, $d] = $rest('GET', '/marketing/newsletter', null, ['check' => '0']);
    $eq('Status: Anbieter brevo, Mailchimp wählbar, nicht verbunden', [200, 'brevo', ['brevo', 'mailchimp'], false], [$st, $d['provider'] ?? '', $d['providers'] ?? null, $d['mailchimp']['connected'] ?? null]);
    [$st, $d] = $rest('POST', '/marketing/newsletter/settings', ['mc_api_key' => 'falsch']);
    $eq('Schlüssel im falschen Format → 400', [400, 'mc_api_key'], [$st, $d['data']['field'] ?? '']);
    [$st, $d] = $rest('POST', '/marketing/newsletter/settings', ['mc_api_key' => str_repeat('ff', 16) . '-us21']);
    $eq('Schlüssel, den Mailchimp nicht annimmt → 400', [400, 'tix_mc_key'], [$st, $d['code'] ?? '']);
    [$st, $d] = $rest('POST', '/marketing/newsletter/settings', ['provider' => 'mailchimp', 'mc_api_key' => $KEY, 'mc_audience_id' => 'aud1', 'mc_from_name' => 'Club Test', 'mc_reply_to' => 'info@club.test']);
    $eq('Mailchimp verbinden: Zielgruppe, Absender, Webhook, versandbereit', [200, 'mailchimp', 'Club-Newsletter', true, true, 'Club Test'],
        [$st, $d['provider'] ?? '', $d['mailchimp']['audience']['name'] ?? '', $d['mailchimp']['webhook'] ?? null, $d['mailchimp']['ready'] ?? null, $d['mailchimp']['account']['name'] ?? '']);
    $eq('Schlüssel kommt nie zurück', false, strpos(wp_json_encode($d), $KEY) !== false);
    $eq('Schlüssel verschlüsselt gespeichert', false, strpos(wp_json_encode(get_option(TIX_Newsletter::OPT)), $KEY) !== false);
    [$st, $d] = $rest('POST', '/marketing/newsletter/settings', ['mc_audience_id' => 'gibtsnicht']);
    $eq('Unbekannte Zielgruppe → 400', [400, 'mc_audience_id'], [$st, $d['data']['field'] ?? '']);
    $secret = TIX_Newsletter::settings(0)['hook_secret'];
    $eq('Webhook-Adresse mit Geheimnis bei Mailchimp, Prüfung per GET → 200', [true, 200], [strpos((string) end($mc['webhooks']), $secret) !== false, $rest('GET', '/public/nl/hook/' . $secret)[0]]);

    // Kontakte (bestätigt)
    foreach ([['mc-a@example.test', 'Ana'], ['mc-b@example.test', '']] as [$em, $fn]) {
        $wpdb->delete(TIX_Newsletter::tc(), ['organizer_id' => 0, 'email' => $em]);
        $wpdb->insert(TIX_Newsletter::tc(), ['organizer_id' => 0, 'email' => $em, 'first_name' => $fn, 'status' => 'active', 'source' => 'form', 'token' => wp_generate_password(40, false, false), 'consent_text' => 'x', 'consent_at' => current_time('mysql', true), 'confirmed_at' => current_time('mysql', true), 'created' => current_time('mysql', true)]);
        $contacts[] = $wpdb->insert_id;
    }
    $all = count(TIX_Newsletter::recipients(0, 'contacts'));

    // Kampagne
    [$st, $d] = $rest('POST', '/marketing/newsletter/campaigns', ['subject' => 'MC Test', 'content' => '<p>Hallo {{ contact.FIRSTNAME | default: "du" }}</p>']);
    $cid = intval($d['campaign']['id'] ?? 0); if ($cid) $cids[] = $cid;
    [$st, $d] = $rest('POST', "/marketing/newsletter/campaigns/$cid", ['audience' => 'brevo_list', 'list_ids' => [5], 'consent_confirmed' => true]);
    $eq('Anbieter-Listen mit Mailchimp → 400', 400, $st);
    [$st, $d] = $rest('POST', "/marketing/newsletter/campaigns/$cid/test", ['emails' => ['test@club.test']]);
    $html = (string) (reset($mc['campaigns'])['html'] ?? '');
    $eq('Testversand: Abmeldelink und Vorname als Mailchimp-Tags', [200, [['test@club.test']], true, true, false],
        [$st, $mc['tests'], strpos($html, '*|UNSUB|*') !== false, strpos($html, '*|FNAME|*') !== false, strpos($html, '{{ unsubscribe }}') !== false]);
    [$st, $d] = $rest('POST', "/marketing/newsletter/campaigns/$cid/send");
    $c = $rest('GET', "/marketing/newsletter/campaigns/$cid")[1]['campaign'] ?? [];
    $camp = $mc['campaigns']['c1'] ?? [];
    $seg = intval($camp['recipients']['segment_opts']['saved_segment_id'] ?? 0);
    $eq('Versand: über Segment mit allen Empfängern, gesendet', [200, 'sent', true, $all, 'aud1'],
        [$st, $c['status'] ?? '', !empty($camp['sent']), count($mc['segments'][$seg] ?? []), $camp['recipients']['list_id'] ?? '']);
    $eq('Neue Kontakte in der Zielgruppe als subscribed mit Vorname', ['subscribed', 'Ana'], [$mc['members'][md5('mc-a@example.test')]['status'] ?? '', $mc['members'][md5('mc-a@example.test')]['fname'] ?? '']);
    $eq('Kennzahlen aus dem Mailchimp-Bericht', [10, 9, 6, 3, 66.7], [$c['stats']['sent'] ?? 0, $c['stats']['delivered'] ?? 0, $c['stats']['opens'] ?? 0, $c['stats']['clicks'] ?? 0, $c['stats']['open_rate'] ?? 0]);

    // Webhook-Abmeldung (Formular wie Mailchimp)
    [$st, $d] = $rest('POST', '/public/nl/hook/' . $secret, ['type' => 'unsubscribe', 'data' => ['email' => 'mc-b@example.test', 'list_id' => 'aud1']], [], true);
    $row = $wpdb->get_row($wpdb->prepare("SELECT status, unsub_reason FROM " . TIX_Newsletter::tc() . " WHERE id = %d", $contacts[1]));
    $eq('Webhook: Abmeldung aus Mailchimp', [200, 1, 'unsubscribed', 'mailchimp'], [$st, $d['handled'] ?? 0, $row->status ?? '', $row->unsub_reason ?? '']);

    // Geplant → Anbieterwechsel gesperrt → zurückholen
    [, $d] = $rest('POST', '/marketing/newsletter/campaigns', ['subject' => 'MC Später', 'content' => '<p>x</p>']);
    $cid2 = intval($d['campaign']['id'] ?? 0); if ($cid2) $cids[] = $cid2;
    [$st] = $rest('POST', "/marketing/newsletter/campaigns/$cid2/send", ['at' => gmdate('c', time() + DAY_IN_SECONDS)]);
    [$st2, $d2] = $rest('POST', '/marketing/newsletter/settings', ['provider' => 'brevo']);
    $eq('Geplante Kampagne: Anbieterwechsel → 409', [200, 409, 'tix_nl_busy'], [$st, $st2, $d2['code'] ?? '']);
    [$st, $d] = $rest('POST', "/marketing/newsletter/campaigns/$cid2/cancel");
    $eq('Zurückholen → Entwurf', [200, 'draft'], [$st, $d['campaign']['status'] ?? '']);

    // Listen-Abgleich
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'MC Event']); $posts[] = $ev;
    [$st, $d] = $rest('GET', '/marketing/newsletter/sync');
    $eq('Abgleich: Mailchimp-Bereich', [200, 'mailchimp', 'aud1', false], [$st, $d['provider'] ?? '', $d['list_id'] ?? '', $d['enabled'] ?? null]);
    [$st, $d] = $rest('POST', '/marketing/newsletter/sync', ['mappings' => [['event_id' => $ev, 'cat_name' => 'VIP']]]);
    $eq('Regel ohne Tag → 400', [400, 'mappings.0.tag'], [$st, $d['data']['field'] ?? '']);
    [$st, $d] = $rest('POST', '/marketing/newsletter/sync', ['enabled' => true, 'mappings' => [['event_id' => $ev, 'cat_name' => 'VIP', 'tag' => 'VIP-Gast']], 'default_tag' => 'Kasse', 'timing' => 'immediate']);
    $eq('Abgleich speichern', [200, true, 'VIP-Gast', 'MC Event', 'Kasse'], [$st, $d['active'] ?? null, $d['mappings'][0]['tag'] ?? '', $d['mappings'][0]['event_title'] ?? '', $d['default_tag'] ?? '']);
    $oid = 990000000 + wp_rand(1, 99999);
    $wpdb->insert(TIX_Order::items_table_name(), ['order_id' => $oid, 'event_id' => $ev, 'quantity' => 1, 'name' => 'VIP', 'cat_name' => 'VIP']); $items[] = $wpdb->insert_id;
    if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
        // evendis: Bereich = Veranstalter des Events; für den Test den Seiten-Bereich nutzen (Event ohne Veranstalter)
        echo "skip  Kauf-Häkchen je Veranstalter (Plattform: Bereich je Veranstalter, hier Seite)\n";
        TIX_Newsletter_Mailchimp::on_confirmed(0, 'mc-kauf@example.test', 'Kim', $oid); // nach Bestätigung: Zeitpunkt passt nicht → nichts
        $eq('Zeitpunkt „sofort“: Bestätigung überträgt nicht', false, isset($mc['members'][md5('mc-kauf@example.test')]));
    } else {
        do_action('tix_newsletter_optin', $oid, 'mc-kauf@example.test', 'Kim', 'Test');
        $eq('Kauf-Häkchen: Kontakt mit Tags Kasse + VIP-Gast', ['Kasse', 'VIP-Gast'], $mc['members'][md5('mc-kauf@example.test')]['tags'] ?? null);
    }
    $rest('POST', '/marketing/newsletter/sync', ['timing' => 'after_confirm']);
    do_action('tix_newsletter_optin', $oid, 'mc-kauf2@example.test', 'Kim', 'Test');
    $eq('Nach Bestätigung: Kauf-Häkchen überträgt nichts', false, isset($mc['members'][md5('mc-kauf2@example.test')]));
    do_action('tix_nl_confirmed', 0, 'mc-kauf2@example.test', 'Kim', $oid);
    $eq('Bestätigung überträgt mit Tags', ['Kasse', 'VIP-Gast'], $mc['members'][md5('mc-kauf2@example.test')]['tags'] ?? null);

    // Rechte
    $staff = wp_insert_user(['user_login' => 'mcs' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'mcs' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_staff']); $users[] = $staff;
    wp_set_current_user($staff);
    $eq('Mitarbeiter: Einstellungen verboten', true, in_array($rest('POST', '/marketing/newsletter/settings', ['provider' => 'brevo'])[0], [401, 403], true));
    wp_set_current_user($adm);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_filter('pre_http_request', $fake, 10);
    foreach ($cids as $c) { $wpdb->delete(TIX_Newsletter::tk(), ['id' => $c]); delete_transient('tix_nl_mc_rcp_' . $c); }
    foreach ($contacts as $c) $wpdb->delete(TIX_Newsletter::tc(), ['id' => $c]);
    $wpdb->query("DELETE FROM " . TIX_Newsletter::tc() . " WHERE email LIKE 'mc-%@example.test'");
    foreach ($items as $i) $wpdb->delete(TIX_Order::items_table_name(), ['id' => $i]);
    foreach ($posts as $p) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    foreach (['s' => TIX_Newsletter::OPT, 't' => 'tix_marketing_trial_until', 'm' => 'tix_marketing_enabled'] as $k => $o) { if ($before[$k] === null) delete_option($o); else update_option($o, $before[$k]); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
