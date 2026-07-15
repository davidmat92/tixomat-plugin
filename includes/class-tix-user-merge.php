<?php
/**
 * TIX User Merge — zwei WP-Users bzw. Kunden zusammenfuehren.
 *
 * Wo Kunden-Daten haengen (in dieser Reihenfolge migriert):
 *   - wp_tix_orders.billing_email / customer_id
 *   - wp_tix_waitlist.email
 *   - wp_tix_feedback.email
 *   - wp_tix_email_log.email
 *   - Post-Meta _tix_ticket_owner_email (auf tix_ticket-Posts)
 *   - Post-Author auf tix-Custom-Post-Types (falls User Autor war)
 *
 * Sekundaerer User wird NICHT geloescht, sondern archiviert:
 *   - E-Mail wird zu "merged_<ts>_<orig>" (blockt Login + Konflikt)
 *   - Rolle wird entfernt (kein Backend-Zugriff mehr)
 *
 * Audit-Trail:
 *   - Order-Note auf jeder migrierten Order
 *   - Zentrales Log in wp_options _tix_user_merges (append-only)
 */
if (!defined('ABSPATH')) exit;

class TIX_User_Merge {

    const OPT_LOG    = '_tix_user_merges';
    const CAPABILITY = 'manage_options';

    public static function init() {
        add_action('admin_menu',                            [__CLASS__, 'register_menu'], 70);
        add_action('wp_ajax_tix_user_merge_search',         [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_tix_user_merge_preview',        [__CLASS__, 'ajax_preview']);
        add_action('wp_ajax_tix_user_merge_execute',        [__CLASS__, 'ajax_execute']);
    }

    /* ─────────── Admin-Menu + Seite ─────────── */

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Kunden zusammenfuehren',
            '🔀 Kunden verknuepfen',
            self::CAPABILITY,
            'tix-user-merge',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can(self::CAPABILITY)) return;
        $log = get_option(self::OPT_LOG, []);
        if (!is_array($log)) $log = [];
        $recent = array_slice(array_reverse($log), 0, 10);
        $nonce = wp_create_nonce('tix_user_merge');
        ?>
        <div class="wrap tix-user-merge">
            <h1 style="display:flex;align-items:center;gap:10px;">🔀 Kunden zusammenfuehren</h1>
            <p style="max-width:820px;color:#475569;">
                Zwei WP-Users oder Kunden-Datensaetze verschmelzen. Alle Bestellungen, Tickets, Warteliste,
                Feedback und Ticket-Owner-Referenzen des <em>sekundaeren</em> Kontos werden auf den
                <em>primaeren</em> uebertragen. Die primaere E-Mail waehlst du aus.
            </p>
            <p style="max-width:820px;color:#7c2d12;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;font-size:13px;">
                ⚠️ <strong>Nicht trivial rueckgaengig zu machen.</strong> Vor dem Merge unbedingt die Vorschau pruefen.
                Sekundaerer Account wird archiviert (E-Mail umbenannt, Rolle entfernt) — nicht geloescht.
            </p>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:16px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <div>
                        <label style="font-weight:600;display:block;margin-bottom:8px;">Kunde A (primaer — bleibt aktiv)</label>
                        <input type="text" id="tix-merge-search-a" placeholder="Suche nach E-Mail oder Name…" autocomplete="off"
                               style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                        <div id="tix-merge-results-a" class="tix-merge-results" style="display:none;"></div>
                        <div id="tix-merge-selected-a" class="tix-merge-selected" style="display:none;"></div>
                    </div>
                    <div>
                        <label style="font-weight:600;display:block;margin-bottom:8px;">Kunde B (sekundaer — wird archiviert)</label>
                        <input type="text" id="tix-merge-search-b" placeholder="Suche nach E-Mail oder Name…" autocomplete="off"
                               style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                        <div id="tix-merge-results-b" class="tix-merge-results" style="display:none;"></div>
                        <div id="tix-merge-selected-b" class="tix-merge-selected" style="display:none;"></div>
                    </div>
                </div>

                <div id="tix-merge-preview" style="margin-top:20px;"></div>
            </div>

            <?php if (!empty($recent)): ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:16px;">
                <h2 style="margin:0 0 12px;font-size:16px;">Letzte Merges</h2>
                <table class="widefat striped" style="background:#fff;">
                    <thead>
                        <tr>
                            <th>Wann</th>
                            <th>Primaer (Behalten)</th>
                            <th>Sekundaer (Archiviert)</th>
                            <th>Uebertragen</th>
                            <th>Von</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $entry):
                        $counts = $entry['counts'] ?? [];
                        $count_str = [];
                        if (!empty($counts['orders']))   $count_str[] = intval($counts['orders']).' Bestellungen';
                        if (!empty($counts['tickets']))  $count_str[] = intval($counts['tickets']).' Tickets';
                        if (!empty($counts['waitlist'])) $count_str[] = intval($counts['waitlist']).' Wartelisten';
                        if (!empty($counts['feedback'])) $count_str[] = intval($counts['feedback']).' Feedbacks';
                    ?>
                        <tr>
                            <td><?php echo esc_html($entry['when'] ?? '—'); ?></td>
                            <td><strong><?php echo esc_html($entry['primary_email'] ?? ''); ?></strong><br><small>#<?php echo intval($entry['primary_id'] ?? 0); ?></small></td>
                            <td><?php echo esc_html($entry['secondary_email'] ?? ''); ?><br><small>#<?php echo intval($entry['secondary_id'] ?? 0); ?></small></td>
                            <td><?php echo esc_html(implode(', ', $count_str) ?: '—'); ?></td>
                            <td><?php echo esc_html($entry['by'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <style>
            .tix-merge-results { max-height:220px;overflow:auto;border:1px solid #d1d5db;border-radius:6px;background:#fff;margin-top:4px; }
            .tix-merge-result-row { padding:8px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer;font-size:13px; }
            .tix-merge-result-row:hover { background:#f8fafc; }
            .tix-merge-result-row:last-child { border-bottom:none; }
            .tix-merge-selected { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px;margin-top:8px;font-size:13px; }
            .tix-merge-selected .rm { float:right;color:#dc2626;cursor:pointer;font-weight:600; }
        </style>

        <script>
        (function(){
            const nonce = '<?php echo esc_js($nonce); ?>';
            const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            let selectedA = null, selectedB = null;

            function debounce(fn, wait){ let t; return function(){ clearTimeout(t); const a=arguments,c=this; t=setTimeout(()=>fn.apply(c,a),wait); }; }

            function attachSearch(inputId, resultsId, selectedId, which) {
                const input = document.getElementById(inputId);
                const results = document.getElementById(resultsId);
                const selectedBox = document.getElementById(selectedId);

                input.addEventListener('input', debounce(function(){
                    const q = input.value.trim();
                    if (q.length < 2) { results.style.display='none'; results.innerHTML=''; return; }
                    fetch(ajaxUrl, {
                        method:'POST',
                        headers:{'Content-Type':'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({action:'tix_user_merge_search', _wpnonce:nonce, q:q})
                    }).then(r=>r.json()).then(data => {
                        if (!data.success || !data.data.length) { results.innerHTML='<div class="tix-merge-result-row" style="color:#94a3b8;">Kein Treffer</div>'; results.style.display='block'; return; }
                        results.innerHTML = data.data.map(u =>
                            `<div class="tix-merge-result-row" data-id="${u.id}" data-email="${u.email.replace(/"/g,'&quot;')}" data-name="${(u.display_name||'').replace(/"/g,'&quot;')}" data-orders="${u.orders}">
                                <strong>${u.display_name||'(ohne Namen)'}</strong> — <code>${u.email}</code>
                                <span style="float:right;color:#64748b;">#${u.id} · ${u.orders} Best.</span>
                            </div>`).join('');
                        results.style.display='block';
                        results.querySelectorAll('.tix-merge-result-row[data-id]').forEach(row => {
                            row.addEventListener('click', function(){
                                const user = { id: parseInt(this.dataset.id,10), email: this.dataset.email, display_name: this.dataset.name, orders: parseInt(this.dataset.orders,10) };
                                if (which === 'a') selectedA = user; else selectedB = user;
                                selectedBox.innerHTML = `<span class="rm">×</span><strong>${user.display_name||'(ohne Namen)'}</strong> — <code>${user.email}</code> <br><small>User #${user.id} · ${user.orders} Bestellungen</small>`;
                                selectedBox.style.display='block';
                                selectedBox.querySelector('.rm').addEventListener('click', function(){
                                    if (which === 'a') selectedA = null; else selectedB = null;
                                    selectedBox.style.display='none';
                                    document.getElementById('tix-merge-preview').innerHTML='';
                                });
                                results.style.display='none';
                                input.value = '';
                                maybePreview();
                            });
                        });
                    });
                }, 250));
            }

            function maybePreview() {
                if (!selectedA || !selectedB) return;
                if (selectedA.id === selectedB.id) {
                    document.getElementById('tix-merge-preview').innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;color:#991b1b;">Beide Auswahlen sind derselbe User.</div>';
                    return;
                }
                const preview = document.getElementById('tix-merge-preview');
                preview.innerHTML = '<div style="color:#64748b;">Lade Vorschau…</div>';
                fetch(ajaxUrl, {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action:'tix_user_merge_preview', _wpnonce:nonce, primary_id:selectedA.id, secondary_id:selectedB.id})
                }).then(r=>r.json()).then(data => {
                    if (!data.success) { preview.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;color:#991b1b;">'+(data.data?.message||'Fehler')+'</div>'; return; }
                    const d = data.data;
                    preview.innerHTML = `
                        <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;">
                            <h3 style="margin:0 0 12px;">Vorschau</h3>
                            <table class="widefat" style="background:#fff;">
                                <tr><th>Uebertragen von B nach A</th><th style="width:100px;">Anzahl</th></tr>
                                <tr><td>Bestellungen</td><td><strong>${d.orders}</strong></td></tr>
                                <tr><td>Tickets (Owner-E-Mail)</td><td><strong>${d.tickets}</strong></td></tr>
                                <tr><td>Wartelisten-Eintraege</td><td><strong>${d.waitlist}</strong></td></tr>
                                <tr><td>Feedback-Eintraege</td><td><strong>${d.feedback}</strong></td></tr>
                                <tr><td>E-Mail-Log</td><td><strong>${d.email_log}</strong></td></tr>
                                <tr><td>Post-Autorschaften</td><td><strong>${d.posts}</strong></td></tr>
                            </table>
                            <div style="margin-top:16px;padding:12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;">
                                <div style="font-weight:600;margin-bottom:8px;">Primaere E-Mail (die bleibt aktiv):</div>
                                <label style="display:block;margin-bottom:6px;"><input type="radio" name="tix_primary_email" value="a" checked> <code>${d.a_email}</code> <small>(aktuell A)</small></label>
                                <label style="display:block;"><input type="radio" name="tix_primary_email" value="b"> <code>${d.b_email}</code> <small>(aktuell B — wird zur E-Mail von A)</small></label>
                            </div>
                            <div style="margin-top:16px;text-align:right;">
                                <button type="button" id="tix-merge-do" class="button button-primary button-large" style="background:#dc2626;border-color:#b91c1c;">🔀 Jetzt zusammenfuehren</button>
                            </div>
                        </div>`;
                    document.getElementById('tix-merge-do').addEventListener('click', executeMerge);
                });
            }

            function executeMerge() {
                if (!confirm('Wirklich zusammenfuehren? Sekundaerer Account wird archiviert. Diese Aktion ist nur schwer rueckgaengig zu machen.')) return;
                const primaryEmailChoice = document.querySelector('input[name="tix_primary_email"]:checked').value;
                const btn = document.getElementById('tix-merge-do');
                btn.disabled = true; btn.textContent = 'Fuehre zusammen…';
                fetch(ajaxUrl, {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action:'tix_user_merge_execute',
                        _wpnonce:nonce,
                        primary_id:selectedA.id,
                        secondary_id:selectedB.id,
                        primary_email_from: primaryEmailChoice // 'a' oder 'b'
                    })
                }).then(r=>r.json()).then(data => {
                    if (data.success) {
                        alert('✅ Erfolgreich zusammengefuehrt. Seite wird neu geladen.');
                        location.reload();
                    } else {
                        alert('❌ Fehler: ' + (data.data?.message || 'Unbekannt'));
                        btn.disabled = false; btn.textContent = '🔀 Jetzt zusammenfuehren';
                    }
                });
            }

            attachSearch('tix-merge-search-a', 'tix-merge-results-a', 'tix-merge-selected-a', 'a');
            attachSearch('tix-merge-search-b', 'tix-merge-results-b', 'tix-merge-selected-b', 'b');
        })();
        </script>
        <?php
    }

    /* ─────────── AJAX: Suche ─────────── */

    public static function ajax_search() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(['message' => 'no perm']);
        check_ajax_referer('tix_user_merge');
        global $wpdb;
        $q = trim(sanitize_text_field($_POST['q'] ?? ''));
        if (strlen($q) < 2) wp_send_json_success([]);

        $like = '%' . $wpdb->esc_like($q) . '%';
        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, user_email, display_name
             FROM {$wpdb->users}
             WHERE user_email LIKE %s OR display_name LIKE %s OR user_login LIKE %s
             ORDER BY user_registered DESC LIMIT 15",
            $like, $like, $like
        ));

