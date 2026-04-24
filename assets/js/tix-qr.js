/**
 * Tixomat - QR Code Renderer
 *
 * Wrapper um qrcode-generator (Kazuhiko Arase, MIT, 2009+, de-facto Standard).
 * Der frühere Custom-Generator produzierte invalide QR-Codes, die jsQR/iOS Camera
 * nicht decodieren konnten — deshalb Switch auf die bewährte Library.
 *
 * Erwartet dass window.qrcode (aus qrcode-generator.js) geladen ist.
 */
(function(){
"use strict";

function renderCanvas(canvas) {
    var text = canvas.getAttribute("data-qr");
    if (!text) return;
    if (typeof window.qrcode !== "function") {
        console.warn("[tix-qr] qrcode-generator nicht geladen");
        return;
    }

    var qr;
    try {
        // Version 0 = auto-select, ECC Level L (kleinster QR, reicht für Ticket-Codes)
        qr = window.qrcode(0, "L");
        // Byte-Mode ist universeller als Alphanumeric (unterstützt alle Zeichen)
        qr.addData(text);
        qr.make();
    } catch(e) {
        console.warn("[tix-qr] QR-Generation fehlgeschlagen:", e);
        return;
    }

    var size = qr.getModuleCount();
    var w = canvas.width, h = canvas.height;
    var quietModules = 4; // Standard-Quiet-Zone
    var cell = Math.floor(Math.min(w, h) / (size + 2 * quietModules));
    if (cell < 2) cell = 2;
    var total = size * cell;
    var ox = Math.floor((w - total) / 2);
    var oy = Math.floor((h - total) / 2);

    var ctx = canvas.getContext("2d");
    ctx.fillStyle = "#fff";
    ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = "#000";
    for (var r = 0; r < size; r++) {
        for (var c = 0; c < size; c++) {
            if (qr.isDark(r, c)) {
                ctx.fillRect(ox + c * cell, oy + r * cell, cell, cell);
            }
        }
    }
}

/**
 * Liefert die QR-Matrix als 2D-Boolean-Array (für andere Renderer).
 */
function generate(text) {
    if (typeof window.qrcode !== "function") {
        throw new Error("qrcode-generator nicht geladen");
    }
    var qr = window.qrcode(0, "L");
    qr.addData(text);
    qr.make();
    var size = qr.getModuleCount();
    var matrix = [];
    for (var r = 0; r < size; r++) {
        matrix[r] = [];
        for (var c = 0; c < size; c++) {
            matrix[r][c] = qr.isDark(r, c) ? 1 : 0;
        }
    }
    return matrix;
}

function init() {
    var els = document.querySelectorAll("canvas.tix-mt-qr-canvas[data-qr]");
    for (var i = 0; i < els.length; i++) renderCanvas(els[i]);
}
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
else setTimeout(init, 0);

window.ehQR = { generate: generate, render: renderCanvas };
})();
