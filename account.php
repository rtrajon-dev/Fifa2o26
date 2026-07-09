<?php
// Ensure sessions persist on shared hosting (cPanel)
if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/sessions');
    if (!is_dir(__DIR__ . '/sessions')) {
        @mkdir(__DIR__ . '/sessions', 0755, true);
    }
}
session_start();

// --- gate: only logged-in players ---
if (empty($_SESSION['phone'])) {
    header('Location: /#register');
    exit;
}

require_once __DIR__ . '/db.php';

$pageTitle = 'আমার অ্যাকাউন্ট — GoalJeeto';
$phone   = $_SESSION['phone'];
$display = $_SESSION['display'] ?? (substr($phone, 0, 3) . '•••' . substr($phone, -3));

// My predictions, newest kickoff first. Points only appear once settled.
$myPreds = [];
$myPoints = 0;
$myExact  = 0;
try {
    $uid = $_SESSION['user_id'] ?? upsert_user(db(), $phone, $_SESSION['display_name'] ?? '');
    if ($uid) {
        $_SESSION['user_id'] = $uid;
        $stmt = db()->prepare(
            'SELECT m.label, m.home_team, m.away_team, m.kickoff_at, m.status,
                    m.home_goals, m.away_goals,
                    p.predicted_goals, p.points, p.is_settled
               FROM predictions p
               JOIN matches m ON m.id = p.match_id
              WHERE p.user_id = ?
              ORDER BY m.kickoff_at DESC'
        );
        $stmt->execute([$uid]);
        $myPreds = $stmt->fetchAll();

        foreach ($myPreds as $p) {
            if ($p['is_settled']) {
                $myPoints += (int) $p['points'];
                if ((int) $p['points'] === PTS_EXACT) {
                    $myExact++;
                }
            }
        }
    }
} catch (Throwable $e) {
    // A DB hiccup must not lock the player out of their own account page.
}

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/navbar.php';
?>

