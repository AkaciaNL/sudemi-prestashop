<?php
/**
 * 1.4.0: the pure parts of SudemiInsights (statistics, products on sudemi, shop profile).
 *
 * - The Dashboard periods (7, 30, 90 days up to today, inclusive; anything else is 30).
 * - The Shop profile checks done before anything is sent to sudemi.
 * - Why a listing is not shown: known reasons kept, unknown ones become "other".
 * - The chart: one bar per day, labels escaped, mirrored for right-to-left languages.
 * - Every text of the new pages in all twelve languages, no long dash anywhere.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );
require __DIR__ . '/../classes/SudemiInsights.php';
require __DIR__ . '/../classes/SudemiText.php';

$failures = 0;

function check( string $what, bool $passed, $got = null ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' got ' . var_export( $got, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

// Periods.
check( 'last 30 days up to today, inclusive', [ '2026-10-15', '2026-11-13' ] === SudemiInsights::period( 30, '2026-11-13' ), SudemiInsights::period( 30, '2026-11-13' ) );
check( 'last 7 days', [ '2026-11-07', '2026-11-13' ] === SudemiInsights::period( 7, '2026-11-13' ) );
check( 'last 90 days across a year', [ '2026-10-04', '2027-01-01' ] === SudemiInsights::period( 90, '2027-01-01' ), SudemiInsights::period( 90, '2027-01-01' ) );
check( 'an unknown period is 30 days', [ '2026-10-15', '2026-11-13' ] === SudemiInsights::period( 5000, '2026-11-13' ) );

// Profile checks.
$good = [ 'description' => 'Tea and pots.', 'support_email' => 'shop@example.com', 'returns_window_days' => '30' ];
check( 'a valid profile passes', '' === SudemiInsights::profileProblem( $good ) );
check( 'description of 2000 characters passes', '' === SudemiInsights::profileProblem( [ 'description' => str_repeat( 'é', 2000 ) ] + $good ) );
check( 'description of 2001 characters is refused', 'profile_bad_description' === SudemiInsights::profileProblem( [ 'description' => str_repeat( 'a', 2001 ) ] + $good ) );
check( 'an empty e-mail is refused', 'profile_bad_email' === SudemiInsights::profileProblem( [ 'support_email' => '' ] + $good ) );
check( 'a broken e-mail is refused', 'profile_bad_email' === SudemiInsights::profileProblem( [ 'support_email' => 'shop@' ] + $good ) );
check( '13 days is refused (EU minimum 14)', 'profile_bad_days' === SudemiInsights::profileProblem( [ 'returns_window_days' => '13' ] + $good ) );
check( '366 days is refused', 'profile_bad_days' === SudemiInsights::profileProblem( [ 'returns_window_days' => '366' ] + $good ) );
check( 'a non-number is refused', 'profile_bad_days' === SudemiInsights::profileProblem( [ 'returns_window_days' => '30 days' ] + $good ) );
check( '14 and 365 pass', '' === SudemiInsights::profileProblem( [ 'returns_window_days' => '14' ] + $good ) && '' === SudemiInsights::profileProblem( [ 'returns_window_days' => '365' ] + $good ) );

// Stored description (Configuration strips HTML, so it is kept encoded).
check( 'stored description round trip keeps < and &', 'a <b>c</b> & d' === SudemiInsights::storedDescription( 'b64:' . base64_encode( 'a <b>c</b> & d' ) ) );
check( 'a value stored before 1.4.0 is read as it is', 'Plain text' === SudemiInsights::storedDescription( 'Plain text' ) );
check( 'a broken encoded value is empty', '' === SudemiInsights::storedDescription( 'b64:%%%' ) );

// Reasons.
check( 'known reasons kept in order', [ 'out_of_stock', 'no_shipping' ] === SudemiInsights::reasons( [ 'listed' => false, 'not_listed_reasons' => [ 'out_of_stock', 'no_shipping', 'out_of_stock' ] ] ) );
check( 'an unknown reason is "other"', [ 'disabled_by_shop', 'other' ] === SudemiInsights::reasons( [ 'listed' => false, 'not_listed_reasons' => [ 'disabled_by_shop', 'something_new' ] ] ) );
check( 'not listed without a reason is "other"', [ 'other' ] === SudemiInsights::reasons( [ 'listed' => false ] ) );
check( 'listed: no reasons', [] === SudemiInsights::reasons( [ 'listed' => true, 'not_listed_reasons' => [] ] ) );
foreach ( SudemiInsights::REASONS as $reason ) {
	check( "reason $reason has a text", isset( SudemiText::EN[ 'rs_' . $reason ] ) );
}
check( 'the "other" reason has a text', isset( SudemiText::EN['rs_other'] ) );

// Numbers.
check( 'conversion 643 bps is 6.4 %', '6.4 %' === SudemiInsights::conversion( 643 ) );
check( 'conversion 0', '0.0 %' === SudemiInsights::conversion( 0 ) );
check( 'count groups thousands', '12 345' === SudemiInsights::count( 12345 ) );
check( 'axis top: small numbers', 4 === SudemiInsights::niceMax( 0 ) && 4 === SudemiInsights::niceMax( 3 ) );
check( 'axis top: 17 -> 20', 20 === SudemiInsights::niceMax( 17 ), SudemiInsights::niceMax( 17 ) );
check( 'axis top: 230 -> 500', 500 === SudemiInsights::niceMax( 230 ), SudemiInsights::niceMax( 230 ) );
check( 'axis top: 1000 stays', 1000 === SudemiInsights::niceMax( 1000 ) );

// Chart.
$daily = [
	[ 'day' => '2026-11-11', 'impressions' => 10, 'detail_views' => 2 ],
	[ 'day' => '2026-11-12', 'impressions' => 0, 'detail_views' => 0 ],
	[ 'day' => '2026-11-13', 'impressions' => 25, 'detail_views' => 5 ],
];
$svg = SudemiInsights::chart( $daily, 'impressions', 'detail_views', 'Shown <b>', 'Views & co', false );
check( 'chart: one bar per day', 3 === substr_count( $svg, '<rect ' ), substr_count( $svg, '<rect ' ) );
check( 'chart: one line', 1 === substr_count( $svg, '<polyline ' ) );
check( 'chart: labels escaped', false === strpos( $svg, '<b>' ) && false !== strpos( $svg, 'Shown &lt;b&gt;' ) && false !== strpos( $svg, 'Views &amp; co' ) );
check( 'chart: first and last day under the axis', false !== strpos( $svg, '>2026-11-11</text>' ) && false !== strpos( $svg, '>2026-11-13</text>' ) );
check( 'chart: a zero day has a zero-height bar', 1 === preg_match( '/height="0"/', $svg ) );
$rtl = SudemiInsights::chart( $daily, 'impressions', 'detail_views', 'a', 'b', true );
preg_match_all( '/<rect x="([0-9.]+)"/', $svg, $ltrX );
preg_match_all( '/<rect x="([0-9.]+)"/', $rtl, $rtlX );
check( 'chart: right-to-left draws the first day on the right', (float) $ltrX[1][0] < (float) $ltrX[1][2] && (float) $rtlX[1][0] > (float) $rtlX[1][2] );
check( 'chart: no days still draws', false !== strpos( SudemiInsights::chart( [], 'a', 'b', 'x', 'y' ), '</svg>' ) );

// Checkout mode banner (1.4.1).
$url = 'https://sudemi.com/seller/payouts';
check( 'mode B with reason and link', [ 'mode' => 'SHOP', 'reason' => 'stripe_requirements_due', 'url' => $url ] === SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SHOP', 'checkout_mode_reason' => 'stripe_requirements_due', 'stripe_connect_url' => $url ] ) );
check( 'mode A, reason null', [ 'mode' => 'SUDEMI', 'reason' => '', 'url' => $url ] === SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SUDEMI', 'checkout_mode_reason' => null, 'stripe_connect_url' => $url ] ) );
check( 'suspended', 'SUSPENDED' === SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SUSPENDED', 'checkout_mode_reason' => 'stripe_restricted' ] )['mode'] );
check( 'an older sudemi (no checkout_mode): no banner', null === SudemiInsights::checkoutModel( [ 'shop_name' => 'x' ] ) );
check( 'an unknown mode: no banner', null === SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SOMETHING' ] ) );
check( 'not an object: no banner', null === SudemiInsights::checkoutModel( 'SHOP' ) && null === SudemiInsights::checkoutModel( [ 'checkout_mode' => [ 'SHOP' ] ] ) );
check( 'an unknown reason is dropped', '' === SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SHOP', 'checkout_mode_reason' => 'new_reason' ] )['reason'] );
check( 'a javascript: link is dropped', '' === SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SHOP', 'stripe_connect_url' => 'javascript:alert(1)' ] )['url'] );
check( 'a link without scheme is dropped', '' === SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SHOP', 'stripe_connect_url' => '//evil.example/x' ] )['url'] );
check( 'no link: empty', '' === SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SHOP', 'stripe_connect_url' => null ] )['url'] );
$model = SudemiInsights::checkoutModel( [ 'checkout_mode' => 'SHOP', 'checkout_mode_reason' => 'stripe_not_connected', 'stripe_connect_url' => $url ] );
$entry = SudemiInsights::checkoutEntry( $model, 1000 );
check( 'cache: fresh entry gives the model', $model === SudemiInsights::cachedCheckout( $entry, 1059 ) );
check( 'cache: after 60 seconds it is asked again', false === SudemiInsights::cachedCheckout( $entry, 1060 ) );
check( 'cache: a failed read is kept as "none"', null === SudemiInsights::cachedCheckout( SudemiInsights::checkoutEntry( null, 1000 ), 1010 ) );
check( 'cache: nothing stored', false === SudemiInsights::cachedCheckout( '', 1000 ) && false === SudemiInsights::cachedCheckout( 'garbage', 1000 ) );
check( 'cache: a time in the future is not trusted', false === SudemiInsights::cachedCheckout( $entry, 900 ) );
check( 'cache: a tampered mode gives no banner', null === SudemiInsights::cachedCheckout( '{"at":1000,"model":{"mode":"X"}}', 1001 ) );

// Texts in every language.
$keys = array_filter( array_keys( SudemiText::EN ), function ( $k ) {
	return 0 === strpos( $k, 'nav_' ) || 0 === strpos( $k, 'mode_' ) || 0 === strpos( $k, 'kpi_' ) || 0 === strpos( $k, 'profile_' ) || 0 === strpos( $k, 'rs_' ) || 0 === strpos( $k, 'prod_' );
} );
foreach ( [ 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ] as $lang ) {
	$missing = [];
	foreach ( $keys as $k ) {
		$en = SudemiText::EN[ $k ];
		$tr = SudemiText::translate( $en, $lang );
		if ( $tr === $en && ! in_array( $en, [ 'Dashboard', 'Support', 'Description' ], true ) ) {
			$missing[] = $k;
		}
		if ( substr_count( $en, '%' ) !== substr_count( $tr, '%' ) ) {
			$missing[] = $k . ' (placeholders)';
		}
	}
	check( "1.4.0 and 1.4.1 texts in $lang", [] === $missing, $missing );
}
$all = '';
foreach ( glob( __DIR__ . '/../translations/*.php' ) as $f ) {
	$all .= file_get_contents( $f );
}
foreach ( glob( __DIR__ . '/../views/templates/admin/*.tpl' ) as $f ) {
	$all .= file_get_contents( $f );
}
check( 'no long dash in any translation or template', false === strpos( $all, "\u{2014}" ) );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall insights checks passed\n";
