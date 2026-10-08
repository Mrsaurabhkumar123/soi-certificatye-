<?php
declare(strict_types=1);

namespace SOI\Certificates\Rendering;

use RuntimeException;
use Throwable;

final class RenderExceptionHandler
{
    public function render(callable $render): RenderResult
    {
        try {
            $result = $render();
            if (!$result instanceof RenderResult) {
                throw new RuntimeException('Renderer returned an invalid result.');
            }
            return $result;
        } catch (Throwable $e) {
            error_log('SOI local certificate rendering failed: ' . get_class($e) . ': ' . $e->getMessage());
            throw new RuntimeException('Certificate rendering failed. Verify the template and local rendering configuration.');
        }
    }
}
