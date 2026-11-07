<?php
/**
 * Serves the ownership challenge.
 *
 * This is what proves to sudemi that whoever started the pairing controls this shop - the only
 * thing between a stranger typing someone else's domain and listing products under their name.
 * It answers with the token and nothing else: no theme, no HTML, no redirect.
 *
 * PrestaShop routes this at /module/sudemi/challenge; the module adds a rewrite so the
 * well-known path reaches it, because the platform asks for a fixed path and will not follow a
 * shop-specific one.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiChallengeModuleFrontController extends ModuleFrontController
{
    /** No session, no cookies, no customer context: this answer is the same for everyone. */
    public $ssl = true;
    public $auth = false;

    public function initContent()
    {
        $token = SudemiPairing::challengeToken();

        if ($token === null) {
            header('HTTP/1.1 404 Not Found');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'no pairing in progress';
            exit;
        }

        header('HTTP/1.1 200 OK');
        header('Content-Type: text/plain; charset=utf-8');
        // Not cacheable: the token changes per pairing, and a cached old one fails the check in
        // a way that looks like the shop is unreachable.
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
        echo $token;
        exit;
    }
}
