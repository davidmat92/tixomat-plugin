<?php
/**
 * 1.38.859: Einladungs-Codes (Testshop-Passwort 24 h, Team/Inhaber 7 Tage) sterben nach einem Tippfehler nicht mehr.
 * Fehlversuch speichert nur mit der echten Restzeit weiter (ttl im Code bzw. Ablauf des Transients); Versuchsgrenze bleibt.
 *   wp eval-file …/test-invite-code-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_App_Account') || !method_exists('TIX_App_Account', 'code_remaining')) { echo "Tixomat ab 1.38.859 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$A = 'TIX_App_Account';
global $wpdb;

// Rein
$now = 1763300000;
$eq('Restzeit: ttl aus dem Code / Altbestand über Transient-Ablauf / sonst 15 min', [86400 - 3600, 500, 900 - 3600],
    [$A::code_remaining(['created' => $now - 3600, 'ttl' => 86400], $now), $A::code_remaining(['created' => $now - 3600], $now, $now + 500), $A::code_remaining(['created' => $now - 3600], $now)]);

$clear = function () use ($wpdb) { $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_app\\_rl\\_confirm%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_app\\_rl\\_confirm%'"); wp_cache_flush(); };
$clear();
$email = 'invite' . wp_rand() . '@example.test';
$uid = wp_insert_user(['user_login' => 'inv_' . wp_generate_password(6, false, false), 'user_pass' => wp_generate_password(20), 'user_email' => $email, 'role' => 'subscriber']);
$key = 'tix_app_code_' . md5(strtolower($email) . '|reset');
$confirm = function ($code, $pw = '') use ($email) {
    $x = new WP_REST_Request('POST', '/tixomat/v1/auth/code/confirm');
    $x->set_param('email', $email); $x->set_param('purpose', 'reset'); $x->set_param('code', $code); if ($pw !== '') $x->set_param('new_password', $pw);
    $r = rest_do_request($x); $d = $r->get_data(); return [$r->get_status(), is_array($d) ? ($d['code'] ?? ($d['error'] ?? '')) : ''];
};
$timeout = function () use ($key) { wp_cache_flush(); return intval(get_option('_transient_timeout_' . $key, 0)) - time(); };
$ext = wp_using_ext_object_cache();

// 24-h-Code (Testshop-Passwort), 1 h alt, ein Tippfehler
$code = $A::issue_invite_code($email, DAY_IN_SECONDS);
$d = get_transient($key); $d['created'] = time() - HOUR_IN_SECONDS; set_transient($key, $d, DAY_IN_SECONDS - HOUR_IN_SECONDS);
$wrong = $code === '000000' ? '111111' : '000000';
[$st] = $confirm($wrong);
$left = $timeout();
$eq('24-h-Code, 1 h alt: Tippfehler → Code bleibt ~23 h gültig (nicht 60 s)', [true, DAY_IN_SECONDS, true], [is_array(get_transient($key)), get_transient($key)['ttl'] ?? null, $ext || ($left > DAY_IN_SECONDS - HOUR_IN_SECONDS - 120 && $left <= DAY_IN_SECONDS - HOUR_IN_SECONDS)]);
[$st2] = $confirm($code, 'Neues-Passwort-123');
$eq('Danach richtiger Code setzt das Passwort', [200, true], [$st2, wp_check_password('Neues-Passwort-123', get_userdata($uid)->user_pass, $uid)]);

// 7-Tage-Einladung, Altbestand ohne ttl (vor 1.38.859 ausgestellt): Restzeit aus dem Transient-Ablauf
$clear();
$code = $A::issue_invite_code($email, 7 * DAY_IN_SECONDS);
$d = get_transient($key); unset($d['ttl']); $d['created'] = time() - 2 * HOUR_IN_SECONDS; set_transient($key, $d, 7 * DAY_IN_SECONDS - 2 * HOUR_IN_SECONDS);
$confirm($code === '000000' ? '111111' : '000000');
$left = $timeout();
$eq('Altbestand ohne ttl (7 Tage): Tippfehler kürzt nicht', true, $ext || $left > 7 * DAY_IN_SECONDS - 2 * HOUR_IN_SECONDS - 120);

// Versuchsgrenze bleibt (5 Fehlversuche, der 6. Versuch löscht den Code)
$clear();
$code = $A::issue_invite_code($email, DAY_IN_SECONDS);
$wrong = $code === '000000' ? '111111' : '000000';
$codes = []; for ($i = 0; $i < 5; $i++) $codes[] = $confirm($wrong)[1];
[$st6, $c6] = $confirm($code, 'Noch-ein-Passwort-1');
$eq('Versuchsgrenze bleibt: 5 Fehlversuche, danach auch der richtige Code abgelaufen', [array_fill(0, 5, 'code_invalid'), 'code_expired', false], [$codes, $c6, is_array(get_transient($key))]);

// Normaler Login-Code (15 min) bekommt ttl 900
$clear();
delete_transient($key);
$eq('Ausstellen speichert ttl (Einladung 24 h)', DAY_IN_SECONDS, ($A::issue_invite_code($email, DAY_IN_SECONDS) && is_array(get_transient($key))) ? intval(get_transient($key)['ttl'] ?? 0) : 0);

delete_transient($key);
$clear();
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($uid);
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
