<?php
if (!defined('ABSPATH')) exit;

/**
 * Zahlungsanbieter im Studio (1.38.679, Doku §96, Vertrag uebergaben/vertrag-zahlungsanbieter-studio.md).
 *
 * Dieselben Einstellungen wie im WP-Admin (Option tix_settings) – kein zweiter Speicher.
 *   GET  /studio/payments         Stand (Schlüssel nur maskiert)
 *   POST /studio/payments         Teil-Update {order?, mollie?, stripe?, paypal?, bank?}
 *   POST /studio/payments/test    {provider} Verbindungstest mit den gespeicherten Daten, nichts wird gebucht
 * Rechte wie Marke (TIX_Studio::perm_brand). Auf der Plattform (Mehr-Veranstalter-Modus) gesperrt: Zahlungen laufen dort
 * über das evendis-Sammelkonto.
 */
class TIX_Studio_Payments {

    const NS = 'tixomat/v1';
    const PROVIDERS = ['mollie', 'stripe', 'paypal', 'bank'];
    const STATE = 'tix_studio_payments_state'; // {last_test: {provider: {...}}}
    const TEST_LIMIT = 10;                     // Tests je 10 Minuten
    const STRIPE_EVENTS = ['checkout.session.completed', 'checkout.session.expired', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'charge.refunded'];
    const PAYPAL_EVENTS = ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $perm = [__CLASS__, 'perm'];
        register_rest_route(self::NS, '/studio/payments', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/studio/payments/test', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_test'], 'permission_callback' => $perm]);
    }

    public static function perm(WP_REST_Request $req) {
        if (class_exists('TIX_Studio') && method_exists('TIX_Studio', 'perm_brand')) return TIX_Studio::perm_brand($req);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
    }

    public static function locked() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function locked_error() {
        return new WP_Error('tix_payments_locked', 'Auf evendis laufen alle Zahlungen über das evendis-Konto. Deine Auszahlung richtest du unter „Auszahlung“ ein.', ['status' => 403]);
    }

    /* ─────────── Helfer ─────────── */

    /** Frische Einstellungen (tix_get_settings hält einen statischen Zwischenspeicher je Aufruf). */
    private static function settings() {
        $s = get_option('tix_settings', []);
        $d = class_exists('TIX_Settings') && method_exists('TIX_Settings', 'defaults') ? TIX_Settings::defaults() : [];
        return wp_parse_args(is_array($s) ? $s : [], $d);
    }

    private static function hint($v) {
        $v = trim((string) $v);
        return $v === '' ? '' : '…' . substr($v, -4);
    }

    private static function secret($v) {
        return ['key_set' => trim((string) $v) !== '', 'key_hint' => self::hint($v)];
    }

    public static function iban_hint($iban) {
        $i = strtoupper(preg_replace('/\s+/', '', (string) $iban));
        return $i === '' ? '' : substr($i, 0, 4) . ' •••• ' . substr($i, -4);
    }

    public static function iban_valid($iban) {
        $i = strtoupper(preg_replace('/\s+/', '', (string) $iban));
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $i)) return false;
        $r = substr($i, 4) . substr($i, 0, 4);
        $n = '';
        foreach (str_split($r) as $c) $n .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        $mod = 0;
        foreach (str_split($n, 7) as $chunk) $mod = (int) (($mod . $chunk) % 97);
        return $mod === 1;
    }

    private static function order($s) {
        $o = array_values(array_intersect(array_filter(array_map('trim', explode(',', (string) ($s['gateway_provider_order'] ?? '')))), self::PROVIDERS));
        foreach (self::PROVIDERS as $p) if (!in_array($p, $o, true)) $o[] = $p;
        return $o;
    }

    private static function stripe_secret($s) {
        return trim((string) (!empty($s['stripe_test_mode']) ? ($s['stripe_secret_key_test'] ?? '') : ($s['stripe_secret_key_live'] ?? '')));
    }

    private static function paypal_enabled($s) {
        return !array_key_exists('paypal_enabled', $s) || !empty($s['paypal_enabled']);
    }

    /** Wie is_available() der Gateways, aber auf frischen Einstellungen. */
    public static function ready($p, $s) {
        switch ($p) {
            case 'mollie': return (bool) ($s['mollie_enabled'] ?? 1) && trim((string) ($s['mollie_api_key'] ?? '')) !== '';
            case 'stripe': return !empty($s['stripe_enabled']) && self::stripe_secret($s) !== '';
            case 'paypal': return self::paypal_enabled($s) && trim((string) ($s['paypal_client_id'] ?? '')) !== '' && trim((string) ($s['paypal_secret'] ?? '')) !== '';
            case 'bank':   return !empty($s['bank_transfer_enabled']) && trim((string) ($s['bank_iban'] ?? '')) !== '';
        }
        return false;
    }

    private static function state() {
        $st = get_option(self::STATE, []);
        return is_array($st) ? $st : [];
    }

    private static function methods_out($list, $selected, $label_key) {
        $out = [];
        foreach ((array) $list as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') continue;
            $out[] = ['id' => $id, 'label' => (string) ($m[$label_key] ?? $id), 'image' => (string) ($m['image'] ?? ''), 'selected' => empty($selected) || in_array($id, $selected, true)];
        }
        return $out;
    }

    /** Zahlarten beim Anbieter (Zwischenspeicher der Gateways, 6 h). Nur wenn der Anbieter bereit ist. */
    private static function provider_methods($p, $s) {
        if (!self::ready($p, $s)) return [];
        if ($p === 'mollie' && class_exists('TIX_Gateway_Mollie')) return TIX_Gateway_Mollie::get_methods();
        if ($p === 'stripe' && class_exists('TIX_Gateway_Stripe')) return TIX_Gateway_Stripe::get_methods();
        return [];
    }

    /* ─────────── Antwort ─────────── */

    public static function payload($with_methods = true) {
        if (self::locked()) {
            return ['locked' => true, 'reason' => 'Auf evendis laufen alle Zahlungen über das evendis-Konto. Deine Auszahlung richtest du unter „Auszahlung“ ein.'];
        }
        $s = self::settings();
        $tests = (array) (self::state()['last_test'] ?? []);
        $ajax = admin_url('admin-ajax.php');
        $msel = array_values((array) ($s['mollie_enabled_methods'] ?? []));
        $ssel = array_values((array) ($s['stripe_enabled_methods'] ?? []));
        $mkey = trim((string) ($s['mollie_api_key'] ?? ''));

        $providers = [
            'mollie' => [
                'enabled' => (bool) ($s['mollie_enabled'] ?? 1),
                'ready' => self::ready('mollie', $s),
                'key_set' => $mkey !== '',
                'key_hint' => self::hint($mkey),
                'mode' => strpos($mkey, 'test_') === 0 ? 'test' : ($mkey !== '' ? 'live' : ''),
                'test_mode' => !empty($s['mollie_test_mode']),
                'methods' => $with_methods ? self::methods_out(self::provider_methods('mollie', $s), $msel, 'description') : [],
                'methods_selected' => $msel,
                'webhook' => ['auto' => true],
                'last_test' => $tests['mollie'] ?? null,
            ],
            'stripe' => [
                'enabled' => !empty($s['stripe_enabled']),
                'ready' => self::ready('stripe', $s),
                'test_mode' => !empty($s['stripe_test_mode']),
                'secret_live' => self::secret($s['stripe_secret_key_live'] ?? ''),
                'secret_test' => self::secret($s['stripe_secret_key_test'] ?? ''),
                'publishable_live' => (string) ($s['stripe_publishable_key_live'] ?? ''),
                'publishable_test' => (string) ($s['stripe_publishable_key_test'] ?? ''),
                'webhook_secret_live' => self::secret($s['stripe_webhook_secret_live'] ?? ''),
                'webhook_secret_test' => self::secret($s['stripe_webhook_secret_test'] ?? ''),
                'webhook' => ['auto' => false, 'url' => add_query_arg('action', 'tix_stripe_webhook', $ajax), 'events' => self::STRIPE_EVENTS],
                'methods' => $with_methods ? self::methods_out(self::provider_methods('stripe', $s), $ssel, 'label') : [],
                'methods_selected' => $ssel,
                'last_test' => $tests['stripe'] ?? null,
            ],
            'paypal' => [
                'enabled' => self::paypal_enabled($s),
                'ready' => self::ready('paypal', $s),
                'client_id' => (string) ($s['paypal_client_id'] ?? ''),
                'secret' => self::secret($s['paypal_secret'] ?? ''),
                'sandbox' => !empty($s['paypal_sandbox']),
                'webhook_id' => (string) ($s['paypal_webhook_id'] ?? ''),
                'webhook' => ['auto' => false, 'url' => add_query_arg('action', 'tix_paypal_webhook', $ajax), 'events' => self::PAYPAL_EVENTS],
                'last_test' => $tests['paypal'] ?? null,
            ],
            'bank' => [
                'enabled' => !empty($s['bank_transfer_enabled']),
                'ready' => self::ready('bank', $s),
                'holder' => (string) ($s['bank_holder'] ?? ''),
                'iban_set' => trim((string) ($s['bank_iban'] ?? '')) !== '',
                'iban_hint' => self::iban_hint($s['bank_iban'] ?? ''),
                'bic' => (string) ($s['bank_bic'] ?? ''),
                'bank_name' => (string) ($s['bank_name'] ?? ''),
                'reference' => (string) ($s['bank_reference'] ?? ''),
            ],
        ];

        $order = self::order($s);
        $active = array_values(array_filter($order, function ($p) use ($providers) { return $providers[$p]['ready']; }));

        $warnings = [];
        if (!$active && !self::none()) $warnings[] = ['code' => 'tix_no_payment', 'message' => 'Keine Zahlart aktiv – Kunden können nur kostenlose Tickets bestellen.'];
        $test_on = [];
        if ($providers['mollie']['ready'] && $providers['mollie']['mode'] === 'test') $test_on[] = 'Mollie';
        if ($providers['stripe']['ready'] && $providers['stripe']['test_mode']) $test_on[] = 'Stripe';
        if ($providers['paypal']['ready'] && $providers['paypal']['sandbox']) $test_on[] = 'PayPal';
        if ($test_on) $warnings[] = ['code' => 'tix_test_mode', 'message' => implode(', ', $test_on) . ' im Testmodus – echte Zahlungen kommen nicht an.'];
        $wh = [];
        if ($providers['stripe']['ready'] && !$providers['stripe'][$providers['stripe']['test_mode'] ? 'webhook_secret_test' : 'webhook_secret_live']['key_set']) $wh[] = 'Stripe';
        if ($providers['paypal']['ready'] && $providers['paypal']['webhook_id'] === '') $wh[] = 'PayPal';
        if ($wh) $warnings[] = ['code' => 'tix_webhook_missing', 'message' => implode(' und ', $wh) . ': Webhook fehlt – Zahlungen werden nur bestätigt, wenn der Kunde zur Seite zurückkehrt.'];

        // 1.38.742: bewusst „Keine Zahlungen anbieten“ (keine Online-Kasse; Infos, Abendkasse, Vermittlung über evendis)
        return ['locked' => false, 'order' => $order, 'active' => $active, 'providers' => $providers, 'warnings' => $warnings, 'none' => self::none(), 'state' => self::state_of(!empty($active))];
    }

    const OPT_NONE = 'tix_payments_none';

    /** Bewusst „Keine Zahlungen anbieten“ gewählt? (1.38.742) */
    public static function none() {
        return get_option(self::OPT_NONE, '0') === '1';
    }

    /** Ist ein Anbieter einsatzbereit? */
    public static function provider_ready() {
        $s = self::settings();
        foreach (self::PROVIDERS as $p) if (self::ready($p, $s)) return true;
        return false;
    }

    /** ok (Anbieter) | none (bewusst keine) | not_needed (nur Vermittlung) | missing */
    public static function state_of($provider = null) {
        $provider = $provider === null ? self::provider_ready() : (bool) $provider;
        if ($provider) return 'ok';
        if (self::none()) return 'none';
        if (class_exists('TIX_Co_Sale') && !TIX_Co_Sale::payments_needed()) return 'not_needed';
        return 'missing';
    }

    private static function respond($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function rest_get(WP_REST_Request $req) {
        return self::respond(self::payload());
    }

    /* ─────────── Speichern ─────────── */

    private static function invalid($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]);
    }

    /** Wert eines Schlüsselfelds: fehlt/'' → unverändert (null zurück), null → löschen (''), sonst getrimmt. */
    private static function key_in(array $in, $k, &$out_set) {
        $out_set = false;
        if (!array_key_exists($k, $in)) return null;
        if ($in[$k] === null) { $out_set = true; return ''; }
        $v = trim((string) $in[$k]);
        if ($v === '') return null;
        $out_set = true;
        return $v;
    }

    public static function rest_save(WP_REST_Request $req) {
        if (self::locked()) return self::locked_error();
        $b = (array) $req->get_json_params();
        $s = get_option('tix_settings', []);
        if (!is_array($s)) $s = [];
        $changed_keys = []; // Anbieter mit neuem Schlüssel → Selbsttest
        if (array_key_exists('none', $b)) update_option(self::OPT_NONE, filter_var($b['none'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', false); // 1.38.742

        if (array_key_exists('order', $b)) {
            $o = array_values(array_map('strval', (array) $b['order']));
            if (array_diff($o, self::PROVIDERS) || count($o) !== count(array_unique($o))) return self::invalid('order', 'Unbekannter Anbieter in der Reihenfolge.');
            foreach (self::order(wp_parse_args($s, ['gateway_provider_order' => 'stripe,mollie,paypal,bank'])) as $p) if (!in_array($p, $o, true)) $o[] = $p; // Rest in bisheriger Reihenfolge
            $s['gateway_provider_order'] = implode(',', $o);
        }

        if (isset($b['mollie']) && is_array($b['mollie'])) {
            $m = $b['mollie'];
            if (array_key_exists('enabled', $m)) $s['mollie_enabled'] = !empty($m['enabled']) ? 1 : 0;
            if (array_key_exists('test_mode', $m)) $s['mollie_test_mode'] = !empty($m['test_mode']) ? 1 : 0;
            $v = self::key_in($m, 'api_key', $set);
            if ($set) {
                if ($v !== '' && !preg_match('/^(live|test)_[A-Za-z0-9]{20,}$/', $v)) return self::invalid('mollie.api_key', 'Der Mollie-Schlüssel beginnt mit live_ oder test_.');
                $s['mollie_api_key'] = $v;
                if (class_exists('TIX_Gateway_Mollie')) TIX_Gateway_Mollie::clear_methods_cache();
                if ($v !== '') $changed_keys[] = 'mollie';
            }
            if (array_key_exists('methods_selected', $m)) {
                $sel = array_values(array_unique(array_map('sanitize_key', (array) $m['methods_selected'])));
                $s['mollie_enabled_methods'] = $sel;
                $s['mollie_methods_order'] = implode(',', $sel);
            }
        }

        if (isset($b['stripe']) && is_array($b['stripe'])) {
            $m = $b['stripe'];
            if (array_key_exists('enabled', $m)) $s['stripe_enabled'] = !empty($m['enabled']) ? 1 : 0;
            if (array_key_exists('test_mode', $m)) $s['stripe_test_mode'] = !empty($m['test_mode']) ? 1 : 0;
            $rules = [
                'secret_live' => ['stripe_secret_key_live', '/^(sk|rk)_live_[A-Za-z0-9]+$/', 'Der Live-Geheimschlüssel beginnt mit sk_live_.'],
                'secret_test' => ['stripe_secret_key_test', '/^(sk|rk)_test_[A-Za-z0-9]+$/', 'Der Test-Geheimschlüssel beginnt mit sk_test_.'],
                'publishable_live' => ['stripe_publishable_key_live', '/^pk_live_[A-Za-z0-9]+$/', 'Der veröffentlichbare Live-Schlüssel beginnt mit pk_live_.'],
                'publishable_test' => ['stripe_publishable_key_test', '/^pk_test_[A-Za-z0-9]+$/', 'Der veröffentlichbare Test-Schlüssel beginnt mit pk_test_.'],
                'webhook_secret_live' => ['stripe_webhook_secret_live', '/^whsec_[A-Za-z0-9]+$/', 'Das Webhook-Geheimnis beginnt mit whsec_.'],
                'webhook_secret_test' => ['stripe_webhook_secret_test', '/^whsec_[A-Za-z0-9]+$/', 'Das Webhook-Geheimnis beginnt mit whsec_.'],
            ];
            foreach ($rules as $f => [$opt, $re, $msg]) {
                $v = self::key_in($m, $f, $set);
                if (!$set) continue;
                if ($v !== '' && !preg_match($re, $v)) return self::invalid("stripe.$f", $msg);
                $s[$opt] = $v;
                if ($v !== '' && strpos($f, 'secret_') === 0) $changed_keys[] = 'stripe';
            }
            if (array_key_exists('methods_selected', $m)) {
                $sel = array_values(array_unique(array_map('sanitize_key', (array) $m['methods_selected'])));
                $s['stripe_enabled_methods'] = $sel;
                $s['stripe_methods_order'] = implode(',', $sel);
            }
            if (array_intersect(['secret_live', 'secret_test', 'test_mode'], array_keys($m))) {
                delete_transient('tix_stripe_methods_v1_live');
                delete_transient('tix_stripe_methods_v1_test');
            }
        }

        if (isset($b['paypal']) && is_array($b['paypal'])) {
            $m = $b['paypal'];
            if (array_key_exists('enabled', $m)) $s['paypal_enabled'] = !empty($m['enabled']) ? 1 : 0;
            if (array_key_exists('sandbox', $m)) $s['paypal_sandbox'] = !empty($m['sandbox']) ? 1 : 0;
            if (array_key_exists('client_id', $m)) $s['paypal_client_id'] = sanitize_text_field((string) $m['client_id']);
            if (array_key_exists('webhook_id', $m)) $s['paypal_webhook_id'] = sanitize_text_field((string) $m['webhook_id']);
            $v = self::key_in($m, 'secret', $set);
            if ($set) { $s['paypal_secret'] = $v; if ($v !== '') $changed_keys[] = 'paypal'; }
            if (array_key_exists('client_id', $m) && trim((string) $m['client_id']) !== '') $changed_keys[] = 'paypal';
        }

        if (isset($b['bank']) && is_array($b['bank'])) {
            $m = $b['bank'];
            if (array_key_exists('iban', $m) && $m['iban'] !== null && trim((string) $m['iban']) !== '') {
                if (!self::iban_valid($m['iban'])) return self::invalid('bank.iban', 'Die IBAN ist nicht gültig.');
                $s['bank_iban'] = strtoupper(preg_replace('/\s+/', '', (string) $m['iban']));
            } elseif (array_key_exists('iban', $m) && $m['iban'] === null) {
                $s['bank_iban'] = '';
            }
            if (array_key_exists('enabled', $m)) {
                $s['bank_transfer_enabled'] = !empty($m['enabled']) ? 1 : 0;
                if ($s['bank_transfer_enabled'] && trim((string) ($s['bank_iban'] ?? '')) === '') return self::invalid('bank.iban', 'Für die Überweisung brauchen wir eine IBAN.');
            }
            foreach (['holder' => 'bank_holder', 'bic' => 'bank_bic', 'bank_name' => 'bank_name', 'reference' => 'bank_reference'] as $f => $opt) {
                if (array_key_exists($f, $m)) $s[$opt] = sanitize_text_field((string) $m[$f]);
            }
            if (isset($s['bank_bic'])) $s['bank_bic'] = strtoupper(preg_replace('/\s+/', '', $s['bank_bic']));
        }

        update_option('tix_settings', $s);
        delete_transient('tix_info_payment_methods');

        $failed = null;
        foreach (array_unique($changed_keys) as $p) {
            $t = self::run_test($p);
            if (empty($t['ok']) && !$failed) $failed = ['provider' => $p, 'code' => $t['code'] ?? '', 'message' => $t['message'] ?? ''];
        }
        $out = self::payload();
        if ($failed) $out['test_failed'] = $failed;
        return self::respond($out);
    }

    /* ─────────── Verbindungstest ─────────── */

    public static function rest_test(WP_REST_Request $req) {
        if (self::locked()) return self::locked_error();
        $p = (string) ($req->get_param('provider') ?? '');
        if (!in_array($p, ['mollie', 'stripe', 'paypal'], true)) return self::invalid('provider', 'Testen lassen sich Mollie, Stripe und PayPal.');
        $key = 'tix_pay_tests';
        $n = (int) get_transient($key);
        if ($n >= self::TEST_LIMIT) return new WP_Error('tix_pay_test_limit', 'Zu viele Tests – bitte in ein paar Minuten noch einmal.', ['status' => 429]);
        set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);
        return self::respond(self::run_test($p));
    }

    private static function http_get($url, $headers) {
        $r = wp_remote_get($url, ['timeout' => 10, 'headers' => $headers]);
        if (is_wp_error($r)) return [0, null];
        return [(int) wp_remote_retrieve_response_code($r), json_decode(wp_remote_retrieve_body($r), true)];
    }

    private static function fail($code, $msg) {
        return ['ok' => false, 'code' => $code, 'message' => $msg];
    }

    /** Test mit den gespeicherten Daten; Ergebnis wird als last_test gemerkt. */
    public static function run_test($p) {
        $s = self::settings();
        $res = self::test_provider($p, $s);
        $res['provider'] = $p;
        $st = self::state();
        $st['last_test'][$p] = ['at' => current_time('c'), 'ok' => !empty($res['ok']), 'code' => $res['code'] ?? '', 'message' => $res['message'] ?? ''];
        update_option(self::STATE, $st, false);
        return $res;
    }

    private static function test_provider($p, $s) {
        if ($p === 'mollie') {
            $k = trim((string) ($s['mollie_api_key'] ?? ''));
            if ($k === '') return self::fail('tix_pay_key_missing', 'Es ist noch kein Mollie-Schlüssel gespeichert.');
            [$c, $d] = self::http_get('https://api.mollie.com/v2/methods?resource=payments&locale=de_DE&includeWallets=applepay', ['Authorization' => 'Bearer ' . $k]);
            if ($c === 0 || $c >= 500) return self::fail('tix_pay_unreachable', 'Mollie ist gerade nicht erreichbar. Bitte später noch einmal testen.');
            if ($c === 401) return self::fail('tix_pay_key_invalid', 'Mollie kennt diesen Schlüssel nicht. Bitte den API-Schlüssel neu kopieren.');
            if ($c === 403 || $c === 422) return self::fail('tix_pay_account_incomplete', 'Dein Mollie-Konto ist noch nicht freigeschaltet. Bitte das Profil bei Mollie vervollständigen.');
            if ($c < 200 || $c >= 300) return self::fail('tix_pay_unreachable', 'Mollie antwortet unerwartet (' . $c . ').');
            $ms = [];
            foreach ((array) ($d['_embedded']['methods'] ?? []) as $m) $ms[] = ['id' => (string) ($m['id'] ?? ''), 'label' => (string) ($m['description'] ?? ''), 'image' => (string) ($m['image']['size2x'] ?? '')];
            if (!$ms) return self::fail('tix_pay_no_methods', 'Der Schlüssel stimmt, aber bei Mollie ist noch keine Zahlart aktiviert.');
            if (class_exists('TIX_Gateway_Mollie')) TIX_Gateway_Mollie::clear_methods_cache();
            return ['ok' => true, 'mode' => strpos($k, 'test_') === 0 ? 'test' : 'live', 'message' => 'Verbindung steht · ' . count($ms) . ' Zahlarten', 'methods' => $ms];
        }
        if ($p === 'stripe') {
            $k = self::stripe_secret($s);
            $mode = !empty($s['stripe_test_mode']) ? 'test' : 'live';
            if ($k === '') return self::fail('tix_pay_key_missing', 'Es ist noch kein Stripe-Geheimschlüssel für den ' . ($mode === 'test' ? 'Test' : 'Live') . '-Modus gespeichert.');
            [$c, $d] = self::http_get('https://api.stripe.com/v1/balance', ['Authorization' => 'Bearer ' . $k]);
            if ($c === 0 || $c >= 500) return self::fail('tix_pay_unreachable', 'Stripe ist gerade nicht erreichbar. Bitte später noch einmal testen.');
            if ($c === 401) return self::fail('tix_pay_key_invalid', 'Stripe kennt diesen Schlüssel nicht. Bitte den Geheimschlüssel neu kopieren.');
            if ($c === 403) return self::fail('tix_pay_account_incomplete', 'Dein Stripe-Konto ist noch nicht freigeschaltet oder der Schlüssel hat zu wenig Rechte.');
            if ($c < 200 || $c >= 300) return self::fail('tix_pay_unreachable', 'Stripe antwortet unerwartet (' . $c . ').');
            delete_transient('tix_stripe_methods_v1_' . $mode);
            return ['ok' => true, 'mode' => $mode, 'message' => 'Verbindung steht', 'methods' => []];
        }
        if ($p === 'paypal') {
            $id = trim((string) ($s['paypal_client_id'] ?? ''));
            $sec = trim((string) ($s['paypal_secret'] ?? ''));
            if ($id === '' || $sec === '') return self::fail('tix_pay_key_missing', 'Bitte Client-ID und Secret von PayPal eintragen.');
            $base = !empty($s['paypal_sandbox']) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
            $r = wp_remote_post($base . '/v1/oauth2/token', ['timeout' => 10, 'headers' => ['Authorization' => 'Basic ' . base64_encode($id . ':' . $sec), 'Content-Type' => 'application/x-www-form-urlencoded'], 'body' => 'grant_type=client_credentials']);
            $c = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
            if ($c === 0 || $c >= 500) return self::fail('tix_pay_unreachable', 'PayPal ist gerade nicht erreichbar. Bitte später noch einmal testen.');
            if ($c === 401) return self::fail('tix_pay_key_invalid', 'PayPal kennt diese Zugangsdaten nicht.' . (!empty($s['paypal_sandbox']) ? ' Sandbox ist an – passen die Daten zur Sandbox-App?' : ' Sind es die Daten der Live-App?'));
            if ($c < 200 || $c >= 300) return self::fail('tix_pay_unreachable', 'PayPal antwortet unerwartet (' . $c . ').');
            return ['ok' => true, 'mode' => !empty($s['paypal_sandbox']) ? 'test' : 'live', 'message' => 'Verbindung steht', 'methods' => []];
        }
        return self::fail('tix_invalid_field', 'Unbekannter Anbieter.');
    }
}

TIX_Studio_Payments::init();
