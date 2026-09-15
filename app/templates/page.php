<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * Template for the generated public page, release r12.
 *
 * Design language is unchanged doctorfizz.com: Manrope and Fira Code, a rust to gold ramp on
 * near black, all caps compound headings split across two tones. r12 rebuilds the layout on a
 * ruled grid, gives every section a mono rule label, adds the position bands and the limits
 * sections, and hands the motion layer more to work with.
 *
 * Nothing here depends on JavaScript to become visible.
 */
$v         = SiteBuilder::assetVersion();
$canonical = rtrim((string) App::config('site_url'), '/');
$faq       = Content::faq();
$maxDepth  = App::settingInt('max_depth');
$limit     = App::settingInt('daily_limit');

$ld = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'               => 'WebApplication',
            'name'                => 'DoctorFizz Keyword Rank Checker',
            'url'                 => $canonical,
            'description'         => App::content('meta_description'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem'     => 'Any',
            'offers'              => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'GBP'],
            'featureList'         => 'Organic position for any keyword, 239 countries, 82 languages, full list of competing results, action guidance per position band',
            'publisher'           => [
                '@type'   => 'Organization',
                'name'    => 'Itzfizz Digital',
                'url'     => 'https://itzfizz.com',
                'logo'    => $canonical . '/assets/img/itzfizz-black.png',
                'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Bengaluru', 'addressCountry' => 'IN'],
            ],
        ],
        [
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(static fn(array $r): array => [
                '@type'          => 'Question',
                'name'           => $r[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $r[1]],
            ], $faq),
        ],
        [
            '@type'     => 'HowTo',
            'name'      => 'How to check where your page ranks for a keyword',
            'totalTime' => 'PT1M',
            'step'      => array_map(static fn(array $s, int $i): array => [
                '@type' => 'HowToStep', 'position' => $i + 1, 'name' => $s[1], 'text' => $s[2],
            ], Content::DEFAULT_STEPS, array_keys(Content::DEFAULT_STEPS)),
        ],
    ],
];

/** Splits a line into per word spans so the reveal can run at word level. */
$words = static function (string $s, string $cls): string {
    $out = [];
    foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
        $grad = $cls === 'stack-b' ? ' data-grad="1"' : '';
        $out[] = '<span class="wd"><span class="ink"' . $grad . '>' . Security::e($w) . '</span></span>';
    }
    return '<span class="' . $cls . '">' . implode(' ', $out) . '</span>';
};

/** Section heading. One line, wrapping naturally, second half carrying the brand ramp. */
$stack = static function (string $a, string $b, string $tag = 'h2') use ($words): string {
    return '<' . $tag . ' class="stack" data-stack>'
         . $words($a, 'stack-a') . ' ' . $words($b, 'stack-b')
         . '</' . $tag . '>';
};

/** The mono rule that labels each section. Plain text without CSS, so it degrades cleanly. */
$rule = static fn(string $label): string => '<p class="rule">' . Security::e($label) . '</p>';
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(App::content('meta_title')) ?></title>
<meta name="description" content="<?= e(App::content('meta_description')) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
<meta name="theme-color" content="#0D0D0D">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Itzfizz Digital">
<meta property="og:title" content="<?= e(App::content('meta_title')) ?>">
<meta property="og:description" content="<?= e(App::content('meta_description')) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($canonical) ?>/assets/img/doctorfizz-black.png">
<meta property="og:image:alt" content="<?= e(App::content('og_alt')) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="assets/img/doctorfizz-black.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Fira+Code:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/css/app.r12.css?v=<?= e($v) ?>">
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
<a class="skip" href="#keyword">Skip to the rank checker</a>
<div class="progress" id="progress" aria-hidden="true"><i></i></div>

<header class="nav" id="nav">
  <div class="shell nav-in">
    <a class="brand" href="./" aria-label="DoctorFizz rank checker, home">
      <img src="assets/img/doctorfizz-white.png" alt="DoctorFizz" width="180" height="31" fetchpriority="high">
    </a>
    <nav class="nav-links" aria-label="Sections">
      <a href="#does">What it does</a>
      <a href="#how">How it works</a>
      <a href="#bands">Position bands</a>
      <a href="#ai">AI Overviews</a>
      <a href="#faq">FAQ</a>
    </nav>
    <a class="pill-cta" href="#keyword"><span>Check a keyword</span></a>
  </div>
