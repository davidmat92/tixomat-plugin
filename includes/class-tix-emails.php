<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat – White-Label E-Mail-Templates
 *
 * Ersetzt alle WooCommerce-E-Mails durch eigenes Design.
 * Nutzt die Tixomat-Design-Tokens (Accent, Border, Radius) aus den Settings.
 * Hängt Ticket-Download-Links an.
 * Plant Erinnerungs- und Nachbefragungsmails via Action Scheduler.
 */
class TIX_Emails {

    /** Email-IDs die wir überschreiben */
    private static $override_ids = [
        // Order-basiert
        'new_order',
        'cancelled_order',
        'failed_order',
        'customer_processing_order',
        'customer_completed_order',
        'customer_on_hold_order',
        'customer_refunded_order',
        'customer_invoice',
        'customer_note',
        // Konto-basiert (kein Order)
        'customer_reset_password',
        'customer_new_account',
    ];

    /** Flag: nächste wp_mail() als HTML senden */
    private static $next_mail_html = false;

    /** Optionaler Hinweis-Block direkt unter der Ueberschrift (einmalig pro Mail, wird nach build geleert) */
    public static $intro_notice_html = '';

    /** Galerie-Vorschau (TIX_Mail_Gallery): Beispiel-Tickets statt Datenbank. */
    public static $preview_tickets = null;

    /** Aktuelles WC_Email-Objekt (wird vor woocommerce_mail_content gesetzt) */
    private static $current_email = null;

