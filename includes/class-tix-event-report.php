<?php
/**
 * TIX_Event_Report — Detail-Bericht pro Event mit Export (CSV/XLSX/PDF)
 *
 * Admin-Seite: Tixomat → Event-Bericht
 * URL: /wp-admin/admin.php?page=tix-event-report&event_id=X
 *
 * Features:
 *  - Event-Auswahl (Filter: alle / vergangen / kommend)
 *  - KPIs: Tickets verkauft, Umsatz, Check-in-Rate, Refunds
 *  - Pro-Kategorie-Aufschlüsselung
 *  - Detail-Tabelle aller verkauften Tickets (sortier-/filterbar)
 *  - Export: CSV, XLSX (nativer Writer), PDF (nativer Writer mit A4 quer)
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Report {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_tix_event_report_export', [__CLASS__, 'handle_export']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue($hook) {
        if ($hook !== 'tixomat_page_tix-event-report') return;
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', [], '4.4.0', true);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Event-Bericht',
            'Event-Bericht',
            'manage_options',
            'tix-event-report',
            [__CLASS__, 'render_page']
        );
    }

    // ═══════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════

    public static function render_page() {
        $event_id    = intval($_GET['event_id'] ?? 0);
        $time_filter = sanitize_text_field($_GET['time_filter'] ?? 'all');
        $events      = self::get_events_for_picker($time_filter);

        ?>
        <div class="wrap" style="max-width:1300px;">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <span class="dashicons dashicons-chart-bar" style="font-size:28px;width:28px;height:28px;color:#FF5500;"></span>
                Event-Bericht
            </h1>
            <p style="color:#6b7280;font-size:13px;margin:4px 0 20px;">
                Detaillierter Verkaufsbericht pro Event mit Pro-Ticket-Aufschlüsselung. Export als CSV, Excel oder PDF.
            </p>

            <?php // ── Event-Auswahl ── ?>
            <form method="get" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="page" value="tix-event-report">
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:5px;">Zeitraum</label>
                    <select name="time_filter" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;min-width:140px;" onchange="this.form.submit()">
                        <option value="all"      <?php selected($time_filter, 'all'); ?>>Alle Events</option>
                        <option value="past"     <?php selected($time_filter, 'past'); ?>>Nur vergangene</option>
                        <option value="upcoming" <?php selected($time_filter, 'upcoming'); ?>>Nur kommende</option>
                    </select>
                </div>
                <div style="flex:1;min-width:280px;">
                    <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:5px;">Event auswählen</label>
                    <select name="event_id" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:100%;" onchange="this.form.submit()">
                        <option value="0">— Bitte Event wählen —</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>" <?php selected($event_id, $ev['id']); ?>>
                                <?php echo esc_html($ev['date_label'] . ' · ' . $ev['title']); ?>
                                <?php if ($ev['is_past']): ?> · vergangen<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($event_id > 0): ?>
                <?php self::render_report($event_id); ?>
            <?php else: ?>
                <div style="background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:60px 20px;text-align:center;color:#6b7280;">
                    <span class="dashicons dashicons-arrow-up" style="font-size:32px;width:32px;height:32px;color:#9ca3af;"></span>
                    <p style="margin:10px 0 0;font-size:14px;">Wähle oben ein Event, um den Detailbericht anzuzeigen.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_report($event_id) {
        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event') {
            echo '<div class="notice notice-error"><p>Event nicht gefunden.</p></div>';
            return;
        }

        $data = self::get_event_data($event_id);

        $date_start = get_post_meta($event_id, '_tix_date_start', true);
        $time_start = get_post_meta($event_id, '_tix_time_start', true);
        $loc_id     = intval(get_post_meta($event_id, '_tix_location_id', true));
        $loc_name   = $loc_id ? get_the_title($loc_id) : '–';

        $date_label = $date_start ? date_i18n('l, d. F Y', strtotime($date_start)) : '–';

        // Export-URLs
        $export_base = admin_url('admin-post.php?action=tix_event_report_export&event_id=' . $event_id);
        $nonce = wp_create_nonce('tix_event_report_export_' . $event_id);

        ?>
        <?php // ── Event-Header ── ?>
        <div style="background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-radius:14px;padding:24px 28px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;">
            <div style="flex:1;min-width:260px;">
                <h2 style="margin:0 0 6px;color:#fff;font-size:22px;line-height:1.2;"><?php echo esc_html($event->post_title); ?></h2>
                <div style="font-size:13px;color:#cbd5e1;display:flex;gap:16px;flex-wrap:wrap;">
                    <span><span class="dashicons dashicons-calendar-alt" style="vertical-align:middle;"></span> <?php echo esc_html($date_label); ?><?php if ($time_start): ?> · <?php echo esc_html($time_start); ?> Uhr<?php endif; ?></span>
                    <span><span class="dashicons dashicons-location" style="vertical-align:middle;"></span> <?php echo esc_html($loc_name); ?></span>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="<?php echo esc_url(add_query_arg(['format' => 'csv', '_wpnonce' => $nonce], $export_base)); ?>"
                   style="background:#fff;color:#0f172a;padding:9px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-media-spreadsheet" style="font-size:16px;width:16px;height:16px;"></span> CSV
                </a>
                <a href="<?php echo esc_url(add_query_arg(['format' => 'xlsx', '_wpnonce' => $nonce], $export_base)); ?>"
                   style="background:#10b981;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-media-spreadsheet" style="font-size:16px;width:16px;height:16px;"></span> Excel
                </a>
                <a href="<?php echo esc_url(add_query_arg(['format' => 'pdf', '_wpnonce' => $nonce], $export_base)); ?>"
                   style="background:#dc2626;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-media-document" style="font-size:16px;width:16px;height:16px;"></span> PDF
                </a>
            </div>
        </div>

        <?php // ── KPI-Karten ── ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px;">
            <?php
            $kpis = [
                ['Tickets verkauft', number_format($data['kpis']['sold'], 0, ',', '.'),                 '#3b82f6', 'tickets-alt'],
                ['Brutto-Umsatz',    number_format($data['kpis']['revenue_gross'], 2, ',', '.') . ' €', '#10b981', 'money-alt'],
                ['Netto (ohne MwSt.)', number_format($data['kpis']['revenue_net'], 2, ',', '.') . ' €', '#8b5cf6', 'chart-line'],
                ['Check-in Rate',    $data['kpis']['checkin_rate'] . '%',                               '#f59e0b', 'groups'],
                ['Storniert',        number_format($data['kpis']['cancelled'], 0, ',', '.'),           '#6b7280', 'no-alt'],
            ];
            // Wenn Zahlungs-Gebühren erfasst → 2 zusätzliche KPI-Karten
            if (!empty($data['kpis']['payment_fees']) && $data['kpis']['payment_fees'] > 0) {
                $kpis[] = ['Zahlungs-Gebühren',    '−' . number_format($data['kpis']['payment_fees'], 2, ',', '.') . ' €', '#dc2626', 'money-alt'];
                $kpis[] = ['Netto nach Gebühren', number_format($data['kpis']['revenue_after_fees'], 2, ',', '.') . ' €', '#059669', 'chart-bar'];
            }
            foreach ($kpis as [$label, $value, $color, $icon]):
            ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" style="color:<?php echo esc_attr($color); ?>;"></span>
                        <span style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;"><?php echo esc_html($label); ?></span>
                    </div>
                    <div style="font-size:22px;font-weight:700;color:#0f172a;line-height:1;"><?php echo esc_html($value); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php // ── Pro-Kategorie-Aufschlüsselung mit Progress-Bars + Chart ── ?>
        <?php if (!empty($data['categories'])):
            // Capacity-Warnings sammeln (nur Kategorien mit definierter Kapazitaet)
            $low_cats = array_filter($data['categories'], fn($c) => $c['stock_status'] === 'low');
            $sold_out = array_filter($data['categories'], fn($c) => $c['stock_status'] === 'soldout');
        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 22px;margin-bottom:18px;">
            <h3 style="margin:0 0 14px;font-size:14px;color:#0f172a;display:flex;align-items:center;gap:8px;">
                <span class="dashicons dashicons-category"></span>
                Aufschlüsselung nach Kategorie
            </h3>

            <?php if ($low_cats || $sold_out): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:14px;color:#991b1b;font-size:13px;">
                <strong>⚠️ Achtung:</strong>
                <?php
                $warns = [];
                if ($sold_out) {
                    $warns[] = count($sold_out) . ' Kategorie(n) ausverkauft: ' . esc_html(implode(', ', array_column($sold_out, 'name')));
                }
                if ($low_cats) {
                    $warns[] = count($low_cats) . ' Kategorie(n) fast voll (< 10% Rest): ' . esc_html(implode(', ', array_column($low_cats, 'name')));
                }
                echo implode(' &middot; ', $warns);
                ?>
            </div>
            <?php endif; ?>

            <?php // ── PROGRESS-BARS pro Kategorie (visueller Vergleich) ── ?>
            <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:18px;">
                <?php foreach ($data['categories'] as $cat):
                    $bar_color = '#10b981'; // gruen
                    $bar_label_color = '#065f46';
                    $bar_bg = '#ecfdf5';
                    if ($cat['stock_status'] === 'medium') {
                        $bar_color = '#f59e0b'; // gelb
                        $bar_label_color = '#92400e';
                        $bar_bg = '#fef3c7';
                    } elseif ($cat['stock_status'] === 'low') {
                        $bar_color = '#ef4444'; // rot
                        $bar_label_color = '#991b1b';
                        $bar_bg = '#fef2f2';
                    } elseif ($cat['stock_status'] === 'soldout') {
                        $bar_color = '#9ca3af';
                        $bar_label_color = '#374151';
                        $bar_bg = '#f3f4f6';
                    } elseif ($cat['stock_status'] === 'unlimited') {
                        $bar_color = '#6366f1'; // indigo
                        $bar_label_color = '#3730a3';
                        $bar_bg = '#eef2ff';
                    }
                    // Bar-Width: bei unlimited keine Anzeige, sonst sold_through%
                    $bar_width = $cat['stock_status'] === 'unlimited' ? 0 : min(100, $cat['sold_through']);
                ?>
                <div style="background:<?php echo $bar_bg; ?>;border-radius:10px;padding:12px 14px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <strong style="color:<?php echo $bar_label_color; ?>;font-size:14px;"><?php echo esc_html($cat['name']); ?></strong>
                            <?php if ($cat['stock_status'] === 'soldout'): ?>
                                <span style="background:#9ca3af;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.04em;">AUSVERKAUFT</span>
                            <?php elseif ($cat['stock_status'] === 'low'): ?>
                                <span style="background:#ef4444;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.04em;">FAST VOLL</span>
                            <?php elseif ($cat['stock_status'] === 'unlimited'): ?>
                                <span style="background:#6366f1;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.04em;" title="Keine Kapazität definiert">UNLIMITIERT</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:13px;color:<?php echo $bar_label_color; ?>;font-weight:600;white-space:nowrap;">
                            <?php if ($cat['capacity'] > 0): ?>
                                <?php echo number_format($cat['sold'], 0, ',', '.'); ?> / <?php echo number_format($cat['capacity'], 0, ',', '.'); ?>
                                <span style="opacity:0.7;">(<?php echo $cat['sold_through']; ?>%)</span>
                                &middot; <strong><?php echo number_format($cat['available'], 0, ',', '.'); ?> verfügbar</strong>
                            <?php else: ?>
                                <?php echo number_format($cat['sold'], 0, ',', '.'); ?> verkauft
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($cat['stock_status'] !== 'unlimited'): ?>
                    <div style="height:8px;background:rgba(0,0,0,0.06);border-radius:99px;overflow:hidden;">
                        <div style="height:100%;width:<?php echo $bar_width; ?>%;background:<?php echo $bar_color; ?>;transition:width 0.5s;"></div>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:<?php echo $bar_label_color; ?>;opacity:0.85;margin-top:6px;">
                        <span>✓ <?php echo number_format($cat['checked_in'], 0, ',', '.'); ?> eingecheckt &middot; Ø <?php echo number_format($cat['avg_price'], 2, ',', '.'); ?> €</span>
                        <span><?php echo number_format($cat['revenue'], 2, ',', '.'); ?> € Umsatz</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php // ── BAR-CHART (Vergleich aller Kategorien) ── ?>
            <div style="margin-bottom:18px;">
                <h4 style="margin:0 0 8px;font-size:13px;color:#475569;font-weight:600;">Visueller Vergleich</h4>
                <canvas id="tix-er-cat-chart" height="220"></canvas>
            </div>

            <?php // ── DETAIL-TABELLE (vorhanden, etwas kompakter) ── ?>
            <h4 style="margin:0 0 8px;font-size:13px;color:#475569;font-weight:600;">Detail-Tabelle</h4>
            <table class="widefat striped" style="border:none;">
                <thead>
                    <tr style="background:#f9fafb;">
                        <th>Kategorie</th>
                        <th style="text-align:right;">Verkauft</th>
                        <th style="text-align:right;">Verfügbar</th>
                        <th style="text-align:right;">Eingecheckt</th>
                        <th style="text-align:right;">Ø Preis</th>
                        <th style="text-align:right;">Brutto-Umsatz</th>
                        <th style="text-align:right;">Umsatz-Anteil</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['categories'] as $cat):
                        $share = $data['kpis']['revenue_gross'] > 0
                            ? round($cat['revenue'] / $data['kpis']['revenue_gross'] * 100, 1) : 0;
                        $available_disp = $cat['capacity'] > 0 ? number_format($cat['available'], 0, ',', '.') : '∞';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($cat['name']); ?></strong></td>
                        <td style="text-align:right;"><?php echo number_format($cat['sold'], 0, ',', '.'); ?></td>
                        <td style="text-align:right;<?php echo $cat['stock_status'] === 'low' ? 'color:#ef4444;font-weight:700;' : ''; ?>"><?php echo $available_disp; ?></td>
                        <td style="text-align:right;color:#10b981;font-weight:600;"><?php echo number_format($cat['checked_in'], 0, ',', '.'); ?></td>
                        <td style="text-align:right;"><?php echo number_format($cat['avg_price'], 2, ',', '.'); ?> €</td>
                        <td style="text-align:right;font-weight:600;"><?php echo number_format($cat['revenue'], 2, ',', '.'); ?> €</td>
                        <td style="text-align:right;color:#6b7280;"><?php echo $share; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#f3f4f6;font-weight:700;">
                        <td>SUMME</td>
                        <td style="text-align:right;"><?php echo number_format($data['kpis']['sold'], 0, ',', '.'); ?></td>
                        <td style="text-align:right;">—</td>
                        <td style="text-align:right;color:#10b981;"><?php echo number_format($data['kpis']['checked_in'], 0, ',', '.'); ?></td>
                        <td style="text-align:right;">—</td>
                        <td style="text-align:right;"><?php echo number_format($data['kpis']['revenue_gross'], 2, ',', '.'); ?> €</td>
                        <td style="text-align:right;">100%</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <script>
        (function() {
            if (typeof Chart === 'undefined') return;
            var ctx = document.getElementById('tix-er-cat-chart');
            if (!ctx) return;
            var labels   = <?php echo wp_json_encode(array_column($data['categories'], 'name')); ?>;
            var sold     = <?php echo wp_json_encode(array_column($data['categories'], 'sold')); ?>;
            var avail    = <?php echo wp_json_encode(array_map(fn($c) => $c['capacity'] > 0 ? $c['available'] : 0, $data['categories'])); ?>;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Verkauft', data: sold, backgroundColor: '#10b981', stack: 'cap' },
                        { label: 'Verfügbar', data: avail, backgroundColor: '#e5e7eb', stack: 'cap' }
                    ]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    plugins: { legend: { position: 'bottom' } },
                    scales: {
                        x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                        y: { stacked: true }
                    }
                }
            });
        })();
        </script>
        <?php endif; ?>

        <?php // ── Detail-Tabelle aller Tickets ── ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 22px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                <h3 style="margin:0;font-size:14px;color:#0f172a;display:flex;align-items:center;gap:8px;">
                    <span class="dashicons dashicons-tickets-alt"></span>
                    Verkaufte Tickets (<?php echo count($data['tickets']); ?>)
                </h3>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="text" id="tix-er-search" placeholder="Suchen (Name, Email, Code)…"
                           style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:240px;">
                    <select id="tix-er-cat-filter" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="">Alle Kategorien</option>
                        <?php foreach ($data['categories'] as $c): ?>
                            <option value="<?php echo esc_attr($c['name']); ?>"><?php echo esc_html($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="tix-er-checkin-filter" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="">Alle Tickets</option>
                        <option value="checked">Nur eingecheckt</option>
                        <option value="not_checked">Nur nicht eingecheckt</option>
                        <option value="cancelled">Nur storniert</option>
                    </select>
                </div>
            </div>

            <div style="overflow-x:auto;">
            <table class="widefat striped" id="tix-er-tickets-table" style="border:none;font-size:13px;">
                <thead>
                    <tr style="background:#f9fafb;">
                        <th>Code</th>
                        <th>Käufer</th>
                        <th>Email</th>
                        <th>Kategorie</th>
                        <th style="text-align:right;">Preis</th>
                        <th>Bestellt am</th>
                        <th>Bestell-Nr.</th>
                        <th>Sitzplatz</th>
                        <th>Status</th>
                        <th>Check-in</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['tickets'] as $t): ?>
                        <tr data-cat="<?php echo esc_attr($t['category']); ?>"
                            data-checkin="<?php echo $t['checked_in'] ? 'checked' : 'not_checked'; ?>"
                            data-status="<?php echo esc_attr($t['status']); ?>"
                            data-search="<?php echo esc_attr(strtolower(($t['code'] ?? '') . ' ' . ($t['owner_name'] ?? '') . ' ' . ($t['owner_email'] ?? ''))); ?>">
                            <td><code style="font-size:11px;"><?php echo esc_html($t['code']); ?></code></td>
                            <td><?php echo esc_html($t['owner_name'] ?: '–'); ?></td>
                            <td style="color:#6b7280;font-size:12px;"><?php echo esc_html($t['owner_email']); ?></td>
                            <td><?php echo esc_html($t['category']); ?></td>
                            <td style="text-align:right;"><?php echo number_format($t['price'], 2, ',', '.'); ?> €</td>
                            <td style="color:#6b7280;"><?php echo esc_html($t['order_date']); ?></td>
                            <td>
                                <?php if (!empty($t['order_id'])): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tix-orders&order_id=' . $t['order_id'])); ?>"><?php echo esc_html($t['order_number']); ?></a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><?php echo esc_html($t['seat'] ?: '–'); ?></td>
                            <td>
                                <?php if ($t['status'] === 'cancelled'): ?>
                                    <span style="color:#ef4444;font-weight:600;">Storniert</span>
                                <?php else: ?>
                                    <span style="color:#10b981;">Gültig</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t['checked_in']): ?>
                                    <span style="color:#10b981;font-weight:600;">✓ <?php echo esc_html($t['checkin_time']); ?></span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">–</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($data['tickets'])): ?>
                        <tr><td colspan="10" style="text-align:center;padding:30px;color:#9ca3af;">Keine Tickets verkauft.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <script>
        (function(){
            var search = document.getElementById('tix-er-search');
            var catSel = document.getElementById('tix-er-cat-filter');
            var ciSel  = document.getElementById('tix-er-checkin-filter');
            var rows   = document.querySelectorAll('#tix-er-tickets-table tbody tr[data-search]');

            function applyFilter(){
                var q = (search.value || '').toLowerCase().trim();
                var cat = catSel.value;
                var ci  = ciSel.value;
                rows.forEach(function(r){
                    var hay = r.getAttribute('data-search') || '';
                    var rcat = r.getAttribute('data-cat') || '';
                    var rci  = r.getAttribute('data-checkin') || '';
                    var rstat = r.getAttribute('data-status') || '';
                    var ok = true;
                    if (q && hay.indexOf(q) === -1) ok = false;
                    if (cat && rcat !== cat) ok = false;
                    if (ci === 'checked' && rci !== 'checked') ok = false;
                    if (ci === 'not_checked' && rci !== 'not_checked') ok = false;
                    if (ci === 'cancelled' && rstat !== 'cancelled') ok = false;
                    r.style.display = ok ? '' : 'none';
                });
            }
            search.addEventListener('input', applyFilter);
            catSel.addEventListener('change', applyFilter);
            ciSel.addEventListener('change', applyFilter);
        })();
        </script>
        <?php
    }

    // ═══════════════════════════════════════════
    // Datenquelle
    // ═══════════════════════════════════════════

    /**
     * Resolved eine Order-ID zu der zugehörigen tix_orders-Row.
     * Strategie:
     *  1. Match per `tix_orders.id = X` (native Order)
     *  2. Match per `tix_orders.wc_order_id = X` (migrierte WC-Order, _tix_ticket_order_id ist die WC-Post-ID)
     *  3. WC aktiv UND nichts gefunden → wc_get_order(X) als zusätzliche Quelle
     *
     * Returns: ['row' => stdClass|null, 'ticket_count' => int]
     */
    private static function resolve_order($order_id_meta) {
        global $wpdb;
        $t  = $wpdb->prefix . 'tix_orders';
        $pm = $wpdb->postmeta;

        // 1) Match per id (native Order)
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, order_number, date_created, total, wc_order_id, status FROM $t WHERE id = %d",
            $order_id_meta
        ));

        // 2) Fallback: Match per wc_order_id (migrierte WC-Order)
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, order_number, date_created, total, wc_order_id, status FROM $t WHERE wc_order_id = %d",
                $order_id_meta
            ));
        }

        // 3) WC-aktiv: Total aus WC-Order übernehmen wenn tix_orders.total = 0
        if ($row && floatval($row->total) <= 0 && $row->wc_order_id && function_exists('wc_get_order')) {
            $wc_order = wc_get_order(intval($row->wc_order_id));
            if ($wc_order) {
                $row->total = floatval($wc_order->get_total());
            }
        }

        // 4) Wenn keine tix_orders-Row und WC aktiv → on-the-fly aus WC bauen
        if (!$row && function_exists('wc_get_order')) {
            $wc_order = wc_get_order($order_id_meta);
            if ($wc_order) {
                $row = (object) [
                    'id'           => 0,
                    'order_number' => $wc_order->get_order_number(),
                    'date_created' => $wc_order->get_date_created() ? $wc_order->get_date_created()->date('Y-m-d H:i:s') : '',
                    'total'        => floatval($wc_order->get_total()),
                    'wc_order_id'  => $order_id_meta,
                    'status'       => preg_replace('/^wc-/', '', $wc_order->get_status()),
                ];
            }
        }

        // Anzahl Tickets für diese Order — nutzen für Preis-Pro-Ticket-Ableitung
        $ticket_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN $pm pm ON pm.post_id = p.ID AND pm.meta_key = '_tix_ticket_order_id'
             WHERE p.post_type = 'tix_ticket' AND p.post_status = 'publish'
               AND pm.meta_value = %s
               AND NOT EXISTS (
                   SELECT 1 FROM $pm sm WHERE sm.post_id = p.ID AND sm.meta_key = '_tix_ticket_status' AND sm.meta_value = 'cancelled'
               )",
            (string) $order_id_meta
        )));

        return [
            'row'          => $row,
            'ticket_count' => $ticket_count ?: 1,
        ];
    }

    private static function get_events_for_picker($time_filter = 'all') {
        $today = current_time('Y-m-d');
        $args = [
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft', 'private'],
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'DESC',
        ];

        if ($time_filter === 'past') {
            $args['meta_query'] = [['key' => '_tix_date_start', 'value' => $today, 'compare' => '<', 'type' => 'DATE']];
        } elseif ($time_filter === 'upcoming') {
            $args['meta_query'] = [['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE']];
            $args['order'] = 'ASC';
        }

        $posts = get_posts($args);
        $list = [];
        foreach ($posts as $p) {
            $date = get_post_meta($p->ID, '_tix_date_start', true);
            $list[] = [
                'id'         => $p->ID,
                'title'      => $p->post_title,
                'date'       => $date,
                'date_label' => $date ? date_i18n('d.m.Y', strtotime($date)) : '—',
                'is_past'    => $date && $date < $today,
            ];
        }
        return $list;
    }

    /**
     * Zentrales Daten-Aggregat für ein Event.
     * Returns: ['kpis' => [...], 'categories' => [...], 'tickets' => [...]]
     */
    public static function get_event_data($event_id) {
        global $wpdb;

        // Tickets aus tix_ticket CPT laden (mit zugehörigen Order-Daten)
        $tickets_q = new WP_Query([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => [
                ['key' => '_tix_ticket_event_id', 'value' => $event_id],
            ],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        $tickets    = [];
        $cat_stats  = [];
        $sold       = 0;
        $cancelled  = 0;
        $checked_in = 0;
        $revenue    = 0.0;

        // Zahlungs-Gebühren-Summe (PayPal etc.) — pro Order nur EINMAL gezählt
        $payment_fees = 0.0;
        $orders_with_fees = []; // Tracking welche Order-IDs schon gezählt wurden

        // Order-Resolver-Cache: order_id_meta → tix_orders-Row + Tickets-Count für diese Order
        // Spart wiederholte DB-Queries pro Event
        $order_cache = [];

        foreach ($tickets_q->posts as $tp) {
            $tid = $tp->ID;
            $code      = get_post_meta($tid, '_tix_ticket_code', true);
            $status    = get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid';
            $cat_name  = get_post_meta($tid, '_tix_ticket_cat_name', true);
            $cat_index = get_post_meta($tid, '_tix_ticket_cat_index', true);
            $price     = floatval(get_post_meta($tid, '_tix_ticket_price', true));
            $owner_n   = get_post_meta($tid, '_tix_ticket_owner_name', true);
            $owner_e   = get_post_meta($tid, '_tix_ticket_owner_email', true);
            $checked   = (bool) get_post_meta($tid, '_tix_ticket_checked_in', true);
            $ci_time   = get_post_meta($tid, '_tix_ticket_checkin_time', true);
            $seat_id   = get_post_meta($tid, '_tix_ticket_seat_id', true);
            $order_id_meta = intval(get_post_meta($tid, '_tix_ticket_order_id', true));

            // ── Order auflösen (matcht id ODER wc_order_id für migrierte Tickets) ──
            $order_row     = null;
            $tickets_in_order = 1;
            if ($order_id_meta) {
                if (!isset($order_cache[$order_id_meta])) {
                    $order_cache[$order_id_meta] = self::resolve_order($order_id_meta);
                }
                $order_row        = $order_cache[$order_id_meta]['row'];
                $tickets_in_order = max(1, intval($order_cache[$order_id_meta]['ticket_count']));
            }

            // ── Preis-Fallback: aus Order ableiten falls Ticket-Meta leer ──
            if ($price <= 0 && $order_row) {
                $order_total = floatval($order_row->total);
                if ($order_total > 0 && $tickets_in_order > 0) {
                    // Total / Anzahl-Tickets-dieser-Order = durchschnittlicher Ticket-Preis
                    $price = round($order_total / $tickets_in_order, 2);
                }
            }

            // ── cat_name-Fallback ──
            if (!$cat_name && $cat_index !== '' && $cat_index !== false) {
                $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
                $idx = intval($cat_index);
                if (is_array($cats) && isset($cats[$idx])) {
                    $cat_name = $cats[$idx]['name'] ?? '';
                }
            }
            if (!$cat_name) {
                // Fallback: erste Event-Kategorie als Default für migrierte Tickets ohne Zuordnung
                $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
                if (is_array($cats) && !empty($cats)) {
                    $cat_name = $cats[0]['name'] ?? '–';
                } else {
                    $cat_name = '–';
                }
            }

            // ── Order-Datum + Nummer aus aufgelöster Order ──
            $order_date   = '';
            $order_number = '';
            $real_order_id = 0;
            if ($order_row) {
                $real_order_id = intval($order_row->id);
                $order_number  = $order_row->order_number;
                $order_date    = $order_row->date_created ? date_i18n('d.m.Y H:i', strtotime($order_row->date_created)) : '';
            }
            if (!$order_date) {
                $order_date = $tp->post_date ? date_i18n('d.m.Y H:i', strtotime($tp->post_date)) : '';
            }
            // Für Detail-Tabelle: tix_orders.id (zum Verlinken zur Order-Detail-Seite)
            $order_id = $real_order_id ?: $order_id_meta;

            // Zahlungs-Gebühr für diese Order summieren (nur EINMAL pro Order)
            if ($real_order_id && !isset($orders_with_fees[$real_order_id])) {
                $orders_with_fees[$real_order_id] = true;
                $fee = floatval(get_post_meta($real_order_id, '_tix_payment_fee', true));
                if ($fee > 0) {
                    $payment_fees += $fee;
                }
            }

            // Sitzplatz menschenlesbar
            $seat_label = '';
            if ($seat_id) {
                $seat_label = is_numeric($seat_id) ? get_post_meta(intval($seat_id), '_tix_seat_label', true) : (string) $seat_id;
                if (!$seat_label) $seat_label = (string) $seat_id;
            }

            $is_cancelled = ($status === 'cancelled');

            // KPIs
            if ($is_cancelled) {
                $cancelled++;
            } else {
                $sold++;
                $revenue += $price;
                if ($checked) $checked_in++;
            }

            // Kategorie-Stats (nur gültige Tickets zählen)
            if (!isset($cat_stats[$cat_name])) {
                $cat_stats[$cat_name] = ['name' => $cat_name, 'sold' => 0, 'checked_in' => 0, 'revenue' => 0.0];
            }
            if (!$is_cancelled) {
                $cat_stats[$cat_name]['sold']++;
                $cat_stats[$cat_name]['revenue'] += $price;
                if ($checked) $cat_stats[$cat_name]['checked_in']++;
            }

            $tickets[] = [
                'id'           => $tid,
                'code'         => $code,
                'owner_name'   => $owner_n,
                'owner_email'  => $owner_e,
                'category'     => $cat_name,
                'price'        => $price,
                'order_id'     => $order_id,
                'order_number' => $order_number ?: ('—'),
                'order_date'   => $order_date,
                'seat'         => $seat_label,
                'status'       => $status,
                'checked_in'   => $checked,
                'checkin_time' => $ci_time ? date_i18n('d.m. H:i', strtotime($ci_time)) : '',
            ];
        }

        // ── Kapazitaeten aus Event-Meta ladern (Kategorie-Definition mit qty) ──
        $event_cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($event_cats)) $event_cats = [];
        $cap_by_name = [];
        foreach ($event_cats as $ec) {
            $n = trim((string) ($ec['name'] ?? ''));
            if (!$n) continue;
            $cap_by_name[$n] = intval($ec['qty'] ?? 0);
            // Auch Kategorie-Defs einbeziehen, die noch keine Tickets haben (verkauft = 0)
            if (!isset($cat_stats[$n])) {
                $cat_stats[$n] = ['name' => $n, 'sold' => 0, 'checked_in' => 0, 'revenue' => 0.0];
            }
        }

        // Kategorie-Liste finalisieren (capacity, available, sold_through, avg_price + sortieren)
        $cat_list = [];
        foreach ($cat_stats as $c) {
            $c['avg_price']        = $c['sold'] > 0 ? $c['revenue'] / $c['sold'] : 0;
            $c['capacity']         = intval($cap_by_name[$c['name']] ?? 0);
            $c['available']        = max(0, $c['capacity'] - $c['sold']);
            $c['sold_through']     = $c['capacity'] > 0
                ? round(($c['sold'] / $c['capacity']) * 100, 1)
                : 0;
            // Restkapazitaets-Status (fuer UI-Faerbung): sold-through 0-50% gruen, 50-90% gelb, 90-100% rot
            if ($c['capacity'] === 0) {
                $c['stock_status'] = 'unlimited'; // Keine Kapazitaet definiert
            } elseif ($c['available'] === 0) {
                $c['stock_status'] = 'soldout';
            } elseif ($c['sold_through'] >= 90) {
                $c['stock_status'] = 'low';      // < 10% verfuegbar
            } elseif ($c['sold_through'] >= 50) {
                $c['stock_status'] = 'medium';
            } else {
                $c['stock_status'] = 'high';
            }
            $cat_list[] = $c;
        }
        usort($cat_list, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        // MwSt aus Settings (Fallback 19%)
        $vat_rate = floatval(tix_get_settings('vat_rate') ?: 19);
        $revenue_net = $revenue / (1 + $vat_rate / 100);

        $checkin_rate = $sold > 0 ? round($checked_in / $sold * 100, 1) : 0;

        return [
            'event_id'   => $event_id,
            'kpis'       => [
                'sold'                   => $sold,
                'cancelled'              => $cancelled,
                'checked_in'             => $checked_in,
                'checkin_rate'           => $checkin_rate,
                'revenue_gross'          => round($revenue, 2),
                'revenue_net'            => round($revenue_net, 2),
                'vat_rate'               => $vat_rate,
                'payment_fees'           => round($payment_fees, 2),
                'revenue_after_fees'     => round($revenue - $payment_fees, 2),
                'revenue_net_after_fees' => round($revenue_net - $payment_fees, 2),
            ],
            'categories' => $cat_list,
            'tickets'    => $tickets,
        ];
    }

    // ═══════════════════════════════════════════
    // Export Router
    // ═══════════════════════════════════════════

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        $event_id = intval($_GET['event_id'] ?? 0);
        $format   = sanitize_text_field($_GET['format'] ?? 'csv');
        $nonce    = sanitize_text_field($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'tix_event_report_export_' . $event_id)) {
            wp_die('Ungültige Sicherheitsprüfung.');
        }

        $data  = self::get_event_data($event_id);
        $event = get_post($event_id);
        $title = $event ? $event->post_title : 'Event';
        $slug  = sanitize_file_name(strtolower(str_replace(' ', '-', $title)));
        $datestr = date('Y-m-d');

        switch ($format) {
            case 'xlsx':
                self::export_xlsx($data, $event, "tix-bericht-{$slug}-{$datestr}.xlsx");
                break;
            case 'pdf':
                self::export_pdf($data, $event, "tix-bericht-{$slug}-{$datestr}.pdf");
                break;
            case 'csv':
            default:
                self::export_csv($data, $event, "tix-bericht-{$slug}-{$datestr}.csv");
                break;
        }
        exit;
    }

    // ═══════════════════════════════════════════
    // CSV Export
    // ═══════════════════════════════════════════

    private static function export_csv($data, $event, $filename) {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // BOM für Excel-Umlaute
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Aussteller-Block ganz oben (Steuerberater-freundlich)
        $issuer = self::format_issuer_block();
        if (!empty($issuer)) {
            fputcsv($out, ['AUSSTELLER'], ';');
            foreach ($issuer as $line) fputcsv($out, [$line], ';');
            fputcsv($out, [], ';');
        }

        // Event-Header
        fputcsv($out, ['Event:', $event ? $event->post_title : ''], ';');
        fputcsv($out, ['Datum (Leistung):', $event ? (get_post_meta($event->ID, '_tix_date_start', true) ?: '') : ''], ';');
        fputcsv($out, ['Bericht erstellt:', date_i18n('d.m.Y H:i')], ';');
        fputcsv($out, [], ';');

        // KPIs
        fputcsv($out, ['Tickets verkauft', $data['kpis']['sold']], ';');
        fputcsv($out, ['Storniert',        $data['kpis']['cancelled']], ';');
        fputcsv($out, ['Eingecheckt',      $data['kpis']['checked_in']], ';');
        fputcsv($out, ['Check-in Rate',    $data['kpis']['checkin_rate'] . '%'], ';');
        fputcsv($out, ['Brutto-Umsatz',    number_format($data['kpis']['revenue_gross'], 2, ',', '.') . ' EUR'], ';');
        fputcsv($out, ['Netto-Umsatz (vor Geb.)', number_format($data['kpis']['revenue_net'], 2, ',', '.') . ' EUR'], ';');
        if (!empty($data['kpis']['payment_fees']) && $data['kpis']['payment_fees'] > 0) {
            fputcsv($out, ['Zahlungs-Gebühren',         '-' . number_format($data['kpis']['payment_fees'], 2, ',', '.') . ' EUR'], ';');
            fputcsv($out, ['Brutto nach Gebühren',      number_format($data['kpis']['revenue_after_fees'], 2, ',', '.') . ' EUR'], ';');
            fputcsv($out, ['Netto nach Gebühren',       number_format($data['kpis']['revenue_net_after_fees'], 2, ',', '.') . ' EUR'], ';');
        }
        fputcsv($out, [], ';');

        // Steueraufschlüsselung
        $vat_rate = floatval($data['kpis']['vat_rate'] ?: 19);
        $brutto   = floatval($data['kpis']['revenue_gross']);
        $netto    = floatval($data['kpis']['revenue_net']);
        $vat_amt  = round($brutto - $netto, 2);
        fputcsv($out, ['STEUERLICHE AUFSCHLÜSSELUNG'], ';');
        fputcsv($out, ['Steuersatz', 'Netto EUR', 'USt-Betrag EUR', 'Brutto EUR'], ';');
        fputcsv($out, [
            number_format($vat_rate, 0, ',', '.') . '%',
            number_format($netto, 2, ',', '.'),
            number_format($vat_amt, 2, ',', '.'),
            number_format($brutto, 2, ',', '.'),
        ], ';');
        fputcsv($out, [], ';');

        // Kategorien
        fputcsv($out, ['KATEGORIEN'], ';');
        fputcsv($out, ['Kategorie', 'Verkauft', 'Eingecheckt', 'Ø Preis EUR', 'Brutto-Umsatz EUR'], ';');
        foreach ($data['categories'] as $c) {
            fputcsv($out, [
                $c['name'],
                $c['sold'],
                $c['checked_in'],
                number_format($c['avg_price'], 2, ',', '.'),
                number_format($c['revenue'], 2, ',', '.'),
            ], ';');
        }
        fputcsv($out, [], ';');

        // Detail-Tickets
        fputcsv($out, ['DETAILS — VERKAUFTE TICKETS'], ';');
        fputcsv($out, ['Ticket-Code', 'Käufer', 'Email', 'Kategorie', 'Preis EUR', 'Bestellt am', 'Bestell-Nr.', 'Sitzplatz', 'Status', 'Check-in', 'Check-in Zeit'], ';');
        foreach ($data['tickets'] as $t) {
            fputcsv($out, [
                $t['code'],
                $t['owner_name'],
                $t['owner_email'],
                $t['category'],
                number_format($t['price'], 2, ',', '.'),
                $t['order_date'],
                $t['order_number'],
                $t['seat'],
                $t['status'] === 'cancelled' ? 'Storniert' : 'Gültig',
                $t['checked_in'] ? 'Ja' : 'Nein',
                $t['checkin_time'],
            ], ';');
        }

        // Compliance-Hinweis
        fputcsv($out, [], ';');
        fputcsv($out, ['Hinweis: Aufbewahrungspflicht 10 Jahre gemäß § 147 AO / GoBD. Dieser Bericht ist eine Auswertung — keine einzelne Rechnung.'], ';');

        fclose($out);
    }

    // ═══════════════════════════════════════════
    // XLSX Export — nativer Writer (ohne Library)
    // ═══════════════════════════════════════════

    private static function export_xlsx($data, $event, $filename) {
        if (!class_exists('ZipArchive')) {
            self::export_csv($data, $event, str_replace('.xlsx', '.csv', $filename));
            return;
        }

        // Daten zusammenstellen: Aussteller + Header + KPIs + Steuer + Kategorien + Tickets
        $rows = [];

        // Aussteller-Block (Steuerberater liest's zuerst)
        $issuer = self::format_issuer_block();
        if (!empty($issuer)) {
            $rows[] = ['AUSSTELLER'];
            foreach ($issuer as $line) $rows[] = [$line];
            $rows[] = [];
        }

        $rows[] = ['Event-Bericht: ' . ($event ? $event->post_title : '')];
        $rows[] = ['Datum (Leistung): ' . ($event ? (get_post_meta($event->ID, '_tix_date_start', true) ?: '') : '')];
        $rows[] = ['Erstellt am ' . date_i18n('d.m.Y H:i')];
        $rows[] = [];
        $rows[] = ['Kennzahl', 'Wert'];
        $rows[] = ['Tickets verkauft',   $data['kpis']['sold']];
        $rows[] = ['Storniert',          $data['kpis']['cancelled']];
        $rows[] = ['Eingecheckt',        $data['kpis']['checked_in']];
        $rows[] = ['Check-in Rate',      $data['kpis']['checkin_rate'] . '%'];
        $rows[] = ['Brutto-Umsatz EUR',          round($data['kpis']['revenue_gross'], 2)];
        $rows[] = ['Netto-Umsatz EUR (vor Geb.)', round($data['kpis']['revenue_net'], 2)];
        if (!empty($data['kpis']['payment_fees']) && $data['kpis']['payment_fees'] > 0) {
            $rows[] = ['Zahlungs-Gebühren EUR',       round($data['kpis']['payment_fees'], 2)];
            $rows[] = ['Brutto nach Gebühren EUR',    round($data['kpis']['revenue_after_fees'], 2)];
            $rows[] = ['Netto nach Gebühren EUR',     round($data['kpis']['revenue_net_after_fees'], 2)];
        }
        $rows[] = [];

        // Steueraufschlüsselung
        $vat_rate = floatval($data['kpis']['vat_rate'] ?: 19);
        $brutto   = floatval($data['kpis']['revenue_gross']);
        $netto    = floatval($data['kpis']['revenue_net']);
        $vat_amt  = round($brutto - $netto, 2);
        $rows[] = ['STEUERLICHE AUFSCHLÜSSELUNG'];
        $rows[] = ['Steuersatz', 'Netto EUR', 'USt-Betrag EUR', 'Brutto EUR'];
        $rows[] = [
            number_format($vat_rate, 0, ',', '.') . '%',
            round($netto, 2),
            $vat_amt,
            round($brutto, 2),
        ];
        $rows[] = [];

        $rows[] = ['KATEGORIEN'];
        $rows[] = ['Kategorie', 'Verkauft', 'Eingecheckt', 'Ø Preis EUR', 'Brutto-Umsatz EUR'];
        foreach ($data['categories'] as $c) {
            $rows[] = [
                $c['name'],
                $c['sold'],
                $c['checked_in'],
                round($c['avg_price'], 2),
                round($c['revenue'], 2),
            ];
        }
        $rows[] = [];

        $rows[] = ['DETAILS - VERKAUFTE TICKETS'];
        $rows[] = ['Ticket-Code', 'Käufer', 'Email', 'Kategorie', 'Preis EUR', 'Bestellt am', 'Bestell-Nr.', 'Sitzplatz', 'Status', 'Check-in', 'Check-in Zeit'];
        foreach ($data['tickets'] as $t) {
            $rows[] = [
                $t['code'],
                $t['owner_name'],
                $t['owner_email'],
                $t['category'],
                round($t['price'], 2),
                $t['order_date'],
                $t['order_number'],
                $t['seat'],
                $t['status'] === 'cancelled' ? 'Storniert' : 'Gültig',
                $t['checked_in'] ? 'Ja' : 'Nein',
                $t['checkin_time'],
            ];
        }

        // Compliance-Hinweis am Ende
        $rows[] = [];
        $rows[] = ['Hinweis: Aufbewahrungspflicht 10 Jahre gemäß § 147 AO / GoBD. Dieser Bericht ist eine Auswertung — keine einzelne Rechnung.'];

        // XLSX = ZIP mit XML-Files
        $tmp = wp_tempnam('tix-xlsx-');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            self::export_csv($data, $event, str_replace('.xlsx', '.csv', $filename));
            return;
        }

        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' .
            '</Types>');

        // _rels/.rels
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');

        // xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' .
            '</Relationships>');

        // xl/workbook.xml
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="Bericht" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>');

        // xl/styles.xml — minimal: 0=normal, 1=bold, 2=currency
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;"/></numFmts>' .
            '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
            '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' .
            '<borders count="1"><border/></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0"/></cellStyleXfs>' .
            '<cellXfs count="3">' .
                '<xf numFmtId="0" fontId="0"/>' .
                '<xf numFmtId="0" fontId="1" applyFont="1"/>' .
                '<xf numFmtId="164" fontId="0" applyNumberFormat="1"/>' .
            '</cellXfs>' .
            '</styleSheet>');

        // Strings → SharedStrings sammeln
        $strings_index = [];
        $strings_list  = [];
        $get_string_idx = function($s) use (&$strings_index, &$strings_list) {
            $s = (string) $s;
            if (!isset($strings_index[$s])) {
                $strings_index[$s] = count($strings_list);
                $strings_list[] = $s;
            }
            return $strings_index[$s];
        };

        // sheet1.xml
        $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>';
        foreach ($rows as $ri => $row) {
            $r = $ri + 1;
            $sheet_xml .= '<row r="' . $r . '">';
            foreach ($row as $ci => $val) {
                $col = self::xlsx_col_letter($ci + 1);
                $ref = $col . $r;
                if (is_numeric($val) && !is_string($val) && $val !== '' && !preg_match('/^0\d/', (string) $val)) {
                    // Numerisch: direkt schreiben (Style 2 für €-Formate setzen wir nur bei Spalten Preis/Umsatz)
                    $is_money = self::is_money_column($ri, $ci, $row);
                    $style = $is_money ? ' s="2"' : '';
                    $sheet_xml .= '<c r="' . $ref . '"' . $style . '><v>' . $val . '</v></c>';
                } else {
                    // String → SharedString-Index
                    $idx = $get_string_idx($val);
                    // Bold-Style für komplette Header-Zeilen
                    $bold = (strpos($val, 'KATEGORIEN') === 0 || strpos($val, 'DETAILS') === 0 || strpos($val, 'Event-Bericht') === 0 || strpos($val, 'Kennzahl') === 0)
                        ? ' s="1"' : '';
                    $sheet_xml .= '<c r="' . $ref . '" t="s"' . $bold . '><v>' . $idx . '</v></c>';
                }
            }
            $sheet_xml .= '</row>';
        }
        $sheet_xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);

        // sharedStrings.xml
        $ss_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings_list) . '" uniqueCount="' . count($strings_list) . '">';
        foreach ($strings_list as $s) {
            $ss_xml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
        }
        $ss_xml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ss_xml);

        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    private static function xlsx_col_letter($n) {
        $s = '';
        while ($n > 0) {
            $r = ($n - 1) % 26;
            $s = chr(65 + $r) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    private static function is_money_column($row_idx, $col_idx, $row) {
        // Heuristik: Header-Zeilen mit "EUR" → die nachfolgenden numerischen Werte mit Money-Format
        // Konservativ: nur Cells in den bekannten Money-Spalten der Detail-Tabelle (Preis, Umsatz)
        return false; // keep simple — Standard-Number genügt; Excel zeigt sauber an
    }

    // ═══════════════════════════════════════════
    // PDF Export — nativer Writer (Helvetica)
    // ═══════════════════════════════════════════

    private static function export_pdf($data, $event, $filename) {
        // A4 Querformat: 842 x 595 Points
        $page_w = 842;
        $page_h = 595;
        $margin_x = 36;
        $margin_y = 40;

        $pdf = new TIX_Simple_PDF($page_w, $page_h, $margin_x, $margin_y);

        // System-Logo laden (gleiche Quelle wie Dashboard oben links)
        $logo_url = tix_get_settings('admin_logo_url');
        $logo_loaded = false;
        if ($logo_url) {
            $logo_loaded = $pdf->load_logo($logo_url);
        }

        // Titel
        $brand = tix_get_settings('email_brand_name') ?: get_bloginfo('name');
        $title = $event ? $event->post_title : 'Event-Bericht';
        $date_label = $event ? (get_post_meta($event->ID, '_tix_date_start', true) ?: '') : '';
        if ($date_label) $date_label = date_i18n('l, d. F Y', strtotime($date_label));

        $pdf->add_page();

        // Header-Block (mehr vertikaler Abstand zwischen den Zeilen)
        $pdf->set_font('Helvetica-Bold', 18);
        $pdf->text(36, 550, 'Event-Bericht');
        $pdf->set_font('Helvetica', 10);
        $pdf->set_color(0.4, 0.4, 0.4);
        $pdf->text(36, 530, $brand . ' · erstellt am ' . date_i18n('d.m.Y H:i'));

        $pdf->set_color(0, 0, 0);
        $pdf->set_font('Helvetica-Bold', 14);
        $pdf->text(36, 500, $title);
        $pdf->set_font('Helvetica', 10);
        $pdf->set_color(0.3, 0.3, 0.3);
        if ($date_label) $pdf->text(36, 482, $date_label);

        // KPIs — bei aktiven Zahlungs-Gebühren 7 Boxen (2 Reihen), sonst 5 (eine Reihe)
        $has_fees = !empty($data['kpis']['payment_fees']) && $data['kpis']['payment_fees'] > 0;
        $box_y = 410;
        $box_h = 50;
        $box_w = 150;
        $box_x = 36;
        $kpi_items = [
            ['Tickets verkauft', number_format($data['kpis']['sold'], 0, ',', '.')],
            ['Brutto-Umsatz',    number_format($data['kpis']['revenue_gross'], 2, ',', '.') . ' EUR'],
            ['Netto-Umsatz',     number_format($data['kpis']['revenue_net'], 2, ',', '.') . ' EUR'],
            ['Check-in Rate',    $data['kpis']['checkin_rate'] . '%'],
            ['Storniert',        number_format($data['kpis']['cancelled'], 0, ',', '.')],
        ];
        if ($has_fees) {
            $kpi_items[] = ['Zahlungs-Gebühren',  '-' . number_format($data['kpis']['payment_fees'], 2, ',', '.') . ' EUR'];
            $kpi_items[] = ['Netto nach Gebühren', number_format($data['kpis']['revenue_after_fees'], 2, ',', '.') . ' EUR'];
        }
        foreach ($kpi_items as $i => $kpi) {
            // Layout: max 5 Boxen pro Reihe, danach Umbruch
            $col = $i % 5;
            $row = intdiv($i, 5);
            $x = $box_x + ($box_w + 8) * $col;
            $y = $box_y - ($row * ($box_h + 8));
            $pdf->set_color(0.95, 0.95, 0.97);
            $pdf->rect($x, $y, $box_w, $box_h, true);
            $pdf->set_color(0.4, 0.4, 0.4);
            $pdf->set_font('Helvetica', 8);
            $pdf->text($x + 8, $y + $box_h - 12, strtoupper($kpi[0]));
            $pdf->set_color(0, 0, 0);
            $pdf->set_font('Helvetica-Bold', 13);
            $pdf->text($x + 8, $y + 12, $kpi[1]);
        }
        // Wenn 2 Reihen → Y-Cursor entsprechend nach unten verschieben
        if ($has_fees) {
            $box_y = $box_y - ($box_h + 8); // Eine Reihe weiter runter für nachfolgende Sektion
        }

        // Kategorien-Tabelle
        $y = $box_y - 30;
        $pdf->set_color(0, 0, 0);
        $pdf->set_font('Helvetica-Bold', 11);
        $pdf->text(36, $y, 'Aufschlüsselung nach Kategorie');
        $y -= 18;

        $cat_headers = ['Kategorie', 'Verkauft', 'Check-in', 'Ø Preis', 'Umsatz', 'Anteil'];
        $cat_widths  = [180, 80, 80, 80, 100, 80];
        $pdf->draw_table_header($cat_headers, $cat_widths, 36, $y);
        $y -= 18;

        $pdf->set_font('Helvetica', 10);
        foreach ($data['categories'] as $cat) {
            $share = $data['kpis']['revenue_gross'] > 0 ? round($cat['revenue'] / $data['kpis']['revenue_gross'] * 100, 1) : 0;
            $pdf->draw_table_row([
                $cat['name'],
                number_format($cat['sold'], 0, ',', '.'),
                number_format($cat['checked_in'], 0, ',', '.'),
                number_format($cat['avg_price'], 2, ',', '.') . ' EUR',
                number_format($cat['revenue'], 2, ',', '.') . ' EUR',
                $share . '%',
            ], $cat_widths, 36, $y);
            $y -= 16;
            if ($y < 200) {
                $pdf->add_page();
                $y = $page_h - 50;
            }
        }

        // ── Steueraufschlüsselung + Aussteller-Block ──
        $y -= 30; // Gap nach Kategorien-Tabelle

        // Falls zu wenig Platz: neue Seite
        if ($y < 220) {
            $pdf->add_page();
            $y = $page_h - 50;
        }

        $vat_rate = floatval($data['kpis']['vat_rate'] ?: 19);
        $brutto   = floatval($data['kpis']['revenue_gross']);
        $netto    = floatval($data['kpis']['revenue_net']);
        $vat_amt  = round($brutto - $netto, 2);

        $pdf->set_font('Helvetica-Bold', 12);
        $pdf->set_color(0, 0, 0);
        $pdf->text(36, $y, 'Steuerliche Aufschlüsselung');
        $y -= 20;

        $tax_headers = ['Steuersatz', 'Netto', 'USt-Betrag', 'Brutto'];
        $tax_widths  = [120, 140, 140, 160];
        $pdf->draw_table_header($tax_headers, $tax_widths, 36, $y);
        $y -= 22;

        $pdf->set_font('Helvetica', 10);
        $pdf->draw_table_row([
            number_format($vat_rate, 0, ',', '.') . ' %',
            number_format($netto, 2, ',', '.') . ' EUR',
            number_format($vat_amt, 2, ',', '.') . ' EUR',
            number_format($brutto, 2, ',', '.') . ' EUR',
        ], $tax_widths, 36, $y);
        $y -= 18;

        // Summen-Linie
        $pdf->set_color(0.85, 0.85, 0.87);
        $pdf->rect(36, $y + 8, array_sum($tax_widths), 0.5, true);
        $y -= 4;
        $pdf->set_color(0, 0, 0);
        $pdf->set_font('Helvetica-Bold', 10);
        $pdf->draw_table_row([
            'Gesamt',
            number_format($netto, 2, ',', '.') . ' EUR',
            number_format($vat_amt, 2, ',', '.') . ' EUR',
            number_format($brutto, 2, ',', '.') . ' EUR',
        ], $tax_widths, 36, $y);
        $y -= 28;

        // ── Aussteller-Block ──
        $issuer = self::format_issuer_block();
        if (!empty($issuer)) {
            // Falls zu wenig Platz: neue Seite
            $needed = 14 + count($issuer) * 13 + 25;
            if ($y < 50 + $needed) {
                $pdf->add_page();
                $y = $page_h - 50;
            }
            $pdf->set_font('Helvetica-Bold', 11);
            $pdf->set_color(0, 0, 0);
            $pdf->text(36, $y, 'Aussteller');
            $y -= 16;
            $pdf->set_font('Helvetica', 9);
            $pdf->set_color(0.25, 0.25, 0.28);
            foreach ($issuer as $line) {
                $pdf->text(36, $y, $line);
                $y -= 13;
            }
            $y -= 8;
            $pdf->set_font('Helvetica', 8);
            $pdf->set_color(0.55, 0.55, 0.55);
            $pdf->text(36, $y, 'Aufbewahrungspflicht: 10 Jahre gemäß § 147 AO / GoBD. Dieses Dokument ist eine Auswertung — keine einzelne Rechnung.');
        }

        // ── Detail-Tickets (neue Seite) ──
        $pdf->add_page();
        $y = $page_h - 50;
        $pdf->set_font('Helvetica-Bold', 16);
        $pdf->set_color(0, 0, 0);
        $pdf->text(36, $y, 'Verkaufte Tickets — Detailliste');
        $y -= 22; // mehr Gap zwischen Title und Subline (vorher 8 → 22)

        $pdf->set_font('Helvetica', 10);
        $pdf->set_color(0.4, 0.4, 0.4);
        $pdf->text(36, $y, count($data['tickets']) . ' Tickets · Event: ' . $title);
        $y -= 28; // mehr Gap zwischen Subline und Tabellen-Header (vorher 14 → 28)

        $headers = ['Code', 'Käufer', 'Email', 'Kat.', 'Preis', 'Bestellt', 'Bestell-Nr.', 'Status', 'Check-in'];
        $widths  = [78, 110, 140, 60, 50, 70, 95, 50, 50];
        $pdf->set_color(0, 0, 0);
        $pdf->draw_table_header($headers, $widths, 36, $y);
        $y -= 22; // mehr Gap zwischen Header und erster Datenzeile (vorher 16 → 22)

        $pdf->set_font('Helvetica', 8);
        foreach ($data['tickets'] as $t) {
            // Truncate für lange Werte
            $row = [
                self::trunc($t['code'], 12),
                self::trunc($t['owner_name'] ?: '–', 18),
                self::trunc($t['owner_email'], 24),
                self::trunc($t['category'], 9),
                number_format($t['price'], 2, ',', '.'),
                self::trunc($t['order_date'], 12),
                self::trunc($t['order_number'], 16),
                $t['status'] === 'cancelled' ? 'Storno' : 'Gültig',
                $t['checked_in'] ? 'Ja' : '–',
            ];
            $pdf->draw_table_row($row, $widths, 36, $y);
            $y -= 13;
            if ($y < 50) {
                $pdf->add_page();
                $y = $page_h - 50;
                $pdf->set_font('Helvetica-Bold', 12);
                $pdf->set_color(0, 0, 0);
                $pdf->text(36, $y, 'Verkaufte Tickets (Fortsetzung)');
                $y -= 26; // mehr Gap (vorher 18 → 26)
                $pdf->draw_table_header($headers, $widths, 36, $y);
                $y -= 22; // mehr Gap (vorher 16 → 22)
                $pdf->set_font('Helvetica', 8);
            }
        }

        // Footer auf jeder Seite
        $pdf->set_footer($brand . ' · Event-Bericht · Seite {p}/{n}');

        // Dezentes Logo unten rechts auf jeder Seite — so groß wie möglich, ganz rechts.
        // - right_anchor_x = page_w - 24 (24pt Padding zur rechten Kante)
        // - y = 8 (8pt Abstand zur Unterkante)
        // - max_h = 38pt (über dem Footer-Text bei y=20, Logo darf bis y=46 reichen)
        // - max_w = 110pt (Hard-Cap damit breite Logos nicht ins Footer-Text rein laufen)
        if ($logo_loaded) {
            $pdf->set_footer_logo($page_w - 24, 8, 110, 38);
        }

        $bytes = $pdf->output();

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    /**
     * Aussteller-Block aus den Settings als Array von Zeilen zurückgeben.
     * Wird im PDF-Bericht (steuerliche Aufschlüsselung) und CSV/XLSX-Header verwendet.
     * Returns: ['Firmenname', 'Straße + PLZ Ort', 'Steuernr.: ... · USt-IdNr.: ...', ...]
     */
    private static function format_issuer_block() {
        $name      = trim((string) tix_get_settings('invoice_company_name'));
        $address   = trim((string) tix_get_settings('invoice_company_address'));
        $tax_id    = trim((string) tix_get_settings('invoice_company_tax_id'));
        $ust_id    = trim((string) tix_get_settings('invoice_company_ust_id'));
        $director  = trim((string) tix_get_settings('invoice_managing_director'));
        $court     = trim((string) tix_get_settings('invoice_register_court'));
        $reg_no    = trim((string) tix_get_settings('invoice_register_number'));
        $email     = trim((string) tix_get_settings('invoice_email'));
        $phone     = trim((string) tix_get_settings('invoice_phone'));
        $footer    = trim((string) tix_get_settings('invoice_footer_text'));

        if (!$name && !$address && !$tax_id && !$ust_id) return [];

        $lines = [];
        if ($name) $lines[] = $name;
        if ($address) {
            // Mehrzeilige Adresse einzeln einsetzen
            foreach (preg_split('/\r\n|\r|\n/', $address) as $ln) {
                $ln = trim($ln);
                if ($ln) $lines[] = $ln;
            }
        }

        // Steuernummer + USt-IdNr.
        $tax_line = [];
        if ($tax_id) $tax_line[] = 'Steuernr.: ' . $tax_id;
        if ($ust_id) $tax_line[] = 'USt-IdNr.: ' . $ust_id;
        if (!empty($tax_line)) $lines[] = implode(' · ', $tax_line);

        // Geschäftsführung + Register
        $rep_line = [];
        if ($director) $rep_line[] = 'Geschäftsführer: ' . $director;
        if ($court && $reg_no) $rep_line[] = $court . ' ' . $reg_no;
        elseif ($court) $rep_line[] = $court;
        elseif ($reg_no) $rep_line[] = $reg_no;
        if (!empty($rep_line)) $lines[] = implode(' · ', $rep_line);

        // Kontakt
        $contact = [];
        if ($email) $contact[] = $email;
        if ($phone) $contact[] = $phone;
        if (!empty($contact)) $lines[] = implode(' · ', $contact);

        // Optionaler Custom-Footer
        if ($footer) {
            foreach (preg_split('/\r\n|\r|\n/', $footer) as $ln) {
                $ln = trim($ln);
                if ($ln) $lines[] = $ln;
            }
        }

        return $lines;
    }

    private static function trunc($str, $max) {
        $str = (string) $str;
        if (mb_strlen($str) <= $max) return $str;
        return mb_substr($str, 0, $max - 1) . '…';
    }
}

/**
 * Minimaler PDF-Writer — schreibt direkt PDF-Bytecode mit den 14 Standard-Fonts.
 * Keine externen Libraries nötig. Unterstützt Text, Linien, Rechtecke, Tabellen.
 */
class TIX_Simple_PDF {
    private $w, $h, $mx, $my;
    private $pages = [];
    private $current = '';
    private $current_color_text = '0 0 0 rg';
    private $current_color_fill = '0.95 0.95 0.97 rg';
    private $current_font = 'F1';
    private $current_size = 10;
    private $footer_text = '';
    // Logo-Daten: JPEG-Bytes + Dimensionen für Image-XObject
    private $logo_jpeg = null;
    private $logo_w = 0;
    private $logo_h = 0;
    // Footer-Logo-Position-Spec: ['x' => , 'y' => , 'w' => , 'opacity' => ] oder null
    private $footer_logo_spec = null;

    public function __construct($w, $h, $mx, $my) {
        $this->w = $w;
        $this->h = $h;
        $this->mx = $mx;
        $this->my = $my;
    }

    /**
     * Logo laden und für PDF-Embedding vorbereiten.
     * Akzeptiert URL (lokale Uploads bevorzugt) oder Filesystem-Pfad.
     * Konvertiert PNG/anderes zu JPEG (für PDF DCTDecode).
     */
    public function load_logo($url_or_path) {
        if (!$url_or_path || !extension_loaded('gd')) return false;

        $bytes = null;
        // Lokale URLs → direkt vom Filesystem (schneller, keine HTTP-Request)
        $upload = wp_get_upload_dir();
        if (strpos($url_or_path, $upload['baseurl']) === 0) {
            $path = str_replace($upload['baseurl'], $upload['basedir'], $url_or_path);
            if (is_readable($path)) $bytes = @file_get_contents($path);
        } elseif (file_exists($url_or_path)) {
            $bytes = @file_get_contents($url_or_path);
        } else {
            $r = wp_remote_get($url_or_path, ['timeout' => 8]);
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $bytes = wp_remote_retrieve_body($r);
            }
        }
        if (!$bytes) return false;

        // GD: Bild öffnen → mit weißem Hintergrund auf JPEG ausgeben
        $img = @imagecreatefromstring($bytes);
        if (!$img) return false;

        $w = imagesx($img);
        $h = imagesy($img);
        // Auf weißem Hintergrund flatten (Transparenz weg, da JPEG keine Alpha hat)
        $bg = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($bg, 255, 255, 255);
        imagefilledrectangle($bg, 0, 0, $w, $h, $white);
        imagecopyresampled($bg, $img, 0, 0, 0, 0, $w, $h, $w, $h);

        ob_start();
        imagejpeg($bg, null, 88);
        $jpeg = ob_get_clean();
        imagedestroy($img);
        imagedestroy($bg);
        if (!$jpeg) return false;

        $this->logo_jpeg = $jpeg;
        $this->logo_w = $w;
        $this->logo_h = $h;
        return true;
    }

    /**
     * Logo an Position (x, y) mit max. Breite zeichnen — Aspect Ratio beibehalten.
     * Gibt die tatsächlich gezeichnete Höhe zurück (für Layout-Berechnungen).
     */
    public function draw_logo($x, $y, $max_w, $max_h = null) {
        if (!$this->logo_jpeg || $this->logo_w <= 0) return 0;
        $ratio = $this->logo_h / $this->logo_w;
        $w = $max_w;
        $h = $w * $ratio;
        if ($max_h && $h > $max_h) {
            $h = $max_h;
            $w = $h / $ratio;
        }
        // q W 0 0 H X Y cm /Logo Do Q (Image-Matrix)
        $this->current .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Logo Do Q\n",
            $w, $h, $x, $y);
        return $h;
    }

    /**
     * Logo auf jedem Footer einbetten — right-anchored.
     * Logo wird so groß wie möglich gerendert (Priorität: max_h),
     * Width fällt auf max_w zurück wenn Aspect-Ratio das verlangt.
     * x = $right_anchor_x - actual_width (Logo-Rechte-Kante an dieser X-Position)
     */
    public function set_footer_logo($right_anchor_x, $y, $max_w, $max_h) {
        $this->footer_logo_spec = [
            'right_anchor_x' => $right_anchor_x,
            'y'              => $y,
            'max_w'          => $max_w,
            'max_h'          => $max_h,
        ];
    }

    public function add_page() {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
    }

    public function set_font($name, $size) {
        $map = [
            'Helvetica'      => 'F1',
            'Helvetica-Bold' => 'F2',
        ];
        $this->current_font = $map[$name] ?? 'F1';
        $this->current_size = $size;
    }

    public function set_color($r, $g, $b) {
        $this->current_color_text = sprintf('%.2f %.2f %.2f rg', $r, $g, $b);
        $this->current_color_fill = sprintf('%.2f %.2f %.2f rg', $r, $g, $b);
    }

    public function text($x, $y, $str) {
        $str = $this->encode($str);
        $this->current .= sprintf("q %s BT /%s %d Tf %d %d Td (%s) Tj ET Q\n",
            $this->current_color_text, $this->current_font, $this->current_size, $x, $y, $str);
    }

    public function rect($x, $y, $w, $h, $fill = false) {
        $op = $fill ? 'f' : 'S';
        $this->current .= sprintf("q %s %d %d %d %d re %s Q\n",
            $this->current_color_fill, $x, $y, $w, $h, $op);
    }

    public function draw_table_header(array $headers, array $widths, $x, $y) {
        // Hintergrund-Bar
        $total_w = array_sum($widths);
        $this->set_color(0.94, 0.94, 0.96);
        $this->rect($x, $y - 3, $total_w, 16, true);
        $this->set_color(0, 0, 0);
        $this->set_font('Helvetica-Bold', 9);
        $cx = $x + 4;
        foreach ($headers as $i => $h) {
            $this->text($cx, $y + 4, $h);
            $cx += $widths[$i];
        }
    }

    public function draw_table_row(array $cells, array $widths, $x, $y) {
        $cx = $x + 4;
        foreach ($cells as $i => $c) {
            $this->text($cx, $y, (string) $c);
            $cx += $widths[$i];
        }
    }

    public function set_footer($text) { $this->footer_text = $text; }

    private function encode($s) {
        // PDF braucht Latin-1 oder PDFDocEncoding für Standard-Fonts.
        // UTF-8 → Latin-1 konvertieren, € durch "EUR" ersetzen (Helvetica hat kein €-Glyph in Latin-1 bei nicht WinAnsi).
        $s = (string) $s;
        $s = str_replace(['€', '–', '—', '…', '✓', '✗'], ['EUR', '-', '-', '...', 'X', 'X'], $s);
        // UTF-8 → CP1252 (Latin-1 Erweiterung mit den meisten Sonderzeichen)
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'CP1252//IGNORE', $s);
            if ($conv !== false) $s = $conv;
        } elseif (function_exists('mb_convert_encoding')) {
            $s = @mb_convert_encoding($s, 'CP1252', 'UTF-8');
        }
        // PDF-Escapes
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '\\r', '\\n'], $s);
    }

    public function output() {
        // Letzte aktive Seite committen
        if ($this->current !== '') {
            $this->pages[] = $this->current;
            $this->current = '';
        }
        if (empty($this->pages)) $this->pages[] = '';

        $n_pages = count($this->pages);

        // Footer-Text + optional Footer-Logo auf jede Seite anhängen
        foreach ($this->pages as $i => $content) {
            $extra = '';
            if ($this->footer_text) {
                $foot_text = str_replace(['{p}', '{n}'], [$i + 1, $n_pages], $this->footer_text);
                $foot_str  = $this->encode($foot_text);
                $extra .= sprintf("q 0.5 0.5 0.5 rg BT /F1 9 Tf %d 20 Td (%s) Tj ET Q\n",
                    $this->mx, $foot_str);
            }
            // Footer-Logo (right-anchored, so groß wie möglich)
            if ($this->footer_logo_spec && $this->logo_jpeg && $this->logo_w > 0) {
                $spec = $this->footer_logo_spec;
                $ratio = $this->logo_h / $this->logo_w; // h/w

                // Priorität: max_h. Width vom Verhältnis abgeleitet, aber capped auf max_w.
                $h = $spec['max_h'];
                $w = $h / $ratio;
                if ($w > $spec['max_w']) {
                    $w = $spec['max_w'];
                    $h = $w * $ratio;
                }
                // Right-anchored: rechte Kante bei right_anchor_x
                $x = $spec['right_anchor_x'] - $w;

                // Dezent: ExtGState mit 55% Opazität
                $extra .= sprintf("q /GS_FADED gs %.2f 0 0 %.2f %.2f %.2f cm /Logo Do Q\n",
                    $w, $h, $x, $spec['y']);
            }
            $this->pages[$i] = $content . $extra;
        }

        // ── PDF zusammenbauen ──
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $obj_id  = 0;

        $catalog_id = ++$obj_id;
        $pages_id   = ++$obj_id;

        $page_ids = [];
        $content_ids = [];
        for ($i = 0; $i < $n_pages; $i++) {
            $page_ids[]    = ++$obj_id;
            $content_ids[] = ++$obj_id;
        }

        $font1_id = ++$obj_id;
        $font2_id = ++$obj_id;

        // Logo-XObject + ExtGState für Opazität (nur wenn Logo geladen)
        $logo_obj_id = 0;
        $gs_obj_id   = 0;
        if ($this->logo_jpeg && $this->logo_w > 0) {
            $logo_obj_id = ++$obj_id;
            $gs_obj_id   = ++$obj_id;
        }

        // 1: Catalog
        $offsets[$catalog_id] = strlen($pdf);
        $pdf .= $catalog_id . " 0 obj\n<< /Type /Catalog /Pages " . $pages_id . " 0 R >>\nendobj\n";

        // 2: Pages-Tree
        $offsets[$pages_id] = strlen($pdf);
        $kids = implode(' ', array_map(fn($id) => $id . ' 0 R', $page_ids));
        $pdf .= $pages_id . " 0 obj\n<< /Type /Pages /Kids [$kids] /Count $n_pages >>\nendobj\n";

        // 3+: Page-Objs + Contents
        $resources_xobj = '';
        $resources_extgstate = '';
        if ($logo_obj_id) {
            $resources_xobj      = ' /XObject << /Logo ' . $logo_obj_id . ' 0 R >>';
            $resources_extgstate = ' /ExtGState << /GS_FADED ' . $gs_obj_id . ' 0 R >>';
        }
        foreach ($this->pages as $i => $content) {
            $page_id    = $page_ids[$i];
            $content_id = $content_ids[$i];
            $offsets[$page_id] = strlen($pdf);
            $pdf .= $page_id . " 0 obj\n<< /Type /Page /Parent " . $pages_id . " 0 R "
                . "/MediaBox [0 0 " . $this->w . " " . $this->h . "] "
                . "/Resources << /Font << /F1 " . $font1_id . " 0 R /F2 " . $font2_id . " 0 R >>"
                . $resources_xobj . $resources_extgstate
                . " >> "
                . "/Contents " . $content_id . " 0 R >>\nendobj\n";

            $offsets[$content_id] = strlen($pdf);
            $pdf .= $content_id . " 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n";
        }

        // Fonts
        $offsets[$font1_id] = strlen($pdf);
        $pdf .= $font1_id . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
        $offsets[$font2_id] = strlen($pdf);
        $pdf .= $font2_id . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Logo-XObject (JPEG mit DCTDecode-Filter)
        if ($logo_obj_id) {
            $offsets[$logo_obj_id] = strlen($pdf);
            $pdf .= $logo_obj_id . " 0 obj\n<< /Type /XObject /Subtype /Image "
                . "/Width " . $this->logo_w . " /Height " . $this->logo_h . " "
                . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode "
                . "/Length " . strlen($this->logo_jpeg) . " >>\nstream\n"
                . $this->logo_jpeg . "\nendstream\nendobj\n";

            // ExtGState: Faded (50% Opazität für dezente Footer-Anzeige)
            $offsets[$gs_obj_id] = strlen($pdf);
            $pdf .= $gs_obj_id . " 0 obj\n<< /Type /ExtGState /ca 0.55 /CA 0.55 >>\nendobj\n";
        }

        // xref
        $xref_offset = strlen($pdf);
        $total_objs = $obj_id + 1;
        $pdf .= "xref\n0 " . $total_objs . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $total_objs; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        // trailer
        $pdf .= "trailer\n<< /Size " . $total_objs . " /Root " . $catalog_id . " 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

        return $pdf;
    }
}
