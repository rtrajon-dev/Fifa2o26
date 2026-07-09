<?php
/**
 * A small file-backed rate limiter, used by otp_send.php to stop the OTP
 * endpoint from being turned into an SMS cannon.
 *
 * Why not the session? Because the attacker controls their own session — they
 * just drop the cookie and start again. The only durable keys are the phone
 * number being texted and the caller's IP, so the counters have to live on disk
 * and outlive any one session.
 *
 * Counters go under data/otp_rate/, which data/.htaccess already denies to the
 * web. Keys are hashed, so a leaked directory listing does not leak phone
 * numbers. Each file is a JSON array of unix timestamps within the window.
 *
 * Not distributed-safe, but this runs on one cPanel box. flock() is enough.
 */

// For bn(). db.php opens no connection at file scope — it only pins the timezone.
require_once __DIR__ . '/db.php';

/** Where the counters live. Under data/, which is denied by .htaccess. */
function rate_dir(): string
{
    $dir = __DIR__ . '/data/otp_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * Record a hit against $key and report whether it is allowed.
 *
 * Prunes timestamps older than $window, then applies two limits:
 *   - $cooldown seconds must have passed since the previous hit
 *   - at most $max hits inside the trailing $window seconds
 *
 * A rejected call is NOT recorded — otherwise an attacker hammering the endpoint
 * would keep pushing their own window forward and lock the number out for good.
 *
 * @return array{ok:bool, retry_after:int}  retry_after is seconds until allowed.
 */
function rate_hit(string $key, int $max, int $window, int $cooldown = 0): array
{
    $file = rate_dir() . '/' . hash('sha256', $key) . '.json';
    $now  = time();

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        // Cannot write the counter (permissions, full disk). Fail OPEN: a login
        // page that nobody can use is a worse outage than an unmetered one.
        error_log("rate_limit: cannot open $file — allowing request");
        return ['ok' => true, 'retry_after' => 0];
    }

    try {
        flock($fh, LOCK_EX);

        $raw  = stream_get_contents($fh);
        $hits = json_decode($raw ?: '[]', true);
        if (!is_array($hits)) {
            $hits = [];
        }

        // Drop anything that has aged out of the window.
        $hits = array_values(array_filter($hits, fn($t) => is_int($t) && ($now - $t) < $window));

        $last = $hits ? max($hits) : 0;
        if ($cooldown > 0 && $last && ($now - $last) < $cooldown) {
            return ['ok' => false, 'retry_after' => $cooldown - ($now - $last)];
        }
        if (count($hits) >= $max) {
            return ['ok' => false, 'retry_after' => max(1, $window - ($now - min($hits)))];
        }

        $hits[] = $now;

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
        fflush($fh);

        return ['ok' => true, 'retry_after' => 0];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Bengali "try again in N seconds/minutes", for the rejection message. */
function retry_after_bn(int $seconds): string
{
    if ($seconds < 60) {
        return bn($seconds) . ' সেকেন্ড পর আবার চেষ্টা করুন।';
    }
    return bn((int) ceil($seconds / 60)) . ' মিনিট পর আবার চেষ্টা করুন।';
}

/**
 * Normalize a Bangladeshi mobile to 01XXXXXXXXX, or '' if it is not one.
 * Mirrors the normalization the bdapps scripts do internally, so the limiter
 * counts the same number the SMS would actually go to (0181…, 88181…, 88018…).
 */
function normalize_msisdn(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if (strpos($digits, '880') === 0 && strlen($digits) === 13) {
        $digits = '0' . substr($digits, 3);
    } elseif (strpos($digits, '88') === 0 && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    }
    return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : '';
}
