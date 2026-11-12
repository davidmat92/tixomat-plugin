#!/usr/bin/env bash
# Phase 0 (1.38.767): Migrations-Lauf beim ersten Aufruf (auch WP-CLI/REST), Sperre, CLI; Soll-Einstellungen für die Wache.
#   bash tests/test-migrate-soll.sh <container>
set -u
C="${1:-fp-kk}"
WP="docker exec -u 33 $C wp"
fail=0; n=0
check() { n=$((n+1)); if [ "$2" = "$3" ]; then echo "ok    $1"; else fail=$((fail+1)); echo "FAIL  $1: erwartet [$2], ist [$3]"; fi; }
j() { python3 -c "import sys,json; d=json.loads(sys.stdin.read().split('ERGEBNIS ',1)[1]); print($1)"; }
VER=$($WP eval 'echo TIXOMAT_VERSION;' 2>/dev/null)

echo "== Migration"
$WP eval 'update_option("tix_db_version", "1.38.700");' >/dev/null 2>&1
check "erster Aufruf danach (CLI, kein admin_init) migriert" "$VER" "$($WP option get tix_db_version 2>/dev/null)"
S=$($WP tixomat migrate status 2>/dev/null)
check "status: nicht offen, letzter Lauf auto ab 1.38.700, alle Schritte ok" "False|auto|1.38.700|True|ok" "$(printf '%s' "$S" | j '"|".join(map(str,[d["pending"],d["last"]["source"],d["last"]["from"],d["last"]["ok"],",".join(sorted(set(d["last"]["steps"].values())))]))')"
$WP eval 'update_option("tix_db_version", "1.38.700"); add_option("tix_migrate_lock", time(), "", "no");' >/dev/null 2>&1
S=$($WP tixomat migrate status 2>/dev/null)
check "Sperre aktiv: Auto-Lauf wartet" "True|True" "$(printf '%s' "$S" | j 'str(d["pending"])+"|"+str(d["locked"])')"
R=$($WP tixomat migrate run 2>/dev/null); RC=$?
check "run bei Sperre: locked, Exit 1" "True|1" "$(printf '%s' "$R" | j 'd.get("locked")')|$RC"
$WP eval 'update_option("tix_migrate_lock", time() - 700);' >/dev/null 2>&1
check "hängende Sperre (>10 min) wird übernommen" "$VER" "$($WP option get tix_db_version 2>/dev/null)"
R=$($WP tixomat migrate run --force 2>/dev/null)
check "run --force: ok, Quelle cli, Sperre frei" "True|cli|0" "$(printf '%s' "$R" | j 'str(d["ok"])+"|"+d["source"]')|$($WP eval 'echo (int) get_option("tix_migrate_lock");' 2>/dev/null)"
check "Bestandstabelle angelegt" "1" "$($WP eval 'echo get_option("tix_stock_db");' 2>/dev/null)"

echo "== Soll"
I=$($WP tixomat soll ist 2>/dev/null)
check "ist: Schlüssel und setzbar" "True|True|False|$VER" "$(printf '%s' "$I" | j 'str(d["werte"]["timezone_string"]["setzbar"])+"|"+str(d["werte"]["multi"]["setzbar"])+"|"+str(d["werte"]["display_errors"]["setzbar"])+"|"+d["werte"]["plugin_version"]["ist"]')"
MULTI=$(printf '%s' "$I" | j 'str(d["werte"]["multi"]["ist"]).lower()')
TZ=$(printf '%s' "$I" | j 'd["werte"]["timezone_string"]["ist"]')
P=$($WP tixomat soll pruefen --soll="{\"timezone_string\":\"$TZ\",\"multi\":$MULTI,\"migration_offen\":false,\"plugin_version\":\"0.0.1\",\"gibtsnicht\":1}" 2>/dev/null)
check "pruefen: Abweichung plugin_version (nicht setzbar), unbekannt gibtsnicht" "False|plugin_version|False|gibtsnicht" "$(printf '%s' "$P" | j 'str(d["ok"])+"|"+",".join(a["schluessel"] for a in d["abweichungen"])+"|"+str(d["abweichungen"][0]["setzbar"])+"|"+",".join(d["unbekannt"])')"
check "pruefen: bool als \"1\"/true gleich" "True" "$($WP tixomat soll pruefen --soll="{\"multi\":\"$([ "$MULTI" = true ] && echo 1 || echo 0)\"}" 2>/dev/null | j 'd["ok"]')"
E=$($WP tixomat soll setzen display_errors 0 2>&1 >/dev/null | head -1)
check "setzen nicht setzbar: tix_not_settable" "1" "$(printf '%s' "$E" | grep -c tix_not_settable)"
E=$($WP tixomat soll setzen timezone_string Mars/Olymp 2>&1 >/dev/null | head -1)
check "setzen falsche Zeitzone: tix_invalid_field" "1" "$(printf '%s' "$E" | grep -c tix_invalid_field)"
R=$($WP tixomat soll setzen timezone_string Europe/Vienna 2>/dev/null)
check "setzen Zeitzone: alt → neu" "True|$TZ|Europe/Vienna" "$(printf '%s' "$R" | j 'str(d["ok"])+"|"+d["alt"]+"|"+d["neu"]')"
$WP tixomat soll setzen timezone_string "$TZ" >/dev/null 2>&1
check "zurückgesetzt" "$TZ" "$($WP option get timezone_string 2>/dev/null)"
E=$($WP tixomat soll pruefen --soll=kaputt 2>&1 >/dev/null | head -1)
check "pruefen ohne JSON: Fehler" "1" "$(printf '%s' "$E" | grep -c tix_invalid_field)"
[ $fail -eq 0 ] && echo "ALLES OK ($n Prüfungen)" || echo "FEHLER: $fail ($n Prüfungen)"
