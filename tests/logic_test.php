<?php
/**
 * Pure-logic tests for the rules that decide who wins.
 *
 * Deliberately needs no database and no .env: db.php connects lazily, so every
 * function exercised here is reachable on a bare checkout. Run it locally the
 * same way CI does:
 *
 *   php tests/logic_test.php
 *
 * Exits non-zero on the first failure count > 0, so it works as a CI gate.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rate_limit.php';

$passed = 0;
$failed = 0;

function check(string $what, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failed++;
    printf("  FAIL  %s\n        expected: %s\n        actual:   %s\n",
        $what, var_export($expected, true), var_export($actual, true));
}

// ---------------------------------------------------------------- scoring
// The user predicts the TOTAL goals, bucketed 0..5 where 5 means "5 or more".
// Both sides clamp to GOAL_MAX, so a 7-goal match settles against bucket 5.
//
// Expectations are written as LITERALS (3, 1, 0), never as PTS_EXACT/PTS_CLOSE.
// Asserting score_prediction(2,3) === PTS_CLOSE is a tautology: change the
// constant and both sides move together, so the test can never catch it. The
// payout values are part of the contract shown to players ("+৩ পয়েন্ট"), so
// pin them here.
echo "score_prediction()\n";
check('the promised payouts have not changed', [GOAL_MAX, PTS_EXACT, PTS_CLOSE], [5, 3, 1]);
check('exact 0-0',            score_prediction(0, 0), 3);
check('exact 3 goals',        score_prediction(3, 3), 3);
check('one under',            score_prediction(2, 3), 1);
check('one over',             score_prediction(4, 3), 1);
check('two off scores zero',  score_prediction(1, 3), 0);
check('three off scores zero', score_prediction(0, 3), 0);
check('"5+" vs a 5-goal game', score_prediction(5, 5), 3);
check('"5+" vs a 7-goal game', score_prediction(5, 7), 3);
check('4 vs a 6-goal game is one off', score_prediction(4, 6), 1);
check('4 vs a 7-goal game is still one off', score_prediction(4, 7), 1);
check('3 vs a 7-goal game scores zero', score_prediction(3, 7), 0);
check('negative pick clamps to 0', score_prediction(-3, 0), 3);
check('absurd pick clamps to 5',   score_prediction(99, 6), 3);

// ---------------------------------------------------------------- open gate
// A prediction is accepted only while the teams are confirmed AND kickoff is in
// the future AND the match is active and still 'upcoming'.
echo "match_is_open()\n";
$future = date('Y-m-d H:i:s', time() + 3600);
$past   = date('Y-m-d H:i:s', time() - 3600);
$base   = ['is_active' => 1, 'status' => 'upcoming',
           'home_team' => 'ব্রাজিল', 'away_team' => 'ফ্রান্স', 'kickoff_at' => $future];

check('confirmed + future kickoff', match_is_open($base), true);
check('kickoff already passed',     match_is_open(['kickoff_at' => $past] + $base), false);
check('home team TBD',              match_is_open(['home_team' => 'TBD'] + $base), false);
check('away team tbd, lowercase',   match_is_open(['away_team' => 'tbd'] + $base), false);
check('empty team name',            match_is_open(['home_team' => ''] + $base), false);
check('status locked',              match_is_open(['status' => 'locked'] + $base), false);
check('status finished',            match_is_open(['status' => 'finished'] + $base), false);
check('hidden match',               match_is_open(['is_active' => 0] + $base), false);

echo "teams_confirmed()\n";
check('both real',   teams_confirmed($base), true);
check('padded TBD',  teams_confirmed(['home_team' => '  TBD '] + $base), false);

// ---------------------------------------------------------------- Bengali
echo "bn() / bn_period() / bn_datetime()\n";
check('digits',       bn('2026'), '২০২৬');
check('mixed string', bn('5 goals'), '৫ goals');

// Every boundary, both sides. A period that starts one hour early is invisible
// in spot checks but wrong on screen for a 3 AM or 6 PM kickoff.
check('00:00 রাত',    bn_period(0),  'রাত');
check('03:00 রাত',    bn_period(3),  'রাত');      // last hour of রাত
check('04:00 ভোর',    bn_period(4),  'ভোর');      // ভোর begins
check('05:00 ভোর',    bn_period(5),  'ভোর');
check('06:00 সকাল',   bn_period(6),  'সকাল');     // সকাল begins
check('11:00 সকাল',   bn_period(11), 'সকাল');
check('12:00 দুপুর',  bn_period(12), 'দুপুর');    // দুপুর begins
check('14:00 দুপুর',  bn_period(14), 'দুপুর');
check('15:00 বিকাল',  bn_period(15), 'বিকাল');    // বিকাল begins
check('17:00 বিকাল',  bn_period(17), 'বিকাল');
check('18:00 সন্ধ্যা', bn_period(18), 'সন্ধ্যা');   // সন্ধ্যা begins
check('19:00 সন্ধ্যা', bn_period(19), 'সন্ধ্যা');
check('20:00 রাত',    bn_period(20), 'রাত');      // রাত resumes
check('23:00 রাত',    bn_period(23), 'রাত');

// The docblock's own example, and QF-1's real kickoff.
check('QF-1 kickoff renders', bn_datetime('2026-07-10 02:00:00'), '১০ জুলাই, রাত ২:০০');
check('no English meridiem',  strpos(bn_datetime('2026-07-10 14:30:00'), 'PM'), false);

// ---------------------------------------------------------------- msisdn
echo "normalize_msisdn()\n";
check('plain',        normalize_msisdn('01812345678'), '01812345678');
check('88 prefix',    normalize_msisdn('8801812345678'), '01812345678');
check('spaces/dashes',normalize_msisdn('018-1234 5678'), '01812345678');
check('too short',    normalize_msisdn('12345'), '');
check('bad operator', normalize_msisdn('01212345678'), '');
check('garbage',      normalize_msisdn('hello'), '');

// ---------------------------------------------------------------- throttle
// The property that matters: a REJECTED attempt must not be recorded, or an
// attacker hammering the endpoint would keep pushing the window forward and
// lock a victim out permanently.
echo "rate_hit()\n";
$key = 'test:' . getmypid() . ':' . php_uname('n');

check('1st allowed', rate_hit($key, 3, 3600, 0)['ok'], true);
check('2nd allowed', rate_hit($key, 3, 3600, 0)['ok'], true);
check('3rd allowed', rate_hit($key, 3, 3600, 0)['ok'], true);
check('4th blocked', rate_hit($key, 3, 3600, 0)['ok'], false);

$cool = 'cool:' . getmypid();
check('cooldown: first allowed',   rate_hit($cool, 99, 3600, 60)['ok'], true);
check('cooldown: second blocked',  rate_hit($cool, 99, 3600, 60)['ok'], false);

$stored = json_decode(file_get_contents(rate_dir() . '/' . hash('sha256', $cool) . '.json'), true);
check('blocked attempt was not recorded', count($stored), 1);

// Independent keys do not interfere.
check('a different key is unaffected', rate_hit('other:' . getmypid(), 3, 3600, 60)['ok'], true);

// Clean up the counters this run created.
foreach (glob(rate_dir() . '/*.json') as $f) {
    @unlink($f);
}

// ---------------------------------------------------------------- summary
echo "\n";
if ($failed > 0) {
    echo "FAILED — {$failed} failed, {$passed} passed\n";
    exit(1);
}
echo "ok — {$passed} passed\n";
