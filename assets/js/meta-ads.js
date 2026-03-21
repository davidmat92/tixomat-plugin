(function($){
    'use strict';

    var M = tixMeta, chart = null, utmHistory = [];

    /* ── Tab Navigation ── */
    $('.tix-meta-tabs .nav-tab').on('click', function(e){
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.tix-meta-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tix-meta-tab').hide().filter('[data-tab="'+tab+'"]').show();
        if(tab==='dashboard') loadDashboard();
    });

    /* ── Copy Buttons ── */
    $(document).on('click', '.tix-copy-btn', function(){
        var btn = $(this), target = $('#'+btn.data('target'));
        var val = target.val() || target.text();
        navigator.clipboard.writeText(val).then(function(){
            btn.addClass('copied').text('Kopiert!');
            setTimeout(function(){ btn.removeClass('copied').text('Kopieren'); }, 2000);
        });
    });

    /* ══════════════════════════════════════════
       DASHBOARD
    ══════════════════════════════════════════ */
    function loadDashboard(){
        $.post(M.ajax, {
            action: 'tix_meta_dashboard_data',
            nonce: M.nonce,
            days: $('#tix-meta-period').val(),
            event_id: $('#tix-meta-event-filter').val()
        }, function(r){
            if(!r.success) return;
            var d = r.data;

            // KPIs
            $('#kpi-revenue').text(d.kpis.revenue);
            $('#kpi-orders').text(d.kpis.orders);
            $('#kpi-aov').text(d.kpis.aov);
            $('#kpi-roas').text(d.kpis.roas);

            // Chart
            renderChart(d.chart);

            // Event table
            renderEventTable(d.events);
        });
    }

    function renderChart(data){
        var ctx = document.getElementById('tix-meta-chart');
        if(!ctx) return;
        if(chart) chart.destroy();

        chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Umsatz (€)',
                    data: data.revenue,
                    backgroundColor: 'rgba(24,119,242,0.15)',
                    borderColor: '#1877F2',
                    borderWidth: 2,
                    borderRadius: 4,
                    yAxisID: 'y'
                },{
                    label: 'Bestellungen',
                    data: data.orders,
                    type: 'line',
                    borderColor: '#10b981',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 3,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { position: 'left', title: { display: true, text: 'Umsatz (€)' }, beginAtZero: true },
                    y1: { position: 'right', title: { display: true, text: 'Bestellungen' }, beginAtZero: true, grid: { drawOnChartArea: false } }
                },
                plugins: { legend: { position: 'top' } }
            }
        });
    }

    function renderEventTable(events){
        var $tbody = $('#tix-meta-event-rows');
        if(!events.length){
            $tbody.html('<tr><td colspan="5" style="text-align:center;padding:20px;color:#999">Noch keine Conversion-Daten vorhanden</td></tr>');
            return;
        }
        var html = '';
        events.forEach(function(ev){
            html += '<tr>' +
                '<td><strong>' + esc(ev.title) + '</strong></td>' +
                '<td>' + ev.conversions + '</td>' +
                '<td>' + fmt(ev.revenue) + ' €</td>' +
                '<td><input type="number" class="tix-adspend-input" data-event="' + ev.id + '" value="' + ev.spend + '" step="0.01" min="0" placeholder="0,00"> €</td>' +
                '<td>' + (ev.roas > 0 ? ev.roas + 'x' : '—') + '</td>' +
                '</tr>';
        });
        $tbody.html(html);
    }

    // Ad spend update
    $(document).on('change', '.tix-adspend-input', function(){
        var $i = $(this);
        $.post(M.ajax, {
            action: 'tix_meta_update_adspend',
            nonce: M.nonce,
            event_id: $i.data('event'),
            spend: $i.val()
        }, function(){ loadDashboard(); });
    });

    // Period / event filter change
    $('#tix-meta-period, #tix-meta-event-filter').on('change', loadDashboard);

    // Test pixel
    $('#tix-meta-test-btn').on('click', function(){
        var btn = $(this);
        btn.prop('disabled', true).text('Sende…');
        $.post(M.ajax, { action: 'tix_meta_test_pixel', nonce: M.nonce }, function(r){
            btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-plugins" style="margin-top:4px"></span> Test-Event senden');
            alert(r.success ? r.data.message : (r.data ? r.data.message : 'Fehler'));
        });
    });

    /* ══════════════════════════════════════════
       CAMPAIGN WIZARD
    ══════════════════════════════════════════ */
    $('#tix-wizard-event').on('change', function(){
        var val = $(this).val();
        if(val){
            $('#tix-wizard-templates').show();
            $('#tix-wizard-result').hide();
            $('.tix-meta-template').removeClass('active');
        } else {
            $('#tix-wizard-templates, #tix-wizard-result').hide();
        }
    });

    $('.tix-meta-template').on('click', function(){
        var tpl = $(this).data('template');
        var eventId = $('#tix-wizard-event').val();
        if(!eventId) return;

        $('.tix-meta-template').removeClass('active');
        $(this).addClass('active');

        $.post(M.ajax, {
            action: 'tix_meta_wizard_generate',
            nonce: M.nonce,
            event_id: eventId,
            template: tpl
        }, function(r){
            if(!r.success) return;
            var d = r.data;
            $('#tix-wizard-result-title').text(d.title);
            $('#tix-wizard-primary').val(d.primary);
            $('#tix-wizard-headline').val(d.headline);
            $('#tix-wizard-description').val(d.description);
            $('#tix-wizard-link').val(d.tracking_link || '');
            $('#tix-wizard-budget').text(d.budget);

            var audHtml = '<ul>';
            (d.audience || []).forEach(function(a){ audHtml += '<li>' + esc(a) + '</li>'; });
            audHtml += '</ul>';
            $('#tix-wizard-audience').html(audHtml);

            var stepsHtml = '';
            (d.steps || []).forEach(function(s){ stepsHtml += '<li>' + esc(s) + '</li>'; });
            $('#tix-wizard-steps').html(stepsHtml);

            $('#tix-wizard-result').show();
        });
    });

    /* ══════════════════════════════════════════
       UTM LINK GENERATOR
    ══════════════════════════════════════════ */
    $('#tix-utm-generate').on('click', function(){
        var baseUrl = $('#tix-utm-event').val();
        if(!baseUrl){
            alert('Bitte wähle ein Event.');
            return;
        }
        $.post(M.ajax, {
            action: 'tix_meta_generate_utm',
            nonce: M.nonce,
            base_url: baseUrl,
            source: $('#tix-utm-source').val(),
            medium: $('#tix-utm-medium').val(),
            campaign: $('#tix-utm-campaign').val(),
            content: $('#tix-utm-content').val()
        }, function(r){
            if(!r.success) return;
            var url = r.data.url;
            $('#tix-utm-url').val(url);
            $('#tix-utm-result').show();

            // Generate QR code via image
            var qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(url);
            $('#tix-utm-qr').html('<img src="' + qrImg + '" alt="QR Code" style="width:300px;height:300px;border-radius:8px">');

            // Save to history
            utmHistory.unshift({
                url: url,
                source: $('#tix-utm-source').val(),
                medium: $('#tix-utm-medium').val(),
                campaign: $('#tix-utm-campaign').val()
            });
            renderUtmHistory();
        });
    });

    $('#tix-utm-qr-download').on('click', function(){
        var img = $('#tix-utm-qr img');
        if(!img.length) return;
        var a = document.createElement('a');
        a.href = img.attr('src');
        a.download = 'qr-code.png';
        a.target = '_blank';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    });

    function renderUtmHistory(){
        var $tbody = $('#tix-utm-history tbody');
        if(!utmHistory.length) return;
        var html = '';
        utmHistory.slice(0,10).forEach(function(h){
            html += '<tr>' +
                '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><code>' + esc(h.url) + '</code></td>' +
                '<td>' + esc(h.source) + '</td>' +
                '<td>' + esc(h.medium) + '</td>' +
                '<td>' + esc(h.campaign) + '</td>' +
                '<td><button type="button" class="button tix-copy-inline" data-url="' + esc(h.url) + '">Kopieren</button></td>' +
                '</tr>';
        });
        $tbody.html(html);
    }

    $(document).on('click', '.tix-copy-inline', function(){
        var btn = $(this);
        navigator.clipboard.writeText(btn.data('url')).then(function(){
            btn.text('Kopiert!');
            setTimeout(function(){ btn.text('Kopieren'); }, 2000);
        });
    });

    /* ── Helpers ── */
    function esc(s){ return $('<span>').text(s||'').html(); }
    function fmt(n){ return parseFloat(n||0).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.'); }

    // Initial load
    loadDashboard();

})(jQuery);
