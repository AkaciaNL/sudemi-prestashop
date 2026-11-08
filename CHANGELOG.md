# Changelog

## 1.2.8

- **A full catalogue sync never runs inside a back-office page load any more.** Before, a heartbeat
  sent from a back-office page load (the fallback for shops without a cron entry) ran the whole
  sync inline when sudemi asked for one, which on a large catalogue held the employee's screen for
  minutes. Now the heartbeat only asks for the sync. The module's cron URL runs it to the end; a
  back-office page load sends at most a small step (25 products, about two seconds) after the page
  was produced (after it was sent, on PHP-FPM), and later page loads continue where it stopped.
  A database lock keeps two requests from sending the sync at once; a sync stopped halfway (a time
  limit, sudemi unreachable) resumes where it stopped; one older than 5.5 hours starts again.
- Editing a product with combinations (the product, or one combination on its own form) sends
  every combination's whole listing in one push, with the shop's catalogue revision computed after
  the save, so such an edit no longer costs a full sync. New hooks for combinations edited, added
  and deleted (registered by upgrade-1.2.8.php on existing installs).
- The revision is sent only when no price change is queued, the category tree sudemi holds is
  current, every request of the push was accepted whole, and no listing removed in the shop (a
  product deleted or switched off, a combination deleted) is still waiting for a full sync to take
  it off sudemi. Before, editing another product after such a removal could make sudemi agree with
  the shop while the removed listing stayed listed.
- Product changes are pushed once per product at the end of the request, instead of once per hook.

## 1.2.7

- The full sync's commit carries the catalogue revision the shop had when the sync started.
  sudemi adopts it, so a product changed or switched off while the sync ran is caught by the
  next heartbeat and synced. The heartbeat right after the commit is still sent.
- Saving a product without combinations sends its whole listing (title, description, price,
  stock, categories, images, shipping) instead of price and stock only, with the shop's catalogue
  revision computed after the save when nothing else is waiting to be sent. sudemi adopts it when
  every item applied, so a product edit no longer costs a full catalogue sync. A product with
  combinations, an inactive product and a push while queued price changes wait still go without
  the revision (a full sync follows, as before). When the category tree changed since it was
  last sent, it is sent first; if that fails, the push goes without the revision.
- A newer push for a product drops its older queued price, which could otherwise be sent after it
  and put the old price back.
- A product saved outside the back office or a shop request (a script, an import), where
  PrestaShop cannot price a full product, still sends its price and stock.

## 1.2.6

- After a full catalogue sync the module tells sudemi at once which catalogue state the sync
  described (a heartbeat with the revision taken when the sync started). Before, sudemi adopted
  whatever the next heartbeat reported, so a product switched off in the minutes after a sync
  could stay listed on sudemi.
- **Order safety: only a definite answer from sudemi decides a local order.** Before, when the
  acceptance of an order came back as anything but "ok" (already answered, a server error, a
  429, a 409 `site_mismatch`, sudemi unreachable halfway), the module cancelled the local order
  and put the stock back, although the buyer might already have been charged: the order was PAID
  on sudemi and never shipped. Now `already_resolved` is read by its `order_status` (PAID,
  SHIPPED, DELIVERED: the order is kept and moves to Payment accepted with the address; ACCEPTED:
  still waiting for the other shops; DECLINED: cancelled and restocked; CANCELLED/REFUNDED:
  cancelled, do not ship), a 404 `no_such_order` releases it, and everything else (5xx, 429,
  401, 403, any 409, unreachable) leaves the order and its stock exactly as they are and asks
  again on the next heartbeat.
- Every heartbeat re-checks the shop's sudemi orders still waiting for payment (lost answers,
  orders released while sudemi could not be reached), up to 25 per beat; one still without a
  definite answer after 3 days is closed with a reject, which also returns the final status.
- **A locally cancelled order is never accepted again.** If the merchant cancels a sudemi order
  that sudemi still asks about, the module now sends a reject instead of accepting it on the next
  heartbeat.
- **Cancelling an order that is not paid yet** (accepted while other shops in the same payment
  still had to answer) now sends a reject, which releases the buyer's hold; before it sent a
  cancel, which sudemi refuses before payment, so the money was taken later anyway. If the order
  was paid meanwhile, it is cancelled with a full refund.
- **Shipped and delivered reports that fail are retried** on the next heartbeats (for up to 14
  days) instead of being logged as sent; a refusal is logged as an error.
- **Full sync in batches of 500 listings.** Products with combinations are one listing per
  combination, so a batch of 100 products could exceed sudemi's 500-per-request limit; sudemi
  refused it and the full sync never completed. Batches are now cut at 500 listings (or the
  smaller maximum sudemi names).
- A sync commit that sudemi holds (it would remove a large share of the listed products) is no
  longer logged as synced; the log says nothing was changed and what to do.
- The price retry queue no longer drops a newer price queued for the same product while an older
  one was being sent, and a batch sudemi refuses for good (400, 403, 413, 422) is dropped instead
  of being re-sent forever; a full sync then carries the current prices.
- Text that is not valid UTF-8 (an old product description) no longer makes a whole call fail;
  invalid bytes are replaced.

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
