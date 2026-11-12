#!/usr/bin/env bash
# Werkzeugseiten + Danke-Seite ohne Theme (F7 Stufe 1, 1.38.774) – echte HTTP-Antworten über den PHP-Webserver im Container,
# mit einem leeren Ersatz-Theme ohne header.php/footer.php (so wie nach „Breakdance aus“).
#   bash tests/test-tool-frame.sh <container>     (Einzel-Seite, home = http://<host>)
set -u
C="${1:-fp-kk}"
WP="docker exec -u 33 $C wp"
fail=0; n=0
check() { n=$((n+1)); if [ "$2" = "$3" ]; then echo "ok    $1"; else fail=$((fail+1)); echo "FAIL  $1: erwartet [$2], ist [$3]"; fi; }
HOST=$($WP eval 'echo wp_parse_url(home_url(), PHP_URL_HOST);' 2>/dev/null)
OLD_THEME=$($WP eval 'echo get_stylesheet();' 2>/dev/null)
# Ersatz-Theme wie „evendis-leer“: nur index.php/style.css, kein header.php/footer.php
docker exec -u 0 $C sh -c 'mkdir -p /var/www/html/wp-content/themes/tix-leer-test && printf "/*\nTheme Name: tix-leer-test\n*/\n" > /var/www/html/wp-content/themes/tix-leer-test/style.css && printf "<?php echo \"THEME-INDEX\"; while (have_posts()) { the_post(); the_content(); }\n" > /var/www/html/wp-content/themes/tix-leer-test/index.php && chown -R 33:33 /var/www/html/wp-content/themes/tix-leer-test'
$WP theme activate tix-leer-test >/dev/null 2>&1
docker exec -u 0 -d $C sh -c 'cd /var/www/html && php -S 0.0.0.0:80 >/tmp/tixfs.log 2>&1'
sleep 1
get() { docker exec $C sh -c "curl -s -H 'Host: $HOST' 'http://127.0.0.1$1'" 2>/dev/null || docker exec $C php -r "echo file_get_contents('http://127.0.0.1$1', false, stream_context_create(['http'=>['header'=>'Host: $HOST','ignore_errors'=>true]]));"; }
IDS=$($WP eval '
$a = wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_title"=>"Einlass Test","post_name"=>"tfx-einlass","post_content"=>"<!-- breakdance -->"]);
update_post_meta($a, "_breakdance_data", wp_slash(wp_json_encode(["tree"=>["root"=>["children"=>[["data"=>["type"=>"Shortcode","properties"=>["content"=>["shortcode"=>"[tix_checkin]"]]]]]]]])));
$b = wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_title"=>"Konto Test","post_name"=>"tfx-konto","post_content"=>"<p>Willkommen im Konto-Bereich.</p>\n[tix_account]"]);
$c = wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_title"=>"Normal Test","post_name"=>"tfx-normal","post_content"=>"<p>Nur Text.</p>"]);
global $wpdb; $wpdb->insert($wpdb->prefix."tix_orders", ["order_number"=>"TFX-".wp_rand(),"status"=>"completed","total"=>0,"subtotal"=>0,"tax"=>0,"discount"=>0,"payment_method"=>"free","payment_method_title"=>"Kostenlos","billing_first_name"=>"T","billing_last_name"=>"F","billing_email"=>"tfx@example.test","customer_id"=>0,"wc_order_id"=>0,"order_key"=>"tfxkey","date_created"=>current_time("mysql")]);
echo "$a $b $c ".$wpdb->insert_id;' 2>/dev/null)
read A B CC O <<< "$IDS"
check "Testseiten angelegt" "1" "$([ -n "${O:-}" ] && echo 1 || echo 0)"

R=$(get /tfx-einlass/)
check "Einlass (Shortcode nur im Breakdance-Layout): Rahmen + Einlass, kein Theme" "1|1|0" "$(printf '%s' "$R" | grep -c 'class="tix-frame' | tr -d ' ' | sed 's/[1-9][0-9]*/1/')|$(printf '%s' "$R" | grep -ci 'tix-checkin\|tix_checkin\|checkin' | sed 's/[1-9][0-9]*/1/')|$(printf '%s' "$R" | grep -c 'THEME-INDEX')"
check "Einlass: noindex" "1" "$(printf '%s' "$R" | grep -c 'noindex,nofollow' | sed 's/[1-9][0-9]*/1/')"
R=$(get /tfx-konto/)
check "Konto (Shortcode im Inhalt): Rahmen, Text drumherum bleibt" "1|1|0" "$(printf '%s' "$R" | grep -c 'class="tix-frame' | sed 's/[1-9][0-9]*/1/')|$(printf '%s' "$R" | grep -c 'Willkommen im Konto-Bereich' | sed 's/[1-9][0-9]*/1/')|$(printf '%s' "$R" | grep -c 'THEME-INDEX')"
R=$(get /tfx-normal/)
check "normale Seite: Theme wie bisher" "0|1" "$(printf '%s' "$R" | grep -c 'class="tix-frame')|$(printf '%s' "$R" | grep -c 'THEME-INDEX' | sed 's/[1-9][0-9]*/1/')"
R=$(get "/?tix_thankyou=1&order_id=$O&key=tfxkey")
check "Danke-Seite: Rahmen ohne Theme, Inhalt da, keine PHP-Meldung" "1|1|0|0" "$(printf '%s' "$R" | grep -c 'tix-frame-thankyou' | sed 's/[1-9][0-9]*/1/')|$(printf '%s' "$R" | grep -c 'tix-ty-wrap' | sed 's/[1-9][0-9]*/1/')|$(printf '%s' "$R" | grep -c 'THEME-INDEX')|$(printf '%s' "$R" | grep -ci 'deprecated\|warning:\|notice:\|fatal')"
$WP option update tix_tool_frame 0 >/dev/null 2>&1
R=$(get /tfx-einlass/)
check "Schalter aus: wieder Theme" "0|1" "$(printf '%s' "$R" | grep -c 'class="tix-frame')|$(printf '%s' "$R" | grep -c 'THEME-INDEX' | sed 's/[1-9][0-9]*/1/')"
$WP option delete tix_tool_frame >/dev/null 2>&1
R=$(get "/tfx-einlass/?breakdance=builder")
check "Breakdance-Editor: nie übernehmen" "0" "$(printf '%s' "$R" | grep -c 'class="tix-frame')"

# Aufräumen
docker exec -u 0 $C sh -c 'pkill -f "php -S 0.0.0.0:80" || kill $(pgrep -f "php -S") 2>/dev/null; true'
$WP eval "wp_delete_post($A, true); wp_delete_post($B, true); wp_delete_post($CC, true); global \$wpdb; \$wpdb->delete(\$wpdb->prefix.'tix_orders', ['id'=>$O]);" >/dev/null 2>&1
$WP theme activate "$OLD_THEME" >/dev/null 2>&1
docker exec -u 0 $C rm -rf /var/www/html/wp-content/themes/tix-leer-test
[ $fail -eq 0 ] && echo "ALLES OK ($n Prüfungen)" || echo "FEHLER: $fail ($n Prüfungen)"
