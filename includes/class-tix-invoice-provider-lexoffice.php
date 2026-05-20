<?php
/**
 * TIX Invoice Provider — Lexoffice (Lexware Office)
 *
 * API: https://developers.lexoffice.io/docs/
 * Auth: Bearer Token (API-Key aus dem lexoffice-Account)
 *
 * Workflow:
 *   1. Invoice via POST /v1/invoices?finalize=true erstellen (taxType=gross → Brutto-Preise)
 *   2. invoice_id + voucherNumber speichern
 *   3. PDF on-demand laden via GET /v1/invoices/{id}/document → {documentFileId}
 *   4. Dann GET /v1/files/{documentFileId} mit Accept: application/pdf
 *
 * @since 1.38.200
 */
if (!defined('ABSPATH')) exit;

class TIX_Invoice_Provider_Lexoffice {

    const ID         = 'lexoffice';
    const NAME       = 'Lexware Office (lexoffice)';
    const API_URL    = 'https://api.lexoffice.io/v1';

    private static function settings(): array {
        return (array) TIX_Invoicing::get_settings(self::ID);
    }

    private static function api_key(): string {
        return trim((string) (self::settings()['api_key'] ?? ''));
    }

    public static function is_configured(): bool {
        return self::api_key() !== '';
    }

    /* ─────────────── RECHNUNG ERSTELLEN ─────────────── */

