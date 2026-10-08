<?php
declare(strict_types=1);

/**
 * Template Version History View Partial
 * Displays immutable version records, canonical SHA-256 hashes, and publication timestamps.
 *
 * @var array $versions List of TemplateVersion objects or version rows
 * @var \SOI\Certificates\Templates\Template $template
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="template-history-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Template Version History</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0;">
        Published template versions are immutable to guarantee historical issuance reproducibility.
      </p>
    </div>
  </div>

  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Version</th>
          <th>Status</th>
          <th>Page Format</th>
          <th>Canonical Hash (SHA-256)</th>
          <th>Published At</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($versions)): ?>
          <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">No version records found.</td></tr>
        <?php else: ?>
          <?php foreach ($versions as $ver): ?>
            <tr>
              <td><strong>v<?= (int)($ver->versionNumber ?? $ver['version_number'] ?? 1) ?></strong></td>
              <td>
                <span class="badge <?= !empty($ver->publishedAt ?? $ver['published_at'] ?? null) ? 'badge-success' : 'badge-warning' ?>">
                  <?= !empty($ver->publishedAt ?? $ver['published_at'] ?? null) ? 'Published' : 'Draft' ?>
                </span>
              </td>
              <td><?= htmlspecialchars($ver->pageFormat ?? $ver['page_format'] ?? 'A4_LANDSCAPE', ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <code style="font-size: 0.75rem;">
                  <?= htmlspecialchars(substr($ver->canonicalHash ?? $ver['canonical_hash'] ?? 'pending...', 0, 16) . '...', ENT_QUOTES, 'UTF-8') ?>
                </code>
              </td>
              <td>
                <?= htmlspecialchars(!empty($ver->publishedAt ?? $ver['published_at'] ?? null) ? ($ver->publishedAt ?? $ver['published_at']) : 'Unpublished', ENT_QUOTES, 'UTF-8') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
