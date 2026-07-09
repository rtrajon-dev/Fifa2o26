<?php
/**
 * Login for already-subscribed users (bdapps model: login = subscription
 * status check).
 *
 * Verifies the number's subscription status with bdapps SERVER-SIDE, and only
 * creates the session if bdapps returns REGISTERED.
 *
 * ┌─ SECURITY NOTE — read before shipping ────────────────────────────────────┐
 * │ This endpoint proves the number is SUBSCRIBED. It does not prove the      │
 * │ caller OWNS it. Anyone who types a subscribed player's number is logged   │
 * │ in as that player. That is inherent to "login = getStatus", which is how  │
 * │ the bdapps checklist describes it, and it is what QuizJeeto does.         │
 * │                                                                          │
 * │ Acceptable while prizes are display-only. The moment a real data pack is  │
 * │ awarded to a leaderboard position, close this: require an OTP on every    │
 * │ login and delete this shortcut, or bind sessions to a device cookie set   │
 * │ at first OTP.                                                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Input  (POST): user_mobile
 * Output (JSON): { ok: true, subscribed: true|false, subscriptionStatus }
 *   - subscribed:true  → session created, caller redirects into the app
 *   - subscribed:false → caller runs the subscribe + OTP flow instead
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/sessions');
    if (!is_dir(__DIR__ . '/sessions')) {
        @mkdir(__DIR__ . '/sessions', 0755, true);
    }
}
session_start();

$digits = preg_replace('/\D+/', '', $_POST['user_mobile'] ?? '');
if (strpos($digits, '880') === 0 && strlen($digits) === 13) {
    $digits = '0' . substr($digits, 3);
} elseif (strpos($digits, '88') === 0 && strlen($digits) === 12) {
    $digits = '0' . substr($digits, 2);
}

if (!preg_match('/^01[3-9][0-9]{8}$/', $digits)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'সঠিক মোবাইল নম্বর দিন']);
    exit;
}

$subscriberId = 'tel:88' . $digits;
$config = require __DIR__ . '/config.php';

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
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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
    echo json_encode(['ok' => false, 'error' => 'সংযোগ সমস্যা: ' . $curlError]);
    exit;
}

$response = json_decode($responseJson, true);
if (!is_array($response)) {
    echo json_encode(['ok' => false, 'error' => 'সার্ভার সাড়া দেয়নি']);
    exit;
}

$status       = strtoupper(trim($response['subscriptionStatus'] ?? ''));
$isSubscribed = ($status === 'REGISTERED');

if ($isSubscribed) {
    $_SESSION['phone']        = $digits;
    $_SESSION['display_name'] = $_SESSION['display_name'] ?? '';
    $_SESSION['display']      = $_SESSION['display']
        ?? (substr($digits, 0, 3) . '•••' . substr($digits, -3));

    // Make sure a users row exists so predictions have something to reference.
    try {
        $uid = upsert_user(db(), $digits, $_SESSION['display_name']);
        if ($uid) {
            $_SESSION['user_id'] = $uid;
        }
    } catch (Throwable $e) {
        // ignore — session login still succeeds
    }
}

echo json_encode([
    'ok'                 => true,
    'subscribed'         => $isSubscribed,
    'subscriptionStatus' => $status,
]);
