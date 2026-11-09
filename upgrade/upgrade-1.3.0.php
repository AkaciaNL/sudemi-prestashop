<?php
/**
 * 1.3.0: orders, shipping and payouts managed from the back office (protocol 4d). Existing
 * installs get the change-feed columns on the order table, the two back-office pages (Orders >
 * sudemi orders, Shipping > sudemi shipping) and the start of the change feed (orders placed
 * before it are never booked by the feed).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0($module)
{
    SudemiChanges::since();

    return SudemiOrders::addChangeColumns() && $module->installTabs();
}
