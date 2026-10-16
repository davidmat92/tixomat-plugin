-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – verwaiste Kinder entfernen (täglicher Abgleich)
-- Gäste und Kategorien ohne Event, Positionen und Zahlungen ohne Bestellung.
-- Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════════

create or replace function public.mirror_prune_orphans(p_site text)
returns jsonb
language plpgsql security definer set search_path = public as $$
declare g int; c int; i int; p int;
begin
    delete from guests x where x.site = p_site and not exists (select 1 from events e where e.site = x.site and e.id = x.event_id);
    get diagnostics g = row_count;
    delete from ticket_categories x where x.site = p_site and not exists (select 1 from events e where e.site = x.site and e.id = x.event_id);
    get diagnostics c = row_count;
    delete from order_items x where x.site = p_site and not exists (select 1 from orders o where o.site = x.site and o.id = x.order_id);
    get diagnostics i = row_count;
    delete from payments x where x.site = p_site and not exists (select 1 from orders o where o.site = x.site and o.id = x.order_id);
    get diagnostics p = row_count;
    return jsonb_build_object('guests', g, 'ticket_categories', c, 'order_items', i, 'payments', p);
end $$;

revoke all on function public.mirror_prune_orphans(text) from public, anon, authenticated;
grant execute on function public.mirror_prune_orphans(text) to service_role;
