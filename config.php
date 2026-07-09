<?php
/**
 * Central configuration loader for Fifa2026.
 *
 * Reads goaljeto/.env once and exposes values via the env() helper, then
 * returns a structured config array. Every file (the bdapps scripts, the
 * prediction backend, etc.) should `require` this instead of hardcoding
 * credentials.
 *
 * Usage:
 *   $config = require __DIR__ . '/../config.php';   // from inside a subfolder
 *   $appId  = $config['bdapps']['app_id'];
 */

if (!function_exists('env')) {
    /**
     * Read a key from goaljeto/.env (parsed once and cached).
     */
    function env($key, $default = null)
    {
        static $vars = null;

        if ($vars === null) {
            $vars = [];
            $path = __DIR__ . '/.env';
            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                        continue;
                    }
                    list($k, $v) = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v);
                    // strip optional surrounding quotes
                    if (strlen($v) >= 2 &&
                        ($v[0] === '"' || $v[0] === "'") &&
                        substr($v, -1) === $v[0]) {
                        $v = substr($v, 1, -1);
                    }
                    $vars[$k] = $v;
                }
            }
        }

        return array_key_exists($key, $vars) ? $vars[$key] : $default;
    }
}

// Kickoff times in data/schedule.php are written in local Bangladesh time, and
// prediction locking compares them against time(). Pin the timezone here, in the
// one file everything requires, so a server set to UTC cannot shift every
// kickoff by six hours and silently open or close predictions at the wrong time.
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Dhaka'));

return [
    'bdapps' => [
        'app_id'   => env('BDAPPS_APP_ID', ''),
        'password' => env('BDAPPS_PASSWORD', ''),
        'app_name' => env('BDAPPS_APP_NAME', 'Fifa2026'),
        // applicationHash for the OTP request. If unset, falls back to app_name
        // so nothing breaks; set BDAPPS_APP_HASH to the real hash from bdapps.
        'app_hash' => env('BDAPPS_APP_HASH') ?: env('BDAPPS_APP_NAME', 'Fifa2026'),
        'verify_ssl' => filter_var(env('BDAPPS_VERIFY_SSL', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],

    'endpoints' => [
        'otp_request' => env('BDAPPS_OTP_REQUEST_URL', 'https://developer.bdapps.com/subscription/otp/request'),
        'otp_verify'  => env('BDAPPS_OTP_VERIFY_URL',  'https://developer.bdapps.com/subscription/otp/verify'),
        'sub_send'    => env('BDAPPS_SUBSCRIPTION_URL', 'https://developer.bdapps.com/subscription/send'),
        'sub_status'  => env('BDAPPS_SUBSCRIPTION_STATUS_URL', 'https://developer.bdapps.com/subscription/getstatus'),
        'sms_send'    => env('BDAPPS_SMS_URL', 'https://developer.bdapps.com/sms/send'),
        'charging'    => env('BDAPPS_CHARGING_URL', ''),
        'ussd'        => env('BDAPPS_USSD_URL', ''),
    ],

    // MySQL connection (cPanel). Import database/goaljeto.sql once, then set
    // these in .env. On cPanel the host is almost always 'localhost'.
    'db' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'goaljeto'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
    ],

    // Shared secret guarding sync_matches.php and settle.php. Anyone holding it
    // can enter match results and therefore decide who wins — keep it secret and
    // long. Blank disables the web entrypoints entirely (CLI still works).
    'admin_token' => env('ADMIN_TOKEN', ''),

    // Kickoff times in data/schedule.php are written in this timezone.
    'timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),
];
