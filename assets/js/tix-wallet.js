/**
 * Tixomat – Wallet-Trigger (Apple Wallet / Google Wallet)
 *
 * Verhalten:
 *  - Buttons werden serverseitig nur gerendert wenn Wallet aktiviert + konfiguriert ist.
 *  - Klick → admin-post.php-Endpoint (TIX_Wallet) generiert .pkpass / JWT-Save-Link.
 *  - Apple → Datei-Download (.pkpass), iOS öffnet Pass-Vorschau automatisch.
 *  - Google → Redirect zu pay.google.com/gp/v/save/{jwt} (Add-to-Wallet).
 *
 * Fallback (Button hat keine ticket-id oder Setup-Link bekommen):
 *  - Zeigt freundlichen Modal mit "Bald verfügbar".
 */
(function(){
    'use strict';

    function fallbackModal(type) {
        var config = {
            apple:  { title: 'Apple Wallet',  icon: '📱', bg: '#000000', fg: '#ffffff' },
            google: { title: 'Google Wallet', icon: '💳', bg: '#1a73e8', fg: '#ffffff' }
        };
        var cfg = config[type] || config.apple;
        var existing = document.getElementById('tix-wallet-modal');
        if (existing) existing.remove();
        var modal = document.createElement('div');
        modal.id = 'tix-wallet-modal';
        modal.innerHTML =
            '<div style="position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10001;display:flex;align-items:center;justify-content:center;padding:20px;animation:tix-wallet-fade 0.2s ease;" onclick="if(event.target===this) this.parentNode.remove()">'
          +   '<div style="background:#fff;border-radius:20px;max-width:440px;width:100%;padding:36px 32px 28px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.25);">'
          +     '<div style="width:72px;height:72px;border-radius:20px;background:' + cfg.bg + ';color:' + cfg.fg + ';display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 18px;">' + cfg.icon + '</div>'
          +     '<h2 style="margin:0 0 8px;font-size:22px;color:#0f172a;">' + cfg.title + '</h2>'
          +     '<p style="margin:0 0 6px;font-size:16px;color:#131020;font-weight:600;">Bald verfügbar</p>'
          +     '<p style="margin:0 0 24px;font-size:14px;color:#64748b;line-height:1.5;">Ticket direkt in ' + cfg.title + ' speichern. Die Integration wird gerade vorbereitet.</p>'
          +     '<button type="button" onclick="this.closest(\'#tix-wallet-modal\').remove()" style="padding:12px 28px;border:none;border-radius:10px;background:#0f172a;color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;">Verstanden</button>'
          +   '</div>'
          + '</div>';

        if (!document.getElementById('tix-wallet-css')) {
            var css = document.createElement('style');
            css.id = 'tix-wallet-css';
            css.textContent = '@keyframes tix-wallet-fade{from{opacity:0}to{opacity:1}}';
            document.head.appendChild(css);
        }

        document.body.appendChild(modal);
    }

    /**
     * Triggert den Wallet-Pass-Download/Save.
     * Liest Ticket-ID aus dem Button-Element (data-ticket-id) und ruft
     * den passenden Endpoint auf. Fallback → Modal.
     */
    window.tixWalletShow = function(type, btn) {
        var ticketId = btn && btn.getAttribute && btn.getAttribute('data-ticket-id');
        ticketId = ticketId ? parseInt(ticketId, 10) : 0;

        // Wenn kein Ticket → Fallback-Modal (Setup-Hinweis)
        if (!ticketId) {
            fallbackModal(type);
            return;
        }

        // tixWalletConfig wird inline gesetzt durch class-tix-tickets.php / class-tix-my-tickets.php
        var ajaxUrl = (window.tixWalletConfig && window.tixWalletConfig.ajax) || '/wp-admin/admin-post.php';
        // Tokens werden serverseitig erzeugt — wir geben nur Action + ticket_id + token weiter
        // Für robusten Setup wird der gesamte Link beim Rendern in data-href gesetzt
        var href = btn.getAttribute('data-href');
        if (href) {
            // Apple: Direkt download. Google: redirect zur Save-URL.
            if (type === 'apple') {
                // Kurzes Loading-Feedback
                var orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = 'Lade…';
                window.location.href = href;
                setTimeout(function(){ btn.disabled = false; btn.innerHTML = orig; }, 4000);
            } else {
                window.location.href = href;
            }
            return;
        }

        // Wenn Button rendering den Link nicht beigelegt hat → Fallback
        fallbackModal(type);
    };
})();
