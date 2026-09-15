<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * Application core: configuration, settings and editable content.
 *
 * Settings marked secret are encrypted at rest with AES-256-GCM using a key derived from
 * app_key in config.php. The database on its own never yields a usable API key.
 */
final class App
{
    private static array $config = [];
    private static array $settings = [];
    private static array $content = [];

    public const DEFAULT_SETTINGS = [
        'provider'          => 'serpapi',
        'daily_limit'       => '5',
        'burst_per_minute'  => '8',
        'api_budget_day'    => '90',
        'cache_ttl_hours'   => '12',
        'max_depth'         => '5',
        'maintenance'       => '0',
        'maintenance_note'  => 'The rank checker is being updated. It will be back shortly.',
        'retention_days'    => '90',
    ];

    public static function boot(array $config): void
    {
        self::$config = $config;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        if ($key === 'db_path') {
            return self::dbPath();
        }
        return self::$config[$key] ?? $default;
    }

    /**
     * Where the SQLite file lives.
     *
     * When db_path is left null the filename is derived from app_key, so it is unguessable.
     * That matters because this folder can be installed inside the web root, and a host that
     * quietly ignores .htaccess would otherwise let anyone download the database by name.
     */
    public static function dbPath(): string
    {
        $configured = self::$config['db_path'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $token = substr(hash_hmac('sha256', 'sqlite-file', (string) self::$config['app_key']), 0, 24);
        return dirname(__DIR__) . '/data/rc-' . $token . '.sqlite';
    }

    public static function providerCredentials(string $provider): array
    {
        $fromFile = self::$config['providers'][$provider] ?? [];
        $fromDb   = [];
        foreach (['api_key'] as $field) {
            $v = self::setting('provider_' . $provider . '_' . $field, '');
            if ($v !== '') {
                $fromDb[$field] = $v;
            }
        }
        // Values set in the admin panel take precedence over the config file.
        return array_merge($fromFile, $fromDb);
    }

    /* ---------------------------------------------------------------- settings */

    public static function setting(string $key, ?string $default = null): string
    {
        if (self::$settings === []) {
            foreach (Db::all('SELECT k, v, secret FROM settings') as $row) {
                self::$settings[$row['k']] = ((int) $row['secret'] === 1)
                    ? self::decrypt((string) $row['v'])
                    : (string) $row['v'];
            }
        }
        $fallback = $default ?? (self::DEFAULT_SETTINGS[$key] ?? '');
        if (array_key_exists($key, self::$settings) && self::$settings[$key] !== '') {
            return self::$settings[$key];
        }
        // An empty stored value means the field was submitted blank, so fall back to the
        // default rather than shipping an empty message to a visitor.
        return $fallback;
    }

    public static function settingInt(string $key): int
    {
        return (int) self::setting($key);
    }

    public static function setSetting(string $key, string $value, bool $secret = false): void
    {
        $stored = $secret ? self::encrypt($value) : $value;
        Db::run(
            'INSERT INTO settings (k, v, secret, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(k) DO UPDATE SET v = excluded.v, secret = excluded.secret, updated_at = excluded.updated_at',
            [$key, $stored, $secret ? 1 : 0, time()]
        );
        self::$settings[$key] = $value;
    }

    /* ---------------------------------------------------------------- content */

    public static function content(string $key): string
    {
        if (self::$content === []) {
            foreach (Db::all('SELECT k, v FROM content') as $row) {
                self::$content[$row['k']] = (string) $row['v'];
            }
        }
        if (array_key_exists($key, self::$content)) {
            return self::$content[$key];
        }
        return Content::DEFAULTS[$key] ?? '';
    }

    public static function setContent(string $key, string $value): void
    {
        Db::run(
            'INSERT INTO content (k, v, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = excluded.updated_at',
            [$key, $value, time()]
        );
        self::$content[$key] = $value;
    }

    /* ---------------------------------------------------------------- crypto */

    private static function key(): string
    {
        $raw = base64_decode((string) self::config('app_key'), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new RuntimeException('app_key is missing or too short. Generate 32 random bytes, base64 encoded.');
        }
        return substr($raw, 0, 32);
    }

    public static function encrypt(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $out = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $out === false ? '' : $out;
    }

    /* ---------------------------------------------------------------- housekeeping */

    public static function audit(string $actor, string $action, string $meta = ''): void
    {
        Db::run('INSERT INTO audit (ts, actor, action, meta) VALUES (?, ?, ?, ?)', [time(), $actor, $action, $meta]);
    }

    /**
     * Retention runs on roughly one percent of requests. Shared hosting does not always
     * offer cron, so this keeps the database from growing without bound either way.
     */
    public static function maybePrune(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        $days   = max(7, self::settingInt('retention_days'));
        $cutoff = time() - ($days * 86400);
        Db::run('DELETE FROM checks WHERE ts < ?', [$cutoff]);
        Db::run('DELETE FROM hits WHERE ts < ?', [time() - 3600]);
        Db::run('DELETE FROM login_attempts WHERE ts < ?', [time() - 86400]);
        Db::run('DELETE FROM audit WHERE ts < ?', [$cutoff]);
        Db::run('DELETE FROM usage_day WHERE day < ?', [gmdate('Y-m-d', $cutoff)]);
        Db::run('DELETE FROM budget_day WHERE day < ?', [gmdate('Y-m-d', $cutoff)]);
        Db::run('DELETE FROM serp_cache WHERE created < ?', [time() - 7 * 86400]);
    }
}