</header>

<div class="rerun" id="rerun" hidden>
  <div class="shell rerun-in">
    <p class="rerun-txt"><b id="rerun-pos">&nbsp;</b><span id="rerun-meta"></span></p>
    <button type="button" class="btn btn-sm" id="rerun-btn">New check</button>
  </div>
</div>

<main>

<!-- ============================================================ hero -->
<section class="hero" id="hero">
  <canvas id="hero-canvas" aria-hidden="true"></canvas>
  <div class="hero-glow" aria-hidden="true"></div>

  <div class="shell hero-in">
    <div class="hero-copy">
      <p class="kicker" data-kicker><span class="dot" aria-hidden="true"></span><?= e(App::content('hero_kicker')) ?></p>
      <?= $stack(App::content('hero_a'), App::content('hero_b'), 'h1') ?>
      <p class="deck" data-deck><?= e(App::content('hero_deck')) ?></p>

      <dl class="facts" data-facts>
        <div><dt>Checks a day</dt><dd data-count="<?= (int) $limit ?>">0</dd></div>
        <div><dt>Countries</dt><dd data-count="<?= count(Geo::COUNTRIES) ?>">0</dd></div>
        <div><dt>Results deep</dt><dd data-count="<?= (int) $maxDepth * 10 ?>">0</dd></div>
        <div><dt>Signup fields</dt><dd data-count="0">0</dd></div>
      </dl>
    </div>

    <div class="tool" id="tool-card">
      <div class="tool-top">
        <h2 class="tool-title">Check a keyword</h2>
        <p class="meter" id="meter" data-state="idle">
          <span class="meter-dot" aria-hidden="true"></span>
          <span id="meter-text">Loading your allowance</span>
        </p>
      </div>

      <div id="notice" class="notice" hidden></div>

      <form id="rank-form" class="form" novalidate autocomplete="off">
        <div class="hp" aria-hidden="true">
          <label for="company">Company</label>
          <input type="text" id="company" name="company" tabindex="-1" autocomplete="off">
        </div>

        <div class="fld fld-lg">
          <label for="keyword">Keyword</label>
          <input type="text" id="keyword" name="keyword" maxlength="120" required
                 placeholder="outsourced accounting services" spellcheck="false" enterkeyhint="go">
        </div>

        <div class="fld fld-lg">
          <label for="domain">Your domain</label>
          <input type="text" id="domain" name="domain" maxlength="253" required
                 placeholder="itzfizz.com" spellcheck="false" inputmode="url" enterkeyhint="go">
        </div>

        <div class="fld">
          <label for="gl-search">Country</label>
          <input list="gl-list" id="gl-search" placeholder="Type to search" autocomplete="off" spellcheck="false">
          <datalist id="gl-list">
            <?php foreach (Geo::COUNTRIES as $code => $name): ?><option value="<?= e($name) ?>"></option><?php endforeach; ?>
          </datalist>
          <select id="gl" name="gl" class="sr-select" aria-label="Country">
            <?php foreach (Geo::COUNTRIES as $code => $name): ?><option value="<?= e($code) ?>"<?= $code === 'uk' ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="fld">
          <label for="hl">Language</label>
          <select id="hl" name="hl">
            <?php foreach (Geo::LANGUAGES as $code => $name): ?><option value="<?= e($code) ?>"<?= $code === 'en' ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="fld">
          <label for="depth">Scan depth</label>
          <select id="depth" name="depth">
            <?php foreach (Geo::DEPTHS as $value => $label): if ($value > $maxDepth) { continue; } ?><option value="<?= (int) $value ?>"<?= $value === 2 ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="fld fld-go">
          <button type="submit" id="go" class="btn btn-go">
            <span class="btn-txt">Check ranking</span>
            <span class="btn-spin" aria-hidden="true"></span>
          </button>
        </div>
      </form>

      <div class="presets" id="presets">
        <span>Try one</span>
        <button type="button" data-k="outsourced accounting services" data-d="acenteus-cca.com">accounting services</button>
        <button type="button" data-k="seo agency bengaluru" data-d="itzfizz.com">seo agency</button>
        <button type="button" data-k="lift management software" data-d="aviaenterprises.net">lift software</button>
      </div>

      <p class="form-note"><?= e(App::content('form_note')) ?></p>
      <noscript><p class="notice is-warn" style="display:block">The checker needs JavaScript. Everything below works without it.</p></noscript>

      <div class="scanline" id="scanline" aria-hidden="true"></div>

      <div id="result" class="result" hidden aria-live="polite"></div>
      <div id="summary" class="summary" hidden></div>
      <ol id="ladder" class="ladder" hidden></ol>
      <div id="recent" class="recent" hidden></div>
    </div>
  </div>
