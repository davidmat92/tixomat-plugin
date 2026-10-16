#!/usr/bin/env bash
# Notfall-Einlass in einem Schritt (Mac mit Docker Desktop). Nur lesend.
#
#   bash supabase/tools/notfall-einlass.sh kitchenklub.de            → Events von heute anzeigen
#   bash supabase/tools/notfall-einlass.sh kitchenklub.de 975        → Liste für Event 975 schreiben und öffnen
#   bash supabase/tools/notfall-einlass.sh kitchenklub.de --datum 2026-10-17
#
# Schlüssel: ~/.private_keys/supabase_datenspiegel.txt (wird nie angezeigt).
# Ergebnis: ~/Desktop/notfall-einlass/notfall-<seite>-<event>.html und .csv – enthalten Namen,
# nach dem Event bitte löschen.
set -euo pipefail

SITE="${1:?Aufruf: notfall-einlass.sh <seite> [event-id | --datum JJJJ-MM-TT]}"
ARG="${2:-}"
URL="${MIRROR_URL:-https://cdslxwiqqedznhdupacy.supabase.co}"
KEY="${MIRROR_KEY_FILE:-$HOME/.private_keys/supabase_datenspiegel.txt}"
OUT="${MIRROR_OUT:-$HOME/Desktop/notfall-einlass}"
TOOLS="$(cd "$(dirname "$0")" && pwd)"

[ -r "$KEY" ] || { echo "Schlüssel-Datei fehlt: $KEY" >&2; exit 2; }
command -v docker >/dev/null || { echo "Docker fehlt (Docker Desktop starten bzw. installieren)." >&2; exit 2; }
mkdir -p "$OUT"; chmod 700 "$OUT"

run() { docker run --rm ${MIRROR_DOCKER_NET:+--network "$MIRROR_DOCKER_NET"} -v "$KEY:/key:ro" -v "$TOOLS:/tools:ro" -v "$OUT:/out" php:8.2-cli php /tools/notfall-einlass.php --url="$URL" --key-file=/key --site="$SITE" "$@"; }

case "$ARG" in
    "")       run ;;
    --datum)  run --date="${3:?Datum fehlt, z. B. 2026-10-17}" ;;
    *)        run --event="$ARG" --out=/out
              f="$OUT/notfall-$(printf '%s' "$SITE" | sed -E 's/[^A-Za-z0-9.-]+/-/g')-$ARG.html"
              [ -f "$f" ] && { echo "Öffne $f"; [ -n "${MIRROR_NO_OPEN:-}" ] || open "$f" 2>/dev/null || true; } ;;
esac
