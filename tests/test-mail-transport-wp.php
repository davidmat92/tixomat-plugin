<?php
/** Mailversand wählbar (1.38.674): evendis | eigener Dienst (Brevo) | Server, Rückfall. wp eval-file …/tests/test-mail-transport-wp.php */
if (!defined('ABSPATH') || !class_exists('TIX_Mail_Transport')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function mt_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function mt_rest($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); return [$res->get_status(), json_decode(wp_json_encode($res->get_data()), true), wp_json_encode($res->get_data())]; }
echo (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$bk = [get_option(TIX_Mail_Transport::OPTION, null), get_option(TIX_Mail_Transport::STATE, null), get_option('tix_studio_brand', null)];
delete_option(TIX_Mail_Transport::OPTION); delete_option(TIX_Mail_Transport::STATE);
$setfrom = function ($from) { $b = get_option('tix_studio_brand', []); $b = is_array($b) ? $b : []; $b['mail_from'] = $from; $b['mail_from_name'] = 'Testclub'; $b['mail_reply_to'] = 'kontakt@testclub.test'; update_option('tix_studio_brand', $b, false); TIX_Mail_Layout::flush(); };
// Versand abfangen: eigener Dienst (send_override) und der normale Weg (pre_wp_mail 60 = „evendis“ bzw. Server)
$sent = []; $wp = [];
TIX_Mail_Transport::$send_override = function ($p, $cfg, $m) use (&$sent) { $sent[] = ['p' => $p, 'm' => $m, 'key' => $cfg['api_key']]; return $GLOBALS['mt_fail'] ? new WP_Error('tix_mail_provider_error', 'Brevo meldet 500.') : ['message_id' => '<abc@brevo>']; };
$catch = function ($r, $atts) use (&$wp) { if ($r !== null) return $r; $wp[] = ['from' => apply_filters('wp_mail_from', 'wordpress@x.test'), 'name' => apply_filters('wp_mail_from_name', 'WordPress'), 'to' => $atts['to']]; do_action('wp_mail_succeeded', $atts); return true; };
add_filter('pre_wp_mail', $catch, 60, 2);
$GLOBALS['mt_fail'] = false;

// ═══ Ohne Wahl: wie heute ═══
$setfrom('noreply@kitchenklub.test');
mt_eq('eigene Domain (KitchenKlub/Mallorca) → own/server, nicht gespeichert', ['own', 'server', false], TIX_Mail_Transport::choice());
$wp = []; wp_mail('gast@example.test', 'A', 'Text');
mt_eq('Server-Weg: Tixomat ändert den Absender nicht', 'noreply@kitchenklub.test', $wp[0]['from'] ?? null);
$setfrom('noreply@evendis.de');
mt_eq('noreply@evendis.de → evendis', ['evendis', null, false], TIX_Mail_Transport::choice());
$setfrom('noreply@xxl.evendis.de');
mt_eq('Übergangsadresse *.evendis.de → evendis', 'evendis', TIX_Mail_Transport::choice()[0]);
$wp = []; wp_mail('gast@example.test', 'A', 'Text');
mt_eq('evendis: Absender noreply@evendis.de mit Namen der Seite', ['noreply@evendis.de', 'Testclub'], [$wp[0]['from'] ?? null, $wp[0]['name'] ?? null]);

// ═══ REST ═══
wp_set_current_user(0);
[$st] = mt_rest('GET', '/studio/mail-transport');
mt_eq('ohne Anmeldung 401', 401, $st);
wp_set_current_user(intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));
[$st, $d] = mt_rest('GET', '/studio/mail-transport');
mt_eq('GET: evendis, Absender/Name/Antwort', [200, 'evendis', 'noreply@evendis.de', 'Testclub', 'kontakt@testclub.test', 'evendis'],
    [$st, $d['mode'], $d['evendis']['from_email'], $d['evendis']['from_name'], $d['evendis']['reply_to'], $d['effective']['via']]);
mt_eq('Anbieter-Liste: Brevo verfügbar, SMTP/Postmark folgen', [['brevo', true], ['smtp', false], ['postmark', false]], array_map(function ($p) { return [$p['key'], $p['available']]; }, $d['providers']));

