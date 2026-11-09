<?php
/**
 * The texts of the sudemi Orders and Shipping pages and of the order page block, in English.
 *
 * Translated with PrestaShop's module translations (translations/<iso>.php, source "sudemi"),
 * like every other text of this module: Module::l() looks each one up in the employee's
 * language. Kept in one list so a test can check that all 12 languages have every text.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiText
{
    const EN = [
        // statuses (shared with the module page's order history)
        'st_AWAITING_SELLER' => 'Waiting for you - buyer not charged',
        'st_ACCEPTED' => 'Accepted - waiting for the other shops in the buyer\'s payment; buyer not charged yet, do not ship',
        'st_PAID' => 'Confirmed and charged',
        'st_DECLINED' => 'Declined - nothing charged',
        'st_SHIPPED' => 'Shipped',
        'st_DELIVERED' => 'Delivered',
        'st_CANCELLED' => 'Cancelled',
        'st_REFUNDED' => 'Refunded',
        // payout states
        'po_not_charged' => 'Not charged',
        'po_waiting_for_shipment' => 'Due once shipped',
        'po_scheduled' => 'Scheduled',
        'po_processing' => 'On its way',
        'po_held' => 'Held (chargeback)',
        'po_partly_paid_out' => 'Partly paid out',
        'po_paid_out' => 'Paid out',
        'po_nothing_due' => 'Nothing due (refunded)',
        // timeline
        'ev_PLACED' => 'Order placed',
        'ev_ACCEPTED' => 'Accepted',
        'ev_PAID' => 'Payment taken',
        'ev_DECLINED' => 'Declined',
        'ev_SHIPPED' => 'Shipped',
        'ev_DELIVERED' => 'Delivered',
        'ev_REFUND' => 'Refund',
        'ev_CANCELLED' => 'Cancelled',
        'ev_REQUEST' => 'Buyer request',
        'ev_REQUEST_DECIDED' => 'Request answered',
        // orders page
        'orders_title' => 'sudemi orders',
        'orders_intro' => 'Your orders as sudemi holds them. sudemi is the source of truth: actions here are sent to sudemi, and the PrestaShop order follows sudemi\'s answer.',
        'not_connected' => 'Connect this shop to sudemi first (Modules > sudemi Connector).',
        'unreachable' => 'Could not reach sudemi just now. Nothing was changed; try again shortly.',
        'auto_off' => 'Automatic confirmation is off: accept or decline waiting orders here before their answer-by time. Without an answer sudemi declines them and the buyer is not charged.',
        'filter_status' => 'Status',
        'all_statuses' => 'All',
        'from' => 'From',
        'to' => 'To',
        'search' => 'Search',
        'search_help' => 'Order number or product name',
        'sort' => 'Sort by',
        'sort_placed' => 'Date',
        'sort_total' => 'Total',
        'sort_status' => 'Status',
        'dir_desc' => 'Newest or highest first',
        'dir_asc' => 'Oldest or lowest first',
        'apply' => 'Apply',
        'reset' => 'Reset',
        'col_order' => 'Order',
        'col_date' => 'Date',
        'col_items' => 'What sold',
        'col_country' => 'Ship to',
        'col_status' => 'Status',
        'col_total' => 'Buyer paid',
        'col_net' => 'You receive',
        'col_refunded' => 'Refunded',
        'col_tracking' => 'Tracking',
        'col_payout' => 'Payout',
        'col_local' => 'PrestaShop order',
        'none' => 'none',
        'no_orders' => 'No orders match.',
        'shown' => 'Orders %1$d to %2$d of %3$d',
        'previous' => 'Previous',
        'next' => 'Next',
        'open' => 'Open',
        'bulk_title' => 'Selected orders',
        'bulk_action' => 'Action',
        'bulk_choose' => 'Choose an action',
        'do_accept' => 'Accept',
        'do_reject' => 'Decline',
        'do_shipped' => 'Mark shipped',
        'do_delivered' => 'Mark delivered',
        'do_refund' => 'Refund part',
        'do_cancel' => 'Cancel and refund in full',
        'do_tracking' => 'Send tracking to sudemi',
        'reason' => 'Reason (shown to the buyer)',
        'carrier' => 'Carrier',
        'tracking_code' => 'Tracking number',
        'amount' => 'Amount',
        'bulk_send' => 'Apply to selected orders',
        'bulk_none' => 'Select at least one order and an action.',
        'bulk_result' => 'Done: %1$d. Refused: %2$d.',
        'bulk_refused' => 'Order %1$s refused: %2$s',
        'reason_required' => 'Give a reason. The buyer is shown it.',
        'export_title' => 'Export',
        'export_lang' => 'Language',
        'export_decimal' => 'Decimal comma (Excel in most of Europe)',
        'export_send' => 'Download CSV',
        'export_failed' => 'The export could not be made just now. Try again shortly.',
        'payouts_title' => 'Payouts',
        'payouts_account' => 'Payout account',
        'payouts_delay' => 'A shop is paid %d days after the buyer received the order, never while a chargeback is open. The payout account is managed on the sudemi seller portal.',
        'payouts_currency' => 'Currency',
        'payouts_paid' => 'Paid out',
        'payouts_outstanding' => 'Still due',
        'payouts_waiting' => 'Due once shipped',
        'payouts_scheduled' => 'Scheduled',
        'payouts_processing' => 'On its way',
        'payouts_held' => 'Held',
        'payouts_next' => 'Next payout',
        'payouts_none' => 'No payouts yet.',
        'acct_NOT_STARTED' => 'Not set up',
        'acct_INCOMPLETE' => 'Incomplete',
        'acct_UNDER_REVIEW' => 'Under review',
        'acct_ACTIVE' => 'Active',
        'acct_ACTION_REQUIRED' => 'Action required',
        // order detail
        'back' => 'Back to all sudemi orders',
        'detail_title' => 'sudemi order %s',
        'placed' => 'Placed',
        'destination' => 'Ship to country',
        'customer' => 'Customer',
        'respond_by' => 'Answer by',
        'declined_reason' => 'Reason declined',
        'note' => 'Note',
        'shipped_at' => 'Shipped on',
        'delivered_at' => 'Delivered on',
        'withdrawal' => 'Return window ends',
        'local_order' => 'PrestaShop order',
        'no_local' => 'No PrestaShop order',
        'lines' => 'Products',
        'product' => 'Product',
        'your_id' => 'Your product ID',
        'quantity' => 'Quantity',
        'unit_price' => 'Price each',
        'line_total' => 'Total',
        'no_withdrawal' => 'No right of withdrawal: %s',
        'money' => 'Money',
        'goods' => 'Products',
        'shipping' => 'Shipping',
        'total' => 'Buyer paid',
        'commission' => 'sudemi commission',
        'you_receive' => 'You receive',
        'refunded' => 'Refunded',
        'payout' => 'Payout',
        'payout_state' => 'State',
        'payout_expected' => 'Expected',
        'payout_paid' => 'Paid out',
        'payout_due' => 'Due on',
        'payout_converted' => 'Paid in %s after the card company\'s conversion fee.',
        'timeline' => 'History',
        'by_SELLER' => 'by you',
        'by_ADMIN' => 'by sudemi',
        'by_PROVIDER' => 'by the payment provider',
        'by_AUTO' => 'automatically',
        'request_title' => 'The buyer asks to cancel or return this order',
        'request_return' => 'Return',
        'request_cancellation' => 'Cancellation',
        'request_accept' => 'Accept the request',
        'request_reject' => 'Reject the request',
        'actions' => 'Actions',
        'no_actions' => 'Nothing to do on this order now.',
        'refund_help' => 'Up to %s can still be refunded. sudemi pays it back to the buyer from the payment it holds.',
        'cancel_help' => 'Cancels the order and refunds the buyer in full. Only before shipping.',
        'tracking_help' => 'sudemi tells the buyer. A tracking number can be sent until the order is shipped on sudemi; after that it cannot be changed.',
        'done_accept' => 'Accepted. The buyer was charged; ship the order.',
        'done_waiting' => 'Accepted. The buyer paid several shops at once: sudemi takes the money once every shop has answered. Do not ship before the order shows Confirmed and charged.',
        'done_reject' => 'Declined. The buyer was not charged.',
        'done_shipped' => 'sudemi now has the order as shipped and tells the buyer.',
        'done_delivered' => 'sudemi now has the order as delivered. The buyer\'s return window runs from today.',
        'done_refund' => 'Refunded through sudemi.',
        'done_cancel' => 'Cancelled on sudemi; the buyer is refunded in full.',
        'done_request' => 'Your answer was sent to sudemi.',
        'done_tracking' => 'Tracking sent to sudemi; sudemi tells the buyer.',
        'err_already_resolved' => 'sudemi already has a newer state for this order. Nothing was changed; the page shows sudemi\'s state now.',
        'err_tracking_late' => 'sudemi already has this order as shipped, so the tracking number can no longer be changed there.',
        'err_shortfall' => 'Not accepted, nothing was sent: %s. Decline the order, or make the product available and accept again.',
        'err_amount' => 'Give an amount above zero and at most what can still be refunded.',
        'err_refused' => 'sudemi refused this: %s',
        'err_invalid' => 'That request could not be verified. Try again from this page.',
        'err_permission' => 'You do not have permission to change this.',
        'err_not_found' => 'That order was not found for this shop.',
        // shipping page
        'ship_title' => 'sudemi shipping',
        'ship_intro' => 'What sudemi buyers pay for shipping, per country. One charge per parcel, never per item.',
        'ship_mode' => 'Shipping prices',
        'ship_mode_shop' => 'From this shop\'s carriers (sent with every product)',
        'ship_mode_custom' => 'My own prices per country on sudemi (only the countries ticked are offered)',
        'ship_country' => 'Country',
        'ship_offered' => 'Offered',
        'ship_price' => 'Price on sudemi',
        'ship_price_help' => 'Empty: the price from this shop\'s carriers.',
        'ship_shop_price' => 'Price from this shop\'s carriers',
        'ship_effective' => 'What buyers pay now',
        'ship_source_shop' => 'shop price',
        'ship_source_sudemi' => 'sudemi price',
        'ship_not_offered' => 'not offered',
        'ship_save' => 'Save on sudemi',
        'ship_saved' => 'Shipping saved on sudemi.',
        'ship_refused' => 'sudemi did not accept these settings: %s',
        'ship_bad_price' => 'Not a price: %s',
        'ship_prefill' => 'Fill in from this shop\'s carriers',
        'ship_prefilled' => 'Prices filled in from this shop\'s carriers and zones (cheapest carrier for one item of the first product). Check them, then save.',
        'ship_currency' => 'Prices are in %s, the shop\'s currency on sudemi.',
        // module page and order page block
        'auto_title' => 'Automatic confirmation',
        'auto_on' => 'Accept orders automatically when the products are in stock',
        'auto_help' => 'Off: waiting orders are accepted or declined by hand on the sudemi orders page, before their answer-by time.',
        'auto_saved' => 'Saved.',
        'save' => 'Save',
        'links_title' => 'Manage on sudemi',
        'block_title' => 'sudemi',
        'block_status' => 'Status on sudemi',
        'block_unknown' => 'not known yet (updated with the next heartbeat)',
        'block_tracking' => 'Tracking on sudemi',
        'block_open' => 'Open this order on the sudemi orders page',
    ];

    /**
     * The texts in the employee's language, as plain text (Module::l returns HTML-escaped text;
     * templates escape once, so it is decoded here).
     *
     * @return array<string,string>
     */
    public static function all($module)
    {
        $out = [];
        foreach (self::EN as $key => $english) {
            $out[$key] = self::one($module, $english);
        }

        return $out;
    }

    public static function one($module, $english)
    {
        return html_entity_decode((string) $module->l($english, 'sudemi'), ENT_QUOTES, 'UTF-8');
    }

    /** One text by key, in the employee's language. */
    public static function get($module, $key)
    {
        return isset(self::EN[$key]) ? self::one($module, self::EN[$key]) : $key;
    }
}
