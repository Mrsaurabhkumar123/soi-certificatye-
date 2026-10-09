<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

/**
 * Route Gateway & Server Adapter.
 * Provides direct filesystem routing for LiteSpeed/OpenLiteSpeed/Nginx/IIS
 * web servers when standard mod_rewrite front-controller rules are absent.
 */
class Gateway
{
    public static function dispatch(string $defaultRoute): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/config/config.php') && is_file(dirname(__DIR__, 3) . '/config/config.php')) {
            $root = dirname(__DIR__, 3);
        }

        // Bootstrap Host CMS if present
        if (is_file($root . '/config/config.php')) {
            require_once $root . '/config/config.php';
        }
        if (is_file($root . '/core/helpers.php')) {
            require_once $root . '/core/helpers.php';
        }

        // Bootstrap Plugin
        $pluginFile = dirname(__DIR__, 2) . '/plugin.php';
        if (is_file($pluginFile)) {
            require_once $pluginFile;
        }

        // Extract subpath
        $sub = $_SERVER['PATH_INFO'] ?? '';
        if ($sub === '' && isset($_SERVER['REQUEST_URI'])) {
            $parsed = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
            $base = '/' . trim($defaultRoute, '/');
            if (str_starts_with($parsed, $base)) {
                $sub = substr($parsed, strlen($base));
            }
        }

        $uri = '/' . trim($defaultRoute, '/') . ($sub !== '' ? (str_starts_with($sub, '/') ? $sub : '/' . $sub) : '');
        if (!empty($_SERVER['QUERY_STRING'])) {
            $uri .= '?' . $_SERVER['QUERY_STRING'];
        }

        Plugin::getInstance()->handleRequest($_SERVER['REQUEST_METHOD'] ?? 'GET', $uri);
        exit;
    }
}

if (!function_exists('soi_cert_dispatch_route')) {
    function soi_cert_dispatch_route(string $defaultRoute): void
    {
        Gateway::dispatch($defaultRoute);
    }
}
