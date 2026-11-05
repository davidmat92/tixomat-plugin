<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Promoter_Notify – Mitteilungen an Promoter per Mail (Promoter-Ausbau Paket 2, 1.38.662).
 *
 * Arten (je Promoter abschaltbar, Vorgabe an; POST /promoter/me {notify{…}}):
 *   sale    Verkauf über den Link bzw. Code – gesammelt (eine Mail je 15 min), nie Käuferdaten
 *   payout  Auszahlung vorbereitet bzw. überwiesen
 *   event   neues Event zugeteilt bzw. neu veröffentlicht (Promoter mit „Alle Events“ bzw. des Veranstalters)
 *   weekly  Wochenbericht montags (Klicks, Verkäufe, Provision, auszahlbar) – nur mit Aktivität oder Guthaben
 * Nur aktive Promoter mit E-Mail, nur bei eingeschaltetem Promoter-Programm. Knopf führt in den Promoter-Bereich im Studio.
 * Push in der App gibt es für Promoter (noch) nicht – sie haben kein App-Konto.
 */
class TIX_Promoter_Notify {

    const TYPES = ['sale' => 'Verkäufe', 'payout' => 'Auszahlungen', 'event' => 'Neue Events', 'weekly' => 'Wochenbericht'];
    const OPT_Q = 'tix_promoter_notify_q';
    const CRON_FLUSH = 'tix_promoter_notify_flush';
    const CRON_WEEKLY = 'tix_promoter_weekly';
    const DELAY = 15 * MINUTE_IN_SECONDS;

    public static function init() {
        add_action('tix_promoter_commission', [__CLASS__, 'on_commission'], 10, 2);
        add_action('tix_promoter_payout_created', function ($id) { self::on_payout($id, 'created'); });
        add_action('tix_promoter_payout_paid', function ($id) { self::on_payout($id, 'paid'); });
        add_action('tix_promoter_event_assigned', [__CLASS__, 'on_assigned'], 10, 2);
        add_action('transition_post_status', [__CLASS__, 'on_publish'], 20, 3);
        add_action(self::CRON_FLUSH, [__CLASS__, 'flush']);
        add_action(self::CRON_WEEKLY, [__CLASS__, 'weekly']);
        add_action('init', function () {
            if (!wp_next_scheduled(self::CRON_WEEKLY)) {
                $next = new DateTime('next monday 09:00', wp_timezone());
                wp_schedule_event($next->getTimestamp(), 'weekly', self::CRON_WEEKLY);
            }
        }, 53);
    }

    private static function on() {
        $s = get_option('tix_settings', []);
        return is_array($s) && !empty($s['promoter_enabled']) && class_exists('TIX_Promoter_DB') && class_exists('TIX_Mail_Layout');
    }

    // ═══════════════ Einstellungen je Promoter ═══════════════

