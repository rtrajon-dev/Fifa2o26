<?php
/**
 * Ask bdapps to SMS a 6-digit OTP to the user's number.
 *
 * The number is bound to the returned referenceNo IN THE SESSION. verify_otp.php
 * reads the number from there rather than from the browser — that binding is what
 * stops someone verifying an OTP for their own phone and then registering as a
 * different number.
 *
 * (No Access-Control-Allow-Origin here, unlike the stock bdapps sample. This is a
 * same-origin, session-bearing endpoint; wildcard CORS on it buys nothing and
 * invites abuse.)
 *
 * Input  (POST): user_mobile
 * Output (JSON): { success, referenceNo, statusCode, statusDetail }
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

$rawMobile = $_POST['user_mobile'] ?? '';
$digits = preg_replace('/\D+/', '', $rawMobile);

// Accept 018xxxxxxxx, 88018xxxxxxxx, or 8818xxxxxxxx and normalize to 018xxxxxxxx
if (strpos($digits, '880') === 0 && strlen($digits) === 13) {
    $digits = '0' . substr($digits, 3);
} elseif (strpos($digits, '88') === 0 && strlen($digits) === 12) {
    $digits = '0' . substr($digits, 2);
}

if (!preg_match('/^01[3-9][0-9]{8}$/', $digits)) {
    echo json_encode([
        'success'     => false,
        'message'     => 'Invalid mobile number format',
        'referenceNo' => null,
    ]);
    exit;
}

$subscriberId = 'tel:88' . $digits;

$config = require __DIR__ . '/../config.php';
$requestData = [
    'applicationId'   => $config['bdapps']['app_id'],
    'password'        => $config['bdapps']['password'],
    'subscriberId'    => $subscriberId,
    'applicationHash' => $config['bdapps']['app_hash'],
    'applicationMetaData' => [
        'client' => 'WEBAPP',
        'device' => 'Browser',
        'os'     => 'web',
    ],
];
$requestJson = json_encode($requestData);

$ch = curl_init($config['endpoints']['otp_request']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($requestJson),
]);

$responseJson = curl_exec($ch);
if ($responseJson === false) {
    echo json_encode(['success' => false, 'message' => 'cURL error: ' . curl_error($ch), 'referenceNo' => null]);
    exit;
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (PHP_VERSION_ID < 80000) {
    curl_close($ch);
}

if (stripos($responseJson, '<html') !== false || stripos($responseJson, '<!DOCTYPE') !== false) {
    echo json_encode([
        'success'     => false,
        'message'     => 'Server returned HTML instead of JSON. HTTP code: ' . $httpCode,
        'referenceNo' => null,
    ]);
    exit;
}

$response = json_decode($responseJson, true);
if (!is_array($response)) {
    echo json_encode([
        'success'     => false,
        'message'     => 'Invalid JSON in response',
        'referenceNo' => null,
        'httpCode'    => $httpCode,
    ]);
    exit;
}

$referenceNo  = isset($response['referenceNo']) ? trim((string) $response['referenceNo']) : '';
$statusCode   = (string) ($response['statusCode'] ?? '');
$statusDetail = (string) ($response['statusDetail'] ?? '');

if ($referenceNo !== '') {
    // Bind referenceNo → the number we actually texted.
    $pending = $_SESSION['otp_pending'] ?? [];
    $pending[$referenceNo] = ['phone' => $digits, 'sent_at' => time()];
    if (count($pending) > 5) {                       // keep only live attempts
        $pending = array_slice($pending, -5, null, true);
    }
    $_SESSION['otp_pending'] = $pending;

    echo json_encode([
        'success'      => true,
        'referenceNo'  => $referenceNo,
        'statusCode'   => $statusCode,
        'statusDetail' => $statusDetail,
    ]);
    exit;
}

echo json_encode([
    'success'      => false,
    'message'      => $statusDetail !== '' ? $statusDetail : 'OTP reference not returned',
    'referenceNo'  => null,
    'statusCode'   => $statusCode,
    'statusDetail' => $statusDetail,
]);
