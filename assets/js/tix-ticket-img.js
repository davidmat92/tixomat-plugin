/**
 * Tixomat – Ticket als Bild speichern
 * Rendert ein Ticket-Bild (Canvas) mit Event-Bild, QR-Code,
 * Kaeuferinfo, Event-Infos und Ticket-Code.
 */
function ehTicketImg(btn) {
    var card = btn.closest('.tix-mt-tcard');
    if (!card) return;

    btn.disabled = true;
    btn.textContent = '\u23F3 Wird erstellt\u2026';

    var d = card.dataset;
    var qrCanvas = card.querySelector('.tix-mt-qr-canvas');

    // Event-Bild vorladen, dann rendern
    if (d.thumb) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() { renderTicket(d, qrCanvas, img, btn); };
        img.onerror = function() { renderTicket(d, qrCanvas, null, btn); };
        img.src = d.thumb;
    } else {
        renderTicket(d, qrCanvas, null, btn);
    }
}

function renderTicket(d, qrCanvas, eventImg, btn) {
    var W = 960, H = 1500;
    var pad = 64;
    var c = document.createElement('canvas');
    c.width = W; c.height = H;
    var ctx = c.getContext('2d');
    var font = '-apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    var mono = '"SF Mono", "Fira Code", Consolas, "Courier New", monospace';

    // Weisser Hintergrund
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);

    var y = 0;

    /* ── EVENT-BILD HEADER ── */
    var headerH = 260;
    if (eventImg) {
        var iw = eventImg.naturalWidth, ih = eventImg.naturalHeight;
        var scale = Math.max(W / iw, headerH / ih);
        var sw = iw * scale, sh = ih * scale;
        var sx = (W - sw) / 2, sy = (headerH - sh) / 2;
        ctx.save();
        ctx.beginPath();
        ctx.rect(0, 0, W, headerH);
        ctx.clip();
        ctx.drawImage(eventImg, sx, sy, sw, sh);
        var grad = ctx.createLinearGradient(0, headerH * 0.3, 0, headerH);
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(0.6, 'rgba(0,0,0,0.45)');
        grad.addColorStop(1, 'rgba(0,0,0,0.8)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, W, headerH);
        ctx.restore();
    } else {
        var grad = ctx.createLinearGradient(0, 0, W, headerH);
        grad.addColorStop(0, '#1a1a1a');
        grad.addColorStop(1, '#333333');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, W, headerH);
    }

    // Akzent-Linie oben
    ctx.fillStyle = '#c8ff00';
    ctx.fillRect(0, 0, W, 6);

    // Ticket-Nummer auf Header
    ctx.fillStyle = 'rgba(255,255,255,0.5)';
    ctx.font = '600 13px ' + font;
    ctx.textAlign = 'left';
    ctx.letterSpacing = '3px';
    ctx.fillText(('TICKET ' + (d.num || '1')).toUpperCase(), pad, headerH - 80);
    ctx.letterSpacing = '0px';

    // Event-Name auf Header
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 32px ' + font;
    var nameLines = wrapText(ctx, d.event || '', W - pad * 2);
    var ny = headerH - 50;
    for (var i = nameLines.length - 1; i >= 0; i--) {
        ctx.fillText(nameLines[i], pad, ny);
        ny -= 40;
    }

    y = headerH;

    /* ── INFO-BEREICH ── */
    y += 32;

    // Ticket-Typ
    if (d.type) {
        ctx.fillStyle = '#1a1a1a';
        ctx.font = 'bold 24px ' + font;
        ctx.textAlign = 'center';
        ctx.fillText(d.type, W / 2, y);
        y += 14;
    }

    // Kaeufername
    y += 24;
    ctx.textAlign = 'center';
    if (d.buyer) {
        ctx.fillStyle = '#444444';
        ctx.font = '500 19px ' + font;
        ctx.fillText(d.buyer, W / 2, y);
        y += 26;
    }

    // E-Mail
    if (d.email) {
        ctx.fillStyle = '#888888';
        ctx.font = '400 16px ' + font;
        ctx.fillText(d.email, W / 2, y);
        y += 12;
    }

    // Trennlinie
    y += 24;
    dashedLine(ctx, pad, y, W - pad, y);

    /* ── QR-CODE ── */
    y += 36;
    var qrSize = 380;
    var qrX = (W - qrSize) / 2;

    if (qrCanvas && qrCanvas.width > 0) {
        ctx.drawImage(qrCanvas, qrX, y, qrSize, qrSize);
    }

    // Ticket-Code
    y += qrSize + 42;
    ctx.fillStyle = '#999999';
    ctx.font = '700 22px ' + mono;
    ctx.textAlign = 'center';
    ctx.letterSpacing = '4px';
    ctx.fillText(d.code || '', W / 2, y);
    ctx.letterSpacing = '0px';

    // Trennlinie
    y += 36;
    dashedLine(ctx, pad, y, W - pad, y);

    /* ── EVENT-DETAILS ── */
    y += 34;
    ctx.textAlign = 'left';

    var details = [];
    if (d.date) details.push(['\uD83D\uDCC5', d.date]);
    if (d.doors) details.push(['\uD83D\uDEAA', d.doors]);
    if (d.time) details.push(['\uD83D\uDD50', d.time]);
    if (d.location) details.push(['\uD83D\uDCCD', d.location]);

    for (var j = 0; j < details.length; j++) {
        ctx.fillStyle = '#444444';
        ctx.font = '400 20px ' + font;
        ctx.fillText(details[j][0] + '  ' + details[j][1], pad + 10, y);
        y += 36;
    }

    /* ── FOOTER ── */
    var footerY = H - 60;
    ctx.strokeStyle = '#eeeeee';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(pad, footerY - 16);
    ctx.lineTo(W - pad, footerY - 16);
    ctx.stroke();

    ctx.fillStyle = '#bbbbbb';
    ctx.font = '400 14px ' + font;
    ctx.textAlign = 'center';
    ctx.fillText('Bitte dieses Ticket beim Einlass vorzeigen', W / 2, footerY);

    // Export
    exportTicketImage(c, d, btn);
}

