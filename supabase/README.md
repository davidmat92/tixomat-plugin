# Datenspiegel (Supabase)

WordPress/Tixomat ist führend. Supabase hält eine Kopie aller Seiten (Spalte `site`), gefüllt vom
Plugin über den Postausgang (`TIX_Mirror`). Doku: `DOCUMENTATION.md` §56.

## Migrationen

`migrations/*.sql` der Reihe nach einspielen. Alle Dateien sind wiederholbar
(`if not exists`, `create or replace`). Am einfachsten mit dem Skript (psql aus Docker, Connection-String
aus einer Datei, wird nie ausgegeben; zeigt Host und Region):

```bash
bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --check
bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt
```

Den Session-Pooler (`aws-…-eu-central-1.pooler.supabase.com:5432`) verwenden; der direkte Host ist
nur per IPv6 erreichbar. Eingespieltes steht in `public.mirror_migrations`.

- RLS ist auf allen Tabellen an, ohne Policies: `anon` und `authenticated` sehen nichts.
- Geschrieben wird nur mit dem Service-Schlüssel (umgeht RLS) vom Server der jeweiligen Seite.
- Funktionen: `mirror_forget` (Löschweg), `mirror_prune` (entfernte Positionen, Kategorien, Gäste,
  Stempelkarten, Gutscheine), `mirror_fingerprints` (täglicher Abgleich), `mirror_prune_log` (Aufbewahrung).
- Tabelle `sync_reports`: Bericht des täglichen Abgleichs je Seite und Tag.

## Zugang auf einer Seite

Bevorzugt in `wp-config.php` (nicht in Datenbank-Sicherungen):

```php
define('TIX_MIRROR_URL', 'https://<projekt>.supabase.co');
define('TIX_MIRROR_KEY', '<service_role oder sb_secret_…>');
```

Alternativ Tixomat → Datenspiegel. Not-Aus ohne Admin: `define('TIX_MIRROR_DISABLED', true);`.

Für IBANs (Veranstalter, Abrechnungen) zusätzlich, auf allen Seiten gleich und sicher verwahrt
(ohne ihn ist eine IBAN im Spiegel nur mit den Salts der jeweiligen Seite lesbar):

```php
define('TIX_MIRROR_ENC_KEY', '<eigener langer Zufallswert>');
```

## Notfall-Einlass (Seite ausgefallen)

```bash
php supabase/tools/notfall-einlass.php --url=https://<projekt>.supabase.co --key-file=~/.private_keys/supabase_<…>.txt --site=kitchenklub.de
php supabase/tools/notfall-einlass.php --url=… --key-file=… --site=kitchenklub.de --event=<ID> --out=.
```

Erst die Events des Tages auflisten, dann je Event `notfall-<seite>-<id>.csv` und `.html` schreiben.
Die HTML-Datei läuft offline auf jedem Handy/Tablet (Suche nach Code oder Name, „einlassen“ merkt
sich das Gerät). Nichts wird zurückgeschrieben.
Ist der Zugang da, startet die Erstbefüllung von selbst.

## Lokal testen (nie gegen Live oder das echte Projekt)

```bash
export MIRROR_JWT_SECRET=$(openssl rand -hex 32)
PLUGIN_DIR=$PWD docker compose -f supabase/local/compose.yml up -d
docker exec mirror-cli wp core install --url=http://localhost:8097 --title=Mirror --admin_user=admin --admin_password=<lokal> --admin_email=admin@example.test --skip-email
docker exec mirror-cli wp plugin activate tixomat
docker exec mirror-cli wp option update timezone_string Europe/Berlin
docker exec mirror-cli wp eval --user=admin 'require_once ABSPATH."wp-admin/includes/admin.php"; do_action("admin_init");'
for f in supabase/migrations/*.sql; do docker exec -i mirror-pg psql -U postgres < "$f"; done
docker kill -s SIGUSR1 mirror-rest   # Schema neu laden
# Test-Schlüssel (JWT role=service_role) mit MIRROR_JWT_SECRET signieren und eintragen:
#   wp option update tix_mirror '{"enabled":1,"url":"http://gw","key":"<jwt>"}' --format=json
docker exec mirror-cli wp eval-file wp-content/plugins/tixomat/tests/test-mirror-wp.php
docker exec mirror-cli wp eval-file wp-content/plugins/tixomat/tests/test-mirror-paket2-wp.php
```

Gegen das echte Projekt nur mit eigener Test-Kennung (Einstellung „Kennung der Seite“, z. B.
`datenspiegel-test.local`) und `MIRROR_TEST_REMOTE=1`; danach `tests/mirror-test-site-cleanup.php`
(entfernt alle Zeilen dieser Kennung, verweigert alles andere).

Hinweis: WP-CLI setzt bei jedem Aufruf die WP-Cron-Sperre (Loopback auf `localhost:8097`
geht im Container ins Leere). Wer WP-Cron per HTTP testen will, löscht `_transient_doing_cron`
direkt in der Datenbank und ruft dann `/wp-cron.php` auf.
