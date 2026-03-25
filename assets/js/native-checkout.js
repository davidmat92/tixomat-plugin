/**
 * TIX Native Checkout — Full-featured Frontend JS
 * 3-Step Navigation, Countdown, Login, Cart, Payment
 */
(function($) {
    'use strict';

    if (typeof tixNativeCheckout === 'undefined') return;

    var ajax = tixNativeCheckout.ajaxUrl;
    var nonce = tixNativeCheckout.nonce;

    // ══════════════════════════════════════
    // 3-STEP NAVIGATION
    // ══════════════════════════════════════
    $(document).on('click', '.tix-co-step-next, .tix-co-step-back', function() {
        var goto = parseInt($(this).data('goto'), 10);
        if (!goto) return;

        // Validate current step before moving forward
        var $currentPanel = $(this).closest('.tix-co-step-panel');
        if ($(this).hasClass('tix-co-step-next')) {
            var valid = true;
            $currentPanel.find('[required]').each(function() {
                if (!this.checkValidity()) {
                    this.reportValidity();
                    valid = false;
                    return false;
                }
            });
            if (!valid) return;
        }

        // Switch panels
        $('.tix-co-step-panel').hide();
        $('.tix-co-step-panel[data-step="' + goto + '"]').show();

        // Update stepper
        $('.tix-co-step-ind').removeClass('tix-co-step-active tix-co-step-done');
        $('.tix-co-step-ind').each(function() {
            var step = parseInt($(this).data('step'), 10);
            if (step < goto) $(this).addClass('tix-co-step-done');
            if (step === goto) $(this).addClass('tix-co-step-active');
        });

        // Scroll to top
        $('html, body').animate({ scrollTop: $('.tix-co').offset().top - 80 }, 300);
    });

    // ══════════════════════════════════════
    // COUNTDOWN TIMER
    // ══════════════════════════════════════
    var $countdown = $('#tix-co-countdown');
    if ($countdown.length) {
        var minutes = parseInt($countdown.data('minutes'), 10) || 10;
        var endTime = Date.now() + minutes * 60000;

        function updateCountdown() {
            var remaining = Math.max(0, endTime - Date.now());
            if (remaining === 0) {
                $countdown.html('<div style="text-align:center;padding:12px;color:#ef4444;font-weight:600;">Zeit abgelaufen — bitte neu starten.</div>');
                return;
            }
            var m = Math.floor(remaining / 60000);
            var s = Math.floor((remaining % 60000) / 1000);
            $('#tix-co-countdown-time').text((m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s);
            var pct = (remaining / (minutes * 60000)) * 100;
            $('#tix-co-countdown-bar').css('width', pct + '%');
            requestAnimationFrame(updateCountdown);
        }
        updateCountdown();
    }

    // ══════════════════════════════════════
    // LOGIN
    // ══════════════════════════════════════
    $(document).on('click', '#tix-native-login-btn', function() {
        var user = $('#tix-native-login-user').val();
        var pass = $('#tix-native-login-pass').val();
        var $msg = $('#tix-native-login-msg');

        if (!user || !pass) {
            $msg.text('Bitte Felder ausfüllen.').css('color', '#ef4444');
            return;
        }

        $msg.text('Wird angemeldet…').css('color', '#6b7280');

        $.post(ajax, {
            action: 'tix_native_login',
            user: user,
            pass: pass,
            nonce: nonce
        }, function(r) {
            if (r.success) {
                $msg.text('Angemeldet!').css('color', '#22c55e');
                setTimeout(function() { location.reload(); }, 500);
            } else {
                $msg.text(r.data ? r.data.message : 'Anmeldung fehlgeschlagen.').css('color', '#ef4444');
            }
        }).fail(function() {
            $msg.text('Netzwerkfehler.').css('color', '#ef4444');
        });
    });

    // ══════════════════════════════════════
    // CHECKOUT FORM SUBMIT
    // ══════════════════════════════════════
    $(document).on('submit', '#tix-native-checkout-form', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $('#tix-native-pay-btn');
        var $error = $('#tix-native-checkout-error');

        // Validate all required fields (not just current step)
        var firstInvalid = null;
        $form.find('[required]').each(function() {
            if ($(this).is(':checkbox') && !$(this).is(':checked')) {
                firstInvalid = firstInvalid || $(this);
            } else if (!$(this).val()) {
                firstInvalid = firstInvalid || $(this);
            }
        });

        if (firstInvalid) {
            // Show the step containing the invalid field
            var $panel = firstInvalid.closest('.tix-co-step-panel');
            if ($panel.length && $panel.is(':hidden')) {
                var step = $panel.data('step');
                $('.tix-co-step-panel').hide();
                $panel.show();
                $('.tix-co-step-ind').removeClass('tix-co-step-active');
                $('.tix-co-step-ind[data-step="' + step + '"]').addClass('tix-co-step-active');
            }
            firstInvalid.focus();
            $error.show().text('Bitte alle Pflichtfelder ausfüllen.');
            return;
        }

        $btn.prop('disabled', true);
        $btn.find('.tix-co-submit-text').hide();
        $btn.find('.tix-co-submit-loading').show();
        $error.hide();

        $.post(ajax, $form.serialize(), function(r) {
            if (r.success && r.data && r.data.redirect) {
                window.location.href = r.data.redirect;
            } else {
                $btn.prop('disabled', false);
                $btn.find('.tix-co-submit-text').show();
                $btn.find('.tix-co-submit-loading').hide();
                var msg = (r.data && r.data.message) ? r.data.message : 'Ein Fehler ist aufgetreten.';
                $error.show().text(msg);
            }
        }).fail(function(xhr) {
            $btn.prop('disabled', false);
            $btn.find('.tix-co-submit-text').show();
            $btn.find('.tix-co-submit-loading').hide();
            var msg = 'Netzwerkfehler. Bitte versuche es erneut.';
            try { var r = JSON.parse(xhr.responseText); if (r.data && r.data.message) msg = r.data.message; } catch(e) {}
            $error.show().text(msg);
        });
    });

    // ══════════════════════════════════════
    // CART: REMOVE + QTY
    // ══════════════════════════════════════
    $(document).on('click', '.tix-co-item-remove, .tix-co-remove', function() {
        var index = $(this).data('index');
        $(this).closest('.tix-co-item').css('opacity', '0.3');
        $.post(ajax, { action: 'tix_native_remove_item', nonce: nonce, index: index }, function() {
            location.reload();
        });
    });

    $(document).on('click', '.tix-co-qty-btn', function() {
        var index = $(this).data('index');
        var delta = parseInt($(this).data('delta'), 10);
        $(this).closest('.tix-co-item').css('opacity', '0.6');
        $.post(ajax, { action: 'tix_native_update_qty', nonce: nonce, index: index, delta: delta }, function() {
            location.reload();
        });
    });

    // ══════════════════════════════════════
    // GATEWAY SELECTION
    // ══════════════════════════════════════
    $(document).on('change', '.tix-co-gw-radio', function() {
        $('.tix-co-gateway').removeClass('tix-co-gw-active');
        $(this).closest('.tix-co-gateway').addClass('tix-co-gw-active');
    });

    // ══════════════════════════════════════
    // COUPON
    // ══════════════════════════════════════
    $(document).on('click', '#tix-co-coupon-btn', function() {
        var code = $('#tix-co-coupon-input').val().trim();
        var $msg = $('#tix-co-coupon-msg');
        var $btn = $(this);

        if (!code) {
            $msg.html('<span style="color:#ef4444;">Bitte einen Gutscheincode eingeben.</span>');
            return;
        }

        $btn.prop('disabled', true).text('…');
        $msg.text('');

        $.post(ajax, {
            action: 'tix_native_apply_coupon',
            coupon_code: code
        }, function(res) {
            if (res.success) {
                $msg.html('<span style="color:#22c55e;">' + res.data.message + '</span>');
                // Reload to reflect new totals
                setTimeout(function() { location.reload(); }, 800);
            } else {
                $msg.html('<span style="color:#ef4444;">' + (res.data.message || 'Fehler') + '</span>');
                $btn.prop('disabled', false).text('Einlösen');
            }
        }).fail(function() {
            $msg.html('<span style="color:#ef4444;">Netzwerkfehler.</span>');
            $btn.prop('disabled', false).text('Einlösen');
        });
    });

})(jQuery);
