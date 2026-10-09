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

try {
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
} catch (\Throwable $e) {
    error_log('[SOI Certificates Gateway Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    $adminUrl = defined('SOI_ADMIN_URL') ? rtrim(SOI_ADMIN_URL, '/') : '/admin';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Certificate Platform Error</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#f8fafc;padding:40px;line-height:1.6;}';
    echo '.box{max-width:800px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:32px;box-shadow:0 10px 25px rgba(0,0,0,0.5);}';
    echo 'h1{color:#ef4444;font-size:24px;margin-top:0;} pre{background:#090d16;padding:16px;border-radius:8px;overflow-x:auto;color:#cbd5e1;font-size:13px;line-height:1.4;}</style></head><body>';
    echo '<div class="box"><h1>Certificate Platform Initialisation Notice</h1>';
    echo '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>Location:</strong> ' . htmlspecialchars($e->getFile()) . ':' . (int)$e->getLine() . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '<p style="margin-top:24px;"><a href="' . htmlspecialchars($adminUrl . '/updates.php') . '" style="color:#38bdf8;text-decoration:none;font-weight:600;">&larr; Return to Admin Center</a></p>';
    echo '</div></body></html>';
}
