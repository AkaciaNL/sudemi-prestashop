<?php

/**
 * What the module left out of the catalogue or could not read, reported to sudemi and to the shop
 * (owner 2026-11-19: "a product or a sync issue occurs, we need to report it to shop and to
 * sudemi as well").
 *
 * Every catalogue push (full sync and change push) carries the issues found while building it as
 * a top-level `issues` list: [{externalId, code, severity, field, message, details}]. sudemi's
 * answer (its `rejected` items and its own `issues`) is kept next to them, and the "Products on
 * sudemi" page shows both, in the employee's language.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiIssues
{
    public const TOO_MANY_REQUIRED_TEXTS = 'too_many_required_texts';
    public const TOO_MANY_OPTIONAL_TEXTS = 'too_many_optional_texts';
    public const REQUIRED_FILE_UPLOAD = 'required_file_upload';
    public const NOT_AVAILABLE_FOR_ORDER = 'not_available_for_order';
    public const MISSING_PRICE = 'missing_price';
    /**
     * Shop-wide, not about a product: waiting sudemi orders are in a currency this shop does not
     * have installed or switched on, so they cannot be booked (SudemiOrders::reportCurrencyProblems).
     * Sent on its own on every heartbeat while it holds, never kept on the catalogue issues list.
     */
    public const ORDER_CURRENCY_UNAVAILABLE = 'order_currency_unavailable';

    /** The codes the page has plain words for (SudemiText 'is_<code>'). */
    public const CODES = [self::TOO_MANY_REQUIRED_TEXTS, self::TOO_MANY_OPTIONAL_TEXTS, self::REQUIRED_FILE_UPLOAD,
        self::NOT_AVAILABLE_FOR_ORDER, self::MISSING_PRICE, 'rejected'];

    /** Where what was sent and sudemi's answers are kept (JSON). */
    public const CONF = 'SUDEMI_CATALOGUE_ISSUES';
    public const MAX_KEPT = 300;

    /** Found while building the current push, not sent yet. */
    private static $pending = [];

    public static function add($externalId, $code, $severity, $field, $message, array $details = [])
    {
        $issue = self::make($externalId, $code, $severity, $field, $message, $details);
        self::$pending[$issue['externalId'] . '|' . $issue['code'] . '|' . $issue['field']] = $issue;
    }

    /** One issue in the wire shape. Pure. */
    public static function make($externalId, $code, $severity, $field, $message, array $details = [])
    {
        return [
            'externalId' => (string) $externalId,
            'code' => (string) $code,
            'severity' => $severity === 'error' ? 'error' : 'warning',
            'field' => (string) $field,
            'message' => (string) $message,
            'details' => (object) $details,
        ];
    }

    /** The pending issues, emptied: they go with the push being sent. */
    public static function take()
    {
        $out = array_values(self::$pending);
        self::$pending = [];

        return $out;
    }

    /** The push body with its issues (only when there are any; sudemi ignores a field it does not know). Pure. */
    public static function attach(array $body, array $issues)
    {
        if (!empty($issues)) {
            $body['issues'] = array_values($issues);
        }

        return $body;
    }

    /**
     * The kept list after one push: the entries of the products in it replaced by what was sent
     * now and what sudemi answered. Pure.
     */
    public static function merge(array $kept, array $listings, array $issues, array $answer, $now)
    {
        $touched = [];
        foreach ($listings as $listing) {
            if (isset($listing['externalId'])) {
                $touched[(string) $listing['externalId']] = true;
            }
        }
        foreach ($issues as $issue) {
            $touched[(string) $issue['externalId']] = true;
        }
        $kept = array_values(array_filter($kept, function ($entry) use ($touched) {
            return is_array($entry) && isset($entry['externalId']) && !isset($touched[(string) $entry['externalId']]);
        }));
        foreach ($issues as $issue) {
            $kept[] = ['externalId' => (string) $issue['externalId'], 'source' => 'module', 'code' => (string) $issue['code'],
                'severity' => (string) $issue['severity'], 'field' => (string) $issue['field'], 'message' => (string) $issue['message'], 'at' => (int) $now];
        }
        foreach (['rejected', 'issues'] as $list) {
            foreach (isset($answer[$list]) && is_array($answer[$list]) ? $answer[$list] : [] as $item) {
                if (!is_array($item) || !isset($item['externalId'])) {
                    continue;
                }
                $kept[] = ['externalId' => (string) $item['externalId'], 'source' => 'sudemi',
                    'code' => isset($item['code']) ? (string) $item['code'] : ($list === 'rejected' ? 'rejected' : 'issue'),
                    'severity' => $list === 'rejected' ? 'error' : (isset($item['severity']) ? (string) $item['severity'] : 'warning'),
                    'field' => isset($item['field']) ? (string) $item['field'] : '',
                    'message' => isset($item['reason']) ? (string) $item['reason'] : (isset($item['message']) ? (string) $item['message'] : ''),
                    'at' => (int) $now];
            }
        }

        return array_slice($kept, -self::MAX_KEPT);
    }

    /** The most bytes kept in the configuration row (a TEXT column holds 64 KB). */
    public const MAX_BYTES = 50000;

    /**
     * The kept list as stored: JSON without < > & ' " (the configuration write strips tags),
     * invalid UTF-8 replaced, \u escapes (fits any database character set), the oldest entries
     * dropped until it fits MAX_BYTES. Null when it cannot be encoded. Pure.
     */
    public static function encode(array $kept, $maxBytes = self::MAX_BYTES)
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | SudemiClient::jsonFlags();
        $kept = array_values($kept);
        while (true) {
            $json = json_encode($kept, $flags);
            if ($json === false) {
                return null;
            }
            if (strlen($json) <= $maxBytes || empty($kept)) {
                return $json;
            }
            // Drop the oldest tenth (at least one) and try again.
            $kept = array_slice($kept, max(1, (int) floor(count($kept) / 10)));
        }
    }

    /** Keeps what was sent and sudemi's answer (a full sync's first push starts afresh). */
    public static function remember(array $listings, array $issues, $answer, $fresh = false)
    {
        $kept = $fresh ? [] : self::all();
        self::store(self::merge($kept, $listings, $issues, is_array($answer) ? $answer : [], time()));
    }

    /** Writes the kept list; a failed write keeps the list as it was (never emptied silently). */
    private static function store(array $kept)
    {
        $json = self::encode($kept);
        if ($json === null) {
            SudemiLogger::warn('Catalogue issues could not be encoded; the list on the "Products on sudemi" page was kept as it was.');

            return false;
        }
        if (!Configuration::updateValue(self::CONF, $json)) {
            SudemiLogger::warn('Catalogue issues could not be saved; the list on the "Products on sudemi" page was kept as it was.');

            return false;
        }

        return true;
    }

    public static function all()
    {
        $kept = json_decode((string) Configuration::get(self::CONF), true);

        return is_array($kept) ? $kept : [];
    }

    /** Issues not sent yet (no listing push carried them, or it failed), JSON: external id => issues. */
    public const CONF_QUEUE = 'SUDEMI_PENDING_ISSUES';

    /** The most issues in one request. */
    public const MAX_PER_REQUEST = 500;

    /**
     * Keeps issues for the next listing push or the heartbeat, never a request of their own while
     * a product is saved. The newest issues of a product replace older ones; they show on the
     * page at once.
     */
    public static function queue(array $issues)
    {
        if (empty($issues)) {
            return;
        }
        $queued = self::queuedMap();
        foreach (self::byProduct($issues) as $id => $list) {
            unset($queued[$id]);
            $queued[$id] = $list;
        }
        self::storeQueue($queued);
        self::remember([], $issues, null);
    }

    /** Issues grouped by external id (as plain arrays). Pure. */
    public static function byProduct(array $issues)
    {
        $out = [];
        foreach ($issues as $issue) {
            $issue['details'] = (array) $issue['details'];
            $out[(string) $issue['externalId']][] = $issue;
        }

        return $out;
    }

    /** Drops what is waiting for these products: a newer push carries their current state. */
    public static function unqueue(array $externalIds)
    {
        $queued = self::queuedMap();
        $before = count($queued);
        foreach ($externalIds as $id) {
            unset($queued[(string) $id]);
        }
        if (count($queued) !== $before) {
            self::storeQueue($queued);
        }
    }

    /** Waiting issues, oldest first, whole products up to $limit issues: [external id => issues]. */
    public static function queued($limit = self::MAX_PER_REQUEST)
    {
        $out = [];
        $count = 0;
        if ($limit <= 0) {
            return $out;
        }
        foreach (self::queuedMap() as $id => $list) {
            if ($count > 0 && $count + count($list) > $limit) {
                break;
            }
            $out[$id] = $list;
            $count += count($list);
        }

        return $out;
    }

    /** The issues of queued() as one wire list. Pure. */
    public static function flat(array $byProduct)
    {
        $out = [];
        foreach ($byProduct as $list) {
            foreach ($list as $issue) {
                $issue['details'] = (object) (isset($issue['details']) ? (array) $issue['details'] : []);
                $out[] = $issue;
            }
        }

        return $out;
    }

    /** Removes what was sent, unless a newer list for the product came in meanwhile. */
    public static function sent(array $byProduct)
    {
        $queued = self::queuedMap();
        foreach ($byProduct as $id => $list) {
            if (isset($queued[$id]) && $queued[$id] == $list) {
                unset($queued[$id]);
            }
        }
        self::storeQueue($queued);
    }

    /**
     * Sends waiting issues on their own (from the heartbeat): one request of at most
     * MAX_PER_REQUEST, no products (sudemi removes no listing for that). Kept on failure; dropped
     * when sudemi refuses them for good (the next full sync sends them again).
     */
    public static function drain()
    {
        $batch = self::queued();
        if (empty($batch)) {
            return false;
        }
        $issues = self::flat($batch);
        $answer = SudemiClient::postSigned('/integration/v1/products', self::attach(['products' => []], $issues));
        if (is_array($answer) && $answer['status'] === 200) {
            self::sent($batch);
            self::remember([], $issues, isset($answer['body']) ? $answer['body'] : null);

            return true;
        }
        if (is_array($answer) && in_array((int) $answer['status'], [400, 413, 422], true)) {
            self::sent($batch);
            SudemiLogger::warn('sudemi refused catalogue issues (HTTP ' . (int) $answer['status'] . '); the next full sync sends them again.');
        }

        return false;
    }

    public static function forgetQueue()
    {
        Configuration::deleteByName(self::CONF_QUEUE);
    }

    private static function queuedMap()
    {
        $queued = json_decode((string) Configuration::get(self::CONF_QUEUE), true);

        return is_array($queued) ? $queued : [];
    }

    /** Stored like the kept list (encode()); past MAX_BYTES the oldest products wait for a full sync. */
    private static function storeQueue(array $queued)
    {
        if (empty($queued)) {
            Configuration::deleteByName(self::CONF_QUEUE);

            return;
        }
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | SudemiClient::jsonFlags();
        while (!empty($queued)) {
            $json = json_encode($queued, $flags);
            if ($json === false) {
                SudemiLogger::warn('Waiting catalogue issues could not be encoded; the next full sync sends them again.');

                return;
            }
            if (strlen($json) <= self::MAX_BYTES) {
                if (!Configuration::updateValue(self::CONF_QUEUE, $json)) {
                    SudemiLogger::warn('Waiting catalogue issues could not be saved; the next full sync sends them again.');
                }

                return;
            }
            $queued = array_slice($queued, max(1, (int) floor(count($queued) / 10)), null, true);
        }
    }

    /** The external ids a list of combination rows of one product would have had. Pure. */
    public static function productId($externalId)
    {
        return (int) explode('-', (string) $externalId, 2)[0];
    }
}
