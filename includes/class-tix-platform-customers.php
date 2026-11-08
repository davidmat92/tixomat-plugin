<?php
if (!defined('ABSPATH')) exit;

/**
 * Kunden an einem Ort (1.38.684, Doku §100, Vertrag uebergaben/vertrag-platform-customers.md,
 * Konzept konzept-studio-kundenverwaltung.md Abschnitt 7).
 *
 *   GET  /platform/customers?q=&art=&attention=1      Liste mit Zählern
 *   GET  /platform/customers/{key}                    Akte (Veranstalter, Lizenz, Partner, Module beider Ebenen, Zähler)
 *   POST /platform/customers/{key}/offers {offer_id}  Angebot fest mit dem Kunden verknüpfen (DELETE {offer_id} löst)
 *   Filter ?customer=<key> zusätzlich an /platform/support, /platform/settlements, /platform/offers.
 *
 * Ein Kunde wird aus drei Quellen zusammengesetzt, verbunden über feste IDs (nie über Namen):
 *   Lizenz (Lizenz-Server, gleiche Seite)  ── Host der Lizenz-Seite bzw. „Host …“ in der Notiz ──┐
 *   Partner-Verzeichnis (eigene Tixomat-Seite, api_base-Host, organizer_id) ────────────────────┤→ Veranstalter (tix_organizer)
 *   Hosting-Kurzname aus der Lizenz-Notiz „[kunden-hetzner:<kurz>]“ (setzt der Kunden-Dienst)
 *
 * Art: hosted (Lizenz mit Kurzname) · bestand (eigene Seite ohne Kunden-Dienst, z. B. KitchenKlub/Mallorca) ·
 *      license (nur Lizenz) · provision (Veranstalter auf der Plattform).
 * Schlüssel: h-<kurz> vor l-<lizenz-id> vor v-<veranstalter-id> vor p-<partner-id>. Jeder Teil-Schlüssel bleibt als
 * Alias gültig (Weiterleitungen /plattform/veranstalter/12/ → v-12), die Antwort nennt den festen `key`.
 * Nur Plattform-Admins (manage_options) im Mehr-Veranstalter-Modus.
 */
class TIX_Platform_Customers {

    const NS = 'tixomat/v1';
    const ARTS = ['provision', 'hosted', 'bestand', 'license'];
    const KEY_RE = '/^(h-[a-z][a-z0-9]{1,15}|[lv]-\d{1,20}|p-[a-z0-9_-]{1,60})$/';
    const EXPIRING_DAYS = 30;

