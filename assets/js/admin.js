(function($) {
    'use strict';

    $(function() {

        // HTML5-Validierung deaktivieren — blockiert Form-Submit still ohne
        // sichtbare Fehlermeldung. Eigene Validierung im Wizard mit Feedback.
        $('#post').attr('novalidate', 'novalidate');

        // ══════════════════════════════════════
        // TAB NAVIGATION
        // ══════════════════════════════════════
        var $tabs = $('.tix-nav-tab');
        var $panes = $('.tix-pane');

        $tabs.on('click', function() {
            var tab = $(this).data('tab');

            $tabs.removeClass('active');
            $(this).addClass('active');

            $panes.removeClass('active');
            $panes.filter('[data-pane="' + tab + '"]').addClass('active');

            // Save active tab to sessionStorage
            if (window.sessionStorage) {
                sessionStorage.setItem('tix_active_tab', tab);
            }
        });

        // Restore active tab from sessionStorage
        if (window.sessionStorage) {
            var saved = sessionStorage.getItem('tix_active_tab');
            if (saved && $tabs.filter('[data-tab="' + saved + '"]').length) {
                $tabs.filter('[data-tab="' + saved + '"]').trigger('click');
            }
        }

        // ══════════════════════════════════════
        // MORE-TABS TOGGLE
        // ══════════════════════════════════════
        var moreBtn = document.getElementById('tix-nav-more-btn');
        var moreGroup = document.getElementById('tix-nav-more');
        if (moreBtn && moreGroup) {
            // Auto-expand if a tab within "more" group is active (e.g. from sessionStorage)
            var activeInMore = moreGroup.querySelector('.tix-nav-tab.active');
            if (activeInMore) moreGroup.classList.add('tix-nav-group-open');

            moreBtn.addEventListener('click', function() {
                moreGroup.classList.toggle('tix-nav-group-open');
            });
        }

        // ══════════════════════════════════════
        // PFLICHTFELD-FORTSCHRITTSLEISTE
        // ══════════════════════════════════════

        function checkRequiredFields() {
            var fields = [
                { name: 'Titel',         tab: 'details', ok: !!$('#title').val() && $('#title').val() !== 'Automatischer Entwurf' },
                { name: 'Startdatum',    tab: 'details', ok: !!$('input[name="tix_date_start"]').val() },
                { name: 'Startzeit',     tab: 'details', ok: !!$('input[name="tix_time_start"]').val() },
                { name: 'Location',      tab: 'details', ok: !!$('#tix_location_id').val() },
            ];

            // Ticket-Pflichtfeld nur wenn Tickets aktiviert
            var ticketsOn = $('#tix-tickets-toggle').is(':checked');
            if (ticketsOn) {
                fields.push({
                    name: 'Mind. 1 Ticket',
                    tab: 'tickets',
                    ok: $tbody.find('tr.tix-row').length > 0
                });
            }

            var total = fields.length;
            var done = 0;
            var missing = [];
            var tabMissing = {};

            fields.forEach(function(f) {
                if (f.ok) {
                    done++;
                } else {
                    missing.push(f.name);
                    tabMissing[f.tab] = (tabMissing[f.tab] || 0) + 1;
                }
            });

            var pct = total > 0 ? Math.round((done / total) * 100) : 0;
            $('#tix-prog-done').text(done);
            $('#tix-prog-total').text(total);
            $('#tix-prog-fill').css('width', pct + '%');
            $('#tix-progress').toggleClass('complete', done === total);

            // Chips für fehlende Felder
            var chips = '';
            missing.forEach(function(name) {
                chips += '<span class="tix-progress-chip">' + name + '</span>';
            });
            $('#tix-prog-items').html(chips);

            // Tab-Badges aktualisieren
            $tabs.find('.tix-tab-badge').remove();
            $.each(tabMissing, function(tab, count) {
                $tabs.filter('[data-tab="' + tab + '"]').find('.tix-nav-label')
                    .append('<span class="tix-tab-badge">' + count + '</span>');
            });
        }

        // Events die Pflichtfelder-Prüfung triggern
        $(document).on('input change', '#title, #tix-expert-title, input[name="tix_date_start"], input[name="tix_time_start"], #tix_location_id, #tix-tickets-toggle', checkRequiredFields);


        // Location-Adresse anzeigen wenn Location-Dropdown sich ändert
        $('#tix_location_id').on('change', function() {
            var $sel = $(this).find(':selected');
            var addr = $sel.data('address') || '';
            $('#tix_location_address_display').val(addr);
        });

        // Initial prüfen
        setTimeout(checkRequiredFields, 300);

        // ══════════════════════════════════════
        // TICKET SECTION
        // ══════════════════════════════════════

        var $tbody = $('#tix-ticket-rows');

        // Tickets aktivieren/deaktivieren
        $('#tix-tickets-enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#tix-tickets-panel').slideDown(200);
            } else {
                $('#tix-tickets-panel').slideUp(200);
            }
        });

        // ══════════════════════════════════════
        // Vorverkauf Toggle
        // ══════════════════════════════════════
        $('#tix-presale-toggle').on('change', function() {
            var active = $(this).is(':checked');
            var $bar = $('#tix-presale-bar');
            var $dot  = $bar.find('.tix-presale-dot');
            var $text = $bar.find('.tix-presale-text strong');

            if (active) {
                $dot.removeClass('tix-dot-ended').addClass('tix-dot-active');
                $text.text('Aktiv');
            } else {
                $dot.removeClass('tix-dot-active').addClass('tix-dot-ended');
                $text.text('Beendet');
            }
        });

        // ══════════════════════════════════════
        // Presale-End-Modus Toggle
        // ══════════════════════════════════════
        $('#tix-presale-end-mode').on('change', function() {
            var mode = $(this).val();
            $('#tix-presale-end-offset-wrap').toggle(mode === 'before_event');
            $('#tix-presale-end-fixed-wrap').toggle(mode === 'fixed');
        });

        // ══════════════════════════════════════
        // Verkauf-Dropdown: Zeile dimmen/färben
        // ══════════════════════════════════════
        $(document).on('change', '.tix-sale-mode', function() {
            var $row = $(this).closest('tr');
            var val = $(this).val();
            $row.removeClass('tix-row-offline tix-row-offline-ticket');
            if (val === 'off') $row.addClass('tix-row-offline');
            else if (val === 'offline') $row.addClass('tix-row-offline-ticket');
        });

        // ══════════════════════════════════════
        // Bild hochladen
        // ══════════════════════════════════════
        $(document).on('click', '.tix-img-box', function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                alert('Media Library konnte nicht geladen werden.');
                return;
            }

            var $box   = $(this);
            var $cell  = $box.closest('td');
            var $input = $cell.find('.tix-img-val');

            var frame = wp.media({
                title:    'Kategorie-Bild wählen',
                button:   { text: 'Bild verwenden' },
                multiple: false,
                library:  { type: 'image' }
            });

            frame.on('select', function() {
                var att = frame.state().get('selection').first().toJSON();
                var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                $input.val(att.id);
                $box.addClass('has-img').html('<img src="' + url + '">');
                if (!$cell.find('.tix-img-clear').length) {
                    $cell.find('.tix-img-wrap').append('<a href="#" class="tix-img-clear">entfernen</a>');
                }
            });
            frame.open();
        });

        // Bild entfernen
        $(document).on('click', '.tix-img-clear', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $cell  = $(this).closest('td');
            $cell.find('.tix-img-val').val('');
            $cell.find('.tix-img-box').removeClass('has-img').html('<span class="dashicons dashicons-format-image"></span>');
            $(this).remove();
        });

        // ══════════════════════════════════════
        // Zeile hinzufügen
        // ══════════════════════════════════════
        $('#tix-add-row').on('click', function() {
            var i = $tbody.find('tr.tix-row').length;
            var html =
                '<tr class="tix-row">' +
                    '<td><input type="text" name="tix_tickets[' + i + '][name]" placeholder="z.B. VIP" style="width:100%">' +
                        '<input type="text" name="tix_tickets[' + i + '][group]" class="tix-group-input" placeholder="Gruppe (optional)">' +
                        '<a href="#" class="tix-phases-toggle" title="Preisphasen">⏱ Phasen</a>' +
                        '<a href="#" class="tix-bundle-toggle" title="Bundle-Angebot">🎁 Paket</a>' +
                        '<div class="tix-bundle-fields" style="display:none;">' +
                            '<label style="font-size:11px;color:#888;">Kaufe <input type="number" name="tix_tickets[' + i + '][bundle_buy]" min="2" step="1" class="tix-input-sm" style="width:50px" placeholder="11"></label>' +
                            '<label style="font-size:11px;color:#888;">zahle <input type="number" name="tix_tickets[' + i + '][bundle_pay]" min="1" step="1" class="tix-input-sm" style="width:50px" placeholder="10"></label>' +
                            '<label style="font-size:11px;color:#888;">Label <input type="text" name="tix_tickets[' + i + '][bundle_label]" class="tix-input-sm" style="width:160px" placeholder="z.B. Mannschafts-Ticket"></label>' +
                        '</div>' +
                    '</td>' +
                    '<td><input type="number" name="tix_tickets[' + i + '][price]" step="0.01" min="0" style="width:100%"></td>' +
                    '<td><input type="number" name="tix_tickets[' + i + '][sale_price]" step="0.01" min="0" placeholder="—" style="width:100%" class="tix-sale-input"></td>' +
                    '<td><input type="number" name="tix_tickets[' + i + '][qty]" min="1" style="width:100%"></td>' +
                    '<td><input type="text" name="tix_tickets[' + i + '][desc]" placeholder="Optional" style="width:100%"></td>' +
                    '<td class="tix-img-cell"><div class="tix-img-wrap"><div class="tix-img-box" data-i="' + i + '"><span class="dashicons dashicons-format-image"></span></div><input type="hidden" name="tix_tickets[' + i + '][image_id]" class="tix-img-val" value=""></div></td>' +
                    '<td><select name="tix_tickets[' + i + '][sale_mode]" class="tix-sale-mode"><option value="online">🌐 Online</option><option value="offline">🏪 Abendkasse</option><option value="off">⛔ Aus</option></select></td>' +
                    '<td class="tix-stock-cell"><em style="color:#bbb;font-size:12px">—</em></td>' +
                    '<td><input type="hidden" name="tix_tickets[' + i + '][tc_event_id]" value=""><input type="hidden" name="tix_tickets[' + i + '][product_id]" value=""><input type="hidden" name="tix_tickets[' + i + '][sku]" value=""><input type="hidden" name="tix_tickets[' + i + '][seatmap_id]" value="" class="tix-seatmap-id-input"><input type="hidden" name="tix_tickets[' + i + '][seatmap_section]" value="" class="tix-seatmap-section-input"><button type="button" class="button tix-del" title="Entfernen">&times;</button></td>' +
                '</tr>' +
                '<tr class="tix-phases-row" style="display:none;">' +
                    '<td colspan="9"><div class="tix-phases-wrap">' +
                        '<div class="tix-phases-header"><strong>⏱ Preisphasen</strong><span class="tix-phases-hint">Phasen werden chronologisch abgearbeitet. Nach der letzten Phase gilt der Standardpreis.</span></div>' +
                        '<table class="tix-phases-table"><thead><tr><th style="width:30%">Phasenname</th><th style="width:20%">Preis (€)</th><th style="width:30%">Gültig bis</th><th style="width:10%">Status</th><th style="width:10%"></th></tr></thead>' +
                        '<tbody class="tix-phases-body"><tr class="tix-phases-empty"><td colspan="5"><em>Keine Phasen definiert. Klicke „+ Phase" um eine Preisphase hinzuzufügen.</em></td></tr></tbody></table>' +
                        '<button type="button" class="button tix-phase-add" style="margin-top:6px;">+ Phase hinzufügen</button>' +
                    '</div></td>' +
                '</tr>';
            $tbody.append(html);
            checkRequiredFields();
        });

        // ══════════════════════════════════════
        // Zeile entfernen
        // ══════════════════════════════════════
        $(document).on('click', '.tix-del', function() {
            var $row = $(this).closest('tr.tix-row');
            var $phasesRow = $row.next('.tix-phases-row');
            if ($row.find('.tix-badge').length > 0) {
                if (!confirm('Bereits synchronisiert. Bei nächstem Speichern werden die verknüpften Einträge NICHT automatisch gelöscht.\n\nTrotzdem entfernen?')) return;
            }
            if ($tbody.find('tr.tix-row').length <= 1) {
                alert('Mindestens eine Kategorie.');
                return;
            }
            $phasesRow.remove();
            $row.remove();
            reindex();
            checkRequiredFields();
        });

        // Start-Datum → End-Datum
        $('input[name="tix_date_start"]').on('change', function() {
            var $end = $('input[name="tix_date_end"]');
            if (!$end.val()) $end.val($(this).val());
        });

        // ══════════════════════════════════════
        // PREISPHASEN
        // ══════════════════════════════════════

        // Toggle Phasen-Zeile
        $(document).on('click', '.tix-phases-toggle', function(e) {
            e.preventDefault();
            var $row = $(this).closest('tr.tix-row');
            var $phasesRow = $row.next('.tix-phases-row');
            $phasesRow.toggle();
        });

        // Phase hinzufügen
        $(document).on('click', '.tix-phase-add', function() {
            var $body = $(this).closest('.tix-phases-wrap').find('.tix-phases-body');
            $body.find('.tix-phases-empty').remove();

            var $phasesRow = $(this).closest('.tix-phases-row');
            var $ticketRow = $phasesRow.prev('.tix-row');

            var firstInput = $ticketRow.find('input[name^="tix_tickets"]').first();
            var match = firstInput.attr('name').match(/tix_tickets\[(\d+)\]/);
            var ti = match ? match[1] : 0;

            var pi = $body.find('.tix-phase-row').length;
            var html =
                '<tr class="tix-phase-row">' +
                    '<td><input type="text" name="tix_tickets[' + ti + '][phases][' + pi + '][name]" placeholder="z.B. Early Bird" style="width:100%"></td>' +
                    '<td><input type="number" name="tix_tickets[' + ti + '][phases][' + pi + '][price]" step="0.01" min="0" style="width:100%"></td>' +
                    '<td><input type="date" name="tix_tickets[' + ti + '][phases][' + pi + '][until]" style="width:100%"></td>' +
                    '<td><span class="tix-phase-status">○ Neu</span></td>' +
                    '<td><button type="button" class="button tix-phase-del" title="Phase entfernen">&times;</button></td>' +
                '</tr>';
            $body.append(html);
        });

        // Phase entfernen
        $(document).on('click', '.tix-phase-del', function() {
            var $body = $(this).closest('.tix-phases-body');
            $(this).closest('tr').remove();
            reindexPhases($body);
            if ($body.find('.tix-phase-row').length === 0) {
                $body.append('<tr class="tix-phases-empty"><td colspan="5"><em>Keine Phasen definiert.</em></td></tr>');
            }
        });

        function reindexPhases($body) {
            var $phasesRow = $body.closest('.tix-phases-row');
            var $ticketRow = $phasesRow.prev('.tix-row');
            var firstInput = $ticketRow.find('input[name^="tix_tickets"]').first();
            var match = firstInput.attr('name').match(/tix_tickets\[(\d+)\]/);
            var ti = match ? match[1] : 0;

            $body.find('.tix-phase-row').each(function(pi) {
                $(this).find('input').each(function() {
                    var name = $(this).attr('name');
                    if (name) {
                        $(this).attr('name', name.replace(/tix_tickets\[\d+\]\[phases\]\[\d+\]/, 'tix_tickets[' + ti + '][phases][' + pi + ']'));
                    }
                });
            });
        }

        // ══════════════════════════════════════
        // FAQ REPEATER
        // ══════════════════════════════════════
        var $faqBody = $('#tix-faq-rows');

        $('#tix-faq-add').on('click', function() {
            $faqBody.find('.tix-faq-empty').remove();

            var i = $faqBody.find('.tix-faq-row').length;
            var html =
                '<tr class="tix-faq-row" draggable="true">' +
                    '<td class="tix-faq-drag" title="Reihenfolge ändern">☰</td>' +
                    '<td><input type="text" name="tix_faq[' + i + '][q]" placeholder="Frage eingeben…" style="width:100%"></td>' +
                    '<td><textarea name="tix_faq[' + i + '][a]" rows="2" placeholder="Antwort eingeben…" style="width:100%"></textarea></td>' +
                    '<td><button type="button" class="button tix-faq-del" title="Entfernen">&times;</button></td>' +
                '</tr>';
            $faqBody.append(html);
        });

        $(document).on('click', '.tix-faq-del', function() {
            $(this).closest('tr').remove();
            reindexFaq();
        });

        // Drag & Drop
        var dragRow = null;
        $faqBody.on('dragstart', '.tix-faq-row', function(e) {
            dragRow = this;
            $(this).addClass('tix-faq-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
        });
        $faqBody.on('dragover', '.tix-faq-row', function(e) {
            e.preventDefault();
            var $target = $(this);
            if (this !== dragRow) {
                var rect = this.getBoundingClientRect();
                var mid = rect.top + rect.height / 2;
                if (e.originalEvent.clientY < mid) {
                    $target.before(dragRow);
                } else {
                    $target.after(dragRow);
                }
            }
        });
        $faqBody.on('dragend', '.tix-faq-row', function() {
            $(this).removeClass('tix-faq-dragging');
            dragRow = null;
            reindexFaq();
        });

        function reindexFaq() {
            $faqBody.find('.tix-faq-row').each(function(idx) {
                $(this).find('input, textarea').each(function() {
                    var name = $(this).attr('name');
                    if (name) $(this).attr('name', name.replace(/tix_faq\[\d+\]/, 'tix_faq[' + idx + ']'));
                });
            });
        }

        function reindex() {
            $tbody.find('tr.tix-row').each(function(idx) {
                var $row = $(this);
                var $phasesRow = $row.next('.tix-phases-row');

                $row.find('input, select, textarea').each(function() {
                    var name = $(this).attr('name');
                    if (name) $(this).attr('name', name.replace(/tix_tickets\[\d+\]/, 'tix_tickets[' + idx + ']'));
                });
                $row.find('.tix-img-box').attr('data-i', idx);

                $phasesRow.find('input, select, textarea').each(function() {
                    var name = $(this).attr('name');
                    if (name) $(this).attr('name', name.replace(/tix_tickets\[\d+\]/, 'tix_tickets[' + idx + ']'));
                });
            });
        }
    });

    // ═══════════════════════════════════════
    // UPSELL EVENT-AUSWAHL
    // ═══════════════════════════════════════

    $('#tix-upsell-disabled').on('change', function() {
        var $opts = $('#tix-upsell-options');
        if ($(this).is(':checked')) {
            $opts.css({opacity: 0.35, 'pointer-events': 'none'});
        } else {
            $opts.css({opacity: 1, 'pointer-events': ''});
        }
    });

    $('#tix-upsell-select').on('change', function() {
        var $sel = $(this);
        var id = $sel.val();
        if (!id) return;

        var $opt   = $sel.find('option:selected');
        var title  = $opt.data('title');
        var date   = $opt.data('date') || '';

        var html = '<div class="tix-upsell-tag" data-id="' + id + '">' +
            '<input type="hidden" name="tix_upsell_events[]" value="' + id + '">' +
            '<span class="tix-upsell-tag-title">' + $('<span>').text(title).html() + '</span>' +
            '<span class="tix-upsell-tag-date">' + $('<span>').text(date).html() + '</span>' +
            '<button type="button" class="tix-upsell-tag-remove" title="Entfernen">&times;</button>' +
            '</div>';
        $('#tix-upsell-selected').append(html);

        $opt.prop('disabled', true);
        $sel.val('');
    });

    $(document).on('click', '.tix-upsell-tag-remove', function() {
        var $tag = $(this).closest('.tix-upsell-tag');
        var id = $tag.data('id');
        $tag.remove();
        $('#tix-upsell-select option[value="' + id + '"]').prop('disabled', false);
    });

    // ═══════════════════════════════════════
    // GALERIE
    // ═══════════════════════════════════════

    $('#tix-gallery-add').on('click', function() {
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') return;

        var frame = wp.media({
            title: 'Bilder zur Galerie hinzufügen',
            button: { text: 'Zur Galerie hinzufügen' },
            library: { type: 'image' },
            multiple: true
        });

        frame.on('select', function() {
            var attachments = frame.state().get('selection').toJSON();
            var $thumbs = $('#tix-gallery-thumbs');

            attachments.forEach(function(att) {
                if ($thumbs.find('[data-id="' + att.id + '"]').length) return;

                var thumb = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                var html = '<div class="tix-gallery-thumb" data-id="' + att.id + '">' +
                    '<img src="' + thumb + '" alt="">' +
                    '<input type="hidden" name="tix_gallery[]" value="' + att.id + '">' +
                    '<button type="button" class="tix-gallery-remove" title="Entfernen">&times;</button>' +
                    '</div>';
                $thumbs.append(html);
            });
        });

        frame.open();
    });

    $(document).on('click', '.tix-gallery-remove', function(e) {
        e.preventDefault();
        $(this).closest('.tix-gallery-thumb').remove();
    });

    if ($.fn.sortable) {
        $('#tix-gallery-thumbs').sortable({
            items: '.tix-gallery-thumb',
            cursor: 'grabbing',
            tolerance: 'pointer',
            placeholder: 'tix-gallery-thumb',
        });
    }

    // ═══════════════════════════════════════
    // BEITRAGSBILD (Featured Image in Medien-Tab)
    // ═══════════════════════════════════════
    $('#tix-featured-img-set').on('click', function() {
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') return;
        var frame = wp.media({
            title: 'Beitragsbild festlegen',
            button: { text: 'Als Beitragsbild verwenden' },
            library: { type: 'image' },
            multiple: false
        });
        frame.on('select', function() {
            var att = frame.state().get('selection').first().toJSON();
            var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
            $('#tix-featured-img-id').val(att.id);
            $('#tix-featured-img-preview').show().find('img').attr('src', url);
            $('#tix-featured-img-set').text('Bild ändern');
            $('#tix-featured-img-remove').show();
        });
        frame.open();
    });
    $('#tix-featured-img-remove').on('click', function() {
        $('#tix-featured-img-id').val('-1');
        $('#tix-featured-img-preview').hide();
        $('#tix-featured-img-set').text('Beitragsbild festlegen');
        $(this).hide();
    });

    // ═══════════════════════════════════════
    // VIDEO
    // ═══════════════════════════════════════

    $('#tix-video-media').on('click', function() {
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') return;

        var frame = wp.media({
            title: 'Video auswählen',
            button: { text: 'Video verwenden' },
            library: { type: 'video' },
            multiple: false
        });

        frame.on('select', function() {
            var att = frame.state().get('selection').first().toJSON();
            $('input[name="tix_video_url"]').val(att.url);
            $('#tix-video-id').val(att.id);
            $('#tix-video-preview').html('<span style="font-size:12px;color:#10b981;">✓ Mediathek-Video: ' + att.filename + '</span>');
        });

        frame.open();
    });

    $('input[name="tix_video_url"]').on('input', function() {
        $('#tix-video-id').val('');
        var val = $(this).val().trim();
        if (val && /youtube\.com|youtu\.be|vimeo\.com/.test(val)) {
            $('#tix-video-preview').html('<span style="font-size:12px;color:#10b981;">✓ Video-URL erkannt</span>');
        } else if (val) {
            $('#tix-video-preview').html('<span style="font-size:12px;color:#94a3b8;">URL wird beim Speichern geprüft</span>');
        } else {
            $('#tix-video-preview').html('');
        }
    });

    // ── Bundle Toggle ──
    $(document).on('click', '.tix-bundle-toggle', function(e) {
        e.preventDefault();
        $(this).next('.tix-bundle-fields').toggle();
    });

    // ── Saalplan Toggle ──
    $(document).on('click', '.tix-seatmap-toggle', function(e) {
        e.preventDefault();
        $(this).next('.tix-seatmap-fields').toggle();
    });

    // ══════════════════════════════════════
    // Mengenrabatt Toggle
    // ══════════════════════════════════════

    $('#tix-gd-toggle').on('change', function() {
        if ($(this).is(':checked')) {
            $('#tix-gd-panel').slideDown(200);
        } else {
            $('#tix-gd-panel').slideUp(200);
        }
    });

    $('#tix-gd-add-tier').on('click', function() {
        var $rows = $('#tix-gd-rows');
        var i = $rows.find('.tix-gd-row').length;
        var html = '<tr class="tix-gd-row">'
            + '<td><input type="number" name="tix_group_discount[tiers][' + i + '][min_qty]" min="2" step="1" class="tix-input-sm" style="width:100%" placeholder="z.B. 10"></td>'
            + '<td><input type="number" name="tix_group_discount[tiers][' + i + '][percent]" min="1" max="99" step="1" class="tix-input-sm" style="width:100%" placeholder="z.B. 15"></td>'
            + '<td><button type="button" class="button tix-gd-remove" title="Entfernen">&times;</button></td>'
            + '</tr>';
        $rows.append(html);
    });

    $(document).on('click', '.tix-gd-remove', function() {
        var $rows = $('#tix-gd-rows');
        if ($rows.find('.tix-gd-row').length <= 1) {
            alert('Mindestens eine Staffel.');
            return;
        }
        $(this).closest('tr').remove();
        $rows.find('.tix-gd-row').each(function(idx) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) $(this).attr('name', name.replace(/tiers\[\d+\]/, 'tiers[' + idx + ']'));
            });
        });
    });

    // ── Embed Widget Toggle ──
    $('#tix-embed-toggle').on('change', function() {
        $('#tix-embed-panel')[this.checked ? 'slideDown' : 'slideUp'](200);
    });

    // ═══════════════════════════════════════
    // KOMBI-TICKETS
    // ═══════════════════════════════════════

    var eventCats = (typeof tixAdmin !== 'undefined' && tixAdmin.eventCategories) ? tixAdmin.eventCategories : {};
    var availableEvents = (typeof tixAdmin !== 'undefined' && tixAdmin.availableEvents) ? tixAdmin.availableEvents : [];

    // Partner-Event <option>-HTML aus JS-Daten erzeugen
    function buildEventOptions(disabledIds) {
        var html = '<option value="">Partner-Event hinzuf\u00fcgen\u2026</option>';
        for (var e = 0; e < availableEvents.length; e++) {
            var ev = availableEvents[e];
            var label = ev.title + (ev.date_fmt ? ' (' + ev.date_fmt + ')' : '');
            var dis = (disabledIds && disabledIds.indexOf(String(ev.id)) !== -1) ? ' disabled' : '';
            html += '<option value="' + ev.id + '"'
                + ' data-title="' + $('<span>').text(ev.title).html() + '"'
                + ' data-date="' + $('<span>').text(ev.date || '').html() + '"'
                + dis + '>'
                + $('<span>').text(label).html()
                + '</option>';
        }
        return html;
    }

    // Kombi hinzufügen
    $(document).on('click', '#tix-combo-add', function() {
        var $cd = $('#tix-combo-deals');
        var ci = $cd.find('.tix-combo-deal').length;

        // Eigene Kategorien aus bestehendem Ticket-Table lesen
        var selfCatsHtml = '';
        $('#tix-ticket-rows tr.tix-row').each(function(idx) {
            var name = $(this).find('input[name*="[name]"]').val();
            if (name) selfCatsHtml += '<option value="' + idx + '">' + $('<span>').text(name).html() + '</option>';
        });

        var eventsHtml = buildEventOptions();

        var html =
            '<div class="tix-combo-deal" data-combo-index="' + ci + '">' +
                '<input type="hidden" name="tix_combos[' + ci + '][id]" value="">' +
                '<div class="tix-combo-deal-header">' +
                    '<strong>Kombi #' + (ci + 1) + '</strong>' +
                    '<button type="button" class="button tix-combo-remove" title="Kombi entfernen">&times;</button>' +
                '</div>' +
                '<div class="tix-combo-deal-fields">' +
                    '<div class="tix-combo-row">' +
                        '<label class="tix-combo-label">Bezeichnung</label>' +
                        '<input type="text" name="tix_combos[' + ci + '][label]" placeholder="z.B. Festival-Kombi Fr+Sa+So" style="width:100%">' +
                    '</div>' +
                    '<div class="tix-combo-row-inline">' +
                        '<div><label class="tix-combo-label">Kombi-Preis (\u20ac)</label>' +
                            '<input type="number" name="tix_combos[' + ci + '][price]" step="0.01" min="0" style="width:120px" placeholder="45.00"></div>' +
                        '<div><label class="tix-combo-label">Kategorie dieses Events</label>' +
                            '<select name="tix_combos[' + ci + '][self_cat_index]" class="tix-combo-self-cat">' + selfCatsHtml + '</select></div>' +
                    '</div>' +
                    '<div class="tix-combo-partners-section">' +
                        '<label class="tix-combo-label">Partner-Events</label>' +
                        '<div class="tix-combo-partners" data-combo-index="' + ci + '"></div>' +
                        '<div class="tix-combo-add-partner">' +
                            '<select class="tix-combo-event-select regular-text">' + eventsHtml + '</select>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        $cd.append(html);
    });

    // Kombi entfernen
    $(document).on('click', '.tix-combo-remove', function() {
        $(this).closest('.tix-combo-deal').remove();
        reindexCombos();
    });

    // Partner-Event hinzufügen
    $(document).on('change', '.tix-combo-event-select', function() {
        var $sel = $(this);
        var id = $sel.val();
        if (!id) return;

        var $opt = $sel.find('option:selected');
        var title = $opt.data('title') || $opt.text().trim();
        var date = $opt.data('date') || '';
        var $deal = $sel.closest('.tix-combo-deal');
        var ci = $deal.data('combo-index');
        var $partners = $deal.find('.tix-combo-partners');
        var pi = $partners.find('.tix-combo-partner-tag').length;

        // Kategorie-Optionen für dieses Event
        var cats = eventCats[id] || [];
        var catHtml = '';
        for (var c = 0; c < cats.length; c++) {
            catHtml += '<option value="' + cats[c].index + '">' + $('<span>').text(cats[c].name).html() + '</option>';
        }
        if (!catHtml) catHtml = '<option value="0">Standard</option>';

        var html =
            '<div class="tix-combo-partner-tag" data-event-id="' + id + '">' +
                '<input type="hidden" name="tix_combos[' + ci + '][partners][' + pi + '][event_id]" value="' + id + '">' +
                '<div class="tix-combo-partner-top">' +
                    '<div class="tix-combo-partner-info">' +
                        '<span class="tix-combo-partner-title">' + $('<span>').text(title).html() + '</span>' +
                        '<span class="tix-combo-partner-date">' + $('<span>').text(date).html() + '</span>' +
                    '</div>' +
                    '<button type="button" class="tix-combo-partner-remove" title="Entfernen">&times;</button>' +
                '</div>' +
                '<div class="tix-combo-partner-cat-row">' +
                    '<span class="tix-combo-partner-cat-label">Kategorie</span>' +
                    '<select name="tix_combos[' + ci + '][partners][' + pi + '][cat_index]" class="tix-combo-cat-select">' + catHtml + '</select>' +
                '</div>' +
            '</div>';

        $partners.append(html);
        $opt.prop('disabled', true);
        $sel.val('');
    });

    // Partner-Event entfernen
    $(document).on('click', '.tix-combo-partner-remove', function() {
        var $tag = $(this).closest('.tix-combo-partner-tag');
        var id = $tag.data('event-id');
        var $deal = $tag.closest('.tix-combo-deal');
        $tag.remove();
        $deal.find('.tix-combo-event-select option[value="' + id + '"]').prop('disabled', false);
        reindexCombos();
    });

    function reindexCombos() {
        $('#tix-combo-deals').find('.tix-combo-deal').each(function(ci) {
            $(this).attr('data-combo-index', ci);
            $(this).find('.tix-combo-deal-header strong').text('Kombi #' + (ci + 1));
            $(this).find('.tix-combo-partners').attr('data-combo-index', ci);

            // Reindex all inputs in this combo
            $(this).find('input, select').each(function() {
                var name = $(this).attr('name');
                if (name && name.indexOf('tix_combos') === 0) {
                    $(this).attr('name', name.replace(/tix_combos\[\d+\]/, 'tix_combos[' + ci + ']'));
                }
            });

            // Reindex partners
            $(this).find('.tix-combo-partner-tag').each(function(pi) {
                $(this).find('input, select').each(function() {
                    var name = $(this).attr('name');
                    if (name && name.indexOf('partners') > -1) {
                        $(this).attr('name', name.replace(/partners\[\d+\]/, 'partners[' + pi + ']'));
                    }
                });
            });
        });
    }

    // ═══════════════════════════════════════
    // GÄSTELISTE
    // ═══════════════════════════════════════

    var $glBody = $('#tix-gl-rows');
    var postId = $('#post_ID').val() || 0;

    // Toggle Gästeliste
    $('#tix-gl-toggle').on('change', function() {
        var show = $(this).is(':checked');
        $('#tix-gl-panel')[show ? 'slideDown' : 'slideUp'](200);
        $('#tix-gl-panel-content')[show ? 'slideDown' : 'slideUp'](200);
    });

    // Random Guest-ID erzeugen (12 Zeichen, kryptographisch sicher)
    function generateGuestId() {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        var id = '';
        var arr = new Uint8Array(12);
        (window.crypto || window.msCrypto).getRandomValues(arr);
        for (var c = 0; c < 12; c++) id += chars.charAt(arr[c] % chars.length);
        return id;
    }

    // Gast hinzufügen
    $('#tix-gl-add').on('click', function() {
        $glBody.find('.tix-gl-empty').remove();
        var i = $glBody.find('.tix-gl-row').length;
        var gid = generateGuestId();
        var qrCode = 'GL-' + postId + '-' + gid;

        var html =
            '<tr class="tix-gl-row" data-guest-id="' + gid + '">' +
                '<td>' +
                    '<input type="text" name="tix_guest_list[' + i + '][name]" placeholder="Name eingeben\u2026" style="width:100%" required>' +
                    '<input type="hidden" name="tix_guest_list[' + i + '][id]" value="' + gid + '">' +
                '</td>' +
                '<td><input type="email" name="tix_guest_list[' + i + '][email]" placeholder="Optional" style="width:100%"></td>' +
                '<td><input type="number" name="tix_guest_list[' + i + '][plus]" value="0" min="0" max="10" style="width:100%"></td>' +
                '<td><input type="text" name="tix_guest_list[' + i + '][note]" placeholder="z.B. VIP Tisch 4" style="width:100%"></td>' +
                '<td class="tix-gl-qr-cell"><canvas class="tix-gl-qr" data-qr="' + qrCode + '" width="52" height="52"></canvas></td>' +
                '<td class="tix-gl-status-cell"><span class="tix-gl-badge tix-gl-badge-open">\u25CB Offen</span></td>' +
                '<td class="tix-gl-actions">' +
                    '<button type="button" class="button tix-gl-checkin" data-event="' + postId + '" data-guest="' + gid + '" title="Einchecken">\u2713</button>' +
                    '<button type="button" class="button tix-gl-del" title="Gast entfernen">&times;</button>' +
                '</td>' +
            '</tr>';
        $glBody.append(html);

        // QR rendern
        var $newRow = $glBody.find('.tix-gl-row').last();
        var canvas = $newRow.find('.tix-gl-qr')[0];
        if (canvas && window.ehQR) {
            window.ehQR.render(canvas);
        }

        // Fokus auf Name
        $newRow.find('input[name*="[name]"]').focus();
    });

    // Gast entfernen
    $(document).on('click', '.tix-gl-del', function() {
        if (!confirm('Gast wirklich entfernen?')) return;
        $(this).closest('.tix-gl-row').remove();
        reindexGuests();
        if ($glBody.find('.tix-gl-row').length === 0) {
            $glBody.append('<tr class="tix-gl-empty"><td colspan="7" style="text-align:center;color:#999;padding:16px;">Noch keine G\u00e4ste. Klicke unten auf \u201e+ Gast hinzuf\u00fcgen\u201c.</td></tr>');
        }
    });

    function reindexGuests() {
        $glBody.find('.tix-gl-row').each(function(idx) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) $(this).attr('name', name.replace(/tix_guest_list\[\d+\]/, 'tix_guest_list[' + idx + ']'));
            });
        });
    }

    // QR-Codes nach Page Load rendern
    if (window.ehQR) {
        $glBody.find('.tix-gl-qr').each(function() {
            window.ehQR.render(this);
        });
    }

    // ── AJAX Check-in Toggle (Admin) ──
    $(document).on('click', '.tix-gl-checkin', function() {
        var $btn = $(this);
        var eventId = $btn.data('event');
        var guestId = $btn.data('guest');
        if (!eventId || !guestId) return;

        $btn.prop('disabled', true);

        $.post(tixAdmin.ajaxUrl, {
            action: 'tix_guest_checkin',
            nonce: tixAdmin.nonce,
            event_id: eventId,
            guest_id: guestId
        }, function(res) {
            $btn.prop('disabled', false);
            if (!res.success) {
                alert(res.data && res.data.message ? res.data.message : 'Fehler beim Check-in.');
                return;
            }

            var guest = res.data.guest;
            var $row = $btn.closest('.tix-gl-row');
            var $status = $row.find('.tix-gl-status-cell');

            if (guest.checked_in) {
                $row.addClass('tix-gl-checked');
                $status.html('<span class="tix-gl-badge tix-gl-badge-ok" title="' + (guest.checkin_time || '') + '">\u2713 Eingecheckt</span>');
                $btn.html('\u21A9').attr('title', 'Check-in r\u00FCckg\u00E4ngig');
            } else {
                $row.removeClass('tix-gl-checked');
                $status.html('<span class="tix-gl-badge tix-gl-badge-open">\u25CB Offen</span>');
                $btn.html('\u2713').attr('title', 'Einchecken');
            }

            // Übersichtszähler aktualisieren
            if (res.data.stats) {
                $('#tix-gl-total').text(res.data.stats.total);
                $('#tix-gl-checked').text(res.data.stats.checked_in);
                $('#tix-gl-open').text(res.data.stats.open);
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            alert('Netzwerkfehler.');
        });
    });

    // ── E-Mail senden ──
    $(document).on('click', '.tix-gl-send-email', function() {
        var $btn = $(this);
        var eventId = $btn.data('event');
        var guestId = $btn.data('guest');

        $btn.prop('disabled', true);

        $.post(tixAdmin.ajaxUrl, {
            action: 'tix_guest_send_email',
            nonce: tixAdmin.nonce,
            event_id: eventId,
            guest_id: guestId
        }, function(res) {
            $btn.prop('disabled', false);
            var $notify = $('<span class="tix-gl-notify"></span>');
            if (res.success) {
                $notify.addClass('tix-gl-notify-ok').text('\u2713 Gesendet');
            } else {
                $notify.addClass('tix-gl-notify-err').text(res.data && res.data.message ? res.data.message : 'Fehler');
            }
            $btn.after($notify);
            setTimeout(function() { $notify.fadeOut(300, function() { $notify.remove(); }); }, 3000);
        }).fail(function() {
            $btn.prop('disabled', false);
        });
    });

    // Alle benachrichtigen
    $('#tix-gl-send-all').on('click', function() {
        if (!confirm('QR-Code an alle G\u00e4ste mit E-Mail-Adresse senden?')) return;
        var $btn = $(this);
        var eventId = $btn.data('event');

        $btn.prop('disabled', true).text('\u2709 Sende\u2026');

        $.post(tixAdmin.ajaxUrl, {
            action: 'tix_guest_send_all_emails',
            nonce: tixAdmin.nonce,
            event_id: eventId
        }, function(res) {
            $btn.prop('disabled', false);
            if (res.success) {
                $btn.text('\u2713 ' + res.data.sent + ' gesendet');
                setTimeout(function() { $btn.text('\u2709 Alle benachrichtigen (' + res.data.total + ')'); }, 3000);
            } else {
                $btn.text('\u2709 Fehler');
                setTimeout(function() { $btn.text('\u2709 Alle benachrichtigen'); }, 3000);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('\u2709 Alle benachrichtigen');
        });
    });

    // ── CSV Import ──
    $('#tix-gl-csv-import').on('click', function() { $('#tix-gl-csv-file').trigger('click'); });

    $('#tix-gl-csv-file').on('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function(ev) {
            var text = ev.target.result;
            var lines = text.split(/\r?\n/);

            // Erste Zeile: Header prüfen (oder überspringen)
            var startLine = 0;
            if (lines.length > 0) {
                var first = lines[0].toLowerCase();
                if (first.indexOf('name') > -1 || first.indexOf('e-mail') > -1 || first.indexOf('email') > -1) {
                    startLine = 1; // Header überspringen
                }
            }

            $glBody.find('.tix-gl-empty').remove();
            var added = 0;

            for (var l = startLine; l < lines.length; l++) {
                var line = lines[l].trim();
                if (!line) continue;

                // CSV parsen: Name, Email, +1, Notiz (Komma oder Semikolon)
                var parts = line.split(/[,;]/);
                var name = (parts[0] || '').trim();
                if (!name) continue;

                var email = (parts[1] || '').trim();
                var plus  = parseInt(parts[2]) || 0;
                var note  = (parts[3] || '').trim();

                var i = $glBody.find('.tix-gl-row').length;
                var gid = generateGuestId();
                var qrCode = 'GL-' + postId + '-' + gid;

                var html =
                    '<tr class="tix-gl-row" data-guest-id="' + gid + '">' +
                        '<td>' +
                            '<input type="text" name="tix_guest_list[' + i + '][name]" value="' + $('<span>').text(name).html() + '" style="width:100%">' +
                            '<input type="hidden" name="tix_guest_list[' + i + '][id]" value="' + gid + '">' +
                        '</td>' +
                        '<td><input type="email" name="tix_guest_list[' + i + '][email]" value="' + $('<span>').text(email).html() + '" style="width:100%"></td>' +
                        '<td><input type="number" name="tix_guest_list[' + i + '][plus]" value="' + plus + '" min="0" max="10" style="width:100%"></td>' +
                        '<td><input type="text" name="tix_guest_list[' + i + '][note]" value="' + $('<span>').text(note).html() + '" style="width:100%"></td>' +
                        '<td class="tix-gl-qr-cell"><canvas class="tix-gl-qr" data-qr="' + qrCode + '" width="52" height="52"></canvas></td>' +
                        '<td class="tix-gl-status-cell"><span class="tix-gl-badge tix-gl-badge-open">\u25CB Offen</span></td>' +
                        '<td class="tix-gl-actions">' +
                            '<button type="button" class="button tix-gl-checkin" data-event="' + postId + '" data-guest="' + gid + '" title="Einchecken">\u2713</button>' +
                            '<button type="button" class="button tix-gl-del" title="Gast entfernen">&times;</button>' +
                        '</td>' +
                    '</tr>';
                $glBody.append(html);
                added++;
            }

            // QR-Codes rendern
            if (window.ehQR) {
                $glBody.find('.tix-gl-qr').each(function() {
                    if (!this.getContext('2d').getImageData(1,1,1,1).data[3]) {
                        window.ehQR.render(this);
                    }
                });
            }

            alert(added + ' G\u00e4ste importiert. Klicke „Aktualisieren" um zu speichern.');
        };
        reader.readAsText(file, 'UTF-8');

        // Reset file input
        $(this).val('');
    });

    // ── CSV Export ──
    $('#tix-gl-csv-export').on('click', function() {
        var rows = [['Name', 'E-Mail', '+1', 'Notiz', 'Status', 'Check-in Zeit']];
        $glBody.find('.tix-gl-row').each(function() {
            var $r = $(this);
            var name  = $r.find('input[name*="[name]"]').val() || '';
            var email = $r.find('input[name*="[email]"]').val() || '';
            var plus  = $r.find('input[name*="[plus]"]').val() || '0';
            var note  = $r.find('input[name*="[note]"]').val() || '';
            var checked = $r.hasClass('tix-gl-checked');
            var time  = $r.find('.tix-gl-badge-ok').attr('title') || '';
            rows.push([name, email, plus, note, checked ? 'Eingecheckt' : 'Offen', time]);
        });

        var csv = rows.map(function(r) {
            return r.map(function(cell) {
                if (cell.indexOf(',') > -1 || cell.indexOf('"') > -1 || cell.indexOf('\n') > -1) {
                    return '"' + cell.replace(/"/g, '""') + '"';
                }
                return cell;
            }).join(',');
        }).join('\n');

        var bom = '\uFEFF';
        var blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'gaesteliste.csv';
        link.click();
    });

    // ── Teilnehmerliste CSV ──
    $(document).on('click', '.tix-csv-teilnehmer', function() {
        var $btn = $(this);
        var eventId = $btn.data('event');
        $btn.prop('disabled', true).text('⏳ Laden...');

        $.post(tixAdmin.ajaxUrl, {
            action: 'tix_teilnehmer_csv',
            nonce: tixAdmin.nonce,
            event_id: eventId
        }, function(res) {
            $btn.prop('disabled', false).text('📋 Teilnehmerliste CSV');
            if (!res.success || !res.data.rows.length) {
                alert('Keine Teilnehmer gefunden.');
                return;
            }
            var header = ['Bestell-Nr', 'Datum', 'Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Ticket', 'Anzahl', 'Summe (€)', 'Ticketcode', 'Status', 'Eingecheckt'];
            var csv = [header.join(';')];
            res.data.rows.forEach(function(r) {
                csv.push([r.order_id, r.date, r.first_name, r.last_name, r.email, r.phone, r.ticket, r.qty, r.total, r.ticket_codes, r.status, r.checkin].map(function(v) {
                    v = String(v || '');
                    return v.indexOf(';') > -1 || v.indexOf('"') > -1 ? '"' + v.replace(/"/g, '""') + '"' : v;
                }).join(';'));
            });
            var blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'teilnehmerliste-' + eventId + '.csv';
            a.click();
        });
    });

    // ══════════════════════════════════════
    // GEFÜHRTER MODUS (WIZARD)
    // ══════════════════════════════════════

    var $wizard = $('#tix-wizard');
    var $expert = $('#tix-expert');
    var $modeButtons = $('.tix-mode-btn');
    var wizCurrentStep = 1;
    var wizTotalSteps = 5;

    // ── Modus-Toggle ──
    $modeButtons.on('click', function() {
        var mode = $(this).data('mode');
        $modeButtons.removeClass('active');
        $(this).addClass('active');

        if (mode === 'wizard') {
            $wizard.show();
            $expert.hide();
            syncExpertToWizard();
        } else {
            syncWizardToExpert();
            $wizard.hide();
            $expert.show();
        }

        if (window.sessionStorage) sessionStorage.setItem('tix_mode', mode);
    });

    // Restore mode from sessionStorage (or force expert if wizard disabled)
    if (!$wizard.length) {
        // Wizard ist deaktiviert → Expert direkt anzeigen, kein Toggle nötig
        $expert.show();
    } else if (window.sessionStorage) {
        var savedMode = sessionStorage.getItem('tix_mode');
        if (savedMode === 'expert') {
            $modeButtons.filter('[data-mode="expert"]').trigger('click');
        }
    }

    // ── Step Navigation ──
    function wizGoTo(step) {
        if (step < 1 || step > wizTotalSteps) return;
        wizCurrentStep = step;

        // Update panes
        $('.tix-wiz-pane').removeClass('active');
        $('.tix-wiz-pane[data-wstep="' + step + '"]').addClass('active');

        // Update step indicator
        $('.tix-wiz-step').each(function() {
            var s = parseInt($(this).data('step'));
            $(this).removeClass('active done');
            if (s === step) $(this).addClass('active');
            else if (s < step) $(this).addClass('done');
        });

        // Navigation buttons
        $('#wiz-prev').toggle(step > 1);
        $('#wiz-next').toggle(step < wizTotalSteps);
        $('.tix-wiz-nav').toggle(step < wizTotalSteps || step > 1);

        // Hide nav on step 5 (summary has its own buttons)
        if (step === wizTotalSteps) {
            $('.tix-wiz-nav').hide();
            wizBuildSummary();
        }

        // Auto-fill end date from start date
        if (step === 1) {
            $('#wiz-date-start').off('change.wiz').on('change.wiz', function() {
                if (!$('#wiz-date-end').val()) $('#wiz-date-end').val($(this).val());
            });
        }
    }

    $('#wiz-next').on('click', function() {
        if (wizValidateStep(wizCurrentStep)) {
            wizGoTo(wizCurrentStep + 1);
        }
    });

    $('#wiz-prev').on('click', function() {
        wizGoTo(wizCurrentStep - 1);
    });

    // ── Click on step tabs to navigate ──
    $('.tix-wiz-step').on('click', function() {
        var target = parseInt($(this).data('step'));
        if (target === wizCurrentStep) return;

        // Going backwards is always allowed
        if (target < wizCurrentStep) {
            wizGoTo(target);
            return;
        }

        // Going forward: validate all steps in between
        for (var s = wizCurrentStep; s < target; s++) {
            if (!wizValidateStep(s)) return;
        }
        wizGoTo(target);
    });

    // ── Step Validation ──
    function wizValidateStep(step) {
        var errors = [];
        if (step === 1) {
            if (!$('#wiz-title').val().trim()) errors.push('Event-Titel');
            if (!$('#wiz-date-start').val()) errors.push('Startdatum');
            if (!$('#wiz-time-start').val()) errors.push('Startzeit');
        } else if (step === 2) {
            if (!$('#wiz-location').val()) errors.push('Veranstaltungsort');
        } else if (step === 3) {
            var hasTicket = false;
            $('#wiz-ticket-rows .tix-wiz-trow').each(function() {
                var n = $(this).find('.wiz-tk-name').val().trim();
                var p = $(this).find('.wiz-tk-price').val();
                if (n && p) hasTicket = true;
            });
            if (!hasTicket) errors.push('Mindestens 1 Ticketkategorie mit Name und Preis');
        }

        if (errors.length) {
            var $pane = $('.tix-wiz-pane[data-wstep="' + step + '"]');
            $pane.find('.tix-wiz-error').remove();
            $pane.prepend('<div class="tix-wiz-error"><strong>Bitte ausfüllen:</strong> ' + errors.join(', ') + '</div>');
            return false;
        }
        $('.tix-wiz-error').remove();
        return true;
    }

    // ── Wizard Ticket Rows ──
    $('#wiz-add-ticket').on('click', function() {
        var html = '<tr class="tix-wiz-trow">' +
            '<td><input type="text" class="wiz-tk-name" placeholder="z.B. VIP" value=""></td>' +
            '<td><input type="number" class="wiz-tk-price" step="0.01" min="0" placeholder="49.90" value=""></td>' +
            '<td><input type="number" class="wiz-tk-qty" min="0" placeholder="\u221e" value=""></td>' +
            '<td><input type="text" class="wiz-tk-desc" placeholder="Optional" value=""></td>' +
            '<td><button type="button" class="button tix-wiz-tk-del" title="Entfernen">&times;</button></td>' +
            '</tr>';
        $('#wiz-ticket-rows').append(html);
    });

    $(document).on('click', '.tix-wiz-tk-del', function() {
        if ($('#wiz-ticket-rows .tix-wiz-trow').length <= 1) {
            alert('Mindestens eine Kategorie.');
            return;
        }
        $(this).closest('tr').remove();
    });

    // ── Location auto-address ──
    $('#wiz-location').on('change', function() {
        var addr = $(this).find(':selected').data('address') || '';
        $('#wiz-address').val(addr);
    });

    // ── Build Summary ──
    function wizBuildSummary() {
        var title = $('#wiz-title').val() || '—';
        var dateStart = $('#wiz-date-start').val() || '—';
        var timeStart = $('#wiz-time-start').val() || '—';
        var dateEnd = $('#wiz-date-end').val() || '';
        var timeEnd = $('#wiz-time-end').val() || '';
        var doors = $('#wiz-time-doors').val() || '';
        var loc = $('#wiz-location option:selected').text() || '—';
        if (!$('#wiz-location').val()) loc = '—';
        var addr = $('#wiz-address').val() || '';
        var org = $('#wiz-organizer option:selected').text() || '';
        if (!$('#wiz-organizer').val()) org = '';
        var desc = $('#wiz-description').val() || '';

        var html = '<div class="tix-wiz-sum-card">';
        html += '<div class="tix-wiz-sum-row"><span class="tix-wiz-sum-label">Event</span><strong>' + escHtml(title) + '</strong></div>';
        html += '<div class="tix-wiz-sum-row"><span class="tix-wiz-sum-label">Datum</span>' + escHtml(dateStart) + ' ' + escHtml(timeStart);
        if (dateEnd || timeEnd) html += ' — ' + escHtml(dateEnd) + ' ' + escHtml(timeEnd);
        html += '</div>';
        if (doors) html += '<div class="tix-wiz-sum-row"><span class="tix-wiz-sum-label">Einlass</span>' + escHtml(doors) + '</div>';
        html += '<div class="tix-wiz-sum-row"><span class="tix-wiz-sum-label">Ort</span>' + escHtml(loc);
        if (addr) html += ' <small>(' + escHtml(addr) + ')</small>';
        html += '</div>';
        if (org) html += '<div class="tix-wiz-sum-row"><span class="tix-wiz-sum-label">Veranstalter</span>' + escHtml(org) + '</div>';
        if (desc) html += '<div class="tix-wiz-sum-row"><span class="tix-wiz-sum-label">Beschreibung</span>' + escHtml(desc.substring(0, 120)) + (desc.length > 120 ? '...' : '') + '</div>';

        // Tickets
        html += '<div class="tix-wiz-sum-divider"></div>';
        html += '<div class="tix-wiz-sum-row"><span class="tix-wiz-sum-label">Tickets</span></div>';
        $('#wiz-ticket-rows .tix-wiz-trow').each(function() {
            var n = $(this).find('.wiz-tk-name').val();
            var p = $(this).find('.wiz-tk-price').val();
            var q = $(this).find('.wiz-tk-qty').val();
            if (n && p && q) {
                html += '<div class="tix-wiz-sum-ticket">' + escHtml(n) + ' &mdash; ' + escHtml(p) + ' € &times; ' + escHtml(q) + '</div>';
            }
        });

        html += '</div>';
        $('#wiz-summary').html(html);
    }

    function escHtml(s) {
        return $('<span>').text(s).html();
    }

    // ── Sync: Expert Title ↔ #title (bidirektional) ──
    // Expert → WP (mit Event-Trigger damit WordPress + Fortschrittsleiste es mitbekommen)
    $(document).on('input', '#tix-expert-title', function() {
        var val = $(this).val();
        $('#title').val(val).trigger('input').trigger('change');
        checkRequiredFields();
    });
    // WP → Expert (Rücksync wenn User im WordPress-Titelfeld tippt)
    $(document).on('input', '#title', function() {
        var val = $(this).val();
        if ($('#tix-expert-title').length && $('#tix-expert-title').val() !== val) {
            $('#tix-expert-title').val(val);
        }
    });

    // ── Sync: Wizard → Expert fields (before form submit) ──
    function syncWizardToExpert() {
        // Title
        var wizTitle = $('#wiz-title').val();
        if (wizTitle) {
            $('#title').val(wizTitle);
            $('#tix-expert-title').val(wizTitle);
        }

        // Dates/Times
        $('input[name="tix_date_start"]').val($('#wiz-date-start').val());
        $('input[name="tix_time_start"]').val($('#wiz-time-start').val());
        $('input[name="tix_date_end"]').val($('#wiz-date-end').val());
        $('input[name="tix_time_end"]').val($('#wiz-time-end').val());
        $('input[name="tix_time_doors"]').val($('#wiz-time-doors').val());

        // Location & Organizer
        $('#tix_location_id').val($('#wiz-location').val()).trigger('change');
        $('#tix_organizer_id').val($('#wiz-organizer').val());

        // Description
        var descVal = $('#wiz-description').val();
        var $descField = $('textarea[name="tix_info[description]"], #wp-tix_info_description-wrap textarea');
        if ($descField.length) $descField.val(descVal);
        // Also try tinyMCE
        if (typeof tinyMCE !== 'undefined') {
            var editor = tinyMCE.get('tix_info_description');
            if (editor) editor.setContent(descVal || '');
        }

        // Tickets: Enable tickets + presale
        $('input[name="tix_tickets_enabled"][type="checkbox"]').prop('checked', true).trigger('change');
        $('input[name="tix_presale_active"][type="checkbox"]').prop('checked', true);

        // ── In-Place-Update: Nur Wizard-Felder überschreiben, Expert-Daten erhalten ──
        var $expertBody = $('#tix-ticket-rows');
        var $expertRows = $expertBody.find('tr.tix-row');
        var existingCount = $expertRows.length;

        // Wizard-Daten sammeln (nur nicht-leere)
        var wizData = [];
        $('#wiz-ticket-rows .tix-wiz-trow').each(function() {
            var n = $(this).find('.wiz-tk-name').val() || '';
            var p = $(this).find('.wiz-tk-price').val() || '';
            var q = $(this).find('.wiz-tk-qty').val() || '';
            var d = $(this).find('.wiz-tk-desc').val() || '';
            if (n) wizData.push({name: n, price: p, qty: q, desc: d});
        });

        // Bestehende Expert-Rows in-place aktualisieren (Paket, Phasen, Bilder, IDs bleiben erhalten!)
        for (var wi = 0; wi < wizData.length; wi++) {
            if (wi < existingCount) {
                // Bestehende Zeile: nur die 4 Wizard-Felder updaten
                var $row = $expertRows.eq(wi);
                $row.find('input[name$="[name]"]').first().val(wizData[wi].name);
                $row.find('input[name$="[price]"]').first().val(wizData[wi].price);
                $row.find('input[name$="[qty]"]').val(wizData[wi].qty);
                $row.find('input[name$="[desc]"]').val(wizData[wi].desc);
            } else {
                // Neue Zeile hinzufügen (nur Basis-Felder)
                var newRow =
                    '<tr class="tix-row">' +
                    '<td><input type="text" name="tix_tickets[' + wi + '][name]" value="' + escAttr(wizData[wi].name) + '" style="width:100%">' +
                        '<input type="text" name="tix_tickets[' + wi + '][group]" class="tix-group-input" value="">' +
                        '<a href="#" class="tix-phases-toggle" title="Preisphasen">⏱ Phasen</a>' +
                        '<a href="#" class="tix-bundle-toggle" title="Bundle-Angebot">🎁 Paket</a>' +
                        '<div class="tix-bundle-fields" style="display:none;"><label style="font-size:11px;color:#888;">Kaufe <input type="number" name="tix_tickets[' + wi + '][bundle_buy]" min="2" step="1" class="tix-input-sm" style="width:50px"></label><label style="font-size:11px;color:#888;">zahle <input type="number" name="tix_tickets[' + wi + '][bundle_pay]" min="1" step="1" class="tix-input-sm" style="width:50px"></label><label style="font-size:11px;color:#888;">Label <input type="text" name="tix_tickets[' + wi + '][bundle_label]" class="tix-input-sm" style="width:160px"></label></div>' +
                    '</td>' +
                    '<td><input type="number" name="tix_tickets[' + wi + '][price]" value="' + escAttr(wizData[wi].price) + '" step="0.01" min="0" style="width:100%"></td>' +
                    '<td><input type="number" name="tix_tickets[' + wi + '][sale_price]" step="0.01" min="0" placeholder="—" style="width:100%" class="tix-sale-input"></td>' +
                    '<td><input type="number" name="tix_tickets[' + wi + '][qty]" value="' + escAttr(wizData[wi].qty) + '" min="1" style="width:100%"></td>' +
                    '<td><input type="text" name="tix_tickets[' + wi + '][desc]" value="' + escAttr(wizData[wi].desc) + '" style="width:100%"></td>' +
                    '<td class="tix-img-cell"><div class="tix-img-wrap"><div class="tix-img-box" data-i="' + wi + '"><span class="dashicons dashicons-format-image"></span></div><input type="hidden" name="tix_tickets[' + wi + '][image_id]" class="tix-img-val" value=""></div></td>' +
                    '<td><select name="tix_tickets[' + wi + '][sale_mode]" class="tix-sale-mode"><option value="online" selected>🌐 Online</option><option value="offline">🏪 Abendkasse</option><option value="off">⛔ Aus</option></select></td>' +
                    '<td class="tix-stock-cell"><em style="color:#bbb;font-size:12px">—</em></td>' +
                    '<td><input type="hidden" name="tix_tickets[' + wi + '][tc_event_id]" value=""><input type="hidden" name="tix_tickets[' + wi + '][product_id]" value=""><input type="hidden" name="tix_tickets[' + wi + '][sku]" value=""><button type="button" class="button tix-del" title="Entfernen">&times;</button></td>' +
                    '</tr>' +
                    '<tr class="tix-phases-row" style="display:none;"><td colspan="9"><div class="tix-phases-wrap">' +
                    '<div class="tix-phases-header"><strong>⏱ Preisphasen</strong></div>' +
                    '<table class="tix-phases-table"><thead><tr><th>Phasenname</th><th>Preis (€)</th><th>Gültig bis</th><th>Status</th><th></th></tr></thead>' +
                    '<tbody class="tix-phases-body"><tr class="tix-phases-empty"><td colspan="5"><em>Keine Phasen.</em></td></tr></tbody></table>' +
                    '<button type="button" class="button tix-phase-add">+ Phase</button>' +
                    '</div></td></tr>';
                $expertBody.append(newRow);
            }
        }

        // Überzählige Expert-Rows entfernen (Wizard hat weniger Kategorien)
        if (wizData.length < existingCount) {
            for (var ri = existingCount - 1; ri >= wizData.length; ri--) {
                var $delRow = $expertRows.eq(ri);
                $delRow.next('.tix-phases-row').remove();
                $delRow.remove();
            }
            // Inline reindex (reindex() is scoped inside $(function(){}))
            $('#tix-ticket-rows').find('tr.tix-row').each(function(idx) {
                var $r = $(this);
                var $pr = $r.next('.tix-phases-row');
                $r.find('input, select, textarea').each(function() {
                    var n = $(this).attr('name');
                    if (n) $(this).attr('name', n.replace(/tix_tickets\[\d+\]/, 'tix_tickets[' + idx + ']'));
                });
                $r.find('.tix-img-box').attr('data-i', idx);
                $pr.find('input, select, textarea').each(function() {
                    var n = $(this).attr('name');
                    if (n) $(this).attr('name', n.replace(/tix_tickets\[\d+\]/, 'tix_tickets[' + idx + ']'));
                });
            });
        }

        // Show tickets panel
        $('#tix-tickets-panel').show();
    }

    // ── Sync: Expert → Wizard fields ──
    function syncExpertToWizard() {
        var expTitle = $('#tix-expert-title').val();
        if (expTitle) $('#title').val(expTitle);
        $('#wiz-title').val($('#title').val() === 'Automatischer Entwurf' ? '' : $('#title').val());
        $('#wiz-date-start').val($('input[name="tix_date_start"]').val());
        $('#wiz-time-start').val($('input[name="tix_time_start"]').val());
        $('#wiz-date-end').val($('input[name="tix_date_end"]').val());
        $('#wiz-time-end').val($('input[name="tix_time_end"]').val());
        $('#wiz-time-doors').val($('input[name="tix_time_doors"]').val());
        $('#wiz-location').val($('#tix_location_id').val());
        var selAddr = $('#wiz-location').find(':selected').data('address') || '';
        $('#wiz-address').val(selAddr);
        $('#wiz-organizer').val($('#tix_organizer_id').val());

        // Description: try tinyMCE first
        var descVal = '';
        if (typeof tinyMCE !== 'undefined') {
            var editor = tinyMCE.get('tix_info_description');
            if (editor) descVal = editor.getContent({ format: 'text' });
        }
        if (!descVal) {
            var $df = $('textarea[name="tix_info[description]"]');
            if ($df.length) descVal = $df.val();
        }
        $('#wiz-description').val(descVal);

        // Tickets
        var $wizBody = $('#wiz-ticket-rows');
        $wizBody.empty();
        var hasRows = false;
        $('#tix-ticket-rows tr.tix-row').each(function(i) {
            hasRows = true;
            var n = $(this).find('input[name$="[name]"]').first().val() || '';
            var p = $(this).find('input[name$="[price]"]').first().val() || '';
            var q = $(this).find('input[name$="[qty]"]').val() || '';
            var d = $(this).find('input[name$="[desc]"]').val() || '';
            var row = '<tr class="tix-wiz-trow">' +
                '<td><input type="text" class="wiz-tk-name" value="' + escAttr(n) + '"></td>' +
                '<td><input type="number" class="wiz-tk-price" step="0.01" min="0" value="' + escAttr(p) + '"></td>' +
                '<td><input type="number" class="wiz-tk-qty" min="0" placeholder="\u221e" value="' + escAttr(q) + '"></td>' +
                '<td><input type="text" class="wiz-tk-desc" value="' + escAttr(d) + '"></td>' +
                '<td><button type="button" class="button tix-wiz-tk-del" title="Entfernen">&times;</button></td>' +
                '</tr>';
            $wizBody.append(row);
        });
        if (!hasRows) {
            $wizBody.append('<tr class="tix-wiz-trow"><td><input type="text" class="wiz-tk-name" placeholder="z.B. Standard"></td><td><input type="number" class="wiz-tk-price" step="0.01" min="0" placeholder="29.90"></td><td><input type="number" class="wiz-tk-qty" min="0" placeholder="\u221e"></td><td><input type="text" class="wiz-tk-desc" placeholder="Optional"></td><td><button type="button" class="button tix-wiz-tk-del">&times;</button></td></tr>');
        }
    }

    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ── Wizard Publish/Draft ──
    $('#wiz-publish').on('click', function() {
        // Validate all steps
        for (var s = 1; s <= 3; s++) {
            if (!wizValidateStep(s)) {
                wizGoTo(s);
                return;
            }
        }
        syncWizardToExpert();
        // Trigger WordPress Publish
        $('#publish').trigger('click');
    });

    $('#wiz-draft').on('click', function() {
        syncWizardToExpert();
        $('#save-post').trigger('click');
    });

    // ── Sync before ANY form submit (safety net) ──
    $('#post').on('submit', function() {
        if ($wizard.is(':visible')) {
            syncWizardToExpert();
        }
        // Expert Title → #title (falls direkt im Expert-Modus)
        var expTitle = $('#tix-expert-title').val();
        if (expTitle && expTitle.trim()) {
            $('#title').val(expTitle.trim());
        }
        // Auch #wiz-title als Fallback
        if (!$('#title').val() || $('#title').val() === 'Automatischer Entwurf') {
            var wizTitle = $('#wiz-title').val();
            if (wizTitle && wizTitle.trim()) {
                $('#title').val(wizTitle.trim());
            }
        }
    });

    // ══════════════════════════════════════════
    // SERIENTERMINE
    // ══════════════════════════════════════════

    // Toggle Serien-Panel
    $('#tix-series-toggle').on('change', function() {
        $('#tix-series-panel').toggle(this.checked);
    });

    // Modus-Umschaltung (Periodisch / Manuell)
    $('input[name="tix_series_mode"]').on('change', function() {
        var mode = $(this).val();
        $('#tix-series-periodic').toggle(mode === 'periodic');
        $('#tix-series-manual').toggle(mode === 'manual');
    });

    // Frequency-Umschaltung
    $('#tix-series-freq').on('change', function() {
        var freq = $(this).val();
        $('#tix-series-days').toggle(freq === 'weekly' || freq === 'biweekly');
        $('#tix-series-mw').toggle(freq === 'monthly_weekday');
        $('#tix-series-md').toggle(freq === 'monthly_date');
    });

    // Tage-Picker: Toggle-Style
    $(document).on('change', '.tix-day-btn input', function() {
        $(this).closest('.tix-day-btn').toggleClass('active', this.checked);
    });
    // Initialzustand
    $('.tix-day-btn input:checked').each(function() {
        $(this).closest('.tix-day-btn').addClass('active');
    });

    // Manuelle Termine: Zeile hinzufügen
    $('#tix-series-add-date').on('click', function() {
        var $tbody = $('#tix-series-dates-table tbody');
        var idx = $tbody.find('tr').length;
        $tbody.append(
            '<tr>' +
            '<td><input type="date" name="tix_series_dates[' + idx + '][date_start]" style="width:100%"></td>' +
            '<td><input type="date" name="tix_series_dates[' + idx + '][date_end]" style="width:100%"></td>' +
            '<td><button type="button" class="button tix-series-rm-date" title="Entfernen">&times;</button></td>' +
            '</tr>'
        );
    });

    // Manuelle Termine: Zeile entfernen
    $(document).on('click', '.tix-series-rm-date', function() {
        var $tbody = $(this).closest('tbody');
        if ($tbody.find('tr').length > 1) {
            $(this).closest('tr').remove();
        } else {
            // Letzte Zeile → nur leeren
            $(this).closest('tr').find('input').val('');
        }
    });

    // ══════════════════════════════════════
    // MODAL – Location / Veranstalter
    // ══════════════════════════════════════

    var placesInstances = {};

    // Modal öffnen
    $(document).on('click', '[data-modal]', function(e) {
        e.preventDefault();
        var modalId = $(this).data('modal');
        var $modal  = $('#' + modalId);
        if (!$modal.length) return;

        $modal.fadeIn(200);
        $modal.find('input:first').focus();

        // Google Places lazy init
        initPlacesInModal(modalId);
    });

    // Modal schließen (X / Abbrechen)
    $(document).on('click', '.tix-modal-close, .tix-modal-cancel', function() {
        var $modal = $(this).closest('.tix-modal-overlay');
        $modal.fadeOut(200);
        resetModal($modal);
    });

    // Overlay-Klick schließt Modal
    $(document).on('click', '.tix-modal-overlay', function(e) {
        if (e.target === this) {
            $(this).fadeOut(200);
            resetModal($(this));
        }
    });

    // ESC schließt Modal
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            var $visible = $('.tix-modal-overlay:visible');
            if ($visible.length) {
                $visible.fadeOut(200);
                resetModal($visible);
            }
        }
    });

    // AJAX speichern
    $(document).on('click', '.tix-modal-save', function() {
        var $btn    = $(this);
        var type    = $btn.data('type'); // 'location' oder 'organizer'
        var $modal  = $btn.closest('.tix-modal-overlay');
        var prefix  = type === 'location' ? 'loc' : 'org';

        var name    = $('#tix-modal-' + prefix + '-name').val().trim();
        var address = $('#tix-modal-' + prefix + '-address').val().trim();
        var desc    = $('#tix-modal-' + prefix + '-desc').val().trim();

        // Validierung
        if (!name) {
            $modal.find('.tix-modal-error').text('Bitte Name eingeben.').show();
            $('#tix-modal-' + prefix + '-name').focus();
            return;
        }

        var origHtml = $btn.html();
        $btn.prop('disabled', true).text('Erstelle…');
        $modal.find('.tix-modal-error').hide();

        $.post(tixAdmin.ajaxUrl, {
            action:      'tix_create_' + type,
            nonce:       tixAdmin.modalNonce,
            name:        name,
            address:     address,
            description: desc
        }, function(res) {
            if (res.success) {
                // In ALLE Dropdowns einfügen (Wizard + Expert)
                var selectors = type === 'location'
                    ? ['#wiz-location', '#tix_location_id']
                    : ['#wiz-organizer', '#tix_organizer_id'];

                $.each(selectors, function(i, sel) {
                    var $sel = $(sel);
                    if (!$sel.length) return;

                    var $opt = $('<option>', {
                        value: res.data.id,
                        text:  res.data.title
                    });
                    if (res.data.address) {
                        $opt.attr('data-address', res.data.address);
                    }
                    $sel.append($opt).val(res.data.id).trigger('change');
                });

                $modal.fadeOut(200);
                resetModal($modal);
            } else {
                $modal.find('.tix-modal-error').text(res.data || 'Fehler beim Erstellen.').show();
            }
        }).fail(function() {
            $modal.find('.tix-modal-error').text('Netzwerkfehler – bitte erneut versuchen.').show();
        }).always(function() {
            $btn.prop('disabled', false).html(origHtml);
        });
    });

    // Enter-Taste → Speichern (innerhalb Modal-Inputs)
    // NICHT bei Autocomplete-Inputs wenn Dropdown sichtbar ist
    $(document).on('keydown', '.tix-modal-body input', function(e) {
        if (e.key === 'Enter') {
            // Wenn Google Places Dropdown sichtbar → Enter soll Place auswählen, nicht Modal speichern
            if ($(this).hasClass('tix-places-autocomplete') && $('.pac-container:visible').length) {
                return; // Google Places handled das
            }
            e.preventDefault();
            $(this).closest('.tix-modal-overlay').find('.tix-modal-save').trigger('click');
        }
    });

    function resetModal($modal) {
        $modal.find('input, textarea').val('');
        $modal.find('.tix-modal-error').hide().text('');
    }

    // Google Places Autocomplete
    var placesRetryCount = {};
    function initPlacesInModal(modalId) {
        if (placesInstances[modalId]) {
            // Bereits initialisiert – Autocomplete-Binding nochmal prüfen
            return;
        }

        if (typeof google === 'undefined' || typeof google.maps === 'undefined' || typeof google.maps.places === 'undefined') {
            // Retry: Google API evtl. noch nicht geladen
            var retries = placesRetryCount[modalId] || 0;
            if (retries < 20) {
                placesRetryCount[modalId] = retries + 1;
                console.log('Tixomat: Google Places API nicht bereit, Retry ' + (retries + 1) + '/20 für #' + modalId);
                setTimeout(function() { initPlacesInModal(modalId); }, 500);
            } else {
                console.error('Tixomat: Google Places API konnte nicht geladen werden. API-Key in Einstellungen prüfen.');
            }
            return;
        }

        var input = document.querySelector('#' + modalId + ' .tix-places-autocomplete');
        if (!input) {
            console.warn('Tixomat: Autocomplete-Input nicht gefunden in #' + modalId);
            return;
        }

        try {
            var autocomplete = new google.maps.places.Autocomplete(input, {
                fields: ['formatted_address', 'address_components', 'name', 'geometry']
            });

            autocomplete.addListener('place_changed', function() {
                var place = autocomplete.getPlace();
                if (place) {
                    input.value = place.formatted_address || place.name || '';
                    $(input).trigger('change');
                }
            });

            placesInstances[modalId] = autocomplete;
            console.log('Tixomat: Google Places Autocomplete initialisiert für #' + modalId);
        } catch(e) {
            console.error('Tixomat: Google Places init error', e);
        }
    }

    // ══════════════════════════════════════
    // SAALPLAN-DROPDOWN (Erweitert-Tab)
    // ══════════════════════════════════════
    $(document).on('change', '#tix-event-seatmap', function() {
        var hasValue = parseInt($(this).val()) > 0;
        $('#tix-seatmap-mode-wrap').toggle(hasValue);
        // Tickets-Tab: Manuelle Kategorien vs. Saalplan-Kategorien
        if (hasValue) {
            $('#tix-manual-categories-wrap').hide();
            $('#tix-seatmap-categories-card').show();
        } else {
            $('#tix-manual-categories-wrap').show();
            $('#tix-seatmap-categories-card').hide();
        }
    });

    // ══════════════════════════════════════
    // CHARITY / SOZIALES PROJEKT
    // ══════════════════════════════════════
    $(document).on('change', '#tix-charity-toggle', function() {
        $('#tix-charity-fields')[this.checked ? 'slideDown' : 'slideUp'](200);
    });

    $(document).on('click', '#tix-charity-image-btn', function() {
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') return;

        var frame = wp.media({
            title: 'Projekt-Bild wählen',
            button: { text: 'Bild verwenden' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function() {
            var att = frame.state().get('selection').first().toJSON();
            var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
            $('#tix-charity-image-val').val(att.id);
            $('#tix-charity-image-preview').attr('src', url).show();
            $('#tix-charity-image-remove').show();
        });

        frame.open();
    });

    $(document).on('click', '#tix-charity-image-remove', function() {
        $('#tix-charity-image-val').val('');
        $('#tix-charity-image-preview').attr('src', '').hide();
        $(this).hide();
    });

})(jQuery);
