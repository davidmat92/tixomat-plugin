<?php
/**
 * TIX_Support_Templates — Canned Responses / Antwort-Vorlagen
 *
 * Speichert vorgefertigte Antworten als WP-Option, plus AJAX-Endpoints
 * für Liste/Speichern/Löschen, plus eine Settings-Page.
 *
 * Platzhalter im Template-Body werden beim Einfügen ersetzt:
 *   {{first_name}}, {{last_name}}, {{email}}, {{ticket_id}},
 *   {{order_id}}, {{event_name}}, {{ticket_code}}
 *
 * @since 1.38.126
 */
if (!defined('ABSPATH')) exit;

class TIX_Support_Templates {

    const OPTION_KEY = 'tix_support_templates';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 31);
        add_action('wp_ajax_tix_sp_templates_list',   [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_tix_sp_templates_save',   [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_tix_sp_templates_delete', [__CLASS__, 'ajax_delete']);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Support-Vorlagen',
            'Support-Vorlagen',
            'manage_options',
            'tix-support-templates',
            [__CLASS__, 'render_page']
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // STORAGE
    // ══════════════════════════════════════════════════════════════════════

    public static function get_all() {
        $templates = get_option(self::OPTION_KEY, []);
        if (!is_array($templates)) $templates = [];

        // Default-Templates beim ersten Aufruf seeden
        if (empty($templates)) {
            $templates = self::default_templates();
            update_option(self::OPTION_KEY, $templates);
        }
        return $templates;
    }

    public static function get_for_category($category_slug) {
        $all = self::get_all();
        $matching = array_values(array_filter($all, function($t) use ($category_slug) {
            $cats = $t['categories'] ?? [];
            return empty($cats) || in_array($category_slug, $cats, true) || in_array('all', $cats, true);
        }));
        return $matching;
    }

    public static function save_template($data) {
        $templates = self::get_all();

        $entry = [
            'id'         => $data['id'] ?? self::generate_id(),
            'title'      => sanitize_text_field($data['title'] ?? ''),
            'body'       => wp_kses_post($data['body'] ?? ''),
            'categories' => array_map('sanitize_text_field', (array) ($data['categories'] ?? [])),
            'shortcut'   => sanitize_text_field($data['shortcut'] ?? ''),
            'updated'    => current_time('c'),
        ];

        $found = false;
        foreach ($templates as $i => $t) {
            if (($t['id'] ?? '') === $entry['id']) {
                $templates[$i] = $entry;
                $found = true;
                break;
            }
        }
        if (!$found) $templates[] = $entry;

        update_option(self::OPTION_KEY, $templates);
        return $entry;
    }

    public static function delete_template($id) {
        $templates = self::get_all();
        $filtered = array_values(array_filter($templates, function($t) use ($id) {
            return ($t['id'] ?? '') !== $id;
        }));
        update_option(self::OPTION_KEY, $filtered);
        return true;
    }

    private static function generate_id() {
        return 'tpl_' . wp_generate_password(8, false, false);
    }

    /**
     * Platzhalter im Body ersetzen.
     */
    public static function render_template($body, $context = []) {
        $defaults = [
            'first_name'   => '',
            'last_name'    => '',
            'email'        => '',
            'ticket_id'    => '',
            'order_id'     => '',
            'event_name'   => '',
            'ticket_code'  => '',
        ];
        $context = array_merge($defaults, $context);

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = (string) $value;
        }
        return strtr($body, $replacements);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AJAX
    // ══════════════════════════════════════════════════════════════════════

    public static function ajax_list() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $category = sanitize_text_field($_POST['category'] ?? '');
        $templates = $category ? self::get_for_category($category) : self::get_all();

        wp_send_json_success(['templates' => $templates]);
    }

    public static function ajax_save() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $data = [
            'id'         => sanitize_text_field($_POST['id'] ?? ''),
            'title'      => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'body'       => wp_kses_post(wp_unslash($_POST['body'] ?? '')),
            'categories' => array_map('sanitize_text_field', (array) ($_POST['categories'] ?? [])),
            'shortcut'   => sanitize_text_field($_POST['shortcut'] ?? ''),
        ];

        if (!$data['title'] || !$data['body']) {
            wp_send_json_error('Titel und Text sind erforderlich.');
        }

        $entry = self::save_template($data);
        wp_send_json_success(['template' => $entry]);
    }

    public static function ajax_delete() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $id = sanitize_text_field($_POST['id'] ?? '');
        if (!$id) wp_send_json_error('Keine ID angegeben.');

        self::delete_template($id);
        wp_send_json_success(['deleted' => true]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ADMIN PAGE
    // ══════════════════════════════════════════════════════════════════════

    public static function render_page() {
        $templates  = self::get_all();
        $categories = class_exists('TIX_Support') ? TIX_Support::get_categories() : [];
        $nonce      = wp_create_nonce('tix_support_action');
        ?>
        <div class="wrap" style="max-width:1100px;">
            <h1 style="display:flex;align-items:center;gap:8px;">
                <span class="dashicons dashicons-format-quote" style="font-size:28px;width:28px;height:28px;"></span>
                Support-Antwort-Vorlagen
                <button type="button" class="button button-primary" id="tix-tpl-new" style="margin-left:auto;">+ Neue Vorlage</button>
            </h1>
            <p style="color:#6b7280;margin:0 0 20px;">Vorgefertigte Antworten für häufige Anfragen. Im Support-Bereich per Klick ins Antwort-Feld einfügbar. Platzhalter:
                <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">{{first_name}}</code>
                <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">{{last_name}}</code>
                <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">{{ticket_id}}</code>
                <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">{{order_id}}</code>
                <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">{{event_name}}</code>
                <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">{{ticket_code}}</code>
                <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">{{email}}</code>
            </p>

            <div id="tix-tpl-list" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;">
                <?php foreach ($templates as $t): ?>
                    <?php self::render_template_card($t, $categories); ?>
                <?php endforeach; ?>
            </div>

            <?php // ── Edit-Modal ── ?>
            <div id="tix-tpl-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center;padding:20px;">
                <div style="background:#fff;border-radius:14px;max-width:640px;width:100%;max-height:90vh;overflow-y:auto;padding:28px;">
                    <h2 id="tix-tpl-modal-title" style="margin:0 0 18px;">Neue Vorlage</h2>
                    <form id="tix-tpl-form">
                        <input type="hidden" name="id" id="tix-tpl-id">

                        <div style="margin-bottom:14px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Titel</label>
                            <input type="text" name="title" id="tix-tpl-title" required
                                style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;"
                                placeholder="z.B. Ticket nicht erhalten – Spam-Hinweis">
                        </div>

                        <div style="margin-bottom:14px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Antwort-Text</label>
                            <textarea name="body" id="tix-tpl-body" required rows="10"
                                style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;line-height:1.5;"
                                placeholder="Hallo {{first_name}},&#10;&#10;..."></textarea>
                        </div>

                        <div style="margin-bottom:14px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">Kategorien (leer = alle)</label>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <?php foreach ($categories as $c): ?>
                                    <label style="display:inline-flex;align-items:center;gap:5px;padding:6px 10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;font-size:13px;">
                                        <input type="checkbox" name="categories[]" value="<?php echo esc_attr($c['slug']); ?>" class="tix-tpl-cat">
                                        <?php echo esc_html($c['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="margin-bottom:14px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Tastenkürzel (optional)</label>
                            <input type="text" name="shortcut" id="tix-tpl-shortcut"
                                style="width:200px;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:ui-monospace,monospace;"
                                placeholder="z.B. spam"
                                maxlength="20">
                            <span style="font-size:11px;color:#6b7280;margin-left:8px;">Tippe <code>/spam</code> + Tab im Antwort-Feld</span>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:24px;">
                            <button type="button" class="button" id="tix-tpl-delete" style="color:#dc2626;display:none;">Löschen</button>
                            <div style="margin-left:auto;display:flex;gap:8px;">
                                <button type="button" class="button" id="tix-tpl-cancel">Abbrechen</button>
                                <button type="submit" class="button button-primary">Speichern</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        (function($){
            const NONCE = <?php echo json_encode($nonce); ?>;

            function openModal(tpl) {
                $('#tix-tpl-modal-title').text(tpl ? 'Vorlage bearbeiten' : 'Neue Vorlage');
                $('#tix-tpl-id').val(tpl?.id || '');
                $('#tix-tpl-title').val(tpl?.title || '');
                $('#tix-tpl-body').val(tpl?.body || '');
                $('#tix-tpl-shortcut').val(tpl?.shortcut || '');
                $('.tix-tpl-cat').prop('checked', false);
                if (tpl?.categories) {
                    tpl.categories.forEach(c => $('.tix-tpl-cat[value="' + c + '"]').prop('checked', true));
                }
                $('#tix-tpl-delete').toggle(!!tpl);
                $('#tix-tpl-modal').css('display', 'flex');
            }
            function closeModal() { $('#tix-tpl-modal').hide(); }

            $('#tix-tpl-new').on('click', () => openModal(null));
            $('#tix-tpl-cancel').on('click', closeModal);
            $('#tix-tpl-modal').on('click', e => { if (e.target === e.currentTarget) closeModal(); });

            $(document).on('click', '.tix-tpl-edit', function() {
                const tpl = JSON.parse($(this).attr('data-tpl'));
                openModal(tpl);
            });

            $('#tix-tpl-form').on('submit', function(e) {
                e.preventDefault();
                const data = {
                    action: 'tix_sp_templates_save',
                    nonce: NONCE,
                    id:    $('#tix-tpl-id').val(),
                    title: $('#tix-tpl-title').val(),
                    body:  $('#tix-tpl-body').val(),
                    shortcut: $('#tix-tpl-shortcut').val(),
                    categories: $('.tix-tpl-cat:checked').map(function(){ return this.value; }).get(),
                };
                $.post(ajaxurl, data, function(r) {
                    if (r.success) location.reload();
                    else alert(r.data || 'Fehler');
                });
            });

            $('#tix-tpl-delete').on('click', function() {
                if (!confirm('Vorlage wirklich löschen?')) return;
                $.post(ajaxurl, {
                    action: 'tix_sp_templates_delete',
                    nonce: NONCE,
                    id: $('#tix-tpl-id').val(),
                }, function(r) {
                    if (r.success) location.reload();
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    private static function render_template_card($t, $categories) {
        $cat_labels = [];
        foreach ((array) ($t['categories'] ?? []) as $slug) {
            foreach ($categories as $c) {
                if ($c['slug'] === $slug) { $cat_labels[] = $c['label']; break; }
            }
        }
        $cat_str = empty($cat_labels) ? 'Alle Kategorien' : implode(', ', $cat_labels);
        $tpl_json = esc_attr(wp_json_encode($t));
        $preview = wp_trim_words(strip_tags($t['body'] ?? ''), 18, '…');
        ?>
        <div class="tix-tpl-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;cursor:pointer;transition:border-color .15s;" onmouseover="this.style.borderColor='#FF5500'" onmouseout="this.style.borderColor='#e5e7eb'">
            <div class="tix-tpl-edit" data-tpl="<?php echo $tpl_json; ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;">
                    <strong style="font-size:14px;color:#0f172a;flex:1;"><?php echo esc_html($t['title'] ?? '—'); ?></strong>
                    <?php if (!empty($t['shortcut'])): ?>
                        <code style="background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:4px;font-size:11px;">/<?php echo esc_html($t['shortcut']); ?></code>
                    <?php endif; ?>
                </div>
                <p style="margin:0 0 8px;font-size:12px;color:#64748b;line-height:1.5;"><?php echo esc_html($preview); ?></p>
                <span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em;"><?php echo esc_html($cat_str); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Default-Vorlagen für den ersten Aufruf.
     */
    private static function default_templates() {
        return [
            [
                'id' => 'tpl_default_spam',
                'title' => 'Ticket nicht erhalten – Spam-Hinweis',
                'body' => "Hallo {{first_name}},\n\nbitte schau in deinem Spam-/Junk-Ordner nach – dort landen E-Mails mit Tickets manchmal automatisch.\n\nDeine Tickets findest du auch jederzeit in deinem Konto unter „Meine Tickets\".\n\nFalls du die Mail trotzdem nicht findest, sag Bescheid – wir senden sie dir gerne erneut.\n\nViele Grüße",
                'categories' => ['ticket'],
                'shortcut' => 'spam',
                'updated' => current_time('c'),
            ],
            [
                'id' => 'tpl_default_owner',
                'title' => 'Ticketinhaber ändern – Selfservice',
                'body' => "Hallo {{first_name}},\n\ndu kannst den Ticketinhaber jederzeit selbst ändern: Logge dich in dein Konto ein, gehe auf „Meine Tickets\" und klicke beim entsprechenden Ticket auf „Inhaber ändern\".\n\nFalls du Hilfe brauchst, melde dich gerne nochmal.\n\nViele Grüße",
                'categories' => ['ticket'],
                'shortcut' => 'owner',
                'updated' => current_time('c'),
            ],
            [
                'id' => 'tpl_default_storno',
                'title' => 'Stornierung – AGB-Hinweis',
                'body' => "Hallo {{first_name}},\n\ngrundsätzlich ist eine Stornierung nach Bestellabschluss laut unseren AGB ausgeschlossen, da es sich um ein Veranstaltungsticket handelt (§ 312g Abs. 2 Nr. 9 BGB).\n\nDu kannst dein Ticket jedoch problemlos an Freunde oder Familie weitergeben – nutze dazu die Funktion „Inhaber ändern\" in deinem Konto.\n\nViele Grüße",
                'categories' => ['payment', 'ticket'],
                'shortcut' => 'storno',
                'updated' => current_time('c'),
            ],
            [
                'id' => 'tpl_default_resend',
                'title' => 'Tickets erneut senden',
                'body' => "Hallo {{first_name}},\n\nich habe dir die Tickets soeben erneut zugesandt. Falls sie wieder nicht ankommen, prüfe bitte den Spam-Ordner.\n\nFalls weiterhin nichts ankommt, könnte es an einem aktiven Mail-Filter liegen – probiere es ggf. mit einer alternativen E-Mail-Adresse.\n\nViele Grüße",
                'categories' => ['ticket'],
                'shortcut' => 'resend',
                'updated' => current_time('c'),
            ],
            [
                'id' => 'tpl_default_einlass',
                'title' => 'Einlass-Hinweise',
                'body' => "Hallo {{first_name}},\n\nfür den Einlass benötigst du nur dein Ticket (digital auf dem Smartphone oder ausgedruckt). Personalausweis oder ID nur, wenn dein Name auf dem Ticket steht.\n\nEinlass beginnt in der Regel 1 Stunde vor Veranstaltungsstart.\n\nViele Grüße",
                'categories' => ['general'],
                'shortcut' => 'einlass',
                'updated' => current_time('c'),
            ],
        ];
    }
}
