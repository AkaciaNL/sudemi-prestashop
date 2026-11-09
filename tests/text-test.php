<?php
/**
 * Descriptions sent to sudemi are plain text: PrestaShop stores them as the rich-text editor's
 * HTML, where "&" is "&amp;" and a no-break space "&nbsp;". strip_tags alone left those entities
 * in the text buyers read. SudemiSync::plain() removes the tags and decodes the entities.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );
require __DIR__ . '/../classes/SudemiSync.php';

$failures = 0;

function check( string $what, bool $passed, $got = null ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' got ' . var_export( $got, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

$html = '<p>Salt &amp; Pepper <b>bold</b>&nbsp;mill, caf&eacute;</p><p>2 &lt; 3</p>';
check( 'tags removed and entities decoded', 'Salt & Pepper bold mill, café2 < 3' === SudemiSync::plain( $html ), SudemiSync::plain( $html ) );
check( 'plain text stays as it is', 'Just text.' === SudemiSync::plain( ' Just text. ' ) );
check( 'empty stays empty', '' === SudemiSync::plain( null ) );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall text checks passed\n";
