<?php
declare(strict_types=1);

namespace SOI\Certificates\Bulk;

use InvalidArgumentException;
use RuntimeException;

final class SpreadsheetReader
{
    private const MAX_ARCHIVE_BYTES = 2_097_152;
    private const MAX_EXPANDED_ENTRY_BYTES = 10_485_760;

    public static function xlsxToCsv(string $path): string
    {
        $archive = self::readArchive($path);
        $workbook = self::xml($archive['xl/workbook.xml'] ?? null, 'Workbook metadata is missing.');
        $relationships = self::xml(
            $archive['xl/_rels/workbook.xml.rels'] ?? null,
            'Workbook relationships are missing.'
        );
        $sheet = $workbook->getElementsByTagName('sheet')->item(0);
        $relationshipId = $sheet?->getAttributeNS(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
            'id'
        );
        if ($relationshipId === null || $relationshipId === '') {
            throw new InvalidArgumentException('The XLSX workbook has no readable worksheet.');
        }

        $sheetPath = null;
        foreach ($relationships->getElementsByTagName('Relationship') as $relationship) {
            if ($relationship->getAttribute('Id') === $relationshipId) {
                $target = ltrim($relationship->getAttribute('Target'), '/');
                $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                break;
            }
        }
        if ($sheetPath === null || str_contains($sheetPath, '..')) {
            throw new InvalidArgumentException('The XLSX worksheet reference is invalid.');
        }
        $worksheet = self::xml($archive[$sheetPath] ?? null, 'The first XLSX worksheet is missing.');

        $sharedStrings = [];
        if (isset($archive['xl/sharedStrings.xml'])) {
            $sharedXml = self::xml($archive['xl/sharedStrings.xml'], 'XLSX shared strings are invalid.');
            foreach ($sharedXml->getElementsByTagName('si') as $item) {
                $value = '';
                foreach ($item->getElementsByTagName('t') as $text) {
                    $value .= $text->textContent;
                }
                $sharedStrings[] = $value;
            }
        }

        $rows = [];
        $maxColumn = 0;
        foreach ($worksheet->getElementsByTagName('row') as $rowNode) {
            if (count($rows) >= 5001) {
                throw new InvalidArgumentException('The XLSX sheet exceeds the 5,000 row limit.');
            }
            $cells = [];
            foreach ($rowNode->getElementsByTagName('c') as $cell) {
                $reference = $cell->getAttribute('r');
                if (!preg_match('/^([A-Z]{1,3})[1-9][0-9]*$/i', $reference, $match)) {
                    throw new InvalidArgumentException('The XLSX sheet contains an invalid cell reference.');
                }
                $column = self::columnIndex(strtoupper($match[1]));
                if ($column > 64) {
                    throw new InvalidArgumentException('The XLSX worksheet exceeds the 64-column import limit.');
                }
                $maxColumn = max($maxColumn, $column);
                $type = $cell->getAttribute('t');
                $valueNode = $cell->getElementsByTagName('v')->item(0);
                $value = $valueNode?->textContent ?? '';
                if ($type === 's') {
                    $stringIndex = filter_var($value, FILTER_VALIDATE_INT);
                    if ($stringIndex === false || !array_key_exists($stringIndex, $sharedStrings)) {
                        throw new InvalidArgumentException('The XLSX sheet references an invalid shared string.');
                    }
                    $value = $sharedStrings[$stringIndex];
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($cell->getElementsByTagName('t') as $text) {
                        $value .= $text->textContent;
                    }
                } elseif ($type === 'b') {
                    $value = $value === '1' ? 'TRUE' : 'FALSE';
                }
                $cells[$column] = $value;
            }
            $rows[] = $cells;
        }
        if ($rows === [] || $maxColumn === 0) {
            throw new InvalidArgumentException('The XLSX worksheet contains no tabular data.');
        }

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare spreadsheet rows.');
        }
        foreach ($rows as $cells) {
            $values = [];
            for ($column = 1; $column <= $maxColumn; $column++) {
                $values[] = (string)($cells[$column] ?? '');
            }
            fputcsv($stream, $values);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new RuntimeException('Unable to convert spreadsheet rows.');
        }
        return $csv;
    }

    public static function pastedTextToCsv(string $text): string
    {
        if (strlen($text) > self::MAX_ARCHIVE_BYTES) {
            throw new InvalidArgumentException('Pasted import data must be no larger than 2 MB.');
        }
        if (!str_contains(strtok($text, "\r\n") ?: '', "\t")) {
            return $text;
        }
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        if ($input === false || $output === false) {
            throw new RuntimeException('Unable to prepare pasted spreadsheet data.');
        }
        fwrite($input, $text);
        rewind($input);
        $rows = 0;
        while (($values = fgetcsv($input, 0, "\t")) !== false) {
            if (++$rows > 5001) {
                fclose($input);
                fclose($output);
                throw new InvalidArgumentException('Pasted data exceeds the 5,000 row limit.');
            }
            fputcsv($output, $values);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($input);
        fclose($output);
        if ($csv === false) {
            throw new RuntimeException('Unable to convert pasted spreadsheet data.');
        }
        return $csv;
    }

    private static function readArchive(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size < 22 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new InvalidArgumentException('XLSX upload must be a valid file no larger than 2 MB.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read XLSX upload.');
        }
        $tailLength = min($size, 65_557);
        fseek($stream, $size - $tailLength);
        $tail = fread($stream, $tailLength);
        $eocdPosition = strrpos($tail, "PK\x05\x06");
        if ($eocdPosition === false || strlen($tail) < $eocdPosition + 22) {
            fclose($stream);
            throw new InvalidArgumentException('The XLSX upload is not a valid ZIP archive.');
        }
        $eocd = unpack('vdisk/vstart/ventriesDisk/ventries/VdirectorySize/VdirectoryOffset/vcommentLength',
            substr($tail, $eocdPosition + 4, 18));
        $absoluteEocdPosition = $size - $tailLength + $eocdPosition;
        if ($eocd['disk'] !== 0 || $eocd['start'] !== 0 || $eocd['entriesDisk'] !== $eocd['entries']
            || $eocd['entries'] > 2048
            || $eocd['directoryOffset'] + $eocd['directorySize'] > $absoluteEocdPosition) {
            fclose($stream);
            throw new InvalidArgumentException('The XLSX archive structure is unsupported.');
        }

        $entries = [];
        $expandedTotal = 0;
        fseek($stream, $eocd['directoryOffset']);
        for ($index = 0; $index < $eocd['entries']; $index++) {
            $header = fread($stream, 46);
            if (strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") {
                fclose($stream);
                throw new InvalidArgumentException('The XLSX ZIP directory is malformed.');
            }
            $entry = unpack(
                'vversionMade/vversionNeeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/VlocalOffset',
                substr($header, 4)
            );
            $name = fread($stream, $entry['nameLength']);
            fseek($stream, $entry['extraLength'] + $entry['commentLength'], SEEK_CUR);
            if ($name === false || str_contains($name, '..') || str_starts_with($name, '/')
                || $entry['disk'] !== 0 || ($entry['flags'] & 1) !== 0
                || $entry['uncompressed'] > self::MAX_EXPANDED_ENTRY_BYTES
                || ($expandedTotal += $entry['uncompressed']) > 20_971_520) {
                fclose($stream);
                throw new InvalidArgumentException('The XLSX archive contains an unsupported entry.');
            }
            if (!in_array($entry['method'], [0, 8], true)) {
                continue;
            }
            $directoryPosition = ftell($stream);
            fseek($stream, $entry['localOffset']);
            $localHeader = fread($stream, 30);
            if (strlen($localHeader) !== 30 || substr($localHeader, 0, 4) !== "PK\x03\x04") {
                fclose($stream);
                throw new InvalidArgumentException('The XLSX ZIP entry is malformed.');
            }
            $local = unpack('vnameLength/vextraLength', substr($localHeader, 26, 4));
            fseek($stream, $entry['localOffset'] + 30 + $local['nameLength'] + $local['extraLength']);
            $compressed = fread($stream, $entry['compressed']);
            if ($compressed === false || strlen($compressed) !== $entry['compressed']) {
                fclose($stream);
                throw new InvalidArgumentException('An XLSX ZIP entry is truncated.');
            }
            $content = $entry['method'] === 8
                ? gzinflate($compressed, (int)$entry['uncompressed'] + 1)
                : $compressed;
            if ($content === false || strlen($content) !== $entry['uncompressed']
                || sprintf('%u', crc32($content)) !== sprintf('%u', $entry['crc'])) {
                fclose($stream);
                throw new InvalidArgumentException('An XLSX ZIP entry failed integrity validation.');
            }
            $entries[$name] = $content;
            fseek($stream, $directoryPosition);
        }
        fclose($stream);
        return $entries;
    }

    private static function xml(?string $content, string $error): \DOMDocument
    {
        if ($content === null) {
            throw new InvalidArgumentException($error);
        }
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->doctype !== null) {
            throw new InvalidArgumentException($error);
        }
        return $document;
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }
        return $index;
    }
}
