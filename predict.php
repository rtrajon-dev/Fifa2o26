<?php
// Ensure sessions persist on shared hosting (cPanel)
if (php_sapi_name() !== 'cli') {
    ini_set('session.save_path', __DIR__ . '/sessions');
    if (!is_dir(__DIR__ . '/sessions')) {
        @mkdir(__DIR__ . '/sessions', 0755, true);
    }
}
session_start();

// --- gate: only registered players ---
if (empty($_SESSION['phone'])) {
    header('Location: /#register');
    exit;
}

$pageTitle = 'গোল অনুমান — Fifa2026';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/navbar.php';
?>

<section class="max-w-3xl mx-auto px-4 py-6 sm:py-10">

  <div class="text-center mb-8">
    <h1 class="text-2xl sm:text-3xl font-bold">আসন্ন ম্যাচের গোল অনুমান করো</h1>
    <p class="text-base-content/60 mt-2">
      ম্যাচে <strong>মোট কত গোল</strong> হবে বেছে নাও। কিক-অফের আগ পর্যন্ত পরিবর্তন করতে পারবে।
    </p>
    <div class="flex flex-wrap justify-center gap-2 mt-4 text-sm">
      <span class="badge badge-success badge-outline">হুবহু মিললে +৩ পয়েন্ট</span>
      <span class="badge badge-warning badge-outline">১ গোলের ব্যবধানে +১ পয়েন্ট</span>
    </div>
  </div>

  <div id="loading" class="text-center py-16">
    <span class="loading loading-spinner loading-lg text-primary"></span>
    <p class="text-base-content/50 mt-3">ম্যাচ লোড হচ্ছে…</p>
  </div>

  <div id="empty" class="hidden text-center py-16 rounded-box border border-dashed border-base-content/20 bg-base-100">
    <div class="text-5xl mb-3">📅</div>
    <p class="font-semibold">এখন কোনো ম্যাচ নেই</p>
    <p class="text-base-content/60 text-sm mt-1">নতুন ম্যাচ যোগ হলে এখানে দেখা যাবে।</p>
  </div>

  <div id="matches" class="space-y-4"></div>

  <div class="text-center mt-10">
    <a href="/#leaderboard" class="btn btn-outline">লিডারবোর্ড দেখো →</a>
  </div>
</section>

