<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

/**
 * Enterprise session manager and CSRF protection utility.
 */
class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
            ]);
        }
    }

    public static function getCsrfToken(): string
    {
        self::start();
        if (empty($_SESSION['_cert_csrf_token'])) {
            $_SESSION['_cert_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_cert_csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::start();
        $stored = $_SESSION['_cert_csrf_token'] ?? '';
        if (empty($stored) || empty($token)) {
            return false;
        }
        return hash_equals($stored, $token);
    }

    public static function csrfField(): string
    {
        $token = htmlspecialchars(self::getCsrfToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }

    public static function flash(string $key, ?string $message = null): ?string
    {
        self::start();
        if ($message !== null) {
            $_SESSION['_flash_' . $key] = $message;
            return null;
        }
        $val = $_SESSION['_flash_' . $key] ?? null;
        unset($_SESSION['_flash_' . $key]);
        return $val;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }
}
