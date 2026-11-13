<?php
/**
 * sudemi > Shipping: what sudemi buyers pay for shipping per country (GET/POST
 * /integration/v1/shipping), filled in from this shop's carriers on request. The page itself is
 * SudemiAdminPages; this controller only routes.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiShippingController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $pages = new SudemiAdminPages($this->module, $this->context);
        if (!$pages->canView(SudemiAdminPages::SHIPPING)) {
            $this->errors[] = SudemiText::get($this->module, 'err_permission');
            parent::initContent();

            return;
        }
        $this->content = $pages->shippingPage($_GET, Tools::isSubmit('sudemi_ship_save') ? $_POST : []);
        parent::initContent();
    }
}
