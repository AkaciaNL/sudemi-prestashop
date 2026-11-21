<?php
/**
 * The shop's own shipping rules (1.4.6, POST /integration/v1/shipping/rules): weight units, bands
 * from PrestaShop's half-open ranges (never cheaper than PrestaShop), out-of-range behaviour, gaps,
 * handling fee and tax in every band, free shipping from an amount, the carrier picked per
 * country, products restricted to other carriers, carrier modules, the request body and its
 * issues, and the per-unit charge in a product's shipping entries.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

require __DIR__ . '/../classes/SudemiCurrency.php';
require __DIR__ . '/../classes/SudemiShipping.php';
require __DIR__ . '/../classes/SudemiShippingRules.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

/** The band a parcel of $x pays (as sudemi picks it), or null when not delivered. */
function band_for( array $rule, int $x ) {
	foreach ( $rule['bands'] as $band ) {
		if ( null === $band['upTo'] || $x <= $band['upTo'] ) {
			return $band['priceMinor'];
		}
	}
	return 'highest' === $rule['aboveLast'] ? max( array_column( $rule['bands'], 'priceMinor' ) ) : null;
}

/** What PrestaShop charges for $x (ranges in the same whole units), or null when the carrier is off. */
function ps_price( array $ranges, int $behavior, float $x ) {
	usort( $ranges, function ( $a, $b ) { return $a[0] <=> $b[0]; } );
	foreach ( $ranges as $r ) {
		if ( $x >= $r[0] && $x < $r[1] ) {
			return $r[2];
		}
	}
	if ( 1 === $behavior ) {
		return null;
	}
	$last = null;
	foreach ( $ranges as $r ) {
		if ( null === $last || $r[1] > $last[1] ) {
			$last = $r;
		}
	}
	return $last[2];
}

// Weight units.
check( 'kg is 1000 g', 1000.0 === SudemiShippingRules::gramsPerUnit( 'kg' ) );
check( 'KG, " Kg." and kgs are kilograms', 1000.0 === SudemiShippingRules::gramsPerUnit( 'KG' ) && 1000.0 === SudemiShippingRules::gramsPerUnit( ' Kg.' ) && 1000.0 === SudemiShippingRules::gramsPerUnit( 'kgs' ) );
check( 'g and gr are grams', 1.0 === SudemiShippingRules::gramsPerUnit( 'g' ) && 1.0 === SudemiShippingRules::gramsPerUnit( 'gr' ) );
check( 'lb and lbs are pounds', abs( SudemiShippingRules::gramsPerUnit( 'lb' ) - 453.59237 ) < 1e-9 && abs( SudemiShippingRules::gramsPerUnit( 'LBS' ) - 453.59237 ) < 1e-9 );
check( 'oz is an ounce', abs( SudemiShippingRules::gramsPerUnit( 'oz' ) - 28.349523125 ) < 1e-9 );
check( 'an unknown unit is unknown', null === SudemiShippingRules::gramsPerUnit( 'stone' ) && null === SudemiShippingRules::gramsPerUnit( '' ) );
check( '1.5 kg is 1500 g', 1500 === SudemiShippingRules::toGrams( 1.5, 'kg' ) );
check( '0.3 kg is 300 g (no float noise)', 300 === SudemiShippingRules::toGrams( 0.3, 'kg' ) );
check( '0.3004 kg rounds up to 301 g (never lighter)', 301 === SudemiShippingRules::toGrams( 0.3004, 'kg' ) );
check( '250 g is 250 g', 250 === SudemiShippingRules::toGrams( 250, 'g' ) );
check( '2 lb is 908 g (907.18 rounded up)', 908 === SudemiShippingRules::toGrams( 2, 'lb' ) );
check( 'unknown unit counts as kg, as before', 2000 === SudemiShippingRules::toGrams( 2, '' ) );
check( 'no weight is 0', 0 === SudemiShippingRules::toGrams( 0, 'kg' ) && 0 === SudemiShippingRules::toGrams( -1, 'kg' ) );

