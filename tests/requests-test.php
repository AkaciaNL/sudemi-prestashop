<?php
/**
 * Buyers' return requests: storing the heartbeat's open_requests (replaced every beat, cleared
 * when absent, junk dropped), the signed answer calls (path, body, reason required to reject),
 * how sudemi's answers become outcomes, and the settled_orders decision that cancels a paid
 * order the buyer cancelled on sudemi.
 *
 * Runs without PrestaShop: Configuration and SudemiClient are replaced by stand-ins.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

class Configuration {
	public static $values = array();

	public static function get( $key ) {
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : false;
	}

	public static function updateValue( $key, $value ) {
		self::$values[ $key ] = $value;
		return true;
	}

	public static function deleteByName( $key ) {
		unset( self::$values[ $key ] );
		return true;
	}
}

/** Stands in for the real client: records each call and answers with the queued response. */
class SudemiClient {
	public static $calls = array();
	public static $next  = null;

	public static function sendSigned( $method, $path, array $body = array() ) {
		self::$calls[] = array( $method, $path, $body );
		return self::$next;
	}
}

require __DIR__ . '/../classes/SudemiRequests.php';
require __DIR__ . '/../classes/SudemiOrders.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

function answer( int $status, array $body ): array {
	return array( 'status' => $status, 'body' => $body, 'retry_after' => 0 );
}

$a = '7d9e8f00-1111-4222-8333-944455556666';
$b = '0b6f1c1e-2a8c-4c55-9f0e-6f1d2c3b4a59';
$beat = array(
	array( 'request_id' => 'aaaaaaaa-1111-4222-8333-944455556666', 'order_id' => strtoupper( $a ), 'kind' => 'RETURN',
		'reason' => "  Too small\r\n<b>really</b>  ", 'requested_at' => '2026-11-07T10:00:00Z', 'respond_by' => '2026-11-14T10:00:00Z' ),
	array( 'request_id' => 'bbbbbbbb-1111-4222-8333-944455556666', 'order_id' => $b, 'kind' => 'RETURN',
		'reason' => null, 'requested_at' => '2026-11-06T09:00:00.123456Z', 'respond_by' => '2026-11-13T09:00:00.123456Z' ),
	array( 'order_id' => '../orders', 'kind' => 'RETURN' ),
	'junk',
	array( 'order_id' => $b, 'kind' => 'RETURN' ),
);

// Storing.
SudemiRequests::store( $beat );
$all = SudemiRequests::all();
check( 'two well-formed requests are kept; a bad id, junk and a duplicate are dropped', 2 === count( $all ) );
check( 'soonest answer-by date first', $b === $all[0]['order_id'] && $a === $all[1]['order_id'] );
$first = SudemiRequests::forOrder( $a );
check( 'order ids are lower-cased', null !== $first && $a === $first['order_id'] );
check( 'the reason is trimmed with line breaks normalised, kept as plain text', "Too small\n<b>really</b>" === $first['reason'] );
check( 'the stored value holds no < > or & (PrestaShop would strip or encode them)',
	! preg_match( '/[<>&]/', (string) Configuration::$values[ SudemiRequests::CONF_OPEN ] ) );
check( 'no reason: empty string', '' === SudemiRequests::forOrder( $b )['reason'] );
check( 'the dates are kept', '2026-11-14T10:00:00Z' === $first['respond_by'] && '2026-11-13T09:00:00.123456Z' === SudemiRequests::forOrder( $b )['respond_by'] );
SudemiRequests::store( array( array( 'order_id' => $a, 'respond_by' => '<script>' ) ) );
check( 'a new beat replaces the list', 1 === count( SudemiRequests::all() ) && null === SudemiRequests::forOrder( $b ) );
check( 'a date that is not an instant is not stored', '' === SudemiRequests::forOrder( $a )['respond_by'] );
SudemiRequests::store( array() );
check( 'a beat without open_requests clears the list', array() === SudemiRequests::all() && ! isset( Configuration::$values[ SudemiRequests::CONF_OPEN ] ) );
SudemiRequests::store( array( array( 'order_id' => $a, 'reason' => str_repeat( 'é', 1500 ) ) ) );
check( 'a very long reason is cut to 1000 characters', 1000 === mb_strlen( SudemiRequests::forOrder( $a )['reason'] ) );

