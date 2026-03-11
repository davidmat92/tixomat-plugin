/**
 * Tixomat – Gewinnspiel Countdown-Roulette Animation + Screen Recording
 * Canvas-basierte Auslosungsanimation mit automatischer Videoaufnahme.
 */
(function () {
    'use strict';

    /* ══════════════════════════════════════
       KONFIGURATION
       ══════════════════════════════════════ */

    var FORMATS = {
        '9:16':  { w: 1080, h: 1920 },
        '1:1':   { w: 1080, h: 1080 },
        '16:9':  { w: 1920, h: 1080 }
    };

    var COLORS = {
        bg:       '#0a0a0a',
        bgAlt:    '#111111',
        text:     '#ffffff',
        muted:    '#888888',
        accent:   '#FF5500',
        gold:     '#FFD700',
        lime:     '#c8ff00',
        green:    '#16a34a'
    };

    var CONFETTI_PALETTE = ['#FF5500', '#c8ff00', '#FFD700', '#ffffff', '#ff3366', '#00ccff'];

    var FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    /* ══════════════════════════════════════
       STATE
       ══════════════════════════════════════ */

    var canvas, ctx;
    var W, H, scale;
    var mediaRecorder, recordedChunks, supportsRecording;
    var recordingMime, recordingExt;
    var animId, running;
    var config;
    var phases, currentPhase, phaseStart, globalStart;

    /* ══════════════════════════════════════
       EASING
       ══════════════════════════════════════ */

    function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
    function easeInOutQuad(t) { return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }
    function lerp(a, b, t) { return a + (b - a) * t; }
    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

    /* ══════════════════════════════════════
       CONFETTI SYSTEM
       ══════════════════════════════════════ */

    function ConfettiSystem() {
        this.particles = [];
    }

    ConfettiSystem.prototype.burst = function (cx, cy, count) {
        count = count || 120;
        for (var i = 0; i < count; i++) {
            var angle = Math.random() * Math.PI * 2;
            var speed = 4 + Math.random() * 12;
            this.particles.push({
                x: cx, y: cy,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed - 4,
                w: 6 + Math.random() * 10,
                h: 4 + Math.random() * 7,
                color: CONFETTI_PALETTE[Math.floor(Math.random() * CONFETTI_PALETTE.length)],
                rot: Math.random() * 360,
                rotV: (Math.random() - 0.5) * 14,
                gravity: 0.18,
                friction: 0.98,
                life: 1.0,
                decay: 0.006 + Math.random() * 0.008
            });
        }
    };

    ConfettiSystem.prototype.update = function () {
        for (var i = this.particles.length - 1; i >= 0; i--) {
            var p = this.particles[i];
            p.vx *= p.friction;
            p.vy *= p.friction;
            p.vy += p.gravity;
            p.x += p.vx;
            p.y += p.vy;
            p.rot += p.rotV;
            p.life -= p.decay;
            if (p.life <= 0) this.particles.splice(i, 1);
        }
    };

    ConfettiSystem.prototype.draw = function (c) {
        for (var i = 0; i < this.particles.length; i++) {
            var p = this.particles[i];
            c.save();
            c.translate(p.x, p.y);
            c.rotate(p.rot * Math.PI / 180);
            c.globalAlpha = Math.max(0, p.life);
            c.fillStyle = p.color;
            c.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
            c.restore();
        }
        c.globalAlpha = 1;
    };

    var confetti = new ConfettiSystem();

    /* ══════════════════════════════════════
       TEXT HELPERS
       ══════════════════════════════════════ */

    function setFont(size, weight) {
        ctx.font = (weight || '600') + ' ' + size + 'px ' + FONT;
    }

    function drawCentered(text, y, size, color, weight) {
        setFont(size, weight);
        ctx.fillStyle = color || COLORS.text;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, W / 2, y);
    }

    function drawGlow(text, y, size, color) {
        ctx.save();
        ctx.shadowColor = color || COLORS.accent;
        ctx.shadowBlur = 30;
        drawCentered(text, y, size, color || COLORS.accent, '800');
        ctx.restore();
    }

    /* ══════════════════════════════════════
       BACKGROUND
       ══════════════════════════════════════ */

    function drawBg() {
        var grd = ctx.createLinearGradient(0, 0, 0, H);
        grd.addColorStop(0, COLORS.bg);
        grd.addColorStop(0.5, COLORS.bgAlt);
        grd.addColorStop(1, COLORS.bg);
        ctx.fillStyle = grd;
        ctx.fillRect(0, 0, W, H);
    }

    function drawBranding(alpha) {
        ctx.save();
        ctx.globalAlpha = alpha != null ? alpha : 0.15;
        setFont(W * 0.025, '700');
        ctx.fillStyle = COLORS.accent;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';
        ctx.fillText('TIXOMAT', W * 0.04, H * 0.03);
        ctx.restore();
    }

    /* ══════════════════════════════════════
       PHASE: INTRO
       ══════════════════════════════════════ */

    function renderIntro(t) {
        var dur = 2000;
        var p = clamp(t / dur, 0, 1);
        var alpha = easeOutCubic(p);

        drawBg();

        // TIXOMAT Logo
        ctx.save();
        ctx.globalAlpha = alpha;
        setFont(W * 0.09, '800');
        ctx.fillStyle = COLORS.accent;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = COLORS.accent;
        ctx.shadowBlur = 40 * alpha;
        ctx.fillText('TIXOMAT', W / 2, H * 0.38);
        ctx.restore();

        // Event-Titel
        ctx.save();
        ctx.globalAlpha = alpha * 0.9;
        var titleSize = W * 0.035;
        setFont(titleSize, '500');
        ctx.fillStyle = COLORS.text;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        // Wrapping fuer lange Titel
        var words = config.eventTitle.split(' ');
        var lines = [];
        var line = '';
        for (var i = 0; i < words.length; i++) {
            var test = line + (line ? ' ' : '') + words[i];
            if (ctx.measureText(test).width > W * 0.8 && line) {
                lines.push(line);
                line = words[i];
            } else {
                line = test;
            }
        }
        if (line) lines.push(line);
        var startY = H * 0.48 - (lines.length - 1) * titleSize * 0.6;
        for (var j = 0; j < lines.length; j++) {
            ctx.fillText(lines[j], W / 2, startY + j * titleSize * 1.3);
        }
        ctx.restore();

        // Untertitel
        drawCentered('GEWINNSPIEL-AUSLOSUNG', H * 0.58, W * 0.022, COLORS.muted, '500');

        if (t >= dur) nextPhase();
    }

    /* ══════════════════════════════════════
       PHASE: COUNT
       ══════════════════════════════════════ */

    function renderCount(t) {
        var dur = 2000;
        var p = clamp(t / dur, 0, 1);

        drawBg();
        drawBranding(0.15);

        // Animierter Zaehler
        var count = Math.round(lerp(0, config.total, easeOutCubic(p)));
        drawGlow(count.toString(), H * 0.42, W * 0.16, COLORS.accent);
        drawCentered('Teilnehmer', H * 0.52, W * 0.04, COLORS.muted, '500');

        // Event-Titel klein oben
        drawCentered(config.eventTitle, H * 0.12, W * 0.025, COLORS.text, '500');

        if (t >= dur) nextPhase();
    }

    /* ══════════════════════════════════════
       PHASE: ROULETTE (per Preis)
       ══════════════════════════════════════ */

    var rouletteState = null;

    function initRouletteState(prizeIdx) {
        var prize = config.prizes[prizeIdx];
        var totalWinners = prize.qty || 1;
        var names = config.names.slice();
        // Shuffle fuer visuelles Roulette
        for (var i = names.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = names[i]; names[i] = names[j]; names[j] = tmp;
        }

        rouletteState = {
            prizeIdx: prizeIdx,
            prizeName: prize.name,
            totalWinners: totalWinners,
            currentWinner: 0,
            winners: [],
            names: names,
            subPhase: 'header',     // header → scroll → countdown → reveal
            subStart: 0,
            scrollOffset: 0,
            scrollSpeed: 0,
            winnerName: '',
            confettiFired: false
        };
    }

    function renderRoulette(t) {
        drawBg();
        drawBranding(0.15);

        if (!rouletteState) return;

        var s = rouletteState;
        var subT = t - s.subStart;

        // Preis-Header oben
        drawCentered(s.prizeName, H * 0.1, W * 0.035, COLORS.gold, '700');
        if (s.totalWinners > 1) {
            drawCentered('Gewinner ' + (s.currentWinner + 1) + ' von ' + s.totalWinners, H * 0.14, W * 0.02, COLORS.muted, '400');
        }

        // Event unten
        drawCentered(config.eventTitle, H * 0.92, W * 0.018, COLORS.muted, '400');

        if (s.subPhase === 'header') {
            // Preis-Name fliegt rein (0.8s)
            var hp = clamp(subT / 800, 0, 1);
            ctx.globalAlpha = easeOutCubic(hp);
            ctx.globalAlpha = 1;
            if (subT >= 800) { s.subPhase = 'scroll'; s.subStart = t; s.scrollSpeed = H * 0.8; }
        }

        else if (s.subPhase === 'scroll') {
            // Namen scrollen (4s), werden langsamer
            var scrollDur = 4000;
            var sp = clamp(subT / scrollDur, 0, 1);
            var easedSpeed = lerp(s.scrollSpeed, 0, easeOutCubic(sp));

            s.scrollOffset += easedSpeed * 0.016; // ~60fps frame time

            var nameH = H * 0.065;
            var visibleCount = Math.ceil(H * 0.6 / nameH) + 2;
            var baseIdx = Math.floor(s.scrollOffset / nameH);
            var remainder = s.scrollOffset % nameH;

            var centerY = H * 0.5;
            var topY = centerY - (visibleCount / 2) * nameH + remainder;

            // Highlight-Linie in der Mitte
            ctx.save();
            ctx.strokeStyle = COLORS.accent;
            ctx.lineWidth = 2;
            ctx.globalAlpha = 0.5;
            ctx.beginPath();
            ctx.moveTo(W * 0.15, centerY - nameH / 2);
            ctx.lineTo(W * 0.85, centerY - nameH / 2);
            ctx.moveTo(W * 0.15, centerY + nameH / 2);
            ctx.lineTo(W * 0.85, centerY + nameH / 2);
            ctx.stroke();
            ctx.restore();

            for (var i = 0; i < visibleCount; i++) {
                var idx = ((baseIdx + i) % s.names.length + s.names.length) % s.names.length;
                var y = topY - i * nameH;
                var distFromCenter = Math.abs(y - centerY) / (H * 0.3);
                var alpha = clamp(1 - distFromCenter, 0.1, 1);
                var sc = lerp(0.7, 1, clamp(1 - distFromCenter, 0, 1));

                ctx.save();
                ctx.globalAlpha = alpha;
                ctx.translate(W / 2, y);
                ctx.scale(sc, sc);
                setFont(W * 0.035, distFromCenter < 0.3 ? '700' : '400');
                ctx.fillStyle = distFromCenter < 0.3 ? COLORS.text : COLORS.muted;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(s.names[idx], 0, 0);
                ctx.restore();
            }

            if (subT >= scrollDur) {
                // Waehle Gewinner
                s.winnerName = s.names[Math.floor(Math.random() * s.names.length)];
                s.subPhase = 'countdown';
                s.subStart = t;
            }
        }

        else if (s.subPhase === 'countdown') {
            // 3... 2... 1... (2.4s)
            var cdDur = 2400;
            var cdp = clamp(subT / cdDur, 0, 1);
            var digit = cdp < 0.33 ? '3' : cdp < 0.66 ? '2' : '1';
            var digitP = (cdp % 0.33) / 0.33;

            // Letzter Name im Hintergrund (eingefroren)
            ctx.save();
            ctx.globalAlpha = 0.2;
            drawCentered(s.winnerName, H * 0.5, W * 0.04, COLORS.muted, '500');
            ctx.restore();

            // Countdown-Zahl
            var cdScale = lerp(2.5, 1, easeOutCubic(clamp(digitP * 3, 0, 1)));
            var cdAlpha = digitP < 0.8 ? 1 : lerp(1, 0, (digitP - 0.8) / 0.2);

            ctx.save();
            ctx.globalAlpha = cdAlpha;
            ctx.translate(W / 2, H * 0.48);
            ctx.scale(cdScale, cdScale);
            setFont(W * 0.18, '800');
            ctx.fillStyle = COLORS.accent;
            ctx.shadowColor = COLORS.accent;
            ctx.shadowBlur = 50;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(digit, 0, 0);
            ctx.restore();

            if (subT >= cdDur) { s.subPhase = 'reveal'; s.subStart = t; s.confettiFired = false; }
        }

        else if (s.subPhase === 'reveal') {
            // Gewinner anzeigen (2.5s)
            var revDur = 2500;
            var rp = clamp(subT / revDur, 0, 1);
            var nameScale = lerp(0.5, 1, easeOutCubic(clamp(rp * 4, 0, 1)));
            var nameAlpha = easeOutCubic(clamp(rp * 4, 0, 1));

            // Konfetti
            if (!s.confettiFired) {
                confetti.burst(W / 2, H * 0.45, 150);
                s.confettiFired = true;
            }
            confetti.update();
            confetti.draw(ctx);

            // GEWINNER Badge
            ctx.save();
            ctx.globalAlpha = nameAlpha;
            drawCentered('GEWINNER', H * 0.37, W * 0.022, COLORS.lime, '700');
            ctx.restore();

            // Name
            ctx.save();
            ctx.globalAlpha = nameAlpha;
            ctx.translate(W / 2, H * 0.48);
            ctx.scale(nameScale, nameScale);
            setFont(W * 0.06, '800');
            ctx.fillStyle = COLORS.text;
            ctx.shadowColor = COLORS.gold;
            ctx.shadowBlur = 30;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(s.winnerName, 0, 0);
            ctx.restore();

            // Bisherige Gewinner (wenn mehrere)
            if (s.winners.length > 0) {
                ctx.save();
                ctx.globalAlpha = 0.5;
                for (var wi = 0; wi < s.winners.length; wi++) {
                    drawCentered(s.winners[wi], H * 0.62 + wi * H * 0.035, W * 0.02, COLORS.muted, '400');
                }
                ctx.restore();
            }

            if (subT >= revDur) {
                s.winners.push(s.winnerName);
                s.currentWinner++;
                if (s.currentWinner < s.totalWinners) {
                    // Naechster Gewinner fuer gleichen Preis
                    s.subPhase = 'scroll';
                    s.subStart = t;
                    s.scrollSpeed = H * 0.8;
                    s.scrollOffset = 0;
                    s.confettiFired = false;
                } else {
                    // Naechster Preis oder Summary
                    // Gewinner dieses Preises JETZT sammeln, bevor State ueberschrieben wird
                    for (var ci = 0; ci < s.winners.length; ci++) {
                        summaryWinners.push({ name: s.winners[ci], prize: s.prizeName });
                    }
                    if (s.prizeIdx + 1 < config.prizes.length) {
                        initRouletteState(s.prizeIdx + 1);
                        rouletteState.subStart = t;
                    } else {
                        nextPhase();
                    }
                }
            }
        }
    }

    /* ══════════════════════════════════════
       PHASE: SUMMARY
       ══════════════════════════════════════ */

    function renderSummary(t) {
        var dur = 4000;
        var p = clamp(t / dur, 0, 1);
        var alpha = easeOutCubic(clamp(p * 3, 0, 1));

        drawBg();

        ctx.save();
        ctx.globalAlpha = alpha;

        // Header
        drawCentered('GEWINNER', H * 0.10, W * 0.05, COLORS.accent, '800');
        drawCentered(config.eventTitle, H * 0.16, W * 0.025, COLORS.text, '500');

        // Gewinner nach Preis gruppiert darstellen
        var yStart = H * 0.24;
        var lineH = H * 0.055;
        var yPos = yStart;

        // Gruppiere summaryWinners nach Preis
        var lastPrize = '';
        for (var si = 0; si < summaryWinners.length; si++) {
            var sw = summaryWinners[si];

            // Neuer Preis-Header wenn sich der Preis aendert
            if (sw.prize !== lastPrize) {
                if (si > 0) yPos += lineH * 0.4; // Extra spacing zwischen Preisen
                drawCentered(sw.prize, yPos, W * 0.022, COLORS.gold, '600');
                yPos += lineH * 0.7;
                lastPrize = sw.prize;
            }

            // Gewinner-Name
            drawCentered(sw.name, yPos, W * 0.035, COLORS.text, '700');
            yPos += lineH;
        }

        ctx.restore();

        // Branding
        drawBranding(0.3);

        // Restliche Konfetti updaten
        confetti.update();
        confetti.draw(ctx);

        if (t >= dur) nextPhase();
    }

    /* ══════════════════════════════════════
       PHASE: OUTRO
       ══════════════════════════════════════ */

    function renderOutro(t) {
        var dur = 1500;
        var p = clamp(t / dur, 0, 1);
        var alpha = 1 - easeOutCubic(p);

        drawBg();

        ctx.save();
        ctx.globalAlpha = alpha;
        drawCentered('Herzlichen Glückwunsch!', H * 0.45, W * 0.04, COLORS.text, '700');
        drawCentered('tixomat.de', H * 0.52, W * 0.025, COLORS.accent, '600');
        ctx.restore();

        confetti.update();
        confetti.draw(ctx);

        if (t >= dur) finish();
    }

    /* ══════════════════════════════════════
       PHASE MANAGEMENT
       ══════════════════════════════════════ */

    var summaryWinners = [];

    function buildPhases() {
        phases = [];
        phases.push({ name: 'intro', render: renderIntro });
        phases.push({ name: 'count', render: renderCount });

        if (config.prizes.length > 0) {
            phases.push({ name: 'roulette', render: renderRoulette });
        }

        phases.push({ name: 'summary', render: renderSummary });
        phases.push({ name: 'outro', render: renderOutro });
    }

    function nextPhase() {
        // Gewinner werden bereits in renderRoulette reveal-Phase gesammelt
        currentPhase++;
        phaseStart = performance.now() - globalStart;

        if (currentPhase < phases.length) {
            if (phases[currentPhase].name === 'roulette' && config.prizes.length > 0) {
                initRouletteState(0);
                if (rouletteState) rouletteState.subStart = 0;
            }
        }
    }

    /* ══════════════════════════════════════
       RENDER LOOP
       ══════════════════════════════════════ */

    function loop(timestamp) {
        if (!running) return;

        if (!globalStart) globalStart = timestamp;
        var elapsed = timestamp - globalStart;
        var phaseTime = elapsed - phaseStart;

        if (currentPhase < phases.length) {
            phases[currentPhase].render(phaseTime);
        }

        animId = requestAnimationFrame(loop);
    }

    /* ══════════════════════════════════════
       MEDIARECORDER
       ══════════════════════════════════════ */

    supportsRecording = (function () {
        try {
            return typeof MediaRecorder !== 'undefined' &&
                typeof HTMLCanvasElement.prototype.captureStream === 'function';
        } catch (e) { return false; }
    })();

    function startRecording() {
        if (!supportsRecording) return;
        recordedChunks = [];
        var stream = canvas.captureStream(30);

        // MP4 zuerst (Chrome 128+, Safari), dann WebM als Fallback
        var mimeType = '';
        recordingExt = 'webm';
        recordingMime = 'video/webm';

        var candidates = [
            ['video/mp4;codecs=avc1',         'mp4', 'video/mp4'],
            ['video/mp4',                      'mp4', 'video/mp4'],
            ['video/webm;codecs=vp9',          'webm', 'video/webm'],
            ['video/webm;codecs=vp8',          'webm', 'video/webm'],
            ['video/webm',                     'webm', 'video/webm']
        ];

        for (var i = 0; i < candidates.length; i++) {
            if (MediaRecorder.isTypeSupported(candidates[i][0])) {
                mimeType = candidates[i][0];
                recordingExt = candidates[i][1];
                recordingMime = candidates[i][2];
                break;
            }
        }

        if (!mimeType) mimeType = 'video/webm';

        mediaRecorder = new MediaRecorder(stream, {
            mimeType: mimeType,
            videoBitsPerSecond: 8000000
        });
        mediaRecorder.ondataavailable = function (e) {
            if (e.data && e.data.size > 0) recordedChunks.push(e.data);
        };
        mediaRecorder.start(100);
    }

    function stopRecording() {
        return new Promise(function (resolve) {
            if (!mediaRecorder || mediaRecorder.state !== 'recording') {
                resolve(null);
                return;
            }
            mediaRecorder.onstop = function () {
                var blob = new Blob(recordedChunks, { type: recordingMime || 'video/webm' });
                resolve(blob);
            };
            mediaRecorder.stop();
        });
    }

    function downloadBlob(blob) {
        if (!blob) return;
        var ext = recordingExt || 'webm';
        var slug = (config.eventTitle || 'verlosung').replace(/[^a-zA-Z0-9äöüÄÖÜß_-]/g, '_').substring(0, 50);
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'verlosung-' + slug + '.' + ext;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
    }

    /* ══════════════════════════════════════
       OVERLAY
       ══════════════════════════════════════ */

    var overlay;

    function createOverlay() {
        overlay = document.createElement('div');
        overlay.id = 'tix-raffle-overlay';

        canvas = document.createElement('canvas');
        canvas.id = 'tix-raffle-canvas';
        canvas.width = W;
        canvas.height = H;
        ctx = canvas.getContext('2d');

        var controls = document.createElement('div');
        controls.id = 'tix-raffle-controls';
        controls.style.display = 'none';

        var dlBtn = document.createElement('button');
        dlBtn.id = 'tix-raffle-dl-btn';
        dlBtn.textContent = '⬇ Video herunterladen (.' + (recordingExt || 'mp4') + ')';
        dlBtn.onclick = function () {
            if (overlay._blob) downloadBlob(overlay._blob);
        };

        var closeBtn = document.createElement('button');
        closeBtn.id = 'tix-raffle-close-btn';
        closeBtn.textContent = 'Schließen';
        closeBtn.onclick = function () { destroy(); if (config.onComplete) config.onComplete(); };

        controls.appendChild(dlBtn);
        controls.appendChild(closeBtn);

        overlay.appendChild(canvas);
        overlay.appendChild(controls);
        document.body.appendChild(overlay);
    }

    function showControls(blob) {
        overlay._blob = blob;
        var ctrl = document.getElementById('tix-raffle-controls');
        if (ctrl) {
            ctrl.style.display = 'flex';
            if (!blob) {
                var dl = document.getElementById('tix-raffle-dl-btn');
                if (dl) dl.style.display = 'none';
            }
        }
    }

    /* ══════════════════════════════════════
       FINISH + DESTROY
       ══════════════════════════════════════ */

    function finish() {
        running = false;
        if (animId) cancelAnimationFrame(animId);

        stopRecording().then(function (blob) {
            showControls(blob);
        });
    }

    function destroy() {
        running = false;
        if (animId) cancelAnimationFrame(animId);
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            try { mediaRecorder.stop(); } catch (e) {}
        }
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
        overlay = null;
        canvas = null;
        ctx = null;
        confetti.particles = [];
        summaryWinners = [];
        rouletteState = null;
    }

    /* ══════════════════════════════════════
       PUBLIC API
       ══════════════════════════════════════ */

    window.TixRaffleDraw = {
        supportsRecording: supportsRecording,

        init: function (cfg) {
            config = cfg;
            var fmt = FORMATS[cfg.format] || FORMATS['9:16'];
            W = fmt.w;
            H = fmt.h;
            scale = 1;

            summaryWinners = [];
            confetti.particles = [];
            rouletteState = null;

            buildPhases();
            currentPhase = 0;
            phaseStart = 0;
            globalStart = null;
        },

        start: function () {
            createOverlay();
            running = true;
            startRecording();
            animId = requestAnimationFrame(loop);

            // ESC zum Abbrechen
            var escHandler = function (e) {
                if (e.key === 'Escape' && running) {
                    destroy();
                    document.removeEventListener('keydown', escHandler);
                    if (config.onError) config.onError('Animation abgebrochen.');
                }
            };
            document.addEventListener('keydown', escHandler);
        },

        destroy: destroy
    };

})();
