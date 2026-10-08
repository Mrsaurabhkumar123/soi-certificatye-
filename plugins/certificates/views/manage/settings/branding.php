<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/** @var \SOI\Certificates\Tenancy\Tenant $tenant */
$branding = $tenant->branding ?? [];
$primaryColor = htmlspecialchars($branding['primary_color'] ?? '#1e3a8a');
$accentColor = htmlspecialchars($branding['accent_color'] ?? '#d97706');
$logo = htmlspecialchars($branding['logo'] ?? '');
?>
<div class="card" id="tenant-branding-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Tenant Branding &amp; Theme</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0;">
        Customize portal appearance, certificate accents, and verification badges.
      </p>
    </div>
  </div>
  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/settings/branding')) ?>">
    <?= Session::csrfField() ?>
    <div class="grid-3" style="align-items: start;">
      <div class="form-group">
        <label class="form-label" for="primary_color">Primary brand color</label>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
          <input type="color" id="primary_color_picker" value="<?= $primaryColor ?>"
                 oninput="document.getElementById('primary_color').value = this.value; document.getElementById('preview-swatch-primary').style.backgroundColor = this.value;"
                 style="width: 42px; height: 38px; padding: 2px; border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer;">
          <input id="primary_color" name="primary_color" class="form-control" type="text"
                 pattern="#[0-9A-Fa-f]{6}" value="<?= $primaryColor ?>" required
                 oninput="if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) { document.getElementById('primary_color_picker').value = this.value; document.getElementById('preview-swatch-primary').style.backgroundColor = this.value; }">
        </div>
        <small style="color: var(--text-muted); font-size: 0.75rem;">Main navigation headers, buttons &amp; seals.</small>
      </div>

      <div class="form-group">
        <label class="form-label" for="accent_color">Accent brand color</label>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
          <input type="color" id="accent_color_picker" value="<?= $accentColor ?>"
                 oninput="document.getElementById('accent_color').value = this.value; document.getElementById('preview-swatch-accent').style.backgroundColor = this.value;"
                 style="width: 42px; height: 38px; padding: 2px; border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer;">
          <input id="accent_color" name="accent_color" class="form-control" type="text"
                 pattern="#[0-9A-Fa-f]{6}" value="<?= $accentColor ?>" required
                 oninput="if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) { document.getElementById('accent_color_picker').value = this.value; document.getElementById('preview-swatch-accent').style.backgroundColor = this.value; }">
        </div>
        <small style="color: var(--text-muted); font-size: 0.75rem;">Highlights, borders, badges &amp; verification status.</small>
      </div>

      <div class="form-group">
        <label class="form-label" for="logo">Managed logo asset reference</label>
        <input id="logo" name="logo" class="form-control" type="text" maxlength="255" value="<?= $logo ?>"
               placeholder="e.g. logo.png or upload in Managed Assets below">
        <small style="color: var(--text-muted); font-size: 0.75rem;">Displayed in verification pages and certificate headers.</small>
      </div>
    </div>

    <!-- Live Theme Preview Swatch -->
    <div style="margin: 1rem 0; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-card, #fff); display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
      <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">Theme Preview:</span>
      <div style="display: flex; align-items: center; gap: 0.5rem;">
        <span id="preview-swatch-primary" style="display: inline-block; width: 22px; height: 22px; border-radius: 4px; background-color: <?= $primaryColor ?>; border: 1px solid rgba(0,0,0,0.15);"></span>
        <span style="font-size: 0.8rem;">Primary</span>
      </div>
      <div style="display: flex; align-items: center; gap: 0.5rem;">
        <span id="preview-swatch-accent" style="display: inline-block; width: 22px; height: 22px; border-radius: 4px; background-color: <?= $accentColor ?>; border: 1px solid rgba(0,0,0,0.15);"></span>
        <span style="font-size: 0.8rem;">Accent</span>
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-sm">Save Branding &amp; Theme</button>
  </form>
</div>
