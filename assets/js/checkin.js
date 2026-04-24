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
        filter: 'all', // all | guest | ticket
        soundEnabled: localStorage.getItem('tix_ci_sound') !== '0',
        offlineQueue: JSON.parse(localStorage.getItem('tix_ci_offline_queue') || '[]'),
        isOnline: navigator.onLine
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
    // DYNAMISCHE UI-ELEMENTE
    // ══════════════════════════════════════════════

    // Counter + Toggle
    var $counter = document.createElement('span');
    $counter.className = 'tix-ci-counter';
    $counter.style.display = 'none';
    var $counterToggle = document.createElement('button');
    $counterToggle.type = 'button';
    $counterToggle.className = 'tix-ci-counter-toggle';
    $counterToggle.title = 'Z\u00e4hler ein-/ausblenden';
    $counterToggle.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';

    // Sound-Toggle
    var $soundToggle = document.createElement('button');
    $soundToggle.type = 'button';
    $soundToggle.className = 'tix-ci-counter-toggle' + (state.soundEnabled ? ' active' : '');
    $soundToggle.title = 'Sound ein-/ausschalten';
    $soundToggle.innerHTML = state.soundEnabled
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';

    if ($listTitle && $listTitle.parentNode) {
        $listTitle.parentNode.insertBefore($counter, $listTitle.nextSibling);
        $listTitle.parentNode.insertBefore($counterToggle, $counter.nextSibling);
        $listTitle.parentNode.insertBefore($soundToggle, $counterToggle.nextSibling);
    }

    var statsVisible = false;
    $counterToggle.addEventListener('click', function() {
        statsVisible = !statsVisible;
        $counter.style.display = statsVisible ? 'inline' : 'none';
        $progress.style.display = statsVisible ? '' : 'none';
        $counterToggle.classList.toggle('active', statsVisible);
    });

    $soundToggle.addEventListener('click', function() {
        state.soundEnabled = !state.soundEnabled;
        localStorage.setItem('tix_ci_sound', state.soundEnabled ? '1' : '0');
        $soundToggle.classList.toggle('active', state.soundEnabled);
        $soundToggle.innerHTML = state.soundEnabled
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
    });

    // Fortschrittsbalken (wird vor der Liste eingefügt)
    var $progress = document.createElement('div');
    $progress.className = 'tix-ci-progress';
    $progress.style.display = 'none';
    $progress.innerHTML = '<div class="tix-ci-progress-bar"><div class="tix-ci-progress-fill"></div></div><span class="tix-ci-progress-text"></span>';
    var $listSection = $list ? $list.parentNode : null;
    if ($listSection && $filters) {
        $listSection.insertBefore($progress, $filters.nextSibling);
    } else if ($listSection && $list) {
        $listSection.insertBefore($progress, $list);
    }

    // Offline-Banner
    var $offlineBanner = document.createElement('div');
    $offlineBanner.className = 'tix-ci-offline-banner';
    $offlineBanner.style.display = 'none';
    $offlineBanner.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.56 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg> <span>Offline \u2013 Check-ins werden gespeichert</span>';
    if ($app.firstChild) {
        $app.insertBefore($offlineBanner, $app.firstChild);
    }

    // Alphabet-Index (wird rechts neben der Liste positioniert)
    var $alphaIndex = document.createElement('div');
    $alphaIndex.className = 'tix-ci-alpha-index';
    $alphaIndex.style.display = 'none';
    var letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ#'.split('');
    for (var li = 0; li < letters.length; li++) {
        var letterBtn = document.createElement('button');
        letterBtn.type = 'button';
        letterBtn.className = 'tix-ci-alpha-letter';
        letterBtn.textContent = letters[li];
        letterBtn.setAttribute('data-letter', letters[li].toLowerCase());
        $alphaIndex.appendChild(letterBtn);
    }
    if ($list && $list.parentNode) {
        // Wrap list + index in a container
        var $listWrap = document.createElement('div');
        $listWrap.className = 'tix-ci-list-wrap';
        $list.parentNode.insertBefore($listWrap, $list);
        $listWrap.appendChild($list);
        $listWrap.appendChild($alphaIndex);
    }

    // Alphabet-Index Click
    $alphaIndex.addEventListener('click', function(e) {
        var btn = e.target.closest('.tix-ci-alpha-letter');
        if (!btn) return;
        var letter = btn.getAttribute('data-letter');
        var rows = $list.querySelectorAll('.tix-ci-guest');
        for (var i = 0; i < rows.length; i++) {
            var name = rows[i].getAttribute('data-name') || '';
            var firstChar = name.charAt(0);
            if (letter === '#' ? !/[a-z]/.test(firstChar) : firstChar === letter) {
                if (rows[i].style.display !== 'none') {
                    rows[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    rows[i].classList.add('tix-ci-guest-highlight');
                    setTimeout(function(el) { el.classList.remove('tix-ci-guest-highlight'); }, 1500, rows[i]);
                    return;
                }
            }
        }
    });

    // ══════════════════════════════════════════════
    // OFFLINE-HANDLING
    // ══════════════════════════════════════════════

    window.addEventListener('online', function() {
        state.isOnline = true;
        $offlineBanner.style.display = 'none';
        flushOfflineQueue();
    });

    window.addEventListener('offline', function() {
        state.isOnline = false;
        if (state.authenticated) {
            $offlineBanner.style.display = '';
        }
    });

    function addToOfflineQueue(code) {
        var entry = { code: code, time: Date.now(), eventId: state.eventId, password: state.password };
        state.offlineQueue.push(entry);
        localStorage.setItem('tix_ci_offline_queue', JSON.stringify(state.offlineQueue));
    }

    function flushOfflineQueue() {
        if (!state.offlineQueue.length) return;
        var queue = state.offlineQueue.slice();
        state.offlineQueue = [];
        localStorage.setItem('tix_ci_offline_queue', '[]');

        var synced = 0;
        var total = queue.length;

        function processNext() {
            if (!queue.length) {
                if (synced > 0) {
                    showResult({
                        success: true,
                        data: { status: 'ok', name: synced + ' Offline-Check-ins', message: 'Erfolgreich synchronisiert.' }
                    });
                    refreshList();
                }
                return;
            }
            var entry = queue.shift();
            ajax('tix_guest_validate', {
                code: entry.code,
                password: entry.password
            }, function(res) {
                if (res.success) synced++;
                processNext();
            });
        }
        processNext();
    }

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
        $progress.style.display = 'none';
        $alphaIndex.style.display = 'none';
        if ($filters) $filters.style.display = 'none';

        if (state.eventId) {
            var savedPw = sessionStorage.getItem('tix_ci_pw_' + state.eventId);
            if (savedPw) {
                state.password = savedPw;
                authenticate();
            } else {
                // Erst mit leerem Passwort probieren — falls Event kein PW hat,
                // startet Scanner sofort. Bei falschem PW zeigen wir die Auth-Form.
                state.password = '';
                authenticateWithFallback();
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
        // Leeres Passwort bewusst erlaubt — Server entscheidet
        authenticate();
    });

    /**
     * Wie authenticate(), aber bei "unauthorized" die Auth-Form zeigen
     * statt stumm zu sein. Wird beim Event-Select verwendet.
     */
    function authenticateWithFallback() {
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
                if (state.isOnline) flushOfflineQueue();
                if (!state.isOnline) $offlineBanner.style.display = '';
            } else {
                // Passwort nötig → Auth-Form anzeigen
                $auth.style.display = '';
                $pwInput.value = '';
                $pwInput.focus();
            }
        });
    }

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
                // Offline-Queue flushen
                if (state.isOnline) flushOfflineQueue();
                // Offline-Banner anzeigen falls offline
                if (!state.isOnline) $offlineBanner.style.display = '';
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

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.warn('[tix-checkin] getUserMedia nicht verfügbar');
            return;
        }

        console.info('[tix-checkin] Starte Scanner…');
        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        }).then(function(stream) {
            state.video = $video;
            $video.srcObject = stream;
            var playPromise = $video.play();
            if (playPromise && playPromise.catch) playPromise.catch(function(e){ console.warn('[tix-checkin] video.play failed', e); });

            // Scanner erst starten wenn tatsächlich Video-Frames da sind
            $video.addEventListener('loadeddata', function onLoaded() {
                $video.removeEventListener('loadeddata', onLoaded);
                console.info('[tix-checkin] Video ready:', $video.videoWidth + 'x' + $video.videoHeight, '— jsQR:', typeof jsQR);
                state.scanning = true;
                requestAnimationFrame(scanFrame);
            }, { once: true });

            // Fallback: Falls loadeddata nicht feuert, nach 1.5s trotzdem starten
            setTimeout(function(){
                if (!state.scanning) {
                    console.info('[tix-checkin] Fallback-Start nach 1.5s');
                    state.scanning = true;
                    requestAnimationFrame(scanFrame);
                }
            }, 1500);
        }).catch(function(err) {
            console.warn('[tix-checkin] Kamera-Fehler:', err && err.name, err && err.message);
        });
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
                // attemptBoth = auch invertierte QR (weiß-auf-schwarz oder Display-Reflektionen)
                var qr = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'attemptBoth' });
                if (qr && qr.data) {
                    var code = qr.data.toUpperCase();
                    var now = Date.now();
                    if (code !== state.lastScanned || now - state.lastScanTime > 3000) {
                        state.lastScanned = code;
                        state.lastScanTime = now;
                        // Sofortiger "QR erkannt"-Impuls — BEVOR wir die Server-Antwort haben
                        vibrate([60]);
                        flashScannerBorder();
                        processCode(code);
                    }
                }
            }
        }
        requestAnimationFrame(scanFrame);
    }

    // Kurzes grünes Blinken am Scan-Rahmen wenn QR erkannt wurde
    function flashScannerBorder() {
        var wrap = document.querySelector('.tix-ci-camera-wrap');
        if (!wrap) return;
        wrap.classList.add('tix-ci-scan-hit');
        setTimeout(function(){ wrap.classList.remove('tix-ci-scan-hit'); }, 400);
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
        // Offline? → Queue
        if (!state.isOnline) {
            addToOfflineQueue(code);
            vibrate([100, 50, 100]);
            showResult({
                success: true,
                data: { status: 'ok', name: 'Offline gespeichert', message: 'Wird synchronisiert sobald online.' }
            });
            return;
        }

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
    // VIBRATION
    // ══════════════════════════════════════════════

    function vibrate(pattern) {
        try {
            if (navigator.vibrate) navigator.vibrate(pattern);
        } catch (e) { /* nicht unterstützt */ }
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
                icon = '\u2713';
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
                // Erfolg: langer + deutlicher Doppel-Impuls
                vibrate([400, 80, 200]);
                break;

            case 'partial':
                cls = 'tix-ci-result-warn';
                icon = '\u26a0';
                title = data.name || 'Teilweise eingecheckt';
                details = [data.checked_in_count + '/' + data.total_expected + ' eingecheckt'];
                if (data.note) details.push(data.note);
                details.push(data.message || 'Teilweise eingecheckt.');
                playBeep(600, 120); setTimeout(function() { playBeep(600, 120); }, 180);
                vibrate([250, 80, 250, 80, 250]);
                break;

            case 'already':
                cls = 'tix-ci-result-warn';
                icon = '\u26a0';
                title = data.name || 'Bereits eingecheckt';
                details = [];
                if (data.total_expected > 1) details.push(data.checked_in_count + '/' + data.total_expected + ' eingecheckt');
                details.push(data.message || 'Bereits eingecheckt.');
                if (data.time) {
                    var t = new Date(data.time);
                    details.push('Um ' + t.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }));
                }
                playBeep(400, 100); setTimeout(function() { playBeep(400, 100); }, 200);
                vibrate([120, 60, 120, 60, 120, 60, 120, 60, 120]);
                break;

            case 'cancelled':
                cls = 'tix-ci-result-err';
                icon = '\u2715';
                title = (data.name || 'Ticket') + ' \u2014 STORNIERT';
                details = [data.message || 'Ticket wurde storniert.'];
                playBeep(200, 300);
                vibrate([500, 100, 500]);
                break;

            case 'not_found':
                cls = 'tix-ci-result-err';
                icon = '\u2715';
                title = 'Nicht gefunden';
                details = [data.message || 'Code nicht gefunden.'];
                playBeep(200, 300);
                vibrate([400, 80, 400]);
                break;

            default:
                cls = 'tix-ci-result-err';
                icon = '\u2715';
                title = 'Fehler';
                details = [data.message || 'Unbekannter Fehler.'];
                playBeep(200, 300);
                vibrate([400, 80, 400]);
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

        $listTitle.textContent = 'Check-in';
        if ($counter) {
            var counterText = '(' + checked + '/' + total + ')';
            if (partial > 0) counterText += ' \u00b7 ' + partial + ' teilweise';
            $counter.textContent = counterText;
        }

        // Fortschrittsbalken aktualisieren
        if (total > 0) {
            $progress.style.display = statsVisible ? '' : 'none';
            var pct = Math.round((checked / total) * 100);
            var fill = $progress.querySelector('.tix-ci-progress-fill');
            var text = $progress.querySelector('.tix-ci-progress-text');
            if (fill) {
                fill.style.width = pct + '%';
                // Farbe je nach Fortschritt
                fill.style.background = pct === 100
                    ? 'var(--ci-ok)'
                    : pct > 50
                        ? 'linear-gradient(90deg, var(--ci-accent), var(--ci-ok))'
                        : 'var(--ci-accent)';
            }
            if (text) text.textContent = checked + ' / ' + total + ' (' + pct + '%)';
        } else {
            $progress.style.display = 'none';
        }

        // Filter-Buttons anzeigen wenn es beides gibt
        if ($filters && (gCount > 0 && tCount > 0)) {
            $filters.style.display = '';
            var btns = $filters.querySelectorAll('.tix-ci-filter-btn');
            for (var f = 0; f < btns.length; f++) {
                var fType = btns[f].getAttribute('data-filter');
                btns[f].classList.toggle('active', fType === state.filter);
                if (fType === 'all') btns[f].textContent = 'Alle (' + total + ')';
                if (fType === 'guest') btns[f].textContent = 'G\u00e4ste (' + gCount + ')';
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

        var checkedSectionStarted = false;
        // Track welche Buchstaben existieren (für Alphabet-Index)
        var usedLetters = {};

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var type = item.type || 'guest';
            var totalExpected = item.total_expected || 1;
            var checkedCount  = item.checked_in_count || 0;
            var isPartial     = checkedCount > 0 && checkedCount < totalExpected;
            var isFull        = item.checked_in && checkedCount >= totalExpected;
            var time          = '';

            // Buchstaben tracken
            var nameLC = (item.name || '').toLowerCase();
            var firstChar = nameLC.charAt(0);
            if (/[a-z]/.test(firstChar)) {
                usedLetters[firstChar] = true;
            } else if (firstChar) {
                usedLetters['#'] = true;
            }

            if (item.checked_in && item.checkin_time) {
                var tt = new Date(item.checkin_time);
                time = tt.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            }

            // Kategorie-Header "Eingecheckt" vor dem ersten vollständig eingecheckten Eintrag
            if (isFull && !checkedSectionStarted) {
                checkedSectionStarted = true;
                var checkedTotal = 0;
                for (var ci = i; ci < items.length; ci++) {
                    var cItem = items[ci];
                    if (cItem.checked_in && (cItem.checked_in_count || 0) >= (cItem.total_expected || 1)) checkedTotal++;
                }
                html += '<div class="tix-ci-section-header tix-ci-section-checked"><span>\u2713 Eingecheckt (' + checkedTotal + ')</span></div>';
            }

            // Filter anwenden
            var filterHidden = (state.filter !== 'all' && state.filter !== type) ? ' style="display:none;"' : '';

            var rowCls = 'tix-ci-guest tix-ci-type-' + type;
            if (isFull) rowCls += ' tix-ci-guest-checked';
            else if (isPartial) rowCls += ' tix-ci-guest-partial';

            html += '<div class="' + rowCls + '" data-name="' + escAttr(nameLC) + '" data-type="' + type + '"' + filterHidden + '>';

            // Info-Bereich
            html += '<div class="tix-ci-guest-info">';
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

        // Alphabet-Index: nur Buchstaben anzeigen die existieren, ab 15 Einträgen
        if (items.length >= 15) {
            $alphaIndex.style.display = '';
            var letterBtns = $alphaIndex.querySelectorAll('.tix-ci-alpha-letter');
            for (var ai = 0; ai < letterBtns.length; ai++) {
                var l = letterBtns[ai].getAttribute('data-letter');
                var exists = usedLetters[l] || false;
                letterBtns[ai].style.opacity = exists ? '1' : '0.25';
                letterBtns[ai].disabled = !exists;
            }
        } else {
            $alphaIndex.style.display = 'none';
        }
    }

    // ── Filter ──
    if ($filters) {
        $filters.addEventListener('click', function(e) {
            var btn = e.target.closest('.tix-ci-filter-btn');
            if (!btn) return;
            state.filter = btn.getAttribute('data-filter') || 'all';

            var btns = $filters.querySelectorAll('.tix-ci-filter-btn');
            for (var i = 0; i < btns.length; i++) {
                btns[i].classList.toggle('active', btns[i] === btn);
            }

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
        if (!state.soundEnabled) return;
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
