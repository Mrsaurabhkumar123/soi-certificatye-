<?php
declare(strict_types=1);

namespace SOI\Certificates\Storage;

use RuntimeException;

final class ArtifactStorageService
{
    public function __construct(private readonly StorageAdapterInterface $storage)
    {
    }

    public function storeVerified(string $relativePath, string $content): string
    {
        if (!$this->storage->put($relativePath, $content)) {
            throw new RuntimeException('Artifact could not be stored.');
        }
        $expectedHash = hash('sha256', $content);
        $storedHash = $this->storage->hash($relativePath);
        if ($storedHash === null || !hash_equals($expectedHash, $storedHash)) {
            $this->storage->delete($relativePath);
            throw new RuntimeException('Stored artifact failed its integrity check.');
        }
        return $storedHash;
    }

    public function readVerified(string $relativePath, string $expectedHash): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            throw new RuntimeException('Artifact integrity metadata is invalid.');
        }
        $content = $this->storage->get($relativePath);
        if ($content === null || $content === '') {
            throw new RuntimeException('Artifact is unavailable.');
        }
        $actualHash = hash('sha256', $content);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('Artifact integrity validation failed.');
        }
        return $content;
    }

    public function delete(string $relativePath): bool
    {
        return $this->storage->delete($relativePath);
    }
}
