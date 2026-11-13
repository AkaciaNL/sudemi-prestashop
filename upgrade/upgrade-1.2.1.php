<?php

/**
 * 1.2.1: refunds and cancellations made in the back office reach sudemi (which holds the buyer's
 * payment). Credit slips arrive through actionOrderSlipAdd, registered here for existing installs.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_1($module)
{
    return $module->registerHook('actionOrderSlipAdd');
}
