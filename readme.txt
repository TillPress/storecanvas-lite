=== StoreCanvas Lite ===
Contributors: alextechgamer
Tags: woocommerce, product options, extra product options, product addons, conditional logic
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0

Free WooCommerce product options: 15 field types, conditional logic, per-choice/lookup/percent/quantity/per-character pricing, and option stock.

== Description ==

StoreCanvas Lite adds a full product options engine to WooCommerce — free forever, no license key, no account, no phone-home.

* 15 field types: dropdown, radio, checkbox, multi-select, short text, long text, email, phone, phone (tel), number, date, color, image choice, file upload, and section heading
* Flexible pricing per field: flat fee, percent of the product price, per quantity, per character (text fields), and per-choice lookup-table pricing — flat and per-choice amounts may be negative for discounts
* Live price preview on the product page that mirrors exactly what the server charges (classic and block themes)
* Multi-rule conditional logic (AND/OR with is, is not, contains, greater/less than, empty, in-list operators) plus simple show-if rules
* Per-choice option stock: give any choice a stock quantity, sold-out choices are disabled, stock decrements on order and restores on cancel/failure
* Reusable global option groups assigned to products or whole categories, with product-local overrides
* Field visibility by user role and by product variation
* Validation on add to cart: required fields, character limits, number ranges, email/color/date formats, and option stock
* Chosen options are shown in cart, checkout, order emails, and the admin order screen
* Compatible with WooCommerce HPOS and block checkout

A paid **StoreCanvas** plugin (live design canvas with mockup placement, print-ready exports, and a production workflow) is sold separately at https://tillpress.com/storecanvas/. It is a different plugin. This Lite plugin is complete and is never locked.

== External services ==

None. StoreCanvas Lite makes no outbound network requests: there is no license check, no telemetry, no account, and no call to the plugin author's servers — ever.

== Installation ==

1. Upload the `storecanvas-lite` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu (WooCommerce must be active)
3. Edit any product and open the **StoreCanvas Lite** tab to add option fields
4. Optionally create reusable groups under **Products → Option groups** and assign them to products or categories

== Frequently Asked Questions ==

= Is there a license key or trial? =
No. Every feature listed above works without a key, trial, or phone-home to the author.

= How does pricing work? =
Each field can add a flat fee, a percent of the product (or selected variation) price, an amount multiplied by the number the customer enters, an amount per character of entered text, or a per-choice price from a lookup table. Negative amounts act as discounts; the unit price never drops below zero.

= What is option stock? =
Any choice on a dropdown, radio, multi-select, or image-choice field can carry a stock quantity. Sold-out choices are disabled on the product page, add-to-cart validates remaining stock, and stock decrements when an order is placed and restores if the order is cancelled or fails.

= Can I reuse the same fields on many products? =
Yes. Create an option group under Products → Option groups, then assign it to product IDs and/or product categories — or pick groups per product on the product's StoreCanvas Lite tab. Product-local fields with the same id override group fields.

= Does conditional logic support multiple rules? =
Yes. Fields can use multi-rule conditions with AND/OR logic and operators: is, is not, contains, does not contain, greater/less than (or equal), empty, not empty, and in-list.

= What if I also install StoreCanvas (the paid plugin)? =
Lite steps aside automatically so you do not get duplicate option fields or double charges. You can deactivate Lite.

= What does the paid StoreCanvas add? =
The live design canvas (customers place artwork and text on product mockups), print-ready exports, and a production workflow — at https://tillpress.com/storecanvas/.

== Screenshots ==

1. Product page with option fields and the live price breakdown.
2. Product-edit StoreCanvas Lite tab: visual field builder with pricing and conditions.
3. Option groups: reusable fields assigned to products or categories.
4. Order screen showing the chosen options and options total.

== Changelog ==

= 1.0.1 =
* Fix: show-if (conditional) fields now work on block themes such as Twenty Twenty-Five.
* Fix: order confirmation and emails show the customer's choices instead of raw sclt_price_extra.
* Fix: a plain Update on a product no longer deletes per-choice prices. Write prices as "Large=10".

= 1.0.0 =
* First public release: 15 field types, flat/percent/quantity/per-character/lookup pricing, multi-rule conditional logic, per-choice option stock, reusable option groups, live price preview.

== Upgrade Notice ==

= 1.0.0 =
First release.
