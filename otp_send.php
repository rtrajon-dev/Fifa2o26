<?php
/**
 * Rate-limited front door to bdapps/send_otp.php.
 *
 * bdapps/ is vendor code and is kept byte-for-byte as shipped, so the throttle
 * lives out here instead. The browser calls THIS file; .htaccess denies direct
 * web access to bdapps/send_otp.php, so there is no way around the limiter.
 * require() is a filesystem read, not an HTTP request, so the deny does not
 * affect the hand-off below.
 *
 * Two independent keys, because they stop two different attacks:
 *   phone → you cannot bomb one victim's handset with repeated OTPs
 *   ip    → you cannot walk the whole 01XXXXXXXXX space one SMS at a time
 * Each SMS costs money on the bdapps account, so this protects the wallet too.
 *
 * Input  (POST): user_mobile          — passed straight through
 * Output (JSON): whatever send_otp.php returns, or a 429 with a Bengali message.
 */

require_once __DIR__ . '/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');

/** Reject with a 429 and the Bengali "try again in N" message. */
function too_many(int $retryAfter): void
{
    http_response_code(429);
    header('Retry-After: ' . max(1, $retryAfter));
    echo json_encode([
        'success'     => false,
        'message'     => 'অনেকবার চেষ্টা করা হয়েছে। ' . retry_after_bn($retryAfter),
        'referenceNo' => null,
    ]);
    exit;
}

// Only count real, well-formed numbers. A malformed one never reaches bdapps
// anyway (send_otp.php rejects it), so charging it against the limit would let
// an attacker exhaust a victim's quota with garbage input.
$phone = normalize_msisdn($_POST['user_mobile'] ?? '');

if ($phone !== '') {
    $byPhone = rate_hit('otp:phone:' . $phone, 3, 3600, 60);   // 3/hour, 60s apart
    if (!$byPhone['ok']) {
        too_many($byPhone['retry_after']);
    }
}

$ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$byIp  = rate_hit('otp:ip:' . $ip, 10, 3600);                  // 10/hour per caller
if (!$byIp['ok']) {
    too_many($byIp['retry_after']);
}

// Hand off to the untouched bdapps script. It reads $_POST['user_mobile'] itself,
// starts its own session, and echoes the JSON response.
require __DIR__ . '/bdapps/send_otp.php';
