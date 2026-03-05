/**
 * Tixomat – Check-In Frontend
 *
 * QR-Scanner + kombinierte Liste (Gäste + Tickets).
 * Unterstützt GL-{EVENT}-{CODE} und 12-stellige alphanumerische Codes.
 *
 * @since 1.27.0
 */
(function() {
    'use strict';

    if (typeof ehCheckin === 'undefined') return;

    var state = {
        eventId: 0,
        password: '',
        authenticated: false,
        scanning: false,
        lastScanned: '',
        lastScanTime: 0,
        pollTimer: null,
        video: null,
        canvas: null,
        ctx: null,
        filter: 'all' // all | guest | ticket
    };

    // ── DOM References ──
    var $app        = document.getElementById('tix-checkin-app');
    var $eventSel   = document.getElementById('tix-ci-event');
    var $auth       = document.getElementById('tix-ci-auth');
    var $pwInput    = document.getElementById('tix-ci-password');
    var $pwSubmit   = document.getElementById('tix-ci-pw-submit');
    var $pwError    = document.getElementById('tix-ci-pw-error');
    var $scanner    = document.getElementById('tix-ci-scanner');
    var $video      = document.getElementById('tix-ci-video');
    var $canvas     = document.getElementById('tix-ci-canvas');
    var $codeInput  = document.getElementById('tix-ci-code');
    var $codeSubmit = document.getElementById('tix-ci-code-submit');
    var $result     = document.getElementById('tix-ci-result');
    var $listTitle  = document.getElementById('tix-ci-list-title');
    var $search     = document.getElementById('tix-ci-search');
    var $list       = document.getElementById('tix-ci-list');
    var $filters    = document.getElementById('tix-ci-filters');

    if (!$app) return;

    // ══════════════════════════════════════════════
    // EVENT AUSWAHL
    // ══════════════════════════════════════════════

    $eventSel.addEventListener('change', function() {
        state.eventId = parseInt(this.value) || 0;
        state.authenticated = false;
        stopScanner();
        $scanner.style.display = 'none';
        $result.style.display = 'none';
        $list.innerHTML = '';
        if ($filters) $filters.style.display = 'none';

        if (state.eventId) {
            var savedPw = sessionStorage.getItem('tix_ci_pw_' + state.eventId);
            if (savedPw) {
                state.password = savedPw;
                authenticate();
            } else {
                $auth.style.display = '';
                $pwInput.value = '';
                $pwInput.focus();
            }
        } else {
            $auth.style.display = 'none';
        }
    });

    // Auto-select wenn nur ein Event
    if ($eventSel.options.length === 2) {
        $eventSel.selectedIndex = 1;
        $eventSel.dispatchEvent(new Event('change'));
    }

    // ══════════════════════════════════════════════
    // PASSWORT
    // ══════════════════════════════════════════════

    $pwSubmit.addEventListener('click', function() {
        state.password = $pwInput.value.trim();
        if (!state.password) return;
        authenticate();
    });

    $pwInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); $pwSubmit.click(); }
    });

    function authenticate() {
        // Kombinierte Liste laden um Passwort zu prüfen
        ajax('tix_checkin_combined_list', {
            event_id: state.eventId,
            password: state.password
        }, function(res) {
            if (res.success) {
                state.authenticated = true;
                sessionStorage.setItem('tix_ci_pw_' + state.eventId, state.password);
                $auth.style.display = 'none';
                $pwError.style.display = 'none';
                $scanner.style.display = '';
                startScanner();
                renderCombinedList(res.data);
                startPolling();
            } else {
                $pwError.textContent = res.data && res.data.message ? res.data.message : 'Fehler';
                $pwError.style.display = '';
                state.authenticated = false;
            }
        });
    }

    // ══════════════════════════════════════════════
    // SCANNER
    // ══════════════════════════════════════════════

    function startScanner() {
        if (state.scanning) return;
        state.canvas = $canvas;
        state.ctx = $canvas.getContext('2d', { willReadFrequently: true });

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } }
        }).then(function(stream) {
            state.video = $video;
            $video.srcObject = stream;
            $video.play();
            state.scanning = true;
            requestAnimationFrame(scanFrame);
        }).catch(function() { /* Kamera nicht verfügbar */ });
    }

    function stopScanner() {
        state.scanning = false;
        if (state.video && state.video.srcObject) {
            state.video.srcObject.getTracks().forEach(function(t) { t.stop(); });
            state.video.srcObject = null;
        }
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    function scanFrame() {
        if (!state.scanning) return;
        if ($video.readyState === $video.HAVE_ENOUGH_DATA) {
            $canvas.width = $video.videoWidth;
            $canvas.height = $video.videoHeight;
            state.ctx.drawImage($video, 0, 0, $canvas.width, $canvas.height);
            var imageData = state.ctx.getImageData(0, 0, $canvas.width, $canvas.height);

            if (typeof jsQR !== 'undefined') {
                var qr = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
                if (qr && qr.data) {
                    var code = qr.data.toUpperCase();
                    var now = Date.now();
                    if (code !== state.lastScanned || now - state.lastScanTime > 3000) {
                        state.lastScanned = code;
                        state.lastScanTime = now;
                        processCode(code);
                    }
                }
            }
        }
        requestAnimationFrame(scanFrame);
    }

    // ══════════════════════════════════════════════
    // MANUELLER CODE
    // ══════════════════════════════════════════════

    $codeSubmit.addEventListener('click', function() {
        var code = $codeInput.value.trim().toUpperCase();
        if (code) processCode(code);
    });

    $codeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); $codeSubmit.click(); }
    });

    // ══════════════════════════════════════════════
    // CODE VERARBEITEN (GL-* oder TIX-*)
    // ══════════════════════════════════════════════

    function processCode(code) {
        // Beide Formate gehen an den gleichen universalen Validator
        ajax('tix_guest_validate', {
            code: code,
            password: state.password
        }, function(res) {
            showResult(res);
            refreshList();
        });
    }

    // ══════════════════════════════════════════════
    // ERGEBNIS ANZEIGEN
    // ══════════════════════════════════════════════

    function showResult(res) {
        $result.style.display = '';
        var data = res.data || {};
        var status = data.status || (res.success ? 'ok' : 'error');
        var cls, icon, title, details;

        switch (status) {
            case 'ok':
                cls = 'tix-ci-result-ok';
                icon = '✓';
                title = data.name || 'Eingecheckt';
                details = [];
                if (data.type === 'ticket') {
                    if (data.cat) details.push(data.cat);
                    if (data.seat) details.push('Platz ' + data.seat);
                } else {
                    if (data.total_expected > 1) details.push(data.checked_in_count + '/' + data.total_expected + ' eingecheckt');
                    if (data.plus > 0) details.push('+' + data.plus + ' Begleitung');
                    if (data.note) details.push(data.note);
                }
                details.push(data.message || 'Willkommen!');
                playBeep(800, 150);
                break;

            case 'partial':
                cls = 'tix-ci-result-warn';
                icon = '⚠';
                title = data.name || 'Teilweise eingecheckt';
                details = [data.checked_in_count + '/' + data.total_expected + ' eingecheckt'];
                if (data.note) details.push(data.note);
                details.push(data.message || 'Teilweise eingecheckt.');
                playBeep(600, 120); setTimeout(function() { playBeep(600, 120); }, 180);
                break;

            case 'already':
                cls = 'tix-ci-result-warn';
                icon = '⚠';
                title = data.name || 'Bereits eingecheckt';
                details = [];
                if (data.total_expected > 1) details.push(data.checked_in_count + '/' + data.total_expected + ' eingecheckt');
                details.push(data.message || 'Bereits eingecheckt.');
                if (data.time) {
                    var t = new Date(data.time);
                    details.push('Um ' + t.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }));
                }
                playBeep(400, 100); setTimeout(function() { playBeep(400, 100); }, 200);
                break;

            case 'cancelled':
                cls = 'tix-ci-result-err';
                icon = '✕';
                title = (data.name || 'Ticket') + ' — STORNIERT';
                details = [data.message || 'Ticket wurde storniert.'];
                playBeep(200, 300);
                break;

            case 'not_found':
                cls = 'tix-ci-result-err';
                icon = '✕';
                title = 'Nicht gefunden';
                details = [data.message || 'Code nicht gefunden.'];
                playBeep(200, 300);
                break;

            default:
                cls = 'tix-ci-result-err';
                icon = '✕';
                title = 'Fehler';
                details = [data.message || 'Unbekannter Fehler.'];
                playBeep(200, 300);
        }

        $result.className = 'tix-ci-result ' + cls;
        $result.innerHTML =
            '<div class="tix-ci-result-icon">' + icon + '</div>' +
            '<div class="tix-ci-result-title">' + escHtml(title) + '</div>' +
            '<div class="tix-ci-result-details">' + details.map(escHtml).join('<br>') + '</div>';

        // Auto-hide nach konfigurierbarer Dauer
        clearTimeout($result._timer);
        $result._timer = setTimeout(function() {
            $result.style.display = 'none';
        }, ehCheckin.popupDuration || 5000);
    }

    // ══════════════════════════════════════════════
    // KOMBINIERTE LISTE (Gäste + Tickets)
    // ══════════════════════════════════════════════

    function renderCombinedList(data) {
        var total   = data.total || 0;
        var checked = data.checked_in || 0;
        var gCount  = data.guests_count || 0;
        var tCount  = data.tickets_count || 0;
        var partial = data.partial || 0;

        var titleText = 'Check-in (' + checked + '/' + total + ')';
        if (partial > 0) titleText += ' · ' + partial + ' teilweise';
        $listTitle.textContent = titleText;

        // Filter-Buttons anzeigen wenn es beides gibt
        if ($filters && (gCount > 0 && tCount > 0)) {
            $filters.style.display = '';
            var btns = $filters.querySelectorAll('.tix-ci-filter-btn');
            for (var f = 0; f < btns.length; f++) {
                var fType = btns[f].getAttribute('data-filter');
                btns[f].classList.toggle('active', fType === state.filter);
                // Zähler aktualisieren
                if (fType === 'all') btns[f].textContent = 'Alle (' + total + ')';
                if (fType === 'guest') btns[f].textContent = 'Gäste (' + gCount + ')';
                if (fType === 'ticket') btns[f].textContent = 'Tickets (' + tCount + ')';
            }
        } else if ($filters) {
            $filters.style.display = 'none';
        }

        var items = data.items || data.guests || [];
        var html = '';

        // Sortieren: Offene zuerst, dann teilweise, dann vollständig
        items.sort(function(a, b) {
            var aState = !a.checked_in ? 0 : ((a.checked_in_count || 0) < (a.total_expected || 1) ? 1 : 2);
            var bState = !b.checked_in ? 0 : ((b.checked_in_count || 0) < (b.total_expected || 1) ? 1 : 2);
            if (aState !== bState) return aState - bState;
            return (a.name || '').localeCompare(b.name || '');
        });

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var type = item.type || 'guest';
            var totalExpected = item.total_expected || 1;
            var checkedCount  = item.checked_in_count || 0;
            var isPartial     = checkedCount > 0 && checkedCount < totalExpected;
            var isFull        = item.checked_in && checkedCount >= totalExpected;
            var time          = '';

            if (item.checked_in && item.checkin_time) {
                var tt = new Date(item.checkin_time);
                time = tt.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            }

            // Filter anwenden
            var filterHidden = (state.filter !== 'all' && state.filter !== type) ? ' style="display:none;"' : '';

            var rowCls = 'tix-ci-guest tix-ci-type-' + type;
            if (isFull) rowCls += ' tix-ci-guest-checked';
            else if (isPartial) rowCls += ' tix-ci-guest-partial';

            html += '<div class="' + rowCls + '" data-name="' + escAttr((item.name || '').toLowerCase()) + '" data-type="' + type + '"' + filterHidden + '>';

            // Info-Bereich
            html += '<div class="tix-ci-guest-info">';
            // Typ-Badge
            if (type === 'ticket') {
                html += '<span class="tix-ci-badge tix-ci-badge-ticket">Ticket</span>';
            } else {
                html += '<span class="tix-ci-badge tix-ci-badge-guest">Gast</span>';
            }
            html += '<span class="tix-ci-guest-name">' + escHtml(item.name || '') + '</span>';

            if (type === 'guest') {
                if (item.plus > 0) html += '<span class="tix-ci-guest-plus">+' + item.plus + '</span>';
                if (item.note) html += '<span class="tix-ci-guest-note">' + escHtml(item.note) + '</span>';
            } else {
                if (item.cat) html += '<span class="tix-ci-guest-note">' + escHtml(item.cat) + '</span>';
                if (item.seat) html += '<span class="tix-ci-guest-note">Platz ' + escHtml(item.seat) + '</span>';
            }
            html += '</div>';

            // Aktions-Bereich
            if (type === 'guest') {
                // Gast: wie bisher mit Counter-System
                if (isFull) {
                    html += '<div class="tix-ci-guest-right">';
                    html += '<span class="tix-ci-guest-status tix-ci-guest-status-ok">\u2713 ' + checkedCount + '/' + totalExpected + ' \u00b7 ' + time + '</span>';
                    html += '<button type="button" class="tix-ci-guest-edit" data-guest="' + escAttr(item.id) + '" data-total="' + totalExpected + '" data-count="' + checkedCount + '" title="Bearbeiten">\u270e</button>';
                    html += '</div>';
                } else if (isPartial) {
                    html += '<div class="tix-ci-guest-counter">';
                    html += '<button type="button" class="tix-ci-counter-btn tix-ci-counter-dec" data-guest="' + escAttr(item.id) + '" data-total="' + totalExpected + '" data-count="' + checkedCount + '">\u2212</button>';
                    html += '<span class="tix-ci-counter-val">' + checkedCount + '/' + totalExpected + '</span>';
                    html += '<button type="button" class="tix-ci-counter-btn tix-ci-counter-inc" data-guest="' + escAttr(item.id) + '" data-total="' + totalExpected + '" data-count="' + checkedCount + '">+</button>';
                    html += '</div>';
                } else {
                    html += '<button type="button" class="tix-ci-guest-checkin" data-guest="' + escAttr(item.id) + '">Check-in</button>';
                }
            } else {
                // Ticket: einfacher Toggle
                if (isFull) {
                    html += '<div class="tix-ci-guest-right">';
                    html += '<span class="tix-ci-guest-status tix-ci-guest-status-ok">\u2713 ' + time + '</span>';
                    html += '<button type="button" class="tix-ci-ticket-toggle" data-ticket="' + item.id + '" title="Zur\u00fccksetzen">\u21a9</button>';
                    html += '</div>';
                } else {
                    html += '<button type="button" class="tix-ci-ticket-checkin" data-ticket="' + item.id + '">Check-in</button>';
                }
            }

            html += '</div>';
        }

        $list.innerHTML = html || '<div class="tix-ci-empty">Keine Eintr\u00e4ge.</div>';
    }

    // ── Filter ──
    if ($filters) {
        $filters.addEventListener('click', function(e) {
            var btn = e.target.closest('.tix-ci-filter-btn');
            if (!btn) return;
            state.filter = btn.getAttribute('data-filter') || 'all';

            // Aktiven Button markieren
            var btns = $filters.querySelectorAll('.tix-ci-filter-btn');
            for (var i = 0; i < btns.length; i++) {
                btns[i].classList.toggle('active', btns[i] === btn);
            }

            // Items ein/ausblenden
            var rows = $list.querySelectorAll('.tix-ci-guest');
            for (var j = 0; j < rows.length; j++) {
                var rowType = rows[j].getAttribute('data-type');
                rows[j].style.display = (state.filter === 'all' || state.filter === rowType) ? '' : 'none';
            }
        });
    }

    // ── Suche ──
    $search.addEventListener('input', function() {
        var query = this.value.toLowerCase().trim();
        var items = $list.querySelectorAll('.tix-ci-guest');
        for (var i = 0; i < items.length; i++) {
            var name = items[i].getAttribute('data-name') || '';
            var type = items[i].getAttribute('data-type') || 'guest';
            var filterMatch = (state.filter === 'all' || state.filter === type);
            var searchMatch = (!query || name.indexOf(query) > -1);
            items[i].style.display = (filterMatch && searchMatch) ? '' : 'none';
        }
    });

    // ══════════════════════════════════════════════
    // KLICK-HANDLER (Gäste + Tickets)
    // ══════════════════════════════════════════════

    $list.addEventListener('click', function(e) {
        // Gast Check-in Button
        var guestBtn = e.target.closest('.tix-ci-guest-checkin');
        if (guestBtn) {
            var guestId = guestBtn.getAttribute('data-guest');
            guestBtn.disabled = true;
            guestBtn.textContent = '\u2026';
            ajax('tix_guest_validate', {
                code: 'GL-' + state.eventId + '-' + guestId,
                password: state.password
            }, function(res) {
                showResult(res);
                refreshList();
            });
            return;
        }

        // Ticket Check-in Button
        var ticketBtn = e.target.closest('.tix-ci-ticket-checkin');
        if (ticketBtn) {
            var ticketId = ticketBtn.getAttribute('data-ticket');
            ticketBtn.disabled = true;
            ticketBtn.textContent = '\u2026';
            ajax('tix_ticket_toggle_checkin', {
                ticket_id: ticketId,
                password: state.password
            }, function(res) {
                showResult({ success: res.success, data: Object.assign({ status: res.success ? 'ok' : 'error', type: 'ticket' }, res.data || {}) });
                refreshList();
            });
            return;
        }

        // Ticket Toggle (Zurücksetzen)
        var toggleBtn = e.target.closest('.tix-ci-ticket-toggle');
        if (toggleBtn) {
            var tId = toggleBtn.getAttribute('data-ticket');
            toggleBtn.disabled = true;
            ajax('tix_ticket_toggle_checkin', {
                ticket_id: tId,
                password: state.password
            }, function(res) {
                refreshList();
            });
            return;
        }

        // Gast Counter −/+ Buttons
        var counterBtn = e.target.closest('.tix-ci-counter-btn');
        if (counterBtn) {
            var gId    = counterBtn.getAttribute('data-guest');
            var total  = parseInt(counterBtn.getAttribute('data-total')) || 1;
            var count  = parseInt(counterBtn.getAttribute('data-count')) || 0;
            var isDec  = counterBtn.classList.contains('tix-ci-counter-dec');
            var newCount = isDec ? Math.max(0, count - 1) : Math.min(total, count + 1);
            if (newCount === count) return;
            counterBtn.disabled = true;
            updateCheckinCount(gId, newCount);
            return;
        }

        // Gast Bearbeiten-Button
        var editBtn = e.target.closest('.tix-ci-guest-edit');
        if (editBtn) {
            var eGuestId = editBtn.getAttribute('data-guest');
            var eTotal   = parseInt(editBtn.getAttribute('data-total')) || 1;
            var eCount   = parseInt(editBtn.getAttribute('data-count')) || 0;
            var rightDiv = editBtn.closest('.tix-ci-guest-right');
            if (rightDiv) {
                rightDiv.outerHTML =
                    '<div class="tix-ci-guest-counter">' +
                    '<button type="button" class="tix-ci-counter-btn tix-ci-counter-dec" data-guest="' + escAttr(eGuestId) + '" data-total="' + eTotal + '" data-count="' + eCount + '">\u2212</button>' +
                    '<span class="tix-ci-counter-val">' + eCount + '/' + eTotal + '</span>' +
                    '<button type="button" class="tix-ci-counter-btn tix-ci-counter-inc" data-guest="' + escAttr(eGuestId) + '" data-total="' + eTotal + '" data-count="' + eCount + '">+</button>' +
                    '</div>';
            }
            return;
        }
    });

    function updateCheckinCount(guestId, newCount) {
        ajax('tix_guest_update_checkin', {
            event_id: state.eventId,
            guest_id: guestId,
            count: newCount,
            password: state.password
        }, function(res) {
            if (res.success) {
                // DOM direkt aktualisieren (verhindert Counter-Verlust)
                var rows = $list.querySelectorAll('.tix-ci-guest');
                for (var i = 0; i < rows.length; i++) {
                    var btns = rows[i].querySelectorAll('.tix-ci-counter-btn');
                    if (btns.length && btns[0].getAttribute('data-guest') === guestId) {
                        var total = parseInt(btns[0].getAttribute('data-total')) || 1;
                        for (var b = 0; b < btns.length; b++) {
                            btns[b].setAttribute('data-count', newCount);
                            btns[b].disabled = false;
                        }
                        var valEl = rows[i].querySelector('.tix-ci-counter-val');
                        if (valEl) valEl.textContent = newCount + '/' + total;
                        break;
                    }
                }
            } else {
                refreshList();
            }
        });
    }

    function refreshList() {
        ajax('tix_checkin_combined_list', {
            event_id: state.eventId,
            password: state.password
        }, function(res) {
            if (res.success) renderCombinedList(res.data);
        });
    }

    function startPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(function() {
            if (!state.authenticated) return;
            refreshList();
        }, 10000);
    }

    // ══════════════════════════════════════════════
    // AUDIO FEEDBACK
    // ══════════════════════════════════════════════

    function playBeep(freq, duration) {
        try {
            var ac = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ac.createOscillator();
            var gain = ac.createGain();
            osc.connect(gain);
            gain.connect(ac.destination);
            osc.frequency.value = freq;
            gain.gain.value = 0.1;
            osc.start();
            osc.stop(ac.currentTime + duration / 1000);
        } catch (e) { /* Audio nicht verfügbar */ }
    }

    // ══════════════════════════════════════════════
    // AJAX HELPER
    // ══════════════════════════════════════════════

    function ajax(action, data, callback) {
        data.action = action;
        data.nonce = ehCheckin.nonce;

        var body = new URLSearchParams();
        for (var key in data) {
            if (data.hasOwnProperty(key)) body.append(key, data[key]);
        }

        fetch(ehCheckin.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(r) { return r.json(); })
        .then(callback)
        .catch(function() {
            callback({ success: false, data: { message: 'Netzwerkfehler.', status: 'error' } });
        });
    }

    // ══════════════════════════════════════════════
    // UTILITIES
    // ══════════════════════════════════════════════

    function escHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

})();
