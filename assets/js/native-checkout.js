/**
 * TIX Native Checkout – Frontend JS
 * Cart + Checkout ohne WooCommerce.
 * Nutzt die gleichen CSS-Klassen wie der WC-Checkout.
 */
(function($) {
    'use strict';

    if (typeof tixNativeCheckout === 'undefined') return;

    var ajax = tixNativeCheckout.ajaxUrl;
    var nonce = tixNativeCheckout.nonce;

    // ── Checkout Form Submit ──
    $(document).on('submit', '#tix-native-checkout-form', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $('#tix-native-pay-btn');
        var $error = $('#tix-native-checkout-error');

        // Validierung
        var firstInvalid = null;
        $form.find('[required]').each(function() {
            if ($(this).is(':checkbox') && !$(this).is(':checked')) {
                firstInvalid = firstInvalid || $(this);
            } else if (!$(this).val()) {
                firstInvalid = firstInvalid || $(this);
            }
        });

        if (firstInvalid) {
            firstInvalid.focus();
            $error.show().text('Bitte alle Pflichtfelder ausfüllen.');
            return;
        }

        // Loading-State
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
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.data && r.data.message) msg = r.data.message;
            } catch(e) {
                if (xhr.responseText) msg = 'Server-Fehler: ' + xhr.responseText.substring(0, 200);
            }
            $error.show().text(msg);
        });
    });

    // ── Artikel entfernen ──
    $(document).on('click', '.tix-co-item-remove, .tix-co-remove', function() {
        var index = $(this).data('index');
        $(this).closest('.tix-co-item').css('opacity', '0.3');
        $.post(ajax, {
            action: 'tix_native_remove_item',
            nonce: nonce,
            index: index
        }, function() {
            location.reload();
        });
    });

    // ── Menge ändern (+/-) ──
    $(document).on('click', '.tix-co-qty-btn', function() {
        var $btn = $(this);
        var index = $btn.data('index');
        var delta = parseInt($btn.data('delta'), 10);
        $btn.closest('.tix-co-item').css('opacity', '0.6');
        $.post(ajax, {
            action: 'tix_native_update_qty',
            nonce: nonce,
            index: index,
            delta: delta
        }, function() {
            location.reload();
        });
    });

    // ── Gateway-Auswahl ──
    $(document).on('change', '.tix-co-gw-radio', function() {
        $('.tix-co-gateway').removeClass('tix-co-gw-active');
        $(this).closest('.tix-co-gateway').addClass('tix-co-gw-active');
    });

})(jQuery);
