<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = 'Template Designer';
$initialJson = json_encode(
    ['layout' => $layout, 'variable_schema' => $variableSchema],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
ob_start();
?>
<meta name="csrf-token" content="<?= htmlspecialchars(Session::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<main class="designer" id="designer" data-template-id="<?= (int)$templateId ?>"
      data-save-url="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/save-draft'), ENT_QUOTES, 'UTF-8') ?>"
      data-initial="<?= htmlspecialchars($initialJson, ENT_QUOTES, 'UTF-8') ?>">
  <header class="designer-toolbar">
    <div>
      <h1><?= htmlspecialchars($template->name, ENT_QUOTES, 'UTF-8') ?></h1>
      <p><?= $isPublishedDraft ? 'Published version — first save creates a new draft.' : 'Draft changes are not published until explicitly published.' ?></p>
    </div>
    <div class="designer-actions">
      <button type="button" class="btn btn-secondary" id="undo" disabled>Undo</button>
      <button type="button" class="btn btn-secondary" id="redo" disabled>Redo</button>
      <button type="button" class="btn btn-secondary" id="preview">Preview</button>
      <button type="button" class="btn btn-primary" id="save">Save draft</button>
      <a class="btn btn-secondary" href="<?= htmlspecialchars($this->plugin->router->url('/manage')) ?>">Back</a>
    </div>
  </header>
  <p class="designer-status" id="status" role="status" aria-live="polite">Loading editor…</p>
  <div class="designer-workspace">
    <aside class="designer-panel" aria-label="Element palette">
      <h2>Elements</h2>
      <button type="button" data-add="text">Add text</button>
      <button type="button" data-add="variable">Add variable</button>
      <button type="button" data-add="rectangle">Add shape</button>
      <button type="button" data-add="qr">Add verification QR</button>
      <hr>
      <label for="sample-name">Preview recipient</label>
      <input id="sample-name" value="Sample Recipient">
      <label for="sample-course">Preview course</label>
      <input id="sample-course" value="Sample Program">
    </aside>
    <section class="designer-canvas-panel" aria-label="Certificate canvas">
      <div id="canvas-scroll">
        <div id="canvas" class="certificate-canvas" tabindex="0" aria-label="Editable certificate canvas"></div>
      </div>
    </section>
    <aside class="designer-panel" aria-label="Selected element properties">
      <h2>Properties</h2>
      <p id="empty-selection">Select an element to edit its properties.</p>
      <div id="properties" hidden>
        <label for="prop-value">Text / variable key</label>
        <input id="prop-value">
        <div class="designer-number-grid">
          <label>X <input id="prop-x" type="number" min="0" step="1"></label>
          <label>Y <input id="prop-y" type="number" min="0" step="1"></label>
          <label>Width <input id="prop-w" type="number" min="1" step="1"></label>
          <label>Height <input id="prop-h" type="number" min="1" step="1"></label>
        </div>
        <label for="prop-font-size">Font size</label>
        <input id="prop-font-size" type="number" min="1" max="256">
        <label for="prop-align">Alignment</label>
        <select id="prop-align"><option>left</option><option>center</option><option>right</option></select>
        <button type="button" id="bring-forward" class="btn-secondary">Bring forward</button>
        <button type="button" id="send-backward" class="btn-secondary">Send backward</button>
        <button type="button" id="delete-element" class="btn-danger">Delete selected element</button>
      </div>
    </aside>
  </div>
</main>
<script src="<?= htmlspecialchars($this->plugin->router->url('/assets/js/designer-canvas.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>
