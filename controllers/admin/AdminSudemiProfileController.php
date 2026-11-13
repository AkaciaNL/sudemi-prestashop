<?php

/**
 * sudemi > Shop profile (1.4.0): what buyers see about this shop. The name is changed by
 * sudemi support only; the description, support e-mail and returns window are saved here.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiProfileController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $pages = new SudemiAdminPages($this->module, $this->context);
        if (!$pages->canView(SudemiAdminPages::PROFILE)) {
            $this->errors[] = SudemiText::get($this->module, 'err_permission');
            parent::initContent();

            return;
        }
        $this->content = $pages->profilePage(Tools::isSubmit('sudemi_profile_save') ? $_POST : [], $pages->url(SudemiAdminPages::SUPPORT, ['sudemi_rename' => 1]));
        parent::initContent();
    }
}
