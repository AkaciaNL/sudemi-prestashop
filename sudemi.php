<?php
/**
 * sudemi Connector for PrestaShop.
 *
 * Lists this shop's catalogue on sudemi so an AI assistant can find the products and send
 * buyers here. The shop remains the merchant of record.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

define('SUDEMI_VERSION', '1.1.0');

require_once __DIR__ . '/classes/SudemiLogger.php';
require_once __DIR__ . '/classes/SudemiKeys.php';
require_once __DIR__ . '/classes/SudemiClient.php';
require_once __DIR__ . '/classes/SudemiPairing.php';
require_once __DIR__ . '/classes/SudemiQueue.php';
require_once __DIR__ . '/classes/SudemiSync.php';
require_once __DIR__ . '/classes/SudemiOrders.php';
require_once __DIR__ . '/classes/SudemiHeartbeat.php';

class Sudemi extends Module
{
    public function __construct()
    {
        $this->name = 'sudemi';
        $this->tab = 'market_place';
        $this->version = SUDEMI_VERSION;
        $this->author = 'Akacia';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('sudemi Connector');
        $this->description = $this->l('List your catalogue on sudemi, so an AI assistant can find your products and send buyers to you.');
        $this->confirmUninstall = $this->l('This removes the signing key and disconnects the shop from sudemi. Continue?');
    }

    public function install()
    {
        return parent::install()
            && SudemiOrders::installTable()
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionUpdateQuantity')
            // Back-office page loads are the fallback beat for shops with no cron entry. The
            // hook is admin-only by definition, so a storefront request never pays for it.
            && $this->registerHook('actionAdminControllerSetMedia')
            // Dispatch and delivery, reported back so sudemi can tell the buyer. Hooked to the
            // shop's own status changes rather than asking the merchant for an extra step: a
            // step that has to be remembered is a step that gets skipped.
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall()
    {
        // A signing key left on a shop that believes it has removed the module is a credential
        // nobody is watching, so it goes — and sudemi is told, best effort.
        if (SudemiClient::isConnected()) {
            SudemiClient::postSigned('/integration/v1/credentials/revoke', ['reason' => 'uninstalled']);
        }
        SudemiKeys::forget();
        SudemiPairing::forget();
        SudemiOrders::uninstallTable();
        SudemiLogger::clear();
        SudemiHeartbeat::forget();
        // Price changes that never got through go with the credential that would have signed
        // them: leaving them would mean a reinstall pushing stale prices.
        SudemiQueue::forget();
        Configuration::deleteByName(SudemiClient::CONF_INSTALL_ID);
        Configuration::deleteByName(SudemiPairing::CONF_CHALLENGE);

        return parent::uninstall();
    }

    public function hookActionProductUpdate($params)
    {
        if (!empty($params['id_product'])) {
            SudemiSync::pushDelta((int) $params['id_product']);
        }
    }

    public function hookActionUpdateQuantity($params)
    {
        if (!empty($params['id_product'])) {
            SudemiSync::pushDelta((int) $params['id_product']);
        }
    }

    /**
     * The fallback beat, for a shop whose host has no cron entry for us.
     *
     * Every back-office page load asks whether a beat is due, and sends one if it is. It is not
     * the supported path — a shop nobody logs into would go quiet and have its listings paused,
     * correctly — but it means the common case of "installed the module, never set up cron"
     * keeps working instead of silently failing a week later.
     *
     * Guarded by the interval so one admin session does not send a beat per page, and never
     * attached to a storefront request: a buyer's page load must not wait on our network.
     */
    /**
     * Reports dispatch and delivery to sudemi when the merchant moves an order on.
     *
     * A buyer who is not told their order shipped assumes it did not, and then asks for a refund
     * for something already in the post. Delivery matters more: the right of withdrawal runs
     * from the day the goods arrive, so this call is what starts the clock on the buyer's
     * statutory rights.
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }
        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        // Most of a shop's orders are not sudemi's, and this hook sees all of them.
        if (SudemiOrders::sudemiIdOf($order) === null) {
            return;
        }

        $status = $params['newOrderStatus'];
        $event = null;
        if (!empty($status->shipped)) {
            $event = 'shipped';
        } elseif (!empty($status->delivery)) {
            $event = 'delivered';
        }
        if ($event === null) {
            return;
        }
        SudemiOrders::report($order, $event);
    }

    public function hookActionAdminControllerSetMedia()
    {
        if (!SudemiClient::isConnected() || !SudemiHeartbeat::isDue()) {
            return;
        }
        SudemiHeartbeat::run();
    }

    /**
     * The configuration screen.
     *
     * Every action checks the employee's permission and a CSRF token before it does anything,
     * and everything printed is escaped. An admin screen is where a module usually gives away
     * more than it meant to.
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('sudemi_connect')) {
            $output .= $this->guard('sudemi_connect');
            if ($output === '') {
                $result = SudemiPairing::start();
                $output .= $result['ok']
                    ? $this->displayConfirmation(Tools::htmlentitiesUTF8($result['message']))
                    : $this->displayError(Tools::htmlentitiesUTF8($result['message']));
            }
        }

        if (Tools::isSubmit('sudemi_check')) {
            $output .= $this->guard('sudemi_check');
            if ($output === '') {
                $output .= SudemiPairing::poll()
                    ? $this->displayConfirmation($this->l('Connected to sudemi.'))
                    : $this->displayWarning($this->l('Not confirmed yet. Open the link, confirm the shop on sudemi, then check again.'));
            }
        }

        if (Tools::isSubmit('sudemi_sync')) {
            $output .= $this->guard('sudemi_sync');
            if ($output === '') {
                $output .= SudemiSync::runFull()
                    ? $this->displayConfirmation($this->l('The catalogue was sent.'))
                    : $this->displayError($this->l('The catalogue could not be sent. Nothing was delisted.'));
            }
        }

        if (Tools::isSubmit('sudemi_disconnect')) {
            $output .= $this->guard('sudemi_disconnect');
            if ($output === '') {
                SudemiKeys::forget();
                SudemiPairing::forget();
                SudemiHeartbeat::forget();
                SudemiQueue::forget();
                Configuration::deleteByName(SudemiClient::CONF_INSTALL_ID);
                Configuration::deleteByName(SudemiPairing::CONF_CHALLENGE);
                $output .= $this->displayConfirmation($this->l('Disconnected.'));
            }
        }

        return $output . $this->renderScreen();
    }

    /** Permission, then CSRF token, on every action without exception. */
    private function guard($action)
    {
        if (!$this->context->employee || !Access::isGranted('ROLE_MOD_TAB_ADMINMODULES_UPDATE', $this->context->employee->id_profile)) {
            return $this->displayError($this->l('You do not have permission to change this.'));
        }
        if (!Tools::getValue('sudemi_token') || !hash_equals($this->csrfToken(), (string) Tools::getValue('sudemi_token'))) {
            return $this->displayError($this->l('That request could not be verified. Try again from this page.'));
        }

        return '';
    }

    private function csrfToken()
    {
        return Tools::getAdminTokenLite('AdminModules') . '|' . $this->name;
    }

    private function renderScreen()
    {
        $connected = SudemiClient::isConnected();
        $claimUrl = SudemiPairing::claimUrl();
        $supported = SudemiKeys::isSupported();

        $status = SudemiHeartbeat::status();

        $this->context->smarty->assign([
            'sudemi_connected' => $connected,
            'sudemi_status' => $status,
            'sudemi_pending' => SudemiQueue::count(),
            // Printed for the shop owner to paste into their crontab. The token is in it, so
            // the template must not leak it anywhere a non-employee can read.
            'sudemi_cron_url' => $this->context->link->getModuleLink(
                'sudemi',
                'cron',
                ['token' => SudemiHeartbeat::cronToken()],
                true
            ),
            'sudemi_supported' => $supported,
            'sudemi_claim_url' => $claimUrl,
            'sudemi_shop_url' => SudemiPairing::shopUrl(),
            'sudemi_install_id' => SudemiClient::installId(),
            'sudemi_token' => $this->csrfToken(),
            'sudemi_log' => SudemiLogger::entries(),
            'sudemi_action' => $_SERVER['REQUEST_URI'],
        ]);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }
}
