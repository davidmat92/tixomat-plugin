<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Giftcard_Gift – Gutschein als Geschenk (1.38.463, User: „Beim Gutschein muss klar sein, dass man diesen verschenkt …
 * am Ende ein Code in einer PDF oder als Bild, den man verschicken kann“).
 *
 *  - Geschenk-Angaben je Gutschein-Position: gift {recipient_name, message (≤ 200), from_name} in POST /customer/cart/quote und
 *    /customer/orders (App, evendis.de) bzw. Felder in der Website-Kasse. Gespeichert an der Bestellposition (meta.gift_info)
 *    und am Gutschein (tix_coupons[code].gift), ausgegeben als gift_recipient / gift_message / gift_from.
 *  - Gutschein-PDF (A4, CI der Seite: Logo, Akzentfarbe; Betrag, „Gutschein für …“, Nachricht, Code, QR lokal, gültig bis,
 *    Einlöse-Hinweis): GET /customer/giftcards/{code}/pdf → {ok, code, filename, mime, data (Base64)}; Zugriff mit Konto
 *    (Käufer oder Inhaber des Gutschein-Tickets) oder ?key=<Bestell-Key> (Gäste).
 *  - Bestätigungs-Mail: Geschenk-Block mit Code + PDF-Anhang (je Gutschein eins); entstehen die Codes erst danach, kommt eine
 *    eigene Mail „Dein Geschenkgutschein“.
 *  - Einstellungen fürs Studio: GET/POST /giftcards/admin/config (Beträge, Wunschbetrag, Gültigkeit, an/aus; Mehr-Veranstalter
 *    je Veranstalter), Rechte wie /wallet/admin/config.
 */
class TIX_Giftcard_Gift {

