<?php
/**
 * The Sudemi-Site header and sudemi's 409 site_mismatch answer.
 *
 * A staging copy or a restored backup holds the live shop's install id and key. Every signed call
 * therefore says which site it comes from (Sudemi-Site, outside the signature), and sudemi refuses
 * a call from any other site than the connected shop with 409 site_mismatch. This proves the header
 * really goes out over HTTP on signed calls only, that the signature is unchanged by it, and that
 * the heartbeat records the refusal as "not connected" with the connected address.
 *
 * Runs without PrestaShop: sudemi is played by tests/fake-sudemi.php on PHP's built-in server.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );
define( '_DB_PREFIX_', 'ps_' );
define( 'SUDEMI_VERSION', 'test' );

$dir = sys_get_temp_dir() . '/sudemi-site-test-' . getmypid();
@mkdir( $dir );
$port   = 18000 + ( getmypid() % 1000 );
$server = proc_open(
	array( PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake-sudemi.php' ),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
	$pipes,
	null,
	array( 'SUDEMI_FAKE_DIR' => $dir )
);
register_shutdown_function(
	function () use ( $server, $dir ) {
		proc_terminate( $server );
		array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
		@rmdir( $dir );
	}
);
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $port ); $i++ ) {
	usleep( 100000 );
}

class Configuration {
	public static $values = array();
	public static function get( $key ) {
		return isset( self::$values[ $key ] ) ? self::$values[ $key ] : false;
	}
	public static function updateValue( $key, $value ) {
		self::$values[ $key ] = $value;
		return true;
	}
	public static function deleteByName( $key ) {
		unset( self::$values[ $key ] );
	}
}
class SudemiKeys {
	public static $signed = array();
	public static function hasKey() {
		return true;
	}
	public static function sign( $canonical ) {
		self::$signed[] = $canonical;
		return 'sig';
	}
}
class SudemiPairing {
	public static $url = 'https://staging.shop.example/';
	public static function shopUrl() {
		return self::$url;
	}
}
class SudemiLogger {
	public static function info( $message ) {}
	public static function warn( $message ) {}
}
class SudemiQueue {
	public static function drain() {
		return 0;
	}
	public static function count() {
		return 0;
	}
}
class SudemiOrders {
	public static function reconcile( array $skip = array() ) {}
	public static function drainCalls() {}
}
class Db {
	public static function getInstance() {
		return new self();
	}
	public function getRow( $sql ) {
		return array( 'n' => 3, 'newest' => '2026-11-07 00:00:00' );
	}
	public function getValue( $sql ) {
		return 3;
	}
}

require __DIR__ . '/../classes/SudemiClient.php';
require __DIR__ . '/../classes/SudemiHeartbeat.php';
require __DIR__ . '/../classes/SudemiRequests.php';
require __DIR__ . '/../classes/SudemiChanges.php';

Configuration::$values = array(
	'SUDEMI_INSTALL_ID' => 'inst-1',
	'SUDEMI_API_BASE'   => 'http://127.0.0.1:' . $port,
);

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

function answer_with( int $status, array $body ): void {
	global $dir;
	file_put_contents( $dir . '/answer.json', json_encode( array( 'status' => $status, 'body' => $body ) ) );
}

function last_request(): array {
	global $dir;
	$lines = file( $dir . '/requests.jsonl', FILE_IGNORE_NEW_LINES ) ?: array( '{}' );
	$request = json_decode( end( $lines ), true );
	$headers = array();
	foreach ( isset( $request['headers'] ) ? $request['headers'] : array() as $name => $value ) {
		$headers[ strtolower( $name ) ] = $value;
	}
	$request['headers'] = $headers;
	return $request;
}

// ---- the header, over HTTP ----
answer_with( 200, array( 'ok' => true ) );
$response = SudemiClient::postSigned( '/integration/v1/products', array( 'products' => array() ) );
check( 'the fake sudemi answered', is_array( $response ) && 200 === $response['status'] );
$sent = last_request()['headers'];
check( 'a signed POST carries Sudemi-Site', isset( $sent['sudemi-site'] ) && 'https://staging.shop.example/' === $sent['sudemi-site'] );
check( 'next to the four signing headers', isset( $sent['sudemi-install'], $sent['sudemi-timestamp'], $sent['sudemi-nonce'], $sent['sudemi-signature'] ) );
$canonical = end( SudemiKeys::$signed );
check( 'the signed string is still five lines', 5 === count( explode( "\n", $canonical ) ) );
check( 'the site is not part of the signed string', false === strpos( $canonical, 'staging.shop.example' ) );

SudemiClient::getSigned( '/integration/v1/orders/x' );
check( 'a signed GET carries Sudemi-Site', isset( last_request()['headers']['sudemi-site'] ) );

SudemiClient::sendSigned( 'POST', '/integration/v1/support/tickets', array( 'subject' => 'x' ) );
check( 'a one-shot signed call carries Sudemi-Site', isset( last_request()['headers']['sudemi-site'] ) );

SudemiClient::postUnsigned( '/integration/v1/pair', array( 'shopUrl' => 'x' ) );
check( 'an unsigned call (pairing) does not', ! isset( last_request()['headers']['sudemi-site'] ) );

SudemiPairing::$url = "https://shop.example/\r\nX-Evil: 1";
$headers = SudemiClient::signedHeaders( 'POST', '/x', '{}' );
check( 'line breaks never reach the header', 1 === count( preg_grep( '/^Sudemi-Site: https:\/\/shop\.example\/X-Evil: 1$/', $headers ) ) );
SudemiPairing::$url = 'https://staging.shop.example/';

// ---- reading the refusal ----
$message = 'This shop is connected to sudemi as https://shop.example.com, but this request came from https://staging.example.com. Requests from other sites (a staging copy, a restored backup) are refused. If this is a copy, disconnect sudemi here. If the shop moved, connect it again.';
check( '409 site_mismatch names the connected address', 'https://shop.example.com' === SudemiClient::siteMismatch( array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch', 'message' => $message ) ) ) );
check( 'an unreadable message still counts, without an address', '' === SudemiClient::siteMismatch( array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch' ) ) ) );
check( 'another 409 is not a site mismatch', null === SudemiClient::siteMismatch( array( 'status' => 409, 'body' => array( 'error' => 'conflict' ) ) ) );
check( 'no answer is not a site mismatch', null === SudemiClient::siteMismatch( null ) );

// ---- the heartbeat ----
answer_with( 409, array( 'error' => 'site_mismatch', 'message' => $message ) );
check( 'heartbeat: sudemi answered', true === SudemiHeartbeat::run() );
$status = SudemiHeartbeat::status();
check( 'heartbeat: the beat carried Sudemi-Site', isset( last_request()['headers']['sudemi-site'] ) );
check( 'heartbeat: reachable but not connected', true === $status['reachable'] && false === $status['connected'] );
check( 'heartbeat: the problem and the connected address are recorded', 'site_mismatch' === $status['problem'] && 'https://shop.example.com' === $status['paired'] );

answer_with( 401, array( 'error' => 'not_authenticated' ) );
SudemiHeartbeat::run();
$status = SudemiHeartbeat::status();
check( 'heartbeat: a 401 is still "not recognised", not a site mismatch', false === $status['connected'] && ! isset( $status['problem'] ) );

answer_with( 200, array( 'catalogue_listed' => true ) );
SudemiHeartbeat::run();
$status = SudemiHeartbeat::status();
check( 'heartbeat: the next good beat clears it', true === $status['connected'] && ! isset( $status['problem'] ) );
check( 'heartbeat: the beat asks for the change feed from the start (changesCursor "")',
	'' === ( json_decode( last_request()['body'], true )['changesCursor'] ?? null ) );

// ---- return requests ride on the beat: stored each 200 beat, cleared when the key is absent ----
$open = array( array( 'request_id' => 'aaaaaaaa-1111-4222-8333-944455556666', 'order_id' => '7d9e8f00-1111-4222-8333-944455556666',
	'kind' => 'RETURN', 'reason' => 'Too small', 'requested_at' => '2026-11-07T10:00:00Z', 'respond_by' => '2026-11-14T10:00:00Z' ) );
answer_with( 200, array( 'catalogue_listed' => true, 'open_requests' => $open ) );
SudemiHeartbeat::run();
check( 'heartbeat: open_requests are stored', 1 === count( SudemiRequests::all() ) && 'Too small' === SudemiRequests::all()[0]['reason'] );
answer_with( 500, array() );
SudemiHeartbeat::run();
check( 'heartbeat: a failed beat keeps the stored requests', 1 === count( SudemiRequests::all() ) );
answer_with( 200, array( 'catalogue_listed' => true ) );
SudemiHeartbeat::run();
check( 'heartbeat: a 200 beat without open_requests clears them', array() === SudemiRequests::all() );

// ---- the module page's wording, in all twelve languages ----
$sources = array(
	'sudemi refused requests from this site: this shop is connected to sudemi under a different address (%s). If this site is a staging copy or a restored backup, disconnect sudemi here. If the shop has moved to this address, connect it again.',
	'sudemi refused requests from this site: this shop is connected to sudemi under a different address. If this site is a staging copy or a restored backup, disconnect sudemi here. If the shop has moved to this address, connect it again.',
);
$module = file_get_contents( __DIR__ . '/../sudemi.php' );
foreach ( $sources as $source ) {
	check( 'sudemi.php says: ' . substr( $source, 70, 40 ) . '...', false !== strpos( $module, "\$this->l('" . $source . "')" ) );
}
foreach ( array( 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ) as $lang ) {
	global $_MODULE;
	$_MODULE = array();
	include __DIR__ . '/../translations/' . $lang . '.php';
	foreach ( $sources as $i => $source ) {
		$key = '<{sudemi}prestashop>sudemi_' . md5( $source );
		$ok  = isset( $_MODULE[ $key ] ) && '' !== $_MODULE[ $key ] && ( 0 === $i ? 1 === substr_count( $_MODULE[ $key ], '%s' ) : false === strpos( $_MODULE[ $key ], '%s' ) );
		check( "$lang translates the site-mismatch message " . ( 0 === $i ? 'with' : 'without' ) . ' the address', $ok );
	}
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
