/**
 * Tixomat – Support System JS
 * Admin Dashboard + Frontend Portal
 * @since 1.23.0
 */
(function($) {
    'use strict';

    if (typeof tixSupport === 'undefined') return;

    var S = tixSupport;
    var isAdmin  = !!S.isAdmin;
    var isFront  = !!S.isFrontend;
    var isChat   = !!S.isChatWidget;

    // ══════════════════════════════════════════════
    // ADMIN DASHBOARD
    // ══════════════════════════════════════════════

    if (isAdmin) {
        var currentPage   = 1;
        var currentTicket = null;
        var listEl        = $('#tix-sp-ticket-list');
        var detailEl      = $('#tix-sp-ticket-detail');

        // ── Tab Navigation ──
        $(document).on('click', '.tix-nav-tab', function(e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            $('.tix-nav-tab').removeClass('active');
            $(this).addClass('active');
            $('.tix-pane').removeClass('active');
            $('[data-pane="' + tab + '"]').addClass('active');

            if (tab === 'tickets') loadTicketList();
            if (tab === 'stats')   loadStats();
        });

        // ── Anfragen-Liste laden ──
        function loadTicketList() {
            var status   = $('#tix-sp-filter-status').val();
            var category = $('#tix-sp-filter-category').val();
            var search   = $('#tix-sp-filter-search').val();

            listEl.html('<div class="tix-sp-loading">Lade Anfragen…</div>');
            detailEl.hide();

            $.post(S.ajax, {
                action:   'tix_support_list',
                nonce:    S.nonce,
                status:   status,
                category: category,
                search:   search,
                page:     currentPage,
            }, function(r) {
                if (!r.success) { listEl.html('<div class="tix-sp-empty"><span class="dashicons dashicons-warning"></span>Fehler beim Laden.</div>'); return; }

                var d = r.data;
                if (!d.tickets.length) {
                    listEl.html('<div class="tix-sp-empty"><span class="dashicons dashicons-format-chat"></span>Keine Anfragen gefunden.</div>');
                    return;
                }

                var html = '';

                // ── Bulk-Toolbar (versteckt, erscheint bei Selektion) ──
                html += '<div class="tix-sp-bulk-toolbar" id="tix-sp-bulk-toolbar" style="display:none;align-items:center;gap:10px;padding:10px 14px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;margin-bottom:10px;font-size:13px;">';
                html += '<strong id="tix-sp-bulk-count" style="color:#92400e;">0 ausgewählt</strong>';
                html += '<select id="tix-sp-bulk-action" style="padding:5px 8px;border:1px solid #fcd34d;border-radius:6px;font-size:12px;background:#fff;">';
                html += '<option value="">– Aktion wählen –</option>';
                html += '<option value="tix_resolved">Als gelöst markieren</option>';
                html += '<option value="tix_progress">In Bearbeitung</option>';
                html += '<option value="tix_open">Wieder öffnen</option>';
                html += '<option value="tix_closed">Schließen</option>';
                html += '<option value="delete">Permanent löschen</option>';
                html += '</select>';
                html += '<button id="tix-sp-bulk-apply" class="button button-primary" style="font-size:12px;">Anwenden</button>';
                html += '<button id="tix-sp-bulk-clear" style="margin-left:auto;background:transparent;border:none;color:#92400e;cursor:pointer;font-size:12px;text-decoration:underline;">Auswahl aufheben</button>';
                html += '</div>';

                // ── Select-All Header ──
                html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;font-size:11px;color:#9ca3af;border-bottom:1px solid #f3f4f6;">';
                html += '<input type="checkbox" id="tix-sp-select-all" style="margin:0;">';
                html += '<label for="tix-sp-select-all" style="cursor:pointer;">Alle auswählen</label>';
                html += '</div>';

                d.tickets.forEach(function(t) {
                    var prioClass = t.priority !== 'normal' ? ' tix-sp-priority-' + t.priority : '';
                    html += '<div class="tix-sp-ticket-row' + prioClass + '" data-id="' + t.id + '" style="position:relative;">';
                    html += '<input type="checkbox" class="tix-sp-bulk-cb" data-id="' + t.id + '" style="margin-right:6px;flex-shrink:0;" onclick="event.stopPropagation();">';
                    html += '<span class="tix-sp-ticket-date">' + esc(t.date_formatted) + '</span>';
                    html += '<span class="tix-sp-ticket-status" style="background:' + esc(t.status_color) + '">' + esc(t.status_label) + '</span>';
                    html += '<span class="tix-sp-ticket-subject">' + esc(t.subject);
                    if (t.ai_summary) {
                        html += '<div style="font-size:11px;color:#64748b;font-style:italic;margin-top:2px;font-weight:400;line-height:1.4;">✨ ' + esc(t.ai_summary) + '</div>';
                    }
                    html += '</span>';
                    html += '<span class="tix-sp-ticket-customer">' + esc(t.name || t.email) + '</span>';
                    html += '<span class="tix-sp-ticket-category">' + esc(t.category_label) + '</span>';
                    html += '</div>';
                });

                // Pagination
                if (d.pages > 1) {
                    html += '<div class="tix-sp-pagination">';
                    for (var i = 1; i <= d.pages; i++) {
                        html += '<button data-page="' + i + '" class="' + (i === d.page ? 'active' : '') + '">' + i + '</button>';
                    }
                    html += '</div>';
                }

                listEl.html(html);
            });
        }

        // Filter-Events
        $('#tix-sp-filter-status, #tix-sp-filter-category').on('change', function() {
            currentPage = 1;
            loadTicketList();
        });

        var searchTimer;
        $('#tix-sp-filter-search').on('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() { currentPage = 1; loadTicketList(); }, 400);
        });

        // Pagination
        $(document).on('click', '.tix-sp-pagination button', function() {
            currentPage = parseInt($(this).data('page'));
            loadTicketList();
        });

        // ── Anfrage öffnen ──
        $(document).on('click', '.tix-sp-ticket-row', function(e) {
            // Klick auf Checkbox darf nicht das Ticket öffnen
            if (e.target && e.target.classList && e.target.classList.contains('tix-sp-bulk-cb')) return;
            var id = $(this).data('id');
            loadTicketDetail(id);
        });

        // ── Bulk-Aktionen ──
        function updateBulkToolbar() {
            var selected = $('.tix-sp-bulk-cb:checked').length;
            var bar = $('#tix-sp-bulk-toolbar');
            if (selected > 0) {
                bar.css('display', 'flex');
                $('#tix-sp-bulk-count').text(selected + ' ausgewählt');
            } else {
                bar.hide();
            }
        }

        $(document).on('change', '.tix-sp-bulk-cb', updateBulkToolbar);

        $(document).on('change', '#tix-sp-select-all', function() {
            $('.tix-sp-bulk-cb').prop('checked', this.checked);
            updateBulkToolbar();
        });

        $(document).on('click', '#tix-sp-bulk-clear', function() {
            $('.tix-sp-bulk-cb, #tix-sp-select-all').prop('checked', false);
            updateBulkToolbar();
        });

        $(document).on('click', '#tix-sp-bulk-apply', function() {
            var action = $('#tix-sp-bulk-action').val();
            var ids = $('.tix-sp-bulk-cb:checked').map(function(){ return parseInt($(this).data('id')); }).get();
            if (!action || !ids.length) {
                alert('Bitte Aktion wählen und mindestens eine Anfrage auswählen.');
                return;
            }
            var label = $('#tix-sp-bulk-action option:selected').text();
            if (!confirm('"' + label + '" auf ' + ids.length + ' Anfrage' + (ids.length === 1 ? '' : 'n') + ' anwenden?')) return;

            var btn = $(this).prop('disabled', true).text('…');
            $.post(S.ajax, {
                action: 'tix_support_bulk', nonce: S.nonce,
                bulk_action: action, ids: ids,
            }, function(r) {
                btn.prop('disabled', false).text('Anwenden');
                if (r.success) {
                    loadTicketList();
                } else {
                    alert(r.data || 'Fehler');
                }
            });
        });

        function loadTicketDetail(id) {
            listEl.hide();
            detailEl.show().html('<div class="tix-sp-loading">Lade Anfrage…</div>');

            $.post(S.ajax, {
                action:    'tix_support_detail',
                nonce:     S.nonce,
                ticket_id: id,
            }, function(r) {
                if (!r.success) { detailEl.html('<div class="tix-sp-empty">Fehler: ' + esc(r.data) + '</div>'); return; }
                currentTicket = r.data;
                pendingAttachments = [];
                templatesCache = null; // pro Ticket neu laden (Kategorie kann anders sein)
                renderTicketDetail(r.data);

                // Draft laden
                $.post(S.ajax, { action: 'tix_support_draft_get', nonce: S.nonce, ticket_id: id }, function(d) {
                    if (d.success && d.data.reply && d.data.reply.content) {
                        $('#tix-sp-reply-text').val(d.data.reply.content);
                        $('#tix-sp-draft-status').text('💾 Entwurf wiederhergestellt');
                        setTimeout(function(){ $('#tix-sp-draft-status').text(''); }, 3000);
                    }
                });
            });
        }

        function renderTicketDetail(t) {
            var statuses = S.statuses;
            var statusOpts = '';
            Object.keys(statuses).forEach(function(k) {
                statusOpts += '<option value="' + k + '"' + (k === t.status ? ' selected' : '') + '>' + statuses[k].label + '</option>';
            });

            var html = '<div class="tix-sp-detail-header">';
            html += '<button class="tix-sp-detail-back" id="tix-sp-back">← Zurück</button>';
            html += '<span class="tix-sp-detail-id">#' + t.id + '</span>';
            html += '<span class="tix-sp-detail-title">' + esc(t.subject) + '</span>';
            html += '<select class="tix-sp-detail-status-select" id="tix-sp-status-select">' + statusOpts + '</select>';
            html += '</div>';

            // KI-Summary (1 Satz, falls cached)
            if (t.ai_summary) {
                html += '<div class="tix-sp-ai-summary" style="margin:0 0 14px;padding:10px 14px;background:linear-gradient(90deg,#fef3c7,#fef9e7);border-left:3px solid #FF5500;border-radius:6px;font-size:13px;color:#374151;font-style:italic;">';
                html += '<span style="font-weight:600;color:#dc2626;font-style:normal;">✨ </span>' + esc(t.ai_summary);
                html += ' <button id="tix-sp-summary-refresh" title="Neu generieren" style="border:none;background:transparent;cursor:pointer;font-size:11px;color:#6b7280;margin-left:6px;">↻</button>';
                html += '</div>';
            } else {
                html += '<div class="tix-sp-ai-summary" style="margin:0 0 14px;text-align:right;">';
                html += '<button id="tix-sp-summary-refresh" style="background:transparent;border:1px solid #e5e7eb;color:#6b7280;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:11px;">✨ KI-Zusammenfassung generieren</button>';
                html += '</div>';
            }

            html += '<div class="tix-sp-detail-layout">';

            // ── Thread ──
            html += '<div class="tix-sp-detail-thread">';
            html += '<div class="tix-sp-messages" id="tix-sp-messages">';
            if (t.messages && t.messages.length) {
                t.messages.forEach(function(m) {
                    html += renderMessage(m);
                });
            }
            html += '</div>';

            // Reply Box
            html += '<div class="tix-sp-reply-box">';

            // ── Toolbar: Vorlagen + KI-Vorschlag + Upload ──
            html += '<div class="tix-sp-reply-toolbar" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:8px 10px;background:#f9fafb;border:1px solid #e5e7eb;border-bottom:none;border-radius:8px 8px 0 0;font-size:12px;">';
            html += '<button type="button" class="tix-sp-tb-btn" id="tix-sp-tb-templates" title="Antwort-Vorlage einfügen" style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:5px 9px;cursor:pointer;font-size:12px;">📋 Vorlagen</button>';
            html += '<button type="button" class="tix-sp-tb-btn" id="tix-sp-tb-ai" title="KI-Antwortvorschlag generieren" style="background:linear-gradient(90deg,#FF5500,#dc2626);color:#fff;border:1px solid #dc2626;border-radius:6px;padding:5px 9px;cursor:pointer;font-size:12px;font-weight:600;">✨ KI-Vorschlag</button>';
            html += '<label for="tix-sp-tb-upload" class="tix-sp-tb-btn" title="Datei anhängen" style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:5px 9px;cursor:pointer;font-size:12px;display:inline-flex;align-items:center;gap:4px;">📎 Anhang<input type="file" id="tix-sp-tb-upload" style="display:none;" accept="image/*,.pdf,.doc,.docx,.txt"></label>';
            html += '<span id="tix-sp-draft-status" style="margin-left:auto;font-size:11px;color:#9ca3af;"></span>';
            html += '</div>';

            html += '<textarea class="tix-sp-reply-textarea" id="tix-sp-reply-text" placeholder="Antwort oder interne Notiz schreiben… (Cmd+Enter zum Senden, /shortcut + Tab für Vorlagen)" style="border-radius:0 0 0 0;border-top:none;"></textarea>';

            // Anhang-Liste (befüllt nach Upload)
            html += '<div id="tix-sp-attached-files" style="display:none;padding:8px 12px;background:#f9fafb;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;font-size:12px;"></div>';

            // Tickets-Anhang Dropdown — nur wenn Bestellung verifiziert (order_id vorhanden)
            if (t.order_id && t.linked_tickets && t.linked_tickets.length) {
                html += '<div class="tix-sp-reply-attach" style="padding:10px 12px;background:#f9fafb;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">';
                html += '<label for="tix-sp-attach-order" style="display:block;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">🎫 Tickets anhängen (optional)</label>';
                html += '<select id="tix-sp-attach-order" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;background:#fff;">';
                html += '<option value="">Keine Tickets anhängen</option>';
                html += '<option value="' + t.order_id + '">Alle Tickets der Bestellung #' + t.order_id + ' (' + t.linked_tickets.length + ')</option>';
                html += '</select>';
                html += '<div style="font-size:11px;color:#9ca3af;margin-top:4px;">PDF-Tickets als Anhang, Online-Tickets als Download-Link in der Mail.</div>';
                html += '</div>';
            }

            html += '<div class="tix-sp-reply-actions">';
            html += '<button class="tix-sp-btn tix-sp-btn-accent" id="tix-sp-reply-btn">📩 Antworten</button>';
            html += '<button class="tix-sp-btn" id="tix-sp-note-btn">📌 Interne Notiz</button>';
            html += '<span style="margin-left:auto;font-size:11px;color:#9ca3af;">Cmd/Ctrl+Enter</span>';
            html += '</div></div>';
            html += '</div>';

            // ── Sidebar ──
            html += '<div class="tix-sp-sidebar">';

            // Kunden-Info
            html += '<div class="tix-sp-sidebar-card">';
            html += '<h4>Kunde</h4>';
            var crmHref = t.email ? (S.crmUrl + encodeURIComponent(t.email)) : '';
            var nameHtml = t.name ? esc(t.name) : '–';
            var emailHtml = esc(t.email || '');
            if (crmHref) {
                nameHtml  = '<a href="' + crmHref + '" target="_blank" title="Kundenansicht öffnen" style="color:#2563eb;text-decoration:none;font-weight:600;">' + nameHtml + '</a>';
                emailHtml = '<a href="' + crmHref + '" target="_blank" title="Kundenansicht öffnen" style="color:#2563eb;text-decoration:none;">' + emailHtml + '</a>';
            }
            html += '<div class="tix-sp-sidebar-row"><span class="tix-sp-sidebar-label">Name</span><span class="tix-sp-sidebar-value">' + nameHtml + '</span></div>';
            html += '<div class="tix-sp-sidebar-row"><span class="tix-sp-sidebar-label">E-Mail</span><span class="tix-sp-sidebar-value">' + emailHtml + '</span></div>';
            if (crmHref) {
                html += '<div class="tix-sp-sidebar-row" style="margin-top:6px;"><a href="' + crmHref + '" target="_blank" style="display:inline-flex;align-items:center;gap:4px;background:#eff6ff;color:#1d4ed8;padding:5px 10px;border-radius:6px;text-decoration:none;font-size:11px;font-weight:600;border:1px solid #bfdbfe;">👤 Kundenansicht öffnen →</a></div>';
            }
            html += '<div class="tix-sp-sidebar-row"><span class="tix-sp-sidebar-label">Kategorie</span><span class="tix-sp-sidebar-value">' + esc(t.category_label) + '</span></div>';
            if (t.order_id) {
                html += '<div class="tix-sp-sidebar-row"><span class="tix-sp-sidebar-label">Bestellung</span><span class="tix-sp-sidebar-value"><a href="' + (t.order && t.order.edit_url ? t.order.edit_url : '#') + '" target="_blank">#' + t.order_id + '</a></span></div>';
            }
            if (t.ticket_code) {
                html += '<div class="tix-sp-sidebar-row"><span class="tix-sp-sidebar-label">Ticket-Code</span><span class="tix-sp-sidebar-value">' + esc(t.ticket_code) + '</span></div>';
            }
            html += '</div>';

            // Quick Actions
            html += '<div class="tix-sp-sidebar-card">';
            html += '<h4>Aktionen</h4>';
            html += '<div class="tix-sp-quick-actions">';
            if (t.linked_ticket) {
                if (t.linked_ticket.download_url) {
                    html += '<a href="' + esc(t.linked_ticket.download_url) + '" target="_blank" rel="noopener" class="tix-sp-qa-btn" style="background:#dbeafe;color:#1d4ed8;">👁️ Online ansehen (Kunden-Sicht)</a>';
                }
                html += '<a href="' + esc(t.linked_ticket.edit_url) + '" target="_blank" class="tix-sp-qa-btn">🎫 Ticket-Backend öffnen</a>';
                if (t.linked_ticket.download_url) {
                    html += '<button class="tix-sp-copy-url" data-url="' + esc(t.linked_ticket.download_url) + '">📥 Download-Link kopieren</button>';
                }
                html += '<button class="tix-sp-qa-resend" data-ticket-id="' + t.linked_ticket.id + '" data-email="' + esc(t.email) + '">📧 Ticket-E-Mail erneut senden</button>';
                html += '<button class="tix-sp-qa-change-owner" data-ticket-id="' + t.linked_ticket.id + '">👤 Ticketinhaber ändern</button>';
            }
            if (t.order_id) {
                html += '<button onclick="window.open(\'' + (t.order && t.order.edit_url ? t.order.edit_url : 'post.php?post=' + t.order_id + '&action=edit') + '\', \'_blank\')">📋 Bestellung öffnen</button>';
            }
            html += '<button class="tix-sp-qa-resolve" data-status="tix_resolved">✓ Als gelöst markieren</button>';
            html += '</div>';

            // Change Owner Form (hidden)
            html += '<div id="tix-sp-change-owner-form" style="display:none;" class="tix-sp-inline-form">';
            html += '<input type="text" id="tix-sp-co-name" placeholder="Neuer Name">';
            html += '<input type="email" id="tix-sp-co-email" placeholder="Neue E-Mail">';
            html += '<button class="tix-sp-btn tix-sp-btn-sm tix-sp-btn-accent" id="tix-sp-co-submit">Speichern</button>';
            html += '</div>';

            html += '</div>';

            // Verknüpfte Tickets (alle aus der Bestellung)
            if (t.linked_tickets && t.linked_tickets.length) {
                html += '<div class="tix-sp-sidebar-card">';
                html += '<h4>Tickets der Bestellung (' + t.linked_tickets.length + ')</h4>';
                t.linked_tickets.forEach(function(lt) {
                    var code      = lt.code || ('#' + (lt.id || '?'));
                    var eventLine = lt.event ? esc(lt.event) : '';
                    var catLine   = lt.cat_name ? esc(lt.cat_name) : '';
                    var ownerLine = lt.owner ? esc(lt.owner) : (lt.email ? esc(lt.email) : '');
                    var seatLine  = lt.seat_label ? '🪑 ' + esc(lt.seat_label) : '';
                    var subLines  = [];
                    if (eventLine) subLines.push(eventLine);
                    if (catLine)   subLines.push(catLine);
                    if (ownerLine) subLines.push('👤 ' + ownerLine);
                    if (seatLine)  subLines.push(seatLine);

                    html += '<div class="tix-sp-linked-ticket" style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:10px 0;border-bottom:1px solid #f3f4f6;">';
                    html += '<div class="tix-sp-linked-ticket-info" style="min-width:0;flex:1;">';
                    html += '<div style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;font-weight:600;color:#0f172a;letter-spacing:0.02em;">' + esc(code) + '</div>';
                    if (subLines.length) {
                        html += '<div style="font-size:11px;color:#64748b;margin-top:3px;line-height:1.5;">' + subLines.join('<br>') + '</div>';
                    }
                    html += '</div>';
                    html += '<div class="tix-sp-linked-ticket-actions" style="display:flex;gap:4px;flex-shrink:0;">';
                    if (lt.download_url) {
                        // Kunden-Sicht direkt in neuem Tab — Ticket wird sofort angezeigt
                        html += '<a href="' + esc(lt.download_url) + '" target="_blank" rel="noopener" title="Ticket online ansehen (Kunden-Sicht)" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:#dbeafe;text-decoration:none;font-size:14px;">👁️</a>';
                    }
                    if (lt.edit_url) {
                        html += '<a href="' + esc(lt.edit_url) + '" target="_blank" title="Ticket-Backend öffnen" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:#f1f5f9;text-decoration:none;font-size:14px;">🎫</a>';
                    }
                    if (lt.download_url) {
                        html += '<button class="tix-sp-copy-url" data-url="' + esc(lt.download_url) + '" title="Online-Ticket-Link kopieren" style="border:none;background:#f1f5f9;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:13px;">🔗</button>';
                    }
                    html += '<button class="tix-sp-qa-resend" data-ticket-id="' + lt.id + '" data-email="' + esc(lt.email || t.email || '') + '" title="Ticket-Mail erneut senden" style="border:none;background:#f1f5f9;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:13px;">📧</button>';
                    html += '<button class="tix-sp-qa-change-owner" data-ticket-id="' + lt.id + '" title="Inhaber ändern" style="border:none;background:#f1f5f9;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:13px;">👤</button>';
                    html += '</div></div>';
                });
                html += '</div>';
            }

            html += '</div>'; // sidebar
            html += '</div>'; // layout

            detailEl.html(html);

            // Scroll to bottom
            var msgBox = document.getElementById('tix-sp-messages');
            if (msgBox) msgBox.scrollTop = msgBox.scrollHeight;
        }

        function renderMessage(m) {
            var cls = 'tix-sp-msg-' + m.type;
            var html = '<div class="tix-sp-msg ' + cls + '">';
            html += '<div class="tix-sp-msg-header">';
            html += '<span class="tix-sp-msg-author">' + esc(m.author) + '</span>';
            html += '<span class="tix-sp-msg-date">' + formatDate(m.date) + '</span>';
            html += '</div>';
            html += '<div class="tix-sp-msg-content">' + esc(m.content) + '</div>';
            // Anhänge der Nachricht
            if (m.attachments && m.attachments.length) {
                html += '<div class="tix-sp-msg-attachments" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;">';
                m.attachments.forEach(function(a) {
                    html += '<a href="' + esc(a.url) + '" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#334155;font-size:11px;">📎 ' + esc(a.name) + '</a>';
                });
                html += '</div>';
            }
            html += '</div>';
            return html;
        }

        // ── Zurück-Button ──
        $(document).on('click', '#tix-sp-back', function() {
            detailEl.hide();
            listEl.show();
            currentTicket = null;
        });

        // ── Status ändern ──
        $(document).on('change', '#tix-sp-status-select', function() {
            var newStatus = $(this).val();
            if (!currentTicket) return;
            $.post(S.ajax, {
                action:    'tix_support_status',
                nonce:     S.nonce,
                ticket_id: currentTicket.id,
                status:    newStatus,
            }, function(r) {
                if (r.success) {
                    currentTicket.status = newStatus;
                    currentTicket.status_label = r.data.status_label;
                }
            });
        });

        // ── Antworten ──
        $(document).on('click', '#tix-sp-reply-btn', function() {
            var content = $('#tix-sp-reply-text').val().trim();
            if (!content || !currentTicket) return;
            var btn = $(this);
            btn.prop('disabled', true).text('Wird gesendet…');

            var attachOrderId = $('#tix-sp-attach-order').val() || '';
            var fileUrls = pendingAttachments.map(function(a){ return a.url; });

            $.post(S.ajax, {
                action:           'tix_support_reply',
                nonce:            S.nonce,
                ticket_id:        currentTicket.id,
                content:          content,
                attach_order_id:  attachOrderId,
                attached_files:   pendingAttachments,
            }, function(r) {
                btn.prop('disabled', false).text('📩 Antworten');
                if (r.success) {
                    $('#tix-sp-reply-text').val('');
                    pendingAttachments = [];
                    $('#tix-sp-attached-files').hide().empty();
                    // Draft auch serverseitig löschen
                    $.post(S.ajax, { action: 'tix_support_draft_save', nonce: S.nonce, ticket_id: currentTicket.id, content: '', kind: 'reply' });
                    if (r.data.attach_message) {
                        // Kurzer Hinweis über Anhang-Status
                        var infoBox = $('<div class="tix-sp-attach-info" style="margin-top:8px;padding:8px 12px;background:#dcfce7;color:#14532d;border:1px solid #86efac;border-radius:6px;font-size:12px;"></div>').text('✓ ' + r.data.attach_message);
                        $('#tix-sp-attach-order').closest('.tix-sp-reply-attach').after(infoBox);
                        $('#tix-sp-attach-order').val('');
                        setTimeout(function() { infoBox.fadeOut(400, function() { $(this).remove(); }); }, 4000);
                    }
                    currentTicket.messages.push(r.data.message);
                    $('#tix-sp-messages').append(renderMessage(r.data.message));
                    if (r.data.attach_note) {
                        currentTicket.messages.push(r.data.attach_note);
                        $('#tix-sp-messages').append(renderMessage(r.data.attach_note));
                    }
                    var msgBox = document.getElementById('tix-sp-messages');
                    if (msgBox) msgBox.scrollTop = msgBox.scrollHeight;
                }
            });
        });

        // ── Interne Notiz ──
        $(document).on('click', '#tix-sp-note-btn', function() {
            var content = $('#tix-sp-reply-text').val().trim();
            if (!content || !currentTicket) return;
            var btn = $(this);
            btn.prop('disabled', true).text('Wird gespeichert…');

            $.post(S.ajax, {
                action:    'tix_support_note',
                nonce:     S.nonce,
                ticket_id: currentTicket.id,
                content:   content,
            }, function(r) {
                btn.prop('disabled', false).text('📌 Interne Notiz');
                if (r.success) {
                    $('#tix-sp-reply-text').val('');
                    currentTicket.messages.push(r.data.message);
                    $('#tix-sp-messages').append(renderMessage(r.data.message));
                    var msgBox = document.getElementById('tix-sp-messages');
                    if (msgBox) msgBox.scrollTop = msgBox.scrollHeight;
                }
            });
        });

        // ══════════════════════════════════════════════
        // NEU 1.38.126: Drafts (Auto-save), Templates, AI, Upload
        // ══════════════════════════════════════════════

        // Hochgeladene Dateien für nächste Antwort sammeln
        var pendingAttachments = [];
        var draftSaveTimer = null;

        // ── Auto-save Drafts (debounce 1.5s) ──
        $(document).on('input', '#tix-sp-reply-text', function() {
            if (!currentTicket) return;
            clearTimeout(draftSaveTimer);
            $('#tix-sp-draft-status').text('Speichere…');
            var content = $(this).val();
            draftSaveTimer = setTimeout(function() {
                $.post(S.ajax, {
                    action: 'tix_support_draft_save', nonce: S.nonce,
                    ticket_id: currentTicket.id, content: content, kind: 'reply',
                }, function(r) {
                    $('#tix-sp-draft-status').text(r.success ? '💾 gespeichert' : '');
                    setTimeout(function(){ $('#tix-sp-draft-status').text(''); }, 2000);
                });
            }, 1500);
        });

        // ── Cmd/Ctrl+Enter zum Senden ──
        $(document).on('keydown', '#tix-sp-reply-text', function(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                e.preventDefault();
                $('#tix-sp-reply-btn').click();
                return;
            }
            // Tab-Trigger für Template-Shortcuts (/spam<Tab>)
            if (e.key === 'Tab') {
                var ta = this;
                var v = ta.value;
                var caret = ta.selectionStart;
                var before = v.substring(0, caret);
                var match = before.match(/\/(\w+)$/);
                if (match) {
                    e.preventDefault();
                    expandShortcut(match[1], ta);
                }
            }
        });

        // ── Templates: Liste laden + einfügen ──
        var templatesCache = null;
        function loadTemplates(category, cb) {
            if (templatesCache) return cb(templatesCache);
            $.post(S.ajax, {
                action: 'tix_sp_templates_list', nonce: S.nonce, category: category || '',
            }, function(r) {
                if (r.success) {
                    templatesCache = r.data.templates || [];
                    cb(templatesCache);
                }
            });
        }

        function expandShortcut(shortcut, textarea) {
            loadTemplates(currentTicket && currentTicket.category, function(tpls) {
                var match = tpls.find(function(t){ return (t.shortcut || '').toLowerCase() === shortcut.toLowerCase(); });
                if (!match) return;
                var v = textarea.value;
                var caret = textarea.selectionStart;
                var before = v.substring(0, caret).replace(/\/\w+$/, '');
                var after = v.substring(caret);
                var rendered = renderTemplate(match.body);
                textarea.value = before + rendered + after;
                textarea.selectionStart = textarea.selectionEnd = before.length + rendered.length;
                $(textarea).trigger('input');
            });
        }

        function renderTemplate(body) {
            if (!currentTicket) return body;
            var first = (currentTicket.name || '').split(' ')[0] || 'zusammen';
            var last  = (currentTicket.name || '').split(' ').slice(1).join(' ') || '';
            var event = (currentTicket.linked_tickets && currentTicket.linked_tickets[0]) ? (currentTicket.linked_tickets[0].event || '') : '';
            return body
                .replace(/\{\{first_name\}\}/g, first)
                .replace(/\{\{last_name\}\}/g, last)
                .replace(/\{\{email\}\}/g, currentTicket.email || '')
                .replace(/\{\{ticket_id\}\}/g, currentTicket.id || '')
                .replace(/\{\{order_id\}\}/g, currentTicket.order_id || '')
                .replace(/\{\{event_name\}\}/g, event)
                .replace(/\{\{ticket_code\}\}/g, currentTicket.ticket_code || '');
        }

        $(document).on('click', '#tix-sp-tb-templates', function() {
            var btn = $(this);
            loadTemplates(currentTicket && currentTicket.category, function(tpls) {
                $('.tix-sp-tpl-popup').remove();
                var pop = $('<div class="tix-sp-tpl-popup" style="position:absolute;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.12);padding:8px;max-width:380px;max-height:400px;overflow-y:auto;z-index:9999;"></div>');
                if (!tpls || !tpls.length) {
                    pop.append('<div style="padding:14px;color:#6b7280;font-size:13px;text-align:center;">Keine Vorlagen vorhanden. <a href="' + S.adminUrl + 'admin.php?page=tix-support-templates" target="_blank">Erstellen →</a></div>');
                } else {
                    tpls.forEach(function(t) {
                        var item = $('<div class="tix-sp-tpl-item" style="padding:8px 10px;border-radius:6px;cursor:pointer;font-size:13px;line-height:1.4;"></div>');
                        var preview = (t.body || '').substring(0, 80).replace(/\n/g, ' ') + (t.body.length > 80 ? '…' : '');
                        item.html('<div style="font-weight:600;color:#0f172a;">' + esc(t.title) + (t.shortcut ? ' <code style="background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:3px;font-size:10px;">/' + esc(t.shortcut) + '</code>' : '') + '</div><div style="color:#64748b;font-size:11px;margin-top:2px;">' + esc(preview) + '</div>');
                        item.on('mouseenter', function(){ $(this).css('background', '#f3f4f6'); });
                        item.on('mouseleave', function(){ $(this).css('background', ''); });
                        item.on('click', function() {
                            var ta = document.getElementById('tix-sp-reply-text');
                            var rendered = renderTemplate(t.body);
                            if (ta.value && ta.value.trim()) {
                                ta.value = ta.value.replace(/\s+$/, '') + '\n\n' + rendered;
                            } else {
                                ta.value = rendered;
                            }
                            $(ta).trigger('input').focus();
                            ta.selectionStart = ta.selectionEnd = ta.value.length;
                            pop.remove();
                        });
                        pop.append(item);
                    });
                    pop.append('<div style="padding:8px 10px;border-top:1px solid #e5e7eb;margin-top:6px;text-align:center;"><a href="' + S.adminUrl + 'admin.php?page=tix-support-templates" target="_blank" style="font-size:11px;color:#6b7280;text-decoration:none;">⚙️ Vorlagen verwalten</a></div>');
                }
                var off = btn.offset();
                pop.css({ top: off.top + btn.outerHeight() + 4, left: off.left });
                $('body').append(pop);
                setTimeout(function() {
                    $(document).one('click', function() { pop.remove(); });
                }, 0);
            });
        });

        // ── KI-Antwortvorschlag ──
        $(document).on('click', '#tix-sp-tb-ai', function() {
            if (!currentTicket) return;
            var btn = $(this);
            var orig = btn.html();
            btn.prop('disabled', true).html('⏳ KI denkt nach…');
            $.post(S.ajax, {
                action: 'tix_support_ai_reply', nonce: S.nonce, ticket_id: currentTicket.id,
            }, function(r) {
                btn.prop('disabled', false).html(orig);
                if (r.success && r.data.reply) {
                    var ta = document.getElementById('tix-sp-reply-text');
                    if (ta.value && ta.value.trim() && !confirm('Vorhandenen Text mit KI-Vorschlag ersetzen?')) return;
                    ta.value = r.data.reply;
                    $(ta).trigger('input').focus();
                    ta.selectionStart = ta.selectionEnd = ta.value.length;
                } else {
                    alert(r.data || r.data?.message || 'KI-Antwort fehlgeschlagen.');
                }
            }).fail(function(xhr) {
                btn.prop('disabled', false).html(orig);
                alert('Fehler: ' + (xhr.responseJSON?.data || 'Netzwerkfehler'));
            });
        });

        // ── KI-Summary refresh ──
        $(document).on('click', '#tix-sp-summary-refresh', function() {
            if (!currentTicket) return;
            var btn = $(this);
            btn.prop('disabled', true);
            $.post(S.ajax, {
                action: 'tix_support_ai_summary', nonce: S.nonce,
                ticket_id: currentTicket.id, force: 1,
            }, function(r) {
                btn.prop('disabled', false);
                if (r.success && r.data.summary) {
                    currentTicket.ai_summary = r.data.summary;
                    var box = $('.tix-sp-ai-summary');
                    box.html('<span style="font-weight:600;color:#dc2626;font-style:normal;">✨ </span>' + esc(r.data.summary) + ' <button id="tix-sp-summary-refresh" title="Neu generieren" style="border:none;background:transparent;cursor:pointer;font-size:11px;color:#6b7280;margin-left:6px;">↻</button>');
                    box.attr('style', 'margin:0 0 14px;padding:10px 14px;background:linear-gradient(90deg,#fef3c7,#fef9e7);border-left:3px solid #FF5500;border-radius:6px;font-size:13px;color:#374151;font-style:italic;');
                } else {
                    alert(r.data || 'KI-Zusammenfassung fehlgeschlagen.');
                }
            });
        });

        // ── Datei-Upload (Admin) ──
        $(document).on('change', '#tix-sp-tb-upload', function() {
            var file = this.files[0];
            if (!file || !currentTicket) return;
            var fd = new FormData();
            fd.append('action', 'tix_support_upload');
            fd.append('nonce', S.nonce);
            fd.append('ticket_id', currentTicket.id);
            fd.append('file', file);

            var listBox = $('#tix-sp-attached-files').show();
            var row = $('<div style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:12px;"><span>📎 ' + esc(file.name) + '</span> <span class="status" style="color:#9ca3af;">…lädt</span></div>');
            listBox.append(row);

            $.ajax({
                url: S.ajax, type: 'POST', data: fd, processData: false, contentType: false,
                success: function(r) {
                    if (r.success) {
                        pendingAttachments.push(r.data);
                        row.find('.status').text('✓').css('color', '#16a34a');
                    } else {
                        row.find('.status').text('✗ ' + (r.data || 'Fehler')).css('color', '#dc2626');
                    }
                },
                error: function() {
                    row.find('.status').text('✗ Netzwerkfehler').css('color', '#dc2626');
                },
            });
            this.value = '';
        });

        // ── Quick Actions ──
        $(document).on('click', '.tix-sp-qa-resolve', function() {
            $('#tix-sp-status-select').val('tix_resolved').trigger('change');
        });

        $(document).on('click', '.tix-sp-qa-resend', function() {
            var btn = $(this);
            var ticketId = btn.data('ticket-id');
            var email    = btn.data('email');
            btn.prop('disabled', true).text('Wird gesendet…');

            $.post(S.ajax, {
                action:          'tix_support_resend_ticket',
                nonce:           S.nonce,
                ticket_post_id:  ticketId,
                email:           email,
            }, function(r) {
                btn.prop('disabled', false).text('📧 Ticket-E-Mail erneut senden');
                if (r.success) {
                    alert(r.data.message);
                }
            });
        });

        $(document).on('click', '.tix-sp-qa-change-owner', function() {
            $('#tix-sp-change-owner-form').toggle();
        });

        $(document).on('click', '#tix-sp-co-submit', function() {
            var ticketId = $('.tix-sp-qa-change-owner').data('ticket-id');
            var name  = $('#tix-sp-co-name').val().trim();
            var email = $('#tix-sp-co-email').val().trim();
            if (!name || !email) return;

            $.post(S.ajax, {
                action:          'tix_support_change_owner',
                nonce:           S.nonce,
                ticket_post_id:  ticketId,
                new_name:        name,
                new_email:       email,
            }, function(r) {
                if (r.success) {
                    alert(r.data.message);
                    $('#tix-sp-change-owner-form').hide();
                    loadTicketDetail(currentTicket.id);
                }
            });
        });

        // ── Neue Anfrage erstellen (Admin) ──
        $(document).on('click', '#tix-sp-create-btn', function() {
            var cats = S.categories;
            var catOpts = '';
            cats.forEach(function(c) {
                catOpts += '<option value="' + c.slug + '">' + c.label + '</option>';
            });

            var html = '<div class="tix-sp-modal-overlay" id="tix-sp-modal">';
            html += '<div class="tix-sp-modal">';
            html += '<h3>Neue Anfrage erstellen</h3>';
            html += '<div class="tix-sp-modal-field"><label>E-Mail *</label><input type="email" id="tix-sp-new-email"></div>';
            html += '<div class="tix-sp-modal-field"><label>Name</label><input type="text" id="tix-sp-new-name"></div>';
            html += '<div class="tix-sp-modal-field"><label>Kategorie</label><select id="tix-sp-new-category">' + catOpts + '</select></div>';
            html += '<div class="tix-sp-modal-field"><label>Bestellnr. (optional)</label><input type="text" id="tix-sp-new-order" placeholder="#12345"></div>';
            html += '<div class="tix-sp-modal-field"><label>Ticket-Code (optional)</label><input type="text" id="tix-sp-new-ticket-code" placeholder="12-stelliger Code"></div>';
            html += '<div class="tix-sp-modal-field"><label>Betreff *</label><input type="text" id="tix-sp-new-subject"></div>';
            html += '<div class="tix-sp-modal-field"><label>Nachricht *</label><textarea id="tix-sp-new-content"></textarea></div>';
            html += '<div class="tix-sp-modal-actions">';
            html += '<button class="tix-sp-btn" id="tix-sp-modal-cancel">Abbrechen</button>';
            html += '<button class="tix-sp-btn tix-sp-btn-accent" id="tix-sp-modal-submit">Erstellen</button>';
            html += '</div></div></div>';

            $('body').append(html);
        });

        $(document).on('click', '#tix-sp-modal-cancel, .tix-sp-modal-overlay', function(e) {
            if (e.target === this) $('#tix-sp-modal').remove();
        });

        $(document).on('click', '#tix-sp-modal-submit', function() {
            var data = {
                action:      'tix_support_create',
                nonce:       S.nonce,
                email:       $('#tix-sp-new-email').val(),
                name:        $('#tix-sp-new-name').val(),
                category:    $('#tix-sp-new-category').val(),
                order_id:    $('#tix-sp-new-order').val(),
                ticket_code: $('#tix-sp-new-ticket-code').val(),
                subject:     $('#tix-sp-new-subject').val(),
                content:     $('#tix-sp-new-content').val(),
            };

            $.post(S.ajax, data, function(r) {
                if (r.success) {
                    $('#tix-sp-modal').remove();
                    loadTicketDetail(r.data.ticket_id);
                } else {
                    alert(r.data || 'Fehler beim Erstellen.');
                }
            });
        });

        // ── Kunden-Suche ──
        $('#tix-sp-search-btn').on('click', doSearch);
        $('#tix-sp-search-input').on('keydown', function(e) {
            if (e.key === 'Enter') doSearch();
        });

        function doSearch() {
            var query = $('#tix-sp-search-input').val().trim();
            if (!query) return;
            var results = $('#tix-sp-search-results');
            results.html('<div class="tix-sp-loading">Suche…</div>');

            $.post(S.ajax, {
                action: 'tix_support_search',
                nonce:  S.nonce,
                query:  query,
            }, function(r) {
                if (!r.success) { results.html('<div class="tix-sp-empty">Keine Ergebnisse.</div>'); return; }
                renderSearchResults(r.data);
            });
        }

        function renderSearchResults(d) {
            var html = '';

            // Kunden-Card
            if (d.customer && d.customer.name) {
                var initials = d.customer.name.split(' ').map(function(n) { return n.charAt(0); }).join('').toUpperCase().substring(0, 2);
                html += '<div class="tix-sp-customer-card">';
                html += '<div class="tix-sp-customer-avatar">' + initials + '</div>';
                html += '<div class="tix-sp-customer-info">';
                html += '<h3>' + esc(d.customer.name) + '</h3>';
                html += '<p>' + esc(d.customer.email || '') + (d.customer.phone ? ' · ' + esc(d.customer.phone) : '') + '</p>';
                html += '</div></div>';
            }

            // Bestellungen
            if (d.orders.length) {
                html += '<div class="tix-sp-result-section"><h3>Bestellungen (' + d.orders.length + ')</h3>';
                d.orders.forEach(function(o) {
                    html += '<div class="tix-sp-result-card">';
                    html += '<div class="tix-sp-result-card-info">';
                    html += '<strong>#' + o.id + ' – ' + esc(o.name) + '</strong>';
                    html += '<span>' + esc(o.date) + ' · ' + o.total + ' ' + esc(o.currency) + ' · ' + esc(o.status) + '</span>';
                    html += '</div>';
                    html += '<div class="tix-sp-result-card-actions">';
                    html += '<a href="' + esc(o.edit_url) + '" target="_blank" class="tix-sp-btn tix-sp-btn-sm">Öffnen</a>';
                    html += '</div></div>';
                });
                html += '</div>';
            }

            // Tickets
            if (d.tickets.length) {
                html += '<div class="tix-sp-result-section"><h3>Tickets (' + d.tickets.length + ')</h3>';
                d.tickets.forEach(function(t) {
                    var seatInfo = t.seat_label ? ' · 🪑 ' + esc(t.seat_label) : '';
                    html += '<div class="tix-sp-result-card">';
                    html += '<div class="tix-sp-result-card-info">';
                    html += '<strong>' + esc(t.code) + '</strong>';
                    html += '<span>' + esc(t.event || '–') + ' · ' + esc(t.owner || '–') + ' · ' + esc(t.email || '') + seatInfo + '</span>';
                    html += '</div>';
                    html += '<div class="tix-sp-result-card-actions">';
                    if (t.download_url) {
                        html += '<a href="' + esc(t.download_url) + '" target="_blank" rel="noopener" class="tix-sp-btn tix-sp-btn-sm" style="background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe;">👁️ Online ansehen</a>';
                    }
                    if (t.edit_url) {
                        html += '<a href="' + esc(t.edit_url) + '" target="_blank" class="tix-sp-btn tix-sp-btn-sm">🎫 Backend</a>';
                    }
                    if (t.download_url) {
                        html += '<button class="tix-sp-btn tix-sp-btn-sm tix-sp-copy-url" data-url="' + esc(t.download_url) + '">📥 Link kopieren</button>';
                    }
                    html += '<button class="tix-sp-btn tix-sp-btn-sm tix-sp-search-resend" data-ticket-id="' + t.id + '" data-email="' + esc(t.email) + '">📧 Erneut senden</button>';
                    html += '</div></div>';
                });
                html += '</div>';
            }

            // Support-Anfragen
            if (d.support.length) {
                html += '<div class="tix-sp-result-section"><h3>Support-Anfragen (' + d.support.length + ')</h3>';
                d.support.forEach(function(s) {
                    html += '<div class="tix-sp-result-card tix-sp-ticket-row" data-id="' + s.id + '">';
                    html += '<span class="tix-sp-ticket-status" style="background:' + s.status_color + '">' + esc(s.status_label) + '</span>';
                    html += '<div class="tix-sp-result-card-info">';
                    html += '<strong>#' + s.id + ' – ' + esc(s.subject) + '</strong>';
                    html += '<span>' + esc(s.date_formatted) + ' · ' + esc(s.category_label) + '</span>';
                    html += '</div></div>';
                });
                html += '</div>';
            }

            if (!html) {
                html = '<div class="tix-sp-empty"><span class="dashicons dashicons-search"></span>Keine Ergebnisse für diese Suche.</div>';
            }

            $('#tix-sp-search-results').html(html);
        }

        // Resend from search
        $(document).on('click', '.tix-sp-search-resend', function(e) {
            e.stopPropagation();
            var btn = $(this);
            btn.prop('disabled', true).text('Wird gesendet…');
            $.post(S.ajax, {
                action:         'tix_support_resend_ticket',
                nonce:          S.nonce,
                ticket_post_id: btn.data('ticket-id'),
                email:          btn.data('email'),
            }, function(r) {
                btn.prop('disabled', false).text('📧 Erneut senden');
                if (r.success) alert(r.data.message);
                else alert(r.data || 'Fehler');
            });
        });

        // Download-Link kopieren
        $(document).on('click', '.tix-sp-copy-url', function(e) {
            e.stopPropagation();
            var btn = $(this);
            var url = btn.data('url');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function() {
                    btn.text('✓ Kopiert!');
                    setTimeout(function() { btn.text('📥 Link kopieren'); }, 2000);
                });
            } else {
                // Fallback
                var tmp = document.createElement('textarea');
                tmp.value = url;
                document.body.appendChild(tmp);
                tmp.select();
                document.execCommand('copy');
                document.body.removeChild(tmp);
                btn.text('✓ Kopiert!');
                setTimeout(function() { btn.text('📥 Link kopieren'); }, 2000);
            }
        });

        // ── Statistiken ──
        function loadStats() {
            $.post(S.ajax, {
                action: 'tix_support_list',
                nonce:  S.nonce,
                page:   1,
            }, function() {
                // Wir holen Stats separat via get_stats() – hier einfach direkt per AJAX die Zähler holen
            });

            // Stats direkt über die Liste berechnen – einfacher Ansatz: mehrere Status-Abfragen
            // Stattdessen machen wir einen dedizierten AJAX-Call
            // (Da wir keinen separaten Endpoint haben, nutzen wir die List-Action mit Filter)
            var statCounts = { open: 0, progress: 0, resolved_today: 0 };

            // Open
            $.post(S.ajax, { action: 'tix_support_list', nonce: S.nonce, status: 'tix_open', page: 1 }, function(r) {
                if (r.success) { statCounts.open = r.data.total; $('#tix-sp-stat-open').text(r.data.total); }
            });
            // Progress
            $.post(S.ajax, { action: 'tix_support_list', nonce: S.nonce, status: 'tix_progress', page: 1 }, function(r) {
                if (r.success) { statCounts.progress = r.data.total; $('#tix-sp-stat-progress').text(r.data.total); }
            });
            // Resolved (today) + Avg Time: über die Detail-Anfragen berechnen (vereinfacht)
            $.post(S.ajax, { action: 'tix_support_list', nonce: S.nonce, status: 'tix_resolved', page: 1 }, function(r) {
                if (r.success) {
                    // Für "heute gelöst" zählen wir Einträge vom heutigen Datum
                    var today = new Date().toISOString().slice(0, 10);
                    var todayCount = 0;
                    r.data.tickets.forEach(function(t) {
                        if (t.date && t.date.slice(0, 10) === today) todayCount++;
                    });
                    $('#tix-sp-stat-resolved').text(todayCount);
                    $('#tix-sp-stat-avg-time').text('–');
                }
            });
        }

        // ── Deep-Link: ?ticket=ID ──
        var urlParams = new URLSearchParams(window.location.search);
        var deepTicket = urlParams.get('ticket');
        if (deepTicket) {
            loadTicketDetail(parseInt(deepTicket));
        } else {
            // Initial load
            loadTicketList();
        }
    }

    // ══════════════════════════════════════════════
    // FRONTEND PORTAL
    // ══════════════════════════════════════════════

    if (isFront) {
        var authData = {
            email:      S.userEmail || '',
            name:       S.userName  || '',
            access_key: '',
            orders:     [],
        };

        // Session wiederherstellen
        try {
            var stored = sessionStorage.getItem('tix_sp_auth');
            if (stored) {
                var parsed = JSON.parse(stored);
                if (parsed.email && parsed.access_key) {
                    authData = parsed;
                    showSection('list');
                    loadCustomerList();
                }
            } else if (authData.email) {
                // Eingeloggt → automatisch authentifizieren (kein Formular nötig)
                autoLogin();
            }
        } catch(e) {}

        function autoLogin() {
            $.post(S.ajax, {
                action: 'tix_support_customer_auth',
                nonce:  S.nonce,
                email:  authData.email,
            }, function(r) {
                if (r.success) {
                    authData.access_key = r.data.access_key;
                    authData.name       = r.data.name || authData.name;
                    authData.orders     = r.data.orders || [];
                    try { sessionStorage.setItem('tix_sp_auth', JSON.stringify(authData)); } catch(e) {}
                    showSection('list');
                    loadCustomerList();
                } else {
                    // Fallback: Auth-Screen zeigen
                    showSection('auth');
                }
            });
        }

        function showSection(section) {
            $('#tix-sp-front-auth, #tix-sp-front-list, #tix-sp-front-create, #tix-sp-front-detail').hide();
            $('#tix-sp-front-' + section).show();
        }

        // ── Auth ──
        if (authData.email) {
            $('#tix-sp-front-email').val(authData.email);
        }

        $(document).on('click', '#tix-sp-front-auth-btn', function() {
            var email   = $('#tix-sp-front-email').val().trim();
            var orderId = $('#tix-sp-front-order').val().trim();
            var errEl   = $('#tix-sp-front-auth-error');
            errEl.hide();

            if (!email) { errEl.text('Bitte E-Mail-Adresse eingeben.').show(); return; }

            var btn = $(this);
            btn.prop('disabled', true).text('Wird geprüft…');

            $.post(S.ajax, {
                action:   'tix_support_customer_auth',
                nonce:    S.nonce,
                email:    email,
                order_id: orderId,
            }, function(r) {
                btn.prop('disabled', false).text('Anmelden');
                if (r.success) {
                    authData.email      = email;
                    authData.name       = r.data.name || '';
                    authData.access_key = r.data.access_key;
                    authData.orders     = r.data.orders || [];
                    try { sessionStorage.setItem('tix_sp_auth', JSON.stringify(authData)); } catch(e) {}
                    showSection('list');
                    loadCustomerList();
                } else {
                    errEl.text(r.data || 'Authentifizierung fehlgeschlagen.').show();
                }
            });
        });

        // ── Meine Anfragen laden ──
        function loadCustomerList() {
            var container = $('#tix-sp-front-tickets');
            container.html('<div class="tix-sp-front-empty">Lade Anfragen…</div>');

            $.post(S.ajax, {
                action:     'tix_support_customer_list',
                nonce:      S.nonce,
                email:      authData.email,
                access_key: authData.access_key,
            }, function(r) {
                if (!r.success) { container.html('<div class="tix-sp-front-empty">Fehler beim Laden.</div>'); return; }

                var tickets = r.data.tickets;
                if (!tickets.length) {
                    container.html('<div class="tix-sp-front-empty">Noch keine Anfragen vorhanden.</div>');
                    return;
                }

                var html = '';
                tickets.forEach(function(t) {
                    html += '<div class="tix-sp-front-ticket" data-id="' + t.id + '">';
                    html += '<div class="tix-sp-front-ticket-top">';
                    html += '<span class="tix-sp-front-ticket-subject">' + esc(t.subject) + '</span>';
                    html += '<span class="tix-sp-front-ticket-badge" style="background:' + t.status_color + '">' + esc(t.status_label) + '</span>';
                    html += '</div>';
                    if (t.last_message_preview) {
                        html += '<div class="tix-sp-front-ticket-preview">' + (t.last_message_type === 'admin' ? '← ' : '') + esc(t.last_message_preview) + '</div>';
                    }
                    html += '<div class="tix-sp-front-ticket-date">#' + t.id + ' · ' + esc(t.date_formatted) + '</div>';
                    html += '</div>';
                });

                container.html(html);
            });
        }

        // ── Anfrage öffnen (Frontend) ──
        $(document).on('click', '.tix-sp-front-ticket', function() {
            var id = $(this).data('id');
            loadCustomerDetail(id);
        });

        function loadCustomerDetail(id) {
            showSection('detail');
            var container = $('#tix-sp-front-detail-content');
            container.html('<div class="tix-sp-front-empty">Lade…</div>');

            $.post(S.ajax, {
                action:     'tix_support_customer_detail',
                nonce:      S.nonce,
                ticket_id:  id,
                email:      authData.email,
                access_key: authData.access_key,
            }, function(r) {
                if (!r.success) { container.html('<div class="tix-sp-front-empty">Fehler: ' + esc(r.data) + '</div>'); return; }
                renderCustomerDetail(r.data);
            });
        }

        function renderCustomerDetail(t) {
            var html = '<div class="tix-sp-front-detail-header">';
            html += '<h3>' + esc(t.subject) + '</h3>';
            html += '<div class="tix-sp-front-detail-meta">';
            html += '<span class="tix-sp-front-ticket-badge" style="background:' + t.status_color + '">' + esc(t.status_label) + '</span>';
            html += '<span>#' + t.id + ' · ' + esc(t.date_formatted) + '</span>';
            html += '</div></div>';

            // Messages
            html += '<div class="tix-sp-front-messages" id="tix-sp-front-messages">';
            if (t.messages && t.messages.length) {
                t.messages.forEach(function(m) {
                    var cls = 'tix-sp-front-msg-' + m.type;
                    html += '<div class="tix-sp-front-msg ' + cls + '">';
                    html += '<div class="tix-sp-front-msg-header">';
                    html += '<span class="tix-sp-front-msg-author">' + esc(m.author) + '</span>';
                    html += '<span class="tix-sp-front-msg-date">' + formatDate(m.date) + '</span>';
                    html += '</div>';
                    html += '<div class="tix-sp-front-msg-content">' + esc(m.content) + '</div>';
                    if (m.attachments && m.attachments.length) {
                        html += '<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;">';
                        m.attachments.forEach(function(a) {
                            html += '<a href="' + esc(a.url) + '" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#334155;font-size:11px;">📎 ' + esc(a.name) + '</a>';
                        });
                        html += '</div>';
                    }
                    html += '</div>';
                });
            }
            html += '</div>';

            // Reply (nur wenn nicht geschlossen)
            if (t.status !== 'tix_closed') {
                html += '<div class="tix-sp-front-reply">';
                html += '<textarea id="tix-sp-front-reply-text" placeholder="Deine Antwort…"></textarea>';
                html += '<div style="display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;">';
                html += '<label for="tix-sp-front-upload" style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:#fff;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:13px;color:#374151;">📎 Datei anhängen<input type="file" id="tix-sp-front-upload" data-ticket-id="' + t.id + '" style="display:none;" accept="image/*,.pdf,.doc,.docx,.txt"></label>';
                html += '<button class="tix-sp-front-btn" id="tix-sp-front-reply-btn" data-id="' + t.id + '" style="margin-left:auto;">Antworten</button>';
                html += '</div>';
                html += '<div id="tix-sp-front-attached" style="margin-top:8px;font-size:12px;color:#475569;"></div>';
                html += '</div>';
            }

            $('#tix-sp-front-detail-content').html(html);
        }

        // ── Customer-Portal: Datei-Upload ──
        var frontPendingAttachments = [];
        $(document).on('change', '#tix-sp-front-upload', function() {
            var file = this.files[0];
            if (!file) return;
            var ticketId = $(this).data('ticket-id');
            var fd = new FormData();
            fd.append('action', 'tix_support_upload');
            fd.append('nonce', S.nonce);
            fd.append('ticket_id', ticketId);
            fd.append('email', authData.email);
            fd.append('access_key', authData.access_key);
            fd.append('file', file);

            var listBox = $('#tix-sp-front-attached');
            var row = $('<div style="display:flex;align-items:center;gap:6px;padding:3px 0;"><span>📎 ' + esc(file.name) + '</span> <span class="status" style="color:#9ca3af;">…lädt</span></div>');
            listBox.append(row);

            $.ajax({
                url: S.ajax, type: 'POST', data: fd, processData: false, contentType: false,
                success: function(r) {
                    if (r.success) {
                        frontPendingAttachments.push(r.data);
                        row.find('.status').text('✓').css('color', '#16a34a');
                    } else {
                        row.find('.status').text('✗ ' + (r.data || 'Fehler')).css('color', '#dc2626');
                    }
                },
                error: function() {
                    row.find('.status').text('✗ Netzwerkfehler').css('color', '#dc2626');
                },
            });
            this.value = '';
        });

        // ── Zurück ──
        $(document).on('click', '#tix-sp-front-back-btn', function() {
            showSection('list');
            loadCustomerList();
        });

        // ── Kunden-Antwort senden ──
        $(document).on('click', '#tix-sp-front-reply-btn', function() {
            var content = $('#tix-sp-front-reply-text').val().trim();
            if (!content) return;
            var btn = $(this);
            var ticketId = btn.data('id');
            btn.prop('disabled', true).text('Wird gesendet…');

            $.post(S.ajax, {
                action:         'tix_support_customer_reply',
                nonce:          S.nonce,
                ticket_id:      ticketId,
                email:          authData.email,
                access_key:     authData.access_key,
                content:        content,
                attached_files: frontPendingAttachments,
            }, function(r) {
                btn.prop('disabled', false).text('Antworten');
                if (r.success) {
                    $('#tix-sp-front-reply-text').val('');
                    $('#tix-sp-front-attached').empty();
                    frontPendingAttachments = [];
                    var m = r.data.message;
                    var cls = 'tix-sp-front-msg-' + m.type;
                    var msgHtml = '<div class="tix-sp-front-msg ' + cls + '">';
                    msgHtml += '<div class="tix-sp-front-msg-header"><span class="tix-sp-front-msg-author">' + esc(m.author) + '</span><span class="tix-sp-front-msg-date">' + formatDate(m.date) + '</span></div>';
                    msgHtml += '<div class="tix-sp-front-msg-content">' + esc(m.content) + '</div>';
                    if (m.attachments && m.attachments.length) {
                        msgHtml += '<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;">';
                        m.attachments.forEach(function(a) {
                            msgHtml += '<a href="' + esc(a.url) + '" target="_blank" style="display:inline-flex;align-items:center;gap:4px;padding:4px 9px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#334155;font-size:11px;">📎 ' + esc(a.name) + '</a>';
                        });
                        msgHtml += '</div>';
                    }
                    msgHtml += '</div>';
                    $('#tix-sp-front-messages').append(msgHtml);
                }
            });
        });

        // ── Neue Anfrage ──
        $(document).on('click', '#tix-sp-front-new-btn', function() {
            showSection('create');
            // Bestellungen als Dropdown anzeigen wenn vorhanden
            var orderEl = $('#tix-sp-front-order-wrap');
            if (authData.orders && authData.orders.length) {
                var selHtml = '<select id="tix-sp-front-order-select" class="tix-sp-front-input">';
                selHtml += '<option value="">— Keine Bestellung —</option>';
                authData.orders.forEach(function(o) {
                    selHtml += '<option value="' + o.id + '">#' + o.id + ' · ' + esc(o.date) + ' · ' + o.total + ' € · ' + esc(o.status) + '</option>';
                });
                selHtml += '</select>';
                orderEl.html(selHtml);
            } else {
                orderEl.html('<input type="text" id="tix-sp-front-order-input" class="tix-sp-front-input" placeholder="Bestellnummer (optional, z.B. #12345)">');
            }
        });
        $(document).on('click', '#tix-sp-front-create-cancel', function() {
            showSection('list');
        });

        $(document).on('click', '#tix-sp-front-create-submit', function() {
            var subject  = $('#tix-sp-front-subject').val().trim();
            var content  = $('#tix-sp-front-message').val().trim();
            var category = $('#tix-sp-front-category').val();
            var ticketCode = $('#tix-sp-front-ticket-code').val().trim();
            var orderId  = $('#tix-sp-front-order-select').val() || $('#tix-sp-front-order-input').val() || '';
            var errEl = $('#tix-sp-front-create-error');
            errEl.hide();

            if (!subject || !content) {
                errEl.text('Betreff und Nachricht sind Pflichtfelder.').show();
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('Wird gesendet…');

            $.post(S.ajax, {
                action:      'tix_support_create',
                nonce:       S.nonce,
                email:       authData.email,
                name:        authData.name,
                subject:     subject,
                category:    category,
                content:     content,
                ticket_code: ticketCode,
                order_id:    orderId,
            }, function(r) {
                btn.prop('disabled', false).text('Anfrage absenden');
                if (r.success) {
                    // Access key für neues Ticket speichern
                    if (r.data.access_key && !authData.access_key) {
                        authData.access_key = r.data.access_key;
                        try { sessionStorage.setItem('tix_sp_auth', JSON.stringify(authData)); } catch(e) {}
                    }
                    showSection('list');
                    loadCustomerList();
                    // Erfolgsmeldung
                    var successHtml = '<div class="tix-sp-front-success">Deine Anfrage wurde erfolgreich gesendet. Du erhältst eine Bestätigung per E-Mail.</div>';
                    $('#tix-sp-front-tickets').before(successHtml);
                    setTimeout(function() { $('.tix-sp-front-success').fadeOut(300, function() { $(this).remove(); }); }, 5000);
                } else {
                    errEl.text(r.data || 'Fehler beim Erstellen.').show();
                }
            });
        });
    }

    // ══════════════════════════════════════════════
    // CHAT WIDGET
    // ══════════════════════════════════════════════

    if (S.isChatWidget) {
        var chatAuth = {
            email:      S.userEmail || '',
            name:       S.userName  || '',
            access_key: '',
            orders:     [],
        };

        // Session wiederherstellen
        try {
            var stored = sessionStorage.getItem('tix_sp_auth');
            if (stored) {
                var parsed = JSON.parse(stored);
                if (parsed.email && parsed.access_key) {
                    chatAuth = parsed;
                }
            }
        } catch(e) {}

        // Toggle Chat Panel
        $(document).on('click', '#tix-sp-chat-toggle', function() {
            var panel = $('#tix-sp-chat-panel');
            var isOpen = panel.is(':visible');
            if (isOpen) {
                panel.slideUp(200);
                $(this).find('.tix-sp-chat-btn-icon').show();
                $(this).find('.tix-sp-chat-btn-close').hide();
            } else {
                panel.slideDown(200);
                $(this).find('.tix-sp-chat-btn-icon').hide();
                $(this).find('.tix-sp-chat-btn-close').show();
                // Auto-Login oder Auth-Screen
                if (chatAuth.access_key) {
                    chatShowSection('list');
                    chatLoadList();
                } else if (chatAuth.email) {
                    chatAutoLogin();
                } else {
                    chatShowSection('auth');
                }
            }
        });

        $(document).on('click', '#tix-sp-chat-close', function() {
            $('#tix-sp-chat-panel').slideUp(200);
            $('#tix-sp-chat-toggle .tix-sp-chat-btn-icon').show();
            $('#tix-sp-chat-toggle .tix-sp-chat-btn-close').hide();
        });

        function chatShowSection(section) {
            $('#tix-sp-chat-auth, #tix-sp-chat-list, #tix-sp-chat-create, #tix-sp-chat-detail').hide();
            $('#tix-sp-chat-' + section).show();
        }

        function chatAutoLogin() {
            $.post(S.ajax, {
                action: 'tix_support_customer_auth',
                nonce:  S.nonce,
                email:  chatAuth.email,
            }, function(r) {
                if (r.success) {
                    chatAuth.access_key = r.data.access_key;
                    chatAuth.name       = r.data.name || chatAuth.name;
                    chatAuth.orders     = r.data.orders || [];
                    try { sessionStorage.setItem('tix_sp_auth', JSON.stringify(chatAuth)); } catch(e) {}
                    chatShowSection('list');
                    chatLoadList();
                } else {
                    chatShowSection('auth');
                }
            });
        }

        // Chat Auth
        if (chatAuth.email) {
            $('#tix-sp-chat-email').val(chatAuth.email);
        }

        $(document).on('click', '#tix-sp-chat-auth-btn', function() {
            var email   = $('#tix-sp-chat-email').val().trim();
            var orderId = $('#tix-sp-chat-order').val().trim();
            var errEl   = $('#tix-sp-chat-auth-error');
            errEl.hide();
            if (!email) { errEl.text('Bitte E-Mail eingeben.').show(); return; }

            var btn = $(this);
            btn.prop('disabled', true).text('Wird geprüft…');

            $.post(S.ajax, {
                action: 'tix_support_customer_auth', nonce: S.nonce,
                email: email, order_id: orderId,
            }, function(r) {
                btn.prop('disabled', false).text('Anmelden');
                if (r.success) {
                    chatAuth.email = email;
                    chatAuth.name = r.data.name || '';
                    chatAuth.access_key = r.data.access_key;
                    chatAuth.orders = r.data.orders || [];
                    try { sessionStorage.setItem('tix_sp_auth', JSON.stringify(chatAuth)); } catch(e) {}
                    chatShowSection('list');
                    chatLoadList();
                } else {
                    errEl.text(r.data || 'Fehler').show();
                }
            });
        });

        // Chat: Liste laden
        function chatLoadList() {
            var container = $('#tix-sp-chat-tickets');
            container.html('<div class="tix-sp-front-empty" style="font-size:13px;">Lade…</div>');

            $.post(S.ajax, {
                action: 'tix_support_customer_list', nonce: S.nonce,
                email: chatAuth.email, access_key: chatAuth.access_key,
            }, function(r) {
                if (!r.success) { container.html('<div class="tix-sp-front-empty" style="font-size:13px;">Fehler.</div>'); return; }
                var tickets = r.data.tickets;
                if (!tickets.length) {
                    container.html('<div class="tix-sp-front-empty" style="font-size:13px;">Noch keine Anfragen.</div>');
                    return;
                }
                var html = '';
                tickets.forEach(function(t) {
                    html += '<div class="tix-sp-chat-ticket-item" data-id="' + t.id + '">';
                    html += '<div style="display:flex;justify-content:space-between;align-items:center;">';
                    html += '<span style="font-weight:600;font-size:13px;">' + esc(t.subject) + '</span>';
                    html += '<span class="tix-sp-front-ticket-badge" style="background:' + t.status_color + ';font-size:10px;padding:2px 6px;">' + esc(t.status_label) + '</span>';
                    html += '</div>';
                    if (t.last_message_preview) {
                        html += '<div style="font-size:12px;color:#64748b;margin-top:2px;">' + esc(t.last_message_preview) + '</div>';
                    }
                    html += '</div>';
                });
                container.html(html);
            });
        }

        // Chat: Ticket öffnen
        $(document).on('click', '.tix-sp-chat-ticket-item', function() {
            chatLoadDetail($(this).data('id'));
        });

        function chatLoadDetail(id) {
            chatShowSection('detail');
            var container = $('#tix-sp-chat-detail-content');
            container.html('<div class="tix-sp-front-empty" style="font-size:13px;">Lade…</div>');

            $.post(S.ajax, {
                action: 'tix_support_customer_detail', nonce: S.nonce,
                ticket_id: id, email: chatAuth.email, access_key: chatAuth.access_key,
            }, function(r) {
                if (!r.success) { container.html('<div style="font-size:13px;">Fehler.</div>'); return; }
                chatRenderDetail(r.data);
            });
        }

        function chatRenderDetail(t) {
            var html = '<div style="margin-bottom:8px;">';
            html += '<strong style="font-size:14px;">' + esc(t.subject) + '</strong>';
            html += ' <span class="tix-sp-front-ticket-badge" style="background:' + t.status_color + ';font-size:10px;padding:2px 6px;">' + esc(t.status_label) + '</span>';
            html += '</div>';

            html += '<div class="tix-sp-chat-messages" id="tix-sp-chat-messages">';
            if (t.messages && t.messages.length) {
                t.messages.forEach(function(m) {
                    html += '<div class="tix-sp-chat-msg tix-sp-chat-msg-' + m.type + '">';
                    html += '<div style="font-size:11px;color:#94a3b8;margin-bottom:2px;">' + esc(m.author) + ' · ' + formatDate(m.date) + '</div>';
                    html += '<div style="font-size:13px;">' + esc(m.content) + '</div>';
                    html += '</div>';
                });
            }
            html += '</div>';

            if (t.status !== 'tix_closed') {
                html += '<div class="tix-sp-chat-reply">';
                html += '<textarea id="tix-sp-chat-reply-text" placeholder="Antwort…" rows="2" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;resize:none;"></textarea>';
                html += '<button class="tix-sp-front-btn" id="tix-sp-chat-reply-btn" data-id="' + t.id + '" style="margin-top:6px;font-size:12px;width:100%;">Antworten</button>';
                html += '</div>';
            }

            container.html(html);
            var msgBox = document.getElementById('tix-sp-chat-messages');
            if (msgBox) msgBox.scrollTop = msgBox.scrollHeight;
        }

        // Chat: Zurück
        $(document).on('click', '#tix-sp-chat-back-btn', function() {
            chatShowSection('list');
            chatLoadList();
        });

        // Chat: Antwort senden
        $(document).on('click', '#tix-sp-chat-reply-btn', function() {
            var content = $('#tix-sp-chat-reply-text').val().trim();
            if (!content) return;
            var btn = $(this);
            var ticketId = btn.data('id');
            btn.prop('disabled', true).text('Sende…');

            $.post(S.ajax, {
                action: 'tix_support_customer_reply', nonce: S.nonce,
                ticket_id: ticketId, email: chatAuth.email, access_key: chatAuth.access_key,
                content: content,
            }, function(r) {
                btn.prop('disabled', false).text('Antworten');
                if (r.success) {
                    $('#tix-sp-chat-reply-text').val('');
                    var m = r.data.message;
                    var msgHtml = '<div class="tix-sp-chat-msg tix-sp-chat-msg-' + m.type + '">';
                    msgHtml += '<div style="font-size:11px;color:#94a3b8;margin-bottom:2px;">' + esc(m.author) + ' · ' + formatDate(m.date) + '</div>';
                    msgHtml += '<div style="font-size:13px;">' + esc(m.content) + '</div></div>';
                    $('#tix-sp-chat-messages').append(msgHtml);
                    var msgBox = document.getElementById('tix-sp-chat-messages');
                    if (msgBox) msgBox.scrollTop = msgBox.scrollHeight;
                }
            });
        });

        // Chat: Neue Anfrage
        $(document).on('click', '#tix-sp-chat-new-btn', function() {
            chatShowSection('create');
            var orderEl = $('#tix-sp-chat-order-wrap');
            if (chatAuth.orders && chatAuth.orders.length) {
                var selHtml = '<select id="tix-sp-chat-order-select" class="tix-sp-front-input">';
                selHtml += '<option value="">— Keine Bestellung —</option>';
                chatAuth.orders.forEach(function(o) {
                    selHtml += '<option value="' + o.id + '">#' + o.id + ' · ' + esc(o.date) + '</option>';
                });
                selHtml += '</select>';
                orderEl.html(selHtml);
            }
        });

        $(document).on('click', '#tix-sp-chat-create-cancel', function() {
            chatShowSection('list');
        });

        $(document).on('click', '#tix-sp-chat-create-submit', function() {
            var subject = $('#tix-sp-chat-subject').val().trim();
            var content = $('#tix-sp-chat-message').val().trim();
            var category = $('#tix-sp-chat-category').val();
            var orderId = $('#tix-sp-chat-order-select').val() || $('#tix-sp-chat-order-input').val() || '';
            var errEl = $('#tix-sp-chat-create-error');
            errEl.hide();

            if (!subject || !content) { errEl.text('Betreff und Nachricht sind Pflichtfelder.').show(); return; }

            var btn = $(this);
            btn.prop('disabled', true).text('Sende…');

            $.post(S.ajax, {
                action: 'tix_support_create', nonce: S.nonce,
                email: chatAuth.email, name: chatAuth.name,
                subject: subject, category: category, content: content, order_id: orderId,
            }, function(r) {
                btn.prop('disabled', false).text('Absenden');
                if (r.success) {
                    if (r.data.access_key && !chatAuth.access_key) {
                        chatAuth.access_key = r.data.access_key;
                        try { sessionStorage.setItem('tix_sp_auth', JSON.stringify(chatAuth)); } catch(e) {}
                    }
                    chatShowSection('list');
                    chatLoadList();
                } else {
                    errEl.text(r.data || 'Fehler').show();
                }
            });
        });
    }

    // ══════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatDate(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        var pad = function(n) { return n < 10 ? '0' + n : n; };
        return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

})(jQuery);
