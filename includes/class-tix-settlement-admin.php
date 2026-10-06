<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-Seite „Tixomat → Auszahlungen“ (nur Mehr-Veranstalter-Modus).
 *
 *  - Fällige Abrechnungen mit Summe, je Zeile Freigeben / Zurückstellen / Neu berechnen / PDF
 *  - Kopierhilfe je freigegebener Abrechnung (Empfänger, IBAN, BIC, Betrag, Verwendungszweck),
 *    danach „als ausgezahlt markieren“ (Datum + Referenz) → Mail/Push an den Veranstalter
 *  - Hinweise: fehlende Auszahlungsdaten, hohe Erstattungsquote, Zahlungsgebühr nach Satz
 *  - Veranstalter: Sonderregeln (Frist, Abschlag, Anhalten), Saldo abrechnen, Abschlag erstellen
 *  - SEPA-Sammeldatei (pain.001.001.09) für freigegebene Abrechnungen – nur wenn eingeschaltet
 */
class TIX_Settlement_Admin {

    const SLUG = 'tix-payouts';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 41);
        add_action('admin_post_tix_payout_action', [__CLASS__, 'handle_action']);
        add_action('admin_post_tix_payout_pdf', [__CLASS__, 'handle_pdf']);
        add_action('admin_post_tix_payout_sepa', [__CLASS__, 'handle_sepa']);
    }

    public static function admin_menu() {
        if (!TIX_Settlement::multi()) return;
        add_submenu_page('tixomat', 'Auszahlungen', 'Auszahlungen', 'manage_options', self::SLUG, [__CLASS__, 'render_page']);
    }

    private static function url(array $args = []) {
        return add_query_arg($args, admin_url('admin.php?page=' . self::SLUG));
    }

    private static function action_url($do, $id, array $extra = []) {
        return wp_nonce_url(admin_url('admin-post.php?' . http_build_query(['action' => 'tix_payout_action', 'do' => $do, 'id' => intval($id)] + $extra)), 'tix_payout_' . $do . '_' . intval($id));
    }

    private static function status_label($st) {
        $map = ['draft' => ['Wartet auf Daten', '#6b7280'], 'ready' => ['Bereit', '#2563eb'], 'approved' => ['Freigegeben', '#d97706'],
                'paid' => ['Ausgezahlt', '#16a34a'], 'held' => ['Zurückgestellt', '#dc2626'], 'void' => ['Ersetzt', '#9ca3af']];
        $m = $map[$st] ?? [$st, '#6b7280'];
        return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;background:' . $m[1] . ';">' . esc_html($m[0]) . '</span>';
    }

    /** Hinweise zu einer Abrechnung. */
    public static function hints($s) {
        global $wpdb;
        $h = [];
        if (!TIX_Payout_Details::complete($s->organizer_id)) $h[] = 'Auszahlungsdaten fehlen';
        if (floatval($s->gross) > 0) {
            $q = floatval($s->refunds) / floatval($s->gross) * 100;
            if ($q >= floatval(TIX_Settlement::opt('settlement_refund_alert'))) $h[] = sprintf('Erstattungsquote %s %%', number_format($q, 0, ',', '.'));
        }
        $rate = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Settlement::items_table() . " WHERE settlement_id = %d AND gateway_fee_source = 'rate'", $s->id)));
        if ($rate) $h[] = $rate . '× Zahlungsgebühr nach Satz';
        if ($s->due_date && strcmp($s->due_date, wp_date('Y-m-d')) > 0 && in_array($s->status, ['ready', 'approved'], true)) $h[] = 'Frist bis ' . mysql2date('d.m.', $s->due_date);
        if (floatval($s->carry_over) < 0) $h[] = 'Negativer Saldo vorgetragen';
        if (get_post_meta($s->organizer_id, TIX_Payout_Details::META_PENDING, true)) $h[] = 'IBAN-Änderung offen';
        return $h;
    }

    // ──────────────────────────────────────────
    //  Seite
    // ──────────────────────────────────────────

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        if (!TIX_Settlement::enabled()) {
            echo '<div class="wrap"><h1>Auszahlungen</h1><p>Abrechnungen sind nur im Mehr-Veranstalter-Modus verfügbar (Tabellen werden beim nächsten Seitenaufruf angelegt).</p></div>';
            return;
        }
        $tab = sanitize_key($_GET['tab'] ?? 'due');
        $msg = sanitize_key($_GET['msg'] ?? '');
        $err = sanitize_text_field(wp_unslash($_GET['err'] ?? ''));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Auszahlungen</h1>
            <hr class="wp-header-end">
            <?php if ($msg) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(self::msg_text($msg)); ?></p></div><?php endif; ?>
            <?php if ($err) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($err); ?></p></div><?php endif; ?>
            <h2 class="nav-tab-wrapper">
                <?php foreach (['due' => 'Fällig', 'approved' => 'Freigegeben', 'paid' => 'Ausgezahlt', 'all' => 'Alle', 'organizers' => 'Veranstalter'] as $k => $l) : ?>
                    <a class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url(['tab' => $k])); ?>"><?php echo esc_html($l); ?></a>
                <?php endforeach; ?>
            </h2>
            <?php
            if (!empty($_GET['id'])) self::render_detail(intval($_GET['id']));
            elseif ($tab === 'organizers') self::render_organizers();
            else self::render_list($tab);
            ?>
        </div>
        <script>
        document.addEventListener('click', function (e) {
            var b = e.target.closest('[data-tix-copy]');
            if (!b) return;
            e.preventDefault();
            navigator.clipboard.writeText(b.getAttribute('data-tix-copy')).then(function () {
                var t = b.textContent; b.textContent = 'Kopiert ✓'; setTimeout(function () { b.textContent = t; }, 1200);
            });
        });
        </script>
        <?php
    }

    private static function msg_text($m) {
        return [
            'approved' => 'Abrechnung freigegeben.', 'held' => 'Abrechnung zurückgestellt.', 'released' => 'Abrechnung wieder aufgenommen.',
            'recalc' => 'Abrechnung neu berechnet.', 'paid' => 'Als ausgezahlt markiert – der Veranstalter wurde benachrichtigt.',
            'created' => 'Abrechnung erstellt.', 'none' => 'Nichts abzurechnen.', 'rules' => 'Sonderregeln gespeichert.', 'cron' => 'Abrechnungslauf ausgeführt.',
        ][$m] ?? 'Erledigt.';
    }

    private static function render_list($tab) {
        global $wpdb;
        $t = TIX_Settlement::table();
        $where = [
            'due'      => "status IN ('draft','ready','held')",
            'approved' => "status = 'approved'",
            'paid'     => "status = 'paid'",
            'all'      => "status <> 'void'",
        ][$tab] ?? "status IN ('draft','ready','held')";
        $order = $tab === 'paid' || $tab === 'all' ? 'id DESC' : 'due_date ASC, id ASC';
        $rows = $wpdb->get_results("SELECT * FROM {$t} WHERE {$where} ORDER BY {$order} LIMIT 300");
        $sum = 0.0;
        foreach ((array) $rows as $r) $sum += floatval($r->payout_amount);
        ?>
        <p style="margin:14px 0;">
            <strong><?php echo count((array) $rows); ?></strong> Abrechnung(en) · Summe <strong><?php echo esc_html(TIX_Settlement::money($sum)); ?></strong>
            <?php if ($tab === 'approved' && $rows && intval(TIX_Settlement::opt('settlement_sepa_enabled'))) : ?>
                · <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_payout_sepa'), 'tix_payout_sepa')); ?>">SEPA-Sammeldatei (pain.001) herunterladen</a>
            <?php endif; ?>
            · <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_payout_action&do=cron&id=0'), 'tix_payout_cron_0')); ?>">Abrechnungslauf jetzt starten</a>
        </p>
        <table class="widefat striped" style="max-width:1300px;">
            <thead><tr>
                <th>Nummer</th><th>Veranstalter</th><th>Event</th><th>Fällig ab</th><th style="text-align:right;">Betrag</th><th>Status</th><th>Hinweise</th><th>Aktionen</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="8" style="color:#6b7280;">Keine Abrechnungen.</td></tr>
            <?php endif; ?>
            <?php foreach ((array) $rows as $s) : ?>
                <tr>
                    <td><a href="<?php echo esc_url(self::url(['id' => $s->id])); ?>"><strong><?php echo esc_html($s->number); ?></strong></a><?php echo $s->type !== 'event' ? '<br><small>' . esc_html($s->type === 'advance' ? 'Abschlag' : 'Saldo') . '</small>' : ''; ?></td>
                    <td><a href="<?php echo esc_url(get_edit_post_link($s->organizer_id)); ?>"><?php echo esc_html(get_the_title($s->organizer_id)); ?></a></td>
                    <td><?php echo esc_html($s->event_title); ?><?php echo $s->event_date ? '<br><small>' . esc_html(mysql2date('d.m.Y', $s->event_date)) . '</small>' : ''; ?></td>
                    <td><?php echo $s->due_date ? esc_html(mysql2date('d.m.Y', $s->due_date)) : '—'; ?></td>
                    <td style="text-align:right;"><strong><?php echo esc_html(TIX_Settlement::money($s->payout_amount)); ?></strong></td>
                    <td><?php echo self::status_label($s->status); ?><?php echo $s->status === 'held' && $s->held_reason ? '<br><small>' . esc_html($s->held_reason) . '</small>' : ''; ?></td>
                    <td><small style="color:#b45309;"><?php echo esc_html(implode(' · ', self::hints($s))); ?></small></td>
                    <td><?php self::row_actions($s); ?></td>
                </tr>
                <?php if ($s->status === 'approved') : ?>
                <tr><td colspan="8" style="background:#fffbeb;"><?php self::copy_helper($s); ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function row_actions($s) {
        $a = [];
        if ($s->status === 'ready') $a[] = '<a class="button button-primary button-small" href="' . esc_url(self::action_url('approve', $s->id)) . '">Freigeben</a>';
        if ($s->status === 'held') $a[] = '<a class="button button-small" href="' . esc_url(self::action_url('release', $s->id)) . '">Wieder aufnehmen</a>';
        if (in_array($s->status, ['draft', 'ready', 'approved'], true)) {
            $a[] = '<a class="button button-small" href="' . esc_url(self::action_url('hold', $s->id)) . '" onclick="var r=prompt(\'Grund für das Zurückstellen:\');if(r===null)return false;this.href+=\'&reason=\'+encodeURIComponent(r);">Zurückstellen</a>';
        }
        if (in_array($s->status, ['draft', 'ready', 'held'], true) && $s->type !== 'advance') {
            $a[] = '<a class="button button-small" href="' . esc_url(self::action_url('recalc', $s->id)) . '">Neu berechnen</a>';
        }
        if ($s->status !== 'draft') {
            $a[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_payout_pdf&id=' . $s->id), 'tix_payout_pdf_' . $s->id)) . '">PDF</a>';
            if ($s->invoice_number !== '' && floatval($s->invoice_total) > 0) {
                $a[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_payout_pdf&doc=invoice&id=' . $s->id), 'tix_payout_pdf_' . $s->id)) . '">Rechnung</a>';
            }
        }
        echo implode(' ', $a);
    }

    /** Kopierhilfe + „als ausgezahlt markieren“. */
    private static function copy_helper($s) {
        $snap = TIX_Settlement::snap($s);
        $iban = !empty($snap['iban_enc']) ? TIX_Payout_Details::decrypt($snap['iban_enc']) : '';
        $fields = [
            'Empfänger'        => (string) ($snap['holder'] ?? ''),
            'IBAN'             => $iban !== '' ? TIX_Payout_Details::format_iban($iban) : '',
            'BIC'              => (string) ($snap['bic'] ?? ''),
            'Betrag'           => number_format(floatval($s->payout_amount), 2, ',', ''),
            'Verwendungszweck' => TIX_Settlement::remittance($s),
        ];
        echo '<div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;">';
        foreach ($fields as $label => $val) {
            if ($val === '') continue;
            $copy = $label === 'IBAN' ? str_replace(' ', '', $val) : $val;
            echo '<div><div style="font-size:11px;color:#6b7280;">' . esc_html($label) . '</div><code style="font-size:13px;">' . esc_html($val) . '</code> '
               . '<button type="button" class="button button-small" data-tix-copy="' . esc_attr($copy) . '">Kopieren</button></div>';
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;align-items:flex-end;margin-left:auto;">
            <input type="hidden" name="action" value="tix_payout_action">
            <input type="hidden" name="do" value="paid">
            <input type="hidden" name="id" value="<?php echo intval($s->id); ?>">
            <?php wp_nonce_field('tix_payout_paid_' . intval($s->id)); ?>
            <label style="font-size:11px;color:#6b7280;">Überwiesen am<br><input type="date" name="date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>"></label>
            <label style="font-size:11px;color:#6b7280;">Referenz (optional)<br><input type="text" name="ref" style="width:140px;"></label>
            <button class="button button-primary" onclick="return confirm('Wirklich als ausgezahlt markieren? Der Veranstalter wird benachrichtigt.');">Als ausgezahlt markieren</button>
        </form>
        <?php
        echo '</div>';
    }

    private static function render_detail($id) {
        $s = TIX_Settlement::get($id);
        if (!$s) { echo '<p>Abrechnung nicht gefunden.</p>'; return; }
        $snap = TIX_Settlement::snap($s);
        $d = TIX_Settlement::detail_payload($s);
        ?>
        <p><a href="<?php echo esc_url(self::url()); ?>">← Zurück</a></p>
        <h2><?php echo esc_html($s->number . ' · ' . $s->event_title); ?> <?php echo self::status_label($s->status); ?></h2>
        <p><?php echo esc_html(get_the_title($s->organizer_id)); ?> · <?php echo esc_html(TIX_Settlement::money($s->payout_amount)); ?>
            <?php echo $s->due_date ? ' · fällig ab ' . esc_html(mysql2date('d.m.Y', $s->due_date)) : ''; ?>
            · <?php echo esc_html($s->tax_mode === 'reseller' ? 'Eigenhandel' : 'Vermittlung'); ?>
            <?php echo $s->invoice_number ? ' · Gebührenrechnung ' . esc_html($s->invoice_number) . ' (' . esc_html(TIX_Settlement::money($s->invoice_total)) . ')' : ''; ?></p>
        <?php if ($s->note) : ?><p style="color:#b45309;"><?php echo nl2br(esc_html($s->note)); ?></p><?php endif; ?>
        <p><?php self::row_actions($s); ?></p>
        <?php if ($s->status === 'approved') { echo '<div style="background:#fffbeb;padding:12px;max-width:1300px;">'; self::copy_helper($s); echo '</div>'; } ?>
        <table class="widefat" style="max-width:600px;margin-top:14px;">
            <?php foreach ([
                'Ticketumsatz' => $d['gross'], 'Erstattungen' => -$d['refunds'], 'Plattformgebühr (Veranstalter)' => -$d['platform_fee'],
                'Zahlungsgebühren' => -$d['gateway_fees'], 'Korrekturen/Nachverkäufe/Überträge' => $d['corrections'], 'Abschläge' => -$d['advances'],
                'Übertrag (negativ)' => -$d['carry_over'], 'Auszahlungsbetrag' => $d['payout_amount'],
                'Info: Kundengebühren' => $d['customer_fees'], 'Info: Kasse vor Ort' => $d['pos_total'],
            ] as $l => $v) : if (abs($v) < 0.005 && !in_array($l, ['Ticketumsatz', 'Auszahlungsbetrag'], true)) continue; ?>
                <tr><td><?php echo esc_html($l); ?></td><td style="text-align:right;"><?php echo esc_html(TIX_Settlement::money($v)); ?></td></tr>
            <?php endforeach; ?>
        </table>
        <p>Auszahlungsdaten: <?php echo esc_html(($snap['holder'] ?? '') . ' · ' . ($snap['iban_masked'] ?? '')); ?> ·
            <?php echo TIX_Payout_Details::complete($s->organizer_id) ? 'vollständig' : '<strong style="color:#dc2626;">unvollständig</strong>'; ?></p>
        <h3>Positionen</h3>
        <table class="widefat striped" style="max-width:1300px;">
            <thead><tr><th>Art</th><th>Bestellung / Text</th><th>Tickets</th><th style="text-align:right;">Umsatz</th><th style="text-align:right;">Erstattung</th><th style="text-align:right;">Plattform</th><th style="text-align:right;">Zahlung</th><th style="text-align:right;">Netto</th></tr></thead>
            <tbody>
            <?php foreach (TIX_Settlement::items($s->id) as $it) : ?>
                <tr>
                    <td><?php echo esc_html($it->type); ?></td>
                    <td><?php echo $it->order_id ? '<a href="' . esc_url(admin_url('admin.php?page=tix-orders&order_id=' . $it->order_id)) . '">' . esc_html($it->note) . '</a>' : esc_html(TIX_Settlement::item_label($it)); ?></td>
                    <td><?php echo intval($it->tickets) ?: ''; ?></td>
                    <td style="text-align:right;"><?php echo esc_html(number_format(floatval($it->gross), 2, ',', '.')); ?></td>
                    <td style="text-align:right;"><?php echo esc_html(number_format(floatval($it->refund), 2, ',', '.')); ?></td>
                    <td style="text-align:right;"><?php echo esc_html(number_format(floatval($it->platform_fee), 2, ',', '.')); ?></td>
                    <td style="text-align:right;"><?php echo esc_html(number_format(floatval($it->gateway_fee), 2, ',', '.')) . ($it->gateway_fee_source === 'rate' ? ' *' : ''); ?></td>
                    <td style="text-align:right;"><strong><?php echo esc_html(number_format(floatval($it->amount), 2, ',', '.')); ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="color:#6b7280;font-size:12px;">* Zahlungsgebühr nach zentralem Satz (tatsächliche Anbietergebühr nicht abrufbar). „Neu berechnen“ versucht es erneut.</p>
        <?php
    }

    private static function render_organizers() {
        global $wpdb;
        $orgs = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $default_delay = intval(TIX_Settlement::opt('settlement_delay_days'));
        ?>
        <p style="margin:14px 0;color:#6b7280;">Sonderregeln gelten nur für den jeweiligen Veranstalter (Vorgabe: Auszahlung <?php echo $default_delay; ?> Tage nach Event-Ende, kein Abschlag). Nur Admins können sie ändern.</p>
        <table class="widefat striped" style="max-width:1300px;">
            <thead><tr><th>Veranstalter</th><th>Auszahlungsdaten</th><th>Gebühr trägt</th><th style="text-align:right;">Offener Saldo</th><th>Sonderregeln</th><th>Aktionen</th></tr></thead>
            <tbody>
            <?php foreach ($orgs as $o) :
                $pd = TIX_Payout_Details::payload($o->ID);
                $open = TIX_Settlement::open_total($o->ID);
                $mode = TIX_Fees::get_fee_config($o->ID)['fee_mode'];
                $delay = get_post_meta($o->ID, TIX_Settlement::META_DELAY, true);
                $adv = get_post_meta($o->ID, TIX_Settlement::META_ADVANCE, true);
                $hold = get_post_meta($o->ID, TIX_Settlement::META_HOLD, true);
                $upcoming = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'future'], 'posts_per_page' => 30, 'fields' => 'ids',
                    'meta_query' => [['key' => '_tix_organizer_id', 'value' => strval($o->ID)], ['key' => '_tix_settled_at', 'compare' => 'NOT EXISTS']],
                    'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC']);
            ?>
                <tr>
                    <td><a href="<?php echo esc_url(get_edit_post_link($o->ID)); ?>"><strong><?php echo esc_html($o->post_title); ?></strong></a></td>
                    <td><?php echo $pd['complete'] ? '✓ ' . esc_html($pd['holder'] . ' · ' . $pd['iban_masked']) : '<span style="color:#dc2626;">fehlen</span>'; ?><?php echo $pd['pending_iban'] ? '<br><small>IBAN-Änderung wartet auf Code</small>' : ''; ?></td>
                    <td><?php echo esc_html(['organizer' => 'Veranstalter', 'split' => 'Geteilt', 'customer' => 'Kunde'][$mode] ?? $mode); ?></td>
                    <td style="text-align:right;"><?php echo esc_html(TIX_Settlement::money($open)); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <input type="hidden" name="action" value="tix_payout_action"><input type="hidden" name="do" value="rules"><input type="hidden" name="id" value="<?php echo intval($o->ID); ?>">
                            <?php wp_nonce_field('tix_payout_rules_' . intval($o->ID)); ?>
                            <label>Frist <input type="number" name="delay" min="0" max="365" value="<?php echo esc_attr($delay); ?>" placeholder="<?php echo $default_delay; ?>" style="width:60px;"> Tage</label>
                            <label>Abschlag <input type="number" name="advance" min="0" max="100" value="<?php echo esc_attr($adv); ?>" placeholder="0" style="width:60px;"> %</label>
                            <label><input type="checkbox" name="hold" value="1" <?php checked($hold); ?>> anhalten</label>
                            <button class="button button-small">Speichern</button>
                        </form>
                    </td>
                    <td>
                        <?php if (abs($open) >= 0.01) : ?>
                            <a class="button button-small" href="<?php echo esc_url(self::action_url('balance', $o->ID)); ?>">Saldo abrechnen</a>
                        <?php endif; ?>
                        <?php if (floatval($adv) > 0 && $upcoming) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex;gap:4px;margin-top:4px;">
                                <input type="hidden" name="action" value="tix_payout_action"><input type="hidden" name="do" value="advance"><input type="hidden" name="id" value="<?php echo intval($o->ID); ?>">
                                <?php wp_nonce_field('tix_payout_advance_' . intval($o->ID)); ?>
                                <select name="event_id"><?php foreach ($upcoming as $eid) : ?><option value="<?php echo intval($eid); ?>"><?php echo esc_html(get_the_title($eid) . ' (' . get_post_meta($eid, '_tix_date_start', true) . ')'); ?></option><?php endforeach; ?></select>
                                <button class="button button-small">Abschlag erstellen</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($upcoming) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex;gap:4px;margin-top:4px;">
                                <input type="hidden" name="action" value="tix_payout_action"><input type="hidden" name="do" value="now"><input type="hidden" name="id" value="<?php echo intval($o->ID); ?>">
                                <?php wp_nonce_field('tix_payout_now_' . intval($o->ID)); ?>
                                <select name="event_id"><?php foreach ($upcoming as $eid) : ?><option value="<?php echo intval($eid); ?>"><?php echo esc_html(get_the_title($eid) . ' (' . get_post_meta($eid, '_tix_date_start', true) . ')'); ?></option><?php endforeach; ?></select>
                                <button class="button button-small" onclick="return confirm('Abrechnung für dieses Event jetzt erstellen (ohne Frist abzuwarten)?');">Jetzt abrechnen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ──────────────────────────────────────────
    //  Aktionen
    // ──────────────────────────────────────────

    private static function back($args) {
        $ref = wp_get_referer();
        $url = $ref ? remove_query_arg(['msg', 'err'], $ref) : self::url();
        wp_safe_redirect(add_query_arg($args, $url));
        exit;
    }

    public static function handle_action() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        $do = sanitize_key($_REQUEST['do'] ?? '');
        $id = intval($_REQUEST['id'] ?? 0);
        check_admin_referer('tix_payout_' . $do . '_' . $id);
        $r = true; $msg = $do;
        switch ($do) {
            case 'approve': $r = TIX_Settlement::approve($id); $msg = 'approved'; break;
            case 'hold':    $r = TIX_Settlement::hold($id, wp_unslash($_REQUEST['reason'] ?? '')); $msg = 'held'; break;
            case 'release': $r = TIX_Settlement::release($id); $msg = 'released'; break;
            case 'recalc':  $r = TIX_Settlement::recalculate($id) ?: new WP_Error('x', 'Diese Abrechnung kann nicht neu berechnet werden.'); $msg = 'recalc'; break;
            case 'paid':    $r = TIX_Settlement::mark_paid($id, wp_unslash($_POST['ref'] ?? ''), sanitize_text_field($_POST['date'] ?? '')); $msg = 'paid'; break;
            case 'balance': $r = TIX_Settlement::create_balance($id); $msg = $r ? 'created' : 'none'; $r = true; break;
            case 'now':
                $eid = intval($_POST['event_id'] ?? 0);
                if (intval(get_post_meta($eid, '_tix_organizer_id', true)) !== $id) { $r = new WP_Error('x', 'Event gehört nicht zu diesem Veranstalter.'); break; }
                $r = TIX_Settlement::create_for_event($eid, true); $msg = $r ? 'created' : 'none'; $r = true; break;
            case 'advance':
                $eid = intval($_POST['event_id'] ?? 0);
                if (intval(get_post_meta($eid, '_tix_organizer_id', true)) !== $id) { $r = new WP_Error('x', 'Event gehört nicht zu diesem Veranstalter.'); break; }
                $r = TIX_Settlement::create_advance($eid); $msg = 'created'; break;
            case 'rules':
                $delay = trim((string) ($_POST['delay'] ?? ''));
                if ($delay === '') delete_post_meta($id, TIX_Settlement::META_DELAY); else update_post_meta($id, TIX_Settlement::META_DELAY, max(0, min(365, intval($delay))));
                $adv = floatval($_POST['advance'] ?? 0);
                if ($adv > 0) update_post_meta($id, TIX_Settlement::META_ADVANCE, min(100, $adv)); else delete_post_meta($id, TIX_Settlement::META_ADVANCE);
                if (!empty($_POST['hold'])) update_post_meta($id, TIX_Settlement::META_HOLD, 1); else delete_post_meta($id, TIX_Settlement::META_HOLD);
                $msg = 'rules'; break;
            case 'cron':
                delete_transient('tix_settlement_lock');
                TIX_Settlement::run_cron(); $msg = 'cron'; break;
            default: $r = new WP_Error('x', 'Unbekannte Aktion.');
        }
        if (is_wp_error($r)) self::back(['err' => $r->get_error_message()]);
        self::back(['msg' => $msg]);
    }

    public static function handle_pdf() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        $id = intval($_GET['id'] ?? 0);
        check_admin_referer('tix_payout_pdf_' . $id);
        $pdf = TIX_Settlement_PDF::render(TIX_Settlement::get($id), ($_GET['doc'] ?? '') === 'invoice' ? 'invoice' : 'settlement');
        if (!$pdf) wp_die('Kein PDF verfügbar.');
        self::send_pdf($pdf);
    }

    public static function send_pdf(array $pdf) {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $pdf['filename'] . '"');
        header('Content-Length: ' . strlen($pdf['bytes']));
        echo $pdf['bytes'];
        exit;
    }

    // ──────────────────────────────────────────
    //  SEPA (pain.001.001.09) – vorbereitet
    // ──────────────────────────────────────────

    public static function sepa_xml(array $rows) {
        $name = (string) TIX_Settlement::opt('settlement_debtor_name');
        $iban = TIX_Payout_Details::normalize_iban(TIX_Settlement::opt('settlement_debtor_iban'));
        $bic  = (string) TIX_Settlement::opt('settlement_debtor_bic');
        if ($name === '' || !TIX_Payout_Details::iban_valid($iban)) return new WP_Error('x', 'Auftraggeber (Name, IBAN) in den Einstellungen unter Gebühren → Abrechnung & Auszahlung hinterlegen.');
        $x = fn($v, $len = 70) => htmlspecialchars(mb_substr((string) $v, 0, $len), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $tx = ''; $sum = 0.0; $n = 0;
        foreach ($rows as $s) {
            $snap = TIX_Settlement::snap($s);
            $to = !empty($snap['iban_enc']) ? TIX_Payout_Details::decrypt($snap['iban_enc']) : '';
            if ($to === '' || floatval($s->payout_amount) <= 0) continue;
            $amt = number_format(floatval($s->payout_amount), 2, '.', '');
            $sum += floatval($amt); $n++;
            $tx .= '<CdtTrfTxInf><PmtId><EndToEndId>' . $x($s->number, 35) . '</EndToEndId></PmtId>'
                 . '<Amt><InstdAmt Ccy="EUR">' . $amt . '</InstdAmt></Amt>'
                 . (!empty($snap['bic']) ? '<CdtrAgt><FinInstnId><BICFI>' . $x($snap['bic'], 11) . '</BICFI></FinInstnId></CdtrAgt>' : '')
                 . '<Cdtr><Nm>' . $x($snap['holder'] ?? '') . '</Nm></Cdtr>'
                 . '<CdtrAcct><Id><IBAN>' . $x($to, 34) . '</IBAN></Id></CdtrAcct>'
                 . '<RmtInf><Ustrd>' . $x(TIX_Settlement::remittance($s), 140) . '</Ustrd></RmtInf></CdtTrfTxInf>';
        }
        if (!$n) return new WP_Error('x', 'Keine überweisbaren Abrechnungen.');
        $msg_id = 'TIX' . gmdate('YmdHis') . wp_rand(100, 999);
        $ctrl = number_format($sum, 2, '.', '');
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.09"><CstmrCdtTrfInitn>'
            . '<GrpHdr><MsgId>' . $msg_id . '</MsgId><CreDtTm>' . gmdate('Y-m-d\TH:i:s') . '</CreDtTm><NbOfTxs>' . $n . '</NbOfTxs><CtrlSum>' . $ctrl . '</CtrlSum><InitgPty><Nm>' . $x($name) . '</Nm></InitgPty></GrpHdr>'
            . '<PmtInf><PmtInfId>' . $msg_id . '-1</PmtInfId><PmtMtd>TRF</PmtMtd><BtchBookg>true</BtchBookg><NbOfTxs>' . $n . '</NbOfTxs><CtrlSum>' . $ctrl . '</CtrlSum>'
            . '<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl></PmtTpInf><ReqdExctnDt><Dt>' . wp_date('Y-m-d') . '</Dt></ReqdExctnDt>'
            . '<Dbtr><Nm>' . $x($name) . '</Nm></Dbtr><DbtrAcct><Id><IBAN>' . $x($iban, 34) . '</IBAN></Id></DbtrAcct>'
            . '<DbtrAgt><FinInstnId>' . ($bic ? '<BICFI>' . $x($bic, 11) . '</BICFI>' : '<Othr><Id>NOTPROVIDED</Id></Othr>') . '</FinInstnId></DbtrAgt>'
            . '<ChrgBr>SLEV</ChrgBr>' . $tx . '</PmtInf></CstmrCdtTrfInitn></Document>';
    }

    public static function handle_sepa() {
        global $wpdb;
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_payout_sepa');
        if (!intval(TIX_Settlement::opt('settlement_sepa_enabled'))) wp_die('SEPA-Sammeldatei ist nicht eingeschaltet.');
        $rows = $wpdb->get_results("SELECT * FROM " . TIX_Settlement::table() . " WHERE status = 'approved' ORDER BY id ASC");
        $xml = self::sepa_xml((array) $rows);
        if (is_wp_error($xml)) self::back(['err' => $xml->get_error_message()]);
        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="auszahlungen-' . wp_date('Y-m-d') . '.xml"');
        echo $xml;
        exit;
    }
}
