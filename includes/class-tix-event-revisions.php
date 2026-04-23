<?php
/**
 * TIX Event Revisions — Wer hat wann was geändert?
 *
 * Hooks `post_updated` auf Event-CPTs und loggt Diffs zu relevanten Feldern
 * (Post-Title, Post-Content, Post-Status + alle `_tix_*` Meta-Felder).
 *
 * Anzeige: Neuer Metabox-Tab "Verlauf" im Event-Editor.
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Revisions {

    const TABLE = 'tix_event_revisions';

    public static function init() {
        add_action('plugins_loaded',   [__CLASS__, 'maybe_install']);
        add_action('post_updated',     [__CLASS__, 'on_post_updated'], 20, 3);
        add_action('updated_post_meta',[__CLASS__, 'on_meta_updated'], 20, 4);
        add_action('added_post_meta',  [__CLASS__, 'on_meta_updated'], 20, 4);

        // Metabox
        add_action('add_meta_boxes_event', [__CLASS__, 'register_metabox']);

        // Admin AJAX
        add_action('wp_ajax_tix_revisions_list', [__CLASS__, 'ajax_list']);
    }

    // ─────────────────────────────────────────────
    // DB-Tabelle
    // ─────────────────────────────────────────────

    public static function maybe_install() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $current = get_option('tix_event_revisions_db_version', 0);
        if ($current >= 1) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            changed_at DATETIME NOT NULL,
            field VARCHAR(100) NOT NULL,
            old_value LONGTEXT NULL,
            new_value LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY changed_at (changed_at)
        ) $charset;";
        dbDelta($sql);
        update_option('tix_event_revisions_db_version', 1, false);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // ─────────────────────────────────────────────
    // Logging
    // ─────────────────────────────────────────────

    public static function on_post_updated($post_id, $post_after, $post_before) {
        if ($post_after->post_type !== 'event') return;
        if ($post_after->post_status === 'auto-draft') return;
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

        $fields = ['post_title', 'post_status', 'post_content'];
        foreach ($fields as $f) {
            $old = $post_before->$f;
            $new = $post_after->$f;
            if ($old !== $new) {
                self::log($post_id, $f, $old, $new);
            }
        }
    }

    public static function on_meta_updated($meta_id, $object_id, $meta_key, $meta_value) {
        if (strpos($meta_key, '_tix_') !== 0) return;
        if (get_post_type($object_id) !== 'event') return;

        // Skip häufige Auto-Felder, die nichts "Sinnvolles" loggen
        $skip = ['_tix_last_sync_log', '_tix_last_sync_time', '_edit_lock', '_edit_last', '_tix_presale_end_computed'];
        if (in_array($meta_key, $skip, true)) return;
        // Formatierte Display-Varianten (werden auto-gesetzt)
        if (strpos($meta_key, '_formatted') !== false) return;

        // Alten Wert lesen bevor er überschrieben wird — wir sind im updated_postmeta Hook also bereits überschrieben.
        // Wir verzichten auf "alter Wert" für Meta-Felder und zeigen nur "geändert" + neuen Wert.
        $new_serialized = is_array($meta_value) || is_object($meta_value) ? wp_json_encode($meta_value) : (string) $meta_value;
        if (mb_strlen($new_serialized) > 2000) {
            $new_serialized = mb_substr($new_serialized, 0, 2000) . '… [gekürzt]';
        }
        self::log($object_id, $meta_key, null, $new_serialized);
    }

    private static function log($event_id, $field, $old, $new) {
        global $wpdb;
        $user_id = get_current_user_id();
        if (!$user_id) return; // Nur authentifizierte Änderungen loggen

        $old_s = is_array($old) || is_object($old) ? wp_json_encode($old) : (string) $old;
        $new_s = is_array($new) || is_object($new) ? wp_json_encode($new) : (string) $new;
        if (mb_strlen($old_s) > 2000) $old_s = mb_substr($old_s, 0, 2000) . '… [gekürzt]';
        if (mb_strlen($new_s) > 2000) $new_s = mb_substr($new_s, 0, 2000) . '… [gekürzt]';

        $wpdb->insert(self::table(), [
            'event_id'   => intval($event_id),
            'user_id'    => intval($user_id),
            'changed_at' => current_time('mysql'),
            'field'      => substr($field, 0, 100),
            'old_value'  => $old_s,
            'new_value'  => $new_s,
        ]);
    }

    // ─────────────────────────────────────────────
    // Metabox
    // ─────────────────────────────────────────────

    public static function register_metabox() {
        add_meta_box(
            'tix_event_revisions',
            '📜 Änderungsverlauf',
            [__CLASS__, 'render_metabox'],
            'event',
            'normal',
            'low'
        );
    }

    public static function render_metabox($post) {
        ?>
        <div id="tix-revisions-metabox" data-event-id="<?php echo intval($post->ID); ?>">
            <div class="tix-rev-filter" style="display:flex;gap:10px;margin-bottom:12px;align-items:center;">
                <label style="font-size:12px;color:#64748b;">Zeige die letzten
                    <select id="tix-rev-limit" style="padding:4px 8px;">
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    Änderungen
                </label>
                <button type="button" class="button" id="tix-rev-refresh">Aktualisieren</button>
            </div>
            <div id="tix-rev-list" style="font-size:13px;">Lädt…</div>
        </div>
        <script>
        (function($){
            var $box = $('#tix-revisions-metabox');
            if (!$box.length) return;
            var eventId = $box.data('event-id');

            function load(){
                var limit = $('#tix-rev-limit').val() || 20;
                $('#tix-rev-list').html('Lädt…');
                $.post(ajaxurl, {
                    action: 'tix_revisions_list',
                    event_id: eventId,
                    limit: limit,
                    nonce: '<?php echo wp_create_nonce('tix_revisions'); ?>'
                }, function(r){
                    if (!r.success) { $('#tix-rev-list').html('Fehler beim Laden.'); return; }
                    if (!r.data.rows.length) { $('#tix-rev-list').html('<p style="color:#94a3b8;">Noch keine Änderungen protokolliert.</p>'); return; }
                    var html = '<div style="max-height:480px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;">';
                    html += '<table class="widefat" style="border:0;"><thead><tr><th style="width:140px;">Zeit</th><th style="width:140px;">Nutzer</th><th>Feld</th><th>Neuer Wert</th></tr></thead><tbody>';
                    r.data.rows.forEach(function(row){
                        html += '<tr>'
                            + '<td style="white-space:nowrap;color:#64748b;">' + row.when + '</td>'
                            + '<td>' + row.user + '</td>'
                            + '<td><code style="font-size:11px;">' + row.field + '</code></td>'
                            + '<td style="max-width:400px;word-break:break-word;"><span style="color:#0f766e;">' + row.new + '</span>'
                            + (row.old ? '<br><span style="color:#dc2626;text-decoration:line-through;font-size:11px;">' + row.old + '</span>' : '')
                            + '</td>'
                            + '</tr>';
                    });
                    html += '</tbody></table></div>';
                    $('#tix-rev-list').html(html);
                });
            }

            $('#tix-rev-refresh, #tix-rev-limit').on('click change', load);
            load();
        })(jQuery);
        </script>
        <?php
    }

    // ─────────────────────────────────────────────
    // AJAX
    // ─────────────────────────────────────────────

    public static function ajax_list() {
        check_ajax_referer('tix_revisions', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $event_id = intval($_POST['event_id'] ?? 0);
        $limit    = max(5, min(200, intval($_POST['limit'] ?? 20)));
        if (!$event_id) wp_send_json_error();

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE event_id = %d ORDER BY id DESC LIMIT %d",
            $event_id, $limit
        ));

        $out = [];
        foreach ($rows as $r) {
            $u = get_userdata(intval($r->user_id));
            $user_label = $u ? $u->display_name : '#' . intval($r->user_id);
            $when = $r->changed_at ? date_i18n('d.m.Y H:i', strtotime($r->changed_at)) : '';

            // Hübschere Field-Labels für Standard-Post-Felder
            $field_labels = [
                'post_title'   => 'Titel',
                'post_status'  => 'Status',
                'post_content' => 'Inhalt',
            ];
            $field = isset($field_labels[$r->field]) ? $field_labels[$r->field] : $r->field;

            $out[] = [
                'when'  => $when,
                'user'  => esc_html($user_label),
                'field' => esc_html($field),
                'old'   => $r->old_value !== null && $r->old_value !== '' ? esc_html(mb_substr($r->old_value, 0, 200)) : '',
                'new'   => esc_html(mb_substr((string) $r->new_value, 0, 200)),
            ];
        }

        wp_send_json_success(['rows' => $out]);
    }
}
