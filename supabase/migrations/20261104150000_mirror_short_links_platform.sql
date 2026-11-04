-- ════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Kurzlinks (Statistik Stufe 2, D1) und Plattform-Sicht über alle Seiten (Stufe 4, D2)
-- Auftrag der Zentrale 2026-11-04 (uebergaben/auftraege-statistik-stufe2-2026-11-04.md), Plugin ab 1.38.635.
--  * short_links: tix_short_links ohne created_by. short_link_clicks: Tageszähler (Tag, Art link|qr, Anzahl),
--    nie einzelne Aufrufe. Was WordPress nach 400 Tagen löscht, fällt auch hier weg.
--  * v_campaigns: Kampagnen je Tag – Kurzlinks als Quelle „go-<code>“ (Klicks, QR, Käufe, Umsatz) und
--    UTM-Kampagnen ohne Kurzlink. Kauf über einen Link = link_code bzw. utm_id „go-<code>“ wie im Plugin.
--  * platform_*: Summen je Seite wie GET /platform/stats (ohne Gutschein-/Guthaben-Systemevents), für alle Seiten.
--    Der Website-Trichter fehlt bewusst: tix_web_stats wird nicht gespiegelt.
-- Views mit security_invoker, nur service_role. Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════

create table if not exists public.short_links (
    site          text not null,
    id            bigint not null,
    organizer_id  bigint,                         -- leer auf Einzel-Seiten
    event_id      bigint,                         -- leer = Ziel ist eine Seite (target)
    code          text not null,
    label         text not null default '',
    target        text not null default '',
    source        text not null default '',
    medium        text not null default '',
    campaign      text not null default '',
    archived      boolean not null default false,
    created_at    timestamptz,
    synced_at     timestamptz not null default now(),
    primary key (site, id)
);
create unique index if not exists short_links_code_idx on public.short_links (site, code);
create index if not exists short_links_org_idx on public.short_links (site, organizer_id);

create table if not exists public.short_link_clicks (
    site       text not null,
    link_id    bigint not null,
    day        date not null,                     -- Kalendertag der Seite (WordPress-Ortszeit)
    kind       text not null default 'link',      -- link | qr
    n          integer not null default 0,
    synced_at  timestamptz not null default now(),
    primary key (site, link_id, day, kind)
);
create index if not exists short_link_clicks_day_idx on public.short_link_clicks (site, day);

drop trigger if exists short_links_touch on public.short_links;
create trigger short_links_touch before insert or update on public.short_links for each row execute function public.mirror_touch();
drop trigger if exists short_links_log on public.short_links;
create trigger short_links_log after insert or update or delete on public.short_links for each row execute function public.mirror_log();
-- Zähler: kein Änderungsverlauf (jeder Klick wäre ein Eintrag), nur synced_at
drop trigger if exists short_link_clicks_touch on public.short_link_clicks;
create trigger short_link_clicks_touch before insert or update on public.short_link_clicks for each row execute function public.mirror_touch();
alter table public.short_links enable row level security;
alter table public.short_link_clicks enable row level security;
revoke all on public.short_links, public.short_link_clicks from anon, authenticated;
grant all on public.short_links, public.short_link_clicks to service_role;

-- Prüfsummen: zusätzlich short_links (Veranstalter, Code, Archiv, Summe der Klicks)
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
    else
        raise exception 'mirror_fingerprints: Tabelle % nicht erlaubt', p_table;
    end if;
    return coalesce(r, '{}'::jsonb);
end $$;

revoke all on function public.mirror_fingerprints(text, text, bigint, bigint) from public, anon, authenticated;
grant execute on function public.mirror_fingerprints(text, text, bigint, bigint) to service_role;

