<?php
/**
 * Zwei-Seiten-Test Live-Befund #1171/#6453 (1.38.715): Co auf dem Spiegel → an die Quelle weitergereicht, beide Seiten gleich,
 * Status-Abgleich „eingeladen“, Bereinigung alter Teil-A-Co auf Spiegeln. Echtes HTTP zwischen kk (Quelle) und plat.
 *   kk   eval-file … setup-kk                          → JSON {sid}
 *   plat eval-file … setup-plat <kk-host> <key_in> <key_out> <sid>  → JSON {mir, mdj, x, y}
 *   kk   eval-file … link-kk <sid> <mir> <mdj>         (KK kennt MDJ als Co, wie live)
 *   plat eval-file … run-plat <json>                   (Admin fügt X auf dem Spiegel hinzu)
 *   kk   eval-file … check-kk <sid> <x>
 *   plat eval-file … status-plat <json>  ·  kk eval-file … status-kk <sid>
 *   plat eval-file … repair-plat <json>  ·  kk eval-file … check-kk <sid> <y>
 *   kk   eval-file … cleanup-kk  ·  plat eval-file … cleanup-plat
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Remote')) { echo "Tixomat nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = (string) ($args[0] ?? '');
global $wpdb;
$GLOBALS['cm_f'] = 0; $GLOBALS['cm_n'] = 0;
function me_eq($label, $exp, $act) {
    $GLOBALS['cm_n']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['cm_f']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function me_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
$done = function () { echo ($GLOBALS['cm_f'] ? "FEHLER: {$GLOBALS['cm_f']}" : 'ALLES OK') . " ({$GLOBALS['cm_n']} Prüfungen)\n"; };
add_filter('pre_wp_mail', function () { return true; });
$admin = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user(intval($admin[0]->ID));

if ($mode === 'setup-kk') {
    $s = get_option('tix_settings', []);
    update_option('tix_me_backup', $s, false);
    $s['co_remote_enabled'] = 1;
    update_option('tix_settings', $s);
    $sid = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'MEXTEST Party Schwelm', 'meta_input' => ['_tix_date_start' => '2027-02-27', '_tix_syndicate' => '1']]);
    echo wp_json_encode(['sid' => $sid]) . "\n"; return;
}
if ($mode === 'setup-plat') {
    [$host, $ki, $ko, $sid] = [(string) $args[1], (string) $args[2], (string) $args[3], intval($args[4])];
    update_option('tix_me_backup', get_option(TIX_Partners::OPTION, null), false);
    $kk = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'MEXTEST KitchenKlub']);
    $mk = function ($n) { $u = wp_insert_user(['user_login' => 'mex_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'mex' . wp_rand(1000, 99999) . '@example.test', 'role' => 'tix_organizer']);
        update_user_meta($u, '_tix_invited_at', gmdate('c'));
        return wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $n, 'meta_input' => ['_tix_org_user_id' => $u]]); };
    $mdj = $mk('MEXTEST MDJ'); $x = $mk('MEXTEST KMT'); $y = $mk('MEXTEST Drei');
    $all = (array) get_option(TIX_Partners::OPTION, []);
    $all['mextest'] = ['id' => 'mextest', 'name' => 'MEXTEST KitchenKlub', 'api_base' => 'http://' . $host . '/wp-json/tixomat/v1', 'key_in' => $ki, 'key_out' => $ko, 'organizer_id' => $kk, 'sales_enabled' => 1];
    update_option(TIX_Partners::OPTION, $all, false);
    $tok = wp_generate_password(48, false);
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'MEXTEST Party Schwelm', 'meta_input' => ['_tix_organizer_id' => $kk, '_tix_date_start' => '2027-02-27',
        '_tix_syndicated' => '1', '_tix_source_partner' => 'mextest', '_tix_source_id' => $sid, '_tix_source_site' => 'MEXTEST KitchenKlub',
        '_tix_co_remote_oid' => $mdj, '_tix_co_remote_access' => '1', '_tix_co_remote_token' => $tok]]);
    echo wp_json_encode(['mir' => $mir, 'mdj' => $mdj, 'x' => $x, 'y' => $y, 'tok' => $tok]) . "\n"; return;
}
if ($mode === 'link-kk') {
    [$sid, $mir, $mdj, $tok] = [intval($args[1]), intval($args[2]), intval($args[3]), (string) $args[4]];
    update_post_meta($sid, '_tix_syndicate_remote_id', $mir);
    update_post_meta($sid, '_tix_co_remote', ['organizer_id' => $mdj, 'name' => 'MEXTEST MDJ', 'access' => true, 'status' => 'invited', 'since' => gmdate('c')]);
    update_post_meta($sid, '_tix_co_token_hash', hash('sha256', $tok));
    echo "link-kk ok\n"; return;
}
if ($mode === 'run-plat') {
    $s = json_decode((string) $args[1], true);
    echo "== Plattform: Admin fügt KMT auf dem Spiegel hinzu\n";
    [, $ed] = me_rest('GET', "/events/{$s['mir']}/edit");
    $e = $ed['event'] ?? $ed;
    me_eq('Editor des Spiegels zeigt den Co der Quelle (MDJ)', [1, $s['mdj'], 'source'], [count($e['co_organizers'] ?? []), intval($e['co_organizers'][0]['id'] ?? 0), $e['co_organizer_mode'] ?? '']);
    [$st, $d, $c] = me_rest('POST', "/platform/events/{$s['mir']}/co-organizer", ['organizer_id' => $s['x'], 'access' => true]);
    if (!in_array($st, [200, 201], true)) echo "      $st $c " . ($d['message'] ?? '') . "\n";
    clean_post_cache($s['mir']); wp_cache_flush();
    me_eq('Weitergereicht, Spiegel hat jetzt KMT (Teil B), kein lokaler Co', [true, true, $s['x'], 0], [in_array($st, [200, 201], true), !empty($d['forwarded']), intval(get_post_meta($s['mir'], '_tix_co_remote_oid', true)), TIX_Co_Organizer::ref_id($s['mir'])]);
    me_eq('Plattform-Zeile: KMT, änderbar über die Quelle', [$s['x'], true], [intval($d['event']['co_organizer']['id'] ?? 0), $d['event']['co_editable'] ?? null]);
    $done(); return;
}
if ($mode === 'check-kk') {
    [$sid, $oid] = [intval($args[1]), intval($args[2])];
    echo "== Lizenzseite: gleicher Co\n";
    $m = get_post_meta($sid, '_tix_co_remote', true);
    [, $ed] = me_rest('GET', "/events/$sid/edit");
    $e = $ed['event'] ?? $ed;
    me_eq('Quelle kennt denselben Co, Editor zeigt ihn', [$oid, 1, $oid], [intval($m['organizer_id'] ?? 0), count($e['co_organizers'] ?? []), intval($e['co_organizers'][0]['id'] ?? 0)]);
    $done(); return;
}
if ($mode === 'status-plat') {
    $s = json_decode((string) $args[1], true);
    $u = intval(get_post_meta($s['x'], '_tix_org_user_id', true));
    update_user_meta($u, '_tix_last_login', gmdate('c'));
    echo "status-plat ok\n"; return;
}
if ($mode === 'status-kk') {
    $sid = intval($args[1]);
    echo "== Lizenzseite: Status-Abgleich\n";
    $m = get_post_meta($sid, '_tix_co_remote', true);
    $m['status'] = 'invited';
    update_post_meta($sid, '_tix_co_remote', $m);
    delete_transient('tix_co_st_' . $sid);
    [, $ed] = me_rest('GET', "/events/$sid/edit");
    $e = $ed['event'] ?? $ed;
    me_eq('Beim Öffnen des Editors: aktiv (von der Plattform)', ['active', 'active'], [(string) (get_post_meta($sid, '_tix_co_remote', true)['status'] ?? ''), (string) ($e['co_organizers'][0]['status'] ?? '')]);
    $done(); return;
}
if ($mode === 'repair-plat') {
    $s = json_decode((string) $args[1], true);
    echo "== Plattform: alter lokaler Co auf dem Spiegel\n";
    TIX_Co_Organizer::set($s['mir'], $s['y'], true, 0);
    $list = TIX_Co_Organizer::mirror_conflicts();
    $hit = array_values(array_filter($list, function ($c) use ($s) { return $c['event'] === intval($s['mir']); }));
    me_eq('Prüfung findet den Widerspruch', [1, $s['y'], $s['x']], [count($hit), $hit[0]['local']['id'] ?? 0, $hit[0]['source']['id'] ?? 0]);
    ob_start(); TIX_Co_Organizer::cli(['spiegel'], ['reparieren' => true]); $out = ob_get_clean();
    clean_post_cache($s['mir']); wp_cache_flush();
    me_eq('Repariert: Quelle übernimmt Y, lokal entfernt', [$s['y'], 0], [intval(get_post_meta($s['mir'], '_tix_co_remote_oid', true)), TIX_Co_Organizer::ref_id($s['mir'])]);
    $done(); return;
}
if ($mode === 'cleanup-kk') {
    $b = get_option('tix_me_backup');
    if (is_array($b)) update_option('tix_settings', $b);
    delete_option('tix_me_backup');
    foreach (get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 's' => 'MEXTEST']) as $id) wp_delete_post($id, true);
    echo "cleanup-kk ok\n"; return;
}
if ($mode === 'cleanup-plat') {
    $b = get_option('tix_me_backup', 'none');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (get_posts(['post_type' => ['event', 'tix_organizer'], 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 's' => 'MEXTEST']) as $id) {
        $u = intval(get_post_meta($id, '_tix_org_user_id', true)); if ($u) wp_delete_user($u);
        wp_delete_post($id, true);
    }
    if ($b === null) delete_option(TIX_Partners::OPTION); elseif ($b !== 'none') update_option(TIX_Partners::OPTION, $b, false);
    delete_option('tix_me_backup');
    echo "cleanup-plat ok\n"; return;
}
echo "Modus fehlt\n";
