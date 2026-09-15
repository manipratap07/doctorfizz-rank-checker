<?php
declare(strict_types=1);

define('DF_ENTRY', true);

/**
 * Admin panel. Server rendered on purpose: the login and every action is checked in PHP
 * before a byte of the page exists, which is a smaller attack surface than shipping an
 * admin single page app and trusting it to ask nicely.
 */

require __DIR__ . '/../app/bootstrap.php';

Security::sendHeaders();
Security::startSession();
header('X-Robots-Tag: noindex, nofollow');

/** The admin lives at a path you choose in config.php, so these are computed, not hardcoded. */
function admin_base(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'))), '/');
    return $dir === '' ? '/' : $dir;
}
function admin_url(string $path = ''): string
{
    $b = admin_base();
    return $path === '' ? $b . '/' : rtrim($b, '/') . '/?p=' . rawurlencode($path);
}
function url(string $path = ''): string
{
    $b = rtrim(dirname(admin_base()), '/');
    return ($b === '' ? '' : $b) . '/' . ltrim($path, '/');
}
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/')) . '?v=' . SiteBuilder::assetVersion();
}
function view(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../app/views/' . $name . '.php';
}

$sub    = preg_replace('/[^a-z]/', '', (string) ($_GET['p'] ?? '')) ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$flash  = null;

/* ---------------------------------------------------------------- login */

if (Security::adminId() === null) {
    if ($isPost) {
        if (!Security::csrfValid((string) ($_POST['csrf'] ?? ''))) {
            $flash = ['bad', 'Your session expired. Try again.'];
        } elseif (Security::loginBlocked()) {
            $flash = ['bad', 'Too many failed attempts. Wait 15 minutes.'];
        } else {
            $user = trim((string) ($_POST['username'] ?? ''));
            $row  = Db::one('SELECT id, username, pass_hash FROM admins WHERE username = ?', [$user]);

            if ($row !== null && password_verify((string) ($_POST['password'] ?? ''), (string) $row['pass_hash'])) {
                Security::recordLogin(true);
                session_regenerate_id(true);
                $_SESSION['admin_id']   = (int) $row['id'];
                $_SESSION['admin_name'] = (string) $row['username'];
                Db::run('UPDATE admins SET last_login = ? WHERE id = ?', [time(), (int) $row['id']]);
                App::audit((string) $row['username'], 'admin.login');
                header('Location: ' . admin_url());
                exit;
            }

            Security::recordLogin(false);
            // Identical message either way, so the form never reveals which field was wrong.
            $flash = ['bad', 'Those details did not work.'];
        }
    }
    view('admin_login', ['csrf' => Security::csrfToken(), 'flash' => $flash]);
    exit;
}

$actor = (string) ($_SESSION['admin_name'] ?? 'admin');

if ($sub === 'logout') {
    App::audit($actor, 'admin.logout');
    $_SESSION = [];
    session_destroy();
    header('Location: ' . admin_url());
    exit;
}

if ($isPost && !Security::csrfValid((string) ($_POST['csrf'] ?? ''))) {
    http_response_code(419);
    exit('Session expired. Go back, reload, and try again.');
}

/* ---------------------------------------------------------------- content */

if ($sub === 'content') {
    if ($isPost) {
        $saved = 0;
        foreach (Content::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $val = trim((string) $_POST[$key]);
            // House style is enforced when it is saved, not left to whoever is typing.
            $val = Content::deDash($val);
            App::setContent($key, mb_substr($val, 0, 8000));
            $saved++;
        }

        if (isset($_POST['faq_json'])) {
            $rows = [];
            $qs = (array) ($_POST['faq_q'] ?? []);
            $as = (array) ($_POST['faq_a'] ?? []);
            foreach ($qs as $i => $q) {
                $q = Content::deDash(trim((string) $q));
                $a = Content::deDash(trim((string) ($as[$i] ?? '')));
                if ($q !== '' && $a !== '') {
                    $rows[] = [$q, $a];
                }
            }
            App::setContent('faq_json', json_encode(array_slice($rows, 0, 20), JSON_UNESCAPED_SLASHES));
            $saved++;
        }

        $build = SiteBuilder::build();
        App::audit($actor, 'content.save', $saved . ' fields, rebuild ' . ($build['ok'] ? 'ok' : 'failed'));
        $flash = $build['ok']
            ? ['good', 'Saved and the page was rebuilt. ' . $saved . ' fields updated, ' . number_format($build['bytes'] / 1024, 1) . ' KB written.']
            : ['bad', 'Content saved, but the page could not be rebuilt. ' . $build['error']];
    }

    view('admin_content', ['csrf' => Security::csrfToken(), 'flash' => $flash, 'actor' => $actor]);
    exit;
}