/* ── Share ── */
function ehTicketShare(btn) {
    var card = btn.closest('.tix-mt-tcard');
    if (!card) return;

    btn.disabled = true;
    btn.textContent = '\u23F3 Wird erstellt\u2026';

    var d = card.dataset;
    var qrCanvas = card.querySelector('.tix-mt-qr-canvas');

    if (d.thumb) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() { renderAndShare(d, qrCanvas, img, btn); };
        img.onerror = function() { renderAndShare(d, qrCanvas, null, btn); };
        img.src = d.thumb;
    } else {
        renderAndShare(d, qrCanvas, null, btn);
    }
}

function renderAndShare(d, qrCanvas, eventImg, btn) {
    // Reuse renderTicket but override export to force share
    var W = 960, H = 1500;
    var pad = 64;
    var c = document.createElement('canvas');
    c.width = W; c.height = H;
    var ctx = c.getContext('2d');
    var font = '-apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    var mono = '"SF Mono", "Fira Code", Consolas, "Courier New", monospace';

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);

    var y = 0;
    var headerH = 260;
    if (eventImg) {
        var iw = eventImg.naturalWidth, ih = eventImg.naturalHeight;
        var scale = Math.max(W / iw, headerH / ih);
        var sw = iw * scale, sh = ih * scale;
        var sx = (W - sw) / 2, sy = (headerH - sh) / 2;
        ctx.save();
        ctx.beginPath(); ctx.rect(0, 0, W, headerH); ctx.clip();
        ctx.drawImage(eventImg, sx, sy, sw, sh);
        var grad = ctx.createLinearGradient(0, headerH * 0.3, 0, headerH);
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(0.6, 'rgba(0,0,0,0.45)');
        grad.addColorStop(1, 'rgba(0,0,0,0.8)');
        ctx.fillStyle = grad; ctx.fillRect(0, 0, W, headerH);
        ctx.restore();
    } else {
        var grad = ctx.createLinearGradient(0, 0, W, headerH);
        grad.addColorStop(0, '#1a1a1a'); grad.addColorStop(1, '#333333');
        ctx.fillStyle = grad; ctx.fillRect(0, 0, W, headerH);
    }
    ctx.fillStyle = '#c8ff00'; ctx.fillRect(0, 0, W, 6);
    ctx.fillStyle = 'rgba(255,255,255,0.5)';
    ctx.font = '600 13px ' + font; ctx.textAlign = 'left';
    ctx.letterSpacing = '3px';
    ctx.fillText(('TICKET ' + (d.num || '1')).toUpperCase(), pad, headerH - 80);
    ctx.letterSpacing = '0px';
    ctx.fillStyle = '#ffffff'; ctx.font = 'bold 32px ' + font;
    var nameLines = wrapText(ctx, d.event || '', W - pad * 2);
    var ny = headerH - 50;
    for (var i = nameLines.length - 1; i >= 0; i--) { ctx.fillText(nameLines[i], pad, ny); ny -= 40; }
    y = headerH + 32;
    if (d.type) { ctx.fillStyle = '#1a1a1a'; ctx.font = 'bold 24px ' + font; ctx.textAlign = 'center'; ctx.fillText(d.type, W / 2, y); y += 14; }
    y += 24; ctx.textAlign = 'center';
    if (d.buyer) { ctx.fillStyle = '#444444'; ctx.font = '500 19px ' + font; ctx.fillText(d.buyer, W / 2, y); y += 26; }
    if (d.email) { ctx.fillStyle = '#888888'; ctx.font = '400 16px ' + font; ctx.fillText(d.email, W / 2, y); y += 12; }
    y += 24; dashedLine(ctx, pad, y, W - pad, y);
    y += 36; var qrSize = 380, qrX = (W - qrSize) / 2;
    if (qrCanvas && qrCanvas.width > 0) {
        ctx.drawImage(qrCanvas, qrX, y, qrSize, qrSize);
    }
    y += qrSize + 42; ctx.fillStyle = '#999999'; ctx.font = '700 22px ' + mono;
    ctx.textAlign = 'center'; ctx.letterSpacing = '4px';
    ctx.fillText(d.code || '', W / 2, y); ctx.letterSpacing = '0px';
    y += 36; dashedLine(ctx, pad, y, W - pad, y);
    y += 34; ctx.textAlign = 'left';
    var details = [];
    if (d.date) details.push(['\uD83D\uDCC5', d.date]);
    if (d.doors) details.push(['\uD83D\uDEAA', d.doors]);
    if (d.time) details.push(['\uD83D\uDD50', d.time]);
    if (d.location) details.push(['\uD83D\uDCCD', d.location]);
    for (var j = 0; j < details.length; j++) { ctx.fillStyle = '#444444'; ctx.font = '400 20px ' + font; ctx.fillText(details[j][0] + '  ' + details[j][1], pad + 10, y); y += 36; }
    var footerY = H - 60;
    ctx.strokeStyle = '#eeeeee'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(pad, footerY - 16); ctx.lineTo(W - pad, footerY - 16); ctx.stroke();
    ctx.fillStyle = '#bbbbbb'; ctx.font = '400 14px ' + font; ctx.textAlign = 'center';
    ctx.fillText('Bitte dieses Ticket beim Einlass vorzeigen', W / 2, footerY);

    // Share
    var filename = 'ticket-' + (d.code || 'unknown').replace(/[^a-zA-Z0-9\-]/g, '_') + '.png';
    c.toBlob(function(blob) {
        if (!blob) { resetShareBtn(btn); return; }
        if (navigator.share && navigator.canShare) {
            var file = new File([blob], filename, { type: 'image/png' });
            if (navigator.canShare({ files: [file] })) {
                navigator.share({
                    files: [file],
                    title: (d.event || 'Ticket') + ' – ' + (d.code || ''),
                    text: 'Mein Ticket: ' + (d.event || '') + (d.type ? ' (' + d.type + ')' : '') + '\n' + (d.date || '')
                }).then(function() { resetShareBtn(btn); })
                  .catch(function() { resetShareBtn(btn); });
                return;
            }
        }
        // Fallback: clipboard or download
        downloadBlob(blob, filename);
        resetShareBtn(btn);
    }, 'image/png');
}

