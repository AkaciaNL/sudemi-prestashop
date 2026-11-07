<?php
/**
 * Price changes that have not reached sudemi yet.
 *
 * A delta push runs inside actionProductUpdate, which means it happens while an employee is
 * saving a product: it gets one attempt and a short timeout, because holding that screen open
 * for a retry would make a sudemi outage look like a PrestaShop fault. What it must not do is
 * give up. A change dropped there is a wrong price on a marketplace until the next full sync,
 * so a failed push is written down here and the heartbeat sends it a few minutes later.
 *
 * Keyed by product id, so twenty saves of one product while sudemi is unreachable leave one
 * entry carrying the newest price rather than twenty carrying a history nobody wants.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiQueue
{
    const CONF = 'SUDEMI_PENDING_PRICES';

    /** A cap, because an unbounded configuration row is a way to make a shop unloadable. */
    const MAX_ENTRIES = 2000;

    public static function enqueue(array $update)
    {
        if (empty($update['externalId'])) {
            return;
        }
        $pending = self::all();
        $pending[(string) $update['externalId']] = $update;

        if (count($pending) > self::MAX_ENTRIES) {
            // Past this point it has stopped being a retry buffer and become a backlog, which a
            // full sync fixes more cheaply than draining would. The revision mismatch asks for
            // that sync on the next beat.
            $pending = array_slice($pending, -self::MAX_ENTRIES, null, true);
        }

        Configuration::updateValue(self::CONF, json_encode($pending, SudemiClient::jsonFlags()));
    }

    public static function all()
    {
        $raw = Configuration::get(self::CONF);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function count()
    {
        return count(self::all());
    }

    /**
     * Sends what is waiting and keeps whatever still does not get through.
     *
     * @return int how many updates sudemi accepted
     */
    public static function drain($batchSize = 200)
    {
        $pending = self::all();
        if (empty($pending)) {
            return 0;
        }

        $batch = array_slice($pending, 0, $batchSize, true);
        $response = SudemiClient::postSigned(
            '/integration/v1/products/prices',
            ['updates' => array_values($batch)]
        );

        $status = is_array($response) ? (int) $response['status'] : 0;
        if ($status !== 200 && !in_array($status, self::REFUSED, true)) {
            // Still unreachable, throttled or failing. Nothing is dropped and the next beat tries again.
            return 0;
        }

        // Only what was sent is cleared, and only where it is still what was sent: a save hook
        // that queued a newer price for the same product while this request was in flight
        // must not see that newer price discarded unsent.
        self::store(self::withoutSent(self::all(), $batch));

        if ($status !== 200) {
            // sudemi refused the batch itself (malformed, too large, or the shop is suspended).
            // Sending it again would be refused again forever, so it is dropped; the change
            // counter moves so the revision disagrees and sudemi asks for a full sync, which
            // carries every price afresh.
            SudemiHeartbeat::noteChange();
            SudemiLogger::error('sudemi refused ' . count($batch) . ' queued price update(s) (HTTP ' . $status
                . '). They were dropped; a full sync will send the current prices.');

            return 0;
        }

        return isset($response['body']['applied']) ? (int) $response['body']['applied'] : 0;
    }

    /** Statuses that refuse a batch for good: retrying the same batch cannot succeed. */
    const REFUSED = [400, 403, 413, 422];

    /**
     * The queue minus the entries that were sent, where they are unchanged since. Pure, so it can
     * be tested without PrestaShop.
     */
    public static function withoutSent(array $current, array $sent)
    {
        foreach ($sent as $key => $update) {
            if (array_key_exists($key, $current) && $current[$key] == $update) {
                unset($current[$key]);
            }
        }

        return $current;
    }

    private static function store(array $pending)
    {
        if (empty($pending)) {
            Configuration::deleteByName(self::CONF);
        } else {
            Configuration::updateValue(self::CONF, json_encode($pending, SudemiClient::jsonFlags()));
        }
    }

    public static function forget()
    {
        Configuration::deleteByName(self::CONF);
    }
}