    public static function ensure_column() {
        static $done = false;
        if ($done) return;
        $done = true;
        global $wpdb;
        $t = TIX_Promoter_DB::table_promoters();
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return;
        if (!$wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM $t LIKE %s", 'notify'))) $wpdb->query("ALTER TABLE $t ADD COLUMN notify VARCHAR(255) NOT NULL DEFAULT ''");
    }

    public static function prefs($pid) {
        global $wpdb;
        self::ensure_column();
        $raw = (string) $wpdb->get_var($wpdb->prepare("SELECT notify FROM " . TIX_Promoter_DB::table_promoters() . " WHERE id = %d", intval($pid)));
        $j = $raw !== '' ? json_decode($raw, true) : [];
        $out = [];
        foreach (array_keys(self::TYPES) as $k) $out[$k] = !is_array($j) || !array_key_exists($k, $j) || !empty($j[$k]);
        return $out;
    }

    public static function save_prefs($pid, array $in) {
        global $wpdb;
        $cur = self::prefs($pid);
        foreach (array_keys(self::TYPES) as $k) if (array_key_exists($k, $in)) $cur[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
        $wpdb->update(TIX_Promoter_DB::table_promoters(), ['notify' => wp_json_encode($cur)], ['id' => intval($pid)]);
        return $cur;
    }

    private static function wants($p, $type) {
        if (!$p || $p->status !== 'active') return false;
        $mail = self::email_of($p);
        return is_email($mail) && !empty(self::prefs(intval($p->id))[$type]);
    }

    private static function email_of($p) {
        $e = (string) ($p->email ?? '');
        if ($e === '' && !empty($p->user_id) && ($u = get_userdata(intval($p->user_id)))) $e = $u->user_email;
        return strtolower($e);
    }

    private static function money($v) { return number_format(floatval($v), 2, ',', '.') . ' €'; }

    private static function title($eid) { return html_entity_decode(get_the_title(intval($eid)), ENT_QUOTES, 'UTF-8'); }

    /** Mail im Layout der Seite mit Knopf in den Promoter-Bereich */
    private static function send($p, $subject, $heading, $html, $path = '') {
        $url = class_exists('TIX_Promoter_Portal') ? TIX_Promoter_Portal::url($path) : home_url('/');
        $blocks = [['type' => 'html', 'html' => '<p>Hallo' . ($p->display_name ? ' ' . esc_html($p->display_name) : '') . ',</p>' . $html . TIX_Mail_Layout::button('Zum Promoter-Bereich', $url)
            . '<p style="font-size:12px;opacity:.7">Diese Mitteilungen kannst du im Promoter-Bereich unter „Profil“ abbestellen.</p>']];
        return TIX_Mail_Layout::send(self::email_of($p), $subject, ['title' => $heading, 'blocks' => $blocks, 'kind' => 'account'], ['customer' => false]);
    }

    // ═══════════════ Verkauf (gesammelt) ═══════════════

    public static function on_commission($cid, array $row) {
        if (!self::on()) return;
        $q = (array) get_option(self::OPT_Q, []);
        $q[] = ['p' => intval($row['promoter_id']), 'e' => intval($row['event_id']), 't' => intval($row['tickets_qty']), 'c' => round(floatval($row['commission_amount']), 2), 'at' => time()];
        update_option(self::OPT_Q, array_slice($q, -2000), false);
        if (!wp_next_scheduled(self::CRON_FLUSH)) wp_schedule_single_event(time() + self::DELAY, self::CRON_FLUSH);
    }

    /** Gesammelte Verkäufe senden: je Promoter eine Mail (Events, Tickets, Provision) */
    public static function flush() {
        $q = (array) get_option(self::OPT_Q, []);
        update_option(self::OPT_Q, [], false);
        if (!$q || !self::on()) return 0;
        $by = [];
        foreach ($q as $e) if (is_array($e) && !empty($e['p'])) $by[intval($e['p'])][] = $e;
        $sent = 0;
        foreach ($by as $pid => $list) {
            $p = TIX_Promoter_DB::get_promoter($pid);
            if (!self::wants($p, 'sale')) continue;
            $ev = []; $tickets = 0; $sum = 0.0;
            foreach ($list as $e) { $ev[$e['e']] = ($ev[$e['e']] ?? 0) + $e['t']; $tickets += $e['t']; $sum += $e['c']; }
            $lines = '';
            foreach ($ev as $eid => $t) $lines .= '<li>' . esc_html(self::title($eid)) . ': ' . intval($t) . ' ' . ($t === 1 ? 'Ticket' : 'Tickets') . '</li>';
            $subject = ($tickets === 1 ? 'Neuer Verkauf' : $tickets . ' neue Tickets') . ' über deinen Link · +' . self::money($sum);
            $html = '<p>Über deinen Link bzw. Code wurde gerade verkauft:</p><ul>' . $lines . '</ul><p>Deine Provision: <strong>+' . self::money($sum) . '</strong>. Sie wird nach dem Event auszahlbar.</p>';
            if (self::send($p, $subject, 'Neuer Verkauf', $html, 'verkaeufe/')) $sent++;
        }
        return $sent;
    }

    // ═══════════════ Auszahlung ═══════════════

    public static function on_payout($id, $kind) {
        if (!self::on()) return;
        $po = TIX_Promoter_DB::get_payout(intval($id));
        if (!$po) return;
        $p = TIX_Promoter_DB::get_promoter(intval($po->promoter_id));
        if (!self::wants($p, 'payout')) return;
        $amt = self::money($po->total_commission);
        if ($kind === 'paid') {
            self::send($p, 'Deine Auszahlung über ' . $amt . ' ist unterwegs', 'Auszahlung überwiesen',
                '<p>Wir haben dir <strong>' . $amt . '</strong> Provision überwiesen. Je nach Bank ist das Geld in 1–2 Werktagen auf deinem Konto.</p>', 'auszahlungen/');
        } else {
            self::send($p, 'Auszahlung über ' . $amt . ' vorbereitet', 'Auszahlung vorbereitet',
                '<p>Für deine Provision über <strong>' . $amt . '</strong> ist eine Auszahlung vorbereitet. Bitte prüfe im Promoter-Bereich, ob deine Bankdaten stimmen.</p>', 'auszahlungen/');
        }
    }

    // ═══════════════ Neues Event ═══════════════

    private static function event_mail($p, $eid) {
        if (!self::wants($p, 'event') || TIX_Promoter::excluded_event($eid)) return false;
        $k = 'tix_pn_ev_' . intval($p->id) . '_' . intval($eid);
        if (get_transient($k)) return false; // je Promoter und Event nur einmal
        set_transient($k, 1, 30 * DAY_IN_SECONDS);
        $a = TIX_Promoter_DB::get_assignment_by_promoter_event(intval($p->id), intval($eid), true);
        $url = (string) get_permalink($eid);
        if (class_exists('TIX_Customer_URL')) $url = TIX_Customer_URL::to($url);
        $l = TIX_Promoter_REST::links((string) $p->promoter_code, $url, TIX_Promoter_REST::link_coupon($a));
        $eff = TIX_Promoter::get_effective_commission(intval($p->id), intval($eid));
        $prov = $eff['source'] === 'none' ? '' : ($eff['type'] === 'percent' ? rtrim(rtrim(number_format($eff['value'], 2, ',', ''), '0'), ',') . ' %' : self::money($eff['value']) . ' je Ticket');
        $date = (string) get_post_meta($eid, '_tix_date_start', true);
        $html = '<p>Du kannst jetzt für <strong>' . esc_html(self::title($eid)) . '</strong>' . ($date ? ' am ' . esc_html(wp_date('j. F Y', strtotime($date))) : '') . ' werben.</p>'
            . ($prov !== '' ? '<p>Deine Provision: <strong>' . esc_html($prov) . '</strong>.</p>' : '')
            . '<p>Dein Link: <a href="' . esc_url($l['link']) . '">' . esc_html($l['link']) . '</a>' . ($l['coupon'] ? '<br>Der Rabatt-Code <strong>' . esc_html($l['coupon']) . '</strong> steckt schon im Link.' : '') . '</p>'
            . '<p>Texte, Bilder und deinen QR-Code findest du im Promoter-Bereich unter „Links“.</p>';
        return self::send($p, 'Neues Event für dich: ' . self::title($eid), 'Neues Event', $html, 'links/?event=' . intval($eid));
    }

    public static function on_assigned($pid, $eid) {
        if (!self::on()) return;
        if (get_post_status(intval($eid)) !== 'publish') return; // Entwurf: Mail beim Veröffentlichen
        self::event_mail(TIX_Promoter_DB::get_promoter(intval($pid)), intval($eid));
    }

    /** Erstes Veröffentlichen: Promoter mit „Alle Events“ (Einzel-Seite) bzw. des Veranstalters und ausdrücklich zugeteilte */
    public static function on_publish($new, $old, $post) {
        if ($new !== 'publish' || $old === 'publish' || !($post instanceof WP_Post) || $post->post_type !== 'event' || !self::on()) return;
        $eid = intval($post->ID);
        if (TIX_Promoter::excluded_event($eid) || get_post_meta($eid, '_tix_promoter_announced', true)) return;
        update_post_meta($eid, '_tix_promoter_announced', gmdate('c'));
        global $wpdb;
        $tp = TIX_Promoter_DB::table_promoters(); $te = TIX_Promoter_DB::table_events();
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT promoter_id FROM $te WHERE status = 'active' AND (event_id = %d OR event_id = 0)", $eid)));
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            $orgs = array_map('intval', TIX_App_Scope::event_org_ids($eid));
            if ($orgs) $ids = array_merge($ids, array_map('intval', (array) $wpdb->get_col("SELECT id FROM $tp WHERE status = 'active' AND organizer_id IN (" . implode(',', $orgs) . ")")));
        }
        foreach (array_slice(array_unique($ids), 0, 500) as $pid) {
            $p = TIX_Promoter_DB::get_promoter($pid);
            if (!$p) continue;
            $explicit = (bool) TIX_Promoter_DB::get_assignment_by_promoter_event($pid, $eid, true);
            if (!TIX_Promoter::event_allowed($p, $eid, $explicit)) continue;
            self::event_mail($p, $eid);
        }
    }

    // ═══════════════ Wochenbericht ═══════════════

    public static function weekly() {
        if (!self::on() || !class_exists('TIX_Promoter_REST')) return 0;
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col("SELECT id FROM " . TIX_Promoter_DB::table_promoters() . " WHERE status = 'active'"));
        if (!$ids) return 0;
        $to = current_time('Y-m-d');
        $from = gmdate('Y-m-d', strtotime($to) - 6 * DAY_IN_SECONDS);
        $sent = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $st = TIX_Promoter_REST::stats($chunk, $from, $to);
            $all = TIX_Promoter_REST::stats($chunk, '', '');
            foreach ($chunk as $pid) {
                $w = $st[$pid] ?? []; $a = $all[$pid] ?? [];
                $clicks = intval($w['clicks'] ?? 0); $tickets = intval($w['tickets'] ?? 0);
                $earned = round(floatval($w['commission']['total'] ?? 0), 2);
                $payable = round(floatval($a['balance'] ?? 0), 2);
                if (!$clicks && !$tickets && $payable <= 0) continue;
                $p = TIX_Promoter_DB::get_promoter($pid);
                if (!self::wants($p, 'weekly')) continue;
                $html = '<p>Deine Woche (' . esc_html(wp_date('j.n.', strtotime($from))) . '–' . esc_html(wp_date('j.n.Y', strtotime($to))) . '):</p><ul>'
                    . '<li>' . $clicks . ' ' . ($clicks === 1 ? 'Klick' : 'Klicks') . ' auf deine Links</li>'
                    . '<li>' . $tickets . ' verkaufte ' . ($tickets === 1 ? 'Ticket' : 'Tickets') . '</li>'
                    . '<li>' . self::money($earned) . ' Provision verdient</li></ul>'
                    . ($payable > 0 ? '<p>Auszahlbar: <strong>' . self::money($payable) . '</strong>.</p>' : '')
                    . ($tickets === 0 ? '<p>Tipp: Teile deinen Link in deiner Story und in Gruppen – am besten 1–2 Tage vor dem Event.</p>' : '');
                if (self::send($p, 'Deine Promoter-Woche: ' . $tickets . ' ' . ($tickets === 1 ? 'Ticket' : 'Tickets') . ', ' . self::money($earned), 'Wochenbericht', $html, '')) $sent++;
            }
        }
        return $sent;
    }
}

TIX_Promoter_Notify::init();
