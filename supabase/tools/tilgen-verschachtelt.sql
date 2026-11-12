-- Datenspiegel: sensible Felder auch VERSCHACHTELT aus Meta-Daten tilgen (1.38.770, Fund 12.11.2026:
-- `_tix_co_mirror` [{oid, access, token}] auf evendis.de und `_tix_co_remotes` [{…, token_hash}] auf kitchenklub.de).
-- Gleiche Liste wie TIX_Mirror_Map::DENY (Teilstrings, ohne Groß/klein). Nur in data/meta-Spalten, nie in Spaltennamen.
-- Betrifft events, organizers, coupons, artists (data), wallet_transactions (meta) und deren Verlauf in change_log.
-- Wiederholbar. Erst NACH dem Plugin-Rollout 1.38.770 laufen lassen (sonst schreibt die Seite den alten Stand wieder).
--   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --sql supabase/tools/tilgen-verschachtelt.sql
begin;

create or replace function pg_temp.mirror_sensitive(k text) returns boolean language sql immutable as $$
  select lower(k) ~ '(pass|token|secret|iban|session|nonce|otp|awareness|api_key|apikey|private|id_photo|ausweis|_tix_cart|guest_list|guestlist|checkin_password|webhook|pushover|payout_pending|code_hash|public_key|_pin|pin_|hash|_key|wfls_|2fa|totp|recovery|application_password|credential)'
$$;

create or replace function pg_temp.mirror_strip(j jsonb) returns jsonb language plpgsql immutable as $$
declare r jsonb; e record;
begin
  if j is null then return null; end if;
  if jsonb_typeof(j) = 'object' then
    r := '{}'::jsonb;
    for e in select key, value from jsonb_each(j) loop
      if not pg_temp.mirror_sensitive(e.key) then r := r || jsonb_build_object(e.key, pg_temp.mirror_strip(e.value)); end if;
    end loop;
    return r;
  elsif jsonb_typeof(j) = 'array' then
    select coalesce(jsonb_agg(pg_temp.mirror_strip(x) order by n), '[]'::jsonb) into r from jsonb_array_elements(j) with ordinality t(x, n);
    return r;
  end if;
  return j;
end $$;

-- Ausnahmen wie im Datenschutz-Scan: bewusst so benannte Felder in Rechnungs-/Abrechnungs-Schnappschüssen bleiben.
-- (Sie stehen nicht in den hier bereinigten Spalten; die Liste dient nur der Vorsicht.)

-- 1. Aktuelle Zeilen (Trigger schreiben dabei bereinigte Verlaufseinträge)
update public.events              set data = pg_temp.mirror_strip(data) where data <> pg_temp.mirror_strip(data);
update public.organizers          set data = pg_temp.mirror_strip(data) where data <> pg_temp.mirror_strip(data);
update public.coupons             set data = pg_temp.mirror_strip(data) where data <> pg_temp.mirror_strip(data);
update public.artists             set data = pg_temp.mirror_strip(data) where data <> pg_temp.mirror_strip(data);
update public.wallet_transactions set meta = pg_temp.mirror_strip(meta) where meta <> pg_temp.mirror_strip(meta);

-- 2. Verlauf: frühere Stände (die Zeile steht als jsonb in change_log.data)
update public.change_log l set data = jsonb_set(l.data, '{data}', pg_temp.mirror_strip(l.data->'data'))
 where l.entity in ('events', 'organizers', 'coupons', 'artists') and jsonb_typeof(l.data->'data') in ('object', 'array')
   and l.data->'data' <> pg_temp.mirror_strip(l.data->'data');
update public.change_log l set data = jsonb_set(l.data, '{meta}', pg_temp.mirror_strip(l.data->'meta'))
 where l.entity = 'wallet_transactions' and jsonb_typeof(l.data->'meta') in ('object', 'array')
   and l.data->'meta' <> pg_temp.mirror_strip(l.data->'meta');

-- 3. Kontrolle: muss überall 0 sein
select 'Rest events: '     || count(*) from public.events where data <> pg_temp.mirror_strip(data);
select 'Rest change_log: ' || count(*) from public.change_log l
 where (l.entity in ('events', 'organizers', 'coupons', 'artists') and jsonb_typeof(l.data->'data') in ('object', 'array') and l.data->'data' <> pg_temp.mirror_strip(l.data->'data'))
    or (l.entity = 'wallet_transactions' and jsonb_typeof(l.data->'meta') in ('object', 'array') and l.data->'meta' <> pg_temp.mirror_strip(l.data->'meta'));
commit;
