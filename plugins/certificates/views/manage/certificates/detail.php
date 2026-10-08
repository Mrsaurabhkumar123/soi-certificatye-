<?php
declare(strict_types=1);

/**
 * Certificate Detail & Lifecycle History Partial
 * Displays authoritative certificate metadata, checksum verification, replacement linkage, and timeline.
 *
 * @var \SOI\Certificates\Issuance\Certificate $certificate
 * @var array $events
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<main class="container" id="certificate-detail-container">
  <section class="card">
    <header class="card-header">
      <div>
        <h1 class="card-title"><?= htmlspecialchars($certificate->certificateNumber, ENT_QUOTES, 'UTF-8') ?></h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0;">Certificate record and authoritative lifecycle history</p>
      </div>
      <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/console')) ?>">Back to registry</a>
    </header>
    <dl class="grid-3" style="margin: 1.25rem 0;">
      <div><dt style="font-weight: 600; color: var(--text-muted);">Recipient</dt><dd><strong><?= htmlspecialchars($certificate->recipientName, ENT_QUOTES, 'UTF-8') ?></strong></dd></div>
      <div>
        <dt style="font-weight: 600; color: var(--text-muted);">Status</dt>
        <dd>
          <span class="badge <?= $certificate->status === 'issued' ? 'badge-success' : 'badge-danger' ?>">
            <?= htmlspecialchars(ucfirst($certificate->status), ENT_QUOTES, 'UTF-8') ?>
          </span>
        </dd>
      </div>
      <div><dt style="font-weight: 600; color: var(--text-muted);">Issued Timestamp</dt><dd><?= htmlspecialchars($certificate->issuedAt, ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt style="font-weight: 600; color: var(--text-muted);">Expires</dt><dd><?= htmlspecialchars($certificate->expiresAt ?? 'No expiry date set', ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt style="font-weight: 600; color: var(--text-muted);">Template Version Reference</dt><dd>Version #<?= (int)$certificate->templateVersionId ?></dd></div>
      <div><dt style="font-weight: 600; color: var(--text-muted);">Artifact Integrity (SHA-256)</dt><dd><code style="font-size: 0.75rem; word-break: break-all;"><?= htmlspecialchars($certificate->fileSha256, ENT_QUOTES, 'UTF-8') ?></code></dd></div>
      <?php if ($certificate->replacesCertificateId !== null): ?>
        <div><dt style="font-weight: 600; color: var(--text-muted);">Replaces Certificate</dt><dd><a href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . (int)$certificate->replacesCertificateId)) ?>">Certificate #<?= (int)$certificate->replacesCertificateId ?></a></dd></div>
      <?php endif; ?>
      <?php if ($certificate->replacedByCertificateId !== null): ?>
        <div><dt style="font-weight: 600; color: var(--text-muted);">Replaced By Certificate</dt><dd><a href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . (int)$certificate->replacedByCertificateId)) ?>">Certificate #<?= (int)$certificate->replacedByCertificateId ?></a></dd></div>
      <?php endif; ?>
    </dl>
    <div style="display: flex; gap: 0.5rem;">
      <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . (int)$certificate->id . '/download')) ?>">Download Verified PDF</a>
      <?php if ($certificate->verificationToken !== ''): ?>
        <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/verify/' . rawurlencode($certificate->verificationToken))) ?>" target="_blank">Open Verification Page</a>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" style="margin-top: 1.5rem;">
    <h2 class="card-title">Lifecycle Timeline</h2>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Transition</th>
            <th>Reason</th>
            <th>Actor</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($events)): ?>
            <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No lifecycle state changes recorded.</td></tr>
          <?php else: ?>
            <?php foreach ($events as $event): ?>
              <tr>
                <td><?= htmlspecialchars($event['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge badge-info"><?= htmlspecialchars($event['from_status'] . ' → ' . $event['to_status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars((string)($event['reason'] ?? 'Initial creation'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($event['actor_id'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
