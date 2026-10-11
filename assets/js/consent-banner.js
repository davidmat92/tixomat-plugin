/**
 * Tixomat – Einwilligungs-Banner. Speichert im Format von CookieConsent v3 (Cookie cc_cookie),
 * feuert tix:consentGranted und räumt beim Entzug Tracking-Cookies/-Speicher auf.
 */
(function () {
    'use strict';
    var cfg = window.tixConsentBanner;
    var el = document.getElementById('tix-consent');
    if (!cfg || !el) return;
    var KEYS = cfg.keys || { statistics: 'analytics', marketing: 'marketing' };
    // wp_localize_script liefert Zahlen als Text
    var REVISION = parseInt(cfg.revision, 10) || 0;
    var DAYS = parseInt(cfg.days, 10) || 182;
    var SECURE = cfg.secure === true || cfg.secure === '1' || cfg.secure === 1;

    function read() {
        var m = document.cookie.match(/(?:^|;\s*)cc_cookie=([^;]*)/);
        if (!m) return null;
        var v = null;
        try { v = JSON.parse(decodeURIComponent(m[1])); } catch (e) { try { v = JSON.parse(m[1]); } catch (e2) { return null; } }
        if (!v || !Array.isArray(v.categories)) return null;
        if ((parseInt(v.revision, 10) || 0) !== REVISION) return null;
        if (v.expirationTime && Date.now() > v.expirationTime) return null;
        return v;
    }
    function tixCats(v) {
        var out = [];
        if (!v) return out;
        if (v.categories.indexOf(KEYS.statistics) !== -1) out.push('statistics');
        if (v.categories.indexOf(KEYS.marketing) !== -1) out.push('marketing');
        return out;
    }
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
        });
    }
    function delCookie(name) {
        var host = location.hostname, parts = host.split('.');
        var domains = ['', host, '.' + host];
        if (cfg.domain) domains.push(cfg.domain);
        for (var i = 1; i < parts.length - 1; i++) domains.push('.' + parts.slice(i).join('.'));
        domains.forEach(function (d) {
            document.cookie = name + '=; Max-Age=0; path=/' + (d ? '; domain=' + d : '');
        });
    }
    function save(cats) {
        var prev = tixCats(read());
        var now = new Date();
        var categories = ['necessary'];
        if (cats.indexOf('statistics') !== -1) categories.push(KEYS.statistics);
        if (cats.indexOf('marketing') !== -1) categories.push(KEYS.marketing);
        var old = read();
        var services = { necessary: [] };
        services[KEYS.statistics] = []; services[KEYS.marketing] = [];
        var value = {
            categories: categories,
            revision: REVISION,
            data: null,
            consentTimestamp: (old && old.consentTimestamp) || now.toISOString(),
            consentId: (old && old.consentId) || uuid(),
            services: services,
            languageCode: 'de',
            lastConsentTimestamp: now.toISOString(),
            expirationTime: now.getTime() + DAYS * 864e5
        };
        var c = 'cc_cookie=' + encodeURIComponent(JSON.stringify(value)) + '; path=/; max-age=' + (DAYS * 86400) + '; SameSite=Lax';
        if (cfg.domain) c += '; domain=' + cfg.domain;
        if (SECURE) c += '; Secure';
        document.cookie = c;
        // Entzogene Kategorien aufräumen
        ['statistics', 'marketing'].forEach(function (k) {
            if (cats.indexOf(k) === -1 && cfg.cleanup && cfg.cleanup[k]) {
                (cfg.cleanup[k].cookies || []).forEach(delCookie);
                (cfg.cleanup[k].storage || []).forEach(function (s) { try { localStorage.removeItem(s); } catch (e) {} });
            }
        });
        hide();
        if (cats.length) document.dispatchEvent(new CustomEvent('tix:consentGranted', { detail: { categories: cats } }));
        // Entzug (vorher erteilt, jetzt nicht mehr): neu laden, damit bereits geladene Skripte weg sind
        if (prev.some(function (k) { return cats.indexOf(k) === -1; })) location.reload();
    }
    var prefs = el.querySelector('.tix-cc__prefs');
    var btnSave = el.querySelector('[data-act="save"]');
    var btnPrefs = el.querySelector('[data-act="prefs"]');
    function show(withPrefs) {
        var cur = tixCats(read());
        el.querySelectorAll('input[data-cat]').forEach(function (i) { i.checked = cur.indexOf(i.getAttribute('data-cat')) !== -1; });
        el.hidden = false;
        togglePrefs(!!withPrefs);
        var first = el.querySelector('.tix-cc__btn');
        if (first) first.focus({ preventScroll: true });
    }
    function hide() { el.hidden = true; }
    function togglePrefs(on) {
        prefs.hidden = !on; btnSave.hidden = !on; btnPrefs.hidden = on;
    }
    el.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]');
        if (!b) return;
        var act = b.getAttribute('data-act');
        if (act === 'accept') save(['statistics', 'marketing']);
        else if (act === 'reject') save([]);
        else if (act === 'prefs') togglePrefs(true);
        else if (act === 'save') {
            var cats = [];
            el.querySelectorAll('input[data-cat]').forEach(function (i) { if (i.checked) cats.push(i.getAttribute('data-cat')); });
            save(cats);
        }
    });
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-tix-consent-open], a[href="#tix-consent"]');
        if (!a) return;
        e.preventDefault();
        show(true);
    });
    var cur = read();
    if (!cur) show(false);
    else if (tixCats(cur).length) document.dispatchEvent(new CustomEvent('tix:consentGranted', { detail: { categories: tixCats(cur) } }));
    window.tixConsentBannerOpen = function () { show(true); };
})();
