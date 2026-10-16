# Datenspiegel (Supabase)

WordPress/Tixomat ist führend. Supabase hält eine Kopie aller Seiten (Spalte `site`), gefüllt vom
Plugin über den Postausgang (`TIX_Mirror`). Doku: `DOCUMENTATION.md` §56.

## Migrationen

`migrations/*.sql` der Reihe nach einspielen, z. B. im SQL-Editor des Projekts oder per
`supabase db push` (Supabase CLI, Projekt verlinkt). Alle Dateien sind wiederholbar
(`if not exists`, `create or replace`).

- RLS ist auf allen Tabellen an, ohne Policies: `anon` und `authenticated` sehen nichts.
- Geschrieben wird nur mit dem Service-Schlüssel (umgeht RLS) vom Server der jeweiligen Seite.
- Funktionen: `mirror_forget` (Löschweg), `mirror_prune` (entfernte Positionen/Kategorien),
  `mirror_prune_change_log` (Aufbewahrung, ab Paket 2 täglich).

## Zugang auf einer Seite

Bevorzugt in `wp-config.php` (nicht in Datenbank-Sicherungen):

```php
define('TIX_MIRROR_URL', 'https://<projekt>.supabase.co');
define('TIX_MIRROR_KEY', '<service_role oder sb_secret_…>');
```

Alternativ Tixomat → Datenspiegel. Not-Aus ohne Admin: `define('TIX_MIRROR_DISABLED', true);`.
Ist der Zugang da, startet die Erstbefüllung von selbst.

## Lokal testen (nie gegen Live oder das echte Projekt)

```bash
export MIRROR_JWT_SECRET=$(openssl rand -hex 32)
PLUGIN_DIR=$PWD docker compose -f supabase/local/compose.yml up -d
docker exec mirror-cli wp core install --url=http://localhost:8097 --title=Mirror --admin_user=admin --admin_password=<lokal> --admin_email=admin@example.test --skip-email
docker exec mirror-cli wp plugin activate tixomat
docker exec mirror-cli wp option update timezone_string Europe/Berlin
docker exec mirror-cli wp eval --user=admin 'require_once ABSPATH."wp-admin/includes/admin.php"; do_action("admin_init");'
docker exec -i mirror-pg psql -U postgres < supabase/migrations/20261016120000_mirror_core.sql
docker kill -s SIGUSR1 mirror-rest   # Schema neu laden
# Test-Schlüssel (JWT role=service_role) mit MIRROR_JWT_SECRET signieren und eintragen:
#   wp option update tix_mirror '{"enabled":1,"url":"http://gw","key":"<jwt>"}' --format=json
docker exec mirror-cli wp eval-file wp-content/plugins/tixomat/tests/test-mirror-wp.php
```

Hinweis: WP-CLI setzt bei jedem Aufruf die WP-Cron-Sperre (Loopback auf `localhost:8097`
geht im Container ins Leere). Wer WP-Cron per HTTP testen will, löscht `_transient_doing_cron`
direkt in der Datenbank und ruft dann `/wp-cron.php` auf.
