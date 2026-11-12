<?php
/**
 * Review 2026-11-12: exactly one caller builds the PrestaShop order for a sudemi order.
 *
 * Runs SudemiClaims against an in-memory table that behaves like MySQL for the statements used
 * (INSERT IGNORE on the primary key, SELECT, conditional UPDATE, DELETE). That the database lets
 * only one insert in under real concurrency is checked end to end on a dev shop (e2e).
 *
 * Runs without PrestaShop.
 */

declare(strict_types=1);

define('_PS_VERSION_', 'test');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');

function pSQL($s)
{
    return addslashes((string) $s);
}

$existingOrders = [501 => true];

class Order
{
    public $id;

    public function __construct($id)
    {
        global $existingOrders;
        $this->id = isset($existingOrders[$id]) ? $id : null;
    }
}

class Validate
{
    public static function isLoadedObject($o)
    {
        return $o->id !== null;
    }
}

class Db
{
    public static $rows = [];
    private $affected = 0;
    private static $one;

    public static function getInstance()
    {
        return self::$one ?: (self::$one = new Db());
    }

    public function Affected_Rows()
    {
        return $this->affected;
    }

    public function execute($sql)
    {
        $this->affected = 0;
        if (preg_match("/^INSERT IGNORE .*VALUES \\('([^']+)', 0, '([^']+)'\\)/s", trim($sql), $m)) {
            if (!isset(self::$rows[$m[1]])) {
                self::$rows[$m[1]] = ['id_order' => 0, 'claimed_at' => $m[2]];
                $this->affected = 1;
            }

            return true;
        }
        if (preg_match("/SET id_order = 0, claimed_at = '([^']+)'\\s+WHERE sudemi_order_id = '([^']+)' AND id_order = (\\d+)/s", $sql, $m)) {
            if (isset(self::$rows[$m[2]]) && self::$rows[$m[2]]['id_order'] === (int) $m[3]) {
                self::$rows[$m[2]] = ['id_order' => 0, 'claimed_at' => $m[1]];
                $this->affected = 1;
            }

            return true;
        }
        if (preg_match("/SET claimed_at = '([^']+)'\\s+WHERE sudemi_order_id = '([^']+)' AND id_order = 0\\s+AND claimed_at = '([^']+)'/s", $sql, $m)) {
            if (isset(self::$rows[$m[2]]) && self::$rows[$m[2]]['id_order'] === 0 && self::$rows[$m[2]]['claimed_at'] === $m[3]) {
                self::$rows[$m[2]]['claimed_at'] = $m[1];
                $this->affected = 1;
            }

            return true;
        }
        if (preg_match("/SET id_order = (\\d+) WHERE sudemi_order_id = '([^']+)'/s", $sql, $m)) {
            self::$rows[$m[2]]['id_order'] = (int) $m[1];

            return true;
        }
        if (preg_match("/^DELETE .*sudemi_order_id = '([^']+)'/s", trim($sql), $m)) {
            unset(self::$rows[$m[1]]);

            return true;
        }
        throw new RuntimeException('unexpected SQL ' . $sql);
    }

    public function getRow($sql, $cache = true)
    {
        preg_match("/sudemi_order_id = '([^']+)'/", $sql, $m);

        return isset(self::$rows[$m[1]]) ? self::$rows[$m[1]] : false;
    }
}

require __DIR__ . '/../classes/SudemiClaims.php';

$failures = 0;
function expect($label, $actual, $expected)
{
    global $failures;
    if ($actual === $expected) {
        echo "ok   $label\n";

        return;
    }
    ++$failures;
    echo "FAIL $label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}

$a = 'aaaaaaaa-0000-0000-0000-000000000001';
expect('first caller wins', SudemiClaims::claim($a), SudemiClaims::WON);
expect('second caller at the same moment builds nothing', SudemiClaims::claim($a), SudemiClaims::BUSY);
expect('upper case is the same order', SudemiClaims::claim(strtoupper($a)), SudemiClaims::BUSY);

SudemiClaims::link($a, 501);
expect('after the build, callers get the built order', SudemiClaims::claim($a), 501);

unset($existingOrders[501]);
expect('deleted order: one caller takes it over', SudemiClaims::claim($a), SudemiClaims::WON);
expect('deleted order: the next caller does not', SudemiClaims::claim($a), SudemiClaims::BUSY);

$b = 'bbbbbbbb-0000-0000-0000-000000000002';
Db::$rows[$b] = ['id_order' => 0, 'claimed_at' => gmdate('Y-m-d H:i:s', time() - SudemiClaims::STALE_SECONDS - 5)];
expect('stale claim: one caller takes it over', SudemiClaims::claim($b), SudemiClaims::WON);
expect('stale claim: the next caller is busy', SudemiClaims::claim($b), SudemiClaims::BUSY);

$c = 'cccccccc-0000-0000-0000-000000000003';
Db::$rows[$c] = ['id_order' => 0, 'claimed_at' => gmdate('Y-m-d H:i:s', time() - 60)];
expect('fresh claim is not taken over', SudemiClaims::claim($c), SudemiClaims::BUSY);
SudemiClaims::release($c);
expect('released claim can be claimed again', SudemiClaims::claim($c), SudemiClaims::WON);

if ($failures > 0) {
    echo "$failures failure(s)\n";
    exit(1);
}
echo "all passed\n";
