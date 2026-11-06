<?php
/**
 * 1.2.2: products can be marked as excluded from the right of withdrawal (Directive 2011/83/EU,
 * art. 16). Existing installs get the table and the product-page hooks here (install() only runs
 * on a fresh install).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_2($module)
{
    return SudemiWithdrawal::installTable() && $module->registerWithdrawalHooks();
}
