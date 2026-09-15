<?php
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));
/** @var string $csrf */
/** @var array|null $flash */
/** @var array $bans */
/** @var array $heavy */

$banned = array_column($bans, 'visitor');

$pageTitle = 'Blocks';
$current   = 'bans';
ob_start();
?>
<h1 class="a-title">Blocks</h1>
<p class="a-sub">Visitors are identified by a one way hash of their IP address and a signed cookie. No IP address is stored anywhere, so a block is on the hash, not on a person.</p>

<?php if ($flash !== null): ?><p class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif; ?>

<div class="box">
  <h2>Heaviest users, last 7 days</h2>
  <p class="hint">A daily limit already caps everyone. Block only when someone is clearly cycling identities or scripting the endpoint.</p>
  <div class="tbl-scroll">
    <table class="t">
      <thead><tr><th>Visitor hash</th><th>Checks</th><th>Last seen</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($heavy as $r): ?>
        <?php $v = (string) $r['visitor']; ?>
        <tr>
          <td><code class="mono"><?= e(substr($v, 0, 16)) ?></code></td>
          <td class="num"><?= (int) $r['n'] ?></td>
          <td class="num"><?= e(gmdate('d M H:i', (int) $r['last_seen'])) ?></td>
          <td>
            <?php if (in_array($v, $banned, true)): ?>
              <span class="pill no">Blocked</span>
            <?php else: ?>
              <form method="post" action="<?= e(admin_url('bans')) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="ban">
                <input type="hidden" name="visitor" value="<?= e($v) ?>">
                <input type="hidden" name="reason" value="Excessive use">
                <button type="submit" class="btn small ghost">Block</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($heavy === []): ?><tr><td colspan="4">No activity in the last 7 days.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="box">
  <h2>Current blocks</h2>
  <div class="tbl-scroll">
    <table class="t">
      <thead><tr><th>Visitor hash</th><th>Reason</th><th>Blocked</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($bans as $r): ?>
        <tr>
          <td><code class="mono"><?= e(substr((string) $r['visitor'], 0, 16)) ?></code></td>
          <td><?= e((string) $r['reason']) ?></td>
          <td class="num"><?= e(gmdate('d M Y', (int) $r['created'])) ?></td>
          <td>
            <form method="post" action="<?= e(admin_url('bans')) ?>">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="unban">
              <input type="hidden" name="visitor" value="<?= e((string) $r['visitor']) ?>">
              <button type="submit" class="btn small ghost">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bans === []): ?><tr><td colspan="4">Nobody is blocked.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$bodyHtml = ob_get_clean();
require __DIR__ . '/admin_layout.php';
