=== Pre-Orders for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, pre order, preorder, stock status, availability date
Requires at least: 6.5
Tested up to: 7.0
WC tested up to: 10.9
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add WooCommerce preorders with availability dates, order limits, delivery choices, an operational overview, and customer emails.

== Description ==

Pre-Orders for WooCommerce helps store owners sell products before they are available by adding a dedicated `On preorder` stock status to WooCommerce products and variations.

Use it to show preorder availability dates, replace the add-to-cart button with a preorder button, limit preorder quantities, collect delivery preferences for mixed orders, and display preorder information across product pages, carts, checkout, orders, and emails.

The paginated Preorder overview brings active products, open-order demand, upcoming dates, overdue products, and customer delay updates together under **Products > Pre-orders**.

The plugin is built for stores that need a simple WooCommerce preorder workflow without turning every product into a complex reservation system.

[Premium version](https://yoohw.com/product/woocommerce-extended-stock-status/) | [Documentation](https://docs.yoohw.com/category/woocommerce-extended-stock-status/) | [Support](https://yoohw.com/support/)  | [Demo](https://sandbox.yoohw.com/demo/wcess_demo.html)

== Key Features ==

* **WooCommerce preorder stock status** - Add an `On preorder` stock status for simple products and variations.
* **Product availability date** - Set the expected available date for each preorder product.
* **Preorder add-to-cart button** - Display a custom preorder button instead of the standard add-to-cart text.
* **Product page preorder notice** - Show customers when a preorder product is expected to become available.
* **Shop, cart, and checkout display** - Show preorder labels and dates throughout the shopping flow.
* **Order and email preorder details** - Include preorder status and availability dates in order details and WooCommerce emails.
* **Preorder overview** - Review active preorder products, open orders, units, due dates, overdue products, and pending customer updates in one paginated screen.
* **Maximum preorder quantity** - Set a per-order preorder limit for simple products or individual variations.
* **Customer delivery preference** - Let customers with mixed carts choose whether to receive all items together or receive available items separately in classic or block checkout.
* **Customer availability email** - Send an email when preorder products become available.
* **Delay notification** - Find affected open orders and send a WooCommerce-styled email when an expected date moves later.
* **Admin availability reminder** - Notify the store administrator before a preorder product reaches its available date.
* **Automatic stock update** - Change products from `On preorder` to `In stock` when the available date is reached.
* **Stable order history** - Preserve each preorder item's status and expected date on the order, even after the product changes later.
* **HPOS and Blocks ready** - Declares compatibility with WooCommerce HPOS and Cart/Checkout Blocks.

== Why Use Pre-Orders for WooCommerce? ==

Preorder sales help WooCommerce stores validate demand, accept early orders, and communicate clear delivery expectations before inventory arrives.

This plugin focuses on the core preorder experience:

* Mark products as preorder items.
* Show clear availability information to customers.
* Control the maximum preorder quantity allowed in each order.
* Keep preorder details visible in carts, checkout, orders, and emails.
* Notify customers and administrators at the right time.
* Automatically return products to normal in-stock status when they become available.

== Preorder Operations ==

Use **Products > Pre-orders** to manage day-to-day preorder activity without opening products and orders one by one.

* See active preorder products, open orders, and total preorder units.
* Filter products that are overdue, due within seven days, scheduled later, or missing an availability date.
* Search by product name or SKU.
* Compare the current product date with the date recorded on each order.
* Review affected orders before sending a delay notification.
* Send WooCommerce-styled customer updates without sending the same update repeatedly for the same date.

== How It Works ==

1. Edit a WooCommerce product or variation.
2. Set the stock status to `On preorder`.
3. Choose the product availability date.
4. Optionally set a Preorder limit for the maximum quantity allowed per order.
5. Save the product.
6. Customers see preorder labels, availability dates, and preorder button text on the storefront.
7. Mixed carts can collect the customer's delivery preference in classic or block checkout.
8. Use **Products > Pre-orders** to monitor demand, deadlines, and customer date updates.
9. When the date arrives, the plugin can automatically update the product to `In stock` and notify customers.

== External Services ==

The plugin contains an optional developer integration with a YoOhw subscription service. It is disabled by default and no data is sent unless a site developer explicitly enables the `yoohw_pre_orders_wc_enable_push_subscription` filter.

When enabled, the request is made after the installed plugin version changes and is used to register the installation for product communication and support follow-up.

Service endpoint: `https://yoexpress.top/wp-json/yo-pr/v1/email-subscriptions`

Data sent: site administrator email, WooCommerce new order recipient email addresses, site domain, plugin product code (`wcpo`), and WooCommerce default country.

Service provider: YoOhw, https://yoohw.com/

Privacy policy: https://yoohw.com/privacy-policy/

Developers can opt in with the `yoohw_pre_orders_wc_enable_push_subscription` filter. Returning `true` enables the request; the default is `false`.

== Installation ==

1. Upload the `pre-orders-wc` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Make sure WooCommerce is installed and active.
4. Go to WooCommerce product settings and set a product stock status to `On preorder`.
5. Choose an availability date and save the product.
6. Open **Products > Pre-orders** to review active preorder operations.

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

Yes. For mixed orders containing preorder and currently available items, customers can choose whether they want to receive all items together or receive available items separately. This works with classic and block checkout when Delivery preference is enabled.

= Can I limit the number of preorder units per order? =

Yes. Set the Preorder limit in the product's Inventory settings. A value of `0` means unlimited, and variations can inherit the limit from their parent product.

= Where can I see current preorder demand? =

Open **Products > Pre-orders** to see active preorder products, open-order counts, preorder units, schedule status, overdue dates, and customer updates. The table supports pagination, status filters, and product or SKU search.

= Can I notify customers when an expected date is delayed? =

Yes. The Preorder overview identifies affected open orders when a product's current expected date is later than the date captured at checkout. You can review the count and send a WooCommerce-styled update without repeatedly emailing the same order for the same date.

= Does this plugin process preorder payments separately? =

No. Payments are handled by WooCommerce and your active WooCommerce payment gateways. This plugin adds preorder stock status, availability display, customer choices, and notifications.

= Can I customize the preorder button text? =

Yes. You can change the preorder button text in the plugin settings.

= Is this compatible with WooCommerce block cart and checkout? =

Yes. The plugin displays preorder information in classic and block-based WooCommerce cart and checkout flows. Mixed carts can also collect and save the customer's delivery preference in either checkout experience.

= Will existing preorder data be preserved when I update? =

Yes. Versioned migrations retain existing product dates, settings, and order data. New orders also store preorder status and expected-date snapshots so their history remains accurate if the product changes later.

= Is there a Pro add-on for advanced preorder management? =

The free plugin includes a complete product-level preorder workflow. WooCommerce Stock Manager adds operational inventory workflows such as restock batches and receiving, inventory movements, suppliers, purchase orders, reorder suggestions, allocation, fulfillment readiness, reports, and multi-location stock. Existing free-product dates and preorder order data remain compatible when upgrading.

== Changelog ==

= 1.3.0 (July 18, 2026) =
* New: Added a paginated Preorder overview with operational metrics, schedule filters, product search, open-order demand, and customer-update counts.
* New: Added maximum preorder quantity per order for simple products and variations, including parent inheritance.
* New: Added manual delay notifications for affected open orders using the configured WooCommerce email design.
* New: Added customer delivery preferences to WooCommerce block checkout for mixed preorder carts.
* Improve: Preserved preorder status and expected-date snapshots on order items so order history remains accurate after products change.
* Improve: Standardized product dates and order tracking with Stock Manager while retaining legacy free-plugin data for seamless upgrades.
* Improve: Added official HPOS and Cart/Checkout Blocks compatibility declarations.
* Improve: Updated settings, upgrade guidance, admin styling, date formatting, and variation handling.
* Security: Made the optional external subscription integration opt-in and disabled it by default.
* Dev: Added versioned, idempotent data migration and extension hooks for delivery preferences and database upgrades.

For older release notes, see `changelog.txt`.
