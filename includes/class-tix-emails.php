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

        // WordPress Core-Mails im Tixomat Design
        add_filter('retrieve_password_message',            [__CLASS__, 'wrap_password_reset'], 99, 4);
        add_filter('wp_mail',                              [__CLASS__, 'ensure_html_content_type'], 1);
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
        $accent  = $s['color_accent']      ?: '#c8ff00';
        $a_text  = $s['color_accent_text'] ?: '#000000';
        $radius  = intval($s['radius_general'] ?: 8);
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
     * Baut den kompletten HTML-Body einer E-Mail
     */
    public static function build_email_html($type, $order, $heading = '', $extra_content = '') {
        $s = self::get_settings();

        $accent      = $s['color_accent']      ?: '#c8ff00';
        $accent_text = $s['color_accent_text'] ?: '#000000';
        $border      = $s['color_border']       ?: '#333333';
        $radius      = intval($s['radius_general'] ?: 8);
        $brand_name  = $s['email_brand_name']  ?: get_bloginfo('name');
        $logo_url    = $s['email_logo_url']    ?: '';
        $logo_height = intval($s['email_logo_height'] ?? 40) ?: 40;
        $footer_text = $s['email_footer_text'] ?: '';

        $text_color  = '#1a1a1a';
        $muted       = '#6b7280';
        $bg          = '#f3f4f6';
        $card_bg     = '#ffffff';
        $divider     = '#e5e7eb';

        $order_id = $order->get_id();

        // Heading-Defaults
        if (!$heading) {
            $headings = [
                'order_confirmation' => 'Bestellbestätigung',
                'order_complete'     => 'Deine Tickets sind bereit!',
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

        // Subtitle
        $subtitle = '';
        if (in_array($type, ['order_confirmation', 'order_complete', 'admin_new_order', 'admin_failed_order', 'on_hold', 'invoice', 'customer_note'])) {
            $subtitle = 'Bestellung #' . $order_id . ' &middot; ' . date_i18n('d.m.Y, H:i', strtotime($order->get_date_created()));
        } elseif ($type === 'cancelled') {
            $subtitle = 'Bestellung #' . $order_id . ' wurde storniert.';
        } elseif ($type === 'refunded') {
            $subtitle = 'Bestellung #' . $order_id . ' wurde erstattet.';
        }

        // Content Blocks
        $items_html   = '';
        $tickets_html = '';
        $event_details_html = '';
        $cta_html     = '';

        // Order Items (alle order-basierten Mails)
        if (in_array($type, ['order_confirmation', 'order_complete', 'admin_new_order', 'admin_failed_order', 'on_hold', 'cancelled', 'refunded', 'invoice', 'customer_note'])) {
            $items_html = self::render_order_items_html($order, $accent, $text_color, $muted, $divider, $radius);
        }

        // Tickets (nur bei completed + processing)
        if (in_array($type, ['order_confirmation', 'order_complete'])) {
            $tickets_html = self::render_tickets_html($order, $accent, $accent_text, $text_color, $muted, $radius);
        }

        // Extra content (Erinnerung / Nachbefragung)
        if ($extra_content) {
            $event_details_html = $extra_content;
        }

        // CTA für Meine Tickets
        if (in_array($type, ['order_confirmation', 'order_complete'])) {
            $my_tickets_url = home_url('/tickets/');
            $cta_html = '
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0 0;">
                <tr>
                    <td align="center">
                        <a href="' . esc_url($my_tickets_url) . '" style="display: inline-block; padding: 12px 32px; background-color: ' . esc_attr($accent) . '; color: ' . esc_attr($accent_text) . '; text-decoration: none; font-weight: 700; font-size: 14px; border-radius: ' . $radius . 'px; mso-padding-alt: 0;">
                            Meine Tickets ansehen
                        </a>
                    </td>
                </tr>
            </table>';
        }

        // Admin-Info
        $admin_info = '';
        if ($type === 'admin_new_order' || $type === 'admin_failed_order') {
            $admin_info = '
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
                <tr>
                    <td style="background-color: #fef3c7; border-radius: ' . $radius . 'px; padding: 12px 16px; font-size: 13px; color: #92400e;">
                        <strong>Kunde:</strong> ' . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '<br>
                        <strong>E-Mail:</strong> ' . esc_html($order->get_billing_email()) . '<br>
                        <strong>Zahlungsart:</strong> ' . esc_html($order->get_payment_method_title()) . '
                    </td>
                </tr>
            </table>';
        }

        // On-hold Info
        $hold_info = '';
        if ($type === 'on_hold') {
            $method = $order->get_payment_method();
            if ($method === 'bacs') {
                $hold_info = '
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
                    <tr>
                        <td style="background-color: #eff6ff; border-radius: ' . $radius . 'px; padding: 14px 16px; font-size: 13px; color: #1e40af;">
                            <strong>Bitte überweise den Betrag von ' . wp_strip_all_tags($order->get_formatted_order_total()) . '</strong><br>
                            Deine Tickets werden nach Zahlungseingang per E-Mail versendet.
                        </td>
                    </tr>
                </table>';
            }
        }

        // Zusammenbauen
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="de" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo esc_html($heading); ?></title>
    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <![endif]-->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style type="text/css">
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media only screen and (max-width: 620px) {
            .tix-email-container { width: 100% !important; padding: 12px !important; }
            .tix-email-inner { padding: 20px 16px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: <?php echo esc_attr($bg); ?>; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 14px; line-height: 1.6; color: <?php echo esc_attr($text_color); ?>;">

    <!-- Outer Container -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: <?php echo esc_attr($bg); ?>;">
        <tr>
            <td align="center" style="padding: 32px 16px;">

                <!-- Email Container -->
                <table role="presentation" class="tix-email-container" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; width: 100%;">

                    <!-- HEADER -->
                    <tr>
                        <td style="background-color: <?php echo esc_attr($accent); ?>; padding: 20px 32px; border-radius: <?php echo $radius; ?>px <?php echo $radius; ?>px 0 0; text-align: center;">
                            <?php if ($logo_url): ?>
                                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($brand_name); ?>" style="max-height: <?php echo $logo_height; ?>px; width: auto; display: inline-block;" />
                            <?php else: ?>
                                <span style="font-size: 18px; font-weight: 700; color: <?php echo esc_attr($accent_text); ?>; letter-spacing: 0.02em;"><?php echo esc_html($brand_name); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- BODY -->
                    <tr>
                        <td class="tix-email-inner" style="background-color: <?php echo esc_attr($card_bg); ?>; padding: 32px; border-left: 1px solid <?php echo esc_attr($divider); ?>; border-right: 1px solid <?php echo esc_attr($divider); ?>;">

                            <!-- Heading -->
                            <h1 style="margin: 0 0 4px; font-size: 22px; font-weight: 700; color: <?php echo esc_attr($text_color); ?>; line-height: 1.3;">
                                <?php echo esc_html($heading); ?>
                            </h1>
                            <?php if ($subtitle): ?>
                            <p style="margin: 0 0 24px; font-size: 13px; color: <?php echo esc_attr($muted); ?>;">
                                <?php echo $subtitle; ?>
                            </p>
                            <?php else: ?>
                            <div style="height: 20px;"></div>
                            <?php endif; ?>

                            <?php echo $admin_info; ?>
                            <?php echo $hold_info; ?>

                            <!-- Order Items -->
                            <?php echo $items_html; ?>

                            <!-- Kostensplit (Gruppenbestellung) -->
                            <?php
                            if (class_exists('TIX_Group_Booking') && in_array($type, ['order_confirmation', 'order_complete', 'admin_new_order', 'on_hold', 'invoice'])) {
                                echo TIX_Group_Booking::render_cost_split_html($order);
                            }
                            ?>

                            <!-- Tickets -->
                            <?php echo $tickets_html; ?>

                            <!-- Event Details (Erinnerung) -->
                            <?php echo $event_details_html; ?>

                            <!-- CTA -->
                            <?php echo $cta_html; ?>

                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td style="background-color: <?php echo esc_attr($card_bg); ?>; padding: 20px 32px; border-top: 1px solid <?php echo esc_attr($divider); ?>; border-left: 1px solid <?php echo esc_attr($divider); ?>; border-right: 1px solid <?php echo esc_attr($divider); ?>; border-bottom: 1px solid <?php echo esc_attr($divider); ?>; border-radius: 0 0 <?php echo $radius; ?>px <?php echo $radius; ?>px; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: <?php echo esc_attr($muted); ?>; line-height: 1.6;">
                                <?php if ($footer_text): ?>
                                    <?php echo wp_kses_post($footer_text); ?>
                                <?php else: ?>
                                    <?php echo esc_html($brand_name); ?><br>
                                    Du erh&auml;ltst diese E-Mail, weil du eine Bestellung aufgegeben hast.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════
    // Generisches Email-Template (kein Order)
    // ══════════════════════════════════════

    /**
     * HTML-Template ohne Order-Bezug.
     * Für WP Core-Mails, WC Account-Mails und sonstige Benachrichtigungen.
     */
    public static function build_generic_email_html($heading, $body_html, $subtitle = '', $footer_note = '') {
        $s = self::get_settings();

        $accent      = $s['color_accent']      ?: '#c8ff00';
        $accent_text = $s['color_accent_text'] ?: '#000000';
        $radius      = intval($s['radius_general'] ?: 8);
        $brand_name  = $s['email_brand_name']  ?: get_bloginfo('name');
        $logo_url    = $s['email_logo_url']    ?: '';
        $logo_height = intval($s['email_logo_height'] ?? 40) ?: 40;
        $footer_text = $footer_note ?: ($s['email_footer_text'] ?: '');

        $text_color  = '#1a1a1a';
        $muted       = '#6b7280';
        $bg          = '#f3f4f6';
        $card_bg     = '#ffffff';
        $divider     = '#e5e7eb';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="de" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo esc_html($heading); ?></title>
    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <![endif]-->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style type="text/css">
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media only screen and (max-width: 620px) {
            .tix-email-container { width: 100% !important; padding: 12px !important; }
            .tix-email-inner { padding: 20px 16px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: <?php echo esc_attr($bg); ?>; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 14px; line-height: 1.6; color: <?php echo esc_attr($text_color); ?>;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: <?php echo esc_attr($bg); ?>;">
        <tr>
            <td align="center" style="padding: 32px 16px;">

                <table role="presentation" class="tix-email-container" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; width: 100%;">

                    <!-- HEADER -->
                    <tr>
                        <td style="background-color: <?php echo esc_attr($accent); ?>; padding: 20px 32px; border-radius: <?php echo $radius; ?>px <?php echo $radius; ?>px 0 0; text-align: center;">
                            <?php if ($logo_url): ?>
                                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($brand_name); ?>" style="max-height: <?php echo $logo_height; ?>px; width: auto; display: inline-block;" />
                            <?php else: ?>
                                <span style="font-size: 18px; font-weight: 700; color: <?php echo esc_attr($accent_text); ?>; letter-spacing: 0.02em;"><?php echo esc_html($brand_name); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- BODY -->
                    <tr>
                        <td class="tix-email-inner" style="background-color: <?php echo esc_attr($card_bg); ?>; padding: 32px; border-left: 1px solid <?php echo esc_attr($divider); ?>; border-right: 1px solid <?php echo esc_attr($divider); ?>;">

                            <h1 style="margin: 0 0 4px; font-size: 22px; font-weight: 700; color: <?php echo esc_attr($text_color); ?>; line-height: 1.3;">
                                <?php echo esc_html($heading); ?>
                            </h1>
                            <?php if ($subtitle): ?>
                            <p style="margin: 0 0 24px; font-size: 13px; color: <?php echo esc_attr($muted); ?>;">
                                <?php echo esc_html($subtitle); ?>
                            </p>
                            <?php else: ?>
                            <div style="height: 20px;"></div>
                            <?php endif; ?>

                            <?php echo $body_html; ?>

                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td style="background-color: <?php echo esc_attr($card_bg); ?>; padding: 20px 32px; border-top: 1px solid <?php echo esc_attr($divider); ?>; border-left: 1px solid <?php echo esc_attr($divider); ?>; border-right: 1px solid <?php echo esc_attr($divider); ?>; border-bottom: 1px solid <?php echo esc_attr($divider); ?>; border-radius: 0 0 <?php echo $radius; ?>px <?php echo $radius; ?>px; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: <?php echo esc_attr($muted); ?>; line-height: 1.6;">
                                <?php if ($footer_text): ?>
                                    <?php echo wp_kses_post($footer_text); ?>
                                <?php else: ?>
                                    <?php echo esc_html($brand_name); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * WC Account-Mails (Password Reset, New Account)
     * Diese haben keinen Order, sondern einen WP_User als Objekt.
     */
    private static function wrap_wc_account_email($email, $type, $heading) {
        $s       = self::get_settings();
        $accent  = $s['color_accent']      ?: '#c8ff00';
        $a_text  = $s['color_accent_text'] ?: '#000000';
        $radius  = intval($s['radius_general'] ?: 8);

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

        $s       = self::get_settings();
        $accent  = $s['color_accent']      ?: '#c8ff00';
        $a_text  = $s['color_accent_text'] ?: '#000000';
        $radius  = intval($s['radius_general'] ?: 8);

        $body = '
        <p style="margin: 0 0 14px; font-size: 14px;">Jemand hat f&uuml;r den folgenden Account ein neues Passwort angefordert:</p>
        <p style="margin: 0 0 14px; font-size: 14px;">Benutzername: <strong>' . esc_html($user_login) . '</strong></p>
        <p style="margin: 0 0 14px; font-size: 14px;">Falls du dies nicht angefordert hast, kannst du diese E-Mail ignorieren und dein Passwort bleibt unver&auml;ndert.</p>
        <p style="margin: 0 0 20px; font-size: 14px;">Um dein Passwort zur&uuml;ckzusetzen, klicke auf den Button:</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 8px;">
            <tr><td align="center">
                <a href="' . esc_url($reset_url) . '" style="display:inline-block;padding:14px 36px;background-color:' . esc_attr($accent) . ';color:' . esc_attr($a_text) . ';text-decoration:none;font-weight:700;font-size:14px;border-radius:' . $radius . 'px;">Passwort zur&uuml;cksetzen</a>
            </td></tr>
        </table>';

        return self::build_generic_email_html(
            'Passwort zur&uuml;cksetzen',
            $body,
            '',
            'Du erh&auml;ltst diese E-Mail, weil eine Passwort-Zur&uuml;cksetzung f&uuml;r dein Konto angefordert wurde.'
        );
    }

    /**
     * Neuer Benutzer – Mail an den Benutzer (WP Core)
     */
    public static function wrap_new_user_email($email, $user, $blogname) {
        $s       = self::get_settings();
        $accent  = $s['color_accent']      ?: '#c8ff00';
        $a_text  = $s['color_accent_text'] ?: '#000000';
        $radius  = intval($s['radius_general'] ?: 8);

        // Set-Password URL aus der Original-Nachricht extrahieren
        $set_pw_url = '';
        if (preg_match('|(https?://\S+)|', $email['message'], $m)) {
            $set_pw_url = $m[1];
        }

        $body = '
        <p style="margin: 0 0 14px; font-size: 14px;">Willkommen bei <strong>' . esc_html($blogname) . '</strong>!</p>
        <p style="margin: 0 0 14px; font-size: 14px;">Dein Benutzername: <strong>' . esc_html($user->user_login) . '</strong></p>';

        if ($set_pw_url) {
            $body .= '
            <p style="margin: 0 0 20px; font-size: 14px;">Bitte klicke auf den Button, um dein Passwort festzulegen:</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 8px;">
                <tr><td align="center">
                    <a href="' . esc_url($set_pw_url) . '" style="display:inline-block;padding:14px 36px;background-color:' . esc_attr($accent) . ';color:' . esc_attr($a_text) . ';text-decoration:none;font-weight:700;font-size:14px;border-radius:' . $radius . 'px;">Passwort festlegen</a>
                </td></tr>
            </table>';
        }

        $email['message'] = self::build_generic_email_html(
            'Willkommen!',
            $body,
            'Dein Konto wurde erstellt.',
            'Du erh&auml;ltst diese E-Mail, weil ein Konto f&uuml;r dich erstellt wurde.'
        );
        $email['headers'] = self::ensure_headers_html($email['headers'] ?? '');

        return $email;
    }

    /**
     * Neuer Benutzer – Admin-Benachrichtigung (WP Core)
     */
    public static function wrap_new_user_admin_email($email, $user, $blogname) {
        $body = '
        <p style="margin: 0 0 14px; font-size: 14px;">Ein neuer Benutzer hat sich auf <strong>' . esc_html($blogname) . '</strong> registriert:</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 16px; border: 1px solid #e5e7eb; border-radius: 8px;">
            <tr>
                <td style="padding: 14px 16px; font-size: 14px;">
                    <strong>Benutzername:</strong> ' . esc_html($user->user_login) . '<br>
                    <strong>E-Mail:</strong> ' . esc_html($user->user_email) . '
                </td>
            </tr>
        </table>';

        $email['message'] = self::build_generic_email_html(
            'Neuer Benutzer',
            $body,
            'Registrierung auf ' . esc_html($blogname),
            'Admin-Benachrichtigung von ' . esc_html($blogname)
        );
        $email['headers'] = self::ensure_headers_html($email['headers'] ?? '');

        return $email;
    }

    /**
     * Passwort ge&auml;ndert (WP Core)
     */
    public static function wrap_password_change_email($email, $user, $userdata) {
        $body = '
        <p style="margin: 0 0 14px; font-size: 14px;">Diese Nachricht best&auml;tigt, dass dein Passwort auf <strong>' . esc_html(get_bloginfo('name')) . '</strong> ge&auml;ndert wurde.</p>
        <p style="margin: 0 0 14px; font-size: 14px;">Benutzername: <strong>' . esc_html($user['user_login']) . '</strong></p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 8px;">
            <tr><td style="background-color: #fef3c7; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400e;">
                Falls du diese &Auml;nderung nicht vorgenommen hast, kontaktiere bitte umgehend den Support.
            </td></tr>
        </table>';

        $email['message'] = self::build_generic_email_html(
            'Passwort ge&auml;ndert',
            $body,
            '',
            'Sicherheitsbenachrichtigung von ' . esc_html(get_bloginfo('name'))
        );
        $email['headers'] = self::ensure_headers_html($email['headers'] ?? '');

        return $email;
    }

    /**
     * E-Mail-Adresse ge&auml;ndert (WP Core)
     */
    public static function wrap_email_change_email($email, $user, $userdata) {
        $new_email = $userdata['user_email'] ?? '';

        $body = '
        <p style="margin: 0 0 14px; font-size: 14px;">Diese Nachricht best&auml;tigt, dass die E-Mail-Adresse deines Kontos auf <strong>' . esc_html(get_bloginfo('name')) . '</strong> ge&auml;ndert wurde.</p>
        <p style="margin: 0 0 14px; font-size: 14px;">Benutzername: <strong>' . esc_html($user['user_login']) . '</strong></p>';

        if ($new_email) {
            $body .= '<p style="margin: 0 0 14px; font-size: 14px;">Neue E-Mail: <strong>' . esc_html($new_email) . '</strong></p>';
        }

        $body .= '
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 8px;">
            <tr><td style="background-color: #fef3c7; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400e;">
                Falls du diese &Auml;nderung nicht vorgenommen hast, kontaktiere bitte umgehend den Support.
            </td></tr>
        </table>';

        $email['message'] = self::build_generic_email_html(
            'E-Mail-Adresse ge&auml;ndert',
            $body,
            '',
            'Sicherheitsbenachrichtigung von ' . esc_html(get_bloginfo('name'))
        );
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
    // Content-Blöcke
    // ══════════════════════════════════════

    /**
     * Bestellpositionen als HTML-Tabelle
     */
    private static function render_order_items_html($order, $accent, $text_color, $muted, $divider, $radius) {
        $items = $order->get_items();
        if (empty($items)) return '';

        $html = '
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
            <tr>
                <td style="padding-bottom: 10px;">
                    <strong style="font-size: 15px; color: ' . esc_attr($text_color) . ';">Bestellübersicht</strong>
                </td>
            </tr>
            <tr>
                <td>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid ' . esc_attr($divider) . '; border-radius: ' . $radius . 'px; overflow: hidden;">';

        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $event_id   = get_post_meta($product_id, '_tix_parent_event_id', true);

            $event_name = '';
            $event_date = '';
            $event_location = '';
            if ($event_id) {
                $event_post = get_post($event_id);
                if ($event_post) $event_name = $event_post->post_title;
                $date_start = get_post_meta($event_id, '_tix_date_start', true);
                if ($date_start) $event_date = date_i18n('D, d. M Y', strtotime($date_start));
                $event_location = get_post_meta($event_id, '_tix_location', true);
            }

            $html .= '
                        <tr>
                            <td style="padding: 12px 16px; border-bottom: 1px solid ' . esc_attr($divider) . ';">
                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="vertical-align: top;">';

            if ($event_name) {
                $html .= '          <span style="font-size: 11px; font-weight: 600; color: ' . esc_attr($muted) . '; text-transform: uppercase; letter-spacing: 0.04em;">' . esc_html($event_name) . '</span><br>';
            }

            $html .= '                 <strong style="font-size: 14px; color: ' . esc_attr($text_color) . ';">' . esc_html($item->get_name()) . '</strong>';

            if ($event_date || $event_location) {
                $meta_parts = [];
                if ($event_date) $meta_parts[] = $event_date;
                if ($event_location) $meta_parts[] = $event_location;
                $html .= '<br><span style="font-size: 12px; color: ' . esc_attr($muted) . ';">' . esc_html(implode(' &middot; ', $meta_parts)) . '</span>';
            }

            $html .= '
                                        </td>
                                        <td style="vertical-align: top; text-align: right; white-space: nowrap; padding-left: 16px;">
                                            <span style="font-size: 12px; color: ' . esc_attr($muted) . ';">&times; ' . $item->get_quantity() . '</span><br>
                                            <strong style="font-size: 14px; color: ' . esc_attr($text_color) . ';">' . wp_strip_all_tags(wc_price($item->get_total())) . '</strong>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>';
        }

        // Totals
        $html .= '
                        <tr>
                            <td style="padding: 12px 16px; background-color: #f9fafb;">';

        // Subtotal
        $html .= '         <table width="100%" cellpadding="0" cellspacing="0" border="0">';

        if ($order->get_total_discount() > 0) {
            $html .= '
                            <tr>
                                <td style="font-size: 13px; color: ' . esc_attr($muted) . '; padding: 2px 0;">Zwischensumme</td>
                                <td style="font-size: 13px; color: ' . esc_attr($text_color) . '; text-align: right; padding: 2px 0;">' . wp_strip_all_tags(wc_price($order->get_subtotal())) . '</td>
                            </tr>
                            <tr>
                                <td style="font-size: 13px; color: #059669; padding: 2px 0;">Rabatt</td>
                                <td style="font-size: 13px; color: #059669; text-align: right; padding: 2px 0;">-' . wp_strip_all_tags(wc_price($order->get_total_discount())) . '</td>
                            </tr>';
        }

        // Fees
        foreach ($order->get_fees() as $fee) {
            $fee_total = floatval($fee->get_total());
            $fee_color = $fee_total < 0 ? '#059669' : $muted;
            $html .= '
                            <tr>
                                <td style="font-size: 13px; color: ' . esc_attr($fee_color) . '; padding: 2px 0;">' . esc_html($fee->get_name()) . '</td>
                                <td style="font-size: 13px; color: ' . esc_attr($fee_color) . '; text-align: right; padding: 2px 0;">' . wp_strip_all_tags(wc_price($fee_total)) . '</td>
                            </tr>';
        }

        // Tax
        if ($order->get_total_tax() > 0) {
            $html .= '
                            <tr>
                                <td style="font-size: 12px; color: ' . esc_attr($muted) . '; padding: 2px 0;">inkl. MwSt.</td>
                                <td style="font-size: 12px; color: ' . esc_attr($muted) . '; text-align: right; padding: 2px 0;">' . wp_strip_all_tags(wc_price($order->get_total_tax())) . '</td>
                            </tr>';
        }

        // Total
        $html .= '
                            <tr>
                                <td style="font-size: 16px; font-weight: 700; color: ' . esc_attr($text_color) . '; padding: 8px 0 0; border-top: 1px solid ' . esc_attr($divider) . ';">Gesamt</td>
                                <td style="font-size: 16px; font-weight: 700; color: ' . esc_attr($text_color) . '; text-align: right; padding: 8px 0 0; border-top: 1px solid ' . esc_attr($divider) . ';">' . wp_strip_all_tags($order->get_formatted_order_total()) . '</td>
                            </tr>
                        </table>
                            </td>
                        </tr>';

        $html .= '
                    </table>
                </td>
            </tr>
        </table>';

        return $html;
    }

    /**
     * Tickets mit Download-Links
     */
    private static function render_tickets_html($order, $accent, $accent_text, $text_color, $muted, $radius) {
        $tickets = self::get_ticket_data($order);
        if (empty($tickets)) return '';

        $html = '
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 8px;">
            <tr>
                <td style="padding-bottom: 10px;">
                    <strong style="font-size: 15px; color: ' . esc_attr($text_color) . ';">Deine Tickets</strong>
                </td>
            </tr>';

        foreach ($tickets as $t) {
            $html .= '
            <tr>
                <td style="padding-bottom: 8px;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: ' . $radius . 'px; overflow: hidden;">
                        <tr>
                            <td style="padding: 14px 16px;">
                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="vertical-align: middle;">';

            if ($t['event_name']) {
                $html .= '                 <span style="font-size: 11px; font-weight: 600; color: ' . esc_attr($muted) . '; text-transform: uppercase; letter-spacing: 0.04em;">' . esc_html($t['event_name']) . '</span><br>';
            }

            $html .= '                     <strong style="font-size: 14px; color: ' . esc_attr($text_color) . ';">' . esc_html($t['type_name'] ?: 'Ticket') . '</strong>';

            if ($t['code']) {
                $html .= '<br><span style="font-size: 11px; font-family: monospace; color: ' . esc_attr($muted) . ';">' . esc_html($t['code']) . '</span>';
            }

            $html .= '
                                        </td>
                                        <td style="vertical-align: middle; text-align: right; padding-left: 12px; white-space: nowrap;">';

            if ($t['download_url']) {
                $html .= '                 <a href="' . esc_url($t['download_url']) . '" style="display: inline-block; padding: 8px 18px; background-color: ' . esc_attr($accent) . '; color: ' . esc_attr($accent_text) . '; text-decoration: none; font-weight: 600; font-size: 12px; border-radius: ' . $radius . 'px;">&#8595; Download</a>';
            } else {
                $html .= '                 <span style="font-size: 12px; color: ' . esc_attr($muted) . '; font-style: italic;">Wird bereitgestellt&hellip;</span>';
            }

            $html .= '
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Event-Details HTML (für Erinnerungsmail)
     */
    private static function render_event_details_html($event_id, $accent, $text_color, $muted, $divider, $radius) {
        $event = get_post($event_id);
        if (!$event) return '';

        $date_start = get_post_meta($event_id, '_tix_date_start', true);
        $time_start = get_post_meta($event_id, '_tix_time_start', true);
        $time_doors = get_post_meta($event_id, '_tix_time_doors', true);
        $location   = get_post_meta($event_id, '_tix_location', true);
        $address    = get_post_meta($event_id, '_tix_address', true);

        $html = '
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
            <tr>
                <td style="padding-bottom: 10px;">
                    <strong style="font-size: 15px; color: ' . esc_attr($text_color) . ';">Event-Details</strong>
                </td>
            </tr>
            <tr>
                <td>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid ' . esc_attr($divider) . '; border-radius: ' . $radius . 'px; overflow: hidden;">
                        <tr>
                            <td style="padding: 16px 20px;">
                                <h2 style="margin: 0 0 12px; font-size: 18px; font-weight: 700; color: ' . esc_attr($text_color) . ';">' . esc_html($event->post_title) . '</h2>';

        $details = [];
        if ($date_start) {
            $details[] = '<tr><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($muted) . '; width: 90px; vertical-align: top;">Datum</td><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($text_color) . '; font-weight: 600;">' . date_i18n('l, d. F Y', strtotime($date_start)) . '</td></tr>';
        }
        if ($time_doors) {
            $details[] = '<tr><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($muted) . '; vertical-align: top;">Einlass</td><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($text_color) . ';">' . date_i18n('H:i', strtotime($time_doors)) . ' Uhr</td></tr>';
        }
        if ($time_start) {
            $details[] = '<tr><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($muted) . '; vertical-align: top;">Beginn</td><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($text_color) . '; font-weight: 600;">' . date_i18n('H:i', strtotime($time_start)) . ' Uhr</td></tr>';
        }
        if ($location) {
            $details[] = '<tr><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($muted) . '; vertical-align: top;">Ort</td><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($text_color) . '; font-weight: 600;">' . esc_html($location) . '</td></tr>';
        }
        if ($address) {
            $maps_url = 'https://www.google.com/maps/search/' . urlencode($address);
            $details[] = '<tr><td style="padding: 4px 0; font-size: 13px; color: ' . esc_attr($muted) . '; vertical-align: top;">Adresse</td><td style="padding: 4px 0; font-size: 13px;"><a href="' . esc_url($maps_url) . '" style="color: ' . esc_attr($accent) . '; text-decoration: none;">' . esc_html($address) . '</a></td></tr>';
        }

        if ($details) {
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0">' . implode('', $details) . '</table>';
        }

        $html .= '
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';

        return $html;
    }

    // ══════════════════════════════════════
    // Ticket Helper
    // ══════════════════════════════════════

    /**
     * Tickets für eine Bestellung laden
     * (Identische Logik wie class-tix-checkout.php render_thankyou)
     */
    private static function get_ticket_data($order) {
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
        $order = wc_get_order($order_id);
        $event = get_post($event_id);
        if (!$order || !$event) return;

        // Prüfen ob Order noch gültig
        if (!in_array($order->get_status(), ['completed', 'processing'])) return;

        $s = self::get_settings();
        $accent      = $s['color_accent']      ?: '#c8ff00';
        $accent_text = $s['color_accent_text'] ?: '#000000';
        $text_color  = '#1a1a1a';
        $muted       = '#6b7280';
        $divider     = '#e5e7eb';
        $radius      = intval($s['radius_general'] ?: 8);

        // Event-Details
        $event_details = self::render_event_details_html($event_id, $accent, $text_color, $muted, $divider, $radius);

        // Tickets für diesen Order
        $tickets_html = self::render_tickets_html($order, $accent, $accent_text, $text_color, $muted, $radius);

        $extra = $event_details . $tickets_html;

        $html = self::build_email_html('reminder', $order, 'Morgen ist es soweit!', $extra);

        $to      = $order->get_billing_email();
        $subject = 'Erinnerung: ' . $event->post_title . ' ist morgen!';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if (function_exists('WC') && WC()->mailer()) {
            WC()->mailer()->send($to, $subject, $html, $headers);
        } else {
            wp_mail($to, $subject, $html, $headers);
        }
    }

    /**
     * Nachbefragungsmail senden (24h nach Event)
     */
    public static function send_followup($order_id, $event_id) {
        $order = wc_get_order($order_id);
        $event = get_post($event_id);
        if (!$order || !$event) return;

        if (!in_array($order->get_status(), ['completed', 'processing'])) return;

        $s = self::get_settings();
        $accent      = $s['color_accent']      ?: '#c8ff00';
        $accent_text = $s['color_accent_text'] ?: '#000000';
        $text_color  = '#1a1a1a';
        $muted       = '#6b7280';
        $radius      = intval($s['radius_general'] ?: 8);
        $brand_name  = $s['email_brand_name']  ?: get_bloginfo('name');
        $followup_url = $s['email_followup_url'] ?: '';

        $extra = '
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
            <tr>
                <td style="font-size: 14px; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
                    <p style="margin: 0 0 12px;">Wir hoffen, du hattest eine tolle Zeit bei <strong>' . esc_html($event->post_title) . '</strong>!</p>
                    <p style="margin: 0 0 12px;">Vielen Dank f&uuml;r deinen Besuch. Wir freuen uns, dich beim n&auml;chsten Event wiederzusehen.</p>
                </td>
            </tr>
        </table>';

        if ($followup_url) {
            $extra .= '
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 8px;">
                <tr>
                    <td align="center">
                        <a href="' . esc_url($followup_url) . '" style="display: inline-block; padding: 12px 32px; background-color: ' . esc_attr($accent) . '; color: ' . esc_attr($accent_text) . '; text-decoration: none; font-weight: 700; font-size: 14px; border-radius: ' . $radius . 'px;">
                            Feedback geben
                        </a>
                    </td>
                </tr>
            </table>';
        }

        // ── Feedback-Sterne (wenn Feedback-System aktiviert) ──
        if (!empty($s['feedback_enabled']) && class_exists('TIX_Feedback')) {
            $fb_email = $order->get_billing_email();
            $extra .= TIX_Feedback::get_email_stars_html($order_id, $event_id, $fb_email);
        }

        $html = self::build_email_html('followup', $order, 'Danke für deinen Besuch!', $extra);

        $to      = $order->get_billing_email();
        $subject = 'Danke für deinen Besuch bei ' . $event->post_title . '!';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if (function_exists('WC') && WC()->mailer()) {
            WC()->mailer()->send($to, $subject, $html, $headers);
        } else {
            wp_mail($to, $subject, $html, $headers);
        }
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

        $s = self::get_settings();
        $accent      = $s['color_accent']      ?: '#c8ff00';
        $accent_text = $s['color_accent_text'] ?: '#000000';
        $border      = $s['color_border']       ?: '#333333';
        $radius      = intval($s['radius_general'] ?: 8);
        $brand_name  = $s['email_brand_name']  ?: get_bloginfo('name');
        $logo_url    = $s['email_logo_url']    ?: '';
        $footer_text = $s['email_footer_text'] ?: '';

        // Event-Daten
        $date_start = get_post_meta($event_id, '_tix_date_start', true);
        $time_doors = get_post_meta($event_id, '_tix_time_doors', true);
        $time_start = get_post_meta($event_id, '_tix_time_start', true);
        $location   = get_post_meta($event_id, '_tix_location', true);
        $date_fmt   = $date_start ? date_i18n('l, d. F Y', strtotime($date_start)) : '';

        // QR-Code
        $qr_code = 'GL-' . $event_id . '-' . $guest_id;
        $qr_url  = home_url('?tix_guest=' . urlencode($qr_code));
        $qr_img  = 'https://quickchart.io/qr?text=' . urlencode($qr_code) . '&size=200&margin=2';

        // Plus-Info
        $plus_text = '';
        if (!empty($guest['plus']) && $guest['plus'] > 0) {
            $plus_text = '+' . intval($guest['plus']) . ' Begleitung';
        }

        // HTML
        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"></head><body style="margin:0;padding:0;background:#f3f4f6;font-family:\'Inter\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';

        // Container
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;"><tr><td align="center" style="padding:32px 16px;">';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;background:#ffffff;border-radius:' . $radius . 'px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">';

        // Header
        $html .= '<tr><td style="background:#1a1a1a;padding:24px 32px;text-align:center;">';
        if ($logo_url) {
            $html .= '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($brand_name) . '" style="max-width:180px;max-height:48px;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">';
        }
        $html .= '<div style="color:#ffffff;font-size:20px;font-weight:700;margin-bottom:4px;">' . esc_html($event->post_title) . '</div>';
        $html .= '<div style="color:#94a3b8;font-size:14px;">Gästeliste</div>';
        $html .= '</td></tr>';

        // Body
        $html .= '<tr><td style="padding:32px;">';

        // Begrüßung
        $html .= '<div style="font-size:16px;font-weight:600;color:#1a1a1a;margin-bottom:4px;">Hallo ' . esc_html($guest['name']) . ',</div>';
        $html .= '<div style="font-size:14px;color:#6b7280;margin-bottom:24px;">du stehst auf der Gästeliste! Zeige diesen QR-Code am Einlass vor.</div>';

        // QR-Code
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:16px 0;">';
        $html .= '<div style="display:inline-block;background:#ffffff;border:2px solid ' . esc_attr($border) . ';border-radius:' . $radius . 'px;padding:16px;">';
        $html .= '<img src="' . esc_url($qr_img) . '" width="200" height="200" alt="QR-Code" style="display:block;">';
        $html .= '</div>';
        $html .= '</td></tr></table>';

        // Code
        $html .= '<div style="text-align:center;font-family:monospace;font-size:13px;color:#94a3b8;letter-spacing:1px;margin:8px 0 24px;">' . esc_html($qr_code) . '</div>';

        // Event-Details
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;padding-top:16px;margin-top:0;">';
        if ($date_fmt) {
            $html .= '<tr><td style="padding:4px 0;font-size:13px;color:#6b7280;width:30px;">📅</td><td style="padding:4px 0;font-size:13px;color:#1a1a1a;">' . esc_html($date_fmt) . '</td></tr>';
        }
        if ($time_doors) {
            $html .= '<tr><td style="padding:4px 0;font-size:13px;color:#6b7280;">🚪</td><td style="padding:4px 0;font-size:13px;color:#1a1a1a;">Einlass: ' . esc_html($time_doors) . ' Uhr</td></tr>';
        }
        if ($time_start) {
            $html .= '<tr><td style="padding:4px 0;font-size:13px;color:#6b7280;">🎵</td><td style="padding:4px 0;font-size:13px;color:#1a1a1a;">Start: ' . esc_html($time_start) . ' Uhr</td></tr>';
        }
        if ($location) {
            $html .= '<tr><td style="padding:4px 0;font-size:13px;color:#6b7280;">📍</td><td style="padding:4px 0;font-size:13px;color:#1a1a1a;">' . esc_html($location) . '</td></tr>';
        }
        if ($plus_text) {
            $html .= '<tr><td style="padding:4px 0;font-size:13px;color:#6b7280;">👥</td><td style="padding:4px 0;font-size:13px;color:#1a1a1a;">' . esc_html($plus_text) . '</td></tr>';
        }
        $html .= '</table>';

        // CTA
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;"><tr><td align="center">';
        $html .= '<a href="' . esc_url($qr_url) . '" style="display:inline-block;padding:12px 32px;background-color:' . esc_attr($accent) . ';color:' . esc_attr($accent_text) . ';text-decoration:none;font-weight:700;font-size:14px;border-radius:' . $radius . 'px;">QR-Code öffnen</a>';
        $html .= '</td></tr></table>';

        $html .= '</td></tr>';

        // Footer
        $html .= '<tr><td style="background:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;">';
        if ($footer_text) {
            $html .= '<div style="font-size:12px;color:#9ca3af;">' . wp_kses_post($footer_text) . '</div>';
        } else {
            $html .= '<div style="font-size:12px;color:#9ca3af;">' . esc_html($brand_name) . '</div>';
        }
        $html .= '</td></tr>';

        $html .= '</table></td></tr></table></body></html>';

        $subject = 'Gästeliste: ' . $event->post_title;
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        return wp_mail($guest['email'], $subject, $html, $headers);
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
        $items_html = '';
        if (is_array($cart_items) && !empty($cart_items)) {
            $items_html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 16px 0;">';
            foreach ($cart_items as $item) {
                $name = esc_html($item['name'] ?? 'Ticket');
                $qty  = intval($item['qty'] ?? 1);
                $items_html .= '<tr><td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb;">' . $name . '</td>';
                $items_html .= '<td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb; text-align: right; white-space: nowrap;">' . $qty . '&times;</td></tr>';
            }
            $items_html .= '</table>';
        }

        // Recovery-URL
        $recovery_url = add_query_arg('tix_recover_cart', $token, wc_get_checkout_url());

        $s = self::get_settings();
        $accent      = $s['color_accent']      ?: '#c8ff00';
        $accent_text = $s['color_accent_text'] ?: '#000000';
        $radius      = intval($s['radius_general'] ?: 8);

        // CTA-Button
        $cta_html = '<div style="text-align: center; margin: 24px 0;">
            <a href="' . esc_url($recovery_url) . '" style="display: inline-block; padding: 14px 32px; background: ' . esc_attr($accent) . '; color: ' . esc_attr($accent_text) . '; text-decoration: none; font-weight: 600; border-radius: ' . $radius . 'px; font-size: 16px;">Warenkorb wiederherstellen</a>
        </div>';

        $body_html  = '<p>Du hast noch Tickets in deinem Warenkorb' . ($event_title ? ' für <strong>' . esc_html($event_title) . '</strong>' : '') . '.</p>';
        $body_html .= $items_html;
        $body_html .= $cta_html;
        $body_html .= '<p style="font-size: 13px; color: #6b7280;">Dieser Link ist 48 Stunden gültig.</p>';

        // Heading
        $heading = 'Du hast noch Tickets im Warenkorb';

        // Betreff
        $subject = $s['abandoned_cart_subject'] ?? '';
        if (empty($subject)) {
            $subject = $event_title
                ? 'Du hast noch Tickets für ' . $event_title . ' im Warenkorb'
                : 'Du hast noch Tickets im Warenkorb';
        }

        $html = self::build_generic_email_html(
            $heading,
            $body_html,
            $event_title ? 'Für ' . $event_title : '',
            'Automatisch generierte Erinnerung'
        );

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if (function_exists('WC') && WC()->mailer()) {
            WC()->mailer()->send($email, $subject, $html, $headers);
        } else {
            wp_mail($email, $subject, $html, $headers);
        }

        // Status aktualisieren
        update_post_meta($ac_post_id, '_tix_ac_status', 'sent');
        update_post_meta($ac_post_id, '_tix_ac_sent_at', current_time('c'));
    }
}
