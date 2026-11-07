<?php
/**
 * Which order state reports what to sudemi. PrestaShop's Shipped and Delivered states carry the
 * same flags, so Delivered is told apart by its id (PS_OS_DELIVERED), never by the delivery flag.
 *
 * Runs without PrestaShop: the module class is loaded against an empty Module stand-in.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

class Module {
}

require __DIR__ . '/../sudemi.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

// Stock PrestaShop: 3 Processing in progress (delivery=1, shipped=0), 4 Shipped and 5 Delivered
// (both shipped=1, delivery=1), 2 Payment accepted.
check( 'Delivered (by id) reports delivered', 'delivered' === Sudemi::orderEvent( 5, true, 5 ) );
check( 'Shipped reports shipped', 'shipped' === Sudemi::orderEvent( 4, true, 5 ) );
check( 'a custom state flagged shipped reports shipped', 'shipped' === Sudemi::orderEvent( 17, true, 5 ) );
check( 'Processing in progress (delivery slip, not shipped) reports nothing', null === Sudemi::orderEvent( 3, false, 5 ) );
check( 'Payment accepted reports nothing', null === Sudemi::orderEvent( 2, false, 5 ) );
check( 'no Delivered state configured: never delivered', 'shipped' === Sudemi::orderEvent( 0, true, 0 ) );
check( 'the module version is 1.2.5', '1.2.5' === SUDEMI_VERSION );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
