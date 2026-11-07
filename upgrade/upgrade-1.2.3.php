<?php
/**
 * 1.2.3: a Support section on the module page (tickets with sudemi support). Nothing to migrate:
 * no new table, hook or setting. Kept so every released version has its upgrade step.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_3($module)
{
    return true;
}
