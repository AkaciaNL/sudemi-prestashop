# sudemi Connector: PrestaShop Addons product sheet (English)

Text for the PrestaShop Addons listing. Not part of the module zip (export-ignore in
.gitattributes). Screenshots: `screenshot-1.png` to `screenshot-5.png` in this folder; the larger
logo for the listing is `logo-512x512.png` (the module's own `logo.png` is 32x32).

## Module name

sudemi Connector

## Category

Marketplaces (module tab: `market_place`)

## Short description

Puts your products in front of people who shop by asking an AI assistant such as ChatGPT or
Claude. Your catalogue, prices and stock stay in step with sudemi, and orders, statistics and
payouts appear in your back office. Free, no monthly fee.

## Long description

More and more people shop by asking an AI assistant: "find me a wool scarf under 40 euros,
delivered to Germany by Friday". sudemi is a shopping service built for that. The assistant
searches sudemi, sudemi searches the catalogues of all connected shops at once, and the buyer sees
real offers with each shop's own price, stock and delivery cost.

sudemi Connector links your PrestaShop shop to sudemi. It keeps your catalogue there up to date,
checks in every five minutes so buyers only see prices that are current, and brings sudemi's
orders, statistics and settings into your back office, under **sudemi** in the sidebar.

You stay the seller. You ship the order, the customer relationship is yours, and your company
details are confirmed once on sudemi and cannot be changed by the module.

### How buyers pay: two ways

Which way applies is decided by your Stripe account; you never set it by hand. Every sudemi page
in the back office shows the current one at the top.

**Payment through sudemi (Stripe connected).** The buyer pays in a secure payment page without
leaving the conversation with their assistant. Payments are processed by Stripe, a licensed
payment provider. sudemi never sees or stores your card details. Stripe holds the payment and
passes the shop's share on to the shop's own Stripe account; sudemi keeps only its commission.

- The order arrives in PrestaShop with the buyer's name and delivery address, so you can ship it.
- Cost per order, both taken from your payout: Stripe's card processing fee (about 1.5% + 0.25 EUR
  for a card from the European Economic Area, more for other cards) and sudemi's 1% commission on
  your own price of each item. Delivery carries no commission.
- Example: a 50.00 EUR order paid with a European card. Stripe's fee is 1.00 EUR and sudemi's
  commission is 0.50 EUR, so you receive 48.50 EUR.
- On a refund the commission on the refunded amount comes back to you. Declined orders cost
  nothing. Each month you receive a commission invoice.

**Payment on your own website (Stripe not connected).** Your products are listed anyway, for free.
The assistant sends the buyer to your own product page and the buyer pays in your own checkout.
sudemi is not involved in the payment and passes nothing about the buyer to your shop. The link
carries `utm_source=sudemi`, so these visitors show up in your own analytics.

How a shop is shown in search never depends on which way its buyers pay.

## Features

- **Your catalogue on sudemi:** categories, products with descriptions and features, photos,
  prices with VAT, stock and delivery prices read from your carriers. Every combination is its own
  listing with its own price, stock and photos.
- **Always current:** a price or stock change is sent the moment you save it. A check-in every five
  minutes keeps your products visible only while your shop is online.
- **Dashboard:** sales, orders, average order, what is left after refunds and commission, how
  often AI assistants showed your products, product views and cart additions, for 7, 30 or 90 days,
  with daily charts, top products and buyer countries.
- **Products on sudemi:** every product as sudemi holds it, whether buyers see it and, if not, why
  (out of stock, no shipping price, ...), with a switch per product to hide it on sudemi only.
- **Orders:** accept or decline, ship with carrier and tracking, mark delivered, refund, cancel,
  answer return requests, bulk actions and a CSV export. Each sudemi order becomes a normal
  PrestaShop order.
- **Shipping costs, Payouts to you, Shop profile, Help and support** pages in the back office.
- **Payment status bar** on every sudemi page, with a Connect Stripe button.
- **Right of withdrawal:** mark products that fall under an EU exception (sealed hygiene goods,
  personalised items, perishables, ...); buyers see it before they pay.
- **Secure by design:** an Ed25519 key pair is created on your own server; the private key never
  leaves it. Every call to sudemi is signed and cannot be replayed. The module never asks for your
  sudemi password.
- **12 languages:** English, Arabic, Chinese, Dutch, French, German, Hebrew, Italian, Polish,
  Russian, Spanish and Turkish (Arabic and Hebrew right to left).

## What is sent to sudemi

Only after you press **Connect to sudemi**, and only to https://api.sudemi.com over HTTPS: your
catalogue (categories, products, prices, stock, photo links, delivery prices), the shop details
you save on the Shop profile page, a check-in every five minutes, and what you do with orders
placed through sudemi. Nothing about your own customers or your other orders is ever sent. The
module downloads nothing after installation. Terms: https://sudemi.com/terms, privacy statement:
https://sudemi.com/privacy.

## Requirements

- PrestaShop 1.7.6 up to 9
- PHP 7.2 or later with the sodium extension (part of PHP since 7.2; the module checks it)
- HTTPS on the shop's own domain
- A cron job every five minutes (the Settings page shows the exact line)
- A free sudemi seller account: https://sudemi.com

## Installation

1. Upload the zip in **Modules > Module Manager > Upload a module**.
2. Open **sudemi > Settings** in the sidebar and press **Connect to sudemi**.
3. Sign in to your sudemi seller account (creating one is free) and confirm the shop is yours.
4. Add the cron line shown on the Settings page to your hosting account.

## Support

Use the **Help and support** page inside the module, or the seller guides at
https://sudemi.com/docs/en/sellers. Security issues: security@sudemi.com.

## Screenshots (captions)

1. Dashboard: sales, orders, how often AI assistants showed your products, and daily charts.
2. Products on sudemi: every product as sudemi holds it, whether buyers can find it, and a switch per product.
3. Orders: the orders placed through sudemi, with filters, bulk actions and export.
4. Shipping costs: the delivery prices sudemi buyers pay, per country.
5. Settings: the connection, the check-in status and the module's options.
