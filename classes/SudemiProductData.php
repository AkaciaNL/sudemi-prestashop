<?php

/**
 * Product data beyond title, price and stock (sudemi contract v2, 2026-11-19), from PrestaShop:
 * specific prices (quantity prices from a quantity, and a sale with a date window), what the
 * shop does when it has none left (out_of_stock: refuse, or take orders, with available_date and
 * the "available later" text), dimensions and condition.
 *
 * The pure parts can be tested without PrestaShop.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiProductData
{
    public const MAX_QUANTITY = 100000;
    public const MAX_TIERS = 20;
    public const MM_PER_UNIT = ['mm' => 1, 'cm' => 10, 'm' => 1000, 'in' => 25.4];

    /**
     * Stock and what happens without it. Pure.
     *
     * @param int $quantity what the shop has
     * @param bool $orderableWithout PrestaShop takes orders without stock (Product::isAvailableWhenOutOfStock)
     * @param string $availableDate the product's or combination's available_date (YYYY-MM-DD, or 0000-00-00)
     * @param string $laterText the "available later" text in the shop's language
     * @param string $today YYYY-MM-DD
     */
    public static function availability($quantity, $orderableWithout, $availableDate, $laterText, $today)
    {
        $out = ['allowBackorders' => (bool) $orderableWithout];
        if ((int) $quantity > 0) {
            $out['availability'] = 'in_stock';

            return $out;
        }
        if (!$orderableWithout) {
            $out['availability'] = 'out_of_stock';

            return $out;
        }
        $out['availability'] = 'backorder';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $availableDate) && (string) $availableDate > $today) {
            $out['availabilityDate'] = (string) $availableDate;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $laterText)));
        if ($text !== '') {
            $out['availabilityText'] = function_exists('mb_substr') ? mb_substr($text, 0, 100) : substr($text, 0, 100);
        }

        return $out;
    }

    /** Width, height, depth (length) in whole millimetres; 0 is not said. Pure. */
    public static function dimensions($width, $height, $depth, $unit)
    {
        $factor = isset(self::MM_PER_UNIT[strtolower((string) $unit)]) ? self::MM_PER_UNIT[strtolower((string) $unit)] : 10;
        $out = [];
        foreach (['lengthMm' => $depth, 'widthMm' => $width, 'heightMm' => $height] as $name => $value) {
            $mm = (int) round((float) $value * $factor);
            if ($mm >= 1 && $mm <= self::MAX_QUANTITY) {
                $out[$name] = $mm;
            }
        }

        return $out;
    }

    /** new, used or refurbished; null otherwise. Pure. */
    public static function condition($condition)
    {
        $condition = strtolower((string) $condition);

        return in_array($condition, ['new', 'used', 'refurbished'], true) ? $condition : null;
    }

    /** [{minQuantity, priceMinor}] from quantity => unit price minor, only below the price for one. Pure. */
    public static function tiers(array $prices, $unitMinor)
    {
        ksort($prices);
        $out = [];
        foreach ($prices as $quantity => $minor) {
            if ((int) $quantity < 2 || (int) $quantity > self::MAX_QUANTITY || $minor === null || (int) $minor < 1 || (int) $minor >= (int) $unitMinor) {
                continue;
            }
            $out[] = ['minQuantity' => (int) $quantity, 'priceMinor' => (int) $minor];
            if (count($out) >= self::MAX_TIERS) {
                break;
            }
        }

        return $out;
    }

    /**
     * A sale with a date window: regular and sale price (minor, with VAT) and its dates as
     * ISO-8601 with the shop's offset. PrestaShop's "to" is the last moment of the sale; sudemi's
     * until is exclusive: one second later. An ended sale, or one that is not cheaper, is not sent.
     * Pure.
     *
     * @param string|null $from 'Y-m-d H:i:s' in the shop's time zone, or null / 0000-00-00 00:00:00
     * @param string|null $to   likewise
     */
    public static function sale($regularMinor, $saleMinor, $from, $to, $timezone, $now)
    {
        if ($regularMinor === null || $saleMinor === null || (int) $saleMinor < 1 || (int) $saleMinor >= (int) $regularMinor) {
            return [];
        }
        $zone = new DateTimeZone($timezone ?: 'UTC');
        $out = ['regularPriceMinor' => (int) $regularMinor, 'salePriceMinor' => (int) $saleMinor];
        if (self::isDate($from)) {
            $out['salePriceFrom'] = (new DateTime((string) $from, $zone))->format('c');
        }
        if (self::isDate($to)) {
            $until = (new DateTime((string) $to, $zone))->modify('+1 second');
            if ($until->getTimestamp() <= (int) $now) {
                return [];
            }
            $out['salePriceUntil'] = $until->format('c');
        }

        return $out;
    }

    /**
     * The sale price of a reduction not running yet, from the regular price. Pure.
     *
     * @param float $reduction a fraction for "percentage", an amount in minor units for "amount"
     */
    public static function reduced($regularMinor, $regularExclMinor, $type, $reduction, $reductionWithTax)
    {
        if ($type === 'percentage') {
            return (int) round($regularMinor * (1 - (float) $reduction));
        }
        $amount = (float) $reduction;
        if (!$reductionWithTax && $regularExclMinor > 0) {
            $amount = $amount * $regularMinor / $regularExclMinor;
        }

        return (int) round($regularMinor - $amount);
    }

    public static function isDate($value)
    {
        return is_string($value) && $value !== '' && strpos($value, '0000-00-00') !== 0;
    }

    /** The fields a price-only update carries along with the price (so it never ends a sale). Pure. */
    public static function priceUpdateFields(array $listing)
    {
        $out = [];
        foreach (['regularPriceMinor', 'salePriceMinor', 'salePriceFrom', 'salePriceUntil', 'availability', 'allowBackorders'] as $name) {
            if (array_key_exists($name, $listing)) {
                $out[$name] = $listing[$name];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------- PrestaShop side ----

    /**
     * The v2 fields for a product (combination 0) or a combination.
     *
     * @param Product $product loaded in the shop's language
     */
    public static function forListing(Product $product, $attributeId, $quantity, $unitMinor, callable $minor)
    {
        $id = (int) $product->id;
        $out = [];
        $outOfStock = StockAvailable::outOfStock($id);
        $date = (string) $product->available_date;
        if ($attributeId) {
            $combination = new Combination((int) $attributeId);
            if (Validate::isLoadedObject($combination) && self::isDate((string) $combination->available_date)) {
                $date = (string) $combination->available_date;
            }
        }
        $later = is_array($product->available_later) ? (string) reset($product->available_later) : (string) $product->available_later;
        $out = array_merge($out, self::availability($quantity, (bool) Product::isAvailableWhenOutOfStock($outOfStock), $date, $later, substr(self::now(), 0, 10)));
        $out = array_merge($out, self::dimensions($product->width, $product->height, $product->depth, Configuration::get('PS_DIMENSION_UNIT')));
        $condition = self::condition($product->condition);
        if ($condition !== null) {
            $out['condition'] = $condition;
        }

        // Quantity prices: from each quantity a specific price starts at, the unit price of the
        // row PrestaShop itself would pick for a guest (priority order as
        // SpecificPrice::getSpecificPrice), sent when below the regular price (sudemi charges the
        // lower of a running sale and it).
        $regular = self::regular($product, $attributeId, $minor);
        $rows = self::rowsFor($id, $attributeId);
        $now = self::now();
        $prices = [];
        foreach ($rows as $row) {
            $from = (int) $row['from_quantity'];
            if ($from > 1 && !isset($prices[$from])) {
                $winner = self::winner($rows, $from, $now);
                if ($winner !== null) {
                    $prices[$from] = self::rowPrice($winner, $regular, $product, $minor);
                }
            }
        }
        $tiers = self::tiers($prices, $regular[0]);
        if (!empty($tiers)) {
            $out['quantityPrices'] = $tiers;
        }

        return array_merge($out, self::saleFor($product, $attributeId, $regular, $minor));
    }

    /**
     * The regular price of an item, [with VAT, without VAT] in minor units: the product's price
     * plus the combination's impact, without any specific price (a specific price can replace
     * the base price, so PrestaShop's own price without reductions is not the regular price).
     */
    private static function regular(Product $product, $attributeId, callable $minor)
    {
        // The stored price: a Product loaded "full" holds the price after specific prices.
        $stored = Db::getInstance()->getValue('SELECT price FROM ' . _DB_PREFIX_ . 'product_shop WHERE id_product = ' . (int) $product->id
            . ' AND id_shop = ' . (int) Context::getContext()->shop->id);
        $excl = $stored !== false && $stored !== null ? (float) $stored : (float) $product->price;
        if ($attributeId) {
            $combination = new Combination((int) $attributeId);
            if (Validate::isLoadedObject($combination)) {
                $excl += (float) $combination->price;
            }
        }
        $rate = Configuration::get('PS_TAX') ? (float) Tax::getProductTaxRate((int) $product->id) : 0.0;

        return [(int) $minor($excl * (1 + $rate / 100)), (int) $minor($excl), $rate];
    }

    /** The unit price one specific price row gives, in minor units with VAT. */
    private static function rowPrice(array $row, array $regular, Product $product, callable $minor)
    {
        list($incl, $excl, $rate) = $regular;
        if ((float) $row['price'] >= 0) {
            $excl = (int) $minor((float) $row['price']);
            $incl = (int) $minor((float) $row['price'] * (1 + $rate / 100));
        }
        if ((float) $row['reduction'] <= 0) {
            return $incl;
        }
        $reduction = (string) $row['reduction_type'] === 'percentage' ? (float) $row['reduction'] : (float) $minor((float) $row['reduction']);

        return self::reduced($incl, $excl, (string) $row['reduction_type'], $reduction, (bool) $row['reduction_tax']);
    }

    /**
     * The sale for one item: the dated specific price PrestaShop applies for one item now, else
     * the next dated one to start (same scope and priority order).
     */
    private static function saleFor(Product $product, $attributeId, array $regular, callable $minor)
    {
        $rows = self::rowsFor((int) $product->id, $attributeId);
        $now = self::now();
        $running = self::winner($rows, 1, $now);
        $sale = null;
        if ($running !== null) {
            if (!self::isDate($running['from']) && !self::isDate($running['to'])) {
                // An undated reduction is simply the shop's price.
                return [];
            }
            $sale = $running;
        } else {
            $next = null;
            foreach ($rows as $row) {
                if ((int) $row['from_quantity'] <= 1 && self::isDate($row['from']) && $row['from'] > $now
                    && ($next === null || $row['from'] < $next['from'])) {
                    $next = $row;
                }
            }
            if ($next !== null) {
                // What PrestaShop will pick at that moment.
                $sale = self::winner($rows, 1, $next['from']);
            }
        }
        if ($sale === null || (!self::isDate($sale['from']) && !self::isDate($sale['to']))) {
            return [];
        }

        return self::sale($regular[0], self::rowPrice($sale, $regular, $product, $minor), $sale['from'], $sale['to'], self::zone(), time());
    }

    /** The shop's time zone: PrestaShop stores specific price dates in it. */
    private static function zone()
    {
        return (string) Configuration::get('PS_TIMEZONE') ?: 'UTC';
    }

    /** Now, as PrestaShop compares specific price dates ('Y-m-d H:i:s' in PS_TIMEZONE). */
    private static function now()
    {
        return (new DateTime('now', new DateTimeZone(self::zone())))->format('Y-m-d H:i:s');
    }

    /**
     * The row PrestaShop applies for this quantity at this moment, or null. Pure: the same
     * filter and order as SpecificPrice::getSpecificPrice (attribute rows first, then the
     * highest starting quantity, catalogue rules after, then the priority score, then the
     * latest end and start).
     */
    public static function winner(array $rows, $quantity, $at)
    {
        $candidates = array_values(array_filter($rows, function ($row) use ($quantity, $at) {
            return ((int) $row['from_quantity'] > 1 ? (int) $row['from_quantity'] : 0) <= (int) $quantity
                && (!self::isDate($row['from']) || $row['from'] <= $at)
                && (!self::isDate($row['to']) || $row['to'] >= $at);
        }));
        usort($candidates, function ($a, $b) {
            foreach ([['id_product_attribute', -1], ['from_quantity', -1], ['id_specific_price_rule', 1], ['score', -1], ['to', -1], ['from', -1]] as $order) {
                list($field, $dir) = $order;
                $x = isset($a[$field]) ? $a[$field] : 0;
                $y = isset($b[$field]) ? $b[$field] : 0;
                if ($x != $y) {
                    return ($x < $y ? -1 : 1) * $dir;
                }
            }

            return 0;
        });

        return empty($candidates) ? null : $candidates[0];
    }

    /**
     * PrestaShop's score of a row for a guest: for each field of the shop's priority order that
     * the row names (shop, currency, country, group, customer), 2^(position from the end + 1).
     * Pure.
     *
     * @param array $priority e.g. ['id_customer', 'id_shop', 'id_currency', 'id_country', 'id_group']
     * @param array $values   field => the guest's value
     */
    public static function score(array $row, array $priority, array $values)
    {
        $score = 0;
        foreach (array_values(array_reverse($priority)) as $k => $field) {
            if ($field !== '' && isset($values[$field]) && isset($row[$field]) && (int) $row[$field] === (int) $values[$field]) {
                $score += 2 ** ($k + 1);
            }
        }

        return $score;
    }

    /** Rows for one item (the product's and the combination's). */
    private static function rowsFor($productId, $attributeId)
    {
        return array_values(array_filter(self::specificPrices($productId), function ($row) use ($attributeId) {
            return (int) $row['id_product_attribute'] === 0 || (int) $row['id_product_attribute'] === (int) $attributeId;
        }));
    }

    /** Rows of one product read in this request (forListing runs once per combination). */
    private static $rows = [];

    /** Forgets the rows read (a new sync, or a price changed since). */
    public static function reset()
    {
        self::$rows = [];
    }

    /**
     * The specific prices a guest gets (what sudemi's buyers are): customer 0, no cart, group 0
     * or the guest group (never a customers-only price), country and currency 0 or the shop's,
     * shop 0 or this one, as SpecificPrice::getSpecificPrice selects them; with their score.
     */
    private static function specificPrices($productId)
    {
        if (isset(self::$rows[(int) $productId])) {
            return self::$rows[(int) $productId];
        }
        $context = Context::getContext();
        $values = [
            'id_shop' => (int) $context->shop->id,
            'id_currency' => (int) Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_country' => (int) Configuration::get('PS_COUNTRY_DEFAULT'),
            'id_group' => (int) Configuration::get('PS_UNIDENTIFIED_GROUP'),
            'id_customer' => 0,
        ];
        $rows = Db::getInstance()->executeS(
            'SELECT id_specific_price, id_specific_price_rule, id_product_attribute, id_shop, id_currency, id_country, id_group, id_customer,
                    from_quantity, `from`, `to`, price, reduction, reduction_type, reduction_tax FROM ' . _DB_PREFIX_ . 'specific_price
              WHERE id_product IN (0, ' . (int) $productId . ')
                AND id_customer = 0 AND id_cart = 0
                AND id_shop IN (0, ' . $values['id_shop'] . ')
                AND id_group IN (0, ' . $values['id_group'] . ')
                AND id_country IN (0, ' . $values['id_country'] . ')
                AND id_currency IN (0, ' . $values['id_currency'] . ')'
        );
        $priority = is_callable(['SpecificPrice', 'getPriority']) ? (array) SpecificPrice::getPriority((int) $productId) : ['id_shop', 'id_currency', 'id_country', 'id_group'];
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $row['score'] = self::score($row, $priority, $values);
            $out[] = $row;
        }

        return self::$rows[(int) $productId] = $out;
    }
}
