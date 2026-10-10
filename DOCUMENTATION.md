# Tixomat -- Event & Ticket Management

**Version:** 1.33.95
**Autor:** MDJ Veranstaltungs UG (haftungsbeschraenkt)
**Text Domain:** `tixomat`
**Abhaengigkeiten:** WordPress 6.x, WooCommerce 8.x (HPOS-kompatibel)
**Page Builder:** Breakdance (alle Meta-Felder als Dynamic Data verfuegbar)

---

## Konstanten

| Konstante | Wert | Beschreibung |
|---|---|---|
| `TIXOMAT_VERSION` | `'1.33.95'` | Aktuelle Plugin-Version |
| `TIXOMAT_PATH` | `plugin_dir_path(__FILE__)` | Absoluter Pfad zum Plugin-Verzeichnis |
| `TIXOMAT_URL` | `plugin_dir_url(__FILE__)` | URL zum Plugin-Verzeichnis |

---

## Inhaltsverzeichnis

1. [Ueberblick & Architektur](#1-ueberblick--architektur)
2. [Dateistruktur](#2-dateistruktur)
3. [Custom Post Types & Taxonomien](#3-custom-post-types--taxonomien)
4. [Ticket-System (Dual-Mode)](#4-ticket-system-dual-mode)
5. [Ticket-Template-System](#5-ticket-template-system)
6. [Metabox-Felder (Admin)](#6-metabox-felder-admin)
7. [Alle Event-Meta-Keys](#7-alle-event-meta-keys)
8. [Location & Organizer Meta](#8-location--organizer-meta)
9. [Subscriber & Abandoned Cart Meta](#9-subscriber--abandoned-cart-meta)
10. [Ticket Meta (tix_ticket CPT)](#10-ticket-meta-tix_ticket-cpt)
11. [Event-Status-System](#11-event-status-system)
12. [Vorverkauf-System (Presale)](#12-vorverkauf-system-presale)
13. [Dynamische Preisphasen](#13-dynamische-preisphasen)
14. [Serientermine](#14-serientermine)
15. [Shortcodes](#15-shortcodes)
16. [AJAX-Endpoints](#16-ajax-endpoints)
17. [Admin-Post Actions](#17-admin-post-actions)
18. [Checkout-System](#18-checkout-system)
19. [Express Checkout](#19-express-checkout)
20. [Gruppenrabatte & Kombi-Tickets](#20-gruppenrabatte--kombi-tickets)
21. [Gruppenbuchung (Group Booking)](#21-gruppenbuchung-group-booking)
22. [Check-in & Gaesteliste](#22-check-in--gaesteliste)
23. [Ticket-Transfer](#23-ticket-transfer)
24. [Meine Tickets](#24-meine-tickets)
25. [Abandoned Cart Recovery](#25-abandoned-cart-recovery)
26. [Newsletter-System](#26-newsletter-system)
27. [E-Mail-System](#27-e-mail-system)
28. [Embed-Modus](#28-embed-modus)
29. [Zusatzprodukte](#29-zusatzprodukte)
30. [Kalender-Integration](#30-kalender-integration)
31. [Einstellungen (Settings)](#31-einstellungen-settings)
32. [Design-System 2026](#32-design-system-2026)
33. [CSS Custom Properties](#33-css-custom-properties)
34. [Loeschutz & Cleanup](#34-loeschutz--cleanup)
35. [Event-Duplizierung](#35-event-duplizierung)
36. [Admin-Spalten & Liste](#36-admin-spalten--liste)
37. [Cron-Jobs & Scheduled Actions](#37-cron-jobs--scheduled-actions)
38. [Transients](#38-transients)
39. [Dashboard-Widget](#39-dashboard-widget)
40. [SEO: Open Graph & JSON-LD](#40-seo-open-graph--json-ld)
41. [Helper Functions](#41-helper-functions)
42. [Hooks & Filter](#42-hooks--filter)
43. [JavaScript & CSS Assets](#43-javascript--css-assets)
44. [Sicherheit](#44-sicherheit)
45. [Soziales Projekt (Charity)](#45-soziales-projekt-charity)
46. [Support-System (CRM + Kunden-Portal)](#46-support-system-crm--kunden-portal)
47. [Gewinnspiel (Raffle)](#47-gewinnspiel-raffle)
48. [Event-Seite (tix_event_page)](#48-event-seite-tix_event_page)
49. [Rabattcode-Generator](#49-rabattcode-generator)
50. [Presale-Countdown & Warteliste](#50-presale-countdown--warteliste)
51. [Post-Event Feedback](#51-post-event-feedback)
52. [Timetable / Programm (Multi-Stage)](#52-timetable--programm-multi-stage)
53. [Statistiken](#53-statistiken)
54. [Saalplan (Seatmap)](#54-saalplan-seatmap)
55. [Promoter-System](#55-promoter-system)
56. [Daten-Synchronisierung](#56-daten-synchronisierung)
57. [Veranstalter-Dashboard (Organizer)](#57-veranstalter-dashboard-organizer)
58. [KI-Schutz (Content Guard)](#58-ki-schutz-content-guard)
59. [REST API (tixomat/v1)](#59-rest-api-tixomatv1)
60. [Admin Shell / Fullscreen-Modus](#60-admin-shell--fullscreen-modus)
61. [Organizer Admin System](#61-organizer-admin-system)
62. [Custom Login URLs](#62-custom-login-urls)
63. [KI-Assistent (AI Writer)](#63-ki-assistent-ai-writer)
64. [Event-Vorlagen](#64-event-vorlagen)
65. [Auto-Save](#65-auto-save)
66. [Emoji-Filter](#66-emoji-filter)

---

## 1. Ueberblick & Architektur

Tixomat automatisiert den gesamten Ticketing-Workflow fuer WordPress-basierte Veranstaltungswebseiten. Der Ablauf gliedert sich in folgende Schritte:

1. **Event erstellen** -- Der Administrator erstellt ein Event (Custom Post Type `event`) mit Ticket-Kategorien, Preisen und allen relevanten Meta-Daten.
2. **Sync** -- Beim Speichern erstellt `TIX_Sync` automatisch WooCommerce-Produkte (je eine Variante pro Ticket-Kategorie).
3. **Ticket-Selector** -- Auf der Event-Seite zeigt `TIX_Ticket_Selector` die verfuegbaren Tickets mit Live-Preisberechnung, Countdown und optionalem Express-Modal.
4. **Checkout** -- `TIX_Checkout` ersetzt den WooCommerce-Standard-Checkout komplett durch einen eigenen, mehrstufigen Checkout-Prozess.
5. **Eigenes Ticketsystem** -- `TIX_Tickets` generiert Tickets mit QR-Code.
6. **Ticket-Templates** -- `TIX_Ticket_Template` ermoeglicht die visuelle Ticket-Gestaltung mit Hintergrundbildern, Drag & Drop und GD-Rendering.
7. **Cron** -- `TIX_Frontend` ueberwacht per WP-Cron Vorverkaufsende, Preisphasen und Archivierung.
8. **Breakdance-Meta** -- Alle Event-Daten stehen als Dynamic Data im Breakdance Page Builder zur Verfuegung.
9. **Serientermine** -- `TIX_Series` generiert Kind-Events fuer wiederkehrende Veranstaltungen.
10. **E-Mail-System** -- `TIX_Emails` sendet White-Label-E-Mails (Bestaetigung, Reminder, Followup, Abandoned Cart).

### Klassenuebersicht

Tixomat besteht aus 43 Klassen:

| Klasse | Datei | Verantwortung |
|---|---|---|
| `TIX_CPT` | `class-tix-cpt.php` | CPT-Registrierung, Admin-Menue, Admin-Bar, Location/Organizer Metaboxen |
| `TIX_Metabox` | `class-tix-metabox.php` | Event-Metabox (12 Tabs + Wizard-Modus), AJAX-Endpoints |
| `TIX_Sync` | `class-tix-sync.php` | WooCommerce-Produkt-Sync, Breakdance-Meta-Generierung |
| `TIX_Settings` | `class-tix-settings.php` | Settings-Seite (11 Tabs), Design-Tokens, CSS-Ausgabe |
| `TIX_Columns` | `class-tix-columns.php` | Admin-Spalten, Event-Duplizierung, CSV-Export |
| `TIX_Frontend` | `class-tix-frontend.php` | Cron-Jobs, Open-Graph/JSON-LD, Dashboard-Widget |
| `TIX_Checkout` | `class-tix-checkout.php` | Checkout-Shortcode, Warenkorb, Abandoned Cart |
| `TIX_Ticket_Selector` | `class-tix-ticket-selector.php` | Ticket-Selector, Express-Modal, Countdown, Low-Stock-Badge, Presale-Countdown |
| `TIX_Calendar` | `class-tix-calendar.php` | Kalender-Shortcode, iCal- und Google-Calendar-Export |
| `TIX_Checkin` | `class-tix-checkin.php` | QR-Scanner, Gaesteliste, Check-in-Seite |
| `TIX_Emails` | `class-tix-emails.php` | White-Label E-Mails, Reminder, Followup, Abandoned Cart, Feedback-Sterne |
| `TIX_FAQ` | `class-tix-faq.php` | FAQ-Shortcode |
| `TIX_My_Tickets` | `class-tix-my-tickets.php` | Meine-Tickets-Shortcode |
| `TIX_Upsell` | `class-tix-upsell.php` | Zusatzprodukte-Shortcode |
| `TIX_Tickets` | `class-tix-tickets.php` | Eigenes Ticketsystem (CPT, PDF-Generierung, QR-Code) |
| `TIX_Ticket_Template` | `class-tix-ticket-template.php` | Visueller Ticket-Template-Editor, GD-Rendering |
| `TIX_Ticket_Template_CPT` | `class-tix-ticket-template-cpt.php` | Ticket-Vorlagen als wiederverwendbarer CPT |
| `TIX_Ticket_Transfer` | `class-tix-ticket-transfer.php` | Ticket-Transfer und Umschreibung |
| `TIX_Group_Booking` | `class-tix-group-booking.php` | Gruppenbuchung mit Kostenaufteilung |
| `TIX_Group_Discount` | `class-tix-group-discount.php` | Gruppenrabatte, Bundle-Deals, Kombi-Tickets |
| `TIX_Dynamic_Pricing` | `class-tix-dynamic-pricing.php` | Dynamische Preisphasen (Early Bird, Last Minute etc.) |
| `TIX_Series` | `class-tix-series.php` | Serientermine (Recurring Events) |
| `TIX_Embed` | `class-tix-embed.php` | Embed-Widget fuer externe Webseiten |
| `TIX_Content_Guard` | `class-tix-content-guard.php` | KI-Inhaltspruefung (Anthropic Claude API) |
| `TIX_AI_Writer` | `class-tix-ai-writer.php` | KI-Assistent (Textgenerierung, Feld-Extraktion aus Bild/URL) |
| `TIX_Event_Templates` | `class-tix-event-templates.php` | Event-Vorlagen (Tab-Steuerung, Defaults, Admin-Seite) |
| `TIX_Cleanup` | `class-tix-cleanup.php` | Loeschutz, Orphan-Cleanup, Event-Daten-Purge |
| `TIX_Support` | `class-tix-support.php` | Support-System (CRM + Kunden-Portal) |
| `TIX_Docs` | `class-tix-docs.php` | Interaktive Dokumentation im Admin-Bereich |
| `TIX_Event_Page` | `class-tix-event-page.php` | Dynamische Event-Detailseite (1col/2col Layout) |
| `TIX_Seatmap` | `class-tix-seatmap.php` | Saalplan-System (Admin-Editor + Frontend-Auswahl) |
| `TIX_Raffle` | `class-tix-raffle.php` | Gewinnspiel-System (Teilnahme, Auslosung, Gewinner) |
| `TIX_Waitlist` | `class-tix-waitlist.php` | Warteliste + Presale-Benachrichtigungen |
| `TIX_Feedback` | `class-tix-feedback.php` | Post-Event Feedback (Sterne-Bewertung + Kommentar) |
| `TIX_Timetable` | `class-tix-timetable.php` | Timetable / Programm (Multi-Stage, Grid + Liste) |
| `TIX_Statistics` | `class-tix-statistics.php` | Verkaufsstatistiken im Admin |
| `TIX_Ticket_DB` | `class-tix-ticket-db.php` | Custom Ticket-Datenbank-Tabelle |
| `TIX_Sync_Supabase` | `class-tix-sync-supabase.php` | Supabase-Synchronisierung |
| `TIX_Sync_Airtable` | `class-tix-sync-airtable.php` | Airtable-Synchronisierung |
| `TIX_Promoter` | `class-tix-promoter.php` | Promoter-System (Tracking + Provisionen) |
| `TIX_Promoter_DB` | `class-tix-promoter-db.php` | Promoter-Datenbank-Tabellen |
| `TIX_Organizer_Dashboard` | `class-tix-organizer-dashboard.php` | Veranstalter-Dashboard (Frontend-Shortcode) |

---

## 2. Dateistruktur

```
tixomat/
├── tixomat.php                           Hauptdatei (Plugin-Bootstrap)
├── includes/
│   ├── class-tix-cpt.php                 Custom Post Types & Taxonomien
│   ├── class-tix-metabox.php             Event-Metabox mit 12 Tabs
│   ├── class-tix-sync.php                WooCommerce-Sync
│   ├── class-tix-settings.php            Einstellungen (11 Tabs)
│   ├── class-tix-columns.php             Admin-Spalten & Export
│   ├── class-tix-frontend.php            Cron, OG, JSON-LD, Dashboard
│   ├── class-tix-checkout.php            Checkout-System
│   ├── class-tix-ticket-selector.php     Ticket-Auswahl (Low-Stock, Presale-Countdown)
│   ├── class-tix-calendar.php            Kalender-Shortcode
│   ├── class-tix-checkin.php             Check-in & QR-Scanner
│   ├── class-tix-emails.php              E-Mail-System (+ Feedback-Sterne)
│   ├── class-tix-faq.php                 FAQ-Shortcode
│   ├── class-tix-my-tickets.php          Meine-Tickets-Seite
│   ├── class-tix-upsell.php              Zusatzprodukte
│   ├── class-tix-tickets.php             Eigenes Ticketsystem
│   ├── class-tix-ticket-template.php     Ticket-Template-Editor
│   ├── class-tix-ticket-template-cpt.php Ticket-Vorlagen CPT
│   ├── class-tix-ticket-transfer.php     Ticket-Transfer
│   ├── class-tix-group-booking.php       Gruppenbuchung
│   ├── class-tix-group-discount.php      Gruppenrabatte
│   ├── class-tix-dynamic-pricing.php     Dynamische Preisphasen
│   ├── class-tix-series.php              Serientermine
│   ├── class-tix-embed.php               Embed-Widget
│   ├── class-tix-content-guard.php       KI-Schutz (Content Moderation via Claude API)
│   ├── class-tix-ai-writer.php          KI-Assistent (Textgenerierung, Bild/URL-Analyse)
│   ├── class-tix-event-templates.php    Event-Vorlagen (Tab-Steuerung + Defaults)
│   ├── class-tix-cleanup.php             Loeschutz, Cleanup & Event-Daten-Purge
│   ├── class-tix-support.php             Support-System (CRM + Kunden-Portal)
│   ├── class-tix-docs.php                Admin-Dokumentation
│   ├── class-tix-event-page.php          Event-Detailseite (1col/2col + Share + Rating)
│   ├── class-tix-seatmap.php             Saalplan-System
│   ├── class-tix-raffle.php              Gewinnspiel (Teilnahme + Auslosung)
│   ├── class-tix-waitlist.php            Warteliste + Presale-Benachrichtigungen
│   ├── class-tix-feedback.php            Post-Event Feedback (Sterne + Kommentar)
│   ├── class-tix-timetable.php           Timetable / Programm (Multi-Stage)
│   ├── class-tix-statistics.php          Verkaufsstatistiken
│   ├── class-tix-ticket-db.php           Custom Ticket-DB
│   ├── class-tix-sync-supabase.php       Supabase-Sync
│   ├── class-tix-sync-airtable.php       Airtable-Sync
│   ├── class-tix-promoter.php            Promoter-System
│   ├── class-tix-promoter-db.php         Promoter-Datenbank
│   ├── class-tix-promoter-admin.php      Promoter-Admin (Menue + Verwaltung)
│   ├── class-tix-promoter-dashboard.php  Promoter-Dashboard (Frontend)
│   ├── class-tix-organizer-dashboard.php Veranstalter-Dashboard (Frontend)
│   ├── class-tix-rest-api.php           REST API (tixomat/v1, 24 Endpoints)
│   ├── class-tix-admin-shell.php        Admin Shell (Fullscreen-Sidebar)
│   ├── class-tix-organizer-admin.php   Organizer Admin (Capabilities, Ownership, Admin-Seiten)
│   ├── class-tix-custom-urls.php       Custom Login & Organizer URLs
│   ├── class-tix-cart.php               Warenkorb & Mini-Cart
│   ├── class-tix-table-reservation.php  Tischreservierung
│   ├── class-tix-pos.php               POS / Abendkasse
│   ├── class-tix-specials.php           Specials / Zusatzprodukte CPT
├── assets/
│   ├── css/                              CSS-Dateien (admin-shell, event-page, ticket-selector, ...)
│   ├── js/                               JS-Dateien (admin-shell, event-page, feedback, ...)
│   └── fonts/
│       ├── OpenSans-Regular.ttf
│       ├── OpenSans-Bold.ttf
│       └── RobotoMono-Regular.ttf
```

---

## 3. Custom Post Types & Taxonomien

### Custom Post Types

Tixomat registriert insgesamt 6 Custom Post Types:

| CPT-Slug | Label | Oeffentlich | Beschreibung |
|---|---|---|---|
| `event` | Events | Ja | Hauptobjekt -- eine Veranstaltung mit allen zugehoerigen Daten |
| `tix_location` | Locations | Ja | Veranstaltungsorte mit Adresse und Beschreibung |
| `tix_organizer` | Veranstalter | Ja | Veranstalter mit Kontaktdaten |
| `tix_ticket` | Tickets | Nein | Eigene Tickets (QR-Code, PDF) -- nur bei aktiviertem eigenem Ticketsystem |
| `tix_subscriber` | Newsletter | Nein | Newsletter-Abonnenten |
| `tix_abandoned_cart` | Warenkorb-Abbrecher | Nein | Abgebrochene Warenkoerbe fuer Recovery-E-Mails |
| `tix_ticket_tpl` | Ticket-Vorlagen | Nein | Wiederverwendbare Ticket-Vorlagen mit visuellem Editor |
| `tix_app_tip` | App-Tipps | Nein | „Tipp des Tages“ für die Apps (nur Admins, `manage_options`), REST `GET /public/tips`, Verwaltung aus den Apps `GET/POST /app/tips…` (nur Admins) -- siehe App-Vertrag |

### tix_ticket_tpl (Ticket-Vorlagen)
- `_tix_template_config` (JSON) -- Template-Konfiguration (gleiche Struktur wie ticket_template in Settings)

### Taxonomien

| Taxonomie-Slug | Zugeordnet zu | Beschreibung |
|---|---|---|
| `event_category` | `event` | Event-Kategorien zur Klassifizierung von Veranstaltungen |

### Registrierung

Die CPTs werden in `TIX_CPT::register_post_types()` registriert. Der CPT `event` unterstuetzt:

- `title`, `editor`, `thumbnail`, `excerpt`, `custom-fields`
- Hat ein eigenes Admin-Menue-Icon
- Rewrites mit anpassbarem Slug
- `show_in_rest = true` fuer Gutenberg-Kompatibilitaet

Die CPTs `tix_location` und `tix_organizer` werden mit eigenen Metaboxen im Admin angezeigt (verwaltet durch `TIX_CPT`).

---

## 4. Ticket-System (Dual-Mode)

Tixomat bietet ein flexibles Dual-Mode-Ticketsystem, das ueber die Einstellung `tix_ticket_system` konfiguriert wird.

### Modi

| Modus | Wert | Beschreibung |
|---|---|---|
| Standalone | `standalone` | Nutzt ausschliesslich das eigene Tixomat-Ticketsystem |

### Eigenes Ticketsystem (`TIX_Tickets`)

Der CPT `tix_ticket` speichert individuelle Tickets mit folgenden Merkmalen:

- **QR-Code-Generierung:** Jedes Ticket erhaelt einen eindeutigen QR-Code im Format `GL-{EVENT_ID}-{CODE}`.
- **PDF-Rendering:** Tickets werden als PDF-Dateien generiert, optional mit visuellem Template (siehe Abschnitt 5).
- **Ticket-Code:** 12-stelliger alphanumerischer Code (Charset: `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, ca. 1,7 x 10^18 Kombinationen).
- **Download-URLs:** Kryptische 64-Zeichen-Hex-Tokens (`?tix_dl=TOKEN`). Alte URLs (`?tix_ticket_code=...&tix_ticket_key=...`) bleiben rueckwaertskompatibel.
- **Status-Verwaltung:** Tickets haben Statuswerte wie `valid`, `used`, `cancelled`, `transferred`.

### Ticket-Erstellung

Tickets werden automatisch nach erfolgreichem WooCommerce-Checkout erstellt:

1. WooCommerce-Bestellung wird als `completed` markiert.
2. `TIX_Tickets` iteriert ueber die Bestellpositionen.
3. Pro Ticket-Einheit wird ein `tix_ticket`-Post erstellt.
4. QR-Code wird generiert und als Meta gespeichert.
5. Optional wird ein PDF mit dem konfigurierten Template gerendert.

### Helper-Funktionen

```php
tix_use_own_tickets()  // Gibt true zurueck wenn standalone oder both aktiv
```

---

## 5. Ticket-Template-System

`TIX_Ticket_Template` bietet einen visuellen Editor zur Gestaltung von Ticket-Layouts.

### Verfuegbare Felder

Das Template-System unterstuetzt 14 positionierbare Felder:

| Feld-Slug | Bezeichnung | Beschreibung |
|---|---|---|
| `event_name` | Event-Name | Titel der Veranstaltung |
| `event_date` | Event-Datum | Formatiertes Datum der Veranstaltung |
| `event_time` | Event-Uhrzeit | Beginn-Uhrzeit |
| `event_doors` | Einlass | Einlass-Uhrzeit |
| `event_location` | Location | Name des Veranstaltungsorts |
| `event_address` | Adresse | Adresse des Veranstaltungsorts |
| `cat_name` | Kategorie | Name der Ticket-Kategorie |
| `price` | Preis | Ticket-Preis (formatiert) |
| `owner_name` | Inhaber-Name | Name des Ticket-Inhabers |
| `owner_email` | Inhaber-E-Mail | E-Mail des Ticket-Inhabers |
| `ticket_code` | Ticket-Code | Eindeutiger 12-stelliger alphanumerischer Code |
| `order_id` | Bestell-Nr. | WooCommerce-Bestell-ID |
| `seat` | Sitzplatz | Zugewiesener Sitzplatz (bei Saalplan) |
| `qr_code` | QR-Code | QR-Code-Bild mit dem Ticket-Code |
| `barcode` | Strichcode | Code128-B Barcode fuer Handscanner |
| `custom_text` | Eigener Text | Frei definierbarer Text |

### GD-basiertes Rendering

Die Ticket-Bilder und PDFs werden mit der PHP-GD-Bibliothek gerendert:

- Hintergrundbild wird als Basis verwendet.
- Felder werden an den konfigurierten X/Y-Positionen gerendert.
- Schriftart, Schriftgroesse und Farbe sind pro Feld konfigurierbar.
- Verfuegbare Schriftarten: `OpenSans-Regular.ttf`, `OpenSans-Bold.ttf`, `RobotoMono-Regular.ttf`.
- Ausgabe als PNG-Bild oder PDF.

### Template-Modi

| Modus | Beschreibung |
|---|---|
| `global` | Verwendet das globale Template aus den Plugin-Einstellungen |
| `template` | Verwendet eine gespeicherte Ticket-Vorlage (CPT `tix_ticket_tpl`) |
| `custom` | Verwendet ein individuelles Template, das pro Event konfiguriert wird |
| `none` | Kein visuelles Template -- Standard-Ticket ohne Hintergrundbild |

### Konfiguration

- **Globales Template:** Wird in den Plugin-Einstellungen unter dem Tab "Ticket-Template" konfiguriert.
- **Ticket-Vorlagen CPT:** Wiederverwendbare Vorlagen unter tixomat → Ticket-Vorlagen erstellen und per Dropdown im Event zuweisen.
- **Pro-Event-Override:** Im Event-Editor kann der Modus auf `global`, `template`, `custom` oder `none` gesetzt werden.
- **Template-Priorisierung:** Vorlage (CPT) > Eigene Vorlage (Inline) > Globale Einstellungen.
- **Drag & Drop:** Felder koennen per Drag & Drop auf der Ticket-Vorschau positioniert werden.
- **QR/Barcode:** Proportionale Groessenaenderung (Seitenverhaeltnis bleibt erhalten).
- **Feintuning:** Exakte X/Y-Koordinaten, Schriftgroesse und Farbe koennen numerisch eingegeben werden.
- **Ausgabeformate:** PDF-Download und Bild-Darstellung (PNG).

### Template-Editor Properties (v1.28.0)
| Property | Typ | Default | Range | Gilt fuer |
|---|---|---|---|---|
| letter_spacing | int | 0 | -5 bis 50 | Text |
| line_height | float | 1.4 | 0.8 bis 3.0 | Text |
| rotation | int | 0 | -180 bis 180 | Alle |
| opacity | float | 1.0 | 0.0 bis 1.0 | Alle |
| bg_color | string | '' | Hex/leer | Alle |
| border_color | string | '' | Hex/leer | Alle |
| border_width | int | 0 | 0 bis 10 | Alle |
| padding | int | 0 | 0 bis 50 | Text |
| text_transform | string | 'none' | none/uppercase/lowercase | Text |

### Ticket-Download-URLs (v1.28.0)
Format: `?tix_dl={64-char-hex-token}`
Token wird bei Ticket-Erstellung generiert und als `_tix_ticket_download_token` gespeichert.
Alte URLs (`?tix_ticket_code=...&tix_ticket_key=...`) funktionieren weiterhin.

---

## 6. Metabox-Felder (Admin)

Die Event-Metabox (`TIX_Metabox`) bietet zwei Modi: den Tab-Modus (9 Tabs) und den Wizard-Modus (5 Schritte).

### Tab-Modus (9 Tabs)

#### Tab 1: Details

Grundlegende Event-Informationen:

- Event-Datum und Uhrzeit (Beginn, Ende, Einlass)
- Enddatum (optional, fuer mehrtaegige Events)
- Event-Status
- Location (Auswahl aus `tix_location` CPT)
- Veranstalter (Auswahl aus `tix_organizer` CPT)
- Vorverkaufseinstellungen (Modus, Datum, Offset)

#### Tab 2: Info

Erweiterte Informationen:

- Kurzinfo / Untertitel
- Altersfreigabe
- Zusatzinformationen
- Hinweise

#### Tab 3: Tickets

Ticket-Kategorien-Verwaltung:

- Dynamischer Repeater fuer Ticket-Kategorien
- Pro Kategorie: Name, Preis, Menge, Beschreibung, Sortierung
- Gruppenrabatt-Einstellungen pro Kategorie
- Dynamische Preisphasen-Konfiguration

#### Tab 4: Media

Medienverwaltung:

- Event-Flyer (Bild-Upload)
- Kuenstler-/Programm-Bilder
- Galerie

#### Tab 5: FAQ

Event-spezifische FAQ:

- Dynamischer Repeater fuer Fragen und Antworten
- Sortierung per Drag & Drop

#### Tab 6: Zusatzprodukte

Zusatzprodukte-Konfiguration:

- Zugehoerige Events/Produkte
- Zusatzprodukte-Texte

#### Tab 7: Series

Serientermin-Konfiguration:

- Muster-Modus (woechentlich, 14-taegig etc.)
- Manueller Modus (einzelne Termine)
- Ausnahmen

#### Tab 8: Gaesteliste

Gaesteliste-Verwaltung:

- Gaestelisten-Eintraege hinzufuegen/bearbeiten
- Import-Funktionen

#### Tab 9: Erweitert

Erweiterte Einstellungen:

- Breakdance-Template-Override
- Eigene CSS-Klassen
- JSON-LD-Override
- Ticket-Template-Modus (global/template/custom/none)

### Wizard-Modus (5 Schritte)

Der Wizard-Modus fuehrt den Benutzer in 5 Schritten durch die Event-Erstellung:

| Schritt | Inhalt |
|---|---|
| 1 | Grunddaten (Titel, Datum, Uhrzeit, Location) |
| 2 | Ticket-Kategorien erstellen |
| 3 | Beschreibung und Info |
| 4 | Medien hochladen |
| 5 | Zusammenfassung und Veroeffentlichung |

---

## 7. Alle Event-Meta-Keys

### Basisdaten

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_date` | `string` (Y-m-d) | Event-Datum |
| `_tix_time` | `string` (H:i) | Event-Beginn (Uhrzeit) |
| `_tix_doors` | `string` (H:i) | Einlass-Uhrzeit |
| `_tix_end_date` | `string` (Y-m-d) | Enddatum (mehrtaegige Events) |
| `_tix_end_time` | `string` (H:i) | End-Uhrzeit |
| `_tix_status` | `string` | Event-Status (available, sold_out, past, presale_closed, cancelled, postponed) |
| `_tix_location` | `int` | Post-ID der zugehoerigen Location (`tix_location`) |
| `_tix_organizer` | `int` | Post-ID des zugehoerigen Veranstalters (`tix_organizer`) |

### Vorverkauf

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_presale_mode` | `string` | Vorverkaufsmodus: `manual`, `date`, `offset` |
| `_tix_presale_date` | `string` (Y-m-d) | Vorverkaufsende-Datum (Modus `date`) |
| `_tix_presale_time` | `string` (H:i) | Vorverkaufsende-Uhrzeit (Modus `date`) |
| `_tix_presale_offset` | `int` | Offset in Stunden vor Event-Beginn (Modus `offset`) |
| `_tix_presale_closed` | `string` (0/1) | Vorverkauf manuell geschlossen |

### Ticket-Kategorien

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_tickets` | `array` | Array aller Ticket-Kategorien (serialisiert) |

Jede Ticket-Kategorie im Array enthaelt:

| Schluessel | Typ | Beschreibung |
|---|---|---|
| `name` | `string` | Kategorie-Name (z.B. "VIP", "Standard") |
| `price` | `float` | Preis in Euro |
| `qty` | `int` | Verfuegbare Menge |
| `sold` | `int` | Bereits verkaufte Menge |
| `desc` | `string` | Beschreibung der Kategorie |
| `sort` | `int` | Sortierung |
| `wc_variation_id` | `int` | Zugehoerige WooCommerce-Variations-ID |

### Info & Beschreibung

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_subtitle` | `string` | Kurzinfo / Untertitel |
| `_tix_age_rating` | `string` | Altersfreigabe |
| `_tix_additional_info` | `string` | Zusaetzliche Informationen |
| `_tix_notes` | `string` | Hinweise |

### Media

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_flyer` | `int` | Attachment-ID des Event-Flyers |
| `_tix_artist_images` | `array` | Array von Attachment-IDs (Kuenstler-Bilder) |
| `_tix_gallery` | `array` | Array von Attachment-IDs (Galerie) |

### FAQ

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_faq` | `array` | Array von FAQ-Eintraegen (jeweils `question` und `answer`) |

### Zusatzprodukte

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_upsell_events` | `array` | Array von Event-IDs fuer Zusatzprodukte |
| `_tix_upsell_products` | `array` | Array von WC-Produkt-IDs |
| `_tix_upsell_text` | `string` | Zusatzprodukte-Beschreibungstext |

### WooCommerce-Sync

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_wc_product_id` | `int` | Zugehoerige WooCommerce-Produkt-ID |

### Serientermine

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_series_enabled` | `string` (0/1) | Serientermine aktiviert |
| `_tix_series_mode` | `string` | Modus: `pattern` oder `manual` |
| `_tix_series_pattern` | `string` | Muster (z.B. `weekly`, `biweekly`, `monthly`) |
| `_tix_series_end_date` | `string` (Y-m-d) | Ende der Serie |
| `_tix_series_manual_dates` | `array` | Manuell definierte Termine |
| `_tix_series_exceptions` | `array` | Ausnahme-Termine |
| `_tix_series_parent` | `int` | Parent-Event-ID (bei Kind-Events) |
| `_tix_series_children` | `array` | Kind-Event-IDs (beim Eltern-Event) |

### Dynamische Preisphasen

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_dynamic_pricing` | `array` | Array der Preisphasen pro Ticket-Kategorie |

### Gruppenrabatte

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_group_discounts` | `array` | Gruppenrabatt-Konfiguration |
| `_tix_combo_tickets` | `array` | Kombi-Ticket-Konfiguration |
| `_tix_bundle_deals` | `array` | Bundle-Deals-Konfiguration |

### Gruppenbuchung

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_group_booking_enabled` | `string` (0/1) | Gruppenbuchung aktiviert |
| `_tix_group_booking_min` | `int` | Mindestanzahl Teilnehmer |
| `_tix_group_booking_max` | `int` | Maximalanzahl Teilnehmer |
| `_tix_group_booking_split` | `string` (0/1) | Kostenaufteilung aktiviert |

### Gaesteliste

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_guestlist` | `array` | Gaestelisten-Eintraege |

### Ticket-Template

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_ticket_template_mode` | `string` | Template-Modus: `global`, `template`, `custom`, `none` |
| `_tix_ticket_template_id` | `int` | ID der gewaehlten Ticket-Vorlage CPT (bei Modus `template`) |
| `_tix_ticket_template` | `array` | Individuelle Template-Konfiguration (bei `custom`) |
| `_tix_ticket_template_bg` | `int` | Attachment-ID des Hintergrundbildes |

### Erweitert

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_custom_css_class` | `string` | Eigene CSS-Klassen fuer das Event |
| `_tix_breakdance_template` | `int` | Breakdance-Template-Override |
| `_tix_jsonld_override` | `string` | JSON-LD-Override (manuelles Schema) |
| `_tix_embed_enabled` | `string` (0/1) | Embed-Modus fuer dieses Event aktiviert |
| `_tix_express_checkout` | `string` (0/1) | Express-Checkout fuer dieses Event aktiviert |
| `_tix_newsletter_enabled` | `string` (0/1) | Newsletter-Anmeldung beim Checkout anbieten |

### KI-Schutz (Content Guard)

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_ai_approved` | `string` (0/1) | Event wurde von KI-Pruefung genehmigt |
| `_tix_ai_flagged` | `string` (0/1) | Event wurde von KI-Pruefung abgelehnt |
| `_tix_ai_flag_reason` | `string` | Begruendung der Ablehnung (deutsch) |
| `_tix_ai_content_hash` | `string` (md5) | Hash des zuletzt geprueften Contents (Cache) |
| `_tix_ai_checked_at` | `int` (Unix) | Zeitstempel der letzten KI-Pruefung |

### Breakdance-Meta (Dynamic Data)

Alle oben genannten Meta-Keys stehen als Dynamic Data im Breakdance Page Builder zur Verfuegung. `TIX_Sync` generiert zusaetzlich aufbereitete Meta-Felder mit dem Praefix `_tix_bd_*` fuer die direkte Anzeige.

---

## 8. Location & Organizer Meta

### Location (`tix_location`)

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_loc_address` | `string` | Vollstaendige Adresse des Veranstaltungsorts |
| `_tix_loc_description` | `string` | Beschreibung der Location |

### Veranstalter (`tix_organizer`)

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_org_address` | `string` | Adresse des Veranstalters |
| `_tix_org_description` | `string` | Beschreibung des Veranstalters |
| `_tix_org_user_id` | `int` | Verknuepfter WP-User fuer Veranstalter-Dashboard Login |

---

## 9. Subscriber & Abandoned Cart Meta

### Newsletter-Abonnent (`tix_subscriber`)

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_sub_email` | `string` | E-Mail-Adresse des Abonnenten |
| `_tix_sub_name` | `string` | Name des Abonnenten |
| `_tix_sub_event_id` | `int` | Event-ID, bei dem die Anmeldung erfolgte |
| `_tix_sub_date` | `string` | Anmeldedatum |
| `_tix_sub_status` | `string` | Status: `active`, `unsubscribed` |
| `_tix_sub_source` | `string` | Quelle der Anmeldung (checkout, widget, shortcode) |

### Abandoned Cart (`tix_abandoned_cart`)

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_ac_email` | `string` | E-Mail-Adresse des Kunden |
| `_tix_ac_name` | `string` | Name des Kunden |
| `_tix_ac_cart_data` | `array` | Serialisierte Warenkorb-Daten |
| `_tix_ac_event_id` | `int` | Zugehoeriges Event |
| `_tix_ac_created` | `string` | Erstellungszeitpunkt |
| `_tix_ac_status` | `string` | Status: `pending`, `sent`, `recovered`, `expired` |
| `_tix_ac_recovery_url` | `string` | URL zur Warenkorb-Wiederherstellung |
| `_tix_ac_emails_sent` | `int` | Anzahl gesendeter Recovery-E-Mails |
| `_tix_ac_last_email` | `string` | Zeitpunkt der letzten E-Mail |

---

## 10. Ticket Meta (tix_ticket CPT)

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_ticket_code` | `string` | Eindeutiger 12-stelliger alphanumerischer Ticket-Code |
| `_tix_ticket_event_id` | `int` | Zugehoeriges Event |
| `_tix_ticket_order_id` | `int` | WooCommerce-Bestell-ID |
| `_tix_ticket_cat_name` | `string` | Name der Ticket-Kategorie |
| `_tix_ticket_price` | `float` | Bezahlter Preis |
| `_tix_ticket_owner_name` | `string` | Name des Ticket-Inhabers |
| `_tix_ticket_owner_email` | `string` | E-Mail des Ticket-Inhabers |
| `_tix_ticket_status` | `string` | Status: `valid`, `checked_in`, `cancelled`, `transferred` |
| `_tix_ticket_checkin_time` | `string` | Zeitpunkt des Check-ins |
| `_tix_ticket_qr_code` | `string` | QR-Code-Daten (Base64 oder Dateipfad) |
| `_tix_ticket_pdf_path` | `string` | Pfad zur generierten PDF-Datei |
| `_tix_ticket_template_id` | `int` | Verwendetes Ticket-Template |
| `_tix_ticket_transferred_from` | `int` | Urspruengliche Ticket-ID (bei Transfer) |
| `_tix_ticket_transferred_to` | `int` | Neue Ticket-ID (bei Transfer) |
| `_tix_ticket_download_token` | `string` | Kryptisches 64-Zeichen Hex-Token fuer sichere Download-URLs |
| `_tix_ticket_template_id` | `int` | ID der gewaehlten Ticket-Vorlage (CPT) wenn Modus = "template" |

---

## 11. Event-Status-System

Jedes Event hat einen Status, der den aktuellen Zustand bestimmt. Der Status wird ueber `_tix_status` gespeichert und kann manuell oder automatisch (per Cron) gesetzt werden.

| Status | Slug | Beschreibung | Badge-Farbe |
|---|---|---|---|
| Verfuegbar | `available` | Tickets sind erhaeltlich, Event liegt in der Zukunft | Gruen |
| Ausverkauft | `sold_out` | Alle Tickets sind verkauft | Rot |
| Vergangen | `past` | Event-Datum liegt in der Vergangenheit | Grau |
| VVK geschlossen | `presale_closed` | Vorverkauf ist beendet, aber Event noch nicht vorbei | Orange |
| Abgesagt | `cancelled` | Event wurde abgesagt | Rot |
| Verschoben | `postponed` | Event wurde auf unbestimmte Zeit verschoben | Gelb |

### Automatische Status-Aenderungen

Der Cron-Job `tix_presale_check` prueft regelmaessig:

1. **past:** Wenn `_tix_date` + `_tix_end_time` in der Vergangenheit liegt.
2. **sold_out:** Wenn alle Ticket-Kategorien ausverkauft sind.
3. **presale_closed:** Wenn das Vorverkaufsende erreicht ist (je nach Presale-Modus).

---

## 12. Vorverkauf-System (Presale)

Das Vorverkaufssystem steuert, wann der Ticketverkauf endet. Drei Modi stehen zur Verfuegung:

### Modi

| Modus | Slug | Beschreibung |
|---|---|---|
| Manuell | `manual` | Vorverkauf wird manuell ueber `_tix_presale_closed` gesteuert |
| Datum | `date` | Vorverkauf endet an einem festen Datum (`_tix_presale_date` + `_tix_presale_time`) |
| Offset | `offset` | Vorverkauf endet X Stunden vor Event-Beginn (`_tix_presale_offset`) |

### Funktionsweise

- **Manuell:** Der Administrator setzt `_tix_presale_closed` auf `1`, um den Vorverkauf zu beenden.
- **Datum:** Der Cron-Job vergleicht das aktuelle Datum mit `_tix_presale_date` und `_tix_presale_time`. Bei Ueberschreitung wird der Status auf `presale_closed` gesetzt.
- **Offset:** Der Cron-Job berechnet `_tix_date` + `_tix_time` minus `_tix_presale_offset` Stunden. Ist dieser Zeitpunkt erreicht, wird der Vorverkauf geschlossen.

---

## 13. Dynamische Preisphasen

`TIX_Dynamic_Pricing` ermoeglicht zeitbasierte Preisanpassungen fuer jede Ticket-Kategorie.

### Konfiguration

Preisphasen werden im Meta-Key `_tix_dynamic_pricing` als Array gespeichert. Jede Phase enthaelt:

| Schluessel | Typ | Beschreibung |
|---|---|---|
| `name` | `string` | Phasen-Name (z.B. "Early Bird", "Last Minute") |
| `price` | `float` | Preis in dieser Phase |
| `start_date` | `string` (Y-m-d H:i) | Beginn der Phase |
| `end_date` | `string` (Y-m-d H:i) | Ende der Phase |
| `cat_index` | `int` | Index der zugehoerigen Ticket-Kategorie |

### Preisermittlung

Die Preisermittlung folgt dieser Prioritaet:

1. Aktive dynamische Preisphase (zeitlich passend).
2. Gruppenrabatt (falls Mindestmenge erreicht).
3. Standard-Preis aus der Ticket-Kategorie.

### Anzeige

Im Ticket-Selector wird der aktuelle Phasen-Name und -Preis angezeigt. Wenn eine Phase bald endet, kann optional ein Countdown dargestellt werden.

---

## 14. Serientermine

`TIX_Series` ermoeglicht die Erstellung wiederkehrender Veranstaltungen. Ein Eltern-Event dient als Vorlage, aus der Kind-Events generiert werden.

### Pattern-Modus

| Muster | Beschreibung |
|---|---|
| `weekly` | Woechentlich am gleichen Wochentag |
| `biweekly` | Alle zwei Wochen |
| `monthly` | Monatlich am gleichen Tag |

Konfiguration:

- `_tix_series_pattern`: Gewaehltes Muster
- `_tix_series_end_date`: Enddatum der Serie
- `_tix_series_exceptions`: Termine, die uebersprungen werden

### Manueller Modus

Im manuellen Modus werden einzelne Termine explizit angegeben:

- `_tix_series_manual_dates`: Array von Datumsangaben (Y-m-d)

### Kind-Events

- Kind-Events erben alle Meta-Daten des Eltern-Events.
- Jedes Kind-Event erhaelt ein eigenes Datum und eigene WooCommerce-Produkte.
- `_tix_series_parent` verweist auf das Eltern-Event.
- `_tix_series_children` am Eltern-Event listet alle Kind-Event-IDs.
- Kind-Events koennen individuell angepasst werden (ueberschreibt die geerbten Daten).

---

## 15. Shortcodes

Tixomat stellt 17 Shortcodes zur Verfuegung:

| Shortcode | Klasse | Parameter | Beschreibung |
|---|---|---|---|
| `[tix_ticket_selector]` | `TIX_Ticket_Selector` | `id` (Event-ID) | Ticket-Auswahl mit Preisberechnung und Warenkorb-Button |
| `[tix_checkout]` | `TIX_Checkout` | -- | Vollstaendiger Checkout-Prozess (ersetzt WC-Checkout) |
| `[tix_calendar]` | `TIX_Calendar` | `category`, `view` (month/list) | Veranstaltungskalender |
| `[tix_checkin]` | `TIX_Checkin` | `id` (Event-ID) | Check-in-Seite mit QR-Scanner |
| `[tix_faq]` | `TIX_FAQ` | `id` (Event-ID) | FAQ-Akkordeon |
| `[tix_my_tickets]` | `TIX_My_Tickets` | -- | Meine-Tickets-Seite fuer eingeloggte Nutzer |
| `[tix_upsell]` | `TIX_Upsell` | `id` (Event-ID) | Zusatzprodukte-Bereich |
| `[tix_embed]` | `TIX_Embed` | `id` (Event-ID), `style` | Embed-Widget fuer externe Seiten |
| `[tix_newsletter]` | `TIX_Emails` | `event_id`, `label` | Newsletter-Anmeldeformular |
| `[tix_countdown]` | `TIX_Ticket_Selector` | `id` (Event-ID), `style` | Countdown bis zum Event |
| `[tix_group_booking]` | `TIX_Group_Booking` | `id` (Event-ID) | Gruppenbuchungs-Formular |
| `[tix_event_page]` | `TIX_Event_Page` | `id` (Event-ID) | Komplette Event-Detailseite (1col/2col Layout) |
| `[tix_raffle]` | `TIX_Raffle` | `id` (Event-ID) | Gewinnspiel-Formular mit Countdown und Gewinnerliste |
| `[tix_feedback]` | `TIX_Feedback` | `id` (Event-ID) | Feedback-Formular (Sterne + Kommentar) oder oeffentliche Bewertung |
| `[tix_timetable]` | `TIX_Timetable` | `id` (Event-ID) | Mehrtaegiges Programm mit Buehnen-Grid |
| `[tix_series_dates]` | `TIX_Series` | `id` (Event-ID) | Serientermin-Uebersicht |
| `[tix_organizer_dashboard]` | `TIX_Organizer_Dashboard` | -- | Frontend-Dashboard fuer Veranstalter (Event-CRUD, Bestellungen, Check-In, Statistiken) |

---

## 16. AJAX-Endpoints

### Admin-Endpoints (erfordern `manage_options`)

| Action | Klasse | Beschreibung |
|---|---|---|
| `tix_save_metabox` | `TIX_Metabox` | Speichert Event-Metabox-Daten |
| `tix_add_ticket_cat` | `TIX_Metabox` | Fuegt eine neue Ticket-Kategorie hinzu |
| `tix_remove_ticket_cat` | `TIX_Metabox` | Entfernt eine Ticket-Kategorie |
| `tix_sort_ticket_cats` | `TIX_Metabox` | Sortiert Ticket-Kategorien |
| `tix_add_faq_item` | `TIX_Metabox` | Fuegt einen FAQ-Eintrag hinzu |
| `tix_remove_faq_item` | `TIX_Metabox` | Entfernt einen FAQ-Eintrag |
| `tix_save_settings` | `TIX_Settings` | Speichert Plugin-Einstellungen |
| `tix_sync_event` | `TIX_Sync` | Manueller Sync eines Events mit WooCommerce |
| `tix_checkin_ticket` | `TIX_Checkin` | Fuehrt einen Ticket-Check-in durch |
| `tix_guestlist_add` | `TIX_Checkin` | Fuegt einen Gaestelisten-Eintrag hinzu |
| `tix_guestlist_remove` | `TIX_Checkin` | Entfernt einen Gaestelisten-Eintrag |
| `tix_save_ticket_template` | `TIX_Ticket_Template` | Speichert ein Ticket-Template |
| `tix_preview_ticket_template` | `TIX_Ticket_Template` | Generiert eine Ticket-Template-Vorschau |
| `tix_generate_series` | `TIX_Series` | Generiert Kind-Events fuer eine Serie |
| `tix_send_test_email` | `TIX_Emails` | Sendet eine Test-E-Mail |
| `tix_ticket_resend` | `TIX_Tickets` | Einzelnes Ticket erneut per E-Mail versenden |
| `tix_ticket_resend_order` | `TIX_Tickets` | Alle Tickets einer Bestellung erneut versenden |
| `tix_ticket_toggle_status` | `TIX_Tickets` | Ticket-Status umschalten (valid/cancelled) |
| `tix_template_preview` | `TIX_Ticket_Template` | Template-Vorschau generieren (PDF-Preview) |
| `tix_checkin_combined_list` | `TIX_Checkin` | Kombinierte Gaeste- und Ticket-Liste laden |
| `tix_seatmap_load` | `TIX_Seatmap` | Saalplan-Daten laden |
| `tix_sync_test_connection` | `TIX_Sync` | Verbindung zur externen Datenbank testen |
| `tix_sync_all` | `TIX_Sync` | Vollstaendige Synchronisierung aller Events |
| `tix_raffle_draw` | `TIX_Raffle` | Gewinnspiel manuell auslosen |

### Oeffentliche Endpoints (nopriv)

| Action | Klasse | Beschreibung |
|---|---|---|
| `tix_add_to_cart` | `TIX_Ticket_Selector` | Fuegt Tickets zum Warenkorb hinzu |
| `tix_update_cart` | `TIX_Checkout` | Aktualisiert den Warenkorb |
| `tix_remove_from_cart` | `TIX_Checkout` | Entfernt Artikel aus dem Warenkorb |
| `tix_apply_coupon` | `TIX_Checkout` | Wendet einen Gutscheincode an |
| `tix_process_checkout` | `TIX_Checkout` | Verarbeitet den Checkout |
| `tix_express_checkout` | `TIX_Ticket_Selector` | Express-Checkout (One-Click) |
| `tix_subscribe_newsletter` | `TIX_Emails` | Newsletter-Anmeldung |
| `tix_unsubscribe_newsletter` | `TIX_Emails` | Newsletter-Abmeldung |
| `tix_save_abandoned_cart` | `TIX_Checkout` | Speichert einen abgebrochenen Warenkorb |
| `tix_recover_cart` | `TIX_Checkout` | Stellt einen abgebrochenen Warenkorb wieder her |
| `tix_transfer_ticket` | `TIX_Ticket_Transfer` | Fuehrt einen Ticket-Transfer durch |
| `tix_group_booking_submit` | `TIX_Group_Booking` | Sendet eine Gruppenbuchung ab |
| `tix_calculate_price` | `TIX_Ticket_Selector` | Live-Preisberechnung (Mengenrabatt etc.) |
| `tix_download_ticket` | `TIX_Tickets` | Ticket-PDF-Download |
| `tix_export_ical` | `TIX_Calendar` | iCal-Export |
| `tix_raffle_enter` | `TIX_Raffle` | Gewinnspiel-Teilnahme (Name + E-Mail) |
| `tix_waitlist_join` | `TIX_Waitlist` | Warteliste / Presale-Benachrichtigung beitreten |
| `tix_feedback_submit` | `TIX_Feedback` | Feedback absenden (Sterne + Kommentar, Token-validiert) |

### Veranstalter-Dashboard Endpoints (erfordern Organizer-Rolle)

| Action | Klasse | Beschreibung |
|---|---|---|
| `tix_od_overview` | `TIX_Organizer_Dashboard` | KPIs + 30-Tage-Verkaufschart |
| `tix_od_events` | `TIX_Organizer_Dashboard` | Event-Liste des Veranstalters |
| `tix_od_event_detail` | `TIX_Organizer_Dashboard` | Einzelnes Event fuer Editor laden |
| `tix_od_save_event` | `TIX_Organizer_Dashboard` | Event erstellen oder bearbeiten |
| `tix_od_delete_event` | `TIX_Organizer_Dashboard` | Event in Papierkorb verschieben |
| `tix_od_duplicate_event` | `TIX_Organizer_Dashboard` | Event duplizieren |
| `tix_od_orders` | `TIX_Organizer_Dashboard` | Bestellungsliste (nur eigene Events) |
| `tix_od_order_detail` | `TIX_Organizer_Dashboard` | Bestellungsdetails |
| `tix_od_guestlist` | `TIX_Organizer_Dashboard` | Gaesteliste laden |
| `tix_od_guestlist_save` | `TIX_Organizer_Dashboard` | Gaesteliste speichern |
| `tix_od_checkin` | `TIX_Organizer_Dashboard` | Check-In Toggle |
| `tix_od_stats` | `TIX_Organizer_Dashboard` | Statistiken mit Event-Filter |
| `tix_od_upload_media` | `TIX_Organizer_Dashboard` | Bild-Upload (Frontend) |
| `tix_od_save_discount` | `TIX_Organizer_Dashboard` | Rabattcode erstellen (WC_Coupon) |
| `tix_od_raffle_draw` | `TIX_Organizer_Dashboard` | Gewinnspiel auslosen |
| `tix_od_profile` | `TIX_Organizer_Dashboard` | Profil speichern |

---

## 17. Admin-Post Actions

Die folgenden Aktionen werden ueber `admin_post_*` registriert und erfordern Administrator-Rechte:

| Action | Klasse | Beschreibung |
|---|---|---|
| `tix_cleanup_orphans` | `TIX_Cleanup` | Bereinigt verwaiste WooCommerce-Produkte ohne zugehoeriges Event |
| `tix_export_subscribers` | `TIX_Columns` | Exportiert Newsletter-Abonnenten als CSV-Datei |
| `tix_duplicate_event` | `TIX_Columns` | Dupliziert ein Event mit allen Meta-Daten (ohne Kind-Events) |
| `tix_force_delete_event` | `TIX_Columns` | Loescht ein Event restlos inkl. WC-Produkte, Tickets, Custom Tables, Coupons, Crons |

---

## 18. Checkout-System

`TIX_Checkout` ersetzt den WooCommerce-Standard-Checkout vollstaendig und bietet einen optimierten, mehrstufigen Checkout-Prozess.

### Checkout-Schritte

| Schritt | Beschreibung |
|---|---|
| 1 -- Warenkorb | Uebersicht der ausgewaehlten Tickets mit Mengen- und Preisanpassung |
| 2 -- Kundendaten | Erfassung von Name, E-Mail, ggf. Rechnungsadresse |
| 3 -- Zahlung | Auswahl und Durchfuehrung der Zahlungsmethode (WooCommerce Payment Gateways) |
| 4 -- Bestaetigung | Bestellbestaetigung mit Ticket-Download-Links |

### Countdown-Timer

Sobald Tickets in den Warenkorb gelegt werden, startet ein konfigurierbarer Countdown-Timer. Nach Ablauf wird der Warenkorb automatisch geleert und die reservierten Tickets freigegeben. Die Dauer ist in den Einstellungen ueber `tix_cart_timeout` konfigurierbar.

### Warenkorb-Logik

- Tickets werden als WooCommerce-Varianten in den Warenkorb gelegt.
- Der Warenkorb ist event-spezifisch (ein Event pro Checkout).
- Gutscheincodes und Gruppenrabatte werden in Echtzeit berechnet.
- Abandoned Cart wird nach konfigurierbarer Inaktivitaetszeit erstellt.

### Shortcode

```
[tix_checkout]
```

Wird auf einer dedizierten Checkout-Seite eingebunden (Seiten-ID ueber `tix_checkout_page` konfigurierbar).

---

## 19. Express Checkout

Der Express Checkout bietet einen One-Click-Kaufprozess fuer schnelle Ticket-Kaeufe.

### Funktionsweise

1. Nutzer klickt auf "Express kaufen" im Ticket-Selector.
2. Ein Modal oeffnet sich mit einer kompakten Zusammenfassung.
3. Kundendaten werden eingegeben (oder aus WooCommerce-Account uebernommen).
4. Zahlung wird direkt im Modal durchgefuehrt.
5. Bestaetigung und Ticket-Download erfolgen im Modal.

### Konfiguration

- Global aktivierbar in den Einstellungen (Tab "Express Checkout") ueber `tix_express_enabled`.
- Pro Event ueber `_tix_express_checkout` steuerbar.
- Unterstuetzt alle WooCommerce Payment Gateways.

### AJAX-Endpoint

```
tix_express_checkout
```

---

## 20. Gruppenrabatte & Kombi-Tickets

`TIX_Group_Discount` bietet drei Rabattmodelle:

### Gruppenrabatte

Mengenbasierte Rabatte auf einzelne Ticket-Kategorien:

| Konfiguration | Beschreibung |
|---|---|
| `min_qty` | Mindestmenge fuer den Rabatt |
| `discount_type` | `percent` oder `fixed` |
| `discount_value` | Rabattwert (Prozent oder fester Betrag) |

Beispiel: Ab 5 Tickets 10% Rabatt, ab 10 Tickets 20% Rabatt.

### Bundle-Deals

Vordefinierte Pakete mit Festpreis:

| Konfiguration | Beschreibung |
|---|---|
| `name` | Paket-Name (z.B. "Freundespaket") |
| `items` | Array von Ticket-Kategorien und Mengen |
| `bundle_price` | Paketpreis (statt Einzelpreise) |

### Kombi-Tickets

Uebergreifende Pakete, die Tickets fuer mehrere Events kombinieren:

| Konfiguration | Beschreibung |
|---|---|
| `name` | Kombi-Name |
| `events` | Array von Event-IDs mit jeweiliger Ticket-Kategorie |
| `combo_price` | Kombi-Preis |

---

## 21. Gruppenbuchung (Group Booking)

`TIX_Group_Booking` ermoeglicht Buchungen fuer Gruppen mit optionaler Kostenaufteilung.

### Funktionsweise

1. Gruppenleiter startet eine Gruppenbuchung.
2. Definiert Anzahl der Teilnehmer.
3. Optional: Kostenaufteilung aktivieren -- jeder Teilnehmer erhaelt einen Zahlungslink.
4. Teilnehmer geben ihre Daten ein und zahlen ihren Anteil.
5. Nach vollstaendiger Zahlung werden alle Tickets generiert.

### Konfiguration (pro Event)

| Meta-Key | Beschreibung |
|---|---|
| `_tix_group_booking_enabled` | Aktiviert die Gruppenbuchung |
| `_tix_group_booking_min` | Mindestanzahl Teilnehmer |
| `_tix_group_booking_max` | Maximalanzahl Teilnehmer |
| `_tix_group_booking_split` | Kostenaufteilung aktiviert |

### Shortcode

```
[tix_group_booking id="123"]
```

---

## 22. Check-in & Gaesteliste

`TIX_Checkin` bietet ein vollstaendiges Check-in-System fuer den Einlass.

### QR-Scanner

- Browser-basierter QR-Code-Scanner (nutzt `jsqr.min.js`).
- Kamera-Zugriff ueber die Web-API.
- Echtzeit-Validierung: unterstuetzt 12-stellige Codes, alte TIX-XXXXXX Codes und GL-{EVENT}-{CODE} QR-Format.
- Visuelles Feedback: gueltig (gruen), ungueltig (rot), bereits eingecheckt (gelb).

### Gaesteliste

- Tabellarische Uebersicht aller Ticket-Inhaber pro Event.
- Suchfunktion nach Name oder E-Mail.
- Manueller Check-in per Klick.
- Status-Anzeige (eingecheckt / teilweise / offen).
- Gaestelisten-Eintraege koennen manuell hinzugefuegt werden (fuer Freitickets etc.).

### Teilweises Einchecken (Partial Check-in)

- Gaeste mit Begleitung (z.B. +4) koennen teilweise eingecheckt werden.
- Nach dem Einchecken laesst sich die Anzahl per Minus/Plus-Buttons anpassen.
- Beispiel: Gast hat +4 Begleitung, aber erst +2 sind da → 3/5 eingecheckt.
- Die restlichen Personen koennen spaeter erneut gescannt oder manuell eingecheckt werden.
- Drei Status-Typen: `ok` (vollstaendig), `partial` (teilweise), `already` (bereits voll eingecheckt).
- AJAX-Endpunkt `tix_guest_update_checkin` zum Anpassen der Check-in-Anzahl.

### Check-in-Seite

Der Shortcode `[tix_checkin]` rendert die komplette Check-in-Oberflaeche:

```
[tix_checkin id="123"]
```

| Parameter | Beschreibung |
|---|---|
| `id` | Event-ID (Pflicht) |

### Berechtigungen

- Check-in erfordert mindestens die Capability `manage_options`.
- Die Check-in-Seite ist nur fuer eingeloggte Administratoren zugaenglich.

---

## 23. Ticket-Transfer

`TIX_Ticket_Transfer` ermoeglicht die Umschreibung von Tickets auf andere Personen.

### Ablauf

1. Ticket-Inhaber ruft "Ticket uebertragen" auf (ueber "Meine Tickets").
2. Gibt Name und E-Mail der Zielperson ein.
3. Das alte Ticket wird als `transferred` markiert.
4. Ein neues Ticket wird fuer die Zielperson erstellt.
5. Die Zielperson erhaelt eine E-Mail mit dem neuen Ticket.

### Meta-Verknuepfungen

- `_tix_ticket_transferred_from`: Verweist vom neuen auf das alte Ticket.
- `_tix_ticket_transferred_to`: Verweist vom alten auf das neue Ticket.

### AJAX-Endpoint

```
tix_transfer_ticket
```

---

## 24. Meine Tickets

`TIX_My_Tickets` stellt eine Uebersichtsseite fuer eingeloggte Nutzer bereit, auf der alle gekauften Tickets angezeigt werden.

### Shortcode

```
[tix_my_tickets]
```

### Funktionen

- Listet alle Tickets des eingeloggten Nutzers auf.
- Gruppierung nach Event.
- Ticket-Status-Anzeige (gueltig, eingecheckt, storniert, uebertragen).
- PDF-Download-Button.
- QR-Code-Anzeige.
- Ticket-Transfer-Button.
- Filtert automatisch nach vergangenen und kommenden Events.

---

## 25. Abandoned Cart Recovery

`TIX_Checkout` und `TIX_Emails` arbeiten zusammen, um abgebrochene Warenkoerbe wiederherzustellen.

### Ablauf

1. Kunde beginnt den Checkout und gibt seine E-Mail-Adresse ein.
2. Schliesst den Kauf nicht ab (Inaktivitaet oder Seite verlassen).
3. Nach konfigurierbarer Wartezeit wird ein `tix_abandoned_cart`-Post erstellt.
4. Der Cron-Job `tix_send_abandoned_cart_email` sendet eine Recovery-E-Mail.
5. Die E-Mail enthaelt einen Link zur Warenkorb-Wiederherstellung.
6. Klickt der Kunde den Link, wird der Warenkorb wiederhergestellt.

### Statuswerte

| Status | Beschreibung |
|---|---|
| `pending` | Warenkorb wurde als abgebrochen erkannt, E-Mail noch nicht gesendet |
| `sent` | Recovery-E-Mail wurde gesendet |
| `recovered` | Kunde hat den Warenkorb wiederhergestellt und den Kauf abgeschlossen |
| `expired` | Warenkorb ist abgelaufen (Cron: `tix_expire_abandoned_carts`) |

---

## 26. Newsletter-System

Tixomat enthaelt ein einfaches Newsletter-System fuer event-bezogene Kommunikation.

### Anmeldung

- Ueber den Shortcode `[tix_newsletter]`.
- Optional im Checkout-Prozess (wenn `tix_checkout_newsletter` aktiviert).
- Erstellt einen `tix_subscriber`-Post.

### Abmeldung

- Ueber einen Abmelde-Link in jeder E-Mail.
- AJAX-Endpoint: `tix_unsubscribe_newsletter`.
- Status wird auf `unsubscribed` gesetzt.

### CSV-Export

- Ueber die Admin-Post-Action `tix_export_subscribers`.
- Exportiert: Name, E-Mail, Event, Datum, Quelle, Status.

---

## 27. E-Mail-System

`TIX_Emails` sendet White-Label-E-Mails mit dem Branding des Veranstalters.

### E-Mail-Typen

| Typ | Trigger | Beschreibung |
|---|---|---|
| Bestellbestaetigung | Bestellung abgeschlossen | Enthaelt Ticket-Downloads und Event-Details |
| Reminder | Cron (`tix_send_reminder_email`) | Erinnerung X Tage vor dem Event |
| Followup | Cron (`tix_send_followup_email`) | Nachfass-E-Mail X Tage nach dem Event |
| Abandoned Cart | Cron (`tix_send_abandoned_cart_email`) | Recovery-E-Mail fuer abgebrochene Warenkoerbe |
| Ticket-Transfer | Transfer-Aktion | Benachrichtigung ueber Ticket-Umschreibung |
| Newsletter | Manuell durch Admin | Newsletter an Abonnenten |

### White-Label

- Absendername und -adresse konfigurierbar ueber `tix_email_from_name` und `tix_email_from_email`.
- E-Mail-Template mit eigenem Logo (`tix_email_logo`) und Farben.
- Fusszeile mit Branding (`tix_branding_footer()`).

### Test-E-Mails

Ueber den AJAX-Endpoint `tix_send_test_email` koennen Test-E-Mails versendet werden.

---

## 28. Embed-Modus

`TIX_Embed` ermoeglicht die Einbettung des Ticket-Selectors auf externen Webseiten.

### Funktionsweise

- Generiert einen iframe-faehigen Embed-Code.
- Der Embed-Modus entfernt Header, Footer und Navigation.
- Nur der Ticket-Selector wird dargestellt.
- Kommunikation mit der Hauptseite ueber `postMessage`.

### Shortcode

```
[tix_embed id="123" style="minimal"]
```

| Parameter | Beschreibung |
|---|---|
| `id` | Event-ID (Pflicht) |
| `style` | Darstellungsstil: `minimal`, `full` |

### Aktivierung

Pro Event ueber `_tix_embed_enabled` aktivierbar.

### Embed-Code (fuer externe Seiten)

```html
<iframe src="https://example.com/?tix_embed=123" width="100%" height="600" frameborder="0"></iframe>
```

---

## 29. Zusatzprodukte

`TIX_Upsell` zeigt verwandte Events und Produkte als Zusatzprodukte an.

### Konfiguration (pro Event)

| Meta-Key | Beschreibung |
|---|---|
| `_tix_upsell_events` | Array von Event-IDs, die als Empfehlung angezeigt werden |
| `_tix_upsell_products` | Array von WooCommerce-Produkt-IDs |
| `_tix_upsell_text` | Beschreibungstext fuer den Zusatzprodukte-Bereich |

### Shortcode

```
[tix_upsell id="123"]
```

### Darstellung

- Karten-Layout mit Event-Bild, Titel, Datum und Preis.
- Direkt-Link zum Event oder Produkt.
- Konfigurierbar in den Einstellungen (Anzahl, Layout).

---

## 30. Kalender-Integration

`TIX_Calendar` bietet eine Kalenderansicht und Export-Funktionen.

### Kalender-Shortcode

```
[tix_calendar category="konzerte" view="month"]
```

| Parameter | Beschreibung |
|---|---|
| `category` | Filtert nach Event-Kategorie (Slug der `event_category` Taxonomie) |
| `view` | Ansichtsmodus: `month` (Monatsansicht) oder `list` (Listenansicht) |

### iCal-Export

- AJAX-Endpoint: `tix_export_ical`.
- Generiert eine `.ics`-Datei fuer einzelne Events oder alle Events.
- Kompatibel mit Apple Kalender, Outlook, Google Calendar.

### Google-Calendar-Export

- Generiert einen "Zu Google Calendar hinzufuegen"-Link.
- Uebergibt Event-Titel, Datum, Uhrzeit, Ort und Beschreibung.

---

## 31. Einstellungen (Settings)

`TIX_Settings` verwaltet alle Plugin-Einstellungen in 11 Tabs. Die Einstellungen werden als einzelne WordPress-Optionen mit dem Praefix `tix_` gespeichert.

### Tab 1: Design

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Primaerfarbe | `tix_primary_color` | `string` (Hex) | Hauptfarbe fuer Buttons und Akzente |
| Sekundaerfarbe | `tix_secondary_color` | `string` (Hex) | Zweite Akzentfarbe |
| Hintergrundfarbe | `tix_bg_color` | `string` (Hex) | Hintergrundfarbe der Tixomat-Elemente |
| Textfarbe | `tix_text_color` | `string` (Hex) | Standard-Textfarbe |
| Border-Radius | `tix_border_radius` | `string` (px) | Abrundung der Ecken |
| Schriftfamilie | `tix_font_family` | `string` | CSS font-family |
| Schriftgroesse | `tix_font_size` | `string` (px) | Basis-Schriftgroesse |
| Button-Stil | `tix_button_style` | `string` | `filled`, `outline`, `ghost` |
| Button-Radius | `tix_button_radius` | `string` (px) | Button-Abrundung |

### Tab 2: Ticket-Selector

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Selector-Stil | `tix_selector_style` | `string` | `cards`, `table`, `minimal` |
| Countdown anzeigen | `tix_show_countdown` | `string` (0/1) | Countdown bis zum Event |
| Kategorie-Beschreibung | `tix_show_cat_desc` | `string` (0/1) | Beschreibung der Ticket-Kategorien anzeigen |
| Verfuegbarkeits-Anzeige | `tix_show_availability` | `string` (0/1) | Verbleibende Tickets anzeigen |
| Primaerfarbe Selector | `tix_selector_primary` | `string` (Hex) | Separate Primaerfarbe fuer Selector |
| Hintergrund Selector | `tix_selector_bg` | `string` (Hex) | Hintergrundfarbe des Selectors |

### Tab 3: FAQ

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| FAQ-Stil | `tix_faq_style` | `string` | `accordion`, `list` |
| Icon-Stil | `tix_faq_icon` | `string` | `plus`, `arrow`, `none` |
| Hintergrundfarbe | `tix_faq_bg` | `string` (Hex) | FAQ-Hintergrundfarbe |
| Border-Farbe | `tix_faq_border_color` | `string` (Hex) | Rahmenfarbe |

### Tab 4: Checkout

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Checkout-Seite | `tix_checkout_page` | `int` | WordPress-Seiten-ID fuer den Checkout |
| Countdown-Dauer | `tix_cart_timeout` | `int` | Warenkorb-Timer in Minuten |
| AGB-Seite | `tix_terms_page` | `int` | Seiten-ID der AGB |
| Gutscheine aktiviert | `tix_coupons_enabled` | `string` (0/1) | Gutscheincode-Feld anzeigen |
| Rechnungsadresse | `tix_require_billing` | `string` (0/1) | Rechnungsadresse erforderlich |
| Newsletter im Checkout | `tix_checkout_newsletter` | `string` (0/1) | Newsletter-Anmeldung im Checkout anbieten |

### Tab 5: Express Checkout

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Express Checkout aktiviert | `tix_express_enabled` | `string` (0/1) | Global aktivieren/deaktivieren |
| Express-Button-Text | `tix_express_button_text` | `string` | Text des Express-Buttons |
| Express-Button-Farbe | `tix_express_button_color` | `string` (Hex) | Farbe des Express-Buttons |

### Tab 6: Meine Tickets

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Meine-Tickets-Seite | `tix_my_tickets_page` | `int` | WordPress-Seiten-ID |
| Transfer aktiviert | `tix_ticket_transfer_enabled` | `string` (0/1) | Ticket-Transfer erlauben |
| Download aktiviert | `tix_ticket_download_enabled` | `string` (0/1) | PDF-Download erlauben |

### Tab 7: Newsletter

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Absendername | `tix_email_from_name` | `string` | Name des E-Mail-Absenders |
| Absender-E-Mail | `tix_email_from_email` | `string` | E-Mail-Adresse des Absenders |
| E-Mail-Logo | `tix_email_logo` | `int` | Attachment-ID des E-Mail-Logos |
| Reminder-Vorlauf | `tix_reminder_days` | `int` | Tage vor Event fuer Reminder |
| Followup-Nachgang | `tix_followup_days` | `int` | Tage nach Event fuer Followup |
| Abandoned Cart Wartezeit | `tix_abandoned_cart_delay` | `int` | Minuten bis zur Recovery-E-Mail |

### Tab 8: Check-in

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Check-in-Seite | `tix_checkin_page` | `int` | WordPress-Seiten-ID |
| Kamera-Aufloesung | `tix_scanner_resolution` | `string` | Kamera-Aufloesung fuer den QR-Scanner |
| Automatischer Check-in | `tix_auto_checkin` | `string` (0/1) | Automatisch einchecken nach Scan |

### Tab 9: Ticket-Template

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Globales Template aktiv | `tix_ticket_template_enabled` | `string` (0/1) | Globales Ticket-Template aktivieren |
| Hintergrundbild | `tix_ticket_template_bg` | `int` | Attachment-ID des Hintergrundbildes |
| Template-Breite | `tix_ticket_template_width` | `int` | Breite in Pixel |
| Template-Hoehe | `tix_ticket_template_height` | `int` | Hoehe in Pixel |
| Feld-Konfiguration | `tix_ticket_template_fields` | `array` | Positionierung und Styling aller 14 Felder |

### Tab 10: Erweitert

| Einstellung | Key | Typ | Beschreibung |
|---|---|---|---|
| Ticket-System | `tix_ticket_system` | `string` | `standalone` |
| Loeschutz aktiv | `tix_delete_protection` | `string` (0/1) | Verhindert versehentliches Loeschen |
| Debug-Modus | `tix_debug_mode` | `string` (0/1) | Erweiterte Logging-Ausgabe |
| Archivierungs-Tage | `tix_archive_days` | `int` | Tage nach Event bis zur Archivierung |
| WC-Produkt-Sichtbarkeit | `tix_wc_product_visibility` | `string` | Sichtbarkeit der generierten WC-Produkte |
| Breakdance-Integration | `tix_breakdance_enabled` | `string` (0/1) | Breakdance Dynamic Data aktivieren |
| KI-Schutz aktiviert | `ai_guard_enabled` | `int` (0/1) | KI-Inhaltspruefung beim Veroeffentlichen |
| Anthropic API Key | `ai_guard_api_key` | `string` | API-Key fuer Anthropic Claude API (sk-ant-...) |

### Tab 11: Dokumentation

Ueber `TIX_Docs` wird eine interaktive Dokumentation direkt im Admin-Bereich angezeigt (kein separater Settings-Key).

---

## 32. Design-System 2026

Tixomat 1.21.0 fuehrt ein modernisiertes Admin-Design-System ein.

### Design-Grundlagen

| Eigenschaft | Wert | Beschreibung |
|---|---|---|
| Akzentfarbe | `#6366f1` (Indigo) | Primaerer Akzent im Admin-Bereich |
| Elevation | Shadow-basiert | Hierarchie durch Schatten statt Rahmen |
| Karten-Design | Ohne Rahmen | Karten nutzen Schatten statt `border` |
| Navigation | Pill-Shape | Tab-Navigation mit abgerundeten Pill-Elementen |
| Border-Radius | `16px` | Moderner, grosszuegiger Radius |
| Font-Stack | System-UI | `system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif` |

### Elevation-Stufen

| Stufe | CSS-Wert | Verwendung |
|---|---|---|
| Level 0 | `none` | Basis-Elemente, Hintergrund |
| Level 1 | `0 1px 3px rgba(0,0,0,0.1)` | Karten, Panels |
| Level 2 | `0 4px 12px rgba(0,0,0,0.1)` | Hervorgehobene Elemente, Dropdowns |
| Level 3 | `0 8px 24px rgba(0,0,0,0.12)` | Modale, Overlays |

### Farbpalette

| Farbe | Hex-Wert | Verwendung |
|---|---|---|
| Indigo 500 | `#6366f1` | Primaer-Akzent, aktive Elemente |
| Indigo 600 | `#4f46e5` | Hover-Zustand |
| Indigo 50 | `#eef2ff` | Hintergrund aktiver Pill-Tabs |
| Grau 50 | `#f9fafb` | Seitenhintergrund |
| Grau 100 | `#f3f4f6` | Karten-Hintergrund |
| Grau 200 | `#e5e7eb` | Dezente Trennlinien |
| Grau 700 | `#374151` | Primaerer Text |
| Grau 500 | `#6b7280` | Sekundaerer Text |
| Gruen 500 | `#22c55e` | Erfolgs-Status |
| Rot 500 | `#ef4444` | Fehler-Status |
| Gelb 500 | `#f59e0b` | Warnungs-Status |

---

## 33. CSS Custom Properties

`TIX_Settings::output_css()` gibt folgende CSS Custom Properties im Frontend und Admin aus:

```css
:root {
  /* Farben */
  --tix-primary: #6366f1;
  --tix-primary-hover: #4f46e5;
  --tix-secondary: /* konfigurierbar ueber tix_secondary_color */;
  --tix-bg: /* konfigurierbar ueber tix_bg_color */;
  --tix-text: /* konfigurierbar ueber tix_text_color */;
  --tix-text-secondary: #6b7280;
  --tix-border: #e5e7eb;
  --tix-success: #22c55e;
  --tix-error: #ef4444;
  --tix-warning: #f59e0b;

  /* Typografie */
  --tix-font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  --tix-font-size: /* konfigurierbar ueber tix_font_size */;
  --tix-font-size-sm: 0.875rem;
  --tix-font-size-lg: 1.125rem;
  --tix-font-size-xl: 1.25rem;
  --tix-line-height: 1.5;

  /* Spacing */
  --tix-spacing-xs: 0.25rem;
  --tix-spacing-sm: 0.5rem;
  --tix-spacing-md: 1rem;
  --tix-spacing-lg: 1.5rem;
  --tix-spacing-xl: 2rem;

  /* Radii */
  --tix-radius: /* konfigurierbar ueber tix_border_radius, Standard 16px */;
  --tix-radius-sm: 8px;
  --tix-radius-lg: 24px;
  --tix-radius-pill: 9999px;
  --tix-button-radius: /* konfigurierbar ueber tix_button_radius */;

  /* Schatten (Elevation) */
  --tix-shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
  --tix-shadow-md: 0 4px 12px rgba(0,0,0,0.1);
  --tix-shadow-lg: 0 8px 24px rgba(0,0,0,0.12);

  /* Uebergaenge */
  --tix-transition: all 0.2s ease;

  /* Ticket-Selector spezifisch */
  --tix-selector-primary: /* konfigurierbar ueber tix_selector_primary */;
  --tix-selector-bg: /* konfigurierbar ueber tix_selector_bg */;

  /* FAQ spezifisch */
  --tix-faq-bg: /* konfigurierbar ueber tix_faq_bg */;
  --tix-faq-border: /* konfigurierbar ueber tix_faq_border_color */;

  /* Express Checkout */
  --tix-express-button-color: /* konfigurierbar ueber tix_express_button_color */;
}
```

Die mit "konfigurierbar" markierten Werte werden dynamisch aus den Plugin-Einstellungen generiert. Der Filter `tix_css_variables` erlaubt externe Anpassungen vor der Ausgabe.

---

## 34. Loeschutz & Cleanup

### Loeschutz (`TIX_Cleanup`)

Wenn `tix_delete_protection` aktiviert ist:

- Events koennen nicht ueber den Standard-WordPress-Loeschvorgang entfernt werden.
- Ein Warnhinweis wird angezeigt, wenn ein Loeschversuch unternommen wird.
- Zum Loeschen muss die Admin-Post-Action `tix_force_delete_event` verwendet werden.
- Dies verhindert versehentliches Loeschen von Events mit verkauften Tickets.

### Force-Delete

Die Action `tix_force_delete_event` loescht:

1. Das Event selbst (inkl. Serien-Kinder bei Serien-Master).
2. Alle zugehoerigen WooCommerce-Produkte (inkl. Varianten).
3. Alle zugehoerigen `tix_ticket`-Posts (eigenes Ticketsystem).
4. **Alle Custom-Table-Daten** via `purge_event_data()` (siehe unten).
5. Automatischer Orphan-Cleanup nach Loeschung.

### Orphan-Cleanup

Die Action `tix_cleanup_orphans` bereinigt:

- WooCommerce-Produkte ohne zugehoeriges Event.
- Verwaiste `tix_ticket`-Posts.
- Verwaiste Serien-Kinder (Master fehlt) -- inklusive `purge_event_data()`.

### Restlose Event-Daten-Loeschung (`purge_event_data`)

Die zentrale Methode `TIX_Cleanup::purge_event_data($event_id)` loescht **alle** verknuepften Daten eines Events restlos aus der Datenbank. Sie wird automatisch aufgerufen bei Papierkorb, endgueltigem Loeschen, Force-Delete und Orphan-Cleanup.

**Custom Tables:**

| Tabelle | Beschreibung |
|---|---|
| `tix_raffle_entries` | Gewinnspiel-Teilnahmen |
| `tix_waitlist` | Warteliste- und Presale-Eintraege |
| `tix_feedback` | Feedback-Bewertungen und Kommentare |
| `tix_seat_reservations` | Sitzplatz-Reservierungen |
| `tixomat_tickets` | Denormalisierte Ticket-Datenbank |
| `tixomat_promoter_events` | Promoter-Event-Zuordnungen |
| `tixomat_promoter_commissions` | Promoter-Provisionen |

**Verknuepfte CPTs:**

| CPT | Meta-Key | Beschreibung |
|---|---|---|
| `tix_abandoned_cart` | `_tix_ac_event_id` | Verlassene Warenkoerbe |
| `tix_subscriber` | `_tix_sub_event_id` | Event-spezifische Subscribers |
| `shop_coupon` | `_tix_event_coupon` | Event-Gutscheine (WC-Coupons) |

**Zusaetzlich:**

- Geplante Cron-Jobs (Reminder- und Follow-up-E-Mails) fuer das Event.
- Per-Event Transients (`tix_sync_log_*`, `tix_ai_flag_*`, `tix_publish_error_*`, `tix_publish_warning_*`).

---

## 35. Event-Duplizierung

Die Admin-Post-Action `tix_duplicate_event` (Klasse `TIX_Columns`) erstellt eine Kopie eines Events.

### Kopierte Daten

- Alle Event-Meta-Keys (siehe Abschnitt 7).
- Event-Titel (mit Praefix "Kopie von").
- Event-Inhalt (Beschreibung).
- Thumbnail / Beitragsbild.
- Event-Kategorien.

### Nicht kopierte Daten

- WooCommerce-Produkt-ID (wird beim Speichern neu erstellt).
- Verkaufszahlen (`sold` in Ticket-Kategorien wird auf 0 gesetzt).
- Serientermin-Verknuepfungen (Kind-Events werden nicht kopiert).
- Bestehende Tickets (`tix_ticket`).

### Ausloesung

- Ueber die Admin-Spalten-Aktion "Duplizieren" in der Event-Liste.
- Erfordert `manage_options` Capability.

---

## 36. Admin-Spalten & Liste

`TIX_Columns` registriert benutzerdefinierte Spalten in der Event-Uebersicht im WordPress-Admin.

### Spalten

| Spalte | Beschreibung |
|---|---|
| Datum | Event-Datum (formatiert) |
| Uhrzeit | Beginn-Uhrzeit |
| Location | Name der Location |
| Status | Event-Status mit farbigem Badge |
| Tickets | Verkauft / Gesamt |
| Vorverkauf | Vorverkaufsstatus |
| KI-Schutz | KI-Pruefungsstatus: ✓ (genehmigt), ⚠️ (abgelehnt), — (nicht geprueft). Nur sichtbar wenn KI-Schutz aktiviert. |
| Kategorie | Event-Kategorie(n) |
| Aktionen | Duplizieren, Loeschen, Bearbeiten |

### Sortierung

Die Spalten "Datum" und "Status" sind sortierbar.

### CSV-Export

Ueber den Button "CSV-Export" in der Event-Liste koennen alle Events (oder gefilterte Events) als CSV exportiert werden. Der Export enthaelt alle wichtigen Event-Daten.

---

## 37. Cron-Jobs & Scheduled Actions

Tixomat registriert 7 Cron-Jobs ueber die WordPress-Cron-API:

| Cron-Hook | Intervall | Klasse | Beschreibung |
|---|---|---|---|
| `tix_presale_check` | Alle 10 Min | `TIX_Frontend` | Prueft Vorverkaufsende, Event-Status, Preisphasen und Archivierung |
| `tix_send_reminder_email` | Taeglich | `TIX_Emails` | Sendet Erinnerungs-E-Mails X Tage vor dem Event |
| `tix_send_followup_email` | Taeglich | `TIX_Emails` | Sendet Nachfass-E-Mails X Tage nach dem Event |
| `tix_send_abandoned_cart_email` | Halbstuendlich | `TIX_Emails` | Sendet Recovery-E-Mails fuer abgebrochene Warenkoerbe |
| `tix_expire_abandoned_carts` | Taeglich | `TIX_Checkout` | Setzt abgelaufene Warenkoerbe auf Status `expired` |
| `tix_raffle_auto_draw` | Alle 10 Min | `TIX_Raffle` | Automatische Gewinnspiel-Auslosung bei Teilnahmeschluss |
| `tix_waitlist_check` | Alle 10 Min | `TIX_Waitlist` | Prueft Presale-Start und Stock-Rueckkehr, sendet Benachrichtigungen |

### `tix_presale_check` im Detail

Dieser Cron-Job fuehrt folgende Pruefungen durch:

1. **Vorverkaufsende:** Prueft alle Events mit `_tix_presale_mode = date` oder `offset`.
2. **Vergangene Events:** Setzt Events auf `past`, wenn das Datum ueberschritten ist.
3. **Ausverkauft:** Setzt Events auf `sold_out`, wenn alle Kategorien ausverkauft sind.
4. **Archivierung:** Archiviert Events nach Ablauf der konfigurierten Tage (`tix_archive_days`).

---

## 38. Transients

Tixomat verwendet WordPress-Transients fuer Performance-Optimierung:

| Transient-Key | TTL | Beschreibung |
|---|---|---|
| `tix_event_count` | 1 Stunde | Gesamtanzahl der Events (fuer Dashboard-Widget) |
| `tix_upcoming_events` | 1 Stunde | Array der naechsten Events (fuer Dashboard-Widget) |
| `tix_ticket_stats_{event_id}` | 30 Minuten | Ticket-Statistiken pro Event |
| `tix_calendar_data_{month}_{year}` | 1 Stunde | Kalender-Daten fuer einen Monat |

Transients werden bei relevanten Aenderungen (Event speichern, Ticket verkauft) automatisch invalidiert.

---

## 39. Dashboard-Widget

`TIX_Frontend` registriert ein WordPress-Dashboard-Widget mit folgenden Informationen:

- Anzahl der Events (gesamt, kommend, vergangen).
- Naechste 5 Events mit Datum und Ticket-Status.
- Verkaufsstatistiken (Tickets verkauft heute / diese Woche / gesamt).
- Quick-Links: Neues Event erstellen, Einstellungen, Dokumentation.
- Event-Status-Verteilung (verfuegbar, ausverkauft, abgesagt etc.).

---

## 40. SEO: Open Graph & JSON-LD

### Open Graph Meta-Tags

`TIX_Frontend` gibt fuer einzelne Event-Seiten Open-Graph-Meta-Tags aus:

```html
<meta property="og:title" content="Event-Titel" />
<meta property="og:description" content="Event-Beschreibung" />
<meta property="og:image" content="URL-zum-Flyer-oder-Beitragsbild" />
<meta property="og:url" content="Event-URL" />
<meta property="og:type" content="event" />
<meta property="og:site_name" content="Website-Titel" />
```

Der Filter `tix_og_meta` erlaubt die Anpassung der Meta-Tags vor der Ausgabe.

### JSON-LD Strukturierte Daten

Fuer jedes Event wird ein `Event`-Schema (schema.org) ausgegeben:

```json
{
  "@context": "https://schema.org",
  "@type": "Event",
  "name": "Event-Titel",
  "description": "Event-Beschreibung",
  "startDate": "2026-03-15T20:00:00+01:00",
  "endDate": "2026-03-15T23:00:00+01:00",
  "doorTime": "2026-03-15T19:00:00+01:00",
  "location": {
    "@type": "Place",
    "name": "Location-Name",
    "address": "Location-Adresse"
  },
  "organizer": {
    "@type": "Organization",
    "name": "Veranstalter-Name"
  },
  "offers": [
    {
      "@type": "Offer",
      "name": "Ticket-Kategorie",
      "price": "29.90",
      "priceCurrency": "EUR",
      "availability": "https://schema.org/InStock",
      "url": "Event-URL"
    }
  ],
  "image": "URL-zum-Bild",
  "eventStatus": "https://schema.org/EventScheduled",
  "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode"
}
```

- Der JSON-LD-Inhalt kann pro Event ueber `_tix_jsonld_override` ueberschrieben werden.
- Der Filter `tix_jsonld_data` erlaubt programmatische Anpassungen.

---

## 41. Helper Functions

### `tix_use_own_tickets()`

```php
function tix_use_own_tickets(): bool
```

Gibt `true` zurueck, wenn das eigene Ticketsystem aktiv ist (`tix_ticket_system` ist `standalone` oder `both`).

### `tix_get_settings()`

```php
function tix_get_settings(string $key = '', $default = ''): mixed
```

Ruft eine einzelne Tixomat-Einstellung ab. Ohne `$key` wird ein Array aller Einstellungen zurueckgegeben.

| Parameter | Typ | Beschreibung |
|---|---|---|
| `$key` | `string` | Einstellungs-Key (ohne `tix_` Praefix) |
| `$default` | `mixed` | Standardwert, falls die Einstellung nicht existiert |

Beispiel:

```php
$primary = tix_get_settings('primary_color', '#6366f1');
$all     = tix_get_settings(); // Alle Einstellungen
```

### `tix_branding_footer()`

```php
function tix_branding_footer(): string
```

Gibt den Branding-Footer fuer E-Mails und den Embed-Modus zurueck. Enthaelt das konfigurierte Logo und den Veranstalternamen.

---

## 42. Hooks & Filter

### Actions

| Hook | Klasse | Beschreibung |
|---|---|---|
| `tix_after_event_save` | `TIX_Metabox` | Wird nach dem Speichern eines Events ausgeloest |
| `tix_after_sync` | `TIX_Sync` | Wird nach dem WooCommerce-Sync ausgeloest |
| `tix_before_checkout` | `TIX_Checkout` | Wird vor der Checkout-Verarbeitung ausgeloest |
| `tix_after_checkout` | `TIX_Checkout` | Wird nach erfolgreichem Checkout ausgeloest |
| `tix_ticket_created` | `TIX_Tickets` | Wird nach der Erstellung eines Tickets ausgeloest |
| `tix_ticket_checked_in` | `TIX_Checkin` | Wird nach einem Check-in ausgeloest |
| `tix_ticket_transferred` | `TIX_Ticket_Transfer` | Wird nach einem Ticket-Transfer ausgeloest |
| `tix_email_sent` | `TIX_Emails` | Wird nach dem Versand einer E-Mail ausgeloest |
| `tix_series_generated` | `TIX_Series` | Wird nach der Generierung von Serienterminen ausgeloest |
| `tix_abandoned_cart_created` | `TIX_Checkout` | Wird nach Erstellung eines Abandoned Cart ausgeloest |
| `tix_cart_recovered` | `TIX_Checkout` | Wird nach Wiederherstellung eines Warenkorbs ausgeloest |

### Filter

| Filter | Klasse | Beschreibung |
|---|---|---|
| `tix_ticket_categories` | `TIX_Metabox` | Filtert Ticket-Kategorien vor der Anzeige |
| `tix_checkout_fields` | `TIX_Checkout` | Filtert die Checkout-Formularfelder |
| `tix_email_template` | `TIX_Emails` | Filtert das E-Mail-Template vor dem Versand |
| `tix_email_subject` | `TIX_Emails` | Filtert den E-Mail-Betreff |
| `tix_ticket_price` | `TIX_Dynamic_Pricing` | Filtert den berechneten Ticket-Preis |
| `tix_event_status` | `TIX_Frontend` | Filtert den Event-Status vor der Anzeige |
| `tix_jsonld_data` | `TIX_Frontend` | Filtert die JSON-LD-Daten vor der Ausgabe |
| `tix_og_meta` | `TIX_Frontend` | Filtert die Open-Graph-Meta-Tags |
| `tix_calendar_events` | `TIX_Calendar` | Filtert die Events fuer den Kalender |
| `tix_embed_html` | `TIX_Embed` | Filtert den Embed-HTML-Code |
| `tix_selector_html` | `TIX_Ticket_Selector` | Filtert den Ticket-Selector-HTML-Code |
| `tix_group_discount_price` | `TIX_Group_Discount` | Filtert den rabattierten Preis |
| `tix_pdf_ticket_data` | `TIX_Tickets` | Filtert die Daten vor der PDF-Generierung |
| `tix_ticket_template_fields` | `TIX_Ticket_Template` | Filtert die Template-Felder |
| `tix_css_variables` | `TIX_Settings` | Filtert die CSS Custom Properties vor der Ausgabe |

---

## 43. JavaScript & CSS Assets

### CSS-Dateien

| Datei | Beschreibung |
|---|---|
| `tix-admin.css` / `tix-admin.min.css` | Admin-Bereich Styles (Metabox, Settings, Spalten) |
| `tix-frontend.css` / `tix-frontend.min.css` | Allgemeine Frontend-Styles |
| `tix-ticket-selector.css` / `tix-ticket-selector.min.css` | Ticket-Selector-Styles |
| `tix-checkout.css` / `tix-checkout.min.css` | Checkout-Styles |
| `tix-calendar.css` / `tix-calendar.min.css` | Kalender-Styles |
| `tix-checkin.css` / `tix-checkin.min.css` | Check-in-Seite und QR-Scanner |
| `tix-faq.css` / `tix-faq.min.css` | FAQ-Akkordeon-Styles |
| `tix-my-tickets.css` / `tix-my-tickets.min.css` | Meine-Tickets-Styles |
| `tix-embed.css` / `tix-embed.min.css` | Embed-Widget-Styles |
| `tix-ticket-template.css` / `tix-ticket-template.min.css` | Ticket-Template-Editor-Styles |

### JavaScript-Dateien

| Datei | Beschreibung |
|---|---|
| `tix-admin.js` / `tix-admin.min.js` | Admin-Metabox-Logik, Tabs, Wizard, Repeater |
| `tix-ticket-selector.js` / `tix-ticket-selector.min.js` | Ticket-Auswahl, Live-Preisberechnung, Mengen-Stepper |
| `tix-checkout.js` / `tix-checkout.min.js` | Checkout-Schritte, Warenkorb, Countdown-Timer |
| `tix-calendar.js` / `tix-calendar.min.js` | Kalender-Navigation und Event-Anzeige |
| `tix-checkin.js` / `tix-checkin.min.js` | QR-Scanner, Check-in-Logik, Gaesteliste |
| `tix-faq.js` / `tix-faq.min.js` | FAQ-Akkordeon-Interaktion |
| `tix-express.js` / `tix-express.min.js` | Express-Checkout-Modal und One-Click-Kauf |
| `tix-group-booking.js` / `tix-group-booking.min.js` | Gruppenbuchungs-Formular und Kostenaufteilung |
| `tix-embed.js` / `tix-embed.min.js` | Embed-Widget-Kommunikation (postMessage) |
| `tix-ticket-template.js` / `tix-ticket-template.min.js` | Template-Editor: Drag & Drop, Vorschau |
| `tix-settings.js` / `tix-settings.min.js` | Settings-Seite: Tabs, Farbwaehler, Vorschau |
| `jsqr.min.js` | QR-Code-Scan-Bibliothek (Drittanbieter) |

### Schriftarten

| Datei | Verwendung |
|---|---|
| `OpenSans-Regular.ttf` | Standard-Schrift fuer Ticket-Templates |
| `OpenSans-Bold.ttf` | Fette Schrift fuer Ticket-Templates |
| `RobotoMono-Regular.ttf` | Monospace-Schrift fuer Ticket-Codes |

---

## 44. Sicherheit

Tixomat implementiert umfassende Sicherheitsmassnahmen:

### Nonce-Verifizierung

Alle AJAX-Endpoints und Admin-Post-Actions verwenden WordPress-Nonces:

```php
wp_verify_nonce($_POST['_tix_nonce'], 'tix_metabox_save');
```

- Jede Metabox hat eine eigene Nonce (`tix_metabox_nonce`).
- Jeder AJAX-Endpoint prueft die Nonce vor der Verarbeitung.
- Admin-Post-Actions verwenden separate Nonces.

### Capability-Pruefungen

| Bereich | Erforderliche Capability |
|---|---|
| Event erstellen/bearbeiten | `manage_options` |
| Settings aendern | `manage_options` |
| Check-in durchfuehren | `manage_options` |
| Event duplizieren | `manage_options` |
| Event loeschen (Force) | `manage_options` |
| CSV-Export | `manage_options` |
| Orphan-Cleanup | `manage_options` |

Alle Admin-Endpoints pruefen die Capability mit `current_user_can()`:

```php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.', 'tixomat'));
}
```

### Eingabe-Bereinigung (Sanitization)

Alle Eingaben werden vor dem Speichern bereinigt:

| Funktion | Verwendung |
|---|---|
| `sanitize_text_field()` | Einfache Textfelder |
| `sanitize_email()` | E-Mail-Adressen |
| `sanitize_textarea_field()` | Mehrzeilige Textfelder |
| `absint()` | Ganzzahlen (positive) |
| `floatval()` | Preise und Dezimalwerte |
| `wp_kses_post()` | HTML-Inhalte (eingeschraenkt) |
| `sanitize_hex_color()` | Farbwerte |
| `esc_url()` | URLs |

### Ausgabe-Escaping

Alle Ausgaben werden korrekt escaped:

| Funktion | Verwendung |
|---|---|
| `esc_html()` | Textausgaben |
| `esc_attr()` | HTML-Attribute |
| `esc_url()` | URLs |
| `wp_kses_post()` | HTML-Inhalte |
| `esc_js()` | JavaScript-Werte |

### SQL-Sicherheit

- Alle Datenbankabfragen verwenden `$wpdb->prepare()` fuer parametrisierte Queries.
- Keine direkten SQL-Queries mit Benutzereingaben.

### Datei-Uploads

- Ticket-Template-Hintergrundbilder werden ueber die WordPress-Media-Library hochgeladen.
- Erlaubte Dateitypen sind auf Bilder beschraenkt (JPEG, PNG, GIF).
- Dateigroessen-Limits werden von WordPress-Einstellungen geerbt.

---

## 45. Soziales Projekt (Charity)

Tixomat unterstuetzt die Einbindung sozialer Projekte pro Event.

### Aktivierung

1. **Global:** Einstellungen → Erweitert → „Soziales Projekt" → Checkbox aktivieren.
2. **Pro Event:** Event-Editor → Erweitert-Tab → „Soziales Projekt" Card.

### Per-Event Konfiguration

| Feld | Meta-Key | Typ | Beschreibung |
|---|---|---|---|
| Aktiviert | `_tix_charity_enabled` | `1/0` | Charity fuer dieses Event aktiv? |
| Projektname | `_tix_charity_name` | `string` | Name des unterstuetzten Projekts |
| Anteil (%) | `_tix_charity_percent` | `int` | Prozentsatz des Warenkorbs |
| Beschreibung | `_tix_charity_desc` | `string` | Kurzbeschreibung (optional) |
| Bild | `_tix_charity_image` | `int` | Attachment-ID des Logos |

### Frontend-Anzeige

- **Ticket-Selector:** Rosa Banner oberhalb der Ticket-Kategorien mit Logo, Prozent und Projektname.
- **Danke-Seite:** Charity-Info zwischen Bestelluebersicht und Zusatzprodukten.

### Hinweis

Die Charity-Funktion zeigt Informationen an – eine automatische Berechnung oder Abfuehrung des Betrags erfolgt nicht durch das Plugin.

---

## 46. Support-System (CRM + Kunden-Portal)

Tixomat bietet ein integriertes Support-System fuer Kunden-Anfragen, Ticket-Suche und Kommunikation.

### Aktivierung

1. **Global:** Einstellungen → Erweitert → „Support-System" → Checkbox aktivieren.
2. **Admin-Dashboard:** Erscheint als neuer Menuepunkt „Support" unter Tixomat.
3. **Kunden-Portal:** Shortcode `[tix_support]` auf einer beliebigen Seite einbinden.

### Custom Post Type

| Eigenschaft | Wert |
|---|---|
| Post-Type | `tix_support_ticket` |
| UI | Eigenes Admin-Dashboard (kein WP-Standard-UI) |
| Statuses | `tix_open`, `tix_progress`, `tix_resolved`, `tix_closed` |

### Meta-Felder

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_sp_email` | `string` | Kunden-E-Mail (Pflicht) |
| `_tix_sp_name` | `string` | Kundenname |
| `_tix_sp_order_id` | `int` | Verknuepfte WC-Bestellnummer |
| `_tix_sp_ticket_code` | `string` | Verknuepfter Ticket-Code (12-stellig alphanumerisch) |
| `_tix_sp_category` | `string` | Kategorie-Slug |
| `_tix_sp_priority` | `string` | normal / high / urgent |
| `_tix_sp_access_key` | `string` | Zufaelliger 32-Zeichen-Key fuer Gast-Zugriff |
| `_tix_sp_last_reply` | `string` | ISO-Timestamp der letzten Nachricht |
| `_tix_sp_messages` | `JSON` | Nachrichten-Verlauf als JSON-Array |
| `_tix_sp_source` | `string` | Herkunft der Anfrage (`app`, `email`; leer = Portal/Admin) |
| `_tix_sp_mail_thread` | `array` | Message-IDs der letzten 20 Support-Mails an den Kunden (für In-Reply-To/References) |
| `_tix_sp_mail_msgid` | `string` (mehrfach) | SHA1 der Message-ID jeder übernommenen Kunden-Mail (Duplikatschutz) |

### Nachrichten-Format

Jede Nachricht im `_tix_sp_messages`-Array hat folgende Struktur:

| Feld | Typ | Beschreibung |
|---|---|---|
| `id` | `string` | Eindeutige Message-ID (msg_...) |
| `type` | `string` | `customer`, `admin` oder `note` |
| `author` | `string` | Anzeigename des Autors |
| `content` | `string` | Nachrichtentext |
| `date` | `string` | ISO-8601-Timestamp |
| `email` | `string` | Absender-Adresse (nur `customer`) |
| `attachments` | `array` | `[{url, name, mime}]` |
| `source` | `string` | `email` = per E-Mail eingegangen (seit 1.38.340, sonst fehlt das Feld) |

- `customer`: Sichtbar fuer Kunde + Admin, loest Admin-Benachrichtigung aus.
- `admin`: Sichtbar fuer Kunde + Admin, loest Kunden-Benachrichtigung aus.
- `note`: Nur im Admin sichtbar (interne Notiz).

### Admin-Dashboard (4 Tabs)

**Tab 1: Anfragen** – Filterbarer Liste aller Support-Tickets mit Status, Kategorie und Freitext-Suche. Klick oeffnet Inline-Detail mit Nachrichten-Thread, Antwort-Box und Quick Actions.

**Tab 2: Kunden-Suche** – Universelle Suche mit Auto-Erkennung:
- E-Mail → Alle Bestellungen, Tickets und Support-Anfragen des Kunden
- `#12345` → Bestellungs-Details mit zugehoerigen Tickets
- 12-stelliger Code → Ticket-Details mit zugehoeriger Bestellung

**Tab 3: Statistiken** – KPI-Cards (Offen, In Bearbeitung, Heute geloest, Ø Antwortzeit) und 7-Tage-Trend-Chart.

**Tab 4: E-Mail-Eingang** – Postfach-Einstellungen, Test-Verbindung, „Jetzt abrufen“, Rückwärts-Import und Protokoll (siehe unten). Per E-Mail eingegangene Nachrichten tragen im Verlauf das Kennzeichen „per E-Mail“.

### Quick Actions

| Aktion | Beschreibung |
|---|---|
| Ticket oeffnen | Oeffnet den Ticket-Post (tix_ticket) in einem neuen Tab |
| Download-Link kopieren | Kopiert den Ticket-Download-Link in die Zwischenablage |
| Ticket-E-Mail erneut senden | Sendet die Ticket-E-Mail erneut an den Kunden |
| Ticketinhaber aendern | Aendert Name + E-Mail des Ticket-Besitzers |
| Bestellung oeffnen | Link zur WooCommerce-Bestellung |
| Als geloest markieren | Setzt Status auf „Geloest" + Kunden-E-Mail |

In der Anfrage-Detail-Sidebar werden zusaetzlich alle Tickets der verknuepften Bestellung angezeigt, jeweils mit Oeffnen-, Erneut-Senden- und Inhaber-Aendern-Aktionen.

### Kunden-Portal (Frontend)

Der Shortcode `[tix_support]` stellt ein vollstaendiges Kunden-Portal bereit:

1. **Anmeldung:** Eingeloggte User werden automatisch authentifiziert (kein Formular noetig). Gaeste geben E-Mail + Bestellnummer ein.
2. **Meine Anfragen:** Liste aller eigenen Anfragen mit Status und Vorschau
3. **Neue Anfrage:** Kategorie, Bestellungs-Dropdown (eingeloggt) oder Textfeld (Gast), Betreff, Nachricht, optional Ticket-Code
4. **Anfrage-Detail:** Nachrichten-Thread (ohne interne Notizen) + Antwort-Funktion

### Floating Chat-Widget

Optionaler schwebender Chat-Button auf allen Seiten:

- **Aktivierung:** Einstellungen → Erweitert → „Floating Chat-Button anzeigen"
- **Setting:** `support_chat_enabled` (Default: 0)
- Runder Button (56px) unten rechts mit Chat-Panel (380x520px)
- Identische Funktionalitaet wie der Shortcode: Auth, Liste, Erstellen, Detail, Antworten
- Eingeloggte User werden automatisch authentifiziert und sehen ihre Bestellungen als Dropdown
- Responsive: auf Mobile 100% Breite

### E-Mail-Benachrichtigungen

| Trigger | Empfaenger | Betreff |
|---|---|---|
| Neue Anfrage erstellt | Admin | „Neue Support-Anfrage: {Betreff}" |
| Neue Anfrage erstellt | Kunde | „Deine Anfrage wurde empfangen [#{ID}]" |
| Admin antwortet | Kunde | „Neue Antwort zu deiner Anfrage [#{ID}]" |
| Kunde antwortet (Portal, App, E-Mail) | Admin | „Neue Kunden-Antwort: #{ID} – {Betreff}" |
| Status → Geloest | Kunde | „Deine Anfrage wurde geloest [#{ID}]" |

Alle E-Mails nutzen `TIX_Emails::build_generic_email_html()` fuer einheitliches Branding. Kunden-Mails gehen über `TIX_Support_Mail::send_customer_mail()` (Kennzeichen, Reply-To, Threading – siehe „E-Mail-Eingang“), Team-Mails tragen `X-Tixomat-Support: team` (Schleifenschutz, falls `admin_email` = Support-Postfach). Bis 1.38.339 lautete das Format „… #{ID}“ ohne Klammern; der Eingang erkennt beide.

### E-Mail-Eingang: Kunden antworten per Mail (seit 1.38.340)

Ziel: Jede Kundenantwort landet in der Anfrage – auch wenn der Kunde im Mailprogramm auf „Antworten“ drückt (Anlass: kitchenklub.de #925, drei Antworten lagen nur im normalen Postfach).

**Ausgehend (jede Kunden-Mail: Bestätigung, Team-Antwort, „gelöst“):**

| Baustein | Inhalt |
|---|---|
| Betreff | `… [#925]` (festes Format) |
| `Reply-To` | Support-Postfach (nur wenn der Eingang aktiv ist) |
| `Message-ID` | `<tixsp.<id>.<rand8>.<sig16>@<host>>`, `sig` = HMAC-SHA256 (Schlüssel aus `wp_salt('auth')`, je Seite verschieden) – per `phpmailer_init` gesetzt |
| `In-Reply-To` / `References` | frühere Support-Mails der Anfrage (`_tix_sp_mail_thread`) → Mailprogramme zeigen einen Verlauf |
| `Auto-Submitted: auto-generated` | Bestätigung und „gelöst“ (Abwesenheitsnotizen antworten nicht darauf) |
| `X-Tixomat-Support` | `received` / `reply` / `resolved` + Ticket-ID (Schleifenschutz) |
| Text oben | „##- Bitte oberhalb dieser Zeile antworten -##“ (nur bei aktivem Eingang; Schnittkante für das Zitat) |
| Text unten | Knopf **„Im Support antworten“** → Portal-Link `?tix_sp_ticket=<id>&tix_sp_key=<access_key>` (keine E-Mail in der URL), optional „In der App öffnen“ (App-Link-Vorlage), Hinweis „Du kannst auch einfach auf diese E-Mail antworten …“ (nur bei aktivem Eingang), Zeile „Anfrage-Referenz: TIX-<id>-<sig16>“ |

Portal: Der Link öffnet über `tix_support_customer_link` (Anfrage-Nr. + access_key, `hash_equals`) direkt die Anfrage; die Parameter werden danach aus der Adresszeile entfernt. Portal-URL: Feld „Support-Portal“ oder automatisch die erste veröffentlichte Seite mit `[tix_support]` (auch in `_breakdance_data`/`_elementor_data`), 12 h zwischengespeichert. Ohne Portal-Seite entfällt der Knopf.

**Eingehend:** WP-Cron-Hook `tix_support_mail_fetch`, Intervall `tix_every_2min` (bei System-Cron dessen Takt, z. B. Mallorca alle 5 min). Sperre per Option `tix_support_mail_lock` (5 min), höchstens 30 Mails bzw. 45 s je Lauf. IMAP über `TIX_Support_IMAP` – eigener Socket-Client (SSL/TLS, STARTTLS, LOGIN bzw. AUTHENTICATE PLAIN bei Sonderzeichen, UID SEARCH/FETCH/STORE/MOVE), **keine ext-imap nötig** (fehlt ab PHP 8.4). Zertifikate werden geprüft (Filter `tix_support_mail_ssl_verify` nur für Tests).

1. Start: Beim ersten Lauf wird nur die Position gemerkt (`UIDNEXT-1`); Altbestand nur über den Rückwärts-Import. Danach `UID SEARCH UID <letzte+1>:*` (Stand in Option `tix_support_mail_state`, bei geändertem Postfach/UIDVALIDITY neu).
2. Vorprüfung nur mit Kopfzeilen (ohne Download): Duplikat (Message-ID), eigene Mail (`X-Tixomat-Support`, eigene Message-ID, Absender = Support-Postfach), automatische Mail (`Auto-Submitted`≠no, `X-Autoreply`/`X-Autorespond`, `Precedence: bulk|list|junk|auto_reply`, `List-Id`/`List-Unsubscribe`, `multipart/report`, `Return-Path: <>`, mailer-daemon/postmaster/noreply, `X-Spam-Flag: YES`, Betreff „Automatische Antwort“, „Out of Office“, „Unzustellbar“ …). Solche Mails bleiben **unverändert** im Postfach.
3. Zuordnung: (a) signierte Message-ID in `In-Reply-To`/`References`, (b) signierte Referenz `TIX-<id>-<sig>` in Betreff/Text (übersteht das Zitat), (c) `[#ID]` oder altes „Anfrage … #ID“ im Betreff **und** Absender = Kunden-E-Mail der Anfrage. Bei (a)/(b) mit anderer Absenderadresse: übernommen + interne Notiz.
4. Text: `text/plain` bevorzugt, sonst HTML→Text (blockquote, `gmail_quote`, Outlook `#divRplyFwdMsg`/`#appendonsend` entfernt); Schnitt an unserer Kante, „Am … schrieb …:“/„On … wrote:“ (auch zweizeilig), „-----Ursprüngliche Nachricht-----“, Outlook-Kopf „Von:/Gesendet:“, `____`, Signatur „-- “, „Von meinem iPhone gesendet“ u. ä.; `>`-Zeilen raus.
5. Anhänge: wie Portal-Upload (`uploads/tix-support/<id>/`, Typ nach Inhalt: jpg/png/gif/webp/heic/pdf/doc/docx/txt), max. 10 MB/Datei, 10 je Mail; Inline-Bilder < 15 KB (Signatur-Logos) ignoriert; Abgelehntes als interne Notiz. Mails > 30 MB: nur Kopf + Hinweis.
6. Speichern als `customer`-Nachricht mit `source: email`, Datum aus dem `Date`-Header, chronologisch einsortiert; gelöste/geschlossene Anfrage → offen; Team-Mail wie bei Portal-Antwort.
7. Nicht zuordenbar: Einstellung „neue Anfrage anlegen“ (Kategorie `general` = „Allgemein“, Betreff ohne Re:/AW:, Kunde bekommt Bestätigung mit `[#ID]`; höchstens 5 neue Anfragen je Absender und Stunde) oder „ignorieren“.
8. Danach: als gelesen markieren oder in Ordner verschieben (`UID MOVE`; ohne MOVE-Unterstützung kopieren + gelesen). **Es wird nie gelöscht oder expunged.**

**Rückwärts-Import** (Knopf, Vorgabe 30 Tage, `SINCE` = Eingangsdatum im Postfach): gleiche Verarbeitung, Duplikatschutz über Message-ID, keine Team-Mails und keine Bestätigungen; Vorgabe nur Antworten zu bestehenden Anfragen (Haken „auch nicht zuordenbare Mails als neue Anfragen“ für ein reines Support-Postfach). Läuft in Häppchen à 20 s, die Oberfläche ruft bis „Fertig“.

**Einstellungen** (Option `tix_support_mail`, je Seite; Admin: Support → E-Mail-Eingang): `enabled`, `address` (Reply-To), `host`, `port`, `encryption` (`ssl`/`tls`/`none`), `user`, `password_enc` (AES-256-GCM, Schlüssel aus `wp_salt('secure_auth')`; wird nie ausgegeben, Formularfeld leer = behalten; nach Salt-Wechsel Hinweis „neu eingeben“), `folder` (INBOX), `after` (`seen`/`move`), `move_folder` (Tixomat-Verarbeitet), `unmatched` (`ticket`/`ignore`), `portal_url`, `app_link` (z. B. `kitchenklub://support/{id}`). Protokoll der letzten 100 Mails: Option `tix_support_mail_log` (Absender, Betreff, Ergebnis – keine Zugangsdaten).

**Einrichtung:** Am besten ein eigenes Support-Postfach. Ist es das allgemeine Postfach (z. B. mail@kitchenklub.de), zuerst „Nicht zuordenbar: ignorieren“ wählen, sonst wird jede Mail zur Anfrage. Nach dem Einschalten einmal den Rückwärts-Import laufen lassen (holt auch Mails aus den ersten Minuten bis zum ersten Abruf). WP-Cron läuft ohne System-Cron nur bei Seitenaufrufen.

### Support-Kategorien

Standard-Kategorien (konfigurierbar in Einstellungen → Erweitert):
- Ticket nicht erhalten
- Ticketinhaber aendern
- Stornierung / Erstattung
- Fragen zum Event
- Sonstiges

### AJAX-Endpunkte

| Action | Zweck | Auth |
|---|---|---|
| `tix_support_search` | Kunden/Tickets/Bestellungen suchen | Admin |
| `tix_support_list` | Anfragen-Liste mit Filtern | Admin |
| `tix_support_detail` | Einzelne Anfrage laden | Admin |
| `tix_support_reply` | Admin-Antwort senden | Admin |
| `tix_support_note` | Interne Notiz hinzufuegen | Admin |
| `tix_support_status` | Status aendern | Admin |
| `tix_support_resend_ticket` | Ticket-E-Mail erneut senden | Admin |
| `tix_support_change_owner` | Ticketinhaber aendern | Admin |
| `tix_support_create` | Neue Anfrage erstellen | Frontend + Admin |
| `tix_support_customer_auth` | Kunden-Authentifizierung | Frontend |
| `tix_support_customer_list` | Eigene Anfragen laden | Frontend |
| `tix_support_customer_detail` | Eigene Anfrage laden | Frontend |
| `tix_support_customer_reply` | Kunden-Antwort senden | Frontend |
| `tix_support_customer_link` | Link aus der Support-Mail (Anfrage-Nr. + access_key) → Sitzung | Frontend |
| `tix_support_mail_test` | IMAP-Verbindung testen (Formularwerte, Passwort leer = gespeichertes) | Admin |
| `tix_support_mail_run` | Postfach sofort abrufen | Admin |
| `tix_support_mail_backfill` | Rückwärts-Import (`days`, `allow_new`, `after_uid`) | Admin |

Speichern der Postfach-Einstellungen: `admin-post.php?action=tix_support_mail_save` (Nonce `tix_support_mail_save`).

### Dateien

| Datei | Zweck |
|---|---|
| `includes/class-tix-support.php` | PHP: CPT, Admin-Dashboard, AJAX, Shortcode, E-Mails |
| `includes/class-tix-support-mail.php` | PHP: E-Mail-Eingang, Kennzeichen, Einstellungen, Cron, Rückwärts-Import |
| `includes/class-tix-support-imap.php` | PHP: IMAP-Client (Sockets) + MIME-Parser, ohne WordPress-Abhängigkeit |
| `assets/js/support.js` | JS: Admin-Dashboard + Frontend-Portal |
| `assets/css/support.css` | CSS: Admin + Frontend Styles |

---

## 47. Gewinnspiel (Raffle)

`TIX_Raffle` ermoeglicht Gewinnspiele pro Event mit automatischer Auslosung.

### Aktivierung

- Metabox-Tab "Gewinnspiel" im Event-Editor
- `_tix_raffle_enabled` auf `1` setzen
- Titel, Beschreibung, Teilnahmeschluss, max. Teilnehmer und Preise konfigurieren

### Meta-Keys

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_raffle_enabled` | `string` | Gewinnspiel aktiviert (`1` / leer) |
| `_tix_raffle_title` | `string` | Titel des Gewinnspiels |
| `_tix_raffle_description` | `string` | Beschreibung / Teilnahmebedingungen (HTML) |
| `_tix_raffle_end_date` | `string` | Teilnahmeschluss (Y-m-d H:i) |
| `_tix_raffle_max_entries` | `int` | Max. Teilnehmer (0 = unbegrenzt) |
| `_tix_raffle_status` | `string` | Status: `open`, `closed`, `drawn` |
| `_tix_raffle_prizes` | `array` | Preise (Array von {name, qty, type, cat_index}) |
| `_tix_raffle_winners` | `array` | Gewinner nach Auslosung |
| `_tix_raffle_drawn_at` | `string` | Zeitpunkt der Auslosung |

### DB-Tabelle `{prefix}tix_raffle_entries`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | `BIGINT` | Auto-Increment PK |
| `event_id` | `BIGINT` | Event-Post-ID |
| `name` | `VARCHAR(255)` | Teilnehmer-Name |
| `email` | `VARCHAR(255)` | Teilnehmer-E-Mail |
| `created_at` | `DATETIME` | Teilnahme-Zeitpunkt |

### Funktionsweise

1. **Teilnahme**: Besucher tragen Name + E-Mail ein (AJAX `tix_raffle_enter`)
2. **Automatische Auslosung**: Cron `tix_raffle_auto_draw` prueft alle 10 Min ob Teilnahmeschluss erreicht
3. **Manuelle Auslosung**: Admin kann via AJAX `tix_raffle_draw` jederzeit auslosen
4. **Gewinner-Benachrichtigung**: E-Mail an Gewinner mit Preis-Details
5. **Frontend**: Zeigt je nach Status Formular, Countdown oder Gewinnerliste

---

## 48. Event-Seite (tix_event_page)

`TIX_Event_Page` rendert eine komplette Event-Detailseite mit dem Shortcode `[tix_event_page]`.

### Layouts

- **2col** (Standard): Hauptinhalt links, Sidebar rechts (Tickets, Kalender, Location)
- **1col**: Alles untereinander

### Sektionen (2col-Reihenfolge)

**Main-Bereich:** Hero, Titel, Beschreibung, Line-Up, Specials, Galerie, Video, Extra-Info, FAQ, Timetable, Gewinnspiel, Serie
**Sidebar:** Meta-Card (Datum, Ort, Veranstalter, Share), Tickets, Kalender, Charity, Location, Organizer

### Steuerung

Jede Sektion kann ueber Settings-Toggles ein-/ausgeschaltet werden:
`ep_show_hero`, `ep_show_gallery`, `ep_show_video`, `ep_show_faq`, `ep_show_location`, `ep_show_organizer`, `ep_show_series`, `ep_show_charity`, `ep_show_upsell`, `ep_show_calendar`, `ep_show_phases`, `ep_show_raffle`, `ep_show_share`, `ep_show_timetable`

### Social-Sharing

Share-Buttons in der Meta-Card: WhatsApp, Facebook, X (Twitter), E-Mail, Link kopieren.
Konfigurierbar ueber `ep_show_share` Setting.

### Feedback-Badge

Wenn Feedback aktiv (`feedback_enabled`) und Bewertungen vorhanden, wird ein Sterne-Badge im Titel angezeigt (z.B. "4.3 (12 Bewertungen)").

---

## 49. Rabattcode-Generator

Event-spezifische Rabattcodes, die als echte WooCommerce-Coupons erstellt werden.

### Metabox-Tab "Rabattcodes"

Repeater-Tabelle mit folgenden Spalten:

| Spalte | Typ | Beschreibung |
|---|---|---|
| Code | `text` | z.B. "EARLY20" (auto-generierbar per Button) |
| Typ | `select` | `percent` (Prozent) oder `fixed_cart` (Festbetrag) |
| Wert | `number` | Rabatt-Wert (z.B. 20 bei 20%) |
| Limit | `number` | Max. Einloesungen (0 = unbegrenzt) |
| Ablaufdatum | `date` | Optionales Ablaufdatum |
| Genutzt | `readonly` | Aktuelle Nutzungszahl |

### Meta-Key

`_tix_discount_codes` -- Array von:
```
{code, type, amount, limit, expiry, coupon_id}
```

### WooCommerce-Integration

- Jeder Code wird als `WC_Coupon` erstellt/aktualisiert
- Coupon ist auf die Event-Produkte beschraenkt (`set_product_ids()`)
- Markierung via `_tix_event_coupon` Meta am Coupon
- Beim Loeschen wird der WC_Coupon in den Papierkorb verschoben

---

## 50. Presale-Countdown & Warteliste

`TIX_Waitlist` sammelt E-Mail-Adressen fuer Presale-Benachrichtigungen und Wartelisten bei ausverkauften Events.

### Meta-Keys (Event)

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_presale_start` | `datetime-local` | Wann startet der Vorverkauf? |
| `_tix_waitlist_enabled` | `string` | Warteliste fuer dieses Event aktiv (`1` / leer) |

### DB-Tabelle `{prefix}tix_waitlist`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | `BIGINT` | Auto-Increment PK |
| `event_id` | `BIGINT` | Event-Post-ID |
| `email` | `VARCHAR(255)` | E-Mail-Adresse |
| `name` | `VARCHAR(255)` | Name (optional) |
| `type` | `ENUM` | `presale` oder `soldout` |
| `notified` | `TINYINT` | Bereits benachrichtigt? |
| `created_at` | `DATETIME` | Eintragszeitpunkt |

UNIQUE KEY auf `(event_id, email, type)`.

### Ticket-Selektor-Integration

1. **Presale noch nicht gestartet**: Countdown + "Benachrichtige mich"-Formular
2. **Alle Online-Tickets ausverkauft**: Warteliste-Formular
3. **AJAX**: `tix_waitlist_join` fuegt Eintrag hinzu

### Cron

`tix_waitlist_check` (alle 10 Min):
- Prueft ob Presale gerade gestartet hat → benachrichtigt `type=presale` Eintraege
- Prueft ob Stock zurueckgekehrt ist → benachrichtigt `type=soldout` Eintraege

### Settings

- `waitlist_enabled` (global Toggle, default: 1)

---

## 51. Post-Event Feedback

`TIX_Feedback` ermoeglicht Sterne-Bewertungen (1-5) und Kommentare nach dem Event.

### DB-Tabelle `{prefix}tix_feedback`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | `BIGINT` | Auto-Increment PK |
| `event_id` | `BIGINT` | Event-Post-ID |
| `order_id` | `BIGINT` | WooCommerce-Bestell-ID |
| `email` | `VARCHAR(255)` | E-Mail des Bewerters |
| `name` | `VARCHAR(255)` | Name |
| `rating` | `TINYINT` | Sterne (1-5) |
| `comment` | `TEXT` | Freitext-Kommentar |
| `token` | `VARCHAR(64)` | Sicherheits-Token |
| `created_at` | `DATETIME` | Bewertungszeitpunkt |

UNIQUE KEY auf `(event_id, email)`.

### Token-System

```
token = hash('sha256', order_id + '|' + event_id + '|' + email + '|' + wp_salt())
```

Nur echte Ticket-Kaeufer koennen bewerten (Token in Follow-Up-E-Mail).

### Shortcode `[tix_feedback]`

| Zustand | Anzeige |
|---|---|
| Token gueltig, kein Feedback | Sterne-Formular + Textarea |
| Token gueltig, bereits bewertet | "Danke fuer dein Feedback!" |
| Kein Token (oeffentlich) | Durchschnittsbewertung |

### Follow-Up-E-Mail

In `TIX_Emails::send_followup()` werden klickbare Sterne-Links eingefuegt.
Klick oeffnet die Feedback-Seite mit vorausgefuelltem Rating.

### Caching

Durchschnitt und Anzahl werden als Post-Meta gecacht:
- `_tix_feedback_avg` -- Durchschnittsbewertung (1.0-5.0)
- `_tix_feedback_count` -- Anzahl Bewertungen

### Settings

- `feedback_enabled` (global Toggle, default: 1)

---

## 52. Timetable / Programm (Multi-Stage)

`TIX_Timetable` zeigt ein mehrtaegiges Programm mit mehreren Buehnen/Raeumen.

### Meta-Keys

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_stages` | `array` | Buehnen: `[{name: 'Hauptbuehne', color: '#6366f1'}, ...]` |
| `_tix_timetable` | `array` | Slots pro Tag: `{'2026-06-15': [{time, end, stage, title, desc}, ...]}` |

### Metabox-Tab "Programm"

1. **Buehnen-Repeater**: Name + Farbpicker pro Buehne
2. **Tages-Tabs**: Automatisch aus Event-Datumsspanne generiert
3. **Slot-Tabelle pro Tag**: Startzeit, Endzeit, Buehne (Dropdown), Titel, Beschreibung

### Frontend-Ansichten

**Desktop (>768px):** CSS-Grid mit Spalten pro Buehne
- `grid-template-columns: 60px repeat(var(--tt-stages), 1fr)`
- Buehnen-Header mit farbigen Akzenten
- Slot-Cards mit `color-mix()` Hintergruenden

**Mobil (<=768px):** Listenansicht
- Alle Slots chronologisch sortiert
- Buehnen-Filter-Buttons zum Filtern nach Buehne
- Stage-Badge mit Buehnen-Farbe

### Tages-Tabs

Buttons zum Wechseln zwischen Veranstaltungstagen. JS schaltet aktive Day-Panes um.

### Shortcode

`[tix_timetable]` oder `[tix_timetable id="123"]`

### Event-Seite

Position: Nach FAQ, vor Gewinnspiel (in beiden Layouts).
Steuerbar ueber `ep_show_timetable` Setting.

---

## 53. Statistiken

`TIX_Statistics` zeigt Verkaufsstatistiken im Admin-Bereich.

---

## 54. Saalplan (Seatmap)

`TIX_Seatmap` ermoeglicht die Erstellung und Verwaltung von Saalplaenen mit Sektionen, Plaetzen und Preiskategorien.

### DB-Tabelle

Eigene Tabelle fuer Saalplan-Daten (Sektionen, Reihen, Plaetze).

---

## 55. Promoter-System

`TIX_Promoter` ermoeglicht Affiliate-/Promoter-Tracking mit individuellen Links und Provisionsberechnung.

### Klassen

- `TIX_Promoter` -- Tracking-Logik (Cookie, Zuordnung)
- `TIX_Promoter_DB` -- Datenbank-Tabellen (Promoter, Clicks, Sales)
- `TIX_Promoter_Admin` -- Admin-Menue + Verwaltung
- `TIX_Promoter_Dashboard` -- Frontend-Dashboard fuer Promoter

### Settings

- `promoter_enabled` (global Toggle, default: 0)

---

## 56. Daten-Synchronisierung

### Supabase (`TIX_Sync_Supabase`)

Synchronisiert Ticket-Daten in eine Supabase-Datenbank (PostgreSQL).

### Airtable (`TIX_Sync_Airtable`)

Synchronisiert Ticket-Daten in eine Airtable-Base.

### Custom Ticket-DB (`TIX_Ticket_DB`)

Optionale lokale Datenbank-Tabelle fuer schnelle Ticket-Abfragen (parallel zum CPT).

---

## 57. Veranstalter-Dashboard (Organizer)

`TIX_Organizer_Dashboard` stellt ein vollstaendiges Frontend-Dashboard fuer externe Veranstalter bereit. Veranstalter koennen Events erstellen, bearbeiten, Bestellungen einsehen, Gaestelisten verwalten und Statistiken abrufen -- ohne wp-admin-Zugang.

### Aktivierung

1. **Setting**: `organizer_dashboard_enabled` auf `1` setzen (Tixomat → Einstellungen → Features)
2. **Rolle**: WP-User mit Rolle `tix_organizer` erstellen
3. **Mapping**: Im `tix_organizer` CPT-Editor den User unter "Verknuepfter Benutzer" auswaehlen (`_tix_org_user_id`)
4. **Seite**: WordPress-Seite mit Shortcode `[tix_organizer_dashboard]` erstellen

### Shortcode

```
[tix_organizer_dashboard]
```

### User-Mapping (Ownership-Chain)

```
WP User (user_id)
  → tix_organizer CPT (via _tix_org_user_id = user_id)
  → event CPT (via _tix_organizer_id = organizer_post_id)
  → WC Products (via _tix_parent_event_id = event_id)
  → WC Orders (via order items mit _tix_event_id)
```

### Dashboard-Tabs

| Tab | Beschreibung |
|---|---|
| Uebersicht | KPI-Cards + 30-Tage-Verkaufschart (Chart.js) |
| Meine Events | Event-Karten mit Status, Datum, Kapazitaet. Neues Event (Wizard), Bearbeiten (Editor-Overlay), Duplizieren, Loeschen |
| Bestellungen | Tabelle aller WC-Orders fuer eigene Events. Filter nach Datum und Event |
| Gaesteliste | Manuelle Gaeste + verkaufte Tickets kombiniert. Check-In per Toggle |
| Statistiken | KPIs mit Event-Filter-Dropdown |
| Einstellungen | Profil (Anzeigename) |

### Event-Editor

- **Wizard** (neue Events): 3 Schritte (Grunddaten → Tickets → Zusammenfassung). Event wird als `draft` gespeichert
- **Editor** (bestehende Events): 9 Tabs (Grunddaten, Info, Tickets, Medien, FAQ, Rabattcodes, Gewinnspiel, Programm, Vorverkauf)
- Media-Upload via `media_handle_upload()`

### Settings

| Key | Default | Beschreibung |
|---|---|---|
| `organizer_dashboard_enabled` | `0` | Dashboard global aktivieren |
| `organizer_auto_publish` | `0` | Events automatisch veroeffentlichen statt Draft |

### Dateien

| Datei | Beschreibung |
|---|---|
| `includes/class-tix-organizer-dashboard.php` | Hauptklasse (Shortcode, 16 AJAX-Endpoints, Rendering) |
| `assets/css/organizer-dashboard.css` | Dashboard-Styling (`.tix-od-*`) |
| `assets/js/organizer-dashboard.js` | Tab-Navigation, AJAX, Event-Karten |
| `assets/css/organizer-event-editor.css` | Editor-Styling (`.tix-oe-*`) |
| `assets/js/organizer-event-editor.js` | Wizard + Editor (9 Tabs) |

---

## 58. KI-Schutz (Content Guard)

`TIX_Content_Guard` prueft Event-Inhalte automatisch via Anthropic Claude API auf verbotene, diskriminierende oder schaedliche Inhalte, bevor sie veroeffentlicht werden. Das Feature ist ueber Einstellungen → Erweitert → KI-Schutz an-/ausschaltbar.

### Funktionsweise

1. Veranstalter klickt "Veroeffentlichen" → `save_post_event` Hook feuert.
2. `TIX_Content_Guard::check()` (Prioritaet 12) laeuft nach `TIX_Metabox::save()` (Prioritaet 10).
3. Content wird gesammelt: **Titel + URL-Slug + Excerpt + Info-Sektionen**.
4. Content-Hash (MD5) wird mit letztem geprueftem Hash verglichen → bei Uebereinstimmung: Skip (keine API-Kosten).
5. Text wird an Anthropic Claude API (Modell: `claude-3-5-haiku-20241022`) gesendet.
6. Claude antwortet mit JSON: `{"approved": true}` oder `{"approved": false, "reason": "..."}`.
7. **Genehmigt**: Event wird veroeffentlicht. Meta `_tix_ai_approved = 1`.
8. **Abgelehnt**: Event wird auf Entwurf zurueckgesetzt. Slug wird auf `event-entwurf-{id}` sanitized. Admin-Notice mit Begruendung.

### Fail-Closed Design

Bei **jedem API-Fehler** (Netzwerk, Timeout, HTTP 401/429/529, Parse-Fehler) wird das Event **nicht veroeffentlicht**. Fehlermeldung wird als Admin-Notice angezeigt.

| Fehler | Meldung |
|---|---|
| Kein API-Key | "KI-Schutz ist aktiviert, aber kein API-Key hinterlegt." |
| HTTP 401 | "Ungueltiger API-Key (HTTP 401). Bitte pruefen." |
| HTTP 429 | "Rate-Limit erreicht (HTTP 429). Bitte kurz warten." |
| HTTP 529 | "Anthropic API ueberlastet (HTTP 529). Bitte kurz warten." |
| Netzwerk/Timeout | "Netzwerk-Fehler: ..." |
| Parse-Fehler | "KI-Antwort konnte nicht geparst werden: ..." |

### Slug-Sanitierung

Wenn Content abgelehnt wird, setzt `sanitize_slug()` den Permalink auf `event-entwurf-{post_id}` zurueck. Dies verhindert, dass verbotene Begriffe in der URL stehen bleiben -- auch wenn das Event nur als Entwurf gespeichert wird.

### Content-Hash-Cache

Ein MD5-Hash des geprueften Contents wird in `_tix_ai_content_hash` gespeichert. Wird dasselbe Event erneut gespeichert, ohne dass sich Titel/Slug/Excerpt/Infos geaendert haben, wird kein neuer API-Call gemacht. Kosten pro Pruefung: ca. 0,001 EUR.

### Pruefungskriterien

Das System prueft auf:
1. Hassrede, rassistische Beleidigungen, Slurs, Diskriminierung
2. Gewaltverherrlichung oder Aufrufe zu Gewalt
3. Illegale Inhalte oder Werbung fuer illegale Aktivitaeten
4. Betrug, Spam, Phishing oder irrefuehrende Inhalte
5. Sexuell explizite oder pornografische Inhalte
6. Terrorismus-Verherrlichung oder Extremismus
7. Persoenlichkeitsrechtsverletzungen oder Doxxing

Normale Events (Konzerte, Partys, Festivals, Messen, Sport, Workshops) sind immer erlaubt.

### Einstellungen

| Key | Typ | Beschreibung |
|---|---|---|
| `ai_guard_enabled` | `int` (0/1) | KI-Inhaltspruefung aktivieren |
| `anthropic_api_key` | `string` | Zentraler Anthropic API Key (unter KI-Einstellungen) |
| `openai_api_key` | `string` | OpenAI API Key (optional, fuer GPT-Modelle) |
| `ai_model` | `string` | KI-Modell fuer Assistent/Textgenerierung (Standard: `claude-sonnet-4-20250514`) |

### Post-Meta

| Meta-Key | Typ | Beschreibung |
|---|---|---|
| `_tix_ai_approved` | `string` (0/1) | Event genehmigt |
| `_tix_ai_flagged` | `string` (0/1) | Event abgelehnt |
| `_tix_ai_flag_reason` | `string` | Ablehnungsgrund (deutsch) |
| `_tix_ai_content_hash` | `string` (md5) | Hash des zuletzt geprueften Contents |
| `_tix_ai_checked_at` | `int` (Unix) | Zeitstempel der letzten Pruefung |

### Hook-Prioritaet

```
save_post_event:
  Prio 10 → TIX_Metabox::save()       (Meta-Daten + Pflichtfeld-Validierung)
  Prio 12 → TIX_Content_Guard::check() (KI-Inhaltspruefung)
  Prio 20 → TIX_Sync::sync()           (WooCommerce-Sync)
  Prio 25 → TIX_Series::on_save()      (Serientermine)
```

### Dateien

| Datei | Beschreibung |
|---|---|
| `includes/class-tix-content-guard.php` | Hauptklasse (~360 Zeilen): API-Call, Content-Sammlung, Hash-Cache, Draft-Revert, Slug-Sanitierung, Admin-Notices |

---

## 59. REST API (tixomat/v1)

Seit v1.34.0 bietet das Plugin eine vollstaendige REST API fuer die Tixomat-App (iOS/Android) und Drittanbieter-Integrationen.

### Authentifizierung

| Methode | Header | Verwendung |
|---|---|---|
| Application Passwords | `Authorization: Basic base64(user:app-password)` | Admin, Organizer |
| Token (Kunden-App) | `X-Tix-Token: {token}` oder `Authorization: Bearer {token}` | Kunden |

Tokens werden bei `/auth/login` generiert und als SHA-256 Hash in `_tix_app_token` User-Meta gespeichert.

### Endpoints

| Methode | Route | Beschreibung | Auth |
|---|---|---|---|
| GET | `/info` | Plugin-Version, Features, aktive Module | Public |
| GET | `/me` | Aktueller User (ID, Name, E-Mail, Rollen, Avatar) | Auth |
| GET | `/events` | Events des Organizers (Filter: status, search, per_page) | Auth |
| GET | `/events/{id}` | Einzel-Event mit allen Meta-Daten | Auth |
| PUT | `/events/{id}` | Event aktualisieren | Auth |
| POST | `/checkin/scan` | QR-Code scannen, Body: `{"code":"ABC123"}` | Auth |
| GET | `/checkin/{event_id}/list` | Gaesteliste (Filter: search, status) | Auth |
| PATCH | `/checkin/{event_id}/guest/{guest_id}` | Gast-Status aktualisieren | Auth |
| PATCH | `/checkin/ticket/{ticket_id}/toggle` | Ticket ein-/auschecken | Auth |
| GET | `/events/{id}/guestlist` | Vollstaendige Gaesteliste | Auth |
| PUT | `/events/{id}/guestlist` | Gaesteliste Bulk-Update | Auth |
| GET | `/events/{id}/tickets` | Verkaufte Tickets eines Events | Auth |
| POST | `/tickets/{id}/resend-email` | Ticket-E-Mail erneut senden | Auth |
| GET | `/pos/events/{id}/categories` | POS Ticket-Kategorien + Bestand | Auth |
| POST | `/pos/orders` | POS-Order erstellen | Auth |
| POST | `/pos/orders/{id}/email` | POS-Tickets per E-Mail senden | Auth |
| POST | `/pos/orders/{id}/void` | POS-Order stornieren | Auth |
| GET | `/pos/report` | Tagesbericht (Filter: date, event_id) | Auth |
| GET | `/pos/transactions` | Transaktionsliste | Auth |
| POST | `/auth/login` | Login (E-Mail + Passwort) -> Token | Public |
| POST | `/auth/register` | Neuen Kunden registrieren | Public |
| GET/PUT | `/auth/profile` | Kundenprofil lesen/aktualisieren | Token |
| POST | `/auth/profile/avatar` | Avatar hochladen (multipart) | Token |
| GET | `/customer/tickets` | Tickets des Kunden (Filter: status) | Token |
| GET | `/customer/events` | Vergangene + kommende Events des Kunden | Token |

### Dateien

| Datei | Beschreibung |
|---|---|
| `includes/class-tix-rest-api.php` | REST API Klasse, alle Endpoints, Token-Auth |

### App-Vertrag: worauf sich KitchenKlub- und evendis-App verlassen (Stand 1.38.368)

Die nativen Apps (Monorepo `tixomat-apps`: `apps/kitchenklub`, `apps/evendis`, Paket `tixomat_core`) lesen die folgenden Routen und Feldnamen direkt. **Feldnamen und Bedeutungen nicht umbenennen oder entfernen** – nur ergänzen. KitchenKlub ist als Store-App ausgeliefert; alte App-Versionen bleiben monatelang im Umlauf.

| Bereich | Route / Feld | Nutzt | Seit |
|---|---|---|---|
| Kontobestätigung | `POST /auth/register` mit `verify=1` → kein Token, Antwort `{verification_required, email, expires_in}`, Nutzer-Meta `_tix_app_unverified`, Code-Mail „Bestätige dein Konto“ | KitchenKlub ab Build 36, evendis | 1.38.324 |
| Kontobestätigung | `POST /auth/code` und `POST /auth/code/confirm` mit `purpose=verify` (neben `login`/`reset`), Bestätigung liefert Token + `verified` | beide | 1.38.324 |
| Kontobestätigung | `POST /auth/login` lehnt unbestätigte Konten mit `code=account_unverified` (403, `data.email`) ab | beide | 1.38.324 |
| Registrierung alt | `POST /auth/register` ohne `verify` → sofort Token + Willkommens-Mail (keine WordPress-„Passwort festlegen“-Mail) | ältere KitchenKlub-Builds | 1.38.324 |
| Event-Katalog | `GET /public/events` je Event zusätzlich `organizer_info {id,slug,name,logo}`, `category {id,slug,name}`, `venue {id,name,city,zip,lat,lng}`, `modules {tickets,music,loyalty,giftcards,support,muttizettel}`; Filter `category`, `organizer`, `city`, `q`; Seiten `page`/`per_page` (≤200) mit `total`/`has_more` | evendis | 1.38.325 |
| Plattform | `GET /public/organizers` (Filter `q`, `city`, `category`), `GET /public/organizers/{id\|slug}` (+ `description`, `email`, `phone`, `address`, `events`), `GET /public/categories` (`upcoming`), `GET /public/cities` | evendis | 1.38.325 |
| Merkliste / Folgen | `GET/POST /customer/favorites`, `POST\|DELETE /customer/favorites/{event_id}` (User-Meta `_tix_saved_events`), dasselbe für `/customer/following` (`_tix_app_following`) | evendis | 1.38.325 |
| Veranstalter-Module | Post-Meta `_tix_org_modules` (JSON) am `tix_organizer`, Vorgabe: nur `tickets` | evendis | 1.38.325 |
| Mehr-Veranstalter | Option `tix_multi_organizer=1` (nur evendis.de): Veranstalter-Routen auf eigene Events begrenzt (`TIX_App_Scope`) | evendis | 1.38.326 |
| Geteilte Events | `GET /public/events[/{id}]` je Event `syndicated {site, checkout_url, sale_via_app, terms_url}` (sonst `null`). Geteilte Events (`_tix_syndicated=1`) haben `tickets_enabled=false`, **außer** `sale_via_app=true` (Quelle im Partner-Verzeichnis freigeschaltet + Häkchen am Event): dann verkauft die App über die Vermittlung. Ohne Vermittlung: `POST /customer/cart/quote` liefert `syndicated` und `sale_open=false`; Angebot mit Positionen und `POST /customer/orders` lehnen mit `code=tix_syndicated` (409, `data.syndicated`) ab; Web-Kasse lehnt ebenfalls ab. | evendis | 1.38.333 |
| Geteilte Events: Kauf | Mit `sale_via_app=true`: `POST /customer/cart/quote`, `POST /customer/orders`, `GET /customer/orders/{id}` **unverändertes Format**; Preise/Gebühren/Zahlarten/`payment_url` kommen von der Quelle. Zusätzlich im Angebot `seller {name, terms_url, privacy_url, revocation_url, notice}`, in der Bestellung `order.seller {name, mail_from_seller}`. Bestell-IDs vermittelter Bestellungen ≥ 800000001, `order_number` = Nummer der Quelle. | evendis | 1.38.334 |
| Support | `GET /customer/support`, `GET /customer/support/{id}`, `POST /customer/support/{id}/reply` **unverändert**. Neu nur ergänzend: Nachrichten, die per E-Mail eingingen, tragen `source: "email"` (sonst fehlt das Feld), `type` bleibt `customer`; sie erscheinen chronologisch im Verlauf (`date` = Sendezeit der Mail). Antworten per Mail öffnen gelöste Anfragen wieder (`status` → `tix_open`). | KitchenKlub, evendis | 1.38.340 |
| Support: Feed/Push | Bei jeder Team-Antwort (Admin → Support → Antworten; nicht bei internen Notizen, Kunden-Antworten oder reinem Statuswechsel) persönlicher Feed-Eintrag + Push über `TIX_Notifications::add_user_item`: `type: "support"`, Titel „Neue Antwort auf deine Anfrage“, Text „#<id> · <Betreff>: <Antwort, max. 120 Zeichen>“ (ohne Betreff „Anfrage #<id>: …“), **Aktion `support:ticket:<id>`** = Verlauf der Anfrage öffnen (`GET /customer/support/{id}`; Kunde muss mit dem Konto der Anfrage-E-Mail angemeldet sein). Empfänger: WordPress-Konto zur Kunden-E-Mail (Rückfall `post_author` bei gleicher E-Mail), ohne Konto nur Mail. KitchenKlub Store-Build 38 kennt die Aktion noch nicht und öffnet das Support-Formular (harmlos). Mail-Knopf „Im Support antworten“: `<Portal-Seite>?tix_sp_ticket=<id>&tix_sp_key=<access_key>#tix-sp-frontend` (KitchenKlub: `https://kitchenklub.de/kontakt/…`). | KitchenKlub, evendis | 1.38.341 |
| Universal Links | `https://<seite>/.well-known/apple-app-site-association` (auch `/apple-app-site-association`): 200, `application/json`, ohne Weiterleitung/Cache, `{"applinks":{"details":[{"appIDs":[…],"components":[{"/":"*","?":{"tix_sp_ticket":"?*"},"comment":"Support-Verlauf in der App"}]}]}}` – nur Links mit `tix_sp_ticket` öffnen die App. App-IDs aus Option `tix_app_link_ids` (Liste „TEAMID.bundle“, Admin: Support → E-Mail-Eingang → „Universal Links“), leer = 404. kitchenklub.de: `6W25PJB528.de.kitchenklub.app`, evendis.de: `6W25PJB528.de.evendis.app`. Code: `includes/class-tix-app-links.php`. | KitchenKlub, evendis | 1.38.342 |
| Geteilte Events: Tickets | `GET /customer/tickets` enthält Spiegel-Tickets (gleicher Code wie bei der Quelle, `event_id` = Plattform-Event, QR `GL-<event_id>-<code>` gilt am Einlass der Quelle). Storniert = `status=cancelled` (bleibt sichtbar), eingecheckt/übertragen kommen per Webhook. Ticket-Antwort für Spiegel zusätzlich `mirror: true`, `source_site` (Name der Quelle). Post-Meta `_tix_ticket_mirror=1`, `_tix_ticket_source_site`. | evendis | 1.38.335 |
| Abrechnung: Berechtigung | Alle Routen unten: nur Mehr-Veranstalter-Modus (sonst 403 `tix_not_multi`), nur Inhaber/Team-Admin eines freigegebenen Veranstalters (`TIX_App_Scope::check_manager`: 401 `rest_not_logged_in`, 403 `rest_forbidden`/`organizer_pending`/`no_organizer`), immer nur der eigene Veranstalter. Beträge als Zahl in Euro (2 Nachkommastellen), Datum `YYYY-MM-DD`, Abzüge als positive Zahlen. | evendis | 1.38.343 |
| Abrechnung: Auszahlungsdaten | `GET /organizer/payout-details` → `{complete, holder, iban_masked ("DE89 •••• •••• •••• 3000"), iban_last4, has_iban, bic, billing {company,name,street,zip,city,country}, tax_status (vat_id\|tax_number\|small_business\|""), vat_id, tax_number, email, pending_iban, pending_email}` (volle IBAN nie). `POST /organizer/payout-details` (Felder wie GET, `iban` nur bei neuer IBAN) → Objekt wie GET; neue IBAN → zusätzlich `verify_required: true`, `code_sent_to` (Code an die Konto-E-Mail des Inhabers, 15 min). `POST …/confirm {code}` → Objekt wie GET (IBAN aktiv + Hinweis-Mail); Fehler 400 `tix_code_invalid` (`data.remaining`), 410 `tix_code_expired`, 429 `tix_code_locked`. `POST …/resend` → `{code_sent_to}`. Validierung 400 `tix_invalid_iban`, 400 `tix_invalid_field` mit `data.field` ∈ bic, country, tax, vat_id, tax_number, email; 429 `tix_rate_limited` (5 Codes/Stunde). | evendis | 1.38.343 |
| Abrechnung: Gebühren-Modus | `GET /organizer/fee-mode[?price=]` → `{mode (organizer\|split\|customer), default_mode, own_choice, choosable, modes, applies_to: "new_orders", fee {fixed, percent, max_per_ticket\|null, max_per_order\|null, label, split_customer_share, rounding}, example, examples {organizer,split,customer}, gateway_note}`; je Beispiel `{price, fee, fee_customer, fee_organizer, customer_pays, payment_fee, you_receive_before_payment_fee, you_receive}` (Server rechnet inkl. Rundung/Höchstbeträgen; `you_receive` nach Plattform- und geschätzter Zahlungsgebühr). `POST /organizer/fee-mode {mode}` → wie GET; ungültig 400 `tix_invalid_field` (`field=mode`). Gespeichert als `_tix_fee_override=1` + `_tix_fee_mode` am Veranstalter. | evendis | 1.38.343 |
| Abrechnung: Saldo | `GET /organizer/balance` → `{currency, sold_total, pending_total, paid_out_total, open_balance, next_payout: null\|{amount, date, settlement_id\|null, status}, payout_details_complete, delay_days}`; `next_payout.status = pending` = noch nicht abgerechnet (Schätzung). | evendis | 1.38.343 |
| Abrechnung: Liste/Detail | `GET /organizer/settlements?page=&per_page=` → `{items, total, has_more}`; Element `{id, number ("EV-2026-0001"), type (event\|balance\|advance), event_id, event_title, event_date, status (draft\|ready\|approved\|paid\|held), payout_amount, payout_date (geplant bzw. ausgezahlt), paid_at, has_pdf, has_invoice, invoice_number}`. `GET /organizer/settlements/{id}` → Element + `{period_from, period_to, gross, refunds, platform_fee, gateway_fees, corrections (±), advances, carry_over, customer_fees, pos_total, tickets, orders, held_reason, tax_mode (agency\|reseller), note, lines [{label, amount (±), type}], iban_masked}`; fremde/unbekannte 404 `tix_not_found`. | evendis | 1.38.343 |
| Abrechnung: PDF | `GET /organizer/settlements/{id}/pdf[?doc=invoice]` → JSON `{filename, mime: "application/pdf", data (base64)}`; Abrechnung/Gutschrift/Abschlag bzw. Gebührenrechnung; 404 `tix_no_pdf` bei `draft` oder ohne Rechnung. | evendis | 1.38.343 |
| Abrechnung: Feed/Push | `TIX_Notifications::add_user_item` an Inhaber + Team-Mitglieder mit Rolle `tix_organizer`: `type: "settlement"`, Aktion `settlement:<id>` („Abrechnung für <Event> erstellt“, „<Betrag> wurde überwiesen“), Aktion `payout:details` („Abrechnung … erstellt“, solange Auszahlungsdaten fehlen). | evendis | 1.38.343 |
| Veranstalter-Freigabe | `GET /me` → im Objekt `organizer` zusätzlich `approval` (nur Mehr-Veranstalter-Modus, sonst fehlt das Feld). `organizer.status` bleibt der Post-Status (`publish`) – maßgeblich für die Freigabe ist `approval.status`. Wartende/abgelehnte/gesperrte Veranstalter behalten Zugriff auf Profil, Events, Auszahlungsdaten und Abrechnungen (`check_manager` unverändert). | evendis | 1.38.346 |
| Veranstalter-Freigabe | `GET /organizer/approval` (Inhaber + Team, 401 `rest_not_logged_in`, 403 `tix_not_multi`/`no_organizer`) → `{status (pending\|approved\|rejected\|blocked), label, title, message, reason, can_sell, can_publish, can_resubmit, complete, missing [keys], requirements [{key, label, done, target (profile\|payout\|terms), hint}], terms {title, url, version, required, accepted, accepted_version, accepted_at}, submitted_at, decided_at}` (Datum ISO-8601 UTC oder `null`). Schlüssel der Pflichtangaben: `name`, `address`, `tax`, `payout` (Ziel `payout` = Auszahlungsdaten), `contact` (Ziel `profile` = Telefon `phone` in `POST /organizer/profile`), `terms` (nur wenn ein Vertrag eingerichtet ist). | evendis | 1.38.346 |
| Veranstalter-Freigabe | `POST /organizer/approval/terms {version}` (Inhaber/Team-Admin) → Objekt wie GET; andere Version 409 `tix_terms_outdated` (`data.terms`), kein Vertrag 409 `tix_no_terms`. `POST /organizer/approval/resubmit` → Objekt wie GET (`status=pending`), nur bei `rejected`, sonst 409 `tix_not_rejected`; nicht Inhaber/Team-Admin 403 `rest_forbidden`. | evendis | 1.38.346 |
| Veranstalter-Freigabe | Solange nicht `approved`: Veröffentlichen wird zurückgehalten – `POST /events` bzw. `POST /events/{id}` mit `published=true` speichert Status `pending`; Editor-Objekt (`GET /events/{id}/edit`, Antworten von Anlegen/Speichern) zusätzlich `publish_held: true` (`published=false`, `post_status=pending`); Listen-/Detail-Objekt (`GET /events`, `GET /events/{id}`) zusätzlich `post_status` und `publish_held` (immer, alle Seiten); `GET /events` liefert im Mehr-Veranstalter-Modus auch `pending`-Events. `POST /events/{id}` mit `published=false` für ein zurückgehaltenes Event lässt den Veröffentlichungswunsch stehen (die App sperrt den Schalter bis zur Freigabe). Bei Freigabe wird automatisch veröffentlicht. `POST /pos/orders`, `POST /customer/cart/quote` (mit Positionen) und `POST /customer/orders` lehnen mit `code=organizer_not_approved` (403, `data.approval`) ab; Web-Kasse ebenfalls. Gesperrt (`blocked`): veröffentlichte Events werden genauso zurückgehalten. Geteilte Events (`_tix_syndicated=1`) sind nie betroffen. | evendis | 1.38.346 |
| Veranstalter-Freigabe: Feed/Push | Bei jeder Entscheidung (freigegeben, abgelehnt, gesperrt, erneut in Prüfung) `TIX_Notifications::add_user_item` an Inhaber + Team-Mitglieder mit Rolle `tix_organizer`: `type: "organizer"`, **Aktion `organizer:approval`** = Veranstalter-Bereich mit Status-Karte öffnen. Admins bekommen bei neuer Registrierung optional `type: "admin"` ohne Aktion. | evendis | 1.38.346 |
| Event-Inhalte | `GET /public/events/{id}` zusätzlich (leer/`null`, wenn nicht gepflegt): `gallery_items [{url, thumb}]`, `faq [{question, answer (HTML)}]`, `timetable {times_tba, stages [{name,color}], days [{date, slots [{time,end,stage,stage_name,title,description}]}]}`, `video {url, type (youtube\|vimeo\|file\|external), embed_url}`, `dresscode` (Text), `entry_rules`/`ticket_notes` (HTML), `charity {name, percent, description, image}`, `series [weitere Termine]`, `box_office {price, description}` (nur Abendkasse), `external_shop {url, text, mode (replace\|both)}`, `presale {starts_at (ISO, '' = offen), started, waitlist_presale, waitlist_soldout}`, `raffle {title, description (HTML), status, end_date, entries\|null, max_entries, consent_text, prizes [{name, qty, per_winner, free_ticket}], winners [{name (Vorname + Initiale), prize}]}\|null` | beide | 1.38.351 |
| Gewinnspiel / Warteliste | `POST /public/events/{id}/raffle {name, email, consent}` → `{ok, message, entries}` (400 `tix_raffle` mit Grund); `POST /public/events/{id}/waitlist {email, type (presale\|soldout)}` → `{ok, message}` (400 `tix_waitlist`) | beide | 1.38.351 |
| Preise / Mengenrabatt | Server rechnet alle Stückpreise zentral (`TIX_Cart_Pricing`, Web-Kasse = App-Kasse). Mengenrabatt ist **in `unit_price`/`subtotal` eingerechnet**. Neu: `categories[].phase {name, until (Y-m-d)}\|null`, `quote.group_discount` und `/public/events/{id}.group_discount` = `{tiers [{min_qty, percent}], combine_bundle, combine_combo, combine_phase}\|null`, `quote.presale`, `totals.group_discount` (Ersparnis, nur Anzeige), `lines[].list_price\|null` (Preis vor Mengenrabatt), `lines[].group_discount\|null` (Prozent), `lines[].event_id`. Pakete/Kombis zählen je Paket als 1 Stück. | beide | 1.38.353 |
| Vorverkauf | Vor `_tix_presale_start` bzw. nach Vorverkaufsende: `quote.sale_open=false`; Angebot mit Positionen/Bestellung 409 `tix_presale_not_started` (`data.starts_at`) bzw. `tix_presale_closed` | beide | 1.38.353 |
| Pakete | `categories[].bundle {buy, pay, name, package_price, max_packages}\|null`; bestellen mit `items[] {index, bundle: true, qty: <Anzahl Pakete>}`; Zeile `lines[].bundle {buy, pay, packages}`; ohne Paket an der Kategorie 400 `tix_bundle` | beide | 1.38.353 |
| Extras (Specials) | `quote.specials` und `/public/events/{id}.specials_offer` = `[{special_id, name, description, price, value\|null, image, quantity_available (-1 = unbegrenzt), sold_out, max_per_order}]` (nur wenn Specials aktiv und am Event in Auswahl/Checkout); bestellen mit `items[] {special_id, qty}`; Zeile `lines[].special_id`, `index=-1`. Hinweis: `specials` im Event-Detail bleibt der HTML-Infotext. | beide | 1.38.354 |
| Gutscheine | Gleiche Prüfung wie Web (Promoter-Codes, Geschenkgutscheine, Einschränkungen). `quote.coupon.message` = Grund, wenn der Code nicht gilt (`valid=false`). Gast kann `email` mitsenden (für „einmal pro E-Mail“). `POST /customer/orders` mit ungültigem Code oder „einmal pro E-Mail“ verletzt → 409 `tix_coupon` (vorher still ignoriert). | beide | 1.38.354 |
| Saalplan | `quote.seatmap` und `/public/events/{id}.seatmap` (bool). `GET /public/events/{id}/seatmap[?hold_token=]` → `{seatmap_id, mode (manual\|best), hold_minutes, layout {width,height,layout,stage_label}, sections [{id,label,color,price,total,available}], map [Sektionen mit rows[].seats[] {id,x,y,type (standard\|vip\|wheelchair\|blocked)}], taken [ids], held [eigene ids]}`. `POST /customer/seats/hold {event_id, seat_ids[], hold_token?}` → `{ok, hold_token, reserved[], failed[], expires_at (ISO)}` (Token beim ersten Aufruf vom Server, danach immer mitschicken). `POST /customer/seats/best {event_id, section_id, qty, hold_token?}` (findet + hält; 409 `tix_no_seats`), `POST /customer/seats/release {event_id, seat_ids[], hold_token}`. Bestellen: `items[] {seats: [...]}` + top-level `hold_token` in quote/orders; Preis = Bereichspreis, je Bereich eine Zeile (`lines[].seats`); fremd belegt 409 `tix_seat_taken`, fehlender Token 400 `tix_hold_token`. Plätze sind nach Zahlung bzw. Vorkasse verkauft, bei Storno frei. | beide | 1.38.355 |
| Kombi-Tickets | `quote.combos` und `/public/events/{id}.combos` = `[{combo_id, name, price, regular_price, sold_out, max_per_order, parts [{event_id, event_title, category}]}]`; bestellen mit `items[] {combo_id, qty}`; je Bestandteil eine Zeile (`lines[].combo {combo_id, group_id, name}`, `lines[].event_id` kann ein Partner-Event sein), Summe = Kombi-Preis; nicht (mehr) verfügbar 409 `tix_combo` | beide | 1.38.356 |
| Tischreservierung | `/public/events/{id}.tables` (bool). `GET /public/events/{id}/tables` → `{event {…, floor_plan}, categories [{index, name, desc, min_spend, min_guests, max_guests, price, quantity, available, tables [{name,x,y,reserved}]}], payment_mode (on_site\|deposit\|full), deposit_type, deposit_value, info_text}`. `POST /customer/table-reservations {event_id, category_index, table_name?, guest_count, customer_name, customer_email, customer_phone?, comments?, payment_method?}` (Konto: Name/E-Mail aus dem Konto) → `{status: confirmed}` oder `{status: payment_required, checkout_url, order_id}` (Zahlung im Browser; Bestätigung + Mail erst nach Zahlung, offene Zahlung hält den Tisch 60 min); Fehler 400 `tix_table`. Nur wenn Tischreservierung in den Einstellungen aktiv ist (sonst 404). | beide | 1.38.357 |
| Audit Event-Felder | Ergänzt, was die Website zeigt: `categories[].low_stock_text` (Knappheits-Hinweis wie Ticketauswahl, '' = keiner), `presale.ends_at` (ISO, '' = offen/manuell), `upsell [{id, title, date_start, time_start, location, image, url}]` (Empfehlungen des Veranstalters), `video` auch aus Einbettungscode, `venue_info {image, short_description, description (HTML), address}\|null` (Ort-Profil), `ticket_sponsor {image, link}\|null`, `ticket_transfer` (bool). Tickets (`GET /customer/tickets`) zusätzlich `sponsor {image, link}\|null`, `can_transfer`. `POST /customer/tickets/{id}/transfer {first_name, last_name, email}` (Konto, eigenes Ticket) → `{ok, name, count}`; danach gehört das Ticket der neuen E-Mail; 403/400 `tix_transfer`, fremd 404. | beide | 1.38.361 |
| Freier Eintritt | Event → Tickets: „Freier Eintritt (keine Tickets nötig)“ (nur ohne Online-Verkauf, schließt „Nur Abendkasse“ aus), Meta `_tix_free_entry` + optional `_tix_free_entry_note`. `GET /public/events[/{id}]` je Event `free_entry` (bool) + `free_entry_note` (Text, '' = keiner). Website: Ticket-Bereich „Eintritt frei“ + Hinweis, Event-Karten „Eintritt frei“, Anzeige-Metas (`_tix_price_card`/`_tix_price_range`/`_tix_price_label` = „Eintritt frei“, `_tix_price_from_display` = „Frei“), Body-Klasse `tix-free-entry` (evendis-Vorlage zeigt „Eintritt“ statt „ab“). **Seit 1.38.364:** „Eintritt frei“ auf Event-Karten und Event-Startseite nur noch mit Schalter oder bei Online-Tickets für 0 € (`TIX_Event_Extras::shows_free_entry`) – vorher geraten aus „kein Preis“; kein Haken = keine Angabe zum Eintritt (Hinweis im Admin). **Seit 1.38.365:** „Eintritt frei“ in der Akzentfarbe (`--tix-card-signal` bzw. `--ev-signal`) statt Türkis – Karten, Event-Startseite, Preisblock und Ticket-Bereich. Außerdem: `presale.ends_at` ist ohne Online-Verkauf immer '' (vorher automatisch berechnet, Apps zeigten „Vorverkauf endet …“). | beide | 1.38.363–365 |
| App-Tipps (Tipp des Tages) | `GET /public/tips[?placement=<slug>]` (öffentlich, 60 s Transient je Platz) → `{tips: [{id, title, text, author, author_image (medium), image (large, '' = keins), image_small (medium), label (Standard „Tipp des Tages“), placements [slugs], event_id (0 = keins), event (Listen-Payload wie `/public/events`, nur veröffentlicht, sonst `null`), link (URL oder App-Aktion wie `event:123`/`page:faq`, '' = keiner), starts_at/ends_at (ISO mit Zeitzone, '' = offen), order}]}`; leer = `{"tips":[]}`. Nur veröffentlichte Tipps im gültigen Zeitraum, Sortierung `menu_order` ↑, dann Datum ↓. Ohne eigenes Bild, aber mit Event: `image`/`image_small` leer → App nimmt das Event-Bild. Mehrere Tipps am selben Platz = Slider. Plätze: `home_top` (Startseite oben, unter der Suche), `home_middle`, `home_bottom`, `event_detail` (Event-Seite unter den Infos), `tickets` (Tickets-Tab oben), `saved` (Merkliste oben, evendis), `day_results` (Ergebnisse nach Datum, evendis, seit 1.38.367), `account` (Konto oben); unbekannter Platz = leere Liste (ohne Cache-Eintrag, seit 1.38.370). Pflege: CPT `tix_app_tip` (Tixomat → App-Tipps, alle Rechte `manage_options` – auch im Mehr-Veranstalter-Modus nicht für Veranstalter), `includes/class-tix-app-tips.php`, Meta `_tix_tip_text`, `_tix_tip_author`, `_tix_tip_author_image` (Anhang-ID), `_tix_tip_image` (Anhang-ID), `_tix_tip_event_id`, `_tix_tip_label`, `_tix_tip_link`, `_tix_tip_placements` (Array), `_tix_tip_start`/`_tix_tip_end` ('Y-m-d H:i', Seiten-Zeitzone, leer = unbegrenzt). Cache-Version-Option `tix_app_tips_cache_v` (hochgezählt bei Tipp speichern/löschen/Statuswechsel und Event speichern/löschen). | beide | 1.38.366 |
| App-Tipps: Verwaltung (Liste) | `GET /app/tips` (nur WordPress-Admins `manage_options` per `X-Tix-Token`; alle anderen – auch Veranstalter/Team im Mehr-Veranstalter-Modus – 403 `rest_forbidden`; ohne/abgelaufenes Token seit 1.38.370 401 `rest_not_logged_in`) → `{tips: [...], placements: [{slug, label}]}`. Jeder Tipp = Felder wie `/public/tips` **plus** `status` (`publish`\|`draft`), `author_image_id`, `image_id` (0 = keins), `start`/`end` im Eingabeformat `YYYY-MM-DDTHH:MM` ('' = unbegrenzt, Seiten-Zeitzone), `active` (bool: veröffentlicht und Zeitraum gilt gerade). Alle Tipps inkl. Entwürfe und abgelaufene (ohne Papierkorb), Sortierung `menu_order` ↑, dann Datum ↓. `event_id` nennt das verknüpfte Event auch, wenn es nicht (mehr) veröffentlicht ist – `event` ist dann `null` (in `/public/tips` bleibt es bei `event_id: 0`). `placements` = alle Plätze in fester Reihenfolge mit Admin-Beschriftung. | beide | 1.38.368 |
| App-Tipps: anlegen/ändern | `POST /app/tips` (JSON) → 201 `{tip}` (Format wie in der Liste). Felder: `title`, `text`, `author`, `label`, `link`, `event_id`, `placements[]` (auch kommagetrennt), `start`, `end` (`YYYY-MM-DDTHH:MM` in Seiten-Zeitzone, ISO mit Zeitzone wie `2026-10-10T08:00:00Z`/`…+02:00` → umgerechnet, oder '' = unbegrenzt), `status` (`publish`\|`draft`, Standard `publish`; sonst 400 `tix_tip_status`), `order` (int → `menu_order`), optional `author_image_id`, `image_id` (Anhang-ID; 0 oder kein Anhang = entfernen). Bereinigung wie die Admin-Metabox (gemeinsame Methode `TIX_App_Tips::apply_fields`): unbekannte Plätze fallen weg, unbekanntes Event → 0, Label leer → „Tipp des Tages“, Link = URL (http/https/mailto/tel) oder App-Aktion als Text. Seit 1.38.370: ungültiges, nicht leeres Datum → 400 `tix_tip_date` (vorher still '' = unbegrenzt), ebenso Ende ≤ Start (beim Ändern gegen den gespeicherten Wert). Ohne `title` nimmt der Server die ersten Wörter des Texts (sonst „App-Tipp“), weil WordPress leere Beiträge ablehnt. `POST /app/tips/{id}` ändert nur die übergebenen Felder → `{tip}`; ID ist kein Tipp oder liegt im Papierkorb → 404 `tix_not_found`. | beide | 1.38.368 |
| App-Tipps: löschen | `POST /app/tips/{id}/delete` oder `DELETE /app/tips/{id}` → `{ok: true}` (Papierkorb, danach weder in `/app/tips` noch in `/public/tips`); unbekannt → 404 `tix_not_found`. | beide | 1.38.368 |
| App-Tipps: Bild hochladen | `POST /app/tips/{id}/image` multipart: `file` (JPEG/PNG/WebP, max. 8 MB; `image` als Feldname geht auch), `kind` = `author` (Porträt → `_tix_tip_author_image`) oder `image` (Tipp-Bild → `_tix_tip_image`) → `{tip}` mit neuen URLs/IDs. Datei landet in der Mediathek (Anhang am Tipp, `media_handle_sideload` wie Flyer-Upload). Fehler 400: `no_file`, `upload_failed`, `too_large`, `bad_type`, `tix_tip_kind`. Entfernen über `POST /app/tips/{id} {image_id: 0}` bzw. `{author_image_id: 0}`. | beide | 1.38.368 |
| App-Tipps: Event-Auswahl | `GET /app/tips/events` → `{events: [{id, title (Entities dekodiert), date_start (YYYY-MM-DD)}]}` – kommende veröffentlichte Events (Startdatum ab heute), nach Datum ↑, max. 200 (gleiche Abfrage wie die Auswahl in der Metabox). | beide | 1.38.368 |
| Tage / Zeitraum | `GET /public/events` zusätzlich `from=YYYY-MM-DD`, `to=YYYY-MM-DD` (beide einschließlich, Zeitzone der Seite; fehlt einer, gilt der andere): Event trifft, wenn seine **Tage** den Zeitraum schneiden. Tage = Starttag bis Endtag, ein Ende **vor 06:00 Uhr** zählt nicht als weiterer Tag (Party 23–5 Uhr nur am Starttag; Ende 06:30 = zwei Tage), ohne Enddatum nur der Starttag (`TIX_Public_Events::day_span`). Kombinierbar mit `filter` (Standard `upcoming` – bereits beendete Events fehlen; für vergangene Tage `filter=all`) und den übrigen Filtern. Falsches Format/`to` vor `from` → 400 `tix_range`. | evendis | 1.38.367 |
| Umkreis | `GET /public/events` zusätzlich `near=LAT,LNG` + `radius=KM` (Standard 30, höchstens 200): nur Events, deren `venue` Koordinaten hat und im Umkreis liegt (Haversine); jedes Event dann zusätzlich `distance_km` (1 Nachkommastelle). Sortierung bleibt nach Datum. Falsches `near` → 400 `tix_near`. Ohne die neuen Parameter sind Antwort und Cache-Schlüssel unverändert. Seit 1.38.370 filtert die Datenbank vor (`TIX_Public_Events::date_window_clause`: `upcoming` Start ≥ gestern oder Ende ≥ gestern, `past` Start ≤ heute, Zeitraum Start ≤ `to` und Start/Ende ≥ `from`; ebenso `/public/events/days`) – vorher belegten vergangene Events die 1000 Plätze der Abfrage (älteste zuerst) und kommende fehlten. Events ohne `_tix_date_start` erscheinen dadurch nicht mehr unter `upcoming`. | evendis | 1.38.367 |
| Tage-Leiste | `GET /public/events/days?from=&to=[&city=][&category=][&organizer=]` → `{ok, from, to, days: {"2026-10-16": 3, …}}` – kommende, veröffentlichte Events je Tag nach derselben Tage-Regel (mehrtägige Events an jedem ihrer Tage, vergangene Tage zählen nicht), Tage ohne Event fehlen, leer = `{}`. Standard `from` = heute, `to` = `from` + 30 Tage; höchstens 62 Tage, sonst 400 `tix_range`. 60 s Transient (`tix_pub_events_days_*`). | evendis | 1.38.367 |
| Wiederkehrende Events | `GET /public/events[/{id}]` je Event zusätzlich `recurring` (bool: Serie oder Termin daraus, auch klassische Serientermine) und `recurrence_parent` (int, Master-ID; 0 beim Master/normalen Event). `series` (Detail, „Weitere Termine“) enthält bei fortlaufender Wiederholung den Master + alle künftigen veröffentlichten Termine (ohne das aktuelle Event). Termine sind normale `event`-Posts mit eigener ID (Tickets, Check-in, Merkliste je Termin). Details siehe „Wiederkehrende Events“ unten. | evendis | 1.38.367 |
| Tag vormerken | `GET /customer/day-alerts` → `{ok, alerts: [{date, city}]}` (nach Datum sortiert, vergangene Tage fallen weg); `POST /customer/day-alerts {date, city?}` → `{ok, alerts}` (gleicher Tag + Stadt ohne Groß/Klein nicht doppelt; vergangener Tag/falsches Format 400 `tix_date`, mehr als 20 → 400 `tix_alert_limit`); `DELETE /customer/day-alerts?date=YYYY-MM-DD[&city=]` bzw. `POST /customer/day-alerts/delete {date, city?}` → `{ok, alerts}` (ohne `city` alle Alarme des Tages). Auth wie `/customer/*` (`X-Tix-Token`, sonst 401). User-Meta `_tix_day_alerts`. | evendis | 1.38.367 |
| Tag vormerken: Feed/Push | Wird ein Event veröffentlicht (auch ein neuer Serientermin), bekommen Nutzer mit passendem Alarm (Event-Tage ab heute enthalten `date`; `city` leer oder gleich `venue.city`, ohne Groß/Klein) `TIX_Notifications::add_user_item`: `type: "reminder"`, Titel „Neu am <Wochentag>, <dd.mm.>“ (z. B. „Neu am Freitag, 16.10.“), Text = Event-Titel, `event_id`, **Aktion `event:<id>`**. Je Nutzer und Event nur einmal (Post-Meta `_tix_day_alert_sent` = Nutzer-IDs). Hook `wp_after_insert_post` (nach dem Speichern der Metas) + direkt nach Registrierungs-/Dashboard-Formular, seit 1.38.370 auch nach App-Anlage (`POST /events` mit `published`) und Syndication-Empfang (neu bzw. erstmals veröffentlicht) – dort fehlten Datum/Ort beim Veröffentlichen noch. | evendis | 1.38.367 |
| Erinnerung am Event-Tag | Stündlicher Cron (`tix_app_notif_cron` → `TIX_Notifications::cron_reminders`): veröffentlichte Events von heute, ab `_tix_app_notif_reminder_hours` (Standard 6) Stunden vor Beginn bis 2 h danach. Empfänger: Ticketinhaber mit App-Konto (Konto per `_tix_ticket_owner_email`, ohne Groß/Klein), **nicht** stornierte Tickets (`_tix_ticket_status=cancelled`), bei weitergegebenen Tickets (`transferred`) der Empfänger (`_tix_ticket_transfer_to`). Hinweis `add_user_item`: `type: "reminder"`, Titel „Heute: <Event>“, Text „Es geht los – Beginn HH:MM Uhr. Deine Tickets findest du in der App.“, `event_id`, Aktion `event:<id>`. Nie als Rundnachricht. Einmal je Event (Option `_tix_app_notif_reminded`, sofort nach jedem Event gespeichert) und je Nutzer (User-Meta `_tix_notif_rem_<event_id>`, vor dem Versand gesetzt – ein abgebrochener Lauf macht ohne Doppelversand weiter); erinnerte Events werden in der Abfrage ausgeschlossen (höchstens 200 je Lauf). | beide | 1.38.370 |
| Heute geöffnet | `GET /public/venues/open?date=YYYY-MM-DD[&city=][&near=LAT,LNG&radius=KM]` (Standard `date` = heute) → `{ok, date, weekday (mon…sun), venues: [{id, name, city, zip, address (Straße), image (large, '' = keins), lat, lng (null ohne Koordinaten), opens "22:00", closes "05:00", overnight (bool, closes ≤ opens = nach Mitternacht), hours [{open, close}] (alle Zeitfenster des Tages), tagline, organizer_info {id,slug,name,logo}\|null, distance_km (nur mit near)}]}`. Nur gelistete (`_tix_loc_listed=1`), veröffentlichte Orte mit Öffnungszeiten an diesem Wochentag; `opens`/`closes` = erstes Zeitfenster. Sortierung: mit `near` nach Entfernung, sonst nach `opens`, dann Name. Falsches Datum 400 `tix_date`, falsches `near` 400 `tix_near`. 60 s Transient. | evendis | 1.38.367 |

### Wiederkehrende Events, Tages-Alarme, Orte mit Öffnungszeiten (seit 1.38.367)

**Wiederkehrende Events** (`includes/class-tix-recurrence.php`, `TIX_Recurrence`). Neben den klassischen Serienterminen (`TIX_Series`, legt alle Termine auf einmal an, Master ist nur Vorlage) gibt es die *fortlaufende Wiederholung*: Das Event selbst ist der erste Termin („Serie“, Master); das Plugin legt die nächsten Termine **bis 8 Wochen im Voraus** als eigene `event`-Posts an – täglich per Cron (`tix_recurrence_daily`) und direkt nach dem Speichern des Masters (`save_post_event`, Priorität 30). Beides lässt sich nicht kombinieren (klassische Serie aktiv → keine Wiederholung und umgekehrt).

| Meta (Master) | Bedeutung |
|---|---|
| `_tix_recurrence` | `none` \| `weekly` \| `biweekly`; Wochentag = Wochentag von `_tix_date_start` |
| `_tix_recurrence_until` | Enddatum (`Y-m-d`, optional) |
| `_tix_recurrence_count` | Anzahl Termine **inkl. Master** (0 = unbegrenzt, höchstens 26) |
| `_tix_recurrence_skip` | Termine, die endgültig gelöscht wurden (werden nicht neu angelegt) |

| Meta (Termin) | Bedeutung |
|---|---|
| `_tix_recurrence_parent` | Master-ID |
| `_tix_recurrence_date` | Termin (`Y-m-d`) – Idempotenz über Master + Datum, keine Dubletten |
| `_tix_recurrence_hash` | Fingerabdruck des Masters beim letzten Abgleich |
| `_tix_recurrence_auto_trashed` | vom Plugin in den Papierkorb gelegt (zählt nicht als „von Hand gelöscht“) |

- **Kopie:** Titel, Inhalt, Kurztext, Beitragsbild, alle Taxonomien (Sparte), Veranstalter, Ort und alle `_tix_*`-Metas **außer** Statistik/Verkauf/Check-in/Bestellung/Sync (u. a. `_tix_sold_*`, `_tix_status*`, `_tix_product_ids`, `_tix_guest_list`, `_tix_syndicate_*`/`_tix_syndicated*`/`_tix_source_*`, `_tix_ai_*`, `_tix_feedback_*`, `_tix_raffle_status/_winners/_drawn_at`, `_tix_publish_held`, `_tix_promoted*`, `_tix_api_key*`, Anzeige-Metas mit Datum `_tix_date_*`/`_tix_time_*`/`_tix_doors_*`/`_tix_calendar_*`/`_tix_is_past*`, `_tix_day_alert_sent`, seit 1.38.370 auch `_tix_archived*` (Auto-Archiv des vergangenen Masters blendete sonst alle Termine aus) und `_tix_event_status` (Absage/Verschiebung gilt nur für den einen Termin)). `_tix_partner_sales` (Häkchen „über die Plattform verkaufen“) wird seit 1.38.370 mitkopiert. Liste: `TIX_Recurrence::SKIP_PREFIXES`/`SKIP_KEYS`.
- **Datum:** Start/Ende/Einlass verschoben (Ende am Folgetag bleibt), ebenso `_tix_presale_start`, `_tix_presale_end`, `_tix_raffle_end_date`, Preisphasen (`phases[].until`) und Programm-Tage mit Datum als Schlüssel. Slug `<titel>-<datum>`.
- **Kategorien:** Bestand (`stock`) = Kontingent (`qty`, 0 = unbegrenzt ohne `stock`), `product_id`/`sku`/`tc_event_id` geleert. Danach dieselbe Nachbearbeitung wie beim Speichern: `TIX_Sync::sync` (WooCommerce-Produkte bzw. Anzeige-/Preis-Metas) und `TIX_App_Events::resolve_status` (`_tix_status`).
- **Status:** wie der Master (`publish`/`private`/`pending`, sonst Entwurf) – veröffentlicht nur, wenn der Master veröffentlicht ist. Veröffentlicht wird als letzter Schritt über `wp_update_post` (löst Tages-Alarme, Verteilung usw. aus). Während des Abgleichs ist `$_POST` geleert, damit Formular-Hooks (Event-Metabox) die Termine nicht mit Master-Daten überschreiben.
- **Änderungen am Master** (Titel, Inhalt, Bild, Infos, Preise/Kategorien …) gehen an alle künftigen Termine **ohne verkaufte Tickets** (nicht stornierte `tix_ticket`-Posts bzw. WooCommerce-Verkäufe); Termine mit Verkäufen bleiben unangetastet. Als Verkauf zählen seit 1.38.370 auch native Bestellungen ohne Tickets (`tix_order_items`, Bestellstatus nicht `cancelled`/`failed`/`refunded`/`trash`, z. B. Zahlung offen) und ein Bestand unter Kontingent (`stock < qty`) – sonst setzte der Abgleich reservierten Bestand zurück. Erkannt über den Fingerabdruck – greift auch, wenn sich der Master ohne Speichern-Hook ändert (nächster Cron).
- **Abschalten / Master in den Papierkorb oder gelöscht / Wochentag oder Ende geändert:** künftige Termine ohne Verkäufe → Papierkorb; es entstehen keine neuen. Von Hand gelöschte Termine werden nicht neu angelegt.
- **Übernommene Events** (Syndication, `_tix_syndicated=1`, seit 1.38.370): nie eigene Termine – die Quelle (Partnerseite) wiederholt und überträgt jeden Termin einzeln. `TIX_Recurrence::mode` = `none`, `parent_of` = 0, `children` ohne übernommene Events (deren Serien-Metas trugen fremde Post-IDs). Push und Empfang lassen `_tix_recurrence*` weg; der Empfang löscht vorhandene Serien-Metas und räumt daraus hier angelegte künftige Termine ohne Verkäufe weg (ebenso der tägliche Cron).
- **Duplizieren** (Admin-Liste, Veranstalter-Dashboard) übernimmt seit 1.38.370 keine `_tix_recurrence*`-Metas – die Kopie ist ein eigenes Event.
- **Admin:** Tab „Serientermine“ → Abschnitt „Wiederholung“ (Felder `tix_recurrence`, `tix_recurrence_until`, `tix_recurrence_count`, Marker `tix_recurrence_form`, gleiche Nonce `tix_nonce`), Liste der künftigen Termine; im Termin ein Hinweis mit Link zum Serien-Event. Admin-Liste: Hinweis „Serie“ bzw. „Termin aus Serie #ID“ (`display_post_states`). Veranstalter-Dashboard: Felder „Wiederholung“ im Assistenten und unter „Grunddaten“ (`recurrence`, `recurrence_until`, `recurrence_count`).

**Tages-Alarme** (`includes/class-tix-day-alerts.php`, `TIX_Day_Alerts`): Routen und Benachrichtigung siehe App-Vertrag. Speicher User-Meta `_tix_day_alerts` = `[{date, city}]` (höchstens 20).

**Nur eintragen (ohne Ticketverkauf):** Registrierung `[tix_register_event]` (Schritt „Vorschau“) und Veranstalter-Dashboard (Assistent + Tab „Tickets“) bieten „Tickets: Online verkaufen \| Nur eintragen (Eintritt frei) \| Nur eintragen (Abendkasse, Preis optional)“ (POST-Feld `ticket_mode` = `online`\|`free`\|`box_office`, `box_office_price`). Nur eintragen setzt `_tix_tickets_enabled=0` und `_tix_free_entry=1` bzw. `_tix_box_office_only=1` + `_tix_box_office_price` (leer = ohne Preis); Ticket-Kategorien sind dann nicht nötig. Ohne `ticket_mode` bleibt alles wie bisher (Registrierung = Online-Verkauf).

**Orte mit Öffnungszeiten + Koordinaten** (`includes/class-tix-venues.php`, `TIX_Venues`): Location → Metabox „App: Ort & Öffnungszeiten“.

| Meta (`tix_location`) | Bedeutung |
|---|---|
| `_tix_loc_listed` | `1` = in der App als Ort mit Öffnungszeiten zeigen |
| `_tix_loc_hours` | JSON `{"mon":[{"open":"22:00","close":"05:00"}], …}` (`mon`…`sun`; `close` ≤ `open` = nach Mitternacht) |
| `_tix_loc_tagline` | Kurzzeile für die App (leer → `_tix_loc_short_desc`) |
| `_tix_loc_organizer_id` | Veranstalter (optional, → `organizer_info`) |
| `_tix_loc_lat` / `_tix_loc_lng` | Koordinaten (von Hand oder automatisch) |
| `_tix_loc_geo_auto`, `_tix_loc_geo_query`, `_tix_loc_geo_failed` | automatisch ermittelt / Suchtext / letzter erfolgloser Suchtext |

Bild: `_tix_loc_image_id` (vorhandenes Feld), sonst Beitragsbild. **Geocoding:** Beim Speichern einer Location ohne Koordinaten, aber mit Straße fragt das Plugin einmal Nominatim (`https://nominatim.openstreetmap.org/search?format=json&limit=1&q=<Straße, PLZ Ort, Land>`, User-Agent `Tixomat/<Version> (<home_url>)`, Timeout 5 s); Fehler werden ignoriert und der Suchtext gemerkt (kein erneuter Versuch beim nächsten Speichern). Automatisch ermittelte Koordinaten werden nach einer Adressänderung neu bestimmt, von Hand eingetragene nie überschrieben. Seit 1.38.370 auch direkt nach dem Anlegen einer Location mit Adresse über das Inline-Formular im Event-Editor und die Registrierung `[tix_register_event]` (dort stand die Adresse beim Speichern-Hook noch nicht fest). Nachtragen: `wp tixomat geocode-locations [--dry-run] [--sleep=1]` (nur Locations ohne Koordinaten, 1 Anfrage pro Sekunde, versucht auch frühere Fehlschläge erneut).

### Ticket-Optionen der nativen Kasse (seit 1.38.353)

Alle Seiten verkaufen nur über die native Kasse (kein WooCommerce). Web-Kasse (`TIX_Native_Checkout`) und App-Kasse (`TIX_App_Checkout`) rechnen über denselben Preisdienst `TIX_Cart_Pricing` (`includes/class-tix-cart-pricing.php`):

- `reprice($items)` berechnet jeden Stückpreis neu (idempotent): Preisphase/Sale (`TIX_Dynamic_Pricing`), Paket `bundle_buy/bundle_pay` (Stückpreis × pay/buy, ungerundet), Special (`TIX_Specials::get_effective_price`), Sitzplatz (Preis des Saalplan-Bereichs), Kombi (Kombi-Preis anteilig nach Einzelpreisen), fester Preis (`locked_price`). Danach Mengenrabatt `_tix_group_discount` je Event in die Stückpreise (`meta.list_price`, `meta.group_discount`); Zählweise wie die Ticketauswahl (Pakete/Kombis je Paket, nur wenn kombinierbar; mit aktiver Preisphase nur bei `combine_phase`).
- Gutschein-Rabatt danach auf die Positionen ohne Servicegebühr: `resolve_coupon` (tix_coupons, Geschenkgutschein, Promoter-Code), `coupon_discount`, `checkout_coupon_error` (mit Rechnungs-E-Mail vor dem Bestellen).
- Vorverkauf: `presale_error($event_id)` beim Hinzufügen und Bezahlen (Web) bzw. in `sale_open`/Angebot (App).
- Kombi-Tickets: `combo()/combos()/combo_lines()`; Gruppen nur vollständig zum Kombi-Preis (sonst `meta.combo.broken` → Einzelpreis + Meldung `combo_error` beim Bezahlen). Entfernen/Menge ändern gilt für die ganze Kombi.
- Saalplan (`TIX_Seatmap`): `hold/release/secure_cart/attach_order`; Halter = Warenkorb-Schlüssel (`TIX_Native_Checkout::session_key`) bzw. `app_<hold_token>`. Vor dem Bestellen 30 min gesichert; verkauft bei Status `completed/processing/on-hold` (`tix_order_status_changed`), frei bei `tix_order_cancelled`; Doppelverkauf → Bestellnotiz. Zeitvergleich in UTC.
- Tischreservierung (`TIX_Table_Reservation::submit`): Anzahlung/Vollzahlung als native Bestellung (Zeile `meta.table_reservation`, kein Eintrittsticket), bestätigt bei `tix_order_completed`.
- Gemeinsam buchen (`TIX_Group_Booking`, seit 1.38.358 auch ohne WooCommerce): Mitglieder wählen Kategorie-Index/Pakete/Kombis, Organisator legt alles über `TIX_Native_Checkout::add_items()` in seinen Warenkorb (`meta.group_member` → Ticket-Meta `_tix_group_member`), Kostensplit über Transient `tix_group_order_data_<md5(Warenkorb-Schlüssel)>`. In der App (noch) nicht verfügbar – Web-Funktion mit Teilen-Link.

### Geteilte Events verkaufen (Partner-Vermittlung, seit 1.38.334)

evendis.de (Plattform) vermittelt, die Quellseite (z. B. kitchenklub.de) verkauft: Geld, Preise, Gebühren, Tickets, Bestand und Einlass bleiben beim Veranstalter. Alles ist aus, bis es konfiguriert ist.

| Seite | Einstellung | Wirkung |
|---|---|---|
| Plattform | Tixomat → **Partner** (Option `tix_partners`): Kennung, Name, API-Basis der Quelle, zugeordneter `tix_organizer`, „Verkauf über diese Seite“, AGB-Link; je Partner eigener **API Key** (`key_in`, Quelle → Plattform) und **Partner-Schlüssel** (`key_out`, Plattform → Quelle) | Quelle wird am Schlüssel erkannt (nicht am Namen); gemeinsamer Empfangs-Key abschaltbar über „Gemeinsamen Empfangs-Key akzeptieren (alt)“ (`syndication_shared_key_enabled`, Vorgabe an; auf evendis.de seit 2026-10-05 aus) |
| Quelle | Einstellungen → Event-Verteilung (Senden): API Key = `key_in`; „Verkauf über die Plattform erlauben“ (`partner_api_enabled`) + Partner-Schlüssel (`partner_api_key` = `key_out`) | Partner-API frei; Webhooks an die Plattform |
| Quelle | Event → Event-Verteilung: Häkchen „Tickets auch über die Plattform verkaufen“ (`_tix_partner_sales`, Vorgabe an) | wird als `partner_sales` mitgeschickt |

**Kopplung neuer Kunden (seit 1.38.339):** Bei der Quelle Einstellungen → Event-Verteilung → „Mit Plattform verbinden“ (Feld z. B. `evendis.de`). Die Quelle ruft `POST /partner/connect` der Plattform auf (`site_url`, `api_base`, `name`, `terms_url`, `organizer {name, address, city, website, email, phone, description, logo_url}`, `nonce`); die Plattform bestätigt per Rückruf `GET {api_base}/partner/connect-verify?nonce=` (nur die gerade laufende Kopplung der Quelle antwortet `ok`), legt Partner (`auto=1`, `pending=1`, **Verkauf aus**) und Veranstalter (mit Logo) an, mailt der Admin-Adresse und gibt die Schlüssel zurück. Die Quelle speichert sie, schaltet Verteilung + Partner-API ein und verteilt markierte kommende Events (`tix_syndication_push_all`). Freigabe des Verkaufs: Tixomat → Partner → „Freigeben“ (Hinweis im Admin, solange Partner warten). Erneutes Verbinden derselben Seite: neue Schlüssel, Freigabe/Veranstalter bleiben. Abschaltbar auf der Plattform: „Kopplung neuer Tixomat-Seiten erlauben“ (`partner_autoconnect`, Vorgabe an); 10 Versuche/Stunde je IP.

Event-Verteilung (Empfang auf der Plattform): neu `_tix_source_partner`, `_tix_source_api`, `_tix_partner_sales`; `_tix_organizer_id` → Veranstalter des Partners, `_tix_location_id` geleert, `product_id` aus Kategorien entfernt, `_tix_source_checkout` nur auf dem Host der Quelle; Ändern/Löschen nur durch die eigene Quelle. Quelle schickt Bestand nach Bestellung/Storno nach (`stock_only`, höchstens 1×/Minute je Event, Cron `tix_syndication_stock_push`).

Partner-API der Quelle (`X-Tix-Partner-Id` = Hostname der Plattform, `X-Tix-Partner-Key`, 600 Anfragen/5 min je Partner):

| Methode | Route | Inhalt |
|---|---|---|
| POST | `/partner/quote` | `{event_id, items, coupon?}` → Angebot wie `/customer/cart/quote` + `legal {seller, terms_url, privacy_url, revocation_url}` |
| POST | `/partner/orders` | `{event_id, items, billing, payment_method, idempotency_token, partner_ref, coupon?}` → Gastbestellung (Option `_tix_order_partner_{id}`, `_tix_order_source_{id}=partner:<host>`) + `payment_url` |
| GET | `/partner/orders/{id}?key=` | `{order{…}, partner_ref, tickets[{ticket_id, code, category, price, status, checked_in, checkin_time, owner_name, owner_email, seat}]}` |
| POST | `/partner/orders/{id}/resend` | Ticket-Mail erneut senden (`{key}`) |

Webhooks Quelle → Plattform: `POST /partner/webhook`, Header `X-Tix-Partner-Kid` (Fingerabdruck von `key_in`) + `X-Tix-Partner-Signature: t=<ts>,v1=<HMAC-SHA256(ts.body, key_in)>` (±5 min), Typen `order.paid`, `order.cancelled`, `order.refunded`, `ticket.checked_in`, `ticket.checkin_reset`, `ticket.transferred` (neuer Hook `tix_ticket_transferred`); Inhalt = aktueller Stand der ganzen Bestellung. Versand sofort nach Ende der auslösenden Anfrage (nach `fastcgi_finish_request`/`litespeed_finish_request`), Warteschlange `tix_partner_webhook_queue` für Wiederholungen per Cron, bis 12 Versuche mit wachsender Pause. Die Plattform holt zusätzlich alle 15 min nach (`tix_partner_broker_poll`).

Plattform: Vermittlungs-Tabelle `{prefix}tix_partner_orders` (IDs ab 800000001), Ticket-Spiegel (`_tix_ticket_mirror`, `_tix_ticket_source_partner`, `_tix_ticket_source_ticket_id`, `_tix_ticket_source_site`, `_tix_ticket_order_id` = Vermittlungs-ID). Keine zweite Ticket-Mail – die kommt vom Veranstalter. **Einlass auf der Plattform gesperrt** für Spiegel-Tickets: `POST /checkin/scan` antwortet `status=invalid`, `mirror=true`, Meldung „Ticket gilt beim Einlass von <Quelle>.“; Umschalter (REST/Web/Admin) lehnen ab; `TIX_Tickets::checkin_ticket`/`reset_checkin` ignorieren Spiegel. Der QR `GL-<Plattform-Event>-<Code>` funktioniert an der Quelle, weil deren Einlass nur den Code prüft.

Allgemein (alle Seiten): Bestand wird bei Storno/fehlgeschlagener/abgelaufener Zahlung zurückgegeben (Option `_tix_order_stock_taken_{id}` bzw. `_restored_`), bei späterer Zahlung wieder abgezogen; Status „Erstattet“ storniert die Tickets (`tix_order_cancelled`), außer im Erstattungs-Dialog ist „Tickets stornieren“ abgewählt; volle Erstattung im Admin wird nicht mehr von der Downgrade-Sperre blockiert. Mollie: volle Erstattung schickt jetzt den Gesamtbetrag (vorher leerer Body → Mollie-Fehler „does not represent an object“, seit 1.38.337).

Code: `includes/class-tix-partners.php`, `includes/class-tix-partner-api.php`, `includes/class-tix-partner-broker.php`, `includes/class-tix-syndication-*.php`, `includes/class-tix-app-checkout.php`.

Code: `includes/class-tix-app-account.php` (Codes/Bestätigung), `includes/class-tix-rest-api.php` (`auth_register`, `auth_login`), `includes/class-tix-public-events.php` + `includes/class-tix-public-platform.php` (Katalog/Plattform), `includes/class-tix-app-scope.php` (Mehr-Veranstalter).

### Sammelkonto mit Abrechnung (nur Mehr-Veranstalter-Modus, seit 1.38.343)

Kunden zahlen Tickets der evendis-Veranstalter auf das Konto der Plattform; nach dem Event bekommt jeder Veranstalter automatisch eine Abrechnung und sein Geld per Überweisung (Plan: `tixomat-apps/docs/plan-evendis-abrechnung.md`). Alles hängt an `tix_multi_organizer=1` – kitchenklub.de/Mallorca: keine Tabellen, kein Cron, keine Admin-Seite.

- **Gebühr** (`TIX_Fees`): Modi `organizer`, `split` (Kunde trägt `fee_split_customer_share` %, Vorgabe 50, kaufmännisch auf Cent; Rundung des Endpreises wie im Kunden-Modus), `customer`. Im Mehr-Veranstalter-Modus gilt die zentrale Höhe für alle; je Veranstalter zählt nur `_tix_fee_mode` (wenn `_tix_fee_override=1`), eigene Beträge werden ignoriert. Wahl durch den Veranstalter (App `POST /organizer/fee-mode`, Web „Auszahlungen“) oder Admin (Veranstalter bearbeiten). `calc_order_fees` liefert zusätzlich `platform_fee_customer`, `platform_fee_organizer`, `fee_customer_share`; `_tix_order_fees_{id}` speichert sie. Modal-/Express-Checkout bekommen `share` in `data-fee-config`.
- **Auszahlungsdaten** (`TIX_Payout_Details`): Post-Meta `_tix_payout_data`, IBAN verschlüsselt in `_tix_payout_iban` (AES-256-GCM, Schlüssel aus `wp_salt('secure_auth')` + optional Konstante `TIX_PAYOUT_KEY` – **Salts nicht ändern, sonst ist die IBAN unlesbar**), neue IBAN erst nach Code (`_tix_payout_pending`), Hinweis-Mail an Inhaber + Abrechnungs-E-Mail. IBAN-Wechsel nach Freigabe setzt freigegebene Abrechnungen auf „bereit“ zurück.
- **Abrechnung** (`TIX_Settlement`, Tabellen `{prefix}tix_settlements` + `{prefix}tix_settlement_items`, Option `tix_settlement_db`): Cron `tix_settlement_cron` stündlich; je Event mit `_tix_organizer_id`, nicht geteilt, Ende (`_tix_date_*`/`_tix_time_*`, über Mitternacht) + Frist (Einstellung `settlement_delay_days`, Vorgabe 7, je Veranstalter `_tix_settle_delay_days`) ≤ jetzt und Ende ≥ `settlement_since` (Vorgabe: Einrichtungstag, Option `tix_settlement_since`). Grundlage: native Bestellungen `completed`/`refunded` (nicht `pos_*`, nicht 0 €; Geschenkgutscheine zählen). Je Bestellung: Ticketumsatz = Gesamt − Kundengebühren (+ Geschenkgutschein), Erstattung (Summe `_tix_refund_total_{id}`, gedeckelt auf Ticketumsatz; Gebühren bleiben), Plattformgebühr Veranstalteranteil, Zahlungsgebühr (tatsächlich: `_tix_payment_fee` – Stripe neu per Balance Transaction, Mollie/PayPal vorhanden; sonst zentraler Satz; 0 bei Überweisung oder wenn der Kunde sie trägt). Spätere Erstattungen und Nachverkäufe → offene Posten (`settlement_id=0`), verrechnet mit der nächsten Abrechnung bzw. Saldo-Abrechnung (`type=balance`, automatisch bei positivem Saldo nach Frist + 7 Tage). Negativer Betrag → 0 € auszahlen, Übertrag (`carry`). Abschlag vor dem Event (`type=advance`) nur bei `_tix_settle_advance_pct` > 0, wird in der Event-Abrechnung abgezogen; `_tix_settle_hold=1` stellt neue Abrechnungen zurück. Status `draft` (Auszahlungsdaten fehlen) → `ready` → `approved` (Admin) → `paid`; `held`; `void` = ersetzter Abschlag. Fortlaufende Nummern `EV-JJJJ-NNNN` / `EVR-JJJJ-NNNN` (Optionen `tix_settlement_seq_*`).
- **Steuer-Variante** (`settlement_tax_mode`): `agency` (Vorgabe, Vermittlung im fremden Namen: Abrechnung + Gebührenrechnung mit `settlement_fee_vat_rate`, Gebühren brutto) oder `reseller` (Eigenhandel: Gutschrift über den Ticketankauf mit `settlement_ticket_vat_rate`, § 19 UStG bei Kleinunternehmern, keine Gebührenrechnung). Vor echtem Geld mit Steuerberatung klären.
- **Belege** (`TIX_Settlement_PDF`, über `TIX_Simple_PDF`): werden aus gespeicherten Daten erzeugt (Snapshot mit Rechnungsadresse/IBAN bei Erstellung bzw. Freigabe), IBAN im PDF gekürzt; Aussteller aus den Rechnungs-Stammdaten (`invoice_company_*`).
- **Admin** Tixomat → **Auszahlungen** (`tix-payouts`, `TIX_Settlement_Admin`): Fällig/Freigegeben/Ausgezahlt/Alle/Veranstalter; Freigeben, Zurückstellen (Grund), Wieder aufnehmen, Neu berechnen, PDF/Rechnung, Kopierhilfe (Empfänger, IBAN, BIC, Betrag, Verwendungszweck) + „Als ausgezahlt markieren“ (Datum, Referenz) → Mail + Feed/Push; Sonderregeln je Veranstalter, „Saldo abrechnen“, „Abschlag erstellen“, „Jetzt abrechnen“, „Abrechnungslauf jetzt starten“. SEPA-Sammeldatei pain.001.001.09 vorbereitet (Schalter `settlement_sepa_enabled` + Auftraggeber `settlement_debtor_*`). Einstellungen: Gebühren → „Abrechnung & Auszahlung“.
- **Veranstalter (Web)**: wp-admin-Seite `tix-organizer-payouts` (`TIX_Settlement_Organizer`, Shell-Link „Auszahlungen“): Saldo, Gebühren-Modus mit Beispielen, Auszahlungsdaten + Code, Abrechnungen mit PDF. Mails: „Abrechnung … erstellt“ (mit PDFs), „Betrag überwiesen“, Admin-Sammelmail „neue Abrechnungen zur Freigabe“ (an `invoice_email`, sonst Admin-Adresse).
- Neu für alle Seiten (ohne Verhaltensänderung): Erstattungs-Summe `_tix_refund_total_{id}` + Hook `tix_order_refund_recorded($order_id, $amount, $is_full)`; Veranstalter-Bearbeiten zeigt die Team-Karte nur noch, wenn `TIX_Team::get_members/get_roles` existieren (vorher Fatal).

Code: `includes/class-tix-fees.php`, `includes/class-tix-payout-details.php`, `includes/class-tix-settlement*.php`; Tests: `tests/test-fees.php` (wird nicht ausgeliefert).

### Freigabe neuer Veranstalter (nur Mehr-Veranstalter-Modus, seit 1.38.346)

Neue Veranstalter aus der Selbst-Registrierung (`[tix_register_event]`, evendis.de/veranstalter-werden) verkaufen erst nach Prüfung durch den Admin – wichtig wegen des Sammelkontos. Alles hängt an `tix_multi_organizer=1`; kitchenklub.de/Mallorca: keine Änderung (Status gilt immer als freigegeben).

- **Status** (`TIX_Org_Approval`, Post-Meta `_tix_org_approval` am `tix_organizer`): `pending` (wartet), `approved`, `rejected` (mit Begründung, erneut einreichbar), `blocked` (gesperrt). Fehlendes Meta = freigegeben. Neu angelegt: durch Admin/System (auch Partner-Kopplung) → `approved`; durch angemeldeten Nicht-Admin oder Registrierung → `pending`. Verlauf in `_tix_org_approval_log`, Entscheidung in `_tix_org_approval_info`. Hook `tix_org_approval_changed($org_id, $new, $old)`.
- **Einführung**: beim ersten Laden im Mehr-Veranstalter-Modus (Option `tix_org_approval_db`) werden alle vorhandenen Veranstalter auf `approved` gesetzt (Verlauf: „Bestand bei Einführung“) und eine **Platzhalter-Seite** „Vermittlungsvertrag für Veranstalter“ (`/vermittlungsvertrag-veranstalter/`, `noindex`) mit Version „Entwurf 2026-10“ angelegt – **vor dem Start durch den endgültigen Vertrag ersetzen und neue Version eintragen**.
- **Wirkung, solange nicht freigegeben**: Veranstalter-Post bleibt veröffentlicht (Anmeldung, Profil, Events vorbereiten, Auszahlungsdaten, Abrechnungen funktionieren), erscheint aber nicht in `/public/organizers[/{id}]`, auf keiner Landingpage, keiner Veranstalter-Seite `/veranstalter/<slug>/` (Filter `tix_organizer_page_public` → 404 + noindex) und nicht in der WordPress-Sitemap. Events: `publish`/`future` wird beim Speichern zu `pending`, Wunsch-Status in `_tix_publish_held` (Filter `wp_insert_post_data`; auch wenn ein Admin ein veröffentlichtes Event einem wartenden Veranstalter zuweist). Freigabe veröffentlicht diese Events; Sperren/Ablehnen hält veröffentlichte Events auf dieselbe Weise zurück (Abrechnungen laufen weiter, verkaufte Tickets bleiben gültig). Kasse (Web, App, Kasse vor Ort) lehnt mit `organizer_not_approved` ab. Geteilte Events sind ausgenommen.
- **Pflichtangaben** (Haken in der Prüfliste): Firma/Name, Anschrift (Rechnungsadresse), Steuer-Status, Auszahlungsdaten (aus `TIX_Payout_Details`), Telefon (`_tix_org_phone`), Zustimmung zum Vermittlungsvertrag (`_tix_org_terms` = `{version, accepted_at, user_id, ip, url}`; bei neuer Vertragsversion erneut nötig – Verkauf freigegebener Veranstalter läuft weiter, Abrechnungs-Admin zeigt den Hinweis „Vermittlungsvertrag nicht zugestimmt“). Freigabe ohne vollständige Angaben nur mit Haken „trotz fehlender Pflichtangaben“ (im Verlauf vermerkt).
- **Admin** Tixomat → **Veranstalter-Prüfung** (`tix-org-review`): Wartet/Abgelehnt/Gesperrt/Freigegeben/Alle, Detail mit allen Angaben, Events, Verlauf; Freischalten (optional Landingpage mit), Ablehnen/Sperren mit Pflicht-Begründung. Einstellungen (Option `tix_org_approval_settings`): Vertragstitel, Seite oder Link, Version, Benachrichtigungs-Adresse (Vorgabe `invoice_email`, sonst Admin-Adresse), Feed/Push an Admins (Vorgabe an).
- **Mails**: an den Veranstalter bei Registrierung („Wir prüfen dein Konto“) und jeder Entscheidung; an den Admin bei neuer Registrierung und erneuter Einreichung.
- **Veranstalter (Web)**: Hinweis-Leiste auf allen Seiten der Veranstalter-Shell, Seite **Mein Konto** (`tix-org-account`, Shell-Link): Status, Begründung, Pflichtangaben mit Sprung, Kontakt (Telefon), Vertrag zustimmen, erneut einreichen.
- **Registrierung**: zusätzlicher Pflicht-Haken für den Vermittlungsvertrag (aktuelle Version, sonst Fehler), Knopf „Registrieren & zur Prüfung einreichen“, Erfolgsseite ohne Event-Link, Weiterleitung auf „Mein Konto“.

Code: `includes/class-tix-org-approval.php`; Test: `tests/test-org-approval-wp.php` (nur gegen ein lokales Test-WordPress per `wp eval-file`, wird nicht ausgeliefert).

### Rollout auf die Kunden-Sites

Push auf `main` deployt nur tixomat.de. Kunden-Sites per Workflow „Deploy to site (manual)“: zuerst `site=evendis` prüfen (evendis.de hat nur Testdaten; demo.mdj.events wird seit 2026-10-03 nicht mehr genutzt), dann **`site=alle`** = kitchenklub.de → evendis.de → mallorca-festival-xxl.de nacheinander (Regel des Betreibers seit 2026-10-02: Mallorca bekommt jede neue Version mit). Der Workflow setzt den OPcache per Einmal-mu-Plugin zurück (`/?tix_oc=<Schlüssel>`; Server = OpenLiteSpeed/lsphp) und prüft Live-Version und Startseite. Mallorca-Live liegt im Ordner `Mallorca-Festival-XXL-2026` (nicht `mallorca-festival-xxl` = alter Shop).

---

## 60. Admin Shell / Fullscreen-Modus

Seit v1.34.0 zeigt das Plugin auf allen Tixomat-Admin-Seiten eine eigene Fullscreen-Oberflaeche mit linker Sidebar statt des WordPress-Backends.

### Features

- **Fullscreen-UI**: Versteckt WordPress Admin-Bar, Sidebar, Footer, Screen Options
- **Eigene Sidebar**: Logo, Gruppen (Events, Ticketing, Verwaltung, Einstellungen, Hilfe)
- **Organizer-Modus**: Veranstalter erhalten eine reduzierte Sidebar (Dashboard, Meine Events, Ticketing, Verwaltung, Einstellungen). Admin-Bar wird fuer Organizer komplett ausgeblendet
- **URL-Rewriting**: `wp-admin`-URLs werden kosmetisch durch den Organizer-Slug ersetzt via `history.replaceState`
- **Kontextabhaengige Sub-Tabs**: Settings-Seite, Docs-Seite und Support-Seite zeigen ihre Tabs in der Sidebar
- **Floating Publish-Button**: Oben rechts fix (Aktualisieren/Veroeffentlichen + Status + Vorschau). Auch auf Post-Edit-Seiten verfuegbar
- **Responsive**: Mobile Slide-In Sidebar
- **Versteckt**: Breakdance-Button, LiteSpeed-Metabox, WP-Sidebar-Boxen (Kategorien/Beitragsbild/Textauszug sind in den Metabox-Tabs)
- **Deaktivierbar**: Einstellungen -> Erweitert -> Admin-Ansicht (Setting: `fullscreen_admin`)

### Erkannte Tixomat-Seiten

- Event CPT (Liste, Editor, Kategorien)
- Alle Tixomat CPTs (tix_ticket, tix_ticket_tpl, tix_support_ticket, tix_location, tix_organizer, tix_subscriber, tix_abandoned_cart, tix_seatmap, tix_special)
- Custom Admin-Seiten (tix-settings, tix-statistics, tix-support, tix-docs, tix-promoters, tix-marketing-export, tix-campaigns)

### CSS-Klassen

| Klasse | Beschreibung |
|---|---|
| `body.tix-fullscreen` | Body-Klasse wenn Fullscreen aktiv |
| `.tix-shell-sidebar` | Sidebar-Container (260px, fixed left) |
| `.tix-shell-item.active` | Aktiver Menuepunkt (orange Akzent) |
| `.tix-floating-publish` | Floating Button Container (fixed top right) |

### Dateien

| Datei | Beschreibung |
|---|---|
| `includes/class-tix-admin-shell.php` | PHP-Klasse: Seitenerkennung, Sidebar-Rendering, Floating Publish |
| `assets/css/admin-shell.css` | Light-Theme Styling, WP-Chrome-Hiding |
| `assets/js/admin-shell.js` | Tab-Switching, Mobile-Toggle, Mehr-Button |

---

## 61. Organizer Admin System

Seit v1.34.0 erhalten Veranstalter (`tix_organizer`-Rolle) ein vollstaendiges Admin-Backend innerhalb von WordPress mit eingeschraenktem Zugriff auf eigene Events.

**Datei:** `includes/class-tix-organizer-admin.php`

### Capabilities

Die Rolle `tix_organizer` erhaelt folgende WordPress-Capabilities:
- `edit_posts`, `publish_posts`, `delete_posts`, `edit_others_posts`, `upload_files`

### Event-Ownership & Zugriffskontrolle

- **pre_get_posts Filter**: Events werden nach `_tix_organizer_id` gefiltert -- Organizer sehen nur eigene Events
- **map_meta_cap**: Stellt sicher, dass Organizer nur eigene Events bearbeiten koennen
- **Ticket-Filter**: `tix_ticket`-Liste zeigt nur Tickets der eigenen Events
- **Erlaubte Post-Types**: `event`, `tix_location`, `tix_ticket`, `tix_seatmap`, `tix_ticket_tpl`, `tix_subscriber`
- **Auto-Assign**: Neue Events erhalten automatisch `_tix_organizer_id` des aktuellen Nutzers

### Admin-Einschraenkungen

- **Menue-Cleanup**: Alle WordPress-Menues entfernt ausser Tixomat, Upload, Profil
- **Admin-Bar**: Komplett ausgeblendet fuer Organizer
- **Login-Redirect**: Organizer werden nach Login zu `tix-organizer-dashboard` weitergeleitet
- **Seitenrestriktionen**: Nur erlaubte Post-Types und Tixomat-Admin-Seiten zugaenglich

### Admin-Seiten (5 Custom Pages)

| Seite | Slug | Beschreibung |
|---|---|---|
| Dashboard | `tix-organizer-dashboard` | KPI-Cards (Umsatz, verkaufte Tickets, aktive Events, naechstes Event) + Top-5-Events-Tabelle |
| Bestellungen | `tix-organizer-orders` | Reduzierte Bestellansicht (Bestellnr., Datum, Kaeufer, E-Mail, Tickets, Betrag, Status) mit Event-Filter + Suche + Pagination |
| Gaesteliste | `tix-organizer-guestlist` | CSV-Export (manuelle Gaeste + WC-Bestellungen). Spalten: Name, E-Mail, Tickets, Kategorie, Checked-in, Quelle |
| E-Mail | `tix-organizer-email` | Bulk-E-Mail an alle Kaeufer eines Events (AJAX, Rate-Limit: 1x pro Event pro Tag) |
| Abrechnung | `tix-organizer-billing` | Monatlicher CSV-Export (Bestellnr., Datum, Event, Tickets, Brutto, Steuer, Netto) |

### User-Profil

- Meta-Feld `_tix_organizer_name` auf dem User-Profil
- Synchronisiert automatisch mit dem Titel des zugehoerigen `tix_organizer` CPT

### Benachrichtigungen

- **Neue Bestellung**: E-Mail an Organizer bei `woocommerce_order_status_completed` und `woocommerce_order_status_processing`
- **Low-Stock-Warnung**: Wenn weniger als 10% Restbestand, einmalige Benachrichtigung (Flag: `_tix_low_stock_notified`)

---

## 62. Custom Login URLs

Seit v1.34.0 koennen benutzerdefinierte Login- und Organizer-URLs konfiguriert werden.

**Datei:** `includes/class-tix-custom-urls.php`

### Features

- **Custom Login URL**: z.B. `/anmelden/` statt `/wp-login.php`
- **Custom Organizer URL**: z.B. `/veranstalter/` leitet zum Organizer-Dashboard weiter
- **Settings**: `login_slug` und `organizer_slug` im Tab **Erweitert**

### Technische Umsetzung

- **URL-Intercept**: Per `init`-Hook wird der URL-Pfad geparst und auf die Custom-Seiten gemappt
- **wp-login.php Redirect**: Automatische Weiterleitung von `wp-login.php` zur Custom-Login-URL
- **Gebrandete Login-Seite**: Tixomat-Logo, Hintergrund `#FAF8F4`, orangener Button
- **Login-Verarbeitung**: POST wird direkt mit `wp_signon()` verarbeitet
- **Organizer-Redirect**: Nach Login werden Organizer automatisch zum Dashboard weitergeleitet

---

## Changelog

### v1.34.0
- **REST API**: 24 Endpoints unter `tixomat/v1` (Events, Check-in, Gaesteliste, POS, Auth, Customer)
- **Admin Shell**: Fullscreen-Modus mit eigener Sidebar-Navigation auf allen Tixomat-Seiten
- **Organizer Admin System**: Vollstaendiges Admin-Backend fuer Veranstalter mit Event-Ownership, 5 Admin-Seiten (Dashboard, Bestellungen, Gaesteliste, E-Mail, Abrechnung), Benachrichtigungen und Ticket-Filter
- **Admin Shell Organizer-Modus**: Reduzierte Sidebar, URL-Rewriting, Admin-Bar ausgeblendet
- **Custom Login URLs**: Benutzerdefinierte Login- und Organizer-URLs (`/anmelden/`, `/veranstalter/`) mit gebrandeter Login-Seite
- **Floating Publish Button**: Aktualisieren/Veroeffentlichen oben rechts fix
- **Light Theme Sidebar**: Helles Design (#FAF8F4) mit Tixomat-Logo
- **Support-Seite**: Konsistentes Tab-Design (tix-nav statt tix-nav-tabs)
- **Dokumentation**: REST API Tab, Admin Shell Doku, Organizer Admin, Custom URLs, Docs-Sidebar-Tabs
- **Fullscreen deaktivierbar**: Einstellung unter Erweitert -> Admin-Ansicht

### v1.28.81 -- v1.28.84
- **KI-Schutz (Content Guard)**: Automatische Inhaltspruefung fuer Events via Anthropic Claude API. Fail-closed Design, Slug-Sanitierung, Hash-Cache, Admin-Spalte.
- **Restlose Event-Loeschung**: Neue zentrale Methode `TIX_Cleanup::purge_event_data()` loescht alle Custom Tables, CPTs, Crons und Transients bei Event-Loeschung. Kein Datenbank-Muell mehr.

### v1.28.25
- **Veranstalter-Dashboard**: Vollstaendiges Frontend-Dashboard fuer externe Veranstalter (`[tix_organizer_dashboard]`). Event-CRUD mit Wizard + Editor (9 Tabs), Bestellungen, Gaesteliste + Check-In, Statistiken. Neue WP-Rolle `tix_organizer`, User-Mapping via `_tix_org_user_id`, 16 AJAX-Endpoints, Media-Upload, Rabattcodes, Gewinnspiel

### v1.28.19 -- v1.28.24
- **Low-Stock-Badge**: "Nur noch X verfuegbar!" im Ticket-Selektor (konfigurierbarer Schwellenwert)
- **Social-Sharing-Buttons**: WhatsApp, Facebook, X, E-Mail, Link kopieren auf Event-Seite
- **Rabattcode-Generator**: Event-spezifische Codes als echte WC_Coupons (Metabox-Tab)
- **Presale-Countdown**: Countdown + E-Mail-Benachrichtigung vor Vorverkaufsstart
- **Warteliste**: E-Mail-Sammlung bei ausverkauften Tickets mit Auto-Benachrichtigung
- **Post-Event Feedback**: Sterne-Bewertung (1-5) + Kommentar, Token-basiert, in Follow-Up E-Mail
- **Timetable (Multi-Stage)**: Mehrtaegiges Programm mit Buehnen-Grid (Desktop) und Listenansicht (Mobil)
- **Docs-Update**: Alle neuen Shortcodes, Meta-Keys und AJAX-Endpoints dokumentiert

### v1.28.18
- **Gewinnspiel (Raffle)**: Teilnahme-Formular, automatische/manuelle Auslosung, Gewinner-Benachrichtigung, Cron

### v1.28.0
- **Ticket-Vorlagen CPT**: Ticket-Vorlagen als eigener Post-Type (tix_ticket_tpl) mit visuellem Editor
- **Template-Editor erweitert**: Neue Properties (Drehung, Deckkraft, Zeichenabstand, Zeilenhoehe, Hintergrund, Rahmen, Innenabstand, Textumwandlung)
- **Placeholder-Vorschau**: Editor zeigt Platzhalter-Text mit tatsaechlicher Formatierung
- **QR/Barcode Fix**: Codes werden als visuelle Pattern (Schachbrett/Streifen) im Editor angezeigt
- **QR/Barcode proportional**: Groessenaenderung nur proportional moeglich, Standard-Position sichtbar (links)
- **Kryptische Download-URLs**: 256-Bit Token statt lesbarer Ticket-Codes in URLs
- **12-stellige Codes**: Ticket- und Gaesteliste-Codes sind jetzt 12 alphanumerische Zeichen (kryptographisch sicher)
- **Template-Auswahl**: Events koennen gespeicherte Vorlagen zuweisen (Modus "Vorlage waehlen")
- **Seatmap Bug Fix**: Tickets werden bei Saalplan-Bestellungen korrekt generiert (_tix_seats Meta persistiert)
- **PDF Bug Fix**: Leere PDFs durch `empty()` Bug und fehlende Sanitization behoben
- **Check-in Bug Fix**: TIX_Settings class_exists Guards fuer AJAX-Kontext (Netzwerkfehler behoben)
- **WooCommerce Bug Fix**: Bestellstatus-Wechsel loest keinen Fehler mehr aus (class_exists + try/catch)
- **Countdown entfernt**: Saalplan-Modal zeigt keinen sichtbaren Timer mehr
- **Settings-Breite**: Einstellungen nutzen volle Breite

---

## 63. KI-Assistent (AI Writer)

`TIX_AI_Writer` bietet KI-gestuetzte Textgenerierung und automatische Feld-Extraktion fuer den Event-Editor.

### Features

1. **Zusammenfassung (Excerpt)**: SEO-optimierte Meta-Description (140-160 Zeichen) per Klick generieren. Wird automatisch beim Wechsel zum Info-Tab generiert wenn leer.
2. **KI-Assistent Bar**: Lila Bar oben im Event-Editor. Bild/Flyer hochladen oder URL eingeben → KI extrahiert alle Event-Daten.
3. **Preview-Modal**: Extrahierte Daten werden als Vorschlaege mit Checkboxen angezeigt. Nutzer waehlt aus, was uebernommen wird.

### Extrahierte Felder

Titel, Beschreibung, Line-Up, Specials, Weitere Infos, Altersbegrenzung, Startdatum, Enddatum, Startzeit, Endzeit, Einlass, Location (Fuzzy-Match gegen bestehende Locations), Kategorie (Match gegen Taxonomie-Terms), Tickets (Name + Preis), FAQ (2-3 generierte Eintraege), Zusammenfassung (SEO).

### Provider-Support

| Provider | Modelle | Vision (Bild) |
|---|---|---|
| Anthropic | Claude Sonnet 4, Opus 4, Haiku 3.5 | Ja |
| OpenAI | GPT-4o, GPT-4o Mini, o3-mini | Ja |

Routing erfolgt automatisch basierend auf dem in den Einstellungen gewaehlten Modell. KI-Schutz nutzt immer Claude Haiku (kosteneffizient).

### AJAX-Endpoints

| Action | Methode | Beschreibung |
|---|---|---|
| `tix_ai_generate_excerpt` | POST | SEO-Zusammenfassung aus Event-Daten generieren |
| `tix_ai_fill_fields` | POST | Felder aus Bild (Attachment-ID) oder URL extrahieren |

### Dateien

| Datei | Beschreibung |
|---|---|
| `includes/class-tix-ai-writer.php` | API-Calls (Anthropic + OpenAI), Feld-Extraktion, Location/Kategorie-Matching |

---

## 64. Event-Vorlagen

`TIX_Event_Templates` verwaltet Event-Vorlagen die steuern welche Tabs im Editor sichtbar sind und welche Standardwerte vorbelegt werden.

### Admin-Seite

Menue: **Events → Vorlagen**. Formular mit: Name, Icon-Auswahl (16 Dashicons), sichtbare Tabs (Checkboxen), Standardwerte (Ticketverkauf, Altersbegrenzung), automatische Kategorie-Zuweisung.

### Vorlage aus Event erstellen

In der Event-Liste: Row-Action **"Als Vorlage"** → analysiert welche Tabs/Felder im Event befuellt sind → oeffnet Vorlagen-Formular mit vorausgefuellten Tab-Checkboxen.

### Preset-Bar

Beim Erstellen eines neuen Events zeigt die Preset-Bar alle gespeicherten Vorlagen als klickbare Cards. "Alle Funktionen" ist immer der erste Eintrag. Bei Klick auf eine Vorlage:
- Nur die ausgewaehlten Tabs werden angezeigt
- Standardwerte werden vorbelegt (Tickets an/aus, Alter, Kategorie)
- "Alle Tabs" Button zeigt die restlichen Tabs an

### Datenstruktur

Gespeichert als `wp_option` (`tix_event_templates`):

| Feld | Typ | Beschreibung |
|---|---|---|
| `label` | `string` | Name der Vorlage |
| `icon` | `string` | Dashicon-Klasse |
| `desc` | `string` | Kurzbeschreibung |
| `tabs` | `array` | Sichtbare Tab-Slugs |
| `defaults` | `array` | Feld-Standardwerte |
| `category_id` | `int` | Auto-zugewiesene Kategorie |
| `source_event` | `int` | Event-ID (wenn aus Event erstellt) |

### Dateien

| Datei | Beschreibung |
|---|---|
| `includes/class-tix-event-templates.php` | Admin-Seite, CRUD, Event-Analyse, Preset-Integration |

---

## 65. Auto-Save

Automatisches Speichern im Event-Editor alle 2 Minuten und bei jedem Tab-Wechsel.

### Funktionsweise

- Eigener AJAX-Endpoint `tix_autosave` der NUR `update_post_meta()` ausfuehrt
- Kein `wp_update_post()`, kein `save_post_event` Hook → keine WC-Sync, keine Series, keine Validierung
- Speichert immer als Entwurf (setzt Post-Status nicht)
- Visueller Indikator in der Floating Publish Bar: "Ungespeicherte Aenderungen" → "Speichert..." → "Gespeichert ✓ HH:MM"
- Wird bei manuellem Speichern/Veroeffentlichen automatisch pausiert (keine Race Conditions)

### AJAX-Endpoint

| Action | Methode | Beschreibung |
|---|---|---|
| `tix_autosave` | POST | Alle Form-Felder als Post-Meta speichern |

---

## 66. Emoji-Filter

Globaler `sanitize_title` Filter (Prioritaet 5) der Emojis aus Permalinks entfernt.

### Entfernte Zeichen

| Bereich | Unicode-Range | Beispiele |
|---|---|---|
| Supplementary Multilingual Plane | U+10000 - U+10FFFF | Moderne Emojis |
| Symbole & Dingbats | U+2600 - U+27BF, U+2300 - U+23FF | Aeltere Symbole |

Greift bei `context === 'save'` fuer alle Post-Typen.

## 67. App-Look für Breakdance (evendis)

Startseite, Event-Seite und Veranstalter-Seite im Design der evendis-App (Stand App 2026-10-10) als **echte Breakdance-Vorlagen** – im Builder bearbeitbar, Header und Footer bleiben unverändert (ab 1.38.370, `includes/class-tix-app-look.php`, ersetzt den Options-Skin `tix_site_skin`).

- **Vorlagen** (`assets/breakdance/app-look-start.json`, `app-look-event.json`, `app-look-organizer.json`, `app-look-page.json`): „Startseite (App-Look)“ (Typ `front-page`), „Einzel-Event (App-Look)“ (`event`), „Veranstalter (App-Look)“ (`tix_organizer`), „Seite (App-Look)“ (`page`, nur Seiten mit `[tix_checkout]`, `[tix_account]`, `[tix_my_tickets]` oder `[tix_support]` – Regel „`[tix_app key="app_page"]` ist nicht leer“, setzt `install`; roter Kopf mit Seitentitel, Inhalt per Code-Block, Tixomat-Variablen der Bausteine auf App-Werte). Aufbau wie die bisherigen evendis-Vorlagen: Breakdance-Bausteine mit Klassen (`evs-*`, `evx-*`, `evo-*`), EIN CSS-Block an der ersten Section (Manrope, Grund #F2F3F7, Karten weiß Radius 20 ohne Schatten, Markenrot #FC2F55, Signalrot #E8445A, Nachtblau #2C2F3F, Titel 20/800 über den Karten).
- **WP-CLI:** `wp tixomat app-look install` (anlegen/aktualisieren, neue bleiben aus) · `on` (App-Look an, bisherige Vorlagen gleichen Typs aus; IDs in Option `tix_app_look_replaced`) · `off` (zurück) · `status`.
- **Einzelwerte für Breakdance-Texte** über das dynamische Feld „Shortcode“: `[tix_app key="…"]` mit `title` (ohne Emojis), `greeting`, `question`, `weekday`/`day`/`month`, `date_long`, `countdown`, `live`, `time_range`, `doors`, `place`, `address`, `maps_url`, `route_car`/`route_transit`/`route_walk`, `age`, `pill_status`/`pill_price`/`pill_free`/`pill_door`/`pill_presale`, `tickets`, `bar_price`/`bar_buy`/`bar_free`/`bar_state`, `organizer`, `related`, `lineup`, `categories`, `live_now`, `organizers`, `app_page`. Leer = Bedingung „ist nicht leer“ blendet den Baustein aus.
- **Bausteine (Shortcode-Element):** `[tix_app_search]` (Suche + Heute/Morgen/Wochenende), `[tix_app_categories]`, `[tix_app_cards limit]` (EventCardLarge), `[tix_app_list limit organizer exclude category filter="1" style="compact" empty]` (EventRow bzw. Veranstalter-Zeile), `[tix_app_related]` („Das könnte dir auch gefallen“), `[tix_app_lineup]` (Initialen-Kreise, Spotify-Suche), `[tix_app_heart]`, `[tix_app_live]` („Jetzt & gleich“: läuft gerade oder beginnt in ≤ 3 Std., laufende zuerst), `[tix_app_organizers]` („Veranstalter entdecken“: bis 10 Veranstalter mit öffentlicher Seite, die mit Events zuerst; Banner, Logo, „Stadt · n Events“, Folgen-Pille), `[tix_app_follow]` (AJAX `tix_app_follow`, User-Meta `_tix_app_following` wie die App; Gäste → Seite mit `[tix_account]` und Rücksprung `tix_back`).
- Assets (`assets/css/app-look.css`, `assets/js/app-look.js`, Manrope aus `assets/fonts/manrope/`) laden nur, wenn ein Baustein auf der Seite steht. JS: Filter der Liste, Apple Karten auf Apple-Geräten, „Mehr lesen“ (`.evs-clamp`), „Einladen“ (Link `#teilen` → Teilen-Dialog bzw. Link kopieren).
- Kleine Erweiterungen: `[tix_event_organizer bold="1"]` („von **Name**“), `[tix_section … body_class="…"]` (Inhalt in eigener Hülle). Ohne die Attribute gleiche Ausgabe wie vorher.
- **Ohne eingeschaltete Vorlagen keine Änderung:** geprüft byte-gleich zu 1.38.369 (Startseite, Event-, Veranstalter-, Kasse-, Support-, Konto- und Suchseiten; ohne Versionsnummer, Nonces und Such-ID).

---

### v1.33.95
- **Event-Vorlagen**: Dynamisches Vorlagen-System (Events → Vorlagen). Vorlagen steuern Tab-Sichtbarkeit + Defaults. Aus bestehenden Events erstellbar ("Als Vorlage" in Event-Liste). Ersetzt hardcoded Presets.
- **KI-Assistent**: Bild/Flyer hochladen oder URL eingeben → KI extrahiert alle Event-Felder. Preview-Modal mit Checkboxen zum selektiven Uebernehmen. Auto-Match von Location und Kategorie.
- **KI-Zusammenfassung**: SEO-optimierte Meta-Description (140-160 Zeichen) per KI. Zeichenzaehler mit Farbcodierung (gruen/gelb/rot). Auto-Generierung beim Info-Tab.
- **OpenAI-Support**: GPT-4o, GPT-4o Mini, o3-mini als Alternative zu Claude. Automatisches Routing basierend auf Modellauswahl. Vision fuer beide Provider.
- **Zentrale KI-Einstellungen**: Neuer "Kuenstliche Intelligenz" Card in Erweitert. Zentraler API-Key fuer alle Features. Modell-Auswahl mit Anthropic + OpenAI Gruppen. Auto-Migration von altem `ai_guard_api_key`.
- **Auto-Save**: Alle 2 Min + Tab-Wechsel. Leichtgewichtiger AJAX-Handler ohne save_post Hooks. Visueller Status-Indikator.
- **Zero-Waste Loeschung**: 6 neue Cleanup-Ziele: Campaign-Views, Meta-Conversions, tix_ticket CPT (direkt), KI-Meta, Serien-Kinder (mit/ohne Verkaeufe), exklusive Medien. Rekursions-Schutz.
- **Emoji-Filter**: Emojis aus Permalinks entfernen (global, alle Post-Typen).
- **Uhrzeit-Fix**: Pflichtfeld-Check fuer Startzeit (input → select Selektor).
- **Entwurf-Button**: Expliziter "Entwurf speichern" Button in Floating Publish Bar.
- **Zeitauswahl**: 30-Min-Intervall Dropdowns statt nativer Time-Inputs. Auto-Fill Enddatum.
- **Visual Consistency**: Alle Tabs im tix-card Design. Presale-Defaults (2h vor Event).

*Tixomat v1.33.95 -- MDJ Veranstaltungs UG (haftungsbeschraenkt)*
