<?php
if (!defined('ABSPATH')) exit;

/**
 * Auszahlungsdaten der Veranstalter (Sammelkonto evendis, nur Mehr-Veranstalter-Modus).
 *
 * Gespeichert am Veranstalter (`tix_organizer`):
 *   - `_tix_payout_data`    Kontoinhaber, BIC, Rechnungsadresse, Steuer-Status, Abrechnungs-E-Mail
 *   - `_tix_payout_iban`    IBAN, verschlüsselt (AES-256-GCM, Schlüssel aus den WP-Salts)
 *   - `_tix_payout_pending` neue IBAN wartet auf den Bestätigungscode (verschlüsselt, Code nur als Hash)
 *
 * Jede neue oder geänderte IBAN wird erst nach Eingabe eines 6-stelligen Codes aktiv, der an die
 * Konto-E-Mail des Inhabers geht; danach Hinweis-Mail „Auszahlungsdaten geändert“. Die volle IBAN
 * verlässt den Server nur in der Admin-Kopierhilfe und in der Abrechnung (PDF an den Veranstalter).
 *
 * REST (Inhaber/Team-Admin, nur Mehr-Veranstalter-Modus):
 *   GET/POST /organizer/payout-details, POST /organizer/payout-details/confirm, POST …/resend
 */
class TIX_Payout_Details {

