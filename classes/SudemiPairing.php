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

        return ['ok' => true, 'message' => 'Open the link and confirm the shop on sudemi.'];
    }

    /** Asks whether the shop has been claimed yet. Called from the configuration screen and from cron. */
    public static function poll()
    {
        $code = Configuration::get(self::CONF_CODE);
        $token = Configuration::get(self::CONF_POLL);
        if (!is_string($code) || $code === '' || !is_string($token) || $token === '') {
            return false;
        }

        $result = SudemiClient::postUnsigned('/integration/v1/pair/poll', [
            'publicCode' => $code,
            'pollToken' => $token,
        ]);
        if ($result === null) {
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
            SudemiLogger::info('Connected to sudemi.');

            return true;
        }

        return false;
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
