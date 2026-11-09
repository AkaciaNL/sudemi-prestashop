<?php
/**
 * Currency (1.2.12, protocol 4c): minor units per currency, orders in another currency declined,
 * a refund in another currency than the buyer paid in not sent, the refund body carrying the
 * currency, sudemi's echo of the shop currency kept, and the new strings in every language.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

class Configuration {
	public static $values = array();
	public static function get( $key ) { return isset( self::$values[ $key ] ) ? self::$values[ $key ] : false; }
	public static function updateValue( $key, $value ) { self::$values[ $key ] = $value; return true; }
	public static function deleteByName( $key ) { unset( self::$values[ $key ] ); return true; }
}

class SudemiLogger {
	public static $lines = array();
	public static function warn( $m ) { self::$lines[] = $m; }
}

require __DIR__ . '/../classes/SudemiCurrency.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

// Minor units.
check( 'EUR has 2 decimals', 2 === SudemiCurrency::decimals( 'EUR' ) );
check( 'HUF has 2 decimals (as sudemi counts it)', 2 === SudemiCurrency::decimals( 'HUF' ) );
check( 'JPY has 0 decimals', 0 === SudemiCurrency::decimals( 'jpy' ) );
check( 'KWD has 3 decimals', 3 === SudemiCurrency::decimals( 'KWD' ) );
check( '12.34 PLN is 1234', 1234 === SudemiCurrency::toMinor( '12.34', 'PLN' ) );
check( '19.999 EUR rounds to 2000', 2000 === SudemiCurrency::toMinor( 19.999, 'EUR' ) );
check( '1500 JPY is 1500, not 150000', 1500 === SudemiCurrency::toMinor( 1500, 'JPY' ) );
check( '1.234 KWD is 1234', 1234 === SudemiCurrency::toMinor( 1.234, 'KWD' ) );
check( 'no price stays null', null === SudemiCurrency::toMinor( null, 'EUR' ) && null === SudemiCurrency::toMinor( '', 'EUR' ) );
check( '5495 SEK minor is 54.95', abs( SudemiCurrency::fromMinor( 5495, 'SEK' ) - 54.95 ) < 1e-9 );
check( '1500 JPY minor is 1500', abs( SudemiCurrency::fromMinor( 1500, 'JPY' ) - 1500.0 ) < 1e-9 );
check( 'format 5495 PLN', '54.95 PLN' === SudemiCurrency::format( 5495, 'PLN' ) );
check( 'format 1500 JPY', '1500 JPY' === SudemiCurrency::format( 1500, 'JPY' ) );
check( 'format 1234 KWD', '1.234 KWD' === SudemiCurrency::format( 1234, 'KWD' ) );

// Supported shop currencies (protocol 4c).
foreach ( array( 'EUR', 'PLN', 'CZK', 'HUF', 'RON', 'SEK', 'DKK', 'CHF', 'GBP', 'NOK', 'TRY' ) as $c ) {
	check( "$c is a sudemi shop currency", SudemiCurrency::isSupported( $c ) );
}
foreach ( array( 'USD', 'JPY', 'ISK', '', 'EURO' ) as $c ) {
	check( "'$c' is not a sudemi shop currency", ! SudemiCurrency::isSupported( $c ) );
}

// Orders.
check( 'old core without currency_code: booked as before', null === SudemiCurrency::orderProblem( null, 'PLN', false ) );
check( 'empty currency_code: booked as before', null === SudemiCurrency::orderProblem( '', 'PLN', false ) );
check( 'order in the shop currency, switched on: booked', null === SudemiCurrency::orderProblem( 'PLN', 'PLN', true ) );
check( 'lower-case code is the same currency', null === SudemiCurrency::orderProblem( 'pln', 'PLN', true ) );
$problem = SudemiCurrency::orderProblem( 'EUR', 'PLN', true );
check( 'order in EUR at a PLN shop is declined, naming both', is_string( $problem ) && false !== strpos( $problem, 'EUR' ) && false !== strpos( $problem, 'PLN' ) );
$problem = SudemiCurrency::orderProblem( 'CZK', 'CZK', false );
check( 'order in a currency not switched on in the shop is declined', is_string( $problem ) && false !== strpos( $problem, 'not switched on' ) );
check( 'garbage currency_code is declined', is_string( SudemiCurrency::orderProblem( 'E1', 'EUR', true ) ) );

// Refunds.
$r = SudemiCurrency::refundBody( 1250, 'Partial refund (credit slip)', 'SEK', 'SEK' );
check( 'refund body carries amountMinor, reason and currency', isset( $r['body'] ) && array( 'reason' => 'Partial refund (credit slip)', 'amountMinor' => 1250, 'currency' => 'SEK' ) === $r['body'] );
$r = SudemiCurrency::refundBody( null, 'x', 'eur', 'EUR' );
check( 'full refund: no amountMinor, currency still sent', isset( $r['body'] ) && ! isset( $r['body']['amountMinor'] ) && 'EUR' === $r['body']['currency'] );
$r = SudemiCurrency::refundBody( 1000, 'x', 'EUR', 'PLN' );
check( 'refund in EUR on an order paid in PLN is refused, nothing to send', isset( $r['error'] ) && ! isset( $r['body'] ) && false !== strpos( $r['error'], 'PLN' ) );
$r = SudemiCurrency::refundBody( 1000, 'x', 'DKK', null );
check( 'order from before 1.2.12 (currency not recorded): sent with the order currency', isset( $r['body'] ) && 'DKK' === $r['body']['currency'] );
$r = SudemiCurrency::refundBody( 1000, 'x', '', 'NOK' );
check( 'unreadable local currency on a NOK order is refused', isset( $r['error'] ) );

// sudemi's echo of the shop currency.
check( 'nothing stored yet: the declared currency', 'PLN' === SudemiCurrency::seenBySudemi( 'PLN' ) );
SudemiCurrency::rememberShopAnswer( array( 'status' => 200, 'body' => array( 'status' => 'ok', 'currency' => 'czk' ) ), 'CZK' );
check( 'POST /shop echo is kept', 'CZK' === SudemiCurrency::seenBySudemi( 'PLN' ) );
SudemiCurrency::rememberShopAnswer( null, 'PLN' );
check( 'unreachable keeps the last echo', 'CZK' === SudemiCurrency::seenBySudemi( 'PLN' ) );
SudemiCurrency::rememberShopAnswer( array( 'status' => 400, 'body' => array( 'error' => 'rejected' ) ), 'USD' );
check( 'a refusal forgets the echo and is logged', 'USD' === SudemiCurrency::seenBySudemi( 'USD' ) && 1 === count( SudemiLogger::$lines ) );

// The new back-office strings in every language the module ships.
$strings = array(
	'Buyer paid',
	'Shop currency',
	'Buyers pay in this currency, and the orders sudemi sends you are in it. Your payout from sudemi is in euros; for any other currency the card company takes a conversion fee of about 2 percent from your payout.',
	'sudemi does not take payments in %1$s, so your products are not shown to buyers. sudemi takes these currencies: %2$s. Choose one of them as the default currency of this shop, then send the catalogue again.',
	'This shop now sells in %1$s, but sudemi still has %2$s. Send the catalogue again so buyers see the right prices.',
);
$source = file_get_contents( __DIR__ . '/../sudemi.php' );
foreach ( array( 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ) as $lang ) {
	$_MODULE = array();
	include __DIR__ . "/../translations/$lang.php";
	$missing = 0;
	foreach ( $strings as $s ) {
		$key = '<{sudemi}prestashop>sudemi_' . md5( $s );
		if ( empty( $_MODULE[ $key ] ) || ( false !== strpos( $s, '%2$s' ) && false === strpos( $_MODULE[ $key ], '%2$s' ) ) ) {
			$missing++;
		}
	}
	check( "all new strings translated in $lang", 0 === $missing );
}
foreach ( $strings as $s ) {
	check( 'string used in sudemi.php: ' . substr( $s, 0, 30 ), false !== strpos( $source, "'" . $s . "'" ) );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
