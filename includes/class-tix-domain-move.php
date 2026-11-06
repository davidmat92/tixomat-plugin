<?php
if (!defined('ABSPATH')) exit;

/**
 * Umzug einer Seite auf eine andere Domain (1.38.681, Doku §98, Umbau 6 „Selbstgehostet“).
 *
 * Kundenseite (läuft über den Anlege-Dienst per WP-CLI, nach DNS + Zertifikat + nginx für die neue Domain):
 *   wp tixomat domain umziehen --nach=<host> [--trocken] [--studio=<url>]
 *     1. home/siteurl → https://<host>
 *     2. Adressen in der Datenbank ersetzen (wp search-replace, auch serialisiert und als JSON „\/\/“), guid bleibt
 *     3. Website-Adresse (tix_website_domain), Zwischenspeicher leeren, Verlauf tix_domain_history
 *     4. Lizenz neu prüfen (der Lizenz-Server muss den Host vorher umgezogen haben, sonst site_limit)
 *     Ausgabe am Ende eine JSON-Zeile „ERGEBNIS {…}“ mit Hinweisen (Apple Pay, Absender-Adresse, Webhooks).
 *   wp tixomat domain status
 * Studio: GET /studio/domain (Rechte wie Marke) → {host, home, history, website, hints}.
 *
 * Lizenz-Server: TIX_License_Server-Ergänzung über move_host() hier, REST POST /license/admin/licenses/{id}/move-host {from, to}
 * und WP-CLI „wp tixomat domain lizenz --lizenz=<id>|--notiz=<text> --von=<host> --nach=<host>“.
 */
class TIX_Domain_Move {

