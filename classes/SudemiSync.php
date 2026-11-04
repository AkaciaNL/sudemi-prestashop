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

        self::pushShop();
        self::pushCategories();

        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $page = 0;
        $sent = 0;
        while (true) {
            $products = Product::getProducts($languageId, $page * self::BATCH_SIZE, self::BATCH_SIZE, 'id_product', 'ASC', false, true);
            if (empty($products)) {
                break;
            }
            $payload = [];
            foreach ($products as $row) {
                $item = self::productPayload((int) $row['id_product'], $languageId);
                if ($item !== null) {
                    $payload[] = $item;
                }
            }
            if (!empty($payload)) {
                $result = SudemiClient::postSigned('/integration/v1/products', [
                    'syncId' => $syncId,
                    'products' => $payload,
                ]);
                if ($result === null || $result['status'] !== 200) {
                    // Abandon rather than commit. An uncommitted sync delists nothing.
                    SudemiLogger::warn('A batch failed; the sync was abandoned and nothing was delisted.');

                    return false;
                }
                $sent += count($payload);
            }
            ++$page;
        }

        $committed = SudemiClient::postSigned('/integration/v1/sync/' . rawurlencode($syncId) . '/commit', []);
        if ($committed === null || $committed['status'] !== 200) {
            SudemiLogger::warn('The catalogue was sent but the sync could not be committed.');

            return false;
        }

        SudemiLogger::info('Synced ' . $sent . ' products.');

        return true;
    }

    /** One product's price and stock, as they change. Quiet on failure: a sudemi outage must not disturb the shop. */
    public static function pushDelta($productId)
    {
        if (!SudemiClient::isConnected()) {
            return;
        }
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $product = new Product((int) $productId, false, $languageId);
        if (!Validate::isLoadedObject($product)) {
            return;
        }

        $quantity = (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id);
        $update = [
            'externalId' => (string) $product->id,
            'priceMinor' => self::minor(Product::getPriceStatic((int) $product->id, true)),
            'currency' => self::currency(),
            'inStock' => $quantity > 0,
            'quantity' => $quantity,
        ];

        // Counted before the attempt, not after a success: the counter is what lets sudemi
        // notice a change that never arrived, so it has to move even when the push fails.
        SudemiHeartbeat::noteChange();

        $response = SudemiClient::postSigned('/integration/v1/products/prices', ['updates' => [$update]]);

        // A dropped change is a wrong price on a marketplace until the next full sync, so a
        // failure is written down rather than logged and forgotten. The heartbeat drains it.
        if (!is_array($response) || $response['status'] !== 200) {
            SudemiQueue::enqueue($update);
            SudemiLogger::warn('A price update could not be sent; it is queued for retry.');
        }
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
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $categories = Category::getCategories($languageId, true, false);
        if (!is_array($categories)) {
            return;
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
        if (!empty($payload)) {
            SudemiClient::postSigned('/integration/v1/categories', ['categories' => $payload]);
        }
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

        return [
            'externalId' => (string) $product->id,
            'sku' => (string) $product->reference,
            'gtin' => (string) $product->ean13,
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
        ];
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
