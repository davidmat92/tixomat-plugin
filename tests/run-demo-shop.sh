#!/usr/bin/env bash
# Demo-Shop „Eigene Website“ (1.38.841) in den lokalen Docker-Seiten prüfen: Einzel-Seite (Schalter aus/an) und Plattform.
# Sichert die Datenbank vorher und spielt sie danach zurück (der Reset löscht alle Events/Bestellungen der Seite).
#   bash tests/run-demo-shop.sh [kk-container] [plat-container]   (Vorgabe zh-kk zh-plat)
set -u
KK=${1:-zh-kk}; PLAT=${2:-zh-plat}
T=wp-content/plugins/tixomat/tests/test-demo-shop-wp.php
W="wp --allow-root --path=/var/www/html"
fail=0
run() { local c=$1 ph=$2; echo "── $c · Phase $ph"; out=$(docker exec -e TIX_T_PHASE=$ph "$c" $W eval-file "$T" 2>&1); echo "$out" | grep -E "^(FAIL|ALLES|FEHLER)|Warning|Notice|Deprecated|Fatal"; echo "$out" | grep -q "^ALLES OK" || fail=1; echo "$out" | grep -qE "PHP (Warning|Notice|Fatal)" && fail=1; }
for c in "$KK" "$PLAT"; do docker exec "$c" $W db export /tmp/vor-demo-shop.sql --quiet; done
run "$KK" off
# CLI ohne Schalter: Exit 1, JSON ok:false
j=$(docker exec "$KK" $W tixomat demo-shop reset 2>/dev/null); rc=$?
echo "CLI aus: rc=$rc $j"; [ $rc -ne 0 ] && echo "$j" | grep -q '"ok":false' || fail=1
run "$KK" on
# CLI mit Schalter: eine JSON-Zeile, Exit 0
docker exec "$KK" $W option update tix_demo_instance 1 --quiet
j=$(docker exec "$KK" $W tixomat demo-shop reset --vorlage=club 2>&1); rc=$?
echo "CLI an:  rc=$rc $(echo "$j" | head -c 300)"; [ $rc -eq 0 ] && [ "$(echo "$j" | wc -l | tr -d ' ')" = "1" ] && echo "$j" | grep -q '"ok":true' || fail=1
j=$(docker exec "$KK" $W tixomat demo-shop seed 2>&1); echo "CLI seed: $(echo "$j" | head -c 200)"; echo "$j" | grep -q '"skipped":true' || fail=1
j=$(docker exec "$KK" $W tixomat demo-shop status 2>&1); echo "CLI status: $j"; echo "$j" | grep -q '"demo_instance":true' || fail=1
j=$(docker exec "$KK" $W tixomat demo-shop reset --vorlage=quatsch 2>&1); rc=$?; echo "CLI falsche Vorlage: rc=$rc"; [ $rc -ne 0 ] || fail=1
run "$PLAT" plat
echo "── $PLAT · Regression Demo-Studio"
# Der Test verlangt einen .test-Host; Host nur für diesen Lauf umbiegen. 14 Fehler (Beispiel-Club-Kasse, /public/demo) gibt es
# auf zh-plat schon ohne dieses Paket (Stand 1.38.840) – geprüft wird, dass keine neuen dazukommen.
docker exec -i "$PLAT" sh -c 'cat > /tmp/da-wrap.php' <<'PHP'
<?php
add_filter('home_url', function ($u) { return preg_replace('#^(https?://)([a-z0-9-]+)(?=/|$)#', '$1$2.test', $u); });
include WP_PLUGIN_DIR . '/tixomat/tests/test-demo-access-wp.php';
PHP
out=$(docker exec "$PLAT" $W eval-file /tmp/da-wrap.php 2>&1); docker exec "$PLAT" rm -f /tmp/da-wrap.php
echo "$out" | grep -q "Nur auf lokalen" && { echo "Demo-Studio-Test lief nicht"; fail=1; }
nf=$(echo "$out" | grep -c "^FAIL"); echo "$out" | tail -1; echo "Fehler im Demo-Studio-Test: $nf (Grundlinie 1.38.840 auf zh-plat: ${BASE_DA_FAILS:-14})"
[ "$nf" -le "${BASE_DA_FAILS:-14}" ] || fail=1; echo "$out" | grep -qE "PHP Fatal" && fail=1
for c in "$KK" "$PLAT"; do docker exec "$c" $W db import /tmp/vor-demo-shop.sql --quiet && docker exec "$c" rm -f /tmp/vor-demo-shop.sql; done
docker exec "$KK" $W option get tix_demo_instance 2>/dev/null | grep -q 1 && echo "WARNUNG: Schalter nach Rückspielen noch an" && fail=1
[ $fail -eq 0 ] && echo "DEMO-SHOP: ALLES OK" || { echo "DEMO-SHOP: FEHLER"; exit 1; }
