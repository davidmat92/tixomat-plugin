<?php
/**
 * Phase 1, Paket 1b (1.38.808): Paket-Kanal am Lizenzserver (V2) und Schatten-Kennung (V3).
 *   wp eval-file …/test-package-channel-wp.php
 * Paket-Kanal nur auf dem Lizenzserver (Option tix_license_server = 1, lokal fp-kk); Schatten-Teil überall.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Shadow') || !class_exists('TIX_Package_Channel')) { echo "Tixomat ab 1.38.808 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$lic_ids = []; $kids = []; $posts = [];
$shadow_before = get_option('tix_shadow_key', null);
$D1 = 'sha256:' . str_repeat('a1', 32); $D2 = 'sha256:' . str_repeat('b2', 32);
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    if (TIX_License_Server::enabled()) {
        TIX_Package_Channel::maybe_install();
        delete_transient('tix_pkg_kid_map');
        $targets_before = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}tix_package_targets", ARRAY_A);
        $wpdb->query("DELETE FROM {$wpdb->prefix}tix_package_targets");
        $lic = TIX_License_Server::create(['customer' => 'Paket-Test', 'valid_until' => gmdate('Y-m-d', strtotime('+200 days'))]); $lic_ids[] = intval($lic->id);
        $kid = TIX_Package_Channel::key_id($lic->license_key); $kids[] = $kid;
        $site = 'paket-test-' . wp_rand(100, 999) . '.example.test';
        $check = function ($ver, $key = null, $tsoff = 0, $k = null) use ($rest, $lic, $kid, $site, $D1) {
            $p = ['key_id' => $k ?? $kid, 'site' => $site, 'component' => 'evendis-api', 'version' => $ver, 'digest' => $D1, 'ts' => time() + $tsoff];
            $p['sig'] = TIX_Package_Channel::signature($key ?? $lic->license_key, $p);
            return $rest('POST', '/license/package/check', $p);
        };
        wp_set_current_user($adm);
        [$st, $d] = $rest('POST', '/license/package/target', ['channel' => 'stable', 'version' => '0.1.1', 'image' => 'ghcr.io/evendis/evendis-api', 'digest' => $D2, 'min_version' => '0.1.0']);
        $eq('Admin: Soll-Version stable setzen', [200, '0.1.1'], [$st, $d['targets']['stable']['version'] ?? '']);
        [$st, $d] = $rest('POST', '/license/package/target', ['channel' => 'stable', 'version' => '0.2', 'image' => 'x', 'digest' => 'kaputt']);
        $eq('Admin: ungültige Version → 400', [400, 'version'], [$st, $d['data']['field'] ?? '']);
        $rest('POST', '/license/package/target', ['channel' => 'beta', 'version' => '0.2.0-beta.1', 'image' => 'ghcr.io/evendis/evendis-api', 'digest' => $D1]);
        wp_set_current_user(0);

        [$st, $d] = $check('0.1.0');
        $eq('Gültige Signatur → Soll-Version stable, Zustand gültig', [200, 'valid', '0.1.1', $D2, 'stable', '0.1.0'], [$st, $d['license']['state'] ?? '', $d['target']['version'] ?? '', $d['target']['digest'] ?? '', $d['target']['channel'] ?? '', $d['target']['min_version'] ?? '']);
        $eq('Antwort signiert (Dienst prüft die Soll-Version)', TIX_Package_Channel::response_signature($lic->license_key, $d['ts'] ?? 0, 'valid', '0.1.1', $D2), $d['sig'] ?? '');
        [$st, , $c] = $check('0.1.0', 'TIX-FALS-CHER-SCHL-UESS');
        $eq('Falsche Signatur → 401', [401, 'tix_bad_signature'], [$st, $c]);
        [$st, , $c] = $check('0.1.0', null, -900);
        $eq('Alte Signatur (15 min) → 401', 401, $st);
        [$st, $d] = $check('0.1.0', 'TIX-UNBE-KANN-TEST-1234', 0, substr(hash('sha256', 'unbekannt' . wp_rand()), 0, 16));
        $eq('Unbekannte Lizenz → 200, state unknown, keine Soll-Version, Hinweis', [200, 'unknown', null, 'license_unknown'], [$st, $d['license']['state'] ?? '', (array_key_exists('target', (array) $d) ? $d['target'] : 'x'), $d['notices'][0]['code'] ?? '']);
        [$st, $d] = $rest('POST', '/license/package/check', ['key_id' => $kid, 'site' => $site, 'component' => 'wordpress', 'version' => '1.0.0', 'ts' => time(), 'sig' => 'x']);
        $eq('Unbekannte Komponente → 400', [400, 'component'], [$st, $d['data']['field'] ?? '']);

        wp_set_current_user($adm);
        [$st, $d] = $rest('GET', '/license/package/status');
        $row = array_values(array_filter((array) ($d['instances'] ?? []), function ($i) use ($site) { return $i['site'] === $site; }))[0] ?? [];
        $eq('Statusliste zeigt die Instanz mit Abweichung', [200, '0.1.0', '0.1.1', true, true, false, 'Paket-Test'], [$st, $row['version'] ?? '', $row['target_version'] ?? '', $row['deviation'] ?? null, $row['behind'] ?? null, $row['below_min'] ?? null, $row['customer'] ?? '']);
        [$st, $d] = $rest('POST', '/license/package/target', ['license_id' => intval($lic->id), 'channel' => 'beta']);
        wp_set_current_user(0);
        [, $d] = $check('0.1.1');
        $eq('Lizenz im Beta-Kanal → Beta-Version', ['0.2.0-beta.1', 'beta'], [$d['target']['version'] ?? '', $d['target']['channel'] ?? '']);
        wp_set_current_user($adm);
        $rest('POST', '/license/package/target', ['license_id' => intval($lic->id), 'clear' => true]);
        wp_set_current_user(0);
        [, $d] = $check('0.1.1');
        $eq('Zurück auf stable, Instanz auf Soll-Version', '0.1.1', $d['target']['version'] ?? '');
        $wpdb->update($wpdb->prefix . 'tix_licenses', ['status' => 'suspended'], ['id' => $lic->id]);
        [$st, $d] = $check('0.1.1');
        $eq('Pausierte Lizenz → kein Update, Hinweis', [200, 'invalid', null], [$st, $d['license']['state'] ?? '', (array_key_exists('target', (array) $d) ? $d['target'] : 'x')]);
        $staff = intval(get_users(['role' => 'tix_staff', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
        wp_set_current_user($staff ?: 0);
        $eq('Nicht-Admin: Status und Soll-Version verboten', [true, true], [in_array($rest('GET', '/license/package/status')[0], [401, 403], true), in_array($rest('POST', '/license/package/target', ['channel' => 'stable'])[0], [401, 403], true)]);
        wp_set_current_user(0);
        $wpdb->query("DELETE FROM {$wpdb->prefix}tix_package_targets");
        foreach ($targets_before as $t) $wpdb->insert($wpdb->prefix . 'tix_package_targets', $t);
    } else echo "skip  Paket-Kanal (kein Lizenzserver auf dieser Seite)\n";

    // ── Schatten-Kennung (V3) ──
    $KEY = str_repeat('s3cr', 10);
    update_option('tix_shadow_key', $KEY, false);
    $counts = function () use ($wpdb) {
        $o = [];
        $o['web'] = intval($wpdb->get_var("SELECT COALESCE(SUM(n),0) FROM " . TIX_Web_Stats::table()));
        $o['dim'] = intval($wpdb->get_var("SELECT COALESCE(SUM(n),0) FROM " . TIX_Web_Stats::dim_table()));
        $ct = class_exists('TIX_Campaign_Tracking') ? TIX_Campaign_Tracking::get_table() : '';
        $o['camp'] = $ct !== '' && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ct)) === $ct ? intval($wpdb->get_var("SELECT COALESCE(SUM(views),0) FROM $ct")) : -1;
        $o['links'] = intval($wpdb->get_var("SELECT COALESCE(SUM(n),0) FROM {$wpdb->prefix}tix_short_clicks"));
        $o['rl'] = get_transient('tix_app_rl_shadowtest_' . md5('shadow-test'));
        return $o;
    };
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Schatten-Event']); $posts[] = $ev;
    TIX_Short_Links::maybe_create();
    $code = 'sh-' . wp_rand(10000, 99999);
    $wpdb->insert($wpdb->prefix . 'tix_short_links', ['organizer_id' => 0, 'code' => $code, 'event_id' => $ev, 'label' => 'Schatten', 'created' => current_time('mysql', true)]);
    $lid = $wpdb->insert_id;
    $act = function () use ($rest, $ev, $code) {
        // Trichter direkt (ohne REST-Methode): POST mit Kennung wäre 403
        $x = new WP_REST_Request('POST', '/tixomat/v1/public/stats/hit'); $x->set_param('step', 'event'); $x->set_param('event_id', $ev); $x->set_param('utm_source', 'schatten');
        TIX_Web_Stats::rest_hit($x);
        if (class_exists('TIX_Campaign_Tracking')) TIX_Campaign_Tracking::record_event_view($ev, 'schatten');
        TIX_Short_Links::resolve($code, false, 'Mozilla/5.0 Testbrowser');
        TIX_App_Account::rate_limited('shadowtest', 5, 600, 'shadow-test');
        return wp_mail('schatten@example.test', 'Schatten', 'nur Test');
    };
    $mails = 0; $count_mail = function ($r) use (&$mails) { if ($r !== null) return $r; $mails++; return true; }; // fängt Mails ab (nach dem Schatten-Filter)
    add_filter('pre_wp_mail', $count_mail, 1);
    // mit Kennung + Schlüssel: nichts zählt, keine Mail
    $_SERVER['HTTP_X_EVENDIS_SHADOW_FETCH'] = '1'; $_SERVER['HTTP_X_EVENDIS_SHADOW_KEY'] = $KEY; TIX_Shadow::reset();
    delete_transient('tix_ws_rate_' . gmdate('YmdHi'));
    $b = $counts(); $mail_ok = $act(); $a = $counts();
    $eq('Schatten-Abruf: kein Zähler ändert sich (Trichter, Kampagne, Kurzlink, Ratenlimit)', $b, $a);
    $eq('Schatten-Abruf: keine Mail (gilt als erledigt, Antwort unverändert)', [true, 0], [$mail_ok, $mails]);
    // Pushes (FCM) und schreibende Methode
    $eq('Schatten-Abruf: kein Push', ['sent' => 0, 'invalid' => []], TIX_FCM::send(['tok1'], 'T', 'B'));
    [$st, , $c] = $rest('POST', '/public/stats/hit', ['step' => 'view']);
    $eq('Kennung + POST → 403 tix_shadow_readonly', [403, 'tix_shadow_readonly'], [$st, $c]);
    // Kennung ohne Schlüssel: zählt normal (sonst könnte jeder die Limits umgehen); schreibend bleibt gesperrt
    $_SERVER['HTTP_X_EVENDIS_SHADOW_KEY'] = 'falsch'; TIX_Shadow::reset();
    [$st, , $c] = $rest('POST', '/public/stats/hit', ['step' => 'view']);
    $eq('Kennung ohne Schlüssel + POST → trotzdem 403', 403, $st);
    unset($_SERVER['HTTP_X_EVENDIS_SHADOW_FETCH']); TIX_Shadow::reset();
    delete_transient('tix_ws_rate_' . gmdate('YmdHi')); // Minutenbremse des Trichters (andere Tests im selben Lauf)
    $_SERVER['HTTP_X_EVENDIS_SHADOW_FETCH'] = '1'; TIX_Shadow::reset();
    $b = $counts(); $act(); $a = $counts();
    $eq('Kennung ohne richtigen Schlüssel: zählt normal', [true, true, true], [$a['web'] > $b['web'], $a['links'] > $b['links'], is_array($a['rl'])]);
    $eq('… und Mail geht raus', 1, $mails);
    // ohne Kennung: normal
    unset($_SERVER['HTTP_X_EVENDIS_SHADOW_FETCH'], $_SERVER['HTTP_X_EVENDIS_SHADOW_KEY']); TIX_Shadow::reset();
    $eq('Ohne Kennung: kein Schatten', false, TIX_Shadow::active());
    delete_option('tix_shadow_key');
    $_SERVER['HTTP_X_EVENDIS_SHADOW_FETCH'] = '1'; $_SERVER['HTTP_X_EVENDIS_SHADOW_KEY'] = $KEY; TIX_Shadow::reset();
    $eq('Ohne eingerichteten Schlüssel: Kennung wird ignoriert', false, TIX_Shadow::active());
    remove_filter('pre_wp_mail', $count_mail, 1);
    $wpdb->delete($wpdb->prefix . 'tix_short_links', ['id' => $lid]); $wpdb->delete($wpdb->prefix . 'tix_short_clicks', ['link_id' => $lid]);
    $wpdb->delete(TIX_Web_Stats::dim_table(), ['event_id' => $ev]); $wpdb->delete(TIX_Web_Stats::table(), ['ref' => $ev]);
    if (class_exists('TIX_Campaign_Tracking')) $wpdb->delete(TIX_Campaign_Tracking::get_table(), ['event_id' => $ev]);
    delete_transient('tix_app_rl_shadowtest_' . md5('shadow-test'));
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    unset($_SERVER['HTTP_X_EVENDIS_SHADOW_FETCH'], $_SERVER['HTTP_X_EVENDIS_SHADOW_KEY']); TIX_Shadow::reset();
    if ($shadow_before === null) delete_option('tix_shadow_key'); else update_option('tix_shadow_key', $shadow_before, false);
    if (TIX_License_Server::enabled()) foreach ($lic_ids as $l) { $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $l]); $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $l]); $wpdb->delete($wpdb->prefix . 'tix_package_instances', ['license_id' => $l]); }
    if (TIX_License_Server::enabled()) { foreach ($kids as $k) $wpdb->delete($wpdb->prefix . 'tix_package_instances', ['key_id' => $k]); $wpdb->query("DELETE FROM {$wpdb->prefix}tix_package_instances WHERE host LIKE 'paket-test-%'"); }
    delete_transient('tix_pkg_kid_map');
    foreach ($posts as $p) wp_delete_post($p, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
