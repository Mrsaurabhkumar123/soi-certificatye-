<?php
declare(strict_types=1);

namespace SOI\Certificates\Http\Controllers;

use SOI\Certificates\Core\Plugin;

/**
 * Web-safe scheduler HTTP runner endpoint controller.
 * Triggers scheduled job queueing and execution within bounded resource limits.
 */
class SchedulerRunnerController
{
    public function __construct(protected readonly Plugin $plugin)
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
