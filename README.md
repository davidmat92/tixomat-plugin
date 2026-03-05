# Tixomat – Event & Ticket Management

Zentrales Event-Management mit integriertem Ticketing-System, Saalplan-Editor, Support-CRM und automatischer WooCommerce-Synchronisation.

**Version:** 1.28.0
**Autor:** MDJ Veranstaltungs UG (haftungsbeschraenkt)
**Voraussetzungen:** WordPress 6.x, WooCommerce 8.x (HPOS-kompatibel)
**Optional:** Tickera (externes Ticketing)

---

## Features

### Event-Verwaltung
- Mehrtaegige Events mit Einlass-/Start-/Endzeit
- 6 automatische Status-Typen (Verfuegbar, Ausverkauft, Vergangen, VVK geschlossen, Abgesagt, Verschoben)
- 3 Vorverkaufs-Modi: Manuell, Datum, Offset (Stunden vor Event)
- Event-Duplizierung mit automatischem Re-Sync
- Serien/Wiederkehrende Events (woechentlich, 2-woechentlich, monatlich, manuell)
- 9-Tab Event-Editor + 5-Schritt Wizard fuer neue Events
- Automatische Archivierung nach konfigurierbarer Frist

### Ticketing (Dual-Mode)
- **Standalone:** Eigenes Ticket-System mit 12-stelligen alphanumerischen Codes
- **Tickera:** Integration mit externem Tickera-Plugin
- **Beides:** Parallele Ticket-Generierung ueber beide Systeme
- QR-Code Generierung und Scanning (Format: `GL-{EVENT_ID}-{CODE}`)
- PDF-Tickets mit visuellem Template-Editor (14 positionierbare Felder)
- Kryptische Download-URLs mit 64-Zeichen Hex-Token
- Ticket-Transfer zwischen Personen

### Saalplan-System
- Visueller Saalplan-Editor (Reihen, Sektionen, Einzelplaetze)
- Echtzeit-Verfuegbarkeitspruefung
- 15-Minuten Reservierungs-Timeout
- Best-Available Sitzplatz-Vorschlaege
- Integration in Ticket-Selector und Checkout

### Ticket-Vorlagen
- Drag & Drop Template-Editor mit Live-Vorschau
- 14 Felder: Event-Name, Datum, Uhrzeit, Einlass, Ort, Adresse, Kategorie, Preis, Name, E-Mail, Code, Bestellnummer, Sitzplatz, Custom-Text
- QR-Code und Barcode mit proportionaler Skalierung
- Erweiterte Eigenschaften: Rotation, Deckkraft, Hintergrundfarbe, Rahmen, Zeichenabstand, Zeilenhoehe, Textumwandlung
- 3 Modi: Global, Vorlage (CPT), Custom (pro Event), Keine
- Wiederverwendbare Vorlagen als eigener Custom Post Type

### Preisgestaltung
- Unbegrenzte Ticket-Kategorien pro Event
- Dynamische Preisphasen (Early Bird, Last Minute, etc.)
- Mengenrabatte (ab X Tickets, % oder Festbetrag)
- Bundle-Deals (Festpreis-Pakete)
- Multi-Event Combo-Tickets
- WooCommerce Coupon-Integration

### Gruppen-Buchungen
- Gruppenbuchungen mit optionalem Cost-Splitting
- Konfigurierbare Min/Max Gruppengroessen
- Individuelle Zahlungslinks fuer Teilnehmer

### Checkout & Warenkorb
- Eigener Multi-Step Checkout (ersetzt WooCommerce-Standard)
- Echtzeit-Preisberechnung (Rabatte, Steuern)
- Express-/One-Click Checkout Modal
- Abandoned Cart Recovery mit E-Mail-Benachrichtigungen
- Optionaler Newsletter-Signup beim Checkout

### Check-in System
- Browser-basierter QR-Code Scanner
- Echtzeit-Validierung: 12-stellige Codes, Legacy-Codes, QR-Format
- Gaesteliste mit Suche, Import, manuellen Eintraegen
- Teilweiser Check-in (z.B. 3 von 5 eingecheckt)
- Self-Service QR-Seite fuer Gaeste
- Massen-E-Mail an Teilnehmer
- CSV-Export der Gaesteliste

### Support-System (CRM)
- CPT-basierte Support-Tickets mit Status-Verwaltung
- Multi-Tab Admin-Dashboard (Anfragen, Kundensuche, Statistiken)
- Kundensuche: E-Mail -> Bestellungen/Tickets, Bestellnummer -> Details, Code -> Ticket
- Nachrichten-Threading (Kunde, Admin, interne Notizen)
- Frontend-Kundenportal per Shortcode
- Optionaler Floating Chat-Widget
- Quick-Actions: Ticket erneut senden, Inhaber aendern, Ticket oeffnen

