<?php
declare(strict_types=1);

/**
 * Test Render Modal Partial
 * Allows tenant administrators and designers to test-render templates with sample data
 * and verify print layout, vector QR placement, and asset resolution before publishing.
 *
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<dialog id="test-render-modal" class="modal" aria-labelledby="test-render-title">
  <div class="modal-content" style="max-width: 600px; padding: 1.5rem; border-radius: 8px; background: var(--bg-card, #fff); border: 1px solid var(--border-color, #e2e8f0);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h3 id="test-render-title" style="margin: 0; font-size: 1.25rem;">Template Test Render</h3>
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('test-render-modal').close();" aria-label="Close dialog">&times;</button>
    </div>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
      Test-rendering evaluates variable substitution, font fallback, and vector QR scaling without creating an authoritative certificate or incrementing sequence numbers.
    </p>
    <form id="test-render-form" method="POST" target="_blank" action="#">
      <div class="form-group">
        <label class="form-label" for="test_recipient_name">Sample Recipient Name</label>
        <input id="test_recipient_name" name="sample_recipient_name" class="form-control" value="Ananya Deshmukh" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="test_course_title">Sample Course / Title</label>
        <input id="test_course_title" name="sample_course_title" class="form-control" value="Advanced Certificate of Excellence" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="test_issue_date">Sample Issue Date</label>
        <input id="test_issue_date" type="date" name="sample_issue_date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('test-render-modal').close();">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="alert('Test-render preview active in Designer Canvas preview mode.'); document.getElementById('test-render-modal').close();">Simulate Test Render</button>
      </div>
    </form>
  </div>
</dialog>
