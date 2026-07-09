<?php
/**
 * Verify the 6-digit OTP with bdapps.
 *
 * On success this records the VERIFIED phone number in the session, looked up
 * from the referenceNo → number binding that send_otp.php wrote. register_user.php
 * will only ever trust this value. The browser cannot influence which number
 * gets registered; it only supplies the OTP and the reference.
 *
 * Input  (POST): Otp, referenceNo
 * Output (JSON): { statusCode, statusDetail, subscriptionStatus, subscriberId }
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

const OTP_TTL = 600;   // a reference is good for 10 minutes

$userOtp     = isset($_POST['Otp'])         ? trim($_POST['Otp'])         : '';
$referenceNo = isset($_POST['referenceNo']) ? trim($_POST['referenceNo']) : '';

if ($userOtp === '' || $referenceNo === '') {
    echo json_encode([
        'statusCode'   => 'FAILED',
        'statusDetail' => 'OTP and reference number are required',
    ]);
    exit;
}

// The reference must be one WE issued, in THIS session, recently.
$pending = $_SESSION['otp_pending'][$referenceNo] ?? null;
if (!$pending) {
    echo json_encode([
        'statusCode'   => 'FAILED',
        'statusDetail' => 'সেশন মেয়াদোত্তীর্ণ। আবার OTP নিন।',
    ]);
    exit;
}
if (time() - $pending['sent_at'] > OTP_TTL) {
    unset($_SESSION['otp_pending'][$referenceNo]);
    echo json_encode([
        'statusCode'   => 'FAILED',
        'statusDetail' => 'OTP-এর মেয়াদ শেষ। আবার চেষ্টা করুন।',
    ]);
    exit;
}

$config = require __DIR__ . '/../config.php';
$requestData = [
    'applicationId' => $config['bdapps']['app_id'],
    'password'      => $config['bdapps']['password'],
    'referenceNo'   => $referenceNo,
    'otp'           => $userOtp,
];
$requestJson = json_encode($requestData);

$ch = curl_init($config['endpoints']['otp_verify']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($requestJson),
]);

$responseJson = curl_exec($ch);
if ($responseJson === false) {
    $err = curl_error($ch);
    echo json_encode(['statusCode' => 'FAILED', 'statusDetail' => 'Unable to reach bdapps: ' . $err]);
    exit;
}
if (PHP_VERSION_ID < 80000) {
    curl_close($ch);
}

$response = json_decode($responseJson, true);
if (!is_array($response)) {
    echo json_encode(['statusCode' => 'FAILED', 'statusDetail' => 'Failed to parse bdapps response']);
    exit;
}

$statusCode         = (string) ($response['statusCode'] ?? 'FAILED');
$subscriptionStatus = (string) ($response['subscriptionStatus'] ?? '');

// bdapps signals a good OTP with S1000 and a subscription status. Only then do we
// promote the bound number to "verified" — this is the gate register_user.php checks.
if ($statusCode === 'S1000' && $subscriptionStatus !== '') {
    $_SESSION['verified_phone'] = $pending['phone'];
    unset($_SESSION['otp_pending'][$referenceNo]);
}

echo json_encode([
    'statusCode'         => $statusCode,
    'statusDetail'       => (string) ($response['statusDetail'] ?? ''),
    'subscriptionStatus' => $subscriptionStatus,
    'subscriberId'       => (string) ($response['subscriberId'] ?? ''),
]);
