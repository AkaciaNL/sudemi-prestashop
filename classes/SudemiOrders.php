<?php
/**
 * Answering sudemi's question: can this shop actually fulfil the order?
 *
 * Nobody but this shop can answer it. sudemi holds no stock - it carries a figure this module
 * pushed, which was true when it was pushed and may not be now, because the same units are on
 * sale in this storefront at the same time. So sudemi does not decide. It authorises the buyer's
 * card, holds the money without taking it, and asks.
 *
 * Until this module answers, nothing has been charged. Yes captures the money and splits it; no
 * releases the hold; saying nothing releases it too once the window closes. Silence is never
 * taken as agreement - a shop that has stopped answering is usually a shop that is gone.
 */
class SudemiOrders
{
    /** Set on the PrestaShop order so one sudemi order can never produce two local ones. */
    const REFERENCE_PREFIX = 'SUDEMI';

    const CONF_AUTO_CONFIRM = 'SUDEMI_AUTO_CONFIRM';

    /**
     * Works through what arrived on the heartbeat.
     *
     * One order at a time, and a failure on one does not stop the rest: each is a buyer whose
     * money is on hold against a deadline.
     *
     * @param array $pending The pending_orders block of a heartbeat response
     */
    public static function handlePending($pending)
    {
        if (empty($pending) || !is_array($pending)) {
            return;
        }
        if (!self::autoConfirmEnabled()) {
            SudemiLogger::info(sprintf(
                '%d order(s) are waiting for confirmation. Automatic confirmation is off, so they '
                . 'must be answered by hand before their window closes.',
                count($pending)
            ));
            return;
        }

        foreach ($pending as $order) {
            if (empty($order['order_id'])) {
                continue;
            }
            try {
                self::answer($order);
            } catch (Exception $e) {
                SudemiLogger::error(sprintf(
                    'Could not answer sudemi order %s: %s',
                    $order['order_id'],
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Decides one order and tells sudemi.
     *
     * The local order is recorded *before* the acceptance is sent. The other way round would
     * leave a buyer charged for something this shop has no record of; this way a refused
     * acceptance leaves a local order with no money behind it, which is recoverable here.
     *
     * @param array $pending One entry of the pending_orders block
     */
    private static function answer(array $pending)
    {
        $sudemiId = (string) $pending['order_id'];

        $existing = self::findLocalOrder($sudemiId);
        if ($existing) {
            // Already built, so a previous run got this far and the answer was lost in transit.
            // Re-sending is safe: sudemi answers already_resolved if it landed the first time.
            self::accept($sudemiId, $existing);
            return;
        }

        $shortfall = self::firstShortfall($pending);
        if ($shortfall !== null) {
            // The ordinary case, and not a failure. The buyer's hold is released within a minute.
            SudemiClient::postSigned(
                '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
                ['reason' => $shortfall]
            );
            SudemiLogger::info(sprintf(
                'Declined sudemi order %s: %s. Nothing was charged.',
                $sudemiId,
                $shortfall
            ));
            return;
        }

        $order = self::createLocalOrder($pending, $sudemiId);
        if (!$order) {
            SudemiClient::postSigned(
                '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
                ['reason' => 'this shop could not record the order']
            );
            return;
        }
        self::accept($sudemiId, $order);
    }

    /**
     * Sends the acceptance, and undoes the local order if it is refused.
     *
     * @param string $sudemiId
     * @param Order  $order
     */
    private static function accept($sudemiId, $order)
    {
        $response = SudemiClient::postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/accept',
            []
        );

        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = isset($response['body']) ? $response['body'] : [];

        if ($status === 200 && isset($body['status']) && $body['status'] === 'ok'
            && isset($body['payment']) && $body['payment'] === 'waiting_for_other_shops') {
            // Accepted, but not paid. The buyer paid several shops in one payment and a card
            // authorisation can be captured only once, so sudemi takes the money when the last of
            // them has answered and says so in settled_orders on a later heartbeat. Until then
            // the order keeps its unpaid state and has no address: shipping it now could mean
            // shipping goods nobody ever pays for.
            self::note($order, 'Accepted on sudemi. The buyer paid several shops in one payment; sudemi '
                . 'takes the money once every shop has answered. Do NOT ship until this order is marked '
                . 'as paid.');
            return;
        }

        if ($status === 200 && isset($body['status']) && $body['status'] === 'ok') {
            // The address arrives only now. sudemi withholds it while the order is still being
            // decided, because answering "do I have stock" needs nobody's address -- so this is
            // the first moment there is a customer to put on the order at all.
            self::fillCustomer($order, $sudemiId);
            self::setState($order, (int) Configuration::get('PS_OS_PAYMENT'));
            SudemiLogger::info(sprintf(
                'Confirmed sudemi order %s as order %s.',
                $sudemiId,
                $order->reference
            ));
            return;
        }

        if ($status === 0) {
            // Unreachable rather than refused. The local order stays and the next heartbeat
            // carries the same pending order, at which point the branch above re-sends. Nothing
            // is lost and nothing is duplicated.
            SudemiLogger::warn(sprintf(
                'Could not reach sudemi to confirm order %s. Will try again on the next heartbeat.',
                $sudemiId
            ));
            return;
        }

        // Refused: the window closed, or the authorisation lapsed before this shop answered. No
        // money was taken, so the local order must not survive - and the stock it reserved has to
        // go back, or this shop slowly loses inventory to orders that never happened.
        $reason = isset($body['message']) ? (string) $body['message'] : sprintf('HTTP %d', $status);
        // PrestaShop puts the stock back itself when an order moves to Canceled.
        self::setState($order, (int) Configuration::get('PS_OS_CANCELED'));
        SudemiLogger::warn(sprintf(
            'sudemi refused the confirmation of order %s: %s. The local order was cancelled and '
            . 'stock restored.',
            $sudemiId,
            $reason
        ));
    }

    /**
     * Records the buyer's delivery address, now that sudemi will release it.
     *
     * Written as a PrestaShop address belonging to the guest customer on the order, so packing
     * slips and carrier modules find it where they expect to. Without this a merchant has a paid
     * order and nowhere to send it.
     *
     * @param Order  $order
     * @param string $sudemiId
     */
    private static function fillCustomer($order, $sudemiId)
    {
        $response = SudemiClient::getSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/fulfilment'
        );
        if ($response === null || (int) $response['status'] !== 200) {
            // Not fatal: the order is paid and real, and the address can be fetched again. Said
            // on the order itself rather than only in a log, because a merchant about to pack
            // this needs to know it has no destination yet.
            self::note($order, 'Could not fetch the delivery address from sudemi yet. The order '
                . 'is paid; try again shortly or contact support before shipping.');

            return;
        }
        $body = isset($response['body']) ? $response['body'] : [];
        if (empty($body['deliverable']) || empty($body['ship_to'])) {
            self::note($order, 'sudemi has no delivery address for this order: '
                . (isset($body['message']) ? (string) $body['message'] : 'reason not given'));

            return;
        }

        $to = $body['ship_to'];
        $name = isset($to['recipient_name']) ? trim((string) $to['recipient_name']) : '';
        // PrestaShop wants the name split, and a recipient line is not reliably "first last" in
        // every locale. Everything before the last space is the first name: wrong for some
        // names, and never loses part of one.
        $space = strrpos($name, ' ');
        $first = $space === false ? $name : Tools::substr($name, 0, $space);
        $last = $space === false ? '-' : Tools::substr($name, $space + 1);

        $address = new Address();
        $address->id_customer = (int) $order->id_customer;
        $address->id_country = (int) Country::getByIso(
            isset($to['country']) ? (string) $to['country'] : 'NL'
        );
        $address->alias = 'sudemi';
        $address->firstname = $first === '' ? '-' : $first;
        $address->lastname = $last === '' ? '-' : $last;
        $address->address1 = isset($to['line1']) ? (string) $to['line1'] : '';
        $address->address2 = isset($to['line2']) ? (string) $to['line2'] : '';
        $address->postcode = isset($to['postal_code']) ? (string) $to['postal_code'] : '';
        $address->city = isset($to['city']) ? (string) $to['city'] : '';
        $address->phone = isset($to['phone']) ? (string) $to['phone'] : '';
        if (!$address->add()) {
            self::note($order, 'The delivery address from sudemi could not be stored.');

            return;
        }

        $order->id_address_delivery = (int) $address->id;
        // No billing address is set: sudemi is the merchant of record for the payment, so this
        // shop issues the buyer no invoice and has no reason to hold more of their data.
        $order->id_address_invoice = (int) $address->id;
        $order->update();
    }

    /**
     * Logged rather than attached to the order.
     *
     * A private customer message needs a customer thread, which a sudemi order has no reason to
     * have, so attaching one would fail silently and look like it had worked. The module log is
     * where a PrestaShop merchant actually looks, and the order reference is in the message.
     *
     * @param Order $order
     */
    private static function note($order, $message)
    {
        SudemiLogger::warn('Order ' . $order->reference . ': ' . $message);
    }

    /**
     * Adds a line to the order's private note (the "Private note" on the order page, PrestaShop
     * 1.7.7+; earlier versions have no such field and keep only the log line).
     *
     * Written straight to the column: the Order object in hand may hold a state that was just
     * changed underneath it, and saving the whole object would put the old state back.
     *
     * @param Order  $order
     * @param string $message
     */
    public static function privateMessage($order, $message)
    {
        if (!property_exists($order, 'note')) {
            return;
        }
        $current = (string) Db::getInstance()->getValue('SELECT note FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ' . (int) $order->id);
        $line = '[sudemi ' . date('Y-m-d H:i') . '] ' . $message;
        $note = $current === '' ? $line : $current . "\n" . $line;
        Db::getInstance()->update('orders', ['note' => pSQL($note)], 'id_order = ' . (int) $order->id);
        $order->note = $note;
    }

    /** The local order for a sudemi order id (null when there is none). */
    public static function localOrder($sudemiId)
    {
        return self::findLocalOrder((string) $sudemiId);
    }

    /**
     * The first line this shop cannot supply, or null when it can supply all of them.
     *
     * Checked against live PrestaShop stock rather than against anything this module last
     * pushed: what was true at the last sync is precisely what cannot be relied on here.
     *
     * @param array $pending
     * @return string|null
     */
    private static function firstShortfall(array $pending)
    {
        $lines = isset($pending['lines']) && is_array($pending['lines']) ? $pending['lines'] : [];
        if (empty($lines)) {
            return 'the order had no recognisable items';
        }

        foreach ($lines as $line) {
            list($productId, $attributeId) = SudemiSync::parseExternalId(isset($line['external_id']) ? $line['external_id'] : '0');
            if ($productId <= 0) {
                return 'an item on the order is no longer sold here';
            }
            $product = new Product($productId, false);
            if (!Validate::isLoadedObject($product) || !$product->active) {
                return sprintf(
                    '"%s" is no longer sold here',
                    isset($line['title']) ? (string) $line['title'] : 'an item'
                );
            }
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            if ((int) StockAvailable::getQuantityAvailableByProduct($productId, $attributeId ?: null) < $quantity) {
                return sprintf('"%s" is out of stock', $product->name);
            }
        }
        return null;
    }

    /**
     * Records the order so the merchant fulfils it through their normal workflow.
     *
     * A real order rather than a note of our own: it appears where the merchant already looks,
     * stock comes down, and picking and shipping work exactly as for any other sale.
     *
     * @param array  $pending
     * @param string $sudemiId
     * @return Order|null
     */
    private static function createLocalOrder(array $pending, $sudemiId)
    {
        // PrestaShop refuses an order without a customer, addresses and a carrier
        // ("Property Order->id_address_delivery is empty"), so every sudemi order used to fail
        // here. The buyer's identity and address only arrive after payment (fillCustomer), so
        // until then the order belongs to one sudemi guest customer, with a placeholder address
        // in the destination country.
        $customer = self::guestCustomer();
        if (!$customer) {
            return null;
        }
        $country = isset($pending['destination_country']) ? (string) $pending['destination_country'] : 'NL';
        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = (int) Country::getByIso($country) ?: (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $address->alias = 'sudemi';
        $address->firstname = 'sudemi';
        $address->lastname = 'buyer';
        $address->address1 = 'Delivery address follows once paid';
        $address->city = '-';
        $address->postcode = '';
        if (!$address->add()) {
            return null;
        }
        $carrierId = SudemiShipping::deliveryCarrierId();

        $cart = new Cart();
        $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency = (int) Currency::getIdByIsoCode(
            isset($pending['currency_code']) ? (string) $pending['currency_code'] : 'EUR'
        );
        if (!$cart->id_currency) {
            $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        }
        $cart->id_shop = (int) Context::getContext()->shop->id;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->id_carrier = $carrierId;
        $cart->secure_key = $customer->secure_key;
        if (!$cart->add()) {
            return null;
        }

        foreach ($pending['lines'] as $line) {
            list($productId, $attributeId) = SudemiSync::parseExternalId(isset($line['external_id']) ? $line['external_id'] : '0');
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            if (!$cart->updateQty($quantity, $productId, $attributeId ?: null)) {
                $cart->delete();
                return null;
            }
        }

        $state = (int) Configuration::get('PS_OS_PREPARATION');
        $order = new Order();
        $order->id_cart = (int) $cart->id;
        $order->id_shop = (int) $cart->id_shop;
        $order->id_shop_group = (int) Context::getContext()->shop->id_shop_group;
        $order->id_currency = (int) $cart->id_currency;
        $order->id_lang = (int) $cart->id_lang;
        $order->id_customer = (int) $customer->id;
        $order->id_address_delivery = (int) $address->id;
        $order->id_address_invoice = (int) $address->id;
        $order->id_carrier = $carrierId;
        $order->secure_key = $customer->secure_key;
        $order->payment = 'sudemi';
        $order->module = 'sudemi';
        // Prefixed so a merchant scanning their order list can see at a glance where it came from.
        $order->reference = self::REFERENCE_PREFIX . '-' . Tools::substr(str_replace('-', '', $sudemiId), 0, 9);
        $order->total_paid = isset($pending['total_minor']) ? ((int) $pending['total_minor']) / 100 : 0.0;
        $order->total_paid_real = 0.0;
        $order->total_paid_tax_incl = $order->total_paid;
        $order->total_paid_tax_excl = $order->total_paid;
        $order->total_products = $order->total_paid;
        $order->total_products_wt = $order->total_paid;
        $order->total_shipping = 0.0;
        // Awaiting payment rather than paid: an order that looks payable before the money is
        // taken is one somebody ships too early.
        $order->current_state = $state;
        $order->conversion_rate = 1.0;
        $order->valid = 0;
        if (!$order->add()) {
            $cart->delete();
            return null;
        }

        // The order's lines, as PrestaShop's own checkout writes them. createList also takes the
        // stock (StockAvailable), so the shop cannot sell the same unit while the confirmation
        // is in flight; PrestaShop puts it back when the order is cancelled. (Taking it here by hand as
        // well, as before, would take it twice.)
        $detail = new OrderDetail();
        $detail->createList($order, $cart, $state, $cart->getProducts());

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->id_order_state = $state;
        $history->add();

        self::remember($order->id, $sudemiId);

        return $order;
    }

    /** One guest customer per shop owns sudemi orders until the buyer's details arrive. */
    private static function guestCustomer()
    {
        $id = (int) Configuration::get('SUDEMI_GUEST_CUSTOMER');
        if ($id) {
            $customer = new Customer($id);
            if (Validate::isLoadedObject($customer)) {
                return $customer;
            }
        }
        $customer = new Customer();
        $customer->firstname = 'sudemi';
        $customer->lastname = 'buyer';
        $customer->email = 'orders@sudemi.com';
        $customer->is_guest = 1;
        $customer->active = 1;
        $customer->passwd = Tools::hash(Tools::passwdGen(32));
        $customer->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
        if (!$customer->add()) {
            return null;
        }
        Configuration::updateValue('SUDEMI_GUEST_CUSTOMER', (int) $customer->id);

        return $customer;
    }

    /**
     * Works through the settled_orders block of a heartbeat.
     *
     * These are orders this shop accepted while the buyer's payment still had other shops to
     * hear from. sudemi repeats each one for a week, so applying one must change nothing the
     * second time: only an order still in its unpaid state here is touched, and every outcome
     * moves it out of that state.
     *
     * @param array $settled The settled_orders block of a heartbeat response
     */
    public static function handleSettled($settled)
    {
        if (empty($settled) || !is_array($settled)) {
            return;
        }
        foreach ($settled as $entry) {
            if (!is_array($entry) || empty($entry['order_id']) || empty($entry['status'])) {
                continue;
            }
            try {
                self::settle($entry);
            } catch (Exception $e) {
                SudemiLogger::warn(sprintf(
                    'Could not apply the outcome of sudemi order %s: %s',
                    $entry['order_id'],
                    $e->getMessage()
                ));
            }
        }
    }

    /** The note on an order the buyer cancelled on sudemi after it was paid. */
    const BUYER_CANCEL_NOTE = 'Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full.';

    /**
     * What one settled_orders entry asks of the local order: 'pay', 'release', 'buyer_cancel', or
     * null (nothing to do). Pure, so it can be tested without PrestaShop.
     *
     * Paid and declined only touch an order still unpaid here; one already paid, shipped or
     * cancelled was settled before (an earlier beat, or an accept that captured straight away).
     * CANCELLED is sudemi saying the order is cancelled and the buyer refunded in full: the buyer
     * cancelled before shipping (rule 1), or the shop's own cancel came back on the beat. Applied
     * to any order not cancelled or refunded here yet and not shipped: a paid order left
     * "Payment accepted" here would get packed and sent to a buyer who has their money back.
     *
     * @param string $status       the order's status on sudemi
     * @param int    $current      the local order's current state id
     * @param bool   $shipped      whether the local order has been shipped or delivered
     * @param array  $states       ['preparation'=>id, 'canceled'=>id, 'refund'=>id, 'error'=>id]
     *
     * @return string|null
     */
    public static function settleAction($status, $current, $shipped, array $states)
    {
        $current = (int) $current;
        if ($status === 'CANCELLED') {
            $done = [(int) $states['canceled'], (int) $states['refund']];
            if (isset($states['error'])) {
                $done[] = (int) $states['error'];
            }
            if ($shipped || in_array($current, $done, true)) {
                return null;
            }

            return 'buyer_cancel';
        }
        if ($current !== (int) $states['preparation']) {
            return null;
        }
        if ($status === 'DECLINED') {
            return 'release';
        }
        if (in_array($status, ['PAID', 'SHIPPED', 'DELIVERED'], true)) {
            return 'pay';
        }

        return null;
    }

    /** Paid means ship it; declined means it never happened; cancelled means do not ship it. */
    private static function settle(array $settled)
    {
        $sudemiId = (string) $settled['order_id'];
        $state = (string) $settled['status'];
        if ($state === 'ACCEPTED' || $state === 'AWAITING_SELLER') {
            return;
        }
        $order = self::findLocalOrder($sudemiId);
        if (!$order) {
            return;
        }
        // hasBeenShipped, not hasBeenDelivered: PrestaShop's Delivered state is also flagged
        // shipped, while "delivery" only means a delivery slip, which Processing in progress has
        // (every sudemi order starts there), so hasBeenDelivered is true for unshipped orders.
        $action = self::settleAction($state, (int) $order->current_state, $order->hasBeenShipped(), [
            'preparation' => (int) Configuration::get('PS_OS_PREPARATION'),
            'canceled' => (int) Configuration::get('PS_OS_CANCELED'),
            'refund' => (int) Configuration::get('PS_OS_REFUND'),
            'error' => (int) Configuration::get('PS_OS_ERROR'),
        ]);
        if ($action === null) {
            return;
        }

        if ($action === 'buyer_cancel') {
            // The money is already back with the buyer (sudemi refunded it), so this only stops
            // the shipment and restocks. setState marks the change as the module's own, so the
            // status hook does not send a /cancel (or /refund) back to sudemi for it.
            self::setState($order, (int) Configuration::get('PS_OS_CANCELED'));
            self::note($order, self::BUYER_CANCEL_NOTE);
            self::privateMessage($order, self::BUYER_CANCEL_NOTE);

            return;
        }

        if ($action === 'release') {
            // Released without being charged: the capture failed, or the authorisation lapsed
            // before the last shop answered. The local order must not survive, and the stock it
            // reserved goes back.
            $reason = !empty($settled['declined_reason']) ? (string) $settled['declined_reason'] : 'reason not given';
            // PrestaShop puts the stock back itself when an order moves to Canceled.
            self::setState($order, (int) Configuration::get('PS_OS_CANCELED'));
            SudemiLogger::warn(sprintf(
                'sudemi order %s was released after all: %s. The local order was cancelled and stock restored.',
                $sudemiId,
                $reason
            ));
            return;
        }

        // PAID, or already further along: the money was taken, and only now is the address released.
        self::fillCustomer($order, $sudemiId);
        self::setState($order, (int) Configuration::get('PS_OS_PAYMENT'));
        SudemiLogger::info(sprintf(
            'sudemi order %s is paid; order %s can be shipped.',
            $sudemiId,
            $order->reference
        ));
    }

    /** True while this module itself changes an order's state (so the status hook ignores it). */
    public static $changingState = false;

    /** Refund (amount null = in full) through sudemi; the outcome is noted on the order and logged. */
    public static function refund($order, $amountMinor, $reason)
    {
        $sudemiId = self::sudemiIdOf($order);
        if ($sudemiId === null) {
            return;
        }
        $body = ['reason' => $reason];
        if ($amountMinor !== null) {
            $body['amountMinor'] = (int) $amountMinor;
        }
        self::noteMoneyResult($order, SudemiClient::postSigned('/integration/v1/orders/' . rawurlencode($sudemiId) . '/refund', $body));
    }

    /** A paid order cancelled in the shop: refunded in full and cancelled on sudemi. */
    public static function cancel($order, $reason)
    {
        $sudemiId = self::sudemiIdOf($order);
        if ($sudemiId === null) {
            return;
        }
        self::noteMoneyResult($order, SudemiClient::postSigned('/integration/v1/orders/' . rawurlencode($sudemiId) . '/cancel', ['reason' => $reason]));
    }

    private static function noteMoneyResult($order, $response)
    {
        if (is_array($response) && (int) $response['status'] === 200) {
            SudemiLogger::info('Order ' . $order->reference . ': refunded to the buyer through sudemi.');

            return;
        }
        $message = is_array($response) && isset($response['body']['message']) ? (string) $response['body']['message']
            : (is_array($response) ? (string) $response['status'] : 'sudemi could not be reached');
        SudemiLogger::error('Order ' . $order->reference . ': sudemi did not refund this (' . $message
            . '). The buyer has NOT been paid back; contact sudemi support (support@sudemi.com).');
    }

    /** @param Order $order */
    private static function setState($order, $state)
    {
        self::$changingState = true;
        try {
            self::applyState($order, $state);
        } finally {
            self::$changingState = false;
        }
    }

    /** @param Order $order */
    private static function applyState($order, $state)
    {
        if (!$state) {
            return;
        }
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($state, (int) $order->id);
        $history->add();
    }

    /**
     * The local order already built for a sudemi order, if there is one.
     *
     * @param string $sudemiId
     * @return Order|null
     */
    private static function findLocalOrder($sudemiId)
    {
        $orderId = (int) Db::getInstance()->getValue(
            'SELECT id_order FROM `' . _DB_PREFIX_ . "sudemi_order`
              WHERE sudemi_order_id = '" . pSQL($sudemiId) . "'"
        );
        if ($orderId <= 0) {
            return null;
        }
        $order = new Order($orderId);

        return Validate::isLoadedObject($order) ? $order : null;
    }

    /**
     * Tells sudemi the order has shipped or arrived.
     *
     * The sudemi order id is recovered from the reference this module wrote when it created the
     * order, so no extra storage is needed and an order that was not placed through sudemi is
     * simply skipped by the caller.
     *
     * @param Order  $order
     * @param string $event 'shipped' or 'delivered'
     */
    public static function report($order, $event)
    {
        $sudemiId = self::sudemiIdOf($order);
        if ($sudemiId === null) {
            return;
        }

        $body = [];
        if ($event === 'shipped') {
            // PrestaShop keeps the carrier on the order and the tracking on the shipment. No
            // tracking is not an error: sudemi says "not given" rather than withholding the
            // dispatch notice over a missing field.
            $carrier = new Carrier((int) $order->id_carrier);
            $body = [
                'carrier' => Validate::isLoadedObject($carrier) ? $carrier->name : null,
                'trackingCode' => $order->getWsShippingNumber() ?: null,
            ];
        }

        $response = SudemiClient::postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/' . $event,
            $body
        );
        if ($response === null) {
            SudemiLogger::warn('Could not reach sudemi to report ' . $event . ' for ' . $sudemiId . '.');

            return;
        }
        SudemiLogger::info('Reported ' . $event . ' for sudemi order ' . $sudemiId . '.');
    }

    /**
     * The table linking a PrestaShop order to the sudemi order it came from.
     *
     * A table rather than the reference, because the reference only has room for a fragment of
     * the uuid and a prefix is not an id -- two sudemi orders could share one. PrestaShop has no
     * per-order meta of its own, so this is the honest way to hold the link.
     */
    public static function installTable()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'sudemi_order` (
                `id_order` INT UNSIGNED NOT NULL,
                `sudemi_order_id` VARCHAR(36) NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_order`),
                UNIQUE KEY `sudemi_order_id` (`sudemi_order_id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function uninstallTable()
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'sudemi_order`'
        );
    }

    /** The sudemi order id behind a PrestaShop order, or null when it was not placed through us. */
    public static function sudemiIdOf($order)
    {
        $value = Db::getInstance()->getValue(
            'SELECT sudemi_order_id FROM `' . _DB_PREFIX_ . 'sudemi_order`
              WHERE id_order = ' . (int) $order->id
        );

        return empty($value) ? null : (string) $value;
    }

    private static function remember($orderId, $sudemiId)
    {
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'sudemi_order`
                 (id_order, sudemi_order_id, created_at)
             VALUES (' . (int) $orderId . ", '" . pSQL($sudemiId) . "', NOW())"
        );
    }

    public static function autoConfirmEnabled()
    {
        $value = Configuration::get(self::CONF_AUTO_CONFIRM);
        return $value === false || $value === null || (string) $value !== '0';
    }
}
