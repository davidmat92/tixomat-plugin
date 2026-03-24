/**
 * TIX Register Event — "Event in 2 Minuten"
 * 3-Step: Event (Chat/Upload/URL) → Preview → Konto → Erfolg
 */
(function($) {
    'use strict';

    if (typeof tixRegister === 'undefined') return;

    var ajax    = tixRegister.ajaxUrl;
    var nonce   = tixRegister.nonce;
    var aiNonce = tixRegister.aiNonce;

    var chatHistory = [];
    var eventData   = {};

    // ══════════════════════════════════════
    // STEP NAVIGATION
    // ══════════════════════════════════════
    function goToStep(step) {
        $('.tix-re-panel').hide();
        $('.tix-re-panel[data-step="' + step + '"]').show();

        $('.tix-re-step').removeClass('active done');
        $('.tix-re-step').each(function() {
            var s = parseInt($(this).data('step'), 10);
            if (s < step) $(this).addClass('done');
            if (s === step) $(this).addClass('active');
        });

        // Step lines
        $('.tix-re-step-line').each(function(i) {
            if (i + 1 < step) $(this).addClass('done');
            else $(this).removeClass('done');
        });

        $('html, body').animate({ scrollTop: $('.tix-re').offset().top - 80 }, 300);
    }

    $(document).on('click', '.tix-re-btn-back, .tix-re-btn-primary[data-goto]', function() {
        var goto = parseInt($(this).data('goto'), 10);
        if (goto) goToStep(goto);
    });

    // ══════════════════════════════════════
    // MODE SELECTION (Step 1)
    // ══════════════════════════════════════
    $(document).on('click', '.tix-re-mode', function() {
        var mode = $(this).data('mode');
        $('.tix-re-mode').removeClass('active');
        $(this).addClass('active');

        $('.tix-re-input-area').hide();
        $('#tix-re-' + mode + '-area').show();
    });

    // ══════════════════════════════════════
    // CHAT MODE
    // ══════════════════════════════════════
    function appendBubble(role, text) {
        var cls = role === 'user' ? 'tix-re-bubble-user' : 'tix-re-bubble-ai';
        var $b = $('<div class="tix-re-bubble ' + cls + '">').text(text);
        $('#tix-re-chat').append($b);
        var chat = document.getElementById('tix-re-chat');
        chat.scrollTop = chat.scrollHeight;
    }

    function sendChat(text) {
        if (!text.trim()) return;

        appendBubble('user', text);
        chatHistory.push({ role: 'user', content: text });

        var $input = $('#tix-re-chat-input');
        var $btn   = $('#tix-re-chat-send');
        $input.val('').prop('disabled', true);
        $btn.prop('disabled', true);

        appendBubble('assistant', '…');

        $.post(ajax, {
            action:  'tix_ai_chat',
            nonce:   aiNonce,
            text:    text,
            history: JSON.stringify(chatHistory),
            context: 'register_event'
        }, function(r) {
            $input.prop('disabled', false);
            $btn.prop('disabled', false);
            $input.focus();

            // Remove typing indicator
            $('#tix-re-chat .tix-re-bubble-ai:last').remove();

            if (!r.success) {
                appendBubble('assistant', r.data ? r.data.message : 'Fehler bei der KI-Anfrage.');
                return;
            }

            var data = r.data;
            chatHistory.push({ role: 'assistant', content: JSON.stringify(data) });

            if (data.status === 'complete' && data.fields) {
                appendBubble('assistant', 'Perfekt! Ich habe alle Infos. Schau dir die Vorschau an.');
                eventData = data.fields;
                renderPreview();
                setTimeout(function() { goToStep(2); }, 800);
            } else {
                appendBubble('assistant', data.message || 'Kannst du mir noch mehr erzählen?');
            }
        }).fail(function() {
            $input.prop('disabled', false);
            $btn.prop('disabled', false);
            $('#tix-re-chat .tix-re-bubble-ai:last').remove();
            appendBubble('assistant', 'Netzwerkfehler. Bitte versuche es erneut.');
        });
    }

    $(document).on('click', '#tix-re-chat-send', function() {
        sendChat($('#tix-re-chat-input').val());
    });

    $(document).on('keydown', '#tix-re-chat-input', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendChat($(this).val());
        }
    });

    // ══════════════════════════════════════
    // UPLOAD MODE (Flyer)
    // ══════════════════════════════════════
    var $dropzone = $('#tix-re-dropzone');
    var $file     = $('#tix-re-file');

    $dropzone.on('click', function() { $file.trigger('click'); });
    $('#tix-re-browse').on('click', function(e) { e.preventDefault(); $file.trigger('click'); });

    $dropzone.on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
    $dropzone.on('dragleave drop', function() { $(this).removeClass('dragover'); });
    $dropzone.on('drop', function(e) {
        e.preventDefault();
        var files = e.originalEvent.dataTransfer.files;
        if (files.length) handleFileUpload(files[0]);
    });

    $file.on('change', function() {
        if (this.files.length) handleFileUpload(this.files[0]);
    });

    function handleFileUpload(file) {
        if (!file.type.startsWith('image/')) {
            showStatus('Bitte lade ein Bild hoch (JPG, PNG, etc.).');
            return;
        }

        // Show preview
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#tix-re-upload-preview').show().html('<img src="' + e.target.result + '" alt="Flyer">');
        };
        reader.readAsDataURL(file);

        showStatus('KI analysiert den Flyer…');

        var fd = new FormData();
        fd.append('action', 'tix_ai_upload_image');
        fd.append('nonce', aiNonce);
        fd.append('file', file);

        $.ajax({
            url: ajax,
            type: 'POST',
            data: fd,
            contentType: false,
            processData: false,
            success: function(r) {
                hideStatus();
                if (r.success && r.data && r.data.attachment_id) {
                    // Now fill fields from image
                    fillFieldsFromImage(r.data.attachment_id);
                } else {
                    showStatus('Bild konnte nicht hochgeladen werden.');
                }
            },
            error: function() {
                hideStatus();
                showStatus('Netzwerkfehler beim Upload.');
            }
        });
    }

    function fillFieldsFromImage(attachmentId) {
        showStatus('KI extrahiert Event-Infos aus dem Flyer…');

        $.post(ajax, {
            action: 'tix_ai_fill_fields',
            nonce: aiNonce,
            source_type: 'image',
            attachment_id: attachmentId,
            context: 'register_event'
        }, function(r) {
            hideStatus();
            if (r.success && r.data && r.data.fields) {
                eventData = r.data.fields;
                renderPreview();
                goToStep(2);
            } else {
                showStatus('Konnte keine Event-Infos extrahieren: ' + (r.data ? r.data.message : ''));
            }
        }).fail(function() {
            hideStatus();
            showStatus('Netzwerkfehler bei der Analyse.');
        });
    }

    // ══════════════════════════════════════
    // URL MODE
    // ══════════════════════════════════════
    $(document).on('click', '#tix-re-url-analyze', function() {
        var url = $('#tix-re-url-input').val().trim();
        if (!url) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Wird analysiert…');
        showStatus('KI analysiert die URL…');

        $.post(ajax, {
            action: 'tix_ai_fill_fields',
            nonce: aiNonce,
            source_type: 'url',
            url: url,
            context: 'register_event'
        }, function(r) {
            $btn.prop('disabled', false).text('Analysieren');
            hideStatus();
            if (r.success && r.data && r.data.fields) {
                eventData = r.data.fields;
                renderPreview();
                goToStep(2);
            } else {
                showStatus('Konnte keine Infos extrahieren: ' + (r.data ? r.data.message : ''));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Analysieren');
            hideStatus();
            showStatus('Netzwerkfehler.');
        });
    });

    // ══════════════════════════════════════
    // STATUS HELPERS
    // ══════════════════════════════════════
    function showStatus(msg) { $('#tix-re-status').show().text(msg); }
    function hideStatus()    { $('#tix-re-status').hide(); }

    // ══════════════════════════════════════
    // PREVIEW RENDERING (Step 2)
    // ══════════════════════════════════════
    function renderPreview() {
        var $p = $('#tix-re-preview').empty();
        var d = eventData;

        // Title
        if (d.title) {
            $p.append('<div class="tix-re-preview-title">' + escHtml(d.title) + '</div>');
        }

        var rows = [
            ['Titel',        'title',        'text'],
            ['Datum',        'date_start',   'date'],
            ['Datum Ende',   'date_end',     'date'],
            ['Uhrzeit',      'time_start',   'text'],
            ['Uhrzeit Ende', 'time_end',     'text'],
            ['Einlass',      'time_doors',   'text'],
            ['Location',     'location',     'text'],
            ['Adresse',      'location_address', 'text'],
            ['Beschreibung', 'description',  'textarea'],
            ['Lineup',       'lineup',       'text'],
            ['Specials',     'specials',     'text'],
            ['Kategorie',    'event_type',   'text'],
            ['Mindestalter', 'age_limit',    'text'],
            ['Textauszug',   'excerpt',      'textarea'],
        ];

        rows.forEach(function(row) {
            var val = d[row[1]];
            if (!val) return;

            var input;
            if (row[2] === 'textarea') {
                input = '<textarea class="tix-re-preview-edit" data-field="' + row[1] + '">' + escHtml(val) + '</textarea>';
            } else if (row[2] === 'date') {
                input = '<input type="date" class="tix-re-preview-edit" data-field="' + row[1] + '" value="' + escAttr(val) + '">';
            } else {
                input = '<input type="text" class="tix-re-preview-edit" data-field="' + row[1] + '" value="' + escAttr(val) + '">';
            }

            $p.append(
                '<div class="tix-re-preview-row">' +
                    '<div class="tix-re-preview-label">' + row[0] + '</div>' +
                    '<div class="tix-re-preview-value">' + input + '</div>' +
                '</div>'
            );
        });

        // Tickets
        if (d.tickets && d.tickets.length) {
            var tHtml = d.tickets.map(function(t, i) {
                return '<div style="display:flex;gap:8px;margin-bottom:4px;">' +
                    '<input type="text" class="tix-re-preview-ticket-name" data-ti="' + i + '" value="' + escAttr(t.name || 'Ticket') + '" style="flex:1;">' +
                    '<input type="number" class="tix-re-preview-ticket-price" data-ti="' + i + '" value="' + (t.price || 0) + '" style="width:80px;" step="0.01" min="0"> €' +
                '</div>';
            }).join('');

            $p.append(
                '<div class="tix-re-preview-row">' +
                    '<div class="tix-re-preview-label">Tickets</div>' +
                    '<div class="tix-re-preview-value">' + tHtml + '</div>' +
                '</div>'
            );
        }
    }

    // Sync preview edits back to eventData
    $(document).on('change', '.tix-re-preview-edit', function() {
        var field = $(this).data('field');
        eventData[field] = $(this).val();
    });
    $(document).on('change', '.tix-re-preview-ticket-name', function() {
        var i = $(this).data('ti');
        if (eventData.tickets && eventData.tickets[i]) eventData.tickets[i].name = $(this).val();
    });
    $(document).on('change', '.tix-re-preview-ticket-price', function() {
        var i = $(this).data('ti');
        if (eventData.tickets && eventData.tickets[i]) eventData.tickets[i].price = parseFloat($(this).val());
    });

    // ══════════════════════════════════════
    // REGISTRATION + PUBLISH (Step 3)
    // ══════════════════════════════════════
    $(document).on('submit', '#tix-re-register-form', function(e) {
        e.preventDefault();

        var $form  = $(this);
        var $btn   = $('#tix-re-publish-btn');
        var $error = $('#tix-re-error');

        // Client-side password match
        var pw  = $form.find('[name="password"]').val();
        var pw2 = $form.find('[name="password_confirm"]').val();
        if (pw !== pw2) {
            $error.show().text('Passwörter stimmen nicht überein.');
            return;
        }
        if (pw.length < 8) {
            $error.show().text('Passwort muss mindestens 8 Zeichen lang sein.');
            return;
        }

        $btn.prop('disabled', true);
        $btn.find('.tix-re-btn-text').hide();
        $btn.find('.tix-re-btn-loading').show();
        $error.hide();

        $.post(ajax, {
            action:           'tix_register_and_publish',
            nonce:            nonce,
            first_name:       $form.find('[name="first_name"]').val(),
            last_name:        $form.find('[name="last_name"]').val(),
            email:            $form.find('[name="email"]').val(),
            password:         pw,
            password_confirm: pw2,
            organizer_name:   $form.find('[name="organizer_name"]').val(),
            event_data:       JSON.stringify(eventData)
        }, function(r) {
            if (r.success && r.data) {
                // Success!
                $('#tix-re-success-msg').text(r.data.message || '');
                $('#tix-re-link-event').attr('href', r.data.event_url || '#');
                $('#tix-re-link-dashboard').attr('href', r.data.dashboard_url || '#');

                goToStep(4);
                launchFireworks();

                // Auto-redirect after 5 seconds
                setTimeout(function() {
                    if (r.data.dashboard_url) window.location.href = r.data.dashboard_url;
                }, 5000);
            } else {
                $btn.prop('disabled', false);
                $btn.find('.tix-re-btn-text').show();
                $btn.find('.tix-re-btn-loading').hide();
                var msg = (r.data && r.data.message) ? r.data.message : 'Ein Fehler ist aufgetreten.';
                $error.show().text(msg);
            }
        }).fail(function(xhr) {
            $btn.prop('disabled', false);
            $btn.find('.tix-re-btn-text').show();
            $btn.find('.tix-re-btn-loading').hide();
            var msg = 'Netzwerkfehler. Bitte versuche es erneut.';
            try { var r = JSON.parse(xhr.responseText); if (r.data && r.data.message) msg = r.data.message; } catch(ex) {}
            $error.show().text(msg);
        });
    });

    // ══════════════════════════════════════
    // FIREWORKS CANVAS ANIMATION
    // ══════════════════════════════════════
    function launchFireworks() {
        var canvas = document.getElementById('tix-re-fireworks');
        if (!canvas) return;

        var ctx    = canvas.getContext('2d');
        var W      = canvas.parentElement.offsetWidth;
        var H      = 300;
        canvas.width  = W;
        canvas.height = H;
        canvas.style.width  = W + 'px';
        canvas.style.height = H + 'px';

        var particles = [];
        var colors = ['#FF5500', '#ff00aa', '#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6'];

        function createBurst(x, y) {
            for (var i = 0; i < 40; i++) {
                var angle = (Math.PI * 2 / 40) * i;
                var speed = 1.5 + Math.random() * 3;
                particles.push({
                    x: x, y: y,
                    vx: Math.cos(angle) * speed,
                    vy: Math.sin(angle) * speed,
                    life: 60 + Math.random() * 30,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    size: 2 + Math.random() * 2
                });
            }
        }

        // Initial bursts
        createBurst(W * 0.3, H * 0.3);
        createBurst(W * 0.7, H * 0.4);
        createBurst(W * 0.5, H * 0.25);

        // More bursts over time
        var burstCount = 0;
        var burstInterval = setInterval(function() {
            createBurst(W * (0.2 + Math.random() * 0.6), H * (0.2 + Math.random() * 0.4));
            burstCount++;
            if (burstCount >= 5) clearInterval(burstInterval);
        }, 400);

        function animate() {
            ctx.clearRect(0, 0, W, H);

            for (var i = particles.length - 1; i >= 0; i--) {
                var p = particles[i];
                p.x += p.vx;
                p.y += p.vy;
                p.vy += 0.04; // gravity
                p.life--;

                if (p.life <= 0) {
                    particles.splice(i, 1);
                    continue;
                }

                var alpha = Math.min(1, p.life / 30);
                ctx.globalAlpha = alpha;
                ctx.fillStyle = p.color;
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size * (alpha * 0.5 + 0.5), 0, Math.PI * 2);
                ctx.fill();
            }

            ctx.globalAlpha = 1;

            if (particles.length > 0) {
                requestAnimationFrame(animate);
            }
        }

        animate();
    }

    // ══════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════
    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    function escAttr(str) {
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

})(jQuery);
