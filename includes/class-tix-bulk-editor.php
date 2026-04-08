<?php
/**
 * TIX KI-Import – Massenimport von Events aus URLs
 *
 * Nutzt TIX_AI_Writer::analyze_url() zur KI-Extraktion.
 * 3-Phasen-Workflow: URLs eingeben → Review → Events erstellen
 */
if (!defined('ABSPATH')) exit;

class TIX_Bulk_Editor {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 30);
        add_action('wp_ajax_tix_bulk_analyze_url',  [__CLASS__, 'ajax_analyze_url']);
        add_action('wp_ajax_tix_bulk_create_event',  [__CLASS__, 'ajax_create_event']);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'KI-Import',
            'KI-Import',
            'edit_posts',
            'tix-bulk-editor',
            [__CLASS__, 'render_page']
        );
    }

    // ─────────────────────────────────────────
    // AJAX: Einzelne URL analysieren
    // ─────────────────────────────────────────
    public static function ajax_analyze_url() {
        check_ajax_referer('tix_bulk_editor', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        if (empty($url)) {
            wp_send_json_error(['message' => 'Keine URL angegeben.']);
        }

        if (!class_exists('TIX_AI_Writer')) {
            wp_send_json_error(['message' => 'AI Writer nicht verfügbar.']);
        }

        $result = TIX_AI_Writer::analyze_url($url);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'url' => $url]);
        }

        $result['source_url'] = $url;
        wp_send_json_success($result);
    }

    // ─────────────────────────────────────────
    // AJAX: Event erstellen
    // ─────────────────────────────────────────
    public static function ajax_create_event() {
        check_ajax_referer('tix_bulk_editor', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        $raw = wp_unslash($_POST['event_data'] ?? '');
        $data = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($data) || empty($data['title'])) {
            wp_send_json_error(['message' => 'Ungültige Event-Daten.']);
        }

        $category_ids = array_map('intval', (array)($_POST['category_ids'] ?? []));
        $post_status  = sanitize_text_field($_POST['post_status'] ?? 'draft');
        if (!in_array($post_status, ['draft', 'publish'])) $post_status = 'draft';

        $post_id = self::create_single_event($data, $category_ids, $post_status);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
        }

        wp_send_json_success([
            'post_id'  => $post_id,
            'edit_url' => get_edit_post_link($post_id, 'raw'),
        ]);
    }

    // ─────────────────────────────────────────
    // Event erstellen
    // ─────────────────────────────────────────
    private static function create_single_event($data, $category_ids = [], $post_status = 'draft') {
        $post_id = wp_insert_post([
            'post_type'   => 'event',
            'post_title'  => sanitize_text_field($data['title']),
            'post_status' => $post_status,
            'post_author' => get_current_user_id(),
        ]);

        if (is_wp_error($post_id)) return $post_id;

        // Datum & Zeit
        $date_fields = [
            '_tix_date_start' => $data['date_start'] ?? '',
            '_tix_date_end'   => $data['date_end'] ?? '',
            '_tix_time_start' => $data['time_start'] ?? '',
            '_tix_time_end'   => $data['time_end'] ?? '',
            '_tix_time_doors' => $data['time_doors'] ?? '',
        ];
        foreach ($date_fields as $key => $val) {
            if ($val !== '') update_post_meta($post_id, $key, sanitize_text_field($val));
        }

        // Display-Datum berechnen
        if (!empty($data['date_start'])) {
            $display = self::build_date_display(
                $data['date_start'], $data['date_end'] ?? '',
                $data['time_start'] ?? '', $data['time_end'] ?? ''
            );
            update_post_meta($post_id, '_tix_date_display', $display);
            update_post_meta($post_id, '_tix_date_card', $display);
        }

        // Location
        if (!empty($data['location'])) {
            $loc_id = intval($data['location_id'] ?? 0);
            if (!$loc_id) {
                // Location suchen oder erstellen
                $loc_id = self::find_or_create_location($data['location'], $data['location_address'] ?? '');
            }
            if ($loc_id) {
                update_post_meta($post_id, '_tix_location_id', $loc_id);
                update_post_meta($post_id, '_tix_location', get_the_title($loc_id));
                $addr = get_post_meta($loc_id, '_tix_location_address', true);
                if ($addr) update_post_meta($post_id, '_tix_address', $addr);
            } else {
                update_post_meta($post_id, '_tix_location', sanitize_text_field($data['location']));
                if (!empty($data['location_address'])) {
                    update_post_meta($post_id, '_tix_address', sanitize_text_field($data['location_address']));
                }
            }
            // Kurzform für Karten
            $short = sanitize_text_field($data['location']);
            update_post_meta($post_id, '_tix_location_short', mb_substr($short, 0, 40));
        }

        // Info-Sektionen
        $info_map = [
            '_tix_info_description' => $data['description'] ?? '',
            '_tix_info_lineup'      => $data['lineup'] ?? '',
            '_tix_info_specials'    => $data['specials'] ?? '',
            '_tix_info_extra_info'  => $data['extra_info'] ?? '',
        ];
        foreach ($info_map as $key => $val) {
            if ($val !== '') update_post_meta($post_id, $key, wp_kses_post($val));
        }

        // Altersbeschränkung
        if (($data['age_limit'] ?? '') !== '') {
            update_post_meta($post_id, '_tix_age_limit', intval($data['age_limit']));
        }

        // Excerpt
        if (!empty($data['excerpt'])) {
            update_post_meta($post_id, '_tix_excerpt', sanitize_text_field($data['excerpt']));
        }

        // Ticket-Kategorien
        if (!empty($data['tickets']) && is_array($data['tickets'])) {
            $cats = [];
            foreach ($data['tickets'] as $t) {
                if (empty($t['name'])) continue;
                $cats[] = [
                    'name'           => sanitize_text_field($t['name']),
                    'price'          => floatval($t['price'] ?? 0),
                    'sale_price'     => '',
                    'qty'            => 0,
                    'desc'           => sanitize_text_field($t['description'] ?? ''),
                    'online'         => '1',
                    'offline_ticket' => '0',
                    'product_id'     => 0,
                    'tc_event_id'    => 0,
                    'sku'            => '',
                    'image_id'       => 0,
                ];
            }
            if ($cats) {
                update_post_meta($post_id, '_tix_ticket_categories', $cats);
                update_post_meta($post_id, '_tix_tickets_enabled', '1');
                update_post_meta($post_id, '_tix_presale_active', '1');
            }
        }

        // FAQ
        if (!empty($data['faq']) && is_array($data['faq'])) {
            $faq = [];
            foreach ($data['faq'] as $f) {
                if (empty($f['question'])) continue;
                $faq[] = [
                    'question' => sanitize_text_field($f['question']),
                    'answer'   => wp_kses_post($f['answer'] ?? ''),
                ];
            }
            if ($faq) update_post_meta($post_id, '_tix_faq', $faq);
        }

        // Beitragsbild
        if (!empty($data['suggested_image_id'])) {
            set_post_thumbnail($post_id, intval($data['suggested_image_id']));
        }

        // Kategorien
        $cat_ids = !empty($category_ids) ? $category_ids : ($data['category_ids'] ?? []);
        if (!empty($cat_ids)) {
            wp_set_object_terms($post_id, array_map('intval', $cat_ids), 'event_category');
        }

        // Breakdance Meta Sync
        if (class_exists('TIX_Sync')) {
            TIX_Sync::sync($post_id, get_post($post_id));
        }

        return $post_id;
    }

    private static function find_or_create_location($name, $address) {
        $name = sanitize_text_field($name);
        if (empty($name)) return 0;

        // Existierende suchen
        $existing = get_posts([
            'post_type'      => 'tix_location',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'title'          => $name,
        ]);
        if ($existing) return $existing[0]->ID;

        // Neu erstellen
        $loc_id = wp_insert_post([
            'post_type'   => 'tix_location',
            'post_title'  => $name,
            'post_status' => 'publish',
        ]);
        if (is_wp_error($loc_id)) return 0;

        if ($address) {
            update_post_meta($loc_id, '_tix_location_address', sanitize_text_field($address));
        }

        return $loc_id;
    }

    private static function build_date_display($date_start, $date_end, $time_start, $time_end) {
        if (empty($date_start)) return '';
        $ts = strtotime($date_start);
        if (!$ts) return '';

        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $months = ['', 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        $d = $days[date('w', $ts)] . '. ' . date('d', $ts) . '. ' . $months[intval(date('n', $ts))] . ' ' . date('Y', $ts);

        if ($time_start) $d .= ' · ' . $time_start . ' Uhr';
        if ($date_end && $date_end !== $date_start) {
            $te = strtotime($date_end);
            if ($te) $d .= ' – ' . $days[date('w', $te)] . '. ' . date('d', $te) . '. ' . $months[intval(date('n', $te))] . ' ' . date('Y', $te);
        }

        return $d;
    }

    // ─────────────────────────────────────────
    // Render Page
    // ─────────────────────────────────────────
    public static function render_page() {
        $api_key = trim(tix_get_settings('anthropic_api_key') ?? '');
        $openai_key = trim(tix_get_settings('openai_api_key') ?? '');
        $has_key = !empty($api_key) || !empty($openai_key);
        ?>
        <div class="wrap">
            <h1 style="display:none;">KI-Import</h1>

            <?php if (!$has_key): ?>
                <div class="notice notice-error"><p><strong>Kein API-Key konfiguriert.</strong> Bitte unter <a href="<?php echo admin_url('admin.php?page=tix-settings#advanced'); ?>">Einstellungen → Erweitert → KI</a> einen Anthropic oder OpenAI Key eintragen.</p></div>
            <?php endif; ?>

            <style>
                .tix-be { max-width: 1100px; }
                .tix-be-header { margin-bottom: 24px; }
                .tix-be-header h2 { font-size: 22px; font-weight: 700; margin: 0 0 6px; color: #1e293b; }
                .tix-be-header p { color: #64748b; margin: 0; font-size: 14px; }
                .tix-be-card { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; }
                .tix-be-card h3 { margin: 0 0 16px; font-size: 15px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; }
                .tix-be-card h3 .dashicons { font-size: 18px; color: var(--tix-primary, #E1248E); }

                /* URL Input */
                .tix-be-urls { width: 100%; min-height: 200px; font-family: 'DM Sans', -apple-system, sans-serif; font-size: 13px; padding: 14px; border: 2px solid #e5e7eb; border-radius: 10px; resize: vertical; transition: border-color .2s; line-height: 1.7; }
                .tix-be-urls:focus { outline: none; border-color: var(--tix-primary, #E1248E); }
                .tix-be-urls::placeholder { color: #94a3b8; }
                .tix-be-actions { display: flex; align-items: center; gap: 12px; margin-top: 16px; }
                .tix-be-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 22px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .2s; }
                .tix-be-btn-primary { background: var(--tix-primary, #E1248E); color: #fff; }
                .tix-be-btn-primary:hover { opacity: .9; transform: translateY(-1px); }
                .tix-be-btn-primary:disabled { opacity: .5; cursor: not-allowed; transform: none; }
                .tix-be-btn-secondary { background: #f1f5f9; color: #475569; }
                .tix-be-btn-secondary:hover { background: #e2e8f0; }
                .tix-be-count { font-size: 13px; color: #64748b; }

                /* Progress */
                .tix-be-progress { margin-bottom: 20px; display: none; }
                .tix-be-progress-bar { height: 6px; background: #f1f5f9; border-radius: 3px; overflow: hidden; }
                .tix-be-progress-fill { height: 100%; background: var(--tix-primary, #E1248E); border-radius: 3px; transition: width .3s ease; width: 0%; }
                .tix-be-progress-text { font-size: 13px; color: #64748b; margin-top: 8px; }

                /* Result Cards */
                .tix-be-results { display: none; }
                .tix-be-controls { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
                .tix-be-controls select { padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; }
                .tix-be-controls label { font-size: 13px; color: #475569; display: flex; align-items: center; gap: 4px; cursor: pointer; }

                .tix-be-item { display: flex; gap: 16px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 10px; align-items: flex-start; transition: border-color .2s, box-shadow .2s; }
                .tix-be-item:hover { border-color: #cbd5e1; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
                .tix-be-item.tix-be-error { border-color: #fca5a5; background: #fef2f2; }
                .tix-be-item.tix-be-created { border-color: #86efac; background: #f0fdf4; }
                .tix-be-item-check { flex-shrink: 0; margin-top: 4px; }
                .tix-be-item-check input { width: 18px; height: 18px; accent-color: var(--tix-primary, #E1248E); cursor: pointer; }
                .tix-be-item-img { width: 80px; height: 80px; border-radius: 8px; object-fit: cover; background: #f1f5f9; flex-shrink: 0; }
                .tix-be-item-img.no-img { display: flex; align-items: center; justify-content: center; color: #94a3b8; }
                .tix-be-item-body { flex: 1; min-width: 0; }
                .tix-be-item-title { font-size: 15px; font-weight: 600; color: #1e293b; margin: 0 0 4px; }
                .tix-be-item-title input { font-size: 15px; font-weight: 600; border: none; background: transparent; width: 100%; padding: 0; color: #1e293b; }
                .tix-be-item-title input:focus { outline: none; border-bottom: 1px solid var(--tix-primary, #E1248E); }
                .tix-be-item-meta { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: #64748b; }
                .tix-be-item-meta span { display: flex; align-items: center; gap: 3px; }
                .tix-be-item-meta .dashicons { font-size: 14px; width: 14px; height: 14px; }
                .tix-be-item-status { flex-shrink: 0; display: flex; align-items: center; gap: 6px; }
                .tix-be-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
                .tix-be-badge-ok { background: #dcfce7; color: #166534; }
                .tix-be-badge-err { background: #fef2f2; color: #dc2626; }
                .tix-be-badge-wait { background: #f1f5f9; color: #64748b; }
                .tix-be-badge-created { background: #dcfce7; color: #166534; }
                .tix-be-item-expand { cursor: pointer; color: #94a3b8; font-size: 18px; transition: transform .2s; }
                .tix-be-item-expand.open { transform: rotate(180deg); }

                .tix-be-item-details { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid #f1f5f9; }
                .tix-be-item-details.open { display: block; }
                .tix-be-detail-row { display: grid; grid-template-columns: 120px 1fr; gap: 8px; margin-bottom: 8px; font-size: 13px; }
                .tix-be-detail-label { color: #64748b; font-weight: 500; }
                .tix-be-detail-value { color: #1e293b; }
                .tix-be-detail-value input, .tix-be-detail-value textarea { width: 100%; border: 1px solid #e5e7eb; border-radius: 6px; padding: 6px 10px; font-size: 13px; font-family: inherit; }
                .tix-be-detail-value textarea { min-height: 60px; resize: vertical; }

                /* Summary */
                .tix-be-summary { display: none; }
                .tix-be-summary-stats { display: flex; gap: 16px; margin-bottom: 20px; }
                .tix-be-stat { padding: 16px 24px; border-radius: 10px; text-align: center; }
                .tix-be-stat-num { font-size: 28px; font-weight: 700; }
                .tix-be-stat-label { font-size: 12px; color: #64748b; margin-top: 2px; }
                .tix-be-stat-ok { background: #f0fdf4; }
                .tix-be-stat-ok .tix-be-stat-num { color: #16a34a; }
                .tix-be-stat-err { background: #fef2f2; }
                .tix-be-stat-err .tix-be-stat-num { color: #dc2626; }
                .tix-be-created-list { list-style: none; padding: 0; margin: 0; }
                .tix-be-created-list li { padding: 8px 0; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 8px; font-size: 14px; }
                .tix-be-created-list li:last-child { border-bottom: none; }
                .tix-be-created-list a { color: var(--tix-primary, #E1248E); text-decoration: none; font-weight: 500; }
            </style>

            <div class="tix-be">

                <div class="tix-be-header">
                    <h2>KI-Import</h2>
                    <p>Event-URLs einfügen — die KI analysiert und erstellt Events automatisch.</p>
                </div>

                <!-- Phase 1: URL Input -->
                <div id="tix-be-input">
                    <div class="tix-be-card">
                        <h3><span class="dashicons dashicons-admin-links"></span> URLs eingeben</h3>
                        <textarea id="tix-be-urls" class="tix-be-urls" placeholder="Eine URL pro Zeile einfügen...&#10;&#10;https://example.com/event-1&#10;https://example.com/event-2&#10;https://example.com/event-3"></textarea>

                        <div class="tix-be-options" style="margin-top:16px;display:flex;gap:24px;flex-wrap:wrap;">
                            <label class="tix-be-option" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;font-weight:500;color:#1e293b;">
                                <input type="radio" name="tix_be_mode" value="review" checked style="accent-color:var(--tix-primary,#E1248E);width:16px;height:16px;">
                                <span><span class="dashicons dashicons-visibility" style="font-size:16px;vertical-align:middle;margin-right:2px;color:#64748b;"></span> Erst reviewen</span>
                                <span style="font-size:12px;color:#94a3b8;font-weight:400;">— Ergebnisse prüfen, dann erstellen</span>
                            </label>
                            <label class="tix-be-option" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;font-weight:500;color:#1e293b;">
                                <input type="radio" name="tix_be_mode" value="direct" style="accent-color:var(--tix-primary,#E1248E);width:16px;height:16px;">
                                <span><span class="dashicons dashicons-yes-alt" style="font-size:16px;vertical-align:middle;margin-right:2px;color:#64748b;"></span> Direkt veröffentlichen</span>
                                <span style="font-size:12px;color:#94a3b8;font-weight:400;">— Events sofort online stellen</span>
                            </label>
                        </div>

                        <div class="tix-be-actions">
                            <button type="button" id="tix-be-analyze" class="tix-be-btn tix-be-btn-primary" <?php echo !$has_key ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-search"></span> Starten
                            </button>
                            <span id="tix-be-count" class="tix-be-count">0 URLs erkannt</span>
                        </div>
                    </div>
                </div>

                <!-- Phase 2: Results -->
                <div id="tix-be-results" class="tix-be-results">
                    <div class="tix-be-progress" id="tix-be-progress">
                        <div class="tix-be-progress-bar"><div class="tix-be-progress-fill" id="tix-be-progress-fill"></div></div>
                        <div class="tix-be-progress-text" id="tix-be-progress-text">Analysiere URL 1 von X...</div>
                    </div>

                    <div class="tix-be-card">
                        <h3><span class="dashicons dashicons-editor-table"></span> Ergebnisse</h3>

                        <div class="tix-be-controls">
                            <label><input type="checkbox" id="tix-be-select-all" checked> Alle</label>
                            <span id="tix-be-result-count" class="tix-be-count"></span>
                        </div>

                        <div id="tix-be-cards"></div>

                        <div class="tix-be-actions">
                            <button type="button" id="tix-be-back" class="tix-be-btn tix-be-btn-secondary">
                                <span class="dashicons dashicons-arrow-left-alt"></span> Zurück
                            </button>
                            <button type="button" id="tix-be-create" class="tix-be-btn tix-be-btn-primary">
                                <span class="dashicons dashicons-plus-alt2"></span> Events erstellen
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Phase 3: Summary -->
                <div id="tix-be-summary" class="tix-be-summary">
                    <div class="tix-be-card">
                        <h3><span class="dashicons dashicons-yes-alt"></span> Fertig!</h3>
                        <div class="tix-be-summary-stats" id="tix-be-stats"></div>
                        <ul class="tix-be-created-list" id="tix-be-created-list"></ul>
                        <div class="tix-be-actions" style="margin-top:20px;">
                            <button type="button" id="tix-be-restart" class="tix-be-btn tix-be-btn-primary">
                                <span class="dashicons dashicons-update"></span> Weitere Events importieren
                            </button>
                            <a href="<?php echo admin_url('edit.php?post_type=event'); ?>" class="tix-be-btn tix-be-btn-secondary">
                                <span class="dashicons dashicons-list-view"></span> Alle Events anzeigen
                            </a>
                        </div>
                    </div>
                </div>

            </div>

            <script>
            (function() {
                var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
                var nonce   = '<?php echo wp_create_nonce("tix_bulk_editor"); ?>';

                var urlsEl     = document.getElementById('tix-be-urls');
                var analyzeBtn = document.getElementById('tix-be-analyze');
                var countEl    = document.getElementById('tix-be-count');
                var inputEl    = document.getElementById('tix-be-input');
                var resultsEl  = document.getElementById('tix-be-results');
                var summaryEl  = document.getElementById('tix-be-summary');
                var progressEl = document.getElementById('tix-be-progress');
                var fillEl     = document.getElementById('tix-be-progress-fill');
                var progText   = document.getElementById('tix-be-progress-text');
                var cardsEl    = document.getElementById('tix-be-cards');
                var selectAll  = document.getElementById('tix-be-select-all');
                var resultCount = document.getElementById('tix-be-result-count');

                var results = [];

                // URL-Zähler
                urlsEl.addEventListener('input', function() {
                    var urls = parseUrls();
                    countEl.textContent = urls.length + ' URL' + (urls.length !== 1 ? 's' : '') + ' erkannt';
                });

                function parseUrls() {
                    return urlsEl.value.split('\n')
                        .map(function(l) { return l.trim(); })
                        .filter(function(l) { return l.match(/^https?:\/\//i); });
                }

                function getMode() {
                    var checked = document.querySelector('input[name="tix_be_mode"]:checked');
                    return checked ? checked.value : 'review';
                }

                // ── Phase 1 → 2: Analysieren ──
                analyzeBtn.addEventListener('click', async function() {
                    var urls = parseUrls();
                    if (!urls.length) return alert('Bitte mindestens eine URL eingeben.');

                    var mode = getMode();
                    var isDirect = mode === 'direct';

                    if (isDirect && !confirm('Alle erkannten Events werden sofort veröffentlicht. Fortfahren?')) return;

                    results = [];
                    cardsEl.innerHTML = '';
                    inputEl.style.display = 'none';
                    resultsEl.style.display = 'block';
                    progressEl.style.display = 'block';

                    var created = 0, failed = 0;
                    var createdItems = [];

                    for (var i = 0; i < urls.length; i++) {
                        fillEl.style.width = ((i / urls.length) * 100) + '%';
                        progText.textContent = (isDirect ? 'Analysiere & erstelle' : 'Analysiere') + ' URL ' + (i + 1) + ' von ' + urls.length + '...';

                        try {
                            var fd = new FormData();
                            fd.append('action', 'tix_bulk_analyze_url');
                            fd.append('nonce', nonce);
                            fd.append('url', urls[i]);

                            var resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
                            var json = await resp.json();

                            if (json.success) {
                                results.push({ ok: true, data: json.data, url: urls[i] });

                                if (isDirect) {
                                    // Sofort Event erstellen
                                    renderCard(results.length - 1, json.data);
                                    var idx = results.length - 1;
                                    var item = cardsEl.querySelector('.tix-be-item[data-idx="' + idx + '"]');
                                    var badge = item ? item.querySelector('.tix-be-badge') : null;
                                    if (badge) { badge.className = 'tix-be-badge tix-be-badge-wait'; badge.textContent = '⏳ Erstelle...'; }

                                    var cfd = new FormData();
                                    cfd.append('action', 'tix_bulk_create_event');
                                    cfd.append('nonce', nonce);
                                    cfd.append('event_data', JSON.stringify(json.data));
                                    cfd.append('post_status', 'publish');

                                    var cr = await fetch(ajaxUrl, { method: 'POST', body: cfd });
                                    var cj = await cr.json();

                                    if (cj.success) {
                                        created++;
                                        if (item) item.classList.add('tix-be-created');
                                        if (badge) { badge.className = 'tix-be-badge tix-be-badge-created'; badge.innerHTML = '✓ <a href="' + cj.data.edit_url + '" target="_blank">Online</a>'; }
                                        createdItems.push({ title: json.data.title, edit_url: cj.data.edit_url });
                                    } else {
                                        failed++;
                                        if (badge) { badge.className = 'tix-be-badge tix-be-badge-err'; badge.textContent = '✗ ' + (cj.data.message || 'Fehler'); }
                                    }
                                } else {
                                    renderCard(results.length - 1, json.data);
                                }
                            } else {
                                results.push({ ok: false, error: json.data.message, url: urls[i] });
                                renderErrorCard(results.length - 1, urls[i], json.data.message);
                                if (isDirect) failed++;
                            }
                        } catch(e) {
                            results.push({ ok: false, error: e.message, url: urls[i] });
                            renderErrorCard(results.length - 1, urls[i], e.message);
                            if (isDirect) failed++;
                        }

                        if (i < urls.length - 1) await sleep(300);
                    }

                    fillEl.style.width = '100%';

                    if (isDirect) {
                        // Direkt → Summary anzeigen
                        progText.textContent = 'Fertig! ' + created + ' Events veröffentlicht.';
                        setTimeout(function() {
                            resultsEl.style.display = 'none';
                            summaryEl.style.display = 'block';
                            document.getElementById('tix-be-stats').innerHTML =
                                '<div class="tix-be-stat tix-be-stat-ok"><div class="tix-be-stat-num">' + created + '</div><div class="tix-be-stat-label">Veröffentlicht</div></div>' +
                                (failed ? '<div class="tix-be-stat tix-be-stat-err"><div class="tix-be-stat-num">' + failed + '</div><div class="tix-be-stat-label">Fehlgeschlagen</div></div>' : '');
                            var list = document.getElementById('tix-be-created-list');
                            list.innerHTML = '';
                            createdItems.forEach(function(it) {
                                var li = document.createElement('li');
                                li.innerHTML = '<span class="dashicons dashicons-yes" style="color:#16a34a;"></span> <a href="' + it.edit_url + '" target="_blank">' + esc(it.title) + '</a> <span style="color:#94a3b8;font-size:12px;">(Veröffentlicht)</span>';
                                list.appendChild(li);
                            });
                        }, 800);
                    } else {
                        progText.textContent = urls.length + ' URLs analysiert — ' + results.filter(function(r){return r.ok;}).length + ' erfolgreich';
                        updateResultCount();
                    }
                });

                function sleep(ms) { return new Promise(function(r) { setTimeout(r, ms); }); }

                function renderCard(idx, data) {
                    var div = document.createElement('div');
                    div.className = 'tix-be-item';
                    div.dataset.idx = idx;

                    var imgHtml = data.suggested_image_url
                        ? '<img class="tix-be-item-img" src="' + esc(data.suggested_image_url) + '" alt="">'
                        : '<div class="tix-be-item-img no-img"><span class="dashicons dashicons-format-image"></span></div>';

                    var dateStr = data.date_start || '';
                    if (data.time_start) dateStr += ' ' + data.time_start;

                    div.innerHTML =
                        '<div class="tix-be-item-check"><input type="checkbox" checked data-idx="' + idx + '"></div>' +
                        imgHtml +
                        '<div class="tix-be-item-body">' +
                            '<div class="tix-be-item-title"><input type="text" value="' + esc(data.title) + '" data-field="title" data-idx="' + idx + '"></div>' +
                            '<div class="tix-be-item-meta">' +
                                (dateStr ? '<span><span class="dashicons dashicons-calendar"></span> ' + esc(dateStr) + '</span>' : '') +
                                (data.location ? '<span><span class="dashicons dashicons-location"></span> ' + esc(data.location) + '</span>' : '') +
                                (data.event_type ? '<span><span class="dashicons dashicons-tag"></span> ' + esc(data.event_type) + '</span>' : '') +
                                (data.tickets && data.tickets.length ? '<span><span class="dashicons dashicons-tickets-alt"></span> ' + data.tickets.length + ' Tickets</span>' : '') +
                            '</div>' +
                            '<div class="tix-be-item-details" id="tix-be-detail-' + idx + '">' +
                                detailRow('Beschreibung', '<textarea data-field="description" data-idx="' + idx + '">' + esc(data.description || '') + '</textarea>') +
                                detailRow('Lineup', '<input type="text" value="' + esc(data.lineup || '') + '" data-field="lineup" data-idx="' + idx + '">') +
                                detailRow('Location', '<input type="text" value="' + esc(data.location || '') + '" data-field="location" data-idx="' + idx + '">') +
                                detailRow('Adresse', '<input type="text" value="' + esc(data.location_address || '') + '" data-field="location_address" data-idx="' + idx + '">') +
                                detailRow('Datum', '<input type="date" value="' + esc(data.date_start || '') + '" data-field="date_start" data-idx="' + idx + '">') +
                                detailRow('Uhrzeit', '<input type="time" value="' + esc(data.time_start || '') + '" data-field="time_start" data-idx="' + idx + '"> – <input type="time" value="' + esc(data.time_end || '') + '" data-field="time_end" data-idx="' + idx + '">') +
                                detailRow('Quelle', '<a href="' + esc(data.source_url || '') + '" target="_blank" style="color:var(--tix-primary);font-size:12px;word-break:break-all;">' + esc(data.source_url || '') + '</a>') +
                            '</div>' +
                        '</div>' +
                        '<div class="tix-be-item-status"><span class="tix-be-badge tix-be-badge-ok">✓ Erkannt</span></div>' +
                        '<span class="tix-be-item-expand dashicons dashicons-arrow-down-alt2" data-idx="' + idx + '"></span>';

                    cardsEl.appendChild(div);

                    // Expand toggle
                    div.querySelector('.tix-be-item-expand').addEventListener('click', function() {
                        var det = document.getElementById('tix-be-detail-' + this.dataset.idx);
                        det.classList.toggle('open');
                        this.classList.toggle('open');
                    });

                    // Inline-edit sync
                    div.querySelectorAll('[data-field]').forEach(function(el) {
                        el.addEventListener('change', function() {
                            var i = parseInt(this.dataset.idx);
                            if (results[i] && results[i].data) {
                                results[i].data[this.dataset.field] = this.value;
                            }
                        });
                    });
                }

                function renderErrorCard(idx, url, msg) {
                    var div = document.createElement('div');
                    div.className = 'tix-be-item tix-be-error';
                    div.dataset.idx = idx;
                    div.innerHTML =
                        '<div class="tix-be-item-check"><input type="checkbox" disabled data-idx="' + idx + '"></div>' +
                        '<div class="tix-be-item-img no-img"><span class="dashicons dashicons-warning" style="color:#dc2626;"></span></div>' +
                        '<div class="tix-be-item-body">' +
                            '<div class="tix-be-item-title" style="color:#dc2626;">' + esc(url) + '</div>' +
                            '<div class="tix-be-item-meta"><span style="color:#dc2626;">' + esc(msg) + '</span></div>' +
                        '</div>' +
                        '<div class="tix-be-item-status"><span class="tix-be-badge tix-be-badge-err">✗ Fehler</span></div>';
                    cardsEl.appendChild(div);
                }

                function detailRow(label, valueHtml) {
                    return '<div class="tix-be-detail-row"><div class="tix-be-detail-label">' + label + '</div><div class="tix-be-detail-value">' + valueHtml + '</div></div>';
                }

                function esc(s) {
                    if (!s) return '';
                    var d = document.createElement('div');
                    d.textContent = s;
                    return d.innerHTML;
                }

                function updateResultCount() {
                    var ok = results.filter(function(r) { return r.ok; }).length;
                    var err = results.length - ok;
                    resultCount.textContent = ok + ' erkannt, ' + err + ' fehlgeschlagen';
                }

                // Select all
                selectAll.addEventListener('change', function() {
                    cardsEl.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(function(cb) {
                        cb.checked = selectAll.checked;
                    });
                });

                // Zurück
                document.getElementById('tix-be-back').addEventListener('click', function() {
                    resultsEl.style.display = 'none';
                    inputEl.style.display = 'block';
                });

                // ── Phase 2 → 3: Events erstellen ──
                document.getElementById('tix-be-create').addEventListener('click', async function() {
                    var selected = [];
                    cardsEl.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
                        var i = parseInt(cb.dataset.idx);
                        if (results[i] && results[i].ok) selected.push(i);
                    });

                    if (!selected.length) return alert('Bitte mindestens ein Event auswählen.');

                    var createBtn = this;
                    createBtn.disabled = true;
                    createBtn.innerHTML = '<span class="dashicons dashicons-update" style="animation:rotation 1s infinite linear;"></span> Erstelle...';

                    var created = 0, failed = 0;
                    var createdItems = [];

                    for (var si = 0; si < selected.length; si++) {
                        var idx = selected[si];
                        var data = results[idx].data;
                        var item = cardsEl.querySelector('.tix-be-item[data-idx="' + idx + '"]');
                        var badge = item ? item.querySelector('.tix-be-badge') : null;

                        if (badge) { badge.className = 'tix-be-badge tix-be-badge-wait'; badge.textContent = '⏳ Erstelle...'; }

                        try {
                            var fd = new FormData();
                            fd.append('action', 'tix_bulk_create_event');
                            fd.append('nonce', nonce);
                            fd.append('event_data', JSON.stringify(data));

                            var resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
                            var json = await resp.json();

                            if (json.success) {
                                created++;
                                if (item) item.classList.add('tix-be-created');
                                if (badge) { badge.className = 'tix-be-badge tix-be-badge-created'; badge.innerHTML = '✓ <a href="' + json.data.edit_url + '" target="_blank">Bearbeiten</a>'; }
                                createdItems.push({ title: data.title, edit_url: json.data.edit_url });
                            } else {
                                failed++;
                                if (badge) { badge.className = 'tix-be-badge tix-be-badge-err'; badge.textContent = '✗ ' + (json.data.message || 'Fehler'); }
                            }
                        } catch(e) {
                            failed++;
                            if (badge) { badge.className = 'tix-be-badge tix-be-badge-err'; badge.textContent = '✗ ' + e.message; }
                        }

                        if (si < selected.length - 1) await sleep(200);
                    }

                    // Summary anzeigen
                    resultsEl.style.display = 'none';
                    summaryEl.style.display = 'block';

                    document.getElementById('tix-be-stats').innerHTML =
                        '<div class="tix-be-stat tix-be-stat-ok"><div class="tix-be-stat-num">' + created + '</div><div class="tix-be-stat-label">Erstellt</div></div>' +
                        (failed ? '<div class="tix-be-stat tix-be-stat-err"><div class="tix-be-stat-num">' + failed + '</div><div class="tix-be-stat-label">Fehlgeschlagen</div></div>' : '');

                    var list = document.getElementById('tix-be-created-list');
                    list.innerHTML = '';
                    createdItems.forEach(function(it) {
                        var li = document.createElement('li');
                        li.innerHTML = '<span class="dashicons dashicons-yes" style="color:#16a34a;"></span> ' +
                            '<a href="' + it.edit_url + '" target="_blank">' + esc(it.title) + '</a>' +
                            ' <span style="color:#94a3b8;font-size:12px;">(Entwurf)</span>';
                        list.appendChild(li);
                    });
                });

                // Restart
                document.getElementById('tix-be-restart').addEventListener('click', function() {
                    summaryEl.style.display = 'none';
                    inputEl.style.display = 'block';
                    urlsEl.value = '';
                    countEl.textContent = '0 URLs erkannt';
                    results = [];
                    cardsEl.innerHTML = '';
                    progressEl.style.display = 'none';
                    fillEl.style.width = '0%';
                });
            })();
            </script>
        </div>
        <?php
    }
}
