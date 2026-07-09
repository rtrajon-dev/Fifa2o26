<?php
/**
 * Set up the player's session after OTP verification, and record them for the
 * leaderboard (hashed phone + masked form via upsert_user — the raw number is
 * never stored).
 *
 * The phone number is taken from the SESSION, never from the POST body:
 *
 *   $_SESSION['verified_phone']  set only by bdapps/verify_otp.php, and only after
 *                                bdapps confirmed the OTP for the number that
 *                                bdapps/send_otp.php actually texted.
 *   $_SESSION['phone']           set below, i.e. that same number, once promoted.
 *
 * Accepting either lets the browser call this twice in one flow — once straight
 * after OTP to establish the session, then again if the player types a display
 * name — without a second OTP. Both values originate from a verified OTP, so
 * neither lets a caller register a number they do not control.
 *
 * Input  (POST): display_name (optional)
 * Output (JSON): { ok, display_name, returning }
 *   returning:true → this phone already had a users row before this call, i.e.
 *                    a login rather than a first-time registration. The caller
 *                    uses it to skip the "choose a name" step.
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

// A freshly verified number, or the one this session already proved it owns.
$phone = $_SESSION['verified_phone'] ?? $_SESSION['phone'] ?? '';

if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    http_response_code(403);
    echo json_encode(['error' => 'OTP যাচাই করা হয়নি। আবার শুরু করুন।']);
    exit;
}

$name = isset($_POST['display_name']) ? trim($_POST['display_name']) : '';
$name = mb_substr($name, 0, 30);

$_SESSION['phone'] = $phone;

// The verified number has been consumed — a fresh OTP is needed for a new login.
unset($_SESSION['verified_phone']);

// Record the player (hashed phone + masked form) so they can appear on the
// leaderboard, and so predictions have a user_id to hang off.
$returning = false;
$stored    = '';
try {
    $pdo = db();

    // Did this player exist before we upserted? That is what distinguishes a
    // login from a registration — and it carries their previously chosen name.
    $sel = $pdo->prepare('SELECT display_name FROM users WHERE phone_hash = ?');
    $sel->execute([hash('sha256', $phone)]);
    $row = $sel->fetch();
    if ($row !== false) {
        $returning = true;
        $stored    = (string) ($row['display_name'] ?? '');
    }

    // upsert_user only overwrites display_name when a non-empty one is supplied,
    // so calling this with no name never wipes a returning player's name.
    $uid = upsert_user($pdo, $phone, $name);
    if ($uid) {
        $_SESSION['user_id'] = $uid;
    }
} catch (Throwable $e) {
    // A DB hiccup must not cost the player their verified session.
    error_log('GoalJeeto register_user.php: ' . $e->getMessage());
}

// What to greet them with: the name they just typed, else the one already on
// file, else a masked number for privacy (017•••678).
$effectiveName = $name !== '' ? $name : $stored;
$display = $effectiveName !== ''
    ? $effectiveName
    : substr($phone, 0, 3) . '•••' . substr($phone, -3);

$_SESSION['display_name'] = $effectiveName;   // raw (may be empty)
$_SESSION['display']      = $display;         // what to greet/show

echo json_encode([
    'ok'           => true,
    'display_name' => $display,
    'returning'    => $returning,
]);
