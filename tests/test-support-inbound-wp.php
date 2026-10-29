<?php
/**
 * Integrationstest TIX_Support_Inbound (Support-Mail-Eingang, 1.38.577) gegen ein lokales Test-WordPress.
 *   php -r 'require "/var/www/html/wp-load.php"; require "wp-content/plugins/tixomat/tests/test-support-inbound-wp.php";'
 * Ende-zu-Ende über zwei Seiten (Hub → Seite per HTTP): tests/test-support-inbound-e2e.sh
 */
if (!defined('ABSPATH') || !class_exists('TIX_Support_Inbound')) { echo "WordPress mit Tixomat (TIX_Support_Inbound) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function si_t($l, $c, $info = null) { $GLOBALS['t_count']++; if ($c) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l " . json_encode($info, JSON_UNESCAPED_UNICODE) . "\n"; }
function si_rest($m, $r, $b = null, array $hd = [], $raw = null) {
    $qs = [];
    if (strpos($r, '?') !== false) { parse_str(substr($r, strpos($r, '?') + 1), $qs); $r = strstr($r, '?', true); }
    $q = new WP_REST_Request($m, '/tixomat/v1' . $r);
    if ($qs) $q->set_query_params($qs);
    foreach ($hd as $k => $v) $q->set_header($k, $v);
    if ($raw !== null) $q->set_body($raw);
    elseif ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); }
    $res = rest_do_request($q); $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), $d];
}
function si_msgs($tid) { $m = get_post_meta($tid, '_tix_sp_messages', true); return is_array($m) ? $m : []; }
function si_mail($from, $to, $subject, $body, array $extra = []) {
    return implode("\r\n", array_merge(["From: $from", "To: $to", "Subject: $subject", 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(6)) . '@guest.test>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'], $extra)) . "\r\n\r\n" . $body;
}

$keep = ['opt' => get_option(TIX_Support_Inbound::OPT, null), 'hub' => get_option(TIX_Support_Inbound::OPT_HUB_KEY, null), 'sites' => get_option(TIX_Support_Inbound::OPT_SITES, null),
    'tix' => get_option('tix_settings', null), 'mail' => get_option(TIX_Support_Mail::OPTION, null)];
$posts = []; $users = [];
$sfx = wp_rand(1000, 9999);
// Support an, IMAP aus (Stufe 1 gilt gerade ohne Postfach)
$ts = get_option('tix_settings', []); $ts = is_array($ts) ? $ts : []; $ts['support_enabled'] = 1; update_option('tix_settings', $ts);
if (function_exists('tix_get_settings')) { tix_get_settings(); }
delete_option(TIX_Support_Mail::OPTION);
// Ausgehende Mails abfangen
$GLOBALS['si_out'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['si_out'][] = $atts; return true; }, 10, 2);

echo "── Ohne Verbindung ──\n";
delete_option(TIX_Support_Inbound::OPT);
si_t('nicht verbunden', !TIX_Support_Inbound::connected() && TIX_Support_Inbound::reply_address(5) === '');
si_t('replies_active ohne Verbindung/IMAP = false', !TIX_Support_Mail::replies_active());

echo "── Verbinden + Adressen ──\n";
update_option(TIX_Support_Inbound::OPT, ['slug' => 'kk', 'secret' => 'geheim' . $sfx], false);
si_t('verbunden', TIX_Support_Inbound::connected() && TIX_Support_Mail::replies_active());
$tid = TIX_Support::create_ticket_from_email(['email' => "gast$sfx@example.test", 'name' => 'Gast Test', 'subject' => 'Frage zum Einlass', 'date_ts' => time()]);
$posts[] = $tid;
$ra = TIX_Support_Inbound::reply_address($tid);
si_t('Antwort-Adresse Format', (bool) preg_match('/^kk-r' . $tid . '-[a-f0-9]{12}@support\.evendis\.de$/', $ra), $ra);
si_t('Antwort-Adresse ≤ 64 Zeichen lokal', strlen(strstr($ra, '@', true)) <= 64);
si_t('parse_local gültig', TIX_Support_Inbound::parse_local(strtoupper($ra)) === ['kind' => 'reply', 'ticket' => $tid]);
si_t('parse_local falsche Signatur', TIX_Support_Inbound::parse_local(preg_replace('/-[a-f0-9]{12}@/', '-000000000000@', $ra)) === null);
si_t('parse_local fremde ID mit alter Signatur', TIX_Support_Inbound::parse_local(str_replace('-r' . $tid . '-', '-r' . ($tid + 1) . '-', $ra)) === null);
si_t('parse_local anderer Slug', TIX_Support_Inbound::parse_local(preg_replace('/^kk-/', 'xy-', $ra)) === null);
$in = TIX_Support_Inbound::channel_address(); $fw = TIX_Support_Inbound::takeover_address();
si_t('Eingang + Übernahme', TIX_Support_Inbound::parse_local($in)['kind'] === 'in' && TIX_Support_Inbound::parse_local($fw)['kind'] === 'fw' && $in !== $fw);