/* ---------------------------------------------------------------- settings */

if ($sub === 'settings') {
    if ($isPost) {
        $numeric = [
            'daily_limit'      => [1, 50],
            'burst_per_minute' => [1, 60],
            'api_budget_day'   => [1, 10000],
            'cache_ttl_hours'  => [1, 168],
            'max_depth'        => [1, 5],
            'retention_days'   => [7, 730],
        ];
        foreach ($numeric as $key => [$min, $max]) {
            if (isset($_POST[$key])) {
                App::setSetting($key, (string) max($min, min($max, (int) $_POST[$key])));
            }
        }

        App::setSetting('provider', 'serpapi');

        App::setSetting('maintenance', isset($_POST['maintenance']) ? '1' : '0');
        App::setSetting('trust_proxy', isset($_POST['trust_proxy']) ? '1' : '0');
        $note = trim((string) ($_POST['maintenance_note'] ?? ''));
        if ($note !== '') {
            App::setSetting('maintenance_note', mb_substr($note, 0, 300));
        }

        // Credentials are written only when a new value is supplied, and always encrypted.
        foreach (['provider_serpapi_api_key'] as $field) {
            $v = trim((string) ($_POST[$field] ?? ''));
            if ($v !== '') {
                App::setSetting($field, $v, true);
                App::audit($actor, 'settings.credential', $field);
            }
        }

        // Limits and depth appear on the public page, so the static file has to follow.
        $build = SiteBuilder::build();
        App::audit($actor, 'settings.save');
        $flash = $build['ok']
            ? ['good', 'Settings saved and the page was rebuilt.']
            : ['bad', 'Settings saved, but the page could not be rebuilt. ' . $build['error']];
    }

    view('admin_settings', ['csrf' => Security::csrfToken(), 'flash' => $flash, 'actor' => $actor]);
    exit;
}

/* ---------------------------------------------------------------- bans */

if ($sub === 'bans') {
    if ($isPost) {
        $action  = (string) ($_POST['action'] ?? '');
        $visitor = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['visitor'] ?? '')) ?? '';
        if ($visitor !== '' && $action === 'ban') {
            Db::run('INSERT OR REPLACE INTO bans (visitor, reason, created) VALUES (?, ?, ?)',
                [$visitor, mb_substr(trim((string) ($_POST['reason'] ?? 'Abuse')), 0, 200), time()]);
            App::audit($actor, 'ban.add', $visitor);
            $flash = ['good', 'Visitor blocked.'];
        } elseif ($visitor !== '' && $action === 'unban') {
            Db::run('DELETE FROM bans WHERE visitor = ?', [$visitor]);
            App::audit($actor, 'ban.remove', $visitor);
            $flash = ['good', 'Block removed.'];
        }
    }

    view('admin_bans', [
        'csrf'  => Security::csrfToken(),
        'flash' => $flash,
        'actor' => $actor,
        'bans'  => Db::all('SELECT * FROM bans ORDER BY created DESC LIMIT 200'),
        'heavy' => Db::all(
            'SELECT visitor, COUNT(*) AS n, MAX(ts) AS last_seen FROM checks
             WHERE ts > ? GROUP BY visitor ORDER BY n DESC LIMIT 20',
            [time() - 7 * 86400]
        ),
    ]);
    exit;
}

/* ---------------------------------------------------------------- provider test */

if ($sub === 'test' && $isPost) {
    $provider = ProviderFactory::make();
    $res = $provider->fetchPage('seo services', 'uk', 'en', 1);
    App::audit($actor, 'provider.test', $res['ok'] ? 'ok' : $res['error']);
    $flash = $res['ok']
        ? ['good', 'Provider works. ' . count($res['items']) . ' results returned, and that test used one API call.']
        : ['bad', 'Provider failed: ' . $res['error']];
}

