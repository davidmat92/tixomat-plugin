<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Register_API – „Veranstalter werden“ per REST (evendis Studio /studio/registrieren/), 1.38.442.
 *
 *   GET  /register/config      Vertrag, Freigabe, Ticket-Arten, Bot-Schutz (Art, Schwierigkeit, Turnstile-Site-Key)
 *   GET  /register/challenge   Rechenaufgabe für den Bot-Schutz {challenge, difficulty, expires_in}
 *   POST /register/analyze     {mode: describe|flyer|url, text|image|url, pow|turnstile, website} → {fields, missing, image}
 *   POST /register/organizer   Konto + Veranstalter + erstes Event wie [tix_register_event] → {token, user, …}
 *                              (1.38.456: without_event = nur Konto + Veranstalter; optional profile, billing, tax_status, payout, logo)
 *
 * Bot-Schutz (jede POST-Anfrage): Honigtopf-Feld `website` muss leer sein; dann entweder Cloudflare Turnstile
 * (wenn Option `tix_turnstile` site_key + secret_key gesetzt) oder eine Rechenaufgabe: `challenge` aus
 * /register/challenge (15 min gültig, nur einmal) und `pow` so, dass sha256(challenge + ":" + pow) mit
 * `difficulty` Null-Bits beginnt. Ratenbegrenzung je Besucher-IP (TIX_Client_IP, Proxy-Kopfzeilen nur von
 * vertrauenswürdigen Proxys) und ein Tageslimit für KI-Analysen (Option `tix_register_ai_daily`, Vorgabe 300).
 * KI läuft erst nach bestandenem Bot-Schutz.
 *
 * Schalter `tix_studio_register` = 1 (und `tix_studio_url` gesetzt): Seiten mit [tix_register_event] leiten auf
 * <studio>/registrieren/ weiter (?classic=1 zeigt das alte Formular).
 */
class TIX_Register_API {

