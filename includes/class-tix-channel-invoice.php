<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Channel_Invoice – Monatsrechnung der Gebühr im evendis-Kanal (Plattform, User-Entscheid 12.10.2026:
 * „A: Monatsrechnung“, Zahlung per SEPA-Lastschrift oder Überweisung).
 *
 * Grundlage: Vermittlungen in `{prefix}tix_partner_orders` mit `channel_fee` > 0 (Gebühr laut Quelle).
 *  - Belastung: bezahlt (`paid_at` bis Monatsende), noch nicht berechnet (`channel_invoice_id` = 0).
 *  - Gutschrift: schon berechnet, inzwischen storniert/erstattet (`channel_credit_id` = 0).
 * Tabelle `{prefix}tix_channel_invoices` (Nummer `KG-JJJJ-NNNN`, je Partner und Monat eine Rechnung,
 * Status open | sent | paid | void). Cron `tix_channel_invoice_cron` täglich: am Monatsanfang für den
 * Vormonat. Admin Tixomat → Kanal-Gebühren: Einstellungen (Satz, Vorgabe neue Partner, Zahlungsziel,
 * Zahlweise), Rechnungen (PDF, senden, bezahlt, stornieren), „Abrechnungslauf jetzt“.
 * Nur auf Plattformen mit Partner-Verzeichnis.
 */
class TIX_Channel_Invoice {

    const DB_VERSION = '1';
    const CRON       = 'tix_channel_invoice_cron';
    const PAID       = ['completed', 'processing'];
    const REVERSED   = ['refunded', 'cancelled'];

