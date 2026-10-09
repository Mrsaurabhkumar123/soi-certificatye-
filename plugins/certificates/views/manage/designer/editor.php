<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * 3-Zone Visual Certificate Canvas Editor Partial
 * Left Zone: Element Palette (text, variables, shapes, vector QR)
 * Center Zone: Zoomable/pan canvas with snap-to-grid alignment guides
 * Right Zone: Live element properties inspector (dimensions, typography, colors, z-index)
 *
 * @var int $templateId
 * @var \SOI\Certificates\Templates\Template $template
 * @var array $layout
 * @var array $variableSchema
 * @var bool $isPublishedDraft
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
$initialJson = json_encode(
    ['layout' => $layout, 'variable_schema' => $variableSchema],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
?>
<meta name="csrf-token" content="<?= htmlspecialchars(Session::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<main class="designer" id="designer" data-template-id="<?= (int)$templateId ?>"
      data-save-url="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/save-draft'), ENT_QUOTES, 'UTF-8') ?>"
      data-initial="<?= htmlspecialchars($initialJson, ENT_QUOTES, 'UTF-8') ?>">
  <header class="designer-toolbar">
    <div>
      <h1 style="margin: 0; font-size: 1.25rem; font-weight: 700;"><?= htmlspecialchars($template->name, ENT_QUOTES, 'UTF-8') ?></h1>
      <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">
        <?= $isPublishedDraft ? 'Published version — first save creates a new draft version.' : 'Draft changes are uncommitted until explicitly saved and published.' ?>
      </p>
    </div>
    <div class="designer-actions">
      <button type="button" class="btn btn-secondary btn-sm" id="undo" disabled>Undo</button>
      <button type="button" class="btn btn-secondary btn-sm" id="redo" disabled>Redo</button>
      <button type="button" class="btn btn-secondary btn-sm" id="preview">Preview</button>
      <button type="button" class="btn btn-primary btn-sm" id="save">Save draft</button>
      <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/manage')) ?>">Back to Manage</a>
    </div>
  </header>
  <p class="designer-status" id="status" role="status" aria-live="polite">Loading editor…</p>
  <div class="designer-workspace">
    <!-- Zone 1: Element Palette -->
    <aside class="designer-panel" aria-label="Element palette">
      <h2>Elements</h2>
      <button type="button" data-add="text">+ Add Text</button>
      <button type="button" data-add="variable">+ Add Variable</button>
      <button type="button" data-add="rectangle">+ Add Shape</button>
      <button type="button" data-add="qr">+ Add Verification QR</button>
      <hr>
      <label for="sample-name">Preview Recipient</label>
      <input id="sample-name" value="Sample Recipient">
      <label for="sample-course">Preview Program / Title</label>
      <input id="sample-course" value="Sample Program">
    </aside>

    <!-- Zone 2: Central Canvas -->
    <section class="designer-canvas-panel" aria-label="Certificate canvas">
      <div id="canvas-scroll">
        <div id="canvas" class="certificate-canvas" tabindex="0" aria-label="Editable certificate canvas"></div>
      </div>
    </section>

    <!-- Zone 3: Properties Inspector -->
    <aside class="designer-panel" aria-label="Selected element properties">
      <h2>Properties</h2>
      <p id="empty-selection">Select an element to edit its properties.</p>
      <div id="properties" hidden>
        <label for="prop-value">Text / Variable Key</label>
        <input id="prop-value">
        <div class="designer-number-grid">
          <label>X <input id="prop-x" type="number" min="0" step="1"></label>
          <label>Y <input id="prop-y" type="number" min="0" step="1"></label>
          <label>Width <input id="prop-w" type="number" min="1" step="1"></label>
          <label>Height <input id="prop-h" type="number" min="1" step="1"></label>
        </div>
        <label for="prop-font-size">Font Size (px/pt)</label>
        <input id="prop-font-size" type="number" min="1" max="256">
        <label for="prop-align">Text Alignment</label>
        <select id="prop-align"><option>left</option><option>center</option><option>right</option></select>
        <button type="button" id="bring-forward" class="btn-secondary">Bring Forward</button>
        <button type="button" id="send-backward" class="btn-secondary">Send Backward</button>
        <button type="button" id="delete-element" class="btn-danger">Delete Element</button>
      </div>
    </aside>
  </div>
</main>
<script src="<?= htmlspecialchars($this->plugin->assetUrl('/js/designer-canvas.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
