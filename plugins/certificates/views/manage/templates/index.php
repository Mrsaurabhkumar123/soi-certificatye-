<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Certificate Templates List & Management Partial
 * Lists templates, categories, publish/draft actions, and creation modal.
 *
 * @var array<\SOI\Certificates\Templates\Template> $templates
 * @var \SOI\Certificates\Tenancy\Tenant $tenant
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="certificate-templates-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Certificate Templates</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0;">
        Manage and publish versioned certificate templates for <?= htmlspecialchars($tenant->displayName, ENT_QUOTES, 'UTF-8') ?>
      </p>
    </div>
    <div>
      <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('create-template-dialog').showModal();">+ New Template</button>
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
        <?php if (empty($templates)): ?>
          <tr><td colspan="4" style="text-align:center; color: var(--text-muted);">No templates created yet. Click "+ New Template" to create one.</td></tr>
        <?php else: ?>
          <?php foreach ($templates as $tpl): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($tpl->name, ENT_QUOTES, 'UTF-8') ?></strong><br>
                <small style="color: var(--text-muted);"><?= htmlspecialchars($tpl->slug, ENT_QUOTES, 'UTF-8') ?></small>
              </td>
              <td><?= htmlspecialchars($tpl->category ?? 'General', ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <span class="badge <?= $tpl->isPublished() ? 'badge-success' : 'badge-warning' ?>">
                  <?= htmlspecialchars(ucfirst($tpl->status), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td>
                <?php if (!$tpl->isPublished()): ?>
                  <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/' . $tpl->id . '/designer')) ?>">Design</a>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/publish')) ?>" style="display:inline;">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="template_id" value="<?= (int)$tpl->id ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Publish Version</button>
                  </form>
                <?php else: ?>
                  <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/' . $tpl->id . '/designer')) ?>">Edit as draft</a>
                  <span style="font-size: 0.8rem; color: var(--success); font-weight: 600; margin-left: 0.5rem;">✓ Active for Issuance</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Create New Template -->
<dialog id="create-template-dialog" class="modal" aria-labelledby="create-tpl-title">
  <div class="modal-content" style="max-width: 520px; padding: 1.5rem; border-radius: 8px; background: var(--bg-card, #fff); border: 1px solid var(--border-color, #e2e8f0);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h3 id="create-tpl-title" style="margin: 0; font-size: 1.2rem;">Create Certificate Template</h3>
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('create-template-dialog').close();">&times;</button>
    </div>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/create')) ?>">
      <?= Session::csrfField() ?>
      <div class="form-group">
        <label class="form-label" for="tpl_name">Template Name</label>
        <input id="tpl_name" name="name" class="form-control" placeholder="e.g. Certificate of Achievement" required maxlength="128">
      </div>
      <div class="form-group">
        <label class="form-label" for="tpl_slug">URL Slug Identifier</label>
        <input id="tpl_slug" name="slug" class="form-control" placeholder="e.g. certificate-achievement" required maxlength="64" pattern="^[a-z0-9][a-z0-9-]{0,63}$">
      </div>
      <div class="form-group">
        <label class="form-label" for="tpl_category">Category</label>
        <input id="tpl_category" name="category" class="form-control" placeholder="e.g. Academic, Training, Corporate" maxlength="64">
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('create-template-dialog').close();">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Create Template</button>
      </div>
    </form>
  </div>
</dialog>
