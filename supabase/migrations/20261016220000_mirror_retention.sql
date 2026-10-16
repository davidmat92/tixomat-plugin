-- ════════════════════════════════════════════════════════════════════════════
-- Tixomat Datenspiegel – Aufbewahrung von Rechnungsdaten (1.38.461)
-- orders.retained_until: Konto gelöscht, Rechnungsname/-anschrift werden bis zu diesem Tag
-- aufbewahrt (31.12. des Bestelljahrs + 10). Leer = keine Aufbewahrung (normal oder abgelaufen).
-- Das Plugin leitet den Wert aus dem Zustand der Bestellung ab. Wiederholbar.
-- ════════════════════════════════════════════════════════════════════════════

alter table public.orders add column if not exists retained_until date;
create index if not exists orders_retained_idx on public.orders (site, retained_until) where retained_until is not null;