-- Verwaiste Kinder: zusätzlich Klickzähler ohne Link
create or replace function public.mirror_prune_orphans(p_site text)
returns jsonb
language plpgsql security definer set search_path = public as $$
declare g int; c int; i int; p int; k int;
begin
    delete from guests x where x.site = p_site and not exists (select 1 from events e where e.site = x.site and e.id = x.event_id);
    get diagnostics g = row_count;
    delete from ticket_categories x where x.site = p_site and not exists (select 1 from events e where e.site = x.site and e.id = x.event_id);
    get diagnostics c = row_count;
    delete from order_items x where x.site = p_site and not exists (select 1 from orders o where o.site = x.site and o.id = x.order_id);
    get diagnostics i = row_count;
    delete from payments x where x.site = p_site and not exists (select 1 from orders o where o.site = x.site and o.id = x.order_id);
    get diagnostics p = row_count;
    delete from short_link_clicks x where x.site = p_site and not exists (select 1 from short_links l where l.site = x.site and l.id = x.link_id);
    get diagnostics k = row_count;
    return jsonb_build_object('guests', g, 'ticket_categories', c, 'order_items', i, 'payments', p, 'short_link_clicks', k);
end $$;
revoke all on function public.mirror_prune_orphans(text) from public, anon, authenticated;
grant execute on function public.mirror_prune_orphans(text) to service_role;

-- ═══════════════ D1: Kampagnen ═══════════════

-- order_attribution um utm_id ergänzt (Spalte hinten angehängt, sonst unverändert)
create or replace view public.order_attribution with (security_invoker = true) as
select o.site,
       o.id                                                        as order_id,
       o.organizer_id,
       o.event_ids,
       o.created_at,
       (o.created_at at time zone 'Europe/Berlin')::date            as day,
       o.status,
       o.status in ('completed', 'processing')                     as paid,
       o.payment_method,
       o.total,
       o.refunded_total,
       case when o.status in ('completed', 'processing') then o.total - o.refunded_total else 0 end as revenue,
       coalesce(nullif(a->>'channel_key', ''),
                case when o.payment_method like 'pos\_%' then 'pos' else 'other' end) as channel,
       coalesce(nullif(lower(a->>'source'), ''), '')               as source,
       coalesce(nullif(lower(a->>'medium'), ''), '')               as medium,
       coalesce(a->>'campaign', '')                                as campaign,
       coalesce(a->>'content', '')                                 as content,
       coalesce(a->>'referrer_host', '')                           as referrer_host,
       coalesce(a->>'link_code', '')                               as link_code,
       nullif(a->>'promoter_id', '')::bigint                       as promoter_id,
       coalesce(a->>'order_source', '')                            as order_source,
       (a is not null)                                             as has_attribution,
       (select count(*) from public.tickets t where t.site = o.site and t.order_id = o.id) as tickets,
       coalesce(a->>'utm_id', '')                                  as utm_id   -- neu (Kurzlinks: „go-<code>“)
  from public.orders o
  left join lateral (select o.data->'attribution' as a) x on true;


