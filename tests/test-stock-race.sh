#!/usr/bin/env bash
# Bestand mit Sperre (1.38.767): 12 gleichzeitige Käufe à 1 Ticket auf 5 verfügbare → genau 5 klappen, Rest tix_sold_out, Bestand 0.
#   bash tests/test-stock-race.sh <container>
set -u
C="${1:-fp-kk}"
WP="docker exec -u 33 $C wp"
fail=0; n=0
check() { n=$((n+1)); if [ "$2" = "$3" ]; then echo "ok    $1"; else fail=$((fail+1)); echo "FAIL  $1: erwartet [$2], ist [$3]"; fi; }
EV=$($WP eval '$e = wp_insert_post(["post_type" => "event", "post_status" => "publish", "post_title" => "Bestand Wettlauf"]); add_post_meta($e, "_tix_ticket_categories", [["name" => "Eng", "price" => 0, "stock" => 5]]); echo $e;' 2>/dev/null)
check "Event angelegt" "1" "$([ -n "$EV" ] && echo 1 || echo 0)"
OUT=$(mktemp -d)
for i in $(seq 1 12); do
  ( $WP eval '$r = TIX_Stock::reserve([["event_id" => '"$EV"', "cat_index" => 0, "qty" => 1, "name" => "Eng"]]); echo (is_wp_error($r) ? $r->get_error_code() : "ok") . "\n";' > "$OUT/$i" 2>/dev/null ) &
done
wait
OK=$(cat "$OUT"/* | grep -c '^ok$'); SO=$(cat "$OUT"/* | grep -c '^tix_sold_out$'); rm -rf "$OUT"
check "genau 5 Reservierungen" "5" "$OK"
check "7 × tix_sold_out" "7" "$SO"
check "Tabelle 0, Anzeige-Kopie 0" "0|0" "$($WP eval 'wp_cache_delete('"$EV"', "post_meta"); echo (TIX_Stock::levels('"$EV"')[0] ?? "x") . "|" . (get_post_meta('"$EV"', "_tix_ticket_categories", true)[0]["stock"] ?? "x");' 2>/dev/null)"
$WP eval 'wp_delete_post('"$EV"', true);' >/dev/null 2>&1
check "aufgeräumt" "0" "$($WP eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Stock::table() . " WHERE event_id = '"$EV"'");' 2>/dev/null)"
[ $fail -eq 0 ] && echo "ALLES OK ($n Prüfungen)" || echo "FEHLER: $fail ($n Prüfungen)"
