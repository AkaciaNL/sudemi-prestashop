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
