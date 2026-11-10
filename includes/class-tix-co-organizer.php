<?php
if (!defined('ABSPATH')) exit;

/**
 * Co-Veranstalter (1.38.696, User 09.11.) – Vertrag `uebergaben/vertrag-co-veranstalter.md` Teil A.
 *
 * Nur im Mehr-Veranstalter-Modus (evendis.de). Der Ersteller (oder ein Plattform-Admin) setzt je Event einen
 * Co-Veranstalter – vorhandenen Veranstalter oder per E-Mail einladen – und schaltet seinen Zugriff.
 *
 * Meta am Event:
 *   _tix_co_organizer_ref     Co-Veranstalter (immer)
 *   _tix_co_organizer_access  '1' | '0'
 *   _tix_co_organizer_id      nur mit Zugriff gesetzt – darüber laufen alle bestehenden Abfragen (Scope, Listen, Statistik)
 *   _tix_co_organizer         Name (Anzeige)
 *   _tix_co_organizer_since   ISO-Zeit
 *   _tix_co_organizer_consent {at, by}
 * Meta am Veranstalter: _tix_org_kind = 'co' (über Einladung angelegt), _tix_org_co_choice {choice, at, message}.
 *
 * Rechte des Co-Veranstalters: wie eigenes Event, aber nicht löschen/wiederherstellen/archivieren/duplizieren,
 * Co-Veranstalter ändern, Datenexport mit Käuferdaten; Abrechnung bleibt beim Ersteller.
 */
class TIX_Co_Organizer {
    const NS          = 'tixomat/v1';
    const META_REF    = '_tix_co_organizer_ref';
    const META_ACCESS = '_tix_co_organizer_access';
    const META_ID     = '_tix_co_organizer_id';
    const META_NAME   = '_tix_co_organizer';
    const META_SINCE  = '_tix_co_organizer_since';
    const META_CONSENT = '_tix_co_organizer_consent';
    const ORG_KIND    = '_tix_org_kind';
    const ORG_CHOICE  = '_tix_org_co_choice';
    const CHOICES     = ['studio', 'provision', 'license'];
    const OPT_REQUESTS = 'tix_co_requests';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat co', [__CLASS__, 'cli']);
        add_filter('rest_request_before_callbacks', [__CLASS__, 'guard'], 10, 3);
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Grundlagen ═══════════════

    /**
     * Mehrere Co-Veranstalter je Event (1.38.716, Vertrag `uebergaben/vertrag-co-mehrere.md`):
     * `_tix_co_list` = [{id, access, since, consent}] ist maßgeblich. Daraus abgeleitet (für alle bestehenden Abfragen):
     * `_tix_co_organizer_ref` (je Co eine Zeile), `_tix_co_organizer_id` (je Co mit Zugriff eine Zeile); die Einzelfelder
     * `_tix_co_organizer_access/_since/_consent` und `_tix_co_organizer` (Name) tragen den ersten Co (alte Leser).
     * Events ohne Liste (vor 1.38.716) werden aus den Einzelfeldern gelesen und beim nächsten Speichern umgestellt.
     */
    const META_LIST = '_tix_co_list';

    /** Alle Co-Veranstalter des Events (Reihenfolge = Hinzufügen). */
    public static function entries($event_id) {
        $event_id = intval($event_id);
        if (!$event_id) return [];
        if (metadata_exists('post', $event_id, self::META_LIST)) {
            $out = [];
            foreach ((array) get_post_meta($event_id, self::META_LIST, true) as $e) {
                if (!is_array($e) || !intval($e['id'] ?? 0)) continue;
                $id = intval($e['id']);
                if (isset($out[$id])) continue;
                $c = $e['consent'] ?? null;
                $out[$id] = ['id' => $id, 'access' => !empty($e['access']), 'since' => (string) ($e['since'] ?? ''), 'consent' => is_array($c) && !empty($c['at']) ? $c : null];
            }
            return array_values($out);
        }
        // Alt-Stand: ein Co in den Einzelfeldern
        $ref = intval(get_post_meta($event_id, self::META_REF, true)) ?: intval(get_post_meta($event_id, self::META_ID, true));
        if (!$ref) return [];
        $a = get_post_meta($event_id, self::META_ACCESS, true);
        $c = get_post_meta($event_id, self::META_CONSENT, true);
        return [['id' => $ref, 'access' => $a === '' ? intval(get_post_meta($event_id, self::META_ID, true)) > 0 : $a === '1',
            'since' => (string) get_post_meta($event_id, self::META_SINCE, true), 'consent' => is_array($c) && !empty($c['at']) ? $c : null]];
    }

    /** Liste speichern + abgeleitete Felder schreiben. */
    public static function save_entries($event_id, array $list) {
        $event_id = intval($event_id);
        if (class_exists('TIX_Co_Money')) TIX_Co_Money::normalize($event_id); // Alt-Geldempfänger „co“ an die bisherige Liste binden
        $list = array_values(array_filter($list, function ($e) { return is_array($e) && intval($e['id'] ?? 0) > 0; }));
        foreach ([self::META_REF, self::META_ID, self::META_ACCESS, self::META_NAME, self::META_SINCE, self::META_CONSENT] as $k) delete_post_meta($event_id, $k);
        if (!$list) { delete_post_meta($event_id, self::META_LIST); clean_post_cache($event_id); return; }
        $clean = [];
        foreach ($list as $e) $clean[] = ['id' => intval($e['id']), 'access' => !empty($e['access']), 'since' => (string) ($e['since'] ?? '') ?: gmdate('c'), 'consent' => $e['consent'] ?? null];
        update_post_meta($event_id, self::META_LIST, $clean);
        foreach ($clean as $e) {
            add_post_meta($event_id, self::META_REF, $e['id']);
            if ($e['access']) add_post_meta($event_id, self::META_ID, $e['id']);
        }
        $f = $clean[0];
        update_post_meta($event_id, self::META_ACCESS, $f['access'] ? '1' : '0');
        update_post_meta($event_id, self::META_NAME, html_entity_decode((string) get_the_title($f['id']), ENT_QUOTES, 'UTF-8'));
        update_post_meta($event_id, self::META_SINCE, $f['since']);
        if ($f['consent']) update_post_meta($event_id, self::META_CONSENT, $f['consent']);
        clean_post_cache($event_id);
    }

    public static function ids($event_id) {
        return array_map(function ($e) { return $e['id']; }, self::entries($event_id));
    }

    public static function access_ids($event_id) {
        return array_values(array_map(function ($e) { return $e['id']; }, array_filter(self::entries($event_id), function ($e) { return $e['access']; })));
    }

    public static function entry_of($event_id, $oid) {
        foreach (self::entries($event_id) as $e) if ($e['id'] === intval($oid)) return $e;
        return null;
    }

    /** Erster Co-Veranstalter (Alt-Schnittstelle) oder 0. */
    public static function ref_id($event_id) {
        $l = self::entries($event_id);
        return $l ? $l[0]['id'] : 0;
    }

    /** Zugriff des Co $oid (ohne $oid: des ersten Co). */
    public static function has_access($event_id, $oid = 0) {
        $e = $oid ? self::entry_of($event_id, $oid) : (self::entries($event_id)[0] ?? null);
        return $e ? $e['access'] : false;
    }

    private static function org_ok($oid) {
        $p = get_post(intval($oid));
        return $p && $p->post_type === 'tix_organizer' && $p->post_status === 'publish' ? $p : null;
    }

    /**
     * Co-Veranstalter hinzufügen oder ändern (ohne Rechteprüfung). Ersetzt nie einen anderen Co (1.38.716).
     * $access null = bisherigen Zugriff behalten (neu: an). → true, wenn neu hinzugefügt.
     */
    public static function set($event_id, $oid, $access = null, $consent_by = 0) {
        $event_id = intval($event_id);
        $oid = intval($oid);
        $list = self::entries($event_id);
        $new = true;
        foreach ($list as $i => $e) {
            if ($e['id'] !== $oid) continue;
            $new = false;
            if ($access !== null) $list[$i]['access'] = (bool) $access;
            if ($list[$i]['access'] && $consent_by && !$list[$i]['consent']) $list[$i]['consent'] = ['at' => gmdate('c'), 'by' => intval($consent_by)];
        }
        if ($new) {
            $acc = $access === null ? true : (bool) $access;
            $list[] = ['id' => $oid, 'access' => $acc, 'since' => gmdate('c'), 'consent' => ($acc && $consent_by) ? ['at' => gmdate('c'), 'by' => intval($consent_by)] : null];
        }
        self::save_entries($event_id, $list);
        return $new;
    }

    /** Einen Co lösen (ohne Rechteprüfung). → war verbunden? */
    public static function remove($event_id, $oid) {
        $list = self::entries($event_id);
        $rest = array_values(array_filter($list, function ($e) use ($oid) { return $e['id'] !== intval($oid); }));
        if (count($rest) === count($list)) return false;
        self::save_entries($event_id, $rest);
        return true;
    }

    /** Alle Co lösen. */
    public static function clear($event_id) {
        $event_id = intval($event_id);
        self::save_entries($event_id, []);
        foreach ([self::META_REF, self::META_ACCESS, self::META_ID, self::META_NAME, self::META_SINCE, self::META_CONSENT] as $k) delete_post_meta($event_id, $k);
        clean_post_cache($event_id);
    }

