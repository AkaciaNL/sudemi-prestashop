<?php
/**
 * The support client: what it sends to sudemi (path, method, body), what it refuses to send,
 * and how sudemi's answers become the module page's messages.
 *
 * Runs without PrestaShop: SudemiClient is replaced by a recorder, so no network is touched.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

/** Stands in for the real client: records each call and answers with the queued response. */
class SudemiClient {
	public static $calls = array();
	public static $next  = null;

	public static function sendSigned( $method, $path, array $body = array() ) {
		self::$calls[] = array( $method, $path, $body );
		return self::$next;
	}
}

require __DIR__ . '/../classes/SudemiSupport.php';

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

$id     = '0b6f1c1e-2a8c-4c55-9f0e-6f1d2c3b4a59';
$order  = '7d9e8f00-1111-4222-8333-944455556666';
$detail = array( 'id' => $id, 'number' => 'S-000042', 'subject' => 'Payout missing', 'status' => 'OPEN', 'messages' => array() );

// Locale: only sudemi's twelve languages, two letters.
foreach ( array( 'en', 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ) as $lang ) {
	check( "locale '$lang' is passed on", $lang === SudemiSupport::locale( $lang ) );
}
check( 'locale is lower-cased and cut to two letters', 'de' === SudemiSupport::locale( 'DE-at' ) );
check( 'a language sudemi does not write is not sent', null === SudemiSupport::locale( 'pt' ) );
check( 'no language: no locale', null === SudemiSupport::locale( null ) );

// Ticket ids go into a path, so only a UUID gets there.
check( 'a UUID is a ticket id', SudemiSupport::isTicketId( $id ) );
foreach ( array( '', 'S-000042', '../orders', $id . '/messages', $id . "\n", null, 42 ) as $bad ) {
	check( 'not a ticket id: ' . json_encode( $bad ), ! SudemiSupport::isTicketId( $bad ) );
}

// Validation before anything is sent.
$built = SudemiSupport::buildTicket( 'payouts', '  Payout missing ', "Line one\r\nLine two\n", $order, 'nl' );
check( 'a good form becomes the body sudemi expects', $built['ok'] && $built['body'] === array(
	'category' => 'PAYOUTS', 'subject' => 'Payout missing', 'message' => "Line one\nLine two", 'orderId' => $order, 'locale' => 'nl',
) );
$plain = SudemiSupport::buildTicket( 'OTHER', 'Hello', 'Hi', '', 'pt' );
check( 'no order and an unsupported language: neither is sent', $plain['ok'] && ! isset( $plain['body']['orderId'] ) && ! isset( $plain['body']['locale'] ) );
check( 'an unknown category is refused', SudemiSupport::ERR_CATEGORY === SudemiSupport::buildTicket( 'BILLING', 'Hello', 'Hi', null, 'en' )['error'] );
check( 'a two-character subject is refused', SudemiSupport::ERR_SUBJECT === SudemiSupport::buildTicket( 'ORDER', ' ab ', 'Hi', null, 'en' )['error'] );
check( 'a 200-character subject is fine', SudemiSupport::buildTicket( 'ORDER', str_repeat( 'é', 200 ), 'Hi', null, 'en' )['ok'] );
check( 'a 201-character subject is refused', SudemiSupport::ERR_SUBJECT === SudemiSupport::buildTicket( 'ORDER', str_repeat( 'a', 201 ), 'Hi', null, 'en' )['error'] );
check( 'a blank message is refused', SudemiSupport::ERR_MESSAGE === SudemiSupport::buildTicket( 'ORDER', 'Hello', " \n ", null, 'en' )['error'] );
check( 'a 10001-character message is refused', SudemiSupport::ERR_MESSAGE === SudemiSupport::buildTicket( 'ORDER', 'Hello', str_repeat( 'a', 10001 ), null, 'en' )['error'] );
check( 'an order that is not a sudemi id is refused', SudemiSupport::ERR_ORDER === SudemiSupport::buildTicket( 'ORDER', 'Hello', 'Hi', '1234', 'en' )['error'] );

