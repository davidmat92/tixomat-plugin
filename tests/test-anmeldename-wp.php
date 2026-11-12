<?php
/**
 * Anmeldenamen ändern (1.38.764). Einzel-Seite: Ausführung wie aus der Akte (TIX_Site_Users::exec 'login');
 * Plattform: POST /platform/users/{id}/login und Team eines Veranstalters.   wp eval-file …/test-anmeldename-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Site_Users') || !method_exists('TIX_Site_Users', 'exec_login')) { echo "Tixomat ab 1.38.764 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$code = function ($r) { return is_wp_error($r) ? $r->get_error_code() : 'ok'; };
$tmp = []; $tmp_p = [];
$mk = function ($login, $role, $email = null) use (&$tmp) { $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(20), 'user_email' => $email ?: $login . '@example.test', 'role' => $role, 'display_name' => 'Test ' . $login]); $tmp[] = $id; return $id; };
$mails = [];
add_filter('pre_wp_mail', function ($r, $atts) use (&$mails) { $mails[] = $atts; return true; }, 10, 2);
$by = ['name' => 'evendis Admin', 'email' => 'admin@evendis.test'];
try {
    $sfx = wp_rand(1000, 9999);
    if (!$multi) {
        echo "== Kunden-Seite\n";
        $team = $mk('an_team' . $sfx, 'tix_organizer');
        $owner = $mk('an_owner' . $sfx, 'administrator'); update_user_meta($owner, '_tix_site_owner', 1);
        $wpadm = $mk('an_wpadm' . $sfx, 'administrator');
        $tech = $mk('an_tech' . $sfx, 'tix_organizer'); update_user_meta($tech, '_tix_technical', '1');
        $other = $mk('an_other' . $sfx, 'tix_customer', 'belegt' . $sfx . '@example.test');
        // App-Token zum Prüfen „bleibt gültig“
        update_user_meta($team, '_tix_app_tokens', [['token' => hash('sha256', 'x' . $sfx), 'created' => time(), 'expires' => time() + 3600]]);
        $x = function ($id, $login, $end = false) use ($by) { return TIX_Site_Users::exec('login', ['id' => $id, 'login' => $login, 'end_sessions' => $end], 0, $by); };
        foreach ([['ab', 'tix_invalid_field'], ['Hallo Welt!', 'tix_invalid_field'], ['evendis-x' . $sfx, 'tix_invalid_field'], ['an_owner' . $sfx, 'tix_login_taken'], ['belegt' . $sfx . '@example.test', 'tix_login_taken'], ['an_team' . $sfx, 'tix_login_same']] as [$l, $c]) {
            $eq("Prüfung „{$l}“ → $c", $c, $code($x($team, $l)));
        }
        $eq('technisches Konto gesperrt', 'tix_user_technical', $code($x($tech, 'neu' . $sfx)));
        $eq('fremder WP-Admin gesperrt', 'tix_login_admin', $code($x($wpadm, 'neu' . $sfx)));
        $r = $x($team, ' Neu.Team' . $sfx . ' ');
        $eq('Team: geändert, kleingeschrieben, alter Name zurück, App-Token bleibt, Mail', ['neu.team' . $sfx, 'an_team' . $sfx, false, true, true],
            [$r['user']['login'] ?? null, $r['user']['old_login'] ?? null, $r['sessions_ended'] ?? null, (bool) get_user_meta($team, '_tix_app_tokens', true), $r['mailed'] ?? null]);
        $eq('Anmeldung mit neuem Namen gefunden, alter nicht', [$team, false], [intval(get_user_by('login', 'neu.team' . $sfx)->ID ?? 0), get_user_by('login', 'an_team' . $sfx)]);
        $eq('Mail mit altem und neuem Namen', true, (bool) array_filter($mails, function ($m) use ($sfx) { return strpos((string) $m['message'], 'neu.team' . $sfx) !== false && strpos((string) $m['message'], 'an_team' . $sfx) !== false; }));
        $r = $x($owner, 'chef' . $sfx, true);
        $eq('Inhaber (WP-Admin) erlaubt, überall abmelden: Tokens weg', ['chef' . $sfx, true, ''], [$r['user']['login'] ?? null, $r['sessions_ended'] ?? null, get_user_meta($owner, '_tix_app_tokens', true)]);
        if (class_exists('TIX_Customers')) {
            global $wpdb;
            $row = $wpdb->get_row("SELECT * FROM " . TIX_Customers::audit_table() . " WHERE action = 'user_login_change' ORDER BY id DESC LIMIT 1", ARRAY_A);
            $sm = json_decode((string) ($row['summary'] ?? ''), true);
            $eq('Protokoll user_login_change alt → neu, durch wen', ['an_owner' . $sfx, 'chef' . $sfx, 'admin@evendis.test'], [$sm['old'] ?? null, $sm['new'] ?? null, $sm['by'] ?? null]);
        }
        $eq('Konto ohne echte E-Mail: keine Mail', false, (function () use ($mk, $x, $sfx) { $id = $mk('an_fb' . $sfx, 'tix_organizer', 'an_fb' . $sfx . '@x.ohne-mail.invalid'); return $x($id, 'fb' . $sfx)['mailed'] ?? null; })());
    } else {
        echo "== Plattform\n";
        $adm = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($adm[0]->ID);
        $cust = $mk('an_kunde' . $sfx, 'tix_customer');
        $adm2 = $mk('an_adm2' . $sfx, 'administrator');
        $rest = function ($id, $b) { $q = new WP_REST_Request('POST', '/tixomat/v1/platform/users/' . $id . '/login'); $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null]; };
        [$st, $d] = $rest($cust, ['login' => 'kunde.neu' . $sfx]);
        $eq('Kundenkonto auf evendis umbenannt', [200, 'kunde.neu' . $sfx], [$st, $d['user']['login'] ?? null]);
        [$st, , $c] = $rest($adm2, ['login' => 'x' . $sfx]);
        $eq('anderer Plattform-Admin: 409', [409, 'tix_login_admin'], [$st, $c]);
        wp_set_current_user($cust);
        $eq('kein Admin: 403', 403, $rest($cust, ['login' => 'y' . $sfx])[0]);
        wp_set_current_user($adm[0]->ID);
        // Team eines Veranstalters (Provisions-/Co-Kunde)
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AN Veranstalter']); $tmp_p[] = $org;
        $tm = $mk('an_orgteam' . $sfx, 'tix_organizer'); update_user_meta($tm, TIX_App_Scope::META_TEAM_ORG, $org);
        $r = TIX_Site_Users::exec('login', ['id' => $tm, 'login' => 'orgteam.neu' . $sfx], $org, $by);
        $eq('Team eines Veranstalters umbenannt', 'orgteam.neu' . $sfx, $r['user']['login'] ?? $code($r));
        $eq('fremdes Konto im Veranstalter-Team: 404', 'tix_no_user', $code(TIX_Site_Users::exec('login', ['id' => $cust, 'login' => 'z' . $sfx], $org, $by)));
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_all_filters('pre_wp_mail');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp as $id) if ($id && !is_wp_error($id)) wp_delete_user($id);
    foreach ($tmp_p as $id) wp_delete_post($id, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
