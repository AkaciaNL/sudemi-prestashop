<?php

/**
 * Combinations a buyer orders exactly, and the texts a buyer types (customization fields).
 *
 * A combination is one listing (size, colour, material...). Its options are named the way the
 * shop's storefront names them (the attribute group's public name and the value), in the order
 * of the groups, so "T-shirt - XL, Red, Cotton" reads the same on every sync.
 *
 * A text customization field ("Name to print") is what sudemi asks the buyer to type. PrestaShop
 * has no price for a customization, so a shop that charges for it adds an attribute group with
 * the same name as the field ("Name to print: No / Yes", the Yes value with a price impact). Then
 * the first value of that group (No) asks for no text, and every other value (Yes) asks for it,
 * required. Without such a group the field is asked on every combination as the shop set it
 * (required or not). A required file upload cannot be asked through sudemi, so such a product is
 * not listed.
 *
 * Pure, so it can be tested without PrestaShop.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiVariants
{
    /** PrestaShop's customization field types. */
    public const FIELD_FILE = 0;
    public const FIELD_TEXT = 1;

    /** PrestaShop keeps a customization text in a 255-character column. */
    public const TEXT_MAX = 255;

    /** sudemi takes texts of at most 200 characters, and at most 5 per listing. */
    public const SUDEMI_MAX = 200;
    public const MAX_FIELDS = 5;

    /**
     * One combination's attribute rows, in the order of their groups' positions.
     *
     * @param array $rows rows of Product::getAttributeCombinations() for one combination
     * @param array $groups id_attribute_group => ['position' => int, 'public_name' => string]
     */
    public static function sorted(array $rows, array $groups)
    {
        usort($rows, function ($a, $b) use ($groups) {
            $pa = isset($groups[(int) $a['id_attribute_group']]['position']) ? (int) $groups[(int) $a['id_attribute_group']]['position'] : 0;
            $pb = isset($groups[(int) $b['id_attribute_group']]['position']) ? (int) $groups[(int) $b['id_attribute_group']]['position'] : 0;

            return $pa === $pb ? (int) $a['id_attribute_group'] - (int) $b['id_attribute_group'] : $pa - $pb;
        });

        return $rows;
    }

    /**
     * [{name, value}] of one combination: the group's public name (as the storefront shows it),
     * else its name, and the value.
     */
    public static function attributes(array $sortedRows, array $groups)
    {
        $out = [];
        foreach ($sortedRows as $row) {
            $g = (int) $row['id_attribute_group'];
            $name = isset($groups[$g]['public_name']) && (string) $groups[$g]['public_name'] !== ''
                ? (string) $groups[$g]['public_name'] : (string) $row['group_name'];
            $out[] = ['name' => $name, 'value' => (string) $row['attribute_name']];
        }

        return $out;
    }

    /**
     * Whether a product's customization fields allow it to be sold through sudemi: not when a
     * required one is a file upload.
     *
     * @param array $fields rows of Product::getCustomizationFields() (type, required)
     */
    public static function sellable(array $fields)
    {
        foreach ($fields as $field) {
            if ((int) $field['type'] === self::FIELD_FILE && !empty($field['required'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * What sudemi asks the buyer to type for one listing: [{key, label, required, maxLength,
     * priceMinor}], or null when the listing needs more required texts than sudemi can ask.
     *
     * @param array $fields rows of Product::getCustomizationFields() (id_customization_field, type, required, name)
     * @param array $sortedRows the combination's attribute rows (empty for a product without combinations)
     * @param array $groups id_attribute_group => ['name' => ..., 'public_name' => ..., 'first' => id of its first value on this product]
     */
    public static function personalisation(array $fields, array $sortedRows, array $groups)
    {
        $out = [];
        foreach ($fields as $field) {
            if ((int) $field['type'] !== self::FIELD_TEXT) {
                continue;
            }
            $label = trim((string) $field['name']);
            $required = !empty($field['required']);
            // A "Name to print: No / Yes" group of the same name decides per combination.
            foreach ($sortedRows as $row) {
                $g = (int) $row['id_attribute_group'];
                $names = [
                    self::norm(isset($groups[$g]['name']) ? $groups[$g]['name'] : $row['group_name']),
                    self::norm(isset($groups[$g]['public_name']) ? $groups[$g]['public_name'] : ''),
                ];
                if ($label !== '' && in_array(self::norm($label), $names, true)) {
                    if (isset($groups[$g]['first']) && (int) $groups[$g]['first'] === (int) $row['id_attribute']) {
                        continue 2;
                    }
                    $required = true;
                    break;
                }
            }
            $out[] = [
                'key' => 'c' . (int) $field['id_customization_field'],
                'label' => $label !== '' ? $label : 'Text',
                'required' => $required,
                'maxLength' => self::SUDEMI_MAX,
                'priceMinor' => 0,
            ];
        }

        // sudemi asks at most five. Required texts first; more than five required ones cannot be
        // asked at all, so the item cannot be sold through sudemi (null). An optional sixth is
        // simply not asked.
        $required = array_values(array_filter($out, function ($f) {
            return $f['required'];
        }));
        if (count($required) > self::MAX_FIELDS) {
            return null;
        }
        $optional = array_values(array_filter($out, function ($f) {
            return !$f['required'];
        }));

        return array_slice(array_merge($required, $optional), 0, self::MAX_FIELDS);
    }

    /**
     * The texts the buyer typed for an order line, id_customization_field => text, from sudemi's
     * line personalisation [{key, label, value}] (keys "c<id>"). Control characters become
     * spaces, empty texts are left out, a text is cut at 255 characters.
     */
    public static function typed(array $line)
    {
        $out = [];
        if (empty($line['personalisation']) || !is_array($line['personalisation'])) {
            return $out;
        }
        foreach ($line['personalisation'] as $entry) {
            if (!is_array($entry) || !isset($entry['key'], $entry['value']) || !is_scalar($entry['value'])
                || !preg_match('/^c([0-9]{1,10})$/', (string) $entry['key'], $m)) {
                continue;
            }
            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $entry['value']));
            if ($value === '') {
                continue;
            }
            $out[(int) $m[1]] = function_exists('mb_substr') ? mb_substr($value, 0, self::TEXT_MAX) : substr($value, 0, self::TEXT_MAX);
        }

        return $out;
    }

    /** The label of the first required text a line lacks, or null. */
    public static function missing(array $personalisation, array $typed)
    {
        foreach ($personalisation as $field) {
            $id = (int) substr((string) $field['key'], 1);
            if (!empty($field['required']) && !isset($typed[$id])) {
                return (string) $field['label'];
            }
        }

        return null;
    }

    private static function norm($text)
    {
        $text = trim((string) $text);

        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }
}