    const NS = 'tixomat/v1';
    const OPT_HISTORY = 'tix_domain_history';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat domain', [__CLASS__, 'cli']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/studio/domain', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_status'],
            'permission_callback' => function ($req) { return class_exists('TIX_Studio') ? TIX_Studio::perm_brand($req) : current_user_can('manage_options'); },
        ]);
        if (class_exists('TIX_License_Server') && TIX_License_Server::enabled()) {
            register_rest_route(self::NS, '/license/admin/licenses/(?P<id>\d+)/move-host', [
                'methods' => 'POST', 'callback' => [__CLASS__, 'rest_move_host'],
                'permission_callback' => function () { return current_user_can('manage_options'); },
            ]);
        }
    }

    /* ─────────── Helfer ─────────── */

    public static function normalize_host($h) {
        $h = strtolower(trim((string) $h));
        $h = preg_replace('#^https?://#', '', $h);
        $h = preg_replace('#[/?\#].*$#', '', $h);
        $h = preg_replace('/^www\./', '', $h);
        return $h;
    }

    public static function valid_host($h) {
        return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $h);
    }

    public static function current_host() {
        return self::normalize_host((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    public static function history() {
        $h = get_option(self::OPT_HISTORY, []);
        return is_array($h) ? array_values($h) : [];
    }

    /** Hinweise nach einem Umzug: was der Kunde bzw. der Betrieb noch selbst erledigen muss. */
    public static function hints($new) {
        $out = [];
        $s = get_option('tix_settings', []);
        $s = is_array($s) ? $s : [];
        if (!empty($s['mollie_api_key']) || !empty($s['stripe_secret_key_live'])) {
            $out[] = ['code' => 'apple_pay_domain', 'message' => "Apple Pay: $new beim Zahlungsanbieter als Domain eintragen (Mollie: Profil → Apple Pay, Stripe: Einstellungen → Zahlungsmethoden-Domains)."];
        }
        if (!empty($s['stripe_webhook_secret_live']) || !empty($s['paypal_webhook_id'])) {
            $out[] = ['code' => 'webhook_url', 'message' => 'Webhooks bei Stripe/PayPal auf die neue Adresse umstellen (Studio → Einstellungen → Zahlungen zeigt sie).'];
        }
        $mt = get_option('tix_mail_transport', []);
        $from = is_array($mt) ? (string) ($mt['from'] ?? ($mt['from_email'] ?? '')) : '';
        if ($from !== '' && substr(strtolower($from), -strlen('@' . $new)) !== '@' . $new) {
            $out[] = ['code' => 'mail_from', 'message' => "Absender ist noch $from. Für Mails von der neuen Domain den Absender im Studio ändern und bei Brevo bestätigen."];
        }
        return $out;
    }

    public static function status_payload() {
        $w = get_option('tix_website_domain', []);
        $host = self::current_host();
        return [
            'host' => $host,
            'home' => home_url(),
            'website' => is_array($w) ? ['status' => (string) ($w['status'] ?? ''), 'live_host' => (string) ($w['live_host'] ?? ''), 'preview_host' => (string) ($w['preview_host'] ?? '')] : null,
            'history' => self::history(),
            'hints' => self::hints($host),
        ];
    }

    public static function rest_status(WP_REST_Request $req) {
        $r = new WP_REST_Response(self::status_payload(), 200);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /* ─────────── Kundenseite: Umzug ─────────── */

    /**
     * Umzug ausführen. $replace = callable($from, $to) für die Datenbank-Ersetzung (Standard: wp search-replace), liefert Anzahl.
     * @return array|WP_Error {from, to, dry_run, steps:[{step, ok, detail}], hints}
     */
    public static function move($to, $args = [], $replace = null) {
        $to = self::normalize_host($to);
        $from = self::current_host();
        $dry = !empty($args['dry_run']);
        if (!self::valid_host($to)) return new WP_Error('tix_invalid_field', 'Die neue Domain ist ungültig.', ['field' => 'to']);
        if ($to === $from) return new WP_Error('tix_domain_same', "Die Seite läuft schon auf $to.");
        $steps = [];

        // 1. Adressen in der Datenbank (vor home/siteurl, damit beide Formen erwischt werden)
        $pairs = [
            ["https://$from", "https://$to"], ["http://$from", "https://$to"],
            ["https://www.$from", "https://$to"], ["http://www.$from", "https://$to"],
            ['https:\\/\\/' . $from, 'https:\\/\\/' . $to], ['https:\\/\\/www.' . $from, 'https:\\/\\/' . $to],
        ];
        $count = 0;
        foreach ($pairs as [$a, $b]) {
            $count += $dry ? 0 : intval(call_user_func($replace ?: [__CLASS__, 'search_replace'], $a, $b));
        }
        $steps[] = ['step' => 'datenbank', 'ok' => true, 'detail' => $dry ? 'würde Adressen ersetzen (search-replace, guid bleibt)' : "$count Ersetzungen"];

        // 2. home/siteurl
        if (!$dry) {
            update_option('home', "https://$to");
            update_option('siteurl', "https://$to");
        }
        $steps[] = ['step' => 'adresse', 'ok' => true, 'detail' => "home/siteurl → https://$to"];

        // 3. Website-Adresse (Vertrag Homepages vertrag-homepage-laufzeit-domain.md): danach immer {status: live, live_host: <neu>};
        //    die Übergangsadresse leitet dann per 301 auf live_host. preview_host nur, wenn er die alte Domain bzw. leer war.
        $w = get_option('tix_website_domain', null);
        $w = is_array($w) ? $w : [];
        if (empty($w['preview_host']) || self::normalize_host($w['preview_host']) === $from) $w['preview_host'] = $to;
        $w['live_host'] = $to;
        if (($w['status'] ?? '') !== 'live') $w['switched_at'] = current_time('c');
        $w['status'] = 'live';
        if (!$dry) update_option('tix_website_domain', $w);
        $steps[] = ['step' => 'website', 'ok' => true, 'detail' => "Website-Adresse live auf $to"];

        // 4. Studio-Adresse nur auf Wunsch (sonst bleibt studio.<kunde>.evendis.de)
        if (!empty($args['studio'])) {
            $su = esc_url_raw((string) $args['studio']);
            if (!preg_match('#^https://[^/\s]+#', $su)) return new WP_Error('tix_invalid_field', 'Die Studio-Adresse muss mit https:// beginnen.', ['field' => 'studio']);
            if (!$dry) update_option('tix_studio_url', untrailingslashit($su));
            $steps[] = ['step' => 'studio', 'ok' => true, 'detail' => "Studio-Adresse → $su"];
        }

        // 5. Verlauf + Zwischenspeicher
        if (!$dry) {
            $h = self::history();
            $h[] = ['from' => $from, 'to' => $to, 'at' => current_time('c'), 'by' => defined('WP_CLI') && WP_CLI ? 'cli' : (string) get_current_user_id()];
            update_option(self::OPT_HISTORY, array_slice($h, -20), false);
            self::flush();
            if (class_exists('TIX_Web_Revalidate')) { TIX_Web_Revalidate::touch_domain($from); TIX_Web_Revalidate::touch_domain($to); }
        }
        $steps[] = ['step' => 'zwischenspeicher', 'ok' => true, 'detail' => $dry ? 'würde geleert' : 'geleert'];

        // 6. Lizenz neu prüfen (Host-Bindung muss der Lizenz-Server schon umgezogen haben)
        if (!$dry && class_exists('TIX_License') && method_exists('TIX_License', 'check') && get_option('tix_license_key')) {
            $st = TIX_License::check();
            $st = is_array($st) ? $st : (array) get_option('tix_license_status', []);
            $ok = !empty($st['valid']);
            $steps[] = ['step' => 'lizenz', 'ok' => $ok, 'detail' => $ok ? 'Lizenz geprüft' : ('Lizenz meldet „' . ($st['status'] ?? '?') . '“' . (($st['status'] ?? '') === 'site_limit' ? ' – Host auf dem Lizenz-Server umziehen (wp tixomat domain lizenz) und erneut prüfen' : ''))];
        }

        return ['from' => $from, 'to' => $to, 'dry_run' => $dry, 'steps' => $steps, 'hints' => self::hints($to)];
    }

    /** Ersetzung über wp search-replace (nur im WP-CLI-Kontext). */
    public static function search_replace($a, $b) {
        if (!(defined('WP_CLI') && WP_CLI)) return 0;
        $r = WP_CLI::runcommand('search-replace ' . escapeshellarg($a) . ' ' . escapeshellarg($b) . ' --all-tables-with-prefix --precise --skip-columns=guid --format=count', ['return' => 'all', 'exit_error' => false, 'launch' => false]);
        return intval(trim((string) ($r->stdout ?? '0')));
    }

    private static function flush() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_%'");
        wp_cache_flush();
    }

    /* ─────────── Lizenz-Server: Host umziehen ─────────── */

    /** @return array|WP_Error {license_id, from, to, moved} */
    public static function move_host($license_id, $from, $to) {
        global $wpdb;
        if (!class_exists('TIX_License_Server')) return new WP_Error('tix_no_license_server', 'Lizenz-Server ist hier nicht aktiv.');
        $lic = TIX_License_Server::get(intval($license_id));
        if (!$lic) return new WP_Error('tix_not_found', 'Lizenz nicht gefunden.', ['status' => 404]);
        $from = self::normalize_host($from); $to = self::normalize_host($to);
        if (!self::valid_host($to)) return new WP_Error('tix_invalid_field', 'Die neue Domain ist ungültig.', ['status' => 400, 'field' => 'to']);
        $t = $wpdb->prefix . 'tix_license_sites';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE license_id = %d AND host = %s", $lic->id, $to));
        $row = $from !== '' ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE license_id = %d AND host = %s", $lic->id, $from)) : null;
        if (!$row && !$exists) {
            // Ohne alten Eintrag: Platz frei? Sonst gibt die erste Prüfung der neuen Domain site_limit.
            return new WP_Error('tix_host_unknown', "Die Lizenz kennt die Website $from nicht.", ['status' => 404, 'field' => 'from']);
        }
        if ($row && !$exists) {
            $wpdb->update($t, ['host' => $to, 'last_seen' => current_time('mysql')], ['id' => $row->id]);
        } elseif ($row && $exists) {
            $wpdb->delete($t, ['id' => $row->id]); // neue Domain hat sich schon gemeldet (z. B. als dev) – alten Eintrag entfernen
        }
        $note = (string) $lic->note;
        if ($from !== '' && strpos($note, "Host $from") !== false) {
            $wpdb->update($wpdb->prefix . 'tix_licenses', ['note' => str_replace("Host $from", "Host $to", $note)], ['id' => $lic->id]);
        }
        $wpdb->insert($wpdb->prefix . 'tix_license_log', [
            'license_id' => intval($lic->id), 'user_id' => get_current_user_id(), 'action' => 'move_host',
            'data' => wp_json_encode(['from' => $from, 'to' => $to]), 'created_at' => current_time('mysql'),
        ]);
        return ['license_id' => intval($lic->id), 'from' => $from, 'to' => $to, 'moved' => true];
    }

    public static function rest_move_host(WP_REST_Request $req) {
        $p = $req->get_json_params() ?: $req->get_params();
        $r = self::move_host(intval($req['id']), (string) ($p['from'] ?? ''), (string) ($p['to'] ?? ''));
        return is_wp_error($r) ? $r : rest_ensure_response(['ok' => true] + $r);
    }

    /* ─────────── WP-CLI ─────────── */

    /**
     * Domain einer Seite umziehen bzw. Host einer Lizenz umziehen.
     *
     * ## OPTIONS
     * <aktion>
     * : umziehen | status | lizenz
     * [--nach=<host>]
     * : Neue Domain (umziehen, lizenz)
     * [--von=<host>]
     * : Alte Domain (lizenz)
     * [--lizenz=<id>]
     * : Lizenz-ID (lizenz)
     * [--notiz=<text>]
     * : Lizenz über Notiz-Merkmal finden, z. B. „[kunden-hetzner:xxl]“ (lizenz)
     * [--studio=<url>]
     * : Neue Studio-Adresse (umziehen, optional)
     * [--trocken]
     * : Nur anzeigen (umziehen)
     */
    public static function cli($args, $assoc) {
        $do = $args[0] ?? 'status';
        if ($do === 'status') { WP_CLI::line(wp_json_encode(self::status_payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); return; }
        if ($do === 'umziehen') {
            $r = self::move((string) ($assoc['nach'] ?? ''), ['dry_run' => isset($assoc['trocken']), 'studio' => $assoc['studio'] ?? '']);
            if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
            foreach ($r['steps'] as $s) WP_CLI::line(($s['ok'] ? 'ok    ' : 'FEHLER ') . $s['step'] . ': ' . $s['detail']);
            foreach ($r['hints'] as $h) WP_CLI::line('Hinweis: ' . $h['message']);
            WP_CLI::line('ERGEBNIS ' . wp_json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($do === 'lizenz') {
            global $wpdb;
            $id = intval($assoc['lizenz'] ?? 0);
            if (!$id && !empty($assoc['notiz'])) {
                $id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tix_licenses WHERE note LIKE %s ORDER BY id LIMIT 1", '%' . $wpdb->esc_like((string) $assoc['notiz']) . '%')));
            }
            $r = self::move_host($id, (string) ($assoc['von'] ?? ''), (string) ($assoc['nach'] ?? ''));
            if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
            WP_CLI::success("Lizenz {$r['license_id']}: {$r['from']} → {$r['to']}");
            return;
        }
        WP_CLI::error('Aktion: umziehen | status | lizenz');
    }
}

TIX_Domain_Move::init();
