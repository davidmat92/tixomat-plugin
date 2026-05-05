<?php
/**
 * TIX CRM — Kunden-Übersicht im Admin
 *
 * Zentrale Kunden-Ansicht, aggregiert aus:
 *   - WordPress-Usern (Kunden-Rolle tix_customer + alle mit E-Mail in tix_orders)
 *   - Native Orders-Tabelle (tix_orders)
 *   - WooCommerce Orders (falls aktiv)
 *
 * Sichtbarkeit:
 *   - Admin (manage_options): sieht alle Kunden
 *   - Organizer (tix_organizer): sieht nur Kunden, die bei SEINEN Events gekauft haben
 *
 * Features:
 *   - Listenansicht mit Suche, Filter, Sortierung, Pagination
 *   - Detailansicht mit Analytics (Bestellungen, Events, Attribution, UTM, LTV)
 *   - Direkt-Mail an Kunden (optional mit Ticket-Anhang aus Bestellung)
 */
if (!defined('ABSPATH')) exit;

class TIX_CRM {

    const PAGE_SLUG     = 'tix-customers';
    const NONCE_SEND    = 'tix_crm_send_mail';
    const NONCE_UPDATE  = 'tix_crm_update_customer';
    const PER_PAGE      = 25;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 26);
        add_action('wp_ajax_tix_crm_send_mail',           [__CLASS__, 'ajax_send_mail']);
        add_action('wp_ajax_tix_crm_export_csv',          [__CLASS__, 'ajax_export_csv']);
        add_action('wp_ajax_tix_crm_update_customer',     [__CLASS__, 'ajax_update_customer']);
        add_action('wp_ajax_tix_crm_send_password_link',  [__CLASS__, 'ajax_send_password_link']);
    }

    public static function register_menu() {
        // Mit 'read' registrieren (jeder eingeloggte User) — echter Access-Check in render_page().
        // Damit der Sidebar-Link (Admin + Organizer) funktioniert, darf die Registrierung nicht
        // vom aktuellen User abhängen. Permissions werden feingranular in render_page() geprüft.
        add_submenu_page(
            'tixomat',
            'Kunden (CRM)',
            'Kunden',
            'read',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    /** True wenn User Zugriff haben darf (Admin oder Organizer) */
    public static function current_user_can_access() {
        if (current_user_can('manage_options')) return true;
        $u = wp_get_current_user();
        return $u && in_array('tix_organizer', (array) $u->roles, true);
    }

    /** True wenn User Admin ist (alles sehen) */
    private static function is_admin_view() {
        return current_user_can('manage_options');
    }

    /** Event-IDs, die der aktuelle Organizer sehen darf (leer = alle → Admin) */
    private static function visible_event_ids() {
        if (self::is_admin_view()) return null; // null = keine Beschränkung

        $user_id = get_current_user_id();
        $org_id  = 0;
        if (class_exists('TIX_Organizer_Dashboard')) {
            $org = TIX_Organizer_Dashboard::get_organizer_by_user($user_id);
            if ($org) $org_id = $org->ID;
        }
        if (!$org_id) return [0];

        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_tix_organizer_id',    'value' => $org_id, 'type' => 'NUMERIC'],
                ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
            ],
        ]);
        return $events ?: [0];
    }

    // ═══════════════════════════════════════════════════════════════
    // ROUTING
    // ═══════════════════════════════════════════════════════════════

    public static function render_page() {
        if (!self::current_user_can_access()) {
            wp_die('Keine Berechtigung.');
        }

        // Detail via ?customer=<email-hash> oder ?user_id=X
        $email = isset($_GET['email']) ? sanitize_email(wp_unslash($_GET['email'])) : '';
        if ($email) {
            self::render_detail($email);
            return;
        }

        self::render_list();
    }

    // ═══════════════════════════════════════════════════════════════
    // LIST VIEW
    // ═══════════════════════════════════════════════════════════════

    private static function render_list() {
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $source = sanitize_text_field(wp_unslash($_GET['source'] ?? ''));
        $paged  = max(1, intval($_GET['paged'] ?? 1));
        $orderby = in_array($_GET['orderby'] ?? '', ['last_order','total_spent','order_count','name','email'], true)
            ? $_GET['orderby'] : 'last_order';
        $order = (($_GET['order'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

        $args = [
            'search'   => $search,
            'source'   => $source,
            'paged'    => $paged,
            'per_page' => self::PER_PAGE,
            'orderby'  => $orderby,
            'order'    => $order,
        ];

        $result = self::query_customers($args);
        $rows   = $result['rows'];
        $total  = $result['total'];
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));

        // Gesamt-Stats (für oben)
        $stats = self::query_summary_stats();

        ?>
        <div class="wrap" style="max-width:100%;">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                <span class="dashicons dashicons-groups" style="font-size:28px;width:28px;height:28px;color:var(--tix-primary, #FF5500);"></span>
                Kunden
                <span style="font-size:14px;color:#6b7280;font-weight:400;"><?php echo intval($total); ?> gesamt</span>
                <?php if (!self::is_admin_view()): ?>
                    <span style="font-size:12px;color:#b45309;background:#fef3c7;padding:3px 10px;border-radius:6px;font-weight:500;">Nur deine Kunden sichtbar</span>
                <?php endif; ?>
            </h1>

            <?php // ── KPI-Kacheln ── ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px;">
                <?php self::render_kpi('Kunden', intval($stats['customers']), 'groups'); ?>
                <?php self::render_kpi('Bestellungen', intval($stats['orders']), 'cart'); ?>
                <?php self::render_kpi('Umsatz', number_format((float)$stats['revenue'], 2, ',', '.') . ' €', 'money-alt'); ?>
                <?php self::render_kpi('Ø Kundenwert', number_format((float)$stats['avg_ltv'], 2, ',', '.') . ' €', 'chart-line'); ?>
            </div>

            <?php // ── Filter + Suche ── ?>
            <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                <form method="get" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>"
                           placeholder="Name, E-Mail oder Bestellnr…"
                           style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;width:280px;">
                    <?php
                    $sources = self::available_sources();
                    if (!empty($sources)):
                    ?>
                    <select name="source" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                        <option value="">Alle Quellen</option>
                        <?php foreach ($sources as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($source, $s); ?>><?php echo esc_html($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <button type="submit" class="button">Filter anwenden</button>
                    <?php if ($search || $source): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" style="font-size:12px;color:#6b7280;text-decoration:none;">× Zurücksetzen</a>
                    <?php endif; ?>
                </form>

                <div style="display:flex;gap:6px;align-items:center;">
                    <a href="<?php echo esc_url(add_query_arg(['export' => 'csv'])); ?>"
                       class="button" onclick="return confirm('Alle Kunden als CSV exportieren?');">⇩ CSV</a>
                </div>
            </div>

            <?php // ── Tabelle ── ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                <table class="widefat" style="border:none;margin:0;">
                    <thead>
                        <tr style="background:#fafafa;">
                            <?php
                            $cols = [
                                ['name',         'Kunde'],
                                ['email',        'E-Mail'],
                                ['order_count',  'Bestellungen'],
                                ['total_spent',  'Umsatz'],
                                ['last_order',   'Letzte Bestellung'],
                                [null,           'Quelle'],
                                [null,           'Rolle'],
                                [null,           'Aktion'],
                            ];
                            foreach ($cols as [$key, $label]):
                                $sort_url = '';
                                if ($key) {
                                    $new_order = ($orderby === $key && $order === 'DESC') ? 'asc' : 'desc';
                                    $sort_url = add_query_arg(['orderby' => $key, 'order' => $new_order]);
                                }
                                $arrow = ($orderby === $key) ? ($order === 'ASC' ? ' ▲' : ' ▼') : '';
                            ?>
                                <th style="padding:12px 14px;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.03em;">
                                    <?php if ($sort_url): ?>
                                        <a href="<?php echo esc_url($sort_url); ?>" style="text-decoration:none;color:inherit;"><?php echo esc_html($label . $arrow); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($label); ?>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" style="padding:40px;text-align:center;color:#6b7280;">Keine Kunden gefunden.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r):
                            $detail_url = admin_url('admin.php?page=' . self::PAGE_SLUG . '&email=' . urlencode($r['email']));
                        ?>
                            <?php $is_user_only = !empty($r['is_user_only']); ?>
                            <tr style="border-top:1px solid #f3f4f6;<?php echo $is_user_only ? 'background:#fafbff;' : ''; ?>">
                                <td style="padding:12px 14px;">
                                    <?php if ($is_user_only): ?>
                                        <?php
                                        $u = $r['user_id'] ? get_userdata($r['user_id']) : null;
                                        $login = $u ? $u->user_login : '';
                                        ?>
                                        <a href="<?php echo esc_url($detail_url); ?>" style="font-weight:600;color:#0f172a;text-decoration:none;">
                                            <?php echo esc_html(trim($r['first_name'] . ' ' . $r['last_name']) ?: ($u ? $u->display_name : '(ohne Namen)')); ?>
                                        </a>
                                        <span style="display:inline-block;background:#dbeafe;color:#1d4ed8;padding:1px 7px;border-radius:6px;font-size:10px;font-weight:600;margin-left:4px;" title="WP-User ohne Bestellungen">👤 NUR USER</span>
                                        <?php if ($login): ?><div style="font-size:11px;color:#9ca3af;font-family:monospace;margin-top:2px;">@<?php echo esc_html($login); ?></div><?php endif; ?>
                                    <?php else: ?>
                                        <a href="<?php echo esc_url($detail_url); ?>" style="font-weight:600;color:#0f172a;text-decoration:none;">
                                            <?php echo esc_html(trim($r['first_name'] . ' ' . $r['last_name']) ?: '(ohne Namen)'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 14px;color:#334155;font-size:13px;"><?php echo esc_html($r['email']); ?></td>
                                <td style="padding:12px 14px;font-weight:600;<?php echo $is_user_only ? 'color:#9ca3af;' : ''; ?>"><?php echo intval($r['order_count']); ?></td>
                                <td style="padding:12px 14px;font-weight:600;color:#16a34a;"><?php echo number_format((float)$r['total_spent'], 2, ',', '.'); ?> €</td>
                                <td style="padding:12px 14px;font-size:13px;color:#334155;">
                                    <?php echo $r['last_order'] ? esc_html(date_i18n('d.m.Y', strtotime($r['last_order']))) : '—'; ?>
                                </td>
                                <td style="padding:12px 14px;">
                                    <?php if ($r['source']): ?>
                                        <span style="background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:500;"><?php echo esc_html($r['source']); ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 14px;font-size:12px;color:#6b7280;">
                                    <?php echo $r['user_id'] ? esc_html($r['user_roles']) : '<em>Gast</em>'; ?>
                                </td>
                                <td style="padding:12px 14px;text-align:right;">
                                    <?php if ($r['user_id'] && class_exists('TIX_User_Switch')):
                                        $u = get_user_by('id', $r['user_id']);
                                        if ($u && !user_can($u, 'manage_options')):
                                            $switch_url = TIX_User_Switch::get_switch_url($r['user_id']);
                                    ?>
                                        <a href="<?php echo esc_url($switch_url); ?>" title="Als diesen Kunden einloggen (Support-Modus)" style="display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;padding:5px 10px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid #fde68a;">
                                            🎭 Login
                                        </a>
                                    <?php endif; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php // ── Pagination ── ?>
            <?php if ($pages > 1): ?>
                <div style="text-align:center;margin:20px 0;">
                    <?php for ($i = 1; $i <= $pages; $i++):
                        if ($i !== 1 && $i !== $pages && abs($i - $paged) > 2) {
                            if ($i === $paged - 3 || $i === $paged + 3) echo ' <span style="color:#9ca3af;">…</span> ';
                            continue;
                        }
                        $url = esc_url(add_query_arg('paged', $i));
                        $active = $i === $paged;
                    ?>
                        <a href="<?php echo $url; ?>" style="display:inline-block;padding:6px 12px;margin:0 2px;border:1px solid <?php echo $active ? '#0f172a' : '#e5e7eb'; ?>;border-radius:6px;background:<?php echo $active ? '#0f172a' : '#fff'; ?>;color:<?php echo $active ? '#fff' : '#334155'; ?>;text-decoration:none;font-size:13px;font-weight:<?php echo $active ? '600' : '400'; ?>;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_kpi($label, $value, $icon) {
        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px;">
            <div style="width:40px;height:40px;border-radius:10px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;">
                <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" style="font-size:20px;width:20px;height:20px;"></span>
            </div>
            <div>
                <div style="font-size:12px;color:#6b7280;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;"><?php echo esc_html($label); ?></div>
                <div style="font-size:20px;font-weight:700;color:#0f172a;margin-top:2px;"><?php echo esc_html($value); ?></div>
            </div>
        </div>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════
    // DETAIL VIEW
    // ═══════════════════════════════════════════════════════════════

    private static function render_detail($email) {
        $data = self::get_customer_detail($email);
        if (!$data) {
            echo '<div class="wrap"><h1>Kunde nicht gefunden</h1><p><a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">← Zurück zur Übersicht</a></p></div>';
            return;
        }

        // Zugriffsprüfung für Organizer: nur sehen wenn der Kunde in einem seiner Events gebucht hat
        if (!self::is_admin_view()) {
            $visible = self::visible_event_ids();
            if (!empty($visible) && !self::customer_has_event_in($email, $visible)) {
                wp_die('Kein Zugriff auf diesen Kunden.');
            }
        }

        $name = trim($data['first_name'] . ' ' . $data['last_name']) ?: $email;
        $back = admin_url('admin.php?page=' . self::PAGE_SLUG);

        ?>
        <div class="wrap" style="max-width:1200px;">
            <p style="margin:0 0 6px;">
                <a href="<?php echo esc_url($back); ?>" style="text-decoration:none;color:#6b7280;font-size:13px;">← Zurück zur Kunden-Übersicht</a>
            </p>
            <h1 style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
                <span class="dashicons dashicons-admin-users" style="font-size:28px;width:28px;height:28px;color:#6b7280;"></span>
                <?php echo esc_html($name); ?>
            </h1>
            <p style="margin:0 0 24px;color:#6b7280;"><?php echo esc_html($email); ?></p>

            <?php // ── KPIs ── ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
                <?php self::render_kpi('Bestellungen', intval($data['order_count']), 'cart'); ?>
                <?php self::render_kpi('Gesamtumsatz', number_format((float)$data['total_spent'], 2, ',', '.') . ' €', 'money-alt'); ?>
                <?php self::render_kpi('Ø Bestellwert', number_format((float)$data['avg_order'], 2, ',', '.') . ' €', 'chart-area'); ?>
                <?php self::render_kpi('Events', intval($data['event_count']), 'calendar-alt'); ?>
                <?php self::render_kpi('Tickets', intval($data['ticket_count']), 'tickets-alt'); ?>
            </div>

            <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;">
                <?php // ═══════════ LEFT COLUMN ═══════════ ?>
                <div>
                    <?php // Bestellungen ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px;">
                        <h3 style="margin:0 0 14px;font-size:15px;">Bestellungen (<?php echo count($data['orders']); ?>)</h3>
                        <?php if (empty($data['orders'])): ?>
                            <p style="color:#9ca3af;margin:0;">Keine Bestellungen.</p>
                        <?php else: ?>
                            <table class="widefat" style="border:none;">
                                <thead>
                                    <tr style="background:#fafafa;">
                                        <th style="padding:8px 12px;font-size:12px;">Nr.</th>
                                        <th style="padding:8px 12px;font-size:12px;">Datum</th>
                                        <th style="padding:8px 12px;font-size:12px;">Status</th>
                                        <th style="padding:8px 12px;font-size:12px;">Event(s)</th>
                                        <th style="padding:8px 12px;font-size:12px;text-align:right;">Betrag</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['orders'] as $o):
                                        $order_url = admin_url('admin.php?page=tix-orders&order_id=' . $o['id']);
                                    ?>
                                        <tr style="border-top:1px solid #f3f4f6;">
                                            <td style="padding:10px 12px;"><a href="<?php echo esc_url($order_url); ?>" style="color:#2563eb;text-decoration:none;font-weight:600;">#<?php echo esc_html($o['number']); ?></a></td>
                                            <td style="padding:10px 12px;font-size:13px;"><?php echo esc_html(date_i18n('d.m.Y', strtotime($o['date']))); ?></td>
                                            <td style="padding:10px 12px;"><?php echo self::status_badge($o['status']); ?></td>
                                            <td style="padding:10px 12px;font-size:12px;color:#334155;"><?php echo esc_html($o['events']); ?></td>
                                            <td style="padding:10px 12px;text-align:right;font-weight:600;"><?php echo number_format((float)$o['total'], 2, ',', '.'); ?> €</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <?php // ── E-Mail-Log für diesen Kunden ── ?>
                    <?php if (class_exists('TIX_Email_Log')):
                        $email_rows = TIX_Email_Log::get_for_email($email, 30);
                        if (!empty($email_rows)):
                    ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px;">
                        <h3 style="margin:0 0 14px;font-size:15px;display:flex;align-items:center;gap:8px;">
                            <span class="dashicons dashicons-email"></span>
                            E-Mail-Log
                            <span style="font-size:11px;color:#9ca3af;font-weight:500;">(<?php echo count($email_rows); ?> E-Mail<?php echo count($email_rows) === 1 ? '' : 's'; ?>)</span>
                        </h3>
                        <?php TIX_Email_Log::render_inline($email_rows, $email); ?>
                    </div>
                    <?php endif; endif; ?>

                    <?php // Events besucht ?>
                    <?php if (!empty($data['events'])): ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px;">
                        <h3 style="margin:0 0 14px;font-size:15px;">Besuchte Events (<?php echo count($data['events']); ?>)</h3>
                        <?php foreach ($data['events'] as $ev): ?>
                            <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f3f4f6;">
                                <div>
                                    <a href="<?php echo esc_url(get_edit_post_link($ev['id'])); ?>" style="color:#0f172a;text-decoration:none;font-weight:500;"><?php echo esc_html($ev['title']); ?></a>
                                    <?php if ($ev['date']): ?>
                                        <div style="font-size:12px;color:#6b7280;margin-top:2px;"><?php echo esc_html(date_i18n('d.m.Y', strtotime($ev['date']))); ?></div>
                                    <?php endif; ?>
                                </div>
                                <span style="font-size:12px;color:#6b7280;"><?php echo intval($ev['ticket_count']); ?>× Ticket</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php // Aktivität-Timeline (optional: erste + letzte Bestellung) ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px;">
                        <h3 style="margin:0 0 14px;font-size:15px;">Timeline</h3>
                        <div style="font-size:13px;color:#334155;">
                            <?php if ($data['first_order']): ?>
                                <div style="padding:6px 0;">
                                    <strong>Erste Bestellung:</strong> <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($data['first_order']))); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($data['last_order']): ?>
                                <div style="padding:6px 0;">
                                    <strong>Letzte Bestellung:</strong> <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($data['last_order']))); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($data['first_order'] && $data['last_order'] && $data['first_order'] !== $data['last_order']):
                                $days = round((strtotime($data['last_order']) - strtotime($data['first_order'])) / 86400);
                            ?>
                                <div style="padding:6px 0;">
                                    <strong>Kunde seit:</strong> <?php echo intval($days); ?> Tagen
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php // ═══════════ RIGHT SIDEBAR ═══════════ ?>
                <div>
                    <?php // Kontakt-Karte ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:16px;">
                        <h3 style="margin:0 0 14px;font-size:14px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Kontakt</h3>
                        <?php if ($data['phone']): ?>
                            <p style="margin:0 0 8px;font-size:13px;"><strong>Telefon:</strong> <a href="tel:<?php echo esc_attr($data['phone']); ?>" style="color:#2563eb;text-decoration:none;"><?php echo esc_html($data['phone']); ?></a></p>
                        <?php endif; ?>
                        <p style="margin:0 0 8px;font-size:13px;"><strong>E-Mail:</strong> <a href="mailto:<?php echo esc_attr($email); ?>" style="color:#2563eb;text-decoration:none;"><?php echo esc_html($email); ?></a></p>
                        <?php if ($data['address']): ?>
                            <p style="margin:0 0 8px;font-size:13px;color:#334155;line-height:1.5;"><?php echo nl2br(esc_html($data['address'])); ?></p>
                        <?php endif; ?>

                        <button type="button" class="button button-primary" id="tix-crm-send-mail-btn" style="width:100%;margin-top:12px;">
                            <span class="dashicons dashicons-email" style="margin-top:4px;"></span>
                            E-Mail schreiben
                        </button>
                        <button type="button" class="button" id="tix-crm-edit-btn" style="width:100%;margin-top:8px;">
                            <span class="dashicons dashicons-edit" style="margin-top:4px;"></span>
                            Kundendaten bearbeiten
                        </button>
                    </div>

                    <?php // WP-Account ?>
                    <?php if ($data['user_id']): ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:16px;">
                        <h3 style="margin:0 0 14px;font-size:14px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">WP-Konto</h3>
                        <p style="margin:0 0 4px;font-size:13px;"><strong>User:</strong> <?php echo esc_html($data['username']); ?></p>
                        <p style="margin:0 0 4px;font-size:13px;"><strong>Rolle:</strong> <?php echo esc_html($data['user_roles']); ?></p>
                        <p style="margin:0 0 8px;font-size:13px;"><strong>Registriert:</strong> <?php echo esc_html($data['user_registered']); ?></p>
                        <?php if (class_exists('TIX_User_Switch')):
                            $u = get_user_by('id', $data['user_id']);
                            if ($u && !user_can($u, 'manage_options')):
                                $switch_url = TIX_User_Switch::get_switch_url($data['user_id']);
                        ?>
                            <a href="<?php echo esc_url($switch_url); ?>"
                               style="display:flex;align-items:center;justify-content:center;gap:6px;background:linear-gradient(90deg,#FF5500,#dc2626);color:#fff;padding:9px 10px;border-radius:8px;text-decoration:none;font-weight:600;font-size:12px;line-height:1.2;width:100%;margin-top:10px;box-sizing:border-box;text-align:center;white-space:nowrap;">
                                <span class="dashicons dashicons-admin-users" style="font-size:15px;width:15px;height:15px;line-height:1;"></span>
                                Als Kunde einloggen
                            </a>
                            <p style="margin:6px 0 0;font-size:11px;color:#6b7280;text-align:center;line-height:1.4;">Support-Modus — du wirst temporär als dieser Kunde eingeloggt.</p>
                        <?php endif; endif; ?>
                    </div>
                    <?php else: ?>
                    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:14px 16px;margin-bottom:16px;color:#92400e;font-size:13px;">
                        <strong>Gast-Kunde</strong> — kein WordPress-Account. Änderungen werden auf allen Bestellungen aktualisiert.
                    </div>
                    <?php endif; ?>

                    <?php // Attribution ?>
                    <?php if ($data['source'] || $data['utm_source']): ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:16px;">
                        <h3 style="margin:0 0 14px;font-size:14px;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">Herkunft</h3>
                        <?php if ($data['source']): ?>
                            <p style="margin:0 0 6px;font-size:13px;"><strong>Veranstalter-Landing:</strong> <?php echo esc_html($data['source']); ?></p>
                        <?php endif; ?>
                        <?php if ($data['utm_source']): ?>
                            <p style="margin:0 0 6px;font-size:13px;"><strong>UTM Source:</strong> <?php echo esc_html($data['utm_source']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($data['utm_campaign'])): ?>
                            <p style="margin:0 0 6px;font-size:13px;"><strong>Kampagne:</strong> <?php echo esc_html($data['utm_campaign']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php // ── Modals ── ?>
            <?php self::render_mail_modal($email, $data); ?>
            <?php self::render_edit_modal($email, $data); ?>
        </div>
        <?php
    }

    private static function status_badge($status) {
        $labels = [
            'completed'  => ['Abgeschlossen', '#16a34a', '#dcfce7'],
            'processing' => ['Bearbeitung',   '#2563eb', '#dbeafe'],
            'pending'    => ['Ausstehend',    '#d97706', '#fef3c7'],
            'on-hold'    => ['Wartend',       '#d97706', '#fef3c7'],
            'cancelled'  => ['Storniert',     '#6b7280', '#f3f4f6'],
            'failed'     => ['Fehlgeschlagen','#dc2626', '#fee2e2'],
            'refunded'   => ['Erstattet',     '#9333ea', '#f3e8ff'],
        ];
        [$label, $fg, $bg] = $labels[$status] ?? [ucfirst($status), '#6b7280', '#f3f4f6'];
        return '<span style="background:' . esc_attr($bg) . ';color:' . esc_attr($fg) . ';padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">' . esc_html($label) . '</span>';
    }

    private static function render_mail_modal($email, $data) {
        ?>
        <div id="tix-crm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center;padding:20px;">
            <div style="background:#fff;border-radius:14px;max-width:600px;width:100%;max-height:90vh;overflow-y:auto;padding:28px;">
                <h2 style="margin:0 0 6px;">E-Mail an Kunden</h2>
                <p style="margin:0 0 20px;color:#6b7280;font-size:13px;">An: <strong><?php echo esc_html($email); ?></strong></p>

                <div id="tix-crm-mail-result" style="display:none;margin-bottom:16px;"></div>

                <form id="tix-crm-mail-form">
                    <?php wp_nonce_field(self::NONCE_SEND, '_tix_crm_nonce'); ?>
                    <input type="hidden" name="email" value="<?php echo esc_attr($email); ?>">

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Betreff</label>
                        <input type="text" name="subject" required
                               style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Nachricht</label>
                        <?php
                        // Begrüßung fest einfügen — Cursor landet darunter
                        $greeting_name = trim($data['first_name']) ?: 'zusammen';
                        $prefilled     = "Hallo " . $greeting_name . ",\n\n";
                        ?>
                        <textarea name="message" id="tix-crm-mail-textarea" required rows="10"
                                  style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;line-height:1.5;"><?php echo esc_textarea($prefilled); ?></textarea>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;">HTML erlaubt. Platzhalter: {{first_name}}, {{last_name}}, {{email}}</div>
                    </div>

                    <?php if (!empty($data['orders'])): ?>
                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Tickets anhängen (optional)</label>
                        <select name="attach_order_id" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                            <option value="">Keine Tickets anhängen</option>
                            <?php foreach ($data['orders'] as $o): ?>
                                <option value="<?php echo intval($o['id']); ?>">
                                    #<?php echo esc_html($o['number']); ?>
                                    · <?php echo esc_html(date_i18n('d.m.Y', strtotime($o['date']))); ?>
                                    · <?php echo esc_html(wp_trim_words($o['events'], 5, '…')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;">Bei konfiguriertem PDF-Template hängt das PDF automatisch an; Online-Tickets werden als Download-Link in die Mail eingefügt.</div>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
                        <button type="button" class="button" id="tix-crm-modal-close">Abbrechen</button>
                        <button type="submit" class="button button-primary" id="tix-crm-mail-submit">
                            <span class="dashicons dashicons-email" style="margin-top:4px;"></span>
                            Senden
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function(){
            const openBtn = document.getElementById('tix-crm-send-mail-btn');
            const modal   = document.getElementById('tix-crm-modal');
            const closeBtn = document.getElementById('tix-crm-modal-close');
            const form    = document.getElementById('tix-crm-mail-form');
            const result  = document.getElementById('tix-crm-mail-result');
            const submit  = document.getElementById('tix-crm-mail-submit');

            openBtn?.addEventListener('click', () => {
                modal.style.display = 'flex';
                // Cursor ans Ende der Grüßung setzen (zwei Zeilen nach „Hallo X,")
                setTimeout(() => {
                    const ta = document.getElementById('tix-crm-mail-textarea');
                    if (ta) {
                        ta.focus();
                        const len = ta.value.length;
                        ta.setSelectionRange(len, len);
                        ta.scrollTop = 0;
                    }
                }, 30);
            });
            closeBtn?.addEventListener('click', () => { modal.style.display = 'none'; });
            modal?.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });

            form?.addEventListener('submit', async (e) => {
                e.preventDefault();
                submit.disabled = true;
                submit.innerHTML = 'Sende…';

                const fd = new FormData(form);
                fd.append('action', 'tix_crm_send_mail');

                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await res.json();
                    if (data && data.success) {
                        result.style.display = 'block';
                        result.style.cssText += 'background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:10px 14px;border-radius:8px;font-size:13px;';
                        result.textContent = data.data?.message || 'E-Mail gesendet ✓';
                        form.reset();
                        setTimeout(() => { modal.style.display = 'none'; result.style.display = 'none'; }, 2000);
                    } else {
                        result.style.display = 'block';
                        result.style.cssText += 'background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;font-size:13px;';
                        result.textContent = data?.data?.message || 'Versand fehlgeschlagen.';
                    }
                } catch (err) {
                    result.style.display = 'block';
                    result.textContent = 'Netzwerkfehler: ' + err.message;
                } finally {
                    submit.disabled = false;
                    submit.innerHTML = '<span class="dashicons dashicons-email" style="margin-top:4px;"></span> Senden';
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Edit-Modal: Kundendaten bearbeiten ohne WP-Backend-Sprung.
     * Aktualisiert (falls vorhanden) den WP-User + alle tix_orders + WC-Orders
     * für konsistente Darstellung.
     */
    private static function render_edit_modal($email, $data) {
        $is_admin = self::is_admin_view();
        ?>
        <div id="tix-crm-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center;padding:20px;">
            <div style="background:#fff;border-radius:14px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto;padding:28px;">
                <h2 style="margin:0 0 6px;">Kundendaten bearbeiten</h2>
                <p style="margin:0 0 20px;color:#6b7280;font-size:13px;">
                    <?php if ($data['user_id']): ?>
                        Änderungen werden im <strong>WP-Konto</strong> und auf allen <strong>Bestellungen</strong> übernommen.
                    <?php else: ?>
                        <strong>Gast</strong> — Änderungen werden auf allen Bestellungen übernommen.
                    <?php endif; ?>
                </p>

                <div id="tix-crm-edit-result" style="display:none;margin-bottom:16px;"></div>

                <form id="tix-crm-edit-form">
                    <?php wp_nonce_field(self::NONCE_UPDATE, '_tix_crm_edit_nonce'); ?>
                    <input type="hidden" name="original_email" value="<?php echo esc_attr($email); ?>">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Vorname</label>
                            <input type="text" name="first_name" value="<?php echo esc_attr($data['first_name']); ?>"
                                   style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Nachname</label>
                            <input type="text" name="last_name" value="<?php echo esc_attr($data['last_name']); ?>"
                                   style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">E-Mail</label>
                        <input type="email" name="email" value="<?php echo esc_attr($email); ?>" required
                               style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                        <div style="font-size:11px;color:#b45309;margin-top:4px;">⚠ Änderung wirkt sich auf Login (WP-User) und alle Bestellungen aus.</div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Telefon</label>
                        <input type="tel" name="phone" value="<?php echo esc_attr($data['phone']); ?>"
                               style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                    </div>

                    <hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 14px;">
                    <div style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:10px;">Adresse</div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Firma (optional)</label>
                        <input type="text" name="company" value="<?php echo esc_attr($data['company']); ?>"
                               style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Straße + Hausnummer</label>
                        <input type="text" name="address_1" value="<?php echo esc_attr($data['street']); ?>"
                               style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                    </div>

                    <div style="display:grid;grid-template-columns:120px 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">PLZ</label>
                            <input type="text" name="postcode" value="<?php echo esc_attr($data['postcode']); ?>"
                                   style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Ort</label>
                            <input type="text" name="city" value="<?php echo esc_attr($data['city']); ?>"
                                   style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Land</label>
                        <input type="text" name="country" value="<?php echo esc_attr($data['country']); ?>" placeholder="z.B. DE"
                               style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                    </div>

                    <?php if ($is_admin && $data['user_id']): ?>
                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Rolle</label>
                        <?php
                        $current_role = '';
                        $u = get_userdata($data['user_id']);
                        if ($u && !empty($u->roles)) $current_role = $u->roles[0];
                        $editable_roles = [
                            'tix_customer'  => 'Kunde',
                            'tix_organizer' => 'Veranstalter',
                            'subscriber'    => 'Abonnent',
                            'administrator' => 'Administrator',
                        ];
                        ?>
                        <select name="role" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                            <?php foreach ($editable_roles as $r => $label): ?>
                                <option value="<?php echo esc_attr($r); ?>" <?php selected($current_role, $r); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;">Nur Administratoren können Rollen ändern.</div>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:20px;flex-wrap:wrap;">
                        <?php if ($data['user_id']): ?>
                            <button type="button" class="button" id="tix-crm-send-pw-btn" title="Schickt dem Kunden einen Link zum Vergeben/Zurücksetzen des Passworts">
                                <span class="dashicons dashicons-email-alt" style="margin-top:4px;"></span>
                                Passwort-Link senden
                            </button>
                        <?php else: ?>
                            <span style="font-size:12px;color:#94a3b8;">Kein WP-Konto vorhanden — Passwort-Link nicht möglich</span>
                        <?php endif; ?>
                        <div style="display:flex;gap:8px;margin-left:auto;">
                            <button type="button" class="button" id="tix-crm-edit-cancel">Abbrechen</button>
                            <button type="submit" class="button button-primary" id="tix-crm-edit-submit">
                                <span class="dashicons dashicons-yes" style="margin-top:4px;"></span>
                                Speichern
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function(){
            const openBtn  = document.getElementById('tix-crm-edit-btn');
            const modal    = document.getElementById('tix-crm-edit-modal');
            const closeBtn = document.getElementById('tix-crm-edit-cancel');
            const form     = document.getElementById('tix-crm-edit-form');
            const result   = document.getElementById('tix-crm-edit-result');
            const submit   = document.getElementById('tix-crm-edit-submit');
            const pwBtn    = document.getElementById('tix-crm-send-pw-btn');

            openBtn?.addEventListener('click', () => { modal.style.display = 'flex'; });
            closeBtn?.addEventListener('click', () => { modal.style.display = 'none'; });
            modal?.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });

            // Passwort-Link senden
            pwBtn?.addEventListener('click', async () => {
                if (!confirm('Dem Kunden wird eine E-Mail mit einem Link zur Passwort-Vergabe geschickt. Fortfahren?')) return;
                pwBtn.disabled = true;
                const origText = pwBtn.innerHTML;
                pwBtn.innerHTML = 'Sende…';

                const fd = new FormData();
                fd.append('action', 'tix_crm_send_password_link');
                fd.append('_tix_crm_nonce', form.querySelector('input[name=\"_tix_crm_edit_nonce\"]').value);
                fd.append('email', form.querySelector('input[name=\"original_email\"]').value);

                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await res.json();
                    result.style.display = 'block';
                    if (data && data.success) {
                        result.style.cssText += 'background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:10px 14px;border-radius:8px;font-size:13px;';
                        result.textContent = data.data?.message || 'Passwort-Link wurde gesendet ✓';
                    } else {
                        result.style.cssText += 'background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;font-size:13px;';
                        result.textContent = data?.data?.message || 'Versand fehlgeschlagen.';
                    }
                } catch (err) {
                    result.style.display = 'block';
                    result.textContent = 'Netzwerkfehler: ' + err.message;
                } finally {
                    pwBtn.disabled = false;
                    pwBtn.innerHTML = origText;
                }
            });

            form?.addEventListener('submit', async (e) => {
                e.preventDefault();
                submit.disabled = true;
                submit.innerHTML = 'Speichere…';

                const fd = new FormData(form);
                fd.append('action', 'tix_crm_update_customer');

                try {
                    const res = await fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await res.json();
                    if (data && data.success) {
                        result.style.display = 'block';
                        result.style.cssText += 'background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:10px 14px;border-radius:8px;font-size:13px;';
                        result.textContent = data.data?.message || 'Gespeichert ✓';
                        setTimeout(() => {
                            // Bei Email-Änderung auf neue URL navigieren, sonst Seite neu laden
                            if (data.data?.redirect) {
                                window.location.href = data.data.redirect;
                            } else {
                                window.location.reload();
                            }
                        }, 900);
                    } else {
                        result.style.display = 'block';
                        result.style.cssText += 'background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;font-size:13px;';
                        result.textContent = data?.data?.message || 'Speichern fehlgeschlagen.';
                    }
                } catch (err) {
                    result.style.display = 'block';
                    result.textContent = 'Netzwerkfehler: ' + err.message;
                } finally {
                    submit.disabled = false;
                    submit.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top:4px;"></span> Speichern';
                }
            });
        })();
        </script>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════
    // QUERIES — Customer aggregation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Aggregierte Kundenliste aus ALLEN Quellen:
     *   - tix_orders (native)
     *   - wc_orders (WooCommerce HPOS, falls vorhanden)
     * Deduplication by billing_email, Stats-Merge.
     *
     * @return array{rows:array, total:int}
     */
    public static function query_customers($args = []) {
        $args = wp_parse_args($args, [
            'search'   => '',
            'source'   => '',
            'paged'    => 1,
            'per_page' => 25,
            'orderby'  => 'last_order',
            'order'    => 'DESC',
        ]);

        // 1. Native tix_orders-Aggregat laden
        $customers = self::aggregate_native_orders($args);

        // 2. WC HPOS orders dazumergen (falls Tabelle existiert)
        $customers = self::merge_wc_orders($customers, $args);

        // 3. Attribution (Quelle) pro Kunde anreichern
        self::enrich_attribution($customers);

        // 4. Filter: Suche — über Orders-Daten + WP-User-Suche (auch User ohne Bestellungen)
        if ($args['search']) {
            $needle = mb_strtolower($args['search']);

            // 4a. WP-User mit passendem Namen/Login/Email finden und ggf. als Pseudo-Customers zufügen
            //     (User die existieren aber keine Bestellungen haben — sonst wären sie schon drin)
            $matched_users = self::search_wp_users($args['search']);
            foreach ($matched_users as $u) {
                $email_key = strtolower($u->user_email ?: '');
                if (!$email_key) continue;
                if (isset($customers[$email_key])) {
                    // User hat schon Orders → nur user_id sicherstellen
                    if (!$customers[$email_key]['user_id']) {
                        $customers[$email_key]['user_id'] = intval($u->ID);
                    }
                    continue;
                }
                // Pseudo-Customer für User ohne Bestellungen
                $first = (string) get_user_meta($u->ID, 'first_name', true);
                $last  = (string) get_user_meta($u->ID, 'last_name',  true);
                $customers[$email_key] = [
                    'email'         => $u->user_email,
                    'first_name'    => $first,
                    'last_name'     => $last,
                    'phone'         => '',
                    'order_count'   => 0,
                    'total_spent'   => 0.0,
                    'last_order'    => '',
                    'first_order'   => $u->user_registered ?: '',
                    'event_count'   => 0,
                    'ticket_count'  => 0,
                    'user_id'       => intval($u->ID),
                    'source'        => '',
                    'utm_source'    => '',
                    'utm_campaign'  => '',
                    'is_user_only'  => true, // Marker: kein Customer aus Orders, nur WP-User
                ];
            }

            // 4b. Filter auf den nun erweiterten Pool — auch user_login + display_name + nicename matchen
            $customers = array_filter($customers, function($c) use ($needle) {
                $hay = mb_strtolower($c['email'] . ' ' . $c['first_name'] . ' ' . $c['last_name']);
                if (!empty($c['user_id'])) {
                    $u = get_userdata($c['user_id']);
                    if ($u) {
                        $hay .= ' ' . mb_strtolower($u->user_login . ' ' . $u->display_name . ' ' . $u->user_nicename);
                    }
                }
                return strpos($hay, $needle) !== false;
            });
        }

        // 5. Filter: Quelle (Source)
        if ($args['source']) {
            $customers = array_filter($customers, fn($c) => $c['source'] === $args['source']);
        }

        // 6. User-Rollen anreichern
        foreach ($customers as &$c) {
            $c['user_roles'] = '';
            if (!$c['user_id']) {
                $u = get_user_by('email', $c['email']);
                if ($u) $c['user_id'] = $u->ID;
            }
            if ($c['user_id']) {
                $u = get_userdata($c['user_id']);
                if ($u) {
                    $c['user_roles'] = implode(', ', array_map([__CLASS__, 'role_label'], (array) $u->roles));
                }
            }
        }
        unset($c);

        // 7. Sortierung
        $orderby = $args['orderby'];
        $dir = strtoupper($args['order']) === 'ASC' ? 1 : -1;
        usort($customers, function($a, $b) use ($orderby, $dir) {
            switch ($orderby) {
                case 'total_spent': $cmp = ($a['total_spent'] <=> $b['total_spent']); break;
                case 'order_count': $cmp = ($a['order_count'] <=> $b['order_count']); break;
                case 'name':        $cmp = strcasecmp($a['last_name'], $b['last_name']); break;
                case 'email':       $cmp = strcasecmp($a['email'], $b['email']); break;
                case 'last_order':
                default:            $cmp = strcmp($a['last_order'] ?: '', $b['last_order'] ?: ''); break;
            }
            return $cmp * $dir;
        });

        // 8. Paginierung
        $total    = count($customers);
        $offset   = max(0, ((int)$args['paged'] - 1) * (int)$args['per_page']);
        $per_page = max(1, (int)$args['per_page']);
        $rows     = array_values(array_slice($customers, $offset, $per_page));

        return ['rows' => $rows, 'total' => $total];
    }

    /** Aggregiert native tix_orders pro Email */
    /**
     * Sucht WP-User nach Begriff in user_login / user_email / display_name /
     * first_name / last_name / user_nicename. Gibt max. 50 Treffer zurück.
     * Wird vom CRM-Search benutzt um auch User ohne Bestellungen zu finden.
     */
    private static function search_wp_users($needle) {
        if (!$needle || strlen($needle) < 2) return [];
        global $wpdb;

        $like = '%' . $wpdb->esc_like($needle) . '%';

        // Direkter Match auf wp_users (login/email/display/nicename)
        $sql = "SELECT DISTINCT u.ID, u.user_login, u.user_email, u.display_name, u.user_nicename, u.user_registered
                FROM {$wpdb->users} u
                LEFT JOIN {$wpdb->usermeta} m_first ON m_first.user_id = u.ID AND m_first.meta_key = 'first_name'
                LEFT JOIN {$wpdb->usermeta} m_last  ON m_last.user_id  = u.ID AND m_last.meta_key  = 'last_name'
                WHERE u.user_login    LIKE %s
                   OR u.user_email    LIKE %s
                   OR u.display_name  LIKE %s
                   OR u.user_nicename LIKE %s
                   OR m_first.meta_value LIKE %s
                   OR m_last.meta_value  LIKE %s
                LIMIT 50";

        return (array) $wpdb->get_results($wpdb->prepare(
            $sql, $like, $like, $like, $like, $like, $like
        ));
    }

    private static function aggregate_native_orders($args) {
        global $wpdb;
        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        // Tabelle prüfen
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return [];

        $where = ['1=1'];
        $params = [];

        // Organizer-Beschränkung
        $visible = self::visible_event_ids();
        if (is_array($visible)) {
            if (empty($visible)) return [];
            $placeholders = implode(',', array_fill(0, count($visible), '%d'));
            $where[] = "id IN (SELECT order_id FROM $ti WHERE event_id IN ($placeholders))";
            $params = array_merge($params, array_map('intval', $visible));
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT
                    billing_email AS email,
                    (SELECT billing_first_name FROM $t x WHERE x.billing_email = $t.billing_email AND x.billing_first_name <> '' ORDER BY x.date_created DESC LIMIT 1) AS first_name,
                    (SELECT billing_last_name  FROM $t x WHERE x.billing_email = $t.billing_email AND x.billing_last_name  <> '' ORDER BY x.date_created DESC LIMIT 1) AS last_name,
                    (SELECT customer_id        FROM $t x WHERE x.billing_email = $t.billing_email AND x.customer_id > 0 ORDER BY x.date_created DESC LIMIT 1) AS user_id,
                    (SELECT billing_phone      FROM $t x WHERE x.billing_email = $t.billing_email AND x.billing_phone <> '' ORDER BY x.date_created DESC LIMIT 1) AS phone,
                    COUNT(*) AS order_count,
                    SUM(CASE WHEN status IN ('completed','processing') THEN total ELSE 0 END) AS total_spent,
                    MIN(date_created) AS first_order,
                    MAX(date_created) AS last_order
                FROM $t
                WHERE $where_sql
                GROUP BY billing_email";

        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        $customers = [];
        foreach ($rows as $r) {
            if (!$r['email']) continue;
            $customers[$r['email']] = [
                'email'       => $r['email'],
                'first_name'  => $r['first_name'] ?: '',
                'last_name'   => $r['last_name']  ?: '',
                'user_id'     => intval($r['user_id']),
                'phone'       => $r['phone'] ?: '',
                'order_count' => intval($r['order_count']),
                'total_spent' => (float) $r['total_spent'],
                'first_order' => $r['first_order'],
                'last_order'  => $r['last_order'],
                'source'      => '',
            ];
        }
        return $customers;
    }

    /** Mergt WC HPOS-Orders in die bestehende Kundenliste */
    private static function merge_wc_orders(array $customers, $args) {
        global $wpdb;
        $wc_t = $wpdb->prefix . 'wc_orders';
        $wc_a = $wpdb->prefix . 'wc_order_addresses';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") !== $wc_t) return $customers;
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_a'") !== $wc_a) return $customers;

        // Organizer-Beschränkung: versuche Events via _tix_parent_event_id → Produkt → Order zu mappen
        $visible = self::visible_event_ids();
        $where = ["o.type = 'shop_order'", "o.status IN ('wc-completed','wc-processing','wc-on-hold','wc-pending')"];
        $params = [];

        if (is_array($visible)) {
            if (empty($visible)) return $customers; // nichts von WC für diesen Organizer
            $wc_pl = $wpdb->prefix . 'wc_order_product_lookup';
            $pm    = $wpdb->postmeta;
            $placeholders = implode(',', array_fill(0, count($visible), '%d'));
            // Order muss mindestens ein Produkt haben, dessen _tix_parent_event_id in den visible events ist
            $where[] = "o.id IN (
                SELECT DISTINCT opl.order_id FROM $wc_pl opl
                INNER JOIN $pm pm ON pm.post_id = opl.product_id AND pm.meta_key = '_tix_parent_event_id'
                WHERE CAST(pm.meta_value AS UNSIGNED) IN ($placeholders)
            )";
            $params = array_merge($params, array_map('intval', $visible));
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT
                    COALESCE(a.email, o.billing_email) AS email,
                    a.first_name,
                    a.last_name,
                    a.phone,
                    o.customer_id,
                    o.total_amount,
                    o.status,
                    o.date_created_gmt
                FROM $wc_t o
                LEFT JOIN $wc_a a ON a.order_id = o.id AND a.address_type = 'billing'
                WHERE $where_sql";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);

        foreach ($rows as $r) {
            $email = $r->email;
            if (!$email) continue;
            if (!isset($customers[$email])) {
                $customers[$email] = [
                    'email'       => $email,
                    'first_name'  => '',
                    'last_name'   => '',
                    'user_id'     => 0,
                    'phone'       => '',
                    'order_count' => 0,
                    'total_spent' => 0.0,
                    'first_order' => null,
                    'last_order'  => null,
                    'source'      => '',
                ];
            }
            $c = &$customers[$email];
            if (!$c['first_name'] && $r->first_name) $c['first_name'] = $r->first_name;
            if (!$c['last_name']  && $r->last_name)  $c['last_name']  = $r->last_name;
            if (!$c['user_id']    && $r->customer_id) $c['user_id']   = intval($r->customer_id);
            if (!$c['phone']      && $r->phone)      $c['phone']      = $r->phone;
            $c['order_count']++;
            if (in_array($r->status, ['wc-completed', 'wc-processing'], true)) {
                $c['total_spent'] += (float) $r->total_amount;
            }
            if (!$c['first_order'] || $r->date_created_gmt < $c['first_order']) $c['first_order'] = $r->date_created_gmt;
            if (!$c['last_order']  || $r->date_created_gmt > $c['last_order'])  $c['last_order']  = $r->date_created_gmt;
            unset($c);
        }

        return $customers;
    }

    /** Lädt Attribution (_tix_ol_source) pro Kunde aus den jüngsten Orders */
    private static function enrich_attribution(array &$customers) {
        if (empty($customers)) return;

        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';
        $emails = array_keys($customers);

        // Jüngste Order-ID pro Email (nur native — _tix_ol_source wird nur dort gesetzt)
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $placeholders = implode(',', array_fill(0, count($emails), '%s'));
            $sql = "SELECT billing_email, MAX(id) AS oid FROM $t WHERE billing_email IN ($placeholders) GROUP BY billing_email";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$emails));
            foreach ($rows as $r) {
                $src = get_post_meta($r->oid, '_tix_ol_source', true);
                if ($src && isset($customers[$r->billing_email])) {
                    $customers[$r->billing_email]['source'] = $src;
                }
            }
        }
    }

    /** Übersetzt Rollen-Keys zu lesbaren Labels */
    private static function role_label($role) {
        $labels = [
            'administrator' => 'Admin',
            'tix_organizer' => 'Veranstalter',
            'tix_customer'  => 'Kunde',
            'customer'      => 'Kunde (WC)',
            'subscriber'    => 'Abonnent',
        ];
        return $labels[$role] ?? $role;
    }

    /** Sichtbare Quellen für den Filter-Dropdown */
    private static function available_sources() {
        global $wpdb;
        $sources = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tix_ol_source' AND meta_value <> '' LIMIT 50");
        return $sources ?: [];
    }

    /** Summary-Stats (für die KPI-Kacheln) */
    private static function query_summary_stats() {
        global $wpdb;
        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        $where = "status IN ('completed', 'processing')";
        $params = [];

        $visible = self::visible_event_ids();
        if (is_array($visible)) {
            if (empty($visible)) return ['customers' => 0, 'orders' => 0, 'revenue' => 0, 'avg_ltv' => 0];
            $placeholders = implode(',', array_fill(0, count($visible), '%d'));
            $where .= " AND id IN (SELECT order_id FROM $ti WHERE event_id IN ($placeholders))";
            $params = array_map('intval', $visible);
        }

        $sql = "SELECT
                    COUNT(DISTINCT billing_email) AS customers,
                    COUNT(*) AS orders,
                    SUM(total) AS revenue
                FROM $t WHERE $where";
        $row = $params ? $wpdb->get_row($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_row($sql, ARRAY_A);
        $row = $row ?: ['customers' => 0, 'orders' => 0, 'revenue' => 0];

        $row['avg_ltv'] = ($row['customers'] > 0) ? ((float)$row['revenue'] / (int)$row['customers']) : 0;
        return $row;
    }

    // ═══════════════════════════════════════════════════════════════
    // QUERIES — Customer detail
    // ═══════════════════════════════════════════════════════════════

    /**
     * Detail-Daten eines Kunden: alle Bestellungen (native + WC), Events, Attribution.
     */
    public static function get_customer_detail($email) {
        global $wpdb;
        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        // Native Bestellungen
        $orders = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $orders = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t WHERE billing_email = %s ORDER BY date_created DESC",
                $email
            ));
        }

        // WC-Bestellungen (HPOS)
        $wc_t = $wpdb->prefix . 'wc_orders';
        $wc_a = $wpdb->prefix . 'wc_order_addresses';
        $wc_orders_raw = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t) {
            $wc_orders_raw = $wpdb->get_results($wpdb->prepare(
                "SELECT o.*, a.first_name, a.last_name, a.phone, a.address_1, a.city, a.postcode, a.country
                 FROM $wc_t o
                 LEFT JOIN $wc_a a ON a.order_id = o.id AND a.address_type = 'billing'
                 WHERE o.type = 'shop_order' AND (o.billing_email = %s OR a.email = %s)
                 ORDER BY o.date_created_gmt DESC",
                $email, $email
            ));
        }

        if (empty($orders) && empty($wc_orders_raw)) return null;

        // Aggregate
        $total_spent   = 0;
        $ticket_count  = 0;
        $events_map    = [];
        $orders_data   = [];
        $first_order   = null;
        $last_order    = null;
        $phone         = '';
        $address_parts = [];
        $addr_company  = '';
        $addr_street   = '';
        $addr_postcode = '';
        $addr_city     = '';
        $addr_country  = '';
        $last_first_name = '';
        $last_last_name  = '';
        $user_id       = 0;

        // Native Orders verarbeiten
        foreach ($orders as $o) {
            if (in_array($o->status, ['completed', 'processing'], true)) {
                $total_spent += (float) $o->total;
            }
            if (!$first_order || $o->date_created < $first_order) $first_order = $o->date_created;
            if (!$last_order  || $o->date_created > $last_order)  $last_order  = $o->date_created;
            if (!$phone && $o->billing_phone) $phone = $o->billing_phone;
            if (!$last_first_name && $o->billing_first_name) $last_first_name = $o->billing_first_name;
            if (!$last_last_name  && $o->billing_last_name)  $last_last_name  = $o->billing_last_name;
            if (!$user_id && $o->customer_id) $user_id = (int) $o->customer_id;
            if (!$addr_company  && !empty($o->billing_company))   $addr_company  = $o->billing_company;
            if (!$addr_street   && !empty($o->billing_address_1)) $addr_street   = $o->billing_address_1;
            if (!$addr_postcode && !empty($o->billing_postcode))  $addr_postcode = $o->billing_postcode;
            if (!$addr_city     && !empty($o->billing_city))      $addr_city     = $o->billing_city;
            if (!$addr_country  && !empty($o->billing_country))   $addr_country  = $o->billing_country;
            if (empty($address_parts) && $o->billing_address_1) {
                $address_parts = array_filter([
                    $o->billing_address_1,
                    trim(($o->billing_postcode ?? '') . ' ' . ($o->billing_city ?? '')),
                    $o->billing_country,
                ]);
            }

            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $ti WHERE order_id = %d", $o->id));
            $event_titles = [];
            foreach ($items as $it) {
                $ticket_count += (int) $it->quantity;
                if ($it->event_id) {
                    if (!isset($events_map[$it->event_id])) {
                        $events_map[$it->event_id] = [
                            'id'           => (int) $it->event_id,
                            'title'        => get_the_title($it->event_id) ?: 'Event #' . $it->event_id,
                            'date'         => get_post_meta($it->event_id, '_tix_date_start', true),
                            'ticket_count' => 0,
                        ];
                    }
                    $events_map[$it->event_id]['ticket_count'] += (int) $it->quantity;
                    $event_titles[] = get_the_title($it->event_id) ?: '';
                }
            }
            $orders_data[] = [
                'id'     => (int) $o->id,
                'number' => $o->order_number,
                'date'   => $o->date_created,
                'status' => $o->status,
                'total'  => (float) $o->total,
                'events' => implode(', ', array_unique(array_filter($event_titles))),
            ];
        }

        // WC-Orders verarbeiten (via wc_get_order → items, für Event-Mapping)
        foreach ($wc_orders_raw as $wo) {
            $wc_status = preg_replace('/^wc-/', '', $wo->status);
            if (in_array($wc_status, ['completed', 'processing'], true)) {
                $total_spent += (float) $wo->total_amount;
            }
            if (!$first_order || $wo->date_created_gmt < $first_order) $first_order = $wo->date_created_gmt;
            if (!$last_order  || $wo->date_created_gmt > $last_order)  $last_order  = $wo->date_created_gmt;
            if (!$phone && $wo->phone) $phone = $wo->phone;
            if (!$last_first_name && $wo->first_name) $last_first_name = $wo->first_name;
            if (!$last_last_name  && $wo->last_name)  $last_last_name  = $wo->last_name;
            if (!$user_id && $wo->customer_id) $user_id = (int) $wo->customer_id;
            if (!$addr_street   && !empty($wo->address_1)) $addr_street   = $wo->address_1;
            if (!$addr_postcode && !empty($wo->postcode))  $addr_postcode = $wo->postcode;
            if (!$addr_city     && !empty($wo->city))      $addr_city     = $wo->city;
            if (!$addr_country  && !empty($wo->country))   $addr_country  = $wo->country;
            if (empty($address_parts) && $wo->address_1) {
                $address_parts = array_filter([
                    $wo->address_1,
                    trim(($wo->postcode ?? '') . ' ' . ($wo->city ?? '')),
                    $wo->country,
                ]);
            }

            // Events via wc_order_product_lookup + _tix_parent_event_id
            $wc_pl = $wpdb->prefix . 'wc_order_product_lookup';
            $event_titles = [];
            if ($wpdb->get_var("SHOW TABLES LIKE '$wc_pl'") === $wc_pl) {
                $products = $wpdb->get_results($wpdb->prepare(
                    "SELECT product_id, product_qty FROM $wc_pl WHERE order_id = %d",
                    $wo->id
                ));
                foreach ($products as $p) {
                    $eid = intval(get_post_meta($p->product_id, '_tix_parent_event_id', true));
                    $ticket_count += (int) $p->product_qty;
                    if ($eid) {
                        if (!isset($events_map[$eid])) {
                            $events_map[$eid] = [
                                'id'           => $eid,
                                'title'        => get_the_title($eid) ?: 'Event #' . $eid,
                                'date'         => get_post_meta($eid, '_tix_date_start', true),
                                'ticket_count' => 0,
                            ];
                        }
                        $events_map[$eid]['ticket_count'] += (int) $p->product_qty;
                        $event_titles[] = get_the_title($eid) ?: '';
                    }
                }
            }

            $orders_data[] = [
                'id'     => (int) $wo->id,
                'number' => $wo->id,
                'date'   => $wo->date_created_gmt,
                'status' => $wc_status,
                'total'  => (float) $wo->total_amount,
                'events' => implode(', ', array_unique(array_filter($event_titles))),
            ];
        }

        // nach Datum sortieren (neueste zuerst)
        usort($orders_data, fn($a, $b) => strcmp($b['date'], $a['date']));

        // Attribution aus Order-Meta (nur native Orders, da _tix_ol_source nur dort existiert)
        $source = '';
        $utm_source = '';
        $utm_campaign = '';
        foreach ($orders as $o) {
            if (!$source) {
                $s = get_post_meta($o->id, '_tix_ol_source', true);
                if ($s) $source = $s;
            }
            $camp = get_option('_tix_order_campaign_' . $o->id);
            if ($camp && is_array($camp)) {
                if (!$utm_source && !empty($camp['source'])) $utm_source   = $camp['source'];
                if (!$utm_campaign && !empty($camp['name'])) $utm_campaign = $camp['name'];
            }
            if ($source && $utm_source) break;
        }

        // WP-User verknüpfen
        if (!$user_id) {
            $u = get_user_by('email', $email);
            if ($u) $user_id = $u->ID;
        }
        $username = '';
        $user_roles = '';
        $user_registered = '';
        if ($user_id) {
            $u = get_userdata($user_id);
            if ($u) {
                $username        = $u->user_login;
                $user_roles      = implode(', ', array_map([__CLASS__, 'role_label'], (array) $u->roles));
                $user_registered = $u->user_registered ? date_i18n('d.m.Y', strtotime($u->user_registered)) : '';
            }
        }

        $order_count = count($orders_data);
        return [
            'email'           => $email,
            'first_name'      => $last_first_name,
            'last_name'       => $last_last_name,
            'company'         => $addr_company,
            'street'          => $addr_street,
            'postcode'        => $addr_postcode,
            'city'            => $addr_city,
            'country'         => $addr_country,
            'phone'           => $phone,
            'address'         => $address_parts ? implode("\n", $address_parts) : '',
            'user_id'         => $user_id,
            'username'        => $username,
            'user_roles'      => $user_roles,
            'user_registered' => $user_registered,
            'order_count'     => $order_count,
            'total_spent'     => $total_spent,
            'avg_order'       => $order_count > 0 ? $total_spent / $order_count : 0,
            'ticket_count'    => $ticket_count,
            'event_count'     => count($events_map),
            'first_order'     => $first_order,
            'last_order'      => $last_order,
            'orders'          => $orders_data,
            'events'          => array_values($events_map),
            'source'          => $source,
            'utm_source'      => $utm_source,
            'utm_campaign'    => $utm_campaign,
        ];
    }

    /** True wenn Kunde in mindestens einem der übergebenen Events gebucht hat (native + WC) */
    private static function customer_has_event_in($email, array $event_ids) {
        global $wpdb;
        if (empty($event_ids)) return false;

        // Native tix_orders
        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
            $params = array_merge([$email], array_map('intval', $event_ids));
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM $t o
                 INNER JOIN $ti i ON i.order_id = o.id
                 WHERE o.billing_email = %s AND i.event_id IN ($placeholders)
                 LIMIT 1",
                ...$params
            ));
            if ($hit) return true;
        }

        // WC HPOS — via wc_order_product_lookup + _tix_parent_event_id
        $wc_t  = $wpdb->prefix . 'wc_orders';
        $wc_a  = $wpdb->prefix . 'wc_order_addresses';
        $wc_pl = $wpdb->prefix . 'wc_order_product_lookup';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t
            && $wpdb->get_var("SHOW TABLES LIKE '$wc_pl'") === $wc_pl) {
            $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
            $params = array_merge([$email, $email], array_map('intval', $event_ids));
            $pm = $wpdb->postmeta;
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM $wc_t o
                 LEFT JOIN $wc_a a ON a.order_id = o.id AND a.address_type = 'billing'
                 INNER JOIN $wc_pl opl ON opl.order_id = o.id
                 INNER JOIN $pm pm ON pm.post_id = opl.product_id AND pm.meta_key = '_tix_parent_event_id'
                 WHERE (o.billing_email = %s OR a.email = %s)
                   AND CAST(pm.meta_value AS UNSIGNED) IN ($placeholders)
                 LIMIT 1",
                ...$params
            ));
            if ($hit) return true;
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════
    // AJAX — Mail senden
    // ═══════════════════════════════════════════════════════════════

    public static function ajax_send_mail() {
        if (!self::current_user_can_access()) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        check_ajax_referer(self::NONCE_SEND, '_tix_crm_nonce');

        $email    = sanitize_email($_POST['email'] ?? '');
        $subject  = sanitize_text_field($_POST['subject'] ?? '');
        $message  = wp_kses_post($_POST['message'] ?? '');
        $order_id = intval($_POST['attach_order_id'] ?? 0);

        if (!$email || !is_email($email)) wp_send_json_error(['message' => 'Ungültige E-Mail.']);
        if (empty($subject) || empty($message)) wp_send_json_error(['message' => 'Betreff und Nachricht sind erforderlich.']);

        // Organizer darf nur seine Kunden anmailen
        if (!self::is_admin_view()) {
            $visible = self::visible_event_ids();
            if (!empty($visible) && !self::customer_has_event_in($email, $visible)) {
                wp_send_json_error(['message' => 'Kein Zugriff auf diesen Kunden.']);
            }
        }

        // Platzhalter ersetzen
        $first_name = '';
        $last_name  = '';
        $u = get_user_by('email', $email);
        if ($u) {
            $first_name = $u->first_name;
            $last_name  = $u->last_name;
        }
        if (!$first_name) {
            global $wpdb;
            $t = $wpdb->prefix . 'tix_orders';
            $row = $wpdb->get_row($wpdb->prepare("SELECT billing_first_name, billing_last_name FROM $t WHERE billing_email = %s ORDER BY date_created DESC LIMIT 1", $email));
            if ($row) {
                $first_name = $row->billing_first_name;
                $last_name  = $row->billing_last_name;
            }
        }
        $replacements = [
            '{{first_name}}' => $first_name,
            '{{last_name}}'  => $last_name,
            '{{email}}'      => $email,
        ];
        $message = strtr($message, $replacements);
        $subject = strtr($subject, $replacements);

        // Body bauen: message ist (HTML-)Rich-Text, wir wrappen in generic template
        $body = nl2br($message);

        // Tickets generieren: PDFs als Attachments (falls Template) + Download-Links im Body
        $attachments     = [];
        $temp_files      = []; // zum Cleanup nach Versand
        $attached_count  = 0;
        $tickets_block   = '';    // Wird erst nach Loop zusammengebaut (Intro abhängig von attached_count)
        $ticket_items    = [];
        if ($order_id > 0 && class_exists('TIX_Tickets')) {
            $tickets = TIX_Tickets::get_all_tickets_for_order($order_id);
            if (!empty($tickets)) {
                $upload_dir = wp_upload_dir();
                $tmp_dir = trailingslashit($upload_dir['basedir']) . 'tix-crm-mail-tmp';
                if (!file_exists($tmp_dir)) {
                    @wp_mkdir_p($tmp_dir);
                    @file_put_contents($tmp_dir . '/.htaccess', "deny from all\n");
                }

                foreach ($tickets as $t) {
                    $ticket_id = intval($t['id'] ?? 0);
                    $code  = $t['code'] ?? '';
                    $dlurl = $t['download_url'] ?? '';
                    if (!$dlurl && $ticket_id) {
                        $dlurl = TIX_Tickets::get_download_url($ticket_id);
                    }

                    // PDF nur anhängen wenn ein Template konfiguriert ist (sonst ist's ein Online-Ticket)
                    if ($ticket_id && TIX_Tickets::has_pdf_template($ticket_id)) {
                        $pdf_binary = TIX_Tickets::get_pdf_binary($ticket_id);
                        if ($pdf_binary) {
                            $safe_code = preg_replace('/[^A-Z0-9]/i', '', $code) ?: (string) $ticket_id;
                            $file_path = $tmp_dir . '/ticket-' . $safe_code . '.pdf';
                            if (file_put_contents($file_path, $pdf_binary)) {
                                $attachments[] = $file_path;
                                $temp_files[]  = $file_path;
                                $attached_count++;
                            }
                        }
                    }

                    $label = $ticket_id ? TIX_Tickets::ticket_type_label($ticket_id) : 'Ticket';
                    $li  = '<li style="padding:10px 0;border-bottom:1px solid #f3f4f6;">';
                    $li .= '<strong>' . esc_html($code) . '</strong>';
                    if ($dlurl) $li .= ' <a href="' . esc_url($dlurl) . '" style="color:#2563eb;text-decoration:none;margin-left:8px;">' . esc_html($label) . ' öffnen →</a>';
                    $li .= '</li>';
                    $ticket_items[] = $li;
                }

                // Intro je nach Art des Versands
                $tickets_block  = '<hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb;">';
                $tickets_block .= '<h3 style="margin:0 0 12px;font-size:15px;">Deine Tickets</h3>';
                if ($attached_count > 0) {
                    $tickets_block .= '<p style="margin:0 0 12px;font-size:13px;color:#64748b;">Deine Tickets hängen als <strong>PDF</strong> an dieser E-Mail. Falls du sie nicht öffnen kannst, nutze die Links unten.</p>';
                } else {
                    $tickets_block .= '<p style="margin:0 0 12px;font-size:13px;color:#64748b;">Deine Online-Tickets kannst du über die folgenden Links abrufen:</p>';
                }
                $tickets_block .= '<ul style="list-style:none;padding:0;margin:0;">';
                $tickets_block .= implode('', $ticket_items);
                $tickets_block .= '</ul>';

                $body .= $tickets_block;
            }
        }

        // Via TIX_Emails::build_generic_email_html rendern
        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html($subject, $body, '', '')
            : '<html><body>' . $body . '</body></html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($email, $subject, $html, $headers, $attachments);

        // Temp-PDFs nach Versand wieder löschen
        foreach ($temp_files as $f) { @unlink($f); }

        if ($sent) {
            $msg = 'E-Mail an ' . $email . ' gesendet ✓';
            if ($attached_count) {
                $msg .= ' (mit ' . $attached_count . ' Ticket-PDF' . ($attached_count > 1 ? 's' : '') . ' als Anhang)';
            } elseif ($tickets_block !== '') {
                $msg .= ' (Online-Tickets: Download-Links im Mail-Body)';
            }
            wp_send_json_success(['message' => $msg]);
        } else {
            wp_send_json_error(['message' => 'Versand fehlgeschlagen. Prüfe Mail-Konfiguration.']);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // CSV Export
    // ═══════════════════════════════════════════════════════════════

    /**
     * AJAX: Kundendaten aktualisieren.
     * Synct WP-User (wenn vorhanden) + alle tix_orders + WC-Orders für diese Email.
     * Email-Änderung wirkt auf: user_email, user_login (nur wenn login=email), alle Order-Billing-Felder.
     */
    public static function ajax_update_customer() {
        if (!self::current_user_can_access()) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        check_ajax_referer(self::NONCE_UPDATE, '_tix_crm_edit_nonce');

        $original_email = sanitize_email($_POST['original_email'] ?? '');
        $new_email      = sanitize_email($_POST['email'] ?? '');
        $first_name     = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name      = sanitize_text_field($_POST['last_name'] ?? '');
        $phone          = sanitize_text_field($_POST['phone'] ?? '');
        $company        = sanitize_text_field($_POST['company'] ?? '');
        $address_1      = sanitize_text_field($_POST['address_1'] ?? '');
        $postcode       = sanitize_text_field($_POST['postcode'] ?? '');
        $city           = sanitize_text_field($_POST['city'] ?? '');
        $country        = sanitize_text_field($_POST['country'] ?? '');
        $new_role       = sanitize_key($_POST['role'] ?? '');
        // Empty-Strings explizit: wir wollen Adresse leeren können
        $addr_provided  = isset($_POST['address_1']) || isset($_POST['postcode']) || isset($_POST['city']) || isset($_POST['country']) || isset($_POST['company']);

        if (!$original_email || !is_email($original_email)) wp_send_json_error(['message' => 'Ungültige Ursprungs-E-Mail.']);
        if (!$new_email || !is_email($new_email))          wp_send_json_error(['message' => 'Ungültige E-Mail.']);

        // Organizer darf nur seine Kunden editieren
        if (!self::is_admin_view()) {
            $visible = self::visible_event_ids();
            if (!empty($visible) && !self::customer_has_event_in($original_email, $visible)) {
                wp_send_json_error(['message' => 'Kein Zugriff auf diesen Kunden.']);
            }
        }

        $email_changed = ($new_email !== $original_email);
        if ($email_changed) {
            // Konflikt: existiert ein anderer WP-User mit der neuen E-Mail?
            $collision = get_user_by('email', $new_email);
            $orig_user = get_user_by('email', $original_email);
            if ($collision && (!$orig_user || $collision->ID !== $orig_user->ID)) {
                wp_send_json_error(['message' => 'Diese E-Mail ist bereits einem anderen Nutzer zugeordnet.']);
            }
        }

        global $wpdb;
        $updates = [];

        // ── 1. WP-User aktualisieren (falls vorhanden) ──
        $user = get_user_by('email', $original_email);
        if ($user) {
            $user_data = ['ID' => $user->ID];
            if ($first_name !== '') $user_data['first_name'] = $first_name;
            if ($last_name !== '')  $user_data['last_name']  = $last_name;
            if (trim($first_name . ' ' . $last_name)) {
                $user_data['display_name'] = trim($first_name . ' ' . $last_name);
            }
            if ($email_changed) $user_data['user_email'] = $new_email;

            $res = wp_update_user($user_data);
            if (is_wp_error($res)) {
                wp_send_json_error(['message' => 'WP-User-Update fehlgeschlagen: ' . $res->get_error_message()]);
            }

            // Rolle ändern (nur Admin, nur wenn explizit gewählt und nicht bestehende Rolle)
            if (self::is_admin_view() && $new_role) {
                $allowed = ['tix_customer', 'tix_organizer', 'subscriber', 'administrator'];
                if (in_array($new_role, $allowed, true) && !in_array($new_role, (array) $user->roles, true)) {
                    // Schutz: nicht sich selbst aus Admin entfernen
                    if ($user->ID === get_current_user_id() && in_array('administrator', (array) $user->roles, true) && $new_role !== 'administrator') {
                        // Silent skip
                    } else {
                        $user->set_role($new_role);
                        $updates[] = 'Rolle';
                    }
                }
            }
            $updates[] = 'WP-Konto';
        }

        // ── 2. tix_orders aktualisieren ──
        $t = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $data_set = [];
            if ($first_name !== '') $data_set['billing_first_name'] = $first_name;
            if ($last_name !== '')  $data_set['billing_last_name']  = $last_name;
            if ($phone !== '')      $data_set['billing_phone']      = $phone;
            if ($email_changed)     $data_set['billing_email']      = $new_email;
            if ($addr_provided) {
                $data_set['billing_company']     = $company;
                $data_set['billing_address_1']   = $address_1;
                $data_set['billing_postcode']    = $postcode;
                $data_set['billing_city']        = $city;
                $data_set['billing_country']     = $country ?: 'DE';
            }
            if (!empty($data_set)) {
                $n = $wpdb->update($t, $data_set, ['billing_email' => $original_email]);
                if ($n > 0) $updates[] = $n . ' native Bestellungen';
            }
        }

        // ── 3. WC-Orders aktualisieren (HPOS) ──
        $wc_t = $wpdb->prefix . 'wc_orders';
        $wc_a = $wpdb->prefix . 'wc_order_addresses';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t) {
            $wc_order_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $wc_t WHERE billing_email = %s", $original_email));
            if (!empty($wc_order_ids)) {
                if ($email_changed) {
                    $wpdb->update($wc_t, ['billing_email' => $new_email], ['billing_email' => $original_email]);
                }
                // Addresses-Tabelle: billing-Zeile updaten (via wc_get_order falls verfügbar, sonst SQL direkt)
                if (function_exists('wc_get_order')) {
                    foreach ($wc_order_ids as $oid) {
                        $order = wc_get_order((int) $oid);
                        if (!$order) continue;
                        if ($first_name !== '') $order->set_billing_first_name($first_name);
                        if ($last_name !== '')  $order->set_billing_last_name($last_name);
                        if ($phone !== '')      $order->set_billing_phone($phone);
                        if ($email_changed)     $order->set_billing_email($new_email);
                        if ($addr_provided) {
                            $order->set_billing_company($company);
                            $order->set_billing_address_1($address_1);
                            $order->set_billing_postcode($postcode);
                            $order->set_billing_city($city);
                            $order->set_billing_country($country ?: 'DE');
                        }
                        $order->save();
                    }
                } else {
                    $addr_update = [];
                    if ($first_name !== '') $addr_update['first_name'] = $first_name;
                    if ($last_name !== '')  $addr_update['last_name']  = $last_name;
                    if ($phone !== '')      $addr_update['phone']      = $phone;
                    if ($email_changed)     $addr_update['email']      = $new_email;
                    if ($addr_provided) {
                        $addr_update['company']   = $company;
                        $addr_update['address_1'] = $address_1;
                        $addr_update['postcode']  = $postcode;
                        $addr_update['city']      = $city;
                        $addr_update['country']   = $country ?: 'DE';
                    }
                    if (!empty($addr_update) && !empty($wc_order_ids)) {
                        $placeholders = implode(',', array_fill(0, count($wc_order_ids), '%d'));
                        $params = array_merge(array_values($addr_update), array_map('intval', $wc_order_ids));
                        $set_sql = implode(', ', array_map(fn($k) => "$k = %s", array_keys($addr_update)));
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $wc_a SET $set_sql WHERE address_type = 'billing' AND order_id IN ($placeholders)",
                            ...$params
                        ));
                    }
                }
                $updates[] = count($wc_order_ids) . ' WC-Bestellungen';
            }
        }

        // Response + Redirect bei Email-Änderung
        $response = [
            'message' => 'Gespeichert ✓' . (empty($updates) ? '' : ' — aktualisiert: ' . implode(', ', array_unique($updates))),
        ];
        if ($email_changed) {
            $response['redirect'] = admin_url('admin.php?page=' . self::PAGE_SLUG . '&email=' . urlencode($new_email));
        }
        wp_send_json_success($response);
    }

    /**
     * AJAX: Passwort-Vergabe-Link an Kunden schicken.
     * Nutzt dieselbe Aktivierungs-Mail wie bei der Konto-Erstellung im Checkout:
     * Gebrandeter Link mit get_password_reset_key, der auf eine Fullscreen-
     * Activation-Page zur Passwort-Vergabe führt.
     */
    public static function ajax_send_password_link() {
        if (!self::current_user_can_access()) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        check_ajax_referer(self::NONCE_UPDATE, '_tix_crm_nonce');

        $email = sanitize_email($_POST['email'] ?? '');
        if (!$email || !is_email($email)) wp_send_json_error(['message' => 'Ungültige E-Mail.']);

        // Organizer-Berechtigung
        if (!self::is_admin_view()) {
            $visible = self::visible_event_ids();
            if (!empty($visible) && !self::customer_has_event_in($email, $visible)) {
                wp_send_json_error(['message' => 'Kein Zugriff auf diesen Kunden.']);
            }
        }

        $user = get_user_by('email', $email);
        if (!$user) {
            wp_send_json_error(['message' => 'Kein WP-Konto für diese E-Mail vorhanden. Passwort-Link nicht möglich.']);
        }

        if (!class_exists('TIX_Account_Activation')) {
            wp_send_json_error(['message' => 'Activation-Klasse nicht verfügbar.']);
        }

        // Order-ID (für Branding der Aktivierungs-Mail) — jüngste des Kunden
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';
        $order_id = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $order_id = intval($wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $t WHERE billing_email = %s ORDER BY date_created DESC LIMIT 1",
                $email
            )));
        }

        $sent = TIX_Account_Activation::trigger_activation($user->ID, $order_id);
        if (!$sent) wp_send_json_error(['message' => 'Mail-Versand fehlgeschlagen.']);

        wp_send_json_success(['message' => 'Passwort-Link an ' . $email . ' gesendet ✓']);
    }

    public static function ajax_export_csv() {
        if (!self::current_user_can_access()) wp_die('Keine Berechtigung.');

        $result = self::query_customers([
            'paged'    => 1,
            'per_page' => 10000,
            'search'   => sanitize_text_field($_GET['s'] ?? ''),
            'source'   => sanitize_text_field($_GET['source'] ?? ''),
        ]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="kunden-' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Bestellungen', 'Umsatz', 'Erste Bestellung', 'Letzte Bestellung', 'Quelle', 'Rolle']);
        foreach ($result['rows'] as $r) {
            fputcsv($out, [
                $r['first_name'],
                $r['last_name'],
                $r['email'],
                $r['phone'],
                $r['order_count'],
                number_format((float) $r['total_spent'], 2, ',', '.'),
                $r['first_order'] ? date_i18n('Y-m-d', strtotime($r['first_order'])) : '',
                $r['last_order']  ? date_i18n('Y-m-d', strtotime($r['last_order']))  : '',
                $r['source'],
                $r['user_roles'],
            ]);
        }
        fclose($out);
        exit;
    }
}

// CSV Export über ?export=csv triggern (direkt auf Kunden-Seite)
add_action('admin_init', function() {
    if (isset($_GET['page']) && $_GET['page'] === TIX_CRM::PAGE_SLUG
        && isset($_GET['export']) && $_GET['export'] === 'csv') {
        TIX_CRM::ajax_export_csv();
    }
});
