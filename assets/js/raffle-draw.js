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
        bg:       '#FF5500',
        bgDark:   '#cc4400',
        bgDeep:   '#992200',
        text:     '#ffffff',
        muted:    'rgba(255,255,255,0.55)',
        accent:   '#ffffff',
        gold:     '#FFD700',
        lime:     '#c8ff00',
        dark:     'rgba(0,0,0,0.35)'
    };

    var CONFETTI_PALETTE = ['#ffffff', '#c8ff00', '#FFD700', '#ff3366', '#00ccff', '#FF5500'];

    var FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    var LOGO_URL = 'https://tixomat.de/wp-content/uploads/2026/03/logo-tixomat-white-500px.png';

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
    var logoImg = null;
    var ambientDots = [];

    /* ══════════════════════════════════════
       EASING
       ══════════════════════════════════════ */

    function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
    function easeOutBack(t) { var c = 1.7; return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2); }
    function lerp(a, b, t) { return a + (b - a) * t; }
    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

    /* ══════════════════════════════════════
       LOGO PRELOADING
       ══════════════════════════════════════ */

    function preloadLogo(cb) {
        if (logoImg) { if (cb) cb(); return; }
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () { logoImg = img; if (cb) cb(); };
        img.onerror = function () { logoImg = null; if (cb) cb(); };
        img.src = LOGO_URL;
    }

    function drawLogo(cx, cy, maxW, alpha) {
        if (!logoImg) return;
        var sc = maxW / logoImg.width;
        var w = logoImg.width * sc;
        var h = logoImg.height * sc;
        ctx.save();
        ctx.globalAlpha = alpha != null ? alpha : 1;
        ctx.drawImage(logoImg, cx - w / 2, cy - h / 2, w, h);
        ctx.restore();
    }

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
            var speed = 4 + Math.random() * 14;
            this.particles.push({
                x: cx, y: cy,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed - 5,
                w: 6 + Math.random() * 10,
                h: 4 + Math.random() * 7,
                color: CONFETTI_PALETTE[Math.floor(Math.random() * CONFETTI_PALETTE.length)],
                rot: Math.random() * 360,
                rotV: (Math.random() - 0.5) * 14,
                gravity: 0.18,
                friction: 0.98,
                life: 1.0,
                decay: 0.005 + Math.random() * 0.007
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
       AMBIENT FLOATING DOTS
       ══════════════════════════════════════ */

    function initAmbient() {
        ambientDots = [];
        for (var i = 0; i < 35; i++) {
            ambientDots.push({
                x: Math.random() * W,
                y: Math.random() * H,
                r: 1.5 + Math.random() * 3.5,
                vx: (Math.random() - 0.5) * 0.4,
                vy: -0.2 - Math.random() * 0.6,
                alpha: 0.06 + Math.random() * 0.14
            });
        }
    }

    function drawAmbient() {
        for (var i = 0; i < ambientDots.length; i++) {
            var d = ambientDots[i];
            d.x += d.vx;
            d.y += d.vy;
            if (d.y < -10) { d.y = H + 10; d.x = Math.random() * W; }
            if (d.x < -10) d.x = W + 10;
            if (d.x > W + 10) d.x = -10;
            ctx.beginPath();
            ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255,255,255,' + d.alpha + ')';
            ctx.fill();
        }
    }

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

    function drawGlow(text, y, size, color, blur) {
        ctx.save();
        ctx.shadowColor = color || COLORS.text;
        ctx.shadowBlur = blur || 30;
        drawCentered(text, y, size, color || COLORS.text, '800');
        ctx.restore();
    }

    function roundRect(c, x, y, w, h, r) {
        c.beginPath();
        c.moveTo(x + r, y);
        c.lineTo(x + w - r, y);
        c.quadraticCurveTo(x + w, y, x + w, y + r);
        c.lineTo(x + w, y + h - r);
        c.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        c.lineTo(x + r, y + h);
        c.quadraticCurveTo(x, y + h, x, y + h - r);
        c.lineTo(x, y + r);
        c.quadraticCurveTo(x, y, x + r, y);
        c.closePath();
    }

    /* ══════════════════════════════════════
       BACKGROUND
       ══════════════════════════════════════ */

    function drawBg() {
        // Radial gradient: center brighter, edges darker
        var grd = ctx.createRadialGradient(W / 2, H * 0.45, 0, W / 2, H * 0.45, Math.max(W, H) * 0.8);
        grd.addColorStop(0, '#ff6622');
        grd.addColorStop(0.5, COLORS.bg);
        grd.addColorStop(1, COLORS.bgDark);
        ctx.fillStyle = grd;
        ctx.fillRect(0, 0, W, H);

        // Subtle vignette
        var vig = ctx.createRadialGradient(W / 2, H / 2, W * 0.2, W / 2, H / 2, Math.max(W, H) * 0.75);
        vig.addColorStop(0, 'rgba(0,0,0,0)');
        vig.addColorStop(1, 'rgba(0,0,0,0.25)');
        ctx.fillStyle = vig;
        ctx.fillRect(0, 0, W, H);

        drawAmbient();
    }

    function drawBranding(alpha) {
        // Logo oben links
        if (logoImg) {
            drawLogo(W * 0.14, H * 0.035, W * 0.18, alpha != null ? alpha : 0.2);
        } else {
            ctx.save();
            ctx.globalAlpha = alpha != null ? alpha : 0.2;
            setFont(W * 0.025, '700');
            ctx.fillStyle = COLORS.text;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'top';
            ctx.fillText('TIXOMAT', W * 0.04, H * 0.03);
            ctx.restore();
        }
    }

    /* ══════════════════════════════════════
       PHASE: INTRO
       ══════════════════════════════════════ */

    function renderIntro(t) {
        var dur = 2500;
        var p = clamp(t / dur, 0, 1);
        var alpha = easeOutCubic(clamp(p * 2, 0, 1));

        drawBg();

        // Logo zentriert gross
        if (logoImg) {
            var logoScale = lerp(0.85, 1, easeOutCubic(clamp(p * 2, 0, 1)));
            ctx.save();
            ctx.globalAlpha = alpha;
            ctx.translate(W / 2, H * 0.36);
            ctx.scale(logoScale, logoScale);
            var lw = W * 0.55;
            var lsc = lw / logoImg.width;
            ctx.drawImage(logoImg, -lw / 2, -(logoImg.height * lsc) / 2, lw, logoImg.height * lsc);
            ctx.restore();
        } else {
            ctx.save();
            ctx.globalAlpha = alpha;
            setFont(W * 0.09, '800');
            ctx.fillStyle = COLORS.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.shadowColor = 'rgba(0,0,0,0.3)';
            ctx.shadowBlur = 20;
            ctx.fillText('TIXOMAT', W / 2, H * 0.36);
            ctx.restore();
        }

        // Trennlinie
        var lineAlpha = easeOutCubic(clamp((p - 0.2) * 3, 0, 1));
        ctx.save();
        ctx.globalAlpha = lineAlpha * 0.4;
        ctx.strokeStyle = COLORS.text;
        ctx.lineWidth = 1.5;
        var lineW = W * 0.3 * lineAlpha;
        ctx.beginPath();
        ctx.moveTo(W / 2 - lineW, H * 0.44);
        ctx.lineTo(W / 2 + lineW, H * 0.44);
        ctx.stroke();
        ctx.restore();

        // Event-Titel
        var titleAlpha = easeOutCubic(clamp((p - 0.25) * 2.5, 0, 1));
        ctx.save();
        ctx.globalAlpha = titleAlpha;
        var titleSize = W * 0.032;
        setFont(titleSize, '500');
        ctx.fillStyle = COLORS.text;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        // Wrapping
        var words = config.eventTitle.split(' ');
        var lines = [];
        var line = '';
        for (var i = 0; i < words.length; i++) {
            var test = line + (line ? ' ' : '') + words[i];
            if (ctx.measureText(test).width > W * 0.75 && line) {
                lines.push(line);
                line = words[i];
            } else {
                line = test;
            }
        }
        if (line) lines.push(line);
        var startY = H * 0.49 - (lines.length - 1) * titleSize * 0.6;
        for (var j = 0; j < lines.length; j++) {
            ctx.fillText(lines[j], W / 2, startY + j * titleSize * 1.3);
        }
        ctx.restore();

        // Untertitel
        var subAlpha = easeOutCubic(clamp((p - 0.4) * 2.5, 0, 1));
        ctx.save();
        ctx.globalAlpha = subAlpha * 0.6;
        drawCentered('GEWINNSPIEL-AUSLOSUNG', H * 0.58, W * 0.02, COLORS.text, '600');
        ctx.restore();

        if (t >= dur) nextPhase();
    }

    /* ══════════════════════════════════════
       PHASE: COUNT
       ══════════════════════════════════════ */

    function renderCount(t) {
        var dur = 2200;
        var p = clamp(t / dur, 0, 1);

        drawBg();
        drawBranding(0.2);

        // Animierter Zaehler
        var count = Math.round(lerp(0, config.total, easeOutCubic(p)));
        drawGlow(count.toString(), H * 0.42, W * 0.16, COLORS.text, 40);

        // Label
        var labelAlpha = easeOutCubic(clamp(p * 3, 0, 1));
        ctx.save();
        ctx.globalAlpha = labelAlpha * 0.7;
        drawCentered('Teilnehmer', H * 0.52, W * 0.035, COLORS.text, '400');
        ctx.restore();

        // Event-Titel klein oben
        ctx.save();
        ctx.globalAlpha = 0.7;
        drawCentered(config.eventTitle, H * 0.12, W * 0.022, COLORS.text, '500');
        ctx.restore();

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
        // Shuffle
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
            subPhase: 'header',
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

        // Preis-Header oben – mit Hintergrund-Pill
        ctx.save();
        setFont(W * 0.03, '700');
        var pillW = ctx.measureText(s.prizeName).width + W * 0.06;
        var pillH = H * 0.045;
        var pillX = W / 2 - pillW / 2;
        var pillY = H * 0.075 - pillH / 2;
        ctx.fillStyle = 'rgba(0,0,0,0.2)';
        roundRect(ctx, pillX, pillY, pillW, pillH, pillH / 2);
        ctx.fill();
        ctx.restore();
        drawCentered(s.prizeName, H * 0.075, W * 0.03, COLORS.gold, '700');

        if (s.totalWinners > 1) {
            ctx.save();
            ctx.globalAlpha = 0.6;
            drawCentered('Gewinner ' + (s.currentWinner + 1) + ' von ' + s.totalWinners, H * 0.115, W * 0.018, COLORS.text, '400');
            ctx.restore();
        }

        // Event unten
        ctx.save();
        ctx.globalAlpha = 0.4;
        drawCentered(config.eventTitle, H * 0.93, W * 0.016, COLORS.text, '400');
        ctx.restore();

        if (s.subPhase === 'header') {
            var hp = clamp(subT / 800, 0, 1);
            ctx.globalAlpha = easeOutCubic(hp);
            ctx.globalAlpha = 1;
            if (subT >= 800) { s.subPhase = 'scroll'; s.subStart = t; s.scrollSpeed = H * 0.8; }
        }

        else if (s.subPhase === 'scroll') {
            var scrollDur = 4000;
            var sp = clamp(subT / scrollDur, 0, 1);
            var easedSpeed = lerp(s.scrollSpeed, 0, easeOutCubic(sp));

            s.scrollOffset += easedSpeed * 0.016;

            var nameH = H * 0.065;
            var visibleCount = Math.ceil(H * 0.6 / nameH) + 2;
            var baseIdx = Math.floor(s.scrollOffset / nameH);
            var remainder = s.scrollOffset % nameH;

            var centerY = H * 0.5;
            var topY = centerY - (visibleCount / 2) * nameH + remainder;

            // Selection-Highlight Box (gerundetes Rechteck)
            ctx.save();
            var hlH = nameH * 1.3;
            var hlW = W * 0.78;
            var hlX = W / 2 - hlW / 2;
            var hlY = centerY - hlH / 2;
            ctx.fillStyle = 'rgba(0,0,0,0.15)';
            roundRect(ctx, hlX, hlY, hlW, hlH, 14);
            ctx.fill();
            ctx.strokeStyle = 'rgba(255,255,255,0.35)';
            ctx.lineWidth = 2;
            roundRect(ctx, hlX, hlY, hlW, hlH, 14);
            ctx.stroke();
            ctx.restore();

            for (var i = 0; i < visibleCount; i++) {
                var idx = ((baseIdx + i) % s.names.length + s.names.length) % s.names.length;
                var y = topY - i * nameH;
                var distFromCenter = Math.abs(y - centerY) / (H * 0.3);
                var alpha = clamp(1 - distFromCenter * 0.8, 0.08, 1);
                var sc = lerp(0.72, 1, clamp(1 - distFromCenter, 0, 1));

                ctx.save();
                ctx.globalAlpha = alpha;
                ctx.translate(W / 2, y);
                ctx.scale(sc, sc);
                setFont(W * 0.033, distFromCenter < 0.25 ? '700' : '400');
                ctx.fillStyle = COLORS.text;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                if (distFromCenter < 0.25) {
                    ctx.shadowColor = 'rgba(0,0,0,0.3)';
                    ctx.shadowBlur = 8;
                }
                ctx.fillText(s.names[idx], 0, 0);
                ctx.restore();
            }

            if (subT >= scrollDur) {
                s.winnerName = s.names[Math.floor(Math.random() * s.names.length)];
                s.subPhase = 'countdown';
                s.subStart = t;
            }
        }

        else if (s.subPhase === 'countdown') {
            // 3... 2... 1... (2.4s) – KEIN Gewinner-Name sichtbar!
            var cdDur = 2400;
            var cdp = clamp(subT / cdDur, 0, 1);
            var digit = cdp < 0.33 ? '3' : cdp < 0.66 ? '2' : '1';
            var digitP = (cdp % 0.33) / 0.33;

            // Pulsierender Ring
            var ringRadius = W * 0.13;
            var ringPulse = 0.15 + Math.sin(digitP * Math.PI) * 0.15;
            ctx.save();
            ctx.beginPath();
            ctx.arc(W / 2, H * 0.48, ringRadius * (1 + digitP * 0.05), 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(255,255,255,' + ringPulse + ')';
            ctx.lineWidth = 3;
            ctx.stroke();
            ctx.restore();

            // Aeusserer Ring
            ctx.save();
            ctx.beginPath();
            ctx.arc(W / 2, H * 0.48, ringRadius * 1.4, 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(255,255,255,' + (ringPulse * 0.4) + ')';
            ctx.lineWidth = 1.5;
            ctx.stroke();
            ctx.restore();

            // Countdown-Zahl
            var cdScale = lerp(2.0, 1, easeOutBack(clamp(digitP * 3, 0, 1)));
            var cdAlpha = digitP < 0.75 ? 1 : lerp(1, 0, (digitP - 0.75) / 0.25);

            ctx.save();
            ctx.globalAlpha = cdAlpha;
            ctx.translate(W / 2, H * 0.48);
            ctx.scale(cdScale, cdScale);
            setFont(W * 0.18, '800');
            ctx.fillStyle = COLORS.text;
            ctx.shadowColor = 'rgba(0,0,0,0.4)';
            ctx.shadowBlur = 30;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(digit, 0, 0);
            ctx.restore();

            if (subT >= cdDur) { s.subPhase = 'reveal'; s.subStart = t; s.confettiFired = false; }
        }

        else if (s.subPhase === 'reveal') {
            var revDur = 2800;
            var rp = clamp(subT / revDur, 0, 1);
            var nameScale = lerp(0.5, 1, easeOutBack(clamp(rp * 3, 0, 1)));
            var nameAlpha = easeOutCubic(clamp(rp * 3, 0, 1));

            // Flash-Effekt am Anfang
            if (rp < 0.1) {
                ctx.save();
                ctx.globalAlpha = (1 - rp * 10) * 0.4;
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, W, H);
                ctx.restore();
            }

            // Konfetti
            if (!s.confettiFired) {
                confetti.burst(W * 0.3, H * 0.42, 80);
                confetti.burst(W * 0.7, H * 0.42, 80);
                s.confettiFired = true;
            }
            confetti.update();
            confetti.draw(ctx);

            // GEWINNER Badge
            ctx.save();
            ctx.globalAlpha = nameAlpha * 0.7;
            setFont(W * 0.018, '700');
            ctx.fillStyle = COLORS.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.letterSpacing = W * 0.008 + 'px';
            ctx.fillText('★  G E W I N N E R  ★', W / 2, H * 0.36);
            ctx.restore();

            // Name – Hintergrund-Pill
            ctx.save();
            ctx.globalAlpha = nameAlpha;
            setFont(W * 0.055, '800');
            var nw = ctx.measureText(s.winnerName).width + W * 0.08;
            var nh = H * 0.075;
            ctx.fillStyle = 'rgba(0,0,0,0.2)';
            roundRect(ctx, W / 2 - nw / 2, H * 0.46 - nh / 2, nw, nh, nh / 2);
            ctx.fill();
            ctx.restore();

            // Name
            ctx.save();
            ctx.globalAlpha = nameAlpha;
            ctx.translate(W / 2, H * 0.46);
            ctx.scale(nameScale, nameScale);
            setFont(W * 0.055, '800');
            ctx.fillStyle = COLORS.text;
            ctx.shadowColor = 'rgba(0,0,0,0.4)';
            ctx.shadowBlur = 20;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(s.winnerName, 0, 0);
            ctx.restore();

            // Preis-Name unter dem Gewinner
            ctx.save();
            ctx.globalAlpha = nameAlpha * 0.6;
            drawCentered(s.prizeName, H * 0.53, W * 0.02, COLORS.gold, '600');
            ctx.restore();

            // Bisherige Gewinner (wenn mehrere)
            if (s.winners.length > 0) {
                ctx.save();
                ctx.globalAlpha = 0.45;
                for (var wi = 0; wi < s.winners.length; wi++) {
                    drawCentered(s.winners[wi], H * 0.61 + wi * H * 0.035, W * 0.018, COLORS.text, '400');
                }
                ctx.restore();
            }

            if (subT >= revDur) {
                s.winners.push(s.winnerName);
                s.currentWinner++;
                if (s.currentWinner < s.totalWinners) {
                    s.subPhase = 'scroll';
                    s.subStart = t;
                    s.scrollSpeed = H * 0.8;
                    s.scrollOffset = 0;
                    s.confettiFired = false;
                } else {
                    // Gewinner sammeln bevor State ueberschrieben wird
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
        var dur = 4500;
        var p = clamp(t / dur, 0, 1);
        var alpha = easeOutCubic(clamp(p * 3, 0, 1));

        drawBg();

        ctx.save();
        ctx.globalAlpha = alpha;

        // Header mit Logo
        if (logoImg) {
            drawLogo(W / 2, H * 0.07, W * 0.28, alpha);
        }
        drawCentered('ALLE GEWINNER', H * 0.135, W * 0.028, COLORS.text, '700');

        // Trennlinie
        ctx.save();
        ctx.globalAlpha = alpha * 0.3;
        ctx.strokeStyle = COLORS.text;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(W * 0.3, H * 0.16);
        ctx.lineTo(W * 0.7, H * 0.16);
        ctx.stroke();
        ctx.restore();

        // Event-Titel
        ctx.save();
        ctx.globalAlpha = alpha * 0.6;
        drawCentered(config.eventTitle, H * 0.185, W * 0.02, COLORS.text, '500');
        ctx.restore();

        // Gewinner nach Preis gruppiert
        var yStart = H * 0.24;
        var lineH = H * 0.052;
        var yPos = yStart;

        var lastPrize = '';
        for (var si = 0; si < summaryWinners.length; si++) {
            var sw = summaryWinners[si];

            if (sw.prize !== lastPrize) {
                if (si > 0) yPos += lineH * 0.5;
                // Preis-Pill
                ctx.save();
                setFont(W * 0.018, '600');
                var pw = ctx.measureText(sw.prize).width + W * 0.04;
                var ph = lineH * 0.7;
                ctx.fillStyle = 'rgba(0,0,0,0.15)';
                roundRect(ctx, W / 2 - pw / 2, yPos - ph / 2, pw, ph, ph / 2);
                ctx.fill();
                ctx.restore();
                drawCentered(sw.prize, yPos, W * 0.018, COLORS.gold, '600');
                yPos += lineH * 0.8;
                lastPrize = sw.prize;
            }

            drawCentered(sw.name, yPos, W * 0.032, COLORS.text, '700');
            yPos += lineH;
        }

        ctx.restore();

        // Konfetti
        confetti.update();
        confetti.draw(ctx);

        if (t >= dur) nextPhase();
    }

    /* ══════════════════════════════════════
       PHASE: OUTRO
       ══════════════════════════════════════ */

    function renderOutro(t) {
        var dur = 2000;
        var p = clamp(t / dur, 0, 1);
        var alpha = p < 0.7 ? 1 : lerp(1, 0, (p - 0.7) / 0.3);

        drawBg();

        ctx.save();
        ctx.globalAlpha = alpha;

        // Logo
        if (logoImg) {
            drawLogo(W / 2, H * 0.4, W * 0.4, alpha);
        }

        drawCentered('Herzlichen Glückwunsch!', H * 0.52, W * 0.035, COLORS.text, '700');

        ctx.save();
        ctx.globalAlpha = alpha * 0.6;
        drawCentered('tixomat.de', H * 0.57, W * 0.02, COLORS.text, '500');
        ctx.restore();

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

        // WebCodecs: Frame nach jedem Render capturen
        captureFrame();

        animId = requestAnimationFrame(loop);
    }

    /* ══════════════════════════════════════
       RECORDING (MP4 fuer alle Browser)
       ══════════════════════════════════════ */

    // Erkennung: MediaRecorder MP4, WebCodecs + mp4-muxer, WebM Fallback
    var recMode = 'none';
    supportsRecording = false;

    (function detectRecording() {
        try {
            var hasMR = typeof MediaRecorder !== 'undefined' &&
                        typeof HTMLCanvasElement.prototype.captureStream === 'function';
            var hasWC = typeof VideoEncoder !== 'undefined' &&
                        typeof Mp4Muxer !== 'undefined' && Mp4Muxer.Muxer;

            if (!hasMR && !hasWC) return;
            supportsRecording = true;

            if (hasMR && (MediaRecorder.isTypeSupported('video/mp4;codecs=avc1') ||
                          MediaRecorder.isTypeSupported('video/mp4'))) {
                recMode = 'mp4-native';
                return;
            }

            if (hasWC) {
                recMode = 'mp4-webcodecs';
                return;
            }

            if (hasMR) recMode = 'webm';
        } catch (e) { /* silent */ }
    })();

    // WebCodecs State
    var wcEncoder, wcMuxer, wcTarget, wcFrameCount;

    function startRecording() {
        if (!supportsRecording) return;
        recordedChunks = [];
        recordingExt = 'mp4';
        recordingMime = 'video/mp4';

        if (recMode === 'mp4-native') {
            var stream = canvas.captureStream(30);
            var mimeType = MediaRecorder.isTypeSupported('video/mp4;codecs=avc1')
                ? 'video/mp4;codecs=avc1' : 'video/mp4';

            mediaRecorder = new MediaRecorder(stream, {
                mimeType: mimeType,
                videoBitsPerSecond: 8000000
            });
            mediaRecorder.ondataavailable = function (e) {
                if (e.data && e.data.size > 0) recordedChunks.push(e.data);
            };
            mediaRecorder.start(100);

        } else if (recMode === 'mp4-webcodecs') {
            wcTarget = new Mp4Muxer.ArrayBufferTarget();
            wcMuxer = new Mp4Muxer.Muxer({
                target: wcTarget,
                video: { codec: 'avc', width: W, height: H },
                fastStart: 'in-memory'
            });
            wcFrameCount = 0;

            wcEncoder = new VideoEncoder({
                output: function (chunk, meta) {
                    wcMuxer.addVideoChunk(chunk, meta);
                },
                error: function (e) {
                    console.error('[TixRaffleDraw] VideoEncoder error:', e);
                }
            });

            wcEncoder.configure({
                codec: 'avc1.42001f',
                width: W,
                height: H,
                bitrate: 8000000,
                framerate: 30
            });

        } else {
            recordingExt = 'webm';
            recordingMime = 'video/webm';
            var stream = canvas.captureStream(30);
            var mimeType = 'video/webm;codecs=vp9';
            if (!MediaRecorder.isTypeSupported(mimeType)) mimeType = 'video/webm;codecs=vp8';
            if (!MediaRecorder.isTypeSupported(mimeType)) mimeType = 'video/webm';

            mediaRecorder = new MediaRecorder(stream, {
                mimeType: mimeType,
                videoBitsPerSecond: 8000000
            });
            mediaRecorder.ondataavailable = function (e) {
                if (e.data && e.data.size > 0) recordedChunks.push(e.data);
            };
            mediaRecorder.start(100);
        }
    }

    function captureFrame() {
        if (recMode !== 'mp4-webcodecs' || !wcEncoder || !canvas) return;
        try {
            var frame = new VideoFrame(canvas, {
                timestamp: wcFrameCount * (1000000 / 30)
            });
            var keyFrame = wcFrameCount % 60 === 0;
            wcEncoder.encode(frame, { keyFrame: keyFrame });
            frame.close();
            wcFrameCount++;
        } catch (e) { /* skip frame */ }
    }

    function stopRecording() {
        return new Promise(function (resolve) {
            if (recMode === 'mp4-webcodecs' && wcEncoder) {
                wcEncoder.flush().then(function () {
                    wcMuxer.finalize();
                    var buf = wcTarget.buffer;
                    var blob = new Blob([buf], { type: 'video/mp4' });
                    wcEncoder = null;
                    wcMuxer = null;
                    wcTarget = null;
                    resolve(blob);
                }).catch(function () { resolve(null); });
                return;
            }

            if (!mediaRecorder || mediaRecorder.state !== 'recording') {
                resolve(null);
                return;
            }
            mediaRecorder.onstop = function () {
                var blob = new Blob(recordedChunks, { type: recordingMime || 'video/mp4' });
                resolve(blob);
            };
            mediaRecorder.stop();
        });
    }

    function downloadBlob(blob) {
        if (!blob) return;
        var ext = recordingExt || 'mp4';
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
        dlBtn.textContent = '⬇ Video herunterladen (.mp4)';
        dlBtn.onclick = function () {
            if (overlay._blob) downloadBlob(overlay._blob);
        };

        var closeBtn = document.createElement('button');
        closeBtn.id = 'tix-raffle-close-btn';
        closeBtn.textContent = 'Schließen';
        closeBtn.onclick = function () { destroy(); if (config.onClose) config.onClose(); };

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
            // Auslosung SOFORT speichern wenn Animation fertig
            if (config.onFinish) config.onFinish();
        });
    }

    function destroy() {
        running = false;
        if (animId) cancelAnimationFrame(animId);
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            try { mediaRecorder.stop(); } catch (e) {}
        }
        if (wcEncoder) { try { wcEncoder.close(); } catch (e) {} wcEncoder = null; }
        wcMuxer = null;
        wcTarget = null;
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
        overlay = null;
        canvas = null;
        ctx = null;
        confetti.particles = [];
        summaryWinners = [];
        rouletteState = null;
        ambientDots = [];
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

            initAmbient();
            buildPhases();
            currentPhase = 0;
            phaseStart = 0;
            globalStart = null;

            // Logo vorladen
            preloadLogo();
        },

        start: function () {
            createOverlay();
            running = true;
            startRecording();
            var dlBtn = document.getElementById('tix-raffle-dl-btn');
            if (dlBtn) dlBtn.textContent = '⬇ Video herunterladen (.' + (recordingExt || 'mp4') + ')';
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
