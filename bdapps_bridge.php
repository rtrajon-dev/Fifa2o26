<?php
/**
 * Shared helpers for the wrappers that front the vendor bdapps/ scripts.
 *
 * bdapps/ is kept byte-for-byte as shipped. That means we inherit its habits —
 * wildcard CORS headers, a curl_close() that is deprecated on PHP 8.5, error
 * text echoed into the response body. The wrappers (otp_send.php,
 * subscription_status.php, unsubscribe_me.php) buffer the vendor output and run
 * it through here before it reaches the browser.
 */

/**
 * Return $body if it is a JSON object/array; otherwise log it and return an
 * encoded $fallback.
 *
 * A vendor script that prints a PHP notice, an HTML error page, or a raw cURL
 * dump would otherwise hand the browser something its `res.json()` cannot parse,
 * and the UI would show "network problem" for what is really a server fault. We
 * would also be forwarding whatever the notice contained — file paths, the DSN —
 * to whoever asked. Log it, return something well-formed and uninformative.
 *
 * @param string $body     Whatever the vendor script echoed.
 * @param array  $fallback Returned (encoded) when $body is not valid JSON.
 */
function json_body(string $body, array $fallback): string
{
    $trimmed = trim($body);

    if ($trimmed !== '' && is_array(json_decode($trimmed, true))) {
        return $trimmed;
    }

    error_log('bdapps bridge: non-JSON response from vendor script: '
            . substr(preg_replace('/\s+/', ' ', $trimmed), 0, 300));

    return json_encode($fallback);
}

/**
 * Strip the wildcard CORS headers the vendor scripts set unconditionally.
 * Safe only while output is still buffered — headers commit on first flush.
 */
function drop_vendor_cors(): void
{
    header_remove('Access-Control-Allow-Origin');
    header_remove('Access-Control-Allow-Methods');
    header_remove('Access-Control-Allow-Headers');
}

/**
 * Silence the vendor scripts' deprecation notices so they cannot be printed into
 * a JSON body. They still reach the error log.
 */
function quiet_vendor_diagnostics(): void
{
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}
