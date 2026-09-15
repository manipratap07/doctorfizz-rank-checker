<?php
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));
/** @var string $bodyHtml */
/** @var string $pageTitle */
/** @var string $current */
$nav = [
    ''         => 'Dashboard',
    'content'  => 'Content',
    'settings' => 'Settings',
    'bans'     => 'Blocks',
];
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> | DoctorFizz admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Spline+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/admin.r11.css')) ?>">
</head>
<body>
<header class="a-head">
  <div class="a-wrap">
    <img src="<?= e(asset('img/doctorfizz-white.png')) ?>" alt="DoctorFizz" width="150" height="26">
    <nav aria-label="Admin">
      <ul class="a-nav">
        <?php foreach ($nav as $slug => $label): ?>
          <li><a href="<?= e(admin_url($slug)) ?>"<?= $current === $slug ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <li><a href="<?= e(url()) ?>" target="_blank" rel="noopener">View site</a></li>
        <li><a href="<?= e(admin_url('logout')) ?>">Sign out</a></li>
      </ul>
    </nav>
  </div>
</header>
<main class="a-wrap">
<?= $bodyHtml ?>
</main>
</body>
</html>