// Bands: half-open PrestaShop ranges [d1, d2) become inclusive whole-unit bands.
$b = SudemiShippingRules::bands( array( array( 0, 2, 4.95 ), array( 2, 10, 8.95 ) ), 1000.0, 0 );
check( 'highest range: last band merges into one without limit', array(
	array( 'upTo' => 1999, 'price' => 4.95 ),
	array( 'upTo' => null, 'price' => 8.95 ),
) === $b['bands'] && 'highest' === $b['aboveLast'] && false === $b['gaps'] );
$b = SudemiShippingRules::bands( array( array( 0, 2, 4.95 ), array( 2, 10, 8.95 ) ), 1000.0, 1 );
check( 'disabled out of range: aboveLast none, last band ends at 9999', array(
	array( 'upTo' => 1999, 'price' => 4.95 ),
	array( 'upTo' => 9999, 'price' => 8.95 ),
) === $b['bands'] && 'none' === $b['aboveLast'] );
$b = SudemiShippingRules::bands( array( array( 0, 0.3, 3.0 ), array( 0.3, 1, 5.0 ) ), 1000.0, 1 );
check( '0.3 kg boundary: 299 g in the first band, 300 g in the next', 299 === $b['bands'][0]['upTo'] && 999 === $b['bands'][1]['upTo'] );
$b = SudemiShippingRules::bands( array( array( 2, 10, 8.95 ), array( 0, 2, 4.95 ) ), 1000.0, 1 );
check( 'ranges in any order are sorted', 1999 === $b['bands'][0]['upTo'] );
check( 'no ranges: no bands', null === SudemiShippingRules::bands( array(), 1000.0, 0 ) );
check( 'an empty range is left out', null === SudemiShippingRules::bands( array( array( 5, 5, 1.0 ) ), 1000.0, 0 ) );

// Gaps and ranges not starting at 0.
$ranges = array( array( 1, 2, 5.0 ), array( 3, 5, 7.0 ), array( 5, 8, 6.0 ) );
$b = SudemiShippingRules::bands( $ranges, 1000.0, 0 );
check( 'highest range: below the first range and in a gap PrestaShop charges the range with the highest upper limit', array(
	array( 'upTo' => 999, 'price' => 6.0 ),
	array( 'upTo' => 1999, 'price' => 5.0 ),
	array( 'upTo' => 2999, 'price' => 6.0 ),
	array( 'upTo' => 4999, 'price' => 7.0 ),
	array( 'upTo' => null, 'price' => 6.0 ),
) === $b['bands'] && true === $b['gaps'] );
$b = SudemiShippingRules::bands( $ranges, 1000.0, 1 );
check( 'disabled: gaps charged the highest range price (the carrier does not deliver them), equal neighbours merged', array(
	array( 'upTo' => 999, 'price' => 7.0 ),
	array( 'upTo' => 1999, 'price' => 5.0 ),
	array( 'upTo' => 4999, 'price' => 7.0 ),
	array( 'upTo' => 7999, 'price' => 6.0 ),
) === $b['bands'] && 'none' === $b['aboveLast'] );
// Overlap: PrestaShop takes the range with the lowest delimiter1.
$b = SudemiShippingRules::bands( array( array( 0, 5, 4.0 ), array( 2, 10, 9.0 ), array( 3, 4, 1.0 ) ), 1.0, 1 );
check( 'overlapping ranges: the lower start wins, a covered range is dropped', array(
	array( 'upTo' => 4, 'price' => 4.0 ),
	array( 'upTo' => 9, 'price' => 9.0 ),
) === $b['bands'] );

