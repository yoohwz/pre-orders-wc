jQuery(document).ready(function($) {
	function togglePreOrderDateField() {
		// Single products
		$('[id^="_stock_status"]').each(function() {
			var stockStatus = $(this).val();
			var preOrderDateField = $(this)
				.closest('.form-field')
				.siblings('.form-field')
				.find('[id^="_pre_order_available_date"]')
				.closest('.form-field');
			var manageStockChecked = $('#_manage_stock').is(':checked');
			var preOrderLimitField = $('.pre-order-limit-input').not('.woocommerce_variation .pre-order-limit-input');

			if (stockStatus === 'onpreorder' && !manageStockChecked) {
				preOrderDateField.show();
			} else {
				preOrderDateField.hide();
			}
			preOrderLimitField.toggle(stockStatus === 'onpreorder');
		});

		// Variations
		$('.woocommerce_variation').each(function() {
			var $variation = $(this);
			var stockStatus = $variation.find('[id^="variable_stock_status"]').val();
			var variationId = $variation.find('input.variable_post_id').val();
			var preOrderDateField = $variation
				.find(`[id="_pre_order_available_date_${variationId}"]`)
				.closest('.form-row');
			// find by class now
			var manageStockChecked = $variation
				.find('input.variable_manage_stock')
				.is(':checked');
			var preOrderLimitField = $variation.find('.pre-order-limit-input');

			if (stockStatus === 'onpreorder' && !manageStockChecked) {
				preOrderDateField.show();
			} else {
				preOrderDateField.hide();
			}
			preOrderLimitField.toggle(stockStatus === 'onpreorder');
		});
	}

	// Initial run
	togglePreOrderDateField();

	// Re-run when stock status or manage-stock toggles
	$(document).on(
		'change',
		'[id^="_stock_status"], #_manage_stock, [id^="variable_stock_status"], input.variable_manage_stock',
		togglePreOrderDateField
	);
	$(document).on('woocommerce_variations_loaded', togglePreOrderDateField);
});
