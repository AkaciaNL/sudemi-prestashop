<?php
/**
 * Every call this shop makes to sudemi, signed.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiClient
{
    const CONF_INSTALL_ID = 'SUDEMI_INSTALL_ID';
    const CONF_API_BASE = 'SUDEMI_API_BASE';

    const DEFAULT_API_BASE = 'https://api.sudemi.com';
    /**
     * How many times to wait when sudemi says this shop is pushing too fast. Two, because the
     * window is a minute: a shop still throttled after two waits is sending far more than the
     * limit and should resume on its next run rather than hold a cron slot open.
     */
    const RATE_LIMIT_RETRIES = 2;

    /** A cap, so a strange Retry-After cannot park a cron run for an hour. */
    const RATE_LIMIT_MAX_WAIT = 90;

    const TIMEOUT_SECONDS = 20;

    /**
     * Test seam, never set in production: when a callable, every call goes through it as
     * fn($method, $path, array $body, callable $send), where $send() makes the real call, and
     * what it returns is used as the answer (null = sudemi could not be reached). The shop e2e
     * tests use it to lose an answer or fake a 503/429 inside one PHP process; nothing outside
     * this process can set it.
     *
     * @var callable|null
     */
    public static $interceptor = null;

    public static function apiBase()
    {
        $configured = Configuration::get(self::CONF_API_BASE);
        $base = (is_string($configured) && $configured !== '') ? $configured : self::DEFAULT_API_BASE;

        return rtrim($base, '/');
    }

    public static function installId()
    {
        $id = Configuration::get(self::CONF_INSTALL_ID);

        return (is_string($id) && $id !== '') ? $id : null;
    }

    public static function isConnected()
    {
        return self::installId() !== null && SudemiKeys::hasKey();
    }

    public static function postUnsigned($path, array $body)
    {
        return self::request($path, $body, false);
    }

    public static function postSigned($path, array $body)
    {
        return self::requestWithBackoff($path, $body, true);
    }

    /**
     * Sends, and waits if sudemi says this shop is going too fast.
     *
     * sudemi caps how much catalogue one shop may push per minute, so a large import cannot take
     * the service away from every other shop. A 429 is therefore an ordinary part of a full sync
     * rather than a failure: it means "not yet", and it says how long to wait.
     *
     * Treating it as a failure is what makes a large catalogue impossible to sync at all -- the
     * batch stops, the sync is abandoned, and the next attempt gets exactly as far.
     */
    /**
     * A signed GET.
     *
     * The canonical string carries the method, so a GET cannot be replayed as a POST or the
     * other way round. Used for reading a confirmed order's delivery address, which is the one
     * thing sudemi hands back rather than receives.
     */
    public static function getSigned($path)
    {
        return self::requestWithBackoff($path, [], true, 'GET');
    }

    /**
     * One signed call, no waiting on a 429.
     *
     * For calls a person is waiting on in the back office (support tickets): there a 429 is an
     * answer to show ("too many tickets"), not a reason to hold the page for minutes. The path
     * carries any query string, which is signed with it; a GET signs the hash of an empty body.
     */
    public static function sendSigned($method, $path, array $body = [], array $headers = [])
    {
        return self::request($path, $body, true, $method === 'GET' ? 'GET' : 'POST', $headers);
    }

    /**
     * A signed GET whose answer is not JSON (the CSV export): the body as it came, with the
     * status and the headers the caller needs (content type, file name, row counts).
     *
     * @return array{status:int,raw:string,headers:array}|null null when sudemi could not be reached
     */
    public static function getRaw($path)
    {
        return self::request($path, [], true, 'GET', [], true);
    }

    private static function requestWithBackoff($path, array $body, $signed, $method = 'POST')
    {
        $attempt = 0;
        while (true) {
            $response = self::request($path, $body, $signed, $method);
            if ($response === null || (int) $response['status'] !== 429) {
                return $response;
            }
            ++$attempt;
            if ($attempt > self::RATE_LIMIT_RETRIES) {
                SudemiLogger::warn(
                    'sudemi is still asking this shop to slow down. Stopping for now; this '
                    . 'continues on the next cron run and nothing has been lost.'
                );

                return $response;
            }
            $wait = isset($response['retry_after']) ? (int) $response['retry_after'] : 60;
            // Capped: a proxy could return an absurd value, and a cron run that sleeps for an
            // hour is worse than one that gives up and retries later.
            $wait = max(1, min(self::RATE_LIMIT_MAX_WAIT, $wait));
            SudemiLogger::info('sudemi asked this shop to slow down; waiting ' . $wait . 's.');
            sleep($wait);
        }
    }

    /**
     * The headers that make a call signed, or null when this shop cannot sign (not connected).
     *
     * The signature covers the method, the path, the timestamp, a nonce and the body hash.
     * Sudemi-Site is sent beside it, unsigned: the address this shop believes it lives at. A
     * staging copy or a restored backup holds this shop's install id and key, and this is how
     * sudemi tells it apart from the live shop and refuses it (409 site_mismatch).
     *
     * @return string[]|null
     */
    public static function signedHeaders($method, $path, $json)
    {
        $installId = self::installId();
        if ($installId === null) {
            return null;
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", [$method, $path, $timestamp, $nonce, hash('sha256', $json)]);
        $signature = SudemiKeys::sign($canonical);
        if ($signature === null) {
            return null;
        }
        $headers = [
            'Sudemi-Install: ' . $installId,
            'Sudemi-Timestamp: ' . $timestamp,
            'Sudemi-Nonce: ' . $nonce,
            'Sudemi-Signature: ' . $signature,
        ];
        $site = self::siteUrl();
        if ($site !== '') {
            $headers[] = 'Sudemi-Site: ' . $site;
        }

        return $headers;
    }

    /** This shop's own base URL, the same address pairing announced. '' when it cannot be told. */
    public static function siteUrl()
    {
        try {
            $url = (string) SudemiPairing::shopUrl();
        } catch (\Throwable $e) {
            return '';
        }

        // A header value: no line breaks, whatever the shop's settings hold.
        return trim(preg_replace('/[\r\n]+/', '', $url));
    }

    /**
     * Whether sudemi refused a call because it came from another site than the connected shop.
     *
     * @param array|null $response as request() returns it
     *
     * @return string|null null when it did not; otherwise the address the shop is connected
     *                     under, as sudemi named it, or '' when it could not be read
     */
    public static function siteMismatch($response)
    {
        if (!is_array($response) || (int) $response['status'] !== 409
            || !isset($response['body']['error']) || $response['body']['error'] !== 'site_mismatch') {
            return null;
        }
        $message = isset($response['body']['message']) ? (string) $response['body']['message'] : '';
        if (preg_match('#connected to sudemi as (https?://[A-Za-z0-9.:\[\]-]+)#', $message, $match)) {
            return rtrim($match[1], '.:');
        }

        return '';
    }

    /**
     * @return array{status:int,body:array}|null null when the call could not be made at all
     */
    private static function request($path, array $body, $signed, $method = 'POST', array $extra = [], $rawAnswer = false)
    {
        if (is_callable(self::$interceptor)) {
            $send = function () use ($path, $body, $signed, $method, $extra, $rawAnswer) {
                return self::send($path, $body, $signed, $method, $extra, $rawAnswer);
            };

            return call_user_func(self::$interceptor, $method, $path, $body, $send);
        }

        return self::send($path, $body, $signed, $method, $extra, $rawAnswer);
    }

    /**
     * The JSON a body is sent as. Text that is not valid UTF-8 (an old product description, a
     * badly imported name) is replaced by U+FFFD instead of failing the whole call.
     *
     * @return string|false
     */
    public static function encode(array $body)
    {
        return $body === [] ? '{}' : json_encode($body, self::jsonFlags());
    }

    /** JSON_INVALID_UTF8_SUBSTITUTE where PHP has it (7.2+). */
    public static function jsonFlags()
    {
        return defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
    }

    private static function send($path, array $body, $signed, $method = 'POST', array $extra = [], $rawAnswer = false)
    {
        // A GET carries no body, and the signature covers the hash of the body actually sent --
        // so it has to be the hash of nothing, not the hash of "{}".
        // An empty body is sent as {} rather than []: an endpoint that takes an optional object
        // refuses a JSON array.
        $json = $method === 'GET' ? '' : self::encode($body);
        if ($json === false) {
            SudemiLogger::warn('A payload could not be encoded.');

            return null;
        }

        $headers = ['Content-Type: application/json'];

        if ($signed) {
            $signedHeaders = self::signedHeaders($method, $path, $json);
            if ($signedHeaders === null) {
                return null;
            }
            $headers = array_merge($headers, $signedHeaders);
        }
        // Extra headers a call needs (Idempotency-Key on a refund): one line each, no line breaks.
        foreach ($extra as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', (string) $name)) {
                $headers[] = $name . ': ' . preg_replace('/[\r\n]+/', '', (string) $value);
            }
        }

        $curl = curl_init(self::apiBase() . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // Never relaxed, and deliberately not configurable: a shop that cannot verify the
            // certificate is a shop whose catalogue could be rewritten in transit.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'sudemi-prestashop/' . SUDEMI_VERSION,
        ]);
        // Retry-After, so a throttled sync knows how long to pause; for a raw answer (the CSV
        // export) also the few headers that describe the file.
        $retryAfter = 0;
        $kept = [];
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($handle, $header) use (&$retryAfter, &$kept) {
            if (stripos($header, 'retry-after:') === 0) {
                $retryAfter = (int) trim(substr($header, strlen('retry-after:')));
            }
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                if (in_array($name, ['content-type', 'content-disposition', 'x-total-count', 'x-export-truncated'], true)) {
                    $kept[$name] = trim($parts[1]);
                }
            }

            return strlen($header);
        });
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            SudemiLogger::warn('Could not reach sudemi: ' . $error);

            return null;
        }

        if ($rawAnswer) {
            return ['status' => $status, 'raw' => (string) $raw, 'headers' => $kept, 'retry_after' => $retryAfter];
        }

        $parsed = json_decode($raw, true);
        // Fail closed: a response that does not parse is not success, whatever the status said.
        if (!is_array($parsed) && $status !== 204) {
            SudemiLogger::warn('sudemi answered ' . $status . ' with something that was not JSON.');

            return null;
        }

        return [
            'status' => $status,
            'body' => is_array($parsed) ? $parsed : [],
            'retry_after' => $retryAfter,
        ];
    }
}
