<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Form Approval Queue UI Partial
 * Displays pending submissions requiring manual operator approval before issuance,
 * along with quick approve & issue and rejection modal/inputs.
 *
 * @var array $pendingFormSubmissions
 * @var bool $canManageForms
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="form-approval-queue-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Form Approval Queue</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted);">
        Review incoming self-service certificate applications requiring manual administrative sign-off.
      </p>
    </div>
    <span class="badge badge-info"><?= count($pendingFormSubmissions) ?> pending</span>
  </div>

  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Submission</th>
          <th>Form Title</th>
          <th>Submitted At</th>
          <th>Decision / Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pendingFormSubmissions)): ?>
          <tr>
            <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 2rem;">
              No pending form submissions in queue.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($pendingFormSubmissions as $submission): ?>
            <tr>
              <td>
                <strong>#<?= (int)$submission['id'] ?></strong>
              </td>
              <td><?= htmlspecialchars($submission['form_title'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($submission['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
              <td style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/forms/submissions/' . (int)$submission['id'] . '/approve')) ?>" style="display:inline;">
                  <?= Session::csrfField() ?>
                  <button type="submit" class="btn btn-primary btn-sm">Approve &amp; Issue</button>
                </form>
                <?php if ($canManageForms ?? true): ?>
                  <details style="display: inline-block;">
                    <summary class="btn btn-outline btn-sm">Reject</summary>
                    <div style="position: absolute; z-index: 10; background: var(--bg-card, #fff); border: 1px solid var(--border-color); padding: 1rem; border-radius: var(--radius-sm); box-shadow: var(--shadow-md); width: 280px; margin-top: 0.25rem;">
                      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/forms/submissions/' . (int)$submission['id'] . '/reject')) ?>">
                        <?= Session::csrfField() ?>
                        <div class="form-group">
                          <label class="form-label" for="form_reject_reason_<?= (int)$submission['id'] ?>">Rejection Reason</label>
                          <input id="form_reject_reason_<?= (int)$submission['id'] ?>" name="reason" class="form-control" maxlength="1000" placeholder="e.g. Incomplete documentation" required>
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;">Confirm Rejection</button>
                      </form>
                    </div>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
