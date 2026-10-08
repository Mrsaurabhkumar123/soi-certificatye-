<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Authoritative Certificate Registry Table Partial
 * Displays issued certificates, status badges, verification links, download actions,
 * revocation controls with reasons, and replacement forms.
 *
 * @var array<\SOI\Certificates\Issuance\Certificate> $certificates
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="certificate-registry-card">
  <div class="card-header">
    <h2 class="card-title">Authoritative Certificate Registry</h2>
    <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($certificates) ?> record(s)</span>
  </div>

  <?php require __DIR__ . '/search_filters.php'; ?>

  <div class="table-responsive" style="margin-top: 1rem;">
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
                <strong><?= htmlspecialchars($cert->certificateNumber, ENT_QUOTES, 'UTF-8') ?></strong>
              </td>
              <td>
                <strong><?= htmlspecialchars($cert->recipientName, ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if ($cert->recipientEmail): ?>
                  <br><small style="color: var(--text-muted);"><?= htmlspecialchars($cert->recipientEmail, ENT_QUOTES, 'UTF-8') ?></small>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $cert->status === 'issued' ? 'badge-success' : 'badge-danger' ?>">
                  <?= htmlspecialchars(ucfirst($cert->status), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td><?= htmlspecialchars(date('M j, Y', strtotime($cert->issuedAt)), ENT_QUOTES, 'UTF-8') ?></td>
              <td style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . (int)$cert->id)) ?>">Details</a>
                <a href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . (int)$cert->id . '/download')) ?>" 
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
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . (int)$cert->id . '/revoke')) ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to revoke this certificate?');">
                    <?= Session::csrfField() ?>
                    <label class="form-label sr-only" for="revoke_reason_<?= (int)$cert->id ?>">Revocation reason</label>
                    <input id="revoke_reason_<?= (int)$cert->id ?>" name="reason" class="form-control" maxlength="1000" placeholder="Revocation reason" required style="display:inline-block; width:140px; padding:0.25rem 0.5rem; font-size:0.75rem;">
                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                  </form>
                  <?php if ($this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_REPLACE)): ?>
                    <details style="display:inline-block;">
                      <summary class="btn btn-outline btn-sm">Replace</summary>
                      <div style="position:absolute; z-index:10; background:var(--bg-card, #fff); border:1px solid var(--border-color); padding:1rem; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.1); width:280px; margin-top:0.25rem;">
                        <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . (int)$cert->id . '/replace')) ?>">
                          <?= Session::csrfField() ?>
                          <div class="form-group">
                            <label class="form-label" for="replacement_name_<?= (int)$cert->id ?>">Corrected Recipient</label>
                            <input id="replacement_name_<?= (int)$cert->id ?>" name="recipient_name" class="form-control" maxlength="128" required>
                          </div>
                          <div class="form-group">
                            <label class="form-label" for="replacement_email_<?= (int)$cert->id ?>">Corrected Email</label>
                            <input id="replacement_email_<?= (int)$cert->id ?>" name="recipient_email" type="email" class="form-control" maxlength="128">
                          </div>
                          <div class="form-group">
                            <label class="form-label" for="replacement_reason_<?= (int)$cert->id ?>">Reason</label>
                            <input id="replacement_reason_<?= (int)$cert->id ?>" name="reason" class="form-control" maxlength="1000" required>
                          </div>
                          <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Issue Replacement</button>
                        </form>
                      </div>
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
