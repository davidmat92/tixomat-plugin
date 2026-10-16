#!/usr/bin/env bash
# Spielt supabase/migrations/*.sql der Reihe nach in das Spiegel-Projekt ein (psql aus dem Docker-Image
# postgres:17, kein lokales psql nötig). Die Verbindung kommt aus einer Datei (Connection-String aus
# Supabase → Project Settings → Database) und wird nie ausgegeben; nur Host und Region werden gezeigt.
#
#   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --check   # nur prüfen
#   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt           # neue Migrationen einspielen
#   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --all     # alle erneut einspielen
#   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --verify  # RLS + Rechte prüfen
#   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --sql datei.sql  # einzelne SQL-Datei
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

# Selbst zerlegen statt den String an psql zu geben: Passwörter mit Sonderzeichen (@ : / ? & |) sind oft
# nicht URL-kodiert, psql würde sie falsch trennen und Teile davon in Fehlermeldungen zeigen.
# Getrennt wird am LETZTEN @ (Host-Teil enthält keins), Benutzer/Passwort am ERSTEN :.
rest="${DBURL#*://}"
userinfo="${rest%@*}"; hostpart="${rest##*@}"
PGUSER="${userinfo%%:*}"; PGPASSWORD="${userinfo#*:}"
hostport="${hostpart%%/*}"; PGHOST="${hostport%%:*}"
PGPORT="5432"; [ "$hostport" != "$PGHOST" ] && PGPORT="${hostport#*:}"
PGDATABASE="postgres"; [ "$hostpart" != "$hostport" ] && { db="${hostpart#*/}"; db="${db%%\?*}"; [ -n "$db" ] && PGDATABASE="$db"; }
# %XX nur dekodieren, wenn das Passwort wirklich so aussieht (URL-kodiert eingefügt)
if [[ "$PGPASSWORD" =~ %[0-9A-Fa-f]{2} ]]; then PGPASSWORD="$(printf '%b' "${PGPASSWORD//%/\\x}")"; fi
PGSSLMODE="${PGSSLMODE:-require}"
case "$PGHOST" in mirror-pg|localhost|127.0.0.1) PGSSLMODE=disable ;; esac  # nur lokaler Teststand
export PGUSER PGPASSWORD PGHOST PGPORT PGDATABASE PGSSLMODE
unset DBURL rest userinfo

host="$PGHOST"
region="unbekannt"
if [[ "$host" =~ ^aws-[0-9]+-([a-z0-9-]+)\.pooler\.supabase\.com$ ]]; then region="${BASH_REMATCH[1]}"; fi
echo "Host:   $host:$PGPORT"
echo "Region: $region$([ "$region" = "eu-central-1" ] && echo ' (Frankfurt)')"