### E-Mail System
- White-Label E-Mails (eigener Absender, Logo, Footer)
- E-Mail-Typen: Bestellbestaetigung, Erinnerung, Follow-up, Abandoned Cart, Ticket-Transfer
- Konfigurierbare Erinnerungen (X Tage vor/nach Event)

### Kalender
- Interaktiver Kalender mit Event-Filterung nach Kategorie
- Monats- und Listenansicht
- iCal-Export fuer Kalender-Sync

### Weitere Features
- Newsletter-System mit Subscribe/Unsubscribe
- FAQ-Akkordeon pro Event
- Countdown-Timer zum Event/Vorverkauf
- Embed-Widget fuer externe Seiten (iframe)
- Upsell/Cross-Sell (Events + Produkte)
- Spenden-/Charity-Integration (pro Event, % vom Warenkorb)
- Admin-Statistiken mit Charts und KPIs
- Dashboard-Widget mit anstehenden Events

---

## Custom Post Types

| CPT | Slug | Oeffentlich | Beschreibung |
|-----|------|-------------|-------------|
| Events | `event` | Ja | Haupt-Event-Objekt mit allen Metadaten |
| Locations | `tix_location` | Ja | Veranstaltungsorte mit Adresse |
| Organizer | `tix_organizer` | Ja | Veranstalter-Kontaktdaten |
| Tickets | `tix_ticket` | Nein | Individuelle Tickets (QR-Code, PDF) |
| Ticket-Vorlagen | `tix_ticket_tpl` | Nein | Wiederverwendbare Ticket-Designs |
| Newsletter | `tix_subscriber` | Nein | Newsletter-Abonnenten |
| Abandoned Carts | `tix_abandoned_cart` | Nein | Unvollstaendige Bestellungen |
| Support-Tickets | `tix_support_ticket` | Nein | Kundenanfragen mit Nachrichtenverlauf |

---

## Shortcodes

| Shortcode | Parameter | Beschreibung |
|-----------|-----------|-------------|
| `[tix_ticket_selector]` | `id` | Ticket-Auswahl mit Warenkorb |
| `[tix_checkout]` | — | Multi-Step Checkout |
| `[tix_calendar]` | `category`, `view` | Event-Kalender |
| `[tix_checkin]` | `id` | QR-Scanner & Gaesteliste |
| `[tix_seatmap]` | `id` | Saalplan-Auswahl |
| `[tix_my_tickets]` | — | Benutzer-Ticket-Dashboard |
| `[tix_support]` | — | Kunden-Support-Portal |
| `[tix_faq]` | `id` | FAQ-Akkordeon |
| `[tix_upsell]` | `id` | Cross-Sell Bereich |
| `[tix_embed]` | `id`, `style` | Embed-Widget (minimal/full) |
| `[tix_newsletter]` | `event_id`, `label` | Newsletter-Formular |
| `[tix_countdown]` | `id`, `style` | Countdown-Timer |
| `[tix_group_booking]` | `id` | Gruppenbuchungs-Formular |

---

## Integrationen

| System | Beschreibung |
|--------|-------------|
| **WooCommerce** | Automatische Produkt-Synchronisation mit Varianten |
| **Tickera** | Optionale Event- & Ticket-Typ-Erstellung |
| **Airtable** | REST API Sync fuer Ticketdaten |
| **Supabase** | PostgreSQL REST API Sync |
| **Breakdance** | Alle Meta-Felder als Dynamic Data verfuegbar |
| **SEO** | Open Graph + JSON-LD Structured Data |

---

## Einstellungen (11 Tabs)

| Tab | Inhalt |
|-----|--------|
| Design | Farben, Typografie, Border-Radius, Button-Style |
| Ticket-Selector | Darstellungsstil, Countdown, Verfuegbarkeit |
| FAQ | Akkordeon/Listen-Stil, Icon, Farben |
| Checkout | Checkout-Seite, Warenkorb-Timeout, AGB |
| Express-Checkout | One-Click Kauf aktivieren |
| Meine Tickets | Ticket-Seite, Transfer, Download |
| E-Mail | Absender, Logo, Erinnerungen, Follow-up |
| Check-in | Check-in Seite, Scanner-Aufloesung |
| Ticket-Vorlage | Hintergrundbild, Felder positionieren |
| Sync | Airtable/Supabase Zugangsdaten |
| Erweitert | Ticket-System, Loeschenschutz, Debug, Support |

