<?php
/** Domain-Umzug (TIX_Domain_Move, §98). wp eval-file …/tests/test-domain-move-wp.php – zieht die Seite um und wieder zurück. Nur lokale Test-DB! */
if (!defined('ABSPATH') || !class_exists('TIX_Domain_Move')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function dm_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
global $wpdb;
$from = TIX_Domain_Move::current_host();
$home0 = get_option('home'); $site0 = get_option('siteurl');
$to = 'umzug-test-' . wp_rand(100, 999) . '.example';
$hist0 = get_option(TIX_Domain_Move::OPT_HISTORY, null);
$wd0 = get_option('tix_website_domain', null);
update_option('tix_website_domain', ['status' => 'live', 'live_host' => $from, 'preview_host' => $from, 'switched_at' => '2026-01-01T00:00:00+00:00']);
$pid = wp_insert_post(['post_title' => 'DM', 'post_status' => 'draft', 'post_content' => "Link https://$from/events/x/ und http://www.$from/y"]);
update_post_meta($pid, '_dm_json', wp_slash(wp_json_encode(['u' => "https://$from/z"])));
update_post_meta($pid, '_dm_ser', ['u' => "https://$from/s", 'n' => 'längerer Text']);

dm_eq('Ungültige Domain → Fehler', 'tix_invalid_field', TIX_Domain_Move::move('kein host')->get_error_code());
$dry = TIX_Domain_Move::move($to, ['dry_run' => true]);
dm_eq('Probelauf ändert nichts', [true, $home0], [$dry['dry_run'], get_option('home')]);

$r = TIX_Domain_Move::move("https://www.$to/", []);
wp_cache_flush();
dm_eq('Umzug: home/siteurl', ["https://$to", "https://$to"], [get_option('home'), get_option('siteurl')]);
$c = get_post_field('post_content', $pid);
dm_eq('Inhalt ersetzt (https und http://www)', "Link https://$to/events/x/ und https://$to/y", $c);
dm_eq('JSON-Meta ersetzt (\\/\\/)', "https://$to/z", json_decode(get_post_meta($pid, '_dm_json', true), true)['u'] ?? '');
dm_eq('Serialisierte Meta ersetzt und lesbar', "https://$to/s", (get_post_meta($pid, '_dm_ser', true)['u'] ?? ''));
$wd = get_option('tix_website_domain');
dm_eq('Website-Adresse umgestellt, switched_at bleibt', [$to, $to, '2026-01-01T00:00:00+00:00'], [$wd['live_host'], $wd['preview_host'], $wd['switched_at']]);
$h = TIX_Domain_Move::history();
dm_eq('Verlauf', [$from, $to], [end($h)['from'] ?? '', end($h)['to'] ?? '']);
dm_eq('Status nennt den neuen Host', $to, TIX_Domain_Move::status_payload()['host']);
dm_eq('guid unverändert', 0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND guid LIKE %s", $pid, '%' . $wpdb->esc_like($to) . '%')));

dm_eq('Gleiche Domain → Fehler', 'tix_domain_same', TIX_Domain_Move::move($to)->get_error_code());
// zweiter Umzug (z. B. Übergangsadresse → eigene Domain)
$to2 = 'eigene-' . wp_rand(100, 999) . '.example';
TIX_Domain_Move::move($to2, []);
wp_cache_flush();
dm_eq('Zweiter Umzug: Inhalt auf neuer Domain', "Link https://$to2/events/x/ und https://$to2/y", get_post_field('post_content', $pid));
dm_eq('Verlauf hat beide Umzüge', 2, count(array_slice(TIX_Domain_Move::history(), -2)));
// Test-DB zurückstellen (Ausgangs-Host ohne Punkt ist kein gültiges Ziel)
foreach (["https://$to2" => rtrim($home0, '/'), 'https:\\/\\/' . $to2 => str_replace('/', '\\/', rtrim($home0, '/'))] as $a => $b) TIX_Domain_Move::search_replace($a, $b);

// Lizenz-Server: Host umziehen
if (class_exists('TIX_License_Server')) {
    $keep = get_option('tix_license_server', null); update_option('tix_license_server', '1'); TIX_License_Server::maybe_install();
    $lic = TIX_License_Server::create(['customer' => 'DM', 'email' => 'dm@example.test', 'note' => '[kunden-hetzner:dmtest] Host alt.example']);
    $wpdb->insert($wpdb->prefix . 'tix_license_sites', ['license_id' => $lic->id, 'host' => 'alt.example', 'dev' => 0, 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql')]);
    dm_eq('move-host: unbekannte alte Domain → Fehler', 'tix_host_unknown', TIX_Domain_Move::move_host($lic->id, 'fremd.example', 'neu.example')->get_error_code());
    $m = TIX_Domain_Move::move_host($lic->id, 'https://www.alt.example/', 'neu.example');
    dm_eq('move-host: umgezogen', true, $m['moved'] ?? false);
    dm_eq('… Eintrag hat neuen Host, Notiz angepasst', ['neu.example', '[kunden-hetzner:dmtest] Host neu.example'], [$wpdb->get_var($wpdb->prepare("SELECT host FROM {$wpdb->prefix}tix_license_sites WHERE license_id = %d", $lic->id)), TIX_License_Server::get($lic->id)->note]);
    dm_eq('… Verlauf move_host', 1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND action = 'move_host'", $lic->id)));
    foreach (['tix_licenses' => 'id', 'tix_license_sites' => 'license_id', 'tix_license_log' => 'license_id'] as $tb => $col) $wpdb->delete($wpdb->prefix . $tb, [$col => $lic->id]);
    if ($keep === null) delete_option('tix_license_server'); else update_option('tix_license_server', $keep);
}

wp_delete_post($pid, true);
update_option('home', $home0); update_option('siteurl', $site0);
if ($hist0 === null) delete_option(TIX_Domain_Move::OPT_HISTORY); else update_option(TIX_Domain_Move::OPT_HISTORY, $hist0, false);
if ($wd0 === null) delete_option('tix_website_domain'); else update_option('tix_website_domain', $wd0);
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
