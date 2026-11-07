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
        if (!class_exists('TIX_License_Server')) return [];
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
            return ['source' => 'license', 'editable' => true, 'all' => $keys === null, 'modules' => $on, 'labels' => $labels, 'locked' => (object) []];
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
            self::flush();
            return rest_ensure_response(['ok' => true, 'modules' => self::modules_payload(self::raw($c['key']))]);
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
