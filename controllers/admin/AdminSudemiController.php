<?php
/**
 * The sudemi entry of the left sidebar (1.4.0). Opening it shows the Dashboard.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function init()
    {
        parent::init();
        Tools::redirectAdmin((new SudemiAdminPages($this->module, $this->context))->url(SudemiAdminPages::DASHBOARD));
    }
}
