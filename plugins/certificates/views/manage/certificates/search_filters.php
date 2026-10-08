<?php
declare(strict_types=1);

/**
 * Certificate Registry Search & Filter Partial
 * Allows filtering by Certificate Number, Recipient, Status, and Date Range, plus CSV export.
 *
 * @var array $filters Current filter values
 * @var bool $canExport Whether actor has export permission
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<form method="GET" action="<?= htmlspecialchars($this->plugin->router->url('/console')) ?>" class="grid-3" role="search" id="registry-search-form">
  <div class="form-group">
    <label class="form-label" for="filter_number">Certificate number</label>
    <input id="filter_number" name="certificate_number" class="form-control" maxlength="64" value="<?= htmlspecialchars($filters['certificate_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. SOI-2026-...">
  </div>
  <div class="form-group">
    <label class="form-label" for="filter_recipient">Recipient</label>
    <input id="filter_recipient" name="recipient" class="form-control" maxlength="128" value="<?= htmlspecialchars($filters['recipient'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Name or keyword">
  </div>
  <div class="form-group">
    <label class="form-label" for="filter_status">Status</label>
    <select id="filter_status" name="status" class="form-control">
      <option value="">All statuses</option>
      <?php foreach (['issued', 'revoked', 'replaced', 'expired', 'cancelled'] as $statusOption): ?>
        <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>" <?= ($filters['status'] ?? '') === $statusOption ? 'selected' : '' ?>>
          <?= htmlspecialchars(ucfirst($statusOption), ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label" for="filter_from">Issued from</label>
    <input id="filter_from" name="from" type="date" class="form-control" value="<?= htmlspecialchars($filters['from'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label class="form-label" for="filter_to">Issued through</label>
    <input id="filter_to" name="to" type="date" class="form-control" value="<?= htmlspecialchars($filters['to'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group" style="align-self:end; display:flex; gap:.5rem; flex-wrap:wrap;">
    <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
    <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/console')) ?>">Clear</a>
    <?php if ($canExport ?? false): ?>
      <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/manage/reports/certificates.csv') . '?' . http_build_query($filters ?? [])) ?>">Export CSV</a>
    <?php endif; ?>
  </div>
</form>
