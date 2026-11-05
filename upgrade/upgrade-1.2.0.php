<?php
/**
 * 1.2.0: shipping prices come from the carriers, so a carrier change must re-send the catalogue.
 * Existing installs register the new hooks here (install() only runs on a fresh install), and
 * the catalogue is sent once so every product gets its shipping prices and delivery times.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module)
{
    // moduleRoutes: the /.well-known/sudemi-challenge route was missing, so pairing could not finish.
    $ok = $module->registerHook('moduleRoutes')
        && $module->registerHook('actionCarrierUpdate')
        && $module->registerHook('actionObjectCarrierUpdateAfter');
    Configuration::updateValue('SUDEMI_RESYNC_DUE', 1);

    return $ok;
}
