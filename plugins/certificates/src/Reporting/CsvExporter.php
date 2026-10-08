<?php
declare(strict_types=1);

namespace SOI\Certificates\Reporting;

use SOI\Certificates\Core\Database;
use SOI\Certificates\Tenancy\TenantContext;

final class CsvExporter
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenantContext
    ) {
    }

    public function exportCertificates(array $filters = []): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_certificates');
        $conditions = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];
        $status = (string)($filters['status'] ?? '');
        if ($status !== '') {
            if (!in_array($status, ['issued', 'revoked', 'replaced', 'expired', 'cancelled'], true)) {
                throw new \InvalidArgumentException('Certificate status filter is invalid.');
            }
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }
        $number = trim((string)($filters['certificate_number'] ?? ''));
        if ($number !== '') {
            $conditions[] = 'certificate_number LIKE :certificate_number';
            $params['certificate_number'] = '%' . str_replace(['%', '_'], '', substr($number, 0, 64)) . '%';
        }
        if (!empty($filters['recipient'])) {
            $conditions[] = "recipient_name LIKE :recipient";
            $params['recipient'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], substr((string)$filters['recipient'], 0, 128)) . '%';
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (!empty($filters[$key])) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$filters[$key]);
                if ($date === false || $date->format('Y-m-d') !== $filters[$key]) {
                    throw new \InvalidArgumentException('Date filters must be valid YYYY-MM-DD calendar dates.');
                }
                $conditions[] = 'DATE(issued_at) ' . $operator . ' :' . $key;
                $params[$key] = $filters[$key];
            }
        }
        if (isset($params['from'], $params['to']) && $params['from'] > $params['to']) {
            throw new \InvalidArgumentException('The start date must not be after the end date.');
        }

        $rows = $this->db->fetchAll(
            "SELECT certificate_number, recipient_name, issued_at, expires_at, status, source_type
             FROM {$table} WHERE " . implode(' AND ', $conditions) . ' ORDER BY issued_at DESC LIMIT 10000',
            $params
        );
        return self::toCsv(
            ['Certificate Number', 'Recipient', 'Issued At', 'Expires At', 'Status', 'Source'],
            array_map(static fn(array $row): array => array_values($row), $rows)
        );
    }

    public static function toCsv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create CSV output stream.');
        }
        fputcsv($stream, array_map([self::class, 'safeCell'], $headers));
        foreach ($rows as $row) {
            fputcsv($stream, array_map([self::class, 'safeCell'], $row));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new \RuntimeException('Unable to read CSV output stream.');
        }
        return $csv;
    }

    public static function safeCell(mixed $value): string
    {
        $cell = (string)$value;
        return preg_match('/^[\s]*[=+\-@]/', $cell) ? "'" . $cell : $cell;
    }
}