<section class="max-w-xl mx-auto px-4 py-8 sm:py-12">
  <h1 class="text-2xl sm:text-3xl font-bold mb-6">আমার অ্যাকাউন্ট</h1>

  <!-- Profile -->
  <div class="card bg-base-200 border border-base-content/10 shadow-sm mb-6">
    <div class="card-body">
      <div class="flex items-center gap-4">
        <div class="text-4xl">👤</div>
        <div>
          <div class="font-semibold text-lg"><?= htmlspecialchars($display, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="text-sm text-base-content/60"><?= htmlspecialchars(substr($phone, 0, 3) . '•••' . substr($phone, -3), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>
      <div class="stats stats-horizontal bg-base-100 mt-4">
        <div class="stat place-items-center py-3">
          <div class="stat-title text-xs">মোট পয়েন্ট</div>
          <div class="stat-value text-2xl text-primary"><?= bn($myPoints) ?></div>
        </div>
        <div class="stat place-items-center py-3">
          <div class="stat-title text-xs">হুবহু সঠিক</div>
          <div class="stat-value text-2xl"><?= bn($myExact) ?></div>
        </div>
        <div class="stat place-items-center py-3">
          <div class="stat-title text-xs">অনুমান</div>
          <div class="stat-value text-2xl"><?= bn(count($myPreds)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- My predictions -->
  <div class="card bg-base-200 border border-base-content/10 shadow-sm mb-6">
    <div class="card-body">
      <h2 class="card-title text-lg">আমার অনুমান</h2>
      <?php if (!$myPreds): ?>
        <p class="text-sm text-base-content/60">এখনো কোনো অনুমান করোনি।</p>
        <a href="/predict.php" class="btn btn-primary btn-sm mt-2 w-fit">এখনই শুরু করো →</a>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="table table-sm">
          <thead><tr><th>ম্যাচ</th><th>অনুমান</th><th>ফল</th><th class="text-right">পয়েন্ট</th></tr></thead>
          <tbody>
          <?php foreach ($myPreds as $p):
            $pick = (int) $p['predicted_goals'];
            $pickLabel = $pick >= GOAL_MAX ? bn($pick) . '+' : bn($pick);
            $settled = (bool) $p['is_settled'];
          ?>
            <tr>
              <td>
                <div class="truncate max-w-[10rem]"><?= htmlspecialchars($p['home_team'] . ' vs ' . $p['away_team'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-xs text-base-content/50"><?= htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td><span class="badge badge-ghost badge-sm"><?= $pickLabel ?></span></td>
              <td>
                <?php if ($settled): ?>
                  <?= bn((int) $p['home_goals']) ?>–<?= bn((int) $p['away_goals']) ?>
                  <span class="text-xs text-base-content/50">(<?= bn((int) $p['home_goals'] + (int) $p['away_goals']) ?>)</span>
                <?php else: ?>
                  <span class="text-base-content/40 text-xs">অপেক্ষমাণ</span>
                <?php endif; ?>
              </td>
              <td class="text-right">
                <?php if (!$settled): ?>
                  <span class="text-base-content/30">—</span>
                <?php elseif ((int) $p['points'] > 0): ?>
                  <span class="badge badge-success badge-sm">+<?= bn((int) $p['points']) ?></span>
                <?php else: ?>
                  <span class="text-base-content/40">০</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Subscription status -->
  <div class="card bg-base-200 border border-base-content/10 shadow-sm mb-6">
    <div class="card-body">
      <h2 class="card-title text-lg">সাবস্ক্রিপশন স্ট্যাটাস</h2>
      <p class="text-sm text-base-content/60">প্রতিদিন ২.৭৮ টাকা + (ভ্যাট + সম্পূরক শুল্ক + সার্ভিস চার্জ), অটো-রিনিউয়াল। শুধুমাত্র রবি ও এয়ারটেল গ্রাহকদের জন্য।</p>
      <div id="status-box" class="mt-2 text-sm">
        <span class="badge badge-ghost">যাচাই করা হয়নি</span>
      </div>
      <div class="card-actions mt-3">
        <button id="btn-status" onclick="checkStatus()" class="btn btn-outline btn-sm">স্ট্যাটাস চেক করুন</button>
      </div>
    </div>
  </div>

  <!-- Actions -->
  <div class="card bg-base-200 border border-base-content/10 shadow-sm">
    <div class="card-body gap-3">
      <a href="/predict.php" class="btn btn-primary">গোল অনুমান করুন →</a>
      <a href="/logout.php" class="btn btn-ghost">লগআউট</a>
      <p id="err" class="text-error text-sm hidden"></p>
    </div>
  </div>
</section>

<script>
  const $ = (id) => document.getElementById(id);
  const PHONE = '<?= htmlspecialchars($phone, ENT_QUOTES, "UTF-8") ?>';   // logged-in user's own number

  function setLoading(btn, on, label) {
    if (on) { btn.dataset.label = btn.innerHTML; btn.innerHTML = '<span class="loading loading-spinner loading-sm"></span> ' + (label || ''); btn.disabled = true; }
    else { btn.innerHTML = btn.dataset.label || btn.innerHTML; btn.disabled = false; }
  }

  async function checkStatus() {
    const btn = $('btn-status');
    setLoading(btn, true, 'যাচাই হচ্ছে...');
    try {
      const res = await fetch('bdapps/check_subscription.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ user_mobile: PHONE }),
      });
      const data = await res.json().catch(() => ({}));
      const box = $('status-box');
      if (data.isSubscribed) {
        box.innerHTML = '<span class="badge badge-success">সক্রিয় (Subscribed)</span>';
      } else if (data.subscriptionStatus) {
        box.innerHTML = '<span class="badge badge-warning">নিষ্ক্রিয় (Unsubscribed)</span>';
      } else {
        box.innerHTML = '<span class="badge badge-ghost">স্ট্যাটাস জানা যায়নি</span>';
      }
    } catch (e) {
      $('err').textContent = 'নেটওয়ার্ক সমস্যা। আবার চেষ্টা করুন।';
      $('err').classList.remove('hidden');
    } finally {
      setLoading(btn, false);
    }
  }
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
