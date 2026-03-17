/**
 * Tixomat Cart Page
 * Nutzt die gleichen AJAX-Endpoints wie der Checkout (tix_update_cart, tix_apply_coupon, tix_remove_coupon).
 */
(function() {
    'use strict';

    var wrap = document.getElementById('tix-cart');
    if (!wrap) return;

    var ajaxUrl = wrap.dataset.ajaxUrl || '/wp-admin/admin-ajax.php';
    var nonce   = wrap.dataset.nonce || '';

    // ── Cart Update (Qty +/-/Remove) ──
    wrap.addEventListener('click', function(e) {
        var btn;

        // Qty buttons
        btn = e.target.closest('.tix-co-qty-btn');
        if (btn) {
            e.preventDefault();
            var key    = btn.dataset.key || btn.dataset.comboGroup;
            var action = btn.classList.contains('tix-co-qty-plus') ? 'increase' : 'decrease';
            updateCart(key, action);
            return;
        }

        // Remove
        btn = e.target.closest('.tix-co-item-remove');
        if (btn) {
            e.preventDefault();
            updateCart(btn.dataset.key, 'remove');
            return;
        }

        // Coupon apply
        if (e.target.id === 'tix-cart-coupon-btn' || e.target.closest('#tix-cart-coupon-btn')) {
            e.preventDefault();
            applyCoupon();
            return;
        }

        // Coupon remove
        btn = e.target.closest('.tix-co-coupon-remove');
        if (btn) {
            e.preventDefault();
            removeCoupon(btn.dataset.coupon);
            return;
        }
    });

    // Enter on coupon input
    var couponInput = document.getElementById('tix-cart-coupon-code');
    if (couponInput) {
        couponInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); }
        });
    }

    function updateCart(cartKey, action) {
        if (!cartKey) return;
        setLoading(true);
        post('tix_update_cart', { cart_key: cartKey, cart_action: action }, function(data) {
            if (data.success) {
                applyCartData(data.data);
            }
            setLoading(false);
        });
    }

    function applyCoupon() {
        var input = document.getElementById('tix-cart-coupon-code');
        var code  = input ? input.value.trim() : '';
        if (!code) return;
        setLoading(true);
        post('tix_apply_coupon', { coupon_code: code }, function(data) {
            var msg = document.getElementById('tix-cart-coupon-msg');
            if (data.success) {
                if (input) input.value = '';
                applyCartData(data.data);
                showMsg(msg, data.data.message || 'Gutschein angewendet.', 'success');
            } else {
                showMsg(msg, data.data && data.data.message ? data.data.message : 'Fehler', 'error');
            }
            setLoading(false);
        });
    }

    function removeCoupon(code) {
        if (!code) return;
        setLoading(true);
        post('tix_remove_coupon', { coupon_code: code }, function(data) {
            if (data.success) applyCartData(data.data);
            setLoading(false);
        });
    }

    function applyCartData(d) {
        // Cart leer? → Seite neu laden
        if (d.empty) { location.reload(); return; }

        // Cart items
        var itemsEl = document.getElementById('tix-cart-items');
        if (itemsEl && d.html) itemsEl.innerHTML = d.html;

        // Coupons
        var couponsEl = document.getElementById('tix-cart-coupon-applied');
        if (couponsEl && d.coupons_html !== undefined) couponsEl.innerHTML = d.coupons_html;

        // Fees
        var feesEl = wrap.querySelector('.tix-co-fees');
        if (feesEl && d.fees_html !== undefined) feesEl.innerHTML = d.fees_html;

        // Discount row
        var discRow = wrap.querySelector('.tix-co-coupon-discount-row');
        if (discRow) {
            discRow.style.display = d.has_discount ? '' : 'none';
            var discEl = discRow.querySelector('.tix-co-discount');
            if (discEl && d.discount) discEl.innerHTML = d.discount;
        }

        // Total
        var totalEl = wrap.querySelector('.tix-co-total');
        if (totalEl && d.total) totalEl.innerHTML = d.total;
        var priceEl = wrap.querySelector('.tix-co-submit-price');
        if (priceEl && d.total) priceEl.innerHTML = stripTags(d.total);

        // Mini-Cart Badge aktualisieren
        var badges = document.querySelectorAll('.tix-minicart-count');
        badges.forEach(function(b) {
            b.textContent = d.count || '0';
            b.style.display = d.count ? '' : 'none';
        });
    }

    function post(action, params, cb) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        for (var k in params) fd.append(k, params[k]);
        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(cb)
            .catch(function() { setLoading(false); });
    }

    function setLoading(on) {
        wrap.classList.toggle('tix-co-loading', on);
    }

    function showMsg(el, text, type) {
        if (!el) return;
        el.textContent = text;
        el.className = 'tix-co-coupon-msg ' + type;
        el.style.display = '';
        setTimeout(function() { el.style.display = 'none'; }, 4000);
    }

    function stripTags(s) {
        var d = document.createElement('div');
        d.innerHTML = s;
        return d.textContent || '';
    }
})();
