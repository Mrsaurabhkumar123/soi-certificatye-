<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Managed Certificate Asset Gallery Partial
 * Provides multipart asset uploading (logos, seals, signatures, backgrounds)
 * with server-side XML SVG sanitization, thumbnail rendering, and safe deletion guards.
 *
 * @var array $assets
 * @var bool $canManageAssets
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<?php if ($canManageAssets): ?>
  <div class="card" id="managed-assets-card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Managed Certificate Assets</h2>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0;">
          Upload a logo, seal, signature, or background artwork. SVG uploads are sanitized before storage.
        </p>
      </div>
    </div>
    <form method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($this->plugin->router->url('/manage/assets/upload')) ?>">
      <?= Session::csrfField() ?>
      <div class="grid-3">
        <div class="form-group">
          <label class="form-label" for="asset_name">Asset Name</label>
          <input id="asset_name" name="name" class="form-control" maxlength="128" placeholder="e.g. Official Seal 2026" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="asset_type">Asset Type</label>
          <select id="asset_type" name="asset_type" class="form-control">
            <?php foreach (['logo', 'seal', 'signature', 'background'] as $assetType): ?>
              <option value="<?= htmlspecialchars($assetType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($assetType), ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="asset_file">Image (PNG, JPEG, GIF, WebP, SVG; max 5 MB)</label>
          <input id="asset_file" name="asset_file" type="file" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" required>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Upload Asset</button>
    </form>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Preview</th>
            <th>Name</th>
            <th>Type</th>
            <th>Size</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($assets)): ?>
            <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">No assets uploaded yet.</td></tr>
          <?php else: ?>
            <?php foreach ($assets as $asset): ?>
              <tr>
                <td>
                  <img src="<?= htmlspecialchars($this->plugin->router->url('/manage/assets/' . (int)$asset['id'])) ?>" alt="<?= htmlspecialchars($asset['name'], ENT_QUOTES, 'UTF-8') ?>" style="max-width:100px; max-height:60px; object-fit:contain; border-radius:4px; border:1px solid var(--border-color);">
                </td>
                <td><strong><?= htmlspecialchars($asset['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><span class="badge badge-info"><?= htmlspecialchars(ucfirst($asset['asset_type']), ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= number_format((int)$asset['file_size']) ?> bytes</td>
                <td>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/assets/' . (int)$asset['id'] . '/delete')) ?>" onsubmit="return confirm('Delete this tenant asset? Published template versions referencing it will block deletion.');">
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
