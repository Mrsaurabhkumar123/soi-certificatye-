<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = "Platform Super Admin - SOI Certificates";
ob_start();
?>

<div class="grid-4" style="margin-bottom: 2rem;">
  <div class="stat-box">
    <span class="stat-label">Active Tenants</span>
    <span class="stat-value"><?= $activeTenantCount ?></span>
  </div>
  <div class="stat-box">
    <span class="stat-label">Total Certificates Issued</span>
    <span class="stat-value"><?= $totalCerts ?></span>
  </div>
  <div class="stat-box">
    <span class="stat-label">Suspended Tenants</span>
    <span class="stat-value"><?= $suspendedTenantCount ?></span>
  </div>
  <div class="stat-box">
    <span class="stat-label">Platform Health</span>
    <span class="stat-value" style="color: <?= $health['status'] === 'healthy' ? 'var(--success)' : 'var(--danger)' ?>;">
      <?= strtoupper($health['status']) ?>
    </span>
  </div>
</div>

<div class="grid-2">
  <!-- Tenant Directory -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Tenant Organizations</h2>
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
          <?php foreach ($tenants as $t): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($t->displayName) ?></strong><br>
                <small style="color: var(--text-muted);"><?= htmlspecialchars($t->slug) ?></small>
              </td>
              <td>
                <span class="badge <?= $t->status === 'active' ? 'badge-success' : 'badge-danger' ?>">
                  <?= htmlspecialchars($t->status) ?>
                </span>
              </td>
              <td>
                <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/super-admin/tenants/suspend')) ?>" style="display:inline;">
                  <?= Session::csrfField() ?>
                  <input type="hidden" name="tenant_id" value="<?= $t->id ?>">
                  <input type="hidden" name="status" value="<?= $t->status ?>">
                  <button type="submit" class="btn btn-outline btn-sm">
                    <?= $t->status === 'active' ? 'Suspend' : 'Activate' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3 style="font-size: 0.95rem; margin: 1.5rem 0 0.75rem;">Create New Tenant</h3>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/super-admin/tenants/create')) ?>">
      <?= Session::csrfField() ?>
      <div class="form-group">
        <label class="form-label">Organization Name</label>
        <input type="text" name="display_name" class="form-control" placeholder="e.g. Acme Academy" required>
      </div>
      <div class="form-group">
        <label class="form-label">Tenant Slug</label>
        <input type="text" name="slug" class="form-control" placeholder="e.g. acme-academy" required>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Create Tenant</button>
    </form>
  </div>

  <!-- System Health & Migrations -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Platform Diagnostic Checks</h2>
      <span class="badge badge-info">v<?= htmlspecialchars($health['version']) ?></span>
    </div>

    <table class="data-table" style="margin-bottom: 1.5rem;">
      <thead>
        <tr>
          <th>Subsystem</th>
          <th>Status</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($health['checks'] as $key => $check): ?>
          <tr>
            <td><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?></strong></td>
            <td>
              <span class="badge <?= $check['status'] === 'healthy' ? 'badge-success' : 'badge-danger' ?>">
                <?= htmlspecialchars($check['status']) ?>
              </span>
            </td>
            <td><?= htmlspecialchars($check['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="card-header" style="padding-top: 1rem;">
      <h2 class="card-title">Schema Migrations</h2>
    </div>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.75rem;">
      Applied: <strong><?= count($appliedMigrations) ?></strong> | 
      Pending: <strong><?= count($pendingMigrations) ?></strong>
    </p>

    <?php if (count($pendingMigrations) > 0): ?>
      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/super-admin/migrations/run')) ?>">
        <?= Session::csrfField() ?>
        <button type="submit" class="btn btn-primary btn-sm">Run <?= count($pendingMigrations) ?> Pending Migration(s)</button>
      </form>
    <?php else: ?>
      <p style="font-size: 0.85rem; color: var(--success); font-weight: 600;">✓ Database schema is up to date.</p>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>
