<?php
if (!defined('ABSPATH')) exit;

/**
 * Freigabe neuer Veranstalter (nur Mehr-Veranstalter-Modus, evendis.de).
 *
 * Status je Veranstalter (`tix_organizer`, Post-Meta `_tix_org_approval`):
 *   pending  – wartet auf Freigabe (Vorgabe nach Selbst-Registrierung)
 *   approved – freigegeben (Vorgabe für alles, was ein Admin/System anlegt, und für Bestand)
 *   rejected – abgelehnt (mit Begründung, Veranstalter kann erneut einreichen)
 *   blocked  – gesperrt (Events offline, Abrechnungen laufen weiter)
 * Fehlt das Meta, gilt „freigegeben“ – so geht beim Einführen nichts offline.
 *
 * Solange nicht freigegeben:
 *   - Der Veranstalter-Post bleibt veröffentlicht (Anmeldung, Profil, Events, Auszahlungsdaten
 *     funktionieren wie gewohnt), erscheint aber nicht in `/public/organizers` und auf keiner
 *     Landingpage.
 *   - Events lassen sich nicht veröffentlichen: `publish`/`future` wird zu `pending`, der gewünschte
 *     Status liegt in `_tix_publish_held` und wird bei der Freigabe wiederhergestellt. Beim Sperren
 *     werden veröffentlichte Events genauso zurückgehalten.
 *   - Kasse (Web, App, Kasse vor Ort) lehnt ab (`organizer_not_approved`).
 * Geteilte Events (`_tix_syndicated=1`) sind nicht betroffen.
 *
 * Pflichtangaben (Haken in der Prüfliste, Liste `missing` in der App): Firma/Name, Anschrift,
 * Kontakt (Telefon), Steuer-Status, Auszahlungsdaten (aus TIX_Payout_Details), Zustimmung zum
 * Vermittlungsvertrag (Version + Zeitpunkt in `_tix_org_terms`). Freigabe ohne vollständige
 * Angaben nur mit ausdrücklicher Bestätigung des Admins.
 *
 * Admin: Tixomat → Veranstalter-Prüfung (`tix-org-review`), Veranstalter: „Mein Konto“
 * (`tix-org-account`) + Hinweis auf allen Seiten. REST: `GET /organizer/approval`,
 * `POST /organizer/approval/terms`, `POST /organizer/approval/resubmit`, zusätzlich `approval`
 * im Veranstalter-Objekt von `/me`.
 */
class TIX_Org_Approval {

    const NS          = 'tixomat/v1';
    const META_STATUS = '_tix_org_approval';
    const META_INFO   = '_tix_org_approval_info';
    const META_LOG    = '_tix_org_approval_log';
    const META_TERMS  = '_tix_org_terms';
    const META_HELD   = '_tix_publish_held';
    const OPTION      = 'tix_org_approval_settings';
    const DB_OPTION   = 'tix_org_approval_db';
    const DB_VERSION  = '1';
    const ADMIN_SLUG  = 'tix-org-review';
    const ORG_SLUG    = 'tix-org-account';
    const STATUSES    = ['pending', 'approved', 'rejected', 'blocked'];

