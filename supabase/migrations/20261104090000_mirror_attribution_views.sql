-- ════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Auswertung Herkunft (Statistik Stufe 1, Plan docs/plan-statistik-marketing.md)
-- Grundlage: orders.data.attribution (seit Plugin 1.38.618: channel_key, source, medium, campaign, content,
-- referrer_host, link_code, promoter_id, order_source, consent_marketing – ohne IP und Kennungen).
-- Nur Views, keine neuen Daten. security_invoker: es gelten die RLS-Regeln der Grundtabellen (anon/authenticated 0).
-- Regeln wie TIX_Studio_Stats / TIX_Sales_Figures:
--   bezahlt = completed | processing; Umsatz = total − refunded_total der bezahlten Bestellungen,
--   Erstattungen = refunded_total aller Bestellungen (auch refunded).
--   Kanal = channel_key; fehlt er (Bestellung ohne Herkunftsangaben), wie channel_of(): pos_* → pos, sonst other.
--   Tag = Kalendertag Europe/Berlin.
-- Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════

-- 1) Je Bestellung: Herkunft flach
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
       (select count(*) from public.tickets t where t.site = o.site and t.order_id = o.id) as tickets
  from public.orders o
  left join lateral (select o.data->'attribution' as a) x on true;

-- 2) Je Tag, Seite, Veranstalter und Herkunft (Veranstalter- und Seiten-Sicht)
create or replace view public.attribution_daily with (security_invoker = true) as
select site, organizer_id, day, channel, source, medium, campaign,
       count(*) filter (where paid)                 as orders,
       coalesce(sum(tickets) filter (where paid), 0) as tickets,
       coalesce(sum(revenue), 0)                    as revenue,
       coalesce(sum(refunded_total), 0)             as refunds,
       count(*)                                     as orders_all
  from public.order_attribution
 group by site, organizer_id, day, channel, source, medium, campaign;

-- 3) Gesamt je Seite, Veranstalter und Kampagne (ohne Tage; für Übersichten „je Quelle/Kanal/Kampagne“)
create or replace view public.attribution_totals with (security_invoker = true) as
select site, organizer_id, channel, source, medium, campaign,
       min(day) as first_day, max(day) as last_day,
       sum(orders) as orders, sum(tickets) as tickets, sum(revenue) as revenue, sum(refunds) as refunds,
       sum(orders_all) as orders_all
  from public.attribution_daily
 group by site, organizer_id, channel, source, medium, campaign;

-- 4) Plattform-Sicht (F6: zuerst evendis.de, später alle Seiten): ohne Veranstalter, nur Summen.
--    Kampagnen mit weniger als 3 bezahlten Bestellungen je Seite und Monat (über alle Kanäle/Quellen) werden zu „(sonstige)“ zusammengefasst.
create or replace view public.attribution_platform_monthly with (security_invoker = true) as
with m as (
  select site, date_trunc('month', day)::date as month, channel, source, medium, campaign,
         sum(orders) as orders, sum(tickets) as tickets, sum(revenue) as revenue, sum(refunds) as refunds,
         sum(orders_all) as orders_all,
         sum(sum(orders)) over (partition by site, date_trunc('month', day)::date, campaign) as campaign_orders
    from public.attribution_daily
   group by 1, 2, 3, 4, 5, 6
)
select site, month, channel, source, medium,
       case when campaign = '' or campaign_orders >= 3 then campaign else '(sonstige)' end as campaign,
       sum(orders) as orders, sum(tickets) as tickets, sum(revenue) as revenue, sum(refunds) as refunds,
       sum(orders_all) as orders_all
  from m
 group by 1, 2, 3, 4, 5, 6;

-- Rechte: nur service_role (Supabase gibt neuen Objekten in public sonst anon/authenticated)
revoke all on public.order_attribution, public.attribution_daily, public.attribution_totals,
              public.attribution_platform_monthly from anon, authenticated, public;
grant select on public.order_attribution, public.attribution_daily, public.attribution_totals,
               public.attribution_platform_monthly to service_role;

-- Abfragen je Seite/Veranstalter/Tag
create index if not exists orders_site_org_created_idx on public.orders (site, organizer_id, created_at);
