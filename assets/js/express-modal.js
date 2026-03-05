/**
 * Tixomat – Express Checkout Modal
 * Shortcode: [tix_express_checkout]
 */
(function() {
    'use strict';

    document.querySelectorAll('.tix-ec-trigger').forEach(function(trigger) {
        var modalId = trigger.dataset.modal;
        var overlay = document.getElementById(modalId);
        if (!overlay) return;

        var modal     = overlay.querySelector('.tix-ec-modal');
        var closeBtn  = overlay.querySelector('.tix-ec-close');
        var cats      = overlay.querySelectorAll('.tix-ec-cat');
        var totalEl   = overlay.querySelector('.tix-ec-total-price');
        var termsChk  = overlay.querySelector('.tix-ec-terms-check');
        var buyBtn    = overlay.querySelector('.tix-ec-buy');
        var msgEl     = overlay.querySelector('.tix-ec-message');
        var eventId   = overlay.dataset.eventId;

        // Angebote toggle
        var offersBtn  = overlay.querySelector('.tix-ec-offers-btn');
        var offersWrap = overlay.querySelector('.tix-ec-offers');

        // Mengenrabatt
        var gdEl = overlay.querySelector('.tix-ec-gd');
        var gdBadge = gdEl ? gdEl.querySelector('.tix-ec-gd-badge') : null;
        var gdTiers = [];
        var gdCombineBundle = false;
        var gdCombineCombo  = false;
        if (gdEl) {
            try { gdTiers = JSON.parse(gdEl.dataset.tiers || '[]'); } catch(e) {}
            gdCombineBundle = gdEl.dataset.combineBundle === '1';
            gdCombineCombo  = gdEl.dataset.combineCombo === '1';
        }

        // ── Open Modal ──
        trigger.querySelector('.tix-ec-trigger-btn').addEventListener('click', function() {
            overlay.style.display = '';
            document.body.style.overflow = 'hidden';
        });

        // ── Close Modal ──
        function close() {
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }
        closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) close();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.style.display !== 'none') close();
        });

        // ── Offers Toggle ──
        if (offersBtn && offersWrap) {
            offersBtn.addEventListener('click', function() {
                var icon = this.querySelector('.tix-ec-offers-icon');
                if (offersWrap.style.display === 'none') {
                    offersWrap.style.display = '';
                    icon.textContent = '\u2212';
                    icon.nextSibling.textContent = ' Angebote ausblenden';
                } else {
                    offersWrap.style.display = 'none';
                    icon.textContent = '+';
                    icon.nextSibling.textContent = ' Angebote anzeigen';
                }
            });
        }

        // ── Qty +/- ──
        cats.forEach(function(cat) {
            var minus  = cat.querySelector('.tix-ec-minus');
            var plus   = cat.querySelector('.tix-ec-plus');
            var valEl  = cat.querySelector('.tix-ec-qty-val');
            var maxQty = parseInt(cat.dataset.max, 10) || 100;

            if (!minus || !plus || !valEl) return;

            minus.addEventListener('click', function() {
                var q = parseInt(valEl.dataset.qty, 10) || 0;
                if (q > 0) {
                    q--;
                    valEl.dataset.qty = q;
                    valEl.textContent = q;
                    cat.classList.toggle('tix-ec-cat-active', q > 0);
                    updateTotal();
                }
            });

            plus.addEventListener('click', function() {
                var q = parseInt(valEl.dataset.qty, 10) || 0;
                if (q < maxQty) {
                    q++;
                    valEl.dataset.qty = q;
                    valEl.textContent = q;
                    cat.classList.add('tix-ec-cat-active');
                    updateTotal();
                }
            });
        });

        // ── Total ──
        function updateTotal() {
            var total = 0;
            var hasItems = false;
            var normalQty = 0;
            var bundlePkgQty = 0;
            var comboPkgQty = 0;

            cats.forEach(function(cat) {
                var q = parseInt(cat.querySelector('.tix-ec-qty-val').dataset.qty, 10) || 0;
                if (q > 0) {
                    hasItems = true;
                    total += q * parseFloat(cat.dataset.price);
                    if (cat.dataset.bundle === '1') {
                        bundlePkgQty += q;
                    } else if (cat.dataset.comboId) {
                        comboPkgQty += q;
                    } else {
                        normalQty += q;
                    }
                }
            });

            // Mengenrabatt
            var gdPercent = getGdPercent(normalQty, bundlePkgQty, comboPkgQty);

            if (gdPercent > 0) {
                var normalTotal = 0;
                cats.forEach(function(cat) {
                    var q = parseInt(cat.querySelector('.tix-ec-qty-val').dataset.qty, 10) || 0;
                    if (q > 0 && !cat.dataset.bundle && !cat.dataset.comboId) {
                        normalTotal += q * parseFloat(cat.dataset.price);
                    }
                });
                total -= normalTotal * (gdPercent / 100);
            }

            if (gdBadge) {
                if (gdPercent > 0) {
                    gdBadge.textContent = '\u2212' + gdPercent + '% aktiv';
                    gdBadge.style.display = '';
                    gdBadge.className = 'tix-ec-gd-badge tix-ec-gd-active';
                } else {
                    gdBadge.style.display = 'none';
                    gdBadge.className = 'tix-ec-gd-badge';
                }
            }

            totalEl.textContent = formatPrice(total);
            buyBtn.disabled = !hasItems || !(termsChk && termsChk.checked);
        }

        function getGdPercent(normalQty, bundlePkgQty, comboPkgQty) {
            if (!gdTiers.length) return 0;
            var gdQty = normalQty;
            if (gdCombineBundle) gdQty += bundlePkgQty;
            if (gdCombineCombo)  gdQty += comboPkgQty;
            var pct = 0;
            for (var i = gdTiers.length - 1; i >= 0; i--) {
                if (gdQty >= parseInt(gdTiers[i].min_qty, 10)) {
                    pct = parseInt(gdTiers[i].percent, 10);
                    break;
                }
            }
            return pct;
        }

        // ── Terms ──
        termsChk.addEventListener('change', function() {
            updateTotal();
        });

        // ── Buy (Express Checkout) ──
        buyBtn.addEventListener('click', function() {
            var items = [];
            var normalQty = 0, bundlePkgQty = 0, comboPkgQty = 0;

            cats.forEach(function(cat) {
                var q = parseInt(cat.querySelector('.tix-ec-qty-val').dataset.qty, 10) || 0;
                if (q > 0) {
                    if (cat.dataset.bundle === '1') bundlePkgQty += q;
                    else if (cat.dataset.comboId) comboPkgQty += q;
                    else normalQty += q;
                }
            });

            var gdPercent = getGdPercent(normalQty, bundlePkgQty, comboPkgQty);

            cats.forEach(function(cat) {
                var q = parseInt(cat.querySelector('.tix-ec-qty-val').dataset.qty, 10) || 0;
                if (q <= 0) return;

                if (cat.dataset.comboId) {
                    var comboItems = [];
                    try { comboItems = JSON.parse(cat.dataset.comboItems || '[]'); } catch(e) {}
                    items.push({
                        combo: 1,
                        combo_id: cat.dataset.comboId,
                        combo_label: cat.dataset.comboLabel || '',
                        combo_price: parseFloat(cat.dataset.comboPrice),
                        qty: q,
                        products: comboItems
                    });
                } else if (cat.dataset.bundle === '1') {
                    items.push({
                        product_id: parseInt(cat.dataset.productId, 10),
                        qty: q,
                        bundle: 1,
                        bundle_buy: parseInt(cat.dataset.bundleBuy, 10),
                        bundle_pay: parseInt(cat.dataset.bundlePay, 10),
                        bundle_label: cat.dataset.bundleLabel || ''
                    });
                } else {
                    items.push({
                        product_id: parseInt(cat.dataset.productId, 10),
                        qty: q
                    });
                }
            });

            if (items.length === 0) return;

            buyBtn.disabled = true;
            buyBtn.querySelector('.tix-ec-buy-text').style.display = 'none';
            buyBtn.querySelector('.tix-ec-buy-loading').style.display = '';

            var data = new FormData();
            data.append('action', 'tix_express_checkout');
            data.append('nonce', ehSel.nonce);
            data.append('items', JSON.stringify(items));
            data.append('terms_accepted', '1');
            if (gdPercent > 0) {
                data.append('group_discount_percent', gdPercent);
            }

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
                        resetBuy();
                    }
                })
                .catch(function() {
                    showMessage('Verbindungsfehler.', 'error');
                    resetBuy();
                });
        });

        function resetBuy() {
            buyBtn.disabled = false;
            buyBtn.querySelector('.tix-ec-buy-text').style.display = '';
            buyBtn.querySelector('.tix-ec-buy-loading').style.display = 'none';
        }

        function showMessage(text, type) {
            msgEl.textContent = text;
            msgEl.className = 'tix-ec-message tix-ec-msg-' + type;
            msgEl.style.display = '';
            setTimeout(function() { msgEl.style.display = 'none'; }, 4000);
        }

        function formatPrice(val) {
            return val.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' \u20ac';
        }
    });
})();
