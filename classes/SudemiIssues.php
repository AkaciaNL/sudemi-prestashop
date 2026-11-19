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

    /** Keeps what was sent and sudemi's answer (a full sync's first push starts afresh). */
    public static function remember(array $listings, array $issues, $answer, $fresh = false)
    {
        $kept = $fresh ? [] : self::all();
        Configuration::updateValue(self::CONF, json_encode(self::merge($kept, $listings, $issues, is_array($answer) ? $answer : [], time())));
    }

    public static function all()
    {
        $kept = json_decode((string) Configuration::get(self::CONF), true);

        return is_array($kept) ? $kept : [];
    }

    /** The external ids a list of combination rows of one product would have had. Pure. */
    public static function productId($externalId)
    {
        return (int) explode('-', (string) $externalId, 2)[0];
    }
}
