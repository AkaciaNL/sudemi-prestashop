<?php

/**
 * Turning this shop's catalogue into what sudemi stores.
 *
 * A full sync walks every active product and ends with a commit, which is the only thing that
 * may delist: a sync that dies halfway is never committed, so a cron timeout cannot empty a
 * shop's listings. It remembers where it stopped, so the cron entry or later back-office page
 * loads continue it (see work()). A change push carries one product's listings and runs from
 * PrestaShop's own update hooks.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiSync
{
    /**
     * A description (HTML from the rich-text editor) as plain text: the tags go, and the entities
     * the HTML held ("&amp;", "&nbsp;", "&eacute;") are decoded, so sudemi gets "Salt & Pepper"
     * and not "Salt &amp; Pepper". A no-break space becomes a plain space. Names need nothing:
     * PrestaShop stores them as typed.
     */
    public static function plain($html)
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text));
    }

    /**
     * 1.4.9 (sudemi owner decision 2026-11-26): a product description as HTML, as the rich-text
     * editor stored it, so buyers see its paragraphs, lists and headings. sudemi keeps only p, br,
     * ul, ol, li, strong, b, em, i, h3 and h4 (h1/h2 as h3, h5/h6 as h4) and removes everything
     * else itself: scripts, styles, frames and their content, every attribute, links (their text
     * stays), images. Entities stay as the HTML has them: sudemi reads them as HTML.
     */
    public static function descriptionHtml($html)
    {
        return trim((string) $html);
    }

    /**
     * The product's whole description: the summary (description_short) and the long description,
     * both as HTML, the summary first. Only one of them when the other is empty or the same, so a
     * shop that fills only one field sends just that one. Pure.
     */
    public static function productDescription($short, $long)
    {
        $short = self::descriptionHtml($short);
        $long = self::descriptionHtml($long);
        if ($short === '' || $short === $long) {
            return $long;
        }
        if ($long === '') {
            return $short;
        }

        return $short . "\n" . $long;
    }

    public const BATCH_SIZE = 100;

    /** Set when a full sync should run (sudemi asked, carriers or delivery times changed). */
    public const CONF_DUE = 'SUDEMI_RESYNC_DUE';

    /** The full sync in progress: its id, where it stopped, the revision it started from. */
    public const CONF_STATE = 'SUDEMI_SYNC_STATE';

    /**
     * Listings that stopped being listed here (a product deleted or switched off, a combination
     * deleted), counted; and the count the last committed full sync covered. Only a full sync's
     * commit can take a listing off sudemi, so while the two differ no push may carry the
     * catalogue revision: sudemi would adopt it and the gone listing would stay listed.
     */
    public const CONF_REMOVALS = 'SUDEMI_REMOVALS';
    public const CONF_REMOVALS_SYNCED = 'SUDEMI_REMOVALS_SYNCED';

    /** A back-office request sends at most this many products, for at most this many seconds. */
    public const STEP_PRODUCTS = 25;
    public const STEP_SECONDS = 2;

    /** sudemi drops an uncommitted sync after 6 hours; one older than this is started again. */
    public const MAX_SYNC_AGE = 19800;

    /** Asks for a full sync; the cron entry (or the next back-office steps) runs it. */
    public static function request()
    {
        Configuration::updateValue(self::CONF_DUE, 1);
    }

    /** Whether a full sync was started and has not finished yet. */
    public static function inProgress()
    {
        return self::state() !== null;
    }

    /** Whether there is full-sync work waiting: asked for, or started and not finished. */
    public static function pending()
    {
        return (bool) Configuration::get(self::CONF_DUE) || self::inProgress();
    }

    /**
     * A whole full sync, now: for scripts and the command line only, never a page request (the
     * module page's buttons only call request()). Continues a sync already in progress rather
     * than starting a second one.
     *
     * @return bool whether it was committed
     */
    public static function runFull()
    {
        if (!self::inProgress()) {
            self::request();
        }
        do {
            $result = self::work();
        } while ($result === 'more');

        return $result === 'done';
    }

    /**
     * Runs the full sync that is due or in progress, for at most $maxProducts products and
     * $maxSeconds seconds (0 = until it is done), and remembers where it stopped.
     *
     * The cron entry runs it to the end. A back-office page load only ever runs a small bounded
     * step after the page was sent (Sudemi::hookActionAdminControllerSetMedia): a full sync of a
     * large catalogue inside a page load held the employee's screen for minutes. A database lock
     * keeps two requests from sending the same sync at once.
     *
     * @return string 'idle' (nothing to do), 'busy' (another request is sending it), 'more'
     *                (stopped at the limit), 'done' (committed), 'held' (sudemi held the commit)
     *                or 'failed' (kept for a retry when sudemi did not answer)
     */
    public static function work($maxProducts = 0, $maxSeconds = 0)
    {
        if (!SudemiClient::isConnected() || !self::pending()) {
            return 'idle';
        }
        if (!self::lock()) {
            return 'busy';
        }
        try {
            // Read again under the lock, from the database rather than PrestaShop's cache of it:
            // another request may have moved it on or finished it meanwhile.
            $state = self::state(true);
            if ($state !== null && !self::ownState($state)) {
                // Started under an earlier connection (the shop was connected again since):
                // sudemi refuses its id from this one, and continuing it would only abandon it
                // and leave this run without a sync. Started again under this connection.
                SudemiLogger::info('A catalogue sync from an earlier connection was replaced by a new one.');
                self::clearState();
                self::request();
                $state = null;
            }
            if ($state !== null && time() - (int) $state['started'] > self::MAX_SYNC_AGE) {
                SudemiLogger::warn('An unfinished catalogue sync was too old and is started again.');
                self::clearState();
                self::request();
                $state = null;
            }
            if ($state === null) {
                if (!self::freshValue(self::CONF_DUE)) {
                    return 'idle';
                }
                $state = self::start();
                if ($state === null) {
                    return 'failed';
                }
            }

            return self::advance($state, (int) $maxProducts, (int) $maxSeconds);
        } finally {
            self::unlock();
        }
    }

    /**
     * Sends a bounded step of the waiting full sync after the response went to the browser,
     * where PHP-FPM (or LiteSpeed) allows that; elsewhere the step is small enough not to matter.
     */
    public static function afterResponse()
    {
        if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
            while (ob_get_level() > 0 && @ob_end_flush()) {
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                litespeed_finish_request();
            }
        }
        ignore_user_abort(true);
        try {
            if (SudemiHeartbeat::isDue()) {
                SudemiHeartbeat::run();
            }
            self::work(self::STEP_PRODUCTS, self::STEP_SECONDS);
        } catch (Throwable $e) {
            SudemiLogger::warn('Background step failed: ' . $e->getMessage());
        }
    }

    /** Opens a sync on sudemi and sends the shop and its categories; null when sudemi refused. */
    private static function start()
    {
        // Cleared first: anything asking for a sync from here on asks for another one.
        Configuration::updateValue(self::CONF_DUE, 0);
        $started = SudemiClient::postSigned('/integration/v1/sync', []);
        if ($started === null || $started['status'] !== 200 || empty($started['body']['sync_id'])) {
            SudemiLogger::warn('Could not start a catalogue sync.');

            return null;
        }
        $state = [
            'id' => (string) $started['body']['sync_id'],
            // The connection the sync belongs to; see ownState().
            'install' => (string) SudemiClient::installId(),
            // What the catalogue was when this sync started reading it; sent with the commit and
            // in a heartbeat right after it (SudemiHeartbeat::anchor), so sudemi holds the
            // revision this sync describes.
            'anchor' => SudemiHeartbeat::revision(),
            'removals' => self::removals(),
            // sudemi takes at most 500 listings per request (fewer when its answer says so).
            'limit' => self::listingsPerRequest($started['body']),
            'cursor' => 0,
            'sent' => 0,
            'started' => time(),
        ];
        self::saveState($state);
        // The per-product returns setting's table (1.4.4), also before a back-office page ran setup.
        SudemiWithdrawal::installReturnableTable();
        self::pushShop();
        self::pushCategories();

        return $state;
    }

    /** Sends products after the cursor, page by page, then commits; see work(). */
    private static function advance(array $state, $maxProducts, $maxSeconds)
    {
        $began = microtime(true);
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $done = 0;
        while (true) {
            $size = $maxProducts > 0 ? min(self::BATCH_SIZE, $maxProducts - $done) : self::BATCH_SIZE;
            $ids = self::productIdsAfter((int) $state['cursor'], $size);
            if (empty($ids)) {
                return self::commit($state);
            }
            // A product with combinations is one listing per combination, so a page of products
            // can be more than one request.
            $listings = [];
            foreach ($ids as $id) {
                foreach (self::listings($id, $languageId) as $item) {
                    $listings[] = $item;
                }
            }
            list($batches) = self::chunkListings($listings, (int) $state['limit'], true);
            // What was left out of this page goes with its first request (SudemiIssues).
            $fresh = (int) $state['sent'] === 0 && (int) $state['cursor'] === 0;
            foreach ($batches as $batch) {
                $issues = SudemiIssues::take();
                $result = SudemiClient::postSigned('/integration/v1/products', SudemiIssues::attach([
                    'syncId' => $state['id'],
                    'products' => $batch,
                ], $issues));
                if ($result === null || $result['status'] !== 200) {
                    return self::failed($result, 'A batch failed');
                }
                SudemiIssues::remember($batch, $issues, isset($result['body']) ? $result['body'] : null, $fresh);
                $fresh = false;
            }
            // Nothing listed on this page but something left out: sent on its own (best effort).
            self::sendLeftIssues($state['id'], $fresh);
            // Moved on only once the whole page is in: a step stopped halfway sends it again,
            // which sudemi takes as the same products once more.
            $state['cursor'] = (int) end($ids);
            $state['sent'] = (int) $state['sent'] + count($listings);
            self::saveState($state);
            $done += count($ids);
            if (($maxProducts > 0 && $done >= $maxProducts)
                || ($maxSeconds > 0 && microtime(true) - $began >= $maxSeconds)) {
                return 'more';
            }
        }
    }

    private static function commit(array $state)
    {
        // The commit carries the revision the sync started from: sudemi adopts it, so anything
        // changed while the sync ran makes the next heartbeat disagree and is synced next time.
        $anchor = isset($state['anchor']) ? $state['anchor'] : null;
        $committed = SudemiClient::postSigned(
            '/integration/v1/sync/' . rawurlencode((string) $state['id']) . '/commit',
            $anchor === null ? [] : ['catalogueRevision' => $anchor]
        );
        if ($committed === null || $committed['status'] !== 200) {
            return self::failed($committed, 'The catalogue was sent but the sync could not be committed');
        }
        self::clearState();
        if (isset($committed['body']['status']) && $committed['body']['status'] === 'held') {
            // sudemi does not apply a commit that would delist a large share of the live
            // catalogue. Nothing was changed, so this is not a completed sync.
            $body = $committed['body'];
            SudemiLogger::warn('The catalogue sync is on hold: it would remove '
                . (isset($body['would_delist']) ? (int) $body['would_delist'] : 0) . ' of '
                . (isset($body['live_products']) ? (int) $body['live_products'] : 0)
                . ' listed products from sudemi, so nothing was changed. If those products really were removed from this shop, contact sudemi support (support@sudemi.com).');

            return 'held';
        }
        // Everything removed before the sync started is gone from sudemi now.
        if ((int) $state['removals'] > (int) Configuration::get(self::CONF_REMOVALS_SYNCED)) {
            Configuration::updateValue(self::CONF_REMOVALS_SYNCED, (int) $state['removals']);
        }

        SudemiHeartbeat::anchor($anchor);
        SudemiLogger::info('Synced ' . (int) $state['sent'] . ' products.');
        // The shop's parcel rules go with every committed full sync (protocol: shipping rules).
        SudemiShippingRules::request();
        SudemiShippingRules::sendIfDue();

        return 'done';
    }

    /**
     * A request of the sync that did not get through. Unanswered, throttled or a server error:
     * kept, and the next step sends it again. Refused (the sync expired or closed, the shop is
     * suspended): abandoned, which delists nothing, and sudemi asks again when it needs one.
     */
    private static function failed($response, $what)
    {
        $status = is_array($response) ? (int) $response['status'] : 0;
        if ($status === 0 || $status === 429 || $status >= 500) {
            SudemiLogger::warn($what . '; it is retried on the next run.');

            return 'failed';
        }
        self::clearState();
        SudemiLogger::warn($what . ' (HTTP ' . $status . '); the sync was abandoned and nothing was delisted.');

        return 'failed';
    }

    /** Ids of the active products after $after, in id order. */
    private static function productIdsAfter($after, $limit)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT p.id_product FROM ' . _DB_PREFIX_ . 'product p ' . Shop::addSqlAssociation('product', 'p')
            . ' WHERE product_shop.active = 1 AND p.id_product > ' . (int) $after
            . ' ORDER BY p.id_product ASC LIMIT ' . max(1, (int) $limit)
        );

        return is_array($rows) ? array_map(function ($row) {
            return (int) $row['id_product'];
        }, $rows) : [];
    }

    private static function state($fresh = false)
    {
        $raw = $fresh ? self::freshValue(self::CONF_STATE) : Configuration::get(self::CONF_STATE);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($state) && !empty($state['id']) ? $state : null;
    }

    /** A configuration value as the database holds it now (Configuration::get is cached per request). */
    private static function freshValue($name)
    {
        $value = Db::getInstance()->getValue(
            'SELECT value FROM ' . _DB_PREFIX_ . "configuration WHERE name = '" . pSQL($name) . "' ORDER BY date_upd DESC"
        );

        return $value === false ? null : $value;
    }

    private static function saveState(array $state)
    {
        Configuration::updateValue(self::CONF_STATE, json_encode($state));
    }

    private static function clearState()
    {
        Configuration::deleteByName(self::CONF_STATE);
    }

    /** Released by MySQL itself if the request dies, so a crash never leaves it held. */
    private static function lock()
    {
        return (int) Db::getInstance()->getValue("SELECT GET_LOCK('" . self::lockName() . "', 0)") === 1;
    }

    private static function unlock()
    {
        Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . self::lockName() . "')");
    }

    private static function lockName()
    {
        return 'sudemi_sync_' . substr(md5(_DB_NAME_ . '|' . _DB_PREFIX_), 0, 16);
    }

    /** A listing stopped being listed here; see CONF_REMOVALS. */
    public static function noteRemoval()
    {
        Configuration::updateValue(self::CONF_REMOVALS, self::removals() + 1);
    }

    private static function removals()
    {
        return (int) Configuration::get(self::CONF_REMOVALS);
    }

    /** Whether every listing removed here has been taken off sudemi by a committed full sync. */
    private static function removalsSynced()
    {
        return self::removals() <= (int) Configuration::get(self::CONF_REMOVALS_SYNCED);
    }

    /** The most listings one /products request may carry. */
    public const MAX_LISTINGS_PER_REQUEST = 500;

    /** The batch size: 500, or less when sudemi's /sync answer names a smaller maximum. */
    public static function listingsPerRequest($syncAnswer)
    {
        $limit = self::MAX_LISTINGS_PER_REQUEST;
        if (is_array($syncAnswer) && isset($syncAnswer['max_products_per_request'])) {
            $max = (int) $syncAnswer['max_products_per_request'];
            if ($max > 0 && $max < $limit) {
                $limit = $max;
            }
        }

        return $limit;
    }

    /**
     * Full batches of at most $limit listings, plus what is left over ([batches, rest]). With
     * $final the rest is sent too. Pure, so it can be tested without PrestaShop.
     */
    public static function chunkListings(array $buffer, $limit, $final)
    {
        $limit = max(1, (int) $limit);
        $batches = [];
        while (count($buffer) >= $limit) {
            $batches[] = array_slice($buffer, 0, $limit);
            $buffer = array_slice($buffer, $limit);
        }
        if ($final && !empty($buffer)) {
            $batches[] = $buffer;
            $buffer = [];
        }

        return [$batches, $buffer];
    }

    /** Products changed in this request, pushed once when it ends (see pushDelta). */
    private static $changed = [];

    /**
     * A product changed (saved, its stock moved, a combination edited, added or deleted): its
     * listings go to sudemi when this request ends. Quiet on failure: a sudemi outage must not
     * disturb the shop.
     *
     * Pushed at the end of the request, once per product, rather than from each hook: saving a
     * product with combinations fires a hook per combination and one for the product, and the
     * catalogue revision a push carries has to be the one that holds after all of them.
     *
     * @param int $productId
     * @param int|null $attributeId the combination a stock move named (all are sent anyway)
     */
    public static function pushDelta($productId, $attributeId = null)
    {
        if ((int) $productId <= 0 || !SudemiClient::isConnected()) {
            return;
        }
        // Counted now, not after a push: the counter is what lets sudemi notice a change that
        // never arrived, so it has to move even when the push fails or never runs.
        SudemiHeartbeat::noteChange();
        if (empty(self::$changed)) {
            register_shutdown_function([__CLASS__, 'pushChanged']);
        }
        self::$changed[(int) $productId] = true;
    }

    /** Sends what pushDelta collected. Runs when the request ends. */
    public static function pushChanged()
    {
        $ids = array_keys(self::$changed);
        self::$changed = [];
        foreach ($ids as $id) {
            try {
                self::pushProduct($id);
            } catch (Throwable $e) {
                SudemiLogger::warn('A product change could not be sent: ' . $e->getMessage());
            }
        }
    }

    /**
     * One product's listings: itself, or every combination. Sent whole, they can carry the
     * catalogue revision (see pushListings). A product that has no listing any more (switched
     * off, deleted) goes as a price push, and its removal is a full sync's job.
     */
    private static function pushProduct($productId)
    {
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $product = new Product((int) $productId, false, $languageId);
        if (!Validate::isLoadedObject($product)) {
            // Deleted: nothing to send, and the listing waits for a full sync to remove it.
            self::noteRemoval();

            return;
        }

        try {
            $listings = self::listings((int) $product->id, $languageId);
            $priced = true;
        } catch (Throwable $e) {
            // PrestaShop prices a full product only with an employee or a cart in the
            // context; outside one (a script, an import) the save still sends price and stock.
            $listings = [];
            $priced = false;
        }
        // Issues waiting for this product are stale now: its current ones replace them.
        $ids = [(string) $product->id];
        foreach (self::combinationIds($product) as $paid) {
            $ids[] = self::externalId((int) $product->id, $paid);
        }
        SudemiIssues::unqueue($ids);
        if (!empty($listings)) {
            self::pushListings($listings);

            return;
        }
        // Not listed because of something the module left out: sudemi and the shop are told why,
        // with the next push or by the heartbeat, never a request of its own while saving.
        SudemiIssues::queue(SudemiIssues::take());
        if ($priced) {
            // Switched off: its listings stay on sudemi until a full sync's commit removes them.
            self::noteRemoval();
        }

        $combinations = self::combinationIds($product);
        $updates = [];
        try {
            foreach (empty($combinations) ? [0] : $combinations as $paid) {
                $quantity = (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, $paid ?: null);
                $updates[] = [
                    'externalId' => self::externalId((int) $product->id, $paid),
                    'priceMinor' => self::minor(Product::getPriceStatic((int) $product->id, true, $paid ?: null)),
                    'currency' => self::currency(),
                    'inStock' => $quantity > 0,
                    'quantity' => $quantity,
                ];
            }
        } catch (Throwable $e) {
            // Not even a price without an employee or a cart in the context (a script, an
            // import). The change counter moved, so the next heartbeat disagrees and sudemi asks
            // for a full sync, which the cron entry runs with a shop context.
            return;
        }

        $response = SudemiClient::postSigned('/integration/v1/products/prices', ['updates' => $updates]);

        // A dropped change is a wrong price on a marketplace until the next full sync, so a
        // failure is written down rather than logged and forgotten. The heartbeat drains it.
        if (!is_array($response) || $response['status'] !== 200) {
            foreach ($updates as $update) {
                SudemiQueue::enqueue($update);
            }
            SudemiLogger::warn('A price update could not be sent; it is queued for retry.');
        }
    }

    /**
     * Sends one product's listings (itself, or every combination) as a change push, with the
     * catalogue revision when nothing else is waiting to be sent.
     *
     * A price push carries price and stock and nothing else, so sending the revision with it
     * would tell sudemi a renamed product had arrived when it had not; the whole listings carry
     * everything about the product. All combinations go together: an edit of the product
     * changes each of them.
     *
     * The revision is computed after PrestaShop saved the change and after the change counter
     * moved, exactly as the next heartbeat computes it, and only sent when: no price change is
     * queued, no listing removed here is still waiting for a full sync to take it off sudemi
     * (a combination deleted, a product switched off), the category tree sudemi holds is
     * current, and every earlier request of this push was accepted whole. If every item
     * applied, sudemi adopts it (catalogue_revision_agreed) and no full sync follows the edit.
     */
    private static function pushListings(array $listings)
    {
        foreach ($listings as $listing) {
            // This push carries the product's newest state; an older queued price for it would
            // otherwise be sent after it and put the old price back.
            SudemiQueue::discard($listing['externalId']);
        }

        list($batches) = self::chunkListings($listings, self::MAX_LISTINGS_PER_REQUEST, true);
        $clean = true;
        $last = count($batches) - 1;
        foreach ($batches as $i => $batch) {
            $body = ['products' => $batch];
            // A product moved into a category sudemi has not been sent would get past sudemi's
            // category checks until a full sync, which an agreed revision makes rare. So the
            // category tree is sent first when it changed, and without it there is no revision.
            if ($i === $last && $clean && SudemiQueue::count() === 0 && self::removalsSynced()
                && self::categoriesCurrent()) {
                $revision = SudemiHeartbeat::revision();
                if ($revision !== null) {
                    $body['catalogueRevision'] = $revision;
                }
            }

            // What was left out of this product goes with its first request (SudemiIssues), and
            // with it the issues still waiting from earlier saves: no request of their own.
            $own = $i === 0 ? SudemiIssues::take() : [];
            $waiting = $i === 0 ? SudemiIssues::queued(max(0, SudemiIssues::MAX_PER_REQUEST - count($own))) : [];
            $issues = array_merge($own, SudemiIssues::flat($waiting));
            $response = SudemiClient::postSigned('/integration/v1/products', SudemiIssues::attach($body, $issues));
            if (is_array($response) && $response['status'] === 200) {
                SudemiIssues::sent($waiting);
                SudemiIssues::remember($batch, $issues, isset($response['body']) ? $response['body'] : null);
            }
            if (!is_array($response) || $response['status'] !== 200) {
                // Retried with the next push or by the heartbeat.
                SudemiIssues::queue($own);
                $clean = false;
                foreach ($batch as $listing) {
                    SudemiQueue::enqueue(self::priceUpdate($listing));
                }
                SudemiLogger::warn('A price update could not be sent; it is queued for retry.');
            } elseif (!empty($response['body']['rejected'])) {
                $clean = false;
            }
        }
    }

    /**
     * Issues of a full sync page where nothing was listed (every product left out): sent with the
     * sync, without products, which sudemi takes from 2026-11-19 (one request per page, from the
     * sync, never while a product is saved). On failure they wait in the queue
     * (SudemiIssues::queue) for the next push or the heartbeat.
     */
    private static function sendLeftIssues($syncId, $fresh = false)
    {
        $left = SudemiIssues::take();
        if (empty($left)) {
            return;
        }
        $answer = SudemiClient::postSigned('/integration/v1/products', SudemiIssues::attach(['syncId' => $syncId, 'products' => []], $left));
        if (!is_array($answer) || $answer['status'] !== 200) {
            if ($fresh) {
                SudemiIssues::remember([], [], null, true);
            }
            SudemiIssues::queue($left);

            return;
        }
        SudemiIssues::remember([], $left, isset($answer['body']) ? $answer['body'] : null, $fresh);
    }

    /** The price-and-stock part of a listing, as the retry queue keeps it. */
    private static function priceUpdate(array $listing)
    {
        // With the sale and availability: a price alone would end a running sale.
        return SudemiProductData::priceUpdateFields($listing) + [
            'externalId' => $listing['externalId'],
            'priceMinor' => $listing['priceMinor'],
            'currency' => $listing['currency'],
            'inStock' => $listing['inStock'],
            'quantity' => $listing['quantity'],
        ];
    }

    /** A hash of the category tree last sent to sudemi, so a change can be noticed. */
    public const CONF_CATEGORIES = 'SUDEMI_CATEGORIES_SENT';

    /** Whether sudemi holds this shop's category tree as it is now; sends it when not. */
    private static function categoriesCurrent()
    {
        $categories = self::categories();
        if ($categories === null) {
            return false;
        }
        if (Configuration::get(self::CONF_CATEGORIES) === self::categoriesHash($categories)) {
            return true;
        }

        return self::sendCategories($categories);
    }

    /** Tied to the connection: a shop connected again has sent its new connection nothing. */
    private static function categoriesHash(array $categories)
    {
        return hash('sha256', (string) SudemiClient::installId() . '|' . json_encode($categories, SudemiClient::jsonFlags()));
    }

    private static function sendCategories(array $categories)
    {
        if (empty($categories)) {
            // sudemi takes at least one; a shop without categories has nothing to be out of date.
            return true;
        }
        $response = SudemiClient::postSigned('/integration/v1/categories', ['categories' => $categories]);
        if (!is_array($response) || $response['status'] !== 200) {
            return false;
        }
        Configuration::updateValue(self::CONF_CATEGORIES, self::categoriesHash($categories));

        return true;
    }

    /**
     * Whether a sync in progress was started under the current connection. One saved by an
     * earlier version carries no connection and counts as foreign: starting it again costs
     * one full sync, continuing a foreign one costs the run.
     */
    private static function ownState(array $state)
    {
        return isset($state['install']) && (string) $state['install'] === (string) SudemiClient::installId();
    }

    /** Called when the shop connects: a sync in progress from an earlier connection is dropped. */
    public static function abandonForeign()
    {
        $state = self::state(true);
        if ($state !== null && !self::ownState($state)) {
            self::clearState();
            self::request();
        }
    }

    public static function forget()
    {
        Configuration::deleteByName(self::CONF_CATEGORIES);
        Configuration::deleteByName(self::CONF_STATE);
        Configuration::deleteByName(self::CONF_DUE);
        Configuration::deleteByName(self::CONF_REMOVALS);
        Configuration::deleteByName(self::CONF_REMOVALS_SYNCED);
    }

    /** "<id_product>" or, for a combination, "<id_product>-<id_product_attribute>". */
    public static function externalId($productId, $attributeId)
    {
        return $attributeId ? $productId . '-' . $attributeId : (string) $productId;
    }

    /** [id_product, id_product_attribute] from an external id (0 = no combination). */
    public static function parseExternalId($externalId)
    {
        $parts = explode('-', (string) $externalId, 2);

        return [(int) $parts[0], isset($parts[1]) ? (int) $parts[1] : 0];
    }

    private static function combinationIds(Product $product)
    {
        $ids = [];
        foreach ($product->getAttributeCombinations((int) Configuration::get('PS_LANG_DEFAULT')) as $row) {
            $ids[(int) $row['id_product_attribute']] = true;
        }

        return array_keys($ids);
    }

    /**
     * The listings one product becomes: itself, or one per combination (size, colour...), because
     * a buyer buys a combination, never the product without one. Each has its own price, stock,
     * reference, barcode, weight, photos and the combination's options (by name, in the shop's
     * language), and all share the product's id as their variant group, so sudemi shows them as
     * one product with a choice.
     */
    private static function listings($productId, $languageId)
    {
        // Specific prices are read once per product (not per combination) and fresh per product.
        SudemiProductData::reset();
        $base = self::productPayload($productId, $languageId);
        if ($base === null) {
            return [];
        }
        $product = new Product($productId, true, $languageId);
        $rows = $product->getAttributeCombinations($languageId);
        if (empty($rows)) {
            return [$base];
        }
        $byCombination = [];
        foreach ($rows as $row) {
            $byCombination[(int) $row['id_product_attribute']][] = $row;
        }
        $groups = self::attributeGroups($languageId, $rows);
        $fields = self::customizationFields($product, $languageId);
        $link = Context::getContext()->link;
        // Photos the shop assigned to each combination (the ones its storefront shows for it).
        $combinationImages = $product->getCombinationImages($languageId);
        // Shipping depends on the price and the weight (a combination's own weight is added).
        $shippingByPrice = [];
        $listings = [];
        foreach ($byCombination as $paid => $attributes) {
            $attributes = SudemiVariants::sorted($attributes, $groups);
            $first = $attributes[0];
            $variant = SudemiVariants::attributes($attributes, $groups);
            $item = $base;
            $item['externalId'] = self::externalId($productId, $paid);
            $item['itemGroupId'] = (string) $productId;
            $item['variantAttributes'] = $variant;
            $item['title'] = $base['title'] . ' - ' . implode(', ', array_map(function ($a) {
                return $a['value'];
            }, $variant));
            $item['sku'] = ((string) $first['reference']) !== '' ? (string) $first['reference'] : $base['sku'];
            $gtin = ((string) $first['ean13']) !== '' ? (string) $first['ean13'] : (((string) $first['upc']) !== '' ? (string) $first['upc'] : null);
            // A combination is a different item from its siblings: never inherit the product's barcode.
            $item['gtin'] = $gtin;
            if (isset($first['mpn']) && (string) $first['mpn'] !== '') {
                $item['mpn'] = (string) $first['mpn'];
            }
            $price = Product::getPriceStatic($productId, true, $paid);
            $item['priceMinor'] = self::minor($price);
            if ((int) $item['priceMinor'] < 1) {
                // Never sold without a price (never free): this combination is not sent.
                SudemiIssues::add($item['externalId'], SudemiIssues::MISSING_PRICE, 'error', 'price', 'Not listed: the product has no price.');
                continue;
            }
            $quantity = (int) StockAvailable::getQuantityAvailableByProduct($productId, $paid);
            $item['quantity'] = $quantity;
            $item['inStock'] = $quantity > 0;
            // In PS_WEIGHT_UNIT, rounded up to the gram (the weight bands of SudemiShippingRules).
            $item['weightGrams'] = SudemiShippingRules::toGrams((float) $product->weight + (float) $first['weight'], Configuration::get('PS_WEIGHT_UNIT'));
            $item['minQuantity'] = isset($first['minimal_quantity']) ? max(1, (int) $first['minimal_quantity']) : $base['minQuantity'];
            // The sale window, quantity prices, availability without stock, size, condition.
            foreach (['availabilityDate', 'availabilityText', 'quantityPrices', 'regularPriceMinor', 'salePriceMinor', 'salePriceFrom', 'salePriceUntil'] as $own) {
                unset($item[$own]);
            }
            $item = array_merge($item, SudemiProductData::forListing($product, $paid, $quantity, $item['priceMinor'], function ($price) {
                return self::minor($price);
            }));
            $item['url'] = $link->getProductLink($product, null, null, null, null, null, $paid);
            $item['specs'] = array_merge(array_map(function ($a) {
                return ['name' => $a['name'], 'values' => [$a['value']]];
            }, $variant), $base['specs']);
            $item['personalisation'] = SudemiVariants::personalisation($fields, $attributes, $groups);
            if ($item['personalisation'] === null) {
                // More required texts than sudemi can ask: not sellable here (see SudemiVariants).
                SudemiIssues::add($item['externalId'], SudemiIssues::TOO_MANY_REQUIRED_TEXTS, 'error', 'customization', 'Not listed: more than ' . SudemiVariants::MAX_FIELDS . ' required texts for the buyer to type.');
                continue;
            }
            $priceKey = (string) $item['priceMinor'] . '|' . (float) $first['weight'];
            if (!isset($shippingByPrice[$priceKey])) {
                $shippingByPrice[$priceKey] = SudemiShipping::ratesFor($product, $price, (float) $first['weight']);
            }
            $item['shipping'] = $shippingByPrice[$priceKey];
            // A combination with its own photos shows only those (a black pot is not shown with
            // the white pot's photo); one without keeps all of the product's photos.
            if (is_array($combinationImages) && !empty($combinationImages[$paid])) {
                $own = [];
                foreach ($combinationImages[$paid] as $image) {
                    $own[] = $link->getImageLink($product->link_rewrite, $productId . '-' . (int) $image['id_image']);
                }
                $item['images'] = $own;
            }
            $listings[] = $item;
        }

        return $listings;
    }

    /**
     * The attribute groups of these combination rows: id => name, public name (as the
     * storefront shows it), position, and the first value this product uses in it (the lowest
     * position: "No" of a "Name to print: No / Yes" group).
     */
    private static function attributeGroups($languageId, array $rows)
    {
        $groups = [];
        foreach (AttributeGroup::getAttributesGroups($languageId) as $group) {
            $groups[(int) $group['id_attribute_group']] = [
                'name' => (string) $group['name'],
                'public_name' => (string) $group['public_name'],
                'position' => (int) $group['position'],
            ];
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['id_attribute']] = (int) $row['id_attribute_group'];
        }
        if (!empty($ids)) {
            $positions = Db::getInstance()->executeS(
                'SELECT id_attribute, position FROM ' . _DB_PREFIX_ . 'attribute WHERE id_attribute IN (' . implode(',', array_map('intval', array_keys($ids))) . ')'
            );
            $best = [];
            foreach (is_array($positions) ? $positions : [] as $p) {
                $g = $ids[(int) $p['id_attribute']];
                if (!isset($best[$g]) || (int) $p['position'] < $best[$g][1]
                    || ((int) $p['position'] === $best[$g][1] && (int) $p['id_attribute'] < $best[$g][0])) {
                    $best[$g] = [(int) $p['id_attribute'], (int) $p['position']];
                }
            }
            foreach ($best as $g => $pick) {
                $groups[$g]['first'] = $pick[0];
            }
        }

        return $groups;
    }

    /** The product's customization fields (text and file), in the shop's language. */
    public static function customizationFields(Product $product, $languageId)
    {
        if (empty($product->customizable)) {
            return [];
        }
        $fields = $product->getCustomizationFields((int) $languageId);

        return is_array($fields) ? array_values(array_filter($fields, 'is_array')) : [];
    }

    /**
     * What the listing behind an external id asks the buyer to type (see SudemiVariants), for
     * an order coming back.
     */
    public static function personalisationFor($productId, $attributeId)
    {
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $product = new Product((int) $productId, false, $languageId);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }
        $fields = self::customizationFields($product, $languageId);
        if (empty($fields)) {
            return [];
        }
        // The groups' first values come from all the product's combinations, the rows from this one.
        $all = $attributeId ? $product->getAttributeCombinations($languageId) : [];
        $all = is_array($all) ? $all : [];
        $rows = array_values(array_filter($all, function ($row) use ($attributeId) {
            return (int) $row['id_product_attribute'] === (int) $attributeId;
        }));
        $groups = self::attributeGroups($languageId, $all);

        return SudemiVariants::personalisation($fields, SudemiVariants::sorted($rows, $groups), $groups);
    }

    private static function pushShop()
    {
        // The description, support e-mail and returns window are the ones saved on the module's
        // Shop profile page (1.4.0). Before, every sync sent the shop name as the description and
        // overwrote what the seller had set; the name only fills an empty one on sudemi.
        $response = SudemiClient::postSigned('/integration/v1/shop', array_merge([
            'displayName' => Configuration::get('PS_SHOP_NAME'),
            'languages' => [Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT'))],
            'currency' => self::currency(),
            'shipsTo' => self::shippingCountries(),
        ], SudemiInsights::profileForSync()));
        // What sudemi now has as this shop's currency, shown on the configuration page.
        SudemiCurrency::rememberShopAnswer($response, self::currency());
    }

    private static function pushCategories()
    {
        $categories = self::categories();
        if ($categories !== null) {
            self::sendCategories($categories);
        }
    }

    /** The category tree as sudemi takes it, or null when PrestaShop cannot read it. */
    private static function categories()
    {
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $categories = Category::getCategories($languageId, true, false);
        if (!is_array($categories)) {
            return null;
        }
        $payload = [];
        foreach ($categories as $category) {
            $payload[] = [
                'externalId' => (string) $category['id_category'],
                'parentExternalId' => !empty($category['id_parent']) ? (string) $category['id_parent'] : null,
                'name' => $category['name'],
                'description' => isset($category['description']) ? self::plain($category['description']) : '',
            ];
        }

        return $payload;
    }

    /**
     * One product, as sudemi stores it.
     *
     * Images are sent as absolute URLs, never as bytes, in the shop's order (the cover first).
     * Any public https address is accepted, a CDN included: sudemi fetches each one through its
     * egress guard (public addresses only) and shows it to buyers through its own signed image
     * proxy.
     */
    private static function productPayload($productId, $languageId)
    {
        $product = new Product($productId, true, $languageId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return null;
        }
        // Not orderable in the shop itself ("Available for order" off, a catalogue-only product).
        if (isset($product->available_for_order) && !$product->available_for_order) {
            SudemiIssues::add($productId, SudemiIssues::NOT_AVAILABLE_FOR_ORDER, 'error', 'available_for_order', 'Not listed: "Available for order" is off in the shop.');

            return null;
        }
        // A required file upload (a photo to print) cannot be asked for through sudemi.
        $fields = self::customizationFields($product, $languageId);
        if (!SudemiVariants::sellable($fields)) {
            SudemiIssues::add($productId, SudemiIssues::REQUIRED_FILE_UPLOAD, 'error', 'customization', 'Not listed: a required file upload cannot be asked for through sudemi.');

            return null;
        }

        $link = Context::getContext()->link;

        $images = [];
        foreach ($product->getImages($languageId) as $image) {
            $images[] = $link->getImageLink($product->link_rewrite, $productId . '-' . $image['id_image']);
        }

        $specs = [];
        foreach ($product->getFrontFeatures($languageId) as $feature) {
            if (!empty($feature['name'])) {
                $specs[] = [
                    'name' => (string) $feature['name'],
                    'values' => [(string) $feature['value']],
                ];
            }
        }

        $categories = array_map('strval', $product->getCategories());

        $payload = [
            'externalId' => (string) $product->id,
            'sku' => (string) $product->reference,
            'gtin' => ((string) $product->ean13) !== '' ? (string) $product->ean13 : (((string) $product->upc) !== '' ? (string) $product->upc : null),
            'mpn' => (string) $product->mpn,
            'title' => (string) $product->name,
            'description' => self::productDescription($product->description_short, $product->description),
            'url' => $link->getProductLink($product),
            'priceMinor' => self::minor(Product::getPriceStatic($productId, true)),
            'currency' => self::currency(),
            'inStock' => self::quantityOf($productId) > 0,
            'quantity' => self::quantityOf($productId),
            // Combinations (variants) override these three; see listings().
            'itemGroupId' => null,
            'variantAttributes' => [],
            // A text the buyer types: the product's text customization fields (SudemiVariants).
            'personalisation' => SudemiVariants::personalisation($fields, [], []),
            // The smallest quantity the shop sells in one order (PrestaShop's minimum quantity).
            'minQuantity' => max(1, (int) $product->minimal_quantity),
            'weightGrams' => SudemiShippingRules::toGrams((float) $product->weight, Configuration::get('PS_WEIGHT_UNIT')),
            'categoryExternalIds' => $categories,
            'images' => $images,
            'specs' => $specs,
            // Without a shipping price for the buyer's country sudemi cannot offer the product.
            'shipping' => SudemiShipping::ratesFor($product, Product::getPriceStatic($productId, true)),
            'maxHandlingTimeDays' => SudemiShipping::handlingDays(),
            'maxTransitTimeDays' => SudemiShipping::transitDays(),
            'brand' => $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : null,
        ];

        if ($payload['personalisation'] === null) {
            // More required texts than sudemi can ask: not sellable here (see SudemiVariants).
            SudemiIssues::add($productId, SudemiIssues::TOO_MANY_REQUIRED_TEXTS, 'error', 'customization', 'Not listed: more than ' . SudemiVariants::MAX_FIELDS . ' required texts for the buyer to type.');

            return null;
        }
        $texts = count(array_filter($fields, function ($f) {
            return (int) $f['type'] === SudemiVariants::FIELD_TEXT;
        }));
        if ($texts > SudemiVariants::MAX_FIELDS) {
            SudemiIssues::add($productId, SudemiIssues::TOO_MANY_OPTIONAL_TEXTS, 'warning', 'customization', 'Listed, but only ' . SudemiVariants::MAX_FIELDS . ' of ' . $texts . ' texts are asked (optional ones left out).');
        }
        if ((int) $payload['priceMinor'] < 1 && !$product->hasAttributes()) {
            // Never sold without a price (never free): not sent, and the shop is told why.
            // Combinations are checked one by one (listings()).
            SudemiIssues::add($productId, SudemiIssues::MISSING_PRICE, 'error', 'price', 'Not listed: the product has no price.');

            return null;
        }

        // The sale window, quantity prices, availability without stock, size, condition (v2).
        $payload = array_merge($payload, SudemiProductData::forListing($product, 0, $payload['quantity'], $payload['priceMinor'], function ($price) {
            return self::minor($price);
        }));

        // The shop accepts returns of personalised items of this product (owner decision 2):
        // false is sent too, so switching it off reaches sudemi. Combinations inherit it.
        $payload['personalisationReturnable'] = SudemiWithdrawal::isReturnable($productId);

        // Excluded from the right of withdrawal (EU 2011/83 art. 16), with the reason; omitted when
        // the normal right applies. Combinations are built from this payload, so they inherit it.
        return SudemiWithdrawal::apply($payload, SudemiWithdrawal::get($productId));
    }

    /**
     * What a product without combinations has in stock. A pack whose stock follows its items
     * has as many as its scarcest item allows (PrestaShop's own Pack::getQuantity).
     */
    private static function quantityOf($productId)
    {
        if (class_exists('Pack') && Pack::isPack((int) $productId)) {
            return (int) Pack::getQuantity((int) $productId);
        }

        return (int) StockAvailable::getQuantityAvailableByProduct($productId);
    }

    /**
     * Money in the shop currency's own minor units (no fixed x100: a zero-decimal currency has
     * none): a float price is a rounding error waiting for a checkout.
     */
    private static function minor($price)
    {
        return SudemiCurrency::toMinor($price, self::currency());
    }

    /** The currency this shop sells in: PrestaShop's default currency. */
    public static function currency()
    {
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

        return Validate::isLoadedObject($currency) ? $currency->iso_code : 'EUR';
    }

    private static function shippingCountries()
    {
        $countries = Country::getCountries((int) Configuration::get('PS_LANG_DEFAULT'), true);
        $codes = [];
        foreach ($countries as $country) {
            $codes[] = $country['iso_code'];
        }

        return $codes;
    }
}
