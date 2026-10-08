<?php
declare(strict_types=1);

namespace SOI\Certificates\Rendering;

use InvalidArgumentException;

final class FontManager
{
    private const APPROVED_FONTS = ['Arial', 'Helvetica', 'Times New Roman', 'Courier New', 'DejaVu Sans'];

    public function approvedFonts(): array
    {
        return self::APPROVED_FONTS;
    }

    public function resolve(string $fontFamily): string
    {
        if (!in_array($fontFamily, self::APPROVED_FONTS, true)) {
            throw new InvalidArgumentException('The requested font is not in the approved local font list.');
        }
        return $fontFamily;
    }

    public function fitDimensions(float $sourceWidth, float $sourceHeight, float $boxWidth, float $boxHeight, string $fit = 'contain'): array
    {
        if ($sourceWidth <= 0 || $sourceHeight <= 0 || $boxWidth <= 0 || $boxHeight <= 0
            || !in_array($fit, ['contain', 'cover'], true)) {
            throw new InvalidArgumentException('Image aspect-ratio dimensions are invalid.');
        }
        $scale = $fit === 'contain'
            ? min($boxWidth / $sourceWidth, $boxHeight / $sourceHeight)
            : max($boxWidth / $sourceWidth, $boxHeight / $sourceHeight);
        return ['width' => $sourceWidth * $scale, 'height' => $sourceHeight * $scale];
    }
}
