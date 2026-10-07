<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = "Operations Console - Certificate Issuance";
ob_start();
?>

<div class="grid-2">
  <!-- Issuance Form -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Issue New Certificate</h2>
    </div>

    <?php if (empty($templates)): ?>
      <p style="color: var(--danger); font-size: 0.9rem;">
        No published templates available for issuance. Go to <a href="<?= htmlspecialchars($this->plugin->router->url('/manage')) ?>">Manage</a> to publish a template first.
      </p>
    <?php else: ?>
      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/console/issue')) ?>">
        <?= Session::csrfField() ?>
        <div class="form-group">
          <label class="form-label">Select Published Template</label>
          <select name="template_id" class="form-control" required>
            <?php foreach ($templates as $tpl): ?>
              <option value="<?= $tpl->id ?>"><?= htmlspecialchars($tpl->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Recipient Full Name</label>
          <input type="text" name="recipient_name" class="form-control" placeholder="e.g. Rahul Sharma" required>
        </div>

        <div class="form-group">
          <label class="form-label">Recipient Email (Optional)</label>
          <input type="email" name="recipient_email" class="form-control" placeholder="rahul@example.com">
        </div>

        <div class="form-group">
          <label class="form-label">Course / Program / Title</label>
          <input type="text" name="course_name" class="form-control" placeholder="e.g. Full Stack Web Development Internship" required>
        </div>

        <div class="form-group">
          <label class="form-label">Date of Issue</label>
          <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%;">
          Generate & Issue Official Certificate
        </button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Operational Summary -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Issuance Pipeline Architecture</h2>
    </div>
    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
      Every issuance triggers the unified <code>CertificateIssuanceService</code>:
    </p>
    <ul style="font-size: 0.85rem; padding-left: 1.25rem; color: var(--text-main); line-height: 1.8;">
      <li><strong>Transactional Sequence:</strong> Automatically reserves sequential number without gaps.</li>
      <li><strong>Cryptographic Token:</strong> Generates 128-bit random non-guessable verification token.</li>
      <li><strong>Deterministic PDF Engine:</strong> Compiles A4 landscape PDF with print-accurate vector elements.</li>
      <li><strong>Embedded Vector QR:</strong> Draws sharp vector QR code pointing to official verification URL.</li>
      <li><strong>SHA-256 Checksum:</strong> Verifies binary artifact integrity upon storage.</li>
      <li><strong>Immutable Event:</strong> Logs lifecycle creation event and audit record.</li>
    </ul>
  </div>
</div>

<!-- Certificate Registry -->
<div class="card">
  <div class="card-header">
    <h2 class="card-title">Authoritative Certificate Registry</h2>
    <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($certificates) ?> record(s)</span>
  </div>

  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Certificate No.</th>
          <th>Recipient</th>
          <th>Status</th>
          <th>Issued Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($certificates)): ?>
          <tr><td colspan="5" style="text-align:center; color: var(--text-muted);">No certificates issued yet.</td></tr>
        <?php else: ?>
          <?php foreach ($certificates as $cert): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($cert->certificateNumber) ?></strong>
              </td>
              <td>
                <strong><?= htmlspecialchars($cert->recipientName) ?></strong>
                <?php if ($cert->recipientEmail): ?>
                  <br><small style="color: var(--text-muted);"><?= htmlspecialchars($cert->recipientEmail) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $cert->status === 'issued' ? 'badge-success' : 'badge-danger' ?>">
                  <?= htmlspecialchars($cert->status) ?>
                </span>
              </td>
              <td><?= htmlspecialchars(date('M j, Y', strtotime($cert->issuedAt))) ?></td>
              <td style="display: flex; gap: 0.5rem; align-items: center;">
                <a href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . $cert->id . '/download')) ?>" 
                   class="btn btn-outline btn-sm" target="_blank">
                  Download PDF
                </a>
                <a href="<?= htmlspecialchars($this->plugin->router->url('/verify/' . $cert->verificationToken)) ?>" 
                   class="btn btn-outline btn-sm" target="_blank">
                  Verify
                </a>
                <?php if ($cert->status === 'issued'): ?>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . $cert->id . '/revoke')) ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to revoke this certificate?');">
                    <?= Session::csrfField() ?>
                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>
