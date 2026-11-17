<?php
/**
 * W4 (1.38.885): Ort-Felder, Event-Extras, alte Serie entfernen, Rechtslinks der Kasse, Kunden-Mail/Passwort-Link,
 * abgebrochene Warenkörbe, Tipps wiederherstellen, Tischplan-Bild, info_html.   wp eval-file …/test-w4-kleinkram-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Abandoned_Carts_REST')) { echo "Tixomat ab 1.38.885 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = [], $files = null) { $req = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($q as $k => $v) $req->set_param($k, $v); if ($b !== null) { $req->set_header('content-type', 'application/json'); $req->set_body(wp_json_encode($b)); } if ($files) $req->set_file_params($files); $res = rest_do_request($req); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null]; };
$mails = [];
$cap = function ($atts) use (&$mails) { $mails[] = ['to' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'subject' => (string) $atts['subject'], 'body' => (string) $atts['message']]; return $atts; };
add_filter('wp_mail', $cap, 1); add_filter('pre_wp_mail', '__return_true', 99);
$demo = class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on();
$keep = get_option('tix_settings');
$sfx = substr(md5(microtime()), 0, 6);
$cleanup = ['posts' => [], 'users' => [], 'orders' => []];
try {
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);

    // ═══ V5 Ort ═══
    [$s, $d] = $rest('POST', '/locations', ['title' => 'W4 Ort ' . $sfx, 'capacity' => 450, 'transit' => 'U2 bis Nordstern', 'parking' => 'Parkhaus gegenüber', 'listed' => true]);
    $lid = intval($d['location']['id'] ?? 0); $cleanup['posts'][] = $lid;
    $eq('Ort angelegt mit Kapazität, Anfahrt, Ortsliste', [201, 450, 'U2 bis Nordstern', 'Parkhaus gegenüber', true], [$s, $d['location']['capacity'] ?? null, $d['location']['transit'] ?? null, $d['location']['parking'] ?? null, $d['location']['listed'] ?? null]);
    [$s, , , $fld] = $rest('POST', "/locations/$lid", ['capacity' => -3]);
    $eq('Kapazität negativ → 400 capacity', [400, 'capacity'], [$s, $fld]);
    [$s, $d] = $rest('POST', "/locations/$lid", ['capacity' => null, 'listed' => false]);
    $eq('Kapazität leeren, aus Ortsliste', [200, null, false, 'U2 bis Nordstern'], [$s, array_key_exists('capacity', (array) ($d['location'] ?? [])) ? $d['location']['capacity'] : 'x', $d['location']['listed'] ?? null, $d['location']['transit'] ?? null]);

    // ═══ V6 Event-Extras ═══
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'W4 Event ' . $sfx]); $cleanup['posts'][] = $ev;
    update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS));
    [$s, $d] = $rest('POST', "/events/$ev/extras", ['free_entry_note' => 'Spende am Eingang willkommen', 'abandoned_cart' => true, 'raffle_header' => ['bg' => '#112233', 'color' => '#FFFFFF']]);
    $eq('Extras gespeichert', [200, 'Spende am Eingang willkommen', true, ['bg' => '#112233', 'color' => '#ffffff']], [$s, $d['free_entry_note'] ?? null, $d['abandoned_cart'] ?? null, $d['raffle_header'] ?? null]);
    [$s, , , $fld] = $rest('POST', "/events/$ev/extras", ['raffle_header' => ['bg' => 'rot']]);
    $eq('Farbe ungültig → 400 raffle_header.bg', [400, 'raffle_header.bg'], [$s, $fld]);
    [$s, , , $fld] = $rest('POST', "/events/$ev/extras", ['free_entry_note' => str_repeat('x', 201)]);
    $eq('Hinweis zu lang → 400', [400, 'free_entry_note'], [$s, $fld]);
    delete_transient('tix_pub_events_d' . $ev);
    [, $d] = $rest('GET', "/public/events/$ev");
    $eq('öffentlich free_entry_note', 'Spende am Eingang willkommen', $d['event']['free_entry_note'] ?? null);

    // ═══ S7 info_html ═══
    update_post_meta($ev, '_tix_info_lineup', '<p>DJ <a href="https://example.test">Nord</a></p><ul><li>eins</li></ul><script>x</script>');
    [, $d] = $rest('GET', "/events/$ev/edit");
    $h = (string) (((array) (($d['event'] ?? $d)['info_html'] ?? []))['lineup'] ?? '');
    $eq('info_html: Link und Liste da, Skript weg', [true, true, false], [strpos($h, '<a href="https://example.test">') !== false, strpos($h, '<li>') !== false, strpos($h, '<script') !== false]);

    // ═══ Tischplan-Bild ═══
    $tmp = wp_tempnam('w4.png');
    $img = imagecreatetruecolor(4, 4); imagepng($img, $tmp); imagedestroy($img);
    [$s, $d] = $rest('POST', "/events/$ev/table-floor-plan", null, [], ['image' => ['name' => 'plan.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)]]);
    $fp = intval($d['floor_plan']['id'] ?? 0); if ($fp) $cleanup['posts'][] = $fp;
    $eq('Tischplan hochgeladen', [200, true], [$s, $fp > 0 && !empty($d['floor_plan']['url'])]);
    [, $d] = $rest('GET', "/events/$ev/edit");
    $tr = ($d['event'] ?? $d)['table_reservation'] ?? [];
    $eq('Editor: floor_plan + has_floor_plan', [$fp, true], [$tr['floor_plan']['id'] ?? null, $tr['has_floor_plan'] ?? null]);
    [$s, $d] = $rest('DELETE', "/events/$ev/table-floor-plan");
    $tr2 = get_post_meta($ev, '_tix_table_reservation', true);
    $eq('Tischplan entfernt', [200, null, false], [$s, array_key_exists('floor_plan', (array) $d) ? $d['floor_plan'] : 'x', isset($tr2['floor_plan_id'])]);
    [$s, , , $fld] = $rest('POST', "/events/$ev/table-floor-plan");
    $eq('ohne Bild → 400 image', [400, 'image'], [$s, $fld]);

    // ═══ V7 alte Serie ═══
    $par = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'W4 Serie ' . $sfx]); $cleanup['posts'][] = $par;
    $kid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'W4 Termin ' . $sfx]); $cleanup['posts'][] = $kid;
    update_post_meta($par, '_tix_series_enabled', '1'); update_post_meta($par, '_tix_series_mode', 'manual'); update_post_meta($par, '_tix_series_children', [$kid]);
    update_post_meta($kid, '_tix_series_parent', $par); update_post_meta($kid, '_tix_series_index', 1);
    [$s, , $c] = $rest('POST', "/events/$kid/series/drop-classic");
    $eq('am Termin → 409 tix_series_child', [409, 'tix_series_child'], [$s, $c]);
    [$s, $d] = $rest('POST', "/events/$par/series/drop-classic");
    $eq('alte Serie entfernt, Termin bleibt eigenes Event', [200, 1, '0', '', 'publish', false], [$s, $d['detached'] ?? null, (string) get_post_meta($par, '_tix_series_enabled', true), (string) get_post_meta($kid, '_tix_series_parent', true), get_post_status($kid), $d['series']['classic_series'] ?? null]);
    [$s, , $c] = $rest('POST', "/events/$par/series/drop-classic");
    $eq('nochmal → 409 tix_series_none', [409, 'tix_series_none'], [$s, $c]);

    // ═══ V8 Rechtslinks ═══
    $s0 = (array) $keep; foreach (['terms_url', 'privacy_url', 'revocation_url'] as $k) $s0[$k] = ''; update_option('tix_settings', $s0);
    $agb = TIX_Studio_Legal::page('agb');
    if (!$agb) { $pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AGB', 'post_name' => 'agb', 'post_content' => 'AGB']); $cleanup['posts'][] = $pid; }
    elseif ($agb->post_status !== 'publish') { echo "(AGB-Seite ist Entwurf – Quelle page nicht prüfbar)\n"; }
    [$s, $d] = $rest('GET', '/studio/legal');
    $eq('GET /studio/legal: checkout_links.terms aus dem Rechtstext', [200, 'page', true], [$s, $d['checkout_links']['terms']['source'] ?? null, (bool) ($d['checkout_links']['terms']['url'] ?? '')]);
    $eq('Kasse (Filter) nimmt den Rechtstext', true, (string) apply_filters('tix_checkout_terms_url', '') !== '');
    [$s, $d] = $rest('POST', '/studio/legal/checkout-links', ['terms' => 'https://example.test/agb-extern']);
    $eq('eigene URL hat Vorrang', [200, 'own', 'https://example.test/agb-extern'], [$s, $d['checkout_links']['terms']['source'] ?? null, $d['checkout_links']['terms']['url'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/studio/legal/checkout-links', ['terms' => 'http://unsicher.test']);
    $eq('ohne https → 400', [400, 'terms'], [$s, $fld]);
    [$s, $d] = $rest('POST', '/studio/legal/checkout-links', ['terms' => '']);
    $eq('leer = wieder aus dem Rechtstext', [200, 'page'], [$s, $d['checkout_links']['terms']['source'] ?? null]);

    // ═══ V11 Warenkörbe ═══
    $eq('cart_sums rein', [3, 50.0], TIX_Abandoned_Carts_REST::cart_sums('[{"qty":2,"price":20},{"quantity":1,"price":10},"x"]'));
    $ac = wp_insert_post(['post_type' => 'tix_abandoned_cart', 'post_status' => 'publish', 'post_title' => 'w4']); $cleanup['posts'][] = $ac;
    update_post_meta($ac, '_tix_ac_email', "korb_$sfx@example.test"); update_post_meta($ac, '_tix_ac_status', 'sent'); update_post_meta($ac, '_tix_ac_event_id', $ev);
    update_post_meta($ac, '_tix_ac_cart_data', wp_json_encode([['qty' => 2, 'price' => 15]]));
    [$s, $d] = $rest('GET', '/organizer/abandoned-carts', null, ['status' => 'sent']);
    $row = array_values(array_filter($d['items'] ?? [], function ($i) use ($ac) { return $i['id'] === $ac; }))[0] ?? [];
    $eq('Warenkorb in der Liste mit Event, 2 Tickets, 30 €', [200, $ev, 2, 30.0, 'sent', true], [$s, $row['event']['id'] ?? null, $row['tickets'] ?? null, $row['total'] ?? null, $row['status'] ?? null, ($d['counts']['sent'] ?? 0) >= 1]);

    // ═══ Tipps ═══
    $tip = wp_insert_post(['post_type' => 'tix_app_tip', 'post_status' => 'publish', 'post_title' => 'W4 Tipp ' . $sfx]); $cleanup['posts'][] = $tip;
    [$s] = $rest('POST', "/app/tips/$tip/delete");
    [$s2, $d] = $rest('GET', '/app/tips', null, ['status' => 'trash']);
    $eq('Tipp gelöscht, im Papierkorb gelistet', [200, 200, true], [$s, $s2, in_array($tip, array_column($d['tips'] ?? [], 'id'), true)]);
    [$s, $d] = $rest('POST', "/app/tips/$tip/restore");
    $eq('Tipp zurückgeholt, wieder veröffentlicht', [200, 'publish'], [$s, get_post_status($tip)]);
    [$s, , $c] = $rest('POST', "/app/tips/$tip/restore");
    $eq('nicht im Papierkorb → 409', [409, 'tix_not_trashed'], [$s, $c]);

    // ═══ V10 Kunden ═══
    $email = "w4kunde_$sfx@example.test";
    $uid = wp_insert_user(['user_login' => 'w4k_' . $sfx, 'user_pass' => wp_generate_password(), 'user_email' => $email, 'first_name' => 'Wanda', 'role' => 'subscriber']); $cleanup['users'][] = $uid;
    $wpdb->insert($wpdb->prefix . 'tix_orders', ['order_number' => 'W4-' . $sfx, 'status' => 'completed', 'total' => 10, 'subtotal' => 10, 'tax' => 0, 'discount' => 0, 'payment_method' => 'free', 'payment_method_title' => 'x',
        'billing_first_name' => 'Wanda', 'billing_last_name' => 'W', 'billing_email' => $email, 'customer_id' => $uid, 'wc_order_id' => 0, 'order_key' => 'tix_' . $sfx, 'date_created' => current_time('mysql')]);
    $oid = intval($wpdb->insert_id); $cleanup['orders'][] = $oid;
    $wpdb->insert($wpdb->prefix . 'tix_order_items', ['order_id' => $oid, 'product_id' => 0, 'event_id' => $ev, 'quantity' => 1, 'total' => 10, 'tax' => 0, 'name' => 'T']);
    [, $d] = $rest('GET', '/customers', null, ['search' => $email]);
    $cid = '';
    foreach ((array) ($d['customers'] ?? $d['items'] ?? []) as $c0) if (($c0['email'] ?? '') === $email) $cid = (string) $c0['id'];
    $eq('Kunde gefunden', true, $cid !== '');
    $mails = [];
    [$s, $d] = $rest('POST', "/customers/$cid/mail", ['subject' => 'Hallo {{first_name}}', 'message' => "Danke!\n<script>alert(1)</script><a href=\"https://example.test\">Link</a>"]);
    $m0 = $mails[0] ?? ['to' => '', 'subject' => '', 'body' => ''];
    $eq('Mail: gesendet, Platzhalter ersetzt, Skript entfernt', [200, $email, true, true, false], [$s, $d['email'] ?? null, $demo || $m0['subject'] === 'Hallo Wanda', $demo || strpos($m0['body'], 'https://example.test') !== false, strpos($m0['body'], '<script') !== false]);
    [$s, , , $fld] = $rest('POST', "/customers/$cid/mail", ['subject' => '', 'message' => 'x']);
    $eq('ohne Betreff → 400 subject', [400, 'subject'], [$s, $fld]);
    [$s] = $rest('POST', "/customers/$cid/password-link");
    $eq('Passwort-Link (Konto vorhanden)', 200, $s);
    $audit = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Customers::audit_table() . " WHERE action IN ('customer_mail','customer_password_link') AND subject_id = %s", $cid)));
    $eq('Änderungsprotokoll: Mail + Passwort-Link (ohne Inhalt)', 2, $audit);

    // ═══ Rechte ═══
    $sub = wp_insert_user(['user_login' => 'w4s_' . $sfx, 'user_pass' => wp_generate_password(), 'user_email' => "w4s_$sfx@example.test", 'role' => 'subscriber']); $cleanup['users'][] = $sub;
    wp_set_current_user($sub);
    [$s1] = $rest('GET', '/organizer/abandoned-carts');
    [$s2] = $rest('POST', "/events/$ev/table-floor-plan");
    [$s3] = $rest('POST', "/customers/$cid/mail", ['subject' => 'x', 'message' => 'y']);
    $eq('Kunde ohne Rolle → 401/403 überall', [true, true, true], [in_array($s1, [401, 403], true), in_array($s2, [401, 403], true), in_array($s3, [401, 403], true)]);
    wp_set_current_user($adm->ID);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('wp_mail', $cap, 1); remove_filter('pre_wp_mail', '__return_true', 99);
    update_option('tix_settings', $keep);
    foreach ($cleanup['orders'] as $o) { $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); }
    foreach ($cleanup['posts'] as $p) if ($p) wp_delete_post($p, true);
    foreach ($cleanup['users'] as $u) if ($u) wp_delete_user($u);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
