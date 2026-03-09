/**
 * Tixomat – Saalplan Picker (Frontend)
 * Interaktive Sitzplatzauswahl für Kunden
 * Unterstützt Theater-, U-Form-, Stadion- und Arena-Layouts
 * Modi: modal (im Ticket-Selector), standalone (Shortcode)
 * @since 1.22.0
 * @updated 1.23.0 – Modal-Modus, Preise pro Sektion, Best-Available, Confirm-Event
 */
(function() {
    'use strict';

    // ── Alle Picker initialisieren ──
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-tix-seatmap-picker]').forEach(function(el) {
            // Modal-Picker nicht automatisch initialisieren (wird beim Öffnen gestartet)
            if (el.dataset.mode === 'modal' && el.closest('.tix-sp-modal-overlay')) return;
            initPicker(el);
        });
    });

    // Globaler Zugang zum Init (für Modal-Öffnung)
    window.tixInitSeatmapPicker = initPicker;

    function initPicker(container) {
        if (container._tixPickerInit) return; // Nicht doppelt initialisieren
        container._tixPickerInit = true;

        var eventId     = parseInt(container.dataset.eventId) || 0;
        var seatmapId   = parseInt(container.dataset.seatmapId) || 0;
        var sectionId   = container.dataset.sectionId || '';
        var categoryIdx = parseInt(container.dataset.categoryIndex) || 0;
        var maxSeats    = parseInt(container.dataset.maxSeats) || 99;
        var ajaxUrl     = container.dataset.ajaxUrl || '/wp-admin/admin-ajax.php';
        var mode        = container.dataset.mode || 'inline'; // 'modal', 'standalone', 'inline'

        // Sektions-Preisdaten (aus PHP übergeben)
        var sectionsData = [];
        try { sectionsData = JSON.parse(container.dataset.sections || '[]'); } catch(e) {}

        // Sektions-Lookup für schnellen Zugriff
        var sectionLookup = {};
        sectionsData.forEach(function(s) {
            sectionLookup[s.id] = s;
        });

        if (!eventId || !seatmapId) return;

        var state = {
            seatmap:   null,
            taken:     [],
            selected:  [],
            expiresAt: null,
            timer:     null
        };

        // Loading
        container.innerHTML = '<div class="tix-sp-loading"><span class="tix-sp-spinner"></span> Saalplan wird geladen…</div>';

        // Laden
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=tix_seat_availability&event_id=' + eventId + '&seatmap_id=' + seatmapId
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success || !res.data || !res.data.seatmap) {
                container.innerHTML = '<div class="tix-sp-loading">Saalplan nicht verfügbar.</div>';
                return;
            }
            state.seatmap = res.data.seatmap;
            state.taken   = res.data.taken || [];

            // Preis-Daten aus Seatmap-Sektionen befüllen (falls nicht via data-sections übergeben)
            if (sectionsData.length === 0 && state.seatmap.sections) {
                state.seatmap.sections.forEach(function(s) {
                    sectionLookup[s.id] = {
                        id:    s.id,
                        label: s.category_name || s.label,
                        color: s.color || '#FF5500',
                        price: s.price || 0
                    };
                });
            }

            render();
        })
        .catch(function() {
            container.innerHTML = '<div class="tix-sp-loading">Fehler beim Laden.</div>';
        });

        // ── Render ──
        function render() {
            var html = '';
            var layout = (state.seatmap.layout || 'theater');
            var stageText = state.seatmap.stage_label || 'BÜHNE';
            var isLayoutMode = (layout !== 'theater');

            // Overview Map (nur bei nicht-Theater-Layout)
            if (isLayoutMode) {
                html += renderOverviewMap(stageText);
            } else {
                // Theater: Stage oben
                html += '<div class="tix-sp-stage">' + esc(stageText) + '</div>';
            }

            // Legend mit Preisen
            html += '<div class="tix-sp-legend">';
            var shownSections = {};
            (state.seatmap.sections || []).forEach(function(section) {
                if (sectionId && section.id !== sectionId) return;
                if (shownSections[section.id]) return;
                shownSections[section.id] = true;

                var info = sectionLookup[section.id] || {};
                var price = info.price || section.price || 0;
                var color = section.color || '#FF5500';
                var label = info.label || section.category_name || section.label || section.id;

                html += '<span class="tix-sp-legend-item">' +
                    '<span class="tix-sp-legend-dot" style="background:' + color + '"></span> ' +
                    esc(label);
                if (price > 0) {
                    html += ' <span class="tix-sp-legend-price">' + formatPrice(price) + '</span>';
                }
                html += '</span>';
            });
            html += '<span class="tix-sp-legend-item"><span class="tix-sp-legend-dot taken"></span> Belegt</span>';
            html += '<span class="tix-sp-legend-item"><span class="tix-sp-legend-dot selected"></span> Gewählt</span>';
            html += '</div>';

            // Sections
            html += '<div class="tix-sp-sections">';
            (state.seatmap.sections || []).forEach(function(section) {
                // Filter: nur gewünschte Sektion zeigen (wenn angegeben)
                if (sectionId && section.id !== sectionId) return;

                var color = section.color || '#FF5500';
                var info  = sectionLookup[section.id] || {};
                var label = info.label || section.category_name || section.label || section.id;
                var price = info.price || section.price || 0;
                var avail = 0;
                (section.rows || []).forEach(function(r) {
                    (r.seats || []).forEach(function(s) {
                        if (s.type !== 'blocked' && state.taken.indexOf(s.id) === -1) avail++;
                    });
                });

                html += '<div class="tix-sp-section" id="tix-sp-sec-' + section.id + '" style="--section-color:' + color + '">';
                html += '<div class="tix-sp-section-header">' +
                    '<span class="tix-sp-section-dot" style="background:' + color + '"></span>' +
                    '<span class="tix-sp-section-name">' + esc(label) + '</span>';
                if (price > 0) {
                    html += '<span class="tix-sp-section-price">' + formatPrice(price) + '</span>';
                }
                html += '<span class="tix-sp-section-avail">' + avail + ' frei</span>';
                html += '</div>';

                (section.rows || []).forEach(function(row) {
                    html += '<div class="tix-sp-row">';
                    html += '<span class="tix-sp-row-label">' + esc(row.id) + '</span>';
                    html += '<div class="tix-sp-row-seats">';

                    (row.seats || []).forEach(function(seat) {
                        var isTaken    = state.taken.indexOf(seat.id) !== -1;
                        var isSelected = state.selected.indexOf(seat.id) !== -1;
                        var isBlocked  = seat.type === 'blocked';
                        var cls = 'tix-sp-seat';

                        if (seat.type === 'vip') cls += ' vip';
                        if (seat.type === 'wheelchair') cls += ' wheelchair';

                        if (isBlocked) {
                            cls += ' blocked';
                        } else if (isTaken) {
                            cls += ' taken';
                        } else if (isSelected) {
                            cls += ' selected';
                        } else {
                            cls += ' available';
                        }

                        var seatNum = seat.id.replace(/^.*?(\d+)$/, '$1');
                        var tooltip = esc(formatSeatId(seat.id));
                        if (price > 0 && !isTaken && !isBlocked) {
                            tooltip += ' · ' + formatPrice(price);
                        }

                        html += '<button type="button" class="' + cls + '" ' +
                            'data-seat-id="' + esc(seat.id) + '" ' +
                            'data-section-id="' + esc(section.id) + '" ' +
                            (isTaken || isBlocked ? 'disabled ' : '') +
                            'title="' + tooltip + '">' +
                            seatNum + '</button>';
                    });

                    html += '</div></div>'; // row-seats, row
                });

                html += '</div>'; // section
            });
            html += '</div>'; // sections

            // Selection Info
            if (state.selected.length > 0) {
                html += '<div class="tix-sp-selection">';
                html += '<div class="tix-sp-selection-header">' + state.selected.length + ' Sitz' + (state.selected.length !== 1 ? 'e' : '') + ' gewählt</div>';
                html += '<div class="tix-sp-selected-list">';
                state.selected.forEach(function(id) {
                    var secId = getSectionForSeat(id);
                    var sec   = sectionLookup[secId] || {};
                    var color = sec.color || '#FF5500';
                    html += '<span class="tix-sp-selected-tag" style="--tag-color:' + color + '">' +
                        esc(formatSeatId(id)) +
                        ' <span class="remove" data-seat-id="' + esc(id) + '">✕</span></span>';
                });
                html += '</div>';
                /* Timer-Anzeige entfernt (v1.28.0) — stille Ablauferkennung bleibt aktiv */
                html += '</div>';
            }

            container.innerHTML = html;

            // Bind Seat Events
            container.querySelectorAll('.tix-sp-seat.available, .tix-sp-seat.selected').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    toggleSeat(btn.dataset.seatId);
                });
            });

            container.querySelectorAll('.tix-sp-selected-tag .remove').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    toggleSeat(btn.dataset.seatId);
                });
            });

            // Bind Overview-Block Klick → Scroll
            container.querySelectorAll('.tix-sp-ov-block').forEach(function(block) {
                block.addEventListener('click', function() {
                    var targetId = block.dataset.sectionId;
                    var target = container.querySelector('#tix-sp-sec-' + targetId);
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        target.classList.add('tix-sp-highlight');
                        setTimeout(function() { target.classList.remove('tix-sp-highlight'); }, 1500);
                    }
                });
            });

            // Timer starten
            if (state.expiresAt) {
                startTimer();
            }

            // Hidden Inputs aktualisieren (nur im Inline-/Standalone-Modus)
            if (mode !== 'modal') {
                updateHiddenInputs();
            }

            // Modal-Footer aktualisieren
            updateModalFooter();
        }

        // ── Overview Map ──
        function renderOverviewMap(stageText) {
            var layout = state.seatmap.layout || 'theater';
            var layoutPositions = {
                'u-shape':  ['top', 'left', 'right'],
                'stadium':  ['top', 'bottom', 'left', 'right'],
                'arena':    ['top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right']
            };

            var positions = layoutPositions[layout] || [];

            // Sektionen nach Position gruppieren
            var byPos = {};
            positions.forEach(function(p) { byPos[p] = []; });
            (state.seatmap.sections || []).forEach(function(s) {
                if (sectionId && s.id !== sectionId) return;
                var pos = s.position || 'center';
                if (byPos[pos]) byPos[pos].push(s);
            });

            var gridAreas = {
                'top-left':     'tl',
                'top':          'top',
                'top-right':    'tr',
                'left':         'left',
                'right':        'right',
                'bottom-left':  'bl',
                'bottom':       'bottom',
                'bottom-right': 'br'
            };

            var html = '<div class="tix-sp-overview tix-sp-overview-' + layout + '">';

            var cells = ['top-left','top','top-right','left','stage','right','bottom-left','bottom','bottom-right'];
            cells.forEach(function(cell) {
                if (cell === 'stage') {
                    html += '<div class="tix-sp-ov-stage">' + esc(stageText) + '</div>';
                    return;
                }

                var cellSections = byPos[cell] || [];

                if (positions.indexOf(cell) === -1) {
                    html += '<div class="tix-sp-ov-cell tix-sp-ov-empty"></div>';
                } else if (cellSections.length === 0) {
                    html += '<div class="tix-sp-ov-cell tix-sp-ov-unused"></div>';
                } else {
                    html += '<div class="tix-sp-ov-cell">';
                    cellSections.forEach(function(s) {
                        var avail = 0;
                        (s.rows || []).forEach(function(r) {
                            (r.seats || []).forEach(function(seat) {
                                if (seat.type !== 'blocked' && state.taken.indexOf(seat.id) === -1) avail++;
                            });
                        });
                        var selectedCount = 0;
                        (s.rows || []).forEach(function(r) {
                            (r.seats || []).forEach(function(seat) {
                                if (state.selected.indexOf(seat.id) !== -1) selectedCount++;
                            });
                        });
                        var blockCls = 'tix-sp-ov-block' + (selectedCount > 0 ? ' has-selection' : '');
                        html += '<div class="' + blockCls + '" data-section-id="' + s.id + '" style="background:' + (s.color || '#FF5500') + '">' +
                            '<div class="tix-sp-ov-label">' + esc(s.label || s.id) + '</div>' +
                            '<div class="tix-sp-ov-avail">' + avail + ' frei</div>' +
                            (selectedCount > 0 ? '<div class="tix-sp-ov-selected">' + selectedCount + ' gewählt</div>' : '') +
                            '</div>';
                    });
                    html += '</div>';
                }
            });

            html += '</div>';
            return html;
        }

        // ── Toggle Seat ──
        function toggleSeat(seatId) {
            var idx = state.selected.indexOf(seatId);

            if (idx !== -1) {
                // Deselect → Release
                state.selected.splice(idx, 1);
                releaseSeat(seatId);
            } else {
                // Select
                if (state.selected.length >= maxSeats) return; // Limit
                state.selected.push(seatId);
                reserveSeat(seatId);
            }

            render();
        }

        // ── Pre-Select seats (für Best Available) ──
        container._tixPreSelect = function(seatIds) {
            seatIds.forEach(function(id) {
                if (state.selected.indexOf(id) === -1) {
                    state.selected.push(id);
                    reserveSeat(id);
                }
            });
            render();
        };

        // ── Get selected state ──
        container._tixGetState = function() {
            var sectionCounts = {};
            var total = 0;
            state.selected.forEach(function(id) {
                var secId = getSectionForSeat(id);
                if (!sectionCounts[secId]) sectionCounts[secId] = { id: secId, count: 0, price: 0 };
                sectionCounts[secId].count++;
                var sec = sectionLookup[secId] || {};
                sectionCounts[secId].price = sec.price || 0;
                total += sec.price || 0;
            });
            return {
                seats: state.selected.slice(),
                sections: Object.values(sectionCounts),
                total: total
            };
        };

        // ── Confirm (für Modal-Modus) ──
        container._tixConfirm = function() {
            var detail = container._tixGetState();
            container.dispatchEvent(new CustomEvent('tix-seats-confirmed', {
                bubbles: true,
                detail: detail
            }));
        };

        // ── Reserve ──
        function reserveSeat(seatId) {
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=tix_reserve_seats&event_id=' + eventId +
                    '&seatmap_id=' + seatmapId +
                    '&seat_ids[]=' + encodeURIComponent(seatId)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data) {
                    if (res.data.expires_at) {
                        state.expiresAt = new Date(res.data.expires_at + 'Z');
                    }
                    if (res.data.failed && res.data.failed.length > 0) {
                        res.data.failed.forEach(function(id) {
                            var i = state.selected.indexOf(id);
                            if (i !== -1) state.selected.splice(i, 1);
                            if (state.taken.indexOf(id) === -1) state.taken.push(id);
                        });
                        render();
                    } else {
                        render();
                    }
                }
            });
        }

        // ── Release ──
        function releaseSeat(seatId) {
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=tix_release_seats&event_id=' + eventId +
                    '&seat_ids[]=' + encodeURIComponent(seatId)
            });

            if (state.selected.length === 0) {
                state.expiresAt = null;
                if (state.timer) {
                    clearInterval(state.timer);
                    state.timer = null;
                }
            }
        }

        // ── Timer (stille Ablauferkennung, ohne sichtbaren Countdown) ──
        function startTimer() {
            if (state.timer) clearInterval(state.timer);
            state.timer = setInterval(function() {
                if (!state.expiresAt) {
                    clearInterval(state.timer);
                    return;
                }

                var remaining = Math.max(0, Math.floor((state.expiresAt - new Date()) / 1000));
                if (remaining <= 0) {
                    clearInterval(state.timer);
                    state.selected = [];
                    state.expiresAt = null;
                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=tix_seat_availability&event_id=' + eventId + '&seatmap_id=' + seatmapId
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success && res.data) {
                            state.taken = res.data.taken || [];
                        }
                        render();
                    });
                    return;
                }
            }, 1000);
        }

        // ── Modal Footer aktualisieren ──
        function updateModalFooter() {
            if (mode !== 'modal') return;
            var modal = container.closest('.tix-sp-modal');
            if (!modal) return;

            var countEl   = modal.querySelector('.tix-sp-modal-count');
            var totalEl   = modal.querySelector('.tix-sp-modal-total');
            var confirmEl = modal.querySelector('.tix-sp-modal-confirm');

            if (!countEl || !totalEl || !confirmEl) return;

            var stateData = container._tixGetState();
            countEl.textContent = stateData.seats.length + ' Platz' + (stateData.seats.length !== 1 ? '̈e' : '') + ' gewählt';
            totalEl.textContent = formatPrice(stateData.total);
            confirmEl.disabled = stateData.seats.length === 0;
        }

        // ── Hidden Inputs ──
        function updateHiddenInputs() {
            var existing = container.parentElement.querySelectorAll('input[name="tix_seats_' + categoryIdx + '[]"]');
            existing.forEach(function(el) { el.remove(); });

            state.selected.forEach(function(id) {
                var input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = 'tix_seats_' + categoryIdx + '[]';
                input.value = id;
                container.parentElement.appendChild(input);
            });

            var qtyInput = container.parentElement.querySelector('[data-tix-qty]');
            if (qtyInput) {
                qtyInput.value = state.selected.length;
                qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        // ── Sektion für Sitz-ID ermitteln ──
        function getSectionForSeat(seatId) {
            if (!state.seatmap || !state.seatmap.sections) return '';
            for (var si = 0; si < state.seatmap.sections.length; si++) {
                var section = state.seatmap.sections[si];
                for (var ri = 0; ri < (section.rows || []).length; ri++) {
                    var row = section.rows[ri];
                    for (var sei = 0; sei < (row.seats || []).length; sei++) {
                        if (row.seats[sei].id === seatId) return section.id;
                    }
                }
            }
            // Fallback: Sektion-ID aus Seat-ID extrahieren (section_1_A3 → section_1)
            var parts = seatId.split('_');
            if (parts.length >= 3) return parts.slice(0, -1).join('_').replace(/_[A-Z]\d+$/, '');
            if (parts.length >= 2) return parts[0] + '_' + parts[1];
            return '';
        }

        // ── Helpers ──
        function formatSeatId(id) {
            // section_1_A3 → A3
            var parts = id.split('_');
            return parts[parts.length - 1] || id;
        }

        function formatPrice(val) {
            return val.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' €';
        }

        function esc(str) {
            var d = document.createElement('div');
            d.textContent = str || '';
            return d.innerHTML;
        }
    }
})();
