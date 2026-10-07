<?php
declare(strict_types=1);

namespace SOI\Certificates\Webhooks;

use SOI\Certificates\Core\Database;

/**
 * Webhook delivery service with HMAC-SHA256 signature and SSRF guards.
 */
class WebhookService
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function signPayload(string $rawPayload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $secret);
    }

    public function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || !in_array($parts['scheme'], ['https', 'http'], true)) {
            return false;
        }

        $host = $parts['host'] ?? '';
        if (empty($host)) {
            return false;
        }

        // Basic SSRF protections: block loopback, local, and metadata endpoints
        $blocked = ['localhost', '127.0.0.1', '::1', '169.254.169.254'];
        if (in_array(strtolower($host), $blocked, true)) {
            return false;
        }

        return true;
    }
}
