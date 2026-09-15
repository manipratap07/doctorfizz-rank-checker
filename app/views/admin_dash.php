<?php
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));
/** @var array $daily */
$peak = max(1, max($daily));
$budgetPct = $budgetMax > 0 ? (int) round(100 * $budgetUsed / $budgetMax) : 0;
$pageTitle = 'Dashboard';
$current   = '';
ob_start();
?>
<h1 class="a-title">Dashboard</h1>
<p class="a-sub">Signed in as <?= e($actor) ?>. Figures are UTC and cover today unless stated.</p>

<?php if ($flash !== null): ?><p class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif; ?>

<div class="cards">
  <div class="card">
    <p class="k">Checks today</p>
    <p class="v"><?= (int) $checksToday ?></p>
    <p class="n"><?= (int) $visitorsToday ?> distinct visitors</p>
  </div>
  <div class="card<?= $budgetPct >= 80 ? ' alert' : '' ?>">
    <p class="k">API budget</p>
    <p class="v"><?= (int) $budgetUsed ?><span style="font-size:1rem;color:var(--ink-3)">/<?= (int) $budgetMax ?></span></p>
    <p class="n"><?= $budgetPct ?> percent used, resets at midnight UTC</p>
  </div>
  <div class="card">
    <p class="k">Found rate</p>
    <p class="v"><?= (int) $foundRate ?>%</p>
    <p class="n">Checks that located the domain, 14 days</p>
  </div>
  <div class="card">
    <p class="k">Static page</p>
    <p class="v"><?= $pageSize > 0 ? number_format($pageSize / 1024, 0) : 0 ?><span style="font-size:1rem;color:var(--ink-3)"> KB</span></p>
    <p class="n"><?= $pageBuilt > 0 ? 'Built ' . e(gmdate('d M H:i', $pageBuilt)) . ' UTC' : 'Never built' ?></p>
  </div>
  <div class="card">
    <p class="k">Cache</p>
    <p class="v"><?= (int) $cacheRows ?></p>
    <p class="n">Stored results, database <?= number_format($dbSize / 1024, 0) ?> KB</p>
  </div>
</div>

<div class="box">
  <h2>System check</h2>
  <p class="hint">Everything that must be true for the public checker to run. If the page says "Checker unavailable", the reason is on this list.</p>
  <div class="tbl-scroll">
    <table class="t">
      <tbody>
      <?php foreach ($system as $row): ?>
        <tr>
          <td style="width:34px"><span class="pill <?= $row[1] ? 'ok' : 'no' ?>"><?= $row[1] ? 'OK' : 'NO' ?></span></td>
          <th scope="row"><?= e($row[0]) ?></th>
          <td><code class="mono"><?= e((string) $row[2]) ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" action="<?= e(admin_url('test')) ?>" style="margin-top:16px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button type="submit" class="btn ghost">Run a live provider test</button>
  </form>
  <p class="hint" style="margin:10px 0 0">The test runs one real search and reports the exact upstream error. It spends one API call.</p>
</div>

<div class="box rebuild-row">
  <div>
    <h2>Public page</h2>
    <p class="hint">The page visitors see is a static HTML file, so it runs no PHP. Saving content or settings rebuilds it automatically. Rebuild by hand if you have edited a template or an asset. Provider in use: <?= e($provider) ?>.</p>
  </div>
  <form method="post" action="<?= e(admin_url('rebuild')) ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button type="submit" class="btn">Rebuild the page</button>
  </form>
</div>

<div class="box">
  <h2>Checks per day, last 14 days</h2>
  <div class="spark" role="img" aria-label="Daily check volume for the last 14 days, peak <?= (int) $peak ?>">
    <?php foreach ($daily as $day => $n): ?>
      <div style="height:<?= max(2, (int) round(100 * $n / $peak)) ?>%" title="<?= e($day) ?>: <?= (int) $n ?>"></div>
    <?php endforeach; ?>
  </div>
  <div class="spark-x">
    <span><?= e(array_key_first($daily)) ?></span>
    <span>Peak <?= (int) $peak ?></span>
    <span><?= e(array_key_last($daily)) ?></span>
  </div>
</div>

<div class="grid-2">
  <div class="box">
    <h2>Top keywords, 14 days</h2>
    <div class="tbl-scroll">
      <table class="t">
        <thead><tr><th>Keyword</th><th>Checks</th></tr></thead>
        <tbody>
        <?php foreach ($topKeywords as $r): ?>
          <tr><td><?= e((string) $r['keyword']) ?></td><td class="num"><?= (int) $r['n'] ?></td></tr>
        <?php endforeach; ?>
        <?php if ($topKeywords === []): ?><tr><td colspan="2">No checks recorded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="box">
    <h2>Top domains, 14 days</h2>
    <div class="tbl-scroll">
      <table class="t">
        <thead><tr><th>Domain</th><th>Checks</th></tr></thead>
        <tbody>
        <?php foreach ($topDomains as $r): ?>
          <tr><td><?= e((string) $r['domain']) ?></td><td class="num"><?= (int) $r['n'] ?></td></tr>
        <?php endforeach; ?>
        <?php if ($topDomains === []): ?><tr><td colspan="2">No checks recorded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="box">
  <h2>Recent checks</h2>
  <div class="tbl-scroll">
    <table class="t">
      <thead><tr><th>Time</th><th>Keyword</th><th>Domain</th><th>Market</th><th>Position</th><th>Calls</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td class="num"><?= e(gmdate('d M H:i', (int) $r['ts'])) ?></td>
          <td><?= e((string) $r['keyword']) ?></td>
          <td><?= e((string) $r['domain']) ?></td>
          <td><?= e(strtoupper((string) $r['gl'])) ?> / <?= e((string) $r['hl']) ?></td>
          <td class="num">
            <?php if ((int) $r['found'] === 1): ?>
              <span class="pill ok">#<?= (int) $r['position'] ?></span>
            <?php else: ?>
              <span class="pill no">Not found</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int) $r['api_calls'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($recent === []): ?><tr><td colspan="6">No checks recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="box">
  <h2>Recent admin activity</h2>
  <div class="tbl-scroll">
    <table class="t">
      <thead><tr><th>Time</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($audit as $r): ?>
        <tr>
          <td class="num"><?= e(gmdate('d M H:i', (int) $r['ts'])) ?></td>
          <td><?= e((string) $r['actor']) ?></td>
          <td><code class="mono"><?= e((string) $r['action']) ?></code></td>
          <td><?= e((string) $r['meta']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($audit === []): ?><tr><td colspan="4">Nothing logged yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$bodyHtml = ob_get_clean();
require __DIR__ . '/admin_layout.php';
