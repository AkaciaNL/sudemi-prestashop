<?php
/**
 * Keeping the PrestaShop copy of each sudemi order current (protocol 4d, the change feed).
 *
 * sudemi is the source of truth for an order: a change made anywhere (the seller portal, the
 * seller API, sudemi support, the buyer, a deadline that ran out, this module) is listed on the
 * heartbeat as changed_orders, each with a revision. This module applies an entry only when its
 * revision is higher than the one it last applied, so a repeat or an older entry changes nothing.
 * A daily GET /orders?from=<yesterday> is the safety net for anything the feed could not bring.
 *
 * What is applied to the local order: the state (paid, released, shipped, delivered, cancelled,
 * refunded), the tracking number and a note for every refund. Changes are made as the module's
 * own (SudemiOrders::setState), so the status hook never sends them back to sudemi, and without
 * any e-mail (plugins never send e-mail).
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiChanges
{
    /** JSON {install, cursor}: the cursor belongs to one install and starts over after re-pairing. */
    const CONF_CURSOR = 'SUDEMI_CHANGES_CURSOR';
    /** When the feed started (unix time): orders placed before it are never booked here by the feed. */
    const CONF_SINCE = 'SUDEMI_CHANGES_SINCE';
    const CONF_DAILY = 'SUDEMI_ORDERS_CHECKED_AT';

    /** Further beats sent at once while sudemi says there is more (200 entries each). */
    const MORE_BEATS = 5;
    const DAILY_SECONDS = 86400;
    const DAILY_PAGES = 10;

    /** Statuses for which the shop has (or should have) an order to fulfil. */
    const BOOKED = ['ACCEPTED', 'PAID', 'SHIPPED', 'DELIVERED'];

    /** The cursor to send on the next heartbeat ('' to start). */
    public static function cursor()
    {
        $stored = json_decode((string) Configuration::get(self::CONF_CURSOR), true);
        $install = (string) SudemiClient::installId();
        if (!is_array($stored) || !isset($stored['install'], $stored['cursor']) || $stored['install'] !== $install) {
            return '';
        }

        return (string) $stored['cursor'];
    }

    private static function rememberCursor($cursor)
    {
        Configuration::updateValue(self::CONF_CURSOR, json_encode([
            'install' => (string) SudemiClient::installId(),
            'cursor' => (string) $cursor,
        ]));
    }

    public static function forget()
    {
        Configuration::deleteByName(self::CONF_CURSOR);
        Configuration::deleteByName(self::CONF_DAILY);
    }

    /** Uninstall only: a reconnect keeps the start, so it never imports old orders. */
    public static function forgetSince()
    {
        Configuration::deleteByName(self::CONF_SINCE);
    }

    /** When the feed started; set the first time it is asked for. */
    public static function since()
    {
        $since = (int) Configuration::get(self::CONF_SINCE);
        if ($since <= 0) {
            $since = time();
            Configuration::updateValue(self::CONF_SINCE, $since);
        }

        return $since;
    }

    /**
     * Works through the change feed of one heartbeat answer and keeps the cursor.
     *
     * @return bool whether sudemi has more changes waiting (changes_more)
     */
    public static function handle(array $body)
    {
        // A core without the feed: nothing to apply, and no cursor to keep (1.2 behaviour).
        if (!array_key_exists('changed_orders', $body) || !is_array($body['changed_orders'])) {
            return false;
        }
        self::since();
        if (!empty($body['changes_cursor_reset'])) {
            SudemiLogger::info('sudemi started the order change list over (last 7 days); orders already up to date are left as they are.');
        }
        foreach ($body['changed_orders'] as $entry) {
            if (!is_array($entry) || empty($entry['order_id'])) {
                continue;
            }
            try {
                self::apply($entry);
            } catch (\Throwable $e) {
                SudemiLogger::warn(sprintf('Could not apply the change of sudemi order %s: %s', (string) $entry['order_id'], $e->getMessage()));
            }
        }
        if (isset($body['changes_cursor']) && is_string($body['changes_cursor']) && $body['changes_cursor'] !== '') {
            self::rememberCursor($body['changes_cursor']);
        }

        return !empty($body['changes_more']);
    }

    /**
     * What one entry asks of the local order, as a list of steps. Pure, so it can be tested
     * without PrestaShop.
     *
     * @param array      $entry  one changed_orders entry (or a row of GET /orders)
     * @param array|null $local  null when there is no local order; otherwise
     *                           ['revision' => int|null, 'state' => prep|paid|shipped|delivered|cancelled|refunded,
     *                            'tracking' => string, 'refunded' => int]
     *
     * @return string[] import, pay, release, cancel, ship, deliver, refunded, tracking, refund_note, remember
     */
    public static function plan(array $entry, $local)
    {
        $status = isset($entry['status']) ? (string) $entry['status'] : '';
        $revision = isset($entry['revision']) && is_numeric($entry['revision']) ? (int) $entry['revision'] : null;
        if ($revision === null) {
            return [];
        }
        $steps = [];
        if ($local === null) {
            if (!in_array($status, self::BOOKED, true)) {
                return [];
            }
            $steps[] = 'import';
            $local = ['revision' => null, 'state' => 'prep', 'tracking' => '', 'refunded' => 0];
        } elseif ($local['revision'] !== null && $revision <= (int) $local['revision']) {
            return [];
        }
        $state = (string) $local['state'];
        $closed = in_array($state, ['cancelled', 'refunded'], true);

        if ($state === 'prep' && in_array($status, ['PAID', 'SHIPPED', 'DELIVERED', 'REFUNDED'], true)) {
            $steps[] = 'pay';
            $state = 'paid';
        }
        if ($state === 'prep' && $status === 'DECLINED') {
            $steps[] = 'release';
        }
        if ($status === 'CANCELLED' && !$closed && !in_array($state, ['shipped', 'delivered'], true)) {
            $steps[] = 'cancel';
        }
        if (in_array($status, ['SHIPPED', 'DELIVERED'], true) && in_array($state, ['paid'], true)) {
            $steps[] = 'ship';
        }
        if ($status === 'DELIVERED' && in_array($state, ['paid', 'shipped'], true)) {
            $steps[] = 'deliver';
        }
        if ($status === 'REFUNDED' && !$closed) {
            $steps[] = 'refunded';
        }
        $code = isset($entry['tracking']['code']) ? trim((string) $entry['tracking']['code']) : '';
        if ($code !== '' && $code !== (string) $local['tracking']) {
            $steps[] = 'tracking';
        }
        $refunded = isset($entry['refunded_minor']) && is_numeric($entry['refunded_minor']) ? (int) $entry['refunded_minor'] : 0;
        if ($refunded > (int) $local['refunded']) {
            $steps[] = 'refund_note';
        }
        $steps[] = 'remember';

        return $steps;
    }

    /** Applies one entry to the local order (see plan()). */
    public static function apply(array $entry)
    {
        $sudemiId = strtolower((string) $entry['order_id']);
        if (!SudemiRequests::isUuid($sudemiId)) {
            return;
        }
        $order = SudemiOrders::localOrder($sudemiId);
        $steps = self::plan($entry, $order ? self::localFacts($order) : null);
        if (empty($steps)) {
            return;
        }
        if ($steps[0] === 'import') {
            $detail = SudemiManage::order($sudemiId);
            if (!$detail['ok']) {
                return;
            }
            $placed = isset($detail['order']['placed_at']) ? strtotime((string) $detail['order']['placed_at']) : false;
            if ($placed === false || $placed < self::since() - 3600) {
                // Placed before this module kept orders in step: left to the merchant, never
                // booked (and stock taken) behind their back.
                return;
            }
            $order = SudemiOrders::importFromSudemi($sudemiId, $detail['order']);
            if ($order === null) {
                return;
            }
        }
        $status = (string) $entry['status'];
        foreach ($steps as $step) {
            switch ($step) {
                case 'pay':
                    SudemiOrders::handleSettled([['order_id' => $sudemiId, 'status' => 'PAID']]);
                    break;
                case 'release':
                    SudemiOrders::handleSettled([['order_id' => $sudemiId, 'status' => 'DECLINED',
                        'declined_reason' => 'released on sudemi']]);
                    break;
                case 'cancel':
                    SudemiOrders::setState($order, (int) Configuration::get('PS_OS_CANCELED'));
                    SudemiOrders::privateMessage($order, 'Cancelled on sudemi; any payment was refunded to the buyer in full. Do not ship this order.');
                    break;
                case 'ship':
                    SudemiOrders::setState($order, (int) Configuration::get('PS_OS_SHIPPING'));
                    break;
                case 'deliver':
                    SudemiOrders::setState($order, (int) Configuration::get('PS_OS_DELIVERED'));
                    break;
                case 'refunded':
                    SudemiOrders::setState($order, (int) Configuration::get('PS_OS_REFUND'));
                    break;
                case 'tracking':
                    self::setTracking($order, isset($entry['tracking']['carrier']) ? (string) $entry['tracking']['carrier'] : '',
                        (string) $entry['tracking']['code']);
                    break;
                case 'refund_note':
                    $currency = SudemiOrders::currencyOf($order);
                    SudemiOrders::privateMessage($order, sprintf('Refunded on sudemi: %s in total of %s. The buyer has been paid back by sudemi.',
                        SudemiCurrency::format((int) $entry['refunded_minor'], $currency),
                        SudemiCurrency::format((int) SudemiCurrency::toMinor($order->total_paid_tax_incl, $currency), $currency)));
                    break;
            }
        }
        self::remember($order, $entry);
        if (count($steps) > 1) {
            SudemiLogger::info(sprintf('sudemi order %s is %s on sudemi; order %s updated.', $sudemiId, $status, $order->reference));
        }
    }

    /** What the module knows of a local order, in the shape plan() reads. */
    public static function localFacts($order)
    {
        $row = Db::getInstance()->getRow('SELECT revision, refunded_minor FROM `' . _DB_PREFIX_ . 'sudemi_order`
            WHERE id_order = ' . (int) $order->id);
        $current = (int) $order->getCurrentState();
        $is = function ($key) use ($current) {
            return $current === (int) Configuration::get($key);
        };
        if ($is('PS_OS_PREPARATION')) {
            $state = 'prep';
        } elseif ($is('PS_OS_CANCELED') || $is('PS_OS_ERROR')) {
            $state = 'cancelled';
        } elseif ($is('PS_OS_REFUND')) {
            $state = 'refunded';
        } elseif ($is('PS_OS_DELIVERED')) {
            $state = 'delivered';
        } elseif ($order->hasBeenShipped()) {
            $state = 'shipped';
        } else {
            $state = 'paid';
        }

        return [
            'revision' => is_array($row) && $row['revision'] !== null ? (int) $row['revision'] : null,
            'state' => $state,
            'tracking' => self::trackingOf($order),
            'refunded' => is_array($row) && $row['refunded_minor'] !== null ? (int) $row['refunded_minor'] : 0,
        ];
    }

    /** The tracking number on the order's shipment line ('' when none). */
    public static function trackingOf($order)
    {
        $id = (int) $order->getIdOrderCarrier();

        return $id > 0 ? (string) Db::getInstance()->getValue('SELECT tracking_number FROM `' . _DB_PREFIX_ . 'order_carrier`
            WHERE id_order_carrier = ' . $id) : '';
    }

    /**
     * Writes sudemi's tracking number on the order's shipment line, where the order page shows
     * it. Written straight to the row: OrderCarrier's own tracking update sends the buyer an
     * e-mail, and sudemi has already told the buyer.
     */
    public static function setTracking($order, $carrier, $code)
    {
        $id = (int) $order->getIdOrderCarrier();
        if ($id > 0) {
            Db::getInstance()->update('order_carrier', ['tracking_number' => pSQL(Tools::substr($code, 0, 64))], 'id_order_carrier = ' . $id);
        } else {
            Db::getInstance()->insert('order_carrier', [
                'id_order' => (int) $order->id,
                'id_carrier' => (int) $order->id_carrier,
                'tracking_number' => pSQL(Tools::substr($code, 0, 64)),
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }
        Db::getInstance()->update('orders', ['shipping_number' => pSQL(Tools::substr($code, 0, 64))], 'id_order = ' . (int) $order->id);
        SudemiOrders::privateMessage($order, 'Tracking from sudemi: ' . trim($carrier . ' ' . $code));
    }

    /** Keeps what was applied: the revision, the status on sudemi, the refunded amount, the tracking. */
    private static function remember($order, array $entry)
    {
        $code = isset($entry['tracking']['code']) ? (string) $entry['tracking']['code'] : '';
        $carrier = isset($entry['tracking']['carrier']) ? (string) $entry['tracking']['carrier'] : '';
        Db::getInstance()->update('sudemi_order', [
            'revision' => (int) $entry['revision'],
            'sudemi_status' => pSQL(Tools::substr((string) $entry['status'], 0, 20)),
            'refunded_minor' => isset($entry['refunded_minor']) ? (int) $entry['refunded_minor'] : 0,
            'tracking' => pSQL(Tools::substr(trim($carrier . ' ' . $code), 0, 255)),
        ], 'id_order = ' . (int) $order->id);
    }

    /**
     * Applies what sudemi answers for one order now (after an action from the back office), so
     * the local order shows sudemi's answer at once instead of on the next heartbeat.
     */
    public static function refresh($sudemiId)
    {
        $detail = SudemiManage::order($sudemiId);
        if (!$detail['ok']) {
            return null;
        }
        self::apply(self::entryOf($detail['order']));

        return $detail['order'];
    }

    /** A change entry from an order page or a list row of sudemi's. Pure. */
    public static function entryOf(array $order)
    {
        $refunded = isset($order['refunded_minor']) ? $order['refunded_minor']
            : (isset($order['money']['refunded_minor']) ? $order['money']['refunded_minor'] : 0);

        return [
            'order_id' => isset($order['order_id']) ? (string) $order['order_id'] : '',
            'status' => isset($order['status']) ? (string) $order['status'] : '',
            'revision' => isset($order['revision']) ? $order['revision'] : null,
            'refunded_minor' => (int) $refunded,
            'tracking' => isset($order['tracking']) && is_array($order['tracking']) ? $order['tracking'] : null,
        ];
    }

    /**
     * The daily safety net: this shop's orders placed since yesterday, applied like feed entries
     * (an entry with a revision already applied changes nothing).
     */
    public static function daily($force = false)
    {
        $last = (int) Configuration::get(self::CONF_DAILY);
        if (!$force && time() - $last < self::DAILY_SECONDS) {
            return false;
        }
        $filters = SudemiManage::filters(['from' => gmdate('Y-m-d', time() - 86400), 'sort' => 'placed', 'dir' => 'asc']);
        for ($page = 0; $page < self::DAILY_PAGES; ++$page) {
            $body = SudemiManage::listOrders($filters, 100);
            if (!is_array($body) || !isset($body['orders']) || !is_array($body['orders'])) {
                return false;
            }
            foreach ($body['orders'] as $row) {
                if (is_array($row) && !empty($row['order_id']) && isset($row['revision'])) {
                    try {
                        self::apply(self::entryOf($row));
                    } catch (\Throwable $e) {
                        SudemiLogger::warn(sprintf('Could not apply sudemi order %s: %s', (string) $row['order_id'], $e->getMessage()));
                    }
                }
            }
            if (empty($body['next_offset'])) {
                break;
            }
            $filters['offset'] = (int) $body['next_offset'];
        }
        Configuration::updateValue(self::CONF_DAILY, time());

        return true;
    }
}
