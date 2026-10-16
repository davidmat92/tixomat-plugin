-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Verlauf anonymisierter Datensätze tilgen (1.38.460+)
-- Kommt eine Bestellung oder ein Ticket anonymisiert an (Konto gelöscht bzw. Ende der
-- Aufbewahrungsfrist für Rechnungsdaten), bleibt nur der neueste, anonyme Verlaufseintrag;
-- ältere Einträge mit Personenbezug werden gelöscht. Wiederholbar.
-- p_items: [{"entity": "orders", "id": "123"}, …]
-- ════════════════════════════════════════════════════════════════════════════

create or replace function public.mirror_purge_history(p_site text, p_items jsonb)
returns integer
language plpgsql security definer set search_path = public as $$
declare n int;
begin
    delete from change_log c
     using (select distinct s->>'entity' as entity, s->>'id' as object_id
              from jsonb_array_elements(p_items) s
             where s->>'entity' in ('orders', 'tickets', 'guests', 'customers')) x
     where c.site = p_site and c.entity = x.entity and c.object_id = x.object_id
       and c.id < (select max(c2.id) from change_log c2
                    where c2.site = p_site and c2.entity = x.entity and c2.object_id = x.object_id);
    get diagnostics n = row_count;
    return n;
end $$;

revoke all on function public.mirror_purge_history(text, jsonb) from public, anon, authenticated;
grant execute on function public.mirror_purge_history(text, jsonb) to service_role;
