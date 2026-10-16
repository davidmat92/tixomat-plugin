-- Datenspiegel: Seiten-Kennung umbenennen  veranstalter.evendis.de → evendis.de  (Entscheidung des Users 2026-10-16)
-- Eine Transaktion über alle Tabellen inkl. change_log und sites. Die Verlaufs-Trigger sind dabei aus, damit die
-- Umbenennung keine neuen Verlaufseinträge erzeugt. Bricht ab, wenn es unter der neuen Kennung schon Zeilen gibt.
-- Vorher auf der Seite den Spiegel pausieren (TIX_MIRROR_DISABLED), danach Kennung setzen und „Abgleich jetzt“.
--   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --sql supabase/tools/kennung-evendis.sql
-- Als Vorlage für andere Seiten: nur die beiden Werte in mirror_rename ändern.
begin;
create temporary table mirror_rename (old_site text, new_site text) on commit drop;
insert into mirror_rename values ('veranstalter.evendis.de', 'evendis.de');

create temporary table mirror_rename_tables (t text) on commit drop;
insert into mirror_rename_tables values ('sites'), ('events'), ('ticket_categories'), ('guests'), ('customers'), ('loyalty'),
  ('orders'), ('order_items'), ('payments'), ('tickets'), ('checkins'), ('refunds'), ('organizers'), ('coupons'),
  ('wallets'), ('wallet_transactions'), ('settlements'), ('settlement_items'), ('invoices'), ('invoice_counters'),
  ('sync_reports'), ('change_log');

do $$
declare
    o text; n text; t text; c bigint; before_total bigint := 0; after_total bigint := 0;
begin
    select old_site, new_site into o, n from mirror_rename;
    -- Schutz: unter der neuen Kennung darf es noch nichts geben
    for t in select x.t from mirror_rename_tables x loop
        execute format('select count(*) from public.%I where site = $1', t) into c using n;
        if c > 0 then raise exception 'Abbruch: % hat schon % Zeilen unter %', t, c, n; end if;
        execute format('select count(*) from public.%I where site = $1', t) into c using o;
        before_total := before_total + c;
    end loop;
    -- Trigger aus (kein neuer Verlauf, synced_at bleibt)
    for t in select x.t from mirror_rename_tables x where x.t not in ('change_log') loop
        if exists (select 1 from pg_trigger where tgrelid = ('public.' || t)::regclass and tgname = t || '_log') then
            execute format('alter table public.%I disable trigger %I', t, t || '_log');
        end if;
        if exists (select 1 from pg_trigger where tgrelid = ('public.' || t)::regclass and tgname = t || '_touch') then
            execute format('alter table public.%I disable trigger %I', t, t || '_touch');
        end if;
    end loop;
    for t in select x.t from mirror_rename_tables x loop
        execute format('update public.%I set site = $1 where site = $2', t) using n, o;
    end loop;
    -- sites: URL steht weiter als Spalte, Kennung ist jetzt die neue
    for t in select x.t from mirror_rename_tables x where x.t not in ('change_log') loop
        if exists (select 1 from pg_trigger where tgrelid = ('public.' || t)::regclass and tgname = t || '_log') then
            execute format('alter table public.%I enable trigger %I', t, t || '_log');
        end if;
        if exists (select 1 from pg_trigger where tgrelid = ('public.' || t)::regclass and tgname = t || '_touch') then
            execute format('alter table public.%I enable trigger %I', t, t || '_touch');
        end if;
    end loop;
    for t in select x.t from mirror_rename_tables x loop
        execute format('select count(*) from public.%I where site = $1', t) into c using n;
        after_total := after_total + c;
        execute format('select count(*) from public.%I where site = $1', t) into c using o;
        if c > 0 then raise exception 'Abbruch: % hat noch % Zeilen unter %', t, c, o; end if;
    end loop;
    if before_total <> after_total then raise exception 'Abbruch: vorher % Zeilen, nachher %', before_total, after_total; end if;
    raise notice 'umbenannt: % Zeilen', after_total;
end $$;

select 'Zeilen je Kennung jetzt: ' || string_agg(site || ' ' || n, ', ' order by site) from (
  select site, count(*) n from (
    select site from public.sites union all select site from public.events union all select site from public.customers
    union all select site from public.orders union all select site from public.tickets union all select site from public.organizers
    union all select site from public.change_log) a group by site) b;
select 'Trigger aus (soll 0 sein): ' || count(*) from pg_trigger
 where not tgisinternal and tgenabled = 'D' and tgrelid in (select ('public.' || t)::regclass from mirror_rename_tables);
commit;
