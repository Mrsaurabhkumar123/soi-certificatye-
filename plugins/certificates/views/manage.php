<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = "Tenant Administration - Manage";
ob_start();
?>

<section class="grid-4" aria-label="Tenant operations dashboard">
  <?php foreach ([
      'Certificates issued' => $dashboardMetrics['issued_total'],
      'Issued in last 30 days' => $dashboardMetrics['issued_30d'],
      'Pending form approvals' => $dashboardMetrics['pending_forms'],
      'Failed schedules' => $dashboardMetrics['failed_schedules'],
  ] as $metricLabel => $metricValue): ?>
    <article class="card stat-card">
      <h2 class="card-title"><?= htmlspecialchars($metricLabel, ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="stat-value"><?= (int)$metricValue ?></p>
    </article>
  <?php endforeach; ?>
</section>

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
                <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/' . $tpl->id . '/designer')) ?>">Design</a>
                <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/publish')) ?>" style="display:inline;">
                  <?= Session::csrfField() ?>
                  <input type="hidden" name="template_id" value="<?= $tpl->id ?>">
                  <button type="submit" class="btn btn-primary btn-sm">Publish Version</button>
                </form>
              <?php else: ?>
                <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/' . $tpl->id . '/designer')) ?>">Edit as draft</a>
                <span style="font-size: 0.8rem; color: var(--success); font-weight: 600;">✓ Active for Issuance</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManageForms): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Public Application Forms</h2>
        <p>Request forms use a published template and can require review or issue immediately.</p>
      </div>
    </div>
    <?php if ($publishedTemplates === []): ?>
      <p>Publish a template before creating a public form.</p>
    <?php else: ?>
      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/forms/create')) ?>">
        <?= Session::csrfField() ?>
        <div class="grid-3">
          <div class="form-group">
            <label class="form-label" for="form_title">Form title</label>
            <input id="form_title" name="title" class="form-control" maxlength="128" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="form_key">Public URL key</label>
            <input id="form_key" name="form_key" class="form-control" pattern="[a-z0-9][a-z0-9-]{0,63}" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="form_template">Published template</label>
            <select id="form_template" name="template_id" class="form-control" required>
              <?php foreach ($publishedTemplates as $publishedTemplate): ?>
                <option value="<?= $publishedTemplate->id ?>"
                  data-variables="<?= htmlspecialchars(json_encode(
                      $formBuilderVariables[$publishedTemplate->id] ?? [],
                      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                  ), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($publishedTemplate->name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="form_issue_mode">Submission policy</label>
            <select id="form_issue_mode" name="issue_mode" class="form-control">
              <option value="approval">Review before issuing</option>
              <option value="request_only">Request only (manual processing)</option>
              <option value="immediate">Issue immediately on valid submission</option>
            </select>
          </div>
        </div>
        <fieldset id="form-field-builder" class="form-field-builder">
          <legend>Public form fields</legend>
          <p class="text-muted">Choose which published-template variables applicants can provide. Required template variables cannot be removed or made optional.</p>
          <div id="form-field-list" class="form-field-list" aria-live="polite"></div>
          <button type="button" class="btn btn-secondary btn-sm" id="form-add-field">Add field</button>
          <input type="hidden" name="field_mapping" id="form-field-mapping">
          <input type="hidden" name="field_schema" id="form-field-schema">
        </fieldset>
        <button type="submit" class="btn btn-primary btn-sm">Create public form</button>
      </form>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Form</th><th>Policy</th><th>State</th><th>Public URL</th></tr></thead>
        <tbody>
          <?php if ($forms === []): ?>
            <tr><td colspan="4">No public forms configured.</td></tr>
          <?php else: ?>
            <?php foreach ($forms as $form): ?>
              <tr>
                <td><?= htmlspecialchars($form['title']) ?></td>
                <td><?= htmlspecialchars(str_replace('_', ' ', $form['issue_mode'])) ?></td>
                <td><?= (int)$form['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                <td><a href="<?= htmlspecialchars($this->plugin->router->url('/forms/' . rawurlencode($form['form_key']))) ?>" target="_blank" rel="noopener">Open form</a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($canManageForms && $publishedTemplates !== []): ?>
  <script src="<?= htmlspecialchars($this->plugin->router->url('/assets/js/form-builder.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>

<?php if ($canIssueCertificates): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Bulk Certificate Import</h2>
        <p>Upload CSV/XLSX or paste CSV/TSV data. Batches are validated and processed in resumable server-side chunks.</p>
      </div>
    </div>
    <?php if ($publishedTemplates === []): ?>
      <p>Publish a template before creating an import batch.</p>
    <?php else: ?>
      <form method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($this->plugin->router->url('/manage/bulk/create')) ?>">
        <?= Session::csrfField() ?>
        <div class="grid-3">
          <div class="form-group">
            <label class="form-label" for="bulk_template">Published template</label>
            <select id="bulk_template" name="template_id" class="form-control" required>
              <?php foreach ($publishedTemplates as $publishedTemplate): ?>
                <option value="<?= $publishedTemplate->id ?>"><?= htmlspecialchars($publishedTemplate->name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="bulk_csv">CSV or XLSX file (max 2 MB)</label>
            <input id="bulk_csv" type="file" name="csv_file" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
          </div>
          <div class="form-group">
            <label class="form-label" for="bulk_name_column">Name column</label>
            <input id="bulk_name_column" name="name_column" class="form-control" value="Recipient Name" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="bulk_email_column">Email column (optional)</label>
            <input id="bulk_email_column" name="email_column" class="form-control" value="Email">
          </div>
          <div class="form-group">
            <label class="form-label" for="bulk_course_column">Course column (optional)</label>
            <input id="bulk_course_column" name="course_column" class="form-control" value="Course">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="bulk_pasted_data">Or paste CSV/TSV data</label>
          <textarea id="bulk_pasted_data" name="pasted_data" class="form-control" rows="5" maxlength="2097152" placeholder="Recipient Name&#9;Email&#9;Course&#10;Asha Rai&#9;asha@example.com&#9;Leadership Program"></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Validate and create batch</button>
      </form>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Batch</th><th>Status</th><th>Rows</th><th>Progress</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($bulkBatches as $batch): ?>
            <tr>
              <td>#<?= (int)$batch['id'] ?></td>
              <td id="bulk_status_<?= (int)$batch['id'] ?>"><?= htmlspecialchars($batch['status']) ?></td>
              <td><?= (int)$batch['total_rows'] ?></td>
              <td id="bulk_progress_<?= (int)$batch['id'] ?>"><?= (int)$batch['succeeded_rows'] ?> succeeded / <?= (int)$batch['failed_rows'] ?> failed</td>
              <td>
                <?php if (!in_array($batch['status'], ['completed', 'cancelled'], true)): ?>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="processBulkBatch(<?= (int)$batch['id'] ?>)">Process / resume</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($bulkBatches === []): ?><tr><td colspan="5">No import batches yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <script>
    async function processBulkBatch(batchId) {
      const button = document.querySelector(`button[onclick="processBulkBatch(${batchId})"]`);
      const status = document.getElementById(`bulk_status_${batchId}`);
      const progress = document.getElementById(`bulk_progress_${batchId}`);
      if (button) button.disabled = true;
      try {
        let result;
        do {
          const body = new FormData();
          body.append('batch_id', String(batchId));
          body.append('_csrf_token', document.querySelector('input[name="_csrf_token"]')?.value || '');
          const response = await fetch(<?= json_encode($this->plugin->router->url('/manage/bulk/process')) ?>, {
            method: 'POST', body, credentials: 'same-origin', headers: {'Accept': 'application/json'}
          });
          const json = await response.json();
          if (!response.ok || !json.data) throw new Error(json.error?.message || 'Import processing failed.');
          result = json.data;
          status.textContent = result.status;
          progress.textContent = `${result.succeeded} succeeded / ${result.failed} failed / ${result.remaining} remaining`;
        } while (result.remaining > 0);
        if (button) button.remove();
      } catch (error) {
        status.textContent = error.message;
        if (button) button.disabled = false;
      }
    }
  </script>
<?php endif; ?>

<?php if ($canManageApiClients): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">API Client Management</h2>
        <p>Secrets are shown once at creation. Revoke credentials immediately if they are exposed.</p>
      </div>
    </div>
    <?php if (is_array($apiClientCredentials) && isset($apiClientCredentials['secret'], $apiClientCredentials['client_id'])): ?>
      <div class="alert alert-warning" role="alert">
        <strong>Copy this API secret now; it will not be shown again.</strong>
        <p>Client ID: <code><?= htmlspecialchars($apiClientCredentials['client_id'], ENT_QUOTES, 'UTF-8') ?></code></p>
        <p>Bearer secret: <code><?= htmlspecialchars($apiClientCredentials['secret'], ENT_QUOTES, 'UTF-8') ?></code></p>
        <p>Scopes: <?= htmlspecialchars(implode(', ', $apiClientCredentials['scopes'] ?? []), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    <?php endif; ?>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/api/clients/create')) ?>">
      <?= Session::csrfField() ?>
      <div class="grid-3">
        <div class="form-group">
          <label class="form-label" for="api_client_name">Client name</label>
          <input id="api_client_name" name="name" class="form-control" maxlength="128" required>
        </div>
        <fieldset class="form-group">
          <legend class="form-label">Scopes</legend>
          <?php foreach (['templates.read', 'certificates.read', 'certificates.issue', 'certificates.revoke'] as $scope): ?>
            <label style="display:block"><input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scope) ?>"> <?= htmlspecialchars($scope) ?></label>
          <?php endforeach; ?>
        </fieldset>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Create API client</button>
    </form>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Client ID</th><th>Scopes</th><th>Status</th><th>Last used</th><th>Action</th></tr></thead>
        <tbody>
          <?php if ($apiClients === []): ?>
            <tr><td colspan="6">No API clients configured.</td></tr>
          <?php else: ?>
            <?php foreach ($apiClients as $apiClient): ?>
              <tr>
                <td><?= htmlspecialchars($apiClient['name']) ?></td>
                <td><code><?= htmlspecialchars($apiClient['client_id']) ?></code></td>
                <td><?= htmlspecialchars(implode(', ', json_decode($apiClient['scopes_json'], true) ?: [])) ?></td>
                <td><?= htmlspecialchars($apiClient['status']) ?></td>
                <td><?= htmlspecialchars((string)($apiClient['last_used_at'] ?? 'Never')) ?></td>
                <td>
                  <?php if ($apiClient['status'] === 'active'): ?>
                    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/api/clients/revoke')) ?>" onsubmit="return confirm('Revoke this API client?');">
                      <?= Session::csrfField() ?>
                      <input type="hidden" name="client_id" value="<?= (int)$apiClient['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
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

<?php if ($canManageWebhooks): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Signed Webhooks</h2>
        <p>Delivery uses HTTPS, pinned public DNS addresses, HMAC-SHA256 signatures, and bounded retries.</p>
      </div>
      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/webhooks/run')) ?>">
        <?= Session::csrfField() ?>
        <button type="submit" class="btn btn-secondary btn-sm">Process due deliveries</button>
      </form>
    </div>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/webhooks/create')) ?>">
      <?= Session::csrfField() ?>
      <div class="grid-3">
        <div class="form-group">
          <label class="form-label" for="webhook_name">Endpoint name</label>
          <input id="webhook_name" name="name" class="form-control" maxlength="128" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="webhook_url">HTTPS URL</label>
          <input id="webhook_url" name="target_url" class="form-control" type="url" pattern="https://.*" maxlength="255" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="webhook_secret">Signing secret (32-64 characters)</label>
          <input id="webhook_secret" name="secret" class="form-control" type="password" minlength="32" maxlength="64" autocomplete="new-password" required>
        </div>
      </div>
      <fieldset class="form-group">
        <legend class="form-label">Subscribed events</legend>
        <?php foreach (['certificate.issued', 'certificate.revoked', 'certificate.replaced', 'certificate.expired'] as $eventKey): ?>
          <label style="margin-right:1rem"><input type="checkbox" name="events[]" value="<?= htmlspecialchars($eventKey) ?>"> <?= htmlspecialchars($eventKey) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <button type="submit" class="btn btn-primary btn-sm">Save webhook</button>
    </form>
    <p class="text-muted">Set <code>SOI_CERT_WEBHOOK_ENCRYPTION_KEY</code> to a private key of at least 32 characters. Secrets are encrypted at rest.</p>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Target</th><th>Events</th><th>State</th></tr></thead>
        <tbody>
          <?php if ($webhooks === []): ?>
            <tr><td colspan="4">No webhook endpoints configured.</td></tr>
          <?php else: ?>
            <?php foreach ($webhooks as $webhook): ?>
              <?php $webhookEvents = json_decode((string)$webhook['events_json'], true) ?: []; ?>
              <tr>
                <td><?= htmlspecialchars($webhook['name']) ?></td>
                <td><?= htmlspecialchars($webhook['target_url']) ?></td>
                <td><?= htmlspecialchars(implode(', ', $webhookEvents)) ?></td>
                <td><?= (int)$webhook['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h2 class="card-title">Tenant Branding</h2>
  </div>
  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/settings/branding')) ?>">
    <?= Session::csrfField() ?>
    <div class="grid-3">
      <div class="form-group">
        <label class="form-label" for="primary_color">Primary color</label>
        <input id="primary_color" name="primary_color" class="form-control" type="text" pattern="#[0-9A-Fa-f]{6}" value="<?= htmlspecialchars($tenant->branding['primary_color'] ?? '#1e3a8a') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="accent_color">Accent color</label>
        <input id="accent_color" name="accent_color" class="form-control" type="text" pattern="#[0-9A-Fa-f]{6}" value="<?= htmlspecialchars($tenant->branding['accent_color'] ?? '#d97706') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="logo">Managed logo reference</label>
        <input id="logo" name="logo" class="form-control" type="text" maxlength="255" value="<?= htmlspecialchars($tenant->branding['logo'] ?? '') ?>">
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Save branding</button>
  </form>
</div>

<?php if ($canManageAssets): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Managed Certificate Assets</h2>
        <p>Upload a logo, seal, signature, or background. SVG uploads are sanitized before storage.</p>
      </div>
    </div>
    <form method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($this->plugin->router->url('/manage/assets/upload')) ?>">
      <?= Session::csrfField() ?>
      <div class="grid-3">
        <div class="form-group">
          <label class="form-label" for="asset_name">Asset name</label>
          <input id="asset_name" name="name" class="form-control" maxlength="128" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="asset_type">Asset type</label>
          <select id="asset_type" name="asset_type" class="form-control">
            <?php foreach (['logo', 'seal', 'signature', 'background'] as $assetType): ?>
              <option value="<?= htmlspecialchars($assetType) ?>"><?= htmlspecialchars(ucfirst($assetType)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="asset_file">Image (PNG, JPEG, GIF, WebP, sanitized SVG; max 5 MB)</label>
          <input id="asset_file" name="asset_file" type="file" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" required>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Upload asset</button>
    </form>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Preview</th><th>Name</th><th>Type</th><th>Size</th><th>Action</th></tr></thead>
        <tbody>
          <?php if ($assets === []): ?>
            <tr><td colspan="5">No assets uploaded.</td></tr>
          <?php else: ?>
            <?php foreach ($assets as $asset): ?>
              <tr>
                <td><img src="<?= htmlspecialchars($this->plugin->router->url('/manage/assets/' . (int)$asset['id'])) ?>" alt="" style="max-width:100px;max-height:60px"></td>
                <td><?= htmlspecialchars($asset['name']) ?></td>
                <td><?= htmlspecialchars($asset['asset_type']) ?></td>
                <td><?= number_format((int)$asset['file_size']) ?> bytes</td>
                <td>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/assets/' . (int)$asset['id'] . '/delete')) ?>" onsubmit="return confirm('Delete this tenant asset?');">
                    <?= Session::csrfField() ?>
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
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

<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Tenant Members</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted);">Assign CMS accounts tenant-scoped roles. The host CMS integration validates account IDs.</p>
    </div>
  </div>
  <?php if ($canManageMembers): ?>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/members/add')) ?>">
      <?= Session::csrfField() ?>
      <div class="grid-3">
        <div class="form-group">
          <label class="form-label" for="member_user_id">CMS user ID</label>
          <input id="member_user_id" name="user_id" class="form-control" type="number" min="1" step="1" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="member_role_key">Tenant role</label>
          <select id="member_role_key" name="role_key" class="form-control" required>
            <?php foreach (['tenant_owner' => 'Owner', 'tenant_admin' => 'Admin', 'template_designer' => 'Template Designer', 'issuer' => 'Issuer', 'viewer' => 'Viewer'] as $roleKey => $roleLabel): ?>
              <option value="<?= htmlspecialchars($roleKey) ?>"><?= htmlspecialchars($roleLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="align-self:end;">
          <button type="submit" class="btn btn-primary btn-sm">Add or restore member</button>
        </div>
      </div>
    </form>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>CMS user ID</th><th>Role</th><th>Membership since</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if ($members === []): ?>
            <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">No active members.</td></tr>
          <?php else: ?>
            <?php foreach ($members as $member): ?>
              <tr>
                <td><?= (int)$member['user_id'] ?></td>
                <td>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/members/role')) ?>">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>">
                    <select name="role_key" class="form-control" aria-label="Role for CMS user <?= (int)$member['user_id'] ?>">
                      <?php foreach (['tenant_owner' => 'Owner', 'tenant_admin' => 'Admin', 'template_designer' => 'Template Designer', 'issuer' => 'Issuer', 'viewer' => 'Viewer'] as $roleKey => $roleLabel): ?>
                        <option value="<?= htmlspecialchars($roleKey) ?>" <?= $member['role_key'] === $roleKey ? 'selected' : '' ?>><?= htmlspecialchars($roleLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm">Update role</button>
                  </form>
                </td>
                <td><?= htmlspecialchars($member['created_at']) ?></td>
                <td>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/members/deactivate')) ?>">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm">Deactivate</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p>Your role does not permit managing tenant memberships.</p>
  <?php endif; ?>
</div>

<?php if ($canReviewForms): ?>
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Form Approval Queue</h2>
      <span class="badge badge-info"><?= count($pendingFormSubmissions) ?> pending</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Submission</th><th>Form</th><th>Submitted</th><th>Decision</th></tr></thead>
        <tbody>
          <?php if ($pendingFormSubmissions === []): ?>
            <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">No pending form submissions.</td></tr>
          <?php else: ?>
            <?php foreach ($pendingFormSubmissions as $submission): ?>
              <tr>
                <td>#<?= (int)$submission['id'] ?></td>
                <td><?= htmlspecialchars($submission['form_title']) ?></td>
                <td><?= htmlspecialchars($submission['created_at']) ?></td>
                <td>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/forms/submissions/' . (int)$submission['id'] . '/approve')) ?>" style="display:inline;">
                    <?= Session::csrfField() ?>
                    <button type="submit" class="btn btn-primary btn-sm">Approve &amp; issue</button>
                  </form>
                  <?php if ($canManageForms): ?>
                    <details>
                      <summary class="btn btn-outline btn-sm">Reject</summary>
                      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/forms/submissions/' . (int)$submission['id'] . '/reject')) ?>">
                        <?= Session::csrfField() ?>
                        <label class="form-label" for="form_reject_reason_<?= (int)$submission['id'] ?>">Reason</label>
                        <input id="form_reject_reason_<?= (int)$submission['id'] ?>" name="reason" class="form-control" maxlength="1000" required>
                        <button type="submit" class="btn btn-danger btn-sm">Confirm rejection</button>
                      </form>
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
