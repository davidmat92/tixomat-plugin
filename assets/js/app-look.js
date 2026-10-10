/* App-Look-Bausteine ([tix_app_*]): Suche/Chips/Kategorien filtern die Liste, Karten-Links, Folgen */
(function () {
    'use strict';
    var cfg = window.tixAppLook || {};

    function ymd(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    function addDays(base, n) {
        var d = new Date(base.getTime());
        d.setDate(d.getDate() + n);
        return d;
    }

    /** Tage je Chip; „heute“ kommt vom Server (Zeitzone der Seite). */
    function daysFor(when) {
        var p = String(cfg.today || ymd(new Date())).split('-');
        var today = new Date(+p[0], +p[1] - 1, +p[2]);
        if (when === 'today') return [ymd(today)];
        if (when === 'tomorrow') return [ymd(addDays(today, 1))];
        // Wochenende: Fr–So dieser Woche (ab heute)
        var wd = today.getDay(); // 0 = So
        var out = [];
        if (wd === 0) return [ymd(today)];
        for (var i = 0; i < 7; i++) {
            var d = addDays(today, i), w = d.getDay();
            if (w === 5 || w === 6 || w === 0) out.push(ymd(d));
            if (w === 0) break;
        }
        return out;
    }

    /**
     * Filter auf der Seite: Suche ([tix_app_search]), Kategorien ([tix_app_categories]) und die Liste
     * [tix_app_list filter="1"] liegen in getrennten Breakdance-Bausteinen. Die Überschrift mit der
     * Klasse evs-all-title zeigt den Filter, body.evs-filtered blendet z. B. „Demnächst“ aus.
     */
    function initFilter(list) {
        var rows = list.querySelectorAll('.evs-row');
        var box = list.parentNode;
        var search = document.querySelector('.evs-search__in');
        var chips = document.querySelectorAll('.evs-chip');
        var cats = document.querySelectorAll('.evs-cat');
        var title = document.querySelector('.evs-all-title');
        var reset = box.querySelector('.evs-reset');
        var empty = box.querySelector('.evs-empty');
        var deflt = title ? title.textContent : '';
        var state = { when: '', cat: '', q: '' };

        function press(els, el) {
            for (var i = 0; i < els.length; i++) {
                var on = els[i] === el;
                els[i].classList.toggle('is-on', on);
                els[i].setAttribute('aria-pressed', on ? 'true' : 'false');
            }
        }

        function apply() {
            var days = state.when ? daysFor(state.when) : null;
            var q = state.q.trim().toLowerCase();
            var shown = 0;
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i], ok = true;
                if (state.cat && r.getAttribute('data-cat') !== state.cat) ok = false;
                if (ok && q && (r.getAttribute('data-q') || '').indexOf(q) === -1) ok = false;
                if (ok && days) {
                    var have = (r.getAttribute('data-days') || '').split(' ');
                    ok = days.some(function (d) { return have.indexOf(d) !== -1; });
                }
                r.hidden = !ok;
                if (ok) shown++;
            }
            var active = !!(state.when || state.cat || q);
            document.body.classList.toggle('evs-filtered', active);
            if (reset) reset.hidden = !active;
            if (empty) empty.hidden = shown > 0;
            if (title) {
                var parts = [];
                if (state.when) {
                    var chip = document.querySelector('.evs-chip.is-on');
                    if (chip) parts.push(chip.textContent.trim());
                }
                if (state.cat) {
                    var cat = document.querySelector('.evs-cat.is-on .evs-cat__n');
                    if (cat) parts.push(cat.textContent.trim());
                }
                if (q) parts.push('„' + state.q.trim() + '“');
                title.textContent = parts.length ? parts.join(' · ') : deflt;
            }
        }

        function scrollToList() {
            var top = (title || list).getBoundingClientRect().top;
            if (top > window.innerHeight * 0.6) (title || list).scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        Array.prototype.forEach.call(chips, function (c) {
            c.addEventListener('click', function () {
                var w = c.getAttribute('data-when');
                state.when = state.when === w ? '' : w;
                press(chips, state.when ? c : null);
                apply();
            });
        });
        Array.prototype.forEach.call(cats, function (c) {
            c.addEventListener('click', function () {
                var k = c.getAttribute('data-cat');
                state.cat = state.cat === k ? '' : k;
                press(cats, state.cat ? c : null);
                apply();
                if (state.cat) scrollToList();
            });
        });
        if (search) {
            search.addEventListener('input', function () { state.q = search.value; apply(); });
            search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); scrollToList(); } });
        }
        if (reset) {
            reset.addEventListener('click', function () {
                state = { when: '', cat: '', q: '' };
                if (search) search.value = '';
                press(chips, null);
                press(cats, null);
                apply();
            });
        }
    }

    /** Ort/Adresse: auf Apple-Geräten Apple Karten statt Google Maps */
    function initMaps() {
        if (!/iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent)) return;
        var links = document.querySelectorAll('a[href^="https://www.google.com/maps/search/"]');
        for (var i = 0; i < links.length; i++) {
            var m = links[i].getAttribute('href').match(/[?&]query=([^&]*)/);
            if (m) links[i].setAttribute('href', 'https://maps.apple.com/?q=' + m[1]);
        }
    }

    function initFollow() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.evs-follow') : null;
            if (!btn || btn.disabled) return;
            e.preventDefault();
            btn.disabled = true;
            var fd = new FormData();
            fd.append('action', 'tix_app_follow');
            fd.append('nonce', cfg.nonce || '');
            fd.append('org', btn.getAttribute('data-org'));
            fd.append('back', location.href);
            fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    btn.disabled = false;
                    if (r && r.success) {
                        btn.classList.toggle('is-on', !!r.data.following);
                        btn.setAttribute('aria-pressed', r.data.following ? 'true' : 'false');
                    } else if (r && r.data && r.data.login) {
                        location.href = r.data.login;
                    }
                })
                .catch(function () { btn.disabled = false; });
        });
    }

    /** Langer Text (Klasse evs-clamp, z. B. „Info“): über 320 Zeichen auf 150 px kürzen, „Mehr lesen“ / „Weniger anzeigen“ */
    function initClamp() {
        var els = document.querySelectorAll('.evs-clamp');
        Array.prototype.forEach.call(els, function (el) {
            if ((el.textContent || '').trim().length <= 320) return;
            el.classList.add('is-clamped');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'evs-clamp__btn';
            btn.textContent = 'Mehr lesen';
            btn.addEventListener('click', function () {
                var open = el.classList.toggle('is-clamped') === false;
                btn.textContent = open ? 'Weniger anzeigen' : 'Mehr lesen';
                btn.classList.toggle('is-open', open);
            });
            el.parentNode.insertBefore(btn, el.nextSibling);
        });
    }

    /** „Einladen“ (Link #teilen): Teilen-Dialog des Geräts, sonst Link kopieren */
    function initShare() {
        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a[href="#teilen"]') : null;
            if (!a) return;
            e.preventDefault();
            var data = { title: document.title, url: location.href.split('#')[0] };
            if (navigator.share) {
                navigator.share(data).catch(function () {});
            } else if (navigator.clipboard) {
                navigator.clipboard.writeText(data.url).then(function () {
                    var t = a.querySelector('.button-atom__text') || a;
                    var old = t.textContent;
                    t.textContent = 'Link kopiert';
                    setTimeout(function () { t.textContent = old; }, 1800);
                });
            }
        });
    }

    function ready() {
        var lists = document.querySelectorAll('.evs-list--filter');
        for (var i = 0; i < lists.length; i++) initFilter(lists[i]);
        initMaps();
        initFollow();
        initClamp();
        initShare();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
