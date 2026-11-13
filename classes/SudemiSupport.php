<?php

/**
 * Support tickets with sudemi, from the module page.
 *
 * The shop owner opens a ticket, reads sudemi's answers and replies, all through the signed
 * /integration/v1/support calls: sudemi knows which shop is asking from the install's key, so
 * nothing here says who the shop is. The module sends no e-mail; sudemi mails the shop's contact
 * address when support answers.
 *
 * Validation here mirrors sudemi's own (which stays the authority) so a mistake is explained
 * before anything is sent. Every function that does not talk to sudemi is pure, for the tests.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiSupport
{
    public const PATH = '/integration/v1/support/tickets';

    public const CATEGORIES = ['ORDER', 'PAYMENT', 'REFUND_RETURN', 'ACCOUNT', 'SHOP_PLUGIN', 'PAYOUTS', 'OTHER'];

    public const STATUSES = ['OPEN', 'WAITING_FOR_CUSTOMER', 'ANSWERED', 'CLOSED'];

    /** The languages sudemi writes its support e-mails in. */
    public const LOCALES = ['en', 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he'];

    public const SUBJECT_MIN = 3;
    public const SUBJECT_MAX = 200;
    public const MESSAGE_MAX = 10000;

    /** Error kinds, which the module page turns into translated messages. */
    public const ERR_CATEGORY = 'category';
    public const ERR_SUBJECT = 'subject';
    public const ERR_MESSAGE = 'message';
    public const ERR_ORDER = 'order';
    public const ERR_INVALID = 'invalid';
    public const ERR_NOT_FOUND = 'not_found';
    public const ERR_TOO_MANY = 'too_many';
    public const ERR_UNREACHABLE = 'unreachable';
    public const ERR_FAILED = 'failed';
    public const ERR_SITE = 'site_mismatch';

    /** A ticket id as it goes into a path: a UUID and nothing else. */
    public static function isTicketId($id)
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $id) === 1;
    }

    /** The employee's language as sudemi takes it, or null when sudemi has no such language. */
    public static function locale($iso)
    {
        if (!is_string($iso)) {
            return null;
        }
        $two = strtolower(substr(trim($iso), 0, 2));

        return in_array($two, self::LOCALES, true) ? $two : null;
    }

    private static function length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * The body for a new ticket, or the first thing wrong with the form.
     *
     * @param string|null $orderId a sudemi order id (UUID), already resolved; null or '' for none
     *
     * @return array{ok:bool,error?:string,body?:array}
     */
    public static function buildTicket($category, $subject, $message, $orderId, $locale)
    {
        $category = is_string($category) ? strtoupper(trim($category)) : '';
        if (!in_array($category, self::CATEGORIES, true)) {
            return ['ok' => false, 'error' => self::ERR_CATEGORY];
        }
        $subject = is_string($subject) ? trim($subject) : '';
        if (self::length($subject) < self::SUBJECT_MIN || self::length($subject) > self::SUBJECT_MAX) {
            return ['ok' => false, 'error' => self::ERR_SUBJECT];
        }
        $message = self::cleanMessage($message);
        if ($message === null) {
            return ['ok' => false, 'error' => self::ERR_MESSAGE];
        }
        $body = ['category' => $category, 'subject' => $subject, 'message' => $message];
        if ($orderId !== null && $orderId !== '') {
            if (!self::isTicketId($orderId)) {
                return ['ok' => false, 'error' => self::ERR_ORDER];
            }
            $body['orderId'] = strtolower($orderId);
        }
        $locale = self::locale($locale);
        if ($locale !== null) {
            $body['locale'] = $locale;
        }

        return ['ok' => true, 'body' => $body];
    }

    /** A reply or first message: trimmed, 1..10000 characters, else null. */
    public static function cleanMessage($message)
    {
        $message = is_string($message) ? trim(str_replace("\r\n", "\n", $message)) : '';
        if ($message === '' || self::length($message) > self::MESSAGE_MAX) {
            return null;
        }

        return $message;
    }

    /**
     * What went wrong with an answer from sudemi, or null when it is the success expected.
     *
     * @param array|null $response as SudemiClient returns it
     */
    public static function errorOf($response, $expected = 200)
    {
        if ($response === null) {
            return self::ERR_UNREACHABLE;
        }
        $status = (int) $response['status'];
        if ($status === $expected || ($expected === 201 && $status === 200)) {
            return null;
        }
        if ($status === 400) {
            return self::ERR_INVALID;
        }
        if ($status === 404) {
            return self::ERR_NOT_FOUND;
        }
        if ($status === 429) {
            return self::ERR_TOO_MANY;
        }
        // This site is not the connected shop (a staging copy, a restored backup, a moved shop).
        if ($status === 409 && isset($response['body']['error']) && $response['body']['error'] === 'site_mismatch') {
            return self::ERR_SITE;
        }

        return self::ERR_FAILED;
    }

    /** @return array{ok:bool,error?:string,tickets?:array} */
    public static function listTickets()
    {
        $response = SudemiClient::sendSigned('GET', self::PATH);
        $error = self::errorOf($response);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        $tickets = isset($response['body']['tickets']) && is_array($response['body']['tickets'])
            ? array_values(array_filter($response['body']['tickets'], 'is_array')) : [];

        return ['ok' => true, 'tickets' => $tickets];
    }

    /** @return array{ok:bool,error?:string,ticket?:array} */
    public static function getTicket($id)
    {
        if (!self::isTicketId($id)) {
            return ['ok' => false, 'error' => self::ERR_NOT_FOUND];
        }

        return self::ticketOf(SudemiClient::sendSigned('GET', self::PATH . '/' . strtolower($id)), 200);
    }

    /** @return array{ok:bool,error?:string,ticket?:array} */
    public static function createTicket($category, $subject, $message, $orderId, $locale)
    {
        $built = self::buildTicket($category, $subject, $message, $orderId, $locale);
        if (!$built['ok']) {
            return $built;
        }

        return self::ticketOf(SudemiClient::sendSigned('POST', self::PATH, $built['body']), 201);
    }

    /** @return array{ok:bool,error?:string,ticket?:array} */
    public static function reply($id, $message)
    {
        if (!self::isTicketId($id)) {
            return ['ok' => false, 'error' => self::ERR_NOT_FOUND];
        }
        $message = self::cleanMessage($message);
        if ($message === null) {
            return ['ok' => false, 'error' => self::ERR_MESSAGE];
        }

        return self::ticketOf(
            SudemiClient::sendSigned('POST', self::PATH . '/' . strtolower($id) . '/messages', ['message' => $message]),
            200
        );
    }

    private static function ticketOf($response, $expected)
    {
        $error = self::errorOf($response, $expected);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        $ticket = isset($response['body']['ticket']) && is_array($response['body']['ticket']) ? $response['body']['ticket'] : null;
        if ($ticket === null || !isset($ticket['id']) || !self::isTicketId((string) $ticket['id'])) {
            return ['ok' => false, 'error' => self::ERR_FAILED];
        }

        return ['ok' => true, 'ticket' => $ticket];
    }
}
