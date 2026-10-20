-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – E-Mail-Folgen von Künstlern (1.38.518, tix_artist_mail_followers)
-- PSEUDONYMISIERT: statt der E-Mail nur email_hash (HMAC-SHA256 mit dem Spiegel-Schlüssel, auf allen
-- Seiten gleich, nicht umkehrbar). Nie gespiegelt: Adresse, Abmelde-Token, IP-Hash. Gelöscht wird in
-- WordPress immer echt; der Spiegel folgt (neue Zeilen per Nachlauf, Änderungen/Löschungen per Abgleich).
-- Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════════

create table if not exists public.artist_followers (
    site           text not null,
    id             bigint not null,
    artist_id      bigint not null,
    email_hash     text not null default '',
    status         text not null default '',      -- pending | active
    cities         jsonb,
    user_id        bigint,                        -- 0/null = ohne Konto
    consent_text   text not null default '',
    consent_at     timestamptz,
    confirmed_at   timestamptz,
    last_notified  timestamptz,
    created_at     timestamptz,
    synced_at      timestamptz not null default now(),
    primary key (site, id)
);
create index if not exists artist_followers_artist_idx on public.artist_followers (site, artist_id, status);
create index if not exists artist_followers_hash_idx on public.artist_followers (email_hash);

drop trigger if exists artist_followers_touch on public.artist_followers;
create trigger artist_followers_touch before insert or update on public.artist_followers for each row execute function public.mirror_touch();
drop trigger if exists artist_followers_log on public.artist_followers;
create trigger artist_followers_log after insert or update or delete on public.artist_followers for each row execute function public.mirror_log();
alter table public.artist_followers enable row level security;
revoke all on public.artist_followers from anon, authenticated;
grant all on public.artist_followers to service_role;

-- Prüfsummen: zusätzlich artist_followers (Künstler, Status, Städte, Bestätigung)
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
    else
        raise exception 'mirror_fingerprints: Tabelle % nicht erlaubt', p_table;
    end if;
    return coalesce(r, '{}'::jsonb);
end $$;

revoke all on function public.mirror_fingerprints(text, text, bigint, bigint) from public, anon, authenticated;
grant execute on function public.mirror_fingerprints(text, text, bigint, bigint) to service_role;
