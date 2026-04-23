/* Tixomat – Organizer Landing (standalone) */
(function(){
    'use strict';

    // Smooth scroll für interne Anker
    document.querySelectorAll('a[href^="#"]').forEach(function(a){
        a.addEventListener('click', function(e){
            var href = a.getAttribute('href');
            if (href.length > 1 && document.querySelector(href)) {
                e.preventDefault();
                document.querySelector(href).scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // Live-Countdown im Hero
    var cd = document.querySelector('.tix-ol-hero-countdown[data-tix-target]');
    if (cd) {
        var target = new Date(cd.getAttribute('data-tix-target')).getTime();
        var $d = cd.querySelector('[data-d]');
        var $h = cd.querySelector('[data-h]');
        var $m = cd.querySelector('[data-m]');
        var $s = cd.querySelector('[data-s]');
        function tick() {
            var diff = target - Date.now();
            if (diff <= 0) { cd.style.display = 'none'; return; }
            var d = Math.floor(diff / 86400000);
            var h = Math.floor((diff % 86400000) / 3600000);
            var m = Math.floor((diff % 3600000) / 60000);
            var s = Math.floor((diff % 60000) / 1000);
            $d.textContent = d;
            $h.textContent = String(h).padStart(2, '0');
            $m.textContent = String(m).padStart(2, '0');
            $s.textContent = String(s).padStart(2, '0');
        }
        tick();
        setInterval(tick, 1000);
    }

    // View-Toggle (Liste / Kalender)
    document.querySelectorAll('.tix-ol-view-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            var target = btn.dataset.view; // 'list' | 'calendar'
            // Buttons
            document.querySelectorAll('.tix-ol-view-btn').forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            // Panels
            document.querySelectorAll('.tix-ol-view-panel').forEach(function(p){
                var isMatch = p.classList.contains('tix-ol-view-' + target);
                p.classList.toggle('active', isMatch);
                if (isMatch) p.removeAttribute('hidden');
                else         p.setAttribute('hidden', '');
            });
        });
    });

    // Fade-in für Sections beim Scrollen
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(en){
                if (en.isIntersecting) {
                    en.target.style.opacity = 1;
                    en.target.style.transform = 'none';
                    io.unobserve(en.target);
                }
            });
        }, { threshold: 0.08 });

        document.querySelectorAll('.tix-ol-about, .tix-ol-events, .tix-ol-card').forEach(function(el, i){
            el.style.opacity = 0;
            el.style.transform = 'translateY(14px)';
            el.style.transition = 'opacity .5s ease ' + (i * 40) + 'ms, transform .5s ease ' + (i * 40) + 'ms';
            io.observe(el);
        });
    }
})();