# Jede Ausgabe läuft durch mask(): das Passwort (und Teile ab 6 Zeichen) wird nie angezeigt.
mask() {
    local t="$1" i n=${#PGPASSWORD}
    [ "$n" -gt 0 ] && t="${t//"$PGPASSWORD"/***}"
    if [ "$n" -ge 6 ]; then for ((i = 0; i + 6 <= n; i++)); do t="${t//"${PGPASSWORD:i:6}"/***}"; done; fi
    printf '%s' "$t"
}
# MIRROR_DOCKER_NET nur für den lokalen Test (Docker-Netz des Teststands)
psql_raw() { docker run --rm -i ${MIRROR_DOCKER_NET:+--network "$MIRROR_DOCKER_NET"} -e PGUSER -e PGPASSWORD -e PGHOST -e PGPORT -e PGDATABASE -e PGSSLMODE postgres:17 psql -X -q -v ON_ERROR_STOP=1 "$@"; }
psql_run() {
    local out rc=0
    out="$(psql_raw "$@" 2>&1)" || rc=$?
    [ -n "$out" ] && printf '%s\n' "$(mask "$out")" | grep -v '^NOTICE:' | grep -v '^$' || true
    return $rc
}

echo "Verbindung …"
psql_run -t -A -c "select 'Postgres ' || current_setting('server_version') || ', Datenbank ' || current_database()"

if [ "$MODE" = "--check" ]; then
    echo "Tabellen im Schema public:"
    psql_run -t -A -c "select coalesce(string_agg(tablename, ', ' order by tablename), '(keine)') from pg_tables where schemaname = 'public'"
    if [ "$(psql_raw -t -A -c "select to_regclass('public.mirror_migrations') is not null" 2>/dev/null)" = "t" ]; then
        psql_run -t -A -c "select 'Eingespielt: ' || coalesce(string_agg(name, ', ' order by name), '-') from public.mirror_migrations"
    else
        echo "Eingespielt: - (noch keine)"
    fi
    exit 0
fi

if [ "$MODE" = "--sql" ]; then
    SQLFILE="${3:?--sql braucht eine SQL-Datei}"
    [ -r "$SQLFILE" ] || { echo "Datei nicht lesbar: $SQLFILE" >&2; exit 2; }
    echo "Führe $(basename "$SQLFILE") aus …"
    psql_run -t -A < "$SQLFILE"
    exit $?
fi

if [ "$MODE" = "--verify" ]; then
    echo "Prüfung RLS und Rechte (anon, authenticated):"
    psql_run -t -A <<'SQL'
select 'Tabellen: ' || count(*) || ', davon ohne RLS: ' || count(*) filter (where not rowsecurity)
       || coalesce(' (' || string_agg(tablename, ', ') filter (where not rowsecurity) || ')', '')
  from pg_tables where schemaname = 'public';
select 'Policies (sollen 0 sein): ' || count(*) from pg_policies where schemaname = 'public';
select 'Tabellen-Rechte für anon/authenticated: ' || count(*)
       || coalesce(' → ' || string_agg(distinct c.relname || ':' || r.rolname || ':' || p.priv, ', '), '')
  from pg_class c join pg_namespace n on n.oid = c.relnamespace
  cross join (values ('anon'), ('authenticated')) r(rolname)
  cross join (values ('SELECT'), ('INSERT'), ('UPDATE'), ('DELETE'), ('TRUNCATE')) p(priv)
 where n.nspname = 'public' and c.relkind in ('r', 'v', 'm') and has_table_privilege(r.rolname, c.oid, p.priv);
select 'Sequenz-Rechte für anon/authenticated: ' || count(*)
  from pg_class c join pg_namespace n on n.oid = c.relnamespace
  cross join (values ('anon'), ('authenticated')) r(rolname)
 where n.nspname = 'public' and c.relkind = 'S' and has_sequence_privilege(r.rolname, c.oid, 'USAGE');
select 'Funktionen für anon/authenticated ausführbar: ' || count(*)
       || coalesce(' → ' || string_agg(distinct p.proname || ':' || r.rolname, ', '), '')
  from pg_proc p join pg_namespace n on n.oid = p.pronamespace
  cross join (values ('anon'), ('authenticated')) r(rolname)
 where n.nspname = 'public' and p.proname like 'mirror\_%' and p.prorettype <> 'trigger'::regtype
   and has_function_privilege(r.rolname, p.oid, 'EXECUTE');
select 'service_role darf schreiben: ' || bool_and(has_table_privilege('service_role', c.oid, 'INSERT'))::text
  from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r';
SQL
    exit $?
fi

psql_run <<'SQL'
create table if not exists public.mirror_migrations (name text primary key, applied_at timestamptz not null default now());
alter table public.mirror_migrations enable row level security;
revoke all on public.mirror_migrations from anon, authenticated;
SQL

applied="$(psql_raw -t -A -c "select string_agg(name, ' ') from public.mirror_migrations" 2>/dev/null || true)"
for f in "$DIR"/*.sql; do
    name="$(basename "$f")"
    # Schon eingespielte Dateien überspringen (laufende Seiten schreiben weiter); --all spielt alles neu ein
    if [ "$MODE" != "--all" ] && [[ " $applied " == *" $name "* ]]; then echo "= $name (schon eingespielt)"; continue; fi
    echo "→ $name"
    if ! psql_run < "$f"; then
        echo "Abbruch in $name (nichts weiter eingespielt)." >&2
        exit 1
    fi
    psql_run -c "insert into public.mirror_migrations (name) values ('$name') on conflict (name) do update set applied_at = now()"
done

echo "Neu laden des API-Schemas …"
psql_run -c "notify pgrst, 'reload schema'"
echo "Fertig. Tabellen:"
psql_run -t -A -c "select string_agg(tablename, ', ' order by tablename) from pg_tables where schemaname = 'public'"
