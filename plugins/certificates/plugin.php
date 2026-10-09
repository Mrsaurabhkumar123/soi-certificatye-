<?php
declare(strict_types=1);

/**
 * Plugin Name: SOI Certificate Management Platform
 * Description: Enterprise modular multi-tenant certificate issuance, verification, and management platform.
 * Version: 1.0.0
 * Author: School Of Interns Development Team
 */

if (!defined('SOI_CERTIFICATES_LOADED')) {
    define('SOI_CERTIFICATES_LOADED', true);

    require_once __DIR__ . '/src/Core/Autoloader.php';
    \SOI\Certificates\Core\Autoloader::register(__DIR__ . '/src');

    // Initialize Plugin instance
    \SOI\Certificates\Core\Plugin::init(__DIR__);

    // Register into Host CMS Admin Menu if running inside SOI Source CMS
    if (class_exists('\SOI\Core\Plugin') && method_exists('\SOI\Core\Plugin', 'addAdminMenu')) {
        $manageUrl = defined('SOI_HOME_URL') ? rtrim(SOI_HOME_URL, '/') . '/manage' : '/manage';
        \SOI\Core\Plugin::addAdminMenu(
            title:    'Certificates',
            slug:     'certificates',
            url:      $manageUrl,
            icon:     '🎓',
            position: 45
        );
    }

    // Register frontend route handler if running inside SOI Source CMS Hook system
    if (function_exists('add_action')) {
        add_action('soi_routes', function (string $uri, string $method) {
            $routes = ['manage', 'console', 'verify', 'forms', 'docs', 'super-admin', 'api/v1', 'scheduler'];
            $cleanUri = trim($uri, '/');
            $cleanUri = preg_replace('#^index\.php/?#', '', $cleanUri);
            foreach ($routes as $route) {
                if ($cleanUri === $route || str_starts_with($cleanUri, $route . '/')) {
                    \SOI\Certificates\Core\Plugin::getInstance()->handleRequest();
                    exit;
                }
            }
        });
    }
}

return \SOI\Certificates\Core\Plugin::getInstance();
