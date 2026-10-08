<?php
declare(strict_types=1);

namespace SOI\Certificates\Issuance;

use InvalidArgumentException;
use SOI\Certificates\Core\Database;

final class NumberGenerator
{
    public function __construct(private readonly Database $db)
    {
    }

    public function next(int $tenantId, int $year): string
    {
        if ($tenantId < 1 || $year < 2000 || $year > 9999) {
            throw new InvalidArgumentException('Certificate sequence parameters are invalid.');
        }

        $sequences = $this->db->tableName('cert_sequences');
        $certificates = $this->db->tableName('cert_certificates');
        $current = $this->db->fetchValue(
            "SELECT last_value FROM {$sequences} WHERE tenant_id = :tenant_id AND sequence_year = :year",
            ['tenant_id' => $tenantId, 'year' => $year]
        );

        if ($current === false || $current === null) {
            $rows = $this->db->fetchAll(
                "SELECT certificate_number FROM {$certificates} WHERE tenant_id = :tenant_id",
                ['tenant_id' => $tenantId]
            );
            $lastValue = 0;
            foreach ($rows as $row) {
                if (preg_match('/^SOI-' . $year . '-([0-9]+)$/', (string)$row['certificate_number'], $matches)) {
                    $lastValue = max($lastValue, (int)$matches[1]);
                }
            }
            if ($this->db->getDriver() === 'sqlite') {
                $this->db->execute(
                    "INSERT OR IGNORE INTO {$sequences} (tenant_id, sequence_year, last_value, updated_at)
                     VALUES (:tenant_id, :year, :last_value, datetime('now'))",
                    ['tenant_id' => $tenantId, 'year' => $year, 'last_value' => $lastValue]
                );
            } else {
                $this->db->execute(
                    "INSERT IGNORE INTO {$sequences} (tenant_id, sequence_year, last_value, updated_at)
                     VALUES (:tenant_id, :year, :last_value, CURRENT_TIMESTAMP)",
                    ['tenant_id' => $tenantId, 'year' => $year, 'last_value' => $lastValue]
                );
            }
        }

        $this->db->execute(
            "UPDATE {$sequences} SET last_value = last_value + 1, updated_at = CURRENT_TIMESTAMP
             WHERE tenant_id = :tenant_id AND sequence_year = :year",
            ['tenant_id' => $tenantId, 'year' => $year]
        );
        $next = (int)$this->db->fetchValue(
            "SELECT last_value FROM {$sequences} WHERE tenant_id = :tenant_id AND sequence_year = :year",
            ['tenant_id' => $tenantId, 'year' => $year]
        );
        return sprintf('SOI-%04d-%05d', $year, $next);
    }
}
