<?php
declare(strict_types=1);

namespace SOI\Certificates\Rendering;

/**
 * Native, self-hosted pure PHP PDF certificate rendering engine.
 * Generates standards-compliant PDF-1.4 documents with vector QR codes,
 * typography styling, and deterministic output.
 */
class LocalCertificateRenderer implements CertificateRendererInterface
{
    public function render(RenderRequest $request): RenderResult
    {
        $startTime = microtime(true);

        $layout = $request->templateVersion->layout;
        $pageConfig = $layout['page'] ?? ['size' => 'A4', 'orientation' => 'landscape'];
        
        $isLandscape = strtolower($pageConfig['orientation'] ?? 'landscape') === 'landscape';
        $pageWidth = $isLandscape ? 842.0 : 595.0;  // Standard A4 points
        $pageHeight = $isLandscape ? 595.0 : 842.0;

        $stream = "";

        // 1. Draw elegant outer and inner border frame (Gold / Deep Navy)
        $stream .= "0.12 0.23 0.54 rg\n"; // Deep Navy
        $stream .= "20 20 " . ($pageWidth - 40) . " " . ($pageHeight - 40) . " re s\n";
        $stream .= "24 24 " . ($pageWidth - 48) . " " . ($pageHeight - 48) . " re s\n";
        $stream .= "0.85 0.65 0.13 rg\n"; // Gold accent
        $stream .= "22 22 " . ($pageWidth - 44) . " " . ($pageHeight - 44) . " re s\n";

        // 2. Elements rendering
        $elements = $layout['elements'] ?? [];
        $variables = $request->variables;

        foreach ($elements as $el) {
            $type = $el['type'] ?? 'text';
            $x = (float)($el['x'] ?? 50);
            $y = (float)($el['y'] ?? 50);
            // Transform Y from top-down to PDF bottom-up coordinate space
            $pdfY = $pageHeight - $y;

            if ($type === 'text') {
                $val = (string)($el['value'] ?? '');
                $size = (int)($el['font_size'] ?? 14);
                $isBold = !empty($el['bold']);
                $font = $isBold ? '/F2' : '/F1';
                $stream .= "BT {$font} {$size} Tf 0.08 0.12 0.25 rg " . $this->escapePdfString($val, $x, $pdfY) . " ET\n";
            } elseif ($type === 'variable') {
                $key = $el['key'] ?? '';
                $val = (string)($variables[$key] ?? $el['sample'] ?? "[{$key}]");
                $size = (int)($el['font_size'] ?? 18);
                $isBold = !empty($el['bold']) || $key === 'recipient_name';
                $font = $isBold ? '/F2' : '/F1';
                // Highlight recipient name in deep royal blue
                $color = ($key === 'recipient_name') ? "0.10 0.25 0.65 rg" : "0.15 0.15 0.15 rg";
                $stream .= "BT {$font} {$size} Tf {$color} " . $this->escapePdfString($val, $x, $pdfY) . " ET\n";
            } elseif ($type === 'qr') {
                // Render vector QR matrix into PDF stream
                $qrSize = (float)($el['size'] ?? 90);
                $matrix = QrCodeGenerator::generateMatrix($request->verificationUrl, 29);
                $count = count($matrix);
                $modSize = $qrSize / $count;

                // White quiet-zone backing
                $stream .= "1 1 1 rg " . ($x - 4) . " " . ($pdfY - $qrSize - 4) . " " . ($qrSize + 8) . " " . ($qrSize + 8) . " re f\n";
                // Dark QR modules
                $stream .= "0 0 0 rg\n";
                for ($r = 0; $r < $count; $r++) {
                    for ($c = 0; $c < $count; $c++) {
                        if ($matrix[$r][$c]) {
                            $mx = $x + ($c * $modSize);
                            $my = $pdfY - (($r + 1) * $modSize);
                            $stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", $mx, $my, $modSize, $modSize);
                        }
                    }
                }
            }
        }

        // 3. Render authoritative footer metadata: Certificate Number & Verification note
        $certNoStr = "Certificate ID: " . $request->certificateNumber;
        $stream .= "BT /F1 9 Tf 0.4 0.4 0.4 rg " . $this->escapePdfString($certNoStr, 35, 35) . " ET\n";
        $verifyNote = "Scan QR or visit " . $request->verificationUrl . " to verify authenticity";
        $stream .= "BT /F1 9 Tf 0.4 0.4 0.4 rg " . $this->escapePdfString($verifyNote, 35, 23) . " ET\n";

        // Assemble full PDF-1.4 file binary structure
        $pdfBytes = $this->buildPdfDocument($pageWidth, $pageHeight, $stream);
        $durationMs = (microtime(true) - $startTime) * 1000;

        return new RenderResult($pdfBytes, $durationMs);
    }

    protected function escapePdfString(string $text, float $x, float $y): string
    {
        $sanitized = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return sprintf("%.2f %.2f Td (%s) Tj", $x, $y, $sanitized);
    }

    protected function buildPdfDocument(float $width, float $height, string $contentStream): string
    {
        $objects = [];
        $streamLen = strlen($contentStream);

        // Object 1: Catalog
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        // Object 2: Pages
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        // Object 3: Page
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>";
        // Object 4: Content Stream
        $objects[4] = "<< /Length {$streamLen} >>\nstream\n{$contentStream}\nendstream";
        // Object 5: Standard Font 1 (Helvetica)
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        // Object 6: Standard Font 2 (Helvetica-Bold)
        $objects[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $output = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $obj) {
            $offsets[$id] = strlen($output);
            $output .= "{$id} 0 obj\n{$obj}\nendobj\n";
        }

        $xrefOffset = strlen($output);
        $output .= "xref\n0 7\n";
        $output .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $output .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $output .= "trailer\n<< /Size 7 /Root 1 0 R >>\n";
        $output .= "startxref\n{$xrefOffset}\n%%EOF";

        return $output;
    }
}
