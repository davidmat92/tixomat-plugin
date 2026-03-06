(function($) {
    'use strict';

    var activeTab = 'promoters';
    var charts = {};

    /* ── Init ── */
    $(function() {
        initTabs();
        populateDropdowns();
        loadTab('promoters');
    });

    /* ── Tabs ── */
    function initTabs() {
        $(document).on('click', '.tix-nav-tab', function() {
            var tab = $(this).data('tab');
            $('.tix-nav-tab').removeClass('active');
            $(this).addClass('active');
            $('.tix-pane').removeClass('active');
            $('[data-pane="' + tab + '"]').addClass('active');
            activeTab = tab;
            loadTab(tab);
        });
    }

    function loadTab(tab) {
        switch(tab) {
            case 'promoters':    loadPromoters(); break;
            case 'events':       loadAssignments(); break;
            case 'commissions':  loadCommissions(); break;
            case 'payouts':      loadPayouts(); break;
            case 'stats':        loadStats(); break;
        }
    }

    /* ── Populate Filter & Form Dropdowns ── */
    function populateDropdowns() {
        var pOpts = '<option value="">Alle Promoter</option>';
        var pOptsReq = '<option value="">Promoter w\u00e4hlen\u2026</option>';
        if (tixPromoter.promoters) {
            $.each(tixPromoter.promoters, function(i, p) {
                var label = esc(p.name || p.code);
                pOpts += '<option value="' + p.id + '">' + label + '</option>';
                pOptsReq += '<option value="' + p.id + '">' + label + '</option>';
            });
        }
        $('#tix-assign-filter-promoter, #tix-comm-filter-promoter, #tix-payout-filter-promoter').html(pOpts);
        $('#tix-af-promoter, #tix-payf-promoter').html(pOptsReq);

        var eOpts = '<option value="">Alle Events</option>';
        var eOptsReq = '<option value="">Event w\u00e4hlen\u2026</option>';
        if (tixPromoter.events) {
            $.each(tixPromoter.events, function(i, e) {
                var label = esc(e.title);
                eOpts += '<option value="' + e.id + '">' + label + '</option>';
                eOptsReq += '<option value="' + e.id + '">' + label + '</option>';
            });
        }
        $('#tix-assign-filter-event, #tix-comm-filter-event').html(eOpts);
        $('#tix-af-event').html(eOptsReq);
    }

    /* ════════════════════════════════
       PROMOTER TAB
       ════════════════════════════════ */

    function loadPromoters() {
        var $tbody = $('#tix-promoter-table tbody');
        $tbody.html('<tr><td colspan="8" class="tix-loading"><div class="tix-spinner"></div></td></tr>');

        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_list',
            nonce: tixPromoter.nonce
        }, function(r) {
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="8" class="tix-empty">Keine Promoter vorhanden</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, p) {
                html += '<tr>' +
                    '<td><strong>' + esc(p.display_name || p.promoter_code) + '</strong></td>' +
                    '<td><code>' + esc(p.promoter_code) + '</code></td>' +
                    '<td>' + esc(p.user_email) + '</td>' +
                    '<td>' + p.status_badge + '</td>' +
                    '<td>' + esc(p.total_sales) + '</td>' +
                    '<td>' + esc(p.total_commission) + '</td>' +
                    '<td>' + esc(p.pending_commission) + '</td>' +
                    '<td class="tix-actions">' +
                        '<button class="button button-small tix-promo-edit" data-id="' + p.id + '" data-code="' + esc(p.promoter_code) + '" data-name="' + esc(p.display_name) + '" data-notes="' + esc(p.notes || '') + '">Bearbeiten</button> ' +
                        (p.status === 'active' ?
                            '<button class="button button-small tix-promo-deactivate" data-id="' + p.id + '">Deaktivieren</button>' :
                            '<button class="button button-small tix-promo-activate" data-id="' + p.id + '">Aktivieren</button>'
                        ) +
                    '</td></tr>';
            });
            $tbody.html(html);
        });
    }

    // Add Promoter Form Toggle
    $(document).on('click', '#tix-promoter-add-btn', function() {
        var $f = $('#tix-promoter-form');
        $f.toggle();
        $('#tix-pf-id').val('0');
        $('#tix-pf-user-search').val('');
        $('#tix-pf-user-id').val('0');
        $('#tix-pf-code').val('');
        $('#tix-pf-display-name').val('');
        $('#tix-pf-notes').val('');
        $('#tix-pf-user-wrap').show();
        $('#tix-promoter-form-title').text('Neuen Promoter erstellen');
    });

    // Cancel
    $(document).on('click', '#tix-promoter-cancel-btn', function() {
        $('#tix-promoter-form').hide();
    });

    // Edit Promoter
    $(document).on('click', '.tix-promo-edit', function() {
        var $f = $('#tix-promoter-form');
        $f.show();
        $('#tix-promoter-form-title').text('Promoter bearbeiten');
        $('#tix-pf-id').val($(this).data('id'));
        $('#tix-pf-code').val($(this).data('code'));
        $('#tix-pf-display-name').val($(this).data('name'));
        $('#tix-pf-notes').val($(this).data('notes'));
        $('#tix-pf-user-wrap').hide();
    });

    // Save Promoter
    $(document).on('click', '#tix-promoter-save-btn', function() {
        var data = {
            action: 'tix_promoter_save',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-pf-id').val(),
            user_id: $('#tix-pf-user-id').val(),
            promoter_code: $('#tix-pf-code').val(),
            display_name: $('#tix-pf-display-name').val(),
            notes: $('#tix-pf-notes').val()
        };
        $.post(tixPromoter.ajaxurl, data, function(r) {
            if (r.success) {
                $('#tix-promoter-form').hide();
                loadPromoters();
                refreshPromoterDropdowns();
            } else {
                alert(r.data || 'Fehler beim Speichern.');
            }
        });
    });

    // Deactivate / Activate
    $(document).on('click', '.tix-promo-deactivate', function() {
        if (!confirm('Promoter wirklich deaktivieren?')) return;
        $.post(tixPromoter.ajaxurl, { action: 'tix_promoter_delete', nonce: tixPromoter.nonce, id: $(this).data('id') }, function() { loadPromoters(); });
    });
    $(document).on('click', '.tix-promo-activate', function() {
        $.post(tixPromoter.ajaxurl, { action: 'tix_promoter_save', nonce: tixPromoter.nonce, promoter_id: $(this).data('id'), status: 'active' }, function() { loadPromoters(); });
    });

    // Auto-generate Code
    $(document).on('click', '#tix-pf-code-generate', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-randomize').addClass('dashicons-update');
        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_generate_code',
            nonce: tixPromoter.nonce
        }, function(r) {
            $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update').addClass('dashicons-randomize');
            if (r.success) {
                $('#tix-pf-code').val(r.data.code).focus();
            } else {
                alert(r.data || 'Code-Generierung fehlgeschlagen.');
            }
        }).fail(function() {
            $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update').addClass('dashicons-randomize');
            alert('Verbindungsfehler.');
        });
    });

    // User Search
    var userSearchTimer;
    $(document).on('input', '#tix-pf-user-search', function() {
        var q = $(this).val();
        clearTimeout(userSearchTimer);
        if (q.length < 2) { $('#tix-pf-user-results').empty().hide(); return; }
        userSearchTimer = setTimeout(function() {
            $.post(tixPromoter.ajaxurl, { action: 'tix_promoter_search_users', nonce: tixPromoter.nonce, q: q }, function(r) {
                if (!r.success || !r.data.length) {
                    $('#tix-pf-user-results').html('<div class="tix-autocomplete-item">Kein Benutzer gefunden</div>').show();
                    return;
                }
                var html = '';
                $.each(r.data, function(i, u) {
                    html += '<div class="tix-autocomplete-item" data-id="' + u.id + '" data-name="' + esc(u.value) + '">' + esc(u.label) + '</div>';
                });
                $('#tix-pf-user-results').html(html).show();
            });
        }, 300);
    });

    $(document).on('click', '.tix-autocomplete-item[data-id]', function() {
        $('#tix-pf-user-id').val($(this).data('id'));
        $('#tix-pf-user-search').val($(this).data('name'));
        $('#tix-pf-user-results').hide();
    });

    /* ════════════════════════════════
       EVENTS TAB
       ════════════════════════════════ */

    function loadAssignments() {
        var $tbody = $('#tix-assign-table tbody');
        $tbody.html('<tr><td colspan="8" class="tix-loading"><div class="tix-spinner"></div></td></tr>');

        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_assignments',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-assign-filter-promoter').val() || '',
            event_id: $('#tix-assign-filter-event').val() || ''
        }, function(r) {
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="8" class="tix-empty">Keine Zuordnungen vorhanden</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, a) {
                html += '<tr>' +
                    '<td>' + esc(a.promoter_name) + '</td>' +
                    '<td>' + esc(a.event_title) + '</td>' +
                    '<td>' + esc(a.commission_display) + '</td>' +
                    '<td>' + esc(a.discount_display) + '</td>' +
                    '<td>' + (a.promo_code && a.promo_code !== '\u2013' ? '<code>' + esc(a.promo_code) + '</code>' : '\u2013') + '</td>' +
                    '<td class="tix-ref-link-cell">' +
                        '<code class="tix-ref-link-code">' + esc(a.referral_link) + '</code>' +
                        '<button class="button button-small tix-copy-btn" data-copy="' + esc(a.referral_link) + '" title="Link kopieren"><span class="dashicons dashicons-clipboard"></span></button>' +
                    '</td>' +
                    '<td>' + a.status_badge + '</td>' +
                    '<td class="tix-actions">' +
                        '<button class="button button-small tix-promo-unassign" data-id="' + a.id + '">Entfernen</button>' +
                    '</td></tr>';
            });
            $tbody.html(html);
        });
    }

    // Filter changes
    $(document).on('change', '#tix-assign-filter-promoter, #tix-assign-filter-event', function() { loadAssignments(); });

    // Assignment Form Toggle
    $(document).on('click', '#tix-assign-add-btn', function() { $('#tix-assign-form').toggle(); });
    $(document).on('click', '#tix-assign-cancel-btn', function() { $('#tix-assign-form').hide(); });

    // Save Assignment
    $(document).on('click', '#tix-assign-save-btn', function() {
        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_assign',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-af-promoter').val(),
            event_id: $('#tix-af-event').val(),
            commission_type: $('#tix-af-commission-type').val(),
            commission_value: $('#tix-af-commission-value').val(),
            discount_type: $('#tix-af-discount-type').val(),
            discount_value: $('#tix-af-discount-value').val(),
            promo_code: $('#tix-af-promo-code').val()
        }, function(r) {
            if (r.success) {
                $('#tix-assign-form').hide();
                loadAssignments();
            } else {
                alert(r.data || 'Fehler.');
            }
        });
    });

    // Unassign
    $(document).on('click', '.tix-promo-unassign', function() {
        if (!confirm('Zuordnung wirklich entfernen?')) return;
        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_unassign',
            nonce: tixPromoter.nonce,
            id: $(this).data('id')
        }, function() { loadAssignments(); });
    });

    /* ════════════════════════════════
       COMMISSIONS TAB
       ════════════════════════════════ */

    function loadCommissions() {
        var $tbody = $('#tix-comm-table tbody');
        $tbody.html('<tr><td colspan="8" class="tix-loading"><div class="tix-spinner"></div></td></tr>');

        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_commissions',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-comm-filter-promoter').val() || '',
            event_id: $('#tix-comm-filter-event').val() || '',
            date_from: $('#tix-comm-filter-from').val() || '',
            date_to: $('#tix-comm-filter-to').val() || '',
            status: $('#tix-comm-filter-status').val() || ''
        }, function(r) {
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="8" class="tix-empty">Keine Provisionen vorhanden</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, c) {
                html += '<tr>' +
                    '<td>' + esc(c.created_at) + '</td>' +
                    '<td>' + esc(c.promoter_name) + '</td>' +
                    '<td>' + esc(c.event_title) + '</td>' +
                    '<td><a href="' + esc(c.order_link) + '">#' + c.order_id + '</a></td>' +
                    '<td>' + c.tickets_qty + '</td>' +
                    '<td>' + esc(c.order_total) + '</td>' +
                    '<td>' + esc(c.commission_amount) + '</td>' +
                    '<td>' + c.status_badge + '</td>' +
                    '</tr>';
            });
            $tbody.html(html);
        });
    }

    $(document).on('click', '#tix-comm-filter-btn', function() { loadCommissions(); });
    $(document).on('change', '#tix-comm-filter-promoter, #tix-comm-filter-event, #tix-comm-filter-status', function() { loadCommissions(); });

    /* ════════════════════════════════
       PAYOUTS TAB
       ════════════════════════════════ */

    function loadPayouts() {
        var $tbody = $('#tix-payout-table tbody');
        $tbody.html('<tr><td colspan="8" class="tix-loading"><div class="tix-spinner"></div></td></tr>');

        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_payouts',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-payout-filter-promoter').val() || '',
            status: $('#tix-payout-filter-status').val() || ''
        }, function(r) {
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="8" class="tix-empty">Keine Auszahlungen vorhanden</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, p) {
                var actions = '';
                if (p.status === 'pending') {
                    actions = '<button class="button button-small tix-promo-mark-paid" data-id="' + p.id + '">Bezahlt</button> ' +
                              '<button class="button button-small tix-promo-cancel-payout" data-id="' + p.id + '">Stornieren</button>';
                }
                html += '<tr>' +
                    '<td>' + esc(p.period) + '</td>' +
                    '<td>' + esc(p.promoter_name) + '</td>' +
                    '<td>' + esc(p.total_sales) + '</td>' +
                    '<td>' + esc(p.total_commission) + '</td>' +
                    '<td>' + p.commission_count + '</td>' +
                    '<td>' + p.status_badge + '</td>' +
                    '<td>' + esc(p.paid_date) + '</td>' +
                    '<td class="tix-actions">' + actions + '</td>' +
                    '</tr>';
            });
            $tbody.html(html);
        });
    }

    $(document).on('change', '#tix-payout-filter-promoter, #tix-payout-filter-status', function() { loadPayouts(); });

    // Payout Form Toggle
    $(document).on('click', '#tix-payout-add-btn', function() { $('#tix-payout-form').toggle(); });
    $(document).on('click', '#tix-payout-cancel-btn', function() { $('#tix-payout-form').hide(); });

    // Create Payout
    $(document).on('click', '#tix-payout-save-btn', function() {
        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_create_payout',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-payf-promoter').val(),
            period_from: $('#tix-payf-from').val(),
            period_to: $('#tix-payf-to').val()
        }, function(r) {
            if (r.success) {
                $('#tix-payout-form').hide();
                loadPayouts();
            } else {
                alert(r.data || 'Fehler.');
            }
        });
    });

    // Mark Paid
    $(document).on('click', '.tix-promo-mark-paid', function() {
        if (!confirm('Auszahlung als bezahlt markieren?')) return;
        $.post(tixPromoter.ajaxurl, { action: 'tix_promoter_mark_paid', nonce: tixPromoter.nonce, id: $(this).data('id') }, function() { loadPayouts(); });
    });

    // Cancel Payout
    $(document).on('click', '.tix-promo-cancel-payout', function() {
        if (!confirm('Auszahlung wirklich stornieren? Die Provisionen werden wieder freigegeben.')) return;
        $.post(tixPromoter.ajaxurl, { action: 'tix_promoter_cancel_payout', nonce: tixPromoter.nonce, id: $(this).data('id') }, function() { loadPayouts(); });
    });

    // CSV Export
    $(document).on('click', '#tix-payout-csv-btn', function(e) {
        e.preventDefault();
        var url = tixPromoter.exporturl + '&nonce=' + tixPromoter.nonce;
        var promoter = $('#tix-payout-filter-promoter').val();
        if (promoter) url += '&promoter_id=' + promoter;
        window.open(url, '_blank');
    });

    /* ════════════════════════════════
       STATS TAB
       ════════════════════════════════ */

    function loadStats() {
        $('#tix-promo-stats-kpi').html('<div class="tix-loading"><div class="tix-spinner"></div></div>');

        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_stats',
            nonce: tixPromoter.nonce
        }, function(r) {
            if (!r.success) return;
            var d = r.data;

            // KPIs
            if (d.kpis) {
                var kpiHtml = '';
                $.each(d.kpis, function(key, kpi) {
                    kpiHtml += '<div class="tix-kpi">' +
                        '<span class="dashicons ' + esc(kpi.icon) + '"></span>' +
                        '<span class="tix-kpi-num">' + esc(String(kpi.value)) + '</span>' +
                        '<span class="tix-kpi-lbl">' + esc(kpi.label) + '</span>' +
                        '</div>';
                });
                $('#tix-promo-stats-kpi').html('<div class="tix-kpi-grid">' + kpiHtml + '</div>');
            }

            // Chart from server config
            if (d.chart && d.chart.data && window.Chart) {
                if (charts['top-promoters']) charts['top-promoters'].destroy();
                var ctx = document.getElementById('tix-promo-chart-top');
                if (ctx) {
                    charts['top-promoters'] = new Chart(ctx.getContext('2d'), {
                        type: d.chart.type || 'bar',
                        data: d.chart.data,
                        options: $.extend(true, {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'top' } },
                            scales: { y: { beginAtZero: true } }
                        }, d.chart.options || {})
                    });
                }
            }
        });
    }

    /* ── Refresh promoter dropdowns after save ── */
    function refreshPromoterDropdowns() {
        $.post(tixPromoter.ajaxurl, { action: 'tix_promoter_list', nonce: tixPromoter.nonce }, function(r) {
            if (!r.success) return;
            tixPromoter.promoters = [];
            $.each(r.data, function(i, p) {
                tixPromoter.promoters.push({ id: p.id, name: p.display_name || p.promoter_code, code: p.promoter_code });
            });
            populateDropdowns();
        });
    }

    /* ════════════════════════════════
       HELPERS
       ════════════════════════════════ */

    // Copy to Clipboard
    $(document).on('click', '.tix-copy-btn', function(e) {
        e.preventDefault();
        var text = $(this).data('copy');
        var $btn = $(this);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
                setTimeout(function() { $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard'); }, 1500);
            });
        } else {
            var $tmp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
            $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
            setTimeout(function() { $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard'); }, 1500);
        }
    });

    function eur(v) {
        v = parseFloat(v) || 0;
        return v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' \u20ac';
    }

    function esc(str) {
        if (!str && str !== 0) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})(jQuery);
