# Plugin protocol

The protocol between this plugin and sudemi (pairing, Ed25519 request signing, catalogue, heartbeat,
orders, shipping, payouts, statistics) is published on the sudemi website:

**https://sudemi.com/docs/en/plugin-protocol**

The page is available in 12 languages (replace `en` with `nl`, `tr`, `de`, `fr`, `es`, `it`, `pl`,
`ru`, `zh`, `ar` or `he`); the technical reference on every page is in English. It is the single,
current version: this file used to hold a copy, which went out of date.

Section numbers are the same as before, so references in this repository's code such as
"PROTOCOL.md 4d" point to section 4d of that page.

Since 1.4.6 the plugin also sends the shop's own shipping rules: `POST /integration/v1/shipping/rules`
(section "The shop's own shipping rules", core 2026-11-21) with `{"currency", "rules": [{country,
baseMinor?, extraItemMinor?, freeOverMinor?, bandBasis?: weight|price, bands?: [{upTo, priceMinor}],
aboveLast?: none|highest}], "issues": [...]}`, which replaces every rule sent before, and
`perUnitMinor` in each product's `shipping` entries for PrestaShop's per-product additional shipping
cost. What cannot be copied exactly is reported shop-wide as `shipping_rule_approximated` or
`shipping_rate_calculated` with `details.countries` (section "Reporting problems: issues").

Building a plugin for another shop system? This plugin and its siblings are reference
implementations:

- https://github.com/AkaciaNL/sudemi-woocommerce
- https://github.com/AkaciaNL/sudemi-prestashop
- https://github.com/AkaciaNL/sudemi-opencart

Prefer plain API calls over a plugin? The seller API: https://sudemi.com/developers/shops
