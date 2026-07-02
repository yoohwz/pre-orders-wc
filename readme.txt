=== Pre-Orders for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, pre order, preorder, stock status, availability date
Requires at least: 6.5
Tested up to: 7.0
WC tested up to: 10.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add WooCommerce pre-orders with preorder stock status, availability dates, custom buttons, cart notices, and customer emails.

== Description ==

Pre-Orders for WooCommerce helps store owners sell products before they are available by adding a dedicated `On preorder` stock status to WooCommerce products and variations.

Use it to show preorder availability dates, replace the add-to-cart button with a preorder button, display preorder information across product pages, carts, checkout, orders, and emails, and notify customers when preorder items become available.

The plugin is built for stores that need a simple WooCommerce preorder workflow without turning every product into a complex reservation system.

[Premium version](https://yoohw.com/product/woocommerce-extended-stock-status/) | [Documentation](https://docs.yoohw.com/category/woocommerce-extended-stock-status/) | [Support](https://yoohw.com/support/)  | [Demo](https://sandbox.yoohw.com/demo/wcess_demo.html)

== Key Features ==

* **WooCommerce preorder stock status** - Add an `On preorder` stock status for simple products and variations.
* **Product availability date** - Set the expected available date for each preorder product.
* **Preorder add-to-cart button** - Display a custom preorder button instead of the standard add-to-cart text.
* **Product page preorder notice** - Show customers when a preorder product is expected to become available.
* **Shop, cart, and checkout display** - Show preorder labels and dates throughout the shopping flow.
* **Order and email preorder details** - Include preorder status and availability dates in order details and WooCommerce emails.
* **Customer delivery preference** - Let customers choose whether to receive all items together or receive available items separately.
* **Customer availability email** - Send an email when preorder products become available.
* **Admin availability reminder** - Notify the store administrator before a preorder product reaches its available date.
* **Automatic stock update** - Change products from `On preorder` to `In stock` when the available date is reached.

== Why Use Pre-Orders for WooCommerce? ==

Preorder sales help WooCommerce stores validate demand, accept early orders, and communicate clear delivery expectations before inventory arrives.

This plugin focuses on the core preorder experience:

* Mark products as preorder items.
* Show clear availability information to customers.
* Keep preorder details visible in carts, checkout, orders, and emails.
* Notify customers and administrators at the right time.
* Automatically return products to normal in-stock status when they become available.

== How It Works ==

1. Edit a WooCommerce product or variation.
2. Set the stock status to `On preorder`.
3. Choose the product availability date.
4. Save the product.
5. Customers see preorder labels, availability dates, and preorder button text on the storefront.
6. When the date arrives, the plugin can automatically update the product to `In stock` and notify customers.

== External Services ==

This plugin connects to a YoOhw subscription service when the plugin version changes. The request is used to register the installation for product communication and support follow-up.

Service endpoint: `https://yoexpress.top/wp-json/yo-pr/v1/email-subscriptions`

Data sent: site administrator email, WooCommerce new order recipient email addresses, site domain, plugin product code (`wcpo`), and WooCommerce default country.

Service provider: YoOhw, https://yoohw.com/

Privacy policy: https://yoohw.com/privacy-policy/

Developers can disable this request with the `yoohw_pre_orders_wc_enable_push_subscription` filter.

== Installation ==

1. Upload the `pre-orders-wc` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Make sure WooCommerce is installed and active.
4. Go to WooCommerce product settings and set a product stock status to `On preorder`.
5. Choose an availability date and save the product.

== Frequently Asked Questions ==

= How do I create a WooCommerce preorder product? =

Edit a WooCommerce product, open the product data section, set the stock status to `On preorder`, choose an availability date, and save the product.

= Does this plugin support variable products? =

Yes. You can enable preorder status and availability dates for WooCommerce variations.

= Can customers see the preorder availability date? =

Yes. The availability date can appear on product pages, product lists, cart, checkout, order details, and WooCommerce email notifications.

= What happens when the preorder date arrives? =

The plugin can automatically update the product stock status from `On preorder` to `In stock` on the available date.

= Does this plugin send preorder email notifications? =

Yes. Customers can receive an email when preorder products become available. Store administrators can also receive an availability reminder before the product date.

= Can customers choose how preorder items are delivered? =

Yes. For orders that include preorder items, customers can choose whether they want to receive all items together or receive available items separately.

= Does this plugin process preorder payments separately? =

No. Payments are handled by WooCommerce and your active WooCommerce payment gateways. This plugin adds preorder stock status, availability display, customer choices, and notifications.

= Can I customize the preorder button text? =

Yes. You can change the preorder button text in the plugin settings.

= Is this compatible with WooCommerce block cart and checkout? =

The plugin displays preorder information in both classic and block-based WooCommerce cart and checkout flows.

= Is there a Pro add-on for advanced preorder management? =

The free plugin includes the core WooCommerce preorder workflow. For advanced preorder management, purchase the premium plugin for bulk preorder scheduling, automated email sequences, preorder reports, and additional workflow integrations.

== Changelog ==

= 1.2.0 (June 13, 2026) =
* Security: Hardened customer delivery preference AJAX requests by validating nonce, action type, order ownership, order key, and duplicate submissions.
* Fix: Validated checkout delivery preference nonce before saving customer choices.
* Fix: Corrected cart and checkout preorder date display to read the saved `_pre_order_available_date` value.
* Fix: Stored preorder order item meta from checkout line items for classic and block checkout compatibility.
* Fix: Matched variation IDs during preorder auto-update so variation preorder notifications and order notes work correctly.
* Fix: Moved deactivation cron cleanup to the main plugin file.
* Improve: Added defensive WooCommerce dependency checks before loading plugin components.
* Improve: Preserved existing WooCommerce product setting sections when adding preorder settings.

For older release notes, see `changelog.txt`.
