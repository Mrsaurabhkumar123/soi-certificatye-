<?php
declare(strict_types=1);

namespace SOI\Certificates\Api;

use SOI\Certificates\Core\Database;

/**
 * API client management and Bearer token credential verification.
 */
class ApiClientService
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function createClient(int $tenantId, string $name, array $scopes = []): array
    {
        $name = trim($name);
        if ($tenantId < 1 || $name === '') {
            throw new \InvalidArgumentException('API client tenant and name are required.');
        }
        $allowedScopes = [
            'platform.read',
            'templates.read',
            'templates.manage',
            'certificates.read',
            'certificates.issue',
            'certificates.revoke',
            'users.read',
            'users.manage',
            'roles.read',
        ];
        if ($scopes === []) {
            $scopes = ['certificates.issue', 'certificates.read'];
        }
        if (array_diff($scopes, $allowedScopes) !== []) {
            throw new \InvalidArgumentException('The API client requested an unsupported scope.');
        }

        $clientId = 'sk_' . bin2hex(random_bytes(12));
        $secret = 'sec_' . bin2hex(random_bytes(24));
        $secretHash = hash('sha256', $secret);

        $table = $this->db->tableName('cert_api_clients');
        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, name, client_id, secret_hash, scopes_json, status, created_at)
             VALUES (:tid, :name, :cid, :shash, :scopes, 'active', CURRENT_TIMESTAMP)",
            [
                'tid' => $tenantId,
                'name' => $name,
                'cid' => $clientId,
                'shash' => $secretHash,
                'scopes' => json_encode(array_values(array_unique($scopes))),
            ]
        );

        return [
            'client_id' => $clientId,
            'secret' => $secret, // Revealed ONCE
            'scopes' => array_values(array_unique($scopes)),
        ];
    }

    public function authenticateBearer(string $bearerToken): ?array
    {
        $secretHash = hash('sha256', trim($bearerToken));
        $table = $this->db->tableName('cert_api_clients');
        $client = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE secret_hash = :shash AND status = 'active'",
            ['shash' => $secretHash]
        );

        if ($client) {
            $this->db->execute(
                "UPDATE {$table} SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id",
                ['id' => $client['id']]
            );
            $client['scopes'] = json_decode($client['scopes_json'] ?? '[]', true) ?: [];
            return $client;
        }

        return null;
    }

    public function listForTenant(int $tenantId): array
    {
        $table = $this->db->tableName('cert_api_clients');
        return $this->db->fetchAll(
            "SELECT id, name, client_id, scopes_json, status, created_at, last_used_at
             FROM {$table} WHERE tenant_id = :tenant_id ORDER BY id DESC",
            ['tenant_id' => $tenantId]
        );
    }

    public function revoke(int $tenantId, int $clientId): bool
    {
        if ($tenantId < 1 || $clientId < 1) {
            return false;
        }
        $table = $this->db->tableName('cert_api_clients');
        return $this->db->execute(
            "UPDATE {$table} SET status = 'revoked'
             WHERE id = :id AND tenant_id = :tenant_id AND status = 'active'",
            ['id' => $clientId, 'tenant_id' => $tenantId]
        ) === 1;
    }

    /**
     * Rotate client API secret. Generates a new cryptographically secure secret,
     * hashes it for storage, and returns the plaintext secret exactly once.
     */
    public function rotateSecret(int $tenantId, int $clientId): ?array
    {
        if ($tenantId < 1 || $clientId < 1) {
            return null;
        }
        $table = $this->db->tableName('cert_api_clients');
        $existing = $this->db->fetchOne(
            "SELECT id, client_id, scopes_json FROM {$table} WHERE id = :id AND tenant_id = :tenant_id AND status = 'active'",
            ['id' => $clientId, 'tenant_id' => $tenantId]
        );
        if (!$existing) {
            return null;
        }

        $newSecret = 'sec_' . bin2hex(random_bytes(24));
        $newHash = hash('sha256', $newSecret);

        $this->db->execute(
            "UPDATE {$table} SET secret_hash = :shash WHERE id = :id AND tenant_id = :tenant_id AND status = 'active'",
            ['shash' => $newHash, 'id' => $clientId, 'tenant_id' => $tenantId]
        );

        return [
            'client_id' => (string)$existing['client_id'],
            'secret' => $newSecret,
            'scopes' => json_decode($existing['scopes_json'] ?? '[]', true) ?: [],
        ];
    }
}