/* ---------------------------------------------------------------- rebuild */

if ($sub === 'rebuild' && $isPost) {
    $build = SiteBuilder::build();
    App::audit($actor, 'site.rebuild', $build['ok'] ? 'ok' : $build['error']);
    $flash = $build['ok']
        ? ['good', 'Page rebuilt, ' . number_format($build['bytes'] / 1024, 1) . ' KB written.']
        : ['bad', $build['error']];
}

/* ---------------------------------------------------------------- dashboard */

$today = RateLimiter::today();
$since = time() - 14 * 86400;

$daily = [];
for ($i = 13; $i >= 0; $i--) {
    $daily[gmdate('Y-m-d', time() - $i * 86400)] = 0;
}
foreach (Db::all('SELECT day, COUNT(*) AS n FROM checks WHERE ts > ? GROUP BY day', [$since]) as $row) {
    if (isset($daily[$row['day']])) {
        $daily[$row['day']] = (int) $row['n'];
    }
}

$dbFile   = (string) App::config('db_path');
$pageFile = public_root() . '/index.html';

/* Everything that has to be true for the public checker to work. If the page shows
   "Checker unavailable", the answer is in this list. */
$creds  = App::providerCredentials('serpapi');
$system = [
    ['PHP 8.1 or newer', PHP_VERSION_ID >= 80100, PHP_VERSION],
    ['cURL extension', function_exists('curl_init'), 'needed to reach the search provider'],
    ['SQLite driver', in_array('sqlite', PDO::getAvailableDrivers(), true), 'needed for storage'],
    ['Database writable', is_writable(dirname($dbFile)) && (!is_file($dbFile) || is_writable($dbFile)), basename(dirname($dbFile)) . ' directory'],
    ['Encryption key valid', (static function () { try { App::encrypt('probe'); return true; } catch (Throwable $e) { return false; } })(), 'app_key in config.php'],
    ['Static page writable', is_file($pageFile) ? is_writable($pageFile) : is_writable(dirname($pageFile)), 'index.html, needed to publish content'],
    ['Provider credentials set', !empty($creds['api_key']), ProviderFactory::label('serpapi')],
    ['site_url matches this host', str_contains((string) App::config('site_url'), (string) ($_SERVER['HTTP_HOST'] ?? '')), (string) App::config('site_url')],
];

view('admin_dash', [
    'actor'         => $actor,
    'flash'         => $flash,
    'csrf'          => Security::csrfToken(),
    'today'         => $today,
    'checksToday'   => (int) Db::scalar('SELECT COUNT(*) FROM checks WHERE day = ?', [$today]),
    'visitorsToday' => (int) Db::scalar('SELECT COUNT(DISTINCT visitor) FROM checks WHERE day = ?', [$today]),
    'budgetUsed'    => RateLimiter::budgetUsed(),
    'budgetMax'     => App::settingInt('api_budget_day'),
    'foundRate'     => (int) Db::scalar('SELECT ROUND(100.0 * SUM(found) / COUNT(*)) FROM checks WHERE ts > ?', [$since]),
    'cacheRows'     => (int) Db::scalar('SELECT COUNT(*) FROM serp_cache'),
    'dbSize'        => is_file($dbFile) ? (int) filesize($dbFile) : 0,
    'pageBuilt'     => SiteBuilder::lastBuild(),
    'pageSize'      => is_file($pageFile) ? (int) filesize($pageFile) : 0,
    'provider'      => ProviderFactory::label('serpapi'),
    'system'        => $system,
    'daily'         => $daily,
    'topKeywords'   => Db::all('SELECT keyword, COUNT(*) AS n FROM checks WHERE ts > ? GROUP BY keyword ORDER BY n DESC LIMIT 10', [$since]),
    'topDomains'    => Db::all('SELECT domain, COUNT(*) AS n FROM checks WHERE ts > ? GROUP BY domain ORDER BY n DESC LIMIT 10', [$since]),
    'recent'        => Db::all('SELECT * FROM checks ORDER BY id DESC LIMIT 25'),
    'audit'         => Db::all('SELECT * FROM audit ORDER BY id DESC LIMIT 10'),
]);