    const NS           = 'tixomat/v1';
    const META_DATA    = '_tix_payout_data';
    const META_IBAN    = '_tix_payout_iban';
    const META_PENDING = '_tix_payout_pending';
    const CODE_TTL     = 15 * MINUTE_IN_SECONDS;
    const CODE_TRIES   = 5;
    const TAX_STATUS   = ['vat_id', 'tax_number', 'small_business'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function enabled() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ──────────────────────────────────────────
    //  Berechtigung (auch für Abrechnungs- und Gebühren-Routen)
    // ──────────────────────────────────────────

    /** Mehr-Veranstalter-Modus + Inhaber/Team-Admin eines freigegebenen Veranstalters. */
    public static function check_perm(WP_REST_Request $req) {
        if (!self::enabled()) {
            return new WP_Error('tix_not_multi', 'Nur auf Plattformen mit mehreren Veranstaltern verfügbar.', ['status' => 403]);
        }
        return TIX_App_Scope::check_manager($req);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/organizer/payout-details', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'rest_get'],  'permission_callback' => [__CLASS__, 'check_perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => [__CLASS__, 'check_perm']],
        ]);
        register_rest_route(self::NS, '/organizer/payout-details/confirm', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_confirm'], 'permission_callback' => [__CLASS__, 'check_perm'],
        ]);
        register_rest_route(self::NS, '/organizer/payout-details/resend', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_resend'], 'permission_callback' => [__CLASS__, 'check_perm'],
        ]);
    }

    // ──────────────────────────────────────────
    //  Verschlüsselung, IBAN
    // ──────────────────────────────────────────

    private static function crypto_key() {
        $extra = defined('TIX_PAYOUT_KEY') ? (string) TIX_PAYOUT_KEY : '';
        return hash('sha256', 'tix-payout-iban|' . $extra . '|' . wp_salt('secure_auth'), true);
    }

    public static function encrypt($plain) {
        if (!function_exists('openssl_encrypt')) return '';
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt((string) $plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return '';
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt($enc) {
        if (!is_string($enc) || strpos($enc, 'v1:') !== 0 || !function_exists('openssl_decrypt')) return '';
        $bin = base64_decode(substr($enc, 3), true);
        if ($bin === false || strlen($bin) < 29) return '';
        $pt = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $pt === false ? '' : $pt;
    }

    public static function normalize_iban($iban) {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $iban));
    }

    /** Prüfziffer (ISO 13616, mod 97) und Länge je Land (bekannte SEPA-Länder). */
    public static function iban_valid($iban) {
        $iban = self::normalize_iban($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) return false;
        $len = ['DE' => 22, 'AT' => 20, 'CH' => 21, 'NL' => 18, 'BE' => 16, 'LU' => 20, 'FR' => 27, 'IT' => 27,
                'ES' => 24, 'PT' => 25, 'PL' => 28, 'CZ' => 24, 'DK' => 18, 'SE' => 24, 'FI' => 18, 'IE' => 22,
                'GB' => 22, 'LI' => 21, 'NO' => 15, 'HR' => 21, 'SI' => 19, 'SK' => 24, 'HU' => 28, 'GR' => 27];
        $cc = substr($iban, 0, 2);
        if (isset($len[$cc]) && strlen($iban) !== $len[$cc]) return false;
        $num = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $ch) {
            $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $mod = 0;
        foreach (str_split($num, 7) as $part) {
            $mod = intval($mod . $part) % 97;
        }
        return $mod === 1;
    }

    /** „DE89 •••• •••• •••• 3000“ */
    public static function mask_iban($iban) {
        $iban = self::normalize_iban($iban);
        if ($iban === '') return '';
        return substr($iban, 0, 4) . ' •••• •••• •••• ' . substr($iban, -4);
    }

    /** IBAN in Vierergruppen (Kopierhilfe/PDF). */
    public static function format_iban($iban) {
        return trim(chunk_split(self::normalize_iban($iban), 4, ' '));
    }

    public static function mask_email($email) {
        $email = (string) $email;
        $at = strpos($email, '@');
        if ($at === false || $at < 1) return $email === '' ? '' : '•••';
        return substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    // ──────────────────────────────────────────
    //  Daten lesen/schreiben
    // ──────────────────────────────────────────

    private static function data($oid) {
        $d = get_post_meta(intval($oid), self::META_DATA, true);
        $d = is_array($d) ? $d : [];
        $b = is_array($d['billing'] ?? null) ? $d['billing'] : [];
        return [
            'holder'     => (string) ($d['holder'] ?? ''),
            'bic'        => (string) ($d['bic'] ?? ''),
            'billing'    => [
                'company' => (string) ($b['company'] ?? ''),
                'name'    => (string) ($b['name'] ?? ''),
                'street'  => (string) ($b['street'] ?? ''),
                'zip'     => (string) ($b['zip'] ?? ''),
                'city'    => (string) ($b['city'] ?? ''),
                'country' => (string) (($b['country'] ?? '') ?: 'DE'),
            ],
            'tax_status' => (string) ($d['tax_status'] ?? ''),
            'vat_id'     => (string) ($d['vat_id'] ?? ''),
            'tax_number' => (string) ($d['tax_number'] ?? ''),
            'email'      => (string) ($d['email'] ?? ''),
            'updated'    => (string) ($d['updated'] ?? ''),
        ];
    }

    /** Volle IBAN (nur serverseitig: Kopierhilfe, Abrechnungs-Schnappschuss, SEPA). */
    public static function iban($oid) {
        return self::decrypt((string) get_post_meta(intval($oid), self::META_IBAN, true));
    }

    /** Konto-E-Mail des Inhabers (Empfänger der Bestätigungscodes). */
    public static function owner_email($oid) {
        $uid = intval(get_post_meta(intval($oid), '_tix_org_user_id', true));
        $u = $uid ? get_user_by('id', $uid) : null;
        return $u ? (string) $u->user_email : '';
    }

    /** E-Mail für Abrechnungen (eigene Angabe, sonst Inhaber, sonst Veranstalter-Kontakt). */
    public static function billing_email($oid) {
        $d = self::data($oid);
        if ($d['email'] !== '') return $d['email'];
        return self::owner_email($oid) ?: (string) get_post_meta(intval($oid), '_tix_org_email', true);
    }

    /** Sind die Auszahlungsdaten vollständig? */
    public static function complete($oid) {
        $d = self::data($oid);
        $b = $d['billing'];
        if ($d['holder'] === '' || self::iban($oid) === '') return false;
        if (($b['company'] === '' && $b['name'] === '') || $b['street'] === '' || $b['zip'] === '' || $b['city'] === '') return false;
        if (!in_array($d['tax_status'], self::TAX_STATUS, true)) return false;
        if ($d['tax_status'] === 'vat_id' && $d['vat_id'] === '') return false;
        if ($d['tax_status'] === 'tax_number' && $d['tax_number'] === '') return false;
        return true;
    }

    /** Antwort für App und Web (volle IBAN nie). */
    public static function payload($oid) {
        $d = self::data($oid);
        $iban = self::iban($oid);
        $pending = get_post_meta(intval($oid), self::META_PENDING, true);
        $has_pending = is_array($pending) && !empty($pending['iban']) && intval($pending['expires'] ?? 0) > time();
        return [
            'complete'      => self::complete($oid),
            'holder'        => $d['holder'],
            'iban_masked'   => self::mask_iban($iban),
            'iban_last4'    => $iban !== '' ? substr($iban, -4) : '',
            'has_iban'      => $iban !== '',
            'bic'           => $d['bic'],
            'billing'       => $d['billing'],
            'tax_status'    => $d['tax_status'],
            'vat_id'        => $d['vat_id'],
            'tax_number'    => $d['tax_number'],
            'email'         => $d['email'],
            'pending_iban'  => $has_pending,
            'pending_email' => $has_pending ? self::mask_email((string) ($pending['sent_to'] ?? '')) : '',
        ];
    }

    private static function field_error($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]);
    }

    /**
     * Speichert alles außer einer neuen IBAN sofort; eine neue IBAN wartet auf den Code.
     * @return array|WP_Error  payload (+ verify_required, code_sent_to)
     */
    public static function save($oid, array $b) {
        $oid = intval($oid);
        $d = self::data($oid);
        $old = $d;

        $text = function ($v, $max = 120) { return mb_substr(sanitize_text_field((string) $v), 0, $max); };
        if (array_key_exists('holder', $b)) $d['holder'] = $text($b['holder'], 70);
        if (array_key_exists('bic', $b)) {
            $bic = strtoupper(preg_replace('/\s+/', '', (string) $b['bic']));
            if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) return self::field_error('bic', 'Bitte eine gültige BIC angeben oder das Feld leer lassen.');
            $d['bic'] = $bic;
        }
        if (isset($b['billing']) && is_array($b['billing'])) {
            foreach (['company', 'name', 'street', 'zip', 'city'] as $k) {
                if (array_key_exists($k, $b['billing'])) $d['billing'][$k] = $text($b['billing'][$k]);
            }
            if (array_key_exists('country', $b['billing'])) {
                $cc = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $b['billing']['country']));
                if ($cc !== '' && strlen($cc) !== 2) return self::field_error('country', 'Bitte den Ländercode mit zwei Buchstaben angeben (z. B. DE).');
                $d['billing']['country'] = $cc ?: 'DE';
            }
        }
        if (array_key_exists('tax_status', $b)) {
            $ts = (string) $b['tax_status'];
            if ($ts !== '' && !in_array($ts, self::TAX_STATUS, true)) return self::field_error('tax', 'Bitte den Steuer-Status wählen.');
            $d['tax_status'] = $ts;
        }
        if (array_key_exists('vat_id', $b)) {
            $vat = strtoupper(preg_replace('/\s+/', '', (string) $b['vat_id']));
            if ($vat !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $vat)) return self::field_error('vat_id', 'Bitte eine gültige USt-IdNr. angeben (z. B. DE123456789).');
            $d['vat_id'] = $vat;
        }
        if (array_key_exists('tax_number', $b)) $d['tax_number'] = $text($b['tax_number'], 40);
        if ($d['tax_status'] === 'vat_id' && $d['vat_id'] === '') return self::field_error('vat_id', 'Bitte die USt-IdNr. angeben.');
        if ($d['tax_status'] === 'tax_number' && $d['tax_number'] === '') return self::field_error('tax_number', 'Bitte die Steuernummer angeben.');
        if (array_key_exists('email', $b)) {
            $em = sanitize_email((string) $b['email']);
            if ((string) $b['email'] !== '' && !is_email($em)) return self::field_error('email', 'Bitte eine gültige E-Mail-Adresse angeben.');
            $d['email'] = $em;
        }

        // IBAN: gleich → nichts zu tun; neu → Code an den Inhaber
        $new_iban = '';
        if (array_key_exists('iban', $b) && trim((string) $b['iban']) !== '') {
            $iban = self::normalize_iban($b['iban']);
            if (!self::iban_valid($iban)) {
                return new WP_Error('tix_invalid_iban', 'Die IBAN ist ungültig. Bitte prüfe sie noch einmal.', ['status' => 400, 'field' => 'iban']);
            }
            if ($iban !== self::iban($oid)) $new_iban = $iban;
        }
        if ($new_iban !== '' && self::owner_email($oid) === '') {
            return new WP_Error('tix_no_owner', 'Für diesen Veranstalter ist kein Inhaber-Konto hinterlegt. Bitte wende dich an den Support.', ['status' => 409]);
        }

        $changed = $d !== $old;
        if ($changed) {
            $d['updated'] = current_time('mysql');
            update_post_meta($oid, self::META_DATA, $d);
        }

        $out = [];
        if ($new_iban !== '') {
            $sent = self::issue_code($oid, $new_iban);
            if (is_wp_error($sent)) return $sent;
            $out = ['verify_required' => true, 'code_sent_to' => self::mask_email($sent)];
        } elseif ($changed) {
            self::notify_changed($oid, false);
        }
        self::after_change($oid);
        return self::payload($oid) + $out;
    }

    private static function code_hash($oid, $code) {
        return hash_hmac('sha256', intval($oid) . '|' . (string) $code, wp_salt('auth'));
    }

    /** Code erzeugen + an den Inhaber senden; liefert die Empfänger-Adresse. */
    private static function issue_code($oid, $iban) {
        $to = self::owner_email($oid);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('payout_code', 5, HOUR_IN_SECONDS, 'o' . intval($oid))) {
            return new WP_Error('tix_rate_limited', 'Zu viele Codes angefordert. Bitte versuche es in einer Stunde erneut.', ['status' => 429]);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        update_post_meta(intval($oid), self::META_PENDING, [
            'iban'    => self::encrypt($iban),
            'hash'    => self::code_hash($oid, $code),
            'tries'   => 0,
            'expires' => time() + self::CODE_TTL,
            'sent_to' => $to,
            'by'      => get_current_user_id(),
            'created' => time(),
        ]);
        $org  = get_the_title(intval($oid));
        $body = '<p>Für <strong>' . esc_html($org) . '</strong> wurde eine neue Bankverbindung für Auszahlungen eingegeben '
              . '(IBAN ' . esc_html(self::mask_iban($iban)) . ').</p>'
              . '<p>Dein Bestätigungscode:</p>'
              . '<p style="font-size:28px;font-weight:700;letter-spacing:6px;margin:12px 0;">' . esc_html($code) . '</p>'
              . '<p>Der Code gilt 15 Minuten. Wenn du das nicht warst, gib den Code nicht weiter und antworte auf diese E-Mail.</p>';
        self::mail($to, 'Bestätigungscode für deine Auszahlungsdaten', 'Bankverbindung bestätigen', $body);
        return $to;
    }

    /** @return array|WP_Error */
    public static function confirm($oid, $code) {
        $oid = intval($oid);
        $p = get_post_meta($oid, self::META_PENDING, true);
        if (!is_array($p) || empty($p['iban'])) {
            return new WP_Error('tix_code_expired', 'Es wartet keine Änderung auf Bestätigung. Bitte gib die IBAN erneut ein.', ['status' => 410]);
        }
        if (intval($p['expires'] ?? 0) < time()) {
            delete_post_meta($oid, self::META_PENDING);
            return new WP_Error('tix_code_expired', 'Der Code ist abgelaufen. Bitte gib die IBAN erneut ein oder fordere einen neuen Code an.', ['status' => 410]);
        }
        if (intval($p['tries'] ?? 0) >= self::CODE_TRIES) {
            return new WP_Error('tix_code_locked', 'Zu viele falsche Versuche. Bitte fordere einen neuen Code an.', ['status' => 429]);
        }
        $code = preg_replace('/\D/', '', (string) $code);
        if (strlen($code) !== 6 || !hash_equals((string) $p['hash'], self::code_hash($oid, $code))) {
            $p['tries'] = intval($p['tries'] ?? 0) + 1;
            update_post_meta($oid, self::META_PENDING, $p);
            $remaining = max(0, self::CODE_TRIES - $p['tries']);
            if ($remaining === 0) {
                return new WP_Error('tix_code_locked', 'Zu viele falsche Versuche. Bitte fordere einen neuen Code an.', ['status' => 429]);
            }
            return new WP_Error('tix_code_invalid', 'Der Code stimmt nicht.', ['status' => 400, 'remaining' => $remaining]);
        }
        $iban = self::decrypt((string) $p['iban']);
        if ($iban === '') return new WP_Error('tix_code_expired', 'Die Änderung ist nicht mehr gültig. Bitte gib die IBAN erneut ein.', ['status' => 410]);
        update_post_meta($oid, self::META_IBAN, self::encrypt($iban));
        delete_post_meta($oid, self::META_PENDING);
        $d = get_post_meta($oid, self::META_DATA, true);
        $d = is_array($d) ? $d : [];
        $d['updated'] = current_time('mysql');
        update_post_meta($oid, self::META_DATA, $d);
        self::notify_changed($oid, true);
        self::after_change($oid);
        return self::payload($oid);
    }

    /** @return array|WP_Error */
    public static function resend($oid) {
        $oid = intval($oid);
        $p = get_post_meta($oid, self::META_PENDING, true);
        if (!is_array($p) || empty($p['iban'])) {
            return new WP_Error('tix_code_expired', 'Es wartet keine Änderung auf Bestätigung. Bitte gib die IBAN erneut ein.', ['status' => 410]);
        }
        $iban = self::decrypt((string) $p['iban']);
        if ($iban === '') return new WP_Error('tix_code_expired', 'Bitte gib die IBAN erneut ein.', ['status' => 410]);
        $sent = self::issue_code($oid, $iban);
        if (is_wp_error($sent)) return $sent;
        return ['code_sent_to' => self::mask_email($sent)];
    }

    /** Hinweis-Mail an Inhaber (+ Abrechnungs-E-Mail, falls anders). */
    private static function notify_changed($oid, $iban_changed) {
        $org = get_the_title(intval($oid));
        $d = self::data($oid);
        $body = '<p>Die Auszahlungsdaten für <strong>' . esc_html($org) . '</strong> wurden am '
              . esc_html(wp_date('d.m.Y \u\m H:i')) . ' Uhr geändert.</p>';
        if ($iban_changed) {
            $body .= '<p>Neue Bankverbindung: ' . esc_html(self::mask_iban(self::iban($oid))) . ' (' . esc_html($d['holder']) . ')</p>';
        }
        $body .= '<p>Wenn du das nicht warst, melde dich bitte sofort bei uns – Auszahlungen halten wir dann an.</p>';
        $to = array_unique(array_filter([self::owner_email($oid), $d['email']]));
        foreach ($to as $addr) {
            self::mail($addr, 'Deine Auszahlungsdaten wurden geändert', 'Auszahlungsdaten geändert', $body);
        }
    }

    /** Nach jeder Änderung: wartende Abrechnungen (Entwurf) ggf. freigeben. */
    private static function after_change($oid) {
        if (class_exists('TIX_Settlement')) TIX_Settlement::payout_details_changed(intval($oid));
    }

    public static function mail($to, $subject, $heading, $body_html, array $attachments = []) {
        if (!$to) return false;
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html($heading, $body_html, $site)
            : '<html><body>' . $body_html . '</body></html>';
        return wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $attachments);
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    public static function rest_get(WP_REST_Request $req) {
        return rest_ensure_response(self::payload(TIX_App_Scope::organizer_id_for_user()));
    }

    public static function rest_save(WP_REST_Request $req) {
        $b = $req->get_json_params();
        if (!is_array($b)) $b = $req->get_body_params();
        $r = self::save(TIX_App_Scope::organizer_id_for_user(), is_array($b) ? $b : []);
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }

    public static function rest_confirm(WP_REST_Request $req) {
        $r = self::confirm(TIX_App_Scope::organizer_id_for_user(), (string) $req->get_param('code'));
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }

    public static function rest_resend(WP_REST_Request $req) {
        $r = self::resend(TIX_App_Scope::organizer_id_for_user());
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }
}