        $out = [];
        foreach ($users as $u) {
            $order_count = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders WHERE billing_email = %s OR customer_id = %d",
                $u->user_email, $u->ID
            )));
            $out[] = [
                'id'           => intval($u->ID),
                'email'        => $u->user_email,
                'display_name' => $u->display_name,
                'orders'       => $order_count,
            ];
        }
        wp_send_json_success($out);
    }

    /* ─────────── AJAX: Preview ─────────── */

    public static function ajax_preview() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(['message' => 'no perm']);
        check_ajax_referer('tix_user_merge');

        $primary_id   = intval($_POST['primary_id'] ?? 0);
        $secondary_id = intval($_POST['secondary_id'] ?? 0);
        if (!$primary_id || !$secondary_id || $primary_id === $secondary_id) {
            wp_send_json_error(['message' => 'Ungueltige Auswahl.']);
        }

        $a = get_userdata($primary_id);
        $b = get_userdata($secondary_id);
        if (!$a || !$b) wp_send_json_error(['message' => 'User nicht gefunden.']);

        $counts = self::count_migratables($a, $b);
        wp_send_json_success(array_merge($counts, [
            'a_email' => $a->user_email,
            'b_email' => $b->user_email,
        ]));
    }

    /**
     * Zaehlt was migriert wuerde (fuer Preview).
     */
    private static function count_migratables($primary_user, $secondary_user): array {
        global $wpdb;
        $a_email = $secondary_user->user_email;
        $b_id    = $secondary_user->ID;

        $orders = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tix_orders WHERE billing_email = %s OR customer_id = %d",
            $a_email, $b_id
        )));
        $tickets = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tix_ticket_owner_email' AND meta_value = %s",
            $a_email
        )));
        $waitlist = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tix_waitlist WHERE email = %s", $a_email
        )));
        $feedback = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tix_feedback WHERE email = %s", $a_email
        )));
        $email_log = 0;
        $log_table = $wpdb->prefix . 'tix_email_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") === $log_table) {
            $has_email_col = $wpdb->get_var("SHOW COLUMNS FROM $log_table LIKE 'email'");
            if ($has_email_col) {
                $email_log = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $log_table WHERE email = %s", $a_email
                )));
            }
        }
        $posts = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d", $b_id
        )));

        return compact('orders', 'tickets', 'waitlist', 'feedback', 'email_log', 'posts');
    }

    /* ─────────── AJAX: Execute ─────────── */

    public static function ajax_execute() {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error(['message' => 'no perm']);
        check_ajax_referer('tix_user_merge');

        $primary_id   = intval($_POST['primary_id'] ?? 0);
        $secondary_id = intval($_POST['secondary_id'] ?? 0);
        $email_from   = ($_POST['primary_email_from'] ?? 'a') === 'b' ? 'b' : 'a';

        if (!$primary_id || !$secondary_id || $primary_id === $secondary_id) {
            wp_send_json_error(['message' => 'Ungueltige Auswahl.']);
        }
        $a = get_userdata($primary_id);
        $b = get_userdata($secondary_id);
        if (!$a || !$b) wp_send_json_error(['message' => 'User nicht gefunden.']);

        $result = self::merge($a, $b, $email_from);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /**
     * Fuehrt zwei User zusammen.
     *
     * @param WP_User $primary   Behalt-Account
     * @param WP_User $secondary Wird archiviert
     * @param string  $email_from 'a' = primary.email bleibt, 'b' = secondary.email wird primaer
     * @return array|WP_Error
     */
    public static function merge($primary, $secondary, string $email_from = 'a') {
        global $wpdb;
        if ($primary->ID === $secondary->ID) {
            return new WP_Error('same_user', 'Primaer und sekundaer sind derselbe User.');
        }

        $secondary_email = $secondary->user_email;
        $counts = self::count_migratables($primary, $secondary);

        // Ziel-E-Mail bestimmen
        $target_email = $email_from === 'b' ? $secondary->user_email : $primary->user_email;

        // 1) Falls primaere E-Mail von B kommen soll, MUSS B's E-Mail zuerst freigeraeumt werden
        //    (WP erlaubt keine 2 User mit gleicher E-Mail). Wir setzen B temporaer auf archived.
        $archived_email = 'merged_' . time() . '_' . $secondary->user_email;
        $wpdb->update($wpdb->users, ['user_email' => $archived_email], ['ID' => $secondary->ID]);
        clean_user_cache($secondary->ID);

        // 2) Ggf. primaere E-Mail auf A setzen (falls B's E-Mail gewaehlt wurde)
        if ($email_from === 'b' && $primary->user_email !== $secondary_email) {
            $wpdb->update($wpdb->users, ['user_email' => $secondary_email], ['ID' => $primary->ID]);
            clean_user_cache($primary->ID);
            $target_email = $secondary_email;
        }

        // 3) Alle Referenzen auf B umschreiben — beide moeglichen Matcher: alte B-Email + B-user_id
        $b_email = $secondary_email; // Original vor Archivierung

        // 3a) tix_orders: billing_email + customer_id
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tix_orders SET billing_email = %s WHERE billing_email = %s",
            $target_email, $b_email
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tix_orders SET customer_id = %d WHERE customer_id = %d",
            $primary->ID, $secondary->ID
        ));
        // Auch wenn primary.email gewechselt hat: alte primary-Email umbiegen (Datenpflege)
        if ($email_from === 'b' && $primary->user_email !== $target_email) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}tix_orders SET billing_email = %s WHERE billing_email = %s",
                $target_email, $primary->user_email
            ));
        }

        // 3b) Waitlist / Feedback / EmailLog — email nur updaten wenn kein Konflikt (unique email+event)
        //     Warteliste: eventgleiche Duplikate wuerden UNIQUE brechen — wir droppen die B-Eintraege in dem Fall.
        self::migrate_email_column($wpdb->prefix . 'tix_waitlist', $b_email, $target_email, ['event_id']);
        self::migrate_email_column($wpdb->prefix . 'tix_feedback', $b_email, $target_email, []);

        $log_table = $wpdb->prefix . 'tix_email_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '$log_table'") === $log_table) {
            $has_email_col = $wpdb->get_var("SHOW COLUMNS FROM $log_table LIKE 'email'");
            if ($has_email_col) {
                $wpdb->query($wpdb->prepare("UPDATE $log_table SET email = %s WHERE email = %s", $target_email, $b_email));
            }
        }

        // 3c) Post-Meta _tix_ticket_owner_email
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_tix_ticket_owner_email' AND meta_value = %s",
            $target_email, $b_email
        ));

        // 3d) Post-Autorschaft
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_author = %d WHERE post_author = %d",
            $primary->ID, $secondary->ID
        ));

        // 4) Order-Notes: Merge-Info auf jede uebertragene Order
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            $touched_orders = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tix_orders WHERE customer_id = %d AND billing_email = %s",
                $primary->ID, $target_email
            ));
            foreach ($touched_orders as $oid) {
                TIX_Order_Admin::add_note(
                    intval($oid),
                    sprintf('🔀 Kunden-Merge: dieser Datensatz wurde von User #%d (%s) zu User #%d (%s) uebertragen.',
                        $secondary->ID, $b_email, $primary->ID, $target_email),
                    'system'
                );
            }
        }

        // 5) Sekundaeren User archivieren: Rolle entfernen (kein Backend/Login-Zugriff mehr)
        $sec = new WP_User($secondary->ID);
        foreach ((array) $sec->roles as $r) $sec->remove_role($r);

        // 6) Zentrales Merge-Log
        $log = get_option(self::OPT_LOG, []);
        if (!is_array($log)) $log = [];
        $current = wp_get_current_user();
        $log[] = [
            'when'            => current_time('mysql'),
            'primary_id'      => $primary->ID,
            'primary_email'   => $target_email,
            'secondary_id'    => $secondary->ID,
            'secondary_email' => $b_email,
            'email_from'      => $email_from,
            'counts'          => $counts,
            'by'              => $current ? $current->user_login : 'system',
        ];
        update_option(self::OPT_LOG, $log, false);

        return [
            'primary_id'      => $primary->ID,
            'primary_email'   => $target_email,
            'secondary_id'    => $secondary->ID,
            'secondary_email_archived' => $archived_email,
            'counts'          => $counts,
        ];
    }

    /**
     * Migriert Email in einer Tabelle. Bei UNIQUE-Konflikten (z.B. gleicher Waitlist-Eintrag
     * fuer selbes Event) wird der B-Eintrag geloescht statt umgeschrieben.
     */
    private static function migrate_email_column($table, $old_email, $new_email, array $conflict_keys) {
        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return;

        // Rows finden die migriert werden sollen
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE email = %s", $old_email));
        foreach ($rows as $row) {
            // Duplikat-Check mit konflikt_keys
            if (!empty($conflict_keys)) {
                $where = ["email = %s"];
                $vals  = [$new_email];
                foreach ($conflict_keys as $k) {
                    $where[] = "$k = %s";
                    $vals[]  = $row->{$k};
                }
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE " . implode(' AND ', $where) . " LIMIT 1", ...$vals
                ));
                if ($exists) {
                    $wpdb->delete($table, ['id' => $row->id]);
                    continue;
                }
            }
            $wpdb->update($table, ['email' => $new_email], ['id' => $row->id]);
        }
    }
}

TIX_User_Merge::init();
