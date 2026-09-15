<?php
declare(strict_types=1);

define('DF_ENTRY', true);

/**
 * The only PHP a visitor ever touches. Two actions, same origin only, JSON in and JSON out.
 * The provider key never leaves this process, which is the whole reason this file exists
 * instead of the browser calling Google directly.
 */

/* Fail with a readable reason rather than a blank 500. "Checker unavailable" with no
   explanation is the single most useless error a tool can show its owner. */
if (!is_file(__DIR__ . '/../app/config.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'code'  => 'setup',
        'error' => 'Not configured yet. Copy app/config.example.php to app/config.php and fill it in.',
    ]);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

Security::startSession();

/* Same origin only. No CORS headers are sent, and a cross site form post is rejected here
   as well, so a hostile page cannot spend a visitor's allowance on their behalf. */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);

    $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $requestHost = explode(':', $requestHost)[0];

    if (
        !is_string($originHost) ||
        strcasecmp($originHost, $requestHost) !== 0
    ) {
        json_out([
            'ok'    => false,
            'code'  => 'origin',
            'error' => 'Cross site requests are not accepted.',
        ], 403);
    }
}
$action = (string) ($_GET['a'] ?? '');
if ($action === '') {
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $action = basename(rtrim($path, '/'));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------------------------------------------------------------- session */

if ($action === 'session') {
    $visitor = Security::visitorKey();
    json_out([
        'ok'          => true,
        'csrf'        => Security::csrfToken(),
        'limit'       => App::settingInt('daily_limit'),
        'remaining'   => RateLimiter::remaining($visitor),
        'resets_in'   => RateLimiter::resetsIn(),
        'maintenance' => App::setting('maintenance') === '1',
        'note'        => App::setting('maintenance') === '1' ? App::setting('maintenance_note') : '',
        'issued'      => time(),
    ]);
}

/* ---------------------------------------------------------------- health */

if ($action === 'health') {
    // Booleans only. Enough for the owner to fix it, nothing an attacker can use.
    $checks = [];
    $checks['php_81']  = PHP_VERSION_ID >= 80100;
    $checks['curl']    = function_exists('curl_init');
    $checks['sqlite']  = in_array('sqlite', PDO::getAvailableDrivers(), true);
    $checks['openssl'] = function_exists('openssl_encrypt');

    try {
        App::encrypt('probe');
        $checks['app_key'] = true;
    } catch (Throwable $e) {
        $checks['app_key'] = false;
    }

    try {
        Db::scalar('SELECT 1');
        $checks['database'] = true;
    } catch (Throwable $e) {
        $checks['database'] = false;
    }

    $page = public_root() . '/index.html';
    $checks['page_writable'] = is_file($page) ? is_writable($page) : is_writable(dirname($page));

    $creds = App::providerCredentials('serpapi');
    $checks['provider_configured'] = !empty($creds['api_key']);

    json_out(['ok' => !in_array(false, $checks, true), 'checks' => $checks]);
}

/* ---------------------------------------------------------------- check */

if ($action !== 'check') {
    json_out(['ok' => false, 'code' => 'route', 'error' => 'Unknown action.'], 404);
}

if ($method !== 'POST') {
    json_out(['ok' => false, 'code' => 'method', 'error' => 'Use POST for this endpoint.'], 405);
}

$raw   = file_get_contents('php://input') ?: '';
if (strlen($raw) > 4096) {
    json_out(['ok' => false, 'code' => 'size', 'error' => 'That request was too large.'], 413);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    json_out(['ok' => false, 'code' => 'body', 'error' => 'Send a JSON body.'], 400);
}

if (!Security::csrfValid((string) ($input['csrf'] ?? ''))) {
    json_out(['ok' => false, 'code' => 'csrf', 'error' => 'Your session expired. Reload the page and try again.'], 419);
}

/* Honeypot plus a minimum fill time. Between them these remove most unsophisticated bots
   without putting a third party captcha script in front of a real person. */
if (trim((string) ($input['company'] ?? '')) !== '') {
    json_out(['ok' => false, 'code' => 'bot', 'error' => 'That request looked automated.'], 400);
}
$issued = (int) ($input['issued'] ?? 0);
if ($issued > 0 && (time() - $issued) < 2) {
    json_out(['ok' => false, 'code' => 'bot', 'error' => 'That was too fast. Try again in a second.'], 429);
}

$visitor = Security::visitorKey();
RateLimiter::recordHit($visitor);

$valid = RankCheck::validate($input);
if (!$valid['ok']) {
    json_out([
        'ok'        => false,
        'code'      => 'input',
        'error'     => $valid['error'],
        'remaining' => RateLimiter::remaining($visitor),
    ], 422);
}
$d = $valid['data'];

$key    = RankCheck::cacheKey($d);
$cached = RankCheck::readCache($key);

$gate = RateLimiter::check($visitor, $cached === null);
if ($gate['status'] !== RateLimiter::OK) {
    $messages = [
        RateLimiter::MAINTENANCE => App::setting('maintenance_note'),
        RateLimiter::BANNED      => 'This tool is not available from your connection.',
        RateLimiter::BURST       => 'That is a lot of checks at once. Wait a minute and try again.',
        RateLimiter::DAILY       => 'You have used all ' . App::settingInt('daily_limit') . ' checks for today.',
        RateLimiter::BUDGET      => 'The tool has used its whole search allowance for today. It resets at midnight UTC.',
    ];
    json_out([
        'ok'        => false,
        'code'      => $gate['status'],
        'error'     => $messages[$gate['status']] ?? 'Request refused.',
        'remaining' => $gate['remaining'],
        'resets_in' => $gate['resets_in'],
    ], 429);
}

if ($cached !== null) {
    $cached['ok']        = true;
    $cached['remaining'] = RateLimiter::remaining($visitor);
    $cached['free']      = true;
    json_out($cached);
}

$provider   = ProviderFactory::make();
$budgetLeft = max(0, App::settingInt('api_budget_day') - RateLimiter::budgetUsed());
$result     = RankCheck::run($d, $provider, $budgetLeft);

if (!$result['ok']) {
    // A failed upstream must never look like a real zero result, and must not cost an allowance.
    json_out([
        'ok'        => false,
        'code'      => 'provider',
        'error'     => $result['error'],
        'remaining' => RateLimiter::remaining($visitor),
    ], 502);
}

RateLimiter::consume($visitor, $result['api_calls']);

Db::run(
    'INSERT INTO checks (ts, day, visitor, keyword, domain, gl, hl, depth, position, found, cached, api_calls, provider)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
    [
        time(), RateLimiter::today(), $visitor, $d['keyword'], $d['domain'], $d['gl'], $d['hl'],
        $d['depth'], $result['position'], $result['found'] ? 1 : 0, $result['api_calls'], $provider->name(),
    ]
);

$payload = [
    'ok'         => true,
    'keyword'    => $d['keyword'],
    'domain'     => $d['domain'],
    'country'    => Geo::country($d['gl']),
    'language'   => Geo::language($d['hl']),
    'depth'      => $d['depth'],
    'position'   => $result['position'],
    'found'      => $result['found'],
    'scanned'    => $result['scanned'],
    'band'       => RankCheck::band($result['position']),
    'results'    => $result['results'],
    'notice'     => $result['error'],
    'checked_at' => time(),
];

RankCheck::writeCache($key, $payload);

$payload['remaining'] = RateLimiter::remaining($visitor);
$payload['free']      = false;
json_out($payload);
