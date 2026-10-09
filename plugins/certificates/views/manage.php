<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = "Tenant Administration - Manage";
ob_start();
?>

<!-- Operational Dashboard Metrics -->
<?php require __DIR__ . '/manage/dashboard.php'; ?>

<!-- Certificate Templates Management -->
<?php require __DIR__ . '/manage/templates/index.php'; ?>

<!-- Public / Dynamic Form Builder -->
<?php if ($canManageForms): ?>
  <?php require __DIR__ . '/manage/forms/builder.php'; ?>
<?php endif; ?>

<?php if ($canManageForms && $publishedTemplates !== []): ?>
  <script src="<?= htmlspecialchars($this->plugin->assetUrl('/js/form-builder.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>

<!-- Bulk Certificate Import Wizard -->
<?php if ($canIssueCertificates): ?>
  <?php require __DIR__ . '/manage/bulk/wizard.php'; ?>
<?php endif; ?>

<!-- API & Integrations -->
<div id="api-section">
  <!-- API Client Management -->
  <?php if ($canManageApiClients): ?>
    <?php require __DIR__ . '/manage/api/clients.php'; ?>
  <?php endif; ?>

  <!-- Signed Webhooks Configuration -->
  <?php if ($canManageWebhooks): ?>
    <?php require __DIR__ . '/manage/api/webhooks.php'; ?>
  <?php endif; ?>
</div>

<!-- Settings & Branding -->
<div id="settings-section">
  <!-- Tenant Branding & Custom CSS -->
  <?php require __DIR__ . '/manage/settings/branding.php'; ?>

  <!-- Managed Asset Gallery -->
  <?php if ($canManageAssets): ?>
    <?php require __DIR__ . '/manage/assets/gallery.php'; ?>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Verification Privacy</h2>
    </div>
  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/settings/verification')) ?>">
    <?= Session::csrfField() ?>
    <div class="form-group">
      <label class="form-label" for="verification_mode">Public verification mode</label>
      <select id="verification_mode" name="verification_mode" class="form-control" required>
        <?php foreach (['public' => 'Public', 'masked' => 'Masked recipient', 'pin' => 'PIN protected', 'authenticated' => 'Signed-in users', 'disabled' => 'Disabled'] as $mode => $label): ?>
          <option value="<?= htmlspecialchars($mode) ?>" <?= $verificationPolicy['mode'] === $mode ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label" for="verification_pin">New verification PIN (6-12 digits)</label>
      <input id="verification_pin" type="password" name="verification_pin" class="form-control" inputmode="numeric" minlength="6" maxlength="12" autocomplete="new-password">
      <small><?= $verificationPolicy['pin_configured'] ? 'Leave empty to retain the current PIN.' : 'Required before enabling PIN protection.' ?></small>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Save privacy settings</button>
  </form>
</div>
</div>

<?php require __DIR__ . '/manage/members/index.php'; ?>

<!-- Form Approval Queue -->
<?php if ($canReviewForms): ?>
  <?php require __DIR__ . '/manage/forms/approval_queue.php'; ?>
<?php endif; ?>