// Exhaustive: sudemi never charges less than PrestaShop, and charges exactly PrestaShop's price
// wherever the carrier delivers (whole units).
$sets = array(
	array( array( 0, 1000, 500 ), array( 1000, 5000, 900 ), array( 5000, 20000, 1500 ) ),
	array( array( 500, 1000, 500 ), array( 2000, 3000, 300 ), array( 3000, 4000, 900 ) ),
	array( array( 0, 2500, 100 ), array( 2500, 2600, 50 ), array( 4000, 6000, 700 ) ),
	array( array( 0, 1500, 800 ), array( 1000, 3000, 200 ) ),
);
foreach ( $sets as $i => $set ) {
	foreach ( array( 0, 1 ) as $behavior ) {
		$built = SudemiShippingRules::bands( $set, 1.0, $behavior );
		$rule  = array( 'aboveLast' => $built['aboveLast'], 'bands' => array_map( function ( $band ) {
			return array( 'upTo' => $band['upTo'], 'priceMinor' => $band['price'] );
		}, $built['bands'] ) );
		$under = 0;
		$wrong = 0;
		for ( $x = 0; $x <= 25000; $x++ ) {
			$ps     = ps_price( $set, $behavior, (float) $x );
			$sudemi = band_for( $rule, $x );
			if ( null === $ps ) {
				continue; // PrestaShop does not deliver it with this carrier.
			}
			if ( null === $sudemi || $sudemi < $ps ) {
				$under++;
			}
			if ( null === $sudemi || (float) $sudemi !== (float) $ps ) {
				$wrong++;
			}
		}
		check( "set $i, behaviour $behavior: never below PrestaShop's price", 0 === $under );
		check( "set $i, behaviour $behavior: exactly PrestaShop's price wherever it delivers", 0 === $wrong );
	}
}

// Prices: handling fee and tax in every band, in minor units.
check( '4.95 + 1.00 handling at 21% is 720', 720 === SudemiShippingRules::priceMinor( 4.95, 1.0, 21, 'EUR' ) );
check( 'no handling, no tax: 495', 495 === SudemiShippingRules::priceMinor( 4.95, 0, 0, 'EUR' ) );
check( 'zero-decimal currency: 500 JPY', 500 === SudemiShippingRules::priceMinor( 500, 0, 0, 'JPY' ) );
check( 'free over 50 EUR is 5000', 5000 === SudemiShippingRules::freeOverMinor( '50', 'EUR' ) );
check( 'free over 49.995 is 5000 (rounded up, never free earlier)', 5000 === SudemiShippingRules::freeOverMinor( 49.995, 'EUR' ) );
check( 'free over 0 or unset: none', null === SudemiShippingRules::freeOverMinor( '0', 'EUR' ) && null === SudemiShippingRules::freeOverMinor( false, 'EUR' ) );

// One carrier's rule.
$weightCarrier = array( 'name' => 'PostNL', 'reference' => 2, 'free' => false, 'basis' => 'weight', 'behavior' => 1, 'handling' => 1.0, 'taxRate' => 21.0,
	'ranges' => array( array( 0, 2, 4.0 ), array( 2, 10, 8.0 ) ) );
$r = SudemiShippingRules::carrierRule( $weightCarrier, 'kg', 'EUR' );
check( 'weight carrier: bands in grams with handling and tax', array( 'bandBasis' => 'weight', 'bands' => array(
	array( 'upTo' => 1999, 'priceMinor' => 605 ),
	array( 'upTo' => 9999, 'priceMinor' => 1089 ),
), 'aboveLast' => 'none' ) === $r['rule'] && 605 === $r['first'] && 1089 === $r['highest'] && array() === $r['approximated'] );
$r = SudemiShippingRules::carrierRule( $weightCarrier, 'lb', 'EUR' );
check( 'weight carrier in pounds: 2 lb ends at 907 g', 907 === $r['rule']['bands'][0]['upTo'] && 4535 === $r['rule']['bands'][1]['upTo'] );
$r = SudemiShippingRules::carrierRule( $weightCarrier, 'stone', 'EUR' );
check( 'unknown weight unit: one price, the highest, reported', array( 'baseMinor' => 1089 ) === $r['rule'] && 1 === count( $r['approximated'] ) );
$priceCarrier = array( 'name' => 'DHL', 'reference' => 3, 'free' => false, 'basis' => 'price', 'behavior' => 0, 'handling' => 0.0, 'taxRate' => 0.0,
	'ranges' => array( array( 0, 50, 6.95 ), array( 50, 100000, 0 ) ) );