<script>
  const API = 'predict_api.php';
  const $ = (id) => document.getElementById(id);
  const bn = (n) => String(n).replace(/[0-9]/g, d => '০১২৩৪৫৬৭৮৯'[d]);

  let GOAL_MAX = 5;

  async function api(action, body) {
    const res = await fetch(API + '?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body ? new URLSearchParams(body) : '',
    });
    if (res.status === 403) { window.location.href = '/#register'; return null; }
    return res.json().catch(() => ({}));
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
  }

  // Time until kickoff, in Bengali. Returns '' once kickoff has passed.
  function countdown(kickoffIso) {
    const diff = new Date(kickoffIso.replace(' ', 'T')).getTime() - Date.now();
    if (diff <= 0) return '';
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    if (h >= 24) return bn(Math.floor(h / 24)) + ' দিন বাকি';
    if (h >= 1)  return bn(h) + ' ঘণ্টা ' + bn(m) + ' মিনিট বাকি';
    return bn(m) + ' মিনিট বাকি';
  }

  function goalLabel(n) {
    return n >= GOAL_MAX ? bn(n) + '+' : bn(n);
  }

  function statusBadge(m) {
    if (m.finished) return '<span class="badge badge-neutral">শেষ</span>';
    if (!m.confirmed) return '<span class="badge badge-ghost">দল নিশ্চিত হয়নি</span>';
    if (!m.open) return '<span class="badge badge-error badge-outline">বন্ধ</span>';
    const c = countdown(m.kickoff);
    return '<span class="badge badge-success badge-outline">' + (c || 'খোলা') + '</span>';
  }

  // The result strip shown after settlement: what really happened, what you said.
  function resultRow(m) {
    if (!m.finished) return '';
    const total = m.result.total;
    const pts = m.my_points;
    let verdict;
    if (m.my_pick === null) {
      verdict = '<span class="text-base-content/50">তুমি অনুমান করোনি</span>';
    } else if (pts >= 3) {
      verdict = '<span class="text-success font-semibold">🎯 হুবহু মিলেছে! +' + bn(pts) + ' পয়েন্ট</span>';
    } else if (pts >= 1) {
      verdict = '<span class="text-warning font-semibold">কাছাকাছি! +' + bn(pts) + ' পয়েন্ট</span>';
    } else {
      verdict = '<span class="text-base-content/50">তোমার অনুমান ' + goalLabel(m.my_pick) + ' — মেলেনি</span>';
    }
    return '<div class="mt-4 pt-4 border-t border-base-content/10 flex flex-wrap items-center justify-between gap-2 text-sm">'
         + '<span>ফলাফল: <strong>' + bn(m.result.home) + ' – ' + bn(m.result.away) + '</strong>'
         + ' <span class="text-base-content/50">(মোট ' + bn(total) + ' গোল)</span></span>'
         + verdict + '</div>';
  }

  function goalButtons(m) {
    if (!m.open) {
      // Locked or TBD: show the pick read-only rather than a dead button row.
      if (m.my_pick === null) return '<p class="text-sm text-base-content/50 mt-3">কোনো অনুমান নেই</p>';
      if (m.finished) return '';
      return '<p class="text-sm mt-3">তোমার অনুমান: <span class="badge badge-primary">'
           + goalLabel(m.my_pick) + ' গোল</span></p>';
    }
    let html = '<div class="grid grid-cols-6 gap-2 mt-4">';
    for (let g = 0; g <= GOAL_MAX; g++) {
      const on = m.my_pick === g;
      html += '<button data-code="' + esc(m.code) + '" data-goals="' + g + '"'
            + ' class="pick btn ' + (on ? 'btn-primary' : 'btn-outline') + ' btn-lg h-auto min-h-12 py-2 px-0 text-lg">'
            + goalLabel(g) + '</button>';
    }
    html += '</div><p class="text-xs text-base-content/40 mt-2 text-center">ম্যাচে মোট কত গোল হবে?</p>';
    return html;
  }

  function card(m) {
    const teams = m.confirmed
      ? esc(m.home) + ' <span class="text-base-content/40 mx-1">vs</span> ' + esc(m.away)
      : '<span class="text-base-content/40">দল ঘোষণা হয়নি</span>';

    return '<div class="card bg-base-200 border border-base-content/10 shadow-sm">'
      + '<div class="card-body p-5">'
      +   '<div class="flex items-start justify-between gap-3">'
      +     '<div>'
      +       '<div class="text-xs text-base-content/50">' + esc(m.label) + '</div>'
      +       '<h3 class="text-lg sm:text-xl font-semibold mt-0.5">' + teams + '</h3>'
      +       '<div class="text-xs text-base-content/50 mt-1">🕐 ' + esc(m.kickoff_bn)
      +         (m.venue ? ' · 📍 ' + esc(m.venue) : '') + '</div>'
      +     '</div>'
      +     statusBadge(m)
      +   '</div>'
      +   goalButtons(m)
      +   resultRow(m)
      + '</div></div>';
  }

  async function savePick(btn) {
    const code = btn.dataset.code;
    const goals = btn.dataset.goals;

    // Optimistic highlight within this card only.
    const row = btn.parentElement;
    row.querySelectorAll('.pick').forEach(b => {
      b.classList.remove('btn-primary');
      b.classList.add('btn-outline');
    });
    btn.classList.remove('btn-outline');
    btn.classList.add('btn-primary');
    row.querySelectorAll('.pick').forEach(b => (b.disabled = true));

    const res = await api('save', { code, goals });
    row.querySelectorAll('.pick').forEach(b => (b.disabled = false));

    if (!res) return;
    if (res.error) {
      // The server refused — most likely kickoff passed while the page sat open.
      alert(res.message || 'অনুমান সংরক্ষণ করা যায়নি।');
      load();
    }
  }

  async function load() {
    const data = await api('list');
    if (!data) return;

    GOAL_MAX = data.goal_max ?? 5;
    $('loading').classList.add('hidden');

    const matches = data.matches || [];
    if (!matches.length) {
      $('empty').classList.remove('hidden');
      return;
    }
    $('empty').classList.add('hidden');
    $('matches').innerHTML = matches.map(card).join('');
    document.querySelectorAll('.pick').forEach(b => (b.onclick = () => savePick(b)));
  }

  load();
  // Refresh every minute so countdowns tick and a match visibly locks at kickoff.
  setInterval(load, 60000);
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
