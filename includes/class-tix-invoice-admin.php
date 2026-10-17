<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Invoice_Admin – WP-Admin „Tixomat → Rechnungen“ (1.38.487, Phase 3): Schalter, Aussteller-Status, Liste, Export,
 * Senden. Nutzt dieselben REST-Routen wie das Studio (intern über rest_do_request), damit Zahlen überall gleich sind.
 */
class TIX_Invoice_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 32);
        add_action('admin_post_tix_invoice_send', [__CLASS__, 'handle_send']);
        add_action('admin_post_tix_invoices_settings', [__CLASS__, 'handle_settings']);
        add_action('admin_post_tix_invoices_export', [__CLASS__, 'handle_export']);
        add_action('admin_post_tix_invoices_retry', [__CLASS__, 'handle_retry']);
    }

    public static function menu() {
        add_submenu_page('tixomat', 'Rechnungen', 'Rechnungen', 'manage_options', 'tix-invoices', [__CLASS__, 'render']);
    }

    private static function rest($method, $route, array $params = []) {
        $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
        foreach ($params as $k => $v) if ($v !== '' && $v !== null) $r->set_param($k, $v);
        $res = rest_do_request($r);
        return [$res->get_status(), json_decode(wp_json_encode($res->get_data()), true)];
    }

    private static function back($args = []) {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=tix-invoices')));
        exit;
    }

    public static function handle_send() {
        $id = intval($_GET['invoice_id'] ?? 0);
        if (!current_user_can('manage_options') || !check_admin_referer('tix_invoice_send_' . $id)) wp_die('Keine Berechtigung.');
        list($s, $r) = self::rest('POST', '/invoices/' . $id . '/send');
        wp_safe_redirect(add_query_arg(['tix_inv_msg' => $s === 200 ? 'sent' : 'send_failed'], wp_get_referer() ?: admin_url('admin.php?page=tix-invoices')));
        exit;
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options') || !check_admin_referer('tix_invoices_settings')) wp_die('Keine Berechtigung.');
        $body = [];
        if (isset($_POST['enabled'])) $body['enabled'] = $_POST['enabled'] === '1';
        if (isset($_POST['issuer_model'])) $body['issuer_model'] = sanitize_key($_POST['issuer_model']);
        foreach (['code', 'tax_rate'] as $k) if (isset($_POST[$k])) $body[$k] = sanitize_text_field(wp_unslash($_POST[$k]));
        if (isset($_POST['small_business_set'])) $body['small_business'] = !empty($_POST['small_business']);
        $issuer = sanitize_text_field((string) ($_POST['issuer'] ?? ''));
        $r = new WP_REST_Request('POST', '/tixomat/v1/studio/invoice-config');
        if ($issuer !== '') $r->set_param('issuer', $issuer);
        $r->set_header('content-type', 'application/json');
        $r->set_body(wp_json_encode($body));
        $res = rest_do_request($r);
        $msg = $res->get_status() === 200 ? 'saved' : 'error';
        $err = $res->get_status() === 200 ? '' : (string) ($res->get_data()['message'] ?? ($res->as_error() ? $res->as_error()->get_error_message() : ''));
        self::back(['tix_inv_msg' => $msg, 'tix_inv_err' => rawurlencode($err)]);
    }

    public static function handle_retry() {
        if (!current_user_can('manage_options') || !check_admin_referer('tix_invoices_retry')) wp_die('Keine Berechtigung.');
        $n = class_exists('TIX_Invoices') ? TIX_Invoices::retry_pending() : 0;
        self::back(['tix_inv_msg' => 'retried', 'n' => intval($n)]);
    }

    public static function handle_export() {
        if (!current_user_can('manage_options') || !check_admin_referer('tix_invoices_export')) wp_die('Keine Berechtigung.');
        $p = [];
        foreach (['from', 'to', 'q', 'kind', 'issuer'] as $k) $p[$k] = sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
        $p['format'] = ($_POST['format'] ?? '') === 'datev' ? 'datev' : 'csv';
        list($s, $r) = self::rest('GET', '/organizer/invoices', $p);
        if ($s !== 200 || empty($r['data'])) wp_die(esc_html($r['message'] ?? 'Export nicht möglich.'));
        nocache_headers();
        header('Content-Type: text/csv; charset=' . ($p['format'] === 'datev' ? 'windows-1252' : 'utf-8'));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($r['filename']) . '"');
        echo base64_decode($r['data']);
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options') || !class_exists('TIX_Invoices')) return;
        $multi = TIX_Invoices::multi();
        $issuer = $multi ? sanitize_text_field((string) ($_GET['issuer'] ?? 'platform')) : 'site';
        list(, $cfg) = self::rest('GET', '/studio/invoice-config', $multi ? ['issuer' => $issuer] : []);
        $f = [];
        foreach (['from', 'to', 'q', 'kind'] as $k) $f[$k] = sanitize_text_field(wp_unslash((string) ($_GET[$k] ?? '')));
        $page = max(1, intval($_GET['paged'] ?? 1));
        list($ls, $list) = self::rest('GET', '/organizer/invoices', $f + ['page' => $page, 'per_page' => 50] + ($multi && !empty($_GET['issuer']) ? ['issuer' => $issuer] : []));
        $msg = sanitize_key((string) ($_GET['tix_inv_msg'] ?? ''));
        $missing = ['name' => 'Firmenname', 'address' => 'Anschrift (Straße + PLZ/Ort)', 'tax_id' => 'Steuernummer oder USt-IdNr.'];
        ?>
        <div class="wrap">
            <h1>Rechnungen</h1>
            <?php if ($msg === 'saved'): ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <?php if ($msg === 'error'): ?><div class="notice notice-error"><p><?php echo esc_html(rawurldecode((string) ($_GET['tix_inv_err'] ?? 'Fehler.'))); ?></p></div><?php endif; ?>
            <?php if ($msg === 'sent'): ?><div class="notice notice-success is-dismissible"><p>Beleg gesendet.</p></div><?php endif; ?>
            <?php if ($msg === 'send_failed'): ?><div class="notice notice-error"><p>Senden fehlgeschlagen.</p></div><?php endif; ?>
            <?php if ($msg === 'retried'): ?><div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['n'] ?? 0); ?> Beleg(e) nachgeholt.</p></div><?php endif; ?>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;max-width:920px;margin:12px 0;">
                <h2 style="margin-top:0;">Status</h2>
                <p>Eigene Rechnungen sind <strong><?php echo !empty($cfg['enabled']) ? 'eingeschaltet' : 'ausgeschaltet'; ?></strong>.
                <?php if (empty($cfg['enabled'])): ?> Erst nach Rücksprache mit dem Steuerberater einschalten (Aussteller, Steuersätze, Gutscheine). Solange aus, entstehen keine Rechnungen; ein eingerichteter Anbieter (z. B. Lexoffice) arbeitet wie bisher.<?php else: ?> Bezahlte Bestellungen bekommen automatisch eine Rechnung (Abendkasse ausgenommen); der Anbieter-Weg ruht.<?php endif; ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px;" onsubmit="return confirm('<?php echo !empty($cfg['enabled']) ? 'Rechnungen ausschalten?' : 'Rechnungen jetzt einschalten? Ab sofort entstehen Rechnungen mit fortlaufenden Nummern.'; ?>');">
                    <input type="hidden" name="action" value="tix_invoices_settings"><?php wp_nonce_field('tix_invoices_settings'); ?>
                    <input type="hidden" name="enabled" value="<?php echo !empty($cfg['enabled']) ? '0' : '1'; ?>">
                    <?php submit_button(!empty($cfg['enabled']) ? 'Ausschalten' : 'Einschalten', !empty($cfg['enabled']) ? 'secondary' : 'primary', 'submit', false); ?>
                </form>
                <?php if ($multi): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                        <input type="hidden" name="action" value="tix_invoices_settings"><?php wp_nonce_field('tix_invoices_settings'); ?>
                        <label>Aussteller der Ticketrechnung:
                            <select name="issuer_model">
                                <option value="organizer" <?php selected(($cfg['issuer_model'] ?? '') === 'organizer'); ?>>Veranstalter (evendis vermittelt, Modell A)</option>
                                <option value="platform" <?php selected(($cfg['issuer_model'] ?? '') === 'platform'); ?>>evendis (Modell B)</option>
                            </select></label>
                        <?php submit_button('Speichern', 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
                <?php if (!empty($cfg['pending'])): ?>
                    <p><strong><?php echo intval($cfg['pending']); ?></strong> Bestellung(en) warten auf vollständige Angaben.
                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_invoices_retry'), 'tix_invoices_retry')); ?>">Jetzt nachholen</a></p>
                <?php endif; ?>
            </div>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;max-width:920px;margin:12px 0;">
                <h2 style="margin-top:0;">Aussteller <?php echo $multi ? '(evendis selbst)' : ''; ?></h2>
                <p><?php echo esc_html($cfg['issuer']['name'] ?? '') ?: '<em>kein Name</em>'; ?> · <?php echo esc_html(implode(', ', (array) ($cfg['issuer']['address_lines'] ?? []))); ?><br>
                    <?php echo esc_html(trim(($cfg['issuer']['tax_id'] ? 'Steuernr. ' . $cfg['issuer']['tax_id'] : '') . ' ' . ($cfg['issuer']['vat_id'] ? 'USt-IdNr. ' . $cfg['issuer']['vat_id'] : ''))); ?></p>
                <?php if (empty($cfg['issuer']['complete'])): ?>
                    <p style="color:#b91c1c;">Es fehlt: <?php echo esc_html(implode(', ', array_map(function ($m) use ($missing) { return $missing[$m] ?? $m; }, (array) ($cfg['issuer']['missing'] ?? [])))); ?>. Firmendaten unter Tixomat → Einstellungen → Rechnungen bzw. im Studio.</p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tix_invoices_settings"><?php wp_nonce_field('tix_invoices_settings'); ?>
                    <input type="hidden" name="issuer" value="<?php echo esc_attr($multi ? 'platform' : ''); ?>">
                    <input type="hidden" name="small_business_set" value="1">
                    <label>Kürzel <input name="code" value="<?php echo esc_attr($cfg['code'] ?? ''); ?>" size="6" maxlength="6"></label>
                    <label style="margin-left:12px;">Steuersatz % <input name="tax_rate" type="number" step="0.01" min="0" max="30" value="<?php echo esc_attr((string) ($cfg['tax_rate'] ?? '')); ?>" style="width:80px;"></label>
                    <label style="margin-left:12px;"><input type="checkbox" name="small_business" value="1" <?php checked(!empty($cfg['issuer']['small_business'])); ?>> Kleinunternehmer (§ 19 UStG)</label>
                    <?php submit_button('Speichern', 'secondary', 'submit', false); ?>
                </form>
                <p style="color:#64748b;">Nächste Nummern: <?php echo esc_html(implode(' · ', (array) ($cfg['next'] ?? []))); ?></p>
            </div>

            <h2>Belege</h2>
            <form method="get" style="margin-bottom:8px;">
                <input type="hidden" name="page" value="tix-invoices">
                <label>von <input type="date" name="from" value="<?php echo esc_attr($f['from']); ?>"></label>
                <label>bis <input type="date" name="to" value="<?php echo esc_attr($f['to']); ?>"></label>
                <select name="kind"><option value="">alle Arten</option><?php foreach (['invoice' => 'Rechnungen', 'correction' => 'Korrekturen', 'receipt' => 'Belege ohne USt'] as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($f['kind'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
                <input type="search" name="q" value="<?php echo esc_attr($f['q']); ?>" placeholder="Nummer, Bestellung, Name, E-Mail">
                <?php submit_button('Filtern', 'secondary', '', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <input type="hidden" name="action" value="tix_invoices_export"><?php wp_nonce_field('tix_invoices_export'); ?>
                <?php foreach ($f as $k => $v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
                <button class="button" name="format" value="csv">CSV herunterladen</button>
                <button class="button" name="format" value="datev">DATEV-Buchungsstapel</button>
                <?php if ($ls === 200): ?><span style="margin-left:12px;color:#475569;"><?php echo intval($list['totals']['count']); ?> Belege · netto <?php echo esc_html(number_format($list['totals']['net'], 2, ',', '.')); ?> € · USt <?php echo esc_html(number_format($list['totals']['tax'], 2, ',', '.')); ?> € · brutto <?php echo esc_html(number_format($list['totals']['gross'], 2, ',', '.')); ?> €</span><?php endif; ?>
            </form>
            <table class="widefat striped">
                <thead><tr><th>Nummer</th><th>Art</th><th>Datum</th><th>Bestellung</th><th>Empfänger</th><?php if ($multi): ?><th>Aussteller</th><?php endif; ?><th style="text-align:right;">Brutto</th><th>Buchhaltung</th><th></th></tr></thead>
                <tbody>
                <?php foreach ((array) ($list['invoices'] ?? []) as $inv):
                    $row = TIX_Invoices::get($inv['id']);
                    $kind = ['invoice' => 'Rechnung', 'correction' => 'Korrektur', 'receipt' => 'Beleg'][$inv['kind']] ?? $inv['kind']; ?>
                    <tr>
                        <td><code><?php echo esc_html($inv['number']); ?></code><?php if ($inv['ref']): ?><br><span style="font-size:11px;color:#94a3b8;">zu <?php echo esc_html($inv['ref']['number']); ?></span><?php endif; ?></td>
                        <td><?php echo esc_html($kind); ?></td>
                        <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($inv['issued_at']))); ?></td>
                        <td><?php echo esc_html($inv['order_number']); ?></td>
                        <td><?php echo esc_html($inv['recipient']['company'] ?: $inv['recipient']['name']); ?><br><span style="font-size:11px;color:#94a3b8;"><?php echo esc_html($inv['recipient']['email']); ?></span></td>
                        <?php if ($multi): ?><td><?php echo esc_html($inv['issuer']['name']); ?></td><?php endif; ?>
                        <td style="text-align:right;"><?php echo esc_html(number_format($inv['gross'], 2, ',', '.')); ?> €</td>
                        <td style="font-size:11px;"><?php if (!empty($inv['export'])): $ex = $inv['export']; echo esc_html(['done' => '✓ übergeben', 'pending' => '… wartet', 'error' => '✗ Fehler', 'skipped' => '– nicht übergeben'][$ex['status']] ?? $ex['status']); if ($ex['error']): ?><br><span style="color:#b91c1c;"><?php echo esc_html($ex['error']); ?></span><?php endif; endif; ?></td>
                        <td style="white-space:nowrap;"><a class="button button-small" href="<?php echo esc_url(TIX_Invoices::download_url($row)); ?>">PDF</a>
                            <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_invoice_send&invoice_id=' . intval($inv['id'])), 'tix_invoice_send_' . intval($inv['id']))); ?>" title="An <?php echo esc_attr($inv['recipient']['email']); ?> senden">✉︎</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($list['invoices'])): ?><tr><td colspan="<?php echo $multi ? 9 : 8; ?>">Keine Belege.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php if (!empty($list['has_more']) || $page > 1): ?>
                <p><?php if ($page > 1): ?><a class="button" href="<?php echo esc_url(add_query_arg(['paged' => $page - 1])); ?>">‹ zurück</a><?php endif; ?>
                <?php if (!empty($list['has_more'])): ?><a class="button" href="<?php echo esc_url(add_query_arg(['paged' => $page + 1])); ?>">weiter ›</a><?php endif; ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
