<?php
if (!defined('ABSPATH')) exit;

class TIX_Ticket_Transfer {

    public static function init() {
        add_shortcode('tix_ticket_transfer', [__CLASS__, 'render']);
        add_action('wp_ajax_tix_transfer_lookup',        [__CLASS__, 'ajax_lookup']);
        add_action('wp_ajax_nopriv_tix_transfer_lookup',  [__CLASS__, 'ajax_lookup']);
        add_action('wp_ajax_tix_transfer_save',           [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_nopriv_tix_transfer_save',    [__CLASS__, 'ajax_save']);
    }

    /**
     * Shortcode: [tix_ticket_transfer]
     */
    public static function render($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'tix_ticket_transfer');
        $post_id = intval($atts['id']) ?: get_the_ID();

        // Prüfe ob Transfer für dieses Event aktiviert ist
        if ($post_id) {
            $enabled = get_post_meta($post_id, '_tix_ticket_transfer', true);
            if ($enabled !== '1') return '';
        }

        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];

        ob_start();
        ?>
        <div class="tix-transfer-wrap" id="tix-transfer" data-event="<?php echo esc_attr($post_id); ?>">

            <?php // ── Schritt 1: Ticket suchen ── ?>
            <div class="tix-transfer-step" id="tix-transfer-lookup">
                <h3 class="tix-transfer-title">Tickets umschreiben</h3>
                <p class="tix-transfer-desc">Gib deine Bestellnummer und E-Mail-Adresse ein, um deine Tickets auf eine andere Person umzuschreiben.</p>

                <div class="tix-transfer-field">
                    <label for="tix-tf-order">Bestellnummer</label>
                    <input type="text" id="tix-tf-order" placeholder="z.B. 1234">
                </div>
                <div class="tix-transfer-field">
                    <label for="tix-tf-email">E-Mail-Adresse (vom Kauf)</label>
                    <input type="email" id="tix-tf-email" placeholder="deine@email.de">
                </div>

                <button type="button" class="tix-transfer-btn" id="tix-tf-search">Tickets suchen</button>
                <div class="tix-transfer-msg" id="tix-tf-msg" style="display:none;"></div>
            </div>

            <?php // ── Schritt 2: Tickets auswählen + umschreiben ── ?>
            <div class="tix-transfer-step" id="tix-transfer-form" style="display:none;">
                <h3 class="tix-transfer-title">Neuen Inhaber eintragen</h3>
                <p class="tix-transfer-desc">W&auml;hle die Tickets aus und trage die Daten des neuen Inhabers ein.</p>

                <div class="tix-transfer-tickets" id="tix-tf-tickets"></div>

                <div class="tix-transfer-field">
                    <label for="tix-tf-new-first">Vorname (neu)</label>
                    <input type="text" id="tix-tf-new-first" placeholder="Vorname">
                </div>
                <div class="tix-transfer-field">
                    <label for="tix-tf-new-last">Nachname (neu)</label>
                    <input type="text" id="tix-tf-new-last" placeholder="Nachname">
                </div>
                <div class="tix-transfer-field">
                    <label for="tix-tf-new-email">E-Mail (neu)</label>
                    <input type="email" id="tix-tf-new-email" placeholder="neue@email.de">
                </div>

                <div class="tix-transfer-confirm-check">
                    <label>
                        <input type="checkbox" id="tix-tf-confirm-check">
                        <span>Mir ist bewusst, dass die umgeschriebenen Tickets dem neuen Inhaber geh&ouml;ren und ich keinen Zugriff mehr darauf habe.</span>
                    </label>
                </div>

                <button type="button" class="tix-transfer-btn" id="tix-tf-save" disabled>Tickets umschreiben</button>
                <button type="button" class="tix-transfer-btn tix-transfer-btn-back" id="tix-tf-back">&larr; Zur&uuml;ck</button>
                <div class="tix-transfer-msg" id="tix-tf-msg2" style="display:none;"></div>
            </div>

            <?php // ── Schritt 3: Bestätigung ── ?>
            <div class="tix-transfer-step" id="tix-transfer-done" style="display:none;">
                <div class="tix-transfer-success">
                    <span class="tix-transfer-check">&#10003;</span>
                    <h3 class="tix-transfer-title">Tickets erfolgreich umgeschrieben!</h3>
                    <p class="tix-transfer-desc" id="tix-tf-confirm-text"></p>
                </div>
            </div>

        </div>

        <style>
        .tix-transfer-wrap{font-family:-apple-system,BlinkMacSystemFont,sans-serif}
        .tix-transfer-title{font-size:18px;font-weight:700;margin:0 0 6px}
        .tix-transfer-desc{font-size:13px;color:#64748b;margin:0 0 20px;line-height:1.5}
        .tix-transfer-field{margin-bottom:14px}
        .tix-transfer-field label{display:block;font-size:12px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:5px}
        .tix-transfer-field input{width:100%;padding:10px 14px;border:1px solid var(--tix-sel-border,#e2e8f0);border-radius:8px;font-size:14px;color:var(--tix-sel-text,#1e293b);background:var(--tix-sel-bg,#fff);box-sizing:border-box;transition:border-color 0.15s}
        .tix-transfer-field input:focus{outline:none;border-color:var(--tix-buy-bg,#1e293b);box-shadow:0 0 0 3px rgba(30,41,59,0.08)}
        .tix-transfer-btn{display:inline-block;padding:12px 28px;border:none;border-radius:var(--tix-buy-radius,8px);background:var(--tix-buy-bg,#1e293b);color:var(--tix-buy-text,#fff);font-weight:700;font-size:14px;cursor:pointer;transition:background 0.2s;margin-top:4px}
        .tix-transfer-btn:hover{opacity:0.9}
        .tix-transfer-btn:disabled{opacity:0.4;cursor:not-allowed}
        .tix-transfer-btn-back{background:transparent;color:var(--tix-sel-text,#64748b);border:1px solid var(--tix-sel-border,#e2e8f0);margin-left:8px}
        .tix-transfer-msg{margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px;line-height:1.5}
        .tix-transfer-msg.error{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}
        .tix-transfer-msg.success{background:#f0fdf4;color:#16a34a;border:1px solid #86efac}
        .tix-transfer-ticket-item{padding:12px 16px;border:2px solid var(--tix-sel-border,#e2e8f0);border-radius:8px;margin-bottom:8px;cursor:pointer;transition:border-color 0.15s,background 0.15s;display:flex;align-items:center;gap:10px}
        .tix-transfer-ticket-item:hover{border-color:var(--tix-buy-bg,#1e293b)}
        .tix-transfer-ticket-item.selected{border-color:var(--tix-buy-bg,#1e293b);background:rgba(30,41,59,0.04)}
        .tix-transfer-ticket-item input[type="checkbox"]{accent-color:var(--tix-buy-bg,#1e293b)}
        .tix-transfer-ticket-info{flex:1}
        .tix-transfer-ticket-name{font-weight:600;font-size:14px}
        .tix-transfer-ticket-code{font-size:11px;color:#94a3b8;font-family:monospace}
        .tix-transfer-ticket-transferred{font-size:11px;color:#f59e0b;margin-top:2px}
        .tix-transfer-confirm-check{margin:16px 0 4px;font-size:13px;color:#475569;line-height:1.5}
        .tix-transfer-confirm-check label{display:flex;align-items:flex-start;gap:8px;cursor:pointer}
        .tix-transfer-confirm-check input[type="checkbox"]{margin-top:3px;accent-color:var(--tix-buy-bg,#1e293b);flex-shrink:0}
        .tix-transfer-success{text-align:center;padding:30px 0}
        .tix-transfer-check{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:#f0fdf4;color:#16a34a;font-size:28px;margin-bottom:14px}
        </style>

        <script>
        (function(){
            'use strict';
            var wrap = document.getElementById('tix-transfer');
            if(!wrap) return;
            var eventId = wrap.dataset.event;
            var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var nonce = '<?php echo esc_js(wp_create_nonce('tix_ticket_transfer')); ?>';
            var selectedTickets = [];
            var tickets = [];

            var $=function(id){return document.getElementById(id);};

            function showMsg(el, text, type) {
                el.textContent = text;
                el.className = 'tix-transfer-msg ' + type;
                el.style.display = 'block';
            }

            // Confirm-Checkbox steuert Save-Button
            $('tix-tf-confirm-check').addEventListener('change', function(){
                $('tix-tf-save').disabled = !this.checked;
            });

            // Schritt 1: Tickets suchen
            $('tix-tf-search').addEventListener('click', function(){
                var order = $('tix-tf-order').value.trim().replace('#','');
                var email = $('tix-tf-email').value.trim();
                var msg = $('tix-tf-msg');
                msg.style.display='none';

                if(!order||!email){showMsg(msg,'Bitte Bestellnummer und E-Mail eingeben.','error');return;}

                this.disabled=true;
                this.textContent='Suche l\u00e4uft\u2026';
                var btn=this;

                var fd = new FormData();
                fd.append('action','tix_transfer_lookup');
                fd.append('nonce',nonce);
                fd.append('order_id',order);
                fd.append('email',email);
                fd.append('event_id',eventId);

                fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
                    btn.disabled=false;
                    btn.textContent='Tickets suchen';
                    if(!res.success){showMsg(msg,res.data||'Keine Tickets gefunden.','error');return;}

                    tickets=res.data;
                    selectedTickets=[];
                    var html='';
                    tickets.forEach(function(t,i){
                        var transferred = t.transferred_to ? '<div class="tix-transfer-ticket-transferred">Bereits umgeschrieben auf: '+t.transferred_to+'</div>' : '';
                        html+='<label class="tix-transfer-ticket-item" data-idx="'+i+'">'+
                            '<input type="checkbox" name="tix_tf_ticket[]" value="'+i+'">'+
                            '<div class="tix-transfer-ticket-info">'+
                                '<div class="tix-transfer-ticket-name">'+t.type_name+'</div>'+
                                '<div class="tix-transfer-ticket-code">'+t.ticket_code+'</div>'+
                                transferred+
                            '</div>'+
                        '</label>';
                    });
                    $('tix-tf-tickets').innerHTML=html;

                    // Click handler für Checkboxen
                    $('tix-tf-tickets').querySelectorAll('.tix-transfer-ticket-item').forEach(function(item){
                        item.addEventListener('click',function(e){
                            var cb = this.querySelector('input[type="checkbox"]');
                            if(e.target !== cb) cb.checked = !cb.checked;
                            this.classList.toggle('selected', cb.checked);
                            // Array neu aufbauen
                            selectedTickets=[];
                            $('tix-tf-tickets').querySelectorAll('input[type="checkbox"]:checked').forEach(function(c){
                                selectedTickets.push(parseInt(c.value));
                            });
                        });
                    });

                    $('tix-transfer-lookup').style.display='none';
                    $('tix-transfer-form').style.display='block';
                }).catch(function(){
                    btn.disabled=false;
                    btn.textContent='Tickets suchen';
                    showMsg(msg,'Fehler bei der Suche.','error');
                });
            });

            // Zurück
            $('tix-tf-back').addEventListener('click',function(){
                $('tix-transfer-form').style.display='none';
                $('tix-transfer-lookup').style.display='block';
                selectedTickets=[];
                $('tix-tf-confirm-check').checked=false;
                $('tix-tf-save').disabled=true;
            });

            // Schritt 2: Umschreiben
            $('tix-tf-save').addEventListener('click',function(){
                var msg=$('tix-tf-msg2');
                msg.style.display='none';

                if(selectedTickets.length===0){showMsg(msg,'Bitte mindestens ein Ticket ausw\u00e4hlen.','error');return;}
                var first=$('tix-tf-new-first').value.trim();
                var last=$('tix-tf-new-last').value.trim();
                var email=$('tix-tf-new-email').value.trim();
                if(!first||!last){showMsg(msg,'Bitte Vor- und Nachname eingeben.','error');return;}
                if(!email){showMsg(msg,'Bitte E-Mail-Adresse eingeben.','error');return;}

                this.disabled=true;
                this.textContent='Wird gespeichert\u2026';
                var btn=this;

                var ticketIds = selectedTickets.map(function(idx){ return tickets[idx].ticket_id; });
                var fd=new FormData();
                fd.append('action','tix_transfer_save');
                fd.append('nonce',nonce);
                fd.append('ticket_ids',JSON.stringify(ticketIds));
                fd.append('first_name',first);
                fd.append('last_name',last);
                fd.append('email',email);

                fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
                    btn.disabled=false;
                    btn.textContent='Tickets umschreiben';
                    if(!res.success){showMsg(msg,res.data||'Fehler beim Umschreiben.','error');return;}

                    var codes = selectedTickets.map(function(idx){ return tickets[idx].ticket_code; });
                    var plural = codes.length > 1;
                    $('tix-tf-confirm-text').textContent =
                        (plural ? codes.length + ' Tickets' : 'Das Ticket') +
                        ' (' + codes.join(', ') + ') ' +
                        (plural ? 'wurden' : 'wurde') +
                        ' erfolgreich auf ' + first + ' ' + last + ' umgeschrieben.' +
                        ' Der neue Inhaber wurde per E-Mail benachrichtigt.';
                    $('tix-transfer-form').style.display='none';
                    $('tix-transfer-done').style.display='block';
                }).catch(function(){
                    btn.disabled=false;
                    btn.textContent='Tickets umschreiben';
                    showMsg(msg,'Fehler beim Speichern.','error');
                });
            });
        })();
        </script>
        <?php echo tix_branding_footer(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Tickets anhand Bestellnummer + E-Mail suchen
     */
    public static function ajax_lookup() {
        check_ajax_referer('tix_ticket_transfer', 'nonce');

        $order_id = intval($_POST['order_id'] ?? 0);
        $email    = sanitize_email($_POST['email'] ?? '');
        $event_id = intval($_POST['event_id'] ?? 0);

        if (!$order_id || !$email) {
            wp_send_json_error('Bitte Bestellnummer und E-Mail eingeben.');
        }

        // WC-Order prüfen
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Bestellung nicht gefunden.');
        }

        // E-Mail prüfen
        if (strtolower($order->get_billing_email()) !== strtolower($email)) {
            wp_send_json_error('E-Mail-Adresse stimmt nicht mit der Bestellung überein.');
        }

        // ── Tickets laden (EH + TC je nach Modus) ──
        $result = [];

        // TIX-eigene Tickets
        if (function_exists('tix_use_own_tickets') && tix_use_own_tickets() && post_type_exists('tix_ticket')) {
            $tix_tickets = get_posts([
                'post_type'      => 'tix_ticket',
                'posts_per_page' => -1,
                'meta_query'     => [
                    ['key' => '_tix_ticket_order_id', 'value' => (string) $order_id],
                    ['key' => '_tix_ticket_status',   'value' => ['valid', 'transferred'], 'compare' => 'IN'],
                ],
                'post_status' => 'publish',
            ]);
            foreach ($tix_tickets as $et) {
                $tix_event_id = intval(get_post_meta($et->ID, '_tix_ticket_event_id', true));
                if ($event_id && $tix_event_id !== $event_id) continue;

                $ci   = intval(get_post_meta($et->ID, '_tix_ticket_cat_index', true));
                $cats = $tix_event_id ? get_post_meta($tix_event_id, '_tix_ticket_categories', true) : [];
                $type_name = (is_array($cats) && isset($cats[$ci])) ? ($cats[$ci]['name'] ?? 'Ticket') : 'Ticket';

                $transferred_to = get_post_meta($et->ID, '_tix_ticket_owner_name', true);
                $status = get_post_meta($et->ID, '_tix_ticket_status', true);

                $result[] = [
                    'ticket_id'      => $et->ID,
                    'ticket_code'    => get_post_meta($et->ID, '_tix_ticket_code', true) ?: $et->post_title,
                    'type_name'      => $type_name,
                    'transferred_to' => ($status === 'transferred') ? ($transferred_to ?: '') : '',
                    'source'         => 'eh',
                ];
            }
        }

        // Tickera-Tickets
        if (function_exists('tix_use_tickera') && tix_use_tickera() && post_type_exists('tc_tickets_instances')) {
            $ticket_posts = get_posts([
                'post_type'      => 'tc_tickets_instances',
                'posts_per_page' => -1,
                'post_parent'    => $order_id,
                'post_status'    => 'any',
            ]);

            // Fallback: order_id Meta
            if (empty($ticket_posts)) {
                $ticket_posts = get_posts([
                    'post_type'      => 'tc_tickets_instances',
                    'posts_per_page' => -1,
                    'meta_query'     => [['key' => 'order_id', 'value' => (string) $order_id]],
                    'post_status'    => 'any',
                ]);
            }

            // Fallback: TC-Order Kette
            if (empty($ticket_posts) && post_type_exists('tc_orders')) {
                $tc_orders = get_posts([
                    'post_type'      => 'tc_orders',
                    'posts_per_page' => -1,
                    'meta_query'     => [['key' => 'order_id', 'value' => (string) $order_id]],
                    'post_status'    => 'any',
                ]);
                foreach ($tc_orders as $tco) {
                    $children = get_posts([
                        'post_type'      => 'tc_tickets_instances',
                        'posts_per_page' => -1,
                        'post_parent'    => $tco->ID,
                        'post_status'    => 'any',
                    ]);
                    $ticket_posts = array_merge($ticket_posts, $children);
                }
            }

            foreach ($ticket_posts as $tp) {
                $tc_event_id = get_post_meta($tp->ID, 'event_id', true);

                if ($event_id) {
                    $parent_event = 0;
                    if ($tc_event_id) {
                        $parent_event = intval(get_post_meta(intval($tc_event_id), '_tix_parent_event_id', true));
                    }
                    if ($parent_event !== $event_id) continue;
                }

                $ticket_code    = get_post_meta($tp->ID, 'ticket_code', true);
                $ticket_type_id = get_post_meta($tp->ID, 'ticket_type_id', true);
                $type_name      = $ticket_type_id ? get_the_title($ticket_type_id) : 'Ticket';
                $transferred_to = get_post_meta($tp->ID, '_tix_transfer_name', true);

                $result[] = [
                    'ticket_id'      => $tp->ID,
                    'ticket_code'    => $ticket_code ?: $tp->post_title,
                    'type_name'      => $type_name,
                    'transferred_to' => $transferred_to ?: '',
                    'source'         => 'tc',
                ];
            }
        }

        // Fallback: Wenn keine Helper-Funktionen existieren, altes TC-Verhalten
        if (!function_exists('tix_use_tickera') && !function_exists('tix_use_own_tickets')) {
            if (post_type_exists('tc_tickets_instances')) {
                $ticket_posts = get_posts([
                    'post_type'      => 'tc_tickets_instances',
                    'posts_per_page' => -1,
                    'post_parent'    => $order_id,
                    'post_status'    => 'any',
                ]);
                foreach ($ticket_posts as $tp) {
                    $tc_event_id = get_post_meta($tp->ID, 'event_id', true);
                    if ($event_id) {
                        $parent_event = $tc_event_id ? intval(get_post_meta(intval($tc_event_id), '_tix_parent_event_id', true)) : 0;
                        if ($parent_event !== $event_id) continue;
                    }
                    $ticket_code    = get_post_meta($tp->ID, 'ticket_code', true);
                    $ticket_type_id = get_post_meta($tp->ID, 'ticket_type_id', true);
                    $result[] = [
                        'ticket_id'      => $tp->ID,
                        'ticket_code'    => $ticket_code ?: $tp->post_title,
                        'type_name'      => $ticket_type_id ? get_the_title($ticket_type_id) : 'Ticket',
                        'transferred_to' => get_post_meta($tp->ID, '_tix_transfer_name', true) ?: '',
                        'source'         => 'tc',
                    ];
                }
            }
        }

        if (empty($result)) {
            wp_send_json_error('Keine Tickets für dieses Event gefunden.');
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Tickets umschreiben (Name + E-Mail ändern, User anlegen, benachrichtigen)
     */
    public static function ajax_save() {
        check_ajax_referer('tix_ticket_transfer', 'nonce');

        // Multi-Ticket: JSON-Array oder Fallback auf einzelne ID
        $ticket_ids_raw = $_POST['ticket_ids'] ?? '';
        $ticket_ids = $ticket_ids_raw ? json_decode(stripslashes($ticket_ids_raw), true) : [];
        if (!is_array($ticket_ids) || empty($ticket_ids)) {
            $single = intval($_POST['ticket_id'] ?? 0);
            $ticket_ids = $single ? [$single] : [];
        }
        $ticket_ids = array_map('intval', $ticket_ids);
        $ticket_ids = array_filter($ticket_ids);

        if (empty($ticket_ids)) {
            wp_send_json_error('Bitte mindestens ein Ticket auswählen.');
        }

        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
        $new_email  = sanitize_email($_POST['email'] ?? '');

        if (!$first_name || !$last_name) {
            wp_send_json_error('Bitte Vor- und Nachname eingeben.');
        }
        if (!$new_email) {
            wp_send_json_error('Bitte E-Mail-Adresse eingeben.');
        }

        $full_name = $first_name . ' ' . $last_name;

        // ── User-Account anlegen / finden ──
        $new_user_id  = 0;
        $user_created = false;

        $existing_user = get_user_by('email', $new_email);
        if ($existing_user) {
            $new_user_id = $existing_user->ID;
        } else {
            $username = self::generate_username($new_email, $first_name, $last_name);
            $new_user_id = wp_insert_user([
                'user_login'   => $username,
                'user_email'   => $new_email,
                'user_pass'    => wp_generate_password(12, true),
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'display_name' => $full_name,
                'role'         => class_exists('WooCommerce') ? 'customer' : 'subscriber',
            ]);
            if (is_wp_error($new_user_id)) {
                wp_send_json_error('Fehler bei der Kontoerstellung: ' . $new_user_id->get_error_message());
            }
            $user_created = true;

            // WordPress Passwort-Reset-E-Mail senden
            wp_new_user_notification($new_user_id, null, 'user');
        }

        // ── Tickets verarbeiten ──
        $processed = [];
        foreach ($ticket_ids as $ticket_id) {
            $ticket = get_post($ticket_id);
            if (!$ticket) continue;

            $tix_event_id = 0;
            $ticket_code = '';

            if ($ticket->post_type === 'tix_ticket') {
                // ── TIX-Ticket: eigene Meta-Felder ──
                update_post_meta($ticket_id, '_tix_ticket_status', 'transferred');
                update_post_meta($ticket_id, '_tix_ticket_owner_name', $full_name);
                update_post_meta($ticket_id, '_tix_ticket_owner_email', $new_email);
                update_post_meta($ticket_id, '_tix_ticket_transfer_to', $new_user_id);
                update_post_meta($ticket_id, '_tix_ticket_transfer_date', current_time('c'));
                update_post_meta($ticket_id, '_tix_ticket_transfer_name', $full_name);

                $tix_event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
                $ticket_code = get_post_meta($ticket_id, '_tix_ticket_code', true);

            } elseif ($ticket->post_type === 'tc_tickets_instances') {
                // ── TC-Ticket: Transfer-Metadaten ──
                update_post_meta($ticket_id, '_tix_transfer_name', $full_name);
                update_post_meta($ticket_id, '_tix_transfer_email', $new_email);
                update_post_meta($ticket_id, '_tix_transfer_first_name', $first_name);
                update_post_meta($ticket_id, '_tix_transfer_last_name', $last_name);
                update_post_meta($ticket_id, '_tix_transfer_date', current_time('c'));
                update_post_meta($ticket_id, '_tix_transfer_user_id', $new_user_id);

                // Tickera-eigene Felder
                update_post_meta($ticket_id, 'first_name', $first_name);
                update_post_meta($ticket_id, 'last_name', $last_name);

                $tc_event_id = get_post_meta($ticket_id, 'event_id', true);
                if ($tc_event_id) {
                    $tix_event_id = intval(get_post_meta(intval($tc_event_id), '_tix_parent_event_id', true));
                }
                $ticket_code = get_post_meta($ticket_id, 'ticket_code', true);

            } else {
                continue;
            }

            // Gästeliste aktualisieren (für beide Ticket-Typen)
            if ($tix_event_id && $ticket_code) {
                $guests = get_post_meta($tix_event_id, '_tix_guest_list', true);
                if (is_array($guests)) {
                    foreach ($guests as &$g) {
                        if (isset($g['ticket_code']) && $g['ticket_code'] === $ticket_code) {
                            $g['name'] = $full_name;
                            break;
                        }
                    }
                    unset($g);
                    update_post_meta($tix_event_id, '_tix_guest_list', $guests);
                }
            }

            $processed[] = $ticket_id;
        }

        if (empty($processed)) {
            wp_send_json_error('Keine gültigen Tickets gefunden.');
        }

        // ── Transfer-Benachrichtigung senden ──
        self::send_transfer_notification($new_user_id, $new_email, $first_name, $full_name, $processed, $user_created);

        wp_send_json_success(['name' => $full_name, 'count' => count($processed)]);
    }

    /**
     * Eindeutigen Benutzernamen generieren
     */
    private static function generate_username($email, $first_name, $last_name) {
        $base = sanitize_user(strtok($email, '@'), true);
        if (!$base) {
            $base = sanitize_user(strtolower($first_name . '.' . $last_name), true);
        }
        if (!$base) {
            $base = 'user';
        }
        $username = $base;
        $suffix = 1;
        while (username_exists($username)) {
            $username = $base . $suffix;
            $suffix++;
        }
        return $username;
    }

    /**
     * Benachrichtigungs-E-Mail an den neuen Inhaber senden
     */
    private static function send_transfer_notification($user_id, $email, $first_name, $full_name, $ticket_ids, $user_created) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $brand_name  = $s['email_brand_name'] ?? get_bloginfo('name');
        $logo_url    = $s['email_logo_url'] ?? '';
        $footer_text = $s['email_footer_text'] ?? '';
        $accent      = $s['color_accent'] ?? '#c8ff00';
        $accent_text = $s['color_accent_text'] ?? '#000000';
        $border      = $s['color_border'] ?? '#333333';
        $radius      = intval($s['radius_general'] ?? 8);

        if (!$brand_name) $brand_name = get_bloginfo('name');

        // Ticket-Infos sammeln
        $ticket_rows = '';
        foreach ($ticket_ids as $tid) {
            $ticket_post = get_post($tid);
            $code       = '';
            $type_name  = 'Ticket';
            $event_name = '';

            if ($ticket_post && $ticket_post->post_type === 'tix_ticket') {
                // TIX-Ticket
                $code        = get_post_meta($tid, '_tix_ticket_code', true);
                $tix_event_id = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
                $ci          = intval(get_post_meta($tid, '_tix_ticket_cat_index', true));
                if ($tix_event_id) {
                    $event_name = get_the_title($tix_event_id);
                    $cats = get_post_meta($tix_event_id, '_tix_ticket_categories', true);
                    if (is_array($cats) && isset($cats[$ci])) {
                        $type_name = $cats[$ci]['name'] ?? 'Ticket';
                    }
                }
            } else {
                // TC-Ticket
                $code     = get_post_meta($tid, 'ticket_code', true);
                $type_id  = get_post_meta($tid, 'ticket_type_id', true);
                $type_name = $type_id ? get_the_title($type_id) : 'Ticket';
                $tc_event_id = get_post_meta($tid, 'event_id', true);
                if ($tc_event_id) {
                    $tix_event_id = get_post_meta(intval($tc_event_id), '_tix_parent_event_id', true);
                    if ($tix_event_id) $event_name = get_the_title($tix_event_id);
                }
            }
            $ticket_rows .= '<tr>'
                . '<td style="padding:8px 12px;font-size:13px;color:#1a1a1a;border-bottom:1px solid #e5e7eb;">' . esc_html($type_name) . '</td>'
                . '<td style="padding:8px 12px;font-size:13px;color:#1a1a1a;border-bottom:1px solid #e5e7eb;">' . esc_html($event_name) . '</td>'
                . '<td style="padding:8px 12px;font-size:12px;color:#94a3b8;font-family:monospace;border-bottom:1px solid #e5e7eb;">' . esc_html($code) . '</td>'
                . '</tr>';
        }

        // Meine-Tickets-Seite finden
        global $wpdb;
        $page_id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%[tix_my_tickets]%' AND post_status = 'publish' AND post_type = 'page' LIMIT 1");
        $my_tickets_url = $page_id ? get_permalink($page_id) : home_url();

        // HTML E-Mail
        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"></head>'
              . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:\'Inter\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';

        // Container
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;"><tr><td align="center" style="padding:32px 16px;">';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;background:#ffffff;border-radius:' . $radius . 'px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">';

        // Header
        $html .= '<tr><td style="background:#1a1a1a;padding:24px 32px;text-align:center;">';
        if ($logo_url) {
            $html .= '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($brand_name) . '" style="max-width:180px;max-height:48px;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">';
        }
        $html .= '<div style="color:#ffffff;font-size:20px;font-weight:700;margin-bottom:4px;">Tickets umgeschrieben</div>';
        $html .= '<div style="color:#94a3b8;font-size:14px;">' . esc_html($brand_name) . '</div>';
        $html .= '</td></tr>';

        // Body
        $html .= '<tr><td style="padding:32px;">';

        // Begrüßung
        $html .= '<div style="font-size:16px;font-weight:600;color:#1a1a1a;margin-bottom:4px;">Hallo ' . esc_html($first_name) . ',</div>';
        $count = count($ticket_ids);
        $html .= '<div style="font-size:14px;color:#6b7280;margin-bottom:24px;line-height:1.6;">'
                . ($count === 1 ? 'Ein Ticket wurde' : $count . ' Tickets wurden')
                . ' auf dich umgeschrieben. Hier sind die Details:</div>';

        // Ticket-Tabelle
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5e7eb;border-radius:' . $radius . 'px;overflow:hidden;margin-bottom:24px;">';
        $html .= '<tr>'
                . '<th style="padding:10px 12px;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;text-align:left;background:#f8fafc;border-bottom:1px solid #e5e7eb;">Ticket</th>'
                . '<th style="padding:10px 12px;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;text-align:left;background:#f8fafc;border-bottom:1px solid #e5e7eb;">Event</th>'
                . '<th style="padding:10px 12px;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;text-align:left;background:#f8fafc;border-bottom:1px solid #e5e7eb;">Code</th>'
                . '</tr>';
        $html .= $ticket_rows;
        $html .= '</table>';

        // Hinweis für neue User
        if ($user_created) {
            $html .= '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:' . $radius . 'px;padding:14px 16px;font-size:13px;color:#15803d;margin-bottom:24px;line-height:1.5;">'
                    . 'Es wurde ein Benutzerkonto f&uuml;r dich erstellt. Du erh&auml;ltst eine separate E-Mail mit einem Link, um dein Passwort festzulegen.'
                    . '</div>';
        }

        // CTA
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">';
        $html .= '<a href="' . esc_url($my_tickets_url) . '" style="display:inline-block;padding:12px 32px;background-color:' . esc_attr($accent) . ';color:' . esc_attr($accent_text) . ';text-decoration:none;font-weight:700;font-size:14px;border-radius:' . $radius . 'px;">Meine Tickets anzeigen</a>';
        $html .= '</td></tr></table>';

        $html .= '</td></tr>';

        // Footer
        $html .= '<tr><td style="background:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;">';
        if ($footer_text) {
            $html .= '<div style="font-size:12px;color:#9ca3af;">' . wp_kses_post($footer_text) . '</div>';
        } else {
            $html .= '<div style="font-size:12px;color:#9ca3af;">' . esc_html($brand_name) . '</div>';
        }
        $html .= '</td></tr>';

        $html .= '</table></td></tr></table></body></html>';

        $subject = ($count === 1 ? 'Ein Ticket wurde' : $count . ' Tickets wurden') . ' auf dich umgeschrieben - ' . $brand_name;
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($email, $subject, $html, $headers);
    }
}
