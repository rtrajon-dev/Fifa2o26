<?php
/**
 * Session-gated front door to bdapps/check_subscription.php.
 *
 * The vendor script reads the number from $_POST and answers with wildcard CORS,
 * which makes it a public oracle: anyone, from any origin, could ask whether any
 * Bangladeshi number is subscribed to this app. bdapps/ is kept as shipped, so
 * the gate lives here and .htaccess denies direct web access to the raw file.
 *
 * The only number a player has any business querying is their own, so that is
 * the only one we ever pass through — taken from the session, never the request.
 *
 * Input  (POST): none
 * Output (JSON): { isSubscribed, subscriptionStatus, ... } from check_subscription.php
 */

require_once __DIR__ . '/bdapps_bridge.php';

// Ensure sessions persist on shared hosting (cPanel)
if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/sessions');
    if (!is_dir(__DIR__ . '/sessions')) {
        @mkdir(__DIR__ . '/sessions', 0755, true);
    }
}
session_start();

$phone = $_SESSION['phone'] ?? '';

// Close the session before handing off: the vendor script calls session_start()
// itself, and starting an already-active session raises a notice that would
// corrupt the JSON body.
session_write_close();

if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

// Feed the vendor script the session's number as if the browser had sent it.
$_POST['user_mobile'] = $phone;

// The vendor script calls curl_close(), deprecated since PHP 8.5. With
// display_errors on, that notice prints straight into the response and the body
// stops being valid JSON. Send diagnostics to the log, where they belong.
quiet_vendor_diagnostics();

// Buffer the response so the wildcard-CORS headers the vendor file sets are
// still removable — headers are not committed until the first byte is flushed.
ob_start();
require __DIR__ . '/bdapps/check_subscription.php';
$body = ob_get_clean();

drop_vendor_cors();
header('Content-Type: application/json; charset=utf-8');

echo json_body($body, ['error' => 'upstream_error']);
