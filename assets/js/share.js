/* ═══════════════════════════════════════════
   Tixomat – Share-Buttons
   ═══════════════════════════════════════════ */
(function () {
    'use strict';

    function init() {

        /* ── Link kopieren ── */
        document.querySelectorAll('.tix-share-btn--copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-url');
                if (!url) return;

                function onCopied() {
                    btn.classList.add('tix-share-btn--copied');
                    var origTitle = btn.title;
                    btn.title = 'Kopiert!';
                    setTimeout(function () {
                        btn.classList.remove('tix-share-btn--copied');
                        btn.title = origTitle;
                    }, 2000);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(onCopied);
                } else {
                    /* Fallback: textarea copy */
                    var ta = document.createElement('textarea');
                    ta.value = url;
                    ta.style.cssText = 'position:fixed;left:-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    onCopied();
                }
            });
        });

        /* ── Native Share API ── */
        document.querySelectorAll('.tix-share-btn--native').forEach(function (btn) {
            if (navigator.share) {
                btn.hidden = false;
                btn.addEventListener('click', function () {
                    navigator.share({
                        title: btn.getAttribute('data-title') || document.title,
                        url:   btn.getAttribute('data-url') || window.location.href
                    }).catch(function () {
                        /* User cancelled – ignore */
                    });
                });
            }
        });
    }

    /* DOM ready */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
