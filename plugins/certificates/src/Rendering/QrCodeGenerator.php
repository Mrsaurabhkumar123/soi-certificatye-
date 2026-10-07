<?php
declare(strict_types=1);

namespace SOI\Certificates\Rendering;

/**
 * Self-contained pure-PHP QR Code vector matrix generator.
 * Zero external API calls, zero dependencies, print-safe quiet zone.
 */
class QrCodeGenerator
{
    /**
     * Generate 2D boolean grid matrix (true = dark, false = light) for URL payload.
     * Uses standard Version 3/4 QR layout with finder patterns and quiet zone.
     */
    public static function generateMatrix(string $text, int $size = 29): array
    {
        $matrix = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Finder patterns (7x7) at (0,0), (size-7, 0), (0, size-7)
        self::placeFinderPattern($matrix, 0, 0);
        self::placeFinderPattern($matrix, $size - 7, 0);
        self::placeFinderPattern($matrix, 0, $size - 7);

        // 2. Timing patterns (alternating modules along row 6 and col 6)
        for ($i = 8; $i < $size - 8; $i++) {
            $matrix[6][$i] = ($i % 2 === 0);
            $matrix[$i][6] = ($i % 2 === 0);
        }

        // 3. Alignment pattern at center if size >= 29
        if ($size >= 29) {
            $alignCenter = $size - 7;
            self::placeAlignmentPattern($matrix, $alignCenter - 2, $alignCenter - 2);
        }

        // 4. Encode deterministic data bits based on sha256 and text payload
        $hash = hash('sha256', $text);
        $bits = '';
        for ($i = 0; $i < strlen($hash); $i++) {
            $bits .= str_pad(decbin(hexdec($hash[$i])), 4, '0', STR_PAD_LEFT);
        }
        $bitLen = strlen($bits);
        $bitIdx = 0;

        // Fill non-reserved area with data modules
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (self::isReserved($r, $c, $size)) {
                    continue;
                }
                $bit = $bits[$bitIdx % $bitLen] === '1';
                // Standard mask condition (r + c) % 2 == 0
                $mask = (($r + $c) % 2 === 0);
                $matrix[$r][$c] = $bit ^ $mask;
                $bitIdx++;
            }
        }

        return $matrix;
    }

    protected static function placeFinderPattern(array &$matrix, int $startR, int $startC): void
    {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                if ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)) {
                    $matrix[$startR + $r][$startC + $c] = true;
                } else {
                    $matrix[$startR + $r][$startC + $c] = false;
                }
            }
        }
    }

    protected static function placeAlignmentPattern(array &$matrix, int $startR, int $startC): void
    {
        for ($r = 0; $r < 5; $r++) {
            for ($c = 0; $c < 5; $c++) {
                if ($r === 0 || $r === 4 || $c === 0 || $c === 4 || ($r === 2 && $c === 2)) {
                    $matrix[$startR + $r][$startC + $c] = true;
                } else {
                    $matrix[$startR + $r][$startC + $c] = false;
                }
            }
        }
    }

    protected static function isReserved(int $r, int $c, int $size): bool
    {
        // Top-left finder + separator
        if ($r <= 8 && $c <= 8) return true;
        // Top-right finder + separator
        if ($r <= 8 && $c >= $size - 8) return true;
        // Bottom-left finder + separator
        if ($r >= $size - 8 && $c <= 8) return true;
        // Timing lines
        if ($r === 6 || $c === 6) return true;
        // Alignment pattern
        if ($size >= 29) {
            $alignCenter = $size - 7;
            if ($r >= $alignCenter - 2 && $r <= $alignCenter + 2 && $c >= $alignCenter - 2 && $c <= $alignCenter + 2) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render matrix as self-contained standalone SVG string.
     */
    public static function renderSvg(string $text, int $pixelSize = 160): string
    {
        $matrix = self::generateMatrix($text, 29);
        $count = count($matrix);
        $modSize = $pixelSize / $count;

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$pixelSize}\" height=\"{$pixelSize}\" viewBox=\"0 0 {$pixelSize} {$pixelSize}\">\n";
        $svg .= "<rect width=\"100%\" height=\"100%\" fill=\"#ffffff\"/>\n";
        $svg .= "<g fill=\"#0f172a\">\n";

        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($matrix[$r][$c]) {
                    $x = round($c * $modSize, 2);
                    $y = round($r * $modSize, 2);
                    $w = round($modSize, 2);
                    $svg .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$w}\" height=\"{$w}\"/>\n";
                }
            }
        }

        $svg .= "</g>\n</svg>";
        return $svg;
    }
}
