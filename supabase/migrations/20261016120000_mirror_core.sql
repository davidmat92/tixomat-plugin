-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Kern (Paket 1)
--
-- WordPress/Tixomat ist führend; diese Datenbank ist eine Kopie aller Seiten.
-- Jede Tabelle trägt die Spalte site (z. B. 'kitchenklub.de'), Schlüssel ist (site, id).
-- Geschrieben wird nur vom Plugin mit dem Service-Schlüssel (umgeht RLS).
-- RLS ist überall an, ohne Policies: anon und authenticated sehen nichts.
-- Keine Fremdschlüssel: Der Spiegel nimmt Zeilen in beliebiger Reihenfolge an.
-- change_log hält jede tatsächliche Änderung fest (für Wiederherstellung auf einen Stand).
-- ════════════════════════════════════════════════════════════════════════════

-- ── Seiten ──
create table if not exists public.sites (
    site             text primary key,
    name             text not null default '',
    url              text not null default '',
    plugin_version   text not null default '',
    multi_organizer  boolean not null default false,
    timezone         text not null default '',
    data             jsonb not null default '{}'::jsonb,
    last_seen        timestamptz,
    synced_at        timestamptz not null default now()
);

-- ── Events ──
create table if not exists public.events (
    site             text not null,
    id               bigint not null,
    title            text not null default '',
    slug             text not null default '',
    status           text not null default '',
    starts_at        timestamptz,
    date_start       text not null default '',
    date_end         text not null default '',
    time_start       text not null default '',
    time_end         text not null default '',
    time_doors       text not null default '',
    location_id      bigint,
    location_name    text not null default '',
    address          text not null default '',
    organizer_id     bigint,
    co_organizer_id  bigint,
    tickets_enabled  boolean not null default false,
    url              text not null default '',
    created_at       timestamptz,
    modified_at      timestamptz,
    data             jsonb not null default '{}'::jsonb,
    synced_at        timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists events_starts_idx on public.events (site, starts_at);
create index if not exists events_org_idx on public.events (site, organizer_id);

-- ── Ticket-Kategorien (id = "<event_id>:<index>", der Index ist die Position im Event) ──
create table if not exists public.ticket_categories (
    site             text not null,
    id               text not null,
    event_id         bigint not null,
    idx              integer not null,
    name             text not null default '',
    price            numeric(12,2),
    sale_price       numeric(12,2),
    qty              integer,
    stock            integer,
    online           boolean not null default true,
    hidden           boolean not null default false,
    data             jsonb not null default '{}'::jsonb,
    synced_at        timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists ticket_categories_event_idx on public.ticket_categories (site, event_id);

-- ── Kunden (WordPress-Konten; ohne Passwörter, Tokens) ──
create table if not exists public.customers (
    site               text not null,
    id                 bigint not null,
    email              text not null default '',
    first_name         text not null default '',
    last_name          text not null default '',
    display_name       text not null default '',
    roles              text[] not null default '{}',
    registered_at      timestamptz,
    phone              text not null default '',
    billing            jsonb not null default '{}'::jsonb,
    email_confirmed    boolean not null default false,
    team_organizer_id  bigint,
    newsletter         boolean not null default false,
    data               jsonb not null default '{}'::jsonb,
    synced_at          timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists customers_email_idx on public.customers (lower(email));

-- ── Bestellungen ──
create table if not exists public.orders (
    site                  text not null,
    id                    bigint not null,
    order_number          text not null default '',
    status                text not null default '',
    total                 numeric(12,2) not null default 0,
    subtotal              numeric(12,2) not null default 0,
    tax                   numeric(12,2) not null default 0,
    discount              numeric(12,2) not null default 0,
    refunded_total        numeric(12,2) not null default 0,
    payment_method        text not null default '',
    payment_method_title  text not null default '',
    billing_first_name    text not null default '',
    billing_last_name     text not null default '',
    billing_email         text not null default '',
    billing_phone         text not null default '',
    billing_company       text not null default '',
    billing_address_1     text not null default '',
    billing_address_2     text not null default '',
    billing_city          text not null default '',
    billing_postcode      text not null default '',
    billing_country       text not null default '',
    customer_id           bigint,
    organizer_id          bigint,
    event_ids             bigint[] not null default '{}',
    checked_in            boolean not null default false,
    newsletter_optin      boolean not null default false,
    coupon                text not null default '',
    created_at            timestamptz,
    modified_at           timestamptz,
    data                  jsonb not null default '{}'::jsonb,
    synced_at             timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists orders_email_idx on public.orders (lower(billing_email));
create index if not exists orders_customer_idx on public.orders (site, customer_id);
create index if not exists orders_created_idx on public.orders (site, created_at);
create index if not exists orders_events_idx on public.orders using gin (event_ids);

create table if not exists public.order_items (
    site        text not null,
    id          bigint not null,
    order_id    bigint not null,
    product_id  bigint,
    event_id    bigint,
    quantity    integer not null default 1,
    total       numeric(12,2) not null default 0,
    tax         numeric(12,2) not null default 0,
    name        text not null default '',
    cat_name    text not null default '',
    meta        jsonb not null default '{}'::jsonb,
    synced_at   timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists order_items_order_idx on public.order_items (site, order_id);
create index if not exists order_items_event_idx on public.order_items (site, event_id);

-- ── Zahlungen (je Bestellung eine Zeile; nur Status, Betrag, Anbieter-Referenz) ──
create table if not exists public.payments (
    site            text not null,
    id              bigint not null,
    order_id        bigint not null,
    provider        text not null default '',
    method_title    text not null default '',
    status          text not null default '',
    amount          numeric(12,2) not null default 0,
    refunded_total  numeric(12,2) not null default 0,
    provider_ref    text not null default '',
    fee             numeric(12,2),
    fee_gross       numeric(12,2),
    fee_net         numeric(12,2),
    data            jsonb not null default '{}'::jsonb,
    synced_at       timestamptz not null default now(),
    primary key (site, id)
);

-- ── Tickets ──
create table if not exists public.tickets (
    site              text not null,
    id                bigint not null,
    code              text not null default '',
    order_id          bigint,
    event_id          bigint,
    event_name        text not null default '',
    status            text not null default '',
    post_status       text not null default '',
    owner_email       text not null default '',
    owner_name        text not null default '',
    assigned_name     text not null default '',
    assigned_user_id  bigint,
    customer_id       bigint,
    cat_name          text not null default '',
    cat_index         integer,
    price             numeric(12,2),
    checked_in        boolean not null default false,
    checkin_at        timestamptz,
    checkin_by        text not null default '',
    seat_id           text not null default '',
    ticket_type       text not null default '',
    is_mirror         boolean not null default false,
    source_site       text not null default '',
    created_at        timestamptz,
    modified_at       timestamptz,
    data              jsonb not null default '{}'::jsonb,
    synced_at         timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists tickets_code_idx on public.tickets (site, code);
create index if not exists tickets_event_idx on public.tickets (site, event_id);
create index if not exists tickets_order_idx on public.tickets (site, order_id);
create index if not exists tickets_owner_idx on public.tickets (lower(owner_email));

-- ── Check-in-Verlauf (WordPress hält nur den letzten Stand; hier jeder Vorgang) ──
create table if not exists public.checkins (
    site         text not null,
    id           text not null,
    ticket_id    bigint not null,
    ticket_code  text not null default '',
    event_id     bigint,
    action       text not null,            -- in | reset
    at           timestamptz not null,
    by           text not null default '',
    data         jsonb not null default '{}'::jsonb,
    synced_at    timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists checkins_ticket_idx on public.checkins (site, ticket_id);
create index if not exists checkins_event_idx on public.checkins (site, event_id, at);

-- ── Stornos, Erstattungen, Rückbuchungen (jeder Vorgang) ──
create table if not exists public.refunds (
    site            text not null,
    id              text not null,
    order_id        bigint not null,
    kind            text not null,         -- refund | cancel | chargeback
    amount          numeric(12,2),
    is_full         boolean,
    reason          text not null default '',
    gateway         text not null default '',
    source          text not null default '',
    admin_user      text not null default '',
    total_refunded  numeric(12,2),
    at              timestamptz not null,
    data            jsonb not null default '{}'::jsonb,
    synced_at       timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists refunds_order_idx on public.refunds (site, order_id);

-- ── Änderungsprotokoll ──
create table if not exists public.change_log (
    id           bigserial primary key,
    site         text not null,
    entity       text not null,           -- Tabellenname
    object_id    text not null,
    op           text not null,           -- insert | update | delete
    customer_id  bigint,                  -- für den Löschweg
    email        text,                    -- für den Löschweg (klein geschrieben)
    data         jsonb,                   -- Zeile nach der Änderung (bei delete null)
    changed_at   timestamptz not null default now()
);
create index if not exists change_log_object_idx on public.change_log (site, entity, object_id, changed_at);
create index if not exists change_log_customer_idx on public.change_log (site, customer_id) where customer_id is not null;
create index if not exists change_log_email_idx on public.change_log (email) where email is not null;
create index if not exists change_log_time_idx on public.change_log (changed_at);

-- ════════════════ Trigger ════════════════

-- synced_at bei jedem Schreiben setzen
create or replace function public.mirror_touch() returns trigger
language plpgsql set search_path = public as $$
begin
    new.synced_at := now();
    return new;
end $$;

-- Jede tatsächliche Änderung protokollieren (ein erneutes Senden desselben Stands erzeugt nichts)
create or replace function public.mirror_log() returns trigger
language plpgsql security definer set search_path = public as $$
declare
    j jsonb;
    o jsonb;
    cid bigint;
    mail text;
begin
    if tg_op = 'DELETE' then
        o := to_jsonb(old);
        insert into change_log (site, entity, object_id, op, customer_id, email, data)
        values (old.site, tg_table_name, coalesce(o->>'id', o->>'site'), 'delete', null, null, null);
        return old;
    end if;
    j := to_jsonb(new) - 'synced_at';
    -- last_seen der Seite zählt nicht als Änderung
    if tg_op = 'UPDATE' and (j - 'last_seen') = (to_jsonb(old) - 'synced_at' - 'last_seen') then
        return new;
    end if;
    if tg_table_name = 'customers' then
        cid := (j->>'id')::bigint;
    elsif j ? 'customer_id' and j->>'customer_id' is not null then
        cid := (j->>'customer_id')::bigint;
    end if;
    mail := nullif(lower(coalesce(j->>'email', j->>'billing_email', j->>'owner_email', '')), '');
    insert into change_log (site, entity, object_id, op, customer_id, email, data)
    values (new.site, tg_table_name, coalesce(j->>'id', j->>'site'), lower(tg_op), cid, mail, j);
    return new;
end $$;

do $$
declare t text;
begin
    foreach t in array array['sites','events','ticket_categories','customers','orders','order_items','payments','tickets','checkins','refunds'] loop
        execute format('drop trigger if exists %I_touch on public.%I', t, t);
        execute format('create trigger %I_touch before insert or update on public.%I for each row execute function public.mirror_touch()', t, t);
        execute format('drop trigger if exists %I_log on public.%I', t, t);
        execute format('create trigger %I_log after insert or update or delete on public.%I for each row execute function public.mirror_log()', t, t);
        execute format('alter table public.%I enable row level security', t);
        execute format('revoke all on public.%I from anon, authenticated', t);
    end loop;
end $$;
alter table public.change_log enable row level security;
revoke all on public.change_log from anon, authenticated;
revoke all on sequence public.change_log_id_seq from anon, authenticated;

-- ════════════════ Funktionen für das Plugin ════════════════

-- Löschweg „Konto löschen“: Profil löschen und Verlauf mit Personenbezug tilgen.
-- Bestellungen und Tickets selbst folgen WordPress (dort anonymisiert bzw. aus
-- Aufbewahrungsgründen behalten) über die normalen Upserts.
create or replace function public.mirror_forget(p_site text, p_customer_id bigint, p_email text)
returns jsonb
language plpgsql security definer set search_path = public as $$
declare
    n_customers int;
    n_log int;
    mail text := nullif(lower(trim(coalesce(p_email, ''))), '');
begin
    delete from customers where site = p_site and id = p_customer_id;
    get diagnostics n_customers = row_count;
    delete from change_log
     where site = p_site
       and ((p_customer_id > 0 and customer_id = p_customer_id)
            or (entity = 'customers' and object_id = p_customer_id::text)
            or (mail is not null and email = mail));
    get diagnostics n_log = row_count;
    return jsonb_build_object('customers', n_customers, 'change_log', n_log);
end $$;

-- Kinder entfernen, die es in WordPress nicht mehr gibt (gebündelt je Runde).
-- p_sets: [{"parent": 12, "keep": [1,2]}, …]
create or replace function public.mirror_prune(p_site text, p_table text, p_sets jsonb)
returns integer
language plpgsql security definer set search_path = public as $$
declare n int := 0; m int;
begin
    if p_table = 'order_items' then
        delete from order_items t
         using jsonb_array_elements(p_sets) s
         where t.site = p_site and t.order_id = (s->>'parent')::bigint
           and not (t.id = any (coalesce(array(select jsonb_array_elements_text(s->'keep'))::bigint[], '{}')));
        get diagnostics m = row_count; n := n + m;
    elsif p_table = 'ticket_categories' then
        delete from ticket_categories t
         using jsonb_array_elements(p_sets) s
         where t.site = p_site and t.event_id = (s->>'parent')::bigint
           and not (t.idx = any (coalesce(array(select jsonb_array_elements_text(s->'keep'))::int[], '{}')));
        get diagnostics m = row_count; n := n + m;
    else
        raise exception 'mirror_prune: Tabelle % nicht erlaubt', p_table;
    end if;
    return n;
end $$;

-- Verlauf kürzen (Aufbewahrung; Aufruf aus dem täglichen Abgleich, Paket 2).
create or replace function public.mirror_prune_change_log(p_days integer)
returns integer
language plpgsql security definer set search_path = public as $$
declare n int;
begin
    if p_days < 30 then raise exception 'mindestens 30 Tage'; end if;
    delete from change_log where changed_at < now() - make_interval(days => p_days);
    get diagnostics n = row_count;
    return n;
end $$;

revoke all on function public.mirror_forget(text, bigint, text) from public, anon, authenticated;
revoke all on function public.mirror_prune(text, text, jsonb) from public, anon, authenticated;
revoke all on function public.mirror_prune_change_log(integer) from public, anon, authenticated;
grant execute on function public.mirror_forget(text, bigint, text) to service_role;
grant execute on function public.mirror_prune(text, text, jsonb) to service_role;
grant execute on function public.mirror_prune_change_log(integer) to service_role;
grant all on all tables in schema public to service_role;
grant all on all sequences in schema public to service_role;
