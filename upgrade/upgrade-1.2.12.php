<?php
/**
 * 1.2.12: each sudemi order remembers the currency sudemi booked it in, so a refund in another
 * currency is caught before it is sent. Existing installs get the column here (install() only
 * runs on a fresh install).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_12($module)
{
    return SudemiOrders::addCurrencyColumn();
}
