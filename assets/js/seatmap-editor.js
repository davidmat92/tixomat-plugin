/**
 * Tixomat – Saalplan Editor (Admin)
 * Visueller Drag & Drop Sitzplan-Editor
 * Unterstützt Theater-, U-Form-, Stadion- und Arena-Layouts
 * @since 1.22.0
 */
(function($) {
    'use strict';

    var config   = window.tixSeatmap || {};
    var sections = [];
    var selectedSeat = null;
    var nextSectionIndex = 0;
    var sectionColors = ['#FF5500','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#14b8a6'];

    // Layout & Stage state
    var currentLayout = 'theater';
    var stageLabel    = 'BÜHNE';

    // Erlaubte Positionen pro Layout-Typ
    var layoutPositions = {
        'theater':  [],
        'u-shape':  ['top', 'left', 'right'],
        'stadium':  ['top', 'bottom', 'left', 'right'],
        'arena':    ['top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right']
    };

    var positionLabels = {
        'center':       'Mitte',
        'top':          'Oben',
        'bottom':       'Unten',
        'left':         'Links',
        'right':        'Rechts',
        'top-left':     'Oben links',
        'top-right':    'Oben rechts',
        'bottom-left':  'Unten links',
        'bottom-right': 'Unten rechts'
    };

    // ── Init ──
    $(function() {
        var $app    = $('#tix-seatmap-app');
        var initial = $app.attr('data-config');

        if (initial) {
            try {
                var parsed = JSON.parse(initial);
                if (parsed && parsed.sections) {
                    sections = parsed.sections;
                    nextSectionIndex = sections.length;
                }
                if (parsed && parsed.layout) {
                    currentLayout = parsed.layout;
                }
                if (parsed && parsed.stage_label) {
                    stageLabel = parsed.stage_label;
                }
            } catch(e) {}
        }

        renderAll();
        bindGlobalEvents();
    });

    // ── Render ──
    function renderAll() {
        var $container = $('#tix-sm-sections');
        $container.empty();

        // Layout-Toolbar aktualisieren
        $('#tix-sm-layout-select').val(currentLayout);
        $('#tix-sm-stage-label').val(stageLabel);

        // Stage-Label aktualisieren
        $('.tix-sm-stage span').text(stageLabel);

        // Layout-spezifische Controls zeigen/verstecken
        if (currentLayout !== 'theater') {
            $('#tix-sm-stage-label-wrap').show();
            $('#tix-sm-overview-wrap').show();
        } else {
            $('#tix-sm-stage-label-wrap').hide();
            $('#tix-sm-overview-wrap').hide();
        }

        // Mini-Map rendern
        renderOverviewMap();

        if (sections.length === 0) {
            $container.html(
                '<div class="tix-sm-empty">' +
                    '<span class="dashicons dashicons-layout"></span>' +
                    '<p>Noch keine Sektionen. Klicke auf "Sektion" um zu beginnen.</p>' +
                '</div>'
            );
        } else {
            sections.forEach(function(section, si) {
                $container.append(renderSection(section, si));
            });
        }

        updateStats();
        syncHiddenInput();
    }

    // ── Overview Map (Mini-Map) ──
    function renderOverviewMap() {
        var $wrap = $('#tix-sm-overview-wrap');
        if (currentLayout === 'theater') {
            $wrap.hide();
            return;
        }

        var positions = layoutPositions[currentLayout] || [];
        var gridAreas = {
            'top-left':     'tl',
            'top':          'top',
            'top-right':    'tr',
            'left':         'left',
            'right':        'right',
            'bottom-left':  'bl',
            'bottom':       'bottom',
            'bottom-right': 'br'
        };

        // Sektionen nach Position gruppieren
        var byPos = {};
        positions.forEach(function(p) { byPos[p] = []; });
        sections.forEach(function(s) {
            var pos = s.position || 'center';
            if (byPos[pos]) byPos[pos].push(s);
        });

        var html = '<div class="tix-sm-overview">';

        // 3×3 Grid: tl, top, tr / left, stage, right / bl, bottom, br
        var cells = ['top-left','top','top-right','left','stage','right','bottom-left','bottom','bottom-right'];
        cells.forEach(function(cell) {
            if (cell === 'stage') {
                html += '<div class="tix-sm-ov-stage">' + escHtml(stageLabel) + '</div>';
                return;
            }

            var area = gridAreas[cell] || cell;
            var cellSections = byPos[cell] || [];

            if (positions.indexOf(cell) === -1) {
                // Position nicht verfügbar in diesem Layout
                html += '<div class="tix-sm-ov-cell tix-sm-ov-empty" data-area="' + area + '"></div>';
            } else if (cellSections.length === 0) {
                html += '<div class="tix-sm-ov-cell tix-sm-ov-available" data-area="' + area + '" data-pos="' + cell + '">' +
                    '<span class="tix-sm-ov-placeholder">+ ' + positionLabels[cell] + '</span>' +
                    '</div>';
            } else {
                html += '<div class="tix-sm-ov-cell" data-area="' + area + '">';
                cellSections.forEach(function(s) {
                    var seatCount = 0;
                    (s.rows || []).forEach(function(r) {
                        (r.seats || []).forEach(function(seat) { if (seat.type !== 'blocked') seatCount++; });
                    });
                    html += '<div class="tix-sm-ov-block" data-section-id="' + s.id + '" style="background:' + (s.color || '#FF5500') + '">' +
                        '<div class="tix-sm-ov-label">' + escHtml(s.label || s.id) + '</div>' +
                        '<div class="tix-sm-ov-seats">' + seatCount + ' Sitze</div>' +
                        '</div>';
                });
                html += '</div>';
            }
        });

        html += '</div>';
        $wrap.html(html).show();
    }

    function renderSection(section, si) {
        var color = section.color || sectionColors[si % sectionColors.length];
        var seatCount = 0;
        section.rows.forEach(function(r) {
            r.seats.forEach(function(s) { if (s.type !== 'blocked') seatCount++; });
        });

        var $sec = $('<div class="tix-sm-section" data-index="' + si + '" id="tix-sm-section-' + section.id + '" style="--section-color:' + color + '">');

        // Header
        var posDropdown = '';
        if (currentLayout !== 'theater') {
            var positions = layoutPositions[currentLayout] || [];
            var curPos = section.position || 'center';
            posDropdown =
                '<select class="tix-sm-section-position" data-si="' + si + '" title="Position im Layout">' +
                '<option value="center"' + (curPos === 'center' ? ' selected' : '') + '>– Position –</option>';
            positions.forEach(function(p) {
                posDropdown += '<option value="' + p + '"' + (curPos === p ? ' selected' : '') + '>' + positionLabels[p] + '</option>';
            });
            posDropdown += '</select>';
        }

        // Preis + Kategorie-Name Felder
        var priceVal = section.price || '';
        var catName  = section.category_name || '';
        var pricingFields =
            '<input type="number" class="tix-sm-section-price" value="' + (priceVal || '') + '" ' +
                'data-si="' + si + '" placeholder="Preis €" step="0.01" min="0" ' +
                'style="width:80px;font-size:12px;" title="Ticket-Preis für diese Sektion">' +
            '<input type="text" class="tix-sm-section-catname" value="' + escAttr(catName) + '" ' +
                'data-si="' + si + '" placeholder="Kategorie-Name" ' +
                'style="width:120px;font-size:12px;" title="Ticket-Kategorie-Name (wenn leer: Sektionsname)">';

        var $header = $(
            '<div class="tix-sm-section-header">' +
                '<div class="tix-sm-section-header-left">' +
                    '<input type="text" class="tix-sm-section-label" value="' + escAttr(section.label) + '" placeholder="Sektionsname..." data-si="' + si + '">' +
                    '<input type="color" class="tix-sm-section-color" value="' + color + '" data-si="' + si + '" title="Farbe">' +
                    posDropdown +
                    pricingFields +
                    '<span class="tix-sm-section-seats-count">' + seatCount + ' Sitze</span>' +
                '</div>' +
                '<div class="tix-sm-section-actions">' +
                    '<button type="button" class="tix-sm-btn-add-rows" data-si="' + si + '" title="Reihen hinzufügen">+R</button>' +
                    '<button type="button" class="tix-sm-btn-delete-section" data-si="' + si + '" title="Sektion löschen">✕</button>' +
                '</div>' +
            '</div>'
        );
        $sec.append($header);

        // Body: Rows
        var $body = $('<div class="tix-sm-section-body">');
        section.rows.forEach(function(row, ri) {
            $body.append(renderRow(row, si, ri));
        });

        // Add Row Button
        $body.append('<button type="button" class="tix-sm-btn-add-row" data-si="' + si + '">+ Reihe hinzufügen</button>');
        $sec.append($body);

        return $sec;
    }

    function renderRow(row, si, ri) {
        var $row = $('<div class="tix-sm-row" data-si="' + si + '" data-ri="' + ri + '">');
        $row.append('<span class="tix-sm-row-label">' + escHtml(row.id) + '</span>');

        var $seats = $('<div class="tix-sm-row-seats">');
        row.seats.forEach(function(seat, sei) {
            var isSelected = selectedSeat && selectedSeat.si === si && selectedSeat.ri === ri && selectedSeat.sei === sei;
            var cls = 'tix-sm-seat' + (isSelected ? ' selected' : '');
            var seatNum = seat.id.replace(/^.*?(\d+)$/, '$1');
            $seats.append(
                '<div class="' + cls + '" data-type="' + seat.type + '" ' +
                'data-si="' + si + '" data-ri="' + ri + '" data-sei="' + sei + '" ' +
                'title="' + escAttr(seat.id) + ' (' + seat.type + ')">' +
                seatNum +
                '</div>'
            );
        });
        $row.append($seats);

        $row.append(
            '<div class="tix-sm-row-actions">' +
                '<button type="button" class="tix-sm-btn-delete-row" data-si="' + si + '" data-ri="' + ri + '" title="Reihe löschen">✕</button>' +
            '</div>'
        );

        return $row;
    }

    // ── Events ──
    function bindGlobalEvents() {
        var $app = $('#tix-seatmap-app');

        // Layout-Typ wechseln
        $app.on('change', '#tix-sm-layout-select', function() {
            currentLayout = $(this).val();
            renderAll();
        });

        // Stage-Label
        $app.on('input', '#tix-sm-stage-label', function() {
            stageLabel = $(this).val() || 'BÜHNE';
            $('.tix-sm-stage span').text(stageLabel);
            renderOverviewMap();
            syncHiddenInput();
        });

        // Section Position
        $app.on('change', '.tix-sm-section-position', function() {
            var si = parseInt($(this).data('si'));
            sections[si].position = $(this).val();
            renderOverviewMap();
            syncHiddenInput();
        });

        // Overview-Block Klick → zur Sektion scrollen
        $app.on('click', '.tix-sm-ov-block', function() {
            var sectionId = $(this).data('section-id');
            var $target = $('#tix-sm-section-' + sectionId);
            if ($target.length) {
                $('html, body').animate({ scrollTop: $target.offset().top - 100 }, 400);
                $target.addClass('tix-sm-highlight');
                setTimeout(function() { $target.removeClass('tix-sm-highlight'); }, 1500);
            }
        });

        // Add Section
        $app.on('click', '.tix-sm-btn-add-section', function() {
            var seatsPerRow = parseInt($('#tix-sm-seats-per-row').val()) || 10;
            var rowsCount   = parseInt($('#tix-sm-rows-count').val()) || 5;
            addSection(seatsPerRow, rowsCount);
        });

        // Delete Section
        $app.on('click', '.tix-sm-btn-delete-section', function() {
            if (!confirm(config.i18n.confirmDelete)) return;
            var si = parseInt($(this).data('si'));
            sections.splice(si, 1);
            selectedSeat = null;
            renderAll();
        });

        // Section Label
        $app.on('input', '.tix-sm-section-label', function() {
            var si = parseInt($(this).data('si'));
            sections[si].label = $(this).val();
            renderOverviewMap();
            syncHiddenInput();
        });

        // Section Color
        $app.on('input', '.tix-sm-section-color', function() {
            var si = parseInt($(this).data('si'));
            sections[si].color = $(this).val();
            $(this).closest('.tix-sm-section').css('--section-color', $(this).val());
            renderOverviewMap();
            syncHiddenInput();
        });

        // Section Price
        $app.on('input', '.tix-sm-section-price', function() {
            var si = parseInt($(this).data('si'));
            sections[si].price = parseFloat($(this).val()) || 0;
            syncHiddenInput();
        });

        // Section Category Name
        $app.on('input', '.tix-sm-section-catname', function() {
            var si = parseInt($(this).data('si'));
            sections[si].category_name = $(this).val();
            syncHiddenInput();
        });

        // Add Rows to Section
        $app.on('click', '.tix-sm-btn-add-rows', function() {
            var si          = parseInt($(this).data('si'));
            var seatsPerRow = parseInt($('#tix-sm-seats-per-row').val()) || 10;
            var rowsCount   = parseInt($('#tix-sm-rows-count').val()) || 1;
            addRows(si, rowsCount, seatsPerRow);
        });

        // Add Single Row
        $app.on('click', '.tix-sm-btn-add-row', function() {
            var si          = parseInt($(this).data('si'));
            var seatsPerRow = parseInt($('#tix-sm-seats-per-row').val()) || 10;
            addRows(si, 1, seatsPerRow);
        });

        // Delete Row
        $app.on('click', '.tix-sm-btn-delete-row', function() {
            var si = parseInt($(this).data('si'));
            var ri = parseInt($(this).data('ri'));
            sections[si].rows.splice(ri, 1);
            selectedSeat = null;
            renderAll();
        });

        // Select Seat
        $app.on('click', '.tix-sm-seat', function() {
            var si  = parseInt($(this).data('si'));
            var ri  = parseInt($(this).data('ri'));
            var sei = parseInt($(this).data('sei'));
            selectSeat(si, ri, sei);
        });

        // Props: Type
        $('#tix-sm-prop-type').on('change', function() {
            if (!selectedSeat) return;
            var s = sections[selectedSeat.si].rows[selectedSeat.ri].seats[selectedSeat.sei];
            s.type = $(this).val();
            renderAll();
            selectSeat(selectedSeat.si, selectedSeat.ri, selectedSeat.sei);
        });

        // Props: Label
        $('#tix-sm-prop-label').on('input', function() {
            if (!selectedSeat) return;
            var s = sections[selectedSeat.si].rows[selectedSeat.ri].seats[selectedSeat.sei];
            s.id = $(this).val();
            syncHiddenInput();
        });

        // Save Button
        $app.on('click', '.tix-sm-btn-save', function() {
            saveSeatmap($(this));
        });
    }

    // ── Actions ──
    function addSection(seatsPerRow, rowsCount) {
        var id    = 'section_' + (nextSectionIndex + 1);
        var label = 'Sektion ' + (nextSectionIndex + 1);
        var color = sectionColors[nextSectionIndex % sectionColors.length];

        var section = {
            id:            id,
            label:         label,
            color:         color,
            position:      'center',
            price:         0,
            category_name: '',
            rows:          []
        };

        // Reihen generieren
        for (var r = 0; r < rowsCount; r++) {
            var rowId = String.fromCharCode(65 + r); // A, B, C, ...
            if (r >= 26) rowId = 'R' + (r + 1);     // R27, R28, ...
            var seats = [];
            for (var s = 1; s <= seatsPerRow; s++) {
                seats.push({
                    id:   id + '_' + rowId + s,
                    x:    0,
                    y:    0,
                    type: 'standard'
                });
            }
            section.rows.push({ id: rowId, seats: seats });
        }

        sections.push(section);
        nextSectionIndex++;
        renderAll();
    }

    function addRows(si, count, seatsPerRow) {
        var section = sections[si];
        var existingRows = section.rows.length;

        for (var r = 0; r < count; r++) {
            var idx   = existingRows + r;
            var rowId = String.fromCharCode(65 + idx);
            if (idx >= 26) rowId = 'R' + (idx + 1);
            var seats = [];
            for (var s = 1; s <= seatsPerRow; s++) {
                seats.push({
                    id:   section.id + '_' + rowId + s,
                    x:    0,
                    y:    0,
                    type: 'standard'
                });
            }
            section.rows.push({ id: rowId, seats: seats });
        }

        renderAll();
    }

    function selectSeat(si, ri, sei) {
        selectedSeat = { si: si, ri: ri, sei: sei };
        var seat = sections[si].rows[ri].seats[sei];

        // Highlight
        $('.tix-sm-seat').removeClass('selected');
        $('.tix-sm-seat[data-si="' + si + '"][data-ri="' + ri + '"][data-sei="' + sei + '"]').addClass('selected');

        // Props Panel
        var $props = $('#tix-sm-props');
        $props.show();
        $('#tix-sm-prop-id').val(seat.id);
        $('#tix-sm-prop-label').val(seat.id);
        $('#tix-sm-prop-type').val(seat.type);
    }

    // ── Save ──
    function saveSeatmap($btn) {
        $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-saved').addClass('dashicons-update spin');

        var data = {
            action:  'tix_seatmap_save',
            nonce:   config.nonce,
            post_id: config.postId,
            data:    JSON.stringify(buildData())
        };

        $.post(config.ajaxUrl, data, function(response) {
            $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-saved');

            if (response.success) {
                var msg = config.i18n.saved;
                if (response.data && response.data.products) {
                    var prodCount = Object.keys(response.data.products).length;
                    if (prodCount > 0) msg += ' (' + prodCount + ' Produkte synchronisiert)';
                }
                showToast(msg);
                if (response.data && response.data.seats !== undefined) {
                    $('#tix-sm-total-seats').text(response.data.seats);
                }
            } else {
                showToast(config.i18n.error, true);
            }
        }).fail(function() {
            $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-saved');
            showToast(config.i18n.error, true);
        });
    }

    // ── Build Data ──
    function buildData() {
        return {
            width:       800,
            height:      600,
            layout:      currentLayout,
            stage_label: stageLabel,
            sections:    sections
        };
    }

    // ── Sync Hidden Input ──
    function syncHiddenInput() {
        $('#tix-seatmap-data').val(JSON.stringify(buildData()));
    }

    // ── Update Stats ──
    function updateStats() {
        var total = 0;
        sections.forEach(function(s) {
            s.rows.forEach(function(r) {
                r.seats.forEach(function(seat) {
                    if (seat.type !== 'blocked') total++;
                });
            });
        });
        $('#tix-sm-total-seats').text(total);
    }

    // ── Toast ──
    function showToast(msg, isError) {
        var $toast = $('<div class="tix-sm-toast' + (isError ? ' error' : '') + '">' + escHtml(msg) + '</div>');
        $('body').append($toast);
        setTimeout(function() { $toast.fadeOut(300, function() { $(this).remove(); }); }, 2500);
    }

    // ── Helpers ──
    function escHtml(str) {
        return $('<span>').text(str || '').html();
    }
    function escAttr(str) {
        return (str || '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

})(jQuery);
