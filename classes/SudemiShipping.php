<?php

/**
 * What a product costs to ship to each country, read from this shop's own PrestaShop carriers,
 * plus the delivery times the merchant set for sudemi.
 *
 * sudemi shows buyers the total including shipping and refuses a checkout to a country with no
 * shipping price, so a product without rates is never offered. Per destination country the
 * cheapest carrier that this shop's own checkout would offer for one item counts:
 *
 * - only active carriers that serve the country's zone, and only the product's own carriers when
 *   it is restricted to some;
 * - free carriers cost 0; otherwise the carrier's price or weight range for that zone (out of
 *   range: the highest range when the carrier says so, otherwise the carrier is skipped);
 * - plus the shop's handling fee when the carrier charges it, plus the product's additional
 *   shipping cost; then the carrier's tax for that country, because buyers see prices with tax;
 *   the additional shipping cost (with tax) also goes as perUnitMinor (1.4.6): PrestaShop charges
 *   it for every unit, so sudemi adds it per unit on top of the shop's parcel rule
 *   (SudemiShippingRules);
 * - a combination weighs the product's weight plus its own (as PrestaShop's cart weighs it);
 * - carriers whose price a module computes at checkout (is_module with external shipping cost)
 *   are skipped rather than guessed: a wrong shipping price is a wrong total shown to a buyer.
 *
 * Free shipping from a cart total (PS_SHIPPING_FREE_PRICE) depends on the cart, so it is not a
 * fixed price for one item and is ignored here; it goes in the shop's parcel rules
 * (SudemiShippingRules).
 *
 * @author    Akacia
 * @copyright Akacia
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiShipping
{
    public const CONF_HANDLING_DAYS = 'SUDEMI_HANDLING_DAYS';
    public const CONF_TRANSIT_DAYS = 'SUDEMI_TRANSIT_DAYS';
    /** JSON list of carrier reference ids that deliver sudemi orders; unset = every active carrier. */
    public const CONF_CARRIERS = 'SUDEMI_CARRIERS';

    /** Conservative until the merchant sets their own (shown and editable on the module page). */
    public const DEFAULT_HANDLING_DAYS = 2;
    public const DEFAULT_TRANSIT_DAYS = 5;

    /** sudemi's buyer markets: EU, EEA, Switzerland, United Kingdom, Turkiye. */
    public const MARKETS = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'NO', 'IS', 'LI', 'CH', 'GB', 'TR',
    ];

    public static function handlingDays()
    {
        return self::days(self::CONF_HANDLING_DAYS, self::DEFAULT_HANDLING_DAYS);
    }

    public static function transitDays()
    {
        return self::days(self::CONF_TRANSIT_DAYS, self::DEFAULT_TRANSIT_DAYS);
    }

    public static function saveDays($handling, $transit)
    {
        foreach ([self::CONF_HANDLING_DAYS => $handling, self::CONF_TRANSIT_DAYS => $transit] as $key => $value) {
            if (is_numeric($value)) {
                Configuration::updateValue($key, max(0, min(60, (int) $value)));
            }
        }
    }

    /** Carrier reference ids chosen for sudemi, or null for "all active carriers". */
    public static function chosenCarriers()
    {
        $raw = Configuration::get(self::CONF_CARRIERS);
        if ($raw === false || $raw === '') {
            return null;
        }
        $ids = json_decode((string) $raw, true);

        return is_array($ids) ? array_map('intval', $ids) : null;
    }

    public static function saveCarriers($ids)
    {
        Configuration::updateValue(self::CONF_CARRIERS, json_encode(array_values(array_map('intval', (array) $ids))));
    }

    /** Active carriers, for the module page: [id_reference => name]. */
    public static function activeCarriers()
    {
        $out = [];
        foreach (Carrier::getCarriers((int) Configuration::get('PS_LANG_DEFAULT'), true, false, false, null, Carrier::ALL_CARRIERS) as $row) {
            $out[(int) $row['id_reference']] = (string) $row['name'];
        }

        return $out;
    }

    /** The carrier put on sudemi orders: the first chosen for sudemi, else the first active one. */
    public static function deliveryCarrierId()
    {
        $chosen = self::chosenCarriers();
        foreach (Carrier::getCarriers((int) Configuration::get('PS_LANG_DEFAULT'), true, false, false, null, Carrier::ALL_CARRIERS) as $row) {
            if ($chosen === null || in_array((int) $row['id_reference'], $chosen, true)) {
                return (int) $row['id_carrier'];
            }
        }

        return (int) Configuration::get('PS_CARRIER_DEFAULT');
    }

    private static function days($key, $default)
    {
        $value = Configuration::get($key);

        return ($value === false || $value === '' || !is_numeric($value)) ? $default : max(0, min(60, (int) $value));
    }

    /**
     * @param float $extraWeight a combination's own weight, added to the product's
     *
     * @return array<int, array{country: string, costMinor: int, perUnitMinor?: int}>
     */
    public static function ratesFor(Product $product, $priceTaxIncl, $extraWeight = 0.0)
    {
        $rates = [];
        $currency = SudemiSync::currency();
        foreach (self::cheapestPerCountry($product, $priceTaxIncl, $extraWeight) as $iso => $pick) {
            $rates[] = self::entry($iso, $pick['cost'], $pick['perUnit'], $currency);
        }

        return $rates;
    }

    /** One shipping entry: what one item costs, and the part PrestaShop charges per unit. Pure. */
    public static function entry($iso, $cost, $perUnit, $currency)
    {
        $entry = ['country' => (string) $iso, 'costMinor' => SudemiCurrency::toMinor($cost, $currency)];
        $perUnitMinor = (int) SudemiCurrency::toMinor($perUnit, $currency);
        if ($perUnitMinor > 0) {
            $entry['perUnitMinor'] = min($perUnitMinor, (int) $entry['costMinor']);
        }

        return $entry;
    }

    /** The same, with the carrier that gives each price (for the module page). */
    public static function preview(Product $product, $priceTaxIncl)
    {
        $out = [];
        foreach (self::cheapestPerCountry($product, $priceTaxIncl) as $iso => $pick) {
            $out[] = ['country' => $iso, 'costMinor' => SudemiCurrency::toMinor($pick['cost'], SudemiSync::currency()), 'carrier' => $pick['carrier']];
        }

        return $out;
    }

    private static function cheapestPerCountry(Product $product, $priceTaxIncl, $extraWeight = 0.0)
    {
        if ($product->is_virtual) {
            return [];
        }
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');

        $carriers = [];
        $restricted = $product->getCarriers();
        if (!empty($restricted)) {
            foreach ($restricted as $row) {
                $carriers[] = new Carrier((int) $row['id_carrier'], $languageId);
            }
        } else {
            foreach (Carrier::getCarriers($languageId, true, false, false, null, Carrier::ALL_CARRIERS) as $row) {
                $carriers[] = new Carrier((int) $row['id_carrier'], $languageId);
            }
        }

        $chosen = self::chosenCarriers();
        if ($chosen !== null) {
            $carriers = array_values(array_filter($carriers, function ($c) use ($chosen) {
                return in_array((int) $c->id_reference, $chosen, true);
            }));
        }

        $picks = [];
        foreach (self::MARKETS as $iso) {
            $countryId = (int) Country::getByIso($iso, true);
            if (!$countryId) {
                continue;
            }
            $zoneId = (int) Country::getIdZone($countryId);
            $best = null;
            foreach ($carriers as $carrier) {
                $cost = self::costFor($carrier, $product, $priceTaxIncl, $countryId, $zoneId, $currencyId, $extraWeight);
                if ($cost !== null && ($best === null || $cost['cost'] < $best['cost'])) {
                    $best = ['cost' => $cost['cost'], 'perUnit' => $cost['perUnit'], 'carrier' => (string) $carrier->name];
                }
            }
            if ($best !== null) {
                $picks[$iso] = $best;
            }
        }

        return $picks;
    }

    /** @return array{cost: float, perUnit: float}|null with tax; perUnit is the per-unit part of cost */
    private static function costFor(Carrier $carrier, Product $product, $priceTaxIncl, $countryId, $zoneId, $currencyId, $extraWeight = 0.0)
    {
        if (!Validate::isLoadedObject($carrier) || !$carrier->active || $carrier->deleted) {
            return null;
        }
        if ($carrier->is_module && $carrier->shipping_external) {
            return null;
        }
        if (!Carrier::checkCarrierZone((int) $carrier->id, $zoneId)) {
            return null;
        }
        if ($carrier->is_free) {
            return ['cost' => 0.0, 'perUnit' => 0.0];
        }

        $method = $carrier->getShippingMethod();
        if ($method == Carrier::SHIPPING_METHOD_FREE) {
            return ['cost' => 0.0, 'perUnit' => 0.0];
        }
        if ($method == Carrier::SHIPPING_METHOD_WEIGHT) {
            $weight = (float) $product->weight + (float) $extraWeight;
            $cost = $carrier->getDeliveryPriceByWeight($weight, $zoneId);
            if ($cost === false && !$carrier->range_behavior) {
                $cost = $carrier->getMaxDeliveryPriceByWeight($zoneId);
            }
        } else {
            $cost = $carrier->getDeliveryPriceByPrice($priceTaxIncl, $zoneId, $currencyId);
            if ($cost === false && !$carrier->range_behavior) {
                $cost = $carrier->getMaxDeliveryPriceByPrice($zoneId);
            }
        }
        if ($cost === false || $cost === null) {
            return null;
        }
        $cost = (float) $cost;
        if ($carrier->shipping_handling) {
            $cost += (float) Configuration::get('PS_SHIPPING_HANDLING');
        }
        $perUnit = max(0.0, (float) $product->additional_shipping_cost);
        $cost += $perUnit;

        $address = new Address();
        $address->id_country = $countryId;
        $rate = (float) $carrier->getTaxesRate($address);

        return ['cost' => $cost * (1 + $rate / 100), 'perUnit' => $perUnit * (1 + $rate / 100)];
    }
}
