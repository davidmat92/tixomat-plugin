/**
 * Tixomat Event Homepage — [tix_homepage] Frontend JS
 * Slider, Zeitfilter, Kategorie-Chips, Load More, URL-Sync,
 * Skeleton, Countdown, View Toggle (Grid/List), Smart-Time
 */
(function() {
    'use strict';

    // (LocalStorage-Helpers + Recent-Tracking leben in event-cards.js,
    //  werden dort global als window.tixRecent und window.tixFavorites exposed.)

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
            if (currentView === 'calendar') {
                loadCalendar();
                return;
            }
            gridOffset = 0;
            updateUrl();
            doFilter();
        });
    });

    function applyViewMode() {
        if (!grid) return;
        viewBtns.forEach(function(b) { b.classList.toggle('active', b.dataset.view === currentView); });
        grid.classList.toggle('tix-hp-list-view', currentView === 'list');
        grid.classList.toggle('tix-hp-cal-view',  currentView === 'calendar');
        // Load-More bei Kalender verbergen
        if (loadMoreWrap) {
            loadMoreWrap.style.display = currentView === 'calendar' ? 'none' : '';
        }
    }

    // ═══════════════════════════════════════════
    // Kalender-Ansicht
    // ═══════════════════════════════════════════
    var calState = { year: new Date().getFullYear(), month: new Date().getMonth() + 1, days: {} };

    function loadCalendar() {
        if (!grid || typeof tixCards === 'undefined') return;
        grid.innerHTML = '<div style="padding:40px;text-align:center;color:#94a3b8;">Kalender lädt…</div>';

        var data = new FormData();
        data.append('action', 'tix_homepage_calendar');
        data.append('nonce', tixCards.nonce);
        data.append('year', calState.year);
        data.append('month', calState.month);

        fetch(tixCards.ajaxUrl, { method: 'POST', body: data })
            .then(function(r){ return r.json(); })
            .then(function(r){
                if (!r.success) { grid.innerHTML = '<p style="text-align:center;padding:40px;">Fehler beim Laden.</p>'; return; }
                grid.innerHTML = r.data.html;
                calState.days = r.data.days || {};
                bindCalendarEvents();
            });
    }

    function bindCalendarEvents() {
        // Prev/Next Monat
        grid.querySelectorAll('.tix-hp-cal-nav').forEach(function(btn){
            btn.addEventListener('click', function(){
                var dir = parseInt(btn.dataset.dir, 10);
                calState.month += dir;
                if (calState.month < 1) { calState.month = 12; calState.year--; }
                if (calState.month > 12) { calState.month = 1; calState.year++; }
                loadCalendar();
            });
        });

        // Tag-Klick → Events des Tages anzeigen
        var dayPanel = grid.querySelector('.tix-hp-cal-day-events');
        grid.querySelectorAll('.tix-hp-cal-cell.has-events').forEach(function(cell){
            cell.addEventListener('click', function(){
                var day = cell.dataset.day;
                var events = calState.days[day] || [];
                if (!events.length) return;
                var html = '<div class="tix-hp-cal-day-header">' + events.length + ' Event' + (events.length > 1 ? 's' : '') + ' am ' + day + '.</div>';
                events.forEach(function(ev){
                    html += '<a href="' + ev.url + '" class="tix-hp-cal-day-item">'
                        + (ev.thumb ? '<div class="tix-hp-cal-day-thumb" style="background-image:url(\'' + ev.thumb + '\')"></div>' : '<div class="tix-hp-cal-day-thumb"></div>')
                        + '<div class="tix-hp-cal-day-info">'
                        + (ev.time ? '<div class="tix-hp-cal-day-time">' + ev.time + ' Uhr</div>' : '')
                        + '<div class="tix-hp-cal-day-title">' + ev.title + '</div>'
                        + (ev.loc ? '<div class="tix-hp-cal-day-loc">' + ev.loc + '</div>' : '')
                        + '</div>'
                        + (ev.price ? '<div class="tix-hp-cal-day-price">' + ev.price + '</div>' : '')
                        + '</a>';
                });
                dayPanel.innerHTML = html;
                dayPanel.style.display = 'block';
                // Highlight
                grid.querySelectorAll('.tix-hp-cal-cell.selected').forEach(function(c){ c.classList.remove('selected'); });
                cell.classList.add('selected');
            });
        });
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
    // ═══════════════════════════════════════════
    // Skeleton für Load-More (temporäre Placeholder-Cards am Ende)
    // ═══════════════════════════════════════════
    function appendLoadMoreSkeletons(count){
        if (!grid) return null;
        var wrap = document.createElement('div');
        wrap.className = 'tix-hp-loadmore-skeletons';
        wrap.style.cssText = 'display:contents;';
        var html = '';
        var cnt = Math.max(2, Math.min(count || 4, 8));
        if (currentView === 'list') {
            for (var i = 0; i < cnt; i++) {
                html += '<div class="tix-hp-skeleton" style="display:flex;align-items:center;gap:16px;padding:14px 0;border-bottom:1px solid var(--tix-card-sand,#F0ECE4);">'
                    + '<div style="width:64px;height:64px;border-radius:12px;flex-shrink:0;" class="tix-hp-skeleton-img"></div>'
                    + '<div style="flex:1;">'
                    + '<div class="tix-hp-skeleton-line" style="width:30%;margin-bottom:6px;"></div>'
                    + '<div class="tix-hp-skeleton-line" style="width:70%;height:14px;margin-bottom:6px;"></div>'
                    + '<div class="tix-hp-skeleton-line" style="width:45%;"></div>'
                    + '</div>'
                    + '</div>';
            }
        } else {
            for (var j = 0; j < cnt; j++) {
                html += '<div class="tix-hp-skeleton">'
                    + '<div class="tix-hp-skeleton-img"></div>'
                    + '<div class="tix-hp-skeleton-body">'
                    + '<div class="tix-hp-skeleton-line"></div>'
                    + '<div class="tix-hp-skeleton-line"></div>'
                    + '<div class="tix-hp-skeleton-line"></div>'
                    + '</div>'
                    + '</div>';
            }
        }
        wrap.innerHTML = html;
        grid.appendChild(wrap);
        return wrap;
    }

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function() {
            if (loadMoreBtn.disabled) return;
            loadMoreBtn.disabled = true;
            loadMoreBtn.textContent = 'Laden…';

            var exclude = loadMoreBtn.dataset.exclude || '';
            var excludeCats = loadMoreBtn.dataset.excludeCats || '';
            var offset = parseInt(loadMoreBtn.dataset.offset, 10) || 0;

            // Skeletons am Ende einfügen
            var skelWrap = appendLoadMoreSkeletons(4);

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
                    if (skelWrap) skelWrap.remove();
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
                    if (skelWrap) skelWrap.remove();
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

    // ═══════════════════════════════════════════
    // „Events in deiner Nähe" — Geolocation + AJAX
    // ═══════════════════════════════════════════
    var nearBtn = hp.querySelector('.tix-hp-nearme-btn');
    if (nearBtn && typeof tixCards !== 'undefined') {
        var originalLabel = nearBtn.querySelector('span') ? nearBtn.querySelector('span').textContent : 'In der Nähe';
        var nearActive = false;

        nearBtn.addEventListener('click', function() {
            if (!navigator.geolocation) {
                alert('Dein Browser unterstützt keine Standortabfrage.');
                return;
            }

            // Toggle: zurücksetzen
            if (nearActive) {
                nearActive = false;
                nearBtn.classList.remove('active');
                if (nearBtn.querySelector('span')) nearBtn.querySelector('span').textContent = originalLabel;
                // Zurück auf "Alle"
                var allBtn = hp.querySelector('.tix-hp-time-btn[data-time="all"]');
                if (allBtn) allBtn.click();
                return;
            }

            nearBtn.classList.add('loading');
            if (nearBtn.querySelector('span')) nearBtn.querySelector('span').textContent = 'Finde Standort…';

            navigator.geolocation.getCurrentPosition(function(pos) {
                var data = new FormData();
                data.append('action', 'tix_homepage_near_me');
                data.append('lat', pos.coords.latitude);
                data.append('lng', pos.coords.longitude);
                data.append('max_km', 50);
                data.append('limit', 12);

                if (nearBtn.querySelector('span')) nearBtn.querySelector('span').textContent = 'Lade…';
                if (grid) grid.innerHTML = '<div class="tix-hp-skeleton"></div>'.repeat(4);

                fetch(tixCards.ajaxUrl, { method: 'POST', body: data })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        nearBtn.classList.remove('loading');
                        if (r.success && r.data.html) {
                            if (grid) grid.innerHTML = r.data.html;
                            if (loadMoreWrap) loadMoreWrap.style.display = 'none';
                            nearActive = true;
                            nearBtn.classList.add('active');
                            var label = r.data.city ? 'Nähe: ' + r.data.city + ' ✕' : 'Nähe ✕';
                            if (nearBtn.querySelector('span')) nearBtn.querySelector('span').textContent = label;
                            // Time-Filter neutral setzen
                            timeBtns.forEach(function(b){ b.classList.remove('active'); });
                        } else {
                            if (grid) grid.innerHTML = (r.data && r.data.html) ? r.data.html : '<p class="tix-hp-empty" style="text-align:center;padding:24px;">Keine Events in deiner Nähe gefunden.</p>';
                            if (nearBtn.querySelector('span')) nearBtn.querySelector('span').textContent = originalLabel;
                        }
                    })
                    .catch(function(){
                        nearBtn.classList.remove('loading');
                        if (nearBtn.querySelector('span')) nearBtn.querySelector('span').textContent = originalLabel;
                    });
            }, function(err) {
                nearBtn.classList.remove('loading');
                if (nearBtn.querySelector('span')) nearBtn.querySelector('span').textContent = originalLabel;
                var msg = 'Standort konnte nicht ermittelt werden.';
                if (err.code === 1) msg = 'Standort-Zugriff wurde verweigert. Bitte in den Browser-Einstellungen erlauben.';
                alert(msg);
            }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
        });
    }

    // ═══════════════════════════════════════════
    // Parallax-Effekt auf Hero-Bildern (Transform-Translate bei Scroll)
    // Der Bild-Container ist via CSS 8% höher als die sichtbare Card (top/bottom:-8%),
    // sodass wir bis zu ±8% Verschiebung ohne weißen Rand haben.
    // ═══════════════════════════════════════════
    (function(){
        var parallaxEls = hp.querySelectorAll('.tix-hp-hero-img, .tix-hp-hero-sm-img');
        if (!parallaxEls.length) return;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        var ticking = false;
        function onScroll(){
            if (ticking) return;
            window.requestAnimationFrame(update);
            ticking = true;
        }
        function update(){
            var vh = window.innerHeight;
            parallaxEls.forEach(function(el){
                // Eltern-Card nutzen zur Sichtbarkeits-Erkennung (der img-Container ragt ja über die Card hinaus)
                var card = el.closest('.tix-hp-hero-card, .tix-hp-hero-sm');
                var refRect = card ? card.getBoundingClientRect() : el.getBoundingClientRect();
                if (refRect.bottom < -100 || refRect.top > vh + 100) return;
                // Fortschritt: 0 = Oberkante unten am Viewport, 1 = Unterkante oben am Viewport
                var progress = 1 - (refRect.top + refRect.height) / (vh + refRect.height);
                // Clamp + Auf ±6% der Element-Höhe abbilden
                progress = Math.max(0, Math.min(1, progress));
                var pxOffset = (progress - 0.5) * 2 * (refRect.height * 0.06);
                el.style.transform = 'translate3d(0, ' + pxOffset.toFixed(1) + 'px, 0)';
            });
            ticking = false;
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        update();
    })();

    // ═══════════════════════════════════════════
    // Lazy-Load: "Kürzlich angesehen" und "Favoriten" (aus LocalStorage)
    // ═══════════════════════════════════════════
    function lazyLoadCards(section, ids, onlyUpcoming) {
        if (!section || !ids.length || typeof tixCards === 'undefined') return;
        var grid = section.querySelector('.ev-grid');
        if (!grid) return;

        var wrap = section.closest('.tix-hp-sec-wrap') || section;
        wrap.style.display = '';
        section.style.display = '';

        var data = new FormData();
        data.append('action', 'tix_homepage_cards_by_ids');
        ids.forEach(function(id){ data.append('ids[]', id); });
        data.append('only_upcoming', onlyUpcoming ? '1' : '0');

        fetch(tixCards.ajaxUrl, { method: 'POST', body: data })
            .then(function(r){ return r.json(); })
            .then(function(r){
                if (r.success && r.data.html) {
                    grid.innerHTML = r.data.html;
                } else {
                    // Keine passenden Events → Sektion wieder ausblenden
                    wrap.style.display = 'none';
                }
            })
            .catch(function(){ wrap.style.display = 'none'; });
    }

    // Recent
    var recentSec = document.querySelector('[data-tix-hp-lazy="recent"]');
    if (recentSec) {
        var recentIds = window.tixRecent.get();
        // Aktuelle Seite aus Liste filtern (falls wir auf Event-Detail sind)
        var currentEventId = null;
        var articleEl = document.querySelector('article.type-event[id^="post-"]');
        if (articleEl) {
            var m = articleEl.id.match(/^post-(\d+)$/);
            if (m) currentEventId = parseInt(m[1], 10);
        }
        if (currentEventId) recentIds = recentIds.filter(function(x){ return x !== currentEventId; });

        lazyLoadCards(recentSec, recentIds, true);

        // Clear-Button
        var clearBtn = recentSec.querySelector('.tix-hp-recent-clear');
        if (clearBtn) clearBtn.addEventListener('click', function(){
            window.tixRecent.clear();
            var wrap = recentSec.closest('.tix-hp-sec-wrap') || recentSec;
            wrap.style.display = 'none';
        });
    }

    // Favorites
    var favSec = document.querySelector('[data-tix-hp-lazy="favorites"]');
    if (favSec) {
        lazyLoadCards(favSec, window.tixFavorites.get(), true);
    }

})();
