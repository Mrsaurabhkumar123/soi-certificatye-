<?php
declare(strict_types=1);

namespace SOI\Certificates\Storage;

use DOMDocument;
use finfo;
use InvalidArgumentException;
use RuntimeException;
use SOI\Certificates\Core\Database;

final class AssetUploader
{
    private const MAX_BYTES = 5242880;
    private const RASTER_MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    private const SVG_ELEMENTS = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan'];
    private const SVG_ATTRIBUTES = [
        'xmlns', 'width', 'height', 'viewBox', 'd', 'fill', 'stroke', 'stroke-width',
        'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'points', 'transform', 'fill-rule', 'stroke-linecap', 'stroke-linejoin',
        'opacity', 'font-size', 'text-anchor',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly StorageAdapterInterface $storage
    ) {
    }

    public function upload(int $tenantId, string $tenantSlug, string $name, string $assetType, array $file): array
    {
        if ($tenantId < 1 || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $tenantSlug)
            || !in_array($assetType, ['logo', 'seal', 'signature', 'background'], true)) {
            throw new InvalidArgumentException('Asset tenant or type is invalid.');
        }
        $name = trim($name);
        if ($name === '' || strlen($name) > 128) {
            throw new InvalidArgumentException('Asset name must be 1 to 128 bytes.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('A valid uploaded asset is required.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('Asset size must not exceed 5 MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $bytes = file_get_contents($file['tmp_name']);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read the uploaded asset.');
        }
        if (isset(self::RASTER_MIME[$mime])) {
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo === false || $imageInfo[0] > 10000 || $imageInfo[1] > 10000) {
                throw new InvalidArgumentException('Uploaded raster image dimensions are invalid.');
            }
            $extension = self::RASTER_MIME[$mime];
        } elseif ($mime === 'image/svg+xml') {
            $bytes = $this->sanitizeSvg($bytes);
            $extension = 'svg';
        } else {
            throw new InvalidArgumentException('Only PNG, JPEG, GIF, WebP and sanitized SVG assets are supported.');
        }

        $relativePath = 'certificate-assets/' . $tenantSlug . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (!$this->storage->put($relativePath, $bytes)) {
            throw new RuntimeException('Unable to store the uploaded asset.');
        }
        $table = $this->db->tableName('cert_template_assets');
        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, name, asset_type, file_path, file_size, mime_type, created_at)
             VALUES (:tenant_id, :name, :asset_type, :file_path, :file_size, :mime_type, CURRENT_TIMESTAMP)",
            [
                'tenant_id' => $tenantId,
                'name' => $name,
                'asset_type' => $assetType,
                'file_path' => $relativePath,
                'file_size' => strlen($bytes),
                'mime_type' => $mime === 'image/svg+xml' ? 'image/svg+xml' : $mime,
            ]
        );

        return ['id' => $this->db->lastInsertId(), 'name' => $name, 'asset_type' => $assetType, 'mime_type' => $mime, 'sha256' => hash('sha256', $bytes)];
    }

    public function delete(int $tenantId, int $assetId): bool
    {
        $assets = $this->db->tableName('cert_template_assets');
        $row = $this->db->fetchOne(
            "SELECT * FROM {$assets} WHERE id = :id AND tenant_id = :tenant_id",
            ['id' => $assetId, 'tenant_id' => $tenantId]
        );
        if ($row === null) {
            return false;
        }

        $versions = $this->db->tableName('cert_template_versions');
        $published = $this->db->fetchAll(
            "SELECT layout_json FROM {$versions} WHERE tenant_id = :tenant_id AND published_at IS NOT NULL",
            ['tenant_id' => $tenantId]
        );
        foreach ($published as $version) {
            $layout = json_decode((string)$version['layout_json'], true);
            foreach ($layout['elements'] ?? [] as $element) {
                if ((int)($element['asset_id'] ?? 0) === $assetId) {
                    throw new RuntimeException('Asset is referenced by a published template version and cannot be deleted.');
                }
            }
        }

        if (!$this->storage->delete((string)$row['file_path'])) {
            throw new RuntimeException('Unable to remove the asset from local storage.');
        }
        $this->db->execute("DELETE FROM {$assets} WHERE id = :id AND tenant_id = :tenant_id", ['id' => $assetId, 'tenant_id' => $tenantId]);
        return true;
    }

    public function listForTenant(int $tenantId): array
    {
        $table = $this->db->tableName('cert_template_assets');
        return $this->db->fetchAll(
            "SELECT id, name, asset_type, file_path, file_size, mime_type, created_at
             FROM {$table} WHERE tenant_id = :tenant_id ORDER BY id DESC",
            ['tenant_id' => $tenantId]
        );
    }

    public function findForTenant(int $tenantId, int $assetId): ?array
    {
        $table = $this->db->tableName('cert_template_assets');
        return $this->db->fetchOne(
            "SELECT id, name, asset_type, file_path, file_size, mime_type
             FROM {$table} WHERE tenant_id = :tenant_id AND id = :id",
            ['tenant_id' => $tenantId, 'id' => $assetId]
        );
    }

    private function sanitizeSvg(string $svg): string
    {
        if (strlen($svg) > self::MAX_BYTES || stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('SVG asset contains unsupported declarations or exceeds the size limit.');
        }
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->documentElement === null || strtolower($document->documentElement->localName) !== 'svg') {
            throw new InvalidArgumentException('Uploaded SVG is malformed.');
        }

        $nodes = iterator_to_array($document->getElementsByTagName('*'));
        foreach (array_reverse($nodes) as $node) {
            if (!in_array(strtolower($node->localName), self::SVG_ELEMENTS, true)) {
                $node->parentNode?->removeChild($node);
                continue;
            }
            if ($node->hasAttributes()) {
                for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                    $attribute = $node->attributes->item($i);
                    if ($attribute === null || !in_array($attribute->name, self::SVG_ATTRIBUTES, true)
                        || preg_match('/url\s*\(|javascript:|data:|expression/i', $attribute->value)) {
                        $node->removeAttributeNode($attribute);
                    }
                }
            }
        }
        $output = $document->saveXML($document->documentElement);
        if (!is_string($output) || $output === '') {
            throw new RuntimeException('Unable to sanitize the uploaded SVG.');
        }
        return $output;
    }
}
