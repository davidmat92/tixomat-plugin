# Event Hub v3 – Tickera & WooCommerce Automation

Kein ACF, kein Extra-Plugin. Nur Tickera + WooCommerce.

## Was ist neu in v3

- **Mehrtägige Events** – Datum von/bis statt nur ein Datum
- **Event-Kategorien** – eigene Taxonomy `event_category`, wird automatisch auf WooCommerce `product_cat` synchronisiert
- **Kein Editor** – nur Titel, Excerpt, Beitragsbild (Breakdance macht den Rest)

## Workflow

1. Events → Neues Event
2. Titel, Excerpt, Beitragsbild
3. Zeitraum: Datum von/bis + Uhrzeit von/bis
4. Ort
5. Event-Kategorie(n) wählen (Seitenleiste)
6. Ticket-Kategorien: Name, Preis, Menge
7. Veröffentlichen → alles wird automatisch generiert

## Kategorie-Sync

```
Event-Kategorie "Konzert" am CPT
    ↓ automatisch beim Speichern
WooCommerce product_cat "Konzert"
    ↓ zugewiesen an alle generierten Produkte dieses Events
```

Du verwaltest Kategorien nur am Event-CPT. Der Sync erstellt fehlende WC-Kategorien automatisch (inkl. Hierarchie).

## Breakdance Dynamic Data

### Event-Daten
| Meta Key | Beispiel |
|---|---|
| `_eh_date_start` | `2026-11-27` |
| `_eh_date_end` | `2026-11-28` |
| `_eh_time_start` | `23:00` |
| `_eh_time_end` | `05:00` |
| `_eh_location` | `Kölnarena` |

### Ticket-Kategorien (nummeriert)
| Meta Key | Beispiel |
|---|---|
| `_eh_ticket_1_name` | `VIP Table` |
| `_eh_ticket_1_price` | `120` |
| `_eh_ticket_1_qty` | `50` |
| `_eh_ticket_1_desc` | `Inkl. Flasche` |
| `_eh_ticket_1_product_id` | `456` |
| `_eh_ticket_1_add_to_cart_url` | `https://…/cart/?add-to-cart=456` |
| `_eh_ticket_count` | `3` |
| `_eh_product_ids` | `456,457,458` |

### Excerpt + Beitragsbild
Nutze in Breakdance die normalen WP Dynamic Data Felder:
- Post Excerpt
- Featured Image

### Taxonomie
`event_category` – nutzbar in Breakdance für Loops, Filter, Archive.

## Ticket-Template

Standard-ID: `25`. Änderbar:

```php
// wp-config.php
define('EH_TICKET_TEMPLATE_ID', '25');

// Oder per Filter (z.B. pro Kategorie unterschiedlich)
add_filter('eh_ticket_template_id', function($id, $cat, $post_id) {
    if ($cat['name'] === 'VIP') return '30';
    return $id;
}, 10, 3);
```

## Dateistruktur

```
event-hub/
├── event-hub.php
├── includes/
│   ├── class-eh-cpt.php          ← CPT + Taxonomy
│   ├── class-eh-metabox.php      ← Meta Boxes (nativ)
│   ├── class-eh-sync.php         ← Sync + Breakdance-Meta
│   ├── class-eh-columns.php      ← Admin-Spalten
│   ├── class-eh-cleanup.php      ← Cleanup bei Löschung
│   └── debug-tickera-meta.php    ← Debug-Tool
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── README.md
```
