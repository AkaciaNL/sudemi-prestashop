<?php
/**
 * Who builds the PrestaShop order for a sudemi order: exactly one caller, decided by the database
 * before anything is built.
 *
 * Review 2026-11-12: the order (and the stock it takes) used to be created first and only then
 * linked to its sudemi order, so two callers at the same moment (a double click on Accept, a bulk
 * accept, the heartbeat's automatic answer) both saw no order and both built one. The fix is the
 * order of steps, not a lock: a row keyed on the sudemi order id is inserted first, the primary
 * key lets exactly one insert in, and only that caller builds the order and then writes its id
 * into the row. Everyone else is told the order is built (or being built) and builds nothing.
 *
 * A caller that dies after claiming leaves a row with no order behind it. Such a row is taken over
 * after STALE_SECONDS by a conditional update, which again lets exactly one caller in.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiClaims
{
    const STALE_SECONDS = 600;

    /** The claim was won: this caller builds the order. */
    const WON = 'won';
    /** Another caller claimed it moments ago and is building it now. */
    const BUSY = 'busy';

    private static function table()
    {
        return _DB_PREFIX_ . 'sudemi_order_claim';
    }

    public static function installTable()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
                `sudemi_order_id` VARCHAR(36) NOT NULL,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `claimed_at` DATETIME NOT NULL,
                PRIMARY KEY (`sudemi_order_id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function uninstallTable()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::table() . '`');
    }

    /**
     * Claims the right to build the order for a sudemi order.
     *
     * @param string $sudemiId
     * @return string|int self::WON, self::BUSY, or the id of the PrestaShop order already built
     */
    public static function claim($sudemiId)
    {
        $db = Db::getInstance();
        $id = pSQL(strtolower($sudemiId));
        $now = gmdate('Y-m-d H:i:s');
        $db->execute('INSERT IGNORE INTO `' . self::table() . "` (sudemi_order_id, id_order, claimed_at)
                      VALUES ('" . $id . "', 0, '" . $now . "')");
        if ((int) $db->Affected_Rows() === 1) {
            return self::WON;
        }
        $row = $db->getRow('SELECT id_order, claimed_at FROM `' . self::table() . "`
                             WHERE sudemi_order_id = '" . $id . "'", false);
        if (!$row) {
            // Released between the insert and the read (a failed build): the next caller claims it.
            return self::BUSY;
        }
        $local = (int) $row['id_order'];
        if ($local > 0) {
            $order = new Order($local);
            if (!Validate::isLoadedObject($order)) {
                // Deleted for good while sudemi still waits: taken over from exactly that order id,
                // so only one caller rebuilds it.
                $db->execute('UPDATE `' . self::table() . "` SET id_order = 0, claimed_at = '" . $now . "'
                              WHERE sudemi_order_id = '" . $id . "' AND id_order = " . $local);

                return (int) $db->Affected_Rows() === 1 ? self::WON : self::BUSY;
            }

            return $local;
        }
        if ($row['claimed_at'] >= gmdate('Y-m-d H:i:s', time() - self::STALE_SECONDS)) {
            return self::BUSY;
        }
        // The caller that claimed it died before the order was built. Taken over from exactly the
        // time it was claimed, so of several callers arriving now only one gets it.
        $db->execute('UPDATE `' . self::table() . "` SET claimed_at = '" . $now . "'
                      WHERE sudemi_order_id = '" . $id . "' AND id_order = 0
                        AND claimed_at = '" . pSQL($row['claimed_at']) . "'");

        return (int) $db->Affected_Rows() === 1 ? self::WON : self::BUSY;
    }

    /** Records the order the winner built. */
    public static function link($sudemiId, $orderId)
    {
        Db::getInstance()->execute('UPDATE `' . self::table() . '` SET id_order = ' . (int) $orderId
            . " WHERE sudemi_order_id = '" . pSQL(strtolower($sudemiId)) . "'");
    }

    /** Gives the claim up: nothing was built. */
    public static function release($sudemiId)
    {
        Db::getInstance()->execute('DELETE FROM `' . self::table() . "`
                                    WHERE sudemi_order_id = '" . pSQL(strtolower($sudemiId)) . "'");
    }
}
