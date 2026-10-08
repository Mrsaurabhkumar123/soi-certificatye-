<?php
declare(strict_types=1);

namespace SOI\Certificates\Templates;

final class PreviewRenderer
{
    public function render(array $layout, array $sampleValues): array
    {
        $preview = $layout;
        foreach ($preview['elements'] ?? [] as $index => $element) {
            if (($element['type'] ?? null) === 'variable') {
                $key = $element['key'] ?? '';
                $preview['elements'][$index]['preview_value'] = $sampleValues[$key] ?? '{{' . $key . '}}';
            } elseif (($element['type'] ?? null) === 'qr') {
                $preview['elements'][$index]['preview_value'] = '[verification QR]';
            }
        }
        return $preview;
    }
}
