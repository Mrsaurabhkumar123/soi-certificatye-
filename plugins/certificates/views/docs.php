<?php
declare(strict_types=1);

$pageTitle = "API & Integration Documentation - /docs";
ob_start();
?>

<div class="card" id="documentation-portal-card">
  <div class="card-header" style="flex-wrap: wrap; gap: 1rem;">
    <div>
      <h2 class="card-title">SOI Certificate Platform Documentation</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
        Interactive Developer API Reference, Webhook Schemas, and Architectural Guide
      </p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
      <span class="badge badge-info">v1.0.0</span>
      <span class="badge badge-success">REST API v1</span>
    </div>
  </div>

  <!-- Interactive Navigation Tabs -->
  <div class="docs-tabs" style="display: flex; gap: 0.5rem; border-bottom: 2px solid var(--border-color); margin-bottom: 1.5rem; padding-bottom: 0.25rem;">
    <button type="button" class="btn btn-sm btn-primary" id="tab-btn-api" onclick="switchDocsTab('api')">
      📡 REST API Reference
    </button>
    <button type="button" class="btn btn-sm btn-outline" id="tab-btn-guide" onclick="switchDocsTab('guide')">
      📘 Integration &amp; Architecture Guide
    </button>
  </div>

  <!-- Tab 1: API Reference -->
  <div id="tab-content-api">
    <?php require __DIR__ . '/docs/api_reference.php'; ?>
  </div>

  <!-- Tab 2: Architecture Guide -->
  <div id="tab-content-guide" style="display: none;">
    <?php require __DIR__ . '/docs/guide.php'; ?>
  </div>
</div>

<script>
  function switchDocsTab(tab) {
    const apiContent = document.getElementById('tab-content-api');
    const guideContent = document.getElementById('tab-content-guide');
    const apiBtn = document.getElementById('tab-btn-api');
    const guideBtn = document.getElementById('tab-btn-guide');

    if (tab === 'api') {
      if (apiContent) apiContent.style.display = 'block';
      if (guideContent) guideContent.style.display = 'none';
      if (apiBtn) {
        apiBtn.className = 'btn btn-sm btn-primary';
      }
      if (guideBtn) {
        guideBtn.className = 'btn btn-sm btn-outline';
      }
    } else {
      if (apiContent) apiContent.style.display = 'none';
      if (guideContent) guideContent.style.display = 'block';
      if (apiBtn) {
        apiBtn.className = 'btn btn-sm btn-outline';
      }
      if (guideBtn) {
        guideBtn.className = 'btn btn-sm btn-primary';
      }
    }
  }
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>
