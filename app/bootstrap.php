<?php
declare(strict_types=1);

// Nothing under app/ runs unless an entry point asked for it.
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * Shared bootstrap. Loaded by the API endpoint and by the admin, never by the public page.
 * The public page is a pre-built static index.html and executes no PHP at all.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('This application needs PHP 8.1 or newer.');
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration missing. Copy app/config.example.php to app/config.php and fill it in.');
}

$config = require $configFile;

/** mbstring is usually present, but a fatal on a live endpoint is a poor way to find out. */
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $enc = null): int
    {
        return (int) preg_match_all('/./us', $s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, ?string $enc = null): string
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length));
    }
}

foreach (['App', 'Db', 'Security', 'Geo', 'Content', 'RateLimiter', 'Serp', 'RankCheck', 'SiteBuilder'] as $lib) {
    require_once __DIR__ . '/lib/' . $lib . '.php';
}

App::boot($config);

error_reporting(E_ALL);
ini_set('display_errors', !empty($config['debug']) ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e) use ($config): void {
    error_log('[rankchecker] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'code'  => 'server',
        'error' => !empty($config['debug'])
            ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            : 'Something went wrong on our side. Try again in a moment.',
    ]);
});

/** The folder this tool is installed in. app/ sits inside it, so the root is one level up. */
function public_root(): string
{
    return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
}

/**
 * URL path the tool is mounted at, always with a trailing slash.
 * Derived from site_url, so /keyword-rank-checker/ and / both work with no code change.
 */
function base_path(): string
{
    $path = parse_url((string) App::config('site_url'), PHP_URL_PATH);
    $path = is_string($path) ? trim($path, '/') : '';
    return $path === '' ? '/' : '/' . $path . '/';
}

function site_url(string $path = ''): string
{
    return rtrim((string) App::config('site_url'), '/') . '/' . ltrim($path, '/');
}

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function e(?string $s): string
{
    return Security::e($s);
}

/** First run: create the admin account from the bootstrap block in config.php. */
if (Db::scalar('SELECT COUNT(*) FROM admins') === 0) {
    $boot = App::config('admin_bootstrap', []);
    if (!empty($boot['username']) && !empty($boot['pass_hash'])) {
        Db::run(
            'INSERT OR IGNORE INTO admins (username, pass_hash, created_at) VALUES (?, ?, ?)',
            [(string) $boot['username'], (string) $boot['pass_hash'], time()]
        );
        App::audit('system', 'admin.bootstrap', (string) $boot['username']);
    }
}

App::maybePrune();
