<?php
/**
 * Return requests from buyers, and this shop's answer to them.
 *
 * After delivery a buyer may ask to return an order (docs: the cancellation and return rules).
 * The shop has 7 days to accept, or to reject with a reason the buyer is shown; with no answer
 * the request goes to sudemi, which then decides. Accepting moves no money: the shop refunds
 * once the item is back, through the module's normal refund path (the Refunded status or a
 * credit slip), and that refund closes the request on sudemi.
 *
 * sudemi sends the full list of open requests on every heartbeat, so the stored copy is simply
 * replaced each beat: nothing has to be tracked, and a request answered elsewhere (the sudemi
 * portal, or by sudemi after the deadline) disappears here on the next beat.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiRequests
{
    const CONF_OPEN = 'SUDEMI_OPEN_REQUESTS';

    /** sudemi answers at most 200; more than that would not fit a Configuration value anyway. */
    const MAX_STORED = 200;

    /** Longest buyer reason kept, and longest rejection reason sent. */
    const MAX_REASON = 1000;

    const OK = 'ok';
    const ALREADY = 'already_resolved';
    const NOT_FOUND = 'not_found';
    const INVALID = 'invalid_request';
    const REASON_REQUIRED = 'reason_required';
    const UNREACHABLE = 'unreachable';
    const SITE = 'site_mismatch';
    const FAILED = 'failed';

    /**
     * Replaces the stored list with what the heartbeat carried. Anything that is not a well-formed
     * entry is dropped: these values are printed on admin pages and used in request paths.
     *
     * @param mixed $open the heartbeat's open_requests (absent = none)
     */
    public static function store($open)
    {
        $kept = [];
        foreach (is_array($open) ? $open : [] as $entry) {
            $clean = self::clean($entry);
            if ($clean !== null && !isset($kept[$clean['order_id']])) {
                $kept[$clean['order_id']] = $clean;
            }
            if (count($kept) >= self::MAX_STORED) {
                break;
            }
        }
        if (!$kept) {
            Configuration::deleteByName(self::CONF_OPEN);

            return;
        }
        // No <, >, &, quotes in the stored text: Configuration strips tags and encodes entities in a
        // plain value, which would change what the buyer wrote. json_decode reads it back as is.
        Configuration::updateValue(self::CONF_OPEN, json_encode(array_values($kept), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }

    /** @return array[] the open requests, soonest answer-by date first */
    public static function all()
    {
        $raw = Configuration::get(self::CONF_OPEN);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            $clean = self::clean($entry);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp((string) $a['respond_by'], (string) $b['respond_by']);
        });

        return $out;
    }

    /** The open request for one sudemi order, or null. */
    public static function forOrder($sudemiId)
    {
        foreach (self::all() as $entry) {
            if ($entry['order_id'] === strtolower((string) $sudemiId)) {
                return $entry;
            }
        }

        return null;
    }

    public static function remove($sudemiId)
    {
        $left = [];
        foreach (self::all() as $entry) {
            if ($entry['order_id'] !== strtolower((string) $sudemiId)) {
                $left[] = $entry;
            }
        }
        self::store($left);
    }

    public static function forget()
    {
        Configuration::deleteByName(self::CONF_OPEN);
    }

    /**
     * Sends the shop's answer to sudemi.
     *
     * One signed call without waiting on a 429: a person is waiting on the page. The entry is
     * removed from the stored list once sudemi has no open request for the order any more (done
     * now, done before, or none at all), so the page stops offering buttons that cannot work.
     *
     * @param string $sudemiId the sudemi order id
     * @param bool   $accept
     * @param string|null $reason required (non-blank) to reject; optional note to accept
     *
     * @return array{ok:bool,outcome:string}
     */
    public static function answer($sudemiId, $accept, $reason = null)
    {
        $sudemiId = strtolower(trim((string) $sudemiId));
        if (!self::isUuid($sudemiId)) {
            return ['ok' => false, 'outcome' => self::NOT_FOUND];
        }
        $reason = self::text($reason, self::MAX_REASON);
        if (!$accept && $reason === '') {
            return ['ok' => false, 'outcome' => self::REASON_REQUIRED];
        }
        $body = $reason === '' ? [] : ['reason' => $reason];
        $response = SudemiClient::sendSigned(
            'POST',
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/request/' . ($accept ? 'accept' : 'reject'),
            $body
        );
        $outcome = self::outcome($response);
        if (in_array($outcome, [self::OK, self::ALREADY, self::NOT_FOUND], true)) {
            self::remove($sudemiId);
        }

        return ['ok' => $outcome === self::OK || $outcome === self::ALREADY, 'outcome' => $outcome];
    }

    /** sudemi's answer, as one of the outcome constants. */
    public static function outcome($response)
    {
        if (!is_array($response)) {
            return self::UNREACHABLE;
        }
        $status = (int) $response['status'];
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : [];
        if ($status === 200) {
            $said = isset($body['status']) ? (string) $body['status'] : '';
            if ($said === 'ok') {
                return self::OK;
            }

            return $said === 'already_resolved' ? self::ALREADY : self::FAILED;
        }
        if ($status === 404) {
            return self::NOT_FOUND;
        }
        if ($status === 400) {
            return self::INVALID;
        }
        if ($status === 409 && isset($body['error']) && $body['error'] === 'site_mismatch') {
            return self::SITE;
        }

        return self::FAILED;
    }

    public static function isUuid($value)
    {
        return is_string($value) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /** One heartbeat entry, checked and trimmed, or null. */
    private static function clean($entry)
    {
        if (!is_array($entry) || !isset($entry['order_id']) || !self::isUuid($entry['order_id'])) {
            return null;
        }
        $requestId = isset($entry['request_id']) && self::isUuid($entry['request_id']) ? strtolower($entry['request_id']) : '';
        $kind = isset($entry['kind']) && is_string($entry['kind']) && preg_match('/^[A-Z_]{1,32}$/', $entry['kind']) ? $entry['kind'] : 'RETURN';

        return [
            'request_id' => $requestId,
            'order_id' => strtolower($entry['order_id']),
            'kind' => $kind,
            'reason' => self::text(isset($entry['reason']) ? $entry['reason'] : null, self::MAX_REASON),
            'requested_at' => self::instant(isset($entry['requested_at']) ? $entry['requested_at'] : null),
            'respond_by' => self::instant(isset($entry['respond_by']) ? $entry['respond_by'] : null),
        ];
    }

    /** An ISO-8601 instant as sudemi sends it, or '' for anything else. */
    private static function instant($value)
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T[0-9:.]+(Z|[+-]\d{2}:?\d{2})$/', $value) ? $value : '';
    }

    /** Plain text: trimmed, line breaks normalised, cut to $max characters. */
    private static function text($value, $max)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}