---

## Dateistruktur

```
tixomat/
├── tixomat.php                              Haupt-Plugin-Datei
├── DOCUMENTATION.md                         Technische Dokumentation
├── README.md
├── includes/                                32 PHP-Klassen
│   ├── class-tix-cpt.php                   CPTs + Taxonomien + Admin UI
│   ├── class-tix-metabox.php               Event-Editor (9 Tabs + Wizard)
│   ├── class-tix-sync.php                  WooCommerce & Tickera Sync
│   ├── class-tix-settings.php              Einstellungen (11 Tabs)
│   ├── class-tix-columns.php               Admin-Spalten, CSV-Export
│   ├── class-tix-frontend.php              Cron, OG/JSON-LD, Dashboard
│   ├── class-tix-checkout.php              Checkout, Abandoned Cart
│   ├── class-tix-ticket-selector.php       Ticket-Auswahl Frontend
│   ├── class-tix-calendar.php              Kalender, iCal-Export
│   ├── class-tix-checkin.php               QR-Scanner, Gaesteliste
│   ├── class-tix-emails.php                White-Label E-Mail-System
│   ├── class-tix-faq.php                   FAQ-Akkordeon
│   ├── class-tix-my-tickets.php            Benutzer-Ticket-Dashboard
│   ├── class-tix-upsell.php               Cross-Sell/Upsell
│   ├── class-tix-tickets.php               Ticket-CPT, PDF-Generierung
│   ├── class-tix-ticket-template.php       Visueller Template-Editor
│   ├── class-tix-ticket-template-cpt.php   Wiederverwendbare Vorlagen
│   ├── class-tix-ticket-transfer.php       Ticket-Umbuchung
│   ├── class-tix-ticket-db.php             Ticket-Datenbank
│   ├── class-tix-group-booking.php         Gruppenbuchungen + Splitting
│   ├── class-tix-group-discount.php        Mengenrabatte, Bundles, Combos
│   ├── class-tix-dynamic-pricing.php       Zeitbasierte Preisphasen
│   ├── class-tix-series.php                Wiederkehrende Events
│   ├── class-tix-embed.php                 iframe Embed-Widget
│   ├── class-tix-cleanup.php               Loeschenschutz, Bereinigung
│   ├── class-tix-support.php               Support-CRM + Kundenportal
│   ├── class-tix-docs.php                  In-Admin Dokumentation
│   ├── class-tix-seatmap.php               Saalplan-Editor & Reservierung
│   ├── class-tix-statistics.php            Admin-Statistiken
│   ├── class-tix-sync-airtable.php         Airtable-Integration
│   ├── class-tix-sync-supabase.php         Supabase-Integration
│   └── debug-tickera-meta.php              Debug-Utility
├── assets/
│   ├── css/                                 13 Stylesheets + Minified
│   ├── js/                                  15 JavaScript-Dateien + Minified
│   └── fonts/                               OpenSans, RobotoMono (TTF)
└── .github/
    └── workflows/
        └── deploy.yml                       Auto-Deploy via GitHub Actions
```

---

## Deployment

Push auf `main` triggert automatisch GitHub Actions:

```
git push origin main
  → GitHub Actions (rsync)
    → Server: /wp-content/plugins/tixomat/
```

Konfiguration: `.github/workflows/deploy.yml`

---

## Technologie

- **WordPress** 6.x mit nativen APIs (keine externen Frameworks)
- **WooCommerce** 8.x (HPOS-kompatibel) fuer E-Commerce
- **GD Library** fuer PDF/Ticket-Rendering mit TrueType-Fonts
- **Vanilla JavaScript** (kein React/Vue)
- **CSS Custom Properties** (`--tix-*`) fuer vollstaendiges Theming
- **REST APIs:** Airtable, Supabase (optionale Sync-Ziele)

---

## Sicherheit

- Nonce-Verifizierung auf allen AJAX-Endpunkten und Formularen
- Capability-Checks (`manage_options` fuer Admin-Aktionen)
- Input-Sanitierung (`sanitize_text_field`, `sanitize_email`, etc.)
- Output-Escaping (`esc_html`, `esc_attr`, `esc_url`, etc.)
- Prepared Statements via `$wpdb->prepare()`
- Token-basierte Ticket-Downloads (64-Zeichen Hex statt vorhersehbarer URLs)
- Gast-Zugangsschluessel fuer Support-Tickets
