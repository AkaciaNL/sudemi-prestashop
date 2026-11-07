<?php
/**
 * Telling sudemi this shop is still here, and still means its prices.
 *
 * sudemi cannot call this shop: it sits behind this host's firewall and accepts no connection
 * from outside. So everything starts here, which makes this the only two-way channel the
 * integration has - the request says what this shop believes its catalogue to be, and the reply
 * is where sudemi asks for whatever it needs.
 *
 * Why it exists: from sudemi's side a shop with nothing to report and a shop whose server died
 * last Tuesday look identical, and only one of them still has prices worth quoting. If silence
 * were read as agreement, a dead shop would keep advertising last week's prices and taking
 * orders at them. So this shop says so, and sudemi stops listing it when it stops saying so.
 * The listings return on the next beat; nothing is deleted.
 *
 * The revision catches the quiet failure. A delta push can be lost - sudemi restarting, a DNS
 * blip, a proxy - and liveness notices nothing, because the shop is healthy and the beats keep
 * arriving. Stating what this catalogue is lets sudemi disagree, and a disagreement costs one
 * full sync instead of weeks of a wrong price.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiHeartbeat
{
    const CONF_STATUS = 'SUDEMI_STATUS';
    const CONF_INTERVAL = 'SUDEMI_HEARTBEAT_SECONDS';
    const CONF_CHANGES = 'SUDEMI_CHANGE_COUNTER';
    const CONF_CRON_TOKEN = 'SUDEMI_CRON_TOKEN';

    /** What this shop asks for. sudemi may hold it to something slower, and says so if it does. */
    const DEFAULT_INTERVAL = 300;

    /**
     * Runs a beat, draining anything that is waiting first.
     *
     * Drained first so the revision this beat reports is the one that holds once the backlog has
     * gone in: reporting a revision sudemi cannot match yet would ask for a full sync the queue
     * was about to make unnecessary.
     *
     * @return bool whether sudemi answered
     */
    public static function run()
    {
        if (!SudemiClient::isConnected()) {
            return false;
        }

        $drained = SudemiQueue::drain();

        $response = SudemiClient::postSigned('/integration/v1/heartbeat', [
            'catalogueRevision' => self::revision(),
            'productCount' => self::productCount(),
            'intervalSeconds' => self::DEFAULT_INTERVAL,
            'pluginVersion' => SUDEMI_VERSION,
        ]);

        if (!is_array($response)) {
            self::remember(['reachable' => false, 'checked' => time()]);

            return false;
        }

        if ($response['status'] === 401) {
            // Revoked from the portal, or this shop was re-paired elsewhere. Said plainly,
            // because the fix is to connect again and no amount of retrying will do it.
            self::remember([
                'reachable' => true,
                'connected' => false,
                'message' => 'sudemi no longer recognises this shop. Connect it again.',
                'checked' => time(),
            ]);

            return true;
        }

        $paired = SudemiClient::siteMismatch($response);
        if ($paired !== null) {
            // This site holds the shop's install id and key but is not the shop sudemi knows: a
            // staging copy, a restored backup, or the shop moved. Refused on purpose, so retrying
            // cannot help. The module page words it in the employee's language (problem/paired).
            self::remember([
                'reachable' => true,
                'connected' => false,
                'problem' => 'site_mismatch',
                'paired' => $paired,
                'message' => 'sudemi refused requests from this site: this shop is connected to sudemi under a different address.',
                'checked' => time(),
            ]);

            return true;
        }

        if ($response['status'] !== 200) {
            self::remember([
                'reachable' => true,
                'message' => 'sudemi answered ' . (int) $response['status'] . '.',
                'checked' => time(),
            ]);

            return true;
        }

        $body = $response['body'];

        // A fresh ownership token. sudemi re-proves the domain on a schedule because a listing
        // names it to buyers as this shop's identity, and domains change hands.
        if (!empty($body['domain_challenge']['token'])) {
            SudemiPairing::storeChallenge((string) $body['domain_challenge']['token']);
        }

        if (!empty($body['heartbeat_seconds'])) {
            Configuration::updateValue(self::CONF_INTERVAL, (int) $body['heartbeat_seconds']);
        }

        self::remember([
            'reachable' => true,
            'connected' => true,
            'listed' => !empty($body['catalogue_listed']),
            'reason' => isset($body['not_listed_reason']) ? (string) $body['not_listed_reason'] : '',
            'trusted_until' => isset($body['trusted_until']) ? (string) $body['trusted_until'] : '',
            'pending' => SudemiQueue::count(),
            'drained' => (int) $drained,
            'checked' => time(),
        ]);

        // Return requests this shop has to answer: the full list on every beat (absent = none),
        // so the stored copy is replaced, and cleared when the key is missing.
        SudemiRequests::store(isset($body['open_requests']) ? $body['open_requests'] : []);

        // Orders whose buyers are waiting on an answer from this shop. Before the full sync
        // below, because each one is money held on someone's card against a deadline, while a
        // catalogue disagreement costs nothing by waiting another five minutes.
        if (!empty($body['pending_orders'])) {
            SudemiOrders::handlePending($body['pending_orders']);
        }

        // Orders this shop accepted while the buyer's payment still covered other shops: now
        // paid (ship them) or released after all (cancel them). Outcomes to record, not questions.
        if (!empty($body['settled_orders'])) {
            SudemiOrders::handleSettled($body['settled_orders']);
        }

        // sudemi and this shop disagree about what the catalogue is, so something did not
        // arrive. A full sync is the only answer that does not involve guessing which product.
        if (!empty($body['full_sync_requested']) || Configuration::get('SUDEMI_RESYNC_DUE')) {
            Configuration::updateValue('SUDEMI_RESYNC_DUE', 0);
            SudemiLogger::info('sudemi asked for a full sync.');
            SudemiSync::runFull();
        }

        return true;
    }

    /** True when the last beat is older than the interval sudemi is holding this shop to. */
    public static function isDue()
    {
        $status = self::status();
        $last = isset($status['checked']) ? (int) $status['checked'] : 0;

        return (time() - $last) >= self::interval();
    }

    public static function interval()
    {
        $configured = (int) Configuration::get(self::CONF_INTERVAL);

        return $configured > 0 ? $configured : self::DEFAULT_INTERVAL;
    }

    public static function status()
    {
        $raw = Configuration::get(self::CONF_STATUS);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A cheap statement of what this catalogue is.
     *
     * Product count, newest modification date and a local change counter, hashed. It does not
     * have to describe the catalogue - only to change whenever the catalogue does, so sudemi's
     * copy of it can disagree. All three are needed: the count alone misses an edited price, the
     * date alone misses a deletion, and neither reliably moves for a stock change - which is why
     * the counter, bumped by the save hook itself, is there.
     */
    public static function revision()
    {
        $row = Db::getInstance()->getRow(
            'select count(*) as n, coalesce(max(date_upd), \'\') as newest
               from ' . _DB_PREFIX_ . 'product
              where active = 1'
        );

        if (!is_array($row)) {
            return null;
        }

        return hash('sha256', (string) $row['n'] . '|' . (string) $row['newest'] . '|' . self::changes());
    }

    /**
     * How many product changes this shop has seen since the module was installed.
     *
     * Monotonic and never reset: the value is meaningless, only its movement matters. Reset it
     * and an old revision could be reproduced, which is exactly the agreement we must not fake.
     */
    public static function changes()
    {
        return (int) Configuration::get(self::CONF_CHANGES);
    }

    public static function noteChange()
    {
        Configuration::updateValue(self::CONF_CHANGES, self::changes() + 1);
    }

    /**
     * The secret in this shop's cron URL.
     *
     * The cron entry point has to be reachable without a session, so the token is what stops a
     * stranger triggering a full catalogue walk on this shop whenever they like.
     */
    public static function cronToken()
    {
        $token = Configuration::get(self::CONF_CRON_TOKEN);
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(16));
            Configuration::updateValue(self::CONF_CRON_TOKEN, $token);
        }

        return $token;
    }

    public static function forget()
    {
        Configuration::deleteByName(self::CONF_STATUS);
        Configuration::deleteByName(self::CONF_INTERVAL);
        Configuration::deleteByName(self::CONF_CHANGES);
        Configuration::deleteByName(self::CONF_CRON_TOKEN);
        SudemiRequests::forget();
    }

    private static function productCount()
    {
        return (int) Db::getInstance()->getValue(
            'select count(*) from ' . _DB_PREFIX_ . 'product where active = 1'
        );
    }

    private static function remember(array $status)
    {
        Configuration::updateValue(self::CONF_STATUS, json_encode($status));
    }
}
