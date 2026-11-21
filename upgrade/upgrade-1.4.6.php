<?php

/**
 * 1.4.6: the shop's own shipping rules go to sudemi (POST /integration/v1/shipping/rules), so a
 * parcel of several items is charged as this shop's checkout charges it. Registers the hooks that
 * send the rules again when a carrier, a range, a zone or the shipping preferences change, and
 * asks for the first send (and a full sync, for the per-unit shipping charge and the weights).
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_6($module)
{
    SudemiShippingRules::request();
    SudemiSync::request();

    return $module->registerShippingRuleHooks();
}
