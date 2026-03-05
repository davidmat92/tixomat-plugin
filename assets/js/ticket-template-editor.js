/**
 * Tixomat – Ticket Template Editor
 * Visueller Canvas-Editor mit Drag & Drop + Koordinaten-Feintuning
 * Erweitert: Vorschau-Text, Schriftdarstellung, QR/Barcode-Muster,
 *            Drehung, Deckkraft, Hintergrund, Rahmen, Textumwandlung
 * @since 1.20.0
 */
(function($) {
    'use strict';

    window.TIX_TemplateEditor = function(container, options) {
        var self = this;

        // ── Defaults ──
        self.opts = $.extend({
            inputSelector: null,   // Hidden-Input für JSON
            nonceAction: 'tix_template_preview',
            nonce: '',
            ajaxUrl: ajaxurl || '',
            fieldDefs: {},
        }, options);

        self.$wrap = $(container);
        self.$input = $(self.opts.inputSelector);
        self.config = null;
        self.activeField = null;
        self.scale = 1;
        self.dragging = null;
        self.resizing = null;

        // ── Init ──
        self.init = function() {
            self.injectEditorStyles();
            self.loadConfig();
            self.buildUI();
            self.bindEvents();
        };

        // ══════════════════════════════════════
        // DYNAMISCHE CSS-STILE
        // ══════════════════════════════════════

        /**
         * Fügt einmalig die CSS-Regeln für QR-Schachbrett
         * und Barcode-Streifen ins Dokument ein.
         */
        self.injectEditorStyles = function() {
            if ($('#tix-tte-dynamic-styles').length) return;

            var css = '' +
                /* Schachbrettmuster für QR-Codes */
                '.tix-tte-field-overlay.qr-pattern {' +
                '  background-image: ' +
                '    linear-gradient(45deg, rgba(0,0,0,.7) 25%, transparent 25%),' +
                '    linear-gradient(-45deg, rgba(0,0,0,.7) 25%, transparent 25%),' +
                '    linear-gradient(45deg, transparent 75%, rgba(0,0,0,.7) 75%),' +
                '    linear-gradient(-45deg, transparent 75%, rgba(0,0,0,.7) 75%);' +
                '  background-size: 12px 12px;' +
                '  background-position: 0 0, 0 6px, 6px -6px, -6px 0;' +
                '  background-color: #fff;' +
                '}' +
                /* Vertikale Streifen für Barcodes */
                '.tix-tte-field-overlay.barcode-pattern {' +
                '  background-image: repeating-linear-gradient(' +
                '    90deg, #000 0px, #000 2px, #fff 2px, #fff 4px,' +
                '    #000 4px, #000 5px, #fff 5px, #fff 9px,' +
                '    #000 9px, #000 11px, #fff 11px, #fff 13px);' +
                '  background-color: #fff;' +
                '}' +
                /* Sektions-Trenner im Properties-Panel */
                '.tix-tte-section-heading {' +
                '  font-weight: 600;' +
                '  font-size: 12px;' +
                '  text-transform: uppercase;' +
                '  letter-spacing: 0.5px;' +
                '  color: #555;' +
                '  border-bottom: 1px solid #ddd;' +
                '  padding: 6px 0 3px;' +
                '  margin: 10px 0 4px;' +
                '  grid-column: 1 / -1;' +
                '}' +
                '.tix-tte-section-heading:first-child { margin-top: 0; }' +
                /* Range-Slider + Wertanzeige */
                '.tix-tte-range-wrap {' +
                '  display: flex;' +
                '  align-items: center;' +
                '  gap: 6px;' +
                '}' +
                '.tix-tte-range-wrap input[type="range"] { flex: 1; }' +
                '.tix-tte-range-val {' +
                '  min-width: 36px;' +
                '  text-align: right;' +
                '  font-size: 12px;' +
                '  color: #555;' +
                '}' +
                /* Slider + Number Combo */
                '.tix-tte-slider-num-wrap {' +
                '  display: flex;' +
                '  align-items: center;' +
                '  gap: 6px;' +
                '}' +
                '.tix-tte-slider-num-wrap input[type="range"] { flex: 1; }' +
                '.tix-tte-slider-num-wrap input[type="number"] {' +
                '  width: 56px;' +
                '  text-align: center;' +
                '}' +
                /* Farbe mit Clear-Button */
                '.tix-tte-color-wrap {' +
                '  display: flex;' +
                '  align-items: center;' +
                '  gap: 4px;' +
                '}' +
                '.tix-tte-color-wrap input[type="color"] { flex: 1; }' +
                '.tix-tte-color-clear {' +
                '  cursor: pointer;' +
                '  color: #a00;' +
                '  font-size: 18px;' +
                '  line-height: 1;' +
                '  padding: 0 2px;' +
                '  border: none;' +
                '  background: none;' +
                '}' +
                '.tix-tte-color-clear:hover { color: #d00; }';

            $('<style id="tix-tte-dynamic-styles"></style>').text(css).appendTo('head');
        };

        // ══════════════════════════════════════
        // CONFIG
        // ══════════════════════════════════════

        self.loadConfig = function() {
            var raw = self.$input.val();
            if (raw) {
                try { self.config = JSON.parse(raw); } catch(e) { self.config = null; }
            }
            if (!self.config) {
                self.config = {
                    template_image_id: 0,
                    canvas_width: 2480,
                    canvas_height: 3508,
                    fields: self.defaultFields()
                };
            }
            // Fehlende Felder ergänzen
            var defs = self.opts.fieldDefs;
            for (var key in defs) {
                if (!self.config.fields[key]) {
                    self.config.fields[key] = self.makeDefaultField(key);
                } else {
                    // Neue Properties mit Defaults auffüllen
                    self.ensureFieldDefaults(self.config.fields[key], key);
                }
            }
        };

        /**
         * Stellt sicher, dass ein gespeichertes Feld alle neuen Properties hat.
         */
        self.ensureFieldDefaults = function(field, key) {
            var defaults = {
                letter_spacing: 0,
                line_height: 1.4,
                rotation: 0,
                opacity: 1.0,
                bg_color: '',
                border_color: '',
                border_width: 0,
                padding: 0,
                text_transform: 'none'
            };
            for (var prop in defaults) {
                if (typeof field[prop] === 'undefined') {
                    field[prop] = defaults[prop];
                }
            }
            if (key === 'custom_text' && typeof field.text === 'undefined') {
                field.text = '';
            }
        };

        self.defaultFields = function() {
            var fields = {};
            var defs = self.opts.fieldDefs;
            for (var key in defs) {
                fields[key] = self.makeDefaultField(key);
            }
            return fields;
        };

        self.makeDefaultField = function(key) {
            var w = self.config ? self.config.canvas_width : 2480;
            var h = self.config ? self.config.canvas_height : 3508;
            var field = {
                enabled: false,
                x: Math.round(w * 0.06),
                y: Math.round(h * 0.08),
                width: Math.round(w * 0.55),
                height: Math.round(h * 0.04),
                font_size: key === 'event_name' ? 48 : 28,
                font_family: key === 'ticket_code' ? 'monospace' : 'sans-serif',
                font_weight: key === 'event_name' ? 'bold' : 'normal',
                color: '#ffffff',
                alignment: 'left',
                // Neue Properties
                letter_spacing: 0,
                line_height: 1.4,
                rotation: 0,
                opacity: 1.0,
                bg_color: '',
                border_color: '',
                border_width: 0,
                padding: 0,
                text_transform: 'none'
            };
            if (key === 'qr_code') {
                field.x = Math.round(w * 0.06);
                field.y = Math.round(h * 0.55);
                field.width = Math.round(w * 0.18);
                field.height = Math.round(w * 0.18);
            }
            if (key === 'barcode') {
                field.x = Math.round(w * 0.06);
                field.y = Math.round(h * 0.55) + Math.round(w * 0.18) + Math.round(h * 0.02);
                field.width = Math.round(w * 0.22);
                field.height = Math.round(w * 0.06);
            }
            if (key === 'custom_text') {
                field.text = '';
            }
            return field;
        };

        self.saveConfig = function() {
            self.$input.val(JSON.stringify(self.config));
        };

        // ══════════════════════════════════════
        // UI AUFBAUEN
        // ══════════════════════════════════════

        self.buildUI = function() {
            self.$wrap.empty();

            // Upload-Row
            var $upload = $('<div class="tix-tte-upload-row"></div>');
            var $uploadBtn = $('<button type="button" class="button"><span class="dashicons dashicons-upload" style="margin-top:3px;margin-right:4px;"></span> Template-Bild hochladen</button>');
            var $uploadInfo = $('<span class="tix-tte-upload-info"></span>');
            var $removeBtn = $('<a class="tix-tte-remove-btn" style="display:none;">Bild entfernen</a>');
            $upload.append($uploadBtn, $uploadInfo, $removeBtn);
            self.$wrap.append($upload);

            self.$uploadBtn = $uploadBtn;
            self.$uploadInfo = $uploadInfo;
            self.$removeBtn = $removeBtn;

            // Editor (Canvas + Fields-Panel)
            var $editor = $('<div class="tix-tte-editor" style="display:none;"></div>');
            var $canvasWrap = $('<div class="tix-tte-canvas-wrap"></div>');
            var $canvasInner = $('<div class="tix-tte-canvas-inner"></div>');
            var $img = $('<img class="tix-tte-bg-img" draggable="false">');
            $canvasInner.append($img);
            $canvasWrap.append($canvasInner);

            var $fieldsPanel = $('<div class="tix-tte-fields-panel"></div>');
            $fieldsPanel.append('<h4>Felder</h4>');

            var defs = self.opts.fieldDefs;
            for (var key in defs) {
                var f = self.config.fields[key] || {};
                var checked = f.enabled ? 'checked' : '';
                var $item = $('<div class="tix-tte-field-item" data-field="' + key + '"></div>');
                $item.append('<input type="checkbox" id="tix-tte-f-' + key + '" ' + checked + '>');
                $item.append('<label for="tix-tte-f-' + key + '">' + defs[key].label + '</label>');
                $fieldsPanel.append($item);
            }

            $editor.append($canvasWrap, $fieldsPanel);
            self.$wrap.append($editor);

            self.$editor = $editor;
            self.$canvasWrap = $canvasWrap;
            self.$canvasInner = $canvasInner;
            self.$bgImg = $img;
            self.$fieldsPanel = $fieldsPanel;

            // Properties-Panel
            var $props = $('<div class="tix-tte-props" style="display:none;"></div>');
            $props.append('<h4>Feld-Eigenschaften</h4>');
            $props.append('<div class="tix-tte-props-empty">Klicke auf ein Feld, um es zu bearbeiten.</div>');
            $props.append('<div class="tix-tte-props-grid" style="display:none;"></div>');
            self.$wrap.append($props);
            self.$props = $props;
            self.$propsGrid = $props.find('.tix-tte-props-grid');
            self.$propsEmpty = $props.find('.tix-tte-props-empty');

            // Preview
            var $previewRow = $('<div class="tix-tte-preview-row" style="display:none;"></div>');
            $previewRow.append('<button type="button" class="button"><span class="dashicons dashicons-visibility" style="margin-top:3px;margin-right:4px;"></span> Vorschau generieren</button>');
            $previewRow.append('<span class="spinner"></span>');
            self.$wrap.append($previewRow);
            self.$previewRow = $previewRow;
            self.$previewBtn = $previewRow.find('.button');

            // Vorschau-Bild Container
            self.$previewImgWrap = $('<div></div>');
            self.$wrap.append(self.$previewImgWrap);

            // Template-Bild anzeigen falls vorhanden
            if (self.config.template_image_id) {
                self.loadTemplateImage(self.config.template_image_id);
            }
        };

        // ══════════════════════════════════════
        // EVENTS
        // ══════════════════════════════════════

        self.bindEvents = function() {
            // Upload
            self.$uploadBtn.on('click', self.openMediaUploader);
            self.$removeBtn.on('click', self.removeTemplate);

            // Field-Checkboxen
            self.$fieldsPanel.on('change', 'input[type="checkbox"]', function() {
                var key = $(this).closest('.tix-tte-field-item').data('field');
                self.config.fields[key].enabled = this.checked;

                // QR/Barcode: Mindestdimensionen setzen
                if (this.checked) {
                    self.enforceMinDimensions(key);
                }

                self.saveConfig();
                self.renderOverlays();
            });

            // Field-Item klicken → auswählen
            self.$fieldsPanel.on('click', '.tix-tte-field-item', function(e) {
                if ($(e.target).is('input')) return;
                var key = $(this).data('field');
                self.selectField(key);
            });

            // Canvas: Mousedown auf Overlay → Drag starten
            self.$canvasInner.on('mousedown touchstart', '.tix-tte-field-overlay', function(e) {
                if ($(e.target).hasClass('tix-tte-resize-handle')) return;
                e.preventDefault();
                var key = $(this).data('field');
                self.selectField(key);
                self.startDrag(key, e);
            });

            // Canvas: Mousedown auf Resize Handle
            self.$canvasInner.on('mousedown touchstart', '.tix-tte-resize-handle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var key = $(this).closest('.tix-tte-field-overlay').data('field');
                self.selectField(key);
                self.startResize(key, e);
            });

            // Global: Mousemove + Mouseup
            $(document).on('mousemove touchmove', self.onMouseMove);
            $(document).on('mouseup touchend', self.onMouseUp);

            // Canvas-Klick auf leere Fläche → Auswahl aufheben
            self.$canvasInner.on('mousedown', function(e) {
                if ($(e.target).hasClass('tix-tte-bg-img') || $(e.target).hasClass('tix-tte-canvas-inner')) {
                    self.selectField(null);
                }
            });

            // Preview
            self.$previewBtn.on('click', self.generatePreview);
        };

        // ══════════════════════════════════════
        // MEDIA UPLOADER
        // ══════════════════════════════════════

        self.openMediaUploader = function() {
            if (self.mediaFrame) {
                self.mediaFrame.open();
                return;
            }
            self.mediaFrame = wp.media({
                title: 'Template-Bild auswählen',
                button: { text: 'Als Template verwenden' },
                multiple: false,
                library: { type: ['image/jpeg', 'image/png'] }
            });
            self.mediaFrame.on('select', function() {
                var attachment = self.mediaFrame.state().get('selection').first().toJSON();
                self.config.template_image_id = attachment.id;
                self.config.canvas_width = attachment.width;
                self.config.canvas_height = attachment.height;

                // Felder auf neue Canvas-Größe anpassen wenn erstmalig
                var anyMoved = false;
                for (var k in self.config.fields) {
                    if (self.config.fields[k].x > 200 || self.config.fields[k].y > 200) {
                        anyMoved = true;
                        break;
                    }
                }
                if (!anyMoved) {
                    self.config.fields = self.defaultFields();
                    // Alle Standard-Felder aktivieren
                    var enable = ['event_name','event_date','event_time','event_location','cat_name','ticket_code','qr_code'];
                    for (var i = 0; i < enable.length; i++) {
                        if (self.config.fields[enable[i]]) {
                            self.config.fields[enable[i]].enabled = true;
                        }
                    }
                }

                self.saveConfig();
                self.loadTemplateImage(attachment.id, attachment.url);
            });
            self.mediaFrame.open();
        };

        self.removeTemplate = function(e) {
            e.preventDefault();
            self.config.template_image_id = 0;
            self.saveConfig();
            self.$editor.hide();
            self.$props.hide();
            self.$previewRow.hide();
            self.$previewImgWrap.empty();
            self.$uploadInfo.text('');
            self.$removeBtn.hide();
        };

        // ══════════════════════════════════════
        // TEMPLATE-BILD LADEN
        // ══════════════════════════════════════

        self.loadTemplateImage = function(attachmentId, url) {
            if (url) {
                self.showEditor(url);
                return;
            }
            // Bild-URL via WP REST API holen
            if (typeof wp !== 'undefined' && wp.media && wp.media.attachment) {
                var att = wp.media.attachment(attachmentId);
                att.fetch().then(function() {
                    var u = att.get('url');
                    if (u) self.showEditor(u);
                });
            } else {
                // Fallback: AJAX
                $.post(self.opts.ajaxUrl, {
                    action: 'tix_get_attachment_url',
                    id: attachmentId
                }, function(resp) {
                    if (resp && resp.data && resp.data.url) {
                        self.showEditor(resp.data.url);
                    }
                });
            }
        };

        self.showEditor = function(imgUrl) {
            self.$bgImg.attr('src', imgUrl);
            self.$bgImg.on('load', function() {
                self.updateScale();
                self.renderOverlays();
            });
            self.$editor.show();
            self.$props.show();
            self.$previewRow.show();
            self.$uploadInfo.text(self.config.canvas_width + ' × ' + self.config.canvas_height + ' px');
            self.$removeBtn.show();

            // Checkboxen aktualisieren
            self.$fieldsPanel.find('.tix-tte-field-item').each(function() {
                var key = $(this).data('field');
                var f = self.config.fields[key];
                $(this).find('input').prop('checked', f && f.enabled);
            });
        };

        // ══════════════════════════════════════
        // QR/BARCODE MINDESTDIMENSIONEN
        // ══════════════════════════════════════

        /**
         * Setzt Mindestabmessungen für QR- und Barcode-Felder,
         * wenn die aktuellen Werte zu klein sind.
         */
        self.enforceMinDimensions = function(key) {
            var f = self.config.fields[key];
            if (!f) return;

            if (key === 'qr_code') {
                if (f.width < 50) f.width = Math.round(self.config.canvas_width * 0.18);
                if (f.height < 50) f.height = Math.round(self.config.canvas_width * 0.18);
                // Position korrigieren wenn noch in der Ecke
                if (f.x > self.config.canvas_width * 0.65) f.x = Math.round(self.config.canvas_width * 0.06);
                if (f.y > self.config.canvas_height * 0.70) f.y = Math.round(self.config.canvas_height * 0.55);
            } else if (key === 'barcode') {
                if (f.width < 50) f.width = Math.round(self.config.canvas_width * 0.22);
                if (f.height < 50) f.height = Math.round(self.config.canvas_width * 0.06);
                // Position korrigieren wenn noch in der Ecke
                if (f.x > self.config.canvas_width * 0.65) f.x = Math.round(self.config.canvas_width * 0.06);
                if (f.y > self.config.canvas_height * 0.70) {
                    var qrField = self.config.fields['qr_code'];
                    var qrBottom = qrField ? (qrField.y + qrField.height + Math.round(self.config.canvas_height * 0.02)) : Math.round(self.config.canvas_height * 0.55);
                    f.y = qrBottom;
                }
            }
        };

        // ══════════════════════════════════════
        // SCALE + OVERLAYS
        // ══════════════════════════════════════

        self.updateScale = function() {
            var displayW = self.$bgImg.width();
            self.scale = displayW / self.config.canvas_width;
        };

        /**
         * Holt den Vorschau-Text für ein Feld aus tixPreviewData
         * oder fällt auf das Label zurück.
         */
        self.getPreviewText = function(key) {
            // Globales Vorschaudaten-Objekt (von PHP übergeben)
            if (window.tixPreviewData && window.tixPreviewData[key]) {
                return window.tixPreviewData[key];
            }
            // Custom-Text: gespeicherten Text verwenden
            if (key === 'custom_text') {
                var ct = self.config.fields[key];
                if (ct && ct.text) return ct.text;
            }
            // Fallback: Label aus fieldDefs
            var defs = self.opts.fieldDefs;
            return (defs[key] && defs[key].label) || key;
        };

        self.renderOverlays = function() {
            self.$canvasInner.find('.tix-tte-field-overlay').remove();
            var defs = self.opts.fieldDefs;

            self.updateScale();

            for (var key in self.config.fields) {
                var f = self.config.fields[key];
                if (!f.enabled) continue;

                var isQr = (key === 'qr_code');
                var isBarcode = (key === 'barcode');
                var isVisualField = (isQr || isBarcode);

                var $overlay = $('<div class="tix-tte-field-overlay" data-field="' + key + '"></div>');

                // QR/Barcode: CSS-Muster statt Text
                if (isQr) {
                    $overlay.addClass('qr-pattern');
                } else if (isBarcode) {
                    $overlay.addClass('barcode-pattern');
                } else {
                    // Text-Overlay mit realer Schriftvorschau
                    var previewText = self.getPreviewText(key);
                    $overlay.text(previewText);

                    // Schrift-Styling auf den Overlay anwenden (skaliert)
                    var scaledFontSize = Math.max(8, Math.round(f.font_size * self.scale));
                    $overlay.css({
                        'font-size': scaledFontSize + 'px',
                        'font-family': f.font_family || 'sans-serif',
                        'font-weight': f.font_weight || 'normal',
                        'color': f.color || '#ffffff',
                        'text-align': f.alignment || 'left',
                        'text-transform': f.text_transform || 'none',
                        'letter-spacing': (f.letter_spacing || 0) * self.scale + 'px',
                        'line-height': f.line_height || 1.4,
                        'overflow': 'hidden',
                        'white-space': 'nowrap',
                        'text-overflow': 'ellipsis'
                    });
                }

                // Gemeinsame Darstellungs-Properties
                var overlayCss = {
                    left: Math.round(f.x * self.scale) + 'px',
                    top: Math.round(f.y * self.scale) + 'px',
                    width: Math.round(f.width * self.scale) + 'px',
                    height: Math.round(f.height * self.scale) + 'px'
                };

                // Drehung
                if (f.rotation && f.rotation !== 0) {
                    overlayCss.transform = 'rotate(' + f.rotation + 'deg)';
                    overlayCss['transform-origin'] = 'top left';
                }

                // Deckkraft
                if (typeof f.opacity === 'number' && f.opacity < 1) {
                    overlayCss.opacity = f.opacity;
                }

                // Hintergrundfarbe (optional)
                if (f.bg_color && !isVisualField) {
                    overlayCss['background-color'] = f.bg_color;
                }

                // Rahmen
                if (f.border_color && f.border_width > 0) {
                    overlayCss.border = f.border_width * self.scale + 'px solid ' + f.border_color;
                }

                // Innenabstand (padding)
                if (f.padding > 0 && !isVisualField) {
                    overlayCss.padding = Math.round(f.padding * self.scale) + 'px';
                    overlayCss['box-sizing'] = 'border-box';
                }

                $overlay.css(overlayCss);

                // Aktiv-Markierung + Resize-Handle
                if (key === self.activeField) {
                    $overlay.addClass('active');
                    $overlay.append('<div class="tix-tte-resize-handle"></div>');
                }

                self.$canvasInner.append($overlay);
            }
        };

        // ══════════════════════════════════════
        // FELD AUSWÄHLEN
        // ══════════════════════════════════════

        self.selectField = function(key) {
            self.activeField = key;

            // UI aktualisieren
            self.$fieldsPanel.find('.tix-tte-field-item').removeClass('active');
            if (key) {
                self.$fieldsPanel.find('.tix-tte-field-item[data-field="' + key + '"]').addClass('active');
            }

            self.renderOverlays();
            self.renderProps();
        };

        // ══════════════════════════════════════
        // PROPERTIES-PANEL
        // ══════════════════════════════════════

        self.renderProps = function() {
            var key = self.activeField;
            if (!key || !self.config.fields[key]) {
                self.$propsEmpty.show();
                self.$propsGrid.hide().empty();
                return;
            }

            self.$propsEmpty.hide();
            self.$propsGrid.show().empty();

            var f = self.config.fields[key];
            var defs = self.opts.fieldDefs;
            var label = (defs[key] && defs[key].label) || key;
            var isQr = (key === 'qr_code' || key === 'barcode');

            self.$props.find('h4').text('Feld: ' + label);

            // ── Sektion: Position ──
            self.addSectionHeading('Position');
            self.addProp('X', 'number', 'x', f.x);
            self.addProp('Y', 'number', 'y', f.y);

            if (isQr) {
                // QR/Barcode: Proportional — nur Breite änderbar, Höhe folgt automatisch
                self.addProportionalSize(key, f);
            } else {
                self.addProp('Breite', 'number', 'width', f.width);
                self.addProp('H\u00f6he', 'number', 'height', f.height);
            }

            // ── Sektion: Schrift (nur Text-Felder) ──
            if (!isQr) {
                self.addSectionHeading('Schrift');

                // Schriftgröße: Slider + Number Combo
                self.addProp('Schriftgr\u00f6\u00dfe', 'slider_number', 'font_size', f.font_size, {
                    min: 8, max: 200, step: 1
                });

                self.addProp('Schrift', 'select', 'font_family', f.font_family, [
                    {v: 'sans-serif', l: 'Sans-Serif'},
                    {v: 'serif', l: 'Serif'},
                    {v: 'monospace', l: 'Monospace'}
                ]);
                self.addProp('Stil', 'select', 'font_weight', f.font_weight, [
                    {v: 'normal', l: 'Normal'},
                    {v: 'bold', l: 'Fett'}
                ]);
                self.addProp('Farbe', 'color', 'color', f.color);

                // Ausrichtung (Buttons)
                self.addAlignmentProp(key, f);

                // Zeichenabstand
                self.addProp('Zeichenabstand', 'number', 'letter_spacing', f.letter_spacing, {
                    min: -5, max: 50, step: 1
                });

                // Zeilenhöhe: Range-Slider
                self.addProp('Zeilenh\u00f6he', 'range', 'line_height', f.line_height, {
                    min: 0.8, max: 3.0, step: 0.1, suffix: ''
                });

                // Textumwandlung
                self.addProp('Textumwandlung', 'select', 'text_transform', f.text_transform, [
                    {v: 'none', l: 'Normal'},
                    {v: 'uppercase', l: 'GROSSBUCHSTABEN'},
                    {v: 'lowercase', l: 'kleinbuchstaben'}
                ]);
            }

            // ── Sektion: Darstellung ──
            self.addSectionHeading('Darstellung');

            // Drehung: Range + Grad-Anzeige
            self.addProp('Drehung', 'range', 'rotation', f.rotation, {
                min: -180, max: 180, step: 1, suffix: '\u00b0'
            });

            // Deckkraft: Range (0-100 → 0.0-1.0)
            self.addProp('Deckkraft', 'range', 'opacity', Math.round((f.opacity || 1) * 100), {
                min: 0, max: 100, step: 1, suffix: '%',
                mapToValue: function(v) { return Math.round(parseFloat(v)) / 100; },
                mapFromValue: function(v) { return Math.round(v * 100); }
            });

            // Hintergrund-Farbe (optional, mit Clear-Button)
            self.addProp('Hintergrund', 'color_optional', 'bg_color', f.bg_color);

            // Rahmen-Farbe und -Breite
            self.addProp('Rahmen-Farbe', 'color_optional', 'border_color', f.border_color);
            self.addProp('Rahmen-Breite', 'number', 'border_width', f.border_width, {
                min: 0, max: 10, step: 1
            });

            // Innenabstand
            self.addProp('Innenabstand', 'number', 'padding', f.padding, {
                min: 0, max: 50, step: 1
            });

            // ── Sektion: Custom Text (nur bei custom_text) ──
            if (key === 'custom_text') {
                self.addSectionHeading('Eigener Text');
                var $textField = $('<div class="tix-tte-prop-field wide"></div>');
                $textField.append('<label>Text</label>');
                var $textInput = $('<input type="text" value="' + (f.text || '').replace(/"/g, '&quot;') + '" placeholder="Eigener Text...">');
                $textInput.on('input', function() {
                    self.config.fields[key].text = $(this).val();
                    self.saveConfig();
                    self.renderOverlays();
                });
                $textField.append($textInput);
                self.$propsGrid.append($textField);
            }
        };

        /**
         * Fügt eine visuelle Sektionsüberschrift in das Properties-Grid ein.
         */
        self.addSectionHeading = function(title) {
            self.$propsGrid.append('<div class="tix-tte-section-heading">' + title + '</div>');
        };

        /**
         * Ausrichtungs-Buttons als eigene Methode
         */
        self.addAlignmentProp = function(key, f) {
            var $alignField = $('<div class="tix-tte-prop-field"></div>');
            $alignField.append('<label>Ausrichtung</label>');
            var $btns = $('<div class="tix-tte-align-btns"></div>');
            ['left', 'center', 'right'].forEach(function(al) {
                var icon = al === 'left' ? 'L' : (al === 'center' ? 'M' : 'R');
                var cls = f.alignment === al ? ' active' : '';
                $btns.append('<button type="button" class="' + cls + '" data-align="' + al + '">' + icon + '</button>');
            });
            $btns.on('click', 'button', function() {
                var al = $(this).data('align');
                self.config.fields[key].alignment = al;
                self.saveConfig();
                self.renderOverlays();
                $btns.find('button').removeClass('active');
                $(this).addClass('active');
            });
            $alignField.append($btns);
            self.$propsGrid.append($alignField);
        };

        /**
         * Proportionale Größeneingabe für QR/Barcode.
         * Breite und Höhe sind gekoppelt: Änderung an einem Wert
         * passt den anderen proportional an.
         */
        self.addProportionalSize = function(key, f) {
            var ratio = f.height > 0 ? f.width / f.height : 1;

            // Breite-Feld
            var $wField = $('<div class="tix-tte-prop-field"></div>');
            $wField.append('<label>Breite</label>');
            var $wInput = $('<input type="number" min="20" value="' + f.width + '">');
            $wField.append($wInput);
            self.$propsGrid.append($wField);

            // Höhe-Feld
            var $hField = $('<div class="tix-tte-prop-field"></div>');
            $hField.append('<label>H\u00f6he</label>');
            var $hInput = $('<input type="number" min="20" value="' + f.height + '">');
            $hField.append($hInput);
            self.$propsGrid.append($hField);

            // Hinweis
            var $note = $('<div class="tix-tte-section-heading" style="font-size:11px;color:#888;margin-top:0;">Proportional gekoppelt \ud83d\udd12</div>');
            self.$propsGrid.append($note);

            // Kopplung: Breite → Höhe
            $wInput.on('change input', function() {
                var newW = Math.max(20, parseInt($(this).val(), 10) || 20);
                var newH = Math.max(20, Math.round(newW / ratio));
                self.config.fields[key].width = newW;
                self.config.fields[key].height = newH;
                $hInput.val(newH);
                self.saveConfig();
                self.renderOverlays();
            });

            // Kopplung: Höhe → Breite
            $hInput.on('change input', function() {
                var newH = Math.max(20, parseInt($(this).val(), 10) || 20);
                var newW = Math.max(20, Math.round(newH * ratio));
                self.config.fields[key].height = newH;
                self.config.fields[key].width = newW;
                $wInput.val(newW);
                self.saveConfig();
                self.renderOverlays();
            });
        };

        /**
         * Universelle Property-Eingabe
         *
         * Unterstützte Typen:
         * - number:         Zahlen-Input mit optionalem min/max/step via options-Objekt
         * - select:         Dropdown, options = [{v: '...', l: '...'}, ...]
         * - color:          Farbwähler
         * - color_optional: Farbwähler mit Clear-Button (leerer Wert = keine Farbe)
         * - range:          Slider mit Wertanzeige, options = {min, max, step, suffix}
         * - slider_number:  Slider + Zahlen-Input synchronisiert, options = {min, max, step}
         */
        self.addProp = function(label, type, prop, value, options) {
            var key = self.activeField;
            var $field = $('<div class="tix-tte-prop-field"></div>');
            $field.append('<label>' + label + '</label>');
            options = options || {};

            // ── range: Slider mit Wertanzeige ──
            if (type === 'range') {
                var displayVal = value;
                var suffix = options.suffix || '';
                var $wrap = $('<div class="tix-tte-range-wrap"></div>');
                var $slider = $('<input type="range">');
                $slider.attr({
                    min: options.min,
                    max: options.max,
                    step: options.step,
                    value: displayVal
                });
                var $valDisplay = $('<span class="tix-tte-range-val">' + displayVal + suffix + '</span>');

                $slider.on('input change', function() {
                    var rawVal = parseFloat($(this).val());
                    $valDisplay.text(rawVal + suffix);
                    // Wert ggf. mappen (z.B. Deckkraft 0-100 → 0.0-1.0)
                    var storeVal = options.mapToValue ? options.mapToValue(rawVal) : rawVal;
                    self.config.fields[key][prop] = storeVal;
                    self.saveConfig();
                    self.renderOverlays();
                });

                $wrap.append($slider, $valDisplay);
                $field.append($wrap);

            // ── slider_number: Slider + Number-Input synchronisiert ──
            } else if (type === 'slider_number') {
                var $snWrap = $('<div class="tix-tte-slider-num-wrap"></div>');
                var $snSlider = $('<input type="range">');
                $snSlider.attr({
                    min: options.min || 0,
                    max: options.max || 200,
                    step: options.step || 1,
                    value: value
                });
                var $snNumber = $('<input type="number">');
                $snNumber.attr({
                    min: options.min || 0,
                    max: options.max || 200,
                    step: options.step || 1,
                    value: value
                });

                // Synchronisation: Slider → Number
                $snSlider.on('input change', function() {
                    var v = parseInt($(this).val(), 10) || 0;
                    $snNumber.val(v);
                    self.config.fields[key][prop] = v;
                    self.saveConfig();
                    self.renderOverlays();
                });
                // Synchronisation: Number → Slider
                $snNumber.on('input change', function() {
                    var v = parseInt($(this).val(), 10) || 0;
                    $snSlider.val(v);
                    self.config.fields[key][prop] = v;
                    self.saveConfig();
                    self.renderOverlays();
                });

                $snWrap.append($snSlider, $snNumber);
                $field.append($snWrap);

            // ── select: Dropdown ──
            } else if (type === 'select') {
                var $select = $('<select></select>');
                options.forEach(function(opt) {
                    var sel = opt.v === value ? ' selected' : '';
                    $select.append('<option value="' + opt.v + '"' + sel + '>' + opt.l + '</option>');
                });
                $select.on('change', function() {
                    self.config.fields[key][prop] = $(this).val();
                    self.saveConfig();
                    self.renderOverlays();
                });
                $field.append($select);

            // ── color: Standard-Farbwähler ──
            } else if (type === 'color') {
                var $colorInput = $('<input type="color" value="' + (value || '#000000') + '">');
                $colorInput.on('input change', function() {
                    self.config.fields[key][prop] = $(this).val();
                    self.saveConfig();
                    self.renderOverlays();
                });
                $field.append($colorInput);

            // ── color_optional: Farbwähler mit Clear-Button ──
            } else if (type === 'color_optional') {
                var $colorWrap = $('<div class="tix-tte-color-wrap"></div>');
                var hasColor = value && value.length > 0;
                var $colorOpt = $('<input type="color" value="' + (hasColor ? value : '#000000') + '">');
                var $clearBtn = $('<button type="button" class="tix-tte-color-clear" title="Farbe entfernen">&times;</button>');

                // Status-Anzeige: leere Farbe = gedimmt
                if (!hasColor) {
                    $colorOpt.css('opacity', '0.35');
                }

                $colorOpt.on('input change', function() {
                    var v = $(this).val();
                    self.config.fields[key][prop] = v;
                    $(this).css('opacity', '1');
                    self.saveConfig();
                    self.renderOverlays();
                });
                $clearBtn.on('click', function() {
                    self.config.fields[key][prop] = '';
                    $colorOpt.css('opacity', '0.35');
                    self.saveConfig();
                    self.renderOverlays();
                });

                $colorWrap.append($colorOpt, $clearBtn);
                $field.append($colorWrap);

            // ── number: Standard-Zahlenfeld ──
            } else {
                var $input = $('<input type="' + type + '" value="' + value + '">');
                if (type === 'number') {
                    $input.attr('min', typeof options.min !== 'undefined' ? options.min : 0);
                    if (typeof options.max !== 'undefined') $input.attr('max', options.max);
                    if (typeof options.step !== 'undefined') $input.attr('step', options.step);
                }

                $input.on('change input', function() {
                    var val = $(this).val();
                    if (type === 'number') val = parseInt(val, 10) || 0;
                    self.config.fields[key][prop] = val;
                    self.saveConfig();
                    self.renderOverlays();
                });
                $field.append($input);
            }

            self.$propsGrid.append($field);
        };

        // ══════════════════════════════════════
        // DRAG & DROP
        // ══════════════════════════════════════

        self.getEventPos = function(e) {
            var orig = e.originalEvent || e;
            if (orig.touches && orig.touches.length) {
                return { x: orig.touches[0].pageX, y: orig.touches[0].pageY };
            }
            return { x: e.pageX, y: e.pageY };
        };

        self.startDrag = function(key, e) {
            var pos = self.getEventPos(e);
            var f = self.config.fields[key];
            self.dragging = {
                key: key,
                startX: pos.x,
                startY: pos.y,
                origX: f.x,
                origY: f.y
            };
        };

        self.startResize = function(key, e) {
            var pos = self.getEventPos(e);
            var f = self.config.fields[key];
            var isProportional = (key === 'qr_code' || key === 'barcode');
            self.resizing = {
                key: key,
                startX: pos.x,
                startY: pos.y,
                origW: f.width,
                origH: f.height,
                proportional: isProportional,
                aspectRatio: f.height > 0 ? f.width / f.height : 1
            };
        };

        self.onMouseMove = function(e) {
            if (self.dragging) {
                var pos = self.getEventPos(e);
                var d = self.dragging;
                var dx = (pos.x - d.startX) / self.scale;
                var dy = (pos.y - d.startY) / self.scale;
                var f = self.config.fields[d.key];
                f.x = Math.max(0, Math.min(self.config.canvas_width - f.width, Math.round(d.origX + dx)));
                f.y = Math.max(0, Math.min(self.config.canvas_height - f.height, Math.round(d.origY + dy)));
                self.renderOverlays();
                self.updatePropsValues();
            }
            if (self.resizing) {
                var pos2 = self.getEventPos(e);
                var r = self.resizing;
                var dw = (pos2.x - r.startX) / self.scale;
                var dh = (pos2.y - r.startY) / self.scale;
                var f2 = self.config.fields[r.key];

                if (r.proportional) {
                    // QR/Barcode: Proportional skalieren (größere Achsenbewegung bestimmt)
                    var delta = Math.abs(dw) >= Math.abs(dh) ? dw : dh;
                    var newW = Math.max(40, Math.round(r.origW + delta));
                    var newH = Math.max(20, Math.round(newW / r.aspectRatio));
                    f2.width = newW;
                    f2.height = newH;
                } else {
                    f2.width = Math.max(20, Math.round(r.origW + dw));
                    f2.height = Math.max(10, Math.round(r.origH + dh));
                }
                self.renderOverlays();
                self.updatePropsValues();
            }
        };

        self.onMouseUp = function() {
            if (self.dragging || self.resizing) {
                self.saveConfig();
                self.dragging = null;
                self.resizing = null;
            }
        };

        self.updatePropsValues = function() {
            if (!self.activeField) return;
            var f = self.config.fields[self.activeField];
            self.$propsGrid.find('input[type="number"]').each(function() {
                var $inp = $(this);
                var lbl = $inp.closest('.tix-tte-prop-field').find('label').text();
                if (lbl === 'X') $inp.val(f.x);
                else if (lbl === 'Y') $inp.val(f.y);
                else if (lbl === 'Breite') $inp.val(f.width);
                else if (lbl === 'H\u00f6he') $inp.val(f.height);
            });
            // Auch synchronisierte Slider aktualisieren
            self.$propsGrid.find('.tix-tte-slider-num-wrap').each(function() {
                var $sw = $(this);
                var lbl = $sw.closest('.tix-tte-prop-field').find('label').text();
                if (lbl === 'Breite') {
                    $sw.find('input[type="range"]').val(f.width);
                    $sw.find('input[type="number"]').val(f.width);
                } else if (lbl === 'H\u00f6he') {
                    $sw.find('input[type="range"]').val(f.height);
                    $sw.find('input[type="number"]').val(f.height);
                }
            });
        };

        // ══════════════════════════════════════
        // VORSCHAU
        // ══════════════════════════════════════

        self.generatePreview = function() {
            self.$previewBtn.prop('disabled', true);
            self.$previewRow.find('.spinner').addClass('is-active');
            self.$previewImgWrap.empty();

            $.post(self.opts.ajaxUrl, {
                action: 'tix_template_preview',
                nonce: self.opts.nonce,
                config: JSON.stringify(self.config)
            }, function(resp) {
                self.$previewBtn.prop('disabled', false);
                self.$previewRow.find('.spinner').removeClass('is-active');

                if (resp.success && resp.data.image) {
                    self.$previewImgWrap.html('<img class="tix-tte-preview-img" src="' + resp.data.image + '" alt="Vorschau">');
                } else {
                    var msg = (resp.data && resp.data.message) || 'Fehler beim Generieren der Vorschau.';
                    self.$previewImgWrap.html('<p style="color:#c00;">' + msg + '</p>');
                }
            }).fail(function() {
                self.$previewBtn.prop('disabled', false);
                self.$previewRow.find('.spinner').removeClass('is-active');
                self.$previewImgWrap.html('<p style="color:#c00;">Netzwerkfehler bei der Vorschau.</p>');
            });
        };

        // ══════════════════════════════════════
        // WINDOW RESIZE
        // ══════════════════════════════════════

        $(window).on('resize', function() {
            if (self.config.template_image_id) {
                self.updateScale();
                self.renderOverlays();
            }
        });

        // Start
        self.init();
    };

})(jQuery);
