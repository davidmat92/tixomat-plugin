<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Mail_Gallery – Vorschau aller Mail-Arten im Layout der Seite (1.38.471).
 *
 * WP-Admin „Tixomat → E-Mails“: Einstellungen der Mail-Marke (Kopf hell/Markenfarbe, Logo für farbigen Kopf, Name,
 * Support-Postfach, Absender, Feedback), Galerie mit Vorschau und „Test an mich senden“.
 * REST (Rechte wie Studio-Marke):
 *   GET /studio/mails                 → {ok, brand, mails: [{key, label, group, subject}]}
 *   GET /studio/mails/preview/{key}   → {ok, key, subject, html, text}   (?format=html liefert die Seite direkt)
 * Beispieldaten: Bestell-Mails laufen durch den echten Baukasten (TIX_Emails::build_email_html mit Beispiel-Bestellung),
 * WordPress-Kernmails durch die echten Filter; das nächste veröffentlichte Event der Seite dient als Event-Karte.
 * Es wird nichts gespeichert und nichts an Kunden gesendet.
 */
class TIX_Mail_Gallery {

    const NS = 'tixomat/v1';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 29);
        add_action('admin_post_tix_mail_brand', [__CLASS__, 'save']);
        add_action('admin_post_tix_mail_test', [__CLASS__, 'send_test']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $perm = [__CLASS__, 'perm'];
        register_rest_route(self::NS, '/studio/mails', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/studio/mails/preview/(?P<key>[a-z0-9_-]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_preview'], 'permission_callback' => $perm]);
    }

    public static function perm(WP_REST_Request $req) {
        if (class_exists('TIX_Studio') && method_exists('TIX_Studio', 'perm_brand')) return TIX_Studio::perm_brand($req);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
    }

    // ═══════════════ Beispieldaten ═══════════════

    /** Nächstes veröffentlichtes Event (nur lesen) für Event-Karten. */
    private static function sample_event() {
        static $id = null;
        if ($id !== null) return $id;
        $q = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=']], 'no_found_rows' => true]);
        if (!$q) $q = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true]);
        return $id = $q ? intval($q[0]) : 0;
    }

    private static function event_title() {
        $e = self::sample_event();
        return $e ? html_entity_decode(get_the_title($e), ENT_QUOTES, 'UTF-8') : 'Sommernacht Open Air';
    }

    private static function event_block() {
        $e = self::sample_event();
        $b = $e && class_exists('TIX_Emails') ? TIX_Emails::event_block($e) : null;
        return $b ?: ['type' => 'event', 'title' => 'Sommernacht Open Air', 'date' => date_i18n('D, j. F Y', strtotime('+10 days')), 'time' => 'Einlass 19:00 · Beginn 20:00 Uhr', 'location' => 'Stadtpark', 'image' => ''];
    }

    private static function qr($t) {
        return class_exists('TIX_QR') ? TIX_QR::url($t, 240) : '';
    }

    /** Beispiel-Bestellung (nur im Speicher). */
    private static function sample_order($status = 'completed', $method = 'paypal') {
        return new TIX_Mail_Gallery_Order(self::sample_event(), $status, $method);
    }

    private static function brand() {
        return TIX_Mail_Layout::brand();
    }

    /** Katalog: key => [label, group, builder() → [subject, html]]. */
    public static function catalog() {
        $b = self::brand();
        $name = $b['name'];
        $ev = self::event_title();
        $site = $b['site_url'];
        $L = 'TIX_Mail_Layout';
        $order = function ($type, $status = 'completed', $method = 'paypal', $extra = '') {
            $o = self::sample_order($status, $method);
            TIX_Emails::$preview_tickets = [
                ['id' => 1, 'code' => 'QUB28J5EMHMS', 'type_name' => 'Vorverkauf', 'event_name' => self::event_title(), 'event_id' => self::sample_event() ?: 1, 'owner_name' => 'Lena Muster', 'download_url' => home_url('/tickets/')],
                ['id' => 2, 'code' => 'JM8KL74W5ETC', 'type_name' => 'Vorverkauf', 'event_name' => self::event_title(), 'event_id' => self::sample_event() ?: 1, 'owner_name' => 'Lena Muster', 'download_url' => home_url('/tickets/')],
            ];
            try { return TIX_Emails::build_email_html($type, $o, '', $extra); } finally { TIX_Emails::$preview_tickets = null; }
        };
        $cat = [
            // ── Kunde ──
            'bestellung' => ['Bestellbestätigung mit Tickets', 'Kunde', function () use ($order, $name) {
                return [$name . ' – Deine Tickets sind bereit!', $order('order_complete')]; }],
            'vorkasse' => ['Bestellung wird geprüft (Vorkasse)', 'Kunde', function () use ($order, $name) {
                return [$name . ' – Bestellung wird geprüft #1042', $order('on_hold', 'on-hold', 'bacs')]; }],
            'storno' => ['Bestellung storniert', 'Kunde', function () use ($order, $name) {
                return [$name . ' – Bestellung storniert #1042', $order('cancelled', 'cancelled')]; }],
            'erstattung' => ['Erstattung bestätigt', 'Kunde', function () use ($order, $name) {
                return [$name . ' – Deine Erstattung wurde bestätigt #1042', $order('refunded', 'refunded')]; }],
            'erinnerung' => ['Erinnerung am Vortag', 'Kunde', function () use ($order, $ev) {
                $extra = [['type' => 'text', 'html' => 'Hallo Lena,<br>morgen ist es so weit: <strong>' . esc_html($ev) . '</strong>. Hier sind alle Infos und deine Tickets.']];
                return ['Erinnerung: ' . $ev . ' ist morgen!', $order('reminder', 'completed', 'paypal', $extra)]; }],
            'nachbefragung' => ['Danke für deinen Besuch', 'Kunde', function () use ($order, $ev, $site) {
                $extra = [['type' => 'text', 'html' => 'Hallo Lena,<br>wir hoffen, du hattest eine tolle Zeit bei <strong>' . esc_html($ev) . '</strong>! Vielen Dank für deinen Besuch – wir freuen uns, dich beim nächsten Event wiederzusehen.'],
                          ['type' => 'button', 'label' => 'Feedback geben', 'url' => $site . '/']];
                return ['Danke für deinen Besuch bei ' . $ev . '!', $order('followup', 'completed', 'paypal', $extra)]; }],
            'gutschein' => ['Geschenkgutschein', 'Kunde', function () use ($L, $name) {
                return [$name . ' – Dein Geschenkgutschein', $L::render(['title' => 'Dein Geschenkgutschein', 'subtitle' => 'Bestellung #1042', 'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo Max,<br>dein Geschenkgutschein ist fertig.'],
                    ['type' => 'text', 'html' => '<strong>Gutschein verschenken</strong><br>Das PDF zum Ausdrucken oder Weiterschicken hängt an dieser E-Mail.'],
                    ['type' => 'ticket', 'label' => 'Geschenkgutschein · 50,00 €', 'title' => 'für Erika', 'sub' => '„Alles Liebe zum Geburtstag!“ – von Max', 'code' => 'GS-K7M2-PQ9X', 'qr' => self::qr('GS-K7M2-PQ9X')],
                    ['type' => 'info', 'rows' => [['Wert', '50,00 €'], ['Gültig bis', date_i18n('d.m.Y', strtotime('+3 years'))]]],
                ]])]; }],
            'gaesteliste' => ['Gästeliste mit QR-Code', 'Kunde', function () use ($L, $ev) {
                return ['Gästeliste: ' . $ev, $L::render(['title' => 'Du bist auf der Gästeliste', 'subtitle' => $ev, 'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo Lena Muster,<br>du stehst auf der Gästeliste! Zeig diesen QR-Code am Einlass.'], self::event_block(),
                    ['type' => 'ticket', 'label' => 'Gästeliste', 'title' => 'Lena Muster', 'sub' => '+1 Begleitung', 'code' => 'GL-1-G7K2', 'qr' => self::qr('GL-1-G7K2')],
                    ['type' => 'button', 'label' => 'QR-Code öffnen', 'url' => home_url('/')]]])]; }],
            'umschreiben' => ['Ticket auf dich umgeschrieben', 'Kunde', function () use ($L, $name, $ev) {
                return ['Ein Ticket wurde auf dich umgeschrieben - ' . $name, $L::render(['title' => 'Tickets umgeschrieben', 'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo Max,<br>ein Ticket wurde auf dich umgeschrieben. Hier sind die Details:'],
                    ['type' => 'ticket', 'label' => 'Ticket', 'title' => 'Vorverkauf', 'sub' => $ev, 'code' => 'QUB28J5EMHMS', 'qr' => self::qr('GL-1-QUB28J5EMHMS')],
                    ['type' => 'button', 'label' => 'Meine Tickets anzeigen', 'url' => home_url('/tickets/')]],
                    'note' => 'Du bekommst diese E-Mail, weil Tickets bei ' . $name . ' auf dich umgeschrieben wurden.'])]; }],
            'warteliste' => ['Warteliste: Tickets verfügbar', 'Kunde', function () use ($L, $name, $ev) {
                return ['Tickets für ' . $ev . ' wieder verfügbar!', $L::render(['title' => 'Tickets wieder verfügbar!', 'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo,<br>Gute Nachrichten! Für <strong>' . esc_html($ev) . '</strong> sind wieder Tickets verfügbar.'], self::event_block(),
                    ['type' => 'button', 'label' => 'Jetzt Tickets sichern', 'url' => home_url('/')]],
                    'note' => 'Du bekommst diese E-Mail, weil du dich bei ' . $name . ' für ' . $ev . ' auf die Warteliste gesetzt hast.'])]; }],
            'warenkorb' => ['Warenkorb-Erinnerung', 'Kunde', function () use ($L, $ev) {
                return ['Du hast noch Tickets für ' . $ev . ' im Warenkorb', $L::render(['title' => 'Du hast noch Tickets im Warenkorb', 'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo,<br>du hast noch Tickets in deinem Warenkorb für <strong>' . esc_html($ev) . '</strong>.'], self::event_block(),
                    ['type' => 'table', 'rows' => [['Vorverkauf', '2', '']]],
                    ['type' => 'button', 'label' => 'Warenkorb wiederherstellen', 'url' => home_url('/')],
                    ['type' => 'text', 'html' => '<span class="tm-muted" style="font-size:13px;color:#6B7280;">Dieser Link ist 48 Stunden gültig.</span>']],
                    'note' => 'Automatisch erstellte Erinnerung an deinen Warenkorb.'])]; }],
            'tischreservierung' => ['Tischreservierung bestätigt', 'Kunde', function () use ($L, $ev) {
                return ['Reservierungsbestätigung – ' . $ev, $L::render(['title' => 'Reservierung bestätigt', 'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo Lena Muster,<br>deine Tischreservierung wurde erfolgreich bestätigt.'], self::event_block(),
                    ['type' => 'info', 'title' => 'Reservierung', 'rows' => [['Tisch', 'Lounge 4'], ['Gäste', '6 Personen'], ['Zahlung', 'Anzahlung bezahlt: 50,00 € – Restzahlung vor Ort: 250,00 €'], ['Reservierungsnummer', '#87']]]]])]; }],
            'magic_link' => ['Tickets ohne Konto (Link)', 'Kunde', function () use ($name) {
                $body = '<p>Hallo,</p><p>du hast angefragt, deine Tickets ohne Konto abzurufen. Klicke auf den folgenden Button, um alle deine Tickets anzusehen und herunterzuladen:</p>'
                    . '<p style="text-align:center;margin:28px 0;"><a href="' . esc_url(home_url('/tickets/')) . '" style="display:inline-block;padding:14px 28px;background:#0f172a;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:15px;">Meine Tickets ansehen</a></p>'
                    . '<p style="color:#64748b;font-size:13px;">Der Link ist aus Sicherheitsgründen <strong>30 Minuten gültig</strong>. Du kannst ihn beliebig oft innerhalb dieser Zeit verwenden.</p>';
                return ['Deine Tickets ansehen – ' . $name, TIX_Emails::build_generic_email_html('Deine Tickets', $body, 'Zugang ohne Konto')]; }],
            'support' => ['Support: neue Antwort', 'Kunde', function () use ($name) {
                $body = '<p>Hallo Lena,</p><p>es gibt eine neue Antwort auf deine Anfrage:</p><div style="background:#f8fafc;border-left:3px solid #3b82f6;padding:12px 16px;margin:16px 0;">Danke für deine Nachricht! Wir haben dein Ticket neu ausgestellt – du findest es in deinem Konto.</div>'
                    . '<p style="text-align:center;"><a href="' . esc_url(home_url('/')) . '" style="display:inline-block;padding:12px 24px;background:#3b82f6;color:#fff;border-radius:8px;text-decoration:none;">Anfrage ansehen</a></p><p style="color:#94a3b8;font-size:12px;">Du kannst direkt auf diese E-Mail antworten.</p>';
                return ['Neue Antwort auf deine Anfrage [#512]', TIX_Emails::build_generic_email_html('Neue Antwort', $body, 'Anfrage #512')]; }],
            // ── Konto ──
            'app_code' => ['App: Anmeldecode', 'Konto', function () use ($L, $name) {
                return ['Dein Anmeldecode – ' . $name, $L::render(['title' => 'Dein Anmeldecode', 'subtitle' => $name . ' App', 'kind' => 'account', 'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo,<br>mit diesem Code meldest du dich ohne Passwort in der App an:'],
                    ['type' => 'code', 'value' => '482913', 'hint' => 'Gültig für 15 Minuten – gib ihn in der App ein, du musst dafür keinen Link öffnen.'],
                    ['type' => 'notice', 'tone' => 'info', 'html' => 'Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail ignorieren.']]])]; }],
            'aktivieren' => ['Konto aktivieren (nach dem Kauf)', 'Konto', function () use ($name) {
                $body = '<p>Hallo Lena,</p><p>vielen Dank für deine Bestellung! Wir haben ein Konto für dich angelegt, damit du jederzeit auf deine Tickets zugreifen kannst.</p><p><strong>Klicke auf den Button, um dein Passwort zu vergeben und dein Konto zu aktivieren:</strong></p>'
                    . '<p style="text-align:center;margin:32px 0;"><a href="' . esc_url(home_url('/')) . '" style="display:inline-block;padding:14px 32px;background:#0f172a;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:15px;">Konto aktivieren</a></p>'
                    . '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Wenn du kein Konto erstellen wolltest, kannst du diese E-Mail ignorieren.</p>';
                return ['Willkommen bei ' . $name . ' — Passwort vergeben', TIX_Emails::build_generic_email_html('Konto aktivieren', $body, 'Nur noch ein Schritt', '', 'account')]; }],
            'passwort' => ['Passwort zurücksetzen', 'Konto', function () use ($name) {
                return ['[' . $name . '] Passwort zurücksetzen', TIX_Emails::wrap_password_reset('', 'beispiel', 'lena', null)]; }],
            'passwort_geaendert' => ['Passwort geändert', 'Konto', function () use ($name) {
                $e = TIX_Emails::wrap_password_change_email(['message' => '', 'headers' => ''], ['user_login' => 'lena'], []);
                return ['[' . $name . '] Passwort geändert', $e['message']]; }],
            'willkommen' => ['Willkommen (neues Konto)', 'Konto', function () use ($name) {
                $u = (object) ['user_login' => 'lena', 'user_email' => 'lena@example.com'];
                $e = TIX_Emails::wrap_new_user_email(['message' => 'Passwort festlegen: ' . home_url('/wp-login.php?action=rp&key=beispiel'), 'headers' => ''], $u, $name);
                return ['[' . $name . '] Anmeldedaten', $e['message']]; }],
            // ── Werbung / Folgen ──
            'folgen' => ['Neues Event eines Veranstalters (Folgen)', 'Werbung', function () use ($L, $ev) {
                return ['Neues Event von Club Beispiel', $L::render(['title' => 'Neues Event von Club Beispiel', 'kind' => 'promo', 'blocks' => [self::event_block(),
                    ['type' => 'text', 'html' => '<a href="' . esc_url(home_url('/')) . '" style="font-weight:700;text-decoration:none;">Zum Event →</a>'],
                    ['type' => 'button', 'label' => 'Alle Events von Club Beispiel', 'url' => home_url('/')]],
                    'note' => 'Du folgst Club Beispiel per E-Mail. <a href="' . esc_url(home_url('/')) . '" style="text-decoration:underline;">Nicht mehr folgen</a>'])]; }],
            'rundmail' => ['Rundmail / Newsletter', 'Werbung', function () use ($name) {
                $html = class_exists('TIX_Broadcast') ? TIX_Broadcast::wrap_html('Neuigkeiten', '<p>Hallo Lena,</p><p>unsere neue Saison startet – sichere dir jetzt die ersten Tickets!</p>', home_url('/')) : '';
                return ['Neuigkeiten von ' . $name, $html]; }],
            // ── Veranstalter / Team ──
            'veranstalter_bestellung' => ['Veranstalter: neue Bestellung', 'Veranstalter', function () use ($ev) {
                $body = nl2br('<b>Lena Muster</b> <small>(lena@example.com)</small>' . "\n\n" . '<b>2× Ticket</b> · ' . esc_html($ev) . "\nBestellung #1042 · abgeschlossen · PayPal")
                    . '<p style="margin-top:24px;"><a href="' . esc_url(admin_url()) . '" style="display:inline-block;padding:10px 20px;background:#0f172a;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Bestellung öffnen →</a></p>';
                return ['💰 Neue Bestellung – 25,80 €', TIX_Emails::build_generic_email_html('💰 Neue Bestellung – 25,80 €', $body, '', 'Benachrichtigung für deinen Veranstalter-Bereich.', 'admin')]; }],
            'fast_ausverkauft' => ['Veranstalter: fast ausverkauft', 'Veranstalter', function () use ($L, $ev) {
                return ['Fast ausverkauft: ' . $ev . ' (noch 18 Tickets)', $L::render(['title' => 'Fast ausverkauft', 'subtitle' => $ev, 'kind' => 'admin', 'blocks' => [
                    ['type' => 'notice', 'tone' => 'warn', 'html' => '<strong>' . esc_html($ev) . '</strong> ist zu <strong>91 %</strong> ausverkauft.'],
                    ['type' => 'info', 'rows' => [['Noch verfügbar', '18 von 200 Tickets'], ['Verkauft', '182']]]], 'note' => 'Benachrichtigung für deinen Veranstalter-Bereich.'])]; }],
            'team' => ['Einladung ins Team', 'Veranstalter', function () use ($name) {
                $body = '<p>Hallo,</p><p><strong>Lena Muster</strong> hat dich ins Team von <strong>' . esc_html($name) . '</strong> eingeladen (Rolle: Einlass).</p>'
                    . '<p style="text-align:center;margin:24px 0;"><a href="' . esc_url(home_url('/')) . '" style="display:inline-block;padding:14px 28px;background:#131020;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;">Einladung annehmen</a></p><p style="color:#64748b;font-size:13px;">Die Einladung ist 7 Tage gültig.</p>';
                return ['Einladung ins Team von ' . $name, TIX_Emails::build_generic_email_html('Einladung ins Team', $body, $name, '', 'account')]; }],
            'abrechnung' => ['Veranstalter: Abrechnung (PDF)', 'Veranstalter', function () use ($name, $ev) {
                $body = '<p>Hallo,</p><p>die Abrechnung für <strong>' . esc_html($ev) . '</strong> ist erstellt. Das PDF hängt an dieser E-Mail.</p><p><a href="' . esc_url(home_url('/')) . '">Abrechnung im Studio ansehen</a></p>';
                return ['Abrechnung ' . $ev . ' (AB-2026-0042)', TIX_Emails::build_generic_email_html('Abrechnung erstellt', $body, $name, '', 'account')]; }],
            'awareness' => ['Team: Awareness-Meldung', 'Veranstalter', function () use ($L, $ev) {
                return ['Neue Awareness-Meldung', $L::render(['title' => 'Neue Awareness-Meldung', 'subtitle' => $ev, 'kind' => 'admin', 'blocks' => [
                    ['type' => 'notice', 'tone' => 'warn', 'html' => 'Es ist eine neue Awareness-Meldung eingegangen (Event: ' . esc_html($ev) . ').'],
                    ['type' => 'text', 'html' => 'Den Inhalt siehst du angemeldet im Veranstalter-Bereich bzw. in der Verwaltung unter Support.']]])]; }],
            // ── Admin ──
            'admin_bestellung' => ['Admin: neue Bestellung', 'Admin', function () use ($order, $name) {
                return [$name . ' – Neue Bestellung #1042', $order('admin_new_order')]; }],
            'admin_text' => ['Admin: Text-Mail (z. B. WordPress, Partner)', 'Admin', function () use ($L) {
                return ['[Seite] Freigabe nötig – neuer Partner: Club Beispiel', $L::from_plain('[Seite] Freigabe nötig – neuer Partner: Club Beispiel',
                    "Ein neuer Partner wurde gekoppelt und wartet auf die Freigabe.\n\nPartner: Club Beispiel\nVeranstalter: #92\n\nPrüfen: " . admin_url('admin.php?page=tix-partners'), 'admin')]; }],
        ];
        return apply_filters('tix_mail_gallery', $cat);
    }

    /** [subject, html, text] eines Eintrags. */
    public static function build($key) {
        $cat = self::catalog();
        if (!isset($cat[$key])) return null;
        list($subject, $html) = call_user_func($cat[$key][2]);
        $html = (string) $html;
        if (class_exists('TIX_Customer_URL')) $html = TIX_Customer_URL::in_text($html);
        return ['subject' => (string) $subject, 'html' => $html, 'text' => TIX_Mail_Layout::html_to_text($html)];
    }

    // ═══════════════ REST ═══════════════

    public static function rest_list(WP_REST_Request $req) {
        $out = [];
        foreach (self::catalog() as $k => $c) {
            $b = self::build($k);
            $out[] = ['key' => $k, 'label' => $c[0], 'group' => $c[1], 'subject' => $b ? $b['subject'] : ''];
        }
        $br = self::brand();
        return rest_ensure_response(['ok' => true, 'brand' => ['name' => $br['name'], 'accent' => $br['accent'], 'logo' => $br['logo'], 'logo_on_accent' => $br['logo_on_accent'], 'header' => $br['header'],
            'from' => self::from_line(), 'reply_to' => $br['support_email']], 'mails' => $out]);
    }

    public static function rest_preview(WP_REST_Request $req) {
        $key = sanitize_key((string) $req['key']);
        $b = self::build($key);
        if (!$b) return new WP_Error('tix_not_found', 'Diese Mail gibt es nicht.', ['status' => 404]);
        if ($req->get_param('format') === 'html') {
            header('Content-Type: text/html; charset=utf-8');
            echo $b['html']; // eigene Vorschau, Inhalt aus dem Layout
            exit;
        }
        return rest_ensure_response(['ok' => true, 'key' => $key] + $b);
    }

    private static function from_line() {
        $email = apply_filters('wp_mail_from', 'wordpress@' . preg_replace('/^www\./', '', strtolower((string) wp_parse_url(network_home_url(), PHP_URL_HOST))));
        $name = apply_filters('wp_mail_from_name', 'WordPress');
        return $name . ' <' . $email . '>';
    }

    // ═══════════════ Admin ═══════════════

    public static function menu() {
        add_submenu_page('tixomat', 'E-Mails', 'E-Mails', 'manage_options', 'tix-mails', [__CLASS__, 'render']);
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_mail_brand');
        $p = wp_unslash($_POST);
        $in = [];
        foreach (TIX_Studio::MAIL_KEYS as $k) $in[$k] = isset($p[$k]) ? (string) $p[$k] : '';
        $b = get_option(TIX_Studio::BRAND, []);
        $nb = TIX_Studio::apply_mail_fields(is_array($b) ? $b : [], $in);
        if (is_wp_error($nb)) {
            wp_safe_redirect(add_query_arg(['page' => 'tix-mails', 'err' => rawurlencode($nb->get_error_message())], admin_url('admin.php')));
            exit;
        }
        update_option(TIX_Studio::BRAND, $nb, false);
        TIX_Mail_Layout::flush();
        wp_safe_redirect(add_query_arg(['page' => 'tix-mails', 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    public static function send_test() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_mail_test');
        $key = sanitize_key((string) ($_POST['key'] ?? ''));
        $b = self::build($key);
        $to = wp_get_current_user()->user_email;
        $ok = $b && is_email($to) ? wp_mail($to, '[Test] ' . $b['subject'], $b['html'], ['Content-Type: text/html; charset=UTF-8']) : false;
        wp_safe_redirect(add_query_arg(['page' => 'tix-mails', 'key' => $key, 'test' => $ok ? 'ok' : 'fail'], admin_url('admin.php')));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $cat = self::catalog();
        $key = sanitize_key((string) ($_GET['key'] ?? ''));
        if (!isset($cat[$key])) $key = (string) array_key_first($cat);
        $o = TIX_Studio::mail_settings();
        $br = self::brand();
        $built = self::build($key);
        $groups = [];
        foreach ($cat as $k => $c) $groups[$c[1]][$k] = $c[0];
        $url = function ($k) { return esc_url(add_query_arg(['page' => 'tix-mails', 'key' => $k], admin_url('admin.php'))); };
        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1>E-Mails</h1>
            <p>Alle Mails dieser Seite nutzen ein gemeinsames Layout mit Logo, Markenfarbe und Fußzeile der Seite (Studio-Marke und Seiten-Konfiguration). Die Vorschau zeigt Beispieldaten.</p>
            <?php if (!empty($_GET['err'])): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html(wp_unslash((string) $_GET['err'])); ?></p></div><?php endif; ?>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <?php if (($_GET['test'] ?? '') === 'ok'): ?><div class="notice notice-success is-dismissible"><p>Test-Mail an <?php echo esc_html(wp_get_current_user()->user_email); ?> gesendet.</p></div><?php endif; ?>
            <?php if (($_GET['test'] ?? '') === 'fail'): ?><div class="notice notice-error is-dismissible"><p>Test-Mail konnte nicht gesendet werden.</p></div><?php endif; ?>
            <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:0 0 300px;max-width:100%;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:16px;">
                        <input type="hidden" name="action" value="tix_mail_brand"><?php wp_nonce_field('tix_mail_brand'); ?>
                        <h2 style="margin-top:0;">Marke in Mails</h2>
                        <p class="description" style="margin-top:0;">Dieselben Felder wie im Studio unter „Marke“. Die Akzentfarbe kommt aus der Marke.</p>
                        <p><strong>Kopf</strong><br>
                            <label><input type="radio" name="mail_header" value="" <?php checked(empty($o['mail_header'])); ?>> automatisch (<?php echo $br['logo_on_accent'] ? 'Markenfarbe' : 'hell'; ?>)</label><br>
                            <label><input type="radio" name="mail_header" value="light" <?php checked(($o['mail_header'] ?? '') === 'light'); ?>> hell (Logo auf Weiß, Linie in Markenfarbe)</label><br>
                            <label><input type="radio" name="mail_header" value="brand" <?php checked(($o['mail_header'] ?? '') === 'brand'); ?>> Markenfarbe (braucht ein Logo für farbigen Grund)</label><br>
                            <label><input type="radio" name="mail_header" value="color" <?php checked(($o['mail_header'] ?? '') === 'color'); ?>> eigene Farbe:</label>
                            <input type="text" name="mail_header_color" value="<?php echo esc_attr($o['mail_header_color'] ?? ''); ?>" placeholder="<?php echo esc_attr($br['accent']); ?>" style="width:100px;" pattern="#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?">
                            <span class="description">Knöpfe und Linien bleiben in der Markenfarbe.</span></p>
                        <?php foreach (['mail_logo_id' => ['Logo auf hellem Grund', '#ffffff', $br['logo'], 'leer = normales Logo'], 'mail_logo_on_color_id' => ['Logo für farbigen Kopf', $br['accent'], $br['logo_on_accent'], 'hell bzw. weiß']] as $fk => $f): ?>
                        <p><strong><?php echo esc_html($f[0]); ?></strong> <span class="description">(<?php echo esc_html($f[3]); ?>)</span><br>
                            <input type="hidden" name="<?php echo esc_attr($fk); ?>" class="tix-ml-id" value="<?php echo intval($o[$fk] ?? 0) ?: ''; ?>">
                            <span class="tix-ml-prev" style="display:inline-block;background:<?php echo esc_attr($f[1]); ?>;border:1px solid #dcdcde;padding:8px;border-radius:6px;margin:4px 0;"><?php echo $f[2] ? '<img src="' . esc_url($f[2]) . '" style="height:32px;display:block;">' : '<span>kein Logo</span>'; ?></span><br>
                            <button type="button" class="button tix-ml-pick">Auswählen</button> <button type="button" class="button-link tix-ml-clear">entfernen</button></p>
                        <?php endforeach; ?>
                        <p><label><strong>Absender-Adresse</strong><br><input type="email" name="mail_from" value="<?php echo esc_attr($o['mail_from'] ?? ''); ?>" placeholder="<?php echo esc_attr(TIX_Mail_Layout::default_from()); ?>" style="width:100%;"></label>
                            <span class="description">Muss im Mailversand der Seite (FluentSMTP bzw. Brevo) freigegeben sein.</span></p>
                        <p><label><strong>Absender-Name</strong><br><input type="text" name="mail_from_name" value="<?php echo esc_attr($o['mail_from_name'] ?? ''); ?>" placeholder="<?php echo esc_attr($br['name']); ?>" style="width:100%;"></label></p>
                        <p><label><strong>Antworten an</strong> (Support-Postfach, „Hilfe“)<br><input type="email" name="mail_reply_to" value="<?php echo esc_attr($o['mail_reply_to'] ?? ''); ?>" placeholder="<?php echo esc_attr($br['support_email']); ?>" style="width:100%;"></label></p>
                        <p>Absender jetzt: <code><?php echo esc_html(self::from_line()); ?></code></p>
                        <?php submit_button('Speichern', 'primary', 'submit', false); ?>
                    </form>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px 16px;">
                        <?php foreach ($groups as $g => $items): ?>
                            <h3 style="margin:12px 0 6px;"><?php echo esc_html($g); ?></h3>
                            <ul style="margin:0;">
                            <?php foreach ($items as $k => $label): ?>
                                <li><a href="<?php echo $url($k); ?>" style="<?php echo $k === $key ? 'font-weight:700;' : ''; ?>"><?php echo esc_html($label); ?></a></li>
                            <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="flex:1 1 640px;min-width:0;">
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
                        <strong style="font-size:14px;">Betreff:</strong> <span><?php echo esc_html($built ? $built['subject'] : ''); ?></span>
                        <span style="flex:1;"></span>
                        <button type="button" class="button" data-w="390">Handy</button>
                        <button type="button" class="button" data-w="760">Desktop</button>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="tix_mail_test"><input type="hidden" name="key" value="<?php echo esc_attr($key); ?>"><?php wp_nonce_field('tix_mail_test'); ?>
                            <button class="button button-primary">Test an mich senden</button>
                        </form>
                    </div>
                    <iframe id="tix-mail-frame" title="Vorschau" style="width:760px;max-width:100%;height:1200px;border:1px solid #dcdcde;border-radius:8px;background:#fff;" srcdoc="<?php echo esc_attr($built ? $built['html'] : ''); ?>"></iframe>
                    <details style="margin-top:12px;"><summary>Text-Fassung</summary><pre style="white-space:pre-wrap;background:#fff;border:1px solid #dcdcde;padding:12px;border-radius:8px;"><?php echo esc_html($built ? $built['text'] : ''); ?></pre></details>
                </div>
            </div>
        </div>
        <script>
        (function () {
            document.querySelectorAll('[data-w]').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('tix-mail-frame').style.width = b.getAttribute('data-w') + 'px'; }); });
            document.querySelectorAll('.tix-ml-pick').forEach(function (btn) {
                var p = btn.parentNode, inp = p.querySelector('.tix-ml-id'), prev = p.querySelector('.tix-ml-prev');
                btn.addEventListener('click', function () {
                    if (!window.wp || !wp.media) return;
                    var f = wp.media({ title: 'Logo wählen', library: { type: 'image' }, multiple: false });
                    f.on('select', function () { var a = f.state().get('selection').first().toJSON(); inp.value = a.id; prev.innerHTML = '<img src="' + (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url) + '" style="height:32px;display:block;">'; });
                    f.open();
                });
                p.querySelector('.tix-ml-clear').addEventListener('click', function () { inp.value = ''; prev.innerHTML = '<span>kein Logo</span>'; });
            });
        })();
        </script>
        <?php
    }
}

