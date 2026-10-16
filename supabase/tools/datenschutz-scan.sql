-- Datenspiegel: projektweite Datenschutz-Suche (nur lesend) über alle Tabellen und den Verlauf, alle Seiten.
-- Zeigt nur Anzahlen und Feldnamen, nie Werte.
--   bash supabase/tools/migrate.sh ~/.private_keys/supabase_datenspiegel_db.txt --sql supabase/tools/datenschutz-scan.sql
create temporary view t as
  select 'customers' tb, to_jsonb(x)::text j from customers x union all select 'organizers', to_jsonb(x)::text from organizers x
  union all select 'orders', to_jsonb(x)::text from orders x union all select 'order_items', to_jsonb(x)::text from order_items x
  union all select 'payments', to_jsonb(x)::text from payments x union all select 'tickets', to_jsonb(x)::text from tickets x
  union all select 'events', to_jsonb(x)::text from events x union all select 'ticket_categories', to_jsonb(x)::text from ticket_categories x
  union all select 'guests', to_jsonb(x)::text from guests x union all select 'loyalty', to_jsonb(x)::text from loyalty x
  union all select 'coupons', to_jsonb(x)::text from coupons x union all select 'wallets', to_jsonb(x)::text from wallets x
  union all select 'wallet_transactions', to_jsonb(x)::text from wallet_transactions x union all select 'settlements', to_jsonb(x)::text from settlements x
  union all select 'settlement_items', to_jsonb(x)::text from settlement_items x union all select 'checkins', to_jsonb(x)::text from checkins x
  union all select 'refunds', to_jsonb(x)::text from refunds x union all select 'invoices', to_jsonb(x)::text from invoices x
  union all select 'invoice_counters', to_jsonb(x)::text from invoice_counters x union all select 'change_log', to_jsonb(x)::text from change_log x
; create temporary view k as
  select tb, (regexp_matches(j, '"([^"]*(pass|token|secret|nonce|_pin|pin_|hash|_key|wfls_|2fa|totp|recovery|application_password|credential|session)[^"]*)"\s*:', 'gi'))[1] as key from t;
select 'Zeilen gesamt: ' || (select count(*) from t);
select 'Hash-Werte: ' || count(*) from t where j ~ '"\$(P|H|wp|2y|2a|2b|argon2id?)\$';
select 'Klartext-IBAN: ' || count(*) from t where regexp_replace(j, '"(iban_enc|iban_masked)": "[^"]*"', '', 'g') ~ '\m[A-Z]{2}[0-9]{2}( ?[A-Z0-9]){11,30}\M';
select 'Verdächtige Feldnamen: ' || coalesce(string_agg(tb || '.' || key || ' ' || n, ', '), 'keine') from (select tb, key, count(*) n from k where key not in ('idempotency_key', 'issuer_key') group by 1, 2 order by 1, 2) s;
