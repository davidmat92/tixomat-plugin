/**
 * Tixomat Social Proof – Live Viewer Count
 * Heartbeat-basiert, kein jQuery.
 */
(function () {
    'use strict';

    var cfg = window.tixSocialProof;
    if (!cfg || !cfg.eventId) return;

    var sessionHash = sessionStorage.getItem('tix_sp_hash');
    if (!sessionHash) {
        sessionHash = Math.random().toString(36).substring(2) + Date.now().toString(36);
        sessionStorage.setItem('tix_sp_hash', sessionHash);
    }

    var el = null;
    var countEl = null;
    var currentCount = 0;
    var isVisible = false;

    function createWidget() {
        el = document.createElement('div');
        el.className = 'tix-sp tix-sp--' + cfg.position;
        el.setAttribute('aria-live', 'polite');
        el.innerHTML =
            '<span class="tix-sp__icon">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                    '<circle cx="12" cy="12" r="3"/>' +
                    '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>' +
                '</svg>' +
            '</span>' +
            '<span class="tix-sp__text"></span>' +
            '<span class="tix-sp__dot"></span>';

        countEl = el.querySelector('.tix-sp__text');
        el.style.opacity = '0';

        // Position bestimmen
        if (cfg.position === 'floating') {
            document.body.appendChild(el);
        } else if (cfg.position === 'below_hero') {
            var hero = document.querySelector('.tix-ep-hero, .tix-ep-image');
            if (hero) {
                hero.parentNode.insertBefore(el, hero.nextSibling);
            } else {
                var content = document.querySelector('.tix-ep, .entry-content, .single-event');
                if (content) content.insertBefore(el, content.firstChild);
            }
        } else {
            // above_tickets (default)
            var selector = document.querySelector('.tix-sel, .tix-ticket-selector, [class*="ticket-selector"]');
            if (selector) {
                selector.parentNode.insertBefore(el, selector);
            } else {
                var entry = document.querySelector('.tix-ep, .entry-content, .single-event');
                if (entry) entry.appendChild(el);
            }
        }
    }

    function updateDisplay(count) {
        if (!el || !countEl) return;

        var text = cfg.text.replace('{count}', count);
        countEl.textContent = text;

        if (count >= cfg.minCount && !isVisible) {
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
            isVisible = true;
        } else if (count < cfg.minCount && isVisible) {
            el.style.opacity = '0';
            isVisible = false;
        }

        currentCount = count;
    }

    function heartbeat() {
        var data = new FormData();
        data.append('action', 'tix_heartbeat');
        data.append('nonce', cfg.nonce);
        data.append('event_id', cfg.eventId);
        data.append('session_hash', sessionHash);

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success && res.data) {
                updateDisplay(res.data.count);
            }
        })
        .catch(function () {
            // Silent fail
        });
    }

    // Init
    createWidget();
    heartbeat();
    setInterval(heartbeat, cfg.interval || 30000);
})();