function resetShareBtn(btn) {
    btn.disabled = false;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v7a2 2 0 002 2h12a2 2 0 002-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> Teilen';
}

/* ── Export ── */
function exportTicketImage(canvas, data, btn) {
    var filename = 'ticket-' + (data.code || 'unknown').replace(/[^a-zA-Z0-9\-]/g, '_') + '.png';

    canvas.toBlob(function(blob) {
        if (!blob) { resetBtn(btn); return; }

        if (navigator.share && navigator.canShare) {
            var file = new File([blob], filename, { type: 'image/png' });
            if (navigator.canShare({ files: [file] })) {
                navigator.share({
                    files: [file],
                    title: data.event || 'Ticket',
                    text: 'Ticket ' + (data.code || '')
                }).then(function() { resetBtn(btn); })
                  .catch(function() {
                    downloadBlob(blob, filename);
                    resetBtn(btn);
                });
                return;
            }
        }

        downloadBlob(blob, filename);
        resetBtn(btn);
    }, 'image/png');
}

function resetBtn(btn) {
    btn.disabled = false;
    btn.innerHTML = '&#128247; Als Bild speichern';
}

function downloadBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function() { URL.revokeObjectURL(url); }, 5000);
}

function wrapText(ctx, text, maxWidth) {
    var words = text.split(' ');
    var lines = [];
    var line = '';
    for (var i = 0; i < words.length; i++) {
        var test = line ? line + ' ' + words[i] : words[i];
        if (ctx.measureText(test).width > maxWidth && line) {
            lines.push(line);
            line = words[i];
        } else {
            line = test;
        }
    }
    if (line) lines.push(line);
    return lines.length ? lines : [text];
}

function dashedLine(ctx, x1, y1, x2, y2) {
    ctx.save();
    ctx.setLineDash([8, 6]);
    ctx.strokeStyle = '#e0e0e0';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.stroke();
    ctx.restore();
}

function roundRect(ctx, x, y, w, h, r, fill, stroke) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
    if (fill) ctx.fill();
    if (stroke) ctx.stroke();
}
