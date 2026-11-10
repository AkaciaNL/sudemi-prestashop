<?php
/**
 * 1.3.0: orders, shipping and payouts managed from the back office (protocol 4d). Existing
 * installs get the change-feed columns on the order table, the two back-office pages (Orders >
 * sudemi orders, Shipping > sudemi shipping), the start of the change feed (orders placed
 * before it are never booked by the feed) and the hooks that send a tracking number added or
 * changed after shipping.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0($module)
{
    SudemiChanges::since();

    return SudemiOrders::ensureSchema() && $module->installTabs() && $module->registerTrackingHooks();
}
