<?php
/**
 * Tixomat – Universal Links (apple-app-site-association)
 *
 * Liefert /.well-known/apple-app-site-association (und /apple-app-site-association)
 * für die native App der Seite aus. Nur Links mit ?tix_sp_ticket=<id> (Knopf
 * „Im Support antworten“ in Support-Mails) öffnen die App, alle anderen Links
 * bleiben im Browser. App-IDs je Seite in der Option tix_app_link_ids
 * („TEAMID.bundle.id“), leer = 404.
 *
 * Zusätzliche Pfade je Seite (seit 1.38.385, Option `tix_app_link_paths`, Liste):
 *   events       → /events/*            (Event-Seiten)
 *   veranstalter → /veranstalter/*      (Veranstalter-Seiten)
 *   v            → /v/*                 (Link des Veranstalters, seit 1.38.412)
 *   suche        → /suche/*             (Suche)
 *   start_p      → / mit ?p=…           (Startseite mit Parameter p)
 *   kasse        → /app/kasse/*         (App-Kasse eines Events, Gutscheine nur in der App, seit 1.38.431)
 *   kuenstler    → /kuenstler/*         (Künstler-Seiten, seit 1.38.518; im Mehr-Veranstalter-Modus automatisch mit „veranstalter“)
 *   marke        → Marken-Homepage (seit 1.38.530): /, /events/*, /line-up/*, /kuenstler/*, /faq/*, /u16/*, /muttizettel/*,
 *                  /kontakt/*, /support/*; seit 1.38.531 nur mit dem Schalter `tix_app_links_ios_marke` (Vorgabe aus – die
 *                  Store-Apps bis KK 1.0.2/Mallorca 1.0.1 werten nur ?tix_sp_ticket aus), Tixomat → App-Push bzw.
 *                  `wp tixomat app-links ios-marke on|off`
 * Kasse, Konto und Rechtstexte werden nie aufgenommen (bleiben im Browser). Seit 1.38.530 stehen davor feste Ausschlüsse
 * (EXCLUDES: WordPress-Pfade, Kasse/Zahlung, Konto, DJ-/Kassen-/Einlass-Seiten, Abmelden, Downloads/PDFs, QR, Passwort-
 * Reset, Vorschau ?draft=) – sie gelten für alle Freigaben außer dem Support-Link ?tix_sp_ticket (steht davor).
 *
 * Android App Links (seit 1.38.530): /.well-known/assetlinks.json aus der Option `tix_android_app_links`
 * {package, fingerprints[]} (SHA-256 des Upload- bzw. App-Signaturschlüssels), leer = 404.
 * Einstellen: Tixomat → App-Push oder `wp tixomat app-links android-set <package> <fp>[,<fp>…]`.
 * Beispiel evendis: wp option update tix_app_link_paths '["events","veranstalter","suche","start_p"]' --format=json
 *          KitchenKlub: wp option update tix_app_link_paths '["events"]' --format=json
 *
 * @since 1.38.342
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Links {

    const OPTION = 'tix_app_link_ids';
    const OPTION_PATHS = 'tix_app_link_paths';
    const OPTION_ANDROID = 'tix_android_app_links';
    const OPTION_MARKE = 'tix_app_links_ios_marke'; // '1' = Homepage-Pfade in der AASA (1.38.531)

    /** Immer zuerst (exclude): bleibt im Browser, auch wenn eine Freigabe passen würde (1.38.530). */
    const EXCLUDES = [
        ['/' => '/wp-admin*'], ['/' => '/wp-login*'], ['/' => '/wp-json*'], ['/' => '/wp-content*'], ['/' => '/wp-includes*'],
        ['/' => '/xmlrpc.php'], ['/' => '/.well-known*'], ['/' => '/index.php*'],
        // Kasse, Zahlungs-Rückwege, Warenkorb
        ['/' => '/kasse/*'], ['/' => '/checkout*'], ['/' => '/cart*'], ['/' => '/warenkorb*'], ['/' => '/shop*'], ['/' => '/feed*'],
        // Konto, Passwort-Reset
        ['/' => '/mein-konto*'], ['/' => '/passwort*'], ['/' => '/lost-password*'],
        // Sonderseiten im Browser: DJ-Musikwünsche, Einlass, Abendkasse, Promoter, Sponsoren
        ['/' => '/dj-musikwuensche*'], ['/' => '/checkin*'], ['/' => '/pos/*'], ['/' => '/promoter*'], ['/' => '/sponsor*'],
        // Abmelden (Folgen, Newsletter)
        ['/' => '*/abmelden*'],
        // Abfragen auf jedem Pfad: Downloads/PDFs (Tickets, Rechnungen), QR, Zahlungs-Rückweg, Bestellung, Abmelden, Vorschau
        ['?' => ['draft' => '*']], ['?' => ['tix_dl' => '*']], ['?' => ['tix_qr' => '*']], ['?' => ['tix_payment_return' => '*']],
        ['?' => ['tix_thankyou' => '*']], ['?' => ['tix_ticket_key' => '*']], ['?' => ['tix_ticket_code' => '*']], ['?' => ['tix_mt_token' => '*']],
        ['?' => ['tix_unsub' => '*']], ['?' => ['tix_sp_file' => '*']], ['?' => ['tix_preview' => '*']], ['?' => ['tix_admin_preview' => '*']],
        ['?' => ['tix_rp_login' => '*']], ['?' => ['tix_recover_cart' => '*']], ['?' => ['tix_cart' => '*']], ['?' => ['tix_embed' => '*']],
        ['?' => ['order_key' => '*']], ['?' => ['key' => '*']], ['?' => ['action' => '*']], ['?' => ['token' => '*']],
        // Zahlungsanbieter-Rückkehr (Mollie/Stripe/PayPal: /?tix_payment_return=1&gateway=…&order_key=…, Abbruch tix_payment_cancel)
        ['?' => ['tix_payment_cancel' => '*']], ['?' => ['gateway' => '*']], ['?' => ['session_id' => '*']], ['?' => ['tix_order_key' => '*']],
    ];

    /** Pfade der Marken-Homepage (apps/marken-web) und der heutigen WordPress-Seiten. */
    const MARKE_PATHS = [
        ['/' => '/',              'comment' => 'Startseite'],
        ['/' => '/events/*',      'comment' => 'Events und Event-Seiten'],
        ['/' => '/line-up/*',     'comment' => 'Line-up'],
        ['/' => '/kuenstler/*',   'comment' => 'Künstler'],
        ['/' => '/faq/*',         'comment' => 'FAQ'],
        ['/' => '/u16/*',         'comment' => 'Ü16'],
        ['/' => '/muttizettel/*', 'comment' => 'Muttizettel'],
        ['/' => '/kontakt/*',     'comment' => 'Kontakt'],
        ['/' => '/support/*',     'comment' => 'Support'],
    ];

    /** Freigebbare Pfad-Bausteine (AASA-Komponenten). */
    const PATH_PRESETS = [
        'events'       => ['/' => '/events/*',       'comment' => 'Event-Seiten'],
        'veranstalter' => ['/' => '/veranstalter/*', 'comment' => 'Veranstalter-Seiten'],
        'v'            => ['/' => '/v/*',            'comment' => 'Link des Veranstalters'],
        'suche'        => ['/' => '/suche/*',        'comment' => 'Suche'],
        'start_p'      => ['/' => '/', '?' => ['p' => '?*'], 'comment' => 'Startseite mit Parameter p'],
        'kasse'        => ['/' => '/app/kasse/*',    'comment' => 'App-Kasse eines Events (Gutscheine nur in der App, seit 1.38.431)'],
        'kuenstler'    => ['/' => '/kuenstler/*',    'comment' => 'Künstler-Seiten'],
        'marke'        => ['list' => self::MARKE_PATHS, 'comment' => 'Marken-Homepage (1.38.530)'],
    ];

    /** Eingestellte Pfad-Bausteine (nur bekannte). */
    public static function paths() {
        $raw = get_option(self::OPTION_PATHS, []);
        if (is_string($raw)) $raw = json_decode($raw, true) ?: preg_split('/[\s,]+/', $raw);
        $out = [];
        foreach ((array) $raw as $k) {
            $k = sanitize_key((string) $k);
            if (isset(self::PATH_PRESETS[$k])) $out[] = $k;
        }
        // Künstler-Seiten (1.38.518, nur evendis): automatisch mit den Veranstalter-Seiten
        if (in_array('veranstalter', $out, true) && class_exists('TIX_Artists') && TIX_Artists::multi()) $out[] = 'kuenstler';
        // Marken-Homepage: nur mit eigenem Schalter (1.38.531, Vorgabe aus), nicht mehr über die Pfad-Liste
        $out = array_values(array_diff($out, ['marke']));
        if (self::marke_on()) $out[] = 'marke';
        return array_values(array_unique($out));
    }

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_serve'], 0);
        add_action('admin_post_tix_app_links_save', [__CLASS__, 'handle_save']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat app-links', [__CLASS__, 'cli']);
    }

    /** Homepage-Pfade (Baustein marke) in der AASA freigegeben? Schaltet die Zentrale, sobald die App-Updates im Store sind. */
    public static function marke_on() {
        return get_option(self::OPTION_MARKE, '0') === '1';
    }

    public static function set_marke($on) {
        update_option(self::OPTION_MARKE, $on ? '1' : '0', false);
    }

    /** Gültige App-IDs (Team-ID mit 10 Zeichen + Bundle-ID) */
    public static function ids() {
        return self::sanitize_ids(get_option(self::OPTION, []));
    }

    public static function sanitize_ids($raw) {
        $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw);
        $out = [];
        foreach ($list as $id) {
            $id = trim((string) $id);
            if (preg_match('/^[A-Z0-9]{10}\.[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $id)) $out[] = $id;
        }
        return array_values(array_unique($out));
    }

    public static function document(array $ids, $paths = null) {
        // Support-Link aus der Mail zuerst (nur mit ?tix_sp_ticket=, Pfad der Support-Seite je Seite verschieden), dann die Ausschlüsse
        $components = [[
            '/'       => '*',
            '?'       => ['tix_sp_ticket' => '?*'],
            'comment' => 'Support-Verlauf in der App',
        ]];
        foreach (self::EXCLUDES as $c) $components[] = $c + ['exclude' => true];
        foreach (($paths === null ? self::paths() : $paths) as $k) {
            if (!isset(self::PATH_PRESETS[$k])) continue;
            if (isset(self::PATH_PRESETS[$k]['list'])) { foreach (self::PATH_PRESETS[$k]['list'] as $c) $components[] = $c; continue; }
            $components[] = self::PATH_PRESETS[$k];
        }
        return [
            'applinks' => [
                'details' => [[
                    'appIDs'     => $ids,
                    'components' => $components,
                ]],
            ],
        ];
    }

    // ═══════════════ Android App Links (1.38.530) ═══════════════

    /** {package, fingerprints[]} oder null (nicht eingerichtet). */
    public static function android() {
        $o = get_option(self::OPTION_ANDROID, []);
        if (is_string($o)) $o = json_decode($o, true);
        if (!is_array($o)) return null;
        $c = self::clean_android($o['package'] ?? '', $o['fingerprints'] ?? []);
        return is_wp_error($c) ? null : $c;
    }

    /** Prüfen: Paketname (Java-Stil) + SHA-256-Fingerabdrücke AA:BB:… (32 Bytes). → {package, fingerprints} | WP_Error */
    public static function clean_android($package, $fingerprints) {
        $package = trim((string) $package);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $package)) return new WP_Error('tix_android_package', 'Paketname ungültig (z. B. de.kitchenklub.app).');
        $list = is_array($fingerprints) ? $fingerprints : preg_split('/[\s,;]+/', (string) $fingerprints);
        $out = [];
        foreach ($list as $f) {
            $f = strtoupper(trim((string) $f));
            if ($f === '') continue;
            if (preg_match('/^[0-9A-F]{64}$/', $f)) $f = implode(':', str_split($f, 2));
            if (!preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $f)) return new WP_Error('tix_android_fingerprint', 'Fingerabdruck ungültig: SHA-256 mit 32 Paaren, z. B. 58:E0:C9:…');
            $out[] = $f;
        }
        $out = array_values(array_unique($out));
        if (!$out) return new WP_Error('tix_android_fingerprint', 'Mindestens ein SHA-256-Fingerabdruck nötig.');
        if (count($out) > 10) return new WP_Error('tix_android_fingerprint', 'Höchstens 10 Fingerabdrücke.');
        return ['package' => $package, 'fingerprints' => $out];
    }

    /** Speichern (null/leer = entfernen). → true | WP_Error */
    public static function save_android($package, $fingerprints) {
        if (trim((string) $package) === '' && (is_array($fingerprints) ? !$fingerprints : trim((string) $fingerprints) === '')) { delete_option(self::OPTION_ANDROID); return true; }
        $c = self::clean_android($package, $fingerprints);
        if (is_wp_error($c)) return $c;
        update_option(self::OPTION_ANDROID, $c, false);
        return true;
    }

    public static function assetlinks(array $a) {
        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target'   => ['namespace' => 'android_app', 'package_name' => $a['package'], 'sha256_cert_fingerprints' => $a['fingerprints']],
        ]];
    }

    public static function handle_save() {
        if (!current_user_can('manage_options') || !check_admin_referer('tix_app_links_save')) wp_die('Keine Berechtigung.');
        if (isset($_POST['ios_marke_set'])) self::set_marke(!empty($_POST['ios_marke']));
        $r = self::save_android(sanitize_text_field(wp_unslash((string) ($_POST['android_package'] ?? ''))), sanitize_textarea_field(wp_unslash((string) ($_POST['android_fingerprints'] ?? ''))));
        $back = admin_url('admin.php?page=tix-app-push');
        wp_safe_redirect(add_query_arg(is_wp_error($r) ? ['tix_links' => 'error', 'msg' => rawurlencode($r->get_error_message())] : ['tix_links' => 'saved'], $back));
        exit;
    }

    /** Abschnitt „App-Links“ für die Seite Tixomat → App-Push. */
    public static function render_admin_section() {
        $a = self::android();
        $ids = self::ids();
        $msg = sanitize_key((string) ($_GET['tix_links'] ?? ''));
        $home = home_url('/');
        ?>
        <?php if ($msg === 'saved'): ?><div class="notice notice-success is-dismissible"><p>App-Links gespeichert.</p></div><?php endif; ?>
        <?php if ($msg === 'error'): ?><div class="notice notice-error"><p><?php echo esc_html(rawurldecode((string) ($_GET['msg'] ?? 'Fehler.'))); ?></p></div><?php endif; ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;max-width:820px;margin:12px 0;">
            <h2 style="margin-top:0;">App-Links (geteilte Links öffnen die App)</h2>
            <p>iPhone: <?php echo $ids ? esc_html(implode(', ', $ids)) : '<em>keine App-ID (tix_app_link_ids)</em>'; ?> · Freigaben: <?php echo esc_html(implode(', ', self::paths()) ?: 'nur Support'); ?>
                · <a href="<?php echo esc_url($home . '.well-known/apple-app-site-association'); ?>" target="_blank" rel="noopener">AASA ansehen</a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tix_app_links_save"><?php wp_nonce_field('tix_app_links_save'); ?>
                <input type="hidden" name="ios_marke_set" value="1">
                <p><label><input type="checkbox" name="ios_marke" value="1" <?php checked(self::marke_on()); ?>> <strong>iPhone: Homepage-Links öffnen die App</strong> (Start, Events, Line-up, Künstler, FAQ, Ü16, Muttizettel, Kontakt, Support)</label><br>
                    <span style="color:#64748b;">Erst einschalten, wenn die App-Version im Store diese Links kennt – ältere Apps öffnen sonst ins Leere. Der Support-Link (?tix_sp_ticket) gilt immer.</span></p>
                <p><label>Android-Paketname<br><input name="android_package" value="<?php echo esc_attr($a['package'] ?? ''); ?>" class="regular-text" placeholder="de.kitchenklub.app"></label></p>
                <p><label>SHA-256-Fingerabdrücke (Upload-Schlüssel und App-Signaturschlüssel aus der Play Console, einer je Zeile)<br>
                    <textarea name="android_fingerprints" rows="3" style="width:100%;font-family:monospace;" placeholder="58:E0:C9:…"><?php echo esc_textarea(implode("\n", $a['fingerprints'] ?? [])); ?></textarea></label></p>
                <?php submit_button('App-Links speichern', 'secondary', 'submit', false); ?>
                <?php if ($a): ?> <a href="<?php echo esc_url($home . '.well-known/assetlinks.json'); ?>" target="_blank" rel="noopener">assetlinks.json ansehen</a><?php endif; ?>
                <span style="color:#64748b;margin-left:8px;">Beide Felder leeren = entfernen (assetlinks.json → 404).</span>
            </form>
        </div>
        <?php
    }

    /** wp tixomat app-links status | ios-marke on|off | android-set <package> <fp>[,<fp>…] | android-clear */
    public static function cli($args, $assoc) {
        $cmd = (string) ($args[0] ?? 'status');
        if ($cmd === 'status') {
            $ids = self::ids();
            WP_CLI::line(wp_json_encode(['ios_marke' => self::marke_on(), 'ios' => $ids ? self::document($ids) : null, 'android' => ($a = self::android()) ? self::assetlinks($a) : null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($cmd === 'android-set') {
            $r = self::save_android((string) ($args[1] ?? ''), (string) ($args[2] ?? ''));
            if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
            WP_CLI::success('Android App Links: ' . wp_json_encode(self::android(), JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($cmd === 'ios-marke') {
            $v = strtolower((string) ($args[1] ?? ''));
            if (!in_array($v, ['on', 'off'], true)) WP_CLI::error('Aufruf: wp tixomat app-links ios-marke on|off (jetzt: ' . (self::marke_on() ? 'on' : 'off') . ')');
            self::set_marke($v === 'on');
            WP_CLI::success('iPhone-Homepage-Links: ' . $v . ' (Freigaben: ' . (implode(', ', self::paths()) ?: 'nur Support') . ')');
            return;
        }
        if ($cmd === 'android-clear') { delete_option(self::OPTION_ANDROID); WP_CLI::success('Android App Links entfernt (assetlinks.json → 404).'); return; }
        WP_CLI::error('Aufruf: wp tixomat app-links status | ios-marke on|off | android-set <package> <fp>[,<fp>…] | android-clear');
    }

    /** Antwort ohne Zwischenspeicher, JSON, ohne Weiterleitung. */
    private static function send_json($code, $body) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        do_action('litespeed_control_set_nocache', 'tixomat app links');
        nocache_headers();
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Content-Type: application/json');
        status_header($code);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
        exit;
    }

    public static function maybe_serve() {
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return;
        if ($path === $base . '/.well-known/assetlinks.json') {
            $a = self::android();
            if (!$a) self::send_json(404, '{"error":"not_configured"}');
            self::send_json(200, wp_json_encode(self::assetlinks($a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        if ($path !== $base . '/.well-known/apple-app-site-association' && $path !== $base . '/apple-app-site-association') return;

        // Nicht zwischenspeichern (LiteSpeed Cache u. a.)
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        do_action('litespeed_control_set_nocache', 'tixomat aasa');
        nocache_headers();
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Content-Type: application/json');

        $ids = self::ids();
        if (!$ids) {
            status_header(404);
            echo '{"error":"not_configured"}';
            exit;
        }
        status_header(200);
        echo wp_json_encode(self::document($ids), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
