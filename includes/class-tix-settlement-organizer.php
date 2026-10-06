<?php
if (!defined('ABSPATH')) exit;

/**
 * Veranstalter-Bereich (Web, wp-admin-Shell der Veranstalter): Seite „Auszahlungen“
 * – Saldo und nächste Auszahlung, Gebühren-Modus wählen, Auszahlungsdaten (IBAN mit Code),
 * Abrechnungen mit PDF. Nur im Mehr-Veranstalter-Modus, nur Inhaber/Team-Admin.
 * Gleiche Logik wie die App-Routen (TIX_Payout_Details, TIX_Settlement, TIX_Settlement_REST).
 */
class TIX_Settlement_Organizer {

    const SLUG = 'tix-organizer-payouts';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 21);
        add_action('admin_post_tix_org_payout', [__CLASS__, 'handle']);
        add_action('admin_post_tix_org_settlement_pdf', [__CLASS__, 'handle_pdf']);
    }

    public static function admin_menu() {
        if (!TIX_Settlement::multi() || current_user_can('manage_options')) return; // Admins: Tixomat → Auszahlungen
        add_submenu_page('tixomat', 'Auszahlungen', 'Auszahlungen', 'read', self::SLUG, [__CLASS__, 'render_page']);
    }

    private static function oid() {
        return class_exists('TIX_App_Scope') ? TIX_App_Scope::organizer_id_for_user() : 0;
    }

    private static function allowed() {
        return TIX_Settlement::multi() && self::oid() && TIX_App_Scope::is_org_manager();
    }

    private static function back(array $args) {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    private static function status_text($st) {
        return ['draft' => 'Wartet auf deine Auszahlungsdaten', 'ready' => 'Erstellt – wird geprüft', 'approved' => 'Freigegeben – Überweisung folgt',
                'paid' => 'Ausgezahlt', 'held' => 'Zurückgestellt', 'pending' => 'Noch nicht abgerechnet'][$st] ?? $st;
    }

    public static function render_page() {
        if (!self::allowed()) {
            echo '<div class="wrap"><h1>Auszahlungen</h1><p>Diese Seite ist nur für Veranstalter (Inhaber oder Team-Admin) verfügbar.'
               . (current_user_can('manage_options') ? ' Als Admin findest du alle Abrechnungen unter <a href="' . esc_url(admin_url('admin.php?page=tix-payouts')) . '">Tixomat → Auszahlungen</a>.' : '') . '</p></div>';
            return;
        }
        $oid = self::oid();
        $bal = TIX_Settlement::balance($oid);
        $pd  = TIX_Payout_Details::payload($oid);
        $fee = TIX_Settlement_REST::fee_mode_payload($oid, 20.0);
        $msg = sanitize_text_field(wp_unslash($_GET['msg'] ?? ''));
        $err = sanitize_text_field(wp_unslash($_GET['err'] ?? ''));
        $money = ['TIX_Settlement', 'money'];
        $box = 'background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin:0 0 18px;max-width:980px;';
        ?>
        <div class="wrap">
            <h1>Auszahlungen</h1>
            <?php if ($msg) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg); ?></p></div><?php endif; ?>
            <?php if ($err) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($err); ?></p></div><?php endif; ?>

            <?php if (!$pd['complete']) : ?>
            <div class="notice notice-warning"><p><strong>Bitte hinterlege deine Auszahlungsdaten.</strong> Du kannst weiter verkaufen; ausgezahlt wird, sobald Kontoinhaber, IBAN, Rechnungsadresse und Steuer-Status vollständig sind.</p></div>
            <?php endif; ?>

            <div style="<?php echo $box; ?>display:flex;gap:28px;flex-wrap:wrap;">
                <div><div style="color:#6b7280;font-size:12px;">Bisher verkauft</div><div style="font-size:22px;font-weight:700;"><?php echo esc_html($money($bal['sold_total'])); ?></div></div>
                <div><div style="color:#6b7280;font-size:12px;">Noch nicht abgerechnet (netto)</div><div style="font-size:22px;font-weight:700;"><?php echo esc_html($money($bal['pending_total'])); ?></div></div>
                <div><div style="color:#6b7280;font-size:12px;">Ausgezahlt</div><div style="font-size:22px;font-weight:700;color:#16a34a;"><?php echo esc_html($money($bal['paid_out_total'])); ?></div></div>
                <?php if (abs($bal['open_balance']) >= 0.01) : ?>
                <div><div style="color:#6b7280;font-size:12px;">Offener Saldo</div><div style="font-size:22px;font-weight:700;"><?php echo esc_html($money($bal['open_balance'])); ?></div></div>
                <?php endif; ?>
                <div><div style="color:#6b7280;font-size:12px;">Nächste Auszahlung</div><div style="font-size:16px;font-weight:600;">
                    <?php if ($bal['next_payout']) : ?>
                        <?php echo esc_html($money($bal['next_payout']['amount'])); ?><?php echo $bal['next_payout']['date'] ? ' · ab ' . esc_html(mysql2date('d.m.Y', $bal['next_payout']['date'])) : ''; ?>
                        <br><small style="color:#6b7280;font-weight:400;"><?php echo esc_html(self::status_text($bal['next_payout']['status'])); ?></small>
                    <?php else : ?>—<?php endif; ?>
                </div></div>
            </div>
            <p style="color:#6b7280;max-width:980px;margin-top:-8px;">Ausgezahlt wird <?php echo intval($bal['delay_days']); ?> Tage nach dem Event per Überweisung, nach Prüfung durch uns. Du bekommst eine Abrechnung als PDF und eine Mitteilung, sobald das Geld überwiesen ist.</p>

            <?php // ── Gebühr ── ?>
            <div style="<?php echo $box; ?>">
                <h2 style="margin-top:0;">Wer trägt die Servicegebühr?</h2>
                <p style="color:#6b7280;">Die Gebühr beträgt <?php echo esc_html(number_format($fee['fee']['fixed'], 2, ',', '.')); ?> € + <?php echo esc_html(number_format($fee['fee']['percent'], 2, ',', '.')); ?> % je Ticket<?php
                    echo $fee['fee']['max_per_ticket'] ? ' (höchstens ' . esc_html(number_format($fee['fee']['max_per_ticket'], 2, ',', '.')) . ' € je Ticket)' : ''; ?>. Du entscheidest nur, wer sie zahlt. Gilt für neue Bestellungen.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tix_org_payout"><input type="hidden" name="do" value="fee">
                    <?php wp_nonce_field('tix_org_payout_fee'); ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <?php foreach (['organizer' => 'Ich trage sie', 'split' => 'Geteilt mit dem Kunden', 'customer' => 'Der Kunde zahlt sie'] as $m => $label) :
                        $ex = $fee['examples'][$m]; ?>
                        <label style="flex:1;min-width:220px;border:2px solid <?php echo $fee['mode'] === $m ? '#FF5500' : '#e5e7eb'; ?>;border-radius:10px;padding:12px;cursor:pointer;">
                            <input type="radio" name="mode" value="<?php echo esc_attr($m); ?>" <?php checked($fee['mode'], $m); ?>>
                            <strong><?php echo esc_html($label); ?></strong><?php echo $m === 'split' ? ' <small>(Kunde ' . esc_html(number_format($fee['fee']['split_customer_share'], 0)) . ' %)</small>' : ''; ?>
                            <div style="font-size:12px;color:#374151;margin-top:6px;">Beispiel Ticket <?php echo esc_html($money($ex['price'])); ?>:<br>
                                Kunde zahlt <strong><?php echo esc_html($money($ex['customer_pays'])); ?></strong><br>
                                Du erhältst <strong><?php echo esc_html($money($ex['you_receive'])); ?></strong>
                                <?php echo $ex['payment_fee'] > 0 ? '<br><small style="color:#6b7280;">inkl. ca. ' . esc_html($money($ex['payment_fee'])) . ' Zahlungsgebühr</small>' : ''; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                    </div>
                    <?php if ($fee['gateway_note']) : ?><p style="color:#6b7280;font-size:12px;"><?php echo esc_html($fee['gateway_note']); ?></p><?php endif; ?>
                    <p><button class="button button-primary">Speichern</button></p>
                </form>
            </div>

            <?php // ── Auszahlungsdaten ── ?>
            <div style="<?php echo $box; ?>">
                <h2 style="margin-top:0;">Auszahlungsdaten <?php echo $pd['complete'] ? '<span style="color:#16a34a;font-size:14px;">✓ vollständig</span>' : ''; ?></h2>
                <?php if ($pd['pending_iban']) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;margin-bottom:14px;">
                    <input type="hidden" name="action" value="tix_org_payout"><input type="hidden" name="do" value="confirm">
                    <?php wp_nonce_field('tix_org_payout_confirm'); ?>
                    <strong>Neue IBAN bestätigen:</strong> Wir haben einen Code an <?php echo esc_html($pd['pending_email']); ?> geschickt.
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123456" style="width:100px;margin-left:8px;">
                    <button class="button button-primary">Bestätigen</button>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_org_payout&do=resend'), 'tix_org_payout_resend')); ?>" style="margin-left:8px;">Code erneut senden</a>
                </form>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tix_org_payout"><input type="hidden" name="do" value="details">
                    <?php wp_nonce_field('tix_org_payout_details'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th>Kontoinhaber</th><td><input type="text" name="holder" value="<?php echo esc_attr($pd['holder']); ?>" class="regular-text" required></td></tr>
                        <tr><th>IBAN</th><td><input type="text" name="iban" value="" class="regular-text" placeholder="<?php echo esc_attr($pd['iban_masked'] ?: 'DE00 0000 0000 0000 0000 00'); ?>" autocomplete="off">
                            <p class="description"><?php echo $pd['has_iban'] ? 'Leer lassen, um die hinterlegte IBAN zu behalten. ' : ''; ?>Eine neue IBAN wird erst nach Eingabe eines Codes aktiv, den wir an die E-Mail-Adresse deines Kontos schicken.</p></td></tr>
                        <tr><th>BIC (optional)</th><td><input type="text" name="bic" value="<?php echo esc_attr($pd['bic']); ?>" class="regular-text"></td></tr>
                        <tr><th>Firma</th><td><input type="text" name="billing[company]" value="<?php echo esc_attr($pd['billing']['company']); ?>" class="regular-text"></td></tr>
                        <tr><th>Name</th><td><input type="text" name="billing[name]" value="<?php echo esc_attr($pd['billing']['name']); ?>" class="regular-text"></td></tr>
                        <tr><th>Straße, Nr.</th><td><input type="text" name="billing[street]" value="<?php echo esc_attr($pd['billing']['street']); ?>" class="regular-text" required></td></tr>
                        <tr><th>PLZ, Ort</th><td><input type="text" name="billing[zip]" value="<?php echo esc_attr($pd['billing']['zip']); ?>" style="width:90px;" required> <input type="text" name="billing[city]" value="<?php echo esc_attr($pd['billing']['city']); ?>" style="width:220px;" required> <input type="text" name="billing[country]" value="<?php echo esc_attr($pd['billing']['country']); ?>" style="width:50px;" maxlength="2"></td></tr>
                        <tr><th>Steuer-Status</th><td>
                            <select name="tax_status" required>
                                <option value="">Bitte wählen</option>
                                <option value="vat_id" <?php selected($pd['tax_status'], 'vat_id'); ?>>USt-IdNr. vorhanden</option>
                                <option value="tax_number" <?php selected($pd['tax_status'], 'tax_number'); ?>>Steuernummer</option>
                                <option value="small_business" <?php selected($pd['tax_status'], 'small_business'); ?>>Kleinunternehmer (§ 19 UStG)</option>
                            </select>
                            <input type="text" name="vat_id" value="<?php echo esc_attr($pd['vat_id']); ?>" placeholder="USt-IdNr." style="width:160px;">
                            <input type="text" name="tax_number" value="<?php echo esc_attr($pd['tax_number']); ?>" placeholder="Steuernummer" style="width:160px;">
                        </td></tr>
                        <tr><th>E-Mail für Abrechnungen</th><td><input type="email" name="email" value="<?php echo esc_attr($pd['email']); ?>" class="regular-text" placeholder="<?php echo esc_attr(TIX_Payout_Details::owner_email($oid)); ?>"></td></tr>
                    </table>
                    <p><button class="button button-primary">Auszahlungsdaten speichern</button></p>
                </form>
            </div>

            <?php // ── Abrechnungen ── ?>
            <div style="<?php echo $box; ?>">
                <h2 style="margin-top:0;">Abrechnungen</h2>
                <?php $list = TIX_Settlement::enabled() ? TIX_Settlement::for_organizer($oid, 1, 100) : ['rows' => []]; ?>
                <?php if (!$list['rows']) : ?>
                    <p style="color:#6b7280;">Noch keine Abrechnungen. Die erste kommt automatisch <?php echo intval($bal['delay_days']); ?> Tage nach deinem nächsten Event.</p>
                <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th>Nummer</th><th>Event</th><th style="text-align:right;">Auszahlung</th><th>Status</th><th>Datum</th><th>Belege</th></tr></thead>
                    <tbody>
                    <?php foreach ($list['rows'] as $s) : $p = TIX_Settlement::list_payload($s); ?>
                        <tr>
                            <td><strong><?php echo esc_html($s->number); ?></strong></td>
                            <td><?php echo esc_html($s->event_title); ?><?php echo $s->event_date ? '<br><small>' . esc_html(mysql2date('d.m.Y', $s->event_date)) . '</small>' : ''; ?></td>
                            <td style="text-align:right;"><strong><?php echo esc_html($money($s->payout_amount)); ?></strong></td>
                            <td><?php echo esc_html(self::status_text($s->status)); ?><?php echo $s->status === 'held' && $s->held_reason ? '<br><small>' . esc_html($s->held_reason) . '</small>' : ''; ?></td>
                            <td><?php echo $p['payout_date'] ? esc_html(mysql2date('d.m.Y', $p['payout_date'])) : '—'; ?></td>
                            <td>
                                <?php if ($p['has_pdf']) : ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_org_settlement_pdf&id=' . $s->id), 'tix_org_pdf_' . $s->id)); ?>">PDF</a><?php endif; ?>
                                <?php if ($p['has_invoice']) : ?> · <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_org_settlement_pdf&doc=invoice&id=' . $s->id), 'tix_org_pdf_' . $s->id)); ?>">Rechnung</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function handle() {
        $do = sanitize_key($_REQUEST['do'] ?? '');
        check_admin_referer('tix_org_payout_' . $do);
        if (!self::allowed()) wp_die('Keine Berechtigung.');
        $oid = self::oid();
        switch ($do) {
            case 'fee':
                $mode = sanitize_key($_POST['mode'] ?? '');
                if (!in_array($mode, TIX_Fees::MODES, true)) self::back(['err' => 'Bitte einen Modus wählen.']);
                TIX_Settlement_REST::save_fee_mode($oid, $mode);
                self::back(['msg' => 'Gebühren-Modus gespeichert. Er gilt für neue Bestellungen.']);
            case 'details':
                $b = wp_unslash($_POST);
                $data = [
                    'holder' => $b['holder'] ?? '', 'bic' => $b['bic'] ?? '', 'billing' => (array) ($b['billing'] ?? []),
                    'tax_status' => $b['tax_status'] ?? '', 'vat_id' => $b['vat_id'] ?? '', 'tax_number' => $b['tax_number'] ?? '', 'email' => $b['email'] ?? '',
                ];
                if (trim((string) ($b['iban'] ?? '')) !== '') $data['iban'] = (string) $b['iban'];
                $r = TIX_Payout_Details::save($oid, $data);
                if (is_wp_error($r)) self::back(['err' => $r->get_error_message()]);
                self::back(['msg' => !empty($r['verify_required']) ? 'Gespeichert. Bitte bestätige die neue IBAN mit dem Code, den wir an ' . $r['code_sent_to'] . ' geschickt haben.' : 'Auszahlungsdaten gespeichert.']);
            case 'confirm':
                $r = TIX_Payout_Details::confirm($oid, (string) ($_POST['code'] ?? ''));
                if (is_wp_error($r)) {
                    $d = $r->get_error_data();
                    self::back(['err' => $r->get_error_message() . (isset($d['remaining']) ? ' Noch ' . intval($d['remaining']) . ' Versuche.' : '')]);
                }
                self::back(['msg' => 'Neue Bankverbindung ist aktiv.']);
            case 'resend':
                $r = TIX_Payout_Details::resend($oid);
                if (is_wp_error($r)) self::back(['err' => $r->get_error_message()]);
                self::back(['msg' => 'Neuer Code an ' . $r['code_sent_to'] . ' gesendet.']);
        }
        self::back(['err' => 'Unbekannte Aktion.']);
    }

    public static function handle_pdf() {
        $id = intval($_GET['id'] ?? 0);
        check_admin_referer('tix_org_pdf_' . $id);
        $s = TIX_Settlement::get($id);
        $own = $s && self::allowed() && intval($s->organizer_id) === self::oid();
        if (!$own && !current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        $pdf = TIX_Settlement_PDF::render($s, ($_GET['doc'] ?? '') === 'invoice' ? 'invoice' : 'settlement');
        if (!$pdf) wp_die('Kein PDF verfügbar.');
        TIX_Settlement_Admin::send_pdf($pdf);
    }
}
