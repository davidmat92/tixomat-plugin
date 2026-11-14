#!/usr/bin/env bash
# Zweitmarkt (§223): bash tests/test-resale.sh <container> [<container> …]
# Setzt für den Lauf einen Test-Mollie-Schlüssel (tix_get_settings() speichert je Prozess zwischen) und stellt danach den alten Stand her.
set -u
fail=0
for c in "$@"; do
  echo "═══ $c"
  docker exec "$c" sh -c '
    W="wp --allow-root"
    OLD=$($W option get tix_settings --format=json 2>/dev/null || echo "{}")
    $W eval "\$s = get_option(\"tix_settings\", []); if (!is_array(\$s)) \$s = []; \$s[\"mollie_api_key\"] = \"test_resale_dummy\"; \$s[\"partner_api_enabled\"] = 1; \$s[\"partner_api_key\"] = \"resale-test-partner-key-1234567890\"; if (empty(\$s[\"syndication_api_url\"])) \$s[\"syndication_api_url\"] = \"https://platform.test/wp-json/tixomat/v1\"; update_option(\"tix_settings\", \$s);" >/dev/null
    $W eval-file wp-content/plugins/tixomat/tests/test-resale-wp.php; RC=$?
    printf "%s" "$OLD" | $W option update tix_settings --format=json >/dev/null
    exit $RC
  ' | tee /tmp/test-resale-$c.log
  grep -q "^ALLES OK" /tmp/test-resale-$c.log || fail=1
done
exit $fail
