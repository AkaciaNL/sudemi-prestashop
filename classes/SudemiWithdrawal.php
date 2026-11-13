<?php

/**
 * Products excluded from the buyer's right of withdrawal (Directive 2011/83/EU, art. 16).
 *
 * The merchant marks a product with the legal reason it is excluded; sudemi tells the buyer
 * before they pay. Nothing is excluded by default, and only the reasons the directive itself
 * lists can be chosen: a buyer told "no withdrawal" without a lawful reason keeps the right
 * anyway, so a free-text reason would only mislead.
 *
 * Stored in the module's own table, one row per excluded product (no row: the normal right
 * applies). A combination has no setting of its own: it is the same product in another size or
 * colour, so it carries the product's.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiWithdrawal
{
    public const FIELD = 'sudemi_withdrawal_exclusion';
    /** The "none" choice in the product form; never stored and never sent. */
    public const NONE = 'none';

    /** The art. 16 exceptions sudemi accepts, as sent in the product payload. */
    public const REASONS = ['hygiene', 'custom', 'perishable', 'media', 'press', 'mixed', 'alcohol'];

    /**
     * One of REASONS, or null for "normal right of withdrawal". Anything else is null: an unknown
     * value must never turn into an exclusion.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    public static function sanitize($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, self::REASONS, true) ? $value : null;
    }

    /**
     * The product payload with the exclusion added when there is one; without the key otherwise.
     *
     * @param array $payload one product, as sent to /integration/v1/products
     * @param string|null $reason the stored value (sanitised again here)
     *
     * @return array
     */
    public static function apply(array $payload, $reason)
    {
        $reason = self::sanitize($reason);
        if ($reason !== null) {
            $payload['withdrawalExclusion'] = $reason;
        }

        return $payload;
    }

    public static function installTable()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'sudemi_product` (
                `id_product` INT UNSIGNED NOT NULL,
                `withdrawal_exclusion` VARCHAR(16) NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_product`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function uninstallTable()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'sudemi_product`');
    }

    /** @return string|null the product's exclusion, null when the normal right applies */
    public static function get($productId)
    {
        $value = Db::getInstance()->getValue(
            'SELECT withdrawal_exclusion FROM `' . _DB_PREFIX_ . 'sudemi_product`
              WHERE id_product = ' . (int) $productId
        );

        return self::sanitize($value);
    }

    /**
     * Stores the exclusion (null removes it).
     *
     * @return bool whether the stored value changed
     */
    public static function set($productId, $reason)
    {
        $productId = (int) $productId;
        $reason = self::sanitize($reason);
        if ($productId <= 0 || self::get($productId) === $reason) {
            return false;
        }
        if ($reason === null) {
            Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'sudemi_product` WHERE id_product = ' . $productId);
        } else {
            Db::getInstance()->execute(
                'REPLACE INTO `' . _DB_PREFIX_ . 'sudemi_product` (id_product, withdrawal_exclusion, date_upd)
                 VALUES (' . $productId . ", '" . pSQL($reason) . "', NOW())"
            );
        }

        return true;
    }

    public static function forget($productId)
    {
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'sudemi_product` WHERE id_product = ' . (int) $productId);
    }
}
