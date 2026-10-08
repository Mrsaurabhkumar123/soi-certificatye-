<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Dynamic Form Builder UI Partial
 * Allows tenant administrators to design and publish self-service certificate request forms
 * mapped to published template variables.
 *
 * @var array<\SOI\Certificates\Templates\Template> $publishedTemplates
 * @var array $formBuilderVariables
 * @var array $forms
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="form-builder-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Dynamic Form Builder</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted);">
        Create public and private certificate request forms mapped directly to published template variables.
      </p>
    </div>
  </div>

  <?php if (empty($publishedTemplates)): ?>
    <p style="color: var(--text-muted); font-size: 0.9rem;">
      Publish a template before creating a certificate request form.
    </p>
  <?php else: ?>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/forms/create')) ?>" id="dynamic-form-builder">
      <?= Session::csrfField() ?>
      <div class="grid-3">
        <div class="form-group">
          <label class="form-label" for="form_title">Form title</label>
          <input id="form_title" name="title" class="form-control" maxlength="128" placeholder="e.g. Internship Certificate Application" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="form_key">Public URL key (slug)</label>
          <input id="form_key" name="form_key" class="form-control" pattern="[a-z0-9][a-z0-9-]{0,63}" placeholder="e.g. internship-2026" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="form_template">Published template</label>
          <select id="form_template" name="template_id" class="form-control" required>
            <?php foreach ($publishedTemplates as $publishedTemplate): ?>
              <option value="<?= (int)$publishedTemplate->id ?>"
                data-variables="<?= htmlspecialchars(json_encode(
                    $formBuilderVariables[$publishedTemplate->id] ?? [],
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                ), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($publishedTemplate->name, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="form_issue_mode">Submission policy</label>
          <select id="form_issue_mode" name="issue_mode" class="form-control">
            <option value="approval">Review before issuing (Approval Queue)</option>
            <option value="request_only">Request only (Manual processing)</option>
            <option value="immediate">Issue immediately on valid submission</option>
          </select>
        </div>
      </div>

      <fieldset id="form-field-builder" class="form-field-builder" style="border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1rem; margin: 1rem 0;">
        <legend style="font-weight: 600; font-size: 0.9rem; padding: 0 0.5rem;">Public form fields</legend>
        <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 0.75rem;">
          Configure fields applicants must fill. Required template variables cannot be removed or made optional.
        </p>
        <div id="form-field-list" class="form-field-list" aria-live="polite"></div>
        <div style="margin-top: 0.75rem;">
          <button type="button" class="btn btn-secondary btn-sm" id="form-add-field">+ Add Custom Field</button>
        </div>
        <input type="hidden" name="field_mapping" id="form-field-mapping">
        <input type="hidden" name="field_schema" id="form-field-schema">
      </fieldset>

      <button type="submit" class="btn btn-primary btn-sm">Create Public Form</button>
    </form>
  <?php endif; ?>

  <div class="table-responsive" style="margin-top: 1.5rem;">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem;">Configured Forms</h3>
    <table class="data-table">
      <thead>
        <tr>
          <th>Form Title</th>
          <th>Policy</th>
          <th>State</th>
          <th>Public URL</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($forms)): ?>
          <tr><td colspan="4" style="text-align:center; color: var(--text-muted);">No public forms configured yet.</td></tr>
        <?php else: ?>
          <?php foreach ($forms as $form): ?>
            <tr>
              <td><strong><?= htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
              <td><?= htmlspecialchars(str_replace('_', ' ', (string)$form['issue_mode']), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <span class="badge <?= (int)$form['is_active'] === 1 ? 'badge-success' : 'badge-warning' ?>">
                  <?= (int)$form['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td>
                <a href="<?= htmlspecialchars($this->plugin->router->url('/forms/' . rawurlencode($form['form_key']))) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
                  Open Form ↗
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
