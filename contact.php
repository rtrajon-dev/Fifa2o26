<?php
session_start();
$pageTitle = 'যোগাযোগ — GoalJeeto';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/navbar.php';
?>

<section class="max-w-2xl mx-auto px-4 py-8 sm:py-12">
  <h1 class="text-3xl font-bold mb-2">যোগাযোগ</h1>
  <p class="text-base-content/60 mb-8">যেকোনো সাহায্য, প্রশ্ন বা অভিযোগের জন্য আমাদের সাথে যোগাযোগ করুন।</p>

  <div class="grid gap-4">
    <div class="card bg-base-200 border border-base-content/10">
      <div class="card-body flex-row items-center gap-4">
        <div class="text-3xl">📧</div>
        <div>
          <div class="font-semibold">ইমেইল</div>
          <a href="mailto:patawise.dev@gmail.com" class="link link-primary">patawise.dev@gmail.com</a>
        </div>
      </div>
    </div>

    <div class="card bg-base-200 border border-base-content/10">
      <div class="card-body flex-row items-center gap-4">
        <div class="text-3xl">🏢</div>
        <div>
          <div class="font-semibold">সেবা প্রদানকারী</div>
          <div class="text-base-content/70">Patawise</div>
        </div>
      </div>
    </div>

    <div class="card bg-base-200 border border-base-content/10">
      <div class="card-body flex-row items-center gap-4">
        <div class="text-3xl">🌐</div>
        <div>
          <div class="font-semibold">প্ল্যাটফর্ম</div>
          <div class="text-base-content/70">ওয়েব অ্যাপ — যেকোনো ব্রাউজারে চলে</div>
        </div>
      </div>
    </div>
  </div>

  <p class="text-sm text-base-content/50 mt-8">
    সাবস্ক্রিপশন ও চার্জিং সংক্রান্ত সমস্যার জন্য bdapps সাপোর্ট:
    <a href="mailto:support@bdapps.com" class="link">support@bdapps.com</a>
  </p>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
