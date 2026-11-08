<?php
/**
 * Turning this shop's catalogue into what sudemi stores.
 *
 * A full sync walks every active product and ends with a commit, which is the only thing that
 * may delist: a sync that dies halfway is never committed, so a cron timeout cannot empty a
 * shop's listings. A delta carries one product's price or stock and runs from PrestaShop's own
 * update hooks.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiSync
{
    const BATCH_SIZE = 100;

    public static function runFull()
    {
        if (!SudemiClient::isConnected()) {
            return false;
        }

        $started = SudemiClient::postSigned('/integration/v1/sync', []);
        if ($started === null || $started['status'] !== 200 || empty($started['body']['sync_id'])) {
            SudemiLogger::warn('Could not start a catalogue sync.');

            return false;
        }
        $syncId = (string) $started['body']['sync_id'];
        // What the catalogue was when this sync started reading it; sent with the commit and in a
        // heartbeat right after it (SudemiHeartbeat::anchor), so sudemi holds the revision this
        // sync describes.
        $anchor = SudemiHeartbeat::revision();

        self::pushShop();
        self::pushCategories();

        // sudemi takes at most 500 listings per request, and a product with combinations is one
        // listing per combination: 100 products can be thousands of listings. Batching by
        // product sent more than 500 at once, sudemi refused the whole batch, and the full sync
        // never completed. So listings are buffered and sent 500 at a time.
        $limit = self::listingsPerRequest($started['body']);
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $page = 0;
        $sent = 0;
        $buffer = [];
        while (true) {
            $products = Product::getProducts($languageId, $page * self::BATCH_SIZE, self::BATCH_SIZE, 'id_product', 'ASC', false, true);
            if (empty($products)) {
                break;
            }
            foreach ($products as $row) {
                foreach (self::listings((int) $row['id_product'], $languageId) as $item) {
                    $buffer[] = $item;
                }
            }
            list($batches, $buffer) = self::chunkListings($buffer, $limit, false);
            foreach ($batches as $batch) {
                if (!self::sendBatch($syncId, $batch)) {
                    return false;
                }
                $sent += count($batch);
            }
            ++$page;
        }
        list($batches) = self::chunkListings($buffer, $limit, true);
        foreach ($batches as $batch) {
            if (!self::sendBatch($syncId, $batch)) {
                return false;
            }
            $sent += count($batch);
        }

        // The commit carries the revision the sync started from: sudemi adopts it, so anything
        // changed while the sync ran makes the next heartbeat disagree and is synced next time.
        $committed = SudemiClient::postSigned(
            '/integration/v1/sync/' . rawurlencode($syncId) . '/commit',
            $anchor === null ? [] : ['catalogueRevision' => $anchor]
        );
        if ($committed === null || $committed['status'] !== 200) {
            SudemiLogger::warn('The catalogue was sent but the sync could not be committed.');

            return false;
        }
        if (isset($committed['body']['status']) && $committed['body']['status'] === 'held') {
            // sudemi does not apply a commit that would delist a large share of the live
            // catalogue. Nothing was changed, so this is not a completed sync.
            $body = $committed['body'];
            SudemiLogger::warn('The catalogue sync is on hold: it would remove '
                . (isset($body['would_delist']) ? (int) $body['would_delist'] : 0) . ' of '
                . (isset($body['live_products']) ? (int) $body['live_products'] : 0)
                . ' listed products from sudemi, so nothing was changed. If those products really were removed from this shop, contact sudemi support (support@sudemi.com).');

            return false;
        }

        SudemiHeartbeat::anchor($anchor);
        SudemiLogger::info('Synced ' . $sent . ' products.');

        return true;
    }

    /** The most listings one /products request may carry. */
    const MAX_LISTINGS_PER_REQUEST = 500;

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

    private static function sendBatch($syncId, array $batch)
    {
        $result = SudemiClient::postSigned('/integration/v1/products', [
            'syncId' => $syncId,
            'products' => $batch,
        ]);
        if ($result === null || $result['status'] !== 200) {
            // Abandon rather than commit. An uncommitted sync delists nothing.
            SudemiLogger::warn('A batch failed; the sync was abandoned and nothing was delisted.');

            return false;
        }

        return true;
    }

    /**
     * One product's price and stock, as they change; per combination when it has combinations
     * (each is its own listing). Quiet on failure: a sudemi outage must not disturb the shop.
     *
     * @param int $productId
     * @param int|null $attributeId only this combination (stock moves name one), null for all
     */
    public static function pushDelta($productId, $attributeId = null)
    {
        if (!SudemiClient::isConnected()) {
            return;
        }
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $product = new Product((int) $productId, false, $languageId);
        if (!Validate::isLoadedObject($product)) {
            return;
        }

        $combinations = self::combinationIds($product);
        if (empty($combinations)) {
            // A product without combinations is one listing: sent whole, it can carry the
            // catalogue revision (see pushListing). Inactive, it has no listing and goes as a
            // price push below; its removal is a full sync's job, which the revision asks for.
            try {
                $listings = self::listings((int) $product->id, $languageId);
            } catch (\Throwable $e) {
                // PrestaShop prices a full product only with an employee or a cart in the
                // context; outside one (a script, an import) the save still sends price and stock.
                $listings = [];
            }
            if (count($listings) === 1) {
                self::pushListing($listings[0]);

                return;
            }
        }
        $targets = empty($combinations) ? [0] : ($attributeId ? [(int) $attributeId] : $combinations);
        $updates = [];
        foreach ($targets as $paid) {
            $quantity = (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, $paid ?: null);
            $updates[] = [
                'externalId' => self::externalId((int) $product->id, $paid),
                'priceMinor' => self::minor(Product::getPriceStatic((int) $product->id, true, $paid ?: null)),
                'currency' => self::currency(),
                'inStock' => $quantity > 0,
                'quantity' => $quantity,
            ];
        }

        // Counted before the attempt, not after a success: the counter is what lets sudemi
        // notice a change that never arrived, so it has to move even when the push fails.
        SudemiHeartbeat::noteChange();

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
     * Sends one product's whole listing as a change push, with the catalogue revision when
     * nothing else is waiting to be sent.
     *
     * A price push carries price and stock and nothing else, so sending the revision with it
     * would tell sudemi a renamed product had arrived when it had not; the whole listing carries
     * everything about the product. A product with combinations never comes here: a change to it
     * can add or remove combinations, which a push cannot delist.
     *
     * The revision is computed here, after PrestaShop saved the change and after the change
     * counter moved, exactly as the next heartbeat computes it. If every item applied, sudemi
     * adopts it (catalogue_revision_agreed) and no full sync follows the edit. With price changes
     * still queued it is left out: it would claim they had arrived.
     */
    private static function pushListing(array $listing)
    {
        $update = [
            'externalId' => $listing['externalId'],
            'priceMinor' => $listing['priceMinor'],
            'currency' => $listing['currency'],
            'inStock' => $listing['inStock'],
            'quantity' => $listing['quantity'],
        ];

        // Counted before the attempt, not after a success: the counter is what lets sudemi
        // notice a change that never arrived, so it has to move even when the push fails.
        SudemiHeartbeat::noteChange();
        // This push carries the product's newest state; an older queued price for it would
        // otherwise be sent after it and put the old price back.
        SudemiQueue::discard($update['externalId']);

        $body = ['products' => [$listing]];
        // A product moved into a category sudemi has not been sent would get past sudemi's
        // category checks until a full sync, which an agreed revision makes rare. So the category
        // tree is sent first when it changed, and without it there is no revision.
        if (SudemiQueue::count() === 0 && self::categoriesCurrent()) {
            $revision = SudemiHeartbeat::revision();
            if ($revision !== null) {
                $body['catalogueRevision'] = $revision;
            }
        }

        $response = SudemiClient::postSigned('/integration/v1/products', $body);
        if (!is_array($response) || $response['status'] !== 200) {
            SudemiQueue::enqueue($update);
            SudemiLogger::warn('A price update could not be sent; it is queued for retry.');
        }
    }

    /** A hash of the category tree last sent to sudemi, so a change can be noticed. */
    const CONF_CATEGORIES = 'SUDEMI_CATEGORIES_SENT';

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

    public static function forget()
    {
        Configuration::deleteByName(self::CONF_CATEGORIES);
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
     * reference, barcode and the combination's options in its title.
     */
    private static function listings($productId, $languageId)
    {
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
        $link = Context::getContext()->link;
        $listings = [];
        foreach ($byCombination as $paid => $attributes) {
            $first = $attributes[0];
            $item = $base;
            $item['externalId'] = self::externalId($productId, $paid);
            $item['title'] = $base['title'] . ' - ' . implode(', ', array_map(function ($a) {
                return (string) $a['attribute_name'];
            }, $attributes));
            $item['sku'] = ((string) $first['reference']) !== '' ? (string) $first['reference'] : $base['sku'];
            $gtin = ((string) $first['ean13']) !== '' ? (string) $first['ean13'] : (((string) $first['upc']) !== '' ? (string) $first['upc'] : null);
            // A combination is a different item from its siblings: never inherit the product's barcode.
            $item['gtin'] = $gtin;
            $price = Product::getPriceStatic($productId, true, $paid);
            $item['priceMinor'] = self::minor($price);
            $quantity = (int) StockAvailable::getQuantityAvailableByProduct($productId, $paid);
            $item['quantity'] = $quantity;
            $item['inStock'] = $quantity > 0;
            $item['weightGrams'] = (int) round(((float) $product->weight + (float) $first['weight']) * 1000);
            $item['url'] = $link->getProductLink($product, null, null, null, null, null, $paid);
            $item['specs'] = array_merge(array_map(function ($a) {
                return ['name' => (string) $a['group_name'], 'values' => [(string) $a['attribute_name']]];
            }, $attributes), $base['specs']);
            $item['shipping'] = SudemiShipping::ratesFor($product, $price);
            $listings[] = $item;
        }

        return $listings;
    }

    private static function pushShop()
    {
        SudemiClient::postSigned('/integration/v1/shop', [
            'displayName' => Configuration::get('PS_SHOP_NAME'),
            'description' => Configuration::get('PS_SHOP_NAME'),
            'languages' => [Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT'))],
            'currency' => self::currency(),
            'supportEmail' => Configuration::get('PS_SHOP_EMAIL'),
            'shipsTo' => self::shippingCountries(),
        ]);
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
                'description' => isset($category['description']) ? strip_tags((string) $category['description']) : '',
            ];
        }
        return $payload;
    }

    /**
     * One product, as sudemi stores it.
     *
     * Images are sent as URLs on this shop's own origin, never as bytes: sudemi fetches them
     * through its egress guard and refuses anything not on the verified shop, which stops a
     * payload walking it around an internal network one image at a time.
     */
    private static function productPayload($productId, $languageId)
    {
        $product = new Product($productId, true, $languageId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
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
            'description' => strip_tags((string) $product->description_short),
            'url' => $link->getProductLink($product),
            'priceMinor' => self::minor(Product::getPriceStatic($productId, true)),
            'currency' => self::currency(),
            'inStock' => (bool) (StockAvailable::getQuantityAvailableByProduct($productId) > 0),
            'quantity' => (int) StockAvailable::getQuantityAvailableByProduct($productId),
            'weightGrams' => (int) round((float) $product->weight * 1000),
            'categoryExternalIds' => $categories,
            'images' => $images,
            'specs' => $specs,
            // Without a shipping price for the buyer's country sudemi cannot offer the product.
            'shipping' => SudemiShipping::ratesFor($product, Product::getPriceStatic($productId, true)),
            'maxHandlingTimeDays' => SudemiShipping::handlingDays(),
            'maxTransitTimeDays' => SudemiShipping::transitDays(),
            'brand' => $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : null,
        ];

        // Excluded from the right of withdrawal (EU 2011/83 art. 16), with the reason; omitted when
        // the normal right applies. Combinations are built from this payload, so they inherit it.
        return SudemiWithdrawal::apply($payload, SudemiWithdrawal::get($productId));
    }

    /** Money in minor units: a float price is a rounding error waiting for a checkout. */
    private static function minor($price)
    {
        if ($price === null || $price === '') {
            return null;
        }

        return (int) round((float) $price * 100);
    }

    private static function currency()
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
