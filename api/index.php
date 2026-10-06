<?php
declare(strict_types=1);

define('DF_ENTRY', true);

/**
 * Public JSON API for DoctorFizz Rank Checker.
 *
 * Primary:
 * VPS → Playwright → DataImpulse → Google
 *
 * Fallback:
 * SerpApi
 */

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

/* -------------------------------------------------------------
 * Same-origin protection
 * ------------------------------------------------------------- */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '') {
    $originHost = parse_url(
        $origin,
        PHP_URL_HOST
    );

    $requestHost = (string) (
        $_SERVER['HTTP_HOST'] ?? ''
    );

    $requestHost = explode(
        ':',
        $requestHost
    )[0];

    if (
        !is_string($originHost) ||
        strcasecmp(
            $originHost,
            $requestHost
        ) !== 0
    ) {
        json_out([
            'ok'    => false,
            'code'  => 'origin',
            'error' => 'Cross site requests are not accepted.',
        ], 403);
    }
}

/* -------------------------------------------------------------
 * Route
 * ------------------------------------------------------------- */

$action = (string) (
    $_GET['a'] ?? ''
);

if ($action === '') {
    $path = parse_url(
        $_SERVER['REQUEST_URI'] ?? '',
        PHP_URL_PATH
    ) ?: '';

    $action = basename(
        rtrim(
            $path,
            '/'
        )
    );
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* -------------------------------------------------------------
 * Session
 * ------------------------------------------------------------- */

if ($action === 'session') {
    $visitor = Security::visitorKey();

    json_out([
        'ok' => true,

        'csrf' =>
            Security::csrfToken(),

        'limit' =>
            App::settingInt('daily_limit'),

        'remaining' =>
            RateLimiter::remaining($visitor),

        'resets_in' =>
            RateLimiter::resetsIn(),

        'maintenance' =>
            App::setting('maintenance') === '1',

        'note' =>
            App::setting('maintenance') === '1'
                ? App::setting('maintenance_note')
                : '',

        'issued' =>
            time(),
    ]);
}

/* -------------------------------------------------------------
 * Health
 * ------------------------------------------------------------- */

if ($action === 'health') {
    $checks = [];

    $checks['php_81'] =
        PHP_VERSION_ID >= 80100;

    $checks['curl'] =
        function_exists('curl_init');

    $checks['sqlite'] =
        in_array(
            'sqlite',
            PDO::getAvailableDrivers(),
            true
        );

    $checks['openssl'] =
        function_exists(
            'openssl_encrypt'
        );

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

    $page =
        public_root() . '/index.html';

    $checks['page_writable'] =
        is_file($page)
            ? is_writable($page)
            : is_writable(
                dirname($page)
            );

    /*
     * Hybrid provider configuration
     */

    $workerUrl = trim(
        (string) App::config(
            'rank_worker_url',
            ''
        )
    );

    $workerSecret = trim(
        (string) App::config(
            'rank_worker_secret',
            ''
        )
    );

    $checks['vps_worker_configured'] =
        $workerUrl !== '' &&
        $workerSecret !== '';

    $creds =
        App::providerCredentials(
            'serpapi'
        );

    $checks['serpapi_fallback_configured'] =
        !empty(
            $creds['api_key']
        );

    /*
     * Tool is considered configured if
     * either provider is available.
     */
    $checks['provider_configured'] =
        $checks['vps_worker_configured'] ||
        $checks['serpapi_fallback_configured'];

    $criticalChecks = $checks;

    /*
     * Vercel deployment files are read-only.
     * page_writable is informational only and is not required
     * for the public rank checker to operate.
     */
    unset($criticalChecks['page_writable']);

    json_out([
        'ok' =>
            !in_array(
                false,
                $criticalChecks,
                true
            ),

        'checks' =>
            $checks,
    ]);
}

/* -------------------------------------------------------------
 * Rank check route
 * ------------------------------------------------------------- */

if ($action !== 'check') {
    json_out([
        'ok'    => false,
        'code'  => 'route',
        'error' => 'Unknown action.',
    ], 404);
}

if ($method !== 'POST') {
    json_out([
        'ok'    => false,
        'code'  => 'method',
        'error' => 'Use POST for this endpoint.',
    ], 405);
}

/* -------------------------------------------------------------
 * Read JSON request
 * ------------------------------------------------------------- */

$raw =
    file_get_contents(
        'php://input'
    ) ?: '';

if (strlen($raw) > 4096) {
    json_out([
        'ok'    => false,
        'code'  => 'size',
        'error' => 'That request was too large.',
    ], 413);
}

$input =
    json_decode(
        $raw,
        true
    );

if (!is_array($input)) {
    json_out([
        'ok'    => false,
        'code'  => 'body',
        'error' => 'Send a JSON body.',
    ], 400);
}

/* -------------------------------------------------------------
 * CSRF
 * ------------------------------------------------------------- */

if (
    !Security::csrfValid(
        (string) (
            $input['csrf'] ?? ''
        )
    )
) {
    json_out([
        'ok'    => false,
        'code'  => 'csrf',
        'error' => 'Your session expired. Reload the page and try again.',
    ], 419);
}

/* -------------------------------------------------------------
 * Basic bot protection
 * ------------------------------------------------------------- */

if (
    trim(
        (string) (
            $input['company'] ?? ''
        )
    ) !== ''
) {
    json_out([
        'ok'    => false,
        'code'  => 'bot',
        'error' => 'That request looked automated.',
    ], 400);
}

$issued =
    (int) (
        $input['issued'] ?? 0
    );

if (
    $issued > 0 &&
    (time() - $issued) < 2
) {
    json_out([
        'ok'    => false,
        'code'  => 'bot',
        'error' => 'That was too fast. Try again in a second.',
    ], 429);
}

/* -------------------------------------------------------------
 * Visitor / validation
 * ------------------------------------------------------------- */

$visitor =
    Security::visitorKey();

RateLimiter::recordHit(
    $visitor
);

$valid =
    RankCheck::validate(
        $input
    );

if (!$valid['ok']) {
    json_out([
        'ok' =>
            false,

        'code' =>
            'input',

        'error' =>
            $valid['error'],

        'remaining' =>
            RateLimiter::remaining(
                $visitor
            ),
    ], 422);
}

$d =
    $valid['data'];

/* -------------------------------------------------------------
 * Cache
 * ------------------------------------------------------------- */

$key =
    RankCheck::cacheKey($d);

$cached =
    RankCheck::readCache($key);

/* -------------------------------------------------------------
 * Rate limits
 * ------------------------------------------------------------- */

$gate =
    RateLimiter::check(
        $visitor,
        $cached === null
    );

if (
    $gate['status'] !==
    RateLimiter::OK
) {
    $messages = [

        RateLimiter::MAINTENANCE =>
            App::setting(
                'maintenance_note'
            ),

        RateLimiter::BANNED =>
            'This tool is not available from your connection.',

        RateLimiter::BURST =>
            'That is a lot of checks at once. Wait a minute and try again.',

        RateLimiter::DAILY =>
            'You have used all ' .
            App::settingInt(
                'daily_limit'
            ) .
            ' checks for today.',

        RateLimiter::BUDGET =>
            'The tool has used its whole search allowance for today. It resets at midnight UTC.',
    ];

    json_out([
        'ok' =>
            false,

        'code' =>
            $gate['status'],

        'error' =>
            $messages[
                $gate['status']
            ] ?? 'Request refused.',

        'remaining' =>
            $gate['remaining'],

        'resets_in' =>
            $gate['resets_in'],
    ], 429);
}

/* -------------------------------------------------------------
 * Cached response
 * ------------------------------------------------------------- */

if ($cached !== null) {
    $cached['ok'] =
        true;

    $cached['remaining'] =
        RateLimiter::remaining(
            $visitor
        );

    $cached['free'] =
        true;

    json_out(
        $cached
    );
}

/* -------------------------------------------------------------
 * Rank provider
 *
 * RankCheck now handles:
 *
 * 1. VPS + Playwright + DataImpulse
 * 2. SerpApi fallback
 * ------------------------------------------------------------- */

$provider =
    ProviderFactory::make();

$budgetLeft =
    max(
        0,
        App::settingInt(
            'api_budget_day'
        ) -
        RateLimiter::budgetUsed()
    );

$result =
    RankCheck::run(
        $d,
        $provider,
        $budgetLeft
    );

/* -------------------------------------------------------------
 * Upstream failure
 * ------------------------------------------------------------- */

if (!$result['ok']) {
    /*
     * Failed provider requests must never
     * appear as "not ranking".
     *
     * Failed searches also do not consume
     * the visitor's daily allowance.
     */
    json_out([
        'ok' =>
            false,

        'code' =>
            'provider',

        'error' =>
            $result['error'],

        'remaining' =>
            RateLimiter::remaining(
                $visitor
            ),
    ], 502);
}

/* -------------------------------------------------------------
 * Consume successful check
 * ------------------------------------------------------------- */

RateLimiter::consume(
    $visitor,
    $result['api_calls']
);

/*
 * Actual provider used:
 *
 * vps_playwright
 * OR
 * serpapi
 */

$actualProvider =
    (string) (
        $result['provider'] ??
        $provider->name()
    );

/* -------------------------------------------------------------
 * Save check
 * ------------------------------------------------------------- */

Db::run(
    'INSERT INTO checks
    (
        ts,
        day,
        visitor,
        keyword,
        domain,
        gl,
        hl,
        depth,
        position,
        found,
        cached,
        api_calls,
        provider
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
    [
        time(),

        RateLimiter::today(),

        $visitor,

        $d['keyword'],

        $d['domain'],

        $d['gl'],

        $d['hl'],

        $d['depth'],

        $result['position'],

        $result['found']
            ? 1
            : 0,

        $result['api_calls'],

        $actualProvider,
    ]
);

/* -------------------------------------------------------------
 * Response
 * ------------------------------------------------------------- */

$payload = [

    'ok' =>
        true,

    'keyword' =>
        $d['keyword'],

    'domain' =>
        $d['domain'],

    'country' =>
        Geo::country(
            $d['gl']
        ),

    'language' =>
        Geo::language(
            $d['hl']
        ),

    'depth' =>
        $d['depth'],

    'position' =>
        $result['position'],

    'found' =>
        $result['found'],

    'scanned' =>
        $result['scanned'],

    'band' =>
        RankCheck::band(
            $result['position']
        ),

    'results' =>
        $result['results'],

    'notice' =>
        $result['error'],

    /*
     * Makes testing easy.
     *
     * Expected:
     *
     * vps_playwright
     * OR
     * serpapi
     */
    'provider' =>
        $actualProvider,

    'checked_at' =>
        time(),
];

/* -------------------------------------------------------------
 * Cache successful response
 * ------------------------------------------------------------- */

RankCheck::writeCache(
    $key,
    $payload
);

$payload['remaining'] =
    RateLimiter::remaining(
        $visitor
    );

$payload['free'] =
    false;

json_out(
    $payload
);
