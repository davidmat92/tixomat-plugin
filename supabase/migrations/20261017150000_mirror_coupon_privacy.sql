-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Empfänger-Adressen von Geschenkgutscheinen (1.38.496)
-- Gutscheine tragen E-Mail-Adressen Dritter (gift.recipient_email, sent_to, sends[].to).
-- 1. mirror_forget tilgt zusätzlich jeden Gutschein-Verlaufseintrag, der die gelöschte E-Mail enthält
--    (Käufer oder Beschenkte*r); der aktuelle Stand folgt WordPress.
-- 2. mirror_purge_history erlaubt 'coupons': kommt ein Gutschein anonymisiert an (…@removed.invalid),
--    bleibt nur der neueste Verlaufseintrag.
-- Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════════

create or replace function public.mirror_forget(p_site text, p_customer_id bigint, p_email text)
returns jsonb
language plpgsql security definer set search_path = public as $$
declare
    n_customers int;
    n_loyalty int;
    n_log int;
    n_coupons int := 0;
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
    -- Gutscheine: E-Mail steht im Inhalt (Empfänger, Versand), nicht in der Spalte email
    if mail is not null then
        delete from change_log
         where site = p_site and entity = 'coupons' and position(mail in lower(data::text)) > 0;
        get diagnostics n_coupons = row_count;
    end if;
    return jsonb_build_object('customers', n_customers, 'loyalty', n_loyalty, 'change_log', n_log, 'coupon_log', n_coupons);
end $$;

create or replace function public.mirror_purge_history(p_site text, p_items jsonb)
returns integer
language plpgsql security definer set search_path = public as $$
declare n int;
begin
    delete from change_log c
     using (select distinct s->>'entity' as entity, s->>'id' as object_id
              from jsonb_array_elements(p_items) s
             where s->>'entity' in ('orders', 'tickets', 'guests', 'customers', 'coupons')) x
     where c.site = p_site and c.entity = x.entity and c.object_id = x.object_id
       and c.id < (select max(c2.id) from change_log c2
                    where c2.site = p_site and c2.entity = x.entity and c2.object_id = x.object_id);
    get diagnostics n = row_count;
    return n;
end $$;

revoke all on function public.mirror_forget(text, bigint, text) from public, anon, authenticated;
revoke all on function public.mirror_purge_history(text, jsonb) from public, anon, authenticated;
grant execute on function public.mirror_forget(text, bigint, text) to service_role;
grant execute on function public.mirror_purge_history(text, jsonb) to service_role;
