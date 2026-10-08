<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * API Client Management UI Partial
 * Provides credentials generation, scope delegation, and active client revocation.
 *
 * @var array|null $apiClientCredentials One-time credentials display
 * @var array $apiClients List of registered API clients
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="api-clients-management-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">API Client Management</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted);">
        Manage scoped machine-to-machine API credentials for automated certificate integration.
      </p>
    </div>
  </div>

  <?php if (is_array($apiClientCredentials ?? null) && isset($apiClientCredentials['secret'], $apiClientCredentials['client_id'])): ?>
    <div class="alert alert-warning" role="alert" style="margin-bottom: 1.5rem; background: #fffbeb; border: 1px solid #fef3c7; color: #92400e; padding: 1rem; border-radius: var(--radius-sm);">
      <strong style="display: block; font-size: 1rem; margin-bottom: 0.5rem;">⚠️ Copy this API secret now; it will not be shown again!</strong>
      <p style="margin: 0.25rem 0;">Client ID: <code><?= htmlspecialchars($apiClientCredentials['client_id'], ENT_QUOTES, 'UTF-8') ?></code></p>
      <p style="margin: 0.25rem 0;">Bearer secret: <code><?= htmlspecialchars($apiClientCredentials['secret'], ENT_QUOTES, 'UTF-8') ?></code></p>
      <p style="margin: 0.25rem 0;">Granted Scopes: <code><?= htmlspecialchars(implode(', ', $apiClientCredentials['scopes'] ?? []), ENT_QUOTES, 'UTF-8') ?></code></p>
    </div>
  <?php endif; ?>

  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/api/clients/create')) ?>">
    <?= Session::csrfField() ?>
    <div class="grid-3">
      <div class="form-group">
        <label class="form-label" for="api_client_name">Client Application Name</label>
        <input id="api_client_name" name="name" class="form-control" maxlength="128" placeholder="e.g. ERP Integration Service" required>
      </div>
      <fieldset class="form-group" style="grid-column: span 2; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem 1rem;">
        <legend class="form-label" style="font-size: 0.85rem; font-weight: 600; padding: 0 0.5rem;">Delegated Scopes</legend>
        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap;">
          <?php foreach (['templates.read', 'certificates.read', 'certificates.issue', 'certificates.revoke'] as $scope): ?>
            <label style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.875rem; cursor: pointer;">
              <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>">
              <code><?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?></code>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Create API Client</button>
  </form>

  <div class="table-responsive" style="margin-top: 1.5rem;">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem;">Active API Clients</h3>
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Client ID</th>
          <th>Scopes</th>
          <th>Status</th>
          <th>Last Used</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($apiClients)): ?>
          <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No API clients configured yet.</td></tr>
        <?php else: ?>
          <?php foreach ($apiClients as $apiClient): ?>
            <tr>
              <td><strong><?= htmlspecialchars($apiClient['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
              <td><code><?= htmlspecialchars($apiClient['client_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
              <td>
                <?php
                  $scopes = json_decode((string)($apiClient['scopes_json'] ?? '[]'), true) ?: [];
                  foreach ($scopes as $sc):
                ?>
                  <span class="badge badge-info" style="font-size: 0.75rem; margin-right: 0.25rem;"><?= htmlspecialchars($sc, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
              </td>
              <td>
                <span class="badge <?= ($apiClient['status'] ?? '') === 'active' ? 'badge-success' : 'badge-warning' ?>">
                  <?= htmlspecialchars(ucfirst((string)($apiClient['status'] ?? 'unknown')), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td><small style="color: var(--text-muted);"><?= htmlspecialchars((string)($apiClient['last_used_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></small></td>
              <td>
                <?php if (($apiClient['status'] ?? '') === 'active'): ?>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/api/clients/revoke')) ?>" onsubmit="return confirm('Revoke this API client? Immediate token invalidation will occur.');">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="client_id" value="<?= (int)$apiClient['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                  </form>
                <?php else: ?>
                  <span style="color: var(--text-muted); font-size: 0.8rem;">Revoked</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
