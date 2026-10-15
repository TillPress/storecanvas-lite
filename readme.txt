=== StoreCanvas Lite ===
Contributors: alextechgamer
Tags: woocommerce, product options, extra product options, product addons, conditional logic
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0

Free WooCommerce product options: 15 field types, conditional fields, per-choice and per-character pricing, live price, and option stock.

== Description ==

StoreCanvas Lite adds extra choices to your WooCommerce products: sizes, finishes, colours, engraving text, gift wrap, gift notes, rush production, file names and more. Each choice can change the price, and the price on the product page updates as the customer picks. Free forever, with no license key, no account and no phone-home.

**What your customer sees.** Under the product's price, your option fields appear above the Add to cart button. As they choose, a line such as "Base: $14.00 + options: $13.30 = $27.30" updates live. Fields you mark as required must be filled before the item can be added to the cart. Their choices then follow the order everywhere: cart, checkout, order emails and the order screen in your admin.

**What you set up.** On any product, open the **StoreCanvas Lite** tab in the Product data box and click **Add field**. Give the field a label, pick a type, choose how it is priced and (for dropdowns, radios and image choices) type the choices. That is all a basic option needs. Everything else (stock per choice, fields that only appear after another choice, fields reused across many products) is optional and explained step by step below.

= Features =

* **15 field types:** dropdown, radio buttons, checkbox, multi-select, short text, long text, email, phone, phone (tel), number, date, colour picker, image choice, file upload (the file name is saved with the order) and section heading.
* **5 ways to price a field:** a flat fee, a percent of the product price, an amount times a number the customer enters, an amount per character typed (engraving), or a different price for each choice (lookup table). Negative amounts work as discounts; the price never drops below zero.
* **Live price** on the product page that matches what the cart charges, on classic and block themes.
* **Conditional fields:** show a field only when another field has a certain value (for example, show "Gift note" only when "Gift wrap" is ticked). Hidden fields are never charged or required.
* **Stock per choice:** give any choice its own stock count. Sold-out choices are disabled on the product page, stock goes down when an order is placed and comes back when an order is cancelled, fails, or a refund restocks it.
* **Option groups:** build a set of fields once (for example "Gift options") and apply it to whole product categories or a list of products.
* **Per role and per variation:** show a field only to certain user roles (for example wholesale) or only for certain variations of a variable product.
* **Validation:** required fields, minimum and maximum characters, number ranges and steps, and email, colour and date formats are checked before add to cart.
* **Orders:** each choice and its price appear as line-item details in the cart, checkout, emails and admin order screen.
* **Compatible** with WooCommerce HPOS (custom order tables), the block cart and block checkout.
* **Help & demo page** with a one-click demo product and a support ticket form.

= Setup guide =

**Step 1: Install and activate.** In WordPress go to Plugins → Add New, search for "StoreCanvas Lite", click Install and then Activate. WooCommerce must be active. A **Help & demo** link appears under the plugin's name on the Plugins screen, and the same page lives at Products → StoreCanvas Lite help.

**Step 2 (optional): Look at the demo.** On Products → StoreCanvas Lite help, click **Create demo data**. It creates one hidden product (not shown in your shop or search) that uses every pricing type, a conditional field and stock per choice, plus one option group. Open it from the links on that page to see the product page, the live price and the cart. Click **Remove demo data** when you are done; uninstalling the plugin removes it too.

**Step 3: Add your first field.** Edit a product. In the Product data box, open the **StoreCanvas Lite** tab and click **Add field**. A new row appears. Fill in:

* **Label:** what the customer reads, for example "Size".
* **Type:** for example Dropdown or Radio.
* **Required:** tick it if the customer must choose before adding to cart.
* **Price:** the pricing method and amount (see Step 4).
* **Choices:** for Dropdown, Radio, Multi-select and Image choice, type the choices separated by commas (a Checkbox is a single tick box and needs no choices), for example `Small, Medium, Large`.

Click **Update** to save the product. View the product: the field is there, under the price.

**Step 4: Price it.** Pick one of these in the Price dropdown for each field:

