#!/usr/bin/env bash
# „Ein Demo-Zugang mit Fällen“ (1.38.843, drei Fälle seit 1.38.844) in den lokalen Docker-Seiten prüfen – echte Signatur-Kette
# Plattform → Instanzen über HTTP. Sichert die Datenbanken und spielt sie danach zurück. Die Instanzen laufen dafür kurz als php -S
# im Container: shop = zh-kk (Port 8080), club = zweite WordPress-Installation im selben Container und derselben DB mit
# Tabellen-Präfix club_ (Port 8081, WORDPRESS_TABLE_PREFIX=club_; wird beim ersten Lauf einmal angelegt und bleibt als Testseite).
#   bash tests/run-demo-remote.sh [kk-container] [plat-container]   (Vorgabe zh-kk zh-plat)
set -u
KK=${1:-zh-kk}; PLAT=${2:-zh-plat}
T=wp-content/plugins/tixomat/tests/test-demo-remote-wp.php
W="wp --allow-root --path=/var/www/html"
CL="-e WORDPRESS_TABLE_PREFIX=club_"   # zweite Instanz (Demo-Club)
fail=0
run() { local c=$1 ph=$2; echo "── $c · Phase $ph"; out=$(docker exec -e TIX_T_PHASE=$ph "$c" $W eval-file "$T" 2>&1); [ -n "${DEBUG_OUT:-}" ] && echo "$out" > "$DEBUG_OUT.$c.$ph"; echo "$out" | grep -E "^(FAIL|ALLES|FEHLER)|Warning|Notice|Deprecated|Fatal"; echo "$out" | grep -q "^ALLES OK" || fail=1; echo "$out" | grep -qE "PHP (Warning|Notice|Fatal)|Deprecated" && fail=1; }
# Club-Instanz einmalig anlegen (vor der Sicherung, damit sie danach als leere Testseite stehen bleibt)
if ! docker exec $CL "$KK" $W core is-installed 2>/dev/null; then
  docker exec $CL "$KK" $W core install --url=http://tqc-club --title='Demo Club (Test)' --admin_user=clubadmin --admin_password="$(openssl rand -hex 16)" --admin_email=admin@tqc-club.test --skip-email --quiet
fi
docker exec $CL "$KK" $W plugin is-active tixomat 2>/dev/null || docker exec $CL "$KK" $W plugin activate tixomat --quiet
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
fpr() { echo "$1" | sed -n 's/^{[^{]*"fingerprint":"\([0-9a-f]*\)".*/\1/p'; }
a=$(fpr "$fk"); b=$(fpr "$fp")
[ -n "$a" ] && [ "$a" = "$b" ] && echo "ok    Fingerabdruck gleich ($a)" || { echo "FAIL Fingerabdruck"; fail=1; }
echo "$fk" | grep -q '"instance_ready":true' || fail=1; echo "$fp" | grep -q '"platform_ready":true' || fail=1

# Club-Instanz (1.38.844): Schalter, Vorlage club, eigener Schlüssel → auf der Plattform mit --target=club
docker exec $CL "$KK" $W option update tix_demo_instance 1 --quiet
docker exec $CL "$KK" $W option update tix_demo_remote_enabled 1 --quiet
docker exec $CL "$KK" rm -f /tmp/demo-club.key
j=$(docker exec $CL "$KK" $W tixomat demo-shop remote-key generate --file=/tmp/demo-club.key 2>&1); echo "Club generate: $j"; echo "$j" | grep -q '"option":"tix_demo_shop_key"' || fail=1
j=$(docker exec $CL "$KK" $W tixomat demo-shop remote-key status --target=club 2>&1); echo "Club status mit --target=club (Instanz): $(echo "$j" | head -c 160)"; echo "$j" | grep -q '"target_ignored":true' || fail=1
j=$(docker exec "$KK" cat /tmp/demo-club.key | docker exec -i "$PLAT" $W tixomat demo-shop remote-key set --target=quatsch 2>&1); rc=$?; echo "set --target=quatsch: rc=$rc"; [ $rc -ne 0 ] || fail=1
j=$(docker exec "$KK" cat /tmp/demo-club.key | docker exec -i "$PLAT" $W tixomat demo-shop remote-key set --target=club 2>&1); echo "set --target=club: $j"; echo "$j" | grep -q '"option":"tix_demo_club_key"' || fail=1
docker exec "$KK" rm -f /tmp/demo-club.key
docker exec "$PLAT" $W option update tix_demo_club_url 'http://zh-kk:8081/?rest_route=/tixomat/v1' --quiet
docker exec $CL "$KK" $W tixomat demo-shop reset --vorlage=club >/dev/null 2>&1 || { echo "FAIL Club-Seed"; fail=1; }
ck=$(docker exec $CL "$KK" $W tixomat demo-shop remote-key status 2>&1); cp=$(docker exec "$PLAT" $W tixomat demo-shop remote-key status --target=club 2>&1)
echo "status Club:            $(echo "$ck" | head -c 200)"; echo "status Plattform club:  $(echo "$cp" | head -c 200)"
a=$(fpr "$ck"); b=$(fpr "$cp")
[ -n "$a" ] && [ "$a" = "$b" ] && echo "ok    Fingerabdruck Club gleich ($a)" || { echo "FAIL Fingerabdruck Club"; fail=1; }
[ "$a" != "$(fpr "$fk")" ] && echo "ok    Club und Shop haben verschiedene Schlüssel" || { echo "FAIL gleicher Schlüssel"; fail=1; }
echo "$cp" | grep -q '"platform_ready":true' || fail=1
docker exec "$PLAT" $W option get tix_demo_shop_key 2>/dev/null | grep -q . || fail=1

