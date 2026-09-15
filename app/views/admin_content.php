<?php
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));
/** @var string $csrf */
/** @var array|null $flash */
/** @var string $actor */

$groups = [
    'Search listing' => ['meta_title', 'meta_description', 'og_alt'],
    'Hero'           => ['hero_eyebrow', 'hero_h1', 'hero_deck', 'form_note'],
    'What it is'     => ['answer_h2', 'answer_body'],
    'How it works'   => ['how_h2', 'how_body', 'honesty_h3', 'honesty_body'],
    'Why ranks differ' => ['differ_h2', 'differ_body'],
    'What to do next'  => ['next_h2', 'next_body'],
    'Daily limit'      => ['limit_h2', 'limit_body'],
    'Beyond rank checking' => ['beyond_h2', 'beyond_body', 'beyond_note'],
    'Call to action'   => ['cta_h2', 'cta_body', 'cta_button', 'cta_url'],
    'Parent company'   => ['parent_h2', 'parent_body'],
    'Footer'           => ['author_line', 'footer_note'],
];

$long = static fn(string $k): bool =>
    str_ends_with($k, '_body') || str_ends_with($k, '_note') || $k === 'meta_description' || $k === 'author_line';

$label = static fn(string $k): string => ucfirst(str_replace('_', ' ', $k));

$pageTitle = 'Content';
$current   = 'content';
ob_start();
?>
<h1 class="a-title">Content</h1>
<p class="a-sub">Everything on the public page. Blank lines start a new paragraph, lines starting with "- " become bullets. Nothing else is interpreted, and em dashes and en dashes are replaced automatically on save.</p>

<?php if ($flash !== null): ?><p class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif; ?>

<form method="post" action="<?= e(admin_url('content')) ?>">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

  <?php foreach ($groups as $groupName => $keys): ?>
    <div class="box">
      <h2><?= e($groupName) ?></h2>
      <?php foreach ($keys as $k): ?>
        <label class="f">
          <span><?= e($label($k)) ?></span>
          <?php if ($long($k)): ?>
            <textarea name="<?= e($k) ?>" rows="<?= str_ends_with($k, '_body') ? 8 : 3 ?>"><?= e(App::content($k)) ?></textarea>
          <?php else: ?>
            <input type="text" name="<?= e($k) ?>" value="<?= e(App::content($k)) ?>" maxlength="300">
          <?php endif; ?>
          <?php if ($k === 'meta_title'): ?><small>Aim for 50 to 60 characters. Currently <?= mb_strlen(App::content($k)) ?>.</small><?php endif; ?>
          <?php if ($k === 'meta_description'): ?><small>Aim for about 150 characters. Currently <?= mb_strlen(App::content($k)) ?>.</small><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="box">
    <h2>Frequently asked questions</h2>
    <p class="hint">These render on the page and are also published as FAQPage structured data. Leave a pair blank to delete it.</p>
    <input type="hidden" name="faq_json" value="1">
    <?php $faq = Content::faq(); ?>
    <?php for ($i = 0; $i < count($faq) + 2; $i++): ?>
      <?php $q = $faq[$i][0] ?? ''; $a = $faq[$i][1] ?? ''; ?>
      <div class="f-row">
        <label class="f">
          <span>Question <?= $i + 1 ?></span>
          <input type="text" name="faq_q[]" value="<?= e($q) ?>" maxlength="200">
        </label>
        <label class="f">
          <span>Answer <?= $i + 1 ?></span>
          <textarea name="faq_a[]" rows="3" maxlength="1200"><?= e($a) ?></textarea>
        </label>
      </div>
    <?php endfor; ?>
  </div>

  <div class="sticky-save">
    <button type="submit" class="btn">Save content</button>
    <a class="btn ghost" href="<?= e(url()) ?>" target="_blank" rel="noopener">Preview the page</a>
    <p>Changes go live immediately.</p>
  </div>
</form>
<?php
$bodyHtml = ob_get_clean();
require __DIR__ . '/admin_layout.php';