    public static function create_invoice(int $order_id): array {
        $api_key = self::api_key();
        if (!$api_key) return ['success' => false, 'error' => 'Kein API-Key konfiguriert.'];

        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if (!$order) return ['success' => false, 'error' => 'Bestellung nicht gefunden.'];

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT name, cat_name, quantity, total, tax FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", $order_id
        ));
        if (empty($items)) return ['success' => false, 'error' => 'Keine Positionen in der Bestellung.'];

        // Adresse
        $address = [
            'name'        => trim($order->billing_first_name . ' ' . $order->billing_last_name),
            'street'      => trim((string) $order->billing_address_1),
            'zip'         => trim((string) $order->billing_postcode),
            'city'        => trim((string) $order->billing_city),
            'countryCode' => strtoupper(trim((string) $order->billing_country)) ?: 'DE',
        ];
        if (!empty($order->billing_company)) {
            $address['supplement'] = $address['name']; // Personenname als Zusatz
            $address['name']       = trim((string) $order->billing_company);
        }

        // Tixomat speichert Brutto-Preise (tax_inclusive) → taxType = gross
        $line_items = [];
        $lines_sum  = 0.0;
        foreach ($items as $it) {
            $qty       = max(1, intval($it->quantity));
            $brutto    = round(floatval($it->total), 2);
            $vat_amt   = round(floatval($it->tax), 2);
            $netto     = $brutto - $vat_amt;
            $vat_rate  = ($netto > 0) ? (int) round(($vat_amt / $netto) * 100) : 0;
            $unit_gross = round($brutto / $qty, 2);
            $desc       = trim(($it->name ?: 'Ticket') . ($it->cat_name ? ' — ' . $it->cat_name : ''));

            $line_items[] = [
                'type'      => 'custom',
                'name'      => $desc !== '' ? $desc : 'Ticket',
                'quantity'  => $qty,
                'unitName'  => $qty > 1 ? 'Stück' : 'Stück',
                'unitPrice' => [
                    'currency'           => 'EUR',
                    'grossAmount'        => $unit_gross,
                    'taxRatePercentage'  => $vat_rate,
                ],
            ];
            $lines_sum += $brutto;
        }

        // Differenz (Coupon / Service-Fee) als zusätzliche Zeile
        $diff = round(floatval($order->total) - $lines_sum, 2);
        if (abs($diff) >= 0.01) {
            $line_items[] = [
                'type'      => 'custom',
                'name'      => $diff < 0 ? 'Rabatt' : 'Servicegebühr',
                'quantity'  => 1,
                'unitName'  => 'Stück',
                'unitPrice' => [
                    'currency'          => 'EUR',
                    'grossAmount'       => $diff,
                    'taxRatePercentage' => 0,
                ],
            ];
        }

        $now_iso = wp_date('Y-m-d\TH:i:s.000P');
        $payload = [
            'voucherDate' => $now_iso,
            'address'     => $address,
            'lineItems'   => $line_items,
            'totalPrice'  => ['currency' => 'EUR'],
            'taxConditions'      => ['taxType' => 'gross'],
            'shippingConditions' => [
                'shippingDate' => $now_iso,
                'shippingType' => 'service',
            ],
            'title'           => 'Rechnung',
            'introduction'    => 'Vielen Dank für deine Bestellung ' . $order->order_number . '.',
            'remark'          => 'Bestellung: ' . $order->order_number . ' · ' . trim($order->billing_first_name . ' ' . $order->billing_last_name),
        ];

        $response = wp_remote_post(self::API_URL . '/invoices?finalize=true', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'Verbindungsfehler: ' . $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $msg = $body['message'] ?? '';
            if (!empty($body['IssueList'][0]['source'])) {
                $msg .= ' (' . $body['IssueList'][0]['source'] . ': ' . ($body['IssueList'][0]['args'][0] ?? '') . ')';
            }
            return ['success' => false, 'error' => 'lexoffice ' . $code . ': ' . ($msg ?: wp_remote_retrieve_body($response))];
        }

        $invoice_id = (string) ($body['id'] ?? '');
        if (!$invoice_id) return ['success' => false, 'error' => 'Keine invoice-ID in der Antwort.'];

        // Voucher-Nummer holen (kommt erst nach finalize, separater GET)
        $invoice_number = '';
        $detail = wp_remote_get(self::API_URL . '/invoices/' . $invoice_id, [
            'timeout' => 12,
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Accept' => 'application/json'],
        ]);
        if (!is_wp_error($detail) && wp_remote_retrieve_response_code($detail) === 200) {
            $detail_body = json_decode(wp_remote_retrieve_body($detail), true);
            $invoice_number = (string) ($detail_body['voucherNumber'] ?? '');
        }

        return [
            'success'        => true,
            'invoice_id'     => $invoice_id,
            'invoice_number' => $invoice_number,
            'pdf_file_id'    => '', // lazy via fetch_pdf
        ];
    }

    /* ─────────────── PDF NACHLADEN ─────────────── */

    public static function fetch_pdf(int $order_id): ?string {
        $api_key = self::api_key();
        if (!$api_key) return null;
        $meta = TIX_Invoicing::get_invoice_meta($order_id);
        if (!$meta || empty($meta['invoice_id'])) return null;

        // Step 1: document-Metadaten holen (liefert documentFileId)
        $doc_response = wp_remote_get(self::API_URL . '/invoices/' . $meta['invoice_id'] . '/document', [
            'timeout' => 12,
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($doc_response)) return null;
        $code = wp_remote_retrieve_response_code($doc_response);
        if ($code < 200 || $code >= 300) return null;
        $doc_body = json_decode(wp_remote_retrieve_body($doc_response), true);
        $file_id  = (string) ($doc_body['documentFileId'] ?? '');
        if (!$file_id) return null;

        // Step 2: PDF-Binary holen
        $pdf_response = wp_remote_get(self::API_URL . '/files/' . $file_id, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Accept' => 'application/pdf'],
        ]);
        if (is_wp_error($pdf_response)) return null;
        $pdf_code = wp_remote_retrieve_response_code($pdf_response);
        if ($pdf_code < 200 || $pdf_code >= 300) return null;
        $pdf = wp_remote_retrieve_body($pdf_response);
        return $pdf !== '' ? $pdf : null;
    }

    /* ─────────────── SETTINGS-UI ─────────────── */

    public static function render_settings(array $settings, string $name_prefix) {
        $api_key  = (string) ($settings['api_key'] ?? '');
        $nonce    = wp_create_nonce('tix_lexoffice_test');
        ?>
        <table class="form-table"><tbody>
            <tr>
                <th scope="row"><label>API-Key</label></th>
                <td>
                    <input type="text" id="tix-lexoffice-key" name="<?php echo esc_attr($name_prefix); ?>[api_key]" value="<?php echo esc_attr($api_key); ?>" placeholder="aus lexoffice → Einstellungen → Öffentliche API" style="width:480px;padding:7px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;">
                    <button type="button" id="tix-lexoffice-test" class="button" style="margin-left:6px;">Verbindung testen</button>
                    <div id="tix-lexoffice-test-result" style="margin-top:10px;font-size:13px;"></div>
                    <p class="description" style="margin-top:10px;">
                        <strong>So findest du den Key:</strong>
                        <br>1. <a href="https://app.lexware.de/permissions/public-api" target="_blank">app.lexware.de → Einstellungen → Öffentliche API</a> öffnen
                        <br>2. „Neuen API-Schlüssel erstellen" anklicken
                        <br>3. Berechtigungen: mindestens <strong>Belege (lesen + schreiben)</strong> und <strong>Kontakte (lesen + schreiben)</strong>
                        <br>4. Der Schlüssel wird einmalig angezeigt — kopieren und hier einfügen, dann speichern.
                    </p>
                </td>
            </tr>
        </tbody></table>
        <script>
        (function($){
            $('#tix-lexoffice-test').on('click', function(){
                var $btn = $(this), $res = $('#tix-lexoffice-test-result');
                var key = $('#tix-lexoffice-key').val();
                if (!key) { $res.html('<span style="color:#b91c1c;">Bitte zuerst einen API-Key eintragen.</span>'); return; }
                $btn.prop('disabled', true).text('Teste…');
                $res.html('<span style="color:#64748b;">Sende Test-Request an api.lexoffice.io …</span>');
                $.post(ajaxurl, {
                    action: 'tix_lexoffice_test_key',
                    nonce:  '<?php echo esc_js($nonce); ?>',
                    api_key: key
                }, function(r){
                    $btn.prop('disabled', false).text('Verbindung testen');
                    if (r.success) {
                        $res.html('<span style="background:#d1fae5;color:#065f46;padding:8px 12px;border-radius:6px;display:inline-block;">✓ Verbindung OK — angemeldet als <strong>' + $('<div>').text(r.data.name || '').html() + '</strong>' + (r.data.company ? ' (' + $('<div>').text(r.data.company).html() + ')' : '') + '</span>');
                    } else {
                        $res.html('<span style="background:#fee2e2;color:#991b1b;padding:8px 12px;border-radius:6px;display:inline-block;">✗ ' + $('<div>').text((r.data && r.data.message) || 'Fehler').html() + '</span>');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function sanitize_settings(array $input): array {
        return [
            'api_key' => sanitize_text_field($input['api_key'] ?? ''),
        ];
    }

    /* ─────────────── AJAX: VERBINDUNGSTEST ─────────────── */

    public static function register_ajax() {
        add_action('wp_ajax_tix_lexoffice_test_key', [__CLASS__, 'ajax_test_key']);
    }

    public static function ajax_test_key() {
        check_ajax_referer('tix_lexoffice_test', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        $api_key = trim((string) ($_POST['api_key'] ?? ''));
        if ($api_key === '') wp_send_json_error(['message' => 'Kein API-Key übergeben.']);

        $response = wp_remote_get(self::API_URL . '/profile', [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Verbindungsfehler: ' . $response->get_error_message()]);
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 401) {
            wp_send_json_error(['message' => 'Unauthorized (401) — API-Key wird von lexoffice abgelehnt. Mögliche Ursachen: Tippfehler, Key wurde widerrufen, falsche Berechtigungen, oder kein API-Key sondern z.B. das App-Token (muss „Neuen API-Schlüssel" unter Einstellungen → Öffentliche API sein).']);
        }
        if ($code < 200 || $code >= 300) {
            wp_send_json_error(['message' => 'lexoffice ' . $code . ': ' . ($body['message'] ?? wp_remote_retrieve_body($response))]);
        }
        wp_send_json_success([
            'name'    => trim(($body['companyName'] ?? '') ?: (($body['firstName'] ?? '') . ' ' . ($body['lastName'] ?? ''))),
            'company' => $body['organizationId'] ?? '',
            'raw'     => $body,
        ]);
    }
}
