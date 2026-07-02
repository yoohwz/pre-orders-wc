jQuery(document).ready(function($) {
    // Adding a slight delay to ensure elements are present
    setTimeout(function() {
        // Iterate through each order row
        $('tr.type-shop_order').each(function() {
            var orderId;

            // Check for HPOS or legacy ID format
            if ($(this).attr('id').startsWith('post-')) {
                orderId = $(this).attr('id').replace('post-', '');
            } else if ($(this).attr('id').startsWith('order-')) {
                orderId = $(this).attr('id').replace('order-', '');
            }

            // Check if orderId is a valid number
            if (!isNaN(orderId)) {
                // Normalize order ID to integer for matching
                orderId = parseInt(orderId, 10);

                // Check if the order ID exists in the localized data
                if (yopowc_order_data.hasOwnProperty(orderId)) {
                    var orderData = yopowc_order_data[orderId];

                    // Apply classes based on preorder status
                    if (orderData.only_preorder) {
                        $(this).addClass('yoohw-only-preorder');
                    } else if (orderData.has_preorder) {
                        $(this).addClass('yoohw-mixed-preorder');
                    }
                }
            }
        });

		// Construct image paths based on the plugin URL passed from PHP
		const onlyPreorderIcon = `${myPluginData.pluginUrl}../../img/only-preorder.svg`;
		const mixedPreorderIcon = `${myPluginData.pluginUrl}../../img/mixed-preorder.svg`;

		$('<style>')
			.prop('type', 'text/css')
			.html(`
				tr.type-shop_order.yoohw-only-preorder a.order-view::before {
					content: url('${onlyPreorderIcon}');
					display: inline-block;
					transform: scale(1);
					margin-right: 5px;
				}
				
				tr.type-shop_order.yoohw-mixed-preorder a.order-view::before {
					content: url('${mixedPreorderIcon}');
					display: inline-block;
					transform: scale(1);
					margin-right: 5px;
				}
			`)
			.appendTo('head');
    }, 500); // Adjust the delay as needed
});
