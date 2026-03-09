/**
 * Tixomat Event Page – Lightbox Gallery
 * Vanilla JS, kein jQuery
 */
(function () {
    'use strict';

    var wrap, img, counter, items, idx, len;

    /* ── Lightbox öffnen ── */
    function open(i) {
        if (!wrap) build();
        idx = i;
        show();
        wrap.classList.add('tix-ep-lightbox--open');
        document.body.style.overflow = 'hidden';
    }

    /* ── Lightbox schließen ── */
    function close() {
        if (!wrap) return;
        wrap.classList.remove('tix-ep-lightbox--open');
        document.body.style.overflow = '';
    }

    /* ── Bild anzeigen ── */
    function show() {
        var src = items[idx].getAttribute('data-full') || items[idx].querySelector('img').src;
        img.src = src;
        counter.textContent = (idx + 1) + ' / ' + len;
    }

    /* ── Navigation ── */
    function prev() { idx = (idx - 1 + len) % len; show(); }
    function next() { idx = (idx + 1) % len; show(); }

    /* ── Lightbox DOM erstellen ── */
    function build() {
        wrap = document.createElement('div');
        wrap.className = 'tix-ep-lightbox';
        wrap.innerHTML =
            '<button class="tix-ep-lightbox-close" aria-label="Schlie\u00dfen">\u00d7</button>' +
            '<button class="tix-ep-lightbox-nav tix-ep-lightbox-prev" aria-label="Zur\u00fcck">\u2039</button>' +
            '<img src="" alt="">' +
            '<button class="tix-ep-lightbox-nav tix-ep-lightbox-next" aria-label="Weiter">\u203a</button>' +
            '<span class="tix-ep-lightbox-counter"></span>';
        document.body.appendChild(wrap);

        img = wrap.querySelector('img');
        counter = wrap.querySelector('.tix-ep-lightbox-counter');

        wrap.querySelector('.tix-ep-lightbox-close').addEventListener('click', close);
        wrap.querySelector('.tix-ep-lightbox-prev').addEventListener('click', prev);
        wrap.querySelector('.tix-ep-lightbox-next').addEventListener('click', next);

        /* Klick auf Hintergrund schließt */
        wrap.addEventListener('click', function (e) {
            if (e.target === wrap) close();
        });

        /* Tastatur-Navigation */
        document.addEventListener('keydown', function (e) {
            if (!wrap.classList.contains('tix-ep-lightbox--open')) return;
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowLeft') prev();
            if (e.key === 'ArrowRight') next();
        });

        /* Touch-Swipe */
        var startX = 0;
        wrap.addEventListener('touchstart', function (e) {
            startX = e.changedTouches[0].clientX;
        }, { passive: true });
        wrap.addEventListener('touchend', function (e) {
            var diff = e.changedTouches[0].clientX - startX;
            if (Math.abs(diff) > 50) {
                if (diff > 0) prev(); else next();
            }
        }, { passive: true });
    }

    /* ── Init: Galerie-Items binden ── */
    function init() {
        var grid = document.querySelector('.tix-ep-gallery-grid');
        if (grid) {
            items = grid.querySelectorAll('.tix-ep-gallery-item');
            len = items.length;
            if (len > 0) {
                items.forEach(function (el, i) {
                    el.addEventListener('click', function () { open(i); });
                });
            }
        }

        /* ── Share: Link kopieren ── */
        document.querySelectorAll('.tix-ep-share-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-url');
                if (!url) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(function () {
                        btn.classList.add('tix-ep-share-copied');
                        btn.title = 'Kopiert!';
                        setTimeout(function () {
                            btn.classList.remove('tix-ep-share-copied');
                            btn.title = 'Link kopieren';
                        }, 2000);
                    });
                } else {
                    /* Fallback: textarea copy */
                    var ta = document.createElement('textarea');
                    ta.value = url;
                    ta.style.cssText = 'position:fixed;left:-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    btn.classList.add('tix-ep-share-copied');
                    btn.title = 'Kopiert!';
                    setTimeout(function () {
                        btn.classList.remove('tix-ep-share-copied');
                        btn.title = 'Link kopieren';
                    }, 2000);
                }
            });
        });
    }

    /* DOM ready */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