* **Flat fee:** adds the amount once when the field is used. Example: Gift wrap, Flat fee, 1.50.
* **Percent of product:** adds that percent of the product (or chosen variation) price. Example: Premium finish, 10.
* **Per quantity:** the customer types a number, and the amount is multiplied by it. Example: Extra name tags, Per quantity, 2.00 (5 tags = $10).
* **Per character (text):** for text fields; each character typed costs the amount. Example: Engraving text, 0.20 ("For Ada" = 7 characters = $1.40).
* **Per choice (lookup table):** each choice has its own price. Write the price after the choice with an equals sign: `11 oz, 15 oz=3`, or `Matte, Gloss=2.5, Metallic=4`. A choice with no `=` costs nothing extra. Use a minus sign for a discount: `Student=-5`.

**Step 5 (optional): Stock per choice.** Add `@` and a number after a choice to give it a stock count: `Steel@40, Black=2@12, Rose gold=5@0`. Here Black costs $2 extra with 12 left, and Rose gold shows as sold out. Leave the `@` off and the choice is unlimited. Stock is reduced when the order is placed and returned if the order is cancelled or fails, or if you tick "Restock refunded items" on a refund. If you edit the stock while orders come in, StoreCanvas Lite applies your change to the current count instead of overwriting sales.

**Step 6 (optional): Show a field only when needed.** Every field shows its **Field ID** at the top right of its row (for example `engrave`). On the field that should appear later, type the other field's ID in **Show if field** and the value to match in **equals**:

* Show "Engraving text" when the checkbox "Add engraving" is ticked: Show if field `engrave`, equals `1` (a ticked checkbox counts as 1).
* Show "Gloss care tips" only when Finish is Gloss: Show if field `finish`, equals `Gloss`.

A hidden field is never required and never charged. Fields can also be limited to user roles (Roles box, for example `wholesale_customer`) or to variations (Variation IDs box, the IDs shown in the Variations tab).

**Step 7 (optional): Reuse fields with an option group.** Go to Products → **Option groups** → Add option group. Give it a title, paste the fields into **Fields JSON** (the easiest way is to build them on one product first; a group uses the same JSON), and choose where it applies: type product IDs in **Product IDs** and/or tick categories under **Product categories**. Every product in those categories now gets the group's fields under its own. On a product's StoreCanvas Lite tab, group fields are listed separately with a link to their group; edit them in the group, not on the product. A product field with the same ID as a group field replaces it on that product. The ticked list under "Global option groups" adds a group to one product even when it is not assigned.

**Step 8: Test an order.** Add the product to the cart with some choices. The cart and checkout show each choice and the total. After checkout, the order screen in WooCommerce → Orders lists every choice under the line item.

= Good to know =

* Prices you see on the product page are recalculated on the server for the cart and checkout, so a customer cannot change a price in the browser.
* If you change a product's price later, carts that already contain it are repriced from the new price.
* Field changes apply to new add-to-carts. Orders already placed keep the choices and prices they were sold with.
* The file upload field saves the uploaded file's name with the order, not the file itself.

A paid **StoreCanvas** plugin (a live design canvas where customers place artwork and text on product mockups, print-ready PNG/SVG/PDF exports and a production desk) is sold separately at https://tillpress.com/storecanvas/. It is a different plugin. StoreCanvas Lite is complete on its own and is never locked or time-limited.

== External services ==

StoreCanvas Lite has no license check, no telemetry, no account, and makes no outbound requests during normal use. The one exception is a support ticket you choose to send.

**Support tickets (only when you send one):** the Help & demo page has a support form. When an administrator fills it in, ticks the consent box, and presses **Send ticket**, the plugin sends the name, reply-to email, topic, subject, message, this site's address, and (if ticked) a system report of WordPress/WooCommerce/PHP versions, theme, and active plugin names to the Tillpress support desk at `https://tillpress.com/wp-json/tp/v1/support`. Nothing is sent automatically, in the background, or on a schedule, and no passwords, keys, or customer/order data are included. Service provider: Tillpress (Alex Software Holdings LLC). Privacy policy: https://tillpress.com/privacy/ — Terms: https://tillpress.com/terms/ . "Email instead" opens the same ticket in your own email app and sends nothing from the site.

== Installation ==

1. In WordPress, go to Plugins → Add New, search for "StoreCanvas Lite", then click Install and Activate. (Or upload the `storecanvas-lite` folder to `/wp-content/plugins/` and activate it on the Plugins screen.)
2. Make sure WooCommerce 7.0 or newer is active.
3. Optional: open Products → StoreCanvas Lite help and click **Create demo data** to see a finished example.
4. Edit a product, open the **StoreCanvas Lite** tab in the Product data box, click **Add field**, fill in the label, type, price and choices, then click **Update**.
5. Optional: create reusable fields under Products → **Option groups** and assign them to products or categories.

