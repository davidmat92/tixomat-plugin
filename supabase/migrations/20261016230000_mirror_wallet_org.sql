-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Plattform-Guthaben (1.38.466)
-- Buchungen tragen den Veranstalter (Cashless mit dem Plattform-Guthaben); Geldbörsen haben
-- zusätzlich scope_type 'platform' (Spalte gibt es schon). Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════════

alter table public.wallet_transactions add column if not exists organizer_id bigint not null default 0;
create index if not exists wallet_tx_org_idx on public.wallet_transactions (site, organizer_id) where organizer_id <> 0;
