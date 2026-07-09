<?php
/**
 * Unsubscribe the LOGGED-IN player from the bdapps daily subscription.
 *
 * ┌─ Why the number comes from the session, never the POST body ───────────────┐
 * │ bdapps will happily unsubscribe whatever subscriberId we hand it. If this  │
 * │ endpoint read the number from the request, anyone could POST a stranger's  │
 * │ number and cancel their subscription. The stock bdapps sample does exactly │
 * │ that, and adds wildcard CORS so any website can do it cross-origin.        │
 * │                                                                           │
 * │ So: same-origin, session-bearing, and the only number we will ever cancel  │
 * │ is $_SESSION['phone'] — the one this browser proved it owned via OTP.      │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * Input  (POST): none — the number is taken from the session
 * Output (JSON): { success, subscriptionStatus, statusCode, statusDetail }
 */

header('Content-Type: application/json; charset=utf-8');

// Ensure sessions persist on shared hosting (cPanel)
if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/../sessions');
    if (!is_dir(__DIR__ . '/../sessions')) {
        @mkdir(__DIR__ . '/../sessions', 0755, true);
    }
}
session_start();

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// --- gate: must be a logged-in player, unsubscribing themselves ---
$phone = $_SESSION['phone'] ?? '';
if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'লগইন করা নেই।']);
    exit;
}

// Only POST — a GET (or a cross-site image/link) must never cancel a subscription.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$config       = require __DIR__ . '/../config.php';
$subscriberId = 'tel:88' . $phone;

$requestData = [
    'applicationId' => $config['bdapps']['app_id'],
    'password'      => $config['bdapps']['password'],
    'subscriberId'  => $subscriberId,
    'version'       => '1.0',
    'action'        => '0',   // 0 = unsubscribe
];
$requestJson = json_encode($requestData);

$ch = curl_init($config['endpoints']['sub_send']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($requestJson),
]);

$responseJson = curl_exec($ch);
$curlError    = curl_error($ch);
if (PHP_VERSION_ID < 80000) {
    curl_close($ch);
}

if ($responseJson === false) {
    echo json_encode(['success' => false, 'error' => 'সংযোগ সমস্যা: ' . $curlError]);
    exit;
}

$response = json_decode($responseJson, true);
if (!is_array($response)) {
    echo json_encode(['success' => false, 'error' => 'সার্ভার সাড়া দেয়নি']);
    exit;
}

$statusCode         = strtoupper((string) ($response['statusCode'] ?? ''));
$subscriptionStatus = (string) ($response['subscriptionStatus'] ?? 'UNKNOWN');

$success = $statusCode === 'S1000'
        || strtoupper($subscriptionStatus) === 'UNREGISTERED';

// They are no longer a subscriber — end the session rather than leave them
// holding a login that login.php would now refuse to re-issue.
if ($success) {
    $_SESSION = [];
    session_destroy();
}

echo json_encode([
    'success'            => $success,
    'subscriptionStatus' => $subscriptionStatus,
    'statusCode'         => $response['statusCode'] ?? null,
    'statusDetail'       => $response['statusDetail'] ?? null,
]);
