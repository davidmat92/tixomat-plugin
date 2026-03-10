/**
 * Tixomat – Modal Ticket Checkout
 * Shortcode: [tix_ticket_modal]
 * 2-Step Modal: Ticket-Auswahl → Checkout (Billing + Payment)
 */
(function() {
    'use strict';

    document.querySelectorAll('.tix-mc-trigger').forEach(function(trigger) {
        var modalId = trigger.dataset.modal;
        var overlay = document.getElementById(modalId);
        if (!overlay) return;

        var modal    = overlay.querySelector('.tix-mc-modal');
        var closeBtn = overlay.querySelector('.tix-mc-close');
        var cats     = overlay.querySelectorAll('.tix-mc-step-1 > .tix-mc-body > .tix-mc-cats > .tix-mc-cat');
        var totalEl  = overlay.querySelector('.tix-mc-total-price');
        var nextBtn  = overlay.querySelector('.tix-mc-next');
        var backBtn  = overlay.querySelector('.tix-mc-back');
        var msgEl    = overlay.querySelector('.tix-mc-message');
        var eventId  = overlay.dataset.eventId;
        var step1    = overlay.querySelector('.tix-mc-step-1');
        var step2    = overlay.querySelector('.tix-mc-step-2');
        var checkoutWrap = overlay.querySelector('.tix-mc-checkout-wrap');

        // Also include offer cats (Bundles in the offers section)
        var offerCats = overlay.querySelectorAll('.tix-mc-offers .tix-mc-cat');
        var allCats   = overlay.querySelectorAll('.tix-mc-cat');

        // Angebote toggle
        var offersBtn  = overlay.querySelector('.tix-mc-offers-btn');
        var offersWrap = overlay.querySelector('.tix-mc-offers');

        // Mengenrabatt
        var gdEl = overlay.querySelector('.tix-mc-gd');
        var gdBadge = gdEl ? gdEl.querySelector('.tix-mc-gd-badge') : null;
        var gdTiers = [];
        var gdCombineBundle = false;
        var gdCombineCombo  = false;
        if (gdEl) {
            try { gdTiers = JSON.parse(gdEl.dataset.tiers || '[]'); } catch(e) {}
            gdCombineBundle = gdEl.dataset.combineBundle === '1';
            gdCombineCombo  = gdEl.dataset.combineCombo === '1';
        }

        // Coupon
        var couponInput  = overlay.querySelector('.tix-mc-coupon-code');
        var couponBtn    = overlay.querySelector('.tix-mc-coupon-btn');
        var couponResult = overlay.querySelector('.tix-mc-coupon-result');
        var appliedCoupon = '';

        // State
        var checkoutLoaded = false;

        // ══════════════════════════════════════
        // OPEN / CLOSE
        // ══════════════════════════════════════

        trigger.querySelector('.tix-mc-trigger-btn').addEventListener('click', function() {
            overlay.style.display = '';
            document.body.style.overflow = 'hidden';
        });

        function closeModal() {
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }

        closeBtn.addEventListener('click', closeModal);

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.style.display !== 'none') closeModal();
        });

        // ══════════════════════════════════════
        // OFFERS TOGGLE
        // ══════════════════════════════════════

        if (offersBtn && offersWrap) {
            offersBtn.addEventListener('click', function() {
                var icon = this.querySelector('.tix-mc-offers-icon');
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

        // ══════════════════════════════════════
        // QTY +/-
        // ══════════════════════════════════════

        allCats.forEach(function(cat) {
            var minus  = cat.querySelector('.tix-mc-minus');
            var plus   = cat.querySelector('.tix-mc-plus');
            var valEl  = cat.querySelector('.tix-mc-qty-val');
            var maxQty = parseInt(cat.dataset.max, 10) || 100;

            if (!minus || !plus || !valEl) return;

            minus.addEventListener('click', function() {
                var q = parseInt(valEl.dataset.qty, 10) || 0;
                if (q > 0) {
                    q--;
                    valEl.dataset.qty = q;
                    valEl.textContent = q;
                    cat.classList.toggle('tix-mc-cat-active', q > 0);
                    updateTotal();
                }
            });

            plus.addEventListener('click', function() {
                var q = parseInt(valEl.dataset.qty, 10) || 0;
                if (q < maxQty) {
                    q++;
                    valEl.dataset.qty = q;
                    valEl.textContent = q;
                    cat.classList.add('tix-mc-cat-active');
                    updateTotal();
                }
            });
        });

        // ══════════════════════════════════════
        // UPDATE TOTAL
        // ══════════════════════════════════════

        function updateTotal() {
            var total = 0;
            var hasItems = false;
            var normalQty = 0;
            var bundlePkgQty = 0;
            var comboPkgQty = 0;

            allCats.forEach(function(cat) {
                var q = parseInt(cat.querySelector('.tix-mc-qty-val').dataset.qty, 10) || 0;
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
                allCats.forEach(function(cat) {
                    var q = parseInt(cat.querySelector('.tix-mc-qty-val').dataset.qty, 10) || 0;
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
                    gdBadge.className = 'tix-mc-gd-badge tix-mc-gd-active';
                } else {
                    gdBadge.style.display = 'none';
                    gdBadge.className = 'tix-mc-gd-badge';
                }
            }

            totalEl.textContent = formatPrice(total);
            nextBtn.disabled = !hasItems;
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

        // ══════════════════════════════════════
        // COUPON
        // ══════════════════════════════════════

        if (couponBtn && couponInput) {
            couponBtn.addEventListener('click', function() {
                var code = couponInput.value.trim();
                if (!code) return;

                couponBtn.disabled = true;
                couponBtn.textContent = '...';

                var data = new FormData();
                data.append('action', 'tix_validate_coupon');
                data.append('nonce', tixModal.nonce);
                data.append('coupon_code', code);

                fetch(tixModal.ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            appliedCoupon = code;
                            couponResult.textContent = res.data.message || 'Gutschein angewendet!';
                            couponResult.className = 'tix-mc-coupon-result success';
                            couponResult.style.display = '';
                        } else {
                            appliedCoupon = '';
                            couponResult.textContent = res.data.message || 'Ungültiger Code.';
                            couponResult.className = 'tix-mc-coupon-result error';
                            couponResult.style.display = '';
                        }
                    })
                    .catch(function() {
                        couponResult.textContent = 'Verbindungsfehler.';
                        couponResult.className = 'tix-mc-coupon-result error';
                        couponResult.style.display = '';
                    })
                    .finally(function() {
                        couponBtn.disabled = false;
                        couponBtn.textContent = 'Einlösen';
                    });
            });
        }

        // ══════════════════════════════════════
        // WEITER → ADD TO CART → STEP 2
        // ══════════════════════════════════════

        nextBtn.addEventListener('click', function() {
            var items = [];
            var normalQty = 0, bundlePkgQty = 0, comboPkgQty = 0;

            allCats.forEach(function(cat) {
                var q = parseInt(cat.querySelector('.tix-mc-qty-val').dataset.qty, 10) || 0;
                if (q > 0) {
                    if (cat.dataset.bundle === '1') bundlePkgQty += q;
                    else if (cat.dataset.comboId) comboPkgQty += q;
                    else normalQty += q;
                }
            });

            var gdPercent = getGdPercent(normalQty, bundlePkgQty, comboPkgQty);

            allCats.forEach(function(cat) {
                var q = parseInt(cat.querySelector('.tix-mc-qty-val').dataset.qty, 10) || 0;
                if (q <= 0) return;

                if (cat.dataset.comboId) {
                    var comboItems = [];
                    try { comboItems = JSON.parse(cat.dataset.comboItems || '[]'); } catch(e) {}
                    items.push({
                        combo: 1,
                        combo_id: cat.dataset.comboId,
                        combo_label: cat.dataset.comboLabel || '',
                        combo_price: parseFloat(cat.dataset.comboPrice),
                        quantity: q,
                        products: comboItems
                    });
                } else if (cat.dataset.bundle === '1') {
                    items.push({
                        product_id: parseInt(cat.dataset.productId, 10),
                        quantity: q,
                        bundle: 1,
                        bundle_buy: parseInt(cat.dataset.bundleBuy, 10),
                        bundle_pay: parseInt(cat.dataset.bundlePay, 10),
                        bundle_label: cat.dataset.bundleLabel || ''
                    });
                } else {
                    items.push({
                        product_id: parseInt(cat.dataset.productId, 10),
                        quantity: q
                    });
                }
            });

            if (items.length === 0) return;

            // Loading state
            nextBtn.disabled = true;
            nextBtn.querySelector('.tix-mc-next-text').style.display = 'none';
            nextBtn.querySelector('.tix-mc-next-loading').style.display = '';

            var data = new FormData();
            data.append('action', 'tix_add_to_cart');
            data.append('nonce', tixModal.nonce);
            data.append('items', JSON.stringify(items));
            if (appliedCoupon) {
                data.append('coupon_code', appliedCoupon);
            }
            if (gdPercent > 0) {
                data.append('group_discount_percent', gdPercent);
            }

            fetch(tixModal.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        showStep2();
                    } else {
                        showMessage(res.data.message || 'Fehler beim Hinzufügen.', 'error');
                        resetNext();
                    }
                })
                .catch(function() {
                    showMessage('Verbindungsfehler.', 'error');
                    resetNext();
                });
        });

        function resetNext() {
            nextBtn.disabled = false;
            nextBtn.querySelector('.tix-mc-next-text').style.display = '';
            nextBtn.querySelector('.tix-mc-next-loading').style.display = 'none';
        }

        // ══════════════════════════════════════
        // STEP 2: CHECKOUT
        // ══════════════════════════════════════

        function showStep2() {
            step1.classList.remove('active');
            step2.classList.add('active');

            // Load checkout form via AJAX
            checkoutWrap.innerHTML = '<div class="tix-mc-checkout-loading">Checkout wird geladen\u2026</div>';

            var data = new FormData();
            data.append('action', 'tix_mc_checkout_form');
            data.append('nonce', tixModal.checkoutNonce);

            fetch(tixModal.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success && res.data.html) {
                        checkoutWrap.innerHTML = res.data.html;
                        checkoutLoaded = true;
                        initCheckoutForm();
                    } else {
                        checkoutWrap.innerHTML = '<div class="tix-mc-checkout-loading" style="color:var(--tx-red,#E53B3B);">'
                            + (res.data && res.data.message ? res.data.message : 'Checkout konnte nicht geladen werden.')
                            + '</div>';
                    }
                })
                .catch(function() {
                    checkoutWrap.innerHTML = '<div class="tix-mc-checkout-loading" style="color:var(--tx-red,#E53B3B);">Verbindungsfehler.</div>';
                });
        }

        // ══════════════════════════════════════
        // CHECKOUT FORM INIT
        // ══════════════════════════════════════

        function initCheckoutForm() {
            var form = checkoutWrap.querySelector('.tix-mc-form');
            if (!form) return;

            var submitBtn = form.querySelector('.tix-mc-submit');

            // ── Gateway Radio switching ──
            form.addEventListener('change', function(e) {
                if (!e.target.classList.contains('tix-mc-gw-radio')) return;
                var activeGw = e.target.closest('.tix-mc-gateway');
                form.querySelectorAll('.tix-mc-gateway').forEach(function(gw) {
                    gw.classList.remove('tix-mc-gw-active');
                    var fields = gw.querySelector('.tix-mc-gw-fields');
                    if (fields) fields.style.display = 'none';
                });
                activeGw.classList.add('tix-mc-gw-active');
                var activeFields = activeGw.querySelector('.tix-mc-gw-fields');
                if (activeFields) activeFields.style.display = '';
            });

            // ── Click on gateway row to select ──
            form.addEventListener('click', function(e) {
                var gwEl = e.target.closest('.tix-mc-gateway');
                if (!gwEl) return;
                if (e.target.closest('input, select, textarea, a, button, label')) return;
                var radio = gwEl.querySelector('.tix-mc-gw-radio');
                if (radio && !radio.checked) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });

            // ── Form submit ──
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                submitCheckout(form, submitBtn);
            });

            submitBtn.addEventListener('click', function(e) {
                e.preventDefault();
                submitCheckout(form, submitBtn);
            });
        }

        function submitCheckout(form, submitBtn) {
            // Validation
            var valid = true;
            form.querySelectorAll('.tix-mc-field-error').forEach(function(el) {
                el.classList.remove('tix-mc-field-error');
            });
            form.querySelectorAll('.tix-mc-legal.tix-mc-field-error').forEach(function(el) {
                el.classList.remove('tix-mc-field-error');
            });

            form.querySelectorAll('[required]').forEach(function(input) {
                if (!input.value || (input.type === 'checkbox' && !input.checked)) {
                    var field = input.closest('.tix-mc-field, .tix-mc-legal');
                    if (field) field.classList.add('tix-mc-field-error');
                    valid = false;
                }
            });

            if (!valid) {
                showMessage('Bitte f\u00fclle alle Pflichtfelder aus.', 'error');
                // Scroll to first error within modal
                var firstErr = form.querySelector('.tix-mc-field-error');
                if (firstErr) {
                    firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            // Loading state
            submitBtn.disabled = true;
            submitBtn.querySelector('.tix-mc-submit-text').style.display = 'none';
            submitBtn.querySelector('.tix-mc-submit-loading').style.display = '';
            hideMessage();

            // Serialize form
            var formData = new URLSearchParams(new FormData(form));

            // Submit to WC AJAX checkout
            fetch(tixModal.wcCheckoutUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.result === 'success' && result.redirect) {
                    showMessage('Bestellung erfolgreich!', 'success');
                    setTimeout(function() {
                        window.location.href = result.redirect;
                    }, 600);
                } else if (result.result === 'failure') {
                    // Parse WC error messages
                    var errors = '';
                    if (result.messages) {
                        var tmp = document.createElement('div');
                        tmp.innerHTML = result.messages;
                        var lis = tmp.querySelectorAll('li');
                        if (lis.length) {
                            var msgs = [];
                            lis.forEach(function(li) { msgs.push(li.textContent.trim()); });
                            errors = msgs.join('\n');
                        } else {
                            errors = tmp.textContent.trim();
                        }
                    }
                    showMessage(errors || 'Bestellung konnte nicht abgeschlossen werden.', 'error');
                    resetSubmit(submitBtn);
                } else {
                    showMessage('Unbekannte Antwort.', 'error');
                    resetSubmit(submitBtn);
                }
            })
            .catch(function(err) {
                showMessage('Verbindungsfehler.', 'error');
                resetSubmit(submitBtn);
            });
        }

        function resetSubmit(btn) {
            btn.disabled = false;
            btn.querySelector('.tix-mc-submit-text').style.display = '';
            btn.querySelector('.tix-mc-submit-loading').style.display = 'none';
        }

        // ══════════════════════════════════════
        // BACK BUTTON (STEP 2 → STEP 1)
        // ══════════════════════════════════════

        backBtn.addEventListener('click', function() {
            step2.classList.remove('active');
            step1.classList.add('active');
            resetNext();
        });

        // ══════════════════════════════════════
        // HELPERS
        // ══════════════════════════════════════

        function showMessage(text, type) {
            msgEl.textContent = text;
            msgEl.className = 'tix-mc-message tix-mc-msg-' + type;
            msgEl.style.display = '';
            if (type !== 'success') {
                setTimeout(function() { msgEl.style.display = 'none'; }, 5000);
            }
        }

        function hideMessage() {
            msgEl.style.display = 'none';
        }

        function formatPrice(val) {
            return val.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' \u20ac';
        }
    });
})();