    public static function active() {
        return class_exists('TIX_Partners') && !empty(TIX_Partners::all());
    }

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 6);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (TIX_Channel_Invoice::active() && !wp_next_scheduled(TIX_Channel_Invoice::CRON)) wp_schedule_event(time() + 600, 'daily', TIX_Channel_Invoice::CRON);
        }, 21);
        add_action('admin_menu', [__CLASS__, 'menu'], 41);
        add_action('admin_post_tix_channel_invoice', [__CLASS__, 'handle_admin']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_channel_invoices';
    }

    private static function orders_table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_partner_orders';
    }

    public static function maybe_install() {
        if (get_option('tix_channel_invoice_db') === self::DB_VERSION || !self::active()) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            number varchar(32) NOT NULL DEFAULT '',
            partner varchar(64) NOT NULL DEFAULT '',
            period varchar(7) NOT NULL DEFAULT '',
            amount decimal(10,2) NOT NULL DEFAULT 0,
            charges int(11) NOT NULL DEFAULT 0,
            credits int(11) NOT NULL DEFAULT 0,
            items longtext NULL,
            snapshot longtext NULL,
            payment varchar(16) NOT NULL DEFAULT 'sepa',
            status varchar(16) NOT NULL DEFAULT 'open',
            due_date date NULL,
            created datetime NOT NULL,
            sent_at datetime NULL,
            paid_at datetime NULL,
            note text NULL,
            PRIMARY KEY  (id),
            KEY partner_period (partner, period)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_channel_invoice_db', self::DB_VERSION, false);
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $id));
    }

    private static function next_number() {
        $year = current_time('Y');
        $key  = 'tix_channel_invoice_seq_' . $year;
        $n = intval(get_option($key, 0)) + 1;
        update_option($key, $n, false);
        return sprintf('KG-%s-%04d', $year, $n);
    }

    /** Offene Belastungen/Gutschriften eines Partners bis Monatsende $ym. */
    public static function pending($partner, $ym) {
        global $wpdb;
        $t = self::orders_table();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) return [[], []];
        $end = gmdate('Y-m-d 00:00:00', strtotime($ym . '-01 +1 month'));
        $ph_p = implode(',', array_fill(0, count(self::PAID), '%s'));
        $ph_r = implode(',', array_fill(0, count(self::REVERSED), '%s'));
        $charges = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE partner = %s AND channel_fee > 0 AND channel_invoice_id = 0 AND status IN ($ph_p) AND paid_at IS NOT NULL AND paid_at < %s ORDER BY paid_at ASC, id ASC",
            ...array_merge([$partner], self::PAID, [$end])
        ));
        $credits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE partner = %s AND channel_fee > 0 AND channel_invoice_id > 0 AND channel_credit_id = 0 AND status IN ($ph_r) ORDER BY updated ASC, id ASC",
            ...array_merge([$partner], self::REVERSED)
        ));
        return [(array) $charges, (array) $credits];
    }

    private static function line($row, $type) {
        $date = $type === 'credit' ? (string) $row->updated : (string) $row->paid_at;
        return [
            'type'  => $type,
            'id'    => intval($row->id),
            'order' => (string) ($row->source_order_number ?: ('#' . $row->source_order_id)),
            'date'  => $date ? mysql2date('d.m.Y', $date) : '',
            'event' => html_entity_decode((string) get_the_title(intval($row->event_id)), ENT_QUOTES, 'UTF-8'),
            'fee'   => round(floatval($row->channel_fee), 2),
        ];
    }

    /** Rechnung (bzw. Gutschrift) für Partner + Monat erstellen. Null, wenn nichts offen ist. */
    public static function create($partner_id, $ym) {
        global $wpdb;
        self::maybe_install();
        $p = TIX_Partners::get($partner_id);
        if (!$p || !preg_match('/^\d{4}-\d{2}$/', (string) $ym)) return null;
        list($charges, $credits) = self::pending($p['id'], $ym);
        if (!$charges && !$credits) return null;
        $lines = []; $amount = 0.0;
        foreach ($charges as $r) { $lines[] = self::line($r, 'charge'); $amount += floatval($r->channel_fee); }
        foreach ($credits as $r) { $lines[] = self::line($r, 'credit'); $amount -= floatval($r->channel_fee); }
        $snap = class_exists('TIX_Settlement') && !empty($p['organizer_id']) ? TIX_Settlement::snapshot(intval($p['organizer_id'])) : [];
        if (empty($snap['organizer'])) $snap['organizer'] = (string) $p['name'];
        $snap['partner_name'] = (string) $p['name'];
        $cfs = TIX_Channel_Fee::settings();
        $now = current_time('mysql');
        $wpdb->insert(self::table(), [
            'number'   => self::next_number(),
            'partner'  => $p['id'],
            'period'   => $ym,
            'amount'   => round($amount, 2),
            'charges'  => count($charges),
            'credits'  => count($credits),
            'items'    => wp_json_encode($lines),
            'snapshot' => wp_json_encode($snap),
            'payment'  => $cfs['payment'],
            'status'   => 'open',
            'due_date' => gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' +' . intval($cfs['due_days']) . ' days')),
            'created'  => $now,
        ]);
        $id = intval($wpdb->insert_id);
        if (!$id) return null;
        $t = self::orders_table();
        foreach ($charges as $r) $wpdb->update($t, ['channel_invoice_id' => $id], ['id' => $r->id]);
        foreach ($credits as $r) $wpdb->update($t, ['channel_credit_id' => $id], ['id' => $r->id]);
        return self::get($id);
    }

    /** Täglich: am Monatsanfang den Vormonat abrechnen (je Partner höchstens eine Rechnung je Monat). */
    public static function cron($force_period = null) {
        if (!self::active()) return [];
        global $wpdb;
        self::maybe_install();
        $ym = $force_period ?: gmdate('Y-m', strtotime(current_time('Y-m-01') . ' -1 month'));
        $made = [];
        foreach (TIX_Partners::all() as $p) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE partner = %s AND period = %s AND status <> 'void' LIMIT 1", $p['id'], $ym));
            if ($exists && !$force_period) continue;
            $inv = self::create($p['id'], $ym);
            if ($inv) $made[] = $inv;
        }
        if ($made) self::notify_admin($made);
        return $made;
    }

    private static function admin_email() {
        $e = function_exists('tix_get_settings') ? (string) tix_get_settings('invoice_email') : '';
        return is_email($e) ? $e : (string) get_option('admin_email');
    }

    private static function notify_admin(array $made) {
        $lines = [];
        foreach ($made as $inv) $lines[] = sprintf('%s · %s · %s · %s €', $inv->number, $inv->partner, $inv->period, number_format(floatval($inv->amount), 2, ',', '.'));
        wp_mail(self::admin_email(), 'Neue Rechnungen Kanal-Gebühr', "Neue Rechnungen zur Gebühr im " . (class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : 'evendis') . "-Kanal:\n\n" . implode("\n", $lines) . "\n\nPrüfen und senden: " . admin_url('admin.php?page=tix-channel-fees'));
    }

    public static function pdf_bytes($inv) {
        return TIX_Settlement_PDF::channel_invoice($inv);
    }

    /** Rechnung als PDF an den Partner (Rechnungs-E-Mail des zugeordneten Veranstalters). */
    public static function send($inv) {
        global $wpdb;
        $snap = json_decode((string) $inv->snapshot, true);
        $to = is_array($snap) ? (string) ($snap['email'] ?? '') : '';
        if (!is_email($to)) return new WP_Error('tix_channel_no_email', 'Für diesen Partner ist keine Rechnungs-E-Mail hinterlegt (Veranstalter → Auszahlungsdaten bzw. E-Mail).');
        $dir = get_temp_dir();
        $file = trailingslashit($dir) . sanitize_file_name($inv->number . '.pdf');
        if (@file_put_contents($file, self::pdf_bytes($inv)) === false) return new WP_Error('tix_channel_pdf', 'PDF konnte nicht erstellt werden.');
        $credit = floatval($inv->amount) < 0;
        $body = sprintf("Hallo,\n\nim Anhang %s %s zur Gebühr im evendis-Kanal für %s über %s €.\n\n%s\n\nViele Grüße\n%s",
            $credit ? 'die Gutschrift' : 'die Rechnung', $inv->number, date_i18n('F Y', strtotime($inv->period . '-01')),
            number_format(abs(floatval($inv->amount)), 2, ',', '.'),
            $credit ? 'Der Betrag wird mit der nächsten Rechnung verrechnet.' : ($inv->payment === 'sepa' ? 'Wir ziehen den Betrag zum Fälligkeitsdatum per SEPA-Lastschrift ein.' : 'Bitte überweise den Betrag bis ' . mysql2date('d.m.Y', $inv->due_date) . '.'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $ok = wp_mail($to, ($credit ? 'Gutschrift ' : 'Rechnung ') . $inv->number, $body, [], [$file]);
        @unlink($file);
        if (!$ok) return new WP_Error('tix_channel_mail', 'Die E-Mail konnte nicht gesendet werden.');
        $wpdb->update(self::table(), ['status' => $inv->status === 'paid' ? 'paid' : 'sent', 'sent_at' => current_time('mysql')], ['id' => $inv->id]);
        return true;
    }

    /** Stornieren: Positionen werden wieder frei und kommen in die nächste Rechnung. */
    public static function void($inv) {
        global $wpdb;
        $t = self::orders_table();
        $wpdb->update($t, ['channel_invoice_id' => 0], ['channel_invoice_id' => intval($inv->id)]);
        $wpdb->update($t, ['channel_credit_id' => 0], ['channel_credit_id' => intval($inv->id)]);
        $wpdb->update(self::table(), ['status' => 'void'], ['id' => $inv->id]);
    }

    // ── Admin ──

    public static function menu() {
        if (!self::active()) return;
        add_submenu_page('tixomat', 'Kanal-Gebühren', 'Kanal-Gebühren', 'manage_options', 'tix-channel-fees', [__CLASS__, 'render']);
    }

    public static function handle_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_channel_invoice');
        $do  = sanitize_key($_REQUEST['do'] ?? '');
        $inv = !empty($_REQUEST['id']) ? self::get(intval($_REQUEST['id'])) : null;
        $msg = 'saved';
        if ($do === 'settings') {
            update_option(TIX_Channel_Fee::OPTION, [
                'percent'      => max(0, min(20, round(floatval(str_replace(',', '.', (string) ($_POST['percent'] ?? 2.5))), 4))),
                'fixed'        => max(0, min(5, round(floatval(str_replace(',', '.', (string) ($_POST['fixed'] ?? 0.59))), 2))),
                'label'        => mb_substr(sanitize_text_field(wp_unslash($_POST['label'] ?? 'Servicegebühr')), 0, 60) ?: 'Servicegebühr',
                'new_partners' => !empty($_POST['new_partners']) ? 1 : 0,
                'due_days'     => max(0, min(60, intval($_POST['due_days'] ?? 14))),
                'payment'      => ($_POST['payment'] ?? '') === 'transfer' ? 'transfer' : 'sepa',
            ], false);
        } elseif ($do === 'run') {
            $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['period'] ?? '')) ? $_POST['period'] : gmdate('Y-m', strtotime(current_time('Y-m-01') . ' -1 month'));
            $made = self::cron($ym);
            $msg = 'run_' . count($made);
        } elseif ($inv && $do === 'pdf') {
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . sanitize_file_name($inv->number . '.pdf') . '"');
            echo self::pdf_bytes($inv);
            exit;
        } elseif ($inv && $do === 'send') {
            $r = self::send($inv);
            $msg = is_wp_error($r) ? 'err_' . $r->get_error_code() : 'sent';
        } elseif ($inv && $do === 'paid') {
            global $wpdb;
            $wpdb->update(self::table(), ['status' => 'paid', 'paid_at' => current_time('mysql')], ['id' => $inv->id]);
        } elseif ($inv && $do === 'void') {
            self::void($inv);
        }
        wp_safe_redirect(admin_url('admin.php?page=tix-channel-fees&msg=' . rawurlencode($msg)));
        exit;
    }

    private static function eur($v) {
        return number_format(floatval($v), 2, ',', '.') . ' €';
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        self::maybe_install();
        $s = TIX_Channel_Fee::settings();
        $msg = sanitize_key($_GET['msg'] ?? '');
        $post = esc_url(admin_url('admin-post.php'));
        $nonce = wp_nonce_field('tix_channel_invoice', '_wpnonce', true, false);
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT 300");
        $labels = ['open' => 'Offen', 'sent' => 'Gesendet', 'paid' => 'Bezahlt', 'void' => 'Storniert'];
        $ym_prev = gmdate('Y-m', strtotime(current_time('Y-m-01') . ' -1 month'));
        ?>
        <div class="wrap">
            <h1>Kanal-Gebühren (evendis-Kanal)</h1>
            <?php if ($msg !== '') : ?>
                <div class="notice notice-<?php echo strpos($msg, 'err_') === 0 ? 'error' : 'success'; ?> is-dismissible"><p><?php
                    if (strpos($msg, 'run_') === 0) echo esc_html(intval(substr($msg, 4)) . ' Rechnung(en) erstellt.');
                    elseif ($msg === 'sent') echo 'Gesendet.';
                    elseif ($msg === 'err_tix_channel_no_email') echo 'Keine Rechnungs-E-Mail beim Veranstalter des Partners hinterlegt.';
                    elseif (strpos($msg, 'err_') === 0) echo 'Fehler: ' . esc_html(substr($msg, 4));
                    else echo 'Gespeichert.'; ?></p></div>
            <?php endif; ?>
            <p>Gebühr für Verkäufe geteilter Events über evendis.de und die evendis-App. Die Quelle kassiert, evendis stellt monatlich eine Rechnung (Anfang des Monats für den Vormonat; Stornos/Erstattungen bereits berechneter Bestellungen werden gutgeschrieben).
               Schalter je Partner unter <a href="<?php echo esc_url(admin_url('admin.php?page=tix-partners')); ?>">Tixomat → Partner</a>.</p>

            <h2>Einstellungen</h2>
            <form method="post" action="<?php echo $post; ?>" style="max-width:720px;">
                <?php echo $nonce; ?><input type="hidden" name="action" value="tix_channel_invoice"><input type="hidden" name="do" value="settings">
                <table class="form-table">
                    <tr><th>Gebühr (Vorgabe)</th><td><input type="number" name="percent" step="0.01" min="0" max="20" value="<?php echo esc_attr($s['percent']); ?>" style="width:80px;"> % + <input type="number" name="fixed" step="0.01" min="0" max="5" value="<?php echo esc_attr($s['fixed']); ?>" style="width:80px;"> € je Ticket (brutto)<?php if (class_exists('TIX_Planned_Values')) echo TIX_Planned_Values::hint(TIX_Channel_Fee::OPTION, 'percent') . TIX_Planned_Values::hint(TIX_Channel_Fee::OPTION, 'fixed'); ?></td></tr>
                    <tr><th>Bezeichnung für Gäste</th><td><input type="text" name="label" value="<?php echo esc_attr($s['label']); ?>" class="regular-text"></td></tr>
                    <tr><th>Neue Partner</th><td><label><input type="checkbox" name="new_partners" value="1" <?php checked($s['new_partners']); ?>> Gebühr bei neu gekoppelten Partnern einschalten (Verkauf startet erst nach Freigabe)</label></td></tr>
                    <tr><th>Zahlungsziel</th><td><input type="number" name="due_days" min="0" max="60" value="<?php echo intval($s['due_days']); ?>" style="width:70px;"> Tage</td></tr>
                    <tr><th>Zahlweise</th><td><select name="payment"><option value="sepa" <?php selected($s['payment'], 'sepa'); ?>>SEPA-Lastschrift (Mandat)</option><option value="transfer" <?php selected($s['payment'], 'transfer'); ?>>Überweisung</option></select></td></tr>
                </table>
                <p><button class="button button-primary">Speichern</button></p>
            </form>

            <h2>Offen (noch nicht berechnet)</h2>
            <table class="widefat striped" style="max-width:720px;"><thead><tr><th>Partner</th><th>Gebühr</th><th>Belastungen</th><th>Gutschriften</th><th>Offen</th></tr></thead><tbody>
            <?php $any = false; foreach (TIX_Partners::all() as $p) : list($c, $cr) = self::pending($p['id'], current_time('Y-m')); $sum = array_sum(array_map(function ($r) { return floatval($r->channel_fee); }, $c)) - array_sum(array_map(function ($r) { return floatval($r->channel_fee); }, $cr)); $any = true; ?>
                <tr><td><?php echo esc_html($p['name']); ?></td><td><?php echo empty($p['channel_fee']) ? 'aus' : 'an'; ?></td><td><?php echo count($c); ?></td><td><?php echo count($cr); ?></td><td><?php echo esc_html(self::eur($sum)); ?></td></tr>
            <?php endforeach; if (!$any) : ?><tr><td colspan="5">Keine Partner.</td></tr><?php endif; ?>
            </tbody></table>
            <form method="post" action="<?php echo $post; ?>" style="margin-top:10px;">
                <?php echo $nonce; ?><input type="hidden" name="action" value="tix_channel_invoice"><input type="hidden" name="do" value="run">
                Abrechnungslauf für <input type="month" name="period" value="<?php echo esc_attr($ym_prev); ?>"> <button class="button">Jetzt abrechnen</button>
            </form>

            <h2>Rechnungen</h2>
            <table class="widefat striped"><thead><tr><th>Nummer</th><th>Partner</th><th>Monat</th><th>Betrag</th><th>Positionen</th><th>Fällig</th><th>Status</th><th></th></tr></thead><tbody>
            <?php if (!$rows) : ?><tr><td colspan="8">Noch keine Rechnungen.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r) : $base = ['action' => 'tix_channel_invoice', 'id' => $r->id, '_wpnonce' => wp_create_nonce('tix_channel_invoice')]; ?>
                <tr><td><?php echo esc_html($r->number); ?></td><td><?php echo esc_html($r->partner); ?></td><td><?php echo esc_html($r->period); ?></td>
                    <td><?php echo esc_html(self::eur($r->amount)); ?></td><td><?php echo intval($r->charges); ?><?php echo $r->credits ? ' / ' . intval($r->credits) . ' Gutschr.' : ''; ?></td>
                    <td><?php echo esc_html($r->due_date ? mysql2date('d.m.Y', $r->due_date) : ''); ?></td><td><?php echo esc_html($labels[$r->status] ?? $r->status); ?></td>
                    <td><a href="<?php echo esc_url(add_query_arg($base + ['do' => 'pdf'], admin_url('admin-post.php'))); ?>">PDF</a>
                    <?php if ($r->status !== 'void') : ?>
                        · <a href="<?php echo esc_url(add_query_arg($base + ['do' => 'send'], admin_url('admin-post.php'))); ?>">Senden</a>
                        <?php if ($r->status !== 'paid') : ?> · <a href="<?php echo esc_url(add_query_arg($base + ['do' => 'paid'], admin_url('admin-post.php'))); ?>">Bezahlt</a><?php endif; ?>
                        · <a href="<?php echo esc_url(add_query_arg($base + ['do' => 'void'], admin_url('admin-post.php'))); ?>" onclick="return confirm('Rechnung stornieren? Die Positionen kommen in die nächste Rechnung.');" style="color:#b91c1c;">Stornieren</a>
                    <?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }
}
