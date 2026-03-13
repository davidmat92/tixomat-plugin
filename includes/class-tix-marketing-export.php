<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Marketing_Export – Segmentierter CSV-Export für Mailchimp / Brevo.
 *
 * Admin-Seite unter Tixomat → Marketing Export.
 * Filter: Event, Zeitraum, VIP-Status, Min-Umsatz, Newsletter-Optin.
 * CSV-Format: Email, Vorname, Nachname, VIP, Events, Letzte_Bestellung, Gesamtumsatz, Newsletter
 *
 * @since 1.28.90
 */
class TIX_Marketing_Export {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu'], 30);
        add_action('admin_post_tix_marketing_export_csv', [__CLASS__, 'handle_csv_download']);
    }

    public static function add_menu() {
        add_submenu_page(
            'tixomat',
            'Marketing Export',
            'Marketing Export',
            'manage_options',
            'tix-marketing-export',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Admin-Seite rendern.
     */
    public static function render_page() {
        $events = self::get_events_list();
        $filter = self::get_filter_from_request();
        $results = null;

        if (isset($_GET['tix_preview'])) {
            $results = self::query_customers($filter, 50);
        }

        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-email-alt" style="font-size:24px;margin-right:8px"></span>Marketing Export</h1>
            <p>Exportiere segmentierte Kundenlisten als CSV f&uuml;r Mailchimp, Brevo und andere E-Mail-Marketing-Tools.</p>

            <form method="get" action="">
                <input type="hidden" name="page" value="tix-marketing-export">
                <input type="hidden" name="tix_preview" value="1">

                <table class="form-table">
                    <tr>
                        <th>Event</th>
                        <td>
                            <select name="event_id">
                                <option value="">Alle Events</option>
                                <?php foreach ($events as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php selected($filter['event_id'], $id); ?>><?php echo esc_html($title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Zeitraum</th>
                        <td>
                            <input type="date" name="date_from" value="<?php echo esc_attr($filter['date_from']); ?>" placeholder="Von">
                            &ndash;
                            <input type="date" name="date_to" value="<?php echo esc_attr($filter['date_to']); ?>" placeholder="Bis">
                        </td>
                    </tr>
                    <tr>
                        <th>VIP-Status</th>
                        <td>
                            <select name="vip_filter">
                                <option value="" <?php selected($filter['vip_filter'], ''); ?>>Alle</option>
                                <option value="vip" <?php selected($filter['vip_filter'], 'vip'); ?>>Nur VIPs</option>
                                <option value="non_vip" <?php selected($filter['vip_filter'], 'non_vip'); ?>>Nur Nicht-VIPs</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Min. Umsatz (&euro;)</th>
                        <td><input type="number" name="min_revenue" value="<?php echo esc_attr($filter['min_revenue']); ?>" min="0" step="1" class="small-text"></td>
                    </tr>
                    <tr>
                        <th>Newsletter</th>
                        <td>
                            <select name="newsletter_filter">
                                <option value="" <?php selected($filter['newsletter_filter'], ''); ?>>Alle</option>
                                <option value="yes" <?php selected($filter['newsletter_filter'], 'yes'); ?>>Nur Opt-in</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Vorschau laden', 'secondary', 'submit', true); ?>
            </form>

            <?php if ($results !== null): ?>
                <h2>Ergebnisse (<?php echo count($results); ?><?php echo count($results) >= 50 ? '+' : ''; ?> Kontakte)</h2>

                <?php if (!empty($results)): ?>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="tix_marketing_export_csv">
                        <?php wp_nonce_field('tix_marketing_export'); ?>
                        <!-- Pass filter params -->
                        <input type="hidden" name="event_id" value="<?php echo esc_attr($filter['event_id']); ?>">
                        <input type="hidden" name="date_from" value="<?php echo esc_attr($filter['date_from']); ?>">
                        <input type="hidden" name="date_to" value="<?php echo esc_attr($filter['date_to']); ?>">
                        <input type="hidden" name="vip_filter" value="<?php echo esc_attr($filter['vip_filter']); ?>">
                        <input type="hidden" name="min_revenue" value="<?php echo esc_attr($filter['min_revenue']); ?>">
                        <input type="hidden" name="newsletter_filter" value="<?php echo esc_attr($filter['newsletter_filter']); ?>">
                        <?php submit_button('CSV exportieren', 'primary', 'submit', true); ?>
                    </form>

                    <table class="wp-list-table widefat fixed striped" style="margin-top:12px">
                        <thead>
                            <tr>
                                <th>E-Mail</th>
                                <th>Vorname</th>
                                <th>Nachname</th>
                                <th>VIP</th>
                                <th>Events</th>
                                <th>Letzte Bestellung</th>
                                <th>Umsatz</th>
                                <th>Newsletter</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row['email']); ?></td>
                                    <td><?php echo esc_html($row['first_name']); ?></td>
                                    <td><?php echo esc_html($row['last_name']); ?></td>
                                    <td><?php echo $row['vip'] ? '<span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FFD700,#FFA500);color:#000">VIP</span>' : '—'; ?></td>
                                    <td><?php echo esc_html($row['events']); ?></td>
                                    <td><?php echo esc_html($row['last_order']); ?></td>
                                    <td><?php echo number_format($row['revenue'], 2, ',', '.') . ' €'; ?></td>
                                    <td><?php echo $row['newsletter'] ? '✓' : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><em>Keine Ergebnisse f&uuml;r diese Filter.</em></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * CSV-Download handler.
     */
    public static function handle_csv_download() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');
        check_admin_referer('tix_marketing_export');

        $filter  = self::get_filter_from_request();
        $results = self::query_customers($filter, 0);

        $filename = 'tixomat-marketing-export-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        // BOM für Excel
        fwrite($out, "\xEF\xBB\xBF");
        // Header
        fputcsv($out, ['Email', 'Vorname', 'Nachname', 'VIP', 'Events', 'Letzte_Bestellung', 'Gesamtumsatz', 'Newsletter'], ';');

        foreach ($results as $row) {
            fputcsv($out, [
                $row['email'],
                $row['first_name'],
                $row['last_name'],
                $row['vip'] ? 'VIP' : '',
                $row['events'],
                $row['last_order'],
                number_format($row['revenue'], 2, '.', ''),
                $row['newsletter'] ? 'Ja' : 'Nein',
            ], ';');
        }

        fclose($out);
        exit;
    }

    /**
     * Kunden abfragen mit Filtern.
     */
    private static function query_customers($filter, $limit = 0) {
        global $wpdb;

        $where = ['1=1'];
        $args  = [];

        if (!empty($filter['event_id'])) {
            $where[] = 't.event_id = %d';
            $args[]  = intval($filter['event_id']);
        }

        if (!empty($filter['date_from'])) {
            $where[] = 't.created_at >= %s';
            $args[]  = $filter['date_from'] . ' 00:00:00';
        }

        if (!empty($filter['date_to'])) {
            $where[] = 't.created_at <= %s';
            $args[]  = $filter['date_to'] . ' 23:59:59';
        }

        if ($filter['newsletter_filter'] === 'yes') {
            $where[] = 't.newsletter_optin = 1';
        }

        $where_sql = implode(' AND ', $where);
        $limit_sql = $limit > 0 ? "LIMIT $limit" : '';

        $sql = "SELECT
                    t.buyer_email AS email,
                    SUBSTRING_INDEX(t.buyer_name, ' ', 1) AS first_name,
                    TRIM(SUBSTR(t.buyer_name, LOCATE(' ', t.buyer_name))) AS last_name,
                    COUNT(*) AS ticket_count,
                    COUNT(DISTINCT t.order_id) AS order_count,
                    GROUP_CONCAT(DISTINCT t.event_name SEPARATOR ', ') AS events,
                    MAX(t.created_at) AS last_order,
                    SUM(t.ticket_price) AS revenue,
                    MAX(t.newsletter_optin) AS newsletter
                FROM {$wpdb->prefix}tixomat_tickets t
                WHERE $where_sql AND t.buyer_email != '' AND t.ticket_status = 'valid'
                GROUP BY t.buyer_email
                HAVING 1=1";

        if (!empty($filter['min_revenue'])) {
            $sql   .= " AND SUM(t.ticket_price) >= %f";
            $args[] = floatval($filter['min_revenue']);
        }

        $sql .= " ORDER BY last_order DESC $limit_sql";

        if (!empty($args)) {
            $sql = $wpdb->prepare($sql, ...$args);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) return [];

        // VIP-Status anhängen
        $result = [];
        foreach ($rows as $row) {
            $is_vip = false;
            // Lookup WP User for VIP check
            $user = get_user_by('email', $row['email']);
            if ($user && class_exists('TIX_VIP')) {
                $is_vip = TIX_VIP::is_vip($user->ID);
            }

            // VIP-Filter anwenden
            if ($filter['vip_filter'] === 'vip' && !$is_vip) continue;
            if ($filter['vip_filter'] === 'non_vip' && $is_vip) continue;

            $result[] = [
                'email'      => $row['email'],
                'first_name' => $row['first_name'] ?: '',
                'last_name'  => $row['last_name'] ?: '',
                'vip'        => $is_vip,
                'events'     => $row['events'] ?: '',
                'last_order' => $row['last_order'] ? date('d.m.Y', strtotime($row['last_order'])) : '',
                'revenue'    => floatval($row['revenue'] ?? 0),
                'newsletter' => intval($row['newsletter'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Filter aus GET/POST lesen.
     */
    private static function get_filter_from_request() {
        return [
            'event_id'          => sanitize_text_field($_REQUEST['event_id'] ?? ''),
            'date_from'         => sanitize_text_field($_REQUEST['date_from'] ?? ''),
            'date_to'           => sanitize_text_field($_REQUEST['date_to'] ?? ''),
            'vip_filter'        => sanitize_text_field($_REQUEST['vip_filter'] ?? ''),
            'min_revenue'       => sanitize_text_field($_REQUEST['min_revenue'] ?? ''),
            'newsletter_filter' => sanitize_text_field($_REQUEST['newsletter_filter'] ?? ''),
        ];
    }

    /**
     * Events-Liste für Dropdown.
     */
    private static function get_events_list() {
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $list = [];
        foreach ($events as $e) {
            $list[$e->ID] = $e->post_title;
        }
        return $list;
    }
}
