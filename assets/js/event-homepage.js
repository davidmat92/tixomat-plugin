/**
 * Tixomat Event Homepage — [tix_homepage] Frontend JS
 * Slider, Zeitfilter, Kategorie-Chips, Load More, URL-Sync,
 * Skeleton, Countdown, View Toggle (Grid/List), Smart-Time
 */
(function() {
    'use strict';

    var hp = document.querySelector('.tix-hp');
    if (!hp) return;

    var grid         = hp.querySelector('.tix-hp-grid');
    var timeBtns     = hp.querySelectorAll('.tix-hp-time-btn');
    var catChips     = hp.querySelectorAll('.tix-hp-cat-chip');
    var viewBtns     = hp.querySelectorAll('.tix-hp-view-btn');
    var loadMoreWrap = hp.querySelector('.tix-hp-load-more-wrap');
    var loadMoreBtn  = hp.querySelector('.tix-hp-load-more');
    var urlSync      = hp.dataset.urlSync === '1';
    var columns      = parseInt(hp.dataset.columns, 10) || 4;

    var currentTime = 'all';
    var currentCat  = '';
    var currentView = 'grid';
    var gridOffset  = 0;

    // ═══════════════════════════════════════════
    // URL-Sync: Lese initiale Filter aus URL
    // ═══════════════════════════════════════════
    if (urlSync) {
        var params = new URLSearchParams(window.location.search);
        var urlTime = params.get('time');
        var urlCat  = params.get('cat');
        var urlView = params.get('view');

        if (urlTime && urlTime !== 'all') {
            currentTime = urlTime;
            timeBtns.forEach(function(b) {
                b.classList.toggle('active', b.dataset.time === urlTime);
            });
            setTimeout(function() { doFilter(); }, 100);
        }
        if (urlCat) {
            currentCat = urlCat;
            catChips.forEach(function(c) {
                c.classList.toggle('active', c.dataset.cat === urlCat);
            });
            if (!urlTime || urlTime === 'all') {
                setTimeout(function() { doFilter(); }, 100);
            }
        }
        if (urlView === 'list') {
            currentView = 'list';
            applyViewMode();
            setTimeout(function() { doFilter(); }, 150);
        }
    }

    function updateUrl() {
        if (!urlSync) return;
        var params = new URLSearchParams(window.location.search);
        if (currentTime && currentTime !== 'all') params.set('time', currentTime); else params.delete('time');
        if (currentCat) params.set('cat', currentCat); else params.delete('cat');
        if (currentView === 'list') params.set('view', 'list'); else params.delete('view');
        var qs = params.toString();
        history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
    }

    // ═══════════════════════════════════════════
    // View Toggle (Grid / List)
    // ═══════════════════════════════════════════
    viewBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var newView = btn.dataset.view;
            if (newView === currentView) return;
            currentView = newView;
            viewBtns.forEach(function(b) { b.classList.toggle('active', b.dataset.view === currentView); });
            applyViewMode();
            gridOffset = 0;
            updateUrl();
            doFilter();
        });
    });

    function applyViewMode() {
        if (!grid) return;
        viewBtns.forEach(function(b) { b.classList.toggle('active', b.dataset.view === currentView); });
        if (currentView === 'list') {
            grid.classList.add('tix-hp-list-view');
        } else {
            grid.classList.remove('tix-hp-list-view');
        }
    }

    // ═══════════════════════════════════════════
    // Skeleton Loading
    // ═══════════════════════════════════════════
    function showSkeletons() {
        if (!grid) return;
        if (currentView === 'list') {
            // Listenansicht: einfache Shimmer-Lines
            var html = '';
            for (var i = 0; i < 6; i++) {
                html += '<div class="tix-hp-skeleton" style="display:flex;align-items:center;gap:16px;padding:14px 0;border-bottom:1px solid var(--tix-card-sand,#F0ECE4);">'
                    + '<div style="width:64px;height:64px;border-radius:12px;flex-shrink:0;" class="tix-hp-skeleton-img"></div>'
                    + '<div style="flex:1;">'
                    + '<div class="tix-hp-skeleton-line" style="width:30%;margin-bottom:6px;"></div>'
                    + '<div class="tix-hp-skeleton-line" style="width:70%;height:14px;margin-bottom:6px;"></div>'
                    + '<div class="tix-hp-skeleton-line" style="width:45%;"></div>'
                    + '</div>'
                    + '<div class="tix-hp-skeleton-line" style="width:60px;height:14px;"></div>'
                    + '</div>';
            }
            grid.innerHTML = html;
        } else {
            var count = Math.min(columns * 2, 8);
            var html = '';
            for (var i = 0; i < count; i++) {
                html += '<div class="tix-hp-skeleton">'
                    + '<div class="tix-hp-skeleton-img"></div>'
                    + '<div class="tix-hp-skeleton-body">'
                    + '<div class="tix-hp-skeleton-line"></div>'
                    + '<div class="tix-hp-skeleton-line"></div>'
                    + '<div class="tix-hp-skeleton-line"></div>'
                    + '</div>'
                    + '<div class="tix-hp-skeleton-footer">'
                    + '<div class="tix-hp-skeleton-price"></div>'
                    + '<div class="tix-hp-skeleton-btn"></div>'
                    + '</div>'
                    + '</div>';
            }
            grid.innerHTML = html;
        }
    }

    // ═══════════════════════════════════════════
    // Zeitfilter
    // ═══════════════════════════════════════════
    timeBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            timeBtns.forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentTime = btn.dataset.time;
            gridOffset = 0;
            updateUrl();
            doFilter();
        });
    });

    // ═══════════════════════════════════════════
    // Kategorie-Chips
    // ═══════════════════════════════════════════
    catChips.forEach(function(chip) {
        chip.addEventListener('click', function() {
            catChips.forEach(function(c) { c.classList.remove('active'); });
            chip.classList.add('active');
            currentCat = chip.dataset.cat;
            gridOffset = 0;
            updateUrl();
            doFilter();
        });
    });

    // ═══════════════════════════════════════════
    // AJAX Filter
    // ═══════════════════════════════════════════
    function doFilter() {
        if (!grid || typeof tixCards === 'undefined') return;

        showSkeletons();

        var data = new FormData();
        data.append('action', 'tix_homepage_filter');
        data.append('nonce', tixCards.nonce);
        data.append('time', currentTime);
        data.append('category', currentCat);
        data.append('view', currentView);
        data.append('limit', 12);

        fetch(tixCards.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (r.success) {
                    grid.innerHTML = r.data.html || '<p class="tix-hp-empty">Keine Events gefunden.</p>';
                    gridOffset = r.data.found;
                    if (loadMoreWrap) {
                        loadMoreWrap.style.display = r.data.found >= 12 ? '' : 'none';
                    }
                    if (loadMoreBtn) {
                        loadMoreBtn.dataset.offset = gridOffset;
                    }
                }
            })
            .catch(function() {
                grid.innerHTML = '<p class="tix-hp-empty">Fehler beim Laden.</p>';
            });
    }

    // ═══════════════════════════════════════════
    // Load More
    // ═══════════════════════════════════════════
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function() {
            if (loadMoreBtn.disabled) return;
            loadMoreBtn.disabled = true;
            loadMoreBtn.textContent = 'Laden…';

            var exclude = loadMoreBtn.dataset.exclude || '';
            var excludeCats = loadMoreBtn.dataset.excludeCats || '';
            var offset = parseInt(loadMoreBtn.dataset.offset, 10) || 0;

            var data = new FormData();
            data.append('action', 'tix_homepage_load_more');
            data.append('nonce', tixCards.nonce);
            data.append('offset', offset);
            data.append('limit', 8);
            data.append('time', currentTime);
            data.append('category', currentCat);
            data.append('view', currentView);
            data.append('exclude', exclude);
            data.append('exclude_cats', excludeCats);

            fetch(tixCards.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.textContent = 'Mehr Events laden';
                    if (r.success && r.data.html) {
                        grid.insertAdjacentHTML('beforeend', r.data.html);
                        gridOffset += r.data.found;
                        loadMoreBtn.dataset.offset = gridOffset;
                        if (!r.data.hasMore) loadMoreWrap.style.display = 'none';
                    } else {
                        loadMoreWrap.style.display = 'none';
                    }
                })
                .catch(function() {
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.textContent = 'Mehr Events laden';
                });
        });
    }

    // ═══════════════════════════════════════════
    // Hero Slider
    // ═══════════════════════════════════════════
    var slider = hp.querySelector('.tix-hp-hero-slider');
    if (slider) {
        var track   = slider.querySelector('.tix-hp-slider-track');
        var slides  = slider.querySelectorAll('.tix-hp-slider-slide');
        var dots    = slider.querySelectorAll('.tix-hp-slider-dot');
        var prevBtn = slider.querySelector('.tix-hp-slider-prev');
        var nextBtn = slider.querySelector('.tix-hp-slider-next');
        var current = 0;
        var total   = slides.length;
        var autoplayMs = parseInt(slider.dataset.autoplay, 10) || 5000;
        var autoplayTimer;

        function goTo(idx) {
            if (idx < 0) idx = total - 1;
            if (idx >= total) idx = 0;
            current = idx;
            track.style.transform = 'translateX(-' + (current * 100) + '%)';
            dots.forEach(function(d, i) { d.classList.toggle('active', i === current); });
        }

        function startAutoplay() {
            stopAutoplay();
            autoplayTimer = setInterval(function() { goTo(current + 1); }, autoplayMs);
        }
        function stopAutoplay() { if (autoplayTimer) clearInterval(autoplayTimer); }

        if (prevBtn) prevBtn.addEventListener('click', function() { goTo(current - 1); startAutoplay(); });
        if (nextBtn) nextBtn.addEventListener('click', function() { goTo(current + 1); startAutoplay(); });
        dots.forEach(function(dot) {
            dot.addEventListener('click', function() { goTo(parseInt(dot.dataset.index, 10)); startAutoplay(); });
        });

        var touchStartX = 0;
        slider.addEventListener('touchstart', function(e) { touchStartX = e.touches[0].clientX; stopAutoplay(); }, { passive: true });
        slider.addEventListener('touchend', function(e) {
            var diff = touchStartX - e.changedTouches[0].clientX;
            if (Math.abs(diff) > 50) goTo(diff > 0 ? current + 1 : current - 1);
            startAutoplay();
        }, { passive: true });

        slider.addEventListener('mouseenter', stopAutoplay);
        slider.addEventListener('mouseleave', startAutoplay);
        if (total > 1) startAutoplay();
    }

    // ═══════════════════════════════════════════
    // Countdown Timer
    // ═══════════════════════════════════════════
    function updateCountdowns() {
        var els = document.querySelectorAll('.tix-hp-countdown[data-start]');
        if (!els.length) return;
        var now = Date.now();
        els.forEach(function(el) {
            var start = new Date(el.dataset.start).getTime();
            var diff = start - now;
            if (diff <= 0) { el.textContent = 'Jetzt!'; return; }
            var h = Math.floor(diff / 3600000);
            var m = Math.floor((diff % 3600000) / 60000);
            el.textContent = h > 0 ? 'Beginnt in ' + h + 'h ' + m + 'min' : 'Beginnt in ' + m + ' Min.';
        });
    }
    updateCountdowns();
    setInterval(updateCountdowns, 60000);

    // ═══════════════════════════════════════════
    // Dashboard: Stats-Bar CountUp Animation
    // ═══════════════════════════════════════════
    var statsBar = hp.querySelector('.tix-hp-stats-bar[data-animate="1"]');
    if (statsBar) {
        var statsAnimated = false;
        function animateStats() {
            if (statsAnimated) return;
            statsAnimated = true;
            statsBar.querySelectorAll('.tix-hp-stat-value').forEach(function(el) {
                var target = parseInt(el.dataset.target, 10) || 0;
                if (target === 0) { el.textContent = '0'; return; }
                var duration = 1200;
                var start = performance.now();
                function step(now) {
                    var progress = Math.min((now - start) / duration, 1);
                    // easeOutExpo
                    var eased = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);
                    el.textContent = Math.round(eased * target);
                    if (progress < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            });
        }
        // IntersectionObserver for viewport trigger
        if ('IntersectionObserver' in window) {
            var obs = new IntersectionObserver(function(entries) {
                entries.forEach(function(e) {
                    if (e.isIntersecting) { animateStats(); obs.disconnect(); }
                });
            }, { threshold: 0.3 });
            obs.observe(statsBar);
        } else {
            animateStats();
        }
    }

    // ═══════════════════════════════════════════
    // Dashboard: Kategorie-Kacheln → Filter-Integration
    // ═══════════════════════════════════════════
    hp.querySelectorAll('.tix-hp-cat-tile').forEach(function(tile) {
        tile.addEventListener('click', function(e) {
            e.preventDefault();
            var cat = tile.dataset.cat;
            // Activate matching cat chip if exists
            var matchChip = hp.querySelector('.tix-hp-cat-chip[data-cat="' + cat + '"]');
            if (matchChip) {
                matchChip.click();
                // Scroll to grid
                var gridEl = hp.querySelector('.tix-hp-grid');
                if (gridEl) gridEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                // Fallback: navigate
                window.location.href = tile.href;
            }
        });
    });

    // ═══════════════════════════════════════════
    // Dashboard: Diese Woche horizontal scroll with grab
    // ═══════════════════════════════════════════
    hp.querySelectorAll('.tix-hp-week-scroll').forEach(function(scroll) {
        var isDown = false, startX, scrollLeft;
        scroll.addEventListener('mousedown', function(e) {
            if (e.target.closest('a')) return;
            isDown = true; scroll.style.cursor = 'grabbing';
            startX = e.pageX - scroll.offsetLeft;
            scrollLeft = scroll.scrollLeft;
        });
        scroll.addEventListener('mouseleave', function() { isDown = false; scroll.style.cursor = ''; });
        scroll.addEventListener('mouseup', function() { isDown = false; scroll.style.cursor = ''; });
        scroll.addEventListener('mousemove', function(e) {
            if (!isDown) return;
            e.preventDefault();
            scroll.scrollLeft = scrollLeft - (e.pageX - scroll.offsetLeft - startX) * 1.5;
        });
    });

})();
