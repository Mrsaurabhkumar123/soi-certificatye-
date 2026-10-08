<?php
declare(strict_types=1);

namespace SOI\Certificates\Storage;

use RuntimeException;

final class FileIntegrity
{
    public function checksum(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Artifact is missing or unreadable.');
        }
        $hash = hash_file('sha256', $filePath);
        if ($hash === false) {
            throw new RuntimeException('Unable to calculate artifact integrity checksum.');
        }
        return $hash;
    }

    public function matches(string $filePath, string $expectedSha256): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $expectedSha256)) {
            return false;
        }
        return hash_equals(strtolower($expectedSha256), $this->checksum($filePath));
    }
}
