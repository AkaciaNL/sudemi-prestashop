# Changelog

## 1.2.3

- New **Support** section on the module page (only while the shop is connected): this shop's
  tickets with sudemi support (number, subject, status, last update), a form to open a ticket
  (category, subject, message, optional sudemi order -- the full sudemi order id or the
  PrestaShop order ID of a sudemi order), each ticket's conversation, and a reply form. A reply
  reopens a closed ticket. Calls go to `/integration/v1/support/tickets`, signed with the shop's
  key like every other call; the employee's back-office language is passed so sudemi answers in
  it (en, nl, tr, de, fr, es, it, pl, ru, zh, ar, he). The module sends no e-mail itself: sudemi
  mails the shop's contact address when support answers. All 12 languages.
- `upgrade/upgrade-1.2.3.php`: nothing to migrate.

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
