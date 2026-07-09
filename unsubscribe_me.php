<?php
/**
 * Session-gated front door to bdapps/unsubscribe.php.
 *
 * ┌─ Why this file exists ────────────────────────────────────────────────────┐
 * │ The vendor script takes the number to cancel from the POST body, behind   │
 * │ wildcard CORS. bdapps will happily unsubscribe whatever subscriberId it   │
 * │ is handed — so, reachable directly, that endpoint lets anyone on the      │
 * │ internet cancel any subscriber's subscription, cross-origin.              │
 * │                                                                          │
 * │ bdapps/ is kept exactly as shipped, so: .htaccess denies direct web       │
 * │ access to bdapps/unsubscribe.php, and this wrapper is the only way in.    │
 * │ The only number it will ever cancel is $_SESSION['phone'] — the one this  │
 * │ browser proved it owned via OTP.                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Input  (POST): none — the number comes from the session
 * Output (JSON): { success, subscriptionStatus, ... } from unsubscribe.php
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

if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    session_write_close();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'লগইন করা নেই।']);
    exit;
}

// A GET (or a cross-site <img>/link) must never cancel a subscription.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

// Close the session before handing off: the vendor script calls session_start()
// itself, and starting an already-active session raises a notice.
session_write_close();

// Feed the vendor script the number as if the browser had sent it.
$_POST['user_mobile'] = $phone;

quiet_vendor_diagnostics();

ob_start();
require __DIR__ . '/bdapps/unsubscribe.php';
$body = ob_get_clean();

drop_vendor_cors();
header('Content-Type: application/json; charset=utf-8');

$json = json_body($body, ['success' => false, 'error' => 'upstream_error']);

// Only now, and only if bdapps actually cancelled it, end the session — leaving
// them holding a login for a subscription that no longer exists would be wrong,
// but logging them out after a FAILED unsubscribe would be worse: they would be
// kicked out while still being charged, with no obvious way back in.
// The vendor script reopened the session, so it is active again here.
$decoded = json_decode($json, true);
if (is_array($decoded) && !empty($decoded['success'])) {
    $_SESSION = [];
    session_destroy();
}

echo $json;
