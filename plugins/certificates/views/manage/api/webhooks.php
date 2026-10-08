<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Webhook Configuration UI Partial
 * Configures outbound webhook subscribers, subscribed lifecycle events, and HMAC-SHA256 signing.
 *
 * @var array $webhooks List of configured webhooks
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="webhooks-management-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Signed Webhooks</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted);">
        Real-time event delivery over HTTPS using HMAC-SHA256 signatures, pinned DNS, and exponential backoff retries.
      </p>
    </div>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/webhooks/run')) ?>">
      <?= Session::csrfField() ?>
      <button type="submit" class="btn btn-secondary btn-sm">Process Due Deliveries</button>
    </form>
  </div>

  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/webhooks/create')) ?>">
    <?= Session::csrfField() ?>
    <div class="grid-3">
      <div class="form-group">
        <label class="form-label" for="webhook_name">Endpoint Identifier</label>
        <input id="webhook_name" name="name" class="form-control" maxlength="128" placeholder="e.g. Production Webhook" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="webhook_url">HTTPS Target URL</label>
        <input id="webhook_url" name="target_url" class="form-control" type="url" pattern="https://.*" maxlength="255" placeholder="https://api.example.com/webhooks" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="webhook_secret">HMAC Signing Secret (32-64 chars)</label>
        <input id="webhook_secret" name="secret" class="form-control" type="password" minlength="32" maxlength="64" autocomplete="new-password" placeholder="At least 32 random characters" required>
      </div>
    </div>
    <fieldset class="form-group" style="border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem 1rem; margin-bottom: 1rem;">
      <legend class="form-label" style="font-size: 0.85rem; font-weight: 600; padding: 0 0.5rem;">Subscribed Lifecycle Events</legend>
      <div style="display: flex; gap: 1.5rem; flex-wrap: wrap;">
        <?php foreach (['certificate.issued', 'certificate.revoked', 'certificate.replaced', 'certificate.expired'] as $eventKey): ?>
          <label style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.875rem; cursor: pointer;">
            <input type="checkbox" name="events[]" value="<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>">
            <code><?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?></code>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <button type="submit" class="btn btn-primary btn-sm">Save Webhook Endpoint</button>
  </form>

  <p class="text-muted" style="font-size: 0.8rem; margin-top: 1rem;">
    🔒 Secrets are encrypted at rest using AES-256-GCM. Outbound deliveries verify receiver SSL/TLS certificates and sign headers with <code>X-SOI-Signature</code>.
  </p>

  <div class="table-responsive" style="margin-top: 1.5rem;">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem;">Configured Endpoints</h3>
    <table class="data-table">
      <thead>
        <tr>
          <th>Endpoint Name</th>
          <th>Target URL</th>
          <th>Subscribed Events</th>
          <th>State</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($webhooks)): ?>
          <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No webhook endpoints configured yet.</td></tr>
        <?php else: ?>
          <?php foreach ($webhooks as $webhook): ?>
            <?php $webhookEvents = json_decode((string)($webhook['events_json'] ?? '[]'), true) ?: []; ?>
            <tr>
              <td><strong><?= htmlspecialchars($webhook['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
              <td><code><?= htmlspecialchars($webhook['target_url'], ENT_QUOTES, 'UTF-8') ?></code></td>
              <td>
                <?php foreach ($webhookEvents as $ev): ?>
                  <span class="badge badge-info" style="font-size: 0.75rem; margin-right: 0.25rem;"><?= htmlspecialchars($ev, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
              </td>
              <td>
                <span class="badge <?= (int)($webhook['is_active'] ?? 0) === 1 ? 'badge-success' : 'badge-warning' ?>">
                  <?= (int)($webhook['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
