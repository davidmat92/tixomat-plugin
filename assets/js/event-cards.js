/**
 * Tixomat Event Cards — [tix_events] Frontend JS
 * Herz-Toggle, Filter, URL-Sync, LocalStorage (Wishlist + Recent)
 */
(function() {
    'use strict';

    // ═══════════════════════════════════════════
    // LocalStorage-Helpers für Wishlist & Recent
    // Global verfügbar (auch für event-homepage.js)
    // ═══════════════════════════════════════════
    var STORAGE_RECENT = 'tix_recent_events';
    var STORAGE_FAVS   = 'tix_favorite_events';
    var MAX_RECENT = 10;

    function lsRead(key) {
        try {
            var raw = localStorage.getItem(key);
            return raw ? JSON.parse(raw) : [];
        } catch (e) { return []; }
    }
    function lsWrite(key, arr) {
        try { localStorage.setItem(key, JSON.stringify(arr)); } catch (e) {}
    }

    window.tixRecent = window.tixRecent || {
        get: function(){ return lsRead(STORAGE_RECENT); },
        add: function(id){
            id = parseInt(id, 10); if (!id) return;
            var list = lsRead(STORAGE_RECENT).filter(function(x){ return x !== id; });
            list.unshift(id);
            lsWrite(STORAGE_RECENT, list.slice(0, MAX_RECENT));
        },
        clear: function(){ lsWrite(STORAGE_RECENT, []); }
    };
    window.tixFavorites = window.tixFavorites || {
        get: function(){ return lsRead(STORAGE_FAVS); },
        has: function(id){ return lsRead(STORAGE_FAVS).indexOf(parseInt(id, 10)) !== -1; },
        toggle: function(id){
            id = parseInt(id, 10); if (!id) return false;
            var list = lsRead(STORAGE_FAVS);
            var i = list.indexOf(id);
            if (i === -1) list.push(id);
            else list.splice(i, 1);
            lsWrite(STORAGE_FAVS, list);
            return list.indexOf(id) !== -1;
        }
    };

    // ── Tracking: Event-Detailseite → in Recent aufnehmen
    try {
        var articleEl = document.querySelector('article.type-event[id^="post-"]');
        if (articleEl) {
            var m = articleEl.id.match(/^post-(\d+)$/);
            if (m) window.tixRecent.add(m[1]);
        }
    } catch (e) {}

    // ── Share-Button (Web Share API + Fallback: Copy-Link) ──
    window.tixShareEvent = function(btn) {
        var url = btn.dataset.url;
        var title = btn.dataset.title || '';
        var text = btn.dataset.text || '';
        if (!url) return;

        var data = { title: title, text: text, url: url };

        // Native Share API (Mobile + Desktop Chrome)
        if (navigator.share && navigator.canShare && navigator.canShare(data)) {
            navigator.share(data).catch(function(){ /* User cancelled */ });
            return;
        }
        if (navigator.share) {
            navigator.share(data).catch(function(){});
            return;
        }

        // Fallback: Clipboard
        var flashSuccess = function(){
            var original = btn.innerHTML;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
            btn.classList.add('ev-share-copied');
            setTimeout(function(){
                btn.innerHTML = original;
                btn.classList.remove('ev-share-copied');
            }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(flashSuccess, function(){
                window.prompt('Link zum Kopieren:', url);
            });
        } else {
            window.prompt('Link zum Kopieren:', url);
        }
    };

    // ── Herz-Toggle (global function, called via onclick) ──
    // Eingeloggt: Server-Sync via AJAX
    // Gast:      LocalStorage via window.tixFavorites (gesetzt durch event-homepage.js)
    window.tixToggleSave = function(btn) {
        var eventId = btn.dataset.eventId;
        if (!eventId) return;

        var heartDefault = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';
        var heartSaved = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';

        // Optimistisches UI-Update
        btn.classList.toggle('saved');
        btn.innerHTML = btn.classList.contains('saved') ? heartSaved : heartDefault;

        // Gast → LocalStorage
        if (typeof tixCards === 'undefined' || !tixCards.isLoggedIn) {
            if (window.tixFavorites) window.tixFavorites.toggle(eventId);
            return;
        }

        // Eingeloggt → Server + LocalStorage beide mitsyncen
        if (window.tixFavorites) window.tixFavorites.toggle(eventId);

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
                    if (window.tixFavorites) window.tixFavorites.toggle(eventId);
                }
            });
    };

    // ── Initialisierung: LocalStorage-Favoriten im UI spiegeln (Gäste) ──
    document.addEventListener('DOMContentLoaded', function(){
        if (typeof tixCards !== 'undefined' && tixCards.isLoggedIn) return; // User-Favoriten kommen vom Server
        if (!window.tixFavorites) return;
        var favs = window.tixFavorites.get();
        if (!favs.length) return;
        var heartSaved = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';
        document.querySelectorAll('.ev-save-btn[data-event-id]').forEach(function(btn){
            var id = parseInt(btn.dataset.eventId, 10);
            if (favs.indexOf(id) !== -1 && !btn.classList.contains('saved')) {
                btn.classList.add('saved');
                btn.innerHTML = heartSaved;
            }
        });
    });

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

    // ── Live Search [tix_search] mit Keyboard-Navigation ──
    document.querySelectorAll('.tix-search-wrap').forEach(function(wrap) {
        var input = wrap.querySelector('.tix-search-input');
        var results = wrap.querySelector('.tix-search-results');
        var clear = wrap.querySelector('.tix-search-clear');
        var limit = parseInt(wrap.dataset.limit, 10) || 5;
        var searchTimer;
        var selectedIdx = -1;

        if (!input || !results) return;

        function updateSelection() {
            var items = results.querySelectorAll('.tix-search-item');
            items.forEach(function(it, i){
                if (i === selectedIdx) {
                    it.classList.add('tix-search-item-active');
                    // Scroll into view
                    var top = it.offsetTop;
                    var bot = top + it.offsetHeight;
                    if (top < results.scrollTop) results.scrollTop = top;
                    else if (bot > results.scrollTop + results.clientHeight) results.scrollTop = bot - results.clientHeight;
                } else {
                    it.classList.remove('tix-search-item-active');
                }
            });
        }

        input.addEventListener('input', function() {
            var q = input.value.trim();
            clear.style.display = q ? '' : 'none';
            clearTimeout(searchTimer);
            selectedIdx = -1;

            if (q.length < 2) {
                results.style.display = 'none';
                results.innerHTML = '';
                return;
            }

            // Loading state
            results.innerHTML = '<div class="tix-search-loading" style="padding:16px;text-align:center;color:#94a3b8;font-size:13px;">Suche…</div>';
            results.style.display = '';

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
            }, 200);
        });

        // Keyboard Navigation
        input.addEventListener('keydown', function(e) {
            var items = results.querySelectorAll('.tix-search-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIdx = (selectedIdx + 1) % items.length;
                updateSelection();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIdx = selectedIdx <= 0 ? items.length - 1 : selectedIdx - 1;
                updateSelection();
            } else if (e.key === 'Enter' && selectedIdx >= 0) {
                e.preventDefault();
                items[selectedIdx].click();
            } else if (e.key === 'Escape') {
                results.style.display = 'none';
                input.blur();
            }
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
