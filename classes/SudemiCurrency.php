<?php

/**
 * The shop's currency as sudemi sees it, and money in a currency's own minor units.
 *
 * A shop sells in one currency (protocol section 4c): the buyer's card is charged in it and the
 * orders sudemi sends carry it. Pure, so it can be tested without PrestaShop.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiCurrency
{
    /** The currency sudemi answered on POST /shop (its echo of what this shop declared). */
    public const CONF_SHOP_CURRENCY = 'SUDEMI_SHOP_CURRENCY';

    /** Shop currencies sudemi takes: EUR and every ECB currency except ISK (owner 2026-11-09, protocol 4c). */
    public const SUPPORTED = ['EUR', 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'GBP', 'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KRW', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR'];

    /** ISO 4217 currencies with no minor unit. */
    public const ZERO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** ISO 4217 currencies with three decimals. */
    public const THREE_DECIMALS = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** Upper-case three-letter code, or '' for anything that is not one. */
    public static function normalise($code)
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : '';
    }

    public static function isSupported($code)
    {
        return in_array(self::normalise($code), self::SUPPORTED, true);
    }

    /** Decimals of the currency's minor unit as ISO 4217 defines them (HUF counts as 2, as sudemi does). */
    public static function decimals($code)
    {
        $code = self::normalise($code);
        if (in_array($code, self::ZERO_DECIMALS, true)) {
            return 0;
        }
        if (in_array($code, self::THREE_DECIMALS, true)) {
            return 3;
        }

        return 2;
    }

    /** A major amount (12.34) in minor units (1234), rounded; null stays null. */
    public static function toMinor($amount, $code)
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return (int) round((float) $amount * pow(10, self::decimals($code)));
    }

    /** Minor units back to a major amount. */
    public static function fromMinor($minor, $code)
    {
        return ((int) $minor) / pow(10, self::decimals($code));
    }

    /** "12.34 PLN", "1500 JPY": an amount with its currency, for the back office. */
    public static function format($minor, $code)
    {
        $code = self::normalise($code);
        $decimals = self::decimals($code);

        return number_format(self::fromMinor($minor, $code), $decimals, '.', '') . ($code !== '' ? ' ' . $code : '');
    }

    /**
     * Why a pending order cannot be booked in this shop, or null when it can.
     *
     * Old cores send no currency_code: null, and the order is booked as before. The reasons go
     * to sudemi as the decline reason, so they are plain words.
     *
     * @param string $orderCode the order's currency_code
     * @param string $shopCode the currency this shop sells in (its default currency)
     * @param bool $activeInShop whether PrestaShop has that currency installed and switched on
     */
    public static function orderProblem($orderCode, $shopCode, $activeInShop)
    {
        if ($orderCode === null || trim((string) $orderCode) === '') {
            return null;
        }
        $order = self::normalise($orderCode);
        if ($order === '') {
            return 'the order has no recognisable currency';
        }
        $shop = self::normalise($shopCode);
        if ($shop !== '' && $order !== $shop) {
            return sprintf('the order is in %s, but this shop sells in %s', $order, $shop);
        }
        if (!$activeInShop) {
            return sprintf('the currency %s is not switched on in this shop', $order);
        }

        return null;
    }

    /**
     * The body of POST /orders/{id}/refund, or the reason it must not be sent.
     *
     * A refund is in the order's currency. When the shop's refund is in another currency than
     * the one sudemi booked the order in, the amount would mean something else on sudemi's side,
     * so nothing is sent.
     *
     * @param int|null $amountMinor null = refund the rest
     * @param string $reason
     * @param string $refundCode the currency of the shop's refund (the local order's)
     * @param string|null $bookedCode the currency sudemi booked the order in; null when not
     *                                known (an order from before 1.2.12)
     *
     * @return array ['body' => array] or ['error' => string]
     */
    public static function refundBody($amountMinor, $reason, $refundCode, $bookedCode)
    {
        $refund = self::normalise($refundCode);
        $booked = self::normalise($bookedCode);
        if ($booked !== '' && $refund !== $booked) {
            return ['error' => sprintf(
                'this refund is in %s, but the buyer paid in %s. Nothing was sent to sudemi and the buyer has NOT been paid back; refund in %s, or contact sudemi support (support@sudemi.com).',
                $refund !== '' ? $refund : 'an unknown currency',
                $booked,
                $booked
            )];
        }
        $body = ['reason' => $reason];
        if ($amountMinor !== null) {
            $body['amountMinor'] = (int) $amountMinor;
        }
        $currency = $booked !== '' ? $booked : $refund;
        if ($currency !== '') {
            $body['currency'] = $currency;
        }

        return ['body' => $body];
    }

    /** The currency sudemi has for this shop: its answer on POST /shop, else what the shop declares. */
    public static function seenBySudemi($declared)
    {
        $echo = self::normalise(Configuration::get(self::CONF_SHOP_CURRENCY));

        return $echo !== '' ? $echo : self::normalise($declared);
    }

    /** Keeps sudemi's answer to POST /shop: the currency it echoes, nothing when it refused. */
    public static function rememberShopAnswer($response, $declared)
    {
        if (!is_array($response) || !isset($response['status'])) {
            return;
        }
        $status = (int) $response['status'];
        if ($status === 200 && isset($response['body']['currency'])) {
            Configuration::updateValue(self::CONF_SHOP_CURRENCY, self::normalise($response['body']['currency']));
        } elseif ($status === 400) {
            // Refused (an unsupported currency): sudemi kept whatever it had, which this module
            // cannot know, so the declared one is shown together with the warning.
            Configuration::deleteByName(self::CONF_SHOP_CURRENCY);
            SudemiLogger::warn(sprintf(
                'sudemi refused this shop\'s currency %s; supported: %s.',
                self::normalise($declared),
                implode(', ', self::SUPPORTED)
            ));
        }
    }
}
