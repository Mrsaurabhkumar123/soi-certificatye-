<?php
declare(strict_types=1);

namespace SOI\Certificates\Storage;

use Exception;
use RuntimeException;

/**
 * Local filesystem storage implementation with directory traversal prevention.
 */
class LocalStorageAdapter implements StorageAdapterInterface
{
    protected string $baseDir;

    public function __construct(string $baseDir)
    {
        $baseDir = rtrim($baseDir, '/\\');
        if ($baseDir === '') {
            throw new RuntimeException('Storage base directory is required.');
        }
        if (!is_dir($baseDir)) {
            if (!mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
                throw new RuntimeException('Unable to initialize local storage.');
            }
        }
        $realBase = realpath($baseDir);
        if ($realBase === false || !is_dir($realBase)) {
            throw new RuntimeException('Local storage directory cannot be resolved safely.');
        }
        $this->baseDir = rtrim($realBase, '/\\');
        $this->protectDirectory($this->baseDir);
        foreach (['certificates', 'certificate-assets'] as $directory) {
            $this->ensureDirectory($this->baseDir . DIRECTORY_SEPARATOR . $directory);
        }
    }

    public function sanitizePath(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:/', $relativePath)) {
            throw new Exception('Invalid storage path.');
        }
        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                throw new Exception('Invalid storage path.');
            }
        }
        return implode('/', $segments);
    }

    public function getAbsolutePath(string $relativePath): string
    {
        $clean = $this->sanitizePath($relativePath);
        $path = $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
        $this->assertContained($path);
        return $path;
    }

    public function put(string $relativePath, string $content): bool
    {
        $fullPath = $this->getAbsolutePath($relativePath);
        $dir = dirname($fullPath);
        $this->ensureDirectory($dir);
        $this->assertContained($fullPath);
        $temporary = $fullPath . '.tmp-' . bin2hex(random_bytes(8));
        $written = file_put_contents($temporary, $content, LOCK_EX);
        if ($written === false || $written !== strlen($content)) {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
            return false;
        }
        if (!rename($temporary, $fullPath)) {
            unlink($temporary);
            return false;
        }
        return true;
    }

    public function get(string $relativePath): ?string
    {
        $fullPath = $this->getAbsolutePath($relativePath);
        if (!file_exists($fullPath)) {
            return null;
        }
        $data = file_get_contents($fullPath);
        return $data !== false ? $data : null;
    }

    public function exists(string $relativePath): bool
    {
        return file_exists($this->getAbsolutePath($relativePath));
    }

    public function delete(string $relativePath): bool
    {
        $fullPath = $this->getAbsolutePath($relativePath);
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    public function hash(string $relativePath): ?string
    {
        $fullPath = $this->getAbsolutePath($relativePath);
        if (!file_exists($fullPath)) {
            return null;
        }
        return hash_file('sha256', $fullPath);
    }

    public function size(string $relativePath): int
    {
        $fullPath = $this->getAbsolutePath($relativePath);
        if (!file_exists($fullPath)) {
            return 0;
        }
        return (int)filesize($fullPath);
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function isWritable(?string $relativePath = null): bool
    {
        $path = $relativePath !== null && $relativePath !== ''
            ? $this->getAbsolutePath($relativePath)
            : $this->baseDir;

        return is_dir($path) ? is_writable($path) : (file_exists($path) ? is_writable($path) : is_writable(dirname($path)));
    }

    public function getFreeDiskSpace(?string $relativePath = null): float|int
    {
        $path = $relativePath !== null && $relativePath !== ''
            ? $this->getAbsolutePath($relativePath)
            : $this->baseDir;

        $target = file_exists($path) ? $path : dirname($path);
        $space = @disk_free_space($target);
        return $space !== false ? $space : -1;
    }

    public function hasSufficientDiskSpace(int $thresholdBytes = 10485760, ?string $relativePath = null): bool
    {
        $free = $this->getFreeDiskSpace($relativePath);
        if ($free < 0) {
            return true;
        }
        return $free >= $thresholdBytes;
    }

    private function ensureDirectory(string $directory): void
    {
        $directory = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create a local storage directory.');
        }
        $this->assertContained($directory);
        $this->protectDirectory($directory);
    }

    private function assertContained(string $path): void
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            $parent = realpath(dirname($path));
            if ($parent === false) {
                return;
            }
            $resolved = $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        $base = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR;
        if (strncasecmp($resolved, $base, strlen($base)) !== 0) {
            throw new Exception('Storage path escapes the configured base directory.');
        }
    }

    private function protectDirectory(string $directory): void
    {
        $htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess)) {
            if (file_put_contents($htaccess, "Options -Indexes\nRequire all denied\n") === false) {
                throw new RuntimeException('Unable to protect local storage from direct web access.');
            }
        }
        $index = $directory . DIRECTORY_SEPARATOR . 'index.html';
        if (!file_exists($index)) {
            if (file_put_contents($index, '') === false) {
                throw new RuntimeException('Unable to initialize local storage protection files.');
            }
        }
    }
}
