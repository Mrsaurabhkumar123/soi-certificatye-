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
}

return \SOI\Certificates\Core\Plugin::getInstance();
