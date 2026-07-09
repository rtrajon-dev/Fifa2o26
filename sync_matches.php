<?php
/**
 * Import data/schedule.php into the `matches` table.
 *
 * Run this every time you edit data/schedule.php — typically to replace a 'TBD'
 * with a real team name once FIFA confirms the fixture.
 *
 *   CLI:  php sync_matches.php
 *   Web:  https://yoursite.com/sync_matches.php?token=<ADMIN_TOKEN>
 *
 * Rows are matched on `code`, never on team names, so filling in the teams
 * updates the existing match and leaves its predictions intact.
 *
 * Safety rules:
 *   - A match already 'finished' keeps its result; only cosmetic fields update.
 *   - Kickoff is never moved on a match whose kickoff has already passed —
 *     that would retroactively reopen predictions on a game being played.
 *   - Removing a row from schedule.php does NOT delete the match (predictions
 *     would be orphaned). Set 'active' => false to hide it instead.
 */

$isCli = (php_sapi_name() === 'cli');
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = $config['admin_token'];
    if ($token === '' || !hash_equals($token, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit("403 — invalid or missing ?token=. Set ADMIN_TOKEN in .env.\n");
    }
}

$schedule = require __DIR__ . '/data/schedule.php';
$pdo = db();

$seen = [];
$created = $updated = $skipped = 0;

foreach ($schedule as $i => $m) {
    foreach (['code', 'home', 'away', 'kickoff'] as $key) {
        if (!isset($m[$key]) || trim((string) $m[$key]) === '') {
            exit("ERROR: schedule.php entry #$i is missing '$key'.\n");
        }
    }

    $code    = trim($m['code']);
    $kickoff = strtotime($m['kickoff']);
    if ($kickoff === false) {
        exit("ERROR: match '$code' has an unparseable kickoff '{$m['kickoff']}'. Use 'YYYY-MM-DD HH:MM'.\n");
    }
    if (isset($seen[$code])) {
        exit("ERROR: duplicate match code '$code' in schedule.php — codes must be unique.\n");
    }
    $seen[$code] = true;

    $sel = $pdo->prepare('SELECT id, status, kickoff_at FROM matches WHERE code = ?');
    $sel->execute([$code]);
    $existing = $sel->fetch();

    $kickoffSql = date('Y-m-d H:i:s', $kickoff);
    $active     = array_key_exists('active', $m) ? (int) (bool) $m['active'] : 1;
    $label      = $m['label'] ?? $code;
    $venue      = $m['venue'] ?? '';

    if (!$existing) {
        $pdo->prepare(
            'INSERT INTO matches (code, label, home_team, away_team, venue, kickoff_at, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$code, $label, trim($m['home']), trim($m['away']), $venue, $kickoffSql, $active]);
        $created++;
        echo "+ created $code — {$m['home']} vs {$m['away']} @ $kickoffSql\n";
        continue;
    }

    if ($existing['status'] === 'finished') {
        // The result is in and points are awarded. Refuse to touch the fixture:
        // renaming a team or moving kickoff now would contradict settled points.
        echo "· skipped $code — already finished (result is locked in)\n";
        $skipped++;
        continue;
    }

    // Never move kickoff backwards past 'now' on a match that already started.
    $kickoffPassed = strtotime($existing['kickoff_at']) <= time();
    $newKickoff    = $kickoffPassed ? $existing['kickoff_at'] : $kickoffSql;
    if ($kickoffPassed && $newKickoff !== $kickoffSql) {
        echo "! $code — kickoff already passed; keeping {$existing['kickoff_at']}, ignoring {$m['kickoff']}\n";
    }

    $pdo->prepare(
        'UPDATE matches
            SET label = ?, home_team = ?, away_team = ?, venue = ?, kickoff_at = ?, is_active = ?
          WHERE id = ?'
    )->execute([$label, trim($m['home']), trim($m['away']), $venue, $newKickoff, $active, $existing['id']]);
    $updated++;
    echo "~ updated $code — {$m['home']} vs {$m['away']} @ $newKickoff\n";
}

// Flip any match whose kickoff has passed from 'upcoming' to 'locked' so the UI
// stops offering it. (predict_api.php re-checks the clock anyway — this is just
// keeping the stored status honest.)
$locked = $pdo->exec(
    "UPDATE matches SET status = 'locked'
      WHERE status = 'upcoming' AND kickoff_at <= NOW()"
);

echo "\ndone — $created created, $updated updated, $skipped skipped, $locked locked at kickoff\n";

$tbd = $pdo->query(
    "SELECT code FROM matches
      WHERE is_active = 1 AND status <> 'finished'
        AND (UPPER(home_team) = 'TBD' OR UPPER(away_team) = 'TBD')
      ORDER BY kickoff_at"
)->fetchAll(PDO::FETCH_COLUMN);

if ($tbd) {
    echo "\nStill awaiting teams (prediction disabled until you fill these in):\n  "
       . implode(', ', $tbd) . "\n";
}