foreach ([
    'Modus' => [['mode' => 'x'], 'mode'],
    'Dienst unbekannt' => [['mode' => 'own', 'provider' => 'gmail'], 'provider'],
    'Dienst folgt' => [['mode' => 'own', 'provider' => 'smtp'], 'provider'],
    'Schlüssel fehlt' => [['mode' => 'own', 'provider' => 'brevo', 'from_email' => 'a@b.test'], 'api_key'],
    'Schlüssel kurz' => [['mode' => 'own', 'provider' => 'brevo', 'api_key' => 'abc'], 'api_key'],
    'Absender fehlt' => [['mode' => 'own', 'provider' => 'brevo', 'api_key' => 'xkeysib-' . str_repeat('a', 40)], 'from_email'],
    'Absender falsch' => [['mode' => 'own', 'provider' => 'brevo', 'api_key' => 'xkeysib-' . str_repeat('a', 40), 'from_email' => 'kein'], 'from_email'],
] as $l => [$body, $field]) {
    delete_option(TIX_Mail_Transport::OPTION);
    [$st, $d] = mt_rest('POST', '/studio/mail-transport', $body);
    mt_eq("400 $l", [400, $field], [$st, $d['data']['field'] ?? null]);
}
mt_eq('nach Fehlern nichts gespeichert', [], TIX_Mail_Transport::stored());

$key = 'xkeysib-' . str_repeat('9', 40) . '-AbCd';
[$st, $d, $raw] = mt_rest('POST', '/studio/mail-transport', ['mode' => 'own', 'provider' => 'brevo', 'api_key' => $key, 'from_email' => 'tickets@kunde.test', 'from_name' => 'Kunde', 'reply_to' => 'hallo@kunde.test']);
mt_eq('eigener Dienst gespeichert', [200, 'own', 'brevo', true, '••••AbCd', 'tickets@kunde.test', 'untested'],
    [$st, $d['mode'], $d['provider'], $d['own']['brevo']['api_key_set'], $d['own']['brevo']['api_key_hint'], $d['effective']['from_email'], $d['status']['state']]);
mt_eq('Schlüssel nie in der Antwort, verschlüsselt gespeichert', [false, false, true],
    [strpos($raw, $key) !== false, strpos(wp_json_encode(get_option(TIX_Mail_Transport::OPTION)), $key) !== false, strpos((string) (TIX_Mail_Transport::stored()['brevo']['key_enc'] ?? ''), 'v1:') === 0]);
[$st, $d] = mt_rest('POST', '/studio/mail-transport', ['mode' => 'own', 'provider' => 'brevo', 'from_name' => 'Kunde GmbH']);
mt_eq('ohne api_key bleibt der Schlüssel', [200, true, 'Kunde GmbH'], [$st, $d['own']['brevo']['api_key_set'], $d['effective']['from_name']]);

// ═══ Versand über den eigenen Dienst ═══
$sent = []; $wp = [];
$ok = wp_mail(['Gast <gast@example.test>'], 'Deine Tickets', '<p>Hallo <b>Gast</b></p>', ['Content-Type: text/html; charset=UTF-8', 'Cc: chef@example.test', 'X-Tix-Kind: customer'], []);
$m = $sent[0]['m'] ?? [];
mt_eq('eigener Dienst: verschickt, WordPress-Weg nicht benutzt', [true, 1, 0, 'brevo', $key], [$ok, count($sent), count($wp), $sent[0]['p'] ?? null, $sent[0]['key'] ?? null]);
mt_eq('Nachricht: An, Cc, Absender, Betreff', [[['email' => 'gast@example.test', 'name' => 'Gast']], [['email' => 'chef@example.test']], 'tickets@kunde.test', 'Kunde GmbH', 'Deine Tickets'],
    [$m['to'] ?? null, $m['cc'] ?? null, $m['from_email'] ?? null, $m['from_name'] ?? null, $m['subject'] ?? null]);
mt_eq('HTML mit Layout und Text-Fassung, eigene Kopfzeile', [true, true, 'customer'], [strpos((string) ($m['html'] ?? ''), 'Hallo') !== false, trim((string) ($m['text'] ?? '')) !== '' && strpos((string) $m['text'], '<') === false, $m['headers']['X-Tix-Kind'] ?? null]);
mt_eq('Reply-To aus der Mail (Kontakt) vor der Dienst-Einstellung', 'kontakt@testclub.test', $m['reply_to']['email'] ?? null);
$sent = []; wp_mail('a@example.test', 'Text', 'nur Text');
mt_eq('Text-Mail: im Layout als HTML, Text-Fassung dabei', [true, true], [strpos((string) ($sent[0]['m']['html'] ?? ''), 'nur Text') !== false, strpos((string) ($sent[0]['m']['text'] ?? ''), 'nur Text') !== false]);
[$st, $d] = mt_rest('GET', '/studio/mail-transport');
mt_eq('Zustand ok nach Versand', ['ok', 0], [$d['status']['state'], $d['status']['fallbacks_24h']]);

