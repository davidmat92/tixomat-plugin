-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Paket 2
-- Veranstalter, Gästelisten, Prämien (Stempelkarte), Gutscheine/Geschenkkarten,
-- Guthaben (Geldbörsen + Buchungen), Abrechnungen; täglicher Abgleich mit Bericht,
-- Aufbewahrung des Verlaufs je Seite.
-- Wiederholbar. Voraussetzung: 20261016120000_mirror_core.sql
-- IBAN steht nie im Klartext im Spiegel: nur maskiert und verschlüsselt
-- (m1: = Spiegel-Schlüssel TIX_MIRROR_ENC_KEY, v1: = Schlüssel der Seite).
-- ════════════════════════════════════════════════════════════════════════════

-- ── Veranstalter ──
create table if not exists public.organizers (
    site          text not null,
    id            bigint not null,
    name          text not null default '',
    status        text not null default '',
    approval      text not null default '',
    user_id       bigint,
    email         text not null default '',
    phone         text not null default '',
    city          text not null default '',
    payout        jsonb not null default '{}'::jsonb,   -- Inhaber, BIC, Rechnungsadresse, Steuer, iban_masked, iban_enc
    created_at    timestamptz,
    modified_at   timestamptz,
    data          jsonb not null default '{}'::jsonb,
    synced_at     timestamptz not null default now(),
    primary key (site, id)
);