    private static $cache = null;
    private static $memo = [];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function perm() {
        if (!self::multi()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur für Plattform-Admins.', ['status' => 403]);
    }

    public static function routes() {
        $p = ['permission_callback' => [__CLASS__, 'perm']];
        register_rest_route(self::NS, '/platform/customers', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list']] + $p);
        register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get']] + $p);
        register_rest_route(self::NS, '/platform/customers/invite', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_invite']] + $p);
        register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/owner', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_owner']] + $p);
        register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/license', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_license']] + $p);
        register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/modules', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_modules']] + $p);
        register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/offers', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_link_offer']] + $p,
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_unlink_offer']] + $p,
        ]);
    }

    public static function flush() {
        self::$cache = null;
        self::$memo = [];
    }

    // ═══════════════ Quellen ═══════════════

    private static function host_of($url) {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url((string) $url, PHP_URL_HOST)));
    }

    private static function licenses() {
        global $wpdb;
        if (!class_exists('TIX_License_Server') || !TIX_License_Server::enabled()) return [];
        $t = $wpdb->prefix . 'tix_licenses';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return [];
        $rows = (array) $wpdb->get_results("SELECT * FROM $t ORDER BY id ASC LIMIT 1000");
        $sites = [];
        $st = $wpdb->prefix . 'tix_license_sites';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $st)) === $st) {
            foreach ((array) $wpdb->get_results("SELECT license_id, host, dev, last_seen FROM $st ORDER BY last_seen DESC") as $s) $sites[intval($s->license_id)][] = $s;
        }
        $out = [];
        foreach ($rows as $l) {
            $id = intval($l->id);
            $hosts = [];
            foreach ($sites[$id] ?? [] as $s) if (!$s->dev) $hosts[] = strtolower(preg_replace('/^www\./', '', (string) $s->host));
            $kurz = preg_match('/\[kunden-hetzner:([a-z][a-z0-9]{1,15})\]/', (string) $l->note, $m) ? $m[1] : '';
            if (preg_match('/Host ([a-z0-9.-]+\.[a-z]{2,})/i', (string) $l->note, $m)) $hosts[] = strtolower(preg_replace('/^www\./', '', rtrim($m[1], '.')));
            $out[$id] = ['row' => $l, 'hosts' => array_values(array_unique($hosts)), 'kurz' => $kurz];
        }
        return $out;
    }

    private static function partners() {
        if (!class_exists('TIX_Partners')) return [];
        $out = [];
        foreach (TIX_Partners::all() as $id => $p) {
            $h = self::host_of($p['api_base'] ?? '');
            if ($h === '') continue;
            $out[(string) $id] = ['id' => (string) $id, 'name' => (string) ($p['name'] ?? ''), 'host' => $h, 'organizer_id' => intval($p['organizer_id'] ?? 0),
                'sales_enabled' => !empty($p['sales_enabled']), 'pending' => !empty($p['pending'])];
        }
        return $out;
    }

    private static function organizer_ids() {
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => ['publish', 'pending', 'draft', 'private'], 'posts_per_page' => 2000, 'fields' => 'ids',
            'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true]);
        $sbx = class_exists('TIX_Sales') && method_exists('TIX_Sales', 'sandbox_ids') ? array_map('intval', (array) TIX_Sales::sandbox_ids()) : [];
        return array_values(array_diff(array_map('intval', $ids), $sbx));
    }

    // ═══════════════ Zusammensetzen ═══════════════

    /** Alle Kunden {key => kunde} plus Alias-Tabelle; einmal je Anfrage berechnet. */
    public static function index() {
        if (self::$cache !== null) return self::$cache;
        $lics = self::licenses();
        $partners = self::partners();
        $orgs = self::organizer_ids();
        $org_set = array_flip($orgs);
        $by_host = [];
        foreach ($partners as $p) $by_host[$p['host']] = $p;

        $customers = [];
        $alias = [];
        $used_org = [];
        $used_partner = [];

        foreach ($lics as $lid => $L) {
            $partner = null;
            foreach ($L['hosts'] as $h) if (isset($by_host[$h])) { $partner = $by_host[$h]; break; }
            $oid = $partner && isset($org_set[$partner['organizer_id']]) && !isset($used_org[$partner['organizer_id']]) ? $partner['organizer_id'] : 0;
            $art = $L['kurz'] !== '' ? 'hosted' : ($partner ? 'bestand' : 'license');
            $key = $L['kurz'] !== '' ? 'h-' . $L['kurz'] : 'l-' . $lid;
            if (isset($customers[$key])) $key = 'l-' . $lid; // doppelter Kurzname (sollte es nicht geben)
            $customers[$key] = ['key' => $key, 'art' => $art, 'organizer_id' => $oid ?: null, 'license_id' => $lid, 'hosting_kurz' => $L['kurz'] ?: null,
                'partner_id' => $partner ? $partner['id'] : null, '_lic' => $L, '_partner' => $partner];
            $alias['l-' . $lid] = $key;
            if ($oid) { $alias['v-' . $oid] = $key; $used_org[$oid] = true; }
            if ($partner) { $alias['p-' . $partner['id']] = $alias['p-' . $partner['id']] ?? $key; $used_partner[$partner['id']] = true; }
        }
        foreach ($partners as $p) {
            if (isset($used_partner[$p['id']])) continue;
            $oid = isset($org_set[$p['organizer_id']]) && !isset($used_org[$p['organizer_id']]) ? $p['organizer_id'] : 0;
            $key = $oid ? 'v-' . $oid : 'p-' . $p['id'];
            $customers[$key] = ['key' => $key, 'art' => 'bestand', 'organizer_id' => $oid ?: null, 'license_id' => null, 'hosting_kurz' => null,
                'partner_id' => $p['id'], '_lic' => null, '_partner' => $p];
            $alias['p-' . $p['id']] = $key;
            if ($oid) { $alias['v-' . $oid] = $key; $used_org[$oid] = true; }
        }
        foreach ($orgs as $oid) {
            if (isset($used_org[$oid])) continue;
            $key = 'v-' . $oid;
            $customers[$key] = ['key' => $key, 'art' => 'provision', 'organizer_id' => $oid, 'license_id' => null, 'hosting_kurz' => null,
                'partner_id' => null, '_lic' => null, '_partner' => null];
            $alias[$key] = $key;
        }
        foreach ($customers as $k => $c) {
            $alias[$k] = $k;
            if ($c['hosting_kurz']) $alias['h-' . $c['hosting_kurz']] = $k;
        }
        return self::$cache = ['customers' => $customers, 'alias' => $alias];
    }

    /** Fester Schlüssel zu einem beliebigen Teil-Schlüssel (v-12, l-5, h-xxl, p-abc) oder null. */
    public static function canonical($key) {
        $key = strtolower(trim((string) $key));
        if (!preg_match(self::KEY_RE, $key)) return null;
        $i = self::index();
        return $i['alias'][$key] ?? null;
    }

    public static function raw($key) {
        $k = self::canonical($key);
        return $k ? self::index()['customers'][$k] : null;
    }

    /** E-Mail-Adressen des Kunden (Inhaber, Lizenz) – für die Zuordnung von Angeboten ohne feste Verknüpfung. */
    public static function emails($c) {
        $out = [];
        if ($c['organizer_id']) {
            $u = get_userdata(intval(get_post_meta($c['organizer_id'], '_tix_org_user_id', true)));
            $out[] = $u ? (string) $u->user_email : (string) get_post_meta($c['organizer_id'], '_tix_org_email', true);
        }
        if ($c['_lic']) $out[] = (string) $c['_lic']['row']->email;
        return array_values(array_unique(array_filter(array_map('strtolower', $out), 'is_email')));
    }

    /** Hosts, über die Hilfe-Anfragen eigener Seiten zugeordnet werden. */
    public static function hosts($c) {
        $h = $c['_lic'] ? $c['_lic']['hosts'] : [];
        if ($c['_partner']) $h[] = $c['_partner']['host'];
        return array_values(array_unique(array_filter($h)));
    }

    // ═══════════════ Aufmerksamkeit / Zähler ═══════════════

    private static function support_rows() {
        if (isset(self::$memo['support'])) return self::$memo['support'];
        $rows = [];
        if (!class_exists('TIX_Platform_Admin')) return $rows;
        $ids = get_posts(['post_type' => 'tix_support_ticket', 'post_status' => ['tix_open', 'tix_progress'], 'posts_per_page' => 500, 'fields' => 'ids',
            'meta_query' => [['key' => TIX_Platform_Admin::HELP_META, 'value' => '1']], 'no_found_rows' => true]);
        foreach ($ids as $tid) {
            $rows[] = ['id' => intval($tid), 'org' => intval(get_post_meta($tid, '_tix_sp_help_org', true)),
                'site' => self::host_of((string) get_post_meta($tid, '_tix_sp_help_site', true)) ?: strtolower((string) get_post_meta($tid, '_tix_sp_help_site', true)),
                'unread' => (bool) get_post_meta($tid, '_tix_sp_help_unread', true), 'status' => get_post_status($tid)];
        }
        return self::$memo['support'] = $rows;
    }

    private static function settlement_counts() {
        global $wpdb;
        if (isset(self::$memo['settlements'])) return self::$memo['settlements'];
        $out = [];
        $t = $wpdb->prefix . 'tix_settlements';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) {
            foreach ((array) $wpdb->get_results("SELECT organizer_id, status, COUNT(*) n FROM $t WHERE status <> 'void' GROUP BY organizer_id, status") as $r) {
                $out[intval($r->organizer_id)][(string) $r->status] = intval($r->n);
            }
        }
        return self::$memo['settlements'] = $out;
    }

    private static function event_counts() {
        global $wpdb;
        if (isset(self::$memo['events'])) return self::$memo['events'];
        $out = [];
        $today = current_time('Y-m-d');
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_value oid, COUNT(DISTINCT p.ID) n, COUNT(DISTINCT CASE WHEN p.post_status IN ('publish','pending','future') AND d.meta_value >= %s THEN p.ID END) up
             FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tix_organizer_id'
             LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_tix_date_start'
             WHERE p.post_type = 'event' AND p.post_status NOT IN ('trash','auto-draft') GROUP BY m.meta_value", $today)) as $r) {
            $out[intval($r->oid)] = [intval($r->n), intval($r->up)];
        }
        return self::$memo['events'] = $out;
    }

    private static function support_for($c) {
        $hosts = self::hosts($c);
        return array_values(array_filter(self::support_rows(), function ($r) use ($c, $hosts) {
            return ($c['organizer_id'] && $r['org'] === intval($c['organizer_id'])) || ($r['site'] !== '' && in_array($r['site'], $hosts, true));
        }));
    }

    public static function attention($c) {
        $a = [];
        $oid = intval($c['organizer_id']);
        if ($oid && class_exists('TIX_Org_Approval')) {
            $st = TIX_Org_Approval::status($oid);
            if ($st === 'pending') $a[] = ['code' => 'approval_pending', 'label' => 'Freigabe offen'];
        }
        if ($c['_lic']) {
            $l = $c['_lic']['row'];
            $state = TIX_License_Server::license_state($l);
            if ($state === 'expired') $a[] = ['code' => 'license_expired', 'label' => 'Lizenz abgelaufen', 'date' => (string) $l->valid_until];
            elseif (in_array($state, ['suspended', 'revoked'], true)) $a[] = ['code' => 'license_' . $state, 'label' => $state === 'suspended' ? 'Lizenz pausiert' : 'Lizenz beendet'];
            elseif ($l->valid_until && $l->valid_until <= gmdate('Y-m-d', current_time('timestamp') + self::EXPIRING_DAYS * DAY_IN_SECONDS)) {
                $a[] = ['code' => 'license_expiring', 'label' => 'Lizenz läuft ab', 'date' => (string) $l->valid_until];
            }
        }
        $sp = self::support_for($c);
        $unread = count(array_filter($sp, function ($r) { return $r['unread']; }));
        if ($unread) $a[] = ['code' => 'support_unread', 'label' => 'Betreiber-Anfrage offen', 'count' => $unread];
        if ($oid) {
            $sc = self::settlement_counts()[$oid] ?? [];
            if (!empty($sc['held'])) $a[] = ['code' => 'settlement_held', 'label' => 'Abrechnung zurückgestellt', 'count' => $sc['held']];
            if (!empty($sc['draft'])) $a[] = ['code' => 'payout_missing', 'label' => 'Auszahlungsdaten fehlen', 'count' => $sc['draft']];
        }
        return $a;
    }

    // ═══════════════ Ausgabe ═══════════════

    private static function org_name($oid) {
        return html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
    }

    private static function org_logo($oid) {
        foreach (['_tix_org_landing_logo_id', '_tix_org_image_id'] as $k) {
            $id = intval(get_post_meta($oid, $k, true));
            if ($id && ($u = wp_get_attachment_image_url($id, 'thumbnail'))) return (string) $u;
        }
        return '';
    }

    private static function domain($c) {
        if ($c['_lic'] && $c['_lic']['hosts']) return $c['_lic']['hosts'][0];
        if ($c['_partner']) return $c['_partner']['host'];
        if ($c['organizer_id']) {
            $d = get_post_meta($c['organizer_id'], '_tix_org_domain', true);
            if (is_array($d) && ($d['status'] ?? '') === 'active' && !empty($d['host'])) return (string) $d['host'];
        }
        return null;
    }

    /** Status in einem Wort: provision → Freigabe-Status, sonst Lizenz-Zustand. Hosting live/aus kennt nur der Kunden-Dienst. */
    private static function status($c) {
        if ($c['_lic']) return ['status' => TIX_License_Server::license_state($c['_lic']['row']), 'status_source' => 'license'];
        if ($c['organizer_id'] && class_exists('TIX_Org_Approval')) return ['status' => TIX_Org_Approval::status($c['organizer_id']), 'status_source' => 'approval'];
        return ['status' => 'active', 'status_source' => 'partner'];
    }

    public static function row($c) {
        $oid = intval($c['organizer_id']);
        $name = $oid ? self::org_name($oid) : '';
        if ($name === '' && $c['_lic']) $name = (string) $c['_lic']['row']->customer;
        if ($name === '' && $c['_partner']) $name = $c['_partner']['name'];
        $ev = $oid ? (self::event_counts()[$oid] ?? [0, 0]) : null;
        $lic = null;
        if ($c['_lic']) {
            $l = $c['_lic']['row'];
            $lic = ['id' => intval($l->id), 'state' => TIX_License_Server::license_state($l), 'stage' => TIX_License_Server::stage_for($l),
                'valid_until' => $l->valid_until ? (string) $l->valid_until : null];
        }
        $aliases = array_keys(array_filter(self::index()['alias'], function ($v) use ($c) { return $v === $c['key']; }));
        sort($aliases);
        return [
            'key' => $c['key'], 'aliases' => $aliases, 'name' => $name, 'art' => $c['art'],
            'organizer_id' => $oid ?: null, 'license_id' => $c['license_id'], 'hosting_kurz' => $c['hosting_kurz'], 'partner_id' => $c['partner_id'],
            'domain' => self::domain($c), 'logo' => $oid ? self::org_logo($oid) : '',
        ] + self::status($c) + [
            'license' => $lic,
            'events' => $ev ? ['all' => $ev[0], 'upcoming' => $ev[1]] : null,
            'attention' => self::attention($c),
        ];
    }

    public static function rest_list(WP_REST_Request $req) {
        $art = (string) $req->get_param('art');
        if ($art !== '' && $art !== 'all' && !in_array($art, self::ARTS, true)) return new WP_Error('tix_invalid_field', 'art: provision, hosted, bestand oder license.', ['status' => 400, 'field' => 'art']);
        $q = mb_strtolower(trim((string) $req->get_param('q')));
        $att = filter_var($req->get_param('attention'), FILTER_VALIDATE_BOOLEAN);
        $counts = array_fill_keys(array_merge(['all'], self::ARTS, ['attention']), 0);
        $out = [];
        foreach (self::index()['customers'] as $c) {
            $row = self::row($c);
            if ($q !== '') {
                $hay = mb_strtolower(implode(' ', array_filter([$row['name'], $row['domain'], $row['hosting_kurz'], $row['key'], implode(' ', self::emails($c))])));
                if (mb_strpos($hay, $q) === false) continue;
            }
            $counts['all']++;
            $counts[$row['art']]++;
            if ($row['attention']) $counts['attention']++;
            if ($art !== '' && $art !== 'all' && $row['art'] !== $art) continue;
            if ($att && !$row['attention']) continue;
            $out[] = $row;
        }
        // Aufmerksamkeit zuerst, dann Name
        usort($out, function ($a, $b) {
            $x = (empty($b['attention']) ? 0 : 1) - (empty($a['attention']) ? 0 : 1);
            return $x ?: strcasecmp($a['name'], $b['name']);
        });
        return rest_ensure_response(['ok' => true, 'customers' => $out, 'counts' => $counts,
            'sources' => ['licenses' => class_exists('TIX_License_Server') && TIX_License_Server::enabled(), 'partners' => class_exists('TIX_Partners')]]);
    }

    public static function rest_get(WP_REST_Request $req) {
        $c = self::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $row = self::row($c);
        $oid = intval($c['organizer_id']);
        $row['requested_key'] = strtolower((string) $req['key']);
        $row['organizer'] = $oid && class_exists('TIX_Platform_Admin') ? TIX_Platform_Admin::org_row($oid) : null;
        $row['license'] = $c['_lic'] ? TIX_License_Server::admin_payload($c['_lic']['row']) : null;
        $row['partner'] = $c['_partner'] ? array_intersect_key($c['_partner'], array_flip(['id', 'name', 'host', 'sales_enabled', 'pending'])) : null;
        $row['emails'] = self::emails($c);
        $row['modules'] = self::modules_payload($c, $row['organizer']);
        $row['owner'] = self::owner_payload($c);
        $row['ready'] = self::ready_payload($c);
        $sc = $oid ? (self::settlement_counts()[$oid] ?? []) : [];
        $row['counts'] = [
            'support_open' => count(self::support_for($c)),
            'settlements' => array_sum($sc),
            'offers' => self::offer_count($c),
        ];
        return rest_ensure_response(['ok' => true, 'customer' => $row]);
    }

    // ═══════════════ Module (User-Entscheid 07.11.: bei Lizenz-Kunden gilt die Lizenz) ═══════════════

    /**
     * Ein Schalterfeld je Kunde. Provision: Veranstalter-Module (TIX_Plan). Kunde mit Lizenz (hosted, bestand, license):
     * Lizenz-Module (TIX_Modules) – die Veranstalter-Module werden dort nicht gezeigt. Bestand ohne Lizenz: nichts schaltbar.
     */
    public static function modules_payload($c, $org_row = null) {
        if ($c['_lic']) {
            $keys = TIX_License_Server::license_modules($c['_lic']['row']);
            $all = class_exists('TIX_Modules') ? TIX_Modules::keys() : [];
            $on = [];
            foreach ($all as $k) $on[$k] = $keys === null || in_array($k, $keys, true);
            $labels = [];
            if (class_exists('TIX_Modules')) foreach (TIX_Modules::MODULES as $k => $m) $labels[$k] = ['label' => $m[0], 'group' => $m[2]];
            // Schaltbar nur, wenn die Seite die Lizenz wirklich prüft (gemeldete Betriebsart managed/selfhosted, Audit 08.11.)
            $mode = TIX_License_Server::reported_mode($c['_lic']['row']);
            $ok = in_array($mode, ['managed', 'selfhosted'], true);
            return ['source' => 'license', 'editable' => $ok, 'reported_mode' => $mode ?: null,
                'hint' => $ok ? null : ['code' => 'tix_license_not_checked', 'message' => 'Die Seite prüft keine Lizenz – Module wirken dort erst, wenn sie sich mit Betriebsart „managed“ oder „selfhosted“ meldet.'],
                'all' => $keys === null, 'modules' => $on, 'labels' => $labels, 'locked' => (object) []];
        }
        if ($c['art'] === 'provision' && $c['organizer_id']) {
            $org_row = $org_row ?: (class_exists('TIX_Platform_Admin') ? TIX_Platform_Admin::org_row($c['organizer_id']) : null);
            return ['source' => 'organizer', 'editable' => true, 'all' => false, 'modules' => $org_row['modules'] ?? (object) [],
                'labels' => class_exists('TIX_Plan') ? array_map(function ($l) { return ['label' => $l]; }, TIX_Plan::LABELS) : (object) [],
                'locked' => $org_row['modules_locked'] ?? (object) []];
        }
        return ['source' => 'none', 'editable' => false, 'all' => false, 'modules' => (object) [], 'labels' => (object) [], 'locked' => (object) []];
    }

    /** POST /platform/customers/{key}/modules {modules: {key: bool}} | {all: true} – schreibt die gültige Ebene. */
    public static function rest_modules(WP_REST_Request $req) {
        $c = self::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $b = $req->get_json_params() ?: $req->get_params();
        $all_flag = !empty($b['all']) && filter_var($b['all'], FILTER_VALIDATE_BOOLEAN);
        $in = $b['modules'] ?? null;
        if (!$all_flag && !is_array($in)) return new WP_Error('tix_invalid_field', 'Bitte Module als {key: true|false} senden.', ['status' => 400, 'field' => 'modules']);
        if ($c['_lic']) {
            if (empty(self::modules_payload($c)['editable'])) {
                return new WP_Error('tix_license_not_checked', 'Die Seite prüft keine Lizenz – Module wirken dort nicht.', ['status' => 409]);
            }
            $known = class_exists('TIX_Modules') ? TIX_Modules::keys() : [];
            if (!$all_flag) {
                $bad = array_diff(array_keys($in), $known);
                if ($bad) return new WP_Error('tix_invalid_field', 'Unbekanntes Modul: ' . reset($bad) . '.', ['status' => 400, 'field' => 'modules.' . reset($bad)]);
                $cur = TIX_License_Server::license_modules($c['_lic']['row']) ?? $known;
                foreach ($in as $k => $v) {
                    if (filter_var($v, FILTER_VALIDATE_BOOLEAN)) $cur[] = $k; else $cur = array_diff($cur, [$k]);
                }
                $cur = array_values(array_intersect($known, array_unique($cur)));
                $value = count($cur) === count($known) ? null : $cur; // alles an = „alle“ (auch künftige Module)
            } else {
                $value = null;
            }
            $r = new WP_REST_Request('POST', '/tixomat/v1/license/admin/licenses/' . intval($c['license_id']));
            $r->set_param('id', intval($c['license_id']));
            $r->set_header('Content-Type', 'application/json');
            $r->set_body(wp_json_encode(['modules' => $value]));
            $res = TIX_License_Server::rest_admin_save($r);
            if (is_wp_error($res)) return $res;
            // Seite sofort neu prüfen lassen (sonst bis zu 24 h bis zur nächsten Prüfung)
            $pr = new WP_REST_Request('POST', '/tixomat/v1/license/admin/licenses/' . intval($c['license_id']) . '/ping');
            $pr->set_param('id', intval($c['license_id']));
            $ping = TIX_License_Server::rest_admin_ping($pr);
            self::flush();
            return rest_ensure_response(['ok' => true, 'modules' => self::modules_payload(self::raw($c['key'])),
                'ping' => is_wp_error($ping) ? null : ($ping->get_data()['sites'] ?? [])]);
        }
        if ($c['art'] === 'provision' && $c['organizer_id'] && class_exists('TIX_Platform_Admin')) {
            if ($all_flag) return new WP_Error('tix_invalid_field', '„all“ gibt es nur für Lizenz-Module.', ['status' => 400, 'field' => 'all']);
            $r = new WP_REST_Request('POST', '/tixomat/v1/platform/organizers/' . intval($c['organizer_id']) . '/modules');
            $r->set_param('id', intval($c['organizer_id']));
            $r->set_param('modules', $in);
            $res = TIX_Platform_Admin::rest_org_modules($r);
            if (is_wp_error($res)) return $res;
            self::flush();
            return rest_ensure_response(['ok' => true, 'modules' => self::modules_payload(self::raw($c['key']))]);
        }
        return new WP_Error('tix_modules_no_license', 'Dieser Kunde hat keine Lizenz auf dieser Plattform – Module schaltet die eigene Seite.', ['status' => 409]);
    }

    // ═══════════════ Zugang, Einladung, Stammdaten, Startklar (§103) ═══════════════

    /** Inhaber-Zugang: Provision = Konto des Veranstalters, Lizenz = Einladung über die Lizenzprüfung. */
    public static function owner_payload($c) {
        if ($c['art'] === 'provision' && $c['organizer_id']) {
            $u = get_userdata(intval(get_post_meta($c['organizer_id'], '_tix_org_user_id', true)));
            if (!$u) return ['source' => 'organizer', 'status' => 'missing', 'email' => null, 'last_login' => null];
            $ll = get_user_meta($u->ID, '_tix_last_login', true) ?: get_user_meta($u->ID, 'last_login', true);
            return ['source' => 'organizer', 'status' => 'active', 'email' => (string) $u->user_email, 'name' => (string) $u->display_name,
                'invited_at' => get_user_meta($u->ID, '_tix_invited_at', true) ?: null, 'last_login' => $ll ? (is_numeric($ll) ? gmdate('c', intval($ll)) : (string) $ll) : null];
        }
        if ($c['_lic'] && class_exists('TIX_Owner_Access')) {
            $q = TIX_Owner_Access::queued($c['license_id']);
            $site_owner = self::site_ready($c)['owner'] ?? null;
            return ['source' => 'license', 'status' => $q ? $q['status'] : ($site_owner ? 'active' : 'missing'), 'email' => $q['email'] ?? null, 'name' => $q['name'] ?? null,
                'invited_at' => $q['at'] ?? null, 'done_at' => $q['done_at'] ?? null, 'mailed' => $q['result']['mailed'] ?? null, 'site_has_owner' => $site_owner];
        }
        return ['source' => 'none', 'status' => null, 'email' => null];
    }

    /** Startklar, das die gebundene Seite mit der Lizenzprüfung gemeldet hat. */
    private static function site_ready($c) {
        foreach (TIX_License_Server::sites($c['license_id']) as $s) {
            if ($s->dev || empty($s->ready)) continue;
            $r = json_decode((string) $s->ready, true);
            if (is_array($r)) return $r + ['_at' => (string) $s->last_seen, '_host' => (string) $s->host];
        }
        return [];
    }

    /** {items: [{key, ok, label, hint}], ok, source, reported_at} – Zahlung, Rechtstexte, Zugang, Homepage. */
    public static function ready_payload($c) {
        $L = ['payment' => 'Zahlung eingerichtet', 'legal' => 'Rechtstexte (Impressum, Datenschutz, AGB)', 'owner' => 'Zugang für den Inhaber', 'homepage' => 'Homepage'];
        $items = [];
        $src = 'none'; $at = null;
        if ($c['_lic']) {
            $r = self::site_ready($c);
            $src = 'site'; $at = $r['_at'] ?? null;
            foreach ($L as $k => $label) {
                $ok = array_key_exists($k, $r) ? (bool) $r[$k] : null;
                $items[] = ['key' => $k, 'ok' => $ok, 'label' => $label, 'hint' => $ok === null ? 'Die Seite hat das noch nicht gemeldet (ab Plugin 1.38.688, nächste Lizenzprüfung).' : null];
            }
        } elseif ($c['art'] === 'provision' && $c['organizer_id']) {
            $oid = intval($c['organizer_id']);
            $src = 'organizer';
            $pay = class_exists('TIX_Payout_Details') ? (bool) TIX_Payout_Details::complete($oid) : null;
            $terms = null;
            if (class_exists('TIX_Org_Approval')) { $pl = TIX_Org_Approval::payload($oid); $terms = !empty($pl['terms']['accepted']); }
            $owner = self::owner_payload($c);
            $public = get_post_status($oid) === 'publish' && (!class_exists('TIX_Org_Approval') || TIX_Org_Approval::status($oid) === 'approved');
            $items = [
                ['key' => 'payment', 'ok' => $pay, 'label' => 'Auszahlungskonto', 'hint' => $pay ? null : 'Auszahlungsdaten fehlen noch.'],
                ['key' => 'legal', 'ok' => $terms, 'label' => 'Vermittlungsvertrag angenommen', 'hint' => $terms ? null : 'Der Veranstalter hat den Vertrag noch nicht angenommen.'],
                ['key' => 'owner', 'ok' => $owner['status'] === 'active', 'label' => 'Zugang für den Inhaber', 'hint' => $owner['status'] === 'active' ? null : 'Kein Konto verknüpft – „Einladen“.'],
                ['key' => 'homepage', 'ok' => $public, 'label' => 'Veranstalter-Seite online', 'hint' => $public ? null : 'Erst nach der Freigabe öffentlich.'],
            ];
        }
        $known = array_filter($items, function ($i) { return $i['ok'] !== null; });
        return ['source' => $src, 'reported_at' => $at, 'ok' => $items && count($known) === count($items) && !array_filter($items, function ($i) { return !$i['ok']; }), 'items' => $items];
    }

    /** POST /platform/customers/{key}/owner {email, name?} – Zugang anlegen bzw. erneut einladen. */
    public static function rest_owner(WP_REST_Request $req) {
        $c = self::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $b = $req->get_json_params() ?: $req->get_params();
        $email = sanitize_email((string) ($b['email'] ?? ''));
        $name = (string) ($b['name'] ?? '');
        if ($c['art'] === 'bestand') return new WP_Error('tix_customer_bestand', 'Bei KitchenKlub und Mallorca bleibt der Server unverändert – Zugänge legt die Seite selbst an.', ['status' => 409]);
        if ($c['art'] === 'provision' && $c['organizer_id']) {
            $oid = intval($c['organizer_id']);
            $uid = intval(get_post_meta($oid, '_tix_org_user_id', true));
            $u = $uid ? get_userdata($uid) : null;
            if (!$u) {
                if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
                $r = TIX_Owner_Access::invite($email, $name, ['platform' => true]);
                if (is_wp_error($r)) return $r;
                update_post_meta($oid, '_tix_org_user_id', $r['user_id']);
                update_user_meta($r['user_id'], '_tix_invited_at', gmdate('c'));
                self::flush();
                return rest_ensure_response(['ok' => true, 'created' => $r['created'], 'mailed' => $r['mailed'], 'owner' => self::owner_payload(self::raw($c['key']))]);
            }
            if ($email !== '' && strtolower($email) !== strtolower($u->user_email)) return new WP_Error('tix_owner_exists', 'Der Veranstalter hat schon einen Inhaber (' . $u->user_email . ').', ['status' => 409, 'field' => 'email']);
            $mailed = TIX_Owner_Access::send_invite($u, $u->display_name, true);
            update_user_meta($u->ID, '_tix_invited_at', gmdate('c'));
            return rest_ensure_response(['ok' => true, 'created' => false, 'mailed' => $mailed, 'owner' => self::owner_payload($c)]);
        }
        if ($c['_lic']) {
            if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
            $q = TIX_Owner_Access::queue($c['license_id'], $email, $name);
            if (is_wp_error($q)) return $q;
            // Lizenz-E-Mail nachtragen, wenn sie noch auf dem Betrieb steht
            $le = strtolower((string) $c['_lic']['row']->email);
            if ($le === '' || preg_match('/@([a-z0-9-]+\.)*evendis\.de$/', $le)) self::license_save($c, ['email' => $email]);
            $pr = new WP_REST_Request('POST', '/tixomat/v1/license/admin/licenses/' . intval($c['license_id']) . '/ping');
            $pr->set_param('id', intval($c['license_id']));
            $ping = TIX_License_Server::rest_admin_ping($pr);
            self::flush();
            return rest_ensure_response(['ok' => true, 'queued' => true, 'owner' => self::owner_payload(self::raw($c['key'])),
                'ping' => is_wp_error($ping) ? null : ($ping->get_data()['sites'] ?? [])]);
        }
        return new WP_Error('tix_owner_unavailable', 'Für diesen Kunden gibt es hier keinen Zugang anzulegen.', ['status' => 409]);
    }

    private static function license_save($c, array $fields) {
        $r = new WP_REST_Request('POST', '/tixomat/v1/license/admin/licenses/' . intval($c['license_id']));
        $r->set_param('id', intval($c['license_id']));
        $r->set_header('Content-Type', 'application/json');
        $r->set_body(wp_json_encode($fields));
        return TIX_License_Server::rest_admin_save($r);
    }

    /** POST /platform/customers/{key}/license {customer?, email?, note?} – Stammdaten der Lizenz; das Hosting-Merkmal bleibt in der Notiz. */
    public static function rest_license(WP_REST_Request $req) {
        $c = self::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        if (!$c['_lic']) return new WP_Error('tix_no_license', 'Dieser Kunde hat keine Lizenz.', ['status' => 409]);
        $b = $req->get_json_params() ?: $req->get_params();
        $f = array_intersect_key((array) $b, array_flip(['customer', 'email', 'note']));
        if (!$f) return new WP_Error('tix_invalid_field', 'Bitte customer, email oder note senden.', ['status' => 400]);
        if (array_key_exists('email', $f) && $f['email'] !== '' && !is_email((string) $f['email'])) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        if (array_key_exists('customer', $f) && trim((string) $f['customer']) === '') return new WP_Error('tix_invalid_field', 'Der Kundenname fehlt.', ['status' => 400, 'field' => 'customer']);
        if (array_key_exists('note', $f) && $c['hosting_kurz']) {
            $tag = '[kunden-hetzner:' . $c['hosting_kurz'] . ']';
            if (strpos((string) $f['note'], $tag) === false) $f['note'] = trim($tag . ' ' . (string) $f['note']); // sonst verlöre der Kunde seinen Schlüssel
        }
        $r = self::license_save($c, $f);
        if (is_wp_error($r)) return $r;
        self::flush();
        $c2 = self::raw($c['key']);
        return rest_ensure_response(['ok' => true, 'license' => TIX_License_Server::admin_payload($c2['_lic']['row'])]);
    }

    /** POST /platform/customers/invite {email, name?, organizer, offer_id?} – Provisionskunde: Konto + Veranstalter + Einladung. */
    public static function rest_invite(WP_REST_Request $req) {
        $b = $req->get_json_params() ?: $req->get_params();
        $email = sanitize_email((string) ($b['email'] ?? ''));
        $org_name = trim(sanitize_text_field((string) ($b['organizer'] ?? '')));
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        if ($org_name === '') return new WP_Error('tix_invalid_field', 'Bitte den Namen des Veranstalters angeben.', ['status' => 400, 'field' => 'organizer']);
        if (email_exists($email)) return new WP_Error('tix_email_exists', 'Zu dieser E-Mail gibt es schon ein Konto. Bitte den Veranstalter in der Kundenliste suchen.', ['status' => 409, 'field' => 'email']);
        $offer = null;
        if (!empty($b['offer_id'])) {
            $offer = class_exists('TIX_Platform_Offers') ? TIX_Platform_Offers::get(intval($b['offer_id'])) : null;
            if (!$offer) return new WP_Error('tix_offer_not_found', 'Angebot nicht gefunden.', ['status' => 404, 'field' => 'offer_id']);
        }
        $r = TIX_Owner_Access::invite($email, (string) ($b['name'] ?? ''), ['no_mail' => true]);
        if (is_wp_error($r)) return $r;
        $oid = wp_insert_post(['post_type' => 'tix_organizer', 'post_title' => $org_name, 'post_status' => 'publish', 'post_author' => $r['user_id']], true);
        if (is_wp_error($oid)) return $oid;
        update_post_meta($oid, '_tix_org_user_id', $r['user_id']);
        if (class_exists('TIX_Org_Approval')) TIX_Org_Approval::set_status($oid, 'pending', ['by' => get_current_user_id(), 'note' => 'Eingeladen (Plattform-Studio)', 'notify' => false]);
        $u = get_userdata($r['user_id']);
        $mailed = TIX_Owner_Access::send_invite($u, (string) ($b['name'] ?? ''), true);
        update_user_meta($u->ID, '_tix_invited_at', gmdate('c'));
        self::flush();
        $key = self::canonical('v-' . $oid);
        if ($offer && $key) {
            $d = TIX_Platform_Offers::data($offer);
            $d['customer_key'] = $key;
            $d['history'] = array_slice(array_merge((array) ($d['history'] ?? []), [['at' => gmdate('c'), 'what' => 'customer', 'by' => get_current_user_id(), 'key' => $key]]), -50);
            TIX_Platform_Offers::update_data($offer->id, $d);
        }
        $res = rest_ensure_response(['ok' => true, 'key' => $key, 'organizer_id' => intval($oid), 'user_id' => intval($r['user_id']), 'mailed' => (bool) $mailed]);
        $res->set_status(201);
        return $res;
    }

    // ═══════════════ Filter ?customer= ═══════════════

    /** Antwort-Kopf für gefilterte Listen: {key, name, art} bzw. WP_Error 404. */
    public static function filter_customer($key) {
        $c = self::raw($key);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404, 'field' => 'customer']);
        return $c;
    }

    public static function filter_head($c) {
        $r = self::row($c);
        return ['key' => $r['key'], 'name' => $r['name'], 'art' => $r['art']];
    }

    /** Support: Hilfe-Anfrage gehört zum Kunden (Veranstalter oder Host der eigenen Seite). */
    public static function support_match($c, $tid) {
        if ($c['organizer_id'] && intval(get_post_meta($tid, '_tix_sp_help_org', true)) === intval($c['organizer_id'])) return true;
        $s = (string) get_post_meta($tid, '_tix_sp_help_site', true);
        $h = self::host_of($s) ?: strtolower($s);
        return $h !== '' && in_array($h, self::hosts($c), true);
    }

    /** SQL-Bedingung für Angebote: fest verknüpft (customer_key), Hosting-Kurzname oder gleiche E-Mail. */
    public static function offer_where($c) {
        global $wpdb;
        $or = [];
        $keys = array_keys(array_filter(self::index()['alias'], function ($v) use ($c) { return $v === $c['key']; }));
        foreach ($keys as $k) $or[] = $wpdb->prepare('data LIKE %s', '%"customer_key":"' . $wpdb->esc_like($k) . '"%');
        if ($c['hosting_kurz']) $or[] = $wpdb->prepare('data LIKE %s', '%"hosting":{"kurz":"' . $wpdb->esc_like($c['hosting_kurz']) . '"%');
        foreach (self::emails($c) as $e) $or[] = $wpdb->prepare('LOWER(contact_email) = %s', $e);
        return $or ? '(' . implode(' OR ', $or) . ')' : '0=1';
    }

    private static function offer_count($c) {
        global $wpdb;
        if (!class_exists('TIX_Platform_Offers')) return 0;
        $t = TIX_Platform_Offers::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return 0;
        return intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status <> 'replaced' AND " . self::offer_where($c)));
    }

    // ═══════════════ Angebot verknüpfen ═══════════════

    private static function offer_for(WP_REST_Request $req) {
        if (!class_exists('TIX_Platform_Offers')) return new WP_Error('tix_unavailable', 'Angebote sind hier nicht verfügbar.', ['status' => 409]);
        $b = $req->get_json_params() ?: $req->get_params();
        $row = TIX_Platform_Offers::get(intval($b['offer_id'] ?? 0));
        return $row ?: new WP_Error('tix_offer_not_found', 'Angebot nicht gefunden.', ['status' => 404, 'field' => 'offer_id']);
    }

    public static function rest_link_offer(WP_REST_Request $req) {
        $c = self::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $row = self::offer_for($req);
        if (is_wp_error($row)) return $row;
        $d = TIX_Platform_Offers::data($row);
        $d['customer_key'] = $c['key'];
        $d['history'] = array_slice(array_merge((array) ($d['history'] ?? []), [['at' => gmdate('c'), 'what' => 'customer', 'by' => get_current_user_id(), 'key' => $c['key']]]), -50);
        TIX_Platform_Offers::update_data($row->id, $d);
        return rest_ensure_response(['ok' => true, 'offer_id' => intval($row->id), 'customer_key' => $c['key']]);
    }

    public static function rest_unlink_offer(WP_REST_Request $req) {
        $c = self::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $row = self::offer_for($req);
        if (is_wp_error($row)) return $row;
        $d = TIX_Platform_Offers::data($row);
        if (($d['customer_key'] ?? '') !== '' && self::canonical($d['customer_key']) === $c['key']) {
            unset($d['customer_key']);
            $d['history'] = array_slice(array_merge((array) ($d['history'] ?? []), [['at' => gmdate('c'), 'what' => 'customer_removed', 'by' => get_current_user_id(), 'key' => $c['key']]]), -50);
            TIX_Platform_Offers::update_data($row->id, $d);
        }
        return rest_ensure_response(['ok' => true, 'offer_id' => intval($row->id), 'customer_key' => null]);
    }
}

TIX_Platform_Customers::init();
