<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

$pageTitle = "Operations Console - Certificate Issuance";
ob_start();
?>

<?php require __DIR__ . '/console/dashboard.php'; ?>

<div class="grid-2">
  <!-- Issuance Form -->
  <?php require __DIR__ . '/console/issue.php'; ?>

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
<?php require __DIR__ . '/manage/certificates/index.php'; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>
