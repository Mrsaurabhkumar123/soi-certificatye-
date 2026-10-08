<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

use RuntimeException;

/**
 * Reads identity only from the trusted CMS bootstrap, never from request fields.
 */
final class CmsIdentity
{
    private function __construct(
        public readonly ?int $userId,
        public readonly bool $isPlatformAdmin,
        public readonly ?int $activeTenantId
    ) {
    }

    public static function current(): self
    {
        $provider = $GLOBALS['soi_certificate_identity_provider'] ?? null;
        if (is_callable($provider)) {
            $identity = $provider();
            if (!is_array($identity)) {
                throw new RuntimeException('The CMS identity provider must return an identity array.');
            }
        } else {
            $identity = $GLOBALS['soi_certificate_identity'] ?? null;
            if ($identity !== null && !is_array($identity)) {
                throw new RuntimeException('The CMS identity global must be an identity array.');
            }
        }

        if (is_array($identity)) {
            $userIdValue = $identity['user_id'] ?? null;
            $tenantIdValue = $identity['active_tenant_id'] ?? null;
            $userId = $userIdValue === null ? null : filter_var($userIdValue, FILTER_VALIDATE_INT);
            $tenantId = $tenantIdValue === null ? null : filter_var($tenantIdValue, FILTER_VALIDATE_INT);
            $isPlatformAdmin = ($identity['is_platform_admin'] ?? false) === true;

            if ($userId === false || ($userId !== null && $userId < 1)) {
                throw new RuntimeException('The CMS identity provider returned an invalid user ID.');
            }
            if ($isPlatformAdmin && $userId === null) {
                throw new RuntimeException('Platform administrator identity requires a CMS user ID.');
            }
            if ($tenantId === false || ($tenantId !== null && $tenantId < 1)) {
                throw new RuntimeException('The CMS identity provider returned an invalid tenant ID.');
            }

            return new self($userId === null ? null : (int)$userId, $isPlatformAdmin, $tenantId === null ? null : (int)$tenantId);
        }

        if (getenv('SOI_CERT_ENV') === 'development' && getenv('SOI_CERT_STANDALONE_DEMO') === '1') {
            return new self(1, true, null);
        }

        return new self(null, false, null);
    }
}
