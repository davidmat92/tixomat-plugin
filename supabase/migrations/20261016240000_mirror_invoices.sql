-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Rechnungs-Kern (1.38.485)
-- invoices: Belege R/RK/B/BK (in WordPress nie geändert oder gelöscht), Schnappschuss in data,
-- PDF nur als Pfad + SHA-256 (die Datei selbst wird nicht gespiegelt). Aufbewahrung 10 Jahre,
-- auch nach „Konto löschen“: der Löschweg fasst Belege nicht an.
-- invoice_counters: Nummernkreise je Aussteller/Serie/Jahr (id = "<issuer_key>|<series>|<year>").
-- Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════════

create table if not exists public.invoices (
    site             text not null,
    id               bigint not null,
    number           text not null default '',
    kind             text not null default '',
    series           text not null default '',
    issuer_key       text not null default '',
    organizer_id     bigint not null default 0,
    code             text not null default '',
    year             integer,
    seq              integer,
    order_id         bigint,
    order_number     text not null default '',
    ref_invoice_id   bigint,
    reason           text not null default '',
    currency         text not null default 'EUR',
    net              numeric(12,2),
    tax              numeric(12,2),
    gross            numeric(12,2),
    small_business   boolean not null default false,
    recipient_name   text not null default '',
    recipient_email  text not null default '',
    service_date     date,
    data             jsonb not null default '{}'::jsonb,
    pdf_path         text not null default '',
    pdf_sha256       text not null default '',
    created_at       timestamptz,
    synced_at        timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists invoices_order_idx on public.invoices (site, order_id);
create index if not exists invoices_number_idx on public.invoices (site, number);
create index if not exists invoices_issuer_idx on public.invoices (site, issuer_key, created_at);

create table if not exists public.invoice_counters (
    site        text not null,
    id          text not null,
    issuer_key  text not null,
    series      text not null,
    year        integer not null,
    last        integer not null default 0,
    synced_at   timestamptz not null default now(),
    primary key (site, id)
);

do $$
declare t text;
begin
    foreach t in array array['invoices','invoice_counters'] loop
        execute format('drop trigger if exists %I_touch on public.%I', t, t);
        execute format('create trigger %I_touch before insert or update on public.%I for each row execute function public.mirror_touch()', t, t);
        execute format('drop trigger if exists %I_log on public.%I', t, t);
        execute format('create trigger %I_log after insert or update or delete on public.%I for each row execute function public.mirror_log()', t, t);
        execute format('alter table public.%I enable row level security', t);
        execute format('revoke all on public.%I from anon, authenticated', t);
        execute format('grant all on public.%I to service_role', t);
    end loop;
end $$;

-- Prüfsummen (wie Paket 2, zusätzlich invoices: Nummer, Art, Brutto, PDF-Prüfsumme)
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
        select jsonb_object_agg(v.id::text, md5(concat_ws('|', v.number, v.kind, v.gross::text, v.pdf_sha256)))
          into r from invoices v where v.site = p_site and v.id > p_from and v.id <= p_to;
    else
        raise exception 'mirror_fingerprints: Tabelle % nicht erlaubt', p_table;
    end if;
    return coalesce(r, '{}'::jsonb);
end $$;

revoke all on function public.mirror_fingerprints(text, text, bigint, bigint) from public, anon, authenticated;
grant execute on function public.mirror_fingerprints(text, text, bigint, bigint) to service_role;
