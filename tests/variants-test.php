<?php
/**
 * 1.4.4: combinations a buyer orders exactly, and the texts a buyer types. A combination's
 * options are named as the storefront names them (public group name), in the groups' order; a
 * text customization field is asked on every combination, or, with an attribute group of the
 * same name ("Name to print: No / Yes"), only on the combinations that are not the group's first
 * value, and then required; a required file upload is not sellable through sudemi; the texts on
 * an order line come back keyed by customization field.
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

require __DIR__ . '/../classes/SudemiVariants.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

$groups = array(
	1 => array( 'name' => 'Size', 'public_name' => 'Size', 'position' => 0 ),
	2 => array( 'name' => 'Color', 'public_name' => 'Colour', 'position' => 1 ),
	3 => array( 'name' => 'Material', 'public_name' => 'Material', 'position' => 2 ),
	4 => array( 'name' => 'Name to print', 'public_name' => 'Name to print', 'position' => 3, 'first' => 40 ),
);
function row( $g, $a, $value, $group_name ) {
	return array( 'id_attribute_group' => $g, 'id_attribute' => $a, 'attribute_name' => $value, 'group_name' => $group_name, 'id_product_attribute' => 7 );
}
// PrestaShop returns a combination's rows in no particular group order.
$rows   = array( row( 4, 41, 'Yes', 'Name to print' ), row( 2, 23, 'Red', 'Color' ), row( 3, 31, 'Cotton', 'Material' ), row( 1, 14, 'XL', 'Size' ) );
$sorted = SudemiVariants::sorted( $rows, $groups );
$attrs  = SudemiVariants::attributes( $sorted, $groups );
check( 'options in the groups\' order, public names', $attrs === array(
	array( 'name' => 'Size', 'value' => 'XL' ),
	array( 'name' => 'Colour', 'value' => 'Red' ),
	array( 'name' => 'Material', 'value' => 'Cotton' ),
	array( 'name' => 'Name to print', 'value' => 'Yes' ),
) );
check( 'no public name: the group name', SudemiVariants::attributes( array( row( 9, 90, 'Oak', 'Wood' ) ), array() ) === array( array( 'name' => 'Wood', 'value' => 'Oak' ) ) );

$text = array( 'id_customization_field' => 5, 'type' => 1, 'required' => 0, 'name' => 'Name to print' );
$file = array( 'id_customization_field' => 6, 'type' => 0, 'required' => 0, 'name' => 'Photo' );
$p_yes = SudemiVariants::personalisation( array( $text, $file ), $sorted, $groups );
check( 'Yes combination: the text, required, price 0, 255 long; the file is not asked', $p_yes === array(
	array( 'key' => 'c5', 'label' => 'Name to print', 'required' => true, 'maxLength' => 255, 'priceMinor' => 0 ),
) );
$no     = SudemiVariants::sorted( array( row( 1, 14, 'XL', 'Size' ), row( 4, 40, 'No', 'Name to print' ) ), $groups );
check( 'No combination (the group\'s first value): no text asked', SudemiVariants::personalisation( array( $text ), $no, $groups ) === array() );
$plain = SudemiVariants::sorted( array( row( 1, 14, 'XL', 'Size' ) ), $groups );
check( 'no group of that name: asked as the shop set it (optional)', SudemiVariants::personalisation( array( $text ), $plain, $groups )[0]['required'] === false );
check( 'product without combinations: asked', count( SudemiVariants::personalisation( array( $text ), array(), array() ) ) === 1 );
check( 'case and spaces of the group name do not matter', count( SudemiVariants::personalisation( array( array( 'id_customization_field' => 5, 'type' => 1, 'required' => 0, 'name' => ' name TO print ' ) ), $no, $groups ) ) === 0 );

check( 'a required file upload is not sellable', false === SudemiVariants::sellable( array( $text, array( 'type' => 0, 'required' => 1 ) ) ) );
check( 'an optional file upload is', true === SudemiVariants::sellable( array( $text, $file ) ) );

$typed = SudemiVariants::typed( array( 'personalisation' => array(
	array( 'key' => 'c5', 'label' => 'Name to print', 'value' => "AJAX\tAMSTERDAM" ),
	array( 'key' => 'c6', 'value' => '  ' ),
	array( 'key' => '../x', 'value' => 'no' ),
	array( 'key' => 'c7', 'value' => str_repeat( 'x', 300 ) ),
) ) );
check( 'typed: keyed by field, control characters become spaces, empty and foreign keys left out, cut at 255',
	array_keys( $typed ) === array( 5, 7 ) && 'AJAX AMSTERDAM' === $typed[5] && 255 === strlen( $typed[7] ) );
check( 'nothing missing', null === SudemiVariants::missing( $p_yes, $typed ) );
check( 'a required text missing is named', 'Name to print' === SudemiVariants::missing( $p_yes, array() ) );
check( 'no personalisation on the line', array() === SudemiVariants::typed( array() ) );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