    /** Rolle des aktuellen Nutzers am Event: owner | co | admin | null. */
    public static function role($event_id, $uid = 0) {
        $event_id = intval($event_id);
        $uid = $uid ?: get_current_user_id();
        $u = get_user_by('id', $uid);
        if ($u && $u->has_cap('manage_options')) return 'admin';
        if (!class_exists('TIX_App_Scope')) return null;
        $oid = TIX_App_Scope::organizer_id_for_user($uid);
        if (!$oid) return null;
        if (!$event_id) return 'owner';
        if (intval(get_post_meta($event_id, '_tix_organizer_id', true)) === $oid) return 'owner';
        if (in_array($oid, self::access_ids($event_id), true)) return 'co';
        return null;
    }

    /** Ist das Event für den aktuellen Nutzer ein gemeinsames Event, bei dem er Co-Veranstalter ist? */
    public static function is_co($event_id) {
        return self::multi() && self::role($event_id) === 'co';
    }

    private static function deny($msg = 'Das darf nur der Ersteller des Events.') {
        return new WP_Error('tix_co_forbidden', $msg, ['status' => 403]);
    }

    private static function ref($oid) {
        if (!$oid) return null;
        // organizer_ref liefert nur freigegebene Veranstalter – eingeladene Co-Veranstalter warten oft noch auf die Prüfung
        $r = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::organizer_ref($oid) : null;
        if ($r) return $r;
        $logo = '';
        foreach (['_tix_org_landing_logo_id', '_tix_org_image_id'] as $k) {
            $aid = intval(get_post_meta($oid, $k, true));
            if ($aid && ($u = wp_get_attachment_image_url($aid, 'medium'))) { $logo = $u; break; }
        }
        return ['id' => intval($oid), 'slug' => '', 'name' => (string) get_the_title($oid), 'logo' => $logo];
    }

    /** Öffentlich nennen: veröffentlicht und nicht gesperrt/abgelehnt (die Freigabe zum eigenen Verkauf ist dafür nicht nötig). */
    public static function public_ok($oid) {
        if (!self::org_ok($oid)) return false;
        return !(class_exists('TIX_Org_Approval') && in_array(TIX_Org_Approval::status($oid), ['blocked', 'rejected'], true));
    }

    public static function owner_user($oid) {
        $uid = intval(get_post_meta(intval($oid), '_tix_org_user_id', true));
        return $uid ? get_user_by('id', $uid) : null;
    }

    /** invited = Konto ohne gesetztes Passwort (eingeladen, nie angemeldet). */
    public static function org_status($oid) {
        if (class_exists('TIX_Co_Sites') && TIX_Co_Sites::is_site_profile($oid)) return 'active'; // Kunden-Seite: kein Passwort, sofort aktiv (1.38.715)
        $u = self::owner_user($oid);
        if (!$u) return 'invited';
        $invited = get_user_meta($u->ID, '_tix_invited_at', true);
        $seen = get_user_meta($u->ID, '_tix_last_login', true) ?: get_user_meta($u->ID, 'last_login', true);
        if ($invited && !$seen && get_user_meta($u->ID, '_tix_co_password_set', true) !== '1') return 'invited';
        return 'active';
    }

    /** Co $oid des Events (ohne $oid: der erste) als Antwort-Objekt oder null. */
    public static function payload($event_id, $oid = 0) {
        $e = $oid ? self::entry_of($event_id, $oid) : (self::entries($event_id)[0] ?? null);
        if (!$e || !get_post($e['id'])) return null;
        $oid = $e['id'];
        $r = self::ref($oid) ?: ['id' => $oid, 'name' => '', 'slug' => '', 'logo' => ''];
        $u = self::owner_user($oid);
        $status = self::org_status($oid);
        $c = $e['consent'];
        return [
            'id' => intval($oid), 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'), 'logo' => (string) $r['logo'], 'slug' => (string) $r['slug'],
            'access' => $e['access'],
            'status' => $status,
            'invited_email' => ($status === 'invited' && $u) ? (string) $u->user_email : '',
            'since' => $e['since'] ?: null,
            'consent' => is_array($c) && !empty($c['at']) ? ['at' => (string) $c['at'], 'by' => intval($c['by'] ?? 0)] : null,
        ];
    }

    /** Alle Co als Einträge für `co_organizers`. */
    public static function entries_payload($event_id, $removable) {
        $out = [];
        foreach (self::ids($event_id) as $oid) if ($e = self::entry($event_id, $removable, $oid)) $out[] = $e;
        return $out;
    }

    /** Zusatzfelder für GET /events/{id}/edit und /events/form. */
    public static function editor_payload($event_id) {
        if (!self::multi()) return class_exists('TIX_Co_Remote') ? TIX_Co_Remote::editor_payload($event_id)
            : ['co_organizer_available' => false, 'co_organizer_mode' => 'remote', 'co_organizer_editable' => false, 'event_role' => null, 'co_organizer' => null];
        $role = self::role($event_id) ?: ($event_id ? null : 'owner');
        $can = in_array($role, ['owner', 'admin'], true);
        if ($event_id && get_post_meta($event_id, '_tix_syndicated', true) === '1') return self::mirror_editor_payload($event_id, $role); // Spiegel: Co der Quelle (1.38.715)
        $list = $event_id ? self::entries_payload($event_id, $can) : [];
        return [
            'co_organizer_available' => true,
            'co_organizer_mode'      => 'local',
            'co_organizer_editable'  => $can,
            'co_organizer_multiple'  => true,
            'event_role'             => $role,
            'co_organizer'           => $event_id ? self::payload($event_id) : null,
            'co_organizers'          => $list,
            'co_organizer_removable' => $can && $list !== [],
            'co_organizer_site_search' => $can && class_exists('TIX_Co_Sites'),
        ];
    }

    /** Spiegel eines Lizenz-Events: Co wie bei der Quelle (Teil B). Ändern = an die Quelle weiterreichen (nur Admin, 1.38.715). */
    private static function mirror_editor_payload($eid, $role) {
        $admin = $role === 'admin';
        $lock = self::platform_lock($eid);
        $ok = $admin && $lock !== null && !empty($lock['forward']);
        $list = self::mirror_entries_payload($eid, $admin);
        return ['co_organizer_available' => $ok, 'co_organizer_mode' => 'source', 'co_organizer_unavailable_reason' => $ok ? null : 'source',
            'co_organizer_editable' => $ok, 'co_organizer_multiple' => true, 'event_role' => $role, 'co_organizer' => $list[0] ?? null, 'co_organizers' => $list,
            'co_organizer_removable' => $admin && $list !== [], 'co_organizer_site_search' => $ok,
            'co_source' => ['site' => (string) ($lock['site'] ?? ''), 'note' => 'Das Event gehört ' . (string) ($lock['site'] ?? 'der Partnerseite') . '. Änderungen am Co-Veranstalter gehen dorthin und gelten auf beiden Seiten.']];
    }

    /** Co-Einträge eines Spiegels (Teil B, von der Quelle gemeldet). */
    public static function mirror_entries_payload($eid, $removable) {
        $out = [];
        foreach (class_exists('TIX_Co_Remote') ? TIX_Co_Remote::mirror_payloads($eid) : [] as $m) {
            $o = self::origin_of($m['id']);
            if ($o['kind'] === 'local') $o['kind'] = 'remote';
            $out[] = $m + ['invited_email' => '', 'since' => null, 'consent' => null, 'remote' => true] + $o + ['removable' => (bool) $removable];
        }
        return $out;
    }

    /**
     * Spiegel mit lokalem Co (Teil A, vor 1.38.715 möglich), je lokalem Co eine Zeile:
     * [{event, title, local {id,name,access}, source {id,name} (erster Co der Quelle), sources [{id,name}], at_source (lokaler Co ist dort schon)}]
     */
    public static function mirror_conflicts() {
        $out = [];
        foreach (get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_syndicated', 'value' => '1'], ['relation' => 'OR', ['key' => self::META_LIST, 'compare' => 'EXISTS'], ['key' => self::META_REF, 'compare' => 'EXISTS'], ['key' => self::META_ID, 'compare' => 'EXISTS']]]]) as $eid) {
            $src = class_exists('TIX_Co_Remote') ? TIX_Co_Remote::mirror_ids($eid) : [];
            $sources = array_map(function ($s) { return ['id' => $s, 'name' => html_entity_decode(get_the_title($s), ENT_QUOTES, 'UTF-8')]; }, $src);
            foreach (self::entries($eid) as $e) {
                $out[] = ['event' => intval($eid), 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'),
                    'local' => ['id' => $e['id'], 'name' => html_entity_decode(get_the_title($e['id']), ENT_QUOTES, 'UTF-8'), 'access' => $e['access']],
                    'source' => $sources[0] ?? null, 'sources' => $sources, 'at_source' => in_array($e['id'], $src, true)];
            }
        }
        return $out;
    }

