<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

use RuntimeException;

/**
 * Validates CMS accounts through the trusted host integration.
 */
final class CmsUserDirectory
{
    public function userExists(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $provider = $GLOBALS['soi_certificate_user_exists_provider'] ?? null;
        if (is_callable($provider)) {
            $exists = $provider($userId);
            if (!is_bool($exists)) {
                throw new RuntimeException('The CMS user directory provider must return a boolean.');
            }
            return $exists;
        }

        if (getenv('SOI_CERT_ENV') === 'development' && getenv('SOI_CERT_STANDALONE_DEMO') === '1') {
            return $userId === 1;
        }

        throw new RuntimeException('The SOI CMS user directory provider is not configured.');
    }
}
