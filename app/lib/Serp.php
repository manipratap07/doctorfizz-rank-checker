<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * DoctorFizz search provider.
 *
 * This build intentionally supports SerpApi only. The SerpApi key stays server-side.
 */
interface SerpProvider
{
    public function name(): string;

    /**
     * Fetch one block of ten Google organic results through SerpApi.
     *
     * @param int $start App offset is 1-based: 1, 11, 21, ...
     * @return array{ok:bool,items:array<int,array{title:string,link:string,snippet:string,position:int}>,error:string}
     */
    public function fetchPage(string $query, string $gl, string $hl, int $start): array;
}

final class Http
{
    /** @return array{ok:bool,status:int,body:string,error:string} */
    public static function getJson(string $url, array $headers = []): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false, 'status' => 0, 'body' => '',
                'error' => 'The cURL extension is not available on this server.',
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_USERAGENT      => 'DoctorFizz-RankChecker/1.0 (+https://itzfizz.com)',
        ]);

        // Refuse to read an unbounded response.
        $max = 2 * 1024 * 1024;
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 16384);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($res, $dlTotal, $dlNow) use ($max) {
            return $dlNow > $max ? 1 : 0;
        });

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok' => false, 'status' => $status, 'body' => '',
                'error' => $err !== '' ? $err : 'Request failed.',
            ];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => (string) $body,
            'error' => '',
        ];
    }
}

final class SerpApiProvider implements SerpProvider
{
    public function __construct(private string $apiKey)
    {
    }

    public function name(): string
    {
        return 'serpapi';
    }

    public function fetchPage(string $query, string $gl, string $hl, int $start): array
    {
        if ($this->apiKey === '') {
            return [
                'ok' => false,
                'items' => [],
                'error' => 'SerpApi is not configured. Add the SerpApi API key in app/config.php.',
            ];
        }

        // RankCheck uses 1, 11, 21...; SerpApi uses 0, 10, 20...
        $offset = max(0, $start - 1);

        $url = 'https://serpapi.com/search.json?' . http_build_query([
            'engine'  => 'google',
            'q'       => $query,
            'gl'      => strtolower($gl),
            'hl'      => strtolower($hl),
            'start'   => $offset,
            'num'     => 10,
            'api_key' => $this->apiKey,
        ], '', '&', PHP_QUERY_RFC3986);

        $res  = Http::getJson($url);
        $data = json_decode($res['body'], true);

        if (!$res['ok']) {
            if ($res['status'] === 429) {
                return ['ok' => false, 'items' => [], 'error' => 'The SerpApi allowance is exhausted or rate limited.'];
            }
            if (in_array($res['status'], [401, 403], true)) {
                return ['ok' => false, 'items' => [], 'error' => 'SerpApi rejected the API key. Check the key in app/config.php.'];
            }
            if (is_array($data) && !empty($data['error'])) {
                return [
                    'ok' => false,
                    'items' => [],
                    'error' => 'SerpApi error: ' . mb_substr((string) $data['error'], 0, 240),
                ];
            }
            return ['ok' => false, 'items' => [], 'error' => 'SerpApi did not respond successfully.'];
        }

        if (!is_array($data)) {
            return ['ok' => false, 'items' => [], 'error' => 'SerpApi returned an unreadable response.'];
        }

        if (!empty($data['error'])) {
            return [
                'ok' => false,
                'items' => [],
                'error' => 'SerpApi error: ' . mb_substr((string) $data['error'], 0, 240),
            ];
        }

        $items = [];
        foreach ($data['organic_results'] ?? [] as $item) {
            if (!is_array($item) || empty($item['link'])) {
                continue;
            }

            $fallbackPosition = $start + count($items);
            $position = isset($item['position']) && is_numeric($item['position'])
                ? (int) $item['position']
                : $fallbackPosition;

            $items[] = [
                'title'    => (string) ($item['title'] ?? ''),
                'link'     => (string) $item['link'],
                'snippet'  => (string) ($item['snippet'] ?? ''),
                'position' => max(1, $position),
            ];

            if (count($items) >= 10) {
                break;
            }
        }

        return ['ok' => true, 'items' => $items, 'error' => ''];
    }
}

final class ProviderFactory
{
    public static function make(): SerpProvider
    {
        $credentials = App::providerCredentials('serpapi');
        return new SerpApiProvider((string) ($credentials['api_key'] ?? ''));
    }

    public static function label(string $key = 'serpapi'): string
    {
        return 'SerpApi';
    }
}
