<?php
/**
 * Report the LOGGED-IN player's bdapps subscription status.
 *
 * The number comes from the session, not the request body. Reading it from POST
 * (as the stock bdapps sample does, with wildcard CORS on top) turns this into a
 * public oracle: anyone could ask whether any Bangladeshi number is subscribed to
 * this app. That is a privacy leak with no upside — the only number a player has
 * any business querying is their own.
 *
 * Input  (POST): none — the number is taken from the session
 * Output (JSON): { isSubscribed, subscriptionStatus, statusCode, statusDetail }
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

// --- gate: must be a logged-in player, checking themselves ---
$phone = $_SESSION['phone'] ?? '';
if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    http_response_code(403);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$config       = require __DIR__ . '/../config.php';
$subscriberId = 'tel:88' . $phone;

$requestData = [
    'version'       => '1.0',
    'applicationId' => $config['bdapps']['app_id'],
    'password'      => $config['bdapps']['password'],
    'subscriberId'  => $subscriberId,
];
$requestJson = json_encode($requestData);

$ch = curl_init($config['endpoints']['sub_status']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
    echo json_encode(['error' => 'cURL failed', 'details' => $curlError]);
    exit;
}

$response = json_decode($responseJson, true);
if (!is_array($response)) {
    echo json_encode(['error' => 'Invalid response']);
    exit;
}

// Per the getStatus contract, subscription status is REGISTERED or UNREGISTERED.
$status = strtoupper(trim($response['subscriptionStatus'] ?? ''));

echo json_encode([
    'subscriptionStatus' => $status,
    'isSubscribed'       => ($status === 'REGISTERED'),
    'statusCode'         => $response['statusCode'] ?? null,
    'statusDetail'       => $response['statusDetail'] ?? null,
]);
