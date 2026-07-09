<?php
/**
 * Log the player out and send them back to the login (register) screen.
 *
 * Required by bdapps guideline #14: a functional Logout that clears the
 * session and redirects to the login page — no exceptions.
 */

// Ensure sessions persist on shared hosting (cPanel)
if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/sessions');
    if (!is_dir(__DIR__ . '/sessions')) {
        @mkdir(__DIR__ . '/sessions', 0755, true);
    }
}
session_start();

// Clear all session data and the session cookie.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: /#register');
exit;
