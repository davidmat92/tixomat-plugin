<?php
/** Integrationstest Studio-Audit 2026-11-03 M19 (Konflikt-Prüfung updated_at bei App-Inhalten und Veranstalter-Seite). wp eval-file …/tests/test-audit-m19-wp.php – einmal ohne, einmal mit tix_multi_organizer=1 */
if (!defined('ABSPATH') || !class_exists('TIX_App_Config')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function m19_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function m19_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$cfg_before = get_option('_tix_app_config', null); $upd_before = get_option('_tix_app_config_updated', null);

// ── App-Inhalte (Einzel-Seite: Inhaber, Plattform: Admin) ──
if ($multi) wp_set_current_user(1);
else { $owner = wp_insert_user(['user_login' => "m19_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "m19_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner; wp_set_current_user($owner); }
[$st, $d] = m19_rest('GET', '/app/config/edit');
m19_eq('App-Inhalte: GET liefert updated_at', true, $st === 200 && is_array($d) && array_key_exists('updated_at', $d));
$u0 = (string) ($d['updated_at'] ?? '');
[$st, $d] = m19_rest('POST', '/app/config', ['home' => ['hashtag' => '#m19a'], 'updated_at' => $u0]);
m19_eq('App-Inhalte: Speichern mit aktuellem Stand', 200, $st);
$u1 = (string) ($d['updated_at'] ?? '');
m19_eq('App-Inhalte: neuer Stand nach dem Speichern', true, $u1 !== '' && $u1 !== $u0);
m19_eq('App-Inhalte: updated_at nicht in den Inhalten', false, array_key_exists('updated_at', (array) get_option('_tix_app_config')));
[$st, $d, $code] = m19_rest('POST', '/app/config', ['home' => ['hashtag' => '#m19alt'], 'updated_at' => $u0]);
m19_eq('App-Inhalte: veralteter Stand → 409', [409, 'tix_app_config_conflict'], [$st, $code]);
m19_eq('App-Inhalte: nach 409 unverändert', '#m19a', (string) (get_option('_tix_app_config')['home']['hashtag'] ?? ''));
[$st] = m19_rest('POST', '/app/config', ['home' => ['hashtag' => '#m19ohne']]);
m19_eq('App-Inhalte: ohne Feld (ältere Builds) wird gespeichert', 200, $st);
[$st, $d] = m19_rest('GET', '/app/config/edit');
m19_eq('App-Inhalte: GET zeigt den neuesten Stand', true, (string) ($d['updated_at'] ?? '') !== $u1);
if ($cfg_before === null) delete_option('_tix_app_config'); else update_option('_tix_app_config', $cfg_before, false);
if ($upd_before === null) delete_option('_tix_app_config_updated'); else update_option('_tix_app_config_updated', $upd_before, false);

// ── Veranstalter-Seite (nur Plattform) ──
if ($multi) {
    $own = wp_insert_user(['user_login' => "m19_org_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "m19_org_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $own;
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'M19 Veranstalter', 'meta_input' => ['_tix_org_user_id' => $own]]); $posts[] = $org;
    wp_set_current_user($own);
    [$st, $d] = m19_rest('GET', '/organizer/profile');
    m19_eq('Profil: GET liefert updated_at', true, $st === 200 && array_key_exists('updated_at', (array) $d));
    $p0 = (string) ($d['updated_at'] ?? '');
    [$st, $d] = m19_rest('POST', '/organizer/profile', ['tagline' => 'Eins', 'updated_at' => $p0]);
    m19_eq('Profil: Speichern mit aktuellem Stand', 200, $st);
    $p1 = (string) ($d['updated_at'] ?? '');
    m19_eq('Profil: Antwort hat neuen Stand', true, $p1 !== '' && $p1 !== $p0);
    [$st, $d, $code] = m19_rest('POST', '/organizer/profile', ['tagline' => 'Alt', 'updated_at' => $p0]);
    m19_eq('Profil: veralteter Stand → 409', [409, 'tix_profile_conflict'], [$st, $code]);
    m19_eq('Profil: nach 409 unverändert', 'Eins', (string) get_post_meta($org, '_tix_org_landing_tagline', true));
    [$st] = m19_rest('POST', '/organizer/profile', ['tagline' => 'Ohne']);
    m19_eq('Profil: ohne Feld wird gespeichert', 200, $st);
    m19_eq('Profil: ohne Feld übernommen', 'Ohne', (string) get_post_meta($org, '_tix_org_landing_tagline', true));
    // Partner-Brücke (KitchenKlub/Mallorca → evendis) nutzt dieselbe Prüfung
    $cur = (string) get_post_meta($org, '_tix_org_profile_updated', true);
    $b = ['tagline' => 'X', 'updated_at' => $p0];
    $c = TIX_App_Scope::profile_conflict($org, $b);
    m19_eq('Brücke: veralteter Stand → 409', 'tix_profile_conflict', is_wp_error($c) ? $c->get_error_code() : '');
    $b = ['tagline' => 'X', 'updated_at' => $cur];
    m19_eq('Brücke: aktueller Stand geht, Feld entfernt', [null, false], [TIX_App_Scope::profile_conflict($org, $b), array_key_exists('updated_at', $b)]);
}

wp_set_current_user(1);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
