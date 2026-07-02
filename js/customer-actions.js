jQuery(document).ready(function ($) {
    $('.stock-status-customer-actions .button').on('click', function (e) {
        e.preventDefault();

        // Figure out which button was clicked
	    let clickedId  = $(this).attr('id');
	    let actionType = '';
	    let orderId    = $(this).data('order-id') || null;
	    let orderKey   = $(this).data('order-key') || '';

        // Two possible IDs: 
        // - On Thank You page: 'receive-all-products' or 'receive-separately'
        // - On Checkout page: 'receive-all-products-checkout' or 'receive-separately-checkout'
        if ( clickedId === 'receive-all-products' || clickedId === 'receive-all-products-checkout' ) {
            actionType = 'all_at_once';
        } else if ( clickedId === 'receive-separately' || clickedId === 'receive-separately-checkout' ) {
            actionType = 'separately';
        } else {
            // Unexpected button ID → use translatable string
            alert( stockStatusActions.i18n.invalid_action );
            return;
        }

        // === CASE 1: If orderId is present (Thank You page) → do AJAX ===
        if ( orderId ) {
            // Disable both buttons to prevent double‐click
            $('.stock-status-customer-actions .button').prop('disabled', true);

            $.ajax({
                url: stockStatusActions.ajax_url,
                type: 'POST',
                data: {
	                    action: 'handle_stock_status_action',
	                    order_id: orderId,
	                    order_key: orderKey,
	                    action_type: actionType,
	                    _wpnonce: stockStatusActions.nonce,
                },
                success: function (response) {
                    if ( response.success ) {
                        alert( response.data.message );
                        $('.stock-status-customer-actions').fadeOut();
                    } else if ( response.data && response.data.message ) {
                        alert( response.data.message );
                    } else {
                        alert( stockStatusActions.i18n.unexpected_error );
                    }
                },
                error: function () {
                    alert( stockStatusActions.i18n.ajax_error );
                },
                complete: function () {
                    $('.stock-status-customer-actions .button').prop('disabled', false);
                }
            });

            return;
        }

        // === CASE 2: No orderId → we must be on the Checkout page. ===
        // Simply write the chosen value into the hidden input and show a brief confirmation.

        let $hiddenField = $('#stock_status_customer_action');
        if ( !$hiddenField.length ) {
            alert( stockStatusActions.i18n.unable_record );
            return;
        }

        // Set the hidden value
        $hiddenField.val( actionType );

        // Show a quick, localized confirmation notice
        let confirmMsg = '';
        if ( actionType === 'all_at_once' ) {
            confirmMsg = stockStatusActions.i18n.confirm_all;
        } else {
            confirmMsg = stockStatusActions.i18n.confirm_separately;
        }
        alert( confirmMsg );

        // Hide the buttons container immediately
        $('.stock-status-customer-actions').hide();
    });
});
