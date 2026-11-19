<?php
/**
 * The sudemi demo T-shirt: one product with 100 combinations and a name the buyer types.
 *
 *   Size           S, M, L, XL, XXL                         (same price)
 *   Colour         White +0, Black +2, Red +3, Navy +4, Green +5
 *   Material       Lycra +0, Cotton +10.00
 *   Name to print  No +0, Yes +20.00
 *   Base price     19.95 (no tax rule, so the price is what the buyer pays), so White / Lycra /
 *                  No is 19.95 and Green / Cotton / Yes is 54.95.
 *
 * The name is a text customization field "Name to print"; PrestaShop has no price for a
 * customization, so the +20.00 is the "Name to print: Yes" combinations' price impact, and the
 * sudemi module asks for the text on those combinations only (same name as the field).
 *
 * Idempotent: run it again and it finds the product by its reference and puts every combination
 * back to these prices (stock is set only on combinations it creates).
 *
 * Run inside the PrestaShop container (PS_ROOT defaults to /var/www/html):
 *   php tshirt.php ["<name>"] ["<reference>"] [<stock>]
 * Prints JSON: product id, combination count, and combination ids keyed "S|White|Lycra|No".
 *
 * Not part of the module (tests/ is left out of the release zip).
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$root = getenv('PS_ROOT') ?: '/var/www/html';
require $root . '/config/config.inc.php';

$name = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : 'sudemi demo T-shirt';
$reference = isset($argv[2]) && $argv[2] !== '' ? $argv[2] : 'SUDEMI-DEMO-TSHIRT';
$stock = isset($argv[3]) && is_numeric($argv[3]) ? (int) $argv[3] : 10;

$lang = (int) Configuration::get('PS_LANG_DEFAULT');
$languages = Language::getLanguages(false);
$attributeClass = class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';
$base = 19.95;
$axes = [
    'Size' => ['S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0, 'XXL' => 0],
    'Colour' => ['White' => 0, 'Black' => 2, 'Red' => 3, 'Navy' => 4, 'Green' => 5],
    'Material' => ['Lycra' => 0, 'Cotton' => 10],
    'Name to print' => ['No' => 0, 'Yes' => 20],
];
$colours = ['White' => '#ffffff', 'Black' => '#000000', 'Red' => '#c0392b', 'Navy' => '#1f2a44', 'Green' => '#2e8b57'];

function sudemi_demo_ml($text, $languages)
{
    $out = [];
    foreach ($languages as $l) {
        $out[(int) $l['id_lang']] = $text;
    }

    return $out;
}

// The attribute groups and their values, found by name or created.
$ids = [];
foreach ($axes as $groupName => $values) {
    $gid = (int) Db::getInstance()->getValue('SELECT ag.id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group ag JOIN '
        . _DB_PREFIX_ . 'attribute_group_lang agl ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ' . $lang
        . " WHERE agl.name = '" . pSQL('sudemi ' . $groupName) . "'");
    if (!$gid) {
        $g = new AttributeGroup();
        $g->name = sudemi_demo_ml('sudemi ' . $groupName, $languages);
        $g->public_name = sudemi_demo_ml($groupName, $languages);
        $g->is_color_group = $groupName === 'Colour';
        $g->group_type = $groupName === 'Colour' ? 'color' : 'select';
        $g->add();
        $gid = (int) $g->id;
    }
    $position = 0;
    foreach (array_keys($values) as $value) {
        $aid = (int) Db::getInstance()->getValue('SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a JOIN ' . _DB_PREFIX_
            . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $lang . ' WHERE a.id_attribute_group = '
            . $gid . " AND al.name = '" . pSQL($value) . "'");
        if (!$aid) {
            $a = new $attributeClass();
            $a->id_attribute_group = $gid;
            $a->name = sudemi_demo_ml($value, $languages);
            $a->color = isset($colours[$value]) && $groupName === 'Colour' ? $colours[$value] : '';
            $a->position = $position;
            $a->add();
            $aid = (int) $a->id;
        }
        // "No" first: the module asks for the name on the other value only.
        Db::getInstance()->update('attribute', ['position' => $position], 'id_attribute = ' . $aid);
        $ids[$groupName][$value] = $aid;
        ++$position;
    }
}

// The product, found by its reference or created.
$pid = (int) Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . "product WHERE reference = '" . pSQL($reference) . "'");
$product = new Product($pid ?: null);
$product->name = sudemi_demo_ml($name, $languages);
$product->link_rewrite = sudemi_demo_ml(Tools::str2url($name), $languages);
$product->description_short = sudemi_demo_ml('Sports T-shirt in five sizes and five colours, in Lycra or cotton. With "Name to print: Yes" we print the name you type (for example your football club) on the back.', $languages);
$product->reference = $reference;
$product->price = $base;
$product->id_tax_rules_group = 0;
$product->weight = 0.2;
$product->active = 1;
$product->available_for_order = 1;
$product->show_price = 1;
$product->visibility = 'both';
$product->minimal_quantity = 1;
$product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY') ?: 2;
$product->customizable = 1;
$product->text_fields = 1;
$product->uploadable_files = 0;
$pid ? $product->update() : $product->add();
$pid = (int) $product->id;
$product->addToCategories([(int) $product->id_category_default]);

// The text customization field "Name to print" (the same name as the attribute group).
$fid = (int) Db::getInstance()->getValue('SELECT cf.id_customization_field FROM ' . _DB_PREFIX_ . 'customization_field cf JOIN '
    . _DB_PREFIX_ . 'customization_field_lang cfl ON cfl.id_customization_field = cf.id_customization_field AND cfl.id_lang = ' . $lang
    . ' WHERE cf.id_product = ' . $pid . " AND cf.type = 1 AND cfl.name = 'Name to print'" . (property_exists('CustomizationField', 'is_deleted') ? ' AND cf.is_deleted = 0' : ''));
if (!$fid) {
    $field = new CustomizationField();
    $field->id_product = $pid;
    $field->type = Product::CUSTOMIZE_TEXTFIELD;
    $field->required = false;
    $field->name = sudemi_demo_ml('Name to print', $languages);
    $field->add();
    $fid = (int) $field->id;
}

// Existing combinations by their choice.
$existing = [];
$keyOf = [];
foreach ($ids as $groupName => $values) {
    foreach ($values as $value => $aid) {
        $keyOf[$aid] = [$groupName, $value];
    }
}
$byCombination = [];
foreach ($product->getAttributeCombinations($lang) as $row) {
    $byCombination[(int) $row['id_product_attribute']][] = (int) $row['id_attribute'];
}
foreach ($byCombination as $paid => $attributeIds) {
    $parts = [];
    foreach ($attributeIds as $aid) {
        if (isset($keyOf[$aid])) {
            $parts[$keyOf[$aid][0]] = $keyOf[$aid][1];
        }
    }
    $key = isset($parts['Size'], $parts['Colour'], $parts['Material'], $parts['Name to print'])
        ? implode('|', [$parts['Size'], $parts['Colour'], $parts['Material'], $parts['Name to print']]) : 'other-' . $paid;
    if (isset($existing[$key])) {
        (new Combination($paid))->delete();
        continue;
    }
    $existing[$key] = $paid;
}

$out = [];
$first = true;
foreach ($axes['Size'] as $size => $p1) {
    foreach ($axes['Colour'] as $colour => $p2) {
        foreach ($axes['Material'] as $material => $p3) {
            foreach ($axes['Name to print'] as $named => $p4) {
                $key = "$size|$colour|$material|$named";
                $paid = isset($existing[$key]) ? $existing[$key] : 0;
                unset($existing[$key]);
                $combination = new Combination($paid ?: null);
                $combination->id_product = $pid;
                $combination->price = $p1 + $p2 + $p3 + $p4;
                $combination->weight = 0;
                $combination->minimal_quantity = 1;
                $combination->default_on = $first ? 1 : null;
                $combination->reference = $reference . '-' . strtoupper($size . '-' . $colour . '-' . $material . '-' . ($named === 'Yes' ? 'NAME' : 'PLAIN'));
                if ($paid) {
                    $combination->update();
                } else {
                    $combination->add();
                    $combination->setAttributes([$ids['Size'][$size], $ids['Colour'][$colour], $ids['Material'][$material], $ids['Name to print'][$named]]);
                    StockAvailable::setQuantity($pid, (int) $combination->id, $stock);
                }
                $out[$key] = (int) $combination->id;
                $first = false;
            }
        }
    }
}
foreach ($existing as $paid) {
    (new Combination($paid))->delete(); // a choice that is no longer offered
}
Product::updateDefaultAttribute($pid);

echo json_encode(['product' => $pid, 'field' => $fid, 'count' => count($out), 'combinations' => $out]);
