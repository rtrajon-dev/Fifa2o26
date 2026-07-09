<?php
/**
 * Database helper for GoalJeeto (MySQL).
 *
 * Import database/goaljeto.sql into your cPanel MySQL database once (it creates
 * every table). Fixtures come from data/schedule.php via sync_matches.php;
 * users + predictions fill in as people play.
 *
 * Usage:
 *   require_once __DIR__ . '/db.php';
 *   $rows = db()->query('SELECT * FROM matches')->fetchAll();
 */

// Load config at file scope, not just inside db(). Requiring it is what pins the
// timezone, and match_is_open()/bn_datetime() both read the clock — a caller that
// uses them without first opening a connection would otherwise compare Dhaka
// kickoff strings against a UTC now(), holding predictions open six hours into
// the match. The array return value is ignored here; db() re-requires for it.
require_once __DIR__ . '/config.php';

/**
 * The highest goal bucket a user can pick. 5 means "৫ বা তার বেশি" (5 or more),
 * so a real 7-goal thriller settles against bucket 5.
 */
const GOAL_MAX = 5;

/** Points for nailing the exact total. */
const PTS_EXACT = 3;

/** Points for being one goal off. Keeps the leaderboard from becoming a mass tie. */
const PTS_CLOSE = 1;

function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';
    $db = $config['db'];

    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

/**
 * Create-or-update a player row keyed by a hash of their phone number, and
 * return the user id. The raw MSISDN is never stored — only its sha256 hash
 * (identity key) and a masked form (017•••678) for display.
 *
 * @param string $phone   11-digit 01XXXXXXXXX number (from the session, not client input)
 * @param string $display Chosen display name (may be empty)
 * @return int|null       The user's id, or null if $phone is not a valid number
 */
function upsert_user(PDO $pdo, string $phone, string $display = ''): ?int
{
    if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
        return null;
    }

    $hash   = hash('sha256', $phone);
    $masked = substr($phone, 0, 3) . '•••' . substr($phone, -3);

    $sel = $pdo->prepare('SELECT id FROM users WHERE phone_hash = ?');
    $sel->execute([$hash]);
    $id = $sel->fetchColumn();

    if ($id !== false) {
        // Keep the newest display name (only overwrite when a non-empty one is given).
        if ($display !== '') {
            $upd = $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
            $upd->execute([$display, $id]);
        }
        return (int) $id;
    }

    $ins = $pdo->prepare(
        'INSERT INTO users (phone_hash, phone_masked, display_name) VALUES (?, ?, ?)'
    );
    $ins->execute([$hash, $masked, $display]);
    return (int) $pdo->lastInsertId();
}

/**
 * Award points for one prediction against the match's real total.
 *
 * Both sides are clamped to GOAL_MAX first, so a prediction of "৫+" against a
 * real 6-goal match is an exact hit, and "৪" against that same match is one
 * bucket away rather than two.
 */
function score_prediction(int $predictedGoals, int $actualTotal): int
{
    $predicted = min(max($predictedGoals, 0), GOAL_MAX);
    $actual    = min(max($actualTotal, 0), GOAL_MAX);

    $off = abs($predicted - $actual);
    if ($off === 0) {
        return PTS_EXACT;
    }
    if ($off === 1) {
        return PTS_CLOSE;
    }
    return 0;
}

/**
 * A match is only predictable once BOTH teams are confirmed (no 'TBD' left in
 * data/schedule.php) and kickoff has not passed. Callers must re-check this
 * server-side before writing a prediction — never trust the browser.
 */
function match_is_open(array $match): bool
{
    if (empty($match['is_active'])) {
        return false;
    }
    if (teams_confirmed($match) === false) {
        return false;
    }
    if ($match['status'] !== 'upcoming') {
        return false;
    }
    return strtotime($match['kickoff_at']) > time();
}

/** Both team names filled in (i.e. the schedule file no longer says TBD)? */
function teams_confirmed(array $match): bool
{
    $tbd = static fn($t) => $t === '' || strcasecmp(trim($t), 'TBD') === 0;
    return !$tbd($match['home_team']) && !$tbd($match['away_team']);
}

/**
 * Convert ASCII digits in a string/number to Bengali numerals (০১২৩...).
 */
function bn($value)
{
    return strtr((string) $value, [
        '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
        '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
    ]);
}

/**
 * Render a kickoff timestamp as "১০ জুলাই, রাত ২:০০" for display.
 */
function bn_datetime(string $mysqlDateTime): string
{
    static $months = [
        1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
        5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
        9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
    ];
    $ts = strtotime($mysqlDateTime);
    return bn((int) date('j', $ts)) . ' ' . $months[(int) date('n', $ts)]
         . ', ' . bn(date('g:i', $ts)) . ' ' . (date('a', $ts) === 'am' ? 'AM' : 'PM');
}
