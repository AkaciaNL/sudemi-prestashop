# Changelog

## 1.2.5

- Buyers' **return requests**. After delivery a buyer may ask to return an order; the shop has
  7 days to accept, or to reject with a reason the buyer is shown (without an answer, sudemi
  decides). The open requests arrive with every heartbeat and are shown on the PrestaShop order
  page (a block on that order) and in a new **Return requests** list on the module page: the
  local order, the buyer's reason, the answer-by date, and Accept / Reject (a reason is required
  to reject). Answers are signed calls to sudemi, checked for the employee's permission and the
  CSRF token like every other action; the outcome is shown, and the order's private note gets a
  line. After accepting, refund the order the usual way once the item is back (status Refunded,
  or a credit slip): that refund closes the request. All 12 languages.
- A **buyer's cancellation** of a paid, not yet shipped order now reaches the PrestaShop order:
  when sudemi reports it cancelled (and refunded in full), the local order moves to Canceled
  with its stock restored and a note in the order's private note, and nothing is sent back to
  sudemi for it. Before, only an order still waiting for payment was touched, so a paid order
  stayed "Payment accepted" and could be shipped. An order already cancelled, refunded or
  shipped is left alone.
- Fix: moving an order to **Delivered** now tells sudemi it was delivered. PrestaShop's Shipped
  and Delivered states carry the same flags, so a delivery used to be reported as another
  shipment (and Processing in progress, which has a delivery slip, could be taken for a
  delivery). Delivered is now recognised by the shop's Delivered state itself; this is what
  starts the buyer's 14-day return window.
- A signed call with no body now sends `{}` instead of `[]` (an endpoint taking an optional
  object, like accepting a return, refused the array).
- `upgrade/upgrade-1.2.5.php` registers the order-page hooks (`displayAdminOrderMain`, and
  `displayAdminOrder` for PrestaShop before 1.7.7).

## 1.2.4

- Every signed call now says which site it comes from: the `Sudemi-Site` header, the shop's own
  base URL (the address pairing announced). It is not part of the signature. A staging copy or a
  restored backup of the shop holds the same install id and key, and sudemi now refuses its calls
  (409 `site_mismatch`), so a copy can no longer push its catalogue or answer orders as the live
  shop.
- When sudemi refuses this site because the shop is connected under another address, the module
  page shows it as not connected and says what to do: if this site is a staging copy or a
  restored backup, disconnect sudemi there; if the shop moved, connect it again. The support
  section says the same instead of a generic error. All 12 languages.
- `upgrade/upgrade-1.2.4.php`: nothing to migrate.

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
