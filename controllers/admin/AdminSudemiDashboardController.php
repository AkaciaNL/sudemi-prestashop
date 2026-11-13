<?php

/**
 * sudemi > Dashboard (1.4.0): statistics from sudemi for this shop. The page itself is
 * SudemiAdminPages; this controller only routes.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiDashboardController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $pages = new SudemiAdminPages($this->module, $this->context);
        if (!$pages->canView(SudemiAdminPages::DASHBOARD)) {
            $this->errors[] = SudemiText::get($this->module, 'err_permission');
            parent::initContent();

            return;
        }
        $this->content = $pages->dashboardPage($_GET);
        parent::initContent();
    }
}
