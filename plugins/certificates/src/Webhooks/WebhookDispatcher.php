<?php
declare(strict_types=1);

namespace SOI\Certificates\Webhooks;

use SOI\Certificates\Core\Database;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Webhook Dispatcher Engine.
 * Responsible for HMAC-SHA256 signature generation, payload formatting,
 * delivery retries with exponential backoff, and SSRF loopback protections.
 */
class WebhookDispatcher
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Generate HMAC-SHA256 signature for payload verification.
     */
    public function signPayload(string $rawPayload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $secret);
    }

    /**
     * Format an event payload envelope with ISO 8601 timestamp and unique event ID.
     */
    public function formatPayload(string $eventKey, array $data, ?string $eventId = null): string
    {
        $id = $eventId ?? ('evt_' . bin2hex(random_bytes(12)));
        return json_encode(
            [
                'id' => $id,
                'type' => $eventKey,
                'created_at' => gmdate(DATE_ATOM),
                'data' => $data,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Validate target URL against SSRF attacks and loopback/internal addresses.
     */
    public function isSafeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        if (empty($host)) {
            return false;
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || $host === 'metadata.google.internal' || $host === 'metadata') {
            return false;
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            $ipv4 = gethostbynamel($host);
            if (is_array($ipv4)) {
                $addresses = array_merge($addresses, $ipv4);
            }
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($host, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (!empty($record['ipv6'])) {
                            $addresses[] = $record['ipv6'];
                        }
                    }
                }
            }
        }
        if ($addresses === []) {
            return false;
        }

        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compute exponential backoff delay in seconds.
     */
    public function calculateRetryDelay(int $attempt): int
    {
        return min(3600, 30 * (2 ** max(0, $attempt - 1)));
    }

    /**
     * Send an HTTP POST webhook request with HMAC signatures and timeout guards.
     */
    public function sendRequest(string $url, string $payload, int $timestamp, string $signature, int $timeoutSeconds = 5): array
    {
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'X-SOI-Timestamp: ' . $timestamp,
            'X-SOI-Signature: ' . $signature,
            'User-Agent: SOI-Certificates-Webhook/2.0',
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            $lastError = error_get_last();
            throw new RuntimeException($lastError['message'] ?? 'Unable to establish secure webhook connection.');
        }

        $meta = stream_get_meta_data($stream);
        $body = stream_get_contents($stream) ?: '';
        fclose($stream);

        $status = 0;
        foreach ($meta['wrapper_data'] ?? [] as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                $status = (int)$matches[1];
            }
        }

        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    /**
     * Dispatch due deliveries for a given tenant.
     */
    public function dispatchDue(int $tenantId, int $limit = 20, ?callable $transport = null, ?callable $secretResolver = null): array
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('A tenant is required to dispatch webhook deliveries.');
        }
        $limit = max(1, min($limit, 100));
        $deliveries = $this->db->tableName('cert_webhook_deliveries');
        $webhooks = $this->db->tableName('cert_webhooks');
        $due = $this->db->fetchAll(
            "SELECT d.id, d.webhook_id, d.event_key, d.payload_json, d.attempts, w.target_url, w.secret
             FROM {$deliveries} d
             JOIN {$webhooks} w ON w.id = d.webhook_id AND w.tenant_id = d.tenant_id
             WHERE d.tenant_id = :tenant_id AND w.is_active = 1
               AND d.status IN ('pending', 'retry')
               AND (d.next_retry_at IS NULL OR d.next_retry_at <= CURRENT_TIMESTAMP)
             ORDER BY d.id ASC LIMIT {$limit}",
            ['tenant_id' => $tenantId]
        );

        $result = ['claimed' => 0, 'succeeded' => 0, 'retrying' => 0, 'failed' => 0];

        foreach ($due as $delivery) {
            $claimed = $this->db->execute(
                "UPDATE {$deliveries} SET status = 'processing', attempts = attempts + 1
                 WHERE id = :id AND tenant_id = :tenant_id AND status IN ('pending', 'retry')
                   AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP)",
                ['id' => (int)$delivery['id'], 'tenant_id' => $tenantId]
            );
            if ($claimed !== 1) {
                continue;
            }
            $result['claimed']++;
            $attempt = (int)$delivery['attempts'] + 1;

            try {
                if (!$this->isSafeUrl((string)$delivery['target_url'])) {
                    throw new RuntimeException('Webhook destination failed the public-address safety check.');
                }

                $secret = $secretResolver !== null
                    ? $secretResolver((string)$delivery['secret'])
                    : (string)$delivery['secret'];

                $timestamp = time();
                $signature = $this->signPayload((string)$delivery['payload_json'], $secret, $timestamp);
                $response = $transport === null
                    ? $this->sendRequest(
                        (string)$delivery['target_url'],
                        (string)$delivery['payload_json'],
                        $timestamp,
                        $signature
                    )
                    : $transport(
                        (string)$delivery['target_url'],
                        (string)$delivery['payload_json'],
                        $timestamp,
                        $signature
                    );

                $statusCode = (int)($response['status'] ?? 0);
                $responseBody = substr((string)($response['body'] ?? ''), 0, 2048);
                $error = $statusCode >= 200 && $statusCode < 300 ? null : 'Endpoint returned HTTP ' . $statusCode . '.';
            } catch (Throwable $e) {
                $statusCode = null;
                $responseBody = '';
                $error = substr($e->getMessage(), 0, 500);
                error_log('SOI webhook delivery ' . (int)$delivery['id'] . ' failed: ' . $e->getMessage());
            }

            $success = $statusCode !== null && $statusCode >= 200 && $statusCode < 300;
            $maxAttempts = 5;
            $retry = !$success && $attempt < $maxAttempts;
            $status = $success ? 'succeeded' : ($retry ? 'retry' : 'failed');
            $retryAt = $retry ? gmdate('Y-m-d H:i:s', time() + $this->calculateRetryDelay($attempt)) : null;

            $this->db->execute(
                "UPDATE {$deliveries} SET status = :status, response_status = :response_status,
                 response_body = :response_body, next_retry_at = :next_retry_at
                 WHERE id = :id AND tenant_id = :tenant_id AND status = 'processing'",
                [
                    'status' => $status,
                    'response_status' => $statusCode,
                    'response_body' => $error ?? $responseBody,
                    'next_retry_at' => $retryAt,
                    'id' => (int)$delivery['id'],
                    'tenant_id' => $tenantId,
                ]
            );
            $result[$status === 'succeeded' ? 'succeeded' : ($retry ? 'retrying' : 'failed')]++;
        }

        return $result;
    }
}