// Answering.
SudemiRequests::store( $beat );
SudemiClient::$calls = array();
SudemiClient::$next  = answer( 200, array( 'status' => 'ok' ) );
$r = SudemiRequests::answer( strtoupper( $a ), true );
check( 'accept: signed POST /integration/v1/orders/{id}/request/accept with an empty body',
	array( array( 'POST', '/integration/v1/orders/' . $a . '/request/accept', array() ) ) === SudemiClient::$calls );
check( 'accept: ok, and the request leaves the stored list', $r['ok'] && SudemiRequests::OK === $r['outcome']
	&& null === SudemiRequests::forOrder( $a ) && null !== SudemiRequests::forOrder( $b ) );

SudemiClient::$calls = array();
foreach ( array( '', '   ', "\n", null ) as $blank ) {
	$r = SudemiRequests::answer( $b, false, $blank );
	check( 'reject with a blank reason (' . json_encode( $blank ) . '): refused before sending', ! $r['ok'] && SudemiRequests::REASON_REQUIRED === $r['outcome'] );
}
check( 'nothing was sent for the blank reasons, and the request stays listed', array() === SudemiClient::$calls && null !== SudemiRequests::forOrder( $b ) );

SudemiClient::$next = answer( 200, array( 'status' => 'ok' ) );
$r = SudemiRequests::answer( $b, false, "  Worn and washed\r\n " );
check( 'reject: POST .../request/reject with the trimmed reason',
	array( 'POST', '/integration/v1/orders/' . $b . '/request/reject', array( 'reason' => 'Worn and washed' ) ) === SudemiClient::$calls[0] );
check( 'reject: ok, removed from the list', $r['ok'] && null === SudemiRequests::forOrder( $b ) );

SudemiClient::$calls = array();
$r = SudemiRequests::answer( '../orders', true );
check( 'an order id that is not a UUID never reaches sudemi', ! $r['ok'] && array() === SudemiClient::$calls );

$cases = array(
	array( answer( 200, array( 'status' => 'already_resolved' ) ), SudemiRequests::ALREADY, true, true ),
	array( answer( 404, array( 'error' => 'not_found' ) ), SudemiRequests::NOT_FOUND, false, true ),
	array( answer( 400, array( 'error' => 'invalid_request' ) ), SudemiRequests::INVALID, false, false ),
	array( null, SudemiRequests::UNREACHABLE, false, false ),
	array( answer( 409, array( 'error' => 'site_mismatch' ) ), SudemiRequests::SITE, false, false ),
	array( answer( 500, array() ), SudemiRequests::FAILED, false, false ),
	array( answer( 200, array( 'status' => 'weird' ) ), SudemiRequests::FAILED, false, false ),
	array( answer( 401, array( 'error' => 'bad_signature' ) ), SudemiRequests::FAILED, false, false ),
);
foreach ( $cases as $case ) {
	SudemiRequests::store( $beat );
	SudemiClient::$next = $case[0];
	$r = SudemiRequests::answer( $a, false, 'No' );
	$label = null === $case[0] ? 'no answer' : $case[0]['status'] . ' ' . json_encode( $case[0]['body'] );
	check( "$label -> {$case[1]}", $case[1] === $r['outcome'] && $case[2] === $r['ok'] );
	check( "$label -> " . ( $case[3] ? 'removed from the list' : 'kept in the list' ), $case[3] === ( null === SudemiRequests::forOrder( $a ) ) );
}