# Instanz als HTTP-Server
docker exec "$KK" sh -c 'pkill -f "php -S 0.0.0.0:8080" 2>/dev/null'; sleep 1
docker exec "$KK" sh -c 'pkill -f "php -S 0.0.0.0:8081" 2>/dev/null'; sleep 1
docker exec -d "$KK" sh -c 'cd /var/www/html && exec php -S 0.0.0.0:8080 -t /var/www/html > /tmp/phps.log 2>&1'
docker exec -d $CL "$KK" sh -c 'cd /var/www/html && exec php -S 0.0.0.0:8081 -t /var/www/html > /tmp/phps-club.log 2>&1'
sleep 2
docker exec "$PLAT" curl -s -m5 -o /dev/null -w 'Instanz per HTTP: %{http_code}\n' 'http://zh-kk:8080/?rest_route=/tixomat/v1/info'
docker exec "$PLAT" curl -s -m5 'http://zh-kk:8081/?rest_route=/tixomat/v1/info' | grep -o '"vorlage":"club"' | sed 's/^/Club-Instanz per HTTP: /'

run "$KK" inst
echo "── $KK (Präfix club_) · Phase inst"; out=$(docker exec $CL -e TIX_T_PHASE=inst "$KK" $W eval-file "$T" 2>&1); echo "$out" | grep -E "^(FAIL|ALLES|FEHLER)|Warning|Notice|Deprecated|Fatal"; echo "$out" | grep -q "^ALLES OK" || fail=1
run "$PLAT" plat
run "$PLAT" none
echo "── Server-Log der Instanzen (PHP-Meldungen)"
for L in /tmp/phps.log /tmp/phps-club.log; do
  docker exec "$KK" sh -c "grep -E 'PHP (Warning|Notice|Fatal|Deprecated)' $L | head -5"
  docker exec "$KK" sh -c "grep -qE 'PHP (Warning|Notice|Fatal|Deprecated)' $L" && fail=1
  docker exec "$KK" sh -c "grep -c '' $L" | sed "s|^|Zeilen in $L: |"
done
docker exec "$KK" sh -c 'pkill -f "php -S 0.0.0.0:808" 2>/dev/null; rm -f /tmp/phps.log /tmp/phps-club.log'

for c in "$KK" "$PLAT"; do docker exec "$c" $W db import /tmp/vor-demo-remote.sql --quiet && docker exec "$c" rm -f /tmp/vor-demo-remote.sql; done
docker exec "$PLAT" $W option get tix_demo_shop_key >/dev/null 2>&1 && { echo "WARNUNG: Schlüssel nach Rückspielen noch gesetzt"; fail=1; }
docker exec "$PLAT" $W option get tix_demo_club_key >/dev/null 2>&1 && { echo "WARNUNG: Club-Schlüssel nach Rückspielen noch gesetzt"; fail=1; }
docker exec $CL "$KK" $W option get tix_demo_shop_key >/dev/null 2>&1 && { echo "WARNUNG: Schlüssel der Club-Instanz nach Rückspielen noch gesetzt"; fail=1; }
[ $fail -eq 0 ] && echo "DEMO-REMOTE: ALLES OK" || { echo "DEMO-REMOTE: FEHLER"; exit 1; }
