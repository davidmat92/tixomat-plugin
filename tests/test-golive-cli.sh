#!/usr/bin/env bash
# Abholer-CLI für „Live schalten durch den Kunden“ (1.38.765) – auf der Plattform-Testseite (Container mit WP-CLI):
#   bash tests/test-golive-cli.sh <container>
# Legt eine Lizenz mit gebundener Seite und eine offene Anfrage an, prüft `wp tixomat golive offen|ergebnis`, räumt auf.
set -u
C="${1:-fp-plat}"
WP="docker exec -u 33 $C wp"
fail=0; n=0
check() { n=$((n+1)); if [ "$2" = "$3" ]; then echo "ok    $1"; else fail=$((fail+1)); echo "FAIL  $1: erwartet [$2], ist [$3]"; fi; }

SETUP=$($WP eval '
update_option("tix_license_server_bak", get_option("tix_license_server", "__none__"), false);
update_option("tix_license_server", "1", false); TIX_License_Server::install();
$lic = TIX_License_Server::create(["customer" => "GLC Kunde", "email" => "glc@example.test", "valid_until" => gmdate("Y-m-d", strtotime("+60 days"))]);
global $wpdb; $wpdb->insert($wpdb->prefix . "tix_license_sites", ["license_id" => $lic->id, "host" => "glc-kunde.test", "dev" => 0, "first_seen" => current_time("mysql"), "last_seen" => current_time("mysql")]);
update_option(TIX_Go_Live::OPT_REQ . $lic->id, ["action" => "live", "status" => "pending", "at" => gmdate("c"), "host" => "glc-kunde.test", "by" => ["name" => "Kim Inhaber", "email" => "kim@glc.test"], "checks" => [], "message" => null, "nonce" => "glcnonce", "done_at" => null], false);
if (method_exists("TIX_Platform_Customers", "flush")) TIX_Platform_Customers::flush();
echo $lic->id;' 2>/dev/null)
LID="$SETUP"
check "Lizenz angelegt" "1" "$([ -n "$LID" ] && echo 1 || echo 0)"

OFFEN=$($WP tixomat golive offen 2>/dev/null)
KEY=$(printf '%s' "$OFFEN" | php -r '$a=json_decode(stream_get_contents(STDIN),true); foreach($a as $r) if($r["license_id"]=='"$LID"') echo $r["key"];' 2>/dev/null || true)
[ -z "$KEY" ] && KEY=$(printf '%s' "$OFFEN" | python3 -c 'import sys,json; a=json.load(sys.stdin); print(next((r["key"] for r in a if r["license_id"]=='"$LID"'),""))')
LINE=$(printf '%s' "$OFFEN" | python3 -c 'import sys,json; a=json.load(sys.stdin); r=next((r for r in a if r["license_id"]=='"$LID"'),{}); print("|".join([r.get("host",""),r.get("action",""),r.get("by",{}).get("email",""),str(bool(r.get("at")))]))')
check "offen: Anfrage mit host, action, by, at (JSON)" "glc-kunde.test|live|kim@glc.test|True" "$LINE"
check "offen: key vorhanden" "1" "$([ -n "$KEY" ] && echo 1 || echo 0)"

ERR=$($WP tixomat golive ergebnis "$KEY" fertig 2>&1 >/dev/null | head -1)
check "ergebnis mit falschem Status: Fehler" "1" "$(printf '%s' "$ERR" | grep -c 'tix_invalid_field')"

RES=$($WP tixomat golive ergebnis "$KEY" done --message="Live seit eben" 2>/dev/null)
ST=$(printf '%s' "$RES" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d["request"]["status"]+"|"+(d["request"]["message"] or ""))')
check "ergebnis done mit Meldung" "done|Live seit eben" "$ST"

OFFEN2=$($WP tixomat golive offen 2>/dev/null)
check "danach nicht mehr offen" "0" "$(printf '%s' "$OFFEN2" | python3 -c 'import sys,json; print(sum(1 for r in json.load(sys.stdin) if r["license_id"]=='"$LID"'))')"

ERR2=$($WP tixomat golive ergebnis l-999999 done 2>&1 >/dev/null | head -1)
check "unbekannter Kunde: Fehler" "1" "$(printf '%s' "$ERR2" | grep -c 'tix_not_found')"

LOG=$($WP eval 'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND action = %s", '"$LID"', "go_live_done"));' 2>/dev/null)
check "Protokoll go_live_done" "1" "$LOG"

$WP eval '
global $wpdb; $id = '"$LID"';
$wpdb->delete($wpdb->prefix . "tix_license_sites", ["license_id" => $id]); $wpdb->delete($wpdb->prefix . "tix_license_log", ["license_id" => $id]); $wpdb->delete($wpdb->prefix . "tix_licenses", ["id" => $id]);
delete_option(TIX_Go_Live::OPT_REQ . $id);
$b = get_option("tix_license_server_bak"); if ($b === "__none__") delete_option("tix_license_server"); else update_option("tix_license_server", $b, false); delete_option("tix_license_server_bak");' >/dev/null 2>&1
[ $fail -eq 0 ] && echo "ALLES OK ($n Prüfungen)" || echo "FEHLER: $fail ($n Prüfungen)"