    const NS = 'tixomat/v1';
    const MSG_MAX = 200;
    const NAME_MAX = 80;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/customer/giftcards/(?P<code>[A-Za-z0-9-]{4,40})/pdf', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_pdf'], 'permission_callback' => '__return_true', // Konto oder ?key=
        ]);
        if (class_exists('TIX_Wallet_Balance')) {
            // /giftcards/admin (Studio, 1.38.464, mit stats + recent); /giftcards/admin/config wie 1.38.463
            foreach (['/giftcards/admin', '/giftcards/admin/config'] as $path) {
                register_rest_route(self::NS, $path, [
                    ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin_config'], 'permission_callback' => ['TIX_Wallet_Balance', 'check_staff']],
                    ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_admin_save'], 'permission_callback' => ['TIX_Wallet_Balance', 'check_manager']],
                ]);
            }
        }
    }

    // ═══════════════ Geschenk-Angaben ═══════════════

    /** gift-Rohwert prüfen → null (keine Angaben) | array | WP_Error (field gift.*). */
    public static function sanitize($raw, $field = 'gift') {
        if ($raw === null || $raw === '' || $raw === false) return null;
        if (!is_array($raw)) return new WP_Error('tix_invalid_field', 'Geschenk-Angaben bitte als Objekt {recipient_name, message, from_name}.', ['status' => 400, 'field' => $field]);
        $clean = function ($v) { return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $v))); };
        $out = [
            'recipient_name' => $clean($raw['recipient_name'] ?? ''),
            'message'        => trim(sanitize_textarea_field((string) ($raw['message'] ?? ''))),
            'from_name'      => $clean($raw['from_name'] ?? ''),
        ];
        foreach (['recipient_name', 'from_name'] as $k) {
            if (mb_strlen($out[$k]) > self::NAME_MAX) return new WP_Error('tix_invalid_field', sprintf('Bitte höchstens %d Zeichen.', self::NAME_MAX), ['status' => 400, 'field' => $field . '.' . $k]);
        }
        if (mb_strlen($out['message']) > self::MSG_MAX) return new WP_Error('tix_invalid_field', sprintf('Die Nachricht darf höchstens %d Zeichen haben.', self::MSG_MAX), ['status' => 400, 'field' => $field . '.message']);
        $out['message'] = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $out['message']));
        return ($out['recipient_name'] === '' && $out['from_name'] === '' && $out['message'] === '') ? null : $out;
    }

    /**
     * Antwortfelder eines Gutscheins (verbindlicher Vertrag 1.38.464): gift_recipient_name, gift_message, gift_from_name,
     * gift_organizer_id (Einzel-Seite 0), gift_organizer_name (Einzel-Seite: Seitenname), gift_pdf (URL, '' = keins).
     * Zusätzlich die Namen aus 1.38.463 (gift_recipient, gift_from) – nur noch zur Verträglichkeit.
     */
    public static function fields($card) {
        $g = is_array($card['gift'] ?? null) ? $card['gift'] : [];
        $oid = intval($card['organizer_id'] ?? 0);
        $rec = ($g['recipient_name'] ?? '') !== '' ? (string) $g['recipient_name'] : null;
        $from = ($g['from_name'] ?? '') !== '' ? (string) $g['from_name'] : null;
        $pdf_ok = !empty($card['code']) && function_exists('imagecreatetruecolor') && class_exists('TIX_Ticket_Template');
        return [
            'gift_recipient_name' => $rec,
            'gift_message'        => ($g['message'] ?? '') !== '' ? (string) $g['message'] : null,
            'gift_from_name'      => $from,
            'gift_organizer_id'   => $oid,
            'gift_organizer_name' => $oid ? html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8') : html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8'),
            'gift_pdf'            => $pdf_ok ? rest_url(self::NS . '/customer/giftcards/' . rawurlencode((string) $card['code']) . '/pdf') : '',
            'gift_recipient'      => $rec,
            'gift_from'           => $from,
        ];
    }

    /** Gutschein-Codes einer Bestellung (erst erzeugen, falls noch nicht geschehen). */
    public static function codes_for_order($order_id) {
        if (!class_exists('TIX_Giftcards')) return [];
        TIX_Giftcards::create_for_order($order_id);
        wp_cache_delete('tix_coupons', 'options');
        $out = [];
        foreach ((array) get_option('tix_coupons', []) as $k => $c) {
            if (($c['discount_type'] ?? '') === 'giftcard' && intval($c['order_id'] ?? 0) === intval($order_id)) $out[] = (string) $k;
        }
        return $out;
    }

    // ═══════════════ PDF ═══════════════

    private static function hex($c, $fallback) {
        $c = ltrim((string) $c, '#');
        return preg_match('/^[0-9a-f]{6}$/i', $c) ? strtoupper($c) : $fallback;
    }

    private static function lum($c) {
        $f = function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); };
        return 0.2126 * $f(hexdec(substr($c, 0, 2))) + 0.7152 * $f(hexdec(substr($c, 2, 2))) + 0.0722 * $f(hexdec(substr($c, 4, 2)));
    }

    /** Logo der Seite als GD-Bild (Studio-Marke, Theme-Logo), nur lokale Dateien; null wenn keins. */
    private static function logo_image() {
        $ids = [];
        $b = get_option('tix_studio_brand', []);
        $ids[] = is_array($b) ? intval($b['logo_id'] ?? 0) : 0;
        $ids[] = intval(get_theme_mod('custom_logo'));
        $ids[] = intval(get_option('site_icon'));
        foreach (array_filter($ids) as $id) {
            $file = get_attached_file($id);
            if (!$file || !is_readable($file) || filesize($file) > 8 * MB_IN_BYTES) continue;
            if (!preg_match('/\.(png|jpe?g|webp|gif)$/i', $file)) continue;
            $im = @imagecreatefromstring((string) file_get_contents($file));
            if ($im) return $im;
        }
        return null;
    }

    private static function eur($v) {
        $s = number_format(floatval($v), 2, ',', '.');
        return (substr($s, -3) === ',00' ? substr($s, 0, -3) : $s) . ' €';
    }

    /** PDF-Bytes eines Gutscheins oder null. */
    public static function pdf($code) {
        if (!class_exists('TIX_Giftcards') || !class_exists('TIX_Ticket_Template') || !function_exists('imagecreatetruecolor')) return null;
        $card = TIX_Giftcards::get_card((string) $code);
        if (!$card) return null;
        $code = (string) $card['code'];
        $gift = self::fields($card);
        $w = 1240;
        $h = 1754;
        $img = imagecreatetruecolor($w, $h);
        if (!$img) return null;
        $rgb = function ($c) use ($img) { return imagecolorallocate($img, hexdec(substr($c, 0, 2)), hexdec(substr($c, 2, 2)), hexdec(substr($c, 4, 2))); };
        $accent = self::hex(class_exists('TIX_Studio') && method_exists('TIX_Studio', 'accent') ? TIX_Studio::accent() : (function_exists('tix_get_settings') ? tix_get_settings('color_accent') : ''), 'FC2F55');
        // Kopf in der Akzentfarbe nur, wenn weiße Schrift darauf lesbar ist; sonst dunkel mit Akzent-Linie
        $head = self::lum($accent) < 0.3 ? $accent : '2C2F3F';
        imagefilledrectangle($img, 0, 0, $w, $h, $rgb('FFFFFF'));
        imagefilledrectangle($img, 0, 0, $w, 300, $rgb($head));
        imagefilledrectangle($img, 0, 300, $w, 312, $rgb($accent));
        $text = function ($str, $x, $y, $width, $size, $bold = false, $color = '2C2F3F', $align = 'left', $lh = 1.6) use ($img) {
            if ((string) $str === '') return;
            TIX_Ticket_Template::render_text_field($img, ['enabled' => true, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => intval($size * 4),
                'font_size' => $size, 'font_family' => 'sans-serif', 'font_weight' => $bold ? 'bold' : 'normal', 'color' => '#' . $color, 'alignment' => $align, 'line_height' => $lh], (string) $str);
        };
        $m = 100;
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $logo = self::logo_image();
        $tx = $m;
        if ($logo) {
            $lw = imagesx($logo); $lh = imagesy($logo);
            $th = 150; $tw = intval($lw * $th / max(1, $lh));
            if ($tw > 420) { $tw = 420; $th = intval($lh * $tw / max(1, $lw)); }
            // weiße Fläche hinter dem Logo, damit dunkle Logos lesbar bleiben
            imagefilledrectangle($img, $m - 16, 75 - 16 + intval((150 - $th) / 2), $m + $tw + 16, 75 + 16 + intval((150 - $th) / 2) + $th, $rgb('FFFFFF'));
            imagecopyresampled($img, $logo, $m, 75 + intval((150 - $th) / 2), 0, 0, $tw, $th, $lw, $lh);
            imagedestroy($logo);
            $tx = $m + $tw + 60;
        }
        $text($site, $tx, 95, $w - $tx - $m, 26, true, 'FFFFFF');
        $text('Geschenkgutschein', $tx, 170, $w - $tx - $m, 18, false, 'FFFFFF');
        // Zeilen wie der Umbruch von TIX_Ticket_Template::render_text_field (Wörter, Breite per imagettfbbox)
        $lines = function ($str, $size, $width, $bold = false) {
            $font = TIX_Ticket_Template::get_font_path('sans-serif', $bold ? 'bold' : 'normal');
            if (!$font || !function_exists('imagettfbbox')) return max(1, intval(ceil(mb_strlen($str) / 40)));
            $n = 1; $line = '';
            foreach (explode(' ', (string) $str) as $wd) {
                $t = trim($line . ' ' . $wd);
                $bb = imagettfbbox($size, 0, $font, $t);
                if ($bb && abs($bb[2] - $bb[0]) > $width && $line !== '') { $n++; $line = $wd; } else { $line = $t; }
            }
            return $n;
        };
        // Betrag + Empfänger
        $cw = $w - 2 * $m;
        $y = 380;
        $text('Ein Geschenk' . ($gift['gift_recipient'] ? ' für' : ''), $m, $y, $cw, 20, false, '6B6F82', 'center');
        $y += 60;
        if ($gift['gift_recipient']) {
            $text($gift['gift_recipient'], $m, $y, $cw, 40, true, '2C2F3F', 'center', 1.3);
            $y += $lines($gift['gift_recipient'], 40, $cw, true) * 52 + 50;
        }
        $text(self::eur($card['value'] ?? 0), $m, $y, $cw, 96, true, $accent === $head ? $accent : '2C2F3F', 'center', 1.2);
        $y += 150;
        if ($gift['gift_message']) {
            // Absatzweise zentriert (Zeilenumbrüche der Nachricht bleiben)
            $paras = explode("\n", $gift['gift_message']);
            $last = count($paras) - 1;
            foreach ($paras as $pi => $para) {
                $para = trim($para);
                if ($pi === 0) $para = '„' . $para;
                if ($pi === $last) $para .= '“';
                if (trim($para, '„“ ') === '') { $y += 30; continue; }
                $text($para, $m + 40, $y, $cw - 80, 19, false, '2C2F3F', 'center', 1.6);
                $y += $lines($para, 19, $cw - 80) * 30;
            }
            $y += 30;
        }
        if ($gift['gift_from']) { $text('von ' . $gift['gift_from'], $m, $y, $cw, 19, true, '6B6F82', 'center'); $y += 60; }
        // QR + Code + Gültigkeit, darunter (mit Abstand) der Einlöse-Hinweis
        $hint_y = $h - 200;
        $qy = max($y + 30, 960);
        $qs = min(400, $hint_y - 150 - $qy);
        if ($qs < 260) { $qs = 260; $qy = $hint_y - 150 - $qs; }
        $qr = class_exists('TIX_QR') ? TIX_QR::gd($code, $qs, 2) : null;
        if ($qr) {
            $q = imagesx($qr);
            imagecopy($img, $qr, intval(($w - $q) / 2), $qy, 0, 0, $q, $q);
            imagedestroy($qr);
            $qs = $q;
        }
        $text($code, $m, $qy + $qs + 20, $cw, 32, true, '2C2F3F', 'center');
        $exp = !empty($card['expires']) ? date_i18n('d.m.Y', strtotime($card['expires'])) : '';
        $text($exp !== '' ? 'Gültig bis ' . $exp : '', $m, $qy + $qs + 90, $cw, 15, false, '6B6F82', 'center');
        $hint = 'Einlösen: Code im Online-Shop bzw. in der App an der Kasse eingeben, vor Ort den QR-Code scannen lassen oder in der App ins Guthaben übernehmen. Restwert bleibt erhalten.';
        if (!empty($card['organizer_id'])) $hint = 'Gilt bei ' . html_entity_decode(get_the_title(intval($card['organizer_id'])), ENT_QUOTES, 'UTF-8') . '. ' . $hint;
        imageline($img, $m, $hint_y - 30, $w - $m, $hint_y - 30, $rgb('E3E5EC'));
        $text($hint, $m, $hint_y, $cw, 13, false, '6B6F82', 'center', 1.6);
        $host = (string) wp_parse_url(class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to(home_url('/')) : home_url('/'), PHP_URL_HOST);
        $text($host, $m, $h - 80, $w - 2 * $m, 13, true, '2C2F3F', 'center');
        ob_start();
        imagejpeg($img, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($img);
        $pdf = TIX_Ticket_Template::create_minimal_pdf($jpeg, $w, $h);
        return $pdf ?: null;
    }

    public static function filename($code) {
        return 'Gutschein-' . (preg_replace('/[^A-Za-z0-9-]/', '', (string) $code) ?: 'Code') . '.pdf';
    }

    // ═══════════════ REST: PDF ═══════════════

    /** Darf der Aufrufer den Gutschein sehen? Käufer (Konto/bestätigte E-Mail), Inhaber des Gutschein-Tickets, Admin oder Bestell-Key. */
    private static function allowed($card, WP_REST_Request $req) {
        global $wpdb;
        $oid = intval($card['order_id'] ?? 0);
        $o = $oid ? $wpdb->get_row($wpdb->prepare("SELECT id, customer_id, billing_email, order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", $oid)) : null;
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        if ($user && $user->has_cap('manage_options')) return true;
        if ($user) {
            $trusted = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : '';
            if ($o && intval($o->customer_id) > 0 && intval($o->customer_id) === intval($user->ID)) return true;
            if ($trusted !== '' && $o && strcasecmp((string) $o->billing_email, $trusted) === 0) return true;
            $tid = intval($card['ticket_id'] ?? 0);
            if ($trusted !== '' && $tid && strcasecmp((string) get_post_meta($tid, '_tix_ticket_owner_email', true), $trusted) === 0) return true;
        }
        $key = sanitize_text_field((string) ($req->get_param('key') ?? ''));
        $want = $req->get_param('order_id');
        if ($key !== '') {
            if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('gift_pdf_key', 20, 600, 'ip')) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', ['status' => 429]);
            // order_id (Web schickt ?order_id=&key=) muss zur Bestellung des Gutscheins passen
            $id_ok = $want === null || $want === '' || ($o && intval($want) === intval($o->id));
            if ($id_ok && $o && (string) $o->order_key !== '' && hash_equals((string) $o->order_key, $key)) return true;
        }
        return $user ? new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]) : new WP_Error('rest_not_logged_in', 'Bitte anmelden oder den Link aus der Bestellung nutzen.', ['status' => 401]);
    }

    /** GET /customer/giftcards/{code}/pdf[?key=] → {ok, code, filename, mime, data} */
    public static function rest_pdf(WP_REST_Request $req) {
        $card = class_exists('TIX_Giftcards') ? TIX_Giftcards::get_card((string) $req['code']) : null;
        if (!$card) return new WP_Error('tix_giftcard_unknown', 'Gutschein nicht gefunden.', ['status' => 404]);
        $ok = self::allowed($card, $req);
        if (is_wp_error($ok)) return $ok;
        $pdf = self::pdf($card['code']);
        if (!$pdf) return new WP_Error('tix_no_pdf', 'Das Gutschein-PDF kann gerade nicht erzeugt werden.', ['status' => 500]);
        // ?download=1: Datei direkt (Link in der Ticket-Ansicht der Website)
        if (filter_var($req->get_param('download'), FILTER_VALIDATE_BOOLEAN)) {
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . self::filename($card['code']) . '"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        }
        $r = new WP_REST_Response(['ok' => true, 'code' => (string) $card['code'], 'filename' => self::filename($card['code']), 'mime' => 'application/pdf', 'data' => base64_encode($pdf)]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    // ═══════════════ Mail ═══════════════

    /** Geschenk-Block für die Bestätigungs-Mail + PDF-Dateien (temporär). ['html', 'files', 'codes'] oder null. */
    public static function mail_parts($order_id) {
        $codes = self::codes_for_order($order_id);
        if (!$codes) return null;
        $dir = trailingslashit(wp_get_upload_dir()['basedir']) . 'tix-private-tmp';
        if (!is_dir($dir) && wp_mkdir_p($dir)) @file_put_contents($dir . '/.htaccess', "deny from all\n");
        $html = '';
        $files = [];
        foreach ($codes as $code) {
            $card = TIX_Giftcards::get_card($code);
            if (!$card) continue;
            $g = self::fields($card);
            $exp = !empty($card['expires']) ? date_i18n('d.m.Y', strtotime($card['expires'])) : '';
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px;"><tr><td style="border:2px dashed #ca8a04;background:#fefce8;border-radius:12px;padding:18px 20px;text-align:center;">'
                . '<div style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#a16207;font-weight:700;">🎁 Dein Geschenkgutschein zum Verschenken</div>'
                . ($g['gift_recipient'] ? '<div style="font-size:15px;color:#44403c;margin-top:8px;">für <strong>' . esc_html($g['gift_recipient']) . '</strong></div>' : '')
                . '<div style="font-size:30px;font-weight:900;color:#1c1917;margin-top:6px;">' . esc_html(self::eur($card['value'] ?? 0)) . '</div>'
                . '<div style="font-size:24px;font-weight:800;letter-spacing:0.04em;font-family:monospace;color:#1c1917;margin-top:8px;">' . esc_html($code) . '</div>'
                . ($g['gift_message'] ? '<div style="font-size:14px;color:#44403c;margin-top:10px;font-style:italic;">„' . nl2br(esc_html($g['gift_message'])) . '“</div>' : '')
                . ($g['gift_from'] ? '<div style="font-size:13px;color:#78716c;margin-top:6px;">von ' . esc_html($g['gift_from']) . '</div>' : '')
                . '<div style="font-size:12px;color:#78716c;margin-top:10px;">Das PDF zum Ausdrucken oder Weiterschicken hängt an dieser E-Mail.' . ($exp ? ' Gültig bis ' . esc_html($exp) . '.' : '') . '</div>'
                . '</td></tr></table>';
            $pdf = self::pdf($code);
            if ($pdf) {
                $f = $dir . '/' . wp_generate_password(12, false) . '-' . self::filename($code);
                if (@file_put_contents($f, $pdf) !== false) $files[] = $f;
            }
        }
        if ($html === '') return null;
        $html = '<div style="margin:0 0 20px;"><div style="font-size:15px;font-weight:700;margin:0 0 10px;">Gutschein verschenken</div>' . $html . '</div>';
        return ['html' => $html, 'files' => $files, 'codes' => $codes];
    }

    public static function cleanup(array $files) {
        foreach ($files as $f) if (is_string($f) && strpos($f, 'tix-private-tmp') !== false && is_file($f)) @unlink($f);
    }

    /** Codes entstanden erst nach der Bestätigungs-Mail: eigene Mail „Dein Geschenkgutschein“. */
    public static function send_late_mail($order_id) {
        if (get_post_meta($order_id, '_tix_gift_mail_sent', true)) return false;
        if (!get_post_meta($order_id, '_tix_completed_email_sent', true)) return false; // Bestätigung kommt noch und nimmt die Codes mit
        $o = class_exists('TIX_Order') ? TIX_Order::get($order_id) : null;
        if (!$o || !is_email($o->get_billing_email())) return false;
        $parts = self::mail_parts($order_id);
        if (!$parts) return false;
        update_post_meta($order_id, '_tix_gift_mail_sent', current_time('mysql'));
        $html = class_exists('TIX_Emails') ? TIX_Emails::build_generic_email_html('Dein Geschenkgutschein', $parts['html'], 'Bestellung #' . intval($order_id)) : $parts['html'];
        $subject = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8') . ' – Dein Geschenkgutschein';
        if (class_exists('TIX_Customer_URL')) TIX_Customer_URL::mail($o->get_billing_email(), $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $parts['files']);
        else wp_mail($o->get_billing_email(), $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $parts['files']);
        self::cleanup($parts['files']);
        return true;
    }

    // ═══════════════ Einstellungen fürs Studio ═══════════════

    private static function scope(WP_REST_Request $req) {
        if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) return 0;
        if (current_user_can('manage_options')) return absint($req->get_param('organizer') ?? $req->get_param('organizer_id') ?? 0);
        return intval(TIX_App_Scope::organizer_id_for_user());
    }

    /** GET /giftcards/admin/config[?organizer=] */
    public static function rest_admin_config(WP_REST_Request $req) {
        $oid = self::scope($req);
        $st = $oid ? TIX_Giftcards::organizer_settings($oid) : TIX_Giftcards::get_settings();
        $can = TIX_Wallet_Balance::check_manager($req) === true;
        list($stats, $recent) = self::stats($oid, $can);
        $r = new WP_REST_Response(['ok' => true, 'organizer_id' => $oid, 'settings' => [
            'enabled' => !empty($st['enabled']), 'amounts' => array_values(array_map('floatval', $st['amounts'])), 'free_amount' => !empty($st['free_amount']),
            'free_min' => intval($st['free_min']), 'free_max' => intval($st['free_max']), 'validity_years' => intval($st['validity_years']),
        ], 'enabled_via_module' => $oid > 0, 'can_edit' => $can, 'stats' => $stats, 'recent' => $recent]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /**
     * Kennzahlen + letzte Gutscheine des Bereichs (Seite bzw. Veranstalter). Stornierte Käufe zählen nicht.
     * stats {sold_count, sold_value, redeemed_value, open_value (nicht abgelaufen)}; recent (20) {code, value, balance, expires, created, buyer}
     * – den vollen Code sehen nur, die ändern dürfen (sonst GS-XXXX-••••).
     */
    private static function stats($oid, $full_codes) {
        global $wpdb;
        wp_cache_delete('tix_coupons', 'options');
        $today = current_time('Y-m-d');
        $s = ['sold_count' => 0, 'sold_value' => 0.0, 'redeemed_value' => 0.0, 'open_value' => 0.0];
        $rows = [];
        foreach ((array) get_option('tix_coupons', []) as $code => $c) {
            if (($c['discount_type'] ?? '') !== 'giftcard') continue;
            if (intval($c['organizer_id'] ?? 0) !== intval($oid) && ($oid || !empty($c['organizer_id']))) continue;
            $storno = 0.0;
            foreach ((array) ($c['redemptions'] ?? []) as $r) if (is_array($r) && ($r['channel'] ?? '') === 'storno') $storno += floatval($r['amount'] ?? 0);
            if ($storno > 0 && $storno + 0.001 >= floatval($c['value'] ?? 0)) continue; // ganz storniert
            $value = round(floatval($c['value'] ?? 0), 2);
            $bal = round(floatval($c['balance'] ?? 0), 2);
            $s['sold_count']++;
            $s['sold_value'] += $value;
            $s['redeemed_value'] += max(0, $value - $bal - $storno);
            if ($bal > 0 && (empty($c['expires']) || $c['expires'] >= $today)) $s['open_value'] += $bal;
            $rows[] = ['code' => (string) $code, 'value' => $value, 'balance' => $bal, 'expires' => (string) ($c['expires'] ?? ''), 'created' => (string) ($c['created'] ?? ''), 'order_id' => intval($c['order_id'] ?? 0)];
        }
        foreach (['sold_value', 'redeemed_value', 'open_value'] as $k) $s[$k] = round($s[$k], 2);
        usort($rows, function ($a, $b) { return strcmp($b['created'], $a['created']); });
        $rows = array_slice($rows, 0, 20);
        $recent = [];
        foreach ($rows as $r) {
            $buyer = '';
            if ($r['order_id']) {
                $o = $wpdb->get_row($wpdb->prepare("SELECT billing_first_name, billing_last_name FROM {$wpdb->prefix}tix_orders WHERE id = %d", $r['order_id']));
                if ($o) $buyer = trim($o->billing_first_name . ' ' . $o->billing_last_name);
            }
            $recent[] = ['code' => $full_codes ? $r['code'] : preg_replace('/[A-Z0-9]{4}$/', '••••', $r['code']), 'value' => $r['value'], 'balance' => $r['balance'],
                'expires' => $r['expires'], 'created' => $r['created'], 'buyer' => $buyer];
        }
        return [$s, $recent];
    }

    /** POST /giftcards/admin[/config] {settings: {enabled?, amounts?, free_amount?, free_min?, free_max?, validity_years?}} – Teil-Update. */
    public static function rest_admin_save(WP_REST_Request $req) {
        $oid = self::scope($req);
        $in = $req->get_json_params();
        if (!is_array($in)) $in = $req->get_body_params();
        if (isset($in['settings']) && is_array($in['settings'])) $in = $in['settings'];
        $amounts = null;
        if (array_key_exists('amounts', $in)) {
            $amounts = [];
            foreach ((is_array($in['amounts']) ? $in['amounts'] : preg_split('/[,;\s]+/', (string) $in['amounts'])) as $a) {
                $a = round(floatval(str_replace(',', '.', (string) $a)), 2);
                if ($a > 0 && $a <= 10000) $amounts[] = $a;
            }
            $amounts = array_values(array_unique($amounts));
            sort($amounts);
            if (!$amounts) return new WP_Error('tix_invalid_field', 'Bitte mindestens einen Gutscheinbetrag angeben.', ['status' => 400, 'field' => 'amounts']);
            if (count($amounts) > 8) return new WP_Error('tix_invalid_field', 'Höchstens 8 feste Beträge.', ['status' => 400, 'field' => 'amounts']);
        }
        if (array_key_exists('validity_years', $in) && (intval($in['validity_years']) < 1 || intval($in['validity_years']) > 10)) {
            return new WP_Error('tix_invalid_field', 'Gültigkeit 1 bis 10 Jahre.', ['status' => 400, 'field' => 'validity_years']);
        }
        // Wunschbetrag-Grenzen (1.38.464 einstellbar): ganze Euro 1–10000, max ≥ min
        $cur_st = $oid ? TIX_Giftcards::organizer_settings($oid) : TIX_Giftcards::get_settings();
        $fmin = array_key_exists('free_min', $in) ? $in['free_min'] : $cur_st['free_min'];
        $fmax = array_key_exists('free_max', $in) ? $in['free_max'] : $cur_st['free_max'];
        foreach (['free_min' => $fmin, 'free_max' => $fmax] as $fk => $fv) {
            if (!is_numeric(str_replace(',', '.', (string) $fv)) || floatval(str_replace(',', '.', (string) $fv)) < 1 || floatval(str_replace(',', '.', (string) $fv)) > 10000) {
                return new WP_Error('tix_invalid_field', 'Wunschbetrag-Grenzen 1 bis 10.000 €.', ['status' => 400, 'field' => $fk]);
            }
        }
        if (floatval(str_replace(',', '.', (string) $fmax)) < floatval(str_replace(',', '.', (string) $fmin))) {
            return new WP_Error('tix_invalid_field', 'Der Höchstbetrag muss mindestens so groß sein wie der Mindestbetrag.', ['status' => 400, 'field' => 'free_max']);
        }
        list($fmin, $fmax) = TIX_Giftcards::clamp_limits($fmin, $fmax);
        if ($oid) {
            // je Veranstalter: an/aus über das Modul „giftcards“
            $g = get_post_meta($oid, '_tix_giftcard_settings', true);
            $g = is_array($g) ? $g : [];
            if ($amounts !== null) $g['amounts'] = $amounts;
            if (array_key_exists('free_amount', $in)) $g['free_amount'] = filter_var($in['free_amount'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if (array_key_exists('validity_years', $in)) $g['validity_years'] = intval($in['validity_years']);
            $g['free_min'] = $fmin;
            $g['free_max'] = $fmax;
            update_post_meta($oid, '_tix_giftcard_settings', $g);
            TIX_Giftcards::ensure_organizer_event($oid);
        } else {
            $cur = TIX_Giftcards::get_settings();
            TIX_Giftcards::save_settings([
                'enabled'        => array_key_exists('enabled', $in) ? filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN) : $cur['enabled'],
                'amounts'        => implode(',', $amounts ?? $cur['amounts']),
                'free_amount'    => array_key_exists('free_amount', $in) ? filter_var($in['free_amount'], FILTER_VALIDATE_BOOLEAN) : $cur['free_amount'],
                'validity_years' => array_key_exists('validity_years', $in) ? intval($in['validity_years']) : $cur['validity_years'],
                'free_min'       => $fmin,
                'free_max'       => $fmax,
            ]);
        }
        return self::rest_admin_config($req);
    }
}

TIX_Giftcard_Gift::init();