</section>

<!-- ============================================================ what it does -->
<section class="sec" id="does">
  <div class="shell">
    <header class="sec-head">
      <?= $rule('01 / What it does') ?>
      <?= $stack(App::content('does_a'), App::content('does_b')) ?>
      <p class="lede" data-reveal><?= e(App::content('does_lede')) ?></p>
    </header>
    <div class="does-grid" data-wipe>
      <?php foreach (Content::DEFAULT_DOES as $d): ?>
      <article class="does">
        <span class="does-v"><?= e($d[0]) ?></span>
        <h3><?= e($d[1]) ?></h3>
        <p><?= e($d[2]) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ pinned step stack -->
<section class="pin" id="how">
  <div class="pin-inner">
    <div class="shell pin-grid">
      <div class="pin-copy">
        <?= $rule('02 / How it works') ?>
        <?= $stack(App::content('how_a'), App::content('how_b')) ?>
        <p class="lede"><?= e(App::content('how_lede')) ?></p>
        <ol class="pin-nav" id="pin-nav" aria-hidden="true">
          <?php foreach (Content::DEFAULT_STEPS as $i => $s): ?>
          <li<?= $i === 0 ? ' class="on"' : '' ?>><span><?= e($s[0]) ?></span><?= e($s[1]) ?></li>
          <?php endforeach; ?>
        </ol>
      </div>
      <div class="pin-stack" id="pin-stack">
        <?php foreach (Content::DEFAULT_STEPS as $i => $s): ?>
        <article class="pcard" data-pcard="<?= $i ?>">
          <span class="pcard-n"><?= e($s[0]) ?></span>
          <h3><?= e($s[1]) ?></h3>
          <p><?= e($s[2]) ?></p>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ what you get -->
<section class="sec" id="result">
  <div class="shell">
    <header class="sec-head">
      <?= $rule('03 / The readout') ?>
      <?= $stack(App::content('result_a'), App::content('result_b')) ?>
      <p class="lede" data-reveal><?= e(App::content('result_lede')) ?></p>
    </header>
    <ol class="gets">
      <?php foreach (Content::DEFAULT_RESULT as $i => $r): ?>
      <li data-row><span class="gets-n"><?= sprintf('%02d', $i + 1) ?></span><b><?= e($r[0]) ?></b><p><?= e($r[1]) ?></p></li>
      <?php endforeach; ?>
    </ol>
    <p class="upsell" data-reveal>
      Need this tracked on a schedule, or across a whole site?
      <button type="button" class="link-btn" data-open-upgrade>See what is coming</button>
    </p>
  </div>
</section>

<!-- ============================================================ position bands -->
<section class="sec sec-alt" id="bands">
  <div class="shell">
    <header class="sec-head">
      <?= $rule('04 / Reading the number') ?>
      <?= $stack(App::content('bands_a'), App::content('bands_b')) ?>
      <p class="lede" data-reveal><?= e(App::content('bands_lede')) ?></p>
    </header>
    <div class="bands" data-wipe>
      <?php foreach (Content::DEFAULT_BANDS as $b): ?>
      <article class="band">
        <span class="band-r"><?= e($b[0]) ?></span>
        <h3><?= e($b[1]) ?></h3>
        <p><?= e($b[2]) ?></p>
        <span class="band-bar" aria-hidden="true"><i data-fill="<?= e($b[3]) ?>"></i></span>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ built for -->
<section class="sec" id="for">
  <div class="shell">
    <header class="sec-head">
      <?= $rule('05 / Who uses it') ?>
      <?= $stack(App::content('for_a'), App::content('for_b')) ?>
      <p class="lede" data-reveal><?= e(App::content('for_lede')) ?></p>
    </header>
    <div class="for-grid">
      <?php foreach (Content::DEFAULT_FOR as $f): ?>
      <article class="forc" data-card>
        <h3><?= e($f[0]) ?></h3>
        <p><?= e($f[1]) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ the data -->
