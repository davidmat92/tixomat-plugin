/**
 * Tixomat – Wallet-Modal (Apple Wallet / Google Wallet)
 *
 * Platzhalter — Feature ist in Entwicklung. Zeigt ein Modal mit „Bald verfügbar".
 */
(function(){
    'use strict';

    window.tixWalletShow = function(type) {
        var config = {
            apple: {
                title: 'Apple Wallet',
                icon:  '\uD83D\uDCF1',
                bg:    '#000000',
                fg:    '#ffffff',
            },
            google: {
                title: 'Google Wallet',
                icon:  '\uD83D\uDCB3',
                bg:    '#1a73e8',
                fg:    '#ffffff',
            }
        };
        var cfg = config[type] || config.apple;

        // Existing modal erneut zeigen
        var existing = document.getElementById('tix-wallet-modal');
        if (existing) existing.remove();

        var modal = document.createElement('div');
        modal.id = 'tix-wallet-modal';
        modal.innerHTML = ''
            + '<div style="position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10001;display:flex;align-items:center;justify-content:center;padding:20px;animation:tix-wallet-fade 0.2s ease;" onclick="if(event.target===this) this.parentNode.remove()">'
            +   '<div style="background:#fff;border-radius:20px;max-width:440px;width:100%;padding:36px 32px 28px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.25);">'
            +     '<div style="width:72px;height:72px;border-radius:20px;background:' + cfg.bg + ';color:' + cfg.fg + ';display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 18px;">' + cfg.icon + '</div>'
            +     '<h2 style="margin:0 0 8px;font-size:22px;color:#0f172a;">' + cfg.title + '</h2>'
            +     '<p style="margin:0 0 6px;font-size:16px;color:#131020;font-weight:600;">Bald verfügbar</p>'
            +     '<p style="margin:0 0 24px;font-size:14px;color:#64748b;line-height:1.5;">Ticket direkt in ' + cfg.title + ' speichern. Die Integration wird gerade vorbereitet.</p>'
            +     '<button type="button" onclick="this.closest(\'#tix-wallet-modal\').remove()" style="padding:12px 28px;border:none;border-radius:10px;background:#0f172a;color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;">Verstanden</button>'
            +   '</div>'
            + '</div>';

        // Animation injizieren (einmalig)
        if (!document.getElementById('tix-wallet-css')) {
            var css = document.createElement('style');
            css.id = 'tix-wallet-css';
            css.textContent = '@keyframes tix-wallet-fade{from{opacity:0}to{opacity:1}}';
            document.head.appendChild(css);
        }

        document.body.appendChild(modal);
    };
})();
