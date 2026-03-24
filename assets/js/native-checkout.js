/**
 * TIX Native Checkout – Frontend JS
 * Cart + Checkout ohne WooCommerce.
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
        var origText = $btn.text();

        // Validierung
        var required = $form.find('[required]');
        var firstInvalid = null;
        required.each(function() {
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

        $btn.prop('disabled', true).text('Wird verarbeitet…');
        $error.hide();

        $.post(ajax, $form.serialize(), function(r) {
            if (r.success && r.data && r.data.redirect) {
                window.location.href = r.data.redirect;
            } else {
                $btn.prop('disabled', false).text(origText);
                var msg = (r.data && r.data.message) ? r.data.message : 'Ein Fehler ist aufgetreten.';
                $error.show().text(msg);
            }
        }).fail(function(xhr) {
            $btn.prop('disabled', false).text(origText);
            var msg = 'Netzwerkfehler. Bitte versuche es erneut.';
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.data && r.data.message) msg = r.data.message;
            } catch(e) {
                if (xhr.responseText) msg = 'Server-Fehler: ' + xhr.responseText.substring(0, 200);
            }
            $error.show().text(msg);
            console.error('TIX Checkout Error:', xhr.status, xhr.responseText);
        });
    });

    // ── Artikel entfernen ──
    $(document).on('click', '.tix-co-remove', function() {
        var index = $(this).data('index');
        $.post(ajax, {
            action: 'tix_native_remove_item',
            nonce: nonce,
            index: index
        }, function() {
            location.reload();
        });
    });

    // ── Gateway-Auswahl visuell ──
    $(document).on('change', '.tix-co-gateways input[type="radio"]', function() {
        $('.tix-co-gateway').removeClass('active');
        $(this).closest('.tix-co-gateway').addClass('active');
    });

})(jQuery);
