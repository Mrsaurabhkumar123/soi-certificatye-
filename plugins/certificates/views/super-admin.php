<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = "Platform Super Admin - SOI Certificates";
ob_start();
?>

<?php require __DIR__ . '/super-admin/dashboard.php'; ?>

<div class="grid-2">
  <!-- Tenant Directory -->
  <?php require __DIR__ . '/super-admin/tenants/index.php'; ?>


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
