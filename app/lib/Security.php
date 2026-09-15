<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

final class Security
{
    private static string $nonce = '';
    private static string $visitor = '';

    /* ---------------------------------------------------------------- headers */

    public static function nonce(): string
    {
        if (self::$nonce === '') {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    public static function sendHeaders(): void
    {
        $nonce = self::nonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data:",
            "style-src 'self' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "script-src 'self' 'nonce-$nonce'",
            "connect-src 'self'",
        ]);

        header('Content-Security-Policy: ' . $csp);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), browsing-topics=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header_remove('X-Powered-By');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /* ---------------------------------------------------------------- session */

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('dfsess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);
        session_start();
    }

    /* ---------------------------------------------------------------- csrf */

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfValid(?string $token): bool
    {
        self::startSession();
        $expected = $_SESSION['csrf'] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    /* ---------------------------------------------------------------- visitor identity */

    /**
     * A visitor key that is stable enough to enforce a daily quota and holds no personal data.
     * It combines a keyed hash of the IP address with a signed long lived cookie. Clearing the
     * cookie does not reset the quota because the IP component still matches.
     */
    public static function visitorKey(): string
    {
        if (self::$visitor !== '') {
            return self::$visitor;
        }

        $pepper = (string) App::config('ip_pepper');
        $ipHash = substr(hash_hmac('sha256', self::clientIp(), $pepper), 0, 32);

        $cookie = $_COOKIE['dfvid'] ?? '';
        $id     = '';
        if (is_string($cookie) && str_contains($cookie, '.')) {
            [$candidate, $sig] = explode('.', $cookie, 2);
            $want = hash_hmac('sha256', $candidate, $pepper);
            if (preg_match('/^[a-f0-9]{24}$/', $candidate) === 1 && hash_equals($want, $sig)) {
                $id = $candidate;
            }
        }
        if ($id === '') {
            $id  = bin2hex(random_bytes(12));
            $val = $id . '.' . hash_hmac('sha256', $id, $pepper);
            setcookie('dfvid', $val, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => self::isHttps(),
            ]);
        }

        // The IP component leads, so a cleared cookie still lands on the same bucket.
        self::$visitor = substr(hash('sha256', $ipHash . '|' . $id), 0, 40);
        return self::$visitor;
    }

    /** Coarse key used for login throttling, IP only, still hashed. */
    public static function sourceKey(): string
    {
        return substr(hash_hmac('sha256', self::clientIp(), (string) App::config('ip_pepper')), 0, 32);
    }

    private static function clientIp(): string
    {
        // Only trust a forwarded header when the host is known to sit behind a proxy.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (App::setting('trust_proxy', '0') === '1') {
            $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($fwd !== '') {
                $first = trim(explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    $ip = $first;
                }
            }
        }
        return (string) $ip;
    }

    /* ---------------------------------------------------------------- output */

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Search providers return markup inside titles and snippets. Keep only bold and break,
     * drop every attribute, and escape everything else.
     */
    public static function safeSnippet(?string $html): string
    {
        $s = (string) $html;
        $s = preg_replace('/<(?!\/?(b|strong|br)\b)[^>]*>/i', '', $s) ?? '';
        $s = strip_tags($s, '<b><strong><br>');
        $s = preg_replace('/<(b|strong|br)[^>]*>/i', '<$1>', $s) ?? '';
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        return trim(mb_substr($s, 0, 400));
    }

    /* ---------------------------------------------------------------- admin auth */

    public static function adminId(): ?int
    {
        self::startSession();
        $id = $_SESSION['admin_id'] ?? null;
        return is_int($id) ? $id : null;
    }

    public static function requireAdmin(string $adminBase): void
    {
        if (self::adminId() === null) {
            header('Location: ' . $adminBase);
            exit;
        }
    }

    public static function loginBlocked(): bool
    {
        $since = time() - 900;
        $fails = (int) Db::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE source = ? AND ok = 0 AND ts > ?',
            [self::sourceKey(), $since]
        );
        return $fails >= 5;
    }

    public static function recordLogin(bool $ok): void
    {
        Db::run('INSERT INTO login_attempts (ts, source, ok) VALUES (?, ?, ?)', [time(), self::sourceKey(), $ok ? 1 : 0]);
        if ($ok) {
            Db::run('DELETE FROM login_attempts WHERE source = ? AND ok = 0', [self::sourceKey()]);
        }
    }
}
