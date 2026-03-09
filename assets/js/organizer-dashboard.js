/**
 * Tixomat – Veranstalter Dashboard (Frontend JS)
 *
 * Tab-Navigation, AJAX-Calls, Event-Karten, Gaesteliste, Stats.
 */
(function($) {
    'use strict';

    if (typeof tixOD === 'undefined') return;

    var $dash   = $('#tix-organizer-dashboard');
    var cache   = {};
    var orgId   = $dash.data('org-id');
    var salesChart = null;

    /* ══════════════════════════════════════════
     * Tab-Navigation
     * ══════════════════════════════════════════ */

    $dash.on('click', '.tix-od-tab', function() {
        var $btn = $(this);
        var tab  = $btn.data('tab');

        // Active state
        $dash.find('.tix-od-tab').removeClass('active').attr('aria-selected', 'false');
        $btn.addClass('active').attr('aria-selected', 'true');

        // Panel switch
        $dash.find('.tix-od-panel').removeClass('active');
        $dash.find('[data-tab="' + tab + '"]').filter('.tix-od-panel').addClass('active');

        // Load data (lazy)
        if (!cache[tab]) {
            cache[tab] = true;
            loadTab(tab);
        }
    });

    /* ══════════════════════════════════════════
     * Tab-Daten laden
     * ══════════════════════════════════════════ */

    function loadTab(tab) {
        switch (tab) {
            case 'overview': loadOverview(); break;
            case 'events':   loadEvents();   break;
            case 'orders':   loadOrders();   break;
            case 'guestlist': break; // Wird per Event-Filter geladen
            case 'stats':    loadStats();    break;
        }
    }

    // Initial: Uebersicht laden
    loadOverview();
    cache.overview = true;

    /* ══════════════════════════════════════════
     * Uebersicht / KPIs
     * ══════════════════════════════════════════ */

    function loadOverview() {
        ajax('tix_od_overview', {}, function(data) {
            // KPIs befuellen
            $('#tix-od-kpi-events').text(data.kpis.events_total);
            $('#tix-od-kpi-tickets').text(data.kpis.tickets_sold);
            $('#tix-od-kpi-revenue').html(data.kpis.total_revenue);
            $('#tix-od-kpi-upcoming').text(data.kpis.upcoming);

            // Chart
            if (data.chart && data.chart.length > 0 && typeof Chart !== 'undefined') {
                var labels   = data.chart.map(function(d) { return d.label; });
                var tickets  = data.chart.map(function(d) { return d.tickets; });
                var revenue  = data.chart.map(function(d) { return d.revenue; });

                var ctx = document.getElementById('tix-od-chart-sales');
                if (ctx) {
                    if (salesChart) salesChart.destroy();
                    salesChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Tickets',
                                    data: tickets,
                                    backgroundColor: 'rgba(255,85,0,0.7)',
                                    borderRadius: 4,
                                    yAxisID: 'y',
                                },
                                {
                                    label: 'Umsatz (€)',
                                    data: revenue,
                                    type: 'line',
                                    borderColor: '#10b981',
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    fill: false,
                                    yAxisID: 'y1',
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { intersect: false, mode: 'index' },
                            plugins: { legend: { position: 'bottom' } },
                            scales: {
                                y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Tickets' } },
                                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '€' } },
                                x: { ticks: { maxTicksLimit: 15 } }
                            }
                        }
                    });
                }
            }
        });
    }

    /* ══════════════════════════════════════════
     * Events laden
     * ══════════════════════════════════════════ */

    function loadEvents() {
        var $list = $('#tix-od-events-list');
        $list.html('<div class="tix-od-loading"><div class="tix-od-spinner"></div></div>');

        ajax('tix_od_events', {}, function(data) {
            if (!data.events || data.events.length === 0) {
                $list.html('<div class="tix-od-empty">Noch keine Events vorhanden. Erstelle jetzt dein erstes Event!</div>');
                return;
            }

            var html = '';
            $.each(data.events, function(i, ev) {
                var statusClass = 'tix-od-badge-' + ev.status;
                if (ev.post_status === 'draft') statusClass = 'tix-od-badge-draft';
                if (ev.post_status === 'pending') statusClass = 'tix-od-badge-pending';

                var statusLabel = ev.status;
                if (ev.post_status === 'draft') statusLabel = 'Entwurf';
                else if (ev.post_status === 'pending') statusLabel = 'Ausstehend';
                else if (ev.status === 'available') statusLabel = 'Verf\u00fcgbar';
                else if (ev.status === 'sold_out') statusLabel = 'Ausverkauft';
                else if (ev.status === 'cancelled') statusLabel = 'Abgesagt';
                else if (ev.status === 'postponed') statusLabel = 'Verschoben';

                var thumb = ev.thumbnail
                    ? '<img src="' + ev.thumbnail + '" class="tix-od-event-thumb" alt="">'
                    : '<div class="tix-od-event-thumb-placeholder"><span class="dashicons dashicons-calendar-alt"></span></div>';

                html += '<div class="tix-od-event-card" data-event-id="' + ev.id + '">'
                    + thumb
                    + '<div class="tix-od-event-body">'
                    + '<h4 class="tix-od-event-title">' + escHtml(ev.title) + '</h4>'
                    + '<div class="tix-od-event-meta">'
                    + '<span><span class="dashicons dashicons-calendar-alt"></span> ' + (ev.date || '–') + (ev.time ? ' ' + ev.time : '') + '</span>'
                    + '<span class="tix-od-badge ' + statusClass + '">' + statusLabel + '</span>'
                    + '</div>'
                    + '<div class="tix-od-event-actions">'
                    + '<button type="button" class="tix-od-btn tix-od-btn-sm tix-od-btn-primary tix-od-edit-event" data-id="' + ev.id + '"><span class="dashicons dashicons-edit"></span> Bearbeiten</button>'
                    + '<button type="button" class="tix-od-btn tix-od-btn-sm tix-od-btn-secondary tix-od-dup-event" data-id="' + ev.id + '"><span class="dashicons dashicons-admin-page"></span> Duplizieren</button>'
                    + '<button type="button" class="tix-od-btn tix-od-btn-sm tix-od-btn-danger tix-od-del-event" data-id="' + ev.id + '"><span class="dashicons dashicons-trash"></span></button>'
                    + (ev.permalink ? '<a href="' + ev.permalink + '" target="_blank" class="tix-od-btn tix-od-btn-sm tix-od-btn-secondary"><span class="dashicons dashicons-external"></span></a>' : '')
                    + '</div>'
                    + '</div></div>';
            });

            $list.html(html);

            // Event-Dropdowns in Gaesteliste und Bestellungen befuellen
            populateEventDropdowns(data.events);
        });
    }

    /* ══════════════════════════════════════════
     * Bestellungen laden
     * ══════════════════════════════════════════ */

    function loadOrders(params) {
        params = params || {};
        var $tbody = $('#tix-od-orders-table tbody');
        $tbody.html('<tr><td colspan="7" class="tix-od-loading-cell"><div class="tix-od-spinner" style="margin:0 auto"></div></td></tr>');

        ajax('tix_od_orders', params, function(data) {
            if (!data.orders || data.orders.length === 0) {
                $tbody.html('<tr><td colspan="7" class="tix-od-loading-cell">Keine Bestellungen gefunden.</td></tr>');
                return;
            }

            var html = '';
            $.each(data.orders, function(i, o) {
                var badge = 'tix-od-badge-' + o.status_key;
                html += '<tr>'
                    + '<td>#' + o.order_id + '</td>'
                    + '<td>' + o.date + '</td>'
                    + '<td>' + escHtml(o.customer) + '</td>'
                    + '<td>' + escHtml(o.event) + '</td>'
                    + '<td>' + o.tickets + '</td>'
                    + '<td>' + o.total + '</td>'
                    + '<td><span class="tix-od-badge ' + badge + '">' + o.status + '</span></td>'
                    + '</tr>';
            });
            $tbody.html(html);
        });
    }

    // Filter-Button
    $dash.on('click', '#tix-od-orders-filter', function() {
        loadOrders({
            date_from: $('#tix-od-orders-from').val(),
            date_to: $('#tix-od-orders-to').val(),
            filter_event: $('#tix-od-orders-event').val(),
        });
    });

    /* ══════════════════════════════════════════
     * Gaesteliste
     * ══════════════════════════════════════════ */

    $dash.on('change', '#tix-od-gl-event', function() {
        var eventId = $(this).val();
        var $content = $('#tix-od-guestlist-content');

        if (!eventId) {
            $content.html('<div class="tix-od-empty">W\u00e4hle ein Event, um die G\u00e4steliste anzuzeigen.</div>');
            return;
        }

        $content.html('<div class="tix-od-loading"><div class="tix-od-spinner"></div></div>');

        ajax('tix_od_guestlist', { event_id: eventId }, function(data) {
            var html = '<div class="tix-od-gl-list">';

            // Manuelle Gaeste
            html += '<div class="tix-od-gl-section">';
            html += '<h4 class="tix-od-gl-section-title">Manuelle G\u00e4ste (' + data.manual.length + ')</h4>';
            if (data.manual.length === 0) {
                html += '<div class="tix-od-empty">Keine manuellen G\u00e4ste.</div>';
            } else {
                $.each(data.manual, function(i, g) {
                    html += renderGuestRow(g, eventId);
                });
            }
            html += '</div>';

            // Verkaufte Tickets
            html += '<div class="tix-od-gl-section">';
            html += '<h4 class="tix-od-gl-section-title">Ticket-K\u00e4ufer (' + data.sold.length + ')</h4>';
            if (data.sold.length === 0) {
                html += '<div class="tix-od-empty">Keine Ticket-K\u00e4ufer.</div>';
            } else {
                $.each(data.sold, function(i, g) {
                    html += renderGuestRow(g, eventId);
                });
            }
            html += '</div>';

            html += '</div>';
            $content.html(html);
        });
    });

    function renderGuestRow(g, eventId) {
        var checked = g.checked_in ? 'checked' : '';
        var dataAttrs = 'data-event-id="' + eventId + '" data-source="' + g.source + '"';
        if (g.source === 'manual') dataAttrs += ' data-index="' + g.index + '"';
        if (g.source === 'order') dataAttrs += ' data-order-id="' + g.order_id + '"';

        return '<div class="tix-od-gl-row">'
            + '<input type="checkbox" class="tix-od-gl-check tix-od-checkin-toggle" ' + checked + ' ' + dataAttrs + '>'
            + '<span class="tix-od-gl-name">' + escHtml(g.name) + '</span>'
            + '<span class="tix-od-gl-email">' + escHtml(g.email || '') + '</span>'
            + '<span class="tix-od-gl-qty">' + g.tickets + ' Tickets</span>'
            + '</div>';
    }

    // Check-In Toggle
    $dash.on('change', '.tix-od-checkin-toggle', function() {
        var $cb = $(this);
        ajax('tix_od_checkin', {
            event_id:   $cb.data('event-id'),
            source:     $cb.data('source'),
            index:      $cb.data('index') || 0,
            order_id:   $cb.data('order-id') || 0,
            checked_in: $cb.is(':checked') ? 1 : 0,
        });
    });

    /* ══════════════════════════════════════════
     * Statistiken
     * ══════════════════════════════════════════ */

    function loadStats(filterEvent) {
        var $content = $('#tix-od-stats-content');
        $content.html('<div class="tix-od-loading"><div class="tix-od-spinner"></div></div>');

        ajax('tix_od_stats', { filter_event: filterEvent || '' }, function(data) {
            var html = '<div class="tix-od-kpis">'
                + kpiCard('dashicons-tickets-alt', 'Tickets verkauft', data.stats.tickets_sold)
                + kpiCard('dashicons-chart-area', 'Umsatz', data.stats.total_revenue_fmt)
                + kpiCard('dashicons-performance', 'Auslastung', data.stats.utilization + '%')
                + kpiCard('dashicons-groups', 'Kapazit\u00e4t', data.stats.capacity)
                + '</div>';
            $content.html(html);

            // Event-Filter Dropdown befuellen
            if (data.events && data.events.length > 0) {
                var $sel = $('#tix-od-stats-event');
                if ($sel.children('option').length <= 1) {
                    $.each(data.events, function(i, ev) {
                        $sel.append('<option value="' + ev.id + '">' + escHtml(ev.title) + '</option>');
                    });
                }
            }
        });
    }

    function kpiCard(icon, label, value) {
        return '<div class="tix-od-kpi"><div class="tix-od-kpi-icon"><span class="dashicons ' + icon + '"></span></div>'
            + '<div class="tix-od-kpi-body"><span class="tix-od-kpi-label">' + label + '</span>'
            + '<span class="tix-od-kpi-value">' + value + '</span></div></div>';
    }

    $dash.on('change', '#tix-od-stats-event', function() {
        loadStats($(this).val());
    });

    /* ══════════════════════════════════════════
     * Event-Aktionen
     * ══════════════════════════════════════════ */

    // Neues Event
    $dash.on('click', '#tix-od-new-event', function() {
        // Placeholder: Event erstellen (wird in Phase 3 mit Editor ersetzt)
        ajax('tix_od_save_event', { title: 'Neues Event' }, function(data) {
            toast('Event erstellt! ID: ' + data.event_id, 'success');
            cache.events = false;
            loadEvents();
        });
    });

    // Event bearbeiten
    $dash.on('click', '.tix-od-edit-event', function() {
        var eventId = $(this).data('id');
        // Placeholder: wird in Phase 3 mit Editor-Modal ersetzt
        toast('Editor f\u00fcr Event #' + eventId + ' wird in Phase 3 implementiert.', 'success');
    });

    // Event duplizieren
    $dash.on('click', '.tix-od-dup-event', function() {
        var eventId = $(this).data('id');
        if (!confirm('Event duplizieren?')) return;
        ajax('tix_od_duplicate_event', { event_id: eventId }, function(data) {
            toast(data.message, 'success');
            cache.events = false;
            loadEvents();
        });
    });

    // Event loeschen
    $dash.on('click', '.tix-od-del-event', function() {
        var eventId = $(this).data('id');
        if (!confirm('Event wirklich l\u00f6schen? Es wird in den Papierkorb verschoben.')) return;
        ajax('tix_od_delete_event', { event_id: eventId }, function(data) {
            toast(data.message, 'success');
            cache.events = false;
            loadEvents();
        });
    });

    /* ══════════════════════════════════════════
     * Profil
     * ══════════════════════════════════════════ */

    $dash.on('click', '#tix-od-save-profile', function() {
        ajax('tix_od_profile', {
            display_name: $('#tix-od-profile-name').val(),
        }, function(data) {
            toast(data.message, 'success');
        });
    });

    /* ══════════════════════════════════════════
     * Hilfsfunktionen
     * ══════════════════════════════════════════ */

    function ajax(action, params, onSuccess) {
        params = params || {};
        params.action = action;
        params.nonce  = tixOD.nonce;

        $.post(tixOD.ajax, params, function(resp) {
            if (resp.success && onSuccess) {
                onSuccess(resp.data);
            } else if (!resp.success) {
                toast(resp.data ? resp.data.message : 'Fehler aufgetreten.', 'error');
            }
        }).fail(function() {
            toast('Verbindungsfehler.', 'error');
        });
    }

    function toast(msg, type) {
        var $t = $('<div class="tix-od-toast tix-od-toast-' + (type || 'success') + '">' + msg + '</div>');
        $('body').append($t);
        setTimeout(function() { $t.fadeOut(300, function() { $t.remove(); }); }, 3000);
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function populateEventDropdowns(events) {
        var dropdowns = ['#tix-od-gl-event', '#tix-od-orders-event'];
        $.each(dropdowns, function(i, sel) {
            var $sel = $(sel);
            var firstOpt = $sel.find('option:first').clone();
            $sel.empty().append(firstOpt);
            $.each(events, function(j, ev) {
                $sel.append('<option value="' + ev.id + '">' + escHtml(ev.title) + ' (' + (ev.date || '') + ')</option>');
            });
        });
    }

})(jQuery);