// ═══ Ausfall → Rückfall auf evendis ═══
$GLOBALS['mt_fail'] = true; $sent = []; $wp = [];
$ok = wp_mail('gast@example.test', 'Code', 'Dein Code');
mt_eq('Ausfall: Mail geht über WordPress raus, Absender evendis', [true, 1, 1, 'noreply@evendis.de', 'Testclub'], [$ok, count($sent), count($wp), $wp[0]['from'] ?? null, $wp[0]['name'] ?? null]);
mt_eq('danach Absender wieder normal (nur für diese Mail)', 'x@y.test', apply_filters('wp_mail_from', 'x@y.test'));
[$st, $d] = mt_rest('GET', '/studio/mail-transport');
mt_eq('Studio: Rückfall mit Fehler', ['fallback', 1, 'tix_mail_provider_error'], [$d['status']['state'], $d['status']['fallbacks_24h'], $d['status']['last_error']['code'] ?? null]);
$GLOBALS['mt_fail'] = false;
wp_mail('gast@example.test', 'Wieder da', 'x');
[$st, $d] = mt_rest('GET', '/studio/mail-transport');
mt_eq('wieder ok, Zähler bleibt 24 h', ['ok', 1], [$d['status']['state'], $d['status']['fallbacks_24h']]);

// ═══ Testmail ═══
$sent = [];
[$st, $d] = mt_rest('POST', '/studio/mail-transport/test', ['to' => 'pruef@example.test']);
mt_eq('Testmail über Brevo', [200, 'brevo', 'pruef@example.test', '<abc@brevo>', 1], [$st, $d['via'] ?? null, $d['to'] ?? null, $d['message_id'] ?? null, count($sent)]);
$GLOBALS['mt_fail'] = true; $wp = [];
[$st, $d] = mt_rest('POST', '/studio/mail-transport/test', ['to' => 'pruef@example.test']);
mt_eq('Testmail ohne Rückfall: 502 mit Grund', [502, 'tix_mail_send_failed', 'tix_mail_provider_error', 0], [$st, $d['code'] ?? null, $d['data']['reason'] ?? null, count($wp)]);
$GLOBALS['mt_fail'] = false;
[$st] = mt_rest('POST', '/studio/mail-transport/test', ['to' => 'kein']);
mt_eq('Testmail: falsche Adresse 400', 400, $st);

// ═══ Zurück auf evendis ═══
[$st, $d] = mt_rest('POST', '/studio/mail-transport', ['mode' => 'evendis']);
mt_eq('evendis gewählt, Brevo-Daten bleiben für später', [200, 'evendis', true, true], [$st, $d['mode'], $d['saved'], $d['own']['brevo']['api_key_set']]);
$sent = []; $wp = []; wp_mail('gast@example.test', 'A', 'B');
mt_eq('evendis: kein eigener Dienst mehr', [0, 'noreply@evendis.de'], [count($sent), $wp[0]['from'] ?? null]);
[$st, $d] = mt_rest('POST', '/studio/mail-transport/test', ['to' => 'pruef@example.test']);
mt_eq('Testmail über evendis', [200, 'evendis', 'noreply@evendis.de'], [$st, $d['via'] ?? null, $d['from_email'] ?? null]);
$setfrom('noreply@kitchenklub.test');
mt_eq('ausdrücklich evendis gilt auch bei eigener Domain', 'evendis', TIX_Mail_Transport::choice()[0]);

// ═══ Andere Filter gewinnen (Demo, *.invalid) ═══
TIX_Mail_Transport::$send_override = function () use (&$sent) { $sent[] = 1; return ['message_id' => '']; };
update_option(TIX_Mail_Transport::OPTION, ['mode' => 'own', 'provider' => 'brevo', 'brevo' => ['key_enc' => TIX_Mail_Transport::encrypt($key), 'from_email' => 'a@kunde.test']], false);
$sent = []; $skip = function () { return true; }; add_filter('pre_wp_mail', $skip, 10);
wp_mail('a@example.test', 'x', 'y');
remove_filter('pre_wp_mail', $skip, 10);
mt_eq('früherer pre_wp_mail-Filter → eigener Dienst schickt nicht', 0, count($sent));

// Aufräumen
remove_filter('pre_wp_mail', $catch, 60);
TIX_Mail_Transport::$send_override = null;
foreach ([[TIX_Mail_Transport::OPTION, $bk[0]], [TIX_Mail_Transport::STATE, $bk[1]], ['tix_studio_brand', $bk[2]]] as [$k, $v]) { if ($v === null) delete_option($k); else update_option($k, $v, false); }
TIX_Mail_Layout::flush();
echo ($GLOBALS['t_fails'] ? 'ROT ' : 'GRÜN ') . $GLOBALS['t_count'] . ' Prüfungen, ' . $GLOBALS['t_fails'] . " Fehler\n";
