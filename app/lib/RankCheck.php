<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

final class RankCheck
{
    /**
     * Validate and normalise the request. Allowlists only, no coercion of bad input.
     *
     * @return array{ok:bool, error:string, data:array}
     */
    public static function validate(array $in): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'data' => []];

        $keyword = trim((string) ($in['keyword'] ?? ''));
        $keyword = preg_replace('/[\x00-\x1F\x7F]/u', '', $keyword) ?? '';

        if ($keyword === '') {
            return $fail('Enter the keyword you want to check.');
        }

        if (mb_strlen($keyword) > 120) {
            return $fail('Keep the keyword under 120 characters.');
        }

        $domain = self::normaliseDomain((string) ($in['domain'] ?? ''));

        if ($domain === null) {
            return $fail('Enter a valid domain, for example itzfizz.com.');
        }

        $gl = strtolower(trim((string) ($in['gl'] ?? 'uk')));

        if (!isset(Geo::COUNTRIES[$gl])) {
            return $fail('Choose a country from the list.');
        }

        $hl = trim((string) ($in['hl'] ?? 'en'));

        if (!isset(Geo::LANGUAGES[$hl])) {
            return $fail('Choose a language from the list.');
        }

        $depth = (int) ($in['depth'] ?? 1);

        $maxDepth = max(
            1,
            App::settingInt('max_depth')
        );

        if (
            !in_array($depth, [1, 2, 3, 5, 10], true) ||
            $depth > $maxDepth
        ) {
            return $fail('Choose a search depth from the list.');
        }

        return [
            'ok' => true,
            'error' => '',
            'data' => compact(
                'keyword',
                'domain',
                'gl',
                'hl',
                'depth'
            ),
        ];
    }

    /**
     * Accepts itzfizz.com, www.itzfizz.com,
     * https://itzfizz.com/blog and returns itzfizz.com.
     */
    public static function normaliseDomain(string $raw): ?string
    {
        $raw = trim(strtolower($raw));

        if ($raw === '' || mb_strlen($raw) > 253) {
            return null;
        }

        if (!str_contains($raw, '://')) {
            $raw = 'https://' . $raw;
        }

        $host = parse_url(
            $raw,
            PHP_URL_HOST
        );

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (
            filter_var(
                $host,
                FILTER_VALIDATE_IP
            ) !== false
        ) {
            return null;
        }

        if (
            preg_match(
                '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                $host
            ) !== 1
        ) {
            return null;
        }

        if (
            in_array(
                $host,
                ['localhost', 'localhost.localdomain'],
                true
            )
        ) {
            return null;
        }

        return $host;
    }

    public static function cacheKey(array $d): string
    {
        return hash(
            'sha256',
            implode('|', [
                'hybrid-v1',
                $d['keyword'],
                $d['domain'],
                $d['gl'],
                $d['hl'],
                (string) $d['depth'],
            ])
        );
    }

    public static function readCache(string $key): ?array
    {
        $ttl = max(
            1,
            App::settingInt('cache_ttl_hours')
        ) * 3600;

        $row = Db::one(
            'SELECT payload, created
             FROM serp_cache
             WHERE k = ?
             AND created > ?',
            [
                $key,
                time() - $ttl,
            ]
        );

        if ($row === null) {
            return null;
        }

        $data = json_decode(
            (string) $row['payload'],
            true
        );

        if (!is_array($data)) {
            return null;
        }

        $data['cached'] = true;
        $data['cached_at'] = (int) $row['created'];

        return $data;
    }

    public static function writeCache(
        string $key,
        array $payload
    ): void {
        unset(
            $payload['cached'],
            $payload['cached_at']
        );

        Db::run(
            'INSERT INTO serp_cache
             (k, payload, created)
             VALUES (?, ?, ?)
             ON CONFLICT(k)
             DO UPDATE SET
             payload = excluded.payload,
             created = excluded.created',
            [
                $key,
                json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES
                ),
                time(),
            ]
        );
    }

    /**
     * Host aware match.
     */
    public static function linkMatchesDomain(
        string $link,
        string $domain
    ): bool {
        $host = parse_url(
            $link,
            PHP_URL_HOST
        );

        if (!is_string($host)) {
            return false;
        }

        $host = strtolower(
            rtrim($host, '.')
        );

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return
            $host === $domain ||
            str_ends_with(
                $host,
                '.' . $domain
            );
    }

    /**
     * Primary provider:
     *
     * Doctorfizz
     * → VPS
     * → Playwright
     * → DataImpulse
     * → Google
     *
     * If worker fails, times out or Google blocks it,
     * return null so SerpApi automatically becomes fallback.
     */
    private static function tryVpsWorker(array $d): ?array
    {
        $base = rtrim(
            trim(
                (string) App::config(
                    'rank_worker_url',
                    ''
                )
            ),
            '/'
        );

        $secret = trim(
            (string) App::config(
                'rank_worker_secret',
                ''
            )
        );

        if (
            $base === '' ||
            $secret === '' ||
            !function_exists('curl_init')
        ) {
            return null;
        }

        $payload = json_encode(
            [
                'keyword' => $d['keyword'],
                'domain' => $d['domain'],
                'country' => $d['gl'],
                'language' => $d['hl'],
                'depth' => $d['depth'],
            ],
            JSON_UNESCAPED_SLASHES
        );

        if (!is_string($payload)) {
            return null;
        }

        $ch = curl_init(
            $base . '/rank'
        );

        curl_setopt_array(
            $ch,
            [
                CURLOPT_POST => true,

                CURLOPT_POSTFIELDS =>
                    $payload,

                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_CONNECTTIMEOUT =>
                    5,

                CURLOPT_TIMEOUT =>
                    20,

                CURLOPT_FOLLOWLOCATION =>
                    false,

                CURLOPT_SSL_VERIFYPEER =>
                    true,

                CURLOPT_SSL_VERIFYHOST =>
                    2,

                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $secret,
                ],

                CURLOPT_USERAGENT =>
                    'DoctorFizz-RankChecker/2.0',
            ]
        );

        $body = curl_exec($ch);

        $status = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        /*
         * Worker unavailable,
         * Cloudflare tunnel unavailable,
         * proxy timeout,
         * Google block,
         * etc.
         *
         * Existing SerpApi flow below
         * will automatically take over.
         */
        if (
            $body === false ||
            $status < 200 ||
            $status >= 300
        ) {
            return null;
        }

        $data = json_decode(
            (string) $body,
            true
        );

        if (
            !is_array($data) ||
            empty($data['ok'])
        ) {
            return null;
        }

        $results = [];

        foreach (
            ($data['results'] ?? [])
            as $item
        ) {
            if (
                !is_array($item) ||
                empty($item['link'])
            ) {
                continue;
            }

            $results[] = [
                'rank' => max(
                    1,
                    (int) (
                        $item['rank'] ??
                        (count($results) + 1)
                    )
                ),

                'title' =>
                    Security::safeSnippet(
                        (string) (
                            $item['title'] ?? ''
                        )
                    ),

                'link' =>
                    (string) $item['link'],

                'snippet' =>
                    '',

                'match' =>
                    !empty($item['match']),
            ];
        }

        $position =
            isset($data['position']) &&
            is_numeric($data['position'])
                ? (int) $data['position']
                : null;

        return [
            'ok' => true,

            'error' => '',

            'results' =>
                $results,

            'position' =>
                $position,

            'found' =>
                !empty($data['found']),

            'scanned' => max(
                count($results),
                (int) (
                    $data['scanned'] ?? 0
                )
            ),

            /*
             * VPS call does not consume
             * SerpApi budget.
             */
            'api_calls' => 0,

            'provider' =>
                'vps_playwright',
        ];
    }

    /**
     * VPS worker is primary.
     * Existing SerpApi provider remains fallback.
     */
    public static function run(
        array $d,
        SerpProvider $provider,
        int $budgetLeft
    ): array {
        /*
         * PRIMARY
         *
         * VPS
         * → Playwright
         * → DataImpulse
         * → Google
         */
        $workerResult =
            self::tryVpsWorker($d);

        if ($workerResult !== null) {
            return $workerResult;
        }

        /*
         * FALLBACK
         *
         * Existing SerpApi implementation.
         */
        $results = [];

        $position = null;

        $apiCalls = 0;

        $error = '';

        for (
            $page = 1;
            $page <= $d['depth'];
            $page++
        ) {
            if ($apiCalls >= $budgetLeft) {
                $error =
                    'The daily search allowance for this tool ran out mid check. The results below are partial.';

                break;
            }

            $start =
                ($page - 1) * 10 + 1;

            $res =
                $provider->fetchPage(
                    $d['keyword'],
                    $d['gl'],
                    $d['hl'],
                    $start
                );

            $apiCalls++;

            if (!$res['ok']) {
                if ($results === []) {
                    return [
                        'ok' => false,

                        'error' =>
                            $res['error'],

                        'results' =>
                            [],

                        'position' =>
                            null,

                        'found' =>
                            false,

                        'scanned' =>
                            0,

                        'api_calls' =>
                            $apiCalls,

                        'provider' =>
                            $provider->name(),
                    ];
                }

                $error =
                    $res['error'];

                break;
            }

            if ($res['items'] === []) {
                break;
            }

            foreach (
                $res['items']
                as $i => $item
            ) {
                $rank =
                    isset($item['position'])
                        ? (int) $item['position']
                        : ($start + $i);

                $match =
                    self::linkMatchesDomain(
                        $item['link'],
                        $d['domain']
                    );

                $results[] = [
                    'rank' =>
                        $rank,

                    'title' =>
                        Security::safeSnippet(
                            $item['title']
                        ),

                    'link' =>
                        $item['link'],

                    'snippet' =>
                        Security::safeSnippet(
                            $item['snippet']
                        ),

                    'match' =>
                        $match,
                ];

                if (
                    $match &&
                    $position === null
                ) {
                    $position =
                        $rank;
                }
            }

            if ($position !== null) {
                break;
            }
        }

        return [
            'ok' => true,

            'error' =>
                $error,

            'results' =>
                $results,

            'position' =>
                $position,

            'found' =>
                $position !== null,

            'scanned' =>
                count($results),

            'api_calls' =>
                $apiCalls,

            'provider' =>
                $provider->name(),
        ];
    }

    /**
     * The action band a position falls into.
     */
    public static function band(?int $position): array
    {
        if ($position === null) {
            return [
                'key' => 'none',
                'label' => 'Not in range',
                'tone' => 'warn',
            ];
        }

        if ($position <= 3) {
            return [
                'key' => 'top3',
                'label' => 'Top 3',
                'tone' => 'good',
            ];
        }

        if ($position <= 10) {
            return [
                'key' => 'page1',
                'label' => 'Page one',
                'tone' => 'good',
            ];
        }

        if ($position <= 20) {
            return [
                'key' => 'striking',
                'label' => 'Striking distance',
                'tone' => 'mid',
            ];
        }

        return [
            'key' => 'deep',
            'label' => 'Deep',
            'tone' => 'warn',
        ];
    }
}