-- ── Gästelisten (id = "<event>:<gast-id>", "<event>:d<index>" Dashboard-Liste, "<event>:t<index>" Liste je Ticket) ──
create table if not exists public.guests (
    site              text not null,
    id                text not null,
    event_id          bigint not null,
    source            text not null default 'guest_list',   -- guest_list | dashboard | tickets
    name              text not null default '',
    email             text not null default '',
    plus              integer not null default 0,
    note              text not null default '',
    checked_in        boolean not null default false,
    checked_in_count  integer not null default 0,
    checkin_time      text not null default '',
    checkin_by        text not null default '',
    data              jsonb not null default '{}'::jsonb,
    synced_at         timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists guests_event_idx on public.guests (site, event_id);
create index if not exists guests_email_idx on public.guests (lower(email));

-- ── Prämien / Stempelkarte (id = "<user>:<organizer>", organizer 0 = ganze Seite) ──
create table if not exists public.loyalty (
    site          text not null,
    id            text not null,
    user_id       bigint not null,
    organizer_id  bigint not null default 0,
    points        integer not null default 0,
    log           jsonb not null default '[]'::jsonb,
    stamped       jsonb not null default '[]'::jsonb,
    synced_at     timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists loyalty_user_idx on public.loyalty (site, user_id);

-- ── Gutscheine und Geschenkkarten (id = Code) ──
create table if not exists public.coupons (
    site           text not null,
    id             text not null,
    discount_type  text not null default '',
    value          numeric(12,2),
    balance        numeric(12,2),
    used           integer not null default 0,
    max_uses       integer,
    expires        text not null default '',
    organizer_id   bigint,
    order_id       bigint,
    ticket_id      bigint,
    data           jsonb not null default '{}'::jsonb,
    synced_at      timestamptz not null default now(),
    primary key (site, id)
);

-- ── Guthaben: Geldbörsen (Beträge in Cent) ──
create table if not exists public.wallets (
    site           text not null,
    id             bigint not null,
    user_id        bigint not null,           -- negativ = Konto gelöscht (anonymisiert)
    scope_type     text not null default '',
    organizer_id   bigint not null default 0,
    balance_paid   bigint not null default 0,
    balance_bonus  bigint not null default 0,
    status         text not null default '',
    created_at     timestamptz,
    updated_at     timestamptz,
    synced_at      timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists wallets_user_idx on public.wallets (site, user_id);

-- ── Guthaben: Buchungen (nur anhängen, Beträge in Cent) ──
create table if not exists public.wallet_transactions (
    site                 text not null,
    id                   bigint not null,
    wallet_id            bigint not null,
    type                 text not null default '',
    amount_paid          bigint not null default 0,
    amount_bonus         bigint not null default 0,
    balance_after_paid   bigint not null default 0,
    balance_after_bonus  bigint not null default 0,
    order_id             bigint,
    ref_tx_id            bigint,
    staff_user_id        bigint,
    station              text not null default '',
    device_id            bigint,
    channel              text not null default '',
    idempotency_key      text not null default '',
    meta                 jsonb not null default '{}'::jsonb,
    created_at           timestamptz,
    synced_at            timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists wallet_tx_wallet_idx on public.wallet_transactions (site, wallet_id);
create index if not exists wallet_tx_order_idx on public.wallet_transactions (site, order_id);

-- ── Abrechnungen ──
create table if not exists public.settlements (
    site            text not null,
    id              bigint not null,
    number          text not null default '',
    type            text not null default '',
    organizer_id    bigint,
    event_id        bigint,
    status          text not null default '',
    gross           numeric(12,2),
    refunds         numeric(12,2),
    platform_fee    numeric(12,2),
    gateway_fees    numeric(12,2),
    payout_amount   numeric(12,2),
    due_date        text not null default '',
    invoice_number  text not null default '',
    paid_at         text not null default '',
    created_at      timestamptz,
    updated_at      timestamptz,
    data            jsonb not null default '{}'::jsonb,       -- alle Spalten; snapshot ohne Klartext-IBAN
    synced_at       timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists settlements_org_idx on public.settlements (site, organizer_id);

create table if not exists public.settlement_items (
    site           text not null,
    id             bigint not null,
    settlement_id  bigint not null default 0,
    organizer_id   bigint,
    event_id       bigint,
    order_id       bigint,
    type           text not null default '',
    amount         numeric(12,2),
    created_at     timestamptz,
    data           jsonb not null default '{}'::jsonb,
    synced_at      timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists settlement_items_settlement_idx on public.settlement_items (site, settlement_id);

-- ── Bericht des täglichen Abgleichs (id = JJJJ-MM-TT Ortszeit der Seite) ──
create table if not exists public.sync_reports (
    site         text not null,
    id           text not null,
    at           timestamptz not null,
    differences  integer not null default 0,
    report       jsonb not null default '{}'::jsonb,
    synced_at    timestamptz not null default now(),
    primary key (site, id)
);

-- ════════════════ Trigger, RLS ════════════════

-- Verlauf: zusätzlich user_id als Kunden-Bezug (Prämien, Geldbörsen), damit der Löschweg greift
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
    elsif j ? 'user_id' and j->>'user_id' is not null and (j->>'user_id')::bigint > 0 then
        cid := (j->>'user_id')::bigint;
    end if;
    mail := nullif(lower(coalesce(j->>'email', j->>'billing_email', j->>'owner_email', '')), '');
    insert into change_log (site, entity, object_id, op, customer_id, email, data)
    values (new.site, tg_table_name, coalesce(j->>'id', j->>'site'), lower(tg_op), cid, mail, j);
    return new;
end $$;

do $$
declare t text;
begin
    foreach t in array array['organizers','guests','loyalty','coupons','wallets','wallet_transactions','settlements','settlement_items'] loop
        execute format('drop trigger if exists %I_touch on public.%I', t, t);
        execute format('create trigger %I_touch before insert or update on public.%I for each row execute function public.mirror_touch()', t, t);
        execute format('drop trigger if exists %I_log on public.%I', t, t);
        execute format('create trigger %I_log after insert or update or delete on public.%I for each row execute function public.mirror_log()', t, t);
    end loop;
    foreach t in array array['organizers','guests','loyalty','coupons','wallets','wallet_transactions','settlements','settlement_items','sync_reports'] loop
        execute format('alter table public.%I enable row level security', t);
        execute format('revoke all on public.%I from anon, authenticated', t);
        execute format('grant all on public.%I to service_role', t);
    end loop;
end $$;

-- ════════════════ Funktionen ════════════════

-- Löschweg: zusätzlich Prämien des Kontos (Geldbörsen anonymisiert WordPress selbst)
create or replace function public.mirror_forget(p_site text, p_customer_id bigint, p_email text)
returns jsonb
language plpgsql security definer set search_path = public as $$
declare
    n_customers int;
    n_loyalty int;
    n_log int;
    mail text := nullif(lower(trim(coalesce(p_email, ''))), '');
begin
    delete from customers where site = p_site and id = p_customer_id;
    get diagnostics n_customers = row_count;
    delete from loyalty where site = p_site and user_id = p_customer_id;
    get diagnostics n_loyalty = row_count;
    delete from change_log
     where site = p_site
       and ((p_customer_id > 0 and customer_id = p_customer_id)
            or (entity = 'customers' and object_id = p_customer_id::text)
            or (mail is not null and email = mail));
    get diagnostics n_log = row_count;
    return jsonb_build_object('customers', n_customers, 'loyalty', n_loyalty, 'change_log', n_log);
end $$;

-- Kinder entfernen, die es in WordPress nicht mehr gibt. p_sets: [{"parent": 12, "keep": [...]}, …]
create or replace function public.mirror_prune(p_site text, p_table text, p_sets jsonb)
returns integer
language plpgsql security definer set search_path = public as $$
declare n int := 0; m int;
begin
    if p_table = 'order_items' then
        delete from order_items t using jsonb_array_elements(p_sets) s
         where t.site = p_site and t.order_id = (s->>'parent')::bigint
           and not (t.id = any (coalesce(array(select jsonb_array_elements_text(s->'keep'))::bigint[], '{}')));
    elsif p_table = 'ticket_categories' then
        delete from ticket_categories t using jsonb_array_elements(p_sets) s
         where t.site = p_site and t.event_id = (s->>'parent')::bigint
           and not (t.idx = any (coalesce(array(select jsonb_array_elements_text(s->'keep'))::int[], '{}')));
    elsif p_table = 'guests' then
        delete from guests t using jsonb_array_elements(p_sets) s
         where t.site = p_site and t.event_id = (s->>'parent')::bigint
           and not (t.id = any (coalesce(array(select jsonb_array_elements_text(s->'keep')), '{}')));
    elsif p_table = 'loyalty' then
        delete from loyalty t using jsonb_array_elements(p_sets) s
         where t.site = p_site and t.user_id = (s->>'parent')::bigint
           and not (t.id = any (coalesce(array(select jsonb_array_elements_text(s->'keep')), '{}')));
    elsif p_table = 'settlement_items' then
        -- parent = Veranstalter, keep = alle Positionen dieses Veranstalters in WordPress
        delete from settlement_items t using jsonb_array_elements(p_sets) s
         where t.site = p_site and t.organizer_id = (s->>'parent')::bigint
           and not (t.id = any (coalesce(array(select jsonb_array_elements_text(s->'keep'))::bigint[], '{}')));
    elsif p_table = 'coupons' then
        -- parent ohne Bedeutung: alle Codes der Seite außer keep
        delete from coupons t using jsonb_array_elements(p_sets) s
         where t.site = p_site
           and not (t.id = any (coalesce(array(select jsonb_array_elements_text(s->'keep')), '{}')));
    else
        raise exception 'mirror_prune: Tabelle % nicht erlaubt', p_table;
    end if;
    get diagnostics m = row_count; n := n + m;
    return n;
end $$;

-- Prüfsummen für den Abgleich: {"<id>": "<md5>", …} für p_from < id <= p_to.
-- Felder und Format müssen exakt TIX_Mirror_Reconcile::wp_fingerprints() entsprechen.
-- Ein jsonb-Wert (keine Zeilen), damit max_rows von Supabase nicht kürzt.
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
    else
        raise exception 'mirror_fingerprints: Tabelle % nicht erlaubt', p_table;
    end if;
    return coalesce(r, '{}'::jsonb);
end $$;

-- Aufbewahrung je Seite (Einstellung „Verlauf aufbewahren“, Standard 10 Jahre)
create or replace function public.mirror_prune_log(p_site text, p_days integer)
returns integer
language plpgsql security definer set search_path = public as $$
declare n int;
begin
    if p_days < 30 then raise exception 'mindestens 30 Tage'; end if;
    delete from change_log where site = p_site and changed_at < now() - make_interval(days => p_days);
    get diagnostics n = row_count;
    return n;
end $$;
drop function if exists public.mirror_prune_change_log(integer);

revoke all on function public.mirror_forget(text, bigint, text) from public, anon, authenticated;
revoke all on function public.mirror_prune(text, text, jsonb) from public, anon, authenticated;
revoke all on function public.mirror_fingerprints(text, text, bigint, bigint) from public, anon, authenticated;
revoke all on function public.mirror_prune_log(text, integer) from public, anon, authenticated;
grant execute on function public.mirror_forget(text, bigint, text) to service_role;
grant execute on function public.mirror_prune(text, text, jsonb) to service_role;
grant execute on function public.mirror_fingerprints(text, text, bigint, bigint) to service_role;
grant execute on function public.mirror_prune_log(text, integer) to service_role;
