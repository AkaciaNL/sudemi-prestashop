<?php
/**
 * sudemi > Orders: the shop's orders as sudemi holds them (list, one order, bulk actions,
 * CSV export). The page itself is SudemiAdminPages; this controller only routes.
 *
 * PrestaShop checks the page token in the address before this runs (AdminController::init); every
 * form post is checked again for the employee's permission and the module's CSRF token.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSudemiOrdersController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $pages = new SudemiAdminPages($this->module, $this->context);
        if (!$pages->canView(SudemiAdminPages::ORDERS)) {
            $this->errors[] = SudemiText::get($this->module, 'err_permission');
            parent::initContent();

            return;
        }
        if (Tools::getValue('sudemi_export')) {
            $this->sendExport($pages);
        }
        $order = (string) Tools::getValue('sudemi_order');
        $post = Tools::isSubmit('sudemi_bulk') || Tools::isSubmit('sudemi_do') ? $_POST : [];
        $this->content = $order !== ''
            ? $pages->orderPage($order, $post)
            : $pages->ordersPage($_GET, $post);
        parent::initContent();
    }

    /** The CSV file, as sudemi wrote it; on a failure the list is shown with the reason. */
    private function sendExport(SudemiAdminPages $pages)
    {
        $result = $pages->export($_GET);
        if (!$result['ok']) {
            $this->errors[] = SudemiText::get($this->module, 'export_failed');

            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $result['csv'];
        exit;
    }
}
