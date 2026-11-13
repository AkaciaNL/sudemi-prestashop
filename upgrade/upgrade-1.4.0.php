<?php
/**
 * 1.4.0: the sudemi menu in the left sidebar (Dashboard, Products on sudemi, Orders, Shipping
 * costs, Payouts, Shop profile, Help and support, Settings). The Orders and Shipping pages move
 * there from PrestaShop's own Orders and Shipping menus.
 *
 * Also repairs what a failed earlier upgrade left out: every tab and hook of the module is checked
 * and the missing ones added (a PrestaShop 9 shop upgraded to 1.3.0 had neither the Orders and
 * Shipping pages nor the tracking hooks: "The controller AdminSudemiOrders is missing or invalid").
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_0($module)
{
    SudemiOrders::ensureSchema();

    return $module->ensureAdminSetup(true);
}
