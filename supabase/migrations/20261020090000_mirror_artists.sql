-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Künstler (1.38.517, nur evendis, Beitragstyp tix_artist)
-- Profil aus Meta _tix_artist (ohne Protokoll _tix_artist_log mit Nutzer-IDs). Die Zuordnung zu Events
-- steht in events.data._tix_artists. Künstler eines Sandbox-Veranstalters (managed_by) werden nicht gespiegelt.
-- Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════════

create table if not exists public.artists (
    site             text not null,
    id               bigint not null,
    name             text not null default '',
    slug             text not null default '',
    post_status      text not null default '',
    status           text not null default '',       -- draft | public | blocked
    kind             text not null default '',
    verified         boolean not null default false,
    managed_by_type  text not null default '',       -- organizer | evendis
    managed_by_id    bigint,
    duplicate_of     bigint,
    merged_into      bigint,
    spotify_id       text not null default '',
    apple_id         text not null default '',
    created_at       timestamptz,
    modified_at      timestamptz,
    data             jsonb not null default '{}'::jsonb,
    synced_at        timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists artists_slug_idx on public.artists (site, slug);
create index if not exists artists_managed_idx on public.artists (site, managed_by_id);

drop trigger if exists artists_touch on public.artists;
create trigger artists_touch before insert or update on public.artists for each row execute function public.mirror_touch();
drop trigger if exists artists_log on public.artists;
create trigger artists_log after insert or update or delete on public.artists for each row execute function public.mirror_log();
alter table public.artists enable row level security;
revoke all on public.artists from anon, authenticated;
grant all on public.artists to service_role;

-- Prüfsummen: zusätzlich artists (Post-Status, Name, Status)
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
    else
        raise exception 'mirror_fingerprints: Tabelle % nicht erlaubt', p_table;
    end if;
    return coalesce(r, '{}'::jsonb);
end $$;

revoke all on function public.mirror_fingerprints(text, text, bigint, bigint) from public, anon, authenticated;
grant execute on function public.mirror_fingerprints(text, text, bigint, bigint) to service_role;
