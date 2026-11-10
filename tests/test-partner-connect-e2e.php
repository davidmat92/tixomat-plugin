<?php
/**
 * Zwei-Seiten-Test Kopplung per WP-CLI (1.38.708) über echtes HTTP – nur lokale Test-Seiten.
 * Plattform-Webserver mit WP_ENVIRONMENT_TYPE=local (HTTP statt HTTPS erlaubt).
 *   plat eval-file … setup-plat <kunden-host> [hosted|plain]   (Lizenz mit Hosting-Kurzname bzw. ohne, Empfang an, Sicherung)
 *   kk   eval-file … setup-kk                                  (Einstellungen sichern, Kopplung leeren)
 *   (dann wp tixomat partner verbinden|status auf kk)
 *   plat eval-file … check-plat <kunden-host> <erwartet pending 0|1>
 *   kk   eval-file … cleanup-kk  ·  plat eval-file … cleanup-plat
 */
if (!defined('ABSPATH') || !class_exists('TIX_Partners') || !method_exists('TIX_Partners', 'connect')) { echo "Tixomat ab 1.38.708 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = (string) ($args[0] ?? '');
global $wpdb;
$GLOBALS['pc_f'] = 0; $GLOBALS['pc_n'] = 0;
function pc_eq($label, $exp, $act) {
    $GLOBALS['pc_n']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['pc_f']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
if ($mode === 'setup-plat') {
    $host = (string) $args[1];
    update_option('tix_pc_backup', ['partners' => get_option(TIX_Partners::OPTION, null), 'settings' => get_option('tix_settings', null), 'lic' => get_option('tix_license_server', null)], false);
    $s = get_option('tix_settings', []); $s = is_array($s) ? $s : [];
    $s['syndication_receive_enabled'] = 1;
    update_option('tix_settings', $s);
    $all = (array) get_option(TIX_Partners::OPTION, []);
    foreach ($all as $k => $p) if (strpos((string) ($p['api_base'] ?? ''), '//' . $host . '/') !== false) unset($all[$k]);
    update_option(TIX_Partners::OPTION, $all, false);
    update_option('tix_license_server', '1');
    if (($args[2] ?? 'hosted') === 'hosted') {
        $wpdb->insert($wpdb->prefix . 'tix_licenses', ['license_key' => 'PCTEST-' . wp_generate_password(8, false), 'customer' => 'PCTEST Kunde', 'email' => 'pctest@example.test', 'max_sites' => 1, 'status' => 'active', 'note' => '[kunden-hetzner:pctest]', 'created_at' => current_time('mysql')]);
        $wpdb->insert($wpdb->prefix . 'tix_license_sites', ['license_id' => $wpdb->insert_id, 'host' => $host, 'dev' => 0, 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql')]);
    }
    echo "setup-plat ok\n"; return;
}
if ($mode === 'check-plat') {
    $host = (string) $args[1]; $exp_pending = intval($args[2] ?? 0);
    $p = null;
    foreach (TIX_Partners::all() as $x) if (strpos((string) $x['api_base'], '//' . $host . '/') !== false) $p = $x;
    pc_eq('Plattform: Partner angelegt', true, (bool) $p);
    pc_eq('Plattform: pending wie erwartet, Verkauf aus', [$exp_pending, 0], [intval($p['pending'] ?? -1), intval($p['sales_enabled'] ?? -1)]);
    pc_eq('Plattform: Veranstalter-Profil', true, intval($p['organizer_id'] ?? 0) > 0);
    pc_eq('Plattform: Profil Art site nur bei Hosting', !$exp_pending, get_post_meta(intval($p['organizer_id'] ?? 0), '_tix_org_kind', true) === 'site');
    if (!$exp_pending) {
        TIX_Platform_Customers::flush();
        $items = TIX_Co_Sites::search('pctest');
        $hit = array_values(array_filter($items, function ($i) use ($host) { return $i['host'] === $host; }));
        pc_eq('Co-Suche: Kunden-Seite bereit', [1, true, 'h-pctest'], [count($hit), $hit[0]['ready'] ?? null, $hit[0]['key'] ?? '']);
    }
    echo ($GLOBALS['pc_f'] ? "FEHLER: {$GLOBALS['pc_f']}" : 'ALLES OK') . " ({$GLOBALS['pc_n']} Prüfungen)\n"; return;
}
if ($mode === 'setup-kk') {
    update_option('tix_pc_backup_kk', ['settings' => get_option('tix_settings', null), 'conn' => get_option(TIX_Partners::OPT_CONNECT, null)], false);
    $s = get_option('tix_settings', []); $s = is_array($s) ? $s : [];
    foreach (['syndication_api_url', 'syndication_api_key', 'partner_api_key'] as $k) $s[$k] = '';
    $s['syndication_enabled'] = 0; $s['partner_api_enabled'] = 0;
    update_option('tix_settings', $s);
    delete_option(TIX_Partners::OPT_CONNECT);
    echo "setup-kk ok\n"; return;
}
if ($mode === 'cleanup-kk') {
    $b = get_option('tix_pc_backup_kk');
    if (is_array($b)) {
        if ($b['settings'] === null) delete_option('tix_settings'); else update_option('tix_settings', $b['settings']);
        if ($b['conn'] === null) delete_option(TIX_Partners::OPT_CONNECT); else update_option(TIX_Partners::OPT_CONNECT, $b['conn'], false);
    }
    delete_option('tix_pc_backup_kk');
    wp_clear_scheduled_hook('tix_syndication_push_all');
    echo "cleanup-kk ok\n"; return;
}
if ($mode === 'cleanup-plat') {
    $b = get_option('tix_pc_backup');
    if (is_array($b)) {
        foreach (TIX_Partners::all() as $p) if (!empty($p['organizer_id']) && !in_array($p['id'], array_keys((array) $b['partners']), true)) wp_delete_post(intval($p['organizer_id']), true);
        foreach (['partners' => TIX_Partners::OPTION, 'settings' => 'tix_settings', 'lic' => 'tix_license_server'] as $k => $o) { if ($b[$k] === null) delete_option($o); else update_option($o, $b[$k], false); }
    }
    $ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}tix_licenses WHERE customer = 'PCTEST Kunde'");
    foreach ($ids as $id) { $wpdb->delete($wpdb->prefix . 'tix_license_sites', ['license_id' => $id]); $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $id]); }
    delete_option('tix_pc_backup');
    echo "cleanup-plat ok\n"; return;
}
echo "Modus fehlt\n";
