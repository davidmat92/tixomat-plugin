/* ═══════════════════════════════════════════
   Tixomat – Post-Event Feedback
   ═══════════════════════════════════════════ */
(function () {
    'use strict';

    /* ── Star-Rating interaktiv ── */
    document.querySelectorAll('.tix-fb-stars').forEach(function (wrap) {
        var stars = wrap.querySelectorAll('.tix-fb-star');
        var input = wrap.parentElement.querySelector('input[name="rating"]');
        var submit = wrap.parentElement.querySelector('.tix-fb-submit');

        stars.forEach(function (star) {
            star.addEventListener('mouseenter', function () {
                var val = parseInt(star.dataset.value);
                stars.forEach(function (s) {
                    s.classList.toggle('active', parseInt(s.dataset.value) <= val);
                });
            });

            star.addEventListener('click', function () {
                var val = parseInt(star.dataset.value);
                wrap.dataset.rating = val;
                if (input) input.value = val;
                if (submit) submit.disabled = false;
                stars.forEach(function (s) {
                    s.classList.toggle('active', parseInt(s.dataset.value) <= val);
                });
            });
        });

        wrap.addEventListener('mouseleave', function () {
            var current = parseInt(wrap.dataset.rating) || 0;
            stars.forEach(function (s) {
                s.classList.toggle('active', parseInt(s.dataset.value) <= current);
            });
        });
    });

    /* ── Formular-Submit (AJAX) ── */
    document.querySelectorAll('.tix-fb-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn     = form.querySelector('.tix-fb-submit');
            var msg     = form.querySelector('.tix-fb-msg');
            var rating  = parseInt(form.querySelector('input[name="rating"]').value);
            var comment = form.querySelector('textarea[name="comment"]').value.trim();
            var eventId = form.dataset.event;
            var token   = form.dataset.token;
            var nonce   = form.dataset.nonce;

            if (!rating || rating < 1) return;

            btn.disabled = true;
            btn.textContent = 'Wird gesendet…';
            if (msg) { msg.hidden = true; msg.className = 'tix-fb-msg'; msg.textContent = ''; }

            var body = new FormData();
            body.append('action', 'tix_feedback_submit');
            body.append('event_id', eventId);
            body.append('token', token);
            body.append('nonce', nonce);
            body.append('rating', rating);
            body.append('comment', comment);

            fetch(tixFeedback.ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        // Formular durch Danke-Nachricht ersetzen
                        var parent = form.parentNode;
                        var thanks = document.createElement('div');
                        thanks.className = 'tix-fb-thanks';
                        thanks.innerHTML =
                            '<div class="tix-fb-thanks-icon">🎉</div>' +
                            '<p class="tix-fb-thanks-title">' + data.data.message + '</p>' +
                            '<p class="tix-fb-thanks-text">Du hast ' + rating + ' von 5 Sternen vergeben.</p>';
                        parent.replaceChild(thanks, form);
                    } else {
                        if (msg) {
                            msg.textContent = data.data.message || 'Ein Fehler ist aufgetreten.';
                            msg.className = 'tix-fb-msg tix-fb-msg--error';
                            msg.hidden = false;
                        }
                        btn.disabled = false;
                        btn.textContent = 'Feedback absenden';
                    }
                })
                .catch(function () {
                    if (msg) {
                        msg.textContent = 'Verbindungsfehler. Bitte versuche es erneut.';
                        msg.className = 'tix-fb-msg tix-fb-msg--error';
                        msg.hidden = false;
                    }
                    btn.disabled = false;
                    btn.textContent = 'Feedback absenden';
                });
        });
    });
})();
