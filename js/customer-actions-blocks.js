(function (window, document) {
	'use strict';

	if (!window.wp || !window.wp.data) {
		return;
	}

	var strings = window.yoohwPreOrdersBlockActions && window.yoohwPreOrdersBlockActions.i18n
		? window.yoohwPreOrdersBlockActions.i18n
		: {};

	function setPreference(actionType) {
		document.cookie = 'yoohw_pre_orders_delivery_preference=' + encodeURIComponent(actionType) + '; path=/; SameSite=Lax';
		var checkout = window.wp.data.dispatch('wc/store/checkout');
		if (checkout && typeof checkout.setExtensionData === 'function') {
			checkout.setExtensionData('yoohw-pre-orders', {
				stock_status_customer_action: actionType
			});
		}
	}

	function handleClick(event) {
		var button = event.target.closest('.yoohw-pre-orders-block-delivery .button-customer-actions');
		if (!button) {
			return;
		}

		var actionType = button.getAttribute('data-action-type');
		var container = button.closest('.yoohw-pre-orders-block-delivery');
		var note = container ? container.querySelector('.stock-status-customer-actions-note') : null;
		if (actionType !== 'all_at_once' && actionType !== 'separately') {
			if (note) {
				note.textContent = strings.invalid_action || 'Invalid action. Please try again.';
			}
			return;
		}

		setPreference(actionType);
		container.querySelectorAll('.button-customer-actions').forEach(function (item) {
			item.classList.toggle('selected', item === button);
			item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
		});
		if (note) {
			note.textContent = actionType === 'all_at_once'
				? (strings.confirm_all || 'We will send all items at once.')
				: (strings.confirm_separately || 'We will send available items separately.');
		}
	}

	document.addEventListener('click', handleClick);
})(window, document);
