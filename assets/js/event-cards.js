/**
 * Tixomat Event Cards — [tix_events] Frontend JS
 * Herz-Toggle, Filter, URL-Sync
 */
(function() {
    'use strict';

    // ── Herz-Toggle (global function, called via onclick) ──
    window.tixToggleSave = function(btn) {
        var eventId = btn.dataset.eventId;
        if (!eventId) return;

        if (typeof tixCards === 'undefined') return;

        if (!tixCards.isLoggedIn) {
            document.dispatchEvent(new CustomEvent('tixomat:login-required'));
            return;
        }

        var isSaved = btn.classList.contains('saved');
        var heartDefault = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';
        var heartSaved = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';

        // Optimistisches UI-Update
        btn.classList.toggle('saved');
        btn.innerHTML = btn.classList.contains('saved') ? heartSaved : heartDefault;

        var data = new FormData();
        data.append('action', 'tix_toggle_save_event');
        data.append('nonce', tixCards.nonce);
        data.append('event_id', eventId);

        fetch(tixCards.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (!r.success) {
                    // Revert
                    btn.classList.toggle('saved');
                    btn.innerHTML = btn.classList.contains('saved') ? heartSaved : heartDefault;
                }
            });
    };

    // ── Filter (Debounced) ──
    var filterTimeout;
    document.querySelectorAll('.ev-filters').forEach(function(bar) {
        var grid = bar.nextElementSibling;
        if (!grid || !grid.classList.contains('ev-grid')) return;

        var search = bar.querySelector('.ev-filter-search');
        var cat = bar.querySelector('.ev-filter-cat');

        function doFilter() {
            if (!tixCards) return;
            var data = new FormData();
            data.append('action', 'tix_filter_events');
            data.append('nonce', tixCards.nonce);
            if (search && search.value) data.append('search', search.value);
            if (cat && cat.value) data.append('category', cat.value);

            fetch(tixCards.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (r.success) {
                        grid.innerHTML = r.data.html || '<p style="grid-column:1/-1;text-align:center;padding:40px 0;opacity:0.5;">Keine Events gefunden.</p>';
                    }
                });
        }

        if (search) search.addEventListener('input', function() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(doFilter, 300);
        });
        if (cat) cat.addEventListener('change', doFilter);
    });

    // ── Live Search [tix_search] ──
    document.querySelectorAll('.tix-search-wrap').forEach(function(wrap) {
        var input = wrap.querySelector('.tix-search-input');
        var results = wrap.querySelector('.tix-search-results');
        var clear = wrap.querySelector('.tix-search-clear');
        var limit = parseInt(wrap.dataset.limit, 10) || 5;
        var searchTimer;

        if (!input || !results) return;

        input.addEventListener('input', function() {
            var q = input.value.trim();
            clear.style.display = q ? '' : 'none';
            clearTimeout(searchTimer);

            if (q.length < 2) {
                results.style.display = 'none';
                results.innerHTML = '';
                return;
            }

            searchTimer = setTimeout(function() {
                var data = new FormData();
                data.append('action', 'tix_search_events');
                data.append('nonce', tixCards.nonce);
                data.append('q', q);
                data.append('limit', limit);

                fetch(tixCards.ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        if (r.success && r.data.html) {
                            results.innerHTML = r.data.html;
                            results.style.display = '';
                        } else {
                            results.style.display = 'none';
                        }
                    });
            }, 250);
        });

        // Clear
        if (clear) clear.addEventListener('click', function() {
            input.value = '';
            clear.style.display = 'none';
            results.style.display = 'none';
            results.innerHTML = '';
            input.focus();
        });

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!wrap.contains(e.target)) {
                results.style.display = 'none';
            }
        });

        // Reopen on focus if has content
        input.addEventListener('focus', function() {
            if (results.innerHTML && input.value.length >= 2) {
                results.style.display = '';
            }
        });
    });
})();
