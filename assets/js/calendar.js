(function() {
    'use strict';
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.tix-cal-btn');
        if (btn) {
            e.preventDefault();
            var cal = btn.closest('.tix-cal');
            cal.classList.toggle('tix-cal-open');
            return;
        }
        // Klick außerhalb → schließen
        if (!e.target.closest('.tix-cal-dropdown')) {
            var open = document.querySelectorAll('.tix-cal-open');
            for (var i = 0; i < open.length; i++) {
                open[i].classList.remove('tix-cal-open');
            }
        }
    });
})();
