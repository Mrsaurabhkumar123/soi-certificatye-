<?php
declare(strict_types=1);

/**
 * Cumulative Production Packaging Utility for SOI Certificate Management Platform.
 * Developer 4: Rendering & Storage Lead
 *
 * Produces the final release artifact: certificates-2.0.0-production.zip
 * Uses pure-PHP standard PKZIP deflate compression (independent of ext-zip).
 */

$version = '2.0.0';
$zipName = "certificates-{$version}-production.zip";

$updatesDir = __DIR__ . '/updates';
if (!is_dir($updatesDir)) {
    mkdir($updatesDir, 0755, true);
}

$zipPath = $updatesDir . '/' . $zipName;
$rootZipPath = __DIR__ . '/' . $zipName;

if (file_exists($zipPath)) {
    unlink($zipPath);
}
if (file_exists($rootZipPath)) {
    unlink($rootZipPath);
}

$pluginDir = __DIR__ . '/plugins/certificates';
if (!is_dir($pluginDir)) {
    die("Error: Plugin directory not found at {$pluginDir}\n");
}

class SimpleZipWriter
{
    private array $files = [];
    private string $centralDirectory = '';
    private int $offset = 0;
    private $stream;

    public function __construct(string $outputPath)
    {
        $this->stream = fopen($outputPath, 'wb');
        if ($this->stream === false) {
            throw new RuntimeException("Cannot open output zip file at {$outputPath}");
        }
    }

    public function addFile(string $filePath, string $entryName): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Cannot read file: {$filePath}");
        }

        $entryName = str_replace('\\', '/', $entryName);
        $crc32 = crc32($content);
        $uncompressedSize = strlen($content);

        $compressedContent = function_exists('gzdeflate') ? gzdeflate($content) : false;
        if ($compressedContent !== false && strlen($compressedContent) < $uncompressedSize) {
            $compressionMethod = 8; // DEFLATE
            $data = $compressedContent;
        } else {
            $compressionMethod = 0; // STORE
            $data = $content;
        }
        $compressedSize = strlen($data);

        $mtime = filemtime($filePath) ?: time();
        $dosTime = $this->unixToDosTime($mtime);

        // Local file header
        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50, // Signature
            20,         // Version needed to extract (2.0)
            0,          // General purpose bit flag
            $compressionMethod,
            $dosTime['time'],
            $dosTime['date'],
            $crc32,
            $compressedSize,
            $uncompressedSize,
            strlen($entryName),
            0           // Extra field length
        ) . $entryName;

        fwrite($this->stream, $localHeader);
        fwrite($this->stream, $data);

        // Central directory entry
        $cdEntry = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50, // Signature
            20,         // Version made by
            20,         // Version needed
            0,          // Flags
            $compressionMethod,
            $dosTime['time'],
            $dosTime['date'],
            $crc32,
            $compressedSize,
            $uncompressedSize,
            strlen($entryName),
            0,          // Extra field length
            0,          // File comment length
            0,          // Disk number start
            0,          // Internal file attributes
            0x81a40000, // External file attributes (regular file, rw-r--r--)
            $this->offset
        ) . $entryName;

        $this->centralDirectory .= $cdEntry;
        $this->offset += strlen($localHeader) + $compressedSize;
        $this->files[] = $entryName;
    }

    public function close(): void
    {
        $cdOffset = $this->offset;
        $cdSize = strlen($this->centralDirectory);
        $entryCount = count($this->files);

        fwrite($this->stream, $this->centralDirectory);

        // End of central directory record
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50, // Signature
            0,          // Disk number
            0,          // Disk where CD starts
            $entryCount,// Number of CD records on this disk
            $entryCount,// Total number of CD records
            $cdSize,    // Size of central directory
            $cdOffset,  // Offset of CD
            0           // Comment length
        );

        fwrite($this->stream, $eocd);
        fclose($this->stream);
    }

    private function unixToDosTime(int $time): array
    {
        $date = getdate($time);
        $dosDate = (($date['year'] - 1980) << 9) | ($date['mon'] << 5) | $date['mday'];
        $dosTime = ($date['hours'] << 11) | ($date['minutes'] << 5) | ($date['seconds'] >> 1);
        return ['date' => $dosDate, 'time' => $dosTime];
    }
}

