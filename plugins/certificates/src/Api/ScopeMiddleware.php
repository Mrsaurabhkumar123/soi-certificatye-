<?php
declare(strict_types=1);

namespace SOI\Certificates\Api;

final class ScopeMiddleware
{
    public function allows(array $client, string $requiredScope): bool
    {
        $scopes = $client['scopes'] ?? [];
        return is_array($scopes) && in_array($requiredScope, $scopes, true);
    }
}
