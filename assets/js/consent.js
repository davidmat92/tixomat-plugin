/**
 * Tixomat – Einwilligung im Browser (Gegenstück zu TIX_Consent).
 * window.tixConsent.has('statistics'|'marketing') und .onGrant(kategorie, callback).
 * Erkennt Cookiebot, CookieConsent v3 (cc_cookie), Borlabs, eingestellte Consent-Cookies
 * und das Ereignis tix:consentGranted (detail.categories = ['statistics', …]).
 */
(function () {
    'use strict';
    var cfg = window.tixConsentCfg || { aliases: {}, cookies: [] };
    var aliases = cfg.aliases || {};

    function cookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return m ? m[1] : '';
    }
    function json(v) { try { return JSON.parse(decodeURIComponent(v)); } catch (e) { try { return JSON.parse(v); } catch (e2) { return null; } } }
    function list(cat) { return (aliases[cat] || [cat]).map(function (a) { return String(a).toLowerCase(); }); }
    function hit(names, cat) {
        var want = list(cat);
        return (names || []).some(function (n) { return want.indexOf(String(n).toLowerCase()) !== -1; });
    }
    var granted = {};

    function has(cat) {
        cat = cat === 'marketing' ? 'marketing' : 'statistics';
        if (granted[cat]) return true;
        try {
            if (window.Cookiebot && window.Cookiebot.consent && window.Cookiebot.consent[cat]) return true;
        } catch (e) {}
        var cb = cookie('CookieConsent');
        if (cb && new RegExp('[{,]\\s*' + cat + '\\s*:\\s*true', 'i').test(decodeURIComponent(cb))) return true;
        try {
            if (window.CookieConsent && typeof window.CookieConsent.acceptedCategory === 'function') {
                if (list(cat).some(function (c) { return window.CookieConsent.acceptedCategory(c); })) return true;
            }
        } catch (e) {}
        var cc = json(cookie('cc_cookie'));
        if (cc && hit(cc.categories || cc.level, cat)) return true;
        var bl = json(cookie('borlabs-cookie'));
        if (bl && bl.consents && hit(Object.keys(bl.consents), cat)) return true;
        return (cfg.cookies || []).some(function (n) { var v = cookie(n); return v && v !== '0' && v !== 'false'; });
    }

    var waiting = [];
    function flush() {
        waiting = waiting.filter(function (w) {
            if (has(w.cat)) { try { w.cb(); } catch (e) {} return false; }
            return true;
        });
    }
    function onGrant(cat, cb) {
        if (has(cat)) { cb(); return; }
        waiting.push({ cat: cat, cb: cb });
    }

    document.addEventListener('tix:consentGranted', function (e) {
        var cats = (e && e.detail && e.detail.categories) || ['statistics', 'marketing'];
        cats.forEach(function (c) { granted[c === 'marketing' ? 'marketing' : 'statistics'] = true; });
        flush();
    });
    window.addEventListener('CookiebotOnAccept', flush);
    window.addEventListener('cc:onConsent', flush);
    window.addEventListener('cc:onChange', flush);
    document.addEventListener('borlabs-cookie-consent-saved', flush);

    window.tixConsent = { has: has, onGrant: onGrant };
})();
