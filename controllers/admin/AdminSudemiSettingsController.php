<?php

/**
 * sudemi > Settings (1.4.0): the module's configuration page.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiSettingsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function init()
    {
        parent::init();
        Tools::redirectAdmin((new SudemiAdminPages($this->module, $this->context))->settingsUrl());
    }
}
