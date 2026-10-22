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
│       └── manrope/ (Manrope-400…800.ttf, OFL)
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
- Verfuegbare Schriftarten (seit 1.38.419): Manrope aus `assets/fonts/manrope/` (normal = 500, fett = 800, „monospace“ = 700).
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

Seit 1.38.471 laufen **alle Mails** durch ein gemeinsames Layout `TIX_Mail_Layout` (Marke der Seite, responsiv, Dark Mode, Text-Fassung). `TIX_Emails` baut die Bestell-Mails aus Bausteinen, ältere HTML-Teile werden angeglichen.

### Marke

- Kommt aus der Seite, Lizenzseiten brauchen keine Anpassung:
  - Name: Seiten-Konfiguration › `email_brand_name` › Seitenname
  - Akzentfarbe immer `TIX_Studio::accent()` (Studio-Marke)
  - Fußzeile aus `/public/site-config`: Impressum, Datenschutz, Kontakt, Social; App-Links nur mit eigener App
- **Mail-Felder der Studio-Marke** `tix_studio_brand` (seit 1.38.473, im Studio unter „Marke“ bzw. WP-Admin Tixomat → E-Mails):

  | Feld | Werte | Vorgabe |
  |---|---|---|
  | `mail_header` | `light` \| `brand` \| `color` | automatisch: `brand`, sobald ein Logo für farbigen Grund da ist |
  | `mail_header_color` | Hex, Kopf-Farbe bei `color` (seit 1.38.479) | Akzentfarbe |
  | `mail_logo_id` | Logo auf hellem Grund | normales Logo |
  | `mail_logo_on_color_id` | Logo für farbigen Kopf | keins; fehlt es, gilt immer `light` |
  | `mail_from` | Absender-Adresse | `noreply@<Domain der Seite>` |
  | `mail_from_name` | Absender-Name | Marke |
  | `mail_reply_to` | Antworten an, Link „Hilfe“ | Kontakt-E-Mail › Einstellung `support_email` |

- WP-Admin **Tixomat → E-Mails**: dieselben Felder, Galerie aller Mail-Arten mit Vorschau (Handy/Desktop, Text-Fassung) und „Test an mich senden“.

### Versand (Filter in `TIX_Mail_Layout::init`)

- **Absender:**
  - `wordpress@…` bzw. leer wird `mail_from_name <mail_from>`.
  - FluentSMTP findet die Verbindung über das Mapping dieser Adresse.
  - Ausdrücklich gesetzte Absender bleiben (Kopfzeile From, Rundmail-Absender).
- **Reply-To:** `mail_reply_to`, nur bei Mails, die nicht an die Admin-Adresse gehen.
- **Ins Layout gesetzt (wp_mail-Filter, Prio 900):**
  - Text-Mails und HTML-Fragmente, auch von WordPress und fremden Plugins
  - Vollständige fremde HTML-Dokumente bleiben unverändert.
  - Kopfzeile `X-Tix-Raw: 1` schaltet das Layout für eine Mail ab.
- **List-Unsubscribe** nur in Werbe- und Follow-Mails (Wochenmail, gefolgte Veranstalter, Rundmails), dort RFC 8058 (HTTPS, One-Click per POST). Transaktions-, Konto- und Admin-Mails haben keinen.
- **Text-Fassung (multipart):** aus den Bausteinen bzw. aus dem HTML, ohne CSS und Vorschauzeile.
- **Mail-Protokoll:**
  - Das Öffnungs-Pixel bleibt.
  - Der Block „War diese E-Mail hilfreich?“ entfällt seit 1.38.473; alte Links funktionieren weiter.
  - Anhänge werden gesichert (geschützter Ordner `…/tix-private/mail-log/`, höchstens 10 MB je Mail, gelöscht mit dem Protokoll), damit „Erneut senden“ sie mitschickt.

### Bausteine (`TIX_Mail_Layout::render`)

- Felder: `title`, `subtitle`, `preheader`, `blocks`, `note`, `kind`
- `kind`:
  - `customer` (Standard)
  - `account`
  - `admin`: ohne Social/App-Links
  - `promo`
- Blöcke:
  - `text`, `button` (VML für Outlook), `info` (Zeilen), `notice` (info/warn/success/error), `code`
  - `event` (Bild bzw. Platzhalter, Datum, Ort), `ticket` (QR-Code lokal, Code, Link), `table` (Positionen + Gesamt)
  - `divider`, `html` (wird angeglichen)
- Bequeme Wege:
  - `TIX_Mail_Layout::send($to, $subject, $m)`
  - `legacy($heading, $html, $subtitle, $note, $kind)` (= `TIX_Emails::build_generic_email_html`)
  - `from_plain($subject, $text)`

### Mail-Arten

| Typ | Trigger | Beschreibung |
|---|---|---|
| Bestellbestaetigung | Bestellung abgeschlossen | Event-Karte, Tickets mit QR-Code und PDF-Link (bis 10, Rest als Hinweis), Gutscheine, Bestellübersicht |
| Status (Vorkasse, Storno, Erstattung, fehlgeschlagen) | Statuswechsel | Vorkasse mit Bankdaten |
| Reminder | Cron (`tix_send_reminder_email`) | Erinnerung am Vortag mit Tickets |
| Followup | Cron (`tix_send_followup_email`) | Nachfass-E-Mail nach dem Event |
| Abandoned Cart | Cron | Recovery-E-Mail fuer abgebrochene Warenkoerbe |
| Ticket-Transfer, Gästeliste, Warteliste, Tischreservierung | jeweilige Aktion | im Layout, mit QR-Code bzw. Event-Karte |
| Newsletter, Folgen, Wochenmail | Werbung | `kind = promo`, Abmeldelink + List-Unsubscribe |
| WordPress-Kernmails | WordPress | Konto, Passwort, E-Mail-Änderung im Layout |

Seit 1.38.471 ohne Doppelmails: Die Bestellmail an den Veranstalter entfällt in `TIX_Organizer_Admin::notify_new_order`, wenn dieselbe Adresse sie über die Benachrichtigungen bekommt bzw. diese pausiert sind; die WooCommerce-Kontomail entfällt neben der Aktivierungs-Mail.

---

### Orts- und Adresssuche (Google Places, seit 1.38.474)

- `TIX_Places` leitet an die Google Places API (New) weiter. Der Schlüssel liegt in der Option `tix_google_places_key` (je Seite) und kommt nie in Browser, Apps oder Logs.
- Aufrufe gehen per IPv4 an Google (Hook `http_api_curl`, nur `places.googleapis.com`), weil der Schlüssel auf die IPv4 des Servers beschränkt ist.
- Datenschutz: Es werden keine Besucher-IPs weitergegeben (kein `X-Forwarded-For`, keine `userIp`).
- Zwischenspeicher: Vorschläge 1 Tag, Details 7 Tage. Treffer daraus zählen nicht.
- Kosten: WP-Admin **Tixomat → Ortssuche (Google)** zeigt die Aufrufe je Tag und Monat (`tix_places_stats`) und das Tageslimit.
- Google-Fehler werden ohne Schlüssel geloggt (`TIX Places: …`).
- Vertrag: siehe REST-Tabelle, Zeile „Orts- und Adresssuche (Google Places)“.

---

### Orte je Veranstalter und Zusammenführen (seit 1.38.475)

`TIX_Locations` (nur Mehr-Veranstalter-Modus):

- **Eigene Orte:** Veranstalter und Team sehen und setzen nur eigene Orte, Admins alle.
- **Selbstheilung** (einmalig, `tix_heal_loc_owner_475`):
  - Orte ohne Veranstalter bekommen den Veranstalter mit den meisten Events dort.
  - Jeder weitere nutzende Veranstalter bekommt eine eigene Kopie; seine Events werden umgehängt, die Kopie zeigt öffentlich auf das Original.
  - Ungenutzte Orte bleiben bei der Plattform.
  - Von Hand: `wp tixomat locations-assign [--dry-run]`.
- **Kanonischer Ort** `_tix_venue_canonical` (0 = selbst).
  - Automatisch zusammengeführt wird bei gleicher `place_id` oder bei Koordinaten unter 50 m bzw. gleicher normalisierter Adresse (Straße/Str., ß/ss, Umlaute, Hausnummer-Zusatz, PLZ) **und** ähnlichem Namen (Trigramme, `tix_venue_merge_threshold`, Vorgabe 0.6).
  - Grenzfälle landen als Vorschlag in WP-Admin **Tixomat → Orte zusammenführen** mit „Zusammenführen“, „Nicht dasselbe“ und „Trennen“.
  - Geprüft wird beim Speichern eines Orts. Alle auf einmal: `wp tixomat venues-merge [--dry-run]` (gibt die Liste aus).

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
| `tix_organizer_broadcast` | `TIX_Notifications` | Weitere Kanäle für Veranstalter-Nachrichten (Mehr-Veranstalter-Modus): `($extra, $oid, $title, $body, $event_id, $target)` → Zahl zusätzlicher Empfänger (genutzt von `TIX_Mail_Follow`) |

---

## 43. JavaScript & CSS Assets

### CSS-Dateien

| Datei | Beschreibung |
|---|---|
| `fonts-local.css` (Handle `tix-google-fonts`, seit 1.38.381) | Schriften **lokal** statt Google Fonts: Outfit, Inter (auch kursiv), Sora, DM Sans als Variable Fonts (100–900, latin + latin-ext, `assets/fonts/<schrift>/`, OFL). Frontend, wp-admin, Login, eigene Login-Seite, Frontend-Dashboard, Gäste-QR- und Weitergabe-Seite; Mails laden keine Webschrift mehr. Karten-/Typografie-Schriften außerhalb dieser vier (`TIX_Settings::LOCAL_FONTS`) kommen weiter von Google (`tix-card-fonts`, Hinweis in den Einstellungen). |
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
| `manrope/Manrope-500.ttf` | Standard-Schrift fuer Ticket-Templates |
| `manrope/Manrope-800.ttf` | Fette Schrift fuer Ticket-Templates |
| `manrope/Manrope-700.ttf` | Schrift fuer Ticket-Codes |

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
| Ticket-E-Mail erneut senden | Sendet die Ticket-E-Mail erneut an den Kunden (Inhaber-E-Mail des Tickets, sonst Rechnungs-E-Mail der Bestellung, oder die eingegebene Adresse) mit Knopf „Ticket herunterladen“ = Ticket-Online-Seite `/?tix_dl=<Token>` (`TIX_Tickets::get_download_url`, Token wird bei Bedarf erzeugt) und Ticket-Code; Versand wird im Ticket protokolliert (`_tix_ticket_send_log`, `scope: support`). Bis 1.38.375 zeigte der Link auf die nicht existierende Adresse `/ticket-download/?code=…`. Ohne Ticket-Code oder bei Mailfehler kommt eine Fehlermeldung statt „gesendet“. |
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
5. Anhänge: wie Portal-Upload (seit 1.38.387 geschützt in `uploads/tix-private/support/<id>/`, siehe §59 „Support: Anhänge“, Typ nach Inhalt: jpg/png/gif/webp/heic/pdf/doc/docx/txt), max. 10 MB/Datei, 10 je Mail; Inline-Bilder < 15 KB (Signatur-Logos) ignoriert; Abgelehntes als interne Notiz. Mails > 30 MB: nur Kopf + Hinweis.
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

### Support-Modell (1.38.451, `TIX_Support_Model`)

**Modus** (`evendis` | `self` | `evendis_package`, setzt nur ein Admin per `POST /support/mode`): Seite Option `tix_support_mode` (Vorgabe Plattform `evendis`, sonst `self`), auf der Plattform je Veranstalter Meta `_tix_support_mode` (Vorgabe: Partner-Veranstalter `self`, eigene `evendis`). **Zuständigkeit** je Anfrage (Ort des Kaufs, gespeichert beim Anlegen): `_tix_sp_responsible` organizer\|evendis, `_tix_sp_resp_org`, `_tix_sp_resp_partner`. Plattform: Bestellung bzw. Event eines geteilten Events (`_tix_syndicated`) → Partner-Veranstalter, außer mit Paket; sonst evendis. Einzelseite: `self` → Team der Seite, `evendis_package` → evendis.

**Partner-Brücke:** Anfragen auf evendis.de zu geteilten Events bleiben dort die Kunden-Anfrage (Mail/App laufen über evendis). Die Partner-Seite bekommt eine Kopie (`_tix_sp_origin = platform`, `_tix_sp_remote_id`) in ihr Postfach; Team-Antworten und Status der Kopie gehen an evendis zurück und von dort an den Kunden (Autor „<Veranstalter>-Team“), neue Kunden-Nachrichten und evendis-Antworten (als Notiz) gehen an die Kopie. Die Partner-Seite mailt dem Kunden nie selbst (`staff_reply` mit `deliver` = false). Fehler → Warteschlange `_tix_sp_bridge_queue`, Cron `tix_support_bridge_flush` (bis 6 Versuche, Reihenfolge bleibt), Wiederholungen werden über `message_id` erkannt. Ohne Partner-API: Mail an den Veranstalter + Notiz. Awareness-Meldungen sind ausgenommen.

**Postfach-Merker** (1.38.452): `_tix_sp_waiting` (team\|customer) und `_tix_sp_customer_n` werden bei jeder Änderung von `_tix_sp_messages` nachgeführt (Hook auf added/updated_post_meta, alle Wege), gelesen je Mitarbeiter in `_tix_sp_read` (Nutzer → Anzahl Kunden-Nachrichten), Zuweisung `_tix_sp_assignee`, Empfänger Option `tix_support_notify` bzw. Veranstalter-Meta `_tix_support_notify`.

**Rückfragen** evendis → Veranstalter (`_tix_sp_inquiries`, Frist, Cron `tix_support_inquiry_remind` stündlich, eine Erinnerung). Kulanz: Erstattung nur nach Freigabe (`TIX_Support_Model::refund_approved()`).

### Dateien

| Datei | Zweck |
|---|---|
| `includes/class-tix-support.php` | PHP: CPT, Admin-Dashboard, AJAX, Shortcode, E-Mails |
| `includes/class-tix-support-mail.php` | PHP: E-Mail-Eingang, Kennzeichen, Einstellungen, Cron, Rückwärts-Import |
| `includes/class-tix-support-imap.php` | PHP: IMAP-Client (Sockets) + MIME-Parser, ohne WordPress-Abhängigkeit |
| `includes/class-tix-support-model.php` | PHP: Support-Modus, Zuständigkeit, Studio-Postfach, Textbausteine, Rückfragen, Partner-Brücke |
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
- Prueft ob Stock zurueckgekehrt ist → benachrichtigt `type=soldout` Eintraege. Seit 1.38.393 nativ (`TIX_Waitlist::has_stock()`): Event veroeffentlicht, Verkauf an, nicht abgesagt/verschoben/vorbei, mindestens eine oeffentliche Kategorie (online, nicht intern/Abendkasse/versteckt) mit `stock` > 0 oder unbegrenzt (-1/leer); WooCommerce-Produkte nur, wenn WooCommerce aktiv ist (vorher: Kategorien ohne `product_id` nie benachrichtigt, mit `product_id` ohne WooCommerce fataler Fehler im Cron). Wer schon gekauft hat, wird uebersprungen.

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

### Datenspiegel Supabase (`TIX_Mirror`, Tixomat → Datenspiegel)

Zweite Speicherung aller Seiten in einem eigenen Supabase-Projekt (EU). **WordPress bleibt führend**; aus Supabase wird nie zurückgeschrieben. Schema und Anleitung: `supabase/migrations/`, `supabase/README.md`.

- **Was:** Seite (`sites`), Events (`events`), Ticket-Kategorien (`ticket_categories`, id `<event>:<index>`), Kunden = WP-Konten (`customers`), Bestellungen (`orders`, `order_items`, `payments`), Tickets (`tickets`), Check-in-Verlauf (`checkins`, jeder Vorgang in/reset), Stornos/Erstattungen/Rückbuchungen (`refunds`, kind cancel/refund/chargeback). Schlüssel überall `(site, id)`, `site` = Domain (Einstellung „Kennung der Seite“). `change_log` hält per Trigger jede tatsächliche Änderung (gleicher Stand erneut gesendet → kein Eintrag).
- **Nie gespiegelt:** Passwörter, PINs (z. B. Kassen-PIN `_tix_pos_pin`), Hashes, Login-/Push-/Download-Tokens, `order_key`, Check-in-Passwort, Awareness, Ausweisfotos, IBAN im Klartext, Pushover-Zugang, Geräte-Schlüssel und Nonces des Guthabens, 2FA-/Wiederherstellungs-Daten.
  - Filter 1: `TIX_Mirror_Map::DENY` (Teilstrings der Meta-Schlüssel, u. a. `pass`, `token`, `secret`, `_pin`, `pin_`, `hash`, `_key`, `wfls_`, `2fa`).
  - Filter 2: Konto-Meta **nur per Erlaubnisliste** `CUSTOMER_META_ALLOW` (+ Namen/Rechnungsfelder; seit 1.38.470). Neue `_tix_`-Felder bleiben draußen, bis sie bewusst aufgenommen werden.
  - Filter 3: Jeder Wert, der wie ein Passwort-/PIN-Hash aussieht (`$wp$`, `$2y$`, `$P$`, argon …), wird verworfen, egal unter welchem Feldnamen.
  - Beim Update auf Spiegel-DB 2 werden alle Konten einmal neu gesendet (Option `tix_mirror_requeue`).
  - Tilgen im Spiegel: `supabase/tools/tilgen-konto-meta.sql` über `migrate.sh … --sql`.
- **Laden:** `tixomat.php` lädt nur `class-tix-mirror.php`, das die übrigen `class-tix-mirror-*.php` nachlädt.
- **Postausgang** `{prefix}tix_mirror_outbox` (entity, object_id, op, payload, rev, attempts, next_try, last_error): `TIX_Mirror::touch($entity, $id, $op, $payload)` merkt im Speicher vor; am Request-Ende ein einziges `INSERT … ON DUPLICATE KEY UPDATE` (rev+1). Kein Netzwerk im Request, jeder Fehler wird geschluckt → Bestellung/Check-in werden nie aufgehalten.
- **Senden:** Cron `tix_mirror_flush` jede Minute (Sperre `tix_mirror_lock`): baut je Eintrag den **aktuellen** Stand (`TIX_Mirror_Map::plan`), bündelt Upserts je Tabelle (`on_conflict=site,id`, bei Datenfehler einzeln nachgeschickt), räumt entfernte Positionen/Kategorien per RPC `mirror_prune` auf, löscht fehlende Objekte. Eintrag wird nur entfernt, wenn `rev` unverändert ist. Fehler: Backoff 1, 2, 4 … 60 min; ab 8 Fehlversuchen Alarm-Mail (höchstens alle 6 h, Empfänger „Alarm an“ bzw. admin_email).
- **Erfassen** (`TIX_Mirror_Hooks`), ohne Eingriff in die Schreibstellen: Post-Meta/save_post/Statuswechsel/Löschen von `event` und `tix_ticket`; Nutzer + relevante Nutzer-Meta (billing_*, Namen, `_tix_*`); Optionen `_tix_order_*_{id}`, `_tix_refund(_total)_{id}`, Zahlungsreferenzen; Post-Meta `_tix_payment_*` an der Bestell-ID; Aktionen `tix_native_order_created`, `tix_order_status_changed`, `tix_order_completed`, `tix_order_cancelled`, `tix_order_refund_recorded`, `tix_order_chargeback`, `tix_ticket_checked_in`, `tix_ticket_checkin_reset`. **Nachlauf:** jede Minute alle `tix_orders` mit neuem `date_modified` (fängt direkte `$wpdb->update` ohne Hook, z. B. E-Mail, Zahlart, Anonymisierung).
- **Löschweg:** `delete_user` (Web „Konto löschen“, App `/auth/delete`, Admin) → Eintrag `forget` mit E-Mail → RPC `mirror_forget` löscht das Profil und tilgt jeden Verlaufseintrag mit dieser Kunden-ID bzw. E-Mail. Bestellungen/Tickets folgen WordPress (Web anonymisiert sie, App behält sie). Endgültig gelöschte Posts werden im Spiegel gelöscht.
- **Kontolöschung über den Kern** (`TIX_Account_Delete`, 1.38.460): `tix_account_deleted` stellt den Löschweg sicher (zusätzlich zu `delete_user`).
  - Kommen Bestellungen oder Tickets anonymisiert an (E-Mail `…@removed.invalid`), tilgt RPC `mirror_purge_history` ihren älteren Verlauf; nur der neueste, anonyme Eintrag bleibt.
  - Das gilt sofort und genauso, wenn WordPress aufbewahrte Rechnungsdaten (1.38.461) nach Ablauf per Cron anonymisiert. Bis dahin steht in der aktuellen Zeile, was WordPress aufbewahrt.
  - Spalte `orders.retained_until` (Migration `20261016220000_mirror_retention.sql`): wird aus dem Zustand abgeleitet (E-Mail anonymisiert, Name/Anschrift noch da → 31.12.(Bestelljahr + Frist)). Nach dem Ablauf ist sie leer.
- **Papierkorb:** Ein Event im Papierkorb bleibt im Spiegel mit `status = trash`; Kategorien, Gäste, Tickets und Bestellungen bleiben. Wiederherstellen setzt den Status wie WordPress (meist `draft`). Gelöscht wird nur, was WordPress endgültig löscht.
  - Seit 1.38.463 löscht auch der Papierkorb im WP-Admin nichts mehr (`TIX_Cleanup` erst beim endgültigen Löschen).
- **Plattform-Guthaben (1.38.466):** Geldbörsen mit `scope_type = platform`; Buchungen tragen `organizer_id` (Cashless beim Veranstalter, Migration `20261016230000_mirror_wallet_org.sql`). Cashless-Posten der Abrechnungen sind `settlement_items.type = cashless`.
- **Erstbefüllung** (`TIX_Mirror_Backfill`, Option `tix_mirror_backfill`): Seite → Events → Kunden → Bestellungen → Tickets in Paketen à 500, nur solange der Postausgang < 3000 ist; anhalten/fortsetzen an derselben Stelle; startet von selbst beim ersten Einrichten.
- **Rechnungs-Kern (1.38.485):** `invoices` (Belege R/RK/B/BK mit Schnappschuss `data`, `pdf_path` und `pdf_sha256`; die PDF-Datei selbst wird nicht gespiegelt) und `invoice_counters` (id `<issuer_key>|<series>|<year>`).
  - Erfasst über `tix_invoice_created` und den Nachlauf (neue IDs), Erstbefüllung und täglicher Abgleich (Nummer, Art, Brutto, PDF-Prüfsumme).
  - IBAN-artige Stellen im Schnappschuss-Text (z. B. Fußzeile) werden maskiert.
  - Der Löschweg fasst Belege nicht an (Aufbewahrung 10 Jahre). Migration `20261016240000_mirror_invoices.sql`.
  - Übergabe an die Buchhaltung (1.38.490): `export_provider`, `export_status`, `export_ref`, `export_error`, `export_tries`, `export_at`.
    · Erfasst per Nachlauf über `export_at` (WP-Ortszeit, eigene Marke `invoice_export`). Der Abgleich prüft `export_status` mit.
    · In `export_error` werden IBAN, Bearer-/API-Schlüssel, `key=…`-Paare und lange Kennungen maskiert (`TIX_Mirror_Extra::mask_secret_text`); UUIDs bleiben stehen.
    · Das E-Rechnungs-XML wird nicht gespiegelt. Migration `20261017090000_mirror_invoice_export.sql`.
- **Team-Protokoll (1.38.494):** `audit_log` aus `tix_audit_log` (Löschungen, Exporte, Gutschriften durch das Team).
  - Betroffene nur als Konto-ID bzw. Gast-HMAC; Freitext in `summary` wird wie Fehlertexte maskiert.
  - Erfasst über `tix_audit_logged` und den Nachlauf (neue IDs), mit Erstbefüllung und Abgleich.
  - Der Löschweg fasst das Protokoll nicht an. Migration `20261017120000_mirror_audit_log.sql`.
- **Gast ohne Konto gelöscht (1.38.494):** `tix_guest_deleted` → `mirror_forget` mit Kunden-ID 0 (Verlauf mit dieser E-Mail tilgen). Bestellungen und Tickets kommen anonymisiert über Nachlauf bzw. Meta-Hooks und tilgen ihren älteren Verlauf; aufbewahrte Rechnungsdaten bleiben.
- **Geschenkgutscheine mit Versand (1.38.496):** `gift.recipient_email`, `sent_to`, `sent_at`, `send_at`, `sends[]` und Bar-Abbuchungen in `redemptions` (`id`, `by`, `event_id`, `void_of`) stehen im Gutschein-Inhalt (`coupons.data`).
  - Löschweg: `mirror_forget` tilgt zusätzlich jeden Gutschein-Verlaufseintrag, der die gelöschte E-Mail enthält (Käufer oder Beschenkte*r).
  - Kommt ein Gutschein anonymisiert an (`…@removed.invalid`), wird sein älterer Verlauf getilgt.
  - Das Anonymisieren in WordPress selbst (Vorschlag an den Plugin-Chat) gehört in `TIX_Account_Delete`.
  - `vergleich-wp.php` und `datenschutz-scan.sql` melden „Gutscheine mit Empfänger-Adresse trotz gelöschtem Käufer“. Migration `20261017150000_mirror_coupon_privacy.sql`.
- **Künstler (1.38.517, nur evendis):** Tabelle `artists` aus `tix_artist` (Name, Slug, Post-Status, `status`, `kind`, `verified`, `managed_by`, `duplicate_of`, `merged_into`, Spotify/Apple-ID, Profil in `data`).
  - Nicht gespiegelt: `_tix_artist_log` (Nutzer-IDs, Gründe), die abgeleiteten Suchschlüssel (`_norm`, `_spotify`, `_apple`) und `_tix_artist_ref`. Die Event-Zuordnung steht vollständig in `events.data._tix_artists`.
  - Künstler eines Sandbox-Veranstalters (`managed_by`) bleiben draußen.
  - Erstbefüllung, Abgleich (Post-Status, Name, Status) und Vergleich sind enthalten. Migration `20261020090000_mirror_artists.sql`.
- **Künstlern folgen (1.38.518):** Konto-Meta `_tix_app_following_artists` steht in der Erlaubnisliste und damit in `customers.data`.
  - E-Mail-Folgen (`tix_artist_mail_followers`) gehen **pseudonymisiert** in die Tabelle `artist_followers`. Statt der Adresse steht dort `email_hash` (HMAC-SHA256 mit `TIX_MIRROR_ENC_KEY`, auf allen Seiten gleich, nicht umkehrbar). Abmelde-Token und IP-Hash werden nie gespiegelt.
  - Neue Zeilen kommen über den Nachlauf (ID). Bestätigen, Ändern, Abmelden und Löschen (in WordPress immer echtes DELETE) holt der tägliche Abgleich (Künstler, Status, Städte, Bestätigung).
  - Folge: Die Adressen lassen sich nicht aus dem Spiegel wiederherstellen. Migration `20261020120000_mirror_artist_followers.sql`.
- **E-Mail-Folgen von Veranstaltern** (`tix_mail_followers`, Entscheidung des Users 2026-10-20): genauso pseudonymisiert in `organizer_followers` (`email_hash`, `share_email`, Status, Einwilligung, Bestätigung; nie Adresse, Token oder IP-Hash).
  - Neue Zeilen kommen über den Nachlauf, Änderungen und Löschungen über den Abgleich.
  - Folgen von Sandbox-Veranstaltern bleiben draußen. Die Erstbefüllung holt den Bestand nach. Migration `20261020150000_mirror_organizer_followers.sql`.
  - Ausgeliefert mit 1.38.520 (beide Spiegel-Teile zusammen).
- **Nicht gespiegelt: Sandbox (Live-Demo Vertrieb).** Demo Club und Vorschau-Veranstalter (`TIX_Sales::is_sandbox_organizer($oid)`, Filter `tix_sandbox_organizer`).
  - Ausgeschlossen werden Veranstalter, Events (Haupt-Veranstalter), Kategorien, Gäste, Bestellungen mit einer Position an einem Sandbox-Event (inkl. Positionen, Zahlung, Check-ins, Stornos), Tickets, Gutscheine (`organizer_id`/`order_id`), Geldbörsen, Buchungen, Abrechnungen, Belege, Protokoll (`scope …:<id>`) und Stempelkarten.
  - Schon Gespiegeltes löscht der Plan bzw. der tägliche Abgleich: Er zählt Sandbox auf der WP-Seite nicht mit, nimmt sie aber erst nach der Paketgrenze heraus.
  - Übernommene Vorschauen sind keine Sandbox mehr und kommen normal in den Spiegel. Der Sandbox-Stand wird je Cron-Lauf neu bestimmt.
  - `vergleich-wp.php` zählt Sandbox nicht mit. `datenschutz-scan.sql` meldet Sandbox-Spuren.
  - Zweite Sicherung: Bestellungen mit Zahlart `demo` und Gutscheine mit `demo` = 1 gelten immer als Sandbox.
  - Vorschau-Events erkennt der Spiegel auch an ihrer eigenen Meta `_tix_preview` bzw. `_tix_preview_scratch`, selbst wenn der Vorschau-Veranstalter schon gelöscht ist (abgelaufene Vorschau, Event im Papierkorb). Beim Übernehmen fällt die Meta weg.
  - Getestet mit dem echten `TIX_Sales` (1.38.508, `wp tixomat demo reset`): Vom Demo Club kommt nichts im Spiegel an.
- **Löschungen gebündelt:** Lösch-Operationen `{site, <spalte>=eq.<wert>}` einer Runde gehen je Tabelle und Spalte als ein Aufruf mit `in.(…)` raus, in Paketen à 100. Ein Demo-Reset mit 20 Bestellungen braucht so 7 statt 124 Aufrufe.
- **Nicht gespiegelt: Support-Anfragen** (`tix_support_ticket`, Nachrichten, `_tix_sp_*`). Sie können Freitext mit IBAN, Gesundheits- oder Zahlungsdaten enthalten. Die Auszahlungs-Anfrage beim Konto-Löschen (1.38.499, `_tix_sp_retain_until`, `_tix_sp_wallet_payout`) behält ihre Frist nur in WordPress; `mirror_forget` kollidiert damit nicht.
- **Guthaben beim Konto-Löschen (1.38.499):**
  - Der Bonus verfällt als Buchung `type = expire`.
  - Das bezahlte Restguthaben bleibt an der geschlossenen Geldbörse (`wallets.status = closed`, `user_id` negativ, `balance_paid` > 0).
  - Der Bonus-Verfall im täglichen Lauf ist ebenfalls `expire`.
- **Guthaben-Korrektur durch das Team:** `type = adjust`, `channel = admin`; Grund und Mitarbeiter stehen in `wallet_transactions.meta` (`reason`, `by`).
- **Werkzeuge:** `migrate.sh` spielt nur neue Migrationen ein (`--all` alle, `--verify` Rechte, `--sql` Datei). `datenschutz-scan.sql` ist die projektweite Suche (nur lesend).
- **Kennung der Seite:** `TIX_MIRROR_SITE` in wp-config (Vorrang), sonst Option `tix_mirror['site']`, sonst Domain aus `home_url()`. Fest einstellen, damit ein Domain-Wechsel keine zweite Seite anlegt. Umbenennen im Spiegel: `supabase/tools/kennung-evendis.sql` als Vorlage (eine Transaktion, ohne neuen Verlauf). Kennungen: `kitchenklub.de`, `mallorca-festival-xxl.de`, `evendis.de`.
- **Zugang:** `TIX_MIRROR_URL` / `TIX_MIRROR_KEY` in wp-config (bevorzugt) oder Option `tix_mirror`; der Schlüssel wird nie angezeigt oder geloggt. `TIX_MIRROR_DISABLED` = Not-Aus. Schalter „Diese Seite spiegeln“ ist standardmäßig an (auch Lizenzkunden, nur mit AVV); ohne Zugang wird nichts erfasst.
- **Abgelöst:** `TIX_Sync_Supabase` (nur Tickets, altes Projekt) ist nur noch Weiche (`is_enabled()` immer false). Alte Werte `supabase_*` bleiben in `tix_settings` stehen, werden aber nicht übernommen (Hinweis auf der Status-Seite, `legacy_url`).
- **Paket 2** (`TIX_Mirror_Extra`, Migration `20261016180000_mirror_paket2.sql`):
  - **Veranstalter** (`organizers`, aus `tix_organizer`): Auszahlungsdaten in `payout` mit `iban_masked` und `iban_enc`. Nie gespiegelt: Pushover, ausstehende IBAN, Code-Hashes.
  - **Gästelisten** (`guests`, id `<event>:<gast-id>` bzw. `<event>:d<index>` für `_tix_guestlist`): laufen mit dem Event.
  - **Prämien/Stempelkarte** (`loyalty`, id `<user>:<veranstalter>`): laufen mit dem Kunden und sind nicht mehr in `customers.data`.
  - **Gutscheine und Geschenkkarten** (`coupons`, id = Code, aus der Option `tix_coupons`): Gesendet werden nur geänderte Codes (Prüfsummen in `tix_mirror_coupon_fp`, gemerkt erst nach Erfolg über die Operation `remember`). Gelöschte Codes werden entfernt.
  - **Guthaben** (`wallets`, `wallet_transactions`, Beträge in Cent) und **Abrechnungen** (`settlements`, `settlement_items`): Erfasst werden sie über den Nachlauf (`TIX_Mirror_Extra::sweeps()`: `updated` bzw. neue IDs; neue Tabellen werden ab Anfang gelesen). Geräte-Schlüssel und Nonces des Guthabens werden nie gespiegelt.
- **IBAN** steht nie im Klartext im Spiegel:
  - Mit `TIX_MIRROR_ENC_KEY` in wp-config wird sie für den Spiegel mit eigenem Schlüssel verschlüsselt (`m1:`, AES-256-GCM, Schlüssel `sha256('tix-mirror-iban|' . KEY)`). Damit lässt sie sich ohne die Seite wiederherstellen (`TIX_Mirror_Extra::iban_from_mirror`).
  - Ohne den Schlüssel bleibt der verschlüsselte Wert der Seite (`v1:`), der nur mit deren Salts lesbar ist.
  - Die Schuldner-IBAN aus `tix_settings` wird nicht gespiegelt.
- **Täglicher Abgleich** (`TIX_Mirror_Reconcile`, ab 3 Uhr nach der Erstbefüllung, Knopf „Abgleich jetzt“):
  - Vergleicht je Tabelle in Paketen à 1000 die IDs und eine md5-Prüfsumme über Kernfelder. WordPress rechnet in `wp_fingerprints()`, Supabase in `mirror_fingerprints()`; beide Formate müssen gleich bleiben.
  - Fehlendes, Abweichendes und Überzähliges geht in den Postausgang. Gutscheine werden täglich komplett gesendet.
  - Der Bericht steht in der Option `tix_mirror_report` (Status-Seite) und in der Tabelle `sync_reports`.
  - Danach wird der Verlauf gekürzt: RPC `mirror_prune_log`, Einstellung „Verlauf aufbewahren“, Option `log_days`, Standard 10 Jahre (Entscheidung des Users).
- **Erstbefüllung** hat die neuen Schritte hinten angehängt. Seiten mit fertiger Erstbefüllung holen beim Update nur die neuen Datenarten nach (`steps_total`).
- **Lesender Vergleich** `wp eval-file wp-content/plugins/tixomat/supabase/tools/vergleich-wp.php` schreibt nichts. Er zeigt:
  - Anzahlen je Tabelle in WordPress und im Spiegel
  - Stichproben (neueste Bestellungen, Tickets, Events, Geldbörsen)
  - Datenschutz-Suche (Passwort-Hashes, Token-Felder, Klartext-IBAN)
  Der tägliche Abgleich entfernt außerdem verwaiste Kinder (RPC `mirror_prune_orphans`).
- **Notfall-Einlass** ohne WordPress: `supabase/tools/notfall-einlass.php --url=… --key-file=… --site=… [--event=ID | --date=…]` schreibt CSV + offline-HTML (Suche, „einlassen“ je Gerät, Rückfragen bei schon eingecheckt / Zurücknehmen). Nur lesend.
- **Test:** `tests/test-mirror-wp.php` (Paket 1) und `tests/test-mirror-paket2-wp.php` gegen den lokalen Stand aus `supabase/local/`.

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
| POST | `/auth/handoff` | Einmal-Code (evendis Studio) -> Token, Antwort wie `/auth/login` | Public |
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
| Registrierung alt | `POST /auth/register` ohne `verify` → sofort Token + Willkommens-Mail (keine WordPress-„Passwort festlegen“-Mail). **Seit 1.38.386** sieht ein solches Konto nur, was im Konto selbst gekauft bzw. angelegt wurde, bis die E-Mail bestätigt ist (siehe „E-Mail-Nachweis“) | ältere KitchenKlub-Builds | 1.38.324 |
| E-Mail-Nachweis | Seit 1.38.386 (`TIX_App_Account::email_trusted()`): Daten, die nur über die E-Mail zugeordnet sind – Tickets (`GET /customer/tickets`, `/customer/events`, Profil-Zähler, Ticket-PDF, Umschreiben), Gastbestellungen (`GET /customer/orders`, `GET /customer/orders/{id}` ohne `key`, vermittelte Bestellungen), Support-Anfragen (`GET/POST /customer/support…`, Web-Portal, Push bei Team-Antwort), Web-Konto (Übersicht, Bestellungen, Datenexport, Kontolöschung, „Meine Tickets“) – gibt es nur mit **bestätigter E-Mail**. Bestätigt durch: `POST /auth/code/confirm` (jeder Zweck), Passwort-Reset per Mail-Link (`after_password_reset`), Magic Link im Web; Konten, die vor dem Update bestanden (Site-Option `tix_email_trust_since`, wird beim ersten Laden gesetzt) und mindestens eine eigene bezahlte Bestellung (`customer_id` + gleiche E-Mail) haben, gelten automatisch als bestätigt. Gespeichert in `_tix_email_confirmed` (+ `_tix_email_confirmed_by`: code, password_reset, magic_link, legacy_order). Ohne Bestätigung: nur Tickets aus im Konto gekauften Bestellungen, nur im Konto angelegte Anfragen; Gastbestellungen werden nicht mehr automatisch dem Konto zugeordnet. Antworten bleiben im Format, zusätzlich `"email_confirmed": false, "notice": "Bestätige deine E-Mail-Adresse …"` in `GET /customer/tickets` und `GET /customer/support` (alte Builds ignorieren die Felder und zeigen leere bzw. kürzere Listen). Beispiel: `{"success":true,"tickets":[],"count":0,"email_confirmed":false,"notice":"Bestätige deine E-Mail-Adresse (Anmeldung per Code oder Passwort zurücksetzen), damit wir dir auch Tickets und Anfragen zeigen, die nicht in diesem Konto gekauft wurden."}` | beide | 1.38.386 |
| Anmelde-Sperre | Seit 1.38.386: `POST /auth/login` und jede Anmeldung über `wp_signon` (Web-Konto, beide Kassen, eigene Login-Seite, wp-login.php) sperren nach **10 Fehlversuchen je Besucher-IP oder je Konto in 15 min** (`TIX_Customer_Auth::login_blocked`). REST: 429 `{"code":"tix_rate_limit","message":"Zu viele Versuche. Bitte warte ein paar Minuten und versuche es dann erneut.","data":{"status":429}}`; Kassen-AJAX: dieselbe Meldung als `message`. Ersetzt für diese Wege fail2ban (das hinter der Durchleitung nur die Proxy-IP sähe). | beide | 1.38.386 |
| Support: Anhänge | Seit 1.38.387 **nicht mehr öffentlich**. **Seit 1.38.391 außerhalb des Webroots:** Basisordner = Konstante `TIX_PRIVATE_DIR`, sonst Option `tix_private_dir`, sonst automatisch zwei Ebenen über dem Webroot `…/tix-private/<app>` (RunCloud: `/home/runcloud/tix-private/<app>`), gespeichert in `tix_private_base` – nur im Web-Request ermittelt; vor jedem Dateisystem-Zugriff auf einen Ort außerhalb wird `ini_get('open_basedir')` geprüft (`TIX_Support::path_allowed()`), damit nie eine PHP-Warnung entsteht (1.38.390 brach so auf kitchenklub.de mit `display_errors` das REST-JSON, zurückgenommen; Neuauflage 1.38.391); nicht erlaubte Orte → stiller Rückfall; ist nichts davon beschreibbar, Rückfall `uploads/tix-private` mit Hinweis im Admin. Umzug nur im Web-Request vom zuletzt genutzten Ort (`tix_support_files_base`) zum aktuellen, nie zurück in den Webroot; Auslieferung liest während eines Wechsels auch am alten Ort. `tix_support_files_version` = 2. Im Rückfall (zusätzlich `index.php` in jedem Unterordner gegen Verzeichnis-Listing): Dateien in `uploads/tix-private/support/<ticket>/` unter zufälligem Namen ohne Endung (Ordner per `.htaccess` gesperrt – `RewriteRule .* - [F,L]` für OpenLiteSpeed plus `Require all denied` für Apache –, `index.php`). Ausgeliefert nur über **signierte, befristete Links** `<Kunden-Adresse>/?tix_sp_file=<ticket>-<datei>&exp=<unix>&sig=<hmac>` (HMAC-SHA256 mit `wp_salt('auth')`, Prüfung per `hash_equals`; gültig 6 h in Oberflächen und Schnittstellen, 30 Tage in Mails an Kunden; abgelaufen/falsch → 403, unbekannt → 404). Die Links erzeugt das Plugin nur für Berechtigte (Admin, Kunde mit Konto oder Support-Schlüssel, App mit Token). Antworten: Anhänge `[{id, file, name, mime, size, date, url}]` – `url` ist der signierte Link, `file` der Schlüssel; Hochladen liefert `{name, file, url, size, mime}`; beim Antworten werden nur Dateien dieses Tickets übernommen (`file` oder signierter Link, Angaben aus der Ticket-Meta). Inline (Bilder, PDF) bzw. Download, `nosniff`, `Cache-Control: private`. Vorhandene Dateien aus `uploads/tix-support/` werden beim ersten Aufruf nach dem Update verschoben (Option `tix_support_files_version` = 1, Zuordnung alt→neu in `_tix_sp_file_map`), der alte Ordner wird gesperrt. Server mit nginx vor Apache sollten zusätzlich `location ^~ /wp-content/uploads/tix-private/ { deny all; }` und dasselbe für `/wp-content/uploads/tix-support/` setzen. | beide | 1.38.387 |
| Support: Gäste | Seit 1.38.387: `GET /public/support/{id}?key=` (auch `tix_sp_key`) → `{ok, request}` wie `GET /customer/support/{id}`; `POST /public/support/{id}/reply {key, message}` → `{ok, request}`. `key` = Schlüssel aus der Support-Mail (`?tix_sp_ticket=<id>&tix_sp_key=<key>`), Vergleich mit `hash_equals`. Falscher Schlüssel 403 `rest_forbidden`, nach 10 Fehlversuchen je Besucher-IP in 10 min 429 `tix_rate_limit`; Antworten höchstens 20 je IP in 10 min. Beispiel: `GET /wp-json/tixomat/v1/public/support/123?key=Ab12…` → `{"ok":true,"request":{"id":123,"subject":"…","status":"tix_open","messages":[{"type":"customer","content":"…","attachments":[{"name":"rechnung.pdf","url":"https://evendis.de/?tix_sp_file=123-…&exp=…&sig=…"}]}]}}` | beide | 1.38.387 |
| Support: interne Felder | Seit 1.38.386 enthalten Kunden-Antworten (`GET /customer/support`, `/customer/support/{id}`, Web-Portal) **kein `ai_summary`** mehr (nur Admin). Zugangsschlüssel des Portals wird zeitkonstant verglichen (`hash_equals`). | beide | 1.38.386 |
| Bestellung per Schlüssel | Seit 1.38.386: `GET /customer/orders/{id}?key=` (auch vermittelte) – nach **20 falschen Schlüsseln je Besucher-IP in 10 min** 429 `tix_rate_limit`; richtige Schlüssel zählen nicht (Abfragen nach der Zahlung bleiben frei). | beide | 1.38.386 |
| Event-Katalog | `GET /public/events` je Event zusätzlich `organizer_info {id,slug,name,logo}`, `category {id,slug,name}`, `venue {id,name,city,zip,lat,lng}`, `modules {tickets,music,loyalty,giftcards,support,muttizettel}`; Filter `category`, `organizer`, `city`, `q`; Seiten `page`/`per_page` (≤200) mit `total`/`has_more` | evendis | 1.38.325 |
| Ort-Koordinaten (`venue.lat/lng`) | Quelle: Koordinaten der Location (`_tix_loc_lat/_tix_loc_lng`), sonst – seit 1.38.373 – am Event gespeicherte (`_tix_venue_lat/_tix_venue_lng`, `TIX_Public_Platform::venue`); beide fehlen → `null`. **Geteilte Events** (Syndication): Der Push enthält zusätzlich `venue {name, address, city, zip, lat, lng}` (`TIX_Syndication_Push::venue_payload`; fehlen der Location Koordinaten, ermittelt die Quelle sie vorher einmal per Nominatim). Der Empfänger speichert sie am Event (`_tix_venue_geo_auto = source`, dazu `_tix_venue_city/_tix_venue_zip`); `meta._tix_venue_*` aus dem Push wird ignoriert, Nur-Bestand-Updates (`stock_only`) lassen den Ort unverändert. Ältere Partner ohne `venue` bzw. ohne Koordinaten: Der Empfänger ermittelt sie im Hintergrund (WP-Cron `tix_geocode_event`, ~10 s) aus `_tix_address` (bzw. Ortsname + PLZ/Ort) → `_tix_venue_geo_auto = 1`, nach Adressänderung erneut; Fehlschlag wird je Suchtext gemerkt (`_tix_venue_geo_failed`). Übertragene Koordinaten haben Vorrang vor ermittelten. Ältere Empfänger ignorieren das Feld `venue`. Nachziehen: `wp tixomat geocode-events [--dry-run] [--all] [--sleep=1]` (kommende veröffentlichte Events ohne Koordinaten: Location mit Adresse → Location, sonst Event-Adresse). | beide | 1.38.373 |
| Plattform | `GET /public/organizers` (Filter `q`, `city`, `category`), `GET /public/organizers/{id\|slug}` (+ `description`, `email`, `phone`, `address`, `events`), `GET /public/categories` (`upcoming`), `GET /public/cities`. Bilder: `logo` (medium) = Landing-Logo `_tix_org_landing_logo_id`, sonst Veranstalter-Bild `_tix_org_image_id`; `hero` (large) = **nur** eigenes Titelbild `_tix_org_landing_hero_id`, sonst `''` (seit 1.38.378 kein Rückfall mehr auf `_tix_org_image_id` – bei Partner-Veranstaltern ist das das Logo; App/Website zeigen dann einen Platzhalter, wie `GET /organizer/profile`). | evendis | 1.38.325 |
| Merkliste / Folgen | `GET/POST /customer/favorites`, `POST\|DELETE /customer/favorites/{event_id}` (User-Meta `_tix_saved_events`), dasselbe für `/customer/following` (`_tix_app_following`) | evendis | 1.38.325 |
| Veranstalter-Module | Post-Meta `_tix_org_modules` (JSON) am `tix_organizer`, Vorgabe: nur `tickets` | evendis | 1.38.325 |
| Mehr-Veranstalter | Option `tix_multi_organizer=1` (nur evendis.de): Veranstalter-Routen auf eigene Events begrenzt (`TIX_App_Scope`) | evendis | 1.38.326 |
| Geteilte Events | `GET /public/events[/{id}]` je Event `syndicated {site, checkout_url, sale_via_app, terms_url}` (sonst `null`). Geteilte Events (`_tix_syndicated=1`) haben `tickets_enabled=false`, **außer** `sale_via_app=true` (Quelle im Partner-Verzeichnis freigeschaltet + Häkchen am Event): dann verkauft die App über die Vermittlung. Ohne Vermittlung: `POST /customer/cart/quote` liefert `syndicated` und `sale_open=false`; Angebot mit Positionen und `POST /customer/orders` lehnen mit `code=tix_syndicated` (409, `data.syndicated`) ab; Web-Kasse lehnt ebenfalls ab. | evendis | 1.38.333 |
| Geteilte Events: Kauf | Mit `sale_via_app=true`: `POST /customer/cart/quote`, `POST /customer/orders`, `GET /customer/orders/{id}` **unverändertes Format**; Preise/Gebühren/Zahlarten/`payment_url` kommen von der Quelle. Zusätzlich im Angebot `seller {name, terms_url, privacy_url, revocation_url, notice}`, in der Bestellung `order.seller {name, mail_from_seller}`. Bestell-IDs vermittelter Bestellungen ≥ 800000001, `order_number` = Nummer der Quelle. | evendis | 1.38.334 |
| Support | `GET /customer/support`, `GET /customer/support/{id}`, `POST /customer/support/{id}/reply` **unverändert**. Neu nur ergänzend: Nachrichten, die per E-Mail eingingen, tragen `source: "email"` (sonst fehlt das Feld), `type` bleibt `customer`; sie erscheinen chronologisch im Verlauf (`date` = Sendezeit der Mail). Antworten per Mail öffnen gelöste Anfragen wieder (`status` → `tix_open`). | KitchenKlub, evendis | 1.38.340 |
| Support: Feed/Push | Bei jeder Team-Antwort (Admin → Support → Antworten; nicht bei internen Notizen, Kunden-Antworten oder reinem Statuswechsel) persönlicher Feed-Eintrag + Push über `TIX_Notifications::add_user_item`: `type: "support"`, Titel „Neue Antwort auf deine Anfrage“, Text „#<id> · <Betreff>: <Antwort, max. 120 Zeichen>“ (ohne Betreff „Anfrage #<id>: …“), **Aktion `support:ticket:<id>`** = Verlauf der Anfrage öffnen (`GET /customer/support/{id}`; Kunde muss mit dem Konto der Anfrage-E-Mail angemeldet sein). Empfänger: WordPress-Konto zur Kunden-E-Mail (Rückfall `post_author` bei gleicher E-Mail), ohne Konto nur Mail. KitchenKlub Store-Build 38 kennt die Aktion noch nicht und öffnet das Support-Formular (harmlos). Mail-Knopf „Im Support antworten“: `<Portal-Seite>?tix_sp_ticket=<id>&tix_sp_key=<access_key>#tix-sp-frontend` (KitchenKlub: `https://kitchenklub.de/kontakt/…`). | KitchenKlub, evendis | 1.38.341 |
| Universal Links | `https://<seite>/.well-known/apple-app-site-association` (auch `/apple-app-site-association`): 200, `application/json`, ohne Weiterleitung/Cache, `{"applinks":{"details":[{"appIDs":[…],"components":[{"/":"*","?":{"tix_sp_ticket":"?*"},"comment":"Support-Verlauf in der App"}]}]}}` – nur Links mit `tix_sp_ticket` öffnen die App. **Seit 1.38.385 zusätzliche Pfade** per Site-Option **`tix_app_link_paths`** (Liste von Vorgaben, unbekannte werden ignoriert; leer = nur Support-Link), angehängt nach dem Support-Eintrag: `events` → `{"/":"/events/*","comment":"Event-Seiten"}`, `veranstalter` → `{"/":"/veranstalter/*","comment":"Veranstalter-Seiten"}`, `suche` → `{"/":"/suche/*","comment":"Suche"}`, `start_p` → `{"/":"/","?":{"p":"?*"},"comment":"Startseite mit Parameter p"}`. Setzen: evendis `wp option update tix_app_link_paths '["events","veranstalter","suche","start_p"]' --format=json`, KitchenKlub `'["events"]'`, Mallorca nichts. App-IDs aus Option `tix_app_link_ids` (Liste „TEAMID.bundle“, Admin: Support → E-Mail-Eingang → „Universal Links“), leer = 404. kitchenklub.de: `6W25PJB528.de.kitchenklub.app`, evendis.de: `6W25PJB528.de.evendis.app`. Code: `includes/class-tix-app-links.php`. | KitchenKlub, evendis | 1.38.342, Pfade 1.38.385 Seit 1.38.518 Baustein `kuenstler` (`/kuenstler/*`, Künstler-Seiten), im Mehr-Veranstalter-Modus automatisch mit `veranstalter`. |
| Geteilte Events: Tickets | `GET /customer/tickets` enthält Spiegel-Tickets (gleicher Code wie bei der Quelle, `event_id` = Plattform-Event, QR `GL-<event_id>-<code>` gilt am Einlass der Quelle). Storniert = `status=cancelled` (bleibt sichtbar), eingecheckt/übertragen kommen per Webhook. Ticket-Antwort für Spiegel zusätzlich `mirror: true`, `source_site` (Name der Quelle). Post-Meta `_tix_ticket_mirror=1`, `_tix_ticket_source_site`. | evendis | 1.38.335 |
| Abrechnung: Berechtigung | Alle Routen unten: nur Mehr-Veranstalter-Modus (sonst 403 `tix_not_multi`), nur Inhaber/Team-Admin eines freigegebenen Veranstalters (`TIX_App_Scope::check_manager`: 401 `rest_not_logged_in`, 403 `rest_forbidden`/`organizer_pending`/`no_organizer`), immer nur der eigene Veranstalter. Beträge als Zahl in Euro (2 Nachkommastellen), Datum `YYYY-MM-DD`, Abzüge als positive Zahlen. | evendis | 1.38.343 |
| Abrechnung: Auszahlungsdaten | `GET /organizer/payout-details` → `{complete, holder, iban_masked ("DE89 •••• •••• •••• 3000"), iban_last4, has_iban, bic, billing {company,name,street,zip,city,country}, tax_status (vat_id\|tax_number\|small_business\|""), vat_id, tax_number, email, pending_iban, pending_email}` (volle IBAN nie). `POST /organizer/payout-details` (Felder wie GET, `iban` nur bei neuer IBAN) → Objekt wie GET; neue IBAN → zusätzlich `verify_required: true`, `code_sent_to` (Code an die Konto-E-Mail des Inhabers, 15 min). `POST …/confirm {code}` → Objekt wie GET (IBAN aktiv + Hinweis-Mail); Fehler 400 `tix_code_invalid` (`data.remaining`), 410 `tix_code_expired`, 429 `tix_code_locked`. `POST …/resend` → `{code_sent_to}`. Validierung 400 `tix_invalid_iban`, 400 `tix_invalid_field` mit `data.field` ∈ bic, country, tax, vat_id, tax_number, email; 429 `tix_rate_limited` (5 Codes/Stunde). | evendis | 1.38.343 |
| Abrechnung: Gebühren-Modus | `GET /organizer/fee-mode[?price=]` → `{mode (organizer\|split\|customer), default_mode, own_choice, choosable, modes, applies_to: "new_orders", fee {fixed, percent, max_per_ticket\|null, max_per_order\|null, label, split_customer_share, rounding, custom, gateway_included}, example, examples {organizer,split,customer}, gateway_note}`; je Beispiel `{price, fee, fee_customer, fee_organizer, customer_pays, payment_fee, you_receive_before_payment_fee, you_receive}` (Server rechnet inkl. Rundung/Höchstbeträgen; `you_receive` nach Plattform- und geschätzter Zahlungsgebühr). `POST /organizer/fee-mode {mode}` → wie GET; ungültig 400 `tix_invalid_field` (`field=mode`). Gespeichert als `_tix_fee_override=1` + `_tix_fee_mode` am Veranstalter. | evendis | 1.38.343 |
| Abrechnung: Saldo | `GET /organizer/balance` → `{currency, sold_total, pending_total, paid_out_total, open_balance, next_payout: null\|{amount, date, settlement_id\|null, status}, payout_details_complete, delay_days}`; `next_payout.status = pending` = noch nicht abgerechnet (Schätzung). | evendis | 1.38.343 |
| Abrechnung: Liste/Detail | `GET /organizer/settlements?page=&per_page=` → `{items, total, has_more}`; Element `{id, number ("EV-2026-0001"), type (event\|balance\|advance), event_id, event_title, event_date, status (draft\|ready\|approved\|paid\|held), payout_amount, payout_date (geplant bzw. ausgezahlt), paid_at, has_pdf, has_invoice, invoice_number}`. `GET /organizer/settlements/{id}` → Element + `{period_from, period_to, gross, refunds, platform_fee, gateway_fees, corrections (±), advances, carry_over, customer_fees, pos_total, tickets, orders, held_reason, tax_mode (agency\|reseller), note, lines [{label, amount (±), type}], iban_masked}`; fremde/unbekannte 404 `tix_not_found`. | evendis | 1.38.343 |
| Abrechnung: PDF | `GET /organizer/settlements/{id}/pdf[?doc=invoice]` → JSON `{filename, mime: "application/pdf", data (base64)}`; Abrechnung/Gutschrift/Abschlag bzw. Gebührenrechnung; 404 `tix_no_pdf` bei `draft` oder ohne Rechnung. | evendis | 1.38.343 |
| Abrechnung: Feed/Push | `TIX_Notifications::add_user_item` an Inhaber + Team-Mitglieder mit Rolle `tix_organizer`: `type: "settlement"`, Aktion `settlement:<id>` („Abrechnung für <Event> erstellt“, „<Betrag> wurde überwiesen“), Aktion `payout:details` („Abrechnung … erstellt“, solange Auszahlungsdaten fehlen). | evendis | 1.38.343 |
| Push: Android über FCM | `TIX_FCM` (Firebase Cloud Messaging HTTP v1). Alles, was `TIX_Notifications::send_push` zustellt (Broadcasts, Veranstalter-Nachrichten, Tages-Erinnerung, „Tickets liegen in der App“, Support, Abrechnung, alle `add_user_item`), geht an iPhones über APNs (unverändert) und an Android-Geräte über FCM. **Registrieren:** `POST /push/register {token, platform: "android", bundle?}` (Token A–Z a–z 0–9 `:` `_` `-`, 32–4096 Zeichen, sonst 400 `tix_bad_token`; iOS/ohne `platform` wie bisher Hex) → `{ok, platform, push_ready (für diese Plattform), apns_configured, fcm_configured}`; Geräte in `_tix_push_tokens` mit `platform` (ohne Angabe = iOS) und `bundle`. `POST /push/unregister {token}` für beide. `GET /notifications` + `apns_configured`, `fcm_configured`; `push_ready` und `pushed` (Broadcast) = einer der Wege eingerichtet. **Zugang:** Dienstkonto-JSON in der Option `_tix_fcm_service_account` (nicht autoload, nie ausgegeben), einzutragen unter Tixomat → App-Push (Datei oder Text) bzw. `wp tixomat push fcm-import <datei>`; Projekt-ID aus dem JSON; OAuth-Zugriffstoken per JWT (RS256, Scope `firebase.messaging`) bei `oauth2.googleapis.com`, gecacht bis 5 min vor Ablauf, bei 401 einmal neu. **Nachricht:** `notification {title, body}`, `data` wie bei APNs (`notif_id`, `event_id`, `action`, nur Zeichenketten), `android.priority: high`, `android.notification.channel_id: tixomat_default` (legt die App an), `sound: default`. Token verworfen bei 404/`NOT_FOUND`, `UNREGISTERED`, `INVALID_ARGUMENT`; andere Fehler bleiben (Status mit letztem Fehler, ohne Token). Google-Aufrufe über IPv4. WP-CLI: `wp tixomat push status \| fcm-import <datei> \| fcm-remove \| test --user=<id>`. Studio `has_app` erkennt auch FCM. | alle | 1.38.527 |
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
| Tischreservierung | `/public/events/{id}.tables` (bool). `GET /public/events/{id}/tables` → `{event {…, floor_plan}, categories [{index, name, desc, min_spend, min_guests, max_guests, price, quantity, available, tables [{name,x,y,reserved}]}], payment_mode (on_site\|deposit\|full), deposit_type, deposit_value, info_text}`. `POST /customer/table-reservations {event_id, category_index, table_name?, guest_count, customer_name, customer_email, customer_phone?, comments?, payment_method?, return_url?}` (Konto: Name/E-Mail aus dem Konto; `return_url` seit 1.38.385 wie bei `POST /customer/orders`: nur https und freigegebene Hosts (`tix_app_return_hosts` + Kunden-Adresse), sonst 400 `tix_return_url`; wird an der Anzahlungs-Bestellung gespeichert, nach der Zahlung Rücksprung mit `order_id`, `key`, `status`; Rate-Limit 10 Anfragen je Besucher-IP in 5 min → 429 `tix_rate_limit`) → `{status: confirmed}` oder `{status: payment_required, checkout_url, order_id}` (Zahlung im Browser; Bestätigung + Mail erst nach Zahlung, offene Zahlung hält den Tisch 60 min); Fehler 400 `tix_table`. Nur wenn Tischreservierung in den Einstellungen aktiv ist (sonst 404). | beide | 1.38.357, `return_url` 1.38.385 |
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

### Website als dritter Client (Next.js auf evendis.de, seit 1.38.374)

Alles nur hinzufügend; ohne Nutzung bleibt jede Antwort gleich (Apps, KitchenKlub, Mallorca, tixomat.de). Die Website ruft die Schnittstelle serverseitig über **`/wp-json/tixomat/v1/…`** auf (nicht `?rest_route=` – dort greift die Token-Anmeldung nicht, weil `REST_REQUEST` zu spät feststeht) und reicht `X-Forwarded-For` (Besucher-IP) und `User-Agent` weiter.

| Bereich | Vertrag | Seiten |
|---|---|---|
| Rücksprung nach der Zahlung | `POST /customer/orders` optional `return_url` (absolute https-URL, Host aus Site-Option `tix_app_return_hosts` = Liste, Standard leer; `localhost`/`*.localhost` nie, Port nur 443, ohne Benutzer/Passwort, ≤ 500 Zeichen, Fragment entfällt). Sonst **400 `tix_return_url`**; ohne Feld wie bisher. Gespeichert als Bestell-Meta `_tix_return_url`. Mollie-, Stripe- und PayPal-Rücksprung (`/?tix_payment_return=1…`), Stripe-Abbruch (`…&cancelled=1`) und PayPal-Abbruch (`/?tix_payment_cancel=1…`) leiten dann per 302 auf `return_url` mit angehängtem `order_id`, `key` (Order-Key) und `status` = `paid` \| `pending` \| `failed` \| `cancelled` (aus dem Bestellstatus; der Webhook kann nach dem Rücksprung noch eintreffen → bei `pending` `GET /customer/orders/{id}?key=` abfragen). Beispiel: `302 Location: https://evendis.de/kasse/danke/?order_id=812&key=tix_ab12…&status=paid`. Die freigegebenen Hosts sind zusätzlich für `wp_safe_redirect` erlaubt (`allowed_redirect_hosts`). Webhooks unverändert; WordPress-Danke-Seite nur ohne `return_url`. Gratis-Bestellungen: `payment_url` bleibt `''`, die Website zeigt die Bestätigung selbst. Vermittelte Bestellungen (geteilte Events, Zahlung beim Veranstalter) nutzen `return_url` noch nicht. **Proxy-Weiche:** `/?tix_payment_return`, `/?tix_payment_cancel` und `/?tix_thankyou` müssen an WordPress gehen. | evendis | 1.38.374 |
| Event per Slug | `GET /public/events/by-slug/{slug}` (`[a-z0-9-]+`, öffentlich) → genau die Antwort von `GET /public/events/{id}` (gleicher Cache, gleiche Freigabe-Regeln), sonst 404 `tix_event`. Früherer Slug (`_wp_old_slug`) findet das Event ebenfalls; dann zusätzlich `canonical_slug` (aktueller Slug, Website leitet per 301 um). Nur veröffentlichte Events. Veranstalter: `GET /public/organizers/{key}` nimmt schon `post_name` bzw. Landing-Slug. | beide | 1.38.374 |
| SEO-Felder | `GET /public/events` und `/public/events/{id}` je Event zusätzlich `seo: {title, description, image}` – Titel ohne HTML-Entities, Beschreibung aus Kurztext (sonst Beschreibung/Inhalt) ohne HTML, ≤ 160 Zeichen (an Wortgrenze mit „…“), `image` = großes Bild (wie `image`). Beispiel: `"seo":{"title":"Mamba & Co","description":"Die heißeste Nacht im Klub. …","image":"https://evendis.de/wp-content/uploads/…-1024x….jpg"}` | beide | 1.38.374 |
| Bestellliste | `GET /customer/orders?page=&per_page=` (Token Pflicht, sonst 401; `per_page` Standard 20, höchstens 50; neueste zuerst) → `{ok, total, page, per_page, has_more, orders: [{id, order_number, key, status, paid, total, currency, created, event {id, slug, title, date_start, url}\|null, ticket_count, invoice_url, seller}]}` (`event.slug` seit 1.38.394). Enthält eigene Bestellungen (`customer_id` bzw. bei Vermittlung `user_id`) und – nur wenn das Konto seine **aktuelle** E-Mail bestätigt hat (User-Meta `_tix_email_confirmed`, gesetzt bei jedem `POST /auth/code/confirm`, seit 1.38.386 auch Reset-Link, Magic Link und Bestand mit eigenem Kauf, siehe „E-Mail-Nachweis“) – frühere Gastbestellungen mit dieser E-Mail. Vermittelte Bestellungen geteilter Events stehen mit drin: `order_number` des Veranstalters, `invoice_url: ""`, `seller: {name, mail_from_seller: true}`; bei eigenen `seller: null`. Ohne Bestellungen `{"orders": [], "total": 0}`. Beispiel: `{"ok":true,"total":3,"page":1,"per_page":20,"has_more":false,"orders":[{"id":812,"order_number":"EV-1042","key":"tix_ab12…","status":"completed","paid":true,"total":40.0,"currency":"EUR","created":"2026-10-10 19:22:01","event":{"id":8,"title":"…","date_start":"2026-10-15","url":"https://evendis.de/events/…/"},"ticket_count":2,"invoice_url":"https://evendis.de/wp-admin/admin-post.php?action=tix_invoice_download&order_id=812&order_key=…","seller":null}]}` | beide | 1.38.374 |
| Rechnung | `invoice_url` (Rechnungs-PDF, über den Order-Key abgesichert, `TIX_Invoicing::get_download_url`; `''` solange keine Rechnung erstellt ist) zusätzlich in `GET /customer/orders/{id}` und in der Antwort von `POST /customer/orders`; bei vermittelten Bestellungen immer `''`. | beide | 1.38.374 |
| Wallet | Tickets (`/customer/tickets`, Tickets in Bestellungen) zusätzlich `wallet: {apple_url, google_url}` (signierte Links `TIX_Wallet::get_apple_pass_url`/`get_google_save_url`; `''` = diese Plattform ist nicht eingerichtet) oder `null`, wenn Wallet aus ist bzw. beim Spiegel-Ticket eines geteilten Events (Wallet beim Veranstalter). | beide | 1.38.374 |
| Zuordnung | `POST /customer/orders` (und `POST /customer/cart/quote`, dort ohne Wirkung) optional `attribution: {promoter_code?, utm_source?, utm_medium?, utm_campaign?, utm_content?, referrer_host?, fbp?, fbc?}`. Promoter-Code wie der Referral-Cookie (`TIX_Promoter_DB::get_promoter_by_code`, nur aktive) → `_tix_promoter_id` + `_tix_promoter_attribution = referral`; ein Promo-Code als Gutschein hat Vorrang; unbekannter Code wird still ignoriert. UTM → `_tix_campaign_source` (Kanal-Slug wie `campaign-pixel.js`, z. B. `instagram`, `google_organic`; `sanitize_key`, 50) / `_medium` / `_name` / `_content` (je 100), `referrer_host` → `_tix_campaign_referrer` (ohne `utm_source`: Quelle `referral` statt `direct`). Bestell-Meta `_tix_order_channel = web`; `_tix_order_source_{id}` bleibt `app` (kein neuer Wert für die Apps). **Meta Conversions-API:** `fbp`/`fbc` nur mit Einwilligung mitschicken → gespeichert mit Besucher-IP und Browser (`_tix_meta_fbp`, `_tix_meta_fbc`, `_tix_meta_client_ip`, `_tix_meta_client_ua`), der Kauf-Event nutzt sie. Mit `attribution`, aber ohne `fbp`/`fbc` → `_tix_meta_no_consent = 1`, **kein** Kauf-Event an Meta. Bestellungen ohne `attribution` (Apps) unverändert. | evendis | 1.38.374 |
| Statistik | `POST /public/track` (öffentlich, ohne Nonce; nur wenn „Kampagnen-Tracking“ aktiv ist, sonst gibt es die Route nicht) `{type: "pageview", visitor_id, session_id, page_path, page_title?, source?, campaign?, content?, referrer_host?, is_first_visit?, is_session_start?, event_id?}` → **204** ohne Inhalt. Gleiche Säuberung wie der Pixel (`TIX_Site_Analytics::record_pageview`: Pfad ohne Query/Fragment, ≤ 500; IDs ≤ 40; Gerät aus dem weitergereichten User-Agent). `event_id` zählt zusätzlich in der Kampagnen-Statistik des Events (`TIX_Campaign_Tracking::record_event_view`). Fehler: 400 `tix_track_invalid` (Pflichtfeld fehlt), 400 `tix_track_type`, 429 `tix_rate_limit` (200 je Besucher-IP und Minute). Erst nach Einwilligung aufrufen (Website). | evendis | 1.38.374 |
| Token-Prüfung | Intern: `authenticate_by_token` sucht über den Index `_tix_app_token_h` (SHA-256 je gültigem Token, mehrfach je Konto, folgt automatisch jeder Änderung von `_tix_app_tokens`/`_tix_app_token`). Altbestand: einmaliger Hintergrund-Job `tix_app_token_reindex` nach dem Update bzw. `wp tixomat app-tokens reindex` (`… status`); bis dahin die alte Suche, danach nur noch Legacy-Einzeltokens ohne Index. Antworten, Tokenformat und Lebensdauer unverändert. | beide | 1.38.374 |
| Rechtstexte | `GET /public/legal/{slug}` (öffentlich; `slug` = `impressum`, `datenschutz`, `agb`, `widerruf`, `zahlungsweisen`, sonst 404 `tix_legal`) → `{ok, slug, title, content, updated_at, configured}`. `content` = HTML wie der Shortcode `[tix_<slug>]` (`TIX_Legal::get_page`, Kurzform `##`/`**` umgewandelt, `wpautop`, `wp_kses_post`); `updated_at` = letztes Speichern unter Tixomat → Rechtstexte (ISO 8601 mit Zeitzone, `''` = nie gespeichert, gilt für alle Seiten gemeinsam); `configured: false` = noch kein eigener Text, `content` ist dann der eingebaute **Mustertext mit Platzhaltern** (Website/App sollten dann „wird eingerichtet“ zeigen statt den Text). 60 s Transient (`tix_pub_legal_<slug>`, beim Speichern geleert), Kopfzeile `Cache-Control: public, max-age=60`. Beispiel: `{"ok":true,"slug":"impressum","title":"Impressum","content":"<h2>Angaben gemäß § 5 TMG</h2>…","updated_at":"2026-10-11T12:30:00+02:00","configured":true}` | beide | 1.38.379 |
| Einwilligung (Cookies) | Seit 1.38.382 setzt Tixomat Tracking-Cookies und lokalen Speicher nur mit Einwilligung (`TIX_Consent::has('statistics'|'marketing')`, im Browser `window.tixConsent` aus `assets/js/consent.js`). Erkannt: Cookiebot (`CookieConsent`), CookieConsent v3 (`cc_cookie`, Kategorien auch deutsch: `statistik`/`analytics`, `marketing`), Borlabs (`borlabs-cookie`), die eingestellten Consent-Cookies von Meta Pixel/Google Tag (`meta_consent_cookie`/`google_consent_cookie`, vorhanden = alles), Ereignis `tix:consentGranted` (`detail.categories`), Filter `tix_has_consent`. **Marketing:** Promoter-Cookie `tix_promoter_ref` (`?ref=`). Ohne Einwilligung zählt die Zuordnung nur im selben Besuch – serverseitig an der Warenkorb-Sitzung (`tix_cart_session`, technisch notwendig; Transient `tix_pref_<hash>`, 2 h, nach der Bestellung verbraucht). Das Cookie `tix_visitor` (1 Jahr) entfällt; Klicks werden über einen täglich wechselnden Hash aus IP + Browser entdoppelt. **Statistik:** Kampagnen-Pixel (`tix_campaign`, `localStorage tix_visitor`, `tix_session`, `tix_visitor_fallback`, Seitenaufrufe) startet erst nach Einwilligung; ebenso das Landing-Zuordnungs-Cookie `tix_ol_src`. Unverändert (technisch notwendig bzw. vom Nutzer ausgelöst): Warenkorb, Logins, `tix_pending_coupon`, Gewinnspiel- und Popup-Merker. Hinweis evendis: Setzt die Next-Website ihr `cc_cookie` mit Cookie-Domain `.evendis.de`, gilt die Einwilligung auch auf veranstalter.evendis.de. Ohne Banner auf einer WordPress-Seite gibt es dort keine Einwilligung – Promoter-Zuordnung dann nur im selben Besuch, keine Kampagnen-Statistik. | beide | 1.38.382 |
| Einwilligungs-Banner | Seit 1.38.383, Site-Option **`tix_consent_banner`** (Vorgabe aus): `{enabled, cookie_domain ('' = Host, evendis: '.evendis.de'), key_statistics ('analytics'), key_marketing ('marketing'), revision (0; erhöhen = alle neu fragen), footer_link (an), title, text}`. Selbst gehostet (`assets/js/consent-banner.js`, `assets/css/consent-banner.css`), keine externen Skripte/Schriften. Kategorien notwendig (fest) / Statistik / Marketing, kein Vorab-Haken, „Nur notwendige“ und „Alle akzeptieren“ gleich groß, „Einstellungen“ mit Einzelwahl. Wieder öffnen: automatischer Footer-Link, `[tix_cookie_settings]`, `data-tix-consent-open`, `href="#tix-consent"`, `window.tixConsentBannerOpen()`. Speichert im **CookieConsent-v3-Format** (`cc_cookie`, URL-kodiertes JSON `{categories, revision, data, consentTimestamp, consentId, services, languageCode, lastConsentTimestamp, expirationTime}`, 182 Tage, SameSite=Lax, Secure bei https, optional Domain) – Kategorie-Schlüssel müssen denen der Next-Website entsprechen. Feuert `tix:consentGranted` (`detail.categories` = `statistics`/`marketing`), beim Seitenaufruf mit bestehender Einwilligung erneut; beim Entzug Aufräumen (lesbare Cookies/`localStorage`) + Neuladen, der Server löscht ohne Einwilligung `tix_promoter_ref`, `tix_campaign`, `tix_session`, `tix_visitor_fallback`, `tix_ol_src`, `tix_visitor` (`TIX_Consent::cleanup_cookies`). Akzentfarbe = `color_primary`, Schrift der Seite. Meta Pixel (`consent_required`) lädt nur bei Marketing, Google Tag (`consent_required`) prüft über `TIX_Consent` (Ads/GTM = Marketing, nur GA4 = Statistik). | alle | 1.38.383 |
| Nur Verwaltung | Seit 1.38.384, **seit 1.38.385 mit öffentlicher Infoseite**. Site-Option **`tix_admin_only`** `{enabled, public_pages}` (Vorgabe aus, wirkt nur mit Kunden-Adresse; `TIX_Admin_Only`; `root` entfällt). Feste Regel: Kunden landen nie auf der Verwaltung. **Durchgeleitete Anfragen** (siehe Kunden-Adresse) werden nie umgeleitet. **Öffentlich (direkt erreichbar, indexierbar):** die WordPress-Startseite (Infoseite für Veranstalter), Seiten aus `public_pages` (Liste aus Seiten-IDs oder Slugs), die Registrierungsseite (Shortcode `tix_register_event`) und die Vertragsseite; Filter **`tix_admin_only_public_pages`** (Array Seiten-IDs). Setzen: `wp option update tix_admin_only '{"enabled":true,"public_pages":["preise","faq"]}' --format=json`. **Außerdem erreichbar:** `/wp-json`, wp-admin inkl. admin-ajax/admin-post (Webhooks), Login/„Passwort vergessen“, AASA, `robots.txt`, Sitemaps, Anfragen mit `tix_*`-Parametern, Seiten mit Verwaltungs-Shortcodes (`tix_promoter_*`, `tix_sponsor_dashboard`, `tix_checkin`, `tix_pos`, `tix_musikwunsch_dj`, `tix_organizer_dashboard`), 404 bleibt 404; angemeldete Veranstalter/Team/Promoter/Bearbeiter sehen alles. `/` mit `?ref=`/`coupon`/`utm_source` → Kundenseite; angemeldete Kunden auf `/` → Konto auf der Kunden-Adresse. Alles Übrige (Kasse, Konto, Support, Events, Veranstalter, nicht gelistete Seiten, Archive, Feeds) → **301 auf die Kunden-Adresse**, gleicher Pfad (Veranstalter- und Landingpages → `/veranstalter/<slug>/`, Archive/Suche/Feeds → `/`, Rechtstext-Seiten → `/impressum/` usw.; Query bleibt). **Kein globales noindex mehr:** noindex nur für Verwaltung (wp-admin, Login), Anfragen mit `tix_*`-Parametern und durchgeleitete Kundenpfade (`X-Robots-Tag` + `wp_robots`); Vertragsplatzhalter bleibt noindex (TIX_Org_Approval). Sitemap nur mit den öffentlichen Seiten (ohne Vertragsplatzhalter; keine Events, Veranstalter, Beiträge, Kategorien, Autoren); `robots.txt` WordPress-Standard. Login nach Anmeldung: Admin → wp-admin, Veranstalter/Team → Veranstalter-Dashboard (falls aktiv), Promoter → Promoter-Seite, Kunde → Konto auf der Kunden-Adresse. Login `wp-login.php` im evendis-Look, **seit 1.38.388 nach `login-vorgabe.jpg`**: Desktop zweispaltig (Formular links, Foto-Karte rechts mit „Dein Event. Deine Gäste. Ein Konto.“ und Pillen), mobil nur Formular; Logo und Foto aus der Mediathek (Anhänge mit Meta `_ev_org_img` = `logo` bzw. `comedy`/`publikum`, sonst `admin_logo_url`), Logo-Link auf die Infoseite (`home_url('/')`), Titel „Willkommen zurück“, Knopf Nachtblau `#2C2F3F`, „Passwort vergessen?“ in der Merkzeile, „Jetzt Veranstalter werden“ (Seite mit `[tix_register_event]`), Fußzeile „← Zur Infoseite“, Impressum/Datenschutz der Kunden-Adresse (mobil am unteren Rand); Manrope lokal; seit 1.38.391 Farben fest evendis (nicht `color_primary`), Merkzeile 18 px über dem Knopf, Checkbox 20 px, Knopf 15,5 px, Überschrift + Unterzeile auch bei „Passwort vergessen?“ und „Neues Passwort“ (statt WordPress-Hinweisbox). **SEO der öffentlichen Seiten (seit 1.38.388, nur ohne SEO-Plugin):** `<title>` = Seitentitel (Startseite pur, Unterseiten „Titel – Seitenname“), `meta description` = Auszug (≤ 160 Zeichen), Open Graph (`og:title/description/url/image/site_name/locale`, Bild = Beitragsbild oder Mediathek `konzert`), Twitter-Card, Canonical (WordPress `rel_canonical`), JSON-LD `FAQPage` aus dem Breakdance-Element „FrequentlyAskedQuestions“ der Seite. **Google Fonts (seit 1.38.388):** auf allen Seiten der Verwaltung und der Durchleitung werden `<link>` auf fonts.googleapis.com durch `assets/css/fonts-local.css` ersetzt (Outfit, Inter, Sora, DM Sans lokal), Preconnects und `@import` entfernt. Nebenfix: eigene Login-Seite (`login_slug`) prüft `redirect_to` (vorher offene Weiterleitung). | evendis | 1.38.384, Infoseite 1.38.385 |
| Themen-Sammlungen | Seit 1.38.395 (`TIX_Collections`), nur hinzufügend: Taxonomie **`tix_collection`** am Event (Häkchen, mehrfach), gepflegt unter Tixomat → Sammlungen (Name, Slug, Einleitung HTML, Bild, optional sichtbar von/bis); pflegen **und zuordnen** nur mit `manage_categories` (Plattform-Admins, Veranstalter sehen das Feld nicht). Keine WordPress-Archivseiten, nicht Teil der Event-Verteilung an Partner. `GET /public/events` (Liste und Detail) je Event `collections` = `[slug]`, Filter **`?collection=<slug>`**. `GET /public/collections` → `{ok, collections:[{slug, name, intro, image{url, alt, width, height}\|null, upcoming, starts_at, ends_at}]}` – nur heute sichtbare Sammlungen mit kommenden Events; `starts_at`/`ends_at` = Sichtbarkeit (Y-m-d) oder `null`; zeitlich begrenzte zuerst. Beispiel: `{"slug":"halloween","name":"Halloween","intro":"<p>…</p>","image":{"url":"https://…/halloween-1024x683.jpg","alt":"Kürbis","width":1024,"height":683},"upcoming":12,"starts_at":"2026-10-01","ends_at":"2026-11-01"}` | evendis (Code überall) | 1.38.395 |
| Gewinnspiele in der Liste | Seit 1.38.395: `GET /public/events` (Liste) je Event `raffle` = `{title, status: open\|closed\|drawn, end_date}` oder `null` (Detail weiterhin volle Form mit `description`, `prizes`, `winners` …); Filter **`?raffle=1`** = nur `status: open`. Kombinierbar mit allen anderen Filtern. | beide | 1.38.395 |
| evendis-Gebühr | Seit 1.38.396: Gebühr inkl. Zahlung (`gateway_fee_included`) und eigene Höhe je Veranstalter (`_tix_fee_custom`, nur Admin). `GET /organizer/fee-mode` → `fee.custom` (true = eigene Höhe des Veranstalters), `fee.gateway_included` (true = Zahlungskosten in der Gebühr enthalten; dann `payment_fee: 0` in den Beispielen und `gateway_note` „… sind in der Gebühr enthalten – es wird nichts zusätzlich abgezogen.“). Beispiel: `{"mode":"split","fee":{"fixed":0.59,"percent":2.5,"custom":false,"gateway_included":true,…},"example":{"price":20,"fee":1.09,"fee_customer":0.55,"fee_organizer":0.54,"payment_fee":0,…}}`. Rein hinzufügend, keine Feld-Umbenennung. Nebenbei: Speichern der Einstellungen ohne Warnung, wenn ein Auswahlfeld fehlt (vorher „headers already sent“ bei `display_errors`). | evendis (Code überall) | 1.38.396 |
| Vorgabe „Gäste zahlen“ für neue Veranstalter | Seit 1.38.397, nur Mehr-Veranstalter-Modus: Einstellung `fee_mode_new_org` (Gebühren → „Vorgabe für neue Veranstalter“, Vorgabe `customer`; `''` = zentraler Modus). Beim Anlegen eines `tix_organizer` (Registrierung, Admin inkl. Entwurf, Partner-Kopplung, REST) setzt der Hook `wp_insert_post` → `TIX_Fees::default_mode_for_new_organizer` `_tix_fee_override=1`, `_tix_fee_mode` und die Markierung `_tix_fee_mode_default=1`; ein beim Anlegen ausdrücklich gesetzter Modus hat Vorrang, bestehende Veranstalter bleiben unverändert. `GET /organizer/fee-mode` → `own_choice: false`, solange die Vorgabe gilt; eigene Wahl (App/Web) oder Änderung durch den Admin löscht die Markierung. | evendis | 1.38.397 |
| Support-Thema „Beratung“ | Seit 1.38.397, nur Mehr-Veranstalter-Modus: `TIX_Support::get_categories()` ergänzt `{slug: "beratung", label: "Beratung für Veranstalter", audience: "organizer"}`, wenn die Liste es noch nicht hat (Filter `tix_support_categories`). `GET /support/config` liefert `audience` nur bei diesem Thema (rein hinzufügend); `POST /customer/support` nimmt `category: "beratung"` an (auch Gäste). Das Kontaktformular der Veranstalter-Landingpage zeigt es nicht (`get_organizer_contact_categories()`). Ziel von „Beratung anfragen“ auf veranstalter.evendis.de → evendis.de/support/?topic=beratung (Vorauswahl baut die Website). | evendis | 1.38.397 |
| Lizenz-Server (Selbstbetrieb) | Seit 1.38.398 (`TIX_License_Server`), nur mit Option `tix_license_server=1` (veranstalter.evendis.de): Tabellen `{prefix}tix_licenses` (Schlüssel `TIX-XXXX-XXXX-XXXX-XXXX`, Kunde, E-Mail, `max_sites` Vorgabe 1, `status` active\|suspended\|revoked, `valid_until`) + `{prefix}tix_license_sites` (Host ohne www., Version, WP/PHP, letzter Download; Test-/Staging-Hosts `dev=1` zählen nicht). Admin Tixomat → **Lizenzen** (anlegen, verlängern +1 Jahr, pausieren/beenden, Website lösen). REST öffentlich (Rate-Limit): `POST /license/check {key, site, version, wp, php}` → `{ok, valid, status: active\|invalid\|expired\|suspended\|revoked\|site_limit\|bad_site, expires_at, latest{version, released_at, requires_php, requires_wp}, package_url\|null, message}`; erste gültige Prüfung bindet die Website. `GET /license/package?l&h&v&e&sig` (HMAC, 12 h) → ZIP der dort installierten Version (Whitelist `tixomat.php`, `includes/`, `assets/`, `templates/`, keine versteckten Dateien) + `self-hosted.flag`, einmal je Version gebaut im privaten Ordner (`…/license/`, Sperrdatei). `GET /license/releases` → `{ok, latest, releases:[{version, released_at}]}` (Option `tix_release_dates`, Datum = erster Lauf der Version auf dem Server). | evendis | 1.38.398 |
| Lizenz + Updates beim Kunden | Seit 1.38.398 (`TIX_License`): aktiv nur im Selbstbetrieb = `self-hosted.flag` im Plugin-Ordner (nur im Paket vom Lizenz-Server) oder eingetragener Schlüssel; nie auf dem Lizenz-Server. Von uns betriebene Seiten (Deploy per rsync, ohne die Datei) zeigen nichts. Admin Tixomat → **Lizenz** (Schlüssel, Status, Ablauf, neueste Version). Plugin-Header **`Update URI: https://veranstalter.evendis.de/tixomat`** (alle Seiten: keine Abfrage bei WordPress.org) → Filter `update_plugins_veranstalter.evendis.de` liefert das Update nur mit gültiger Lizenz. Prüfung täglich (Cron `tix_license_check`), beim Speichern und vor einem Update-Angebot, wenn älter als 6 h; Option `tix_license_status`. Server nicht erreichbar → letzter Stand gilt 14 Tage weiter. **Ohne gültige Lizenz bricht nichts ab**: nur Hinweis (Dashboard, Plugins, Tixomat-Seiten), keine Updates. Server-Adresse überschreibbar per Konstante `TIX_LICENSE_SERVER`. | Selbstbetrieb | 1.38.398 |
| Mindest-Version für Apps | Seit 1.38.398 (`TIX_App_Compat`, alle Seiten, nur hinzufügend): App schickt optional `X-Tix-App-Min-Plugin` (z. B. `1.39.0`), `X-Tix-App-Min-Since` (Y-m-d, Erscheinen dieser Version, z. B. aus `/license/releases`) und `X-Tix-App-Id` (Bundle-ID). Jede `tixomat/v1`-Antwort trägt `X-Tix-Plugin-Version`; mit Anforderung zusätzlich `X-Tix-Compat: ok\|grace\|outdated` und `X-Tix-Compat-Deadline`. Frist **30 Tage** ab `Min-Since` (sonst ab erstem Kontakt). `GET /info` → `compat {plugin_version, grace_days: 30, status, required, deadline, apps:[{app_id, required, status, deadline}]}`. Nichts wird gesperrt: bei `outdated` zeigt die App einen Update-Hinweis. Admin-Hinweis in WordPress (Frist läuft / abgelaufen), gemerkt in Option `tix_app_requirements` (max. 10 Apps, höchstens 1 Schreibzugriff je App und Tag). | alle | 1.38.398 |
| Preis-Wächter | Seit 1.38.399 (`TIX_Price_Watch`), nur Mehr-Veranstalter-Modus: `GET /organizer/price-watch[?months=1..24, Vorgabe 12]` (Rechte wie `/organizer/fee-mode`) → `{ok, enabled, fee{percent, fixed, source: current\|custom\|reference}, shop_monthly, months:[{month: "Y-m", orders, tickets, paid_tickets, revenue, fee, shop, savings}], total{months, orders, tickets, paid_tickets, revenue, fee, shop, savings}, avg_ticket_price, break_even_tickets_month, recommend_shop, message}`. Grundlage: eigene Positionen (Events mit `_tix_organizer_id`) in bezahlten nativen Bestellungen; Freitickets ohne Gebühr; Gebühr = Satz × Umsatz + Fix × bezahlte Tickets (ohne Höchstbeträge). Satz = Gebühr des Veranstalters, solange sie 0 ist der Rechensatz `price_watch_ref_percent`/`price_watch_ref_fixed` (2,5 / 0,59). Einstellungen (Gebühren, nur Mehr-Veranstalter-Modus): `price_watch_enabled` (Vorgabe 0 → App/Web zeigen nichts), `price_watch_shop_monthly` (79). | evendis | 1.38.399 |
| Module je Plattform/Lizenz | Seit 1.38.400 (`TIX_Modules`, alle Seiten, Grundlage für Modul-Abos): Module `loyalty` (Prämien), `music` (Musikwunsch + DJ), `giftcards` (Gutscheine), `muttizettel`, `seating` (Saalplan/Tische), `tips` (Tipps/Inhalte). Freischaltung: im Selbstbetrieb aus der Lizenz (Lizenz-Server Spalte `modules`, NULL = alle; Antwort `/license/check` → `modules`; unbekannter Schlüssel ändert die letzte Freischaltung nicht, abgelaufene Lizenz behält ihre Module), sonst Option `tix_modules_entitled` per **`wp tixomat modules [list\|set a,b\|all]`**; nichts gesetzt = alle frei (Bestand unverändert). Gesperrt: REST-Routen des Moduls → 403 `tix_module_disabled` (`data.module`), `TIX_Loyalty::enabled()`/`TIX_Music::enabled()` → false, Gutschein-Shortcodes leer, Veranstalter-Module (`TIX_Public_Platform::modules`) begrenzt. `GET /info` → `modules {key: {label, allowed}}`, `modules_source` all\|platform\|license. Muttizettel hat noch keine eigene Route (nur Schalter). | alle | 1.38.400 |
| CSV-Exporte für Veranstalter | Seit 1.38.401 (`TIX_Export`, alle Seiten; Leitlinie „Kein Einsperren“): **App** `GET /organizer/export?type=guests\|tickets\|combined\|orders\|customers[&event_id][&from=Y-m-d][&to=Y-m-d][&status=paid\|all]` (Rechte `check_organizer`, Events über `can_access_event`, im Mehr-Veranstalter-Modus Team-Rechte `view_guestlist`/`view_orders`) → `{ok, filename, mime: "text/csv", rows, data (Base64)}`; `guests`/`tickets`/`combined` brauchen `event_id` (400 `tix_invalid_field`), fremdes Event 403. Spalten Bestellungen: Bestellnr, Datum, Status, Vorname, Nachname, E-Mail, Telefon, Adresse, PLZ, Ort, Event, Positionen, Tickets, Betrag (eigene Positionen), Gesamtbetrag, Zahlart; Kunden: Name, E-Mail, Telefon, Bestellungen, Tickets, Umsatz (eigene Positionen, bezahlt), erste/letzte Bestellung. **Web** Bestellungen → „Als CSV exportieren“ (Zeitraum, nur bezahlte oder alle inkl. storniert/erstattet; admin-post `tix_organizer_orders_csv`). Alle CSV: UTF-8 mit BOM, Semikolon – auch die Kunden-CSV (vorher Komma ohne BOM). | alle | 1.38.401 |
| Gebühr im evendis-Kanal + Monatsrechnung | Seit 1.38.402 (`TIX_Channel_Fee`, `TIX_Channel_Invoice`; User-Entscheid „A: Monatsrechnung“): **Plattform** – je Partner im Verzeichnis `channel_fee` (an/aus; Bestand ohne Feld = aus, z. B. KitchenKlub/Mallorca), optional `channel_fee_percent`/`channel_fee_fixed`; Vorgaben in Option `tix_channel_fee_settings` (percent 2,5, fixed 0,59, label, `new_partners` = neu gekoppelte Partner an, `due_days` 14, `payment` sepa\|transfer), Admin Tixomat → **Kanal-Gebühren**. Die Vermittlung schickt bei `/partner/quote` und `/partner/orders` `channel_fee {percent, fixed, label}`. **Quelle** – Einstellung `channel_fee_mode` (customer\|split\|organizer, Vorgabe customer, Event-Verteilung → „Gebühr der Plattform“); während der Partner-Anfrage ersetzt die Kanal-Gebühr die eigene Gebühr der Quelle (`TIX_Fees::get_fee_config`) → erscheint im Partner-Angebot (`totals.fee`); je Bestellung Option `_tix_order_channel_fee_{id}` {partner, percent, fixed, mode, fee, fee_customer, fee_organizer}; Status/Webhooks tragen `channel_fee` (null = keine). Eigener Shop der Quelle unverändert. **Spiegel** `tix_partner_orders` (DB 2): `channel_fee`, `paid_at`, `channel_invoice_id`, `channel_credit_id`. **Monatsrechnung** Tabelle `{prefix}tix_channel_invoices` (Nummer `KG-JJJJ-NNNN`, Status open\|sent\|paid\|void): Cron `tix_channel_invoice_cron` täglich → am Monatsanfang je Partner für den Vormonat; Belastung = bezahlte, noch nicht berechnete Vermittlungen (`paid_at` bis Monatsende), Gutschrift = berechnete, inzwischen stornierte/erstattete; nur Gutschriften → negative Rechnung (Gutschrift). PDF (`TIX_Settlement_PDF::channel_invoice`, brutto mit `settlement_fee_vat_rate`, Empfänger = Abrechnungsdaten des zugeordneten Veranstalters), Versand an dessen Rechnungs-E-Mail, „Bezahlt“, „Stornieren“ (Positionen werden wieder frei), „Jetzt abrechnen“. SEPA-Lastschrift: Hinweis auf der Rechnung, Einzug außerhalb des Plugins. | evendis + Quellen | 1.38.402 |
| Module je Veranstalter (Paket 3a) | Seit 1.38.403, nur hinzufügend: **Musikwunsch je Event** – `GET /music/status?event_id=` gleiches Format, aber für dieses Event (`enabled` = Seite erlaubt Musikwünsche und im Mehr-Veranstalter-Modus Modul `music` des Veranstalters an; `open` = Zeitfenster dieses Events; `next` = dieses Event, solange es noch nicht offen ist); `POST /music/requests {event_id}` prüft Modul + Zeitfenster (404 `tix_music_event`/`tix_music_off`, 409 `tix_music_closed`), Limits je Event und Gerät; ohne `event_id` unverändert. **Gutscheine je Veranstalter** – `GET /giftcards?organizer=ID` (Mehr-Veranstalter-Modus, Modul `giftcards`, Veranstalter freigegeben) → Format wie bisher plus `organizer_id`; eigenes verstecktes Gutschein-Event je Veranstalter (`_tix_giftcard_organizer`, `_tix_organizer_id`, wird bei Bedarf angelegt und an die Beträge angeglichen); Einstellungen über `GET/POST /organizer/profile` → `giftcards {amounts[], free_amount, validity_years}` (Meta `_tix_giftcard_settings`, Rückfall: Einstellungen der Seite); Codes tragen `organizer_id` und gelten nur bei Events dieses Veranstalters (Kasse: „Gilt nur bei <Name>.“, Tür-Einlösung nur durch dessen Personal/Admin). Verkaufte Gutscheine werden nicht als Event abgerechnet; der Veranstalter bekommt den Wert beim Einlösen über die Abrechnung des jeweiligen Events. **`GET /loyalty/cards`** (Login) → `{ok, cards:[{organizer_id\|null, organizer_name, organizer_image, points, stamps_per_visit, rewards, last_activity}]}` – alle Programme mit aktivem Stand, Karten mit Punkten zuerst. Nebenbei: E-Mail-Vorlage ohne Warnungen, wenn die Einstellungen noch nie gespeichert wurden. | evendis (Code überall) | 1.38.403 |
| Veranstalter-Dashboard (wp-admin): Hinweis + Preis-Wächter | Seit 1.38.404 (Gegenstück zur App-Runde 41): „Auszahlungen“ zeigt bei `own_choice: false` und Modus `customer` „Voreinstellung: Gäste zahlen die Gebühr. Du kannst es jederzeit ändern.“; das Dashboard (`page=tixomat`) zeigt für Veranstalter mit Abrechnungs-Recht die Karte „Preis-Wächter“ (`TIX_Price_Watch::render_card`, nur bei `price_watch_enabled`, Mehr-Veranstalter-Modus): fertiger Satz, Summe der letzten 12 Monate, Schwelle, aufklappbare Monate, Link „Mehr zum eigenen Shop“ (veranstalter.evendis.de/eigener-shop/). | evendis | 1.38.404 |
| Awareness Stufe 1 (Angaben, Etikett, Filter) | Seit 1.38.405 (`TIX_Awareness`, alle Seiten, kostenlos; Konzept `tixomat-apps/docs/konzept-awareness.md`): Felder `{team, sign, point, codeword, areas[], drink_tests, taxi, rules, alarm_enabled}`. Quellen: Event (`_tix_awareness_mode` custom → `_tix_awareness`; off → keine) › Veranstalter-Vorgabe (`_tix_awareness` am tix_organizer mit `enabled`, Metabox „Awareness (Vorgabe für deine Events)“ im Mehr-Veranstalter-Modus bzw. `GET/POST /organizer/profile` → `awareness`) › Seite (Option `tix_awareness`, Tixomat → **Awareness**, Vorgabe AUS – KitchenKlub). Event-Metabox „Awareness“: Vorgabe übernehmen / eigene Angaben / keine. Ausgabe nur mit Inhalt: `GET /public/events/{id}` → `awareness` (Objekt ohne `enabled`) \| null + `awareness_team`; Liste → `awareness_team` (bool); Filter **`?awareness=1`** (nur Events mit Team). Event-Verteilung schickt die wirksamen Angaben als `awareness` (null = keine), der Empfänger speichert sie am Event (custom bzw. off); ältere Quellen ohne Feld: Event-Metas wie bisher. | alle | 1.38.405 |
| Awareness Stufen 2–3 (Melden, Feedback, Hilfe vor Ort) | Seit 1.38.406 (`TIX_Awareness_Live`): Schalter unter Tixomat → Awareness (Option `tix_awareness_flags`, **beide Vorgabe AUS**): `report_enabled` (Stufe 2) und `alarm_live` (Stufe 3, erst nach der Rechtsprüfung). **Melden:** Support-Thema `awareness` (`audience: organizer_event`, `anonymous: true`) in `/support/config`; `POST /customer/support {category: awareness, event_id, message, anonymous?, email?}` legt eine Anfrage ohne Pflicht-E-Mail an (`_tix_sp_event_id`, `_tix_sp_organizer_id`, Priorität hoch) → `{ok, anonymous, notice, request{id}}`; Hinweis-Mail ohne Inhalt an Support/Veranstalter; `GET /awareness/reports?event_id=` (Inhaber/Admin). **Feedback:** `POST /awareness/feedback {event_id, answer: yes\|partly\|no, note?, device}` ab Beginn bis 14 Tage nach Ende, eine Antwort je Gerät und Event (409 `tix_awareness_feedback_done`), „no“ → `follow_up {report, hotline: "116 016", chat_url}`; `GET /awareness/feedback?event_id=` (Team) → `{total, hidden, counts\|null}` – Summe erst ab 5. **Hilfe vor Ort:** `GET /awareness/status?event_id=` → `{active, on_duty}` (aktiv nur mit Schalter, Team + `alarm_enabled` und laufendem Event, Fenster wie Musikwunsch); `POST /awareness/alerts {event_id, area, text?, sign?, anonymous, device}` → `{alert{id, key, status, status_label, taken}, point}` (409 `tix_awareness_unavailable` mit `point`, 429 `tix_awareness_limit` ab dem 4. Hinweis je Gerät und Event); `GET /awareness/alerts/{id}?key=` (Gast, ohne Inhalt); Team: `GET /awareness/alerts?event_id=`, `POST /awareness/alerts/{id}/status {taken\|done\|no_action}`, `POST /awareness/duty {event_id, on}` (endet mit Fenster-Ende); `GET /awareness/report?event_id=` (Inhaber/Admin: je Bereich, Median Reaktionszeit, Feedback). Rolle **`tix_awareness`** („Awareness (App)“, `tix_app_awareness`, `/team` Schlüssel `awareness`, Login → Profil); Abschottung je Veranstalter. Push an alle im Dienst ohne Inhalt. Tabellen `{prefix}tix_awareness_alerts`, `{prefix}tix_awareness_feedback` (Gerät nur als HMAC). Cron `tix_awareness_cleanup` täglich: Hinweise und Feedback-Einzelantworten 30 Tage nach dem Event (Summe bleibt in `_tix_awareness_feedback_sum`), Awareness-Meldungen nach 180 Tagen. | alle | 1.38.406 |
| Awareness: Signal + App-Editor | Seit 1.38.407, nur hinzufügend: **maßgeblich für Apps/Web** `GET /info` → `awareness {report_enabled, alarm_live}` (Schalter der Seite; Melden-Links/Feedback-Karte nur bei `report_enabled`, Hilfe-Knopf zusätzlich über `/awareness/status`); `/info.modules.awareness = {label, allowed: true}` (immer erlaubt, nicht über Freischaltungen sperrbar); `/awareness/status` zusätzlich `report_enabled`; `POST /awareness/alerts` zusätzlich `id`, `key`, `status` oben; `GET /awareness/alerts/{id}` zusätzlich `status`, `status_label` oben. App-Event-Editor: `GET /events/{id}/edit` → `awareness_mode` (inherit\|custom\|off), `awareness` (eigene Angaben), `awareness_effective` (was Gäste sehen \| null); `POST /events/{id}` nimmt `awareness_mode` und `awareness` (Teil-Update) an, ungültiger Modus → 400 `tix_invalid_field` (`field: awareness_mode`). | alle | 1.38.407 |
| Awareness: Art des Hinweises + Test-Modus | Seit 1.38.439 (`TIX_Awareness_Live`, `TIX_Awareness_Test`). **`POST /awareness/alerts`** nimmt `category`: `harassment` (Belästigung), `sexual_harassment` (Sexuelle Belästigung), `threat` (Bedrohung), `health` (Gesundheit), `other` (Sonstiges); unbekannt oder fehlend → `other` (alte Apps). Spalte `category` (DB-Version 2, Upgrade per dbDelta). Team-Liste `GET /awareness/alerts?event_id=` → je Hinweis zusätzlich `category`, `category_label`, `priority` (true bei `sexual_harassment`/`health`); Reihenfolge: offen zuerst, darin Priorität zuerst, dann neueste. Push/Mitteilung an das Team im Dienst: Titel „Neuer Hinweis: <Art>“ (Priorität: „Dringend – Neuer Hinweis: <Art>“; `other`: wie bisher „Neuer Hinweis für das Awareness-Team“) – weiter ohne Bereich, Text oder Namen. `GET /awareness/report` zusätzlich `by_category`. Admin (Tixomat → Awareness): Tabelle der letzten 100 Hinweise (Zeit, Event, Art, Bereich, Text, Status, Reaktion) und CSV-Export `admin-post.php?action=tix_awareness_alerts_csv[&event_id=]` (Nonce, Semikolon, UTF-8 mit BOM). **Test-Modus:** unsichtbares Test-Event (Status `private`, Meta `_tix_awareness_test`, Awareness custom mit Team + Hilfe-Knopf, läuft ab jetzt) – nicht in `/public/events`, nicht auf der Website (404), nicht verteilt. Nur **Tester** (Admins + User-Meta `_tix_awareness_tester`) bekommen es in `/app/sos` (vor echten Events, `event.test: true`), `/awareness/status`, `/awareness/alerts`, `/awareness/duty`, `/awareness/events` (`test: true`); für alle anderen verhält es sich wie nicht vorhanden. „Hilfe vor Ort“ braucht weiter `alarm_live`. WP-CLI: `wp tix awareness test-event [--minutes=60] [--testers=login,mail,ID] [--organizer=<id>]`, `wp tix awareness test-event --cleanup` (Test-Events, ihre Hinweise/Feedback, Dienste und alle Tester-Freigaben), `wp tix awareness tester <konto> [--off]`. Admin-Knöpfe unter Tixomat → Awareness → „Test-Modus“. Der tägliche Cron entfernt Test-Events 24 h nach Ende. | alle | 1.38.439 |
| Awareness: Vorname, Angaben per REST, Erkennungszeichen | Seit 1.38.440. **Vorname:** `POST /awareness/alerts` mit `anonymous: false` von einem angemeldeten Gast speichert nur den Vornamen (Profil-Vorname, sonst erstes Wort des Anzeigenamens; nie Login, E-Mail oder Nachname). Team-Liste `first_name` nur bei nicht-anonymen Hinweisen (sonst `""`); ohne Anmeldung bzw. ohne `anonymous` bleibt der Hinweis anonym. Konto-Verknüpfung (`user_id`) nur für die Statusmeldung an den Gast, nie in der Antwort. Admin-Tabelle und CSV zeigen den Vornamen (Spalte „Vorname“, anonym leer). Eigene Gast-Antwort (`POST /awareness/alerts`, `GET /awareness/alerts/{id}`) zusätzlich `alert.category`. **`GET/POST /awareness/settings`** (Admin, Veranstalter, Mitarbeiter, Awareness-Team; Mehr-Veranstalter-Abschottung über `TIX_App_Scope`): ohne Parameter die Vorgabe des eigenen Veranstalters (Mehr-Veranstalter) bzw. der Seite (Ein-Veranstalter; Admins im Mehr-Veranstalter-Modus `?organizer=<id>`, sonst Seite); `?event_id=` das Event. Antwort `{ok, scope: site\|organizer\|event, organizer_id?, event_id?, mode? (inherit\|custom\|off), source? (organizer\|site\|none), settings{enabled (nicht bei event), team, sign, point, codeword, areas[], drink_tests, taxi, rules, alarm_enabled}, effective? (was Gäste sehen), default_areas[], limits{areas:12, sign:160}, alarm_live}`. POST nimmt die Felder oben oder unter `settings{}` (nur übergebene ändern sich) und beim Event `mode`; eigene Felder ohne `mode` an einem Event mit Vorgabe schalten auf `custom`. Mehr als 12 Orte → 400 `tix_invalid_field` `areas`; `sign` über 160 Zeichen → 400 `field: sign`; falscher Modus → 400. Ändern: Vorgaben nur Admin bzw. Inhaber/Team-Admin (Mehr-Veranstalter) bzw. Admin/Mitarbeiter (Seite); Events Admin, Veranstalter, Mitarbeiter mit Zugriff aufs Event; das Awareness-Team liest nur. **`sign`** überall bis 160 Zeichen (vorher 120). | alle | 1.38.440 **Meldungen über `/customer/support` (category awareness, nicht anonym, 1.38.524):** Ticket und erste Nachricht tragen nur den Vornamen (Konto: `first_name_of`, Gast: erstes Wort des Namens; nie eine E-Mail), die E-Mail bleibt intern in `_tix_sp_email` für Antworten. Team-Ansichten zeigen sie nicht: `/awareness/reports` (+ `first_name`, `has_contact`), `/support/inbox/{id}` und Nachrichten (`email` leer), Ticket-Format der Verwaltung (`email` leer, `has_contact`), Weiterleitung an Partner. Anonym unverändert. |
| Awareness: Team-Events + Mitteilungs-Ziele | Seit 1.38.408, nur hinzufügend: `GET /awareness/events` (Rolle `tix_awareness`, Personal, Admin; im Mehr-Veranstalter-Modus nur eigener Veranstalter) → `{ok, events:[{id, title, starts_at, ends_at, opens_at, closes_at, running, alarm_enabled, point, on_duty}]}` – Events mit Awareness-Team, laufend bzw. in den nächsten 30 Tagen; `alarm_enabled` nur mit Schalter „Hilfe vor Ort“. Mitteilungen: `action` = `awareness:alert:<id>` (Team: neuer Hinweis, Gast mit Konto: Neuigkeit), `event_id` im Push. | alle | 1.38.408 |
| Muttizettel je Veranstalter | Seit 1.38.409 (`TIX_Muttizettel`, nur Mehr-Veranstalter-Modus): Modul `muttizettel` in `_tix_org_modules` (wie bisher über `POST /organizer/profile` → `modules`, neu auch WP-Admin-Metabox „Module (Angebot auf deiner Seite)“ am Veranstalter). Am Event optional `_tix_muttizettel_note` (Metabox „Muttizettel“, App-Editor `GET /events/{id}/edit` / `POST /events/{id}` → `muttizettel_note`, max. 300 Zeichen). Ausgabe: `/public/events` (Liste + Detail) → `modules` des Veranstalters (bestand schon) und neu `muttizettel_note` (nur bei eingeschaltetem Modul, sonst ''); `/public/organizers/{id}` → `modules.muttizettel`. Modul-Freischaltung der Plattform/Lizenz (`TIX_Modules`) wirkt weiter. Das PDF entsteht lokal, der Server speichert keine Muttizettel-Daten. Ein-Veranstalter-Betrieb: keine neuen Felder. | evendis | 1.38.409 |
| Event melden, Barrierefreiheit, Erstattungsregel | Seit 1.38.410, nur Mehr-Veranstalter-Modus (evendis): **Event melden** (DSA Art. 16) – Support-Thema `{slug: event_report, label: "Event melden", audience: platform}` in `/support/config` (nicht im Veranstalter-Kontaktformular); `POST /customer/support {category: event_report, event_id, reason?, good_faith?, name, email, subject, message}` auch ohne Konto → Anfrage ans Plattform-Team (Priorität hoch, `_tix_sp_event_id`, `_tix_sp_reason`, `_tix_sp_good_faith`). **Barrierefreiheit** (`TIX_Event_Facts`) – Schlüssel `step_free, wheelchair_wc, seats, hearing_loop, companion, parking, quiet_room, sign_language` (Texte vom Plugin) + Hinweis; am Ort (`tix_location`, Meta `_tix_accessibility`) pflegbar, je Event `_tix_accessibility_mode` inherit\|custom\|off; Event-Detail `accessibility {items:[{key,label}], note}` \| null. **Erstattungsregel** – `_tix_refund_policy {type: no_refund\|until_days\|transfer\|custom, days, note}`; Event-Detail `refund_policy {type, label, note, days, legal_review_pending: true}` \| null; der Text endet immer mit „Wird das Event abgesagt, bekommst du den Ticketpreis erstattet.“ (Formulierungen: Rechtsprüfung offen). Pflege: Metaboxen an Ort und Event, App-Editor (`accessibility_mode`, `accessibility`, `refund_policy`, dazu `accessibility_keys`, `refund_types`, `accessibility_effective`; ungültig → 400 mit `field`). Nebenbei: `tix_btn_style()` ohne Warnung bei nie gespeicherten Einstellungen. | evendis | 1.38.410 |
| Wochenmail „Was geht am Wochenende?“ | Seit 1.38.411 (`TIX_Weekly_Mail`, nur Mehr-Veranstalter-Modus): Schalter Tixomat → **Wochenmail** (Option `tix_weekly_mail {enabled, day (ISO, 4 = Do), hour}`, **Vorgabe AUS**). `GET /info` → `newsletter {enabled}`. `POST /public/newsletter {email, city?, consent: true}` → immer `{ok, message}` (kein Hinweis, ob die Adresse bekannt ist; 400 `invalid_email`/`tix_consent_required`, 404 `tix_newsletter_off`, 429 ab 5 je IP und Stunde) → Double-Opt-in-Mail; offene Anmeldungen behalten ihren Link. `GET /public/newsletter/confirm?token=` und `/unsubscribe?token=` leiten auf die Kunden-Seite (`?newsletter=bestaetigt\|abgemeldet\|ungueltig`). Tabelle `{prefix}tix_weekly_subscribers` (E-Mail, Stadt, Status, Einwilligungstext + Zeitpunkt + IP als HMAC, Bestätigung, letzter Versand). Versand: Cron stündlich ab Tag/Stunde, je Stadt die Events Donnerstag bis Sonntag (über `/public/events`), Pakete à 100, ohne Events keine Mail; Abmeldelink + `List-Unsubscribe` in jeder Mail, kein Öffnungs-Pixel des Mail-Logs, keine Werbung Dritter. Aufräumen: unbestätigt nach 7 Tagen, abgemeldet nach 30 Tagen. | evendis | 1.38.411 |
| Link des Veranstalters (Markteintritt) | Seit 1.38.412 (`TIX_Organizer_Link`, nur Mehr-Veranstalter-Modus): Herkunft `utm_source=veranstalter-link&utm_medium=<slug>`. Die WordPress-Weiterleitung `/v/<slug>` hängt sie an (nur wenn noch keine `utm_source` da ist); App-Eventliste `GET /events` → `share_url` mit Herkunft. Bestellung: Kasse übergibt `attribution {utm_source, utm_medium, …}` (bestand) → `_tix_campaign_source/_medium`. Besuche: `POST /public/track` mit `source` + neu `medium` (landet in `campaign`, wenn leer). `GET /organizer/profile` → `link`, `link_source`, `followers {total, account, mail}`, `completeness {percent, items:[{key, label, done}]}` (Logo, Titelbild, Beschreibung ≥ 40 Zeichen, Instagram/Website, Kontakt-E-Mail, Event mit Bild). `GET /organizer/link-stats[?days=30]` (Inhaber/Team-Admin) → `{link, days, visits (Sitzungen), orders, tickets, revenue (eigene Positionen, bezahlt), followers}`. AASA-Baustein `v` → `/v/*` (Option `tix_app_link_paths`). | evendis | 1.38.412 |
| Folgen per E-Mail (ohne Konto) | Seit 1.38.413 (`TIX_Mail_Follow`, nur Mehr-Veranstalter-Modus): Schalter Tixomat → **Folgen per E-Mail** (Option `tix_mail_follow {enabled}`, **Vorgabe AUS** bis Datenschutz-Absatz + Go). `GET /info` → `mail_follow {enabled}`. `POST /public/organizers/{id}/follow {email, consent: true, share_email?}` → immer `{ok, message}` (400 `invalid_email`/`tix_consent_required`, 404 `tix_follow_mail_off`/`tix_organizer`, 429 ab 10 je IP und Stunde) → Double-Opt-in-Mail; offene Anmeldung behält ihren Link, das Häkchen gilt in der letzten Fassung. `GET /public/organizers/{id}/follow/confirm?token=` und `/unfollow?token=` leiten auf `/veranstalter/<slug>/?follow=bestaetigt\|abgemeldet\|ungueltig` (Abmelden löscht den Eintrag; Bestätigen tauscht den Schlüssel). Tabelle `{prefix}tix_mail_followers` (Veranstalter, E-Mail, Status, Einwilligungstext inkl. Weitergabe + Zeitpunkt + IP als HMAC, Häkchen `share_email`, Bestätigung, letzter Versand). Neue Events: beim Veröffentlichen vorgemerkt, im stündlichen Cron dem Veranstalter (`_tix_organizer_id`, auch geteilte Partner-Events; nie Gutschein-Systemevents) zugeordnet → höchstens **eine gebündelte Mail je Veranstalter und Tag**. Nachricht des Veranstalters an Follower (`POST /notifications/broadcast` ohne Ziel `event`) geht über den Filter `tix_organizer_broadcast` auch per Mail; `recipients` zählt sie mit. Jede Mail mit „Nicht mehr folgen“ + `List-Unsubscribe`, kein Öffnungs-Pixel. Konto mit gleicher Adresse (Registrierung, Anmeldung, Bestätigung bei bestehendem Konto) → Folgen wandert nach `_tix_app_following`, Mail-Eintrag gelöscht. `GET /organizer/mail-followers` (Inhaber/Team-Admin) → `{total, shared:[{email, since}]}` – Adressen nur mit Häkchen. `followers.mail` in Profil/Link-Statistik. Unbestätigt nach 7 Tagen gelöscht. | evendis | 1.38.413 |
| Folgen per E-Mail: Abmeldeseite, Ein-Klick, Konto | Seit 1.38.447 (`TIX_Mail_Follow`, DB-Version 2 mit `user_id`). **Abmelden:** sichtbarer Link in Digest und Nachrichten → `<Kunden-Adresse>/veranstalter/<slug>/abmelden/?token=…` (Website meldet erst nach Klick ab); Kopfzeilen `List-Unsubscribe: <…/public/organizers/{id}/unfollow?token=…>` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (jetzt auch bei Nachrichten). `GET /public/organizers/{id}/unfollow?token=` löscht **nicht** mehr, sondern leitet zur Abmeldeseite weiter. **`POST /public/organizers/{id}/unfollow {token}`** (JSON, Formular oder Token in der URL; RFC 8058 `List-Unsubscribe=One-Click`) → `{ok:true, organizer_id}`, Eintrag gelöscht; ungültig bzw. anderer Veranstalter → 404 `tix_token_invalid`; 30 je 10 min je IP. **Konto:** Übernahme ins Konto (Registrierung/Anmeldung/Bestätigung mit gleicher Adresse) behält den Mail-Eintrag und verknüpft ihn (`user_id`; Mails an die aktuelle Konto-Adresse, eigenes Abmelde-Token). **`GET /customer/following/mail`** → `{ok, enabled, consent_text, items: [{organizer_id, mail}]}` (alle gefolgten Veranstalter). **`POST /customer/following/{id}/mail {mail, consent}`** bzw. **`POST /customer/following/mail {organizer_id, mail, consent}`** → `{ok, organizer_id, mail}`; Einschalten braucht `consent` (400 `tix_consent_required`, gespeichert: `CONSENT_ACCOUNT_TEXT` + Zeitpunkt + IP-HMAC); Ausschalten löscht den Eintrag; nicht gefolgt → 404 `tix_not_following`; Schalter aus → 404 `tix_follow_mail_off`. Konto-Folgen ohne Eintrag = keine Mails (Vorgabe aus). Folgen im Konto beendet bzw. Konto gelöscht → Mail-Eintrag gelöscht. | evendis | 1.38.447 |
| Dashboard „Dein Link“, Zahlarten, neue Follower | Seit 1.38.414 (nur Mehr-Veranstalter-Modus): `GET /info` → `payment_methods [{id, title}]` (aktive Zahlarten wie in der App-Kasse, für die Vertrauens-Leiste im Web; 10 Minuten zwischengespeichert, beim Speichern der Einstellungen neu). `GET /organizer/link-stats` zusätzlich `followers_total` und `followers_new` (neu im Zeitraum: Konto-Follows mit Zeitstempel + im Zeitraum bestätigte Mail-Follower). Follow-Zeitpunkt: User-Meta `_tix_app_following_since {organizer_id: unix}` wird bei jeder Änderung von `_tix_app_following` mitgeschrieben (ältere Follows ohne Zeitstempel zählen nicht als neu). Web-Dashboard `[tix_organizer_dashboard]` → Übersicht oben (nur Inhaber/Team-Admin): Karte **Dein Link** (kurze Adresse, Kopieren, Teilen per Web Share API sonst Kopieren, QR-Vorschau, **PNG laden** 1200 × 1400, **PDF laden** A5 als Vektor mit Helvetica/WinAnsi, **Story-Bild 9:16** 1080 × 1920 mit Titelbild/Logo, Name, Stadt, QR; Bio-Tipp wie in der App; Zeile „Über deinen Link · 30 Tage“: Besuche, Käufe, Umsatz, Follower) und **Deine Seite ist zu X % fertig** (Punkte aus `completeness`, offene Punkte verlinken auf „Meine Landingpage“ bzw. „Neues Event“). Alles im Browser (`assets/js/organizer-link.js` + `qrcode-generator.js`), kein Server-Aufruf. | evendis | 1.38.414 |
| Veranstalter-Seite: Steckbrief, FAQ, Vertrauen, Galerie, Anfahrt | Seit 1.38.415 (`TIX_Organizer_Page`, nur Mehr-Veranstalter-Modus, Vertrag evendis-web): `GET /public/organizers/{id\|slug}` → `organizer` zusätzlich `facts {music, age, dresscode, opening, capacity}` (leere Werte fehlen, `capacity` Zahl), `faq [{question, answer}]` (answer einfaches HTML), `followers` (Konto + App + bestätigte Mail), `since` (frühestes von Veröffentlichung des Veranstalters und erstem Event), `events_held` (vergangene, nicht abgesagte, veröffentlichte Events, ohne Gutschein-Systemevents), `gallery [{url, thumb, event_id, event_title, date}]` (Galerien vergangener eigener Events, neueste zuerst, max. 24), `venue {name, address, city, lat, lng, transit, parking}` (Ort mit den meisten Events, sonst Adresse des Veranstalters; `null` ohne beides; Anfahrt am Ort vor Veranstalter), `schema_type` (`""` oder NightClub, EventVenue, MusicVenue, BarOrPub, Organization, PerformingGroup). `GET /public/events` → je Event `series_id` (Wiederholungs-Elter bzw. manuelle Serie; Elter = eigene ID; 0 = keine). Pflege: `GET/POST /organizer/profile` → `facts` (Teil-Update, leer/0 entfernt), `faq` (ganze Liste, max. 20; 400 `tix_invalid_field`), `schema_type` (400 bei unbekanntem Typ), `transit`, `parking`; WP-Admin Metabox „Seite auf der Plattform“ am Veranstalter und „Anfahrt“ am Ort. Meta `_tix_org_facts`, `_tix_org_faq` [{q, a}], `_tix_org_schema_type`, `_tix_org_transit/_parking`, `_tix_loc_transit/_parking`. Kein ICS (macht das Web). | evendis | 1.38.415 |
| Musik hören: Künstler-Links | Seit 1.38.416 (`TIX_Lineup_Links`, alle Seiten): Line-up-Zeilen wie in den Apps (je Absatz/Zeile, Aufzählungszeichen weg; Künstler = Teil hinter dem ersten „:“; mehr als 20 Zeilen oder eine Zeile über 40 Zeichen = Fließtext, keine Links). Auflösung nur im Hintergrund (beim Speichern vorgemerkt, beim ersten Lauf einmalig alle kommenden Events, Cron stündlich + kurz nach dem Speichern, je Lauf und Dienst 15 Künstler, bei 429/Fehler Pause): Apple Music über die iTunes Search API (ohne Schlüssel), Spotify über die Web API mit Client Credentials (Optionen `tix_spotify_client_id` / `tix_spotify_client_secret`, Tixomat → **Künstler-Links**). Übernommen wird nur ein Treffer mit gleichem Namen (normalisiert: Groß/klein, Akzente, Satzzeichen, „&“ = „and“, „DJ“/„MC“ vorne und „(live)“ egal); ohne Treffer erneuter Versuch nach 30 Tagen. Tabelle `{prefix}tix_artist_links` je Künstlername. `GET /public/events/{id}` und `GET /events/{id}/edit` → `lineup_links [{name, artist, spotify, apple_music, source}]` (`source` auto|manual|none; URL, `null` = noch kein Treffer → Suche wie bisher, `""` = kein Link); Editor zusätzlich `auto {spotify, apple_music}`. `POST /events/{id}` → `lineup_links [{name, spotify, apple_music, source}]`: auto = Korrektur entfernen, manual = Links festhalten (bleibt bei jedem Abgleich), none = für dieses Event kein Link; ungültige Links 400 `tix_invalid_field` (`lineup_links.spotify`/`.apple_music`). Meta `_tix_lineup_links`. WP-Admin: Metabox „Musik hören: Künstler-Links“ am Event. Website (`[tix_app_lineup]`): Künstlerseite (Spotify vor Apple Music), ohne Treffer Spotify-Suche wie bisher, „kein Link“ ohne Link. | alle | 1.38.416 |
| Design der Veranstalter-Seite | Seit 1.38.417 (`TIX_Organizer_Page`, nur Mehr-Veranstalter-Modus, Vertrag evendis-web): `page_design` = `galerie` (Standard) \| `clubnacht` \| `profil` \| `ticketschalter` \| `bento`; leer/unbekannt = `galerie`. `GET /public/organizers` und `/public/organizers/{id\|slug}` → `page_design` (immer gesetzt). `GET/POST /organizer/profile` → `page_design` (nur eigener Veranstalter; andere Werte 400 `tix_invalid_design`, `field` `page_design`). Auswahl als fünf Kacheln mit schematischer Vorschau, Namen und einem Satz im WP-Admin (Metabox am Veranstalter) und auf „Meine Landingpage“. Speichern leert die öffentlichen Zwischenspeicher (`tix_pub_*`). Meta `_tix_org_page_design`. | evendis | 1.38.417 |
| Website sofort aktualisieren (evendis.de) | Seit 1.38.418 (`TIX_Web_Revalidate`, nur mit Schlüssel): nach dem Speichern eines Veranstalters (WP-Admin, „Meine Landingpage“, `POST /organizer/profile` inkl. Bild) bzw. eines Events (Speichern, Veröffentlichen, Absagen, Papierkorb/Löschen, App-Editor `POST /events/{id}`) einmal je Request beim Beenden `POST <URL>` mit Header `x-revalidate-secret` und `{"organizer":"<slug>","events":["<slug>",…]}` (Veranstalter: seine kommenden Events; Slugs nur `[a-z0-9-]`, sortiert, max. 50; Event ohne Veranstalter nur `events`). Nicht blockierend, Zeitlimit 2 s, nur IPv4 (`CURLOPT_IPRESOLVE_V4`), Fehler nur im Server-Log, letzter Aufruf in `tix_web_revalidate_last`. Käufe lösen nichts aus (nur „Ausverkauft“ über `_tix_status`). Schlüssel: Konstante `TIX_WEB_REVALIDATE_SECRET` bzw. Option `tix_web_revalidate_secret`; URL: Konstante `TIX_WEB_REVALIDATE_URL` bzw. Option `tix_web_revalidate_url`, Standard Kunden-Adresse + `/api/revalidate/`. Ohne Schlüssel kein Aufruf. | evendis | 1.38.418 |
| Ticket-PDF: Schriften + Ersatz-Ticket | Seit 1.38.419 (alle Seiten): Die GD-Schriften für gerenderte Tickets waren seit dem Start keine Schriften (`assets/fonts/OpenSans-*.ttf`, `RobotoMono-Regular.ttf` = gespeicherte HTML-Seiten) – in allen Bild-/PDF-Tickets fehlte der Text (Vorlagen-Tickets in Mails/CRM/Support/Sponsoren, `GET /customer/tickets/{id}/pdf`). `TIX_Ticket_Template::get_font_path()` nutzt jetzt Manrope (OFL, `assets/fonts/manrope/`, normal 500, fett 800, „monospace“ 700) und prüft, ob die Datei eine echte TrueType-Schrift ist; die kaputten Dateien sind entfernt. Ersatz-PDF ohne Ticket-Vorlage (`GET /customer/tickets/{id}/pdf`): hell und druckfreundlich – Kopf mit Seitenname (Akzentfarbe nur bei genug Kontrast, sonst Dunkelgrau; evendis Rot), Titel, Datum/Uhrzeit, Einlass, Ort, Kategorie/Sitz, Name, Preis, Bestellung, großer QR-Code, Ticketcode, Hinweis „Bitte am Einlass vorzeigen“. | alle | 1.38.419 |
| Online-Ticket teilen | Seit 1.38.420 (alle Seiten): Ticket-JSON (`TIX_App_Checkout::ticket_from_post` → `GET /customer/tickets`, `GET /customer/orders/{id}`, Bestellstatus der App-Kasse, Vermittlung) zusätzlich `online_url` = Online-Ticket `?tix_dl=<Token>` (Token wie in den Mails, wird bei Bedarf angelegt) auf der Kunden-Adresse (evendis: `https://evendis.de/?tix_dl=…`, Durchleitung an WordPress); `""` bei Spiegel-Tickets und ohne Ticketcode. Ohne Kunden-Adresse (KitchenKlub/Mallorca) die eigene Seite. | alle | 1.38.420 |
| Vermittlungsvertrag digital | Seit 1.38.421 (`TIX_Contract`, nur Mehr-Veranstalter-Modus, baut auf `TIX_Org_Approval` auf): **Fassungen:** Beim Setzen einer neuen Vertragsversion wird der Wortlaut der Vertragsseite (WP-Seite, sonst Adresse) eingefroren – Option `tix_contract_snapshots` (version → title, url, html, text, sha256, frozen_at, by); fehlt er, beim nächsten Zustimmen. Eingefrorene Fassungen ändern sich nicht (neuer Wortlaut = neue Version). **Nachweis:** `_tix_org_terms` zusätzlich `sha256`; jede Zustimmung auch in `_tix_org_terms_history`; Aktion `tix_org_terms_accepted`. Danach Mail an den Inhaber mit dem Vertrag als PDF (Wortlaut, Veranstalter, Nutzer, Zeitpunkt, IP, Prüfsumme), optional Kopie an die Benachrichtigungs-Adresse (Einstellung „Vertragsbestätigungen“, `contract_copy_admin`). **Zusatzvereinbarung:** Ändert ein Admin am Veranstalter die eigene Gebühr oder „Weitere Sonderkonditionen“, entsteht ein Vorschlag (`_tix_org_addendum_pending`: id, title, text, sha256, values {fee {custom, percent, fixed}, extra}, previous); bis zur Zustimmung gilt die bisherige Kondition. Zustimmung: „Mein Konto“ oder `POST /organizer/approval/addendum {id, accept: true}` (Inhaber/Team-Admin; 400 `tix_accept_required`, 409 `tix_addendum_changed`, 404 `tix_no_addendum`) → Gebühr greift, Nachweis in `_tix_org_addenda`, Mail mit PDF. `GET /organizer/approval/addendum` → {pending, accepted[]}; `GET /organizer/approval` → `addendum` (offen oder null), `terms.sha256`. **Dokumente:** `GET /organizer/approval/document?type=terms|addendum[&id=]` (PDF), WP-Admin Metabox „Vertrag & Nachweise“ am Veranstalter (Wortlaut ansehen, PDF, frühere Zustimmungen, Zusatzvereinbarungen), „Mein Konto“ → Vertragsunterlagen. **Hinweis beim ersten Verkauf:** `GET /events/{id}/edit` → `contract_notice {version, title, url, text}` bzw. null, WP-Hinweis im Event-Editor – solange der Veranstalter kein anderes Event mit Online-Verkauf hat; ohne Häkchen. | evendis | 1.38.421 |
| Vermittlungsvertrag dynamisch | Seit 1.38.422 (`TIX_Contract_Template`, nur Mehr-Veranstalter-Modus, Vertragsquelle „Vorlage im Plugin“ = `tix_org_approval_settings.terms_source = template`, Vorgabe „page“ wie bisher): Vorlage `tix_contract_template` (Markdown, im Admin editierbar; leer = `includes/contract/vermittlungsvertrag.md`), Platzhalter `{{…}}` aus den Einstellungen (Gebühr, Zahlungskosten, Zahlungsarten, Anteil Gast, Vorgabe neue Veranstalter, Kanal-Gebühr, Auszahlungsfrist, Stammdaten `invoice_company_*` + Vertretung/Register, Einwandfrist, Zahlungsziel, Kündigungsfrist, Änderungsvorlauf, Gerichtsstand, USt-Hinweis – neue Werte unter `contract`), Veranstalter-Platzhalter aus Veranstalter/Rechnungsadresse; Vermerke **[PRÜFEN …]** werden nicht angezeigt. Anlage „Aktuelle Konditionen“ (ab „### Anlage“) je Veranstalter mit wirksamen Werten, nicht Teil des eingefrorenen Wortlauts, im Zustimmungs-PDF. Versionen `tix_contract_versions`: Ändert sich ein allgemeiner Wert oder die Vorlage, entsteht ein Entwurf (`tix_contract_draft`, Änderungsliste + Einstufung `favorable` | `adverse` | `immediate`), Admin-Hinweis, Veröffentlichen in Veranstalter-Prüfung → Einstellungen (Einstufung überschreibbar), sonst Cron nach einer Stunde. `favorable` gilt sofort ohne neue Zustimmung (Info-Mail), `adverse` mit Ankündigungs-Mail und Hinweis, gilt nach dem Änderungsvorlauf, danach `tix_terms_outdated` in der Kasse bis zur Zustimmung, `immediate` sofort mit Zustimmung (erste Fassung). Name = Datum (`2026-10-13`, `-2` …), Fassung eingefroren (sha256) auch in `tix_contract_snapshots`. Stammdaten fehlen (Firma, Anschrift, Vertretung, USt-IdNr.) → kein Veröffentlichen, kein Zustimmen (409 `tix_contract_incomplete`), bestehende Zustimmungen bleiben. Zustimmung speichert zusätzlich `doc_sha256`, `doc_text`, `annex_text`. `GET /organizer/approval` → `terms` zusätzlich `source`, `valid_from`, `pending_version`, `effective_at`, `pending_requires_consent`, `outdated`, `missing_master_data`, `can_accept`; `POST /organizer/approval/terms` nimmt gültige und angekündigte Version an; `GET /organizer/approval/contract` → {current, pending (je version, valid_from, sha256, requires_consent, changes, html, text, annex_html, annex_text), missing_master_data, can_accept, accepted, outdated}; Lese-Ansicht `admin-post.php?action=tix_contract_view[&version=]`. | evendis | 1.38.422 |
| Geplante Werte (Vertrag) | Seit 1.38.423 (`TIX_Planned_Values`, nur Mehr-Veranstalter-Modus mit Vertragsquelle „Vorlage im Plugin“): Nachteilige Änderungen an `tix_settings.fee_percent`, `fee_fixed`, `gateway_fee_included`, `fee_split_customer_share`, `settlement_delay_days` und `tix_channel_fee_settings.percent/fixed` werden beim Speichern zurückgehalten (Filter `pre_update_option_*`) und als geplanter Wert in `tix_planned_values` gespeichert; die Vertragsversion bekommt sie als neue Werte, beim Veröffentlichen erhält der Plan sein Gültig-ab (= Inkrafttreten der Version, Ankündigung + Vorlauf). Ab dem Stichtag liefern die Einstellungen den neuen Wert sofort (Filter `option_*`, Bestellzeitpunkt zählt), der Cron schreibt ihn fest. Zugunsten-Änderungen gelten sofort (und ersetzen einen Plan); wird die Version als „zugunsten“ veröffentlicht, gilt der Plan sofort. Admin: Hinweis „Aktuell X, ab TT.MM. Y“ unter den Feldern (Gebühren, Zahlungskosten, Gastanteil, Auszahlung, Kanal-Gebühr) und Liste in Veranstalter-Prüfung → Einstellungen mit „zurückziehen“ – vor der Ankündigung entsteht dann keine neue Fassung, eine angekündigte Version wird zurückgenommen (Mail, Zustimmungen fallen auf die vorherige Version zurück). `GET /organizer/fee-mode` → `planned [{key, setting, label, current, planned, current_label, planned_label, valid_from}]` (ohne Gebührenwerte bei eigener Gebühr), `GET /organizer/approval` → `terms.planned_values` (gleiche Form). Nicht geplant: Werte einzelner Veranstalter (Zusatzvereinbarung mit Zustimmung) und abweichende Kanal-Gebühr einzelner Partner. | evendis | 1.38.423 |
| Mein Konto: Vertrag sichtbar | Seit 1.38.424 (nur Mehr-Veranstalter-Modus): „Mein Konto“ zeigt oben (Aktion `tix_org_account_top`) – „Ticketverkauf pausiert“ nach Inkrafttreten einer zustimmungspflichtigen Version ohne Zustimmung (mit Grund), die angekündigte Fassung mit Datum und Änderungen alt → neu sowie „Lesen und zustimmen“, eine offene Zusatzvereinbarung, „Deine Konditionen“ (Gebühr, Zahlungskosten, Auszahlung) mit geplanten Werten „Ab TT.MM.: …“. Mitteilung (Feed/Push, Aktion `organizer:approval`) an Inhaber und Team-Admins aller Veranstalter beim Veröffentlichen einer Version (zugunsten, Ankündigung, neue Fassung) und bei der Rücknahme; Zusatzvereinbarung wie bisher. Zustimmen-Knöpfe nur für Inhaber/Team-Admins. | evendis | 1.38.424 |
| Künstlerfoto je Act | Seit 1.38.425 (`TIX_Lineup_Links`, alle Seiten; Rückfall statt Deezer – die Deezer-API-Bedingungen erlauben keine kommerzielle Nutzung): Foto je Act im Event (Meta `_tix_lineup_photos` {artist_key: attachment_id}, 0 = bewusst ohne Foto); das zuletzt gesetzte Foto eines Künstlers gilt auch in anderen Events (Spalte `photo_id` in `tix_artist_links`, DB-Version 2), bis dort eins gesetzt ist; Entfernen löscht auch diese Vorgabe, wenn sie von hier stammte. `lineup_links`-Zeilen (Event-Detail, Editor) zusätzlich `picture`/`picture_thumb` (URL oder null), Editor `photo_id`. Pflege: WP-Admin Metabox „Musik hören: Künstler-Links“ (Spalte Foto, Mediathek), `POST /events/{id}` `lineup_links [{name, photo_id}]` (nur Foto ändert keine Links; kein Bild → 400 `tix_invalid_field` `lineup_links.photo_id`), `POST /events/{id}/lineup-photo` (multipart `name`, `file`; JPG/PNG/WebP ≤ 10 MB) → {photo_id, artist}. Öffentlich: `GET /public/events/{id}/artists` → {artists: [{name, artist, picture, picture_thumb, deezer_url: null, top, spotify, apple_music, source}]} (404 für nicht öffentliche Events; `top` seit 1.38.428, siehe „Bekannt für“). Website `[tix_app_lineup]`: Foto im Künstlerkreis. Bilder aus der eigenen Mediathek, kein Abruf bei Dritten. | alle | 1.38.425 |
| Landingpages entfernt, eigene Domains | Seit 1.38.426: `TIX_Organizer_Landing` rendert nichts mehr (keine /v/-Seite, keine Subdomain-Weiche, kein Branding, kein Umbiegen von home_url, kein Landing-Herkunftscookie). Felder `_tix_org_landing_*` und ihre Pflege (Admin-Metabox, „Meine Landingpage“, `/organizer/profile`) bleiben als Datenquelle für `/public/organizers`. Alte Links (nur wenn die Landing-Funktion an ist): `/v/{slug}` → 301 auf `<Kunden-Adresse>/veranstalter/{slug}/` (vorhandene Parameter bleiben, sonst `utm_source=veranstalter-link&utm_medium={slug}`); `{slug}.<Kunden-Host>`, falls es bei WordPress ankommt → 301 auf die Veranstalter-Seite bzw. die Startseite. `canonical_url()` = eigene Domain bzw. Kunden-Adresse/veranstalter/{slug}/. **Eigene Domain** (`TIX_Org_Domains`, nur Mehr-Veranstalter-Modus): Admin-Schalter je Veranstalter „Eigene Domain erlaubt“ (`_tix_org_domain_allowed`) + Hinweis Zusatzpreis (`_tix_org_domain_fee_note`, keine Abrechnung); Domain (`_tix_org_domain` {host, status pending_dns|active|error, error, checked_at, activated_at}) über „Mein Konto“ bzw. `GET/POST/DELETE /organizer/domain`, `POST /organizer/domain/check` (Inhaber/Team-Admin; 403 `tix_domain_not_allowed`, 400 `tix_invalid_domain`, 409 `tix_domain_taken`). DNS-Prüfung per `dns_get_record` (CNAME auf `cname_target` bzw. A/AAAA auf die eingestellten Adressen; Option `tix_org_domain_settings`, Admin Tixomat → Eigene Domains), Cron stündlich (aktive täglich), beim ersten „aktiv“ Mail + Mitteilung (`organizer:domain`). Öffentlich: `GET /public/domains/{host}` → {host, organizer_id, slug, design, active} (Cache 5 Min.), `GET /public/domains` → Liste aktiver, `GET /public/domains/allowed?domain=[&secret=]` → 200/404 für On-Demand-TLS. `/public/organizers` → `custom_domain`. Aktive Domains auch in `tix_org_domains_active` (host → organizer_id): `TIX_Customer_URL` leitet Anfragen von dort wie die Kunden-Adresse durch (vertrauenswürdiger Proxy). Bestellungen: `POST /customer/orders` `origin_host` → `_tix_origin_host` (nur aktive Domain eines beteiligten Veranstalters); Bestätigungs-, Status-, Erinnerungs- und Nachbefragungs-Mails dieser Bestellung zeigen auf die Domain. | evendis | 1.38.426 |
| Eigene Domains: Vertrag Web | Seit 1.38.427: `GET /public/domains/{host}` → zusätzlich `domain {host, organizer_id, slug, design, active}` (flache Felder bleiben); `www.`-Variante liefert die eingetragene Hauptadresse (auch `/public/domains/allowed` und `origin_host`). `return_url` ist für aktive eigene Domains erlaubt. Neuaufbau der Website bei angelegten/geänderten/abgeschalteten Domains: `POST /api/revalidate/` mit `{"domains":[host]}` (TIX_Web_Revalidate). Optionales Impressum je Veranstalter (`_tix_org_imprint` HTML, `_tix_org_imprint_url` https) über `/organizer/profile` (`imprint`, `imprint_url`; 400 ohne https) und Admin → `/public/organizers/{id|slug}` `imprint` (HTML) / `imprint_url` bzw. null. | evendis | 1.38.427 |
| Musikwunsch: Apple statt Deezer | Seit 1.38.428 (`TIX_Music`, alle Seiten; Deezer-API nur nicht-kommerziell): `GET /music/search?q=` sucht in der eigenen Tabelle `tix_songs` und ab 3 Zeichen live in der **Apple iTunes Search API** (`media=music&entity=song&country=DE&limit=25`, nur `kind: song`); Ergebnis 15 Min. zwischengespeichert, eigener Zähler höchstens 18 Anfragen pro Minute, bei 429/403 10 Min. Pause (dann nur eigene Liste, kein Fehler). Gespeichert werden nur Titel, Artist, Dauer, Explicit und die Apple-Track-ID (Spalte `apple_id`, DB-Version 2); **keine Cover, keine Hörproben** (bei Apple „Promotional Content“: nur zur Store-Werbung, nur neben Apple-Badge, nicht speichern). Ein Song aus der Deezer-Zeit mit gleichem Titel + Artist bekommt die Apple-ID (Wunsch-Zähler bleiben). **Antwortformat unverändert** `{id, deezer_id, title, artist, cover, duration, explicit, requests}`: `deezer_id` ist die Song-Referenz für `POST /music/requests` – alte Songs behalten ihre Deezer-ID, neue tragen die Apple-Track-ID; `cover` immer `""`. `POST /music/requests {deezer_id}` sucht den Song nur in der eigenen Tabelle (bei Doppelbelegung entscheidet `title`), kein Abruf bei Deezer/Apple; unbekannt → Freitext mit `title`/`artist`. Wunsch-Schlüssel `dz:<Deezer-ID>`, `am:<Apple-ID>`, sonst Freitext. Beim Update: Deezer-Cover-Adressen in Songs und Wünschen geleert, täglicher Deezer-Chart-Import (`tix_music_import`) abgemeldet, Einstellungen `deezer_genres`/`deezer_playlists` entfernt. Admin „Musikwünsche“ ohne Chart-Import. | alle | 1.38.428 |
| „Bekannt für“: Apple Music Top-Songs | Seit 1.38.428 (`TIX_Apple_Music`, alle Seiten): `GET /public/events/{id}/artists` → je Act `top [{title, preview, link, artwork, year}]` = Apple Music Top-Songs des Künstlers (`/v1/catalog/de/artists/{id}/view/top-songs`, meistgestreamt zuerst, **seit 1.38.429 bis zu 10** (vorher 3; Apps zeigen 3 + „Mehr anzeigen“), nur mit Hörprobe; `?top=1…10` begrenzt die Titel je Act; `preview` = `previews[0].url`, `link` = `attributes.url`, `artwork` = Cover-URL 200x200, `year` Zahl oder null). Developer-Token ES256-JWT (12 h) aus Optionen `_tix_apple_music_key` (.p8-Inhalt, wird nie angezeigt), `_tix_apple_music_key_id`, `_tix_apple_music_team_id` (leer = `_tix_apns_team_id`); Pflege Tixomat → Künstler-Links (Kasten „Bekannt für“, .p8 einfügen oder hochladen, Status, „Ranglisten jetzt laden“) oder `wp option update`. **Ohne Schlüssel `top: []`, kein Fehler, kein Abruf.** Künstler-ID aus dem Apple-Music-Link der Zeile (manuelle Korrektur vor automatischem Treffer), sonst Katalogsuche nach dem Namen (nur gleicher Name, 30 Tage gemerkt); `source: none` oder manuell ohne Apple-Link → keine Titel. Abruf nur im Hintergrund: Cron `tix_apple_music_cron` stündlich über kommende Events (je Lauf höchstens 40 Anfragen), fehlende/veraltete Ranglisten stoßen aus der Route einen Lauf an; Rangliste je Künstler-ID 24 h frisch, bis 7 Tage lesbar (Transients `tix_am_top_<id>` mit Format-Version `v`; Einträge im alten Format mit 3 Titeln bleiben sichtbar und werden sofort erneuert, nach dem Update stößt `tix_apple_music_ver` einmal einen Lauf an); 401/403 → 1 h Pause + Status, 429/5xx → Pause nach Retry-After. Gespeichert werden nur Adressen – Hörproben und Cover lädt das Gerät direkt von Apple. | alle | 1.38.428 |
| Künstler aus dem Programm | Seit 1.38.428 (`TIX_Lineup_Links::lines`): Künstlerliste wie in den Apps – zuerst die Titel aus dem Programm (`_tix_timetable`, Tage nach Datum, Reihenfolge wie im Programm, leere und über 40 Zeichen lange Titel weg, höchstens 60), danach weitere Zeilen aus dem Line-up-Feld (Fließtext-Regel wie bisher); jeder Künstler einmal (Vergleichsform `norm`). Gilt für `/public/events/{id}/artists`, `lineup_links` (Event-Detail, Editor, `POST /events/{id}`), Metabox „Künstler-Links“ mit Foto-Spalte und die Hintergrundsuche (Änderung am Programm merkt neue Künstler vor). Beispiel Mallorca: Line-up leer, 11 Acts im Programm. `lineup_links` ohne `photo_id` lässt ein gepflegtes Foto unverändert. | alle | 1.38.428 |
| Partyspiel „Mehrere Handys“ | Seit 1.38.430 (`TIX_Party_Rooms`, alle Seiten; Vertrag uebergaben/kitchenklub-app.md Paket 4): Räume mit 5-stelligem Code (`23456789ABCDEFGHJKMNPQRSTUVWXYZ`, ohne Groß/klein), ohne Konto, nur Spitznamen (1–20 Zeichen), höchstens 12 Handys. Routen (`tixomat/v1`): `GET /party/config` → `{enabled, max_players: 12, ttl_hours: 6, poll_ms: 1500}`; `POST /party/rooms {nickname}` → 201 `{player_id, player_token, room}` (5 je Stunde und IP); `POST /party/rooms/{code}/join {nickname}` (20 je Minute und IP; 404 `party_room_not_found`, 410 `party_room_expired` auch bei beendet, 409 `party_room_full`/`party_nickname_taken`, 422 `party_nickname_invalid`); `GET /party/rooms/{code}?since=rev` (Header `X-Party-Token`) → `{room}` oder **304 ohne Inhalt**, 403 `party_forbidden`, 429 `party_rate_limited` unter 500 ms je Token, verlängert die Lebenszeit; `POST …/start`, `…/turn` (Karte opak ≤ 4 KB, Typen task/who/truth/group/vote/rule/quiz, sonst 422 `party_turn_invalid`; löscht Stimmen), `…/score {points, strafen}` und `DELETE /party/rooms/{code}` nur Gastgeber; `POST …/vote {n, target}` (409 `party_vote_closed`/`party_already_voted`, 422 `party_vote_invalid`); `POST …/leave` (Gastgeber = beenden). Raum-JSON `{code, rev, status lobby\|playing\|ended, host_id, players [{id, nickname, points, strafen, host}], turn (+ counts {id: n}, my_vote) \| null, expires_at ISO 8601}`; Spieler-IDs `p1`, `p2` … (nicht wiederverwendet). Token 32 Byte base64url, gespeichert nur SHA-256. Antworten `Cache-Control: no-store` + `X-LiteSpeed-Cache-Control: no-cache` (config 60 s). Tabellen `{prefix}tix_party_rooms` (Raum-JSON ≤ 16 KB, optimistisches Schreiben über `rev`) und `{prefix}tix_party_players` (Token-Hash → Spieler, `seen_ms` für den Mindestabstand); das Abfragen braucht eine PK-Abfrage + ein PK-UPDATE. Keine IP, keine Geräte-ID gespeichert; Rate-Limits über Transients mit HMAC der IP. Verfall 6 h nach letzter Aktivität, höchstens 12 h; beendete Räume nach 10 Minuten gelöscht (Cron `tix_party_rooms_cleanup` stündlich + einmalig nach dem Beenden), Tokens mit. Schalter Tixomat → **Partyspiel** (Option `tix_party.enabled`, Standard an): aus → config `enabled:false`, übrige Routen 404 `party_disabled`. | alle | 1.38.430 |
| Gutscheine nur in der App | Seit 1.38.431 (`TIX_App_Coupons`, alle Seiten): neue Felder am Gutschein (`tix_coupons`): `app_only` (nur in der App einlösbar), `app_promote` + `app_title`, `app_text` (≤ 140), `app_from`/`app_until` (Werbezeitraum, Y-m-d einschließlich), `app_auto` (Art in der App: automatisch im Warenkorb bzw. Code zum Eingeben), `organizer_id` (Mehr-Veranstalter-Modus: gilt nur bei Events dieses Veranstalters, 0 = Plattform). **Kanal:** App = Header **`X-Tix-Client: app`** (auch `app/<bundle>`), ersatzweise `X-Tix-Channel: app` oder User-Agent `Dart/…`; Filter `tix_is_app_request`. Außerhalb der App: `POST /customer/cart/quote` → `coupon {valid:false, discount:0, message:"Dieser Gutschein gilt nur in der <App-Name>-App.", app_only:true, error_code:"tix_coupon_app_only", app_url, store_url, play_url}`; `POST /customer/orders` → 409 `tix_coupon_app_only` (`data.app_url/store_url/play_url`, vor Zahlart/Adresse); WordPress-Kasse (AJAX) gleiche Meldung + Link „In der App einlösen →“; Auto-Apply und Coupon-Popup überspringen Nur-App-Gutscheine. In der App: eingelöst → `coupon.message` „App-Gutschein „<Titel>“ eingelöst.“, `coupon.app_only`. `app_url` = `<Kunden-Adresse>/app/kasse/<event_id>/?coupon=<CODE>` (Universal Link, AASA-Baustein `kasse` in `tix_app_link_paths`), sonst App-Store-Link (`tix_app_store_url`, dann `tix_play_store_url`), sonst null; `/app/kasse/<id>/` im Browser (WordPress-Seiten): Ausweichseite mit Hinweis, Code, Store-Knöpfen und „Ohne Gutschein im Web kaufen“ (noindex). **Liste:** `GET /public/app-coupons[?organizer=<id\|slug>][&event=<id>]` (60 s Cache) → `{ok, coupons:[{code, title, text, auto_apply, app_only, organizer_id, event_ids, discount_type, value, discount_label, min_amount, starts_at, ends_at, valid_until}]}` – nur beworbene, aktive, nicht abgelaufene/aufgebrauchte, im Werbezeitraum; mit `organizer` eigene + passende Plattform-Gutscheine, mit `event` nur passende. **Verwaltung (App):** `GET/POST /organizer/coupons`, `POST/DELETE /organizer/coupons/{code}` (Admin; Mehr-Veranstalter-Modus Inhaber/Team-Admin, nur eigene, `organizer_id` fest; sonst Rolle Veranstalter) mit `{code, discount_type, value, discount_label, description, expires, max_uses, used, min_amount, allowed_events, one_per_email, organizer_id, app_only, promote_in_app, auto_apply, title, text, promote_from, promote_until, promoted_now}`; 400 `tix_invalid_field`, 409 `tix_coupon_exists`, 404 `tix_coupon_not_found`; Liste mit `can_app_fields: true`. Admin: Abschnitt „📱 App“ im Gutschein-Formular (Veranstalter-Auswahl im Mehr-Veranstalter-Modus), Kennzeichen „NUR APP“/„APP“, Einstellungen App-Name/App-Store/Google Play (`tix_app_name`, `tix_app_store_url`, `tix_play_store_url`). | alle | 1.38.431 |
| Guthaben (Phase 1: Kern) | Seit 1.38.432 (`TIX_Wallet_Balance`, `class-tix-wallet-balance.php` – `TIX_Wallet` bleibt die Apple-/Google-Wallet-Pässe der Tickets; alle Seiten, **ohne Einstellung unsichtbar**). Geschlossener Kreis: Guthaben gilt nur beim Bereich, wo es aufgeladen wurde (Seite bzw. evendis-Veranstalter). **Tabellen** (`tix_wallet_db`): `{prefix}tix_wallets` (id, user_id, scope_type site\|organizer, organizer_id, balance_paid, balance_bonus in Cent, status active\|locked\|closed; eindeutig user_id+organizer_id), `{prefix}tix_wallet_tx` (**nur anfügen**: type topup\|bonus\|charge\|reversal\|refund\|adjust\|expire\|payout, amount_paid/amount_bonus mit Vorzeichen, balance_after_*, order_id, ref_tx_id, staff_user_id, station, device_id, channel app\|web\|pos_cash\|pos_card\|sumup\|admin\|system, idempotency_key eindeutig je Geldbörse, meta JSON, created UTC), `{prefix}tix_wallet_devices` und `{prefix}tix_wallet_nonces` (Phase 2). **Buchen** nur über `TIX_Wallet_Balance::post_tx()`: Transaktion, Zeile `FOR UPDATE`, Idempotenz-Treffer liefert die vorhandene Buchung (`replay`), Saldo per `UPDATE … WHERE saldo + x >= 0`, danach Buchungszeile mit Saldo danach. Abbuchen nach Option `_tix_wallet_spend_order` (`paid_first` Standard \| `bonus_first`). `wp tix wallet verify [--wallet=]` (auch `wp tixomat wallet`) prüft Saldo = Summe der Buchungen, lückenlose Kette, nie negativ; `wp tix wallet charge <id> <euro> [--key=]` nur Test/Support. **Einstellungen** Option `_tix_wallet` bzw. evendis je Veranstalter Meta `_tix_org_wallet`: enabled, amounts (€), free_amount, free_min/free_max, bonus_tiers `[{from, bonus, type: fixed\|percent}]` (höchste erreichte Stufe), max_balance (250), max_topup (250), new_account_days (7) + new_account_max (50, Summe der Aufladungen in den ersten Tagen), approval_threshold (50, ab Phase 2), stations, refund_policy. evendis: aktiv nur mit Modul `wallet` (`_tix_org_modules`, neu, Standard aus) und freigegebenem Veranstalter; **Aufladen bleibt im Mehr-Veranstalter-Modus aus** (`topup_disabled_reason: direct_payment_pending`), kein plattformweites Guthaben. **Aufladen** über die App-Kasse: verstecktes System-Event „Guthaben“ (Meta `_tix_system_wallet_event`, aus Listen ausgeblendet, Vorverkauf immer offen) mit Kategorien `wallet_topup` (fester Betrag bzw. `wallet_free_amount`, `no_fee`); `POST /customer/cart/quote` und `/customer/orders` mit `items:[{index, qty:1, custom_amount}]` → **Konto Pflicht** (401 `tix_wallet_login_required`), Limits schon in der Quote (409 `tix_wallet_limit_topup` \| `tix_wallet_limit_balance` \| `tix_wallet_limit_new` mit `data.limit/room`), 400 `tix_wallet_amount` \| `tix_wallet_qty` \| `tix_wallet_coupon` \| `tix_wallet_mixed`, 423 `tix_wallet_locked`, 404 `tix_wallet_off`; Quote zusätzlich `wallet_topup {amount, bonus, credit, organizer_id}`. Bei `tix_order_completed` Gutschrift `topup` + `bonus` (Idempotenz = Bestellposition), keine Tickets, Mitteilung „Guthaben aufgeladen: 50 € + 5 € Bonus“ (`type`/`action` `wallet`). Storno/Erstattung/Fehlschlag nach Bezahlung und Mollie-Rückbuchung (neuer Hook `tix_order_chargeback` aus dem Mollie-Webhook bei `amountChargedBack`) → Gegenbuchung `reversal`, soweit Guthaben da ist; fehlt bezahltes Guthaben → Geldbörse `locked` + Admin-Hinweis (`tix_wallet_alerts`). Web-Kasse lehnt Guthaben ab („nur in der App“). **REST** (alle `Cache-Control: no-store`, `X-LiteSpeed-Cache-Control: no-cache`): `GET /wallet/config[?organizer=<id\|slug>]` (öffentlich) → `{ok, enabled}` bzw. `{ok, enabled:true, organizer_id, topup_enabled, topup_disabled_reason, event_id, amounts:[{index, amount, bonus, credit}], free_amount, free_index, min, max, bonus_tiers:[{from, bonus, type, text:"+5 € ab 50 €"}], limits{max_balance, max_topup, new_account_days, new_account_max}, approval_threshold, refund_policy, login_required:true, currency}`; `GET /wallet[?organizer=]` (Token) → `{ok, enabled, wallets:[{id, scope, organizer_id, organizer_name, status, balance, balance_paid, balance_bonus, currency, updated_at}]}` (leer, bis zur ersten Aufladung; geschlossene nie; ausgeschaltete Bereiche nur mit Restguthaben); `GET /wallet/{id}/tx?page=` (nur eigene, 30 je Seite) → `{ok, wallet, page, per_page, has_more, tx:[{id, type, title, amount, amount_paid, amount_bonus, balance_after, station, channel, order_id, created_at}]}` ohne Personal-Namen. Personal (neues Recht **`tix_app_wallet_charge`** für Admin, `tix_staff`, `tix_organizer`; Mehr-Veranstalter-Modus nur eigener Veranstalter über `TIX_App_Scope`, Admin `?organizer=`): `GET /wallet/admin/config` → `{organizer_id, active, topup_enabled, settings, spend_order, module_required, can_edit}`; `POST /wallet/admin/config` (Teil-Update; Admin, Inhaber/Team-Admin bzw. Rolle Veranstalter; evendis: an/aus nur über das Modul); `GET /wallet/admin/report?date=Y-m-d&station=` → `{organizer_id, date, station, by_type{type:{count, paid, bonus, total}}, by_station:[…], by_staff:[{staff_user_id, name, …}], open{wallets, paid, bonus, total}}`. **Konto löschen** (`/auth/delete`, WordPress-Löschung): Geldbörsen werden anonymisiert (`user_id` = −id) und geschlossen, Buchungen bleiben; Option `_tix_wallet_delete_policy` = `anonymize` (Standard) \| `block` (409 `tix_wallet_open_balance` bei offenem bezahltem Guthaben) – Entscheidung des Users offen. Admin: Tixomat → **Guthaben** (Einstellungen, Hinweise, Geldbörsen mit Suche, Verlauf, Korrektur mit Pflichtgrund, Sperren/Entsperren, Prüfung je Geldbörse). | alle | 1.38.432 |
| Guthaben Phase 2: Bezahlen per QR | Seit 1.38.433 (`TIX_Wallet_Pay`, `class-tix-wallet-pay.php`; Tabellen aus Phase 1, DB-Version 2: Geräte + Zahlungsvorgänge). Alle Routen `Cache-Control: no-store`, `X-LiteSpeed-Cache-Control: no-cache`; ist Guthaben nirgends aktiv → 404 `tix_wallet_off`. **Geräte** (Gast, Token): `POST /wallet/devices {public_key (P-256 SPKI PEM oder Base64-DER), name, platform: ios\|android}` → 201 `{ok, device{id, name, platform, status, created_at, last_used_at}, verification_required}`; `key_id` rechnet der Server (SHA-256 des Schlüssels), gleicher Schlüssel = gleiches Gerät (bei `pending` wird der Code neu geschickt). **Erstes Gerät sofort `active`**, jedes weitere `pending` + 6-stelliger Code per E-Mail (15 min, 5 Versuche) → `POST /wallet/devices/{id}/verify {code}`; höchstens 2 aktive (409 `tix_wallet_device_limit`); `GET /wallet/devices` → `{ok, max:2, devices:[…]}` (aktive + pending); `DELETE /wallet/devices/{id}` → Status `removed`, der Schlüssel kann nicht wieder angemeldet werden (403 `tix_wallet_device_blocked`, App erzeugt einen neuen). Fehler: 400 `tix_wallet_key_invalid`, 409 `tix_wallet_key_taken`, 400 `tix_wallet_code_wrong`, 410 `tix_wallet_code_expired`, 404 `tix_wallet_device_unknown`. Konto löschen entfernt die Geräte. **Code**: `TXP1.<wallet_id>.<device_id>.<ts>.<nonce>.<sig>` – ts Unix-Sekunden, nonce 16 Byte base64url (16–43 Zeichen), sig = ECDSA P-256/SHA-256 über `TXP1.<wallet_id>.<device_id>.<ts>.<nonce>` (DER, base64url); gültig 60 s ab ts, ±30 s Uhrabweichung; Prüfung `openssl_verify`. Prüf-Fehler (für das Personal formuliert): 400 `tix_wallet_code_invalid`, 410 `tix_wallet_code_expired`, 403 `tix_wallet_device_unknown` (Gerät passt nicht zur Geldbörse), 403 `tix_wallet_device_pending`, 403 `tix_wallet_device_blocked`, 403 `tix_wallet_code_signature`, 403 `tix_wallet_scope` (Geldbörse eines anderen Veranstalters), 423 `tix_wallet_locked`, 409 `tix_wallet_code_used`. Jede Nonce einmal (`tix_wallet_nonces`, Primärschlüssel Gerät+Nonce; die Zeile ist zugleich der Zahlungsvorgang mit `state` pending\|approval\|paid\|declined\|expired\|failed; Aufräumen stündlich nach 24 h). **Personal** (Recht `tix_app_wallet_charge`; Mehr-Veranstalter-Modus nur Geldbörsen des eigenen Veranstalters): `POST /wallet/scan {code, amount?}` → `{ok, preview{wallet_id, first_name, avatar (URL\|null), balance, amount, balance_ok, missing, approval_required, approval_threshold, code_used, stations}}` – verbraucht den Code **nicht**; `POST /wallet/charge {code, amount (€), station, idempotency_key (8–80 Zeichen, Pflicht)}` → 200 `{ok, status:"paid", amount, station, first_name, balance, tx{…}}` bzw. ab `approval_threshold` (0 = nie) **202** `{ok, status:"approval_required", amount, station, first_name, balance, approval_expires_at}` (Freigabe 120 s); erneuter Aufruf mit gleichem `idempotency_key` liefert den aktuellen Stand (paid mit `tx`, 409 `tix_wallet_declined`, 410 `tix_wallet_approval_expired`); zu wenig Guthaben → 409 `tix_wallet_insufficient` `{data.missing, data.balance}` **ohne** den Code zu verbrauchen; Station aus den Einstellungen (leer → „Kasse“; fehlt sie → erste Station; unbekannt → 400 `tix_wallet_station` mit `data.stations`); 400 `tix_wallet_idempotency`, 400 `tix_wallet_amount`. `POST /wallet/charge/{tx_id}/reverse {reason}` → `{ok, tx (reversal), balance}` bzw. `{ok, tx, already:true}`; Personal 10 min, danach nur Admin/Inhaber bzw. Rolle Veranstalter (403 `tix_wallet_reverse_late`), 400 `tix_wallet_reason`, 404 `tix_wallet_tx_unknown`. `POST /wallet/topup-pos {code, amount, method: pos_cash\|pos_card, station, idempotency_key}` → wie charge plus `bonus` (Bonusstufen gelten; Limits Höchstguthaben/je Aufladung, 409 `tix_wallet_limit_*`); `sumup` → 400 `tix_wallet_method` (Phase 4); geht auch im Mehr-Veranstalter-Modus (Bargeld/Karte direkt beim Veranstalter). **Gast**: `GET /wallet/pay-status?code=&timeout=0..25` (eigener Code; Long-Polling, Standard 25 s, solange `pending`) → `{ok, status: pending\|approval_required\|paid\|declined\|expired\|failed, kind: charge\|topup, amount, station, balance, approval_expires_at?, tx_id?}`; `POST /wallet/approve {code, approve: true\|false}` (nach Face ID in der App) → Status wie pay-status, 404 `tix_wallet_no_approval`, 410 `tix_wallet_approval_expired`. **Mitteilungen** (Feed + Push, `action: wallet`): „12,50 € bezahlt · Bar 1“, „Zahlung bestätigen?“, „Zahlung storniert“, „Guthaben aufgeladen: 20 € + 2 € Bonus · Bar 2“. **Ratenbegrenzung** je Mitarbeiter, Gerät und Geldbörse (je 60/min, Gast 120/min; Filter `tix_wallet_rate_limits`) → 429 `tix_wallet_rate_limited`. | alle | 1.38.433 |
| SOS-Menüpunkt in der App | Seit 1.38.434 (`TIX_App_Sos`, alle Seiten; für Club- und Festival-Profil): Option **`_tix_app_nav_sos`** `{enabled, label (≤ 20, Standard „SOS“), text (≤ 600), numbers:[{label, number}] (≤ 4, Standard Polizei 110, Feuerwehr & Rettungsdienst 112)}` – eigene Option, nicht in `_tix_app_config`. `GET /app/config` → zusätzlich `nav: {sos: {enabled:true, label, icon:"lifebuoy", text, numbers, live:"/app/sos"}}` **nur wenn an**; aus bzw. nie gesetzt = Antwort unverändert; leere App-Konfiguration + an → nur `{nav:{sos}}` ohne `ok` (Rückfall der App auf die WordPress-Seite bleibt). `POST /app/config` (Editor) speichert `nav.sos` (Teil-Update) in der eigenen Option; fehlt `nav.sos`, bleibt die Einstellung; übrige `nav`-Schlüssel landen wie bisher in `_tix_app_config`. `GET /app/config/edit` → zusätzlich `nav_sos` (immer, auch aus). **`GET /app/sos[?event_id=]`** (öffentlich, no-store) → aus: `{ok, enabled:false}`; an: `{ok, enabled:true, label, icon, text, numbers, event{id, title, date, location, starts_at, ends_at}\|null (laufendes Event im Fenster wie Musikwunsch, sonst das angegebene), awareness (wie `awareness` im Event-Detail)\|null, status{active, on_duty, report_enabled} (wie `/awareness/status`), hotline{label, number:"116 016", url}}`. Admin: Tixomat → **Awareness**, Abschnitt „SOS in der App“. | alle | 1.38.434 |
| Eventseite per URL einlesen | Seit 1.38.435 (`TIX_Event_Import`, genutzt von „Veranstalter werden“ `[tix_register_event]` → „Eventseite analysieren“ und dem Bulk-Editor über `TIX_AI_Writer::import_url()`/`analyze_url()`): Abruf mit `wp_safe_remote_get` (keine internen Adressen, nur http/https, `https://` wird ergänzt), Browser-Kennung, 5 Weiterleitungen, max. 3 MB, bei 403/429/503/Zeitüberschreitung zweiter Versuch mit Mobil-Kennung. **Zuerst strukturierte Daten**: JSON-LD schema.org `*Event` (auch `@graph`/Listen; name, startDate/endDate mit Zeitzone → Ortszeit, doorTime, location+address, performer → Line-up, offers/AggregateOffer → Tickets, typicalAgeRange, image), danach OpenGraph/Microdata. Die KI bekommt die strukturierten Werte als verlässlichen Hinweis; Titel, Datum, Zeiten und Ort aus den strukturierten Daten haben Vorrang. Ist die KI nicht verfügbar, reichen strukturierte Daten mit Titel + Datum allein. AJAX `tix_ai_fill_fields` (source_type url) → Erfolg `{fields, source: structured\|structured+ai\|ai}`; Fehler `{message, code: url_invalid\|url_unreadable\|ai_unavailable, alternatives:["upload","chat"]}` (Admins zusätzlich `reason`), Meldung „Wir konnten die Seite nicht lesen – lade den Flyer hoch oder beschreibe dein Event.“, im Formular mit Knöpfen „Flyer hochladen“ / „Event beschreiben“. **KI-Schlüssel ohne Workspace**: neue Einstellung `anthropic_workspace_id` (Einstellungen → KI) → Header `anthropic-workspace-id` bei allen Anthropic-Aufrufen (`TIX_AI_Writer::anthropic_headers()`: KI-Assistent, Content-Guard, Meta-Ads, Support-KI); verständliche Fehlermeldungen für Workspace- und Modell-Fehler. | alle | 1.38.435 |
| Mit Guthaben bezahlen (App-Kasse) | Seit 1.38.436 (`TIX_Wallet_Balance`, `TIX_App_Checkout`): `POST /customer/cart/quote` und `/customer/orders` mit **`use_wallet: true`** (nur mit Konto). Reihenfolge: erst Gutschein (in `totals`), dann Guthaben, Rest normal. Quote → zusätzlich `wallet {available, applied, rest, wallet_id, organizer_id[, reason: login_required\|no_wallet\|locked\|topup\|scope\|zero]}` (Euro); deckt das Guthaben alles, ist `payment_methods = [{id:"wallet", title:"Guthaben"}]`. Bereich: Seite (Ein-Veranstalter) bzw. die Geldbörse des Veranstalters, dem alle Events des Warenkorbs gehören (evendis); Aufladungen nie mit Guthaben. Bestellung: Guthaben-Anteil wird beim Anlegen atomar abgebucht (`charge`, channel `app`, `order_id`, Idempotenz `order:<id>:wallet`, erst bezahlt dann Bonus); Bestellsumme = Rest (wie Geschenkgutscheine; der Guthaben-Anteil steht in der Buchung und als Notiz). Rest 0 → Zahlart `wallet` („Guthaben“), sofort bezahlt; sonst Rest über die gewählte Zahlart. Antwort zusätzlich `wallet {applied, rest, wallet_id}`. Reicht das Guthaben beim Anlegen nicht mehr (zweite Bestellung zugleich) → Bestellung `failed`, 409 `tix_wallet_insufficient`. Rückbuchung (genau die Aufteilung, nur einmal, Mitteilung „… zurück ins Guthaben“) bei `failed`, `cancelled` (auch Mollie „expired“/„canceled“) und `refunded`; Cron `tix_wallet_pay_timeout` storniert stündlich offene Bestellungen, deren Restzahlung nach 3 h nicht kam. | alle | 1.38.436 |
| Gutschein ins Guthaben | Seit 1.38.436: **`POST /wallet/redeem-giftcard {code[, organizer]}`** (Token) → `{ok, amount, code, wallet{…}, tx{…, title:"Gutschein eingelöst"}}`. `code` = Gutschein-Code `GS-XXXX-XXXX` (Groß/klein, Leerzeichen egal) **oder** der Ticket-QR des Gutschein-Tickets `GL-<event>-<ticketcode>` (steht schon in Mail, PDF und Ticket-Ansicht); die Gutschein-Ansicht zeigt zusätzlich einen QR mit dem Code. Restwert → Buchung `topup` (bezahlter Anteil, channel `giftcard`) in die Geldbörse des Bereichs (Gutschein der Seite → Seite; Gutschein eines Veranstalters → dessen Geldbörse), Gutschein danach aufgebraucht (Einlösung „wallet“ am Gutschein). Atomar per Datenbank-Sperre, nur einmal. Fehler: 404 `tix_giftcard_unknown`, 410 `tix_giftcard_expired`, 409 `tix_giftcard_used`, 409 `tix_giftcard_scope` (anderer Bereich bzw. dort kein Guthaben), 409 `tix_wallet_limit_balance` (Höchstguthaben; Gutschein bleibt unverändert), 423 `tix_wallet_locked`, 429. Einlösung an Kasse/Bar und als Gutschein in der Kasse unverändert. | alle | 1.38.436 |
| Nur eintragen – Tickets bei einem anderen Shop | Seit 1.38.436: vierte Ticket-Art **`external`** neben `online`, `free`, `box_office` (Logik `TIX_Register_Event::ticket_mode_of()` / `apply_ticket_mode()`). Speichert in die Felder des externen Shops (`_tix_extshop_enabled/url/mode=replace/text`, neu `_tix_extshop_price`), Online-Verkauf aus; Wechsel auf eine andere Art schaltet den Shop wieder ab (außer Modus „both“ aus dem Editor). URL nur https mit echtem Host (sonst 400 `tix_invalid_field` `external_shop.url`). `[tix_register_event]`: Wahl „Nur eintragen – Tickets bei einem anderen Shop“ mit URL (Pflicht) und „Preis ab“ (`external_url`, `external_price`). Staff-Routen: `GET /events/form` und `GET /events/{id}/edit` → zusätzlich `ticket_mode` (online\|free\|box_office\|external\|""), `box_office_price` (Zahl\|null), `external_shop {url, price_from, text}`; `POST /events` und `POST /events/{id}` nehmen `ticket_mode` + `box_office_price` bzw. `external_shop {url, price_from}`. Event-Detail `external_shop` zusätzlich `price_from` (Zahl\|null). URL-Analyse: `fields.external_shop_url` (+ `external_shop_price`), wenn die Seite ein bekannter Ticketshop ist (Eventbrite, Eventim, Reservix, RA, Ticketmaster, Dice, Pretix …) oder ihre Event-Daten dorthin verlinken; das Formular wählt dann „anderer Shop“ vor. | alle | 1.38.436 |
| evendis Studio – Anmeldung per Einmal-Code | Seit 1.38.437 (`TIX_Studio`). Schalter: Option **`tix_studio_url`** (Basis-Adresse, z. B. `https://veranstalter.evendis.de/studio`; leer = altes Verhalten, nichts davon aktiv). Nach dem WordPress-Login (`wp-login.php`) eines Veranstalters bzw. Team-Kontos (Rolle `tix_organizer` oder einem Veranstalter zugeordnet, **kein** Admin) ohne ausdrückliches Ziel (bzw. Ziel `wp-admin/`, `admin.php?page=tixomat`, `page=tix-org-account`, `profile.php`) → Weiterleitung auf **`<studio>/anmelden/?code=<einmal>`**. Ein ausdrücklich verlangtes wp-admin-Ziel (z. B. Experten-Modus-Link `post.php?post=<id>&action=edit`) bleibt erhalten. Nach erfolgreicher Registrierung auf `/veranstalter-werden/` (`tix_register_and_publish`) ist `dashboard_url` dieselbe Studio-Adresse mit Code (das Formular leitet nach 5 s weiter). Code: 40 Zeichen, **60 s** gültig, **einmal** nutzbar (atomar), an den Nutzer gebunden; gespeichert nur als SHA-256 (`wp_options` `tix_sho_<hash>`, autoload no, Abgelaufenes wird beim nächsten Ausstellen gelöscht). **`POST /auth/handoff {code, device}`** → `{success, token, user}` genau wie `/auth/login` (Geräte-Token 90 Tage, `device` Vorgabe „evendis Studio“), optional `next`. Ungültig/abgelaufen/benutzt → 401 `tix_handoff_invalid`; 20 Versuche/Minute je IP → 429 `tix_rate_limit`. Admins werden nicht umgeleitet, dürfen den Weg aber nutzen: Admin-Leiste „evendis Studio“ bzw. **`/wp-admin/admin-post.php?action=tix_studio_open[&next=/studio/…]`** (angemeldet → frischer Code → `<studio>/anmelden/?code=…[&next=…]`; `next` nur relative Pfade unter dem Studio-Pfad). | evendis | 1.38.437 |
| Experten-Modus (klassische Veranstalter-Seite) | Seit 1.38.437, nur mit `tix_studio_url`: Klassische Seite = **`https://veranstalter.evendis.de/wp-admin/admin.php?page=tixomat`** (Event: `…/wp-admin/post.php?post=<id>&action=edit`). Für Veranstalter/Team-Konten (nicht Admins) feste Leiste oben (56 px, Nachtblau #2C2F3F, Akzent #FC2F55, Figtree mit Manrope-Rückfall – Figtree ist nicht lokal eingebunden): Wortmarke „evendis.“ → `<studio>/`, Hinweis „Experten-Modus“, Knopf **„← Zurück zur einfachen Ansicht“** → `<studio>/` bzw. aus einem Event heraus (`post=<id>`, `event_id`, `event`) → **`<studio>/events/<id>/`**. WP-Admin-Leiste, „Hilfe/Ansicht anpassen“ und Fußzeile ausgeblendet; Menüs Dashboard, Beiträge, Kommentare, Werkzeuge, Design, Plugins, Einstellungen entfernt (Rolle `tix_organizer` sah schon vorher nur Tixomat, Medien, Profil). Admin-Shell: Seitenleiste rückt unter die Leiste, Handy ohne Lücke. | evendis | 1.38.437 |
| Aufgaben für die Studio-Startseite | Seit 1.38.437: **`GET /organizer/tasks`** (Token; Inhaber und Team-Konten auch während der Prüfung; Admins `?organizer=<id>`, sonst 400; ohne Mehr-Veranstalter-Modus 403 `tix_not_multi`; ohne Veranstalter 403 `no_organizer`) → `{organizer_id, open, all_done, tasks:[{key, title, done, target, hint[, event_id][, status]}]}`. Aufgaben: `payout` „Auszahlungsdaten hinterlegen“ (Firma/Name, Anschrift, Steuer-Status, IBAN – `hint` nennt das Fehlende), `terms` „Vertrag annehmen“ (nur wenn eine Vertragsversion gilt; im Vorlagen-Modus auch bei veralteter Zustimmung offen), `profile` „Profil vervollständigen“ (Telefon, Logo, Beschreibung), je Entwurf `draft:<id>` „Entwurf fertigstellen: <Titel>“ (`target: event`, `event_id`; bis 5, ohne die wegen der Freigabe zurückgehaltenen Events), `approval` (Titel je Status: „Wartet auf Freigabe“, „Angaben ergänzen und erneut einreichen“, „Konto gesperrt“, „Konto freigegeben“; `status`). `target` ∈ `payout`, `terms`, `profile`, `event`, `approval` – das Studio ordnet seine Seiten zu. | evendis | 1.38.437 |
| Studio: Sparte, Veranstalter-Link, Code-Login nur für Staff | Seit 1.38.438. **Sparte im Event-Editor:** `GET /events/form` und `GET /events/{id}/edit` → zusätzlich `event_categories [{id, slug, name}]` (Taxonomie `event_category`, alle, nach Name) und `event.event_category` (Slug, `""` = keine). `POST /events` und `POST /events/{id}` nehmen `event_category` (Slug oder ID; `""` entfernt; Alias `category` als Text); unbekannt → 400 `tix_invalid_field` `field: event_category`, geprüft vor allen Änderungen (neues Event wird nicht angelegt). Ohne Feld bleibt die Sparte. (`categories` bleibt die Liste der Ticketarten.) **`/me`:** `organizer.slug` (eigener Landing-Slug vor `post_name`) und `organizer.link` (`<Kunden-Adresse>/v/<slug>/`). **Code-Login nur für Staff:** `POST /auth/code` und `/auth/code/confirm` mit **`context: "staff"`** melden nur vorhandene Admin-, Veranstalter- und Team-Konten an (Rollen `tix_organizer`, `tix_staff`, `tix_entrance`, `tix_dj`, `tix_awareness` oder einem Veranstalter zugeordnet); sonst 404 `tix_staff_not_found`, es entsteht **kein** Kundenkonto. Rollen werden beim Code-Login nie geändert (auch ohne `context`). Ohne `context` unverändert (Gast mit Bestellung bekommt ein Konto). | evendis | 1.38.438 |
| Studio Stufe 2: Pakete, Mengenrabatt, Duplizieren, Import | Seit 1.38.441 (`TIX_App_Events`). **Pakete:** `GET /events/form` und `/events/{id}/edit` → je Kategorie `bundle {buy, pay, label}` oder `null` (Meta `bundle_buy/pay/label`, wirkt in der Kasse über `TIX_Cart_Pricing`). **Mengenrabatt:** am Event `group_discount {enabled, tiers[{min_qty, percent}], combine_bundle, combine_combo, combine_phase}` (immer ein Objekt; Meta `_tix_group_discount`). `POST /events[/{id}]` übernimmt beides nur, wenn mitgeschickt (`bundle: null` bzw. `group_discount: null` schaltet ab; `enabled: false` behält die Staffeln). Prüfung vor allen Änderungen: Paket `buy` 2–100, `pay` ≥ 1 und < `buy`, `label` bis 60 Zeichen; Staffel `min_qty` 2–1000 (jede nur einmal), `percent` 1–99, höchstens 10 Staffeln, `enabled` braucht eine Staffel → sonst 400 `tix_invalid_field` mit `field` (z. B. `categories[0].bundle`, `group_discount.tiers[1].percent`). Kombi-Tickets (`combos[]`) nicht über die App. **`POST /events/{id}/duplicate {title?, date_start?, date_end?}`** (Staff, Zugriff aufs Event) → `{ok, event, edit}`: Entwurf mit allen Feldern, Kategorien (Kontingent wieder voll), Sparte, Bild und Awareness; ohne Gästeliste, Bestellungen, Verkaufs- und Feedback-Zähler, Sync, Serien und Freigabe-Merker; Titel ohne Angabe „<Titel> (Kopie)“; nur `date_start` verschiebt das Ende um denselben Abstand; Datum nicht JJJJ-MM-TT → 400. Gleiche Regeln für „Duplizieren“ in wp-admin (`TIX_Columns::duplicate_event`). **`POST /events/import-url {url}`** (Staff, 10 je 5 min je Konto) → `{ok, source: structured\|structured+ai\|ai, fields{title, excerpt, description, lineup, specials, extra_info, age_limit, date_start, date_end, time_start, time_end, time_doors, location, location_address, location_id, category_ids, event_category (Slug), tickets[{name, price, description}], faq[], external_shop_url, external_shop_price, suggested_image_id, suggested_image_url}}`; Fehler 400 `url_invalid`, 422 `url_unreadable`, 503 `ai_unavailable`, 429. Danach legt das Studio selbst über `POST /events` an. | evendis | 1.38.441 |
| Studio Stufe 2: Erstatten, Stornieren, Bestellliste | Seit 1.38.441. **`POST /orders/{id}/refund {amount?, cancel_tickets?, reason?}`** (Admin; Mehr-Veranstalter Inhaber/Team-Admin; sonst Rolle Veranstalter; nur Bestellungen eigener Events) → `{ok, refunded, full, tickets_cancelled, order{status, total, refunded_total}}`. Ohne `amount` wird der noch offene Betrag erstattet; Teilbeträge zählen zusammen (`refunded_total`), sind alle erstattet → Status `refunded`. `cancel_tickets` Vorgabe: ohne `amount` true, mit `amount` false. Zahlungsanbieter wie in wp-admin (Mollie, Stripe, PayPal; Überweisung/kostenlos ohne API). Bestellung ohne Restbetrag (ganz mit Guthaben bzw. kostenlos): Erstattung = Status `refunded`, das Guthaben bucht die vorhandene Rückbuchung zurück. Fehler: 409 `already_refunded`, 409 `not_paid`, 409 `pos_order` (Kasse über `/pos/orders/{id}/void`), 400 `amount_invalid` (u. a. mehr als offen), 502 `gateway_error`, 403. Gleicher Kern für die Admin-Erstattung (`TIX_Order_Admin::process_refund`; neu: Teilerstattungen höchstens bis zum offenen Betrag). **`POST /orders/{id}/cancel`** (gleiche Rechte) für unbezahlte Bestellungen (`pending`, `on-hold`) → Status `cancelled` (Tickets/Bestand/Guthaben wie bisher); bezahlt → 409 `already_paid`, schon storniert → 409 `already_cancelled`. **`GET /events/{id}/orders`** zusätzlich `page`, `per_page` (1–100, Vorgabe 25), `status` (`paid` = completed/processing, `open` = pending/on-hold, `cancelled` = cancelled/refunded/failed, `all`), `search` (Nummer, Name, E-Mail, ID) → zusätzlich `total`, `page`, `per_page`, `has_more`, `counts{all, paid, open, cancelled}`; ohne `page`/`per_page` wie bisher bis 200. | evendis | 1.38.441 |
| Team: Einladung, Rolle „Kasse & Bar“ | Seit 1.38.441 (`TIX_Team`). `GET /team` → `roles[]` jetzt `admin`, `entrance`, `cashier` („Kasse & Bar“), `awareness`, `dj`. **`POST /team {email, name?, role, invite: true}`** schickt eine Einladungs-Mail (Team, Rolle, Anmeldung per „Code per E-Mail“ ohne Passwort, bei neuem Konto zusätzlich Link „Passwort festlegen“, 24 h; für Team-Admins Link ins evendis Studio, wenn aktiv) → zusätzlich `invited`, `created`. **Rolle `cashier`** → WP-Rolle `tix_cashier` „Kasse & Bar (App)“ (Rechte `tix_app_pos`, `tix_app_wallet_charge`): darf alle `/pos/*` (Kategorien, Verkauf, E-Mail, Storno, Tagesbericht, Buchungen) und Guthaben abbuchen/aufladen (`/wallet/*`-Personal-Routen), sonst nichts aus dem Veranstalter-Bereich (Events, Bestellungen, Statistik, Auszahlung, Einstellungen → 403). Neu **`GET /pos/events`** (Staff und Kasse) → `{events[{id, title, date_start, time_start, date_end, location}]}` (laufend bis +30 Tage, Mehr-Veranstalter nur eigene). `/me` → `user.app_role` (`admin_wp`\|`admin`\|`cashier`\|`entrance`\|`awareness`\|`dj`\|`staff`\|null). Code-Login `context: staff` kennt `tix_cashier`. | alle | 1.38.441 |
| Awareness-Einstellungen gesammelt | Seit 1.38.441: `GET /awareness/settings` (Ziel Veranstalter bzw. Seite) zusätzlich `events[{id, title, date_start, mode, effective}]` (heute bis +90 Tage, höchstens 50, eigene). `POST /awareness/settings {events: [{id, mode: inherit\|custom\|off}]}` setzt den Modus gesammelt (vor dem Speichern geprüft: falscher Modus 400, fremdes Event 403). | alle | 1.38.441 |
| „Veranstalter werden“ per REST (Studio) | Seit 1.38.442 (`TIX_Register_API`, Kern `TIX_Register_Event::register()` für Formular und REST). **`GET /register/config`** → `{review, terms{title, url, version, required}, ticket_modes[online, free, box_office, external], bot{type: pow\|turnstile, difficulty, turnstile_site_key, honeypot: "website"}, limits{image_bytes, text_min, text_max, password_min}}`. **`GET /register/challenge`** → `{challenge, difficulty, expires_in: 900}` (30 je 10 min je IP). **Bot-Schutz** für jede POST-Anfrage: Feld `website` muss leer sein; ohne Turnstile `challenge` + `pow` (sha256(`challenge + ":" + pow`) beginnt mit `difficulty` Null-Bits, Vorgabe 16, Option `tix_register_pow_difficulty`; Aufgabe 15 min gültig, nur einmal); mit Option `tix_turnstile {site_key, secret_key}` stattdessen `turnstile` (Cloudflare-Token, serverseitig geprüft mit Besucher-IP). Fehler 403 `tix_bot_check`. KI läuft erst nach bestandener Prüfung. **`POST /register/analyze {mode: describe\|flyer\|url, text\|image\|url}`** → `{ok, mode, source, fields{title, description, lineup, specials, extra_info, age_limit, date_start, date_end, time_start, time_end, time_doors, location, location_address, location_id, excerpt, event_type, event_category, category_ids, tickets[], faq[], external_shop_url?, external_shop_price?}, missing[title\|date_start\|time_start\|location], image{id, url}\|null}`; `describe`: Text 10–4000 Zeichen; `flyer`: `image` als Datei (multipart) oder Data-URL/Base64, JPG/PNG/WebP/GIF bis 5 MB (wird vorläufig gespeichert und mit `image_id` bei der Registrierung zum Event-Bild, sonst nach 24 h gelöscht); `url`: wie `/events/import-url`. Fehler 400 `tix_invalid_field` (`mode`, `text`, `image`), 400 `url_invalid`, 422 `url_unreadable`, 503 `ai_unavailable` (mit `alternatives`, `image`), 429 `tix_rate_limit` (6 je 10 min, 30 je Tag und IP), 429 `tix_ai_budget` (Option `tix_register_ai_daily`, Vorgabe 300 Analysen je Tag für alle). **`POST /register/organizer {first_name, last_name, email, password, password_confirm?, organizer_name, accept_contract, ticket_mode, box_office_price?, external_url?, external_price?, event{title, date_start, …, tickets[], event_type}, image_id?, device?}`** + Bot-Schutz → 201 `{ok, token, user, organizer_id, event_id, review, heading, message, event_url, approval?}` (Konto, Veranstalter und erstes Event genau wie `[tix_register_event]`: Freigabe wartet, Vertrag angenommen, alle vier Ticket-Arten; Geräte-Token wie `/auth/login`, keine Code-Bestätigung – auch das Formular meldet sofort an). Fehler 400 `tix_invalid_field` mit `field` (u. a. `accept_contract`, `external_url`, `event.title`), 409 `tix_email_exists`, 429 (3 je Stunde, 10 je Tag und IP). **Besucher-IP:** über `TIX_Client_IP` – Proxy-Kopfzeilen (`X-Real-IP`, `X-Forwarded-For`) zählen nur von `tix_trusted_proxies` (evendis: Hetzner 46.224.181.160 ist eingetragen); das Studio muss die Besucher-IP als `X-Real-IP` mitschicken. **Schalter `tix_studio_register` = 1** (nur mit `tix_studio_url`): Seiten mit `[tix_register_event]` leiten per 302 auf `<studio>/registrieren/` weiter (Parameter bleiben, `?classic=1` zeigt das alte Formular). | evendis | 1.38.442 |
| Kasse & Bar ohne Berichte | Seit 1.38.442 (User-Entscheid): `/pos/report`, `/pos/transactions` und `/wallet/admin/report` nur noch Admin, Veranstalter, Team-Admin und Mitarbeiter; die Rolle `cashier` bekommt 403 und darf nur verkaufen (`/pos/events`, Kategorien, Verkauf, E-Mail, Storno) und Guthaben buchen. Außerdem: Der Chat „Beschreibe dein Event“ ruft die KI je Nachricht nur noch einmal auf (vorher doppelt). | alle | 1.38.442 |
| Registrierung: Preis und Anzahl | Seit 1.38.443: Der Registrierungs-Kern (`TIX_Register_Event::register()`, Formular und `POST /register/organizer`) übernimmt den Preis bei Abendkasse (`box_office_price`) bzw. „anderer Shop“ (`external_price`) aus den Eingaben (vorher nur aus `$_POST`, bei JSON verloren) und je Ticketart `qty` (Alias `quantity`; 0/leer = unbegrenzt, sonst Bestand = Anzahl), `price` (Komma erlaubt) und `desc` (Alias `description`). | alle | 1.38.443 |
| Import: Ticketarten | Seit 1.38.444 (`TIX_Event_Import`, `TIX_AI_Writer`). `fields.tickets[]` in `/register/analyze` und `/events/import-url` (auch KI-Wege) in fester Form **`{name, price (Zahl), currency (ISO, Vorgabe EUR)[, qty, description, sale_start (JJJJ-MM-TT HH:MM), sale_end, available]}`**, höchstens 20. Quellen der Reihe nach: (1) JSON-LD `offers` als Liste einzelner `Offer` (Name, Preis, Währung, `inventoryLevel`, `availability`, `availabilityStarts/Ends`; `AggregateOffer` mit einzelnen `offers` wird aufgelöst); (2) **Eventbrite**: öffentliches Checkout-Widget `https://<gleiche Eventbrite-Domain>/checkout-external?eid=<id>` (ohne Schlüssel; Preis = Netto ohne Eventbrite-Gebühr, `available` aus dem Verkaufsstatus); (3) **Pretix**: öffentliche Widget-Quelle `<shop>/<veranstalter>/<event>/widget/product_list` (Bruttopreis, Varianten einzeln, Kategorien wie Merchandise/Getränke ausgelassen); (4) KI mit der Anweisung „jede Ticketart einzeln mit Namen, keine ab/bis-Einträge“ (KI-Einträge „Ticket ab/bis/von“ werden verworfen). Benannte Ticketarten der Seite bzw. des Shops haben Vorrang vor der KI. **Nur ein `AggregateOffer`** (Spanne ohne Namen): genau EINE Ticketart `{name: "Ticket", price: <niedrigster Preis>, currency}` plus **`fields.price_range {min, max, currency}`** als Hinweis „Bitte Ticketarten prüfen“ (nennt die KI mindestens zwei benannte Ticketarten, gelten diese und `price_range` ist `null`). Neu außerdem `fields.tickets_source` (`jsonld`\|`eventbrite`\|`pretix`\|`ai`\|`range`\|`none`). Ticket Tailor, Reservix u. a. liefern einzelne `Offer` im JSON-LD (Weg 1). | alle | 1.38.444 |
| Studio auf allen Seiten: Marke und Funktionen | Seit 1.38.445 (`TIX_Studio`). **`GET /studio/config`** (öffentlich, ohne Anmeldung `Cache-Control: public, max-age=60`) → `{ok, name, accent, logo, custom{accent, logo} (seit 1.38.446: im Studio gesetzte Werte, `null` = nur Rückfall), profile: platform\|single, features{register, money, approval, wallet, awareness, team, pos, coupons, tasks}, site_url, expert_url, studio_url}`. `name` = „<Seitenname> Studio“ (nicht änderbar). `profile` = `platform` bei `tix_multi_organizer`, sonst `single`. `accent`: gespeichert › Plattform #FC2F55 › Primärfarbe der Seite (`color_primary`, nur wenn gespeichert) › #FC2F55. `logo`: gespeichert › Custom Logo › Logo der Verwaltung (Mediathek-Markierung bzw. `admin_logo_url`) › Website-Icon. `features`: `register`/`money`/`tasks` nur Plattform, `approval` mit Veranstalter-Freigabe, `wallet` Plattform bzw. Guthaben eingeschaltet. `expert_url` = `wp-admin/admin.php?page=tixomat`. **`POST /studio/config`** (Admin; auf Ein-Veranstalter-Seiten auch Rolle Veranstalter) nimmt **nur** `accent` (Hex, #RGB oder #RRGGBB) und `logo` (Datei, multipart) bzw. `logo_id` (Mediathek); `accent: null`/`""` bzw. `logo_id: 0` setzt auf die Vorgabe zurück; andere Felder → 400 `tix_invalid_field`. Logo: PNG/JPEG/WebP/GIF, höchstens 2 MB, 16–4000 px, **SVG abgelehnt**; zu groß → 413 `tix_too_large`. Option `tix_studio_brand {accent, logo_id}`. Experten-Leiste und Admin-Eintrag nutzen Name, Logo und Akzent der Seite (Plattform weiter „evendis.“). | alle | 1.38.445 |
| Studio Stufe 3 (Teil 1): Programm, Restbestand, Übertragung, Sponsor, Orte | Seit 1.38.448 (`TIX_Studio_Editor`). Additiv in `GET /events/form`, `GET /events/{id}/edit` (`event`) und `POST /events[/{id}]`, nur mitgeschickte Schlüssel; Prüfung vor allen Änderungen (400 `tix_invalid_field` mit `field`). **`timetable {times_tba, stages[{name, color}], days{"JJJJ-MM-TT": [{time, end, stage, title, desc}]}}`** (Metas `_tix_timetable`, `_tix_stages`, `_tix_timetable_times_tba`; Teilschlüssel einzeln; Zeiten HH:MM, nach Zeit sortiert, leere Zeilen fallen weg; `stage` = Index der Bühne; höchstens 20 Bühnen, 200 Punkte je Tag; `null` löscht alles). **`low_stock {mode: global\|custom\|manual\|off, threshold (1–9999, bei custom), text (≤ 80, Pflicht bei manual)}`** + `low_stock_global` (nur lesend). **`ticket_transfer`** (bool) + `ticket_transfer_available` (globaler Schalter, nur lesend). **`ticket_sponsor {image, image_id, link}`** (Mediathek-Bild – im Mehr-Veranstalter-Modus nur eigene bzw. am Event; oder `image` als https-Adresse; `link` http(s); `null` löscht). **`POST /events/{id}/sponsor-image`** (multipart `image`, JPG/PNG/WebP ≤ 5 MB) → `{ok, image_id, image}`. **Orte:** `GET /locations` → `{locations: [{id, title, address, zip, city, country, lat, lng, maps_url, events, editable}]}`; `POST /locations` (201) und `POST /locations/{id}` `{title, address, zip, city, country, lat?, lng?}` – Adresse geändert → Geokodierung wie bisher, Koordinaten von Hand schalten die automatische ab; `DELETE /locations/{id}` nur ohne Events (sonst 409 `tix_location_in_use`). Lesen: Staff; ändern: Admin bzw. Rolle Veranstalter. Mehr-Veranstalter: eigene Orte (selbst angelegt oder dem eigenen Veranstalter zugeordnet – neue Orte bekommen `_tix_loc_organizer_id`) änderbar, in der App gelistete Orte anderer nur lesend (`editable: false`), sonstige fremde nicht sichtbar. Editor-Liste `locations` zusätzlich `city`. | alle | 1.38.448 |
| Studio Stufe 3 (Teil 2): Extras, Kombis, Verteilung, Tische | Seit 1.38.449 (`TIX_Studio_Editor`), gleiche Regeln wie Teil 1. **`specials {available, in_selector, in_checkout, items[{special_id, enabled, price_override, qty_override}]}`** + **`specials_catalog[{id, name, description, price, value, qty, image, editable}]`** (nur lesend). Nur erlaubte Extras (Admin/Ein-Veranstalter: alle aktiven; Mehr-Veranstalter: eigene – `_tix_special_organizer_id` bzw. selbst angelegt – und vom Admin freigegebene `_tix_special_shared = 1`), sonst 400 (`specials.items[i].special_id`); Extras auf der Seite aus → 409 `tix_specials_unavailable`. **`GET /specials`**, **`POST /specials`** (201) bzw. **`POST /specials/{id}`** `{name, description, price, value, qty}` (Admin bzw. Rolle Veranstalter; nur eigene ändern). **`combo_deals[{id, label, price, self_cat_index, partners[{event_id, cat_index}]}]`** (`null`/`[]` leert; Name Pflicht, Preis > 0, eigene Ticketart vorhanden, Partner = kommendes erlaubtes Event ≠ dieses mit vorhandener Ticketart, mindestens ein Partner, höchstens 20; fehlende `id` wird vergeben) + **`combo_partner_options[{event_id, title, date_start, categories[{index, name, price}]}]`** (kommende Events ab heute, Mehr-Veranstalter nur eigene). **`syndication {available, active, partner_sales, partner_sales_available, status, last, error}`** (`available` nur auf Einzel-Veranstalter-Seiten mit eingerichteter Verteilung); POST `syndicate` / `partner_sales` (oder `syndication {active, partner_sales}`) wie in der Metabox – Push bzw. Löschen über `TIX_Syndication_Push::on_save` (genau einmal); nicht verfügbar → 409 `tix_syndication_unavailable`. **`table_reservation {available, enabled, payment_mode: deposit\|full\|on_site, deposit_type: percent\|fixed, deposit_value, info_text, show_in_checkout, categories[{index, name, desc, min_spend, min_guests, max_guests, quantity, tables (Anzahl, nur lesend)}], has_floor_plan}`**: Teilschlüssel einzeln; Kategorien nach `index` zusammengeführt, neue ohne `index` hinten; Tische und Grundriss bleiben; Löschen einer Kategorie, hinter der Reservierungen hängen → 409 `table_category_in_use`, Umsortieren → 409 `table_category_order`; Tischreservierung auf der Seite aus → 409. **Fehler behoben:** Der Breakdance-Abgleich (`TIX_Sync`) schrieb den Text der Info-Sektion „Specials“ nach `_tix_specials` und löschte damit bei jedem Speichern über App/REST die Extra-Zuordnung; eine vorhandene Zuordnung (Liste) bleibt jetzt stehen. | alle | 1.38.449 |
| Deine Veranstalterseite (Partner-Brücke) | Seit 1.38.450 (`TIX_Partner_Page`). **Partner-Seite** (Ein-Veranstalter-Betrieb, z. B. KitchenKlub, Mallorca; Admin bzw. Rolle Veranstalter, sonst 403): **`GET /partner/evendis-page`** → Profil wie `GET /organizer/profile` (vom Partner-Eintrag auf evendis.de) + `{connected: true, page_url, slug}`; ohne Verbindung `{ok, connected: false, reason: not_configured\|unauthorized\|no_organizer\|unreachable\|unsupported, message}` (immer 200). **`POST /partner/evendis-page`** mit denselben Feldern wie `POST /organizer/profile` → gleiche Antwort; Prüffehler von evendis werden mit Code/Status/`field` durchgereicht. **`POST /partner/evendis-page/image`** (multipart `file`, `kind` = logo\|hero; JPG/PNG/WebP ≤ 5 MB, sonst 400/413). Weitergereicht über die Verteilungs-Verbindung: `syndication_api_url` + Partner-Schlüssel `syndication_api_key` (Kopfzeile `X-Tix-Syndication-Key`). **Plattform** (evendis.de, Mehr-Veranstalter): `GET/POST /syndicate/organizer`, `POST /syndicate/organizer/image {kind, filename, data (Base64)}` – nur mit dem eigenen Schlüssel eines Partners aus dem Partner-Verzeichnis (`key_in`; der gemeinsame Schlüssel und Nutzer-Logins gelten nicht → 401); der Partner liest und ändert genau den ihm zugeordneten Veranstalter (`organizer_id`; eine mitgeschickte ID wird ignoriert), sonst 404 `tix_partner_no_organizer`. Antwort = Profil + `slug`, `page_url` (`<Kunden-Adresse>/veranstalter/<slug>/`); keine Schlüssel in Antworten. Ratenbegrenzung: 120 Anfragen je 10 min je Partner, 20 Bilder je Stunde, Fehlversuche ab 30 je 10 min und IP → 429. Bilder geprüft wie das Logo (JPG/PNG/WebP, ≤ 5 MB, 16–6000 px). Gleicher Speicher-Kern wie `/organizer/profile` (`TIX_App_Scope::save_profile()`, `profile_for()`, `store_profile_image()`). | alle | 1.38.450 |
| Support-Modell, Studio-Postfach, Rückfragen | Seit 1.38.451 (`TIX_Support_Model`, siehe §46). **Kunden-Sicht:** `GET /support/config[?event_id=]` zusätzlich `responsible {type: organizer\|evendis, name[, on_behalf]}` – Einzelseite `self`: `{organizer, "<Seite>-Team"}`, mit Paket `{evendis, "evendis-Support", on_behalf: "<Seite>"}`; evendis: `{evendis, "evendis-Support"}`, für ein geteiltes Event `{organizer, "<Veranstalter>"}` bzw. mit Paket `on_behalf`. **Postfach** (Staff wie `check_organizer`): `GET /support/inbox?status=open\|progress\|resolved\|closed&event_id&order_id&category&assignee=me\|none\|<id>&search&page&per_page` → `{ok, mode: self\|evendis\|evendis_package\|platform, can_reply, total, page, per_page, has_more, tickets:[{id, subject, status, category, name, email, order_id, event_id, source, responsible, origin: local\|platform, assignee{id,name}\|null, last_message{type,date,preview}\|null, created, updated, can_reply}]}`; `GET /support/inbox/{id}` → `{ok, ticket: {…, messages (inkl. type note), ticket_code, inquiries, team:[{id,name}]}}`; `POST /support/inbox/{id}/reply {content \| template_id}` (Mail + Push an den Kunden; Kopie einer evendis-Anfrage: über evendis) → `{ok, message, ticket}`; `POST …/status {status: open\|progress\|resolved\|closed}`; `POST …/assign {user_id\|0}` (Push an die Person); `POST …/note {content}`. **Rechte:** Einzelseite `self`: Admin, Veranstalter, Mitarbeiter bearbeiten; `evendis_package`: Team liest nur (Kopien geteilter Events bleiben bearbeitbar), 403 `rest_forbidden`. Plattform: Admin alles; Veranstalter sehen nur Anfragen ihres Veranstalters ohne Notizen, `can_reply: false`; Awareness-Meldungen nur Admin. **Textbausteine** (nur wo das Team antwortet): `GET /support/templates[?ticket_id=]` → `{placeholders: ["{{first_name}}", …], templates:[{id, title, body, categories, shortcut, rendered?}]}`, `POST /support/templates {id?, title, body ≤ 5000, categories?, shortcut?}`, `DELETE /support/templates/{id}`. **Team-Hinweis** bei neuer Anfrage: App-Feed + Push `type: support_team`, Aktion `support:inbox:<id>` (Einzelseite `self`: Admins, Veranstalter, Mitarbeiter; Plattform: Admins für evendis-Anfragen); Mail an `admin_email` wie bisher. **Rückfragen** (evendis → Veranstalter): `POST /support/inbox/{id}/inquiry {question, kind: info\|refund, amount (bei refund), due_hours 1–240, Vorgabe 48}` (nur Admin; ohne Veranstalter 409 `tix_no_organizer`) → `{inquiry {id, kind, question, amount, organizer_id, status: open\|answered, due, answer, approved, …}}`, Push `type: support_inquiry` (Aktion `support:inquiry:<ticket>`) + Mail an den Veranstalter; `GET /support/inquiries?status=open\|answered\|all` (Veranstalter: eigene, mit `ticket_id, subject, order_id, event, overdue`); `POST /support/inquiries/{ticket}/{inquiry}/answer {answer, approve (Pflicht bei refund)}` (Inhaber bzw. Team-Admin; zweimal 409 `tix_inquiry_answered`). **Modus:** `GET /support/mode` → `{site_mode, platform, organizer_mode?}`, `POST /support/mode {mode, organizer_id?}` nur Admin. **Brücke** (Server zu Server): Partner-Seite `POST /partner/support/inbound`, `POST /partner/support/{id}/messages` (Kopfzeilen `X-Tix-Partner-Id` + `X-Tix-Partner-Key` wie die Partner-API, sonst 401; auf der Plattform 404); evendis `POST /syndicate/support/{id}/reply`, `POST /syndicate/support/{id}/status` (nur der eigene Partner-Schlüssel `X-Tix-Syndication-Key` und nur Anfragen dieses Partners, sonst 401/404; Ratenbegrenzung 300 je 10 min). Ungültige Felder 400 `tix_invalid_field` mit `field`. Anhänge aus der Partner-Kopie gehen noch nicht an den Kunden. | alle | 1.38.451 |
| Studio-Postfach: Zähler, Kunde, Rechte, Benachrichtigung | Seit 1.38.452 (`TIX_Support_Model`), ergänzt die Zeile 1.38.451. **Rechte neu:** Postfach, Textbausteine, Rückfragen und `/support/notify` nur für Admins sowie Inhaber und Team-Admins (Rolle Veranstalter; Plattform: `is_org_manager`). Einlass, Eingang, DJ, Awareness, Kasse und „Mitarbeiter (App)“ (`tix_staff`) → 403 `rest_forbidden`. Zuweisung und Team = Admins + Team-Admins (Plattform: Admins). Plattform-Veranstalter lesen nur (ohne Notizen), die Kunden-E-Mail ist sichtbar. **`GET /support/inbox`** zusätzlich `counts {open, progress, resolved, closed, mine, unassigned, waiting, unread}`. Die vier Status zählen alle sichtbaren Anfragen ohne Awareness; `mine`, `unassigned`, `waiting` (wartet auf uns) und `unread` zählen nur offene und in Arbeit. Neuer Filter `waiting=team\|customer`. Je Zeile neu: `event {id, title, date}\|null`, `order {id, number, event_id}\|null`, `waiting: team\|customer` (team = letzte Nachricht vom Kunden bzw. noch keine Antwort; Notizen zählen nicht), `unread` (je Mitarbeiter, nur offen bzw. in Arbeit und wartet auf uns). `order_id` und `event_id` bleiben. **`GET /support/inbox/counts`** → `{ok, counts}` (eine SQL-Abfrage, für den Menüpunkt). **`POST /support/inbox/{id}/read {unread?: true}`** → `{ok, ticket}`; `GET /support/inbox/{id}` und eine Antwort markieren gelesen. Detail zusätzlich `customer {has_account, orders: [{id, number, event_id, event_title, date (ISO), total, status}]}` mit den letzten 5 Bestellungen zur E-Mail (Plattform-Veranstalter: nur Bestellungen eigener Events). **`POST …/reply`** nimmt optional `status` (open\|progress\|resolved\|closed) an; geprüft vor dem Speichern. Bei Antwort + Status geht nur die Antwort-Mail raus, keine zweite „gelöst“-Mail. **`GET /support/notify`** → `{scope: site\|organizer, event: new_ticket\|inquiry, default, user_ids, candidates: [{id, name, selected}]}`; **`POST /support/notify {user_ids: [..]}`** (`[]` = niemand) bzw. `{reset: true}` (Vorgabe). Seite: wer bei neuen Anfragen Push + Mail bekommt (Vorgabe: Admins + Team-Admins; `admin_email` bekommt wie bisher die Hinweis-Mail). Plattform-Veranstalter: wer Rückfragen bekommt (Vorgabe: Inhaber + Team-Admins; die Veranstalter-Mail bleibt). Fremde IDs → 400 `field: user_ids[i]`. Mail-Eingang: Kunden-Antworten per Mail lösen jetzt `tix_support_message_added` aus und erreichen so bei geteilten Events auch die Partner-Kopie. **Fehlercodes der Support-Routen:** 401 `rest_not_logged_in`; 403 `rest_forbidden` (Rolle bzw. nur lesen), `organizer_pending`, `no_organizer`; 400 `tix_invalid_field` mit `data.field` (z. B. `content`, `status`, `user_id`, `user_ids[0]`, `question`, `amount`, `answer`, `approve`, `mode`, `title`, `body`); 404 `not_found` (Anfrage, Rückfrage, Textbaustein, Veranstalter); 409 `tix_inquiry_answered` (Rückfrage schon beantwortet), `tix_no_organizer` (Rückfrage ohne Veranstalter); 429 `tix_rate_limit` (Brücke); Brücke 401 `tix_partner_auth` bzw. `unauthorized`. **Verteilung im Event-Editor:** `syndication.active` zeigt wie die Metabox „an“, wenn noch nie entschieden wurde (neu: `decided`). `syndicate` und `partner_sales` werden nur bei einer Änderung bzw. der ersten Entscheidung geschrieben, ein unverändert mitgeschicktes Formular löst weder Push noch DELETE aus. Ohne eingerichtete Verteilung ist „aus“ kein Fehler, nur Einschalten → 409 `tix_syndication_unavailable`. | alle | 1.38.452 |
| Verteilung abgesichert, Event-Links | Seit 1.38.453 (Fall Mallorca XXL: Plattform-Event lag nach dem DELETE einer Kopie mit derselben `remote_id` im Papierkorb). Quelle: `DELETE /syndicate/{id}` und „auf Entwurf“ schicken jetzt `source_id` mit. Trägt ein anderes veröffentlichtes, verteiltes Event dieselbe `_tix_syndicate_remote_id` (Kopie aus einem Duplizier-Plugin), geht kein DELETE bzw. Entwurf raus (Log-Eintrag, `TIX_Syndication_Push::other_owner()`). · Plattform: Löschen bzw. Entwurf mit einer `source_id`, die nicht zu `_tix_source_id` passt → 409 `tix_source_mismatch`. Ältere Quellen ohne `source_id` werden wie bisher angenommen. · Ein veröffentlichter Push übernimmt das Event (`_tix_source_id` = Absender). · Push einer veröffentlichten Quelle an ein Event im Papierkorb stellt es wieder her (Antwort `restored: true`, sonst `status: "trashed"`). Auch `POST /syndicate` findet Papierkorb-Events über die `source_id`. · Doppeltes DELETE an ein Papierkorb-Event ist harmlos. · Duplizieren (wp-admin und `POST /events/{id}/duplicate`) übernimmt keine `_tix_syndicate_remote_id`, keinen Status, keinen Fehler und keinen letzten Abgleich. **Event-Links auf Einzel-Veranstalter-Seiten:** `GET /events` und `GET /events/{id}` liefern jetzt auch dort `share_url` (Event-Seite der eigenen Website mit `utm_source=veranstalter-link`, zählt in `/organizer/link-stats`; nur bei `publish`, sonst `""`) und `evendis_url` (Adresse der Event-Seite auf evendis.de, wenn das Event geteilt und abgeglichen ist, sonst `null`). Die Plattform schickt die Adresse in der Antwort von `POST/PATCH /syndicate…` als `url` mit, die Quelle merkt sie sich in `_tix_syndicate_url` (ältere geteilte Events: nach dem nächsten Abgleich). Auf evendis selbst bleibt `share_url` wie bisher, ohne `evendis_url`. | alle | 1.38.453 |
| Studio: Statistik + Export, Ticket umschreiben | Seit 1.38.454 (`TIX_Studio_Stats`). **Rechte:** Statistik, Bestellungen/Kunden/Einlass/Gutschein-Export und Umschreiben nur für Admin sowie Inhaber und Team-Admins (Plattform: `is_org_manager`; Einzelseite: Rolle Veranstalter), sonst 403 `rest_forbidden`. Einlass (Eingang) bekommt nur den Gästeliste-Export eines Events (`type=guests&event_id`; `GET /organizer/export` läuft dafür über `check_entrance`). „Mitarbeiter (App)“ (`tix_staff`) verliert auf Einzelseiten die Bestell- und Kunden-Exporte. Mehr-Veranstalter: nur eigene Events bzw. eigene Positionen. **`GET /organizer/stats?from&to[&event_id][&group=day\|week\|month]`** (Vorgabe letzte 30 Tage, höchstens 731 Tage; ungültig → 400 `tix_invalid_field` mit `field` from\|to\|group, fremdes bzw. unbekanntes Event 404 `not_found`) → `{ok, range {from, to, days, group, previous {from, to}, event_id}, totals {revenue, tickets, orders, refunds, avg_order}, previous {…gleich langer Zeitraum davor}, series [{date, revenue, tickets, orders}] (lückenlos; Woche = Montag, Monat = 1.), by_event [{id, title, date, revenue, tickets, capacity (Summe der Kategorie-Mengen, 0 = offen), checkin_rate}] (Top 50 nach Umsatz), by_channel [{key, label, orders, tickets, revenue}], by_category_top [{name, tickets, revenue}] (Top 10)}`. Umsatz und Tickets = eigene Positionen bezahlter Bestellungen (completed, processing), nach Bestelldatum. `refunds` = erstattete € der Bestellungen im Zeitraum (Teil-Erstattungen `_tix_refund_total_*` und voll erstattete, anteilig auf eigene Positionen). **Kanal** (`TIX_Studio_Stats::channel_of`, Reihenfolge): `pos` (payment_method `pos_*`) → `evendis` (über die Partner-API vermittelt, Option `_tix_order_source_<id>` = `partner:*`) → `link` (`_tix_campaign_source` = `veranstalter-link`) → `app` bzw. `web` (App-Kasse, `_tix_order_channel` = web → web) → `web` (Website-Kasse mit Kampagnen-Quelle) → `other`. **`GET /events/{id}/statistics`** bleibt unverändert (auch `by_day` = letzte 30 Tage, App-Vertrag); für Inhaber und Team-Admins zusätzlich `sales_by_day [{date, revenue, tickets, orders}]` (erster bis letzter Verkaufstag), `by_channel`, `by_source [{source, orders, revenue}]` (utm_source, ohne = direct), `checkin_by_slot [{time: "YYYY-MM-DDTHH:MM" Ortszeit, count}]` (15 Minuten, Tickets + Gästeliste), `views` (Aufrufe der Event-Seite, Kampagnen-Zähler) und `conversion` (Bestellungen / Aufrufe × 100, ohne Aufrufe `null`). **`GET /organizer/link-stats?days=&event_id=`** jetzt auch auf Einzel-Veranstalter-Seiten (Herkunft nur `utm_source=veranstalter-link`, alle Events, `link` = Startseite mit utm_source, Follower 0) und mit `event_id` (Besuche = Aufrufe der Event-Seite über den Link; Antwort zusätzlich `event_id`). **Export** `GET /organizer/export`: `type=checkins&event_id` (Uhrzeit, Typ Ticket\|Gast, Ticket-Code, Name, Kategorie, Einlass durch), `type=coupons[&event_id][&from][&to][&status]` (Datum, Bestellnr, Code, Rabatt, Bestellbetrag, Event, E-Mail, Status; nur Bestellungen mit Rabatt-Code). **Ticket umschreiben durch das Team** (Rechte: Inhaber und Team-Admins, `can_access_event`, Mehr-Veranstalter nur eigene Events und Team-Recht `view_orders`; unabhängig von `ticket_transfer_enabled` bzw. `_tix_ticket_transfer`, das Team darf immer): **`POST /tickets/{id}/transfer {first_name, last_name, email, notify?: true, create_account?: false}`** überträgt auf eine neue Person (Kern `TIX_Ticket_Transfer::transfer(…, $by_team = true, ['create_account', 'notify'])`). Standardmäßig wird kein WordPress-Konto angelegt, also geht auch keine Passwort-Mail raus; ein bestehendes Konto zur E-Mail wird verknüpft. `notify` = Mail an den neuen Inhaber, Status `transferred`. **`POST /tickets/{id}/holder {name}`** korrigiert nur den Namen (auch in der Gästeliste), ohne neue Person und ohne Mail. Antwort beider: `{ok, ticket {id, code, event_id, name, email, status}}`. Gesperrt mit 403 `tix_transfer` (Grund in `message`): eingecheckt, storniert bzw. verbraucht, Spiegel-Ticket eines geteilten Events. 400 `tix_invalid_field` mit `field` first_name\|last_name\|email\|name; 404 `not_found`. Protokoll `_tix_ticket_rename_log`, Aktion `tix_ticket_renamed` (holder). **`GET /studio/config`** zusätzlich `app {has_app, override, name, ios_url, android_url, icon}`. `has_app` gilt automatisch auf Einzel-Veranstalter-Seiten mit App-IDs (`tix_app_link_ids`), Store-Link (`tix_app_store_url` bzw. `tix_play_store_url`) oder eingerichtetem Push; auf evendis ist es false. Ein Admin übersteuert per `POST /studio/config {has_app: true|false|null}` (Option `tix_studio_has_app`, null = automatisch, sonst 403). `name` kommt aus `tix_app_name`, sonst aus dem Seitennamen. | alle | 1.38.454 |
| Info-Abschnitte, Editor-Textfelder, „Deine App“ | Seit 1.38.455 (`TIX_Event_Texts`). **Info-Abschnitte** `description`, `lineup`, `artist` (neu: Künstler-Info, Meta `_tix_info_artist`, auch als Info-Text in `TIX_Metabox::info_sections()` und im Editor `info.artist`), `specials`, `extra_info`, `accessibility` (Barrierefreiheit). **Reihenfolge und Sichtbarkeit** `_tix_info_sections` = Liste der Keys in Reihenfolge, ausgeblendete fehlen; leer bzw. alte Form ({heading, body}) = Standard (alle in dieser Reihenfolge). Gilt auf der Website (Plugin-Vorlage `single-event-content.php`, Event-Seite `tix_event_page`: ohne eigene Reihenfolge wie bisher, mit eigener alle Abschnitte oben), in Breakdance (ausgeblendet → Feld `_tix_<key>` leer; neu `_tix_artist`, `_tix_info_html` = alle sichtbaren Abschnitte in Reihenfolge, `_tix_accessibility_html`, `_tix_accessibility_label`), im Shortcode `[tix_event_info id=""]` und in der API: `GET /public/events/{id}` zusätzlich `artist` (HTML, "" wenn ausgeblendet) und `info {sections: [{key, label, html}], custom_order, accessibility}`; die bisherigen Felder (`description`, `lineup` …) bleiben unverändert. Metabox „Info-Abschnitte: Reihenfolge & Sichtbarkeit“ (Haken + ▲▼, Überschrift „Barrierefreiheit“, „Standard-Reihenfolge“). **Barrierefreiheit** (`TIX_Event_Facts`) jetzt auf allen Seiten: Metaboxen am Ort und am Event, Editor `accessibility_mode` inherit\|custom\|off + `accessibility {items, note}` + `accessibility_effective`, `accessibility_keys`, API `accessibility`; die Erstattungsregel bleibt nur auf evendis. **Editor** (`GET /events/{id}/edit` → `event`, `POST /events[/{id}]`, gleiche Rechte wie die übrigen Felder, geprüft vor dem Speichern): `info_labels {key: label}` (Keys der Info-Texte + `accessibility`, "" = Standard, ≤ 60 Zeichen); `faq [{q, a}]` (leere Fragen fallen weg, höchstens 30, `a` HTML); `video {url, type}` \| null (`type` aus der URL: youtube\|vimeo\|external; Einbettung `_tix_video_embed` ohne Abruf, YouTube ohne Cookies; nur http(s)); `charity {enabled, name, desc, percent 0–100, image_id, image, site_enabled}` (an → Name und Anteil > 0 Pflicht; `site_enabled` = Einstellung `charity_enabled`, sonst zeigt die Website nichts); **`POST /events/{id}/charity-image`** (multipart `image`, JPG/PNG/WebP ≤ 5 MB) → `{image_id, image}`; `box_office_desc` (≤ 300); `info_order {sections, custom, default, available [{key, label}]}`, POST als Liste der Keys (oder das Objekt), `null`/`[]` = Standard. Ungültig → 400 `tix_invalid_field` mit `field` (z. B. `info_labels.lineup`, `faq[2].q`, `video.url`, `charity.percent`, `info_order[0]`). **„Deine App“:** `GET /studio/config` → `app.layout` club\|festival (+ `layout_override`; Admin-Option `tix_app_layout`, sonst `layout`/`profile` in `_tix_app_config`, sonst „festival“ im Seitennamen bzw. in der Adresse, sonst club), Admin setzt es per `POST /studio/config {app_layout: club\|festival\|null}`. **`GET/POST /music/settings`** (Einzelseite Inhaber und Team-Admins, Plattform nur Admin) → `{settings {enabled, before_min 0–1440, after_hours 1–24, grace_min 0–600, default_start HH:MM, max_per_night 1–100, min_interval_min 0–120}, active, module_allowed, dj_url}`, POST = Teil-Update. **`GET /notifications/broadcasts?per_page=&page=`** (Inhaber und Team-Admins; Plattform: eigener Veranstalter, Admin alle) → `{total, page, per_page, broadcasts: [{id, title, body, target all\|event\|followers, event {id, title}\|null, recipients, created (ISO), sender {id, name}\|null}]}`; Verlauf ab 1.38.455 (Option `_tix_app_broadcast_log`, 200 Einträge), ältere Mitteilungen an alle aus dem Feed ohne Absender und Empfängerzahl. **`GET /app/config/edit`** zusätzlich `defaults {source: page\|none, tiles, faq [{q, a}], pages}` aus der WordPress-Seite `app-config` (JSON im Inhalt, wie die App sie liest). Guthaben: `POST /wallet/admin/config {enabled}` schalten Admin und Inhaber bzw. Team-Admins auf Einzelseiten (getestet). | alle | 1.38.455 |
| Freigabe zweistufig, Registrierung ohne Event, Bilder, Ort-Barrierefreiheit | Seit 1.38.456. **Freigabe (evendis):** Angaben in zwei Gruppen. `phase: review` = zur Prüfung nötig (Konto mit E-Mail, Name des Veranstalters, Vermittlungsvertrag; `TIX_Org_Approval::review_checks()`), `phase: after` = ergänzt der Veranstalter nach der Freischaltung im Studio (Firma/Name und Anschrift der Rechnung, Telefon, Steuer, Auszahlungsdaten; `after_items()`). Admin-Liste „Zur Prüfung“ grün/rot + „Später im Studio …“ grau, „noch kein Event“ neutral; Detail mit eigener grauer Box. Freischalten hängt nur an `review`: fehlt dort etwas, Warnung + „Ich gebe trotzdem frei“ (Log `forced`), sonst ohne Zusatz-Klick. Ausgezahlt wird wie bisher erst mit Auszahlungsdaten; `can_sell` = freigegeben. `approval` (in `/me`, `/organizer/approval`, Registrierung) zusätzlich `review_complete`, `review_missing`, `requirements[].phase`; `complete`/`missing` bleiben (Aufgaben). Registrierungs-Mail, Hinweis im Veranstalter-Bereich und „Mein Konto“ formulieren offene Angaben als Aufgaben. **`POST /register/organizer {without_event: true, …}`**: Konto + Veranstalter ohne Event (Freigabe wie mit Event: pending, Mails an Veranstalter und Admin); `event`/`ticket_mode` entfallen. Optional (auch mit Event), alles vor dem Anlegen geprüft: `profile {phone ≤ 40, email, website, address, city, tagline, short_desc, description, social {instagram, facebook, tiktok, youtube, spotify, soundcloud, website}}` (wie `POST /organizer/profile`), nur Plattform: `billing {company, name, street, zip, city, country (2 Buchstaben)}`, `tax_status vat_id\|tax_number\|small_business` (+ `vat_id` bzw. `tax_number`), `payout {holder, iban, bic}` (wie `POST /organizer/payout-details`; eine IBAN wartet auf den Code aus der Mail an die Konto-E-Mail, bestätigt mit `POST /organizer/payout-details/confirm {code}` und dem Token aus der Antwort), `logo` (Base64 bzw. data-URL, JPG/PNG/WebP, ≤ 5 MB, 16–6000 px). Antwort 201 `{ok, token, user, organizer_id, event_id: 0 (seit 1.38.457; mit Event die ID), without_event, review, saved {profile, payout {complete, verify_required, code_sent_to} \| null, logo (URL) \| null}, warnings [{field, message}], heading, message, event_url: "", approval}`. Fehler: 400 `tix_invalid_field` mit `field` (`first_name`, `email`, `password`, `organizer_name`, `accept_contract`, `profile.email`, `profile.phone`, `profile.social`, `billing.country`, `tax_status`, `vat_id`, `tax_number`, `payout.bic`, `logo`, auf Einzelseiten `billing`/`tax_status`/`payout`), 400 `tix_invalid_iban` (`payout.iban`), 413 `tix_too_large` (`logo`), 409 `tix_email_exists`, 429 `tix_rate_limit`, Bot-Schutz wie bisher. **`GET /events`** je Event zusätzlich `image` (large) und `image_small` (medium). **`GET /events/{id}/edit`** zusätzlich `image_full` (Original) und `srcset`. **`GET/POST /locations`**: `accessibility {items, note}` (Vorgabe für Events mit „vom Ort übernehmen“); POST `accessibility` {items, note} \| null, unbekannte Angabe → 400 `accessibility.items`, Hinweis ≤ 500 Zeichen. | alle | 1.38.456 |
| Kopieren ohne Laufzeit-Zustände, Archiv und Entwurf im Studio, Telefon bei der Registrierung | Seit 1.38.457. **Kopieren** (wp-admin „Duplizieren“, `POST /events/{id}/duplicate`, Veranstalter-Dashboard per AJAX, Serien/Termine): eine gemeinsame Ausnahmeliste `TIX_Event_Copy` (Präfixe `_tix_archived`, `_tix_is_past`, `_tix_notif_`, `_tix_calendar_`, `_tix_recurrence`, `_tix_series_`, `_tix_sold_`, `_tix_syndicate_`, `_tix_syndicated`, `_tix_source_`, `_tix_last_sync_`, `_tix_feedback_`, `_tix_ai_`, `_tix_day_alert`, `_tix_settle`, `_tix_org_notified_`, `_tix_raffle_winner`, `_tix_awareness_feedback` + Einzelfelder wie Zähler, Gästeliste, Gewinnspiel-Status, `_tix_publish_held`). Kalender-Links werden für die Kopie neu erzeugt, Werte nicht mehr doppelt angehängt; das Dashboard nutzt denselben Kern wie wp-admin (vorher kopierte es alle `_tix_*`). Fund kitchenklub.de: Kopien alter Events trugen `_tix_archived` und fehlten in der Admin-Liste. **Selbstheilung:** einmalig (Option `tix_archive_heal_v1`) und in jedem Archiv-Lauf holt `TIX_Archive::heal_copies()` Events aus dem Archiv, deren `_tix_archived_at` vor `post_date` liegt (sicheres Zeichen für eine Kopie). Absichtlich archivierte Events bleiben. **`GET /events`** je Event `archived` (bool) und `archived_at` (MySQL-Datum \| null). Filter `view=active\|archived\|all`; Vorgabe `active` wie die Admin-Liste, bei `filter=past` Vorgabe `all` (wie bisher). Antwort zusätzlich `view`. **`POST /events/{id}/archive {archived: true\|false}`** (Rechte wie die übrigen Event-Routen: `check_organizer` + `can_access_event`, Mehr-Veranstalter nur eigene) → `{ok, event_id, archived, archived_at}`; ohne `archived` 400 `tix_invalid_field`, 404 `not_found`, fremd 403. **Entwurf:** `POST /events/{id} {published: false}` setzt wie bisher auf Entwurf; bei einem zurückgehaltenen Event (Freigabe ausstehend, `_tix_publish_held`) bleibt es wie bisher zurückgehalten. Neu `{draft: true}`: immer Entwurf, auch zurückgehaltene, der Veröffentlichungswunsch entfällt. Wird ein veröffentlichtes, geplantes oder zurückgehaltenes Event mit verkauften Tickets zum Entwurf, enthält die Antwort `warning {code: tix_draft_with_sales, sold, message}`: nicht mehr öffentlich und nicht buchbar, verkaufte Tickets bleiben gültig (Einlass geht), Käufer werden nicht benachrichtigt; geteilte Events gehen auf evendis ebenfalls auf Entwurf. **Registrierung** (`POST /register/organizer`): `event_id` ohne Event 0 (statt null); neu `phone` (privat, nur für Rückfragen bei der Prüfung, 4–40 Zeichen aus Ziffern, Leerzeichen, + ( ) / . -, sonst 400 `phone`) → Meta `_tix_org_review_phone`, nicht im öffentlichen Profil. Es erfüllt die Aufgabe „Kontakt (Telefon)“, steht im Admin in Liste und Detail und in `/me` unter `organizer.review_phone` (nur Inhaber/Team-Admin). Für ein wartendes Konto funktionieren `/organizer/profile`, `/organizer/profile/image` und `/organizer/payout-details` (getestet); die Angaben erscheinen in der Freigabe nicht als Fehler. | alle | 1.38.457 |
| Platzhalterbild für Events, Meta-Einwilligung | Seit 1.38.458. **Platzhalter** je Seite (Studio-Marke `tix_studio_brand.placeholder_id`; im Mehr-Veranstalter-Modus gilt die Seite): **`POST /studio/placeholder`** (multipart `image` bzw. `file`, JPG/PNG/WebP ≤ 5 MB, 200–8000 px, empfohlen 16:9 1920×1080) und **`DELETE /studio/placeholder`** (zurück auf den Standard), außerdem `POST /studio/config {placeholder_id: id\|0}`; Rechte wie Akzent und Logo (`perm_brand`: Admin, auf Einzelseiten auch Inhaber bzw. Rolle Veranstalter). Antwort jeweils wie `GET /studio/config`, das neu enthält `brand {accent, logo, placeholder {url (large), url_small (medium)} \| null}` und `custom.placeholder` (bool). `GET /info` liefert `event_placeholder {url, url_small} \| null`. **Website:** Events ohne Beitragsbild bekommen den Platzhalter als Beitragsbild. Das ist ein Lesefilter auf `_thumbnail_id`, nur im Frontend (nicht REST, Verwaltung, Cron, WP-CLI) und greift auch in Theme und Breakdance. Ohne Einstellung bleibt alles wie bisher. In API-Antworten bleibt `image`/`thumbnail` leer; die Apps zeigen dann ihren eigenen Platzhalter bzw. den aus `/info`. Ein Platzhalter je Veranstalter ist noch nicht gebaut (Wunsch). **Meta-Einwilligung:** Im Modus `meta_consent_mode = consent_required` geht die Conversions-API nur mit Nachweis an Meta: `_tix_meta_consent` bzw. gespeichertes `fbp`/`fbc`. Die WordPress-Kasse merkt sich die Einwilligung „Marketing“ jetzt beim Anlegen der Bestellung (`TIX_Native_Checkout::record_meta_consent`: `_tix_meta_consent` + fbp/fbc/IP/Browser, sonst `_tix_meta_no_consent`); App-Bestellungen ohne Nachweis gehen nicht an Meta. Modus `always` unverändert. Der verzögerte Pixel-Lader wertet das eingestellte Consent-Cookie nach seinem Wert aus („0/false/no/denied“ sowie JSON ohne Marketing = nein), nicht mehr nach bloßem Vorhandensein; das Tixomat-Banner feuert bei gespeicherter Zustimmung auf jeder Seite `tix:consentGranted`. `tix_consent_banner.accent` (#hex) setzt eine eigene Banner-Farbe, sonst gilt die Primärfarbe. | alle | 1.38.458 Seit 1.38.513 liefern auch die Staff-Routen `GET /events` (Liste), `GET /events/{id}` und `GET /events/{id}/edit` (unter `event`) dasselbe Feld `placeholder` `{url, url_small} | null` wie `/public/events`, auch für Entwürfe. |
| Marken-Websites: site-config, Zeiten, Slugs, ref/coupon, Magic Link | Seit 1.38.459 (`TIX_Site_Config`). **`GET /public/site-config`** (öffentlich, `Cache-Control: public, max-age=60`) → `{ok, name, profile club\|festival\|festivalTour, colors {accent, background, glow}, logo {light, dark}, icon, favicon, event_placeholder, nav {header [{label, url}], footer}, home {blocks [{key, enabled}]}, seo {title_pattern (mit %s), description, image}, social {instagram, facebook, tiktok, youtube, spotify, soundcloud, whatsapp}, app {has_app, name, app_store_id, ios_url, play_package, android_url}, features {checkout, music, awareness, wallet, coupons, muttizettel, app_banner}, tracking {meta_pixel_id, meta_consent_mode, consent {banner, categories}}, contact {email, phone, address, company}, venue {name, address, city, lat, lng} \| null, legal {privacy, imprint, terms, privacy_slug, imprint_slug, terms_slug}, overrides [Bereiche], plugin_version}`. Berechnet aus Studio-Marke, App-Inhalten (`_tix_app_config.contact`), Tixomat-Einstellungen, WordPress-Menüs (Ort primary/header bzw. Menü mit „Header“/„Footer“ im Namen), Rechtstext-Seiten und dem Ort mit den meisten kommenden Events. Werte, die es im Plugin nicht gibt (Hintergrund, Lichtschein, Bausteine), sind null bzw. leer → Grundwerte der Web-App. **`POST /studio/site-config`** (Rechte wie Akzent/Logo) überschreibt je Bereich `profile`, `nav`, `home`, `seo`, `social`, `contact`, `venue`, `legal` (Option `tix_site_config`; Bereich null = zurück auf berechnet; Links nur https:// oder /…; ungültig 400 `tix_invalid_field` mit `field`). **`/public/events` und `/public/events/{id}`** zusätzlich `starts_at`, `ends_at` (ISO 8601 mit Versatz der Seiten-Zeitzone, Sommerzeit richtig, Ende über Mitternacht), `ends_at_estimated` (ohne Endangabe: 8 h bzw. Tagesende) und `modified` (UTC, `…Z`). **`/public/events/by-slug/{slug}`** mit altem Slug zusätzlich `redirect` (= `canonical_slug`) für eine 301. **`?ref=` / `?coupon=`:** Mit Option `tix_strip_ref_params` = 1 leitet WordPress nach dem Merken (Promoter-Sitzung bzw. Gutschein-Cookie) per 302 auf dieselbe Adresse ohne diese beiden Parameter um (andere wie utm_* bleiben; Kunden-Adresse). Nur GET, nicht in Verwaltung, REST oder AJAX; Vorgabe aus. **`POST /auth/magic {token, device?}`** löst den Link aus „Meine Tickets“ (`?tix_mt_token=`) einmalig ein → `{ok, success, token, user, created}`: vorhandenes Kundenkonto angemeldet (E-Mail bestätigt), sonst neues Konto ohne Passwort wie beim Code-Weg. 400 `tix_magic_invalid` (ungültig, abgelaufen, schon benutzt), 403 `tix_magic_staff` (Personal meldet sich normal an), 429 `tix_rate_limit`. | alle | 1.38.459 |
| Kontolöschung Web + App, Schriften lokal, Platzhalter per /studio/config | Seit 1.38.460. **Kontolöschung** (`TIX_Account_Delete::delete($user_id, $source)`, gemeinsamer Kern für die Website `ajax_delete_account` und die App **`POST /auth/delete`**, Antwort unverändert `{ok, deleted}`): Bestellungen (`tix_orders`, WooCommerce HPOS) → Name `[Gelöscht]`, E-Mail `gelöscht-<hash>@removed.invalid`, Telefon/Anschrift/Firma leer, `customer_id` 0; Betrag, Datum, Status, Positionen, Bestellnummer, Zahlart bleiben. Tickets: Inhaber anonymisiert, **Code, Status und Check-in bleiben** – gültige (auch kommende) Tickets funktionieren am Einlass weiter, keine Sonderregel. Neu: Ticket-Tabelle `tixomat_tickets` (buyer_name/email/phone/company/city/zip) wird mit anonymisiert; Support-Anfragen aller Status (vorher fielen offene/geschlossene durch). Newsletter-Abos gelöscht, Profilbild, App-Tokens, Konto. Guthaben-Richtlinie `block` gilt jetzt auch auf der Website. Protokoll `tix_deleted_accounts_log` mit `source` web/app (ohne Personenbezug). Zuordnung per E-Mail nur mit bestätigter E-Mail. Danach **`do_action('tix_account_deleted', $user_id, $email, $info)`**, `$info = {anon_hash, source, orders, tickets, support}`. App: Admin/Veranstalter/Personal weiter 403. **Schriften lokal** (`TIX_Font_Mirror`, Option `tix_local_fonts`, Vorgabe an, `0` = aus; Tixomat → Schriften mit „Neu aufbauen“): jede Google-Fonts-Adresse aus `<link>` (auch `preload as=style`), `wp_enqueue_style` und `@import` (Inline-Style und eigene CSS-Dateien unter wp-content, z. B. Breakdance `uploads/breakdance/css/…` → Kopie unter `uploads/tix-fonts/css/` mit lokalem Import und absoluten Pfaden) wird einmal per Cron heruntergeladen (CSS + woff2 nach `uploads/tix-fonts/`, Liste in Option `tix_font_mirror`) und lokal ausgeliefert; preconnect/dns-prefetch zu Google und gstatic-Preloads entfernt. Noch nicht gespiegelt oder Download fehlgeschlagen → Original-Link bleibt (bis 3 Versuche stündlich, danach Hinweis im Admin). Nur Website-HTML (nicht REST, Admin, AJAX, Feed). **Platzhalter**: zusätzlich `POST /studio/config` multipart `placeholder=<Datei>` (JPG/PNG/WebP, höchstens 5 MB, 200×100 bis 8000 px; Fehler mit `field: placeholder`, 413 bei zu groß), Zurücksetzen `placeholder_id=0`; `brand.placeholder` immer vorhanden (null ohne Bild); `POST`/`DELETE /studio/placeholder` bleiben. |
| Aufbewahrung der Rechnungsdaten, Schriften-Spiegel robuster | Seit 1.38.461. **Kontolöschung mit Aufbewahrung** (Option `tix_delete_invoice_retention`: `retain` = Vorgabe, User-Entscheid B; `anonymize` = alles sofort wie 1.38.460): Bestellungen des Kontos (über bestätigte E-Mail oder Konto-ID) mit Status completed, processing, refunded, partially-refunded oder mit ausgestellter Rechnung (`_tix_invoice_<id>`) behalten Rechnungsname, Firma, Anschrift und Land bis **31.12. des 10. Jahres nach dem Bestelljahr** (§147 Abs. 4 AO, §14b UStG; Filter `tix_invoice_retention_years`). Sofort weg: E-Mail (→ `gelöscht-<hash>@removed.invalid`; steht nicht auf der Rechnung, §14 Abs. 4 UStG verlangt sie nicht), Telefon, Konto-Verknüpfung, Marketing-Einwilligung und Tracking-IDs der Bestellung (`_tix_meta_consent`, `_tix_meta_fbp`, `_tix_meta_fbc`). Unbezahlte/stornierte Bestellungen ohne Rechnung: sofort vollständig anonymisiert. Cron **`tix_order_retention_expire`** (täglich) leert nach Fristende Name, Firma und Anschrift (Markierung = anonymisierte E-Mail; Aktion `tix_order_retention_expired($anzahl)`). `tix_account_deleted` liefert in `$info` zusätzlich `retention` (retain/anonymize) und `retained` = Liste `{order_id, order_number, retained_until}`; Protokoll mit `retained`. Gilt gleich für WooCommerce-Altbestellungen. **Schriften**: gleiche woff2-Datei nur einmal laden, nur Zeichensätze aus Option `tix_font_subsets` (Vorgabe `latin,latin-ext`, `all` = alle; Feld unter Tixomat → Schriften, Ändern lädt neu), andere @font-face-Blöcke fallen aus der lokalen CSS (ohne Kennzeichnung oder wenn sonst nichts übrig bliebe: alles behalten). Grenze 300 Dateien (Fehler nennt die Anzahl). Fehlversuche sperren nicht mehr dauerhaft: neue Spiegel-Version (`tix_font_mirror_rev`) startet alle fehlerhaften Einträge neu, sonst nach 3 Fehlversuchen täglich ein neuer Anlauf. |
| Status ohne Online-Verkauf, Papierkorb per REST | Seit 1.38.462. **Status**: ohne Online-Verkauf (`_tix_tickets_enabled` ≠ 1, also auch freier Eintritt, nur Abendkasse, externer Shop) wird `_tix_status` nie mehr automatisch `sold_out` (weder aus Kategorie-Beständen noch aus beendetem Vorverkauf) – beim Speichern (`TIX_Sync`, neu `TIX_Sync::online_sale($id)`), im App-/Studio-Editor (`TIX_App_Events::resolve_status`) und im Vorverkaufs-Cron; `sold_out` nur noch mit aktivem Online-Verkauf oder als Override `_tix_event_status`. `/public/events` (Liste + Detail) meldet in diesem Fall auch bei altem gespeichertem Wert `status: available`. **Freier Eintritt** (`free_entry: true`): `price_from: null`, `categories: []`. **Ohne Online-Verkauf** allgemein: `categories: []` (nichts kaufbar; die Website listete sie sonst unter „Eintritt“), `price_from` bleibt wie bisher (z. B. „Nur Abendkasse“), `box_office` unverändert. **Kopieren** (`TIX_Event_Copy`): je Feld nur eine Zeile (doppelte Zeilen des Originals werden nicht weitergegeben), Kategorien ohne `sold`/`sold_count`, Bestand = Kontingent. **Selbstheilung** einmal je Seite beim ersten Aufruf nach dem Update (Option `tix_heal_status_462` mit Ergebnis `{at, status, duplicates}`): Events ohne Online-Verkauf mit `_tix_status=sold_out` ohne Override neu berechnet (vergangene → `past`), doppelte `_tix_*`-Zeilen an Events auf die erste Zeile zurückgeführt. **`DELETE /events/{id}`** (Rechte und Abschottung wie `/events/{id}/archive`) → Papierkorb, nicht endgültig: `{ok, event_id, trashed: true}` (auch wiederholt); mit verkauften Tickets bzw. Plätzen in offenen/bezahlten Bestellungen **409 `tix_has_sales` `{sold}`** (Gästeliste zählt nicht, stornierte Bestellungen/Tickets zählen nicht). Geteilte Events: wie der Papierkorb im WP-Admin – die Verteilung setzt das Plattform-Event auf Entwurf (`PATCH /syndicate/{remote_id}` mit `status: draft`, `source_id`), löscht es nicht; endgültiges Löschen (WP-Admin) entfernt es dort. **`POST /events/{id}/restore`** → aus dem Papierkorb, immer als Entwurf: `{ok, event_id, restored: true, status: draft}`; nicht im Papierkorb 409 `tix_not_trashed`. **Duplizieren** (`POST /events/{id}/duplicate`) unverändert: ohne `title` Titel + „ (Kopie)“, Kopie ist Entwurf. |
| Gutschein als Geschenk, Gutschein-PDF, Aufladen aus Gutschein, Studio-Einstellungen, Papierkorb ohne Datenverlust | Seit 1.38.463. **Geschenk-Angaben**: in `POST /customer/cart/quote` und `/customer/orders` je Gutschein-Position optional `gift {recipient_name (≤ 80), message (≤ 200, Zeilenumbrüche erlaubt), from_name (≤ 80)}`; Fehler 400 `tix_invalid_field` mit `field` z. B. `items[0].gift.message`; leere Angaben = keine. `totals.lines[].gift` gibt sie zurück (null ohne/kein Gutschein). Gespeichert an der Position (`meta.gift_info`) und am Gutschein (`tix_coupons[code].gift`); Ticket-Antworten (`gift_fields`) zusätzlich `gift_recipient`, `gift_message`, `gift_from` (je null ohne Angabe). Website-Kasse: Felder „Für / Von / Nachricht“ je Gutschein-Position (`tix_gift[<Position>][…]`). **Gutschein-PDF** (A4, Logo + Akzentfarbe der Seite, Betrag, „Ein Geschenk für …“, Nachricht, von, QR, Code, gültig bis, Einlöse-Hinweis; QR lokal über `TIX_QR`, kein Fremddienst): **`GET /customer/giftcards/{code}/pdf`** → `{ok, code, filename, mime: application/pdf, data (Base64)}`; mit `download=1` die Datei direkt. Zugriff: Käufer (Konto oder bestätigte E-Mail), Inhaber des Gutschein-Tickets, Admin oder `?key=<Bestell-Key>` (Gäste); sonst 401/403, unbekannt 404. **Mail**: Bestätigungs-Mail mit Block „Gutschein verschenken“ (Code, Betrag, Empfänger, Nachricht) und je Gutschein ein PDF-Anhang; entstehen die Codes erst danach, eigene Mail „Dein Geschenkgutschein“ (einmal). Ticket-Ansicht der Website: QR lokal, Geschenk-Angaben, Link „Gutschein als PDF“. Fix: App-Gutschein mit Wunschbetrag bekam keinen Code (Kategorie ging über den Positionsnamen verloren; jetzt `meta.cat_index`). **`POST /wallet/redeem-giftcard`** `{code, amount?}`: `code` = Gutschein-Code, Ticket-QR `GL-…` oder reiner Ticket-Code des Gutschein-Tickets; `amount` optional (Teilbetrag ≤ Restwert, sonst ganzer Restwert, 409 `tix_giftcard_amount`); Antwort zusätzlich `giftcard_rest`; schon eingelöst 409 `tix_giftcard_used` mit `{code, value, balance: 0, expires, redeemed_at, redeemed_via}`, abgelaufen 410 mit denselben Feldern. **Studio-Einstellungen**: `GET`/`POST /giftcards/admin/config` (Lesen Staff wie `/wallet/admin/config`, Ändern Admin/Inhaber; Mehr-Veranstalter je eigenem Veranstalter, an/aus dort über das Modul): `{organizer_id, settings {enabled, amounts (≤ 8), free_amount, free_min, free_max, validity_years 1–10}, enabled_via_module, can_edit}`. `POST /wallet/admin/config` nimmt zusätzlich `spend_order` (paid_first/bonus_first, seitenweit; im Mehr-Veranstalter-Modus nur Admin). **Papierkorb** (Fund Datenspiegel): „In den Papierkorb“ löscht nichts mehr endgültig (vorher im WP-Admin: Tickets, Ticket-Tabelle, Warteliste, Gewinnspiel, Sitzplätze, Provisionen, Kampagnen-/Pixel-Daten weg); erst endgültiges Löschen räumt auf, und mit verkauften Tickets bleiben Ticket-Posts (markiert `_tix_ticket_event_deleted`, `_tix_ticket_event_title`), Ticket-Tabelle und Provisionen; Bestellungen werden nie gelöscht. WP-Admin: Papierkorb und „Endgültig löschen“ bei Verkäufen gesperrt (Hinweis bzw. 409). Serien: Termine ohne Verkäufe wandern mit dem Master in den Papierkorb und kommen beim Wiederherstellen zurück. Verwaist-Aufräumen löscht keine Tickets und keine Termine von Papierkorb-Events mehr. |
| Gutschein-Vertrag verbindlich, Gutschein-Verwaltung im Studio, Studio-Felder in GET /events, Eventbericht | Seit 1.38.464. **Verbindlicher Gutschein-Vertrag** (Studio, evendis.de, Apps): Kauf mit `gift {recipient_name, message (≤ 200), from_name}` an der Gutschein-Position in `POST /customer/cart/quote` und `/customer/orders`. Gutschein-Tickets (`/customer/tickets`, Tickets der Bestellung) liefern `gift_recipient_name`, `gift_message`, `gift_from_name` (je null ohne Angabe), `gift_organizer_id` (Einzel-Seite 0), `gift_organizer_name` (Veranstalter bzw. Seitenname), `gift_pdf` (URL der PDF-Route, '' = keins) – zusätzlich noch `gift_recipient`/`gift_from` aus 1.38.463. PDF: `GET /customer/giftcards/{code}/pdf` → `{filename, mime, data}` (Base64; dazu `ok`, `code`) mit Token oder für Gäste `?order_id=&key=` (order_id muss zur Bestellung passen; `?key=` allein geht weiter). **Gutschein-Verwaltung**: `GET /giftcards/admin` (Staff wie `/wallet/admin/config`) → `{settings {enabled, amounts[], free_amount, free_min, free_max, validity_years}, can_edit, stats {sold_count, sold_value, redeemed_value, open_value}, recent [{code, value, balance, expires, created, buyer}] (20 neueste; voller Code nur mit can_edit, sonst GS-XXXX-••••), organizer_id, enabled_via_module}`; `POST /giftcards/admin {settings}` (Admin/Inhaber; Teil-Update). Mehr-Veranstalter je eigenem Veranstalter, sonst die Seite; `/giftcards/admin/config` bleibt als gleiche Route. **Wunschbetrag-Grenzen einstellbar** (`free_min`/`free_max`, ganze Euro 1–10000, Vorgabe 10–500; je Seite bzw. Veranstalter), gelten in App-Kasse, Website-Kasse, Shortcodes und `GET /giftcards` (`min`/`max`). Stornierte Käufe zählen nicht in `stats`. **Guthaben**: `GET /wallet/admin/config` zusätzlich `spend_order_editable` (Admin bzw. auf Einzel-Seiten der Veranstalter); `POST` nimmt `spend_order`. **`GET /events`** (Staff) liefert auf allen Seiten, auch für Entwürfe: `tickets_enabled`, `ticket_mode` (online \| box_office \| free \| external \| none), `free_entry`, `has_report`. **Eventbericht** (Daten der Admin-Seite „Event-Bericht“; Rechte wie Statistik/Bestellungen, Mehr-Veranstalter abgeschottet, nicht für Kasse/Einlass): `GET /events/{id}/report` → `{has_presale: true, event {id, title, post_status, date_start, time_start, location}, kpis {sold, cancelled, checked_in, checkin_rate, revenue_gross, revenue_net, vat_rate, payment_fees, revenue_after_fees, revenue_net_after_fees}, categories [{name, sold, checked_in, revenue, avg_price, capacity, available, sold_through, stock_status}], warnings {low[], sold_out[]}, tickets [{id, code, owner_name, owner_email, category, price, order_id, order_number, order_date, seat, status, checked_in, checkin_time}], exports}`; ohne Vorverkauf (keine bezahlte bzw. erstattete Online-Bestellung) 404 `tix_no_presale` mit `has_presale: false`. `GET /events/{id}/report/export?format=pdf\|xlsx\|csv` → `{filename, mime, data}` (Base64), derselbe Export wie im WP-Admin. Die Reparatur-Werkzeuge bleiben im WP-Admin. |
| Platzhalter je Event, alle Events teilen, App-Bild, QR lokal, Auschecken, Bericht-Zusätze | Seit 1.38.465. **Platzhalter je Event**: geteiltes Event → Platzhalter der Ursprungsseite (die Verteilung sendet `placeholder` = URL bzw. null; die Plattform lädt ihn einmal je Adresse in die Mediathek, Event-Meta `_tix_source_placeholder_id`), im Mehr-Veranstalter-Modus Platzhalter des Veranstalters (`_tix_org_placeholder_id`; `POST /organizer/profile/image` mit `kind=placeholder` + `file`, Zurücksetzen `kind=placeholder&remove=1`; `GET /organizer/profile` → `placeholder {url, url_small} \| null`), sonst der Seite. `/public/events` (Liste + Detail) liefert `placeholder {url, url_small} \| null`; `image` bleibt leer (Clients: `image` → `placeholder` → Seiten-Platzhalter). Website (Beitragsbild-Filter) ebenso. **Alle Events auf evendis teilen** (Einzel-Seiten mit Verteilung): `GET /studio/config` → `syndication {available, default (true\|false\|null), default_partner_sales, partner_sales_available}`; `POST /studio/config {syndicate_default: true\|false\|null, syndicate_default_partner_sales: bool}` (Rechte wie Marke; ohne eingerichtete Verteilung 409). Mit `default: true` bekommen neue Events (Studio, App, WP-Admin, Registrierung, Serien) und Duplikate `_tix_syndicate=1` und die Verkaufsart (`_tix_partner_sales`) vorbelegt, je Event abwählbar; der erste Push eines gerade angelegten Events folgt beim nächsten Speichern mit vollständigen Daten. Gutschein-, Guthaben- und Test-Events werden nie verteilt. `POST /studio/syndicate-upcoming {dry_run = true}` → `{dry_run, count, events [{id, title, date_start}]}`: veröffentlichte kommende, noch nicht geteilte Events; ohne dry_run markieren + Senden planen. Einmal nach dem Update werden alle verteilten kommenden Events neu gesendet (trägt `evendis_url` für Freigaben vor 1.38.453 nach und überträgt den Platzhalter). **`POST /app/config/image`** (Rechte wie `POST /app/config`): multipart `file`, `kind` = banner \| image → `{ok, url, id, kind, width, height}` (JPG/PNG/WebP/GIF ≤ 5 MB, ≤ 8000 px). **QR lokal**: Ticket-PDFs/-Bilder, Ticket-Ansicht und Gästelisten-Mail erzeugen den QR selbst (`TIX_QR`); Mails und Seiten nutzen eine signierte Bildadresse der eigenen Seite `?tix_qr=…&px=…&s=…` (falsche Signatur 404). **Check-in**: `GET /checkin/{event}/list` je Ticket `order_id` (nicht für Einlass-Rollen); **`POST /checkin/uncheck {ticket_id \| code}`** (Rechte wie Check-in, Check-in-Passwort per Kopfzeile) setzt Ticket bzw. Gast (Code `GL-<Event>-<Gast-ID>`) sicher auf „nicht eingecheckt“ → `{ok, type ticket\|guest, id, event_id, checked_in: false, changed, name, message}`. **Eventbericht** zusätzlich `kpis.orders`, `kpis.discounts` (Rabatt anteilig), `by_payment [{method, label, orders, revenue}]`, `phases [{category, name, sold, revenue}]` (Phase aus dem gezahlten Preis abgeleitet; Bestellungen speichern keine Phase). **Gutschein-Reparatur** (Bestellungen vor 1.38.463): `TIX_Giftcards::repair_order($id, $dry_run = true)` trägt die fehlende Kategorie nach und erzeugt die Codes (ohne Mail; Mail bei Bedarf `TIX_Giftcard_Gift::send_late_mail($id)`). |
| Plattform-Guthaben (evendis) | Seit 1.38.466 (`TIX_Wallet_Balance`, `TIX_Wallet_Pay`, `TIX_Settlement`; User-Entscheid 2026-10-16). **Schalter** Option `tix_wallet_platform` ('1' an, Vorgabe **aus**; nur Mehr-Veranstalter-Modus; einschalten erst nach Freigabe durch den User, Klärung E-Geld/ZAG mit dem Anwalt; z. B. `wp option update tix_wallet_platform 1`). Abgebildet in **`GET /wallet/config` ohne `organizer` → `enabled`**. Einzel-Seiten (KitchenKlub, Mallorca) unverändert: Guthaben bleibt beim Betreiber. **Eine Geldbörse je Kunde** (`organizer_id` 0, `scope_type` platform). `GET /wallet/config` (an) zusätzlich `scope: platform`, `platform: true`, `usable_for {tickets, cashless}`, `cashless_organizers [{id, name, slug}]` (Veranstalter mit Modul „wallet“, freigegeben); `GET /wallet/config?organizer=X` liefert dann dieselbe Plattform-Antwort mit `organizer_id: X` und `cashless_here`. `GET /wallet`: je Geldbörse `scope` platform \| organizer \| site, Plattform zuerst; Buchungen (`tx`) mit `organizer_id`/`organizer_name` (wo bezahlt). **Aufladen** über das versteckte Guthaben-Event der Seite (Einstellungen = Bereich 0, `POST /wallet/admin/config` als Admin ohne `organizer`), bezahlt über das evendis-Zahlungskonto; **vorerst kein Bonus** auf dem Plattform-Guthaben (`bonus_tiers: []`, `bonus: 0`; Filter `tix_wallet_platform_bonus`); `GET /wallet/admin/config` zusätzlich `platform`, `platform_on`, `bonus_allowed`. **Gutscheine**: Seiten-Gutscheine → Plattform-Geldbörse (auch wenn die App `organizer` mitschickt), Veranstalter-Gutscheine bleiben beim Veranstalter. **Tickets jedes Veranstalters** mit dem Plattform-Guthaben (`use_wallet` wie bisher; ist es leer, wird ein vorhandenes Veranstalter-Guthaben genutzt); die Bestellung zählt in der Event-Abrechnung voll wie eine Kartenzahlung. **Cashless** (`/wallet/scan`, `/wallet/charge`, Freigabe, Storno): Personal eines Veranstalters mit Modul „wallet“ bucht vom Plattform-Guthaben; Stationen und Freigabe-Schwelle dieses Veranstalters; Buchung trägt `organizer_id`; Vorschau zusätzlich `platform`; ohne Modul 403 `tix_wallet_scope`; Storno nur durch diesen Veranstalter. `POST /wallet/topup-pos` für das Plattform-Guthaben 409 `tix_wallet_platform_pos` (nur in der App aufladen). Tagesbericht des Veranstalters enthält diese Buchungen. **Abrechnung**: jede Cashless-Buchung wird offener Posten `cashless` beim Veranstalter (Storno negativ) und geht mit der **nächsten Event-Abrechnung** (bzw. Saldo-Abrechnung) als **ein Posten** „Cashless mit evendis-Guthaben (N Zahlungen)“ raus (`detail_payload.cashless`, `lines[type=cashless]`, PDF); nicht Teil der Ticket-Gutschrift (Mehrzweck-Gutschein). DB-Version Guthaben 3 (`organizer_id` an Buchung und Zahlungsvorgang). |
| App-Profil festivalTour | Seit 1.38.467: `TIX_Studio::app_layout()` kennt `club`, `festival` und **`festivalTour`** (Mallorca). Quelle wie bisher: Option `tix_app_layout` › `layout` bzw. `profile` in den App-Inhalten (`_tix_app_config`) › automatisch („festival“ im Seitennamen bzw. in der Adresse → `festival`, sonst `club`). `/studio/config.app.layout`, `app.layout_override` und `GET /public/site-config` → `profile` liefern den Wert; `POST /studio/config {app_layout: club \| festival \| festivalTour \| null}` (nur Admin). Für Mallorca einmal setzen: `wp option update tix_app_layout festivalTour`. |
| Awareness: Events mit Meldungen (letzte 30 Tage) | Seit 1.38.468: `GET /awareness/events?range=recent` (Rechte wie bisher: Awareness-Team bzw. Staff/Admin; Abschottung je Veranstalter wie die Event-Liste) → `{ok, range: "recent", events: [{id, title, date_start, starts_at, ends_at, alerts, alerts_open, feedback, feedback_notes, reports, reports_open, last_at}]}` – Events mit Datum in den letzten 30 Tagen bis heute, die mindestens eine Meldung (`alerts`, davon offen/übernommen `alerts_open`) oder Rückmeldung (`feedback`, davon mit Text `feedback_notes`) haben, neueste zuerst. Ohne `range` unverändert (laufende und kommende Events). Seit 1.38.482 zählen auch Meldungen nach dem Event (`/awareness/reports`): `reports` (gesamt) und `reports_open` (offen bzw. in Bearbeitung); Events nur mit solchen Meldungen sind dabei, `last_at` berücksichtigt sie. |
| Mail-Layout und Mail-Vorschau | Seit 1.38.471 (`TIX_Mail_Layout`, `TIX_Mail_Gallery`): `GET /studio/mails` (Rechte wie Studio-Marke: Admin, auf Ein-Veranstalter-Seiten auch der Inhaber) → `{ok, brand: {name, accent, logo, logo_on_accent, header, from, support_email, feedback}, mails: [{key, label, group, subject}]}`; `GET /studio/mails/preview/{key}` → `{ok, key, subject, html, text}` (`?format=html` liefert die Seite direkt; unbekannt = 404). Beispieldaten, es wird nichts gespeichert oder gesendet. Wochenmail-Abmeldung `/public/newsletter/unsubscribe` nimmt jetzt auch **POST** (RFC 8058 One-Click, Antwort `{ok: true}`); GET wie bisher. |
| Veranstalterseite folgt der Freigabe | Seit 1.38.472 (Mehr-Veranstalter-Modus): kein Häkchen „Landingpage freischalten“ mehr. Mit der Freigabe (`approved`) ist die Veranstalterseite öffentlich, abgelehnt/gesperrt nicht (`TIX_Organizer_Landing::is_approved` = `TIX_Org_Approval::is_public`; Meta `_tix_org_landing_approved` folgt dem Status, einmalige Selbstheilung `tix_heal_org_landing_472` für freigegebene ohne Flag). Event-Karten verlinken ohne Veranstalter-Seite auf `<Kunden-Adresse>/v/<slug>/`. Ein-Veranstalter-Seiten unverändert. |
| Mail-Marke in der Studio-Marke | Seit 1.38.473: `GET /studio/config` → `brand.mail = {header: light|brand (wirksam), header_set: light|brand|null, logo (URL, Logo auf hellem Grund), logo_custom (bool), logo_on_color (URL|null), from, from_name, reply_to, custom: {from, from_name, reply_to} (selbst gesetzt bzw. null)}`. `POST /studio/config` (Rechte wie Marke) nimmt `mail_header` (light|brand), `mail_logo_id`, `mail_logo_on_color_id` (Bild-ID, 0/null = zurück), Dateien `mail_logo` bzw. `mail_logo_on_color` (multipart, wie `logo`), `mail_from`, `mail_reply_to` (E-Mail), `mail_from_name`; null/"" = Vorgabe. Fehler 400 mit `field`. Akzent immer `brand.accent`; `brand` ohne Logo für farbigen Grund wirkt als `light`. Vorgaben: Absender `noreply@<Domain der Seite>`, Name = Marke, Antworten an = Kontakt-/Support-Adresse. `GET /studio/mails` liefert `brand.reply_to` statt `support_email`/`feedback`. |
| Orts- und Adresssuche (Google Places) | Seit 1.38.474 (`TIX_Places`, alle Seiten, öffentlich mit Limits): `GET /places/autocomplete?q=&session=&type=venue|address` → `{results: [{place_id, name, address}], session}` (`venue` = Lokale/Orte, `address` = Straßenadressen; `q` unter 3 Zeichen → `{results: []}` ohne Google-Aufruf, auch ohne Schlüssel). `GET /places/details?place_id=&session=` → `{place: {name, address, street, house_number, zip, city, country, country_code, lat, lng, place_id}}`. Session-Token Pflicht (8–64 Zeichen `[A-Za-z0-9_-]`, je Eingabe-Sitzung ein neues, für Vorschläge und die anschließende Detail-Abfrage dasselbe). Fehler: `tix_places_off` 404 (kein Schlüssel bzw. Google lehnt ab), `tix_places_limit` 429 (je IP 60/min, angemeldet 120/min; je Session 40 Vorschläge bzw. 5 Details; Tageslimit `tix_places_daily_limit`, Vorgabe 2000), `tix_places_session` 400, `tix_places_origin` 403 (Origin/Referer fremder Host), `tix_places_error` 502. Clients: bei Fehler oder leerer Liste normal weiter tippen lassen. Pflicht-Kennzeichnung: Unter der Vorschlagsliste das Google-Maps-Logo (bzw. bei Platzmangel den Text „Google Maps“) zeigen. |
| Orte je Veranstalter, kanonische Orte, Tipps für Inhaber | Seit 1.38.475. **Mehr-Veranstalter-Modus:** `GET /locations` und `/events/form` bzw. `/events/{id}/edit` (`locations`) liefern Veranstaltern und Team nur eigene Orte (`_tix_loc_organizer_id`, sonst Autor; der am Event gesetzte Ort bleibt in der Liste), Admins alle; Einzel-Seiten unverändert. Je Ort zusätzlich `place_id` (oder null), `last_used` (ISO, jüngstes Event an diesem Ort, oder null), `organizer_id`, `canonical_id` (öffentlicher Ort). `POST /locations` nimmt `place_id`; gibt es beim eigenen Veranstalter schon einen Ort mit dieser `place_id` → 200 `{ok, existing: true, location}` statt neu anzulegen. `POST /events`, `POST /events/{id}` mit fremder `location_id` → 403 `tix_location_foreign` (field `location_id`); neue Orte über `POST /locations`. **Öffentlich:** `venue.id` in `/public/events` u. a. ist der kanonische Ort (gleicher Ort mehrerer Veranstalter = ein Ort, Name/Stadt/Koordinaten des kanonischen); neuer Filter `/public/events?venue=<ID>` bzw. `/public/events/days?venue=` (jede ID der Gruppe liefert alle Events des Orts); `/public/venues/open` je kanonischem Ort einmal. **Tipps:** `/app/tips` (GET/POST/DELETE, Bild) auf Einzel-Seiten auch für Inhaber (Rolle `tix_organizer` = App-Rolle `admin`); evendis weiter nur Admins. |
| Guthaben in der Kasse, Verlauf, Meldungen bei System-Bestellungen | Seit 1.38.476. `POST /customer/cart/quote` liefert bei angemeldeten Kunden **immer** `wallet: {available, can_apply, selected, applied, rest, wallet_id, organizer_id, reason?}` (auch ohne `use_wallet`; dann `selected: false`, `applied: 0`, `rest = total`). Die App bietet „Guthaben nutzen (can_apply €)“ an, wenn `can_apply > 0`; gewählt → Quote und `POST /customer/orders` mit `use_wallet: true`: Guthaben wird bis zum Saldo angerechnet (Teilzahlung), Rest über die gewählte Zahlart; deckt es alles, ist `payment_methods = [{id: wallet}]` und keine Zahlart nötig. Gäste: kein `wallet` ohne `use_wallet` (mit: `reason: login_required`). Bei Aufladung bzw. Partner-Event kein `wallet`. **Verlauf:** neu `GET /wallet/me/tx?page=&organizer=` → `{ok, page, per_page (30), has_more, balance, tx: [...]}` über alle eigenen Geldbörsen, neueste zuerst; je Buchung zusätzlich (auch in `/wallet/{id}/tx`) `subtitle` (Event · Bestellung bzw. Station/Veranstalter bzw. Grund), `direction` in/out, `order_number`, `event_id`, `event_title`, `wallet_id`; Titel: Aufgeladen, Gutschein eingelöst, Bonus, Ticketkauf (Zahlung mit Bestellung), Bezahlt (Kasse/Bar), Storniert/Zahlung storniert, Erstattet, Korrektur, Verfallen, Ausgezahlt; `amount` mit Vorzeichen. **Meldungen:** Aufladung → Mitteilung/Push „Guthaben aufgeladen · x € sind jetzt auf deinem Konto“ (Aktion `wallet`) und Mail „Guthaben aufgeladen“; reiner Gutschein-Kauf → „Dein Gutschein ist da“ (Aktion `giftcards`) und Mail „Dein Gutschein ist da“; nie mehr „Deine Tickets liegen in der App“ bzw. „Deine Tickets sind bereit!“ für solche Bestellungen. |
| Guthaben-Übersicht je Kunde | Seit 1.38.477 (Rechte wie Guthaben-Einstellungen `check_manager`: Admin; Einzel-Seite Inhaber `tix_organizer`; evendis Inhaber/Team-Admin des eigenen Veranstalters). `GET /wallet/admin/customers?q=&sort=last|balance|topup|spent|name&page=&from=&to=&organizer=` → `{ok, organizer_id, scope: all|organizer, range {from,to}|null, totals {topped_up, topups, spent, spent_pos, spent_tickets, refunded, open_balance, customers}, series [{period, topped_up, topups}], series_unit: day|week (bis 62 Tage je Tag), page, per_page (50), total, has_more, customers [{user_id, name, email, wallet_ids, topups, topped_up (inkl. Bonus), spent, spent_pos (Bar/Kasse), spent_tickets, refunded, balance|null, last_activity}]}`; `&format=csv` → `{ok, filename, mime: text/csv, rows, data (Base64, UTF-8 BOM, Semikolon)}`. `GET /wallet/admin/customers/{user_id}?page=&from=&to=` → `{ok, customer {user_id, name, email, balance|null}, page, per_page (30), has_more, tx, transactions}` (Buchungen wie `/wallet/me/tx` plus `staff_user_id`, `reason`); ohne Buchungen im Bereich 404. Bereich: Einzel-Seite bzw. Plattform-Admin ohne `?organizer=` alles; Veranstalter auf evendis nur eigene Geldbörsen und Zahlungen mit dem Plattform-Guthaben bei ihm (Bar/Kasse), Kontostand (`balance`) nur bei eigenen Geldbörsen, sonst null; `open_balance` = offenes Guthaben der eigenen Geldbörsen (Verbindlichkeit). Außerdem: Verlauf-Antworten (`/wallet/me/tx`, `/wallet/{id}/tx`) zusätzlich als `transactions`; `/wallet/pay-status` zusätzlich `balance_after` (Stand nach der Buchung). |
| Guthaben-Übersicht: App-Vertrag | Seit 1.38.478: `sort` nimmt auch `topped_up` (= topup); je Kunde zusätzlich `wallet_id` (Geldbörse für das Detail) und `last_at` (= last_activity); `totals.open` (= open_balance). **Detail jetzt `GET /wallet/admin/customers/{wallet_id}`** (statt user_id) → `{ok, customer {wallet_id, user_id, name, email, balance|null}, page, per_page, has_more, tx, transactions}`; Verlauf über alle Geldbörsen dieses Kunden im eigenen Bereich. |
| Mail-Kopf in eigener Farbe | Seit 1.38.479: neues Mail-Feld `mail_header_color` (Hex `#RGB`/`#RRGGBB`, gespeichert als `#RRGGBB`; null/"" = Akzentfarbe) über `POST /studio/config` wie die übrigen Mail-Felder; ungültig → 400 `tix_invalid_field`, field `mail_header_color`. `mail_header` nimmt zusätzlich `color`: Kopf in `mail_header_color` (leer → Akzentfarbe), Logo für farbigen Grund bleibt Pflicht (sonst hell). `GET /studio/config` → `brand.mail` zusätzlich `header` = `light`\|`brand`\|`color` (wirksam), `header_color` (gespeichert oder null), `head_color` (wirksame Kopf-Farbe oder null bei hell). Heller Kopf (Luminanz) → dunkler Kopf-Text. Knöpfe und Linien bleiben in der Akzentfarbe. Gilt für alle Mails und die Galerie. |
| Plattform-Admin-Studio Paket 1 | Seit 1.38.480 (`TIX_Platform_Admin`, nur evendis, nur `manage_options`; sonst 403, auf Einzel-Seiten 404). **Veranstalter:** `GET /platform/organizers?status=pending|approved|rejected|blocked|all&q=` → `{organizers: [{id, name, slug, city, logo, status, created, owner {name, email}, events, upcoming, modules {11 Schlüssel: tickets, guestlist, pos, coupons, loyalty, wallet, giftcards, music, awareness, support, muttizettel}, requirements [{key, label, done}]}]}`; `GET /platform/organizers/{id}` → `{organizer: {…, description, website, phone, reason, terms {version, accepted_at}|null, payout {complete, iban_masked}|null, events_list [{id, title, date_start, status}]}}`; `POST …/approve` (auch bei fehlenden Angaben, `forced: true`), `…/reject {reason}`, `…/block {reason}` (Grund Pflicht, sonst 400 `tix_reason_required`), `…/unblock` (nur gesperrt, sonst 409) → `{ok, status, forced?}`; `POST …/modules {modules: {key: bool}}` → `{ok, modules}` (Tickets immer an; guestlist/pos/coupons/awareness werden gespeichert, Vorgabe an, noch ohne Sperre im Plugin). **Abrechnungen:** `GET /platform/settlements?status=draft|ready|released|paid|held|all&q=` → `{settlements: [{id, number, organizer {id, name}, event {id, title, date_start}, type, status, gross, refunds, fee, payment_fees, payout, created, note, paid_at}], totals {open (draft+ready+held), released, paid}}` (`released` = Plugin `approved`); `POST …/{id}/release` (zurückgestellt → freigeben; ohne vollständige Auszahlungsdaten bleibt bzw. wird es `draft`, Entwurf → 409 `tix_payout_details_missing`), `…/paid {ref?, date?}`, `…/hold {reason}` → `{ok, status, settlement}`. **Betreiber-Support:** Studio „Hilfe & Support“ schickt wie bisher `POST /customer/support` mit Staff-Token; solche Anfragen sind Betreiber-Anfragen (`_tix_sp_help`), erscheinen nicht mehr im Kunden-Postfach (`/support/inbox`), auf Partner-Seiten gehen sie über die Partner-Brücke an evendis (`POST /syndicate/help`, `/syndicate/help/{id}/messages`, Antworten zurück an `POST /partner/help/{id}/reply`). `GET /platform/support?status=open|all` → `{tickets: [{id, subject, status open|progress|resolved|closed, site, organizer {id, name}, from {name, email}, last_message, last_from organizer|team, updated, unread, category}]}`; `GET /platform/support/{id}` → `{ticket: {…, messages [{id, from, author, content, date}]}}` (setzt gelesen); `POST …/reply {message, status?}` (ohne status → progress), `…/status {status}` → `{ok, status}`. |
| Bestellnummer im Kurzformat | Seit 1.38.481 (`TIX_Order_Number`, Plan Bestellnummern Phase 1). Schalter je Seite `tix_order_number_mode` = `legacy` (Vorgabe, bisheriges Format) \| `short`: neue Bestellungen bekommen `<Kürzel>-<5 Zeichen><Prüfzeichen>`, z. B. `KK-7HQ4M2` (Alphabet `23456789ABCDEFGHJKMNPQRSTVWXYZ`, ohne 0/1/I/L/O/U; Prüfzeichen Luhn mod 30; zufällig und eindeutig). Kürzel `tix_order_number_code` (2–5 Zeichen A–Z/0–9; Vorgabe evendis `EV`, sonst aus dem bisherigen Präfix). Alte Nummern bleiben gültig, interne ID fortlaufend, Feld `order_number` unverändert. Einstellen über `POST /studio/config {order_number_mode, order_number_code}` (Rechte wie Marke; auf evendis Kürzel nur `EV`, ungültig → 400); `GET /studio/config` → `order_number {mode, code, example}`. **Suche** über beide Formate, ohne Bindestrich/Leerzeichen und Groß-/Kleinschreibung: Bestell-Suche (`search` in Event-Bestellungen, Studio, WP-Admin, Veranstalter-Bereich) und Support-Postfach (`/support/inbox?search=` findet Anfragen zur Bestellung); `TIX_Order::find_by_number()` für weitere Stellen, `TIX_Order::number_hint()` erkennt Tippfehler (Prüfzeichen). Außerdem: Mitteilung nach Gutschein-Kauf mit Aktion `giftcard:<Code>` (erster Code; ohne Code `tab:tickets`). |
| Wetter über den eigenen Server | Seit 1.38.482 (`TIX_Weather`): `GET /public/weather?lat=&lon=&date=&last_date=&tz=` ohne Anmeldung – Parameter wie Bright Sky `/weather` (Aliasse `lng`, `from`/`to`); `date`/`last_date` als `YYYY-MM-DD` oder `YYYY-MM-DDTHH:MM`, ohne `last_date` = `date` + 1 Tag, höchstens 10 Tage, `date` 3 Tage zurück bis 12 Tage voraus; `tz` Vorgabe `Europe/Berlin`. → Antwort von Bright Sky (DWD) **1:1** (`{weather: [...], sources: [...]}`), Kopf `X-Tix-Weather: miss\|hit\|stale`, `Cache-Control: public, max-age=1800`. Der Server ruft `api.brightsky.dev` mit eigenem User-Agent ab, Koordinaten auf 3 Stellen gerundet, Cache 30 min je Koordinate/Zeitraum/Zeitzone, bei Ausfall bis 6 h alte Antwort. Keine Geräte-IP an Bright Sky; Limit je Aufrufer 60/min über einen 60 s gültigen HMAC der IP (keine IP gespeichert), gesamt `tix_weather_daily_limit` Abrufe je Tag (Vorgabe 5000). Fehler: `tix_weather_invalid` 400 (`data.field`), `tix_weather_limit` 429, `tix_weather_none` 404 (keine Daten für den Ort), `tix_weather_error` 502, `tix_weather_off` 404 (Filter `tix_weather_enabled`). Apps tauschen nur die Basis-URL (`https://<Seite>/wp-json/tixomat/v1/public/weather`). Seit 1.38.483 nutzt auch die Web-Ticketseite (Schalter `ht_weather_enabled`) diese Route statt Open-Meteo im Browser: Koordinaten aus dem (kanonischen) Ort bzw. `_tix_venue_lat/_lng`, fehlen sie, ermittelt der Server sie im Hintergrund aus der Adresse (`TIX_Venues::queue_event_geocode`); angezeigt wird die Stunde des Beginns mit „Quelle: DWD“ (bis 7 Tage voraus, bis 2 Tage danach). |
| Kasse: Gutschein-Code, Kasse & Bar, Vorschau | Seit 1.38.484: **Gutschein-Code an der Kasse** (`POST /pos/orders {…, coupon_code}`, App-Kasse und Studio): Geschenkgutschein wird bis zum Restwert abgezogen (Rest bleibt auf dem Gutschein, Einlösung mit Kanal `pos`), Rabatt-Codes wie online; ungültiger Code → 409 `tix_coupon` (`field: coupon_code`) statt still ignoriert. Antwort zusätzlich `subtotal`, `discount`, `coupon {code, type giftcard\|discount, amount, remaining}` (remaining nur Geschenkgutschein). Neu `POST /pos/quote {event_id, items[{index, qty}], coupon_code?, payment?}` → `{ok, subtotal, discount, total, coupon {code, type, amount, balance, remaining}\|null}` (Vorschau, bucht nichts). **Storno** `POST /pos/orders/{id}/void` gibt den Gutschein zurück (Geschenkgutschein: Betrag wieder gutgeschrieben, Rabatt-Code: Nutzung zurück; idempotent) → zusätzlich `coupon_restored {code, type, amount, balance}\|null`. **Bericht** `GET /pos/report` zusätzlich `vouchers {giftcard, discount, orders}` und `gross_revenue` (= `total_revenue` + mit Geschenkgutschein bezahlt; `total_revenue` bleibt das kassierte Geld). **Rolle „Kasse & Bar“ (`tix_cashier`):** `GET /pos/transactions` erlaubt, aber nur eigene Verkäufe von heute (`scope: own`; anderer Tag → 403 `tix_cashier_scope`), Storno nur eigene von heute (sonst 403 `tix_cashier_scope`); Tagesbericht bleibt Inhaber/Team. `GET /pos/transactions?event_id=` filtert nach Event; je Buchung zusätzlich `staff_id`, `event_id`, `coupon {code, type, amount}`, `can_void`; Antwort zusätzlich `event_id`, `scope own\|all`. |
| order_number in Ticket-Ausgaben | Seit 1.38.484 neben `order_id` die Bestellnummer aus der Mail (`order_number`, ohne Bestellung `null`): `GET /customer/tickets` (alle Quellen), `GET /customer/tickets/{id}` bzw. Ticket-Detail der App, Staff `GET /events/{id}/tickets` und Einlass-Liste `GET /checkin/{event_id}/list` (`items[]`). Guthaben-Verlauf (`/wallet/me/tx`, `/wallet/{id}/tx`, `tx` und `transactions`) liefert `order_number` schon seit 1.38.477. Helfer `TIX_Order::number_for($order_id)`. |
| Rechnungs-Kern (Rechnungen, Korrekturen, Belege) | Seit 1.38.485 (`TIX_Invoices`, `TIX_Invoice_PDF`, `TIX_Invoice_Export`; Plan Bestellnummern/Rechnungen Phase 2). **Schalter je Seite `tix_invoices_enabled` (Vorgabe aus, schaltet der User nach dem Steuerberater)**; solange an, legt der alte Anbieter-Weg (`TIX_Invoicing`/Lexoffice) keine Rechnungen an. Belege in Tabelle `tix_invoices` (nie geändert/gelöscht, auch nicht bei Kontolöschung): `R` Rechnung bei bestätigter Zahlung (`tix_order_completed`, Betrag > 0), `RK` Rechnungskorrektur bei Erstattung (`tix_order_refund_recorded`, anteilig je Steuersatz), Storno/Erstattung per Status und Rückbuchung (Rest), `B` Beleg ohne USt für Guthaben-Aufladung und Gutschein-Kauf (Mehrzweck), `BK` Belegkorrektur. Nummer `<Serie>-<Kürzel>-<Jahr>-<00001>`, lückenlos je Aussteller/Serie/Jahr (`tix_invoice_counters`, Vergabe in einer Transaktion mit Sperre). Aussteller: Einzel-Seite `site` (Firmendaten aus den Einstellungen); evendis Modell A (`tix_invoice_issuer_model` = `organizer`, Vorgabe) je Veranstalter `org:<id>` aus den Auszahlungsdaten (Rechnung im Namen des Veranstalters), Servicegebühr an den Kunden als eigene Rechnung von evendis (`platform`); Modell B (`platform`) alles von evendis. Steuersatz: Kategorie `tax_rate` → Event `tax_rate` (`_tix_tax_rate`) → Aussteller → Seite; Kleinunternehmer = 0 % mit § 19-Hinweis; Geschenkgutschein-Einlösung ist Zahlung (USt auf den vollen Preis), Rabatt-Codes mindern anteilig je Satz. Fehlen Pflichtangaben (Name, Anschrift, Steuernummer bzw. USt-IdNr.), wartet die Bestellung (`pending`); seit 1.38.526 ohne automatisches Nachholen (nur per Klick, siehe unten). Abendkasse (`pos_*`) nur mit Schalter `tix_invoice_pos`. PDF im CI (Akzentfarbe, Logo), Ablage im geschützten Ordner `invoices/<Jahr>/` mit SHA-256; fehlt/ändert sich die Datei, wird aus dem Schnappschuss neu erzeugt. **REST:** `GET /customer/orders/{id}/invoices` (Konto oder `?key=order_key`) → `{ok, order_id, enabled, pending, invoices: [{id, number, kind invoice\|correction\|receipt, series, issuer {key, name, organizer_id}, order_id, order_number, ref {id, number}, reason, issued_at, service_date, recipient {name, company, email}, currency, net, tax, gross, small_business, taxes [{rate, net, tax, gross}], payments [{label, amount}], pdf, lines [{type ticket\|discount\|fee\|voucher\|refund, text, detail, qty, unit, gross, rate}]}]}`; `GET /invoices/{id}/pdf` (Kunde, `?key=`, Aussteller, Admin) → `{ok, filename, mime, stored, data}` (base64); `GET /organizer/invoices?from=&to=&q=&kind=&page=&per_page=&format=csv\|datev` (Inhaber/Admin; evendis je Veranstalter, Admin alle bzw. `?organizer=`/`?issuer=platform`) → `{ok, issuer, enabled, totals {count, net, tax, gross}, pending, page, per_page, total, has_more, invoices}` bzw. Datei `{filename, mime, rows, data}` (DATEV: EXTF 700 Buchungsstapel, Windows-1252, Konten einstellbar, Vorgaben SKR03 10000/8400/8300/8200/1700); `GET\|POST /studio/invoice-config` → `{enabled, issuer_model, issuer {key, name, address_lines, tax_id, vat_id, small_business, complete, missing, source}, code, code_set, tax_rate, tax_rate_set, company, datev, next {R, RK, B}, counters, pending}`; POST `{code, tax_rate, small_business, company {name, address, tax_id, vat_id, email, phone, managing_director, register_court, register_number, footer}, datev {consultant, client, account_length, debitor, revenue_19, revenue_7, revenue_0, voucher}, enabled, issuer_model}` – Veranstalter nur `code`/`tax_rate`/`datev` (Firmendaten und Kleinunternehmer über die Auszahlungsdaten → 400), `enabled`/`issuer_model` nur Admin (403), Kürzel 2–6 Zeichen und eindeutig (409 `tix_code_taken`). App-Events: `tax_rate` je Event und je Kategorie (Zahl 0–30, `null` = erben; ungültig 400 mit `field`). Hook `tix_invoice_created($id, $kind, $order_id)`. |
| App-Startseite „Jetzt“ (home.now) | `/app/config` speichert und liefert das App-JSON 1:1 (auch `home.help_live`). Seit 1.38.486 wird `home.now` beim Speichern (`POST /app/config`) geprüft und normalisiert: `{show: bool, style: "section"\|"badge", hours: 1–12}` (Vorgaben style section, hours 3; ungültig → 400 `bad_data` mit `field` `home.now.style`/`home.now.hours`). `GET /app/config` und `GET /app/config/edit` (Studio/App-Editor) liefern es mit. |
| Geschenkgutschein bei Storno/Erstattung online | Seit 1.38.486 gibt ein Storno (`tix_order_cancelled`, z. B. volle Erstattung im Admin/Studio) bzw. der Status „erstattet“ (z. B. Mollie) einen eingelösten Gutschein zurück wie die Kasse: Geschenkgutschein bekommt den Betrag wieder gutgeschrieben (Eintrag in `redemptions` mit negativem Betrag, Kanal `online` bzw. `pos`), Rabatt-Code eine Nutzung zurück; Notiz an der Bestellung; idempotent (`TIX_Giftcards::restore_for_order`). Teil-Erstattungen geben nichts zurück. Nur bezahlte Bestellungen (Einlösung erfolgt). |
| Rechnungen: Zustellung (Phase 3) | Seit 1.38.487, alles nur bei `tix_invoices_enabled`: **Mail-Anhang** – Bestellmail (auch „erneut senden“) mit Rechnung/Beleg als PDF und Hinweis; Storno-/Erstattungs-Mail mit der dabei entstandenen Rechnungskorrektur (Rechnungen entstehen jetzt vor der Mail: `tix_order_completed` Prio 5, Korrektur bei Statuswechsel Prio 5). **Konto/App** – `GET /customer/orders/{id}` und die Antwort von `POST /customer/orders` zusätzlich `invoices: [{id, number, kind, series, gross, issued_at, ref, pdf, download_url}]` (`download_url` über den Bestell-Schlüssel, ohne Anmeldung; `admin-post.php?action=tix_invoice_download&order_id&order_key&invoice_id`); das bisherige Feld `invoice_url` und das Web-Konto zeigen auf die eigene Rechnung (R, sonst B). **Studio** – `GET /orders/{id}/detail` zusätzlich `invoices[]`, `invoices_enabled`, `invoice_pending`; `GET /customer/orders/{id}/invoices` auch für Inhaber/Team der Bestellung; `POST /invoices/{id}/send {email?}` (Inhaber/Team/Admin; Vorgabe Rechnungs-E-Mail; ungültig 400) → `{ok, email}`; `POST /orders/{id}/invoices/issue` (fehlende Belege anlegen, auch für Bestellungen vor dem Einschalten; Schalter aus 409 `tix_invoices_off`, unbezahlt 409 `tix_order_unpaid`) → `{ok, created [Nummern], pending [Aussteller], invoices}`; `GET /studio/config` zusätzlich `invoices {enabled, model}`. **WP-Admin** – Tixomat → Rechnungen (Schalter, Modell, Aussteller-Status mit fehlenden Angaben, Kürzel/Steuersatz/Kleinunternehmer, Nachholen, Liste mit Filter, CSV/DATEV, PDF, Senden); Bestellansicht zeigt alle Belege mit PDF/Senden und „Jetzt erstellen“ (ersetzt nie). |
| Gewinnspiele im Studio | Seit 1.38.489 (`TIX_Raffle_API`; Rechte `check_organizer` + Event-Zugriff, evendis je Veranstalter): `GET /raffles?range=upcoming\|past` → `{ok, range, raffles:[{event_id, title, date_start, enabled, status open\|closed\|drawn, end_date "Y-m-d H:i", entries, prizes (Anzahl), drawn_at}]}` (vergangen = ausgelost oder Event vorbei). `GET /events/{id}/raffle` → `{ok, event_id, raffle:{enabled, title, description, end_date, max_entries, consent_text, hide_count, auto_draw, prizes:[{name, qty, type text\|ticket, cat_index, per_winner}], status, entries, drawn_at, winners:[{entry_id, name, email, prize_index, prize_name, per_winner, ticket_codes[], notified_at\|null}]}, categories:[{index, name}]}`. `POST /events/{id}/raffle` (nur mitgeschickte Felder; `end_date` "Y-m-d H:i" oder leer; Preis: Name, qty 1–500, per_winner 1–20, Freikarte mit gültiger `cat_index`; ungültig 400 mit `field`; Preise ändern nach der Auslosung 409 `tix_raffle_drawn`) → wie GET. `GET /events/{id}/raffle/entries?q=&page=` (50 je Seite) → `{entries:[{id, name, email, created_at, is_winner, prize_name}], total, page, per_page, has_more}`; `format=csv` → `{filename, mime, rows, data}`. `POST /events/{id}/raffle/draw {notify}` (Vorgabe true; false = ohne Gewinner-Mail) → `{ok, status, notified, winners, raffle}`; 409 `no_entries`, `no_prizes`, `already_drawn`. `POST /events/{id}/raffle/notify {entry_id?}` (einzeln oder alle, setzt `notified_at`) → `{ok, sent}`; vor der Auslosung 409 `not_drawn`, Nicht-Gewinner 404 `tix_raffle_winner`. `POST /events/{id}/raffle/reset` storniert die Freikarten und öffnet wieder → wie GET; eingecheckte Freikarte 409 `tix_raffle_checked_in`. **Automatische Auslosung** am Teilnahmeschluss bleibt, je Gewinnspiel abschaltbar (`auto_draw`, Meta `_tix_raffle_auto_draw` = '0'; Vorgabe an). Gewinner-Mail jetzt im Mail-Layout mit Ticket-Knöpfen. |
| Studio „Hilfe & Support“: Themen, scope=help | Seit 1.38.490: `POST /customer/support` nimmt vom Personal (Studio) die Themen `studio_setup` (Einrichtung & Events), `studio_tickets` (Tickets & Kasse), `studio_app` (App & Mitteilungen), `studio_payout` (Abrechnung & Auszahlung), `studio_invoices` (Rechnungen), `studio_bug` (Fehler melden), `studio_contract` (Vertrag & Paket), `studio_other` (Sonstiges) an (vorher Rückfall auf `other`); mit `scope=help` wird ein unbekanntes Thema zu `studio_other`. Das Thema geht über die Partner-Brücke an evendis und steht im Admin-Studio (`/platform/support`, `category` + neu `category_label`) und im WP-Admin (Bezeichnung). `GET /customer/support?scope=help` → nur Anfragen an den Plattform-Betreiber, je mit `help: true`, `category`, `category_label`; Antwort zusätzlich `scope`; ohne `scope` unverändert. Personal-Konten werden vom Schalter „Kunden-Support“ der Seite nicht blockiert (Anlegen, Liste, Verlauf, Antwort) – so erscheinen Antworten von evendis auch auf Seiten mit ausgeschaltetem Kunden-Support im Verlauf. `GET /support/config` für Personal zusätzlich `help_topics [{slug, label}]` und immer `customer_enabled` (Kunden-Support der Seite; `enabled` = für den Aufrufer nutzbar). Studio-Themen erscheinen nicht in Kunden-Formularen. Grenzen: Kunden 5 Anfragen je 10 Minuten und IP (wie bisher); Personal seit 1.38.491 je Konto 30 Anfragen bzw. 60 Antworten je 10 Minuten (mehrere Leute im selben Büro blockieren sich nicht); darüber 429 `tix_rate_limit`. |
| Auto-Archiv kurz nach dem Ende | Seit 1.38.492 (`TIX_Archive`): Prüfung stündlich statt täglich (bestehender Tageslauf wird umgestellt). Archiviert wird, wenn das echte Ende + Gnadenfrist vorbei ist: Ende = Enddatum (sonst Startdatum) + Endzeit (Endzeit vor Beginn ohne eigenes Enddatum = nächster Tag); ohne Endzeit Beginn des letzten Tages + 7 h; ohne Uhrzeit Ende des letzten Tages. Gnadenfrist in Stunden (Option `tix_archive_grace_hours`, Vorgabe 1 h; eine alte Tage-Einstellung > 0 gilt als Tage × 24, bis Stunden gesetzt sind). Neu: Entwürfe ausnehmen (Option `tix_archive_skip_drafts`, Vorgabe aus). Schalter bleibt `auto_archive_enabled` in den Einstellungen. Studio/App: `GET /studio/config` → `archive {enabled, grace_hours, skip_drafts, interval: "hourly", next_run}`; `POST /studio/config {archive: {enabled?, grace_hours? (0–720), skip_drafts?}}` (Rechte wie Marke: Admin, auf Einzel-Seiten auch der Inhaber; ungültig 400 `field: archive.grace_hours`); nach dem Einschalten läuft die Prüfung nach etwa 1 Minute. Seit 1.38.493: `POST /studio/config` nimmt das Archiv auch flach (Multipart) an: `archive_enabled=1\|0`, `archive_grace_hours`, `archive_skip_drafts=1\|0` (Fehler mit demselben `field`, z. B. `archive.grace_hours`). **evendis je Veranstalter:** Inhaber bzw. Team-Admin sehen und setzen über `GET\|POST /studio/config` die Werte für die eigenen Events (Organizer-Meta `_tix_archive_config`; `null`/leer = wieder Vorgabe); `archive` dann zusätzlich `scope: "organizer"`, `own` (selbst gesetzte Schlüssel) und `platform_default {enabled, grace_hours, skip_drafts}`; Veranstalter dürfen in `/studio/config` nur das Archiv ändern (sonst 403). Der Plattform-Admin setzt die Vorgabe (`scope: "platform"`); der Sweep nutzt je Event die Werte des Veranstalters. **Sichtbarkeit:** archivierte Events fehlen in den Event-Listen der WordPress-Website und stehen im Studio/App-Personalbereich unter „Archiv“ (`view=archived`); die Event-Seite bleibt per Link erreichbar, Tickets/Bestellungen/Berichte bleiben, und die öffentlichen Listen für App und evendis.de (`/public/events?filter=past`, Katalog) zeigen sie weiter als vergangene Events. WP-Admin-Karte „Auto-Archiv“: Gnadenfrist in Stunden + „Entwürfe nicht archivieren“. |
| Kunden im Studio/App | Seit 1.38.494 (`TIX_Customers`). **Rechte:** Admin; Einzel-Seite zusätzlich Inhaber (`tix_organizer`); evendis Inhaber/Team-Admin (nur eigene Kunden); sonst 403 (Team ohne Admin, Einlass, Kasse). **Kunde** = Käufer bezahlter/erstatteter/stornierter Bestellungen (über die E-Mail zusammengefasst, Gastbestellungen gleicher E-Mail gehören zum Konto), Gäste der Gästelisten mit E-Mail, Konten mit Geldbörse; evendis nur Bestellungen mit Positionen eigener Events (Umsatz/Tickets = eigener Anteil) und eigene Gästelisten, Follower nur als Zahl. **ID** stabil: `u<user_id>` bzw. `e<16 Hex>` (HMAC der E-Mail). `GET /customers?q=&sort=last\|name\|orders\|revenue\|balance (Alias wallet)&dir=asc\|desc&from=&to=&source=all\|orders\|guestlist\|wallet&has_wallet=1&page=&per_page=(≤100)&format=csv&organizer=(Admin)` → `{ok, scope site\|organizer\|platform, organizer_id, totals {customers, new, revenue, followers?, wallet {topped_up, spent, open}?}, customers [{id, user_id, name, email, phone, orders, tickets, revenue, first_order_at, last_order_at, has_account, sources[], wallet_balance, wallet_topped_up, wallet_spent, has_wallet?, loyalty_points, range_orders?, range_revenue?}], page, per_page, total, has_more}` (mit Zeitraum: nur Kunden mit Bestellungen darin, `new` = erste Bestellung im Zeitraum, sonst letzte 30 Tage); CSV `{filename, mime, rows, data}` wird protokolliert. `GET /customers/{id}` → `{customer {…, address, account_created_at, orders [{id, order_number, status, total, created_at, events [{id, title, tickets}], tickets}], tickets [{id, code, event_id, event_title, status, checked_in}], wallet {id, balance, topped_up, spent, transactions[] (je Buchung u. a. type, channel, title, reason, staff_name, manual)}\|null, wallet_id, loyalty {points}\|null, support [{id, subject, status, updated_at}], giftcards [{code, value, balance, expires}], guestlists [{event_id, event_title, plus, checked_in}], can_delete, delete_blockers[]}}`; fremd/unbekannt 404. **Guthaben manuell** `POST /customers/{id}/wallet {amount (€ ±, ≤ 500), reason, notify?, as?: bonus\|paid, organizer_id? (evendis), idempotency_key?}` (Admin; Einzel-Seite auch Inhaber; evendis nur Plattform-Admin, sonst 403 `tix_customer_wallet_forbidden`) → Buchung `adjust`, Kanal `admin`, mit Bearbeiter + Grund; Verlauf-Titel „Gutschrift vom Team“ bzw. „Korrektur vom Team“; Gutschrift als Bonus (`as: paid` = bezahltes Guthaben); Höchstguthaben 409 `tix_wallet_limit_balance`, nicht unter 0 (409 `tix_wallet_insufficient`), Gast ohne Konto 409 `tix_customer_no_account`; `notify` → Mitteilung „Dir wurden X € gutgeschrieben“ (Aktion `wallet`) → `{ok, transaction, balance, notified}`. **Löschen** `POST /customers/{id}/delete {confirm, confirm_wallet?}` bzw. `DELETE /customers/{id}?confirm=1` (Einzel-Seite Admin + Inhaber, evendis nur Plattform-Admin, sonst 403 `tix_customer_delete_forbidden`): `confirm: false` → `{preview: true, summary {orders, retained_orders, tickets, wallet_balance, loyalty_points, giftcards, account}, blockers [protected, wallet]}`; `confirm: true` wie „Konto löschen“ (Konto weg, Rechnungsdaten bezahlter Bestellungen 10 Jahre, Tickets gültig; Gäste über die E-Mail, Hook `tix_guest_deleted`) → `{deleted {orders_anonymized, tickets, support, account}, retained [{order_id, order_number, retained_until}], retention}`; 409 `tix_customer_protected` (Admin/Personal/Veranstalter), 409 `tix_customer_wallet` (Guthaben ohne `confirm_wallet`). Alias `/platform/customers…` (nur Plattform-Admin). **Änderungsprotokoll** Tabelle `tix_audit_log` (Löschung, CSV-Export, Guthaben-Buchung: wer, wann, Kunden-ID, Zusammenfassung ohne Klartextdaten), Hook `tix_audit_logged`; `GET /customers/audit?page=` → `{entries [{id, at, actor {id, name}, action, customer_id, scope, summary}]}`. |
| Geschenkgutschein verschicken, Bar-Abbuchung ohne Ticketkauf | Seit 1.38.496 (`TIX_Giftcard_Send`, `TIX_Giftcard_Pos`). `{code}` = GS-Code. **Beim Kauf:** `gift` je Gutschein-Position in `POST /customer/cart/quote` bzw. `/customer/orders` zusätzlich `recipient_email` (klein geschrieben gespeichert, ungültig → 400 `items[n].gift.recipient_email`) und optional `send_at` (ISO 8601 bzw. `YYYY-MM-DD` = 9 Uhr Ortszeit, Zukunft, ≤ 400 Tage; ohne E-Mail bzw. Vergangenheit → 400 `items[n].gift.send_at`); Echo in `lines[].gift` (`send_at` als ISO mit Zeitzone). Nach der Bezahlung geht der Gutschein sofort bzw. zum Termin an diese Adresse; die Bestätigung an den Käufer nennt „Verschickt an …“ bzw. „Wird verschickt am …“, nach einem geplanten Versand bekommt der Käufer eine kurze Bestätigung. Website-Kasse: Felder E-Mail + Datum im Geschenk-Kasten. **Verschicken:** `POST /giftcards/{code}/send {email (Alias recipient_email), name? (= recipient_name), message? (≤ 200), from_name?, send_at?, copy?, key? (Bestell-Key für Gäste)}` → `{ok, scheduled, code, sent {email, at}\|null, sent_to, sent_at, send_at, recipient_name, recipient_email, from_name, message, sends [{at, email, mode manual\|purchase\|scheduled, ok}] (letzte 5)}`; gesendete Angaben ersetzen die Geschenk-Angaben (auch im PDF); erneut senden jederzeit; `copy: true` = Kopie an den Aufrufer (Käufer: Bestell-E-Mail). `DELETE /giftcards/{code}/send` sagt einen geplanten Versand ab. **Rechte:** Admin; Käufer (Konto, bestätigte Bestell-E-Mail oder `key`); Einzel-Seite Rolle Veranstalter (Inhaber/Team); evendis Inhaber/Team-Admin nur für Gutscheine des eigenen Veranstalters (Plattform-Gutscheine nur Admin); sonst 401/403. Fehler: 404 `tix_giftcard_unknown`, 400 `tix_invalid_field` (`email`, `name`, `message`, `from_name`, `send_at`; Termin nach Ablauf), 409 `tix_giftcard_expired`, 409 `tix_giftcard_balance` (kein Rest), 429 `tix_rate_limit` (10 je Konto bzw. IP pro Stunde, 5 je Gutschein pro Tag), 502 `tix_mail_failed`. **Am Gutschein** (`tix_coupons[code]`): `gift.recipient_email`, `sent_to`, `sent_at` (ISO), `send_at` (geplant), `sends[]`. Ticket-/Gutschein-Antworten (`gift_fields`) zusätzlich `gift_recipient_email`, `gift_send_at`, `gift_sent_to`, `gift_sent_at` (ISO, null = nie). `GET /giftcards/admin` `recent[]` und `GET /customers/{id}` `customer.giftcards[]` zusätzlich `sent {email, at}\|null`, `sent_to`, `sent_at`, `send_at` (in `recent` E-Mail nur für `can_edit`, sonst null). **Mail an die beschenkte Person** (Mail-CI, `TIX_Mail_Layout`, neuer Baustein `qr {src, value, caption, size}`): Betreff „<Marke> – <Von> schenkt dir einen Gutschein“ bzw. „… – Ein Gutschein für dich“; Anrede, Nachricht, großer QR (240 px), Code, Betrag/Rest/Gültig bis/Gilt bei, Pflichttext „So löst du deinen Gutschein ein:“ mit Punkt 1 (Kasse/Bar) und Punkt 2 (App: „Lade den Gutschein in die <App-Name>-App …“, App-Name ohne angehängtes „App“) – Punkt 2 und die App-Store-Knöpfe nur, wenn die Seite eine App hat (`TIX_Studio::app_info().has_app`) und Guthaben für den Bereich des Gutscheins an ist (`TIX_Wallet_Balance::scope_enabled`); ohne Punkt 2 ohne Nummer. Gutschein-PDF im Anhang; Hinweis im PDF entsprechend gekürzt. **QR-Inhalt:** reiner Text, der GS-Code (`GS-XXXX-XXXX`, Großbuchstaben); Bar-Kasse und „Gutschein einlösen“ (`/wallet/redeem-giftcard`) lesen dasselbe, dazu Ticket-QR `GL-<event>-<ticketcode>` und Ticket-Code des Gutschein-Tickets (`TIX_Wallet_Balance::giftcard_code_from_input`). **Bar/Kasse ohne Ticketkauf** (Rechte `check_pos`: Kasse & Bar, Personal, Veranstalter, Admin; evendis nur Gutscheine des eigenen Veranstalters, sonst 409 `tix_giftcard_scope`): `GET /pos/giftcard/{code}` → `{ok, code, value, balance, expires, status active\|expired\|used, usable, organizer_id, organizer_name, recipient_name, redemptions [{id, at, amount, channel, rest, voided}] (neueste zuerst, 10)}`; `POST /pos/giftcard/redeem {code, amount (auch "12,50"), event_id?, idempotency_key?}` → `{ok, code, amount, redeemed, balance_before, balance_after, balance, value, expires, used_up, redemption_id, replay}` (gleicher Schlüssel = gleiche Antwort ohne zweite Abbuchung); `POST /pos/giftcard/void {code, redemption_id}` → `{ok, code, amount, balance, balance_after, replay}` (Kasse & Bar nur eigene von heute, sonst 403 `tix_cashier_scope`). Fehler: 404 `tix_giftcard_unknown`, 409 `tix_giftcard_expired`, 409 `tix_giftcard_balance` (zu wenig bzw. kein Rest, mit `balance`), 400 `tix_invalid_field` (`amount`), 429 `tix_rate_limit`. Protokoll in `redemptions` mit Kanal `bar` (`by`, `event_id`, `ref pos:<login>`, `id`), Storno als Gegenbuchung (`void_of`); aufgebraucht → Gutschein-Ticket entwertet wie bisher; gleiche Sperre wie `/wallet/redeem-giftcard`. |
| Löschen: Geschenkgutscheine anonymisieren | Seit 1.38.497 (`TIX_Account_Delete`, „Konto löschen“, Studio-Kunden löschen, Gast-Löschung): **Käufer gelöscht** (Gutschein gehört zu einer seiner Bestellungen) → `gift.recipient_email`, `sent_to`, `sends[].to` = dieselbe anonyme Adresse wie an der Bestellung (`gelöscht-<hash>@removed.invalid`), `gift.recipient_name`/`gift.from_name` = `[Gelöscht]`, `gift.message` leer. **Beschenkte Person gelöscht** (ihre E-Mail als `gift.recipient_email`, `sent_to` oder `sends[].to`, Groß/Klein egal; bei Konten nur mit bestätigter E-Mail) → nur `recipient_email`, `recipient_name`, `sent_to`, `sends[].to`; Käufer-Angaben bleiben. Ein geplanter Versand (`send_at`) wird in beiden Fällen abgesagt. Code, Wert, Restwert, Gültigkeit und Einlösungen bleiben, der Gutschein bleibt einlösbar. Antwort/Hook-Info von `delete()`/`delete_guest()` (`tix_account_deleted`, `tix_guest_deleted`) zusätzlich `giftcards` = Anzahl geänderter Gutscheine. |
| App-Funktionen an/aus (Studio + App) | Seit 1.38.498 (`TIX_Features`). Ein Block für alle Schalter; die bestehenden Einzelschalter bleiben die Quelle der Wahrheit (`features` spiegelt und setzt sie, kein zweiter Zustand). **Keys** (`order`): `awareness` (Sammelschlüssel: `enabled` = Stufe 2 oder 3 an, dazu `report`, `alarm`; einzeln auch `awareness_report` = `tix_awareness_flags.report_enabled` und `awareness_alarm` = `alarm_live`), `app_coupons` (neu, Option `tix_app_coupons_enabled`, Vorgabe an), `music` (`tix_music.enabled`; evendis + Modul `music`), `tips` (neu, `tix_app_tips_enabled`, an), `notifications` (neu, `tix_broadcast_enabled`, an), `loyalty` (`_tix_loyalty_enabled`; evendis je Veranstalter `_tix_org_loyalty.enabled` + Modul), `wallet` (`_tix_wallet.enabled`; evendis Plattform `tix_wallet_platform`, je Veranstalter Modul `wallet`), `giftcards` (`tix_giftcard_settings.enabled`; evendis je Veranstalter Modul), `party_game` (`tix_party.enabled`), `sos` (`_tix_app_nav_sos.enabled`), `muttizettel` (Einzel-Seite neu `tix_muttizettel_enabled`, an; evendis je Veranstalter Modul), `raffles` (neu, global `tix_raffles_enabled`, an; Event-Einstellungen bleiben), `live_now` (`_tix_app_config.home.now.show`, fehlt = an; übrige App-Config bleibt). **Öffentlich:** `GET /app/config` → `features {<key>: {enabled, can_edit}}` (immer, auch bei leerer App-Config ohne `ok`; `awareness` zusätzlich `report`, `alarm`; `can_edit` für den angemeldeten Aufrufer, sonst false), `GET /info` → `app_features` (gleich); evendis mit `?organizer=<id\|slug>` = wirksamer Zustand des Veranstalters (Musikwunsch zusätzlich Plattform-Schalter), ohne = Plattform (`loyalty`/`muttizettel` dort true = je Veranstalter möglich). **Studio:** `GET /studio/config` → `app_features {scope site\|platform\|organizer, organizer_id, features {<key>: {key, label, enabled, status on\|off\|unavailable, can_edit, scope, available, hint}}, order[]}` (der bisherige nur lesbare Block `features` bleibt unverändert). Setzen: `POST /studio/config {app_features: {<key>: bool \| {enabled: bool}}}`, Multipart `feature_<key>=1\|0` (oder `app_features` als JSON-Text), bzw. `GET/POST /studio/features` (gleiches Format, `no-store`; POST auch flach `{<key>: bool}`). `awareness`: false = Stufe 2 und 3 aus, true = nur Stufe 2 an, `{report?, alarm?}` einzeln. Erst alles prüfen, dann schreiben: unbekannt bzw. kein bool → 400 `tix_invalid_field` (`field app_features.<key>`), nicht freigeschaltet (Lizenz) → 409 `tix_module_disabled`, nicht erlaubt → 403 `rest_forbidden` (`field`). **Rechte:** Admin; Einzel-Seite Inhaber (Rolle Veranstalter) für alles; evendis: Plattform-Schalter nur Admin (Awareness ausdrücklich Admin vorbehalten), Inhaber/Team-Admin schalten `music`, `loyalty`, `wallet`, `giftcards`, `muttizettel` für den eigenen Veranstalter (auch über `/studio/config`; andere Keys dort weiter 403); Admin mit `?organizer=` bzw. `organizer_id` bearbeitet die Module eines Veranstalters; Prämien/Muttizettel gibt es auf evendis nur je Veranstalter. Personal, Kasse 403, ohne Anmeldung 401. Hook `tix_features_changed`. **Aus heißt:** `/public/app-coupons` → `{enabled: false, coupons: []}` (Codes bleiben gültig); `/public/tips` → `{enabled: false, tips: []}`; `notifications` aus → `POST /notifications/broadcast` 409 `tix_feature_off`, Info-Nachrichten (Broadcast, Nachrichten an Käufer, Typen message/promo/news/tip) werden nicht angelegt und im Feed ausgeblendet, Transaktionales (Tickets, Erinnerungen, Guthaben, Gutschein, Support, Awareness, Abrechnung) kommt weiter; `GET /notifications` je Eintrag `kind info\|transactional` und `broadcast_enabled`; `raffles` aus → Gewinnspiel-Infos in `/public/events` null, Teilnahme abgelehnt, Website-Block aus; `sos` aus → `/app/sos` `{enabled: false, numbers}` (Notrufnummern bleiben immer erreichbar); `/public/site-config` `features.muttizettel` folgt dem Schalter. Übrige wie bisher (`/music/status`, `/party/config`, `/wallet/config`, `/giftcards`, `/loyalty/config` melden `enabled: false`). Daten bleiben erhalten. **Hörproben** (1.38.524, User-Entscheid): Schlüssel `artist_previews` („Hörproben (Künstler)“), Option `tix_artist_previews_enabled`, **Vorgabe aus**; erscheint in `GET /app/config` `features.artist_previews {enabled, can_edit}`, `GET /studio/config` `app_features.features.artist_previews` und `GET/POST /studio/features`. Setzen: **Studio** über `POST /studio/features {app_features: {artist_previews: bool}}` bzw. `POST /studio/config {app_features: …}`; **App-Veranstalterbereich** über `POST /app/config {features: {artist_previews: bool}}` (seit 1.38.524 nimmt `POST /app/config` `features`/`app_features` an, gleiche Rechte und Prüfung wie `/studio/features`, wird nie in die App-Inhalte geschrieben; nur Schalter geschickt → Inhalte bleiben, Antwort `{ok, features}`). Einzel-Seiten: Admin und Inhaber; evendis: Plattform-Schalter, nur Admin. |
| Guthaben: Gültigkeit, Bonus-Verfall, Konto löschen mit Restguthaben | Seit 1.38.499 (`TIX_Wallet_Balance`, `TIX_Account_Delete`). `GET /wallet/config` zusätzlich: `validity {paid_years: 3, paid_rule: "end_of_year", bonus_days (nur wenn Bonus verfällt, sonst 0), bonus_expires, paid_text, bonus_text, text}`, `validity_text`, `on_account_delete` (`support` \| `block`, aus `_tix_wallet_delete_policy`), `on_account_delete_text` = `delete_text`, `scope_text` („Wo gilt es?“: Einzel-Seite, evendis-Plattform bzw. „Gilt nur bei <Veranstalter>“). Geldbörse (`GET /wallet` u. a. `wallet_payload`) zusätzlich `paid_valid_until` (Y-m-d, 31.12. des dritten Jahres nach der ältesten noch offenen Aufladung) und `bonus_expiring {amount, on}\|null` (nächster verfallender Bonus, nur mit `bonus_expires`). **Bezahltes Guthaben** wird nur ausgewiesen, nie automatisch ausgebucht (gesetzliche Verjährungsfrist, kein Verfall). **Bonus-Guthaben:** Einstellungen `bonus_days` (0–3650, Vorgabe 365, 0 = unbegrenzt) und `bonus_expires` (Vorgabe aus) über `POST /wallet/admin/config`, je Bereich (evendis je Veranstalter). Mit `bonus_expires` bucht der tägliche Lauf `tix_wallet_bonus_expire` abgelaufenen Bonus als `expire` (FIFO: ältester Bonus zuerst verbraucht, Restposten aus Gutschriften minus allen Abgängen), mit Mitteilung „Bonus-Guthaben verfallen“; bezahltes Guthaben bleibt unberührt. **Konto löschen** (`/auth/delete`, Website, `POST /customers/{id}/delete`): Bonus verfällt (Buchung `expire`), bezahltes Restguthaben bleibt als offener Posten an der geschlossenen Geldbörse; ist welches da, entsteht automatisch eine Support-Anfrage „Auszahlung Restguthaben X €“ (Kategorie `cancellation`, Quelle `wallet_payout`, mit Geldbörsen-Bezug; der Kunde erhält die übliche Bestätigung und wird um Kontoinhaber + IBAN gebeten). Antworten zusätzlich `wallet_payout_request {id, amount, retain_until}\|null`. **Datenschutz:** Die Anfrage behält E-Mail und Name nur für die Auszahlung (Art. 17 Abs. 3 lit. b/e DSGVO) bis `_tix_sp_retain_until` (31.12. des dritten Jahres) bzw. 90 Tage nach dem Abschluss; danach anonymisiert `TIX_Account_Delete::expire_payout_requests()` (täglicher Lauf der Kontolöschung) E-Mail, Name und Kundennachrichten (ggf. IBAN), Betrag und Geldbörsen-Bezug bleiben. Mit Richtlinie `block` bleibt es bei 409 `tix_wallet_open_balance`. |
| URL-Import: JavaScript-Seiten rendern | Seit 1.38.502 (`TIX_Event_Import`, `TIX_AI_Writer::import_url`, genutzt von `POST /register/analyze` mode=url und dem Bulk-Editor). Konfiguration nur per wp-config `TIX_RENDER_URL` + `TIX_RENDER_SECRET` (alternativ Optionen `tix_render_url`/`tix_render_secret`); fehlt eins, bleibt alles wie bisher. Der Schlüssel wird nie ausgegeben. **Wann:** ohne brauchbares JSON-LD und sichtbarer Text < 300 Zeichen, oder SPA-Hülle (leeres `#root/#app/#__next/#__nuxt`, Vite-Bundle `/assets/index-*.js`, Base44) mit < 1500 Zeichen, oder der normale Abruf scheitert (403/429/503, Netz). **Aufruf:** `POST TIX_RENDER_URL` mit `X-Render-Key`, Body `{url, subpages: 3, max_text: 60000}`, Zeitlimit 30 s. Aus der Antwort entsteht ein schlankes HTML (Titel, Meta/OG, `meta.jsonld`, `embeds` als iframe/script, Links, Bilder) für die bekannte Auswertung (JSON-LD, OpenGraph, Eventbrite/Pretix); die KI bekommt Haupt- und Unterseiten-Text (mit „Seite: …“) bis 12.000 Zeichen, `[Bild: …]` als Bildbeschreibung (z. B. Line-up). Bild: og:image, sonst größtes Bild ohne Logo. **Rückfall:** Dienst-Fehler (401/429/5xx) bzw. leerer Text → Auswertung wie bisher; reicht der Inhalt nicht, wie bisher 422 `url_unreadable` (mit `read_mode: partial` in den Fehlerdaten). **Antwort** `POST /register/analyze` zusätzlich `read_mode` (`direct` \| `rendered` \| `partial`; bei describe/flyer `null`), `notice` (bei partial: „Die Seite konnte nur teilweise gelesen werden – bitte prüfe die Angaben.“, sonst `null`), `pages_read` (gelesene Seiten inkl. Unterseiten). Limits wie bisher (Analyse-Limits und KI-Budget). |
| KI-Modelle aktuell | Seit 1.38.503 (`TIX_AI_Writer::MODELS`): Vorgabe `claude-sonnet-5-5` (Claude Sonnet 5.5), Auswahl `claude-opus-5-5` (Premium), `claude-haiku-5-5` (Budget) sowie wie bisher OpenAI `gpt-4o`, `gpt-4o-mini`, `o3-mini`. Content-Guard und Support-KI nutzen `claude-haiku-5-5`. Gespeicherte Werte außerhalb der Liste (z. B. `claude-sonnet-4-20250514`) gelten als Vorgabe (`TIX_AI_Writer::model_for()`), beim Speichern der Einstellungen wird darauf korrigiert. Lehnt die API ein Modell ab (400/404 mit „model“), wiederholt `TIX_AI_Writer::anthropic_post()` den Aufruf einmal mit der Vorgabe und setzt den Admin-Hinweis (Option `tix_ai_model_notice`, verschwindet beim Speichern der KI-Einstellungen). Gilt für KI-Texte/Import, Content-Guard, Support-KI und Meta-Ads. |
| URL-Import: Original statt Zuschnitt | Seit 1.38.504 (`TIX_Event_Import::uncrop_url()` / `original_image()`, im URL-Import direkt und gerendert): Zuschnitt-URLs von Bilddiensten werden auf das Original zurückgeführt – Base44/Wix (ab `/v1/fill|crop|fit/…` abschneiden), Cloudinary (Transformationen hinter `/upload/` entfernen, Version und Datei bleiben), imgix (Parameter `fit`, `crop`, `rect`, `ar`, `w`, `h` weglassen), Next.js (`/_next/image?url=` → dekodierte, absolute URL; auch verschachtelt). Das Original wird nur genommen, wenn es erreichbar ist (HEAD, sonst GET bis 64 KB, `image/*`, nur öffentliche Adressen); sonst bleibt die bisherige URL. Beispiel xxlschlagerparty.de: Flyer 1131×1600 statt Zuschnitt 1366×900. |
| KI-Funktionen im Studio | Seit 1.38.505 (`TIX_AI_REST`, Kerne aus `TIX_AI_Writer`, `TIX_Meta_Ads`, `TIX_Support_AI`, `TIX_Content_Guard` – dieselbe Logik wie im WP-Admin). Rechte A–D: `check_organizer`, mit `event_id` nur zugängliche Events (evendis: eigener Veranstalter). Limit 20/min je Nutzer (429 `tix_rate_limit`), ohne Schlüssel 503 `ai_unavailable`, Eingaben 400 `tix_invalid_field` (`field`). Jede KI-Antwort trägt `model`; gespeichert wird nichts. **A** `POST /ai/text {event_id?, field: excerpt|description|lineup|specials|extra_info, title?, context?}` → `{content, field, model}` (excerpt = Meta-Beschreibung 140–160 Zeichen aus Eventdaten; Info-Felder HTML strong/em/br, ohne eigenen `context` aus Datum/Ort/übrigen Info-Feldern des Events). **B** `POST /ai/extract {mode: image|text, image (Multipart-Datei oder Base64/Data-URL, ≤ 15 MB, JPG/PNG/WebP/GIF) | text (10–4000)}` → `{mode, fields (wie /events/import-url, mit event_category), image {id, url}|null (Flyer als vorläufiger Anhang, unbenutzt räumt ihn der Tageslauf der Registrierung auf), missing[], model}`; große Bilder werden nur für die KI verkleinert (≤ 4,5 MB JPEG). **C** `GET /ai/duplicates?title&date_start&location&exclude` → `{duplicates [{id, title, date_start (Y-m-d), date (gleich), location, status, url (Event-Seite), reason}]}` (ähnlicher Titel > 85 % oder gleicher Ort am gleichen Tag; evendis nur eigene Events, Admin alle; höchstens 10). **D** `POST /ai/social {event_id, platform: instagram|instagram_story (Alias story)|facebook|whatsapp|newsletter|twitter (Alias x)}` → `{platform, variants [{name, text, hashtags (Text), subject, cta}], model}`. **E** `GET /support/inbox` je Zeile zusätzlich `ai_summary` (Satz bzw. `null`); `POST /support/inbox/{id}/suggest` → `{draft, model}` (Rechte wie `/reply`, nur bei `can_reply`, sonst 403). **F** `guard {flagged, reason, checked_at (ISO|null), approved}` in `GET /events`, `/events/{id}` und `/events/{id}/edit` (`event.guard`); `POST /events/{id}/guard {approve: true}` (nur Admin) → `{event_id, guard, status}`: Markierung weg, aktueller Inhalt gilt als geprüft (spätere Änderungen werden neu geprüft). **G** `GET /studio/ai` (Admin; Einzel-Seite auch Inhaber) → `{model, models [{id, label, provider}], default, guard_enabled, provider_ready, can_edit}`; `POST /studio/ai {model?, guard_enabled?}` nur Admin; der API-Schlüssel ist weder lesbar noch setzbar (andere Felder 400). Außerdem `POST /events/import-url`: `read_mode`, `notice`, `pages_read` als eigene Felder statt in `fields`. `POST /events` und `POST /events/{id}` nehmen `image_id` an (Eventbild; eigenes Bild bzw. Flyer aus /ai/extract, Admin jedes Bild; `0`/`null` entfernt; fremdes Bild 403, kein Bild 400, Feld `image_id`); ein vorläufiger Flyer wird dabei fester Anhang des Events. |
| Datenschutz Support-KI | Seit 1.38.506 (`TIX_Support_AI`): **Awareness-Anfragen** (Kategorie `awareness`, Melden/Hilfe vor Ort; einmal erkannt dauerhaft über Meta `_tix_sp_ai_excluded`, auch nach Kategorie-Wechsel; Filter `tix_ai_excluded`) gehen nie an die KI: keine Zusammenfassung (`ai_summary` null, kein Hintergrundlauf, bestehende `_tix_sp_ai_summary` einmalig gelöscht), `POST /support/inbox/{id}/suggest` → 403 `tix_ai_excluded`, im WP-Admin Fehlermeldung. **Datenminimierung** bei allen anderen Anfragen: an die KI nur Vorname (keine E-Mail, kein Nachname), keine Namen der Ticket-Inhaber; in Betreff, Verlauf, Bestell- und Ticketangaben werden E-Mail-Adressen, Telefonnummern und IBAN geschwärzt (`[E-Mail]`, `[Telefon]`, `[IBAN]`; Datum, Preise, Bestellnummern bleiben). Übrige KI-Pfade (Inhaltsprüfung, Social-Texte, Texte, Flyer/Text/Link-Analyse) senden nur Event- bzw. Veranstalterangaben, keine Kundendaten. |
| URL-Import respektiert Sperren | Seit 1.38.507 (`TIX_Event_Import`; gilt für `POST /events/import-url`, `POST /register/analyze` mode=url, Studio-Assistent, Event-Editor und Bulk-Editor): ehrliche Kennung `<marke>-import/1.0 (+https://<seite>/)` (`TIX_Event_Import::user_agent()`, keine Tarnung als Browser, ein einziger Versuch). **robots.txt** wird beachtet (Gruppe der eigenen Kennung vor `*`, längste Regel gewinnt, Allow bei Gleichstand; 6 h je Host zwischengespeichert, fehlt sie = erlaubt), auch für Folgeabrufe von Ticket-Widgets. **Gesperrt** bei HTTP 401/403/429, Bot-Sperre (Cloudflare-Challenge/`cf-mitigated: challenge`, Captcha, „Access denied“ u. Ä., 503 mit Cloudflare/DDoS-Seite) oder Login-Wand (Passwortfeld auf Seite mit wenig Inhalt): kein Render-Dienst, kein zweiter Versuch; Fehler 422 `url_blocked`, `message` = `notice` = „Diese Seite erlaubt das Auslesen nicht – nimm einen Screenshot oder kopiere den Text.“, `data {read_mode: "blocked", notice, reason: http_401\|http_403\|http_429\|bot_check\|login\|robots, field: url}` (bei `/register/analyze` zusätzlich `alternatives`). Der Render-Dienst bleibt nur für reine JavaScript-Seiten ohne Sperre (HTTP 200, wenig Text bzw. SPA-Hülle). |
| Live-Demo Vertrieb: Rolle, Demo Club, Demo-Ticket | Seit 1.38.508 (`TIX_Sales`, `TIX_Demo`; Vertrag uebergaben/vertrag-live-demo.md; nur Mehr-Veranstalter-Modus, sonst 404). **Sandbox:** `TIX_Sales::is_sandbox_organizer($oid)` (Demo Club `_tix_org_demo`, Vorschau `_tix_org_preview` bis zur Übernahme; billig, ohne Netzwerk) + Filter `tix_sandbox_organizer`; Events über `_tix_organizer_id`, Bestellungen über Zahlart `demo` bzw. Sandbox-Event. Nie in: `/public/events` (auch days/raffle/collection/near), Kategorien, Städte, Collections, `/public/organizers`, Tipps, Upsell, offene Orte, Meta-Katalog, Bot-API, Sitemap, Website-Listen (`pre_get_posts`, Opt-out `tix_include_sandbox`), Wochenmail, Verteilung, Folgen-Mails, Tages-Alarme, Abrechnungen (Cron, Saldo, Abschlag, Positionen), Plattform-Abrechnungen/-Summen, Rechnungen (kein Nummernkreis), Plattform-Kunden, Plattform-Statistik; nie Admin-Mail „Neue Bestellung“. Detail bleibt: `/public/events/{id}` und `/public/organizers/{slug}` mit `demo: true`. Mails an `*.invalid` werden nie verschickt (`pre_wp_mail`). **Kennzeichen** `demo` (bool) in `/events`, `/events/{id}`, `/events/{id}/edit`, `/public/events/{id}`, `/public/organizers/{slug}`, `/organizer/profile`, `/studio/config`, `/me` (`organizer.demo`, dazu `sales`), `/checkin/*` (`demo: true` beim Demo Club). **Rolle Vertrieb** `tix_sales` (+ `tix_organizer`, `_tix_team_organizer_id` = Demo Club): Team-Admin von Demo Club; `/team` und `/register/organizer` 403, `/platform/*` 403. `POST /platform/sales {email, name}` (Admin) → 201 `{ok, user {id, name, email, role, previews {total, opened, claimed}, last_login}, invite_sent}`; `GET /platform/sales` → `{ok, users[]}`; `DELETE /platform/sales/{id}`. WP-Admin: Rolle „Vertrieb“ ordnet automatisch zu. **Demo Club** (Option `tix_demo_organizer_id`): Reset nachts 04:00 (Cron `tix_demo_reset`), `wp tixomat demo reset [--orders=<n>]`, `POST /platform/demo/reset {orders?}` (Admin) → `{ok, demo, organizer_id, wiped, seeded}`; löscht alles von Demo Club (auch Änderungen der Vertriebler) und legt neu an: 5 Events (heute „Live-Demo-Abend“ mit 0-€-„Demo-Ticket“, +7/+14/+30 Tage, ein vergangenes mit Abrechnung `DEMO-…`), Bestellungen (Vorgabe 120, Zahlart `demo`, @demo.invalid), Tickets teils eingecheckt, Gästelisten, 3 Gutscheine, 6 Kundenanfragen mit `ai_summary`; ohne Mails und ohne KI. `GET /public/demo` → `{ok, demo, organizer, events[] (Listenformat, demo), demo_ticket_event_id, reset_at}`. Sichtbare Namen seit 1.38.512 „Beispiel“: Veranstalter „Beispiel-Club“ (alter Name „Demo Club“ wird beim Reset umbenannt), Event „Live-Abend (Beispiel)“, Kategorie „Beispiel-Ticket“; Schlüssel (`demo`, `_tix_org_demo`, `/public/demo…`, Slug `demo-club`) unverändert. **Demo-Ticket** `POST /public/demo-ticket {name?, email?, event_id?}` → 201 `{ok, demo, ticket {id, code, qr: "GL-<event>-<code>", qr_image (absolute URL), name, category, price: 0, event {id, title, date_start, time_start, location}, checked_in}, view_url ("<Kundenseite>/demo-ticket/<code>/?key=<key>"), expires_at}`; ohne Konto/Zahlung, Mail nur mit `email`; nur Demo-Club-Events (sonst 404 `tix_demo_event`); Limit 5 je IP/10 min, angemeldete Vertriebler/Admins (Token, z. B. Studio serverseitig) 30 je Nutzer/10 min (1.38.511), dazu 300/Tag je IP. `reset_at`/`expires_at` = nächster Reset 04:00 Ortszeit; der Cron wird nach jedem Lauf (und bei Abweichung) wieder auf 04:00 gelegt, auch über die Zeitumstellung (1.38.511). `GET /public/demo-ticket/{code}?key=` → gleiches Format (Live-Stand `checked_in`). Check-in über `/checkin/scan` wie gewohnt. |
| Live-Demo Vertrieb: Vorschauen | Seit 1.38.510 (`TIX_Sales_Previews`; Vertrag uebergaben/vertrag-live-demo.md D–H; nur Mehr-Veranstalter-Modus, sonst 404). **Rechte** `/sales/*`: Rolle `tix_sales` (eigene Vorschauen) oder Admin (alle, `?owner=`); ohne Login 401, sonst 403 `rest_forbidden`, fremde Vorschau 403. **Anlegen** `POST /sales/previews` (JSON/Multipart) `{url | text | image, name?, customer_email?, note?}` → 201 `{ok, preview}`; Link: ein Abruf mit Sperren/robots.txt wie der Event-Import (gesperrt 422 `url_blocked` mit `read_mode: blocked` + `notice`, unlesbar 422 `url_unreadable`, ungültig 400 `url_invalid`), mehrere Events aus JSON-LD (auch ItemList/`event`/`subEvent`, kommende zuerst), sonst ein Event über die KI; Text/Flyer über die KI (503 `ai_unavailable`), Flyer wird Event-Bild; kein Event 422 `tix_preview_no_events`; höchstens 12 Events; Limit 30 Importe/h je Konto. Marke: Name `og:site_name` → JSON-LD → Seitentitel → Ort/Host; Logo `og:logo` → JSON-LD `logo` → `apple-touch-icon` → Logo-Bild; Akzent `theme-color` (nur kräftig) → Hauptfarbe von Logo/Flyer → `TIX_Studio::accent()` (evendis #FC2F55). **Lesen** `GET /sales/previews?status=active|expired|claimed&page=` → `{ok, previews[], total, page, per_page (20)}` (neueste zuerst), `GET /sales/previews/{id}` → `{ok, preview}`. **PREVIEW** `{id, token "p_"+32, status active|expired|claimed, name, logo|null, accent "#RRGGBB", source_url ('' bei Text/Flyer), created_at, expires_at, extended, opened_at|null, views, last_view_at|null, claimed_at|null, customer_email|null, note, events_count, events [{id, title, date_start, image}], links {hub (Übersichtsseite für den Kunden, `tix_preview_home_base`, Vorgabe https://vorschau.evendis.de/<token>/, 1.38.512), public (`tix_preview_base`, Vorgabe https://evendis.de/vorschau/<token>/), homepage (hub + `homepage/`, 1.38.512), app (`tix_preview_app_base`, Vorgabe <Studio>/app-vorschau/<token>/), claim (<Studio>/registrieren/?claim=c_…, null nach Übernahme), qr (absolute URL)}, owner {id, name}, read_mode direct|rendered|partial, notice|null}`. **Ändern** `PATCH /sales/previews/{id} {name?, accent? (#RGB/#RRGGBB, ''/null = Vorgabe), logo_id? (eigenes Bild bzw. Bild der Vorschau, 0 entfernt), customer_email?, note?, extend?: true}` – verlängern einmal +14 Tage (zweites Mal 409 `tix_preview_extended`), auch noch abgelaufen bis zum Löschen; `POST /sales/previews/{id}/logo` (Multipart `image` oder Base64) setzt ein eigenes Logo; `POST /sales/previews/{id}/events {url|text|image}` → 201 `{ok, preview, added []}` (ohne Doppelte, voll 409 `tix_preview_full`); `DELETE /sales/previews/{id}/events/{event_id}`; `DELETE /sales/previews/{id}` (sofort, Link danach 404). `POST /sales/previews/{id}/claim {email?}` → `{ok, claim_url, sent_to}` (Mail an den Kunden mit genau einem Link: hub, 1.38.512; Vorgabe `customer_email`, sonst 400; 5/h je Vorschau). Fehler: 404 `tix_preview_unknown`, 410 `tix_preview_expired`, 409 `tix_preview_claimed`. **Speicher:** Veranstalter als Entwurf ohne Autor mit `_tix_org_preview` (Sandbox), `_tix_preview_data`, `_tix_preview_token`, `_tix_preview_claim`, `_tix_preview_owner`, `_tix_org_accent`, Logo `_tix_org_landing_logo_id`, Freigabe-Status vorbelegt (keine Prüfung/Admin-Mail); Events als Entwürfe ohne Autor mit `_tix_preview` (keine Orte angelegt); Bilder mit `_tix_preview`. **Öffentlich** (noindex: `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`): `GET /public/previews/{token}` → `{ok, preview, noindex, status: "active", expires_at, organizer (Format /public/organizers/{slug} + accent, logo, preview), events [Listenformat + preview: true, tickets_enabled: false, url in der Vorschau], takeover_url, contact {name, email}}`; abgelaufen 410, unbekannt 404, übernommen 200 `{status: "taken_over", organizer: null, events: [], redirect: "/veranstalter/<slug>" (erst nach Freigabe) | null}`. `GET /public/previews/{token}/events/{id|slug}` → `{ok, preview, noindex, event {Detailformat, preview, sale {enabled: false, reason: "preview"}}}`. `POST /public/previews/{token}/seen {visit_id}` → `{ok, first, counted}` (30 min je visit_id, höchstens 60 je Token/Stunde, Vertriebler/Admin zählen nicht; GET zählt nie). **Test-Ticket** (1.38.512): `POST /public/previews/{token}/test-ticket {name?, event_id?}` → 201 `{ok, demo, preview, ticket (wie Demo-Ticket: id, code, qr "GL-<event>-<code>", qr_image, name, category "Test-Ticket", price 0, event, checked_in), view_url, expires_at (= Ablauf der Vorschau)}`; Vorgabe: nächstes laufendes/kommendes Event der Vorschau, sonst das erste; fremdes `event_id` 404 `tix_preview_event`; Zahlart `demo`, Adresse `*.invalid`, ohne Kategorie/Bestand; Limits wie Demo-Ticket (eigene Zähler `preview_ticket`). Live-Stand über `GET /public/demo-ticket/{code}?key=` (dann `preview: true`, `expires_at` der Vorschau). Einlass mit dem normalen Scanner (`/checkin/scan`) durch den Inhaber der Vorschau (Vertriebler) bzw. Admin, Antwort `demo: true, preview: true`; abgelaufen kein Einlass. Bei Übernahme, Ablauf, Löschen der Vorschau bzw. des Events werden Test-Tickets samt Bestellungen endgültig gelöscht; der nächtliche Demo-Reset lässt sie stehen. `GET /public/previews/claim/{claim_token}` → `{ok, organizer {name, logo, accent}, events_count, customer_email|null, expires_at}` (409/410/404). **Übernehmen:** `POST /register/organizer` mit `claim_token` (ohne Event, `organizer_name` optional = Vorschau-Name) übernimmt den Vorschau-Veranstalter: neuer Inhaber, veröffentlicht, Freigabe pending, Sandbox aus, Events bleiben Entwürfe und gehören dem Kunden; Antwort zusätzlich `claimed: true`, `claimed_events`, `organizer_id` = Vorschau; 409/410/404 wie oben. **Auswertung:** erstes Öffnen und Übernahme → Glocke (Typ `sales`, `action` `sales_preview:<id>`) + Mail an den Vertriebler (Link auf den hub mit `?intern=1` – Frontends zählen ihn nicht; Kunden-Links und `PREVIEW.links` ohne Zusatz, 1.38.511/512); `GET /sales/summary` → `{ok, previews {active, opened, claimed, expired}, last_30_days {created, opened, claimed}}` (gelöschte zählen über das Archiv `_tix_preview_archive` am Nutzer); `/platform/sales` liefert `previews {total, opened, claimed}`; Vertriebler gelöscht → Vorschauen + Archiv an den Admin. **Harte Sperre** (1.38.513): Vorschau-Veranstalter (`_tix_org_preview`) und ihre Events (`_tix_preview` bzw. Veranstalter ist eine Vorschau) kommen auf keinem Weg in `publish`/`future`/`pending`/`private`: `wp_insert_post_data` (Editor, Veröffentlichen-Knopf, Schnellbearbeiten, Bulk, Import, Kopie, REST) erzwingt `draft`, `transition_post_status` fängt `wp_publish_post` (Cron) und direkte Wechsel, Zuordnung `_tix_organizer_id`/Merkmal an einem schon öffentlichen Event setzt es zurück auf `draft`. `TIX_Org_Approval::is_public` immer false, `set_status` verweigert, `sale_error` → 403 `tix_preview_locked` (Web-Kasse, App-Kasse `/customer/orders`, POS `/pos/orders`). Studio-Routen `/events/{id}…` (lesen, `/edit`, speichern, Archiv, Löschen, Wiederherstellen, Kopie, Bilder, Berichte …) → 403 `tix_preview_locked` auch für Admins, `GET /events` listet sie nie; bearbeitet wird nur über `/sales/previews*`. Test-Tickets (ohne Kasse) und die öffentliche Vorschau-Ansicht laufen weiter. Nur die Übernahme (`claim_token`) hebt die Sperre auf (`TIX_Sales_Previews::begin_claim/end_claim`). Beispiel-Club: Events bleiben veröffentlicht (Detail, Demo-Ticket), aber nie im Katalog. **Ablauf:** Cron `tix_preview_expire` stündlich löscht abgelaufene, nicht übernommene Vorschauen samt Events und Bildern; Link danach 30 Tage 410 (Token-Hash in `tix_preview_gone`), dann 404. |
| Live-Demo Vertrieb: Vorschau-Pakete | Seit 1.38.514 (Vertrag uebergaben/vertrag-vorschau-pakete.md; ersetzt die Speicherung aus 1.38.510–513, Formate der Routen bleiben). **Speicher:** Tabelle `{prefix}tix_previews` (id, token, claim, owner, status, name, created_at, expires_at, opened_at, claimed_at, claimed_org, data JSON mit `organizer {name, logo_id, accent, …}`, `template`, `events [{key "e_…", vid, title, excerpt, slug, image_id, term_id, meta (alle `_tix_*`-Editor-Meta)}]`, Zähler); kein Veranstalter-/Event-Post für Inhalte; Bilder = Anhänge mit `_tix_preview` = Paket-ID. **Editor generisch:** Anlegen und `PATCH` laufen über `TIX_App_Events::apply_body` (dieselbe Funktion wie `POST /events/{id}`) auf einem kurzlebigen, gesperrten Arbeits-Entwurf (`_tix_preview_scratch`, sofort gelöscht, Cron räumt Reste nach 1 h); dessen `_tix_*`-Meta wird ins Paket übernommen. Neue Editor-Felder gelten damit automatisch beim Laden, Speichern und bei der Übernahme. **Virtuelle Posts:** öffentliche Antworten und `…/edit` entstehen aus Posts nur im Objekt-Cache (Veranstalter `900000000 + id·100`, Events `+ slot`) mit denselben Payload-Funktionen (`TIX_Public_Events::payload`, `TIX_Public_Platform::organizer_payload`, `TIX_App_Events::edit_response`). **Neu:** `GET /sales/previews/{id}/events/{key}/edit` → Format von `GET /events/{id}/edit` (+ `event.key`, `event.preview`); `PATCH /sales/previews/{id}/events/{key}` mit den Feldern von `POST /events/{id}`, Info-Abschnitte auch flach (`description`, `lineup`, `specials`, `extra_info`, `age_limit` …), `location` ohne `location_id` = Freitext, `image_id` (Paket-/eigenes Bild, 0 entfernt) → `{ok, preview, event, edit, ignored}` (ignoriert: `published`, `post_status`, `organizer_id`, `id`); `POST /sales/previews/{id}/events` mit `{fields}` (bzw. Feldern direkt) = Event von Hand → 201 `{…, added [{key, id, title}], event, edit}`; Import (`{url|text|image}`) → `added [{key, id, title}]`; `POST …/events/{key}/image` → `{…, image, image_id, event, edit}`; `DELETE …/events/{key}` (auch virtuelle id). PREVIEW zusätzlich `template` (`club`|`festival`|`klar`, Vorgabe club; `PATCH {template}`, `POST {template}`), `claimed_org`, `events[].key`; `id` = Paket-ID. Öffentlich zusätzlich `template` (oben und in `organizer.template`), `events[].key`, Detail per id, slug oder key; `?template=` wird nie gespeichert. **Test-Tickets:** an technischen Entwurf-Events (`_tix_preview_pkg`, `_tix_preview_key`) unter dem Sandbox-Veranstalter „Vorschau (technisch)“ (Option `tix_preview_tech_org`), lazy beim ersten Test-Ticket; `ticket.event.id` = technischer Event, `ticket.event.key` = Paket-Event. **Übernahme:** `POST /register/organizer` + `claim_token` legt einen **neuen** Veranstalter an (pending) mit Logo, Akzent, Vorlage (`_tix_org_site_template`) und echten Event-Entwürfen (alle Paket-Meta, Bild, Sparte); technische Events, Test-Tickets und übrige Paket-Bilder werden gelöscht, das Paket bleibt als `claimed`-Datensatz (`claimed_org`). **Migration** (einmalig, automatisch beim ersten Web-/Cron-Aufruf, Option `tix_preview_pkg_migrated`, Bericht in `tix_preview_pkg_migration`; `wp tixomat previews migrate [--dry-run]`, `wp tixomat previews report`): Vorschau-Veranstalter → Pakete (Token, Claim-Code, Zähler gleich, Bilder umgehängt), Test-Tickets und alte Posts endgültig gelöscht; übernommene → `claimed`-Datensätze. Harte Sperre (1.38.513) gilt weiter für technische Posts und Altbestand. **Waisen** (1.38.522, Fund evendis #1406): Der stündliche Ablauf-Cron entfernt auch Reste der alten Vorschau-Posts in jedem Status einschließlich Papierkorb – Events mit `_tix_preview` ohne Paket (`_tix_preview_pkg`) und ohne Arbeits-Entwurf, deren Veranstalter fehlt bzw. ein nicht übernommener Vorschau-Veranstalter ist (samt Test-Tickets, Bestellungen, Bildern), Vorschau-Veranstalter im Papierkorb und alte Vorschau-Bilder ohne Eltern-Post; echte, technische und übernommene Posts bleiben. `wp tixomat previews cleanup [--dry-run]`, `report` zeigt `orphans`. Die Migration wandelt Vorschauen im Papierkorb nicht mehr in Pakete um. |
| Marken-Website: Aufbau, Entwurf, Domain | Seit 1.38.515 (`TIX_Website`; Vertrag uebergaben/vertrag-website.md, Format tixomat-apps/docs/homepages-vorlagen-phase0.md Abschnitt 5; nur Einzel-Seiten, im Mehr-Veranstalter-Modus 404). **Studio:** `GET /studio/website` (Admin/Inhaber wie `perm_brand`) → `{ok, website (veröffentlicht\|null), draft\|null, published_at, updated_at, updated_by, preview {key, expires_at (24 h)}, catalog {templates, sections [{key, label, options, switch, available, defaults {club, festival, klar}, max?}]}, features, domain, history [≤10 {published_at, by}]}`; `POST /studio/website {website?, publish?, discard_draft?, domain? (nur Admin, sonst `ignored`), updated_at}` → wie GET + `ignored`; 409 `tix_website_conflict` mit `current`, 400 `tix_invalid_field` mit `field`. Veröffentlichen meldet live_host/preview_host an `TIX_Web_Revalidate` (`domains`). **WEBSITE** `{version 1, template club\|festival\|klar, sections [{key, enabled, Optionen}], nav {header ≤8, cta, footer ≤5×8}, texts {claim, seo_title, seo_description, newsletter_title, newsletter_text}}`; Abschnitte stage, ticker (title, eyebrow), tickets (title, eyebrow; Schalter Kasse, auf Einzel-Seiten immer an), arrival (title, eyebrow), more_dates (max ≤12, title, eyebrow), highlights (max), next (max, title), presale, service, lineup (max, title), about (eyebrow, title, html, facts ≤8), gallery (images ≤24, video https), faq (max), giftcards, wallet, loyalty, music, u16, awareness, raffles (max), tips (max), live_now, app_banner, newsletter, partners (title, logos ≤24), contact; unbekannte/doppelte keys 400. **Vorgaben je Vorlage** (1.38.516, erweitert 1.38.523, mit Homepages abgestimmt; alle übrigen aus; `available` nach App-Schaltern): club = stage, live_now, highlights, next, presale, service, tips, raffles, about, gallery, music, loyalty, giftcards, wallet, u16, awareness, faq, newsletter, app_banner · festival = stage, ticker, lineup, tickets, raffles, about, arrival, more_dates, tips, gallery, wallet, awareness, faq, newsletter, app_banner · klar = stage, next, tips, raffles, about, arrival, service, faq, contact, newsletter, app_banner. `gallery` ohne gepflegte Bilder = leer (die Website zeigt die Flyer der kommenden Events). **Öffentlich:** `/public/site-config` + `website` (veröffentlicht bzw. Vorgabe; vollständige Liste: eigene Reihenfolge, dann Vorgabe der Vorlage, dann übrige aus; je Abschnitt `available` nach App-Schalter (`TIX_Features::map`, `app_banner` = App vorhanden); Bilder `{id, large, medium_large, medium}`; `source` published\|default) und `domain {status, preview_host, live_host}`; `website.nav` ersetzt `nav.header`/`nav.footer`. `GET /public/website[?draft=<preview.key>]` → `{ok, website, draft, domain}` (Entwurf: no-store, noindex; falscher Schlüssel 403 `tix_website_preview`); `?template=` ändert nie etwas. **Domain** (Option `tix_website_domain`): nur Information, das Plugin schaltet nichts um. Speicher: Option `tix_website`. **Vorschau-Claim (evendis):** setzt `_tix_org_site_template` und – ohne eigenes Design – `page_design` (club → clubnacht, festival → ticketschalter, klar → galerie; User-Entscheid A). Seit 1.38.516: `domain.preview_host` ohne Pflege = `neu.<live_host>` (ohne „www.“). |
| Künstlerseiten (Paket 1) | Seit 1.38.517 (`TIX_Artists`; Vertrag uebergaben/vertrag-kuenstler.md; nur Mehr-Veranstalter-Modus, sonst 404). **Daten:** Beitragstyp `tix_artist` (nicht öffentlich im WP-Sinn), Meta `_tix_artist` {kind solo\|band\|comedian\|dj\|ensemble\|show, genres ≤5, origin, short_bio ≤300, bio (kses), images {cover, portrait: {id, credit}}, links {website, instagram, tiktok, youtube, spotify, apple_music} (https), ids {spotify, apple} (aus den Links bzw. ausdrücklich), tours [{slug, name, from, to, image, text}], status draft\|public\|blocked, verified, managed_by {type organizer\|evendis, id}, duplicate_of}, Suchschlüssel `_tix_artist_norm/_spotify/_apple`, Protokoll `_tix_artist_log` (200), Zusammengeführt `_tix_artist_merged_into`. Event-Meta `_tix_artists` [{artist_id, role headliner\|support\|guest, tour, by_organizer, at, notified}] (≤20) + Index `_tix_artist_ref` (eine Zeile je Künstler; Kopie/Claim bauen ihn neu). **Öffentlich** erst mit ≥1 veröffentlichtem Event eines freigegebenen Veranstalters (Sandbox nie). `GET /public/artists?q=&letter=&genre=&city=&sort=new&page=&per_page=` → `{ok, artists [{id, slug, name, kind, genres, image, verified, next {date, city}, upcoming_count}], total, page, per_page}`; `?sitemap=1` → `{ok, items [{slug, lastmod, tours}]}`. `GET /public/artists/{slug|id}[/tours/{tour}]?city=&month=&near=&radius=&tour=` → `{ok, artist, tour, events, past (20), filters {cities [{city, count}], months, tours [{slug, name, count}]}, days, upcoming_count, seo {title, description, canonical, jsonld [Person\|MusicGroup\|PerformingGroup mit event[] + BreadcrumbList]}}`; Termine im Listenformat + `artist_role`, `tour`, `state` (onsale\|presale\|few\|soldout_waitlist\|box_office\|free\|external\|cancelled), `presale_start`, `waitlist`; zusammengeführt bzw. alter Slug → `{ok, redirect}`; unbekannt/nicht öffentlich 404 `tix_artist`. `/public/events*` + `artists [{id, slug, name, role, tour, image}]` (nur öffentliche; in Vorschauen alle zugeordneten), Detail-`lineup_links[].artist_slug`. **Studio:** `GET /artists/search?q=&spotify=&apple=` (Treffer per ID zuerst, `match` id\|name), `POST /artists` (Inhaber/Team-Admin eines freigegebenen Veranstalters bzw. Admin; Sandbox/Vertrieb 403; Dublette 409 `tix_artist_duplicate` mit `duplicates`, `force: true` legt mit `duplicate_of` an + Admin-Mail), `GET /artists/mine`, `GET /artists/{id}` (+ `can_manage`; Verwalter mit `followers`, `duplicate_of`, `public`), `POST /artists/{id}` (nur Verwalter/Admin, sonst 403 `tix_artist_not_manager`; Bilder `cover_image`/`portrait_image` = eigenes Bild mit `rights_confirmed` + `credit`), `POST /artists/{id}/image {slot, rights_confirmed, credit}` + `image`, `GET /artists/{id}/events` (alle Termine aller Veranstalter, ohne Umsätze, `assigned_by`), `DELETE /artists/{id}/events/{event_id} {reason}` (Pflicht; Zuordnender wird informiert). Zuordnen über den Event-Editor: `POST /events/{id} {artists: [{artist_id, role, tour}]}` (ersetzt die Liste; öffentliche bzw. eigene Künstler, nicht gesperrt), `GET /events/{id}/edit` + `artists`, `artist_suggestions` (Line-up: Name bzw. Spotify/Apple). **Mitteilungen:** fremde Zuordnung → Glocke (Typ `artist`, `action artist:<id>`) + Mail an Inhaber/Team-Admins des Verwalters, bei verifizierten zusätzlich Admin-Mail; nur für veröffentlichte Events (sonst beim Veröffentlichen), nie für Sandbox/Vorschau. **Admin:** `GET /platform/artists[?duplicates=1]`, `POST /platform/artists/{id} {verified, status, managed_by}`, `POST /platform/artists/{id}/merge {into}` (Events, Links/IDs, Touren, Texte/Bilder wenn leer; alter Slug leitet weiter). Vorschau-Paket: Feld `artists` zieht mit (nur Anzeige). |
| Künstlern folgen (Paket 5) | Seit 1.38.518 (`TIX_Artist_Follow`; Vertrag uebergaben/vertrag-kuenstler.md §4; nur Mehr-Veranstalter-Modus, auf Einzel-Seiten keine Routen). **Konto/App** (`check_customer`, auch Gast-Token): `GET /customer/following/artists` → `{ok, artist_ids, artists [ARTIST_CARD + {cities, since, mail}], mail_enabled, consent_text}`; `POST /customer/following/artists {artist_id, cities? (≤10, null = alle), mail?: bool, consent?: true}` (Mails im Konto ohne Bestätigungs-Mail, nur mit Schalter, sonst 404 `tix_follow_mail_off`; 400 `tix_consent_required`); `DELETE /customer/following/artists/{id}` (auch die Konto-Mails). Speicher User-Meta `_tix_app_following_artists` {artist_id: {cities, since}}. **E-Mail ohne Konto** (Double-Opt-in wie Veranstalter, Tabelle `tix_artist_mail_followers`, nur wenn `tix_mail_follow` an ist – den schaltet nur der User): `POST /public/artists/{id}/follow {email, consent: true, cities?}` → immer `{ok, message}` (10/h je IP), `GET …/follow/confirm?token=` → 302 `/kuenstler/<slug>/?follow=bestaetigt|ungueltig`, `GET …/unfollow?token=` → 302 Abmeldeseite `/kuenstler/<slug>/abmelden/?token=`, `POST …/unfollow {token}` (auch RFC 8058) → löscht; 404 `tix_token_invalid`. Einwilligung mit Text + Zeitpunkt + IP als HMAC; unbestätigte nach 7 Tagen gelöscht; Konto gelöscht → Einträge weg. **Auslöser:** Zuordnung an veröffentlichtem Event bzw. Veröffentlichen (Aktion `tix_artist_event_added`) → „Neuer Termin“, bei weiterem Termin derselben Tour „Zusatztermin“; Vorverkaufsstart (stündlich eingesammelt); nie Sandbox/Vorschau. **Zustellung:** Cron `tix_artist_follow_cron` stündlich, ab 18:00 einmal am Tag: je Konto eine Glocken-Mitteilung (Typ `artist`, `action artist:<slug>`, transaktional, Push) und je Mail-Adresse eine gebündelte Mail über alle gefolgten Künstler (je Künstler „Alle Termine“ und „Nicht mehr folgen“, List-Unsubscribe One-Click), `cities` filtert. Follower-Zahl (Konten + bestätigte Mail ohne Konto) in `/artists/{id}` für den Verwalter; Zusammenführen nimmt Follower mit. **Universal Links:** AASA-Baustein `kuenstler` (`/kuenstler/*`), im Mehr-Veranstalter-Modus automatisch zusammen mit `veranstalter` (Option `tix_app_link_paths`). |
| Gewinnspiel: Datenschutz (IP, Löschfrist) | Seit 1.38.519 (`TIX_Raffle`, alle Seiten; Fund Rechtstexte-Chat R27). **IP:** Spalte `ip` in `tix_raffle_entries` enthält nur eine Prüfsumme (SHA-256 über IP + Salt `wp_salt('auth')`, 40 Zeichen, `TIX_Raffle::ip_hash`), nie die IP; vorhandene Einträge einmalig umgewandelt (Option `tix_raffle_ip_hashed`, gleiche Formel, auch IPv6). Rate-Limit (5/Minute) wie bisher über einen kurzlebigen Zähler. **Löschfrist** (Option `tix_raffle_retention_days`, Vorgabe **90 Tage**, Cron `tix_raffle_cleanup` täglich): Teilnahmen **ohne Gewinn** 90 Tage nach der Auslosung (nicht ausgelost: nach Teilnahmeschluss, ohne Schluss: nach Event-Ende); **Gewinner** 90 Tage nach dem späteren von Auslosung und Event-Ende (Freikarte bzw. Versand erledigt), dann werden ihre Einträge gelöscht und in `_tix_raffle_winners` Name auf den Vornamen gekürzt und E-Mail entfernt (`anonymized: true`, Preis bleibt für die Anzeige); Event gelöscht → Teilnahmen sofort weg. Freikarten-Tickets selbst folgen den Regeln für Tickets. |
| Rechnungen: E-Rechnung + Buchhaltung (Phase 4) | Seit 1.38.500: **E-Rechnung** – Rechnungen (R) und Korrekturen (RK) als XML nach EN 16931 im CII-Format (Profil ZUGFeRD/Factur-X „EN 16931“, Guideline `urn:cen.eu:en16931:2017`; R = TypeCode 380, RK = 381 mit positiven Beträgen und Bezug BT-25; Kleinunternehmer Kategorie E mit Befreiungsgrund; bereits bezahlt: TotalPrepaid = Gesamt). `GET /invoices/{id}/xml` (Rechte wie PDF) → `{ok, filename, mime, profile, data}`; Belege ohne USt (B/BK) 404 `tix_invoice_no_xml`. Das PDF ist jetzt PDF/A-3b (`TIX_PDFA`: Schrift Manrope eingebettet, sRGB-OutputIntent, XMP) und enthält bei R/RK die `factur-x.xml` als Associated File (`/AFRelationship /Alternative`, fx-XMP) = ZUGFeRD-Hybrid; € als Zeichen. **Übergabe an die Buchhaltung** (`TIX_Invoice_Accounting`) – je Aussteller `POST /studio/invoice-config {accounting: {provider: none\|lexoffice, key?, use_site_key?, category_id?}}` (Schlüssel wird bei Lexoffice geprüft, AES-256-GCM verschlüsselt gespeichert, nie ausgegeben; `use_site_key` nur Einzel-Seite = Schlüssel des bisherigen Lexoffice-Anbieters; Veranstalter tragen ihren eigenen ein); GET liefert `accounting {provider, key_set, key_last4, use_site_key, site_key_available, category_id, since}`. Jede neue R/RK geht im Hintergrund als Lexoffice-Verkaufsbeleg (`salesinvoice` bzw. `salescreditnote`, Beträge je Steuersatz, Sammelkunde) mit dem ZUGFeRD-PDF an Lexoffice; Wiederholung stündlich bis 5 Versuche, bereits angelegter Beleg bekommt nur das PDF nach; B/BK werden nicht übergeben (`skipped`). Status je Beleg in `tix_invoices.export_*` (DB-Version 2) und in den Listen als `export {provider, status pending\|done\|error\|skipped, ref, error, at}`; `POST /invoices/{id}/export` → `{ok, export}` (jetzt erneut übergeben; ohne Buchhaltung 409 `tix_accounting_off`, B/BK 409 `tix_accounting_skip`, Fehler 502 mit Lexoffice-Meldung). Zusammenfassungen (`invoices[]`) zusätzlich `xml`. |
| Rechnungen: „USt-IdNr. folgt“ + Neuausstellung | Seit 1.38.525 (`TIX_Invoices`, DB-Version 3; Vertrag uebergaben/vertrag-rechnungen-nummer-folgt.md). **Schalter je Aussteller** `tax_id_follows` (`vat_id` \| `tax_number` \| leer) in der Aussteller-Konfiguration (`tix_invoice_config[site\|platform]` bzw. `_tix_invoice_config` am Veranstalter): fehlen Steuernummer und USt-IdNr., gilt der Aussteller trotzdem als vollständig (kein `missing: tax_id`); wartende Bestellungen holt das Speichern seit 1.38.526 nicht nach (Klick „Rechnung jetzt erstellen“). Der Beleg zeigt in der Fußzeile „USt-IdNr.: folgt“ bzw. „Steuernummer: folgt“ und trägt `tix_invoices.tax_id_missing` = 1 (R und RK). Ohne Nummer kein E-Rechnungs-XML (EN 16931 BR-S-02): PDF ohne eingebettete `factur-x.xml`, `GET /invoices/{id}/xml` 409 `tix_invoice_no_xml_tax_id`, `xml` in Listen null. Ist eine Nummer eingetragen, wirkt der Schalter nicht mehr (`tax_id_follows_active` false), neue Belege tragen die Nummer. **Neuausstellung:** `POST /invoices/{id}/reissue {send?}` (Rechte wie Senden) → `{ok, replaced, invoice, storno, sent, tax_id_missing}`: neue Rechnung R mit aktuellen Angaben über den offenen Betrag (`replaces_id`, `reason` reissue, PDF „Ersetzt Rechnung …“; teilweise erstattet: je Steuersatz eine Zeile „Leistungen lt. Rechnung … (nach Erstattung)“), danach Storno RK der alten (`reason` reissue, Hinweis „Storno wegen Neuausstellung als Rechnung … Es wird nichts erstattet“, auch im XML); spätere Erstattungen treffen die neue Rechnung. Fehler 409: `tix_tax_id_missing` (noch keine Nummer), `tix_invoice_reissue_not_needed`, `tix_invoice_reissued`, `tix_invoice_reissue_cancelled` (komplett storniert/erstattet), `tix_invoice_reissue_kind` (keine Rechnung R). `POST /studio/invoices/reissue {send?, limit? 1–100 (50), dry_run?}` (eigener Aussteller, Admin `?issuer=`/`?organizer=`) → `{ok, dry_run, reissued [{replaced, number, storno, sent}], failed [{number, code, message}], would_reissue? [], tax_id_missing}`. **Studio:** `GET /studio/invoice-config` zusätzlich `tax_id_follows` (gewählt oder null), `tax_id_follows_active`, `tax_id_info` ((i)-Text, § 14 UStG), `tax_id_missing {open, open_gross, cancelled, can_reissue, message}` (z. B. „3 Rechnungen ohne Steuernummer – jetzt korrigieren“), `issuer.tax_id_follows`; POST nimmt `tax_id_follows` (`true` = USt-IdNr., `""`/`false`/`null` = aus; ungültig 400 `field`; Plattform nur Admin). `GET /organizer/invoices` + `tax_id_missing_open`, Filter `?tax_id_missing=1`; Beleg-Zeilen + `tax_id_missing`, `replaces {id, number}`, `replaced_by {id, number}`. WP-Verwaltung „Rechnungen“: Auswahl „Ohne Nummer“, Hinweis mit Knopf „Jetzt neu ausstellen“ (optional Versand an die Kunden). Versand nur mit `send`. |
| Rechnungen: nachträglich nur per Klick + Nummernkreis einstellbar | Seit 1.38.526 (Vertrag uebergaben/vertrag-rechnungen-nummer-folgt.md §6–7). **Automatik nur bei Zahlung:** Eine Rechnung entsteht automatisch nur bei einem echten Statuswechsel nach „completed“, während `tix_invoices_enabled` an ist, und wenn die Rechnungsdaten vollständig sind (bzw. „Nummer folgt“). Kein Nachholen mehr: kein stündlicher Cron (`tix_invoices_retry` und `tix_invoices_retry_one` werden einmalig entfernt), kein Nachholen beim Speichern der Firmen-, Auszahlungs- oder Rechnungsdaten, kein erneutes Auslösen an schon bezahlten Bestellungen. Bestellungen vor dem Einschalten und die Warteschlange `tix_invoice_pending` bekommen ihre Rechnung nur per Klick: `POST /orders/{id}/invoices/issue` (auch für alte Bestellungen) → `{ok, created[], pending[], message, invoices}`; Abendkasse 409 `tix_invoice_pos`, Demo/Vorschau 409 `tix_invoice_sandbox`. Bestell-Detail `GET /orders/{id}/detail` + `invoice_can_issue`. Einschalt-Zeitpunkt `tix_invoices_enabled_at` (beim Setzen der Option, auch per WP-CLI; ältere Seiten: früheste wartende Bestellung bzw. jetzt). **Hinweis** `without_invoice {count, waiting, since_enabled, before_enabled, enabled_at, order_ids[≤20], message „n Bestellungen ohne Rechnung“}` in `GET /studio/invoice-config` und `GET /organizer/invoices` (bezahlt, > 0 €, ohne R/B, ohne Abendkasse und Sandbox; evendis je Veranstalter über die Events). WP-Verwaltung: Hinweis statt „Jetzt nachholen“, Knopf „Rechnung jetzt erstellen“ in der Bestellung. **Nummernkreis** je Aussteller und Serie (R, RK, B, BK) in `numbering` der Aussteller-Konfiguration: `prefix` (bis 20 Zeichen A–Z 0–9 - _ / .; im Mehr-Veranstalter-Modus Pflicht; ohne Jahr nicht auf Ziffer endend), `year` (mit Jahr: `<Präfix><Jahr>-<Nr>`, Zähler je Kalenderjahr; ohne: durchlaufend, Zähler-Jahr 0), `digits` 3–10, `next`. Vorgabe = bisheriger Aufbau `<Serie>-<Kürzel>-<Jahr>-<00001>`. `GET /studio/invoice-config` → `numbering {R\|RK\|B\|BK: {prefix, year, digits, custom, next, min_next, preview}}`, `next` = Vorschau. `POST {numbering: {R: {prefix?, year?, digits?, next?} \| null (Vorgabe)}}`: `next` < kleinste erlaubte (über Zähler und vergebene Nummern mit dem Präfix) → 409 `tix_number_too_low` (`min_next`); Präfix, das Nummern eines anderen Kreises erzeugen könnte (gleich oder nur um Ziffern/Bindestriche verlängert, auch andere Aussteller und vorhandene Belege) → 409 `tix_number_prefix_taken`; sonst 400 mit `field` `numbering.<Serie>.<feld>`. Zähler laufen nur vorwärts, Wechsel gilt für künftige Belege, Korrekturen bleiben in ihrer Serie. DB-Version 4: Spalte `counter_year`, Eindeutigkeit `(issuer_key, series, counter_year, seq)`; vor dem Anlegen wird die Nummer auf Eindeutigkeit geprüft. WP-Verwaltung: Felder für R und RK mit Vorschau. |
| E-Rechnung für Gebührenrechnungen (EVR, KG) | Seit 1.38.501: Gebührenrechnungen an Veranstalter (`EVR-…`, Vermittlung) und Monatsrechnungen bzw. Gutschriften der Kanal-Gebühr (`KG-…`) sind PDF/A-3 mit eingebetteter `factur-x.xml` (EN 16931, CII; Verkäufer = Firmendaten der Plattform, Käufer = Rechnungsdaten aus dem Schnappschuss inkl. USt-IdNr.; EVR „mit Auszahlung verrechnet“ = bezahlt; KG mit Fälligkeit, Storno-Zeilen mit Menge −1, Gutschrift (Betrag < 0) als 381 mit positiven Beträgen). `GET /organizer/settlements/{id}/pdf?doc=invoice&format=xml` → `{filename, mime: application/xml, data}`. Gemeinsamer Kern `TIX_EInvoice::render($doc)`; Bausteine `TIX_Settlement_PDF::invoice_doc()`/`channel_doc()`. Die Abrechnung selbst (EV-…) bleibt ohne E-Rechnung. |
| Bestellung: Event | Seit 1.38.394: `GET /customer/orders/{id}` (Konto oder `?key=`, auch vermittelte Bestellungen) enthält zusätzlich `order.event` = `{id, slug, title, date_start, url}` oder `null` – **auch bei unbezahlten Bestellungen** (Web-Kasse: Rücksprung zur Event-Seite ohne Slug im `return_url`). Beispiel: `"event":{"id":6,"slug":"sommerfest-2026","title":"Sommerfest","date_start":"2026-10-21","url":"https://evendis.de/events/sommerfest-2026/"}` | beide | 1.38.394 |
| WooCommerce-Reste (nativ abgesichert) | Seit 1.38.393 ohne WooCommerce keine fatalen Fehler mehr: Warteliste „wieder verfügbar“ (siehe §50), `admin-ajax tix_meta_store_event_id` (nur noch für WooCommerce-Bestellungen, sonst `{"success":true}`), `tix_add_special` (Specials nur mit WooCommerce-Warenkorb, sonst Fehlermeldung), Web-Kasse `[tix_pos]` (ohne WooCommerce Hinweis „Bitte die Kasse in der App verwenden“, AJAX `tix_pos_*` → `{"success":false,"data":{"message":…}}`; PIN-Feld im Profil bleibt; die App-Kasse `POST /pos/orders` ist nativ), Gruppenbuchung (WooCommerce-Preis nur mit WooCommerce). | alle | 1.38.393 |
| Sparten- und Städte-Texte | Seit 1.38.392 (`TIX_SEO_Texts`), nur hinzufügend: `GET /public/categories` je Sparte zusätzlich `intro` (HTML, `''` ohne Text) und `image` (`{url, thumb, alt}` oder `null`) – gepflegt unter Events → Kategorien (Term-Meta `_tix_intro`, `_tix_image_id`, Recht `manage_categories`). `GET /public/cities` je Stadt zusätzlich `slug` (Köln → `koeln`, Düsseldorf → `duesseldorf`: ä/ö/ü/ß → ae/oe/ue/ss, dann `sanitize_title`), `intro`, `image` – gepflegt unter Tixomat → Städte-Texte (Option `tix_city_texts` `{slug: {name, intro, image_id}}`, nur Admins; die Seite listet Städte kommender Events, weitere frei ergänzbar; in der API erscheinen weiterhin nur Städte mit kommenden Events). HTML per `wp_kses_post`. Beispiel: `{"id":12,"slug":"comedy","name":"Comedy","count":8,"upcoming":5,"intro":"<p>Die besten Comedy-Abende …</p>","image":{"url":"https://…/comedy-1024x683.jpg","thumb":"https://…/comedy-300x200.jpg","alt":"Bühne"}}` bzw. `{"name":"Köln","upcoming":14,"slug":"koeln","intro":"","image":null}` | beide | 1.38.392 |
| Veranstalter-Bereich (wp-admin) | Seit 1.38.389 (Schalter `organizer_dashboard_enabled`): **Dashboard** = `admin.php?page=tixomat` (`TIX_Dashboard`), für Veranstalter auf eigene Events gefiltert – Umsatz/Tickets/Diagramm/letzte Bestellungen nur aus Positionen der eigenen Events (gemischte Bestellungen zählen nur anteilig, wie die App-Statistik), kein Support-Widget, Schnellzugriff Bestellungen/Gästeliste/Auszahlungen bzw. Abrechnung; `tix-organizer-dashboard` leitet dorthin (vorher Weiterleitungsschleife). Die Veranstalter-Erkennung (`TIX_Organizer_Dashboard::get_organizer_by_user`) wird jetzt auch in wp-admin geladen (vorher leer → Dashboard, CRM, Event-Filter ohne Daten). **Bestellungen, E-Mail an Käufer, Abrechnung (CSV), Exporte, Benachrichtigung bei neuer Bestellung** lesen die native Kasse (`tix_orders`/`tix_order_items`) statt WooCommerce; Abrechnung mit MwSt aus den Steuer-Einstellungen; Benachrichtigung an `_tix_org_email` (sonst Inhaber), je Event und Veranstalter einmal (Option `_tix_org_notified_<id>`); E-Mail an Käufer mit `Reply-To` des Veranstalters. CSV-Exporte über admin-post sind für Veranstalter freigegeben (vorher Umleitung), Team-Rechte (`view_guestlist`, `manage_billing`) werden auch in den Handlern geprüft. Ticketliste filtert über `_tix_ticket_event_id` (vorher falscher Schlüssel). **Mehr-Veranstalter-Modus:** fremde Veranstalter-Profile, Locations und Medien nur lesen, nicht bearbeiten/löschen. **Login-Ziele:** Admin → wp-admin, Veranstalter (auch Team-Admin) → Dashboard, Einlass/Mitarbeiter → Seite mit `[tix_checkin]`, DJ → Seite mit `[tix_musikwunsch_dj]`, sonst Profil (vorher „Keine Berechtigung“). Rechte der Rolle `tix_organizer` werden bei `init` ergänzt (vorher 403 beim ersten Admin-Aufruf). **Look:** im Mehr-Veranstalter-Modus `assets/css/admin-evendis.css` auf allen Tixomat-Seiten (Manrope, helle Seitenleiste, Karten Radius 20, Akzent fest evendis-Rot `#FC2F55` – seit 1.38.391 nicht mehr `color_primary`). | evendis, alle mit Veranstalter-Dashboard | 1.38.389 |
| Veranstalter werden (Look) | Seit 1.38.388: `[tix_register_event]` im Mehr-Veranstalter-Modus (evendis) im evendis-Look (`assets/css/register-evendis.css`, Klasse `tix-re-evendis`): Manrope lokal, Akzent `#FC2F55`, Hauptknöpfe Nachtblau, Lucide-Icons (`TIX_Register_Event::icon()`) im getönten Kreis statt Emojis, auch in den Fortschrittsschritten (`tixRegister.icons`). Filter `tix_register_event_evendis_look`. Andere Seiten unverändert. | evendis | 1.38.388 |
| Kunden-Anmeldung | Seit 1.38.386 (`TIX_Customer_Auth`): Über die Durchleitung (evendis.de) braucht die Kunden-Anmeldung **kein `wp-login.php`** mehr (der Proxy sperrt POST `/wp-login.php` und `/xmlrpc.php`). Formulare posten an `admin-post.php?action=tix_auth&tix_do=login\|logout\|lostpassword\|resetpass\|register\|postpass` (läuft über die Durchleitung). `login_url` → Konto-Seite (`[tix_account]`, `?redirect_to=`), `lostpassword_url` → `?tix_auth=lost`, `register_url` → `?tix_auth=register`, `logout_url` → Handler mit Nonce; Formular-Ziele auf wp-login.php (`wp_login_form`, Seitenpasswort) → Handler. Aufrufe von `wp-login.php` über die Durchleitung: GET → passende Ansicht der Konto-Seite (`?action=rp&key=&login=` → `?tix_auth=rp&tix_rp_key=&tix_rp_login=`), POST → Handler. Ansichten der Konto-Seite: Passwort vergessen, neues Passwort (mind. 8 Zeichen), Registrieren (nur mit „Jeder kann sich registrieren“), Hinweise `?tix_auth_err=` / `?tix_auth_msg=` (feste Texte). Reset- und „Passwort festlegen“-Links in Mails an Kunden zeigen bei gesetzter Kunden-Adresse auf `<Kunden-Adresse>/<Konto-Seite>/?tix_auth=rp&tix_rp_key=…&tix_rp_login=…` (Team-Konten behalten wp-login.php). Passwort vergessen antwortet immer gleich (kein Ausforschen), höchstens 5 je IP und 3 je Konto pro Stunde; Registrieren 5 je IP und Stunde. Über die Durchleitung außerdem: keine Kommentare/Pingbacks (`wp-comments-post.php`, `xmlrpc.php`), kein `X-Pingback`, kein RSD-Link. Direkte Aufrufe der Verwaltung und Seiten ohne Kunden-Adresse unverändert. **Werte (seit 1.38.386):** Ansichten der Konto-Seite `?tix_auth=lost` (Passwort vergessen), `?tix_auth=rp&tix_rp_key=&tix_rp_login=` (neues Passwort, Ziel der Reset-Mail), `?tix_auth=register` (nur mit „Jeder kann sich registrieren“); Hinweise `?tix_auth_err=empty\|invalid\|too_many\|invalidkey\|mismatch\|short\|email\|exists\|closed\|nonce\|failed` und `?tix_auth_msg=lost_sent\|reset_ok\|registered\|loggedout`; Handler `admin-post.php?action=tix_auth&tix_do=login\|logout\|lostpassword\|resetpass\|register\|postpass` (kein `tix_auth=logout`; Abmelden = `tix_do=logout&_wpnonce=…`). **Seit 1.38.387** Site-Option **`tix_auth_redirects`** `{"lost":"https://evendis.de/…","register":"…","rp":"…"}`: diese Ansichten (ohne Hinweis-Parameter) leiten auf die eingestellte Seite um, `tix_rp_key`/`tix_rp_login`/`redirect_to` werden mitgegeben; „Passwort vergessen“/„Registrieren“-Links zeigen direkt dorthin; nur Hosts der Seite bzw. Kunden-Adresse. **„Benutzer wechseln“** (Admin) landet seit 1.38.387 auf `<Konto-Seite>/?tix_switched=1` der WordPress-Adresse – der `tix_`-Parameter hält es bei WordPress, auch wenn `/mein-konto/` sonst zu Next gehört. | evendis | 1.38.386 |
| Kunden-Adresse | Site-Option **`tix_customer_base_url`** (z. B. `https://evendis.de`; leer = keine Änderung, Vorgabe auf allen Seiten). Zieht WordPress auf eine Verwaltungs-Adresse um (evendis: `veranstalter.evendis.de`), zeigen kundenseitige Links auf die Kunden-Adresse (`TIX_Customer_URL::to()` / `in_text()`): Event-`url` (Liste, Detail, by-slug, `series[]`, `upsell[]`, Bestellliste, Bot-API), Veranstalter-Seiten-`url`, `invoice_url`, `wallet.apple_url/google_url`, Kalender-Link (ICS/Google; UID bleibt), Google-Wallet-`origins` (+ Kunden-Host), alle Links in Mails an Kunden (Bestellbestätigung/-status, Erinnerung, Nachbefragung, Gästeliste, Warenkorb, Tickets erneut senden, Code-Login, Willkommen, Magic Link, Weitergabe, Warteliste, Gewinnspiel, Newsletter, Support-Antworten inkl. Universal Link `?tix_sp_ticket=`, Konto-Aktivierung, Tischreservierung, Sponsor-Tickets, CRM, Bankdaten). **Nie umgeschrieben:** `/wp-json/…`, `?rest_route=`, `/wp-admin/…` außer `admin-post.php`/`admin-ajax.php`, `/wp-login.php`, `/wp-cron.php`, fremde Hosts, Webhook-/Notify-Adressen, `/info.url`, Mails an Admins/Veranstalter/Promoter/Sponsoren – (bis 1.38.383 blieben außerdem Links mit `tix_`-Parametern auf der WordPress-Adresse; **seit 1.38.384 gehen auch sie auf die Kunden-Adresse** – feste Regel „Kunden landen nie auf der Verwaltung“; die Kundenseite leitet Anfragen mit `tix_`-Parametern per Durchleitung an WordPress). **Durchleitung (seit 1.38.384, `TIX_Customer_URL::boot_proxied()`):** Kommt eine Anfrage von einem vertrauten Proxy (`tix_trusted_proxies`) mit `X-Forwarded-Host` = Kunden-Host, gibt sich WordPress für diese Anfrage vollständig als Kunden-Adresse aus: `HTTP_HOST`/`HTTPS`, `home_url`, `site_url` (auch wp-login.php), `content_url`, `plugins_url`, `includes_url`, `rest_url`, admin-ajax/admin-post, `wp_redirect`, erlaubte Weiterleitungsziele, und ein Ausgabefilter ersetzt fest gespeicherte Adressen (Breakdance, Menüs, Inhalte, auch JSON-maskiert). Nur wp-admin (ohne admin-ajax/admin-post) bleibt auf der Verwaltung. Damit erzeugen über die Kundenseite ausgelieferte WP-Seiten (Kasse, Konto, Support, Danke-Seite, Ticket-Seiten) keine Links, Canonicals, Formular-Ziele oder Assets auf der Verwaltung; die Kundenseite muss dafür `/wp-content/`, `/wp-includes/`, `/wp-json/`, `/wp-login.php`, admin-ajax/admin-post und alle Anfragen mit `tix_`-Parametern durchleiten. Die Kunden-Adresse ist automatisch erlaubtes Rücksprung-Ziel (`return_url`, nur https) und Weiterleitungsziel. Ändern der Option leert den Event-Cache. Setzen: `wp option update tix_customer_base_url https://evendis.de`. Seit 1.38.377 ist der Host der Kunden-Adresse außerdem die **Partner-Kennung der Plattform** (`TIX_Partners::platform_id()`, Kopfzeile `X-Tix-Partner-Id` an die Quellen; die Quellen erwarten den Host ihrer `syndication_api_url`, z. B. `evendis.de`) und zählt für die Freischaltung der Veranstalter-Landingpages (`TIX_Organizer_Landing::is_feature_enabled`, Host `evendis.de`). Proxy auf der Kunden-Adresse: `/wp-json/*`, `/wp-admin/admin-post.php`, `/wp-admin/admin-ajax.php`, `/wp-content/*`, AASA an WordPress; Seitenpfade (`/kasse/`, `/mein-konto/`, `/support/` …) per 301 an die Verwaltung, solange Next sie nicht hat. | evendis | 1.38.375 |
| Besucher-IP | `TIX_Client_IP::get()` für alle Rate-Limits, IP-Sperre, Statistik, Gewinnspiel, Promoter-/Sponsor-Login, App-Log, Meta CAPI, seit 1.38.385 auch Tischreservierung, Warteliste, Landingpage-Statistik, Versand-Log „Tickets erneut senden“ und Kassen-Sitzung: `X-Forwarded-For` (von rechts, erste Adresse, die kein eigener Proxy ist), `X-Real-IP`, `CF-Connecting-IP` zählen **nur**, wenn `REMOTE_ADDR` ein vertrauenswürdiger Proxy ist – Option `tix_trusted_proxies` (IPs/CIDR, Array oder Text), Vorgabe `127.0.0.1`, `::1` und die eigene Server-IP (`SERVER_ADDR`), Filter `tix_trusted_proxies`. Sonst `REMOTE_ADDR` (vorher wurde den Kopfzeilen ungeprüft geglaubt). Kein Cloudflare/CDN vor den Seiten (Stand 2026-10-10). | beide | 1.38.374 |

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

Bild: `_tix_loc_image_id` (vorhandenes Feld), sonst Beitragsbild. **Geocoding:** Beim Speichern einer Location ohne Koordinaten, aber mit Straße fragt das Plugin einmal Nominatim (`https://nominatim.openstreetmap.org/search?format=json&limit=1&q=<Straße, PLZ Ort, Land>`, User-Agent `Tixomat/<Version> (<home_url>)`, Timeout 5 s); Fehler werden ignoriert und der Suchtext gemerkt (kein erneuter Versuch beim nächsten Speichern). Automatisch ermittelte Koordinaten werden nach einer Adressänderung neu bestimmt, von Hand eingetragene nie überschrieben. Seit 1.38.373 merkt `TIX_Venues::geocode` jeden Suchtext (Treffer 30 Tage, Fehlschlag 1 Tag, Transient `tix_geo_<md5>`): gleiche Adressen – z. B. viele Events am selben Ort – fragen Nominatim nur einmal; `--sleep` der CLI-Befehle greift nur zwischen echten Anfragen. Seit 1.38.370 auch direkt nach dem Anlegen einer Location mit Adresse über das Inline-Formular im Event-Editor und die Registrierung `[tix_register_event]` (dort stand die Adresse beim Speichern-Hook noch nicht fest). Nachtragen: `wp tixomat geocode-locations [--dry-run] [--sleep=1]` (nur Locations ohne Koordinaten, 1 Anfrage pro Sekunde, versucht auch frühere Fehlschläge erneut).

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

- **Gebühr** (`TIX_Fees`): Modi `organizer`, `split` (Kunde trägt `fee_split_customer_share` %, Vorgabe 50, kaufmännisch auf Cent; Rundung des Endpreises wie im Kunden-Modus), `customer`. Im Mehr-Veranstalter-Modus gilt die zentrale Höhe (Einstellungen → Gebühren: `fee_percent`, `fee_fixed`; evendis: 2,5 % + 0,59 € je Ticket, brutto) für alle; je Veranstalter zählt `_tix_fee_mode` (wenn `_tix_fee_override=1`). **Eigene Höhe je Veranstalter** (seit 1.38.396): nur Plattform-Admins im Veranstalter-Formular, Schalter `_tix_fee_custom=1` + `_tix_fee_percent`/`_tix_fee_fixed` (Änderung in `_tix_fee_custom_changed`); Haken weg → wieder zentrale Höhe; sonstige eigene Beträge ohne den Schalter werden weiter ignoriert. **Zahlung inklusive** (seit 1.38.396, Einstellung `gateway_fee_included`, Vorgabe 0): die Plattformgebühr deckt die Zahlungskosten ab – keine Zahlungsgebühr für Kunde oder Veranstalter, die Abrechnung zieht die tatsächliche Zahlungsgebühr nicht ab (`gateway_fee_source: included`); die Plattform trägt sie. Wird je Bestellung in `_tix_order_fees_{id}` (`gateway_fee_included`) festgehalten, ältere Bestellungen bleiben unverändert. Wahl durch den Veranstalter (App `POST /organizer/fee-mode`, Web „Auszahlungen“) oder Admin (Veranstalter bearbeiten). `calc_order_fees` liefert zusätzlich `platform_fee_customer`, `platform_fee_organizer`, `fee_customer_share`, `gateway_fee_included`; `_tix_order_fees_{id}` speichert sie (native Kasse seit 1.38.396 inkl. `rounding_surplus`; vorher fehlte die Aufteilung – für ältere „geteilt“-Bestellungen rechnet die Abrechnung den Kundenanteil aus `customer_fee_line` abzüglich Kunden-Zahlungsgebühr zurück). Modal-/Express-Checkout bekommen `share` in `data-fee-config`.
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
- **WP-CLI:** `wp tixomat app-look install` (anlegen/aktualisieren, neue bleiben aus; im Builder geänderte Vorlagen werden übersprungen und gemeldet – erkannt per md5 gegen ausgelieferte Stände bzw. Post-Meta `_tix_app_look_md5`, überschreiben mit `--force`) · `on` (App-Look an, bisherige Vorlagen gleichen Typs aus; IDs in Option `tix_app_look_replaced`) · `off` (zurück) · `status`.
- **Einzelwerte für Breakdance-Texte** über das dynamische Feld „Shortcode“: `[tix_app key="…"]` mit `title` (ohne Emojis), `greeting`, `question`, `weekday`/`day`/`month`, `date_long`, `countdown`, `live`, `time_range`, `doors`, `place`, `address`, `maps_url`, `route_car`/`route_transit`/`route_walk`, `age`, `pill_status`/`pill_price`/`pill_free`/`pill_door`/`pill_presale`, `tickets`, `bar_price`/`bar_buy`/`bar_free`/`bar_state`, `organizer`, `related`, `lineup`, `categories`, `live_now`, `organizers`, `app_page`. Leer = Bedingung „ist nicht leer“ blendet den Baustein aus.
- **Bausteine (Shortcode-Element):** `[tix_app_search]` (Suche + Heute/Morgen/Wochenende), `[tix_app_categories]`, `[tix_app_cards limit]` (EventCardLarge), `[tix_app_list limit organizer exclude category filter="1" style="compact" empty]` (EventRow bzw. Veranstalter-Zeile), `[tix_app_related]` („Das könnte dir auch gefallen“), `[tix_app_lineup]` (Initialen-Kreise, Spotify-Suche), `[tix_app_heart]`, `[tix_app_live]` („Jetzt & gleich“: läuft gerade oder beginnt in ≤ 3 Std., laufende zuerst), `[tix_app_organizers]` („Veranstalter entdecken“: bis 10 Veranstalter mit öffentlicher Seite, die mit Events zuerst; Banner, Logo, „Stadt · n Events“, Folgen-Pille), `[tix_app_follow]` (AJAX `tix_app_follow`, User-Meta `_tix_app_following` wie die App; Gäste → Seite mit `[tix_account]` und Rücksprung `tix_back`).
- Assets (`assets/css/app-look.css`, `assets/js/app-look.js`, Manrope aus `assets/fonts/manrope/`) laden nur, wenn ein Baustein auf der Seite steht. JS: Filter der Liste, Apple Karten auf Apple-Geräten, „Mehr lesen“ (`.evs-clamp`), „Einladen“ (Link `#teilen` → Teilen-Dialog bzw. Link kopieren).
- Seit 1.38.372 zusätzlich „Jetzt & gleich“, „Veranstalter entdecken“ und die Vorlage „Seite (App-Look)“ (Kasse, Konto/Tickets, Support); nach dem Update einmal `wp tixomat app-look install` ausführen.
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
