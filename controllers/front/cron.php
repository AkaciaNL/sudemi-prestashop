<?php
/**
 * The entry point this shop's own cron calls, every five minutes.
 *
 * PrestaShop has no scheduler of its own, so the beat has to come from somewhere: either the
 * host's crontab calling this URL, or - as a fallback for shops with no cron at all - a
 * back-office page load. Only this entry runs a full catalogue sync to the end; a page load
 * sends at most a small step of it.
 *
 * It is reachable without a session, which is why it carries a token. Without one, a stranger
 * could trigger a full catalogue walk on this shop as often as they liked, and a cron endpoint
 * that is free to hammer is a denial of service with extra steps. The token is compared with
 * hash_equals, and the answer is deliberately the same shape whether or not it matched.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;

    public function initContent()
    {
        $presented = (string) Tools::getValue('token');
        $expected = SudemiHeartbeat::cronToken();

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');

        if ($presented === '' || !hash_equals($expected, $presented)) {
            header('HTTP/1.1 403 Forbidden');
            echo 'no';
            exit;
        }

        // A full sync of a large catalogue outlives a short PHP time limit; where the host lets
        // it, this run sends it to the end. Stopped anyway, it resumes where it stopped.
        ignore_user_abort(true);
        @set_time_limit(0);

        // A pairing the shop owner confirmed on sudemi is collected here, so the shop connects
        // without anyone pressing "Check again". Once collected, the beat below already runs.
        SudemiPairing::pollInBackground();

        $answered = SudemiHeartbeat::run();
        // The full sync the beat (or a carrier change, or the module page) asked for, or the
        // rest of one that back-office page loads started.
        SudemiSync::work();

        // A beat that could not reach sudemi is still a successful cron run: the shop's own
        // scheduler must not start mailing failures because a marketplace was briefly down.
        header('HTTP/1.1 200 OK');
        echo $answered ? 'ok' : 'queued';
        exit;
    }
}
