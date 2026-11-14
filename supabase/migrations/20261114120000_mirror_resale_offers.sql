-- ════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Zweitmarkt (Weiterverkauf, RS7, Plugin §223)
-- Spiegel von {prefix}tix_resale_offers. Ohne E-Mail des Verkäufers (Konto-ID und Bestellungen genügen).
-- Zustände: open | locked | sold | withdrawn | expired; Auszahlung original | wallet | voucher, pending | paid | failed.
-- Das alte Ticket steht in tickets mit status = 'resold', das neue mit eigener Bestellung (buyer_order_id).
-- Vor dem Plugin-Rollout einspielen (sonst Fehler im Postausgang für resale_offers). Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════

create table if not exists public.resale_offers (
    site             text not null,
    id               bigint not null,
    event_id         bigint,
    cat_index        integer not null default -1,
    organizer_id     bigint,                       -- leer auf Einzel-Seiten
    ticket_id        bigint,                       -- altes Ticket (Verkäufer)
    new_ticket_id    bigint,                       -- neues Ticket (Käufer), leer bis verkauft
    seller_user_id   bigint,
    seller_order_id  bigint,
    buyer_order_id   bigint,
    channel          text not null default '',     -- app | web | partner
    price            numeric(10,2),
    original_price   numeric(10,2),
    buyer_fee        numeric(10,2),
    status           text not null default 'open',
    close_reason     text not null default '',
    payout_method    text not null default '',
    payout_status    text not null default '',
    payout_amount    numeric(10,2),
    created_at       timestamptz,
    sold_at          timestamptz,
    closed_at        timestamptz,
    modified_at      timestamptz,
    synced_at        timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists resale_offers_event_idx on public.resale_offers (site, event_id, status);
create index if not exists resale_offers_ticket_idx on public.resale_offers (site, ticket_id);

drop trigger if exists resale_offers_touch on public.resale_offers;
create trigger resale_offers_touch before insert or update on public.resale_offers for each row execute function public.mirror_touch();
drop trigger if exists resale_offers_log on public.resale_offers;
create trigger resale_offers_log after insert or update or delete on public.resale_offers for each row execute function public.mirror_log();
alter table public.resale_offers enable row level security;
revoke all on public.resale_offers from anon, authenticated;
grant all on public.resale_offers to service_role;