$r = SudemiShippingRules::carrierRule( $priceCarrier, 'kg', 'EUR' );
check( 'price carrier: bands in minor units of the goods, free from 50.00', array( 'bandBasis' => 'price', 'bands' => array(
	array( 'upTo' => 4999, 'priceMinor' => 695 ),
	array( 'upTo' => null, 'priceMinor' => 0 ),
), 'aboveLast' => 'highest' ) === $r['rule'] );
$flat = array( 'name' => 'Flat', 'reference' => 4, 'free' => false, 'basis' => 'weight', 'behavior' => 0, 'handling' => 0.0, 'taxRate' => 21.0,
	'ranges' => array( array( 0, 1000, 5.0 ) ) );
check( 'one range, highest range out of it: one price (baseMinor)', array( 'baseMinor' => 605 ) === SudemiShippingRules::carrierRule( $flat, 'kg', 'EUR' )['rule'] );
check( 'free carrier: baseMinor 0', array( 'baseMinor' => 0 ) === SudemiShippingRules::carrierRule( array( 'name' => 'Free', 'reference' => 5, 'free' => true ), 'kg', 'EUR' )['rule'] );
check( 'no range for the zone: no rule', null === SudemiShippingRules::carrierRule( array( 'name' => 'X', 'reference' => 6, 'free' => false, 'basis' => 'weight', 'behavior' => 0, 'handling' => 0.0, 'taxRate' => 0.0, 'ranges' => array() ), 'kg', 'EUR' ) );
$gappy = $weightCarrier;
$gappy['ranges'] = array( array( 0.5, 2, 4.0 ), array( 2, 10, 8.0 ) );
check( 'disabled carrier with a gap: reported as approximated', 1 === count( SudemiShippingRules::carrierRule( $gappy, 'kg', 'EUR' )['approximated'] ) );

// The rule for a country.
$c = SudemiShippingRules::countryRule( 'NL', array( $weightCarrier, $priceCarrier ), 'kg', 'EUR', 5000 );
check( 'country: the carrier cheapest for one item (PostNL 6.05 < DHL 6.95)', 'PostNL' === $c['carrier'] && 'weight' === $c['rule']['bandBasis'] && 'NL' === $c['rule']['country'] );
check( 'country: free over the shop amount', 5000 === $c['rule']['freeOverMinor'] );
$c = SudemiShippingRules::countryRule( 'DE', array( $priceCarrier, array( 'name' => 'Free', 'reference' => 5, 'free' => true ) ), 'kg', 'EUR', 5000 );
check( 'a free carrier wins and needs no free-over amount', array( 'country' => 'DE', 'baseMinor' => 0 ) === $c['rule'] );
$module = array( 'name' => 'LiveRates', 'reference' => 9, 'external' => true );
$c = SudemiShippingRules::countryRule( 'FR', array( $module ), 'kg', 'EUR', null );
check( 'only a carrier module: no rule, reported as calculated', null === $c['rule'] && true === $c['calculated'] && 'LiveRates' === $c['carrier'] );
$c = SudemiShippingRules::countryRule( 'FR', array( $module, $priceCarrier ), 'kg', 'EUR', null );
check( 'a carrier module next to a normal carrier: the normal one, nothing reported', false === $c['calculated'] && 'DHL' === $c['carrier'] && array() === $c['approximated'] );
check( 'no carrier: no rule at all', null === SudemiShippingRules::countryRule( 'IS', array(), 'kg', 'EUR', null ) );
$c = SudemiShippingRules::countryRule( 'BE', array( $weightCarrier, $priceCarrier ), 'kg', 'EUR', 5000, array( array( 3 ) ) );
check( 'a product only DHL may ship: the highest price of any carrier there, reported', array( 'country' => 'BE', 'baseMinor' => 1089, 'freeOverMinor' => 5000 ) === $c['rule'] && 1 === count( $c['approximated'] ) && 'PostNL, DHL' === $c['carrier'] );
$c = SudemiShippingRules::countryRule( 'BE', array( $weightCarrier, $priceCarrier ), 'kg', 'EUR', null, array( array( 2, 3 ), array( 77 ) ) );
check( 'restrictions that include the picked carrier, or carriers not delivering here: exact rule kept', 'weight' === $c['rule']['bandBasis'] && array() === $c['approximated'] );

