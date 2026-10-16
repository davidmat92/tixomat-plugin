-- Datenspiegel: Konto-Meta auf die Erlaubnisliste zurückschneiden (1.38.470, Fund KitchenKlub: Kassen-PIN-Hash).
-- Entfernt in customers.data und im Verlauf (change_log, Einträge der Tabelle customers) jedes Feld, das nicht
-- in TIX_Mirror_Map::USER_META bzw. CUSTOMER_META_ALLOW steht, und zählt danach nach. Wiederholbar.
--   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --sql supabase/tools/tilgen-konto-meta.sql
begin;
create temporary table mirror_allow (k text primary key) on commit drop;
insert into mirror_allow (k) values
  ('first_name'), ('last_name'), ('nickname'), ('billing_first_name'), ('billing_last_name'), ('billing_company'),
  ('billing_address_1'), ('billing_address_2'), ('billing_postcode'), ('billing_city'), ('billing_country'),
  ('billing_phone'), ('billing_email'),
  ('_tix_phone'), ('_tix_email_confirmed'), ('_tix_email_confirmed_by'), ('_tix_team_organizer_id'),
  ('_tix_organizer_name'), ('_tix_saved_events'), ('_tix_app_following'), ('_tix_app_verified'),
  ('_tix_app_created'), ('_tix_first_event_celebrated'), ('_tix_legacy_source'), ('_tix_legacy_user_id'),
  ('_tix_avatar_url'), ('_tix_avatar_id');

-- 1. Aktuelle Zeilen (der Trigger schreibt dabei einen bereinigten Verlaufseintrag)
update public.customers c
   set data = coalesce((select jsonb_object_agg(e.key, e.value) from jsonb_each(c.data) e
                         where e.key in (select k from mirror_allow)), '{}'::jsonb)
 where jsonb_typeof(c.data) = 'object'
   and exists (select 1 from jsonb_object_keys(c.data) x where x not in (select k from mirror_allow));

-- 2. Verlauf: alle früheren Stände der Konten
update public.change_log l
   set data = jsonb_set(l.data, '{data}', coalesce((select jsonb_object_agg(e.key, e.value) from jsonb_each(l.data->'data') e
                                                     where e.key in (select k from mirror_allow)), '{}'::jsonb))
 where l.entity = 'customers' and jsonb_typeof(l.data->'data') = 'object'
   and exists (select 1 from jsonb_object_keys(l.data->'data') x where x not in (select k from mirror_allow));

-- 3. Kontrolle (alle Seiten)
select 'customers mit Feldern außerhalb der Erlaubnisliste: ' || count(*) from public.customers c
 where jsonb_typeof(c.data) = 'object' and exists (select 1 from jsonb_object_keys(c.data) x where x not in (select k from mirror_allow));
select 'Verlauf (customers) mit Feldern außerhalb der Erlaubnisliste: ' || count(*) from public.change_log l
 where l.entity = 'customers' and jsonb_typeof(l.data->'data') = 'object'
   and exists (select 1 from jsonb_object_keys(l.data->'data') x where x not in (select k from mirror_allow));
select 'Hash-artige Werte im gesamten Verlauf: ' || count(*) from public.change_log where data::text ~ '"\$(P|H|wp|2y|2a|2b|argon2id?)\$';
select 'Hash-artige Werte in customers: ' || count(*) from public.customers where data::text ~ '"\$(P|H|wp|2y|2a|2b|argon2id?)\$';
select 'Feld _tix_pos_pin irgendwo (customers + Verlauf): ' || ((select count(*) from public.customers where data ? '_tix_pos_pin')
       + (select count(*) from public.change_log where data->'data' ? '_tix_pos_pin'));
commit;
