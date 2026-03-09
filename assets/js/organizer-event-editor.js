/**
 * Tixomat – Veranstalter Event-Editor (Frontend JS)
 *
 * Fullscreen-Editor mit Tabs, Repeatern, Media-Upload.
 */
(function($) {
    'use strict';

    if (typeof tixOD === 'undefined') return;

    var $dash = $('#tix-organizer-dashboard');
    var eventData = null;
    var isNew = false;

    /* ══════════════════════════════════════════
     * Editor oeffnen
     * ══════════════════════════════════════════ */

    window.tixOEOpen = function(eventId) {
        isNew = !eventId;

        if (isNew) {
            // Wizard fuer neues Event
            openWizard();
        } else {
            // Event laden und Editor oeffnen
            loadEventAndOpen(eventId);
        }
    };

    // Hook in Dashboard-Buttons
    $dash.off('click', '#tix-od-new-event').on('click', '#tix-od-new-event', function() {
        tixOEOpen(0);
    });
    $dash.off('click', '.tix-od-edit-event').on('click', '.tix-od-edit-event', function() {
        tixOEOpen($(this).data('id'));
    });

    /* ══════════════════════════════════════════
     * Event laden
     * ══════════════════════════════════════════ */

    function loadEventAndOpen(eventId) {
        ajax('tix_od_event_detail', { event_id: eventId }, function(data) {
            eventData = data;
            openEditor(data);
        });
    }

    /* ══════════════════════════════════════════
     * Wizard (3 Schritte fuer neue Events)
     * ══════════════════════════════════════════ */

    function openWizard() {
        // Locations laden
        ajax('tix_od_event_detail', { event_id: 0 }, function() {
            // Leerer Aufruf schlaegt fehl, Locations separat holen
        });

        var html = '<div class="tix-oe-overlay" id="tix-oe-overlay">'
            + '<div class="tix-oe-editor">'
            + '<div class="tix-oe-header"><h3>Neues Event erstellen</h3><button type="button" class="tix-oe-close">&times;</button></div>'

            + '<div class="tix-oe-body">'
            + '<div class="tix-oe-wizard-steps">'
            + '<div class="tix-oe-wizard-step active" data-step="1"><span class="tix-oe-wizard-num">1</span> Grunddaten</div>'
            + '<div class="tix-oe-wizard-connector"></div>'
            + '<div class="tix-oe-wizard-step" data-step="2"><span class="tix-oe-wizard-num">2</span> Tickets</div>'
            + '<div class="tix-oe-wizard-connector"></div>'
            + '<div class="tix-oe-wizard-step" data-step="3"><span class="tix-oe-wizard-num">3</span> Fertig</div>'
            + '</div>'

            // Step 1: Grunddaten
            + '<div class="tix-oe-wiz-pane active" data-wiz-step="1">'
            + '<div class="tix-oe-row"><label class="tix-oe-label">Titel *</label><input type="text" class="tix-oe-input" id="tix-oe-wiz-title" placeholder="Name des Events"></div>'
            + '<div class="tix-oe-grid-2">'
            + '<div class="tix-oe-row"><label class="tix-oe-label">Startdatum *</label><input type="date" class="tix-oe-input" id="tix-oe-wiz-date-start"></div>'
            + '<div class="tix-oe-row"><label class="tix-oe-label">Startzeit *</label><input type="time" class="tix-oe-input" id="tix-oe-wiz-time-start"></div>'
            + '</div>'
            + '<div class="tix-oe-grid-2">'
            + '<div class="tix-oe-row"><label class="tix-oe-label">Enddatum</label><input type="date" class="tix-oe-input" id="tix-oe-wiz-date-end"></div>'
            + '<div class="tix-oe-row"><label class="tix-oe-label">Endzeit</label><input type="time" class="tix-oe-input" id="tix-oe-wiz-time-end"></div>'
            + '</div>'
            + '<div class="tix-oe-row"><label class="tix-oe-label">Einlass</label><input type="time" class="tix-oe-input" id="tix-oe-wiz-time-doors" style="max-width:200px"></div>'
            + '</div>'

            // Step 2: Tickets
            + '<div class="tix-oe-wiz-pane" data-wiz-step="2">'
            + '<div class="tix-oe-section">Ticket-Kategorien</div>'
            + '<div id="tix-oe-wiz-tickets" class="tix-oe-repeater"></div>'
            + '<button type="button" class="tix-oe-repeater-add" id="tix-oe-wiz-add-ticket"><span class="dashicons dashicons-plus-alt2"></span> Ticket hinzuf&uuml;gen</button>'
            + '</div>'

            // Step 3: Zusammenfassung
            + '<div class="tix-oe-wiz-pane" data-wiz-step="3">'
            + '<div style="text-align:center;padding:24px 0;">'
            + '<span style="font-size:48px;">&#127881;</span>'
            + '<h3 style="margin:12px 0 4px;">Fast fertig!</h3>'
            + '<p style="color:#64748b;">Pr&uuml;fe die Daten und erstelle dein Event. Du kannst danach alles bearbeiten.</p>'
            + '<div id="tix-oe-wiz-summary" style="text-align:left;max-width:400px;margin:16px auto;"></div>'
            + '</div>'
            + '</div>'

            + '</div>'

            + '<div class="tix-oe-footer">'
            + '<button type="button" class="tix-od-btn tix-od-btn-secondary" id="tix-oe-wiz-prev" style="display:none">Zur&uuml;ck</button>'
            + '<button type="button" class="tix-od-btn tix-od-btn-primary" id="tix-oe-wiz-next">Weiter</button>'
            + '</div>'
            + '</div></div>';

        $('body').append(html);

        // Ein erstes Ticket hinzufuegen
        addTicketRow('#tix-oe-wiz-tickets');

        bindWizardEvents();
    }

    function bindWizardEvents() {
        var currentStep = 1;
        var totalSteps = 3;

        $(document).off('click.tixoe_wiz').on('click.tixoe_wiz', '#tix-oe-wiz-next', function() {
            if (currentStep === 1) {
                // Validierung
                var title = $('#tix-oe-wiz-title').val().trim();
                var dateStart = $('#tix-oe-wiz-date-start').val();
                var timeStart = $('#tix-oe-wiz-time-start').val();
                if (!title) { showToast('Bitte gib einen Titel ein.', 'error'); return; }
                if (!dateStart) { showToast('Bitte w\u00e4hle ein Startdatum.', 'error'); return; }
                if (!timeStart) { showToast('Bitte gib eine Startzeit ein.', 'error'); return; }
            }
            if (currentStep === 2) {
                // Mindestens 1 Ticket
                var hasTicket = false;
                $('#tix-oe-wiz-tickets .tix-oe-repeater-item').each(function() {
                    if ($(this).find('.tix-oe-tk-name').val().trim()) hasTicket = true;
                });
                if (!hasTicket) { showToast('Bitte erstelle mindestens eine Ticket-Kategorie.', 'error'); return; }

                // Zusammenfassung
                buildWizSummary();
            }
            if (currentStep === totalSteps) {
                // Event erstellen
                createEventFromWizard();
                return;
            }
            currentStep++;
            updateWizSteps(currentStep);
        });

        $(document).off('click.tixoe_wiz_prev').on('click.tixoe_wiz_prev', '#tix-oe-wiz-prev', function() {
            if (currentStep > 1) { currentStep--; updateWizSteps(currentStep); }
        });

        // Ticket hinzufuegen
        $(document).off('click.tixoe_wiz_addtk').on('click.tixoe_wiz_addtk', '#tix-oe-wiz-add-ticket', function() {
            addTicketRow('#tix-oe-wiz-tickets');
        });

        // Close
        $(document).off('click.tixoe_close').on('click.tixoe_close', '.tix-oe-close, .tix-oe-overlay', function(e) {
            if (e.target === this || $(e.target).hasClass('tix-oe-close')) {
                closeEditor();
            }
        });

        // Ticket entfernen
        $(document).off('click.tixoe_remtk').on('click.tixoe_remtk', '.tix-oe-repeater-remove', function() {
            $(this).closest('.tix-oe-repeater-item').remove();
        });

        function updateWizSteps(step) {
            $('.tix-oe-wiz-pane').removeClass('active');
            $('[data-wiz-step="' + step + '"]').addClass('active');

            $('.tix-oe-wizard-step').removeClass('active done');
            for (var i = 1; i < step; i++) {
                $('[data-step="' + i + '"]').addClass('done');
            }
            $('[data-step="' + step + '"]').addClass('active');

            $('#tix-oe-wiz-prev').toggle(step > 1);
            $('#tix-oe-wiz-next').text(step === totalSteps ? 'Event erstellen' : 'Weiter');
        }
    }

    function buildWizSummary() {
        var title = $('#tix-oe-wiz-title').val();
        var dateStart = $('#tix-oe-wiz-date-start').val();
        var timeStart = $('#tix-oe-wiz-time-start').val();
        var tickets = [];
        $('#tix-oe-wiz-tickets .tix-oe-repeater-item').each(function() {
            var n = $(this).find('.tix-oe-tk-name').val();
            var p = $(this).find('.tix-oe-tk-price').val();
            var q = $(this).find('.tix-oe-tk-qty').val();
            if (n) tickets.push(n + ' – ' + p + ' € × ' + q);
        });

        var html = '<div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">'
            + '<p><strong>Titel:</strong> ' + escHtml(title) + '</p>'
            + '<p><strong>Datum:</strong> ' + dateStart + ' ' + timeStart + '</p>'
            + '<p><strong>Tickets:</strong></p><ul>';
        tickets.forEach(function(t) { html += '<li>' + escHtml(t) + '</li>'; });
        html += '</ul></div>';
        $('#tix-oe-wiz-summary').html(html);
    }

    function createEventFromWizard() {
        var ticketCats = [];
        $('#tix-oe-wiz-tickets .tix-oe-repeater-item').each(function() {
            var n = $(this).find('.tix-oe-tk-name').val().trim();
            if (!n) return;
            ticketCats.push({
                name:  n,
                price: $(this).find('.tix-oe-tk-price').val() || '0',
                qty:   $(this).find('.tix-oe-tk-qty').val() || '0',
                online: 1,
            });
        });

        var params = {
            title:       $('#tix-oe-wiz-title').val(),
            date_start:  $('#tix-oe-wiz-date-start').val(),
            date_end:    $('#tix-oe-wiz-date-end').val(),
            time_start:  $('#tix-oe-wiz-time-start').val(),
            time_end:    $('#tix-oe-wiz-time-end').val(),
            time_doors:  $('#tix-oe-wiz-time-doors').val(),
        };

        // Tickets als Array serialisieren
        ticketCats.forEach(function(tc, i) {
            Object.keys(tc).forEach(function(k) {
                params['ticket_categories[' + i + '][' + k + ']'] = tc[k];
            });
        });

        ajax('tix_od_save_event', params, function(data) {
            closeEditor();
            showToast('Event erstellt!', 'success');
            // Events-Liste neu laden
            if (window.tixODReloadEvents) window.tixODReloadEvents();
        });
    }

    /* ══════════════════════════════════════════
     * Vollständiger Editor (Bearbeiten)
     * ══════════════════════════════════════════ */

    function openEditor(data) {
        var tabs = [
            { key: 'basics', label: 'Grunddaten', icon: 'calendar-alt' },
            { key: 'info', label: 'Info', icon: 'info' },
            { key: 'tickets', label: 'Tickets', icon: 'tickets-alt' },
            { key: 'media', label: 'Medien', icon: 'format-gallery' },
            { key: 'faq', label: 'FAQ', icon: 'editor-help' },
            { key: 'discounts', label: 'Rabattcodes', icon: 'tag' },
            { key: 'raffle', label: 'Gewinnspiel', icon: 'tickets' },
            { key: 'timetable', label: 'Programm', icon: 'schedule' },
            { key: 'presale', label: 'Vorverkauf', icon: 'clock' },
        ];

        var tabsHtml = '';
        tabs.forEach(function(t, i) {
            tabsHtml += '<button type="button" class="tix-oe-tab' + (i === 0 ? ' active' : '') + '" data-oe-tab="' + t.key + '">'
                + '<span class="dashicons dashicons-' + t.icon + '"></span> ' + t.label + '</button>';
        });

        var html = '<div class="tix-oe-overlay" id="tix-oe-overlay">'
            + '<div class="tix-oe-editor">'
            + '<div class="tix-oe-header"><h3>' + escHtml(data.title) + ' bearbeiten</h3><button type="button" class="tix-oe-close">&times;</button></div>'
            + '<div class="tix-oe-tabs">' + tabsHtml + '</div>'
            + '<div class="tix-oe-body">'
            + buildBasicsPane(data)
            + buildInfoPane(data)
            + buildTicketsPane(data)
            + buildMediaPane(data)
            + buildFaqPane(data)
            + buildDiscountsPane(data)
            + buildRafflePane(data)
            + buildTimetablePane(data)
            + buildPresalePane(data)
            + '</div>'
            + '<div class="tix-oe-footer">'
            + '<button type="button" class="tix-od-btn tix-od-btn-secondary tix-oe-close">Abbrechen</button>'
            + '<button type="button" class="tix-od-btn tix-od-btn-primary" id="tix-oe-save">Speichern</button>'
            + '</div>'
            + '</div></div>';

        $('body').append(html);
        bindEditorEvents(data);
    }

    /* ── Tab-Pane Builders ── */

    function buildBasicsPane(d) {
        var locOptions = '<option value="0">– Keine Location –</option>';
        if (d.locations) {
            d.locations.forEach(function(l) {
                var sel = l.id === d.location_id ? ' selected' : '';
                locOptions += '<option value="' + l.id + '"' + sel + '>' + escHtml(l.title) + (l.address ? ' (' + escHtml(l.address) + ')' : '') + '</option>';
            });
        }
        var statusOpts = '';
        ['available', 'sold_out', 'cancelled', 'postponed'].forEach(function(s) {
            var labels = { available: 'Verf\u00fcgbar', sold_out: 'Ausverkauft', cancelled: 'Abgesagt', postponed: 'Verschoben' };
            statusOpts += '<option value="' + s + '"' + (d.event_status === s ? ' selected' : '') + '>' + labels[s] + '</option>';
        });

        return '<div class="tix-oe-pane active" data-oe-pane="basics">'
            + field('Titel', '<input type="text" class="tix-oe-input" id="tix-oe-title" value="' + escAttr(d.title) + '">')
            + '<div class="tix-oe-grid-2">'
            + field('Startdatum', '<input type="date" class="tix-oe-input" id="tix-oe-date-start" value="' + (d.date_start || '') + '">')
            + field('Startzeit', '<input type="time" class="tix-oe-input" id="tix-oe-time-start" value="' + (d.time_start || '') + '">')
            + '</div>'
            + '<div class="tix-oe-grid-3">'
            + field('Enddatum', '<input type="date" class="tix-oe-input" id="tix-oe-date-end" value="' + (d.date_end || '') + '">')
            + field('Endzeit', '<input type="time" class="tix-oe-input" id="tix-oe-time-end" value="' + (d.time_end || '') + '">')
            + field('Einlass', '<input type="time" class="tix-oe-input" id="tix-oe-time-doors" value="' + (d.time_doors || '') + '">')
            + '</div>'
            + field('Location', '<select class="tix-oe-select" id="tix-oe-location">' + locOptions + '</select>')
            + field('Event-Status', '<select class="tix-oe-select" id="tix-oe-status" style="max-width:200px">' + statusOpts + '</select>')
            + '</div>';
    }

    function buildInfoPane(d) {
        return '<div class="tix-oe-pane" data-oe-pane="info">'
            + field('Kurzbeschreibung', '<textarea class="tix-oe-textarea" id="tix-oe-short-desc" rows="2">' + escHtml(d.short_description || '') + '</textarea>')
            + field('Beschreibung', '<textarea class="tix-oe-textarea" id="tix-oe-description" rows="6">' + escHtml(d.description || '') + '</textarea>')
            + field('K&uuml;nstler / Artist', '<textarea class="tix-oe-textarea" id="tix-oe-artist" rows="3">' + escHtml(d.artist_description || '') + '</textarea>')
            + '</div>';
    }

    function buildTicketsPane(d) {
        var cats = d.ticket_categories || [];
        var html = '<div class="tix-oe-pane" data-oe-pane="tickets">'
            + '<div class="tix-oe-section">Ticket-Kategorien</div>'
            + '<div id="tix-oe-tickets" class="tix-oe-repeater">';

        cats.forEach(function(c, i) {
            html += ticketRowHtml(c, i);
        });

        html += '</div>'
            + '<button type="button" class="tix-oe-repeater-add" id="tix-oe-add-ticket"><span class="dashicons dashicons-plus-alt2"></span> Ticket hinzuf&uuml;gen</button>'
            + '</div>';
        return html;
    }

    function buildMediaPane(d) {
        var thumbHtml = d.featured_image_url
            ? '<img src="' + d.featured_image_url + '" alt="">'
            : '<span class="dashicons dashicons-cloud-upload" style="font-size:32px"></span><br>Bild hochladen';
        var cls = d.featured_image_url ? 'has-image' : '';

        return '<div class="tix-oe-pane" data-oe-pane="media">'
            + '<div class="tix-oe-section">Titelbild</div>'
            + '<div class="tix-oe-upload-zone ' + cls + '" id="tix-oe-featured-upload" data-image-id="' + (d.featured_image || 0) + '">'
            + thumbHtml
            + '</div>'
            + (d.featured_image ? '<button type="button" class="tix-od-btn tix-od-btn-sm tix-od-btn-danger tix-oe-upload-remove" id="tix-oe-featured-remove">Entfernen</button>' : '')
            + '<div class="tix-oe-section" style="margin-top:24px">Video-URL</div>'
            + field('', '<input type="url" class="tix-oe-input" id="tix-oe-video-url" value="' + escAttr(d.video_url || '') + '" placeholder="https://youtube.com/...">')
            + '</div>';
    }

    function buildFaqPane(d) {
        var faqs = d.faq || [];
        var html = '<div class="tix-oe-pane" data-oe-pane="faq">'
            + '<div class="tix-oe-section">H&auml;ufig gestellte Fragen</div>'
            + '<div id="tix-oe-faq" class="tix-oe-repeater">';
        faqs.forEach(function(f, i) {
            html += '<div class="tix-oe-repeater-item">'
                + '<button type="button" class="tix-oe-repeater-remove">&times;</button>'
                + field('Frage', '<input type="text" class="tix-oe-input tix-oe-faq-q" value="' + escAttr(f.q || '') + '">')
                + field('Antwort', '<textarea class="tix-oe-textarea tix-oe-faq-a" rows="2">' + escHtml(f.a || '') + '</textarea>')
                + '</div>';
        });
        html += '</div>'
            + '<button type="button" class="tix-oe-repeater-add" id="tix-oe-add-faq"><span class="dashicons dashicons-plus-alt2"></span> FAQ hinzuf&uuml;gen</button>'
            + '</div>';
        return html;
    }

    function buildDiscountsPane(d) {
        var codes = d.discount_codes || [];
        var html = '<div class="tix-oe-pane" data-oe-pane="discounts">'
            + '<div class="tix-oe-section">Rabattcodes</div>'
            + '<div id="tix-oe-discounts" class="tix-oe-repeater">';
        codes.forEach(function(dc) {
            html += discountRowHtml(dc);
        });
        html += '</div>'
            + '<button type="button" class="tix-oe-repeater-add" id="tix-oe-add-discount"><span class="dashicons dashicons-plus-alt2"></span> Rabattcode hinzuf&uuml;gen</button>'
            + '</div>';
        return html;
    }

    function buildRafflePane(d) {
        var en = d.raffle_enabled === '1' ? 'checked' : '';
        var prizes = d.raffle_prizes || [];
        var html = '<div class="tix-oe-pane" data-oe-pane="raffle">'
            + '<div class="tix-oe-checkbox-row"><input type="checkbox" id="tix-oe-raffle-enabled" ' + en + '> <label for="tix-oe-raffle-enabled">Gewinnspiel aktivieren</label></div>'
            + '<div id="tix-oe-raffle-fields"' + (en ? '' : ' style="display:none"') + '>'
            + field('Titel', '<input type="text" class="tix-oe-input" id="tix-oe-raffle-title" value="' + escAttr(d.raffle_title || '') + '">')
            + field('Beschreibung', '<textarea class="tix-oe-textarea" id="tix-oe-raffle-desc" rows="2">' + escHtml(d.raffle_description || '') + '</textarea>')
            + '<div class="tix-oe-grid-2">'
            + field('Enddatum', '<input type="datetime-local" class="tix-oe-input" id="tix-oe-raffle-end" value="' + (d.raffle_end_date || '') + '">')
            + field('Max. Teilnahmen', '<input type="number" class="tix-oe-input" id="tix-oe-raffle-max" value="' + (d.raffle_max_entries || 0) + '" min="0">')
            + '</div>'
            + '<div class="tix-oe-section">Preise</div>'
            + '<div id="tix-oe-raffle-prizes" class="tix-oe-repeater">';
        prizes.forEach(function(p) {
            html += '<div class="tix-oe-repeater-item">'
                + '<button type="button" class="tix-oe-repeater-remove">&times;</button>'
                + '<div class="tix-oe-grid-2">'
                + field('Name', '<input type="text" class="tix-oe-input tix-oe-prize-name" value="' + escAttr(p.name || '') + '">')
                + field('Anzahl', '<input type="number" class="tix-oe-input tix-oe-prize-qty" value="' + (p.qty || 1) + '" min="1">')
                + '</div></div>';
        });
        html += '</div>'
            + '<button type="button" class="tix-oe-repeater-add" id="tix-oe-add-prize"><span class="dashicons dashicons-plus-alt2"></span> Preis hinzuf&uuml;gen</button>'
            + '</div>'
            + '</div>';
        return html;
    }

    function buildTimetablePane(d) {
        var stages = d.stages || [];
        var tt = d.timetable || {};
        var html = '<div class="tix-oe-pane" data-oe-pane="timetable">'
            + '<div class="tix-oe-section">B&uuml;hnen / R&auml;ume</div>'
            + '<div id="tix-oe-stages" class="tix-oe-repeater">';
        stages.forEach(function(s) {
            html += '<div class="tix-oe-repeater-item">'
                + '<button type="button" class="tix-oe-repeater-remove">&times;</button>'
                + '<div class="tix-oe-grid-2">'
                + field('Name', '<input type="text" class="tix-oe-input tix-oe-stage-name" value="' + escAttr(s.name || '') + '">')
                + field('Farbe', '<input type="color" class="tix-oe-stage-color" value="' + (s.color || '#6366f1') + '" style="width:50px;height:36px;padding:2px;border:1px solid #dfe2e6;border-radius:6px">')
                + '</div></div>';
        });
        html += '</div>'
            + '<button type="button" class="tix-oe-repeater-add" id="tix-oe-add-stage"><span class="dashicons dashicons-plus-alt2"></span> B&uuml;hne hinzuf&uuml;gen</button>'
            + '<div class="tix-oe-section" style="margin-top:24px">Programm-Slots</div>'
            + '<p style="color:#94a3b8;font-size:13px;">Programm-Slots k&ouml;nnen aktuell &uuml;ber den Admin verwaltet werden. Frontend-Editor folgt in einem sp&auml;teren Update.</p>'
            + '</div>';
        return html;
    }

    function buildPresalePane(d) {
        var active = d.presale_active === '1' ? 'checked' : '';
        var wl = d.waitlist_enabled === '1' ? 'checked' : '';
        return '<div class="tix-oe-pane" data-oe-pane="presale">'
            + '<div class="tix-oe-checkbox-row"><input type="checkbox" id="tix-oe-presale-active" ' + active + '> <label for="tix-oe-presale-active">Vorverkauf aktivieren</label></div>'
            + '<div id="tix-oe-presale-fields"' + (active ? '' : ' style="display:none"') + '>'
            + field('Presale-Start', '<input type="datetime-local" class="tix-oe-input" id="tix-oe-presale-start" value="' + (d.presale_start || '') + '">')
            + '</div>'
            + '<div class="tix-oe-checkbox-row" style="margin-top:16px"><input type="checkbox" id="tix-oe-waitlist" ' + wl + '> <label for="tix-oe-waitlist">Warteliste bei Ausverkauf aktivieren</label></div>'
            + '</div>';
    }

    /* ── Helper ── */

    function field(label, inputHtml) {
        if (!label) return '<div class="tix-oe-row">' + inputHtml + '</div>';
        return '<div class="tix-oe-row"><label class="tix-oe-label">' + label + '</label>' + inputHtml + '</div>';
    }

    function ticketRowHtml(c, i) {
        c = c || {};
        return '<div class="tix-oe-repeater-item">'
            + '<button type="button" class="tix-oe-repeater-remove">&times;</button>'
            + '<div class="tix-oe-grid-3">'
            + field('Name', '<input type="text" class="tix-oe-input tix-oe-tk-name" value="' + escAttr(c.name || '') + '" placeholder="z.B. Standard">')
            + field('Preis (&euro;)', '<input type="number" class="tix-oe-input tix-oe-tk-price" value="' + (c.price || '') + '" min="0" step="0.01">')
            + field('Menge', '<input type="number" class="tix-oe-input tix-oe-tk-qty" value="' + (c.qty || '') + '" min="0">')
            + '</div>'
            + '<div class="tix-oe-grid-2">'
            + field('Sale-Preis (&euro;)', '<input type="number" class="tix-oe-input tix-oe-tk-sale" value="' + (c.sale_price || '') + '" min="0" step="0.01">')
            + field('Beschreibung', '<input type="text" class="tix-oe-input tix-oe-tk-desc" value="' + escAttr(c.desc || '') + '" placeholder="Optional">')
            + '</div>'
            + '</div>';
    }

    function addTicketRow(container) {
        $(container).append(ticketRowHtml({}, 0));
    }

    function discountRowHtml(dc) {
        dc = dc || {};
        var typeOpts = '<option value="percent"' + (dc.type === 'percent' ? ' selected' : '') + '>Prozent (%)</option>'
            + '<option value="fixed_cart"' + (dc.type === 'fixed_cart' ? ' selected' : '') + '>Festbetrag (&euro;)</option>';
        return '<div class="tix-oe-repeater-item">'
            + '<button type="button" class="tix-oe-repeater-remove">&times;</button>'
            + '<input type="hidden" class="tix-oe-dc-coupon-id" value="' + (dc.coupon_id || 0) + '">'
            + '<div class="tix-oe-grid-3">'
            + field('Code', '<input type="text" class="tix-oe-input tix-oe-dc-code" value="' + escAttr(dc.code || '') + '" placeholder="z.B. EARLY20">')
            + field('Typ', '<select class="tix-oe-select tix-oe-dc-type">' + typeOpts + '</select>')
            + field('Wert', '<input type="number" class="tix-oe-input tix-oe-dc-amount" value="' + (dc.amount || '') + '" min="0" step="0.01">')
            + '</div>'
            + '<div class="tix-oe-grid-3">'
            + field('Limit', '<input type="number" class="tix-oe-input tix-oe-dc-limit" value="' + (dc.limit || '') + '" min="0" placeholder="0 = unbegrenzt">')
            + field('Ablaufdatum', '<input type="date" class="tix-oe-input tix-oe-dc-expiry" value="' + (dc.expiry || '') + '">')
            + field('Genutzt', '<input type="text" class="tix-oe-input" value="' + (dc.usage || 0) + '" readonly style="background:#f8fafc">')
            + '</div></div>';
    }

    /* ══════════════════════════════════════════
     * Editor Events binden
     * ══════════════════════════════════════════ */

    function bindEditorEvents(data) {
        // Tab-Switching
        $(document).off('click.tixoe_tab').on('click.tixoe_tab', '.tix-oe-tab', function() {
            var tab = $(this).data('oe-tab');
            $('.tix-oe-tab').removeClass('active');
            $(this).addClass('active');
            $('.tix-oe-pane').removeClass('active');
            $('[data-oe-pane="' + tab + '"]').addClass('active');
        });

        // Close
        $(document).off('click.tixoe_close').on('click.tixoe_close', '.tix-oe-close', function(e) {
            e.stopPropagation();
            closeEditor();
        });
        $(document).off('click.tixoe_overlay').on('click.tixoe_overlay', '.tix-oe-overlay', function(e) {
            if ($(e.target).hasClass('tix-oe-overlay')) closeEditor();
        });

        // Repeater: Add
        $(document).off('click.tixoe_addtk').on('click.tixoe_addtk', '#tix-oe-add-ticket', function() {
            addTicketRow('#tix-oe-tickets');
        });
        $(document).off('click.tixoe_addfaq').on('click.tixoe_addfaq', '#tix-oe-add-faq', function() {
            $('#tix-oe-faq').append(
                '<div class="tix-oe-repeater-item"><button type="button" class="tix-oe-repeater-remove">&times;</button>'
                + field('Frage', '<input type="text" class="tix-oe-input tix-oe-faq-q">')
                + field('Antwort', '<textarea class="tix-oe-textarea tix-oe-faq-a" rows="2"></textarea>')
                + '</div>'
            );
        });
        $(document).off('click.tixoe_adddc').on('click.tixoe_adddc', '#tix-oe-add-discount', function() {
            $('#tix-oe-discounts').append(discountRowHtml({}));
        });
        $(document).off('click.tixoe_addprize').on('click.tixoe_addprize', '#tix-oe-add-prize', function() {
            $('#tix-oe-raffle-prizes').append(
                '<div class="tix-oe-repeater-item"><button type="button" class="tix-oe-repeater-remove">&times;</button>'
                + '<div class="tix-oe-grid-2">'
                + field('Name', '<input type="text" class="tix-oe-input tix-oe-prize-name">')
                + field('Anzahl', '<input type="number" class="tix-oe-input tix-oe-prize-qty" value="1" min="1">')
                + '</div></div>'
            );
        });
        $(document).off('click.tixoe_addstage').on('click.tixoe_addstage', '#tix-oe-add-stage', function() {
            $('#tix-oe-stages').append(
                '<div class="tix-oe-repeater-item"><button type="button" class="tix-oe-repeater-remove">&times;</button>'
                + '<div class="tix-oe-grid-2">'
                + field('Name', '<input type="text" class="tix-oe-input tix-oe-stage-name">')
                + field('Farbe', '<input type="color" class="tix-oe-stage-color" value="#6366f1" style="width:50px;height:36px;padding:2px;border:1px solid #dfe2e6;border-radius:6px">')
                + '</div></div>'
            );
        });

        // Repeater: Remove
        $(document).off('click.tixoe_rem').on('click.tixoe_rem', '.tix-oe-repeater-remove', function() {
            $(this).closest('.tix-oe-repeater-item').remove();
        });

        // Toggles
        $(document).off('change.tixoe_raffle').on('change.tixoe_raffle', '#tix-oe-raffle-enabled', function() {
            $('#tix-oe-raffle-fields').toggle($(this).is(':checked'));
        });
        $(document).off('change.tixoe_presale').on('change.tixoe_presale', '#tix-oe-presale-active', function() {
            $('#tix-oe-presale-fields').toggle($(this).is(':checked'));
        });

        // Image Upload
        $(document).off('click.tixoe_upload').on('click.tixoe_upload', '#tix-oe-featured-upload', function() {
            var $zone = $(this);
            var $input = $('<input type="file" accept="image/*" style="display:none">');
            $('body').append($input);
            $input.trigger('click');
            $input.on('change', function() {
                var file = this.files[0];
                if (!file) return;
                var fd = new FormData();
                fd.append('file', file);
                fd.append('action', 'tix_od_upload_media');
                fd.append('nonce', tixOD.nonce);
                $.ajax({
                    url: tixOD.ajax,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            $zone.html('<img src="' + resp.data.url + '" alt="">').addClass('has-image').data('image-id', resp.data.id);
                            if (!$('#tix-oe-featured-remove').length) {
                                $zone.after('<button type="button" class="tix-od-btn tix-od-btn-sm tix-od-btn-danger tix-oe-upload-remove" id="tix-oe-featured-remove">Entfernen</button>');
                            }
                        }
                    }
                });
                $input.remove();
            });
        });

        $(document).off('click.tixoe_remimg').on('click.tixoe_remimg', '#tix-oe-featured-remove', function() {
            $('#tix-oe-featured-upload')
                .html('<span class="dashicons dashicons-cloud-upload" style="font-size:32px"></span><br>Bild hochladen')
                .removeClass('has-image')
                .data('image-id', 0);
            $(this).remove();
        });

        // Save
        $(document).off('click.tixoe_save').on('click.tixoe_save', '#tix-oe-save', function() {
            saveEvent(data.id);
        });

        // ESC
        $(document).off('keydown.tixoe_esc').on('keydown.tixoe_esc', function(e) {
            if (e.key === 'Escape') closeEditor();
        });
    }

    /* ══════════════════════════════════════════
     * Event speichern
     * ══════════════════════════════════════════ */

    function saveEvent(eventId) {
        var params = {
            event_id:    eventId,
            title:       $('#tix-oe-title').val(),
            date_start:  $('#tix-oe-date-start').val(),
            date_end:    $('#tix-oe-date-end').val(),
            time_start:  $('#tix-oe-time-start').val(),
            time_end:    $('#tix-oe-time-end').val(),
            time_doors:  $('#tix-oe-time-doors').val(),
            location_id: $('#tix-oe-location').val(),
            event_status: $('#tix-oe-status').val(),
            short_description: $('#tix-oe-short-desc').val(),
            description: $('#tix-oe-description').val(),
            artist_description: $('#tix-oe-artist').val(),
            video_url:   $('#tix-oe-video-url').val(),
            featured_image: $('#tix-oe-featured-upload').data('image-id') || 0,
            presale_active: $('#tix-oe-presale-active').is(':checked') ? 1 : 0,
            presale_start: $('#tix-oe-presale-start').val(),
            waitlist_enabled: $('#tix-oe-waitlist').is(':checked') ? 1 : 0,
            raffle_enabled: $('#tix-oe-raffle-enabled').is(':checked') ? 1 : 0,
            raffle_title: $('#tix-oe-raffle-title').val(),
            raffle_description: $('#tix-oe-raffle-desc').val(),
            raffle_end_date: $('#tix-oe-raffle-end').val(),
            raffle_max_entries: $('#tix-oe-raffle-max').val(),
        };

        // Ticket-Kategorien
        $('#tix-oe-tickets .tix-oe-repeater-item').each(function(i) {
            var $item = $(this);
            params['ticket_categories[' + i + '][name]'] = $item.find('.tix-oe-tk-name').val();
            params['ticket_categories[' + i + '][price]'] = $item.find('.tix-oe-tk-price').val();
            params['ticket_categories[' + i + '][qty]'] = $item.find('.tix-oe-tk-qty').val();
            params['ticket_categories[' + i + '][sale_price]'] = $item.find('.tix-oe-tk-sale').val();
            params['ticket_categories[' + i + '][desc]'] = $item.find('.tix-oe-tk-desc').val();
            params['ticket_categories[' + i + '][online]'] = 1;
        });

        // FAQ
        $('#tix-oe-faq .tix-oe-repeater-item').each(function(i) {
            params['faq[' + i + '][q]'] = $(this).find('.tix-oe-faq-q').val();
            params['faq[' + i + '][a]'] = $(this).find('.tix-oe-faq-a').val();
        });

        // Raffle Prizes
        $('#tix-oe-raffle-prizes .tix-oe-repeater-item').each(function(i) {
            params['raffle_prizes[' + i + '][name]'] = $(this).find('.tix-oe-prize-name').val();
            params['raffle_prizes[' + i + '][qty]'] = $(this).find('.tix-oe-prize-qty').val();
            params['raffle_prizes[' + i + '][type]'] = 'text';
        });

        // Stages
        $('#tix-oe-stages .tix-oe-repeater-item').each(function(i) {
            params['stages[' + i + '][name]'] = $(this).find('.tix-oe-stage-name').val();
            params['stages[' + i + '][color]'] = $(this).find('.tix-oe-stage-color').val();
        });

        ajax('tix_od_save_event', params, function(data) {
            closeEditor();
            showToast(data.message || 'Gespeichert!', 'success');
            if (window.tixODReloadEvents) window.tixODReloadEvents();
        });
    }

    /* ══════════════════════════════════════════
     * Editor schliessen
     * ══════════════════════════════════════════ */

    function closeEditor() {
        $('#tix-oe-overlay').remove();
        // Event-Bindings aufraumen
        $(document).off('.tixoe_tab .tixoe_close .tixoe_overlay .tixoe_save .tixoe_esc');
        $(document).off('.tixoe_addtk .tixoe_addfaq .tixoe_adddc .tixoe_addprize .tixoe_addstage .tixoe_rem');
        $(document).off('.tixoe_raffle .tixoe_presale .tixoe_upload .tixoe_remimg');
        $(document).off('.tixoe_wiz .tixoe_wiz_prev .tixoe_wiz_addtk .tixoe_remtk');
    }

    /* ══════════════════════════════════════════
     * Reload-Hook fuer Dashboard
     * ══════════════════════════════════════════ */

    window.tixODReloadEvents = function() {
        // Cache invalidieren und Events neu laden
        var $tab = $dash.find('[data-tab="events"]').filter('.tix-od-tab');
        if ($tab.hasClass('active')) {
            var $list = $('#tix-od-events-list');
            $list.html('<div class="tix-od-loading"><div class="tix-od-spinner"></div></div>');
            ajax('tix_od_events', {}, function(data) {
                // Reuse loadEvents logic from organizer-dashboard.js
                // Trigger a custom event that organizer-dashboard.js listens for
                $(document).trigger('tix-od-events-reload', [data]);
            });
        }
    };

    /* ══════════════════════════════════════════
     * Utils
     * ══════════════════════════════════════════ */

    function ajax(action, params, onSuccess) {
        params = params || {};
        params.action = action;
        params.nonce  = tixOD.nonce;

        $.post(tixOD.ajax, params, function(resp) {
            if (resp.success && onSuccess) {
                onSuccess(resp.data);
            } else if (!resp.success) {
                showToast(resp.data ? resp.data.message : 'Fehler aufgetreten.', 'error');
            }
        }).fail(function() {
            showToast('Verbindungsfehler.', 'error');
        });
    }

    function showToast(msg, type) {
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

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

})(jQuery);
