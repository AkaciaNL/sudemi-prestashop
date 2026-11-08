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

define('SUDEMI_VERSION', '1.2.9');

require_once __DIR__ . '/classes/SudemiLogger.php';
require_once __DIR__ . '/classes/SudemiKeys.php';
require_once __DIR__ . '/classes/SudemiClient.php';
require_once __DIR__ . '/classes/SudemiPairing.php';
require_once __DIR__ . '/classes/SudemiQueue.php';
require_once __DIR__ . '/classes/SudemiShipping.php';
require_once __DIR__ . '/classes/SudemiWithdrawal.php';
require_once __DIR__ . '/classes/SudemiSync.php';
require_once __DIR__ . '/classes/SudemiOrders.php';
require_once __DIR__ . '/classes/SudemiHeartbeat.php';
require_once __DIR__ . '/classes/SudemiSupport.php';
require_once __DIR__ . '/classes/SudemiRequests.php';

class Sudemi extends Module
{
    /** The ticket to show in the Support section after an action (created or replied to). */
    private $supportTicket;

    /** Set once a ticket or reply went through, so the form is shown empty again. */
    private $supportSent = false;
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
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('moduleRoutes')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('actionCarrierUpdate')
            && $this->registerHook('actionObjectCarrierUpdateAfter')
            && SudemiWithdrawal::installTable()
            && $this->registerWithdrawalHooks()
            && $this->registerOrderPageHooks()
            && $this->registerCombinationHooks();
    }

    /**
     * A combination edited, added or deleted on its own (its own form in PrestaShop 8/9 does not
     * fire actionProductUpdate). Shared with upgrade-1.2.8.php, which registers them on
     * existing installs.
     */
    public function registerCombinationHooks()
    {
        return $this->registerHook('actionObjectCombinationAddAfter')
            && $this->registerHook('actionObjectCombinationUpdateAfter')
            && $this->registerHook('actionObjectCombinationDeleteAfter');
    }

    public function hookActionObjectCombinationAddAfter($params)
    {
        $this->combinationChanged($params, false);
    }

    public function hookActionObjectCombinationUpdateAfter($params)
    {
        $this->combinationChanged($params, false);
    }

    /** A deleted combination stays listed on sudemi until a full sync's commit removes it. */
    public function hookActionObjectCombinationDeleteAfter($params)
    {
        $this->combinationChanged($params, true);
    }

    private function combinationChanged($params, $deleted)
    {
        $combination = isset($params['object']) ? $params['object'] : null;
        if (!is_object($combination) || empty($combination->id_product)) {
            return;
        }
        if ($deleted) {
            SudemiSync::noteRemoval();
        }
        SudemiSync::pushDelta((int) $combination->id_product);
    }

    /**
     * The return-request block on the back-office order page: displayAdminOrderMain on the
     * PrestaShop 1.7.7+ order page, displayAdminOrder on the older one. Shared with
     * upgrade-1.2.5.php, which registers them on existing installs.
     */
    public function registerOrderPageHooks()
    {
        return $this->registerHook('displayAdminOrderMain')
            && $this->registerHook('displayAdminOrder');
    }

    /**
     * The right-of-withdrawal field on the product page (PrestaShop 8.1+/9 product form) and its
     * save. Shared with upgrade-1.2.2.php, which registers them on existing installs.
     */
    public function registerWithdrawalHooks()
    {
        return $this->registerHook('actionProductFormBuilderModifier')
            && $this->registerHook('actionAfterUpdateProductFormHandler')
            && $this->registerHook('actionProductDelete');
    }

    public function uninstall()
    {
        // A signing key left on a shop that believes it has removed the module is a credential
        // nobody is watching, so it goes - and sudemi is told, best effort.
        if (SudemiClient::isConnected()) {
            SudemiClient::postSigned('/integration/v1/credentials/revoke', ['reason' => 'uninstalled']);
        }
        SudemiKeys::forget();
        SudemiPairing::forget();
        SudemiOrders::uninstallTable();
        SudemiWithdrawal::uninstallTable();
        SudemiLogger::clear();
        SudemiHeartbeat::forget();
        // Price changes that never got through go with the credential that would have signed
        // them: leaving them would mean a reinstall pushing stale prices.
        SudemiQueue::forget();
        SudemiSync::forget();
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

    /**
     * The right-of-withdrawal choice on the product page: a select in the Options tab of the
     * product form (PrestaShop 8.1+ / 9). Combinations carry their product's setting.
     */
    public function hookActionProductFormBuilderModifier($params)
    {
        if (empty($params['form_builder'])) {
            return;
        }
        $builder = $params['form_builder'];
        $tab = null;
        foreach (['options', 'shipping', 'description'] as $candidate) {
            if ($builder->has($candidate)) {
                $tab = $builder->get($candidate);
                break;
            }
        }
        if ($tab === null) {
            return;
        }
        $current = !empty($params['id']) ? SudemiWithdrawal::get((int) $params['id']) : null;
        $choices = [];
        foreach ($this->withdrawalLabels() as $value => $label) {
            $choices[$label] = $value;
        }
        $tab->add(SudemiWithdrawal::FIELD, \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
            'label' => $this->l('Right of withdrawal'),
            'help' => $this->l('Only for products that legally fall under an exception. Buyers see it before paying; misuse is not allowed.'),
            'choices' => $choices,
            'data' => $current !== null ? $current : SudemiWithdrawal::NONE,
            'required' => false,
            'placeholder' => false,
            'translation_domain' => false,
            'choice_translation_domain' => false,
        ]);
    }

    /**
     * Saves the choice after PrestaShop has saved the product (the form's own permission and
     * CSRF checks have passed by then). A changed exclusion is sent with a full sync on the next
     * heartbeat: the price push that follows every save carries price and stock only.
     */
    public function hookActionAfterUpdateProductFormHandler($params)
    {
        $productId = isset($params['id']) ? (int) $params['id'] : 0;
        $data = isset($params['form_data']) && is_array($params['form_data']) ? $params['form_data'] : [];
        foreach ($data as $tab) {
            if (is_array($tab) && array_key_exists(SudemiWithdrawal::FIELD, $tab)) {
                if (SudemiWithdrawal::set($productId, $tab[SudemiWithdrawal::FIELD])) {
                    SudemiSync::request();
                }

                return;
            }
        }
    }

    public function hookActionProductDelete($params)
    {
        if (!empty($params['id_product'])) {
            SudemiWithdrawal::forget((int) $params['id_product']);
            // Its listings stay on sudemi until a full sync's commit removes them.
            SudemiSync::noteRemoval();
        }
    }

    /** reason => label, in the employee's language. */
    private function withdrawalLabels()
    {
        return [
            SudemiWithdrawal::NONE => $this->l('None - normal right of withdrawal applies'),
            'hygiene' => $this->l('Sealed for health or hygiene reasons (not returnable once unsealed)'),
            'custom' => $this->l('Made to the buyer’s specifications or personalised'),
            'perishable' => $this->l('Perishable or quickly expiring'),
            'media' => $this->l('Sealed audio, video or software (not returnable once unsealed)'),
            'press' => $this->l('Newspaper, periodical or magazine'),
            'mixed' => $this->l('Inseparably mixed with other items after delivery'),
            'alcohol' => $this->l('Alcohol with a market-dependent price, delivered after 30+ days'),
        ];
    }

    public function hookActionUpdateQuantity($params)
    {
        if (!empty($params['id_product'])) {
            SudemiSync::pushDelta(
                (int) $params['id_product'],
                !empty($params['id_product_attribute']) ? (int) $params['id_product_attribute'] : null
            );
        }
    }

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
        // sudemi holds the buyer's payment: a refund or cancellation made here has to reach it,
        // or the shop records it and the buyer never gets the money back. The module's own
        // cancellations (a released, never-charged order) are not sent.
        if (!SudemiOrders::$changingState) {
            if ((int) $status->id === (int) Configuration::get('PS_OS_REFUND')) {
                SudemiOrders::refund($order, null, 'Refunded by the shop');

                return;
            }
            if ((int) $status->id === (int) Configuration::get('PS_OS_CANCELED')) {
                // Asked as a reject first, because only sudemi knows whether the money was taken:
                // an order not paid yet (accepted while other shops in the same payment still had
                // to answer, or the acceptance never got through) is released by /reject, while
                // sudemi refuses /cancel for it and would take the money later. If sudemi answers
                // that it is paid, rejectFromShop cancels it there with a full refund. (PrestaShop's
                // own "paid" flag cannot tell: Processing in progress counts as paid.)
                SudemiOrders::rejectFromShop($order, SudemiOrders::sudemiIdOf($order), 'Cancelled by the shop');

                return;
            }
        }
        $event = self::orderEvent((int) $status->id, !empty($status->shipped), (int) Configuration::get('PS_OS_DELIVERED'));
        if ($event === null) {
            return;
        }
        SudemiOrders::report($order, $event);
    }

    /**
     * Which event a new order state reports: 'delivered', 'shipped' or null.
     *
     * Delivered is the shop's own Delivered state. PrestaShop's state flags cannot tell it apart:
     * Shipped and Delivered both carry shipped and delivery, and "delivery" only means a delivery
     * slip exists (Processing in progress has it too). Reading the flag reported every Delivered
     * as another shipment, so sudemi never heard of a delivery and the buyer's 14-day return
     * window never started.
     */
    public static function orderEvent($stateId, $shipped, $deliveredId)
    {
        if ($deliveredId > 0 && (int) $stateId === (int) $deliveredId) {
            return 'delivered';
        }

        return $shipped ? 'shipped' : null;
    }

    /**
     * The ownership challenge lives at a fixed path, /.well-known/sudemi-challenge, which
     * PrestaShop's dispatcher only reaches through a route (friendly URLs on). Without this the
     * path answered 404 and no PrestaShop shop could ever finish pairing.
     */
    public function hookModuleRoutes($params)
    {
        return [
            'module-sudemi-challenge' => [
                'controller' => 'challenge',
                'rule' => '.well-known/sudemi-challenge',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => 'sudemi', 'controller' => 'challenge'],
            ],
        ];
    }

    /**
     * A partial refund (credit slip) made in the back office is passed to sudemi, which pays it
     * back to the buyer from the payment it holds.
     */
    public function hookActionOrderSlipAdd($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order instanceof Order || SudemiOrders::sudemiIdOf($order) === null) {
            return;
        }
        $row = Db::getInstance()->getRow('SELECT total_products_tax_incl, total_shipping_tax_incl FROM '
            . _DB_PREFIX_ . 'order_slip WHERE id_order = ' . (int) $order->id . ' ORDER BY id_order_slip DESC');
        if (!$row) {
            return;
        }
        $amount = (int) round(((float) $row['total_products_tax_incl'] + (float) $row['total_shipping_tax_incl']) * 100);
        if ($amount > 0) {
            SudemiOrders::refund($order, $amount, 'Partial refund (credit slip)');
        }
    }

    /** Shipping prices come from the carriers: a change there re-sends the catalogue on the next heartbeat. */
    public function hookActionCarrierUpdate($params)
    {
        SudemiSync::request();
    }

    public function hookActionObjectCarrierUpdateAfter($params)
    {
        SudemiSync::request();
    }

    /**
     * The fallback beat, for a shop whose host has no cron entry for us.
     *
     * A back-office page load sends a beat when one is due and a small step (at most
     * SudemiSync::STEP_PRODUCTS products, about two seconds) of a waiting full sync, both after
     * the page was produced (after it was sent, where PHP-FPM allows it). Never the whole sync:
     * on a large catalogue that held the employee's screen for minutes. The cron entry runs the
     * rest; without cron the following page loads continue where the last one stopped.
     *
     * Not the supported path - a shop nobody logs into would go quiet and have its listings
     * paused, correctly - and never attached to a storefront request: a buyer's page load must
     * not wait on our network.
     */
    public function hookActionAdminControllerSetMedia()
    {
        if (!SudemiClient::isConnected() || (!SudemiHeartbeat::isDue() && !SudemiSync::pending())) {
            return;
        }
        register_shutdown_function(['SudemiSync', 'afterResponse']);
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

        // Both only ask for a full sync: it never runs inside a page request. The cron entry sends
        // it (or, without cron, small steps after back-office pages; see SudemiSync::work).
        if (Tools::isSubmit('sudemi_sync')) {
            $output .= $this->guard('sudemi_sync');
            if ($output === '') {
                SudemiSync::request();
                $output .= $this->displayConfirmation($this->l('The catalogue sync has started and runs in the background; the catalogue is sent within a few minutes.'));
            }
        }

        if (Tools::isSubmit('sudemi_delivery')) {
            $output .= $this->guard('sudemi_delivery');
            if ($output === '') {
                SudemiShipping::saveDays(Tools::getValue('handling_days'), Tools::getValue('transit_days'));
                SudemiShipping::saveCarriers((array) Tools::getValue('sudemi_carriers', []));
                // Every product carries the times, so the catalogue is sent again.
                SudemiSync::request();
                $output .= $this->displayConfirmation($this->l('Delivery times saved. The catalogue will be sent with the next sync.'));
            }
        }

        if (Tools::isSubmit('sudemi_disconnect')) {
            $output .= $this->guard('sudemi_disconnect');
            if ($output === '') {
                SudemiKeys::forget();
                SudemiPairing::forget();
                SudemiHeartbeat::forget();
                SudemiQueue::forget();
                SudemiSync::forget();
                Configuration::deleteByName(SudemiClient::CONF_INSTALL_ID);
                Configuration::deleteByName(SudemiPairing::CONF_CHALLENGE);
                $output .= $this->displayConfirmation($this->l('Disconnected.'));
            }
        }

        if (Tools::isSubmit('sudemi_support_create')) {
            $output .= $this->guard('sudemi_support_create');
            if ($output === '') {
                $output .= $this->supportCreate();
            }
        }

        if (Tools::isSubmit('sudemi_support_reply')) {
            $output .= $this->guard('sudemi_support_reply');
            if ($output === '') {
                $output .= $this->supportReply();
            }
        }

        // A buyer's return request, answered from this page or from the order page's block.
        foreach (['sudemi_request_accept' => true, 'sudemi_request_reject' => false] as $action => $accept) {
            if (Tools::isSubmit($action)) {
                $refused = $this->guard($action);
                $output .= $refused !== '' ? $refused : $this->requestAnswer($accept);
            }
        }

        return $output . $this->renderScreen();
    }

    /** Opens a ticket from the form. The form's values are kept on a failure, so nothing typed is lost. */
    private function supportCreate()
    {
        if (!SudemiClient::isConnected()) {
            return $this->displayError($this->l('Connect this shop to sudemi first: support tickets belong to the connected shop. Until then, write to support@sudemi.com.'));
        }
        $orderInput = trim((string) Tools::getValue('sudemi_support_order'));
        $orderId = $this->resolveSudemiOrder($orderInput);
        if ($orderInput !== '' && $orderId === null) {
            return $this->displayError($this->supportError(SudemiSupport::ERR_ORDER));
        }
        $result = SudemiSupport::createTicket(
            Tools::getValue('sudemi_support_category'),
            Tools::getValue('sudemi_support_subject'),
            Tools::getValue('sudemi_support_message'),
            $orderId,
            $this->employeeLocale()
        );
        if (!$result['ok']) {
            return $this->displayError($this->supportError($result['error']));
        }
        $this->supportTicket = $result['ticket'];
        $this->supportSent = true;

        return $this->displayConfirmation(Tools::htmlentitiesUTF8(sprintf(
            $this->l('Ticket %s was sent. sudemi support answers here and by e-mail.'),
            isset($result['ticket']['number']) ? (string) $result['ticket']['number'] : ''
        )));
    }

    private function supportReply()
    {
        if (!SudemiClient::isConnected()) {
            return $this->displayError($this->l('Connect this shop to sudemi first: support tickets belong to the connected shop. Until then, write to support@sudemi.com.'));
        }
        $result = SudemiSupport::reply((string) Tools::getValue('sudemi_ticket'), Tools::getValue('sudemi_support_reply_message'));
        if (!$result['ok']) {
            return $this->displayError($this->supportError($result['error']));
        }
        $this->supportTicket = $result['ticket'];
        $this->supportSent = true;

        return $this->displayConfirmation($this->l('Your reply was sent.'));
    }

    /**
     * The sudemi order a ticket is about: a full sudemi order id, or the number of a PrestaShop
     * order that came through sudemi. Null when nothing (or nothing known) was given.
     */
    private function resolveSudemiOrder($input)
    {
        if ($input === '') {
            return null;
        }
        if (SudemiSupport::isTicketId($input)) {
            return strtolower($input);
        }
        if (ctype_digit($input)) {
            $id = Db::getInstance()->getValue('SELECT sudemi_order_id FROM `' . _DB_PREFIX_ . 'sudemi_order`
                WHERE id_order = ' . (int) $input);

            return is_string($id) && SudemiSupport::isTicketId($id) ? $id : null;
        }

        return null;
    }

    /** The employee's back-office language, when sudemi writes in it (it answers in English otherwise). */
    private function employeeLocale()
    {
        $language = isset($this->context->language) ? $this->context->language : null;

        return SudemiSupport::locale($language && isset($language->iso_code) ? $language->iso_code : null);
    }

    private function supportError($kind)
    {
        $messages = [
            SudemiSupport::ERR_CATEGORY => $this->l('Choose a category.'),
            SudemiSupport::ERR_SUBJECT => $this->l('The subject needs 3 to 200 characters.'),
            SudemiSupport::ERR_MESSAGE => $this->l('The message cannot be empty or longer than 10,000 characters.'),
            SudemiSupport::ERR_ORDER => $this->l('That order was not found. Give the full sudemi order id, or the PrestaShop order ID of an order that came through sudemi.'),
            SudemiSupport::ERR_INVALID => $this->l('sudemi did not accept this. Check the subject, the message and the order, then try again.'),
            SudemiSupport::ERR_NOT_FOUND => $this->l('That ticket was not found for this shop.'),
            SudemiSupport::ERR_TOO_MANY => $this->l('Too many tickets at once: at most 20 open tickets, and 10 new tickets per hour. Reply to an existing ticket, or try again later.'),
            SudemiSupport::ERR_UNREACHABLE => $this->l('Could not reach sudemi just now. Nothing was sent; try again shortly.'),
            SudemiSupport::ERR_SITE => $this->siteMismatchMessage(''),
        ];

        return isset($messages[$kind]) ? $messages[$kind] : $this->l('sudemi could not handle this just now. Try again shortly.');
    }

    /** The Support section: this shop's tickets, the open-a-ticket form, and one ticket's thread. */
    private function supportSection()
    {
        $categories = [
            'ORDER' => $this->l('An order'),
            'PAYMENT' => $this->l('A payment'),
            'REFUND_RETURN' => $this->l('A refund or return'),
            'ACCOUNT' => $this->l('Your sudemi account'),
            'SHOP_PLUGIN' => $this->l('The shop connection or this module'),
            'PAYOUTS' => $this->l('Payouts'),
            'OTHER' => $this->l('Something else'),
        ];
        $statuses = [
            'OPEN' => $this->l('Open'),
            'WAITING_FOR_CUSTOMER' => $this->l('Waiting for your reply'),
            'ANSWERED' => $this->l('Answered'),
            'CLOSED' => $this->l('Closed'),
        ];
        $section = [
            'labels' => [
                'title' => $this->l('Support'),
                'intro' => $this->l('Questions about orders, payments, payouts or this module? Open a ticket with sudemi support. Answers appear here and are also sent by e-mail to the address of your sudemi account.'),
                'not_connected' => $this->l('Connect this shop to sudemi first: support tickets belong to the connected shop. Until then, write to support@sudemi.com.'),
                'list_error' => $this->l('Could not load your tickets from sudemi just now. Try again shortly.'),
                'tickets' => $this->l('Your tickets'),
                'empty' => $this->l('No tickets yet.'),
                'number' => $this->l('Number'),
                'subject' => $this->l('Subject'),
                'status' => $this->l('Status'),
                'updated' => $this->l('Last updated'),
                'view' => $this->l('View'),
                'new' => $this->l('Open a ticket'),
                'category' => $this->l('Category'),
                'choose' => $this->l('Choose a category'),
                'message' => $this->l('Message'),
                'order' => $this->l('sudemi order (optional)'),
                'order_help' => $this->l('The full sudemi order id, or the PrestaShop order ID of an order that came through sudemi.'),
                'send' => $this->l('Send ticket'),
                'reply' => $this->l('Reply'),
                'send_reply' => $this->l('Send reply'),
                'back' => $this->l('Back to all tickets'),
                'support' => $this->l('sudemi support'),
                'you' => $this->l('You'),
                'closed' => $this->l('This ticket is closed. A reply opens it again.'),
            ],
            'categories' => $categories,
            'tickets' => null,
            'ticket' => null,
            'error' => '',
            'form' => $this->supportSent ? ['category' => '', 'subject' => '', 'message' => '', 'order' => '', 'reply' => ''] : [
                'category' => (string) Tools::getValue('sudemi_support_category'),
                'subject' => (string) Tools::getValue('sudemi_support_subject'),
                'message' => (string) Tools::getValue('sudemi_support_message'),
                'order' => (string) Tools::getValue('sudemi_support_order'),
                'reply' => (string) Tools::getValue('sudemi_support_reply_message'),
            ],
            'list_url' => $this->supportUrl(null),
        ];

        $ticket = $this->supportTicket;
        $wanted = (string) Tools::getValue('sudemi_ticket');
        if ($ticket === null && $wanted !== '') {
            $loaded = SudemiSupport::getTicket($wanted);
            if ($loaded['ok']) {
                $ticket = $loaded['ticket'];
            } else {
                $section['error'] = $this->supportError($loaded['error']);
            }
        }
        if ($ticket !== null) {
            $messages = [];
            foreach ((isset($ticket['messages']) && is_array($ticket['messages']) ? $ticket['messages'] : []) as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $messages[] = [
                    'support' => isset($message['author']) && $message['author'] === 'SUPPORT',
                    'body' => isset($message['body']) ? (string) $message['body'] : '',
                    'at' => $this->supportTime(isset($message['createdAt']) ? $message['createdAt'] : null),
                ];
            }
            $section['ticket'] = $this->supportRow($ticket, $categories, $statuses) + ['messages' => $messages];
        }

        $list = SudemiSupport::listTickets();
        if ($list['ok']) {
            $section['tickets'] = [];
            foreach ($list['tickets'] as $row) {
                if (isset($row['id']) && SudemiSupport::isTicketId((string) $row['id'])) {
                    $section['tickets'][] = $this->supportRow($row, $categories, $statuses);
                }
            }
        }

        return $section;
    }

    private function supportRow(array $ticket, array $categories, array $statuses)
    {
        $status = isset($ticket['status']) ? (string) $ticket['status'] : '';
        $category = isset($ticket['category']) ? (string) $ticket['category'] : '';

        return [
            'id' => (string) $ticket['id'],
            'number' => isset($ticket['number']) ? (string) $ticket['number'] : '',
            'subject' => isset($ticket['subject']) ? (string) $ticket['subject'] : '',
            'status' => isset($statuses[$status]) ? $statuses[$status] : $status,
            'closed' => $status === 'CLOSED',
            'category' => isset($categories[$category]) ? $categories[$category] : $category,
            'updated' => $this->supportTime(isset($ticket['updatedAt']) ? $ticket['updatedAt'] : null),
            'url' => $this->supportUrl((string) $ticket['id']),
        ];
    }

    /** An ISO-8601 time from sudemi, in the shop's time zone. */
    private function supportTime($iso)
    {
        $time = is_string($iso) ? strtotime($iso) : false;

        return $time ? date('Y-m-d H:i', $time) : '';
    }

    /** This page's address, showing one ticket (or the list when $ticketId is null). */
    private function supportUrl($ticketId)
    {
        $parts = explode('?', (string) $_SERVER['REQUEST_URI'], 2);
        $query = [];
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
        }
        unset($query['sudemi_ticket']);
        if ($ticketId !== null) {
            $query['sudemi_ticket'] = $ticketId;
        }

        return $parts[0] . ($query ? '?' . http_build_query($query) : '') . '#sudemi-support';
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

    /**
     * Sends the shop's answer to a buyer's return request (accept, or reject with a reason) and
     * says how it went. The local order gets a line in its private note when sudemi took it.
     */
    private function requestAnswer($accept)
    {
        if (!SudemiClient::isConnected()) {
            return $this->displayError($this->l('Connect this shop to sudemi first.'));
        }
        $sudemiId = strtolower(trim((string) Tools::getValue('sudemi_request_order')));
        $reason = (string) Tools::getValue('sudemi_request_reason');
        $result = SudemiRequests::answer($sudemiId, $accept, $reason);
        $order = SudemiRequests::isUuid($sudemiId) ? SudemiOrders::localOrder($sudemiId) : null;

        if ($result['outcome'] === SudemiRequests::OK) {
            if ($order) {
                SudemiOrders::privateMessage($order, $accept
                    ? 'Return request accepted on sudemi. Refund this order once the item is back (status Refunded, or a credit slip); that refund closes the request.'
                    : 'Return request rejected on sudemi. Reason given to the buyer: ' . trim($reason));
            }
            SudemiLogger::info(sprintf('Return request for sudemi order %s %s.', $sudemiId, $accept ? 'accepted' : 'rejected'));

            return $this->displayConfirmation(Tools::htmlentitiesUTF8($accept
                ? $this->l('Return accepted. The buyer is told to send the item back. Once it is back, refund this order the usual way (status Refunded, or a credit slip for part of it): the refund goes to the buyer through sudemi and closes the request.')
                : $this->l('Return rejected. sudemi shows your reason to the buyer.')));
        }

        return $this->requestError($result['outcome']);
    }

    /** sudemi's answer to a return decision that did not go through, in the employee's language. */
    private function requestError($outcome)
    {
        switch ($outcome) {
            case SudemiRequests::ALREADY:
                return $this->displayWarning(Tools::htmlentitiesUTF8($this->l('This return request was already answered (on sudemi, or by sudemi after the deadline). Nothing was changed.')));
            case SudemiRequests::NOT_FOUND:
                return $this->displayWarning(Tools::htmlentitiesUTF8($this->l('sudemi has no open return request for this order. It was removed from the list.')));
            case SudemiRequests::REASON_REQUIRED:
                return $this->displayError(Tools::htmlentitiesUTF8($this->l('To reject a return, give a reason. The buyer is shown it.')));
            case SudemiRequests::INVALID:
                return $this->displayError(Tools::htmlentitiesUTF8($this->l('sudemi did not accept this answer. A rejection needs a reason; check it and try again.')));
            case SudemiRequests::UNREACHABLE:
                return $this->displayError(Tools::htmlentitiesUTF8($this->l('Could not reach sudemi just now. Nothing was sent; try again shortly.')));
            case SudemiRequests::SITE:
                return $this->displayError(Tools::htmlentitiesUTF8($this->siteMismatchMessage('')));
            default:
                return $this->displayError(Tools::htmlentitiesUTF8($this->l('sudemi could not handle this just now. Try again shortly.')));
        }
    }

    /** Labels shared by the return-request list and the order page block. */
    private function requestLabels()
    {
        return [
            'title' => $this->l('Return requests'),
            'intro' => $this->l('Buyers asked to return these delivered orders. Answer each one before its answer-by date: accept, or reject with a reason (the buyer is shown it). Without an answer, sudemi decides. After accepting, refund the order once the item is back.'),
            'empty' => $this->l('No open return requests.'),
            'order' => $this->l('Order'),
            'reason' => $this->l('Reason given by the buyer'),
            'no_reason' => $this->l('No reason given'),
            'requested' => $this->l('Requested on'),
            'respond_by' => $this->l('Answer by'),
            'accept' => $this->l('Accept return'),
            'reject' => $this->l('Reject return'),
            'reject_reason' => $this->l('Reason for rejecting (shown to the buyer)'),
            'no_local' => $this->l('No PrestaShop order'),
            'block_title' => $this->l('Return request from the buyer (sudemi)'),
            'block_intro' => $this->l('The buyer asked to return this order. Accept, or reject with a reason (the buyer is shown it). Without an answer by the date below, sudemi decides. After accepting, refund this order once the item is back.'),
        ];
    }

    /** One stored request, ready for the template (local order number and link, local times). */
    private function requestRow(array $request)
    {
        $order = SudemiOrders::localOrder($request['order_id']);

        return [
            'order_id' => $request['order_id'],
            'short' => Tools::substr($request['order_id'], 0, 8),
            'local_id' => $order ? (int) $order->id : 0,
            'reference' => $order ? (string) $order->reference : '',
            'local_url' => $order ? $this->adminUrl('AdminOrders', ['id_order' => (int) $order->id, 'vieworder' => 1]) : '',
            'reason' => $request['reason'],
            'requested' => $this->supportTime($request['requested_at']),
            'respond_by' => $this->supportTime($request['respond_by']),
        ];
    }

    /** Where the answer forms post: this module's page, which checks permission and token. */
    private function requestFormUrl()
    {
        return $this->adminUrl('AdminModules', ['configure' => $this->name]) . '#sudemi-requests';
    }

    /**
     * A back-office link with its token. Link::getAdminLink knows the newer Symfony pages; when
     * it cannot build one (it answers '' or throws outside a full back-office request), the
     * legacy controller address still reaches the same page.
     */
    private function adminUrl($controller, array $params)
    {
        try {
            $url = (string) $this->context->link->getAdminLink($controller, true, [], $params);
        } catch (\Throwable $e) {
            $url = '';
        }
        if ($url !== '') {
            return $url;
        }

        return 'index.php?' . http_build_query(['controller' => $controller] + $params + ['token' => Tools::getAdminTokenLite($controller)]);
    }

    /** The return-request list on the module page. */
    private function requestSection()
    {
        $rows = [];
        foreach (SudemiRequests::all() as $request) {
            $rows[] = $this->requestRow($request);
        }

        return ['labels' => $this->requestLabels(), 'rows' => $rows];
    }

    /** The order page (PrestaShop 1.7.7+): the block for this order's open return request. */
    public function hookDisplayAdminOrderMain($params)
    {
        return $this->orderRequestBlock(isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    /** The order page before PrestaShop 1.7.7 (displayAdminOrderMain covers the newer one). */
    public function hookDisplayAdminOrder($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) {
            return '';
        }

        return $this->orderRequestBlock(isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    /** Nothing for most orders: only a sudemi order with an open return request gets a block. */
    public function orderRequestBlock($orderId)
    {
        if ($orderId <= 0 || !SudemiClient::isConnected()) {
            return '';
        }
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }
        $sudemiId = SudemiOrders::sudemiIdOf($order);
        $request = $sudemiId === null ? null : SudemiRequests::forOrder($sudemiId);
        if ($request === null) {
            return '';
        }
        $this->context->smarty->assign([
            'sudemi_rq_labels' => $this->requestLabels(),
            'sudemi_rq' => $this->requestRow($request),
            'sudemi_rq_action' => $this->requestFormUrl(),
            'sudemi_token' => $this->csrfToken(),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/order-request.tpl');
    }

    private function csrfToken()
    {
        return Tools::getAdminTokenLite('AdminModules') . '|' . $this->name;
    }

    /**
     * The shop's orders as sudemi holds them (approved, declined, shipped, who bought), shown on
     * this page. Read from sudemi rather than from PrestaShop's own orders: an order sudemi
     * declined because nobody answered in time leaves no local order at all.
     *
     * @return array|null null when sudemi could not be reached
     */
    private function orderHistory()
    {
        $response = SudemiClient::getSigned('/integration/v1/orders?limit=20');
        if ($response === null || (int) $response['status'] !== 200 || !is_array($response['body'])) {
            return null;
        }
        $body = $response['body'];
        $labels = [
            'AWAITING_SELLER' => $this->l('Waiting for you - buyer not charged'),
            'ACCEPTED' => $this->l('Accepted - waiting for the other shops in the buyer\'s payment; buyer not charged yet, do not ship'),
            'PAID' => $this->l('Confirmed and charged'),
            'DECLINED' => $this->l('Declined - nothing charged'),
            'SHIPPED' => $this->l('Shipped'),
            'DELIVERED' => $this->l('Delivered'),
            'CANCELLED' => $this->l('Cancelled'),
            'REFUNDED' => $this->l('Refunded'),
        ];
        $rows = [];
        foreach ((isset($body['orders']) && is_array($body['orders']) ? $body['orders'] : []) as $order) {
            $items = [];
            foreach ((isset($order['lines']) && is_array($order['lines']) ? $order['lines'] : []) as $line) {
                $qty = isset($line['quantity']) ? (int) $line['quantity'] : 1;
                $items[] = (isset($line['title']) ? (string) $line['title'] : '') . ($qty > 1 ? ' × ' . $qty : '');
            }
            $status = isset($order['status']) ? (string) $order['status'] : '';
            $rows[] = [
                'id' => Tools::substr((string) $order['order_id'], 0, 8),
                'date' => isset($order['placed_at']) ? Tools::substr((string) $order['placed_at'], 0, 10) : '',
                'items' => implode(', ', $items),
                'status' => isset($labels[$status]) ? $labels[$status] : $status,
                'net' => number_format((isset($order['your_net_minor']) ? (int) $order['your_net_minor'] : 0) / 100, 2)
                    . ' ' . (isset($order['currency_code']) ? (string) $order['currency_code'] : 'EUR'),
                // Only present once sudemi has released it, which is only after this shop confirmed.
                'customer' => isset($order['customer_name']) ? (string) $order['customer_name'] : ' - ',
            ];
        }
        $awaiting = isset($body['counts']['AWAITING_SELLER']) ? (int) $body['counts']['AWAITING_SELLER'] : 0;

        return [
            'rows' => $rows,
            'awaiting' => $awaiting > 0 ? sprintf($this->l('Orders waiting to be confirmed: %d. Those buyers have not been charged yet.'), $awaiting) : '',
            'labels' => [
                'title' => $this->l('Orders through sudemi'),
                'empty' => $this->l('No orders through sudemi yet.'),
                'order' => $this->l('Order'),
                'items' => $this->l('What sold'),
                'status' => $this->l('Status'),
                'net' => $this->l('You receive'),
                'customer' => $this->l('Customer'),
            ],
        ];
    }

    /** Shipping prices as sudemi buyers will see them, for the first active product. */
    private function shippingPreview()
    {
        $id = (int) Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 AND is_virtual = 0 ORDER BY id_product');
        if (!$id) {
            return [];
        }
        $product = new Product($id, false, (int) Configuration::get('PS_LANG_DEFAULT'));

        return SudemiShipping::preview($product, Product::getPriceStatic($id, true));
    }

    /** sudemi refused this site as not being the connected shop: what it means and what to do. */
    private function siteMismatchMessage($paired)
    {
        if ($paired !== '') {
            return sprintf(
                $this->l('sudemi refused requests from this site: this shop is connected to sudemi under a different address (%s). If this site is a staging copy or a restored backup, disconnect sudemi here. If the shop has moved to this address, connect it again.'),
                $paired
            );
        }

        return $this->l('sudemi refused requests from this site: this shop is connected to sudemi under a different address. If this site is a staging copy or a restored backup, disconnect sudemi here. If the shop has moved to this address, connect it again.');
    }

    private function renderScreen()
    {
        $connected = SudemiClient::isConnected();
        $claimUrl = SudemiPairing::claimUrl();
        $supported = SudemiKeys::isSupported();

        $status = SudemiHeartbeat::status();
        if (is_array($status) && isset($status['problem']) && $status['problem'] === 'site_mismatch') {
            $status['message'] = $this->siteMismatchMessage(isset($status['paired']) ? (string) $status['paired'] : '');
        }

        $this->context->smarty->assign([
            'sudemi_history_error' => $this->l('Could not reach sudemi to load the order history just now. Nothing is wrong with the orders themselves; try again shortly.'),
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
            'sudemi_handling_days' => SudemiShipping::handlingDays(),
            'sudemi_transit_days' => SudemiShipping::transitDays(),
            'sudemi_shipping_preview' => $this->shippingPreview(),
            'sudemi_carriers' => SudemiShipping::activeCarriers(),
            'sudemi_chosen_carriers' => SudemiShipping::chosenCarriers(),
            'sudemi_history' => $connected ? $this->orderHistory() : null,
            'sudemi_support' => $connected ? $this->supportSection() : null,
            'sudemi_requests' => $connected ? $this->requestSection() : null,
            'sudemi_rq_action' => $this->requestFormUrl(),
            'sudemi_support_not_connected' => $this->l('Connect this shop to sudemi first: support tickets belong to the connected shop. Until then, write to support@sudemi.com.'),
            'sudemi_support_title' => $this->l('Support'),
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
