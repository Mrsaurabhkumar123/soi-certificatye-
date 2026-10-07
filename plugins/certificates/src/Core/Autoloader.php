<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

/**
 * PSR-4 zero-dependency autoloader for the SOI Certificate Platform.
 * Avoids deployment-time Composer overhead on shared hosting.
 */
class Autoloader
{
    protected static string $prefix = 'SOI\\Certificates\\';
    protected static string $baseDir = '';
    protected static bool $registered = false;

    public static function register(string $baseDir): void
    {
        if (self::$registered) {
            return;
        }

        self::$baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        spl_autoload_register(function (string $class) {
            $len = strlen(self::$prefix);
            if (strncmp(self::$prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = self::$baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });

        self::$registered = true;
    }
}
