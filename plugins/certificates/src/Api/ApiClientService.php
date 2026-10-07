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
        $clientId = 'sk_' . bin2hex(random_bytes(12));
        $secret = 'sec_' . bin2hex(random_bytes(24));
        $secretHash = hash('sha256', $secret);

        $table = $this->db->tableName('cert_api_clients');
        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, name, client_id, secret_hash, scopes_json, status, created_at)
             VALUES (:tid, :name, :cid, :shash, :scopes, 'active', datetime('now'))",
            [
                'tid' => $tenantId,
                'name' => $name,
                'cid' => $clientId,
                'shash' => $secretHash,
                'scopes' => json_encode($scopes ?: ['certificates.issue', 'certificates.read']),
            ]
        );

        return [
            'client_id' => $clientId,
            'secret' => $secret, // Revealed ONCE
            'scopes' => $scopes,
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
                "UPDATE {$table} SET last_used_at = datetime('now') WHERE id = :id",
                ['id' => $client['id']]
            );
            $client['scopes'] = json_decode($client['scopes_json'] ?? '[]', true) ?: [];
            return $client;
        }

        return null;
    }
}