    /**
     * Co-Veranstalter auf Spiegeln prüfen bzw. bereinigen (1.38.715).
     *
     * ## OPTIONS
     * <aktion>
     * : spiegel
     *
     * [--reparieren]
     * : lokalen Co an die Quelle weiterreichen (ersetzt deren Co) und lokal entfernen
     */
    public static function cli($args, $assoc) {
        if (($args[0] ?? '') !== 'spiegel' || !self::multi()) WP_CLI::error('Aufruf: wp tixomat co spiegel [--reparieren] (nur Plattform)');
        $list = self::mirror_conflicts();
        foreach ($list as $c) {
            $names = implode(', ', array_map(function ($x) { return $x['name'] . ' (#' . $x['id'] . ')'; }, $c['sources']));
            $line = sprintf('#%d %s: lokal %s (#%d)%s', $c['event'], $c['title'], $c['local']['name'], $c['local']['id'], $names !== '' ? ', Quelle ' . $names : ', Quelle ohne Co');
            if (empty($assoc['reparieren'])) { WP_CLI::line($line); continue; }
            if (!$c['at_source']) { // hinzufügen, die Co der Quelle bleiben (1.38.716)
                $f = self::forward_mirror($c['event'], ['organizer_id' => $c['local']['id'], 'access' => $c['local']['access']]);
                if (is_wp_error($f)) { WP_CLI::warning($line . ' → ' . $f->get_error_code() . ' ' . $f->get_error_message()); continue; }
            }
            self::remove($c['event'], $c['local']['id']);
            WP_CLI::line($line . ($c['at_source'] ? ' → bei der Quelle schon eingetragen, lokal entfernt' : ' → an die Quelle weitergereicht (zusätzlich), lokal entfernt'));
        }
        WP_CLI::line('ERGEBNIS ' . wp_json_encode(['ok' => true, 'count' => count($list), 'repaired' => !empty($assoc['reparieren'])]));
    }

