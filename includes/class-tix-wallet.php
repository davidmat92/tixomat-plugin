<?php
/**
 * TIX_Wallet — Apple Wallet (.pkpass) + Google Wallet (Save-to-Wallet)
 *
 * Aufbau:
 *  - is_apple_ready() / is_google_ready() — prüfen ob Settings vollständig sind
 *  - get_apple_pass_url($ticket_id) / get_google_save_url($ticket_id) — Download/Save-URLs
 *  - handle_apple_download() — generiert .pkpass on-the-fly + streamt
 *  - handle_google_save() — generiert JWT Save-Link und redirected
 *  - generate_apple_pass($ticket) — interne Pass-Generierung (PKCS#7-signiert)
 *  - generate_google_jwt($ticket) — interne JWT-Generierung
 *
 * Aktivierung: Sobald `wallet_*`-Settings ausgefüllt sind. Solange leer,
 * geben is_*_ready() false zurück und Buttons werden ausgeblendet.
 */
if (!defined('ABSPATH')) exit;

class TIX_Wallet {

    public static function init() {
        // Endpoints für die Pass-Generierung
        add_action('init',                                [__CLASS__, 'register_rewrite']);
        add_action('template_redirect',                   [__CLASS__, 'maybe_handle_request']);
        add_action('admin_post_tix_wallet_apple',         [__CLASS__, 'handle_apple_download']);
        add_action('admin_post_nopriv_tix_wallet_apple',  [__CLASS__, 'handle_apple_download']);
        add_action('admin_post_tix_wallet_google',        [__CLASS__, 'handle_google_save']);
        add_action('admin_post_nopriv_tix_wallet_google', [__CLASS__, 'handle_google_save']);
    }

    public static function register_rewrite() {
        // Saubere URLs: /wallet/apple/{ticket_id}/{token}.pkpass
        // Optional — admin-post.php Variante reicht für die meisten Fälle.
    }

    public static function maybe_handle_request() {
        // Reserved für saubere /wallet/ URLs falls später gewünscht.
    }

    // ───────────────────────────────────────────────
    // Bereitschaft prüfen
    // ───────────────────────────────────────────────

    public static function is_master_enabled(): bool {
        return !empty(tix_get_settings('wallet_enabled'));
    }

    public static function is_apple_ready(): bool {
        if (!self::is_master_enabled()) return false;
        $s = tix_get_settings();
        if (empty($s['wallet_apple_enabled']))         return false;
        if (empty($s['wallet_apple_pass_type_id']))    return false;
        if (empty($s['wallet_apple_team_id']))         return false;
        if (empty($s['wallet_apple_cert_path']))       return false;
        if (!file_exists($s['wallet_apple_cert_path'])) return false;
        if (empty($s['wallet_apple_wwdr_path']))       return false;
        if (!file_exists($s['wallet_apple_wwdr_path'])) return false;
        return true;
    }

    public static function is_google_ready(): bool {
        if (!self::is_master_enabled()) return false;
        $s = tix_get_settings();
        if (empty($s['wallet_google_enabled']))      return false;
        if (empty($s['wallet_google_issuer_id']))    return false;
        if (empty($s['wallet_google_service_email'])) return false;
        if (empty($s['wallet_google_service_key']))  return false;
        return true;
    }

    // ───────────────────────────────────────────────
    // URL-Helper für Buttons
    // ───────────────────────────────────────────────

    public static function get_apple_pass_url($ticket_id): string {
        $token = self::sign_ticket($ticket_id, 'apple');
        return add_query_arg([
            'action'    => 'tix_wallet_apple',
            'ticket_id' => intval($ticket_id),
            'token'     => $token,
        ], admin_url('admin-post.php'));
    }

    public static function get_google_save_url($ticket_id): string {
        $token = self::sign_ticket($ticket_id, 'google');
        return add_query_arg([
            'action'    => 'tix_wallet_google',
            'ticket_id' => intval($ticket_id),
            'token'     => $token,
        ], admin_url('admin-post.php'));
    }

    /**
     * Signed Token zur Verifikation: stellt sicher, dass nur der Käufer
     * (oder Empfänger des Tickets) die Pass-Generierung anstoßen kann.
     */
    private static function sign_ticket($ticket_id, $type): string {
        return hash_hmac('sha256', $type . '|' . intval($ticket_id), wp_salt('auth'));
    }

