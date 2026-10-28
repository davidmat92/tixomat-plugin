<?php
/**
 * Integrationstest TIX_Plan (ein Schlüsselsatz für Module, 1.38.563) gegen ein lokales
 * Test-WordPress. Legt Nutzer, Veranstalter und Events an und löscht sie am Ende wieder.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-plan-wp.php
 *
 * Läuft auf einer Einzelseite (Bestand darf sich nicht ändern) und im Mehr-Veranstalter-Modus
 * (Schalter je Veranstalter, Durchsetzung an den Routen, Migration, Plattform-Studio).
 */
if (!defined('ABSPATH') || !class_exists('TIX_Plan')) { echo "WordPress mit Tixomat (TIX_Plan) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function tp_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function tp_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data();
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}

$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$created_users = []; $created_posts = [];
$sfx = wp_rand(1000, 9999);

if (!$multi) {
    // ══════════ Einzelseite: Bestand unverändert ══════════
    echo "── Einzelseite ──\n";
    $m = TIX_Plan::modules();
    foreach (TIX_Plan::ALWAYS as $k) tp_eq("immer an: $k", true, $m[$k]);
    foreach (TIX_Plan::EXTRAS as $k) tp_eq("Extra ohne Lizenz-Einschränkung an: $k", true, $m[$k]);
    tp_eq('Paket website = tix_website_enabled', get_option('tix_website_enabled', '0') === '1', $m['website']);
    tp_eq('Paket app = has_app', !empty(TIX_Studio::app_info()['has_app']), $m['app']);
    // keine Sperre über TIX_Plan auf Einzelseiten
    foreach (['/music/status', '/loyalty/config', '/raffles', '/assistant/config', '/wallet/config'] as $r) {
        list($st, , $code) = tp_rest('GET', $r);
        tp_eq("Einzelseite sperrt nicht: $r", false, $code === 'tix_module_disabled');
    }
    // App-Funktionen: reine App-Schalter hängen auf Einzelseiten nicht an „Eigene App“
    update_option('tix_studio_has_app', '0');
    tp_eq('Tipps ohne App-Paket weiter wie bisher (Einzelseite)', TIX_Features::site_on('tips'), TIX_Features::on('tips'));
    delete_option('tix_studio_has_app');
    // /me liefert modules
    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
    wp_set_current_user($admin);
    list($st, $d) = tp_rest('GET', '/me');
    tp_eq('/me 200', 200, $st);
    tp_eq('/me.modules vorhanden', true, is_array($d['modules'] ?? null));
    tp_eq('/me.modules.music an', true, $d['modules']['music'] ?? null);
    list($st, $d) = tp_rest('GET', '/info');
    tp_eq('/info.plan.extras.loyalty an', true, $d['plan']['extras']['loyalty'] ?? null);
    // Lizenz/Option schränkt ein → Modul aus
    update_option('tix_modules_entitled', ['loyalty', 'giftcards', 'muttizettel', 'seating', 'tips']);
    tp_eq('Option ohne music → music aus', false, TIX_Plan::site_on('music'));
    tp_eq('Option mit loyalty → loyalty an', true, TIX_Plan::site_on('loyalty'));
    delete_option('tix_modules_entitled');
    tp_eq('Option gelöscht → alles wieder an', true, TIX_Plan::site_on('music'));

    // Modul-Schalter im Studio der Einzelseite (nur Admin, 1.38.564)
    wp_set_current_user($admin);
    list($st, $d) = tp_rest('GET', '/studio/modules');
    tp_eq('Studio-Module: Admin 200', 200, $st);
    tp_eq('Studio-Module: alles an', true, $d['modules']['music'] ?? null);
    $staff = wp_insert_user(['user_login' => "tp_staff_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "tp_staff_$sfx@example.test", 'role' => 'tix_organizer']);
    $created_users[] = $staff;
    wp_set_current_user($staff);
    list($st) = tp_rest('GET', '/studio/modules');
    tp_eq('Studio-Module: Nicht-Admin 403', 403, $st);
    wp_set_current_user($admin);
    list($st, $d) = tp_rest('POST', '/studio/modules', ['modules' => ['music' => false, 'pos' => false]]);
    tp_eq('Musikwunsch aus gespeichert', false, $d['modules']['music'] ?? null);
    tp_eq('Abendkasse bleibt immer an', true, $d['modules']['pos'] ?? null);
    list($st, , $code) = tp_rest('GET', '/music/status');
    tp_eq('ausgeschaltet → Route gesperrt', 'tix_module_disabled', $code);
    list(, $d) = tp_rest('GET', '/me');
    tp_eq('/me.modules.music aus', false, $d['modules']['music'] ?? null);
    list(, , $code) = tp_rest('GET', '/loyalty/config');
    tp_eq('andere Extras nicht gesperrt', false, $code === 'tix_module_disabled');
    list($st) = tp_rest('POST', '/studio/modules', ['modules' => ['quatsch' => true]]);
    tp_eq('unbekanntes Modul 400', 400, $st);
    update_option('tix_modules_entitled', ['loyalty', 'giftcards', 'muttizettel', 'seating', 'tips']);
    list($st) = tp_rest('POST', '/studio/modules', ['modules' => ['music' => true]]);
    tp_eq('Lizenz sperrt Einschalten (409)', 409, $st);
    delete_option('tix_modules_entitled');
    list($st, $d) = tp_rest('POST', '/studio/modules', ['modules' => ['music' => true]]);
    tp_eq('wieder an', true, $d['modules']['music'] ?? null);
    list(, , $code) = tp_rest('GET', '/music/status');
    tp_eq('wieder an → Route offen', false, $code === 'tix_module_disabled');
    tp_eq('Option sauber (nichts aus)', [], (array) get_option(TIX_Plan::SITE_OPT, []));
} else {
    // ══════════ Plattform ══════════
    echo "── Plattform ──\n";
    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
    $mk_user = function ($name) use (&$created_users, $sfx) {
        $id = wp_insert_user(['user_login' => "tp_{$name}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "tp_{$name}_$sfx@example.test", 'role' => 'tix_organizer']);
        $created_users[] = $id;
        return $id;
    };
    $mk_org = function ($title, $owner) use (&$created_posts) {
        $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $title]);
        update_post_meta($id, '_tix_org_user_id', $owner);
        if (class_exists('TIX_Org_Approval')) update_post_meta($id, TIX_Org_Approval::META_STATUS, 'approved');
        $created_posts[] = $id;
        return $id;
    };
    $mk_event = function ($oid) use (&$created_posts) {
        $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Plan-Test ' . $oid]);
        update_post_meta($id, '_tix_organizer_id', (string) $oid);
        update_post_meta($id, '_tix_date_start', date('Y-m-d', strtotime('+7 days')));
        $created_posts[] = $id;
        return $id;
    };
    $ua = $mk_user('a'); $ub = $mk_user('b');
    $oa = $mk_org("Plan A $sfx", $ua); $ob = $mk_org("Plan B $sfx", $ub);
    $ea = $mk_event($oa); $eb = $mk_event($ob);

    // 1. Vorgaben ohne gespeicherten Wert
    $m = TIX_Plan::modules($oa);
    foreach (TIX_Plan::ALWAYS as $k) tp_eq("Vorgabe immer an: $k", true, $m[$k]);
    tp_eq('Vorgabe website aus', false, $m['website']);
    tp_eq('Vorgabe app aus', false, $m['app']);
    tp_eq('Vorgabe awareness an (Bestand)', true, $m['awareness']);
    tp_eq('Vorgabe raffles an (Bestand)', true, $m['raffles']);
    tp_eq('Vorgabe music aus (Bestand)', false, $m['music']);

    // 2. Plattform-Studio (Admin): speichern, Immer-Module bleiben an, unbekannt 400
    wp_set_current_user($admin);
    list($st, $d) = tp_rest('POST', "/platform/organizers/$oa/modules", ['modules' => ['music' => true, 'website' => true, 'pos' => false]]);
    tp_eq('Plattform speichert 200', 200, $st);
    tp_eq('music an gespeichert', true, $d['modules']['music'] ?? null);
    tp_eq('pos bleibt fest an', true, $d['modules']['pos'] ?? null);
    tp_eq('website → eigene Domain freigegeben', '1', get_post_meta($oa, '_tix_org_domain_allowed', true));
    list($st, , $code) = tp_rest('POST', "/platform/organizers/$oa/modules", ['modules' => ['quatsch' => true]]);
    tp_eq('unbekanntes Modul 400', 400, $st);
    tp_rest('POST', "/platform/organizers/$ob/modules", ['modules' => ['music' => false, 'raffles' => false, 'loyalty' => true]]);
    list(, $d) = tp_rest('GET', '/platform/organizers');
    $row = null;
    foreach ((array) ($d['organizers'] ?? []) as $o) if (intval($o['id'] ?? 0) === $ob) $row = $o;
    tp_eq('Liste zeigt neue Schlüssel (raffles aus)', false, $row['modules']['raffles'] ?? null);

    // 3. /me.modules je Konto
    wp_set_current_user($ua);
    list(, $d) = tp_rest('GET', '/me');
    tp_eq('/me.modules A music an', true, $d['modules']['music'] ?? null);
    wp_set_current_user($ub);
    list(, $d) = tp_rest('GET', '/me');
    tp_eq('/me.modules B music aus', false, $d['modules']['music'] ?? null);
    tp_eq('/me.modules B loyalty an', true, $d['modules']['loyalty'] ?? null);

    // 4. Durchsetzung: Verwaltung (Konto) und Gast (Event, ?organizer)
    wp_set_current_user($ub);
    list($st, , $code) = tp_rest('GET', '/music/dj/requests');
    tp_eq('B: Musikwunsch-Verwaltung gesperrt', 'tix_module_disabled', $code);
    tp_eq('B: 403', 403, $st);
    wp_set_current_user($ua);
    list($st, , $code) = tp_rest('GET', '/music/dj/requests');
    tp_eq('A: Musikwunsch-Verwaltung nicht über Modul gesperrt', false, $code === 'tix_module_disabled');
    wp_set_current_user(0);
    list($st, , $code) = tp_rest('GET', "/public/events/$eb/raffle");
    tp_eq('Gast: Gewinnspiel-Event von B gesperrt', 'tix_module_disabled', $code);
    list($st, , $code) = tp_rest('GET', "/public/events/$ea/raffle");
    tp_eq('Gast: Gewinnspiel-Event von A nicht gesperrt', false, $code === 'tix_module_disabled');
    list($st, , $code) = tp_rest('GET', '/music/status', ['event_id' => $eb]);
    tp_eq('Gast: Musikwunsch für Event von B gesperrt', 'tix_module_disabled', $code);
    list($st, , $code) = tp_rest('GET', '/info');
    tp_eq('/info nie gesperrt', 200, $st);
    // App-Paket: schreiben gesperrt, lesen offen
    wp_set_current_user($ub);
    list($st, , $code) = tp_rest('POST', '/app/tips', ['title' => 'x']);
    tp_eq('B ohne App-Paket: Tipps schreiben gesperrt', 'tix_module_disabled', $code);
    wp_set_current_user(0);
    list($st, , $code) = tp_rest('GET', '/app/config');
    tp_eq('/app/config lesen offen', false, $code === 'tix_module_disabled');
    // Admin der Plattform wird nicht über Veranstalter-Module gesperrt
    wp_set_current_user($admin);
    list($st, , $code) = tp_rest('GET', '/music/dj/requests');
    tp_eq('Admin nicht gesperrt', false, $code === 'tix_module_disabled');

    // 5. App-Funktionen folgen dem Modul
    tp_eq('Funktion music für B aus', false, TIX_Features::on('music', $ob));
    tp_eq('Funktion raffles für B aus', false, TIX_Features::on('raffles', $ob));

    // 6. Daten bleiben beim Ausschalten
    update_post_meta($ob, '_tix_org_loyalty', ['enabled' => true, 'per_visit' => 2]);
    TIX_Plan::save_org($ob, ['loyalty' => false]);
    TIX_Plan::save_org($ob, ['loyalty' => true]);
    tp_eq('Prämien-Einstellungen nach aus → an unverändert', 2, intval((get_post_meta($ob, '_tix_org_loyalty', true))['per_visit'] ?? 0));

    // 6b. Website aus → Domain bleibt, Auflösung liefert Weiterleitung auf die Veranstalterseite (M4 A)
    if (class_exists('TIX_Customer_URL') && class_exists('TIX_Org_Domains')) {
        $host = "plan-$sfx.example.test";
        $map = (array) get_option(TIX_Customer_URL::OPTION_DOMAINS, []);
        $map_before = $map;
        $map[$host] = $oa;
        update_option(TIX_Customer_URL::OPTION_DOMAINS, $map);
        TIX_Plan::save_org($oa, ['website' => true]);
        wp_set_current_user(0);
        list($st, $d) = tp_rest('GET', "/public/domains/$host");
        tp_eq('Domain mit Website: kein redirect', false, isset($d['domain']['redirect']));
        TIX_Plan::save_org($oa, ['website' => false]);
        list($st, $d) = tp_rest('GET', "/public/domains/$host");
        tp_eq('Domain ohne Website: 200', 200, $st);
        tp_eq('Domain ohne Website: website false', false, $d['domain']['website'] ?? null);
        tp_eq('Domain ohne Website: redirect auf /v/<slug>/', true, (bool) preg_match('#/v/[^/]+/$#', (string) ($d['domain']['redirect'] ?? '')));
        list($st) = tp_rest('GET', '/public/domains/allowed', ['domain' => $host]);
        tp_eq('Zertifikat bleibt (allowed 200)', 200, $st);
        TIX_Plan::save_org($oa, ['website' => true]);
        list($st, $d) = tp_rest('GET', "/public/domains/$host");
        tp_eq('Website wieder an: kein redirect', false, isset($d['domain']['redirect']));
        update_option(TIX_Customer_URL::OPTION_DOMAINS, $map_before);
    }

    // 7. Migration: Immer-Module fest an, website aus Domain-Freigabe
    $oc = $mk_org("Plan C $sfx", $ua);
    update_post_meta($oc, '_tix_org_modules', wp_json_encode(['guestlist' => false, 'support' => false, 'music' => true]));
    update_post_meta($oc, '_tix_org_domain_allowed', '1');
    delete_option(TIX_Plan::MIGRATE);
    TIX_Plan::maybe_migrate();
    $raw = json_decode((string) get_post_meta($oc, '_tix_org_modules', true), true);
    tp_eq('Migration: guestlist an', true, $raw['guestlist'] ?? null);
    tp_eq('Migration: support an', true, $raw['support'] ?? null);
    tp_eq('Migration: music bleibt an', true, $raw['music'] ?? null);
    tp_eq('Migration: website aus Domain-Freigabe', true, $raw['website'] ?? null);
    tp_eq('Migration nur einmal (Option)', '1', get_option(TIX_Plan::MIGRATE));
}

// Aufräumen
wp_set_current_user(0);
foreach ($created_posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($created_users as $u) wp_delete_user($u);
echo "\n{$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
