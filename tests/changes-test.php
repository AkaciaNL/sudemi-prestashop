<?php
/**
 * 1.3.0: managing orders, shipping and payouts from the back office (protocol 4d).
 *
 * - The change feed: which steps an entry asks of the local order, applied only when its revision
 *   is higher than the one applied before (a repeat or an older entry changes nothing), and an
 *   order accepted on sudemi that has no local order is booked here.
 * - The order table's filters: only known values reach sudemi's query string.
 * - A price typed in the back office, the pending-order shape built from sudemi's order page.
 * - Every text of the new pages in all twelve languages, placeholders kept; no long dash anywhere.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );
require __DIR__ . '/../classes/SudemiCurrency.php';
require __DIR__ . '/../classes/SudemiRequests.php';
require __DIR__ . '/../classes/SudemiManage.php';
require __DIR__ . '/../classes/SudemiChanges.php';
require __DIR__ . '/../classes/SudemiOrders.php';
require __DIR__ . '/../classes/SudemiText.php';

$failures = 0;

function check( string $what, bool $passed, $got = null ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' got ' . var_export( $got, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

function local( string $state, $revision = 3, string $tracking = '', int $refunded = 0 ): array {
	return array( 'revision' => $revision, 'state' => $state, 'tracking' => $tracking, 'refunded' => $refunded );
}

$id = '0f6c1c3e-1111-4222-8333-944455556666';
$entry = function ( string $status, int $revision, array $extra = array() ) use ( $id ): array {
	return $extra + array( 'order_id' => $id, 'status' => $status, 'revision' => $revision, 'refunded_minor' => 0, 'tracking' => null );
};

// ---- revisions ----
check( 'an entry with the revision already applied changes nothing', array() === SudemiChanges::plan( $entry( 'SHIPPED', 3 ), local( 'paid' ) ) );
check( 'an older entry changes nothing', array() === SudemiChanges::plan( $entry( 'SHIPPED', 2 ), local( 'paid' ) ) );
check( 'an entry without a revision (old core) changes nothing', array() === SudemiChanges::plan( array( 'order_id' => $id, 'status' => 'SHIPPED' ), local( 'paid', null ) ) );
check( 'a first entry for a known order is applied (no revision held yet)', array( 'ship', 'remember' ) === SudemiChanges::plan( $entry( 'SHIPPED', 1 ), local( 'paid', null ) ) );

// ---- states ----
check( 'SHIPPED on sudemi, paid here: ship', array( 'ship', 'remember' ) === SudemiChanges::plan( $entry( 'SHIPPED', 4 ), local( 'paid' ) ) );
check( 'SHIPPED with tracking: ship and write the tracking',
	array( 'ship', 'tracking', 'remember' ) === SudemiChanges::plan( $entry( 'SHIPPED', 4, array( 'tracking' => array( 'carrier' => 'DHL', 'code' => 'JJD1' ) ) ), local( 'paid' ) ) );
check( 'the same tracking again is not written twice',
	array( 'remember' ) === SudemiChanges::plan( $entry( 'SHIPPED', 5, array( 'tracking' => array( 'carrier' => 'DHL', 'code' => 'JJD1' ) ) ), local( 'shipped', 4, 'JJD1' ) ) );
check( 'DELIVERED, paid here: ship, then deliver', array( 'ship', 'deliver', 'remember' ) === SudemiChanges::plan( $entry( 'DELIVERED', 4 ), local( 'paid' ) ) );
check( 'DELIVERED, shipped here: deliver', array( 'deliver', 'remember' ) === SudemiChanges::plan( $entry( 'DELIVERED', 4 ), local( 'shipped' ) ) );
check( 'PAID, still unpaid here: pay', array( 'pay', 'remember' ) === SudemiChanges::plan( $entry( 'PAID', 4 ), local( 'prep' ) ) );
check( 'SHIPPED, still unpaid here: pay first, then ship', array( 'pay', 'ship', 'remember' ) === SudemiChanges::plan( $entry( 'SHIPPED', 4 ), local( 'prep' ) ) );
check( 'DECLINED, unpaid here: release', array( 'release', 'remember' ) === SudemiChanges::plan( $entry( 'DECLINED', 4 ), local( 'prep' ) ) );
check( 'CANCELLED before shipping: cancel', array( 'cancel', 'remember' ) === SudemiChanges::plan( $entry( 'CANCELLED', 4 ), local( 'paid' ) ) );
check( 'CANCELLED, already cancelled here: nothing but the revision', array( 'remember' ) === SudemiChanges::plan( $entry( 'CANCELLED', 4 ), local( 'cancelled' ) ) );
check( 'CANCELLED, already shipped here: not cancelled here (the merchant decides)', array( 'remember' ) === SudemiChanges::plan( $entry( 'CANCELLED', 4 ), local( 'shipped' ) ) );
check( 'REFUNDED in full: refunded state and a refund note',
	array( 'refunded', 'refund_note', 'remember' ) === SudemiChanges::plan( $entry( 'REFUNDED', 6, array( 'refunded_minor' => 2995 ) ), local( 'delivered' ) ) );
check( 'a partial refund (status stays): only the note',
	array( 'refund_note', 'remember' ) === SudemiChanges::plan( $entry( 'DELIVERED', 6, array( 'refunded_minor' => 300 ) ), local( 'delivered' ) ) );
check( 'a refund already known (the module sent it): no second note',
	array( 'remember' ) === SudemiChanges::plan( $entry( 'DELIVERED', 7, array( 'refunded_minor' => 300 ) ), local( 'delivered', 6, '', 300 ) ) );

// ---- no local order ----
check( 'PAID on sudemi without a local order (accepted on the portal): import, pay', array( 'import', 'pay', 'remember' ) === SudemiChanges::plan( $entry( 'PAID', 2 ), null ) );
check( 'ACCEPTED without a local order: import only (not paid yet)', array( 'import', 'remember' ) === SudemiChanges::plan( $entry( 'ACCEPTED', 2 ), null ) );
check( 'DECLINED without a local order: nothing', array() === SudemiChanges::plan( $entry( 'DECLINED', 2 ), null ) );
check( 'AWAITING_SELLER without a local order: nothing (answered on the heartbeat or by hand)', array() === SudemiChanges::plan( $entry( 'AWAITING_SELLER', 1 ), null ) );

// ---- an entry from an order page or a list row ----
$detail = array( 'order_id' => $id, 'status' => 'SHIPPED', 'revision' => 9, 'money' => array( 'refunded_minor' => 150 ), 'tracking' => array( 'carrier' => 'PostNL', 'code' => '3S1' ) );
$e = SudemiChanges::entryOf( $detail );
check( 'an order page reads as a change entry (refunded from money)', 9 === $e['revision'] && 150 === $e['refunded_minor'] && '3S1' === $e['tracking']['code'], $e );
$e = SudemiChanges::entryOf( array( 'order_id' => $id, 'status' => 'PAID', 'revision' => 2, 'refunded_minor' => 0, 'tracking' => null ) );
check( 'a list row reads as a change entry', 'PAID' === $e['status'] && null === $e['tracking'], $e );

// ---- filters ----
$f = SudemiManage::filters( array( 'status' => 'paid', 'from' => '2026-11-01', 'to' => '2026-02-30', 'q' => "  kettle\n red ", 'sort' => 'total', 'dir' => 'ASC', 'offset' => '50' ) );
check( 'filters: status upper-cased, a date that does not exist dropped, search squeezed',
	'PAID' === $f['status'] && '2026-11-01' === $f['from'] && '' === $f['to'] && 'kettle red' === $f['q'] && 'total' === $f['sort'] && 'asc' === $f['dir'] && 50 === $f['offset'], $f );
$f = SudemiManage::filters( array( 'status' => 'PAID&x=1', 'sort' => 'id;drop', 'dir' => 'sideways', 'offset' => '-3', 'from' => '2026-11-01T00:00:00Z' ) );
check( 'filters: unknown values never reach sudemi',
	'' === $f['status'] && 'placed' === $f['sort'] && 'desc' === $f['dir'] && 0 === $f['offset'] && '' === $f['from'], $f );
$q = SudemiManage::query( SudemiManage::filters( array( 'q' => 'a&b c', 'status' => 'SHIPPED' ) ), 25 );
check( 'query: always the table shape (offset), values encoded', 'status=SHIPPED&q=a%26b%20c&sort=placed&dir=desc&limit=25&offset=0' === $q, $q );
check( 'query for the export: no paging, extra parameters', 'sort=placed&dir=desc&lang=de&decimal=comma'
	=== SudemiManage::query( SudemiManage::filters( array() ), null, array( 'lang' => 'de', 'decimal' => 'comma' ) ) );

// ---- prices typed in the back office ----
check( 'price "4,95" EUR = 495', 495 === SudemiManage::priceMinor( '4,95', 'EUR' ) );
check( 'price "5" EUR = 500', 500 === SudemiManage::priceMinor( '5', 'EUR' ) );
check( 'price "1500" JPY = 1500 (no decimals)', 1500 === SudemiManage::priceMinor( '1500', 'JPY' ) );
check( 'price empty = null (the shop\'s own price)', null === SudemiManage::priceMinor( '  ', 'EUR' ) );
check( 'price "-1", "4.9.5" and "abc" are not prices', false === SudemiManage::priceMinor( '-1', 'EUR' ) && false === SudemiManage::priceMinor( '4.9.5', 'EUR' ) && false === SudemiManage::priceMinor( 'abc', 'EUR' ) );

// ---- the pending-order shape from sudemi's order page ----
$page = array(
	'order_id' => $id, 'status' => 'AWAITING_SELLER', 'destination_country' => 'DE',
	'lines' => array( array( 'product_id' => 'p1', 'external_id' => '12-3', 'title' => 'Kettle', 'quantity' => 2, 'unit_price_minor' => 1250, 'goods_minor' => 2500 ) ),
	'money' => array( 'currency_code' => 'EUR', 'goods_minor' => 2500, 'shipping_minor' => 695, 'total_minor' => 3195 ),
);
$pending = SudemiOrders::pendingFromDetail( $page );
check( 'an order page becomes the heartbeat\'s pending-order shape (lines, money, country)',
	is_array( $pending ) && '12-3' === $pending['lines'][0]['external_id'] && 1250 === $pending['lines'][0]['unit_price_minor']
	&& 695 === $pending['shipping_minor'] && 3195 === $pending['total_minor'] && 'DE' === $pending['destination_country'] && 'EUR' === $pending['currency_code'], $pending );
check( 'and the paid amounts read from it as from a heartbeat', array( 'shipping' => 695, 'lines' => array( 1250 ), 'total' => 3195 ) === SudemiOrders::paidAmounts( $pending ) );
check( 'an order page without lines is not booked', null === SudemiOrders::pendingFromDetail( array( 'order_id' => $id, 'money' => array() ) ) );

// ---- every text in all twelve languages (English is the source) ----
check( 'the texts include the order page block and the shipping page', isset( SudemiText::EN['do_tracking'], SudemiText::EN['ship_prefill'], SudemiText::EN['payouts_delay'] ) );
foreach ( array( 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ) as $lang ) {
	global $_MODULE;
	$_MODULE = array();
	include __DIR__ . '/../translations/' . $lang . '.php';
	$missing = array();
	foreach ( SudemiText::EN as $key => $source ) {
		$k = '<{sudemi}prestashop>sudemi_' . md5( preg_replace( "/\\\\*'/", "\\'", $source ) );
		if ( ! isset( $_MODULE[ $k ] ) || '' === trim( $_MODULE[ $k ] ) ) {
			$missing[] = $key;
			continue;
		}
		preg_match_all( '/%(?:\d\$)?[sd]/', $source, $want );
		preg_match_all( '/%(?:\d\$)?[sd]/', $_MODULE[ $k ], $have );
		$a = $want[0];
		$b = $have[0];
		sort( $a );
		sort( $b );
		if ( $a !== $b ) {
			$missing[] = $key . ' (placeholders)';
		}
	}
	check( "all new texts translated into $lang, placeholders kept", array() === $missing, $missing );
}

// ---- no long dash anywhere (owner rule) ----
$dash = array();
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/..', FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	$path = (string) $file;
	if ( strpos( $path, '/.git/' ) !== false || ! preg_match( '/\.(php|tpl|md|yml)$/', $path ) ) {
		continue;
	}
	if ( strpos( (string) file_get_contents( $path ), "\u{2014}" ) !== false ) {
		$dash[] = basename( $path );
	}
}
check( 'no long dash in any module file', array() === $dash, $dash );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall change-feed and order-management checks passed\n";
