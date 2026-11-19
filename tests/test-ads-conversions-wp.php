<?php
/**
 * 1.38.906 Google-Ads-Conversions vom Server (vertrag-ads-offline-conversions.md §2/§4/§5): /public/ads/click, tix_ads_clicks, CSV-Export, Löschfrist.
 *   wp eval-file …/test-ads-conversions-wp.php   (läuft auf Plattform und Einzel-Seite)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Ads_Conversions')) { echo "Tixomat mit TIX_Ads_Conversions nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$A = 'TIX_Ads_Conversions';
$bak = []; foreach ([$A::OPT, $A::OPT_NAMES, 'tix_demo_instance'] as $o) $bak[$o] = get_option($o, null);
update_option('tix_demo_instance', '0', false);
$sfx = strtolower(wp_generate_password(6, false, false)); $nid = random_int(100000000, 899999999);
$gid = function ($k) use ($sfx) { return 'TeSt' . $k . '_' . $sfx . '_abcdef'; };
$rl = function () use ($wpdb) { $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_ads\\_click%'"); wp_cache_flush(); };
$post = function ($route, $body) { $x = new WP_REST_Request('POST', '/tixomat/v1' . $route); $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($body)); $r = rest_do_request($x); return [$r->get_status(), $r->get_data()]; };
$ok = ['ad_user_data' => 'GRANTED', 'ad_personalization' => 'DENIED'];
$click = function ($k, $extra = []) use ($gid, $ok) { return array_merge(['click_id_type' => 'gclid', 'click_id' => $gid($k), 'conversion' => 'beratung', 'source' => 'evendis.de/eigener-ticketshop', 'reference' => 'support:42', 'email' => ' Anna@Beispiel.de ', 'consent' => $ok], $extra); };
$row = function ($k) use ($wpdb, $A, $gid) { return get_option('tix_ads_db') ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $A::t() . ' WHERE click_id = %s ORDER BY id DESC LIMIT 1', $gid($k))) : null; };

// ═══ Reiner Kern ═══
$eq('E-Mail-Hash: getrimmt, klein, SHA-256; ungültig leer', [hash('sha256', 'anna@beispiel.de'), '', ''], [$A::hash_email(' Anna@Beispiel.DE '), $A::hash_email('kein-mail'), $A::hash_email('')]);
$eq('Einwilligung: GRANTED/DENIED; ohne ad_user_data → null; Unsinn → false; Personalisierung Vorgabe DENIED', [$ok, null, false, $ok],
    [$A::clean_consent($ok), $A::clean_consent(['ad_user_data' => 'DENIED']), $A::clean_consent(['ad_user_data' => 'ja']), $A::clean_consent(['ad_user_data' => 'granted'])]);
$fld = function ($b) use ($A) { return $A::clean_click($b)[1]; };
$eq('Klick-Prüfung: Typ, ID-Muster, Conversion (kunde nur intern), Bezug, E-Mail', ['click_id_type', 'click_id', 'click_id', 'conversion', 'reference', 'email', null],
    [$fld(['click_id_type' => 'fbclid', 'click_id' => 'x']), $fld(['click_id_type' => 'gclid', 'click_id' => 'kurz']), $fld(['click_id_type' => 'gclid', 'click_id' => 'abc def ghij klm']),
     $fld(['click_id_type' => 'gclid', 'click_id' => 'Cj0KCQiA12345', 'conversion' => 'kunde']), $fld(['click_id_type' => 'gclid', 'click_id' => 'Cj0KCQiA12345', 'conversion' => 'beratung', 'reference' => 'order:1']),
     $fld(['click_id_type' => 'gclid', 'click_id' => 'Cj0KCQiA12345', 'conversion' => 'beratung', 'email' => 'nein']), $fld(['click_id_type' => 'wbraid', 'click_id' => 'Cj0KCQiA-_12345', 'conversion' => 'testphase', 'reference' => 'trial:7'])]);
$eq('CSV-Zeit: UTC → Europe/Berlin (Winter +1, Sommer +2)', ['2026-11-19 11:00:00', '2026-07-01 12:00:00'], [$A::csv_time('2026-11-19 10:00:00'), $A::csv_time('2026-07-01 10:00:00')]);
$csv0 = $A::build_csv([(object) ['click_id' => 'Cj0KCQiA12345', 'conversion' => 'kunde', 'clicked_at' => '2026-11-19 10:00:00', 'conversion_value' => '790.00', 'currency_code' => 'EUR', 'consent' => wp_json_encode($ok)]], $A::DEFAULT_NAMES);
$eq('CSV im Format „Conversions from clicks“: Zeitzone, Kopf, Wert, Einwilligung Granted/Denied', ["Parameters:TimeZone=Europe/Berlin", 'Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Ad User Data,Ad Personalization', 'Cj0KCQiA12345,Kunde,"2026-11-19 11:00:00",790.00,EUR,Granted,Denied'],
    array_slice(explode("\n", trim($csv0)), 0, 3));

// ═══ Schalter aus ═══
update_option($A::OPT, '0', false); $rl();
[$st] = $post('/public/ads/click', $click('off'));
$eq('Schalter aus (Vorgabe): /public/ads/click → 404, nichts gespeichert', [404, null], [$st, $row('off')]);

// ═══ Route ═══
update_option($A::OPT, '1', false); $A::maybe_install(); $rl();
[$st, $d] = $post('/public/ads/click', $click('a'));
$r = $row('a');
$eq('Klick mit Einwilligung → 202, Status neu, Prüfwert statt E-Mail, Einwilligung als JSON', [202, true, 'neu', hash('sha256', 'anna@beispiel.de'), 'support:42', 'beratung', $ok],
    [$st, $d['ok'] ?? null, $r->status ?? '', $r->email_sha256 ?? '', $r->reference ?? '', $r->conversion ?? '', json_decode((string) ($r->consent ?? ''), true)]);
$eq('Klartext-E-Mail nirgends in der Zeile', false, stripos(wp_json_encode($r), 'anna@') !== false);
$post('/public/ads/click', $click('a'));
$eq('Gleicher Klick doppelt → kein zweiter Eintrag', 1, intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $A::t() . ' WHERE click_id = %s', $gid('a')))));
[$st, $d] = $post('/public/ads/click', $click('b', ['consent' => ['ad_user_data' => 'DENIED', 'ad_personalization' => 'DENIED']]));
$eq('ad_user_data nicht GRANTED → 409 tix_no_consent, nichts gespeichert', [409, 'tix_no_consent', null], [$st, $d['code'] ?? '', $row('b')]);
[$st, $d] = $post('/public/ads/click', $click('c', ['conversion' => 'kunde']));
$eq('conversion kunde von außen → 400 field conversion', [400, 'tix_invalid_field', 'conversion'], [$st, $d['code'] ?? '', $d['data']['field'] ?? '']);
[$st, $d] = $post('/public/ads/click', $click('c', ['consent' => 'ja']));
$eq('consent kein Objekt → 400 field consent', [400, 'consent'], [$st, $d['data']['field'] ?? '']);
[$st, $d] = $post('/public/ads/click', $click('c'));
$eq('6. Anfrage derselben IP in 10 min → 429 tix_rate_limited', [429, 'tix_rate_limited', null], [$st, $d['code'] ?? '', $row('c')]);
$rl();

// ═══ Einstellung + Zähler (nur Admin) ═══
wp_set_current_user(0);
$x = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/platform/ads-conversions'));
$eq('Einstellung ohne Anmeldung → 401', 401, $x->get_status());
$adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
wp_set_current_user($adm);
$x = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/platform/ads-conversions'));
$g = $x->get_data();
$eq('GET: enabled, upload csv, Zähler, nie Klick-IDs', [200, true, 'csv', true, false], [$x->get_status(), $g['enabled'] ?? null, $g['upload'] ?? '', ($g['counts']['beratung']['neu'] ?? 0) >= 1, strpos(wp_json_encode($g), $gid('a')) !== false]);
[$st, $d] = $post('/platform/ads-conversions', ['names' => ['beratung' => 'Beratung (Test)'], 'enabled' => true]);
[$st2, $d2] = $post('/platform/ads-conversions', ['names' => ['foo' => 'x']]);
$eq('POST names: eigener Name; unbekannte Conversion → 400', [200, 'Beratung (Test)', 400, 'names.foo'], [$st, $d['conversions'][0]['name'] ?? '', $st2, $d2['data']['field'] ?? '']);

// ═══ Export ═══
$old = gmdate('Y-m-d H:i:s', time() - 7 * HOUR_IN_SECONDS);
$wpdb->update($A::t(), ['clicked_at' => $old], ['click_id' => $gid('a')]);
$mk = function ($k, $conv, $ref, $type = 'gclid', $at = null, $status = 'neu', $extra = []) use ($A, $gid, $ok, $wpdb, $old) {
    $id = $A::store(['click_id_type' => $type, 'click_id' => $gid($k), 'conversion' => $conv, 'reference' => $ref, 'source' => 't', 'email_sha256' => hash('sha256', 'x@y.de')], $ok, $status, $extra);
    $wpdb->update($A::t(), ['clicked_at' => $at ?? $old], ['id' => $id]); return $id; };
$mk('tp', 'testphase', 'trial:' . $nid);
$mk('young', 'beratung', 'support:1', 'gclid', gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS));
$mk('braid', 'beratung', 'support:2', 'gbraid');
$mk('wait', 'testphase', 'trial:' . ($nid + 1), 'gclid', null, 'wartet');
$p = $A::payload()['pending'];
$eq('Offen: exportierbar ≥ 2, jünger als 6 h ≥ 1, gbraid ≥ 1', [true, true, true], [$p['exportable'] >= 2, $p['too_young'] >= 1, $p['braid'] >= 1]);
[$st, $d] = $post('/platform/ads-conversions/export', []);
$csv = (string) ($d['csv'] ?? '');
$eq('Export: 200, Dateiname .csv, enthält a + tp mit eigenem Namen, nicht young/braid/wartet', [200, true, true, true, true, false, false, false],
    [$st, (bool) preg_match('/^google-ads-conversions-.*\.csv$/', $d['filename'] ?? ''), strpos($csv, $gid('a')) !== false, strpos($csv, $gid('tp')) !== false, strpos($csv, 'Beratung (Test)') !== false,
     strpos($csv, $gid('young')) !== false, strpos($csv, $gid('braid')) !== false, strpos($csv, $gid('wait')) !== false]);
$ra = $wpdb->get_row('SELECT * FROM ' . $A::t() . " WHERE reference = 'support:42' AND source = 'evendis.de/eigener-ticketshop' ORDER BY id DESC LIMIT 1");
$rt = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $A::t() . ' WHERE reference = %s', 'trial:' . $nid));
$eq('Nach Export: Beratung gemeldet + Klick-ID/Prüfwert geleert; Testphase gemeldet, Klick-ID bleibt für „kunde“, Prüfwert weg', ['gemeldet', '', '', true, 'gemeldet', $gid('tp'), ''],
    [$ra->status ?? '', $ra->click_id ?? 'x', $ra->email_sha256 ?? 'x', !empty($ra->uploaded_at), $rt->status ?? '', $rt->click_id ?? '', $rt->email_sha256 ?? 'x']);
[$st, $d] = $post('/platform/ads-conversions/export', []);
$eq('Zweiter Export: nichts davon noch einmal', false, strpos((string) ($d['csv'] ?? ''), $gid('a')) !== false);

// ═══ kunde über den Haken der Testphase ═══
$A::on_trial_converted((object) ['id' => $nid], 790.0);
$A::on_trial_converted((object) ['id' => $nid], 790.0);
$rk = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $A::t() . " WHERE reference = %s AND conversion = 'kunde'", 'trial:' . $nid));
$rt = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $A::t() . " WHERE reference = %s AND conversion = 'testphase'", 'trial:' . $nid));
$eq('Kunde: einmal, Wert 790 EUR, Klick-ID übernommen; Testphase danach ohne Klick-ID', [1, '790.00', 'EUR', $gid('tp'), ''], [count($rk), $rk[0]->conversion_value ?? '', $rk[0]->currency_code ?? '', $rk[0]->click_id ?? '', $rt->click_id ?? 'x']);
$A::on_trial_queued((object) ['id' => ($nid + 1)]);
$eq('Testphase bestätigt: wartet → neu', 'neu', $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $A::t() . ' WHERE reference = %s', 'trial:' . ($nid + 1))));

// ═══ Löschfrist 90 Tage ═══
$mk('alt', 'beratung', 'support:3', 'gclid', gmdate('Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS));
$ida = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $A::t() . ' WHERE click_id = %s', $gid('alt')));
$mk('alt2', 'testphase', 'trial:' . ($nid + 2), 'gclid', gmdate('Y-m-d H:i:s', time() - 95 * DAY_IN_SECONDS), 'gemeldet');
$idb = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $A::t() . ' WHERE click_id = %s', $gid('alt2')));
$A::on_trial_converted((object) ['id' => ($nid + 2)], 790.0);
$eq('Klick älter als 90 Tage → keine Conversion kunde mehr', 0, intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $A::t() . " WHERE reference = %s AND conversion = 'kunde'", 'trial:' . ($nid + 2)))));
$A::cleanup();
$ca = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $A::t() . ' WHERE id = %d', $ida)); $cb = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $A::t() . ' WHERE id = %d', $idb));
$eq('Cron: > 90 Tage offen → verfallen ohne Klick-ID/Prüfwert; gemeldet bleibt gemeldet, geleert; jüngere unberührt', ['verfallen', '', '', 'gemeldet', '', $gid('young')],
    [$ca->status, $ca->click_id, $ca->email_sha256, $cb->status, $cb->click_id, (string) $wpdb->get_var($wpdb->prepare('SELECT click_id FROM ' . $A::t() . ' WHERE click_id = %s', $gid('young')))]);
$eq('Cron täglich eingeplant', true, (bool) wp_next_scheduled('tix_ads_cleanup') || (do_action('init') || wp_next_scheduled('tix_ads_cleanup')));

// ═══ Nichts in Logs ═══
$src = file_get_contents(TIXOMAT_PATH . 'includes/class-tix-ads-conversions.php');
$eq('Klasse schreibt kein error_log und keine Mail', [false, false], [strpos($src, 'error_log(') !== false, strpos($src, 'wp_mail(') !== false]);

// ═══ Aufräumen ═══
wp_set_current_user(0);
$wpdb->query("DELETE FROM " . $A::t() . " WHERE click_id LIKE '%" . esc_sql($sfx) . "%' OR reference IN ('trial:$nid', 'trial:" . ($nid + 1) . "', 'trial:" . ($nid + 2) . "') OR source IN ('t', 'evendis.de/eigener-ticketshop')");
foreach ($bak as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
$rl();
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
