# Changelog

## 1.2.2

- New per-product setting on the product page (Options tab): **Right of withdrawal**. For
  products that legally fall under an exception of the EU right of withdrawal (Directive
  2011/83/EU, art. 16): sealed for hygiene reasons, made to the buyer's specifications or
  personalised, perishable, sealed audio/video/software, newspapers and magazines, inseparably
  mixed after delivery, or alcohol with a market-dependent price delivered after 30+ days. sudemi
  shows the exception to the buyer before payment. Default: none (the normal right of withdrawal
  applies). Combinations follow their product. A change re-sends the catalogue on the next
  heartbeat. Requires the PrestaShop 8.1+/9 product page.
- Stored in a new table `sudemi_product`; `upgrade/upgrade-1.2.2.php` creates it and registers
  the product-page hooks on existing installs.

## 1.2.1

- Refunds and cancellations made in the back office reach sudemi (which holds the buyer's payment).