SudemiClient::$calls = array();
$refused = SudemiSupport::createTicket( 'ORDER', 'x', 'Hi', null, 'en' );
check( 'an invalid form never reaches sudemi', ! $refused['ok'] && array() === SudemiClient::$calls );

// What is sent.
SudemiClient::$calls = array();
SudemiClient::$next  = answer( 201, array( 'ticket' => $detail ) );
$created = SudemiSupport::createTicket( 'ORDER', 'Payout missing', 'Where is it?', null, 'tr' );
check( 'open a ticket: signed POST to /integration/v1/support/tickets',
	array( 'POST', '/integration/v1/support/tickets' ) === array_slice( SudemiClient::$calls[0], 0, 2 ) );
check( 'open a ticket: the locale goes with it', 'tr' === SudemiClient::$calls[0][2]['locale'] );
check( 'open a ticket: 201 gives the ticket back', $created['ok'] && 'S-000042' === $created['ticket']['number'] );

SudemiClient::$calls = array();
SudemiClient::$next  = answer( 200, array( 'tickets' => array( $detail, 'junk' ) ) );
$list = SudemiSupport::listTickets();
check( 'list: a GET with no body', array( array( 'GET', '/integration/v1/support/tickets', array() ) ) === SudemiClient::$calls );
check( 'list: rows that are not tickets are dropped', $list['ok'] && 1 === count( $list['tickets'] ) );

SudemiClient::$calls = array();
SudemiClient::$next  = answer( 200, array( 'ticket' => $detail ) );
SudemiSupport::getTicket( strtoupper( $id ) );
check( 'view: GET /integration/v1/support/tickets/{id}', array( 'GET', '/integration/v1/support/tickets/' . $id, array() ) === SudemiClient::$calls[0] );

SudemiClient::$calls = array();
SudemiSupport::reply( $id, "  Thanks!\r\nStill missing.  " );
check( 'reply: POST .../{id}/messages with the trimmed message',
	array( 'POST', '/integration/v1/support/tickets/' . $id . '/messages', array( 'message' => "Thanks!\nStill missing." ) ) === SudemiClient::$calls[0] );

SudemiClient::$calls = array();
check( 'reply to a non-id: not found, nothing sent', SudemiSupport::ERR_NOT_FOUND === SudemiSupport::reply( '../orders', 'Hi' )['error'] && array() === SudemiClient::$calls );
check( 'empty reply: refused, nothing sent', SudemiSupport::ERR_MESSAGE === SudemiSupport::reply( $id, '   ' )['error'] && array() === SudemiClient::$calls );

// Answers from sudemi.
$cases = array(
	array( answer( 400, array( 'error' => 'invalid_request' ) ), SudemiSupport::ERR_INVALID ),
	array( answer( 404, array( 'error' => 'not_found' ) ), SudemiSupport::ERR_NOT_FOUND ),
	array( answer( 429, array( 'error' => 'too_many_tickets' ) ), SudemiSupport::ERR_TOO_MANY ),
	array( answer( 500, array() ), SudemiSupport::ERR_FAILED ),
	array( answer( 401, array( 'error' => 'bad_signature' ) ), SudemiSupport::ERR_FAILED ),
	array( null, SudemiSupport::ERR_UNREACHABLE ),
	array( answer( 201, array( 'ticket' => array( 'id' => 'not-a-uuid' ) ) ), SudemiSupport::ERR_FAILED ),
	array( answer( 201, array() ), SudemiSupport::ERR_FAILED ),
);
foreach ( $cases as $case ) {
	SudemiClient::$next = $case[0];
	$r = SudemiSupport::createTicket( 'OTHER', 'Hello', 'Hi', null, 'en' );
	check( 'answer ' . ( null === $case[0] ? 'none' : $case[0]['status'] ) . ' -> ' . $case[1], ! $r['ok'] && $case[1] === $r['error'] );
}
SudemiClient::$next = answer( 429, array( 'error' => 'too_many_tickets' ) );
check( 'a 429 on the list is "too many", not a wait', SudemiSupport::ERR_TOO_MANY === SudemiSupport::listTickets()['error'] );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
