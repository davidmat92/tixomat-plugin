<?php
/** Integrationstest Newsletter an Brevo-Listen (§105, 1.38.692). Brevo wird über pre_http_request zustandsbehaftet nachgebildet
 *  (Listen mit Blättern, Ordner, Kontakte je Liste, Import, Kampagne) – es geht nichts ins Netz.
 *  wp eval-file …/tests/test-newsletter-lists-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Newsletter')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function nll_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function nll_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '', is_array($d) ? ($d['data'] ?? []) : []]; }
global $wpdb;
TIX_Newsletter::maybe_create();
$cols = $wpdb->get_col("SHOW COLUMNS FROM " . TIX_Newsletter::tk());
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
nll_eq('DB 3: Spalten brevo_lists_src, audience_meta, brevo_excl', [true, true, true], [in_array('brevo_lists_src', $cols, true), in_array('audience_meta', $cols, true), in_array('brevo_excl', $cols, true)]);
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$opt_before = ['s' => get_option(TIX_Newsletter::OPT, null), 't' => get_option('tix_marketing_trial_until', null), 'm' => get_option('tix_marketing_enabled', null), 'ts' => get_option('tix_settings', null)];
delete_option(TIX_Newsletter::OPT);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_nl\\_%'");
wp_cache_flush();
$ts = is_array($opt_before['ts']) ? $opt_before['ts'] : []; unset($ts['brevo_api_key']); update_option('tix_settings', $ts);
delete_option('tix_marketing_trial_until');
$owner = wp_insert_user(['user_login' => "nll_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "nll_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
$org = 0;
if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'NLL Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner, '_tix_org_address' => "Hauptstr. 1\n50667 Köln"]]); $posts[] = $org; }
TIX_Newsletter::purge_organizer($org);

// ── Nachbildung Brevo (zustandsbehaftet) ──
$KEY = 'xkeysib-' . str_repeat('c3', 20);
$ct = function ($e, $bl = false) { return ['email' => $e, 'emailBlacklisted' => $bl]; };
$L = [
    2 => ['name' => 'Stammgäste', 'folderId' => 4, 'contacts' => [$ct("a_$sfx@example.test"), $ct("b_$sfx@example.test"), $ct("gast_$sfx@example.test"), $ct("unsub_$sfx@example.test"), $ct("blk_$sfx@example.test", true), $ct("hashed_$sfx@example.test")]],
    3 => ['name' => 'VIP', 'folderId' => 0, 'contacts' => [$ct("A_$sfx@example.test"), $ct("c_$sfx@example.test")]],
    4 => ['name' => 'Riesig', 'folderId' => 0, 'contacts' => [], 'unique' => 60000],
    5 => ['name' => 'Im Tixomat-Ordner', 'folderId' => 3, 'contacts' => []],
    800 => ['name' => 'Tixomat · Kampagne #0 · alt', 'folderId' => 0, 'contacts' => []],
];
for ($i = 100; $i < 155; $i++) $L[$i] = ['name' => "Liste $i", 'folderId' => 0, 'contacts' => []];
$GLOBALS['ml'] = ['lists' => $L, 'next' => 901, 'calls' => [], 'imports' => [], 'campaigns' => [], 'deleted' => [], 'process' => 'completed'];
add_filter('pre_http_request', function ($pre, $args, $url) use ($KEY) {
    if (strpos($url, 'https://api.brevo.com/v3') !== 0) return $pre;
    $path = substr($url, strlen('https://api.brevo.com/v3'));
    $m = strtoupper((string) ($args['method'] ?? 'GET'));
    $b = json_decode((string) ($args['body'] ?? ''), true) ?: [];
    $GLOBALS['ml']['calls'][] = [$m, $path, $b];
    $J = function ($code, $body = []) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => 'x'], 'cookies' => [], 'filename' => null]; };
    if (($args['headers']['api-key'] ?? '') !== $KEY) return $J(401, ['message' => 'Key not found']);
    $q = []; parse_str((string) wp_parse_url($path, PHP_URL_QUERY), $q); $p = (string) wp_parse_url($path, PHP_URL_PATH);
    $lim = intval($q['limit'] ?? 50); $off = intval($q['offset'] ?? 0);
    $size = function ($l) { return intval($l['unique'] ?? count($l['contacts'])); };
    if ($p === '/account') return $J(200, ['companyName' => 'NLL GmbH', 'email' => 'konto@example.test']);
    if ($p === '/senders') return $J(200, ['senders' => [['id' => 7, 'name' => 'NLL Team', 'email' => 'news@example.test', 'active' => true]]]);
    if ($p === '/webhooks' && $m === 'POST') return $J(201, ['id' => 55]);
    if ($p === '/contacts/folders' && $m === 'POST') return $J(201, ['id' => 3]);
    if ($p === '/contacts/folders') return $J(200, ['folders' => array_slice([['id' => 3, 'name' => 'Tixomat'], ['id' => 4, 'name' => 'Import']], $off, $lim), 'count' => 2]);
    if ($p === '/contacts/lists' && $m === 'POST') { $id = $GLOBALS['ml']['next']++; $GLOBALS['ml']['lists'][$id] = ['name' => $b['name'], 'folderId' => intval($b['folderId'] ?? 0), 'contacts' => []]; return $J(201, ['id' => $id]); }
    if ($p === '/contacts/lists') {
        $all = $GLOBALS['ml']['lists']; ksort($all); $rows = [];
        foreach ($all as $id => $l) $rows[] = ['id' => $id, 'name' => $l['name'], 'folderId' => $l['folderId'], 'uniqueSubscribers' => $size($l), 'totalSubscribers' => $size($l)];
        return $J(200, ['lists' => array_slice($rows, $off, $lim), 'count' => count($rows)]);
    }
    if (preg_match('#^/contacts/lists/(\d+)(/contacts)?$#', $p, $x)) {
        $id = intval($x[1]); $l = $GLOBALS['ml']['lists'][$id] ?? null;
        if ($m === 'DELETE') { $GLOBALS['ml']['deleted'][] = $id; unset($GLOBALS['ml']['lists'][$id]); return $J(204); }
        if (!$l) return $J(404, ['code' => 'document_not_found', 'message' => 'List ID does not exist']);
        if (empty($x[2])) return $J(200, ['id' => $id, 'name' => $l['name'], 'uniqueSubscribers' => $size($l), 'totalSubscribers' => $size($l)]);
        return $J(200, ['contacts' => array_slice($l['contacts'], $off, $lim), 'count' => count($l['contacts'])]);
    }
    if ($p === '/contacts/import') { $GLOBALS['ml']['imports'][] = $b; return $J(202, ['processId' => 70 + count($GLOBALS['ml']['imports'])]); }
    if (preg_match('#^/processes/\d+$#', $p)) return $J(200, ['status' => $GLOBALS['ml']['process']]);
    if ($p === '/emailCampaigns' && $m === 'POST') { $GLOBALS['ml']['campaigns'][] = $b; return $J(201, ['id' => 4300 + count($GLOBALS['ml']['campaigns'])]); }
    if (preg_match('#^/emailCampaigns/\d+(/sendNow|/status)?$#', $p)) return $J(204);
    if (strpos($p, '/contacts') === 0) return $J(200, ['contacts' => []]);
    return $J(404, ['message' => 'nicht nachgebildet ' . $path]);
}, 10, 3);
add_filter('pre_wp_mail', function () { return true; });
$calls = function () { return count($GLOBALS['ml']['calls']); };

wp_set_current_user($owner);
if (!$multi) nll_rest('POST', '/marketing/trial');
[$st, $d, $code] = nll_rest('GET', '/marketing/newsletter/lists');
nll_eq('Ohne Brevo: 409 tix_nl_not_connected', [409, 'tix_nl_not_connected'], [$st, $code]);
nll_rest('POST', '/marketing/newsletter/settings', ['api_key' => $KEY]);
[$st, $r] = nll_rest('POST', '/marketing/newsletter/settings', ['sender_id' => 7]);
nll_eq('Brevo verbunden, Absender gewählt', [true, 'news@example.test'], [$r['connected'] ?? null, $r['sender']['email'] ?? '']);

// ═══ 1. Listen ═══
$n0 = $calls();
[$st, $r] = nll_rest('GET', '/marketing/newsletter/lists');
$ids = array_column((array) ($r['lists'] ?? []), 'id');
$by = array_column((array) ($r['lists'] ?? []), null, 'id');
nll_eq('Listen: alle Seiten (2 Seiten à 50), ohne Tixomat · …', [200, 59, false, true, true], [$st, $r['total'] ?? null, in_array(800, $ids, true), in_array(154, $ids, true), in_array(5, $ids, true)]);
nll_eq('Liste: Name, Anzahl, Ordner', [['id' => 2, 'name' => 'Stammgäste', 'count' => 6, 'folder' => 'Import'], ''], [$by[2] ?? null, $by[3]['folder'] ?? null]);
$n1 = $calls();
nll_rest('GET', '/marketing/newsletter/lists');
nll_eq('Zwischenspeicher: zweiter Abruf ohne Brevo, ?refresh=1 fragt neu', [0, true], [$calls() - $n1, (nll_rest('GET', '/marketing/newsletter/lists', ['refresh' => 1]) && $calls() > $n1)]);

// eigene Kontakte: aktiv, abgemeldet, anonymisiert (Hash wie daily())
$tc = TIX_Newsletter::tc();
$base = ['organizer_id' => $org, 'source' => 'form', 'consent_text' => 'x', 'consent_at' => current_time('mysql', true), 'created' => current_time('mysql', true)];
$wpdb->insert($tc, $base + ['email' => "gast_$sfx@example.test", 'first_name' => 'Gina', 'status' => 'active', 'token' => wp_generate_password(40, false)]);
$wpdb->insert($tc, $base + ['email' => "unsub_$sfx@example.test", 'status' => 'unsubscribed', 'unsubscribed_at' => current_time('mysql', true), 'token' => wp_generate_password(40, false)]);
$wpdb->insert($tc, $base + ['email' => hash('sha256', "hashed_$sfx@example.test" . wp_salt('auth')), 'status' => 'blocked', 'token' => wp_generate_password(40, false)]);
$wpdb->query($wpdb->prepare("UPDATE $tc SET email = SHA2(CONCAT(%s, %s), 256) WHERE email = %s", "hashed_$sfx@example.test", wp_salt('auth'), hash('sha256', "hashed_$sfx@example.test" . wp_salt('auth'))));
nll_eq('Hash in PHP = SHA2 in MySQL', 1, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tc WHERE organizer_id = %d AND status = 'blocked' AND email = %s", $org, hash('sha256', "hashed_$sfx@example.test" . wp_salt('auth'))))));

// ═══ 3. Empfängerzahl ═══
[$st, $a] = nll_rest('GET', '/marketing/newsletter/audience', ['audience' => 'contacts']);
nll_eq('Ohne list_ids wie bisher + own, exact', [1, 1, true], [$a['count'] ?? null, $a['own'] ?? null, $a['exact'] ?? null]);
[$st, $a] = nll_rest('GET', '/marketing/newsletter/audience', ['audience' => 'contacts', 'list_ids' => '2,3']);
nll_eq('Gemischt: ohne Doppelte, Abmeldungen, Hash, Brevo-Sperre', [200, 4, 1, [['id' => 2, 'count' => 6], ['id' => 3, 'count' => 2]], 5, true], [$st, $a['count'] ?? null, $a['own'] ?? null, $a['lists'] ?? null, $a['excluded'] ?? null, $a['exact'] ?? null]);
nll_eq('Keine internen Felder in der Antwort', [false, false], [isset($a['_own']), isset($a['_exclude'])]);
[$st, $a] = nll_rest('GET', '/marketing/newsletter/audience', ['audience' => 'brevo_list', 'list_ids' => '2,3']);
nll_eq('Nur Listen: 4 (gast steht in Liste 2), own 0', [4, 0, 4], [$a['count'] ?? null, $a['own'] ?? null, $a['excluded'] ?? null]);
[$st, $a] = nll_rest('GET', '/marketing/newsletter/audience', ['audience' => 'contacts', 'list_ids' => '4']);
nll_eq('Sehr große Liste: Schätzung exact false', [60001, false], [$a['count'] ?? null, $a['exact'] ?? null]);
[$st, $a] = nll_rest('GET', '/marketing/newsletter/audience', ['audience' => 'brevo_list']);
nll_eq('Altes Studio: brevo_list ohne list_ids = Listenauswahl', [true, isset($a['count'])], [in_array(2, array_column((array) ($a['lists'] ?? []), 'id'), true), false]);
[$st, , $code] = nll_rest('GET', '/marketing/newsletter/audience', ['list_ids' => implode(',', range(100, 110))]);
nll_eq('Mehr als 10 Listen → 400', [400, 'tix_invalid_field'], [$st, $code]);
$n1 = $calls();
nll_rest('GET', '/marketing/newsletter/audience', ['audience' => 'contacts', 'list_ids' => '2,3']);
nll_eq('Zählung 60 s zwischengespeichert', 0, $calls() - $n1);

// ═══ 2. Kampagne speichern ═══
$body = ['subject' => 'Listen-Test', 'content' => '<p>Hallo</p>', 'audience' => 'contacts', 'list_ids' => [2, 3], 'list_id' => 2];
[$st, , $code, $dd] = nll_rest('POST', '/marketing/newsletter/campaigns', $body);
nll_eq('list_ids ohne Einwilligung → 400 consent_confirmed', [400, 'consent_confirmed'], [$st, $dd['field'] ?? '']);
[$st, , $code, $dd] = nll_rest('POST', '/marketing/newsletter/campaigns', ['list_ids' => [2, 999], 'consent_confirmed' => true] + $body);
nll_eq('Unbekannte Liste → 400 list_ids', [400, 'list_ids'], [$st, $dd['field'] ?? '']);
[$st, , $code, $dd] = nll_rest('POST', '/marketing/newsletter/campaigns', ['audience' => 'brevo_list', 'list_ids' => [], 'consent_confirmed' => true] + $body);
nll_eq('Nur Listen ohne Liste → 400 list_ids', [400, 'list_ids'], [$st, $dd['field'] ?? '']);
[$st, $r] = nll_rest('POST', '/marketing/newsletter/campaigns', ['consent_confirmed' => true] + $body);
$C = $r['campaign'] ?? [];
nll_eq('Gemischt gespeichert: list_ids, list_id = erste', [200, [2, 3], 2], [$st, $C['list_ids'] ?? null, $C['list_id'] ?? null]);
[$st, $r] = nll_rest('GET', '/marketing/newsletter/campaigns/' . $C['id']);
nll_eq('Nachweis: Einwilligung mit Zeitpunkt/Benutzer, Listen mit Namen', [true, $owner, [['id' => 2, 'name' => 'Stammgäste'], ['id' => 3, 'name' => 'VIP']]], [$r['campaign']['consent']['confirmed'] ?? null, $r['campaign']['consent']['by'] ?? null, $r['campaign']['lists'] ?? null]);
[$st, $r] = nll_rest('POST', '/marketing/newsletter/campaigns', ['subject' => 'Alt', 'content' => '<p>x</p>', 'audience' => 'brevo_list', 'list_id' => 3, 'consent_confirmed' => true]);
nll_eq('Altes Studio (nur list_id) → list_ids [3]', [200, [3], 3], [$st, $r['campaign']['list_ids'] ?? null, $r['campaign']['list_id'] ?? null]);
$Calt = $r['campaign'];
[$st, $r] = nll_rest('POST', '/marketing/newsletter/campaigns/' . $Calt['id'], ['audience' => 'contacts']);
nll_eq('Zielgruppe gewechselt ohne list_ids → Listen weg', [[], null], [$r['campaign']['list_ids'] ?? null, $r['campaign']['list_id'] ?? null]);
nll_rest('POST', '/marketing/newsletter/campaigns/' . $Calt['id'] . '/duplicate');

// ═══ 4. Versand gemischt ═══
$GLOBALS['ml']['imports'] = []; $GLOBALS['ml']['campaigns'] = [];
[$st, $r] = nll_rest('POST', '/marketing/newsletter/campaigns/' . $C['id'] . '/send');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", $C['id']), ARRAY_A);
$camp = end($GLOBALS['ml']['campaigns']);
$imp = $GLOBALS['ml']['imports'];
$own_list = intval($row['brevo_list']); $excl = intval($row['brevo_excl']);
nll_eq('Gemischt versendet: Status, Empfänger 4', [200, 'sent', 4], [$st, $row['status'], intval($row['recipients'])]);
nll_eq('Import: eigene Empfänger, Ausschluss = Abmeldung + anonymisierte (nicht die Brevo-Sperre)', [["gast_$sfx@example.test"], ["hashed_$sfx@example.test", "unsub_$sfx@example.test"]],
    [array_column((array) ($imp[0]['jsonBody'] ?? []), 'email'), (function ($x) { sort($x); return $x; })(array_column((array) ($imp[1]['jsonBody'] ?? []), 'email'))]);
nll_eq('Kampagne: listIds = eigene Liste + gewählte, exclusionListIds', [[$own_list, 2, 3], [$excl], true], [$camp['recipients']['listIds'] ?? null, $camp['recipients']['exclusionListIds'] ?? null, $own_list > 0 && $excl > 0 && $own_list !== $excl]);
nll_eq('Listen-Namen der Ausschluss-Liste', true, strpos((string) ($GLOBALS['ml']['lists'][$excl]['name'] ?? ''), 'evendis · Ausschluss #' . $C['id']) === 0);
nll_eq('Gemischt: Fußzeile wie bisher', true, strpos((string) $camp['htmlContent'], 'für den Newsletter von') !== false);
[$st, $r] = nll_rest('GET', '/marketing/newsletter/campaigns/' . $C['id']);
nll_eq('Versand-Nachweis: Listen mit Name und Größe', [['id' => 2, 'name' => 'Stammgäste', 'count' => 6], ['id' => 3, 'name' => 'VIP', 'count' => 2]], $r['campaign']['sent_lists'] ?? null);

// Nur Listen
[$st, $r] = nll_rest('POST', '/marketing/newsletter/campaigns', ['subject' => 'Nur Listen', 'content' => '<p>Hi</p>', 'audience' => 'brevo_list', 'list_ids' => [2, 3], 'consent_confirmed' => true]);
$C2 = $r['campaign'];
$GLOBALS['ml']['imports'] = [];
[$st] = nll_rest('POST', '/marketing/newsletter/campaigns/' . $C2['id'] . '/send');
$row2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", $C2['id']), ARRAY_A);
$camp = end($GLOBALS['ml']['campaigns']);
nll_eq('Nur Listen: listIds = gewählte, keine eigene Liste, Ausschluss dabei', ['sent', [2, 3], 0, 1, true], [$row2['status'], $camp['recipients']['listIds'] ?? null, intval($row2['brevo_list']), count($GLOBALS['ml']['imports']), intval($row2['brevo_excl']) > 0]);
nll_eq('Nur Listen: Grund „bei {Absender} angemeldet“, Abmeldelink', [true, true], [strpos((string) $camp['htmlContent'], 'weil du dich bei NLL Team angemeldet hast') !== false, strpos((string) $camp['htmlContent'], '{{ unsubscribe }}') !== false]);

// Zurückholen: nie die Kundenlisten löschen
$GLOBALS['ml']['process'] = 'in_process';
[$st, $r] = nll_rest('POST', '/marketing/newsletter/campaigns', ['subject' => 'Zurück', 'content' => '<p>Hi</p>', 'audience' => 'contacts', 'list_ids' => [2], 'consent_confirmed' => true]);
$C3 = $r['campaign'];
nll_rest('POST', '/marketing/newsletter/campaigns/' . $C3['id'] . '/send', ['at' => gmdate('c', time() + 11 * MINUTE_IN_SECONDS)]);
TIX_Newsletter::advance($wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", $C3['id']), ARRAY_A));
$row3 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", $C3['id']), ARRAY_A);
$GLOBALS['ml']['deleted'] = [];
[$st, $r] = nll_rest('POST', '/marketing/newsletter/campaigns/' . $C3['id'] . '/cancel');
$del = $GLOBALS['ml']['deleted']; sort($del);
$exp = [intval($row3['brevo_list']), intval($row3['brevo_excl'])]; sort($exp);
nll_eq('Zurückholen: wartet auf Import, löscht nur eigene Listen, Liste 2 bleibt', ['preparing', 200, 'draft', $exp, false], [$row3['status'], $st, $r['campaign']['status'] ?? '', $del, in_array(2, $del, true)]);
$GLOBALS['ml']['process'] = 'completed';

// Liste gelöscht
[$st, $r] = nll_rest('POST', '/marketing/newsletter/campaigns', ['subject' => 'Weg', 'content' => '<p>Hi</p>', 'audience' => 'brevo_list', 'list_ids' => [3], 'consent_confirmed' => true]);
$C4 = $r['campaign'];
nll_rest('POST', '/marketing/newsletter/campaigns/' . $C4['id'] . '/send', ['at' => gmdate('c', time() + 11 * MINUTE_IN_SECONDS)]);
$vip = $GLOBALS['ml']['lists'][3]; unset($GLOBALS['ml']['lists'][3]);
TIX_Newsletter::advance($wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", $C4['id']), ARRAY_A));
$row4 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", $C4['id']), ARRAY_A);
nll_eq('Liste gelöscht: fehlgeschlagen mit Namen', ['failed', 'Die Brevo-Liste „VIP“ gibt es nicht mehr.'], [$row4['status'], $row4['error']]);
nll_rest('POST', '/marketing/newsletter/campaigns/' . $C4['id'] . '/cancel');
[$st, , $code, $dd] = nll_rest('POST', '/marketing/newsletter/campaigns/' . $C4['id'] . '/send');
nll_eq('Sofort senden mit gelöschter Liste → 409 tix_nl_list_gone + list_id', [409, 'tix_nl_list_gone', 3], [$st, $code, intval($dd['list_id'] ?? 0)]);
$GLOBALS['ml']['lists'][3] = $vip;

// Ordner Tixomat (nach dem ersten Versand gemerkt) fällt aus der Liste
[$st, $r] = nll_rest('GET', '/marketing/newsletter/lists', ['refresh' => 1]);
$ids = array_column((array) ($r['lists'] ?? []), 'id');
nll_eq('Listen im Tixomat-Ordner und eigene Kampagnen-Listen fallen weg', [false, false, true], [in_array(5, $ids, true), in_array($excl, $ids, true), in_array(2, $ids, true)]);

// ═══ Aufräumen nach 30 Tagen ═══
$old = gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS);
$wpdb->query($wpdb->prepare("UPDATE " . TIX_Newsletter::tk() . " SET sent_at = %s WHERE id IN (%d, %d)", $old, $C['id'], $C2['id']));
$GLOBALS['ml']['deleted'] = [];
TIX_Newsletter::daily();
$del = $GLOBALS['ml']['deleted']; sort($del);
$exp = [$own_list, $excl, intval($row2['brevo_excl'])]; sort($exp);
nll_eq('Täglich: eigene + Ausschluss-Listen gelöscht, Kundenlisten 2/3 nie', [$exp, false, false], [$del, in_array(2, $del, true), in_array(3, $del, true)]);

// Aufräumen
wp_set_current_user(1);
TIX_Newsletter::purge_organizer($org);
foreach ($opt_before as $k => $v) { $name = ['s' => TIX_Newsletter::OPT, 't' => 'tix_marketing_trial_until', 'm' => 'tix_marketing_enabled', 'ts' => 'tix_settings'][$k]; if ($v === null) delete_option($name); else update_option($name, $v); }
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_nl\\_%'");
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
