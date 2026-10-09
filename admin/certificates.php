<?php
declare(strict_types=1);

/**
 * Admin Panel — Certificates Gateway
 * Provides direct, authenticated CMS admin access to Certificate Management Platform.
 */
if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__));
}

require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';

// Autoload CMS core
spl_autoload_register(function (string $class) {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use SOI\Core\{Database, Auth};

Database::connect([
    'host'   => SOI_DB_HOST,
    'name'   => SOI_DB_NAME,
    'user'   => SOI_DB_USER,
    'pass'   => SOI_DB_PASS,
    'port'   => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Auth::init();
Auth::requireAuth('author');

// Route inside Certificate Platform
$pluginFile = SOI_ROOT . '/plugins/certificates/plugin.php';
if (!file_exists($pluginFile)) {
    die('Certificate plugin not installed at /plugins/certificates.');
}
require_once $pluginFile;

$sub = $_GET['route'] ?? $_SERVER['PATH_INFO'] ?? '/manage';
if (!str_starts_with($sub, '/')) {
    $sub = '/' . $sub;
}

$_SERVER['REQUEST_URI'] = $sub;
\SOI\Certificates\Core\Plugin::getInstance()->handleRequest($_SERVER['REQUEST_METHOD'] ?? 'GET', $sub);
