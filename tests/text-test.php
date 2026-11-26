<?php
/**
 * Category descriptions sent to sudemi are plain text: PrestaShop stores them as the rich-text editor's
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

// 1.4.9 (sudemi owner decision 2026-11-26): product descriptions go as HTML; sudemi keeps the
// paragraphs, lists and headings and removes everything else itself.
$rich = '<h2>Care</h2><p>Salt &amp; Pepper <strong>mill</strong></p><ul><li>Steel</li><li>Oak</li></ul>';
check( 'a product description is sent as HTML (lists and headings kept)', $rich === SudemiSync::descriptionHtml( "\n" . $rich . ' ' ), SudemiSync::descriptionHtml( $rich ) );
check( 'an empty product description stays empty', '' === SudemiSync::descriptionHtml( null ) );
$sync = (string) file_get_contents( __DIR__ . '/../classes/SudemiSync.php' );
check( 'the listing sends descriptionHtml(), not plain()',
	1 === preg_match( "/'description' => self::descriptionHtml\\(\\\$product->description_short\\)/", $sync )
	&& false === strpos( $sync, "'description' => self::plain(\$product->description_short)" ) );
$upgrade = (string) file_get_contents( __DIR__ . '/../upgrade/upgrade-1.4.9.php' );
check( 'the 1.4.9 upgrade asks for a full sync (descriptions are sent again)',
	false !== strpos( $upgrade, 'function upgrade_module_1_4_9(' ) && false !== strpos( $upgrade, 'SudemiSync::request();' ) );
$changelog = (string) file_get_contents( __DIR__ . '/../CHANGELOG.md' );
check( 'CHANGELOG.md starts with 1.4.9', 1 === preg_match( '/^# Changelog\s+## 1\.4\.9\n/', $changelog ) );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall text checks passed\n";
