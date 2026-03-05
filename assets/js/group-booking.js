(function() {
    'use strict';

    if (typeof ehGroup === 'undefined') return;

    document.addEventListener('DOMContentLoaded', function() {
        var sel = document.querySelector('.tix-sel[data-event-id]');
        if (!sel) return;

        var panel    = sel.querySelector('.tix-group-panel');
        var trigger  = sel.querySelector('.tix-group-btn');
        if (!panel) return;

        // ── State ──
        var state = {
            token:      ehGroup.groupToken || null,
            adminToken: null,
            memberId:   null,
            isOrganizer: false,
            pollTimer:  null,
            hasAdded:   false,
        };

        // localStorage Keys
        function lsKey(suffix) { return 'tix_group_' + state.token + '_' + suffix; }

        // ── Init ──
        if (state.token) {
            // Gruppen-Modus: Link wurde geteilt
            initGroupMode();
        } else if (trigger) {
            // Normal: "Gemeinsam buchen" Button zeigen
            trigger.addEventListener('click', showCreateForm);
        }

        // ══════════════════════════════════════
        // Gruppe erstellen
        // ══════════════════════════════════════

        function showCreateForm() {
            trigger.style.display = 'none';
            panel.style.display = '';
            panel.innerHTML =
                '<div class="tix-group-create">' +
                    '<div class="tix-group-create-title">Gemeinsam buchen</div>' +
                    '<p class="tix-group-create-desc">Erstelle eine Gruppenbestellung und teile den Link mit deinen Freunden.</p>' +
                    '<div class="tix-group-create-form">' +
                        '<input type="text" class="tix-group-name-input" placeholder="Dein Name" maxlength="50" autocomplete="given-name">' +
                        '<button type="button" class="tix-group-create-btn">Gruppe erstellen</button>' +
                    '</div>' +
                    '<div class="tix-group-create-msg" style="display:none;"></div>' +
                '</div>';

            var nameInput = panel.querySelector('.tix-group-name-input');
            var createBtn = panel.querySelector('.tix-group-create-btn');
            var msgEl     = panel.querySelector('.tix-group-create-msg');

            nameInput.focus();

            nameInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); createBtn.click(); }
            });

            createBtn.addEventListener('click', function() {
                var name = nameInput.value.trim();
                if (!name) {
                    nameInput.classList.add('tix-group-input-error');
                    nameInput.focus();
                    return;
                }
                nameInput.classList.remove('tix-group-input-error');
                createBtn.disabled = true;
                createBtn.textContent = 'Wird erstellt…';

                ajaxPost('tix_group_create', {
                    event_id: sel.dataset.eventId,
                    name: name
                }, function(data) {
                    state.token      = data.token;
                    state.adminToken = data.admin_token;
                    state.memberId   = data.member_id;
                    state.isOrganizer = true;

                    // In localStorage speichern
                    try {
                        localStorage.setItem(lsKey('admin'), state.adminToken);
                        localStorage.setItem(lsKey('member'), state.memberId);
                    } catch(e) {}

                    // URL aktualisieren (ohne Reload)
                    var url = new URL(window.location.href);
                    url.searchParams.set('tix_group', state.token);
                    history.replaceState(null, '', url.toString());

                    renderOrganizer(data.share_url, data.group_html);
                    activateGroupMode();
                    startPolling();
                }, function(msg) {
                    createBtn.disabled = false;
                    createBtn.textContent = 'Gruppe erstellen';
                    msgEl.textContent = msg;
                    msgEl.style.display = '';
                });
            });
        }

        // ══════════════════════════════════════
        // Gruppen-Modus initialisieren (Link geöffnet)
        // ══════════════════════════════════════

        function initGroupMode() {
            // Restore state from localStorage
            try {
                state.adminToken = localStorage.getItem(lsKey('admin')) || null;
                state.memberId   = localStorage.getItem(lsKey('member')) || null;
            } catch(e) {}

            state.isOrganizer = !!state.adminToken;

            if (trigger) trigger.style.display = 'none';
            panel.style.display = '';

            // Status abrufen für initiale Anzeige
            ajaxPost('tix_group_status', {
                token: state.token,
                admin_token: state.adminToken || '',
                member_id: state.memberId || ''
            }, function(data) {
                if (data.status === 'completed') {
                    panel.innerHTML = '<div class="tix-group-completed">Diese Gruppenbestellung wurde bereits abgeschlossen.</div>';
                    return;
                }

                if (state.isOrganizer) {
                    var shareUrl = window.location.href.split('?')[0] + '?tix_group=' + state.token;
                    renderOrganizer(shareUrl, data.group_html);
                } else {
                    renderMember(data.group_html);
                }

                activateGroupMode();
                startPolling();
            }, function() {
                panel.innerHTML = '<div class="tix-group-error">Gruppe nicht gefunden oder abgelaufen.</div>';
            });
        }

        // ══════════════════════════════════════
        // Organisator-View
        // ══════════════════════════════════════

        function renderOrganizer(shareUrl, groupHtml) {
            panel.innerHTML =
                '<div class="tix-group-header">' +
                    '<span class="tix-group-header-icon">👥</span>' +
                    '<span class="tix-group-header-title">Gruppenbestellung</span>' +
                '</div>' +
                '<div class="tix-group-share">' +
                    '<label class="tix-group-share-label">Link teilen:</label>' +
                    '<div class="tix-group-share-row">' +
                        '<input type="text" class="tix-group-share-url" value="' + escAttr(shareUrl) + '" readonly>' +
                        '<button type="button" class="tix-group-copy-btn" title="Kopieren">📋</button>' +
                    '</div>' +
                    '<div class="tix-group-copy-msg" style="display:none;">Link kopiert!</div>' +
                '</div>' +
                '<div class="tix-group-overview">' + groupHtml + '</div>';

            bindOverviewActions();
            bindCopyButton();
        }

        // ══════════════════════════════════════
        // Mitglied-View (Nicht-Organisator)
        // ══════════════════════════════════════

        function renderMember(groupHtml) {
            var nameHtml = '';
            if (!state.hasAdded && !state.memberId) {
                nameHtml =
                    '<div class="tix-group-join">' +
                        '<span class="tix-group-join-label">Dein Name für die Gruppenbestellung:</span>' +
                        '<input type="text" class="tix-group-name-input" placeholder="Dein Name" maxlength="50" autocomplete="given-name">' +
                    '</div>';
            }

            panel.innerHTML =
                '<div class="tix-group-header">' +
                    '<span class="tix-group-header-icon">👥</span>' +
                    '<span class="tix-group-header-title">Gruppenbestellung</span>' +
                '</div>' +
                nameHtml +
                '<div class="tix-group-overview">' + groupHtml + '</div>';

            bindOverviewActions();
        }

        // ══════════════════════════════════════
        // Group-Modus für Ticket-Selector aktivieren
        // ══════════════════════════════════════

        function activateGroupMode() {
            var buyBtn  = sel.querySelector('.tix-sel-buy');
            var buyText = sel.querySelector('.tix-sel-buy-text');

            if (buyText) buyText.textContent = 'Zur Gruppe hinzufügen';

            // Buy-Button überschreiben
            if (buyBtn) {
                // Original-Handler entfernen → neuen setzen
                var newBtn = buyBtn.cloneNode(true);
                buyBtn.parentNode.replaceChild(newBtn, buyBtn);

                newBtn.addEventListener('click', function() {
                    addToGroup(newBtn);
                });
            }

            // Name-Feld fokussieren wenn vorhanden
            var nameInput = panel.querySelector('.tix-group-join .tix-group-name-input');
            if (nameInput && !state.memberId) {
                nameInput.focus();
            }
        }

        // ══════════════════════════════════════
        // Tickets zur Gruppe hinzufügen
        // ══════════════════════════════════════

        function addToGroup(btn) {
            // Name prüfen (für neue Mitglieder)
            var nameInput = panel.querySelector('.tix-group-join .tix-group-name-input');
            var name = '';
            if (nameInput) {
                name = nameInput.value.trim();
                if (!name) {
                    nameInput.classList.add('tix-group-input-error');
                    nameInput.focus();
                    return;
                }
                nameInput.classList.remove('tix-group-input-error');
            } else if (state.memberId) {
                name = ''; // Server kennt den Namen bereits
            }

            // Items sammeln (gleiche Logik wie ticket-selector.js)
            var items = collectItems();
            if (items.length === 0) return;

            btn.disabled = true;
            var loadingEl = btn.querySelector('.tix-sel-buy-loading');
            var textEl    = btn.querySelector('.tix-sel-buy-text');
            if (textEl)    textEl.style.display = 'none';
            if (loadingEl) loadingEl.style.display = '';

            ajaxPost('tix_group_add', {
                token: state.token,
                name: name,
                member_id: state.memberId || '',
                admin_token: state.adminToken || '',
                items: JSON.stringify(items)
            }, function(data) {
                state.memberId = data.member_id;
                state.hasAdded = true;

                try { localStorage.setItem(lsKey('member'), state.memberId); } catch(e) {}

                // Übersicht aktualisieren
                var overview = panel.querySelector('.tix-group-overview');
                if (overview) overview.innerHTML = data.group_html;

                // Name-Input ausblenden
                var joinEl = panel.querySelector('.tix-group-join');
                if (joinEl) joinEl.style.display = 'none';

                // Nachricht
                showSelectorMessage(data.message || 'Hinzugefügt!', 'success');
                bindOverviewActions();

                btn.disabled = false;
                if (textEl)    textEl.style.display = '';
                if (loadingEl) loadingEl.style.display = 'none';
            }, function(msg) {
                showSelectorMessage(msg, 'error');
                btn.disabled = false;
                if (textEl)    textEl.style.display = '';
                if (loadingEl) loadingEl.style.display = 'none';
            });
        }

        // ══════════════════════════════════════
        // Items aus Selector sammeln
        // ══════════════════════════════════════

        function collectItems() {
            var items = [];
            var cats   = sel.querySelectorAll('.tix-sel-cat:not(.tix-sel-combo)');
            var combos = sel.querySelectorAll('.tix-sel-combo');

            cats.forEach(function(cat) {
                var valEl = cat.querySelector('.tix-sel-qty-val');
                if (!valEl) return;
                var qty = parseInt(valEl.dataset.qty, 10) || 0;
                if (qty <= 0) return;

                var pid = parseInt(cat.dataset.productId, 10);
                var isBundle = cat.dataset.bundle === '1';

                if (isBundle) {
                    var bBuy = parseInt(cat.dataset.bundleBuy, 10) || 0;
                    var bPay = parseInt(cat.dataset.bundlePay, 10) || 0;
                    items.push({
                        product_id: pid,
                        quantity: qty * bBuy,
                        bundle: 1,
                        bundle_buy: bBuy,
                        bundle_pay: bPay,
                        bundle_label: cat.dataset.bundleLabel || ''
                    });
                } else {
                    items.push({ product_id: pid, quantity: qty });
                }
            });

            combos.forEach(function(combo) {
                var valEl = combo.querySelector('.tix-sel-qty-val');
                if (!valEl) return;
                var qty = parseInt(valEl.dataset.qty, 10) || 0;
                if (qty <= 0) return;

                var comboItems = [];
                try { comboItems = JSON.parse(combo.dataset.comboItems || '[]'); } catch(ex) {}

                items.push({
                    combo: 1,
                    combo_id: combo.dataset.comboId || '',
                    combo_label: combo.dataset.comboLabel || '',
                    combo_price: parseFloat(combo.dataset.comboPrice) || 0,
                    quantity: qty,
                    products: comboItems
                });
            });

            return items;
        }

        // ══════════════════════════════════════
        // Polling
        // ══════════════════════════════════════

        function startPolling() {
            if (state.pollTimer) clearInterval(state.pollTimer);
            state.pollTimer = setInterval(pollStatus, 8000);
        }

        function pollStatus() {
            ajaxPost('tix_group_status', {
                token: state.token,
                admin_token: state.adminToken || '',
                member_id: state.memberId || ''
            }, function(data) {
                if (data.status === 'completed') {
                    stopPolling();
                    panel.innerHTML = '<div class="tix-group-completed">Die Gruppenbestellung wurde abgeschlossen! 🎉</div>';
                    return;
                }
                var overview = panel.querySelector('.tix-group-overview');
                if (overview) {
                    overview.innerHTML = data.group_html;
                    bindOverviewActions();
                }
            }, function() {
                // Stille Fehler beim Polling
            });
        }

        function stopPolling() {
            if (state.pollTimer) {
                clearInterval(state.pollTimer);
                state.pollTimer = null;
            }
        }

        // ══════════════════════════════════════
        // Event-Bindings für dynamischen Content
        // ══════════════════════════════════════

        function bindOverviewActions() {
            // Mitglied entfernen
            panel.querySelectorAll('.tix-group-member-remove').forEach(function(btn) {
                btn.onclick = function() {
                    var mid = btn.dataset.memberId;
                    btn.disabled = true;
                    ajaxPost('tix_group_remove', {
                        token: state.token,
                        admin_token: state.adminToken,
                        remove_member_id: mid,
                        member_id: state.memberId || ''
                    }, function(data) {
                        var overview = panel.querySelector('.tix-group-overview');
                        if (overview) overview.innerHTML = data.group_html;
                        bindOverviewActions();
                    }, function() {
                        btn.disabled = false;
                    });
                };
            });

            // Checkout
            var checkoutBtn = panel.querySelector('.tix-group-checkout-btn');
            if (checkoutBtn) {
                checkoutBtn.onclick = function() {
                    checkoutBtn.disabled = true;
                    checkoutBtn.textContent = 'Wird vorbereitet…';

                    ajaxPost('tix_group_checkout', {
                        token: state.token,
                        admin_token: state.adminToken
                    }, function(data) {
                        stopPolling();
                        window.location.href = data.checkout_url;
                    }, function(msg) {
                        checkoutBtn.disabled = false;
                        checkoutBtn.textContent = 'Jetzt für alle bestellen';
                        showSelectorMessage(msg, 'error');
                    });
                };
            }
        }

        function bindCopyButton() {
            var copyBtn = panel.querySelector('.tix-group-copy-btn');
            var copyMsg = panel.querySelector('.tix-group-copy-msg');
            var urlInput = panel.querySelector('.tix-group-share-url');

            if (copyBtn && urlInput) {
                copyBtn.addEventListener('click', function() {
                    var url = urlInput.value;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(url).then(function() {
                            flashCopyMsg();
                        });
                    } else {
                        urlInput.select();
                        document.execCommand('copy');
                        flashCopyMsg();
                    }
                });

                urlInput.addEventListener('click', function() {
                    urlInput.select();
                });
            }

            function flashCopyMsg() {
                if (copyMsg) {
                    copyMsg.style.display = '';
                    setTimeout(function() { copyMsg.style.display = 'none'; }, 2000);
                }
            }
        }

        // ══════════════════════════════════════
        // Helpers
        // ══════════════════════════════════════

        function ajaxPost(action, params, onSuccess, onError) {
            var data = new FormData();
            data.append('action', action);
            data.append('nonce', ehGroup.nonce);
            for (var k in params) {
                if (params.hasOwnProperty(k)) data.append(k, params[k]);
            }

            fetch(ehGroup.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        onSuccess(res.data);
                    } else {
                        if (onError) onError(res.data ? res.data.message : 'Fehler');
                    }
                })
                .catch(function() {
                    if (onError) onError('Verbindungsfehler.');
                });
        }

        function showSelectorMessage(text, type) {
            var msg = sel.querySelector('.tix-sel-message');
            if (!msg) return;
            msg.textContent = text;
            msg.className = 'tix-sel-message tix-sel-msg-' + type;
            msg.style.display = '';
            setTimeout(function() { msg.style.display = 'none'; }, 4000);
        }

        function escAttr(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML.replace(/"/g, '&quot;');
        }
    });
})();
