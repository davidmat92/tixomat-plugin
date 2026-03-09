/* ═══════════════════════════════════════════
   Tixomat – Timetable (Multi-Stage)
   ═══════════════════════════════════════════ */
(function () {
    'use strict';

    /* ── Tages-Tab-Switching ── */
    document.querySelectorAll('.tix-tt-days').forEach(function (nav) {
        var tt = nav.closest('.tix-tt');
        nav.querySelectorAll('.tix-tt-day').forEach(function (btn) {
            btn.addEventListener('click', function () {
                // Tabs
                nav.querySelectorAll('.tix-tt-day').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                // Content
                var day = btn.dataset.day;
                tt.querySelectorAll('.tix-tt-content').forEach(function (c) {
                    c.classList.toggle('active', c.dataset.day === day);
                });
            });
        });
    });

    /* ── Bühnen-Filter (Mobile) ── */
    document.querySelectorAll('.tix-tt-stage-filter').forEach(function (filter) {
        var tt = filter.closest('.tix-tt');
        filter.querySelectorAll('.tix-tt-filter-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                // Buttons
                filter.querySelectorAll('.tix-tt-filter-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                var stage = btn.dataset.stage;
                // Filter list items in active day
                var activeContent = tt.querySelector('.tix-tt-content.active');
                if (!activeContent) return;

                activeContent.querySelectorAll('.tix-tt-list-item').forEach(function (item) {
                    if (stage === 'all') {
                        item.hidden = false;
                    } else {
                        item.hidden = item.dataset.stage !== stage;
                    }
                });
            });
        });
    });
})();
