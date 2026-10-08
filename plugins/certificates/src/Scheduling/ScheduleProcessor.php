<?php
declare(strict_types=1);

namespace SOI\Certificates\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SOI\Certificates\Audit\AuditService;
use SOI\Certificates\Authorization\Authorizer;
use SOI\Certificates\Authorization\Permissions;
use SOI\Certificates\Core\Database;
use SOI\Certificates\Issuance\CertificateIssuanceService;
use SOI\Certificates\Issuance\IssuanceCommand;
use SOI\Certificates\Tenancy\TenantContext;
use Throwable;

final class ScheduleProcessor
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenantContext,
        private readonly Authorizer $authorizer,
        private readonly JobScheduler $jobs,
        private readonly CertificateIssuanceService $issuanceService,
        private readonly AuditService $audit
    ) {
    }

    public function createSchedule(
        string $name,
        int $templateId,
        array $payload,
        string $runAt,
        string $recurrence = 'once',
        string $timezone = 'UTC'
    ): int {
        $this->authorizer->require(Permissions::SCHEDULES_MANAGE);
        $tenantId = $this->tenantContext->getTenantId();
        $name = trim($name);
        if ($name === '' || strlen($name) > 128 || $templateId < 1
            || !in_array($recurrence, ['once', 'daily', 'weekly', 'monthly'], true)) {
            throw new InvalidArgumentException('Schedule name, template, or recurrence is invalid.');
        }
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Schedule timezone is invalid.', 0, $e);
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
            throw new InvalidArgumentException('Schedule timezone must be a supported IANA timezone.');
        }
        $runTime = new DateTimeImmutable($runAt, $zone);
        $normalizedRunAt = $runTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $recipientName = trim((string)($payload['recipient_name'] ?? ''));
        $recipientEmail = trim((string)($payload['recipient_email'] ?? ''));
        $variables = $payload['variables'] ?? [];
        if ($recipientName === '' || strlen($recipientName) > 128 || !is_array($variables)
            || ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Scheduled issuance payload is invalid.');
        }
        $safePayload = [
            'recipient_name' => $recipientName,
            'recipient_email' => $recipientEmail === '' ? null : $recipientEmail,
            'variables' => $variables,
            'expires_at' => isset($payload['expires_at']) ? (string)$payload['expires_at'] : null,
        ];
        if (strlen(json_encode($safePayload, JSON_THROW_ON_ERROR)) > 65536) {
            throw new InvalidArgumentException('Scheduled issuance payload exceeds the supported size.');
        }

        $templates = $this->db->tableName('cert_templates');
        $template = $this->db->fetchOne(
            "SELECT id FROM {$templates}
             WHERE id = :template_id AND tenant_id = :tenant_id AND status = 'published'",
            ['template_id' => $templateId, 'tenant_id' => $tenantId]
        );
        if ($template === null) {
            throw new InvalidArgumentException('Schedules must use a published template from the active tenant.');
        }

        $schedules = $this->db->tableName('cert_schedules');
        $this->db->execute(
            "INSERT INTO {$schedules}
             (tenant_id, name, template_id, trigger_type, recurrence, next_run_at, status, payload_json, timezone, created_at)
             VALUES (:tenant_id, :name, :template_id, 'scheduled_issuance', :recurrence, :next_run_at,
                     'active', :payload, :timezone, CURRENT_TIMESTAMP)",
            [
                'tenant_id' => $tenantId,
                'name' => $name,
                'template_id' => $templateId,
                'recurrence' => $recurrence,
                'next_run_at' => $normalizedRunAt,
                'payload' => json_encode($safePayload, JSON_THROW_ON_ERROR),
                'timezone' => $timezone,
            ]
        );
        $scheduleId = $this->db->lastInsertId();
        $this->audit->log(
            $tenantId,
            'user',
            $this->tenantContext->getCurrentUserId(),
            'schedule.created',
            'schedule',
            (string)$scheduleId,
            ['recurrence' => $recurrence, 'template_id' => $templateId]
        );
        return $scheduleId;
    }

    public function listSchedules(): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_schedules');
        return $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE tenant_id = :tenant_id ORDER BY next_run_at ASC, id ASC",
            ['tenant_id' => $tenantId]
        );
    }

    public function setStatus(int $scheduleId, string $status): bool
    {
        $this->authorizer->require(Permissions::SCHEDULES_MANAGE);
        if ($scheduleId < 1 || !in_array($status, ['active', 'paused'], true)) {
            throw new InvalidArgumentException('Schedule status transition is invalid.');
        }
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_schedules');
        $current = $this->db->fetchOne(
            "SELECT status FROM {$table} WHERE id = :id AND tenant_id = :tenant_id",
            ['id' => $scheduleId, 'tenant_id' => $tenantId]
        );
        if ($current === null || !in_array($current['status'], ['active', 'paused'], true)) {
            return false;
        }
        if ($current['status'] === $status) {
            return true;
        }
        $updated = $this->db->execute(
            "UPDATE {$table} SET status = :status
             WHERE id = :id AND tenant_id = :tenant_id AND status IN ('active', 'paused')",
            ['status' => $status, 'id' => $scheduleId, 'tenant_id' => $tenantId]
        ) > 0;
        if ($updated) {
            $this->audit->log(
                $tenantId,
                'user',
                $this->tenantContext->getCurrentUserId(),
                'schedule.status.updated',
                'schedule',
                (string)$scheduleId,
                ['status' => $status]
            );
        }
        return $updated;
    }

    public function enqueueDueOccurrences(int $limit = 50): int
    {
        $this->authorizer->require(Permissions::SCHEDULES_MANAGE);
        $tenantId = $this->tenantContext->getTenantId();
        $limit = max(1, min(100, $limit));
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $schedules = $this->db->tableName('cert_schedules');
        $due = $this->db->fetchAll(
            "SELECT * FROM {$schedules}
             WHERE tenant_id = :tenant_id AND status = 'active' AND next_run_at <= :now
             ORDER BY next_run_at ASC, id ASC LIMIT {$limit}",
            ['tenant_id' => $tenantId, 'now' => $now]
        );
        $queued = 0;

        foreach ($due as $schedule) {
            $occurrence = (string)$schedule['next_run_at'];
            $timezone = (string)($schedule['timezone'] ?? 'UTC');
            $recurrence = (string)($schedule['recurrence'] ?? 'once');
            [$nextRun, $nextStatus] = $this->nextOccurrence($occurrence, $recurrence, $timezone);
            $pdo = $this->db->getPdo();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            try {
                $claimed = $this->db->execute(
                    "UPDATE {$schedules}
                     SET next_run_at = :next_run, status = :next_status,
                         last_run_at = CURRENT_TIMESTAMP, last_result = 'queued'
                     WHERE id = :id AND tenant_id = :tenant_id AND status = 'active' AND next_run_at = :occurrence",
                    [
                        'next_run' => $nextRun,
                        'next_status' => $nextStatus,
                        'id' => (int)$schedule['id'],
                        'tenant_id' => $tenantId,
                        'occurrence' => $occurrence,
                    ]
                );
                if ($claimed !== 1) {
                    if ($ownsTransaction) {
                        $this->db->rollBack();
                    }
                    continue;
                }
                $payload = json_decode((string)$schedule['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new InvalidArgumentException('The stored schedule payload is invalid.');
                }
                $dedupeKey = 'schedule:' . (int)$schedule['id'] . ':' . hash('sha256', $occurrence);
                $this->jobs->enqueueJob(
                    $tenantId,
                    'schedule.issue',
                    [
                        'schedule_id' => (int)$schedule['id'],
                        'template_id' => (int)$schedule['template_id'],
                        'occurrence' => $occurrence,
                        'dedupe_key' => $dedupeKey,
                        'issuance' => $payload,
                    ],
                    $now,
                    (int)$schedule['id'],
                    $dedupeKey
                );
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                $queued++;
                $this->audit->log(
                    $tenantId,
                    'system',
                    $this->tenantContext->getCurrentUserId(),
                    'schedule.occurrence.queued',
                    'schedule',
                    (string)$schedule['id'],
                    ['occurrence' => $occurrence, 'dedupe_key' => $dedupeKey]
                );
            } catch (Throwable $e) {
                if ($ownsTransaction) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }
        return $queued;
    }

    public function runDueJobs(int $limit = 20, int $leaseSeconds = 60, ?int $actorUserId = null): array
    {
        $this->authorizer->require(Permissions::SCHEDULES_MANAGE);
        $tenantId = $this->tenantContext->getTenantId();
        $jobs = $this->jobs->acquireDueJobs($limit, $leaseSeconds, $tenantId, 'schedule.issue');
        $issued = 0;
        $failed = 0;
        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            $leaseToken = (string)$job['lease_token'];
            try {
                $payload = json_decode((string)$job['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || (int)($payload['schedule_id'] ?? 0) !== (int)$job['schedule_id']
                    || !is_array($payload['issuance'] ?? null)) {
                    throw new InvalidArgumentException('The scheduled job payload is invalid.');
                }
                $schedules = $this->db->tableName('cert_schedules');
                $schedule = $this->db->fetchOne(
                    "SELECT status FROM {$schedules} WHERE id = :id AND tenant_id = :tenant_id",
                    ['id' => (int)$job['schedule_id'], 'tenant_id' => $tenantId]
                );
                if ($schedule === null || $schedule['status'] === 'paused') {
                    $this->jobs->completeJob($jobId, $leaseToken);
                    $this->updateScheduleResult((int)$job['schedule_id'], $tenantId, 'skipped');
                    continue;
                }

                $issuance = $payload['issuance'];
                $certificate = $this->issuanceService->issue(
                    new IssuanceCommand(
                        (int)$payload['template_id'],
                        (string)($issuance['recipient_name'] ?? ''),
                        is_array($issuance['variables'] ?? null) ? $issuance['variables'] : [],
                        isset($issuance['recipient_email']) ? (string)$issuance['recipient_email'] : null,
                        date('Y-m-d'),
                        isset($issuance['expires_at']) ? (string)$issuance['expires_at'] : null,
                        'schedule',
                        (string)$payload['dedupe_key']
                    ),
                    $actorUserId
                );
                if ($this->jobs->completeJob($jobId, $leaseToken)) {
                    $this->updateScheduleResult((int)$job['schedule_id'], $tenantId, 'issued');
                    $this->audit->log(
                        $tenantId,
                        'system',
                        $actorUserId,
                        'schedule.occurrence.issued',
                        'certificate',
                        (string)$certificate->id,
                        ['schedule_id' => (int)$job['schedule_id'], 'certificate_number' => $certificate->certificateNumber]
                    );
                    $issued++;
                }
            } catch (Throwable $e) {
                if (!$this->jobs->failJob($jobId, $leaseToken, $e->getMessage())) {
                    continue;
                }
                $this->updateScheduleResult((int)$job['schedule_id'], $tenantId, 'failed');
                $this->audit->log(
                    $tenantId,
                    'system',
                    $actorUserId,
                    'schedule.occurrence.failed',
                    'schedule',
                    (string)$job['schedule_id'],
                    ['job_id' => $jobId]
                );
                error_log('SOI scheduled issuance failed for job ' . $jobId . ': ' . $e->getMessage());
                $failed++;
            }
        }
        return ['leased' => count($jobs), 'issued' => $issued, 'failed' => $failed];
    }

    private function nextOccurrence(string $occurrence, string $recurrence, string $timezone): array
    {
        if ($recurrence === 'once') {
            return [null, 'completed'];
        }
        $zone = new DateTimeZone($timezone);
        $local = (new DateTimeImmutable($occurrence, new DateTimeZone('UTC')))->setTimezone($zone);
        $next = match ($recurrence) {
            'daily' => $local->modify('+1 day'),
            'weekly' => $local->modify('+1 week'),
            'monthly' => $this->nextMonth($local),
            default => throw new InvalidArgumentException('The stored schedule recurrence is invalid.'),
        };
        return [$next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'active'];
    }

    private function nextMonth(DateTimeImmutable $local): DateTimeImmutable
    {
        $day = (int)$local->format('j');
        $nextMonth = $local->modify('first day of next month');
        $day = min($day, (int)$nextMonth->format('t'));
        return $nextMonth->setDate((int)$nextMonth->format('Y'), (int)$nextMonth->format('n'), $day)
            ->setTime((int)$local->format('H'), (int)$local->format('i'), (int)$local->format('s'));
    }

    private function updateScheduleResult(int $scheduleId, int $tenantId, string $result): void
    {
        $schedules = $this->db->tableName('cert_schedules');
        $this->db->execute(
            "UPDATE {$schedules} SET last_result = :result WHERE id = :id AND tenant_id = :tenant_id",
            ['result' => $result, 'id' => $scheduleId, 'tenant_id' => $tenantId]
        );
    }
}
