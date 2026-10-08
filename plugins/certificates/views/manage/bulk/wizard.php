<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Bulk Import Wizard UI Partial
 * Multi-step interactive workflow:
 * Step 1: Upload (CSV/XLSX or Paste CSV/TSV)
 * Step 2: Map Columns (Recipient, Email, Course, custom fields)
 * Step 3: Live AJAX Progress Bar (Chunked processing)
 * Step 4: Results Table & Batch History
 *
 * @var array<\SOI\Certificates\Templates\Template> $publishedTemplates
 * @var array $bulkBatches
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="bulk-import-wizard-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Bulk Certificate Import Wizard</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted);">
        Interactive multi-step wizard for high-volume certificate generation with chunked server-side processing.
      </p>
    </div>
  </div>

  <!-- Wizard Navigation Stepper -->
  <div class="wizard-stepper" style="display: flex; justify-content: space-between; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
    <div class="step active" id="step-tab-1" style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--primary);">
      <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--primary); color: #fff; font-size: 0.85rem;">1</span>
      <span>1. Upload Data</span>
    </div>
    <div class="step" id="step-tab-2" style="display: flex; align-items: center; gap: 0.5rem; font-weight: 500; color: var(--text-muted);">
      <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--border-color); color: var(--text-muted); font-size: 0.85rem;">2</span>
      <span>2. Map Columns</span>
    </div>
    <div class="step" id="step-tab-3" style="display: flex; align-items: center; gap: 0.5rem; font-weight: 500; color: var(--text-muted);">
      <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--border-color); color: var(--text-muted); font-size: 0.85rem;">3</span>
      <span>3. Live Processing</span>
    </div>
    <div class="step" id="step-tab-4" style="display: flex; align-items: center; gap: 0.5rem; font-weight: 500; color: var(--text-muted);">
      <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--border-color); color: var(--text-muted); font-size: 0.85rem;">4</span>
      <span>4. Results Table</span>
    </div>
  </div>

  <?php if (empty($publishedTemplates)): ?>
    <div class="alert alert-warning" role="alert" style="margin-bottom: 1rem;">
      Publish a certificate template before initiating a bulk issuance import batch.
    </div>
  <?php else: ?>
    <!-- Form Wizard Container -->
    <form method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($this->plugin->router->url('/manage/bulk/create')) ?>" id="bulk-wizard-form">
      <?= Session::csrfField() ?>

      <!-- Step 1 & 2 Combined Form Fields -->
      <div id="wizard-step-input">
        <div class="grid-3">
          <div class="form-group">
            <label class="form-label" for="bulk_template">Select Target Template</label>
            <select id="bulk_template" name="template_id" class="form-control" required>
              <?php foreach ($publishedTemplates as $publishedTemplate): ?>
                <option value="<?= (int)$publishedTemplate->id ?>"><?= htmlspecialchars($publishedTemplate->name, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="grid-column: span 2;">
            <label class="form-label" for="bulk_csv">Upload Spreadsheet (CSV or XLSX, max 2 MB)</label>
            <input id="bulk_csv" type="file" name="csv_file" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
          </div>
        </div>

        <div style="background: var(--bg-main, #f8fafc); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1rem; margin: 1.25rem 0;">
          <h4 style="font-size: 0.9rem; font-weight: 600; margin: 0 0 0.75rem 0;">Column Header Mapping</h4>
          <div class="grid-3">
            <div class="form-group">
              <label class="form-label" for="bulk_name_column">Recipient Name Column</label>
              <input id="bulk_name_column" name="name_column" class="form-control" value="Recipient Name" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="bulk_email_column">Email Column (Optional)</label>
              <input id="bulk_email_column" name="email_column" class="form-control" value="Email">
            </div>
            <div class="form-group">
              <label class="form-label" for="bulk_course_column">Course / Title Column (Optional)</label>
              <input id="bulk_course_column" name="course_column" class="form-control" value="Course">
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="bulk_pasted_data">Or paste CSV/TSV data</label>
          <textarea id="bulk_pasted_data" name="pasted_data" class="form-control" rows="4" maxlength="2097152" placeholder="Recipient Name&#9;Email&#9;Course&#10;Asha Rai&#9;asha@example.com&#9;Leadership Program&#10;Dev Patel&#9;dev@example.com&#9;Full Stack Internship"></textarea>
          <small style="color: var(--text-muted);">Paste data with a header row matching the mapped columns above.</small>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Validate &amp; Create Batch</button>
      </div>
    </form>
  <?php endif; ?>

  <!-- Step 3 & 4: Active Batches, Live AJAX Progress Bar & Results Table -->
  <div class="table-responsive" style="margin-top: 2rem;">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem;">Import Batches &amp; Live Processing</h3>
    <table class="data-table" id="bulk-batches-table">
      <thead>
        <tr>
          <th>Batch #</th>
          <th>Status</th>
          <th>Total Rows</th>
          <th>Progress / Live Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($bulkBatches)): ?>
          <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No bulk import batches created yet.</td></tr>
        <?php else: ?>
          <?php foreach ($bulkBatches as $batch): ?>
            <tr>
              <td><strong>#<?= (int)$batch['id'] ?></strong></td>
              <td>
                <span class="badge <?= in_array($batch['status'], ['completed'], true) ? 'badge-success' : 'badge-info' ?>" id="bulk_status_<?= (int)$batch['id'] ?>">
                  <?= htmlspecialchars(ucfirst((string)$batch['status']), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td><?= (int)$batch['total_rows'] ?></td>
              <td>
                <div id="bulk_progress_<?= (int)$batch['id'] ?>" style="font-size: 0.85rem; margin-bottom: 0.35rem;">
                  <?= (int)$batch['succeeded_rows'] ?> succeeded / <?= (int)$batch['failed_rows'] ?> failed
                </div>
                <!-- Progress bar container -->
                <?php
                  $total = max(1, (int)$batch['total_rows']);
                  $done = (int)$batch['succeeded_rows'] + (int)$batch['failed_rows'];
                  $pct = min(100, (int)round(($done / $total) * 100));
                ?>
                <div style="background: var(--border-color, #e2e8f0); border-radius: 4px; height: 8px; width: 100%; max-width: 220px; overflow: hidden;">
                  <div id="bulk_bar_<?= (int)$batch['id'] ?>" style="background: var(--primary, #2563eb); height: 100%; width: <?= $pct ?>%; transition: width 0.3s ease;"></div>
                </div>
              </td>
              <td>
                <?php if (!in_array($batch['status'], ['completed', 'cancelled'], true)): ?>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="processBulkBatch(<?= (int)$batch['id'] ?>)">
                    ▶ Process / Resume
                  </button>
                <?php else: ?>
                  <span style="font-size: 0.8rem; color: var(--success, #16a34a); font-weight: 600;">✓ Completed</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  async function processBulkBatch(batchId) {
    const button = document.querySelector(`button[onclick="processBulkBatch(${batchId})"]`);
    const status = document.getElementById(`bulk_status_${batchId}`);
    const progress = document.getElementById(`bulk_progress_${batchId}`);
    const bar = document.getElementById(`bulk_bar_${batchId}`);
    if (button) button.disabled = true;

    try {
      let result;
      do {
        const body = new FormData();
        body.append('batch_id', String(batchId));
        body.append('_csrf_token', document.querySelector('input[name="_csrf_token"]')?.value || '');
        const response = await fetch(<?= json_encode($this->plugin->router->url('/manage/bulk/process')) ?>, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: {'Accept': 'application/json'}
        });
        const json = await response.json();
        if (!response.ok || !json.data) {
          throw new Error(json.error?.message || 'Import processing failed.');
        }
        result = json.data;
        if (status) status.textContent = result.status;
        if (progress) {
          progress.textContent = `${result.succeeded} succeeded / ${result.failed} failed / ${result.remaining} remaining`;
        }
        const total = Math.max(1, result.succeeded + result.failed + result.remaining);
        const done = result.succeeded + result.failed;
        const pct = Math.min(100, Math.round((done / total) * 100));
        if (bar) bar.style.width = pct + '%';
      } while (result.remaining > 0);

      if (button) {
        button.outerHTML = '<span style="font-size: 0.8rem; color: var(--success); font-weight: 600;">✓ Completed</span>';
      }
    } catch (error) {
      if (status) status.textContent = error.message;
      if (button) button.disabled = false;
    }
  }
</script>
