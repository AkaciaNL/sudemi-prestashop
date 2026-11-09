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
     * leave a buyer charged for something this shop has no record of; this way an acceptance
     * that does not land leaves a local order with no money behind it, which is recoverable here.
     *
     * @param array $pending One entry of the pending_orders block
     */
    private static function answer(array $pending)
    {
        $sudemiId = (string) $pending['order_id'];

        $existing = self::findLocalOrder($sudemiId);
        if ($existing) {
            if (self::isLocallyCancelled($existing)) {
                // The merchant cancelled the local order (and PrestaShop put the stock back)
                // while sudemi was still waiting for an answer. Accepting now would take the
                // buyer's money for goods this shop no longer means to send.
                self::rejectFromShop($existing, $sudemiId, 'cancelled in the shop', false);

                return;
            }
            // Already built, so a previous run got this far and the answer was lost in transit,
            // or sudemi could not be reached. Re-sending is safe: sudemi answers already_resolved
            // with the order's status if the first one landed.
            self::accept($sudemiId, $existing, false);

            return;
        }

        // An order in another currency than the one this shop sells in is declined rather than
        // booked in the wrong currency (protocol 4c): the shop's books would show amounts the
        // buyer never paid.
        $shortfall = self::currencyProblem($pending);
        if ($shortfall === null) {
            $shortfall = self::firstShortfall($pending);
        }
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
        self::accept($sudemiId, $order, false);
    }

    /**
     * What an /accept answer means for the local order. Pure, so it can be tested without
     * PrestaShop.
     *
     * Only a definitive answer decides: 'paid' (captured, keep it and ship), 'waiting' (accepted,
     * other shops in the same payment still to answer), 'declined' (released, nothing charged:
     * cancel and restock), 'cancelled' (cancelled on sudemi, any payment refunded). Everything
     * else is 'retry': unreachable, 5xx, 429, 401, 403, any 409 (site_mismatch, a lapsed
     * authorisation that sudemi may still settle either way), or already_resolved without a
     * status this module knows. A retry leaves the order and its stock as they are; cancelling on
     * it would cancel orders whose buyers may already have paid.
     *
     * @param array|null $response
     *
     * @return string paid|waiting|declined|cancelled|retry
     */
    public static function acceptOutcome($response)
    {
        if (!is_array($response)) {
            return 'retry';
        }
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : [];
        if ($status === 200) {
            $answer = isset($body['status']) ? (string) $body['status'] : '';
            if ($answer === 'ok') {
                return isset($body['payment']) && $body['payment'] === 'waiting_for_other_shops' ? 'waiting' : 'paid';
            }
            if ($answer === 'already_resolved') {
                return self::outcomeOfStatus(isset($body['order_status']) ? (string) $body['order_status'] : '');
            }

            return 'retry';
        }
        if ($status === 404 && isset($body['error']) && $body['error'] === 'no_such_order') {
            // No order of this shop's by that id: nothing was or will be charged for it here.
            return 'declined';
        }

        return 'retry';
    }

    /**
     * What a /reject answer means: 'declined' when it was applied (or there is no such order),
     * otherwise as acceptOutcome reads already_resolved (a reject that came too late tells how
     * the order really ended: PAID, DECLINED, CANCELLED...). Pure.
     *
     * @param array|null $response
     *
     * @return string paid|waiting|declined|cancelled|retry
     */
    public static function rejectOutcome($response)
    {
        if (!is_array($response)) {
            return 'retry';
        }
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : [];
        if ($status === 200 && isset($body['status']) && $body['status'] === 'ok') {
            return 'declined';
        }

        return self::acceptOutcome($response) === 'waiting' ? 'retry' : self::acceptOutcome($response);
    }

    /** The outcome an order status on sudemi stands for. */
    private static function outcomeOfStatus($orderStatus)
    {
        if (in_array($orderStatus, ['PAID', 'SHIPPED', 'DELIVERED'], true)) {
            return 'paid';
        }
        if ($orderStatus === 'ACCEPTED') {
            return 'waiting';
        }
        if ($orderStatus === 'DECLINED') {
            return 'declined';
        }
        if ($orderStatus === 'CANCELLED' || $orderStatus === 'REFUNDED') {
            return 'cancelled';
        }

        return 'retry';
    }

    /**
     * Sends the acceptance and applies a definitive answer.
     *
     * @param string $sudemiId
     * @param Order  $order
     * @param bool   $quiet    true on the heartbeat's reconcile pass: no log line for an order
     *                         that is simply still waiting
     *
     * @return string the outcome
     */
    private static function accept($sudemiId, $order, $quiet)
    {
        $outcome = self::acceptOutcome(SudemiClient::postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/accept',
            []
        ));
        self::applyOutcome($sudemiId, $order, $outcome, $quiet);

        return $outcome;
    }

    /** States in which this module still treats a local order as unpaid and open. */
    private static function isUnpaidOpen($order)
    {
        return (int) $order->current_state === (int) Configuration::get('PS_OS_PREPARATION');
    }

    /** Cancelled, refunded or failed here. */
    private static function isLocallyCancelled($order)
    {
        return in_array((int) $order->current_state, [
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_REFUND'),
            (int) Configuration::get('PS_OS_ERROR'),
        ], true);
    }

    /**
     * Brings the local order in line with a definitive outcome; a retry changes nothing.
     *
     * @param string $sudemiId
     * @param Order  $order
     * @param string $outcome  paid|waiting|declined|cancelled|retry
     * @param bool   $quiet
     */
    private static function applyOutcome($sudemiId, $order, $outcome, $quiet)
    {
        if ($outcome === 'paid') {
            if (!self::isUnpaidOpen($order)) {
                return;
            }
            // The address arrives only now. sudemi withholds it while the order is still being
            // decided, because answering "do I have stock" needs nobody's address.
            self::fillCustomer($order, $sudemiId);
            self::setState($order, (int) Configuration::get('PS_OS_PAYMENT'));
            SudemiLogger::info(sprintf('Confirmed sudemi order %s as order %s; the buyer has been charged.', $sudemiId, $order->reference));

            return;
        }
        if ($outcome === 'waiting') {
            // Accepted, but not paid. The buyer paid several shops in one payment and a card
            // authorisation can be captured only once, so sudemi takes the money when the last of
            // them has answered. Until then the order keeps its unpaid state and has no address.
            if (!$quiet) {
                self::note($order, 'Accepted on sudemi. The buyer paid several shops in one payment; sudemi '
                    . 'takes the money once every shop has answered. Do NOT ship until this order is marked '
                    . 'as paid.');
            }

            return;
        }
        if ($outcome === 'declined') {
            if (!self::isUnpaidOpen($order)) {
                return;
            }
            // Released without being charged. The local order must not survive, and the stock it
            // reserved goes back (PrestaShop restocks when an order moves to Canceled).
            self::setState($order, (int) Configuration::get('PS_OS_CANCELED'));
            SudemiLogger::warn(sprintf(
                'sudemi order %s was released (nothing charged). Local order %s was cancelled and stock restored.',
                $sudemiId,
                $order->reference
            ));

            return;
        }
        if ($outcome === 'cancelled') {
            if (self::isLocallyCancelled($order) || $order->hasBeenShipped()) {
                return;
            }
            self::setState($order, (int) Configuration::get('PS_OS_CANCELED'));
            $message = 'Cancelled on sudemi; any payment was refunded to the buyer in full. Do not ship this order.';
            self::note($order, $message);
            self::privateMessage($order, $message);

            return;
        }
        // Not answered (unreachable, 5xx, 429, a refusal that may be temporary). The local order
        // and its stock stay exactly as they are: the buyer may already have paid. The next
        // heartbeat asks again.
        SudemiLogger::warn(sprintf(
            'No definite answer from sudemi for order %s; the local order %s is kept as it is. Will try again on the next heartbeat.',
            $sudemiId,
            $order->reference
        ));
    }

    /** How many unpaid local orders one heartbeat re-checks. */
    const RECONCILE_LIMIT = 25;

    /**
     * Re-asks sudemi about local orders still waiting for payment here.
     *
     * pending_orders only carries orders sudemi is still waiting on, and settled_orders only
     * orders this shop's acceptance reached. An acceptance whose answer was lost, or an order
     * sudemi released while it could not be reached, appears in neither, and its local order
     * would sit unpaid with its stock held forever. Re-sending the acceptance is safe (sudemi
     * answers already_resolved with how the order ended). An order still without a definite
     * answer 3 days after it arrived is past sudemi's answer window (about two days): then the
     * module asks with /reject, which a suspended shop may still send and which also returns
     * the final status.
     *
     * @param string[] $skip sudemi order ids this beat already handled
     */
    public static function reconcile(array $skip = [])
    {
        $rows = Db::getInstance()->executeS(
            'SELECT so.id_order, so.sudemi_order_id, so.created_at
               FROM `' . _DB_PREFIX_ . 'sudemi_order` so
               JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_order = so.id_order
              WHERE o.current_state = ' . (int) Configuration::get('PS_OS_PREPARATION') . '
                AND so.created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
              ORDER BY so.created_at ASC
              LIMIT ' . (int) (self::RECONCILE_LIMIT + count($skip))
        );
        if (!is_array($rows)) {
            return;
        }
        $done = 0;
        foreach ($rows as $row) {
            $sudemiId = (string) $row['sudemi_order_id'];
            if (in_array($sudemiId, $skip, true) || $done >= self::RECONCILE_LIMIT) {
                continue;
            }
            ++$done;
            try {
                $order = new Order((int) $row['id_order']);
                if (!Validate::isLoadedObject($order)) {
                    continue;
                }
                $outcome = self::accept($sudemiId, $order, true);
                if ($outcome === 'retry' && strtotime((string) $row['created_at']) < time() - 3 * 86400) {
                    $answer = self::rejectOutcome(SudemiClient::postSigned(
                        '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
                        ['reason' => 'no confirmation from sudemi in time']
                    ));
                    self::applyOutcome($sudemiId, new Order((int) $row['id_order']), $answer, true);
                }
            } catch (Exception $e) {
                SudemiLogger::warn(sprintf('Could not re-check sudemi order %s: %s', $sudemiId, $e->getMessage()));
            }
        }
    }

    /**
     * The merchant cancelled an order that has not been paid: sudemi must be told with /reject
     * (a /cancel is refused before payment), so the buyer's hold is released and nothing is
     * charged. If it turns out the order was paid meanwhile, it is cancelled with a full refund.
     *
     * @param Order  $order
     * @param string $sudemiId
     * @param string $reason
     * @param bool   $queueOnRetry put it in the order-call retry list when sudemi does not answer
     */
    public static function rejectFromShop($order, $sudemiId, $reason, $queueOnRetry = true)
    {
        $response = SudemiClient::postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
            ['reason' => $reason]
        );
        $outcome = self::rejectOutcome($response);
        if ($outcome === 'retry') {
            if ($queueOnRetry) {
                self::queueCall($sudemiId, 'reject', ['reason' => $reason]);
            }
            SudemiLogger::warn(sprintf('Could not tell sudemi that order %s was cancelled here; will try again on the next heartbeat.', $sudemiId));

            return $outcome;
        }
        self::applyShopReject($order, $sudemiId, $outcome);

        return $outcome;
    }

    private static function applyShopReject($order, $sudemiId, $outcome)
    {
        if ($outcome === 'paid') {
            // The order was paid before the cancellation reached sudemi. The merchant cancelled
            // it, so the buyer gets the money back.
            SudemiLogger::warn(sprintf('sudemi order %s was already paid when it was cancelled here; asking sudemi to refund it in full.', $sudemiId));
            if ($order) {
                self::cancel($order, 'Cancelled by the shop');
            }

            return;
        }
        SudemiLogger::info(sprintf('sudemi order %s was released after the cancellation here; the buyer was not charged.', $sudemiId));
    }

    // ------------------------------------------------------------- order-call retry list ----

    const CONF_CALLS = 'SUDEMI_ORDER_CALLS';
    const CALLS_MAX = 500;
    const CALLS_PER_BEAT = 25;
    const CALLS_MAX_AGE = 1209600; // 14 days

    /**
     * What a shipped/delivered (or other order) call answer means: 'done' (200), 'retry'
     * (unreachable, 5xx, 429, 401, 403, any 409: site_mismatch, or not_paid for an order that may
     * be paid later) or 'refused' (any other 4xx: sending it again cannot help). Pure.
     */
    public static function callOutcome($response)
    {
        if (!is_array($response)) {
            return 'retry';
        }
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        if ($status === 200) {
            return 'done';
        }
        if ($status === 0 || $status >= 500 || in_array($status, [401, 403, 409, 429], true)) {
            return 'retry';
        }

        return 'refused';
    }

    public static function calls()
    {
        $raw = Configuration::get(self::CONF_CALLS);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function storeCalls(array $calls)
    {
        if (empty($calls)) {
            Configuration::deleteByName(self::CONF_CALLS);
        } else {
            Configuration::updateValue(self::CONF_CALLS, json_encode($calls, SudemiClient::jsonFlags()));
        }
    }

    public static function queueCall($sudemiId, $call, array $body)
    {
        $calls = self::calls();
        $key = $sudemiId . '|' . $call;
        $calls[$key] = [
            'order_id' => (string) $sudemiId,
            'call' => (string) $call,
            'body' => $body,
            'since' => isset($calls[$key]['since']) ? (int) $calls[$key]['since'] : time(),
        ];
        if (count($calls) > self::CALLS_MAX) {
            $calls = array_slice($calls, -self::CALLS_MAX, null, true);
        }
        self::storeCalls($calls);
    }

    private static function forgetCall($key)
    {
        $calls = self::calls();
        unset($calls[$key]);
        self::storeCalls($calls);
    }

    /**
     * Sends the order calls that did not get through before (shipped, delivered, a reject for an
     * order cancelled here), oldest first, a few per beat. Kept until sudemi answers definitely;
     * given up after 14 days with an error in the log.
     */
    public static function drainCalls()
    {
        $calls = self::calls();
        $sent = 0;
        foreach ($calls as $key => $entry) {
            if ($sent >= self::CALLS_PER_BEAT) {
                break;
            }
            $sudemiId = isset($entry['order_id']) ? (string) $entry['order_id'] : '';
            $call = isset($entry['call']) ? (string) $entry['call'] : '';
            if ($sudemiId === '' || !in_array($call, ['shipped', 'delivered', 'reject'], true)) {
                self::forgetCall($key);
                continue;
            }
            if (isset($entry['since']) && (int) $entry['since'] < time() - self::CALLS_MAX_AGE) {
                self::forgetCall($key);
                SudemiLogger::error(sprintf('Gave up telling sudemi "%s" for order %s after 14 days. Report it in the sudemi portal.', $call, $sudemiId));
                continue;
            }
            ++$sent;
            $body = isset($entry['body']) && is_array($entry['body']) ? $entry['body'] : [];
            $response = SudemiClient::postSigned('/integration/v1/orders/' . rawurlencode($sudemiId) . '/' . $call, $body);
            if ($call === 'reject') {
                $outcome = self::rejectOutcome($response);
                if ($outcome === 'retry') {
                    continue;
                }
                self::forgetCall($key);
                self::applyShopReject(self::findLocalOrder($sudemiId), $sudemiId, $outcome);
                continue;
            }
            $outcome = self::callOutcome($response);
            if ($outcome === 'retry') {
                continue;
            }
            self::forgetCall($key);
            if ($outcome === 'done') {
                SudemiLogger::info('Reported ' . $call . ' for sudemi order ' . $sudemiId . ' (retried).');
            } else {
                SudemiLogger::error('sudemi refused "' . $call . '" for order ' . $sudemiId . ': '
                    . (isset($response['body']['message']) ? (string) $response['body']['message'] : 'HTTP ' . (int) $response['status']));
            }
        }
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
     * Why the order's currency cannot be booked here, or null. Null for a core that sends no
     * currency_code (the order is then booked as before).
     *
     * @param array $pending
     * @return string|null
     */
    private static function currencyProblem(array $pending)
    {
        $code = isset($pending['currency_code']) ? (string) $pending['currency_code'] : '';
        if (SudemiCurrency::normalise($code) === '') {
            return SudemiCurrency::orderProblem($code, '', true);
        }

        return SudemiCurrency::orderProblem($code, SudemiSync::currency(), self::activeCurrencyId($code) > 0);
    }

    /** The id of an installed, switched-on PrestaShop currency with this ISO code, or 0. */
    private static function activeCurrencyId($code)
    {
        $id = (int) Currency::getIdByIsoCode(SudemiCurrency::normalise($code));
        if ($id <= 0) {
            return 0;
        }
        $currency = new Currency($id);

        return Validate::isLoadedObject($currency) && $currency->active && empty($currency->deleted) ? $id : 0;
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
        // In the order's own currency (checked in currencyProblem before this); a core that sends
        // none gets the shop's default currency, as before.
        $code = SudemiCurrency::normalise(isset($pending['currency_code']) ? $pending['currency_code'] : '');
        $cart->id_currency = $code !== '' ? self::activeCurrencyId($code) : (int) Configuration::get('PS_CURRENCY_DEFAULT');
        if (!$cart->id_currency) {
            return null;
        }
        $currency = new Currency((int) $cart->id_currency);
        $code = Validate::isLoadedObject($currency) ? (string) $currency->iso_code : $code;
        $cart->id_shop = (int) Context::getContext()->shop->id;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->id_carrier = $carrierId;
        $cart->secure_key = $customer->secure_key;
        if (!$cart->add()) {
            return null;
        }

        // What the buyer paid, from sudemi. Null from a core that does not send it yet, and then
        // the order is priced from the catalogue as before.
        $paid = self::paidAmounts($pending);
        $paidByProduct = [];
        foreach ($pending['lines'] as $index => $line) {
            list($productId, $attributeId) = SudemiSync::parseExternalId(isset($line['external_id']) ? $line['external_id'] : '0');
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            if (!$cart->updateQty($quantity, $productId, $attributeId ?: null)) {
                $cart->delete();
                return null;
            }
            if ($paid !== null) {
                $key = (int) $productId . '-' . (int) $attributeId;
                if (!isset($paidByProduct[$key])) {
                    $paidByProduct[$key] = 0;
                }
                $paidByProduct[$key] += $paid['lines'][$index] * $quantity;
            }
        }

        $products = $cart->getProducts();
        if ($paid !== null) {
            // The buyer's price wins over today's catalogue price: it may have changed since the
            // last sync, and the shop's order has to say what was actually paid. The VAT inside
            // it is split out at the rate PrestaShop used for the cart line, never added on top.
            $goodsIncl = 0;
            $goodsExcl = 0;
            foreach ($products as &$row) {
                $key = (int) $row['id_product'] . '-' . (int) $row['id_product_attribute'];
                $qty = max(1, (int) $row['cart_quantity']);
                if (!isset($paidByProduct[$key])) {
                    $cart->delete();
                    return null;
                }
                $gross = $paidByProduct[$key];
                $net = self::netOf($gross, (float) $row['price'], (float) $row['price_wt']);
                $row['price_wt'] = round(SudemiCurrency::fromMinor($gross, $code) / $qty, 6);
                $row['price'] = round(SudemiCurrency::fromMinor($net, $code) / $qty, 6);
                $row['price_with_reduction'] = $row['price_wt'];
                $row['price_with_reduction_without_tax'] = $row['price'];
                $row['total_wt'] = SudemiCurrency::fromMinor($gross, $code);
                $row['total'] = SudemiCurrency::fromMinor($net, $code);
                $goodsIncl += $gross;
                $goodsExcl += $net;
            }
            unset($row);
            $carrier = new Carrier($carrierId);
            $carrierRate = Configuration::get('PS_TAX') && Validate::isLoadedObject($carrier)
                ? (float) $carrier->getTaxesRate($address) : 0.0;
            $shippingExcl = (int) round($paid['shipping'] * 100 / (100 + $carrierRate));
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
        $order->total_paid = isset($pending['total_minor']) ? SudemiCurrency::fromMinor($pending['total_minor'], $code) : 0.0;
        $order->total_paid_real = 0.0;
        $order->total_paid_tax_incl = $order->total_paid;
        if ($paid === null) {
            $order->total_paid_tax_excl = $order->total_paid;
            $order->total_products = $order->total_paid;
            $order->total_products_wt = $order->total_paid;
            $order->total_shipping = 0.0;
        } else {
            // The goods and the shipping as the buyer paid them, so the shop's books show the
            // shipping instead of folding it into the products. The total stays total_minor.
            $tax = ($goodsIncl - $goodsExcl) + ($paid['shipping'] - $shippingExcl);
            $order->total_paid_tax_excl = SudemiCurrency::fromMinor($paid['total'] - $tax, $code);
            $order->total_products = SudemiCurrency::fromMinor($goodsExcl, $code);
            $order->total_products_wt = SudemiCurrency::fromMinor($goodsIncl, $code);
            $order->total_shipping = SudemiCurrency::fromMinor($paid['shipping'], $code);
            $order->total_shipping_tax_incl = SudemiCurrency::fromMinor($paid['shipping'], $code);
            $order->total_shipping_tax_excl = SudemiCurrency::fromMinor($shippingExcl, $code);
            $order->carrier_tax_rate = $carrierRate;
        }
        // Awaiting payment rather than paid: an order that looks payable before the money is
        // taken is one somebody ships too early.
        $order->current_state = $state;
        // The order currency's rate against the shop default, as PrestaShop's own checkout
        // records it (1 when the order is in the default currency, which is the normal case).
        $order->conversion_rate = Validate::isLoadedObject($currency) && (float) $currency->conversion_rate > 0
            ? (float) $currency->conversion_rate : 1.0;
        $order->valid = 0;
        // The shop's rounding, as PrestaShop's own checkout records it on the order: left at 0
        // the invoice reads it as "round up" and its VAT rows no longer match the order lines.
        $order->round_mode = (int) Configuration::get('PS_PRICE_ROUND_MODE');
        $order->round_type = (int) Configuration::get('PS_ROUND_TYPE');
        if (!$order->add()) {
            $cart->delete();
            return null;
        }

        // The order's lines, as PrestaShop's own checkout writes them. createList also takes the
        // stock (StockAvailable), so the shop cannot sell the same unit while the confirmation
        // is in flight; PrestaShop puts it back when the order is cancelled. (Taking it here by hand as
        // well, as before, would take it twice.)
        $detail = new OrderDetail();
        $detail->createList($order, $cart, $state, $products);

        if ($paid !== null) {
            // The shipment line PrestaShop's own checkout writes: it is where the order page shows
            // the carrier and the shipping paid, and where the merchant enters the tracking number.
            $orderCarrier = new OrderCarrier();
            $orderCarrier->id_order = (int) $order->id;
            $orderCarrier->id_carrier = $carrierId;
            $orderCarrier->weight = (float) $cart->getTotalWeight();
            $orderCarrier->shipping_cost_tax_excl = SudemiCurrency::fromMinor($shippingExcl, $code);
            $orderCarrier->shipping_cost_tax_incl = SudemiCurrency::fromMinor($paid['shipping'], $code);
            $orderCarrier->add();
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->id_order_state = $state;
        $history->add();

        self::remember($order->id, $sudemiId, $code);

        return $order;
    }

    /**
     * What the buyer paid for a pending order, in minor units: the shipping, each line's unit
     * price (keyed like the lines) and the total.
     *
     * Null when sudemi did not send them (a core older than this module) or sent something that
     * is not money; the order is then priced from the catalogue, as before.
     *
     * @param array $pending One entry of the pending_orders block
     * @return array|null
     */
    public static function paidAmounts(array $pending)
    {
        if (!isset($pending['shipping_minor'], $pending['total_minor']) || empty($pending['lines'])
            || !is_array($pending['lines']) || !is_numeric($pending['shipping_minor'])
            || !is_numeric($pending['total_minor']) || (int) $pending['shipping_minor'] < 0) {
            return null;
        }
        $lines = [];
        foreach ($pending['lines'] as $index => $line) {
            if (!is_array($line) || !isset($line['unit_price_minor']) || !is_numeric($line['unit_price_minor'])
                || (int) $line['unit_price_minor'] < 0) {
                return null;
            }
            $lines[$index] = (int) $line['unit_price_minor'];
        }

        return [
            'shipping' => (int) $pending['shipping_minor'],
            'lines' => $lines,
            'total' => (int) $pending['total_minor'],
        ];
    }

    /**
     * The part of a gross amount without VAT, at the ratio of PrestaShop's own price without and
     * with tax for the line. Minor units in, minor units out; never more than the gross.
     */
    public static function netOf($grossMinor, $priceExcl, $priceIncl)
    {
        if ($priceIncl <= 0 || $priceExcl <= 0 || $priceExcl >= $priceIncl) {
            return (int) $grossMinor;
        }

        return (int) round($grossMinor * $priceExcl / $priceIncl);
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
        // In the order's currency (protocol 4c). A refund in another currency than the buyer paid
        // in is not sent: the amount would mean something else on sudemi's side.
        $prepared = SudemiCurrency::refundBody($amountMinor, $reason, self::currencyOf($order), self::bookedCurrency($order));
        if (isset($prepared['error'])) {
            SudemiLogger::error('Order ' . $order->reference . ': ' . $prepared['error']);
            self::privateMessage($order, 'sudemi refund not sent: ' . $prepared['error']);

            return;
        }
        self::noteMoneyResult($order, SudemiClient::postSigned('/integration/v1/orders/' . rawurlencode($sudemiId) . '/refund', $prepared['body']));
    }

    /** The ISO code of a PrestaShop order's currency, or '' when it cannot be read. */
    public static function currencyOf($order)
    {
        $currency = new Currency((int) $order->id_currency);

        return Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '';
    }

    /** The currency sudemi booked the order in, or null for an order recorded before 1.2.12. */
    public static function bookedCurrency($order)
    {
        $value = Db::getInstance()->getValue(
            'SELECT currency_code FROM `' . _DB_PREFIX_ . 'sudemi_order`
              WHERE id_order = ' . (int) $order->id
        );

        return empty($value) ? null : (string) $value;
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
        $outcome = self::callOutcome($response);
        if ($outcome === 'retry') {
            // Not lost: a buyer who is never told the order shipped, or whose return window never
            // starts, is the failure here. Sent again on the next heartbeat.
            self::queueCall($sudemiId, $event, $body);
            SudemiLogger::warn('Could not report ' . $event . ' for sudemi order ' . $sudemiId
                . (is_array($response) ? ' (HTTP ' . (int) $response['status'] . ')' : '') . '; will try again on the next heartbeat.');

            return;
        }
        if ($outcome === 'refused') {
            SudemiLogger::error('sudemi refused "' . $event . '" for order ' . $sudemiId . ': '
                . (isset($response['body']['message']) ? (string) $response['body']['message'] : 'HTTP ' . (int) $response['status']));

            return;
        }
        // A newer report supersedes one still waiting for the same event.
        self::forgetCall($sudemiId . '|' . $event);
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
                `currency_code` CHAR(3) NULL,
                PRIMARY KEY (`id_order`),
                UNIQUE KEY `sudemi_order_id` (`sudemi_order_id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /** 1.2.12: the currency sudemi booked each order in, on tables made by an older version. */
    public static function addCurrencyColumn()
    {
        $exists = Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . "sudemi_order` LIKE 'currency_code'"
        );
        if (!empty($exists)) {
            return true;
        }

        return Db::getInstance()->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . 'sudemi_order` ADD COLUMN `currency_code` CHAR(3) NULL'
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

    private static function remember($orderId, $sudemiId, $currency = '')
    {
        $currency = SudemiCurrency::normalise($currency);
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'sudemi_order`
                 (id_order, sudemi_order_id, created_at, currency_code)
             VALUES (' . (int) $orderId . ", '" . pSQL($sudemiId) . "', NOW(), "
                . ($currency !== '' ? "'" . pSQL($currency) . "'" : 'NULL') . ')'
        );
    }

    public static function autoConfirmEnabled()
    {
        $value = Configuration::get(self::CONF_AUTO_CONFIRM);
        return $value === false || $value === null || (string) $value !== '0';
    }
}
