<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;

final class SchedulerRunnerController
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function run(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $queued = $this->plugin->scheduleProcessor->enqueueDueOccurrences();
            $result = $this->plugin->scheduleProcessor->runDueJobs(
                20,
                60,
                $this->plugin->tenantContext->getCurrentUserId()
            );
            echo json_encode(['data' => ['queued' => $queued] + $result], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            error_log('SOI scheduler HTTP runner failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => [
                    'code' => 'SCHEDULER_RUN_FAILED',
                    'message' => 'Due scheduled work could not be completed.',
                ],
            ]);
        }
    }
}
