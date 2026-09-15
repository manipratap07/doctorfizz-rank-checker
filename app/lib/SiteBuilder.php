<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * Static site generator.
 *
 * The public page is plain HTML on disk. It is rebuilt only when an admin saves content,
 * which means a visitor never triggers PHP, the page is served straight off LiteSpeed, and
 * the whole rendered text is in the HTML source where search engines and AI crawlers read it.
 */
final class SiteBuilder
{
    public static function build(): array
    {
        $target = public_root() . '/index.html';

        ob_start();
        require __DIR__ . '/../templates/page.php';
        $html = (string) ob_get_clean();

        // Trim the runs of whitespace that indentation leaves behind, without touching pre or textarea.
        $html = preg_replace('/\n\s*\n+/', "\n", $html) ?? $html;

        // Every asset the page references must exist on disk before the page is written.
        // A stylesheet that 404s produces a page that looks broken but reports no error,
        // which is exactly the failure this build survived three times.
        $missing = self::missingAssets($html);
        if ($missing !== []) {
            return [
                'ok' => false, 'bytes' => 0,
                'error' => 'These files are referenced by the page but are not on disk: '
                         . implode(', ', $missing) . '. The page was not written.',
            ];
        }

        $written = @file_put_contents($target, $html, LOCK_EX);
        if ($written === false) {
            return ['ok' => false, 'bytes' => 0, 'error' => 'Could not write ' . $target . '. Check that the directory is writable by PHP.'];
        }

        Db::run(
            'INSERT INTO settings (k, v, secret, updated_at) VALUES ("last_build", ?, 0, ?)
             ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = excluded.updated_at',
            [(string) time(), time()]
        );

        return ['ok' => true, 'bytes' => (int) $written, 'error' => ''];
    }

    /**
     * Local assets referenced by the built page that do not exist.
     *
     * @return array<int,string>
     */
    public static function missingAssets(string $html): array
    {
        $root = public_root();
        $missing = [];

        if (preg_match_all('/(?:href|src)="((?:\.\/)?assets\/[^"?]+)/i', $html, $m) === false) {
            return [];
        }

        foreach (array_unique($m[1] ?? []) as $rel) {
            $path = $root . '/' . ltrim((string) $rel, './');
            if (!is_file($path)) {
                $missing[] = (string) $rel;
            }
        }

        return $missing;
    }

    public static function lastBuild(): int
    {
        return (int) App::setting('last_build', '0');
    }

    /** Cache busting token so a rebuild never serves a stale stylesheet. */
    public static function assetVersion(): string
    {
        $t = self::lastBuild();
        return substr(hash('crc32b', (string) ($t > 0 ? $t : 1)), 0, 8);
    }
}
