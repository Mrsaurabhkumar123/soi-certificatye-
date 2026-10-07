<?php
declare(strict_types=1);

namespace SOI\Certificates\Rendering;

class RenderResult
{
    public string $pdfBytes;
    public string $sha256;
    public int $sizeBytes;
    public int $pageCount;
    public float $renderDurationMs;
    public array $warnings = [];

    public function __construct(string $pdfBytes, float $durationMs = 0.0, array $warnings = [])
    {
        $this->pdfBytes = $pdfBytes;
        $this->sha256 = hash('sha256', $pdfBytes);
        $this->sizeBytes = strlen($pdfBytes);
        $this->pageCount = 1;
        $this->renderDurationMs = $durationMs;
        $this->warnings = $warnings;
    }
}
