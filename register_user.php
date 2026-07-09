<?php
/**
 * Set up the player's session after OTP verification, and record them for the
 * leaderboard (hashed phone + masked form via upsert_user — the raw number is
 * never stored).
 *
 * The phone number is taken from $_SESSION['verified_phone'], which only
 * bdapps/verify_otp.php can set and only after bdapps confirmed the OTP for the
 * number bdapps/send_otp.php actually texted. It is deliberately NOT read from
 * the POST body: doing so would let anyone POST a stranger's number here and be
 * logged in as them without ever seeing an OTP.
 *
 * Input  (POST): display_name (optional)
 * Output (JSON): { "ok": true, "display_name": "<name or masked number>" }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

// Ensure sessions persist on shared hosting (cPanel)
if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/sessions');
    if (!is_dir(__DIR__ . '/sessions')) {
        @mkdir(__DIR__ . '/sessions', 0755, true);
    }
}
session_start();

$phone = $_SESSION['verified_phone'] ?? '';

if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    http_response_code(403);
    echo json_encode(['error' => 'OTP যাচাই করা হয়নি। আবার শুরু করুন।']);
    exit;
}

$name = isset($_POST['display_name']) ? trim($_POST['display_name']) : '';
$name = mb_substr($name, 0, 30);

// display: chosen name, else a masked number for privacy (017•••678)
$display = $name !== ''
    ? $name
    : substr($phone, 0, 3) . '•••' . substr($phone, -3);

$_SESSION['phone']        = $phone;
$_SESSION['display_name'] = $name;     // raw (may be empty)
$_SESSION['display']      = $display;  // what to greet/show

// The verified number has been consumed — a fresh OTP is needed for a new login.
unset($_SESSION['verified_phone']);

// Record the player (hashed phone + masked form) so they can appear on the
// leaderboard, and so predictions have a user_id to hang off.
try {
    $uid = upsert_user(db(), $phone, $name);
    if ($uid) {
        $_SESSION['user_id'] = $uid;
    }
} catch (Throwable $e) {
    // ignore — session login still succeeds
}

echo json_encode(['ok' => true, 'display_name' => $display]);
