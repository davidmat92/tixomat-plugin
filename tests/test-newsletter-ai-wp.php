<?php
/** Integrationstest KI-Generator für Newsletter (TIX_Newsletter_AI). Die KI wird über pre_http_request nachgebildet –
 *  es geht nichts ins Netz. wp eval-file …/tests/test-newsletter-ai-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Newsletter_AI')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function ai_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function ai_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Newsletter::maybe_create();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$opt_before = ['u' => get_option(TIX_Newsletter_AI::OPT_USAGE, null), 't' => get_option('tix_marketing_trial_until', null), 'c' => get_option('tix_coupons', null)];
delete_option(TIX_Newsletter_AI::OPT_USAGE); delete_option('tix_marketing_trial_until');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_nl\\_ai\\_%'");
wp_cache_flush();
$GLOBALS['ai_key'] = 'sk-ant-test';
add_filter('tix_nl_ai_api_key', function () { return $GLOBALS['ai_key']; });

$owner = wp_insert_user(['user_login' => "ai_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ai_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "ai_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ai_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'KI Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'KI Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$day = wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS);
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "KI Sommerparty $sfx", 'post_excerpt' => 'Die große Sommerparty unter freiem Himmel.', 'meta_input' => [
    '_tix_organizer_id' => $org, '_tix_date_start' => $day, '_tix_time_start' => '22:00', '_tix_time_doors' => '21:00', '_tix_location' => 'Stadthalle Testdorf', '_tix_address' => "Hauptstr. 1\n12345 Testdorf",
    '_tix_tickets_enabled' => '1', '_tix_ticket_categories' => [['name' => 'Regulär', 'price' => 15, 'qty' => 100], ['name' => 'VIP', 'price' => 39.5, 'qty' => 10]],
    '_tix_info_lineup' => "DJ Alpha\nDJ Beta", '_tix_timetable' => [$day => [['time' => '22:00', 'end' => '23:30', 'stage' => 0, 'title' => 'DJ Alpha'], ['time' => '23:30', 'end' => '01:00', 'stage' => 0, 'title' => 'DJ Beta']]],
    '_tix_gallery' => ['https://example.test/g1.jpg', 'https://example.test/g2.jpg']]]); $posts[] = $ev;
$ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "KI Herbstball $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS), '_tix_time_start' => '20:00']]); $posts[] = $ev2;
$foreign = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "KI Fremd $sfx", 'meta_input' => ['_tix_organizer_id' => $org2, '_tix_date_start' => $day]]); $posts[] = $foreign;
$coupons = is_array($opt_before['c']) ? $opt_before['c'] : [];
$CODE = "HERBST$sfx";
$coupons[$CODE] = ['code' => $CODE, 'discount_type' => 'percent', 'value' => 20, 'expires' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS), 'max_uses' => 0, 'used' => 0, 'organizer_id' => $org];
$coupons["ALT$sfx"] = ['code' => "ALT$sfx", 'discount_type' => 'fixed', 'value' => 5, 'expires' => '2020-01-01', 'max_uses' => 0, 'used' => 0, 'organizer_id' => $org];
if ($multi) $coupons["FREMD$sfx"] = ['code' => "FREMD$sfx", 'discount_type' => 'percent', 'value' => 10, 'expires' => '', 'max_uses' => 0, 'used' => 0, 'organizer_id' => $org2];
update_option('tix_coupons', $coupons);

// ── Nachbildung der KI: liefert Texte mit Absicht auch mit Verstößen ──
$GLOBALS['ai_mock'] = ['calls' => [], 'code' => 200];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'https://api.anthropic.com/') !== 0) return $pre;
    $b = json_decode((string) ($args['body'] ?? ''), true) ?: [];
    $GLOBALS['ai_mock']['calls'][] = $b;
    $GLOBALS['ai_mock']['key'] = (string) ($args['headers']['x-api-key'] ?? '');
    $J = function ($code, $body) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => 'x'], 'cookies' => [], 'filename' => null]; };
    if ($GLOBALS['ai_mock']['code'] !== 200) return $J($GLOBALS['ai_mock']['code'], ['error' => ['message' => 'kaputt']]);
    $drafts = [];
    foreach (['flyer', 'compact', 'story'] as $k) $drafts[] = ['key' => $k, 'badge' => 'Nur 2 Tage', 'headline' => 'Sommer, Sonne, Beats', 'intro' => 'Die Nacht des Jahres steht an. Tickets nur 9 € – schnell sein! Mehr auf www.example.test.',
        'blocks' => [['type' => 'text', 'heading' => 'Warum du kommen musst', 'text' => 'Zwei DJs, eine Bühne. Wie am Ballermann! Einlass ab 21 Uhr.'], ['type' => 'lineup'], ['type' => 'prices', 'text' => 'Regulär kostet 15 €, VIP 39,50 €.'],
            ['type' => 'gallery'], ['type' => 'foo'], ['type' => 'lineup'], ['type' => 'social']], 'closing' => 'Wir sehen uns am Samstag! Mit Code FAKE-99 gibt es 50 % extra.'];
    return $J(200, ['model' => $b['model'] ?? '', 'usage' => ['input_tokens' => 2000, 'output_tokens' => 1000],
        'content' => [['type' => 'tool_use', 'name' => 'newsletter', 'input' => [
            'subjects' => ['Die Sommerparty ruft ☀️', 'Nur noch 9 € – heute!', 'Am Ballermann feiern', 'Diese Nacht willst du nicht verpassen und deshalb ist dieser Betreff absichtlich viel zu lang', 'Beats unter freiem Himmel'],
            'preheaders' => ['Zwei DJs, eine Bühne, eine Nacht – sichere dir dein Ticket.', 'Einlass ab 21 Uhr, los geht es um 22 Uhr.', 'Infos auf https://example.test'],
            'drafts' => $drafts]]]]);
}, 10, 3);

// ═══ Rechte, Modul ═══
wp_set_current_user($owner);
if (!$multi) {
    [$st] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev]]);
    ai_eq('Ohne Modul gesperrt (Einzel-Seite)', 403, $st);
    ai_rest('POST', '/marketing/trial');
}
[$st, $s] = ai_rest('GET', '/marketing/newsletter/ai');
ai_eq('Status: verfügbar, Limit 30, Auswahl-Listen', [200, true, 30, 30, 8, 3], [$st, $s['available'] ?? null, $s['limit'] ?? null, $s['remaining'] ?? null, count((array) ($s['options']['goals'] ?? [])), count((array) ($s['options']['drafts'] ?? []))]);

// ═══ Eingaben ═══
[$st, $d, $code] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event']);
ai_eq('Event bewerben ohne Event → 400 event_ids', [400, 'event_ids'], [$st, $d['data']['field'] ?? '']);
[$st, $d, $code] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'quatsch', 'event_ids' => [$ev]]);
ai_eq('Unbekanntes Ziel → 400', 400, $st);
[$st, $d, $code] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'voucher']);
ai_eq('Gutschein-Aktion ohne Gutschein → 400 coupon', [400, 'coupon'], [$st, $d['data']['field'] ?? '']);
[$st, $d, $code] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev], 'coupon' => "ALT$sfx"]);
ai_eq('Abgelaufener Gutschein → 409 tix_coupon_invalid', [409, 'tix_coupon_invalid'], [$st, $code]);
[$st, $d, $code] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev], 'coupon' => 'GIBTSNICHT']);
ai_eq('Unbekannter Gutschein → 404', 404, $st);
if ($multi) {
    [$st] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$foreign]]);
    ai_eq('Fremdes Event → 404', 404, $st);
    [$st] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev], 'coupon' => "FREMD$sfx"]);
    ai_eq('Gutschein eines anderen Veranstalters → 404', 404, $st);
}
ai_eq('Fehlerhafte Eingaben rufen die KI nicht auf', 0, count($GLOBALS['ai_mock']['calls']));

// ═══ Erzeugen ═══
[$st, $r] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev], 'coupon' => strtolower($CODE), 'include' => ['lineup', 'prices', 'directions', 'gallery'], 'tone' => 'festive', 'note' => 'Bitte Sommer-Stimmung']);
ai_eq('Erzeugt: 200, drei Entwürfe', [200, ['flyer', 'compact', 'story']], [$st, array_column((array) ($r['drafts'] ?? []), 'key')]);
$call = $GLOBALS['ai_mock']['calls'][0] ?? [];
ai_eq('KI-Aufruf: Claude-Modell, Werkzeug erzwungen, Schlüssel', [TIX_Newsletter_AI::model(), 0, 'newsletter', 'sk-ant-test'], [$call['model'] ?? '', strpos((string) ($call['model'] ?? ''), 'claude'), $call['tool_choice']['name'] ?? '', $GLOBALS['ai_mock']['key'] ?? '']);
$msg = (string) ($call['messages'][0]['content'] ?? '');
ai_eq('An die KI gehen Event-Daten und Wünsche, keine Kundendaten', [true, true, true, false], [strpos($msg, "KI Sommerparty $sfx") !== false, strpos($msg, 'Sommer-Stimmung') !== false, strpos($msg, $CODE) !== false, strpos($msg, '@example.test') !== false]);
$subs = array_column((array) $r['subjects'], 'text');
ai_eq('Betreff: erfundener Preis und „Ballermann“ entfernt', ['Die Sommerparty ruft ☀️', 'Diese Nacht willst du nicht verpassen und deshalb ist dieser Betreff absichtlich viel zu lang', 'Beats unter freiem Himmel'], $subs);
ai_eq('Betreff: Zeichenzahl und Hinweis „zu lang“', [false, true, 60], [$r['subjects'][0]['too_long'], $r['subjects'][1]['too_long'], $r['subjects'][0]['max']]);
ai_eq('Vorschautexte: Link entfernt, richtige Uhrzeiten bleiben', ['Zwei DJs, eine Bühne, eine Nacht – sichere dir dein Ticket.', 'Einlass ab 21 Uhr, los geht es um 22 Uhr.'], array_column((array) $r['preheaders'], 'text'));
$fly = $r['drafts'][0]; $cmp = $r['drafts'][1]; $sto = $r['drafts'][2];
$c = (string) $fly['content'];
$url = get_permalink($ev);
ai_eq('Fakten aus den Daten: Datum, Uhrzeit, Ort, Code, Rabatt, Preise', [true, true, true, true, true, true, true],
    [strpos($c, TIX_Newsletter_AI::facts($ev)['date_label']) !== false, strpos($c, '22:00 Uhr') !== false, strpos($c, 'Stadthalle Testdorf') !== false, strpos($c, $CODE) !== false, strpos($c, '20 % Rabatt') !== false, strpos($c, '15 €') !== false, strpos($c, '39,50 €') !== false]);
ai_eq('Erfundenes entfernt: 9 €, Ballermann, Link, Fremdcode, 50 %', [false, false, false, false, false], [strpos($c, '9 €') !== false && strpos($c, '39') === false, stripos($c, 'ballermann') !== false, strpos($c, 'www.example.test') !== false, strpos($c, 'FAKE-99') !== false, strpos($c, '50 %') !== false]);
ai_eq('Erlaubte Sätze bleiben (Einlass 21 Uhr, Preise aus den Daten)', [true, true], [strpos($c, 'Einlass ab 21 Uhr') !== false, strpos($c, 'Regulär kostet 15 €, VIP 39,50 €.') !== false]);
ai_eq('Entfernte Stellen werden gezählt', true, intval($r['removed'] ?? 0) >= 8);
ai_eq('Line-up, Programm-freie Auswahl, Galerie mit alt-Text, Anfahrt mit Route', [true, true, true, 1], [strpos($c, 'DJ Alpha') !== false, strpos($c, 'alt="Bild von KI Sommerparty') !== false, strpos($c, 'google.com/maps/search') !== false, substr_count($c, 'DJ Beta')]);
ai_eq('Flyer: großes Bild nur ohne Event-Bild (hier keins) – kein kaputtes img', false, (bool) preg_match('#<img src=""#', $c));
ai_eq('Unbekannter Baustein ignoriert, Social ohne Links entfällt', [false, false], [strpos($c, 'foo') !== false, strpos($c, 'Instagram') !== false]);
ai_eq('Knopf auf die Event-Seite', true, strpos($c, esc_url(TIX_Customer_URL::to($url))) !== false || strpos($c, esc_url($url)) !== false);
ai_eq('Anrede mit Vorname aus Brevo', true, strpos($c, '{{ contact.FIRSTNAME | default: "du" }}') !== false);
ai_eq('Kompakt ohne Galerie, Story mit Zwischenüberschrift', [false, true], [strpos((string) $cmp['content'], 'Bild von') !== false, strpos((string) $sto['content'], 'Warum du kommen musst') !== false]);
$p = (string) $fly['html'];
ai_eq('Vorschau: Gerüst, Abmeldelink-Platz, Impressum-Hinweis, UTM, Name statt Platzhalter', [true, true, true, false],
    [strpos($p, '<!doctype html') !== false || stripos($p, '<html') !== false, strpos($p, 'Abmelden') !== false, strpos($p, 'utm_campaign=nl-0') !== false, strpos($p, 'contact.FIRSTNAME') !== false]);
$man = TIX_Newsletter::html(['id' => 6, 'organizer_id' => $org, 'subject' => 'Handbetreff', 'preheader' => '', 'content' => '<p>Hallo</p>']);
ai_eq('Nur eine Überschrift im KI-Entwurf, Handkampagne behält den Betreff als Titel', [1, 1, true], [substr_count($p, '<h1'), substr_count($man, '<h1'), strpos($man, 'Handbetreff</h1>') !== false]);
$pos = function ($x) use ($c) { $i = strpos($c, $x); return $i === false ? PHP_INT_MAX : $i; };
ai_eq('Event-Karte und Gutschein vor den übrigen Bausteinen', [true, true], [$pos('Jetzt Tickets sichern') < $pos('Line-up'), $pos($CODE) < $pos('Line-up')]);
$real = TIX_Newsletter::html(['id' => 5, 'organizer_id' => $org, 'subject' => 'x', 'preheader' => '', 'content' => wp_kses_post($c)]);
ai_eq('Übernahme in den Editor: wp_kses_post behält Knopf, Code und Abmeldelink im Versand', [true, true, true, true],
    [strpos($real, 'tm-btn') !== false, strpos($real, $CODE) !== false, strpos($real, '{{ unsubscribe }}') !== false, strpos($real, 'utm_campaign=nl-5') !== false]);
ai_eq('Text-Fassung mit Link in Klammern', [true, true], [strpos((string) $fly['text'], 'Stadthalle Testdorf') !== false, (bool) preg_match('/\(https?:\/\/[^)]+\)/', (string) $fly['text'])]);
ai_eq('Antwort: Fakten, Modell, Kosten, Zähler', [$ev, $CODE, TIX_Newsletter_AI::model(), 1, 29], [$r['facts']['events'][0]['id'] ?? 0, $r['facts']['coupon']['code'] ?? '', $r['model'] ?? '', $r['used_today'] ?? 0, $r['remaining'] ?? 0]);
ai_eq('Kosten geschätzt (2000 ein, 1000 aus)', round(TIX_Newsletter_AI::cost_eur(TIX_Newsletter_AI::model(), ['input_tokens' => 2000, 'output_tokens' => 1000]), 4), $r['cost_eur'] ?? 0);
ai_eq('Preis Sonnet: 2000/1000 Token ≈ 0,0193 €', 0.0193, round(TIX_Newsletter_AI::cost_eur('claude-sonnet-5-5', ['input_tokens' => 2000, 'output_tokens' => 1000]), 4));

// ═══ Teile neu erzeugen, Übersicht ═══
[$st, $r2] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev], 'parts' => ['subjects'], 'rephrase' => 'frecher']);
ai_eq('Nur Betreffzeilen: keine Entwürfe, keine Vorschautexte', [200, true, 0, 0], [$st, count($r2['subjects']) > 0, count($r2['preheaders']), count($r2['drafts'])]);
$m2 = (string) (end($GLOBALS['ai_mock']['calls'])['messages'][0]['content'] ?? '');
ai_eq('„Anders formulieren“ geht an die KI', true, strpos($m2, 'frecher') !== false);
[$st, $r3] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev], 'parts' => ['drafts'], 'draft' => 'story']);
ai_eq('Nur ein Entwurf neu', ['story'], array_column((array) $r3['drafts'], 'key'));
[$st, $r4] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'overview', 'event_ids' => [$ev, $ev2], 'parts' => ['drafts']]);
$c4 = (string) ($r4['drafts'][0]['content'] ?? '');
ai_eq('Übersicht: zwei Entwürfe, beide Events mit Link', [['flyer', 'compact'], true, true], [array_column((array) $r4['drafts'], 'key'), strpos($c4, "KI Herbstball $sfx") !== false, substr_count($c4, 'Tickets &amp; Infos') + substr_count($c4, 'Mehr erfahren') >= 2]);

// ═══ Fehler der KI, Limit, Protokoll ═══
$GLOBALS['ai_mock']['code'] = 500;
[$st, $d, $code] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev]]);
ai_eq('KI-Fehler → 503 ai_unavailable', [503, 'ai_unavailable'], [$st, $code]);
$GLOBALS['ai_mock']['code'] = 200;
$u = TIX_Newsletter_AI::usage($org);
ai_eq('Protokoll: 4 erfolgreiche Erzeugungen, Kosten und Token', [4, 8000, 4000, true], [$u['n'], $u['in'], $u['out'], $u['cost_eur'] > 0]);
$all = get_option(TIX_Newsletter_AI::OPT_USAGE);
$all[current_time('Y-m-d')][intval($org)]['n'] = 30;
update_option(TIX_Newsletter_AI::OPT_USAGE, $all, false);
$n = count($GLOBALS['ai_mock']['calls']);
[$st, $d, $code] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev]]);
ai_eq('Tageslimit 30 → 429 tix_nl_ai_limit ohne KI-Aufruf', [429, 'tix_nl_ai_limit', $n], [$st, $code, count($GLOBALS['ai_mock']['calls'])]);
[$st, $s] = ai_rest('GET', '/marketing/newsletter/ai');
ai_eq('Status nach Limit: 0 übrig, Kosten heute', [0, true], [$s['remaining'] ?? null, ($s['cost_today_eur'] ?? 0) >= 0]);
if ($multi) {
    wp_set_current_user($other);
    [$st, $s2] = ai_rest('GET', '/marketing/newsletter/ai');
    ai_eq('Limit gilt je Veranstalter (anderer hat noch 30)', 30, $s2['remaining'] ?? null);
    wp_set_current_user($owner);
}
$GLOBALS['ai_key'] = '';
$all[current_time('Y-m-d')][intval($org)]['n'] = 0; update_option(TIX_Newsletter_AI::OPT_USAGE, $all, false);
[$st, $d, $code] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev]]);
ai_eq('Ohne KI-Schlüssel → 503 ai_unavailable', [503, 'ai_unavailable'], [$st, $code]);
wp_set_current_user(0);
[$st] = ai_rest('POST', '/marketing/newsletter/ai', ['goal' => 'event', 'event_ids' => [$ev]]);
ai_eq('Ohne Anmeldung → 401', 401, $st);

// Aufräumen
wp_set_current_user(1);
foreach ($opt_before as $k => $v) { $name = ['u' => TIX_Newsletter_AI::OPT_USAGE, 't' => 'tix_marketing_trial_until', 'c' => 'tix_coupons'][$k]; if ($v === null) delete_option($name); else update_option($name, $v); }
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
