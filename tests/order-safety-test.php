<?php
/**
 * Order safety (1.2.6): only a definitive answer from sudemi decides a local order. An accept
 * answered already_resolved/PAID keeps the order; a 5xx, 429, 401, 403 or 409 never cancels it.
 * Also the listing batches of a full sync (at most 500 per request) and the price queue keeping
 * a newer update queued while an older one was in flight.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

require __DIR__ . '/../classes/SudemiOrders.php';
require __DIR__ . '/../classes/SudemiSync.php';
require __DIR__ . '/../classes/SudemiQueue.php';

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

$accept = function ( $r ) { return SudemiOrders::acceptOutcome( $r ); };

check( 'accept ok (captured) is paid', 'paid' === $accept( answer( 200, array( 'status' => 'ok', 'payment' => 'captured' ) ) ) );
check( 'accept ok without payment field is paid', 'paid' === $accept( answer( 200, array( 'status' => 'ok' ) ) ) );
check( 'accept ok waiting for other shops is waiting', 'waiting' === $accept( answer( 200, array( 'status' => 'ok', 'payment' => 'waiting_for_other_shops' ) ) ) );
foreach ( array( 'PAID', 'SHIPPED', 'DELIVERED' ) as $s ) {
	check( "already_resolved $s keeps the order (paid)", 'paid' === $accept( answer( 200, array( 'status' => 'already_resolved', 'order_status' => $s ) ) ) );
}
check( 'already_resolved ACCEPTED is waiting', 'waiting' === $accept( answer( 200, array( 'status' => 'already_resolved', 'order_status' => 'ACCEPTED' ) ) ) );
check( 'already_resolved DECLINED is declined', 'declined' === $accept( answer( 200, array( 'status' => 'already_resolved', 'order_status' => 'DECLINED' ) ) ) );
check( 'already_resolved CANCELLED is cancelled', 'cancelled' === $accept( answer( 200, array( 'status' => 'already_resolved', 'order_status' => 'CANCELLED' ) ) ) );
check( 'already_resolved REFUNDED is cancelled', 'cancelled' === $accept( answer( 200, array( 'status' => 'already_resolved', 'order_status' => 'REFUNDED' ) ) ) );
check( 'already_resolved without order_status (old core) is retry, never a cancel', 'retry' === $accept( answer( 200, array( 'status' => 'already_resolved' ) ) ) );
check( 'already_resolved AWAITING_SELLER is retry', 'retry' === $accept( answer( 200, array( 'status' => 'already_resolved', 'order_status' => 'AWAITING_SELLER' ) ) ) );
check( 'already_resolved UNKNOWN is retry', 'retry' === $accept( answer( 200, array( 'status' => 'already_resolved', 'order_status' => 'UNKNOWN' ) ) ) );
check( 'unreachable is retry', 'retry' === $accept( null ) );
foreach ( array( 500, 502, 503, 429, 401, 403, 400 ) as $code ) {
	check( "HTTP $code is retry", 'retry' === $accept( answer( $code, array( 'error' => 'x' ) ) ) );
}
check( '409 site_mismatch is retry', 'retry' === $accept( answer( 409, array( 'error' => 'site_mismatch' ) ) ) );
check( '409 authorisation_lapsed is retry', 'retry' === $accept( answer( 409, array( 'error' => 'authorisation_lapsed' ) ) ) );
check( '404 no_such_order is declined', 'declined' === $accept( answer( 404, array( 'error' => 'no_such_order' ) ) ) );
check( '404 from something else (a proxy) is retry', 'retry' === $accept( answer( 404, array( 'error' => 'not_found' ) ) ) );

$reject = function ( $r ) { return SudemiOrders::rejectOutcome( $r ); };
check( 'reject ok is declined', 'declined' === $reject( answer( 200, array( 'status' => 'ok' ) ) ) );
check( 'reject already_resolved PAID is paid (refund needed)', 'paid' === $reject( answer( 200, array( 'status' => 'already_resolved', 'order_status' => 'PAID' ) ) ) );
check( 'reject already_resolved DECLINED is declined', 'declined' === $reject( answer( 200, array( 'status' => 'already_resolved', 'order_status' => 'DECLINED' ) ) ) );
check( 'reject 503 is retry', 'retry' === $reject( answer( 503, array() ) ) );
check( 'reject unreachable is retry', 'retry' === $reject( null ) );

$call = function ( $r ) { return SudemiOrders::callOutcome( $r ); };
check( 'shipped 200 is done', 'done' === $call( answer( 200, array( 'status' => 'ok' ) ) ) );
check( 'shipped already_resolved is done', 'done' === $call( answer( 200, array( 'status' => 'already_resolved' ) ) ) );
check( 'shipped 503 is retried', 'retry' === $call( answer( 503, array() ) ) );
check( 'shipped unreachable is retried', 'retry' === $call( null ) );
check( 'shipped 409 not_paid is retried (may be paid later)', 'retry' === $call( answer( 409, array( 'error' => 'not_paid' ) ) ) );
check( 'shipped 429 is retried', 'retry' === $call( answer( 429, array() ) ) );
check( 'shipped 404 is refused', 'refused' === $call( answer( 404, array( 'error' => 'no_such_order' ) ) ) );

// Full sync batches: never more than 500 listings in one request.
$listings = range( 1, 1234 );
list( $batches, $rest ) = SudemiSync::chunkListings( $listings, 500, false );
check( '1234 listings: two full batches of 500 now, 234 kept for later', 2 === count( $batches ) && 500 === count( $batches[0] ) && 234 === count( $rest ) );
list( $batches, $rest ) = SudemiSync::chunkListings( $rest, 500, true );
check( 'final flush sends the remaining 234', 1 === count( $batches ) && 234 === count( $batches[0] ) && array() === $rest );
check( 'max_products_per_request smaller than 500 is honoured', 200 === SudemiSync::listingsPerRequest( array( 'max_products_per_request' => 200 ) ) );
check( 'max_products_per_request larger than 500 is capped', 500 === SudemiSync::listingsPerRequest( array( 'max_products_per_request' => 5000 ) ) );
check( 'no max in the answer: 500', 500 === SudemiSync::listingsPerRequest( array( 'sync_id' => 'x' ) ) );

// Price queue: only entries still equal to what was sent are cleared.
$sent    = array( '7' => array( 'externalId' => '7', 'priceMinor' => 1000 ), '8' => array( 'externalId' => '8', 'priceMinor' => 500 ) );
$current = array( '7' => array( 'externalId' => '7', 'priceMinor' => 1200 ), '8' => array( 'externalId' => '8', 'priceMinor' => 500 ), '9' => array( 'externalId' => '9', 'priceMinor' => 1 ) );
$left    = SudemiQueue::withoutSent( $current, $sent );
check( 'a newer price for the same product queued in flight survives the drain', isset( $left['7'] ) && 1200 === $left['7']['priceMinor'] );
check( 'the sent, unchanged entry is cleared', ! isset( $left['8'] ) );
check( 'an entry added in flight stays', isset( $left['9'] ) );

// What the buyer paid (1.2.10): the shop's order is booked at it, shipping included.
$mug = array(
	'total_minor'    => 1345,
	'shipping_minor' => 495,
	'currency_code'  => 'EUR',
	'lines'          => array( array( 'external_id' => '14', 'quantity' => 1, 'unit_price_minor' => 850 ) ),
);
check( 'paid amounts read', array( 'shipping' => 495, 'lines' => array( 0 => 850 ), 'total' => 1345 ) === SudemiOrders::paidAmounts( $mug ) );
$older = $mug;
unset( $older['shipping_minor'] );
check( 'older core without shipping: catalogue prices', null === SudemiOrders::paidAmounts( $older ) );
$older = $mug;
unset( $older['lines'][0]['unit_price_minor'] );
check( 'older core without line prices: catalogue prices', null === SudemiOrders::paidAmounts( $older ) );
check( 'net of 8.50 at 21% (catalogue 10.00 / 12.10)', 702 === SudemiOrders::netOf( 850, 10.0, 12.1 ) );
check( 'net without tax is the gross', 850 === SudemiOrders::netOf( 850, 8.5, 8.5 ) );
check( 'net with a zero price is the gross', 850 === SudemiOrders::netOf( 850, 0.0, 0.0 ) );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
