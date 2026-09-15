<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * Three independent layers, because each one catches a different attacker.
 *
 *   1. Burst      short sliding window, stops a naive loop immediately
 *   2. Daily      the product rule, per visitor, server side only
 *   3. Budget     absolute ceiling on upstream calls, protects the quota and the bill
 *
 * The cache sits in front of all three, so a repeated identical request is free and does
 * not consume the daily allowance either.
 */
final class RateLimiter
{
    public const OK          = 'ok';
    public const BANNED      = 'banned';
    public const BURST       = 'burst';
    public const DAILY       = 'daily';
    public const BUDGET      = 'budget';
    public const MAINTENANCE = 'maintenance';

    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    public static function recordHit(string $visitor): void
    {
        Db::run('INSERT INTO hits (ts, visitor) VALUES (?, ?)', [time(), $visitor]);
    }

    public static function used(string $visitor): int
    {
        return (int) Db::scalar(
            'SELECT used FROM usage_day WHERE day = ? AND visitor = ?',
            [self::today(), $visitor]
        );
    }

    public static function remaining(string $visitor): int
    {
        return max(0, App::settingInt('daily_limit') - self::used($visitor));
    }

    public static function budgetUsed(): int
    {
        return (int) Db::scalar('SELECT calls FROM budget_day WHERE day = ?', [self::today()]);
    }

    /** Seconds until the daily allowance resets, measured in UTC. */
    public static function resetsIn(): int
    {
        return (int) (strtotime(gmdate('Y-m-d') . ' 23:59:59 UTC') - time() + 1);
    }

    /**
     * @param bool $willCallApi false when the answer is already cached, which makes the
     *                          request free and exempt from the daily allowance.
     * @return array{status:string, remaining:int, resets_in:int}
     */
    public static function check(string $visitor, bool $willCallApi): array
    {
        $remaining = self::remaining($visitor);
        $out = static fn(string $s): array => [
            'status'    => $s,
            'remaining' => $remaining,
            'resets_in' => self::resetsIn(),
        ];

        if (App::setting('maintenance') === '1') {
            return $out(self::MAINTENANCE);
        }

        if (Db::one('SELECT visitor FROM bans WHERE visitor = ?', [$visitor]) !== null) {
            return $out(self::BANNED);
        }

        $window = (int) Db::scalar(
            'SELECT COUNT(*) FROM hits WHERE visitor = ? AND ts > ?',
            [$visitor, time() - 60]
        );
        if ($window > App::settingInt('burst_per_minute')) {
            return $out(self::BURST);
        }

        if (!$willCallApi) {
            return $out(self::OK);
        }

        if ($remaining <= 0) {
            return $out(self::DAILY);
        }

        if (self::budgetUsed() >= App::settingInt('api_budget_day')) {
            return $out(self::BUDGET);
        }

        return $out(self::OK);
    }

    public static function consume(string $visitor, int $apiCalls): void
    {
        $day = self::today();

        Db::run(
            'INSERT INTO usage_day (day, visitor, used) VALUES (?, ?, 1)
             ON CONFLICT(day, visitor) DO UPDATE SET used = used + 1',
            [$day, $visitor]
        );

        if ($apiCalls > 0) {
            Db::run(
                'INSERT INTO budget_day (day, calls) VALUES (?, ?)
                 ON CONFLICT(day) DO UPDATE SET calls = calls + ?',
                [$day, $apiCalls, $apiCalls]
            );
        }
    }
}
