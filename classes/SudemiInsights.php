<?php
/**
 * What sudemi holds about this shop (protocol 4e, 1.4.0): statistics, the shop's products as
 * sudemi lists them, and the shop profile buyers see. Calls are signed and see only this shop.
 *
 * The chart is drawn here as inline SVG (no outside scripts in the back office). Everything
 * that is not a call is pure, so tests/insights-test.php runs it without PrestaShop.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SudemiInsights
{
    /** The periods offered on the Dashboard, in days. */
    const PERIODS = [7, 30, 90];

    /** The reasons a listing is not shown (protocol 4e); anything else is shown as "not listed". */
    const REASONS = ['disabled_by_shop', 'not_in_shop', 'out_of_stock', 'no_shipping', 'suspended', 'payouts_not_ready', 'shop_not_reporting'];

    const PAGE_SIZE = 50;

    // Profile values kept by the module, sent with every catalogue sync instead of the shop's
    // own name and e-mail (before 1.4.0 each sync overwrote what the seller had set on sudemi).
    const CONF_DESCRIPTION = 'SUDEMI_PROFILE_DESCRIPTION';
    const CONF_SUPPORT_EMAIL = 'SUDEMI_PROFILE_SUPPORT_EMAIL';
    const CONF_RETURNS_DAYS = 'SUDEMI_PROFILE_RETURNS_DAYS';

    // ----------------------------------------------------------------- calls ----

    /** @return array{ok:bool,stats?:array,error?:string} */
    public static function stats($days)
    {
        list($from, $to) = self::period($days, gmdate('Y-m-d'));

        return self::get('/integration/v1/stats?' . http_build_query(['from' => $from, 'to' => $to]), 'stats');
    }

    /** @return array{ok:bool,listings?:array,error?:string} */
    public static function listings($status, $q, $page)
    {
        $query = [
            'status' => in_array($status, ['listed', 'not_listed'], true) ? $status : 'all',
            'page' => max(0, (int) $page),
            'size' => self::PAGE_SIZE,
        ];
        $q = SudemiManage::text($q, 100);
        if ($q !== '') {
            $query['q'] = $q;
        }

        return self::get('/integration/v1/listings?' . http_build_query($query), 'listings');
    }

    /** @return array{ok:bool,result?:array,error?:string} */
    public static function setEnabled($externalId, $enabled)
    {
        $id = SudemiManage::text($externalId, 100);
        if ($id === '' || !preg_match('/^[0-9A-Za-z_.:-]+$/', $id)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $response = SudemiClient::sendSigned('POST', '/integration/v1/listings/' . rawurlencode($id) . '/enabled', ['enabled' => (bool) $enabled]);
        if (is_array($response) && (int) $response['status'] === 200 && is_array($response['body'])) {
            return ['ok' => true, 'result' => $response['body']];
        }

        return ['ok' => false, 'error' => SudemiManage::error($response)];
    }

    /** @return array{ok:bool,shop?:array,error?:string} */
    public static function shop()
    {
        return self::get('/integration/v1/shop', 'shop');
    }

    /**
     * Saves the profile on sudemi and keeps it here, so the next catalogue sync sends the same.
     *
     * @return array{ok:bool,error?:string,message?:string}
     */
    public static function saveProfile(array $profile)
    {
        $problem = self::profileProblem($profile);
        if ($problem !== '') {
            return ['ok' => false, 'error' => $problem];
        }
        $body = [
            'description' => $profile['description'],
            'supportEmail' => $profile['support_email'],
            'returnsWindowDays' => (int) $profile['returns_window_days'],
        ];
        $response = SudemiClient::sendSigned('POST', '/integration/v1/shop', $body);
        if (!is_array($response) || (int) $response['status'] !== 200) {
            return [
                'ok' => false,
                'error' => SudemiManage::error($response),
                'message' => is_array($response) && isset($response['body']['message']) ? (string) $response['body']['message'] : '',
            ];
        }
        // Kept encoded: Configuration::updateValue strips anything that looks like HTML, and the
        // description is plain text that may contain "<" or "&" as typed.
        Configuration::updateValue(self::CONF_DESCRIPTION, $profile['description'] === '' ? '' : 'b64:' . base64_encode($profile['description']));
        Configuration::updateValue(self::CONF_SUPPORT_EMAIL, $profile['support_email']);
        Configuration::updateValue(self::CONF_RETURNS_DAYS, (int) $profile['returns_window_days']);

        return ['ok' => true];
    }

    /**
     * What the catalogue sync sends as the shop's profile: the values saved on the Shop profile
     * page; without them the shop's e-mail only (never the shop name as a description).
     */
    public static function profileForSync()
    {
        $out = [];
        $description = self::storedDescription((string) Configuration::get(self::CONF_DESCRIPTION));
        if ($description !== '') {
            $out['description'] = $description;
        }
        $email = (string) Configuration::get(self::CONF_SUPPORT_EMAIL);
        $out['supportEmail'] = $email !== '' ? $email : (string) Configuration::get('PS_SHOP_EMAIL');
        $days = (int) Configuration::get(self::CONF_RETURNS_DAYS);
        if ($days > 0) {
            $out['returnsWindowDays'] = $days;
        }

        return $out;
    }

    /** The description as saved by saveProfile ("b64:" + base64), or as stored before. Pure. */
    public static function storedDescription($stored)
    {
        if (strpos($stored, 'b64:') === 0) {
            $decoded = base64_decode(substr($stored, 4), true);

            return $decoded === false ? '' : $decoded;
        }

        return $stored;
    }

    private static function get($path, $key)
    {
        $response = SudemiClient::sendSigned('GET', $path);
        if (is_array($response) && (int) $response['status'] === 200 && is_array($response['body'])) {
            return ['ok' => true, $key => $response['body']];
        }

        return ['ok' => false, 'error' => SudemiManage::error($response)];
    }

    // ------------------------------------------------------------------ pure ----

    /** [from, to] for the last $days days up to $today (inclusive), $days one of PERIODS. */
    public static function period($days, $today)
    {
        $days = in_array((int) $days, self::PERIODS, true) ? (int) $days : 30;
        $to = new DateTimeImmutable($today, new DateTimeZone('UTC'));

        return [$to->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /** '' when the profile can be saved, else the error key (shown in the page's language). */
    public static function profileProblem(array $profile)
    {
        $description = isset($profile['description']) ? (string) $profile['description'] : '';
        if (self::length($description) > 2000) {
            return 'profile_bad_description';
        }
        $email = isset($profile['support_email']) ? (string) $profile['support_email'] : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return 'profile_bad_email';
        }
        $days = isset($profile['returns_window_days']) ? $profile['returns_window_days'] : '';
        if (!preg_match('/^\d{1,3}$/', (string) $days) || (int) $days < 14 || (int) $days > 365) {
            return 'profile_bad_days';
        }

        return '';
    }

    /** Orders x 10000 / views as a percentage with one decimal ("6.4 %"), or "-" without views. */
    public static function conversion($bps)
    {
        return number_format(((int) $bps) / 100, 1, '.', '') . ' %';
    }

    /** A whole number with thin grouping ("12 345"), for counts. */
    public static function count($n)
    {
        return number_format((int) $n, 0, '.', ' ');
    }

    /** The known reasons of a listing; unknown values become 'other'. */
    public static function reasons($row)
    {
        $out = [];
        foreach (isset($row['not_listed_reasons']) && is_array($row['not_listed_reasons']) ? $row['not_listed_reasons'] : [] as $reason) {
            $key = in_array($reason, self::REASONS, true) ? $reason : 'other';
            if (!in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        if (empty($out) && isset($row['listed']) && !$row['listed']) {
            $out[] = 'other';
        }

        return $out;
    }

    /**
     * A daily bar chart as SVG: one bar per day for $barKey, a line for $lineKey (both counts,
     * on their own scale each), the first and last day under the axis. Labels are escaped here.
     *
     * @param array[] $daily rows with 'day' and the keys
     */
    public static function chart(array $daily, $barKey, $lineKey, $barLabel, $lineLabel, $rtl = false)
    {
        $w = 720;
        $h = 220;
        $left = 44;
        $right = 44;
        $top = 16;
        $bottom = 30;
        $n = max(1, count($daily));
        $plotW = $w - $left - $right;
        $plotH = $h - $top - $bottom;
        $barMax = 0;
        $lineMax = 0;
        foreach ($daily as $row) {
            $barMax = max($barMax, (int) (isset($row[$barKey]) ? $row[$barKey] : 0));
            $lineMax = max($lineMax, (int) (isset($row[$lineKey]) ? $row[$lineKey] : 0));
        }
        $barTop = self::niceMax($barMax);
        $lineTop = self::niceMax($lineMax);
        $slot = $plotW / $n;
        $barW = max(1.0, $slot * 0.62);
        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        $svg = '<svg class="sudemi-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . $e($barLabel . ', ' . $lineLabel) . '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">';
        // Grid: 4 lines with the bar scale on the left and the line scale on the right.
        for ($i = 0; $i <= 4; ++$i) {
            $y = $top + $plotH - $plotH * $i / 4;
            $svg .= '<line x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" class="sudemi-chart-grid"/>';
            $svg .= '<text x="' . ($left - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end" class="sudemi-chart-axis">' . $e(self::count($barTop * $i / 4)) . '</text>';
            $svg .= '<text x="' . ($w - $right + 6) . '" y="' . round($y + 4, 1) . '" text-anchor="start" class="sudemi-chart-axis sudemi-chart-axis-line">' . $e(self::count($lineTop * $i / 4)) . '</text>';
        }
        $points = [];
        foreach (array_values($daily) as $i => $row) {
            $index = $rtl ? $n - 1 - $i : $i;
            $x = $left + $slot * $index + ($slot - $barW) / 2;
            $value = (int) (isset($row[$barKey]) ? $row[$barKey] : 0);
            $bh = $barTop > 0 ? $plotH * $value / $barTop : 0;
            $title = $e((isset($row['day']) ? $row['day'] : '') . ': ' . $barLabel . ' ' . self::count($value)
                . ', ' . $lineLabel . ' ' . self::count(isset($row[$lineKey]) ? $row[$lineKey] : 0));
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($top + $plotH - $bh, 1) . '" width="' . round($barW, 1) . '" height="' . round($bh, 1) . '" rx="2" class="sudemi-chart-bar"><title>' . $title . '</title></rect>';
            $lv = (int) (isset($row[$lineKey]) ? $row[$lineKey] : 0);
            $points[] = round($left + $slot * $index + $slot / 2, 1) . ',' . round($top + $plotH - ($lineTop > 0 ? $plotH * $lv / $lineTop : 0), 1);
        }
        if ($rtl) {
            $points = array_reverse($points);
        }
        if (count($points) > 1) {
            $svg .= '<polyline points="' . implode(' ', $points) . '" class="sudemi-chart-line"/>';
        }
        if (!empty($daily)) {
            $first = reset($daily);
            $last = end($daily);
            $startLabel = $e(isset($first['day']) ? $first['day'] : '');
            $endLabel = $e(isset($last['day']) ? $last['day'] : '');
            $svg .= '<text x="' . $left . '" y="' . ($h - 8) . '" text-anchor="start" class="sudemi-chart-axis">' . ($rtl ? $endLabel : $startLabel) . '</text>';
            $svg .= '<text x="' . ($w - $right) . '" y="' . ($h - 8) . '" text-anchor="end" class="sudemi-chart-axis">' . ($rtl ? $startLabel : $endLabel) . '</text>';
        }

        return $svg . '</svg>';
    }

    /** A round axis top at or above $max: 1, 2, 5 x 10^n (4 at least, so 4 grid steps stay whole). */
    public static function niceMax($max)
    {
        if ($max <= 4) {
            return 4;
        }
        $power = pow(10, floor(log10($max)));
        foreach ([1, 2, 5, 10] as $step) {
            if ($step * $power >= $max) {
                $top = $step * $power;

                return (int) (fmod($top, 4) === 0.0 ? $top : ceil($top / 4) * 4);
            }
        }

        return (int) (10 * $power);
    }

    private static function length($s)
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
