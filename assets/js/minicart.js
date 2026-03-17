/**
 * Tixomat Mini-Cart Drawer
 *
 * Trigger: Klick auf .tix-minicart-trigger oder [data-tix-minicart]
 * Nutzt tix_update_cart AJAX-Endpoint aus TIX_Checkout.
 */
(function() {
    'use strict';

    var overlay, drawer, body;
    var ajaxUrl, nonce;

    function init() {
        overlay = document.getElementById('tix-mc-overlay');
        drawer  = document.getElementById('tix-mc-drawer');
        body    = document.getElementById('tix-mc-drawer-body');
        if (!overlay || !drawer) return;

        ajaxUrl = (typeof tixMC !== 'undefined' && tixMC.ajaxUrl) ? tixMC.ajaxUrl : '/wp-admin/admin-ajax.php';
        nonce   = (typeof tixMC !== 'undefined' && tixMC.nonce)   ? tixMC.nonce   : '';

        // Trigger: Klick auf .tix-minicart-trigger oder [data-tix-minicart]
        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.tix-minicart-trigger, [data-tix-minicart]');
            if (trigger) {
                e.preventDefault();
                e.stopPropagation();
                open();
                return;
            }
        });

        // Close: X-Button
        var closeBtn = document.getElementById('tix-mc-drawer-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                close();
            });
        }

        // Close: Overlay-Klick (außerhalb Drawer)
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) close();
        });

        // Close: Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('tix-mc-open')) {
                close();
            }
        });

        // Qty + Remove im Drawer
        overlay.addEventListener('click', function(e) {
            var btn;

            btn = e.target.closest('.tix-mc-qty-btn');
            if (btn) {
                e.preventDefault();
                var key    = btn.dataset.key;
                var action = btn.classList.contains('tix-mc-qty-plus') ? 'increase' : 'decrease';
                updateCart(key, action);
                return;
            }

            btn = e.target.closest('.tix-mc-item-remove');
            if (btn) {
                e.preventDefault();
                updateCart(btn.dataset.key, 'remove');
                return;
            }
        });
    }

    function open() {
        if (!overlay) return;
        overlay.style.display = '';
        // Force reflow for animation
        void overlay.offsetHeight;
        overlay.classList.add('tix-mc-open');
        document.body.classList.add('tix-mc-body-open');
        refreshContent();
    }

    function close() {
        if (!overlay) return;
        overlay.classList.remove('tix-mc-open');
        document.body.classList.remove('tix-mc-body-open');
        setTimeout(function() {
            if (!overlay.classList.contains('tix-mc-open')) {
                overlay.style.display = 'none';
            }
        }, 300);
    }

    function refreshContent() {
        post('tix_get_minicart', {}, function(data) {
            if (data.success && body) {
                var content = document.querySelector('.tix-mc-content');
                if (content) content.outerHTML = data.data.html;
                updateBadges(data.data.count);
            }
        });
    }

    function updateCart(cartKey, action) {
        if (!cartKey) return;
        setLoading(true);
        post('tix_update_cart', { cart_key: cartKey, cart_action: action }, function(data) {
            if (data.success) {
                // Drawer-Inhalt neu laden
                refreshContent();
                updateBadges(data.data.count);

                // Cart-Page Items aktualisieren (falls offen)
                var cartPage = document.getElementById('tix-cart-items');
                if (cartPage && data.data.html) cartPage.innerHTML = data.data.html;
                var cartTotal = document.querySelector('.tix-cart .tix-co-total');
                if (cartTotal && data.data.total) cartTotal.innerHTML = data.data.total;
                var cartPrice = document.querySelector('.tix-cart-checkout-price');
                if (cartPrice && data.data.total) {
                    var d = document.createElement('div');
                    d.innerHTML = data.data.total;
                    cartPrice.textContent = d.textContent || '';
                }

                // Leer? → Seite neu laden wenn Cart-Page aktiv
                if (data.data.empty && cartPage) location.reload();
            }
            setLoading(false);
        });
    }

    function updateBadges(count) {
        document.querySelectorAll('.tix-minicart-count').forEach(function(b) {
            b.textContent = count || '0';
            b.style.display = count ? '' : 'none';
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
        var content = document.querySelector('.tix-mc-content');
        if (content) content.classList.toggle('tix-mc-loading', on);
    }

    // Init when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
