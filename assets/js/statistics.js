(function($) {
    'use strict';

    /* ── Globals ── */
    var charts = {};
    var cache  = {};
    var activeTab = 'overview';
    var loadedTabs = {};

    var TIX_COLORS = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16'];

    /* ── Chart.js Defaults ── */
    if (window.Chart) {
        Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#64748b';
        Chart.defaults.plugins.legend.position = 'bottom';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.padding = 16;
        Chart.defaults.elements.line.tension = 0.3;
        Chart.defaults.responsive = true;
        Chart.defaults.maintainAspectRatio = false;
    }

    /* ── Filters ── */
    var filters = {
        date_from:    dateDaysAgo(30),
        date_to:      dateToday(),
        event_id:     '',
        location_id:  '',
        category_id:  '',
        compare_mode: false,
        compare_type: 'previous',
        compare_from: '',
        compare_to:   ''
    };

    function dateToday() {
        return new Date().toISOString().slice(0, 10);
    }
    function dateDaysAgo(n) {
        var d = new Date();
        d.setDate(d.getDate() - n);
        return d.toISOString().slice(0, 10);
    }
    function dateYearStart() {
        return new Date().getFullYear() + '-01-01';
    }
    function formatDateDE(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    /* ── Vergleichszeitraum berechnen ── */
    function resolveComparisonDates() {
        if (!filters.compare_mode) return;
        if (filters.compare_type === 'custom') return; // manuelle Eingabe

        var from = new Date(filters.date_from + 'T00:00:00');
        var to   = new Date(filters.date_to + 'T00:00:00');

        if (isNaN(from.getTime()) || isNaN(to.getTime())) return;

        var days = Math.round((to - from) / 86400000);

        if (filters.compare_type === 'previous') {
            var prevTo   = new Date(from);
            prevTo.setDate(prevTo.getDate() - 1);
            var prevFrom = new Date(prevTo);
            prevFrom.setDate(prevFrom.getDate() - days);
            filters.compare_from = prevFrom.toISOString().slice(0, 10);
            filters.compare_to   = prevTo.toISOString().slice(0, 10);
        } else if (filters.compare_type === 'previous_year') {
            var yearFrom = new Date(from);
            yearFrom.setFullYear(yearFrom.getFullYear() - 1);
            var yearTo = new Date(to);
            yearTo.setFullYear(yearTo.getFullYear() - 1);
            filters.compare_from = yearFrom.toISOString().slice(0, 10);
            filters.compare_to   = yearTo.toISOString().slice(0, 10);
        }
    }

    function updateCompareLabel() {
        var $label = $('#tix-compare-label');
        if (!$label.length) return;
        if (!filters.compare_mode || !filters.compare_from || !filters.compare_to) {
            $label.text('');
            return;
        }
        $label.text('Vergleich: ' + formatDateDE(filters.compare_from) + ' – ' + formatDateDE(filters.compare_to));
    }

    /* ── Init ── */
    $(function() {
        initFilterDropdowns();
        initFilterPresets();
        initFilterDates();
        initFilterSelects();
        initCompare();
        initTabs();
        initExport();
        loadTabData('overview');
    });

    /* ── Filter Dropdowns ── */
    function initFilterDropdowns() {
        var $ev = $('#tix-stats-event');
        var $lo = $('#tix-stats-location');
        var $ca = $('#tix-stats-category');

        if (tixStats.events) {
            $.each(tixStats.events, function(i, e) {
                $ev.append('<option value="' + e.id + '">' + escHtml(e.title) + '</option>');
            });
        }
        if (tixStats.locations) {
            $.each(tixStats.locations, function(i, l) {
                $lo.append('<option value="' + l.id + '">' + escHtml(l.title) + '</option>');
            });
        }
        if (tixStats.categories) {
            $.each(tixStats.categories, function(i, c) {
                $ca.append('<option value="' + c.id + '">' + escHtml(c.name) + '</option>');
            });
        }
    }

    function initFilterPresets() {
        $('.tix-stats-presets').on('click', 'button', function() {
            var range = $(this).data('range');
            $(this).siblings().removeClass('active');
            $(this).addClass('active');

            if (range === 'all') {
                filters.date_from = '';
                filters.date_to   = '';
            } else if (range === 'year') {
                filters.date_from = dateYearStart();
                filters.date_to   = dateToday();
            } else {
                filters.date_from = dateDaysAgo(parseInt(range));
                filters.date_to   = dateToday();
            }

            $('#tix-stats-from').val(filters.date_from);
            $('#tix-stats-to').val(filters.date_to);
            invalidateAndReload();
        });
    }

    function initFilterDates() {
        $('#tix-stats-from').val(filters.date_from);
        $('#tix-stats-to').val(filters.date_to);

        $('#tix-stats-from, #tix-stats-to').on('change', function() {
            filters.date_from = $('#tix-stats-from').val();
            filters.date_to   = $('#tix-stats-to').val();
            // Deselect presets
            $('.tix-stats-presets button').removeClass('active');
            invalidateAndReload();
        });
    }

    function initFilterSelects() {
        $('#tix-stats-event').on('change', function() {
            filters.event_id = $(this).val();
            invalidateAndReload();
        });
        $('#tix-stats-location').on('change', function() {
            filters.location_id = $(this).val();
            invalidateAndReload();
        });
        $('#tix-stats-category').on('change', function() {
            filters.category_id = $(this).val();
            invalidateAndReload();
        });
    }

    /* ── Vergleichszeitraum Controls ── */
    function initCompare() {
        $('#tix-compare-mode').on('change', function() {
            filters.compare_mode = this.checked;
            $('.tix-compare-options').toggle(filters.compare_mode);
            invalidateAndReload();
        });

        $('#tix-compare-type').on('change', function() {
            filters.compare_type = $(this).val();
            $('.tix-compare-custom-dates').toggle(filters.compare_type === 'custom');
            invalidateAndReload();
        });

        $('#tix-compare-from, #tix-compare-to').on('change', function() {
            filters.compare_from = $('#tix-compare-from').val();
            filters.compare_to   = $('#tix-compare-to').val();
            if (filters.compare_from && filters.compare_to) {
                invalidateAndReload();
            }
        });
    }

    function invalidateAndReload() {
        resolveComparisonDates();
        updateCompareLabel();
        cache = {};
        loadedTabs = {};
        loadTabData(activeTab);
    }

    /* ── Tabs ── */
    function initTabs() {
        var $app = $('#tix-stats-app');
        $app.on('click', '.tix-nav-tab', function() {
            var tab = $(this).data('tab');
            $app.find('.tix-nav-tab').removeClass('active');
            $(this).addClass('active');
            $app.find('.tix-pane').removeClass('active');
            $app.find('[data-pane="' + tab + '"]').addClass('active');
            activeTab = tab;
            loadTabData(tab);
        });

        // Restore saved tab
        var saved = sessionStorage.getItem('tix_stats_tab');
        if (saved && $app.find('.tix-nav-tab[data-tab="' + saved + '"]').length) {
            $app.find('.tix-nav-tab[data-tab="' + saved + '"]').trigger('click');
        }
    }

    /* ── Export ── */
    function initExport() {
        $(document).on('click', '.tix-stats-export-btn', function() {
            var tab = $(this).data('tab');
            var form = $('<form>', {
                method: 'POST',
                action: tixStats.ajaxurl,
                target: '_blank'
            });
            form.append($('<input>', { type: 'hidden', name: 'action', value: 'tix_stats_export' }));
            form.append($('<input>', { type: 'hidden', name: 'nonce',  value: tixStats.nonce }));
            form.append($('<input>', { type: 'hidden', name: 'tab',    value: tab }));
            form.append($('<input>', { type: 'hidden', name: 'date_from',   value: filters.date_from }));
            form.append($('<input>', { type: 'hidden', name: 'date_to',     value: filters.date_to }));
            form.append($('<input>', { type: 'hidden', name: 'event_id',    value: filters.event_id }));
            form.append($('<input>', { type: 'hidden', name: 'location_id', value: filters.location_id }));
            form.append($('<input>', { type: 'hidden', name: 'category_id', value: filters.category_id }));
            $('body').append(form);
            form.submit();
            form.remove();
        });
    }

    /* ── Data Loading ── */
    function loadTabData(tab) {
        sessionStorage.setItem('tix_stats_tab', tab);
        var ck = tab + '_' + JSON.stringify(filters);

        if (cache[ck]) {
            renderTab(tab, cache[ck]);
            return;
        }

        showLoading(tab);

        var postData = {
            action:       'tix_stats_' + tab,
            nonce:        tixStats.nonce,
            date_from:    filters.date_from,
            date_to:      filters.date_to,
            event_id:     filters.event_id,
            location_id:  filters.location_id,
            category_id:  filters.category_id
        };

        // Vergleichs-Parameter mitschicken
        if (filters.compare_mode && filters.compare_from && filters.compare_to) {
            postData.compare_mode = '1';
            postData.compare_from = filters.compare_from;
            postData.compare_to   = filters.compare_to;
        }

        $.post(tixStats.ajaxurl, postData, function(response) {
            if (response.success && response.data) {
                cache[ck] = response.data;
                renderTab(tab, response.data);
            } else {
                showEmpty(tab);
            }
        }).fail(function() {
            showEmpty(tab);
        });
    }

    /* ── Rendering ── */
    function renderTab(tab, data) {
        if (data.kpis)   renderKPIs(tab, data.kpis);
        if (data.charts) renderCharts(tab, data.charts);
        if (data.table)  renderTable(tab, data.table);
        loadedTabs[tab] = true;
    }

    function renderKPIs(tab, kpis) {
        var $c = $('#tix-stats-kpi-' + tab);
        $c.empty();
        $.each(kpis, function(key, kpi) {
            var trendClass = '';
            var trendText  = '';
            if (kpi.trend !== null && kpi.trend !== undefined) {
                if (kpi.trend > 0) {
                    trendClass = 'tix-trend-up';
                    trendText  = '+' + kpi.trend + '%';
                } else if (kpi.trend < 0) {
                    trendClass = 'tix-trend-down';
                    trendText  = kpi.trend + '%';
                } else {
                    trendClass = 'tix-trend-neutral';
                    trendText  = '±0%';
                }
            }

            var hasCompare = kpi.compare !== null && kpi.compare !== undefined;

            var html = '<div class="tix-stats-kpi-card' + (hasCompare ? ' tix-kpi-has-compare' : '') + '">' +
                '<span class="tix-stats-kpi-icon dashicons ' + kpi.icon + '"></span>' +
                '<span class="tix-stats-kpi-num">' + kpi.value + '</span>' +
                '<span class="tix-stats-kpi-lbl">' + kpi.label + '</span>';

            if (hasCompare) {
                html += '<div class="tix-kpi-compare-row">' +
                    '<span class="tix-kpi-compare-val">' + kpi.compare + '</span>' +
                    (trendText ? '<span class="tix-stats-kpi-trend ' + trendClass + '">' + trendText + '</span>' : '') +
                '</div>';
            } else if (trendText) {
                html += '<span class="tix-stats-kpi-trend ' + trendClass + '">' + trendText + '</span>';
            }

            html += '</div>';
            $c.append(html);
        });
    }

    function renderCharts(tab, chartConfigs) {
        $.each(chartConfigs, function(chartId, config) {
            // Interne Felder überspringen
            if (chartId.charAt(0) === '_') return;

            var canvasId = 'chart-' + tab + '-' + chartId;
            var canvas   = document.getElementById(canvasId);
            if (!canvas) return;

            // Destroy existing
            if (charts[canvasId]) {
                charts[canvasId].destroy();
                delete charts[canvasId];
            }

            var ctx = canvas.getContext('2d');

            // Merge default options
            var options = $.extend(true, {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {}
                    }
                }
            }, config.options || {});

            // Auto-assign colors if not present
            if (config.data && config.data.datasets) {
                $.each(config.data.datasets, function(i, ds) {
                    if (!ds.borderColor && !ds.backgroundColor && config.type === 'line') {
                        ds.borderColor = TIX_COLORS[i % TIX_COLORS.length];
                    }
                });
            }

            try {
                charts[canvasId] = new Chart(ctx, {
                    type: config.type || 'bar',
                    data: config.data || {},
                    options: options
                });
            } catch(e) {
                // Chart rendering failed silently
            }
        });
    }

    function renderTable(tab, rows) {
        var $tbody = $('#tix-stats-table-' + tab + ' tbody');
        if (!$tbody.length) return;

        $tbody.empty();

        if (!rows || rows.length === 0) {
            var cols = $('#tix-stats-table-' + tab + ' thead th').length;
            $tbody.append('<tr><td colspan="' + cols + '" class="tix-stats-empty"><span class="dashicons dashicons-chart-area"></span>Keine Daten vorhanden</td></tr>');
            return;
        }

        $.each(rows, function(i, row) {
            var tr = '<tr>';
            $.each(row, function(k, v) {
                tr += '<td>' + (v !== null && v !== undefined ? v : '–') + '</td>';
            });
            tr += '</tr>';
            $tbody.append(tr);
        });
    }

    function showLoading(tab) {
        var $kpi = $('#tix-stats-kpi-' + tab);
        $kpi.html('<div class="tix-stats-loading"><div class="tix-stats-spinner"></div></div>');

        var $tbody = $('#tix-stats-table-' + tab + ' tbody');
        if ($tbody.length) {
            var cols = $('#tix-stats-table-' + tab + ' thead th').length;
            $tbody.html('<tr><td colspan="' + cols + '" class="tix-stats-loading"><div class="tix-stats-spinner"></div></td></tr>');
        }
    }

    function showEmpty(tab) {
        var $kpi = $('#tix-stats-kpi-' + tab);
        $kpi.html('<div class="tix-stats-empty"><span class="dashicons dashicons-chart-area"></span>Keine Daten verfügbar</div>');
    }

    /* ── Helpers ── */
    function escHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})(jQuery);
