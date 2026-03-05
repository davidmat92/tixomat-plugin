(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ══════════════════════════════════════
        // TICKET SELECTOR
        // ══════════════════════════════════════
        document.querySelectorAll('.tix-sel').forEach(initSelector);

        function initSelector(sel) {
            var cats      = sel.querySelectorAll('.tix-sel-cat:not(.tix-sel-combo)');
            var combos    = sel.querySelectorAll('.tix-sel-combo');
            var total     = sel.querySelector('.tix-sel-total-price');
            var buyBtn    = sel.querySelector('.tix-sel-buy');
            var expressBtn    = sel.querySelector('.tix-sel-express');
            var expressTerms  = sel.querySelector('.tix-sel-express-terms-check');
            var msg           = sel.querySelector('.tix-sel-message');

            // Coupon state
            var activeCoupon = null;

            // Group discount
            var gdEl = sel.querySelector('.tix-sel-group-discount');
            var gdTiers = [];
            var gdCombineCombo = false;
            var gdCombineBundle = false;
            var gdCombinePhase = false;
            if (gdEl) {
                try { gdTiers = JSON.parse(gdEl.dataset.tiers || '[]'); } catch(e) {}
                gdTiers.sort(function(a, b) { return b.min_qty - a.min_qty; });
                gdCombineCombo = gdEl.dataset.combineCombo === '1';
                gdCombineBundle = gdEl.dataset.combineBundle === '1';
                gdCombinePhase = gdEl.dataset.combinePhase === '1';
            }

            function getGroupTier(totalQty) {
                for (var t = 0; t < gdTiers.length; t++) {
                    if (totalQty >= gdTiers[t].min_qty) return gdTiers[t];
                }
                return null;
            }

            // ── Quantity Buttons ──
            sel.addEventListener('click', function(e) {
                var btn = e.target.closest('.tix-sel-minus, .tix-sel-plus');
                if (!btn) return;

                var cat    = btn.closest('.tix-sel-cat');
                var valEl  = cat.querySelector('.tix-sel-qty-val');
                var qty    = parseInt(valEl.dataset.qty, 10) || 0;

                if (btn.classList.contains('tix-sel-plus')) {
                    qty++;
                } else if (btn.classList.contains('tix-sel-minus') && qty > 0) {
                    qty--;
                }

                valEl.dataset.qty = qty;
                valEl.textContent = qty;

                // Bei Bundle: Ticket-Anzahl anzeigen
                if (cat.dataset.bundle === '1') {
                    var bBuy = parseInt(cat.dataset.bundleBuy, 10) || 0;
                    var ticketCount = qty * bBuy;
                    valEl.textContent = qty;
                    var ticketHint = cat.querySelector('.tix-sel-bundle-tickets');
                    if (qty > 0) {
                        if (!ticketHint) {
                            ticketHint = document.createElement('span');
                            ticketHint.className = 'tix-sel-bundle-tickets';
                            valEl.parentNode.appendChild(ticketHint);
                        }
                        ticketHint.textContent = '= ' + ticketCount + ' Tickets';
                    } else if (ticketHint) {
                        ticketHint.remove();
                    }
                }

                // Bei Kombi: Ticket-Anzahl anzeigen
                if (cat.classList.contains('tix-sel-combo')) {
                    var comboItems = [];
                    try { comboItems = JSON.parse(cat.dataset.comboItems || '[]'); } catch(ex) {}
                    var comboTickets = qty * comboItems.length;
                    var ticketHint = cat.querySelector('.tix-sel-combo-tickets');
                    if (qty > 0 && comboItems.length > 1) {
                        if (!ticketHint) {
                            ticketHint = document.createElement('span');
                            ticketHint.className = 'tix-sel-bundle-tickets tix-sel-combo-tickets';
                            valEl.parentNode.appendChild(ticketHint);
                        }
                        ticketHint.textContent = '= ' + comboTickets + ' Tickets';
                    } else if (ticketHint) {
                        ticketHint.remove();
                    }
                }

                if (qty > 0) {
                    cat.classList.add('tix-sel-active');
                } else {
                    cat.classList.remove('tix-sel-active');
                }

                updateTotal();
            });

            // ── Total berechnen ──
            function updateTotal() {
                var sum      = 0;
                var discount = 0;
                var hasItems = false;
                var totalQty = 0;

                var bundleSum = 0;
                var bundlePkgQty = 0; // Anzahl Bundle-PAKETE (nicht Einzeltickets)
                var normalQty = 0;    // Normale Einzel-Tickets (ohne Bundles)

                cats.forEach(function(cat) {
                    var valEl = cat.querySelector('.tix-sel-qty-val');
                    if (!valEl) return;
                    var qty   = parseInt(valEl.dataset.qty, 10) || 0;
                    var price = parseFloat(cat.dataset.price) || 0;
                    if (qty <= 0) return;

                    hasItems = true;

                    if (cat.dataset.bundle === '1') {
                        var bBuy = parseInt(cat.dataset.bundleBuy, 10) || 0;
                        sum += qty * price;
                        totalQty += qty * bBuy; // Gesamt-Anzeige: Einzeltickets
                        bundleSum += qty * price;
                        bundlePkgQty += qty;    // Mengenrabatt: Pakete zählen
                    } else {
                        sum += qty * price;
                        totalQty += qty;
                        normalQty += qty;
                    }
                });

                // Gruppenrabatt: Pakete zählen als PAKETE, nicht als Einzeltickets
                // normalQty = normale Tickets, bundlePkgQty = Anzahl Pakete (nicht × bBuy)
                var gdQty = normalQty + (gdCombineBundle ? bundlePkgQty : 0);
                var gdBase = gdCombineBundle ? sum : (sum - bundleSum);

                // Phase-Check: wenn combine_phase=false UND gewählte Kategorie eine Phase hat → kein GD
                if (!gdCombinePhase) {
                    var hasPhaseItem = false;
                    cats.forEach(function(cat) {
                        var q = parseInt((cat.querySelector('.tix-sel-qty-val') || {}).dataset && cat.querySelector('.tix-sel-qty-val').dataset.qty || '0', 10);
                        if (q > 0 && cat.dataset.hasPhase === '1') hasPhaseItem = true;
                    });
                    if (hasPhaseItem) { gdQty = 0; gdBase = 0; }
                }

                // Kombi-Tickets zum Gesamtbetrag addieren
                combos.forEach(function(combo) {
                    var valEl = combo.querySelector('.tix-sel-qty-val');
                    if (!valEl) return;
                    var qty = parseInt(valEl.dataset.qty, 10) || 0;
                    var comboPrice = parseFloat(combo.dataset.comboPrice) || 0;
                    if (qty <= 0) return;
                    hasItems = true;
                    sum += qty * comboPrice;

                    // Kombis in Gruppenrabatt einbeziehen wenn kombinierbar
                    // Zähle Anzahl der Kombi-Pakete, NICHT die enthaltenen Einzeltickets
                    if (gdCombineCombo) {
                        gdQty += qty;
                        gdBase += qty * comboPrice;
                    }
                });
                var gdActive = getGroupTier(gdQty);
                var gdDiscount = 0;
                if (gdActive) {
                    gdDiscount = gdBase * (gdActive.percent / 100);
                }

                // Coupon-Rabatt (auf Preis nach Gruppenrabatt)
                var couponBase = sum - gdDiscount;
                if (activeCoupon && hasItems) {
                    cats.forEach(function(cat) {
                        var valEl = cat.querySelector('.tix-sel-qty-val');
                        if (!valEl) return;
                        var qty   = parseInt(valEl.dataset.qty, 10) || 0;
                        var price = parseFloat(cat.dataset.price) || 0;
                        var pid   = parseInt(cat.dataset.productId, 10) || 0;
                        if (qty <= 0) return;

                        var lineTotal = qty * price;
                        if (gdActive) {
                            lineTotal -= lineTotal * (gdActive.percent / 100);
                        }

                        var applies = activeCoupon.product_ids.length === 0
                            || activeCoupon.product_ids.indexOf(pid) !== -1;
                        if (applies) {
                            if (activeCoupon.type === 'percent') {
                                discount += lineTotal * (activeCoupon.amount / 100);
                            } else if (activeCoupon.type === 'fixed_product') {
                                discount += qty * activeCoupon.amount;
                            }
                        }
                    });

                    if (activeCoupon.type === 'fixed_cart') {
                        discount = Math.min(activeCoupon.amount, couponBase);
                    }
                }

                var totalDiscount = gdDiscount + discount;
                totalDiscount = Math.min(totalDiscount, sum);
                var finalTotal = sum - totalDiscount;

                // Gruppenrabatt-Badge
                if (gdEl) {
                    var badge = gdEl.querySelector('.tix-sel-gd-badge');
                    if (gdActive) {
                        badge.textContent = '\u2212' + gdActive.percent + '%';
                        badge.style.display = '';
                        gdEl.classList.add('tix-sel-gd-active');
                    } else {
                        badge.style.display = 'none';
                        gdEl.classList.remove('tix-sel-gd-active');
                    }
                }

                if (total) {
                    if (totalDiscount > 0) {
                        total.innerHTML = '<span class="tix-sel-total-original">' + formatPrice(sum) + '</span> ' + formatPrice(finalTotal);
                    } else {
                        total.textContent = formatPrice(finalTotal);
                    }
                }

                if (buyBtn) buyBtn.disabled = !hasItems;
                if (expressBtn) expressBtn.disabled = !hasItems || !(expressTerms && expressTerms.checked);
            }

            // ── Coupon ──
            var couponWrap   = sel.querySelector('.tix-sel-coupon');
            var couponInput  = sel.querySelector('.tix-sel-coupon-code');
            var couponBtn    = sel.querySelector('.tix-sel-coupon-btn');
            var couponResult = sel.querySelector('.tix-sel-coupon-result');

            if (couponBtn) {
                couponBtn.addEventListener('click', function() {
                    var code = couponInput.value.trim();
                    if (!code) return;

                    couponBtn.disabled = true;
                    couponBtn.textContent = '\u2026';

                    var data = new FormData();
                    data.append('action', 'tix_validate_coupon');
                    data.append('nonce', ehSel.nonce);
                    data.append('coupon_code', code);

                    fetch(ehSel.ajaxUrl, { method: 'POST', body: data })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            couponBtn.disabled = false;
                            if (res.success) {
                                activeCoupon = {
                                    code: res.data.code,
                                    type: res.data.type,
                                    amount: res.data.amount,
                                    product_ids: res.data.product_ids || []
                                };
                                couponResult.innerHTML = '<span class="tix-sel-coupon-ok">\u2713 ' + res.data.message
                                    + '</span><button type="button" class="tix-sel-coupon-remove">\u2715</button>';
                                couponResult.style.display = '';
                                couponInput.style.display = 'none';
                                couponBtn.style.display = 'none';
                                updateTotal();
                            } else {
                                couponResult.innerHTML = '<span class="tix-sel-coupon-err">' + res.data.message + '</span>';
                                couponResult.style.display = '';
                                setTimeout(function() { couponResult.style.display = 'none'; }, 3000);
                            }
                            couponBtn.textContent = 'Einl\u00f6sen';
                        })
                        .catch(function() {
                            couponBtn.disabled = false;
                            couponBtn.textContent = 'Einl\u00f6sen';
                        });
                });

                if (couponInput) {
                    couponInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') { e.preventDefault(); couponBtn.click(); }
                    });
                }

                if (couponWrap) {
                    couponWrap.addEventListener('click', function(e) {
                        if (e.target.closest('.tix-sel-coupon-remove')) {
                            activeCoupon = null;
                            couponInput.value = '';
                            couponInput.style.display = '';
                            couponBtn.style.display = '';
                            couponResult.style.display = 'none';
                            updateTotal();
                        }
                    });
                }
            }

            // ── Kaufen ──
            if (buyBtn) buyBtn.addEventListener('click', function() {
                var items = [];

                cats.forEach(function(cat) {
                    var valEl = cat.querySelector('.tix-sel-qty-val');
                    if (!valEl) return;
                    var qty = parseInt(valEl.dataset.qty, 10) || 0;
                    if (qty > 0) {
                        var pid = parseInt(cat.dataset.productId, 10);
                        var isBundle = cat.dataset.bundle === '1';

                        if (isBundle) {
                            var bBuy = parseInt(cat.dataset.bundleBuy, 10) || 0;
                            var bPay = parseInt(cat.dataset.bundlePay, 10) || 0;
                            items.push({
                                product_id: pid,
                                quantity: qty * bBuy,
                                bundle: 1,
                                bundle_buy: bBuy,
                                bundle_pay: bPay,
                                bundle_label: cat.dataset.bundleLabel || ''
                            });
                        } else {
                            items.push({
                                product_id: pid,
                                quantity: qty
                            });
                        }
                    }
                });

                // Kombi-Tickets
                combos.forEach(function(combo) {
                    var valEl = combo.querySelector('.tix-sel-qty-val');
                    if (!valEl) return;
                    var qty = parseInt(valEl.dataset.qty, 10) || 0;
                    if (qty <= 0) return;

                    var comboItems = [];
                    try { comboItems = JSON.parse(combo.dataset.comboItems || '[]'); } catch(ex) {}

                    items.push({
                        combo: 1,
                        combo_id: combo.dataset.comboId || '',
                        combo_label: combo.dataset.comboLabel || '',
                        combo_price: parseFloat(combo.dataset.comboPrice) || 0,
                        quantity: qty,
                        products: comboItems
                    });
                });

                if (items.length === 0) return;

                buyBtn.disabled = true;
                buyBtn.querySelector('.tix-sel-buy-text').style.display = 'none';
                buyBtn.querySelector('.tix-sel-buy-loading').style.display = '';

                var data = new FormData();
                data.append('action', 'tix_add_to_cart');
                data.append('nonce', ehSel.nonce);
                data.append('items', JSON.stringify(items));
                if (activeCoupon) data.append('coupon_code', activeCoupon.code);
                if (ehSel.isEmbed) data.append('is_embed', '1');

                fetch(ehSel.ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            showMessage(res.data.message, 'success');
                            var url = (ehSel.isEmbed || ehSel.skipCart) && res.data.checkout_url ? res.data.checkout_url : res.data.cart_url;
                            setTimeout(function() {
                                if (ehSel.isEmbed) {
                                    // Embed: Top-Fenster navigieren (cross-origin erlaubt, kein Popup-Blocker)
                                    window.top.location.href = url;
                                } else {
                                    window.location.href = url;
                                }
                            }, 800);
                        } else {
                            showMessage(res.data.message, 'error');
                            resetBuyBtn();
                        }
                    })
                    .catch(function() {
                        showMessage('Ein Fehler ist aufgetreten.', 'error');
                        resetBuyBtn();
                    });
            });

            function resetBuyBtn() {
                if (!buyBtn) return;
                buyBtn.disabled = false;
                buyBtn.querySelector('.tix-sel-buy-text').style.display = '';
                buyBtn.querySelector('.tix-sel-buy-loading').style.display = 'none';
            }

            // ── Express Checkout Terms ──
            if (expressTerms) expressTerms.addEventListener('change', function() {
                updateTotal();
            });

            // ── Express Checkout (1-Klick-Kauf) ──
            if (expressBtn) expressBtn.addEventListener('click', function() {
                var items = [];
                cats.forEach(function(cat) {
                    var valEl = cat.querySelector('.tix-sel-qty-val');
                    if (!valEl) return;
                    var qty = parseInt(valEl.dataset.qty, 10) || 0;
                    if (qty > 0) {
                        items.push({
                            product_id: parseInt(cat.dataset.productId, 10),
                            qty: qty
                        });
                    }
                });
                if (items.length === 0) return;

                expressBtn.disabled = true;
                expressBtn.querySelector('.tix-sel-express-text').style.display = 'none';
                expressBtn.querySelector('.tix-sel-express-loading').style.display = '';

                var data = new FormData();
                data.append('action', 'tix_express_checkout');
                data.append('nonce', ehSel.nonce);
                data.append('items', JSON.stringify(items));
                data.append('terms_accepted', '1');

                fetch(ehSel.ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success && res.data.redirect) {
                            showMessage('Bestellung erfolgreich!', 'success');
                            setTimeout(function() {
                                window.location.href = res.data.redirect;
                            }, 600);
                        } else {
                            showMessage(res.data.message || 'Express Checkout fehlgeschlagen.', 'error');
                            expressBtn.disabled = false;
                            expressBtn.querySelector('.tix-sel-express-text').style.display = '';
                            expressBtn.querySelector('.tix-sel-express-loading').style.display = 'none';
                        }
                    })
                    .catch(function() {
                        showMessage('Verbindungsfehler.', 'error');
                        expressBtn.disabled = false;
                        expressBtn.querySelector('.tix-sel-express-text').style.display = '';
                        expressBtn.querySelector('.tix-sel-express-loading').style.display = 'none';
                    });
            });

            function showMessage(text, type) {
                if (!msg) return;
                msg.textContent = text;
                msg.className = 'tix-sel-message tix-sel-msg-' + type;
                msg.style.display = '';
                setTimeout(function() { msg.style.display = 'none'; }, 4000);
            }

            function formatPrice(val) {
                return val.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' \u20ac';
            }
        }

        // ══════════════════════════════════════
        // COUNTDOWN
        // ══════════════════════════════════════
        // ══════════════════════════════════════
        // SAALPLAN-MODAL INTEGRATION
        // ══════════════════════════════════════
        document.querySelectorAll('.tix-sel-seatmap').forEach(function(sel) {
            var eventId   = parseInt(sel.dataset.eventId) || 0;
            var seatmapId = parseInt(sel.dataset.seatmapId) || 0;
            var buyBtn    = sel.querySelector('.tix-sel-buy');
            var totalEl   = sel.querySelector('.tix-sel-total-price');
            var msg       = sel.querySelector('.tix-sel-message');
            var pickerInitialized = false;
            var selectedSeats = [];
            var seatSections  = []; // [{id, count, price, product_id}]
            var totalPrice    = 0;

            // ── Platz-wählen Button → Modal öffnen ──
            sel.addEventListener('click', function(e) {
                var btn = e.target.closest('.tix-sel-btn-seatmap');
                if (!btn) return;

                var modal = document.getElementById('tix-seatmap-modal');
                if (!modal) return;
                modal.style.display = 'flex';

                // Picker initialisieren (einmalig)
                if (!pickerInitialized) {
                    var pickerEl = modal.querySelector('[data-tix-seatmap-picker]');
                    if (pickerEl && window.tixInitSeatmapPicker) {
                        window.tixInitSeatmapPicker(pickerEl);
                    }
                    pickerInitialized = true;

                    // Confirm-Button
                    var confirmBtn = modal.querySelector('.tix-sp-modal-confirm');
                    if (confirmBtn) {
                        confirmBtn.addEventListener('click', function() {
                            if (pickerEl && pickerEl._tixConfirm) {
                                pickerEl._tixConfirm();
                            }
                        });
                    }
                }
            });

            // ── Besten Platz finden ──
            sel.addEventListener('click', function(e) {
                var btn = e.target.closest('.tix-sel-btn-best-available');
                if (!btn) return;

                var qty = prompt('Wie viele Plätze?', '2');
                if (!qty || isNaN(parseInt(qty)) || parseInt(qty) < 1) return;
                qty = parseInt(qty);

                btn.disabled = true;
                var origHTML = btn.innerHTML;
                btn.textContent = 'Suche…';

                var data = new FormData();
                data.append('action', 'tix_best_available');
                data.append('event_id', eventId);
                data.append('seatmap_id', seatmapId);
                data.append('qty', qty);

                fetch(ehSel.ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        btn.innerHTML = origHTML;

                        if (res.success && res.data && res.data.seats) {
                            // Modal öffnen + Seats pre-selecten
                            var modal = document.getElementById('tix-seatmap-modal');
                            if (!modal) return;
                            modal.style.display = 'flex';

                            if (!pickerInitialized) {
                                var pickerEl = modal.querySelector('[data-tix-seatmap-picker]');
                                if (pickerEl && window.tixInitSeatmapPicker) {
                                    window.tixInitSeatmapPicker(pickerEl);
                                }
                                pickerInitialized = true;

                                var confirmBtn = modal.querySelector('.tix-sp-modal-confirm');
                                if (confirmBtn) {
                                    confirmBtn.addEventListener('click', function() {
                                        if (pickerEl && pickerEl._tixConfirm) {
                                            pickerEl._tixConfirm();
                                        }
                                    });
                                }
                            }

                            // Pre-Select nach kurzer Verzögerung (Picker muss geladen sein)
                            setTimeout(function() {
                                var pickerEl = modal.querySelector('[data-tix-seatmap-picker]');
                                if (pickerEl && pickerEl._tixPreSelect) {
                                    pickerEl._tixPreSelect(res.data.seats);
                                }
                            }, 800);
                        } else {
                            showSeatmapMsg(res.data || 'Nicht genügend Plätze verfügbar', 'error');
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.innerHTML = origHTML;
                        showSeatmapMsg('Fehler bei der Platzsuche.', 'error');
                    });
            });

            // ── Modal schließen ──
            document.addEventListener('click', function(e) {
                if (e.target.closest('.tix-sp-modal-close')) {
                    var modal = document.getElementById('tix-seatmap-modal');
                    if (modal) modal.style.display = 'none';
                }
                if (e.target.classList && e.target.classList.contains('tix-sp-modal-overlay')) {
                    e.target.style.display = 'none';
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    var modal = document.getElementById('tix-seatmap-modal');
                    if (modal && modal.style.display !== 'none') {
                        modal.style.display = 'none';
                    }
                }
            });

            // ── Seats confirmed Event ──
            document.addEventListener('tix-seats-confirmed', function(e) {
                var detail = e.detail;
                if (!detail) return;

                selectedSeats = detail.seats || [];
                seatSections  = detail.sections || [];
                totalPrice    = detail.total || 0;

                // Kategorie-Anzeigen aktualisieren
                seatSections.forEach(function(sec) {
                    var catEl = sel.querySelector('[data-section-id="' + sec.id + '"] .tix-sel-seatmap-qty');
                    if (catEl) {
                        catEl.dataset.qty = sec.count;
                        catEl.textContent = sec.count + ' Platz' + (sec.count !== 1 ? 'e' : '');
                    }
                });

                // Alle Sektionen ohne Auswahl zurücksetzen
                sel.querySelectorAll('.tix-sel-seatmap-qty').forEach(function(el) {
                    var secId = el.dataset.section;
                    var found = seatSections.find(function(s) { return s.id === secId; });
                    if (!found) {
                        el.dataset.qty = 0;
                        el.textContent = '0 Plätze';
                    }
                });

                // Hidden inputs für Warenkorb
                var container = document.getElementById('tix-seatmap-selection');
                if (container) {
                    container.innerHTML = '';
                    selectedSeats.forEach(function(seatId) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'tix_seats[]';
                        input.value = seatId;
                        container.appendChild(input);
                    });
                }

                // Ausgewählte Plätze anzeigen
                var selectedWrap = sel.querySelector('.tix-sel-seatmap-selected');
                if (selectedWrap) {
                    if (selectedSeats.length > 0) {
                        selectedWrap.style.display = '';
                        var list = selectedWrap.querySelector('.tix-sel-seatmap-selected-list');
                        if (list) {
                            list.innerHTML = '';
                            selectedSeats.forEach(function(id) {
                                var parts = id.split('_');
                                var label = parts[parts.length - 1] || id;
                                var span = document.createElement('span');
                                span.className = 'tix-sel-seatmap-selected-tag';
                                span.textContent = label;
                                list.appendChild(span);
                            });
                        }
                    } else {
                        selectedWrap.style.display = 'none';
                    }
                }

                // Gesamtpreis
                if (totalEl) {
                    totalEl.textContent = formatSeatPrice(totalPrice);
                }

                // Kaufen-Button
                if (buyBtn) {
                    buyBtn.disabled = selectedSeats.length === 0;
                }

                // Modal schließen
                var modal = document.getElementById('tix-seatmap-modal');
                if (modal) modal.style.display = 'none';
            });

            // ── Kaufen (Saalplan-Modus) ──
            if (buyBtn) buyBtn.addEventListener('click', function() {
                if (selectedSeats.length === 0) return;

                buyBtn.disabled = true;
                var buyText = buyBtn.querySelector('.tix-sel-buy-text');
                var buyLoad = buyBtn.querySelector('.tix-sel-buy-loading');
                if (buyText) buyText.style.display = 'none';
                if (buyLoad) buyLoad.style.display = '';

                // Seats nach Sektion gruppieren → je ein Cart-Item pro Sektion
                var items = [];
                seatSections.forEach(function(sec) {
                    var secSeats = selectedSeats.filter(function(id) {
                        // Seat-ID enthält Sektions-ID als Prefix
                        return id.indexOf(sec.id + '_') === 0;
                    });

                    items.push({
                        product_id: sec.product_id || 0,
                        quantity: sec.count,
                        seats: secSeats,
                        event_id: eventId,
                        seatmap_id: seatmapId
                    });
                });

                var data = new FormData();
                data.append('action', 'tix_add_to_cart');
                data.append('nonce', ehSel.nonce);
                data.append('items', JSON.stringify(items));

                fetch(ehSel.ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            showSeatmapMsg(res.data.message, 'success');
                            var url = ehSel.skipCart && res.data.checkout_url ? res.data.checkout_url : res.data.cart_url;
                            setTimeout(function() { window.location.href = url; }, 800);
                        } else {
                            showSeatmapMsg(res.data.message || 'Fehler', 'error');
                            resetSeatmapBuyBtn();
                        }
                    })
                    .catch(function() {
                        showSeatmapMsg('Verbindungsfehler.', 'error');
                        resetSeatmapBuyBtn();
                    });
            });

            function resetSeatmapBuyBtn() {
                if (!buyBtn) return;
                buyBtn.disabled = selectedSeats.length === 0;
                var buyText = buyBtn.querySelector('.tix-sel-buy-text');
                var buyLoad = buyBtn.querySelector('.tix-sel-buy-loading');
                if (buyText) buyText.style.display = '';
                if (buyLoad) buyLoad.style.display = 'none';
            }

            function showSeatmapMsg(text, type) {
                if (!msg) return;
                msg.textContent = text;
                msg.className = 'tix-sel-message tix-sel-msg-' + type;
                msg.style.display = '';
                setTimeout(function() { msg.style.display = 'none'; }, 4000);
            }

            function formatSeatPrice(val) {
                return val.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' \u20ac';
            }
        });

        // ══════════════════════════════════════
        // COUNTDOWN
        // ══════════════════════════════════════
        document.querySelectorAll('.tix-countdown').forEach(function(el) {
            var target = new Date(el.dataset.target).getTime();
            if (isNaN(target)) return;

            var days  = el.querySelector('.tix-cd-days');
            var hours = el.querySelector('.tix-cd-hours');
            var mins  = el.querySelector('.tix-cd-mins');
            var secs  = el.querySelector('.tix-cd-secs');

            function tick() {
                var now  = Date.now();
                var diff = Math.max(0, Math.floor((target - now) / 1000));

                var d = Math.floor(diff / 86400);
                var h = Math.floor((diff % 86400) / 3600);
                var m = Math.floor((diff % 3600) / 60);
                var s = diff % 60;

                days.textContent  = d < 10 ? '0' + d : d;
                hours.textContent = h < 10 ? '0' + h : h;
                mins.textContent  = m < 10 ? '0' + m : m;
                secs.textContent  = s < 10 ? '0' + s : s;

                if (diff <= 0) {
                    el.classList.add('tix-countdown-ended');
                    return;
                }
                requestAnimationFrame(function() { setTimeout(tick, 1000); });
            }
            tick();
        });
    });
})();
