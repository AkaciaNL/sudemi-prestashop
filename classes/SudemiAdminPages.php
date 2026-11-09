<?php
/**
 * The sudemi Orders and Shipping pages of the back office (controllers/admin), and the sudemi
 * block on PrestaShop's own order page.
 *
 * Read from sudemi, actions sent to sudemi, sudemi's answer reflected in the local order
 * (SudemiChanges::refresh). Every action checks the employee's permission and a CSRF token
 * before anything is sent; everything printed is escaped by the templates.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiAdminPages
{
    const ORDERS = 'AdminSudemiOrders';
    const SHIPPING = 'AdminSudemiShipping';

    /** @var Module */
    private $module;
    /** @var Context */
    private $context;
    /** @var array<string,string> */
    private $t;
    /** @var array[] [type, text] shown above the page */
    private $messages = [];

    public function __construct($module, $context = null)
    {
        $this->module = $module;
        $this->context = $context ?: Context::getContext();
        $this->t = SudemiText::all($module);
    }

    // ------------------------------------------------------------------- access ----

    /** The CSRF token of the page's forms (distinct per page, tied to the employee's session). */
    public static function csrf($controller)
    {
        return Tools::getAdminTokenLite($controller) . '|sudemi-' . strtolower(substr($controller, 11));
    }

    /**
     * Permission, then CSRF token. Returns an error text, or '' when the action may go ahead.
     */
    public function guard($controller, array $post)
    {
        $employee = $this->context->employee;
        if (!$employee || !$employee->id || !Access::isGranted('ROLE_MOD_TAB_' . strtoupper($controller) . '_UPDATE', $employee->id_profile)) {
            return $this->t['err_permission'];
        }
        $token = isset($post['sudemi_token']) ? (string) $post['sudemi_token'] : '';
        if ($token === '' || !hash_equals(self::csrf($controller), $token)) {
            return $this->t['err_invalid'];
        }

        return '';
    }

    public function canView($controller)
    {
        $employee = $this->context->employee;

        return $employee && $employee->id && Access::isGranted('ROLE_MOD_TAB_' . strtoupper($controller) . '_READ', $employee->id_profile);
    }

    /** A back-office link with its token (as Sudemi::adminUrl). */
    public function url($controller, array $params = [])
    {
        try {
            $url = (string) $this->context->link->getAdminLink($controller, true, [], $params);
        } catch (\Throwable $e) {
            $url = '';
        }
        if ($url !== '') {
            return $url;
        }

        return 'index.php?' . http_build_query(['controller' => $controller] + $params + ['token' => Tools::getAdminTokenLite($controller)]);
    }

    private function say($type, $text)
    {
        $this->messages[] = ['type' => $type, 'text' => (string) $text];
    }

    public function messages()
    {
        return $this->messages;
    }

    private function lang()
    {
        $iso = isset($this->context->language->iso_code) ? strtolower((string) $this->context->language->iso_code) : 'en';

        return in_array($iso, SudemiManage::LANGUAGES, true) ? $iso : 'en';
    }

    // ----------------------------------------------------------------- the list ----

    /** The Orders page: the payouts panel, the filters, the table and its bulk actions. */
    public function ordersPage(array $get, array $post)
    {
        if (!SudemiClient::isConnected()) {
            return $this->render('sudemi-orders.tpl', ['connected' => false]);
        }
        if (!empty($post['sudemi_bulk'])) {
            $refused = $this->guard(self::ORDERS, $post);
            if ($refused !== '') {
                $this->say('error', $refused);
            } else {
                $this->bulk($post);
            }
        }
        $filters = SudemiManage::filters($get);
        $body = SudemiManage::listOrders($filters);
        $rows = [];
        if ($body === null) {
            $this->say('error', $this->t['unreachable']);
        } else {
            foreach ((isset($body['orders']) && is_array($body['orders']) ? $body['orders'] : []) as $order) {
                if (is_array($order) && isset($order['order_id']) && SudemiRequests::isUuid((string) $order['order_id'])) {
                    $rows[] = $this->row($order);
                }
            }
        }
        $total = is_array($body) && isset($body['total']) ? (int) $body['total'] : count($rows);
        $offset = $filters['offset'];
        $counts = is_array($body) && isset($body['counts']) && is_array($body['counts']) ? $body['counts'] : [];
        $statuses = [];
        foreach (SudemiManage::STATUSES as $status) {
            $statuses[$status] = $this->t['st_' . $status] . (isset($counts[$status]) ? ' (' . (int) $counts[$status] . ')' : '');
        }
        $base = $this->filterParams($filters);
        $nextOffset = is_array($body) && isset($body['next_offset']) && $body['next_offset'] !== null ? (int) $body['next_offset'] : null;

        return $this->render('sudemi-orders.tpl', [
            'connected' => true,
            'filters' => $filters,
            'statuses' => $statuses,
            'rows' => $rows,
            'shown' => $rows ? sprintf($this->t['shown'], $offset + 1, $offset + count($rows), $total) : '',
            'prev_url' => $offset > 0 ? $this->url(self::ORDERS, ['offset' => max(0, $offset - SudemiManage::PAGE_SIZE)] + $base) : '',
            'next_url' => $nextOffset !== null ? $this->url(self::ORDERS, ['offset' => $nextOffset] + $base) : '',
            'self_url' => $this->url(self::ORDERS, ['offset' => $offset] + $base),
            'reset_url' => $this->url(self::ORDERS),
            'form_controller' => self::ORDERS,
            'form_token' => Tools::getAdminTokenLite(self::ORDERS),
            'auto_off' => !SudemiOrders::autoConfirmEnabled() && !empty($counts['AWAITING_SELLER']),
            'payouts' => $this->payoutsPanel(),
            'languages' => SudemiManage::LANGUAGES,
            'lang' => $this->lang(),
            'carriers' => array_values(SudemiShipping::activeCarriers()),
        ]);
    }

    private function filterParams(array $filters)
    {
        $out = [];
        foreach (['status', 'from', 'to', 'q', 'sort', 'dir'] as $key) {
            if ($filters[$key] !== '' && !($key === 'sort' && $filters[$key] === 'placed') && !($key === 'dir' && $filters[$key] === 'desc')) {
                $out[$key] = $filters[$key];
            }
        }

        return $out;
    }

    /** One table row, ready to print. */
    private function row(array $order)
    {
        $id = strtolower((string) $order['order_id']);
        $currency = isset($order['currency_code']) ? (string) $order['currency_code'] : '';
        $items = [];
        foreach ((isset($order['lines']) && is_array($order['lines']) ? $order['lines'] : []) as $line) {
            $qty = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            $items[] = (isset($line['title']) ? (string) $line['title'] : '') . ($qty > 1 ? ' x ' . $qty : '');
        }
        $status = isset($order['status']) ? (string) $order['status'] : '';
        $local = SudemiOrders::localOrder($id);
        $payout = isset($order['payout']) && is_array($order['payout']) ? $order['payout'] : null;

        return [
            'id' => $id,
            'short' => Tools::substr($id, 0, 8),
            'url' => $this->url(self::ORDERS, ['sudemi_order' => $id]),
            'date' => isset($order['placed_at']) ? $this->time($order['placed_at']) : '',
            'items' => implode(', ', $items),
            'country' => isset($order['destination_country']) ? (string) $order['destination_country'] : '',
            'status' => $status,
            'status_label' => isset($this->t['st_' . $status]) ? $this->t['st_' . $status] : $status,
            'total' => isset($order['total_minor']) ? SudemiCurrency::format((int) $order['total_minor'], $currency) : '',
            'net' => isset($order['your_net_minor']) ? SudemiCurrency::format((int) $order['your_net_minor'], $currency) : '',
            'refunded' => !empty($order['refunded_minor']) ? SudemiCurrency::format((int) $order['refunded_minor'], $currency) : '',
            'tracking' => isset($order['tracking']) && is_array($order['tracking'])
                ? trim((isset($order['tracking']['carrier']) ? $order['tracking']['carrier'] : '') . ' ' . (isset($order['tracking']['code']) ? $order['tracking']['code'] : '')) : '',
            'payout' => $payout ? $this->payoutLabel($payout) : '',
            'respond_by' => !empty($order['respond_by']) ? $this->time($order['respond_by']) : '',
            'local_ref' => $local ? (string) $local->reference : '',
            'local_url' => $local ? $this->url('AdminOrders', ['id_order' => (int) $local->id, 'vieworder' => 1]) : '',
            'can_ship' => $status === 'PAID',
        ];
    }

    private function payoutLabel(array $payout)
    {
        $state = isset($payout['state']) ? (string) $payout['state'] : '';
        $label = isset($this->t['po_' . $state]) ? $this->t['po_' . $state] : $state;
        if (isset($payout['expected_minor']) && $payout['expected_minor'] !== null && !in_array($state, ['not_charged', 'nothing_due'], true)) {
            $label .= ' ' . SudemiCurrency::format((int) $payout['expected_minor'], isset($payout['currency_code']) ? (string) $payout['currency_code'] : '');
        }

        return $label;
    }

    /** The shop's payouts (GET /payouts), or null when sudemi could not be read. */
    public function payoutsPanel()
    {
        $body = SudemiManage::payouts();
        if ($body === null) {
            return null;
        }
        $account = isset($body['account']) && is_array($body['account']) ? $body['account'] : [];
        $state = isset($account['state']) ? (string) $account['state'] : '';
        $rows = [];
        foreach ((isset($body['currencies']) && is_array($body['currencies']) ? $body['currencies'] : []) as $c) {
            $code = isset($c['currency_code']) ? (string) $c['currency_code'] : '';
            $money = function ($key) use ($c, $code) {
                return SudemiCurrency::format(isset($c[$key]) ? (int) $c[$key] : 0, $code);
            };
            $rows[] = [
                'currency' => $code,
                'orders' => isset($c['orders']) ? (int) $c['orders'] : 0,
                'paid' => $money('paid_out_minor'),
                'outstanding' => $money('outstanding_minor'),
                'waiting' => $money('waiting_for_shipment_minor'),
                'scheduled' => $money('scheduled_minor'),
                'processing' => $money('processing_minor'),
                'held' => $money('held_minor'),
                'next' => !empty($c['next_due_at']) ? $this->time($c['next_due_at']) : '',
            ];
        }

        return [
            'account' => isset($this->t['acct_' . $state]) ? $this->t['acct_' . $state] : $state,
            'delay' => sprintf($this->t['payouts_delay'], isset($body['payout_delay_days']) ? (int) $body['payout_delay_days'] : 0),
            'rows' => $rows,
        ];
    }

    /**
     * The bulk form: accept (each booked here first), decline with one reason, shipped (one
     * carrier, a tracking number per order) or delivered.
     */
    private function bulk(array $post)
    {
        $action = isset($post['sudemi_action']) ? (string) $post['sudemi_action'] : '';
        $ids = [];
        foreach ((isset($post['sudemi_ids']) && is_array($post['sudemi_ids']) ? $post['sudemi_ids'] : []) as $id) {
            if (SudemiRequests::isUuid((string) $id)) {
                $ids[] = strtolower((string) $id);
            }
        }
        $ids = array_slice(array_values(array_unique($ids)), 0, SudemiManage::BULK_MAX);
        if (empty($ids) || !in_array($action, SudemiManage::BULK_ACTIONS, true)) {
            $this->say('warning', $this->t['bulk_none']);

            return;
        }
        $reason = isset($post['sudemi_reason']) ? (string) $post['sudemi_reason'] : '';
        if ($action === 'reject' && trim($reason) === '') {
            $this->say('error', $this->t['reason_required']);

            return;
        }
        $items = [];
        $localRefused = [];
        $prepared = [];
        foreach ($ids as $id) {
            if ($action === 'accept') {
                // Booked here before sudemi is told, as on the heartbeat: an acceptance that
                // lands must never leave a paid order this shop has no record of.
                $result = SudemiOrders::prepareLocal($id);
                if (isset($result['problem'])) {
                    $localRefused[$id] = sprintf($this->t['err_shortfall'], $result['problem']);
                    continue;
                }
                if (isset($result['error'])) {
                    $localRefused[$id] = $this->t['unreachable'];
                    continue;
                }
                $prepared[$id] = $result['order'];
            }
            $items[$id] = $action === 'shipped' ? [
                'carrier' => isset($post['sudemi_carrier']) ? (string) $post['sudemi_carrier'] : '',
                'trackingCode' => isset($post['sudemi_tracking'][$id]) ? (string) $post['sudemi_tracking'][$id] : '',
            ] : [];
        }
        $done = 0;
        $refused = count($localRefused);
        foreach ($localRefused as $id => $why) {
            $this->say('warning', sprintf($this->t['bulk_refused'], Tools::substr($id, 0, 8), $why));
        }
        if (!empty($items)) {
            $answer = SudemiManage::bulk($action, $items, $reason);
            if (!$answer['ok']) {
                $this->say('error', $answer['error'] === 'reason_required' ? $this->t['reason_required']
                    : ($answer['error'] === 'unreachable' ? $this->t['unreachable'] : sprintf($this->t['err_refused'], $answer['error'])));

                return;
            }
            foreach ($answer['results'] as $result) {
                $id = isset($result['order_id']) ? strtolower((string) $result['order_id']) : '';
                if (!isset($items[$id])) {
                    continue;
                }
                if (isset($result['result']) && $result['result'] === 'done') {
                    ++$done;
                    if ($action === 'accept' && isset($prepared[$id])) {
                        $waiting = isset($result['payment']) && $result['payment'] === 'waiting_for_other_shops';
                        SudemiOrders::applyAccept($id, $prepared[$id], $waiting ? 'waiting' : 'paid');
                    }
                } else {
                    ++$refused;
                    $this->say('warning', sprintf($this->t['bulk_refused'], Tools::substr($id, 0, 8),
                        isset($result['message']) ? (string) $result['message'] : (isset($result['error']) ? (string) $result['error'] : '')));
                }
                // sudemi's answer, reflected here (state, tracking).
                SudemiChanges::refresh($id);
            }
        }
        $this->say($refused ? 'warning' : 'success', sprintf($this->t['bulk_result'], $done, $refused));
    }

    // ---------------------------------------------------------------- one order ----

    /** One order as sudemi's order page shows it, with its actions. */
    public function orderPage($sudemiId, array $post)
    {
        $sudemiId = strtolower((string) $sudemiId);
        if (!SudemiClient::isConnected()) {
            return $this->render('sudemi-order.tpl', ['connected' => false]);
        }
        if (!empty($post['sudemi_do'])) {
            $refused = $this->guard(self::ORDERS, $post);
            if ($refused !== '') {
                $this->say('error', $refused);
            } else {
                $this->act($sudemiId, (string) $post['sudemi_do'], $post);
            }
        }
        $loaded = SudemiManage::order($sudemiId);
        if (!$loaded['ok']) {
            $this->say('error', $loaded['error'] === 'unreachable' ? $this->t['unreachable'] : $this->t['err_not_found']);

            return $this->render('sudemi-order.tpl', ['connected' => true, 'order' => null, 'back_url' => $this->url(self::ORDERS)]);
        }
        $o = $loaded['order'];
        // What sudemi says now is what the local order shows (an entry already applied changes nothing).
        SudemiChanges::apply(SudemiChanges::entryOf($o));

        return $this->render('sudemi-order.tpl', [
            'connected' => true,
            'order' => $this->detail($o),
            'back_url' => $this->url(self::ORDERS),
            'action_url' => $this->url(self::ORDERS, ['sudemi_order' => $sudemiId]),
            'idempotency' => bin2hex(random_bytes(12)),
            'carriers' => array_values(SudemiShipping::activeCarriers()),
        ]);
    }

    /** The order page's data, ready to print. */
    private function detail(array $o)
    {
        $id = strtolower((string) $o['order_id']);
        $money = isset($o['money']) && is_array($o['money']) ? $o['money'] : [];
        $currency = isset($money['currency_code']) ? (string) $money['currency_code'] : '';
        $fmt = function ($minor, $code = null) use ($currency) {
            return $minor === null ? '' : SudemiCurrency::format((int) $minor, $code === null ? $currency : $code);
        };
        $lines = [];
        foreach ((isset($o['lines']) && is_array($o['lines']) ? $o['lines'] : []) as $line) {
            $lines[] = [
                'title' => isset($line['title']) ? (string) $line['title'] : '',
                'external_id' => isset($line['external_id']) ? (string) $line['external_id'] : '',
                'quantity' => isset($line['quantity']) ? (int) $line['quantity'] : 0,
                'unit' => $fmt(isset($line['unit_price_minor']) ? $line['unit_price_minor'] : null),
                'total' => $fmt(isset($line['goods_minor']) ? $line['goods_minor'] : null),
                'exclusion' => !empty($line['withdrawal_exclusion']) ? sprintf($this->t['no_withdrawal'], (string) $line['withdrawal_exclusion']) : '',
            ];
        }
        $events = [];
        foreach ((isset($o['timeline']) && is_array($o['timeline']) ? $o['timeline'] : []) as $e) {
            $type = isset($e['type']) ? (string) $e['type'] : '';
            $parts = [];
            if ($type === 'REFUND' && isset($e['amount_minor'])) {
                $parts[] = $fmt($e['amount_minor']);
            }
            if (in_array($type, ['REQUEST', 'REQUEST_DECIDED'], true) && !empty($e['kind'])) {
                $parts[] = $e['kind'] === 'RETURN' ? $this->t['request_return'] : $this->t['request_cancellation'];
            }
            if (!empty($e['outcome'])) {
                $parts[] = (string) $e['outcome'];
            }
            if ($type === 'SHIPPED' && (!empty($e['carrier']) || !empty($e['tracking_code']))) {
                $parts[] = trim((isset($e['carrier']) ? $e['carrier'] : '') . ' ' . (isset($e['tracking_code']) ? $e['tracking_code'] : ''));
            }
            if (!empty($e['reason'])) {
                $parts[] = (string) $e['reason'];
            }
            if (!empty($e['by']) && isset($this->t['by_' . $e['by']])) {
                $parts[] = $this->t['by_' . $e['by']];
            }
            $events[] = [
                'at' => isset($e['at']) ? $this->time($e['at']) : '',
                'title' => isset($this->t['ev_' . $type]) ? $this->t['ev_' . $type] : $type,
                'detail' => implode(' / ', array_filter($parts, 'strlen')),
            ];
        }
        $payout = isset($o['payout']) && is_array($o['payout']) ? $o['payout'] : null;
        $payoutCode = $payout && isset($payout['currency_code']) ? (string) $payout['currency_code'] : $currency;
        $request = isset($o['customer_request']) && is_array($o['customer_request']) ? $o['customer_request'] : null;
        $status = isset($o['status']) ? (string) $o['status'] : '';
        $refundable = max(0, (isset($money['total_minor']) ? (int) $money['total_minor'] : 0) - (isset($money['refunded_minor']) ? (int) $money['refunded_minor'] : 0));
        $local = SudemiOrders::localOrder($id);
        $actions = isset($o['allowed_actions']) && is_array($o['allowed_actions']) ? array_values(array_intersect(SudemiManage::ORDER_ACTIONS, $o['allowed_actions'])) : [];

        return [
            'id' => $id,
            'title' => sprintf($this->t['detail_title'], Tools::substr($id, 0, 8)),
            'status' => $status,
            'status_label' => isset($this->t['st_' . $status]) ? $this->t['st_' . $status] : $status,
            'facts' => array_filter([
                $this->t['placed'] => isset($o['placed_at']) ? $this->time($o['placed_at']) : '',
                $this->t['destination'] => isset($o['destination_country']) ? (string) $o['destination_country'] : '',
                $this->t['customer'] => isset($o['customer_name']) ? (string) $o['customer_name'] : '',
                $this->t['respond_by'] => !empty($o['respond_by']) ? $this->time($o['respond_by']) : '',
                $this->t['declined_reason'] => isset($o['declined_reason']) ? (string) $o['declined_reason'] : '',
                $this->t['note'] => isset($o['note']) && is_string($o['note']) ? $o['note'] : '',
                $this->t['col_tracking'] => isset($o['tracking']) && is_array($o['tracking'])
                    ? trim((isset($o['tracking']['carrier']) ? $o['tracking']['carrier'] : '') . ' ' . (isset($o['tracking']['code']) ? $o['tracking']['code'] : '')) : '',
                $this->t['shipped_at'] => !empty($o['shipped_at']) ? $this->time($o['shipped_at']) : '',
                $this->t['delivered_at'] => !empty($o['delivered_at']) ? $this->time($o['delivered_at']) : '',
                $this->t['withdrawal'] => !empty($o['withdrawal_deadline']) ? $this->time($o['withdrawal_deadline']) : '',
            ], 'strlen'),
            'local_ref' => $local ? (string) $local->reference : '',
            'local_url' => $local ? $this->url('AdminOrders', ['id_order' => (int) $local->id, 'vieworder' => 1]) : '',
            'lines' => $lines,
            'money' => array_filter([
                $this->t['goods'] => $fmt(isset($money['goods_minor']) ? $money['goods_minor'] : null),
                $this->t['shipping'] => $fmt(isset($money['shipping_minor']) ? $money['shipping_minor'] : null),
                $this->t['total'] => $fmt(isset($money['total_minor']) ? $money['total_minor'] : null),
                $this->t['commission'] => $fmt(isset($money['commission_minor']) ? $money['commission_minor'] : null),
                $this->t['you_receive'] => $fmt(isset($money['your_net_minor']) ? $money['your_net_minor'] : null),
                $this->t['refunded'] => !empty($money['refunded_minor']) ? $fmt($money['refunded_minor']) : '',
            ], 'strlen'),
            'payout' => $payout ? array_filter([
                $this->t['payout_state'] => isset($payout['state'], $this->t['po_' . $payout['state']]) ? $this->t['po_' . $payout['state']] : (isset($payout['state']) ? (string) $payout['state'] : ''),
                $this->t['payout_expected'] => isset($payout['expected_minor']) ? $fmt($payout['expected_minor'], $payoutCode) : '',
                $this->t['payout_paid'] => isset($payout['paid_out_minor']) ? $fmt($payout['paid_out_minor'], $payoutCode) : '',
                $this->t['payout_due'] => !empty($payout['due_at']) ? $this->time($payout['due_at']) : '',
            ], 'strlen') : [],
            'payout_note' => $payout && !empty($payout['converted']) ? sprintf($this->t['payout_converted'], $payoutCode) : '',
            'request' => $request ? [
                'kind' => isset($request['kind']) && $request['kind'] === 'RETURN' ? $this->t['request_return'] : $this->t['request_cancellation'],
                'reason' => isset($request['reason']) ? (string) $request['reason'] : '',
                'requested' => !empty($request['requested_at']) ? $this->time($request['requested_at']) : '',
                'respond_by' => !empty($request['respond_by']) ? $this->time($request['respond_by']) : '',
            ] : null,
            'actions' => $actions,
            'can' => array_fill_keys($actions, true),
            'nothing_to_do' => !array_diff($actions, ['request_accept', 'request_reject']),
            'refundable' => $refundable > 0 ? $fmt($refundable) : '',
            'refund_help' => sprintf($this->t['refund_help'], $fmt($refundable)),
            'currency' => $currency,
            'events' => $events,
            'can_track' => $status === 'PAID',
            'tracking_carrier' => isset($o['tracking']['carrier']) ? (string) $o['tracking']['carrier'] : '',
            'tracking_code' => isset($o['tracking']['code']) ? (string) $o['tracking']['code'] : '',
        ];
    }

    /** One action on one order, then sudemi's answer reflected here. */
    private function act($sudemiId, $action, array $post)
    {
        if (!SudemiRequests::isUuid($sudemiId)) {
            $this->say('error', $this->t['err_not_found']);

            return;
        }
        $reason = isset($post['sudemi_reason']) ? (string) $post['sudemi_reason'] : '';
        switch ($action) {
            case 'accept':
                $result = SudemiOrders::acceptManually($sudemiId);
                $outcome = $result['outcome'];
                if ($outcome === 'refused') {
                    $this->say('error', sprintf($this->t['err_shortfall'], $result['problem']));
                } elseif ($outcome === 'paid') {
                    $this->say('success', $this->t['done_accept']);
                } elseif ($outcome === 'waiting') {
                    $this->say('success', $this->t['done_waiting']);
                } elseif ($outcome === 'declined' || $outcome === 'cancelled') {
                    $this->say('warning', $this->t['err_already_resolved']);
                } else {
                    $this->say('error', $this->t['unreachable']);
                }
                break;
            case 'reject':
                $outcome = SudemiOrders::declineManually($sudemiId, $reason);
                if ($outcome === 'reason_required') {
                    $this->say('error', $this->t['reason_required']);
                } elseif ($outcome === 'declined') {
                    $this->say('success', $this->t['done_reject']);
                } elseif ($outcome === 'retry') {
                    $this->say('error', $this->t['unreachable']);
                } else {
                    $this->say('warning', $this->t['err_already_resolved']);
                }
                break;
            case 'request_accept':
            case 'request_reject':
                $answer = SudemiRequests::answer($sudemiId, $action === 'request_accept', $reason);
                if ($answer['outcome'] === SudemiRequests::OK) {
                    $this->say('success', $this->t['done_request']);
                } elseif ($answer['outcome'] === SudemiRequests::REASON_REQUIRED) {
                    $this->say('error', $this->t['reason_required']);
                } elseif ($answer['outcome'] === SudemiRequests::UNREACHABLE) {
                    $this->say('error', $this->t['unreachable']);
                } else {
                    $this->say('warning', $this->t['err_already_resolved']);
                }
                break;
            case 'tracking':
            case 'update_tracking':
                // "Send tracking to sudemi" (PrestaShop's order page) and "Change tracking": the
                // tracking call for an order sudemi has as shipped, the shipped call (with the
                // tracking) for one it has as paid.
                $result = SudemiOrders::sendTracking($sudemiId, SudemiOrders::trackingBody(
                    isset($post['sudemi_carrier']) ? $post['sudemi_carrier'] : '',
                    isset($post['sudemi_tracking']) ? $post['sudemi_tracking'] : ''
                ));
                $outcome = $result['outcome'];
                if (in_array($outcome, ['updated', 'shipped'], true)) {
                    $this->say('success', $this->t['done_tracking']);
                } elseif ($outcome === 'unchanged') {
                    $this->say('info', $this->t['tracking_same']);
                } elseif ($outcome === 'final') {
                    $this->say('warning', $this->t['err_tracking_late']);
                } elseif ($outcome === 'required') {
                    $this->say('error', $this->t['tracking_required']);
                } elseif ($outcome === 'retry') {
                    $this->say('error', $this->t['unreachable']);
                } else {
                    $response = $result['response'];
                    $this->say('error', sprintf($this->t['err_refused'], is_array($response) && isset($response['body']['message'])
                        ? (string) $response['body']['message'] : SudemiManage::error($response)));
                }
                break;
            case 'shipped':
            case 'delivered':
            case 'refund':
            case 'cancel':
                $params = [
                    'reason' => $reason,
                    'carrier' => isset($post['sudemi_carrier']) ? $post['sudemi_carrier'] : '',
                    'trackingCode' => isset($post['sudemi_tracking']) ? $post['sudemi_tracking'] : '',
                    'idempotency' => isset($post['sudemi_idem']) ? $post['sudemi_idem'] : '',
                ];
                if ($action === 'refund') {
                    $order = SudemiManage::order($sudemiId);
                    $currency = $order['ok'] && isset($order['order']['money']['currency_code']) ? (string) $order['order']['money']['currency_code'] : '';
                    $amount = SudemiManage::priceMinor(isset($post['sudemi_amount']) ? $post['sudemi_amount'] : '', $currency);
                    if (!is_int($amount) || $amount <= 0) {
                        $this->say('error', $this->t['err_amount']);
                        break;
                    }
                    $params['amountMinor'] = $amount;
                    $params['currency'] = $currency;
                }
                if (in_array($action, ['refund', 'cancel'], true) && trim($reason) === '') {
                    $this->say('error', $this->t['reason_required']);
                    break;
                }
                $answer = SudemiManage::action($sudemiId, $action, $params);
                if ($answer['ok']) {
                    $this->say('success', $this->t['done_' . $action]);
                    if ($action === 'cancel') {
                        // Cancelled by this shop: the note on the PrestaShop order says so.
                        SudemiChanges::refresh($sudemiId, ['cancelled_by' => 'shop']);

                        return;
                    }
                } elseif ($answer['error'] === 'already_resolved') {
                    $this->say('warning', $this->t['err_already_resolved']);
                } elseif ($answer['error'] === 'unreachable') {
                    $this->say('error', $this->t['unreachable']);
                } elseif ($answer['error'] === 'reason_required') {
                    $this->say('error', $this->t['reason_required']);
                } else {
                    $this->say('error', sprintf($this->t['err_refused'], $answer['message'] !== '' ? $answer['message'] : $answer['error']));
                }
                break;
            default:
                $this->say('error', $this->t['err_invalid']);

                return;
        }
        SudemiChanges::refresh($sudemiId);
    }

    // ------------------------------------------------------------------- export ----

    /**
     * The CSV export, through sudemi's signed endpoint. The admin controller sends it as a file
     * (its own token check has passed by then).
     *
     * @return array{ok:bool,csv?:string,filename?:string,error?:string}
     */
    public function export(array $get)
    {
        if (!SudemiClient::isConnected()) {
            return ['ok' => false, 'error' => 'not_connected'];
        }
        $lang = isset($get['lang']) ? (string) $get['lang'] : $this->lang();

        return SudemiManage::export(SudemiManage::filters($get), $lang, !empty($get['decimal']));
    }

    // ----------------------------------------------------------------- shipping ----

    /** The Shipping page: settings on sudemi, the prices buyers pay now, the prefill from carriers. */
    public function shippingPage(array $get, array $post)
    {
        if (!SudemiClient::isConnected()) {
            return $this->render('sudemi-shipping.tpl', ['connected' => false]);
        }
        $saved = null;
        $typed = null;
        if (!empty($post['sudemi_ship_save'])) {
            $refused = $this->guard(self::SHIPPING, $post);
            if ($refused !== '') {
                $this->say('error', $refused);
            } else {
                list($saved, $typed) = $this->saveShipping($post);
            }
        }
        $answer = $saved !== null ? ['ok' => true, 'shipping' => $saved] : SudemiManage::shipping();
        if (!$answer['ok']) {
            $this->say('error', $this->t['unreachable']);

            return $this->render('sudemi-shipping.tpl', ['connected' => true, 'shipping' => null]);
        }
        $s = $answer['shipping'];
        $currency = isset($s['currency']) ? (string) $s['currency'] : SudemiSync::currency();
        $prefill = [];
        if (!empty($get['prefill'])) {
            $prefill = $this->carrierPrices();
            $this->say('info', $this->t['ship_prefilled']);
        }
        $effective = [];
        foreach ((isset($s['effective']) && is_array($s['effective']) ? $s['effective'] : []) as $e) {
            if (!empty($e['country'])) {
                $effective[(string) $e['country']] = $e;
            }
        }
        $rows = [];
        foreach ((isset($s['countries']) && is_array($s['countries']) ? $s['countries'] : []) as $c) {
            $iso = isset($c['country']) ? strtoupper((string) $c['country']) : '';
            if (!preg_match('/^[A-Z]{2}$/', $iso)) {
                continue;
            }
            $price = isset($c['price_minor']) && $c['price_minor'] !== null ? number_format(SudemiCurrency::fromMinor($c['price_minor'], $currency), SudemiCurrency::decimals($currency), '.', '') : '';
            $enabled = !empty($c['enabled']);
            if (isset($prefill[$iso])) {
                $price = number_format(SudemiCurrency::fromMinor($prefill[$iso], $currency), SudemiCurrency::decimals($currency), '.', '');
                $enabled = true;
            } elseif ($prefill) {
                $enabled = false;
            }
            if (is_array($typed) && isset($typed[$iso])) {
                $price = $typed[$iso]['price'];
                $enabled = $typed[$iso]['enabled'];
            }
            $e = isset($effective[$iso]) ? $effective[$iso] : null;
            $now = $this->t['ship_not_offered'];
            if ($e && !empty($e['offered']) && $e['min_minor'] !== null) {
                $now = SudemiCurrency::format((int) $e['min_minor'], $currency)
                    . ($e['max_minor'] !== null && (int) $e['max_minor'] !== (int) $e['min_minor'] ? ' - ' . SudemiCurrency::format((int) $e['max_minor'], $currency) : '')
                    . ' (' . ($e['source'] === 'sudemi_price' ? $this->t['ship_source_sudemi'] : $this->t['ship_source_shop']) . ')';
            }
            $shopMin = isset($c['shop_price_min_minor']) && $c['shop_price_min_minor'] !== null ? (int) $c['shop_price_min_minor'] : null;
            $shopMax = isset($c['shop_price_max_minor']) && $c['shop_price_max_minor'] !== null ? (int) $c['shop_price_max_minor'] : null;
            $rows[] = [
                'country' => $iso,
                'enabled' => $enabled,
                'price' => $price,
                'shop_price' => $shopMin === null ? '' : SudemiCurrency::format($shopMin, $currency)
                    . ($shopMax !== null && $shopMax !== $shopMin ? ' - ' . SudemiCurrency::format($shopMax, $currency) : ''),
                'effective' => $now,
                'offered' => $e && !empty($e['offered']),
            ];
        }
        $mode = isset($s['mode']) && $s['mode'] === 'custom' ? 'custom' : 'shop';
        if ($prefill) {
            $mode = 'custom';
        }

        return $this->render('sudemi-shipping.tpl', [
            'connected' => true,
            'shipping' => true,
            'mode' => is_array($typed) && isset($post['sudemi_mode']) ? ($post['sudemi_mode'] === 'custom' ? 'custom' : 'shop') : $mode,
            'currency' => $currency,
            'currency_note' => sprintf($this->t['ship_currency'], $currency),
            'rows' => $rows,
            'action_url' => $this->url(self::SHIPPING),
            'prefill_url' => $this->url(self::SHIPPING, ['prefill' => 1]),
        ]);
    }

    /**
     * Sends the form to sudemi. Returns [sudemi's settings or null, what was typed (kept on a
     * failure so nothing typed is lost) or null].
     */
    private function saveShipping(array $post)
    {
        $mode = isset($post['sudemi_mode']) && $post['sudemi_mode'] === 'custom' ? 'custom' : 'shop';
        $current = SudemiManage::shipping();
        $currency = $current['ok'] && !empty($current['shipping']['currency']) ? (string) $current['shipping']['currency'] : SudemiSync::currency();
        $countries = [];
        $typed = [];
        $prices = isset($post['sudemi_price']) && is_array($post['sudemi_price']) ? $post['sudemi_price'] : [];
        $enabled = isset($post['sudemi_enabled']) && is_array($post['sudemi_enabled']) ? $post['sudemi_enabled'] : [];
        foreach ($prices as $iso => $price) {
            $iso = strtoupper((string) $iso);
            if (!preg_match('/^[A-Z]{2}$/', $iso)) {
                continue;
            }
            $on = !empty($enabled[$iso]);
            $typed[$iso] = ['price' => SudemiManage::text($price, 20), 'enabled' => $on];
            $minor = SudemiManage::priceMinor($price, $currency);
            if ($minor === false) {
                $this->say('error', sprintf($this->t['ship_bad_price'], $iso . ' ' . SudemiManage::text($price, 20)));

                return [null, $typed];
            }
            $countries[] = ['country' => $iso, 'enabled' => $on, 'priceMinor' => $minor];
        }
        $answer = SudemiManage::saveShipping($mode, $currency, $countries);
        if (!$answer['ok']) {
            $this->say('error', $answer['error'] === 'unreachable' ? $this->t['unreachable']
                : sprintf($this->t['ship_refused'], $answer['message'] !== '' ? $answer['message'] : $answer['error']));

            return [null, $typed];
        }
        $this->say('success', $this->t['ship_saved']);

        return [$answer['shipping'], null];
    }

    /** Per country the cheapest price of this shop's own carriers for the first product, in minor units. */
    public function carrierPrices()
    {
        $id = (int) Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 AND is_virtual = 0 ORDER BY id_product');
        if (!$id) {
            return [];
        }
        $product = new Product($id, false, (int) Configuration::get('PS_LANG_DEFAULT'));
        $out = [];
        foreach (SudemiShipping::ratesFor($product, Product::getPriceStatic($id, true)) as $rate) {
            $out[$rate['country']] = (int) $rate['costMinor'];
        }

        return $out;
    }

    // ---------------------------------------------------------- order page block ----

    /**
     * The sudemi block on PrestaShop's order page: the state on sudemi as last applied (no call to
     * sudemi while the page loads), a link to the sudemi order page and "Send tracking to sudemi"
     * (while sudemi has the order as paid or shipped).
     *
     * @return array|null null for an order that did not come through sudemi
     */
    public function orderBlock(Order $order)
    {
        $sudemiId = SudemiOrders::sudemiIdOf($order);
        if ($sudemiId === null) {
            return null;
        }
        $row = Db::getInstance()->getRow('SELECT sudemi_status, tracking FROM `' . _DB_PREFIX_ . 'sudemi_order` WHERE id_order = ' . (int) $order->id);
        $status = is_array($row) && !empty($row['sudemi_status']) ? (string) $row['sudemi_status'] : '';
        $carrier = new Carrier((int) $order->id_carrier);

        return [
            'labels' => $this->t,
            'status' => $status === '' ? $this->t['block_unknown'] : (isset($this->t['st_' . $status]) ? $this->t['st_' . $status] : $status),
            'tracking' => is_array($row) ? (string) $row['tracking'] : '',
            'url' => $this->url(self::ORDERS, ['sudemi_order' => $sudemiId]),
            'action_url' => $this->url(self::ORDERS, ['sudemi_order' => $sudemiId]),
            'order_id' => $sudemiId,
            'carrier' => Validate::isLoadedObject($carrier) ? (string) $carrier->name : '',
            'tracking_code' => SudemiChanges::trackingOf($order) ?: (string) $order->getWsShippingNumber(),
            'token' => self::csrf(self::ORDERS),
            // Paid: sent as shipped with the tracking; shipped: the tracking is corrected.
            'can_track' => in_array($status, ['', 'PAID', 'SHIPPED'], true),
        ];
    }

    // ------------------------------------------------------------------ helpers ----

    /** An ISO-8601 time from sudemi, in the shop's time zone. */
    private function time($iso)
    {
        $time = is_string($iso) ? strtotime($iso) : false;

        return $time ? date('Y-m-d H:i', $time) : '';
    }

    private function render($template, array $vars)
    {
        $controller = $template === 'sudemi-shipping.tpl' ? self::SHIPPING : self::ORDERS;
        $this->context->smarty->assign([
            'sudemi' => $vars,
            'sudemi_t' => $this->t,
            'sudemi_messages' => $this->messages,
            'sudemi_token' => self::csrf($controller),
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'sudemi/views/templates/admin/' . $template);
    }
}