/** Beispiel-Bestellung für die Galerie (nur im Speicher, gleiche Methoden wie TIX_Order). */
class TIX_Mail_Gallery_Order {
    private $event; private $status; private $method;
    public function __construct($event, $status, $method) { $this->event = intval($event); $this->status = $status; $this->method = $method; }
    public function get_id() { return 1042; }
    public function get_order_number() { return '1042'; }
    public function get_status() { return $this->status; }
    public function get_date_created() { return new DateTime(current_time('mysql')); }
    public function get_billing_first_name() { return 'Lena'; }
    public function get_billing_last_name() { return 'Muster'; }
    public function get_billing_email() { return 'lena@example.com'; }
    public function get_payment_method() { return $this->method; }
    public function get_payment_method_title() { return $this->method === 'bacs' ? 'Vorkasse (Überweisung)' : 'PayPal'; }
    public function get_items() { return [new TIX_Mail_Gallery_Item($this->event, 'Vorverkauf', 2, 24.0), new TIX_Mail_Gallery_Item($this->event, 'Servicegebühr', 1, 1.8)]; }
    public function get_total_discount() { return 0.0; }
    public function get_total_tax() { return 0.0; }
    public function get_subtotal() { return 25.8; }
    public function get_total() { return 25.8; }
    public function get_formatted_order_total() { return '25,80 €'; }
    public function get_meta($k, $single = true) { return ''; }
}

class TIX_Mail_Gallery_Item {
    private $e; private $n; private $q; private $t;
    public function __construct($e, $n, $q, $t) { $this->e = $e; $this->n = $n; $this->q = $q; $this->t = $t; }
    public function get_event_id() { return $this->e; }
    public function get_product_id() { return 0; }
    public function get_name() { return $this->n; }
    public function get_quantity() { return $this->q; }
    public function get_total() { return $this->t; }
}