echo "── Ausgehende Support-Mail ──\n";
$GLOBALS['si_out'] = [];
TIX_Support_Mail::send_customer_mail($tid, "gast$sfx@example.test", 'Re: Frage', TIX_Support_Mail::body_top_html() . '<p>Hallo</p>' . TIX_Support_Mail::body_footer_html($tid), 'reply');
$o = $GLOBALS['si_out'][0] ?? [];
$hdr = implode("\n", (array) ($o['headers'] ?? []));
si_t('Reply-To = versteckte Antwort-Adresse', strpos($hdr, 'Reply-To: "') !== false && strpos($hdr, '<' . $ra . '>') !== false, $hdr);
si_t('kein eigener From-Kopf (Absender der Seite bleibt)', stripos($hdr, "\nFrom:") === false && stripos($hdr, 'From:') !== 0);
si_t('Marker + „einfach antworten“ in der Mail', strpos((string) $o['message'], TIX_Support_Mail::MARKER) !== false && strpos((string) $o['message'], 'einfach auf diese E-Mail antworten') !== false);

echo "── Stufe 1: Antworten ──\n";
$n0 = count(si_msgs($tid));
$r = TIX_Support_Inbound::deliver_local(si_mail("Gast Test <gast$sfx@example.test>", $ra, 'Re: Frage zum Einlass', "Danke, passt!\n\n" . TIX_Support_Mail::MARKER . "\nAlter Text\n> zitiert"), $ra);
$m = si_msgs($tid); $last = end($m);
si_t('Antwort übernommen', $r['result'] === 'imported' && intval($r['ticket']) === $tid && ($r['how'] ?? '') === 'antwort-adresse', $r);
si_t('Zitat am Marker gekürzt', count($m) === $n0 + 1 && trim($last['content']) === 'Danke, passt!' && $last['type'] === 'customer' && $last['source'] === 'email', $last);
$r = TIX_Support_Inbound::deliver_local(si_mail("Fremd <fremd$sfx@example.test>", $ra, 'Re: Frage', 'Ich bin die Begleitung.'), $ra);
$m = si_msgs($tid);
$notes = array_filter($m, function ($x) use ($sfx) { return ($x['type'] ?? '') === 'note' && strpos((string) $x['content'], "fremd$sfx@") !== false; });
si_t('abweichender Absender: übernommen + Hinweis', $r['result'] === 'imported' && count($notes) === 1, $r);
$r = TIX_Support_Inbound::deliver_local(si_mail("gast$sfx@example.test", $ra, 'Automatische Antwort: Frage', 'Bin im Urlaub', ['Auto-Submitted: auto-replied']), $ra);
si_t('Abwesenheitsnotiz verworfen', $r['result'] === 'skipped_auto', $r);
$r = TIX_Support_Inbound::deliver_local(si_mail("gast$sfx@example.test", $ra, 'Re: Frage', 'Viagra', ['X-Spam-Flag: YES']), $ra);
si_t('Spam verworfen', $r['result'] === 'skipped_auto', $r);
$bad = preg_replace('/-[a-f0-9]{12}@/', '-abcdefabcdef@', $ra);
$r = TIX_Support_Inbound::deliver_local(si_mail("gast$sfx@example.test", $bad, 'Re', 'x'), $bad);
si_t('gefälschte Antwort-Adresse abgelehnt', $r['result'] === 'invalid_address', $r);
$dupe = si_mail("gast$sfx@example.test", $ra, 'Re: Frage', 'Nochmal');
TIX_Support_Inbound::deliver_local($dupe, $ra);
$r = TIX_Support_Inbound::deliver_local($dupe, $ra);
si_t('doppelte Zustellung erkannt', $r['result'] === 'duplicate', $r);
$b = 'b' . $sfx;
$multi = "From: gast$sfx@example.test\r\nTo: $ra\r\nSubject: Re: Beleg\r\nMessage-ID: <att$sfx@guest.test>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nHier der Beleg.\r\n--$b\r\nContent-Type: application/pdf; name=\"beleg.pdf\"\r\nContent-Disposition: attachment; filename=\"beleg.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode("%PDF-1.4\n" . str_repeat('x', 2000))) . "--$b--\r\n";
$r = TIX_Support_Inbound::deliver_local($multi, $ra);
$m = si_msgs($tid); $last = end($m);
si_t('Anhang gespeichert', $r['result'] === 'imported' && count((array) $last['attachments']) === 1 && $last['attachments'][0]['name'] === 'beleg.pdf', $last['attachments'] ?? null);
$closed_raw = si_mail("gast$sfx@example.test", 'kk-r999999-' . substr(TIX_Support_Mail::sign('inb|r|999999'), 0, 12) . '@support.evendis.de', 'Re', 'gibt es nicht');
$r = TIX_Support_Inbound::deliver_local($closed_raw, 'kk-r999999-' . substr(TIX_Support_Mail::sign('inb|r|999999'), 0, 12) . '@support.evendis.de');
si_t('Antwort auf unbekannte Anfrage: keine neue Anfrage', $r['result'] === 'ignored_unmatched', $r);

