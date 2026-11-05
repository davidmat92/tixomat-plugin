<?php
/** KI-Newsletter mit mehreren Events (1.38.658): Line-up je Event, Datumsfolge, Teaser je Event, Varianten, Anlass.
 *  Fake-KI für 1, 3 und 8 Events, jeweils mit Line-up an/aus. wp eval-file …/tests/test-newsletter-ai-multi-wp.php (beide Modi) */
if (!defined('ABSPATH') || !class_exists('TIX_Newsletter_AI')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function am_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function am_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$before = ['u' => get_option(TIX_Newsletter_AI::OPT_USAGE, null), 't' => get_option('tix_marketing_trial_until', null)];
delete_option(TIX_Newsletter_AI::OPT_USAGE); delete_option('tix_marketing_trial_until');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_nl\\_ai\\_%'"); wp_cache_flush();
add_filter('tix_nl_ai_api_key', function () { return 'sk-ant-test'; });
add_filter('tix_nl_ai_daily_limit', function () { return 1000; });
$owner = wp_insert_user(['user_login' => "am_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "am_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$org = 0;
if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AM Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org; }
// 8 Events, absichtlich nicht in Datumsfolge angelegt; Event i hat i+2 Acts (Event 6: 12 Acts → „und 4 weitere“)
$evs = []; $acts = [];
foreach ([5, 2, 7, 0, 3, 6, 1, 4] as $i) {
    $day = wp_date('Y-m-d', time() + (10 + $i * 3) * DAY_IN_SECONDS);
    $names = []; for ($a = 0; $a < ($i === 6 ? 12 : $i + 2); $a++) $names[] = "Act$i-$a $sfx";
    $tt = [$day => [['time' => '22:00', 'end' => '23:00', 'stage' => 0, 'title' => $names[0]]]];
    $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "AM Party $i $sfx", 'post_excerpt' => "Beschreibung $i", 'meta_input' => [
        '_tix_organizer_id' => $org, '_tix_date_start' => $day, '_tix_time_start' => '21:00', '_tix_location' => "Halle $i", '_tix_tickets_enabled' => '1',
        '_tix_ticket_categories' => [['name' => 'Regulär', 'price' => 10 + $i, 'qty' => 50]], '_tix_info_lineup' => implode("\n", $names), '_tix_timetable' => $tt]]);
    $posts[] = $id; $evs[$i] = $id; $acts[$i] = $names;
}
ksort($evs);
// Fake-KI: Teaser je Event (aus den gesendeten Daten), Betreff mit Anlass
$GLOBALS['am_calls'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'https://api.anthropic.com/') !== 0) return $pre;
    $b = json_decode((string) $args['body'], true);
    $GLOBALS['am_calls'][] = $b;
    preg_match('/Daten \(JSON\):\n(.*)$/s', (string) $b['messages'][0]['content'], $m);
    $data = json_decode($m[1] ?? '{}', true);
    $ids = array_column((array) ($data['events'] ?? []), 'id');
    $n = count($ids);
    $drafts = [];
    foreach ((array) ($data['liefere']['entwuerfe'] ?? []) as $k) $drafts[] = ['key' => $k, 'headline' => $n > 1 ? "$n Partys" : 'Eine Party', 'intro' => 'Hier ist, was ansteht.',
        'blocks' => [['type' => 'lineup'], ['type' => 'events'], ['type' => 'text', 'text' => 'Hier ist, was ansteht.']],
        'events' => array_map(function ($id) use ($k) { return ['id' => $id, 'teaser' => "Teaser $k für #$id. Zweiter Satz."]; }, $ids), 'closing' => 'Bis bald!'];
    return ['headers' => [], 'body' => wp_json_encode(['model' => $b['model'], 'usage' => ['input_tokens' => 100, 'output_tokens' => 100], 'content' => [['type' => 'tool_use', 'name' => 'newsletter',
        'input' => ['subjects' => [$n > 1 ? "$n Partys im " . ($data['zeitraum'] ?? '') : 'Eine Party'], 'preheaders' => ['Alles auf einen Blick.'], 'drafts' => $drafts]]]]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);
wp_set_current_user($owner);
if (!$multi) am_rest('POST', '/marketing/trial');
$pos = function ($html, $needle) { $p = strpos($html, $needle); return $p === false ? -1 : $p; };

foreach ([1, 3, 8] as $count) foreach ([true, false] as $with) {
    $pick = array_slice($evs, 0, $count, true); // Indizes 0..count-1 = Datumsfolge
    $ids = array_values($pick);
    $shuffled = array_reverse($ids); // absichtlich falsche Reihenfolge senden
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_nl\\_ai\\_%'"); wp_cache_flush(); // Anfragebremse (6/min) für den Test
    [$st, $r] = am_rest('POST', '/marketing/newsletter/ai', ['goal' => $count > 1 ? 'overview' : 'event', 'event_ids' => $shuffled, 'include' => $with ? ['lineup', 'prices'] : ['prices']]);
    $lbl = "$count Event(s), Line-up " . ($with ? 'an' : 'aus');
    am_eq("$lbl: 200, drei Entwürfe", [200, ['flyer', 'compact', 'story']], [$st, array_column((array) ($r['drafts'] ?? []), 'key')]);
    $call = end($GLOBALS['am_calls']);
    preg_match('/Daten \(JSON\):\n(.*)$/s', (string) $call['messages'][0]['content'], $m); $data = json_decode($m[1] ?? '{}', true);
    am_eq("$lbl: KI bekommt alle Events mit id und vollem Line-up", [$count, true], [count((array) ($data['events'] ?? [])), count((array) ($data['events'][$count - 1]['line_up'] ?? [])) === count($acts[$count - 1])]);
    foreach ($r['drafts'] ?? [] as $dr) {
        $c = (string) $dr['content']; $k = $dr['key'];
        // Datumsfolge der Event-Karten
        $ps = array_map(function ($i) use ($c, $sfx, $pos) { return $pos($c, "AM Party $i $sfx"); }, array_keys($pick));
        $sorted = $ps; sort($sorted);
        am_eq("$lbl/$k: alle Events in Datumsfolge", [true, $sorted], [!in_array(-1, $ps, true), $ps]);
        if ($count > 1) {
            // Teaser je Event genau einmal, beim richtigen Event
            $ok = true; foreach ($ids as $j => $id) { if (substr_count($c, "#$id") !== 1) $ok = false; }
            am_eq("$lbl/$k: Teaser je Event genau einmal, Text der Einleitung nicht doppelt", [true, 1], [$ok, substr_count($c, 'Hier ist, was ansteht.')]);
            am_eq("$lbl/$k: Knopf je Event", true, substr_count($c, 'class="tm-btn"') >= $count);
            if ($k === 'compact') am_eq("$lbl/compact: Teaser nur ein Satz, Karten ohne Bild", false, strpos($c, 'Zweiter Satz') !== false);
            else am_eq("$lbl/$k: Teaser mit zweitem Satz", true, strpos($c, 'Zweiter Satz') !== false);
        }
        if ($with) {
            // Line-up je Event: alle Acts beim eigenen Event, vor dem nächsten Event
            $okl = true;
            $order = array_keys($pick);
            foreach ($order as $j => $i) {
                $start = $pos($c, "AM Party $i $sfx");
                $end = isset($order[$j + 1]) ? $pos($c, "AM Party {$order[$j + 1]} $sfx") : strlen($c);
                foreach ($acts[$i] as $a) { $p = $pos($c, $a); if ($p < $start || $p > $end) $okl = false; }
            }
            am_eq("$lbl/$k: Line-up je Event, jeder Act beim eigenen Event", true, $okl);
            if ($count > 1) am_eq("$lbl/$k: Line-up-Überschrift mit Eventtitel", true, strpos($c, 'Line-up · AM Party') !== false);
        } else {
            am_eq("$lbl/$k: ohne Line-up keine Acts außer im Programm-Titel", false, strpos($c, 'Act' . (array_keys($pick)[0]) . '-1 ') !== false);
        }
        // Preise je Event
        $okp = true; foreach (array_keys($pick) as $i) if (strpos($c, (10 + $i) . ' €') === false) $okp = false;
        am_eq("$lbl/$k: Preise je Event", true, $okp);
    }
    if ($count > 1) am_eq("$lbl: Betreff nennt den Anlass mit Zeitraum", true, (bool) preg_match('/^' . $count . ' Partys im [A-ZÄÖÜ][a-zäöü]+/u', (string) ($r['subjects'][0]['text'] ?? '')));
}
// 8 Events mit Line-up: Event 6 hat 12 Acts → 8 im Raster, „und 4 weitere“ (flyer/story), compact 4 + „und 8 weitere“
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_nl\\_ai\\_%'"); wp_cache_flush();
[$st, $r] = am_rest('POST', '/marketing/newsletter/ai', ['goal' => 'overview', 'event_ids' => array_values($evs), 'include' => ['lineup']]);
$byk = array_column((array) $r['drafts'], 'content', 'key');
am_eq('Viele Acts: Top 8 mit Foto bzw. Initiale, dann „und 4 weitere“ (compact: Top 4, „und 8 weitere“)', [true, true], [strpos($byk['flyer'], 'und 4 weitere') !== false, strpos($byk['compact'], 'und 8 weitere') !== false]);
am_eq('Uhrzeit aus dem Programm am Act', true, strpos($byk['story'], '22:00 Uhr') !== false);
am_eq('Prompt: Teaser je Event und Anlass im Betreff', [true, true], [strpos((string) end($GLOBALS['am_calls'])['system'], 'drafts[].events') !== false, strpos((string) end($GLOBALS['am_calls'])['system'], '3 Partys im November') !== false]);
am_eq('Zeitraum für die KI', true, (bool) preg_match('/^[A-ZÄÖÜ][a-zäöü]+( \d{4})?( bis [A-ZÄÖÜ][a-zäöü]+)? \d{4}$/u', TIX_Newsletter_AI::period_label(array_map([TIX_Newsletter_AI::class, 'facts'], array_values($evs)))));

// Aufräumen
wp_set_current_user(1);
foreach ($before as $k => $v) { $n = $k === 'u' ? TIX_Newsletter_AI::OPT_USAGE : 'tix_marketing_trial_until'; if ($v === null) delete_option($n); else update_option($n, $v); }
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
