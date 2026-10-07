<?php
declare(strict_types=1);

/**
 * Local Developer Standalone Server Gateway.
 * Allows instant local testing via `php -S localhost:8000 index.php`.
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';

// Serve static assets directly if requested
if (str_starts_with($uri, '/assets/')) {
    $assetFile = __DIR__ . '/plugins/certificates/' . ltrim($uri, '/');
    if (file_exists($assetFile)) {
        $ext = pathinfo($assetFile, PATHINFO_EXTENSION);
        $mime = match($ext) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            default => 'text/plain',
        };
        header("Content-Type: {$mime}");
        readfile($assetFile);
        exit;
    }
}

// Bootstrap plugin
/** @var \SOI\Certificates\Core\Plugin $plugin */
$plugin = require __DIR__ . '/plugins/certificates/plugin.php';
$plugin->handleRequest();
