<?php
// Shared <head>. Pass $pageTitle before including.
$pageTitle = $pageTitle ?? 'GoalJeeto — গোল অনুমান করুন, জিতুন';
?>
<!DOCTYPE html>
<html lang="bn" data-theme="night">
<script>
  // Apply saved theme before paint (no flash). Default = night (dark).
  (function () {
    var t = localStorage.getItem('gj-theme') || 'night';
    document.documentElement.setAttribute('data-theme', t);
  })();
</script>
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="GoalJeeto — ফিফা বিশ্বকাপ ২০২৬-এর ম্যাচে কত গোল হবে অনুমান করুন। সঠিক অনুমানে সেরাদের জন্য পুরস্কার। রবি ও এয়ারটেল গ্রাহকদের জন্য।" />
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <!-- Bengali font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Tailwind (Play CDN — prototyping only; swap for a Vite build before production) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- DaisyUI components -->
  <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" />

  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['"Hind Siliguri"', 'sans-serif'] },
        },
      },
    };
  </script>
  <style>
    :root { font-family: "Hind Siliguri", sans-serif; }
    body { font-family: "Hind Siliguri", sans-serif; }
    /* Brand accent overrides on top of the complete built-in "night" theme.
       night already defines every DaisyUI variable, so overriding a few
       colors here is safe and won't leave anything undefined.
       Pitch green + gold, rather than QuizJeeto's violet. */
    [data-theme="night"], [data-theme="light"] {
      --p: 152 69% 38%;    /* primary  — pitch green */
      --pf: 152 69% 28%;   /* primary-focus         */
      --pc: 0 0% 100%;     /* primary-content       */
      --s: 43 96% 52%;     /* secondary — trophy gold */
      --sf: 43 96% 42%;
      --sc: 0 0% 10%;
      --a: 199 89% 52%;    /* accent   — sky        */
      --af: 199 89% 42%;
      --ac: 0 0% 100%;
    }
  </style>
</head>
<body class="bg-base-100 text-base-content min-h-screen">
