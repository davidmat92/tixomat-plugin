/* ═══════════════════════════════════════════
   Tixomat – Gewinnspiel (Raffle)
   ═══════════════════════════════════════════ */
(function () {
    'use strict';

    /* ── Formular-Submit (AJAX) ── */
    document.querySelectorAll('.tix-raffle-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn   = form.querySelector('.tix-raffle-submit');
            var msg   = form.querySelector('.tix-raffle-msg');
            var name  = form.querySelector('input[name="name"]').value.trim();
            var email = form.querySelector('input[name="email"]').value.trim();
            var eventId = form.dataset.event;
            var nonce   = form.dataset.nonce;

            if (!name || !email) return;

            btn.disabled = true;
            btn.textContent = 'Wird gesendet…';
            if (msg) { msg.hidden = true; msg.className = 'tix-raffle-msg'; msg.textContent = ''; }

            var body = new FormData();
            body.append('action', 'tix_raffle_enter');
            body.append('event_id', eventId);
            body.append('nonce', nonce);
            body.append('name', name);
            body.append('email', email);

            fetch(tixRaffle.ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        // Formular durch Erfolgs-Nachricht ersetzen
                        var wrapper = form.closest('.tix-raffle');
                        var formParent = form.parentNode;

                        // Countdown ausblenden
                        var countdown = wrapper ? wrapper.querySelector('.tix-raffle-countdown') : null;
                        if (countdown) countdown.style.display = 'none';

                        // Formular ersetzen
                        var success = document.createElement('div');
                        success.className = 'tix-raffle-success';
                        success.innerHTML =
                            '<div class="tix-raffle-success-icon">🎉</div>' +
                            '<p class="tix-raffle-success-title">' + escHtml(data.data.message) + '</p>' +
                            '<p class="tix-raffle-success-text">Du wirst per E-Mail benachrichtigt, wenn die Gewinner ausgelost werden.</p>';
                        formParent.replaceChild(success, form);

                        // Zähler aktualisieren
                        if (data.data.count !== undefined && wrapper) {
                            var counter = wrapper.querySelector('.tix-raffle-count');
                            if (counter) {
                                var maxSpan = counter.querySelector('.tix-raffle-max');
                                var maxHtml = maxSpan ? ' ' + maxSpan.outerHTML : '';
                                counter.innerHTML = data.data.count + ' Teilnehmer' + maxHtml;
                            }
                        }

                        // Cookie setzen (30 Tage)
                        document.cookie = 'tix_raffle_' + eventId + '=1;path=/;max-age=' + (30 * 86400) + ';SameSite=Lax';
                    } else {
                        // Fehler anzeigen
                        if (msg) {
                            msg.textContent = data.data.message || 'Ein Fehler ist aufgetreten.';
                            msg.className = 'tix-raffle-msg tix-raffle-msg--error';
                            msg.hidden = false;
                        }
                        btn.disabled = false;
                        btn.textContent = 'Jetzt teilnehmen';
                    }
                })
                .catch(function () {
                    if (msg) {
                        msg.textContent = 'Verbindungsfehler. Bitte versuche es erneut.';
                        msg.className = 'tix-raffle-msg tix-raffle-msg--error';
                        msg.hidden = false;
                    }
                    btn.disabled = false;
                    btn.textContent = 'Jetzt teilnehmen';
                });
        });
    });

    /* ── Countdown-Timer ── */
    document.querySelectorAll('.tix-raffle-countdown').forEach(function (el) {
        var endStr = el.dataset.end;
        if (!endStr) return;

        var endTime = new Date(endStr.replace(' ', 'T')).getTime();
        if (isNaN(endTime)) return;

        var timerEl = el.querySelector('.tix-raffle-timer');
        if (!timerEl) return;

        function update() {
            var now  = Date.now();
            var diff = endTime - now;

            if (diff <= 0) {
                // Abgelaufen
                el.innerHTML = '<strong>Teilnahmeschluss erreicht</strong>';
                // Formular ausblenden
                var raffle = el.closest('.tix-raffle');
                if (raffle) {
                    var form = raffle.querySelector('.tix-raffle-form');
                    if (form) {
                        var closed = document.createElement('div');
                        closed.className = 'tix-raffle-closed';
                        closed.innerHTML = '<p>Die Teilnahme ist beendet. Die Auslosung steht noch aus.</p>';
                        form.parentNode.replaceChild(closed, form);
                    }
                }
                return;
            }

            var days  = Math.floor(diff / 86400000);
            var hours = Math.floor((diff % 86400000) / 3600000);
            var mins  = Math.floor((diff % 3600000) / 60000);
            var secs  = Math.floor((diff % 60000) / 1000);

            var parts = [];
            if (days > 0)  parts.push(days + (days === 1 ? ' Tag' : ' Tage'));
            if (hours > 0) parts.push(hours + ' Std');
            parts.push(mins + ' Min');
            if (days === 0) parts.push(secs + ' Sek');

            timerEl.textContent = parts.join(', ');

            setTimeout(update, 1000);
        }

        update();
    });

    /* ── HTML-Escape Helfer ── */
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
