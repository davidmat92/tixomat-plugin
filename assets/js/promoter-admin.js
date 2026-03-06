(function($) {
    'use strict';

    var activeTab = 'promoters';
    var charts = {};

    /* ── Init ── */
    $(function() {
        initTabs();
        loadTab('promoters');
    });

    /* ── Tabs ── */
    function initTabs() {
        $('.tix-promoter-wrap').on('click', '.tix-nav-tab', function() {
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

    /* ════════════════════════════════
       PROMOTER TAB
       ════════════════════════════════ */

    function loadPromoters() {
        var $tbody = $('#tix-promo-promoters-body');
        $tbody.html('<tr><td colspan="8" class="tix-promo-loading"><div class="tix-promo-spinner"></div></td></tr>');

        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_list',
            nonce: tixPromoter.nonce
        }, function(r) {
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="8" class="tix-promo-empty">Keine Promoter vorhanden</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, p) {
                html += '<tr>' +
                    '<td><strong>' + esc(p.name) + '</strong></td>' +
                    '<td><code>' + esc(p.code) + '</code></td>' +
                    '<td>' + esc(p.email) + '</td>' +
                    '<td>' + badge(p.status) + '</td>' +
                    '<td>' + eur(p.total_sales) + '</td>' +
                    '<td>' + eur(p.total_commission) + '</td>' +
                    '<td>' + eur(p.pending_commission) + '</td>' +
                    '<td class="tix-promo-actions">' +
                        '<button class="tix-promo-btn tix-promo-btn-sm tix-promo-btn-outline tix-promo-edit" data-id="' + p.id + '" data-code="' + esc(p.code) + '" data-name="' + esc(p.display_name) + '" data-notes="' + esc(p.notes || '') + '">Bearbeiten</button> ' +
                        (p.status === 'active' ?
                            '<button class="tix-promo-btn tix-promo-btn-sm tix-promo-btn-danger tix-promo-deactivate" data-id="' + p.id + '">Deaktivieren</button>' :
                            '<button class="tix-promo-btn tix-promo-btn-sm tix-promo-btn-success tix-promo-activate" data-id="' + p.id + '">Aktivieren</button>'
                        ) +
                    '</td></tr>';
            });
            $tbody.html(html);
        });
    }

    // Add Promoter Form Toggle
    $(document).on('click', '#tix-promo-add-btn', function() {
        $('#tix-promo-add-form').toggleClass('open');
        $('#tix-promo-add-form input, #tix-promo-add-form textarea').val('');
        $('#tix-promo-add-form [name="promoter_id"]').val('');
        $('#tix-promo-add-form h3').text('Neuen Promoter hinzufügen');
    });

    // Edit Promoter
    $(document).on('click', '.tix-promo-edit', function() {
        var $f = $('#tix-promo-add-form');
        $f.addClass('open');
        $f.find('h3').text('Promoter bearbeiten');
        $f.find('[name="promoter_id"]').val($(this).data('id'));
        $f.find('[name="promoter_code"]').val($(this).data('code'));
        $f.find('[name="display_name"]').val($(this).data('name'));
        $f.find('[name="notes"]').val($(this).data('notes'));
    });

    // Save Promoter
    $(document).on('click', '#tix-promo-save', function() {
        var $f = $('#tix-promo-add-form');
        var data = {
            action: 'tix_promoter_save',
            nonce: tixPromoter.nonce,
            promoter_id: $f.find('[name="promoter_id"]').val(),
            user_id: $f.find('[name="user_id"]').val(),
            promoter_code: $f.find('[name="promoter_code"]').val(),
            display_name: $f.find('[name="display_name"]').val(),
            notes: $f.find('[name="notes"]').val()
        };
        $.post(tixPromoter.ajaxurl, data, function(r) {
            if (r.success) {
                $f.removeClass('open');
                loadPromoters();
            } else {
                alert(r.data && r.data.message ? r.data.message : 'Fehler beim Speichern.');
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

    // User Search
    var userSearchTimer;
    $(document).on('input', '#tix-promo-user-search', function() {
        var q = $(this).val();
        clearTimeout(userSearchTimer);
        if (q.length < 2) { $('#tix-promo-user-results').empty().hide(); return; }
        userSearchTimer = setTimeout(function() {
            $.post(tixPromoter.ajaxurl, { action: 'tix_promoter_search_users', nonce: tixPromoter.nonce, q: q }, function(r) {
                if (!r.success || !r.data.length) { $('#tix-promo-user-results').html('<div class="tix-promo-user-item">Kein Benutzer gefunden</div>').show(); return; }
                var html = '';
                $.each(r.data, function(i, u) {
                    html += '<div class="tix-promo-user-item" data-id="' + u.id + '" data-name="' + esc(u.name) + '">' + esc(u.name) + ' (' + esc(u.email) + ')</div>';
                });
                $('#tix-promo-user-results').html(html).show();
            });
        }, 300);
    });

    $(document).on('click', '.tix-promo-user-item[data-id]', function() {
        $('#tix-promo-add-form [name="user_id"]').val($(this).data('id'));
        $('#tix-promo-user-search').val($(this).data('name'));
        $('#tix-promo-user-results').hide();
    });

    /* ════════════════════════════════
       EVENTS TAB
       ════════════════════════════════ */

    function loadAssignments() {
        var $tbody = $('#tix-promo-events-body');
        $tbody.html('<tr><td colspan="8" class="tix-promo-loading"><div class="tix-promo-spinner"></div></td></tr>');

        var filters = {
            action: 'tix_promoter_assignments',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-filter-promoter').val() || '',
            event_id: $('#tix-filter-event').val() || ''
        };

        $.post(tixPromoter.ajaxurl, filters, function(r) {
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="8" class="tix-promo-empty">Keine Zuordnungen vorhanden</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, a) {
                var commLabel = a.commission_type === 'percent' ? a.commission_value + ' %' : eur(a.commission_value);
                var discLabel = '';
                if (a.discount_type === 'percent') discLabel = a.discount_value + ' %';
                else if (a.discount_type === 'fixed') discLabel = eur(a.discount_value);
                else discLabel = '–';

                html += '<tr>' +
                    '<td>' + esc(a.promoter_name || a.promoter_code) + '</td>' +
                    '<td>' + esc(a.event_title || '#' + a.event_id) + '</td>' +
                    '<td>' + commLabel + '</td>' +
                    '<td>' + discLabel + '</td>' +
                    '<td>' + (a.promo_code ? '<code>' + esc(a.promo_code) + '</code>' : '–') + '</td>' +
                    '<td>' + badge(a.status) + '</td>' +
                    '<td class="tix-promo-actions">' +
                        '<button class="tix-promo-btn tix-promo-btn-sm tix-promo-btn-danger tix-promo-unassign" data-id="' + a.id + '" data-coupon="' + (a.coupon_id || 0) + '">Entfernen</button>' +
                    '</td></tr>';
            });
            $tbody.html(html);
        });
    }

    // Filter changes
    $(document).on('change', '#tix-filter-promoter, #tix-filter-event', function() { loadAssignments(); });

    // Assignment Form Toggle
    $(document).on('click', '#tix-promo-assign-btn', function() { $('#tix-promo-assign-form').toggleClass('open'); });

    // Discount type toggle
    $(document).on('change', '#tix-assign-discount-type', function() {
        var show = $(this).val() !== '';
        $('#tix-assign-discount-value-wrap').toggle(show);
    });

    // Save Assignment
    $(document).on('click', '#tix-promo-assign-save', function() {
        var $f = $('#tix-promo-assign-form');
        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_assign',
            nonce: tixPromoter.nonce,
            promoter_id: $f.find('[name="assign_promoter_id"]').val(),
            event_id: $f.find('[name="assign_event_id"]').val(),
            commission_type: $f.find('[name="commission_type"]').val(),
            commission_value: $f.find('[name="commission_value"]').val(),
            discount_type: $f.find('[name="discount_type"]').val(),
            discount_value: $f.find('[name="discount_value"]').val(),
            promo_code: $f.find('[name="promo_code"]').val()
        }, function(r) {
            if (r.success) {
                $f.removeClass('open');
                loadAssignments();
            } else {
                alert(r.data && r.data.message ? r.data.message : 'Fehler.');
            }
        });
    });

    // Unassign
    $(document).on('click', '.tix-promo-unassign', function() {
        if (!confirm('Zuordnung wirklich entfernen?')) return;
        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_unassign',
            nonce: tixPromoter.nonce,
            id: $(this).data('id'),
            coupon_id: $(this).data('coupon')
        }, function() { loadAssignments(); });
    });

    /* ════════════════════════════════
       COMMISSIONS TAB
       ════════════════════════════════ */

    function loadCommissions() {
        var $tbody = $('#tix-promo-commissions-body');
        $tbody.html('<tr><td colspan="8" class="tix-promo-loading"><div class="tix-promo-spinner"></div></td></tr>');

        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_commissions',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-comm-promoter').val() || '',
            event_id: $('#tix-comm-event').val() || '',
            date_from: $('#tix-comm-from').val() || '',
            date_to: $('#tix-comm-to').val() || '',
            status: $('#tix-comm-status').val() || ''
        }, function(r) {
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="8" class="tix-promo-empty">Keine Provisionen vorhanden</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, c) {
                html += '<tr>' +
                    '<td>' + esc(c.date) + '</td>' +
                    '<td>' + esc(c.promoter) + '</td>' +
                    '<td>' + esc(c.event) + '</td>' +
                    '<td>#' + c.order_id + '</td>' +
                    '<td>' + c.tickets_qty + '</td>' +
                    '<td>' + eur(c.order_total) + '</td>' +
                    '<td>' + eur(c.commission_amount) + '</td>' +
                    '<td>' + badge(c.status) + '</td>' +
                    '</tr>';
            });
            $tbody.html(html);
        });
    }

    $(document).on('change', '#tix-comm-promoter, #tix-comm-event, #tix-comm-from, #tix-comm-to, #tix-comm-status', function() { loadCommissions(); });

    /* ════════════════════════════════
       PAYOUTS TAB
       ════════════════════════════════ */

    function loadPayouts() {
        var $tbody = $('#tix-promo-payouts-body');
        $tbody.html('<tr><td colspan="8" class="tix-promo-loading"><div class="tix-promo-spinner"></div></td></tr>');

        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_payouts',
            nonce: tixPromoter.nonce,
            promoter_id: $('#tix-payout-promoter').val() || '',
            status: $('#tix-payout-status').val() || ''
        }, function(r) {
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="8" class="tix-promo-empty">Keine Auszahlungen vorhanden</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, p) {
                var actions = '';
                if (p.status === 'pending') {
                    actions = '<button class="tix-promo-btn tix-promo-btn-sm tix-promo-btn-success tix-promo-mark-paid" data-id="' + p.id + '">Bezahlt</button> ' +
                              '<button class="tix-promo-btn tix-promo-btn-sm tix-promo-btn-danger tix-promo-cancel-payout" data-id="' + p.id + '">Stornieren</button>';
                }
                html += '<tr>' +
                    '<td>' + esc(p.period) + '</td>' +
                    '<td>' + esc(p.promoter) + '</td>' +
                    '<td>' + eur(p.total_sales) + '</td>' +
                    '<td>' + eur(p.total_commission) + '</td>' +
                    '<td>' + p.commission_count + '</td>' +
                    '<td>' + badge(p.status) + '</td>' +
                    '<td>' + (p.paid_date || '–') + '</td>' +
                    '<td class="tix-promo-actions">' + actions + '</td>' +
                    '</tr>';
            });
            $tbody.html(html);
        });
    }

    $(document).on('change', '#tix-payout-promoter, #tix-payout-status', function() { loadPayouts(); });

    // Payout Form Toggle
    $(document).on('click', '#tix-promo-payout-btn', function() { $('#tix-promo-payout-form').toggleClass('open'); });

    // Create Payout
    $(document).on('click', '#tix-promo-payout-save', function() {
        var $f = $('#tix-promo-payout-form');
        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_create_payout',
            nonce: tixPromoter.nonce,
            promoter_id: $f.find('[name="payout_promoter_id"]').val(),
            period_from: $f.find('[name="period_from"]').val(),
            period_to: $f.find('[name="period_to"]').val()
        }, function(r) {
            if (r.success) {
                $f.removeClass('open');
                loadPayouts();
            } else {
                alert(r.data && r.data.message ? r.data.message : 'Fehler.');
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
    $(document).on('click', '#tix-promo-export-csv', function() {
        var form = $('<form>', { method: 'POST', action: tixPromoter.ajaxurl.replace('admin-ajax.php', 'admin-post.php'), target: '_blank' });
        form.append($('<input>', { type: 'hidden', name: 'action', value: 'tix_promoter_export_csv' }));
        form.append($('<input>', { type: 'hidden', name: 'nonce', value: tixPromoter.nonce }));
        form.append($('<input>', { type: 'hidden', name: 'promoter_id', value: $('#tix-payout-promoter').val() || '' }));
        $('body').append(form);
        form.submit();
        form.remove();
    });

    /* ════════════════════════════════
       STATS TAB
       ════════════════════════════════ */

    function loadStats() {
        $.post(tixPromoter.ajaxurl, {
            action: 'tix_promoter_stats',
            nonce: tixPromoter.nonce
        }, function(r) {
            if (!r.success) return;
            var d = r.data;

            // KPIs
            $('#tix-promo-stat-sales').text(eur(d.total_sales));
            $('#tix-promo-stat-commission').text(eur(d.total_commission));
            $('#tix-promo-stat-pending').text(eur(d.pending_commission));
            $('#tix-promo-stat-active').text(d.active_promoters);

            // Top Promoter Chart
            if (d.top_promoters && d.top_promoters.length && window.Chart) {
                var labels = [], sales = [], commissions = [];
                $.each(d.top_promoters, function(i, p) {
                    labels.push(p.name || p.code);
                    sales.push(parseFloat(p.sales));
                    commissions.push(parseFloat(p.commission));
                });

                if (charts['top-promoters']) charts['top-promoters'].destroy();
                var ctx = document.getElementById('tix-promo-chart-top');
                if (ctx) {
                    charts['top-promoters'] = new Chart(ctx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                { label: 'Umsatz', data: sales, backgroundColor: 'rgba(99,102,241,0.7)' },
                                { label: 'Provision', data: commissions, backgroundColor: 'rgba(16,185,129,0.7)' }
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
        });
    }

    /* ════════════════════════════════
       HELPERS
       ════════════════════════════════ */

    function eur(v) {
        v = parseFloat(v) || 0;
        return v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' €';
    }

    function badge(status) {
        return '<span class="tix-promo-badge tix-promo-badge-' + status + '">' + esc(status) + '</span>';
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})(jQuery);
