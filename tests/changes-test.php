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

/** Stands in for the signed client: records each POST and answers with the next queued answer. */
class SudemiClient {
	public static $calls   = array();
	public static $answers = array();

	public static function postSigned( $path, array $body = array() ) {
		self::$calls[] = array( $path, $body );
		return array_shift( self::$answers );
	}
}

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

// ---- tracking after shipping (core 2026-11-09, POST /orders/{id}/tracking) ----
check( 'a tracking number added after shipping is sent', 'send' === SudemiOrders::trackingAction( 'JJD2', 'SHIPPED', 'DHL JJD1', true, false ) );
check( 'also while the module still holds the order as paid on sudemi (the change feed has not said shipped yet)',
	'send' === SudemiOrders::trackingAction( 'JJD2', 'PAID', '', true, false ) );
check( 'not before the order is shipped here (the shipped report carries it)', null === SudemiOrders::trackingAction( 'JJD2', 'PAID', '', false, false ) );
check( 'not when sudemi already has this number (it came from sudemi, or was sent before)',
	null === SudemiOrders::trackingAction( 'JJD1', 'SHIPPED', 'DHL JJD1', true, false ) && null === SudemiOrders::trackingAction( 'JJD1', 'SHIPPED', 'JJD1', true, false ) );
check( 'a carrier with spaces: the code is the last word', null === SudemiOrders::trackingAction( '3S1', 'SHIPPED', 'Post NL 3S1', true, false ) );
check( 'a number that only ends like sudemi\'s is new', 'send' === SudemiOrders::trackingAction( 'S1', 'SHIPPED', 'PostNL 3S1', true, false ) );
foreach ( array( 'DELIVERED', 'CANCELLED', 'REFUNDED', 'DECLINED' ) as $s ) {
	check( "not when sudemi has the order $s (final there)", null === SudemiOrders::trackingAction( 'JJD2', $s, 'DHL JJD1', true, false ) );
}
check( 'no number: nothing', null === SudemiOrders::trackingAction( '  ', 'SHIPPED', '', true, false ) );
check( 'a shipped report still waiting for a retry carries the new number instead', 'queued' === SudemiOrders::trackingAction( 'JJD2', 'PAID', '', true, true ) );
check( 'tracking body: blank carrier left out (sudemi keeps its own), at most 100 characters',
	array( 'carrier' => null, 'trackingCode' => str_repeat( 'x', 100 ) ) === SudemiOrders::trackingBody( '  ', str_repeat( 'x', 120 ) ) );

$ans = function ( int $status, array $body ) { return array( 'status' => $status, 'body' => $body, 'retry_after' => 0 ); };
check( 'tracking 200 ok: updated', 'updated' === SudemiOrders::trackingOutcome( $ans( 200, array( 'status' => 'ok' ) ) ) );
check( 'tracking 200 already_resolved: unchanged', 'unchanged' === SudemiOrders::trackingOutcome( $ans( 200, array( 'status' => 'already_resolved' ) ) ) );
check( 'tracking 409 not_shipped: try shipped', 'not_shipped' === SudemiOrders::trackingOutcome( $ans( 409, array( 'error' => 'not_shipped' ) ) ) );
check( 'tracking 409 site_mismatch, 503, 429, no answer: retry',
	'retry' === SudemiOrders::trackingOutcome( $ans( 409, array( 'error' => 'site_mismatch' ) ) ) && 'retry' === SudemiOrders::trackingOutcome( $ans( 503, array() ) )
	&& 'retry' === SudemiOrders::trackingOutcome( $ans( 429, array() ) ) && 'retry' === SudemiOrders::trackingOutcome( null ) );
check( 'tracking 400 tracking_code_required, 404 no_such_order: refused',
	'refused' === SudemiOrders::trackingOutcome( $ans( 400, array( 'error' => 'tracking_code_required' ) ) ) && 'refused' === SudemiOrders::trackingOutcome( $ans( 404, array( 'error' => 'no_such_order' ) ) ) );
check( 'shipped after not_shipped, 200 ok: sudemi had it paid, now shipped', 'shipped' === SudemiOrders::shippedFallbackOutcome( $ans( 200, array( 'status' => 'ok' ) ) ) );
check( 'shipped after not_shipped, already_resolved: delivered, cancelled or refunded, final', 'final' === SudemiOrders::shippedFallbackOutcome( $ans( 200, array( 'status' => 'already_resolved' ) ) ) );
check( 'shipped after not_shipped, 409 not_paid: retry (as every shipped report)', 'retry' === SudemiOrders::shippedFallbackOutcome( $ans( 409, array( 'error' => 'not_paid' ) ) ) );

