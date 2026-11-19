<?php
/**
 * 1.4.4, contract v2: availability without stock (refuse or take orders, the date and the
 * "available later" text), dimensions, condition, quantity prices and a sale with a date window
 * (PrestaShop's "to" is the last moment, sudemi's until is exclusive), and a price update that
 * keeps the sale.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

require __DIR__ . '/../classes/SudemiProductData.php';

$failures = 0;

function check( string $what, bool $passed, $detail = '' ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' ' . var_export( $detail, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

$a = SudemiProductData::availability( 5, false, '0000-00-00', '', '2026-11-19' );
check( 'in stock', $a === array( 'allowBackorders' => false, 'availability' => 'in_stock' ), $a );
$a = SudemiProductData::availability( 0, false, '2026-12-01', 'Soon', '2026-11-19' );
check( 'none left, no orders without stock: out of stock', $a === array( 'allowBackorders' => false, 'availability' => 'out_of_stock' ), $a );
$a = SudemiProductData::availability( 0, true, '2026-12-01', ' Ships in <b>2-3</b> weeks ', '2026-11-19' );
check( 'none left, orders taken: backorder with the date and the shop\'s words', $a === array( 'allowBackorders' => true, 'availability' => 'backorder', 'availabilityDate' => '2026-12-01', 'availabilityText' => 'Ships in 2-3 weeks' ), $a );
$a = SudemiProductData::availability( 0, true, '2026-11-01', '', '2026-11-19' );
check( 'a date in the past is not sent', $a === array( 'allowBackorders' => true, 'availability' => 'backorder' ), $a );

check( 'dimensions in cm (depth is the length)', SudemiProductData::dimensions( 8, 12, 10, 'cm' ) === array( 'lengthMm' => 100, 'widthMm' => 80, 'heightMm' => 120 ) );
check( 'dimensions 0 not said', SudemiProductData::dimensions( 0, 0, 2.5, 'in' ) === array( 'lengthMm' => 64 ) );
check( 'condition', SudemiProductData::condition( 'Refurbished' ) === 'refurbished' && SudemiProductData::condition( 'mint' ) === null );

check( 'quantity prices only below the price for one, sorted', SudemiProductData::tiers( array( 10 => 1500, 3 => 1800, 2 => 2000 ), 2000 ) === array( array( 'minQuantity' => 3, 'priceMinor' => 1800 ), array( 'minQuantity' => 10, 'priceMinor' => 1500 ) ) );

$now  = mktime( 12, 0, 0, 11, 19, 2026 );
$sale = SudemiProductData::sale( 1200, 900, '2026-11-18 00:00:00', '2026-11-25 23:59:59', 'Europe/Amsterdam', $now );
check( 'a dated sale: regular, sale, from, and until one second after the last moment, with the shop\'s offset', $sale === array(
	'regularPriceMinor' => 1200, 'salePriceMinor' => 900,
	'salePriceFrom' => '2026-11-18T00:00:00+01:00', 'salePriceUntil' => '2026-11-26T00:00:00+01:00',
), $sale );
check( 'an ended sale is not sent', SudemiProductData::sale( 1200, 900, '2026-11-01 00:00:00', '2026-11-10 23:59:59', 'UTC', $now ) === array() );
check( 'not cheaper: no sale', SudemiProductData::sale( 1200, 1200, null, '2026-12-01 00:00:00', 'UTC', $now ) === array() );
check( 'open start (0000-00-00): no from', ! isset( SudemiProductData::sale( 1200, 900, '0000-00-00 00:00:00', '2026-12-01 00:00:00', 'UTC', $now )['salePriceFrom'] ) );

check( 'a coming 25% reduction', SudemiProductData::reduced( 1200, 1000, 'percentage', 0.25, true ) === 900 );
check( 'a coming 2.00 reduction with tax', SudemiProductData::reduced( 1200, 1000, 'amount', 200, true ) === 1000 );
check( 'a coming 2.00 reduction without tax (VAT added at the product\'s rate)', SudemiProductData::reduced( 1210, 1000, 'amount', 200, false ) === 968 );

check( 'a price update keeps the sale and availability', SudemiProductData::priceUpdateFields( array( 'priceMinor' => 900, 'salePriceMinor' => 900, 'regularPriceMinor' => 1200, 'allowBackorders' => false, 'title' => 'x' ) ) === array( 'regularPriceMinor' => 1200, 'salePriceMinor' => 900, 'allowBackorders' => false ) );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
