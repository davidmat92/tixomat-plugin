<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Org_Domains – eigene Domain je Veranstalter für seine Seite auf der Plattform (nur Mehr-Veranstalter-Modus, evendis).
 *
 * Admin schaltet je Veranstalter „Eigene Domain erlaubt“ frei (Meta _tix_org_domain_allowed, Hinweis zum Zusatzpreis
 * _tix_org_domain_fee_note – abgerechnet wird noch nichts). Danach trägt der Inhaber bzw. Team-Admin die Domain ein
 * („Mein Konto“, REST). Status (Meta _tix_org_domain {host, status, checked_at, error, created_at, activated_at}):
 *   pending_dns – eingetragen, DNS zeigt noch nicht auf die Plattform
 *   active      – CNAME auf das Ziel bzw. A/AAAA auf die Plattform-IP; Mail + Mitteilung beim ersten Mal
 *   error       – DNS zeigt woandershin (Meldung in error)
 * Prüfung per dns_get_record: Cron stündlich (aktive täglich), Knopf „Jetzt prüfen“.
 * Ziele aus Option tix_org_domain_settings {cname_target, a_records[], aaaa_records[], ask_secret}.
 *
 * Für Next.js/TLS: GET /public/domains/{host} → {organizer_id, slug, design, active}; GET /public/domains?active=1 → Liste;
 * GET /public/domains/allowed?domain= (bzw. host=, optional secret=) → 200/404 für On-Demand-TLS.
 * Aktive Domains stehen zusätzlich in der Option tix_org_domains_active (host → organizer_id): TIX_Customer_URL leitet
 * Anfragen von dort wie die Kunden-Adresse durch (Online-Ticket, Kasse bleiben auf der Domain).
 * Bestellungen merken sich die Herkunft (POST /customer/orders origin_host) → Mails dieser Bestellung zeigen auf die Domain.
 */
class TIX_Org_Domains {

