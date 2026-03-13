/**
 * Tixomat Campaign Tracking Pixel
 * Lightweight (~1.5KB): URL-Param parsing, cookie attribution, pageview AJAX.
 */
(function () {
    'use strict';

    var cfg = window.tixCampaign;
    if (!cfg || !cfg.eventId) return;

    var params = new URLSearchParams(window.location.search);

    // 1. Parse URL params (tix_src hat Prio, utm_source als Fallback)
    var src     = params.get('tix_src') || params.get('utm_source') || '';
    var camp    = params.get('tix_camp') || params.get('utm_campaign') || '';
    var content = params.get('tix_content') || params.get('utm_content') || '';

    // 2. Cookie: First-Touch Attribution (nur setzen wenn keiner existiert UND source vorhanden)
    var existing = getCookie(cfg.cookieName);
    if (!existing && src) {
        var cookieData = JSON.stringify({
            src: src,
            camp: camp,
            content: content,
            ts: Date.now()
        });
        setCookie(cfg.cookieName, cookieData, cfg.cookieDays || 30);
    }

    // 3. Source bestimmen: URL-Param > Cookie > 'direct'
    var trackSrc = src;
    var trackCamp = camp;
    var trackContent = content;

    if (!trackSrc && existing) {
        try {
            var parsed = JSON.parse(decodeURIComponent(existing));
            trackSrc     = parsed.src || '';
            trackCamp    = trackCamp || parsed.camp || '';
            trackContent = trackContent || parsed.content || '';
        } catch (e) { /* ignore */ }
    }

    // 4. Pageview AJAX senden
    var fd = new FormData();
    fd.append('action', 'tix_campaign_pageview');
    fd.append('nonce', cfg.nonce);
    fd.append('event_id', cfg.eventId);
    fd.append('source', trackSrc || 'direct');
    fd.append('campaign', trackCamp);
    fd.append('content', trackContent);

    fetch(cfg.ajaxUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    }).catch(function () {});

    // ── Helpers ──

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
        return m ? m[1] : '';
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';path=/;expires=' + d.toUTCString() + ';SameSite=Lax';
    }
})();
