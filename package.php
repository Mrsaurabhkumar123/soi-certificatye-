<?php
declare(strict_types=1);

/**
 * Cumulative Update Center Packaging Utility.
 * Packages the plugins/certificates directory into a clean, cumulative Update Center ZIP.
 */

$version = '1.0.0';
$prompt = 'prompt1';
$zipName = "certificates-{$version}-{$prompt}.zip";

$updatesDir = __DIR__ . '/updates';
if (!is_dir($updatesDir)) {
    mkdir($updatesDir, 0755, true);
}

$zipPath = $updatesDir . '/' . $zipName;
if (file_exists($zipPath)) {
    unlink($zipPath);
}

$pluginDir = __DIR__ . '/plugins/certificates';
$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Error: Cannot create ZIP file at {$zipPath}\n");
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$count = 0;
foreach ($files as $name => $file) {
    if (!$file->isDir()) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen(realpath($pluginDir)) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        // Exclude local database and generated temp artifacts from release package
        if (str_starts_with($relativePath, 'storage/data/') || str_ends_with($relativePath, '.sqlite')) {
            continue;
        }

        $zip->addFile($filePath, 'certificates/' . $relativePath);
        $count++;
    }
}

$zip->close();

$hash = hash_file('sha256', $zipPath);
$size = filesize($zipPath);

echo "========================================================\n";
echo "   SOI Certificate Platform - Update Center Package     \n";
echo "========================================================\n";
echo " Package Name   : {$zipName}\n";
echo " Output Path    : updates/{$zipName}\n";
echo " Files Packaged : {$count}\n";
echo " Package Size   : " . number_format($size) . " bytes\n";
echo " SHA-256 Hash   : {$hash}\n";
echo " Status         : SUCCESS (Ready for Update Center)\n";
echo "========================================================\n";