$packager = new SimpleZipWriter($zipPath);

// Add root manifest.json for direct CMS update uploader compatibility
$rootManifestData = [
    'version' => $version,
    'name' => 'SOI Certificate Management Platform',
    'type' => 'plugin',
    'min_php' => '8.0',
    'description' => 'Enterprise-grade, modular, multi-tenant certificate issuance, verification, and management platform for SOI CMS.',
];
$rootManifestJson = json_encode($rootManifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$manifestTmp = sys_get_temp_dir() . '/soi_root_manifest_' . time() . '.json';
file_put_contents($manifestTmp, $rootManifestJson);
$packager->addFile($manifestTmp, 'manifest.json');

// Add root update.sql for automatic database schema provisioning on CMS update
$sqlMigrations = glob($pluginDir . '/migrations/*.sql');
sort($sqlMigrations);
$combinedSql = "-- SOI Certificate Management Platform Database Schema\n";
foreach ($sqlMigrations as $sqlFile) {
    $combinedSql .= "\n-- Migration: " . basename($sqlFile) . "\n";
    $combinedSql .= file_get_contents($sqlFile) . "\n";
}
$sqlTmp = sys_get_temp_dir() . '/soi_root_update_' . time() . '.sql';
file_put_contents($sqlTmp, $combinedSql);
$packager->addFile($sqlTmp, 'update.sql');

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$count = 2;
foreach ($files as $name => $file) {
    if (!$file->isDir()) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen(realpath($pluginDir)) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        // Exclude runtime database files, local test databases, and temp files
        if (str_starts_with($relativePath, 'storage/data/')
            || str_ends_with($relativePath, '.sqlite')
            || str_ends_with($relativePath, '.tmp')
            || str_contains($relativePath, '/.git')) {
            continue;
        }

        // Package under plugins/certificates/ for seamless CMS extraction
        $packager->addFile($filePath, 'plugins/certificates/' . $relativePath);
        // Also package under certificates/ for backward compatibility
        $packager->addFile($filePath, 'certificates/' . $relativePath);
        $count++;
    }
}

// Add LiteSpeed direct entrypoints, admin bridge, and root .htaccess
$bridges = [
    'admin/certificates.php' => __DIR__ . '/admin/certificates.php',
    'manage/index.php' => __DIR__ . '/manage/index.php',
    'console/index.php' => __DIR__ . '/console/index.php',
    'verify/index.php' => __DIR__ . '/verify/index.php',
    'forms/index.php' => __DIR__ . '/forms/index.php',
    'docs/index.php' => __DIR__ . '/docs/index.php',
    'super-admin/index.php' => __DIR__ . '/super-admin/index.php',
    '.htaccess' => __DIR__ . '/.htaccess',
];
foreach ($bridges as $entryName => $filePath) {
    if (file_exists($filePath)) {
        $packager->addFile($filePath, $entryName);
        $count++;
    }
}

$packager->close();
@unlink($manifestTmp);
@unlink($sqlTmp);

// Also copy to root for immediate accessibility
copy($zipPath, $rootZipPath);

$hash = hash_file('sha256', $zipPath);
$size = filesize($zipPath);

echo "========================================================\n";
echo "   SOI Certificate Platform - Production Release Package\n";
echo "========================================================\n";
echo " Package Name   : {$zipName}\n";
echo " Output Path    : updates/{$zipName}\n";
echo " Root Copy      : {$zipName}\n";
echo " Files Packaged : {$count}\n";
echo " Package Size   : " . number_format($size) . " bytes\n";
echo " SHA-256 Hash   : {$hash}\n";
echo " Status         : SUCCESS (Ready for Deployment)\n";
echo "========================================================\n";
