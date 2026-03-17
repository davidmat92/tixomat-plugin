/**
 * Tixomat – Table Reservation SPA
 * Calendar → Event → Category → Booking Form → Confirmation
 *
 * @since 1.30.0
 */
(function () {
    'use strict';

    var cfg = window.tixTableRes || {};
    var app;
    var i18n = cfg.i18n || {};

    // ── State ──
    var state = {
        screen: 'calendar',
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1,
        events: [],
        // Event detail
        event: null,
        categories: [],
        paymentMode: 'on_site',
        depositType: 'percent',
        depositValue: 0,
        // Booking
        selectedCat: null,
        selectedTable: null,
        guests: 1,
        customerName: (cfg.user && cfg.user.name) || '',
        customerEmail: (cfg.user && cfg.user.email) || '',
        customerPhone: (cfg.user && cfg.user.phone) ? cfg.user.phone.replace(/^\+49\s?/, '').replace(/^0049\s?/, '').replace(/^0/, '') : '',
        comments: '',
        // State
        loading: false,
        error: '',
        reservation: null,
    };

    var isModal = false;
    var modalEventId = null;
    var mainApp = null;

    // ── Init ──
    function init() {
        app = document.getElementById('tix-table-res-app');
        if (!app) return;

        // Apply accent color
        if (cfg.accentColor) {
            app.style.setProperty('--tx-accent', cfg.accentColor);
        }

        // Check URL for direct event link
        var hash = window.location.hash;
        var urlParams = new URLSearchParams(window.location.search);
        var eventId = urlParams.get('event') || '';
        if (hash.match(/#event=(\d+)/)) {
            eventId = hash.match(/#event=(\d+)/)[1];
        }

        if (eventId) {
            loadEventDetail(parseInt(eventId));
        } else {
            loadCalendar();
        }
    }

    // ── AJAX Helper ──
    function ajax(action, data, cb) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce);
        for (var k in data) {
            if (data.hasOwnProperty(k)) fd.append(k, data[k]);
        }

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.success) {
                    cb(null, json.data);
                } else {
                    cb(json.data && json.data.message ? json.data.message : 'Unbekannter Fehler');
                }
            })
            .catch(function (err) {
                cb('Verbindungsfehler: ' + err.message);
            });
    }

    // ── Render ──
    function render() {
        var screens = {
            calendar: renderCalendar,
            event: renderEvent,
            form: renderForm,
            success: renderSuccess,
        };
        var fn = screens[state.screen];
        if (fn) {
            app.innerHTML = fn();
        }
    }

    // ══════════════════════════════════════
    // CALENDAR SCREEN
    // ══════════════════════════════════════

    function loadCalendar() {
        state.screen = 'calendar';
        state.loading = true;
        state.error = '';
        render();

        ajax('tix_table_events', { year: state.year, month: state.month }, function (err, data) {
            state.loading = false;
            if (err) { state.error = err; render(); return; }
            state.events = data.events || [];
            render();
        });
    }

    function renderCalendar() {
        var months = i18n.months || ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
        var weekdays = i18n.weekdays || ['Mo','Di','Mi','Do','Fr','Sa','So'];

        var html = '';

        // Nav
        html += '<div class="tr-calendar-nav">';
        html += '<button onclick="window._trPrevMonth()">←</button>';
        html += '<span class="tr-calendar-month">' + months[state.month - 1] + ' ' + state.year + '</span>';
        html += '<button onclick="window._trNextMonth()">→</button>';
        html += '</div>';

        if (state.loading) {
            html += '<div class="tr-loading"><div class="tr-spinner"></div>Lade Events...</div>';
            return html;
        }
        if (state.error) {
            html += '<div class="tr-screen"><div class="tr-error">' + esc(state.error) + '</div></div>';
            return html;
        }

        // Build calendar grid
        var firstDay = new Date(state.year, state.month - 1, 1);
        var lastDay = new Date(state.year, state.month, 0);
        var startDow = (firstDay.getDay() + 6) % 7; // Monday = 0
        var daysInMonth = lastDay.getDate();
        var today = new Date();
        var todayStr = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());

        // Events by date
        var eventsByDate = {};
        state.events.forEach(function (ev) {
            if (!eventsByDate[ev.date]) eventsByDate[ev.date] = [];
            eventsByDate[ev.date].push(ev);
        });

        html += '<div class="tr-calendar-grid">';

        // Weekday headers
        weekdays.forEach(function (wd) {
            html += '<div class="tr-weekday-header">' + wd + '</div>';
        });

        // Empty cells before first day
        for (var e = 0; e < startDow; e++) {
            html += '<div class="tr-day-cell tr-empty"></div>';
        }

        // Day cells
        for (var d = 1; d <= daysInMonth; d++) {
            var dateStr = state.year + '-' + pad(state.month) + '-' + pad(d);
            var dayEvents = eventsByDate[dateStr] || [];
            var isToday = dateStr === todayStr;
            var isPast = dateStr < todayStr;
            var hasEvent = dayEvents.length > 0;

            var cls = 'tr-day-cell';
            if (isToday) cls += ' tr-today';
            if (isPast) cls += ' tr-past';
            if (hasEvent) cls += ' tr-has-event';

            var onclick = '';
            if (hasEvent && dayEvents.length === 1) {
                onclick = ' onclick="window._trSelectEvent(' + dayEvents[0].id + ')"';
            } else if (hasEvent && dayEvents.length > 1) {
                // For multiple events on same day, click the first one (could add dropdown later)
                onclick = ' onclick="window._trSelectEvent(' + dayEvents[0].id + ')"';
            }

            html += '<div class="' + cls + '"' + onclick + '>';
            html += '<div class="tr-day-number">' + d + '</div>';

            dayEvents.forEach(function (ev) {
                html += '<div class="tr-day-event">';
                if (ev.thumbnail) {
                    html += '<img src="' + esc(ev.thumbnail) + '" alt="' + esc(ev.title) + '" loading="lazy">';
                }
                html += '<div class="tr-day-event-title">' + esc(ev.title) + '</div>';
                html += '</div>';
            });

            html += '</div>';
        }

        // Empty cells after last day
        var totalCells = startDow + daysInMonth;
        var remaining = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
        for (var r = 0; r < remaining; r++) {
            html += '<div class="tr-day-cell tr-empty"></div>';
        }

        html += '</div>';

        if (state.events.length === 0 && !state.loading) {
            html += '<div class="tr-no-events">' + (i18n.noEvents || 'Keine Events in diesem Monat.') + '</div>';
        }

        return html;
    }

    // ══════════════════════════════════════
    // EVENT DETAIL SCREEN
    // ══════════════════════════════════════

    function loadEventDetail(eventId) {
        state.screen = 'event';
        state.loading = true;
        state.error = '';
        render();

        ajax('tix_table_event_detail', { event_id: eventId }, function (err, data) {
            state.loading = false;
            if (err) { state.error = err; render(); return; }
            state.event = data.event;
            state.categories = data.categories || [];
            state.paymentMode = data.payment_mode || 'on_site';
            state.depositType = data.deposit_type || 'percent';
            state.depositValue = parseFloat(data.deposit_value) || 0;
            state.infoText = data.info_text || '';
            render();
        });
    }

    function renderEvent() {
        var html = '';

        // Header
        html += '<div class="tr-header">';
        html += '<button class="tr-back-btn" onclick="window._trBackToCalendar()">← ' + (i18n.back || 'Zurück') + '</button>';
        html += '<span class="tr-header-title">' + (i18n.tables || 'Tische') + '</span>';
        html += '</div>';

        if (state.loading) {
            html += '<div class="tr-loading"><div class="tr-spinner"></div></div>';
            return html;
        }
        if (state.error) {
            html += '<div class="tr-screen"><div class="tr-error">' + esc(state.error) + '</div></div>';
            return html;
        }

        var ev = state.event;
        if (!ev) return html;

        // Event Hero
        html += '<div class="tr-event-hero">';
        if (ev.thumbnail) {
            html += '<img class="tr-event-poster" src="' + esc(ev.thumbnail) + '" alt="' + esc(ev.title) + '">';
        }
        html += '<div class="tr-event-info">';
        html += '<h2 class="tr-event-title">' + esc(ev.title) + '</h2>';
        html += '<div class="tr-event-meta">';
        var metaParts = [esc(ev.date_formatted)];
        if (ev.time_start) {
            metaParts.push(esc(ev.time_start) + ' Uhr');
        }
        if (ev.location) {
            metaParts.push(esc(ev.location));
        }
        html += '<span>' + metaParts.join(' · ') + '</span>';
        html += '</div></div></div>';

        // Info Text
        if (state.infoText) {
            html += '<div class="tr-info-text">';
            html += '<span>' + esc(state.infoText) + '</span>';
            html += '</div>';
        }

        // Floor Plan – interactive or static
        var hasInteractiveTables = false;
        if (ev.floor_plan) {
            // Check if any category has tables with x/y positions
            state.categories.forEach(function (cat) {
                if (cat.tables && cat.tables.length > 0) {
                    cat.tables.forEach(function (t) {
                        if (t.x > 0 || t.y > 0) hasInteractiveTables = true;
                    });
                }
            });

            if (hasInteractiveTables) {
                html += '<div class="tr-floor-plan-interactive">';
                html += '<img src="' + esc(ev.floor_plan) + '" alt="Raumplan">';
                // Overlay markers
                state.categories.forEach(function (cat) {
                    if (!cat.tables || cat.tables.length === 0) return;
                    cat.tables.forEach(function (t) {
                        if (!t.name) return;
                        var cls = t.reserved ? 'tr-marker reserved' : 'tr-marker available';
                        var onclick = t.reserved ? '' : ' onclick="window._trSelectTable(' + cat.index + ',\'' + esc(t.name).replace(/'/g, "\\'") + '\')"';
                        html += '<div class="' + cls + '" style="left:' + t.x + '%;top:' + t.y + '%"' + onclick + '>';
                        html += '<div class="tr-marker-dot"></div>';
                        html += '<div class="tr-marker-label">' + esc(t.name) + '</div>';
                        html += '</div>';
                    });
                });
                html += '</div>';
            } else {
                html += '<div class="tr-floor-plan">';
                html += '<button class="tr-floor-plan-toggle" onclick="window._trToggleFloorPlan()">';
                html += (i18n.floorPlan || 'Raumplan') + ' anzeigen';
                html += '</button>';
                html += '<img class="tr-floor-plan-img" id="tr-floor-plan" src="' + esc(ev.floor_plan) + '" alt="Raumplan">';
                html += '</div>';
            }
        }

        // Categories
        html += '<div class="tr-categories">';
        html += '<div class="tr-categories-title">Verfügbare Tische</div>';

        state.categories.forEach(function (cat) {
            var soldOut = cat.available <= 0;
            var few = cat.available > 0 && cat.available <= 2;

            html += '<div class="tr-cat-card' + (soldOut ? ' tr-sold-out' : '') + '" onclick="window._trSelectCat(' + cat.index + ')">';
            html += '<div class="tr-cat-card-info">';
            html += '<div class="tr-cat-name">' + esc(cat.name) + '</div>';
            if (cat.desc) {
                html += '<div class="tr-cat-desc">' + esc(cat.desc) + '</div>';
            }
            html += '<div class="tr-cat-badges">';
            html += '<span class="tr-cat-badge">' + cat.min_guests + '–' + cat.max_guests + ' ' + (i18n.persons || 'Personen') + '</span>';
            html += '</div>';
            html += '</div>';
            html += '<div class="tr-cat-right">';
            if (cat.min_spend > 0) {
                html += '<div class="tr-cat-price">' + formatPrice(cat.min_spend) + '</div>';
                html += '<div class="tr-cat-price-label">' + (i18n.minSpend || 'Mindestverzehr') + '</div>';
            }
            if (soldOut) {
                html += '<div class="tr-cat-avail" style="color:var(--tx-red,#E53B3B)">' + (i18n.soldOut || 'Ausgebucht') + '</div>';
            } else {
                html += '<div class="tr-cat-avail' + (few ? ' tr-few' : '') + '">' + cat.available + ' ' + (i18n.available || 'verfügbar') + '</div>';
            }
            html += '</div>';
            html += '<span class="tr-cat-arrow">→</span>';
            html += '</div>';
        });

        if (state.categories.length === 0) {
            html += '<div class="tr-no-events">Keine Tischkategorien konfiguriert.</div>';
        }

        html += '</div>';

        return html;
    }

    // ══════════════════════════════════════
    // BOOKING FORM SCREEN
    // ══════════════════════════════════════

    function renderForm() {
        var ev = state.event;
        var cat = state.selectedCat;
        if (!ev || !cat) return '';

        var html = '';

        // Header
        html += '<div class="tr-header">';
        html += '<button class="tr-back-btn" onclick="window._trBackToEvent()">← ' + (i18n.back || 'Zurück') + '</button>';
        html += '<span class="tr-header-title">' + (i18n.yourReservation || 'Deine Reservierung') + '</span>';
        html += '</div>';

        html += '<div class="tr-form-wrap">';

        // Summary
        html += '<div class="tr-form-summary">';
        html += '<div class="tr-form-summary-title">' + esc(ev.title) + '</div>';
        html += '<div class="tr-form-summary-cat">' + esc(cat.name) + (state.selectedTable ? ' – ' + esc(state.selectedTable) : '') + '</div>';
        html += '<div class="tr-form-summary-meta">';
        html += esc(ev.date_formatted) + '<br>';
        if (ev.time_start) html += esc(ev.time_start) + ' Uhr<br>';
        if (ev.location) html += esc(ev.location);
        html += '</div></div>';

        // Error
        if (state.error) {
            html += '<div class="tr-error">' + esc(state.error) + '</div>';
        }

        // Guest Count
        html += '<div class="tr-form-group">';
        html += '<label class="tr-form-label">' + (i18n.guestCount || 'Anzahl Gäste') + ' <span class="tr-required">*</span></label>';
        html += '<div class="tr-guest-stepper">';
        html += '<button onclick="window._trGuests(-1)"' + (state.guests <= cat.min_guests ? ' disabled' : '') + '>−</button>';
        html += '<span class="tr-guest-count">' + state.guests + '</span>';
        html += '<button onclick="window._trGuests(1)"' + (state.guests >= cat.max_guests ? ' disabled' : '') + '>+</button>';
        html += '<span style="color:var(--tx-text-muted,rgba(13,11,9,0.40));font-size:13px;margin-left:8px">' + cat.min_guests + '–' + cat.max_guests + ' ' + (i18n.persons || 'Personen') + '</span>';
        html += '</div></div>';

        // Name
        html += '<div class="tr-form-group">';
        html += '<label class="tr-form-label">' + (i18n.name || 'Name') + ' <span class="tr-required">*</span></label>';
        html += '<input class="tr-form-input" type="text" id="tr-name" value="' + esc(state.customerName) + '" placeholder="Max Mustermann">';
        html += '</div>';

        // Email
        html += '<div class="tr-form-group">';
        html += '<label class="tr-form-label">' + (i18n.email || 'E-Mail') + ' <span class="tr-required">*</span></label>';
        html += '<input class="tr-form-input" type="email" id="tr-email" value="' + esc(state.customerEmail) + '" placeholder="max@beispiel.de">';
        html += '</div>';

        // Phone
        html += '<div class="tr-form-group">';
        html += '<label class="tr-form-label">' + (i18n.phone || 'Telefon') + '</label>';
        html += '<div class="tr-phone-wrap">';
        html += '<input class="tr-form-input tr-phone-prefix" type="text" value="+49" readonly>';
        html += '<input class="tr-form-input" type="tel" id="tr-phone" value="' + esc(state.customerPhone) + '" placeholder="170 1234567">';
        html += '</div></div>';

        // Comments
        html += '<div class="tr-form-group">';
        html += '<label class="tr-form-label">' + (i18n.comments || 'Anmerkungen') + '</label>';
        html += '<textarea class="tr-form-textarea" id="tr-comments" placeholder="' + esc(i18n.commentsPlaceholder || 'Besondere Wünsche, Allergien, Anlass...') + '">' + esc(state.comments) + '</textarea>';
        html += '</div>';

        // Price Summary – Mindestverzehr ist der Zahlbetrag
        var price = cat.min_spend || 0;
        html += '<div class="tr-price-summary">';

        if (price > 0) {
            html += '<div class="tr-price-row"><span>' + (i18n.minSpend || 'Mindestverzehr') + '</span><span>' + formatPrice(price) + '</span></div>';

            if (state.paymentMode === 'deposit') {
                var deposit = state.depositType === 'percent'
                    ? Math.round(price * state.depositValue) / 100
                    : Math.min(state.depositValue, price);
                html += '<div class="tr-price-total"><span>' + (i18n.deposit || 'Anzahlung') + '</span><span>' + formatPrice(deposit) + '</span></div>';
                html += '<div class="tr-price-note">Restzahlung von ' + formatPrice(price - deposit) + ' vor Ort</div>';
            } else if (state.paymentMode === 'full') {
                html += '<div class="tr-price-total"><span>' + (i18n.total || 'Gesamt') + '</span><span>' + formatPrice(price) + '</span></div>';
            } else {
                html += '<div class="tr-price-total"><span>' + (i18n.total || 'Gesamt') + '</span><span>' + formatPrice(price) + '</span></div>';
                html += '<div class="tr-price-note">' + (i18n.payOnSite || 'Zahlung vor Ort') + '</div>';
            }
        } else {
            html += '<div class="tr-price-row"><span>Kostenlose Reservierung</span><span>0 €</span></div>';
        }

        html += '</div>';

        // Submit
        var btnText = i18n.submit || 'Jetzt reservieren';
        if (price > 0 && state.paymentMode === 'full') btnText = i18n.payOnline || 'Jetzt bezahlen & reservieren';
        if (price > 0 && state.paymentMode === 'deposit') btnText = i18n.payDeposit || 'Anzahlung & reservieren';

        html += '<button class="tr-submit-btn" onclick="window._trSubmit()"' + (state.loading ? ' disabled' : '') + '>';
        if (state.loading) {
            html += '<span class="tr-spinner" style="width:20px;height:20px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:8px"></span>';
        }
        html += btnText + '</button>';

        html += '</div>';

        return html;
    }

    // ══════════════════════════════════════
    // SUCCESS SCREEN
    // ══════════════════════════════════════

    function renderSuccess() {
        var res = state.reservation;
        if (!res) return '';

        var html = '<div class="tr-success">';
        html += '<div class="tr-success-icon" style="color:var(--tx-green,#1DB86A)">&#10003;</div>';
        html += '<h2 class="tr-success-title">' + (i18n.confirmed || 'Reservierung bestätigt!') + '</h2>';
        html += '<p class="tr-success-text">' + (i18n.confirmText || 'Du erhältst eine Bestätigung per E-Mail.') + '</p>';

        html += '<div class="tr-success-details">';
        html += '<div class="tr-success-row"><span class="tr-success-row-label">Event</span><span class="tr-success-row-value">' + esc(res.event) + '</span></div>';
        if (res.date) {
            html += '<div class="tr-success-row"><span class="tr-success-row-label">Datum</span><span class="tr-success-row-value">' + esc(res.date) + '</span></div>';
        }
        if (res.time) {
            html += '<div class="tr-success-row"><span class="tr-success-row-label">Uhrzeit</span><span class="tr-success-row-value">' + esc(res.time) + ' Uhr</span></div>';
        }
        if (res.location) {
            html += '<div class="tr-success-row"><span class="tr-success-row-label">Location</span><span class="tr-success-row-value">' + esc(res.location) + '</span></div>';
        }
        html += '<div class="tr-success-row"><span class="tr-success-row-label">Tisch</span><span class="tr-success-row-value">' + esc(res.category) + '</span></div>';
        html += '<div class="tr-success-row"><span class="tr-success-row-label">Gäste</span><span class="tr-success-row-value">' + res.guests + '</span></div>';
        if (res.total > 0) {
            html += '<div class="tr-success-row"><span class="tr-success-row-label">Betrag</span><span class="tr-success-row-value">' + formatPrice(res.total) + '</span></div>';
        }
        html += '<div class="tr-success-row"><span class="tr-success-row-label">Reservierungs-Nr.</span><span class="tr-success-row-value">#' + res.id + '</span></div>';
        html += '</div>';

        html += '<button class="tr-new-btn" onclick="window._trNewReservation()">' + (i18n.newReservation || 'Neue Reservierung') + '</button>';
        html += '</div>';

        return html;
    }

    // ══════════════════════════════════════
    // ACTIONS (exposed to window)
    // ══════════════════════════════════════

    window._trPrevMonth = function () {
        state.month--;
        if (state.month < 1) { state.month = 12; state.year--; }
        loadCalendar();
    };

    window._trNextMonth = function () {
        state.month++;
        if (state.month > 12) { state.month = 1; state.year++; }
        loadCalendar();
    };

    window._trSelectEvent = function (eventId) {
        loadEventDetail(eventId);
    };

    window._trBackToCalendar = function () {
        if (isModal) {
            window._trCloseModal();
            return;
        }
        state.event = null;
        state.categories = [];
        state.selectedCat = null;
        state.selectedTable = null;
        state.error = '';
        loadCalendar();
    };

    window._trBackToEvent = function () {
        state.selectedCat = null;
        state.selectedTable = null;
        state.error = '';
        state.screen = 'event';
        render();
    };

    window._trSelectCat = function (catIndex) {
        var cat = null;
        state.categories.forEach(function (c) {
            if (c.index === catIndex) cat = c;
        });
        if (!cat || cat.available <= 0) return;

        state.selectedCat = cat;
        state.guests = cat.min_guests;
        state.error = '';
        state.screen = 'form';
        render();
    };

    window._trToggleFloorPlan = function () {
        var img = document.getElementById('tr-floor-plan');
        if (img) img.classList.toggle('visible');
    };

    window._trGuests = function (delta) {
        var cat = state.selectedCat;
        if (!cat) return;
        var newVal = state.guests + delta;
        if (newVal >= cat.min_guests && newVal <= cat.max_guests) {
            state.guests = newVal;
            render();
        }
    };

    window._trSubmit = function () {
        // Read form values
        var nameEl = document.getElementById('tr-name');
        var emailEl = document.getElementById('tr-email');
        var phoneEl = document.getElementById('tr-phone');
        var commentsEl = document.getElementById('tr-comments');

        state.customerName = nameEl ? nameEl.value.trim() : '';
        state.customerEmail = emailEl ? emailEl.value.trim() : '';
        state.customerPhone = phoneEl ? '+49' + phoneEl.value.trim() : '';
        state.comments = commentsEl ? commentsEl.value.trim() : '';

        // Validate
        if (!state.customerName || !state.customerEmail) {
            state.error = i18n.required || 'Bitte fülle alle Pflichtfelder aus.';
            render();
            return;
        }

        state.loading = true;
        state.error = '';
        render();

        var submitData = {
            event_id: state.event.id,
            category_index: state.selectedCat.index,
            customer_name: state.customerName,
            customer_email: state.customerEmail,
            customer_phone: state.customerPhone,
            guest_count: state.guests,
            comments: state.comments,
        };
        if (state.selectedTable) {
            submitData.table_name = state.selectedTable;
        }

        ajax('tix_table_submit', submitData, function (err, data) {
            state.loading = false;

            if (err) {
                state.error = err;
                render();
                return;
            }

            if (data.status === 'payment_required' && data.checkout_url) {
                // Redirect to WC checkout
                state.screen = 'form';
                state.error = '';
                render();
                // Show redirect message briefly
                app.innerHTML = '<div class="tr-loading"><div class="tr-spinner"></div>' + (i18n.redirecting || 'Weiterleitung zur Zahlung...') + '</div>';
                setTimeout(function () {
                    window.location.href = data.checkout_url;
                }, 1000);
                return;
            }

            // Success (on_site or already paid)
            state.reservation = data.reservation;
            state.screen = 'success';
            render();
        });
    };

    window._trNewReservation = function () {
        if (isModal && modalEventId) {
            state.selectedCat = null;
            state.selectedTable = null;
            state.reservation = null;
            state.customerName = '';
            state.customerEmail = '';
            state.customerPhone = '';
            state.comments = '';
            state.guests = 1;
            state.error = '';
            loadEventDetail(modalEventId);
            return;
        }
        state.screen = 'calendar';
        state.event = null;
        state.categories = [];
        state.selectedCat = null;
        state.selectedTable = null;
        state.reservation = null;
        state.customerName = '';
        state.customerEmail = '';
        state.customerPhone = '';
        state.comments = '';
        state.guests = 1;
        state.error = '';
        loadCalendar();
    };

    // ══════════════════════════════════════
    // MODAL
    // ══════════════════════════════════════

    window._trOpenModal = function (eventId) {
        var overlay = document.getElementById('tix-table-res-modal');
        if (!overlay) return;

        // Move modal to body to escape any parent overflow/transform constraints
        if (overlay.parentNode !== document.body) {
            document.body.appendChild(overlay);
        }

        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        mainApp = app;
        app = document.getElementById('tix-table-res-app-modal');
        isModal = true;
        modalEventId = eventId;
        loadEventDetail(eventId);

        // Close on overlay background click
        overlay.addEventListener('click', function handler(e) {
            if (e.target === overlay) {
                window._trCloseModal();
                overlay.removeEventListener('click', handler);
            }
        });
    };

    window._trCloseModal = function () {
        var overlay = document.getElementById('tix-table-res-modal');
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';
        isModal = false;
        modalEventId = null;
        if (mainApp) {
            app = mainApp;
            mainApp = null;
        }
    };

    // ══════════════════════════════════════
    // TABLE SELECTION (Floor Plan)
    // ══════════════════════════════════════

    window._trSelectTable = function (catIndex, tableName) {
        var cat = null;
        state.categories.forEach(function (c) {
            if (c.index === catIndex) cat = c;
        });
        if (!cat || cat.available <= 0) return;

        // Check specific table is available
        if (cat.tables && cat.tables.length > 0) {
            var tbl = null;
            cat.tables.forEach(function (t) {
                if (t.name === tableName) tbl = t;
            });
            if (!tbl || tbl.reserved) return;
        }

        state.selectedCat = cat;
        state.selectedTable = tableName;
        state.guests = cat.min_guests;
        state.error = '';
        state.screen = 'form';
        render();
    };

    // ══════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════

    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function formatPrice(amount) {
        var sym = cfg.currency || '€';
        return parseFloat(amount).toFixed(2).replace('.', ',') + ' ' + sym;
    }

    // ── Boot ──
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
