<?php
declare(strict_types=1);

/**
 * Direct entrypoint for /forms
 */
$root = dirname(__DIR__);

if (is_file($root . '/config/config.php')) {
    require_once $root . '/config/config.php';
}
if (is_file($root . '/core/helpers.php')) {
    require_once $root . '/core/helpers.php';
}

$pluginFile = $root . '/plugins/certificates/plugin.php';
if (!is_file($pluginFile)) {
    http_response_code(500);
    die('Certificates plugin is not installed at /plugins/certificates.');
}
require_once $pluginFile;

$sub = $_SERVER['PATH_INFO'] ?? '';
if ($sub === '' && isset($_SERVER['REQUEST_URI'])) {
    $parsed = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    if (str_starts_with($parsed, '/forms')) {
        $sub = substr($parsed, 6);
    }
}

$uri = '/forms' . ($sub !== '' ? (str_starts_with($sub, '/') ? $sub : '/' . $sub) : '');
if (!empty($_SERVER['QUERY_STRING'])) {
    $uri .= '?' . $_SERVER['QUERY_STRING'];
}

\SOI\Certificates\Core\Plugin::getInstance()->handleRequest($_SERVER['REQUEST_METHOD'] ?? 'GET', $uri);
