/**
 * Tixomat – Ticket als Bild speichern / teilen
 *
 * Rendert ein Portrait-Ticket-Bild, das optisch am Online-Ticket-HTML-Template
 * orientiert ist (Header mit Logo, Event-Info, großer QR-Code, Sponsor-Footer).
 * Ein einziger Render-Pfad, entweder Download oder Web-Share.
 */

(function(){
    'use strict';

    // ═══════════════════════════════════════
    // PUBLIC
    // ═══════════════════════════════════════

    window.ehTicketImg   = function(btn) { startRender(btn, 'download'); };
    window.ehTicketShare = function(btn) { startRender(btn, 'share'); };

    // ═══════════════════════════════════════
    // PIPELINE
    // ═══════════════════════════════════════

    function startRender(btn, mode) {
        var card = btn.closest('.tix-mt-tcard');
        if (!card) return;

        var originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '\u23F3 wird erstellt…';

        var d = card.dataset;
        var qrCanvas = card.querySelector('.tix-mt-qr-canvas');

        // Bilder parallel vorladen (Event-Bild, Logo, Sponsor)
        Promise.all([
            loadImg(d.thumb),
            loadImg(d.logo),
            loadImg(d.sponsor),
        ]).then(function(imgs) {
            var c = renderCanvas(d, qrCanvas, imgs[0], imgs[1], imgs[2]);
            if (mode === 'share') {
                shareCanvas(c, d, btn, originalHTML);
            } else {
                downloadCanvas(c, d, btn, originalHTML);
            }
        });
    }

    function loadImg(src) {
        return new Promise(function(resolve) {
            if (!src) return resolve(null);
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() { resolve(img); };
            img.onerror = function() { resolve(null); };
            img.src = src;
        });
    }

    function resetButton(btn, original) {
        btn.disabled = false;
        btn.innerHTML = original;
    }

    // ═══════════════════════════════════════
    // RENDER CANVAS (konsistent mit Online-Ticket-Layout)
    // ═══════════════════════════════════════

    function renderCanvas(d, qrCanvas, eventImg, logoImg, sponsorImg) {
        // Portrait-Format — QR-Code dominiert die Fläche
        var W = 900;
        var H = sponsorImg ? 1500 : 1380;     // +120px für Sponsor, Hauptlayout bleibt identisch
        var pad = 56;

        var accentBg = d.accentBg || '#131020';
        var accentFg = d.accentFg || '#ffffff';

        var c = document.createElement('canvas');
        c.width = W; c.height = H;
        var ctx = c.getContext('2d');
        var font = '-apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
        var mono = '"SF Mono", "Fira Code", Consolas, "Courier New", monospace';

        // Hintergrund: neutraler Rand, weiße Ticket-Karte innen
        ctx.fillStyle = '#f0f0f0';
        ctx.fillRect(0, 0, W, H);
        var cardX = 20, cardY = 20;
        var cardW = W - 40;
        var cardH = H - 40;
        var radius = 20;
        drawRoundedRect(ctx, cardX, cardY, cardW, cardH, radius);
        ctx.fillStyle = '#ffffff';
        ctx.fill();

        // ── HEADER (Logo + Event-Name, Accent-Farbe) ──
        var headerH = 120;
        drawRoundedRect(ctx, cardX, cardY, cardW, headerH, radius, { topOnly: true });
        ctx.fillStyle = accentBg;
        ctx.fill();

        var headerInsetX = cardX + 30;
        var headerInsetY = cardY + 30;
        var headerInnerW = cardW - 60;

        if (logoImg) {
            // Logo links, max 56px Höhe
            var logoMaxH = 56;
            var logoRatio = logoImg.naturalWidth / logoImg.naturalHeight;
            var logoH = Math.min(logoMaxH, logoImg.naturalHeight);
            var logoW = logoH * logoRatio;
            ctx.drawImage(logoImg, headerInsetX, headerInsetY + (logoMaxH - logoH) / 2, logoW, logoH);
        } else {
            // Kein Logo: Brand-Name als Text
            ctx.fillStyle = accentFg;
            ctx.font = '700 26px ' + font;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText('TICKET', headerInsetX, headerInsetY + 28);
        }

        // Ticket-Nummer rechts im Header
        ctx.fillStyle = accentFg;
        ctx.globalAlpha = 0.65;
        ctx.font = '600 14px ' + font;
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        ctx.fillText('TICKET ' + (d.num || '1'), cardX + cardW - 30, headerInsetY + 28);
        ctx.globalAlpha = 1.0;

        // ── EVENT-BILD unter dem Header ──
        var imgY = cardY + headerH;
        var imgH = 280;
        if (eventImg) {
            var iw = eventImg.naturalWidth, ih = eventImg.naturalHeight;
            var scale = Math.max(cardW / iw, imgH / ih);
            var sw = iw * scale, sh = ih * scale;
            var sx = cardX + (cardW - sw) / 2, sy = imgY + (imgH - sh) / 2;
            ctx.save();
            ctx.beginPath();
            ctx.rect(cardX, imgY, cardW, imgH);
            ctx.clip();
            ctx.drawImage(eventImg, sx, sy, sw, sh);
            var grad = ctx.createLinearGradient(0, imgY + imgH * 0.4, 0, imgY + imgH);
            grad.addColorStop(0, 'rgba(0,0,0,0)');
            grad.addColorStop(1, 'rgba(0,0,0,0.55)');
            ctx.fillStyle = grad;
            ctx.fillRect(cardX, imgY, cardW, imgH);
            ctx.restore();

            // Event-Titel auf dem Bild
            ctx.fillStyle = '#ffffff';
            ctx.font = '700 32px ' + font;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'bottom';
            var eventText = (d.event || 'Event').toUpperCase();
            drawWrappedText(ctx, eventText, cardX + 30, imgY + imgH - 20, cardW - 60, 36, 2);
        } else {
            // Fallback: Event-Titel als großer Text auf neutralem Grund
            ctx.fillStyle = '#f8f8f8';
            ctx.fillRect(cardX, imgY, cardW, imgH);
            ctx.fillStyle = '#131020';
            ctx.font = '700 36px ' + font;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            drawWrappedText(ctx, (d.event || 'Event'), cardX + cardW / 2, imgY + imgH / 2, cardW - 80, 42, 2, 'center');
        }

        // ── QR-CODE (groß, dominant) ──
        var qrSize = 420;
        var qrY = imgY + imgH + 40;
        var qrX = cardX + (cardW - qrSize) / 2;

        if (qrCanvas) {
            // Scharf skalieren durch imageSmoothingEnabled=false
            ctx.imageSmoothingEnabled = false;
            ctx.drawImage(qrCanvas, qrX, qrY, qrSize, qrSize);
            ctx.imageSmoothingEnabled = true;
        }

        // Ticket-Code unter QR
        if (d.code) {
            ctx.fillStyle = '#131020';
            ctx.font = '700 28px ' + mono;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            ctx.fillText(d.code, cardX + cardW / 2, qrY + qrSize + 18);
        }

        // ── INFO-BLOCK (2 Spalten: Datum/Ort | Inhaber) ──
        var infoY = qrY + qrSize + 70;
        var colW = (cardW - 80) / 2;
        var col1X = cardX + 30;
        var col2X = cardX + cardW / 2 + 10;

        var infoEntries = [];
        if (d.date) {
            infoEntries.push(['Datum', d.date + (d.doors ? ' · ' + d.doors : '')]);
        }
        if (d.location) {
            infoEntries.push(['Ort', d.location]);
        }
        if (d.type) {
            infoEntries.push(['Kategorie', d.type]);
        }
        if (d.buyer) {
            infoEntries.push(['Inhaber:in', d.buyer]);
        }

        var rowH = 42;
        var col = 0;
        infoEntries.forEach(function(row, i) {
            var x = col === 0 ? col1X : col2X;
            var y = infoY + Math.floor(i / 2) * rowH;

            ctx.fillStyle = '#9ca3af';
            ctx.font = '600 11px ' + font;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'top';
            ctx.fillText(row[0].toUpperCase(), x, y);

            ctx.fillStyle = '#131020';
            ctx.font = '600 15px ' + font;
            ctx.textBaseline = 'top';
            ctx.fillText(truncate(row[1], 30), x, y + 16);

            col = col === 0 ? 1 : 0;
        });

        // ── SPONSOR (falls vorhanden) ──
        if (sponsorImg) {
            var sponsorMaxH = 100;
            var sponsorPadTop = 40;
            var sponsorY = cardY + cardH - sponsorMaxH - 40;

            // Divider
            ctx.strokeStyle = '#e5e7eb';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(cardX + 30, sponsorY - 20);
            ctx.lineTo(cardX + cardW - 30, sponsorY - 20);
            ctx.setLineDash([4, 6]);
            ctx.stroke();
            ctx.setLineDash([]);

            // Sponsor-Label
            ctx.fillStyle = '#9ca3af';
            ctx.font = '600 10px ' + font;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            ctx.fillText('IN KOOPERATION MIT', cardX + cardW / 2, sponsorY - 10);

            // Sponsor-Logo zentriert
            var spRatio = sponsorImg.naturalWidth / sponsorImg.naturalHeight;
            var spH = Math.min(sponsorMaxH, sponsorImg.naturalHeight);
            var spW = spH * spRatio;
            var maxSpW = cardW - 80;
            if (spW > maxSpW) {
                spW = maxSpW;
                spH = spW / spRatio;
            }
            ctx.drawImage(
                sponsorImg,
                cardX + (cardW - spW) / 2,
                sponsorY + 10,
                spW,
                spH
            );
        } else {
            // Ohne Sponsor: kleiner Footer-Hinweis
            ctx.fillStyle = '#9ca3af';
            ctx.font = '400 12px ' + font;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            ctx.fillText(
                'Bitte dieses Ticket ausgedruckt oder digital zum Einlass mitbringen.',
                cardX + cardW / 2,
                cardY + cardH - 30
            );
        }

        return c;
    }

    // ═══════════════════════════════════════
    // EXPORT (Download oder Share)
    // ═══════════════════════════════════════

    function downloadCanvas(c, d, btn, originalHTML) {
        try {
            c.toBlob(function(blob) {
                if (!blob) { alert('Bild konnte nicht erstellt werden.'); resetButton(btn, originalHTML); return; }
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'ticket-' + (d.code || Date.now()) + '.png';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function(){ URL.revokeObjectURL(url); }, 2000);
                resetButton(btn, originalHTML);
            }, 'image/png', 0.95);
        } catch (e) {
            alert('Fehler: ' + e.message);
            resetButton(btn, originalHTML);
        }
    }

    function shareCanvas(c, d, btn, originalHTML) {
        c.toBlob(function(blob) {
            if (!blob) { resetButton(btn, originalHTML); return; }
            var file = new File([blob], 'ticket-' + (d.code || Date.now()) + '.png', { type: 'image/png' });

            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share({
                    title: d.event || 'Mein Ticket',
                    text: 'Mein Ticket für ' + (d.event || 'das Event'),
                    files: [file],
                }).catch(function(){ /* User cancel → ignore */ })
                  .finally(function(){ resetButton(btn, originalHTML); });
            } else {
                // Fallback: Download statt Share
                downloadCanvas(c, d, btn, originalHTML);
            }
        }, 'image/png', 0.95);
    }

    // ═══════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════

    function drawRoundedRect(ctx, x, y, w, h, r, opts) {
        opts = opts || {};
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.arcTo(x + w, y, x + w, y + r, r);
        if (opts.topOnly) {
            ctx.lineTo(x + w, y + h);
            ctx.lineTo(x, y + h);
        } else {
            ctx.lineTo(x + w, y + h - r);
            ctx.arcTo(x + w, y + h, x + w - r, y + h, r);
            ctx.lineTo(x + r, y + h);
            ctx.arcTo(x, y + h, x, y + h - r, r);
        }
        ctx.lineTo(x, y + r);
        ctx.arcTo(x, y, x + r, y, r);
        ctx.closePath();
    }

    function drawWrappedText(ctx, text, x, y, maxW, lineH, maxLines, align) {
        align = align || ctx.textAlign || 'left';
        var words = text.split(' ');
        var lines = [];
        var line = '';
        for (var i = 0; i < words.length; i++) {
            var test = line ? line + ' ' + words[i] : words[i];
            if (ctx.measureText(test).width > maxW && line) {
                lines.push(line);
                line = words[i];
            } else {
                line = test;
            }
        }
        if (line) lines.push(line);
        if (maxLines && lines.length > maxLines) {
            lines = lines.slice(0, maxLines);
            var last = lines[maxLines - 1];
            while (ctx.measureText(last + '…').width > maxW && last.length > 1) last = last.slice(0, -1);
            lines[maxLines - 1] = last + '…';
        }
        // zeichnen von UNTEN nach oben wenn Baseline bottom → Reihenfolge umdrehen
        var startY = y;
        if (ctx.textBaseline === 'bottom') {
            startY = y - (lines.length - 1) * lineH;
        }
        for (var j = 0; j < lines.length; j++) {
            ctx.fillText(lines[j], x, startY + j * lineH);
        }
    }

    function truncate(str, max) {
        if (!str) return '';
        if (str.length <= max) return str;
        return str.slice(0, max - 1) + '…';
    }

})();
