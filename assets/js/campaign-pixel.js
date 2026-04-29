/**
 * Tixomat Campaign Tracking Pixel
 * Lightweight (~2.5KB): URL-Param + Referrer-Detection + Cookie-Attribution + Pageview-AJAX.
 *
 * Quellen-Priorität (First-Touch):
 *  1. tix_src / utm_source URL-Parameter (manuell gesetzt)
 *  2. document.referrer → mapped zu Channel-Slug (google, facebook, instagram, …)
 *  3. Bestehender Cookie (First-Touch beibehalten — älteste Quelle gewinnt)
 *  4. 'direct' (kein Referrer = Direkteingabe)
 */
(function () {
    'use strict';

    var cfg = window.tixCampaign;
    if (!cfg) return;

    var params = new URLSearchParams(window.location.search);

    // 1. URL-Params (höchste Priorität für Erst-Quelle)
    var src     = (params.get('tix_src') || params.get('utm_source') || '').toLowerCase();
    var camp    = params.get('tix_camp') || params.get('utm_campaign') || '';
    var content = params.get('tix_content') || params.get('utm_content') || '';
    var medium  = (params.get('utm_medium') || '').toLowerCase();

    // 2. Referrer-Detection als Fallback wenn keine URL-Quelle gesetzt
    if (!src && document.referrer) {
        try {
            var refUrl  = new URL(document.referrer);
            var refHost = refUrl.hostname.toLowerCase();
            var ownHost = window.location.hostname.toLowerCase();

            // Skip wenn Referrer = eigene Domain (interne Navigation)
            var isExternal = refHost && refHost !== ownHost
                && refHost.indexOf(ownHost) === -1
                && ownHost.indexOf(refHost) === -1;

            if (isExternal) {
                var detected = detectChannel(refHost);
                if (detected) {
                    src = detected.src;
                    if (!camp && detected.camp) camp = detected.camp;
                } else {
                    // Unbekannter Referrer → speichere Hostname als Quelle
                    src  = 'referral';
                    if (!camp) camp = refHost;
                }
            }
        } catch (e) { /* invalid URL */ }
    }

    var existing = getCookie(cfg.cookieName);

    // 3. First-Touch: nur Cookie setzen wenn keiner existiert UND wir eine Quelle haben
    if (!existing && src) {
        var cookieData = JSON.stringify({
            src: src,
            camp: camp,
            content: content,
            medium: medium,
            referrer: document.referrer || '',
            ts: Date.now()
        });
        setCookie(cfg.cookieName, cookieData, cfg.cookieDays || 30);
    }

    // 4. Track-Source bestimmen (Cookie hat Vorrang wenn vorhanden — First-Touch)
    var trackSrc = src || 'direct';
    var trackCamp = camp;
    var trackContent = content;

    if (existing) {
        try {
            var parsed = JSON.parse(decodeURIComponent(existing));
            trackSrc     = parsed.src || trackSrc;
            trackCamp    = parsed.camp || trackCamp;
            trackContent = parsed.content || trackContent;
        } catch (e) { /* ignore */ }
    }

    // 5. Pageview-AJAX nur auf Event-Seiten senden (für Statistik-Aggregat)
    if (cfg.eventId) {
        var fd = new FormData();
        fd.append('action', 'tix_campaign_pageview');
        fd.append('nonce', cfg.nonce);
        fd.append('event_id', cfg.eventId);
        fd.append('source', trackSrc);
        fd.append('campaign', trackCamp);
        fd.append('content', trackContent);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
    }

    // ──────────────────────────────────────────
    // Channel-Detection aus Referrer-Hostname
    // ──────────────────────────────────────────
    function detectChannel(host) {
        var rules = [
            // Suchmaschinen (organic)
            { pattern: /(?:^|\.)google\./,                src: 'google_organic',     camp: 'organic' },
            { pattern: /(?:^|\.)bing\./,                  src: 'bing_organic',       camp: 'organic' },
            { pattern: /(?:^|\.)duckduckgo\./,            src: 'duckduckgo_organic', camp: 'organic' },
            { pattern: /(?:^|\.)ecosia\./,                src: 'ecosia_organic',     camp: 'organic' },
            { pattern: /(?:^|\.)startpage\./,             src: 'startpage_organic',  camp: 'organic' },
            { pattern: /(?:^|\.)yahoo\./,                 src: 'yahoo_organic',      camp: 'organic' },
            { pattern: /(?:^|\.)yandex\./,                src: 'yandex_organic',     camp: 'organic' },

            // Social Media
            { pattern: /(?:^|\.)instagram\.com$|^l\.instagram\.com$/,                                              src: 'instagram' },
            { pattern: /(?:^|\.)facebook\.com$|^l\.facebook\.com$|^lm\.facebook\.com$|^m\.facebook\.com$/,         src: 'facebook' },
            { pattern: /(?:^|\.)tiktok\.com$/,                                                                     src: 'tiktok' },
            { pattern: /(?:^|\.)twitter\.com$|(?:^|\.)x\.com$|(?:^|\.)t\.co$/,                                     src: 'twitter' },
            { pattern: /(?:^|\.)linkedin\.com$|^lnkd\.in$/,                                                        src: 'linkedin' },
            { pattern: /(?:^|\.)xing\.com$/,                                                                       src: 'xing' },
            { pattern: /(?:^|\.)youtube\.com$|^youtu\.be$/,                                                        src: 'youtube' },
            { pattern: /(?:^|\.)pinterest\.com$|^pin\.it$/,                                                        src: 'pinterest' },
            { pattern: /(?:^|\.)reddit\.com$/,                                                                     src: 'reddit' },
            { pattern: /(?:^|\.)snapchat\.com$/,                                                                   src: 'snapchat' },
            { pattern: /(?:^|\.)threads\.net$/,                                                                    src: 'threads' },

            // Messenger
            { pattern: /(?:^|\.)whatsapp\.com$|wa\.me$/,        src: 'whatsapp' },
            { pattern: /(?:^|\.)t\.me$|telegram\.me$/,          src: 'telegram' },
            { pattern: /(?:^|\.)signal\.org$/,                  src: 'signal' },
            { pattern: /messenger\.com$/,                       src: 'messenger' },

            // Email
            { pattern: /mail\.google\.com$|^gmail\./,           src: 'email', camp: 'gmail' },
            { pattern: /outlook\.live\.com$|outlook\.office\./, src: 'email', camp: 'outlook' },
            { pattern: /web\.de$|gmx\./,                        src: 'email', camp: 'webde-gmx' },

            // Event-Plattformen / Mitbewerber
            { pattern: /(?:^|\.)eventim\./,                     src: 'eventim' },
            { pattern: /(?:^|\.)eventbrite\./,                  src: 'eventbrite' },
            { pattern: /(?:^|\.)ticketmaster\./,                src: 'ticketmaster' }
        ];

        for (var i = 0; i < rules.length; i++) {
            if (rules[i].pattern.test(host)) {
                return { src: rules[i].src, camp: rules[i].camp || '' };
            }
        }
        return null;
    }

    // ──────────────────────────────────────────
    // Cookie-Helpers
    // ──────────────────────────────────────────
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
