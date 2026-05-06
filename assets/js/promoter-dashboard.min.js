(function($) {
    'use strict';

    var activeTab = 'overview';
    var charts = {};
    var cache = {};

    $(function() {
        initTabs();
        initCopy();
        loadTab('overview');
    });

    /* ── Tabs (Sidebar-Nav my-account-Stil + Legacy-Buttons) ── */
    function initTabs() {
        // Sidebar-Nav-Links (neu) + Legacy-Tab-Buttons (versteckt) — beide triggern dasselbe
        $('.tix-pd').on('click', '.tix-pd-nav-link, .tix-pd-tab', function(e) {
            e.preventDefault();
            switchTab($(this).data('tab'));
        });
    }

    function switchTab(tab) {
        if (!tab) return;
        // Sidebar-Nav active
        $('.tix-account-nav-item').removeClass('is-active');
        $('.tix-pd-nav-link[data-tab="' + tab + '"]').closest('.tix-account-nav-item').addClass('is-active');
        // Legacy-Buttons (für Backward-Compat)
        $('.tix-pd-tab').removeClass('active');
        $('.tix-pd-tab[data-tab="' + tab + '"]').addClass('active');
        // Panels
        $('.tix-pd-panel').removeClass('active');
        $('.tix-pd-panel[data-tab="' + tab + '"]').addClass('active');
        activeTab = tab;
        loadTab(tab);
    }

    function loadTab(tab) {
        if (cache[tab]) {
            renderTab(tab, cache[tab]);
            return;
        }
        switch(tab) {
            case 'overview':    loadOverview(); break;
            case 'events':      loadEvents(); break;
            case 'tracking':    loadTracking(); break;
            case 'sales':       loadSales(); break;
            case 'commissions': loadCommissions(); break;
            case 'payouts':     loadPayouts(); break;
        }
    }

    /* ── Tracking-Tab ── */
    function loadTracking() {
        ajax('tix_pd_tracking', {}, function(d) {
            cache['tracking'] = d;
            renderTracking(d);
        });
    }

    function renderTracking(d) {
        var s = d.stats || {};
        $('#tix-pd-tk-total').text(s.total || 0);
        $('#tix-pd-tk-unique').text(s.unique || 0);
        $('#tix-pd-tk-today').text(s.today || 0);
        $('#tix-pd-tk-7d').text(s.last_7d || 0);
        $('#tix-pd-tk-30d').text(s.last_30d || 0);
        $('#tix-pd-tk-conv').text(d.conversion_rate ? d.conversion_rate + '%' : '0%');

        // Top Pages
        var $body = $('#tix-pd-tk-pages-body');
        if (!s.top_pages || !s.top_pages.length) {
            $body.html('<tr><td colspan="3" class="tix-pd-empty">Noch keine Klicks</td></tr>');
        } else {
            var html = '';
            $.each(s.top_pages, function(i, p) {
                html += '<tr>' +
                    '<td><code style="font-size:11px;">' + esc(p.page_path || '/') + '</code></td>' +
                    '<td>' + esc(p.clicks) + '</td>' +
                    '<td>' + esc(p.uniques) + '</td>' +
                    '</tr>';
            });
            $body.html(html);
        }

        // Devices
        var $dev = $('#tix-pd-tk-devices');
        if (!s.devices || !s.devices.length) {
            $dev.html('<div class="tix-pd-empty">Keine Daten</div>');
        } else {
            var totalClicks = 0;
            $.each(s.devices, function(i, d) { totalClicks += parseInt(d.clicks, 10) || 0; });
            var devIcons = { mobile: '📱', tablet: '💻', desktop: '🖥️', unknown: '❓' };
            var dh = '';
            $.each(s.devices, function(i, dv) {
                var pct = totalClicks > 0 ? Math.round((dv.clicks / totalClicks) * 100) : 0;
                var icon = devIcons[dv.device_type] || '•';
                var label = (dv.device_type || 'unbekannt').charAt(0).toUpperCase() + (dv.device_type || 'unbekannt').slice(1);
                dh += '<div style="margin-bottom:10px;">' +
                    '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px;">' +
                        '<span>' + icon + ' <strong>' + esc(label) + '</strong></span>' +
                        '<span style="color:#64748b;">' + pct + '%</span>' +
                    '</div>' +
                    '<div style="height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;">' +
                        '<div style="height:100%;background:var(--tix-acc-primary, #FF5500);width:' + pct + '%;"></div>' +
                    '</div>' +
                    '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">' + esc(dv.clicks) + ' Klicks</div>' +
                '</div>';
            });
            $dev.html(dh);
        }

        // Time-Series Chart
        if (s.timeseries && s.timeseries.length && typeof Chart !== 'undefined') {
            var ctx = document.getElementById('tix-pd-chart-clicks');
            if (ctx) {
                if (charts.clicks) { charts.clicks.destroy(); }
                charts.clicks = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: s.timeseries.map(function(r) { return r.click_date; }),
                        datasets: [
                            { label: 'Klicks', data: s.timeseries.map(function(r) { return parseInt(r.clicks, 10); }), borderColor: getComputedStyle(document.documentElement).getPropertyValue('--tix-acc-primary').trim() || '#FF5500', backgroundColor: 'rgba(255,85,0,0.12)', fill: true, tension: 0.3 },
                            { label: 'Unique', data: s.timeseries.map(function(r) { return parseInt(r.uniques, 10); }), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.08)', fill: false, tension: 0.3 }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });
            }
        }
    }

    /* ── Copy to Clipboard ── */
    function initCopy() {
        $(document).on('click', '.tix-pd-copy', function() {
            var text = $(this).data('copy');
            var $btn = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    $btn.addClass('copied').find('.tix-pd-copy-label').text('Kopiert!');
                    setTimeout(function() { $btn.removeClass('copied').find('.tix-pd-copy-label').text('Kopieren'); }, 2000);
                });
            } else {
                // Fallback
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                $btn.addClass('copied').find('.tix-pd-copy-label').text('Kopiert!');
                setTimeout(function() { $btn.removeClass('copied').find('.tix-pd-copy-label').text('Kopieren'); }, 2000);
            }
        });
    }

    /* ════════════════════════════════
       OVERVIEW
       ════════════════════════════════ */
    function loadOverview() {
        showLoading('#tix-pd-kpis');
        ajax('tix_pd_overview', {}, function(d) {
            cache['overview'] = d;
            renderOverview(d);
        });
    }

    function renderOverview(d) {
        var kpis = d.kpis;
        var html = '';
        if (kpis) {
            var items = [
                { icon: '💰', num: kpis.total_sales, lbl: 'Gesamtumsatz' },
                { icon: '📊', num: kpis.total_commission, lbl: 'Provision gesamt' },
                { icon: '⏳', num: kpis.pending_commission, lbl: 'Ausstehend' },
                { icon: '🎫', num: kpis.events_count, lbl: 'Aktive Events' }
            ];
            $.each(items, function(i, kpi) {
                html += '<div class="tix-pd-kpi">' +
                    '<span class="tix-pd-kpi-icon">' + kpi.icon + '</span>' +
                    '<span class="tix-pd-kpi-num">' + kpi.num + '</span>' +
                    '<span class="tix-pd-kpi-lbl">' + kpi.lbl + '</span>' +
                    '</div>';
            });
        }
        $('#tix-pd-kpis').html(html);

        // Referral-Links
        var linksHtml = '';
        if (d.links && d.links.length) {
            $.each(d.links, function(i, l) {
                linksHtml += '<div class="tix-pd-link-card">' +
                    '<div class="tix-pd-link-card-title">' + esc(l.title) + '</div>' +
                    '<div class="tix-pd-link-card-row">' +
                        '<span class="tix-pd-link-card-label">Referral-Link</span>' +
                        '<div class="tix-pd-link-card-value">' +
                            '<input type="text" readonly value="' + esc(l.link) + '" class="tix-pd-link-input">' +
                            '<button class="tix-pd-copy" data-copy="' + esc(l.link) + '"><span class="tix-pd-copy-label">Kopieren</span></button>' +
                        '</div>' +
                    '</div>';
                if (l.promo) {
                    linksHtml += '<div class="tix-pd-link-card-row">' +
                        '<span class="tix-pd-link-card-label">Promo-Code</span>' +
                        '<div class="tix-pd-link-card-value">' +
                            '<code class="tix-pd-link-code">' + esc(l.promo) + '</code>' +
                            '<button class="tix-pd-copy" data-copy="' + esc(l.promo) + '"><span class="tix-pd-copy-label">Kopieren</span></button>' +
                        '</div>' +
                    '</div>';
                }
                linksHtml += '</div>';
            });
        } else {
            linksHtml = '<p class="tix-pd-empty">Keine aktiven Events zugeordnet.</p>';
        }
        $('#tix-pd-links-list').html(linksHtml);

        // Chart
        if (d.chart && d.chart.labels && d.chart.labels.length && window.Chart) {
            if (charts['sales']) charts['sales'].destroy();
            var ctx = document.getElementById('tix-pd-chart-sales');
            if (ctx) {
                charts['sales'] = new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: d.chart.labels,
                        datasets: [
                            {
                                label: 'Umsatz',
                                data: d.chart.sales,
                                borderColor: '#FF5500',
                                backgroundColor: 'rgba(255,85,0,0.08)',
                                fill: true,
                                tension: 0.3
                            },
                            {
                                label: 'Provision',
                                data: d.chart.commission,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16,185,129,0.08)',
                                fill: true,
                                tension: 0.3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
        }
    }

    /* ════════════════════════════════
       EVENTS
       ════════════════════════════════ */
    function loadEvents() {
        showLoading('#tix-pd-events-body', 6);
        ajax('tix_pd_events', {}, function(d) {
            cache['events'] = d;
            renderEvents(d);
        });
    }

    function renderEvents(d) {
        var $tbody = $('#tix-pd-events-body');
        if (!d.events || !d.events.length) {
            $tbody.html('<tr><td colspan="6" class="tix-pd-empty">Keine Events zugeordnet</td></tr>');
            return;
        }
        var html = '';
        $.each(d.events, function(i, e) {
            // PHP liefert event_title/event_date — fallback auf title/date für Abwärtskompat.
            var title = e.event_title || e.title || '';
            var date  = e.event_date  || e.date  || '';
            var link  = e.referral_link || '';
            var rowStyle = e.is_global ? 'background:#fef3c7;' : '';
            html += '<tr style="' + rowStyle + '">' +
                '<td><strong>' + esc(title) + '</strong></td>' +
                '<td>' + esc(date) + '</td>' +
                '<td>' +
                    '<span class="tix-pd-link" style="word-break:break-all;font-size:11px;">' + esc(link) + '</span> ' +
                    '<button class="tix-pd-copy" data-copy="' + esc(link) + '"><span class="tix-pd-copy-label">Kopieren</span></button>' +
                '</td>' +
                '<td>' + (e.promo_code ? '<code>' + esc(e.promo_code) + '</code> <button class="tix-pd-copy" data-copy="' + esc(e.promo_code) + '"><span class="tix-pd-copy-label">Kopieren</span></button>' : '–') + '</td>' +
                '<td>' + esc(e.commission) + '</td>' +
                '<td>' + (e.discount || '–') + '</td>' +
                '</tr>';
        });
        $tbody.html(html);
    }

    /* ════════════════════════════════
       SALES
       ════════════════════════════════ */
    function loadSales() {
        showLoading('#tix-pd-sales-body', 6);
        var params = {
            date_from: $('#tix-pd-sales-from').val() || '',
            date_to: $('#tix-pd-sales-to').val() || ''
        };
        ajax('tix_pd_sales', params, function(d) {
            renderSales(d);
        });
    }

    function renderSales(d) {
        var $tbody = $('#tix-pd-sales-body');
        if (!d.sales || !d.sales.length) {
            $tbody.html('<tr><td colspan="6" class="tix-pd-empty">Keine Verkäufe vorhanden</td></tr>');
            return;
        }
        var html = '';
        $.each(d.sales, function(i, s) {
            html += '<tr>' +
                '<td>' + esc(s.date) + '</td>' +
                '<td>' + esc(s.event) + '</td>' +
                '<td>' + s.tickets + '</td>' +
                '<td>' + esc(s.total) + '</td>' +
                '<td>' + esc(s.commission) + '</td>' +
                '<td>' + esc(s.attribution) + '</td>' +
                '</tr>';
        });
        $tbody.html(html);
    }

    $(document).on('change', '#tix-pd-sales-from, #tix-pd-sales-to', function() {
        cache['sales'] = null;
        loadSales();
    });

    /* ════════════════════════════════
       COMMISSIONS
       ════════════════════════════════ */
    function loadCommissions() {
        showLoading('#tix-pd-commissions-body', 6);
        ajax('tix_pd_commissions', {}, function(d) {
            cache['commissions'] = d;
            renderCommissions(d);
        });
    }

    function renderCommissions(d) {
        var $tbody = $('#tix-pd-commissions-body');
        if (!d.commissions || !d.commissions.length) {
            $tbody.html('<tr><td colspan="6" class="tix-pd-empty">Keine Provisionen vorhanden</td></tr>');
            return;
        }
        var html = '';
        $.each(d.commissions, function(i, c) {
            html += '<tr>' +
                '<td>' + esc(c.date) + '</td>' +
                '<td>' + esc(c.event) + '</td>' +
                '<td>#' + c.order_id + '</td>' +
                '<td>' + c.tickets + '</td>' +
                '<td>' + esc(c.commission) + '</td>' +
                '<td><span class="tix-pd-badge tix-pd-badge-' + c.status + '">' + esc(c.status_label) + '</span></td>' +
                '</tr>';
        });
        $tbody.html(html);
    }

    /* ════════════════════════════════
       PAYOUTS
       ════════════════════════════════ */
    function loadPayouts() {
        showLoading('#tix-pd-payouts-body', 6);
        ajax('tix_pd_payouts', {}, function(d) {
            cache['payouts'] = d;
            renderPayouts(d);
        });
    }

    function renderPayouts(d) {
        var $tbody = $('#tix-pd-payouts-body');
        if (!d.payouts || !d.payouts.length) {
            $tbody.html('<tr><td colspan="6" class="tix-pd-empty">Keine Auszahlungen vorhanden</td></tr>');
            return;
        }
        var html = '';
        $.each(d.payouts, function(i, p) {
            html += '<tr>' +
                '<td>' + esc(p.period) + '</td>' +
                '<td>' + esc(p.total_sales) + '</td>' +
                '<td>' + esc(p.total_commission) + '</td>' +
                '<td>' + p.count + '</td>' +
                '<td><span class="tix-pd-badge tix-pd-badge-' + p.status + '">' + esc(p.status_label) + '</span></td>' +
                '<td>' + esc(p.paid_date || '–') + '</td>' +
                '</tr>';
        });
        $tbody.html(html);
    }

    /* ════════════════════════════════
       HELPERS
       ════════════════════════════════ */

    function ajax(action, params, callback) {
        var data = $.extend({ action: action, nonce: tixPD.nonce }, params);
        $.post(tixPD.ajax, data, function(r) {
            if (r.success && r.data) {
                callback(r.data);
            }
        });
    }

    function showLoading(selector, cols) {
        cols = cols || 1;
        $(selector).html('<tr><td colspan="' + cols + '" class="tix-pd-loading"><div class="tix-pd-spinner"></div></td></tr>');
    }

    function renderTab(tab, d) {
        switch(tab) {
            case 'overview':    renderOverview(d); break;
            case 'events':      renderEvents(d); break;
            case 'sales':       renderSales(d); break;
            case 'commissions': renderCommissions(d); break;
            case 'payouts':     renderPayouts(d); break;
        }
    }

    function esc(str) {
        if (!str && str !== 0) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})(jQuery);
