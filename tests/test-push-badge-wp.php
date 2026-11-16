<?php
/**
 * Hotfix 1.38.854: rote 1 am App-Symbol – APNs-`badge` nur bei nutzerbezogenen Hinweisen, nicht bei Rundmails an alle,
 * Geräten ohne Konto und Team-Mitteilungen (Analyse uebergaben/kitchenklub-app.md, 16.11.).
 *   wp eval-file …/test-push-badge-wp.php
 * Es geht kein echter Push raus: APNs ist mit Platzhaltern „eingerichtet“, die JWT-Erzeugung scheitert vor dem Versand;
 * der Inhalt wird über die Aktion tix_apns_payload beobachtet.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Notifications') || !method_exists('TIX_Notifications', 'apns_payload')) { echo "Tixomat ab 1.38.854 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$N = 'TIX_Notifications';

// Reiner Inhalt
$eq('Ohne Angabe kein badge', false, array_key_exists('badge', $N::apns_payload('T', 'B')['aps']));
$eq('Mit Angabe badge = 1 (nie negativ)', [1, 0], [$N::apns_payload('T', 'B', [], 1)['aps']['badge'], $N::apns_payload('T', 'B', [], -3)['aps']['badge']]);
$eq('Alert und Ton unverändert', [['title' => 'T', 'body' => 'B'], 'default'], [$N::apns_payload('T', 'B')['aps']['alert'], $N::apns_payload('T', 'B')['aps']['sound']]);

// Abläufe: Platzhalter-Konfiguration sichern/setzen
$keys = ['_tix_apns_key', '_tix_apns_key_id', '_tix_apns_team_id', '_tix_push_tokens'];
$bak = []; foreach ($keys as $k) $bak[$k] = get_option($k, null);
update_option('_tix_apns_key', "-----BEGIN PRIVATE KEY-----\nplatzhalter\n-----END PRIVATE KEY-----", false);
update_option('_tix_apns_key_id', 'TESTKEY123', false);
update_option('_tix_apns_team_id', 'TESTTEAM12', false);
$uid = wp_insert_user(['user_login' => 'badge_' . wp_generate_password(6, false, false), 'user_pass' => wp_generate_password(20), 'user_email' => 'badge' . wp_rand() . '@example.test', 'role' => 'subscriber']);
$tok_user = str_repeat('a', 64); $tok_anon = str_repeat('b', 64);
update_option('_tix_push_tokens', [$tok_user => ['user' => $uid, 'platform' => 'ios', 'at' => time()], $tok_anon => ['user' => 0, 'platform' => 'ios', 'at' => time()]], false);
$seen = [];
$obs = function ($payload, $tokens, $topic) use (&$seen) { $seen[] = ['badge' => array_key_exists('badge', $payload['aps']) ? $payload['aps']['badge'] : null, 'tokens' => $tokens]; };
add_action('tix_apns_payload', $obs, 10, 3);
$feat = class_exists('TIX_Features') ? get_option('tix_features', null) : null;

// Rundmail an alle (Geräte mit und ohne Konto)
$seen = [];
$item = $N::add_broadcast('Rundmail Test', 'Hallo alle', ['type' => 'message']);
if ($item === null) echo "skip  Rundmail: Mitteilungen auf dieser Seite aus (TIX_Features)\n";
else $eq('Rundmail an alle: ein Versand an beide Geräte, kein badge (auch nicht für Geräte mit Konto)', [[null], 2], [array_column($seen, 'badge'), count($seen[0]['tokens'] ?? [])]);

// Nutzerbezogener Hinweis
$seen = [];
$N::add_user_item($uid, 'Dein Ticket', 'Ist da', ['type' => 'ticket']);
$eq('Nutzerbezogener Hinweis: badge 1, nur an die Geräte des Nutzers', [[1], [[$tok_user]]], [array_column($seen, 'badge'), array_column($seen, 'tokens')]);

// Team-Mitteilung (TIX_Staff_Push ruft apns_send mit Bundle, ohne badge)
$seen = [];
$N::apns_send([$tok_anon], 'Team', 'Neue Bestellung', ['action' => 'staff:order:1'], 'de.evendis.veranstalter');
$eq('Team-Mitteilung (apns_send mit Bundle): kein badge', [null], array_column($seen, 'badge'));

// Direkter Versand ohne Angabe (z. B. Tests, Geräte ohne Konto)
$seen = [];
$N::send_push([$tok_anon], 'Ohne Konto', 'Text');
$eq('send_push ohne Angabe: kein badge', [null], array_column($seen, 'badge'));

remove_action('tix_apns_payload', $obs, 10);
foreach ($bak as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v, false); }
if (class_exists('TIX_Notifications')) {
    // eigene Feed-Einträge entfernen (Rundmail bleibt sonst im Feed der Testseite)
    $b = get_option(TIX_Notifications::OPT_FEED, null);
    if (is_array($b) && isset($item['id'])) update_option(TIX_Notifications::OPT_FEED, array_values(array_filter($b, function ($x) use ($item) { return ($x['id'] ?? '') !== $item['id']; })), false);
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($uid);
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