-- Kampagnen je Tag: Kurzlinks („go-<code>“, Klicks/QR aus den Tageszählern, Käufe über link_code bzw. utm_id) und
-- UTM-Kampagnen ohne Kurzlink (Quelle/Medium/Kampagne aus der Bestellung). Käufe = bezahlte Bestellungen wie überall.
create or replace view public.v_campaigns with (security_invoker = true) as
with o as (
  select a.site, a.day, a.paid, a.revenue, a.refunded_total, a.tickets, a.organizer_id, a.source, a.medium, a.campaign, l.id as link_id
    from public.order_attribution a
    left join lateral (
      select k.id from public.short_links k
       where k.site = a.site
         and k.code in (nullif(a.link_code, ''), case when a.utm_id like 'go-%' then substr(a.utm_id, 4) end)
       order by (k.code = a.link_code) desc
       limit 1) l on true
   where l.id is not null or a.campaign <> ''
), x as (
  select site, day, link_id, null::bigint as organizer_id, '' as source, '' as medium, '' as campaign,
         0::bigint as clicks, 0::bigint as clicks_qr,
         count(*) filter (where paid) as orders, coalesce(sum(tickets) filter (where paid), 0) as tickets,
         coalesce(sum(revenue), 0) as revenue, coalesce(sum(refunded_total), 0) as refunds
    from o where link_id is not null
   group by site, day, link_id
  union all
  select site, day, null, organizer_id, source, medium, campaign, 0, 0,
         count(*) filter (where paid), coalesce(sum(tickets) filter (where paid), 0),
         coalesce(sum(revenue), 0), coalesce(sum(refunded_total), 0)
    from o where link_id is null
   group by site, day, organizer_id, source, medium, campaign
  union all
  select c.site, c.day, c.link_id, null, '', '', '',
         coalesce(sum(c.n) filter (where c.kind <> 'qr'), 0), coalesce(sum(c.n) filter (where c.kind = 'qr'), 0),
         0, 0, 0, 0
    from public.short_link_clicks c
    join public.short_links k on k.site = c.site and k.id = c.link_id
   group by c.site, c.day, c.link_id
)
select x.site,
       case when x.link_id is null then x.organizer_id else l.organizer_id end   as organizer_id,
       x.day,
       case when x.link_id is null then x.campaign else 'go-' || l.code end     as campaign_key,
       case when x.link_id is null then 'utm' else 'link' end                    as kind,
       x.link_id, l.code, l.label, l.event_id, coalesce(l.archived, false)       as archived,
       case when x.link_id is null then x.source else lower(l.source) end        as source,
       case when x.link_id is null then x.medium else lower(l.medium) end        as medium,
       case when x.link_id is null then x.campaign else l.campaign end           as campaign,
       sum(x.clicks) as clicks, sum(x.clicks_qr) as clicks_qr,
       sum(x.orders) as orders, sum(x.tickets) as tickets, sum(x.revenue) as revenue, sum(x.refunds) as refunds
  from x
  left join public.short_links l on l.site = x.site and l.id = x.link_id
 group by x.site, x.day, x.link_id, x.organizer_id, x.source, x.medium, x.campaign,
          l.organizer_id, l.code, l.label, l.event_id, l.archived, l.source, l.medium, l.campaign;

-- Gesamt je Kampagne (ohne Tage)
create or replace view public.v_campaigns_totals with (security_invoker = true) as
select site, organizer_id, campaign_key, kind, link_id, code, label, event_id, archived, source, medium, campaign,
       min(day) as first_day, max(day) as last_day,
       sum(clicks) as clicks, sum(clicks_qr) as clicks_qr,
       sum(orders) as orders, sum(tickets) as tickets, sum(revenue) as revenue, sum(refunds) as refunds
  from public.v_campaigns
 group by site, organizer_id, campaign_key, kind, link_id, code, label, event_id, archived, source, medium, campaign;

-- ═══════════════ D2: Plattform-Sicht über alle Seiten (wie GET /platform/stats) ═══════════════

-- Bestellungen ohne Gutschein-/Guthaben-Systemevents (wie TIX_Platform_Stats: _tix_system_giftcard_event/_wallet_event)
create or replace view public.platform_orders with (security_invoker = true) as
select a.*
  from public.order_attribution a
 where exists (select 1 from unnest(a.event_ids) eid
                 join public.events e on e.site = a.site and e.id = eid
                where coalesce(e.data->>'_tix_system_giftcard_event', '') <> '1'
                  and coalesce(e.data->>'_tix_system_wallet_event', '') <> '1');

-- Summen je Seite und Tag
create or replace view public.platform_daily with (security_invoker = true) as
select site, day,
       count(*) filter (where paid)                  as orders,
       coalesce(sum(tickets) filter (where paid), 0) as tickets,
       coalesce(sum(revenue), 0)                     as revenue,
       coalesce(sum(refunded_total), 0)              as refunds,
       count(*)                                      as orders_all
  from public.platform_orders
 group by site, day;

