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
TIX_Object_Data::write(TIX_Go_Live::OPT_REQ, $lic->id, ["action" => "live", "status" => "pending", "at" => gmdate("c"), "host" => "glc-kunde.test", "by" => ["name" => "Kim Inhaber", "email" => "kim@glc.test"], "checks" => [], "message" => null, "nonce" => "glcnonce", "done_at" => null]);
if (method_exists("TIX_Platform_Customers", "flush")) TIX_Platform_Customers::flush();
echo $lic->id;' 2>/dev/null)
LID="$SETUP"
check "Lizenz angelegt" "1" "$([ -n "$LID" ] && echo 1 || echo 0)"

OFFEN=$($WP tixomat golive offen 2>/dev/null)
check "offen: Zeile beginnt mit ERGEBNIS" "ERGEBNIS" "$(printf '%s' "$OFFEN" | cut -d' ' -f1)"
J="${OFFEN#ERGEBNIS }"
LINE=$(printf '%s' "$J" | python3 -c 'import sys,json; a=json.load(sys.stdin); r=next((r for r in a if r["license_id"]=='"$LID"'),{}); print("|".join([r.get("nonce",""),r.get("action",""),r.get("host",""),r.get("by",{}).get("email",""),str(bool(r.get("at"))),",".join(sorted(r.keys()))]))')
check "offen: Felder license_id, nonce, action, host, by, at" "glcnonce|live|glc-kunde.test|kim@glc.test|True|action,at,by,host,license_id,nonce" "$LINE"

ERR=$($WP tixomat golive ergebnis "$LID" done --nonce=falsch 2>&1 >/dev/null | head -1)
check "falsche nonce: tix_golive_changed, nichts geschrieben" "1|pending" "$(printf '%s' "$ERR" | grep -c 'tix_golive_changed')|$($WP eval 'echo TIX_Go_Live::request_of('"$LID"')["status"];' 2>/dev/null)"
ERR=$($WP tixomat golive ergebnis "$LID" done 2>&1 >/dev/null | head -1)
check "ohne --nonce: Fehler" "1" "$(printf '%s' "$ERR" | grep -c 'nonce')"
ERR=$($WP tixomat golive ergebnis "$LID" fertig --nonce=glcnonce 2>&1 >/dev/null | head -1)
check "falscher Status: tix_invalid_field" "1" "$(printf '%s' "$ERR" | grep -c 'tix_invalid_field')"

RES=$($WP tixomat golive ergebnis "$LID" done --nonce=glcnonce --message="Live seit eben" 2>/dev/null)
check "ergebnis: ERGEBNIS {ok, license_id, status}" "ERGEBNIS {\"ok\":true,\"license_id\":$LID,\"status\":\"done\"}" "$RES"
ST=$($WP eval '$r = TIX_Go_Live::request_of('"$LID"'); echo $r["status"] . "|" . $r["message"] . "|" . (empty($r["done_at"]) ? 0 : 1);' 2>/dev/null)
check "gespeichert: done, Meldung, done_at" "done|Live seit eben|1" "$ST"

ERR=$($WP tixomat golive ergebnis "$LID" failed --nonce=glcnonce 2>&1 >/dev/null | head -1)
check "nicht mehr pending: tix_golive_changed" "1" "$(printf '%s' "$ERR" | grep -c 'tix_golive_changed')"

OFFEN2=$($WP tixomat golive offen 2>/dev/null)
check "danach nicht mehr offen" "0" "$(printf '%s' "${OFFEN2#ERGEBNIS }" | python3 -c 'import sys,json; print(sum(1 for r in json.load(sys.stdin) if r["license_id"]=='"$LID"'))')"

ERR2=$($WP tixomat golive ergebnis 999999 done --nonce=x 2>&1 >/dev/null | head -1)
check "unbekannte Lizenz: tix_not_found" "1" "$(printf '%s' "$ERR2" | grep -c 'tix_not_found')"

LOG=$($WP eval 'global $wpdb; $r = $wpdb->get_var($wpdb->prepare("SELECT data FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND action = %s ORDER BY id DESC LIMIT 1", '"$LID"', "go_live_done")); echo strpos((string) $r, "kunden-dienst") !== false ? 1 : 0;' 2>/dev/null)
check "Protokoll go_live_done durch kunden-dienst" "1" "$LOG"

$WP eval '
global $wpdb; $id = '"$LID"';
$wpdb->delete($wpdb->prefix . "tix_license_sites", ["license_id" => $id]); $wpdb->delete($wpdb->prefix . "tix_license_log", ["license_id" => $id]); $wpdb->delete($wpdb->prefix . "tix_licenses", ["id" => $id]);
TIX_Object_Data::remove(TIX_Go_Live::OPT_REQ, $id);
$b = get_option("tix_license_server_bak"); if ($b === "__none__") delete_option("tix_license_server"); else update_option("tix_license_server", $b, false); delete_option("tix_license_server_bak");' >/dev/null 2>&1
[ $fail -eq 0 ] && echo "ALLES OK ($n Prüfungen)" || echo "FEHLER: $fail ($n Prüfungen)"
