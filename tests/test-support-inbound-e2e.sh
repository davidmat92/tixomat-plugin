#!/bin/sh
# Ende-zu-Ende: Postfix-Pipe → Hub (WordPress A) → signierte Weiterleitung per HTTP → Seite (WordPress B) → Ticket.
#   sh tests/test-support-inbound-e2e.sh <hub-container> <seiten-container> [pipe-skript]
# Beide Container im selben Docker-Netz, Seite unter http://<seiten-container> erreichbar.
HUB=${1:-sm-plat}; SITE=${2:-sm-kk}; PIPE=${3:-}
ok=0; fail=0
t() { if [ "$2" = "1" ]; then ok=$((ok+1)); echo "ok    $1"; else fail=$((fail+1)); echo "FAIL  $1 $3"; fi; }
wp() { docker exec -w /var/www/html "$1" php -r "require '/var/www/html/wp-load.php'; $2"; }

KEY=hub$$; SEC=sec$$
wp "$HUB" "update_option('tix_sp_inbound_hub_key', '$KEY', false); update_option('tix_sp_inbound_sites', ['kk' => ['url' => 'http://$SITE', 'secret' => '$SEC']], false); delete_option('tix_sp_inbound_log');"
wp "$SITE" "update_option('tix_sp_inbound', ['slug' => 'kk', 'secret' => '$SEC'], false); delete_option('tix_sp_inbound_log');"
OUT=$(wp "$SITE" "\$id = TIX_Support::create_ticket_from_email(['email' => 'e2e$$@example.test', 'name' => 'E2E', 'subject' => 'E2E-Frage', 'date_ts' => time()]); echo \$id, ' ', TIX_Support_Inbound::reply_address(\$id);")
TID=${OUT%% *}; RA=${OUT#* }
t "Anfrage + Antwort-Adresse" "$( [ -n "$TID" ] && echo "$RA" | grep -q "^kk-r$TID-" && echo 1)" "$OUT"

mail() { printf 'From: E2E <e2e%s@example.test>\r\nTo: %s\r\nSubject: Re: E2E-Frage\r\nMessage-ID: <%s@e2e.test>\r\nDate: %s\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n%s\r\n\r\n##- Bitte oberhalb dieser Zeile antworten -##\r\nalt\r\n' "$$" "$RA" "$1" "$(date -R)" "$2"; }
post() { docker exec -i "$HUB" curl -s -w '\n%{http_code}' -X POST -H "Content-Type: message/rfc822" -H "X-Tix-Rcpt: $1" -H "X-Tix-Inbound-Key: $2" --data-binary @- http://localhost/wp-json/tixomat/v1/support/inbound; }

R=$(mail m1$$ "Antwort über den Hub" | post "$RA" "$KEY")
t "Hub → Seite per HTTP: übernommen" "$(echo "$R" | tail -1 | grep -q 200 && echo "$R" | grep -q '"result":"imported"' && echo 1)" "$R"
C=$(wp "$SITE" "\$m = get_post_meta($TID, '_tix_sp_messages', true); \$l = end(\$m); echo trim(\$l['content']);")
t "Nachricht im Ticket (Zitat gekürzt)" "$( [ "$C" = "Antwort über den Hub" ] && echo 1)" "$C"

R=$(mail m2$$ "x" | post "$RA" "falsch")
t "falscher Hub-Schlüssel 403" "$(echo "$R" | tail -1 | grep -q 403 && echo 1)" "$R"

wp "$HUB" "update_option('tix_sp_inbound_sites', ['kk' => ['url' => 'http://$SITE', 'secret' => 'anders']], false);"
R=$(mail m3$$ "x" | post "$RA" "$KEY")
t "falscher Seiten-Schlüssel: Seite lehnt ab (kein Ticket)" "$(echo "$R" | grep -q '403' && echo "$R" | grep -qv '"imported"' && echo 1)" "$R"
wp "$HUB" "update_option('tix_sp_inbound_sites', ['kk' => ['url' => 'http://$SITE', 'secret' => '$SEC']], false);"

wp "$SITE" "\$s = get_option('tix_settings'); \$s['support_enabled'] = 0; update_option('tix_settings', \$s);"
R=$(mail m4$$ "aus" | post "$RA" "$KEY")
t "Support aus auf der Seite: nicht übernommen" "$(echo "$R" | grep -q '"result":"ignored"' && echo 1)" "$R"
wp "$SITE" "\$s = get_option('tix_settings'); \$s['support_enabled'] = 1; update_option('tix_settings', \$s);"

if [ -n "$PIPE" ]; then
  docker cp "$PIPE" "$HUB":/tmp/tix-inbound-pipe.sh >/dev/null
  docker exec "$HUB" sh -c "mkdir -p /tmp/tixk && printf '%s' '$KEY' > /tmp/tixk/key"
  mail m5$$ "Über die Postfix-Pipe" | docker exec -i -e TIX_INBOUND_URL=http://localhost/wp-json/tixomat/v1/support/inbound -e TIX_INBOUND_KEY_FILE=/tmp/tixk/key "$HUB" sh /tmp/tix-inbound-pipe.sh "$RA"
  t "Pipe-Skript: Exit 0" "$( [ $? -eq 0 ] && echo 1)"
  C=$(wp "$SITE" "\$m = get_post_meta($TID, '_tix_sp_messages', true); \$l = end(\$m); echo trim(\$l['content']);")
  t "Pipe-Skript: Nachricht im Ticket" "$( [ "$C" = "Über die Postfix-Pipe" ] && echo 1)" "$C"
  mail m6$$ "x" | docker exec -i -e TIX_INBOUND_URL=http://127.0.0.1:9/ -e TIX_INBOUND_KEY_FILE=/tmp/tixk/key "$HUB" sh /tmp/tix-inbound-pipe.sh "$RA" 2>/dev/null
  t "Pipe-Skript: Hub nicht erreichbar → 75 (Postfix wiederholt)" "$( [ $? -eq 75 ] && echo 1)"
fi

wp "$SITE" "wp_delete_post($TID, true); delete_option('tix_sp_inbound'); delete_option('tix_sp_inbound_log');"
wp "$HUB" "delete_option('tix_sp_inbound_hub_key'); delete_option('tix_sp_inbound_sites'); delete_option('tix_sp_inbound_log');"
echo; echo "$ok/$((ok+fail)) ok"