    private static function verify_ticket($ticket_id, $type, $token): bool {
        return hash_equals(self::sign_ticket($ticket_id, $type), (string) $token);
    }

    // ───────────────────────────────────────────────
    // Apple Wallet: Endpoint
    // ───────────────────────────────────────────────

    public static function handle_apple_download() {
        $ticket_id = intval($_GET['ticket_id'] ?? 0);
        $token     = sanitize_text_field($_GET['token'] ?? '');
        if (!$ticket_id || !self::verify_ticket($ticket_id, 'apple', $token)) wp_die('Ungültiger Link.', 403);
        if (!self::is_apple_ready()) wp_die('Apple Wallet ist nicht konfiguriert.', 503);

        $ticket = self::load_ticket($ticket_id);
        if (!$ticket) wp_die('Ticket nicht gefunden.', 404);

        $pkpass = self::generate_apple_pass($ticket);
        if (!$pkpass) wp_die('Pass konnte nicht erstellt werden. Prüfe Server-Logs.', 500);

        $filename = 'ticket-' . $ticket['code'] . '.pkpass';
        nocache_headers();
        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pkpass));
        echo $pkpass;
        exit;
    }

    // ───────────────────────────────────────────────
    // Google Wallet: Endpoint
    // ───────────────────────────────────────────────

    public static function handle_google_save() {
        $ticket_id = intval($_GET['ticket_id'] ?? 0);
        $token     = sanitize_text_field($_GET['token'] ?? '');
        if (!$ticket_id || !self::verify_ticket($ticket_id, 'google', $token)) wp_die('Ungültiger Link.', 403);
        if (!self::is_google_ready()) wp_die('Google Wallet ist nicht konfiguriert.', 503);

        $ticket = self::load_ticket($ticket_id);
        if (!$ticket) wp_die('Ticket nicht gefunden.', 404);

        $jwt = self::generate_google_jwt($ticket);
        if (!$jwt) wp_die('JWT konnte nicht generiert werden.', 500);

        $save_url = 'https://pay.google.com/gp/v/save/' . $jwt;
        wp_redirect($save_url);
        exit;
    }

    // ───────────────────────────────────────────────
    // Ticket-Daten laden (für Pass-Inhalte)
    // ───────────────────────────────────────────────

    private static function load_ticket($ticket_id): ?array {
        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_ticket') return null;

        $code     = get_post_meta($ticket_id, '_tix_ticket_code', true) ?: get_post_meta($ticket_id, '_tix_ticket_serial', true) ?: '';
        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));

        // Kategorie: cat_name ist Standard-Meta, category_name als Legacy-Fallback
        $cat_name = get_post_meta($ticket_id, '_tix_ticket_cat_name', true)
                 ?: get_post_meta($ticket_id, '_tix_ticket_category_name', true)
                 ?: 'Ticket';

        // Inhaber-Name: erst personalisierter Owner, sonst Käufer aus der Order, sonst leer
        $owner = trim((string) get_post_meta($ticket_id, '_tix_ticket_owner_name', true));
        if ($owner === '') {
            // Legacy-Format mit first/last name
            $owner = trim(
                get_post_meta($ticket_id, '_tix_ticket_owner_first_name', true) . ' ' .
                get_post_meta($ticket_id, '_tix_ticket_owner_last_name', true)
            );
        }
        if ($owner === '') {
            // Fallback: Käufer aus zugehöriger Order
            $order_id = intval(get_post_meta($ticket_id, '_tix_ticket_order_id', true));
            if ($order_id > 0) {
                global $wpdb;
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT billing_first_name, billing_last_name FROM {$wpdb->prefix}tix_orders WHERE id = %d",
                    $order_id
                ));
                if ($row) {
                    $owner = trim(($row->billing_first_name ?? '') . ' ' . ($row->billing_last_name ?? ''));
                }
            }
        }

        $event = $event_id ? get_post($event_id) : null;
        $event_title = $event ? $event->post_title : 'Event';
        $date_start  = $event_id ? get_post_meta($event_id, '_tix_date_start', true) : '';
        $time_start  = $event_id ? get_post_meta($event_id, '_tix_time_start', true) : '';
        $admission   = $event_id ? get_post_meta($event_id, '_tix_time_admission', true) : '';

        // Venue-Daten
        $venue_name = '';
        $venue_lat  = 0.0;
        $venue_lng  = 0.0;
        $venue_addr = '';
        if ($event_id) {
            $loc_id = intval(get_post_meta($event_id, '_tix_location_id', true));
            if ($loc_id) {
                $venue_name = get_the_title($loc_id);
                $venue_lat  = floatval(get_post_meta($loc_id, '_tix_loc_lat', true));
                $venue_lng  = floatval(get_post_meta($loc_id, '_tix_loc_lng', true));
                $venue_addr = trim(get_post_meta($loc_id, '_tix_loc_address', true));
            }
        }

        return [
            'id'           => $ticket_id,
            'code'         => $code ?: ('TIX-' . $ticket_id),
            'event_id'     => $event_id,
            'event_title'  => $event_title,
            'date_start'   => $date_start,
            'time_start'   => $time_start,
            'admission'    => $admission,
            'venue_name'   => $venue_name,
            'venue_addr'   => $venue_addr,
            'venue_lat'    => $venue_lat,
            'venue_lng'    => $venue_lng,
            'owner_name'   => $owner, // leer wenn weder personalisiert noch Käufer-Daten verfügbar
            'category'     => $cat_name,
        ];
    }

    // ───────────────────────────────────────────────
    // Apple Wallet — Pass-Paket erstellen
    // ───────────────────────────────────────────────

    /**
     * Erzeugt eine signierte .pkpass-Datei (ZIP-Container) für ein Ticket.
     *
     * Voraussetzungen am Server:
     *  - PHP-OpenSSL Extension
     *  - PHP-ZipArchive Extension
     *  - Pass-Zertifikat (.p12) + Passwort in Settings
     *  - WWDR-Zertifikat (.pem) in Settings
     *
     * Returns: Binary .pkpass content (ZIP) oder null bei Fehler.
     */
    public static function generate_apple_pass(array $ticket): ?string {
        $s = tix_get_settings();
        if (!class_exists('ZipArchive')) {
            self::log('PHP ZipArchive Extension fehlt');
            return null;
        }
        if (!function_exists('openssl_pkcs7_sign')) {
            self::log('PHP OpenSSL Extension fehlt');
            return null;
        }

        // 1. pass.json zusammenbauen
        $pass_json = self::build_apple_pass_json($ticket, $s);

        // 2. Asset-Bilder herunterladen (logo.png, icon.png, optional strip.png)
        $assets = self::collect_apple_assets($s, $ticket);

        // 3. Manifest (SHA1 jeder Datei) erstellen
        $manifest = ['pass.json' => sha1($pass_json)];
        foreach ($assets as $name => $bytes) {
            $manifest[$name] = sha1($bytes);
        }
        $manifest_json = wp_json_encode($manifest);

        // 4. Manifest signieren (PKCS#7 detached signature mit Pass-Cert + WWDR)
        $signature = self::sign_apple_manifest($manifest_json, $s);
        if (!$signature) return null;

        // 5. ZIP-Container bauen
        $tmp_zip = wp_tempnam('tixwallet-apple-');
        $zip = new ZipArchive();
        if ($zip->open($tmp_zip, ZipArchive::OVERWRITE) !== true) {
            self::log('ZIP open fehlgeschlagen: ' . $tmp_zip);
            return null;
        }
        $zip->addFromString('pass.json',     $pass_json);
        $zip->addFromString('manifest.json', $manifest_json);
        $zip->addFromString('signature',     $signature);
        foreach ($assets as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        $bytes = file_get_contents($tmp_zip);
        @unlink($tmp_zip);
        return $bytes ?: null;
    }

    private static function build_apple_pass_json(array $t, array $s): string {
        $pass = [
            'formatVersion'      => 1,
            'passTypeIdentifier' => $s['wallet_apple_pass_type_id'],
            'serialNumber'       => $t['code'],
            'teamIdentifier'     => $s['wallet_apple_team_id'],
            'organizationName'   => $s['wallet_apple_org_name'] ?: get_bloginfo('name'),
            'description'        => $t['event_title'] . ' — ' . $t['category'],
            // logoText leer lassen, wenn ein Logo-Bild gesetzt ist — sonst rendert iOS Logo UND Text
            // nebeneinander und das Header-Layout wird gequetscht (mit headerField rechts).
            'logoText'           => '',
            'foregroundColor'    => self::hex_to_rgb_css($s['wallet_apple_fg_color'] ?? '#ffffff'),
            'backgroundColor'    => self::hex_to_rgb_css($s['wallet_apple_bg_color'] ?? '#0f172a'),
            'labelColor'         => self::hex_to_rgb_css($s['wallet_apple_label_color'] ?? '#cbd5e1'),
            'eventTicket' => [
                // Header: nur 1 Kurz-Info rechts neben dem Logo (Kategorie passt am besten)
                // KEIN langer Event-Titel hier — der schneidet ab.
                'headerFields' => array_values(array_filter([
                    $t['category'] ? ['key' => 'cat', 'label' => 'KATEGORIE', 'value' => $t['category']] : null,
                ])),
                // Primary: Event-Titel groß — das ist die wichtigste Info
                'primaryFields' => [
                    ['key' => 'event', 'label' => 'EVENT', 'value' => $t['event_title']],
                ],
                // Secondary: 2-spaltig DATUM + EINLASS
                'secondaryFields' => array_values(array_filter([
                    ['key' => 'date',  'label' => 'DATUM',   'value' => self::format_date($t['date_start'])],
                    ($t['admission'] || $t['time_start']) ? ['key' => 'admission', 'label' => 'EINLASS', 'value' => $t['admission'] ?: $t['time_start']] : null,
                ])),
                // Auxiliary: VENUE breit + optional NAME wenn personalisiert
                'auxiliaryFields' => array_values(array_filter([
                    $t['venue_name']  ? ['key' => 'venue', 'label' => 'VENUE', 'value' => $t['venue_name']] : null,
                    $t['owner_name'] ? ['key' => 'name', 'label' => 'NAME', 'value' => $t['owner_name']] : null,
                ])),
                'backFields' => [
                    ['key' => 'addr',     'label' => 'Adresse',     'value' => $t['venue_addr']],
                    ['key' => 'venue_b',  'label' => 'Venue',       'value' => $t['venue_name']],
                    ['key' => 'code',     'label' => 'Ticket-Code', 'value' => $t['code']],
                    ['key' => 'powered',  'label' => 'Powered by',  'value' => 'Tixomat'],
                ],
            ],
            'barcodes' => [
                [
                    'message'         => $t['code'],
                    'format'          => 'PKBarcodeFormatQR',
                    'messageEncoding' => 'iso-8859-1',
                    'altText'         => $t['code'],
                ],
            ],
        ];

        // Geo-Push: Push-Notification wenn iPhone in Venue-Nähe
        if ($t['venue_lat'] && $t['venue_lng']) {
            $pass['locations'] = [
                [
                    'latitude'         => $t['venue_lat'],
                    'longitude'        => $t['venue_lng'],
                    'relevantText'     => 'Du bist am Venue — Ticket bereit!',
                ],
            ];
            $pass['maxDistance'] = intval($s['wallet_apple_relevant_radius'] ?? 200);
        }

        // Relevant Date: 3h vor Einlass auf dem Lock-Screen
        if ($t['date_start']) {
            $relevant = $t['date_start'] . 'T' . ($t['time_start'] ?: '19:00') . ':00';
            $pass['relevantDate'] = self::iso8601_with_tz($relevant);
        }

        return wp_json_encode($pass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Lädt Logo, Icon und optional Strip-Bild als Binary, gibt assoziatives
     * Array zurück mit Apple-Wallet-Dateinamen ('logo.png', 'icon.png', 'strip.png',
     * 'logo@2x.png', 'icon@2x.png').
     */
    private static function collect_apple_assets(array $s, ?array $ticket = null): array {
        $files = [];
        $logo  = self::fetch_image_bytes($s['wallet_apple_logo_url'] ?? '');
        $icon  = self::fetch_image_bytes($s['wallet_apple_icon_url'] ?? '');

        if ($logo) {
            $files['logo.png']    = $logo;
            $files['logo@2x.png'] = $logo;
        }
        if ($icon) {
            $files['icon.png']    = $icon;
            $files['icon@2x.png'] = $icon;
        }
        // KEIN strip/thumbnail/background — Pass bleibt clean nur mit Logo + Icon + Texten.
        // Apple's Bild-Slots erlauben kein scharfes Vollbild ohne Crop oder Blur, daher weggelassen.
        return $files;
    }

    /**
     * Holt das Event-Beitragsbild und gibt rohe Bytes zurück (PNG oder JPG — Resize macht der Caller).
     */
    private static function event_strip_image(int $event_id): ?string {
        $thumb_id = get_post_thumbnail_id($event_id);
        if (!$thumb_id) return null;

        // Großzügige Quelle nehmen — Resize macht der Caller. medium_large ist gecached + meist 768px.
        $img = wp_get_attachment_image_src($thumb_id, 'medium_large');
        if (!$img || empty($img[0])) {
            $img = wp_get_attachment_image_src($thumb_id, 'large');
        }
        if (!$img || empty($img[0])) {
            $img = wp_get_attachment_image_src($thumb_id, 'full');
        }
        if (!$img || empty($img[0])) return null;

        return self::fetch_image_bytes($img[0]);
    }

    /**
     * Resized Bild-Bytes (PNG/JPG/WebP) auf exakt $w × $h, cropped center,
     * gibt komprimierte PNG-Bytes zurück. Null wenn GD fehlt oder Bild kaputt.
     */
    private static function resize_to_strip(string $bytes, int $w, int $h): ?string {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) return null;
        $src = @imagecreatefromstring($bytes);
        if (!$src) return null;
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) { imagedestroy($src); return null; }

        // Cover-Crop: Quell-Region wählen, sodass Aspect-Ratio passt (zentriert)
        $target_ratio = $w / $h;
        $src_ratio    = $sw / $sh;
        if ($src_ratio > $target_ratio) {
            // Quelle zu breit → links/rechts beschneiden
            $crop_w = intval($sh * $target_ratio);
            $crop_h = $sh;
            $crop_x = intval(($sw - $crop_w) / 2);
            $crop_y = 0;
        } else {
            // Quelle zu hoch → oben/unten beschneiden
            $crop_w = $sw;
            $crop_h = intval($sw / $target_ratio);
            $crop_x = 0;
            $crop_y = intval(($sh - $crop_h) / 2);
        }

        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, $crop_x, $crop_y, $w, $h, $crop_w, $crop_h);
        imagedestroy($src);

        ob_start();
        imagepng($dst, null, 8); // Compression 8 (0=none, 9=max)
        $out = ob_get_clean();
        imagedestroy($dst);
        return $out ?: null;
    }

    private static function fetch_image_bytes($url): ?string {
        if (!$url) return null;
        // Lokale URLs: direkt vom Filesystem
        $upload = wp_get_upload_dir();
        if (strpos($url, $upload['baseurl']) === 0) {
            $path = str_replace($upload['baseurl'], $upload['basedir'], $url);
            return file_exists($path) ? file_get_contents($path) : null;
        }
        // Remote: HTTP fetchen
        $r = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return null;
        return wp_remote_retrieve_body($r);
    }

    /**
     * PKCS#7-Signatur des Manifests mit Pass-Zertifikat + WWDR.
     */
    private static function sign_apple_manifest(string $manifest_json, array $s): ?string {
        $cert_path  = $s['wallet_apple_cert_path']     ?? '';
        $cert_pw    = $s['wallet_apple_cert_password'] ?? '';
        $wwdr_path  = $s['wallet_apple_wwdr_path']     ?? '';

        if (!is_readable($cert_path) || !is_readable($wwdr_path)) {
            self::log('Cert oder WWDR nicht lesbar');
            return null;
        }

        // .p12 → key + cert
        $p12 = file_get_contents($cert_path);
        $certs = [];
        if (!openssl_pkcs12_read($p12, $certs, $cert_pw)) {
            self::log('p12 konnte nicht entschlüsselt werden — falsches Passwort?');
            return null;
        }

        // Temp-Dateien für openssl_pkcs7_sign
        $tmp_in   = wp_tempnam('tix-mf-in-');
        $tmp_out  = wp_tempnam('tix-mf-out-');
        file_put_contents($tmp_in, $manifest_json);

        $signed = openssl_pkcs7_sign(
            $tmp_in,
            $tmp_out,
            $certs['cert'],
            [$certs['pkey'], ''],
            [],
            PKCS7_BINARY | PKCS7_DETACHED,
            $wwdr_path
        );

        if (!$signed) {
            self::log('openssl_pkcs7_sign fehlgeschlagen: ' . openssl_error_string());
            @unlink($tmp_in); @unlink($tmp_out);
            return null;
        }

        // Output enthält MIME-Header — Apple erwartet nur den DER-Block
        $raw = file_get_contents($tmp_out);
        @unlink($tmp_in); @unlink($tmp_out);

        // MIME Header entfernen, Base64 decodieren
        if (preg_match('/Content-Disposition:.*?\\r?\\n\\r?\\n(.*)/s', $raw, $m)) {
            $b64 = preg_replace('/[\\r\\n-]/', '', $m[1]);
            $der = base64_decode($b64);
            if ($der) return $der;
        }
        return null;
    }

    // ───────────────────────────────────────────────
    // Google Wallet — JWT für Save-Link
    // ───────────────────────────────────────────────

    /**
     * Generiert ein JWT für Google Wallet Save-to-Wallet.
     * Spec: https://developers.google.com/wallet/tickets/events/web/jwt
     */
    public static function generate_google_jwt(array $ticket): ?string {
        $s = tix_get_settings();

        $issuer_id    = $s['wallet_google_issuer_id'];
        $service_email = $s['wallet_google_service_email'];
        $private_key   = $s['wallet_google_service_key'];
        $class_suffix  = $s['wallet_google_class_suffix'] ?: 'tixomat-event-ticket';
        $class_id      = $issuer_id . '.' . $class_suffix;
        $object_id     = $issuer_id . '.tix-' . $ticket['id'];

        $event_object = [
            'id'        => $object_id,
            'classId'   => $class_id,
            'state'     => 'ACTIVE',
            'barcode'   => [
                'type'  => 'QR_CODE',
                'value' => $ticket['code'],
            ],
            'ticketHolderName' => $ticket['owner_name'],
            'ticketNumber'     => $ticket['code'],
            'ticketType'       => ['defaultValue' => ['language' => 'de', 'value' => $ticket['category']]],
        ];

        // EventTicketClass — minimale Felder. Class wird in Google's DB gespeichert,
        // aber wir können sie auch im JWT mitschicken und Google legt sie automatisch an.
        $event_class = [
            'id'                  => $class_id,
            'issuerName'          => $s['wallet_apple_org_name'] ?: get_bloginfo('name'),
            'reviewStatus'        => 'UNDER_REVIEW',
            'eventName'           => ['defaultValue' => ['language' => 'de', 'value' => $ticket['event_title']]],
            'venue' => [
                'name'    => ['defaultValue' => ['language' => 'de', 'value' => $ticket['venue_name'] ?: '–']],
                'address' => ['defaultValue' => ['language' => 'de', 'value' => $ticket['venue_addr'] ?: '']],
            ],
            'dateTime' => [
                'start' => $ticket['date_start'] ? $ticket['date_start'] . 'T' . ($ticket['time_start'] ?: '19:00') . ':00' : null,
            ],
            'hexBackgroundColor' => $s['wallet_google_bg_color'] ?? '#0f172a',
        ];
        if (!empty($s['wallet_google_logo_url'])) {
            $event_class['logo'] = ['sourceUri' => ['uri' => $s['wallet_google_logo_url']]];
        }
        if (!empty($s['wallet_google_hero_url'])) {
            $event_class['heroImage'] = ['sourceUri' => ['uri' => $s['wallet_google_hero_url']]];
        }

        // JWT-Payload
        $payload = [
            'iss'     => $service_email,
            'aud'     => 'google',
            'typ'     => 'savetowallet',
            'iat'     => time(),
            'origins' => [parse_url(home_url(), PHP_URL_HOST)],
            'payload' => [
                'eventTicketClasses'  => [$event_class],
                'eventTicketObjects'  => [$event_object],
            ],
        ];

        return self::jwt_sign($payload, $private_key);
    }

    private static function jwt_sign(array $payload, string $private_key): ?string {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [
            self::base64url(wp_json_encode($header)),
            self::base64url(wp_json_encode($payload)),
        ];
        $signing_input = implode('.', $segments);

        $pk = openssl_pkey_get_private($private_key);
        if (!$pk) {
            self::log('Google: private_key konnte nicht geladen werden');
            return null;
        }

        $signature = '';
        $ok = openssl_sign($signing_input, $signature, $pk, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            self::log('Google: openssl_sign fehlgeschlagen');
            return null;
        }

        $segments[] = self::base64url($signature);
        return implode('.', $segments);
    }

    // ───────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────

    private static function base64url($data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function hex_to_rgb_css(string $hex): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgb($r, $g, $b)";
    }

    private static function format_date(string $iso): string {
        if (!$iso) return '';
        $ts = strtotime($iso);
        return $ts ? date_i18n('D, d. M Y', $ts) : $iso;
    }

    private static function iso8601_with_tz(string $local_iso): string {
        $ts = strtotime($local_iso);
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : $local_iso;
    }

    private static function log(string $msg): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[tix-wallet] ' . $msg);
        }
    }
}