    public static function init() {
        // WC Email-Objekt abfangen (fires inside get_content(), BEFORE woocommerce_mail_content)
        add_action('woocommerce_email_header', [__CLASS__, 'capture_email'], 1, 2);

        // WC-Mail-Body komplett ersetzen (nur 1 Argument: der HTML-String)
        add_filter('woocommerce_mail_content', [__CLASS__, 'wrap_email'], 99);

        // WC-Default-CSS entfernen
        add_filter('woocommerce_email_styles', [__CLASS__, 'strip_wc_styles'], 99);

        // Erinnerung + Nachbefragung planen bei Bestellabschluss
        add_action('woocommerce_order_status_completed',  [__CLASS__, 'schedule_event_emails']);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'schedule_event_emails']);

        // Scheduler-Hooks
        add_action('tix_send_reminder_email', [__CLASS__, 'send_reminder'], 10, 2);
        add_action('tix_send_followup_email', [__CLASS__, 'send_followup'], 10, 2);

        // Native order emails (without WooCommerce)
        add_action('tix_order_completed', [__CLASS__, 'send_native_completed'], 10, 1);
        add_action('tix_order_status_changed', [__CLASS__, 'send_native_status_email'], 10, 4);

        // WordPress Core-Mails im Tixomat Design
        add_filter('retrieve_password_message',            [__CLASS__, 'wrap_password_reset'], 99, 4);
        add_filter('wp_mail',                              [__CLASS__, 'ensure_html_content_type'], 1);
        add_action('phpmailer_init',                       [__CLASS__, 'add_plaintext_alt'], 10);
        add_filter('wp_new_user_notification_email',       [__CLASS__, 'wrap_new_user_email'], 99, 3);
        add_filter('wp_new_user_notification_email_admin', [__CLASS__, 'wrap_new_user_admin_email'], 99, 3);
        add_filter('password_change_email',                [__CLASS__, 'wrap_password_change_email'], 99, 3);
        add_filter('email_change_email',                   [__CLASS__, 'wrap_email_change_email'], 99, 3);
    }

    // ══════════════════════════════════════
    // WC E-Mail-Body überschreiben
    // ══════════════════════════════════════

    /**
     * Email-Objekt zwischenspeichern.
     * woocommerce_email_header feuert INNERHALB von WC_Email::get_content(),
     * also VOR woocommerce_mail_content in WC_Emails::send().
     */
    public static function capture_email($heading, $email = null) {
        if ($email && is_object($email) && isset($email->id)) {
            self::$current_email = $email;
        }
    }

    /**
     * Hauptfilter: Ersetzt den gesamten WC-E-Mail-Body.
     * Bekommt NUR den HTML-String (1 Argument).
     * Das Email-Objekt wurde vorher via capture_email() gespeichert.
     */
    public static function wrap_email($content) {
        $email = self::$current_email;
        self::$current_email = null; // Reset für nächsten Aufruf

        if (!$email || !is_object($email) || !isset($email->id)) return $content;
        if (!in_array($email->id, self::$override_ids, true)) return $content;

        $heading = method_exists($email, 'get_heading') ? $email->get_heading() : '';
        $type    = self::map_email_type($email->id);

        // ── Nicht-Order WC E-Mails (Password Reset, New Account) ──
        if (in_array($email->id, ['customer_reset_password', 'customer_new_account'], true)) {
            return self::wrap_wc_account_email($email, $type, $heading);
        }

        // ── Order-basierte E-Mails ──
        $order = isset($email->object) && $email->object instanceof WC_Order ? $email->object : null;
        if (!$order) return $content;

        $s       = self::get_settings();
        $accent  = ($s['color_accent'] ?? '')      ?: '#c8ff00';
        $a_text  = ($s['color_accent_text'] ?? '') ?: '#000000';
        $radius  = intval(($s['radius_general'] ?? '') ?: 8);
        $extra   = '';

        // Customer Note
        if ($type === 'customer_note' && isset($email->customer_note)) {
            $extra = '
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
                <tr><td style="background-color: #eff6ff; border-radius: ' . $radius . 'px; padding: 14px 16px; font-size: 14px; color: #1e40af; line-height: 1.6;">
                    ' . wp_kses_post(wpautop(wptexturize($email->customer_note))) . '
                </td></tr>
            </table>';
        }

        // Invoice: Jetzt-bezahlen CTA
        if ($type === 'invoice') {
            $pay_url = $order->get_checkout_payment_url();
            $extra = '
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0 0;">
                <tr><td align="center">
                    <a href="' . esc_url($pay_url) . '" style="display:inline-block;padding:14px 36px;background-color:' . esc_attr($accent) . ';color:' . esc_attr($a_text) . ';text-decoration:none;font-weight:700;font-size:14px;border-radius:' . $radius . 'px;">Jetzt bezahlen</a>
                </td></tr>
            </table>';
        }

        // Failed Order: Fehlermeldung
        if ($type === 'admin_failed_order') {
            $extra = '
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
                <tr><td style="background-color: #fef2f2; border-radius: ' . $radius . 'px; padding: 14px 16px; font-size: 13px; color: #991b1b;">
                    <strong>Zahlung fehlgeschlagen.</strong> Die Bestellung wurde nicht abgeschlossen.
                </td></tr>
            </table>';
        }

        return self::build_email_html($type, $order, $heading, $extra);
    }

    /**
     * WC-Standard-CSS entfernen
     */
    public static function strip_wc_styles($css) {
        return '';
    }

    /**
     * E-Mail-ID → interner Typ-Name
     */
    private static function map_email_type($email_id) {
        $map = [
            'new_order'                   => 'admin_new_order',
            'cancelled_order'             => 'cancelled',
            'failed_order'                => 'admin_failed_order',
            'customer_processing_order'   => 'order_confirmation',
            'customer_completed_order'    => 'order_complete',
            'customer_on_hold_order'      => 'on_hold',
            'customer_refunded_order'     => 'refunded',
            'customer_invoice'            => 'invoice',
            'customer_note'               => 'customer_note',
            'customer_reset_password'     => 'wc_password_reset',
            'customer_new_account'        => 'wc_new_account',
        ];
        return $map[$email_id] ?? 'order_confirmation';
    }

    // ══════════════════════════════════════
    // HTML Template Builder
    // ══════════════════════════════════════

    /**
     * Art einer Bestellung (1.38.476): 'topup' (nur Guthaben-Aufladung), 'giftcard' (nur Gutscheine) oder 'tickets'.
     * Bestellungen auf System-Events bekommen keine Ticket-Texte („Deine Tickets …“).
     */
    public static function order_kind($order) {
        if (!is_object($order) && class_exists('TIX_Order')) $order = TIX_Order::get(intval($order));
        if (!is_object($order)) return 'tickets';
        $kinds = [];
        foreach ((array) $order->get_items() as $item) {
            $eid = method_exists($item, 'get_event_id') ? intval($item->get_event_id()) : 0;
            if (!$eid) $eid = intval(get_post_meta($item->get_product_id(), '_tix_parent_event_id', true));
            if ($eid && class_exists('TIX_Wallet_Balance') && TIX_Wallet_Balance::is_wallet_event($eid)) $kinds['topup'] = true;
            elseif ($eid && get_post_meta($eid, '_tix_system_giftcard_event', true) === '1') $kinds['giftcard'] = true;
            else $kinds['tickets'] = true;
        }
        if (!$kinds || isset($kinds['tickets'])) return 'tickets';
        return isset($kinds['topup']) && !isset($kinds['giftcard']) ? 'topup' : (isset($kinds['giftcard']) && !isset($kinds['topup']) ? 'giftcard' : 'tickets');
    }

    /**
     * Baut den kompletten HTML-Body einer Bestell-Mail (seit 1.38.471 im einheitlichen TIX_Mail_Layout).
     * $extra_content: HTML (wird angeglichen) oder Liste von Layout-Bausteinen (z. B. Gutscheine, Erinnerung).
     */
    public static function build_email_html($type, $order, $heading = '', $extra_content = '') {
        $order_id = $order->get_id();
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : get_bloginfo('name');
        $admin = in_array($type, ['admin_new_order', 'admin_failed_order'], true);

        if (!$heading) {
            $headings = [
                'order_confirmation' => 'Bestellbestätigung',
                'order_complete'     => 'Deine Tickets sind bereit!',
                'topup_complete'     => 'Guthaben aufgeladen',
                'giftcard_complete'  => 'Dein Gutschein ist da',
                'admin_new_order'    => 'Neue Bestellung',
                'admin_failed_order' => 'Bestellung fehlgeschlagen',
                'cancelled'          => 'Bestellung storniert',
                'on_hold'            => 'Bestellung wird geprüft',
                'refunded'           => 'Erstattung bestätigt',
                'invoice'            => 'Rechnung zu deiner Bestellung',
                'customer_note'      => 'Nachricht zu deiner Bestellung',
                'reminder'           => 'Morgen ist es soweit!',
                'followup'           => 'Danke für deinen Besuch!',
            ];
            $heading = $headings[$type] ?? 'Bestellbestätigung';
        }

        $created = $order->get_date_created();
        $created_ts = $created instanceof DateTimeInterface ? $created->getTimestamp() : (is_object($created) && method_exists($created, 'getTimestamp') ? $created->getTimestamp() : strtotime((string) $created));
        $number = method_exists($order, 'get_order_number') ? $order->get_order_number() : $order_id;
        $subtitle = '';
        if (in_array($type, ['order_confirmation', 'order_complete', 'topup_complete', 'giftcard_complete', 'admin_new_order', 'admin_failed_order', 'on_hold', 'invoice', 'customer_note'], true)) {
            $subtitle = 'Bestellung #' . $number . ($created_ts ? ' · ' . date_i18n('d.m.Y, H:i', $created_ts) : '');
        } elseif ($type === 'cancelled') {
            $subtitle = 'Bestellung #' . $number . ' wurde storniert.';
        } elseif ($type === 'refunded') {
            $subtitle = 'Bestellung #' . $number . ' wurde erstattet.';
        }

        $first = trim((string) $order->get_billing_first_name());
        $blocks = [];
        if (self::$intro_notice_html) { $blocks[] = ['type' => 'html', 'html' => self::$intro_notice_html]; self::$intro_notice_html = ''; }

        // Begrüßung
        $intro = [
            'order_confirmation' => 'danke für deine Bestellung! Wir haben sie erhalten.',
            'order_complete'     => 'danke für deine Bestellung! Deine Tickets sind unten – zeig einfach den QR-Code am Einlass.',
            'on_hold'            => 'danke für deine Bestellung! Sie wird geprüft bzw. wartet auf deine Zahlung. Sobald sie bestätigt ist, bekommst du deine Tickets per E-Mail.',
            'cancelled'          => 'deine Bestellung wurde storniert. Bereits erstellte Tickets sind damit ungültig.',
            'refunded'           => 'deine Erstattung ist bestätigt. Der Betrag geht über die ursprüngliche Zahlart an dich zurück.',
            'invoice'            => 'hier ist die Rechnung zu deiner Bestellung.',
            'topup_complete'     => 'danke! Dein Guthaben ist aufgeladen: <strong>' . esc_html(html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8')) . '</strong> sind jetzt auf deinem Konto. Du kannst damit in der App bezahlen.',
            'giftcard_complete'  => 'danke für deine Bestellung! Dein Gutschein ist da – den Code findest du unten.',
        ];
        if (!$admin && isset($intro[$type])) $blocks[] = ['type' => 'text', 'html' => 'Hallo' . ($first !== '' ? ' ' . esc_html($first) : '') . ',<br>' . $intro[$type]];

        // Admin: Kunde
        if ($admin) {
            $blocks[] = ['type' => 'info', 'title' => 'Kunde', 'rows' => array_values(array_filter([
                ['Name', esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()))],
                ['E-Mail', esc_html($order->get_billing_email())],
                $order->get_payment_method_title() ? ['Zahlart', esc_html($order->get_payment_method_title())] : null,
            ]))];
            if ($type === 'admin_failed_order') $blocks[] = ['type' => 'notice', 'tone' => 'error', 'html' => '<strong>Zahlung fehlgeschlagen.</strong> Die Bestellung wurde nicht abgeschlossen.'];
        }

        // Vorkasse: Bankdaten
        if ($type === 'on_hold' && in_array($order->get_payment_method(), ['bacs', 'bank'], true)) {
            $bs = function_exists('tix_get_settings') ? tix_get_settings() : [];
            $total = wp_strip_all_tags($order->get_formatted_order_total());
            $rows = [];
            if (!empty($bs['bank_holder'])) $rows[] = ['Kontoinhaber', esc_html($bs['bank_holder'])];
            if (!empty($bs['bank_iban']))   $rows[] = ['IBAN', '<span style="font-family:ui-monospace,Menlo,Consolas,monospace;">' . esc_html($bs['bank_iban']) . '</span>'];
            if (!empty($bs['bank_bic']))    $rows[] = ['BIC', esc_html($bs['bank_bic'])];
            if (!empty($bs['bank_name']))   $rows[] = ['Bank', esc_html($bs['bank_name'])];
            $rows[] = ['Betrag', esc_html($total)];
            if ($number) $rows[] = ['Verwendungszweck', esc_html($number)];
            $blocks[] = ['type' => 'info', 'title' => 'Bitte überweise ' . $total, 'rows' => $rows];
            $blocks[] = ['type' => 'notice', 'tone' => 'info', 'html' => (!empty($bs['bank_reference']) ? esc_html($bs['bank_reference']) . '<br>' : '') . 'Deine Tickets werden automatisch erstellt, sobald die Zahlung eingegangen ist.'];
        }

        // Event-Karte (eine Veranstaltung in der Bestellung)
        $event_ids = [];
        foreach ($order->get_items() as $item) {
            $eid = method_exists($item, 'get_event_id') ? intval($item->get_event_id()) : 0;
            if (!$eid) $eid = intval(get_post_meta($item->get_product_id(), '_tix_parent_event_id', true));
            if ($eid && !in_array($eid, $event_ids, true)) $event_ids[] = $eid;
        }
        if (count($event_ids) === 1 && in_array($type, ['order_confirmation', 'order_complete', 'on_hold', 'reminder', 'followup', 'admin_new_order'], true)) {
            $ev = self::event_block($event_ids[0]);
            if ($ev) $blocks[] = $ev;
        }

        // Tickets
        if (in_array($type, ['order_confirmation', 'order_complete'], true)) {
            $blocks = array_merge($blocks, self::ticket_blocks($order, count($event_ids) > 1));
            $url = class_exists('TIX_Tickets') ? (string) TIX_Tickets::get_bundle_url($order_id) : '';
            if ($url === '') {
                $s = tix_get_settings();
                $url = home_url('/' . (($s['my_tickets_slug'] ?? '') ?: 'tickets') . '/');
            }
            $blocks[] = ['type' => 'button', 'label' => 'Tickets öffnen', 'url' => $url];
        }

        // Zusatzinhalt (Gutscheine, Erinnerung, Nachbefragung)
        if (is_array($extra_content)) $blocks = array_merge($blocks, $extra_content);
        elseif ($extra_content) $blocks[] = ['type' => 'html', 'html' => $extra_content];

        // Bestellübersicht
        if (in_array($type, ['order_confirmation', 'order_complete', 'topup_complete', 'giftcard_complete', 'admin_new_order', 'admin_failed_order', 'on_hold', 'cancelled', 'refunded', 'invoice', 'customer_note'], true)) {
            $tb = self::order_table_block($order, count($event_ids) > 1);
            if ($tb) $blocks[] = $tb;
        }
        if (class_exists('TIX_Group_Booking') && in_array($type, ['order_confirmation', 'order_complete', 'admin_new_order', 'on_hold', 'invoice'], true)) {
            $split = TIX_Group_Booking::render_cost_split_html($order);
            if ($split) $blocks[] = ['type' => 'html', 'html' => $split];
        }
        if (!$admin && in_array($type, ['order_confirmation', 'order_complete', 'topup_complete', 'giftcard_complete', 'on_hold'], true)) {
            $blocks[] = ['type' => 'info', 'title' => 'Bestellung', 'rows' => array_values(array_filter([
                ['Bestellnummer', '#' . esc_html($number)],
                $created_ts ? ['Datum', date_i18n('d.m.Y, H:i', $created_ts)] : null,
                $order->get_payment_method_title() ? ['Zahlart', esc_html($order->get_payment_method_title())] : null,
            ]))];
        }

        $note = $admin
            ? 'Benachrichtigung für das Team von ' . $brand . '.'
            : (in_array($type, ['reminder', 'followup'], true) ? 'Du bekommst diese E-Mail, weil du Tickets bei ' . $brand . ' gekauft hast.' : 'Du bekommst diese E-Mail, weil du bei ' . $brand . ' bestellt hast.');
        $footer = (string) (self::get_settings()['email_footer_text'] ?? '');
        if ($footer !== '' && !$admin) $note = $footer;

        $m = ['title' => $heading, 'subtitle' => $subtitle, 'blocks' => $blocks, 'note' => $note, 'kind' => $admin ? 'admin' : 'customer',
              'preheader' => $type === 'order_complete' ? 'Bestellung #' . $number . ' – deine Tickets sind da.' : ''];
        if (class_exists('TIX_Mail_Layout')) return TIX_Mail_Layout::render($m);
        return '<html><body><h1>' . esc_html($heading) . '</h1></body></html>';
    }

    /** Event-Karte: Titel, Datum/Uhrzeit, Ort, Bild (Beitragsbild bzw. Platzhalter). */
    public static function event_block($event_id) {
        $event = get_post($event_id);
        if (!$event) return null;
        $date = (string) get_post_meta($event_id, '_tix_date_start', true);
        $time = (string) get_post_meta($event_id, '_tix_time_start', true);
        $doors = (string) get_post_meta($event_id, '_tix_time_doors', true);
        $img = (string) get_the_post_thumbnail_url($event_id, 'large');
        if ($img === '' && class_exists('TIX_Studio') && method_exists('TIX_Studio', 'event_placeholder')) {
            $ph = TIX_Studio::event_placeholder($event_id);
            $img = $ph ? (string) $ph['url'] : '';
        }
        $t = [];
        if ($doors) $t[] = 'Einlass ' . date_i18n('H:i', strtotime($doors));
        if ($time) $t[] = ($doors ? 'Beginn ' : '') . date_i18n('H:i', strtotime($time)) . ' Uhr';
        return ['type' => 'event', 'title' => html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8'),
                'date' => $date ? date_i18n('D, j. F Y', strtotime($date)) : '', 'time' => implode(' · ', $t),
                'location' => (string) get_post_meta($event_id, '_tix_location', true), 'image' => $img];
    }

    /** Tickets als Bausteine (QR + Code + PDF-Link); ab 10 Tickets Rest als Hinweis. */
    public static function ticket_blocks($order, $show_event = false) {
        $tickets = self::get_ticket_data($order);
        if (!$tickets) return [];
        $out = [];
        $n = count($tickets);
        $max = 10;
        foreach (array_slice($tickets, 0, $max) as $i => $t) {
            $code = (string) $t['code'];
            $eid = intval($t['event_id'] ?? 0);
            $out[] = ['type' => 'ticket', 'label' => $n > 1 ? 'Ticket ' . ($i + 1) . ' von ' . $n : 'Ticket',
                'title' => html_entity_decode((string) ($t['type_name'] ?: 'Ticket'), ENT_QUOTES, 'UTF-8'),
                'sub' => $show_event ? html_entity_decode((string) $t['event_name'], ENT_QUOTES, 'UTF-8') : (string) ($t['owner_name'] ?? ''),
                'code' => $code,
                'qr' => ($code !== '' && $eid && class_exists('TIX_QR')) ? TIX_QR::url('GL-' . $eid . '-' . $code, 240) : '',
                'link' => (string) $t['download_url'], 'link_label' => $t['download_url'] ? 'Ticket als PDF' : ''];
        }
        if ($n > $max) $out[] = ['type' => 'notice', 'tone' => 'info', 'html' => '+ ' . ($n - $max) . ' weitere Tickets – alle findest du über „Tickets öffnen“.'];
        return $out;
    }

    /** Bestellübersicht als Tabelle (Positionen, Rabatt, Gebühren, MwSt., Gesamt). */
    public static function order_table_block($order, $with_event = false) {
        $items = $order->get_items();
        if (empty($items)) return null;
        $rows = [];
        foreach ($items as $item) {
            $name = html_entity_decode((string) $item->get_name(), ENT_QUOTES, 'UTF-8');
            if ($with_event) {
                $eid = method_exists($item, 'get_event_id') ? intval($item->get_event_id()) : intval(get_post_meta($item->get_product_id(), '_tix_parent_event_id', true));
                if ($eid) $name = html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') . ' · ' . $name;
            }
            $rows[] = [$name, (string) $item->get_quantity(), self::format_price_plain($item->get_total())];
        }
        if ($order->get_total_discount() > 0) $rows[] = ['Rabatt', '', '−' . self::format_price_plain($order->get_total_discount())];
        foreach ((method_exists($order, 'get_fees') ? $order->get_fees() : []) as $fee) $rows[] = [html_entity_decode((string) $fee->get_name(), ENT_QUOTES, 'UTF-8'), '', self::format_price_plain($fee->get_total())];
        if ($order->get_total_tax() > 0) $rows[] = ['inkl. MwSt.', '', self::format_price_plain($order->get_total_tax())];
        return ['type' => 'table', 'rows' => $rows, 'total' => html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8')];
    }

    private static function format_price_plain($amount) {
        return html_entity_decode(wp_strip_all_tags(self::format_price($amount)), ENT_QUOTES, 'UTF-8');
    }

    // ══════════════════════════════════════
    // Generisches Email-Template (kein Order)
    // ══════════════════════════════════════

    /**
     * HTML-Mail ohne Bestell-Bezug im einheitlichen Layout (TIX_Mail_Layout, seit 1.38.471).
     * $body_html wird angeglichen (Knöpfe in Markenfarbe, Dark Mode). $kind: customer | account | admin.
     */
    public static function build_generic_email_html($heading, $body_html, $subtitle = '', $footer_note = '', $kind = 'customer') {
        if (class_exists('TIX_Mail_Layout')) return TIX_Mail_Layout::legacy($heading, $body_html, $subtitle, $footer_note, $kind);
        return '<html><body><h1>' . esc_html($heading) . '</h1>' . $body_html . '</body></html>';
    }

    /**
     * WC Account-Mails (Password Reset, New Account)
     * Diese haben keinen Order, sondern einen WP_User als Objekt.
     */
    private static function wrap_wc_account_email($email, $type, $heading) {
        $s       = self::get_settings();
        $accent  = ($s['color_accent'] ?? '')      ?: '#c8ff00';
        $a_text  = ($s['color_accent_text'] ?? '') ?: '#000000';
        $radius  = intval(($s['radius_general'] ?? '') ?: 8);

        $user = isset($email->object) && $email->object instanceof \WP_User ? $email->object : null;
        $login = $user ? $user->user_login : (isset($email->user_login) ? $email->user_login : '');

        if ($type === 'wc_password_reset') {
            $reset_url = '';
            if ($user && isset($email->reset_key)) {
                $reset_url = add_query_arg([
                    'key'   => $email->reset_key,
                    'id'    => $user->ID,
                ], wc_get_endpoint_url('lost-password', '', wc_get_page_permalink('myaccount')));
            }

            $body = '
            <p style="margin: 0 0 14px; font-size: 14px;">Jemand hat f&uuml;r dein Konto ein neues Passwort angefordert.</p>
            <p style="margin: 0 0 14px; font-size: 14px;">Benutzername: <strong>' . esc_html($login) . '</strong></p>
            <p style="margin: 0 0 14px; font-size: 14px;">Falls du dies nicht angefordert hast, kannst du diese E-Mail ignorieren.</p>';

            if ($reset_url) {
                $body .= '
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0 8px;">
                    <tr><td align="center">
                        <a href="' . esc_url($reset_url) . '" style="display:inline-block;padding:14px 36px;background-color:' . esc_attr($accent) . ';color:' . esc_attr($a_text) . ';text-decoration:none;font-weight:700;font-size:14px;border-radius:' . $radius . 'px;">Passwort zur&uuml;cksetzen</a>
                    </td></tr>
                </table>';
            }

            return self::build_generic_email_html(
                $heading ?: 'Passwort zur&uuml;cksetzen',
                $body,
                '',
                'Du erh&auml;ltst diese E-Mail, weil eine Passwort-Zur&uuml;cksetzung angefordert wurde.'
            );
        }

        if ($type === 'wc_new_account') {
            $body = '
            <p style="margin: 0 0 14px; font-size: 14px;">Willkommen! Dein Konto wurde erfolgreich erstellt.</p>
            <p style="margin: 0 0 14px; font-size: 14px;">Benutzername: <strong>' . esc_html($login) . '</strong></p>';

            // Set-Password URL (WC 6.0+)
            $set_pw_url = isset($email->set_password_url) ? $email->set_password_url : '';
            $my_account  = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';

            if ($set_pw_url) {
                $body .= '
                <p style="margin: 0 0 20px; font-size: 14px;">Bitte lege jetzt dein Passwort fest:</p>
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 8px;">
                    <tr><td align="center">
                        <a href="' . esc_url($set_pw_url) . '" style="display:inline-block;padding:14px 36px;background-color:' . esc_attr($accent) . ';color:' . esc_attr($a_text) . ';text-decoration:none;font-weight:700;font-size:14px;border-radius:' . $radius . 'px;">Passwort festlegen</a>
                    </td></tr>
                </table>';
            } elseif ($my_account) {
                $body .= '
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0 8px;">
                    <tr><td align="center">
                        <a href="' . esc_url($my_account) . '" style="display:inline-block;padding:14px 36px;background-color:' . esc_attr($accent) . ';color:' . esc_attr($a_text) . ';text-decoration:none;font-weight:700;font-size:14px;border-radius:' . $radius . 'px;">Zum Konto</a>
                    </td></tr>
                </table>';
            }

            return self::build_generic_email_html(
                $heading ?: 'Willkommen!',
                $body,
                'Dein Konto wurde erstellt.',
                'Du erh&auml;ltst diese E-Mail, weil ein Konto f&uuml;r dich erstellt wurde.'
            );
        }

        // Fallback
        return self::build_generic_email_html($heading ?: 'Benachrichtigung', '');
    }

    // ══════════════════════════════════════
    // WordPress Core-Mails
    // ══════════════════════════════════════

    /**
     * Passwort-Reset (WP Core)
     */
    public static function wrap_password_reset($message, $key, $user_login, $user_data) {
        self::$next_mail_html = true;
        $reset_url = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user_login), 'login');
        return self::layout([
            'title' => 'Passwort zurücksetzen',
            'kind' => 'account',
            'blocks' => [
                ['type' => 'text', 'html' => 'Hallo,<br>für dein Konto <strong>' . esc_html($user_login) . '</strong> wurde ein neues Passwort angefordert. Klick auf den Knopf, um ein neues Passwort festzulegen:'],
                ['type' => 'button', 'label' => 'Passwort zurücksetzen', 'url' => $reset_url],
                ['type' => 'notice', 'tone' => 'info', 'html' => 'Du hast das nicht angefordert? Dann kannst du diese E-Mail ignorieren – dein Passwort bleibt unverändert.'],
            ],
            'note' => 'Du bekommst diese E-Mail, weil für dein Konto ein neues Passwort angefordert wurde.',
        ]);
    }

    /** Mail im Layout (Fallback ohne Layout-Klasse: schlichtes HTML). */
    private static function layout(array $m) {
        if (class_exists('TIX_Mail_Layout')) return TIX_Mail_Layout::render($m);
        return '<html><body><h1>' . esc_html($m['title'] ?? '') . '</h1></body></html>';
    }

    /**
     * Neuer Benutzer – Mail an den Benutzer (WP Core)
     */
    public static function wrap_new_user_email($email, $user, $blogname) {
        // Set-Password URL aus der Original-Nachricht extrahieren
        $set_pw_url = '';
        if (preg_match('|(https?://\S+)|', $email['message'], $m)) {
            $set_pw_url = rtrim($m[1], '>');
        }
        $blocks = [['type' => 'text', 'html' => 'Hallo,<br>willkommen bei <strong>' . esc_html(class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : $blogname) . '</strong>! Dein Konto ist angelegt.'],
                   ['type' => 'info', 'rows' => [['Benutzername', esc_html($user->user_login)]]]];
        if ($set_pw_url) {
            $blocks[] = ['type' => 'text', 'html' => 'Leg jetzt dein Passwort fest:'];
            $blocks[] = ['type' => 'button', 'label' => 'Passwort festlegen', 'url' => $set_pw_url];
        }
        $email['message'] = self::layout(['title' => 'Willkommen!', 'subtitle' => 'Dein Konto wurde erstellt.', 'blocks' => $blocks, 'kind' => 'account',
            'note' => 'Du bekommst diese E-Mail, weil ein Konto für dich erstellt wurde.']);
        $email['headers'] = self::ensure_headers_html($email['headers'] ?? '');
        return $email;
    }

    /**
     * Neuer Benutzer – Admin-Benachrichtigung (WP Core)
     */
    public static function wrap_new_user_admin_email($email, $user, $blogname) {
        $email['message'] = self::layout(['title' => 'Neuer Benutzer', 'kind' => 'admin',
            'blocks' => [
                ['type' => 'text', 'html' => 'Ein neuer Benutzer hat sich registriert:'],
                ['type' => 'info', 'rows' => [['Benutzername', esc_html($user->user_login)], ['E-Mail', esc_html($user->user_email)]]],
            ],
            'note' => 'Benachrichtigung für das Team.']);
        $email['headers'] = self::ensure_headers_html($email['headers'] ?? '');
        return $email;
    }

    /**
     * Passwort geändert (WP Core)
     */
    public static function wrap_password_change_email($email, $user, $userdata) {
        $email['message'] = self::layout(['title' => 'Passwort geändert', 'kind' => 'account',
            'blocks' => [
                ['type' => 'text', 'html' => 'Hallo,<br>das Passwort deines Kontos <strong>' . esc_html($user['user_login']) . '</strong> wurde geändert.'],
                ['type' => 'notice', 'tone' => 'warn', 'html' => 'Du hast das nicht selbst geändert? Dann melde dich bitte sofort beim Support.'],
            ],
            'note' => 'Sicherheitshinweis zu deinem Konto.']);
        $email['headers'] = self::ensure_headers_html($email['headers'] ?? '');
        return $email;
    }

    /**
     * E-Mail-Adresse geändert (WP Core)
     */
    public static function wrap_email_change_email($email, $user, $userdata) {
        $new_email = $userdata['user_email'] ?? '';
        $rows = [['Benutzername', esc_html($user['user_login'])]];
        if ($new_email) $rows[] = ['Neue E-Mail', esc_html($new_email)];
        $email['message'] = self::layout(['title' => 'E-Mail-Adresse geändert', 'kind' => 'account',
            'blocks' => [
                ['type' => 'text', 'html' => 'Hallo,<br>die E-Mail-Adresse deines Kontos wurde geändert.'],
                ['type' => 'info', 'rows' => $rows],
                ['type' => 'notice', 'tone' => 'warn', 'html' => 'Du hast das nicht selbst geändert? Dann melde dich bitte sofort beim Support.'],
            ],
            'note' => 'Sicherheitshinweis zu deinem Konto.']);
        $email['headers'] = self::ensure_headers_html($email['headers'] ?? '');
        return $email;
    }

    /**
     * wp_mail Filter: HTML Content-Type setzen wenn Flag aktiv (Password Reset)
     */
    public static function ensure_html_content_type($atts) {
        if (!self::$next_mail_html) return $atts;
        self::$next_mail_html = false;

        if (!is_array($atts['headers'])) {
            $atts['headers'] = !empty($atts['headers']) ? [$atts['headers']] : [];
        }
        $atts['headers'][] = 'Content-Type: text/html; charset=UTF-8';

        return $atts;
    }

    /**
     * Headers-Array mit HTML Content-Type erstellen (Helper)
     */
    private static function ensure_headers_html($headers) {
        if (!is_array($headers)) {
            $headers = !empty($headers) ? [$headers] : [];
        }
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        return $headers;
    }

    // ══════════════════════════════════════
    // Email Deliverability: Reply-To, List-Unsubscribe, Plain-Text
    // ══════════════════════════════════════

    /**
     * Früher: Reply-To und List-Unsubscribe (mailto) an jede HTML-Mail.
     * Seit 1.38.471: Reply-To setzt TIX_Mail_Layout (Support-Postfach, nicht bei Admin-Mails); List-Unsubscribe
     * nur noch in Werbe- und Follow-Mails, dort RFC 8058 mit HTTPS-One-Click (setzen die Absender selbst).
     */
    public static function enhance_email_headers($atts) {
        return $atts;
    }

    /**
     * Text-Alternative (AltBody) für HTML-Mails, ohne CSS und Vorschauzeile (TIX_Mail_Layout::html_to_text).
     * Eigene Layout-Mails bekommen ihre Text-Fassung schon in TIX_Mail_Layout::phpmailer (Prio 5).
     */
    public static function add_plaintext_alt($phpmailer) {
        if ($phpmailer->ContentType !== 'text/html') return;
        if (!empty($phpmailer->AltBody)) return;
        if (empty($phpmailer->Body)) return;
        $text = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::html_to_text($phpmailer->Body) : trim(wp_strip_all_tags($phpmailer->Body));
        if ($text !== '') $phpmailer->AltBody = $text;
    }

    // ══════════════════════════════════════
    // Ticket Helper
    // ══════════════════════════════════════

    /**
     * Tickets für eine Bestellung laden
     * (Identische Logik wie class-tix-checkout.php render_thankyou)
     */
    private static function get_ticket_data($order) {
        if (is_array(self::$preview_tickets)) return self::$preview_tickets;
        $tickets  = [];
        $order_id = $order->get_id();

        // Unified Interface: TIX_Tickets liefert normalisierte Daten aus beiden Quellen
        if (class_exists('TIX_Tickets')) {
            $unified = TIX_Tickets::get_all_tickets_for_order($order_id);
            foreach ($unified as $ut) {
                $tickets[] = [
                    'id'           => $ut['id'],
                    'code'         => $ut['code'],
                    'type_name'    => $ut['cat_name'],
                    'event_name'   => $ut['event_name'],
                    'event_id'     => intval($ut['event_id'] ?? 0),
                    'owner_name'   => (string) ($ut['owner_name'] ?? ''),
                    'download_url' => $ut['download_url'],
                ];
            }
        }

        return $tickets;
    }

    // ══════════════════════════════════════
    // Erinnerung + Nachbefragung (Scheduler)
    // ══════════════════════════════════════

    /**
     * Bei Bestellabschluss: Erinnerungs- und Nachbefragungsmails planen
     */
    public static function schedule_event_emails($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $s = self::get_settings();
        $reminder_active = !empty($s['email_reminder']);
        $followup_active = !empty($s['email_followup']);

        if (!$reminder_active && !$followup_active) return;

        // Alle Events in dieser Bestellung finden
        $event_ids = [];
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $event_id   = get_post_meta($product_id, '_tix_parent_event_id', true);
            if ($event_id && !in_array($event_id, $event_ids)) {
                $event_ids[] = $event_id;
            }
        }

        $now = time();

        foreach ($event_ids as $event_id) {
            $date_start = get_post_meta($event_id, '_tix_date_start', true);
            $time_start = get_post_meta($event_id, '_tix_time_start', true);

            if (!$date_start) continue;

            $event_time = strtotime($date_start . ($time_start ? ' ' . $time_start : ' 00:00'));
            if (!$event_time) continue;

            // Erinnerung: 24h vor Event
            if ($reminder_active) {
                $reminder_time = $event_time - (24 * 3600);
                if ($reminder_time > $now) {
                    // Deduplizierung prüfen
                    if (function_exists('as_next_scheduled_action')) {
                        $existing = as_next_scheduled_action('tix_send_reminder_email', [$order_id, $event_id]);
                        if (!$existing) {
                            as_schedule_single_action($reminder_time, 'tix_send_reminder_email', [$order_id, $event_id]);
                        }
                    } else {
                        if (!wp_next_scheduled('tix_send_reminder_email', [$order_id, $event_id])) {
                            wp_schedule_single_event($reminder_time, 'tix_send_reminder_email', [$order_id, $event_id]);
                        }
                    }
                }
            }

            // Nachbefragung: 24h nach Event
            if ($followup_active) {
                $followup_time = $event_time + (24 * 3600);
                if ($followup_time > $now) {
                    if (function_exists('as_next_scheduled_action')) {
                        $existing = as_next_scheduled_action('tix_send_followup_email', [$order_id, $event_id]);
                        if (!$existing) {
                            as_schedule_single_action($followup_time, 'tix_send_followup_email', [$order_id, $event_id]);
                        }
                    } else {
                        if (!wp_next_scheduled('tix_send_followup_email', [$order_id, $event_id])) {
                            wp_schedule_single_event($followup_time, 'tix_send_followup_email', [$order_id, $event_id]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Erinnerungsmail senden (24h vor Event)
     */
    public static function send_reminder($order_id, $event_id) {
        $domain_scope = class_exists('TIX_Org_Domains') ? TIX_Org_Domains::scope_order($order_id) : null; // Links auf die eigene Domain, wenn die Bestellung von dort kam
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order && class_exists('TIX_Order')) $order = TIX_Order::get($order_id);
        $event = get_post($event_id);
        if (!$order || !$event) return;

        // Prüfen ob Order noch gültig
        if (!in_array($order->get_status(), ['completed', 'processing'])) return;

        $extra = [];
        $address = (string) get_post_meta($event_id, '_tix_address', true);
        if ($address) $extra[] = ['type' => 'info', 'rows' => [['Adresse', '<a href="' . esc_url('https://www.google.com/maps/search/' . urlencode($address)) . '">' . esc_html($address) . '</a>']]];
        $extra = array_merge($extra, self::ticket_blocks($order));
        $url = class_exists('TIX_Tickets') ? (string) TIX_Tickets::get_bundle_url($order_id) : '';
        if ($url) $extra[] = ['type' => 'button', 'label' => 'Tickets öffnen', 'url' => $url];
        array_unshift($extra, ['type' => 'text', 'html' => 'Hallo' . ($order->get_billing_first_name() ? ' ' . esc_html($order->get_billing_first_name()) : '') . ',<br>morgen ist es so weit: <strong>' . esc_html($event->post_title) . '</strong>. Hier sind alle Infos und deine Tickets.']);

        $html = self::build_email_html('reminder', $order, 'Morgen ist es soweit!', $extra);
        $subject = 'Erinnerung: ' . html_entity_decode($event->post_title, ENT_QUOTES, 'UTF-8') . ' ist morgen!';
        TIX_Customer_URL::mail($order->get_billing_email(), $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * Nachbefragungsmail senden (24h nach Event)
     */
    public static function send_followup($order_id, $event_id) {
        $domain_scope = class_exists('TIX_Org_Domains') ? TIX_Org_Domains::scope_order($order_id) : null; // Links auf die eigene Domain, wenn die Bestellung von dort kam
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order && class_exists('TIX_Order')) $order = TIX_Order::get($order_id);
        $event = get_post($event_id);
        if (!$order || !$event) return;

        if (!in_array($order->get_status(), ['completed', 'processing'])) return;

        $s = self::get_settings();
        $followup_url = $s['email_followup_url'] ?: '';
        $extra = [['type' => 'text', 'html' => 'Hallo' . ($order->get_billing_first_name() ? ' ' . esc_html($order->get_billing_first_name()) : '') . ',<br>wir hoffen, du hattest eine tolle Zeit bei <strong>' . esc_html($event->post_title) . '</strong>! Vielen Dank für deinen Besuch – wir freuen uns, dich beim nächsten Event wiederzusehen.']];
        if ($followup_url) $extra[] = ['type' => 'button', 'label' => 'Feedback geben', 'url' => $followup_url];

        // ── Feedback-Sterne (wenn Feedback-System aktiviert) ──
        if (!empty($s['feedback_enabled']) && class_exists('TIX_Feedback')) {
            $extra[] = ['type' => 'html', 'html' => TIX_Feedback::get_email_stars_html($order_id, $event_id, $order->get_billing_email())];
        }

        $html = self::build_email_html('followup', $order, 'Danke für deinen Besuch!', $extra);
        $subject = 'Danke für deinen Besuch bei ' . html_entity_decode($event->post_title, ENT_QUOTES, 'UTF-8') . '!';
        TIX_Customer_URL::mail($order->get_billing_email(), $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ══════════════════════════════════════
    // Native Order Emails (ohne WooCommerce)
    // ══════════════════════════════════════

    /**
     * Sendet Bestätigungsmail bei abgeschlossener nativer Bestellung.
     */
    public static function send_native_completed($order_id) {
        $domain_scope = class_exists('TIX_Org_Domains') ? TIX_Org_Domains::scope_order($order_id) : null; // Links auf die eigene Domain, wenn die Bestellung von dort kam
        if (!class_exists('TIX_Order')) return;

        $order = TIX_Order::get($order_id);
        if (!$order) return;

        // Skip if this native order is linked to a WC order (WC handles its own emails)
        $wc_id = method_exists($order, 'get_wc_order_id') ? $order->get_wc_order_id() : 0;
        if ($wc_id > 0 && function_exists('wc_get_order')) return;

        $brand_name = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : (self::get_settings()['email_brand_name'] ?: get_bloginfo('name'));

        // Gutschein als Geschenk (1.38.463): Codes jetzt erzeugen, Geschenk-Block + PDF-Anhang
        $gift = null;
        if (class_exists('TIX_Giftcard_Gift')) {
            TIX_Giftcards::$in_mail = true;
            $gift = TIX_Giftcard_Gift::mail_parts($order_id);
            TIX_Giftcards::$in_mail = false;
            if ($gift) update_post_meta($order_id, '_tix_gift_mail_sent', current_time('mysql'));
        }

        // Customer confirmation – Guthaben-Aufladung bzw. reiner Gutschein-Kauf ohne Ticket-Texte (1.38.476)
        $kind = self::order_kind($order);
        $ctype = $kind === 'topup' ? 'topup_complete' : ($kind === 'giftcard' ? 'giftcard_complete' : 'order_complete');
        $chead = $kind === 'topup' ? 'Guthaben aufgeladen' : ($kind === 'giftcard' ? 'Dein Gutschein ist da' : 'Deine Tickets sind bereit!');
        // Rechnung/Beleg als PDF-Anhang (Rechnungs-Kern, nur mit Schalter tix_invoices_enabled, 1.38.487)
        $inv_files = class_exists('TIX_Invoices') ? TIX_Invoices::completed_mail_files($order_id) : [];
        $extra = $gift ? ($gift['blocks'] ?? $gift['html']) : '';
        if ($inv_files) {
            $note = ['type' => 'notice', 'tone' => 'info', 'html' => count($inv_files) > 1 ? 'Deine Rechnung und Belege findest du als PDF im Anhang.' : 'Deine Rechnung findest du als PDF im Anhang.'];
            $extra = is_array($extra) ? array_merge($extra, [$note]) : ($extra !== '' ? [['type' => 'html', 'html' => $extra], $note] : [$note]);
        }
        $html = self::build_email_html($ctype, $order, $chead, $extra);
        self::send_html_mail(
            $order->get_billing_email(),
            $brand_name . ' – ' . $chead,
            $html,
            true,
            array_merge($gift ? $gift['files'] : [], $inv_files)
        );
        if ($gift) TIX_Giftcard_Gift::cleanup($gift['files']);
        if ($inv_files) TIX_Invoices::cleanup($inv_files);

        // Marker setzen — verhindert Doppel-Versand durch Auto-Heal-Mechanismus
        update_post_meta($order_id, '_tix_completed_email_sent', current_time('mysql'));

        // Admin notification (new_order) — kann per Flag unterdrückt werden
        $skip_admin = get_transient('_tix_skip_admin_email_' . $order_id);
        // Demo Club / Vorschau (1.38.508): nie „Neue Bestellung“ an das Team
        if (!$skip_admin && class_exists('TIX_Sales') && TIX_Sales::is_sandbox_order($order_id)) $skip_admin = 1;
        if ($skip_admin) {
            delete_transient('_tix_skip_admin_email_' . $order_id);
        } else {
            $admin_html = self::build_email_html('admin_new_order', $order, 'Neue Bestellung');
            self::send_html_mail(
                get_option('admin_email'),
                $brand_name . ' – Neue Bestellung #' . $order->get_id(),
                $admin_html
            );
        }

        // Schedule reminder + followup emails for native orders
        self::schedule_native_event_emails($order);
    }

    /**
     * Sendet Status-Änderungs-Mails für native Bestellungen.
     */
    public static function send_native_status_email($order_id, $new_status, $old_status, $gateway) {
        $domain_scope = class_exists('TIX_Org_Domains') ? TIX_Org_Domains::scope_order($order_id) : null; // Links auf die eigene Domain, wenn die Bestellung von dort kam
        if (!class_exists('TIX_Order')) return;

        // No email needed if status didn't change
        if ($new_status === $old_status) return;

        // No email for pending status
        if ($new_status === 'pending') return;

        // Stripe-Auto-Cleanup (Cron, ?cancelled=1, session.expired): keine Kunden-Mail —
        // der Kunde hat nie bezahlt und keine Bestellbestaetigung erhalten. Eine
        // "Bestellung storniert"-Mail waere verwirrend.
        if ($new_status === 'cancelled'
            && class_exists('TIX_Gateway_Stripe')
            && (!empty(TIX_Gateway_Stripe::$suppress_cancel_notifications) || !empty(TIX_Gateway_Stripe::$suppress_cancel_email))) {
            return;
        }

        $order = TIX_Order::get($order_id);
        if (!$order) return;

        // Skip if this native order is linked to a WC order (WC handles its own emails)
        $wc_id = method_exists($order, 'get_wc_order_id') ? $order->get_wc_order_id() : 0;
        if ($wc_id > 0 && function_exists('wc_get_order')) return;

        $brand_name = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : (self::get_settings()['email_brand_name'] ?: get_bloginfo('name'));

        // Map status to email type and heading
        $status_map = [
            'on-hold'   => ['type' => 'on_hold',    'heading' => 'Bestellung wird geprüft',    'subject' => 'Bestellung wird geprüft'],
            'cancelled' => ['type' => 'cancelled',   'heading' => 'Bestellung storniert',       'subject' => 'Bestellung storniert'],
            'failed'    => ['type' => 'admin_failed_order', 'heading' => 'Bestellung fehlgeschlagen', 'subject' => 'Bestellung fehlgeschlagen'],
            'refunded'  => ['type' => 'refunded',     'heading' => 'Erstattung bestätigt',       'subject' => 'Deine Erstattung wurde bestätigt'],
        ];

        if (!isset($status_map[$new_status])) return;

        $config  = $status_map[$new_status];
        $html    = self::build_email_html($config['type'], $order, $config['heading']);
        $subject = $brand_name . ' – ' . $config['subject'] . ' #' . $order->get_id();

        if ($new_status === 'failed') {
            // Failed: admin only
            self::send_html_mail(get_option('admin_email'), $subject, $html);
        } else {
            // on-hold, cancelled, refunded: send to customer – Storno/Erstattung mit Rechnungskorrektur als PDF (1.38.487)
            $inv_files = in_array($new_status, ['cancelled', 'refunded'], true) && class_exists('TIX_Invoices') ? TIX_Invoices::status_mail_files($order_id) : [];
            if ($inv_files) $html = self::build_email_html($config['type'], $order, $config['heading'], [['type' => 'notice', 'tone' => 'info', 'html' => 'Die Rechnungskorrektur findest du als PDF im Anhang.']]);
            self::send_html_mail($order->get_billing_email(), $subject, $html, true, $inv_files);
            if ($inv_files) TIX_Invoices::cleanup($inv_files);
        }
    }

    /**
     * HTML-Mail versenden via wp_mail mit korrektem Content-Type Header.
     */
    public static function send_html_mail($to, $subject, $html, $for_customer = false, $attachments = []) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        // Mails an Kunden: Links auf die Kunden-Adresse (TIX_Customer_URL); Admin-Mails unverändert
        if ($for_customer && class_exists('TIX_Customer_URL')) $html = TIX_Customer_URL::in_text($html);
        wp_mail($to, $subject, $html, $headers, (array) $attachments);
    }

    /**
     * Erinnerungs- und Nachbefragungsmails für native Orders planen.
     */
    private static function schedule_native_event_emails($order) {
        $s = self::get_settings();
        $reminder_active = !empty($s['email_reminder']);
        $followup_active = !empty($s['email_followup']);

        if (!$reminder_active && !$followup_active) return;

        $order_id  = $order->get_id();
        $event_ids = [];

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $event_id   = $item->get_event_id() ?: (int) get_post_meta($product_id, '_tix_parent_event_id', true);
            if ($event_id && !in_array($event_id, $event_ids)) {
                $event_ids[] = $event_id;
            }
        }

        $now = time();

        foreach ($event_ids as $event_id) {
            $date_start = get_post_meta($event_id, '_tix_date_start', true);
            $time_start = get_post_meta($event_id, '_tix_time_start', true);

            if (!$date_start) continue;

            $event_time = strtotime($date_start . ($time_start ? ' ' . $time_start : ' 00:00'));
            if (!$event_time) continue;

            if ($reminder_active) {
                $reminder_time = $event_time - (24 * 3600);
                if ($reminder_time > $now) {
                    if (function_exists('as_next_scheduled_action')) {
                        if (!as_next_scheduled_action('tix_send_reminder_email', [$order_id, $event_id])) {
                            as_schedule_single_action($reminder_time, 'tix_send_reminder_email', [$order_id, $event_id]);
                        }
                    } else {
                        if (!wp_next_scheduled('tix_send_reminder_email', [$order_id, $event_id])) {
                            wp_schedule_single_event($reminder_time, 'tix_send_reminder_email', [$order_id, $event_id]);
                        }
                    }
                }
            }

            if ($followup_active) {
                $followup_time = $event_time + (24 * 3600);
                if ($followup_time > $now) {
                    if (function_exists('as_next_scheduled_action')) {
                        if (!as_next_scheduled_action('tix_send_followup_email', [$order_id, $event_id])) {
                            as_schedule_single_action($followup_time, 'tix_send_followup_email', [$order_id, $event_id]);
                        }
                    } else {
                        if (!wp_next_scheduled('tix_send_followup_email', [$order_id, $event_id])) {
                            wp_schedule_single_event($followup_time, 'tix_send_followup_email', [$order_id, $event_id]);
                        }
                    }
                }
            }
        }
    }

    // ══════════════════════════════════════
    // Price Formatting Helper
    // ══════════════════════════════════════

    /**
     * Preis formatieren – nutzt wc_price() wenn verfügbar, sonst eigene Formatierung.
     */
    private static function format_price($amount) {
        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($amount));
        }
        return number_format(floatval($amount), 2, ',', '.') . '&nbsp;&euro;';
    }

    // ══════════════════════════════════════
    // Settings Helper
    // ══════════════════════════════════════

    private static function get_settings() {
        if (class_exists('TIX_Settings')) {
            return tix_get_settings();
        }
        return wp_parse_args(get_option('tix_settings', []), [
            'color_accent'      => '#c8ff00',
            'color_accent_text' => '#000000',
            'color_border'      => '#333333',
            'radius_general'    => 8,
            'email_logo_url'    => '',
            'email_brand_name'  => '',
            'email_footer_text' => '',
            'email_reminder'    => 1,
            'email_followup'    => 1,
            'email_followup_url' => '',
        ]);
    }

    // ══════════════════════════════════════
    // Gästeliste: E-Mail-Benachrichtigung
    // ══════════════════════════════════════

    /**
     * Sendet einem Gast seinen QR-Code per E-Mail.
     *
     * @param int    $event_id
     * @param string $guest_id
     * @return bool
     */
    public static function send_guest_notification($event_id, $guest_id) {
        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event') return false;

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) return false;

        $guest = null;
        foreach ($guests as $g) {
            if (($g['id'] ?? '') === $guest_id) { $guest = $g; break; }
        }
        if (!$guest || empty($guest['email'])) return false;

        // QR-Code
        $qr_code = 'GL-' . $event_id . '-' . $guest_id;
        $qr_url  = home_url('?tix_guest=' . urlencode($qr_code));
        $qr_img  = class_exists('TIX_QR') ? TIX_QR::url($qr_code, 240) : ''; // lokal statt quickchart.io (1.38.465)
        $plus = !empty($guest['plus']) && $guest['plus'] > 0 ? '+' . intval($guest['plus']) . ' Begleitung' : '';

        $blocks = [['type' => 'text', 'html' => 'Hallo ' . esc_html($guest['name']) . ',<br>du stehst auf der Gästeliste! Zeig diesen QR-Code am Einlass.']];
        $ev = self::event_block($event_id);
        if ($ev) $blocks[] = $ev;
        $blocks[] = ['type' => 'ticket', 'label' => 'Gästeliste', 'title' => (string) $guest['name'], 'sub' => $plus, 'code' => $qr_code, 'qr' => $qr_img];
        $blocks[] = ['type' => 'button', 'label' => 'QR-Code öffnen', 'url' => $qr_url];
        $html = self::layout(['title' => 'Du bist auf der Gästeliste', 'subtitle' => html_entity_decode($event->post_title, ENT_QUOTES, 'UTF-8'), 'blocks' => $blocks, 'kind' => 'customer']);

        $subject = 'Gästeliste: ' . html_entity_decode($event->post_title, ENT_QUOTES, 'UTF-8');
        return TIX_Customer_URL::mail($guest['email'], $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ══════════════════════════════════════
    // Abandoned Cart Recovery E-Mail
    // ══════════════════════════════════════

    /**
     * Sendet eine Abandoned-Cart-Erinnerungsmail.
     * Wird via Action Scheduler aufgerufen.
     */
    public static function send_abandoned_cart($ac_post_id) {
        $post = get_post($ac_post_id);
        if (!$post || $post->post_type !== 'tix_abandoned_cart') return;

        $status = get_post_meta($ac_post_id, '_tix_ac_status', true);
        if ($status !== 'pending') return; // Bereits recovered/expired/sent

        $email    = get_post_meta($ac_post_id, '_tix_ac_email', true);
        $token    = get_post_meta($ac_post_id, '_tix_ac_token', true);
        $event_id = intval(get_post_meta($ac_post_id, '_tix_ac_event_id', true));
        $cart_raw = get_post_meta($ac_post_id, '_tix_ac_cart_data', true);

        if (!$email || !$token) return;

        // Event-Name
        $event_title = '';
        if ($event_id) {
            $event = get_post($event_id);
            if ($event) $event_title = $event->post_title;
        }

        // Cart-Daten auslesen
        $cart_items = json_decode($cart_raw, true);
        $rows = [];
        if (is_array($cart_items)) foreach ($cart_items as $item) $rows[] = [(string) ($item['name'] ?? 'Ticket'), (string) intval($item['qty'] ?? 1), ''];

        // Recovery-URL: zur Event-Seite zurück (mit recovery-Token in URL)
        $base_url = $event_id ? get_permalink($event_id) : home_url('/');
        $recovery_url = add_query_arg('tix_recover_cart', $token, $base_url);

        $s = self::get_settings();
        $blocks = [['type' => 'text', 'html' => 'Hallo,<br>du hast noch Tickets in deinem Warenkorb' . ($event_title ? ' für <strong>' . esc_html($event_title) . '</strong>' : '') . '.']];
        if ($event_id && ($ev = self::event_block($event_id))) $blocks[] = $ev;
        if ($rows) $blocks[] = ['type' => 'table', 'rows' => $rows];
        $blocks[] = ['type' => 'button', 'label' => 'Warenkorb wiederherstellen', 'url' => $recovery_url];
        $blocks[] = ['type' => 'text', 'html' => '<span class="tm-muted" style="font-size:13px;color:#6B7280;">Dieser Link ist 48 Stunden gültig.</span>'];

        // Betreff
        $subject = $s['abandoned_cart_subject'] ?? '';
        if (empty($subject)) {
            $subject = $event_title
                ? 'Du hast noch Tickets für ' . html_entity_decode($event_title, ENT_QUOTES, 'UTF-8') . ' im Warenkorb'
                : 'Du hast noch Tickets im Warenkorb';
        }
        $html = self::layout(['title' => 'Du hast noch Tickets im Warenkorb', 'blocks' => $blocks, 'kind' => 'customer', 'note' => 'Automatisch erstellte Erinnerung an deinen Warenkorb.']);
        TIX_Customer_URL::mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);

        // Status aktualisieren
        update_post_meta($ac_post_id, '_tix_ac_status', 'sent');
        update_post_meta($ac_post_id, '_tix_ac_sent_at', current_time('c'));
    }
}
