#!/usr/bin/env bash
# „Ein Demo-Zugang mit Fällen“ (1.38.843) in den lokalen Docker-Seiten prüfen – echte Signatur-Kette Plattform → Instanz über HTTP.
# Sichert beide Datenbanken und spielt sie danach zurück. Die Instanz läuft dafür kurz als php -S auf Port 8080 im Container.
#   bash tests/run-demo-remote.sh [kk-container] [plat-container]   (Vorgabe zh-kk zh-plat)
set -u
KK=${1:-zh-kk}; PLAT=${2:-zh-plat}
T=wp-content/plugins/tixomat/tests/test-demo-remote-wp.php
W="wp --allow-root --path=/var/www/html"
fail=0
run() { local c=$1 ph=$2; echo "── $c · Phase $ph"; out=$(docker exec -e TIX_T_PHASE=$ph "$c" $W eval-file "$T" 2>&1); echo "$out" | grep -E "^(FAIL|ALLES|FEHLER)|Warning|Notice|Deprecated|Fatal"; echo "$out" | grep -q "^ALLES OK" || fail=1; echo "$out" | grep -qE "PHP (Warning|Notice|Fatal)|Deprecated" && fail=1; }
for c in "$KK" "$PLAT"; do docker exec "$c" $W db export /tmp/vor-demo-remote.sql --quiet; done

# Instanz: Schalter an, Schlüssel erzeugen (Datei 0600), auf der Plattform per stdin setzen
docker exec "$KK" $W option update tix_demo_instance 1 --quiet
docker exec "$KK" $W option update tix_demo_remote_enabled 1 --quiet
docker exec "$KK" rm -f /tmp/demo-remote.key
j=$(docker exec "$KK" $W tixomat demo-shop remote-key generate --file=/tmp/demo-remote.key 2>&1); echo "generate: $j"; echo "$j" | grep -q '"ok":true' || fail=1
perm=$(docker exec "$KK" stat -c %a /tmp/demo-remote.key); echo "Dateirechte: $perm"; [ "$perm" = "600" ] || fail=1
key=$(docker exec "$KK" cat /tmp/demo-remote.key | tr -d '\n')
echo "$j" | grep -q "$key" && { echo "FAIL Schlüssel in der Ausgabe"; fail=1; }
j=$(docker exec "$KK" $W tixomat demo-shop remote-key generate --file=/tmp/demo-remote.key 2>&1); rc=$?; echo "generate auf vorhandene Datei: rc=$rc"; [ $rc -ne 0 ] || fail=1
j=$(docker exec "$PLAT" $W tixomat demo-shop remote-key set "$key" 2>&1); rc=$?; echo "set mit Argument: rc=$rc $(echo "$j" | head -c 120)"; [ $rc -ne 0 ] || fail=1
docker exec "$PLAT" $W option get tix_demo_shop_key >/dev/null 2>&1 && { echo "FAIL Schlüssel per Argument gespeichert"; fail=1; }
j=$(echo "kurz" | docker exec -i "$PLAT" $W tixomat demo-shop remote-key set 2>&1); rc=$?; echo "set zu kurz: rc=$rc"; [ $rc -ne 0 ] || fail=1
j=$(docker exec "$KK" cat /tmp/demo-remote.key | docker exec -i "$PLAT" $W tixomat demo-shop remote-key set 2>&1); echo "set (stdin): $j"; echo "$j" | grep -q '"ok":true' || fail=1
echo "$j" | grep -q "$key" && { echo "FAIL Schlüssel in der Ausgabe"; fail=1; }
docker exec "$KK" rm -f /tmp/demo-remote.key
docker exec "$PLAT" $W option update tix_demo_shop_url 'http://zh-kk:8080/?rest_route=/tixomat/v1' --quiet
fk=$(docker exec "$KK" $W tixomat demo-shop remote-key status 2>&1); fp=$(docker exec "$PLAT" $W tixomat demo-shop remote-key status 2>&1)
echo "status Instanz:   $fk"; echo "status Plattform: $fp"
a=$(echo "$fk" | sed -n 's/.*"fingerprint":"\([0-9a-f]*\)".*/\1/p'); b=$(echo "$fp" | sed -n 's/.*"fingerprint":"\([0-9a-f]*\)".*/\1/p')
[ -n "$a" ] && [ "$a" = "$b" ] && echo "ok    Fingerabdruck gleich ($a)" || { echo "FAIL Fingerabdruck"; fail=1; }
echo "$fk" | grep -q '"instance_ready":true' || fail=1; echo "$fp" | grep -q '"platform_ready":true' || fail=1

# Instanz als HTTP-Server
docker exec "$KK" sh -c 'pkill -f "php -S 0.0.0.0:8080" 2>/dev/null'; sleep 1
docker exec -d "$KK" sh -c 'cd /var/www/html && exec php -S 0.0.0.0:8080 -t /var/www/html > /tmp/phps.log 2>&1'
sleep 2
docker exec "$PLAT" curl -s -m5 -o /dev/null -w 'Instanz per HTTP: %{http_code}\n' 'http://zh-kk:8080/?rest_route=/tixomat/v1/info'

run "$KK" inst
run "$PLAT" plat
run "$PLAT" none
echo "── Server-Log der Instanz (PHP-Meldungen)"
docker exec "$KK" sh -c 'grep -E "PHP (Warning|Notice|Fatal|Deprecated)" /tmp/phps.log | head -5'
docker exec "$KK" sh -c 'grep -qE "PHP (Warning|Notice|Fatal|Deprecated)" /tmp/phps.log' && fail=1
docker exec "$KK" sh -c 'grep -c "" /tmp/phps.log' | sed 's/^/Zeilen im Log: /'
docker exec "$KK" sh -c 'pkill -f "php -S 0.0.0.0:8080" 2>/dev/null; rm -f /tmp/phps.log'

for c in "$KK" "$PLAT"; do docker exec "$c" $W db import /tmp/vor-demo-remote.sql --quiet && docker exec "$c" rm -f /tmp/vor-demo-remote.sql; done
docker exec "$PLAT" $W option get tix_demo_shop_key >/dev/null 2>&1 && { echo "WARNUNG: Schlüssel nach Rückspielen noch gesetzt"; fail=1; }
[ $fail -eq 0 ] && echo "DEMO-REMOTE: ALLES OK" || { echo "DEMO-REMOTE: FEHLER"; exit 1; }
