<?php

/**
 * 1.2.5: buyers' return requests are shown on the order page and on the module page, and are
 * answered from there. Existing installs get the order-page hooks here (install() only runs on a
 * fresh install). The open requests themselves arrive with the next heartbeat.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_5($module)
{
    return $module->registerOrderPageHooks();
}
