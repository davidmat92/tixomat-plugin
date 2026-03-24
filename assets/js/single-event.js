/**
 * Tixomat Single Event — Countdown, Scroll-Spy, Calendar, Share
 */
(function() {
    'use strict';
    if (typeof tixSingle === 'undefined') return;

    // ── Countdown ──
    var cdEl = document.getElementById('tse-countdown');
    if (cdEl && tixSingle.eventDate) {
        var target = new Date(tixSingle.eventDate).getTime();
        function updateCountdown() {
            var now = Date.now();
            var diff = Math.max(0, target - now);
            if (diff === 0) {
                cdEl.style.display = 'none';
                return;
            }
            var d = Math.floor(diff / 86400000);
            var h = Math.floor((diff % 86400000) / 3600000);
            var m = Math.floor((diff % 3600000) / 60000);
            var s = Math.floor((diff % 60000) / 1000);
            var el = function(id) { return document.getElementById(id); };
            if (el('tse-cd-days')) el('tse-cd-days').textContent = d;
            if (el('tse-cd-hours')) el('tse-cd-hours').textContent = h;
            if (el('tse-cd-mins')) el('tse-cd-mins').textContent = m;
            if (el('tse-cd-secs')) el('tse-cd-secs').textContent = s;
        }
        updateCountdown();
        setInterval(updateCountdown, 1000);
    }

    // ── Scroll-Spy for Tabs ──
    var tabs = document.querySelectorAll('.tse-tab');
    var sections = [];
    tabs.forEach(function(tab) {
        var id = tab.getAttribute('href');
        if (id && id.startsWith('#')) {
            var sec = document.querySelector(id);
            if (sec) sections.push({ el: sec, tab: tab });
        }
    });

    if (sections.length > 0 && 'IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    tabs.forEach(function(t) { t.classList.remove('active'); });
                    sections.forEach(function(s) {
                        if (s.el === entry.target) s.tab.classList.add('active');
                    });
                }
            });
        }, { rootMargin: '-120px 0px -60% 0px' });

        sections.forEach(function(s) { observer.observe(s.el); });
    }

    // ── Smooth Scroll for Tabs ──
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var target = document.querySelector(tab.getAttribute('href'));
            if (target) {
                var offset = 120;
                window.scrollTo({
                    top: target.offsetTop - offset,
                    behavior: 'smooth'
                });
            }
        });
    });

    // ── Calendar Download (ICS) ──
    var calBtn = document.getElementById('tse-cal-btn');
    if (calBtn) {
        calBtn.addEventListener('click', function() {
            var start = tixSingle.eventDate.replace(/[-: ]/g, function(m) {
                return m === ' ' ? 'T' : '';
            }).replace(/T/g, 'T').substring(0, 15) + '00';
            // Format: YYYYMMDDTHHMMSS
            var dtStart = tixSingle.eventDate.replace(/[- :]/g, '');
            if (dtStart.length === 12) dtStart += '00';
            var dtEnd = tixSingle.eventEnd ? tixSingle.eventEnd.replace(/[- :]/g, '') : dtStart;
            if (dtEnd.length === 12) dtEnd += '00';

            var ics = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//Tixomat//Event//DE',
                'BEGIN:VEVENT',
                'DTSTART:' + dtStart,
                'DTEND:' + dtEnd,
                'SUMMARY:' + tixSingle.eventTitle,
                'LOCATION:' + (tixSingle.eventLocation || ''),
                'URL:' + tixSingle.eventUrl,
                'END:VEVENT',
                'END:VCALENDAR'
            ].join('\r\n');

            var blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'event.ics';
            a.click();
        });
    }

    // ── Share Button ──
    var shareBtn = document.getElementById('tse-share-btn');
    if (shareBtn) {
        shareBtn.addEventListener('click', function() {
            if (navigator.share) {
                navigator.share({ title: tixSingle.eventTitle, url: tixSingle.eventUrl });
            } else {
                window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(tixSingle.eventUrl), '_blank', 'width=600,height=400');
            }
        });
    }

    // ── Copy Link ──
    var copyBtn = document.getElementById('tse-copy-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            navigator.clipboard.writeText(tixSingle.eventUrl).then(function() {
                var orig = copyBtn.innerHTML;
                copyBtn.innerHTML = '<span style="font-size:12px;">Kopiert!</span>';
                setTimeout(function() { copyBtn.innerHTML = orig; }, 1500);
            });
        });
    }
})();
