<?php
/**
 * Umschreiben mit Frist an einer Stelle (1.38.877): ticket_transfer_hours im Studio-Editor, Event-Frist für den Inhaberwechsel,
 * strengere Regel aus Ticket-Angaben (Bestand) gewinnt, Namenskorrektur unverändert nur nach Ticket-Angaben.
 *   wp eval-file …/test-transfer-hours-wp.php   (can_transfer/öffentlich nur mit globalem Schalter ticket_transfer_enabled)
 */
if (!defined('ABSPATH') || !method_exists('TIX_Ticket_Fields', 'effective_hours')) { echo "Tixomat ab 1.38.877 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); return [$res->get_status(), $res->get_data()]; };
$ev = 0; $tid = 0;
try {
    // ═══ Reine Funktionen ═══
    $off = ['personalize' => 'off', 'questions' => [], 'age_confirm' => 0, 'transfer' => 'never', 'transfer_hours' => 24];
    $on  = ['personalize' => 'required', 'questions' => [], 'age_confirm' => 0, 'transfer' => 'allowed', 'transfer_hours' => 24];
    $eq('Ticket-Angaben inaktiv: nur Event-Frist (never zählt nicht)', [0, 48], [TIX_Ticket_Fields::effective_hours($off, 0), TIX_Ticket_Fields::effective_hours($off, 48)]);
    $eq('Ticket-Angaben allowed: Event-Frist', 12, TIX_Ticket_Fields::effective_hours($on, 12));
    $eq('until_hours 24 vs. Event 72 / 6: strengere gewinnt', [72, 24], [TIX_Ticket_Fields::effective_hours(['transfer' => 'until_hours'] + $on, 72), TIX_Ticket_Fields::effective_hours(['transfer' => 'until_hours'] + $on, 6)]);
    $eq('never: nie (null), auch mit Event-Frist', [null, null], [TIX_Ticket_Fields::effective_hours(['transfer' => 'never'] + $on, 0), TIX_Ticket_Fields::effective_hours(['transfer' => 'never'] + $on, 24)]);
    $eq('within_hours an der Grenze', [true, false], [TIX_Ticket_Fields::within_hours(100 * HOUR_IN_SECONDS, 76 * HOUR_IN_SECONDS, 24), TIX_Ticket_Fields::within_hours(100 * HOUR_IN_SECONDS, 76 * HOUR_IN_SECONDS + 1, 24)]);

    // ═══ Studio-Editor ═══
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Frist Test']);
    $start = time() + 48 * HOUR_IN_SECONDS;
    update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', $start)); update_post_meta($ev, '_tix_time_start', wp_date('H:i', $start));
    $get = function () use ($rest, $ev) { [$st, $d] = $rest('GET', "/events/$ev/edit"); $e = $d['event'] ?? $d; return [$st, $e['ticket_transfer'] ?? null, $e['ticket_transfer_hours'] ?? null, array_key_exists('ticket_transfer_rule', (array) $e) ? $e['ticket_transfer_rule'] : 'fehlt']; };
    $eq('ohne Angabe: aus, Frist 0, keine Regel', [200, false, 0, null], $get());
    [$st] = $rest('POST', "/events/$ev", ['ticket_transfer' => true, 'ticket_transfer_hours' => 24]);
    $eq('„Bis 24 Std. vor Beginn“ gespeichert', [200, 200, true, 24, null], array_merge([$st], $get()));
    $eq('Meta _tix_ticket_transfer_hours', '24', (string) get_post_meta($ev, '_tix_ticket_transfer_hours', true));
    [$st, $d] = $rest('POST', "/events/$ev", ['ticket_transfer_hours' => 200]);
    $eq('ungültig 200 → 400 ticket_transfer_hours', [400, 'ticket_transfer_hours'], [$st, $d['data']['field'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev", ['ticket_transfer_hours' => 'abc']);
    $eq('ungültig Text → 400', 400, $st);
    [$st] = $rest('POST', "/events/$ev", ['ticket_transfer' => false]);
    $eq('nur Schalter aus: Frist bleibt', [200, false, 24], [$st, $get()[1], $get()[2]]);
    [$st] = $rest('POST', "/events/$ev", ['ticket_transfer' => true, 'ticket_transfer_hours' => 0]);
    $eq('„Jederzeit“: Frist 0, Meta gelöscht', [200, 0, ''], [$st, $get()[2], (string) get_post_meta($ev, '_tix_ticket_transfer_hours', true)]);
    [$st, $d] = $rest('GET', '/events/form');
    $eq('Formular für neues Event: Frist 0, Regel null', [0, null], [($d['event'] ?? $d)['ticket_transfer_hours'] ?? 'fehlt', ($d['event'] ?? $d)['ticket_transfer_rule'] ?? null]);

    // ═══ Prüfung am Ticket ═══
    $tid = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'T']);
    update_post_meta($tid, '_tix_ticket_event_id', $ev); update_post_meta($tid, '_tix_ticket_status', 'valid');
    $ok = function ($owner) use ($tid) { return TIX_Ticket_Fields::transfer_allowed($tid, $owner) === true; };
    update_post_meta($ev, '_tix_ticket_transfer_hours', 24);
    $eq('Event in 48 h, Frist 24: Inhaberwechsel erlaubt', true, $ok(true));
    update_post_meta($ev, '_tix_ticket_transfer_hours', 72);
    $r = TIX_Ticket_Fields::transfer_allowed($tid, true);
    $eq('Frist 72: Inhaberwechsel gesperrt mit Meldung', ['tix_transfer', true], [is_wp_error($r) ? $r->get_error_code() : '', is_wp_error($r) && strpos($r->get_error_message(), '72 Stunden') !== false]);
    $eq('Namenskorrektur ohne Ticket-Angaben: unverändert erlaubt (Event-Frist zählt nicht)', true, $ok(false));
    update_post_meta($ev, TIX_Ticket_Fields::META, ['transfer' => 'until_hours', 'transfer_hours' => 24] + $on + ['categories' => [], 'ask_email' => false, 'retention_days' => 30]);
    $eq('Bestand until_hours 24 + Event 72: Inhaberwechsel gesperrt, Namenskorrektur erlaubt', [false, true], [$ok(true), $ok(false)]);
    $eq('Studio sieht Bestandsregel (nur lesend)', ['transfer' => 'until_hours', 'transfer_hours' => 24], $get()[3]);
    update_post_meta($ev, '_tix_ticket_transfer_hours', 0);
    update_post_meta($ev, TIX_Ticket_Fields::META, ['transfer' => 'until_hours', 'transfer_hours' => 72] + $on + ['categories' => [], 'ask_email' => false, 'retention_days' => 30]);
    $eq('Bestand until_hours 72 + Event jederzeit: strengere (Bestand) gewinnt bei beidem', [false, false], [$ok(true), $ok(false)]);
    update_post_meta($ev, TIX_Ticket_Fields::META, ['transfer' => 'never'] + $on + ['categories' => [], 'ask_email' => false, 'retention_days' => 30]);
    $eq('Bestand never: beides gesperrt (wie bisher)', [false, false], [$ok(true), $ok(false)]);
    delete_post_meta($ev, TIX_Ticket_Fields::META);

    // ═══ Mit globalem Schalter: can_transfer + öffentlich ═══
    if (tix_get_settings('ticket_transfer_enabled')) {
        update_post_meta($ev, '_tix_ticket_transfer', '1');
        update_post_meta($ev, '_tix_ticket_transfer_hours', 24);
        $eq('can_transfer: Frist 24, Event in 48 h → ja', true, TIX_Ticket_Transfer::can_transfer($tid));
        update_post_meta($ev, '_tix_ticket_transfer_hours', 72);
        $eq('can_transfer: Frist 72 → nein', false, TIX_Ticket_Transfer::can_transfer($tid));
        delete_transient('tix_pub_events_d' . $ev);
        [$st, $d] = $rest('GET', "/public/events/$ev");
        $eq('öffentlich: ticket_transfer + ticket_transfer_hours', [200, true, 72], [$st, $d['event']['ticket_transfer'] ?? null, $d['event']['ticket_transfer_hours'] ?? null]);
        delete_transient('tix_pub_events_d' . $ev);
        update_post_meta($ev, '_tix_ticket_transfer', '0');
        $eq('Schalter aus: öffentlich Frist 0', 0, TIX_Event_Extras::ticket_transfer_hours($ev));
    } else {
        echo "(globaler Schalter aus: can_transfer/öffentlich übersprungen)\n";
        $eq('ohne globalen Schalter: öffentlich Frist 0', 0, TIX_Event_Extras::ticket_transfer_hours($ev));
    }
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    if ($tid) wp_delete_post($tid, true);
    if ($ev) wp_delete_post($ev, true);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
