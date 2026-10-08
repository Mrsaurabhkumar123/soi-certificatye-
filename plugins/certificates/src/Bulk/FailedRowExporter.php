<?php
declare(strict_types=1);

namespace SOI\Certificates\Bulk;

use SOI\Certificates\Reporting\CsvExporter;

final class FailedRowExporter
{
    public function export(array $failedRows): string
    {
        $rows = [];
        foreach ($failedRows as $row) {
            $rows[] = [
                $row['row_number'] ?? '',
                $row['error'] ?? '',
                json_encode($row['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        return CsvExporter::toCsv(['Row', 'Error', 'Submitted Data'], $rows);
    }
}
