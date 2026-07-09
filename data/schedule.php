<?php
/**
 * ============================================================================
 *  ম্যাচ তালিকা / FIXTURE LIST  —  এই ফাইলটাই তুমি হাতে এডিট করবে।
 * ============================================================================
 *
 *  HOW TO USE
 *  ----------
 *  1. FIFA যখন একটা ম্যাচের দল নিশ্চিত করে, নিচে সেই ম্যাচের 'home' / 'away'
 *     ঘরে 'TBD' মুছে দলের নাম লিখে দাও (বাংলায়)।
 *  2. তারপর একবার চালাও:   php sync_matches.php
 *     (অথবা ব্রাউজারে: /sync_matches.php?token=<ADMIN_TOKEN>)
 *  3. ব্যস। সাইট আপডেট হয়ে যাবে।
 *
 *  THE ONE RULE
 *  ------------
 *  ** 'code' কখনও বদলাবে না। **
 *  Everything in the database keys off `code`, not off team names. That is what
 *  lets you leave a match as TBD today and fill in the teams tomorrow without
 *  losing predictions, points, or the match's row. Change a team name freely;
 *  change a code and you have created a different match.
 *
 *  TBD BEHAVIOUR
 *  -------------
 *  A match with 'TBD' in either slot is shown on the site as "দল নিশ্চিত হয়নি"
 *  and prediction is DISABLED for it. That is deliberate: predicting a goal
 *  count for an unknown team is meaningless, and it would force you to void
 *  those rows later. As soon as both names are real, it opens automatically.
 *
 *  KICKOFF
 *  -------
 *  'kickoff' is Bangladesh time (Asia/Dhaka), 24-hour, 'YYYY-MM-DD HH:MM'.
 *  Predictions lock automatically at kickoff — enforced on the server, so a
 *  user cannot sneak a prediction in after the whistle.
 *
 *  ADDING A MATCH
 *  --------------
 *  Just append a row. Deleting a row here does NOT delete it from the database
 *  (predictions would be orphaned); set 'active' => false instead to hide it.
 */

return [

    // ---------- কোয়ার্টার ফাইনাল / QUARTER-FINALS ----------
    [
        'code'    => 'QF-1',
        'label'   => 'কোয়ার্টার ফাইনাল ১',
        'home'    => 'TBD',
        'away'    => 'TBD',
        'kickoff' => '2026-07-10 02:00',
        'venue'   => 'MetLife Stadium, New Jersey',
        'active'  => true,
    ],
    [
        'code'    => 'QF-2',
        'label'   => 'কোয়ার্টার ফাইনাল ২',
        'home'    => 'TBD',
        'away'    => 'TBD',
        'kickoff' => '2026-07-10 06:00',
        'venue'   => 'AT&T Stadium, Dallas',
        'active'  => true,
    ],
    [
        'code'    => 'QF-3',
        'label'   => 'কোয়ার্টার ফাইনাল ৩',
        'home'    => 'TBD',
        'away'    => 'TBD',
        'kickoff' => '2026-07-11 02:00',
        'venue'   => 'Mercedes-Benz Stadium, Atlanta',
        'active'  => true,
    ],
    [
        'code'    => 'QF-4',
        'label'   => 'কোয়ার্টার ফাইনাল ৪',
        'home'    => 'TBD',
        'away'    => 'TBD',
        'kickoff' => '2026-07-11 06:00',
        'venue'   => 'Levi\'s Stadium, San Francisco',
        'active'  => true,
    ],

    // ---------- সেমি ফাইনাল / SEMI-FINALS ----------
    [
        'code'    => 'SF-1',
        'label'   => 'সেমি ফাইনাল ১',
        'home'    => 'TBD',
        'away'    => 'TBD',
        'kickoff' => '2026-07-15 05:00',
        'venue'   => 'AT&T Stadium, Dallas',
        'active'  => true,
    ],
    [
        'code'    => 'SF-2',
        'label'   => 'সেমি ফাইনাল ২',
        'home'    => 'TBD',
        'away'    => 'TBD',
        'kickoff' => '2026-07-16 05:00',
        'venue'   => 'Mercedes-Benz Stadium, Atlanta',
        'active'  => true,
    ],

    // ---------- তৃতীয় স্থান নির্ধারণী / THIRD PLACE ----------
    [
        'code'    => 'THIRD',
        'label'   => 'তৃতীয় স্থান নির্ধারণী',
        'home'    => 'TBD',
        'away'    => 'TBD',
        'kickoff' => '2026-07-19 01:00',
        'venue'   => 'Hard Rock Stadium, Miami',
        'active'  => true,
    ],

    // ---------- ফাইনাল / FINAL ----------
    [
        'code'    => 'FINAL',
        'label'   => 'ফাইনাল',
        'home'    => 'TBD',
        'away'    => 'TBD',
        'kickoff' => '2026-07-20 01:00',
        'venue'   => 'MetLife Stadium, New Jersey',
        'active'  => true,
    ],
];
