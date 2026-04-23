/**
 * Tixomat – Verkaufte Tickets Admin JS
 *
 * Modal für Ticket-Versand, Status-Toggle, Notices.
 *
 * @since 1.26.0
 */
(function($) {
    'use strict';

    if (typeof tixTickets === 'undefined') return;
    var T = tixTickets;

    // ══════════════════════════════════════════════
    // RESEND SINGLE TICKET
    // ══════════════════════════════════════════════

    $(document).on('click', '.tix-resend-ticket', function(e) {
        e.preventDefault();
        var ticketId = $(this).data('ticket-id');
        var email    = $(this).data('email') || '';

        showResendModal('Ticket erneut senden', email, function(newEmail) {
            $.post(T.ajax, {
                action:    'tix_ticket_resend',
                nonce:     T.nonce,
                ticket_id: ticketId,
                email:     newEmail
            }, function(r) {
                if (r.success) {
                    showNotice('success', r.data.message);
                } else {
                    showNotice('error', r.data || 'Fehler beim Senden.');
                }
            }).fail(function() {
                showNotice('error', 'Verbindungsfehler.');
            });
        });
    });

    // ══════════════════════════════════════════════
    // RESEND ALL TICKETS OF AN ORDER
    // ══════════════════════════════════════════════

    $(document).on('click', '.tix-resend-order', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var email   = $(this).data('email') || '';

        showResendModal('Alle Tickets der Bestellung #' + orderId + ' erneut senden', email, function(newEmail) {
            $.post(T.ajax, {
                action:   'tix_ticket_resend_order',
                nonce:    T.nonce,
                order_id: orderId,
                email:    newEmail
            }, function(r) {
                if (r.success) {
                    showNotice('success', r.data.message);
                } else {
                    showNotice('error', r.data || 'Fehler beim Senden.');
                }
            }).fail(function() {
                showNotice('error', 'Verbindungsfehler.');
            });
        });
    });

    // ══════════════════════════════════════════════
    // ADMIN CHECK-IN TOGGLE (synchron zum Scanner)
    // ══════════════════════════════════════════════

    $(document).on('click', '.tix-admin-checkin-toggle', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var ticketId = $btn.data('ticket-id');
        var state    = parseInt($btn.data('state'), 10) || 0;
        var action   = state ? 'Check-in zurücksetzen' : 'manuell einchecken';

        if (!confirm('Ticket wirklich ' + action + '?')) return;

        $btn.css('pointer-events', 'none').css('opacity', '.5');
        $.post(T.ajax, {
            action:    'tix_admin_checkin_toggle',
            nonce:     T.nonce,
            ticket_id: ticketId
        }, function(r) {
            $btn.css('pointer-events', '').css('opacity', '');
            if (r.success) {
                showNotice('success', r.data.message || 'OK');
                // Zeile visuell aktualisieren (ohne Reload)
                var $row = $btn.closest('tr');
                var checkedIn = !!r.data.checked_in;

                // Button-State
                $btn.data('state', checkedIn ? 1 : 0).attr('title', checkedIn ? 'Check-in zurücksetzen' : 'Manuell einchecken');
                var $icon = $btn.find('.dashicons');
                $icon.removeClass('dashicons-yes dashicons-tickets-alt')
                     .addClass(checkedIn ? 'dashicons-yes' : 'dashicons-tickets-alt')
                     .css({ 'font-size': checkedIn ? '18px' : '16px', 'width': checkedIn ? '18px' : '16px', 'height': checkedIn ? '18px' : '16px' });
                $btn.css('color', checkedIn ? '#10b981' : '#6b7280');

                // Status-Badge in der Status-Spalte anpassen
                var $badge = $row.find('[data-tix-checkin-badge]');
                if (checkedIn) {
                    if (!$badge.length) {
                        var $statusCell = $row.find('[data-tix-ticket-status]');
                        if ($statusCell.length) {
                            $statusCell.after('<br><span data-tix-checkin-badge="' + ticketId + '" style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#065f46;background:#ecfdf5;border:1px solid #a7f3d0;padding:2px 8px;border-radius:14px;margin-top:4px;white-space:nowrap;"><span class="dashicons dashicons-yes" style="font-size:13px;width:13px;height:13px;"></span>Eingecheckt</span>');
                        }
                    }
                } else {
                    if ($badge.length) {
                        $badge.prev('br').remove();
                        $badge.remove();
                    }
                }
            } else {
                showNotice('error', (r.data && r.data.message) || 'Fehler.');
            }
        }).fail(function() {
            $btn.css('pointer-events', '').css('opacity', '');
            showNotice('error', 'Verbindungsfehler.');
        });
    });

    // ══════════════════════════════════════════════
    // QUICK STATUS TOGGLE
    // ══════════════════════════════════════════════

    $(document).on('click', '.tix-toggle-status', function(e) {
        e.preventDefault();
        var ticketId  = $(this).data('ticket-id');
        var newStatus = $(this).data('new-status');
        var action    = newStatus === 'cancelled' ? 'stornieren' : 'reaktivieren';

        if (!confirm('Ticket wirklich ' + action + '?')) return;

        $.post(T.ajax, {
            action:     'tix_ticket_toggle_status',
            nonce:      T.nonce,
            ticket_id:  ticketId,
            new_status: newStatus
        }, function(r) {
            if (r.success) {
                location.reload();
            } else {
                showNotice('error', r.data || 'Fehler.');
            }
        }).fail(function() {
            showNotice('error', 'Verbindungsfehler.');
        });
    });

    // ══════════════════════════════════════════════
    // MODAL
    // ══════════════════════════════════════════════

    function showResendModal(title, defaultEmail, onConfirm) {
        // Bestehende Modals entfernen
        $('.tix-modal-overlay').remove();

        var html =
            '<div class="tix-modal-overlay">' +
                '<div class="tix-modal">' +
                    '<h3>' + esc(title) + '</h3>' +
                    '<label for="tix-resend-email">E-Mail-Adresse</label>' +
                    '<input type="email" id="tix-resend-email" value="' + esc(defaultEmail) + '" placeholder="email@example.com">' +
                    '<div class="tix-modal-actions">' +
                        '<button class="button tix-modal-cancel">Abbrechen</button>' +
                        '<button class="button button-primary tix-modal-confirm">Senden</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        var $modal = $(html).appendTo('body');

        // Focus + Select
        setTimeout(function() {
            $modal.find('#tix-resend-email').focus().select();
        }, 100);

        // Schliessen: Klick auf Overlay oder Abbrechen
        $modal.on('click', function(e) {
            if (e.target === this) $modal.remove();
        });
        $modal.on('click', '.tix-modal-cancel', function() {
            $modal.remove();
        });

        // Senden
        $modal.on('click', '.tix-modal-confirm', function() {
            var email = $modal.find('#tix-resend-email').val().trim();
            if (!email) {
                $modal.find('#tix-resend-email').css('border-color', '#ef4444').focus();
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true).text('Wird gesendet\u2026');
            onConfirm(email);
        });

        // Enter-Taste
        $modal.on('keypress', '#tix-resend-email', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $modal.find('.tix-modal-confirm').trigger('click');
            }
        });

        // Escape-Taste
        $(document).one('keydown', function(e) {
            if (e.which === 27) $modal.remove();
        });
    }

    // ══════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function showNotice(type, msg) {
        var cssClass = type === 'error' ? 'notice-error' : 'notice-success';
        var $notice = $(
            '<div class="notice tix-notice ' + cssClass + '">' +
                '<p><strong>Tixomat:</strong> ' + esc(msg) + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Ausblenden</span></button>' +
            '</div>'
        );
        // Modal entfernen falls noch offen
        $('.tix-modal-overlay').remove();
        $('body').append($notice);
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(200, function() { $(this).remove(); });
        });
        // Auto-dismiss nach 6 Sekunden
        setTimeout(function() {
            $notice.fadeOut(400, function() { $(this).remove(); });
        }, 6000);
    }

})(jQuery);