-- Je Seite und Monat: Summen, verkaufende Veranstalter/Events, neue Veranstalter; aktive = heute veröffentlichte
create or replace view public.platform_monthly with (security_invoker = true) as
with s as (
  select site, date_trunc('month', day)::date as month,
         count(*) filter (where paid)                  as orders,
         coalesce(sum(tickets) filter (where paid), 0) as tickets,
         coalesce(sum(revenue), 0)                     as revenue,
         coalesce(sum(refunded_total), 0)              as refunds,
         count(distinct organizer_id) filter (where paid and organizer_id is not null) as organizers_selling
    from public.platform_orders
   group by 1, 2
), ev as (
  select p.site, date_trunc('month', p.day)::date as month, count(distinct eid) as events_selling
    from public.platform_orders p
    cross join lateral unnest(p.event_ids) eid
    join public.events e on e.site = p.site and e.id = eid
   where p.paid
     and coalesce(e.data->>'_tix_system_giftcard_event', '') <> '1'
     and coalesce(e.data->>'_tix_system_wallet_event', '') <> '1'
   group by 1, 2
), org as (
  select site, date_trunc('month', (created_at at time zone 'Europe/Berlin'))::date as month, count(*) as organizers_new
    from public.organizers
   where status = 'publish' and created_at is not null
   group by 1, 2
), act as (
  select site, count(*) as organizers_active from public.organizers where status = 'publish' group by site
), k as (
  select site, month from s union select site, month from org
)
select k.site, k.month,
       coalesce(s.orders, 0) as orders, coalesce(s.tickets, 0) as tickets,
       coalesce(s.revenue, 0) as revenue, coalesce(s.refunds, 0) as refunds,
       coalesce(s.organizers_selling, 0) as organizers_selling,
       coalesce(ev.events_selling, 0)    as events_selling,
       coalesce(org.organizers_new, 0)   as organizers_new,
       coalesce(act.organizers_active, 0) as organizers_active
  from k
  left join s   on s.site = k.site and s.month = k.month
  left join ev  on ev.site = k.site and ev.month = k.month
  left join org on org.site = k.site and org.month = k.month
  left join act on act.site = k.site;

-- Veranstalter je Seite und Monat mit Rang (Top-Liste = rank <= 10); nur Veranstalter mit bezahlten Bestellungen
create or replace view public.platform_organizers_monthly with (security_invoker = true) as
with m as (
  select p.site, date_trunc('month', p.day)::date as month, p.organizer_id,
         count(*) filter (where p.paid) as orders,
         coalesce(sum(p.tickets) filter (where p.paid), 0) as tickets,
         coalesce(sum(p.revenue), 0) as revenue
    from public.platform_orders p
   where p.organizer_id is not null
   group by 1, 2, 3
)
select m.site, m.month, m.organizer_id, coalesce(g.name, '') as name, m.orders, m.tickets, m.revenue,
       row_number() over (partition by m.site, m.month order by m.revenue desc, m.tickets desc, m.organizer_id) as rank
  from m
  left join public.organizers g on g.site = m.site and g.id = m.organizer_id
 where m.orders > 0;

-- Quellen der bezahlten Bestellungen je Seite und Monat (Quelle, sonst Kanal, sonst „direct“);
-- Gruppen unter 3 Bestellungen werden zu „sonstige“ zusammengefasst (außer direct), wie im Plugin
create or replace view public.platform_channels_monthly with (security_invoker = true) as
with m as (
  select site, date_trunc('month', day)::date as month,
         coalesce(nullif(source, ''), nullif(channel, ''), 'direct') as source,
         count(*) as orders, coalesce(sum(revenue), 0) as revenue, coalesce(sum(tickets), 0) as tickets
    from public.platform_orders
   where paid
   group by 1, 2, 3
)
select site, month,
       case when orders >= 3 or source = 'direct' then source else 'sonstige' end as source,
       sum(orders) as orders, sum(tickets) as tickets, sum(revenue) as revenue
  from m
 group by 1, 2, 3;

-- Rechte: nur service_role
revoke all on public.order_attribution, public.v_campaigns, public.v_campaigns_totals, public.platform_orders,
              public.platform_daily, public.platform_monthly, public.platform_organizers_monthly,
              public.platform_channels_monthly from anon, authenticated, public;
grant select on public.order_attribution, public.v_campaigns, public.v_campaigns_totals, public.platform_orders,
               public.platform_daily, public.platform_monthly, public.platform_organizers_monthly,
               public.platform_channels_monthly to service_role;
