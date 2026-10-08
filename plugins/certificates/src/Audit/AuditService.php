<?php
declare(strict_types=1);

namespace SOI\Certificates\Audit;

use SOI\Certificates\Core\Database;

/**
 * Append-only security and domain audit service.
 * Redacts secrets, tokens, and sensitive credentials prior to persistence.
 */
class AuditService
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function log(
        ?int $tenantId,
        string $actorType,
        ?int $actorId,
        string $eventKey,
        string $targetType,
        string $targetId,
        array $metadata = []
    ): void {
        $cleanMetadata = $this->sanitizeMetadata($metadata);
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? ('req_' . bin2hex(random_bytes(6)));
        $sourceIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $table = $this->db->tableName('cert_audit_log');
        $this->db->execute(
            "INSERT INTO {$table} 
            (tenant_id, actor_type, actor_id, event_key, target_type, target_id, request_id, source_ip, metadata_json, created_at)
            VALUES (:tid, :atype, :aid, :event, :ttype, :tid_val, :req, :ip, :meta, CURRENT_TIMESTAMP)",
            [
                'tid' => $tenantId,
                'atype' => $actorType,
                'aid' => $actorId,
                'event' => $eventKey,
                'ttype' => $targetType,
                'tid_val' => $targetId,
                'req' => $requestId,
                'ip' => $sourceIp,
                'meta' => json_encode($cleanMetadata),
            ]
        );
    }

    public function getRecent(int $tenantId, int $limit = 50): array
    {
        $table = $this->db->tableName('cert_audit_log');
        return $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE tenant_id = :tid ORDER BY id DESC LIMIT :limit",
            ['tid' => $tenantId, 'limit' => $limit]
        );
    }

    protected function sanitizeMetadata(array $data): array
    {
        $redactedKeys = ['secret', 'api_key', 'token', 'pin', 'password', 'private_key', 'credential'];
        array_walk_recursive($data, function (&$val, $key) use ($redactedKeys) {
            foreach ($redactedKeys as $rk) {
                if (stripos((string)$key, $rk) !== false) {
                    $val = '***REDACTED***';
                    break;
                }
            }
        });
        return $data;
    }
}
