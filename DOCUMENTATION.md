# Tixomat -- Event & Ticket Management

**Version:** 1.33.1
**Autor:** MDJ Veranstaltungs UG (haftungsbeschraenkt)
**Text Domain:** `tixomat`
**Abhaengigkeiten:** WordPress 6.x, WooCommerce 8.x (HPOS-kompatibel)
**Page Builder:** Breakdance (alle Meta-Felder als Dynamic Data verfuegbar)

---

## Konstanten

| Konstante | Wert | Beschreibung |
|---|---|---|
| `TIXOMAT_VERSION` | `'1.33.1'` | Aktuelle Plugin-Version |
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

Tixomat besteht aus 40 Klassen:

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
├── assets/
│   ├── css/                              CSS-Dateien (event-page, ticket-selector, feedback, timetable, ...)
│   ├── js/                               JS-Dateien (event-page, feedback, timetable, ...)
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

### Nachrichten-Format

Jede Nachricht im `_tix_sp_messages`-Array hat folgende Struktur:

| Feld | Typ | Beschreibung |
|---|---|---|
| `id` | `string` | Eindeutige Message-ID (msg_...) |
| `type` | `string` | `customer`, `admin` oder `note` |
| `author` | `string` | Anzeigename des Autors |
| `content` | `string` | Nachrichtentext |
| `date` | `string` | ISO-8601-Timestamp |

- `customer`: Sichtbar fuer Kunde + Admin, loest Admin-Benachrichtigung aus.
- `admin`: Sichtbar fuer Kunde + Admin, loest Kunden-Benachrichtigung aus.
- `note`: Nur im Admin sichtbar (interne Notiz).

### Admin-Dashboard (3 Tabs)

**Tab 1: Anfragen** – Filterbarer Liste aller Support-Tickets mit Status, Kategorie und Freitext-Suche. Klick oeffnet Inline-Detail mit Nachrichten-Thread, Antwort-Box und Quick Actions.

**Tab 2: Kunden-Suche** – Universelle Suche mit Auto-Erkennung:
- E-Mail → Alle Bestellungen, Tickets und Support-Anfragen des Kunden
- `#12345` → Bestellungs-Details mit zugehoerigen Tickets
- 12-stelliger Code → Ticket-Details mit zugehoeriger Bestellung

**Tab 3: Statistiken** – KPI-Cards (Offen, In Bearbeitung, Heute geloest, Ø Antwortzeit) und 7-Tage-Trend-Chart.

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
| Neue Anfrage erstellt | Kunde | „Deine Anfrage wurde empfangen – #{ID}" |
| Admin antwortet | Kunde | „Neue Antwort zu deiner Anfrage #{ID}" |
| Kunde antwortet | Admin | „Neue Kunden-Antwort: #{ID} – {Betreff}" |
| Status → Geloest | Kunde | „Deine Anfrage #{ID} wurde geloest" |

Alle E-Mails nutzen `TIX_Emails::build_generic_email_html()` fuer einheitliches Branding.

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

### Dateien

| Datei | Zweck |
|---|---|
| `includes/class-tix-support.php` | PHP: CPT, Admin-Dashboard, AJAX, Shortcode, E-Mails |
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
| `ai_guard_api_key` | `string` | Anthropic API Key (`sk-ant-...`) |

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

## Changelog

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

*Tixomat v1.28.24 -- MDJ Veranstaltungs UG (haftungsbeschraenkt)*
