<?php
// Ensure sessions persist on shared hosting (cPanel)
if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/sessions');
    if (!is_dir(__DIR__ . '/sessions')) {
        @mkdir(__DIR__ . '/sessions', 0755, true);
    }
}
session_start();
$pageTitle = 'Fifa2026 — গোল অনুমান করুন, জিতুন';

require_once __DIR__ . '/db.php';

// --- Rewards. These labels are DISPLAY ONLY: nothing is sent automatically, and
//     no record of a win is written. Fulfil them yourself from this table. ---
$prizes = ['২০০০ MB ডেটা', '১৫০০ MB ডেটা', '১০০০ MB ডেটা', '৫০০ MB ডেটা', '৫০০ MB ডেটা'];

$matches      = [];
$lbRows       = [];
$openCount    = 0;
$totalMatches = 0;
$totalPlayers = 0;

// This is the public landing page: it must render even with no database behind
// it (misconfigured .env, MySQL down mid-tournament). A visitor should see the
// pitch and the registration card, not a blank 500. The counters simply read ০
// and the leaderboard falls back to its "no results yet" empty state.
try {
    // --- Fixtures straight from the DB. Edit data/schedule.php and run
    //     sync_matches.php to change what appears here — no code change. ---
    $matches = db()->query(
      "SELECT code, label, home_team, away_team, venue, kickoff_at, status, home_goals, away_goals
         FROM matches
        WHERE is_active = 1
        ORDER BY kickoff_at ASC"
    )->fetchAll();

    foreach ($matches as $m) {
        if (match_is_open($m)) {
            $openCount++;
        }
    }
    $totalMatches = count($matches);
    $totalPlayers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

    /**
     * Top-5 by total points across every SETTLED prediction.
     *
     * Ties are broken by who committed earliest (MIN(created_at)), which matters a
     * lot more here than it did in QuizJeeto: with only six goal buckets to choose
     * from, hundreds of players land on the same points total. Without a tiebreak
     * the prize column would be arbitrary.
     */
    $lbRows = db()->query(
        "SELECT u.display_name, u.phone_masked,
                SUM(p.points) AS points,
                SUM(p.points = " . PTS_EXACT . ") AS exact_hits,
                COUNT(*) AS played
           FROM predictions p
           JOIN users u ON u.id = p.user_id
          WHERE p.is_settled = 1
          GROUP BY u.id
          ORDER BY points DESC, exact_hits DESC, MIN(p.created_at) ASC
          LIMIT 5"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Fifa2026 index.php: database unavailable — ' . $e->getMessage());
}

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/navbar.php';
?>

<!-- ============ HERO ============ -->
<section class="relative overflow-hidden">
  <div class="absolute inset-0 bg-gradient-to-br from-primary/20 via-base-100 to-secondary/10"></div>
  <div class="relative max-w-6xl mx-auto px-4 lg:px-8 py-10 sm:py-16 lg:py-24 grid lg:grid-cols-2 gap-8 lg:gap-12 items-center">
    <!-- Left: copy -->
    <div class="text-center lg:text-left">
      <div class="flex flex-wrap gap-2 justify-center lg:justify-start mb-4">
        <div class="badge badge-secondary badge-outline gap-1">🏆 ফিফা বিশ্বকাপ ২০২৬</div>
        <div class="badge badge-primary badge-outline gap-1">🌐 ওয়েব অ্যাপ — যেকোনো ব্রাউজারে</div>
      </div>
      <h1 class="text-3xl sm:text-4xl lg:text-6xl font-bold leading-tight">
        গোল অনুমান করে <span style="background:linear-gradient(90deg,hsl(var(--p)),hsl(var(--s)));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;">জিতুন</span> পুরস্কার
      </h1>
      <p class="py-5 sm:py-6 text-base sm:text-lg text-base-content/70 max-w-lg mx-auto lg:mx-0">
        প্রতিটি ম্যাচে মোট কত গোল হবে অনুমান করুন। হুবহু মিললে ৩ পয়েন্ট, ১ গোলের ব্যবধানে ১ পয়েন্ট।
        সবচেয়ে বেশি পয়েন্ট যাদের, তাদের জন্য থাকছে ডেটা প্যাক পুরস্কার।
      </p>
      <div class="flex flex-col sm:flex-row gap-3 sm:justify-center lg:justify-start">
        <a href="#register" class="btn btn-primary btn-lg w-full sm:w-auto">এখনই অনুমান করুন</a>
        <a href="#how" class="btn btn-outline btn-lg w-full sm:w-auto">কীভাবে কাজ করে?</a>
      </div>
      <div class="grid grid-cols-3 gap-2 mt-8 text-center text-xs sm:text-sm text-base-content/60">
        <div><span class="text-xl sm:text-2xl font-bold text-base-content"><?= bn($totalMatches) ?></span><br>ম্যাচ</div>
        <div><span class="text-xl sm:text-2xl font-bold text-base-content"><?= bn($openCount) ?></span><br>এখন খোলা</div>
        <div><span class="text-xl sm:text-2xl font-bold text-base-content"><?= bn($totalPlayers) ?></span><br>খেলোয়াড়</div>
      </div>
    </div>

    <!-- Right: registration / OTP card -->
    <div id="register" class="card bg-base-200 shadow-xl border border-base-content/10 scroll-mt-20">
      <div class="card-body p-5 sm:p-8">
      <?php if (!empty($_SESSION['phone'])): ?>
        <!-- Already logged in — skip the registration flow -->
        <h2 class="card-title text-2xl">স্বাগতম<?= !empty($_SESSION['display']) ? ' ' . htmlspecialchars($_SESSION['display'], ENT_QUOTES, 'UTF-8') : '' ?>! 🎉</h2>
        <p class="text-base-content/60 text-sm">আপনি ইতিমধ্যে সাবস্ক্রাইব করেছেন — এখনই গোল অনুমান করুন।</p>
        <div class="mt-4 space-y-3">
          <a href="/predict.php" class="btn btn-primary w-full">গোল অনুমান করুন →</a>
          <a href="/account.php" class="btn btn-ghost btn-sm w-full">আমার অ্যাকাউন্ট</a>
          <a href="/logout.php" class="btn btn-ghost btn-sm w-full">লগআউট</a>
        </div>
      <?php else: ?>
        <h2 class="card-title text-2xl">শুরু করুন</h2>
        <p class="text-base-content/60 text-sm">রবি / এয়ারটেল নম্বর দিন — নতুন হলে সাবস্ক্রাইব, আগে থেকে থাকলে লগইন</p>

        <!-- Step 1: phone -->
        <div id="step-phone" class="mt-4 space-y-3">
          <label class="form-control w-full">
            <div class="label"><span class="label-text">মোবাইল নম্বর</span></div>
            <label class="input input-bordered flex items-center gap-2">
              <span class="text-base-content/50">+৮৮</span>
              <input type="tel" id="phone" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX" class="grow" />
            </label>
            <div class="label"><span class="label-text-alt text-base-content/50">শুধু রবি ও এয়ারটেল নম্বর সমর্থিত</span></div>
          </label>
          <p id="err-phone" class="text-error text-sm hidden"></p>
          <button id="btn-send" onclick="goToOtp()" class="btn btn-primary w-full">প্রবেশ করুন</button>
        </div>

        <!-- Step 2: OTP (hidden until step 1) -->
        <div id="step-otp" class="mt-4 space-y-3 hidden">
          <p class="text-sm text-base-content/70">আপনার নম্বরে পাঠানো ৬-সংখ্যার কোডটি লিখুন</p>
          <div class="flex gap-2 sm:gap-3 justify-center" dir="ltr">
            <input type="text" maxlength="1" inputmode="numeric" class="input input-bordered w-11 h-14 sm:w-12 text-center text-xl otp-box" />
            <input type="text" maxlength="1" inputmode="numeric" class="input input-bordered w-11 h-14 sm:w-12 text-center text-xl otp-box" />
            <input type="text" maxlength="1" inputmode="numeric" class="input input-bordered w-11 h-14 sm:w-12 text-center text-xl otp-box" />
            <input type="text" maxlength="1" inputmode="numeric" class="input input-bordered w-11 h-14 sm:w-12 text-center text-xl otp-box" />
            <input type="text" maxlength="1" inputmode="numeric" class="input input-bordered w-11 h-14 sm:w-12 text-center text-xl otp-box" />
            <input type="text" maxlength="1" inputmode="numeric" class="input input-bordered w-11 h-14 sm:w-12 text-center text-xl otp-box" />
          </div>
          <p id="otp-sent-to" class="text-xs text-base-content/50 text-center"></p>
          <p id="err-otp" class="text-error text-sm text-center hidden"></p>
          <button id="btn-verify" onclick="verifyOtp()" class="btn btn-primary w-full">যাচাই করুন</button>
          <button onclick="resetReg()" class="btn btn-ghost btn-sm w-full">← নম্বর পরিবর্তন করুন</button>
        </div>

        <!-- Step 3: name (optional) -->
        <div id="step-name" class="mt-4 space-y-3 hidden">
          <div class="text-center text-4xl">✅</div>
          <p class="text-center text-sm text-base-content/70">যাচাই সফল! লিডারবোর্ডে দেখানোর জন্য একটি নাম দাও</p>
          <label class="form-control w-full">
            <div class="label"><span class="label-text">তোমার নাম <span class="text-base-content/40">(ঐচ্ছিক)</span></span></div>
            <input type="text" id="display-name" maxlength="30" placeholder="যেমন: রাকিব হাসান" class="input input-bordered w-full" />
          </label>
          <p id="err-name" class="text-error text-sm hidden"></p>
          <button id="btn-name" onclick="saveName()" class="btn btn-primary w-full">সংরক্ষণ করুন</button>
          <button onclick="saveName(true)" class="btn btn-ghost btn-sm w-full">এড়িয়ে যান</button>
        </div>

        <!-- Step 4: success -->
        <div id="step-done" class="mt-4 hidden text-center space-y-3">
          <div class="text-5xl">🎉</div>
          <h3 class="font-bold text-lg">স্বাগতম<span id="welcome-name"></span>!</h3>
          <p class="text-sm text-base-content/70">আপনি এখন গোল অনুমান করতে প্রস্তুত।</p>
          <a href="/predict.php" class="btn btn-accent w-full">অনুমান শুরু করুন →</a>
        </div>
      <?php endif; ?>

        <div class="divider text-xs text-base-content/40">নিরাপদ ও বিশ্বস্ত</div>
        <p class="text-xs text-base-content/40 text-center">
          প্রতিদিন ২.৭৮ টাকা + (ভ্যাট + সম্পূরক শুল্ক + সার্ভিস চার্জ), অটো-রিনিউয়ালসহ আপনার ব্যালেন্স
          থেকে কাটা হবে। শুধুমাত্র রবি ও এয়ারটেল গ্রাহকদের জন্য।
        </p>
      </div>
    </div>
  </div>
</section>

<!-- ============ HOW IT WORKS ============ -->
<section id="how" class="max-w-6xl mx-auto px-4 lg:px-8 py-12 sm:py-16 scroll-mt-20">
  <h2 class="text-2xl sm:text-3xl font-bold text-center mb-2">যেভাবে খেলবেন</h2>
  <p class="text-center text-base-content/60 mb-10">মাত্র তিনটি সহজ ধাপ</p>
  <ul class="steps steps-horizontal w-full">
    <li class="step step-primary" data-content="১">
      <div class="p-4"><div class="text-3xl mb-2">📱</div><h3 class="font-semibold">নম্বর দিন</h3><p class="text-sm text-base-content/60">রবি/এয়ারটেল নম্বর দিয়ে রেজিস্টার করুন</p></div>
    </li>
    <li class="step step-primary" data-content="২">
      <div class="p-4"><div class="text-3xl mb-2">⚽</div><h3 class="font-semibold">গোল অনুমান করুন</h3><p class="text-sm text-base-content/60">কিক-অফের আগে বেছে নিন মোট কত গোল হবে</p></div>
    </li>
    <li class="step step-primary" data-content="৩">
      <div class="p-4"><div class="text-3xl mb-2">🏆</div><h3 class="font-semibold">পয়েন্ট জিতুন</h3><p class="text-sm text-base-content/60">ম্যাচ শেষে পয়েন্ট যোগ হবে, সেরারা পাবেন পুরস্কার</p></div>
    </li>
  </ul>

  <div class="mt-10 max-w-2xl mx-auto grid sm:grid-cols-2 gap-4">
    <div class="card bg-success/10 border border-success/30">
      <div class="card-body items-center text-center p-5">
        <div class="text-3xl">🎯</div>
        <h3 class="font-semibold">হুবহু সঠিক</h3>
        <p class="text-sm text-base-content/70">ম্যাচে যত গোল হয়েছে, ঠিক ততটাই বলেছেন → <strong>+<?= bn(PTS_EXACT) ?> পয়েন্ট</strong></p>
      </div>
    </div>
    <div class="card bg-warning/10 border border-warning/30">
      <div class="card-body items-center text-center p-5">
        <div class="text-3xl">👌</div>
        <h3 class="font-semibold">কাছাকাছি</h3>
        <p class="text-sm text-base-content/70">১ গোলের ব্যবধানে অনুমান → <strong>+<?= bn(PTS_CLOSE) ?> পয়েন্ট</strong></p>
      </div>
    </div>
  </div>
</section>

<!-- ============ FIXTURES ============ -->
<section id="fixtures" class="bg-base-200/50 py-12 sm:py-16 scroll-mt-16">
  <div class="max-w-4xl mx-auto px-4 lg:px-8">
    <h2 class="text-2xl sm:text-3xl font-bold text-center mb-2">ম্যাচসূচি</h2>
    <p class="text-center text-base-content/60 mb-10">দল নিশ্চিত হওয়ার সাথে সাথে অনুমান করা যাবে</p>

    <?php if (!$matches): ?>
      <div class="text-center py-10 rounded-box border border-dashed border-base-content/20 bg-base-100">
        <div class="text-5xl mb-3">📅</div>
        <p class="font-semibold">এখনো কোনো ম্যাচ যোগ হয়নি</p>
        <p class="text-base-content/60 text-sm mt-1">শীঘ্রই আসছে।</p>
      </div>
    <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($matches as $m):
        $confirmed = teams_confirmed($m);
        $open      = match_is_open($m);
        $finished  = $m['status'] === 'finished';
      ?>
      <div class="card bg-base-100 border border-base-content/10">
        <div class="card-body p-4 sm:p-5 flex-row items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="text-xs text-base-content/50"><?= htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="font-semibold truncate">
              <?php if ($confirmed): ?>
                <?= htmlspecialchars($m['home_team'], ENT_QUOTES, 'UTF-8') ?>
                <span class="text-base-content/40 mx-1">vs</span>
                <?= htmlspecialchars($m['away_team'], ENT_QUOTES, 'UTF-8') ?>
              <?php else: ?>
                <span class="text-base-content/40">দল ঘোষণা হয়নি</span>
              <?php endif; ?>
            </div>
            <div class="text-xs text-base-content/50 mt-0.5">🕐 <?= htmlspecialchars(bn_datetime($m['kickoff_at']), ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div class="shrink-0 text-right">
            <?php if ($finished): ?>
              <span class="badge badge-neutral"><?= bn((int) $m['home_goals']) ?> – <?= bn((int) $m['away_goals']) ?></span>
              <div class="text-xs text-base-content/50 mt-1">মোট <?= bn((int) $m['home_goals'] + (int) $m['away_goals']) ?> গোল</div>
            <?php elseif (!$confirmed): ?>
              <span class="badge badge-ghost">অপেক্ষমাণ</span>
            <?php elseif ($open): ?>
              <span class="badge badge-success badge-outline">খোলা</span>
            <?php else: ?>
              <span class="badge badge-error badge-outline">বন্ধ</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-8">
      <a href="<?= !empty($_SESSION['phone']) ? '/predict.php' : '#register' ?>" class="btn btn-primary">অনুমান করুন →</a>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ============ REWARDS ============ -->
<section id="rewards" class="max-w-6xl mx-auto px-4 lg:px-8 py-12 sm:py-16 scroll-mt-16">
  <h2 class="text-2xl sm:text-3xl font-bold text-center mb-2">পুরস্কার</h2>
  <p class="text-center text-base-content/60 mb-10">সেরা অনুমানকারীদের জন্য থাকছে বিশেষ পুরস্কার</p>
  <div class="grid md:grid-cols-3 gap-6">
    <div class="card bg-gradient-to-br from-warning/20 to-base-200 border border-warning/30">
      <div class="card-body items-center text-center">
        <div class="text-5xl">🥇</div><h3 class="card-title">শীর্ষ অনুমানকারী</h3>
        <p class="text-base-content/70">সর্বোচ্চ পয়েন্টধারী পাবেন ২০০০ MB ডেটা + বোনাস পয়েন্ট</p>
      </div>
    </div>
    <div class="card bg-gradient-to-br from-secondary/20 to-base-200 border border-secondary/30">
      <div class="card-body items-center text-center">
        <div class="text-5xl">📶</div><h3 class="card-title">ডেটা বোনাস</h3>
        <p class="text-base-content/70">লিডারবোর্ডের সেরা ৫ জন পাবেন ইন্টারনেট ডেটা বোনাস</p>
      </div>
    </div>
    <div class="card bg-gradient-to-br from-accent/20 to-base-200 border border-accent/30">
      <div class="card-body items-center text-center">
        <div class="text-5xl">🎁</div><h3 class="card-title">ফাইনাল প্রাইজ</h3>
        <p class="text-base-content/70">ফাইনাল ম্যাচের সঠিক অনুমানকারীদের জন্য বিশেষ উপহার</p>
      </div>
    </div>
  </div>
</section>

<!-- ============ LEADERBOARD ============ -->
<section id="leaderboard" class="bg-base-200/50 py-12 sm:py-16 scroll-mt-16">
  <div class="max-w-3xl mx-auto px-4 lg:px-8">
    <h2 class="text-2xl sm:text-3xl font-bold text-center mb-2">লিডারবোর্ড</h2>
    <p class="text-center text-base-content/60 mb-10">টুর্নামেন্ট জুড়ে সর্বোচ্চ পয়েন্ট</p>

    <?php if (!$lbRows): ?>
      <div class="text-center py-10 rounded-box border border-dashed border-base-content/20 bg-base-100">
        <div class="text-5xl mb-3">🏁</div>
        <p class="font-semibold">এখনো কোনো ম্যাচের ফল আসেনি!</p>
        <p class="text-base-content/60 text-sm mt-1">প্রথম ম্যাচ শেষ হলেই পয়েন্ট যোগ হবে।</p>
        <a href="#register" class="btn btn-primary btn-sm mt-4">এখনই অনুমান করুন</a>
      </div>
    <?php else: ?>
    <div class="overflow-x-auto rounded-box border border-base-content/10 bg-base-100">
      <table class="table">
        <thead>
          <tr><th>র‍্যাঙ্ক</th><th>খেলোয়াড়</th><th>পয়েন্ট</th><th class="hidden sm:table-cell">হুবহু</th><th class="text-right">পুরস্কার</th></tr>
        </thead>
        <tbody>
          <?php foreach ($lbRows as $i => $row): $rank = $i + 1; ?>
          <tr class="<?= $rank <= 3 ? 'font-semibold' : '' ?>">
            <td>
              <?php if ($rank == 1): ?>🥇<?php elseif ($rank == 2): ?>🥈<?php elseif ($rank == 3): ?>🥉<?php else: ?><span class="text-base-content/50"><?= bn($rank) ?></span><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($row['display_name'] !== null && $row['display_name'] !== '' ? $row['display_name'] : $row['phone_masked'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="badge badge-primary badge-sm"><?= bn($row['points']) ?></span></td>
            <td class="hidden sm:table-cell text-base-content/70"><?= bn($row['exact_hits']) ?>🎯</td>
            <td class="text-right text-base-content/70"><?= htmlspecialchars($prizes[$i] ?? '৫০০ MB ডেটা', ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ============ FINAL CTA ============ -->
<section class="max-w-4xl mx-auto px-4 py-12 sm:py-16 text-center">
  <div class="card bg-gradient-to-r from-primary to-secondary text-primary-content">
    <div class="card-body items-center">
      <h2 class="text-2xl sm:text-3xl font-bold">পরের ম্যাচে কত গোল হবে?</h2>
      <p class="opacity-90">অনুমান করুন, পয়েন্ট জিতুন, লিডারবোর্ডের শীর্ষে উঠুন।</p>
      <a href="#register" class="btn btn-neutral btn-lg mt-2">এখনই শুরু করুন</a>
    </div>
  </div>
</section>

<script>
  // --- Endpoints (relative to docroot) ---
  // otp_send.php is the rate-limited wrapper around bdapps/send_otp.php, which
  // .htaccess denies directly. verify_otp.php is safe to call as shipped.
  const SEND_OTP_URL   = 'otp_send.php';
  const VERIFY_OTP_URL = 'bdapps/verify_otp.php';

  // referenceNo is the only thing we carry between steps. The phone number lives
  // in the PHP session (bound to this reference by send_otp.php) — the browser
  // never gets to tell register_user.php which number was verified.
  let referenceNo = null;

  const $ = (id) => document.getElementById(id);

  function showError(el, msg) {
    el.textContent = msg;
    el.classList.remove('hidden');
  }
  function hideError(el) { el.classList.add('hidden'); }

  function setLoading(btn, loading, label) {
    if (loading) {
      btn.dataset.label = btn.innerHTML;
      btn.innerHTML = '<span class="loading loading-spinner loading-sm"></span> ' + (label || '');
      btn.disabled = true;
    } else {
      btn.innerHTML = btn.dataset.label || btn.innerHTML;
      btn.disabled = false;
    }
  }

  // STEP 1 — request an OTP. Every entry into the app goes through this, whether
  // the number is already subscribed (a login) or not (a subscription). There is
  // deliberately no "already subscribed → straight in" shortcut: proving a number
  // is subscribed is not proving the person at the keyboard owns it.
  async function goToOtp() {
    const btn = $('btn-send');
    const errEl = $('err-phone');
    hideError(errEl);

    const phone = $('phone').value.trim();
    if (!/^01[3-9]\d{8}$/.test(phone)) {
      showError(errEl, 'সঠিক ১১-সংখ্যার মোবাইল নম্বর দিন (যেমন: 01812345678)');
      return;
    }

    setLoading(btn, true, 'অপেক্ষা করুন...');
    try {
      const res = await fetch(SEND_OTP_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ user_mobile: phone }),
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.referenceNo) {
        showError(errEl, data.message || data.statusDetail || data.error || 'OTP পাঠানো যায়নি। আবার চেষ্টা করুন।');
        return;
      }

      referenceNo = data.referenceNo;
      $('otp-sent-to').textContent = '+৮৮' + phone + ' নম্বরে কোড পাঠানো হয়েছে';
      $('step-phone').classList.add('hidden');
      $('step-otp').classList.remove('hidden');
      document.querySelector('.otp-box')?.focus();
    } catch (e) {
      showError(errEl, 'নেটওয়ার্ক সমস্যা। ইন্টারনেট সংযোগ দেখুন।');
    } finally {
      setLoading(btn, false);
    }
  }

  // STEP 2 — verify OTP. On success the server marks the bound number verified.
  async function verifyOtp() {
    const btn = $('btn-verify');
    const errEl = $('err-otp');
    hideError(errEl);

    const otp = Array.from(document.querySelectorAll('.otp-box')).map(b => b.value).join('');
    if (!/^\d{6}$/.test(otp)) {
      showError(errEl, '৬-সংখ্যার কোডটি সম্পূর্ণ লিখুন');
      return;
    }
    if (!referenceNo) {
      showError(errEl, 'সেশন মেয়াদোত্তীর্ণ। আবার OTP নিন।');
      return;
    }

    setLoading(btn, true, 'যাচাই হচ্ছে...');
    try {
      const res = await fetch(VERIFY_OTP_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ Otp: otp, referenceNo }),
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.subscriptionStatus) {
        showError(errEl, data.statusDetail || data.message || data.error || 'ভুল বা মেয়াদোত্তীর্ণ OTP।');
        return;
      }

      // The OTP is good: promote the verified number into a real session now.
      // register_user.php tells us whether this phone already had an account.
      const reg = await fetch('register_user.php', { method: 'POST' });
      const rdata = await reg.json().catch(() => ({}));

      if (!reg.ok || !rdata.ok) {
        showError(errEl, rdata.error || 'সেশন তৈরি করা যায়নি। আবার চেষ্টা করুন।');
        return;
      }

      // Returning player → straight in. They already have a name on file.
      if (rdata.returning) {
        window.location.href = '/';
        return;
      }

      $('step-otp').classList.add('hidden');
      $('step-name').classList.remove('hidden');
      $('display-name').focus();
    } catch (e) {
      showError(errEl, 'নেটওয়ার্ক সমস্যা। আবার চেষ্টা করুন।');
    } finally {
      setLoading(btn, false);
    }
  }

  // STEP 3 — save name (or skip) → create user + session, then show success
  async function saveName(skip = false) {
    const btn = $('btn-name');
    const errEl = $('err-name');
    hideError(errEl);

    const name = skip ? '' : $('display-name').value.trim();

    setLoading(btn, true, 'অপেক্ষা করুন...');
    try {
      const res = await fetch('register_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ display_name: name }),
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.ok) {
        showError(errEl, data.error || 'সংরক্ষণ ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
        return;
      }

      $('welcome-name').textContent = data.display_name ? ' ' + data.display_name : '';
      $('step-name').classList.add('hidden');
      $('step-done').classList.remove('hidden');
    } catch (e) {
      showError(errEl, 'নেটওয়ার্ক সমস্যা। আবার চেষ্টা করুন।');
    } finally {
      setLoading(btn, false);
    }
  }

  function resetReg() {
    hideError($('err-otp'));
    referenceNo = null;
    document.querySelectorAll('.otp-box').forEach(b => (b.value = ''));
    $('step-otp').classList.add('hidden');
    $('step-phone').classList.remove('hidden');
    $('phone').focus();
  }

  // OTP boxes: auto-advance, backspace-to-previous, submit on Enter
  document.querySelectorAll('.otp-box').forEach((box, i, arr) => {
    box.addEventListener('input', () => {
      box.value = box.value.replace(/\D/g, '');       // digits only
      if (box.value && arr[i + 1]) arr[i + 1].focus();
    });
    box.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !box.value && arr[i - 1]) arr[i - 1].focus();
      if (e.key === 'Enter') verifyOtp();
    });
  });

  // Submit phone step on Enter (absent when already logged in)
  $('phone')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') goToOtp(); });
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
