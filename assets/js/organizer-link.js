/**
 * Tixomat – „Dein Link“ im Veranstalter-Dashboard (Mehr-Veranstalter-Modus, wie in der evendis-App).
 * Kopieren, Teilen, QR-Code als PNG/PDF (Vektor), Story-Bild 9:16 – alles im Browser, ohne Server-Aufruf.
 * Erwartet window.qrcode (assets/js/qrcode-generator.js).
 */
(function () {
    'use strict';

    var root = document.getElementById('tix-od-link');
    if (!root) return;
    var info;
    try { info = JSON.parse(root.getAttribute('data-link') || '{}'); } catch (e) { return; }

    var INK = '#2C2F3F', RED = '#FC2F55', RED2 = '#E8445A';
    var FONT = "Manrope, Outfit, Inter, system-ui, -apple-system, sans-serif";

    function matrix() {
        if (typeof window.qrcode !== 'function') return null;
        var qr = window.qrcode(0, 'M');
        qr.addData(info.url);
        qr.make();
        return qr;
    }

    /** QR-Module auf einen Canvas zeichnen (x, y = linke obere Ecke, size = Kantenlänge ohne Ruhezone). */
    function drawQr(ctx, qr, x, y, size, color) {
        var n = qr.getModuleCount(), cell = size / n;
        ctx.fillStyle = color || INK;
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) ctx.fillRect(Math.floor(x + c * cell), Math.floor(y + r * cell), Math.ceil(cell), Math.ceil(cell));
            }
        }
    }

    function toast(btn, text) {
        var span = btn.querySelector('span') || btn;
        var old = span.textContent;
        span.textContent = text;
        btn.disabled = true;
        setTimeout(function () { span.textContent = old; btn.disabled = false; }, 1600);
    }

    function copy(btn) {
        var done = function () { toast(btn, 'Link kopiert.'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(info.url).then(done, fallback);
        } else {
            fallback();
        }
        function fallback() {
            var t = document.createElement('textarea');
            t.value = info.url; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
            document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); done(); } catch (e) { window.prompt('Link kopieren:', info.url); }
            document.body.removeChild(t);
        }
    }

    function share(btn) {
        if (navigator.share) {
            navigator.share({ title: info.name, text: info.share_text, url: info.url }).catch(function () {});
        } else {
            copy(btn);
        }
    }

    function download(blob, name) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    function fileBase() { return 'evendis-' + (info.slug || 'link'); }

    /** Text einkürzen, bis er in maxW passt. */
    function fit(ctx, text, maxW) {
        if (ctx.measureText(text).width <= maxW) return text;
        while (text.length > 1 && ctx.measureText(text + '…').width > maxW) text = text.slice(0, -1);
        return text + '…';
    }

    /** Text in höchstens maxLines Zeilen umbrechen. */
    function wrap(ctx, text, maxW, maxLines) {
        var words = String(text).split(/\s+/), lines = [], line = '';
        for (var i = 0; i < words.length; i++) {
            var test = line ? line + ' ' + words[i] : words[i];
            if (ctx.measureText(test).width > maxW && line) { lines.push(line); line = words[i]; } else { line = test; }
        }
        if (line) lines.push(line);
        if (lines.length > maxLines) { lines = lines.slice(0, maxLines); lines[maxLines - 1] = fit(ctx, lines[maxLines - 1] + '…', maxW); }
        return lines;
    }

    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
    }

    /** PNG: weißer QR-Code für Flyer, darunter „Tickets: Name“ und der Link (wie die App). */
    function png() {
        var qr = matrix(); if (!qr) return;
        var W = 1200, H = 1400, pad = 100, size = W - 2 * pad;
        var cv = document.createElement('canvas'); cv.width = W; cv.height = H;
        var ctx = cv.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
        drawQr(ctx, qr, pad, pad, size, INK);
        ctx.fillStyle = INK; ctx.textAlign = 'center';
        ctx.font = '800 56px ' + FONT;
        ctx.fillText(fit(ctx, 'Tickets: ' + info.name, W - 2 * pad), W / 2, pad + size + 100);
        ctx.fillStyle = '#6B6F82'; ctx.font = '700 44px ' + FONT;
        ctx.fillText(fit(ctx, info.short, W - 2 * pad), W / 2, pad + size + 165);
        cv.toBlob(function (b) { if (b) download(b, fileBase() + '-qr.png'); }, 'image/png');
    }

    /** PDF (A5 hochkant, Vektor – druckt scharf in jeder Größe), Schrift Helvetica (WinAnsi). */
    function pdf() {
        var qr = matrix(); if (!qr) return;
        var PW = 420, PH = 595, size = 300, x0 = (PW - size) / 2, y0 = PH - 70 - size;
        var n = qr.getModuleCount(), cell = size / n, ops = [];
        ops.push('0.172 0.184 0.247 rg');
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) ops.push((x0 + c * cell).toFixed(2) + ' ' + (y0 + size - (r + 1) * cell).toFixed(2) + ' ' + cell.toFixed(3) + ' ' + cell.toFixed(3) + ' re');
            }
        }
        ops.push('f');
        var esc = function (s) {
            var out = '';
            for (var i = 0; i < s.length; i++) {
                var ch = s.charAt(i), code = s.charCodeAt(i);
                if (ch === '(' || ch === ')' || ch === '\\') out += '\\' + ch;
                else if (code === 0x2013 || code === 0x2014) out += '\\226';
                else if (code === 0x2026) out += '...';
                else if (code === 0x20AC) out += '\\200';
                else if (code < 32) out += ' ';
                else if (code < 128) out += ch;
                else if (code < 256) out += '\\' + code.toString(8);
                else out += '?';
            }
            return out;
        };
        // Breite grob abschätzen (Helvetica-Bold ≈ 0,58 em, normal ≈ 0,5 em), lange Zeilen erst kleiner, dann kürzen
        var text = function (s, sz, y, bold, gray) {
            var f = bold ? 0.58 : 0.5, maxW = PW - 60;
            if (s.length * sz * f > maxW) sz = Math.max(13, Math.floor(maxW / (s.length * f)));
            var maxChars = Math.floor(maxW / (sz * f));
            if (s.length > maxChars) s = s.slice(0, maxChars - 3) + '...';
            var w = s.length * sz * f;
            ops.push('BT ' + (gray ? '0.42 0.44 0.51 rg' : '0.172 0.184 0.247 rg') + ' /' + (bold ? 'F2' : 'F1') + ' ' + sz + ' Tf ' + Math.max(30, (PW - w) / 2).toFixed(1) + ' ' + y + ' Td (' + esc(s) + ') Tj ET');
        };
        text('Tickets: ' + info.name, 22, y0 - 50, true, false);
        text(info.short, 16, y0 - 78, true, true);
        text('Alle Events & Tickets – scannen oder Link eingeben', 11, 60, false, true);
        var stream = ops.join('\n');
        var objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + PW + ' ' + PH + '] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream'
        ];
        var out = '%PDF-1.4\n', offs = [];
        for (var i = 0; i < objs.length; i++) { offs.push(out.length); out += (i + 1) + ' 0 obj\n' + objs[i] + '\nendobj\n'; }
        var xref = out.length;
        out += 'xref\n0 ' + (objs.length + 1) + '\n0000000000 65535 f \n';
        for (var j = 0; j < offs.length; j++) out += ('0000000000' + offs[j]).slice(-10) + ' 00000 n \n';
        out += 'trailer\n<< /Size ' + (objs.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF';
        var bytes = new Uint8Array(out.length);
        for (var k = 0; k < out.length; k++) bytes[k] = out.charCodeAt(k) & 0xff;
        download(new Blob([bytes], { type: 'application/pdf' }), fileBase() + '-qr.pdf');
    }

    function loadImg(src) {
        return new Promise(function (resolve) {
            if (!src) return resolve(null);
            var im = new Image();
            im.crossOrigin = 'anonymous';
            im.onload = function () { resolve(im); };
            im.onerror = function () { resolve(null); };
            im.src = src;
        });
    }

    /** Story 9:16 (1080 × 1920): rote Markenfläche, Titelbild/Logo, Name, Stadt, QR, Link – wie die App. */
    function story(btn) {
        var qr = matrix(); if (!qr) return;
        btn.disabled = true;
        Promise.all([loadImg(info.hero), info.hero ? Promise.resolve(null) : loadImg(info.logo)]).then(function (imgs) {
            var W = 1080, H = 1920, P = 72;
            var cv = document.createElement('canvas'); cv.width = W; cv.height = H;
            var ctx = cv.getContext('2d');
            var g = ctx.createLinearGradient(0, 0, 0, H); g.addColorStop(0, RED); g.addColorStop(1, RED2);
            ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
            ctx.fillStyle = '#fff'; ctx.textAlign = 'center';
            ctx.font = '800 72px ' + FONT; ctx.fillText('evendis', W / 2, 190);
            // Titelbild 16:9 (oder Logo im Kreis)
            var bx = P, by = 270, bw = W - 2 * P, bh = Math.round(bw * 9 / 16);
            ctx.save(); roundRect(ctx, bx, by, bw, bh, 60); ctx.clip();
            ctx.fillStyle = 'rgba(255,255,255,0.16)'; ctx.fillRect(bx, by, bw, bh);
            try {
                if (imgs[0]) {
                    var im = imgs[0], s = Math.max(bw / im.width, bh / im.height);
                    ctx.drawImage(im, bx + (bw - im.width * s) / 2, by + (bh - im.height * s) / 2, im.width * s, im.height * s);
                } else if (imgs[1]) {
                    var d = 288, cx = W / 2, cy = by + bh / 2;
                    ctx.save(); ctx.beginPath(); ctx.arc(cx, cy, d / 2, 0, Math.PI * 2); ctx.clip();
                    ctx.drawImage(imgs[1], cx - d / 2, cy - d / 2, d, d); ctx.restore();
                }
            } catch (e) { /* fremde Bildquelle: ohne Bild weiter */ }
            ctx.restore();
            var y = by + bh + 130;
            ctx.fillStyle = '#fff'; ctx.font = '800 84px ' + FONT;
            wrap(ctx, info.name, W - 2 * P, 2).forEach(function (l) { ctx.fillText(l, W / 2, y); y += 96; });
            if (info.city) { ctx.globalAlpha = 0.9; ctx.font = '600 48px ' + FONT; ctx.fillText(fit(ctx, info.city, W - 2 * P), W / 2, y + 4); ctx.globalAlpha = 1; y += 70; }
            ctx.font = '700 54px ' + FONT; ctx.fillText('Alle Events & Tickets', W / 2, y + 20);
            // QR unten in weißer Box – Größe nach verfügbarem Platz (lange Namen, Stadt)
            var qp = 42, qs = Math.max(300, Math.min(450, H - 200 - (y + 90) - 2 * qp)), qx = (W - qs - 2 * qp) / 2, qy = H - 200 - qs - 2 * qp;
            ctx.fillStyle = '#fff'; roundRect(ctx, qx, qy, qs + 2 * qp, qs + 2 * qp, 60); ctx.fill();
            drawQr(ctx, qr, qx + qp, qy + qp, qs, INK);
            ctx.fillStyle = '#fff'; ctx.font = '800 54px ' + FONT;
            ctx.fillText(fit(ctx, info.short, W - 2 * P), W / 2, H - 110);
            var done = function (b) { btn.disabled = false; if (b) download(b, fileBase() + '-story.png'); };
            try { cv.toBlob(done, 'image/png'); } catch (e) {
                // Bild einer fremden Quelle macht den Canvas unlesbar → ohne Bild neu zeichnen
                info.hero = ''; info.logo = ''; btn.disabled = false; story(btn);
            }
        });
    }

    // Vorschau im Dashboard
    var prev = root.querySelector('[data-qr-preview]');
    if (prev) {
        var q = matrix();
        if (q) {
            var pc = prev.getContext('2d');
            pc.fillStyle = '#fff'; pc.fillRect(0, 0, prev.width, prev.height);
            drawQr(pc, q, 0, 0, prev.width, INK);
        }
    }

    root.addEventListener('click', function (e) {
        var el = e.target.closest('[data-act]');
        if (!el || !root.contains(el)) return;
        var act = el.getAttribute('data-act');
        if (act === 'new-event') {
            e.preventDefault();
            var tab = document.querySelector('.tix-od-tab[data-tab="events"]');
            if (tab) tab.click();
            var nb = document.getElementById('tix-od-new-event');
            if (nb) setTimeout(function () { nb.click(); }, 50);
            return;
        }
        if (act === 'copy') copy(el);
        else if (act === 'share') share(el);
        else if (act === 'png') png();
        else if (act === 'pdf') pdf();
        else if (act === 'story') story(el);
    });
})();