echo "── Stufe 2: eigene Adresse ──\n";
$admin = wp_insert_user(['user_login' => "si_admin_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "si_admin_$sfx@example.test", 'role' => 'administrator']); $users[] = $admin;
$sub = wp_insert_user(['user_login' => "si_sub_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "si_sub_$sfx@example.test", 'role' => 'subscriber']); $users[] = $sub;
wp_set_current_user(0);
[$st] = si_rest('GET', '/support/mail-inbox');
si_t('ohne Anmeldung 401', $st === 401, $st);
wp_set_current_user($sub);
[$st] = si_rest('GET', '/support/mail-inbox');
si_t('Gast-Konto 403', $st === 403, $st);
wp_set_current_user($admin);
[$st, $d] = si_rest('GET', '/support/mail-inbox');
si_t('Admin: Zustand', $st === 200 && $d['available'] === true && $d['replies']['active'] === true && $d['alias']['status'] === 'none' && $d['alias']['forward_to'] === $in && $d['takeover_address'] === $fw, $d);
[$st, $d] = si_rest('POST', '/support/mail-inbox', ['alias' => 'kein-mail']);
si_t('ungültige Adresse 400', $st === 400 && ($d['data']['field'] ?? '') === 'alias', $d);
[$st, $d] = si_rest('POST', '/support/mail-inbox', ['alias' => $in]);
si_t('Zieladresse als eigene Adresse abgelehnt', $st === 400, $d);
[$st, $d] = si_rest('POST', '/support/mail-inbox', ['alias' => "Support$sfx@Club.test", 'provider' => 'gmail', 'show_contact' => true]);
si_t('Adresse gespeichert, wartet', $st === 200 && $d['alias']['address'] === "support$sfx@club.test" && $d['alias']['status'] === 'waiting' && $d['alias']['provider'] === 'gmail', $d['alias'] ?? $d);
si_t('Kontakt erst nach Verbindung', TIX_Support_Inbound::contact_email() === '');
$GLOBALS['si_out'] = [];
[$st, $d] = si_rest('POST', '/support/mail-inbox/test');
$tm = $GLOBALS['si_out'][0] ?? [];
si_t('Testmail an die eigene Adresse', $st === 200 && $d['alias']['status'] === 'testing' && ($tm['to'] ?? '') === "support$sfx@club.test" && preg_match('/TIXTEST-[a-f0-9]{10}-[a-f0-9]{8}/', (string) ($tm['subject'] ?? '')), [$st, $tm['subject'] ?? null]);
preg_match('/TIXTEST-[a-f0-9]{10}-[a-f0-9]{8}/', (string) ($tm['subject'] ?? ''), $tok);
// Gmail-Bestätigung, bevor die Weiterleitung steht
$gmail = si_mail('Gmail Team <forwarding-noreply@google.com>', $in, '(#839201477) Gmail-Weiterleitungsbestätigung - Empfangen von E-Mails von support@club.test',
    "support@club.test hat angefordert, E-Mails automatisch an Ihre E-Mail-Adresse weiterzuleiten.\nBestätigungscode: 839201477\n\nKlicken Sie auf den Link:\nhttps://mail-settings.google.com/mail/vf-%5BANGjdJ%5D-abc\n");
$r = TIX_Support_Inbound::deliver_local($gmail, $in);
[$st, $d] = si_rest('GET', '/support/mail-inbox');
$v = $d['verifications'][0] ?? [];
si_t('Gmail-Bestätigung erkannt', $r['result'] === 'verification' && ($v['code'] ?? '') === '839201477' && strpos((string) ($v['link'] ?? ''), 'mail-settings.google.com') !== false, [$r, $v]);
si_t('Bestätigung wird keine Anfrage', !get_posts(['post_type' => 'tix_support_ticket', 'post_status' => 'any', 'meta_key' => '_tix_sp_email', 'meta_value' => 'forwarding-noreply@google.com', 'fields' => 'ids']));
[$st, $d] = si_rest('POST', '/support/mail-inbox/verifications/' . $v['id'] . '/done');
si_t('Bestätigung erledigt', $st === 200 && !$d['verifications'], $d['verifications'] ?? null);
// Testmail kommt weitergeleitet an (mit X-Tixomat-Support würde sie sonst als eigene Mail verworfen)
$r = TIX_Support_Inbound::deliver_local(si_mail("Club <mail@club.test>", $in, (string) ($tm['subject'] ?? ''), 'Test', ['X-Forwarded-To: ' . $in]), $in);
[$st, $d] = si_rest('GET', '/support/mail-inbox');
si_t('Testmail angekommen → Verbunden', $r['result'] === 'test_ok' && $d['alias']['status'] === 'connected' && $d['alias']['connected_at'] !== '', [$r, $d['alias']]);
si_t('Kontakt-Adresse jetzt in /support/config', TIX_Support_Inbound::contact_email() === "support$sfx@club.test" && (si_rest('GET', '/support/config')[1]['contact_email'] ?? '') === "support$sfx@club.test");
$r = TIX_Support_Inbound::deliver_local(si_mail("Neue Gästin <neu$sfx@example.test>", $in, 'Tisch für Samstag?', "Hallo,\nhabt ihr noch einen Tisch?"), $in);
$nt = intval($r['ticket'] ?? 0); $posts[] = $nt;
si_t('neue Mail an die eigene Adresse → neue Anfrage', $r['result'] === 'imported' && ($r['how'] ?? '') === 'new' && get_post_meta($nt, '_tix_sp_email', true) === "neu$sfx@example.test", $r);
si_t('Eingangsbestätigung an die Gästin', (bool) array_filter($GLOBALS['si_out'], function ($o) use ($sfx) { return ($o['to'] ?? '') === "neu$sfx@example.test" || (is_array($o['to'] ?? null) && in_array("neu$sfx@example.test", $o['to'], true)); }));
$r = TIX_Support_Inbound::deliver_local(si_mail("Newsletter <news@shop.test>", $in, 'Angebote', 'x', ['List-Unsubscribe: <https://x>']), $in);
si_t('Newsletter an die Adresse wird keine Anfrage', $r['result'] === 'skipped_auto', $r);

echo "── Einzel-Übernahme ──\n";
$fwd = si_mail("Team <mail@club.test>", $fw, 'WG: Ticket verloren', "Kannst du helfen?\n\n---------- Forwarded message ---------\nFrom: Max Muster <max$sfx@example.test>\nDate: Mon, 26 Oct 2026 at 10:00\nSubject: Ticket verloren\nTo: <mail@club.test>\n\nIch finde mein Ticket nicht mehr.\nGruß Max");
$r = TIX_Support_Inbound::deliver_local($fwd, $fw);
$ot = intval($r['ticket'] ?? 0); $posts[] = $ot;
$om = si_msgs($ot);
si_t('Gmail-Weiterleitung → Anfrage von Max', $r['result'] === 'imported' && get_post_meta($ot, '_tix_sp_email', true) === "max$sfx@example.test" && get_post_meta($ot, '_tix_sp_name', true) === 'Max Muster', $r);
si_t('Betreff + Text der Original-Mail', strpos(get_the_title($ot), 'Ticket verloren') !== false && strpos((string) ($om[0]['content'] ?? ''), 'Ich finde mein Ticket') !== false, [get_the_title($ot), $om[0]['content'] ?? null]);
si_t('Hinweis „Übernommen aus …“', (bool) array_filter($om, function ($x) { return ($x['type'] ?? '') === 'note' && strpos((string) $x['content'], 'Übernommen aus') !== false; }));
$ol = si_mail("Team <mail@club.test>", $fw, 'WG: Rechnung', "\r\n________________________________\r\nVon: Erika Beispiel <erika$sfx@example.test>\r\nGesendet: Montag, 26. Oktober 2026 10:00\r\nAn: mail@club.test\r\nBetreff: Rechnung fehlt\r\n\r\nBitte Rechnung schicken.");
$r = TIX_Support_Inbound::deliver_local($ol, $fw); $posts[] = intval($r['ticket'] ?? 0);
si_t('Outlook-Weiterleitung (deutsch) → Anfrage von Erika', $r['result'] === 'imported' && get_post_meta(intval($r['ticket']), '_tix_sp_email', true) === "erika$sfx@example.test", $r);
$innerMail = si_mail("Lea <lea$sfx@example.test>", 'mail@club.test', 'Gästeliste', 'Steh ich drauf?');
$b2 = 'r' . $sfx;
$att = "From: Team <mail@club.test>\r\nTo: $fw\r\nSubject: Fwd: Gästeliste\r\nMessage-ID: <fw$sfx@club.test>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b2\"\r\n\r\n--$b2\r\nContent-Type: text/plain\r\n\r\nSiehe Anhang\r\n--$b2\r\nContent-Type: message/rfc822\r\n\r\n$innerMail\r\n--$b2--\r\n";
$r = TIX_Support_Inbound::deliver_local($att, $fw); $posts[] = intval($r['ticket'] ?? 0);
si_t('als Anlage weitergeleitet → Anfrage von Lea', $r['result'] === 'imported' && get_post_meta(intval($r['ticket']), '_tix_sp_email', true) === "lea$sfx@example.test", $r);
$r = TIX_Support_Inbound::deliver_local(si_mail("Team <mail@club.test>", $fw, 'Hallo', 'Nur Text ohne Weiterleitung'), $fw);
si_t('ohne Weiterleitung: keine Anfrage', $r['result'] === 'skipped_invalid', $r);

echo "── Zustellung vom Hub (signiert) ──\n";
$raw = si_mail("gast$sfx@example.test", $ra, 'Re: Frage', 'Über den Hub');
$t0 = time();
$sig = TIX_Support_Inbound::sign_payload('geheim' . $sfx, $ra, $t0, $raw);
[$st, $d] = si_rest('POST', '/support/inbound/deliver', null, ['x-tix-rcpt' => $ra, 'x-tix-ts' => (string) $t0, 'x-tix-sig' => $sig, 'content-type' => 'message/rfc822'], $raw);
si_t('signiert: übernommen', $st === 200 && $d['result'] === 'imported', [$st, $d]);
[$st] = si_rest('POST', '/support/inbound/deliver', null, ['x-tix-rcpt' => $ra, 'x-tix-ts' => (string) $t0, 'x-tix-sig' => str_repeat('0', 64)], $raw . 'x');
si_t('falsche Signatur 403', $st === 403, $st);
$old = $t0 - 3600;
[$st] = si_rest('POST', '/support/inbound/deliver', null, ['x-tix-rcpt' => $ra, 'x-tix-ts' => (string) $old, 'x-tix-sig' => TIX_Support_Inbound::sign_payload('geheim' . $sfx, $ra, $old, $raw)], $raw);
si_t('alte Zeitmarke 403', $st === 403, $st);

echo "── Zentraler Eingang (Hub auf derselben Seite) ──\n";
delete_option(TIX_Support_Inbound::OPT_HUB_KEY);
[$st] = si_rest('POST', '/support/inbound', null, ['x-tix-rcpt' => $ra], $raw);
si_t('ohne Hub-Schlüssel 404', $st === 404, $st);
update_option(TIX_Support_Inbound::OPT_HUB_KEY, 'hubkey' . $sfx, false);
[$st] = si_rest('POST', '/support/inbound', null, ['x-tix-rcpt' => $ra, 'x-tix-inbound-key' => 'falsch'], $raw);
si_t('falscher Schlüssel 403', $st === 403, $st);
[$st, $d] = si_rest('POST', '/support/inbound', null, ['x-tix-rcpt' => $ra, 'x-tix-inbound-key' => 'hubkey' . $sfx, 'content-type' => 'message/rfc822'], si_mail("gast$sfx@example.test", $ra, 'Re: Frage', 'Postfix-Weg'));
si_t('Postfix-Weg (Rohmail): übernommen', $st === 200 && ($d['results'][0]['result'] ?? '') === 'imported', [$st, $d]);
[$st, $d] = si_rest('POST', '/support/inbound', null, ['x-tix-inbound-key' => 'hubkey' . $sfx], si_mail("gast$sfx@example.test", $ra, 'Re: Frage', 'Empfänger aus To'));
si_t('Empfänger aus To-Kopf', $st === 200 && ($d['results'][0]['result'] ?? '') === 'imported', $d);
$brevo = ['items' => [[
    'Uuid' => ['x'], 'MessageId' => "brevo$sfx@guest.test", 'InReplyTo' => null,
    'From' => ['Name' => 'Gast Test', 'Address' => "gast$sfx@example.test"], 'To' => [['Name' => null, 'Address' => $ra]], 'Recipients' => [$ra],
    'Subject' => 'Re: Frage zum Einlass', 'SentAtDate' => gmdate('D, d M Y H:i:s') . ' +0000', 'SpamScore' => 0.4,
    'RawTextBody' => "Brevo-Weg klappt\n\n" . TIX_Support_Mail::MARKER . "\nalt", 'RawHtmlBody' => '', 'Attachments' => [], 'Headers' => ['Auto-Submitted' => 'no'],
]]];
[$st, $d] = si_rest('POST', '/support/inbound', null, ['content-type' => 'application/json', 'authorization' => 'Bearer hubkey' . $sfx], wp_json_encode($brevo));
$m = si_msgs($tid); $last = end($m);
si_t('Brevo-Weg (JSON): übernommen + gekürzt', $st === 200 && ($d['results'][0]['result'] ?? '') === 'imported' && trim($last['content']) === 'Brevo-Weg klappt', [$st, $d, $last['content'] ?? null]);
$brevo['items'][0]['MessageId'] = "brevo2$sfx@guest.test"; $brevo['items'][0]['SpamScore'] = 9.1;
[$st, $d] = si_rest('POST', '/support/inbound?key=hubkey' . $sfx, null, ['content-type' => 'application/json'], wp_json_encode($brevo));
si_t('Brevo: Spam-Wert ≥ 5 verworfen', ($d['results'][0]['result'] ?? '') === 'skipped_auto', $d);
[$st, $d] = si_rest('POST', '/support/inbound', null, ['x-tix-rcpt' => 'zz-in-0000000000@support.evendis.de', 'x-tix-inbound-key' => 'hubkey' . $sfx], $raw);
si_t('unbekannte Seite: angenommen, verworfen', $st === 200 && ($d['results'][0]['result'] ?? '') === 'unknown', $d);
update_option(TIX_Support_Inbound::OPT_SITES, ['tot' => ['url' => 'http://127.0.0.1:9', 'secret' => 'x']], false);
[$st, $d] = si_rest('POST', '/support/inbound', null, ['x-tix-rcpt' => 'tot-in-0000000000@support.evendis.de', 'x-tix-inbound-key' => 'hubkey' . $sfx], $raw);
si_t('Seite nicht erreichbar → 503 (Postfix stellt erneut zu)', $st === 503, [$st, $d]);

// „Support aus“ prüft test-support-inbound-e2e.sh (tix_get_settings hält die Einstellungen im Prozess)
si_t('Protokoll gefüllt', count(TIX_Support_Inbound::log_entries()) > 5);

// Aufräumen
wp_set_current_user(0);
foreach (array_filter($posts) as $p) wp_delete_post($p, true);
foreach ($users as $u) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u); }
foreach (['opt' => TIX_Support_Inbound::OPT, 'hub' => TIX_Support_Inbound::OPT_HUB_KEY, 'sites' => TIX_Support_Inbound::OPT_SITES, 'tix' => 'tix_settings', 'mail' => TIX_Support_Mail::OPTION] as $k => $o) {
    if ($keep[$k] === null) delete_option($o); else update_option($o, $keep[$k]);
}
delete_option(TIX_Support_Inbound::LOG);
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
