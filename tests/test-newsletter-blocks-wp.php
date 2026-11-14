<?php
/** Baukasten für Newsletter (TIX_Newsletter_Blocks, 1.38.654). wp eval-file …/tests/test-newsletter-blocks-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Newsletter_Blocks')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function nb_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function nb_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Newsletter::maybe_create();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $atts = [];
$before = ['t' => get_option('tix_marketing_trial_until', null), 'c' => get_option('tix_coupons', null), 'tpl' => get_option(TIX_Newsletter_Blocks::OPT_TPL, null)];
delete_option('tix_marketing_trial_until');
$owner = wp_insert_user(['user_login' => "nb_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "nb_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "nb_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "nb_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'NB Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'NB Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$mkev = function ($t, $o, $days) use (&$posts) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "NB $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS), '_tix_time_start' => '21:00', '_tix_location' => 'Halle Alt',
    '_tix_tickets_enabled' => '1', '_tix_ticket_categories' => [['name' => 'Regulär', 'price' => 12, 'qty' => 50]], '_tix_info_lineup' => "DJ Eins\nDJ Zwei"]]); $posts[] = $id; return $id; };
$ev = $mkev("Sommer $sfx", $org, 6); $ev2 = $mkev("Herbst $sfx", $org, 0); $evf = $mkev("Fremd $sfx", $org2, 0); // heute: vorn in „kommende“ (Limit 6), unabhängig vom Datenbestand
$coupons = is_array($before['c']) ? $before['c'] : [];
$coupons["NB$sfx"] = ['code' => "NB$sfx", 'discount_type' => 'percent', 'value' => 15, 'expires' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS), 'max_uses' => 0, 'used' => 0, 'organizer_id' => $org];
$coupons["ALTNB$sfx"] = ['code' => "ALTNB$sfx", 'discount_type' => 'percent', 'value' => 5, 'expires' => '2020-01-01', 'max_uses' => 0, 'used' => 0, 'organizer_id' => $org];
update_option('tix_coupons', $coupons);
wp_set_current_user($owner);
if (!$multi) nb_rest('POST', '/marketing/trial');

// ═══ Prüfen ═══
$e = TIX_Newsletter_Blocks::sanitize([['type' => 'zauber']]);
nb_eq('Unbekannter Block → Fehler am Feld', 'blocks.0.type', is_wp_error($e) ? $e->get_error_data()['field'] : '');
$e = TIX_Newsletter_Blocks::sanitize([['type' => 'button', 'label' => 'Los', 'url' => 'javascript:alert(1)']]);
nb_eq('Knopf mit javascript:-Link → Fehler', 'blocks.0.url', is_wp_error($e) ? $e->get_error_data()['field'] : '');
$e = TIX_Newsletter_Blocks::sanitize([['type' => 'columns', 'left' => [['type' => 'event', 'event_id' => $ev]], 'right' => []]]);
nb_eq('In Spalten nur einfache Blöcke', 'blocks.0.left.0.type', is_wp_error($e) ? $e->get_error_data()['field'] : '');
$ok = TIX_Newsletter_Blocks::sanitize([['type' => 'text', 'html' => '<p onclick="x()">Hi <script>alert(1)</script><strong>fett</strong> <a href="https://x.test" onmouseover="y">L</a></p>']]);
nb_eq('Text: nur erlaubte Auszeichnung (kein Script, keine Ereignisse)', [false, false, true], [strpos($ok[0]['html'], '<script') !== false, strpos($ok[0]['html'], 'onclick') !== false || strpos($ok[0]['html'], 'onmouseover') !== false, strpos($ok[0]['html'], '<strong>fett</strong>') !== false]);
$v = TIX_Newsletter_Blocks::sanitize([['type' => 'video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'alt' => 'Trailer']]);
nb_eq('Video: Vorschaubild von YouTube automatisch', 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $v[0]['src'] ?? '');

// ═══ Kampagne mit Blöcken ═══
$blocks = [
    ['type' => 'heading', 'text' => 'Hallo {Vorname|Gast}!', 'level' => 1, 'badge' => 'Neu'],
    ['type' => 'text', 'html' => '<p>Schön, dass du da bist.</p>', 'style' => ['bg' => '#FFF4E5', 'padding' => 'm']],
    ['type' => 'image', 'src' => 'https://example.test/flyer.jpg', 'alt' => '', 'link' => 'https://example.test/'],
    ['type' => 'event', 'event_id' => $ev, 'show_image' => false],
    ['type' => 'coupon', 'code' => strtolower("NB$sfx"), 'event_id' => $ev],
    ['type' => 'coupon', 'code' => "ALTNB$sfx"],
    ['type' => 'lineup', 'event_id' => $ev],
    ['type' => 'button', 'label' => 'Alle Events', 'url' => 'https://example.test/events/', 'color' => '#00AA55'],
    ['type' => 'columns', 'left' => [['type' => 'heading', 'text' => 'Links', 'level' => 2]], 'right' => [['type' => 'text', 'html' => '<p>Rechts</p>']]],
    ['type' => 'quote', 'text' => 'Beste Party!', 'author' => 'Gast'],
    ['type' => 'events', 'mode' => 'upcoming', 'limit' => 6],
    ['type' => 'divider'], ['type' => 'spacer', 'height' => 30],
];
[$st, $r] = nb_rest('POST', '/marketing/newsletter/campaigns', ['name' => 'Baukasten', 'subject' => 'Test', 'blocks' => $blocks]);
$cid = intval($r['campaign']['id'] ?? 0);
[$st, $g] = nb_rest('GET', "/marketing/newsletter/campaigns/$cid");
$c = $g['campaign'] ?? [];
nb_eq('Kampagne speichert Blöcke (mit IDs), editor = blocks', [200, 'blocks', count($blocks), true], [$st, $c['editor'] ?? '', count((array) ($c['blocks'] ?? [])), !empty($c['blocks'][0]['id'])]);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Newsletter::tk() . " WHERE id = %d", $cid), ARRAY_A);
$html = TIX_Newsletter::html($row);
nb_eq('Rendern: Vorname mit Ersatztext, Abzeichen, Hintergrund, Knopffarbe, Zitat, Spalten', [true, true, true, true, true, true],
    [strpos($html, '{{ contact.FIRSTNAME | default: "Gast" }}') !== false, strpos($html, '>Neu<') !== false, strpos($html, '#FFF4E5') !== false, strpos($html, '#00AA55') !== false, strpos($html, 'Beste Party!') !== false, strpos($html, '>Links<') !== false]);
nb_eq('Event-Karte und Line-up aus den Daten, Gutschein mit echtem Code, abgelaufener entfällt', [true, true, true, true, false],
    [strpos($html, 'Halle Alt') !== false, strpos($html, 'DJ Eins') !== false, strpos($html, "NB$sfx") !== false, strpos($html, '15 % Rabatt') !== false, strpos($html, "ALTNB$sfx") !== false]);
nb_eq('Event-Liste „kommende“: eigene Events, fremde nicht', [true, $multi ? false : true], [strpos($html, "NB Herbst $sfx") !== false, strpos($html, "NB Fremd $sfx") !== false]);
nb_eq('Kein Betreff-Titel über eigenem Kopf, kein „<!--“ im Knopf, Abmeldelink, UTM', [1, false, true, true], [substr_count($html, '<h1'), (bool) preg_match('#<!--(?!\[if)#', preg_replace('#<!--\[if[^>]*>.*?<!\[endif\]-->#s', '', strstr($html, '<body') ?: $html)), strpos($html, '{{ unsubscribe }}') !== false, strpos($html, 'utm_campaign=nl-' . $cid) !== false]);
update_post_meta($ev, '_tix_location', 'Halle Neu');
$html2 = TIX_Newsletter::html($row);
nb_eq('Beim Versand frisch gerendert (neuer Ort im Event, die anderen Events behalten ihren)', [true, true], [strpos($html2, 'Halle Neu') !== false, substr_count($html2, 'Halle Alt') < substr_count($html, 'Halle Alt')]);
// Alte Kampagne
$wpdb->insert(TIX_Newsletter::tk(), ['organizer_id' => $org, 'name' => 'Alt', 'subject' => 'Alt', 'preheader' => '', 'content' => '<p>Alter Inhalt</p>', 'audience' => 'contacts', 'status' => 'draft', 'created' => current_time('mysql', true), 'updated' => current_time('mysql', true)]);
$old = intval($wpdb->insert_id);
[$st, $go] = nb_rest('GET', "/marketing/newsletter/campaigns/$old");
nb_eq('Alte Kampagne: editor = html, ein HTML-Block mit dem Inhalt', ['html', 'html', '<p>Alter Inhalt</p>'], [$go['campaign']['editor'] ?? '', $go['campaign']['blocks'][0]['type'] ?? '', $go['campaign']['blocks'][0]['html'] ?? '']);
[$st, $gu] = nb_rest('POST', "/marketing/newsletter/campaigns/$old", ['blocks' => [['type' => 'html', 'html' => '<p>Alter Inhalt</p>'], ['type' => 'text', 'html' => '<p>Neu</p>']]]);
[$st, $go2] = nb_rest('GET', "/marketing/newsletter/campaigns/$old");
nb_eq('… im Baukasten weiter bearbeitet → editor = blocks', ['blocks', 2], [$go2['campaign']['editor'] ?? '', count((array) ($go2['campaign']['blocks'] ?? []))]);
[$st, $du] = nb_rest('POST', "/marketing/newsletter/campaigns/$cid/duplicate");
$dup = intval($du['campaign']['id'] ?? 0);
nb_eq('Duplizieren: Entwurf „Kopie von …“ mit gleichen Blöcken', [201, 'Kopie von Baukasten', count($blocks), 'draft'], [$st, $du['campaign']['name'] ?? '', count((array) ($du['campaign']['blocks'] ?? [])), $du['campaign']['status'] ?? '']);

// ═══ Vorschau und Prüfhinweise ═══
[$st, $pv] = nb_rest('POST', '/marketing/newsletter/render', ['subject' => 'JETZT KLICKEN UND GEWINNEN!!! Die beste Party des ganzen Jahres wartet', 'blocks' => $blocks]);
$codes = array_column((array) ($pv['warnings'] ?? []), 'code');
nb_eq('Live-Vorschau: Vorname als Ersatztext, Prüfhinweise (Betreff lang, Alt-Text fehlt, Spam)', [200, true, true, true, true, true],
    [$st, strpos((string) ($pv['html'] ?? ''), 'Hallo Gast!') !== false, in_array('subject_long', $codes, true), in_array('alt_missing', $codes, true), in_array('spam_word', $codes, true) || in_array('spam_style', $codes, true), ($pv['size_kb'] ?? 0) > 0]);
[$st, $pe] = nb_rest('POST', '/marketing/newsletter/render', ['blocks' => [['type' => 'heading', 'text' => '']]]);
nb_eq('Vorschau mit ungültigem Block → 400 am Feld', [400, 'blocks.0.text'], [$st, $pe['data']['field'] ?? '']);

// ═══ Mediathek ═══
$img = imagecreatetruecolor(2000, 1000); imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 60)); ob_start(); imagejpeg($img, null, 90); $jpg = ob_get_clean();
$pngi = imagecreatetruecolor(400, 400); imagesavealpha($pngi, true); imagefill($pngi, 0, 0, imagecolorallocatealpha($pngi, 0, 0, 0, 127)); ob_start(); imagepng($pngi); $png = ob_get_clean();
[$st, $up] = nb_rest('POST', '/marketing/newsletter/media', ['data' => 'data:image/jpeg;base64,' . base64_encode($jpg), 'filename' => 'Flyer Groß.jpg', 'alt' => 'Roter Flyer']);
$m1 = $up['item'] ?? []; $atts[] = intval($m1['id'] ?? 0);
nb_eq('Upload: auf 1200 px verkleinert, JPEG, Alt-Text, öffentliche URL', [201, 1200, 600, true, 'Roter Flyer', true], [$st, $m1['width'] ?? 0, $m1['height'] ?? 0, (bool) preg_match('/\.jpg$/', (string) ($m1['url'] ?? '')), $m1['alt'] ?? '', strpos((string) ($m1['url'] ?? ''), '/tix-newsletter/') !== false]);
[$st, $up2] = nb_rest('POST', '/marketing/newsletter/media', ['data' => base64_encode($png), 'filename' => 'logo.png', 'crop' => ['x' => 0, 'y' => 0, 'w' => 200, 'h' => 100]]);
$m2 = $up2['item'] ?? []; $atts[] = intval($m2['id'] ?? 0);
nb_eq('PNG bleibt PNG (Transparenz), Zuschnitt 200×100', [201, true, 200, 100], [$st, (bool) preg_match('/\.png$/', (string) ($m2['url'] ?? '')), $m2['width'] ?? 0, $m2['height'] ?? 0]);
[$st, $bad, $c] = nb_rest('POST', '/marketing/newsletter/media', ['data' => base64_encode('keinbild-keinbild-keinbild'), 'filename' => 'x.jpg']);
nb_eq('Kein Bild → 400', [400, 'file'], [$st, $bad['data']['field'] ?? '']);
[$st, $lib] = nb_rest('GET', '/marketing/newsletter/media');
nb_eq('Mediathek: beide Bilder', [200, true], [$st, count(array_intersect(array_column((array) ($lib['items'] ?? []), 'id'), array_filter($atts))) === 2]);
set_post_thumbnail($ev, intval($m1['id'] ?? 0));
update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + 3650 * DAY_IN_SECONDS)); // Flyer-Quelle zeigt die 60 spätesten Events – Testevent nach vorn, unabhängig vom Datenbestand
[$st, $fl] = nb_rest('GET', '/marketing/newsletter/media', ['source' => 'flyers']);
nb_eq('Quelle Flyer: Event-Flyer mit Titel als Alt-Text', [200, true], [$st, in_array($ev, array_column((array) ($fl['items'] ?? []), 'event_id'), true)]);
[$st] = nb_rest('POST', '/marketing/newsletter/media/' . intval($m2['id'] ?? 0), ['alt' => 'Logo']);
nb_eq('Alt-Text nachtragen', 'Logo', (string) get_post_meta(intval($m2['id'] ?? 0), '_wp_attachment_image_alt', true));
if ($multi) {
    wp_set_current_user($other);
    [$st, $lib2] = nb_rest('GET', '/marketing/newsletter/media');
    [$st2] = nb_rest('DELETE', '/marketing/newsletter/media/' . intval($m2['id'] ?? 0));
    nb_eq('Anderer Veranstalter: sieht die Mediathek nicht, darf nicht löschen', [0, 404], [count((array) ($lib2['items'] ?? [])), $st2]);
    wp_set_current_user($owner);
}
[$st] = nb_rest('DELETE', '/marketing/newsletter/media/' . intval($m2['id'] ?? 0));
nb_eq('Eigenes Bild löschen', [200, false], [$st, (bool) get_post(intval($m2['id'] ?? 0))]);

// ═══ Vorlagen ═══
[$st, $T] = nb_rest('GET', '/marketing/newsletter/templates');
nb_eq('Mitgelieferte Vorlagen: 5', [200, ['event', 'overview', 'voucher', 'thanks', 'app']], [$st, array_column((array) ($T['builtin'] ?? []), 'key')]);
$tb = TIX_Newsletter_Blocks::sanitize($T['builtin'][1]['blocks'] ?? []);
nb_eq('Vorlage ist gültig (lässt sich so speichern)', false, is_wp_error($tb));
[$st, $ts] = nb_rest('POST', '/marketing/newsletter/templates', ['name' => 'Meine Vorlage', 'campaign_id' => $cid]);
$tk = (string) ($ts['template']['key'] ?? '');
[$st2, $T2] = nb_rest('GET', '/marketing/newsletter/templates');
nb_eq('Kampagne als Vorlage speichern', [201, 1, count($blocks)], [$st, count((array) ($T2['own'] ?? [])), count((array) ($T2['own'][0]['blocks'] ?? []))]);
[$st] = nb_rest('DELETE', "/marketing/newsletter/templates/$tk");
nb_eq('Vorlage löschen', 200, $st);

// Aufräumen
wp_set_current_user(1);
foreach (array_filter([$cid, $old, $dup]) as $id) $wpdb->delete(TIX_Newsletter::tk(), ['id' => $id]);
foreach (array_filter($atts) as $a) wp_delete_attachment($a, true);
foreach ($before as $k => $v) { $n = ['t' => 'tix_marketing_trial_until', 'c' => 'tix_coupons', 'tpl' => TIX_Newsletter_Blocks::OPT_TPL][$k]; if ($v === null) delete_option($n); else update_option($n, $v); }
if ($org) { delete_post_meta($org, TIX_Newsletter_Blocks::META_TPL); }
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