The **Setup guide** in the Description tab walks through every setting with examples.

== Frequently Asked Questions ==

= Is there a license key, trial or "pro" lock? =
No. Every feature on this page works without a key, a trial or an account, and nothing is locked later.

= Where do I add options to a product? =
Edit the product, scroll to the Product data box and open the **StoreCanvas Lite** tab (after Advanced). Click **Add field** for each option, then **Update**.

= How do I give each choice its own price? =
Set the field's Price to **Per choice (lookup table)** and write the price after each choice with an equals sign: `Small, Medium=2, Large=4`. Choices without `=` add nothing.

= How do I charge for engraving by the letter? =
Add a **Short text** field, set Price to **Per character (text)** and enter the price per character, for example 0.20. Set **Max chars** to limit the length.

= How do I make a field appear only after another choice? =
Copy the other field's **Field ID** (shown at the top right of its row) into **Show if field**, and type the value it must have into **equals**. For a checkbox, use `1` (ticked). Hidden fields are never required or charged.

= How do I set stock for a choice? =
Add `@` and the quantity after the choice: `Black@12`, or with a price `Black=2@12`. A choice at `@0` shows as sold out. Without `@` a choice is unlimited.

= What happens to option stock on cancellations and refunds? =
Stock is reduced when an order is placed and returned if the order is cancelled or fails. On a refund, stock returns only when you tick "Restock refunded items", and only for the refunded quantity. A restock that fails is not marked done, so you can retry it.

= Can I reuse the same fields on many products? =
Yes. Create an option group under Products → Option groups and assign it to product IDs and/or categories. The fields appear on those products automatically. Edit them in the group; a product's tab lists them with a link to the group.

= Does it work with variable products? =
Yes. Percent pricing uses the chosen variation's price, and a field can be limited to certain variations with the Variation IDs box.

= Does it work with the block cart and checkout, and HPOS? =
Yes. Choices are validated and shown in the block cart and checkout, and orders work with WooCommerce's custom order tables (HPOS).

= Can customers change the price in their browser? =
No. The live price is only a preview. The cart and checkout recalculate every option from your settings on the server.

= Is there a demo? =
Yes. On **Products → StoreCanvas Lite help**, click **Create demo data**. It creates one hidden product (not in your shop or search) with lookup pricing, per-character engraving, a gift note that appears only when Gift wrap is ticked, option stock with a sold-out choice, and a reusable option group. Everything is labelled "Demo", never emailed, and removed with one click (or when you uninstall the plugin).

= How do I get support? =
Open **Products → StoreCanvas Lite help**. The support form there sends your ticket straight to the Tillpress support desk at tillpress.com after you tick the consent box and press Send ticket; you get a ticket number on screen and the reply comes by email. It includes your name, email, message, this site's address, and (optional) a system report of versions, theme, and active plugins; never passwords, keys, or customer data. Prefer email? "Email instead" opens the same ticket in your own email app. You can also use the WordPress.org support forum for this plugin or https://tillpress.com/support/. Replies Monday to Friday, 09:00 to 17:00 Pacific Time; first reply within one business day.

= Does the plugin advertise anything? =
Only on its own Help & demo page: one box describing the paid StoreCanvas plugin with an optional 20% discount code. It can be hidden for good with "Hide this offer". There are no dashboard-wide notices, no tracking, and no referral IDs.

= What if I also install StoreCanvas (the paid plugin)? =
Lite steps aside automatically so you do not get duplicate option fields or double charges. You can then deactivate Lite.

= What does the paid StoreCanvas add? =
The live design canvas (customers place artwork and text on product mockups), print-ready exports, and a production workflow, at https://tillpress.com/storecanvas/.

== Screenshots ==

1. What the customer sees: radio, dropdown, text, checkbox and a gift note that appeared when Gift wrap was ticked, with the live price "Base + options = total". The last two fields come from an option group.
2. Products → StoreCanvas Lite help: one-click demo data, the support ticket form and an optional offer you can hide.
3. Setting up a product: Product data → StoreCanvas Lite tab. Each row is one field with its Field ID, label, type, price and choices. "Steel@40, Black=2@12, Rose gold=5@0" sets a price and a stock count per choice; the last field uses Show if field "engrave" equals 1.
4. The result on the product page: Black adds $2, Rose gold is sold out, and the engraving text box appeared because "Add engraving" was ticked. Engraving is priced per character.
5. Products → Option groups: reusable sets of fields.
6. Editing an option group: the fields, the products it applies to and the categories it applies to (here, every product in Mugs).
7. A product that gets fields from a group: the group's fields are listed with a link to the group, above the product's own fields.
8. The order screen: every choice the customer made is saved under the line item.