    /** Spiegel: Co an die Quelle weiterreichen und Antwort im Format der Plattform liefern (1.38.715). */
    private static function forward_mirror($eid, array $b) {
        $r = TIX_Co_Remote::forward_to_source($eid, $b + ['by' => html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8')]);
        if (is_wp_error($r)) return $r;
        clean_post_cache($eid);
        return ['ok' => true, 'forwarded' => true, 'mailed' => !empty($r['mailed']), 'mail_note' => (string) ($r['mail_note'] ?? ''), 'created' => !empty($r['created'])];
    }

    /** Herkunft eines Co-Veranstalters: {kind, origin, site} (1.38.706). */
    public static function origin_of($oid) {
        $site = class_exists('TIX_Co_Sites') ? TIX_Co_Sites::site_of($oid) : null;
        if ($site) return ['kind' => 'site', 'origin' => ['type' => 'site', 'label' => 'eigene Seite ' . $site['host'], 'host' => $site['host']], 'site' => $site];
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        return ['kind' => 'local', 'origin' => ['type' => 'platform', 'label' => 'auf ' . $host, 'host' => $host], 'site' => null];
    }

    /** Eintrag für `co_organizers` (Teil A) oder null. */
    public static function entry($event_id, $removable, $oid = 0) {
        $p = self::payload($event_id, $oid);
        return $p ? $p + self::origin_of($p['id']) + ['removable' => (bool) $removable] : null;
    }

    /** Zusatzfelder für GET /events und /events/{id}: Rolle + „Gemeinsam mit …“. */
    public static function list_fields($event_id) {
        if (!self::multi()) return class_exists('TIX_Co_Remote') ? TIX_Co_Remote::list_fields($event_id) : [];
        $role = self::role($event_id);
        $with = [];
        if ($role === 'co') {
            $me = class_exists('TIX_App_Scope') ? TIX_App_Scope::organizer_id_for_user() : 0;
            $with = array_merge([intval(get_post_meta(intval($event_id), '_tix_organizer_id', true))], array_diff(self::ids($event_id), [$me]));
        } elseif ($role === 'owner' || $role === 'admin') $with = self::ids($event_id);
        $all = [];
        foreach (array_filter($with) as $w) {
            $r = self::ref($w);
            if ($r) $all[] = ['id' => intval($r['id']), 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'), 'logo' => (string) $r['logo']];
        }
        return ['event_role' => $role, 'shared_with' => $all[0] ?? null, 'shared_with_all' => $all];
    }

    /** Öffentliche Angabe „mit …“ (unabhängig vom Zugriff): erster Co. */
    public static function public_ref($event_id) {
        return self::public_refs($event_id)[0] ?? null;
    }

    /** Öffentliche Angabe „mit …“: alle Co (1.38.716). */
    public static function public_refs($event_id) {
        if (get_post_meta(intval($event_id), '_tix_syndicated', true)) { // geteilte Events: nur die Co-Veranstalter, die die Lizenzseite gemeldet hat (Teil B)
            $ids = class_exists('TIX_Co_Remote') ? TIX_Co_Remote::mirror_ids($event_id) : [];
        } elseif (!self::multi() && class_exists('TIX_Co_Remote')) {
            return TIX_Co_Remote::public_refs($event_id);
        } else {
            $ids = self::ids($event_id);
        }
        $out = [];
        foreach ($ids as $oid) if (self::public_ok($oid) && ($r = self::ref($oid))) $out[] = $r;
        return $out;
    }

    // ═══════════════ Editor: prüfen + speichern ═══════════════

    /** Feld `co_organizer` im Body prüfen. → null (unverändert) | ['op' => 'clear'|'set', …] | WP_Error */
    public static function check(array $body, $post_id, $is_new) {
        if (!array_key_exists('co_organizer', $body)) return null;
        if (!self::multi()) return class_exists('TIX_Co_Remote') ? TIX_Co_Remote::check($body, $post_id, $is_new) : null;
        $role = $is_new ? (TIX_App_Scope::is_admin() ? 'admin' : 'owner') : self::role($post_id);
        if (!in_array($role, ['owner', 'admin'], true)) return self::deny('Den Co-Veranstalter kann nur der Ersteller des Events ändern.');
        $v = $body['co_organizer'];
        if (!$is_new && get_post_meta($post_id, '_tix_syndicated', true) === '1') { // Spiegel: an die Quelle (1.38.715)
            if ($role !== 'admin') return self::deny('Den Co-Veranstalter legt die Seite des Erstellers fest.');
            $cur_ids = TIX_Co_Remote::mirror_ids($post_id);
            if ($v === null || $v === false || $v === '' || $v === 0) {
                if (!$cur_ids) return null;
                foreach ($cur_ids as $c) TIX_Co_Remote::release_mirror($post_id, 'platform', $c);
                return ['op' => 'remote'];
            }
            if (!is_array($v)) return new WP_Error('tix_invalid_field', 'co_organizer: null oder {id, access}.', ['status' => 400, 'field' => 'co_organizer']);
            $oid = isset($v['id']) ? intval($v['id']) : (count($cur_ids) === 1 ? $cur_ids[0] : 0);
            if (!$oid) return new WP_Error('tix_invalid_field', 'Bitte einen Co-Veranstalter wählen.', ['status' => 400, 'field' => 'co_organizer']);
            $f = self::forward_mirror($post_id, ['organizer_id' => $oid, 'access' => array_key_exists('access', $v) ? (bool) filter_var($v['access'], FILTER_VALIDATE_BOOLEAN) : true]);
            return is_wp_error($f) ? $f : ['op' => 'remote'];
        }
        if ($v === null || $v === false || $v === '' || $v === 0) { // alle lösen
            if (!$is_new && class_exists('TIX_Co_Money') && is_wp_error($cc = TIX_Co_Money::can_clear($post_id))) return $cc;
            return ['op' => 'clear'];
        }
        if (!is_array($v)) return new WP_Error('tix_invalid_field', 'co_organizer: null oder {id, access, consent}.', ['status' => 400, 'field' => 'co_organizer']);
        $cur_ids = $is_new ? [] : self::ids($post_id);
        $oid = isset($v['id']) ? intval($v['id']) : (count($cur_ids) === 1 ? $cur_ids[0] : 0);
        if (!$oid) return new WP_Error('tix_invalid_field', count($cur_ids) > 1 ? 'Bitte angeben, welcher Co-Veranstalter gemeint ist (id).' : 'Bitte einen Co-Veranstalter wählen.', ['status' => 400, 'field' => 'co_organizer.id']);
        if (!self::org_ok($oid)) return new WP_Error('tix_invalid_field', 'Diesen Veranstalter gibt es nicht oder er ist nicht freigegeben.', ['status' => 400, 'field' => 'co_organizer']);
        $own = $is_new ? TIX_App_Scope::organizer_id_for_user() : intval(get_post_meta($post_id, '_tix_organizer_id', true));
        if ($own && $own === $oid) return new WP_Error('tix_invalid_field', 'Der eigene Veranstalter kann nicht Co-Veranstalter sein.', ['status' => 400, 'field' => 'co_organizer']);
        $access = array_key_exists('access', $v) ? filter_var($v['access'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if (array_key_exists('access', $v) && $access === null) return new WP_Error('tix_invalid_field', 'co_organizer.access: true oder false.', ['status' => 400, 'field' => 'co_organizer.access']);
        $cur = $is_new ? null : self::entry_of($post_id, $oid);
        $eff = $access === null ? ($cur ? $cur['access'] : true) : $access;
        $had = $cur && $cur['consent']; // Bestätigung bleibt gespeichert, auch wenn der Zugriff zwischendurch aus war
        if ($cur && $access === false && $cur['access'] && class_exists('TIX_Co_Money') && TIX_Co_Money::owner_id($post_id) === $oid && TIX_Co_Money::sold($post_id)) {
            return new WP_Error('tix_co_money_locked', 'Dieser Co-Veranstalter nimmt das Geld ein, und es gibt schon Verkäufe. Der Zugriff bleibt an.', ['status' => 409, 'field' => 'co_organizer.access']);
        }
        if ($eff && $role !== 'admin' && !$had && empty($v['consent'])) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'co_organizer.consent']);
        }
        return ['op' => 'set', 'id' => $oid, 'access' => $access, 'consent' => !empty($v['consent']) || $role === 'admin'];
    }

    public static function apply($post_id, $c) {
        if (!$c) return;
        if (($c['op'] ?? '') === 'remote') return; // Lizenzseite: schon bei der Prüfung an evendis gemeldet (TIX_Co_Remote::check)
        if ($c['op'] === 'clear') {
            $old = self::ids($post_id);
            self::clear($post_id);
            if (class_exists('TIX_Co_Money')) TIX_Co_Money::reset($post_id);
            foreach ($old as $o) self::log_change($post_id, 'removed', $o, false, 'creator');
            return;
        }
        $cur = self::entry_of($post_id, $c['id']);
        $old_access = $cur ? $cur['access'] : null;
        $new = self::set($post_id, $c['id'], $c['access'], $c['consent'] ? get_current_user_id() : 0); // hinzufügen, ersetzt nie (1.38.716)
        $now = self::has_access($post_id, $c['id']);
        if (!$now && class_exists('TIX_Co_Money')) TIX_Co_Money::on_co_lost($post_id, intval($c['id']), true);
        if ($new) self::notify($post_id, $c['id'], null);
        if ($new) self::log_change($post_id, 'added', intval($c['id']), $now, 'creator');
        elseif ($old_access !== $now) self::log_change($post_id, 'changed', intval($c['id']), $now, 'creator');
    }

    // ═══════════════ Sperren (Rechte) ═══════════════

    /** Routen, die der Co-Veranstalter an gemeinsamen Events nicht nutzen darf. */
    public static function guard($response, $handler, $request) {
        if ($response instanceof WP_Error || !self::multi() || !is_user_logged_in()) return $response;
        $route = (string) $request->get_route();
        $method = $request->get_method();
        if (preg_match('#^/tixomat/v1/events/(\d+)(/restore|/archive|/duplicate)?$#', $route, $m)) {
            $sub = $m[2] ?? '';
            $blocked = ($sub === '' && $method === 'DELETE') || ($sub !== '' && $method === 'POST');
            if ($blocked && self::is_co(intval($m[1]))) {
                return self::deny($sub === '/duplicate' ? 'Ein gemeinsames Event kann nur der Ersteller duplizieren.' : 'Ein gemeinsames Event kann nur der Ersteller löschen oder archivieren.');
            }
        }
        if ($route === '/tixomat/v1/organizer/export') {
            $eid = absint($request->get_param('event_id'));
            $type = sanitize_key((string) $request->get_param('type'));
            if ($eid && self::is_co($eid) && !in_array($type, ['guests', 'checkins'], true)) {
                return self::deny('Käuferdaten eines gemeinsamen Events kann nur der Ersteller exportieren.');
            }
        }
        return $response;
    }

    /** Für Exporte ohne event_id: gemeinsame Events herausnehmen. */
    public static function without_co(array $ids) {
        if (!self::multi() || (class_exists('TIX_App_Scope') && TIX_App_Scope::is_admin())) return $ids;
        return array_values(array_filter($ids, function ($id) { return !self::is_co($id); }));
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        if (!self::multi()) return;
        $org = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/organizers/search', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_search'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-organizer/invite', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_invite'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-organizer', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_remove'], 'permission_callback' => $org]);
        register_rest_route(self::NS, '/organizer/co-choice', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_choice'], 'permission_callback' => [__CLASS__, 'perm_manager']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save_choice'], 'permission_callback' => [__CLASS__, 'perm_manager']],
        ]);
        $adm = ['permission_callback' => ['TIX_Platform_Customers', 'perm']];
        register_rest_route(self::NS, '/platform/co-organizers', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform_list']] + $adm);
        register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/convert', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_convert']] + $adm);
        // Co-Veranstalter durch den Plattform-Admin (1.38.705, Vertrag `uebergaben/vertrag-co-durch-plattform.md`)
        register_rest_route(self::NS, '/platform/events', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform_events']] + $adm);
        register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/co', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform_customer_co']] + $adm);
        register_rest_route(self::NS, '/platform/events/(?P<id>\d+)/co-organizer', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_platform_set']] + $adm,
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_platform_clear']] + $adm,
        ]);
    }

    public static function perm_manager() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (TIX_App_Scope::is_admin()) return true;
        return TIX_App_Scope::is_org_manager() ? true : new WP_Error('rest_forbidden', 'Nur für Inhaber und Team-Admins.', ['status' => 403]);
    }

    private static function creator_ok() {
        return TIX_App_Scope::is_admin() || TIX_App_Scope::is_org_manager();
    }

    public static function rest_search(WP_REST_Request $req) {
        if (!self::creator_ok()) return new WP_Error('rest_forbidden', 'Nur für Inhaber und Team-Admins.', ['status' => 403]);
        $q = trim(sanitize_text_field((string) $req->get_param('q')));
        if (mb_strlen($q) < 2) return new WP_Error('tix_invalid_field', 'Bitte mindestens 2 Zeichen eingeben.', ['status' => 400, 'field' => 'q']);
        $own = TIX_App_Scope::organizer_id_for_user();
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 11, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC']);
        $items = [];
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id === $own) continue;
            if (class_exists('TIX_Sales') && method_exists('TIX_Sales', 'is_demo_organizer') && TIX_Sales::is_demo_organizer($id)) continue;
            if (class_exists('TIX_Co_Sites') && TIX_Co_Sites::is_site_profile($id)) continue; // Kunden-Seiten nur in der Seiten-Suche (1.38.709)
            $r = self::ref($id);
            $items[] = ['id' => $id, 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'), 'slug' => (string) $r['slug'], 'logo' => (string) $r['logo'],
                'city' => (string) (get_post_meta($id, '_tix_org_city', true) ?: get_post_meta($id, '_tix_org_landing_city', true))];
            if (count($items) >= 10) break;
        }
        return rest_ensure_response(['ok' => true, 'items' => $items]);
    }

    /** Veranstalter zur E-Mail (Inhaber) oder null. */
    private static function org_for_user($uid) {
        $p = TIX_App_Scope::owned_organizer($uid);
        return $p ?: null;
    }


    /**
     * Veranstalter zur E-Mail finden oder Co-Veranstalter-Profil (Konto + Veranstalter) anlegen.
     * → [WP_Post $org, bool $created, WP_User $user, bool $new_user] | WP_Error
     */
    public static function find_or_create($email, $org_name, $name, array $ctx = []) {
        $user = get_user_by('email', $email);
        $org = $user ? self::org_for_user($user->ID) : null;
        if ($user && $user->has_cap('manage_options') && !$org) return new WP_Error('tix_invalid_field', 'Diese E-Mail gehört zu einem Plattform-Konto.', ['status' => 400, 'field' => 'email']);
        $created = false;
        $new_user = false;
        if (!$org) {
            if ($org_name === '') return new WP_Error('tix_invalid_field', 'Bitte den Namen des Veranstalters angeben.', ['status' => 400, 'field' => 'organizer']);
            if (!$user) {
                $r = TIX_Owner_Access::invite($email, $name, ['no_mail' => true]);
                if (is_wp_error($r)) return $r;
                $user = get_user_by('id', $r['user_id']);
                $new_user = true;
                update_user_meta($user->ID, '_tix_invited_at', gmdate('c'));
            } elseif (!in_array('tix_organizer', (array) $user->roles, true)) {
                $user->add_role('tix_organizer');
            }
            $oid = wp_insert_post(['post_type' => 'tix_organizer', 'post_title' => $org_name, 'post_status' => 'publish', 'post_author' => $user->ID], true);
            if (is_wp_error($oid)) return $oid;
            update_post_meta($oid, '_tix_org_user_id', $user->ID);
            update_post_meta($oid, self::ORG_KIND, 'co');
            update_post_meta($oid, '_tix_org_co_invited_by', $ctx + ['user' => get_current_user_id(), 'at' => gmdate('c')]);
            if (class_exists('TIX_Org_Approval')) TIX_Org_Approval::set_status($oid, 'pending', ['by' => get_current_user_id(), 'note' => 'Als Co-Veranstalter eingeladen', 'notify' => false]);
            $org = get_post($oid);
            $created = true;
            if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush();
        } elseif ($org->post_status !== 'publish') {
            return new WP_Error('tix_invalid_field', 'Der Veranstalter zu dieser E-Mail ist nicht freigegeben.', ['status' => 400, 'field' => 'email']);
        }
        return [$org, $created, $user, $new_user];
    }

    public static function rest_invite(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $role = self::role($eid);
        if (!in_array($role, ['owner', 'admin'], true)) return self::deny('Einen Co-Veranstalter kann nur der Ersteller des Events einladen.');
        $b = $req->get_json_params() ?: $req->get_params();
        if (get_post_meta($eid, '_tix_syndicated', true) === '1') { // Spiegel: an die Quelle weiterreichen (1.38.715)
            if ($role !== 'admin') return self::deny('Den Co-Veranstalter legt die Seite des Erstellers fest.');
            $f = self::forward_mirror($eid, array_intersect_key((array) $b, array_flip(['email', 'site', 'organizer', 'name', 'access', 'money_owner', 'share'])));
            if (is_wp_error($f)) return $f;
            $res = rest_ensure_response($f + ['co_organizers' => (self::editor_payload($eid)['co_organizers'] ?? [])]);
            $res->set_status(201);
            return $res;
        }
        if (!empty($b['site'])) return self::invite_site($req, $eid, $role, (array) $b);
        $email = sanitize_email((string) ($b['email'] ?? ''));
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
        $org_name = trim(sanitize_text_field((string) ($b['organizer'] ?? '')));
        $name = mb_substr(trim(sanitize_text_field((string) ($b['name'] ?? ''))), 0, 120);
        $access = array_key_exists('access', (array) $b) ? filter_var($b['access'], FILTER_VALIDATE_BOOLEAN) : true;
        if ($access && $role !== 'admin' && empty($b['consent'])) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'consent']);
        }
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $existing = get_user_by('email', $email);
        $eorg = $existing ? self::org_for_user($existing->ID) : null;
        $money = self::money_in($eid, $b, $access, $eorg ? intval($eorg->ID) : -1);
        if (is_wp_error($money)) return $money;
        $r = self::find_or_create($email, $org_name, $name, ['event_id' => $eid, 'organizer_id' => $main]);
        if (is_wp_error($r)) return $r;
        [$org, $created, $user, $new_user] = $r;
        if (intval($org->ID) === $main) return new WP_Error('tix_co_self', 'Diese E-Mail gehört zum eigenen Veranstalter.', ['status' => 409, 'field' => 'email']);
        $res = self::link_one($eid, intval($org->ID), $access, ($access && !empty($b['consent'])) || $role === 'admin' ? get_current_user_id() : 0, $money, 'creator');
        if (is_wp_error($res)) return $res;
        $mailed = $res['linked'] || $new_user ? self::notify($eid, intval($org->ID), $new_user ? $user : null, $name) : false;
        $out = rest_ensure_response(['ok' => true, 'created' => $created, 'mailed' => (bool) $mailed, 'changed' => $res['changed'], 'co_organizer' => self::payload($eid, intval($org->ID)), 'co_organizers' => self::entries_payload($eid, true)]);
        $out->set_status($res['linked'] ? 201 : 200);
        return $out;
    }

    /**
     * Geldempfänger/Anteil beim Verbinden prüfen (1.38.711, mehrere Co 1.38.716): money_owner ('creator' | 'co' = dieser neue Co
     * | Veranstalter-ID), share (Anteil dieses Co), shares ([{organizer_id|party, percent}]). $new_oid = der neue Co (-1 = wird
     * erst angelegt, 0 = unbekannt). → null | array | WP_Error
     */
    private static function money_in($eid, array $b, $access, $new_oid) {
        if (!class_exists('TIX_Co_Money')) return null;
        $in = [];
        foreach (['money_owner' => 'owner', 'share' => 'share', 'shares' => 'shares'] as $k => $t) if (array_key_exists($k, $b)) $in[$t] = $b[$k];
        if (!$in) return null;
        return TIX_Co_Money::check_values($eid, $in, ['new' => intval($new_oid), 'new_access' => (bool) $access]);
    }

    /**
     * Einen Co hinzufügen bzw. ändern + Geld + Protokoll (gemeinsam für Einladen, Seite, Plattform). Ersetzt nie.
     * → ['linked' => bool, 'changed' => added|changed|unchanged, 'access' => bool] | WP_Error
     */
    private static function link_one($eid, $oid, $access, $consent_by, $money, $via) {
        $cur = self::entry_of($eid, $oid);
        $old_access = $cur ? $cur['access'] : null;
        if ($cur && $access === false && $cur['access'] && class_exists('TIX_Co_Money') && TIX_Co_Money::owner_id($eid) === $oid && TIX_Co_Money::sold($eid)) {
            return new WP_Error('tix_co_money_locked', 'Dieser Co-Veranstalter nimmt das Geld ein, und es gibt schon Verkäufe. Der Zugriff bleibt an.', ['status' => 409, 'field' => 'access']);
        }
        $linked = self::set($eid, $oid, $access, $consent_by);
        $now = self::has_access($eid, $oid);
        if (!$now && class_exists('TIX_Co_Money')) TIX_Co_Money::on_co_lost($eid, $oid, true);
        if (is_array($money) && class_exists('TIX_Co_Money')) TIX_Co_Money::apply($eid, TIX_Co_Money::resolve_new($money, $oid), $via);
        $changed = $linked ? 'added' : ($old_access !== $now ? 'changed' : 'unchanged');
        if ($changed !== 'unchanged') self::log_change($eid, $changed, $oid, $now, $via);
        return ['linked' => $linked, 'changed' => $changed, 'access' => $now];
    }

    /** Einen Co lösen + Geld + Protokoll + Mitteilung. → true | WP_Error */
    private static function unlink_one($eid, $oid, $via) {
        if (class_exists('TIX_Co_Money') && is_wp_error($cc = TIX_Co_Money::can_clear($eid, $oid))) return $cc;
        if (!self::remove($eid, $oid)) return true;
        if (class_exists('TIX_Co_Money')) TIX_Co_Money::on_co_lost($eid, $oid, false);
        self::log_change($eid, 'removed', $oid, false, $via);
        self::notify_removed_co($eid, $oid);
        return true;
    }

    /** Teil A: Kunden-Seite verbinden (invite mit `site`). */
    private static function invite_site(WP_REST_Request $req, $eid, $role, array $b) {
        $access = array_key_exists('access', $b) ? filter_var($b['access'], FILTER_VALIDATE_BOOLEAN) : true;
        if ($access && $role !== 'admin' && empty($b['consent'])) {
            return new WP_Error('tix_co_consent', 'Bitte bestätigen, dass Käuferdaten dieses Events an den Co-Veranstalter weitergegeben werden.', ['status' => 400, 'field' => 'consent']);
        }
        $r = TIX_Co_Sites::organizer_for(sanitize_text_field((string) $b['site']));
        if (is_wp_error($r)) return $r;
        [$oid, $created] = $r;
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        if ($oid === $main) return new WP_Error('tix_co_self', 'Diese Seite gehört zum Ersteller des Events.', ['status' => 409, 'field' => 'site']);
        $money = self::money_in($eid, $b, $access, $oid);
        if (is_wp_error($money)) return $money;
        $res = self::link_one($eid, $oid, $access, ($access && !empty($b['consent'])) || $role === 'admin' ? get_current_user_id() : 0, $money, 'creator');
        if (is_wp_error($res)) return $res;
        $mailed = false; $note = '';
        if ($res['linked']) [$mailed, $note] = TIX_Co_Sites::notify_result($eid, $oid);
        if ($res['changed'] !== 'unchanged') TIX_Co_Sites::trigger_sync($oid);
        $out = rest_ensure_response(['ok' => true, 'created' => $created, 'mailed' => (bool) $mailed, 'mail_note' => $note, 'changed' => $res['changed'], 'co_organizer' => self::payload($eid, $oid), 'co_organizers' => self::entries_payload($eid, true)]);
        $out->set_status($res['linked'] ? 201 : 200);
        return $out;
    }

    /** DELETE /events/{id}/co-organizer (Ersteller/Admin, evendis). */
    public static function rest_remove(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $role = self::role($eid);
        if (!in_array($role, ['owner', 'admin'], true)) return self::deny('Die Verbindung kann nur der Ersteller des Events trennen.');
        $want = absint($req->get_param('organizer_id'));
        if (get_post_meta($eid, '_tix_syndicated', true)) { // Spiegel einer Partnerseite: nur der Admin, Teil B lösen
            $cur = TIX_Co_Remote::mirror_ids($eid);
            $oid = self::pick_one($cur, $want);
            if (is_wp_error($oid)) return $oid;
            $src = $oid ? TIX_Co_Remote::release_mirror($eid, $role === 'admin' ? 'platform' : 'creator', $oid) : null;
            return rest_ensure_response(['ok' => true, 'changed' => $oid ? 'removed' : 'unchanged', 'removed_id' => $oid ?: null, 'co_organizers' => self::mirror_entries_payload($eid, $role === 'admin'), 'released' => ['platform' => $oid ? true : null, 'source' => $src]]);
        }
        $oid = self::pick_one(self::ids($eid), $want);
        if (is_wp_error($oid)) return $oid;
        if (!$oid) return rest_ensure_response(['ok' => true, 'changed' => 'unchanged', 'removed_id' => null, 'co_organizers' => self::entries_payload($eid, true), 'released' => ['platform' => null, 'source' => null]]);
        $via = $role === 'admin' && intval(get_post_meta($eid, '_tix_organizer_id', true)) !== TIX_App_Scope::organizer_id_for_user() ? 'platform' : 'creator';
        $u = self::unlink_one($eid, $oid, $via);
        if (is_wp_error($u)) return $u;
        if ($via === 'platform') self::notify_creator($eid, $oid, 'removed');
        if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush();
        return rest_ensure_response(['ok' => true, 'changed' => 'removed', 'removed_id' => $oid, 'co_organizers' => self::entries_payload($eid, true), 'released' => ['platform' => true, 'source' => null]]);
    }

    /**
     * Welcher Co ist beim Lösen gemeint? $want = organizer_id aus der Anfrage. Ohne Angabe nur, wenn es genau einen gibt.
     * → ID | 0 (keiner verbunden bzw. $want nicht verbunden) | WP_Error
     */
    public static function pick_one(array $ids, $want) {
        if ($want) return in_array(intval($want), $ids, true) ? intval($want) : 0;
        if (count($ids) > 1) return new WP_Error('tix_co_choose', 'Das Event hat mehrere Co-Veranstalter. Bitte angeben, welcher gelöst wird (organizer_id).', ['status' => 400, 'field' => 'organizer_id']);
        return $ids[0] ?? 0;
    }

    // ═══════════════ Mitteilungen ═══════════════

    /** Mail + Mitteilung an den Inhaber des Co-Veranstalters. $new_user = neues Konto → Link zum Passwort. */
    public static function notify($event_id, $oid, $new_user = null, $name = '') {
        $u = $new_user ?: self::owner_user($oid);
        if (!$u) return false;
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $title = html_entity_decode((string) get_the_title($event_id), ENT_QUOTES, 'UTF-8');
        $by = html_entity_decode((string) get_the_title(intval(get_post_meta($event_id, '_tix_organizer_id', true))), ENT_QUOTES, 'UTF-8') ?: $site;
        $studio = class_exists('TIX_Studio') && TIX_Studio::active() ? TIX_Studio::url('/') : admin_url();
        $access = self::has_access($event_id, $oid);
        $hello = $name !== '' ? 'Hallo ' . esc_html(strtok($name, ' ')) . ',' : 'Hallo,';
        $html = '<p>' . $hello . '</p><p>' . esc_html($by) . ' hat dich als Co-Veranstalter von <strong>' . esc_html($title) . '</strong> eingetragen.</p>';
        if ($access) $html .= '<p>Im Studio siehst du das Event unter „Events“ mit dem Hinweis „Gemeinsam mit ' . esc_html($by) . '“. Du kannst es dort bearbeiten, Verkäufe und Gäste sehen und den Einlass machen.</p>';
        else $html .= '<p>Du wirst beim Event als Mitveranstalter genannt.</p>';
        if ($new_user) { // wie die Team-Einladung: Code + Passwort im Studio festlegen (1.38.701)
            $pw = TIX_Owner_Access::password_block($new_user, 'co');
            if ($pw['html'] !== '') $html .= '<p>Für dich wurde ein Zugang angelegt (E-Mail: ' . esc_html($new_user->user_email) . ').</p>' . $pw['html'];
        }
        $html .= '<p>Zum Studio: <a href="' . esc_url($studio) . '">' . esc_html(preg_replace('#^https?://#', '', untrailingslashit($studio))) . '</a></p>';
        $subject = 'Du bist Co-Veranstalter von ' . $title;
        if (class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
            TIX_Notifications::add_user_item($u->ID, 'Du bist Co-Veranstalter', $title . ' · gemeinsam mit ' . $by, ['type' => 'message', 'action' => 'staff:event:' . intval($event_id)]);
        }
        if (class_exists('TIX_Mail_Layout')) {
            return (bool) TIX_Mail_Layout::send($u->user_email, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        }
        return (bool) wp_mail($u->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ═══════════════ Wahl Studio / Provision / Lizenz ═══════════════

    public static function is_co_kind($oid) {
        return get_post_meta(intval($oid), self::ORG_KIND, true) === 'co';
    }

    /** Gemeinsame Events, bei denen der Veranstalter Co-Veranstalter ist (mit und ohne Zugriff). */
    public static function shared_event_ids($oid) {
        $oid = intval($oid);
        if (!$oid) return [];
        return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => ['relation' => 'OR', ['key' => self::META_REF, 'value' => strval($oid)], ['key' => self::META_ID, 'value' => strval($oid)], ['key' => '_tix_co_remote_oid', 'value' => strval($oid)]]]));
    }

    public static function choice_of($oid) {
        $c = get_post_meta(intval($oid), self::ORG_CHOICE, true);
        return is_array($c) && in_array($c['choice'] ?? '', self::CHOICES, true) ? $c : null;
    }

    private static function my_org() {
        $p = TIX_App_Scope::organizer_post_for_user(get_current_user_id());
        return $p ? intval($p->ID) : 0;
    }

    public static function rest_choice(WP_REST_Request $req) {
        $oid = self::my_org();
        $c = $oid ? self::choice_of($oid) : null;
        $is_co = $oid && self::is_co_kind($oid);
        return rest_ensure_response(['ok' => true, 'show' => $is_co && !$c, 'is_co' => $is_co, 'choice' => $c ? $c['choice'] : null,
            'requested_at' => ($c && $c['choice'] !== 'studio') ? (string) $c['at'] : null, 'shared_events' => $oid ? count(self::shared_event_ids($oid)) : 0]);
    }

    public static function rest_save_choice(WP_REST_Request $req) {
        $oid = self::my_org();
        if (!$oid) return new WP_Error('no_organizer', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        $b = $req->get_json_params() ?: $req->get_params();
        $choice = sanitize_key((string) ($b['choice'] ?? ''));
        if (!in_array($choice, self::CHOICES, true)) return new WP_Error('tix_invalid_field', 'choice: studio, provision oder license.', ['status' => 400, 'field' => 'choice']);
        $msg = mb_substr(sanitize_textarea_field((string) ($b['message'] ?? '')), 0, 1000);
        $c = ['choice' => $choice, 'at' => gmdate('c'), 'message' => $msg, 'by' => get_current_user_id()];
        update_post_meta($oid, self::ORG_CHOICE, $c);
        if ($choice !== 'studio') self::request_admin($oid, $c);
        if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush();
        return rest_ensure_response(['ok' => true, 'choice' => $choice, 'requested_at' => $choice !== 'studio' ? $c['at'] : null, 'show' => false]);
    }

    private static function request_admin($oid, array $c) {
        $name = html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
        $u = self::owner_user($oid);
        $what = $c['choice'] === 'license' ? 'eine Lizenz (eigene Seite/App)' : 'eigene Events auf ' . html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8') . ' (Provision)';
        $html = '<p>Der Co-Veranstalter <strong>' . esc_html($name) . '</strong> möchte ' . esc_html($what) . '.</p>'
            . ($u ? '<p>Kontakt: ' . esc_html($u->display_name) . ' · ' . esc_html($u->user_email) . '</p>' : '')
            . ($c['message'] !== '' ? '<p>Nachricht:<br>' . nl2br(esc_html($c['message'])) . '</p>' : '')
            . '<p>Im Plattform-Studio unter Kunden → Co-Veranstalter.</p>';
        $subject = 'Anfrage Co-Veranstalter: ' . $name;
        $to = (string) get_option('admin_email');
        if (class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($to, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        else wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ═══════════════ Plattform ═══════════════

    /** Zusatz für die Kundenzeile (oder null). */
    public static function customer_info($oid) {
        $oid = intval($oid);
        if (!$oid) return null;
        $shared = self::shared_event_ids($oid);
        if (!self::is_co_kind($oid) && !$shared) return null;
        $c = self::choice_of($oid);
        return ['choice' => $c ? $c['choice'] : null, 'requested_at' => ($c && $c['choice'] !== 'studio') ? (string) $c['at'] : null,
            'message' => $c ? (string) ($c['message'] ?? '') : '', 'shared_events' => count($shared)];
    }

    public static function rest_platform_list(WP_REST_Request $req) {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s) AND meta_value <> ''", self::META_REF, self::META_ID, '_tix_co_remote_oid')));
        $kind = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'co'", self::ORG_KIND)));
        $ids = array_values(array_unique(array_filter(array_merge($ids, $kind))));
        $items = [];
        foreach ($ids as $oid) {
            $p = get_post($oid);
            if (!$p || $p->post_type !== 'tix_organizer') continue;
            $u = self::owner_user($oid);
            $c = self::choice_of($oid);
            $events = [];
            foreach (self::shared_event_ids($oid) as $eid) {
                $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
                $events[] = ['id' => $eid, 'title' => html_entity_decode((string) get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($eid, '_tix_date_start', true),
                    'owner' => $main ? ['id' => $main, 'name' => html_entity_decode((string) get_the_title($main), ENT_QUOTES, 'UTF-8')] : null,
                    'access' => get_post_meta($eid, '_tix_syndicated', true) === '1' ? TIX_Co_Remote::mirror_has_access($eid, $oid) : self::has_access($eid, $oid),
                    'remote' => get_post_meta($eid, '_tix_syndicated', true) === '1' ? ['site' => (string) get_post_meta($eid, '_tix_source_site', true), 'source_id' => intval(get_post_meta($eid, '_tix_source_id', true))] : null];
            }
            $key = class_exists('TIX_Platform_Customers') ? TIX_Platform_Customers::canonical('v-' . $oid) : 'v-' . $oid;
            $items[] = ['organizer_id' => $oid, 'key' => $key, 'name' => html_entity_decode((string) $p->post_title, ENT_QUOTES, 'UTF-8'), 'email' => $u ? (string) $u->user_email : '',
                'kind' => self::is_co_kind($oid) ? 'co' : 'other', 'status' => self::org_status($oid),
                'choice' => $c ? $c['choice'] : null, 'requested_at' => ($c && $c['choice'] !== 'studio') ? (string) $c['at'] : null, 'message' => $c ? (string) ($c['message'] ?? '') : '',
                'events' => $events];
        }
        usort($items, function ($a, $b) { return strcmp((string) $b['requested_at'], (string) $a['requested_at']) ?: strcasecmp($a['name'], $b['name']); });
        return rest_ensure_response(['ok' => true, 'items' => $items]);
    }

    public static function rest_convert(WP_REST_Request $req) {
        $b = $req->get_json_params() ?: $req->get_params();
        if (($b['to'] ?? '') !== 'provision') return new WP_Error('tix_invalid_field', 'to: provision (Lizenz über Angebote).', ['status' => 400, 'field' => 'to']);
        $c = TIX_Platform_Customers::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $oid = intval($c['organizer_id']);
        if (!$oid || !self::is_co_kind($oid)) return new WP_Error('tix_not_co', 'Dieser Kunde ist kein Co-Veranstalter.', ['status' => 409]);
        update_post_meta($oid, self::ORG_KIND, 'provision');
        if (!self::choice_of($oid)) update_post_meta($oid, self::ORG_CHOICE, ['choice' => 'provision', 'at' => gmdate('c'), 'message' => '', 'by' => get_current_user_id()]);
        TIX_Platform_Customers::flush();
        return rest_ensure_response(['ok' => true, 'key' => TIX_Platform_Customers::canonical('v-' . $oid), 'art' => 'provision']);
    }

    // ═══════════════ Plattform-Admin: Co-Veranstalter je Event (1.38.705) ═══════════════

    const ORG_LOG = '_tix_org_co_log';

    /** Protokoll in beiden Veranstaltern (Ersteller + Co), neueste zuletzt, je 50. */
    public static function log_change($event_id, $what, $co_oid, $access, $via) {
        $event_id = intval($event_id);
        $co_oid = intval($co_oid);
        $main = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        $u = wp_get_current_user();
        $base = ['at' => gmdate('c'), 'what' => $what, 'event_id' => $event_id,
            'event_title' => html_entity_decode((string) get_the_title($event_id), ENT_QUOTES, 'UTF-8'),
            'access' => (bool) $access, 'by' => ['id' => intval($u->ID), 'name' => (string) ($u->display_name ?: $u->user_login)], 'via' => $via];
        foreach ([[$main, 'owner', $co_oid], [$co_oid, 'co', $main]] as [$oid, $role, $other]) {
            if (!$oid || get_post_type($oid) !== 'tix_organizer') continue;
            $log = (array) get_post_meta($oid, self::ORG_LOG, true);
            $log[] = $base + ['role' => $role, 'other' => $other ? ['id' => $other, 'name' => html_entity_decode((string) get_the_title($other), ENT_QUOTES, 'UTF-8')] : null];
            update_post_meta($oid, self::ORG_LOG, array_slice(array_values(array_filter($log, 'is_array')), -50));
        }
    }

    public static function org_log($oid) {
        return array_reverse(array_values(array_filter((array) get_post_meta(intval($oid), self::ORG_LOG, true), 'is_array')));
    }

    /** Sperre für den Plattform-Admin: null oder {reason, site, message}. */
    public static function platform_lock($event_id) {
        $event_id = intval($event_id);
        if (get_post_status($event_id) === 'trash') return ['reason' => 'trash', 'site' => '', 'message' => 'Das Event liegt im Papierkorb.'];
        if (get_post_meta($event_id, '_tix_syndicated', true)) {
            $site = (string) (get_post_meta($event_id, '_tix_moved_to', true) ?: get_post_meta($event_id, '_tix_source_site', true));
            $site = preg_replace('#^https?://#', '', untrailingslashit($site)) ?: 'der Partnerseite';
            $p = class_exists('TIX_Partners') ? TIX_Partners::get((string) get_post_meta($event_id, '_tix_source_partner', true)) : null;
            $fw = $p && !empty($p['api_base']) && strlen((string) ($p['key_out'] ?? '')) >= 20 && intval(get_post_meta($event_id, '_tix_source_id', true)) > 0;
            return ['reason' => 'source', 'site' => $site, 'forward' => $fw, 'message' => $fw ? 'Dieses Event gehört ' . $site . '. Der Co-Veranstalter wird dort eingetragen und gilt auf beiden Seiten.' : 'Dieses Event kommt von ' . $site . '. Den Co-Veranstalter legt der Ersteller dort an.'];
        }
        return null;
    }

    private static function org_mini($oid) {
        if (!$oid || get_post_type($oid) !== 'tix_organizer') return null;
        $key = class_exists('TIX_Platform_Customers') ? TIX_Platform_Customers::canonical('v-' . $oid) : null;
        return ['id' => intval($oid), 'name' => html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8'), 'key' => $key ?: 'v-' . intval($oid)];
    }

    public static function platform_event_row($event_id) {
        $event_id = intval($event_id);
        $lock = self::platform_lock($event_id);
        $rm = $lock === null || $lock['reason'] === 'source';
        $list = get_post_meta($event_id, '_tix_syndicated', true) ? self::mirror_entries_payload($event_id, $rm) : self::entries_payload($event_id, $rm);
        $co = $list[0] ?? null;
        $img = get_the_post_thumbnail_url($event_id, 'medium');
        return [
            'id' => $event_id, 'title' => html_entity_decode((string) get_the_title($event_id), ENT_QUOTES, 'UTF-8'),
            'date' => (string) get_post_meta($event_id, '_tix_date_start', true), 'time' => (string) get_post_meta($event_id, '_tix_time_start', true),
            'status' => (string) get_post_status($event_id), 'image' => $img ? (string) $img : '',
            'owner' => self::org_mini(intval(get_post_meta($event_id, '_tix_organizer_id', true))),
            'co_organizer' => $co, 'co_organizers' => $list, 'co_editable' => $lock === null || ($lock['reason'] === 'source' && !empty($lock['forward'])), 'co_locked' => ($lock && empty($lock['forward'])) ? $lock : null,
            'co_source' => ($lock && $lock['reason'] === 'source') ? ['site' => $lock['site'], 'forward' => !empty($lock['forward']), 'note' => $lock['message']] : null,
            'co_removable' => $co !== null && $rm,
            'co_money' => class_exists('TIX_Co_Money') ? TIX_Co_Money::payload($event_id) : null,
        ];
    }

    private static function sort_rows(array &$rows, $desc) {
        usort($rows, function ($a, $b) use ($desc) {
            $x = ($a['date'] ?: '9999') . ' ' . $a['time']; $y = ($b['date'] ?: '9999') . ' ' . $b['time'];
            return $desc ? strcmp($y, $x) : strcmp($x, $y);
        });
    }

    public static function rest_platform_events(WP_REST_Request $req) {
        $q = trim(sanitize_text_field((string) $req->get_param('q')));
        $scope = sanitize_key((string) ($req->get_param('scope') ?: 'upcoming'));
        if (!in_array($scope, ['upcoming', 'past', 'all'], true)) return new WP_Error('tix_invalid_field', 'scope: upcoming, past oder all.', ['status' => 400, 'field' => 'scope']);
        $page = max(1, absint($req->get_param('page') ?: 1));
        $per = min(100, max(1, absint($req->get_param('per_page') ?: 20)));
        $args = ['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 1000, 'fields' => 'ids', 'no_found_rows' => true];
        if (mb_strlen($q) >= 2) $args['s'] = $q;
        $mq = [];
        if ($oid = absint($req->get_param('organizer_id'))) $mq[] = ['key' => '_tix_organizer_id', 'value' => strval($oid)];
        if ($cid = absint($req->get_param('co_organizer_id'))) $mq[] = ['relation' => 'OR', ['key' => self::META_REF, 'value' => strval($cid)], ['key' => self::META_ID, 'value' => strval($cid)], ['key' => '_tix_co_remote_oid', 'value' => strval($cid)]];
        if ($mq) $args['meta_query'] = $mq;
        $today = wp_date('Y-m-d');
        $rows = [];
        foreach (get_posts($args) as $eid) {
            $d = (string) get_post_meta($eid, '_tix_date_end', true) ?: (string) get_post_meta($eid, '_tix_date_start', true);
            $past = $d !== '' && $d < $today;
            if (($scope === 'upcoming' && $past) || ($scope === 'past' && !$past)) continue;
            $rows[] = ['id' => intval($eid), 'date' => (string) get_post_meta($eid, '_tix_date_start', true), 'time' => (string) get_post_meta($eid, '_tix_time_start', true)];
        }
        self::sort_rows($rows, $scope === 'past');
        $total = count($rows);
        $items = array_map(function ($r) { return self::platform_event_row($r['id']); }, array_slice($rows, ($page - 1) * $per, $per));
        return rest_ensure_response(['ok' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'has_more' => $page * $per < $total]);
    }

    public static function rest_platform_customer_co(WP_REST_Request $req) {
        $c = TIX_Platform_Customers::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        $oid = intval($c['organizer_id'] ?? 0);
        if (!$oid || get_post_type($oid) !== 'tix_organizer') return new WP_Error('tix_no_organizer', 'Dieser Kunde hat keinen Veranstalter auf ' . html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8') . '.', ['status' => 409]);
        $today = wp_date('Y-m-d');
        $own = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 500, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => strval($oid)]]]);
        $up = []; $past = [];
        foreach ($own as $eid) {
            $d = (string) get_post_meta($eid, '_tix_date_end', true) ?: (string) get_post_meta($eid, '_tix_date_start', true);
            $r = ['id' => intval($eid), 'date' => (string) get_post_meta($eid, '_tix_date_start', true), 'time' => (string) get_post_meta($eid, '_tix_time_start', true)];
            if ($d !== '' && $d < $today) $past[] = $r; else $up[] = $r;
        }
        self::sort_rows($up, false); self::sort_rows($past, true);
        $own_rows = array_map(function ($r) { return self::platform_event_row($r['id']); }, array_slice(array_merge($up, $past), 0, 200));
        $shared = array_values(array_filter(self::shared_event_ids($oid), function ($e) use ($oid) { return intval(get_post_meta($e, '_tix_organizer_id', true)) !== $oid; }));
        $sr = array_map(function ($e) { return ['id' => intval($e), 'date' => (string) get_post_meta($e, '_tix_date_start', true), 'time' => (string) get_post_meta($e, '_tix_time_start', true)]; }, $shared);
        self::sort_rows($sr, false);
        return rest_ensure_response(['ok' => true, 'organizer_id' => $oid, 'own_events' => $own_rows,
            'shared_events' => array_map(function ($r) { return self::platform_event_row($r['id']); }, $sr), 'log' => self::org_log($oid)]);
    }

    private static function platform_event_or_error($eid) {
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $lock = self::platform_lock($eid);
        if ($lock) return new WP_Error($lock['reason'] === 'source' ? 'tix_co_source' : 'tix_co_trash', $lock['message'], ['status' => 409, 'site' => $lock['site']]);
        return true;
    }

    public static function rest_platform_set(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $b = (array) ($req->get_json_params() ?: $req->get_params());
        $lock = get_post_type($eid) === 'event' ? self::platform_lock($eid) : null;
        if ($lock && $lock['reason'] === 'source' && !empty($lock['forward'])) { // Spiegel: an die Quelle (1.38.715)
            $in = array_intersect_key($b, array_flip(['organizer_id', 'email', 'site', 'organizer', 'name', 'access', 'money_owner', 'share']));
            $was = TIX_Co_Remote::mirror_ids($eid);
            if (empty($in['organizer_id']) && empty($in['email']) && empty($in['site']) && count($was) === 1) $in['organizer_id'] = $was[0];
            if (empty($in['organizer_id']) && empty($in['email']) && empty($in['site'])) return new WP_Error('tix_invalid_field', 'Bitte einen Veranstalter wählen (organizer_id) oder Name und E-Mail angeben.', ['status' => 400, 'field' => 'organizer_id']);
            $f = self::forward_mirror($eid, $in);
            if (is_wp_error($f)) return $f;
            $added = array_diff(TIX_Co_Remote::mirror_ids($eid), $was);
            $res = rest_ensure_response($f + ['changed' => $added ? 'added' : 'changed', 'event' => self::platform_event_row($eid)]);
            $res->set_status($added ? 201 : 200);
            return $res;
        }
        $ok = self::platform_event_or_error($eid);
        if (is_wp_error($ok)) return $ok;
        $access = null;
        if (array_key_exists('access', $b) && $b['access'] !== null) {
            $access = filter_var($b['access'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($access === null) return new WP_Error('tix_invalid_field', 'access: true oder false.', ['status' => 400, 'field' => 'access']);
        }
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $created = false; $new_user = null; $name = ''; $site_oid = 0;
        if (!empty($b['site'])) {
            $r = TIX_Co_Sites::organizer_for(sanitize_text_field((string) $b['site']));
            if (is_wp_error($r)) return $r;
            [$oid, $created] = $r;
            $site_oid = $oid;
            if ($oid === $main) return new WP_Error('tix_co_self', 'Diese Seite gehört zum Ersteller des Events.', ['status' => 409, 'field' => 'site']);
        } elseif (!empty($b['organizer_id'])) {
            $oid = absint($b['organizer_id']);
            if (!self::org_ok($oid)) return new WP_Error('tix_invalid_field', 'Diesen Veranstalter gibt es nicht oder er ist nicht veröffentlicht.', ['status' => 400, 'field' => 'organizer_id']);
            if ($oid === $main) return new WP_Error('tix_co_self', 'Der Ersteller kann nicht Co-Veranstalter seines eigenen Events sein.', ['status' => 409, 'field' => 'organizer_id']);
        } elseif (isset($b['email']) && $b['email'] !== '') {
            $email = sanitize_email((string) $b['email']);
            if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => 'email']);
            $name = mb_substr(trim(sanitize_text_field((string) ($b['name'] ?? ''))), 0, 120);
            $existing = get_user_by('email', $email);
            $eorg = $existing ? self::org_for_user($existing->ID) : null;
            if ($eorg && intval($eorg->ID) === $main) return new WP_Error('tix_co_self', 'Diese E-Mail gehört zum Ersteller des Events.', ['status' => 409, 'field' => 'email']);
            $r = self::find_or_create($email, trim(sanitize_text_field((string) ($b['organizer'] ?? ''))), $name, ['event_id' => $eid, 'organizer_id' => $main, 'via' => 'platform']);
            if (is_wp_error($r)) return $r;
            [$org, $created, $user, $nu] = $r;
            $oid = intval($org->ID);
            if ($nu) $new_user = $user;
        } else {
            return new WP_Error('tix_invalid_field', 'Bitte einen Veranstalter wählen (organizer_id) oder Name und E-Mail angeben.', ['status' => 400, 'field' => 'organizer_id']);
        }
        $cur = self::entry_of($eid, $oid);
        $money = self::money_in($eid, $b, $access === null ? ($cur ? $cur['access'] : true) : $access, $oid);
        if (is_wp_error($money)) return $money;
        $res = self::link_one($eid, $oid, $access, get_current_user_id(), $money, 'platform');
        if (is_wp_error($res)) return $res;
        $linked = $res['linked'];
        $changed = $res['changed'];
        $mailed = false; $note = '';
        if ($linked) {
            if ($site_oid) { [$mailed, $note] = TIX_Co_Sites::notify_result($eid, $oid, self::platform_by()); TIX_Co_Sites::trigger_sync($oid); }
            else $mailed = self::notify($eid, $oid, $new_user, $name);
            self::notify_creator($eid, $oid, 'added');
        } elseif ($changed === 'changed') {
            if (class_exists('TIX_Co_Sites') && TIX_Co_Sites::is_site_profile($oid)) TIX_Co_Sites::trigger_sync($oid);
            self::notify_creator($eid, $oid, 'changed');
        }
        if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush();
        $res = rest_ensure_response(['ok' => true, 'created' => $created, 'mailed' => (bool) $mailed, 'mail_note' => $note, 'changed' => $changed, 'event' => self::platform_event_row($eid)]);
        $res->set_status($linked ? 201 : 200);
        return $res;
    }

    private static function platform_by() {
        return html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
    }

    public static function rest_platform_clear(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $lock = get_post_type($eid) === 'event' ? self::platform_lock($eid) : null;
        $want = absint($req->get_param('organizer_id'));
        if ($lock && $lock['reason'] === 'source') { // geteiltes Event: Teil B lösen (1.38.706)
            $oid = self::pick_one(TIX_Co_Remote::mirror_ids($eid), $want);
            if (is_wp_error($oid)) return $oid;
            $src = $oid ? TIX_Co_Remote::release_mirror($eid, 'platform', $oid) : null;
            if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush();
            return rest_ensure_response(['ok' => true, 'changed' => $oid ? 'removed' : 'unchanged', 'removed_id' => $oid ?: null, 'event' => self::platform_event_row($eid), 'source_released' => $src]);
        }
        $ok = self::platform_event_or_error($eid);
        if (is_wp_error($ok)) return $ok;
        $oid = self::pick_one(self::ids($eid), $want);
        if (is_wp_error($oid)) return $oid;
        if (!$oid) return rest_ensure_response(['ok' => true, 'changed' => 'unchanged', 'removed_id' => null, 'event' => self::platform_event_row($eid)]);
        $u = self::unlink_one($eid, $oid, 'platform');
        if (is_wp_error($u)) return $u;
        self::notify_creator($eid, $oid, 'removed');
        if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush();
        return rest_ensure_response(['ok' => true, 'changed' => 'removed', 'removed_id' => $oid, 'event' => self::platform_event_row($eid), 'source_released' => null]);
    }

    private static function feed($uid, $title, $body, $eid) {
        if ($uid && class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
            TIX_Notifications::add_user_item($uid, $title, $body, ['type' => 'message', 'action' => 'staff:event:' . intval($eid)]);
        }
    }

    public static function notify_removed_co($eid, $oid) {
        $u = self::owner_user($oid);
        if ($u) self::feed($u->ID, 'Nicht mehr Co-Veranstalter', 'Du bist nicht mehr Co-Veranstalter von ' . html_entity_decode((string) get_the_title($eid), ENT_QUOTES, 'UTF-8') . '.', $eid);
    }

    /** Ersteller informieren: added = Mail + Feed, changed/removed = Feed. */
    private static function notify_creator($eid, $co_oid, $what) {
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $u = $main ? self::owner_user($main) : null;
        if (!$u) return false;
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $title = html_entity_decode((string) get_the_title($eid), ENT_QUOTES, 'UTF-8');
        $co = html_entity_decode((string) get_the_title($co_oid), ENT_QUOTES, 'UTF-8');
        $acc = self::has_access($eid, $co_oid);
        $text = [
            'added' => $site . ' hat ' . $co . ' als Co-Veranstalter hinzugefügt',
            'changed' => $site . ' hat den Zugriff von ' . $co . ' ' . ($acc ? 'eingeschaltet' : 'ausgeschaltet'),
            'removed' => $site . ' hat ' . $co . ' als Co-Veranstalter entfernt',
        ][$what];
        self::feed($u->ID, $text, $title, $eid);
        if ($what !== 'added') return true;
        $html = '<p>Hallo,</p><p>' . esc_html($text) . ': <strong>' . esc_html($title) . '</strong>.</p>'
            . ($acc ? '<p>' . esc_html($co) . ' kann das Event jetzt mit bearbeiten, Verkäufe und Gäste sehen und den Einlass machen. Löschen, archivieren und die Abrechnung bleiben bei dir.</p>'
                    : '<p>' . esc_html($co) . ' wird beim Event als Mitveranstalter genannt, hat aber keinen Zugriff.</p>')
            . '<p>Ändern oder lösen kannst du das im Studio beim Event unter „Co-Veranstalter“.</p>';
        $subject = 'Neuer Co-Veranstalter bei ' . $title;
        if (class_exists('TIX_Mail_Layout')) return (bool) TIX_Mail_Layout::send($u->user_email, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        return (bool) wp_mail($u->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }
}