    /** Vom Status-Filter zurückgehaltene Events, deren Meta nach dem Speichern gesetzt wird. */
    private static $hold = [];
    /** Events, deren Veranstalter-Zuordnung sich geändert hat (Prüfung nach dem Speichern). */
    private static $queue = [];
    private static $processing = false;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_migrate'], 7);
        add_filter('wp_insert_post_data', [__CLASS__, 'filter_event_status'], 99, 2);
        add_action('wp_insert_post', [__CLASS__, 'on_insert'], 10, 3);
        add_action('wp_insert_post', [__CLASS__, 'process_queue'], 999);
        add_action('shutdown', [__CLASS__, 'process_queue']);
        add_action('added_post_meta', [__CLASS__, 'on_meta'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'on_meta'], 10, 4);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 22);
        add_action('admin_post_tix_org_review', [__CLASS__, 'handle_review']);
        add_action('admin_post_tix_org_review_settings', [__CLASS__, 'handle_settings']);
        add_action('admin_post_tix_org_account', [__CLASS__, 'handle_account']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_filter('wp_robots', [__CLASS__, 'robots']);
        // Öffentliche Veranstalter-Seiten /veranstalter/<slug>/ (TIX_Organizer_Pages) + Sitemap
        add_filter('tix_organizer_page_public', [__CLASS__, 'filter_page_public'], 10, 2);
        add_filter('wp_sitemaps_posts_query_args', [__CLASS__, 'filter_sitemap'], 10, 2);
    }

    /** Veranstalter-Seite nur für freigegebene Veranstalter (sonst 404 + noindex). */
    public static function filter_page_public($public, $oid) {
        return $public && self::is_public(intval($oid));
    }

    /** Nicht freigegebene Veranstalter nicht in die WordPress-Sitemap. */
    public static function filter_sitemap($args, $post_type) {
        if ($post_type !== 'tix_organizer' || !self::multi()) return $args;
        $mq = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];
        $mq[] = [
            'relation' => 'OR',
            ['key' => self::META_STATUS, 'compare' => 'NOT EXISTS'],
            ['key' => self::META_STATUS, 'value' => 'approved'],
        ];
        $args['meta_query'] = $mq;
        return $args;
    }

    // ──────────────────────────────────────────
    //  Grundlagen
    // ──────────────────────────────────────────

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Status des Veranstalters; außerhalb des Mehr-Veranstalter-Modus immer „approved“. */
    public static function status($oid) {
        if (!self::multi()) return 'approved';
        $s = (string) get_post_meta(intval($oid), self::META_STATUS, true);
        return in_array($s, self::STATUSES, true) ? $s : 'approved';
    }

    /** Darf der Veranstalter öffentlich erscheinen und verkaufen? */
    public static function is_public($oid) {
        return self::status($oid) === 'approved';
    }

    public static function labels() {
        return ['pending' => 'Wartet auf Freigabe', 'approved' => 'Freigegeben', 'rejected' => 'Abgelehnt', 'blocked' => 'Gesperrt'];
    }

    public static function label($status) {
        return self::labels()[$status] ?? $status;
    }

    private static function is_syndicated($event_id) {
        return get_post_meta(intval($event_id), '_tix_syndicated', true) === '1';
    }

    /** Darf ein Event öffentlich sein bzw. verkaufen? (Veranstalter freigegeben; geteilte Events immer) */
    public static function event_allowed($event_id) {
        if (!self::multi()) return true;
        $event_id = intval($event_id);
        if (self::is_syndicated($event_id)) return true;
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        return !$oid || self::is_public($oid);
    }

    /** null = Verkauf erlaubt, sonst WP_Error für Kasse/App/Kasse vor Ort. */
    public static function sale_error($event_id) {
        if (self::event_allowed($event_id)) return null;
        $oid = intval(get_post_meta(intval($event_id), '_tix_organizer_id', true));
        $msg = self::status($oid) === 'blocked'
            ? 'Der Veranstalter ist derzeit gesperrt – Tickets können nicht verkauft werden.'
            : 'Der Veranstalter ist noch nicht freigeschaltet – Tickets können noch nicht verkauft werden.';
        return new WP_Error('organizer_not_approved', $msg, ['status' => 403, 'approval' => self::status($oid)]);
    }

    // ──────────────────────────────────────────
    //  Einstellungen (Vermittlungsvertrag, Benachrichtigung)
    // ──────────────────────────────────────────

    public static function settings() {
        $s = get_option(self::OPTION, []);
        return array_merge([
            'terms_title'   => 'Vermittlungsvertrag für Veranstalter',
            'terms_url'     => '',
            'terms_page_id' => 0,
            'terms_version' => '',
            'notify_email'  => '',
            'push_admins'   => 1,
        ], is_array($s) ? $s : []);
    }

    /** Aktueller Vertrag: {title, url, version}; version '' = kein Vertrag eingerichtet. */
    public static function terms() {
        $s = self::settings();
        $url = (string) $s['terms_url'];
        if ($url === '' && intval($s['terms_page_id'])) $url = (string) get_permalink(intval($s['terms_page_id']));
        return ['title' => (string) $s['terms_title'], 'url' => $url, 'version' => (string) $s['terms_version']];
    }

    public static function terms_accepted($oid) {
        $t = self::terms();
        if ($t['version'] === '') return true;
        $a = get_post_meta(intval($oid), self::META_TERMS, true);
        return is_array($a) && (string) ($a['version'] ?? '') === $t['version'];
    }

    /** Zustimmung speichern (Version + Zeitpunkt + Nutzer + IP). */
    public static function accept_terms($oid, $uid, $version = null) {
        $t = self::terms();
        $version = $version === null ? $t['version'] : (string) $version;
        if ($t['version'] === '' || $version !== $t['version']) return false;
        update_post_meta(intval($oid), self::META_TERMS, [
            'version'     => $version,
            'accepted_at' => time(),
            'user_id'     => intval($uid),
            'ip'          => sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            'url'         => $t['url'],
        ]);
        self::log($oid, 'terms', ['by' => intval($uid), 'note' => 'Vertrag zugestimmt (Version ' . $version . ')']);
        return true;
    }

    public static function admin_email() {
        $s = self::settings();
        $to = (string) $s['notify_email'];
        if ($to === '' && function_exists('tix_get_settings')) $to = (string) tix_get_settings('invoice_email');
        return $to !== '' ? $to : (string) get_option('admin_email');
    }

    // ──────────────────────────────────────────
    //  Pflichtangaben
    // ──────────────────────────────────────────

    /** Liste der Pflichtangaben: [{key, label, done, target (profile|payout|terms), hint}] */
    public static function requirements($oid) {
        $oid = intval($oid);
        $pd = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::payload($oid) : [];
        $b = is_array($pd['billing'] ?? null) ? $pd['billing'] : [];
        $tax = (string) ($pd['tax_status'] ?? '');
        $tax_ok = ($tax === 'small_business')
            || ($tax === 'vat_id' && (string) ($pd['vat_id'] ?? '') !== '')
            || ($tax === 'tax_number' && (string) ($pd['tax_number'] ?? '') !== '');
        $phone = trim((string) get_post_meta($oid, '_tix_org_phone', true));
        $items = [
            ['key' => 'name', 'label' => 'Firma bzw. Name', 'target' => 'payout',
             'done' => trim((string) ($b['company'] ?? '')) !== '' || trim((string) ($b['name'] ?? '')) !== '',
             'hint' => 'Rechnungsadresse: Firma oder Vor- und Nachname'],
            ['key' => 'address', 'label' => 'Anschrift', 'target' => 'payout',
             'done' => trim((string) ($b['street'] ?? '')) !== '' && trim((string) ($b['zip'] ?? '')) !== '' && trim((string) ($b['city'] ?? '')) !== '',
             'hint' => 'Straße, PLZ und Ort der Rechnungsadresse'],
            ['key' => 'contact', 'label' => 'Kontakt (Telefon)', 'target' => 'profile',
             'done' => $phone !== '',
             'hint' => 'Telefonnummer für Rückfragen im Veranstalter-Profil'],
            ['key' => 'tax', 'label' => 'Steuer-Status', 'target' => 'payout',
             'done' => $tax_ok,
             'hint' => 'USt-IdNr., Steuernummer oder Kleinunternehmer'],
            ['key' => 'payout', 'label' => 'Auszahlungsdaten (IBAN)', 'target' => 'payout',
             'done' => trim((string) ($pd['holder'] ?? '')) !== '' && !empty($pd['has_iban']),
             'hint' => 'Kontoinhaber und bestätigte IBAN'],
        ];
        $t = self::terms();
        if ($t['version'] !== '') {
            $items[] = ['key' => 'terms', 'label' => $t['title'], 'target' => 'terms',
                        'done' => self::terms_accepted($oid),
                        'hint' => 'Zustimmung zur aktuellen Version (' . $t['version'] . ')'];
        }
        return $items;
    }

    public static function missing($oid) {
        $out = [];
        foreach (self::requirements($oid) as $r) {
            if (!$r['done']) $out[] = $r['key'];
        }
        return $out;
    }

    private static function iso($ts) {
        $ts = intval($ts);
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }

    /** Status-Objekt für App (`/me`, `/organizer/approval`) und Web. */
    public static function payload($oid, $uid = 0) {
        $oid = intval($oid);
        $uid = $uid ?: get_current_user_id();
        $st = self::status($oid);
        $info = get_post_meta($oid, self::META_INFO, true);
        $info = is_array($info) ? $info : [];
        $reqs = self::requirements($oid);
        $missing = [];
        foreach ($reqs as $r) { if (!$r['done']) $missing[] = $r['key']; }
        $t = self::terms();
        $acc = get_post_meta($oid, self::META_TERMS, true);
        $acc = is_array($acc) ? $acc : [];
        $texts = [
            'pending'  => ['Dein Konto wird geprüft', 'Wir prüfen deine Angaben. Du kannst dein Profil und deine Events schon vorbereiten – veröffentlicht wird nach der Freigabe, verkaufen kannst du ab dann.'],
            'approved' => ['Dein Konto ist freigegeben', 'Deine Events sind öffentlich und der Ticketverkauf ist aktiv.'],
            'rejected' => ['Dein Konto wurde nicht freigegeben', 'Bitte prüfe die Begründung, ergänze deine Angaben und reiche dein Konto erneut zur Prüfung ein.'],
            'blocked'  => ['Dein Konto ist gesperrt', 'Deine Events sind offline und es werden keine Tickets verkauft. Bereits verkaufte Tickets bleiben gültig, laufende Abrechnungen werden wie gewohnt erstellt. Bei Fragen wende dich an den Support.'],
        ];
        return [
            'status'       => $st,
            'label'        => self::label($st),
            'title'        => $texts[$st][0],
            'message'      => $texts[$st][1],
            'reason'       => in_array($st, ['rejected', 'blocked'], true) ? (string) ($info['reason'] ?? '') : '',
            'can_sell'     => $st === 'approved',
            'can_publish'  => $st === 'approved',
            'can_resubmit' => $st === 'rejected' && self::user_is_manager($oid, $uid),
            'complete'     => !$missing,
            'missing'      => $missing,
            'requirements' => array_map(function ($r) { return ['key' => $r['key'], 'label' => $r['label'], 'done' => (bool) $r['done'], 'target' => $r['target'], 'hint' => $r['hint']]; }, $reqs),
            'terms'        => [
                'title'            => $t['title'],
                'url'              => $t['url'],
                'version'          => $t['version'],
                'required'         => $t['version'] !== '',
                'accepted'         => self::terms_accepted($oid),
                'accepted_version' => (string) ($acc['version'] ?? ''),
                'accepted_at'      => self::iso($acc['accepted_at'] ?? 0),
            ],
            'submitted_at' => self::iso($info['submitted_at'] ?? 0),
            'decided_at'   => self::iso($info['decided_at'] ?? 0),
        ];
    }

    // ──────────────────────────────────────────
    //  Status setzen
    // ──────────────────────────────────────────

    public static function log($oid, $action, array $args = []) {
        $log = get_post_meta(intval($oid), self::META_LOG, true);
        $log = is_array($log) ? $log : [];
        $log[] = [
            'at'     => time(),
            'by'     => intval($args['by'] ?? get_current_user_id()),
            'action' => (string) $action,
            'note'   => (string) ($args['note'] ?? ''),
            'forced' => !empty($args['forced']),
        ];
        if (count($log) > 100) $log = array_slice($log, -100);
        update_post_meta(intval($oid), self::META_LOG, $log);
    }

    /**
     * Status ändern. $args: by, reason, note, forced, notify (Vorgabe true).
     * Freigabe stellt zurückgehaltene Events wieder her; Ablehnen/Sperren/Warten hält
     * veröffentlichte Events zurück.
     */
    public static function set_status($oid, $new, array $args = []) {
        $oid = intval($oid);
        if (!in_array($new, self::STATUSES, true) || get_post_type($oid) !== 'tix_organizer') return false;
        $old = self::status($oid);
        $by  = intval($args['by'] ?? get_current_user_id());
        $info = get_post_meta($oid, self::META_INFO, true);
        $info = is_array($info) ? $info : [];
        if ($new === 'pending') {
            $info['submitted_at'] = time();
            unset($info['reason']);
        } else {
            $info['decided_at'] = time();
            $info['decided_by'] = $by;
            $info['reason']     = sanitize_textarea_field((string) ($args['reason'] ?? ''));
            $info['forced']     = !empty($args['forced']);
        }
        update_post_meta($oid, self::META_INFO, $info);
        update_post_meta($oid, self::META_STATUS, $new);
        self::$pending_count = null;
        $note = trim((string) ($args['note'] ?? '') . ' ' . (string) ($args['reason'] ?? ''));
        self::log($oid, $new, ['by' => $by, 'note' => $note, 'forced' => !empty($args['forced'])]);

        if ($new === 'approved') self::release_events($oid);
        else self::hold_events($oid);
        if (class_exists('TIX_App_Scope')) TIX_App_Scope::flush_public_cache();

        if (($args['notify'] ?? true) && $new !== $old) self::notify_organizer($oid, $new, (string) ($args['reason'] ?? ''));
        do_action('tix_org_approval_changed', $oid, $new, $old);
        return true;
    }

    /** Events des Veranstalters (Hauptveranstalter), optional nur bestimmte Status. */
    private static function org_events($oid, $statuses) {
        return array_map('intval', get_posts([
            'post_type'        => 'event',
            'post_status'      => $statuses,
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'meta_query'       => [['key' => '_tix_organizer_id', 'value' => strval(intval($oid))]],
        ]));
    }

    /** Veröffentlichte/geplante Events zurückhalten (Status → pending, alter Status gemerkt). */
    public static function hold_events($oid) {
        $n = 0;
        foreach (self::org_events($oid, ['publish', 'future']) as $eid) {
            if (self::is_syndicated($eid)) continue;
            self::hold_event($eid);
            $n++;
        }
        return $n;
    }

    private static function hold_event($eid) {
        $p = get_post($eid);
        if (!$p || !in_array($p->post_status, ['publish', 'future'], true)) return;
        update_post_meta($eid, self::META_HELD, $p->post_status);
        self::$hold[] = ['id' => intval($eid), 'target' => $p->post_status];
        wp_update_post(['ID' => intval($eid), 'post_status' => 'pending']);
    }

    /** Zurückgehaltene Events wieder veröffentlichen. */
    public static function release_events($oid) {
        $n = 0;
        foreach (self::org_events($oid, ['pending']) as $eid) {
            $target = (string) get_post_meta($eid, self::META_HELD, true);
            if (!in_array($target, ['publish', 'future'], true)) continue;
            delete_post_meta($eid, self::META_HELD);
            wp_update_post(['ID' => $eid, 'post_status' => $target]);
            $n++;
        }
        return $n;
    }

    // ──────────────────────────────────────────
    //  Veröffentlichen sperren
    // ──────────────────────────────────────────

    /** Veranstalter eines Events beim Speichern ermitteln (vor dem Schreiben der Meta-Daten). */
    private static function resolve_org($data, $postarr) {
        $pid = intval($postarr['ID'] ?? 0);
        $oid = intval($postarr['meta_input']['_tix_organizer_id'] ?? 0);
        // Event-Editor (Metabox): Feld kommt im selben Request
        if (!$oid && isset($_POST['tix_organizer_id']) && $pid && intval($_POST['post_ID'] ?? 0) === $pid) {
            $oid = intval($_POST['tix_organizer_id']);
        }
        if (!$oid && $pid) $oid = intval(get_post_meta($pid, '_tix_organizer_id', true));
        if (!$oid && class_exists('TIX_App_Scope')) {
            $uid = get_current_user_id();
            if ($uid && !TIX_App_Scope::is_admin()) {
                $p = TIX_App_Scope::organizer_post_for_user($uid);
                if ($p) $oid = intval($p->ID);
            }
            if (!$oid && !empty($data['post_author'])) {
                $p = TIX_App_Scope::organizer_post_for_user(intval($data['post_author']));
                if ($p) $oid = intval($p->ID);
            }
        }
        return $oid;
    }

    public static function filter_event_status($data, $postarr) {
        if (($data['post_type'] ?? '') !== 'event') return $data;
        if (!in_array($data['post_status'] ?? '', ['publish', 'future'], true)) return $data;
        if (!self::multi()) return $data;
        $pid = intval($postarr['ID'] ?? 0);
        if ($pid && self::is_syndicated($pid)) return $data;
        if (!empty($postarr['meta_input']['_tix_syndicated'])) return $data;
        $oid = self::resolve_org($data, $postarr);
        if (!$oid || self::is_public($oid)) return $data;
        self::$hold[] = ['id' => $pid, 'target' => $data['post_status']];
        $data['post_status'] = 'pending';
        return $data;
    }

    public static function on_insert($post_id, $post, $update) {
        if (!$post) return;
        if ($post->post_type === 'event') {
            foreach (self::$hold as $i => $h) {
                if ($h['id'] === intval($post_id) || ($h['id'] === 0 && !$update)) {
                    unset(self::$hold[$i]);
                    if ($post->post_status === 'pending') update_post_meta($post_id, self::META_HELD, $h['target']);
                    return;
                }
            }
            // Bewusst als Entwurf gespeichert oder doch veröffentlicht → Merker entfernen
            if ($post->post_status !== 'pending' && get_post_meta($post_id, self::META_HELD, true) !== '') {
                delete_post_meta($post_id, self::META_HELD);
            }
            return;
        }
        if ($post->post_type === 'tix_organizer' && !$update && self::multi()
            && get_post_meta($post_id, self::META_STATUS, true) === '') {
            // Von Admin/System angelegt → freigegeben; von einem angemeldeten Nicht-Admin → Prüfung
            $self = is_user_logged_in() && !current_user_can('manage_options');
            update_post_meta($post_id, self::META_STATUS, $self ? 'pending' : 'approved');
            if ($self) {
                update_post_meta($post_id, self::META_INFO, ['submitted_at' => time()]);
                self::log($post_id, 'pending', ['note' => 'Selbst angelegt']);
                self::notify_admin_new($post_id);
            }
        }
    }

    /** Veranstalter eines veröffentlichten Events geändert → nach dem Speichern prüfen. */
    public static function on_meta($meta_id, $object_id, $meta_key, $value) {
        if ($meta_key !== '_tix_organizer_id' || !self::multi()) return;
        if (get_post_type($object_id) !== 'event') return;
        self::$queue[intval($object_id)] = true;
    }

    public static function process_queue() {
        if (self::$processing || !self::$queue) return;
        self::$processing = true;
        $ids = array_keys(self::$queue);
        self::$queue = [];
        foreach ($ids as $eid) {
            $p = get_post($eid);
            if (!$p || !in_array($p->post_status, ['publish', 'future'], true)) continue;
            if (!self::event_allowed($eid)) self::hold_event($eid);
        }
        self::$processing = false;
    }

    // ──────────────────────────────────────────
    //  Einführung: Bestand freigeben, Vertrags-Platzhalter
    // ──────────────────────────────────────────

    public static function maybe_migrate() {
        if (!self::multi() || get_option(self::DB_OPTION) === self::DB_VERSION) return;
        $ids = get_posts([
            'post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
        ]);
        foreach ($ids as $oid) {
            if (get_post_meta($oid, self::META_STATUS, true) !== '') continue;
            update_post_meta($oid, self::META_STATUS, 'approved');
            update_post_meta($oid, self::META_INFO, ['decided_at' => time(), 'decided_by' => 0, 'reason' => '', 'forced' => false]);
            self::log($oid, 'approved', ['by' => 0, 'note' => 'Bestand bei Einführung der Freigabe übernommen']);
        }
        $s = self::settings();
        if (!intval($s['terms_page_id']) && (string) $s['terms_url'] === '') {
            $page = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Vermittlungsvertrag für Veranstalter',
                'post_name'    => 'vermittlungsvertrag-veranstalter',
                'post_content' => "<p><strong>Entwurf – Platzhalter.</strong> Der Vermittlungsvertrag (AGB für Veranstalter) wird derzeit abgestimmt und hier veröffentlicht.</p>\n<p>Bis dahin gilt: Die Plattform vermittelt den Ticketverkauf im Namen und auf Rechnung des Veranstalters, kassiert die Ticketerlöse treuhänderisch und zahlt sie nach dem Event abzüglich der vereinbarten Gebühren aus. Details folgen in der endgültigen Fassung; sobald sie vorliegt, bitten wir alle Veranstalter um erneute Zustimmung.</p>",
            ]);
            if ($page && !is_wp_error($page)) {
                $s['terms_page_id'] = intval($page);
                if ((string) $s['terms_version'] === '') $s['terms_version'] = 'Entwurf 2026-10';
                update_option(self::OPTION, $s, false);
            }
        }
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    /** Platzhalter-Vertragsseite nicht indexieren. */
    public static function robots($robots) {
        if (!self::multi() || !is_singular('page')) return $robots;
        $s = self::settings();
        if (intval($s['terms_page_id']) && get_queried_object_id() === intval($s['terms_page_id'])) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    // ──────────────────────────────────────────
    //  Benachrichtigungen
    // ──────────────────────────────────────────

    private static function mail($to, $subject, $heading, $html) {
        if (class_exists('TIX_Payout_Details')) return TIX_Payout_Details::mail($to, $subject, $heading, $html);
        return $to ? wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']) : false;
    }

    private static function org_name($oid) {
        $p = get_post(intval($oid));
        return $p ? html_entity_decode((string) $p->post_title, ENT_QUOTES, 'UTF-8') : '';
    }

    /** Inhaber + Team-Mitglieder mit Rolle tix_organizer. */
    public static function manager_ids($oid) {
        $ids = [];
        $owner = intval(get_post_meta(intval($oid), '_tix_org_user_id', true));
        if ($owner) $ids[] = $owner;
        $team = get_users(['meta_key' => '_tix_team_organizer_id', 'meta_value' => intval($oid), 'role' => 'tix_organizer', 'fields' => 'ID']);
        foreach ($team as $u) $ids[] = intval($u);
        return array_values(array_unique($ids));
    }

    public static function user_is_manager($oid, $uid) {
        $uid = intval($uid);
        if (!$uid) return false;
        return in_array($uid, self::manager_ids($oid), true);
    }

    public static function notify_organizer($oid, $status, $reason = '') {
        $name  = self::org_name($oid);
        $site  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $url   = admin_url('admin.php?page=' . self::ORG_SLUG);
        $why   = $reason !== '' ? '<p><strong>Begründung:</strong><br>' . nl2br(esc_html($reason)) . '</p>' : '';
        $btn   = '<p><a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;background:#111827;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Zum Veranstalter-Bereich</a></p>';
        $m = [
            'registered' => ['Wir prüfen dein Veranstalter-Konto', 'Danke für deine Registrierung',
                '<p>Hallo,</p><p>danke, dass du <strong>' . esc_html($name) . '</strong> bei ' . esc_html($site) . ' angemeldet hast. Wir prüfen dein Konto und melden uns, sobald es freigegeben ist.</p><p>Du kannst dich schon anmelden, dein Profil und deine Events vorbereiten. Damit die Freigabe schnell geht, ergänze bitte Rechnungsadresse, Steuer-Status, Auszahlungsdaten, Telefonnummer und stimme dem Vermittlungsvertrag zu.</p>',
                'Wir prüfen dein Konto', 'Bitte ergänze deine Angaben – wir melden uns nach der Prüfung.'],
            'approved' => ['Dein Veranstalter-Konto ist freigegeben', 'Willkommen auf ' . $site,
                '<p>Hallo,</p><p>gute Nachricht: <strong>' . esc_html($name) . '</strong> ist freigegeben. Deine Events, die du veröffentlichen wolltest, sind jetzt online und der Ticketverkauf ist aktiv.</p>',
                'Dein Konto ist freigegeben', 'Deine Events sind online, der Ticketverkauf ist aktiv.'],
            'rejected' => ['Dein Veranstalter-Konto wurde nicht freigegeben', 'Freigabe abgelehnt',
                '<p>Hallo,</p><p>wir konnten <strong>' . esc_html($name) . '</strong> leider noch nicht freigeben.</p>' . $why . '<p>Du kannst deine Angaben ergänzen und dein Konto im Veranstalter-Bereich erneut zur Prüfung einreichen.</p>',
                'Konto nicht freigegeben', $reason !== '' ? mb_substr($reason, 0, 120) : 'Bitte prüfe die Begründung im Veranstalter-Bereich.'],
            'blocked' => ['Dein Veranstalter-Konto wurde gesperrt', 'Konto gesperrt',
                '<p>Hallo,</p><p><strong>' . esc_html($name) . '</strong> wurde gesperrt. Deine Events sind offline und es werden keine Tickets mehr verkauft. Bereits verkaufte Tickets bleiben gültig, laufende Abrechnungen werden wie gewohnt erstellt und ausgezahlt.</p>' . $why . '<p>Bei Fragen antworte einfach auf diese E-Mail.</p>',
                'Konto gesperrt', 'Deine Events sind offline. Details findest du im Veranstalter-Bereich.'],
            'pending' => ['Dein Veranstalter-Konto wird erneut geprüft', 'Erneut zur Prüfung eingereicht',
                '<p>Hallo,</p><p>wir prüfen <strong>' . esc_html($name) . '</strong> erneut und melden uns, sobald wir entschieden haben.</p>',
                'Konto wird geprüft', 'Wir melden uns nach der Prüfung.'],
        ];
        if (!isset($m[$status])) return;
        list($subject, $heading, $body, $ftitle, $fbody) = $m[$status];
        $to = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::owner_email($oid) : '';
        if ($to) self::mail($to, $subject, $heading, $body . $btn);
        if (class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
            foreach (self::manager_ids($oid) as $uid) {
                TIX_Notifications::add_user_item($uid, $ftitle, $fbody, ['type' => 'organizer', 'action' => 'organizer:approval']);
            }
        }
    }

    /** Admin: neue Registrierung bzw. erneut eingereicht (Mail + optional Feed/Push an Admins). */
    public static function notify_admin_new($oid, $resubmitted = false) {
        $name = self::org_name($oid);
        $owner = intval(get_post_meta($oid, '_tix_org_user_id', true));
        $u = $owner ? get_user_by('id', $owner) : null;
        $url = admin_url('admin.php?page=' . self::ADMIN_SLUG . '&id=' . intval($oid));
        $subject = ($resubmitted ? 'Erneut eingereicht: ' : 'Neuer Veranstalter wartet auf Freigabe: ') . $name;
        $body = '<p><strong>' . esc_html($name) . '</strong>' . ($resubmitted ? ' wurde erneut zur Prüfung eingereicht.' : ' hat sich registriert und wartet auf Freigabe.') . '</p>'
              . ($u ? '<p>Inhaber: ' . esc_html($u->display_name) . ' &lt;' . esc_html($u->user_email) . '&gt;</p>' : '')
              . '<p><a href="' . esc_url($url) . '">Zur Prüfliste</a></p>';
        self::mail(self::admin_email(), $subject, 'Veranstalter-Prüfung', $body);
        if (!intval(self::settings()['push_admins']) || !class_exists('TIX_Notifications') || !method_exists('TIX_Notifications', 'add_user_item')) return;
        foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $aid) {
            TIX_Notifications::add_user_item(intval($aid), $resubmitted ? 'Veranstalter erneut eingereicht' : 'Neuer Veranstalter wartet auf Freigabe', $name . ' – bitte im Web-Admin prüfen.', ['type' => 'admin']);
        }
    }

    // ──────────────────────────────────────────
    //  REST (App, Veranstalter-Bereich)
    // ──────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::NS, '/organizer/approval', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => [__CLASS__, 'perm_member'],
        ]);
        register_rest_route(self::NS, '/organizer/approval/terms', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_terms'], 'permission_callback' => [__CLASS__, 'perm_manager'],
        ]);
        register_rest_route(self::NS, '/organizer/approval/resubmit', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_resubmit'], 'permission_callback' => [__CLASS__, 'perm_manager'],
        ]);
    }

    private static function user_org_id($uid = 0) {
        $uid = $uid ?: get_current_user_id();
        $p = class_exists('TIX_App_Scope') ? TIX_App_Scope::organizer_post_for_user($uid) : null;
        return $p ? intval($p->ID) : 0;
    }

    public static function perm_member(WP_REST_Request $req) {
        if (!self::multi()) return new WP_Error('tix_not_multi', 'Nur auf Plattformen mit mehreren Veranstaltern verfügbar.', ['status' => 403]);
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (!self::user_org_id()) return new WP_Error('no_organizer', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        return true;
    }

    public static function perm_manager(WP_REST_Request $req) {
        $r = self::perm_member($req);
        if (is_wp_error($r)) return $r;
        if (!self::user_is_manager(self::user_org_id(), get_current_user_id())) {
            return new WP_Error('rest_forbidden', 'Nur der Veranstalter selbst (Inhaber oder Team-Admin) kann das tun.', ['status' => 403]);
        }
        return true;
    }

    public static function rest_get(WP_REST_Request $req) {
        return rest_ensure_response(self::payload(self::user_org_id()));
    }

    /** POST /organizer/approval/terms {version} – Zustimmung zur aktuellen Vertragsversion. */
    public static function rest_terms(WP_REST_Request $req) {
        $oid = self::user_org_id();
        $t = self::terms();
        if ($t['version'] === '') return new WP_Error('tix_no_terms', 'Es ist kein Vertrag hinterlegt.', ['status' => 409]);
        $v = (string) ($req->get_param('version') ?? '');
        if ($v !== $t['version']) {
            return new WP_Error('tix_terms_outdated', 'Der Vertrag wurde aktualisiert. Bitte lies die aktuelle Fassung.', ['status' => 409, 'terms' => $t]);
        }
        self::accept_terms($oid, get_current_user_id(), $v);
        return rest_ensure_response(self::payload($oid));
    }

    /** POST /organizer/approval/resubmit – abgelehntes Konto erneut einreichen. */
    public static function rest_resubmit(WP_REST_Request $req) {
        $oid = self::user_org_id();
        if (self::status($oid) !== 'rejected') {
            return new WP_Error('tix_not_rejected', 'Nur abgelehnte Konten können erneut eingereicht werden.', ['status' => 409]);
        }
        self::set_status($oid, 'pending', ['note' => 'Erneut eingereicht']);
        self::notify_admin_new($oid, true);
        return rest_ensure_response(self::payload($oid));
    }

    // ──────────────────────────────────────────
    //  Admin-Menü, Hinweise
    // ──────────────────────────────────────────

    private static $pending_count = null;

    public static function count_pending() {
        if (!self::multi()) return 0;
        if (self::$pending_count !== null) return self::$pending_count;
        return self::$pending_count = count(get_posts([
            'post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => [['key' => self::META_STATUS, 'value' => 'pending']],
        ]));
    }

    public static function admin_menu() {
        if (!self::multi()) return;
        if (current_user_can('manage_options')) {
            $n = self::count_pending();
            $title = 'Veranstalter-Prüfung' . ($n ? ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . $n . '</span></span>' : '');
            add_submenu_page('tixomat', 'Veranstalter-Prüfung', $title, 'manage_options', self::ADMIN_SLUG, [__CLASS__, 'render_admin']);
        } else {
            add_submenu_page('tixomat', 'Mein Konto', 'Mein Konto', 'read', self::ORG_SLUG, [__CLASS__, 'render_account']);
        }
    }

    /** Hinweis für Veranstalter (alle Seiten außer „Mein Konto“) und für Admins (wartende Registrierungen). */
    public static function admin_notices() {
        if (!self::multi()) return;
        $page = sanitize_key($_GET['page'] ?? '');
        if (current_user_can('manage_options')) {
            if ($page === self::ADMIN_SLUG) return;
            $n = self::count_pending();
            if ($n) {
                printf('<div class="notice notice-warning"><p><strong>%d Veranstalter %s auf Freigabe.</strong> <a href="%s">Tixomat → Veranstalter-Prüfung</a></p></div>',
                    $n, $n === 1 ? 'wartet' : 'warten', esc_url(admin_url('admin.php?page=' . self::ADMIN_SLUG)));
            }
            return;
        }
        if ($page === self::ORG_SLUG) return;
        $oid = self::user_org_id();
        if (!$oid) return;
        $st = self::status($oid);
        if ($st === 'approved') return;
        $p = self::payload($oid);
        $colors = ['pending' => ['#fffbeb', '#f59e0b', '#92400e'], 'rejected' => ['#fef2f2', '#ef4444', '#991b1b'], 'blocked' => ['#fef2f2', '#ef4444', '#991b1b']];
        $c = $colors[$st];
        $missing = count($p['missing']);
        // Bewusst ohne „notice“ im Klassennamen: die Admin-Shell blendet solche Elemente aus.
        printf('<div class="tix-org-review-banner" style="margin:16px 20px 0 0;padding:14px 18px;border-radius:10px;background:%s;border-left:4px solid %s;color:%s;font-size:14px;line-height:1.5;">'
             . '<strong>%s.</strong> %s%s <a href="%s" style="font-weight:600;color:inherit;">%s →</a></div>',
            $c[0], $c[1], $c[2], esc_html($p['title']), esc_html($p['message']),
            $missing ? ' ' . esc_html(sprintf('Es fehlen noch %d Angabe%s.', $missing, $missing === 1 ? '' : 'n')) : '',
            esc_url(admin_url('admin.php?page=' . self::ORG_SLUG)), esc_html($missing ? 'Angaben ergänzen' : 'Details'));
    }

    // ──────────────────────────────────────────
    //  Veranstalter: „Mein Konto“
    // ──────────────────────────────────────────

    private static function target_url($target) {
        if ($target === 'payout') return admin_url('admin.php?page=tix-organizer-payouts');
        return admin_url('admin.php?page=' . self::ORG_SLUG . '#tix-acc-' . $target);
    }

    public static function render_account() {
        $oid = self::user_org_id();
        echo '<div class="wrap"><h1>Mein Konto</h1>';
        if (!self::multi() || !$oid) {
            echo '<p>Dieses Konto ist keinem Veranstalter zugeordnet.</p></div>';
            return;
        }
        $p = self::payload($oid);
        $manager = self::user_is_manager($oid, get_current_user_id());
        $msg = sanitize_text_field(wp_unslash($_GET['msg'] ?? ''));
        $err = sanitize_text_field(wp_unslash($_GET['err'] ?? ''));
        $box = 'background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin:0 0 18px;max-width:820px;';
        $tone = ['pending' => '#f59e0b', 'approved' => '#16a34a', 'rejected' => '#ef4444', 'blocked' => '#ef4444'][$p['status']];
        $nonce = wp_nonce_field('tix_org_account', '_wpnonce', true, false);
        $action = esc_url(admin_url('admin-post.php'));
        if ($msg) echo '<div class="tix-org-review-banner" style="' . $box . 'border-left:4px solid #16a34a;">' . esc_html($msg) . '</div>';
        if ($err) echo '<div class="tix-org-review-banner" style="' . $box . 'border-left:4px solid #ef4444;">' . esc_html($err) . '</div>';
        ?>
        <div style="<?php echo $box; ?>border-left:4px solid <?php echo esc_attr($tone); ?>;">
            <div style="font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:<?php echo esc_attr($tone); ?>;"><?php echo esc_html($p['label']); ?></div>
            <h2 style="margin:6px 0 8px;"><?php echo esc_html($p['title']); ?></h2>
            <p style="margin:0;color:#374151;"><?php echo esc_html($p['message']); ?></p>
            <?php if ($p['reason'] !== '') : ?><p style="margin:12px 0 0;"><strong>Begründung:</strong><br><?php echo nl2br(esc_html($p['reason'])); ?></p><?php endif; ?>
            <?php if ($p['can_resubmit']) : ?>
                <form method="post" action="<?php echo $action; ?>" style="margin-top:14px;"><?php echo $nonce; ?>
                    <input type="hidden" name="action" value="tix_org_account"><input type="hidden" name="do" value="resubmit">
                    <button class="button button-primary">Erneut zur Prüfung einreichen</button>
                </form>
            <?php endif; ?>
        </div>

        <div style="<?php echo $box; ?>">
            <h2 style="margin-top:0;">Pflichtangaben</h2>
            <p style="color:#6b7280;margin-top:0;">Diese Angaben brauchen wir vor dem ersten Verkauf und der ersten Auszahlung.</p>
            <ul style="margin:0;padding:0;list-style:none;">
            <?php foreach ($p['requirements'] as $r) : ?>
                <li style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-top:1px solid #f3f4f6;">
                    <span style="font-size:16px;line-height:1.3;color:<?php echo $r['done'] ? '#16a34a' : '#ef4444'; ?>;"><?php echo $r['done'] ? '✓' : '✗'; ?></span>
                    <span style="flex:1;"><strong><?php echo esc_html($r['label']); ?></strong><br><small style="color:#6b7280;"><?php echo esc_html($r['hint']); ?></small></span>
                    <?php if (!$r['done'] && $manager) : ?><a class="button" href="<?php echo esc_url(self::target_url($r['target'])); ?>">Ergänzen</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($manager) :
            $phone = (string) get_post_meta($oid, '_tix_org_phone', true);
            $email = (string) get_post_meta($oid, '_tix_org_email', true);
        ?>
        <div id="tix-acc-profile" style="<?php echo $box; ?>">
            <h2 style="margin-top:0;">Kontakt</h2>
            <form method="post" action="<?php echo $action; ?>"><?php echo $nonce; ?>
                <input type="hidden" name="action" value="tix_org_account"><input type="hidden" name="do" value="contact">
                <p><label>Telefon *<br><input type="tel" name="phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" required></label></p>
                <p><label>Öffentliche Kontakt-E-Mail<br><input type="email" name="email" value="<?php echo esc_attr($email); ?>" class="regular-text"></label></p>
                <button class="button button-primary">Speichern</button>
            </form>
        </div>

        <?php if ($p['terms']['required']) : ?>
        <div id="tix-acc-terms" style="<?php echo $box; ?>">
            <h2 style="margin-top:0;"><?php echo esc_html($p['terms']['title']); ?></h2>
            <?php if ($p['terms']['accepted']) : ?>
                <p>✓ Du hast der Version <strong><?php echo esc_html($p['terms']['version']); ?></strong> am <?php echo esc_html(wp_date('d.m.Y H:i', strtotime($p['terms']['accepted_at']))); ?> zugestimmt.
                <?php if ($p['terms']['url']) : ?><a href="<?php echo esc_url($p['terms']['url']); ?>" target="_blank" rel="noopener">Vertrag ansehen</a><?php endif; ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo $action; ?>"><?php echo $nonce; ?>
                    <input type="hidden" name="action" value="tix_org_account"><input type="hidden" name="do" value="terms">
                    <input type="hidden" name="version" value="<?php echo esc_attr($p['terms']['version']); ?>">
                    <p><label><input type="checkbox" name="accept" value="1" required> Ich habe den
                        <?php if ($p['terms']['url']) : ?><a href="<?php echo esc_url($p['terms']['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($p['terms']['title']); ?></a><?php else : echo esc_html($p['terms']['title']); endif; ?>
                        (Version <?php echo esc_html($p['terms']['version']); ?>) gelesen und stimme ihm zu.</label></p>
                    <button class="button button-primary">Zustimmen</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_account() {
        check_admin_referer('tix_org_account');
        $back = function (array $a) { wp_safe_redirect(add_query_arg($a, admin_url('admin.php?page=' . self::ORG_SLUG))); exit; };
        $oid = self::user_org_id();
        if (!self::multi() || !$oid || !self::user_is_manager($oid, get_current_user_id())) $back(['err' => 'Kein Zugriff.']);
        $do = sanitize_key($_POST['do'] ?? '');
        if ($do === 'contact') {
            $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            if ($phone === '') $back(['err' => 'Bitte eine Telefonnummer angeben.']);
            if (!empty($_POST['email']) && !is_email($email)) $back(['err' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
            update_post_meta($oid, '_tix_org_phone', $phone);
            update_post_meta($oid, '_tix_org_email', $email);
            $back(['msg' => 'Kontakt gespeichert.']);
        }
        if ($do === 'terms') {
            if (empty($_POST['accept'])) $back(['err' => 'Bitte bestätige die Zustimmung.']);
            $ok = self::accept_terms($oid, get_current_user_id(), sanitize_text_field(wp_unslash($_POST['version'] ?? '')));
            $back($ok ? ['msg' => 'Danke – deine Zustimmung ist gespeichert.'] : ['err' => 'Der Vertrag wurde inzwischen aktualisiert. Bitte lies die aktuelle Fassung.']);
        }
        if ($do === 'resubmit') {
            if (self::status($oid) !== 'rejected') $back(['err' => 'Nur abgelehnte Konten können erneut eingereicht werden.']);
            self::set_status($oid, 'pending', ['note' => 'Erneut eingereicht']);
            self::notify_admin_new($oid, true);
            $back(['msg' => 'Dein Konto wurde erneut zur Prüfung eingereicht.']);
        }
        $back([]);
    }

    // ──────────────────────────────────────────
    //  Admin: Prüfliste
    // ──────────────────────────────────────────

    private static function admin_url_args(array $a = []) {
        return add_query_arg($a, admin_url('admin.php?page=' . self::ADMIN_SLUG));
    }

    private static function badge($st) {
        $c = ['pending' => '#d97706', 'approved' => '#16a34a', 'rejected' => '#dc2626', 'blocked' => '#7f1d1d'][$st] ?? '#6b7280';
        return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;background:' . $c . ';">' . esc_html(self::label($st)) . '</span>';
    }

    private static function checks_html($oid) {
        $short = ['name' => 'Name', 'address' => 'Adresse', 'contact' => 'Telefon', 'tax' => 'Steuer', 'payout' => 'IBAN', 'terms' => 'Vertrag'];
        $out = '';
        foreach (self::requirements($oid) as $r) {
            $out .= '<span title="' . esc_attr($r['label'] . ($r['done'] ? ' ✓' : ' fehlt')) . '" style="display:inline-block;margin:0 6px 2px 0;white-space:nowrap;font-weight:700;color:' . ($r['done'] ? '#16a34a' : '#dc2626') . ';">'
                  . ($r['done'] ? '✓' : '✗') . '<small style="font-weight:400;color:#6b7280;margin-left:2px;">' . esc_html($short[$r['key']] ?? $r['key']) . '</small></span>';
        }
        return $out;
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        if (!self::multi()) {
            echo '<div class="wrap"><h1>Veranstalter-Prüfung</h1><p>Nur im Mehr-Veranstalter-Modus verfügbar.</p></div>';
            return;
        }
        $tab = sanitize_key($_GET['tab'] ?? 'pending');
        $msg = sanitize_text_field(wp_unslash($_GET['msg'] ?? ''));
        $err = sanitize_text_field(wp_unslash($_GET['err'] ?? ''));
        echo '<div class="wrap"><h1 class="wp-heading-inline">Veranstalter-Prüfung</h1><hr class="wp-header-end">';
        if ($msg) echo '<div class="tix-org-review-banner" style="padding:12px 16px;margin:12px 0;background:#ecfdf5;border-left:4px solid #16a34a;border-radius:8px;">' . esc_html($msg) . '</div>';
        if ($err) echo '<div class="tix-org-review-banner" style="padding:12px 16px;margin:12px 0;background:#fef2f2;border-left:4px solid #dc2626;border-radius:8px;">' . esc_html($err) . '</div>';
        echo '<h2 class="nav-tab-wrapper">';
        $n = self::count_pending();
        foreach (['pending' => 'Wartet' . ($n ? " ($n)" : ''), 'rejected' => 'Abgelehnt', 'blocked' => 'Gesperrt', 'approved' => 'Freigegeben', 'all' => 'Alle', 'settings' => 'Einstellungen'] as $k => $l) {
            printf('<a class="nav-tab %s" href="%s">%s</a>', $tab === $k && empty($_GET['id']) ? 'nav-tab-active' : '', esc_url(self::admin_url_args(['tab' => $k])), esc_html($l));
        }
        echo '</h2>';
        if (!empty($_GET['id'])) self::render_detail(intval($_GET['id']));
        elseif ($tab === 'settings') self::render_settings();
        else self::render_list($tab);
        echo '</div>';
    }

    private static function render_list($tab) {
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'orderby' => 'ID', 'order' => 'DESC']);
        $rows = [];
        foreach ($ids as $oid) {
            $st = self::status($oid);
            if ($tab !== 'all' && $st !== $tab) continue;
            $rows[] = $oid;
        }
        if (!$rows) {
            echo '<p style="margin-top:20px;">' . ($tab === 'pending' ? 'Keine Veranstalter warten auf Freigabe.' : 'Keine Einträge.') . '</p>';
            return;
        }
        echo '<table class="widefat striped" style="margin-top:16px;"><thead><tr><th>Veranstalter</th><th>Inhaber</th><th>Eingereicht</th><th>Events</th><th>Pflichtangaben</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $oid) {
            $owner = intval(get_post_meta($oid, '_tix_org_user_id', true));
            $u = $owner ? get_user_by('id', $owner) : null;
            $info = get_post_meta($oid, self::META_INFO, true);
            $sub = is_array($info) && !empty($info['submitted_at']) ? wp_date('d.m.Y H:i', intval($info['submitted_at'])) : '—';
            $online = count(self::org_events($oid, ['publish', 'future']));
            $held = 0;
            foreach (self::org_events($oid, ['pending']) as $eid) { if (get_post_meta($eid, self::META_HELD, true) !== '') $held++; }
            $all = count(self::org_events($oid, ['publish', 'future', 'pending', 'draft', 'private']));
            printf('<tr><td><strong><a href="%s">%s</a></strong></td><td>%s</td><td>%s</td><td>%d gesamt · %d online%s</td><td>%s</td><td>%s</td><td><a class="button" href="%s">Prüfen</a></td></tr>',
                esc_url(self::admin_url_args(['id' => $oid])), esc_html(self::org_name($oid)),
                $u ? esc_html(trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name) . '<br><small>' . esc_html($u->user_email) . '</small>' : '—',
                esc_html($sub), $all, $online, $held ? ' · ' . $held . ' zurückgehalten' : '',
                self::checks_html($oid), self::badge(self::status($oid)), esc_url(self::admin_url_args(['id' => $oid])));
        }
        echo '</tbody></table>';
    }

    private static function render_detail($oid) {
        $p = get_post($oid);
        if (!$p || $p->post_type !== 'tix_organizer') { echo '<p>Veranstalter nicht gefunden.</p>'; return; }
        $st = self::status($oid);
        $pl = self::payload($oid);
        $pd = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::payload($oid) : [];
        $b = is_array($pd['billing'] ?? null) ? $pd['billing'] : [];
        $owner = intval(get_post_meta($oid, '_tix_org_user_id', true));
        $u = $owner ? get_user_by('id', $owner) : null;
        $g = function ($k) use ($oid) { return (string) get_post_meta($oid, $k, true); };
        $acc = get_post_meta($oid, self::META_TERMS, true);
        $acc = is_array($acc) ? $acc : [];
        $box = 'background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin:16px 0;';
        $tax = ['vat_id' => 'USt-IdNr. ' . ($pd['vat_id'] ?? ''), 'tax_number' => 'Steuernummer ' . ($pd['tax_number'] ?? ''), 'small_business' => 'Kleinunternehmer (§ 19 UStG)'][$pd['tax_status'] ?? ''] ?? '—';
        $row = function ($l, $v) { return '<tr><th style="width:200px;text-align:left;padding:4px 12px 4px 0;vertical-align:top;color:#6b7280;font-weight:500;">' . esc_html($l) . '</th><td style="padding:4px 0;">' . $v . '</td></tr>'; };
        $nonce = function ($do) use ($oid) { return wp_nonce_field('tix_org_review_' . $do . '_' . $oid, '_wpnonce', true, false); };
        $form_head = function ($do) use ($oid, $nonce) {
            return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 14px;">' . $nonce($do)
                 . '<input type="hidden" name="action" value="tix_org_review"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="id" value="' . intval($oid) . '">';
        };
        ?>
        <p style="margin-top:16px;"><a href="<?php echo esc_url(self::admin_url_args(['tab' => $st])); ?>">← Zurück zur Liste</a></p>
        <h2 style="display:flex;gap:12px;align-items:center;"><?php echo esc_html(self::org_name($oid)); ?> <?php echo self::badge($st); ?>
            <a class="button" href="<?php echo esc_url(get_edit_post_link($oid)); ?>">Veranstalter bearbeiten</a></h2>
        <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:20px;align-items:start;max-width:1200px;">
        <div>
            <div style="<?php echo $box; ?>"><h3 style="margin-top:0;">Angaben</h3><table>
                <?php
                echo $row('Inhaber', $u ? esc_html(trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name) . ' &lt;' . esc_html($u->user_email) . '&gt;<br><small>Konto seit ' . esc_html(wp_date('d.m.Y', strtotime($u->user_registered . ' UTC'))) . '</small>' : '—');
                echo $row('Kontakt', esc_html(trim($g('_tix_org_email') . ' · ' . $g('_tix_org_phone'), ' ·')) ?: '—');
                echo $row('Website', $g('_tix_org_website') ? '<a href="' . esc_url($g('_tix_org_website')) . '" target="_blank" rel="noopener">' . esc_html($g('_tix_org_website')) . '</a>' : '—');
                echo $row('Ort (Profil)', esc_html(trim($g('_tix_org_address') . ' ' . $g('_tix_org_city'))) ?: '—');
                $addr = array_filter([$b['company'] ?? '', $b['name'] ?? '', $b['street'] ?? '', trim(($b['zip'] ?? '') . ' ' . ($b['city'] ?? ''))]);
                echo $row('Rechnungsadresse', $addr ? esc_html(implode(', ', $addr) . ', ' . ($b['country'] ?? '')) : '—');
                echo $row('Steuer', esc_html($tax));
                echo $row('Auszahlung', esc_html(($pd['holder'] ?? '') ?: '—') . ($pd['has_iban'] ?? false ? '<br><code>' . esc_html($pd['iban_masked']) . '</code>' . (($pd['bic'] ?? '') ? ' · ' . esc_html($pd['bic']) : '') : '<br><em>keine IBAN</em>') . (!empty($pd['pending_iban']) ? '<br><small>IBAN-Änderung wartet auf Code</small>' : ''));
                echo $row('Vertrag', !empty($acc['version']) ? 'Version ' . esc_html($acc['version']) . ' am ' . esc_html(wp_date('d.m.Y H:i', intval($acc['accepted_at'] ?? 0))) . (!empty($acc['ip']) ? ' <small>(IP ' . esc_html($acc['ip']) . ')</small>' : '') . ($pl['terms']['accepted'] ? '' : ' – <strong>veraltet</strong>, aktuell: ' . esc_html($pl['terms']['version'])) : '—');
                ?>
            </table></div>

            <div style="<?php echo $box; ?>"><h3 style="margin-top:0;">Pflichtangaben</h3>
                <?php foreach ($pl['requirements'] as $r) : ?>
                    <div style="padding:4px 0;"><span style="font-weight:700;color:<?php echo $r['done'] ? '#16a34a' : '#dc2626'; ?>;"><?php echo $r['done'] ? '✓' : '✗'; ?></span> <?php echo esc_html($r['label']); ?> <small style="color:#6b7280;">– <?php echo esc_html($r['hint']); ?></small></div>
                <?php endforeach; ?>
            </div>

            <div style="<?php echo $box; ?>"><h3 style="margin-top:0;">Events</h3>
                <?php $evs = self::org_events($oid, ['publish', 'future', 'pending', 'draft', 'private']);
                if (!$evs) echo '<p>Noch keine Events.</p>';
                else {
                    echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Datum</th><th>Status</th></tr></thead><tbody>';
                    foreach (array_slice($evs, 0, 50) as $eid) {
                        $held = (string) get_post_meta($eid, self::META_HELD, true);
                        $ps = get_post_status($eid);
                        $label = ['publish' => 'Online', 'future' => 'Geplant', 'draft' => 'Entwurf', 'private' => 'Privat', 'pending' => 'Ausstehend'][$ps] ?? $ps;
                        if ($held !== '') $label = 'Zurückgehalten (soll ' . ($held === 'future' ? 'geplant' : 'online') . ')';
                        printf('<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>', esc_url(get_edit_post_link($eid)), esc_html(get_post_field('post_title', $eid)),
                            esc_html(($d = (string) get_post_meta($eid, '_tix_date_start', true)) ? mysql2date('d.m.Y', $d) : '—'), esc_html($label));
                    }
                    echo '</tbody></table>';
                } ?>
            </div>

            <div style="<?php echo $box; ?>"><h3 style="margin-top:0;">Verlauf</h3>
                <?php $log = get_post_meta($oid, self::META_LOG, true);
                $log = is_array($log) ? array_reverse($log) : [];
                if (!$log) echo '<p>—</p>';
                foreach ($log as $l) {
                    $by = intval($l['by'] ?? 0);
                    $who = $by ? (($x = get_user_by('id', $by)) ? $x->display_name : '#' . $by) : 'System';
                    $what = ['terms' => 'Vertrag'] + self::labels();
                    printf('<div style="padding:4px 0;border-top:1px solid #f3f4f6;"><small style="color:#6b7280;">%s · %s</small><br><strong>%s</strong>%s%s</div>',
                        esc_html(wp_date('d.m.Y H:i', intval($l['at'] ?? 0))), esc_html($who), esc_html($what[$l['action'] ?? ''] ?? ($l['action'] ?? '')),
                        !empty($l['forced']) ? ' <em>(trotz fehlender Angaben)</em>' : '', ($l['note'] ?? '') !== '' ? ' – ' . esc_html($l['note']) : '');
                } ?>
            </div>
        </div>

        <div style="<?php echo $box; ?>margin-top:16px;">
            <h3 style="margin-top:0;">Entscheidung</h3>
            <?php if ($st !== 'approved') :
                echo $form_head('approve'); ?>
                <?php if (!$pl['complete']) : ?>
                    <p style="color:#991b1b;margin-top:0;">Es fehlen Pflichtangaben: <?php echo esc_html(implode(', ', array_map(function ($r) { return $r['label']; }, array_filter($pl['requirements'], function ($r) { return !$r['done']; })))); ?>.</p>
                    <p><label><input type="checkbox" name="force" value="1"> Ich gebe trotz fehlender Pflichtangaben frei.</label></p>
                <?php endif; ?>
                <?php if (class_exists('TIX_Organizer_Landing') && TIX_Organizer_Landing::is_feature_enabled() && !get_post_meta($oid, '_tix_org_landing_approved', true)) : ?>
                    <p><label><input type="checkbox" name="landing" value="1" checked> Landingpage ebenfalls freischalten</label></p>
                <?php endif; ?>
                <button class="button button-primary"><?php echo $st === 'blocked' ? 'Entsperren &amp; freigeben' : 'Freischalten'; ?></button></form>
            <?php endif; ?>
            <?php if ($st === 'pending') :
                echo $form_head('reject'); ?>
                <p style="margin:0 0 6px;"><label>Begründung für die Ablehnung (geht an den Veranstalter) *<br>
                    <textarea name="reason" rows="3" style="width:100%;" required></textarea></label></p>
                <button class="button">Ablehnen</button></form>
            <?php endif; ?>
            <?php if ($st !== 'blocked') :
                echo $form_head('block'); ?>
                <p style="margin:0 0 6px;"><label>Begründung für die Sperre (geht an den Veranstalter) *<br>
                    <textarea name="reason" rows="3" style="width:100%;" required></textarea></label></p>
                <button class="button" style="color:#b91c1c;border-color:#b91c1c;" onclick="return confirm('Veranstalter sperren? Alle Events gehen offline, der Verkauf stoppt.');">Sperren</button></form>
            <?php endif; ?>
            <p style="color:#6b7280;font-size:12px;margin-bottom:0;">Jede Entscheidung schickt eine E-Mail an den Inhaber und einen Hinweis in die App. Gesperrt: Events offline, kein Verkauf; verkaufte Tickets und laufende Abrechnungen bleiben erhalten.</p>
        </div>
        </div>
        <?php
    }

    public static function handle_review() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        $oid = intval($_POST['id'] ?? 0);
        $do  = sanitize_key($_POST['do'] ?? '');
        check_admin_referer('tix_org_review_' . $do . '_' . $oid);
        $back = function (array $a) use ($oid) { wp_safe_redirect(self::admin_url_args(['id' => $oid] + $a)); exit; };
        if (get_post_type($oid) !== 'tix_organizer') $back(['err' => 'Veranstalter nicht gefunden.']);
        $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
        if ($do === 'approve') {
            $complete = !self::missing($oid);
            if (!$complete && empty($_POST['force'])) $back(['err' => 'Es fehlen Pflichtangaben. Zum Freigeben bitte „trotz fehlender Pflichtangaben“ bestätigen.']);
            if (!empty($_POST['landing'])) update_post_meta($oid, '_tix_org_landing_approved', 1);
            self::set_status($oid, 'approved', ['forced' => !$complete]);
            $back(['msg' => 'Freigeschaltet – zurückgehaltene Events sind online, der Veranstalter wurde benachrichtigt.']);
        }
        if ($do === 'reject' || $do === 'block') {
            if ($reason === '') $back(['err' => 'Bitte eine Begründung angeben.']);
            self::set_status($oid, $do === 'reject' ? 'rejected' : 'blocked', ['reason' => $reason]);
            $back(['msg' => $do === 'reject' ? 'Abgelehnt – der Veranstalter wurde benachrichtigt.' : 'Gesperrt – Events sind offline, der Veranstalter wurde benachrichtigt.']);
        }
        $back([]);
    }

    private static function render_settings() {
        $s = self::settings();
        $t = self::terms();
        $pages = get_pages(['post_status' => 'publish,draft,private', 'sort_column' => 'post_title']);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:820px;margin-top:16px;">
            <?php wp_nonce_field('tix_org_review_settings'); ?>
            <input type="hidden" name="action" value="tix_org_review_settings">
            <table class="form-table">
                <tr><th>Vertrag: Titel</th><td><input type="text" name="terms_title" value="<?php echo esc_attr($s['terms_title']); ?>" class="regular-text"></td></tr>
                <tr><th>Vertrag: Seite</th><td><select name="terms_page_id"><option value="0">— keine —</option>
                    <?php foreach ($pages as $pg) printf('<option value="%d" %s>%s (%s)</option>', $pg->ID, selected(intval($s['terms_page_id']), $pg->ID, false), esc_html($pg->post_title), esc_html($pg->post_status)); ?>
                    </select><p class="description">Oder externer Link:</p><input type="url" name="terms_url" value="<?php echo esc_attr($s['terms_url']); ?>" class="regular-text" placeholder="https://…">
                    <p class="description">Aktuell: <?php echo $t['url'] ? '<a href="' . esc_url($t['url']) . '" target="_blank">' . esc_html($t['url']) . '</a>' : '—'; ?></p></td></tr>
                <tr><th>Vertrag: Version</th><td><input type="text" name="terms_version" value="<?php echo esc_attr($s['terms_version']); ?>" class="regular-text">
                    <p class="description">Neue Version eintragen, sobald sich der Vertrag ändert: Alle Veranstalter müssen dann erneut zustimmen (in der Prüfliste als fehlend sichtbar; der Verkauf freigegebener Veranstalter läuft weiter). Leer = keine Zustimmung nötig.</p></td></tr>
                <tr><th>Benachrichtigung an</th><td><input type="email" name="notify_email" value="<?php echo esc_attr($s['notify_email']); ?>" class="regular-text" placeholder="<?php echo esc_attr(self::admin_email()); ?>">
                    <p class="description">E-Mail bei neuer Registrierung. Leer = Rechnungs-E-Mail bzw. Admin-Adresse.</p></td></tr>
                <tr><th>Feed/Push an Admins</th><td><label><input type="checkbox" name="push_admins" value="1" <?php checked(intval($s['push_admins'])); ?>> Admin-Konten bekommen bei neuer Registrierung einen Hinweis in der App</label></td></tr>
            </table>
            <?php submit_button('Speichern'); ?>
        </form>
        <?php
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_org_review_settings');
        $s = self::settings();
        $s['terms_title']   = sanitize_text_field(wp_unslash($_POST['terms_title'] ?? '')) ?: 'Vermittlungsvertrag für Veranstalter';
        $s['terms_page_id'] = intval($_POST['terms_page_id'] ?? 0);
        $s['terms_url']     = esc_url_raw(wp_unslash($_POST['terms_url'] ?? ''));
        $s['terms_version'] = sanitize_text_field(wp_unslash($_POST['terms_version'] ?? ''));
        $s['notify_email']  = sanitize_email(wp_unslash($_POST['notify_email'] ?? ''));
        $s['push_admins']   = empty($_POST['push_admins']) ? 0 : 1;
        update_option(self::OPTION, $s, false);
        wp_safe_redirect(self::admin_url_args(['tab' => 'settings', 'msg' => 'Einstellungen gespeichert.']));
        exit;
    }
}