// settled_orders: a buyer cancellation of a paid order reaches the local order.
$st = array( 'preparation' => 3, 'canceled' => 6, 'refund' => 7, 'error' => 8 );
$payment = 2;
check( 'CANCELLED on a paid, unshipped order: cancel it here', 'buyer_cancel' === SudemiOrders::settleAction( 'CANCELLED', $payment, false, $st ) );
check( 'CANCELLED on an unpaid order here: cancel it too', 'buyer_cancel' === SudemiOrders::settleAction( 'CANCELLED', 3, false, $st ) );
check( 'CANCELLED, already cancelled here: left alone (idempotent)', null === SudemiOrders::settleAction( 'CANCELLED', 6, false, $st ) );
check( 'CANCELLED, already refunded here: left alone', null === SudemiOrders::settleAction( 'CANCELLED', 7, false, $st ) );
check( 'CANCELLED, payment error here: left alone', null === SudemiOrders::settleAction( 'CANCELLED', 8, false, $st ) );
check( 'CANCELLED but already shipped or delivered here: left alone', null === SudemiOrders::settleAction( 'CANCELLED', 4, true, $st ) );
check( 'DECLINED on an unpaid order: release', 'release' === SudemiOrders::settleAction( 'DECLINED', 3, false, $st ) );
check( 'DECLINED on an order already paid here: left alone', null === SudemiOrders::settleAction( 'DECLINED', $payment, false, $st ) );
check( 'PAID on an unpaid order: pay', 'pay' === SudemiOrders::settleAction( 'PAID', 3, false, $st ) );
check( 'SHIPPED on an unpaid order: pay', 'pay' === SudemiOrders::settleAction( 'SHIPPED', 3, false, $st ) );
check( 'PAID on an order already paid here: nothing', null === SudemiOrders::settleAction( 'PAID', $payment, false, $st ) );
check( 'REFUNDED on an unpaid order is not taken as paid', null === SudemiOrders::settleAction( 'REFUNDED', 3, false, $st ) );
check( 'the note says the buyer cancelled and was refunded in full',
	'Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full.' === SudemiOrders::BUYER_CANCEL_NOTE );

// The cancellation must not be sent back to sudemi: settle changes the state through setState,
// which raises the flag the status hook checks. Checked on the source, as the hook needs PrestaShop.
$orders = (string) file_get_contents( __DIR__ . '/../classes/SudemiOrders.php' );
$module = (string) file_get_contents( __DIR__ . '/../sudemi.php' );
check( 'the buyer-cancel branch changes the state through setState (flagged as the module\'s own)',
	(bool) preg_match( "/action === 'buyer_cancel'\\) \\{.*?self::setState\\(\\\$order, \\(int\\) Configuration::get\\('PS_OS_CANCELED'\\)\\)/s", $orders ) );
check( 'the status hook sends no /cancel or /refund while the module changes the state',
	(bool) preg_match( '/if \(!SudemiOrders::\$changingState\) \{.*?SudemiOrders::refund.*?SudemiOrders::rejectFromShop/s', $module ) );

// Every string the module page and the order page show is translated into all twelve languages
// (English is the source). Keys are md5 of the source exactly as written in sudemi.php.
preg_match_all( "/\\\$this->l\\('([^'\\\\]*)'\\)/", $module, $found );
$strings = array_unique( $found[1] );
check( 'the return-request strings are among the module strings', in_array( 'Reason for rejecting (shown to the buyer)', $strings, true ) && in_array( 'Accept return', $strings, true ) );
foreach ( array( 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ) as $lang ) {
	global $_MODULE;
	$_MODULE = array();
	include __DIR__ . '/../translations/' . $lang . '.php';
	$missing = array();
	foreach ( $strings as $source ) {
		$key = '<{sudemi}prestashop>sudemi_' . md5( $source );
		if ( ! isset( $_MODULE[ $key ] ) || '' === trim( $_MODULE[ $key ] ) ) {
			$missing[] = $source;
		}
	}
	check( "$lang translates every string in sudemi.php" . ( $missing ? ': missing ' . implode( ' | ', array_slice( $missing, 0, 3 ) ) : '' ), array() === $missing );
	$copies = 0;
	foreach ( array( 'Return requests', 'Accept return', 'Reject return', 'Reason given by the buyer' ) as $source ) {
		$copies += ( $_MODULE[ '<{sudemi}prestashop>sudemi_' . md5( $source ) ] ?? '' ) === $source ? 1 : 0;
	}
	check( "$lang: the new strings are translated, not English copies", 0 === $copies );
	check( "$lang: no em dash", false === strpos( implode( '', $_MODULE ), "\u{2014}" ) );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