<?php if ($canManageSchedules): ?>
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Scheduled Issuance</h2>
      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/schedules/run')) ?>">
        <?= Session::csrfField() ?>
        <button type="submit" class="btn btn-secondary btn-sm">Run due schedules</button>
      </form>
    </div>
    <?php if ($publishedTemplates === []): ?>
      <p>Publish a template before creating an issuance schedule.</p>
    <?php else: ?>
      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/schedules/create')) ?>">
        <?= Session::csrfField() ?>
        <div class="grid-3">
          <div class="form-group">
            <label class="form-label" for="schedule_name">Schedule name</label>
            <input id="schedule_name" name="name" class="form-control" maxlength="128" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="schedule_template">Published template</label>
            <select id="schedule_template" name="template_id" class="form-control" required>
              <?php foreach ($publishedTemplates as $publishedTemplate): ?>
                <option value="<?= $publishedTemplate->id ?>"><?= htmlspecialchars($publishedTemplate->name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="schedule_recurrence">Recurrence</label>
            <select id="schedule_recurrence" name="recurrence" class="form-control">
              <option value="once">One time</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="schedule_run_at">First run time</label>
            <input id="schedule_run_at" name="next_run_at" type="datetime-local" class="form-control" value="<?= gmdate('Y-m-d\TH:i', time() + 300) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="schedule_timezone">Timezone</label>
            <select id="schedule_timezone" name="timezone" class="form-control">
              <?php foreach (['UTC', 'Asia/Kolkata', 'America/New_York', 'Europe/London', 'Australia/Sydney'] as $timezone): ?>
                <option value="<?= htmlspecialchars($timezone) ?>"><?= htmlspecialchars($timezone) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="schedule_recipient">Recipient name</label>
            <input id="schedule_recipient" name="recipient_name" class="form-control" maxlength="128" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="schedule_email">Recipient email</label>
            <input id="schedule_email" name="recipient_email" type="email" class="form-control" maxlength="128">
          </div>
          <div class="form-group">
            <label class="form-label" for="schedule_course">Course / program</label>
            <input id="schedule_course" name="course_name" class="form-control" maxlength="128" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Create schedule</button>
      </form>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Status</th><th>Next run (UTC)</th><th>Last result</th><th>Action</th></tr></thead>
        <tbody>
          <?php if ($schedules === []): ?>
            <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">No schedules configured.</td></tr>
          <?php else: ?>
            <?php foreach ($schedules as $schedule): ?>
              <tr>
                <td><?= htmlspecialchars($schedule['name']) ?></td>
                <td><?= htmlspecialchars($schedule['status']) ?></td>
                <td><?= htmlspecialchars((string)($schedule['next_run_at'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string)($schedule['last_result'] ?? '—')) ?></td>
                <td>
                  <?php if (in_array($schedule['status'], ['active', 'paused'], true)): ?>
                    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/schedules/status')) ?>">
                      <?= Session::csrfField() ?>
                      <input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                      <input type="hidden" name="status" value="<?= $schedule['status'] === 'active' ? 'paused' : 'active' ?>">
                      <button type="submit" class="btn btn-outline btn-sm"><?= $schedule['status'] === 'active' ? 'Pause' : 'Resume' ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="grid-2">
  <!-- Create Template -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Create New Template</h2>
    </div>

    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/create')) ?>">
      <?= Session::csrfField() ?>
      <div class="form-group">
        <label class="form-label">Template Title</label>
        <input type="text" name="name" class="form-control" placeholder="e.g. Graduate Internship Certification" required>
      </div>
      <div class="form-group">
        <label class="form-label">Template Identifier (Slug)</label>
        <input type="text" name="slug" class="form-control" placeholder="e.g. graduate-internship-cert" required>
      </div>
      <div class="form-group">
        <label class="form-label">Category</label>
        <input type="text" name="category" class="form-control" placeholder="e.g. Internship / Training">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Create Draft Template</button>
    </form>
  </div>

  <!-- Audit Trail -->
  <div class="card" id="audit-section">
    <div class="card-header">
      <h2 class="card-title">Recent Audit Trail</h2>
    </div>

    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Event</th>
            <th>Target</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentAudit)): ?>
            <tr><td colspan="3" style="text-align:center; color: var(--text-muted);">No audit entries logged yet.</td></tr>
          <?php else: ?>
            <?php foreach ($recentAudit as $log): ?>
              <tr>
                <td><strong><?= htmlspecialchars($log['event_key']) ?></strong></td>
                <td><?= htmlspecialchars($log['target_type']) ?> #<?= htmlspecialchars($log['target_id']) ?></td>
                <td><small style="color: var(--text-muted);"><?= htmlspecialchars($log['created_at']) ?></small></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>
