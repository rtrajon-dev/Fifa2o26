<?php
/**
 * Enter a finished match's real score and award points to everyone who predicted.
 *
 *   CLI:  php settle.php FINAL 2 1          # Brazil 2 – 1 France → total 3 goals
 *         php settle.php FINAL 2 1 --force  # re-settle (recomputes every point)
 *
 *   Web:  https://yoursite.com/settle.php?token=<ADMIN_TOKEN>
 *         (a small form listing every match awaiting a result)
 *
 * Scoring (see score_prediction() in db.php): the user predicted the TOTAL goals
 * in the match, bucketed 0..5 where 5 means "5 or more".
 *   exact bucket    → 3 points
 *   one bucket off  → 1 point
 *   anything else   → 0
 *
 * Settling is idempotent per match: it runs in a transaction, rewrites points for
 * every prediction on that match, and marks the match 'finished'. Running it twice
 * with the same score changes nothing. Running it with --force and a corrected
 * score recomputes cleanly rather than double-awarding.
 */

$isCli  = (php_sapi_name() === 'cli');
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Authenticate BEFORE touching the database. Connecting first means an anonymous
// caller can provoke a PDOException — and an uncaught one prints the DSN, the DB
// username and the full stack trace straight into the response. Whoever holds
// this token can decide who wins prizes, so nothing happens before it checks out.
$token = $isCli ? '' : $config['admin_token'];
if (!$isCli && ($token === '' || !hash_equals($token, $_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('403 — invalid or missing ?token=. Set ADMIN_TOKEN in .env.');
}

$pdo = db();

/**
 * Write the result and rewrite every prediction's points for one match.
 * @return array{settled:int,exact:int,close:int,total_goals:int}
 */
function settle_match(PDO $pdo, string $code, int $homeGoals, int $awayGoals, bool $force): array
{
    $sel = $pdo->prepare('SELECT id, status FROM matches WHERE code = ?');
    $sel->execute([$code]);
    $match = $sel->fetch();

    if (!$match) {
        throw new RuntimeException("No match with code '$code'. Did you run sync_matches.php?");
    }
    if ($match['status'] === 'finished' && !$force) {
        throw new RuntimeException("Match '$code' is already settled. Pass --force to re-settle with a corrected score.");
    }

    $actualTotal = $homeGoals + $awayGoals;

    $pdo->beginTransaction();
    try {
        // settled_at from PHP's clock (pinned to Asia/Dhaka in config.php), not
        // MySQL's NOW() — the DB server's timezone is usually UTC and would
        // stamp every result six hours off the kickoff times beside it.
        $pdo->prepare(
            "UPDATE matches
                SET home_goals = ?, away_goals = ?, status = 'finished', settled_at = ?
              WHERE id = ?"
        )->execute([$homeGoals, $awayGoals, date('Y-m-d H:i:s'), $match['id']]);

        $preds = $pdo->prepare('SELECT id, predicted_goals FROM predictions WHERE match_id = ?');
        $preds->execute([$match['id']]);

        $upd = $pdo->prepare('UPDATE predictions SET points = ?, is_settled = 1 WHERE id = ?');
        $exact = $close = $settled = 0;

        foreach ($preds->fetchAll() as $p) {
            $pts = score_prediction((int) $p['predicted_goals'], $actualTotal);
            $upd->execute([$pts, $p['id']]);
            $settled++;
            if ($pts === PTS_EXACT) { $exact++; }
            elseif ($pts === PTS_CLOSE) { $close++; }
        }

        $pdo->commit();
        return ['settled' => $settled, 'exact' => $exact, 'close' => $close, 'total_goals' => $actualTotal];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------- CLI
if ($isCli) {
    $args  = array_slice($argv, 1);
    $force = in_array('--force', $args, true);
    $args  = array_values(array_filter($args, fn($a) => $a !== '--force'));

    if (count($args) !== 3) {
        exit("usage: php settle.php <MATCH_CODE> <HOME_GOALS> <AWAY_GOALS> [--force]\n"
           . "  e.g. php settle.php FINAL 2 1\n");
    }

    try {
        $r = settle_match($pdo, $args[0], (int) $args[1], (int) $args[2], $force);
    } catch (Throwable $e) {
        exit('ERROR: ' . $e->getMessage() . "\n");
    }

    echo "settled {$args[0]} — {$args[1]}-{$args[2]} ({$r['total_goals']} goals total)\n"
       . "  {$r['settled']} predictions scored: {$r['exact']} exact (+" . PTS_EXACT . "), "
       . "{$r['close']} one off (+" . PTS_CLOSE . ")\n";
    exit;
}

// ---------------------------------------------------------------- Web
// (the ?token= check already ran, before the database was touched)

$notice = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $r = settle_match(
            $pdo,
            $_POST['code'] ?? '',
            max(0, (int) ($_POST['home_goals'] ?? 0)),
            max(0, (int) ($_POST['away_goals'] ?? 0)),
            !empty($_POST['force'])
        );
        $notice = "Settled — {$r['total_goals']} goals total, {$r['settled']} predictions scored "
                . "({$r['exact']} exact, {$r['close']} one off).";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$matches = $pdo->query(
    "SELECT code, label, home_team, away_team, kickoff_at, status, home_goals, away_goals,
            (SELECT COUNT(*) FROM predictions p WHERE p.match_id = m.id) AS preds
       FROM matches m WHERE is_active = 1 ORDER BY kickoff_at"
)->fetchAll();

$tokenAttr = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="night">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>GoalJeeto — Settle matches</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet">
</head>
<body class="bg-base-100 text-base-content p-6">
<div class="max-w-4xl mx-auto">
  <h1 class="text-2xl font-bold mb-1">Settle matches</h1>
  <p class="text-sm text-base-content/60 mb-6">
    Enter the real score once a match ends. Points are awarded on the <strong>total</strong>
    goals: exact bucket +<?= PTS_EXACT ?>, one off +<?= PTS_CLOSE ?>. A prediction of “৫+”
    matches any score of 5 or more.
  </p>

  <?php if ($notice): ?><div class="alert alert-success mb-4"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error mb-4"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <table class="table">
    <thead><tr><th>Match</th><th>Kickoff</th><th>Preds</th><th>Result</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($matches as $m): $done = $m['status'] === 'finished'; ?>
      <tr>
        <td>
          <div class="font-semibold"><?= htmlspecialchars($m['home_team'] . ' vs ' . $m['away_team'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="text-xs text-base-content/50"><?= htmlspecialchars($m['code'] . ' · ' . $m['label'], ENT_QUOTES, 'UTF-8') ?></div>
        </td>
        <td class="text-xs"><?= htmlspecialchars($m['kickoff_at'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= (int) $m['preds'] ?></td>
        <td>
          <?php if ($done): ?>
            <span class="badge badge-success"><?= (int) $m['home_goals'] ?>–<?= (int) $m['away_goals'] ?></span>
          <?php else: ?>
            <span class="badge badge-ghost"><?= htmlspecialchars($m['status'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </td>
        <td>
          <form method="post" action="?token=<?= $tokenAttr ?>" class="flex items-center gap-1">
            <input type="hidden" name="code" value="<?= htmlspecialchars($m['code'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="number" min="0" max="20" name="home_goals" value="<?= $done ? (int) $m['home_goals'] : 0 ?>" class="input input-bordered input-xs w-14">
            <span class="text-base-content/40">–</span>
            <input type="number" min="0" max="20" name="away_goals" value="<?= $done ? (int) $m['away_goals'] : 0 ?>" class="input input-bordered input-xs w-14">
            <?php if ($done): ?><input type="hidden" name="force" value="1"><?php endif; ?>
            <button class="btn btn-xs <?= $done ? 'btn-warning' : 'btn-primary' ?>"
                    onclick="return confirm('<?= $done ? 'Re-settle and recompute every point for this match?' : 'Settle this match and award points?' ?>')">
              <?= $done ? 'Re-settle' : 'Settle' ?>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
