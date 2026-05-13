(function ($) {
    'use strict';

    $(function () {

        var $form = $('#tix-co-form');
        var $cart = $('#tix-co-cart');
        var $msg  = $('#tix-co-message');
        var $btn  = $('#tix-co-submit');

        if (!$form.length && !$('#tix-co-login-section').length) return;

        // ══════════════════════════════════════
        // LOGIN
        // ══════════════════════════════════════
        $('#tix-co-login-toggle').on('click', function () {
            $('#tix-co-login-form').slideToggle(200);
        });

        $('#tix-co-login-btn').on('click', function () {
            var user = $('#dpc_login_email').val().trim();
            var pass = $('#dpc_login_pass').val();
            var $lmsg = $('#tix-co-login-msg');

            if (!user || !pass) {
                $lmsg.text('Bitte beide Felder ausfuellen.').attr('class', 'tix-co-login-msg error');
                return;
            }

            var $lbtn = $(this);
            $lbtn.prop('disabled', true).text('Anmelden…');

            $.post(dpcCo.ajaxUrl, {
                action: 'dpc_login',
                nonce:  dpcCo.nonce,
                user:   user,
                pass:   pass
            }, function (res) {
                if (res.success) {
                    $lmsg.text(res.data.message).attr('class', 'tix-co-login-msg success');
                    if (res.data.reload) setTimeout(function () { location.reload(); }, 600);
                } else {
                    $lmsg.text(res.data.message).attr('class', 'tix-co-login-msg error');
                    $lbtn.prop('disabled', false).text('Anmelden');
                }
            }).fail(function () {
                $lmsg.text('Verbindungsfehler.').attr('class', 'tix-co-login-msg error');
                $lbtn.prop('disabled', false).text('Anmelden');
            });
        });

        $('#dpc_login_email, #dpc_login_pass').on('keypress', function (e) {
            if (e.which === 13) { e.preventDefault(); $('#tix-co-login-btn').click(); }
        });

        // ══════════════════════════════════════
        // KONTO ERSTELLEN: Passwort-Feld toggle
        // ══════════════════════════════════════
        $('#createaccount').on('change', function () {
            if ($(this).is(':checked')) {
                $('#tix-co-account-pw').slideDown(200);
                $('#account_password').attr('required', true);
            } else {
                $('#tix-co-account-pw').slideUp(200);
                $('#account_password').removeAttr('required');
            }
        });

        // ══════════════════════════════════════
        // FIRMA: aufklappbar
        // ══════════════════════════════════════
        $('#tix-co-company-toggle').on('click', function () {
            var $field = $('#tix-co-company-field');
            if ($field.is(':visible')) {
                $field.slideUp(200);
                $(this).text('+ Firma hinzufuegen');
            } else {
                $field.slideDown(200);
                $(this).text('− Firma ausblenden');
                $field.find('input').focus();
            }
        });

        // ══════════════════════════════════════
        // GUTSCHEINCODE
        // ══════════════════════════════════════
        $('#tix-co-coupon-btn').on('click', applyCoupon);
        $('#tix-co-coupon-code').on('keypress', function (e) {
            if (e.which === 13) { e.preventDefault(); applyCoupon(); }
        });

        function applyCoupon() {
            var code  = $('#tix-co-coupon-code').val().trim();
            var $cmsg = $('#tix-co-coupon-msg');
            if (!code) return;

            $('#tix-co-coupon-btn').prop('disabled', true).text('…');

            $.post(dpcCo.ajaxUrl, {
                action:      'dpc_apply_coupon',
                nonce:       dpcCo.nonce,
                coupon_code: code
            }, function (res) {
                $('#tix-co-coupon-btn').prop('disabled', false).text('Einloesen');

                if (res.success) {
                    $cmsg.text(res.data.message).attr('class', 'tix-co-coupon-msg success').show();
                    $('#tix-co-coupon-code').val('');
                    updateTotals(res.data);
                    $('#tix-co-coupon-applied').html(res.data.coupons_html);
                } else {
                    $cmsg.text(res.data.message).attr('class', 'tix-co-coupon-msg error').show();
                }
                setTimeout(function () { $cmsg.fadeOut(300); }, 3000);
            }).fail(function () {
                $('#tix-co-coupon-btn').prop('disabled', false).text('Einloesen');
                $cmsg.text('Verbindungsfehler.').attr('class', 'tix-co-coupon-msg error').show();
            });
        }

        $(document).on('click', '.tix-co-coupon-remove', function (e) {
            e.preventDefault();
            var code = $(this).data('coupon');
            $.post(dpcCo.ajaxUrl, {
                action:      'dpc_remove_coupon',
                nonce:       dpcCo.nonce,
                coupon_code: code
            }, function (res) {
                if (res.success) {
                    updateTotals(res.data);
                    $('#tix-co-coupon-applied').html(res.data.coupons_html);
                }
            });
        });

        // ══════════════════════════════════════
        // WARENKORB: Menge / entfernen
        // ══════════════════════════════════════
        $cart.on('click', '.tix-co-qty-minus, .tix-co-qty-plus, .tix-co-item-remove', function (e) {
            e.preventDefault();
            var $el = $(this), key = $el.data('key'), action;
            if ($el.hasClass('tix-co-qty-plus'))    action = 'increase';
            if ($el.hasClass('tix-co-qty-minus'))   action = 'decrease';
            if ($el.hasClass('tix-co-item-remove')) action = 'remove';
            if (!action || !key) return;

            $cart.addClass('tix-co-loading');

            $.post(dpcCo.ajaxUrl, {
                action:      'dpc_update_cart',
                nonce:       dpcCo.nonce,
                cart_key:    key,
                cart_action: action
            }, function (res) {
                $cart.removeClass('tix-co-loading');
                if (res.success) {
                    if (res.data.empty) { location.reload(); return; }
                    $cart.html(res.data.html);
                    updateTotals(res.data);
                    $('#tix-co-coupon-applied').html(res.data.coupons_html);
                } else {
                    showMessage(res.data.message || 'Fehler.', 'error');
                }
            }).fail(function () {
                $cart.removeClass('tix-co-loading');
                showMessage('Verbindungsfehler.', 'error');
            });
        });

        // ══════════════════════════════════════
        // ZAHLUNGSARTEN: Wechseln
        // ══════════════════════════════════════
        $form.on('change', '.tix-co-gw-radio', function () {
            var $gw = $(this).closest('.tix-co-gateway');
            $('.tix-co-gateway').removeClass('tix-co-gw-active');
            $('.tix-co-gw-fields').slideUp(150);
            $gw.addClass('tix-co-gw-active');
            $gw.find('.tix-co-gw-fields').slideDown(150);
        });

        $form.on('click', '.tix-co-gateway', function (e) {
            if ($(e.target).closest('input, select, textarea, a, button, label').length) return;
            var $radio = $(this).find('.tix-co-gw-radio');
            if (!$radio.is(':checked')) $radio.prop('checked', true).trigger('change');
        });

        // ══════════════════════════════════════
        // FORMULAR ABSENDEN
        // ══════════════════════════════════════
        $form.on('submit', function (e) {
            e.preventDefault();

            var valid = true;
            var firstErrStep = null;
            $form.find('.tix-co-field-error').removeClass('tix-co-field-error');

            $form.find('[required]').each(function () {
                var $input = $(this);
                if (!$input.val() || ($input.is(':checkbox') && !$input.is(':checked'))) {
                    $input.closest('.tix-co-field, .tix-co-legal, .tix-co-terms').addClass('tix-co-field-error');
                    valid = false;
                    if (!firstErrStep && typeof dpcCo !== 'undefined' && dpcCo.useSteps) {
                        var $panel = $input.closest('.tix-co-step-panel');
                        if ($panel.length) firstErrStep = parseInt($panel.data('step'), 10);
                    }
                }
            });

            if (!valid) {
                if (firstErrStep && typeof dpcCo !== 'undefined' && dpcCo.useSteps) {
                    $('.tix-co-step-panel').removeClass('tix-co-step-visible');
                    $('.tix-co-step-panel[data-step="' + firstErrStep + '"]').addClass('tix-co-step-visible');
                    $('.tix-co-step-ind').each(function () {
                        var s = parseInt($(this).data('step'), 10);
                        $(this).removeClass('tix-co-step-active tix-co-step-done');
                        if (s === firstErrStep) $(this).addClass('tix-co-step-active');
                        if (s < firstErrStep)   $(this).addClass('tix-co-step-done');
                    });
                }
                showMessage('Bitte fuelle alle Pflichtfelder aus.', 'error');
                var $firstErr = $form.find('.tix-co-field-error').filter(':visible').first();
                if ($firstErr.length) $('html, body').animate({ scrollTop: $firstErr.offset().top - 100 }, 300);
                return;
            }

            $btn.prop('disabled', true);
            $btn.find('.tix-co-submit-text').hide();
            $btn.find('.tix-co-submit-loading').show();
            hideMessage();

            $.ajax({
                type:     'POST',
                url:      dpcCo.wcCheckoutUrl,
                data:     $form.serialize(),
                dataType: 'json',
                success:  function (result) {
                    if (result.result === 'success' && result.redirect) {
                        window.location.href = result.redirect;
                    } else if (result.result === 'failure') {
                        var errors = '';
                        if (result.messages) {
                            var $tmp = $('<div>').html(result.messages);
                            errors = $tmp.find('li').map(function () { return $(this).text().trim(); }).get().join('\n');
                            if (!errors) errors = $tmp.text().trim();
                        }
                        showMessage(errors || 'Bestellung konnte nicht abgeschlossen werden.', 'error');
                        resetBtn();
                    } else {
                        showMessage('Unbekannte Antwort.', 'error');
                        resetBtn();
                    }
                },
                error: function (xhr, status, err) {
                    if (xhr.status === 200 && xhr.responseText) {
                        try {
                            var result = JSON.parse(xhr.responseText);
                            if (result.result === 'success' && result.redirect) {
                                window.location.href = result.redirect;
                                return;
                            }
                        } catch (parseErr) { /* fall-through */ }
                    }
                    showMessage('Verbindungsfehler: ' + (err || status), 'error');
                    resetBtn();
                }
            });
        });

        // ══════════════════════════════════════
        // HELPERS
        // ══════════════════════════════════════
        function updateTotals(data) {
            if (data.subtotal) $('.tix-co-subtotal').html(data.subtotal);
            if (data.tax)      $('.tix-co-tax').html(data.tax);
            if (data.total) {
                $('.tix-co-total').html(data.total);
                var $bp = $('.tix-co-submit-price');
                if ($bp.length) $bp.html(data.total);
            }

            var $couponRow = $('.tix-co-coupon-discount-row');
            if (data.has_discount) {
                $couponRow.show();
                $('.tix-co-discount').html(data.discount);
            } else {
                $couponRow.hide();
            }

            if (data.fees_html !== undefined) {
                $('.tix-co-fees').html(data.fees_html);
            }
        }

        function resetBtn() {
            $btn.prop('disabled', false);
            $btn.find('.tix-co-submit-text').show();
            $btn.find('.tix-co-submit-loading').hide();
        }

        function showMessage(text, type) {
            $msg.text(text).attr('class', 'tix-co-message tix-co-msg-' + type).show();
            $('html, body').animate({ scrollTop: $msg.offset().top - 100 }, 300);
        }

        function hideMessage() { $msg.hide(); }

        $form.on('input change', '.tix-co-input, .tix-co-select, .tix-co-check', function () {
            $(this).closest('.tix-co-field, .tix-co-legal, .tix-co-terms').removeClass('tix-co-field-error');
        });

        // ══════════════════════════════════════
        // 3-STEP CHECKOUT
        // ══════════════════════════════════════
        if (typeof dpcCo !== 'undefined' && dpcCo.useSteps) {

            var currentStep = 1;

            function goToStep(step) {
                if (step > currentStep) {
                    var $currentPanel = $('.tix-co-step-panel[data-step="' + currentStep + '"]');
                    var valid = true;
                    $currentPanel.find('.tix-co-field-error').removeClass('tix-co-field-error');

                    $currentPanel.find('[required]').each(function () {
                        var $input = $(this);
                        if (!$input.val() || ($input.is(':checkbox') && !$input.is(':checked'))) {
                            $input.closest('.tix-co-field, .tix-co-legal').addClass('tix-co-field-error');
                            valid = false;
                        }
                    });

                    if (!valid) {
                        var $firstErr = $currentPanel.find('.tix-co-field-error').first();
                        if ($firstErr.length) $('html, body').animate({ scrollTop: $firstErr.offset().top - 100 }, 300);
                        return;
                    }
                }

                currentStep = step;

                $('.tix-co-step-panel').removeClass('tix-co-step-visible');
                $('.tix-co-step-panel[data-step="' + step + '"]').addClass('tix-co-step-visible');

                $('.tix-co-step-ind').each(function () {
                    var s = parseInt($(this).data('step'), 10);
                    $(this).removeClass('tix-co-step-active tix-co-step-done');
                    if (s === step) $(this).addClass('tix-co-step-active');
                    if (s < step)   $(this).addClass('tix-co-step-done');
                });

                var $co = $('#tix-co');
                if ($co.length) $('html, body').animate({ scrollTop: $co.offset().top - 20 }, 200);
            }

            $(document).on('click', '.tix-co-step-next, .tix-co-step-back', function (e) {
                e.preventDefault();
                goToStep(parseInt($(this).data('goto'), 10));
            });

            $(document).on('click', '.tix-co-step-ind.tix-co-step-done', function () {
                goToStep(parseInt($(this).data('step'), 10));
            });
        }

        // ══════════════════════════════════════
        // CHECKOUT COUNTDOWN
        // ══════════════════════════════════════
        (function () {
            try {
                var minutes = parseInt(dpcCo.countdown, 10);
                if (!minutes || !$('#tix-co-countdown').length) return;

                var STORAGE_KEY = 'dpc_co_countdown_end';
                var $wrap = $('#tix-co-countdown');
                var $bar  = $('#tix-co-countdown-bar');
                var $time = $('#tix-co-countdown-time');
                var totalSeconds = minutes * 60;

                var endTime = parseInt(sessionStorage.getItem(STORAGE_KEY), 10);
                if (!endTime || isNaN(endTime) || endTime <= Date.now()) {
                    endTime = Date.now() + totalSeconds * 1000;
                    sessionStorage.setItem(STORAGE_KEY, endTime);
                }

                function tick() {
                    var remaining = Math.max(0, Math.round((endTime - Date.now()) / 1000));
                    var pct = remaining / totalSeconds;
                    var m = Math.floor(remaining / 60);
                    var s = remaining % 60;
                    $time.text((m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s);
                    $bar.css('transform', 'scaleX(' + pct + ')');
                    $bar.removeClass('tix-co-countdown-warn tix-co-countdown-crit');
                    if (pct <= 0.15)      $bar.addClass('tix-co-countdown-crit');
                    else if (pct <= 0.33) $bar.addClass('tix-co-countdown-warn');

                    if (remaining <= 0) {
                        clearInterval(timer);
                        sessionStorage.removeItem(STORAGE_KEY);
                        expireCart();
                    }
                }

                function expireCart() {
                    $wrap.html('<div class="tix-co-countdown-expired">⏰ Zeit abgelaufen — dein Warenkorb wurde geleert.</div>');
                    $.post(dpcCo.ajaxUrl, {
                        action: 'dpc_countdown_clear',
                        nonce:  dpcCo.nonce
                    }, function () {
                        if ($form.length) $form.find('input, select, textarea, button').prop('disabled', true);
                        if ($btn.length)  $btn.prop('disabled', true);
                        setTimeout(function () {
                            window.location.href = window.location.pathname.split('?')[0];
                        }, 3000);
                    });
                }

                tick();
                var timer = setInterval(tick, 1000);
            } catch (e) { /* countdown init failed */ }
        })();

    });

})(jQuery);

// ══════════════════════════════════════
// GOOGLE PLACES AUTOCOMPLETE (optional)
// ══════════════════════════════════════
function dpcInitAutocomplete() {
    var addressInput = document.getElementById('billing_address_1');
    if (!addressInput || typeof google === 'undefined') return;

    var autocomplete = new google.maps.places.Autocomplete(addressInput, {
        types:  ['address'],
        fields: ['address_components', 'formatted_address']
    });

    var countrySelect = document.getElementById('billing_country');
    if (countrySelect && countrySelect.value) {
        autocomplete.setComponentRestrictions({ country: countrySelect.value.toLowerCase() });
    }
    if (countrySelect) {
        countrySelect.addEventListener('change', function () {
            autocomplete.setComponentRestrictions({ country: this.value.toLowerCase() });
        });
    }

    autocomplete.addListener('place_changed', function () {
        var place = autocomplete.getPlace();
        if (!place.address_components) return;

        var street_number = '', route = '', city = '', postcode = '';

        place.address_components.forEach(function (component) {
            var types = component.types;
            if (types.indexOf('street_number') > -1)                 street_number = component.long_name;
            if (types.indexOf('route') > -1)                         route = component.long_name;
            if (types.indexOf('locality') > -1)                      city = component.long_name;
            if (types.indexOf('sublocality_level_1') > -1 && !city)  city = component.long_name;
            if (types.indexOf('postal_town') > -1 && !city)          city = component.long_name;
            if (types.indexOf('postal_code') > -1)                   postcode = component.long_name;
        });

        if (route) {
            addressInput.value = route + (street_number ? ' ' + street_number : '');
        }

        var cityInput     = document.getElementById('billing_city');
        var postcodeInput = document.getElementById('billing_postcode');

        if (cityInput && city)         { cityInput.value = city; jQuery(cityInput).trigger('change'); }
        if (postcodeInput && postcode) { postcodeInput.value = postcode; jQuery(postcodeInput).trigger('change'); }
    });
}
