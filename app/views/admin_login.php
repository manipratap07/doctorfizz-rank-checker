<?php
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));
/** @var string $csrf */
/** @var array|null $flash */
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in | DoctorFizz admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Spline+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/admin.r11.css')) ?>">
</head>
<body>
<div class="login-shell">
  <div class="login-card">
    <img src="<?= e(asset('img/doctorfizz-black.png')) ?>" alt="DoctorFizz" width="170" height="29">
    <h1>Sign in</h1>
    <p class="a-sub">Rank checker admin</p>

    <?php if ($flash !== null): ?>
      <p class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(admin_url()) ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label class="f">
        <span>Username</span>
        <input type="text" name="username" autocomplete="username" required autofocus>
      </label>
      <label class="f">
        <span>Password</span>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit" class="btn">Sign in</button>
    </form>
  </div>
</div>
</body>
</html>
