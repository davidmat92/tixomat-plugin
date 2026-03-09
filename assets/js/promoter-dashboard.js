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

    /* ── Tabs ── */
    function initTabs() {
        $('.tix-pd').on('click', '.tix-pd-tab', function() {
            var tab = $(this).data('tab');
            $('.tix-pd-tab').removeClass('active');
            $(this).addClass('active');
            $('.tix-pd-pane').removeClass('active');
            $('[data-pane="' + tab + '"]').addClass('active');
            activeTab = tab;
            loadTab(tab);
        });
    }

    function loadTab(tab) {
        if (cache[tab]) {
            renderTab(tab, cache[tab]);
            return;
        }
        switch(tab) {
            case 'overview':    loadOverview(); break;
            case 'events':      loadEvents(); break;
            case 'sales':       loadSales(); break;
            case 'commissions': loadCommissions(); break;
            case 'payouts':     loadPayouts(); break;
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
            html += '<tr>' +
                '<td><strong>' + esc(e.title) + '</strong></td>' +
                '<td>' + esc(e.date) + '</td>' +
                '<td>' +
                    '<span class="tix-pd-link">' + esc(e.referral_link) + '</span> ' +
                    '<button class="tix-pd-copy" data-copy="' + esc(e.referral_link) + '"><span class="tix-pd-copy-label">Kopieren</span></button>' +
                '</td>' +
                '<td>' + (e.promo_code ? '<code>' + esc(e.promo_code) + '</code> <button class="tix-pd-copy" data-copy="' + esc(e.promo_code) + '"><span class="tix-pd-copy-label">Kopieren</span></button>' : '–') + '</td>' +
                '<td>' + esc(e.commission) + '</td>' +
                '<td>' + esc(e.discount || '–') + '</td>' +
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
