<?php
/**
 * Export V5 für den Dienst (1.38.893): Zugang nur mit Schlüssel (sonst 404), Änderungs-Log, Entitäten, keine Geheimnisse.
 *   wp eval-file …/test-export-v5-wp.php   (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Service_Export')) { echo "Tixomat ab 1.38.893 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$srv = ['HTTP_X_EVENDIS_EXPORT' => $_SERVER['HTTP_X_EVENDIS_EXPORT'] ?? null, 'HTTP_X_EVENDIS_SHADOW_KEY' => $_SERVER['HTTP_X_EVENDIS_SHADOW_KEY'] ?? null];
$keep = ['tix_shadow_key' => get_option('tix_shadow_key', null), TIX_Service_Export::FLOOR_OPT => get_option(TIX_Service_Export::FLOOR_OPT, null), 'tix_app_tips_enabled' => get_option('tix_app_tips_enabled', null)];
$const = defined('EVENDIS_SHADOW_KEY');
$key = $const ? (string) EVENDIS_SHADOW_KEY : 'k' . str_repeat(substr(md5(microtime()), 0, 8), 5);
if (!$const) update_option('tix_shadow_key', $key, false);
$hdr = function ($export, $k) { foreach (['HTTP_X_EVENDIS_EXPORT' => $export, 'HTTP_X_EVENDIS_SHADOW_KEY' => $k] as $h => $v) { if ($v === null) unset($_SERVER[$h]); else $_SERVER[$h] = $v; } TIX_Shadow::reset(); };
$get = function ($r, $q = [], $m = 'GET') { $req = new WP_REST_Request($m, '/tixomat/v1/_export' . $r); foreach ($q as $k => $v) $req->set_param($k, $v); $res = rest_do_request($req); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null]; };
$find = function ($ch, $ent, $id) { return array_values(array_filter((array) $ch, function ($c) use ($ent, $id) { return $c['entity'] === $ent && $c['id'] === (string) $id; })); };
wp_set_current_user(0);
$ev = 0; $org = 0; $secret = 'GEHEIM' . md5('x' . microtime());
try {
    // ═══ Zugang ═══
    $hdr(null, null);
    [$s, , $c] = $get('/changes'); $eq('ohne Kopf → 404 rest_no_route', [404, 'rest_no_route'], [$s, $c]);
    $hdr('1', 'falsch' . str_repeat('x', 40));
    [$s, , $c] = $get('/changes'); $eq('falscher Schlüssel → 404', [404, 'rest_no_route'], [$s, $c]);
    [$s, , $c] = $get('/events', [], 'POST'); $eq('POST mit Export-Kopf → 403 nur lesend', [403, 'tix_shadow_readonly'], [$s, $c]);
    $hdr(null, $key);
    [$s] = $get('/changes'); $eq('Schlüssel ohne Export-Kopf → 404', 404, $s);
    if (!$const) {
        delete_option('tix_shadow_key'); $hdr('1', $key);
        [$s] = $get('/changes'); $eq('kein Schlüssel eingerichtet → 404', 404, $s);
        update_option('tix_shadow_key', $key, false);
    }
    $hdr(null, null);
    $idx = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1'))->get_data();
    $eq('nicht im REST-Index', false, (bool) preg_grep('#_export#', array_keys((array) ($idx['routes'] ?? []))));

    // ═══ Gültig ═══
    $hdr('1', $key);
    $eq('gilt als Schatten-Abruf: keine Zähler, keine Mails', [true, true], [TIX_Shadow::active(), apply_filters('pre_wp_mail', null, ['to' => 'x@example.test'])]);
    $req0 = get_option('tix_app_requirements', null);
    $rec = TIX_App_Compat::record(['app' => 'v5test_' . substr(md5(microtime()), 0, 6), 'min' => '9.9.9', 'since' => '']);
    $eq('Schatten: App-Anforderung nur ausgewertet, nicht gespeichert (1.38.896)', ['9.9.9', $req0], [$rec['min'] ?? null, get_option('tix_app_requirements', null)]);
    [$s, $d] = $get('/changes');
    $head0 = (string) ($d['cursor'] ?? '');
    $eq('changes ohne since: nur Cursor = head', [200, [], $head0, false], [$s, $d['changes'] ?? null, (string) ($d['head'] ?? 'x'), $d['has_more'] ?? null]);

    // Änderungen erzeugen (wie eine Studio-Anfrage; Puffer am Ende schreiben)
    $hdr(null, null);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'V5 Event']);
    update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Normal', 'price' => 20, 'stock' => 50]]);
    update_post_meta($ev, '_tix_checkin_token', $secret);
    update_post_meta($ev, '_tix_co_mirror', [['oid' => 5, 'access' => 'view', 'token' => $secret]]);
    update_post_meta($ev, '_tix_subtitle', 'Untertitel');
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'V5 Club']);
    update_post_meta($org, '_tix_payout_iban', 'DE89370400440532013000');
    update_post_meta($org, '_tix_payout_data', ['holder' => 'X', 'iban' => 'DE89370400440532013000']);
    TIX_Service_Export::flush();
    update_post_meta($ev, '_tix_subtitle', 'Neu'); // zweite Runde: alter Eintrag fällt weg
    update_option('tix_app_tips_enabled', get_option('tix_app_tips_enabled') ? 0 : 1, false);
    if (class_exists('TIX_Sponsor_Logos') && TIX_Sponsor_Logos::ready()) TIX_Sponsor_Logos::put_settings('event', $ev, ['enabled' => true]);
    TIX_Service_Export::flush();

    $hdr('1', $key);
    [$s, $d] = $get('/changes', ['since' => $head0]);
    $ch = $d['changes'] ?? [];
    $e1 = $find($ch, 'events', $ev);
    $eq('Event als upsert, nur ein Eintrag', [200, 1, 'upsert'], [$s, count($e1), $e1[0]['op'] ?? null]);
    $eq('ticket_categories, organizers, config signalisiert', [1, 1, 1], [count($find($ch, 'ticket_categories', $ev)), count($find($ch, 'organizers', $org)), count($find($ch, 'config', 'tix_app_tips_enabled'))]);
    if (class_exists('TIX_Sponsor_Logos')) $eq('Sponsoren-Einstellung signalisiert', 1, count($find($ch, 'sponsors', 'event:' . $ev)));
    $vs = array_column($ch, 'version');
    $sorted = $vs; sort($sorted);
    $eq('aufsteigend, Cursor = letzte Version', [$sorted, (string) end($vs)], [$vs, (string) ($d['cursor'] ?? '')]);
    [$s, $d2] = $get('/changes', ['since' => $head0, 'limit' => 1]);
    $eq('limit 1 → has_more', [1, true], [count($d2['changes'] ?? []), $d2['has_more'] ?? null]);

    // ═══ Entitäten ═══
    [$s, $d] = $get('/events', ['ids' => $ev . ',999999999']);
    $it = $d['items'][0] ?? [];
    $meta = (array) ($it['meta'] ?? []);
    $eq('events?ids: Zeile + missing', [200, $ev, 'publish', ['999999999'], 'Neu'], [$s, $it['id'] ?? null, $it['status'] ?? null, $d['missing'] ?? null, $meta['_tix_subtitle'] ?? null]);
    $eq('version = Log, modified_gmt gesetzt', [$e1[0]['version'] ?? -1, true], [$it['version'] ?? null, !empty($it['modified_gmt'])]);
    $eq('Token-Meta entfernt, verschachtelt auch', [false, false], [array_key_exists('_tix_checkin_token', $meta), isset($meta['_tix_co_mirror'][0]['token'])]);
    [, $d] = $get('/ticket_categories', ['ids' => (string) $ev]);
    $eq('ticket_categories mit index', [0, 'Normal'], [$d['items'][0]['categories'][0]['index'] ?? null, $d['items'][0]['categories'][0]['name'] ?? null]);
    [, $d] = $get('/organizers', ['ids' => (string) $org]);
    $eq('Veranstalter ohne _tix_payout_*', [$org, []], [$d['items'][0]['id'] ?? null, preg_grep('/^_tix_payout_/', array_keys((array) ($d['items'][0]['meta'] ?? [])))]);
    [$s, $d] = $get('/events', ['page' => 1, 'per_page' => 2]);
    $eq('Seiten: per_page, total', [200, 2, true], [$s, $d['per_page'] ?? null, ($d['total'] ?? 0) >= 1]);
    [$s, , $c] = $get('/events', ['ids' => implode(',', range(1, 201))]);
    $eq('201 IDs → 400', [400, 'tix_export_too_many'], [$s, $c]);
    [$s, $d] = $get('/config');
    $names = array_column($d['items'] ?? [], 'id');
    $eq('config: Liste ohne Schlüssel-Optionen', [200, true, false], [$s, in_array('tix_settings', $names, true), in_array('tix_shadow_key', $names, true)]);
    [, $d] = $get('/config', ['ids' => 'tix_shadow_key']);
    $eq('config?ids=tix_shadow_key → missing', ['tix_shadow_key'], $d['missing'] ?? null);
    [$s] = $get('/media'); $eq('media ohne ids → 400', 400, $s);
    [$s, $d] = $get('/terms'); $eq('terms', 200, $s);
    [$s, $d] = $get('/derived');
    $eq('derived: plan, license, staff_site, version', [200, true, true, true], [$s, array_key_exists('plan', $d), array_key_exists('license', $d), is_int($d['version'] ?? null)]);
    $eq('derived 1.38.894: settings_effective, site_config, event_placeholder', [true, true, true], [array_key_exists('settings_effective', $d), array_key_exists('site_config', $d), array_key_exists('event_placeholder', $d)]);
    $eq('settings_effective ohne Schlüssel-Felder', [], array_values(array_filter(array_keys((array) ($d['settings_effective'] ?? [])), ['TIX_Mirror_Map', 'is_sensitive'])));
    [, $cfg] = $get('/config', ['ids' => 'tix_awareness_flags']);
    $eq('config: tix_awareness_flags dabei', ['tix_awareness_flags'], array_column($cfg['items'] ?? [], 'id'));
    [, $d] = $get('/config', ['ids' => 'tix_play_public,tix_app_top_bar,tix_modules_entitled_v,tix_giftcard_settings,tix_degaso']);
    $eq('config: Optionen für /info und site-config in der Liste', [], array_values(array_diff(['tix_play_public', 'tix_app_top_bar', 'tix_modules_entitled_v', 'tix_giftcard_settings', 'tix_degaso'], array_column($d['items'] ?? [], 'id'))));
    $info = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/info'))->get_data();
    [, $d] = $get('/derived');
    $eq('derived 1.38.895 = /info (Version, Name, Module, Quelle, Funktionen)', [$info['plugin_version'], $info['name'], $info['modules'], $info['modules_source'], $info['app_features']],
        [$d['plugin_version'] ?? null, $d['site_name'] ?? null, $d['modules'] ?? null, $d['modules_source'] ?? null, $d['app_features_site'] ?? null]);
    $eq('derived: timezone, multi, Newsletter nur bei multi', [wp_timezone_string(), class_exists('TIX_App_Scope') && TIX_App_Scope::multi(), isset($info['newsletter']) ? $info['newsletter']['enabled'] : null],
        [$d['timezone'] ?? null, $d['multi'] ?? null, array_key_exists('newsletter_enabled', (array) $d) ? $d['newsletter_enabled'] : 'x']);
    $can = array_filter(array_column(array_values((array) ($d['app_features_site'] ?? [])), 'can_edit'));
    $eq('app_features_site als Gast: nichts bearbeitbar', [], array_values($can));
    [$s] = $get('/nichtda'); $eq('unbekannte Entität → 404', 404, $s);

    // Keine Geheimnisse in allen Antworten
    $all = '';
    foreach (['/events?p', '/organizers?p', '/config?p', '/derived?p', '/sponsors?p'] as $r) { [, $d] = $get(strtok($r, '?'), $r === '/derived?p' ? [] : ['per_page' => 200]); $all .= wp_json_encode($d); }
    $eq('kein Geheimnis, keine IBAN, kein Schlüssel', [false, false, false], [strpos($all, $secret) !== false, strpos($all, 'DE89370400440532013000') !== false, strpos($all, $key) !== false]);

    // ═══ 1.38.898: url + Signal reload|urls ═══
    [, $d] = $get('/organizers', ['ids' => (string) $org]);
    $eq('url = Kunden-Adresse des Permalinks', TIX_Customer_URL::to((string) get_permalink($org)), $d['items'][0]['url'] ?? null);
    $pm = get_post($org)->post_modified_gmt;
    $eq('post_modified_gmt = Post-Wert (1.38.900)', gmdate('Y-m-d\\TH:i:s\\Z', strtotime($pm . ' UTC')), $d['items'][0]['post_modified_gmt'] ?? null);
    [, $d] = $get('/config', ['ids' => 'tix_org_approval_db']);
    $eq('config: tix_org_approval_db', ['tix_org_approval_db'], array_column($d['items'] ?? [], 'id'));
    [, $d] = $get('/changes'); $hu = (string) $d['cursor'];
    $hdr(null, null);
    $cb = get_option('tix_customer_base_url', null);
    update_option('tix_customer_base_url', 'https://kunden-v5.example.test');
    if ($cb === null) delete_option('tix_customer_base_url'); else update_option('tix_customer_base_url', $cb);
    TIX_Service_Export::flush();
    $hdr('1', $key);
    [, $d] = $get('/changes', ['since' => $hu]);
    $eq('Kunden-Adresse geändert → reload|urls', 1, count($find($d['changes'] ?? [], 'reload', 'urls')));

    // ═══ 1.38.897: Fixtures-Schutz, Gebühren-Regel ohne rounding ═══
    if (class_exists('TIX_Export_Fixtures')) {
        $hf = function () { return 'https://kitchenklub.de'; };
        add_filter('pre_option_home', $hf);
        $remote = TIX_Export_Fixtures::local_site();
        remove_filter('pre_option_home', $hf);
        $eq('export-fixtures seed: echte Domain abgelehnt, Testseite erlaubt', [false, true], [$remote, TIX_Export_Fixtures::local_site()]);
    }
    $hdr(null, null);
    $syn = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'V5 Syn']);
    update_post_meta($syn, '_tix_syndicated', '1');
    update_post_meta($syn, '_tix_source_fee', ['fixed' => 1, 'mode' => 'customer']);
    $warn = [];
    set_error_handler(function ($no, $msg) use (&$warn) { $warn[] = $msg; return true; }, E_WARNING | E_NOTICE);
    $fc = TIX_Fees::display_config($syn);
    restore_error_handler();
    wp_delete_post($syn, true);
    $eq('Regel der Quelle ohne rounding: keine Warnung, none', [[], 'none'], [$warn, $fc['fee_rounding'] ?? null]);
    $hdr('1', $key);
    [, $d] = $get('/derived');
    $eq('derived.fees_site', true, is_array($d['fees_site'] ?? null) && array_key_exists('fee_mode', $d['fees_site']));
    $eq('derived.locale (1.38.924)', get_locale(), $d['locale'] ?? null);

    // ═══ Künstler: old_slugs (1.38.924) ═══
    $hdr(null, null);
    $art = wp_insert_post(['post_type' => 'tix_artist', 'post_status' => 'publish', 'post_title' => 'V5 Künstler', 'post_name' => 'v5-kuenstler-alt-' . substr(md5(microtime()), 0, 5)]);
    $old_slug = get_post_field('post_name', $art);
    TIX_Service_Export::flush();
    $hdr('1', $key);
    [, $d] = $get('/changes'); $hA = (string) ($d['cursor'] ?? '');
    $hdr(null, null);
    wp_update_post(['ID' => $art, 'post_name' => $old_slug . '-neu']); // WordPress merkt den alten Slug (_wp_old_slug)
    TIX_Service_Export::flush();
    $hdr('1', $key);
    [, $d] = $get('/changes', ['since' => $hA]);
    $eq('Slug-Änderung → Änderung artists', 1, count($find($d['changes'] ?? [], 'artists', $art)) >= 1 ? 1 : 0);
    [, $d] = $get('/artists', ['per_page' => 200]);
    $row = array_values(array_filter((array) ($d['items'] ?? $d['rows'] ?? []), function ($r) use ($art) { return intval($r['id'] ?? 0) === $art; }))[0] ?? [];
    $eq('artists-Zeile: old_slugs mit altem Slug', [true, true], [isset($row['old_slugs']), in_array($old_slug, (array) ($row['old_slugs'] ?? []), true)]);
    [, $d] = $get('/events', ['per_page' => 5]);
    $er = (array) (($d['items'] ?? $d['rows'] ?? [])[0] ?? []);
    $eq('Event-Zeilen ohne old_slugs', false, array_key_exists('old_slugs', $er));
    $hdr(null, null);
    wp_delete_post($art, true);
    $hdr('1', $key);

    // ═══ Löschen + 410 ═══
    $hdr(null, null);
    [, $d] = (function () use ($get, $hdr, $key) { $hdr('1', $key); $r = $get('/changes'); $hdr(null, null); return $r; })();
    $h1 = (string) $d['cursor'];
    wp_delete_post($ev, true); $del = $ev; $ev = 0;
    TIX_Service_Export::flush();
    $hdr('1', $key);
    [, $d] = $get('/changes', ['since' => $h1]);
    $x = $find($d['changes'] ?? [], 'events', $del);
    $eq('gelöschtes Event → delete', [1, 'delete'], [count($x), $x[0]['op'] ?? null]);
    [, $d] = $get('/events', ['ids' => (string) $del]);
    $eq('gelöscht: in missing', [[], [(string) $del]], [$d['items'] ?? null, $d['missing'] ?? null]);
    update_option(TIX_Service_Export::FLOOR_OPT, (int) $h1, false);
    [$s, , $c] = $get('/changes', ['since' => (string) ((int) $h1 - 1)]);
    $eq('Cursor unter floor → 410', [410, 'tix_export_cursor_gone'], [$s, $c]);
    [$s, , $c] = $get('/changes', ['since' => 'abc']);
    $eq('unlesbarer Cursor → 400', [400, 'tix_export_cursor'], [$s, $c]);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    foreach ($srv as $h => $v) { if ($v === null) unset($_SERVER[$h]); else $_SERVER[$h] = $v; }
    TIX_Shadow::reset();
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    if (class_exists('TIX_Sponsor_Logos') && TIX_Sponsor_Logos::ready() && ($ev || isset($del))) { global $wpdb; $wpdb->delete(TIX_Sponsor_Logos::t('settings'), ['scope' => 'event', 'owner_id' => $ev ?: $del]); }
    if ($ev) wp_delete_post($ev, true);
    if ($org) wp_delete_post($org, true);
    TIX_Service_Export::flush();
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
