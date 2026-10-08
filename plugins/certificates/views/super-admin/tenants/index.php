<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Super-Admin Tenant Management Partial
 * Displays tenant directory, status toggles, and tenant creation form.
 *
 * @var array<\SOI\Certificates\Tenancy\Tenant> $tenants
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="tenant-management-card">
  <div class="card-header">
    <h2 class="card-title">Tenant Organizations</h2>
    <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($tenants) ?> registered tenant(s)</span>
  </div>

  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name / Slug</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tenants)): ?>
          <tr><td colspan="3" style="text-align:center; color: var(--text-muted);">No tenants registered yet.</td></tr>
        <?php else: ?>
          <?php foreach ($tenants as $t): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($t->displayName, ENT_QUOTES, 'UTF-8') ?></strong><br>
                <small style="color: var(--text-muted);"><?= htmlspecialchars($t->slug, ENT_QUOTES, 'UTF-8') ?></small>
              </td>
              <td>
                <span class="badge <?= $t->status === 'active' ? 'badge-success' : 'badge-danger' ?>">
                  <?= htmlspecialchars(ucfirst($t->status), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td>
                <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/super-admin/tenants/suspend')) ?>" style="display:inline;">
                  <?= Session::csrfField() ?>
                  <input type="hidden" name="tenant_id" value="<?= (int)$t->id ?>">
                  <input type="hidden" name="status" value="<?= htmlspecialchars($t->status, ENT_QUOTES, 'UTF-8') ?>">
                  <button type="submit" class="btn btn-outline btn-sm">
                    <?= $t->status === 'active' ? 'Suspend' : 'Activate' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <h3 style="font-size: 0.95rem; margin: 1.5rem 0 0.75rem;">Create New Tenant</h3>
  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/super-admin/tenants/create')) ?>">
    <?= Session::csrfField() ?>
    <div class="form-group">
      <label class="form-label" for="new_tenant_display_name">Organization Name</label>
      <input id="new_tenant_display_name" type="text" name="display_name" class="form-control" placeholder="e.g. Acme Academy" required maxlength="128">
    </div>
    <div class="form-group">
      <label class="form-label" for="new_tenant_slug">Tenant Slug (URL-Safe Identifier)</label>
      <input id="new_tenant_slug" type="text" name="slug" class="form-control" placeholder="e.g. acme-academy" required maxlength="64" pattern="^[a-z0-9][a-z0-9-]{0,63}$">
      <small style="color: var(--text-muted); font-size: 0.75rem;">Lowercase letters, numbers, and hyphens only.</small>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Create Tenant</button>
  </form>
</div>
