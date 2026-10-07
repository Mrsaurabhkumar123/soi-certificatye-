<?php
declare(strict_types=1);

namespace SOI\Certificates\Storage;

use Exception;

/**
 * Local filesystem storage implementation with directory traversal prevention.
 */
class LocalStorageAdapter implements StorageAdapterInterface
{
    protected string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
    }

    public function sanitizePath(string $relativePath): string
    {
        $clean = str_replace(['..', '\\', "\0"], ['', '/', ''], $relativePath);
        return ltrim($clean, '/');
    }

    public function getAbsolutePath(string $relativePath): string
    {
        $clean = $this->sanitizePath($relativePath);
        return $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
    }

    public function put(string $relativePath, string $content): bool
    {
        $fullPath = $this->getAbsolutePath($relativePath);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($fullPath, $content) !== false;
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
}
