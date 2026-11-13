<?php

/**
 * sudemi > Products on sudemi (1.4.0): the shop's products as sudemi lists them, and the
 * shop's own on/off switch per product. Form posts are checked for permission and CSRF token.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiProductsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $pages = new SudemiAdminPages($this->module, $this->context);
        if (!$pages->canView(SudemiAdminPages::PRODUCTS)) {
            $this->errors[] = SudemiText::get($this->module, 'err_permission');
            parent::initContent();

            return;
        }
        $this->content = $pages->productsPage($_GET, Tools::isSubmit('sudemi_switch') ? $_POST : []);
        parent::initContent();
    }
}
