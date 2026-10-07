<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = "Tenant Administration - Manage";
ob_start();
?>

<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Certificate Templates</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted);">Manage and publish versioned certificate templates for <?= htmlspecialchars($tenant->displayName) ?></p>
    </div>
  </div>

  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Template Name / Slug</th>
          <th>Category</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($templates as $tpl): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($tpl->name) ?></strong><br>
              <small style="color: var(--text-muted);"><?= htmlspecialchars($tpl->slug) ?></small>
            </td>
            <td><?= htmlspecialchars($tpl->category ?? 'General') ?></td>
            <td>
              <span class="badge <?= $tpl->isPublished() ? 'badge-success' : 'badge-warning' ?>">
                <?= htmlspecialchars($tpl->status) ?>
              </span>
            </td>
            <td>
              <?php if (!$tpl->isPublished()): ?>
                <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/publish')) ?>" style="display:inline;">
                  <?= Session::csrfField() ?>
                  <input type="hidden" name="template_id" value="<?= $tpl->id ?>">
                  <button type="submit" class="btn btn-primary btn-sm">Publish Version</button>
                </form>
              <?php else: ?>
                <span style="font-size: 0.8rem; color: var(--success); font-weight: 600;">✓ Active for Issuance</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

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
  <div class="card">
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
