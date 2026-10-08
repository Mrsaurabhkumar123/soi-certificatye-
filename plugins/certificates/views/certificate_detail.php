<?php
declare(strict_types=1);
?>
<main class="container">
  <section class="card">
    <header class="card-header">
      <div>
        <h1 class="card-title"><?= htmlspecialchars($certificate->certificateNumber, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Certificate record and lifecycle history</p>
      </div>
      <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/console')) ?>">Back to registry</a>
    </header>
    <dl class="grid-3">
      <div><dt>Recipient</dt><dd><?= htmlspecialchars($certificate->recipientName, ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Status</dt><dd><?= htmlspecialchars($certificate->status, ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Issued</dt><dd><?= htmlspecialchars($certificate->issuedAt, ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Expires</dt><dd><?= htmlspecialchars($certificate->expiresAt ?? 'No expiry', ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Template version</dt><dd><?= (int)$certificate->templateVersionId ?></dd></div>
      <div><dt>Artifact SHA-256</dt><dd><code><?= htmlspecialchars($certificate->fileSha256, ENT_QUOTES, 'UTF-8') ?></code></dd></div>
      <?php if ($certificate->replacesCertificateId !== null): ?>
        <div><dt>Replaces certificate ID</dt><dd><?= (int)$certificate->replacesCertificateId ?></dd></div>
      <?php endif; ?>
      <?php if ($certificate->replacedByCertificateId !== null): ?>
        <div><dt>Replaced by certificate ID</dt><dd><?= (int)$certificate->replacedByCertificateId ?></dd></div>
      <?php endif; ?>
    </dl>
    <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/console/certificates/' . $certificate->id . '/download')) ?>">Download PDF</a>
  </section>
  <section class="card">
    <h2 class="card-title">Lifecycle timeline</h2>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Time</th><th>Transition</th><th>Reason</th><th>Actor ID</th></tr></thead>
        <tbody>
          <?php foreach ($events as $event): ?>
            <tr>
              <td><?= htmlspecialchars($event['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($event['from_status'] . ' → ' . $event['to_status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($event['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($event['actor_id'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($events === []): ?><tr><td colspan="4">No lifecycle changes recorded.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
