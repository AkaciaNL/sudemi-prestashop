<?php

/**
 * sudemi > Support (1.4.0): tickets with sudemi support (before on the module page).
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiSupportController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $pages = new SudemiAdminPages($this->module, $this->context);
        if (!$pages->canView(SudemiAdminPages::SUPPORT)) {
            $this->errors[] = SudemiText::get($this->module, 'err_permission');
            parent::initContent();

            return;
        }
        /** @var Sudemi $module */
        $module = $this->module;
        $this->content = $module->supportPage();
        parent::initContent();
    }
}
