/**
 * Tixomat POS – Fullscreen SPA
 * State Machine: pin → events → sale → payment → change → success
 * Plus: report, transactions
 * @since 1.29.0
 */
(function () {
    'use strict';

    var cfg = window.tixPOS;
    if (!cfg) return;

    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); };

    // ── State ──
    var state = {
        screen: cfg.pinRequired ? 'pin' : 'events',
        pin: '',
        user: null,
        eventFilter: 'all',
        searchQuery: '',
        events: [],
        selectedEvent: null,
        eventTitle: '',
        categories: [],
        cart: [],    // [{product_id, name, price, qty, stock}]
        couponCode: '',
        customerName: '',
        customerEmail: '',
        cartTotal: 0,
        payment: '',
        givenAmount: '',
        changeAmount: 0,
        orderResult: null,
        dailyStats: { tickets: 0, revenue: 0 },
        resetTimer: null,
        resetStart: 0,
    };

    var app = document.getElementById('tix-pos-app');
    if (!app) return;

    // ── Init ──
    render();
    bindKeyboard();

    // ── Render Engine ──
    function render() {
        var screens = {
            pin: renderPin,
            events: renderEvents,
            sale: renderSale,
            payment: renderPayment,
            change: renderChange,
            success: renderSuccess,
            report: renderReport,
            transactions: renderTransactions,
        };
        var fn = screens[state.screen];
        if (!fn) return;

        var html = fn();
        app.innerHTML = html;

        // Post-render hooks
        afterRender();
    }

    function afterRender() {
        // Focus search on events screen
        if (state.screen === 'events') {
            var s = $('.pos-search', app);
            if (s) setTimeout(function () { s.focus(); }, 100);
        }
        // Focus PIN numpad
        if (state.screen === 'pin') {
            app.focus();
        }
        // Render QR codes on success
        if (state.screen === 'success' && state.orderResult) {
            renderQRCodes();
            startAutoReset();
        }
        // Load data
        if (state.screen === 'events' && state.events.length === 0) {
            loadEvents();
        }
        if (state.screen === 'report') {
            loadReport();
        }
        if (state.screen === 'transactions') {
            loadTransactions();
        }
    }

    // ── Screen: PIN ──
    function renderPin() {
        var dots = '';
        for (var i = 0; i < 6; i++) {
            dots += '<div class="pos-pin-dot' + (i < state.pin.length ? ' filled' : '') + '"></div>';
        }
        return '<div class="pos-screen active"><div class="pos-pin">' +
            '<img class="pos-pin-logo-img" src="' + (cfg.logoUrl || '') + '" alt="Tixomat POS">' +
            '<div class="pos-pin-title">PIN eingeben</div>' +
            '<div class="pos-pin-dots" id="pos-pin-dots">' + dots + '</div>' +
            '<div class="pos-pin-error" id="pos-pin-error"></div>' +
            '<div class="pos-numpad">' +
            numpadBtn(1) + numpadBtn(2) + numpadBtn(3) +
            numpadBtn(4) + numpadBtn(5) + numpadBtn(6) +
            numpadBtn(7) + numpadBtn(8) + numpadBtn(9) +
            '<button class="pos-numpad-btn muted" onclick="window._posPin(\'clear\')">Löschen</button>' +
            numpadBtn(0) +
            '<button class="pos-numpad-btn accent" onclick="window._posPin(\'enter\')">✓</button>' +
            '</div></div></div>';
    }

    function numpadBtn(n) {
        return '<button class="pos-numpad-btn" onclick="window._posPin(\'' + n + '\')">' + n + '</button>';
    }

    window._posPin = function (val) {
        if (val === 'clear') {
            state.pin = state.pin.slice(0, -1);
        } else if (val === 'enter') {
            if (state.pin.length >= 4) doAuth();
            return;
        } else {
            if (state.pin.length < 6) state.pin += val;
            if (state.pin.length === 6) {
                setTimeout(doAuth, 150);
            }
        }
        // Update dots only
        var dots = $('#pos-pin-dots', app);
        if (dots) {
            var html = '';
            for (var i = 0; i < 6; i++) {
                html += '<div class="pos-pin-dot' + (i < state.pin.length ? ' filled' : '') + '"></div>';
            }
            dots.innerHTML = html;
        }
    };

    function doAuth() {
        ajax('tix_pos_auth', { pin: state.pin }, function (data) {
            state.user = data;
            state.pin = '';
            go('events');
        }, function (msg) {
            state.pin = '';
            var dots = $('#pos-pin-dots', app);
            if (dots) {
                dots.classList.add('shake');
                setTimeout(function () { dots.classList.remove('shake'); }, 400);
            }
            var err = $('#pos-pin-error', app);
            if (err) err.textContent = msg || 'PIN ungültig';
            render();
        });
    }

    // ── Screen: Events ──
    function renderEvents() {
        var filters = ['today', 'week', 'all'];
        var labels = { today: 'Heute', week: 'Diese Woche', all: 'Alle' };
        var filterHtml = '';
        filters.forEach(function (f) {
            filterHtml += '<button class="pos-filter-btn' + (state.eventFilter === f ? ' active' : '') + '" onclick="window._posFilter(\'' + f + '\')">' + labels[f] + '</button>';
        });

        var eventsHtml = '';
        if (state.events.length === 0) {
            eventsHtml = '<div class="pos-no-events">Keine Events gefunden</div>';
        } else {
            var filtered = state.events;
            if (state.searchQuery) {
                var q = state.searchQuery.toLowerCase();
                filtered = filtered.filter(function (e) {
                    return e.title.toLowerCase().indexOf(q) >= 0 || (e.location || '').toLowerCase().indexOf(q) >= 0;
                });
            }
            eventsHtml = '<div class="pos-events-grid">';
            filtered.forEach(function (e) {
                var dateStr = formatDate(e.date);
                if (e.time) dateStr += ' · ' + e.time.substring(0, 5);
                eventsHtml += '<div class="pos-event-card' + (e.is_today ? ' today' : '') + '" onclick="window._posSelectEvent(' + e.id + ')">' +
                    (e.image ? '<img class="pos-event-img" src="' + esc(e.image) + '" alt="">' : '<div class="pos-event-img"></div>') +
                    '<div class="pos-event-info">' +
                    '<div class="pos-event-title">' + esc(e.title) + '</div>' +
                    '<div class="pos-event-meta">' +
                    '<span>📅 ' + dateStr + '</span>' +
                    (e.location ? '<span>📍 ' + esc(e.location) + '</span>' : '') +
                    '</div>' +
                    '<div class="pos-event-badge' + (e.is_today ? ' today-badge' : '') + '">' +
                    '🎟️ ' + e.sold + ' / ' + e.capacity +
                    '</div></div></div>';
            });
            eventsHtml += '</div>';
        }

        return topbar('Events', null, [
            iconBtn('📊', 'window._posGo(\'report\')', 'Bericht'),
            iconBtn('📋', 'window._posGo(\'transactions\')', 'Transaktionen'),
            iconBtn('🔒', 'window._posLock()', 'Sperren'),
        ]) +
            '<div class="pos-events">' +
            '<div class="pos-events-filter">' + filterHtml +
            '<input class="pos-search" placeholder="🔍 Event suchen…" value="' + esc(state.searchQuery) + '" oninput="window._posSearch(this.value)">' +
            '</div>' + eventsHtml + '</div>' +
            statusbar();
    }

    window._posFilter = function (f) {
        state.eventFilter = f;
        state.events = [];
        render();
        loadEvents();
    };

    window._posSearch = function (q) {
        state.searchQuery = q;
        // Re-render events grid only
        var grid = $('.pos-events', app);
        if (grid) {
            var filtered = state.events;
            if (q) {
                var ql = q.toLowerCase();
                filtered = filtered.filter(function (e) {
                    return e.title.toLowerCase().indexOf(ql) >= 0 || (e.location || '').toLowerCase().indexOf(ql) >= 0;
                });
            }
            var eventsHtml = '';
            if (filtered.length === 0) {
                eventsHtml = '<div class="pos-no-events">Keine Events gefunden</div>';
            } else {
                eventsHtml = '<div class="pos-events-grid">';
                filtered.forEach(function (e) {
                    var dateStr = formatDate(e.date);
                    if (e.time) dateStr += ' · ' + e.time.substring(0, 5);
                    eventsHtml += '<div class="pos-event-card' + (e.is_today ? ' today' : '') + '" onclick="window._posSelectEvent(' + e.id + ')">' +
                        (e.image ? '<img class="pos-event-img" src="' + esc(e.image) + '" alt="">' : '<div class="pos-event-img"></div>') +
                        '<div class="pos-event-info">' +
                        '<div class="pos-event-title">' + esc(e.title) + '</div>' +
                        '<div class="pos-event-meta">' +
                        '<span>📅 ' + dateStr + '</span>' +
                        (e.location ? '<span>📍 ' + esc(e.location) + '</span>' : '') +
                        '</div>' +
                        '<div class="pos-event-badge' + (e.is_today ? ' today-badge' : '') + '">' +
                        '🎟️ ' + e.sold + ' / ' + e.capacity +
                        '</div></div></div>';
                });
                eventsHtml += '</div>';
            }
            // Replace only grid portion
            var filterBar = $('.pos-events-filter', grid);
            grid.innerHTML = '';
            if (filterBar) grid.appendChild(filterBar);
            grid.innerHTML = '<div class="pos-events-filter">' + grid.innerHTML + '</div>';
            // Actually, simpler to just re-render
            render();
        }
    };

    window._posSelectEvent = function (id) {
        state.selectedEvent = id;
        state.cart = [];
        state.couponCode = '';
        state.customerName = '';
        state.customerEmail = '';
        loadTickets(id);
    };

    // ── Screen: Sale ──
    function renderSale() {
        var catHtml = '<div class="pos-cat-grid">';
        state.categories.forEach(function (c, idx) {
            var soldOut = c.stock <= 0;
            catHtml += '<div class="pos-cat-tile' + (soldOut ? ' sold-out' : '') + '" onclick="window._posAddToCart(' + idx + ')">' +
                '<div class="pos-cat-name">' + esc(c.name) + '</div>' +
                '<div class="pos-cat-price">' + money(c.price) + '</div>' +
                '<div class="pos-cat-stock">Noch ' + c.stock + ' verfügbar</div>' +
                (soldOut ? '<div class="pos-cat-sold-out">Ausverkauft</div>' : '') +
                '</div>';
        });
        catHtml += '</div>';

        var cartItemsHtml = '';
        if (state.cart.length === 0) {
            cartItemsHtml = '<div class="pos-cart-empty">Warenkorb ist leer</div>';
        } else {
            cartItemsHtml = '<div class="pos-cart-items">';
            state.cart.forEach(function (item, idx) {
                cartItemsHtml += '<div class="pos-cart-item">' +
                    '<div class="pos-cart-item-info">' +
                    '<div class="pos-cart-item-name">' + esc(item.name) + '</div>' +
                    '<div class="pos-cart-item-price">' + money(item.price) + ' / Stk.</div>' +
                    '</div>' +
                    '<div class="pos-cart-item-actions">' +
                    '<button class="pos-qty-btn remove" onclick="window._posCartQty(' + idx + ',-1)">−</button>' +
                    '<span class="pos-cart-qty">' + item.qty + '</span>' +
                    '<button class="pos-qty-btn" onclick="window._posCartQty(' + idx + ',1)">+</button>' +
                    '</div>' +
                    '<div class="pos-cart-item-total">' + money(item.price * item.qty) + '</div>' +
                    '</div>';
            });
            cartItemsHtml += '</div>';
        }

        calcTotal();

        var customerHtml = '';
        if (cfg.requireName || cfg.requireEmail) {
            customerHtml = '<div class="pos-customer-fields">';
            if (cfg.requireName) {
                customerHtml += '<input placeholder="Kundenname" value="' + esc(state.customerName) + '" onchange="window._posCustomer(\'name\',this.value)">';
            }
            if (cfg.requireEmail) {
                customerHtml += '<input type="email" placeholder="E-Mail" value="' + esc(state.customerEmail) + '" onchange="window._posCustomer(\'email\',this.value)">';
            }
            customerHtml += '</div>';
        }

        return topbar(state.eventTitle, function () { go('events'); }, [
            iconBtn('📊', 'window._posGo(\'report\')', 'Bericht'),
            iconBtn('📋', 'window._posGo(\'transactions\')', 'Transaktionen'),
            iconBtn('🔒', 'window._posLock()', 'Sperren'),
        ]) +
            '<div class="pos-sale">' +
            '<div class="pos-categories">' + catHtml + '</div>' +
            '<div class="pos-cart">' +
            '<div class="pos-cart-header">🛒 Warenkorb (' + cartCount() + ')</div>' +
            (state.cart.length ? cartItemsHtml : '<div class="pos-cart-empty">Warenkorb ist leer</div>') +
            '<div class="pos-cart-footer">' +
            '<div class="pos-coupon-row">' +
            '<input class="pos-coupon-input" placeholder="🎫 Coupon-Code" value="' + esc(state.couponCode) + '" onchange="window._posCoupon(this.value)">' +
            '</div>' +
            customerHtml +
            '<div class="pos-cart-total"><span>Gesamt</span><span class="pos-cart-total-value">' + money(state.cartTotal) + '</span></div>' +
            '<button class="pos-pay-btn" ' + (state.cart.length === 0 ? 'disabled' : '') + ' onclick="window._posGo(\'payment\')">💰 Bezahlen</button>' +
            '</div></div></div>' +
            statusbar();
    }

    window._posAddToCart = function (catIdx) {
        var cat = state.categories[catIdx];
        if (!cat || cat.stock <= 0) return;

        var existing = state.cart.find(function (c) { return c.product_id === cat.product_id; });
        if (existing) {
            if (existing.qty < cat.stock) existing.qty++;
        } else {
            state.cart.push({
                product_id: cat.product_id,
                name: cat.name,
                price: cat.price,
                qty: 1,
                stock: cat.stock,
            });
        }
        render();
    };

    window._posCartQty = function (idx, delta) {
        var item = state.cart[idx];
        if (!item) return;
        item.qty += delta;
        if (item.qty <= 0) {
            state.cart.splice(idx, 1);
        } else {
            // Check stock
            var cat = state.categories.find(function (c) { return c.product_id === item.product_id; });
            if (cat && item.qty > cat.stock) item.qty = cat.stock;
        }
        render();
    };

    window._posCoupon = function (v) { state.couponCode = v; };
    window._posCustomer = function (field, v) {
        if (field === 'name') state.customerName = v;
        if (field === 'email') state.customerEmail = v;
    };

    // ── Screen: Payment ──
    function renderPayment() {
        calcTotal();

        var methods = [
            { key: 'cash', icon: '💵', label: 'Barzahlung' },
            { key: 'card', icon: '💳', label: 'EC-Karte' },
        ];
        if (cfg.allowFree) {
            methods.push({ key: 'free', icon: '🆓', label: 'Kostenlos' });
        }

        var btnsHtml = '';
        methods.forEach(function (m) {
            btnsHtml += '<button class="pos-payment-btn ' + m.key + '" onclick="window._posSelectPayment(\'' + m.key + '\')">' +
                '<span class="pos-payment-icon">' + m.icon + '</span>' + m.label + '</button>';
        });

        return topbar('Zahlung', function () { go('sale'); }) +
            '<div class="pos-payment">' +
            '<div class="pos-payment-label">Zu zahlen</div>' +
            '<div class="pos-payment-amount">' + money(state.cartTotal) + '</div>' +
            '<div class="pos-payment-methods">' + btnsHtml + '</div>' +
            '</div>';
    }

    window._posSelectPayment = function (method) {
        state.payment = method;
        if (method === 'cash') {
            state.givenAmount = '';
            state.changeAmount = 0;
            go('change');
        } else {
            createOrder();
        }
    };

    // ── Screen: Change Calculator ──
    function renderChange() {
        calcTotal();
        var given = parseFloat(state.givenAmount) || 0;
        var change = given - state.cartTotal;
        state.changeAmount = change;

        var amounts = [5, 10, 20, 50, 100];
        var quickHtml = '';
        amounts.forEach(function (a) {
            quickHtml += '<button class="pos-quick-btn" onclick="window._posGiven(\'' + a + '\')">' + a + ' €</button>';
        });
        quickHtml += '<button class="pos-quick-btn exact" onclick="window._posGiven(\'exact\')">Passend</button>';

        var resultClass = given === 0 ? '' : (change >= 0 ? ' ok' : ' short');
        var resultText = given === 0 ? '—' : money(Math.abs(change));
        var resultLabel = change >= 0 ? 'Rückgeld:' : 'Es fehlen:';
        if (given === 0) resultLabel = 'Rückgeld:';

        return topbar('Barzahlung', function () { go('payment'); }) +
            '<div class="pos-change">' +
            '<div class="pos-change-due">Zu zahlen: <strong>' + money(state.cartTotal) + '</strong></div>' +
            '<div class="pos-change-given-label">Gegeben:</div>' +
            '<div class="pos-change-given">' + (state.givenAmount ? state.givenAmount + ' €' : '—') + '</div>' +
            '<div class="pos-numpad" style="margin:0">' +
            numpadBtnChange(1) + numpadBtnChange(2) + numpadBtnChange(3) +
            numpadBtnChange(4) + numpadBtnChange(5) + numpadBtnChange(6) +
            numpadBtnChange(7) + numpadBtnChange(8) + numpadBtnChange(9) +
            '<button class="pos-numpad-btn muted" onclick="window._posGivenKey(\'clear\')">C</button>' +
            numpadBtnChange(0) +
            '<button class="pos-numpad-btn muted" onclick="window._posGivenKey(\'.\')">.</button>' +
            '</div>' +
            '<div class="pos-quick-amounts">' + quickHtml + '</div>' +
            '<div class="pos-change-result' + resultClass + '">' + resultLabel + ' <strong>' + resultText + '</strong></div>' +
            '<button class="pos-change-confirm" ' + (change < 0 && given > 0 ? 'disabled' : '') + ' onclick="window._posConfirmCash()">Verkauf abschließen</button>' +
            '</div>';
    }

    function numpadBtnChange(n) {
        return '<button class="pos-numpad-btn" onclick="window._posGivenKey(\'' + n + '\')">' + n + '</button>';
    }

    window._posGiven = function (val) {
        if (val === 'exact') {
            state.givenAmount = state.cartTotal.toFixed(2);
        } else {
            state.givenAmount = val.toString();
        }
        render();
    };

    window._posGivenKey = function (key) {
        if (key === 'clear') {
            state.givenAmount = '';
        } else if (key === '.') {
            if (state.givenAmount.indexOf('.') < 0) {
                state.givenAmount += state.givenAmount ? '.' : '0.';
            }
        } else {
            state.givenAmount += key;
        }
        render();
    };

    window._posConfirmCash = function () {
        createOrder();
    };

    // ── Screen: Success ──
    function renderSuccess() {
        if (!state.orderResult) return '<div class="pos-screen active"><div class="pos-loading"><div class="pos-spinner"></div></div></div>';

        var ticketsHtml = '<div class="pos-tickets-list">';
        (state.orderResult.tickets || []).forEach(function (t) {
            ticketsHtml += '<div class="pos-ticket-card">' +
                '<div style="font-weight:600">' + esc(t.category || t.event) + '</div>' +
                '<div class="pos-ticket-qr" data-qr="' + esc(t.qr_data) + '"></div>' +
                '<div class="pos-ticket-code">' + esc(t.code) + '</div>' +
                '</div>';
        });
        ticketsHtml += '</div>';

        var checkSvg = '<svg class="pos-check-svg" viewBox="0 0 80 80"><circle cx="40" cy="40" r="38"/><path d="M24 42 L35 53 L56 28"/></svg>';

        return topbar('Verkauf abgeschlossen', null, []) +
            '<div class="pos-success">' +
            checkSvg +
            '<div class="pos-success-title">Verkauf erfolgreich!</div>' +
            '<div class="pos-success-subtitle">Bestellung #' + state.orderResult.order_id + ' · ' + money(state.orderResult.total) + '</div>' +
            ticketsHtml +
            '<div class="pos-success-actions">' +
            '<button class="pos-success-btn secondary" onclick="window._posEmailDialog()">📧 Per E-Mail senden</button>' +
            '<button class="pos-success-btn primary" onclick="window._posNewSale()">🆕 Neuer Verkauf</button>' +
            '</div></div>' +
            '<div class="pos-reset-bar" id="pos-reset-bar"></div>' +
            statusbar();
    }

    function renderQRCodes() {
        var containers = $$('.pos-ticket-qr', app);
        containers.forEach(function (el) {
            var data = el.getAttribute('data-qr');
            if (!data) return;
            if (window.ehQR && window.ehQR.render) {
                window.ehQR.render(el, data, { size: 124 });
            } else {
                // Fallback: just show text
                el.textContent = data;
                el.style.fontSize = '10px';
                el.style.wordBreak = 'break-all';
            }
        });
    }

    function startAutoReset() {
        clearTimeout(state.resetTimer);
        var duration = (cfg.autoReset || 10) * 1000;
        state.resetStart = Date.now();

        var bar = $('#pos-reset-bar', app);
        if (bar) {
            bar.style.width = '100%';
            // Force reflow
            bar.offsetWidth;
            bar.style.transitionDuration = duration + 'ms';
            bar.style.width = '0%';
        }

        state.resetTimer = setTimeout(function () {
            window._posNewSale();
        }, duration);
    }

    window._posNewSale = function () {
        clearTimeout(state.resetTimer);
        state.cart = [];
        state.couponCode = '';
        state.customerName = '';
        state.customerEmail = '';
        state.orderResult = null;
        state.payment = '';
        state.givenAmount = '';
        go('sale');
        // Reload tickets for fresh stock
        if (state.selectedEvent) loadTickets(state.selectedEvent);
    };

    window._posEmailDialog = function () {
        clearTimeout(state.resetTimer);
        var overlay = document.createElement('div');
        overlay.className = 'pos-modal-overlay';
        overlay.innerHTML = '<div class="pos-modal">' +
            '<h3>📧 Ticket per E-Mail senden</h3>' +
            '<input type="email" id="pos-email-input" placeholder="E-Mail-Adresse" value="' + esc(state.customerEmail) + '">' +
            '<div class="pos-modal-actions">' +
            '<button class="pos-modal-btn cancel" onclick="this.closest(\'.pos-modal-overlay\').remove();window._posResumeReset()">Abbrechen</button>' +
            '<button class="pos-modal-btn primary" onclick="window._posSendEmail()">Senden</button>' +
            '</div></div>';
        app.appendChild(overlay);
        setTimeout(function () { var inp = $('#pos-email-input', app); if (inp) inp.focus(); }, 100);
    };

    window._posSendEmail = function () {
        var email = ($('#pos-email-input', app) || {}).value || '';
        if (!email) return;
        ajax('tix_pos_send_email', { order_id: state.orderResult.order_id, email: email }, function () {
            toast('E-Mail gesendet!', 'success');
            var overlay = $('.pos-modal-overlay', app);
            if (overlay) overlay.remove();
            startAutoReset();
        }, function (msg) {
            toast(msg || 'Fehler beim Senden', 'error');
        });
    };

    window._posResumeReset = function () {
        startAutoReset();
    };

    // ── Screen: Report ──
    function renderReport() {
        return topbar('Kassenbericht', function () { state.selectedEvent ? go('sale') : go('events'); }, [
            '<button class="pos-icon-btn" onclick="window.print()">🖨️</button>'
        ]) +
            '<div class="pos-report"><div class="pos-loading"><div class="pos-spinner"></div></div></div>' +
            statusbar();
    }

    function renderReportData(report) {
        var el = $('.pos-report', app);
        if (!el) return;

        var maxPayment = Math.max(report.by_payment.cash || 0, report.by_payment.card || 0, report.by_payment.free || 0, 1);

        var catRows = '';
        Object.keys(report.by_category || {}).forEach(function (name) {
            var d = report.by_category[name];
            catRows += '<tr><td>' + esc(name) + '</td><td>' + d.tickets + '</td><td>' + money(d.revenue) + '</td></tr>';
        });

        var hourlyHtml = '';
        var hours = Object.keys(report.by_hour || {});
        var maxHourRev = 0;
        hours.forEach(function (h) { maxHourRev = Math.max(maxHourRev, report.by_hour[h].revenue); });
        if (hours.length) {
            hourlyHtml = '<div class="pos-hourly">';
            hours.forEach(function (h) {
                var d = report.by_hour[h];
                var pct = maxHourRev > 0 ? Math.round((d.revenue / maxHourRev) * 100) : 0;
                hourlyHtml += '<div class="pos-hour-col">' +
                    '<div class="pos-hour-value">' + d.tickets + '</div>' +
                    '<div class="pos-hour-bar" style="height:' + Math.max(pct, 3) + '%"></div>' +
                    '<div class="pos-hour-label">' + h + ':00</div>' +
                    '</div>';
            });
            hourlyHtml += '</div>';
        }

        var avgTicket = report.total_tickets > 0 ? (report.total_revenue / report.total_tickets) : 0;

        el.innerHTML =
            '<div class="pos-report-kpis">' +
            kpi('Gesamtumsatz', money(report.total_revenue)) +
            kpi('Tickets verkauft', report.total_tickets) +
            kpi('Bestellungen', report.total_orders) +
            kpi('Ø Ticketpreis', money(avgTicket)) +
            kpi('Storniert', report.cancelled) +
            '</div>' +
            '<div class="pos-report-section"><h3>Nach Zahlungsart</h3>' +
            '<div class="pos-payment-bars">' +
            paymentBar('Barzahlung', 'cash', report.by_payment.cash || 0, maxPayment) +
            paymentBar('EC-Karte', 'card', report.by_payment.card || 0, maxPayment) +
            paymentBar('Kostenlos', 'free', report.by_payment.free || 0, maxPayment) +
            '</div></div>' +
            (catRows ? '<div class="pos-report-section"><h3>Nach Kategorie</h3><table class="pos-cat-table"><thead><tr><th>Kategorie</th><th>Tickets</th><th>Umsatz</th></tr></thead><tbody>' + catRows + '</tbody></table></div>' : '') +
            (hourlyHtml ? '<div class="pos-report-section"><h3>Stündliche Übersicht</h3>' + hourlyHtml + '</div>' : '');
    }

    function kpi(label, value) {
        return '<div class="pos-kpi"><div class="pos-kpi-label">' + label + '</div><div class="pos-kpi-value">' + value + '</div></div>';
    }

    function paymentBar(label, cls, amount, max) {
        var pct = max > 0 ? Math.round((amount / max) * 100) : 0;
        return '<div class="pos-bar-row">' +
            '<div class="pos-bar-label">' + label + '</div>' +
            '<div class="pos-bar-track"><div class="pos-bar-fill ' + cls + '" style="width:' + pct + '%">' + (pct > 15 ? money(amount) : '') + '</div></div>' +
            '<div class="pos-bar-amount">' + money(amount) + '</div></div>';
    }

    // ── Screen: Transactions ──
    function renderTransactions() {
        return topbar('Transaktionen', function () { state.selectedEvent ? go('sale') : go('events'); }) +
            '<div class="pos-transactions"><div class="pos-loading"><div class="pos-spinner"></div></div></div>' +
            statusbar();
    }

    function renderTransactionsList(transactions) {
        var el = $('.pos-transactions', app);
        if (!el) return;

        if (!transactions.length) {
            el.innerHTML = '<div class="pos-no-events">Keine Transaktionen heute</div>';
            return;
        }

        var html = '<div class="pos-tx-list">';
        transactions.forEach(function (tx) {
            var cancelled = tx.status === 'cancelled';
            html += '<div class="pos-tx-item' + (cancelled ? ' cancelled' : '') + '">' +
                '<div class="pos-tx-time">' + esc(tx.time) + '</div>' +
                '<div class="pos-tx-info">' +
                '<div class="pos-tx-items">' + esc(tx.items) + '</div>' +
                '<div class="pos-tx-customer">' + esc(tx.customer || 'POS Kunde') + (tx.staff ? ' · ' + esc(tx.staff) : '') + '</div>' +
                '</div>' +
                '<span class="pos-tx-payment ' + tx.payment + '">' + esc(tx.payment_label) + '</span>' +
                '<div class="pos-tx-amount">' + money(tx.total) + '</div>' +
                (!cancelled ? '<button class="pos-tx-void" onclick="event.stopPropagation();window._posVoid(' + tx.order_id + ')">Storno</button>' : '<span style="color:var(--pos-danger);font-size:12px">Storniert</span>') +
                '</div>';
        });
        html += '</div>';
        el.innerHTML = html;
    }

    window._posVoid = function (orderId) {
        if (!confirm('Bestellung #' + orderId + ' wirklich stornieren?')) return;
        ajax('tix_pos_void_order', { order_id: orderId }, function () {
            toast('Bestellung storniert', 'success');
            loadTransactions();
        }, function (msg) {
            toast(msg || 'Storno fehlgeschlagen', 'error');
        });
    };

    // ── Common UI Components ──
    function topbar(title, backFn, rightBtns) {
        var left = '';
        if (backFn) {
            left = '<button class="pos-back-btn" onclick="' + (typeof backFn === 'string' ? backFn : 'window._posBack()') + '">←</button>';
            window._posBack = backFn;
        }
        var right = '';
        if (rightBtns) {
            right = rightBtns.map(function (b) { return typeof b === 'string' ? b : ''; }).join('');
        }
        return '<div class="pos-topbar"><div class="pos-topbar-left">' + left +
            '<div class="pos-topbar-title">' + esc(title) + '</div></div>' +
            '<div class="pos-topbar-right">' + right + '</div></div>';
    }

    function iconBtn(icon, onclick, title) {
        return '<button class="pos-icon-btn" onclick="' + onclick + '" title="' + (title || '') + '">' + icon + '</button>';
    }

    function statusbar() {
        var user = state.user ? state.user.name : '';
        var event = state.eventTitle || '';
        var now = new Date();
        var time = pad(now.getHours()) + ':' + pad(now.getMinutes());

        return '<div class="pos-statusbar">' +
            '<div class="pos-statusbar-left">' +
            (event ? '<span class="pos-status-item">🎟️ ' + esc(event) + '</span>' : '') +
            (user ? '<span class="pos-status-item">👤 ' + esc(user) + '</span>' : '') +
            '</div>' +
            '<div class="pos-statusbar-right">' +
            '<span class="pos-status-item">🎫 <span class="pos-status-value">' + state.dailyStats.tickets + '</span> Tickets</span>' +
            '<span class="pos-status-item">💰 <span class="pos-status-value">' + money(state.dailyStats.revenue) + '</span></span>' +
            '<span class="pos-status-item">🕐 ' + time + '</span>' +
            '</div></div>';
    }

    // ── Navigation ──
    function go(screen) {
        state.screen = screen;
        render();
    }

    window._posGo = function (s) { go(s); };
    window._posLock = function () {
        state.user = null;
        state.pin = '';
        go('pin');
    };

    // ── Data Loading ──
    function loadEvents() {
        ajax('tix_pos_events', { filter: state.eventFilter, search: state.searchQuery }, function (data) {
            state.events = data.events || [];
            render();
        });
    }

    function loadTickets(eventId) {
        ajax('tix_pos_event_tickets', { event_id: eventId }, function (data) {
            state.categories = data.categories || [];
            state.eventTitle = data.event_title || '';
            go('sale');
        }, function (msg) {
            toast(msg || 'Fehler beim Laden der Tickets', 'error');
        });
    }

    function createOrder() {
        var items = state.cart.map(function (c) {
            return { product_id: c.product_id, qty: c.qty };
        });

        var params = {
            event_id: state.selectedEvent,
            items: JSON.stringify(items),
            payment: state.payment,
            customer_name: state.customerName,
            customer_email: state.customerEmail,
            staff_id: state.user ? state.user.user_id : 0,
            coupon_code: state.couponCode,
        };

        // Show loading
        state.orderResult = null;
        go('success');

        ajax('tix_pos_create_order', params, function (data) {
            state.orderResult = data;
            state.dailyStats.tickets += cartCount();
            state.dailyStats.revenue += data.total;
            render();
        }, function (msg) {
            toast(msg || 'Bestellung fehlgeschlagen', 'error');
            go('payment');
        });
    }

    function loadReport() {
        ajax('tix_pos_daily_report', {
            date: new Date().toISOString().slice(0, 10),
            event_id: state.selectedEvent || 0,
        }, function (data) {
            renderReportData(data.report);
        });
    }

    function loadTransactions() {
        ajax('tix_pos_transactions', {
            date: new Date().toISOString().slice(0, 10),
            event_id: state.selectedEvent || 0,
        }, function (data) {
            renderTransactionsList(data.transactions || []);
        });
    }

    // ── Keyboard Shortcuts ──
    function bindKeyboard() {
        document.addEventListener('keydown', function (e) {
            // PIN screen
            if (state.screen === 'pin') {
                if (e.key >= '0' && e.key <= '9') {
                    window._posPin(e.key);
                    e.preventDefault();
                } else if (e.key === 'Backspace') {
                    window._posPin('clear');
                    e.preventDefault();
                } else if (e.key === 'Enter') {
                    window._posPin('enter');
                    e.preventDefault();
                }
                return;
            }

            // Don't intercept input fields
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

            // Sale screen: number keys add category
            if (state.screen === 'sale') {
                var num = parseInt(e.key);
                if (num >= 1 && num <= 9 && num <= state.categories.length) {
                    window._posAddToCart(num - 1);
                    e.preventDefault();
                } else if (e.key === 'Enter' && state.cart.length > 0) {
                    go('payment');
                    e.preventDefault();
                } else if (e.key === 'Escape') {
                    go('events');
                    e.preventDefault();
                }
            }

            // Payment screen
            if (state.screen === 'payment') {
                if (e.key === '1') { window._posSelectPayment('cash'); e.preventDefault(); }
                else if (e.key === '2') { window._posSelectPayment('card'); e.preventDefault(); }
                else if (e.key === '3' && cfg.allowFree) { window._posSelectPayment('free'); e.preventDefault(); }
                else if (e.key === 'Escape') { go('sale'); e.preventDefault(); }
            }

            // Change screen
            if (state.screen === 'change') {
                if (e.key >= '0' && e.key <= '9') { window._posGivenKey(e.key); e.preventDefault(); }
                else if (e.key === '.' || e.key === ',') { window._posGivenKey('.'); e.preventDefault(); }
                else if (e.key === 'Backspace') { window._posGivenKey('clear'); e.preventDefault(); }
                else if (e.key === 'Enter') { window._posConfirmCash(); e.preventDefault(); }
                else if (e.key === 'Escape') { go('payment'); e.preventDefault(); }
            }

            // Success screen
            if (state.screen === 'success') {
                if (e.key === 'Enter' || e.key === ' ') { window._posNewSale(); e.preventDefault(); }
            }

            // Global: L to lock
            if (e.key === 'l' && !e.ctrlKey && !e.metaKey) {
                if (['sale', 'events'].indexOf(state.screen) >= 0) {
                    window._posLock();
                    e.preventDefault();
                }
            }
        });
    }

    // ── Helpers ──
    function ajax(action, data, success, error) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) {
            fd.append(k, data[k]);
        });

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    if (success) success(res.data);
                } else {
                    if (error) error(res.data ? res.data.message : 'Fehler');
                }
            })
            .catch(function () {
                if (error) error('Netzwerkfehler');
            });
    }

    function calcTotal() {
        state.cartTotal = 0;
        state.cart.forEach(function (c) { state.cartTotal += c.price * c.qty; });
    }

    function cartCount() {
        var n = 0;
        state.cart.forEach(function (c) { n += c.qty; });
        return n;
    }

    function money(v) {
        return parseFloat(v || 0).toFixed(2).replace('.', ',') + ' €';
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        return parts[2] + '.' + parts[1] + '.' + parts[0];
    }

    function esc(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function toast(msg, type) {
        var existing = $('.pos-toast', app);
        if (existing) existing.remove();

        var el = document.createElement('div');
        el.className = 'pos-toast ' + (type || '');
        el.textContent = msg;
        app.appendChild(el);

        setTimeout(function () { el.classList.add('show'); }, 10);
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 300);
        }, 3000);
    }

    // Update clock every minute
    setInterval(function () {
        var clock = $$('.pos-status-item', app);
        // Just re-render statusbar time
    }, 60000);

})();
