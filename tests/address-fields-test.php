<?php
/**
 * Delivery address fields (1.4.2): a buyer's name or address with characters PrestaShop refuses
 * (a digit in a name, "(office)", "@" in a street line) is cleaned to what PrestaShop accepts
 * instead of stopping the order step. The validators below are PrestaShop 9's own rules.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

class Validate {
	public static function isName( $v ) { return (bool) preg_match( '/^[^0-9!<>,;?=+()@#"°{}_$%:¤|]*$/u', $v ); }
	public static function isAddress( $v ) { return empty( $v ) || preg_match( '/^[^!<>?=+@{}_$%]*$/u', $v ); }
	public static function isCityName( $v ) { return (bool) preg_match( '/^[^!<>;?=+@#"°{}_$%]*$/u', $v ); }
	public static function isPhoneNumber( $v ) { return (bool) preg_match( '/^[+0-9. ()\/-]*$/', $v ); }
	public static function isPostCode( $v ) { return empty( $v ) || preg_match( '/^[a-zA-Z 0-9-]+$/', $v ); }
}

class Tools {
	public static function substr( $s, $start, $length ) { return mb_substr( (string) $s, $start, $length ); }
}

require __DIR__ . '/../classes/SudemiOrders.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

check( 'an ordinary name is kept', 'Anna-Marie' === SudemiOrders::fitName( 'Anna-Marie' ) );
check( 'accents and apostrophes are kept', "Zoë O'Brien" === SudemiOrders::fitName( "Zoë O'Brien" ) );
check( 'non-Latin names are kept', '李小龙' === SudemiOrders::fitName( '李小龙' ) && 'שרה' === SudemiOrders::fitName( 'שרה' ) );
check( 'a digit in a name is dropped (E2e -> E e)', 'E e' === SudemiOrders::fitName( 'E2e' ) );
check( 'brackets are dropped', 'Jan office' === SudemiOrders::fitName( 'Jan (office)' ) );
check( 'the result always passes isName', Validate::isName( SudemiOrders::fitName( 'x@y; #1 {z}' ) ) );
check( 'nothing left becomes "-"', '-' === SudemiOrders::fitName( '123' ) && '-' === SudemiOrders::fitName( '' ) );

$line = SudemiOrders::fitField( 'Hauptstr. 5 @ Hinterhaus {2}', 'isAddress', '/[!<>?=+@{}_$%]/u', 128 );
check( 'street line: refused characters dropped, rest kept', 'Hauptstr. 5 Hinterhaus 2' === $line && Validate::isAddress( $line ) );
check( 'a valid street line is unchanged', 'ul. Długa 12/3' === SudemiOrders::fitField( 'ul. Długa 12/3', 'isAddress', '/[!<>?=+@{}_$%]/u', 128 ) );
check( 'postcode with a symbol is cleaned', '75001' === SudemiOrders::fitField( '75001!', 'isPostCode', '/[^a-zA-Z 0-9-]/', 12 ) );
check( 'city with a symbol is cleaned', 'Köln' === SudemiOrders::fitField( 'Köln;', 'isCityName', '/[!<>;?=+@#"°{}_$%]/u', 64 ) );
check( 'phone with letters is cleaned', '+49 30 1234' === SudemiOrders::fitField( '+49 30 1234 ext', 'isPhoneNumber', '/[^+0-9. ()\/-]/', 32 ) );
check( 'fields are cut to the column size', 64 === mb_strlen( SudemiOrders::fitField( str_repeat( 'a', 100 ), 'isCityName', '/[!<>;?=+@#"°{}_$%]/u', 64 ) ) );

// 1.4.3: the name is split at the last space counted in characters. Before, the byte position of
// the space was used as a character position, so letters such as "ë" and "Ł" moved the split.
$awkward = "Zoë O'Brien-Łukasz & Co. 2, Åsa van der Wiśniewska-Kowalczykówna";
list( $first, $last ) = SudemiOrders::splitName( $awkward );
check( 'split at the last space with non-ASCII letters before it', "Zoë O'Brien-Łukasz & Co. 2, Åsa van der" === $first && 'Wiśniewska-Kowalczykówna' === $last );
check( 'the cleaned first name keeps every letter', "Zoë O'Brien-Łukasz & Co. Åsa van der" === SudemiOrders::fitName( $first ) );
check( 'one word: last name "-"', array( 'Madonna', '-' ) === SudemiOrders::splitName( ' Madonna ' ) );
check( 'Hebrew and Chinese names split by characters', array( 'שרה', 'כהן' ) === SudemiOrders::splitName( 'שרה כהן' ) && array( '王', '小明' ) === SudemiOrders::splitName( '王 小明' ) );
check( 'runs of spaces count as one', array( 'Anna Maria', 'Łącka' ) === SudemiOrders::splitName( "Anna  Maria \t Łącka" ) );

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit( $failures ? 1 : 0 );