    const NS          = 'tixomat/v1';
    const POW_PREFIX  = 'tix_pow_';
    const POW_TTL     = 900;
    const TMP_META    = '_tix_register_tmp';
    const CRON        = 'tix_register_cleanup';
    const MAX_IMAGE   = 5242880;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect'], 2);
        add_action(self::CRON, [__CLASS__, 'cleanup']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Register_API::CRON)) wp_schedule_event(time() + 1800, 'daily', TIX_Register_API::CRON);
        }, 28);
    }

    // ── Einstellungen ──

    public static function difficulty() {
        $d = intval(get_option('tix_register_pow_difficulty', 16));
        return max(8, min(24, $d ?: 16));
    }

    private static function turnstile() {
        $t = get_option('tix_turnstile', []);
        $t = is_array($t) ? $t : [];
        $site = trim((string) ($t['site_key'] ?? ''));
        $secret = trim((string) ($t['secret_key'] ?? ''));
        return ($site !== '' && $secret !== '') ? ['site_key' => $site, 'secret_key' => $secret] : null;
    }

    private static function error($code, $msg, $status, array $extra = []) {
        return new WP_Error($code, $msg, ['status' => $status] + $extra);
    }

    private static function limited($bucket, $max, $window) {
        return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('reg_' . $bucket, $max, $window);
    }

    // ── Rechenaufgabe (Proof of Work) ──

    private static function sign($payload) {
        return substr(hash_hmac('sha256', $payload, wp_salt('nonce')), 0, 32);
    }

    public static function issue_challenge() {
        global $wpdb;
        // Alte Einlöse-Merker wegräumen
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like(self::POW_PREFIX) . '%', time() - 2 * self::POW_TTL));
        $d = self::difficulty();
        $payload = 'v1.' . time() . '.' . wp_generate_password(16, false, false) . '.' . $d;
        return ['challenge' => $payload . '.' . self::sign($payload), 'difficulty' => $d, 'expires_in' => self::POW_TTL];
    }

    /** Anzahl führender Null-Bits eines Binär-Hashes. */
    public static function zero_bits($bin) {
        $n = 0;
        for ($i = 0, $l = strlen($bin); $i < $l; $i++) {
            $b = ord($bin[$i]);
            if ($b === 0) { $n += 8; continue; }
            for ($k = 7; $k >= 0; $k--) { if ($b & (1 << $k)) return $n; $n++; }
        }
        return $n;
    }

    /** true oder WP_Error tix_bot_check (403). */
    private static function check_pow($challenge, $pow) {
        global $wpdb;
        $bad = self::error('tix_bot_check', 'Die Sicherheitsprüfung ist abgelaufen – bitte versuche es noch einmal.', 403);
        $parts = explode('.', (string) $challenge);
        if (count($parts) !== 5 || $parts[0] !== 'v1') return $bad;
        list(, $ts, , $d, $sig) = $parts;
        $payload = implode('.', array_slice($parts, 0, 4));
        if (!hash_equals(self::sign($payload), (string) $sig)) return $bad;
        if (intval($ts) < time() - self::POW_TTL || intval($ts) > time() + 60) return $bad;
        $pow = (string) $pow;
        if ($pow === '' || strlen($pow) > 32 || !ctype_alnum($pow)) return $bad;
        if (self::zero_bits(hash('sha256', $challenge . ':' . $pow, true)) < max(intval($d), self::difficulty())) return $bad;
        // Nur einmal (atomar)
        $ins = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::POW_PREFIX . md5($challenge), (string) time()));
        return intval($ins) === 1 ? true : $bad;
    }

    private static function check_turnstile($token, array $t) {
        $token = trim((string) $token);
        if ($token === '') return self::error('tix_bot_check', 'Bitte bestätige, dass du kein Roboter bist.', 403);
        $r = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['timeout' => 8, 'body' => [
            'secret' => $t['secret_key'], 'response' => $token, 'remoteip' => class_exists('TIX_App_Account') ? TIX_App_Account::client_ip() : '',
        ]]);
        $j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
        return (is_array($j) && !empty($j['success'])) ? true : self::error('tix_bot_check', 'Die Sicherheitsprüfung ist fehlgeschlagen – bitte versuche es noch einmal.', 403);
    }

    /** Honigtopf + Turnstile bzw. Rechenaufgabe. */
    private static function check_bot(WP_REST_Request $req) {
        if (trim((string) $req->get_param('website')) !== '') return self::error('tix_bot_check', 'Die Sicherheitsprüfung ist fehlgeschlagen.', 403);
        $t = self::turnstile();
        if ($t) return self::check_turnstile($req->get_param('turnstile'), $t);
        return self::check_pow($req->get_param('challenge'), $req->get_param('pow'));
    }

    // ── Routen ──

    public static function register_routes() {
        register_rest_route(self::NS, '/register/config', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_config'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/register/challenge', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_challenge'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/register/analyze', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_analyze'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/register/organizer', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_organizer'], 'permission_callback' => '__return_true']);
    }

    private static function nocache($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    /** GET /register/config */
    public static function rest_config(WP_REST_Request $req) {
        $review = class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi();
        $terms = $review ? TIX_Org_Approval::terms() : ['title' => '', 'url' => '', 'version' => ''];
        $t = self::turnstile();
        return self::nocache([
            'ok'           => true,
            'review'       => $review,
            'terms'        => ['title' => (string) $terms['title'], 'url' => (string) $terms['url'], 'version' => (string) $terms['version'], 'required' => $terms['version'] !== ''],
            'ticket_modes' => ['online', 'free', 'box_office', 'external'],
            'bot'          => ['type' => $t ? 'turnstile' : 'pow', 'difficulty' => self::difficulty(), 'turnstile_site_key' => $t ? $t['site_key'] : null, 'honeypot' => 'website'],
            'limits'       => ['image_bytes' => self::MAX_IMAGE, 'text_min' => 10, 'text_max' => 4000, 'password_min' => 8],
        ]);
    }

    /** GET /register/challenge */
    public static function rest_challenge(WP_REST_Request $req) {
        if (self::limited('challenge', 30, 600)) return self::error('tix_rate_limit', 'Zu viele Anfragen. Bitte warte ein paar Minuten.', 429);
        return self::nocache(['ok' => true] + self::issue_challenge());
    }

    /** Tageslimit für KI-Analysen (alle Besucher zusammen). */
    private static function ai_budget_ok() {
        $max = intval(get_option('tix_register_ai_daily', 300));
        if ($max <= 0) return true;
        $key = 'tix_reg_ai_' . current_time('Ymd');
        $n = intval(get_transient($key));
        if ($n >= $max) return false;
        set_transient($key, $n + 1, DAY_IN_SECONDS + 3600);
        return true;
    }

    /** Hochgeladenes Bild (multipart `image` oder Data-URL/Base64 im Feld `image`) → [bytes, mime] oder WP_Error. */
    private static function read_image(WP_REST_Request $req) {
        $files = $req->get_file_params();
        $bytes = '';
        if (!empty($files['image']['tmp_name']) && is_uploaded_file($files['image']['tmp_name'])) {
            if (intval($files['image']['size']) > self::MAX_IMAGE) return self::error('tix_invalid_field', 'Das Bild ist zu groß (höchstens 5 MB).', 400, ['field' => 'image']);
            $bytes = (string) file_get_contents($files['image']['tmp_name']);
        } else {
            $raw = (string) $req->get_param('image');
            if (preg_match('#^data:image/[a-z+.-]+;base64,#i', $raw)) $raw = substr($raw, strpos($raw, ',') + 1);
            if (strlen($raw) > self::MAX_IMAGE * 1.4) return self::error('tix_invalid_field', 'Das Bild ist zu groß (höchstens 5 MB).', 400, ['field' => 'image']);
            $bytes = $raw !== '' ? (string) base64_decode($raw, true) : '';
        }
        if ($bytes === '') return self::error('tix_invalid_field', 'Bitte lade deinen Flyer hoch (JPG, PNG oder WebP).', 400, ['field' => 'image']);
        if (strlen($bytes) > self::MAX_IMAGE) return self::error('tix_invalid_field', 'Das Bild ist zu groß (höchstens 5 MB).', 400, ['field' => 'image']);
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return self::error('tix_invalid_field', 'Nur Bilder (JPG, PNG, WebP, GIF).', 400, ['field' => 'image']);
        return [$bytes, $mime];
    }

    /** Bild als vorläufigen Anhang speichern (wird bei der Registrierung zum Event-Bild, sonst nach 24 h gelöscht). */
    public static function store_tmp_image($bytes, $mime) { // öffentlich seit 1.38.505 (POST /ai/extract)
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime];
        $up = wp_upload_bits('flyer-' . wp_generate_password(8, false, false) . '.' . $ext, null, $bytes);
        if (!empty($up['error'])) return 0;
        $id = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => 'Flyer', 'post_status' => 'inherit', 'post_author' => 0], $up['file']);
        if (!$id || is_wp_error($id)) return 0;
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
        update_post_meta($id, self::TMP_META, time());
        return intval($id);
    }

    private static function image_payload($id) {
        $id = intval($id);
        return $id ? ['id' => $id, 'url' => (string) (wp_get_attachment_image_url($id, 'large') ?: wp_get_attachment_url($id))] : null;
    }

    /** POST /register/analyze */
    public static function rest_analyze(WP_REST_Request $req) {
        $mode = sanitize_key((string) $req->get_param('mode'));
        if (!in_array($mode, ['describe', 'flyer', 'url'], true)) return self::error('tix_invalid_field', 'Bitte wähle einen Weg: beschreiben, Flyer oder Link.', 400, ['field' => 'mode']);
        if (self::limited('analyze', 6, 600) || self::limited('analyze_day', 30, DAY_IN_SECONDS)) return self::error('tix_rate_limit', 'Zu viele Analysen. Bitte warte etwas oder trage die Angaben selbst ein.', 429);
        // Eingaben prüfen, bevor die Aufgabe verbraucht wird
        $img = null;
        if ($mode === 'describe') {
            $text = trim(sanitize_textarea_field((string) $req->get_param('text')));
            if (mb_strlen($text) < 10) return self::error('tix_invalid_field', 'Beschreibe dein Event in ein paar Worten (Titel, Datum, Ort).', 400, ['field' => 'text']);
            if (mb_strlen($text) > 4000) return self::error('tix_invalid_field', 'Bitte höchstens 4000 Zeichen.', 400, ['field' => 'text']);
        } elseif ($mode === 'flyer') {
            $img = self::read_image($req);
            if (is_wp_error($img)) return $img;
        } else {
            $url = trim((string) $req->get_param('url'));
            if ($url === '' || !class_exists('TIX_Event_Import') || TIX_Event_Import::clean_url($url) === '') return self::error('url_invalid', 'Bitte eine vollständige Adresse eingeben, z. B. https://deine-seite.de/event.', 400, ['field' => 'url']);
        }
        $bot = self::check_bot($req);
        if (is_wp_error($bot)) return $bot;
        if (!self::ai_budget_ok()) return self::error('tix_ai_budget', 'Die automatische Analyse ist für heute ausgelastet – bitte trage die Angaben selbst ein.', 429);

        $image_id = 0;
        if ($mode === 'describe') {
            $f = TIX_AI_Writer::extract_text($text);
            $source = 'ai';
        } elseif ($mode === 'flyer') {
            $image_id = self::store_tmp_image($img[0], $img[1]);
            $f = TIX_AI_Writer::extract_image(base64_encode($img[0]), $img[1]);
            $source = 'ai';
        } else {
            $f = TIX_AI_Writer::import_url($url);
            $source = is_wp_error($f) ? '' : (string) ($f['_source'] ?? 'ai');
            if (!is_wp_error($f) && !empty($f['suggested_image_id'])) {
                $image_id = intval($f['suggested_image_id']);
                if (!get_post_field('post_parent', $image_id)) update_post_meta($image_id, self::TMP_META, time());
            }
        }
        if (is_wp_error($f)) {
            $code = $f->get_error_code();
            $map = ['url_invalid' => 400, 'url_unreadable' => 422, 'url_blocked' => 422, 'ai_unavailable' => 503];
            if (!isset($map[$code])) $code = 'ai_unavailable';
            $d = (array) $f->get_error_data();
            // Gesperrte Seite (1.38.507): read_mode blocked + notice
            $extra = $code === 'url_blocked' ? ['read_mode' => 'blocked', 'notice' => (string) ($d['notice'] ?? TIX_Event_Import::BLOCKED_NOTICE), 'reason' => (string) ($d['reason'] ?? '')] : [];
            return self::error($code, $f->get_error_message(), $map[$code], ['alternatives' => array_values(array_diff(['describe', 'flyer', 'url'], [$mode])), 'image' => self::image_payload($image_id)] + $extra);
        }
        // Wie die Seite gelesen wurde (1.38.502, nur mode=url): direct | rendered | partial, Hinweis, gelesene Seiten
        $read = ['read_mode' => $mode === 'url' ? (string) ($f['_read_mode'] ?? 'direct') : null, 'notice' => $mode === 'url' ? ($f['_notice'] ?? null) : null,
            'pages_read' => $mode === 'url' ? intval($f['_pages_read'] ?? 1) : null];
        unset($f['_source'], $f['suggested_image_id'], $f['suggested_image_url'], $f['_read_mode'], $f['_notice'], $f['_pages_read']);
        $ids = array_map('intval', (array) ($f['category_ids'] ?? []));
        $term = $ids ? get_term($ids[0], 'event_category') : null;
        $f['event_category'] = ($term && !is_wp_error($term)) ? (string) $term->slug : '';
        $missing = [];
        foreach (['title', 'date_start', 'time_start', 'location'] as $k) if (trim((string) ($f[$k] ?? '')) === '') $missing[] = $k;
        return self::nocache(['ok' => true, 'mode' => $mode, 'source' => $source, 'fields' => $f, 'missing' => $missing, 'image' => self::image_payload($image_id)] + $read);
    }

    /**
     * Stammdaten bei der Registrierung (optional, 1.38.456) vorab prüfen – damit nach dem Anlegen nichts mehr scheitert.
     * profile {phone, email, website, address, city, tagline, short_desc, description, social{…}}, billing {company, name, street, zip, city, country},
     * tax_status, vat_id, tax_number, payout {holder, iban, bic}, logo (Base64 bzw. data-URL, JPG/PNG/WebP ≤ 5 MB).
     * @return array|WP_Error bereinigte Teile
     */
    private static function check_master(array $p) {
        $out = [];
        if (isset($p['profile'])) {
            if (!is_array($p['profile'])) return self::error('tix_invalid_field', 'Ungültige Profil-Angaben.', 400, ['field' => 'profile']);
            $pr = array_intersect_key($p['profile'], array_flip(['phone', 'email', 'website', 'address', 'city', 'tagline', 'short_desc', 'description', 'social']));
            if (isset($pr['email']) && (string) $pr['email'] !== '' && !is_email((string) $pr['email'])) return self::error('tix_invalid_field', 'Bitte eine gültige Kontakt-E-Mail angeben.', 400, ['field' => 'profile.email']);
            if (isset($pr['phone']) && mb_strlen((string) $pr['phone']) > 40) return self::error('tix_invalid_field', 'Die Telefonnummer ist zu lang.', 400, ['field' => 'profile.phone']);
            if (isset($pr['social']) && !is_array($pr['social'])) return self::error('tix_invalid_field', 'Ungültige Social-Links.', 400, ['field' => 'profile.social']);
            if (isset($pr['website']) && (string) $pr['website'] !== '') { $pr['social'] = (array) ($pr['social'] ?? []) + ['website' => (string) $pr['website']]; }
            unset($pr['website']);
            $out['profile'] = $pr;
        }
        $pay = [];
        if (isset($p['billing'])) {
            if (!is_array($p['billing'])) return self::error('tix_invalid_field', 'Ungültige Rechnungsadresse.', 400, ['field' => 'billing']);
            $pay['billing'] = array_intersect_key($p['billing'], array_flip(['company', 'name', 'street', 'zip', 'city', 'country']));
            $cc = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($pay['billing']['country'] ?? '')));
            if ($cc !== '' && strlen($cc) !== 2) return self::error('tix_invalid_field', 'Bitte den Ländercode mit zwei Buchstaben angeben (z. B. DE).', 400, ['field' => 'billing.country']);
        }
        if (isset($p['tax_status']) && (string) $p['tax_status'] !== '') {
            $ts = (string) $p['tax_status'];
            if (!class_exists('TIX_Payout_Details') || !in_array($ts, TIX_Payout_Details::TAX_STATUS, true)) return self::error('tix_invalid_field', 'Steuer-Status: vat_id, tax_number oder small_business.', 400, ['field' => 'tax_status']);
            $pay['tax_status'] = $ts;
            $vat = strtoupper(preg_replace('/\s+/', '', (string) ($p['vat_id'] ?? '')));
            if ($ts === 'vat_id' && !preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $vat)) return self::error('tix_invalid_field', 'Bitte eine gültige USt-IdNr. angeben (z. B. DE123456789).', 400, ['field' => 'vat_id']);
            if ($ts === 'tax_number' && trim((string) ($p['tax_number'] ?? '')) === '') return self::error('tix_invalid_field', 'Bitte die Steuernummer angeben.', 400, ['field' => 'tax_number']);
            if ($vat !== '') $pay['vat_id'] = $vat;
            if (isset($p['tax_number'])) $pay['tax_number'] = (string) $p['tax_number'];
        }
        if (isset($p['payout'])) {
            if (!is_array($p['payout'])) return self::error('tix_invalid_field', 'Ungültige Auszahlungsdaten.', 400, ['field' => 'payout']);
            if (isset($p['payout']['holder'])) $pay['holder'] = (string) $p['payout']['holder'];
            if (isset($p['payout']['bic'])) {
                $bic = strtoupper(preg_replace('/\s+/', '', (string) $p['payout']['bic']));
                if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) return self::error('tix_invalid_field', 'Bitte eine gültige BIC angeben oder das Feld leer lassen.', 400, ['field' => 'payout.bic']);
                $pay['bic'] = $bic;
            }
            if (isset($p['payout']['iban']) && trim((string) $p['payout']['iban']) !== '') {
                if (!class_exists('TIX_Payout_Details') || !TIX_Payout_Details::iban_valid(TIX_Payout_Details::normalize_iban($p['payout']['iban']))) return self::error('tix_invalid_iban', 'Die IBAN ist ungültig. Bitte prüfe sie noch einmal.', 400, ['field' => 'payout.iban']);
                $pay['iban'] = (string) $p['payout']['iban'];
            }
        }
        if ($pay) {
            if (!class_exists('TIX_Payout_Details') || !TIX_Payout_Details::enabled()) return self::error('tix_invalid_field', 'Rechnungs- und Auszahlungsdaten gibt es nur auf der Plattform.', 400, ['field' => isset($pay['billing']) ? 'billing' : (isset($pay['tax_status']) ? 'tax_status' : 'payout')]);
            $out['payout'] = $pay;
        }
        if (isset($p['logo']) && (string) $p['logo'] !== '') {
            $raw = preg_replace('#^data:image/[a-z]+;base64,#i', '', (string) $p['logo']);
            $bytes = base64_decode($raw, true);
            if ($bytes === false || $bytes === '') return self::error('tix_invalid_field', 'Das Logo konnte nicht gelesen werden.', 400, ['field' => 'logo']);
            if (strlen($bytes) > 5 * MB_IN_BYTES) return self::error('tix_too_large', 'Das Logo ist zu groß (höchstens 5 MB).', 413, ['field' => 'logo']);
            $info = @getimagesizefromstring($bytes);
            if (!is_array($info) || !in_array((string) $info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) return self::error('tix_invalid_field', 'Bitte ein JPG-, PNG- oder WebP-Logo wählen.', 400, ['field' => 'logo']);
            if ($info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) return self::error('tix_invalid_field', 'Das Logo muss zwischen 16 und 6000 Pixel groß sein.', 400, ['field' => 'logo']);
            $out['logo'] = $bytes;
        }
        return $out;
    }

    /** POST /register/organizer */
    public static function rest_organizer(WP_REST_Request $req) {
        if (class_exists('TIX_Sales') && TIX_Sales::is_sales_user()) return self::error('rest_forbidden', 'Mit einem Vertriebs-Zugang kann kein eigener Veranstalter angelegt werden.', 403);
        if (self::limited('organizer', 3, HOUR_IN_SECONDS) || self::limited('organizer_day', 10, DAY_IN_SECONDS)) {
            return self::error('tix_rate_limit', 'Zu viele Registrierungen von diesem Anschluss. Bitte versuche es später noch einmal.', 429);
        }
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : $req->get_params();
        // Vorschau des Vertriebs übernehmen (1.38.510; seit 1.38.514 Paket → neuer Veranstalter + Event-Entwürfe aus dem Paket)
        $claim_oid = 0;
        $claim = is_scalar($p['claim_token'] ?? null) ? trim((string) $p['claim_token']) : '';
        if ($claim !== '') {
            if (!class_exists('TIX_Sales_Previews')) return self::error('tix_preview_unknown', 'Diese Vorschau gibt es nicht (mehr).', 404);
            $claim_oid = TIX_Sales_Previews::claim_target($claim);
            if (is_wp_error($claim_oid)) return $claim_oid;
        }
        // „Erst Konto anlegen, Event später“ (1.38.456)
        $without = $claim_oid || (!empty($p['without_event']) && filter_var($p['without_event'], FILTER_VALIDATE_BOOLEAN));
        $event = $without ? [] : ($p['event'] ?? ($p['event_data'] ?? []));
        if (!$without) {
            if (!is_array($event)) return self::error('tix_invalid_field', 'Event-Angaben fehlen.', 400, ['field' => 'event']);
            if (trim((string) ($event['title'] ?? '')) === '') return self::error('tix_invalid_field', 'Bitte gib deinem Event einen Titel.', 400, ['field' => 'event.title']);
        }
        $in = ['event_data' => $event, 'without_event' => $without];
        foreach (['first_name', 'last_name', 'email', 'password', 'organizer_name', 'accept_contract', 'ticket_mode', 'box_office_price', 'external_url', 'external_price'] as $k) {
            $in[$k] = is_scalar($p[$k] ?? null) ? (string) $p[$k] : '';
        }
        // Studio fragt das Passwort nur einmal ab (Wiederholung optional)
        $in['password_confirm'] = isset($p['password_confirm']) ? (string) $p['password_confirm'] : $in['password'];
        if ($claim_oid && trim($in['organizer_name']) === '') $in['organizer_name'] = TIX_Sales_Previews::claim_name($claim_oid);
        $master = self::check_master($p);
        if (is_wp_error($master)) return $master;
        // Telefon für Rückfragen bei der Prüfung (privat, nicht im Profil; 1.38.457)
        $phone = trim(sanitize_text_field((string) ($p['phone'] ?? '')));
        if (mb_strlen($phone) > 40 || ($phone !== '' && !preg_match('/^[0-9 +()\/.\-]{4,40}$/', $phone))) return self::error('tix_invalid_field', 'Bitte eine gültige Telefonnummer angeben.', 400, ['field' => 'phone']);
        $bot = self::check_bot($req);
        if (is_wp_error($bot)) return $bot;

        if ($claim_oid) {
            // erneut prüfen (zwischen Prüfung und Anlegen könnte jemand anders übernommen haben)
            $again = TIX_Sales_Previews::claim_target($claim);
            if (is_wp_error($again)) return $again;
        }
        $r = TIX_Register_Event::register($in);
        if (is_wp_error($r)) return $r;
        // Paket → echte Event-Entwürfe am neuen Veranstalter (1.38.514)
        $claimed_events = ($claim_oid && $r['org_id']) ? TIX_Sales_Previews::complete_claim($claim_oid, $r['user_id'], $r['org_id']) : null;

        // Bild aus der Analyse (vorläufiger Anhang) wird Event-Bild
        $img = intval($p['image_id'] ?? 0);
        if ($r['event_id'] && $img && get_post_type($img) === 'attachment' && intval(get_post_meta($img, self::TMP_META, true)) > time() - DAY_IN_SECONDS && !get_post_field('post_parent', $img)) {
            wp_update_post(['ID' => $img, 'post_parent' => $r['event_id'], 'post_author' => $r['user_id']]);
            delete_post_meta($img, self::TMP_META);
            set_post_thumbnail($r['event_id'], $img);
        }
        if ($r['org_id'] && $phone !== '') update_post_meta($r['org_id'], '_tix_org_review_phone', $phone);
        // Stammdaten (vorab geprüft); die neue IBAN bestätigt der Inhaber mit dem Code aus der Mail (POST /organizer/payout-details/confirm)
        $saved = ['profile' => false, 'payout' => null, 'logo' => null];
        $warnings = [];
        if ($r['org_id'] && $master) {
            $prev = get_current_user_id();
            wp_set_current_user($r['user_id']);
            if (!empty($master['profile']) && class_exists('TIX_App_Scope')) {
                $sp = TIX_App_Scope::save_profile($r['org_id'], $master['profile']);
                if (is_wp_error($sp)) $warnings[] = ['field' => 'profile', 'message' => $sp->get_error_message()]; else $saved['profile'] = true;
            }
            if (!empty($master['payout'])) {
                $pd = TIX_Payout_Details::save($r['org_id'], $master['payout']);
                if (is_wp_error($pd)) $warnings[] = ['field' => (string) ($pd->get_error_data()['field'] ?? 'payout'), 'message' => $pd->get_error_message()];
                else $saved['payout'] = ['complete' => (bool) $pd['complete'], 'verify_required' => !empty($pd['verify_required']), 'code_sent_to' => (string) ($pd['code_sent_to'] ?? '')];
            }
            if (!empty($master['logo']) && class_exists('TIX_App_Scope')) {
                $lg = TIX_App_Scope::store_profile_image($r['org_id'], 'logo', $master['logo'], 'logo');
                if (is_wp_error($lg)) $warnings[] = ['field' => 'logo', 'message' => $lg->get_error_message()]; else $saved['logo'] = (string) (wp_get_attachment_image_url(intval($lg), 'medium') ?: wp_get_attachment_url(intval($lg)));
            }
            wp_set_current_user($prev);
        }
        $user = get_user_by('id', $r['user_id']);
        $token = TIX_REST_API::issue_app_token($r['user_id'], (string) ($p['device'] ?? '') ?: 'evendis Studio');
        $out = [
            'ok'           => true,
            'success'      => true,
            'token'        => $token,
            'user'         => TIX_REST_API::guest_user_payload($user),
            'organizer_id' => $r['org_id'],
            'event_id'     => intval($r['event_id']),
            'without_event' => $without,
            'review'       => $r['review'],
            'saved'        => $saved,
            'warnings'     => $warnings,
        ];
        if ($claimed_events !== null) {
            // Übernommene Vorschau: Events liegen als Entwürfe im Studio
            $out['claimed'] = true;
            $out['claimed_events'] = intval($claimed_events);
            $out['heading'] = $r['review'] ? 'Geschafft! Wir prüfen dein Konto.' : 'Geschafft! Dein Konto ist angelegt.';
            $out['message'] = 'Dein Veranstalter-Konto ist angelegt. ' . ($claimed_events ? sprintf(_n('Dein Event aus der Vorschau liegt als Entwurf im Studio.', 'Deine %d Events aus der Vorschau liegen als Entwürfe im Studio.', $claimed_events), $claimed_events) . ' ' : '')
                . ($r['review'] ? 'Online gehen sie, sobald wir dein Konto freigeschaltet haben.' : 'Prüfe sie und veröffentliche sie, wenn alles passt.');
            $out['event_url'] = '';
        } elseif ($without) {
            $out['heading'] = $r['review'] ? 'Geschafft! Wir prüfen dein Konto.' : 'Geschafft! Dein Konto ist angelegt.';
            $out['message'] = $r['review']
                ? 'Dein Veranstalter-Konto ist angelegt. Dein erstes Event kannst du jederzeit im Studio anlegen – online geht es, sobald wir dein Konto freigeschaltet haben. Auszahlungsdaten kannst du auch danach ergänzen.'
                : 'Dein Veranstalter-Konto ist angelegt. Lege jetzt im Studio dein erstes Event an.';
            $out['event_url'] = '';
        } else {
            $out['heading'] = $r['review'] ? 'Geschafft! Wir prüfen dein Konto.' : 'Geschafft! Dein Event ist online.';
            $out['message'] = $r['review']
                ? 'Dein Event „' . $r['event_title'] . '“ ist angelegt und geht online, sobald wir dein Konto freigeschaltet haben. Rechnungsadresse, Steuer und Auszahlungsdaten kannst du auch nach der Freischaltung ergänzen.'
                : 'Dein Event „' . $r['event_title'] . '“ wurde veröffentlicht.';
            $out['event_url'] = $r['review'] ? '' : (string) get_permalink($r['event_id']);
        }
        if (class_exists('TIX_Org_Approval') && $r['org_id'] && TIX_Org_Approval::multi()) $out['approval'] = TIX_Org_Approval::payload($r['org_id'], $r['user_id']);
        return self::nocache($out, 201);
    }

    // ── Weiterleitung, Aufräumen ──

    public static function maybe_redirect() {
        if (get_option('tix_studio_register') !== '1' || !class_exists('TIX_Studio') || !TIX_Studio::active()) return;
        if (!is_singular('page') || isset($_GET['classic'])) return;
        $post = get_queried_object();
        if (!$post || strpos((string) $post->post_content, '[tix_register_event') === false) return;
        $q = [];
        foreach ($_GET as $k => $v) if (is_scalar($v)) $q[sanitize_key($k)] = sanitize_text_field(wp_unslash($v));
        wp_redirect(add_query_arg(array_map('rawurlencode', $q), TIX_Studio::url('/registrieren/')), 302);
        exit;
    }

    /** Täglich: vorläufige Flyer-Anhänge, die keiner Registrierung zugeordnet wurden, nach 24 h löschen. */
    public static function cleanup() {
        $old = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => [['key' => self::TMP_META, 'value' => time() - DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC']]]);
        global $wpdb;
        $n = 0;
        foreach ($old as $id) {
            if (get_post_field('post_parent', $id)) { delete_post_meta($id, self::TMP_META); continue; }
            // inzwischen als Beitragsbild in Verwendung (z. B. ohne image_id gesetzt) → behalten (1.38.536)
            if ($wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1", (string) intval($id)))) { delete_post_meta($id, self::TMP_META); continue; }
            wp_delete_attachment($id, true);
            $n++;
        }
        return $n;
    }
}
