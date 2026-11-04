<?php
/**
 * A short, bounded log a shop owner can read, which never contains key material.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiLogger
{
    const CONF = 'SUDEMI_LOG';
    const LIMIT = 50;

    public static function info($message)
    {
        self::write('info', $message);
    }

    public static function warn($message)
    {
        self::write('warn', $message);
    }

    public static function error($message)
    {
        self::write('error', $message);
    }

    public static function entries()
    {
        $raw = Configuration::get(self::CONF);
        $log = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];

        return is_array($log) ? $log : [];
    }

    public static function clear()
    {
        Configuration::deleteByName(self::CONF);
    }

    private static function write($level, $message)
    {
        $log = self::entries();
        array_unshift($log, [
            'at' => gmdate('c'),
            'level' => $level,
            // The key classes never expose key material, so nothing here can carry it; the
            // message is still truncated so a long upstream error cannot fill the row.
            'message' => Tools::substr(strip_tags((string) $message), 0, 300),
        ]);
        Configuration::updateValue(self::CONF, json_encode(array_slice($log, 0, self::LIMIT)));
    }
}