<section class="sec" id="data">
  <div class="shell sec-split">
    <header class="sec-head">
      <?= $rule('06 / Where the number comes from') ?>
      <?= $stack(App::content('acc_a'), App::content('acc_b')) ?>
      <p class="lede" data-reveal><?= e(App::content('acc_lede')) ?></p>
    </header>
    <div class="copy" data-reveal><?= Content::render(App::content('acc_body')) ?></div>
  </div>
</section>

<!-- ============================================================ limits -->
<section class="sec sec-alt" id="limits">
  <div class="shell sec-split">
    <header class="sec-head">
      <?= $rule('07 / Straight answers') ?>
      <?= $stack(App::content('limits_a'), App::content('limits_b')) ?>
      <p class="lede" data-reveal><?= e(App::content('limits_lede')) ?></p>
    </header>
    <ol class="gets">
      <?php foreach (Content::DEFAULT_LIMITS as $i => $l): ?>
      <li data-row><span class="gets-n"><?= sprintf('%02d', $i + 1) ?></span><b><?= e($l[0]) ?></b><p><?= e($l[1]) ?></p></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- ============================================================ ai -->
<section class="sec sec-ai" id="ai">
  <div class="shell">
    <header class="sec-head">
      <?= $rule('08 / AI Overviews') ?>
      <?= $stack(App::content('ai_a'), App::content('ai_b')) ?>
    </header>
    <div class="stat-row" data-wipe>
      <?php foreach (Content::DEFAULT_AI_STATS as $st): ?>
      <div class="stat">
        <b data-count="<?= e($st[0]) ?>" data-unit="<?= e($st[1]) ?>">0<?= e($st[1]) ?></b>
        <p><?= e($st[2]) ?></p>
        <cite><?= e($st[3]) ?></cite>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="ai-body">
      <div class="copy" data-reveal><?= Content::render(App::content('ai_body')) ?></div>
      <p class="callout" data-reveal><?= e(App::content('ai_note')) ?></p>
      <details class="sources">
        <summary>Sources</summary>
        <ul>
          <?php foreach (Content::DEFAULT_SOURCES as $src): ?>
          <li><a href="<?= e($src[1]) ?>" rel="noopener nofollow" target="_blank"><?= e($src[0]) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </details>
    </div>
  </div>
</section>

<!-- ============================================================ faq -->
<section class="sec" id="faq">
  <div class="shell sec-split">
    <header class="sec-head">
      <?= $rule('09 / FAQ') ?>
      <?= $stack('COMMON', 'QUESTIONS') ?>
      <p class="lede" data-reveal>Everything people ask before they trust the number.</p>
    </header>
    <div>
    <div class="faq">
      <?php foreach ($faq as $i => $pair): ?>
      <details<?= $i === 0 ? ' open' : '' ?> data-row>
        <summary><?= e($pair[0]) ?></summary>
        <div class="faq-a"><p><?= e($pair[1]) ?></p></div>
      </details>
      <?php endforeach; ?>
    </div>
    <p class="byline" data-reveal><?= e(App::content('author_line')) ?></p>
    </div>
  </div>
</section>

<!-- ============================================================ close -->
<section class="close" id="close">
  <div class="shell close-in">
    <div class="close-copy">
      <?= $stack(App::content('cta_a'), App::content('cta_b')) ?>
      <p data-reveal><?= e(App::content('cta_body')) ?></p>
      <div class="close-actions" data-reveal>
        <a class="btn btn-primary" href="<?= e(App::content('cta_url')) ?>" rel="noopener">
          <span><?= e(App::content('cta_button')) ?></span>
          <svg viewBox="0 0 20 20" width="17" height="17" aria-hidden="true"><path d="M3 10h12M10 5l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
        <a class="btn btn-quiet" href="#keyword">Run another check</a>
      </div>
    </div>

    <aside class="close-card" data-reveal>
      <p class="close-k"><?= e(App::content('parent_k')) ?></p>
      <img src="assets/img/itzfizz-black.png" alt="Itzfizz Digital" width="196" height="64" loading="lazy">
      <p><?= e(App::content('parent_body')) ?></p>
      <a class="close-link" href="https://itzfizz.com" rel="noopener">
        <?= e(App::content('parent_cta')) ?>
        <svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true"><path d="M3 10h12M10 5l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
    </aside>
  </div>