    const NS          = 'tixomat/v1';
    const OPTION      = 'tix_org_domain_settings';
    const META_ALLOW  = '_tix_org_domain_allowed';
    const META_FEE    = '_tix_org_domain_fee_note';
    const META_DOMAIN = '_tix_org_domain';
    const META_ORIGIN = '_tix_origin_host';
    const CRON        = 'tix_org_domains_cron';
    const BLOCKED     = ['evendis.de', 'evendis.com', 'tixomat.de', 'tixomat.com'];

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function init() {
        if (!self::multi()) return;
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Org_Domains::CRON)) wp_schedule_event(time() + 600, 'hourly', TIX_Org_Domains::CRON);
        }, 27);
        add_action('add_meta_boxes', function () {
            add_meta_box('tix_org_domain', 'Eigene Domain', [__CLASS__, 'render_box'], 'tix_organizer', 'side', 'default');
        });
        add_action('save_post_tix_organizer', [__CLASS__, 'save_box'], 30, 2);
        add_action('tix_org_account_sections', [__CLASS__, 'render_account_section'], 20, 2);
        add_action('admin_post_tix_org_domain', [__CLASS__, 'handle_form']);
        add_action('admin_menu', [__CLASS__, 'menu'], 47);
        add_action('admin_post_tix_org_domain_settings', [__CLASS__, 'save_settings']);
    }

    // ── Einstellungen ──

    public static function settings() {
        $s = get_option(self::OPTION, []);
        $s = is_array($s) ? $s : [];
        return [
            'cname_target' => strtolower(trim((string) ($s['cname_target'] ?? 'domains.evendis.de'), " .\t\n")),
            'a_records'    => array_values(array_filter(array_map('trim', (array) ($s['a_records'] ?? [])))),
            'aaaa_records' => array_values(array_filter(array_map('trim', (array) ($s['aaaa_records'] ?? [])))),
            'ask_secret'   => (string) ($s['ask_secret'] ?? ''),
        ];
    }

    // ── Daten ──

    public static function allowed($oid) {
        return (bool) get_post_meta(intval($oid), self::META_ALLOW, true);
    }

    public static function get($oid) {
        $d = get_post_meta(intval($oid), self::META_DOMAIN, true);
        return (is_array($d) && !empty($d['host'])) ? $d : null;
    }

    public static function active_host($oid) {
        $d = self::get($oid);
        return ($d && $d['status'] === 'active' && self::allowed($oid)) ? (string) $d['host'] : '';
    }

    /** Liste aktiver Domains (host → organizer_id) neu schreiben (für TIX_Customer_URL, autoload). */
    public static function sync_active() {
        global $wpdb;
        $map = [];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_DOMAIN));
        foreach ((array) $rows as $r) {
            $d = maybe_unserialize($r->meta_value);
            if (is_array($d) && ($d['status'] ?? '') === 'active' && !empty($d['host']) && get_post_status($r->post_id) === 'publish' && self::allowed($r->post_id)) $map[$d['host']] = intval($r->post_id);
        }
        ksort($map);
        $old = get_option(TIX_Customer_URL::OPTION_DOMAINS, []);
        update_option(TIX_Customer_URL::OPTION_DOMAINS, $map, true);
        // Website neu aufbauen für angelegte, geänderte oder abgeschaltete Domains (Vertrag evendis-web)
        if (class_exists('TIX_Web_Revalidate')) {
            $old = is_array($old) ? $old : [];
            foreach (array_unique(array_merge(array_keys($old), array_keys($map))) as $h) {
                if (($old[$h] ?? null) !== ($map[$h] ?? null)) TIX_Web_Revalidate::touch_domain($h);
            }
        }
        if (class_exists('TIX_App_Scope') && method_exists('TIX_App_Scope', 'flush_public_cache')) TIX_App_Scope::flush_public_cache();
        return $map;
    }

    /** Hostname prüfen und normalisieren (ohne Schema, Pfad, Port, „www.“ bleibt erhalten). */
    public static function normalize($input) {
        $h = strtolower(trim((string) $input));
        $h = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $h);
        $h = preg_replace('#[/?\\#].*$#', '', $h);
        $h = preg_replace('/:\\d+$/', '', $h);
        $h = rtrim($h, '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\\x20-\\x7e]/', $h)) { $a = idn_to_ascii($h, 0, INTL_IDNA_VARIANT_UTS46); if ($a) $h = $a; }
        if (strlen($h) > 253 || !preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/', $h)) {
            return new WP_Error('tix_invalid_domain', 'Bitte eine gültige Domain angeben, z. B. tickets.dein-club.de.', ['status' => 400, 'field' => 'host']);
        }
        $blocked = self::BLOCKED;
        foreach ([TIX_Customer_URL::host(), (string) parse_url((string) get_option('home'), PHP_URL_HOST)] as $own) if ($own) $blocked[] = preg_replace('/^(www|veranstalter)\\./', '', strtolower($own));
        foreach (array_unique($blocked) as $b) {
            if ($h === $b || substr($h, -strlen('.' . $b)) === '.' . $b) return new WP_Error('tix_invalid_domain', 'Domains der Plattform können nicht verwendet werden.', ['status' => 400, 'field' => 'host']);
        }
        return $h;
    }

    private static function owner_of($host) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_DOMAIN));
        foreach ((array) $rows as $r) { $d = maybe_unserialize($r->meta_value); if (is_array($d) && ($d['host'] ?? '') === $host) return intval($r->post_id); }
        return 0;
    }

    /** Domain setzen (Inhaber/Team-Admin): prüfen, speichern, gleich DNS prüfen. */
    public static function set($oid, $input) {
        $oid = intval($oid);
        if (!self::allowed($oid)) return new WP_Error('tix_domain_not_allowed', 'Eine eigene Domain ist für dein Konto nicht freigeschaltet. Frag uns gern danach.', ['status' => 403]);
        $h = self::normalize($input);
        if (is_wp_error($h)) return $h;
        $owner = self::owner_of($h);
        if ($owner && $owner !== $oid) return new WP_Error('tix_domain_taken', 'Diese Domain ist schon vergeben.', ['status' => 409, 'field' => 'host']);
        $cur = self::get($oid);
        if (!$cur || $cur['host'] !== $h) {
            update_post_meta($oid, self::META_DOMAIN, ['host' => $h, 'status' => 'pending_dns', 'checked_at' => 0, 'error' => '', 'created_at' => time(), 'activated_at' => 0]);
        }
        self::check($oid);
        self::sync_active();
        return self::get($oid);
    }

    public static function remove($oid) {
        delete_post_meta(intval($oid), self::META_DOMAIN);
        self::sync_active();
    }

    /** DNS-Einträge eines Hosts (überschreibbar für Tests über den Filter tix_org_domain_dns). */
    private static function dns($host) {
        $pre = apply_filters('tix_org_domain_dns', null, $host);
        if (is_array($pre)) return $pre;
        if (!function_exists('dns_get_record')) return ['cname' => [], 'a' => [], 'aaaa' => []];
        $out = ['cname' => [], 'a' => [], 'aaaa' => []];
        $c = @dns_get_record($host, DNS_CNAME);
        foreach ((array) $c as $r) if (!empty($r['target'])) $out['cname'][] = strtolower(rtrim($r['target'], '.'));
        $a = @dns_get_record($host, DNS_A);
        foreach ((array) $a as $r) if (!empty($r['ip'])) $out['a'][] = $r['ip'];
        $aa = @dns_get_record($host, DNS_AAAA);
        foreach ((array) $aa as $r) if (!empty($r['ipv6'])) $out['aaaa'][] = strtolower($r['ipv6']);
        return $out;
    }

    /** DNS prüfen und Status setzen; beim ersten „aktiv“ Mail + Mitteilung. */
    public static function check($oid) {
        $oid = intval($oid);
        $d = self::get($oid);
        if (!$d) return null;
        $s = self::settings();
        $dns = self::dns($d['host']);
        $ok = false; $err = '';
        if ($dns['cname']) {
            $ok = $s['cname_target'] !== '' && in_array($s['cname_target'], $dns['cname'], true);
            if (!$ok) $err = 'Der CNAME zeigt auf ' . implode(', ', $dns['cname']) . ' statt auf ' . $s['cname_target'] . '.';
        } elseif ($dns['a'] || $dns['aaaa']) {
            $ok = ($s['a_records'] && array_intersect($dns['a'], $s['a_records'])) || ($s['aaaa_records'] && array_intersect($dns['aaaa'], array_map('strtolower', $s['aaaa_records'])));
            if (!$ok) $err = 'Die Domain zeigt auf ' . implode(', ', array_merge($dns['a'], $dns['aaaa'])) . '. Erwartet: ' . ($s['a_records'] ? implode(', ', array_merge($s['a_records'], $s['aaaa_records'])) : 'CNAME auf ' . $s['cname_target']) . '.';
        }
        $before = $d['status'];
        $d['checked_at'] = time();
        if ($ok) { $d['status'] = 'active'; $d['error'] = ''; if (empty($d['activated_at'])) $d['activated_at'] = time(); }
        elseif ($err !== '') { $d['status'] = 'error'; $d['error'] = $err; }
        else { $d['status'] = $before === 'active' ? 'error' : 'pending_dns'; $d['error'] = $before === 'active' ? 'Für die Domain sind keine DNS-Einträge mehr zu finden.' : ''; }
        update_post_meta($oid, self::META_DOMAIN, $d);
        if ($before !== $d['status']) self::sync_active();
        if ($d['status'] === 'active' && $before !== 'active') self::notify_active($oid, $d['host']);
        return $d;
    }

    private static function notify_active($oid, $host) {
        $name = html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
        $u = get_user_by('id', intval(get_post_meta($oid, '_tix_org_user_id', true)));
        if ($u && is_email($u->user_email) && class_exists('TIX_Payout_Details')) {
            TIX_Payout_Details::mail($u->user_email, 'Deine Domain ist aktiv: ' . $host, 'Eigene Domain aktiv',
                '<p>Hallo,</p><p>die Seite von <strong>' . esc_html($name) . '</strong> ist jetzt unter <a href="https://' . esc_attr($host) . '/">https://' . esc_html($host) . '/</a> erreichbar. Das Zertifikat wird beim ersten Aufruf automatisch ausgestellt; das kann ein paar Minuten dauern.</p>');
        }
        if (class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item') && class_exists('TIX_Org_Approval')) {
            foreach (TIX_Org_Approval::manager_ids($oid) as $uid) TIX_Notifications::add_user_item($uid, 'Deine Domain ist aktiv', 'Deine Seite ist jetzt unter ' . $host . ' erreichbar.', ['type' => 'message', 'action' => 'organizer:domain']);
        }
    }

    /** Stündlich: offene/fehlerhafte Domains prüfen, aktive einmal am Tag. */
    public static function cron() {
        global $wpdb;
        $n = 0;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_DOMAIN));
        foreach ((array) $rows as $r) {
            $d = maybe_unserialize($r->meta_value);
            if (!is_array($d) || empty($d['host']) || !self::allowed($r->post_id)) continue;
            $due = $d['status'] === 'active' ? DAY_IN_SECONDS : HOUR_IN_SECONDS - 120;
            if (time() - intval($d['checked_at'] ?? 0) < $due) continue;
            self::check($r->post_id); $n++;
        }
        return $n;
    }

    // ── Anleitung ──

    /** DNS-Anleitung für die App/Mein Konto. */
    public static function dns_payload($host = '') {
        $s = self::settings();
        $apex = $host !== '' && substr_count($host, '.') === 1;
        $steps = [];
        if ($host === '' || !$apex) $steps[] = 'Lege bei deinem Domain-Anbieter für ' . ($host ?: 'deine Subdomain (z. B. tickets.dein-club.de)') . ' einen CNAME-Eintrag auf ' . $s['cname_target'] . ' an.';
        if ($host === '' || $apex) $steps[] = 'Für die Hauptdomain (' . ($host ?: 'dein-club.de') . ') geht kein CNAME: lege A-Einträge' . ($s['aaaa_records'] ? ' bzw. AAAA-Einträge' : '') . ' auf ' . ($s['a_records'] || $s['aaaa_records'] ? implode(', ', array_merge($s['a_records'], $s['aaaa_records'])) : '(Adresse folgt)') . ' an.';
        $steps[] = 'Änderungen am DNS brauchen bis zu 24 Stunden. Wir prüfen stündlich; mit „Jetzt prüfen“ geht es sofort.';
        return ['cname_target' => $s['cname_target'], 'a_records' => $s['a_records'], 'aaaa_records' => $s['aaaa_records'], 'apex' => $apex, 'steps' => $steps];
    }

    public static function payload($oid) {
        $d = self::get($oid);
        return [
            'ok'       => true,
            'allowed'  => self::allowed($oid),
            'fee_note' => (string) get_post_meta($oid, self::META_FEE, true),
            'domain'   => $d ? ['host' => $d['host'], 'status' => $d['status'], 'error' => (string) $d['error'],
                'checked_at' => !empty($d['checked_at']) ? gmdate('Y-m-d\TH:i:s\Z', intval($d['checked_at'])) : null,
                'activated_at' => !empty($d['activated_at']) ? gmdate('Y-m-d\TH:i:s\Z', intval($d['activated_at'])) : null,
                'url' => $d['status'] === 'active' ? 'https://' . $d['host'] . '/' : null] : null,
            'dns'      => self::dns_payload($d ? $d['host'] : ''),
        ];
    }

    // ── Bestellungen ──

    /** Herkunft einer Bestellung merken, wenn sie über eine aktive eigene Domain eines beteiligten Veranstalters kam. */
    public static function remember_origin($order_id, $host, array $event_ids = []) {
        $h = self::resolve_host($host);
        if ($h === '') return false;
        $map = get_option(TIX_Customer_URL::OPTION_DOMAINS, []);
        $oid = intval($map[$h]);
        if ($event_ids) {
            $hit = false;
            foreach ($event_ids as $eid) if (intval(get_post_meta(intval($eid), '_tix_organizer_id', true)) === $oid) $hit = true;
            if (!$hit) return false;
        }
        update_post_meta(intval($order_id), self::META_ORIGIN, $h);
        return true;
    }

    /**
     * Für Mails einer Bestellung: Kunden-Adresse auf die Herkunfts-Domain setzen. Gibt ein Objekt zurück, das beim Verlassen
     * der aufrufenden Funktion die vorherige Adresse wiederherstellt (in einer lokalen Variable halten).
     */
    public static function scope_order($order_id) {
        if (!self::multi() || !class_exists('TIX_Customer_URL')) return null;
        $h = (string) get_post_meta(intval($order_id), self::META_ORIGIN, true);
        $map = get_option(TIX_Customer_URL::OPTION_DOMAINS, []);
        if ($h === '' || !is_array($map) || !isset($map[$h])) return null;
        return new TIX_Org_Domains_Scope(TIX_Customer_URL::use_base('https://' . $h));
    }

    // ── REST ──

    public static function register_routes() {
        $mgr = ['TIX_App_Scope', 'check_manager'];
        register_rest_route(self::NS, '/organizer/domain', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $mgr],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_set'], 'permission_callback' => $mgr],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $mgr],
        ]);
        register_rest_route(self::NS, '/organizer/domain/check', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_check'], 'permission_callback' => $mgr]);
        register_rest_route(self::NS, '/public/domains', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/public/domains/allowed', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_allowed'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/public/domains/(?P<host>[A-Za-z0-9.-]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_resolve'], 'permission_callback' => '__return_true']);
    }

    private static function oid() { return intval(TIX_App_Scope::organizer_id_for_user()); }

    public static function rest_get(WP_REST_Request $req) { return rest_ensure_response(self::payload(self::oid())); }

    public static function rest_set(WP_REST_Request $req) {
        $r = self::set(self::oid(), (string) $req->get_param('host'));
        return is_wp_error($r) ? $r : rest_ensure_response(self::payload(self::oid()));
    }

    public static function rest_check(WP_REST_Request $req) {
        $oid = self::oid();
        if (!self::get($oid)) return new WP_Error('tix_no_domain', 'Es ist keine Domain eingetragen.', ['status' => 404]);
        self::check($oid);
        return rest_ensure_response(self::payload($oid));
    }

    public static function rest_delete(WP_REST_Request $req) {
        self::remove(self::oid());
        return rest_ensure_response(self::payload(self::oid()));
    }

    private static function public_entry($host, $oid) {
        return ['host' => $host, 'organizer_id' => intval($oid), 'slug' => class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : (string) get_post_field('post_name', $oid),
            'design' => class_exists('TIX_Organizer_Page') ? TIX_Organizer_Page::page_design($oid) : 'galerie', 'active' => true];
    }

    /** Aktive Domain zu einem Host – auch mit bzw. ohne „www.“ (liefert die eingetragene Hauptadresse) oder ''. */
    public static function resolve_host($host) {
        $h = strtolower(rtrim(preg_replace('/:\d+$/', '', trim((string) $host)), '.'));
        $map = get_option(TIX_Customer_URL::OPTION_DOMAINS, []);
        if (!is_array($map)) return '';
        foreach ([$h, strpos($h, 'www.') === 0 ? substr($h, 4) : 'www.' . $h] as $c) if (isset($map[$c])) return $c;
        return '';
    }

    /** Öffentlich: Domain → Veranstalter (nur aktive, keine persönlichen Daten); www.-Variante liefert die Hauptadresse. */
    public static function rest_resolve(WP_REST_Request $req) {
        $h = self::resolve_host((string) $req['host']);
        $map = get_option(TIX_Customer_URL::OPTION_DOMAINS, []);
        if ($h === '' || (class_exists('TIX_Org_Approval') && !TIX_Org_Approval::is_public($map[$h]))) return new WP_Error('tix_domain', 'Unbekannte Domain.', ['status' => 404]);
        $entry = self::public_entry($h, $map[$h]);
        // Vertrag evendis-web: {domain: {host, organizer_id, slug, design, active}}; die flachen Felder bleiben zusätzlich
        $res = rest_ensure_response(['ok' => true, 'domain' => $entry] + $entry);
        $res->header('Cache-Control', 'public, max-age=300');
        return $res;
    }

    /** Öffentlich: Liste aktiver Domains (für Zertifikate/Routing). */
    public static function rest_list(WP_REST_Request $req) {
        $map = get_option(TIX_Customer_URL::OPTION_DOMAINS, []);
        $out = [];
        foreach ((array) $map as $h => $oid) if (!class_exists('TIX_Org_Approval') || TIX_Org_Approval::is_public($oid)) $out[] = self::public_entry($h, $oid);
        $res = rest_ensure_response(['ok' => true, 'domains' => $out]);
        $res->header('Cache-Control', 'public, max-age=300');
        return $res;
    }

    /** On-Demand-TLS (z. B. Caddy „ask“): 200, wenn der Host eine aktive Domain ist; mit gesetztem Geheimnis nur mit secret. */
    public static function rest_allowed(WP_REST_Request $req) {
        $s = self::settings();
        if ($s['ask_secret'] !== '' && !hash_equals($s['ask_secret'], (string) $req->get_param('secret'))) return new WP_Error('tix_forbidden', 'Nicht erlaubt.', ['status' => 404]);
        // Zertifikat auch für die www.-Variante (das Web leitet sie auf die Hauptadresse um)
        if (self::resolve_host((string) ($req->get_param('domain') ?: $req->get_param('host'))) === '') return new WP_Error('tix_domain', 'Unbekannte Domain.', ['status' => 404]);
        return rest_ensure_response(['ok' => true]);
    }

    // ── WP-Admin ──

    public static function render_box($post) {
        $oid = intval($post->ID);
        $d = self::get($oid);
        wp_nonce_field('tix_org_domain_box', 'tix_org_domain_nonce');
        echo '<p><label><input type="checkbox" name="tix_org_domain_allowed" value="1" ' . checked(self::allowed($oid), true, false) . '> <strong>Eigene Domain erlaubt</strong></label></p>'
            . '<p><label>Zusatz (Hinweis, noch keine Abrechnung)<br><input type="text" class="widefat" name="tix_org_domain_fee_note" value="' . esc_attr((string) get_post_meta($oid, self::META_FEE, true)) . '" placeholder="z. B. 9 € / Monat"></label></p>';
        if ($d) {
            $lbl = ['pending_dns' => 'wartet auf DNS', 'active' => 'aktiv', 'error' => 'Fehler'][$d['status']] ?? $d['status'];
            echo '<p>Domain: <strong>' . esc_html($d['host']) . '</strong><br>Status: ' . esc_html($lbl) . ($d['error'] !== '' ? '<br><small style="color:#b91c1c;">' . esc_html($d['error']) . '</small>' : '') . '</p>';
        } else {
            echo '<p class="description">Noch keine Domain eingetragen (macht der Veranstalter in „Mein Konto“ oder in der App).</p>';
        }
    }

    public static function save_box($post_id, $post) {
        if (!isset($_POST['tix_org_domain_nonce']) || !wp_verify_nonce($_POST['tix_org_domain_nonce'], 'tix_org_domain_box')) return;
        if (!current_user_can('manage_options')) return;
        $was = self::allowed($post_id);
        if (!empty($_POST['tix_org_domain_allowed'])) update_post_meta($post_id, self::META_ALLOW, 1); else delete_post_meta($post_id, self::META_ALLOW);
        update_post_meta($post_id, self::META_FEE, mb_substr(sanitize_text_field(wp_unslash($_POST['tix_org_domain_fee_note'] ?? '')), 0, 120));
        if ($was !== self::allowed($post_id)) self::sync_active();
    }

    public static function render_account_section($oid, $manager) {
        if (!self::allowed($oid) || !$manager) return;
        $p = self::payload($oid);
        $d = $p['domain'];
        $box = 'background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin:0 0 18px;max-width:820px;';
        $act = esc_url(admin_url('admin-post.php'));
        $nonce = wp_nonce_field('tix_org_domain', '_wpnonce', true, false);
        echo '<div id="tix-acc-domain" style="' . $box . '"><h2 style="margin-top:0;">Eigene Domain</h2>';
        if ($p['fee_note'] !== '') echo '<p style="color:#6b7280;margin-top:0;">Zusatzleistung: ' . esc_html($p['fee_note']) . '</p>';
        if ($d) {
            $col = ['pending_dns' => '#f59e0b', 'active' => '#16a34a', 'error' => '#ef4444'][$d['status']] ?? '#6b7280';
            $lbl = ['pending_dns' => 'Wartet auf DNS', 'active' => 'Aktiv', 'error' => 'DNS stimmt nicht'][$d['status']] ?? $d['status'];
            echo '<p><strong>' . esc_html($d['host']) . '</strong> · <span style="color:' . $col . ';font-weight:700;">' . esc_html($lbl) . '</span>' . ($d['url'] ? ' · <a href="' . esc_url($d['url']) . '" target="_blank">öffnen</a>' : '') . '</p>';
            if ($d['error'] !== '') echo '<p style="color:#b91c1c;">' . esc_html($d['error']) . '</p>';
            if ($d['status'] !== 'active') { echo '<ol>'; foreach ($p['dns']['steps'] as $st) echo '<li>' . esc_html($st) . '</li>'; echo '</ol>'; }
            echo '<form method="post" action="' . $act . '" style="display:inline-block;margin-right:8px;">' . $nonce . '<input type="hidden" name="action" value="tix_org_domain"><input type="hidden" name="do" value="check"><button class="button">Jetzt prüfen</button></form>';
            echo '<form method="post" action="' . $act . '" style="display:inline-block;" onsubmit="return confirm(\'Domain wirklich entfernen?\');">' . $nonce . '<input type="hidden" name="action" value="tix_org_domain"><input type="hidden" name="do" value="remove"><button class="button-link" style="color:#b91c1c;">Entfernen</button></form>';
        } else {
            echo '<form method="post" action="' . $act . '">' . $nonce . '<input type="hidden" name="action" value="tix_org_domain"><input type="hidden" name="do" value="set">'
                . '<p><label>Domain<br><input type="text" name="host" class="regular-text" placeholder="tickets.dein-club.de" required></label></p><button class="button button-primary">Speichern</button></form>';
            echo '<ol>'; foreach ($p['dns']['steps'] as $st) echo '<li>' . esc_html($st) . '</li>'; echo '</ol>';
        }
        echo '</div>';
    }

    public static function handle_form() {
        check_admin_referer('tix_org_domain');
        $oid = self::oid();
        $back = admin_url('admin.php?page=' . (class_exists('TIX_Org_Approval') ? TIX_Org_Approval::ORG_SLUG : '') . '#tix-acc-domain');
        if (!$oid || !TIX_App_Scope::is_org_manager()) { wp_safe_redirect(add_query_arg('err', rawurlencode('Kein Zugriff.'), $back)); exit; }
        $do = sanitize_key($_POST['do'] ?? '');
        $r = true;
        if ($do === 'set') $r = self::set($oid, sanitize_text_field(wp_unslash($_POST['host'] ?? '')));
        elseif ($do === 'check') self::check($oid);
        elseif ($do === 'remove') self::remove($oid);
        wp_safe_redirect(add_query_arg(is_wp_error($r) ? ['err' => rawurlencode($r->get_error_message())] : ['msg' => rawurlencode('Gespeichert.')], $back));
        exit;
    }

    public static function menu() {
        add_submenu_page('tixomat', 'Eigene Domains', 'Eigene Domains', 'manage_options', 'tix-org-domains', [__CLASS__, 'render_admin']);
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_org_domain_settings');
        $lines = function ($v) { return array_values(array_filter(array_map('trim', preg_split('/[\\s,]+/', (string) wp_unslash($v))), function ($x) { return filter_var($x, FILTER_VALIDATE_IP) !== false; })); };
        $s = self::settings();
        update_option(self::OPTION, [
            'cname_target' => strtolower(trim(sanitize_text_field(wp_unslash($_POST['cname_target'] ?? '')), ' .')) ?: 'domains.evendis.de',
            'a_records'    => array_values(array_filter($lines($_POST['a_records'] ?? ''), function ($x) { return filter_var($x, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4); })),
            'aaaa_records' => array_values(array_filter($lines($_POST['aaaa_records'] ?? ''), function ($x) { return filter_var($x, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6); })),
            'ask_secret'   => (string) (($_POST['ask_secret'] ?? '') !== '' ? sanitize_text_field(wp_unslash($_POST['ask_secret'])) : $s['ask_secret']),
        ], false);
        wp_safe_redirect(admin_url('admin.php?page=tix-org-domains&saved=1'));
        exit;
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $s = self::settings();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_DOMAIN));
        echo '<div class="wrap"><h1>Eigene Domains</h1>' . (!empty($_GET['saved']) ? '<div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>' : '');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('tix_org_domain_settings', '_wpnonce', true, false) . '<input type="hidden" name="action" value="tix_org_domain_settings"><table class="form-table">'
            . '<tr><th>CNAME-Ziel</th><td><input class="regular-text" name="cname_target" value="' . esc_attr($s['cname_target']) . '"></td></tr>'
            . '<tr><th>A-Einträge (IPv4)</th><td><input class="regular-text" name="a_records" value="' . esc_attr(implode(', ', $s['a_records'])) . '" placeholder="46.224.181.160"></td></tr>'
            . '<tr><th>AAAA-Einträge (IPv6)</th><td><input class="regular-text" name="aaaa_records" value="' . esc_attr(implode(', ', $s['aaaa_records'])) . '"></td></tr>'
            . '<tr><th>Geheimnis für /public/domains/allowed</th><td><input class="regular-text" name="ask_secret" value="" placeholder="' . ($s['ask_secret'] !== '' ? 'gesetzt – leer lassen zum Behalten' : 'optional') . '" autocomplete="off"></td></tr>'
            . '</table><p><button class="button button-primary">Speichern</button></p></form>';
        echo '<table class="widefat striped"><thead><tr><th>Veranstalter</th><th>Domain</th><th>Status</th><th>geprüft</th></tr></thead><tbody>';
        foreach ((array) $rows as $r) {
            $d = maybe_unserialize($r->meta_value);
            if (!is_array($d)) continue;
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($r->post_id)) . '">' . esc_html(get_the_title($r->post_id)) . '</a>' . (self::allowed($r->post_id) ? '' : ' <em>(nicht freigeschaltet)</em>') . '</td><td>' . esc_html($d['host']) . '</td><td>' . esc_html($d['status']) . ($d['error'] ? ' – ' . esc_html($d['error']) : '') . '</td><td>' . (!empty($d['checked_at']) ? esc_html(wp_date('d.m.Y H:i', intval($d['checked_at']))) : '–') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

/** Stellt die Kunden-Adresse beim Verlassen des Gültigkeitsbereichs wieder her (siehe TIX_Org_Domains::scope_order). */
class TIX_Org_Domains_Scope {
    private $prev;
    public function __construct($prev) { $this->prev = $prev; }
    public function __destruct() { if (class_exists('TIX_Customer_URL')) TIX_Customer_URL::use_base($this->prev); }
}
