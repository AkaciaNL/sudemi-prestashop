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
        $out = array_merge($out, self::availability($quantity, (bool) Product::isAvailableWhenOutOfStock($outOfStock), $date, $later, date('Y-m-d')));
        $out = array_merge($out, self::dimensions($product->width, $product->height, $product->depth, Configuration::get('PS_DIMENSION_UNIT')));
        $condition = self::condition($product->condition);
        if ($condition !== null) {
            $out['condition'] = $condition;
        }

        // Quantity prices: the price per unit PrestaShop charges from each quantity on.
        $prices = [];
        foreach (self::specificPrices($id, $attributeId) as $row) {
            $from = (int) $row['from_quantity'];
            if ($from > 1 && self::runsNow($row)) {
                $prices[$from] = $minor(Product::getPriceStatic($id, true, $attributeId ?: null, 6, null, false, true, $from));
            }
        }
        $tiers = self::tiers($prices, $unitMinor);
        if (!empty($tiers)) {
            $out['quantityPrices'] = $tiers;
        }

        return array_merge($out, self::saleFor($product, $attributeId, $unitMinor, $minor));
    }

    /** A dated reduction for one item: running now (the price PrestaShop charges) or coming. */
    private static function saleFor(Product $product, $attributeId, $unitMinor, callable $minor)
    {
        $id = (int) $product->id;
        $now = date('Y-m-d H:i:s');
        $best = null;
        foreach (self::specificPrices($id, $attributeId) as $row) {
            if ((int) $row['from_quantity'] > 1 || (!self::isDate($row['from']) && !self::isDate($row['to']))) {
                continue;
            }
            if (self::isDate($row['to']) && $row['to'] < $now) {
                continue;
            }
            // The running one first, else the next to start.
            if ($best === null || (self::runsNow($row) && !self::runsNow($best))
                || (!self::runsNow($row) && !self::runsNow($best) && (string) $row['from'] < (string) $best['from'])) {
                $best = $row;
            }
        }
        if ($best === null) {
            return [];
        }
        $regular = $minor(Product::getPriceStatic($id, true, $attributeId ?: null, 6, null, false, false));
        if (self::runsNow($best)) {
            $sale = $unitMinor;
        } elseif ((float) $best['price'] < 0) {
            $regularExcl = $minor(Product::getPriceStatic($id, false, $attributeId ?: null, 6, null, false, false));
            $reduction = (string) $best['reduction_type'] === 'percentage' ? (float) $best['reduction'] : (float) $minor((float) $best['reduction']);
            $sale = self::reduced($regular, $regularExcl, (string) $best['reduction_type'], $reduction, (bool) $best['reduction_tax']);
        } else {
            // A fixed price that has not started: only PrestaShop knows its tax and currency.
            return [];
        }

        return self::sale($regular, $sale, $best['from'], $best['to'], Configuration::get('PS_TIMEZONE'), time());
    }

    private static function runsNow(array $row)
    {
        $now = date('Y-m-d H:i:s');

        return (!self::isDate($row['from']) || $row['from'] <= $now) && (!self::isDate($row['to']) || $row['to'] >= $now);
    }

    /** Specific prices for every customer of the shop's default group, country and currency. */
    private static function specificPrices($productId, $attributeId)
    {
        $context = Context::getContext();
        $rows = Db::getInstance()->executeS(
            'SELECT from_quantity, `from`, `to`, price, reduction, reduction_type, reduction_tax FROM ' . _DB_PREFIX_ . 'specific_price
              WHERE id_product = ' . (int) $productId . '
                AND id_product_attribute IN (0, ' . (int) $attributeId . ')
                AND id_customer = 0 AND id_cart = 0
                AND id_shop IN (0, ' . (int) $context->shop->id . ')
                AND id_group IN (0, ' . (int) Configuration::get('PS_UNIDENTIFIED_GROUP') . ', ' . (int) Configuration::get('PS_CUSTOMER_GROUP') . ')
                AND id_country IN (0, ' . (int) Configuration::get('PS_COUNTRY_DEFAULT') . ')
                AND id_currency IN (0, ' . (int) Configuration::get('PS_CURRENCY_DEFAULT') . ')'
        );

        return is_array($rows) ? $rows : [];
    }
}
