<?php

/**
 * The shop's signing key.
 *
 * Generated on this host and never sent anywhere. sudemi receives the public half only, which
 * is why a breach of their database cannot produce a signature for this shop.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiKeys
{
    public const CONF_SECRET = 'SUDEMI_SIGNING_SECRET';
    public const CONF_PUBLIC = 'SUDEMI_SIGNING_PUBLIC';

    /**
     * Whether this host can sign at all. Checked before anything is offered, so a host without
     * libsodium is told on the configuration screen rather than failing in a cron job later.
     */
    public static function isSupported()
    {
        return function_exists('sodium_crypto_sign_keypair')
            && function_exists('sodium_crypto_sign_detached');
    }

    public static function regenerate()
    {
        if (!self::isSupported()) {
            return false;
        }
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);

        Configuration::updateValue(self::CONF_SECRET, base64_encode($secret));
        Configuration::updateValue(self::CONF_PUBLIC, base64_encode($public));

        sodium_memzero($keypair);
        sodium_memzero($secret);

        return true;
    }

    /**
     * The public key as X.509 SubjectPublicKeyInfo, which is what the platform parses.
     * libsodium returns 32 raw bytes; the fixed 12-byte Ed25519 prefix makes it a DER key.
     */
    public static function publicKeyDer()
    {
        $raw = self::rawPublic();
        if ($raw === null) {
            return null;
        }
        $prefix = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

        return base64_encode($prefix . $raw);
    }

    public static function sign($message)
    {
        $secret = self::rawSecret();
        if ($secret === null) {
            return null;
        }
        $signature = sodium_crypto_sign_detached($message, $secret);
        sodium_memzero($secret);

        return base64_encode($signature);
    }

    public static function hasKey()
    {
        return self::rawSecret() !== null;
    }

    public static function forget()
    {
        Configuration::deleteByName(self::CONF_SECRET);
        Configuration::deleteByName(self::CONF_PUBLIC);
    }

    private static function rawSecret()
    {
        $stored = Configuration::get(self::CONF_SECRET);
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);

        return ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) ? null : $raw;
    }

    private static function rawPublic()
    {
        $stored = Configuration::get(self::CONF_PUBLIC);
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);

        return ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? null : $raw;
    }
}