// sendTracking against a stand-in sudemi: the calls it makes and what they mean.
SudemiClient::$answers = array( $ans( 200, array( 'status' => 'ok' ) ) );
$r = SudemiOrders::sendTracking( strtoupper( $id ), array( 'carrier' => 'DHL', 'trackingCode' => 'JJD2' ) );
check( 'a SHIPPED order: one tracking call, updated',
	'updated' === $r['outcome'] && array( array( '/integration/v1/orders/' . $id . '/tracking', array( 'carrier' => 'DHL', 'trackingCode' => 'JJD2' ) ) ) === SudemiClient::$calls, SudemiClient::$calls );
SudemiClient::$calls = array();
SudemiClient::$answers = array( $ans( 409, array( 'error' => 'not_shipped' ) ), $ans( 200, array( 'status' => 'ok' ) ) );
$r = SudemiOrders::sendTracking( $id, array( 'carrier' => null, 'trackingCode' => 'JJD2' ) );
check( 'a PAID order: tracking answers not_shipped, then shipped with the same tracking',
	'shipped' === $r['outcome'] && 2 === count( SudemiClient::$calls ) && '/integration/v1/orders/' . $id . '/shipped' === SudemiClient::$calls[1][0]
	&& array( 'carrier' => null, 'trackingCode' => 'JJD2' ) === SudemiClient::$calls[1][1], SudemiClient::$calls );
SudemiClient::$calls = array();
SudemiClient::$answers = array( $ans( 409, array( 'error' => 'not_shipped' ) ), $ans( 200, array( 'status' => 'already_resolved' ) ) );
check( 'a DELIVERED order: not_shipped, then already_resolved: final', 'final' === SudemiOrders::sendTracking( $id, array( 'trackingCode' => 'JJD2' ) )['outcome'] );
SudemiClient::$calls = array();
SudemiClient::$answers = array( null );
check( 'sudemi unreachable: retry, nothing more sent', 'retry' === SudemiOrders::sendTracking( $id, array( 'trackingCode' => 'JJD2' ) )['outcome'] && 1 === count( SudemiClient::$calls ) );
SudemiClient::$calls = array();
check( 'no tracking number: nothing sent', 'required' === SudemiOrders::sendTracking( $id, array( 'carrier' => 'DHL', 'trackingCode' => '' ) )['outcome'] && array() === SudemiClient::$calls );
check( 'the order page offers "Change tracking" (update_tracking) when sudemi allows it', in_array( 'update_tracking', SudemiManage::ORDER_ACTIONS, true ) );

// ---- who cancelled (cancelled_by on settled_orders and changed_orders) ----
check( 'cancelled_by buyer: the note says the buyer', 'Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full. Do not ship this order.' === SudemiOrders::cancelNote( 'buyer' ) );
check( 'cancelled_by shop: the note says the shop', 'Cancelled by the shop on sudemi before shipping; the buyer has been refunded in full. Do not ship this order.' === SudemiOrders::cancelNote( 'shop' ) );
check( 'cancelled_by sudemi: the note says sudemi', 'Cancelled by sudemi before shipping; the buyer has been refunded in full. Do not ship this order.' === SudemiOrders::cancelNote( 'sudemi' ) );
check( 'an older core (no cancelled_by) or an unknown value: neutral, nobody blamed',
	'note_cancel' === SudemiOrders::cancelNoteKey( null ) && 'note_cancel' === SudemiOrders::cancelNoteKey( 'martian' ) && 'note_cancel_shop' === SudemiOrders::cancelNoteKey( 'SHOP' ) );
check( 'the buyer note is the one used before 1.3.0', SudemiOrders::BUYER_CANCEL_NOTE === SudemiText::EN['note_cancel_buyer'] );
check( 'the note in the shop\'s language (German shop: storniert vom Shop)', 'Vom Shop bei sudemi vor dem Versand storniert; der Käufer hat den vollen Betrag zurückerhalten.'
	=== SudemiText::translate( SudemiText::EN['note_cancel_shop'], 'de' ) );
check( 'translate: English and an unknown language give the English text',
	SudemiText::EN['note_cancel'] === SudemiText::translate( SudemiText::EN['note_cancel'], 'en' ) && SudemiText::EN['note_cancel'] === SudemiText::translate( SudemiText::EN['note_cancel'], 'xx' ) );
check( 'translate: a text with an apostrophe is found (PrestaShop\'s key escapes it)',
	SudemiText::EN['ship_mode_shop'] !== SudemiText::translate( SudemiText::EN['ship_mode_shop'], 'nl' ) );
$e = SudemiChanges::entryOf( array( 'order_id' => $id, 'status' => 'CANCELLED', 'revision' => 5, 'cancelled_by' => 'shop' ) );
check( 'a change entry keeps cancelled_by', 'shop' === $e['cancelled_by'] );

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
