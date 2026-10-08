<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = "Operations Console - Certificate Issuance";
ob_start();
?>

<section class="grid-4" aria-label="Issuance analytics">
  <?php foreach ([
      'Certificates issued' => $dashboardMetrics['issued_total'],
      'Issued in last 30 days' => $dashboardMetrics['issued_30d'],
      'Currently valid' => $dashboardMetrics['active'],
      'Failures in last 30 days' => $dashboardMetrics['failures_30d'],
  ] as $metricLabel => $metricValue): ?>
    <article class="card stat-card">
      <h2 class="card-title"><?= htmlspecialchars($metricLabel, ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="stat-value"><?= (int)$metricValue ?></p>
    </article>
  <?php endforeach; ?>
</section>

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

<?php if ($recentFailures !== []): ?>
  <section class="card" aria-labelledby="recent-failures-heading">
    <div class="card-header"><h2 class="card-title" id="recent-failures-heading">Recent Failure Log</h2></div>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Event</th><th>Target</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach ($recentFailures as $failure): ?>
            <tr>
              <td><?= htmlspecialchars($failure['event_key'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($failure['target_type'] . ' #' . $failure['target_id'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($failure['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<!-- Certificate Registry -->
<div class="card">
  <div class="card-header">
    <h2 class="card-title">Authoritative Certificate Registry</h2>
    <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($certificates) ?> record(s)</span>
  </div>

  <form method="GET" action="<?= htmlspecialchars($this->plugin->router->url('/console')) ?>" class="grid-3" role="search">
    <div class="form-group">
      <label class="form-label" for="filter_number">Certificate number</label>
      <input id="filter_number" name="certificate_number" class="form-control" maxlength="64" value="<?= htmlspecialchars($filters['certificate_number']) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="filter_recipient">Recipient</label>
      <input id="filter_recipient" name="recipient" class="form-control" maxlength="128" value="<?= htmlspecialchars($filters['recipient']) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="filter_status">Status</label>
      <select id="filter_status" name="status" class="form-control">
        <option value="">All statuses</option>
        <?php foreach (['issued', 'revoked', 'replaced', 'expired', 'cancelled'] as $statusOption): ?>
          <option value="<?= htmlspecialchars($statusOption) ?>" <?= $filters['status'] === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($statusOption)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label" for="filter_from">Issued from</label>
      <input id="filter_from" name="from" type="date" class="form-control" value="<?= htmlspecialchars($filters['from']) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="filter_to">Issued through</label>
      <input id="filter_to" name="to" type="date" class="form-control" value="<?= htmlspecialchars($filters['to']) ?>">
    </div>
    <div class="form-group" style="align-self:end;display:flex;gap:.5rem">
      <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
      <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/console')) ?>">Clear</a>
      <?php if ($canExport): ?>
        <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/manage/reports/certificates.csv') . '?' . http_build_query($filters)) ?>">Export CSV</a>
      <?php endif; ?>
    </div>
  </form>

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
                <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . $cert->id)) ?>">Details</a>
                <a href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . $cert->id . '/download')) ?>" 
                   class="btn btn-outline btn-sm" target="_blank">
                  Download PDF
                </a>
                <?php if ($cert->verificationToken !== ''): ?>
                  <a href="<?= htmlspecialchars($this->plugin->router->url('/verify/' . rawurlencode($cert->verificationToken))) ?>"
                     class="btn btn-outline btn-sm" target="_blank">Verify</a>
                <?php else: ?>
                  <span class="btn btn-outline btn-sm" aria-label="Scan the PDF verification QR code">Scan PDF QR</span>
                <?php endif; ?>
                <?php if ($cert->status === 'issued'): ?>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . $cert->id . '/revoke')) ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to revoke this certificate?');">
                    <?= Session::csrfField() ?>
                    <label class="form-label" for="revoke_reason_<?= $cert->id ?>">Revocation reason</label>
                    <input id="revoke_reason_<?= $cert->id ?>" name="reason" class="form-control" maxlength="1000" required>
                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                  </form>
                  <?php if ($this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_REPLACE)): ?>
                    <details>
                      <summary class="btn btn-outline btn-sm">Replace</summary>
                      <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . $cert->id . '/replace')) ?>">
                        <?= Session::csrfField() ?>
                        <div class="form-group">
                          <label class="form-label" for="replacement_name_<?= $cert->id ?>">Corrected recipient name</label>
                          <input id="replacement_name_<?= $cert->id ?>" name="recipient_name" class="form-control" maxlength="128" required>
                        </div>
                        <div class="form-group">
                          <label class="form-label" for="replacement_email_<?= $cert->id ?>">Corrected recipient email</label>
                          <input id="replacement_email_<?= $cert->id ?>" name="recipient_email" type="email" class="form-control" maxlength="128">
                        </div>
                        <div class="form-group">
                          <label class="form-label" for="replacement_reason_<?= $cert->id ?>">Replacement reason</label>
                          <input id="replacement_reason_<?= $cert->id ?>" name="reason" class="form-control" maxlength="1000" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Issue replacement</button>
                      </form>
                    </details>
                  <?php endif; ?>
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
