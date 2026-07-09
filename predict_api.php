<?php
/**
 * Prediction engine (server-authoritative).
 *
 * Where QuizJeeto scored an answer inside the request — the truth sat in the
 * questions table — a goal prediction cannot be scored until the real match is
 * played. So this file only *records* predictions; settle.php awards the points
 * afterwards. Nothing here ever tells the client whether they were right.
 *
 * The rule that matters: a prediction is accepted only while now < kickoff_at,
 * checked HERE on the server via match_is_open(), against PHP's clock (pinned to
 * Asia/Dhaka in config.php, which is the timezone kickoff times are written in).
 * The browser's idea of whether a match is open is decoration.
 *
 * Actions (?action=):
 *   list (POST) → every active match + this user's prediction + open/locked state
 *   save (POST: code=<match code>, goals=0..5) → create or replace a prediction
 */

header('Content-Type: application/json; charset=utf-8');

if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/sessions');
    if (!is_dir(__DIR__ . '/sessions')) {
        @mkdir(__DIR__ . '/sessions', 0755, true);
    }
}
session_start();
require_once __DIR__ . '/db.php';

// --- gate: must be a registered player ---
if (empty($_SESSION['phone'])) {
    http_response_code(403);
    echo json_encode(['error' => 'not_registered']);
    exit;
}

$pdo    = db();
$action = $_GET['action'] ?? '';

/** The logged-in player's users.id, creating the row if login didn't. */
function current_user_id(PDO $pdo): ?int
{
    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }
    $uid = upsert_user($pdo, $_SESSION['phone'], $_SESSION['display_name'] ?? '');
    if ($uid) {
        $_SESSION['user_id'] = $uid;
    }
    return $uid;
}

/** Every active match, newest kickoff last, with this user's pick attached. */
function match_list(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT m.id, m.code, m.label, m.home_team, m.away_team, m.venue, m.kickoff_at,
                m.status, m.home_goals, m.away_goals, m.is_active,
                p.predicted_goals, p.points, p.is_settled
           FROM matches m
           LEFT JOIN predictions p ON p.match_id = m.id AND p.user_id = ?
          WHERE m.is_active = 1
          ORDER BY m.kickoff_at ASC'
    );
    $stmt->execute([$userId]);

    $out = [];
    foreach ($stmt->fetchAll() as $m) {
        $confirmed = teams_confirmed($m);
        $open      = match_is_open($m);
        $finished  = $m['status'] === 'finished';

        $out[] = [
            'code'      => $m['code'],
            'label'     => $m['label'],
            'home'      => $confirmed ? $m['home_team'] : null,
            'away'      => $confirmed ? $m['away_team'] : null,
            'venue'     => $m['venue'],
            'kickoff'   => $m['kickoff_at'],
            'kickoff_bn'=> bn_datetime($m['kickoff_at']),
            'confirmed' => $confirmed,
            'open'      => $open,
            'finished'  => $finished,
            // Real result only once the match is settled — never a hint beforehand.
            'result'    => $finished
                ? ['home' => (int) $m['home_goals'], 'away' => (int) $m['away_goals'],
                   'total' => (int) $m['home_goals'] + (int) $m['away_goals']]
                : null,
            'my_pick'   => $m['predicted_goals'] === null ? null : (int) $m['predicted_goals'],
            'my_points' => $m['is_settled'] ? (int) $m['points'] : null,
        ];
    }
    return $out;
}

$uid = current_user_id($pdo);
if (!$uid) {
    http_response_code(500);
    echo json_encode(['error' => 'no_user']);
    exit;
}

if ($action === 'list') {
    echo json_encode([
        'ok'       => true,
        'goal_max' => GOAL_MAX,
        'matches'  => match_list($pdo, $uid),
    ]);
    exit;
}

if ($action === 'save') {
    $code  = trim($_POST['code'] ?? '');
    $goals = $_POST['goals'] ?? '';

    if ($goals === '' || !ctype_digit((string) $goals) || (int) $goals < 0 || (int) $goals > GOAL_MAX) {
        http_response_code(422);
        echo json_encode(['error' => 'bad_goals']);
        exit;
    }
    $goals = (int) $goals;

    $stmt = $pdo->prepare('SELECT * FROM matches WHERE code = ?');
    $stmt->execute([$code]);
    $match = $stmt->fetch();

    if (!$match) {
        http_response_code(404);
        echo json_encode(['error' => 'no_such_match']);
        exit;
    }

    // The authoritative gate. Kickoff passed, teams still TBD, match already
    // settled, match hidden — all land here, whatever the browser believed.
    if (!match_is_open($match)) {
        http_response_code(409);
        echo json_encode([
            'error'   => 'match_closed',
            'message' => teams_confirmed($match)
                ? 'ম্যাচ শুরু হয়ে গেছে — আর অনুমান করা যাবে না।'
                : 'এই ম্যাচের দল এখনো নিশ্চিত হয়নি।',
        ]);
        exit;
    }

    // One row per (user, match). Re-picking before kickoff overwrites the old
    // choice; the UNIQUE key makes that a single atomic statement.
    $pdo->prepare(
        'INSERT INTO predictions (user_id, match_id, predicted_goals)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE predicted_goals = VALUES(predicted_goals)'
    )->execute([$uid, $match['id'], $goals]);

    echo json_encode(['ok' => true, 'code' => $code, 'goals' => $goals]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown_action']);
