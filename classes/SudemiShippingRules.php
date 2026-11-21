<?php

/**
 * The shop's own PrestaShop shipping rules, sent to sudemi so a parcel of several items is charged
 * exactly as this shop's own checkout would charge it (POST /integration/v1/shipping/rules, core
 * 2026-11-21; owner: never undercharge the shop, never overcharge the buyer).
 *
 * Per destination country ONE carrier counts: of the active carriers chosen for sudemi
 * (SUDEMI_CARRIERS) that serve the country's zone, the one that is cheapest for a single item
 * (the price of its lightest or cheapest range, handling fee and tax included). Its rule:
 *
 * - a free carrier: baseMinor 0;
 * - by weight or by price: bands from the carrier's ranges for that zone (delimiter2 is the band's
 *   upper end, converted to grams with PS_WEIGHT_UNIT or to minor units of the shop currency);
 *   each band's price includes the shop's handling fee (PS_SHIPPING_HANDLING, when the carrier
 *   charges it) and the carrier's tax for the country, as the single-item prices do;
 * - out of range: "use the highest range" sends a last band without upper limit at the price
 *   PrestaShop then charges (the range with the highest upper limit), "disable the carrier" sends
 *   aboveLast "none";
 * - free shipping from a cart total (PS_SHIPPING_FREE_PRICE): freeOverMinor. PrestaShop compares
 *   the cart's goods with tax, shipping not counted, and frees from that amount on (>=), exactly
 *   what sudemi compares. Free shipping by weight (PS_SHIPPING_FREE_WEIGHT) cannot be said in a
 *   rule and is left out: it only ever makes shipping free, so leaving it out never undercharges
 *   the shop.
 *
 * PrestaShop ranges are half open, [delimiter1, delimiter2): a parcel of exactly delimiter2 pays
 * the next range. sudemi's bands are inclusive and counted in whole grams or minor units, so a
 * band ends at ceil(delimiter2) - 1 and the next one starts at ceil(delimiter2), the same split.
 * A gap between ranges (or ranges starting above 0) becomes a band of its own: PrestaShop charges
 * the price of the range with the highest upper limit there when the carrier uses the highest
 * range, which is copied exactly; a carrier that is disabled out of range does not deliver such a
 * parcel at all, so sudemi charges the carrier's highest price there and the shop is told
 * (shipping_rule_approximated). Product weights are sent rounded up to the gram (never lighter
 * than PrestaShop weighs them).
 *
 * What cannot be copied is never guessed lower: the highest rate that can be computed is sent and
 * reported (shipping_rule_approximated), and a country only a carrier module prices (live rates)
 * gets no rule and is reported (shipping_rate_calculated).
 *
 * The rules are built and sent in the background only (cron or after a back-office page was
 * sent): after every committed full sync, when a carrier, range, zone or shipping setting changed
 * (the hooks only set a flag), and at most once an hour when what they would say changed.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiShippingRules
{
    public const PATH = '/integration/v1/shipping/rules';

    /** Set by the hooks: rules to send on the next background run. */
    public const CONF_DUE = 'SUDEMI_RULES_DUE';
    /** Hash of the last payload sudemi took. */
    public const CONF_SENT = 'SUDEMI_RULES_SENT';
    /** When the rules were last built (for the hourly check). */
    public const CONF_CHECKED = 'SUDEMI_RULES_CHECKED';
    public const CHECK_EVERY = 3600;

    public const APPROXIMATED = 'shipping_rule_approximated';
    public const CALCULATED = 'shipping_rate_calculated';

    /** PrestaShop's out-of-range behaviour (OutOfRangeBehavior). */
    public const USE_HIGHEST_RANGE = 0;
    public const DISABLED = 1;

    /** Hooks that change a carrier's price after the ranges: the result cannot be read. */
    public const COST_HOOKS = ['actionDeliveryPriceByWeight', 'actionDeliveryPriceByPrice', 'actionCartGetPackageShippingCost'];
    public const FREE_PRICE_HOOK = 'actionOverrideShippingFreePrice';

    // ------------------------------------------------------------------ pure parts ----

    /** Grams in one PS_WEIGHT_UNIT, or null when the unit is not one we know. Pure. */
    public static function gramsPerUnit($unit)
    {
        $unit = strtolower(trim(str_replace('.', '', (string) $unit)));
        $units = [
            'kg' => 1000.0, 'kgs' => 1000.0, 'kilo' => 1000.0, 'kilos' => 1000.0, 'kilogram' => 1000.0, 'kilograms' => 1000.0, 'kilogramme' => 1000.0, 'kilogrammes' => 1000.0,
            'g' => 1.0, 'gr' => 1.0, 'grs' => 1.0, 'gram' => 1.0, 'grams' => 1.0, 'gramme' => 1.0, 'grammes' => 1.0,
            'lb' => 453.59237, 'lbs' => 453.59237, 'pound' => 453.59237, 'pounds' => 453.59237,
            'oz' => 28.349523125, 'ounce' => 28.349523125, 'ounces' => 28.349523125,
            't' => 1000000.0, 'mg' => 0.001,
        ];

        return isset($units[$unit]) ? $units[$unit] : null;
    }

    /**
     * A product weight in whole grams, rounded up (never lighter than PrestaShop weighs it, so a
     * parcel never lands in a cheaper weight band than PrestaShop's). An unknown unit is taken as
     * kilograms, as before 1.4.6. Pure.
     */
    public static function toGrams($weight, $unit)
    {
        $factor = self::gramsPerUnit($unit);
        $grams = (float) $weight * ($factor === null ? 1000.0 : $factor);

        return $grams <= 0 ? 0 : (int) ceil(round($grams, 6));
    }

    /** The smallest whole number not below $value * $scale (float noise removed). Pure. */
    private static function ceilScaled($value, $scale)
    {
        return (int) ceil(round((float) $value * $scale, 6));
    }

    /**
     * Bands from PrestaShop ranges [[delimiter1, delimiter2, price], ...] (price before handling
     * and tax), $scale whole units (grams, minor units) per range unit. Pure.
     *
     * @return array|null ['bands' => [['upTo' => int|null, 'price' => float]], 'aboveLast' => string,
     *                    'gaps' => bool], or null without a usable range
     */
    public static function bands(array $ranges, $scale, $behavior)
    {
        $rows = [];
        foreach ($ranges as $r) {
            $rows[] = ['lo' => self::ceilScaled($r[0], $scale), 'hi' => self::ceilScaled($r[1], $scale) - 1, 'd2' => (float) $r[1], 'price' => (float) $r[2]];
        }
        $rows = array_values(array_filter($rows, function ($r) {
            return $r['hi'] >= $r['lo'] && $r['hi'] >= 0;
        }));
        if (empty($rows)) {
            return null;
        }
        // PrestaShop takes the matching range with the lowest delimiter1; of two equal ones the
        // dearer is used here (PrestaShop's pick between them is the database's).
        usort($rows, function ($a, $b) {
            return $a['lo'] === $b['lo'] ? ($b['price'] <=> $a['price']) : ($a['lo'] <=> $b['lo']);
        });
        // Out of every range, PrestaShop charges the range with the highest delimiter2.
        $last = null;
        $highest = 0.0;
        foreach ($rows as $r) {
            if ($last === null || $r['d2'] > $last['d2'] || ($r['d2'] === $last['d2'] && $r['price'] > $last['price'])) {
                $last = $r;
            }
            $highest = max($highest, $r['price']);
        }
        $outside = (int) $behavior === self::USE_HIGHEST_RANGE ? $last['price'] : $highest;

        $bands = [];
        $gaps = false;
        $next = 0;
        foreach ($rows as $r) {
            if ($r['hi'] < $next) {
                continue;
            }
            if ($r['lo'] > $next) {
                $gaps = true;
                $bands[] = ['upTo' => $r['lo'] - 1, 'price' => $outside];
            }
            $bands[] = ['upTo' => $r['hi'], 'price' => $r['price']];
            $next = $r['hi'] + 1;
        }
        if ((int) $behavior === self::USE_HIGHEST_RANGE) {
            $bands[] = ['upTo' => null, 'price' => $last['price']];
        }

        // Neighbours at the same price are one band.
        $merged = [];
        foreach ($bands as $band) {
            $n = count($merged);
            if ($n > 0 && $merged[$n - 1]['price'] === $band['price']) {
                $merged[$n - 1]['upTo'] = $band['upTo'];
            } else {
                $merged[] = $band;
            }
        }

        return [
            'bands' => $merged,
            'aboveLast' => (int) $behavior === self::USE_HIGHEST_RANGE ? 'highest' : 'none',
            'gaps' => $gaps,
        ];
    }

    /** A carrier price (major units, before handling and tax) as buyers pay it, in minor units. Pure. */
    public static function priceMinor($price, $handling, $taxRate, $currency)
    {
        return max(0, (int) SudemiCurrency::toMinor(((float) $price + (float) $handling) * (1 + (float) $taxRate / 100), $currency));
    }

    /** PS_SHIPPING_FREE_PRICE in minor units (rounded up: never free below PrestaShop's amount), or null. Pure. */
    public static function freeOverMinor($freePrice, $currency)
    {
        if (!is_numeric($freePrice) || (float) $freePrice <= 0) {
            return null;
        }

        return self::ceilScaled($freePrice, pow(10, SudemiCurrency::decimals($currency)));
    }

    /**
     * One carrier's rule for one country. $carrier: ['name', 'reference', 'free' => bool,
     * 'basis' => 'weight'|'price', 'ranges' => [[d1, d2, price]], 'behavior' => 0|1,
     * 'handling' => float, 'taxRate' => float]. Pure.
     *
     * @return array|null ['rule' => [...], 'first' => int, 'highest' => int, 'approximated' => [reasons]],
     *                    or null when the carrier has no price for the zone
     */
    public static function carrierRule(array $carrier, $weightUnit, $currency)
    {
        if (!empty($carrier['free'])) {
            return ['rule' => ['baseMinor' => 0], 'first' => 0, 'highest' => 0, 'approximated' => []];
        }
        $basis = $carrier['basis'] === 'weight' ? 'weight' : 'price';
        $unknownUnit = false;
        if ($basis === 'weight') {
            $scale = self::gramsPerUnit($weightUnit);
            if ($scale === null) {
                $unknownUnit = true;
                $scale = 1000.0;
            }
        } else {
            $scale = pow(10, SudemiCurrency::decimals($currency));
        }
        $built = self::bands(isset($carrier['ranges']) ? $carrier['ranges'] : [], $scale, isset($carrier['behavior']) ? $carrier['behavior'] : 0);
        if ($built === null) {
            return null;
        }
        $bands = [];
        $highest = 0;
        foreach ($built['bands'] as $band) {
            $minor = self::priceMinor($band['price'], $carrier['handling'], $carrier['taxRate'], $currency);
            $bands[] = ['upTo' => $band['upTo'], 'priceMinor' => $minor];
            $highest = max($highest, $minor);
        }
        $approximated = [];
        if ($unknownUnit) {
            // Ranges in a unit we cannot turn into grams: every parcel pays the highest range.
            $approximated[] = "weight unit '" . (string) $weightUnit . "' unknown; highest range used";

            return ['rule' => ['baseMinor' => $highest], 'first' => $highest, 'highest' => $highest, 'approximated' => $approximated];
        }
        if ($built['gaps'] && (int) $carrier['behavior'] === self::DISABLED) {
            $approximated[] = 'ranges with gaps or not starting at 0 and the carrier disabled out of range; the highest range is charged in the gaps';
        }
        $rule = ['bandBasis' => $basis, 'bands' => $bands, 'aboveLast' => $built['aboveLast']];
        // One band without upper limit: one price for every parcel.
        if (count($bands) === 1 && $bands[0]['upTo'] === null) {
            $rule = ['baseMinor' => $bands[0]['priceMinor']];
        }

        return ['rule' => $rule, 'first' => $bands[0]['priceMinor'], 'highest' => $highest, 'approximated' => $approximated];
    }

    /**
     * The rule for one country from the carriers that deliver there (already filtered: active,
     * chosen for sudemi, serving the zone; 'external' => true for a carrier a module prices).
     * $restrictions: the carrier reference lists of products restricted to some carriers. Pure.
     *
     * @return array|null ['rule' => array|null, 'carrier' => string, 'approximated' => [reasons],
     *                    'calculated' => bool], or null when no carrier delivers there
     */
    public static function countryRule($iso, array $carriers, $weightUnit, $currency, $freeOverMinor, array $restrictions = [])
    {
        $external = [];
        $built = [];
        foreach ($carriers as $carrier) {
            if (!empty($carrier['external'])) {
                $external[] = (string) $carrier['name'];
                continue;
            }
            $one = self::carrierRule($carrier, $weightUnit, $currency);
            if ($one !== null) {
                $one['carrier'] = $carrier;
                $built[] = $one;
            }
        }
        if (empty($built)) {
            if (empty($external)) {
                return null;
            }

            // Only a carrier module prices this country (live rates): no price is invented.
            return ['rule' => null, 'carrier' => implode(', ', $external), 'approximated' => [], 'calculated' => true];
        }
        $pick = null;
        foreach ($built as $one) {
            if ($pick === null || $one['first'] < $pick['first']) {
                $pick = $one;
            }
        }
        $rule = $pick['rule'];
        $approximated = $pick['approximated'];
        $names = [(string) $pick['carrier']['name']];

        // A product only some carriers may ship, none of them this one: it would pay another
        // carrier's price, so the parcel pays the highest price of any carrier here.
        $references = array_map(function ($one) {
            return (int) $one['carrier']['reference'];
        }, $built);
        foreach ($restrictions as $allowed) {
            $allowed = array_map('intval', (array) $allowed);
            if (in_array((int) $pick['carrier']['reference'], $allowed, true) || empty(array_intersect($allowed, $references))) {
                continue;
            }
            $highest = 0;
            foreach ($built as $one) {
                $highest = max($highest, $one['highest']);
                if (in_array((int) $one['carrier']['reference'], $allowed, true)) {
                    $names[] = (string) $one['carrier']['name'];
                }
            }
            $rule = ['baseMinor' => $highest];
            $approximated[] = 'products restricted to other carriers than the cheapest; the highest carrier price is charged';
            break;
        }

        if ($freeOverMinor !== null && !(isset($rule['baseMinor']) && (int) $rule['baseMinor'] === 0)) {
            $rule['freeOverMinor'] = (int) $freeOverMinor;
        }

        return [
            'rule' => array_merge(['country' => (string) $iso], $rule),
            'carrier' => implode(', ', array_values(array_unique($names))),
            'approximated' => $approximated,
            'calculated' => false,
        ];
    }

    /**
     * The request body from the per-country results ([iso => countryRule()]) and the reasons
     * that hold for every country (a module overriding free shipping...). Pure.
     */
    public static function payload($currency, array $countries, array $shopWide = [])
    {
        ksort($countries);
        $rules = [];
        $approx = ['countries' => [], 'carriers' => [], 'reasons' => []];
        $calc = ['countries' => [], 'carriers' => []];
        foreach ($countries as $iso => $result) {
            if ($result === null) {
                continue;
            }
            if ($result['calculated']) {
                $calc['countries'][] = (string) $iso;
                $calc['carriers'][] = $result['carrier'];
                continue;
            }
            $rules[] = $result['rule'];
            $reasons = array_merge($result['approximated'], $shopWide);
            if (!empty($reasons)) {
                $approx['countries'][] = (string) $iso;
                $approx['carriers'][] = $result['carrier'];
                $approx['reasons'] = array_merge($approx['reasons'], $reasons);
            }
        }
        $issues = [];
        if (!empty($approx['countries'])) {
            $issues[] = self::issue(
                self::APPROXIMATED,
                'PrestaShop shipping rule cannot be copied exactly: ' . implode('; ', array_values(array_unique($approx['reasons']))),
                $approx['countries'],
                $approx['carriers']
            );
        }
        if (!empty($calc['countries'])) {
            $issues[] = self::issue(
                self::CALCULATED,
                'Shipping price computed by a carrier module at checkout (live rate); no rule sent, the products\' own rates apply',
                $calc['countries'],
                $calc['carriers']
            );
        }

        return ['currency' => (string) $currency, 'rules' => $rules, 'issues' => $issues];
    }

    private static function issue($code, $message, array $countries, array $carriers)
    {
        sort($countries);
        $names = [];
        foreach ($carriers as $list) {
            foreach (explode(', ', (string) $list) as $name) {
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
        $names = array_keys($names);
        sort($names);

        return [
            'code' => $code,
            'severity' => 'warning',
            'field' => 'shipping',
            'message' => $message,
            'details' => ['countries' => array_values(array_unique($countries)), 'carrier' => implode(', ', $names)],
        ];
    }

    // ------------------------------------------------------- reading PrestaShop ----

    /** The payload for this shop as its carriers stand now. */
    public static function build()
    {
        $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $currency = SudemiSync::currency();
        $weightUnit = (string) Configuration::get('PS_WEIGHT_UNIT');
        $handling = (float) Configuration::get('PS_SHIPPING_HANDLING');

        $chosen = SudemiShipping::chosenCarriers();
        $carriers = [];
        foreach (Carrier::getCarriers($languageId, true, false, false, null, Carrier::ALL_CARRIERS) as $row) {
            if ($chosen !== null && !in_array((int) $row['id_reference'], $chosen, true)) {
                continue;
            }
            $carrier = new Carrier((int) $row['id_carrier'], $languageId);
            if (Validate::isLoadedObject($carrier) && $carrier->active && !$carrier->deleted) {
                $carriers[] = $carrier;
            }
        }

        $shopWide = [];
        $freeOver = self::freeOverMinor(Configuration::get('PS_SHIPPING_FREE_PRICE'), $currency);
        if ($freeOver !== null && self::hooked(self::FREE_PRICE_HOOK)) {
            // A module sets its own free-shipping amount: left out (never free too early).
            $freeOver = null;
            $shopWide[] = 'a module overrides the free shipping amount (' . self::FREE_PRICE_HOOK . '); free shipping left out';
        }
        $costHooked = [];
        foreach (self::COST_HOOKS as $hook) {
            if (self::hooked($hook)) {
                $costHooked[] = $hook;
            }
        }
        if ((float) Configuration::get('PS_SHIPPING_FREE_WEIGHT') > 0) {
            SudemiLogger::info('Free shipping by weight (PS_SHIPPING_FREE_WEIGHT) cannot be sent to sudemi; buyers there pay the normal rate.');
        }
        $restrictions = self::restrictions();

        $results = [];
        foreach (SudemiShipping::MARKETS as $iso) {
            $countryId = (int) Country::getByIso($iso, true);
            if (!$countryId) {
                continue;
            }
            $zoneId = (int) Country::getIdZone($countryId);
            $address = new Address();
            $address->id_country = $countryId;
            $here = [];
            foreach ($carriers as $carrier) {
                if (!Carrier::checkCarrierZone((int) $carrier->id, $zoneId)) {
                    continue;
                }
                $here[] = self::describe($carrier, $zoneId, $address, $handling);
            }
            $result = self::countryRule($iso, $here, $weightUnit, $currency, $freeOver, $restrictions);
            if ($result !== null && !empty($costHooked) && !$result['calculated']) {
                // A module changes the carriers' prices after the ranges: unreadable, never guessed.
                $result = ['rule' => null, 'carrier' => $result['carrier'] . ' (' . implode(', ', $costHooked) . ')', 'approximated' => [], 'calculated' => true];
            }
            $results[$iso] = $result;
        }

        return self::payload($currency, $results, $shopWide);
    }

    /** A carrier as countryRule() takes it, for one zone and country. */
    private static function describe(Carrier $carrier, $zoneId, Address $address, $handling)
    {
        $out = [
            'name' => (string) $carrier->name,
            'reference' => (int) $carrier->id_reference,
            'external' => (bool) ($carrier->is_module && $carrier->shipping_external),
            'free' => false,
            'basis' => 'price',
            'ranges' => [],
            'behavior' => (int) $carrier->range_behavior,
            'handling' => $carrier->shipping_handling ? (float) $handling : 0.0,
            'taxRate' => 0.0,
        ];
        $method = $carrier->getShippingMethod();
        if ($carrier->is_free || $method == Carrier::SHIPPING_METHOD_FREE) {
            $out['free'] = true;

            return $out;
        }
        if ($out['external']) {
            return $out;
        }
        $table = $method == Carrier::SHIPPING_METHOD_WEIGHT ? 'range_weight' : 'range_price';
        $out['basis'] = $method == Carrier::SHIPPING_METHOD_WEIGHT ? 'weight' : 'price';
        $rows = Db::getInstance()->executeS(
            'SELECT r.delimiter1, r.delimiter2, d.price FROM ' . _DB_PREFIX_ . 'delivery d'
            . ' INNER JOIN ' . _DB_PREFIX_ . bqSQL($table) . ' r ON d.id_' . bqSQL($table) . ' = r.id_' . bqSQL($table)
            . ' WHERE d.id_zone = ' . (int) $zoneId . ' AND d.id_carrier = ' . (int) $carrier->id
            . ' ' . Carrier::sqlDeliveryRangeShop($table)
            . ' ORDER BY r.delimiter1 ASC'
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out['ranges'][] = [(float) $row['delimiter1'], (float) $row['delimiter2'], (float) $row['price']];
        }
        $out['taxRate'] = (float) $carrier->getTaxesRate($address);

        return $out;
    }

    /** The carrier reference lists of active products restricted to some carriers. */
    private static function restrictions()
    {
        $shopId = (int) Context::getContext()->shop->id;
        $rows = Db::getInstance()->executeS(
            'SELECT pc.id_product, pc.id_carrier_reference FROM ' . _DB_PREFIX_ . 'product_carrier pc'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = pc.id_product AND ps.id_shop = pc.id_shop'
            . ' WHERE pc.id_shop = ' . $shopId . ' AND ps.active = 1'
        );
        $byProduct = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $byProduct[(int) $row['id_product']][] = (int) $row['id_carrier_reference'];
        }
        $sets = [];
        foreach ($byProduct as $references) {
            sort($references);
            $sets[implode(',', $references)] = $references;
        }

        return array_values($sets);
    }

    /** Whether any module is hooked to $hook. */
    private static function hooked($hook)
    {
        if (!method_exists('Hook', 'getHookModuleExecList')) {
            return false;
        }
        $list = Hook::getHookModuleExecList($hook);

        return is_array($list) && !empty($list);
    }

    // ----------------------------------------------------------------- sending ----

    /** Asks for the rules to be sent on the next background run (hooks: never work inside a page request). */
    public static function request()
    {
        Configuration::updateValue(self::CONF_DUE, 1);
    }

    /**
     * Sends the rules when asked for, or (at most once an hour) when what they say changed.
     * Background only: the cron entry, a heartbeat after a back-office page was sent, a sync's commit.
     *
     * @return string 'idle', 'same', 'sent' or 'failed'
     */
    public static function sendIfDue()
    {
        if (!SudemiClient::isConnected()) {
            return 'idle';
        }
        $asked = (bool) Configuration::get(self::CONF_DUE);
        if (!$asked && time() - (int) Configuration::get(self::CONF_CHECKED) < self::CHECK_EVERY) {
            return 'idle';
        }
        // Cleared first: a change from here on asks again.
        Configuration::updateValue(self::CONF_DUE, 0);
        Configuration::updateValue(self::CONF_CHECKED, time());
        try {
            $payload = self::build();
        } catch (Throwable $e) {
            SudemiLogger::warn('Could not read the shipping rules: ' . $e->getMessage());

            return 'failed';
        }
        $hash = sha1(json_encode($payload));
        if (!$asked && $hash === (string) Configuration::get(self::CONF_SENT)) {
            return 'same';
        }
        $result = SudemiClient::postSigned(self::PATH, $payload);
        $status = is_array($result) ? (int) $result['status'] : 0;
        if ($status === 0 || $status === 429 || $status >= 500) {
            Configuration::updateValue(self::CONF_DUE, 1);
            SudemiLogger::warn('The shipping rules could not be sent; they are sent again on the next run.');

            return 'failed';
        }
        // Answered (also a refusal): not sent again until something changes.
        Configuration::updateValue(self::CONF_SENT, $hash);
        if ($status !== 200) {
            SudemiLogger::warn('sudemi refused the shipping rules (HTTP ' . $status . ').');

            return 'failed';
        }
        $body = isset($result['body']) && is_array($result['body']) ? $result['body'] : [];
        foreach (isset($body['ignored']) && is_array($body['ignored']) ? $body['ignored'] : [] as $ignored) {
            SudemiLogger::warn('sudemi ignored the shipping rule for ' . (isset($ignored['country']) ? (string) $ignored['country'] : '?')
                . (isset($ignored['reason']) ? ': ' . (string) $ignored['reason'] : '') . '.');
        }
        SudemiLogger::info('Shipping rules sent for ' . count($payload['rules']) . ' countries.');

        return 'sent';
    }

    public static function forget()
    {
        Configuration::deleteByName(self::CONF_DUE);
        Configuration::deleteByName(self::CONF_SENT);
        Configuration::deleteByName(self::CONF_CHECKED);
    }
}
