<?php
/**
 * 1.4.4, owner decision 2: "Accept returns of personalised items" is a per-product setting, off by
 * default. Its label and help text exist in all twelve languages (English in the code, eleven
 * translation files keyed as PrestaShop's $this->l() looks them up), with no long dash, and the
 * module sends personalisationReturnable from it.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

$root    = dirname( __DIR__ );
$module  = (string) file_get_contents( $root . '/sudemi.php' );
$texts   = array(
	'Accept returns of personalised items',
	'Off (default): an item made with a text the buyer typed (a name, an engraving) cannot be returned, as the law allows; buyers are told before paying. On: buyers may return it like any other item.',
);
foreach ( $texts as $text ) {
	check( 'the form uses: ' . substr( $text, 0, 40 ), false !== strpos( $module, "\$this->l('" . $text . "')" ) );
}
foreach ( array( 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ) as $lang ) {
	$_MODULE = array();
	include $root . '/translations/' . $lang . '.php';
	foreach ( $texts as $text ) {
		$key = '<{sudemi}prestashop>sudemi_' . md5( $text );
		check( "$lang: translated: " . substr( $text, 0, 30 ), isset( $_MODULE[ $key ] ) && '' !== trim( $_MODULE[ $key ] ) && $_MODULE[ $key ] !== $text );
		check( "$lang: no long dash", ! isset( $_MODULE[ $key ] ) || false === strpos( $_MODULE[ $key ], "\u{2014}" ) );
	}
}
$sync = (string) file_get_contents( $root . '/classes/SudemiSync.php' );
check( 'the listing sends personalisationReturnable from the setting', false !== strpos( $sync, "\$payload['personalisationReturnable'] = SudemiWithdrawal::isReturnable(\$productId);" ) );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
