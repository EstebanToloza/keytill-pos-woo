=== Keytill POS — Point of Sale for WooCommerce ===
Contributors: estebantoloza
Tags: point of sale, pos, woocommerce, cash register, barcode
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell at the counter from your WooCommerce store. Keyboard-driven, barcode-ready, with a cash register that actually balances at closing.

== Description ==

Keytill turns your WooCommerce store into a counter point of sale. It runs in the browser on any computer, tablet or phone — no extra hardware required, no separate inventory to keep in sync. The same products, the same stock, the same customers.

It is built to be **fast with a keyboard**, because that is how a counter actually works. The command field is always focused, so you scan and the item lands in the ticket. Every action has a function key. You never need the mouse to make a sale.

= What you can do with the free version =

This is a complete point of sale, not a trial:

* **Sell by scanning or searching.** The SKU is the barcode. Type `3*` before a code to add three units. Products with sizes or colors have one code per variation, and each one draws down its own stock.
* **Use your phone's camera as a scanner**, if you do not have a barcode reader.
* **Take payment with any method you configure.** Cash is always there, with change calculation; add card, bank transfer, a wallet app — whatever you use. The POS records which method was used so the count adds up.
* **Discounts** on the whole sale or on a single item, by percentage or by amount.
* **Assign a customer** to the sale, or create one on the spot. POS customers are your store customers — and the POS never sends email.
* **Print the receipt**, and a **gift receipt** without prices that carries a barcode: scan it later to load the sale for an exchange.
* **Refund in cash**, putting the stock back. Works on orders made at the counter *and* online.
* **Open and close the register.** Enter the opening float, and at closing you get the expected cash, the **breakdown by payment method**, and the difference against what you physically counted.
* **Record cash withdrawals and deposits** with a reason, so the count still balances.
* **Track several salespeople.** Pick who made each sale at payment time — they do not need WordPress accounts.
* **Manage from the counter**: find and reprint any order, filter by date, method or status, and fix prices and stock right in the product list (including each variation).
* **Redeem store credit and gift cards** — always, even without PRO.
* Works in **Spanish and English**, and follows your WooCommerce currency and price format, so it fits any country.

= Keytill PRO =

PRO adds what a business needs once it grows past one counter:

* **Several registers at once** — one cash drawer per cashier, each counted separately.
* **Several locations** — stock per store, and moving stock between them.
* **Digital register**: the bank account that receives transfers and card payments, with its own balance, movements and periods.
* **Bank reconciliation** — tick off each transfer against your online banking, to catch payment screenshots that never arrived.
* **Movement log** — every movement across periods, filterable, with CSV export.
* **Issue store credit and gift cards** — refund to credit, and sell gift cards as a product.
* **Barcode labels** — print sheets of 3.5 × 3.5 cm labels.
* **Sales by salesperson** report over any date range.
* **Full product editor from the POS** — create variable products, generate variations, upload images and build your catalog without leaving the counter.

Codes already issued keep working if a PRO subscription ends: your customers will never be turned away with a gift card the system refuses.

= Good to know =

* The receipt the POS prints is an **internal document, not a tax invoice**. If your country requires one, issue it from your invoicing system.
* The POS **never sends email** — not when creating a customer, not when closing a sale.
* Nothing is sent anywhere. Keytill talks only to your own site.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Create a page, paste the `[keytill]` shortcode, and assign the **Keytill — Full screen** template.
3. Open that page. The first time, enter the cash you have in the drawer and the register opens.
4. Optional: in **Keytill → Settings**, set your business details for the receipt, your payment methods and your salespeople.

Two roles are created on activation: **Cashier** (sells, refunds, operates the register) and **Manager** (also edits products and closes the register). Administrators get both.

For the full operating guide, see **Keytill → Counter manual** in your dashboard. You can print it to keep a copy at the register.

== Frequently Asked Questions ==

= Do I need a barcode scanner? =

No. Any USB scanner works as a keyboard and needs no setup, but you can also type to search, or use your phone's camera. The camera needs the site served over HTTPS.

= Does it work with my existing products and stock? =

Yes. Keytill reads and writes your WooCommerce catalog directly. There is no second inventory and nothing to synchronise. A sale at the counter draws down the same stock an online order would.

= Can I sell products with sizes and colors? =

Yes, in the free version. Each variation has its own code and its own stock. Creating variable products *from the POS* is a PRO feature, but you can always create them with the WooCommerce product editor, which is free.

= Does it work offline? =

No. Keytill needs a connection to your site to register a sale, because stock and the register count are resolved on the server. That is a deliberate choice: it is what prevents two terminals from overselling the same item.

= Is the receipt a valid invoice? =

No. It is an internal receipt and says so. Tax invoicing varies too much by country to be handled here; issue it from your accounting or invoicing system.

= Does it send my data anywhere? =

No. There are no external calls, no analytics and no CDN. The fonts ship with the plugin for that reason.

= Which browsers work? =

Any current version of Chrome, Edge, Firefox or Safari, on desktop, tablet or phone. The interface adapts to touch.

== Screenshots ==

1. The sale screen: the command field is always focused, the ticket on the left, the total on the right.
2. Taking payment: pick the method by number, type what you received and the change is worked out.
3. Closing the register: expected cash, breakdown by payment method and the difference against what you counted.
4. Refunds and exchanges: find the sale by number or by scanning the gift receipt.
5. The console: orders, products and customers without leaving the POS.
6. The counter manual, included in the dashboard.

== Changelog ==

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.

== Credits ==

Keytill bundles the following open source libraries and fonts, so that nothing is loaded from an external service:

* [Alpine.js](https://alpinejs.dev/) 3.14.1 — MIT.
* [ZXing-js](https://github.com/zxing-js/library) (`@zxing/library`) 0.21.3 — MIT. Used for the camera scanner.
* [Inter](https://github.com/rsms/inter) — SIL Open Font License 1.1. Licence text in `assets/fonts/LICENSE-Inter.txt`.
* [Roboto Mono](https://github.com/googlefonts/robotomono) — SIL Open Font License 1.1. Licence text in `assets/fonts/LICENSE-RobotoMono.txt`.