== Changelog ==

= 1.0.12 =
* Fix: File upload fields now work. A required file field no longer blocks add to cart, and the uploaded file's name is saved with the order.

= 1.0.11 =
* Fix: saving a product no longer copies option-group fields into the product. The builder now edits only the product's own fields and lists group fields separately with a link to their group, so later edits to the group reach every product.
* Fix: each field shows its Field ID (needed for Show if field), and multi-rule conditions set outside the builder are kept when the product is saved.
* Improved: the choices box uses the full row, and the help text explains =price, @stock and Show if field.

= 1.0.10 =
* Includes 1.0.9 (option stock race, refund restock retry, stale product save).
* Plugin Check: Store API stock message escaped, refund restock flag unslashed and sanitized, stock compare-and-swap uses a prepared %i table placeholder.

= 1.0.9 =
* Fix: option stock sold by concurrent checkouts can no longer be overwritten by a later write.
* Fix: a refund whose restock failed is no longer marked done; retrying it restocks once.
* Fix: saving a product that was open while orders came in keeps the sold stock; a stock edit is applied as a change to the current amount.

= 1.0.8 =
* Fix: customized cart lines always use the current catalog price; a saved cart can no longer keep the old price after a catalog price change.

= 1.0.7 =
* Fix: option surcharges are applied once from the product's current price (including sale price). Recalculating the cart no longer stacks the same extra.
* Fix: option stock tracks each line and refund. Amount-only refunds do not restock; restock restores only the refunded quantity. Failed then paid orders re-reserve remaining stock.

= 1.0.6 =
* Fix: carts saved before this update are re-checked and re-priced from the current product options; invalid items are removed with a notice.

= 1.0.5 =
* Security: option quantities must be whole positive numbers; malformed values can no longer lower a price.
* Fix: option stock is counted across the whole cart, on quantity changes and at checkout; stock is reduced and restored once.

= 1.0.4 =
* Change: front-end option styles and the field-builder type lists now load through wp_enqueue_style / wp_add_inline_script instead of inline <style> and <script> tags (wordpress.org review).

= 1.0.3 =
* New: Send support tickets from inside the plugin (Help & demo page). You get a ticket number on screen; nothing is sent until you tick the consent box and press Send ticket. "Email instead" still opens your email app.

= 1.0.2 =
* New: Help & demo page (Products → StoreCanvas Lite help, also linked from the Plugins screen).
* New: one-click demo product and option group showing lookup, per-character, and flat pricing, a show-if field, and option stock. Hidden from the shop; removed with one click or on uninstall.
* New: support ticket form that opens a pre-filled email in your own email app, with an optional system report (no passwords, keys, or customer data). Nothing is sent from your site.
* New: optional, dismissible 20% offer for the paid StoreCanvas plugin, shown only on the Help & demo page.
* Fix: sold-out choices on radio and multi-select fields are now disabled on the product page (they were only blocked at add to cart). Radio choices show "(sold out)".

= 1.0.1 =
* Fix: show-if (conditional) fields now work on block themes such as Twenty Twenty-Five.
* Fix: order confirmation and emails show the customer's choices instead of raw sclt_price_extra.
* Fix: a plain Update on a product no longer deletes per-choice prices. Write prices as "Large=10".

= 1.0.0 =
* First public release: 15 field types, flat/percent/quantity/per-character/lookup pricing, multi-rule conditional logic, per-choice option stock, reusable option groups, live price preview.

== Upgrade Notice ==

= 1.0.11 =
Product saves no longer copy option-group fields into the product. Products saved with earlier versions keep the copied fields; remove them from the product's StoreCanvas Lite tab if you want the group to control them again.

= 1.0.4 =
Internal asset-loading change for wordpress.org review. No settings change.

= 1.0.3 =
Support tickets can now be sent from inside the plugin. No settings change.

= 1.0.2 =
Adds a Help & demo page: demo product, support form, and system report. No settings change.

= 1.0.0 =
First release.
