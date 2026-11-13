<?php

/**
 * 1.2.8: a combination edited, added or deleted on its own form is sent to sudemi (with all the
 * product's combinations). Existing installs get the combination hooks here (install() only runs
 * on a fresh install).
 *
 * @author    Akacia
 * @copyright Akacia
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_8($module)
{
    return $module->registerCombinationHooks();
}
