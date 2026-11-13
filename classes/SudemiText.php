<?php
/**
 * The texts of the sudemi Orders and Shipping pages, of the order page block and of the notes the
 * module writes on PrestaShop orders, in English.
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
        'payout_converted' => 'Paid in %s after the conversion fee of the payment provider Stripe.',
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
        'tracking_help' => 'sudemi tells the buyer. You can send the tracking number, and correct it later, until the order is delivered on sudemi.',
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
        'err_tracking_late' => 'This order is already delivered, cancelled or refunded on sudemi, so its tracking number can no longer be changed.',
        'tracking_same' => 'sudemi already has this tracking number. Nothing was changed.',
        'tracking_required' => 'Give a tracking number.',
        'do_update_tracking' => 'Change tracking',
        'update_tracking_help' => 'Corrects the carrier or tracking number on sudemi. sudemi e-mails the buyer the new tracking number.',
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
        // the sudemi menu, Dashboard, Products on sudemi, Payouts, Shop profile (1.4.0)
        'nav_dashboard' => 'Dashboard',
        'nav_products' => 'Products on sudemi',
        'nav_orders' => 'Orders',
        'nav_shipping' => 'Shipping costs',
        'nav_payouts' => 'Payouts to you',
        'nav_profile' => 'Shop profile',
        'nav_support' => 'Help and support',
        'nav_settings' => 'Settings',
        'hdr_listed' => 'Products shown to buyers',
        'hdr_not_listed' => 'Products not shown to buyers',
        'hdr_not_connected' => 'Not connected',
        'hdr_tagline' => 'AI assistants find your products and send buyers to you.',
        // checkout mode banner on every sudemi page (1.4.1, docs/seller-modes.md)
        'mode_shop_title' => 'Stripe is not connected. Your products are listed on sudemi, Connect Stripe',
        'mode_shop_text' => 'Until then buyers pay on your own website: free listing, no commission.',
        'mode_requirements' => 'Stripe needs more information from you.',
        'mode_connect' => 'Connect Stripe',
        'mode_sudemi_title' => 'Connect Stripe is done',
        'mode_sudemi_text' => 'Buyers pay through sudemi, and you get your orders and payouts here.',
        'mode_suspended_title' => 'Your shop is paused on sudemi while we review a payment provider restriction',
        'mode_suspended_text' => 'Your products are not shown for now. We e-mail you what is needed.',
        'mode_support' => 'Contact support',
        'dash_intro' => 'How your products did on sudemi: how often AI assistants showed them, how often buyers looked closer, and what sold.',
        'period_days' => 'Last %d days',
        'stats_error' => 'Could not load the statistics from sudemi just now. Try again shortly.',
        'kpi_sales' => 'Sales',
        'kpi_sales_help' => 'What buyers paid, shipping and VAT included',
        'kpi_orders' => 'Orders',
        'kpi_units' => '%d items sold',
        'kpi_average' => 'Average order',
        'kpi_net' => 'After refunds and commission',
        'net_note' => 'A guide: the payouts page shows what is actually paid to you.',
        'kpi_impressions' => 'Shown in search',
        'kpi_impressions_help' => 'Times your products were in the results an AI assistant got',
        'kpi_views' => 'Product views',
        'kpi_views_help' => 'Times an assistant opened a product for a buyer',
        'kpi_cart' => 'Added to cart',
        'kpi_conversion' => 'Views that became orders',
        'refunded_commission' => 'Refunded %1$s, sudemi commission %2$s',
        'commission_only' => 'sudemi commission %s',
        'chart_seen' => 'Shown and viewed per day',
        'chart_sold' => 'Carts and orders per day',
        'top_title' => 'Top products',
        'col_product' => 'Product',
        'col_shown' => 'Times shown',
        'col_views' => 'Views',
        'col_cart' => 'In cart',
        'col_orders' => 'Orders',
        'col_units' => 'Items',
        'col_sales' => 'Sales',
        'no_data' => 'Nothing yet in this period.',
        'status_title' => 'Orders by status',
        'countries_title' => 'Where buyers are',
        'listings_title' => 'Your products on sudemi',
        'listings_summary' => '%1$d of %2$d products are shown to buyers.',
        'listings_open' => 'See all products',
        'see_not_listed' => 'See which products are not shown, and why',
        'latest_title' => 'Latest orders',
        'all_orders' => 'All orders',
        'no_orders_yet' => 'No orders through sudemi yet.',
        'prod_intro' => 'Your products as sudemi holds them, whether buyers can see them, and why not. Product details come from this shop: change them in the catalogue and they are sent to sudemi.',
        'tab_all' => 'All',
        'tab_listed' => 'Shown to buyers',
        'tab_not_listed' => 'Not shown',
        'search_products' => 'Name, reference or ID',
        'col_price' => 'Price',
        'col_stock' => 'Stock',
        'col_photos' => 'Photos',
        'col_countries' => 'Ships to',
        'col_shown30' => 'Shown, 30 days',
        'col_views30' => 'Views, 30 days',
        'col_orders30' => 'Orders, 30 days',
        'col_on_sudemi' => 'On sudemi',
        'state_listed' => 'Shown',
        'state_not_listed' => 'Not shown',
        'rs_disabled_by_shop' => 'Turned off by you',
        'rs_not_in_shop' => 'No longer for sale in this shop',
        'rs_out_of_stock' => 'Out of stock',
        'rs_no_shipping' => 'No shipping price for any country',
        'rs_suspended' => 'Taken down by sudemi',
        'rs_payouts_not_ready' => 'Your payout account is not active yet',
        'rs_shop_not_reporting' => 'This shop is not reporting to sudemi',
        'rs_other' => 'Not shown',
        'switch_off' => 'Hide on sudemi',
        'switch_on' => 'Show on sudemi',
        'switched_on' => 'Shown on sudemi again: %s',
        'switched_off' => 'Hidden on sudemi: %s',
        'switch_help' => 'Hiding a product only hides it on sudemi. Your shop is not changed, and a catalogue sync does not show it again.',
        'prod_none' => 'No products match.',
        'prod_shown' => 'Products %1$d to %2$d of %3$d',
        'prod_error' => 'Could not load the products from sudemi just now. Try again shortly.',
        'err_not_found_product' => 'That product was not found on sudemi for this shop.',
        'payouts_intro' => 'What sudemi has paid you for this shop, and what is still due.',
        'payouts_per_order' => 'The payout of each order is on the orders page.',
        'profile_intro' => 'What buyers see about this shop on sudemi.',
        'profile_edit' => 'What buyers see',
        'profile_name' => 'Shop name',
        'profile_name_help' => 'The name buyers see. To change it, ask sudemi support: a new name is checked first.',
        'profile_rename' => 'Ask to rename',
        'profile_description' => 'Description',
        'profile_description_help' => 'A few sentences about your shop, at most 2000 characters. AI assistants may show it to buyers.',
        'profile_email' => 'Support e-mail',
        'profile_email_help' => 'Where buyers and sudemi write to about orders.',
        'profile_days' => 'Returns window, days',
        'profile_days_help' => 'Days a buyer has to return a product. At least 14, the EU minimum.',
        'profile_currency' => 'Currency',
        'profile_address' => 'Shop address',
        'profile_fixed' => 'Fixed by the connection',
        'profile_fixed_help' => 'The shop address was checked when the shop was connected, and the currency is the shop default currency. Legal details of your company are changed on the sudemi website.',
        'profile_save' => 'Save on sudemi',
        'profile_saved' => 'Shop profile saved on sudemi.',
        'profile_error' => 'Could not load the shop profile from sudemi just now. Try again shortly.',
        'profile_bad_description' => 'The description can be at most 2000 characters.',
        'profile_bad_email' => 'Give a valid e-mail address.',
        'profile_bad_days' => 'The returns window is a number of days from 14 to 365.',
        'rename_subject' => 'Please change the name of my shop',
        'rename_message' => 'Please change the name buyers see for this shop. The name now is "%s". The new name should be: ',
        // notes on the PrestaShop order (in the shop's language)
        'note_cancel_buyer' => 'Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full.',
        'note_cancel_shop' => 'Cancelled by the shop on sudemi before shipping; the buyer has been refunded in full.',
        'note_cancel_sudemi' => 'Cancelled by sudemi before shipping; the buyer has been refunded in full.',
        'note_cancel' => 'Cancelled on sudemi before shipping; the buyer has been refunded in full.',
        'note_do_not_ship' => 'Do not ship this order.',
        'note_decline_buyer' => 'Cancelled by the buyer on sudemi before the order was confirmed; the buyer was not charged.',
        'note_decline_shop' => 'Declined by the shop on sudemi; the buyer was not charged.',
        'note_decline_sudemi' => 'Declined automatically by sudemi (for example because the order was not answered in time); the buyer was not charged.',
        'note_decline' => 'Declined on sudemi; the buyer was not charged.',
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

    /** Translations read from translations/<iso>.php, per language. */
    private static $files = [];

    /**
     * An English text in another of the module's languages, read from the module's own
     * translation file (the key PrestaShop uses for it); the English text when there is none.
     * Pure apart from reading the file.
     */
    public static function translate($english, $iso)
    {
        $iso = strtolower((string) $iso);
        if ($iso === 'en' || !preg_match('/^[a-z]{2}$/', $iso)) {
            return $english;
        }
        if (!isset(self::$files[$iso])) {
            self::$files[$iso] = [];
            $file = __DIR__ . '/../translations/' . $iso . '.php';
            if (is_file($file)) {
                global $_MODULE;
                $saved = isset($_MODULE) ? $_MODULE : null;
                $_MODULE = [];
                include $file;
                self::$files[$iso] = is_array($_MODULE) ? $_MODULE : [];
                $_MODULE = $saved;
            }
        }
        $key = '<{sudemi}prestashop>sudemi_' . md5(preg_replace("/\\\\*'/", "\\'", $english));

        return isset(self::$files[$iso][$key]) && trim(self::$files[$iso][$key]) !== ''
            ? html_entity_decode((string) self::$files[$iso][$key], ENT_QUOTES, 'UTF-8') : $english;
    }

    /**
     * An English text in the shop's default language, for notes written on a PrestaShop order
     * (written by the heartbeat as well, where there is no employee to ask).
     */
    public static function forShop($english)
    {
        $iso = class_exists('Language') && class_exists('Configuration')
            ? (string) Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT')) : 'en';

        return self::translate($english, $iso);
    }

    /** One text by key, in the employee's language. */
    public static function get($module, $key)
    {
        return isset(self::EN[$key]) ? self::one($module, self::EN[$key]) : $key;
    }
}
