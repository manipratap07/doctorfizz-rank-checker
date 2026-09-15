<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * SQLite access layer. One file, no server, no credentials to leak.
 * Migrations are idempotent so a redeploy cannot double apply them.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = App::config('db_path');
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        self::migrate($pdo);

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS settings (
                k          TEXT PRIMARY KEY,
                v          TEXT NOT NULL,
                secret     INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS content (
                k          TEXT PRIMARY KEY,
                v          TEXT NOT NULL,
                updated_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS checks (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                ts        INTEGER NOT NULL,
                day       TEXT NOT NULL,
                visitor   TEXT NOT NULL,
                keyword   TEXT NOT NULL,
                domain    TEXT NOT NULL,
                gl        TEXT NOT NULL,
                hl        TEXT NOT NULL,
                depth     INTEGER NOT NULL,
                position  INTEGER,
                found     INTEGER NOT NULL DEFAULT 0,
                cached    INTEGER NOT NULL DEFAULT 0,
                api_calls INTEGER NOT NULL DEFAULT 0,
                provider  TEXT NOT NULL DEFAULT ""
            );
            CREATE INDEX IF NOT EXISTS idx_checks_day ON checks(day);
            CREATE INDEX IF NOT EXISTS idx_checks_ts  ON checks(ts);

            CREATE TABLE IF NOT EXISTS usage_day (
                day     TEXT NOT NULL,
                visitor TEXT NOT NULL,
                used    INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, visitor)
            );

            CREATE TABLE IF NOT EXISTS budget_day (
                day   TEXT PRIMARY KEY,
                calls INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS serp_cache (
                k       TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                created INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_cache_created ON serp_cache(created);

            CREATE TABLE IF NOT EXISTS hits (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                ts      INTEGER NOT NULL,
                visitor TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_hits_ts ON hits(ts);

            CREATE TABLE IF NOT EXISTS bans (
                visitor TEXT PRIMARY KEY,
                reason  TEXT NOT NULL DEFAULT "",
                created INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS admins (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                username   TEXT NOT NULL UNIQUE,
                pass_hash  TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                last_login INTEGER
            );

            CREATE TABLE IF NOT EXISTS login_attempts (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                ts      INTEGER NOT NULL,
                source  TEXT NOT NULL,
                ok      INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_login_ts ON login_attempts(ts);

            CREATE TABLE IF NOT EXISTS audit (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                ts     INTEGER NOT NULL,
                actor  TEXT NOT NULL,
                action TEXT NOT NULL,
                meta   TEXT NOT NULL DEFAULT ""
            );
        ');
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $args = []): ?array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($args);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $args = []): array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function run(string $sql, array $args = []): void
    {
        $st = self::conn()->prepare($sql);
        $st->execute($args);
    }

    public static function scalar(string $sql, array $args = [], mixed $default = 0): mixed
    {
        $st = self::conn()->prepare($sql);
        $st->execute($args);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : $v;
    }
}
