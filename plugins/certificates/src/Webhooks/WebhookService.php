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
    protected WebhookDispatcher $dispatcher;

    public function __construct(Database $db, ?WebhookDispatcher $dispatcher = null)
    {
        $this->db = $db;
        $this->dispatcher = $dispatcher ?? new WebhookDispatcher($db);
    }

    public function getDispatcher(): WebhookDispatcher
    {
        return $this->dispatcher;
    }

    public function signPayload(string $rawPayload, string $secret, int $timestamp): string
    {
        return $this->dispatcher->signPayload($rawPayload, $secret, $timestamp);
    }

    public function createWebhook(int $tenantId, string $name, string $url, string $secret, array $events): int
    {
        $name = trim($name);
        $secret = trim($secret);
        $allowedEvents = ['certificate.issued', 'certificate.revoked', 'certificate.replaced', 'certificate.expired'];
        $events = array_values(array_unique($events));
        if ($tenantId < 1 || $name === '' || strlen($name) > 128 || strlen($url) > 255
            || !$this->isSafeUrl($url) || strlen($secret) < 32 || strlen($secret) > 64
            || $events === [] || array_diff($events, $allowedEvents) !== []) {
            throw new \InvalidArgumentException('Webhook name, public HTTPS target, secret, or event list is invalid.');
        }
        $table = $this->db->tableName('cert_webhooks');
        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, name, target_url, secret, events_json, is_active, created_at)
             VALUES (:tenant_id, :name, :url, :secret, :events, 1, CURRENT_TIMESTAMP)",
            [
                'tenant_id' => $tenantId,
                'name' => $name,
                'url' => $url,
                'secret' => $this->encryptSecret($secret),
                'events' => json_encode($events, JSON_THROW_ON_ERROR),
            ]
        );
        return $this->db->lastInsertId();
    }

    public function listWebhooks(int $tenantId): array
    {
        $table = $this->db->tableName('cert_webhooks');
        return $this->db->fetchAll(
            "SELECT id, name, target_url, events_json, is_active, created_at
             FROM {$table} WHERE tenant_id = :tenant_id ORDER BY id DESC",
            ['tenant_id' => $tenantId]
        );
    }

    public function queueEvent(int $tenantId, string $eventKey, array $payload): int
    {
        if ($tenantId < 1 || !in_array($eventKey, ['certificate.issued', 'certificate.revoked', 'certificate.replaced', 'certificate.expired'], true)) {
            throw new \InvalidArgumentException('Webhook event is unsupported.');
        }
        $webhooks = $this->db->tableName('cert_webhooks');
        $deliveries = $this->db->tableName('cert_webhook_deliveries');
        $targets = $this->db->fetchAll(
            "SELECT id, events_json FROM {$webhooks} WHERE tenant_id = :tenant_id AND is_active = 1",
            ['tenant_id' => $tenantId]
        );
        $rawPayload = json_encode(
            ['id' => 'evt_' . bin2hex(random_bytes(12)), 'type' => $eventKey, 'created_at' => gmdate(DATE_ATOM), 'data' => $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        $queued = 0;
        foreach ($targets as $target) {
            $subscribedEvents = json_decode((string)$target['events_json'], true);
            if (!is_array($subscribedEvents) || !in_array($eventKey, $subscribedEvents, true)) {
                continue;
            }
            $this->db->execute(
                "INSERT INTO {$deliveries}
                 (webhook_id, tenant_id, event_key, payload_json, attempts, status, created_at)
                 VALUES (:webhook_id, :tenant_id, :event_key, :payload, 0, 'pending', CURRENT_TIMESTAMP)",
                [
                    'webhook_id' => (int)$target['id'],
                    'tenant_id' => $tenantId,
                    'event_key' => $eventKey,
                    'payload' => $rawPayload,
                ]
            );
            $queued++;
        }
        return $queued;
    }

    public function dispatchDue(int $tenantId, int $limit = 20, ?callable $transport = null): array
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('A tenant is required to dispatch webhook deliveries.');
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
                    throw new \RuntimeException('Webhook destination failed the public-address safety check.');
                }
                $secret = $this->decryptSecret((string)$delivery['secret']);
                $timestamp = time();
                $signature = $this->signPayload((string)$delivery['payload_json'], $secret, $timestamp);
                $response = $transport === null
                    ? $this->sendPinnedRequest(
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
            } catch (\Throwable $e) {
                $statusCode = null;
                $responseBody = '';
                $error = substr($e->getMessage(), 0, 500);
                error_log('SOI webhook delivery ' . (int)$delivery['id'] . ' failed: ' . $e->getMessage());
            }
            $success = $statusCode !== null && $statusCode >= 200 && $statusCode < 300;
            $maxAttempts = 5;
            $retry = !$success && $attempt < $maxAttempts;
            $status = $success ? 'succeeded' : ($retry ? 'retry' : 'failed');
            $retryAt = $retry ? gmdate('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** ($attempt - 1)))) : null;
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

    public function isSafeUrl(string $url): bool
    {
        return $this->dispatcher->isSafeUrl($url);
    }

    private function encryptSecret(string $secret): string
    {
        $key = $this->encryptionKey();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Webhook signing secret could not be encrypted.');
        }
        return 'enc:' . base64_encode($nonce . $tag . $ciphertext);
    }

    private function decryptSecret(string $stored): string
    {
        if (!str_starts_with($stored, 'enc:')) {
            throw new \RuntimeException('Webhook signing secret is not encrypted; rotate it in settings.');
        }
        $encoded = base64_decode(substr($stored, 4), true);
        if ($encoded === false || strlen($encoded) < 29) {
            throw new \RuntimeException('Webhook signing secret could not be decoded.');
        }
        $nonce = substr($encoded, 0, 12);
        $tag = substr($encoded, 12, 16);
        $ciphertext = substr($encoded, 28);
        $secret = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, '');
        if ($secret === false) {
            throw new \RuntimeException('Webhook signing secret could not be decrypted.');
        }
        return $secret;
    }

    private function encryptionKey(): string
    {
        $configuredKey = getenv('SOI_CERT_WEBHOOK_ENCRYPTION_KEY') ?: '';
        if (strlen($configuredKey) < 32 || !function_exists('openssl_encrypt')) {
            throw new \RuntimeException('Configure SOI_CERT_WEBHOOK_ENCRYPTION_KEY with at least 32 characters and enable OpenSSL.');
        }
        return hash('sha256', $configuredKey, true);
    }

    private function sendPinnedRequest(string $url, string $payload, int $timestamp, string $signature): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('Webhook URL is invalid.');
        }
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        $scheme = strtolower((string)$parts['scheme']);
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = $this->resolvePublicAddresses($host);
        if ($addresses === []) {
            throw new \RuntimeException('Webhook destination did not resolve to a public IP address.');
        }
        $address = $addresses[0];
        $socketAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $context = stream_context_create($scheme === 'https' ? [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'disable_compression' => true,
            ],
        ] : []);
        $socket = @stream_socket_client(
            "{$transport}://{$socketAddress}:{$port}",
            $errorCode,
            $errorMessage,
            8,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new \RuntimeException('Webhook connection failed (' . (int)$errorCode . ').');
        }
        stream_set_timeout($socket, 8);
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $hostHeader = $host . ($defaultPort ? '' : ':' . $port);
        $request = "POST {$path} HTTP/1.1\r\n"
            . "Host: {$hostHeader}\r\n"
            . "Content-Type: application/json\r\n"
            . "X-SOI-Timestamp: {$timestamp}\r\n"
            . "X-SOI-Signature: v1={$signature}\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n" . $payload;
        $written = 0;
        while ($written < strlen($request)) {
            $count = fwrite($socket, substr($request, $written));
            if ($count === false || $count === 0) {
                fclose($socket);
                throw new \RuntimeException('Webhook request could not be sent completely.');
            }
            $written += $count;
        }
        $statusLine = fgets($socket, 2048);
        $statusCode = 0;
        if (is_string($statusLine) && preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', $statusLine, $matches)) {
            $statusCode = (int)$matches[1];
        }
        fclose($socket);
        if ($statusCode === 0) {
            throw new \RuntimeException('Webhook endpoint returned an invalid HTTP response.');
        }
        return ['status' => $statusCode, 'body' => ''];
    }

    private function resolvePublicAddresses(string $host): array
    {
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            $ipv4 = gethostbynamel($host);
            if (is_array($ipv4)) {
                $addresses = array_merge($addresses, $ipv4);
            }
            if (function_exists('dns_get_record')) {
                $records = dns_get_record($host, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (!empty($record['ipv6'])) {
                            $addresses[] = $record['ipv6'];
                        }
                    }
                }
            }
        }
        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return [];
            }
        }
        return array_values(array_unique($addresses));
    }
}