// The request body.
$results = array(
	'NL' => SudemiShippingRules::countryRule( 'NL', array( $weightCarrier ), 'kg', 'EUR', 5000 ),
	'BE' => SudemiShippingRules::countryRule( 'BE', array( $gappy ), 'kg', 'EUR', null ),
	'AT' => SudemiShippingRules::countryRule( 'AT', array( $gappy ), 'kg', 'EUR', null ),
	'FR' => SudemiShippingRules::countryRule( 'FR', array( $module ), 'kg', 'EUR', null ),
	'IS' => null,
);
$p = SudemiShippingRules::payload( 'EUR', $results );
check( 'payload: currency and rules sorted by country, no rule for FR or IS', 'EUR' === $p['currency'] && array( 'AT', 'BE', 'NL' ) === array_column( $p['rules'], 'country' ) );
check( 'payload: two shop-wide issues with the exact codes', array( 'shipping_rule_approximated', 'shipping_rate_calculated' ) === array_column( $p['issues'], 'code' ) );
check( 'issues: no externalId, countries sorted, carrier named', ! isset( $p['issues'][0]['externalId'] ) && array( 'AT', 'BE' ) === $p['issues'][0]['details']['countries'] && 'PostNL' === $p['issues'][0]['details']['carrier'] && array( 'FR' ) === $p['issues'][1]['details']['countries'] && 'LiveRates' === $p['issues'][1]['details']['carrier'] );
check( 'issues: warnings with an English message', 'warning' === $p['issues'][0]['severity'] && false !== strpos( $p['issues'][0]['message'], 'highest range' ) );
$p = SudemiShippingRules::payload( 'EUR', array( 'NL' => $results['NL'] ), array( 'a module overrides the free shipping amount' ) );
check( 'shop-wide reason: every country with a rule is reported', array( 'NL' ) === $p['issues'][0]['details']['countries'] );
$p = SudemiShippingRules::payload( 'PLN', array() );
check( 'nothing to say: an empty rule list (replaces what was sent) and no issues', array( 'currency' => 'PLN', 'rules' => array(), 'issues' => array() ) === $p );
$json = json_encode( SudemiShippingRules::payload( 'EUR', $results ) );
check( 'payload is JSON with upTo null', false !== $json && false !== strpos( $json, '"upTo":9999' ) );
check( 'no long dash anywhere in the payload', false === strpos( (string) $json, "\u{2014}" ) );

// A product's shipping entry: one item, and the per-unit part.
check( 'entry without a per-unit charge has no perUnitMinor', array( 'country' => 'DE', 'costMinor' => 795 ) === SudemiShipping::entry( 'DE', 7.95, 0.0, 'EUR' ) );
check( 'entry with a per-unit charge (with tax)', array( 'country' => 'DE', 'costMinor' => 795, 'perUnitMinor' => 242 ) === SudemiShipping::entry( 'DE', 7.95, 2.42, 'EUR' ) );
check( 'perUnitMinor is never more than costMinor', 100 === SudemiShipping::entry( 'DE', 1.0, 1.004, 'EUR' )['perUnitMinor'] );

// No long dash in the new code.
foreach ( array( 'classes/SudemiShippingRules.php', 'classes/SudemiShipping.php', 'upgrade/upgrade-1.4.6.php', 'CHANGELOG.md' ) as $file ) {
	check( "no long dash in $file", false === strpos( (string) file_get_contents( __DIR__ . '/../' . $file ), "\u{2014}" ) );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
