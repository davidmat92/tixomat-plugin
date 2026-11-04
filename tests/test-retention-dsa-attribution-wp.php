<?php
/**
 * Test Löschfristen „Event melden“ (2 Jahre) und Herkunft an der Bestellung (25 Monate), TIX_Retention 1.38.623,
 * dazu Vertragsvorlage §§ 8a–8e und Schalter „Hilfe vor Ort“. Räumt auf.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-retention-dsa-attribution-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Retention')) { echo "WordPress mit Tixomat nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function rt_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
global $wpdb;
$now = current_time('timestamp');
$ago = function ($spec) use ($now) { return wp_date('Y-m-d H:i:s', strtotime($spec, $now)); };

// ── Support-Anfragen ──
$mk = function ($cat, $status, $modified, $closed = null) use ($wpdb) {
    $id = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_status' => 'publish', 'post_title' => 'RT ' . $cat]);
    update_post_meta($id, '_tix_sp_category', $cat);
    update_post_meta($id, '_tix_sp_email', 'rt@example.com');
    $wpdb->update($wpdb->posts, ['post_status' => $status, 'post_modified' => $modified, 'post_modified_gmt' => $modified], ['ID' => $id]);
    clean_post_cache($id);
    if ($closed) update_post_meta($id, TIX_Retention::META_CLOSED, $closed); else delete_post_meta($id, TIX_Retention::META_CLOSED);
    return $id;
};
$dsa_old    = $mk('event_report', 'tix_closed', $ago('-3 years'), $ago('-3 years'));
$dsa_new    = $mk('event_report', 'tix_closed', $ago('-1 year'), $ago('-1 year'));
$dsa_open   = $mk('event_report', 'tix_open', $ago('-25 months'));
$dsa_open_n = $mk('event_report', 'tix_open', $ago('-3 months'));
$sup_old    = $mk('allgemein', 'tix_closed', $ago('-3 years'), $ago('-3 years'));

rt_eq('DSA fällig (Dry-Run) enthält alte Meldung', true, in_array($dsa_old, TIX_Retention::dsa_due(100000), true));
rt_eq('DSA fällig enthält offene, 25 Monate unveränderte Meldung', true, in_array($dsa_open, TIX_Retention::dsa_due(100000), true));
rt_eq('DSA fällig ohne junge Meldung', false, in_array($dsa_new, TIX_Retention::dsa_due(100000), true));
rt_eq('Support-Frist (Anonymisieren) lässt DSA aus', false, in_array($dsa_old, TIX_Retention::support_due(100000), true));
rt_eq('Support-Frist greift weiter für normale Anfrage', true, in_array($sup_old, TIX_Retention::support_due(100000), true));

// ── Herkunft ──
TIX_Order_Attribution::maybe_create();
$ot = TIX_Order::table_name();
$at = TIX_Order_Attribution::table();
$base = 990000000 + wp_rand(0, 9999) * 10;
$o_old = $base + 1; $o_new = $base + 2; $o_orphan = $base + 3;
$ins = function ($id, $date) use ($wpdb, $ot) {
    $ok = $wpdb->insert($ot, ['id' => $id, 'order_number' => 'RT-' . $id, 'date_created' => $date]);
    if ($ok === false) echo "  (Bestellung $id nicht angelegt: {$wpdb->last_error})\n";
};
$ins($o_old, $ago('-26 months'));
$ins($o_new, $ago('-24 months'));
foreach ([$o_old, $o_new, $o_orphan] as $o) TIX_Order_Attribution::set($o, ['source' => 'rt', 'campaign' => 'rt-test']);
$wpdb->update($at, ['updated' => gmdate('Y-m-d H:i:s', strtotime('-26 months'))], ['order_id' => $o_orphan]);

$has = function ($o) use ($wpdb, $at) { return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $at WHERE order_id = %d", $o))) === 1; };
$dry_before = TIX_Retention::attribution(true);
rt_eq('Herkunft Dry-Run zählt mindestens 2', true, $dry_before >= 2);
rt_eq('Dry-Run löscht nichts', true, $has($o_old) && $has($o_orphan));

// ── Lauf ──
$r = TIX_Retention::run(false);
rt_eq('Lauf liefert dsa + attribution', true, isset($r['dsa'], $r['attribution']));
rt_eq('alte DSA-Meldung gelöscht', null, get_post($dsa_old));
rt_eq('offene alte DSA-Meldung gelöscht', null, get_post($dsa_open));
rt_eq('junge DSA-Meldung bleibt', true, (bool) get_post($dsa_new));
rt_eq('junge offene DSA-Meldung bleibt', true, (bool) get_post($dsa_open_n));
rt_eq('junge DSA-Meldung nicht anonymisiert', 'rt@example.com', (string) get_post_meta($dsa_new, '_tix_sp_email', true));
rt_eq('normale alte Anfrage bleibt (anonymisiert)', true, (bool) get_post($sup_old) && get_post_meta($sup_old, TIX_Retention::META_ANON, true) !== '');
rt_eq('Herkunft 26 Monate gelöscht', false, $has($o_old));
rt_eq('Herkunft 24 Monate bleibt', true, $has($o_new));
rt_eq('Herkunft ohne Bestellung, 26 Monate alt, gelöscht', false, $has($o_orphan));
rt_eq('zweiter Lauf: keine fällige DSA-Meldung von uns', [], array_values(array_intersect([$dsa_new, $dsa_open_n], TIX_Retention::dsa_due(100000))));

// ── Vertragsvorlage + Schalter ──
$tpl = (string) file_get_contents(TIXOMAT_PATH . 'includes/contract/vermittlungsvertrag.md');
foreach (['8a', '8b', '8c', '8d', '8e', '8f'] as $p) rt_eq("Vorlage enthält § $p", true, strpos($tpl, "### § $p ") !== false);
rt_eq('§ 8e vor § 9', true, strpos($tpl, '### § 8e') < strpos($tpl, '### § 9'));
$blk = substr($tpl, strpos($tpl, '### § 8a'), strpos($tpl, '### § 9') - strpos($tpl, '### § 8a'));
rt_eq('§§ 8a–8f ohne [PRÜFEN]-Marken', false, strpos($blk, 'PRÜFEN') !== false);
if (class_exists('TIX_Contract_Template') && TIX_Contract_Template::active() && get_option(TIX_Contract_Template::OPTION_TEMPLATE, '') === '') {
    $vs = TIX_Contract_Template::versions();
    $last = $vs ? end($vs) : null;
    $d = TIX_Contract_Template::detect();
    if ($last && ($last['values']['vorlage'] ?? '') !== hash('sha256', TIX_Contract_Template::template())) {
        rt_eq('Vorlage geändert → Entwurf mit Änderung „vorlage“', true, is_array($d) && in_array('vorlage', array_column((array) $d['changes'], 'key'), true));
        rt_eq('Entwurf braucht neue Zustimmung (nicht „favorable“)', true, is_array($d) && $d['auto'] !== 'favorable');
    } else echo "  (Vertragsversion: keine veröffentlichte Version zum Vergleich)\n";
} else echo "  (Vertragsvorlage hier nicht aktiv – Versionsprüfung übersprungen)\n";
rt_eq('Vorlage ohne HTML', false, (bool) preg_match('/<\/?(li|ol|h3|strong)>/', $tpl));
$aw = (string) file_get_contents(TIXOMAT_PATH . 'includes/class-tix-awareness.php');
rt_eq('„Hilfe vor Ort“ ohne Hinweis auf rechtliche Prüfung', false, strpos($aw, 'erst nach der rechtlichen Prüfung') !== false);

// ── /info: Vertragsstand ──
$info = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/info'))->get_data();
if (class_exists('TIX_Contract') && TIX_Contract::multi()) {
    $c = $info['contract'] ?? null;
    rt_eq('/info contract vorhanden (Mehr-Veranstalter)', true, is_array($c));
    rt_eq('/info contract.source page|template', true, is_array($c) && in_array($c['source'], ['page', 'template'], true));
    rt_eq('/info contract hat current/pending/draft/custom_template', true, is_array($c) && array_key_exists('current', $c) && array_key_exists('pending', $c) && array_key_exists('draft', $c) && is_bool($c['custom_template']));
    $os = get_option(TIX_Org_Approval::OPTION);
    $tmp = is_array($os) ? $os : [];
    $tmp['terms_source'] = 'template';
    update_option(TIX_Org_Approval::OPTION, $tmp, false);
    $c2 = TIX_Contract_Template::info_payload();
    rt_eq('mit terms_source=template: active', true, $c2['source'] === 'template' && $c2['active'] === true);
    TIX_Contract_Template::detect();
    $c3 = TIX_Contract_Template::info_payload();
    rt_eq('Entwurf sichtbar mit Stichtag', true, is_array($c3['draft']) && $c3['draft']['autopublish_at'] !== '' && in_array('vorlage', $c3['draft']['changes'], true) || is_array($c3['draft']) && !$c3['current']);
    if (is_array($os)) update_option(TIX_Org_Approval::OPTION, $os, false); else delete_option(TIX_Org_Approval::OPTION);
    delete_option(TIX_Contract_Template::OPTION_DRAFT);
} else rt_eq('/info contract null auf Einzel-Seite', null, $info['contract'] ?? 'fehlt');

// ── Aufräumen ──
foreach ([$dsa_new, $dsa_open_n, $sup_old] as $id) wp_delete_post($id, true);
$wpdb->query("DELETE FROM $at WHERE order_id BETWEEN $base AND " . ($base + 9));
$wpdb->query("DELETE FROM $ot WHERE id BETWEEN $base AND " . ($base + 9));

echo "\n{$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
