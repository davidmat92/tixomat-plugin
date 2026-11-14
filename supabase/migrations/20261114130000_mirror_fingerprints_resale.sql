-- ════════════════════════════════════════════════════════════════════════
-- Datenspiegel – täglicher Abgleich für den Zweitmarkt (resale_offers, RS7, Plugin 1.38.835)
-- Prüfsumme je Angebot: Zustand, Preis, neues Ticket, Auszahlungs-Zustand und -Betrag (wie TIX_Mirror_Reconcile).
-- Enthält schon den Zweig sponsor_logos (Branch dev/datenspiegel-sponsoren), damit sich die beiden Migrationen nicht
-- gegenseitig überschreiben: wer zuletzt läuft, bringt beide Zweige mit. Wiederholbar. Nach 20261114120000.
-- ════════════════════════════════════════════════════════════════════════

create or replace function public.mirror_fingerprints(p_site text, p_table text, p_from bigint, p_to bigint)
returns jsonb
language plpgsql stable security definer set search_path = public as $$
declare r jsonb;
begin
    if p_table = 'events' then
        select jsonb_object_agg(e.id::text, md5(concat_ws('|', e.status, e.title,
                   (select count(*) from ticket_categories c where c.site = e.site and c.event_id = e.id)::text,
                   (select count(*) from guests g where g.site = e.site and g.event_id = e.id)::text)))
          into r from events e where e.site = p_site and e.id > p_from and e.id <= p_to;
    elsif p_table = 'tickets' then
        select jsonb_object_agg(t.id::text, md5(concat_ws('|', t.post_status, t.status,
                   case when t.checked_in then '1' else '0' end, t.owner_email, coalesce(t.order_id::text, ''))))
          into r from tickets t where t.site = p_site and t.id > p_from and t.id <= p_to;
    elsif p_table = 'customers' then
        select jsonb_object_agg(c.id::text, md5(concat_ws('|', c.email, c.display_name, array_to_string(c.roles, ','))))
          into r from customers c where c.site = p_site and c.id > p_from and c.id <= p_to;
    elsif p_table = 'orders' then
        select jsonb_object_agg(o.id::text, md5(concat_ws('|', o.status, o.total::text, o.billing_email, o.refunded_total::text)))
          into r from orders o where o.site = p_site and o.id > p_from and o.id <= p_to;
    elsif p_table = 'organizers' then
        select jsonb_object_agg(g.id::text, md5(concat_ws('|', g.status, g.name, g.approval, coalesce(g.payout->>'iban_masked', ''))))
          into r from organizers g where g.site = p_site and g.id > p_from and g.id <= p_to;
    elsif p_table = 'wallets' then
        select jsonb_object_agg(w.id::text, md5(concat_ws('|', w.user_id::text, w.status, w.balance_paid::text, w.balance_bonus::text)))
          into r from wallets w where w.site = p_site and w.id > p_from and w.id <= p_to;
    elsif p_table = 'wallet_transactions' then
        select jsonb_object_agg(x.id::text, md5(concat_ws('|', x.wallet_id::text, x.type, x.amount_paid::text, x.amount_bonus::text)))
          into r from wallet_transactions x where x.site = p_site and x.id > p_from and x.id <= p_to;
    elsif p_table = 'settlements' then
        select jsonb_object_agg(s.id::text, md5(concat_ws('|', s.status, s.number, coalesce(s.payout_amount::text, ''))))
          into r from settlements s where s.site = p_site and s.id > p_from and s.id <= p_to;
    elsif p_table = 'settlement_items' then
        select jsonb_object_agg(i.id::text, md5(concat_ws('|', i.settlement_id::text, i.type, coalesce(i.amount::text, ''))))
          into r from settlement_items i where i.site = p_site and i.id > p_from and i.id <= p_to;
    elsif p_table = 'invoices' then
        select jsonb_object_agg(v.id::text, md5(concat_ws('|', v.number, v.kind, v.gross::text, v.pdf_sha256, v.export_status)))
          into r from invoices v where v.site = p_site and v.id > p_from and v.id <= p_to;
    elsif p_table = 'audit_log' then
        select jsonb_object_agg(a.id::text, md5(concat_ws('|', a.action, a.subject_id)))
          into r from audit_log a where a.site = p_site and a.id > p_from and a.id <= p_to;
    elsif p_table = 'artists' then
        select jsonb_object_agg(a.id::text, md5(concat_ws('|', a.post_status, a.name, a.status)))
          into r from artists a where a.site = p_site and a.id > p_from and a.id <= p_to;
    elsif p_table = 'artist_followers' then
        select jsonb_object_agg(f.id::text, md5(concat_ws('|', f.artist_id::text, f.status, coalesce(f.cities::text, ''), coalesce(to_char(f.confirmed_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS'), ''))))
          into r from artist_followers f where f.site = p_site and f.id > p_from and f.id <= p_to;
    elsif p_table = 'organizer_followers' then
        select jsonb_object_agg(f.id::text, md5(concat_ws('|', f.organizer_id::text, f.status, case when f.share_email then '1' else '0' end,
                   coalesce(to_char(f.confirmed_at at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS'), ''))))
          into r from organizer_followers f where f.site = p_site and f.id > p_from and f.id <= p_to;
    elsif p_table = 'artist_follow_proofs' then
        select jsonb_object_agg(p.id::text, md5(concat_ws('|', p.artist_id::text, p.revoke_reason)))
          into r from artist_follow_proofs p where p.site = p_site and p.id > p_from and p.id <= p_to;
    elsif p_table = 'short_links' then
        select jsonb_object_agg(l.id::text, md5(concat_ws('|', coalesce(l.organizer_id, 0)::text, l.code, case when l.archived then '1' else '0' end,
                   (select coalesce(sum(c.n), 0) from short_link_clicks c where c.site = l.site and c.link_id = l.id)::text)))
          into r from short_links l where l.site = p_site and l.id > p_from and l.id <= p_to;
    elsif p_table = 'resale_offers' then
        select jsonb_object_agg(o.id::text, md5(concat_ws('|', o.status, coalesce(o.price, 0)::numeric(10,2)::text, coalesce(o.new_ticket_id, 0)::text,
                   o.payout_status, coalesce(o.payout_amount, 0)::numeric(10,2)::text)))
          into r from resale_offers o where o.site = p_site and o.id > p_from and o.id <= p_to;
    elsif p_table = 'sponsor_logos' then
        -- Sponsoren (Migration 20261114140000, kann später kommen): plpgsql prüft die Tabelle erst beim Aufruf
        select jsonb_object_agg(s.id::text, md5(concat_ws('|', s.scope, s.owner_id::text, s.tier, s.name, case when s.active then '1' else '0' end,
                   (select coalesce(sum(c.n), 0) from sponsor_clicks c where c.site = s.site and c.sponsor_id = s.id)::text)))
          into r from sponsor_logos s where s.site = p_site and s.id > p_from and s.id <= p_to;
    else
        raise exception 'mirror_fingerprints: Tabelle % nicht erlaubt', p_table;
    end if;
    return coalesce(r, '{}'::jsonb);
end $$;

revoke all on function public.mirror_fingerprints(text, text, bigint, bigint) from public, anon, authenticated;
grant execute on function public.mirror_fingerprints(text, text, bigint, bigint) to service_role;