</section>

</main>

<footer class="foot">
  <div class="shell foot-in">
    <div>
      <img class="foot-mark" src="assets/img/doctorfizz-white.png" alt="DoctorFizz" width="164" height="28" loading="lazy">
      <p class="foot-note"><?= e(App::content('footer_note')) ?></p>
      <p class="foot-copy">&copy; <?= date('Y') ?> Itzfizz Digital. All rights reserved.</p>
    </div>
    <nav aria-label="Footer">
      <ul class="foot-links">
        <li><a href="https://itzfizz.com" rel="noopener">Itzfizz Digital</a></li>
        <li><a href="https://doctorfizz.com" rel="noopener">DoctorFizz</a></li>
        <li><a href="https://itzfizz.com/contact" rel="noopener">Contact</a></li>
        <li><a href="https://itzfizz.com/privacy-policy" rel="noopener">Privacy policy</a></li>
        <li><a href="https://itzfizz.com/terms-of-service" rel="noopener">Terms of service</a></li>
      </ul>
      <ul class="social">
        <li><a href="https://www.linkedin.com/company/itzfizzz/" rel="noopener" aria-label="Itzfizz Digital on LinkedIn"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M4.98 3.5A2.5 2.5 0 1 1 5 8.5a2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-.95 1.83-1.95 3.77-1.95C20.4 8.75 21 11 21 14.1V21h-4v-6.1c0-1.46-.03-3.35-2.05-3.35-2.05 0-2.37 1.6-2.37 3.25V21H9z"/></svg></a></li>
        <li><a href="https://www.instagram.com/itzfizz_digital/" rel="noopener" aria-label="Itzfizz Digital on Instagram"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2.2c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.8 3.8 0 0 1-1.38-.9 3.8 3.8 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.21 15.58 2.2 15.2 2.2 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.21 8.8 2.2 12 2.2zm0 3.05A6.75 6.75 0 1 0 18.75 12 6.76 6.76 0 0 0 12 5.25zm0 11.13A4.38 4.38 0 1 1 16.38 12 4.38 4.38 0 0 1 12 16.38zm6.94-11.4a1.58 1.58 0 1 1-1.58-1.57 1.58 1.58 0 0 1 1.58 1.57z"/></svg></a></li>
        <li><a href="https://www.youtube.com/@itzfizz_digital" rel="noopener" aria-label="Itzfizz Digital on YouTube"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M23 12s0-3.2-.41-4.74a2.5 2.5 0 0 0-1.76-1.77C19.29 5.08 12 5.08 12 5.08s-7.29 0-8.83.41A2.5 2.5 0 0 0 1.41 7.26 26 26 0 0 0 1 12a26 26 0 0 0 .41 4.74 2.5 2.5 0 0 0 1.76 1.77c1.54.41 8.83.41 8.83.41s7.29 0 8.83-.41a2.5 2.5 0 0 0 1.76-1.77C23 15.2 23 12 23 12zM9.75 15.02V8.98L15.5 12z"/></svg></a></li>
        <li><a href="https://www.facebook.com/itzfizz.digital" rel="noopener" aria-label="Itzfizz Digital on Facebook"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg></a></li>
      </ul>
    </nav>
  </div>
</footer>

<!-- ============================================================ upgrade modal -->
<div class="modal" id="upgrade" hidden role="dialog" aria-modal="true" aria-labelledby="up-title">
  <div class="modal-back" data-close-upgrade></div>
  <div class="modal-card" role="document">
    <button type="button" class="modal-x" data-close-upgrade aria-label="Close">
      <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <p class="modal-k">DoctorFizz</p>
    <h2 id="up-title"><?= e(App::content('up_title')) ?></h2>
    <p class="modal-body"><?= e(App::content('up_body')) ?></p>
    <a class="btn btn-primary" href="<?= e(App::content('up_url')) ?>" rel="noopener" target="_blank">
      <span><?= e(App::content('up_cta')) ?></span>
      <svg viewBox="0 0 20 20" width="17" height="17" aria-hidden="true"><path d="M3 10h12M10 5l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <p class="modal-note"><?= e(App::content('up_note')) ?></p>
  </div>
</div>

<script src="assets/js/app.r12.js?v=<?= e($v) ?>" data-df-base="<?= e(base_path()) ?>" data-df-rel="r12" defer></script>
</body>
</html>
