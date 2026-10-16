#!/usr/bin/env bash
# Spielt supabase/migrations/*.sql der Reihe nach in das Spiegel-Projekt ein (psql aus dem Docker-Image
# postgres:17, kein lokales psql nötig). Die Verbindung kommt aus einer Datei (Connection-String aus
# Supabase → Project Settings → Database) und wird nie ausgegeben; nur Host und Region werden gezeigt.
#
#   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --check   # nur prüfen
#   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt           # einspielen
#
# Alle Migrationen sind wiederholbar. Eingespielte Dateien stehen in public.mirror_migrations.
# Hinweis: Der direkte Host db.<ref>.supabase.co ist nur per IPv6 erreichbar; aus Docker auf dem Mac
# den Session-Pooler (…pooler.supabase.com:5432) verwenden.
set -euo pipefail

FILE="${1:?Aufruf: migrate.sh <datei-mit-connection-string> [--check]}"
MODE="${2:-apply}"
DIR="$(cd "$(dirname "$0")/../migrations" && pwd)"
[ -r "$FILE" ] || { echo "Datei nicht lesbar: $FILE" >&2; exit 2; }

DBURL="$(tr -d '\r\n' < "$FILE" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
case "$DBURL" in postgres://*|postgresql://*) ;; *) echo "Kein postgres://-Connection-String in der Datei." >&2; exit 2 ;; esac
export DBURL

# Host und Region ohne Zugangsdaten zeigen
rest="${DBURL#*://}"; rest="${rest##*@}"; host="${rest%%[:/?]*}"
region="unbekannt"
if [[ "$host" =~ ^aws-[0-9]+-([a-z0-9-]+)\.pooler\.supabase\.com$ ]]; then region="${BASH_REMATCH[1]}"; fi
echo "Host:   $host"
echo "Region: $region$([ "$region" = "eu-central-1" ] && echo ' (Frankfurt)')"

# MIRROR_DOCKER_NET nur für den lokalen Test (Docker-Netz des Teststands)
psql_run() { docker run --rm -i ${MIRROR_DOCKER_NET:+--network "$MIRROR_DOCKER_NET"} -e DBURL postgres:17 sh -c 'psql "$DBURL" -X -q -v ON_ERROR_STOP=1 "$@"' psql "$@"; }

echo "Verbindung …"
psql_run -t -A -c "select 'Postgres ' || current_setting('server_version') || ', Datenbank ' || current_database()"

if [ "$MODE" = "--check" ]; then
    echo "Tabellen im Schema public:"
    psql_run -t -A -c "select coalesce(string_agg(tablename, ', ' order by tablename), '(keine)') from pg_tables where schemaname = 'public'"
    psql_run -t -A -c "select 'Eingespielt: ' || coalesce(string_agg(name, ', ' order by name), '-') from public.mirror_migrations" 2>/dev/null || echo "Eingespielt: - (noch keine)"
    exit 0
fi

psql_run <<'SQL'
create table if not exists public.mirror_migrations (name text primary key, applied_at timestamptz not null default now());
alter table public.mirror_migrations enable row level security;
revoke all on public.mirror_migrations from anon, authenticated;
SQL

for f in "$DIR"/*.sql; do
    name="$(basename "$f")"
    echo "→ $name"
    if ! out="$(psql_run < "$f" 2>&1)"; then
        printf '%s\n' "$out" | grep -v '^NOTICE:' >&2 || true
        echo "Abbruch in $name (nichts weiter eingespielt)." >&2
        exit 1
    fi
    printf '%s\n' "$out" | grep -v '^NOTICE:' | grep -v '^$' || true
    psql_run -c "insert into public.mirror_migrations (name) values ('$name') on conflict (name) do update set applied_at = now()"
done

echo "Neu laden des API-Schemas …"
psql_run -c "notify pgrst, 'reload schema'"
echo "Fertig. Tabellen:"
psql_run -t -A -c "select string_agg(tablename, ', ' order by tablename) from pg_tables where schemaname = 'public'"
