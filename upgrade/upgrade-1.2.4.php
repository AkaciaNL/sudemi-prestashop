<?php

/**
 * 1.2.4: signed calls carry Sudemi-Site, and the module page explains sudemi's 409 site_mismatch.
 * Nothing to migrate: no new table, hook or setting. Kept so every released version has its
 * upgrade step.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_4($module)
{
    return true;
}
