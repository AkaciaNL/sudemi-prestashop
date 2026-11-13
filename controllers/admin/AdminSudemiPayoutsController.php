<?php

/**
 * sudemi > Payouts (1.4.0): what sudemi paid this shop and what is still due (read only).
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiPayoutsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $pages = new SudemiAdminPages($this->module, $this->context);
        if (!$pages->canView(SudemiAdminPages::PAYOUTS)) {
            $this->errors[] = SudemiText::get($this->module, 'err_permission');
            parent::initContent();

            return;
        }
        $this->content = $pages->payoutsPage();
        parent::initContent();
    }
}
