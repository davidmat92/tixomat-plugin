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
        if (class_exists('TIX_Wallet_Updates')) TIX_Wallet_Updates::mark_issued($ticket, 'apple');

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
        if (class_exists('TIX_Wallet_Updates')) TIX_Wallet_Updates::mark_issued($ticket, 'google');

        $save_url = 'https://pay.google.com/gp/v/save/' . $jwt;
        wp_redirect($save_url);
        exit;
    }

    // ───────────────────────────────────────────────
    // Ticket-Daten laden (für Pass-Inhalte)
    // ───────────────────────────────────────────────

    public static function load_ticket($ticket_id): ?array {
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

        $code = $code ?: ('TIX-' . $ticket_id);
        $img_id = $event_id ? get_post_thumbnail_id($event_id) : 0;
        return [
            'id'           => $ticket_id,
            'code'         => $code,
            // QR wie App und Web-Ticket (Scanner-kompatibel), 1.38.541
            'barcode'      => $event_id ? 'GL-' . $event_id . '-' . $code : $code,
            'status'       => (string) get_post_meta($ticket_id, '_tix_ticket_status', true),
            'image'        => $img_id ? (string) (wp_get_attachment_image_url($img_id, 'large') ?: '') : '',
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
            // Update-Dienst (1.38.542): Event-Status (manuell vor berechnet) und Check-in
            'event_status' => $event_id ? ((string) get_post_meta($event_id, '_tix_event_status', true) ?: (string) get_post_meta($event_id, '_tix_status', true)) : '',
            'checked_in'   => (bool) get_post_meta($ticket_id, '_tix_ticket_checked_in', true),
        ];
    }

    /** Status im Pass: Storniert, Abgesagt, Verschoben, Eingelassen oder Gültig. */
    public static function status_label(array $t): string {
        if (($t['status'] ?? '') === 'cancelled') return 'Storniert';
        if (($t['event_status'] ?? '') === 'cancelled') return 'Abgesagt';
        if (($t['event_status'] ?? '') === 'postponed') return 'Verschoben';
        if (!empty($t['checked_in']) || ($t['status'] ?? '') === 'used') return 'Eingelassen';
        return 'Gültig';
    }

    /** Fingerabdruck der sichtbaren Pass-Inhalte – ändert er sich, wird der Pass aktualisiert. */
    public static function fingerprint(array $t): string {
        return md5(wp_json_encode([self::status_label($t), $t['event_title'], $t['date_start'], $t['time_start'], $t['admission'], $t['venue_name'], $t['venue_addr'], $t['category'], $t['owner_name']]));
    }

    /** Seriennummer des Apple-Passes: je Seite und Ticket (gemeinsame Pass Type ID für alle Seiten). */
    public static function serial($ticket_id): string {
        return self::site_key() . '-t' . intval($ticket_id);
    }

    /** Ticket-ID aus der Seriennummer (nur Seriennummern dieser Seite), sonst 0. */
    public static function ticket_from_serial($serial): int {
        $prefix = self::site_key() . '-t';
        if (strpos((string) $serial, $prefix) !== 0) return 0;
        $id = substr((string) $serial, strlen($prefix));
        return ctype_digit($id) && get_post_type(intval($id)) === 'tix_ticket' ? intval($id) : 0;
    }

    /** authenticationToken des Passes (Apple Web Service). */
    public static function auth_token($serial): string {
        return hash_hmac('sha256', 'passkit|' . $serial, wp_salt('auth'));
    }

    /** Basis-URL des Update-Dienstes (https, Kunden-Adresse); '' = keiner (z. B. lokal ohne https). */
    public static function web_service_url(): string {
        $url = rest_url('tixomat/v1/passkit');
        if (class_exists('TIX_Customer_URL')) $url = TIX_Customer_URL::to($url);
        $url = (string) apply_filters('tix_wallet_web_service_url', $url);
        return strpos($url, 'https://') === 0 ? untrailingslashit($url) : '';
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

        if (!function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php'; // außerhalb von admin-post (App/REST)

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
            'serialNumber'       => self::serial($t['id']),
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
                    ['key' => 'date',  'label' => 'DATUM',   'value' => self::format_date($t['date_start']), 'changeMessage' => 'Neuer Termin: %@'],
                    ($t['admission'] || $t['time_start']) ? ['key' => 'admission', 'label' => 'EINLASS', 'value' => $t['admission'] ?: $t['time_start'], 'changeMessage' => 'Neuer Einlass: %@'] : null,
                ])),
                // Auxiliary: VENUE breit + optional NAME wenn personalisiert
                'auxiliaryFields' => array_values(array_filter([
                    ['key' => 'status', 'label' => 'STATUS', 'value' => self::status_label($t), 'changeMessage' => 'Ticket: %@'],
                    $t['venue_name']  ? ['key' => 'venue', 'label' => 'VENUE', 'value' => $t['venue_name'], 'changeMessage' => 'Neuer Ort: %@'] : null,
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
                    'message'         => $t['barcode'],
                    'format'          => 'PKBarcodeFormatQR',
                    'messageEncoding' => 'iso-8859-1',
                    'altText'         => $t['code'],
                ],
            ],
        ];

        // Storniertes Ticket: Pass als ungültig markieren (1.38.541)
        if ($t['status'] === 'cancelled') $pass['voided'] = true;

        // Update-Dienst (1.38.542): Wallet meldet sich an und holt Änderungen nach einem Push
        $ws = self::web_service_url();
        if ($ws !== '') {
            $pass['webServiceURL'] = $ws;
            $pass['authenticationToken'] = self::auth_token(self::serial($t['id']));
        }

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

        // Apple-Größen (1.38.542): Logo höchstens 160×50 bzw. 320×100 (@2x), Icon 29/58/87 – große Vorlagen
        // (z. B. 920×227) machten den Pass sonst 150+ KB groß. Ohne GD bleibt die Vorlage unverändert.
        if ($logo) {
            $files['logo.png']    = self::fit_png($logo, 160, 50);
            $files['logo@2x.png'] = self::fit_png($logo, 320, 100);
        }
        if ($icon) {
            $files['icon.png']    = self::fit_png($icon, 29, 29);
            $files['icon@2x.png'] = self::fit_png($icon, 58, 58);
            $files['icon@3x.png'] = self::fit_png($icon, 87, 87);
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

    /** PNG verkleinern, sodass es in W×H passt (Seitenverhältnis bleibt, Transparenz bleibt); nie vergrößern. */
    private static function fit_png(string $bytes, int $w, int $h): string {
        if (!function_exists('imagecreatefromstring')) return $bytes;
        $src = @imagecreatefromstring($bytes);
        if (!$src) return $bytes;
        $sw = imagesx($src); $sh = imagesy($src);
        $scale = min($w / $sw, $h / $sh, 1);
        $nw = max(1, (int) round($sw * $scale)); $nh = max(1, (int) round($sh * $scale));
        if ($scale >= 1 && strncmp($bytes, "\x89PNG", 4) === 0) { imagedestroy($src); return $bytes; }
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
        ob_start();
        imagepng($dst, null, 9);
        $out = ob_get_clean();
        imagedestroy($src); imagedestroy($dst);
        return $out ?: $bytes;
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
        // JWT-Payload
        $payload = [
            'iss'     => $s['wallet_google_service_email'],
            'aud'     => 'google',
            'typ'     => 'savetowallet',
            'iat'     => time(),
            // Herkunft der „In Google Wallet speichern“-Knöpfe: WordPress und ggf. Kunden-Adresse
            'origins' => array_values(array_filter(array_unique([parse_url(home_url(), PHP_URL_HOST), TIX_Customer_URL::host()]))),
            'payload' => [
                'eventTicketClasses'  => [self::google_class($ticket)],
                'eventTicketObjects'  => [self::google_object($ticket)],
            ],
        ];
        return self::jwt_sign($payload, (string) $s['wallet_google_service_key']);
    }

    /**
     * EventTicketObject je Seite und Ticket (1.38.541). Auch für das Objekt-Update (TIX_Wallet_Updates, 1.38.542).
     */
    public static function google_object(array $ticket): array {
        return [
            'id'        => self::google_object_id($ticket),
            'classId'   => self::google_class_id($ticket),
            'state'     => $ticket['status'] === 'cancelled' ? 'INACTIVE' : 'ACTIVE',
            'barcode'   => [
                'type'          => 'QR_CODE',
                'value'         => $ticket['barcode'],
                'alternateText' => $ticket['code'],
            ],
            'ticketHolderName' => $ticket['owner_name'],
            'ticketNumber'     => $ticket['code'],
            'ticketType'       => ['defaultValue' => ['language' => 'de', 'value' => $ticket['category']]],
            'textModulesData'  => [['id' => 'status', 'header' => 'Status', 'body' => self::status_label($ticket)]],
        ];
    }

    /**
     * EventTicketClass je Seite und Event (Eventname/Ort/Datum stehen in der Klasse; eine gemeinsame Klasse zeigte bei
     * allen Tickets das erste Event). Google legt sie beim ersten Speichern aus dem JWT an, Änderungen per Klassen-Update.
     */
    public static function google_class(array $ticket): array {
        $s = tix_get_settings();
        $event_class = [
            'id'                  => self::google_class_id($ticket),
            'issuerName'          => ($s['wallet_apple_org_name'] ?? '') ?: get_bloginfo('name'),
            'reviewStatus'        => 'UNDER_REVIEW',
            'eventName'           => ['defaultValue' => ['language' => 'de', 'value' => $ticket['event_title']]],
            'venue' => [
                'name'    => ['defaultValue' => ['language' => 'de', 'value' => $ticket['venue_name'] ?: '–']],
                'address' => ['defaultValue' => ['language' => 'de', 'value' => $ticket['venue_addr'] ?: '']],
            ],
            'dateTime' => [
                'start' => $ticket['date_start'] ? self::iso8601_with_tz($ticket['date_start'] . 'T' . ($ticket['time_start'] ?: '19:00') . ':00') : null,
            ],
            'hexBackgroundColor' => $s['wallet_google_bg_color'] ?? '#0f172a',
        ];
        if (!empty($s['wallet_google_logo_url'])) {
            $event_class['logo'] = ['sourceUri' => ['uri' => $s['wallet_google_logo_url']]];
        }
        $hero = !empty($s['wallet_google_hero_url']) ? $s['wallet_google_hero_url'] : ($ticket['image'] ?? '');
        if ($hero) {
            $event_class['heroImage'] = ['sourceUri' => ['uri' => $hero]];
        }
        // Absage/Verschiebung als Hinweis in der Klasse (alle Tickets des Events)
        $ev = $ticket['event_status'] ?? '';
        if (in_array($ev, ['cancelled', 'postponed'], true)) {
            $event_class['messages'] = [['id' => 'event_status', 'header' => $ev === 'cancelled' ? 'Event abgesagt' : 'Event verschoben',
                'body' => $ev === 'cancelled' ? 'Dieses Event wurde abgesagt. Infos zur Erstattung bekommst du per E-Mail.' : 'Dieses Event wurde verschoben. Der neue Termin steht im Ticket, sobald er feststeht.']];
        }
        return $event_class;
    }

    public static function jwt_sign(array $payload, string $private_key): ?string {
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

    public static function base64url($data): string {
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

    /** Ortszeit des Events (Zeitzone der Seite) als ISO 8601 mit Versatz – vorher als UTC gelesen (1–2 h daneben). */
    private static function iso8601_with_tz(string $local_iso): string {
        try {
            return (new DateTime($local_iso, wp_timezone()))->format('c');
        } catch (Exception $e) {
            return $local_iso;
        }
    }

    public static function google_class_id(array $t): string {
        $s = tix_get_settings();
        return ($s['wallet_google_issuer_id'] ?? '') . '.' . (($s['wallet_google_class_suffix'] ?? '') ?: 'tixomat-event-ticket') . '-' . self::site_key() . '-e' . intval($t['event_id']);
    }

    public static function google_object_id(array $t): string {
        return (tix_get_settings('wallet_google_issuer_id') ?? '') . '.' . self::site_key() . '-t' . intval($t['id']);
    }

    /** Kennung der Seite für Google-IDs (mehrere Seiten können einen Aussteller teilen). */
    public static function site_key(): string {
        return preg_replace('/[^a-z0-9]+/', '_', strtolower((string) parse_url(home_url(), PHP_URL_HOST)));
    }

    private static function log(string $msg): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[tix-wallet] ' . $msg);
        }
    }
}
