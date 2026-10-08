<?php
declare(strict_types=1);

namespace SOI\Certificates\Storage;

/**
 * Storage abstraction allowing local filesystem or future remote adapters (S3, R2).
 */
interface StorageAdapterInterface
{
    public function put(string $relativePath, string $content): bool;
    public function get(string $relativePath): ?string;
    public function exists(string $relativePath): bool;
    public function delete(string $relativePath): bool;
    public function hash(string $relativePath): ?string;
    public function size(string $relativePath): int;
    public function isWritable(?string $relativePath = null): bool;
    public function getFreeDiskSpace(?string $relativePath = null): float|int;
    public function hasSufficientDiskSpace(int $thresholdBytes = 10485760, ?string $relativePath = null): bool;
    public function getAbsolutePath(string $relativePath): string;
}
