jQuery(document).ready(function($) {
    // When a variation is selected, WooCommerce fires 'found_variation'
    $('.variations_form').on('found_variation', function(event, variation) {
        if ( variation.stock_status === 'onpreorder' ) {
            // Use the localized “Pre‐order now” text
            $('.single_add_to_cart_button').text( stockStatusPreorderTexts.preorder_text );
        } else {
            // Otherwise show the localized “Add to cart” text
            $('.single_add_to_cart_button').text( stockStatusPreorderTexts.add_to_cart_text );
        }
    });

    // When variations are reset (no variation chosen), revert to “Add to cart”
    $('.variations_form').on('reset_data', function() {
        $('.single_add_to_cart_button').text( stockStatusPreorderTexts.add_to_cart_text );
    });
});
