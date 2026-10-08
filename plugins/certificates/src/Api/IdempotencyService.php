<?php
declare(strict_types=1);

namespace SOI\Certificates\Api;

use InvalidArgumentException;
use RuntimeException;
use SOI\Certificates\Core\Database;

final class IdempotencyService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function fingerprint(array $request, ?int $clientId = null): string
    {
        return hash('sha256', json_encode(
            ['client_id' => $clientId, 'request' => self::sortKeys($request)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    public function replayOrReserve(int $tenantId, string $key, string $fingerprint): ?array
    {
        $this->validate($tenantId, $key, $fingerprint);
        $table = $this->db->tableName('cert_idempotency');
        $this->db->execute(
            "DELETE FROM {$table} WHERE tenant_id = :tenant_id AND idempotency_key = :key AND expires_at <= CURRENT_TIMESTAMP",
            ['tenant_id' => $tenantId, 'key' => $key]
        );
        $existing = $this->db->fetchOne(
            "SELECT request_fingerprint, response_code, response_body
             FROM {$table}
             WHERE tenant_id = :tenant_id AND idempotency_key = :key AND expires_at > CURRENT_TIMESTAMP",
            ['tenant_id' => $tenantId, 'key' => $key]
        );
        if ($existing !== null) {
            if (!hash_equals((string)$existing['request_fingerprint'], $fingerprint)) {
                throw new InvalidArgumentException('Idempotency key was already used for a different request.');
            }
            if ((int)$existing['response_code'] === 0 || $existing['response_body'] === '') {
                throw new RuntimeException('An identical request is still being processed.');
            }
            $response = json_decode((string)$existing['response_body'], true);
            if (!is_array($response)) {
                throw new RuntimeException('The stored idempotency response is invalid.');
            }
            return ['status' => (int)$existing['response_code'], 'body' => (string)$existing['response_body']];
        }

        if ($this->db->getDriver() === 'sqlite') {
            $created = $this->db->execute(
                "INSERT OR IGNORE INTO {$table}
                 (tenant_id, idempotency_key, request_fingerprint, response_code, response_body, created_at, expires_at)
                 VALUES (:tenant_id, :key, :fingerprint, 0, '', CURRENT_TIMESTAMP, datetime('now', '+24 hours'))",
                ['tenant_id' => $tenantId, 'key' => $key, 'fingerprint' => $fingerprint]
            );
        } else {
            $created = $this->db->execute(
                "INSERT IGNORE INTO {$table}
                 (tenant_id, idempotency_key, request_fingerprint, response_code, response_body, created_at, expires_at)
                 VALUES (:tenant_id, :key, :fingerprint, 0, '', CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))",
                ['tenant_id' => $tenantId, 'key' => $key, 'fingerprint' => $fingerprint]
            );
        }
        if ($created > 0) {
            return null;
        }

        $existing = $this->db->fetchOne(
            "SELECT request_fingerprint, response_code, response_body FROM {$table}
             WHERE tenant_id = :tenant_id AND idempotency_key = :key",
            ['tenant_id' => $tenantId, 'key' => $key]
        );
        if ($existing === null) {
            throw new RuntimeException('Unable to reserve the idempotency key.');
        }
        if (!hash_equals((string)$existing['request_fingerprint'], $fingerprint)) {
            throw new InvalidArgumentException('Idempotency key was already used for a different request.');
        }
        if ((int)$existing['response_code'] === 0 || $existing['response_body'] === '') {
            throw new RuntimeException('An identical request is still being processed.');
        }
        return ['status' => (int)$existing['response_code'], 'body' => (string)$existing['response_body']];
    }

    public function complete(int $tenantId, string $key, string $fingerprint, int $status, array $body): void
    {
        $this->validate($tenantId, $key, $fingerprint);
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Idempotency response status is invalid.');
        }
        $table = $this->db->tableName('cert_idempotency');
        $updated = $this->db->execute(
            "UPDATE {$table} SET response_code = :status, response_body = :body
             WHERE tenant_id = :tenant_id AND idempotency_key = :key AND request_fingerprint = :fingerprint",
            [
                'status' => $status,
                'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'tenant_id' => $tenantId,
                'key' => $key,
                'fingerprint' => $fingerprint,
            ]
        );
        if ($updated !== 1) {
            throw new RuntimeException('The idempotency reservation could not be completed.');
        }
    }

    private function validate(int $tenantId, string $key, string $fingerprint): void
    {
        if ($tenantId < 1 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) || !preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new InvalidArgumentException('Idempotency key is invalid.');
        }
    }

    private static function sortKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }
        return $value;
    }
}
