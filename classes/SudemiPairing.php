<?php
/**
 * Connecting this shop to a sudemi seller account.
 *
 * The module generates the keypair and asks sudemi to start a pairing, serves the challenge
 * token at a fixed path so sudemi can confirm the request came from this shop, and polls until
 * a person has claimed the shop in a browser. Signing in happens on sudemi: this module never
 * asks for a sudemi password and could not use one.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiPairing
{
    const CONF_CHALLENGE = 'SUDEMI_CHALLENGE_TOKEN';
    const CONF_CODE = 'SUDEMI_PAIRING_CODE';
    const CONF_POLL = 'SUDEMI_PAIRING_POLL';
    const CONF_CLAIM_URL = 'SUDEMI_CLAIM_URL';
    /** When this pairing was started (unix time), so the background poll knows its lifetime. */
    const CONF_STARTED = 'SUDEMI_PAIRING_STARTED';
    /** Not before this time (unix) does the background poll ask again. */
    const CONF_NEXT_POLL = 'SUDEMI_PAIRING_NEXT_POLL';

    /** sudemi keeps a pairing open for 24 hours; after that there is nothing left to collect. */
    const LIFETIME = 86400;

    /**
     * The background poll asks at most once a minute for the first half hour (the shop owner is
     * usually confirming right now, in another tab), then every twenty minutes. That keeps a
     * whole 24-hour pairing within the 100 polls sudemi allows per pairing (PROTOCOL.md).
     */
    const POLL_EVERY = 60;
    const POLL_FAST_FOR = 1800;
    const POLL_SLOW_EVERY = 1200;

    /** @return array{ok:bool,message:string} */
    public static function start()
    {
        if (!SudemiKeys::isSupported()) {
            return ['ok' => false, 'message' => 'This server is missing the sodium extension, which the connection needs in order to sign requests. Ask your host to enable it.'];
        }
        if (!SudemiKeys::regenerate()) {
            return ['ok' => false, 'message' => 'A signing key could not be created on this server.'];
        }

        $result = SudemiClient::postUnsigned('/integration/v1/pair', [
            'shopUrl' => self::shopUrl(),
            'platform' => 'prestashop',
            'pluginVersion' => SUDEMI_VERSION,
            'publicKey' => SudemiKeys::publicKeyDer(),
            'proposedProfile' => self::proposedProfile(),
        ]);

        if ($result === null) {
            return ['ok' => false, 'message' => 'sudemi could not be reached.'];
        }
        if ($result['status'] !== 200) {
            $message = isset($result['body']['message']) ? $result['body']['message'] : 'sudemi refused to start the connection.';

            return ['ok' => false, 'message' => $message];
        }

        $body = $result['body'];
        Configuration::updateValue(self::CONF_CHALLENGE, isset($body['challenge_token']) ? $body['challenge_token'] : '');
        Configuration::updateValue(self::CONF_CODE, isset($body['public_code']) ? $body['public_code'] : '');
        Configuration::updateValue(self::CONF_POLL, isset($body['poll_token']) ? $body['poll_token'] : '');
        Configuration::updateValue(self::CONF_CLAIM_URL, isset($body['claim_url']) ? $body['claim_url'] : '');
        Configuration::updateValue(self::CONF_STARTED, time());
        Configuration::deleteByName(self::CONF_NEXT_POLL);
        // The cron URL has to work before the module page is opened again: the next cron run is
        // what collects this pairing once the shop owner has confirmed it on sudemi.
        SudemiHeartbeat::cronToken();

        return ['ok' => true, 'message' => 'Open the link and confirm the shop on sudemi.'];
    }

    /** Asks whether the shop has been claimed yet. Called by "Check again" and by pollInBackground(). */
    public static function poll()
    {
        $code = Configuration::get(self::CONF_CODE);
        $token = Configuration::get(self::CONF_POLL);
        if (!is_string($code) || $code === '' || !is_string($token) || $token === '') {
            return false;
        }

        // Whoever asked (the button or the background), the background poll waits a while.
        Configuration::updateValue(self::CONF_NEXT_POLL, time() + self::pollGap());
        $result = SudemiClient::postUnsigned('/integration/v1/pair/poll', [
            'publicCode' => $code,
            'pollToken' => $token,
        ]);
        if ($result === null) {
            return false;
        }

        // 429: sudemi asks for a pause. The background poll waits as long as it was told.
        if ($result['status'] === 429) {
            $wait = !empty($result['retry_after']) ? (int) $result['retry_after'] : 300;
            Configuration::updateValue(self::CONF_NEXT_POLL, time() + max(self::pollGap(), min(3600, $wait)));

            return false;
        }

        // 410: the pairing expired or was abandoned. Nothing left to wait for.
        if ($result['status'] === 410) {
            self::forget();
            SudemiLogger::warn('The connection attempt expired. Start it again.');

            return false;
        }

        if ($result['status'] === 200 && isset($result['body']['install_id'])) {
            Configuration::updateValue(SudemiClient::CONF_INSTALL_ID, $result['body']['install_id']);
            // The challenge has done its job; leaving it served would keep a token on a public
            // path for no reason.
            Configuration::deleteByName(self::CONF_CHALLENGE);
            self::forget();
            // A full sync a previous connection left unfinished belongs to that connection;
            // sudemi refuses it from this one. The new connection starts its own.
            SudemiSync::abandonForeign();
            SudemiLogger::info('Connected to sudemi.');

            return true;
        }

        return false;
    }

    /** True while a pairing was started here and has not been collected or given up yet. */
    public static function pending()
    {
        $code = Configuration::get(self::CONF_CODE);
        $token = Configuration::get(self::CONF_POLL);

        return is_string($code) && $code !== '' && is_string($token) && $token !== '';
    }

    /**
     * Collects a claimed pairing without anyone pressing "Check again".
     *
     * Called by every cron run and every fallback beat (back-office page loads). While a pairing
     * is open it asks sudemi once (at most once a minute, later every twenty minutes, and never
     * sooner than a 429 said), and on success stores the install exactly as the button does, so
     * the same run's heartbeat already goes out signed and sudemi asks for the first full sync.
     * A pairing older than its 24-hour lifetime is given up with one line in the log.
     *
     * @return bool whether this call connected the shop
     */
    public static function pollInBackground()
    {
        if (!self::pending()) {
            return false;
        }
        $now = time();
        $started = (int) Configuration::get(self::CONF_STARTED);
        if ($started <= 0) {
            // Started by a version that did not record it: count the lifetime from now. sudemi
            // still answers 410 when it really expired, which ends the polling sooner.
            Configuration::updateValue(self::CONF_STARTED, $now);
            $started = $now;
        }
        if ($now - $started >= self::LIFETIME) {
            self::forget();
            SudemiLogger::warn('The connection attempt expired after 24 hours without being confirmed on sudemi. Start it again.');

            return false;
        }
        if ((int) Configuration::get(self::CONF_NEXT_POLL) > $now) {
            return false;
        }

        return self::poll();
    }

    /** Seconds until the background poll may ask again, by how long this pairing has been open. */
    private static function pollGap()
    {
        $started = (int) Configuration::get(self::CONF_STARTED);

        return ($started > 0 && time() - $started >= self::POLL_FAST_FOR) ? self::POLL_SLOW_EVERY : self::POLL_EVERY;
    }

    /**
     * The fallback for shops with no cron entry: after a back-office page was sent (where
     * PHP-FPM allows it), so the employee never waits on sudemi for it.
     */
    public static function afterResponse()
    {
        if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
            while (ob_get_level() > 0 && @ob_end_flush()) {
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                litespeed_finish_request();
            }
        }
        ignore_user_abort(true);
        try {
            self::pollInBackground();
        } catch (\Throwable $e) {
            SudemiLogger::warn('Background step failed: ' . $e->getMessage());
        }
    }

    /**
     * Stores a token sudemi handed back in a heartbeat response.
     *
     * Ownership is re-proved on a schedule, not once at install: a domain can be sold or
     * repointed, and the listing names this one to buyers as the shop's identity. The same
     * front controller serves both the pairing token and these later ones, because the platform
     * asks for one fixed path and does not care why a token is there.
     */
    public static function storeChallenge($token)
    {
        Configuration::updateValue(self::CONF_CHALLENGE, (string) $token);
    }

    public static function challengeToken()
    {
        $token = Configuration::get(self::CONF_CHALLENGE);

        return (is_string($token) && $token !== '') ? $token : null;
    }

    public static function claimUrl()
    {
        $url = Configuration::get(self::CONF_CLAIM_URL);

        return (is_string($url) && $url !== '') ? $url : null;
    }

    public static function forget()
    {
        Configuration::deleteByName(self::CONF_CODE);
        Configuration::deleteByName(self::CONF_POLL);
        Configuration::deleteByName(self::CONF_CLAIM_URL);
        Configuration::deleteByName(self::CONF_STARTED);
        Configuration::deleteByName(self::CONF_NEXT_POLL);
    }

    public static function shopUrl()
    {
        $shop = Context::getContext()->shop;

        return 'https://' . $shop->domain_ssl . $shop->getBaseURI();
    }

    /**
     * What this shop knows about itself, offered as a draft.
     *
     * A draft only: the legal name and country decide a buyer's withdrawal rights, so sudemi
     * shows them for a person to confirm and locks them afterwards. This module cannot set them.
     */
    private static function proposedProfile()
    {
        $country = '';
        $countryId = (int) Configuration::get('PS_SHOP_COUNTRY_ID');
        if ($countryId > 0) {
            $country = (string) Country::getIsoById($countryId);
        }

        return [
            'displayName' => Configuration::get('PS_SHOP_NAME'),
            'legalName' => Configuration::get('PS_SHOP_NAME'),
            'establishmentCountry' => $country,
            'dispatchCountry' => $country,
            'traderStatus' => 'TRADER',
            'vatNumber' => (string) Configuration::get('PS_SHOP_DETAILS'),
            'tradeRegisterNumber' => '',
        ];
    }
}
