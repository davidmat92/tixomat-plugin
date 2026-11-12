#!/usr/bin/env bash
# Weg-1-Regeln (1.38.767, DOCUMENTATION.md §171): nichts Neues mit WP-Oberfläche oder Fremd-Plugins.
# Zählt Muster im Plugin-Code und vergleicht mit tests/weg1-baseline.txt. Mehr als die Grundlinie → Fehler (Exit 1).
# Weniger ist gut: dann mit --update die Grundlinie senken.
#   bash tests/check-weg1.sh [--update]
set -u
cd "$(dirname "$0")/.."
B=tests/weg1-baseline.txt
count() { grep -rEo --include='*.php' "$1" includes tixomat.php templates 2>/dev/null | wc -l | tr -d ' '; }
declare -a KEYS=(shortcode admin_menu meta_box wp_ajax register_setting woocommerce option_je_objekt)
pat() {
  case "$1" in
    shortcode)        echo "add_shortcode\(" ;;
    admin_menu)       echo "add_(sub)?menu_page\(" ;;
    meta_box)         echo "add_meta_box\(" ;;
    wp_ajax)          echo "['\"]wp_ajax_(nopriv_)?[a-z0-9_]+" ;;
    register_setting) echo "register_setting\(" ;;
    woocommerce)      echo "(wc_get_order|wc_get_product|wc_create_order|WC\(\)|woocommerce_[a-z_]+['\"])" ;;
    option_je_objekt) echo "update_option\(['\"]_?tix_[a-z0-9_]+_['\"] *\." ;;
  esac
}
if [ "${1:-}" = "--update" ]; then
  : > "$B"; for k in "${KEYS[@]}"; do echo "$k $(count "$(pat $k)")" >> "$B"; done
  echo "Grundlinie neu geschrieben:"; cat "$B"; exit 0
fi
[ -f "$B" ] || { echo "Keine Grundlinie ($B) – erst mit --update anlegen."; exit 2; }
fail=0
for k in "${KEYS[@]}"; do
  now=$(count "$(pat $k)"); base=$(awk -v k="$k" '$1==k{print $2}' "$B"); base=${base:-0}
  if [ "$now" -gt "$base" ]; then fail=1; echo "FAIL  $k: $now > Grundlinie $base (neu dazugekommen – Weg 1: REST/Studio statt WP-Oberfläche, eigene Tabelle statt Option je Objekt)";
  elif [ "$now" -lt "$base" ]; then echo "ok    $k: $now < $base (weniger – Grundlinie mit --update senken)";
  else echo "ok    $k: $now"; fi
done
[ $fail -eq 0 ] && echo "ALLES OK (Weg-1-Regeln)" || { echo "FEHLER: Weg-1-Regeln verletzt"; exit 1; }
