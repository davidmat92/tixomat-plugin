/**
 * Tixomat Exit-Intent Popup
 * Desktop: mouseleave top. Mobile: scroll-up pattern.
 */
(function () {
    'use strict';

    var cfg = window.tixExitIntent;
    if (!cfg || !cfg.coupon) return;

    // Cookie check
    if (getCookie('tix_exit_seen')) return;

    var shown = false;
    var ready = false;
    var overlay = null;

    // Delay before activation
    setTimeout(function () { ready = true; }, cfg.delay || 5000);

    // --- Desktop: mouseleave ---
    document.documentElement.addEventListener('mouseleave', function (e) {
        if (e.clientY > 0) return; // Only trigger when mouse leaves from top
        showPopup();
    });

    // --- Mobile: scroll-up pattern ---
    var lastY = 0;
    var lastDir = 'down';
    var scrollUpStart = 0;

    window.addEventListener('scroll', function () {
        if (!ready || shown) return;
        var y = window.scrollY;

        if (y < lastY) {
            // Scrolling up
            if (lastDir !== 'up') {
                scrollUpStart = lastY;
                lastDir = 'up';
            }
            // Trigger if scrolled up 200px quickly
            if (scrollUpStart - y > 200 && y > 300) {
                showPopup();
            }
        } else {
            lastDir = 'down';
        }
        lastY = y;
    }, { passive: true });

    function showPopup() {
        if (shown || !ready) return;
        shown = true;

        createPopup();
        setCookie('tix_exit_seen', '1', cfg.cookieDays || 7);

        // Track impression
        var data = new FormData();
        data.append('action', 'tix_exit_intent_impression');
        data.append('nonce', cfg.nonce);
        data.append('event_id', cfg.eventId);
        fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' }).catch(function(){});
    }

    function createPopup() {
        overlay = document.createElement('div');
        overlay.className = 'tix-ei-overlay';
        overlay.innerHTML =
            '<div class="tix-ei-modal">' +
                '<button class="tix-ei-close" aria-label="Schließen">&times;</button>' +
                '<div class="tix-ei-emoji">🎟️</div>' +
                '<h2 class="tix-ei-headline">' + escHtml(cfg.headline) + '</h2>' +
                '<p class="tix-ei-text">' + escHtml(cfg.text) + '</p>' +
                '<div class="tix-ei-coupon" role="button" tabindex="0" title="Klicken zum Kopieren">' +
                    '<span class="tix-ei-code">' + escHtml(cfg.coupon) + '</span>' +
                    '<span class="tix-ei-copy-icon">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>' +
                    '</span>' +
                '</div>' +
                '<button class="tix-ei-button">' + escHtml(cfg.buttonText) + '</button>' +
            '</div>';

        document.body.appendChild(overlay);

        // Animations
        requestAnimationFrame(function () {
            overlay.classList.add('tix-ei-overlay--visible');
        });

        // Events
        overlay.querySelector('.tix-ei-close').addEventListener('click', closePopup);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closePopup();
        });
        document.addEventListener('keydown', onEsc);

        // Copy coupon
        var couponEl = overlay.querySelector('.tix-ei-coupon');
        couponEl.addEventListener('click', function () {
            copyToClipboard(cfg.coupon);
            couponEl.classList.add('tix-ei-coupon--copied');
            var codeEl = couponEl.querySelector('.tix-ei-code');
            codeEl.textContent = 'Kopiert!';
            setTimeout(function () {
                codeEl.textContent = cfg.coupon;
                couponEl.classList.remove('tix-ei-coupon--copied');
            }, 2000);
        });

        // Button: scroll to ticket selector
        overlay.querySelector('.tix-ei-button').addEventListener('click', function () {
            closePopup();
            var sel = document.querySelector('.tix-sel, .tix-ticket-selector, [class*="ticket-selector"]');
            if (sel) sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    function closePopup() {
        if (!overlay) return;
        overlay.classList.remove('tix-ei-overlay--visible');
        setTimeout(function () {
            if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 300);
        document.removeEventListener('keydown', onEsc);
    }

    function onEsc(e) {
        if (e.key === 'Escape') closePopup();
    }

    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).catch(function(){});
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
        return m ? m[1] : '';
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + value + ';path=/;expires=' + d.toUTCString() + ';SameSite=Lax';
    }
})();
