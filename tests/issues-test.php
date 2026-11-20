<?php
/**
 * 1.4.4 (owner 2026-11-19: "we need to know what we are failing"): what the module left out is
 * sent to sudemi as the push's top-level `issues` [{externalId, code, severity, field, message,
 * details}] and kept with sudemi's answer for the shop's page; every code has plain words in all
 * twelve languages.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

require __DIR__ . '/../classes/SudemiIssues.php';

class Configuration {
	public static $v = array();
	public static $fail = false;
	public static function get( $k ) {
		return isset( self::$v[ $k ] ) ? self::$v[ $k ] : false;
	}
	public static function updateValue( $k, $v ) {
		if ( self::$fail ) {
			return false;
		}
		// PrestaShop's pSQL() strips tags from a value written without html.
		self::$v[ $k ] = strip_tags( (string) $v );
		return true;
	}
	public static function deleteByName( $k ) {
		unset( self::$v[ $k ] );
		return true;
	}
}
class SudemiLogger {
	public static $warned = array();
	public static function warn( $m ) {
		self::$warned[] = $m;
	}
}
class SudemiClient {
	public static $calls = array();
	public static $status = 200;
	public static function jsonFlags() {
		return defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
	}
	public static function postSigned( $path, $body ) {
		self::$calls[] = array( $path, $body );
		return array( 'status' => self::$status, 'body' => array() );
	}
}
require __DIR__ . '/../classes/SudemiText.php';

$failures = 0;

function check( string $what, bool $passed, $got = null ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' got ' . var_export( $got, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

$i = SudemiIssues::make( '12-34', SudemiIssues::TOO_MANY_REQUIRED_TEXTS, 'error', 'customization', 'Not listed' );
check( 'the wire shape uses the contract names', array( 'externalId', 'code', 'severity', 'field', 'message', 'details' ) === array_keys( $i ) );
check( 'details is a JSON object', '{}' === json_encode( $i['details'] ) );
check( 'an unknown severity becomes warning', 'warning' === SudemiIssues::make( 1, 'x', 'fatal', '', '' )['severity'] );

SudemiIssues::add( 5, 'missing_price', 'error', 'price', 'no price' );
SudemiIssues::add( 5, 'missing_price', 'error', 'price', 'no price again' );
SudemiIssues::add( '6-7', 'too_many_required_texts', 'error', 'customization', 'six' );
$taken = SudemiIssues::take();
check( 'the same issue twice is sent once', 2 === count( $taken ) );
check( 'taken once: nothing pending afterwards', array() === SudemiIssues::take() );
$body = SudemiIssues::attach( array( 'products' => array() ), $taken );
check( 'the push carries issues at the top level', isset( $body['issues'] ) && 2 === count( $body['issues'] ) );
check( 'no issues: no field', ! array_key_exists( 'issues', SudemiIssues::attach( array( 'products' => array() ), array() ) ) );

$kept = SudemiIssues::merge( array(), array( array( 'externalId' => '5' ) ), $taken, array( 'rejected' => array( array( 'externalId' => '9', 'reason' => 'price is 0' ) ) ), 100 );
check( 'kept: the module\'s two and sudemi\'s refusal', 3 === count( $kept ) && 'sudemi' === $kept[2]['source'] && 'rejected' === $kept[2]['code'] && 'error' === $kept[2]['severity'] );
$kept = SudemiIssues::merge( $kept, array( array( 'externalId' => '5' ) ), array(), array(), 200 );
check( 'a later push of 5 without issues clears its entries, others stay', array( '6-7', '9' ) === array_map( function ( $k ) { return $k['externalId']; }, $kept ) );
check( 'the product of a combination\'s external id', 6 === SudemiIssues::productId( '6-7' ) );

// Kept list: tags survive the configuration write, invalid UTF-8 never empties it, capped by bytes.
SudemiIssues::remember( array(), array( SudemiIssues::make( 1, 'missing_price', 'error', 'price', 'Name <b>x</b> & "y" ' . "\xC3\x28" . ' מחיר' ) ), null, true );
$all = SudemiIssues::all();
check( 'kept: tags, quotes and Hebrew survive the write; invalid UTF-8 replaced', 1 === count( $all ) && false !== strpos( $all[0]['message'], '<b>x</b> & "y"' ) && false !== strpos( $all[0]['message'], 'מחיר' ), $all );
$many = array();
for ( $n = 0; $n < 2000; $n++ ) {
	$many[] = array( 'externalId' => (string) $n, 'source' => 'module', 'code' => 'missing_price', 'severity' => 'error', 'field' => 'price', 'message' => str_repeat( 'x', 60 ), 'at' => 1 );
}
$json = SudemiIssues::encode( $many );
$back = json_decode( (string) $json, true );
check( 'encode: capped at 50 KB, newest kept, never empty', strlen( (string) $json ) <= SudemiIssues::MAX_BYTES && count( $back ) > 100 && '1999' === end( $back )['externalId'], strlen( (string) $json ) );
Configuration::$fail = true;
SudemiIssues::remember( array(), array( SudemiIssues::make( 2, 'missing_price', 'error', 'price', 'm' ) ), null, true );
Configuration::$fail = false;
check( 'a failed write keeps the list as it was and logs it', 1 === count( SudemiIssues::all() ) && ! empty( SudemiLogger::$warned ) );

// Issues of a product not listed: queued (no request while saving), sent once by the heartbeat.
SudemiIssues::queue( array( SudemiIssues::make( 11, 'missing_price', 'error', 'price', 'a' ) ) );
SudemiIssues::queue( array( SudemiIssues::make( '12-3', 'too_many_required_texts', 'error', 'customization', 'b' ), SudemiIssues::make( '12-3', 'missing_price', 'error', 'price', 'c' ) ) );
check( 'queue: no request, shown on the page at once', 0 === count( SudemiClient::$calls ) && 3 <= count( SudemiIssues::all() ) );
check( 'queued: whole products up to the limit', array( 11 ) === array_keys( SudemiIssues::queued( 2 ) ), SudemiIssues::queued( 2 ) );
SudemiClient::$status = 503;
check( 'drain on failure: one request, kept', false === SudemiIssues::drain() && 1 === count( SudemiClient::$calls ) && 3 === count( SudemiIssues::flat( SudemiIssues::queued() ) ) );
SudemiClient::$status = 200;
$ok = SudemiIssues::drain();
$sent = SudemiClient::$calls[1][1];
check( 'drain: one request with every waiting issue, no products, details as objects', $ok && 3 === count( $sent['issues'] ) && array() === $sent['products'] && '{}' === json_encode( $sent['issues'][0]['details'] ), $sent );
check( 'drain: nothing left, nothing more sent', array() === SudemiIssues::queued() && false === SudemiIssues::drain() && 2 === count( SudemiClient::$calls ) );
SudemiIssues::queue( array( SudemiIssues::make( 13, 'missing_price', 'error', 'price', 'd' ) ) );
SudemiIssues::unqueue( array( '13' ) );
check( 'unqueue: a newer push of the product drops what waited', array() === SudemiIssues::queued() );

foreach ( SudemiIssues::CODES as $code ) {
	check( "plain words for $code", isset( SudemiText::EN[ 'is_' . $code ] ) );
}
foreach ( array( 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ) as $lang ) {
	$missing = array();
	$_MODULE = array();
	include __DIR__ . '/../translations/' . $lang . '.php';
	foreach ( SudemiText::EN as $key => $english ) {
		// Some words are the same in English ("Details"): the entry has to exist all the same.
		if ( 0 === strpos( $key, 'is_' ) && empty( $_MODULE[ '<{sudemi}prestashop>sudemi_' . md5( $english ) ] ) ) {
			$missing[] = $key;
		}
	}
	check( "$lang: every issue text translated", array() === $missing, $missing );
}

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
