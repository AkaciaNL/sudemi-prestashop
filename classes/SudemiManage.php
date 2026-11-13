<?php

/**
 * The shop's orders, shipping and payouts as sudemi holds them (protocol 4d).
 *
 * sudemi is the source of truth: the back-office pages read from it, every action is sent to it,
 * and only sudemi's answer is reflected in the PrestaShop order (SudemiChanges::apply). Nothing
 * here decides on its own what an order's state is.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiManage
{
    public const STATUSES = ['AWAITING_SELLER', 'ACCEPTED', 'PAID', 'DECLINED', 'SHIPPED', 'DELIVERED', 'CANCELLED', 'REFUNDED'];
    public const SORTS = ['placed', 'total', 'status'];
    public const LANGUAGES = ['en', 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he'];
    public const BULK_ACTIONS = ['accept', 'reject', 'shipped', 'delivered'];
    public const ORDER_ACTIONS = ['accept', 'reject', 'shipped', 'update_tracking', 'delivered', 'refund', 'cancel', 'request_accept', 'request_reject'];
    public const PAGE_SIZE = 25;
    public const BULK_MAX = 100;
    public const MAX_TEXT = 500;

    /**
     * The table filters, cleaned: only known values pass, so nothing a visitor types reaches the
     * query string unchecked. Pure.
     *
     * @return array{status:string,from:string,to:string,q:string,sort:string,dir:string,offset:int}
     */
    public static function filters(array $input)
    {
        $status = isset($input['status']) ? strtoupper(trim((string) $input['status'])) : '';
        $sort = isset($input['sort']) ? strtolower(trim((string) $input['sort'])) : '';
        $dir = isset($input['dir']) ? strtolower(trim((string) $input['dir'])) : '';
        $q = isset($input['q']) ? trim(preg_replace('/\s+/u', ' ', (string) $input['q'])) : '';

        return [
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'from' => self::date(isset($input['from']) ? $input['from'] : ''),
            'to' => self::date(isset($input['to']) ? $input['to'] : ''),
            'q' => function_exists('mb_substr') ? mb_substr($q, 0, 100) : substr($q, 0, 100),
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'placed',
            'dir' => $dir === 'asc' ? 'asc' : 'desc',
            'offset' => isset($input['offset']) && is_numeric($input['offset']) ? max(0, (int) $input['offset']) : 0,
        ];
    }

    /** A YYYY-MM-DD date that exists, or ''. Pure. */
    public static function date($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return $value;
    }

    /**
     * The query string of the portal's order table for these filters. Always carries offset, so
     * sudemi answers with the table shape (total, next_offset) even without other filters. Pure.
     */
    public static function query(array $filters, $limit = self::PAGE_SIZE, array $extra = [])
    {
        $params = [];
        foreach (['status', 'from', 'to', 'q', 'sort', 'dir'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $params[$key] = (string) $filters[$key];
            }
        }
        if ($limit !== null) {
            $params['limit'] = max(1, min(100, (int) $limit));
            $params['offset'] = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        }

        return http_build_query($params + $extra, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array|null the answer body, or null when sudemi could not be read */
    public static function listOrders(array $filters, $limit = self::PAGE_SIZE)
    {
        return self::body(SudemiClient::sendSigned('GET', '/integration/v1/orders?' . self::query($filters, $limit)));
    }

    /**
     * One order as the portal's order page shows it.
     *
     * @return array{ok:bool,order?:array,error?:string}
     */
    public static function order($sudemiId)
    {
        if (!SudemiRequests::isUuid($sudemiId)) {
            return ['ok' => false, 'error' => 'no_such_order'];
        }
        $response = SudemiClient::sendSigned('GET', '/integration/v1/orders/' . rawurlencode(strtolower($sudemiId)));
        if (is_array($response) && (int) $response['status'] === 200 && is_array($response['body'])) {
            return ['ok' => true, 'order' => $response['body']];
        }

        return ['ok' => false, 'error' => self::error($response)];
    }

    /**
     * The portal's CSV export of this shop's orders, as sudemi wrote it.
     *
     * @return array{ok:bool,csv?:string,filename?:string,total?:int,truncated?:bool,error?:string}
     */
    public static function export(array $filters, $lang, $decimalComma)
    {
        $lang = strtolower((string) $lang);
        $extra = ['lang' => in_array($lang, self::LANGUAGES, true) ? $lang : 'en'];
        if ($decimalComma) {
            $extra['decimal'] = 'comma';
        }
        $response = SudemiClient::getRaw('/integration/v1/orders/export.csv?' . self::query($filters, null, $extra));
        if (!is_array($response) || (int) $response['status'] !== 200) {
            $body = is_array($response) && isset($response['raw']) ? json_decode($response['raw'], true) : null;

            return ['ok' => false, 'error' => is_array($body) && isset($body['error']) ? (string) $body['error']
                : (is_array($response) ? 'http_' . (int) $response['status'] : 'unreachable')];
        }
        $headers = isset($response['headers']) ? $response['headers'] : [];
        $filename = 'sudemi-orders-' . gmdate('Y-m-d') . '.csv';
        if (isset($headers['content-disposition']) && preg_match('/filename="([A-Za-z0-9._-]+)"/', $headers['content-disposition'], $m)) {
            $filename = $m[1];
        }

        return [
            'ok' => true,
            'csv' => (string) $response['raw'],
            'filename' => $filename,
            'total' => isset($headers['x-total-count']) ? (int) $headers['x-total-count'] : 0,
            'truncated' => isset($headers['x-export-truncated']) && $headers['x-export-truncated'] === 'true',
        ];
    }

    /**
     * Several orders at once, as the portal's bulk action.
     *
     * @param array $items [orderId => ['carrier' => ..., 'trackingCode' => ...]] (shipped) or [orderId => []]
     *
     * @return array{ok:bool,results?:array,done?:int,refused?:int,error?:string}
     */
    public static function bulk($action, array $items, $reason = '')
    {
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            return ['ok' => false, 'error' => 'invalid_request'];
        }
        $list = [];
        foreach ($items as $orderId => $extra) {
            if (!SudemiRequests::isUuid((string) $orderId)) {
                continue;
            }
            $item = ['orderId' => strtolower((string) $orderId)];
            if ($action === 'shipped') {
                $item['carrier'] = self::text(isset($extra['carrier']) ? $extra['carrier'] : '', 100);
                $item['trackingCode'] = self::text(isset($extra['trackingCode']) ? $extra['trackingCode'] : '', 100);
            }
            $list[] = $item;
        }
        if (empty($list) || count($list) > self::BULK_MAX) {
            return ['ok' => false, 'error' => 'invalid_request'];
        }
        $body = ['action' => $action, 'items' => $list];
        if ($action === 'reject') {
            $reason = self::text($reason, self::MAX_TEXT);
            if ($reason === '') {
                return ['ok' => false, 'error' => 'reason_required'];
            }
            $body['reason'] = $reason;
        }
        $response = SudemiClient::sendSigned('POST', '/integration/v1/orders/bulk', $body);
        if (!is_array($response) || (int) $response['status'] !== 200 || !isset($response['body']['results'])) {
            return ['ok' => false, 'error' => self::error($response)];
        }

        return [
            'ok' => true,
            'results' => is_array($response['body']['results']) ? $response['body']['results'] : [],
            'done' => isset($response['body']['done']) ? (int) $response['body']['done'] : 0,
            'refused' => isset($response['body']['refused']) ? (int) $response['body']['refused'] : 0,
        ];
    }

    /**
     * One action on one order, each the existing /orders/{id}/... call.
     *
     * @param array $params reason, carrier, trackingCode, amountMinor, currency, idempotency
     *
     * @return array{ok:bool,status?:int,body?:array,error?:string,message?:string}
     */
    public static function action($sudemiId, $action, array $params = [])
    {
        if (!SudemiRequests::isUuid($sudemiId) || !in_array($action, self::ORDER_ACTIONS, true)) {
            return ['ok' => false, 'error' => 'invalid_request'];
        }
        $reason = self::text(isset($params['reason']) ? $params['reason'] : '', self::MAX_TEXT);
        $body = [];
        $headers = [];
        $path = str_replace('_', '/', $action);
        switch ($action) {
            case 'reject':
            case 'request_reject':
                if ($reason === '') {
                    return ['ok' => false, 'error' => 'reason_required'];
                }
                $body['reason'] = $reason;
                break;
            case 'update_tracking':
                // POST /orders/{id}/tracking (protocol 4d): a blank carrier keeps sudemi's.
                $path = 'tracking';
                // fall through
                // no break
            case 'shipped':
                $body['carrier'] = self::text(isset($params['carrier']) ? $params['carrier'] : '', 100);
                $body['trackingCode'] = self::text(isset($params['trackingCode']) ? $params['trackingCode'] : '', 100);
                foreach (['carrier', 'trackingCode'] as $key) {
                    if ($body[$key] === '') {
                        $body[$key] = null;
                    }
                }
                break;
            case 'refund':
            case 'cancel':
                if ($reason === '') {
                    return ['ok' => false, 'error' => 'reason_required'];
                }
                $body['reason'] = $reason;
                if ($action === 'refund') {
                    $amount = isset($params['amountMinor']) ? (int) $params['amountMinor'] : 0;
                    if ($amount <= 0) {
                        return ['ok' => false, 'error' => 'invalid_amount'];
                    }
                    $body['amountMinor'] = $amount;
                    if (!empty($params['currency'])) {
                        $body['currency'] = (string) $params['currency'];
                    }
                }
                // The same key for a repeated form post: sudemi answers it with the first
                // answer and moves no money twice.
                $key = isset($params['idempotency']) ? preg_replace('/[^A-Za-z0-9-]/', '', (string) $params['idempotency']) : '';
                if ($key !== '') {
                    $headers['Idempotency-Key'] = 'ps-' . Tools::substr($key, 0, 64);
                }
                break;
        }
        $response = SudemiClient::sendSigned('POST', '/integration/v1/orders/' . rawurlencode(strtolower($sudemiId)) . '/' . $path, $body, $headers);
        if (!is_array($response)) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $status = (int) $response['status'];
        $answer = is_array($response['body']) ? $response['body'] : [];
        if ($status === 200 && (!isset($answer['status']) || $answer['status'] !== 'already_resolved')) {
            return ['ok' => true, 'status' => $status, 'body' => $answer];
        }

        return [
            'ok' => false,
            'status' => $status,
            'body' => $answer,
            'error' => $status === 200 ? 'already_resolved' : self::error($response),
            'message' => isset($answer['message']) ? (string) $answer['message'] : '',
        ];
    }

    /** @return array{ok:bool,shipping?:array,error?:string,message?:string} */
    public static function shipping()
    {
        $response = SudemiClient::sendSigned('GET', '/integration/v1/shipping');
        if (is_array($response) && (int) $response['status'] === 200) {
            return ['ok' => true, 'shipping' => $response['body']];
        }

        return ['ok' => false, 'error' => self::error($response)];
    }

    /**
     * Replaces this shop's shipping settings on sudemi.
     *
     * @param array $countries [['country' => 'DE', 'enabled' => bool, 'priceMinor' => int|null], ...]
     *
     * @return array{ok:bool,shipping?:array,error?:string,message?:string}
     */
    public static function saveShipping($mode, $currency, array $countries)
    {
        $body = ['mode' => $mode === 'custom' ? 'custom' : 'shop'];
        if ($currency !== '') {
            $body['currency'] = (string) $currency;
        }
        if ($body['mode'] === 'custom') {
            $body['countries'] = array_values($countries);
        }
        $response = SudemiClient::sendSigned('POST', '/integration/v1/shipping', $body);
        if (is_array($response) && (int) $response['status'] === 200) {
            return ['ok' => true, 'shipping' => $response['body']];
        }

        return [
            'ok' => false,
            'error' => self::error($response),
            'message' => is_array($response) && isset($response['body']['message']) ? (string) $response['body']['message'] : '',
        ];
    }

    /** @return array|null */
    public static function payouts()
    {
        return self::body(SudemiClient::sendSigned('GET', '/integration/v1/payouts'));
    }

    /**
     * A price typed in the back office ("4,95", "4.95", "5") in minor units of the currency, or
     * null when the field is empty, or false when it is not a price. Pure.
     */
    public static function priceMinor($typed, $currency)
    {
        $typed = str_replace([' ', "\xC2\xA0"], '', trim((string) $typed));
        if ($typed === '') {
            return null;
        }
        $typed = str_replace(',', '.', $typed);
        if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', $typed)) {
            return false;
        }

        return SudemiCurrency::toMinor($typed, $currency);
    }

    /** Plain one-line-or-more text of at most $max characters, trimmed. Pure. */
    public static function text($value, $max)
    {
        $value = trim(str_replace("\0", '', (string) $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private static function body($response)
    {
        if (!is_array($response) || (int) $response['status'] !== 200 || !is_array($response['body'])) {
            return null;
        }

        return $response['body'];
    }

    /** The error code of a failed call ('unreachable' when there was no answer). */
    public static function error($response)
    {
        if (!is_array($response)) {
            return 'unreachable';
        }
        if (SudemiClient::siteMismatch($response) !== null) {
            return 'site_mismatch';
        }
        if (isset($response['body']['error']) && is_string($response['body']['error'])) {
            return $response['body']['error'];
        }

        return 'http_' . (int) $response['status'];
    }
}
